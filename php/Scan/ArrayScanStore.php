<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * The store, in memory.
 *
 * NOT A MOCK OF SqlScanStore — an independent implementation of the same
 * contract. That distinction is the point. A mock returns what the test told it
 * to and therefore proves only that the test agrees with itself; two independent
 * implementations run against ONE shared assertion set disagree wherever the
 * contract is ambiguous, which is where the bugs live. It is the same technique
 * this repository already uses for the PHP and JavaScript rule engines.
 *
 * WHAT IT IS FOR. The fast suite runs the contract here in milliseconds, so
 * every developer runs it on every change; the database matrix runs the same
 * contract against real InnoDB on four servers, so the invariants that need a
 * server are checked where they can be. Neither substitutes for the other, and
 * a behaviour asserted only here is explicitly NOT proved for production.
 *
 * WHAT IT CANNOT PROVE, and must not be read as proving: the concurrency
 * invariants. Single-process PHP has no second connection, so "the engine
 * refuses the second active run" is here a matter of this class checking an
 * array. That check is a description of the intended behaviour, not evidence of
 * it. The evidence is in tests/mysql/run.php.
 */
final class ArrayScanStore implements ScanStore
{
    private $runs = [];        // run_id => row
    private $records = [];     // run_id => [ordinal => row]
    private $findings = [];    // list
    private $candidates = [];  // uniqueness candidates, keyed as the UNIQUE index is
    private $slots = [];       // slot_no => row
    private $aggregates = [];  // run_id => list
    private $audits = [];
    private $nextRun = 1;

    public function __construct($slotCount = 2)
    {
        for ($i = 1; $i <= max(1, (int) $slotCount); $i++) {
            $this->slots[$i] = ['slot_no' => $i, 'owner' => null, 'epoch' => 0,
                                'run_id' => null, 'expires_at' => null];
        }
    }

    public function startRun($pid, array $run)
    {
        foreach ($this->runs as $r) {
            // The array stand-in for the UNIQUE key. See the class docblock: this
            // DESCRIBES the invariant, it does not evidence it.
            if ((int) $r['project_id'] === (int) $pid && $r['active_slot'] === 1) {
                return ['ok' => false, 'busy' => true, 'run' => null,
                        'why' => 'a validation scan is already running for this project'];
            }
        }
        $id = $this->nextRun++;
        $this->runs[$id] = array_merge([
            'run_id' => $id, 'project_id' => (int) $pid, 'scope_dag' => null,
            'phase' => 'planning', 'terminal' => null, 'coverage' => ScanOutcome::COV_PARTIAL,
            'detail' => ScanOutcome::DETAIL_COMPLETE, 'values_state' => 'none',
            'policy_revision' => 1, 'fingerprint' => str_repeat('0', 64),
            'manifest_total' => 0, 'manifest_done' => 0, 'cursor_ordinal' => 0,
            'lease_epoch' => 0, 'generation_id' => 1, 'created_by' => '',
            'detail_rows' => 0, 'detail_bytes' => 0, 'active_slot' => 1,
            'cancel_requested_at' => null,
            // Reconciliation state. Present from the start so progressState()
            // reads the same shape on a run that has not reached catch-up as on
            // one that has - an absent key and a null one are the same value
            // here, and only one of them is safe to read.
            'fence_open' => null, 'fence_target' => null, 'catchup_cursor' => null,
            'catchup_round' => 0, 'catchup_dirty' => 0, 'rollup_cursor' => 0,
        ], array_intersect_key($run, array_flip(
            ['scope_dag', 'created_by', 'generation_id', 'policy_revision', 'fingerprint',
             'fence_open'])));
        $this->records[$id] = [];
        return ['ok' => true, 'busy' => false, 'run' => $this->runs[$id], 'why' => null];
    }

    public function run($pid, $runId)
    {
        $r = isset($this->runs[$runId]) ? $this->runs[$runId] : null;
        // Bound to the project: a locator that resolves across projects is an
        // authorisation bug wearing a lookup's clothes.
        if ($r === null || (int) $r['project_id'] !== (int) $pid) return null;
        return $r;
    }

