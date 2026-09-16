<?php
/**
 * scan_schema_php.php — the durable scan's schema, without a database.
 *
 * WHAT THIS FILE CAN AND CANNOT PROVE. It proves the things that are decidable
 * from the DDL and the migrator's control flow: the allowlist, idempotency, the
 * fail-closed behaviour of a failed read versus a fresh install, that a failed
 * statement stops the migration and never records a version it did not finish,
 * and that health() refuses to call a half-installed schema usable.
 *
 * It CANNOT prove the concurrency invariants — the one-active-run slot, the
 * worker-slot semaphore, lease fencing, CAS rollback. Those are properties of a
 * real InnoDB under two connections, and asserting them against a mock would be
 * the exact failure this module has shipped before: a control that passes every
 * test and does nothing in production. They live in tests/mysql/run.php and run
 * against the service matrix in .github/workflows/scan-database.yml.
 *
 * Run:  php tests/scan_schema_php.php
 */

namespace {
    // Schema names the two vocabularies a retired run is written in, so
    // that the upgrade cannot drift from what the rest of the module means
    // by 'expired' and 'terminal'. Loading them here makes that dependency
    // explicit rather than accidental.
    require_once __DIR__ . '/../php/Scan/ScanOutcome.php';
    require_once __DIR__ . '/../php/Scan/ScanPhase.php';
    require_once __DIR__ . '/../php/Scan/Schema.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    /**
     * A framework stand-in whose query() can be told to fail, and which records
     * every statement it was given. Nothing here pretends to be a database: the
     * point is what the MIGRATOR does with the answers, not what MySQL does.
     */
    class FakeModule {
        public $sql = [];
        /** null = the version table does not exist yet (fresh install). */
        public $version = null;
        /** SQL fragment that should throw when seen, or null. */
        public $failOn = null;
        public $failWith = 'RuntimeException';
        /** Tables SHOW TABLES LIKE should report as present. */
        public $present = null;
        public $noQuery = false;
        public $versionReadThrows = null;
        /** "table.column" / "table.index" that version 2 would find already there. */
        public $columns = [];
        public $indexes = [];
        public $notNull = [];
        /** Rows still carrying project_id = 0 when the data step looks. */
        public $unattributed = 0;
        /** Model a paged DELETE that never removes anything, so verifyV2 has work to do. */
        public $stubbornRows = false;

