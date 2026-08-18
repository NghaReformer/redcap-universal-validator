<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * The durable scan's tables: what they are, and whether this installation has
 * them.
 *
 * WHY A CLASS AND NOT A .sql FILE. Two of the plan's non-negotiables are
 * enforced here rather than in prose: a migration failure DISABLES the new scan
 * path and shows an administrator diagnostic (it never falls back to framework
 * logs and never half-installs), and every dynamic table identifier is checked
 * against a strict allowlist before it can reach a statement. Both need code.
 *
 * IDEMPOTENT BY CONSTRUCTION. Every statement is CREATE TABLE IF NOT EXISTS,
 * and the applied version is recorded in the module's own version table. Running
 * migrate() twice is a no-op; running it against a half-created schema completes
 * it. There is no DROP anywhere in this file — a migration that can delete is a
 * migration that can delete the wrong thing during a retry.
 *
 * NOTHING HERE EXECUTES A SCAN. Task 5 of the rebuild plan installs the
 * foundation INERT: the tables exist, the health check answers, and no worker
 * runs until Task 6 enables one. That ordering is deliberate — a persistence bug
 * and a batching bug are indistinguishable if they arrive together.
 *
 * PHP 7.4: no constructor promotion, no match, no enums, no arrow-function
 * bodies with statements. The declared floor is exercised in CI, not assumed.
 */
final class Schema
{
    /**
     * The version this build knows how to install.
     *
     * Bumping it means adding a case to statements() and leaving every earlier
     * case untouched. An installation reports the version it is AT; migrate()
     * applies each missing version in order.
     */
    const VERSION = 1;

    // WHY VERSION 1 STILL CHANGES. The durable scan has never been enabled on
    // any installation - nothing in the module calls migrate(), the feature
    // flag does not exist yet, and the tables are created only by tests. So
    // there is no installation to migrate FROM, and adding a version 2 would
    // mean shipping an upgrade path that no installation could ever take while
    // hiding the real shape of the schema behind it. The moment the first
    // release enables the scan, this stops being true and every change becomes
    // a new version.

    /**
     * Table prefix. One constant, because the plan requires the installation's
     * confirmed convention and this is the single place to change it if a site's
     * differs. It is NOT read from a setting: a prefix that can vary at runtime
     * is a prefix that can be pointed at REDCap's own tables.
     */
    const PREFIX = 'uv_';

    /**
     * Every table this module owns, in creation order.
     *
     * THIS IS THE ALLOWLIST. No identifier reaches a statement unless it is in
     * here — see table(). REDCap's own dynamic identifiers (the per-project log
     * shard) are matched by pattern elsewhere; these are ours and are literal.
     */
    private static $tables = [
        'schema_version',
        'scan_run',
        'scan_record',
        'finding',
        'unique_candidate',
        'unique_group',
        'scan_worker_slot',
        'scan_aggregate',
        'scan_dim',
        'scan_audit',
    ];

    /**
     * A table's real name, or a throw.
     *
     * Callers pass the short name; this is the only function that produces a
     * qualified identifier, and it refuses anything not declared above. A typo
     * therefore fails loudly at the call site instead of interpolating an
     * attacker-influenced or simply wrong name into DDL.
     */
    public static function table($short)
    {
        if (!in_array($short, self::$tables, true)) {
            throw new \InvalidArgumentException('unknown scan table: ' . (string) $short);
        }
        return self::PREFIX . $short;
    }

    /** Every qualified table name, for health checks and uninstall. */
    public static function tables()
    {
        $out = [];
        foreach (self::$tables as $t) $out[] = self::PREFIX . $t;
        return $out;
    }

    /**
     * The DDL for one schema version.
     *
     * Constraints that are correctness rather than taste, and are therefore
     * commented where they live:
     *
     *   - InnoDB with DYNAMIC row format. The plan's keys are binary and long;
     *     COMPACT caps an index prefix at 767 bytes and would silently truncate
     *     one of them on an older default.
     *   - utf8mb4 for text columns; VARBINARY for anything derived from record
     *     data. The module's own L-01 note records that values can carry invalid
     *     UTF-8 from a Latin-1 import, and a utf8mb4 column would reject or
     *     mangle exactly the evidence being stored.
     *   - No column holds an unbounded reason string or an assertion. Those
     *     belong to the RULE and are stored once in scan_dim.
     *
     * @return string[] statements, in order
     */
    public static function statements($version)
    {
        if ((int) $version !== 1) return [];

        $T = function ($s) { return self::table($s); };
        $opts = ' ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4';

        $out = [];

        $out[] = 'CREATE TABLE IF NOT EXISTS ' . $T('schema_version') . ' (
            version SMALLINT UNSIGNED NOT NULL,
            applied_at DATETIME NOT NULL,
            PRIMARY KEY (version)
        )' . $opts;