    public function writeManifest($runId, array $records)
    {
        $this->appendManifest($runId, $records);
        $n = $this->freezeManifest($runId);
        return $n === false ? 0 : $n;
    }

    public function appendManifest($runId, array $records)
    {
        if (!isset($this->runs[$runId])) return 0;
        if ($this->runs[$runId]['phase'] !== ScanPhase::PLANNING) return 0;
        $ord = 0;
        $seen = [];
        if (isset($this->records[$runId])) {
            foreach ($this->records[$runId] as $rec) {
                if ($rec['ordinal'] > $ord) $ord = $rec['ordinal'];
                $seen[$rec['hash']] = true;
            }
        }
        $added = 0;
        foreach ($records as $rec) {
            $ord++;
            // The array stand-in for UNIQUE (run_id, record_hash). The record
            // walk re-reads its page boundary, so the same record can be offered
            // twice and must land once.
            if (isset($seen[$rec['hash']])) continue;
            $seen[$rec['hash']] = true;
            $this->records[$runId][$ord] = [
                'ordinal' => $ord, 'id_bin' => $rec['id_bin'], 'hash' => $rec['hash'],
                'dag' => isset($rec['dag']) ? $rec['dag'] : null,
                'state' => self::REC_PENDING, 'attempts' => 0, 'version' => null,
                'claimed_at' => null,
            ];
            $added++;
        }
        return $added;
    }

    public function freezeManifest($runId)
    {
        if (!isset($this->runs[$runId])) return false;
        if ($this->runs[$runId]['phase'] !== ScanPhase::PLANNING) return false;
        $total = isset($this->records[$runId]) ? count($this->records[$runId]) : 0;
        $this->runs[$runId]['manifest_total'] = $total;
        $this->runs[$runId]['phase'] = ScanPhase::SCANNING;
        return $total;
    }

    public function claimPending($runId, $owner, $epoch, $limit, $staleSeconds = 900)
    {
        $r = isset($this->runs[$runId]) ? $this->runs[$runId] : null;
        // Refused and empty are DIFFERENT answers - see SqlScanStore::claim().
        // Both stores are judged by one contract, so both must draw the line in
        // the same place.
        if ($r === null || (int) $r['lease_epoch'] !== (int) $epoch) return false;
        if ($r['cancel_requested_at'] !== null || !ScanPhase::mayWork($r['phase'])) return false;
        $limit = max(1, (int) $limit);
        $cut = time() - max(1, (int) $staleSeconds);
        $out = [];
        if (!isset($this->records[$runId])) return [];
        foreach ($this->records[$runId] as $o => $rec) {
            if (count($out) >= $limit) break;
            $free = ($rec['state'] === self::REC_PENDING)
                 || ($rec['state'] === self::REC_CLAIMED
                     && $rec['claimed_at'] !== null && $rec['claimed_at'] < $cut);
            if (!$free) continue;
            $this->records[$runId][$o]['state'] = self::REC_CLAIMED;
            $this->records[$runId][$o]['claimed_at'] = time();
            $out[] = self::claimRow($rec);
        }
        return $out;
    }

    public function claim($runId, $owner, $epoch, $limit)
    {
        $r = isset($this->runs[$runId]) ? $this->runs[$runId] : null;
        if ($r === null || (int) $r['lease_epoch'] !== (int) $epoch) return false;
        if ($r['cancel_requested_at'] !== null || $r['phase'] !== 'scanning') return false;
        $limit = max(1, (int) $limit);
        $from = (int) $r['cursor_ordinal'];
        // Ordinals are not contiguous - appending a manifest in pages leaves a
        // gap wherever a re-offered record was ignored - so the cursor moves to
        // the last row actually taken rather than by a count. Advancing by a
        // count steps over live rows and strands them below the cursor forever.
        $out = [];
        $to = $from;
        if (isset($this->records[$runId])) {
            foreach ($this->records[$runId] as $o => $rec) {
                if (count($out) >= $limit) break;
                if ((int) $rec['ordinal'] <= $from) continue;
                if ($rec['state'] !== self::REC_PENDING) continue;
                $out[] = self::claimRow($rec);
                if ((int) $rec['ordinal'] > $to) $to = (int) $rec['ordinal'];
            }
        }
        $this->runs[$runId]['cursor_ordinal'] = $to;
        // The cursor claim does NOT mark the rows, exactly as the SQL store
        // does not: the advancing cursor is what keeps two workers apart there,
        // and marking would only add a second mechanism to disagree with.
        return $out;
    }

