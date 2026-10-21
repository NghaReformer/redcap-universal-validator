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
    private $findings = [];    // list, in finding_id order - see stored()
    private $candidates = [];  // uniqueness candidates, keyed as the UNIQUE index is
    private $aggregates = [];  // run_id => list
    private $audits = [];
    private $nextRun = 1;

    /**
     * The stand-in for uv_finding's AUTO_INCREMENT (M13).
     *
     * Findings had no id here at all, so findings() paged by POSITION in this
     * list: page two's cursor was a count of rows walked, closed and other
     * projects' rows included, and no caller holding a row could ask for the
     * next page from it. The SQL store pages by finding_id, so the two could
     * not even be asked the same question.
     */
    private $nextFinding = 1;

    /** Per-project generation counters, the stand-in for uv_project_seq. */
    private $seq = [];

    /** run_id => the stand-in for its uv_scan_plan row; see plan(). */
    private $plans = [];

    /** run_id => {max:int, hashes:[hash => true]} while the run plans; see appendManifest(). */
    private $manifestIndex = [];

    /** @var callable|null  seconds as a float; microtime(true) when null. See claimPending(). */
    private $clock;

    /**
     * @param callable|null $clock what claimPending() reads the time from, as
     *        SqlScanStore's does, so a test can step it rather than wait.
     */
    public function __construct($clock = null)
    {
        $this->clock = is_callable($clock) ? $clock : null;
    }

    private function clock()
    {
        return $this->clock === null ? microtime(true) : (float) call_user_func($this->clock);
    }

    public function startRun($pid, array $run)
    {
        foreach ($this->runs as $r) {
            // The array stand-in for the UNIQUE key. See the class docblock: this
            // DESCRIBES the invariant, it does not evidence it.
            if ((int) $r['project_id'] === (int) $pid && $r['active_slot'] === 1) {
                return ['ok' => false, 'busy' => true, 'run' => null,
                        // The same one sentence the SQL store answers with.
                        // Two stores writing their own copy is how the wording
                        // drifted from the helper that exists to fix it.
                        'why' => self::BUSY_WHY];
            }
        }
        // THE SAME PER-PROJECT SEQUENCE THE REAL STORE ALLOCATES. It matters
        // that this store models it rather than defaulting to 1: the constant
        // generation is the defect the whole release turns on, and a stand-in
        // that hands out 1 forever would keep every mocked test green over it
        // exactly as it did before.
        if (!isset($this->seq[(int) $pid])) $this->seq[(int) $pid] = 0;
        $seq = ++$this->seq[(int) $pid];
        $gen = (isset($run['run_kind']) && $run['run_kind'] === 'incremental'
                && isset($run['baseline_generation']) && $run['baseline_generation'] !== null)
            ? (int) $run['baseline_generation'] : $seq;

        $id = $this->nextRun++;
        $this->runs[$id] = array_merge([
            'run_id' => $id, 'project_id' => (int) $pid, 'scope_dag' => null,
            'phase' => 'planning', 'terminal' => null, 'coverage' => ScanOutcome::COV_PARTIAL,
            'detail' => ScanOutcome::DETAIL_COMPLETE, 'values_state' => 'none',
            'policy_revision' => 1, 'fingerprint' => str_repeat('0', 64),
            'manifest_total' => 0, 'manifest_done' => 0, 'cursor_ordinal' => 0,
            'lease_epoch' => 0, 'generation_id' => $gen, 'run_seq' => $seq,
            // The planning lease (claimPlan()). Not in RUN_COLUMNS, as the SQL
            // store's columns are not.
            'lease_owner' => null, 'lease_expires_at' => null,
            'supersede_cursor' => 0, 'created_by' => '',
            'detail_rows' => 0, 'detail_bytes' => 0, 'active_slot' => 1,
            'cancel_requested_at' => null,
            // Reconciliation state. Present from the start so progressState()
            // reads the same shape on a run that has not reached catch-up as on
            // one that has - an absent key and a null one are the same value
            // here, and only one of them is safe to read.
            'fence_open' => null, 'fence_target' => null, 'catchup_cursor' => null,
            'catchup_round' => 0, 'catchup_dirty' => 0, 'rollup_cursor' => 0,
            // Written in exactly two places, both real progress - see
            // SqlScanStore::commitBatch. NULL until one of them happens.
            'progress_at' => null,
            // The rest of ScanStore::RUN_COLUMNS, with the defaults the schema
            // gives them. `clean` is NULL, not 0: nothing has been decided.
            'terminal_reason' => null,
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
            'clean' => null, 'gap_count' => 0, 'rule_problem_count' => 0, 'not_examined' => 0,
            'policy_json' => '{}',
        ], array_intersect_key($run, array_flip(
            ['scope_dag', 'created_by', 'generation_id', 'policy_revision', 'fingerprint',
             'fence_open', 'policy_json'])));
        $this->records[$id] = [];
        return ['ok' => true, 'busy' => false, 'run' => $this->run($pid, $id), 'why' => null];
    }

    public function run($pid, $runId)
    {
        $r = isset($this->runs[$runId]) ? $this->runs[$runId] : null;
        // Bound to the project: a locator that resolves across projects is an
        // authorisation bug wearing a lookup's clothes.
        if ($r === null || (int) $r['project_id'] !== (int) $pid) return null;
        // EXACTLY THE PROJECTION THE SQL STORE RETURNS, in its order. This used
        // to hand back the whole internal row, so a caller could come to depend
        // on a key - active_slot, a catch-up cursor - that the real store never
        // returns, and only a live server would say so.
        $out = [];
        foreach (self::RUN_COLUMNS as $k) {
            $out[$k] = array_key_exists($k, $r) ? $r[$k] : null;
        }
        return $out;
    }

    public function writeManifest($runId, array $records)
    {
        $this->appendManifest($runId, $records);
        // The whole list is in hand, so the source has said it is done - the
        // fact the freeze is gated on, as SqlScanStore::markPlanDone() writes it.
        if (isset($this->runs[$runId])) $this->plan($runId)['done'] = 1;
        $n = $this->freezeManifest($runId);
        return $n === false ? 0 : $n;
    }

    /** A uv_scan_plan row as the schema defaults it. */
    const NEW_PLAN = ['cursor' => null, 'carry' => null, 'pages' => 0, 'listed' => 0,
                      'outOfScope' => 0, 'attempts' => 0, 'done' => 0];

    /** The stand-in for a uv_scan_plan row, created as claimPlan() creates it. */
    private function &plan($runId)
    {
        if (!isset($this->plans[$runId])) $this->plans[$runId] = self::NEW_PLAN;
        return $this->plans[$runId];
    }

    public function appendManifest($runId, array $records, ?array $plan = null)
    {
        if (!$records && $plan === null) return 0;
        if (!isset($this->runs[$runId])) return false;
        $r = $this->runs[$runId];
        if ($r['phase'] !== ScanPhase::PLANNING) return false;
        if ($plan !== null) {
            // The SQL store's planFence(): epoch, no stop asked for, this
            // owner's lease - and the plan row, which a leased append that
            // finds missing does not write (review R5-9).
            $owner = isset($plan['owner']) ? (string) $plan['owner'] : '';
            if ($owner === '' || !isset($plan['epoch']) || (int) $r['lease_epoch'] !== (int) $plan['epoch']
                    || $r['cancel_requested_at'] !== null
                    || $r['lease_owner'] === null || (string) $r['lease_owner'] !== $owner
                    || !isset($this->plans[$runId])) {
                return false;
            }
        }
        // ONE PASS OVER THE MANIFEST PER RUN, NOT PER PAGE. This rebuilt the
        // ordinal and the hash set from every row on every call, so a plan
        // across pages cost O(rows x pages) - quadratic in the project, in the
        // store the fast suite plans its larger fixtures through. The index is
        // built once and kept while the run plans; nothing else adds rows then.
        if (!isset($this->manifestIndex[$runId])) {
            $ix = ['max' => 0, 'hashes' => []];
            foreach ($this->records[$runId] as $rec) {
                if ($rec['ordinal'] > $ix['max']) $ix['max'] = $rec['ordinal'];
                $ix['hashes'][$rec['hash']] = true;
            }
            $this->manifestIndex[$runId] = $ix;
        }
        $ix =& $this->manifestIndex[$runId];
        $added = 0;
        foreach ($records as $rec) {
            $ord = ++$ix['max'];
            // The array stand-in for UNIQUE (run_id, record_hash). The record
            // walk re-reads its page boundary, so the same record can be offered
            // twice and must land once. The ordinal is spent either way, as the
            // SQL store spends it.
            if (isset($ix['hashes'][$rec['hash']])) continue;
            $ix['hashes'][$rec['hash']] = true;
            $this->records[$runId][$ord] = [
                'ordinal' => $ord, 'id_bin' => $rec['id_bin'], 'hash' => $rec['hash'],
                'dag' => isset($rec['dag']) ? $rec['dag'] : null,
                'state' => self::REC_PENDING, 'attempts' => 0, 'version' => null,
                'claimed_at' => null, 'claim_owner' => null, 'claim_seq' => 0,
            ];
            $added++;
        }
        unset($ix);
        $now = gmdate('Y-m-d H:i:s');
        if ($plan !== null) {
            $p =& $this->plan($runId);
            $p['cursor'] = isset($plan['cursor']) ? (string) $plan['cursor'] : null;
            $p['carry'] = isset($plan['carry']) ? (string) $plan['carry'] : null;
            $p['pages']++;
            $p['listed'] = max(0, (int) (isset($plan['listed']) ? $plan['listed'] : 0));
            $p['outOfScope'] = max(0, (int) (isset($plan['outOfScope']) ? $plan['outOfScope'] : 0));
            $p['attempts'] = 0;
            $p['done'] = empty($plan['done']) ? 0 : 1;
            unset($p);
            if (!empty($plan['release'])) {
                $this->runs[$runId]['lease_owner'] = null;
                $this->runs[$runId]['lease_expires_at'] = null;
            } else {
                $this->runs[$runId]['lease_expires_at'] = gmdate('Y-m-d H:i:s',
                    time() + (isset($plan['leaseSeconds']) ? max(1, (int) $plan['leaseSeconds']) : 300));
            }
        }
        // The heartbeat, as the SQL store writes it.
        $this->runs[$runId]['updated_at'] = $now;
        $this->runs[$runId]['progress_at'] = $now;
        return $added;
    }

    public function claimPlan($runId, $owner, $epoch, $leaseSeconds, $maxAttempts)
    {
        $owner = (string) $owner;
        if ($owner === '' || !isset($this->runs[$runId])) return false;
        $r = $this->runs[$runId];
        if ($r['phase'] !== ScanPhase::PLANNING || (int) $r['lease_epoch'] !== (int) $epoch
                || $r['cancel_requested_at'] !== null) {
            return false;
        }
        $now = gmdate('Y-m-d H:i:s');
        // READ WITHOUT WRITING. SqlScanStore writes the plan row only for a
        // claim that takes the lease, and a stand-in written by a `held`
        // answer would let a later leased append find a row the SQL store
        // does not have (review R5-9).
        $p = isset($this->plans[$runId]) ? $this->plans[$runId] : self::NEW_PLAN;
        $state = ['state' => 'ok', 'cursor' => $p['cursor'], 'carry' => $p['carry'],
                  'pages' => $p['pages'], 'listed' => $p['listed'],
                  'outOfScope' => $p['outOfScope'], 'attempts' => $p['attempts']];
        $held = $r['lease_owner'] !== null && (string) $r['lease_owner'] !== $owner
                && $r['lease_expires_at'] !== null && strcmp((string) $r['lease_expires_at'], $now) > 0;
        if ((int) $p['done'] === 1) {
            $state['state'] = 'done';
        } elseif ($held) {
            $state['state'] = 'held';
        } elseif ($p['attempts'] >= max(1, (int) $maxAttempts)) {
            $state['state'] = 'exhausted';
        }
        if ($state['state'] !== 'ok') return $state;
        $p =& $this->plan($runId);
        $p['attempts'] = min(254, $p['attempts'] + 1);
        $state['attempts'] = $p['attempts'];
        unset($p);
        $this->runs[$runId]['lease_owner'] = $owner;
        $this->runs[$runId]['lease_expires_at'] = gmdate('Y-m-d H:i:s', time() + max(1, (int) $leaseSeconds));
        $this->runs[$runId]['updated_at'] = $now;
        return $state;
    }

    public function releasePlan($runId, $owner)
    {
        if (!isset($this->runs[$runId])) return false;
        $r = $this->runs[$runId];
        if ($r['phase'] !== ScanPhase::PLANNING || $r['lease_owner'] === null
                || (string) $r['lease_owner'] !== (string) $owner) {
            return false;
        }
        $this->runs[$runId]['lease_owner'] = null;
        $this->runs[$runId]['lease_expires_at'] = null;
        $this->runs[$runId]['updated_at'] = gmdate('Y-m-d H:i:s');
        return true;
    }

    public function freezeManifest($runId)
    {
        if (!isset($this->runs[$runId])) return false;
        if ($this->runs[$runId]['phase'] !== ScanPhase::PLANNING) return false;
        // The gate: the source said done, as SqlScanStore reads plan_done.
        if (!isset($this->plans[$runId]) || (int) $this->plans[$runId]['done'] !== 1) return false;
        $total = isset($this->records[$runId]) ? count($this->records[$runId]) : 0;
        $this->runs[$runId]['manifest_total'] = $total;
        $this->runs[$runId]['phase'] = ScanPhase::SCANNING;
        // The second of the two places progress_at is written.
        $this->runs[$runId]['progress_at'] = gmdate('Y-m-d H:i:s');
        unset($this->manifestIndex[$runId]);
        return $total;
    }

    public function claimPending($runId, $owner, $epoch, $limit, $staleSeconds = 900,
                                 $maxAttempts = null, $exhausted = self::REC_UNREADABLE,
                                 &$retired = null)
    {
        $retired = 0;
        $r = isset($this->runs[$runId]) ? $this->runs[$runId] : null;
        // Refused and empty are DIFFERENT answers - see SqlScanStore::claim().
        // Both stores are judged by one contract, so both must draw the line in
        // the same place.
        if ($r === null || (int) $r['lease_epoch'] !== (int) $epoch) return false;
        if ($r['cancel_requested_at'] !== null || !ScanPhase::mayWork($r['phase'])) return false;
        $limit = max(1, (int) $limit);
        $token = $this->claimToken($runId);
        if ($token < 1) return false;
        $cut = time() - max(1, (int) $staleSeconds);
        $out = [];
        if (!isset($this->records[$runId])) return [];
        $isFree = function (array $rec) use ($cut) {
            return ($rec['state'] === self::REC_PENDING)
                || ($rec['state'] === self::REC_CLAIMED
                    && $rec['claimed_at'] !== null && $rec['claimed_at'] < $cut);
        };
        // WINDOW AFTER WINDOW, exactly as the SQL store's LIMIT walks them
        // (review R2S-5): the next $limit free rows in ordinal order, and the
        // next window only when every row of a full one was retired. This
        // store used to retire any number of rows in one call while the SQL
        // store stopped after one window, so the fast suite could not see the
        // call that answered [] with free rows still waiting behind it.
        //
        // And bounded the same two ways (review R3S-1): each window starts
        // after the last row of the one before, and a later window starts only
        // while the call is younger than SWEEP_SECONDS.
        $began = $this->clock();
        $after = 0;
        for ($window = 0; $window < self::SWEEP_WINDOWS; $window++) {
            if ($window > 0 && $this->clock() - $began >= self::SWEEP_SECONDS) break;
            $rows = [];
            foreach ($this->records[$runId] as $o => $rec) {
                if (count($rows) >= $limit) break;
                if ((int) $rec['ordinal'] <= $after) continue;
                if ($isFree($rec)) $rows[] = $o;
            }
            foreach ($rows as $o) {
                $rec = $this->records[$runId][$o];
                // ABANDONED: free, still carrying a claim, AND that claim stale -
                // a fresh stamp on a PENDING row is a live scanning worker's
                // (review R2S-1). See SqlScanStore.
                $abandoned = $maxAttempts !== null && (int) $rec['claim_seq'] !== 0
                          && $rec['claimed_at'] !== null && $rec['claimed_at'] < $cut;
                if ($abandoned) {
                    if ($out) break;                 // it goes alone, in a call of its own
                    $now = min(254, (int) $rec['attempts'] + 1);
                    $this->records[$runId][$o]['attempts'] = $now;
                    if ($now >= max(1, (int) $maxAttempts)) {
                        $this->records[$runId][$o]['state'] = (int) $exhausted;
                        $this->records[$runId][$o]['claim_owner'] = null;
                        $this->records[$runId][$o]['claim_seq'] = 0;
                        $this->records[$runId][$o]['claimed_at'] = null;
                        $retired++;
                        continue;
                    }
                }
                $this->records[$runId][$o]['state'] = self::REC_CLAIMED;
                $this->records[$runId][$o]['claimed_at'] = time();
                // TAKING A STRAGGLER REPLACES ITS TOKEN, which is the whole
                // takeover fence: whoever held it before now matches nothing.
                $this->records[$runId][$o]['claim_owner'] = (string) $owner;
                $this->records[$runId][$o]['claim_seq'] = $token;
                $out[] = self::claimRow($this->records[$runId][$o]) + ['abandoned' => $abandoned];
                if ($abandoned) break;
            }
            if ($out || count($rows) < $limit) break;
            $after = (int) $this->records[$runId][$rows[count($rows) - 1]]['ordinal'];
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
        $token = $this->claimToken($runId);
        if ($token < 1) return false;
        $out = [];
        $to = $from;
        if (isset($this->records[$runId])) {
            foreach ($this->records[$runId] as $o => $rec) {
                if (count($out) >= $limit) break;
                if ((int) $rec['ordinal'] <= $from) continue;
                if ($rec['state'] !== self::REC_PENDING) continue;
                // The token is stamped; the STATE is not. In this phase the
                // advancing cursor keeps two workers apart, and marking these
                // rows CLAIMED would hide an abandoned one from the straggler
                // sweep for a quarter of an hour. See SqlScanStore::claim().
                $this->records[$runId][$o]['claim_owner'] = (string) $owner;
                $this->records[$runId][$o]['claim_seq'] = $token;
                // WHEN, as the SQL store's updated_at says it: the straggler
                // sweep tells a live scanning claim from a dead one by its age
                // (review R2S-1), and a stamp with no time cannot be aged.
                $this->records[$runId][$o]['claimed_at'] = time();
                $out[] = self::claimRow($this->records[$runId][$o]);
                if ((int) $rec['ordinal'] > $to) $to = (int) $rec['ordinal'];
            }
        }
        $this->runs[$runId]['cursor_ordinal'] = $to;
        // The cursor claim does NOT mark the rows, exactly as the SQL store
        // does not: the advancing cursor is what keeps two workers apart there,
        // and marking would only add a second mechanism to disagree with.
        return $out;
    }

    /**
     * The stand-in for uv_project_seq, drawn on for a claim token.
     *
     * Zero means there is no such run, and the caller answers `false` to that -
     * matching SqlScanStore::claimToken(), because "there is no such run" is a
     * refusal the claim fence has always handled and not a storage failure.
     */
    private function claimToken($runId)
    {
        if (!isset($this->runs[$runId])) return 0;
        $pid = (int) $this->runs[$runId]['project_id'];
        if ($pid < 1) return 0;
        if (!isset($this->seq[$pid])) $this->seq[$pid] = 0;
        return ++$this->seq[$pid];
    }

    public function commitBatch($runId, $owner, $epoch, array $batch, &$heldCount = null,
                                &$heldOrdinals = null)
    {
        // Nothing held until the batch is kept. See ScanStore::commitBatch().
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
        // The detail budget's counts (H9), refused in the SQL store's words.
        $unstored = [];
        foreach ((isset($batch['unstored']) && is_array($batch['unstored']) ? $batch['unstored'] : [])
                 as $o => $k) {
            if (!is_int($k) && !(is_string($k) && ctype_digit($k))) {
                throw new \RuntimeException('an unstored-findings count reached the store that is '
                    . 'not a whole number; refusing to record what was found as less than it was');
            }
            if ((int) $k > 0) $unstored[(int) $o] = (int) $k;
        }

        // Shape first, and with the same words the SQL store uses: a missing
        // claim token is a programming error, and a store that quietly accepted
        // one here would let the fast suite bless a batch the real store throws
        // on. That asymmetry is the whole reason this class is not a mock.
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
        if (isset($r['terminal']) && $r['terminal'] !== null) {
            return 'this scan had already finished when these records were offered, so '
                 . 'nothing from them was kept';
        }
        if ((int) $r['lease_epoch'] !== (int) $epoch) {
            return 'another worker took over this scan while these records were being examined, '
                 . 'so nothing from them was kept; they will be examined again';
        }

        $projectId    = (int) $r['project_id'];
        $generationId = (int) $r['generation_id'];
        $runSeq       = (int) $r['run_seq'];

        // DECIDE EVERYTHING, THEN WRITE. This store has no transaction, so the
        // only way it can model one is to make no change at all until it knows
        // the whole batch will be kept.
        //
        // THIS IS NOT HOUSEKEEPING. Written the other way round - states first,
        // then the duplicate check - the fast suite reported a permanently
        // refused batch as having marked its record DONE, because the refusal
        // returned after the write and nothing undid it. The real store rolls
        // back and leaves the record claimable, so the two stores disagreed
        // about the state a project is left in by a failed write: the case the
        // whole retry cap exists for.

        // WHICH RECORDS ARE STILL OURS. Per-record, on the claim token, exactly
        // as the SQL store's UPDATE predicate decides it - a record taken over
        // between the claim and now is absent from $held and everything it
        // produced is dropped with it.
        $held = [];
        foreach ($records as $rec) {
            $o = $rec['ordinal'];
            if (!isset($this->records[$runId][$o])) continue;
            $row = $this->records[$runId][$o];
            // Not-yet-terminal rather than pending: a straggler taken by
            // claimPending() is CLAIMED and must still be committable, while a
            // terminal row is never rewritten.
            if ($row['state'] >= self::REC_DONE) continue;
            if ((string) $row['claim_owner'] !== (string) $owner) continue;
            if ((int) $row['claim_seq'] !== (int) $rec['claim']) continue;
            $held[(int) $o] = true;
        }

        // WHAT WOULD BE SUPERSEDED, decided before anything is.
        //
        // BY RECORD, not by the identities in this batch. A violation FIXED
        // between two examinations produces no finding the second time, and an
        // identity-scoped close would leave it active forever - the report would
        // show corrected data as still broken. Over HELD records only, because a
        // record we lost is one whose new evidence we are about to drop, and
        // closing its old rows would blank the report for a record still under
        // examination.
        $closing = [];
        foreach ($records as $rec) {
            if (!isset($rec['record_hash'])) continue;
            if (empty($held[(int) $rec['ordinal']])) continue;
            $st = (int) $rec['state'];
            if ($st === self::REC_DONE || $st === self::REC_TOMBSTONE) {
                $closing[$rec['record_hash']] = true;
            }
        }
        $close = [];
        foreach ($this->findings as $i => $old) {
            if (!isset($old['active_slot']) || (int) $old['active_slot'] !== 1) continue;
            if (isset($old['stage_epoch']) && $old['stage_epoch'] !== null) continue;
            if ((int) $old['project_id'] !== $projectId) continue;
            if ((int) $old['generation_id'] !== $generationId) continue;
            if (!isset($closing[$old['record_hash']])) continue;
            $close[$i] = true;
        }

        // ONE ACTIVE ROW PER IDENTITY, which in the real store is a UNIQUE key
        // and here has to be a check. A batch carrying the same identity twice
        // is refused ENTIRE, exactly as MySQL refuses it - because a store that
        // quietly kept the first and dropped the second would let a defect
        // through that costs the real one a rolled-back batch and, before the
        // retry cap was fixed, an unbounded retry loop.
        //
        // The rows this batch is about to close are excluded, because by the
        // time the inserts happen they will not be active any more. That is the
        // supersede: it is what lets a re-examined record commit the same
        // finding again.
        //
        // AND ONE STAGED ROW PER IDENTITY PER FINALIZER PASS, which is the other
        // unique key (uq_staged_identity_v2). A staged row - one carrying a
        // stage_epoch - is written with active_slot NULL by the SQL store and
        // so is not held by the active key at all; this store used to write it
        // ACTIVE, where findings() returned it and the active check counted it.
        $active = [];
        foreach ($this->findings as $i => $old) {
            if (isset($close[$i])) continue;
            if ($old['stage_epoch'] !== null) {
                $active[self::stagedKey($old)] = true;
            }
            if ($old['active_slot'] === null || (int) $old['active_slot'] !== 1) continue;
            $active[(int) $old['project_id'] . '|' . (int) $old['generation_id']
                    . '|' . bin2hex($old['identity'])] = true;
        }
        $incoming = [];
        $keep = [];
        foreach ($findings as $f) {
            // A finding belonging to a record another worker took over is
            // dropped rather than offered - offering it here would make this
            // store refuse a batch the real one commits.
            if (empty($held[(int) $f['ordinal']])) continue;
            if (!isset($f['project_id']) || (int) $f['project_id'] < 1) {
                return 'the database refused to store these findings, so nothing from these '
                     . 'records was kept: a finding reached the store with no project';
            }
            // THE SAME REFUSAL THE SQL STORE MAKES, in its words (review ST-2).
            // That store refuses rather than let a lenient server store '' for
            // a NULL; this one used to keep the finding with the key missing,
            // so the fast suite blessed a batch production refuses.
            foreach (self::FINDING_REQUIRED as $k) {
                if (!isset($f[$k])) {
                    return 'the database refused to store these findings, so nothing from these '
                         . 'records was kept: a finding reached the store with no ' . $k
                         . '; refusing to let the database invent one';
                }
            }
            $staged = isset($f['stage_epoch']) && $f['stage_epoch'] !== null;
            $k = $staged ? self::stagedKey($f)
               : (int) $f['project_id'] . '|' . (int) $f['generation_id'] . '|' . bin2hex($f['identity']);
            if (isset($active[$k]) || isset($incoming[$k])) {
                return 'the database refused to store these findings, so nothing from these '
                     . 'records was kept: Duplicate entry (value withheld) for key '
                     . ($staged ? "'uv_finding.uq_staged_identity_v2'"
                                : "'uv_finding.uq_active_identity_v2'");
            }
            $incoming[$k] = true;
            $keep[] = $f;
        }

        // -- from here nothing can be refused, so the writing begins ----------

        $applied = 0;
        foreach ($records as $rec) {
            $o = $rec['ordinal'];
            if (empty($held[(int) $o])) continue;
            $this->records[$runId][$o]['state'] = $rec['state'];
            // Saturating, as the SQL store's LEAST(attempts + 1, 254) does:
            // attempts is a TINYINT UNSIGNED there, and a store that counted
            // past 254 here would disagree with production at the exact point
            // the retry cap is decided.
            $this->records[$runId][$o]['attempts']
                = min(254, (int) $this->records[$runId][$o]['attempts'] + 1);
            $this->records[$runId][$o]['claim_owner'] = null;
            $this->records[$runId][$o]['claim_seq'] = 0;
            $this->records[$runId][$o]['claimed_at'] = null;
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
        foreach (array_keys($close) as $i) {
            $this->findings[$i]['active_slot'] = null;
            $this->findings[$i]['valid_to_seq'] = $runSeq;
        }
        foreach ($keep as $f) {
            $this->findings[] = self::stored($f, $runSeq, $this->nextFinding++);
        }
        // In the same commit as the findings, for the reason in SqlScanStore:
        // a candidate that outlived a rolled-back batch would make a record a
        // duplicate of a reading that was discarded.
        foreach ($candidates as $c) {
            if (empty($held[(int) $c['ordinal']])) continue;
            $k = $c['group_hmac'] . '|' . $c['record_hash'] . '|' . $c['field'] . '|'
               . (isset($c['event_id']) ? $c['event_id'] : '') . '|'
               . (isset($c['instance']) ? $c['instance'] : 1);
            $this->candidates[$k] = $c;
        }
        // Behind the same fences as everything else in the batch, and only when
        // a record was held - see SqlScanStore::commitBatch().
        if ($held) {
            foreach ($presence as $m) {
                $this->markAggregate($runId, $m['kind'], $m['axis1'], $m['axis2'],
                                     empty($m['blocks']) ? 0 : 1,
                                         isset($m['samples']) ? $m['samples'] : null);
            }
            // The detail budget's count, for held records only (H9).
            $dropped = 0;
            foreach ($unstored as $o => $k) {
                if (!empty($held[$o])) $dropped += $k;
            }
            if ($dropped > 0) $this->addAggregate($runId, self::DETAIL_UNSTORED, '', '', $dropped);
        }
        $this->runs[$runId]['manifest_done'] += $applied;
        $this->runs[$runId]['detail_rows'] += count($keep);
        $this->runs[$runId]['detail_bytes'] += isset($batch['bytes']) ? (int) $batch['bytes'] : 0;
        // One of exactly two places, and only when a record actually finished.
        if ($applied > 0) $this->runs[$runId]['progress_at'] = gmdate('Y-m-d H:i:s');
        $heldCount = count($held);
        $heldOrdinals = array_keys($held);
        return true;
    }

    /**
     * The keys a worker gets, and only those.
     *
     * The SQL store selects a fixed column list; returning the whole in-memory
     * row here would let a test lean on a field production never sends, and the
     * shared contract would pass against a shape only one implementation has.
     */
    private static function claimRow(array $rec)
    {
        return ['ordinal' => $rec['ordinal'], 'id_bin' => $rec['id_bin'],
                'hash' => $rec['hash'], 'dag' => $rec['dag'],
                'attempts' => (int) $rec['attempts'], 'version' => $rec['version'],
                'claim' => (int) $rec['claim_seq']];
    }

    /**
     * A finding as uv_finding holds it: every column, and the schema's default
     * where the producer sent none (M13).
     *
     * This store kept whatever array it was handed, so a column the producer
     * left out was absent here and DEFAULTED there - instance 1, rule_ord 0,
     * reason_bits 0, valid_from_seq the run's - and a staged duplicate went in
     * ACTIVE because nothing read its stage_epoch. Written out against the
     * schema rather than borrowed from SqlScanStore::findingRow(): the two
     * stores are independent on purpose, and the contract compares what they
     * answer. The producer's name `identity` is kept for finding_identity,
     * because every check in this class reads it by that name.
     */
    private static function stored(array $f, $runSeq, $id)
    {
        $staged = isset($f['stage_epoch']) && $f['stage_epoch'] !== null;
        $or = function ($k, $default) use ($f) { return isset($f[$k]) ? $f[$k] : $default; };
        return [
            'finding_id'        => (int) $id,
            'project_id'        => (int) $f['project_id'],
            'generation_id'     => (int) $f['generation_id'],
            'identity'          => $f['identity'],
            'valid_from_seq'    => (int) $or('valid_from_seq', $runSeq),
            'valid_to_seq'      => null,
            'active_slot'       => $staged ? null : 1,
            'record_hash'       => $f['record_hash'],
            'record_id_bin'     => $f['record_id_bin'],
            'event_id'          => $or('event_id', null),
            'arm_id'            => $or('arm_id', null),
            'instance'          => $or('instance', 1),
            'host_form'         => $f['host_form'],
            'field'             => $f['field'],
            'rule_source_id'    => $f['rule_source_id'],
            'rule_revision'     => $f['rule_revision'],
            'rule_ord'          => $or('rule_ord', 0),
            'check_type'        => $f['check_type'],
            'reason_code'       => $f['reason_code'],
            'reason_bits'       => $or('reason_bits', 0),
            'severity'          => $or('severity', 0),
            'dag_key'           => $or('dag_key', null),
            'status_key'        => $or('status_key', null),
            'value_bin'         => $or('value_bin', null),
            'value_len'         => $or('value_len', null),
            'value_fingerprint' => $or('value_fingerprint', null),
            'value_truncated'   => $or('value_truncated', 0),
            'value_binary'      => $or('value_binary', 0),
            'value_expires_at'  => $or('value_expires_at', null),
            'group_hmac'        => $or('group_hmac', null),
            'stage_epoch'       => $staged ? $f['stage_epoch'] : null,
            // Schema versions 3 and 5, as SqlScanStore::findingRow().
            'as_of'             => isset($f['as_of']) ? (string) $f['as_of'] : null,
            'z_score'           => isset($f['z_score']) ? (string) $f['z_score'] : null,
            'growth_reference'  => isset($f['growth_reference']) ? (string) $f['growth_reference'] : null,
        ];
    }

    /** uq_staged_identity_v2's key, for a row that carries a stage_epoch. */
    private static function stagedKey(array $f)
    {
        return 'staged|' . (int) $f['project_id'] . '|' . (int) $f['generation_id']
             . '|' . bin2hex($f['identity']) . '|' . (int) $f['stage_epoch'];
    }

    /** Uniqueness candidates written so far. For assertions, not for production. */
    public function candidates()
    {
        return array_values($this->candidates);
    }

    /**
     * Hand claimed rows back. See SqlScanStore::releaseClaims() for why: without
     * it, a rolled-back batch and a phase that refuses to advance over
     * unexamined records combine into a deadlock.
     *
     * Fenced on the epoch AND on the per-row claim token, because only the
     * second can express takeover - the epoch does not move when a straggler
     * changes hands.
     */
    public function releaseClaims($runId, $epoch, $owner, array $claims)
    {
        $r = isset($this->runs[$runId]) ? $this->runs[$runId] : null;
        if ($r === null || (int) $r['lease_epoch'] !== (int) $epoch) return 0;
        if (!$claims || !isset($this->records[$runId])) return 0;
        $n = 0;
        $released = [];
        foreach ($claims as $ordinal => $token) {
            // Zero is the unclaimed default, never a token. See
            // SqlScanStore::byToken() for why passing it through is dangerous.
            if ((int) $token < 1) continue;
            $o = (int) $ordinal;
            if (!isset($this->records[$runId][$o])) continue;
            $rec = $this->records[$runId][$o];
            if ((string) $rec['claim_owner'] !== (string) $owner) continue;
            if ((int) $rec['claim_seq'] !== (int) $token) continue;
            if ($rec['state'] >= self::REC_DONE) continue;
            // A scanning-phase row is PENDING and still carries a token; a
            // straggler is CLAIMED. Both drop the token; only the second
            // changes state, and only the second is counted as handed back.
            if ($rec['state'] === self::REC_CLAIMED) {
                $this->records[$runId][$o]['state'] = self::REC_PENDING;
                $n++;
            }
            $this->records[$runId][$o]['claimed_at'] = null;
            $this->records[$runId][$o]['claim_owner'] = null;
            $this->records[$runId][$o]['claim_seq'] = 0;
            $released[] = $o;
        }
        // Over rows actually released. Rewinding past a row we did not release
        // would re-offer another worker's live claim.
        if ($released) {
            $low = min($released) - 1;
            if ($low < (int) $this->runs[$runId]['cursor_ordinal']) {
                $this->runs[$runId]['cursor_ordinal'] = $low;
            }
        }
        return $n;
    }

    /**
     * Count one attempt for work that was attempted, and retire what has run
     * out of attempts. See ScanStore::noteAttempts for why it is not part of
     * commitBatch: the counter used to move only when the commit succeeded, so
     * the retry cap could not be reached by the path that needed it.
     *
     * And hand back what is left, in the same call (review R2S-4) - see
     * SqlScanStore::noteAttempts(). A counted row that kept its token until a
     * separate release looked abandoned to the straggler sweep if that release
     * never came, and was charged for the same failure twice.
     */
    public function noteAttempts($runId, $epoch, $owner, array $claims, $maxAttempts, $exhausted)
    {
        $none = ['counted' => 0, 'retired' => 0, 'released' => 0];
        $r = isset($this->runs[$runId]) ? $this->runs[$runId] : null;
        if ($r === null || (int) $r['lease_epoch'] !== (int) $epoch) return $none;
        if (!$claims || !isset($this->records[$runId])) return $none;
        $cap = max(1, (int) $maxAttempts);
        $counted = 0;
        $retired = 0;
        $released = [];
        foreach ($claims as $ordinal => $token) {
            if ((int) $token < 1) continue;                 // never the unclaimed default
            $o = (int) $ordinal;
            if (!isset($this->records[$runId][$o])) continue;
            $rec = $this->records[$runId][$o];
            if ($rec['state'] >= self::REC_DONE) continue;
            if ((string) $rec['claim_owner'] !== (string) $owner) continue;
            if ((int) $rec['claim_seq'] !== (int) $token) continue;
            // Saturating, as the SQL store's LEAST(attempts + 1, 254) is.
            $now = min(254, (int) $rec['attempts'] + 1);
            $this->records[$runId][$o]['attempts'] = $now;
            $counted++;
            if ($now >= $cap) {
                $this->records[$runId][$o]['state'] = (int) $exhausted;
                $retired++;
            } else {
                // Handed back: a straggler returns to PENDING, a scanning row
                // (already PENDING) loses its stamp.
                $this->records[$runId][$o]['state'] = self::REC_PENDING;
                $released[] = $o;
            }
            $this->records[$runId][$o]['claim_owner'] = null;
            $this->records[$runId][$o]['claim_seq'] = 0;
            $this->records[$runId][$o]['claimed_at'] = null;
        }
        // Over rows actually released, as releaseClaims() moves it.
        if ($released) {
            $low = min($released) - 1;
            if ($low < (int) $this->runs[$runId]['cursor_ordinal']) {
                $this->runs[$runId]['cursor_ordinal'] = $low;
            }
        }
        return ['counted' => $counted, 'retired' => $retired, 'released' => count($released)];
    }

    /** A predicate over states, exactly as the SQL store computes it. */
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
        // The decision, in the same write as the terminal - see the SQL store.
        $n = function ($k) use ($outcome) {
            return isset($outcome[$k]) ? max(0, (int) $outcome[$k]) : 0;
        };
        $this->runs[$runId]['terminal_reason'] = isset($outcome['why'])
            ? substr((string) $outcome['why'], 0, 255) : null;
        $this->runs[$runId]['clean'] = ScanOutcome::cleanColumn($outcome);
        $this->runs[$runId]['gap_count'] = $n('gaps');
        $this->runs[$runId]['rule_problem_count'] = $n('ruleProblems');
        $this->runs[$runId]['not_examined'] = $n('notExamined');
        $this->runs[$runId]['updated_at'] = gmdate('Y-m-d H:i:s');
        return true;
    }

    public function cancel($pid, $runId, $actor)
    {
        // The INTERNAL row, not run(): active_slot is no part of the projection
        // a caller sees, and asking run() for it reads null and refuses every
        // cancel.
        $r = isset($this->runs[$runId]) ? $this->runs[$runId] : null;
        if ($r === null || (int) $r['project_id'] !== (int) $pid
            || $r['active_slot'] !== 1) return false;
        $this->runs[$runId]['cancel_requested_at'] = gmdate('Y-m-d H:i:s');
        $this->runs[$runId]['phase'] = 'cancelling';
        $this->runs[$runId]['lease_epoch']++;
        $this->audit($pid, $runId, 'cancel', $actor, null);
        return true;
    }

    // THE WORKER SLOTS ARE NOT MODELLED HERE ANY MORE. This class used to carry
    // an in-memory copy of them because the contract declared leaseSlot() and
    // releaseSlot(); the contract does not, because WorkerSlots is the one
    // semaphore and the store's pair had no caller. The assertions that lived
    // on this pair moved to the WorkerSlots contract, which runs against a real
    // server - which is where a semaphore's behaviour can actually be shown,
    // since the interesting half of it is two processes racing.

    /**
     * One keyset page, as the SQL store pages: finding_id > $afterId, in
     * finding_id order, each row keyed by FindingRowMapper::INPUT_KEYS in that
     * order (B5-N1). This returned the whole internal row, positioned by a
     * count of rows walked, and ignored a dag_key filter the SQL store applies.
     */
    public function findings($projectId, $generationId, array $filter, $afterId, $limit)
    {
        $limit = max(1, min(100, (int) $limit));
        $out = [];
        foreach ($this->findings as $f) {
            if ((int) $f['finding_id'] <= (int) $afterId) continue;
            if ((int) $f['project_id'] !== (int) $projectId) continue;
            if ((int) $f['generation_id'] !== (int) $generationId) continue;
            // Closed rows belong to an earlier reading of a record and are
            // kept so an "as of run N" view stays reproducible. A report
            // shows the ACTIVE ones; the real store says the same with
            // active_slot = 1 in its WHERE.
            if ($f['active_slot'] === null || (int) $f['active_slot'] !== 1) continue;
            foreach (['host_form', 'reason_code', 'dag_key', 'check_type'] as $k) {
                if (isset($filter[$k]) && $filter[$k] !== ''
                    && ($f[$k] === null || (string) $f[$k] !== (string) $filter[$k])) continue 2;
            }
            $row = [];
            foreach (FindingRowMapper::INPUT_KEYS as $k) {
                $row[$k] = array_key_exists($k, $f) ? $f[$k] : null;
            }
            // z_score only beside value_bin, as SqlScanStore::findings: a
            // growth z-score gives the measurement back.
            if ($row['value_bin'] === null) $row['z_score'] = null;
            $out[] = $row;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    public function aggregates($runId)
    {
        return isset($this->aggregates[$runId]) ? $this->aggregates[$runId] : [];
    }

    /**
     * The same page the SQL store closes, in the same order: the next older
     * generation of this project's own runs, its active rows in finding_id
     * order, at most min($limit, PAGE) of them; the cursor past a generation
     * that closed fewer than that, and to this run's own generation when none
     * is left. See SqlScanStore::supersedePrevious().
     */
    public function supersedePrevious($pid, $runId, $limit)
    {
        $pid = (int) $pid;
        $limit = max(1, min(self::PAGE, (int) $limit));
        $out = function ($done, $rows, $cursor, $why) {
            return ['done' => (bool) $done, 'rows' => (int) $rows, 'cursor' => (int) $cursor,
                    'why' => $why];
        };
        $r = isset($this->runs[$runId]) ? $this->runs[$runId] : null;
        if ($r === null || (int) $r['project_id'] !== $pid) {
            return $out(true, 0, 0, 'there is no such run in this project');
        }
        $gen = (int) $r['generation_id'];
        $cursor = (int) $r['supersede_cursor'];
        if ($r['terminal'] === null) {
            return $out(false, 0, $cursor, 'this scan has not finished, so it supersedes nothing yet');
        }
        if ((string) $r['terminal'] !== ScanOutcome::COMPLETE) {
            return $out(true, 0, $cursor,
                'this scan did not complete, so the previous report stays the current one');
        }
        // One group's scan supersedes nothing (review R8-1); see the contract.
        if ($r['scope_dag'] !== null) return $out(true, 0, $cursor, self::SUPERSEDE_SCOPED);
        $older = $this->olderGeneration($pid, $cursor, $gen);
        if ($older === null) {
            $this->runs[$runId]['supersede_cursor'] = max($cursor, $gen);
            return $out(true, 0, $gen, null);
        }
        $rows = 0;
        foreach ($this->findings as $i => $f) {
            if ($rows >= $limit) break;
            if ($f['project_id'] !== $pid || (int) $f['generation_id'] !== $older) continue;
            if ($f['active_slot'] === null || (int) $f['active_slot'] !== 1) continue;
            $this->findings[$i]['active_slot'] = null;
            $this->findings[$i]['valid_to_seq'] = (int) $r['run_seq'];
            $rows++;
        }
        if ($rows >= $limit) return $out(false, $rows, $cursor, null);
        $after = $this->olderGeneration($pid, $older + 1, $gen);
        $to = $after === null ? $gen : $older + 1;
        $this->runs[$runId]['supersede_cursor'] = $to;
        return $out($after === null, $rows, $to, null);
    }

    /** The lowest generation of this project's runs in [$from, $gen), or null. */
    private function olderGeneration($pid, $from, $gen)
    {
        $min = null;
        foreach ($this->runs as $run) {
            if ((int) $run['project_id'] !== (int) $pid) continue;
            $g = (int) $run['generation_id'];
            if ($g < (int) $from || $g >= (int) $gen) continue;
            if ($min === null || $g < $min) $min = $g;
        }
        return $min;
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
                // THE WHOLE ROW SHAPE, not most of it. These rows were built
                // without 'claimed_at', so the very first straggler sweep to
                // reach a reconciled record read a key that was not there - and
                // the claim fence would now read two more.
                'claimed_at' => null, 'claim_owner' => null, 'claim_seq' => 0];
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
            // The claim goes with the state. A record sent back to pending
            // still carrying its old token could be committed by the worker
            // that was holding it when catch-up requeued it - which is the one
            // reading whose staleness is the reason it was requeued.
            $this->records[$runId][$o]['claimed_at'] = null;
            $this->records[$runId][$o]['claim_owner'] = null;
            $this->records[$runId][$o]['claim_seq'] = 0;
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

    public function holdRollupCursor($runId, $epoch)
    {
        // Same three refusals, in the same order, as the SQL store. Nothing to
        // hold in one process; the comparison is the part a caller relies on.
        if (!isset($this->runs[$runId])) return self::ROLLUP_NO_RUN;
        $r = $this->runs[$runId];
        if ((int) $r['lease_epoch'] !== (int) $epoch) return self::ROLLUP_TAKEN_OVER;
        if (isset($r['terminal']) && $r['terminal'] !== null) return self::ROLLUP_FINISHED;
        return (int) (array_key_exists('rollup_cursor', $r) ? $r['rollup_cursor'] : 0);
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

    public function markAggregate($runId, $kind, $axis1, $axis2, $blocks = 0, $samples = null)
    {
        $axis1 = (string) $axis1;
        $axis2 = (string) $axis2;
        $k = $kind . '|' . $axis1 . '|' . $axis2;
        if (!isset($this->aggregates[$runId][$k])) {
            $this->aggregates[$runId][$k] = ['kind' => $kind, 'axis1' => $axis1, 'axis2' => $axis2,
                                             'cnt' => 0, 'blocks' => 0, 'samples' => $samples];
        }
        // SET, not added - and set even on a row addAggregate() had counted up.
        $this->aggregates[$runId][$k]['cnt'] = 1;
        $this->aggregates[$runId][$k]['blocks'] = max($this->aggregates[$runId][$k]['blocks'],
                                                      $blocks ? 1 : 0);
        if ($this->aggregates[$runId][$k]['samples'] === null) {
            $this->aggregates[$runId][$k]['samples'] = $samples;
        }
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
