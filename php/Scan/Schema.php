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
    const VERSION = 2;

    // VERSION 1 IS FROZEN, BYTE FOR BYTE, FOREVER.
    //
    // The paragraph that used to stand here said the durable scan had never
    // been enabled on any installation, so version 1 could keep changing in
    // place. That stopped being true at 1.9.0, when it was enabled and piloted
    // on a live server - and it stayed in the file for five releases while
    // being false. It cost the whole of version 2: every DDL change written
    // into statements(1) between 1.9.0 and now was invisible to the piloted
    // installation, because migrate() is a no-op once the version row is
    // present and the tables exist.
    //
    // statements(1) is now the DEFINITION of what a field installation
    // contains. Editing it makes the code and the field disagree, silently,
    // with no way for either to notice.
    //
    // VERSION 2 IS ALTERs, NOT RE-ISSUED CREATEs. Re-issuing a CREATE TABLE IF
    // NOT EXISTS with a changed column list against an existing populated table
    // succeeds, emits one warning, and changes nothing - so a schema change
    // written that way reaches only installations that never had the table.

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
        // Version 2.
        'project_seq',
        'scan_plan',
        'rate_bucket',
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
        if ((int) $version === 2) return self::statementsV2();
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
            -- Catch-up walks the change log by record id and rolls up findings
            -- by finding id, and both must survive the request they started in.
            -- A cursor that lived only in a variable would make every reconciler
            -- restart from the beginning, which on a project with a long change
            -- log is a phase that never finishes.
            catchup_cursor VARBINARY(255) NULL,
            catchup_round SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            catchup_dirty TINYINT UNSIGNED NOT NULL DEFAULT 0,
            rollup_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
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
            -- NOT NULL with an empty default, and that is correctness rather
            -- than tidiness. The unique key below is what makes an aggregate
            -- ACCUMULATE across bounded pages, and MySQL counts every NULL in a
            -- unique index as distinct - so a nullable axis would give every
            -- page a row of its own and the summary would report the count of
            -- one page as the whole. No Data Access Group is a real answer and
            -- the empty string is how it is written. (Second time this trap has
            -- been found here; the first was the staged findings key.)
            axis1 VARCHAR(255) NOT NULL DEFAULT \'\',
            axis2 VARCHAR(255) NOT NULL DEFAULT \'\',
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
     * Version 2: project scoping, the identity fix's storage, and three tables.
     *
     * RETURNS DESCRIPTORS, NOT STRINGS, and that difference is load-bearing.
     * ALTER TABLE x ADD COLUMN y FAILS if y already exists, and migrate() fails
     * closed on the first statement error - so a migration interrupted between
     * two ALTERs could never be resumed, which breaks this class's promise that
     * running it against a half-created schema completes it, and kills the
     * repair branch that re-applies every version when a table has gone
     * missing. Each descriptor carries a skipIf predicate, checked against
     * information_schema in the same preparable form health() already uses.
     *
     * "Idempotent by construction" becomes idempotent BY CHECK from here on.
     * Allow-listing MySQL's 1060/1061/1091 would have been cheaper and is
     * wrong: those codes are shared with real errors, and this class's whole
     * design is to fail loud.
     *
     * NO ALGORITHM=INPLACE, LOCK=NONE. Naming the algorithm turns "this server
     * cannot do it online" into a migration FAILURE. Let the server choose.
     *
     * @return array[] each ['sql' => string, 'skipIf' => ?array]
     */
    private static function statementsV2()
    {
        $T = function ($s) { return self::table($s); };
        $opts = ' ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4';
        $out = [];

        $create = function ($sql) use (&$out) { $out[] = ['sql' => $sql, 'skipIf' => null]; };
        $col = function ($table, $column, $sql) use (&$out) {
            $out[] = ['sql' => $sql, 'skipIf' => ['column', $table, $column]];
        };
        $idx = function ($table, $index, $sql) use (&$out) {
            $out[] = ['sql' => $sql, 'skipIf' => ['index', $table, $index]];
        };

        // -- new tables ------------------------------------------------------

        // THE GENERATION IS A PER-PROJECT SEQUENCE, which is the whole of the
        // root cause. It was the literal 1 for every run of every project, so
        // the second scan of any project re-inserted identities that were
        // already there and the batch was refused - forty times, identically,
        // in the pilot, with no exit, because a rolled-back commit could not
        // increment the attempt counter that was supposed to give up.
        //
        // ONE counter, not two: run_seq and generation_id are the same
        // monotonic number for a full run, and that is what makes
        // valid_from_seq/valid_to_seq a real interval rather than two columns
        // that happen to be filled in.
        $create('CREATE TABLE IF NOT EXISTS ' . $T('project_seq') . ' (
            project_id INT UNSIGNED NOT NULL,
            next_seq BIGINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (project_id)
        )' . $opts);

        // Planning becomes a resumable phase. It used to walk, hash and insert
        // the entire record list inside the single scan-start request, with
        // both of ScanPlanner::stream()'s guards passed as null - so a project
        // large enough to exceed max_execution_time died mid-walk, an
        // uncatchable fatal, leaving a run in `planning` holding the project's
        // only slot with nothing anywhere able to reap it.
        //
        // plan_carry is MEDIUMBLOB because it must be: it holds a tie page of
        // record ids at up to 255 bytes each, hex-encoded and joined, which
        // exceeds BLOB's 65,535 limit.
        $create('CREATE TABLE IF NOT EXISTS ' . $T('scan_plan') . ' (
            run_id BIGINT UNSIGNED NOT NULL,
            plan_cursor VARBINARY(255) NULL,
            plan_carry MEDIUMBLOB NULL,
            plan_pages INT UNSIGNED NOT NULL DEFAULT 0,
            plan_listed BIGINT UNSIGNED NOT NULL DEFAULT 0,
            plan_out_of_scope BIGINT UNSIGNED NOT NULL DEFAULT 0,
            plan_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            plan_done TINYINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (run_id)
        )' . $opts);

        // The survey uniqueness endpoint is unauthenticated and rate-limits
        // itself with a read-modify-write over a system setting, so concurrent
        // requests lose increments - worst exactly under the flood the tier was
        // written for. A counter the database owns cannot lose one.
        $create('CREATE TABLE IF NOT EXISTS ' . $T('rate_bucket') . ' (
            project_id INT UNSIGNED NOT NULL,
            bucket INT UNSIGNED NOT NULL,
            hits INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (project_id, bucket)
        )' . $opts);

        // -- scan_run --------------------------------------------------------

        $r = $T('scan_run');
        // How far the previous generation's active rows have been closed, so
        // superseding is resumable rather than one unbounded statement.
        $col($r, 'supersede_cursor',
            'ALTER TABLE ' . $r . ' ADD COLUMN supersede_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0');
        // updated_at moves on every touch, including a touch that did nothing.
        // "Has this run made PROGRESS" is a different question, and the
        // stale-run reaper is the caller that needs it.
        $col($r, 'progress_at',
            'ALTER TABLE ' . $r . ' ADD COLUMN progress_at DATETIME NULL');
        // The outcome, persisted rather than re-derived on every read. NULL is
        // "not yet decided", which is distinct from decided-and-false.
        $col($r, 'clean',
            'ALTER TABLE ' . $r . ' ADD COLUMN clean TINYINT UNSIGNED NULL');
        $col($r, 'gap_count',
            'ALTER TABLE ' . $r . ' ADD COLUMN gap_count BIGINT UNSIGNED NOT NULL DEFAULT 0');
        $col($r, 'rule_problem_count',
            'ALTER TABLE ' . $r . ' ADD COLUMN rule_problem_count BIGINT UNSIGNED NOT NULL DEFAULT 0');
        // Records that reached a terminal state WITHOUT being examined, counted
        // apart from manifest_done. A progress figure that counts them reads
        // 100% over a run that examined a fraction, which is how a wedged run
        // came to look finished.
        $col($r, 'not_examined',
            'ALTER TABLE ' . $r . ' ADD COLUMN not_examined BIGINT UNSIGNED NOT NULL DEFAULT 0');

        // -- scan_record -----------------------------------------------------

        // PER-RECORD CLAIM TOKENS. lease_epoch is incremented in exactly one
        // statement in the whole codebase, inside cancel(), so takeover fencing
        // did not exist: a second worker could re-claim a stale worker's rows
        // while both held the same epoch, and the first worker's later commit
        // passed the fence. Bumping the epoch on takeover was the obvious fix
        // and is wrong - it invalidates the whole run's fence rather than the
        // records that actually moved. The claim belongs on the row.
        $rec = $T('scan_record');
        $col($rec, 'claim_owner',
            'ALTER TABLE ' . $rec . ' ADD COLUMN claim_owner VARBINARY(64) NULL');
        $col($rec, 'claim_seq',
            'ALTER TABLE ' . $rec . ' ADD COLUMN claim_seq BIGINT UNSIGNED NOT NULL DEFAULT 0');

        // -- finding ---------------------------------------------------------

        // uv_finding, uv_unique_candidate, uv_unique_group and uv_scan_dim
        // carried NO project_id at all, and every query over them filtered by
        // generation alone. Reproduced against MySQL 8.0.46: one project's
        // rollup wrote another project's instrument and Data Access Group names
        // into its summary; one project's retention purge deleted every
        // project's findings installation-wide; and one project's unfinished
        // duplicate group blocked a different project's run from ever
        // finishing. The finding IDENTITY was always project-safe, which is
        // exactly why the corruption was silent instead of a key error.
        //
        // DEFAULT 0 IS PERMANENT, not a migration convenience. It is what lets
        // ADD COLUMN succeed against a populated table, and removing it later
        // would make every insert written before the code catches up fail under
        // STRICT_TRANS_TABLES. The fail-loud lives in PHP: the writers refuse a
        // missing or zero project id.
        $f = $T('finding');
        $col($f, 'project_id',
            'ALTER TABLE ' . $f . ' ADD COLUMN project_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER finding_id');

        // Every key rebuilt with project_id LEADING, so a project-scoped query
        // can use it. Dropped and added in one statement each, so the table is
        // walked once per key rather than twice.
        $idx($f, 'uq_active_identity_v2', 'ALTER TABLE ' . $f . '
            DROP INDEX uq_active_identity,
            ADD UNIQUE KEY uq_active_identity_v2 (project_id, generation_id, finding_identity, active_slot)');
        $idx($f, 'uq_staged_identity_v2', 'ALTER TABLE ' . $f . '
            DROP INDEX uq_staged_identity,
            ADD UNIQUE KEY uq_staged_identity_v2 (project_id, generation_id, finding_identity, stage_epoch)');
        $idx($f, 'ix_page_v2', 'ALTER TABLE ' . $f . '
            DROP INDEX ix_page,
            ADD KEY ix_page_v2 (project_id, generation_id, active_slot, finding_id)');
        $idx($f, 'ix_group_stage_v2', 'ALTER TABLE ' . $f . '
            DROP INDEX ix_group_stage,
            ADD KEY ix_group_stage_v2 (project_id, generation_id, group_hmac, stage_epoch, finding_id)');
        $idx($f, 'ix_filter_form_v2', 'ALTER TABLE ' . $f . '
            DROP INDEX ix_filter_form,
            ADD KEY ix_filter_form_v2 (project_id, generation_id, active_slot, host_form, finding_id)');
        $idx($f, 'ix_filter_reason_v2', 'ALTER TABLE ' . $f . '
            DROP INDEX ix_filter_reason,
            ADD KEY ix_filter_reason_v2 (project_id, generation_id, active_slot, reason_code, finding_id)');
        $idx($f, 'ix_filter_dag_v2', 'ALTER TABLE ' . $f . '
            DROP INDEX ix_filter_dag,
            ADD KEY ix_filter_dag_v2 (project_id, generation_id, active_slot, dag_key, finding_id)');

        // ix_record IS WIDENED, NOT DROPPED, and three separate specs asked for
        // it to be dropped as pure write cost. They were right about the tree
        // as it shipped - no query filtered uv_finding by record_hash - and
        // wrong from the moment a re-examined record has to close its own prior
        // findings, which is exactly "WHERE <project/generation> AND
        // record_hash = ? AND active_slot = 1", run once per record per batch.
        // Measured on 125,000 findings: 1.690 ms with this key, 333.527 ms
        // without it, because the optimiser falls back to the identity key and
        // examines 61,268 rows. The trailing active_slot is the part that must
        // not be lost.
        $idx($f, 'ix_record_v2', 'ALTER TABLE ' . $f . '
            DROP INDEX ix_record,
            ADD KEY ix_record_v2 (project_id, generation_id, record_hash, active_slot)');

        // The report filters by check type. Without this it filters by scan.
        $idx($f, 'ix_filter_type', 'ALTER TABLE ' . $f . '
            ADD KEY ix_filter_type (project_id, generation_id, active_slot, check_type, finding_id)');

        // -- unique_candidate ------------------------------------------------

        $uc = $T('unique_candidate');
        $col($uc, 'project_id',
            'ALTER TABLE ' . $uc . ' ADD COLUMN project_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER candidate_id');
        // event_id BECOMES NOT NULL WITH A ZERO SENTINEL, and this is
        // correctness rather than tidiness. It is NULL on every classic
        // project, MySQL counts each NULL in a unique index as distinct, so
        // insertCandidate's ON DUPLICATE KEY UPDATE never fired there and
        // duplicate candidate rows accumulated. Harmless only because
        // discover() counts DISTINCT record hashes - one line away from
        // reporting a duplicate that is one record counted twice. Every reader
        // maps 0 back to null at the boundary.
        $out[] = ['sql' => 'ALTER TABLE ' . $uc . ' MODIFY COLUMN event_id INT UNSIGNED NOT NULL DEFAULT 0',
                  'skipIf' => ['notnull', $uc, 'event_id']];
        $idx($uc, 'uq_candidate_v2', 'ALTER TABLE ' . $uc . '
            DROP INDEX uq_candidate,
            ADD UNIQUE KEY uq_candidate_v2 (project_id, generation_id, group_hmac, record_hash, field, event_id, instance)');
        $idx($uc, 'ix_group_v2', 'ALTER TABLE ' . $uc . '
            DROP INDEX ix_group,
            ADD KEY ix_group_v2 (project_id, generation_id, group_hmac)');

        // -- unique_group ----------------------------------------------------

        $ug = $T('unique_group');
        $col($ug, 'project_id',
            'ALTER TABLE ' . $ug . ' ADD COLUMN project_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER group_id');
        $idx($ug, 'uq_group_v2', 'ALTER TABLE ' . $ug . '
            DROP INDEX uq_group,
            ADD UNIQUE KEY uq_group_v2 (project_id, generation_id, group_hmac)');
        // nextUnfinished() filters on phase and had no index for it, so it
        // walked the group index and got slower as groups were published:
        // measured 2.88 ms with none settled, 283.82 ms with all settled, and a
        // flat 1.49-1.73 ms with this key.
        $idx($ug, 'ix_pending', 'ALTER TABLE ' . $ug . '
            ADD KEY ix_pending (project_id, generation_id, phase, group_hmac)');

        // -- scan_dim --------------------------------------------------------

        $d = $T('scan_dim');
        $col($d, 'project_id',
            'ALTER TABLE ' . $d . ' ADD COLUMN project_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER dim_id');
        $idx($d, 'uq_dim_v2', 'ALTER TABLE ' . $d . '
            DROP INDEX uq_dim,
            ADD UNIQUE KEY uq_dim_v2 (project_id, generation_id, kind, dim_key)');

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
            // Version 2 onwards returns DESCRIPTORS so migrate() can skip a
            // change already made. An administrator running this by hand wants
            // the statement, and gets every one of them: a conditional skipped
            // here would be a statement they never saw and never ran.
            foreach (self::statements($v) as $item) {
                $out[] = is_array($item) ? $item['sql'] : $item;
            }
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
        // THE VERSION ROW IS NOT EVIDENCE THAT THE TABLES ARE THERE, and this
        // is where trusting it costs most. A partial drop, a restore from a
        // dump taken mid-uninstall, or a botched manual cleanup can leave the
        // version row standing over tables that are gone - and migrate() would
        // then look at "already at version 1" and do nothing, forever, while
        // health() correctly reported the schema broken and nothing on the
        // installation was able to repair it.
        //
        // Found while cleaning up after an interrupted test run, which is the
        // same accident an administrator can have with a database restore.
        //
        // Cheap to be right: every statement is CREATE TABLE IF NOT EXISTS, so
        // re-applying costs one no-op per existing table and rebuilds whatever
        // is missing. Ask the facts, not the flag.
        $missing = self::missingTables($module);
        if ($from >= self::VERSION && $missing === []) {
            return ['ok' => true, 'from' => $from, 'to' => self::VERSION, 'applied' => 0, 'why' => null];
        }
        if ($from >= self::VERSION && $missing !== null && $missing !== []) {
            $from = 0;      // re-apply every version; IF NOT EXISTS makes it safe
        }
        if (!is_callable([$module, 'query'])) {
            return ['ok' => false, 'from' => $from, 'to' => self::VERSION, 'applied' => 0,
                    'why' => 'this framework build exposes no query() method, so the module cannot '
                           . 'install its own schema'];
        }

        $applied = 0;
        for ($v = $from + 1; $v <= self::VERSION; $v++) {
            foreach (self::statements($v) as $item) {
                $sql  = is_array($item) ? $item['sql'] : $item;
                $skip = (is_array($item) && isset($item['skipIf'])) ? $item['skipIf'] : null;
                try {
                    // IDEMPOTENT BY CHECK from version 2 on. Version 1 is every
                    // statement a CREATE TABLE IF NOT EXISTS, so re-running it
                    // is free; an ALTER is not, and a migration interrupted
                    // between two of them has to be resumable or this class's
                    // promise to complete a half-created schema is a lie.
                    if ($skip !== null && self::alreadyApplied($module, $skip)) continue;
                    $module->query($sql, []);
                } catch (\Throwable $e) {
                    return ['ok' => false, 'from' => $from, 'to' => self::VERSION, 'applied' => $applied,
                            'why' => 'schema version ' . $v . ' could not be installed (' . get_class($e)
                                   . '). The scan stays disabled; an administrator can install the '
                                   . 'schema by hand from Schema::plan().'];
                }
                $applied++;
            }
            // DDL FIRST, THEN WHAT THE DDL MEANS. Version 2 gives four tables a
            // project_id they did not have; the rows already in them belong to
            // no project and cannot be attributed after the fact, so they go.
            if ($v === 2) {
                try {
                    self::upgradeDataV2($module);
                } catch (\Throwable $e) {
                    return ['ok' => false, 'from' => $from, 'to' => self::VERSION, 'applied' => $applied,
                            'why' => 'schema version 2 was installed but its data could not be '
                                   . 'brought forward (' . get_class($e) . '), so the version is NOT '
                                   . 'recorded and the scan stays disabled. Re-saving the '
                                   . 'configuration retries it.'];
                }
                $bad = self::verifyV2($module);
                if ($bad !== null) {
                    return ['ok' => false, 'from' => $from, 'to' => self::VERSION, 'applied' => $applied,
                            'why' => 'schema version 2 did not verify after it was applied: ' . $bad
                                   . '. The version is NOT recorded and the scan stays disabled.'];
                }
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

    /**
     * Which of our tables are absent, or NULL when that cannot be established.
     *
     * NULL is not an empty list. "Nothing is missing" and "we could not ask"
     * lead to opposite actions - one proceeds, the other must not pretend the
     * schema is sound - and this file has the same distinction at every other
     * boundary.
     *
     * @return string[]|null
     */
    private static function missingTables($module)
    {
        if (!is_callable([$module, 'query'])) return null;
        $missing = [];
        foreach (self::tables() as $t) {
            try {
                $q = $module->query('SELECT COUNT(*) FROM information_schema.tables
                    WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
                $row = $q ? self::firstRow($q) : null;
                if ($row === null) return null;
                if ((int) (isset($row[0]) ? $row[0] : 0) < 1) $missing[] = $t;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return $missing;
    }

    /**
     * Has this descriptor's change already been made?
     *
     * A probe that cannot be read is NOT "no". Returning false there would run
     * an ALTER that then fails on a duplicate column, and the migration would
     * report a schema fault when the real fault was an unreadable
     * information_schema. It throws instead, and migrate() turns that into a
     * refusal naming the probe that could not be answered.
     *
     * @param array $skipIf [kind, table, name]; kind is column|index|notnull
     */
    private static function alreadyApplied($module, array $skipIf)
    {
        $kind  = isset($skipIf[0]) ? (string) $skipIf[0] : '';
        $table = isset($skipIf[1]) ? (string) $skipIf[1] : '';
        $name  = isset($skipIf[2]) ? (string) $skipIf[2] : '';

        if ($kind === 'column') {
            $sql = 'SELECT COUNT(*) FROM information_schema.columns
                    WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?';
        } elseif ($kind === 'index') {
            $sql = 'SELECT COUNT(*) FROM information_schema.statistics
                    WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?';
        } elseif ($kind === 'notnull') {
            $sql = 'SELECT COUNT(*) FROM information_schema.columns
                    WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
                      AND is_nullable = "NO"';
        } else {
            throw new \RuntimeException('unknown skipIf kind: ' . $kind);
        }

        $q = $module->query($sql, [$table, $name]);
        $row = $q ? self::firstRow($q) : null;
        if ($row === null) {
            throw new \RuntimeException('the schema could not be inspected for '
                . $kind . ' ' . $table . '.' . $name);
        }
        return ((int) (isset($row[0]) ? $row[0] : 0)) > 0;
    }

    /**
     * Bring version-1 DATA to what version 2 means, after version 2's DDL.
     *
     * VERSION-1 SCAN ROWS ARE DELETED, NOT MIGRATED, and that is a decision
     * rather than an omission. Three things changed underneath them in this
     * release: every rule_source_id changed (the naming pass re-indexed a
     * sparse list densely, and every settings rule was named through the
     * annotation branch), the finding tuple gained a locus, and the generation
     * became a per-project sequence. A version-1 finding_identity is therefore
     * not comparable with a post-upgrade one, so superseding across the
     * boundary is impossible and attempting it would match the wrong rows.
     * Keeping them would leave a report mixing two identity schemes whose rows
     * can never be closed - and on a multi-project server they are additionally
     * wrong about which project they describe. The findings are re-derivable by
     * running a scan; the corruption is not detectable by a reader.
     *
     * PAGED, because the one unpaged statement in this module measured 160
     * seconds over 500,000 rows while holding row locks throughout, and this
     * runs inside an administrator's settings save.
     */
    private static function upgradeDataV2($module)
    {
        // Every version-1 row carries project_id = 0: that is the default the
        // ADD COLUMN gave it, and nothing else can have written a zero.
        foreach (['finding', 'unique_candidate', 'unique_group', 'scan_dim'] as $short) {
            $t = self::table($short);
            for ($page = 0; $page < 100000; $page++) {
                $module->query('DELETE FROM ' . $t . ' WHERE project_id = 0 LIMIT 5000', []);
                $q = $module->query('SELECT COUNT(*) FROM ' . $t . ' WHERE project_id = 0', []);
                $row = $q ? self::firstRow($q) : null;
                if ($row === null) {
                    throw new \RuntimeException('could not confirm the version-1 rows were '
                        . 'removed from ' . $t);
                }
                if ((int) (isset($row[0]) ? $row[0] : 0) === 0) break;
            }
        }

        // RETIRE EVERY PRE-EXISTING RUN. Each is holding its project's only
        // slot - the pilot's wedged runs are still there, because nothing reaps
        // them - and none can be resumed: its manifest describes a generation
        // that no longer means anything. Expired is what this module already
        // says for a run whose evidence has gone, and it releases the slot.
        $module->query('UPDATE ' . self::table('scan_run') . '
            SET active_slot = NULL, phase = ?, terminal = ?, coverage = ?, clean = NULL,
                terminal_reason = ?, updated_at = ?
            WHERE active_slot = 1 OR terminal IS NULL',
            [ScanPhase::TERMINAL, ScanOutcome::EXPIRED, ScanOutcome::COV_PARTIAL,
             'ended by the upgrade to schema version 2, which changed what a finding is named',
             date('Y-m-d H:i:s')]);

        // SEED THE SEQUENCE SO NO PROJECT REUSES A GENERATION. The retired runs
        // are gone as reports, but their generation numbers were real and a new
        // run reusing one would collide with whatever survived.
        $module->query('INSERT INTO ' . self::table('project_seq') . ' (project_id, next_seq)
            SELECT project_id, MAX(generation_id) + 1 FROM ' . self::table('scan_run') . '
            GROUP BY project_id
            ON DUPLICATE KEY UPDATE next_seq = GREATEST(next_seq, VALUES(next_seq))', []);
    }

    /**
     * Prove version 2 really is what is on the server before saying so.
     *
     * migrate() used to report ok on the strength of having executed its
     * statements without an error. With conditional ALTERs that stops being
     * evidence: a skipIf that answered wrongly would skip a change and the
     * migration would report success over a schema that never got it.
     *
     * @return ?string null when everything holds, otherwise what does not
     */
    private static function verifyV2($module)
    {
        foreach (['finding', 'unique_candidate', 'unique_group', 'scan_dim'] as $short) {
            $t = self::table($short);
            if (!self::alreadyApplied($module, ['column', $t, 'project_id'])) {
                return $t . ' has no project_id column, so the migration did not take';
            }
            $q = $module->query('SELECT COUNT(*) FROM ' . $t . ' WHERE project_id = 0', []);
            $row = $q ? self::firstRow($q) : null;
            if ($row === null) return $t . ' could not be checked for unattributed rows';
            if ((int) (isset($row[0]) ? $row[0] : 0) > 0) {
                return $t . ' still holds rows belonging to no project';
            }
        }
        if (!self::alreadyApplied($module, ['index', self::table('finding'), 'ix_record_v2'])) {
            return 'the finding table has no ix_record_v2, so closing a re-examined record'
                 . ' would scan the whole generation';
        }
        return null;
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