    public function commitBatch($runId, $owner, $epoch, $expectCursor, array $batch)
    {
        // Both stores name WHICH fence refused - one contract, one set of
        // words. "Cancelled or taken over" covered three causes and told a
        // pilot nothing about which one it had hit.
        $r = isset($this->runs[$runId]) ? $this->runs[$runId] : null;
        if ($r === null) {
            return 'this scan no longer exists, so nothing from these records was kept';
        }
        if ($r['cancel_requested_at'] !== null) {
            return 'this scan was stopped while these records were being examined, so nothing '
                 . 'from them was kept';
        }
        if ((int) $r['lease_epoch'] !== (int) $epoch) {
            return 'another worker took over this scan while these records were being examined, '
                 . 'so nothing from them was kept; they will be examined again';
        }
        foreach (isset($batch['findings']) ? $batch['findings'] : [] as $f) {
            $this->findings[] = $f;
        }
        // In the same commit as the findings, for the reason in SqlScanStore:
        // a candidate that outlived a rolled-back batch would make a record a
        // duplicate of a reading that was discarded.
        foreach (isset($batch['candidates']) ? $batch['candidates'] : [] as $c) {
            $k = $c['group_hmac'] . '|' . $c['record_hash'] . '|' . $c['field'] . '|'
               . (isset($c['event_id']) ? $c['event_id'] : '') . '|'
               . (isset($c['instance']) ? $c['instance'] : 1);
            $this->candidates[$k] = $c;
        }
        $applied = 0;
        foreach (isset($batch['records']) ? $batch['records'] : [] as $rec) {
            $o = $rec['ordinal'];
            if (!isset($this->records[$runId][$o])) continue;
            // Not-yet-terminal rather than pending: a straggler taken by
            // claimPending() is CLAIMED and must still be committable, while a
            // terminal row is never rewritten.
            if ($this->records[$runId][$o]['state'] >= self::REC_DONE) continue;
            $this->records[$runId][$o]['state'] = $rec['state'];
            $this->records[$runId][$o]['attempts']++;
            // The source version this record was examined AT. Catch-up compares
            // it against the change log to decide whether an edit is already
            // inside the reading we hold; a store that dropped it would requeue
            // every record the log mentions, on every run.
            if (array_key_exists('version', $rec)) {
                $this->records[$runId][$o]['version'] = $rec['version'];
            }
            // Terminal rows only: a requeue changes the row without finishing it.
            if ((int) $rec['state'] >= self::REC_DONE) $applied++;
        }
        $this->runs[$runId]['manifest_done'] += $applied;
        $this->runs[$runId]['detail_rows'] += count(isset($batch['findings']) ? $batch['findings'] : []);
        $this->runs[$runId]['detail_bytes'] += isset($batch['bytes']) ? (int) $batch['bytes'] : 0;
        return true;
    }

    /**
     * The four keys a worker gets, and only those.
     *
     * The SQL store selects four columns; returning the whole in-memory row
     * here would let a test lean on a field production never sends, and the
     * shared contract would pass against a shape only one implementation has.
     */
    private static function claimRow(array $rec)
    {
        return ['ordinal' => $rec['ordinal'], 'id_bin' => $rec['id_bin'],
                'hash' => $rec['hash'], 'dag' => $rec['dag'],
                'attempts' => (int) $rec['attempts'], 'version' => $rec['version']];
    }

    /** Uniqueness candidates written so far. For assertions, not for production. */
    public function candidates()
    {
        return array_values($this->candidates);
    }

