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
 *   are decided by affected() — claim, cancel, finish, leaseSlot, releaseSlot.
 *   Each of these necessarily CHANGES a column when it succeeds (a cursor
 *   advances, a slot goes from NULL to an owner), which is what makes the count
 *   meaningful.
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
 * All of this is exercised against MySQL 5.7/8.0 and MariaDB 10.5/10.11, under
 * the server default isolation and under READ COMMITTED, by tests/mysql/run.php.
 * The same class runs there as runs in REDCap; only ScanDb differs.
 *
 * PHP 7.4 throughout.
 */
final class SqlScanStore implements ScanStore
{
    /** @var ScanDb */
    private $db;

    public function __construct(ScanDb $db)
    {
        $this->db = $db;
    }

    // -- runs ---------------------------------------------------------------

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
        $sql = 'INSERT INTO ' . Schema::table('scan_run') . '
            (run_uuid, project_id, run_seq, generation_id, created_by, scope_dag, scope_kind,
             run_kind, baseline_generation, phase, coverage, detail, values_state, policy_json,
             policy_revision, fingerprint, fence_open, created_at, updated_at, active_slot)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)';
        $params = [
            isset($run['uuid']) ? $run['uuid'] : random_bytes(16),
            $pid,
            isset($run['run_seq']) ? $run['run_seq'] : 1,
            isset($run['generation_id']) ? $run['generation_id'] : 1,
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
            $this->db->exec($sql, $params);
        } catch (\Throwable $e) {
            // Any insert failure here is reported as busy WITHOUT detail. A
            // message distinguishing "slot taken" from "column too long" would
            // be an oracle for one caller and a support ticket for the other;
            // the run row that owns the slot is never named either way.
            return ['ok' => false, 'busy' => true, 'run' => null,
                    'why' => 'a validation scan is already running for this project'];
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
        // fence_open/fence_target and cancel_requested_at are here because
        // ScanPromotion reads the run row to decide what it may claim: a fence
        // it cannot see is a fence it treats as absent, which silently
        // downgrades every fenced run to manifest-complete. The in-memory store
        // disagreed with this one about exactly these three columns and the
        // promotion test is what said so.
        $r = $this->db->select('SELECT run_id, project_id, scope_dag, phase, terminal, coverage,
            detail, values_state, policy_revision, fingerprint, manifest_total, manifest_done,
            cursor_ordinal, lease_epoch, generation_id, created_by, detail_rows, detail_bytes,
            fence_open, fence_target, cancel_requested_at
            FROM ' . Schema::table('scan_run') . ' WHERE run_id = ? AND project_id = ?',
            [$runId, $pid]);
        if (!isset($r[0])) return null;
        $k = ['run_id', 'project_id', 'scope_dag', 'phase', 'terminal', 'coverage', 'detail',
              'values_state', 'policy_revision', 'fingerprint', 'manifest_total', 'manifest_done',
              'cursor_ordinal', 'lease_epoch', 'generation_id', 'created_by', 'detail_rows',
              'detail_bytes', 'fence_open', 'fence_target', 'cancel_requested_at'];
        return array_combine($k, $r[0]);
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
                if (count($chunk) >= 500) {
                    $this->insertRecords($t, $chunk);
                    $added += $this->db->affected();
                    $chunk = [];
                }
            }
            if ($chunk) {
                $this->insertRecords($t, $chunk);
                $added += $this->db->affected();
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
            $this->db->exec('UPDATE ' . Schema::table('scan_run') . '
                SET manifest_total = ?, phase = ?, updated_at = ? WHERE run_id = ?',
                [$total, ScanPhase::SCANNING, self::now(), $runId]);
            $this->db->commit();
            return $total;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

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
            $to = $from;
            foreach ($rows as $row) {
                $out[] = ['ordinal' => (int) $row[0], 'id_bin' => $row[1],
                          'hash' => $row[2], 'dag' => $row[3],
                          // How many times this record has already been tried.
                          // The worker needs it to decide between requeueing a
                          // record that will not hold still and declaring it a
                          // blocking exclusion - a decision it cannot make from
                          // what it can see in one request.
                          'attempts' => (int) $row[4], 'version' => $row[5]];
                if ((int) $row[0] > $to) $to = (int) $row[0];
            }
            $this->db->exec('UPDATE ' . Schema::table('scan_run') . '
                SET cursor_ordinal = ?, lease_owner = ?, lease_expires_at = ?, updated_at = ?
                WHERE run_id = ?',
                [$to, $owner, self::inSeconds(300), self::now(), $runId]);
            $this->db->commit();
            return $out;
        } catch (\Throwable $e) {
            $this->db->rollback();
            return false;
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
                          'attempts' => (int) $row[4], 'version' => $row[5]];
            }
            if ($out) {
                $ords = [];
                foreach ($out as $o) $ords[] = $o['ordinal'];
                $marks = implode(',', array_fill(0, count($ords), '?'));
                $this->db->exec('UPDATE ' . $t . ' SET state = ?, updated_at = ?
                    WHERE run_id = ? AND ordinal IN (' . $marks . ') AND state < ?',
                    array_merge([self::REC_CLAIMED, self::now(), $runId], $ords, [self::REC_DONE]));
            }
            $this->db->commit();
            return $out;
        } catch (\Throwable $e) {
            // A failed read is not an empty one, and this is the file that says
            // so everywhere else.
            $this->db->rollback();
            return false;
        }
    }

    /**
     * I2/I3: findings, record states and the cursor commit as one transaction.
     *
     * The record states are written LAST inside the transaction and only for
     * rows this worker claimed, so a crash anywhere leaves them pending and the
     * batch is simply re-claimable. Nothing marks a record done except the
     * transaction that scanned it.
     */
    public function commitBatch($runId, $owner, $epoch, $expectCursor, array $batch)
    {
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
            $fence = $this->db->select('SELECT lease_epoch, cancel_requested_at FROM '
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
            if ((int) $fence[0][0] !== (int) $epoch) {
                $this->db->rollback();
                return 'another worker took over this scan while these records were being '
                     . 'examined, so nothing from them was kept; they will be examined again';
            }

            foreach (isset($batch['findings']) ? $batch['findings'] : [] as $f) {
                $this->insertFinding($f);
            }
            // UNIQUENESS CANDIDATES, in the SAME transaction as the findings and
            // the record states. A uniqueness verdict is the only thing in the
            // scan that depends on records other than the one being examined, so
            // a candidate written outside the batch that produced it could
            // survive a rolled-back batch and make a record look like a
            // duplicate of a reading that was discarded.
            foreach (isset($batch['candidates']) ? $batch['candidates'] : [] as $c) {
                $this->insertCandidate($c);
            }
            $applied = 0;
            foreach (isset($batch['records']) ? $batch['records'] : [] as $rec) {
                // `state < REC_DONE` rather than `= REC_PENDING`: a straggler
                // claimed by claimPending() is in CLAIMED, and must still be
                // committable. Terminal rows stay untouched, which is I3 - only
                // the transaction that scanned a record marks it done, and it
                // never marks it done twice.
                $this->db->exec('UPDATE ' . Schema::table('scan_record') . '
                    SET state = ?, attempts = attempts + 1, version_scanned = ?, updated_at = ?
                    WHERE run_id = ? AND ordinal = ? AND state < ?',
                    [$rec['state'], isset($rec['version']) ? $rec['version'] : null, self::now(),
                     $runId, $rec['ordinal'], self::REC_DONE]);
                // Terminal rows only. A requeued record is written back as
                // pending, which changes its attempt count and its timestamp -
                // so it affects a row without having been finished, and
                // counting it would walk progress past the manifest total.
                $applied += ($this->db->affected() === 1
                    && (int) $rec['state'] >= self::REC_DONE) ? 1 : 0;
            }
            // Counters last, and NOT gated on affected(): a batch that finished
            // zero records and found zero findings changes no column, reports
            // zero, and would roll itself back for having nothing to say. The
            // fence above already decided whether this transaction may commit -
            // asking twice, with a weaker question, only adds a way to be wrong.
            // What ACTUALLY became terminal, not what was offered. A record
            // re-offered after a requeue would otherwise be counted twice and
            // the progress figure would pass the manifest total.
            $done = $applied;
            $this->db->exec('UPDATE ' . Schema::table('scan_run') . '
                SET manifest_done = manifest_done + ?, detail_rows = detail_rows + ?,
                    detail_bytes = detail_bytes + ?, updated_at = ?
                WHERE run_id = ?',
                [$done, count(isset($batch['findings']) ? $batch['findings'] : []),
                 isset($batch['bytes']) ? (int) $batch['bytes'] : 0, self::now(), $runId]);

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            // The server refused the write. Named, because "cancelled or taken
            // over" would be a guess and this is the one cause an administrator
            // can actually act on.
            return 'the database refused to store these findings (' . get_class($e)
                 . '), so nothing from these records was kept';
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
     * FENCED, and on the epoch this worker held. A worker whose rows were taken
     * over by someone else must not be able to yank them back out of the new
     * holder's hands, so a moved epoch releases nothing.
     *
     * The cursor moves back with them. Scanning hands out rows ABOVE the cursor,
     * so released rows below it would only ever be reachable by the straggler
     * sweep - which is a slower path for records that were never examined at all.
     *
     * @return int rows handed back
     */
    public function releaseClaims($runId, $epoch, array $ordinals)
    {
        if (!$ordinals) return 0;
        $this->db->begin();
        try {
            $r = $this->db->select('SELECT lease_epoch, cursor_ordinal FROM '
                . Schema::table('scan_run') . ' WHERE run_id = ? FOR UPDATE', [$runId]);
            if (!isset($r[0]) || (int) $r[0][0] !== (int) $epoch) {
                $this->db->rollback();
                return 0;
            }
            $marks = implode(',', array_fill(0, count($ordinals), '?'));
            $this->db->exec('UPDATE ' . Schema::table('scan_record') . '
                SET state = ?, updated_at = ?
                WHERE run_id = ? AND state = ? AND ordinal IN (' . $marks . ')',
                array_merge([self::REC_PENDING, self::now(), $runId, self::REC_CLAIMED],
                            $ordinals));
            $n = $this->db->affected();
            $low = min($ordinals) - 1;
            if ($low < (int) $r[0][1]) {
                $this->db->exec('UPDATE ' . Schema::table('scan_run')
                    . ' SET cursor_ordinal = ?, updated_at = ? WHERE run_id = ? AND lease_epoch = ?',
                    [$low, self::now(), $runId, $epoch]);
            }
            $this->db->commit();
            return $n;
        } catch (\Throwable $e) {
            $this->db->rollback();
            return 0;
        }
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
    private function insertCandidate(array $c)
    {
        $this->db->exec('INSERT INTO ' . Schema::table('unique_candidate') . '
            (generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
             record_hash, record_id_bin, event_id, instance, host_form, field, version_scanned)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE version_scanned = VALUES(version_scanned)', [
            $c['generation_id'], $c['rule_source_id'], $c['rule_revision'], $c['group_hmac'],
            isset($c['scope_key']) ? $c['scope_key'] : '',
            $c['record_hash'], $c['record_id_bin'],
            isset($c['event_id']) ? $c['event_id'] : null,
            isset($c['instance']) ? $c['instance'] : 1,
            $c['host_form'], $c['field'],
            isset($c['version']) ? $c['version'] : null,
        ]);
    }

    private function insertFinding(array $f)
    {
        // A STAGED row is written with active_slot NULL and is invisible to every
        // report query until the finalizer publishes its group. Ordinary
        // findings are active from the moment they are written.
        $staged = isset($f['stage_epoch']) && $f['stage_epoch'] !== null;
        $this->db->exec('INSERT INTO ' . Schema::table('finding') . '
            (generation_id, finding_identity, valid_from_seq, active_slot, record_hash,
             record_id_bin, event_id, arm_id, instance, host_form, field, rule_source_id,
             rule_revision, rule_ord, check_type, reason_code, reason_bits, severity, dag_key,
             status_key, value_bin, value_len, value_fingerprint, value_truncated, value_binary,
             value_expires_at, group_hmac, stage_epoch)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $f['generation_id'], $f['identity'], $f['seq'], $staged ? null : 1,
            $f['record_hash'], $f['record_id_bin'],
            isset($f['event_id']) ? $f['event_id'] : null,
            isset($f['arm_id']) ? $f['arm_id'] : null,
            isset($f['instance']) ? $f['instance'] : 1,
            $f['host_form'], $f['field'], $f['rule_source_id'], $f['rule_revision'],
            isset($f['rule_ord']) ? $f['rule_ord'] : 0,
            $f['check_type'], $f['reason_code'],
            isset($f['reason_bits']) ? $f['reason_bits'] : 0,
            isset($f['severity']) ? $f['severity'] : 0,
            isset($f['dag_key']) ? $f['dag_key'] : null,
            isset($f['status_key']) ? $f['status_key'] : null,
            isset($f['value_bin']) ? $f['value_bin'] : null,
            isset($f['value_len']) ? $f['value_len'] : null,
            isset($f['value_fingerprint']) ? $f['value_fingerprint'] : null,
            isset($f['value_truncated']) ? $f['value_truncated'] : 0,
            isset($f['value_binary']) ? $f['value_binary'] : 0,
            isset($f['value_expires_at']) ? $f['value_expires_at'] : null,
            isset($f['group_hmac']) ? $f['group_hmac'] : null,
            $staged ? $f['stage_epoch'] : null,
        ]);
    }

    /**
     * I4: completeness is a PREDICATE over record states, never a counter.
     *
     * A counter can be incremented twice by a retry; this cannot be wrong in
     * that direction, because a record is terminal or it is not.
     */
    public function manifestComplete($runId)
    {
        $r = $this->db->select('SELECT COUNT(*) FROM ' . Schema::table('scan_record')
            . ' WHERE run_id = ? AND state < ?', [$runId, self::REC_DONE]);
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
            $this->db->exec('UPDATE ' . Schema::table('scan_run') . '
                SET phase = ?, cursor_ordinal = ?, updated_at = ? WHERE run_id = ?',
                // The cursor belongs to the phase that used it. Carrying it into
                // catch-up would make the straggler sweep start part way through
                // a manifest it walks by state rather than by position.
                [$to, 0, self::now(), $runId]);
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            return false;
        }
    }

    public function finish($runId, array $outcome)
    {
        $this->db->exec('UPDATE ' . Schema::table('scan_run') . '
            SET phase = ?, terminal = ?, coverage = ?, detail = ?, active_slot = NULL,
                terminal_reason = ?, updated_at = ?
            WHERE run_id = ? AND active_slot = 1',
            ['terminal', $outcome['terminal'], $outcome['coverage'], $outcome['detail'],
             isset($outcome['why']) ? substr((string) $outcome['why'], 0, 255) : null,
             self::now(), $runId]);
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

    // -- worker slots -------------------------------------------------------

    /**
     * Lease one installation-wide slot.
     *
     * An UPDATE with a predicate, never an INSERT: the rows are precreated, so
     * two workers racing for the last slot are serialised by the row lock and
     * exactly one sees affected() === 1. An expired lease is takeable in the
     * same statement, so a dead worker cannot hold the server hostage.
     */
    public function leaseSlot($owner, $runId, $ttlSeconds)
    {
        $t = Schema::table('scan_worker_slot');
        $this->db->exec('UPDATE ' . $t . '
            SET owner = ?, run_id = ?, epoch = epoch + 1, expires_at = ?
            WHERE (owner IS NULL OR expires_at < ?) ORDER BY slot_no LIMIT 1',
            [$owner, $runId, self::inSeconds($ttlSeconds), self::now()]);
        if ($this->db->affected() !== 1) return null;
        $r = $this->db->select('SELECT slot_no, epoch FROM ' . $t
            . ' WHERE owner = ? ORDER BY slot_no LIMIT 1', [$owner]);
        if (!isset($r[0])) return null;
        return ['slot_no' => (int) $r[0][0], 'epoch' => (int) $r[0][1]];
    }

    /** A stale holder releases nothing: the epoch is part of the predicate. */
    public function releaseSlot($slotNo, $owner, $epoch)
    {
        $this->db->exec('UPDATE ' . Schema::table('scan_worker_slot') . '
            SET owner = NULL, run_id = NULL, expires_at = NULL
            WHERE slot_no = ? AND owner = ? AND epoch = ?', [$slotNo, $owner, $epoch]);
        return $this->db->affected() === 1;
    }

    // -- reads --------------------------------------------------------------

    /** One keyset page. Never OFFSET: it degrades quadratically over a run. */
    public function findings($generationId, array $filter, $afterId, $limit)
    {
        $limit = max(1, min(100, (int) $limit));
        $sql = 'SELECT finding_id, record_id_bin, event_id, instance, host_form, field,
                       check_type, reason_code, rule_ord, dag_key, value_bin, value_truncated
                FROM ' . Schema::table('finding') . '
                WHERE generation_id = ? AND active_slot = 1 AND finding_id > ?';
        $params = [$generationId, (int) $afterId];
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

    /** Purge finished runs past retention. Active runs are never touched. */
    public function purgeRuns($pid, $olderThan)
    {
        $ids = $this->db->select('SELECT run_id FROM ' . Schema::table('scan_run') . '
            WHERE project_id = ? AND active_slot IS NULL AND updated_at < ?', [$pid, $olderThan]);
        $n = 0;
        foreach ($ids as $row) {
            $id = (int) $row[0];
            // Children first: there are no foreign keys, so cascade is this
            // order and nothing else. Reversing it would orphan rows whose
            // parent is already gone.
            $this->db->exec('DELETE FROM ' . Schema::table('scan_record') . ' WHERE run_id = ?', [$id]);
            $this->db->exec('DELETE FROM ' . Schema::table('scan_aggregate') . ' WHERE run_id = ?', [$id]);
            $this->db->exec('DELETE FROM ' . Schema::table('scan_run') . ' WHERE run_id = ?', [$id]);
            $n++;
        }
        return $n;
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
                if (count($chunk) >= 500) {
                    $this->insertRecords($t, $chunk);
                    $added += $this->db->affected();
                    $chunk = [];
                }
            }
            if ($chunk) {
                $this->insertRecords($t, $chunk);
                $added += $this->db->affected();
            }
            $c = $this->db->select('SELECT COUNT(*) FROM ' . $t . ' WHERE run_id = ?', [$runId]);
            $this->db->exec('UPDATE ' . Schema::table('scan_run')
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
            $this->db->exec('UPDATE ' . Schema::table('scan_record') . '
                SET state = ?, ' . ($clearScan ? 'version_scanned = NULL, ' : '') . 'updated_at = ?
                WHERE record_id_bin IN (' . $marks . ') AND run_id = ?', $params);
            $n = $this->db->affected();
            // manifest_done is a counter and this moved rows out of (or into) a
            // terminal state, so it is recounted rather than adjusted.
            $d = $this->db->select('SELECT COUNT(*) FROM ' . Schema::table('scan_record')
                . ' WHERE run_id = ? AND state >= ?', [$runId, self::REC_DONE]);
            $this->db->exec('UPDATE ' . Schema::table('scan_run')
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

    public function addAggregate($runId, $kind, $axis1, $axis2, $cnt, $blocks = 0, $samples = null)
    {
        $this->db->exec('INSERT INTO ' . Schema::table('scan_aggregate') . '
            (run_id, kind, axis1, axis2, cnt, samples, blocks_coverage)
            VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE cnt = cnt + VALUES(cnt),
                                    blocks_coverage = GREATEST(blocks_coverage, VALUES(blocks_coverage)),
                                    samples = COALESCE(samples, VALUES(samples))',
            [$runId, $kind, (string) $axis1, (string) $axis2, (int) $cnt, $samples,
             $blocks ? 1 : 0]);
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
        $this->db->exec('INSERT INTO ' . Schema::table('scan_audit') . '
            (project_id, run_id, event, actor, detail, created_at) VALUES (?,?,?,?,?,?)',
            [$pid, $runId, $event, $actor, $detail, self::now()]);
    }

    // -- time, in one place so a test can reason about it --------------------

    private static function now() { return gmdate('Y-m-d H:i:s'); }
    private static function inSeconds($s) { return gmdate('Y-m-d H:i:s', time() + (int) $s); }
}