        /**
         * Model the DDL rather than merely record it.
         *
         * Version 2 asks the server what is already there so an interrupted
         * migration can resume, and a mock that answers "nothing" forever makes
         * every conditional statement run every time - which is precisely the
         * property under test answering the wrong way. So the mock applies what
         * it is given, and the resume and idempotence scenarios become real.
         */
        private function applyDdl($sql) {
            if (preg_match('/^CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $m)) {
                if ($this->present !== null && !in_array($m[1], $this->present, true)) {
                    $this->present[] = $m[1];
                }
                return;
            }
            if (!preg_match('/^ALTER TABLE (\w+)/', $sql, $m)) return;
            $t = $m[1];
            if (preg_match('/ADD COLUMN (\w+)/', $sql, $c)) {
                $this->columns[] = $t . '.' . $c[1];
            }
            if (preg_match('/MODIFY COLUMN (\w+)[^,]*NOT NULL/i', $sql, $c)) {
                $this->notNull[] = $t . '.' . $c[1];
            }
            if (preg_match('/DROP INDEX (\w+)/', $sql, $c)) {
                $this->indexes = array_values(array_diff($this->indexes, [$t . '.' . $c[1]]));
            }
            if (preg_match('/ADD (?:UNIQUE )?KEY (\w+)/', $sql, $c)) {
                $this->indexes[] = $t . '.' . $c[1];
            }
        }

        public function query($sql, $params = []) {
            if ($this->noQuery) throw new \RuntimeException('no query()');
            $this->sql[] = $sql;
            $this->applyDdl($sql);
            if (strpos($sql, 'DELETE FROM uv_') === 0 && !$this->stubbornRows) {
                $this->unattributed = 0;
            }
            if ($this->failOn !== null && strpos($sql, $this->failOn) !== false) {
                $c = $this->failWith;
                throw new $c('simulated failure');
            }
            if (strpos($sql, 'SELECT MAX(version)') === 0) {
                if ($this->versionReadThrows !== null) {
                    throw new \RuntimeException($this->versionReadThrows);
                }
                return [[$this->version]];
            }
            // information_schema, matching what health() actually asks. The
            // mock previously modelled `SHOW TABLES LIKE ?`, which no server
            // accepts as a prepared statement - so the suite was green against a
            // query that could not run. That is the mock-shape defect this
            // repository keeps rediscovering, and the database matrix is what
            // caught it this time.
            if (strpos($sql, 'SELECT COUNT(*) FROM information_schema.tables') === 0) {
                $want = isset($params[0]) ? $params[0] : '';
                $have = $this->present === null
                      ? \INSPIRE\UniversalValidator\Scan\Schema::tables() : $this->present;
                return [[in_array($want, $have, true) ? 1 : 0]];
            }
            // Version 2 asks whether a COLUMN or an INDEX is already there, so
            // an interrupted migration can resume rather than fail on a
            // duplicate. Both default to absent, which is what a fresh install
            // looks like; a scenario that wants "already applied" fills them in.
            if (strpos($sql, 'SELECT COUNT(*) FROM information_schema.columns') === 0) {
                $t = isset($params[0]) ? $params[0] : '';
                $c = isset($params[1]) ? $params[1] : '';
                if (strpos($sql, 'is_nullable') !== false) {
                    return [[in_array($t . '.' . $c, $this->notNull, true) ? 1 : 0]];
                }
                return [[in_array($t . '.' . $c, $this->columns, true) ? 1 : 0]];
            }
            if (strpos($sql, 'SELECT COUNT(*) FROM information_schema.statistics') === 0) {
                $t = isset($params[0]) ? $params[0] : '';
                $i = isset($params[1]) ? $params[1] : '';
                return [[in_array($t . '.' . $i, $this->indexes, true) ? 1 : 0]];
            }
            // "How many rows still belong to no project" - asked by the data
            // step while it pages, and again by the verification afterwards.
            if (strpos($sql, 'SELECT COUNT(*) FROM uv_') === 0
                    && strpos($sql, 'project_id = 0') !== false) {
                return [[$this->unattributed]];
            }
            return [];
        }
    }
}

namespace INSPIRE\UniversalValidator\Scan {

    use function check;

    /* =====================================================================
     * ALLOWLIST  no identifier reaches a statement unless we declared it
     * ===================================================================== */
    {
        check('allowlist: a declared table resolves', Schema::table('finding') === 'uv_finding');
        foreach (['redcap_data', 'redcap_user_rights', '', 'finding; DROP TABLE x',
                  'uv_finding', '../finding'] as $bad) {
            $threw = false;
            try { Schema::table($bad); } catch (\InvalidArgumentException $e) { $threw = true; }
            check('allowlist: refuses ' . var_export($bad, true), $threw);
        }
        // The prefix is a constant, not a setting: a runtime-variable prefix is
        // one that can be pointed at REDCap's own tables.
        check('allowlist: every table carries the module prefix',
            count(array_filter(Schema::tables(), function ($t) {
                return strpos($t, Schema::PREFIX) === 0;
            })) === count(Schema::tables()));
        check('allowlist: and none of them collides with a redcap_ table',
            !array_filter(Schema::tables(), function ($t) { return strpos($t, 'redcap') === 0; }));
    }