    /** A predicate over states, exactly as the SQL store computes it. */
    /**
     * Hand claimed rows back. See SqlScanStore::releaseClaims() for why: without
     * it, a rolled-back batch and a phase that refuses to advance over
     * unexamined records combine into a deadlock.
     */
    public function releaseClaims($runId, $epoch, array $ordinals)
    {
        $r = isset($this->runs[$runId]) ? $this->runs[$runId] : null;
        if ($r === null || (int) $r['lease_epoch'] !== (int) $epoch) return 0;
        if (!$ordinals || !isset($this->records[$runId])) return 0;
        $n = 0;
        foreach ($this->records[$runId] as $o => $rec) {
            if (!in_array((int) $rec['ordinal'], array_map('intval', $ordinals), true)) continue;
            if ($rec['state'] !== self::REC_CLAIMED) continue;
            $this->records[$runId][$o]['state'] = self::REC_PENDING;
            $this->records[$runId][$o]['claimed_at'] = null;
            $n++;
        }
        $low = min(array_map('intval', $ordinals)) - 1;
        if ($low < (int) $this->runs[$runId]['cursor_ordinal']) {
            $this->runs[$runId]['cursor_ordinal'] = $low;
        }
        return $n;
    }

    public function manifestComplete($runId)
    {
        if (!isset($this->records[$runId])) return false;
        foreach ($this->records[$runId] as $rec) {
            if ($rec['state'] < self::REC_DONE) return false;
        }
        return true;
    }

    public function advancePhase($runId, $epoch, $to)
    {
        $r = isset($this->runs[$runId]) ? $this->runs[$runId] : null;
        if ($r === null || (int) $r['lease_epoch'] !== (int) $epoch) return false;
        if ($r['cancel_requested_at'] !== null) return false;
        if (!ScanPhase::may($r['phase'], $to)) return false;
        $this->runs[$runId]['phase'] = $to;
        $this->runs[$runId]['cursor_ordinal'] = 0;
        return true;
    }

    public function finish($runId, array $outcome)
    {
        if (!isset($this->runs[$runId]) || $this->runs[$runId]['active_slot'] !== 1) return false;
        $this->runs[$runId]['phase'] = 'terminal';
        $this->runs[$runId]['terminal'] = $outcome['terminal'];
        $this->runs[$runId]['coverage'] = $outcome['coverage'];
        $this->runs[$runId]['detail'] = $outcome['detail'];
        $this->runs[$runId]['active_slot'] = null;
        return true;
    }

    public function cancel($pid, $runId, $actor)
    {
        $r = $this->run($pid, $runId);
        if ($r === null || $r['active_slot'] !== 1) return false;
        $this->runs[$runId]['cancel_requested_at'] = gmdate('Y-m-d H:i:s');
        $this->runs[$runId]['phase'] = 'cancelling';
        $this->runs[$runId]['lease_epoch']++;
        $this->audit($pid, $runId, 'cancel', $actor, null);
        return true;
    }

    public function leaseSlot($owner, $runId, $ttlSeconds)
    {
        $now = time();
        foreach ($this->slots as $no => $s) {
            $free = ($s['owner'] === null)
                 || ($s['expires_at'] !== null && $s['expires_at'] < $now);
            if (!$free) continue;
            $this->slots[$no]['owner'] = $owner;
            $this->slots[$no]['run_id'] = $runId;
            $this->slots[$no]['epoch']++;
            $this->slots[$no]['expires_at'] = $now + (int) $ttlSeconds;
            return ['slot_no' => $no, 'epoch' => $this->slots[$no]['epoch']];
        }
        return null;
    }

    public function releaseSlot($slotNo, $owner, $epoch)
    {
        if (!isset($this->slots[$slotNo])) return false;
        $s = $this->slots[$slotNo];
        if ($s['owner'] !== $owner || (int) $s['epoch'] !== (int) $epoch) return false;
        $this->slots[$slotNo]['owner'] = null;
        $this->slots[$slotNo]['run_id'] = null;
        $this->slots[$slotNo]['expires_at'] = null;
        return true;
    }

