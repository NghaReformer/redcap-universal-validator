<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * The durable store, over MySQL or MariaDB.
 *
 * READ ScanStore's invariant list first — I1 to I6 are the specification, and
 * every method below exists to hold one of them. The short version, because it
 * is the thing that is easy to lose in a refactor:
 *
 *   NOTHING IS TRUE UNTIL A FENCED COMPARE-AND-SET SAYS SO.
 *
 * A worker never asks "am I still the owner?" and then acts — that is two
 * statements with a gap in the middle, and the gap is where the other worker
 * lives. Two mechanisms hold that, and the choice between them is not stylistic:
 *
 *   SINGLE-STATEMENT MUTATIONS carry their precondition in the WHERE clause and
 *   are decided by affected() — claim, cancel and finish. Each of these
 *   necessarily CHANGES a column when it succeeds (a cursor advances, a run
 *   leaves its active slot), which is what makes the count meaningful.
 *
 *   MULTI-STATEMENT TRANSACTIONS fence with SELECT ... FOR UPDATE and compare in
 *   PHP — commitBatch. affected() cannot be used here: MySQL reports rows
 *   CHANGED rather than matched, so a fence that rewrites a timestamp inside the
 *   same second changes nothing, reports zero, and rolls back a good batch. That
 *   bug was written, and the local MySQL/MariaDB matrix caught it: MariaDB on
 *   default isolation passed while MySQL failed, so a single-engine test would
 *   have shipped a scan that intermittently discarded work under load.
 *
 * The rule to carry into any new method: if success does not change a value,
 * affected() cannot tell you whether it happened.
 *
 * COUNTED AND UNCOUNTED WRITES. A write whose count decides something goes
 * through exec() and reads affected() in the SAME function, straight after. A
 * write nobody counts goes through execNoCount(), which over the framework
 * saves the ROW_COUNT() round trip - half the traffic of a busy batch. After
 * an uncounted write affected() throws, and the order lint in
 * tests/scan_moduledb_php.php fails the build on any read that does not follow
 * an exec() in its own function body.
 *
 * All of this is exercised against MySQL 5.7/8.0 and MariaDB 10.5/10.11, under
 * the server default isolation and under READ COMMITTED, by tests/mysql/run.php.
 * The same class runs there as runs in REDCap; only ScanDb differs.
 *
 * PHP 7.4 throughout.
 */
final class SqlScanStore implements ScanStore
{
    /**
     * Manifest rows per INSERT. Seven placeholders a row, so 3,500 a statement
     * against MySQL's 65,535 - and small enough that a chunk is a short write.
     */
    const MANIFEST_CHUNK = 500;

    /**
     * Findings per INSERT. 29 placeholders a row, so 14,500 a statement; about
     * 1.3 KB a row at worst, so a chunk stays well under the 4 MB default
     * max_allowed_packet of the oldest server in the matrix.
     */
    const FINDING_CHUNK = 500;

    /** @var ScanDb */
    private $db;

    /** @var callable|null  seconds as a float; microtime(true) when null. See claimPending(). */
    private $clock;

    /**
     * @param callable|null $clock what claimPending() reads the time from, so
     *        a test can step it rather than wait. Anything not callable is
     *        ignored: a stale caller once passed a key string here.
     */
    public function __construct(ScanDb $db, $clock = null)
    {
        $this->db = $db;
        $this->clock = is_callable($clock) ? $clock : null;
    }

    /**
     * The database failed, and this is how the store says so.
     *
     * WHY THESE TWO HELPERS EXIST AT ALL. Four methods below used to end in
     * `catch (\Throwable $e) { rollback(); return false; }`, which spends the
     * fence's own vocabulary on something the fence never decided — see
     * ScanStoreUnavailable's docblock for what that cost. They now end in a
     * throw, and a throw built one way in four places is a throw that will be
     * built two ways by the sixth.
     *
     * DbError::safe() is the only redaction point in this module. Nothing here
     * looks at getMessage() itself.
     */
    private function unavailable(\Throwable $e)
    {
        return ScanStoreUnavailable::from($e);
    }

    /**
     * The same, for a failure inside a transaction.
     *
     * The rollback is wrapped because it can fail too, and on precisely the
     * faults that get us here: a connection that has gone away cannot be told
     * to roll back. An unwrapped rollback would throw a SECOND, unclassified
     * exception and the first one — the one that says what actually happened —
     * would never be seen.
     */
    private function rolledBack(\Throwable $e)
    {
        try {
            $this->db->rollback();
        } catch (\Throwable $ignored) {
            // Nothing to do and nothing to say: the caller is about to be told
            // the storage is unavailable, which is already true.
        }
        return ScanStoreUnavailable::from($e);
    }

    // -- runs ---------------------------------------------------------------

    /**
     * Allocate this project's next sequence number.
     *
     * THIS IS THE ROOT CAUSE, in one method. The generation was the literal 1
     * for every run of every project, because three separate places defaulted
     * it and no caller ever supplied one. With `uq_active_identity` keyed on
     * the generation and nothing superseding the previous run's rows, the
     * SECOND scan of any project re-inserted identities that were already
     * active, the key refused them, and the batch rolled back - forty times,
     * identically, in the pilot.
     *
     * ONE STATEMENT, atomic under InnoDB's row lock on the project's row. No
     * SELECT ... FOR UPDATE and no explicit transaction: a read-then-write is
     * the same race the run slot exists to avoid, and a gap lock on a row that
     * does not exist yet is not portable across the four servers this module
     * supports.
     *
     * LAST_INSERT_ID(expr) sets the session's value explicitly and returns it,
     * so the fresh-project INSERT path and the existing-project UPDATE path
     * both answer with the number they allocated. It is session-scoped and read
     * on the same connection as the write - the same caveat ScanDb already
     * documents for ROW_COUNT().
     */
    private function allocateSeq($pid)
    {
        $this->db->execNoCount('INSERT INTO ' . Schema::table('project_seq') . '
            (project_id, next_seq) VALUES (?, LAST_INSERT_ID(1) + 1)
            ON DUPLICATE KEY UPDATE next_seq = LAST_INSERT_ID(next_seq) + 1', [(int) $pid]);
        $r = $this->db->select('SELECT LAST_INSERT_ID()', []);
        $seq = isset($r[0][0]) ? (int) $r[0][0] : 0;
        if ($seq < 1) {
            // Never a fallback to 1. A generation that silently repeats is the
            // defect this method exists to remove.
            throw new \RuntimeException('the scan could not allocate a generation for this project');
        }
        return $seq;
    }

    /**
     * The highest policy revision of the project's runs, 1 when it has none:
     * what a new run starts at (W10A-N1). One statement over the project's
     * own rows (ix_project_created), which retention keeps few.
     */
    private function projectRevision($pid)
    {
        $r = $this->db->select('SELECT MAX(policy_revision) FROM ' . Schema::table('scan_run')
            . ' WHERE project_id = ?', [(int) $pid]);
        return isset($r[0][0]) && (int) $r[0][0] > 1 ? (int) $r[0][0] : 1;
    }

    /**
     * A claim token for one claim, drawn from the run's own project sequence.
     *
     * NOT A RANDOM NUMBER, and not a timestamp. Random would be adequate for
     * the fence - the token only has to differ from whatever the previous
     * holder was given - but the column is `claim_seq` and a column named for a
     * sequence should hold one, so that "this row was claimed after that one"
     * is a comparison a reader can make rather than a coincidence. A
     * millisecond clock was the other candidate and is not safe: two workers
     * claiming inside the same millisecond would be handed the same token,
     * which is precisely the case the fence exists for.
     *
     * The cost is one extra statement pair per CLAIM - not per record - against
     * a single row this project's one active run already serialises on.
     *
     * ZERO MEANS THE RUN IS GONE, and the caller answers `false` to that -
     * which is the same answer its own fence gives, from one statement later.
     * Throwing here instead would turn "there is no such run", an ordinary
     * refusal that claim() has always handled, into a storage failure.
     *
     * @return int the token, or 0 when there is no such run
     * @throws ScanStoreUnavailable when the storage failed rather than answered
     */
    private function claimToken($runId)
    {
        try {
            $r = $this->db->select('SELECT project_id FROM ' . Schema::table('scan_run')
                . ' WHERE run_id = ?', [$runId]);
            $pid = isset($r[0][0]) ? (int) $r[0][0] : 0;
            if ($pid < 1) return 0;
            return $this->allocateSeq($pid);
        } catch (ScanStoreUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            // No transaction is open yet, so there is nothing to roll back -
            // but the classification is the same one every other read in this
            // file makes, and making it differently here is how the fence's
            // vocabulary ends up on something the fence never decided.
            throw $this->unavailable($e);
        }
    }

    /**
     * I1: at most one active run per project, enforced by the UNIQUE key.
     *
     * The insert simply tries. A duplicate-key error is not an exception in the
     * exceptional sense — it is the answer to "may I start?", arriving from the
     * only component that can answer it without a race. Catching it and
     * reporting busy is the design, not error handling.
     */
    public function startRun($pid, array $run)
    {
        $now = self::now();

        // ALLOCATED BEFORE THE INSERT, AND DELIBERATELY OUTSIDE ITS try.
        //
        // The insert below reports a duplicate key as "a scan is already
        // running for this project", which is the right answer to contention
        // and the wrong answer to anything else. An allocation folded into
        // that try would report a missing uv_project_seq table as busy, which
        // tells an operator to wait for a wait that never ends - the exact
        // misdiagnosis M10 was fixed to stop making.
        $seq = $this->allocateSeq($pid);
        // An incremental run reports findings against the baseline it is
        // comparing with; a full run writes its own generation. Both take
        // run_seq from the sequence, which is what makes valid_from_seq and
        // valid_to_seq a single monotonic interval per project.
        $gen = (isset($run['run_kind']) && $run['run_kind'] === 'incremental'
                && isset($run['baseline_generation']) && $run['baseline_generation'] !== null)
            ? (int) $run['baseline_generation'] : $seq;
        // AT THE PROJECT'S REVISION NOW (W10A-N1), outside the insert's try
        // for the reason above. A run that started at 1 after the retention
        // cron moved the project's runs to a higher revision sat below it, so
        // a repeat of that bump expired the previews of a run planned under
        // the policy that caused it (ScanRetention::revokePreviews()).
        $rev = isset($run['policy_revision']) ? (int) $run['policy_revision'] : $this->projectRevision($pid);

        $sql = 'INSERT INTO ' . Schema::table('scan_run') . '
            (run_uuid, project_id, run_seq, generation_id, created_by, scope_dag, scope_kind,
             run_kind, baseline_generation, phase, coverage, detail, values_state, policy_json,
             policy_revision, fingerprint, fence_open, created_at, updated_at, active_slot)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)';
        $params = [
            isset($run['uuid']) ? $run['uuid'] : random_bytes(16),
            $pid,
            // No defaults. A default is what let generation 1 ship for every
            // run of every project on the installation.
            $seq,
            $gen,
            isset($run['created_by']) ? $run['created_by'] : '',
            isset($run['scope_dag']) ? $run['scope_dag'] : null,
            isset($run['scope_dag']) && $run['scope_dag'] !== null ? 'dag' : 'global',
            isset($run['run_kind']) ? $run['run_kind'] : 'full',
            isset($run['baseline_generation']) ? $run['baseline_generation'] : null,
            'planning', ScanOutcome::COV_PARTIAL, ScanOutcome::DETAIL_COMPLETE,
            isset($run['values_state']) ? $run['values_state'] : 'none',
            isset($run['policy_json']) ? $run['policy_json'] : '{}',
            $rev,
            isset($run['fingerprint']) ? $run['fingerprint'] : str_repeat('0', 64),
            isset($run['fence_open']) ? $run['fence_open'] : null,
            $now, $now,
        ];
        try {
            $this->db->execNoCount($sql, $params);
        } catch (\Throwable $e) {
            // WHICH FAULT WAS IT? CONTENTION IS A FACT ABOUT THE TABLE, NOT
            // ABOUT THE ERROR.
            //
            // Every insert failure here used to be reported as busy, and the
            // docblock above defended that: a message distinguishing "slot
            // taken" from "column too long" would be an oracle for one caller
            // and a support ticket for the other. The first half of that is
            // still true and is still held below. The second half was wrong in
            // a way that costs a pilot round: "busy" tells an operator to WAIT,
            // which is correct for contention and is a wait that never ends for
            // a missing table, a value too long, or a lost connection. That is
            // the same misdiagnosis as "the server is busy with other scans"
            // over a slot pool with no rows in it, which ScanWorker::work()
            // carries twenty lines about.
            //
            // So ask the one question that separates them, on the failure path
            // only. NOT the driver's error code: the framework wraps mysqli
            // errors and getCode() is frequently 0, so a code test is a second
            // thing that works locally and not in production. The slot itself
            // is reliable, and the statement that reads it is already in this
            // method.
            $held = [];
            try {
                $held = $this->db->select('SELECT 1 FROM ' . Schema::table('scan_run')
                    . ' WHERE project_id = ? AND active_slot = 1', [$pid]);
            } catch (\Throwable $ignored) {
                // The probe failed too, which is itself the answer: this is not
                // contention, it is a database that cannot be read from.
                $held = [];
            }
            if (isset($held[0])) {
                // Unchanged, wording included. The probe returns a bare 1 and
                // never the run id, the creator or the scope, so the
                // non-disclosure property ScanAuthorization::busy() depends on
                // is exactly what it was.
                return ['ok' => false, 'busy' => true, 'run' => null,
                        // ONE SENTENCE, ONE OWNER. ScanAuthorization::busy()
                        // exists so this wording is identical whoever asks -
                        // its docblock says so - and both stores were writing
                        // their own copy of it, which had already drifted from
                        // the helper by a sentence. Nothing called the helper
                        // at all: its only two mentions in the shipped tree
                        // were inside comments, which is why the wiring test
                        // could not see it until the corpus stopped counting
                        // prose as a call site.
                        'why' => self::BUSY_WHY];
            }
            throw $this->unavailable($e);
        }
        $row = $this->db->select('SELECT run_id FROM ' . Schema::table('scan_run')
            . ' WHERE project_id = ? AND active_slot = 1', [$pid]);
        $id = isset($row[0][0]) ? (int) $row[0][0] : 0;
        return ['ok' => true, 'busy' => false, 'run' => $this->run($pid, $id), 'why' => null];
    }