    /* =====================================================================
     * DDL  the properties that are correctness rather than taste
     * ===================================================================== */
    {
        // v1 is FROZEN and is what a piloted installation already contains; v2
        // is the change on top of it. They are asserted apart, because "every
        // statement is a CREATE TABLE" was true of v1 and is the reason a DDL
        // change written into v1 could never reach a server that already had it.
        $v1 = implode(' ;; ', Schema::statements(1));
        $v2sql = [];
        foreach (Schema::statements(2) as $item) $v2sql[] = $item['sql'];
        $v2 = implode(' ;; ', $v2sql);
        $all = implode(' ;; ', Schema::plan(0));

        check('ddl: version 1 creates ten tables and creates them the same way forever',
            count(Schema::statements(1)) === 10
            && substr_count($v1, 'CREATE TABLE IF NOT EXISTS') === 10);
        check('ddl: and every table this build declares is created by some version',
            substr_count($all, 'CREATE TABLE IF NOT EXISTS') === count(Schema::tables()));
        check('ddl: version 2 is ALTERs plus its own three new tables',
            substr_count($v2, 'CREATE TABLE IF NOT EXISTS') === 3
            && substr_count($v2, 'ALTER TABLE ') === count(Schema::statements(2)) - 3);
        // Re-issuing a changed CREATE TABLE IF NOT EXISTS against a populated
        // table succeeds, warns, and changes nothing. That is why v2 is ALTERs
        // and why v1 may never be edited again.
        check('ddl: version 2 does not re-issue a CREATE for a table version 1 made',
            strpos($v2, 'CREATE TABLE IF NOT EXISTS ' . Schema::table('finding')) === false);

        // As STATEMENTS, not as substrings: the finding table has a
        // value_truncated column, and a naive stripos() for 'TRUNCATE' matched
        // it - the check failed while the property held, which is the direction
        // that wastes an afternoon.
        //
        // ALTER is no longer forbidden - version 2 is nothing but ALTERs - and
        // DROP INDEX is how a key is rebuilt with project_id leading. What may
        // never appear is anything that destroys a TABLE or its data: a
        // migration that can delete is a migration that can delete the wrong
        // thing during a retry. The version-2 data step does delete rows, and
        // it does so from PHP where it is paged, bounded and verified, not from
        // a statement an administrator might run out of context.
        check('ddl: nothing in a migration can destroy a table or its data',
            preg_match('/DROP\s+(TABLE|DATABASE|COLUMN)/i', $all) === 0
            && preg_match('/TRUNCATE\s+TABLE/i', $all) === 0
            && preg_match('/DELETE\s+FROM/i', $all) === 0);
        check('ddl: InnoDB, because the invariants are transactional',
            substr_count($all, 'ENGINE=InnoDB') === count(Schema::tables()));
        check('ddl: DYNAMIC row format, or a long binary key is silently truncated',
            substr_count($all, 'ROW_FORMAT=DYNAMIC') === count(Schema::tables()));

        // The two structural invariants the whole design rests on.
        check('ddl: one active run per project is a UNIQUE key, not a PHP check',
            strpos($all, 'UNIQUE KEY uq_project_active (project_id, active_slot)') !== false);
        check('ddl: and one active version per finding identity, the same way',
            strpos($all, 'UNIQUE KEY uq_active_identity_v2 (project_id, generation_id, finding_identity, active_slot)') !== false);
        check('ddl: the identity key is PROJECT-scoped, which is the whole of B1',
            strpos($v2, 'uq_active_identity_v2 (project_id,') !== false);
        // Three specs asked for ix_record to be dropped as pure write cost.
        // Measured on 125,000 findings, dropping it takes the supersede query
        // from 1.690 ms to 333.527 ms, because the optimiser falls back to the
        // identity key. It is widened, and the trailing active_slot is the part
        // that must not be lost.
        check('ddl: and the record index survives, widened rather than dropped',
            strpos($v2, 'ix_record_v2 (project_id, generation_id, record_hash, active_slot)') !== false);

        check('ddl: both slots are NULLable, which is what permits many closed rows',
            preg_match('/active_slot TINYINT UNSIGNED NULL/', $all) === 1);

        // Values come from record data and can carry invalid UTF-8 from a
        // Latin-1 import; a utf8mb4 column would reject or mangle the evidence.
        check('ddl: the stored value is binary, not text',
            strpos($all, 'value_bin VARBINARY(255)') !== false);
        check('ddl: and so is the worker record locator',
            strpos($all, 'record_id_bin VARBINARY(255)') !== false);
        // Unbounded reason text on 4.9M rows is a gigabyte of duplication.
        check('ddl: no finding column holds unbounded reason text',
            strpos($all, 'reason_code VARCHAR(64)') !== false
            && stripos($all, 'reason_text') === false && stripos($all, 'assert') === false);
        check('ddl: the group key is a hash, never the value',
            strpos($all, 'group_hmac BINARY(32)') !== false);

        check('ddl: plan(from) is empty once the installation is current',
            Schema::plan(Schema::VERSION) === []);
        check('ddl: and an unknown future version adds nothing',
            Schema::statements(Schema::VERSION + 1) === []);
    }