    public function findings($generationId, array $filter, $afterId, $limit)
    {
        $out = [];
        $i = 0;
        foreach ($this->findings as $f) {
            $i++;
            if ($i <= (int) $afterId) continue;
            if ((int) $f['generation_id'] !== (int) $generationId) continue;
            $skip = false;
            foreach (['host_form', 'reason_code', 'check_type'] as $k) {
                if (isset($filter[$k]) && $filter[$k] !== ''
                    && (!isset($f[$k]) || $f[$k] !== $filter[$k])) { $skip = true; break; }
            }
            if ($skip) continue;
            $out[] = $f;
            if (count($out) >= max(1, min(100, (int) $limit))) break;
        }
        return $out;
    }

    public function aggregates($runId)
    {
        return isset($this->aggregates[$runId]) ? $this->aggregates[$runId] : [];
    }

    public function expireValues($now)
    {
        $n = 0;
        foreach ($this->findings as $i => $f) {
            if (empty($f['value_expires_at']) || $f['value_expires_at'] > $now) continue;
            // The VALUE goes, the finding stays. A report that shrinks as it
            // ages reads as the project having improved.
            $this->findings[$i]['value_bin'] = null;
            $this->findings[$i]['value_expires_at'] = null;
            $n++;
        }
        return $n;
    }

    public function purgeRuns($pid, $olderThan)
    {
        $n = 0;
        foreach ($this->runs as $id => $r) {
            if ((int) $r['project_id'] !== (int) $pid || $r['active_slot'] === 1) continue;
            unset($this->runs[$id], $this->records[$id]);
            $n++;
        }
        return $n;
    }

    public function audit($pid, $runId, $event, $actor, $detail)
    {
        $this->audits[] = ['pid' => $pid, 'run_id' => $runId, 'event' => $event,
                           'actor' => $actor, 'detail' => $detail];
    }

    // -- reconciliation ------------------------------------------------------

    public function reconcileAdd($runId, $epoch, array $records)
    {
        if (!isset($this->runs[$runId])) return 0;
        $r = $this->runs[$runId];
        if ($r['phase'] !== ScanPhase::CATCH_UP || (int) $r['lease_epoch'] !== (int) $epoch) {
            return 0;
        }
        $have = [];
        foreach ($this->records[$runId] as $row) $have[$row['id_bin']] = true;
        $ord = 0;
        foreach ($this->records[$runId] as $o => $row) $ord = max($ord, (int) $o);
        $added = 0;
        foreach ($records as $rec) {
            if (isset($have[$rec['id_bin']])) continue;
            $ord++;
            $this->records[$runId][$ord] = ['ordinal' => $ord, 'id_bin' => $rec['id_bin'],
                'hash' => $rec['hash'], 'dag' => isset($rec['dag']) ? $rec['dag'] : null,
                'state' => ScanStore::REC_PENDING, 'attempts' => 0, 'version' => null,
                'owner' => null];
            $have[$rec['id_bin']] = true;
            $added++;
        }
        $this->runs[$runId]['manifest_total'] = count($this->records[$runId]);
        return $added;
    }

    public function requeue($runId, $epoch, array $recordIds)
    {
        return $this->reState($runId, $epoch, $recordIds, ScanStore::REC_PENDING, true);
    }

    public function tombstone($runId, $epoch, array $recordIds)
    {
        return $this->reState($runId, $epoch, $recordIds, ScanStore::REC_TOMBSTONE, false);
    }

    private function reState($runId, $epoch, array $recordIds, $state, $clearScan)
    {
        if (!isset($this->runs[$runId])) return 0;
        $r = $this->runs[$runId];
        if ($r['phase'] !== ScanPhase::CATCH_UP || (int) $r['lease_epoch'] !== (int) $epoch) {
            return 0;
        }
        $want = array_fill_keys(array_map('strval', $recordIds), true);
        $n = 0;
        foreach ($this->records[$runId] as $o => $row) {
            if (!isset($want[(string) $row['id_bin']])) continue;
            if ((int) $row['state'] === (int) $state) continue;
            $this->records[$runId][$o]['state'] = $state;
            // Attempts survive on purpose: see the SQL store's note. A record
            // being edited constantly must still reach its limit.
            if ($clearScan) $this->records[$runId][$o]['version'] = null;
            $this->records[$runId][$o]['owner'] = null;
            $n++;
        }
        $done = 0;
        foreach ($this->records[$runId] as $row) {
            if ((int) $row['state'] >= ScanStore::REC_DONE) $done++;
        }
        $this->runs[$runId]['manifest_done'] = $done;
        return $n;
    }