    /**
     * BOUND TO THE PROJECT. The run id is a locator, and a locator that resolves
     * across projects is an authorisation bug wearing a lookup's clothes.
     */
    public function run($pid, $runId)
    {
        // THE COLUMNS AND THE KEYS ARE ONE LIST - ScanStore::RUN_COLUMNS, which
        // says why. Every name in it is a column of scan_run, so it can be
        // imploded straight into the statement; nothing here comes from a caller.
        // Not a run retention has begun removing (see ScanStore::run()).
        $r = $this->db->select('SELECT ' . implode(', ', self::RUN_COLUMNS) . '
            FROM ' . Schema::table('scan_run') . ' WHERE run_id = ? AND project_id = ?
              AND (terminal_reason IS NULL OR terminal_reason <> ?)',
            [$runId, $pid, self::REMOVING_WHY]);
        if (!isset($r[0])) return null;
        $row = array_values((array) $r[0]);
        if (count($row) !== count(self::RUN_COLUMNS)) {
            // array_combine() would answer FALSE here, and a false run row reads
            // as "no such key" to everything downstream. This is not a storage
            // fault and must not be dressed as one: the row and the list disagree
            // because somebody changed one of them.
            throw new \LogicException('the run row has ' . count($row) . ' columns and '
                . 'ScanStore::RUN_COLUMNS names ' . count(self::RUN_COLUMNS));
        }
        return array_combine(self::RUN_COLUMNS, $row);
    }

    /**
     * Freeze the manifest, then set the totals.
     *
     * TOTALS LAST, and inside the same transaction as the rows. A run that
     * published a total before its rows existed could be asked "are you
     * complete?" in the gap and answer yes over an empty manifest.
     */
    public function writeManifest($runId, array $records)
    {
        // Kept as one call for the small synchronous paths and for every test
        // written against it, but implemented in terms of the streaming pair so
        // there is one code path that can be wrong rather than two. The whole
        // list is in hand, so the source has said it is done: that is what
        // the freeze is now gated on.
        $this->appendManifest($runId, $records);
        $this->markPlanDone($runId);
        $n = $this->freezeManifest($runId);
        return $n === false ? 0 : $n;
    }