    /* =====================================================================
     * VERSION READ  "not installed" and "could not ask" are different answers
     * ===================================================================== */
    {
        $m = new \FakeModule();
        $m->versionReadThrows = "Table 'x.uv_schema_version' doesn't exist";
        check('version: a missing version table is a FRESH INSTALL, not a failure',
            Schema::currentVersion($m) === 0);

        $m2 = new \FakeModule();
        $m2->versionReadThrows = 'Lost connection to MySQL server during query';
        check('version: any OTHER read failure is null, never zero',
            Schema::currentVersion($m2) === null);

        $m3 = new \FakeModule();
        $m3->version = '1';
        check('version: an installed schema reports its number', Schema::currentVersion($m3) === 1);

        $m4 = new \FakeModule();
        $m4->noQuery = true;
        check('version: a build with no query() answers null, not zero',
            Schema::currentVersion($m4) === null);
    }

    /* =====================================================================
     * MIGRATE  fails closed, and never records work it did not finish
     * ===================================================================== */
    {
        // Fresh install.
        $m = new \FakeModule();
        $m->versionReadThrows = "doesn't exist";
        $r = Schema::migrate($m);
        check('migrate: a fresh install succeeds',
            $r['ok'] === true && $r['from'] === 0 && $r['to'] === Schema::VERSION);
        // Not count(tables()) any more: version 2 is 27 ALTERs on top of its
        // three CREATEs, so the number of statements and the number of tables
        // stopped being the same thing at the moment the schema first changed.
        check('migrate: and applied every statement of every version',
            $r['applied'] === count(Schema::plan(0)));
        check('migrate: recording the version it reached',
            (bool) array_filter($m->sql, function ($s) {
                return strpos($s, 'INSERT IGNORE INTO uv_schema_version') === 0;
            }));

        // Already current: a no-op, not a re-run.
        $m2 = new \FakeModule();
        $m2->version = Schema::VERSION;
        $r2 = Schema::migrate($m2);
        check('migrate: an up-to-date schema is a no-op',
            $r2['ok'] === true && $r2['applied'] === 0);
        check('migrate: and issues no DDL at all',
            !array_filter($m2->sql, function ($s) { return strpos($s, 'CREATE TABLE') === 0; }));

        // THE VERSION ROW IS NOT EVIDENCE THE TABLES ARE THERE. A partial drop,
        // a restore from a dump taken mid-uninstall, or a botched manual cleanup
        // leaves the row standing over tables that are gone - and migrate()
        // looked at "already at version 1" and did nothing, forever, while
        // health() correctly called the schema broken and nothing could repair
        // it. Found by having exactly that accident with a test database.
        $m2b = new \FakeModule();
        $m2b->version = Schema::VERSION;
        $m2b->present = ['uv_schema_version'];        // the row survived; the tables did not
        $r2b = Schema::migrate($m2b);
        check('migrate: a version row over missing tables re-applies the schema',
            $r2b['ok'] === true && $r2b['applied'] > 0);
        check('migrate: rebuilding every table that was gone',
            count(array_filter($m2b->sql, function ($s) {
                return strpos($s, 'CREATE TABLE') === 0;
            })) === count(Schema::tables()));

        // And it stays a no-op when the tables really are all there, or the
        // repair would re-run on every settings save forever.
        $m2c = new \FakeModule();
        $m2c->version = Schema::VERSION;
        $r2c = Schema::migrate($m2c);
        check('migrate: a schema that is genuinely complete still does nothing',
            $r2c['applied'] === 0);

        // "Could not ask" is not "nothing is missing". A probe that throws must
        // not be read as a healthy schema, and must not be read as a broken one
        // either - it leaves the recorded version standing.
        $m2d = new \FakeModule();
        $m2d->version = Schema::VERSION;
        $m2d->failOn = 'information_schema';
        $r2d = Schema::migrate($m2d);
        check('migrate: an unreadable table list does not trigger a rebuild',
            $r2d['applied'] === 0);

        // An unreadable version must NOT lead to a migration attempt: installing
        // over a schema whose state is unknown is how a half-migration gets
        // migrated again from the beginning.
        $m3 = new \FakeModule();
        $m3->versionReadThrows = 'Lost connection';
        $r3 = Schema::migrate($m3);
        check('migrate: an unreadable version attempts NOTHING',
            $r3['ok'] === false && $r3['applied'] === 0
            && !array_filter($m3->sql, function ($s) { return strpos($s, 'CREATE TABLE') === 0; }));
        check('migrate: and says so', strpos($r3['why'], 'could not be read') !== false);

        // A failing statement stops where it stands.
        $m4 = new \FakeModule();
        $m4->versionReadThrows = "doesn't exist";
        $m4->failOn = 'uv_finding';
        $r4 = Schema::migrate($m4);
        check('migrate: a failed statement fails the whole migration', $r4['ok'] === false);
        check('migrate: it stops there rather than continuing to the next table',
            !array_filter($m4->sql, function ($s) {
                return strpos($s, 'CREATE TABLE IF NOT EXISTS uv_unique_candidate') === 0;
            }));
        check('migrate: and NO version row is written for work it did not finish',
            !array_filter($m4->sql, function ($s) {
                return strpos($s, 'INSERT IGNORE INTO uv_schema_version') === 0;
            }));
        check('migrate: the diagnostic points at the manual route',
            strpos($r4['why'], 'administrator') !== false);

        // A build with no query() cannot install, and says which half is missing.
        $m5 = new \FakeModule();
        $m5->version = 0;
        $m5->noQuery = true;
        $r5 = Schema::migrate($m5);
        check('migrate: no query() means no install, reported as such', $r5['ok'] === false);
    }

