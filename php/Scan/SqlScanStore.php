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

    public function __construct(ScanDb $db)
    {
        $this->db = $db;
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
            isset($run['policy_revision']) ? $run['policy_revision'] : 1,
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
        $r = $this->db->select('SELECT ' . implode(', ', self::RUN_COLUMNS) . '
            FROM ' . Schema::table('scan_run') . ' WHERE run_id = ? AND project_id = ?',
            [$runId, $pid]);
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
        // there is one code path that can be wrong rather than two.
        $this->appendManifest($runId, $records);
        $n = $this->freezeManifest($runId);
        return $n === false ? 0 : $n;
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
    public function appendManifest($runId, array $records)
    {
        if (!$records) return 0;
        $this->db->begin();
        try {
            // FOR UPDATE, so two planners cannot interleave their ordinals, and
            // so a freeze cannot land between the phase check and the insert.
            $r = $this->db->select('SELECT phase FROM ' . Schema::table('scan_run')
                . ' WHERE run_id = ? FOR UPDATE', [$runId]);
            if (!isset($r[0]) || $r[0][0] !== ScanPhase::PLANNING) {
                $this->db->rollback();
                return 0;
            }
            $t = Schema::table('scan_record');
            $m = $this->db->select('SELECT COALESCE(MAX(ordinal), 0) FROM ' . $t
                . ' WHERE run_id = ?', [$runId]);
            $ord = isset($m[0][0]) ? (int) $m[0][0] : 0;
            $now = self::now();
            $added = 0;
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
            $this->db->commit();
            return $added;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
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
            $this->db->rollback();
            throw $e;
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
    public function claimPending($runId, $owner, $epoch, $limit, $staleSeconds = 900)
    {
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
            $rows = $this->db->select('SELECT ordinal, record_id_bin, record_hash, dag_at_fence,
                attempts, version_scanned
                FROM ' . $t . '
                WHERE run_id = ? AND (state = ? OR (state = ? AND updated_at < ?))
                ORDER BY ordinal LIMIT ' . $limit,
                [$runId, self::REC_PENDING, self::REC_CLAIMED, $stale]);
            $out = [];
            foreach ($rows as $row) {
                $out[] = ['ordinal' => (int) $row[0], 'id_bin' => $row[1],
                          'hash' => $row[2], 'dag' => $row[3],
                          'attempts' => (int) $row[4], 'version' => $row[5],
                          'claim' => $token];
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
            // either. This is the file that says so everywhere else.
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
    public function commitBatch($runId, $owner, $epoch, array $batch, &$heldCount = null)
    {
        // Nothing held until the transaction has committed, so every refusal,
        // throw and rollback below leaves it at 0. See ScanStore::commitBatch().
        $heldCount = 0;
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
            $stored = $this->insertFindings($findings, $held);
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
     * TWO STATEMENTS, NOT ONE PER ROW. The increment saturates in SQL, then the
     * rows that have reached the limit are retired in a second pass over the
     * same predicate - which is also what makes `retired` a count of rows that
     * really changed rather than an inference from the first statement.
     *
     * @return array{counted:int, retired:int}
     */
    public function noteAttempts($runId, $epoch, $owner, array $claims, $maxAttempts, $exhausted)
    {
        if (!$claims) return ['counted' => 0, 'retired' => 0];
        $cap = max(1, (int) $maxAttempts);
        $this->db->begin();
        try {
            $r = $this->db->select('SELECT lease_epoch FROM ' . Schema::table('scan_run')
                . ' WHERE run_id = ? FOR UPDATE', [$runId]);
            if (!isset($r[0]) || (int) $r[0][0] !== (int) $epoch) {
                $this->db->rollback();
                return ['counted' => 0, 'retired' => 0];
            }
            $t = Schema::table('scan_record');
            $counted = 0;
            $retired = 0;
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
            }
            $this->db->commit();
            return ['counted' => $counted, 'retired' => $retired];
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
             record_hash, record_id_bin, event_id, instance, host_form, field, version_scanned)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE version_scanned = VALUES(version_scanned)', [
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
    private static function findingRow(array $f)
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
            'valid_from_seq'    => isset($f['valid_from_seq']) ? (int) $f['valid_from_seq'] : 0,
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
     * @return int rows stored
     */
    private function insertFindings(array $findings, array $held)
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
            $row = self::findingRow($f);
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
     */
    public function manifestComplete($runId)
    {
        $r = $this->db->select('SELECT EXISTS(SELECT 1 FROM ' . Schema::table('scan_record')
            . ' FORCE INDEX (ix_run_state) WHERE run_id = ? AND state < ?)',
            [$runId, self::REC_DONE]);
        return isset($r[0][0]) && (int) $r[0][0] === 0;
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

    /** One keyset page. Never OFFSET: it degrades quadratically over a run. */
    public function findings($projectId, $generationId, array $filter, $afterId, $limit)
    {
        $limit = max(1, min(100, (int) $limit));
        $sql = 'SELECT finding_id, record_id_bin, event_id, instance, host_form, field,
                       check_type, reason_code, rule_ord, dag_key, value_bin, value_truncated
                FROM ' . Schema::table('finding') . '
                WHERE project_id = ? AND generation_id = ? AND active_slot = 1
                  AND finding_id > ?';
        // PROJECT FIRST, in the predicate and in the index. This method had
        // no production caller at all, which is the only reason a read
        // filtered by generation alone - with the generation the literal 1
        // for every project - was stored corruption rather than a live
        // cross-project disclosure. The report is what would have turned it
        // into one.
        $params = [(int) $projectId, $generationId, (int) $afterId];
        // Only allowlisted axes reach the statement; an unknown key is dropped
        // rather than interpolated.
        foreach (['host_form' => 'host_form', 'reason_code' => 'reason_code',
                  'dag_key' => 'dag_key', 'check_type' => 'check_type'] as $k => $col) {
            if (isset($filter[$k]) && $filter[$k] !== '') {
                $sql .= ' AND ' . $col . ' = ?';
                $params[] = $filter[$k];
            }
        }
        $sql .= ' ORDER BY finding_id LIMIT ' . $limit;
        return $this->db->select($sql, $params);
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

    // -- retention ----------------------------------------------------------

    /**
     * Expire value previews whose TTL has passed.
     *
     * The value is NULLED, not the row: the finding is still true, and deleting
     * it would make a report shrink as it ages, which reads as the project
     * having improved.
     */
    public function expireValues($now)
    {
        $this->db->exec('UPDATE ' . Schema::table('finding') . '
            SET value_bin = NULL, value_fingerprint = NULL, value_expires_at = NULL
            WHERE value_expires_at IS NOT NULL AND value_expires_at <= ?', [$now]);
        return $this->db->affected();
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

    private static function now() { return gmdate('Y-m-d H:i:s'); }
    private static function inSeconds($s) { return gmdate('Y-m-d H:i:s', time() + (int) $s); }
}