    /** The plan row, saying the walk has reached the end of its source. writeManifest() only. */
    private function markPlanDone($runId)
    {
        $now = self::now();
        $this->db->execNoCount('INSERT INTO ' . Schema::table('scan_plan') . '
            (run_id, plan_done, updated_at) VALUES (?, 1, ?)
            ON DUPLICATE KEY UPDATE plan_done = 1, updated_at = ?', [$runId, $now, $now]);
    }

    /**
     * Append, continuing the manifest's ordinals.
     *
     * INSERT IGNORE, not INSERT: the record walk re-reads its page boundary to
     * avoid skipping ids the database considers equal, so it can legitimately
     * offer the same record twice. The unique key on (run_id, record_hash) makes
     * the second offer a no-op instead of an error - which is why the walk is
     * allowed to be generous.
     *
     * The gap this leaves in the ordinals is deliberate and harmless: claiming
     * walks ordinals in order rather than assuming they are contiguous, and
     * completeness is a predicate over states.
     */
    public function appendManifest($runId, array $records, ?array $plan = null)
    {
        if (!$records && $plan === null) return 0;
        $this->db->begin();
        try {
            // FOR UPDATE, so two planners cannot interleave their ordinals, and
            // so a freeze cannot land between the phase check and the insert.
            // The rest of the fence is compared here in PHP, never by affected
            // rows: see the class note.
            //
            // THE PLAN ROW IS PART OF THE FENCE, read in the same statement
            // (review R5-9). claimPlan() writes it before it hands out a lease,
            // so a leased append that finds none comes after something that
            // deleted it, and the walk's state is then unknown. 2bd57cd wrote
            // the row here instead and said ArrayScanStore did the same, while
            // ArrayScanStore refused the page; both refuse it now. A join and
            // not a second locking read, which is the round trip 2bd57cd took
            // out: the plan row is locked after the run row, the order
            // claimPlan() locks them in.
            $r = $this->db->select('SELECT r.phase, r.lease_epoch, r.cancel_requested_at, r.lease_owner,
                p.run_id FROM ' . Schema::table('scan_run') . ' r LEFT JOIN ' . Schema::table('scan_plan')
                . ' p ON p.run_id = r.run_id WHERE r.run_id = ? FOR UPDATE', [$runId]);
            if (!isset($r[0]) || $r[0][0] !== ScanPhase::PLANNING
                    || ($plan !== null && (!self::planFence($r[0], $plan) || $r[0][4] === null))) {
                $this->db->rollback();
                return false;
            }
            $t = Schema::table('scan_record');
            $now = self::now();
            $added = 0;
            if ($records) {
                // One index dive: MAX over the leading prefix of the primary key
                // (run_id, ordinal). tests/mysql/cases/planning.php asserts the plan.
                $m = $this->db->select('SELECT COALESCE(MAX(ordinal), 0) FROM ' . $t
                    . ' WHERE run_id = ?', [$runId]);
                $ord = isset($m[0][0]) ? (int) $m[0][0] : 0;
                $chunk = [];
                foreach ($records as $rec) {
                    $ord++;
                    $chunk[] = [$runId, $ord, $rec['id_bin'], $rec['hash'],
                                isset($rec['dag']) ? $rec['dag'] : null, self::REC_PENDING, $now];
                    if (count($chunk) >= self::MANIFEST_CHUNK) {
                        $added += $this->insertRecords($t, $chunk);
                        $chunk = [];
                    }
                }
                if ($chunk) {
                    $added += $this->insertRecords($t, $chunk);
                }
            }
            if ($plan !== null) {
                // WHERE THE WALK STANDS, IN THE PAGE'S TRANSACTION. The attempt
                // count goes back to zero because this slice got a page in.
                // An UPDATE, uncounted: the fence above found the row and holds
                // its lock, so this matches it.
                $cursor = isset($plan['cursor']) ? (string) $plan['cursor'] : null;
                $carry = isset($plan['carry']) ? (string) $plan['carry'] : null;
                $listed = max(0, (int) (isset($plan['listed']) ? $plan['listed'] : 0));
                $out = max(0, (int) (isset($plan['outOfScope']) ? $plan['outOfScope'] : 0));
                $done = empty($plan['done']) ? 0 : 1;
                $this->db->execNoCount('UPDATE ' . Schema::table('scan_plan') . '
                    SET plan_cursor = ?, plan_carry = ?, plan_pages = plan_pages + 1,
                        plan_listed = ?, plan_out_of_scope = ?, plan_attempts = 0,
                        plan_done = ?, updated_at = ?
                    WHERE run_id = ?',
                    [$cursor, $carry, $listed, $out, $done, $now, $runId]);
            }
            // THE HEARTBEAT. progress_at as well as updated_at: the page, the
            // stall rule and the abandoned-run sweep all read progress_at, and
            // a page appended IS progress - the third place it is written,
            // beside commitBatch() and freezeManifest().
            if ($plan === null) {
                $this->db->execNoCount('UPDATE ' . Schema::table('scan_run')
                    . ' SET updated_at = ?, progress_at = ? WHERE run_id = ?', [$now, $now, $runId]);
            } elseif (!empty($plan['release'])) {
                $this->db->execNoCount('UPDATE ' . Schema::table('scan_run') . '
                    SET updated_at = ?, progress_at = ?, lease_owner = NULL, lease_expires_at = NULL
                    WHERE run_id = ?', [$now, $now, $runId]);
            } else {
                $this->db->execNoCount('UPDATE ' . Schema::table('scan_run') . '
                    SET updated_at = ?, progress_at = ?, lease_expires_at = ?
                    WHERE run_id = ?',
                    [$now, $now, self::inSeconds(self::leaseFor($plan)), $runId]);
            }
            $this->db->commit();
            return $added;
        } catch (\Throwable $e) {
            throw $this->rolledBack($e);
        }
    }

    /**
     * The planner's fence over a locked run row [phase, lease_epoch,
     * cancel_requested_at, lease_owner, ...]: same epoch, no stop asked for, and
     * the lease this planner was given. A planner whose lease ran out and was
     * taken over finds another owner there; one whose slice handed the lease
     * back finds none. Either way it is not the planner any more.
     */
    private static function planFence(array $row, array $plan)
    {
        $owner = isset($plan['owner']) ? (string) $plan['owner'] : '';
        return $owner !== ''
            && isset($plan['epoch']) && (int) $row[1] === (int) $plan['epoch']
            && $row[2] === null
            && $row[3] !== null && (string) $row[3] === $owner;
    }

    /** Seconds a planning lease lasts, from the caller; never less than one. */
    private static function leaseFor(array $plan)
    {
        return isset($plan['leaseSeconds']) ? max(1, (int) $plan['leaseSeconds']) : 300;
    }

    /**
     * Take the planning lease, count the attempt, and say where the walk stands.
     *
     * ONE TRANSACTION, fenced on the run row like every other planning write.
     * The plan row is created here the first time, under that lock, so two
     * claimers cannot both insert it.
     */
    public function claimPlan($runId, $owner, $epoch, $leaseSeconds, $maxAttempts)
    {
        $owner = (string) $owner;
        if ($owner === '') return false;
        $cap = max(1, (int) $maxAttempts);
        $this->db->begin();
        try {
            $r = $this->db->select('SELECT phase, lease_epoch, cancel_requested_at, lease_owner,
                lease_expires_at FROM ' . Schema::table('scan_run') . ' WHERE run_id = ? FOR UPDATE',
                [$runId]);
            if (!isset($r[0]) || $r[0][0] !== ScanPhase::PLANNING || (int) $r[0][1] !== (int) $epoch
                    || $r[0][2] !== null) {
                $this->db->rollback();
                return false;
            }
            $now = self::now();
            $p = $this->db->select('SELECT plan_cursor, plan_carry, plan_pages, plan_listed,
                plan_out_of_scope, plan_attempts, plan_done FROM ' . Schema::table('scan_plan')
                . ' WHERE run_id = ? FOR UPDATE', [$runId]);
            // A RUN'S FIRST CLAIM WRITES THE ROW, with this attempt already in
            // it, below; one statement where an insert and then the update
            // were two, on every start.
            $missing = !isset($p[0]);
            if ($missing) $p = [[null, null, 0, 0, 0, 0, 0]];
            $state = self::planState($p[0]);
            // HELD: another owner, and its lease has not run out. Both sides are
            // UTC 'Y-m-d H:i:s', so the strings compare as the times do.
            $held = $r[0][3] !== null && (string) $r[0][3] !== $owner
                    && $r[0][4] !== null && strcmp((string) $r[0][4], $now) > 0;
            if ((int) $p[0][6] === 1) {
                $state['state'] = 'done';
            } elseif ($held) {
                $state['state'] = 'held';
            } elseif ($state['attempts'] >= $cap) {
                $state['state'] = 'exhausted';
            }
            if ($state['state'] !== 'ok') {
                $this->db->commit();
                return $state;
            }
            // LEAST(attempts + 1, 254), as every attempt counter here: the
            // column is a TINYINT UNSIGNED, and a wrap would un-cap the retry.
            if ($missing) {
                $this->db->execNoCount('INSERT INTO ' . Schema::table('scan_plan')
                    . ' (run_id, plan_attempts, updated_at) VALUES (?, 1, ?)', [$runId, $now]);
            } else {
                $this->db->execNoCount('UPDATE ' . Schema::table('scan_plan') . '
                    SET plan_attempts = LEAST(plan_attempts + 1, 254), updated_at = ? WHERE run_id = ?',
                    [$now, $runId]);
            }
            $this->db->execNoCount('UPDATE ' . Schema::table('scan_run') . '
                SET lease_owner = ?, lease_expires_at = ?, updated_at = ? WHERE run_id = ?',
                [$owner, self::inSeconds(max(1, (int) $leaseSeconds)), $now, $runId]);
            $this->db->commit();
            $state['attempts'] = min(254, $state['attempts'] + 1);
            return $state;
        } catch (\Throwable $e) {
            throw $this->rolledBack($e);
        }
    }

    /** A plan row [cursor, carry, pages, listed, out of scope, attempts, done] as claimPlan() answers it. */
    private static function planState(array $row)
    {
        return ['state' => 'ok',
                'cursor' => $row[0] === null ? null : (string) $row[0],
                'carry' => $row[1] === null ? null : (string) $row[1],
                'pages' => (int) $row[2], 'listed' => (int) $row[3],
                'outOfScope' => (int) $row[4], 'attempts' => (int) $row[5]];
    }

    public function releasePlan($runId, $owner)
    {
        // A single statement decided by affected(): it necessarily changes
        // lease_owner when it succeeds, which is what makes the count honest.
        $this->db->exec('UPDATE ' . Schema::table('scan_run') . '
            SET lease_owner = NULL, lease_expires_at = NULL, updated_at = ?
            WHERE run_id = ? AND phase = ? AND lease_owner = ?',
            [self::now(), $runId, ScanPhase::PLANNING, (string) $owner]);
        return $this->db->affected() === 1;
    }

    /**
     * Count what is there, publish it, and enter `scanning`.
     *
     * COUNTED, NOT ACCUMULATED. A total added up while writing would disagree
     * with the manifest whenever an append was retried or partly ignored, and
     * the disagreement would be invisible - the run would simply believe it had
     * more or fewer records than it holds.
     */
    public function freezeManifest($runId)
    {
        $this->db->begin();
        try {
            $r = $this->db->select('SELECT phase FROM ' . Schema::table('scan_run')
                . ' WHERE run_id = ? FOR UPDATE', [$runId]);
            if (!isset($r[0]) || $r[0][0] !== ScanPhase::PLANNING) {
                $this->db->rollback();
                return false;
            }
            // THE SOURCE SAID DONE, AND SAID IT IN A COMMITTED WRITE. Not the
            // walk returning: a walk returns when its request runs out of time
            // too, and a manifest frozen there is part of the project.
            $d = $this->db->select('SELECT plan_done FROM ' . Schema::table('scan_plan')
                . ' WHERE run_id = ?', [$runId]);
            if (!isset($d[0]) || (int) $d[0][0] !== 1) {
                $this->db->rollback();
                return false;
            }
            $c = $this->db->select('SELECT COUNT(*) FROM ' . Schema::table('scan_record')
                . ' WHERE run_id = ?', [$runId]);
            $total = isset($c[0][0]) ? (int) $c[0][0] : 0;
            // THE SECOND OF THE TWO PLACES progress_at is written. Freezing
            // the manifest IS progress - it is the moment the run stops
            // planning and becomes work - and without it a run that planned a
            // large project and then wedged before its first batch would carry
            // a NULL progress_at forever and be indistinguishable from one that
            // had never started.
            $this->db->execNoCount('UPDATE ' . Schema::table('scan_run') . '
                SET manifest_total = ?, phase = ?, updated_at = ?, progress_at = ?
                WHERE run_id = ?',
                [$total, ScanPhase::SCANNING, self::now(), self::now(), $runId]);
            $this->db->commit();
            return $total;
        } catch (\Throwable $e) {
            throw $this->rolledBack($e);
        }
    }

    /** @return int how many of $rows were new to the manifest */
    private function insertRecords($table, array $rows)
    {
        $ph = implode(',', array_fill(0, count($rows), '(?,?,?,?,?,?,?)'));
        $flat = [];
        foreach ($rows as $r) foreach ($r as $v) $flat[] = $v;
        // ON DUPLICATE KEY UPDATE with a no-op assignment, NOT `INSERT IGNORE`.
        //
        // Both make a re-offered record harmless, and only this one leaves a
        // REAL write error still an error: IGNORE downgrades a value too long
        // for its column, a bad character set and a lock timeout into warnings
        // nobody reads, which is the M-05 shape - a failed write judged as a
        // successful one. `run_id = run_id` changes nothing, so affected()
        // counts exactly the rows that were inserted.
        //
        // The in-memory store deduplicated in PHP and the fast suite was green;
        // four real servers rejected the second offer of the same record on the
        // first run of the database matrix. That is what the matrix is for.
        $this->db->exec('INSERT INTO ' . $table . '
            (run_id, ordinal, record_id_bin, record_hash, dag_at_fence, state, updated_at)
            VALUES ' . $ph . ' ON DUPLICATE KEY UPDATE run_id = run_id', $flat);
        // RETURNED HERE, not left for the caller to ask. The count belongs to the
        // statement above, and a caller reading affected() a line later is one
        // inserted write away from reading somebody else's number.
        return $this->db->affected();
    }

    /**
     * Claim the next ordinal range, fenced on the epoch.
     *
     * The claim is itself a compare-and-set: a worker whose epoch has already
     * moved gets nothing, rather than a range it would fail to commit later and
     * would have spent a full getData() on first.
     *
     * EMPTY AND REFUSED ARE DIFFERENT ANSWERS, and this is the method where
     * conflating them cost most. `[]` means the run has no more rows to hand
     * out; `false` means this worker may not have any right now - a cancelled
     * run, a moved epoch, a phase that has changed underneath it, or a read that
     * failed. The first is a reason to move on; the second is a reason to stop.
     * The live pilot walked a 39-record run to its last phase with 3 records
     * examined because both answers were [].
     *
     * @return array|false
     */
    public function claim($runId, $owner, $epoch, $limit)
    {
        $limit = max(1, (int) $limit);
        // THE CLAIM TOKEN IS ALLOCATED BEFORE THE TRANSACTION OPENS, so the
        // project's sequence row is not locked for the life of a claim. It is
        // the same per-project sequence that numbers runs and generations,
        // which makes the token monotonic rather than merely distinct - and a
        // token burned by a rolled-back claim costs nothing, because these
        // numbers are identifiers and never counts.
        $token = $this->claimToken($runId);
        if ($token < 1) return false;            // no such run: refused, not empty
        $this->db->begin();
        try {
            // THE FENCE IS A LOCKING READ. It was an UPDATE gated on
            // affected() === 1, and that fails the same way commitBatch() did:
            // a worker re-claiming within the same second writes the lease
            // expiry it already held, changes nothing, and is told it lost the
            // run. FOR UPDATE also serialises two workers on the run row, which
            // is what keeps their claims disjoint.
            $r = $this->db->select('SELECT lease_epoch, phase, cancel_requested_at, cursor_ordinal
                FROM ' . Schema::table('scan_run') . ' WHERE run_id = ? FOR UPDATE', [$runId]);
            if (!isset($r[0]) || (int) $r[0][0] !== (int) $epoch || $r[0][2] !== null
                    || $r[0][1] !== ScanPhase::SCANNING) {
                $this->db->rollback();
                return false;                     // refused, NOT empty
            }
            $from = (int) $r[0][3];

            // ORDINALS ARE NOT CONTIGUOUS. Appending a manifest in pages skips
            // an ordinal wherever a re-offered record was ignored, so advancing
            // the cursor by a COUNT would step over live rows and leave them
            // permanently unreachable. Take the next N pending rows in order and
            // move the cursor to the last one actually taken.
            $rows = $this->db->select('SELECT ordinal, record_id_bin, record_hash, dag_at_fence,
                attempts, version_scanned
                FROM ' . Schema::table('scan_record') . '
                WHERE run_id = ? AND ordinal > ? AND state = ?
                ORDER BY ordinal LIMIT ' . $limit, [$runId, $from, self::REC_PENDING]);
            $out = [];
            $ords = [];
            $to = $from;
            foreach ($rows as $row) {
                $out[] = ['ordinal' => (int) $row[0], 'id_bin' => $row[1],
                          'hash' => $row[2], 'dag' => $row[3],
                          // How many times this record has already been tried.
                          // The worker needs it to decide between requeueing a
                          // record that will not hold still and declaring it a
                          // blocking exclusion - a decision it cannot make from
                          // what it can see in one request.
                          'attempts' => (int) $row[4], 'version' => $row[5],
                          'claim' => $token];
                $ords[] = (int) $row[0];
                if ((int) $row[0] > $to) $to = (int) $row[0];
            }
            // STAMP THE CLAIM, LEAVE THE STATE ALONE.
            //
            // In this phase the advancing cursor is what keeps two workers
            // apart, and marking these rows CLAIMED would change more than the
            // fence: a scanning row abandoned by a dead worker is PENDING and
            // catch-up picks it up at once, where a CLAIMED one is invisible to
            // the straggler sweep for a quarter of an hour. So the token is
            // written and the state is not.
            if ($ords) {
                $marks = implode(',', array_fill(0, count($ords), '?'));
                $this->db->execNoCount('UPDATE ' . Schema::table('scan_record') . '
                    SET claim_owner = ?, claim_seq = ?, updated_at = ?
                    WHERE run_id = ? AND ordinal IN (' . $marks . ')',
                    array_merge([$owner, $token, self::now(), $runId], $ords));
            }
            $this->db->execNoCount('UPDATE ' . Schema::table('scan_run') . '
                SET cursor_ordinal = ?, lease_owner = ?, lease_expires_at = ?, updated_at = ?
                WHERE run_id = ?',
                [$to, $owner, self::inSeconds(300), self::now(), $runId]);
            $this->db->commit();
            return $out;
        } catch (\Throwable $e) {
            // A FAILED READ IS NOT A REFUSED ONE. `false` here means the fence
            // looked and said no; a deadlock means the fence never got to look,
            // and the worker that treats them alike stops without anything
            // recording that the database is in trouble.
            throw $this->rolledBack($e);
        }
    }

    /**
     * Claim stragglers by state, without touching the ordinal cursor.
     *
     * The rows are marked CLAIMED inside the same transaction that selects them,
     * so a second worker arriving behind the run row's lock sees them taken
     * rather than evaluating them again. CLAIMED is not terminal: a worker that
     * dies leaves rows another worker reclaims once they have gone stale, and
     * nothing about a claim marks a record examined.
     */
    public function claimPending($runId, $owner, $epoch, $limit, $staleSeconds = 900,
                                 $maxAttempts = null, $exhausted = self::REC_UNREADABLE,
                                 &$retired = null)
    {
        $retired = 0;
        $limit = max(1, (int) $limit);
        $token = $this->claimToken($runId);
        if ($token < 1) return false;            // no such run: refused, not empty
        $this->db->begin();
        try {
            $r = $this->db->select('SELECT lease_epoch, phase, cancel_requested_at FROM '
                . Schema::table('scan_run') . ' WHERE run_id = ? FOR UPDATE', [$runId]);
            if (!isset($r[0]) || (int) $r[0][0] !== (int) $epoch || $r[0][2] !== null
                    || !ScanPhase::mayWork($r[0][1])) {
                // REFUSED, which is not the same as EMPTY - see the contract
                // note on claim(). Returning [] here let the worker read "you
                // may not claim" as "there is nothing left" and walk the phase
                // chain to the end over a manifest it had barely started.
                $this->db->rollback();
                return false;
            }
            $t = Schema::table('scan_record');
            $stale = gmdate('Y-m-d H:i:s', time() - max(1, (int) $staleSeconds));
            $out = [];
            // WINDOW AFTER WINDOW, while a whole window is retired (review
            // R2S-5). The LIMIT below is the window. Rows retired out of
            // attempts used to take their places in it, so a window that was
            // all retired answered [] while free rows sat behind it - and the
            // worker, finding the manifest incomplete, said "records are still
            // out with another worker" and backed off, once per window of dead
            // rows. Bounded, so the run row's lock is held for a bounded number
            // of statements and a bounded time; the in-memory store walks the
            // same windows.
            //
            // EACH WINDOW STARTS AFTER THE LAST ROW OF THE ONE BEFORE, AND IS
            // READ THROUGH ix_run_state (review R3S-1). The windows used to be
            // one statement re-run from the start of the run - free rows in
            // ordinal order, `state = PENDING OR (state = CLAIMED AND stale)` -
            // and the server served that ORDER BY from PRIMARY (run_id,
            // ordinal), testing every row on the way. In catch-up nearly every
            // row is finished, so each window walked the finished prefix:
            // measured at 98,499 rows a window on 100,000 records and 498,499
            // on 500,000, sixteen times a call, 13 seconds with the run row
            // locked FOR UPDATE and every commit, claim and Stop of the run
            // queued behind it. Now the two free states are two ranges of
            // (run_id, state, ordinal) - InnoDB keeps the primary key at the
            // end of every secondary key, so the ordinal order is the index
            // order - each read from just past the previous window and cut off
            // at the window size, and the union of the two is sorted: at most
            // twice the window, whatever is finished in front of it.
            //
            // FORCED FOR THE ORDER BY, AND ONLY FOR IT. PRIMARY can serve this
            // ORDER BY too, and MySQL 8.0 chose it with 90,000 finished rows in
            // front: the plan above. A plain FORCE INDEX (ix_run_state) is the
            // obvious cure and is worse on MySQL: with PRIMARY taken out of
            // the usable keys the optimiser no longer knows run_id is constant
            // in the primary-key suffix it would sort by, so every branch read
            // all of its state's rows and sorted them - 100,000 rows a window
            // with everything pending. FOR ORDER BY keeps PRIMARY usable, which
            // is what lets MySQL read the range in ordinal order and stop at
            // the window. MariaDB gets the access path forced as well, in a
            // comment only MariaDB executes: without it MariaDB planned PRIMARY
            // with a sort and only switched to the state index while running
            // the statement, so what it did was not what EXPLAIN said. Both are
            // asserted, with the rows each window read, in
            // tests/mysql/cases/store.php. The CLAIMED range is short by
            // nature: rows a live worker holds are skipped, and they are at
            // most the batches in flight.
            $began = $this->clock();
            $after = 0;
            $free = 'SELECT ordinal, record_id_bin, record_hash, dag_at_fence, attempts,
                    version_scanned, claim_seq, (updated_at < ?) AS stale
                FROM ' . $t . ' FORCE INDEX FOR ORDER BY (ix_run_state)
                    /*M! FORCE INDEX FOR JOIN (ix_run_state) */
                WHERE run_id = ? AND state = ? AND ordinal > ?';
            for ($window = 0; $window < self::SWEEP_WINDOWS; $window++) {
                // AND WITHIN A TIME. The first window always runs, so every
                // call makes progress; a later one starts only while the run
                // row has been held for less than SWEEP_SECONDS.
                if ($window > 0 && $this->clock() - $began >= self::SWEEP_SECONDS) break;
                // The eighth column says whether the row's claim is as old as
                // a CLAIMED row must be before it is free: asked of the server,
                // in the same comparison the WHERE clause makes, so the two can
                // never disagree about what stale means.
                $rows = $this->db->select('(' . $free . ' ORDER BY ordinal LIMIT ' . $limit . ')
                    UNION ALL
                    (' . $free . ' AND updated_at < ? ORDER BY ordinal LIMIT ' . $limit . ')
                    ORDER BY ordinal LIMIT ' . $limit,
                    [$stale, $runId, self::REC_PENDING, $after,
                     $stale, $runId, self::REC_CLAIMED, $after, $stale]);
                $retire = [];
                foreach ($rows as $row) {
                    // ABANDONED: free, yet still carrying a claim. Every way a
                    // worker lets go of a row - commit, release, the give-back
                    // after a failure, a requeue - clears claim_seq to 0 in the
                    // same statement. A row that is free and still holds one
                    // was left by a worker that died: a request killed by
                    // memory_limit or max_execution_time inside the read or the
                    // evaluation, where nothing after the fatal ran and no
                    // attempt was counted. A record that kills PHP every time
                    // it is evaluated was therefore taken over and retried for
                    // ever, and the run never finished.
                    //
                    // So a takeover counts the attempt the dead worker could
                    // not, retires the row at the limit as any other exhausted
                    // record is retired, and otherwise hands it out ALONE: if
                    // it kills this worker too, it charges only itself and not
                    // the records that happened to share its batch. Only with
                    // $maxAttempts, so a caller that does not bound attempts
                    // keeps the old behaviour.
                    //
                    // AND ONLY WHEN THE CLAIM IS STALE (review R2S-1). The rule
                    // above said "free and still stamped", and a PENDING row is
                    // free at any age - while claim() stamps the rows it hands
                    // out in scanning and leaves them PENDING. So a LIVE worker
                    // still evaluating its last scanning batch looked dead to a
                    // second driver that had reached catch-up (a second tab, a
                    // reload, Continue while the old request ran): its healthy
                    // records were charged, and at recordAttempts=1 retired as
                    // unreadable before anyone examined them. A fresh stamp is
                    // taken over uncharged, which replaces its token and lets
                    // the live worker's commit drop it - wasteful, never wrong.
                    // Stale is the eighth column: updated_at, which the stamp
                    // wrote.
                    $abandoned = $maxAttempts !== null && (int) $row[6] !== 0
                              && (int) $row[7] === 1;
                    $attempts = (int) $row[4];
                    if ($abandoned) {
                        if ($out) break;             // it goes alone, in a call of its own
                        $attempts = min(254, $attempts + 1);
                        if ($attempts >= max(1, (int) $maxAttempts)) {
                            $retire[] = (int) $row[0];
                            continue;
                        }
                    }
                    $out[] = ['ordinal' => (int) $row[0], 'id_bin' => $row[1],
                              'hash' => $row[2], 'dag' => $row[3],
                              'attempts' => $attempts, 'version' => $row[5],
                              'claim' => $token, 'abandoned' => $abandoned];
                    if ($abandoned) break;
                }
                if ($retire) {
                    $marks = implode(',', array_fill(0, count($retire), '?'));
                    // Terminal, so the run can finish; blocking, so it can
                    // never finish clean - the same state noteAttempts()
                    // retires to. COUNTED, so the worker can report them: a
                    // record retired here was never in a batch, and nothing
                    // else would say so.
                    $this->db->exec('UPDATE ' . $t . '
                        SET state = ?, attempts = LEAST(attempts + 1, 254),
                            claim_owner = NULL, claim_seq = 0, updated_at = ?
                        WHERE run_id = ? AND ordinal IN (' . $marks . ') AND state < ?',
                        array_merge([(int) $exhausted, self::now(), $runId], $retire,
                                    [self::REC_DONE]));
                    $retired += $this->db->affected();
                }
                // Something to hand out, or nothing behind this window: either
                // way this is the answer. Otherwise every row of a full window
                // was retired, and the next one is looked at from its last row.
                if ($out || count($rows) < $limit) break;
                $after = (int) $rows[count($rows) - 1][0];
            }
            if ($out && $out[0]['abandoned']) {
                // The attempt the dead worker never counted, with the takeover.
                $this->db->execNoCount('UPDATE ' . $t . '
                    SET attempts = LEAST(attempts + 1, 254)
                    WHERE run_id = ? AND ordinal = ? AND state < ?',
                    [$runId, $out[0]['ordinal'], self::REC_DONE]);
            }
            if ($out) {
                $ords = [];
                foreach ($out as $o) $ords[] = $o['ordinal'];
                $marks = implode(',', array_fill(0, count($ords), '?'));
                // TAKING A STRAGGLER REPLACES ITS TOKEN. That single assignment
                // is the takeover fence: whoever held the row before now
                // matches nothing, and no other worker of this run is affected.
                $this->db->execNoCount('UPDATE ' . $t . '
                    SET state = ?, claim_owner = ?, claim_seq = ?, updated_at = ?
                    WHERE run_id = ? AND ordinal IN (' . $marks . ') AND state < ?',
                    array_merge([self::REC_CLAIMED, $owner, $token, self::now(), $runId],
                                $ords, [self::REC_DONE]));
            }
            $this->db->commit();
            return $out;
        } catch (\Throwable $e) {
            // A failed read is not an empty one, and it is not a refused one
            // either. This is the file that says so everywhere else. Nothing
            // was retired either: the rollback undid it.
            $retired = 0;
            throw $this->rolledBack($e);
        }
    }

    /**
     * I2/I2b/I3: record states, findings and candidates as one transaction.
     *
     * THE ORDER INSIDE THE TRANSACTION CHANGED, and the old order's stated
     * reason did not survive inspection. It was "record states LAST ... so a
     * crash anywhere leaves them pending", which a transaction already
     * guarantees whatever order the statements were issued in - a crash rolls
     * back all of them. The new order buys something real: the per-record fence
     * is evaluated BY those UPDATEs, so writing them first is what tells the
     * rest of the transaction which records this worker still holds.
     *
     *   1. the run fence, a locking read, held for the whole transaction
     *   2. record states, fenced per row on the claim token -> $held
     *   3. supersede the previous evidence of the HELD records only
     *   4. insert the findings and candidates of HELD records only - the
     *      findings as plain multi-row INSERTs, see insertFindings()
     *   5. counters
     *
     * WHY 4 IS FILTERED. A record taken over by another worker while this one
     * was evaluating it may already have been committed by that worker. Writing
     * our copy of its findings inserts an identity that is already active, the
     * unique key refuses it, and the WHOLE batch rolls back - so one taken-over
     * record used to destroy the work of every record beside it. Dropping its
     * findings costs nothing: the new holder is committing them.
     */
    public function commitBatch($runId, $owner, $epoch, array $batch, &$heldCount = null,
                                &$heldOrdinals = null)
    {
        // Nothing held until the transaction has committed, so every refusal,
        // throw and rollback below leaves it at 0. See ScanStore::commitBatch().
        $heldCount = 0;
        $heldOrdinals = [];
        $records    = isset($batch['records'])    && is_array($batch['records'])    ? $batch['records']    : [];
        $findings   = isset($batch['findings'])   && is_array($batch['findings'])   ? $batch['findings']   : [];
        $candidates = isset($batch['candidates']) && is_array($batch['candidates']) ? $batch['candidates'] : [];
        // $presence, NOT $marks: the SQL store's supersede already builds a string
        // of placeholders under that name, and a list sharing it was silently
        // overwritten - which only a real server showed.
        $presence   = isset($batch['marks'])      && is_array($batch['marks'])      ? $batch['marks']      : [];
        // A mark with no kind or no axis is a programming error, refused in the
        // same words by both stores and before anything is written.
        foreach ($presence as $m) {
            if (!is_array($m) || !isset($m['kind'], $m['axis1'], $m['axis2'])
                    || (string) $m['kind'] === '') {
                throw new \RuntimeException('a presence mark reached the store with no kind or no '
                    . 'axis; refusing to write a fact that cannot be read back');
            }
        }

        // SHAPE FIRST, AND OUTSIDE THE TRANSACTION. A missing claim token or an
        // unattributable finding is a programming error, not a database
        // refusal, and throwing it inside the try below would dress it as one:
        // the caller would be told "the database refused to store these
        // findings" about a database that was never asked.
        foreach ($records as $rec) {
            if (!isset($rec['claim']) || (int) $rec['claim'] < 1) {
                throw new \RuntimeException('a batch record reached the store with no claim token; '
                    . 'refusing to write a row this worker cannot prove it still holds');
            }
        }
        foreach ([$findings, $candidates] as $rows) {
            foreach ($rows as $row) {
                if (!isset($row['ordinal'])) {
                    throw new \RuntimeException('a finding reached the store with no record '
                        . 'ordinal; refusing to write evidence that cannot be attributed to a '
                        . 'claimed record');
                }
            }
        }

        $this->db->begin();
        try {
            // THE FENCE IS A LOCKING READ, NOT AN UPDATE.
            //
            // It was an UPDATE that set updated_at and checked affected() === 1,
            // and that is wrong in a way only a real server shows: MySQL reports
            // rows CHANGED, not rows MATCHED, so writing the same timestamp
            // within the same second changed nothing, reported zero, and rolled
            // back a perfectly good batch. Intermittent by construction - it
            // depended on whether the clock had ticked since writeManifest().
            //
            // The local matrix caught it because MariaDB on default isolation
            // happened to pass while MySQL failed; a single-engine test would
            // have shipped a scan that silently discarded batches under load.
            //
            // SELECT ... FOR UPDATE holds the run row for the life of the
            // transaction, which is what the old comment claimed and the old
            // code did not do: a concurrent cancel now serialises behind us
            // rather than racing us, and the epoch is compared in PHP where
            // "unchanged" is not mistaken for "absent".
            $fence = $this->db->select('SELECT lease_epoch, cancel_requested_at, project_id,
                       generation_id, run_seq, terminal FROM '
                . Schema::table('scan_run') . ' WHERE run_id = ? FOR UPDATE', [$runId]);
            // SAY WHICH FENCE REFUSED. "Cancelled or taken over" covered three
            // different causes, and during the pilot a run failed its very first
            // commit with no way to tell which one it was. A worker's own report
            // about its own run discloses nothing, and it is the difference
            // between a diagnosis and an afternoon.
            if (!isset($fence[0])) {
                $this->db->rollback();
                return 'this scan no longer exists, so nothing from these records was kept';
            }
            if ($fence[0][1] !== null) {
                $this->db->rollback();
                return 'this scan was stopped while these records were being examined, so nothing '
                     . 'from them was kept';
            }
            // A FINISHED RUN TAKES NO MORE WORK. Measured on 8.0.46: a run
            // retired to terminal='expired' accepted a late commit from the
            // worker that had been abandoned, wrote its findings and advanced
            // manifest_done - so a run reported as having examined a fraction of
            // the project kept growing after it had ended, and its stored
            // coverage verdict described a manifest that no longer matched it.
            if ($fence[0][5] !== null) {
                $this->db->rollback();
                return 'this scan had already finished when these records were offered, so '
                     . 'nothing from them was kept';
            }
            if ((int) $fence[0][0] !== (int) $epoch) {
                $this->db->rollback();
                return 'another worker took over this scan while these records were being '
                     . 'examined, so nothing from them was kept; they will be examined again';
            }

            $projectId    = (int) $fence[0][2];
            $generationId = (int) $fence[0][3];
            $runSeq       = (int) $fence[0][4];

            // WHICH RECORDS ARE STILL OURS. Each UPDATE carries its own row's
            // claim token in the WHERE clause, so a record another worker took
            // over between our claim and now matches nothing and is simply
            // absent from $held.
            //
            // affected() IS MEANINGFUL HERE, which is not true of every fence in
            // this file. The claim columns are CLEARED by the same statement -
            // claim_owner goes from this worker's name to NULL - so a matching
            // row always changes, whatever the clock did and whatever the state
            // was. That is deliberate: it is what makes the count a fence rather
            // than an accident of timing.
            $held = [];
            $applied = 0;
            foreach ($records as $rec) {
                // `state < REC_DONE` rather than `= REC_PENDING`: a straggler
                // claimed by claimPending() is in CLAIMED, and must still be
                // committable. Terminal rows stay untouched, which is I3 - only
                // the transaction that scanned a record marks it done, and it
                // never marks it done twice.
                //
                // LEAST(attempts + 1, 254): attempts is a TINYINT UNSIGNED. A
                // bare +1 wraps to 0 at 255 under a permissive sql_mode and
                // errors under a strict one, and the wrap is the worse of the
                // two - a record tried 255 times would start again at nothing
                // and the retry cap would silently stop existing.
                $this->db->exec('UPDATE ' . Schema::table('scan_record') . '
                    SET state = ?, attempts = LEAST(attempts + 1, 254), version_scanned = ?,
                        claim_owner = NULL, claim_seq = 0, updated_at = ?
                    WHERE run_id = ? AND ordinal = ? AND state < ?
                      AND claim_owner = ? AND claim_seq = ?',
                    [$rec['state'], isset($rec['version']) ? $rec['version'] : null, self::now(),
                     $runId, $rec['ordinal'], self::REC_DONE, $owner, (int) $rec['claim']]);
                if ($this->db->affected() !== 1) continue;
                $held[(int) $rec['ordinal']] = true;
                // Terminal rows only. A requeued record is written back as
                // pending, which changes its attempt count and its timestamp -
                // so it affects a row without having been finished, and
                // counting it would walk progress past the manifest total.
                if ((int) $rec['state'] >= self::REC_DONE) $applied++;
            }

            // SUPERSEDE THIS BATCH'S RECORDS BEFORE WRITING THEIR NEW EVIDENCE.
            //
            // Nothing anywhere used to close a record's existing findings. A
            // record edited during a run is requeued by catch-up, re-examined,
            // and produces the same violation again - so commitBatch inserted
            // an identity its own first pass had already committed as active,
            // the unique key refused it, and the batch rolled back. On a FIRST
            // run of a fresh installation, with no prior scan involved.
            //
            // Every clause of where this sits is load-bearing:
            //   AFTER the four fences, so a cancelled, finished or taken-over
            //     worker cannot close a live worker's findings;
            //   INSIDE the transaction, so a batch that rolls back does not
            //     leave the previous evidence closed and silently empty the
            //     report for records nobody re-examined;
            //   BEFORE any insert, so the unique key sees only the new rows as
            //     active;
            //   under the row lock the SELECT ... FOR UPDATE above already
            //     holds, which serialises two workers of the same run;
            //   over HELD records only, because a record we lost is one whose
            //     new evidence we are about to drop - closing its old rows
            //     would blank the report for a record still under examination.
            //
            // BY RECORD, not by the identities in this batch. A violation FIXED
            // between the two examinations produces no finding the second time,
            // and an identity-scoped close would leave it active forever - the
            // report would show corrected data as still broken.
            $closing = [];
            foreach ($records as $rec) {
                if (!isset($rec['record_hash'])) continue;
                if (empty($held[(int) $rec['ordinal']])) continue;
                $st = (int) $rec['state'];
                // Only a record that has just been examined, or one that is
                // gone. A record REQUEUED because it moved commits no new
                // findings, so closing its rows would blank the report for a
                // record that still violates.
                if ($st === self::REC_DONE || $st === self::REC_TOMBSTONE) {
                    $closing[] = $rec['record_hash'];
                }
            }
            if ($closing) {
                $marks = implode(',', array_fill(0, count($closing), '?'));
                // stage_epoch IS NULL excludes duplicate-value findings. Those
                // belong to UniqueFinalizer::publish(), which closes and
                // republishes per GROUP; closing them here would make a still
                // -true duplicate group vanish the moment any one of its
                // records was re-examined.
                //
                // This is the query ix_record serves - (project_id,
                // generation_id, record_hash, active_slot) - and the reason
                // three separate specifications were overruled about dropping
                // it. Without the index it is 333 ms per batch instead of 1.7.
                $this->db->execNoCount('UPDATE ' . Schema::table('finding') . '
                    SET active_slot = NULL, valid_to_seq = ?
                    WHERE project_id = ? AND generation_id = ? AND active_slot = 1
                      AND stage_epoch IS NULL
                      AND record_hash IN (' . $marks . ')',
                    array_merge([$runSeq, $projectId, $generationId], $closing));
            }

            // The findings of HELD records only, and the filter is applied inside
            // insertFindings() as it walks them rather than by building a second
            // list here - see the memory note there.
            $stored = $this->insertFindings($findings, $held, $runSeq);
            // UNIQUENESS CANDIDATES, in the SAME transaction as the findings and
            // the record states. A uniqueness verdict is the only thing in the
            // scan that depends on records other than the one being examined, so
            // a candidate written outside the batch that produced it could
            // survive a rolled-back batch and make a record look like a
            // duplicate of a reading that was discarded.
            foreach ($candidates as $c) {
                if (empty($held[(int) $c['ordinal']])) continue;
                $this->insertCandidate($c);
            }

            // WHAT THE BATCH LEARNED ABOUT THE RULES, in the same transaction and
            // behind the same four fences - so a worker that was cancelled or
            // taken over mid-batch adds nothing to a run that is no longer its
            // own. ONLY WHEN A RECORD WAS HELD: a batch that lost every row has
            // no standing to say anything about the run, and its evaluation may
            // be of records a newer worker is reading differently.
            if ($held) {
                foreach ($presence as $m) {
                    $this->markAggregate($runId, $m['kind'], $m['axis1'], $m['axis2'],
                                         empty($m['blocks']) ? 0 : 1,
                                         isset($m['samples']) ? $m['samples'] : null);
                }
            }

            // Counters last, and NOT gated on affected(): a batch that finished
            // zero records and found zero findings changes no column, reports
            // zero, and would roll itself back for having nothing to say. The
            // fence above already decided whether this transaction may commit -
            // asking twice, with a weaker question, only adds a way to be wrong.
            // What ACTUALLY became terminal and what was actually STORED, not
            // what was offered: a record re-offered after a requeue would
            // otherwise be counted twice and walk progress past the manifest
            // total, and a lost record's dropped findings would be counted as
            // detail rows that do not exist.
            //
            // PROGRESS_AT IS ONE OF ONLY TWO PLACES, and that is the whole point
            // of the column. updated_at is written by claim(), advancePhase(),
            // setProgressState() and by this method's own fence, so a wedged run
            // refreshes it continuously and the stale-run sweep can never see it
            // at any staleHours setting - while the sweep's own terminal_reason
            // says "no progress within the configured window". This column
            // measures progress: records actually finished, and the manifest
            // freezing. The other place is freezeManifest().
            $sql = 'UPDATE ' . Schema::table('scan_run') . '
                SET manifest_done = manifest_done + ?, detail_rows = detail_rows + ?,
                    detail_bytes = detail_bytes + ?, updated_at = ?';
            $args = [$applied, $stored, isset($batch['bytes']) ? (int) $batch['bytes'] : 0,
                     self::now()];
            if ($applied > 0) {
                $sql .= ', progress_at = ?';
                $args[] = self::now();
            }
            $sql .= ' WHERE run_id = ?';
            $args[] = $runId;
            $this->db->execNoCount($sql, $args);

            $this->db->commit();
            $heldCount = count($held);
            $heldOrdinals = array_keys($held);
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            // The server refused the write. SAY WHAT IT SAID.
            //
            // The class name alone cost three rounds of the live pilot: every
            // batch failed with "(Exception)", which is the framework's wrapper
            // and describes nothing. "Data too long for column reason_code" and
            // "Column host_form cannot be null" need completely different fixes,
            // and only the server knows which one it is.
            return 'the database refused to store these findings, so nothing from these records '
                 . 'was kept: ' . DbError::safe($e);
        }
    }

    /**
     * Hand claimed records back, so another worker can take them at once.
     *
     * WHY THIS EXISTS AT ALL. Claiming and committing are separate transactions
     * - they must be, because the evaluation between them can take seconds - so
     * a batch that rolls back leaves its rows CLAIMED. A claimed row is
     * invisible to the straggler sweep until it goes stale, and the phase
     * machine now (correctly) refuses to advance over rows nobody has examined.
     * Without this the two safe behaviours combine into a deadlock: the pilot
     * sat at 0 of 39 answering "waiting" for a quarter of an hour.
     *
     * FENCED TWICE, and the second fence is the one that does the work. The
     * epoch says the run has not been cancelled from under us; the CLAIM TOKEN
     * says these particular rows are still ours. Only the second can express
     * takeover, because takeover does not move the epoch - so a worker whose
     * rows were reclaimed by the straggler sweep used to be able to yank them
     * straight back out of the new holder's hands, on a fence that had passed.
     *
     * THE CURSOR MOVES BACK ONLY OVER ROWS ACTUALLY RELEASED. Scanning hands
     * out rows ABOVE the cursor, so a released row below it would otherwise be
     * reachable only by the straggler sweep - a slower path for records that
     * were never examined at all. Rewinding over rows we did NOT release would
     * re-offer another worker's live claim.
     *
     * @param array $claims ordinal => claim token
     * @return int rows handed back
     */
    public function releaseClaims($runId, $epoch, $owner, array $claims)
    {
        if (!$claims) return 0;
        $this->db->begin();
        try {
            $r = $this->db->select('SELECT lease_epoch, cursor_ordinal FROM '
                . Schema::table('scan_run') . ' WHERE run_id = ? FOR UPDATE', [$runId]);
            if (!isset($r[0]) || (int) $r[0][0] !== (int) $epoch) {
                $this->db->rollback();
                return 0;
            }
            $n = 0;
            $released = [];
            // One statement per DISTINCT token rather than per row. A batch is
            // claimed in one call and therefore carries one token, so this is
            // normally a single statement; grouping keeps it correct if a caller
            // ever hands back rows from two claims at once.
            foreach (self::byToken($claims) as $token => $ords) {
                $marks = implode(',', array_fill(0, count($ords), '?'));
                $this->db->exec('UPDATE ' . Schema::table('scan_record') . '
                    SET state = ?, claim_owner = NULL, claim_seq = 0, updated_at = ?
                    WHERE run_id = ? AND state = ? AND claim_owner = ? AND claim_seq = ?
                      AND ordinal IN (' . $marks . ')',
                    array_merge([self::REC_PENDING, self::now(), $runId, self::REC_CLAIMED,
                                 $owner, (int) $token], $ords));
                $hit = $this->db->affected();
                if ($hit > 0) {
                    $n += $hit;
                    foreach ($ords as $o) $released[] = (int) $o;
                }
                // AND THE SCANNING-PHASE ROWS, which are never marked CLAIMED -
                // there the advancing cursor keeps workers apart, so a claim
                // stamps the token and leaves the state PENDING. Their claim
                // still has to be dropped, or the row would be re-offered to
                // another worker while this one's stale token still sat on it
                // and could be committed by nobody.
                $this->db->exec('UPDATE ' . Schema::table('scan_record') . '
                    SET claim_owner = NULL, claim_seq = 0, updated_at = ?
                    WHERE run_id = ? AND state = ? AND claim_owner = ? AND claim_seq = ?
                      AND ordinal IN (' . $marks . ')',
                    array_merge([self::now(), $runId, self::REC_PENDING, $owner, (int) $token],
                                $ords));
                if ($this->db->affected() > 0) {
                    foreach ($ords as $o) $released[] = (int) $o;
                }
            }
            if ($released) {
                $low = min($released) - 1;
                if ($low < (int) $r[0][1]) {
                    $this->db->execNoCount('UPDATE ' . Schema::table('scan_run')
                        . ' SET cursor_ordinal = ?, updated_at = ?'
                        . ' WHERE run_id = ? AND lease_epoch = ?',
                        [$low, self::now(), $runId, $epoch]);
                }
            }
            $this->db->commit();
            return $n;
        } catch (\Throwable $e) {
            // Zero is "the epoch had moved, so these rows are not yours to hand
            // back". It is not "the database refused to talk to us", and a
            // worker that reads one as the other leaves its rows CLAIMED and
            // invisible to the straggler sweep until they go stale.
            throw $this->rolledBack($e);
        }
    }

    /**
     * Count one attempt against records this worker tried and could not finish.
     *
     * SEE ScanStore::noteAttempts FOR WHY THIS IS NOT PART OF commitBatch. The
     * short version is that the only statement which incremented `attempts`
     * lived inside the transaction that a refused batch rolls back, so the
     * counter moved only when the commit had already succeeded: `recordAttempts`
     * was unreachable, the run never became terminal, and it held the project's
     * one active slot retrying the same failing write for as long as anybody
     * kept a browser tab open.
     *
     * FENCED ON THE EPOCH AS WELL AS THE CLAIM. A cancelled run bumps the epoch,
     * and retiring records to a blocking terminal state inside a run that is
     * being stopped would put an exclusion in a report about work nobody asked
     * for. A takeover does not bump it, which is exactly why the claim token is
     * the other half.
     *
     * SET STATEMENTS, NOT ONE PER ROW. The increment saturates in SQL, then the
     * rows that have reached the limit are retired in a second pass over the
     * same predicate - which is also what makes `retired` a count of rows that
     * really changed rather than an inference from the first statement - and
     * a third pass over that predicate hands back what is left.
     *
     * THE HAND-BACK IS IN THIS TRANSACTION (review R2S-4). It used to be the
     * caller's next call, releaseClaims(), in a transaction of its own. If that
     * one failed, or a takeover landed between the two, the row kept this
     * worker's token after its attempt had been counted - and the straggler
     * sweep, finding a stale claim on a free row, charged the same failure a
     * second time. Counting and releasing together leaves no moment in which a
     * counted row still looks abandoned. The cursor moves back over the rows
     * released, as releaseClaims() moves it, for the same reason.
     *
     * @return array{counted:int, retired:int, released:int}
     */
    public function noteAttempts($runId, $epoch, $owner, array $claims, $maxAttempts, $exhausted)
    {
        if (!$claims) return ['counted' => 0, 'retired' => 0, 'released' => 0];
        $cap = max(1, (int) $maxAttempts);
        $this->db->begin();
        try {
            $r = $this->db->select('SELECT lease_epoch, cursor_ordinal FROM '
                . Schema::table('scan_run') . ' WHERE run_id = ? FOR UPDATE', [$runId]);
            if (!isset($r[0]) || (int) $r[0][0] !== (int) $epoch) {
                $this->db->rollback();
                return ['counted' => 0, 'retired' => 0, 'released' => 0];
            }
            $t = Schema::table('scan_record');
            $counted = 0;
            $retired = 0;
            $released = 0;
            $low = null;
            foreach (self::byToken($claims) as $token => $ords) {
                $marks = implode(',', array_fill(0, count($ords), '?'));
                $where = ' WHERE run_id = ? AND state < ? AND claim_owner = ? AND claim_seq = ?'
                       . ' AND ordinal IN (' . $marks . ')';
                $args  = array_merge([$runId, self::REC_DONE, $owner, (int) $token], $ords);
                // LEAST(attempts + 1, 254), for the reason commitBatch gives:
                // attempts is a TINYINT UNSIGNED, and a bare +1 wraps to zero at
                // 255 under a permissive sql_mode - which would not merely lose
                // a count, it would silently un-cap the retry loop this method
                // exists to bound.
                $this->db->exec('UPDATE ' . $t . '
                    SET attempts = LEAST(attempts + 1, 254), updated_at = ?' . $where,
                    array_merge([self::now()], $args));
                $counted += $this->db->affected();
                // OUT OF ATTEMPTS. Terminal, so the run can finish and give the
                // project its slot back; blocking, so it can never finish clean.
                // The claim is cleared with it - a terminal row is nobody's.
                $this->db->exec('UPDATE ' . $t . '
                    SET state = ?, claim_owner = NULL, claim_seq = 0, updated_at = ?'
                    . $where . ' AND attempts >= ?',
                    array_merge([(int) $exhausted, self::now()], $args, [$cap]));
                $retired += $this->db->affected();
                // WHAT IS LEFT GOES BACK, now. A retired row no longer carries
                // this worker's token, so the same predicate matches exactly
                // the rows still held: a straggler (CLAIMED) returns to
                // PENDING, a scanning row (PENDING, stamped) loses its stamp.
                // The lowest of them is asked first, for the cursor.
                $m = $this->db->select('SELECT MIN(ordinal) FROM ' . $t . $where, $args);
                if (isset($m[0][0]) && $m[0][0] !== null) {
                    $low = $low === null ? (int) $m[0][0] : min($low, (int) $m[0][0]);
                }
                $this->db->exec('UPDATE ' . $t . '
                    SET state = ?, claim_owner = NULL, claim_seq = 0, updated_at = ?' . $where,
                    array_merge([self::REC_PENDING, self::now()], $args));
                $released += $this->db->affected();
            }
            // Over rows actually released, as releaseClaims() does: scanning
            // hands out rows above the cursor, and a row handed back below it
            // would otherwise wait for the straggler sweep.
            if ($low !== null && $low - 1 < (int) $r[0][1]) {
                $this->db->execNoCount('UPDATE ' . Schema::table('scan_run')
                    . ' SET cursor_ordinal = ?, updated_at = ? WHERE run_id = ? AND lease_epoch = ?',
                    [$low - 1, self::now(), $runId, $epoch]);
            }
            $this->db->commit();
            return ['counted' => $counted, 'retired' => $retired, 'released' => $released];
        } catch (\Throwable $e) {
            throw $this->rolledBack($e);
        }
    }

    /**
     * ordinal => token, regrouped as token => [ordinals].
     *
     * A batch is claimed in one call and so carries one token; this exists so
     * that stays an optimisation rather than an assumption.
     */
    private static function byToken(array $claims)
    {
        $out = [];
        foreach ($claims as $ordinal => $token) {
            // A TOKEN OF ZERO IS DROPPED, NOT PASSED THROUGH. Zero is the column
            // default - what an unclaimed row carries - so a caller that lost a
            // token somewhere would fence on it and match every record of the
            // run that nobody holds. Refusing is the safe direction: the write
            // does nothing rather than everything.
            if ((int) $token < 1) continue;
            $out[(int) $token][] = (int) $ordinal;
        }
        return $out;
    }

    /**
     * One uniqueness candidate.
     *
     * ON DUPLICATE KEY UPDATE rather than plain INSERT, because a record
     * requeued by the stable-read protocol is evaluated again and offers its
     * candidates again. The unique key makes the second offer land on the same
     * row; `INSERT IGNORE` would do that too and would also swallow a value too
     * long for its column, which is the failure worth keeping loud.
     *
     * THE VALUE IS NOT STORED. Only the keyed group hash is, because a Notes
     * field can be 64 KB and a candidate per record would be a second copy of
     * the project. A group that actually collides is re-read from the source.
     */
    /**
     * The project a row belongs to, or a refusal.
     *
     * project_id carries DEFAULT 0 in the schema, and permanently: the default
     * is what lets ADD COLUMN succeed against a populated table, and removing
     * it would make every insert written before the code catches up fail under
     * STRICT_TRANS_TABLES. So the column cannot fail loud, and this does it
     * instead.
     *
     * A zero here would not be a small mistake. Every predicate over the four
     * generation-scoped tables now filters by project, so a row written with 0
     * belongs to no project: invisible to its own report, immune to its own
     * project's retention, and indistinguishable from the version-1 rows the
     * migration deletes. Silence is exactly how the constant generation shipped.
     */
    private static function mustProject(array $row)
    {
        $pid = isset($row['project_id']) ? (int) $row['project_id'] : 0;
        if ($pid < 1) {
            throw new \RuntimeException('a scan row reached the store with no project; '
                . 'refusing to write a row that belongs to nothing');
        }
        return $pid;
    }

    private function insertCandidate(array $c)
    {
        $this->db->execNoCount('INSERT INTO ' . Schema::table('unique_candidate') . '
            (project_id, generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
             record_hash, record_id_bin, event_id, instance, host_form, field, version_scanned, lookup)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE version_scanned = VALUES(version_scanned), lookup = VALUES(lookup)', [
            self::mustProject($c),
            $c['generation_id'], $c['rule_source_id'], $c['rule_revision'], $c['group_hmac'],
            isset($c['scope_key']) ? $c['scope_key'] : '',
            $c['record_hash'], $c['record_id_bin'],
            // 0, NOT NULL. MySQL counts every NULL in a unique index as
            // distinct, so on a classic project - where event_id is always
            // null - ON DUPLICATE KEY UPDATE never fired and duplicate
            // candidate rows accumulated for the same record.
            isset($c['event_id']) && $c['event_id'] !== null ? (int) $c['event_id'] : 0,
            isset($c['instance']) ? $c['instance'] : 1,
            $c['host_form'], $c['field'],
            isset($c['version']) ? $c['version'] : null,
            // 1: a value a @UVUNIQUE rule looks in, never reported (Schema::statementsV4).
            empty($c['lookup']) ? 0 : 1,
        ]);
    }

    /**
     * One finding as column => value, in column order.
     *
     * THE ONLY COLUMN LIST. insertFindings() takes its column names and its
     * placeholder marks from the keys of this array, so the names, the marks and
     * the values cannot drift apart - which with 29 of them, written by hand in
     * three places, they eventually would.
     *
     * UniqueFinalizer::emit() writes the same table with its own statement and
     * deliberately shares nothing with this one: it stages rows under the other
     * unique key and uses ON DUPLICATE KEY UPDATE, the opposite conflict policy.
     */
    private static function findingRow(array $f, $runSeq)
    {
        // The project first, so a finding missing both is refused for the same
        // reason by both stores.
        $pid = self::mustProject($f);
        // A NULL IS REFUSED HERE, NOT LEFT TO THE SERVER (review ST-2). The
        // statement this row joins is a multi-row INSERT, and under a
        // non-strict sql_mode the server answers a NULL in a NOT NULL column
        // by storing '' or 0 and counting the row as written - so the
        // affected() check in insertFindingChunk() cannot see it. See
        // ScanStore::FINDING_REQUIRED.
        foreach (self::FINDING_REQUIRED as $k) {
            if (!isset($f[$k])) {
                throw new \RuntimeException('a finding reached the store with no ' . $k
                    . '; refusing to let the database invent one');
            }
        }
        // A STAGED row is written with active_slot NULL and is invisible to every
        // report query until the finalizer publishes its group. Ordinary
        // findings are active from the moment they are written.
        $staged = isset($f['stage_epoch']) && $f['stage_epoch'] !== null;
        return [
            'project_id'        => $pid,
            'generation_id'     => $f['generation_id'],
            'finding_identity'  => $f['identity'],
            // The RUN's sequence number. It used to be a per-record
            // ordinal, which put valid_from_seq and valid_to_seq in
            // different number spaces and made the interval meaningless.
            // ABSENT MEANS THIS RUN'S, as in ArrayScanStore (M13). It was 0
            // here, an interval opened before the project's first run, while
            // the in-memory store used the run's sequence; the producer always
            // sends it, so only the two stores disagreed.
            'valid_from_seq'    => isset($f['valid_from_seq']) ? (int) $f['valid_from_seq'] : (int) $runSeq,
            'active_slot'       => $staged ? null : 1,
            'record_hash'       => $f['record_hash'],
            'record_id_bin'     => $f['record_id_bin'],
            'event_id'          => isset($f['event_id']) ? $f['event_id'] : null,
            'arm_id'            => isset($f['arm_id']) ? $f['arm_id'] : null,
            'instance'          => isset($f['instance']) ? $f['instance'] : 1,
            'host_form'         => $f['host_form'],
            'field'             => $f['field'],
            'rule_source_id'    => $f['rule_source_id'],
            'rule_revision'     => $f['rule_revision'],
            'rule_ord'          => isset($f['rule_ord']) ? $f['rule_ord'] : 0,
            'check_type'        => $f['check_type'],
            'reason_code'       => $f['reason_code'],
            'reason_bits'       => isset($f['reason_bits']) ? $f['reason_bits'] : 0,
            'severity'          => isset($f['severity']) ? $f['severity'] : 0,
            'dag_key'           => isset($f['dag_key']) ? $f['dag_key'] : null,
            'status_key'        => isset($f['status_key']) ? $f['status_key'] : null,
            'value_bin'         => isset($f['value_bin']) ? $f['value_bin'] : null,
            'value_len'         => isset($f['value_len']) ? $f['value_len'] : null,
            'value_fingerprint' => isset($f['value_fingerprint']) ? $f['value_fingerprint'] : null,
            'value_truncated'   => isset($f['value_truncated']) ? $f['value_truncated'] : 0,
            'value_binary'      => isset($f['value_binary']) ? $f['value_binary'] : 0,
            'value_expires_at'  => isset($f['value_expires_at']) ? $f['value_expires_at'] : null,
            'group_hmac'        => isset($f['group_hmac']) ? $f['group_hmac'] : null,
            'stage_epoch'       => $staged ? $f['stage_epoch'] : null,
            // Schema versions 3 (as_of) and 5 (z_score, growth_reference).
            'as_of'             => isset($f['as_of']) ? (string) $f['as_of'] : null,
            'z_score'           => isset($f['z_score']) ? (string) $f['z_score'] : null,
            'growth_reference'  => isset($f['growth_reference']) ? (string) $f['growth_reference'] : null,
        ];
    }

    /**
     * Every finding of a batch, in as few statements as the server allows.
     *
     * ONE STATEMENT PER CHUNK, NOT ONE PER FINDING. This was a loop, and inside
     * commitBatch the run row is held FOR UPDATE for as long as the loop runs:
     * measured on 8.0.46, a record with 4,000 findings held it for 15.9 seconds
     * where the batched form holds it for 0.85 - and over the framework each of
     * those inserts paid a second round trip for a count nobody read.
     *
     * A PLAIN INSERT. Never ON DUPLICATE KEY UPDATE, never INSERT IGNORE. The
     * same identity twice in one batch, or an identity that is already active,
     * is a defect upstream, and uq_active_identity_v2 refusing the WHOLE batch
     * is how it surfaces. Either softer form would store one of the two and
     * report success. One duplicate anywhere in a multi-row statement fails the
     * statement, and commitBatch rolls back every chunk before it.
     *
     * COUNTED, unlike the writes around it. This is the one insert whose silent
     * shortfall reads as a cleaner project than the one that was scanned, so it
     * keeps its ROW_COUNT() read - one extra round trip per FINDING_CHUNK rows -
     * and refuses the batch if the server stored fewer rows than it was sent.
     *
     * ONE CHUNK IN MEMORY AT A TIME (review EE-1). This used to build
     * findingRow() for every finding of the batch and only then cut the list
     * into chunks, so the batch was held twice over - once as the findings the
     * worker handed in, once as 29-column rows - before a single row was sent.
     * Measured: 25 records of 4,000 findings each added 138.8 MB on top of the
     * batch, which died at a 256 MB memory_limit inside this method. A fatal
     * there skips noteAttempts(), so the claims stay held, the same batch is
     * claimed again when they go stale, and it dies the same way for ever. Now
     * a row is built only when it is about to join the chunk being filled, and
     * the chunk's parameters are dropped as soon as its INSERT has answered.
     *
     * @param array $held ordinal => true, the records this transaction still
     *                    holds; a finding of any other record is not written
     * @param int $runSeq the run's sequence, for a finding that names none
     * @return int rows stored
     */
    private function insertFindings(array $findings, array $held, $runSeq)
    {
        $head = null;
        $mark = null;
        $full = null;          // the VALUES list of a full chunk, built once
        $size = self::FINDING_CHUNK;
        $flat = [];
        $rows = 0;
        $stored = 0;
        foreach ($findings as $f) {
            if (empty($held[(int) $f['ordinal']])) continue;
            $row = self::findingRow($f, $runSeq);
            if ($head === null) {
                // findingRow() is the only column list, so the statement is
                // shaped from the first row it builds.
                $cols = array_keys($row);
                $mark = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
                // MySQL allows 65,535 placeholders a statement. The division
                // keeps a chunk legal if this table ever gains enough columns
                // to matter.
                $size = max(1, min(self::FINDING_CHUNK, intdiv(65535, count($cols))));
                $head = 'INSERT INTO ' . Schema::table('finding') . '
            (' . implode(', ', $cols) . ')
            VALUES ';
                $full = implode(',', array_fill(0, $size, $mark));
            }
            foreach ($row as $v) $flat[] = $v;
            $rows++;
            if ($rows >= $size) {
                $stored += $this->insertFindingChunk($head . $full, $flat, $rows);
                // Freed here, not when the loop ends: holding the last chunk's
                // parameters while the next one fills is the copy this method
                // exists not to make.
                $flat = [];
                $rows = 0;
            }
        }
        if ($rows > 0) {
            $stored += $this->insertFindingChunk(
                $head . implode(',', array_fill(0, $rows, $mark)), $flat, $rows);
        }
        return $stored;
    }

    /**
     * One chunk of findings, and the count that says the server kept all of it.
     *
     * The write and the read of its count stay in this one function, which is
     * what the order lint in tests/scan_moduledb_php.php requires: the count
     * belongs to the statement straight above it and to nothing else.
     *
     * @return int rows stored, always equal to $rows
     */
    private function insertFindingChunk($sql, array $params, $rows)
    {
        $this->db->exec($sql, $params);
        $n = $this->db->affected();
        if ($n !== $rows) {
            throw new \RuntimeException('the database stored ' . (int) $n . ' of '
                . (int) $rows . ' findings it was sent');
        }
        return $n;
    }

    /**
     * I4: completeness is a PREDICATE over record states, never a counter.
     *
     * A counter can be incremented twice by a retry; this cannot be wrong in
     * that direction, because a record is terminal or it is not.
     *
     * AN EXISTENCE TEST, NOT A COUNT. The question is "is any row unfinished",
     * and COUNT(*) answers it by walking every unfinished row first - the whole
     * pending manifest, on a run cancelled early. EXISTS stops at the first.
     *
     * EXISTS(...) RATHER THAN `SELECT 1 ... LIMIT 1`, and the difference is the
     * direction it fails in. EXISTS always returns exactly one row, holding 0 or
     * 1, so "no row came back" still means "the read did not work" and answers
     * NOT complete. The bare form answers complete with an EMPTY result - which
     * is also what ModuleDb::rows() hands back for any result shape it does not
     * recognise, so a broken read would certify an unfinished run.
     *
     * FORCE INDEX, for the reason UniqueFinalizer::nextUnfinished() gives at
     * length. PRIMARY (run_id, ordinal) can serve this too, and when every row
     * is terminal - the state this is asked in most - it walks the whole
     * manifest to discover that none qualifies, where ix_run_state (run_id,
     * state) seeks to `state < 100` and finds nothing at once. Not a guess:
     * with the hint removed and 1,200 terminal rows, MySQL 5.7, MySQL 8.0 and
     * MariaDB 10.11 all chose PRIMARY, and only MariaDB 10.5 chose the state
     * index. tests/mysql/cases/store.php asserts the plan. Safe to force:
     * ix_run_state is part of the version-1 CREATE TABLE, so there is no schema
     * carrying this table without it.
     *
     * AND THE RUN HAS TO EXIST (W6-N1). A run id with no manifest rows has no
     * unfinished row either, so the first EXISTS alone answered "complete" for
     * a run nobody can find, where ArrayScanStore answered "not complete". The
     * second EXISTS is a primary-key probe in the same statement; both columns
     * must be read, as numbers, for the answer to be yes.
     */
    public function manifestComplete($runId)
    {
        $r = $this->db->select('SELECT EXISTS(SELECT 1 FROM ' . Schema::table('scan_record')
            . ' FORCE INDEX (ix_run_state) WHERE run_id = ? AND state < ?), EXISTS(SELECT 1 FROM '
            . Schema::table('scan_run') . ' WHERE run_id = ?)',
            [$runId, self::REC_DONE, $runId]);
        return isset($r[0][0], $r[0][1]) && (int) $r[0][0] === 0 && (int) $r[0][1] === 1;
    }

    /**
     * Terminal, and the project slot released. IDEMPOTENT: the predicate
     * requires the slot to still be held, so a retried finaliser changes nothing
     * rather than reopening a finished run.
     */
    public function advancePhase($runId, $epoch, $to)
    {
        $this->db->begin();
        try {
            $r = $this->db->select('SELECT phase, lease_epoch, cancel_requested_at FROM '
                . Schema::table('scan_run') . ' WHERE run_id = ? FOR UPDATE', [$runId]);
            if (!isset($r[0]) || (int) $r[0][1] !== (int) $epoch || $r[0][2] !== null) {
                $this->db->rollback();
                return false;
            }
            // The transition table decides, not the caller. A worker that has
            // finished its own phase has no way of knowing whether the phase
            // after it is the one that should run next.
            if (!ScanPhase::may($r[0][0], $to)) {
                $this->db->rollback();
                return false;
            }
            $this->db->execNoCount('UPDATE ' . Schema::table('scan_run') . '
                SET phase = ?, cursor_ordinal = ?, updated_at = ? WHERE run_id = ?',
                // The cursor belongs to the phase that used it. Carrying it into
                // catch-up would make the straggler sweep start part way through
                // a manifest it walks by state rather than by position.
                [$to, 0, self::now(), $runId]);
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            // THE WORST OF THE FOUR, because of what the caller does with it.
            // ScanWorker reads `false` as "there is no next phase" and reports
            // the run DONE. A deadlock swallowed here therefore certified a
            // project on the strength of a transaction that never ran.
            throw $this->rolledBack($e);
        }
    }

    /**
     * THE DECISION IS WRITTEN WITH THE TERMINAL, in the one statement. `clean`
     * and the three counts used to live only in the array promote() returned,
     * so the run row said "complete" and nothing anywhere said whether that
     * meant "and nothing was found" - every later reader had to re-derive it
     * from tables retention is allowed to empty.
     *
     * The counts arrive IN $outcome. They are not computed here by subquery:
     * that would read scan_record and scan_aggregate under the run-row lock,
     * the reverse of the order commitBatch() takes them in.
     */
    public function finish($runId, array $outcome)
    {
        $n = function ($k) use ($outcome) {
            return isset($outcome[$k]) ? max(0, (int) $outcome[$k]) : 0;
        };
        $this->db->exec('UPDATE ' . Schema::table('scan_run') . '
            SET phase = ?, terminal = ?, coverage = ?, detail = ?, active_slot = NULL,
                terminal_reason = ?, clean = ?, gap_count = ?, rule_problem_count = ?,
                not_examined = ?, updated_at = ?
            WHERE run_id = ? AND active_slot = 1',
            ['terminal', $outcome['terminal'], $outcome['coverage'], $outcome['detail'],
             isset($outcome['why']) ? substr((string) $outcome['why'], 0, 255) : null,
             ScanOutcome::cleanColumn($outcome), $n('gaps'), $n('ruleProblems'),
             $n('notExamined'), self::now(), $runId]);
        return $this->db->affected() === 1;
    }

    /**
     * Cancel by bumping the epoch, which is what makes it beat an in-flight
     * worker: that worker's next fenced write finds a different epoch, affects
     * zero rows, and rolls back everything it had buffered.
     */
    public function cancel($pid, $runId, $actor)
    {
        $this->db->exec('UPDATE ' . Schema::table('scan_run') . '
            SET cancel_requested_at = ?, phase = ?, lease_epoch = lease_epoch + 1, updated_at = ?
            WHERE run_id = ? AND project_id = ? AND active_slot = 1',
            [self::now(), 'cancelling', self::now(), $runId, $pid]);
        $ok = $this->db->affected() === 1;
        if ($ok) $this->audit($pid, $runId, 'cancel', $actor, null);
        return $ok;
    }

    /**
     * An active run past the stale window that no worker holds, as a condition
     * over uv_scan_run's own columns, with its parameters in order.
     *
     * ONE DEFINITION FOR EVERY READER THAT DECIDES ABANDONMENT: this store's
     * read and its write, the cron's census (ScanMaintenance) and
     * ScanRetention's installation-wide read. They were four copies of one
     * predicate, and all four judged every phase by `progress_at`.
     *
     * THE CLOCK IS THE STATUS PAGE'S (ScanService::progressOf()). In the
     * phases of ScanPhase::ON_THE_UPDATE_CLOCK - catch-up and the two
     * finalizers - nothing moves `progress_at` and nothing renews the lease,
     * while every step moves `updated_at`; so a run finalising for longer than
     * the window after its last record was expired as abandoned by the cron or
     * by another user's Start, while the page showed it working (review
     * rv10a1-1). Those phases are judged by the later of the two. Every other
     * phase keeps `progress_at` alone: in scanning, claim() moves `updated_at`
     * on a run whose every batch is refused, which is the wedge the progress
     * clock exists to catch.
     *
     * @param string $cut the window's start, 'Y-m-d H:i:s' UTC
     * @param string $at  now, the same form, for the lease
     * @return array{0:string, 1:array}
     */
    public static function staleWhere($cut, $at)
    {
        $phases = array_values(ScanPhase::ON_THE_UPDATE_CLOCK);
        return ['active_slot = 1 AND (CASE WHEN phase IN (' . implode(', ', array_fill(0, count($phases), '?')) . ')'
            . ' THEN GREATEST(COALESCE(progress_at, created_at), updated_at)'
            . ' ELSE COALESCE(progress_at, created_at) END) < ?'
            . ' AND (lease_expires_at IS NULL OR lease_expires_at < ?)',
            array_merge($phases, [(string) $cut, (string) $at])];
    }

    /**
     * See ScanStore::expireAbandoned(): one fenced UPDATE, then the audit row.
     *
     * READ, THEN WRITE UNDER THE SAME PREDICATE. The audit has to name the
     * run, and MySQL hands back no row from an UPDATE, so the run is named by
     * an indexed read first: uq_project_active is (project_id, active_slot),
     * so the read is one index entry, and in the common case - nothing stale -
     * it is the only statement sent. The UPDATE carries the whole predicate
     * again, so a run that moved between the two is not touched, and its
     * count decides whether there is anything to audit.
     *
     * THE WRITE AND ITS AUDIT ARE ONE TRANSACTION (review rv10a1-5). The audit
     * was a statement of its own after the UPDATE had committed, so an audit
     * insert that failed left a run expired with no record of who expired it,
     * for ever - the next pass finds nothing stale - while the cron logged the
     * work as rolled back. Now a failed audit rolls the expiry back with it,
     * the run is still stale, and the next pass or Start does both. The read
     * stays outside: in the common case it is the only statement, and it
     * opens no transaction.
     *
     * The row is finish()'s for derive(['expired' => true]), column for column,
     * with the lease epoch moved in the same statement (Risk 5).
     */
    public function expireAbandoned($pid, $staleHours, $actor, $now = null)
    {
        $t = $now === null ? time() : (int) $now;
        $hours = max(1, (int) $staleHours);
        $cut = gmdate('Y-m-d H:i:s', $t - $hours * 3600);
        $at = gmdate('Y-m-d H:i:s', $t);
        $w = self::staleWhere($cut, $at);
        // Not a run somebody stopped: it ends as cancelled, by the finisher
        // Stop uses, never as abandoned (review rv10a1-2).
        $stale = ' WHERE project_id = ? AND phase <> ? AND ' . $w[0];
        $bind = array_merge([(int) $pid, ScanPhase::CANCELLING], $w[1]);
        try {
            $r = $this->db->select('SELECT run_id FROM ' . Schema::table('scan_run') . $stale, $bind);
        } catch (\Throwable $e) {
            throw $this->unavailable($e);
        }
        if (!isset($r[0][0])) return 0;
        $runId = (int) $r[0][0];
        $o = ScanOutcome::derive(['expired' => true]);
        $this->db->begin();
        try {
            $this->db->exec('UPDATE ' . Schema::table('scan_run') . '
                SET phase = ?, terminal = ?, coverage = ?, detail = ?, active_slot = NULL,
                    terminal_reason = ?, clean = ?, gap_count = ?, rule_problem_count = 0,
                    not_examined = 0, lease_epoch = lease_epoch + 1, updated_at = ?'
                . $stale . ' AND run_id = ?',
                array_merge([ScanPhase::TERMINAL, $o['terminal'], $o['coverage'], $o['detail'],
                    substr((string) $o['why'], 0, 255), ScanOutcome::cleanColumn($o), (int) $o['gaps'], $at],
                    $bind, [$runId]));
            if ($this->db->affected() !== 1) {
                $this->db->rollback();
                return 0;
            }
            $this->audit((int) $pid, $runId, self::AUDIT_EXPIRE, (string) $actor,
                json_encode(['stale_hours' => $hours]));
            $this->db->commit();
        } catch (\Throwable $e) {
            throw $this->rolledBack($e);
        }
        return 1;
    }

    // THE WORKER SLOTS USED TO BE LEASED FROM HERE TOO, and that is why they
    // are not any more. leaseSlot() and releaseSlot() were a second
    // implementation of WorkerSlots over the same uv_scan_worker_slot table,
    // wired to nothing, and while nobody was calling them they grew a defect:
    // the read-back after the UPDATE asked for the lowest-numbered slot the
    // OWNER holds rather than the one just taken, so one owner leasing twice
    // was told "slot 1" both times, and releasing what it had been told
    // stranded slot 2 until its TTL expired. Two mechanisms over one semaphore
    // is how the wrong one keeps a defect nobody notices for five releases.
    // WorkerSlots is the only one now, and it holds the same read-back
    // corrected - see the compare-and-set in WorkerSlots::acquire().

    // -- reads --------------------------------------------------------------

    /**
     * One keyset page. Never OFFSET: it degrades quadratically over a run.
     *
     * THE SELECT LIST IS FindingRowMapper::INPUT_KEYS, and each row is zipped
     * with it (B5-N1). This returned twelve positional columns - no project, no
     * rule source, no stored length, no expiry - so the report the mapper
     * renders could not have been built from it, and ArrayScanStore answered
     * with a different shape again. Every name in the list is a column of
     * uv_finding, so it is imploded straight into the statement; nothing here
     * comes from a caller.
     *
     * z_score comes back only beside value_bin: a growth z-score gives the
     * measurement back, so a row whose value preview expired, was revoked or
     * was never stored has no z-score either. A reader that may not see
     * value_bin must not be shown z_score.
     */
    public function findings($projectId, $generationId, array $filter, $afterId, $limit)
    {
        $limit = max(1, min(100, (int) $limit));
        $keys = FindingRowMapper::INPUT_KEYS;
        // PROJECT FIRST, in the predicate and in the index. This method had
        // no production caller at all, which is the only reason a read
        // filtered by generation alone - with the generation the literal 1
        // for every project - was stored corruption rather than a live
        // cross-project disclosure. The report is what would have turned it
        // into one.
        $params = [(int) $projectId, $generationId, (int) $afterId];
        // Only allowlisted axes reach the statement; an unknown key is dropped
        // rather than interpolated.
        $where = '';
        foreach (['host_form' => 'host_form', 'reason_code' => 'reason_code',
                  'dag_key' => 'dag_key', 'check_type' => 'check_type'] as $k => $col) {
            if (isset($filter[$k]) && $filter[$k] !== '') {
                $where .= ' AND ' . $col . ' = ?';
                $params[] = $filter[$k];
            }
        }
        // An unfiltered page is ix_page_v2's prefix in ix_page_v2's order, and
        // is pinned to it: over a small table MySQL 8.0 and MariaDB chose
        // uq_active_identity_v2 and sorted the generation (see
        // ScanRetention::slicePage()). A filtered page is left to the
        // optimiser, which has an ix_filter_* key for each axis.
        $hint = $where === '' ? ' FORCE INDEX (ix_page_v2)' : '';
        $cols = [];
        foreach ($keys as $k) {
            $cols[] = $k === 'z_score' ? 'CASE WHEN value_bin IS NULL THEN NULL ELSE z_score END AS z_score' : $k;
        }
        $sql = 'SELECT ' . implode(', ', $cols) . '
                FROM ' . Schema::table('finding') . $hint . '
                WHERE project_id = ? AND generation_id = ? AND active_slot = 1
                  AND finding_id > ?' . $where . '
                ORDER BY finding_id LIMIT ' . $limit;
        $out = [];
        foreach ($this->db->select($sql, $params) as $r) {
            $row = array_values((array) $r);
            if (count($row) !== count($keys)) {
                // As in run(): array_combine() would answer false, and a false
                // row reads as "no such key" in every cell of the report.
                throw new \LogicException('a finding row has ' . count($row) . ' columns and '
                    . 'FindingRowMapper::INPUT_KEYS names ' . count($keys));
            }
            $out[] = array_combine($keys, $row);
        }
        return $out;
    }

    /**
     * Keyed rows, not the positional ones the driver returns.
     *
     * The in-memory store answered with named keys and this one with numeric
     * ones, and every caller happened to be written against whichever it was
     * developed on. The shared contract said nothing about the shape until it
     * asked both the same question - which is the whole reason the contract is
     * one file run twice.
     */
    public function aggregates($runId)
    {
        $out = [];
        foreach ($this->db->select('SELECT kind, axis1, axis2, cnt, samples, blocks_coverage
                FROM ' . Schema::table('scan_aggregate') . ' WHERE run_id = ? ORDER BY kind, axis1',
                [$runId]) as $r) {
            $out[] = ['kind' => $r[0], 'axis1' => $r[1], 'axis2' => $r[2],
                      'cnt' => (int) $r[3], 'samples' => $r[4], 'blocks' => (int) $r[5]];
        }
        return $out;
    }

    // -- superseding the previous generation (W4-D1) --------------------------
    //
    // Value expiry used to live here as well, as a second unpaged UPDATE beside
    // ScanRetention's; see the note in ScanStore where expireValues() was.

    /**
     * Close one page of this project's older generations. See the contract.
     *
     * BY GENERATION, NOT BY FINDING ID. The page is
     * `project_id = ? AND generation_id = ? AND active_slot = 1 LIMIT n`, which
     * is an equality on the first three columns of ix_page_v2: the server reads
     * exactly the rows it closes, and a closed row leaves that range, so the
     * next page starts where this one stopped without a cursor inside the
     * generation. A finding-id cursor across generations has no index to walk
     * (generation_id comes before finding_id in every key), and a range over
     * `generation_id < ?` reads every closed row of every older run on every
     * page - the whole history of the project, again and again.
     *
     * THE CURSOR MOVES WITH THE PAGE THAT FINISHES A GENERATION, in the same
     * transaction, as a compare-and-set under the run row's lock. It always
     * changes when it moves (a generation id below this run's becomes a larger
     * one), so affected() is meaningful; a cursor that did not move as expected
     * rolls the page back. A page that closed its full limit leaves the cursor
     * where it was: the generation may have more, and the next call looks.
     *
     * LOCK ORDER is this run's row, then the older generations' finding rows.
     * commitBatch() takes ITS run's row and then its own generation's rows, so
     * the two never wait on each other's rows. The one meeting point is the
     * gap lock that ends a closed range in ix_page_v2, and it is a wait, not a
     * deadlock (W8-N5, measured under REPEATABLE READ on MySQL 5.7 and MariaDB
     * 10.5 in tests/mysql/cases/cron.php): when the completed run's own
     * generation holds no rows, a batch inserting into the next generation
     * waits until this page commits, and nothing waits the other way. A wait
     * past innodb_lock_wait_timeout refuses that batch whole, for its worker
     * to retry; a deadlock with anything else is reported unavailable, having
     * closed nothing, and the cron asks again next pass.
     *
     * valid_to_seq IS THIS RUN'S run_seq. A newer run that completes before
     * this one has finished closing will close the rest with its own sequence,
     * which is a later end than the truth by one run; nothing reads the
     * interval finer than that.
     */
    public function supersedePrevious($pid, $runId, $limit)
    {
        $pid = (int) $pid;
        $limit = max(1, min(self::PAGE, (int) $limit));
        $this->db->begin();
        try {
            $r = $this->db->select('SELECT generation_id, run_seq, terminal, supersede_cursor,
                    scope_dag FROM '
                . Schema::table('scan_run') . ' WHERE run_id = ? AND project_id = ? FOR UPDATE',
                [$runId, $pid]);
            if (!isset($r[0])) {
                $this->db->rollback();
                return self::superseded(true, 0, 0, 'there is no such run in this project');
            }
            $gen = (int) $r[0][0];
            $seq = (int) $r[0][1];
            $cursor = (int) $r[0][3];
            if ($r[0][2] === null) {
                $this->db->rollback();
                return self::superseded(false, 0, $cursor,
                    'this scan has not finished, so it supersedes nothing yet');
            }
            if ((string) $r[0][2] !== ScanOutcome::COMPLETE) {
                $this->db->rollback();
                return self::superseded(true, 0, $cursor,
                    'this scan did not complete, so the previous report stays the current one');
            }
            // ONE GROUP'S SCAN SUPERSEDES NOTHING (review R8-1). It examined
            // that group's records and no others, and closing every older
            // generation closed the other groups' findings too, as superseded
            // by a run that never read them. Why not the group's share alone:
            // ScanStore::supersedePrevious(). A row that does not say what its
            // scope was is not taken for a whole-project one.
            if (!array_key_exists(4, $r[0]) || $r[0][4] !== null) {
                $this->db->rollback();
                return self::superseded(true, 0, $cursor, self::SUPERSEDE_SCOPED);
            }
            $older = $this->olderGeneration($pid, $cursor, $gen);
            if ($older === null) {
                // Nothing below this run is left. The cursor becomes the run's
                // own generation, once, so "done" is readable from the row.
                if ($cursor < $gen) {
                    $this->db->exec('UPDATE ' . Schema::table('scan_run')
                        . ' SET supersede_cursor = ? WHERE run_id = ? AND supersede_cursor = ?',
                        [$gen, $runId, $cursor]);
                    if ($this->db->affected() !== 1) {
                        $this->db->rollback();
                        return self::superseded(false, 0, $cursor, self::SUPERSEDE_MOVED);
                    }
                }
                $this->db->commit();
                return self::superseded(true, 0, $gen, null);
            }

            // FORCE INDEX: the page is ix_page_v2's three-column prefix, and
            // MySQL 5.7 chose another key for it once in eight matrix passes.
            // See ScanRetention::slicePage().
            $this->db->exec('UPDATE ' . Schema::table('finding') . ' FORCE INDEX (ix_page_v2)
                SET active_slot = NULL, valid_to_seq = ?
                WHERE project_id = ? AND generation_id = ? AND active_slot = 1
                LIMIT ' . $limit, [$seq, $pid, $older]);
            $rows = $this->db->affected();
            $to = $cursor;
            $done = false;
            if ($rows < $limit) {
                // This generation is closed. Past it - and when nothing older
                // is left, all the way to the run's own generation, so the
                // call that finishes says so rather than leaving it to the next.
                $after = $this->olderGeneration($pid, $older + 1, $gen);
                $to = $after === null ? $gen : $older + 1;
                $done = $after === null;
                $this->db->exec('UPDATE ' . Schema::table('scan_run')
                    . ' SET supersede_cursor = ? WHERE run_id = ? AND supersede_cursor = ?',
                    [$to, $runId, $cursor]);
                if ($this->db->affected() !== 1) {
                    $this->db->rollback();
                    return self::superseded(false, 0, $cursor, self::SUPERSEDE_MOVED);
                }
            }
            $this->db->commit();
            return self::superseded($done, $rows, $to, null);
        } catch (\Throwable $e) {
            throw $this->rolledBack($e);
        }
    }

    /** Why a supersede page was given back rather than kept. */
    const SUPERSEDE_MOVED = 'the supersede cursor moved under this page, so nothing was closed; ask again';

    /**
     * The lowest generation of this project at or above $from and below $gen.
     *
     * From the project's own runs - one per generation, a handful per project -
     * through the project-leading run keys. A generation with findings always
     * has its run: purgeRuns() deletes the run row last.
     *
     * @return int|null
     */
    private function olderGeneration($pid, $from, $gen)
    {
        $n = $this->db->select('SELECT MIN(generation_id) FROM ' . Schema::table('scan_run')
            . ' WHERE project_id = ? AND generation_id >= ? AND generation_id < ?',
            [$pid, (int) $from, (int) $gen]);
        if (!isset($n[0]) || !array_key_exists(0, (array) $n[0])) {
            // MIN() always answers one row. No row is a read that did not work,
            // and reading it as "nothing older" would mark the supersede done.
            throw new \RuntimeException('the older generations of this project could not be read');
        }
        $v = array_values((array) $n[0]);
        return $v[0] === null ? null : (int) $v[0];
    }

    private static function superseded($done, $rows, $cursor, $why)
    {
        return ['done' => (bool) $done, 'rows' => (int) $rows, 'cursor' => (int) $cursor,
                'why' => $why];
    }


    // -- reconciliation ------------------------------------------------------

    /**
     * Add records reconciliation found, and republish the total.
     *
     * Deliberately NOT a relaxation of appendManifest()'s planning guard. A
     * separate method with its own phase check keeps "the manifest is frozen"
     * true as a rule with one named exception, rather than true as a rule the
     * planning path happens to enforce.
     *
     * The total is recounted rather than incremented, for the reason
     * freezeManifest() gives: a total added up while writing disagrees with the
     * manifest the moment an insert is ignored, and nothing notices.
     */
    public function reconcileAdd($runId, $epoch, array $records)
    {
        if (!$records) return 0;
        $this->db->begin();
        try {
            $r = $this->db->select('SELECT phase, lease_epoch FROM ' . Schema::table('scan_run')
                . ' WHERE run_id = ? FOR UPDATE', [$runId]);
            if (!isset($r[0]) || $r[0][0] !== ScanPhase::CATCH_UP
                    || (int) $r[0][1] !== (int) $epoch) {
                $this->db->rollback();
                return 0;
            }
            $t = Schema::table('scan_record');
            $m = $this->db->select('SELECT COALESCE(MAX(ordinal), 0) FROM ' . $t
                . ' WHERE run_id = ?', [$runId]);
            $ord = isset($m[0][0]) ? (int) $m[0][0] : 0;
            $now = self::now();
            $chunk = [];
            $added = 0;
            foreach ($records as $rec) {
                $ord++;
                $chunk[] = [$runId, $ord, $rec['id_bin'], $rec['hash'],
                            isset($rec['dag']) ? $rec['dag'] : null, self::REC_PENDING, $now];
                if (count($chunk) >= self::MANIFEST_CHUNK) {
                    $added += $this->insertRecords($t, $chunk);
                    $chunk = [];
                }
            }
            if ($chunk) {
                $added += $this->insertRecords($t, $chunk);
            }
            $c = $this->db->select('SELECT COUNT(*) FROM ' . $t . ' WHERE run_id = ?', [$runId]);
            $this->db->execNoCount('UPDATE ' . Schema::table('scan_run')
                . ' SET manifest_total = ?, updated_at = ? WHERE run_id = ?',
                [isset($c[0][0]) ? (int) $c[0][0] : 0, $now, $runId]);
            $this->db->commit();
            return $added;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Send terminal records back to pending.
     *
     * The attempt counter is untouched on purpose. A record somebody is editing
     * every few seconds would otherwise be requeued forever with a counter that
     * keeps resetting, and the run would never end - so the limit that turns it
     * into a reported exclusion has to survive reconciliation.
     */
    public function requeue($runId, $epoch, array $recordIds)
    {
        if (!$recordIds) return 0;
        return $this->reState($runId, $epoch, $recordIds, self::REC_PENDING, true);
    }

    /**
     * Mark records the project no longer holds.
     *
     * Without this a deleted record can never reach a terminal state and holds
     * the run incomplete forever, which C3 called the worse half of the frozen-
     * manifest problem: the false-complete case at least finishes.
     */
    public function tombstone($runId, $epoch, array $recordIds)
    {
        if (!$recordIds) return 0;
        return $this->reState($runId, $epoch, $recordIds, self::REC_TOMBSTONE, false);
    }

    private function reState($runId, $epoch, array $recordIds, $state, $clearScan)
    {
        $this->db->begin();
        try {
            $r = $this->db->select('SELECT phase, lease_epoch FROM ' . Schema::table('scan_run')
                . ' WHERE run_id = ? FOR UPDATE', [$runId]);
            if (!isset($r[0]) || $r[0][0] !== ScanPhase::CATCH_UP
                    || (int) $r[0][1] !== (int) $epoch) {
                $this->db->rollback();
                return 0;
            }
            $marks = implode(',', array_fill(0, count($recordIds), '?'));
            $params = [$state, self::now()];
            foreach ($recordIds as $id) $params[] = $id;
            $params[] = $runId;
            // THE CLAIM GOES WITH THE STATE. A record sent back to pending
            // while still carrying its token could be committed by the worker
            // that was holding it when catch-up requeued it - and that reading's
            // staleness is the entire reason the record was requeued.
            $this->db->exec('UPDATE ' . Schema::table('scan_record') . '
                SET state = ?, ' . ($clearScan ? 'version_scanned = NULL, ' : '')
                . 'claim_owner = NULL, claim_seq = 0, updated_at = ?
                WHERE record_id_bin IN (' . $marks . ') AND run_id = ?', $params);
            $n = $this->db->affected();
            // manifest_done is a counter and this moved rows out of (or into) a
            // terminal state, so it is recounted rather than adjusted.
            $d = $this->db->select('SELECT COUNT(*) FROM ' . Schema::table('scan_record')
                . ' WHERE run_id = ? AND state >= ?', [$runId, self::REC_DONE]);
            $this->db->execNoCount('UPDATE ' . Schema::table('scan_run')
                . ' SET manifest_done = ?, updated_at = ? WHERE run_id = ?',
                [isset($d[0][0]) ? (int) $d[0][0] : 0, self::now(), $runId]);
            $this->db->commit();
            return $n;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function recordStates($runId)
    {
        $out = [];
        foreach ($this->db->select('SELECT state, COUNT(*) FROM ' . Schema::table('scan_record')
                . ' WHERE run_id = ? GROUP BY state', [$runId]) as $r) {
            $out[(int) $r[0]] = (int) $r[1];
        }
        return $out;
    }

    public function scannedVersions($runId, array $recordIds)
    {
        if (!$recordIds) return [];
        $marks = implode(',', array_fill(0, count($recordIds), '?'));
        $params = [$runId];
        foreach ($recordIds as $id) $params[] = $id;
        $out = [];
        foreach ($this->db->select('SELECT record_id_bin, version_scanned, state FROM '
                . Schema::table('scan_record') . ' WHERE run_id = ? AND record_id_bin IN ('
                . $marks . ')', $params) as $r) {
            $out[(string) $r[0]] = ['version' => $r[1], 'state' => (int) $r[2]];
        }
        return $out;
    }

    public function progressState($runId)
    {
        $r = $this->db->select('SELECT catchup_cursor, catchup_round, catchup_dirty,
            rollup_cursor, fence_open, fence_target FROM ' . Schema::table('scan_run')
            . ' WHERE run_id = ?', [$runId]);
        if (!isset($r[0])) return null;
        return ['catchupCursor' => $r[0][0], 'catchupRound' => (int) $r[0][1],
                'catchupDirty' => (int) $r[0][2], 'rollupCursor' => (int) $r[0][3],
                'fenceOpen' => $r[0][4], 'fenceTarget' => $r[0][5]];
    }

    public function setProgressState($runId, $epoch, array $st)
    {
        $sets = [];
        $params = [];
        $map = ['catchupCursor' => 'catchup_cursor', 'catchupRound' => 'catchup_round',
                'catchupDirty' => 'catchup_dirty', 'rollupCursor' => 'rollup_cursor',
                'fenceTarget' => 'fence_target'];
        foreach ($map as $k => $col) {
            if (!array_key_exists($k, $st)) continue;
            $sets[] = $col . ' = ?';
            $params[] = $st[$k];
        }
        if (!$sets) return false;
        $sets[] = 'updated_at = ?';
        $params[] = self::now();
        $params[] = $runId;
        $params[] = $epoch;
        $this->db->exec('UPDATE ' . Schema::table('scan_run') . ' SET ' . implode(', ', $sets)
            . ' WHERE run_id = ? AND lease_epoch = ?', $params);
        // Not affected() === 1. Writing a cursor that already holds the value it
        // is being set to changes no row, and MySQL reports rows CHANGED - the
        // trap this file has hit three times. Ask instead.
        if ($this->db->affected() === 1) return true;
        $r = $this->db->select('SELECT 1 FROM ' . Schema::table('scan_run')
            . ' WHERE run_id = ? AND lease_epoch = ?', [$runId, $epoch]);
        return isset($r[0]);
    }

    /**
     * A locking read and nothing else. No begin() here: the hold has to last
     * until the caller's page is written, so it lives in the caller's
     * transaction, and a commit of our own would drop it before the counts
     * it is guarding were added.
     */
    public function holdRollupCursor($runId, $epoch)
    {
        $r = $this->db->select('SELECT lease_epoch, terminal, rollup_cursor FROM '
            . Schema::table('scan_run') . ' WHERE run_id = ? FOR UPDATE', [$runId]);
        if (!isset($r[0])) return self::ROLLUP_NO_RUN;
        if ((int) $r[0][0] !== (int) $epoch) return self::ROLLUP_TAKEN_OVER;
        if ($r[0][1] !== null) return self::ROLLUP_FINISHED;
        return (int) $r[0][2];
    }

    public function addAggregate($runId, $kind, $axis1, $axis2, $cnt, $blocks = 0, $samples = null)
    {
        $this->db->execNoCount('INSERT INTO ' . Schema::table('scan_aggregate') . '
            (run_id, kind, axis1, axis2, cnt, samples, blocks_coverage)
            VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE cnt = cnt + VALUES(cnt),
                                    blocks_coverage = GREATEST(blocks_coverage, VALUES(blocks_coverage)),
                                    samples = COALESCE(samples, VALUES(samples))',
            [$runId, $kind, (string) $axis1, (string) $axis2, (int) $cnt, $samples,
             $blocks ? 1 : 0]);
        return true;
    }

    /**
     * `cnt = 1` on the duplicate arm, NOT `cnt = cnt`: a row an earlier build
     * counted up through addAggregate() is brought back to a presence the first
     * time it is marked, instead of keeping a number that meant something else.
     * Uncounted - nothing reads how many rows this changed, and the answer
     * (1 inserted, 2 updated, 0 unchanged) would mean nothing to a caller.
     */
    public function markAggregate($runId, $kind, $axis1, $axis2, $blocks = 0, $samples = null)
    {
        $this->db->execNoCount('INSERT INTO ' . Schema::table('scan_aggregate') . '
            (run_id, kind, axis1, axis2, cnt, samples, blocks_coverage)
            VALUES (?,?,?,?,1,?,?)
            ON DUPLICATE KEY UPDATE cnt = 1,
                                    blocks_coverage = GREATEST(blocks_coverage, VALUES(blocks_coverage)),
                                    samples = COALESCE(samples, VALUES(samples))',
            [$runId, $kind, (string) $axis1, (string) $axis2, $samples, $blocks ? 1 : 0]);
        return true;
    }

    public function blockingAggregates($runId)
    {
        $r = $this->db->select('SELECT COALESCE(SUM(cnt), 0) FROM ' . Schema::table('scan_aggregate')
            . ' WHERE run_id = ? AND blocks_coverage = 1', [$runId]);
        return isset($r[0][0]) ? (int) $r[0][0] : 0;
    }

    public function audit($pid, $runId, $event, $actor, $detail)
    {
        $this->db->execNoCount('INSERT INTO ' . Schema::table('scan_audit') . '
            (project_id, run_id, event, actor, detail, created_at) VALUES (?,?,?,?,?,?)',
            [$pid, $runId, $event, $actor, $detail, self::now()]);
    }

    // -- time, in one place so a test can reason about it --------------------

    /** Seconds, for measuring how long a call has held a lock; see claimPending(). */
    private function clock()
    {
        return $this->clock === null ? microtime(true) : (float) call_user_func($this->clock);
    }

    private static function now() { return gmdate('Y-m-d H:i:s'); }
    private static function inSeconds($s) { return gmdate('Y-m-d H:i:s', time() + (int) $s); }
}
