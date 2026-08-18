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
        $r = $this->db->select('SELECT run_id, project_id, scope_dag, phase, terminal, coverage,
            detail, values_state, policy_revision, fingerprint, manifest_total, manifest_done,
            cursor_ordinal, lease_epoch, generation_id, created_by, detail_rows, detail_bytes
            FROM ' . Schema::table('scan_run') . ' WHERE run_id = ? AND project_id = ?',
            [$runId, $pid]);
        if (!isset($r[0])) return null;
        $k = ['run_id', 'project_id', 'scope_dag', 'phase', 'terminal', 'coverage', 'detail',
              'values_state', 'policy_revision', 'fingerprint', 'manifest_total', 'manifest_done',
              'cursor_ordinal', 'lease_epoch', 'generation_id', 'created_by', 'detail_rows',
              'detail_bytes'];
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
        $this->db->begin();
        try {
            $ord = 0;
            $t = Schema::table('scan_record');
            $now = self::now();
            // Batched, because one statement per record is one round trip per
            // record and the manifest is the largest thing a run writes.
            $chunk = [];
            foreach ($records as $rec) {
                $ord++;
                $chunk[] = [$runId, $ord, $rec['id_bin'], $rec['hash'],
                            isset($rec['dag']) ? $rec['dag'] : null, self::REC_PENDING, $now];
                if (count($chunk) >= 500) { $this->insertRecords($t, $chunk); $chunk = []; }
            }
            if ($chunk) $this->insertRecords($t, $chunk);

            $this->db->exec('UPDATE ' . Schema::table('scan_run') . '
                SET manifest_total = ?, phase = ?, updated_at = ? WHERE run_id = ?',
                [$ord, 'scanning', $now, $runId]);
            $this->db->commit();
            return $ord;
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
        $this->db->exec('INSERT INTO ' . $table . '
            (run_id, ordinal, record_id_bin, record_hash, dag_at_fence, state, updated_at)
            VALUES ' . $ph, $flat);
    }

    /**
     * Claim the next ordinal range, fenced on the epoch.
     *
     * The claim is itself a compare-and-set: a worker whose epoch has already
     * moved gets nothing, rather than a range it would fail to commit later and
     * would have spent a full getData() on first.
     */
    public function claim($runId, $owner, $epoch, $limit)
    {
        $limit = max(1, (int) $limit);
        $this->db->exec('UPDATE ' . Schema::table('scan_run') . '
            SET cursor_ordinal = cursor_ordinal + ?, lease_owner = ?, lease_expires_at = ?,
                updated_at = ?
            WHERE run_id = ? AND lease_epoch = ? AND cancel_requested_at IS NULL
              AND phase = ?',
            [$limit, $owner, self::inSeconds(300), self::now(), $runId, $epoch, 'scanning']);
        if ($this->db->affected() !== 1) return [];

        $r = $this->db->select('SELECT cursor_ordinal FROM ' . Schema::table('scan_run')
            . ' WHERE run_id = ?', [$runId]);
        $to = isset($r[0][0]) ? (int) $r[0][0] : 0;
        $from = $to - $limit;

        $rows = $this->db->select('SELECT ordinal, record_id_bin, record_hash, dag_at_fence
            FROM ' . Schema::table('scan_record') . '
            WHERE run_id = ? AND ordinal > ? AND ordinal <= ? AND state = ?
            ORDER BY ordinal', [$runId, $from, $to, self::REC_PENDING]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['ordinal' => (int) $row[0], 'id_bin' => $row[1],
                      'hash' => $row[2], 'dag' => $row[3]];
        }
        return $out;
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
            if (!isset($fence[0])) { $this->db->rollback(); return false; }
            if ((int) $fence[0][0] !== (int) $epoch || $fence[0][1] !== null) {
                $this->db->rollback();
                return false;
            }

            foreach (isset($batch['findings']) ? $batch['findings'] : [] as $f) {
                $this->insertFinding($f);
            }
            foreach (isset($batch['records']) ? $batch['records'] : [] as $rec) {
                $this->db->exec('UPDATE ' . Schema::table('scan_record') . '
                    SET state = ?, attempts = attempts + 1, version_scanned = ?, updated_at = ?
                    WHERE run_id = ? AND ordinal = ? AND state = ?',
                    [$rec['state'], isset($rec['version']) ? $rec['version'] : null, self::now(),
                     $runId, $rec['ordinal'], self::REC_PENDING]);
            }
            // Counters last, and NOT gated on affected(): a batch that finished
            // zero records and found zero findings changes no column, reports
            // zero, and would roll itself back for having nothing to say. The
            // fence above already decided whether this transaction may commit -
            // asking twice, with a weaker question, only adds a way to be wrong.
            $done = count(isset($batch['records']) ? $batch['records'] : []);
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
            return false;
        }
    }

    private function insertFinding(array $f)
    {
        $this->db->exec('INSERT INTO ' . Schema::table('finding') . '
            (generation_id, finding_identity, valid_from_seq, active_slot, record_hash,
             record_id_bin, event_id, arm_id, instance, host_form, field, rule_source_id,
             rule_revision, rule_ord, check_type, reason_code, reason_bits, severity, dag_key,
             status_key, value_bin, value_len, value_fingerprint, value_truncated, value_binary,
             value_expires_at)
            VALUES (?,?,?,1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $f['generation_id'], $f['identity'], $f['seq'], $f['record_hash'], $f['record_id_bin'],
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

    public function aggregates($runId)
    {
        return $this->db->select('SELECT kind, axis1, axis2, cnt, samples, blocks_coverage
            FROM ' . Schema::table('scan_aggregate') . ' WHERE run_id = ? ORDER BY kind, axis1',
            [$runId]);
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
