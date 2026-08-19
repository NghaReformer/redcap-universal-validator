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

        public function query($sql, $params = []) {
            if ($this->noQuery) throw new \RuntimeException('no query()');
            $this->sql[] = $sql;
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
        $all = implode(' ;; ', Schema::plan(0));
        check('ddl: version 1 creates every declared table',
            count(Schema::plan(0)) === count(Schema::tables()));
        check('ddl: every statement is IF NOT EXISTS, so a retry resumes',
            substr_count($all, 'CREATE TABLE IF NOT EXISTS') === count(Schema::tables()));
        // As STATEMENTS, not as substrings: the finding table has a
        // value_truncated column, and a naive stripos() for 'TRUNCATE' matched
        // it - the check failed while the property held, which is the direction
        // that wastes an afternoon.
        check('ddl: nothing in a migration can delete',
            preg_match('/DROP\s+(TABLE|DATABASE|INDEX|COLUMN)/i', $all) === 0
            && preg_match('/TRUNCATE\s+TABLE/i', $all) === 0
            && preg_match('/DELETE\s+FROM/i', $all) === 0
            && preg_match('/ALTER\s+TABLE/i', $all) === 0);
        check('ddl: InnoDB, because the invariants are transactional',
            substr_count($all, 'ENGINE=InnoDB') === count(Schema::tables()));
        check('ddl: DYNAMIC row format, or a long binary key is silently truncated',
            substr_count($all, 'ROW_FORMAT=DYNAMIC') === count(Schema::tables()));

        // The two structural invariants the whole design rests on.
        check('ddl: one active run per project is a UNIQUE key, not a PHP check',
            strpos($all, 'UNIQUE KEY uq_project_active (project_id, active_slot)') !== false);
        check('ddl: and one active version per finding identity, the same way',
            strpos($all, 'UNIQUE KEY uq_active_identity (generation_id, finding_identity, active_slot)') !== false);
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
        check('migrate: and applied every statement', $r['applied'] === count(Schema::tables()));
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

    echo "scan_schema_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