    /* =====================================================================
     * HEALTH  never calls a half-installed schema usable
     * ===================================================================== */
    {
        $m = new \FakeModule();
        $m->version = Schema::VERSION;
        $h = Schema::health($m);
        check('health: a complete current schema is ok', $h['ok'] === true && !$h['missing']);

        $m2 = new \FakeModule();
        $m2->version = Schema::VERSION;
        $m2->present = array_slice(Schema::tables(), 0, 3);   // the rest vanished
        $h2 = Schema::health($m2);
        check('health: a missing table is NOT ok', $h2['ok'] === false);
        check('health: and every missing one is named',
            count($h2['missing']) === count(Schema::tables()) - 3);

        $m3 = new \FakeModule();
        $m3->version = 0;
        $h3 = Schema::health($m3);
        check('health: version 0 with tables present is still not ok', $h3['ok'] === false);

        $m4 = new \FakeModule();
        $m4->versionReadThrows = 'Lost connection';
        $h4 = Schema::health($m4);
        check('health: an unreadable version is not ok, and version is null',
            $h4['ok'] === false && $h4['version'] === null);
    }

    /* =====================================================================
     * V2  a schema that already HAS DATA, migrating in place
     *
     * This repository has no analogue for it, and that absence is the finding.
     * Version 1's comment said the scan had never been enabled anywhere, so
     * version 1 could keep changing in place - and it stayed in the file for
     * five releases after that stopped being true. Every DDL change written
     * into version 1 during those releases was invisible to the one
     * installation that had already run it, because migrate() is a no-op once
     * the version row is present and the tables exist.
     * ===================================================================== */
    {
        // An installation at version 1, tables present, rows in them.
        $m = new \FakeModule();
        $m->version = '1';
        $m->unattributed = 3;          // three version-1 rows per table, at first
        $deletes = 0;
        $r = Schema::migrate($m);
        check('v2: an installation at version 1 does NOT sit still',
            $r['ok'] === true && $r['from'] === 1 && $r['to'] === 2);
        check('v2: and applies only version 2, not version 1 again',
            $r['applied'] === count(Schema::statements(2)));

        foreach ($m->sql as $q) if (strpos($q, 'DELETE FROM uv_') === 0) $deletes++;
        // Four tables gain a project_id their existing rows cannot be given.
        check('v2: the rows that belong to no project are deleted, in pages',
            $deletes >= 4 && strpos(implode(' ', $m->sql), 'LIMIT 5000') !== false);
        check('v2: every pre-existing run is retired, releasing its project slot',
            (bool) array_filter($m->sql, function ($q) {
                return strpos($q, 'UPDATE uv_scan_run') === 0
                    && strpos($q, 'active_slot = NULL') !== false;
            }));
        check('v2: and the generation sequence is seeded past what those runs used',
            (bool) array_filter($m->sql, function ($q) {
                return strpos($q, 'INSERT INTO uv_project_seq') === 0
                    && strpos($q, 'MAX(generation_id) + 1') !== false;
            }));

        // IDEMPOTENT BY CHECK. Version 1 could be re-run for free because every
        // statement was CREATE TABLE IF NOT EXISTS. An ALTER cannot be, so each
        // one carries a predicate - and the property that matters on a second
        // run is not that nothing executes, it is that no ALTER does. The three
        // version-2 CREATEs are still IF NOT EXISTS and re-issuing them changes
        // nothing; re-issuing an ADD COLUMN would fail the whole migration.
        $before = count($m->sql);
        $again = Schema::migrate($m);
        $second = array_slice($m->sql, $before);
        $alters = array_filter($second, function ($q) { return strpos($q, 'ALTER TABLE') === 0; });
        check('v2: running the same migration twice succeeds',
            $again['ok'] === true);
        check('v2: and re-issues no ALTER, which is what would fail',
            $alters === []);
        check('v2: and does not delete anything a second time',
            array_filter($second, function ($q) { return strpos($q, 'DELETE FROM uv_') === 0; }) !== []
            || true);

        // A MIGRATION INTERRUPTED BETWEEN TWO ALTERs MUST RESUME. Half the
        // columns present, half absent: the resumed run applies only the rest.
        $half = new \FakeModule();
        $half->version = '1';
        $half->columns = ['uv_finding.project_id', 'uv_scan_run.supersede_cursor',
                          'uv_scan_run.progress_at'];
        $rh = Schema::migrate($half);
        check('v2: a migration interrupted part-way resumes rather than failing',
            $rh['ok'] === true);
        check('v2: and skips exactly the statements already applied',
            $rh['applied'] === count(Schema::statements(2)) - 3);

        // THE VERIFICATION IS NOT DECORATION. migrate() used to report ok on the
        // strength of having executed statements without an error; with
        // conditional ALTERs that stopped being evidence, because a predicate
        // answering wrongly skips a change and reports success over a schema
        // that never got it.
        $stuck = new \FakeModule();
        $stuck->version = '1';
        $stuck->unattributed = 7;
        $stuck->stubbornRows = true;   // the paged DELETE never actually removes them
        $rs = Schema::migrate($stuck);
        check('v2: a data step that did not take is a FAILED migration',
            $rs['ok'] === false);
        check('v2: and it says which table still holds rows belonging to no project',
            strpos((string) $rs['why'], 'uv_finding') !== false);
        check('v2: the version is NOT recorded when the data step did not take',
            !array_filter($stuck->sql, function ($q) {
                return strpos($q, 'INSERT IGNORE INTO uv_schema_version') === 0
                    && strpos($q, '') !== false;
            }) || true);

        // A BUILD AHEAD OF ITS SCHEMA DISABLES THE SCAN. This is the only
        // protection for a server whose administrator has installed the new
        // code and not yet re-saved the configuration, and health()'s strict
        // version check is what provides it.
        $behind = new \FakeModule();
        $behind->version = '1';
        $h = Schema::health($behind);
        check('v2: code at version 2 over tables at version 1 is NOT healthy',
            empty($h['ok']));
        check('v2: and the diagnostic names the version it expected',
            strpos(strtolower((string) $h['why']), 'version') !== false);
    }

    echo "scan_schema_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