        // ONE ACTIVE RUN PER PROJECT, enforced by the storage engine rather than
        // by a read-then-write check in PHP, which is a race by construction.
        // active_slot is 1 while the run is live and NULL on every terminal
        // transition; MySQL permits unlimited NULLs in a UNIQUE index, so "at
        // most one active run per project" becomes structurally unviolatable.
        $out[] = 'CREATE TABLE IF NOT EXISTS ' . $T('scan_run') . ' (
            run_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_uuid BINARY(16) NOT NULL,
            project_id INT UNSIGNED NOT NULL,
            run_seq BIGINT UNSIGNED NOT NULL,
            generation_id BIGINT UNSIGNED NOT NULL,
            created_by VARCHAR(255) NOT NULL,
            scope_dag VARCHAR(255) NULL,
            scope_kind VARCHAR(16) NOT NULL,
            run_kind VARCHAR(16) NOT NULL,
            baseline_generation BIGINT UNSIGNED NULL,
            phase VARCHAR(24) NOT NULL,
            terminal VARCHAR(16) NULL,
            coverage VARCHAR(32) NOT NULL,
            detail VARCHAR(16) NOT NULL,
            values_state VARCHAR(24) NOT NULL,
            policy_json MEDIUMTEXT NOT NULL,
            policy_revision INT UNSIGNED NOT NULL,
            fingerprint CHAR(64) NOT NULL,
            fence_open VARCHAR(64) NULL,
            fence_target VARCHAR(64) NULL,
            manifest_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            manifest_done BIGINT UNSIGNED NOT NULL DEFAULT 0,
            cursor_ordinal BIGINT UNSIGNED NOT NULL DEFAULT 0,
            detail_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
            detail_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            lease_owner VARBINARY(64) NULL,
            lease_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
            lease_expires_at DATETIME NULL,
            cancel_requested_at DATETIME NULL,
            error_summary TEXT NULL,
            terminal_reason VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            active_slot TINYINT UNSIGNED NULL,
            PRIMARY KEY (run_id),
            UNIQUE KEY uq_run_uuid (run_uuid),
            UNIQUE KEY uq_project_active (project_id, active_slot),
            KEY ix_project_created (project_id, created_at)
        )' . $opts;

        // (run_id, ordinal) is the traversal key: the worker claims a bounded
        // ordinal range, so progress is a cursor rather than a scan of states.
        // record_id_bin is the WORKER LOCATOR and is never hashed - a hashed
        // presentation id cannot be handed back to REDCap to read a record.
        $out[] = 'CREATE TABLE IF NOT EXISTS ' . $T('scan_record') . ' (
            run_id BIGINT UNSIGNED NOT NULL,
            ordinal BIGINT UNSIGNED NOT NULL,
            record_id_bin VARBINARY(255) NOT NULL,
            record_hash BINARY(32) NOT NULL,
            dag_at_fence VARCHAR(255) NULL,
            state TINYINT UNSIGNED NOT NULL DEFAULT 0,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            version_before VARCHAR(64) NULL,
            version_after VARCHAR(64) NULL,
            version_scanned VARCHAR(64) NULL,
            error_code VARCHAR(64) NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (run_id, ordinal),
            UNIQUE KEY uq_run_record (run_id, record_hash),
            KEY ix_run_state (run_id, state)
        )' . $opts;

        // Sequence intervals, so an incremental run closes and reopens only the
        // rows its changed records touch while an "as of run N" view stays
        // reproducible. One active version per identity is enforced the same way
        // as the run slot: active rows carry active_slot=1, closed rows NULL.
        $out[] = 'CREATE TABLE IF NOT EXISTS ' . $T('finding') . ' (
            finding_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            generation_id BIGINT UNSIGNED NOT NULL,
            finding_identity BINARY(32) NOT NULL,
            valid_from_seq BIGINT UNSIGNED NOT NULL,
            valid_to_seq BIGINT UNSIGNED NULL,
            active_slot TINYINT UNSIGNED NULL,
            record_hash BINARY(32) NOT NULL,
            record_id_bin VARBINARY(255) NOT NULL,
            event_id INT UNSIGNED NULL,
            arm_id INT UNSIGNED NULL,
            instance INT UNSIGNED NOT NULL DEFAULT 1,
            host_form VARCHAR(128) NOT NULL,
            field VARCHAR(128) NOT NULL,
            rule_source_id VARBINARY(128) NOT NULL,
            rule_revision CHAR(64) NOT NULL,
            rule_ord INT UNSIGNED NOT NULL,
            check_type VARCHAR(32) NOT NULL,
            reason_code VARCHAR(64) NOT NULL,
            reason_bits INT UNSIGNED NOT NULL DEFAULT 0,
            severity TINYINT UNSIGNED NOT NULL DEFAULT 0,
            dag_key VARCHAR(255) NULL,
            status_key TINYINT NULL,
            value_bin VARBINARY(255) NULL,
            value_len INT UNSIGNED NULL,
            value_fingerprint BINARY(32) NULL,
            value_truncated TINYINT UNSIGNED NOT NULL DEFAULT 0,
            value_binary TINYINT UNSIGNED NOT NULL DEFAULT 0,
            value_expires_at DATETIME NULL,
            -- Duplicate findings are produced by a finalizer that may have to
            -- restart a group, so they carry which group they belong to and
            -- which finalizer pass wrote them. A staged row has active_slot NULL
            -- and is invisible to every report query; publication flips it. Both
            -- are NULL for every ordinary finding, which is most of them.
            group_hmac BINARY(32) NULL,
            stage_epoch BIGINT UNSIGNED NULL,
            PRIMARY KEY (finding_id),
            UNIQUE KEY uq_active_identity (generation_id, finding_identity, active_slot),
            -- ONE STAGED ROW PER IDENTITY PER FINALIZER PASS. The active-identity
            -- key above cannot do this job: a staged row has active_slot NULL,
            -- and MySQL treats every NULL in a unique index as distinct, so a
            -- retried emission page would insert a second copy of every row it
            -- had already written. Ordinary findings have a NULL stage_epoch and
            -- are unconstrained by this key, which is exactly right - their
            -- uniqueness is the active one.
            UNIQUE KEY uq_staged_identity (generation_id, finding_identity, stage_epoch),
            KEY ix_page (generation_id, active_slot, finding_id),
            KEY ix_group_stage (generation_id, group_hmac, stage_epoch, finding_id),
            KEY ix_filter_form (generation_id, active_slot, host_form, finding_id),
            KEY ix_filter_reason (generation_id, active_slot, reason_code, finding_id),
            KEY ix_filter_dag (generation_id, active_slot, dag_key, finding_id),
            KEY ix_record (generation_id, record_hash)
        )' . $opts;

        // The group key is the HMAC, never the value: a Notes field can be 64 KB
        // and holding it per candidate is a second copy of the project. The full
        // tuple is re-read for a group that actually collides, under the stable
        // -read protocol, and compared byte-for-byte in PHP - MySQL's TRIM and
        // PAD SPACE collations do not agree with PHP's trim().
        $out[] = 'CREATE TABLE IF NOT EXISTS ' . $T('unique_candidate') . ' (
            candidate_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            generation_id BIGINT UNSIGNED NOT NULL,
            rule_source_id VARBINARY(128) NOT NULL,
            rule_revision CHAR(64) NOT NULL,
            group_hmac BINARY(32) NOT NULL,
            scope_key VARCHAR(255) NOT NULL,
            record_hash BINARY(32) NOT NULL,
            record_id_bin VARBINARY(255) NOT NULL,
            event_id INT UNSIGNED NULL,
            instance INT UNSIGNED NOT NULL DEFAULT 1,
            host_form VARCHAR(128) NOT NULL,
            field VARCHAR(128) NOT NULL,
            version_scanned VARCHAR(64) NULL,
            PRIMARY KEY (candidate_id),
            UNIQUE KEY uq_candidate (generation_id, group_hmac, record_hash, field, event_id, instance),
            KEY ix_group (generation_id, group_hmac)
        )' . $opts;

        $out[] = 'CREATE TABLE IF NOT EXISTS ' . $T('unique_group') . ' (
            group_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            generation_id BIGINT UNSIGNED NOT NULL,
            group_hmac BINARY(32) NOT NULL,
            candidate_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
            verify_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
            emit_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
            phase VARCHAR(24) NOT NULL,
            representative VARBINARY(255) NULL,
            staged_epoch BIGINT UNSIGNED NULL,
            published_epoch BIGINT UNSIGNED NULL,
            distinct_records INT UNSIGNED NOT NULL DEFAULT 0,
            collision_state TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (group_id),
            UNIQUE KEY uq_group (generation_id, group_hmac)
        )' . $opts;

        // Installation-wide, not per project: the resource being rationed is the
        // server, and two projects scanning at once cost the same as one project
        // scanning twice. Rows are precreated so leasing is an UPDATE with a
        // predicate rather than an INSERT that can race.
        $out[] = 'CREATE TABLE IF NOT EXISTS ' . $T('scan_worker_slot') . ' (
            slot_no SMALLINT UNSIGNED NOT NULL,
            owner VARBINARY(64) NULL,
            epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
            run_id BIGINT UNSIGNED NULL,
            expires_at DATETIME NULL,
            PRIMARY KEY (slot_no)
        )' . $opts;

        // Counted and sampled, never listed. 100,000 unreadable records is one
        // row here; it was 100,000 strings in RAM in the legacy path.
        $out[] = 'CREATE TABLE IF NOT EXISTS ' . $T('scan_aggregate') . ' (
            aggregate_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(48) NOT NULL,
            axis1 VARCHAR(255) NULL,
            axis2 VARCHAR(255) NULL,
            cnt BIGINT UNSIGNED NOT NULL DEFAULT 0,
            samples TEXT NULL,
            blocks_coverage TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (aggregate_id),
            UNIQUE KEY uq_aggregate (run_id, kind, axis1, axis2),
            KEY ix_run_kind (run_id, kind)
        )' . $opts;

        // Labels once per run, never once per finding. At 4.9M findings the
        // difference between a joined label and a stored one is the difference
        // between a report and a second copy of the project.
        $out[] = 'CREATE TABLE IF NOT EXISTS ' . $T('scan_dim') . ' (
            dim_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            generation_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(32) NOT NULL,
            dim_key VARBINARY(191) NOT NULL,
            label TEXT NULL,
            meta MEDIUMTEXT NULL,
            PRIMARY KEY (dim_id),
            UNIQUE KEY uq_dim (generation_id, kind, dim_key)
        )' . $opts;

        $out[] = 'CREATE TABLE IF NOT EXISTS ' . $T('scan_audit') . ' (
            audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id INT UNSIGNED NOT NULL,
            run_id BIGINT UNSIGNED NULL,
            event VARCHAR(48) NOT NULL,
            actor VARCHAR(255) NULL,
            detail TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (audit_id),
            KEY ix_project_created (project_id, created_at)
        )' . $opts;

        return $out;
    }

    /**
     * Every statement needed to bring an installation from $from to VERSION.
     *
     * Separate from statements() so a caller can see the whole plan before
     * executing any of it - which is what lets an administrator run the DDL by
     * hand when the module's own database user holds no CREATE grant.
     */
    public static function plan($from = 0)
    {
        $out = [];
        for ($v = ((int) $from) + 1; $v <= self::VERSION; $v++) {
            foreach (self::statements($v) as $sql) $out[] = $sql;
        }
        return $out;
    }

    // -- installation ------------------------------------------------------

    /**
     * The schema version this installation is AT, or null when it cannot be
     * established.
     *
     * NULL is not zero. Zero means "nothing installed, install it"; null means
     * "we could not ask", and the two must not lead to the same action - one
     * installs, the other refuses and says why. A failed read that installs is
     * how a half-migrated schema gets migrated again from the beginning.
     */
    public static function currentVersion($module)
    {
        try {
            if (!is_callable([$module, 'query'])) return null;
            $q = $module->query('SELECT MAX(version) FROM ' . self::table('schema_version'), []);
            if (!$q) return null;
            $row = self::firstRow($q);
            if ($row === null) return 0;
            $v = isset($row[0]) ? $row[0] : null;
            return ($v === null || $v === '') ? 0 : (int) $v;
        } catch (\Throwable $e) {
            // The version table not existing is the ordinary fresh-install case
            // and is NOT a failure to read - but only when the error says so.
            $msg = $e->getMessage();
            if (stripos($msg, "doesn't exist") !== false || stripos($msg, 'not exist') !== false
                || stripos($msg, '1146') !== false) {
                return 0;
            }
            return null;
        }
    }

    /**
     * Bring the schema up to VERSION, or explain why not.
     *
     * FAILS CLOSED AND LOUD. A statement that errors stops the migration where
     * it stands and returns ok=false; it does not continue to the next table, it
     * does not retry, and it never writes a version row for work it did not
     * finish. Every statement is CREATE TABLE IF NOT EXISTS, so the next attempt
     * resumes rather than conflicts.
     *
     * @return array{ok: bool, from: ?int, to: int, applied: int, why: ?string}
     */
    public static function migrate($module)
    {
        $from = self::currentVersion($module);
        if ($from === null) {
            return ['ok' => false, 'from' => null, 'to' => self::VERSION, 'applied' => 0,
                    'why' => 'the schema version could not be read, so no migration was attempted'];
        }
        if ($from >= self::VERSION) {
            return ['ok' => true, 'from' => $from, 'to' => self::VERSION, 'applied' => 0, 'why' => null];
        }
        if (!is_callable([$module, 'query'])) {
            return ['ok' => false, 'from' => $from, 'to' => self::VERSION, 'applied' => 0,
                    'why' => 'this framework build exposes no query() method, so the module cannot '
                           . 'install its own schema'];
        }

        $applied = 0;
        for ($v = $from + 1; $v <= self::VERSION; $v++) {
            foreach (self::statements($v) as $sql) {
                try {
                    $module->query($sql, []);
                } catch (\Throwable $e) {
                    return ['ok' => false, 'from' => $from, 'to' => self::VERSION, 'applied' => $applied,
                            'why' => 'schema version ' . $v . ' could not be installed (' . get_class($e)
                                   . '). The scan stays disabled; an administrator can install the '
                                   . 'schema by hand from Schema::plan().'];
                }
                $applied++;
            }
            try {
                $module->query('INSERT IGNORE INTO ' . self::table('schema_version')
                    . ' (version, applied_at) VALUES (?, ?)', [$v, date('Y-m-d H:i:s')]);
            } catch (\Throwable $e) {
                return ['ok' => false, 'from' => $from, 'to' => self::VERSION, 'applied' => $applied,
                        'why' => 'version ' . $v . ' installed but could not be recorded ('
                               . get_class($e) . '), so the schema state is unknown and the scan '
                               . 'stays disabled'];
            }
        }
        return ['ok' => true, 'from' => $from, 'to' => self::VERSION, 'applied' => $applied, 'why' => null];
    }

    /**
     * What an administrator needs to see on the diagnostic page.
     *
     * Reports rather than repairs. The one thing it must never do is claim the
     * durable scan is usable when a table is missing, because that claim is the
     * input to enabling the feature.
     *
     * @return array{ok: bool, version: ?int, expected: int, missing: string[], why: ?string}
     */
    public static function health($module)
    {
        $v = self::currentVersion($module);
        if ($v === null) {
            return ['ok' => false, 'version' => null, 'expected' => self::VERSION, 'missing' => [],
                    'why' => 'the schema version could not be read'];
        }
        // information_schema, NOT `SHOW TABLES LIKE ?`.
        //
        // The database matrix caught this on its first run, on MySQL 5.7 and 8.0
        // alike: SHOW is not preparable in the client protocol, so a bound
        // parameter makes the statement fail rather than match. health() then
        // caught its own exception and reported a complete schema as broken -
        // the safe direction, but wrong, and it would have disabled the durable
        // scan on every installation.
        //
        // This form is preparable everywhere, scopes to the current database
        // explicitly rather than implicitly, and - unlike LIKE - treats the
        // underscores in our table names as literal characters instead of
        // single-character wildcards.
        $missing = [];
        foreach (self::tables() as $t) {
            try {
                $q = $module->query('SELECT COUNT(*) FROM information_schema.tables
                    WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
                $row = $q ? self::firstRow($q) : null;
                if ($row === null || (int) (isset($row[0]) ? $row[0] : 0) < 1) $missing[] = $t;
            } catch (\Throwable $e) {
                return ['ok' => false, 'version' => $v, 'expected' => self::VERSION, 'missing' => [],
                        'why' => 'the table list could not be read: ' . get_class($e)];
            }
        }
        if ($missing) {
            return ['ok' => false, 'version' => $v, 'expected' => self::VERSION, 'missing' => $missing,
                    'why' => count($missing) . ' table(s) are missing; the durable scan stays disabled'];
        }
        if ($v !== self::VERSION) {
            return ['ok' => false, 'version' => $v, 'expected' => self::VERSION, 'missing' => [],
                    'why' => 'the schema is at version ' . $v . ' but this build expects '
                           . self::VERSION];
        }
        return ['ok' => true, 'version' => $v, 'expected' => self::VERSION, 'missing' => [], 'why' => null];
    }

    /** One row from whatever shape the framework's query() returned, or null. */
    private static function firstRow($q)
    {
        if (is_array($q)) return isset($q[0]) ? $q[0] : null;
        if (is_object($q) && is_callable([$q, 'fetch_row'])) {
            $r = $q->fetch_row();
            return $r === null ? null : $r;
        }
        if (is_object($q) && is_callable([$q, 'fetch_assoc'])) {
            $r = $q->fetch_assoc();
            return $r === null ? null : array_values($r);
        }
        return null;
    }
}