    public function recordStates($runId)
    {
        $out = [];
        if (!isset($this->records[$runId])) return $out;
        foreach ($this->records[$runId] as $row) {
            $k = (int) $row['state'];
            $out[$k] = (isset($out[$k]) ? $out[$k] : 0) + 1;
        }
        return $out;
    }

    public function scannedVersions($runId, array $recordIds)
    {
        $want = array_fill_keys(array_map('strval', $recordIds), true);
        $out = [];
        if (!isset($this->records[$runId])) return $out;
        foreach ($this->records[$runId] as $row) {
            if (!isset($want[(string) $row['id_bin']])) continue;
            $out[(string) $row['id_bin']] = ['version' => $row['version'],
                                             'state' => (int) $row['state']];
        }
        return $out;
    }

    public function progressState($runId)
    {
        if (!isset($this->runs[$runId])) return null;
        $r = $this->runs[$runId];
        $get = function ($k, $d) use ($r) { return array_key_exists($k, $r) ? $r[$k] : $d; };
        return ['catchupCursor' => $get('catchup_cursor', null),
                'catchupRound' => (int) $get('catchup_round', 0),
                'catchupDirty' => (int) $get('catchup_dirty', 0),
                'rollupCursor' => (int) $get('rollup_cursor', 0),
                'fenceOpen' => $get('fence_open', null),
                'fenceTarget' => $get('fence_target', null)];
    }

    public function setProgressState($runId, $epoch, array $st)
    {
        if (!isset($this->runs[$runId])) return false;
        if ((int) $this->runs[$runId]['lease_epoch'] !== (int) $epoch) return false;
        // Mapped to the STORAGE names, not written through as they arrive. The
        // caller speaks in the contract's names and the run row is read back by
        // ScanPromotion in the column names, so a store that kept both would be
        // a store where a fence written by one method is invisible to the other
        // - which is exactly what happened before this map existed.
        $map = ['catchupCursor' => 'catchup_cursor', 'catchupRound' => 'catchup_round',
                'catchupDirty' => 'catchup_dirty', 'rollupCursor' => 'rollup_cursor',
                'fenceTarget' => 'fence_target'];
        foreach ($st as $k => $v) {
            if (!isset($map[$k])) continue;
            $this->runs[$runId][$map[$k]] = $v;
        }
        return true;
    }

    public function addAggregate($runId, $kind, $axis1, $axis2, $cnt, $blocks = 0, $samples = null)
    {
        // Coerced to strings exactly as the column is declared NOT NULL. A store
        // that kept null here would merge rows the server keeps apart.
        $axis1 = (string) $axis1;
        $axis2 = (string) $axis2;
        $k = $kind . '|' . $axis1 . '|' . $axis2;
        if (!isset($this->aggregates[$runId][$k])) {
            $this->aggregates[$runId][$k] = ['kind' => $kind, 'axis1' => $axis1, 'axis2' => $axis2,
                                             'cnt' => 0, 'blocks' => 0, 'samples' => $samples];
        }
        $this->aggregates[$runId][$k]['cnt'] += (int) $cnt;
        $this->aggregates[$runId][$k]['blocks'] = max($this->aggregates[$runId][$k]['blocks'],
                                                      $blocks ? 1 : 0);
        return true;
    }

    public function blockingAggregates($runId)
    {
        $n = 0;
        foreach ($this->aggregates($runId) as $a) {
            if (!empty($a['blocks'])) $n += (int) $a['cnt'];
        }
        return $n;
    }

    /** Test accessors. Deliberately not part of ScanStore. */
    public function allFindings() { return $this->findings; }
    public function allAudits()   { return $this->audits; }
    public function recordState($runId, $ordinal)
    {
        return isset($this->records[$runId][$ordinal])
             ? $this->records[$runId][$ordinal]['state'] : null;
    }
}
