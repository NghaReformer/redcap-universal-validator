<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * One bounded unit of scanning: claim some records, prove they held still, look
 * at them, and commit — or commit nothing at all.
 *
 * THE SAME CLASS RUNS IN THE BROWSER AND IN CRON. Not two implementations that
 * are meant to agree: one, called with a different budget. The alternative is
 * two code paths whose divergence nobody notices until the one that runs
 * unattended turns out to have a different idea of what "done" means.
 *
 * THE STABLE READ, AND WHY IT IS FOUR STEPS RATHER THAN ONE.
 *
 *   1. Read each record's source version.
 *   2. Read the records.
 *   3. Read the versions again.
 *   4. Keep only the records whose version did not move.
 *
 * A scan of 100,000 records reads a project people are still using. Without this
 * a record edited during step 2 is examined half in its old state and half in
 * its new one, and the finding that results describes a state the project was
 * never in. Requeueing it costs one re-read; certifying it costs the report its
 * meaning. A record that will not hold still after the configured number of
 * attempts becomes a blocking exclusion — reported, and enough on its own to
 * stop the run claiming complete coverage.
 *
 * NOTHING IS BELIEVED BECAUSE IT WAS TRUE A MOMENT AGO. Every write is fenced on
 * the run's lease epoch, and the epoch is bumped by cancellation and by lease
 * takeover. A worker that was cancelled mid-evaluation therefore discovers it at
 * its final compare-and-set and discards everything it buffered, rather than
 * committing into a run that has already been finished and exported.
 *
 * WHAT IT REFUSES TO DO. It never marks a record done outside the transaction
 * that examined it (I3), never advances a phase the transition table forbids,
 * and never continues when the validation configuration has changed underneath
 * it — a run half-checked against rules that no longer exist is worse than no
 * run, because it looks like one.
 *
 * PHP 7.4.
 */
final class ScanWorker
{
    /** @var ScanStore */
    private $store;
    /** @var array */
    private $deps;

    /**
     * @param array $deps {
     *   slots:          ?WorkerSlots  installation semaphore; null means unrationed
     *   fence:          ?RecordVersions  null means reads cannot be proved stable
     *   read:           callable(string[] $ids): array{ok:bool, data:array, why:?string}
     *   evaluate:       callable(string $id, array $node): array{findings:array, bytes:int,
     *                                                            contexts:int, why:?string}
     *   budget:         WorkBudget
     *   owner:          string        who this worker is, for the leases
     *   attempts:       int           how many times one record may be retried
     *   fingerprint:    string        the configuration as it is NOW
     *   policyRevision: int           the privacy policy as it is NOW
     *   slotTtl:        int
     * }
     */
    public function __construct(ScanStore $store, array $deps)
    {
        $this->store = $store;
        $this->deps = $deps;
    }

    /**
     * Do one request's worth of work.
     *
     * @return array{ok:bool, worked:int, requeued:int, blocked:int, findings:int,
     *               phase:string, done:bool, stop:?string, why:?string}
     */
    public function work($pid, $runId, array $opts = [])
    {
        $run = $this->store->run($pid, $runId);
        if ($run === null) {
            // The same wording a cross-project run id gets. A message that
            // distinguished "not here" from "not yours" would let anyone with a
            // project link count the runs on every other project.
            return self::stop('no scan with that reference is running for this project');
        }
        $phase = (string) $run['phase'];
        if (!ScanPhase::mayWork($phase)) {
            return ['ok' => true, 'worked' => 0, 'requeued' => 0, 'blocked' => 0, 'findings' => 0,
                    'phase' => $phase, 'done' => ($phase === ScanPhase::TERMINAL), 'stop' => null,
                    'why' => 'this scan is not in a phase that takes work'];
        }
        $epoch = (int) $run['lease_epoch'];

        // THE CONFIGURATION MOVED. A run cannot be half-checked against rules
        // that no longer exist and still describe the project, and there is no
        // partial claim that would be true - so it ends, and says so.
        if (isset($this->deps['fingerprint'])
                && !ScanPlanner::fingerprintMatches($run['fingerprint'], $this->deps['fingerprint'])) {
            $this->store->finish($runId, array_merge(
                ScanOutcome::derive(['failed' => true]),
                ['why' => 'the project\'s validation rules changed while this scan was running, '
                        . 'so its results would describe two different configurations']));
            return self::stop('the validation rules changed during this scan, so it was stopped '
                . 'and must be run again');
        }

        // THE PRIVACY POLICY TIGHTENED. The run stores the policy it began
        // under, and continuing would keep writing value previews the project
        // has just decided it does not want. Tightening takes effect at once by
        // design; loosening waits for the next run.
        if (isset($this->deps['policyRevision'])
                && (int) $run['policy_revision'] !== (int) $this->deps['policyRevision']) {
            $this->store->finish($runId, array_merge(
                ScanOutcome::derive(['cancelled' => true]),
                ['why' => 'the project\'s privacy settings were tightened while this scan was '
                        . 'running, so it was stopped before writing anything further']));
            return self::stop('this project\'s privacy settings changed during the scan, so it was '
                . 'stopped; run it again to get a report under the new settings');
        }

        // THE INSTALLATION-WIDE SEMAPHORE, taken before any project work. Two
        // projects scanning at once cost the server the same as one project
        // scanning twice, which is why the limit is not per project.
        $slot = null;
        $slots = isset($this->deps['slots']) ? $this->deps['slots'] : null;
        if ($slots instanceof WorkerSlots) {
            $ttl = isset($this->deps['slotTtl']) ? (int) $this->deps['slotTtl'] : 300;
            $slot = $slots->acquire($this->owner(), $runId, $ttl);
            if ($slot === null) {
                // TWO DIFFERENT FAULTS LOOK IDENTICAL HERE, and only one of them
                // is contention.
                //
                // Leasing is an UPDATE against precreated rows, so the count of
                // rows IS the limit - and a table with no rows is a limit of
                // zero. Every worker is refused, forever, and "the server is
                // busy with other scans" is then a false sentence that sends an
                // administrator looking for scans that do not exist. The first
                // live pilot spent a round exactly there.
                //
                // One extra query, on the failure path only, to tell an empty
                // pool from a full one.
                $census = $slots->census();
                if ((int) $census['total'] < 1) {
                    return ['ok' => false, 'worked' => 0, 'requeued' => 0, 'blocked' => 0,
                            'findings' => 0, 'phase' => $phase, 'done' => false,
                            'stop' => 'unprovisioned',
                            'why' => 'this installation has no scan worker slots, so no scan can '
                                   . 'run. An administrator can create them by saving the module\'s '
                                   . 'system configuration.'];
                }
                // Genuine contention. Not an error: the right answer is to come
                // back rather than to fail the run.
                return ['ok' => true, 'worked' => 0, 'requeued' => 0, 'blocked' => 0,
                        'findings' => 0, 'phase' => $phase, 'done' => false, 'stop' => 'capacity',
                        'why' => 'this server is running as many scans as it allows at once; '
                               . 'this one will continue shortly'];
            }
        }

        try {
            return $this->loop($pid, $runId, $phase, $epoch,
                (int) $run['generation_id'], $opts);
        } finally {
            if ($slot !== null && $slots instanceof WorkerSlots) {
                $slots->release($slot['slot_no'], $this->owner(), $slot['epoch']);
            }
        }
    }

    /** Claim, evaluate and commit until the budget says stop or the phase is empty. */
    private function loop($pid, $runId, $phase, $epoch, $generationId, array $opts)
    {
        $budget = isset($this->deps['budget']) ? $this->deps['budget'] : new WorkBudget();
        $worked = 0; $requeued = 0; $blocked = 0; $found = 0; $stop = null; $why = null;
        $done = false;

        while (true) {
            $stop = $budget->mustStop();
            if ($stop !== null) {
                $why = ($stop === 'time') ? WorkBudget::OUT_OF_TIME : WorkBudget::OUT_OF_MEMORY;
                break;
            }

            // UNIQUENESS IS NOT A RECORD-AT-A-TIME PHASE. No record is a
            // duplicate on its own evidence, so this phase works over candidate
            // GROUPS and has its own bounded step.
            if ($phase === ScanPhase::UNIQUE) {
                $fin = isset($this->deps['finalizer']) ? $this->deps['finalizer'] : null;
                if (!($fin instanceof DuplicateFinalizer)) {
                    // Advancing past a finalizer that was never configured would
                    // make "we checked and found no duplicates" and "nobody
                    // checked" the same stored fact. Stop instead.
                    return ['ok' => false, 'worked' => $worked, 'requeued' => $requeued,
                            'blocked' => $blocked, 'findings' => $found, 'phase' => $phase,
                            'done' => false, 'stop' => 'unconfigured',
                            'why' => 'this scan has no way to decide duplicate values, so it '
                                   . 'stopped rather than reporting that it found none'];
                }
                $t0 = microtime(true);
                $m0 = memory_get_usage(true);
                $r = $fin->step($generationId, $budget->claim());
                $found += $r['emitted'];
                $blocked += $r['collisions'];
                if ($r['done']) {
                    $next = ScanPhase::next($phase);
                    if ($next !== null && $this->store->advancePhase($runId, $epoch, $next)) {
                        $phase = $next;
                        continue;
                    }
                    $done = true;
                    break;
                }
                $adj = $budget->next([
                    'records' => max(1, $r['verified'] + $r['emitted'] + $r['groups']),
                    'seconds' => microtime(true) - $t0,
                    'memoryDelta' => max(0, memory_get_usage(true) - $m0),
                    'usage' => memory_get_usage(true),
                ]);
                if ($adj['stop'] !== null) { $stop = $adj['stop']; $why = $adj['why']; break; }
                continue;
            }

            // THE SUMMARY, built once at the end from bounded pages. Nothing
            // here reads a record; it reads the findings the earlier phases
            // wrote, which is why it is a phase of its own and not a step in
            // the commit path.
            if ($phase === ScanPhase::ROLLUP) {
                $roll = isset($this->deps['rollup']) ? $this->deps['rollup'] : null;
                if ($roll instanceof RollupBuilder) {
                    $t0 = microtime(true);
                    $m0 = memory_get_usage(true);
                    $r = $roll->step($runId, $epoch, $generationId, $budget->claim());
                    if (!$r['done']) {
                        if ($r['rows'] === 0 && $r['why'] !== null) {
                            // The lease moved under us. Its page was discarded
                            // rather than counted twice; stopping is the rest of
                            // that decision.
                            return ['ok' => false, 'worked' => $worked, 'requeued' => $requeued,
                                    'blocked' => $blocked, 'findings' => $found, 'phase' => $phase,
                                    'done' => false, 'stop' => 'fenced', 'why' => $r['why']];
                        }
                        $adj = $budget->next([
                            'records' => max(1, $r['rows']), 'seconds' => microtime(true) - $t0,
                            'memoryDelta' => max(0, memory_get_usage(true) - $m0),
                            'usage' => memory_get_usage(true),
                        ]);
                        if ($adj['stop'] !== null) { $stop = $adj['stop']; $why = $adj['why']; break; }
                        continue;
                    }
                }
                // End of the chain. Whether the run may FINISH is not this
                // method's decision either - ScanPromotion owns that, and it
                // asks questions this loop deliberately never sees.
                $done = true;
                break;
            }

            // WHAT THE PROJECT DID WHILE WE READ IT. Pending rows first, so a
            // record this reconciler requeued a moment ago is examined before
            // the next page of the window is walked; otherwise the confirming
            // round would find it unchanged and settle over work not yet done.
            if ($phase === ScanPhase::CATCH_UP) {
                $claimed = $this->store->claimPending($runId, $this->owner(), $epoch,
                                                      $budget->claim());
                if (!$claimed) {
                    $cu = isset($this->deps['catchup']) ? $this->deps['catchup'] : null;
                    if ($cu instanceof CatchUp) {
                        $t0 = microtime(true);
                        $m0 = memory_get_usage(true);
                        $r = $cu->step($pid, $runId, $epoch, $budget->claim());
                        if (!$r['done']) {
                            $adj = $budget->next([
                                'records' => max(1, $r['scanned']),
                                'seconds' => microtime(true) - $t0,
                                'memoryDelta' => max(0, memory_get_usage(true) - $m0),
                                'usage' => memory_get_usage(true),
                            ]);
                            if ($adj['stop'] !== null) { $stop = $adj['stop']; $why = $adj['why']; break; }
                            continue;
                        }
                    }
                    $next = ScanPhase::next($phase);
                    if ($next !== null && $this->store->advancePhase($runId, $epoch, $next)) {
                        $phase = $next;
                        continue;
                    }
                    $done = true;
                    break;
                }
            } else {
                $claimed = $this->store->claim($runId, $this->owner(), $epoch, $budget->claim());
            }

            if (!$claimed) {
                // Nothing left in this phase. Whether that means the run is
                // finished is not this method's decision - the phase chain says
                // what comes next, and a phase that has nothing to do still has
                // to be entered so that "it ran and found nothing" is
                // distinguishable from "it never ran".
                if (!$this->store->manifestComplete($runId)) {
                    // The cursor reached the end with rows still unfinished:
                    // stragglers, which catch-up sweeps by state rather than by
                    // position.
                    $done = false;
                }
                $next = ScanPhase::next($phase);
                if ($next !== null && $this->store->advancePhase($runId, $epoch, $next)) {
                    $phase = $next;
                    continue;
                }
                $done = true;
                break;
            }

            $t0 = microtime(true);
            $m0 = memory_get_usage(true);
            $r = $this->batch($pid, $runId, $epoch, $claimed);
            if (!$r['ok']) {
                // A refused commit means this worker was overtaken or cancelled.
                // Everything it buffered is already discarded; stopping is the
                // only correct response, because whatever it does next would be
                // done on behalf of a run that no longer wants it.
                return ['ok' => false, 'worked' => $worked, 'requeued' => $requeued,
                        'blocked' => $blocked, 'findings' => $found, 'phase' => $phase,
                        'done' => false, 'stop' => 'fenced', 'why' => $r['why']];
            }
            $worked   += $r['worked'];
            $requeued += $r['requeued'];
            $blocked  += $r['blocked'];
            $found    += $r['findings'];

            $adj = $budget->next([
                'records' => count($claimed), 'seconds' => microtime(true) - $t0,
                'memoryDelta' => max(0, memory_get_usage(true) - $m0),
                'usage' => memory_get_usage(true),
            ]);
            if ($adj['stop'] !== null) {
                $stop = $adj['stop'];
                $why = $adj['why'];
                break;
            }
        }

        return ['ok' => true, 'worked' => $worked, 'requeued' => $requeued, 'blocked' => $blocked,
                'findings' => $found, 'phase' => $phase, 'done' => $done,
                'stop' => $stop, 'why' => $why];
    }

    /**
     * One claimed batch, read stably and committed as a unit.
     *
     * @return array{ok:bool, worked:int, requeued:int, blocked:int, findings:int, why:?string}
     */
    private function batch($pid, $runId, $epoch, array $claimed)
    {
        $ids = [];
        foreach ($claimed as $c) $ids[] = $c['id_bin'];

        $fence = isset($this->deps['fence']) ? $this->deps['fence'] : null;
        $before = ($fence instanceof RecordVersions) ? $fence->versions($ids) : [];

        $read = $this->deps['read'];
        $got = $read($ids);
        if (empty($got['ok'])) {
            // A FAILED READ IS NOT AN EMPTY ONE. Committing these records as
            // examined-and-clean is the exact mistake the module was built to
            // prevent, so nothing is committed and the rows stay claimable.
            return ['ok' => true, 'worked' => 0, 'requeued' => count($claimed), 'blocked' => 0,
                    'findings' => 0,
                    'why' => isset($got['why']) ? $got['why'] : 'the records could not be read'];
        }
        $data = isset($got['data']) && is_array($got['data']) ? $got['data'] : [];

        $after = ($fence instanceof RecordVersions) ? $fence->versions($ids) : [];

        $maxAttempts = isset($this->deps['attempts']) ? max(1, (int) $this->deps['attempts']) : 3;
        $batch = ['bytes' => 0, 'records' => [], 'findings' => [], 'candidates' => []];
        $worked = 0; $requeued = 0; $blocked = 0;

        foreach ($claimed as $c) {
            $id = $c['id_bin'];
            $tries = isset($c['attempts']) ? (int) $c['attempts'] : 0;

            $moved = ($fence instanceof RecordVersions)
                && (!array_key_exists($id, $before) || !array_key_exists($id, $after)
                    || $before[$id] !== $after[$id]);
            if ($moved) {
                // One more attempt, unless it has already had them all. A record
                // that is edited every time we look at it is a real fact about
                // the project, and reporting it is better than either retrying
                // forever or quietly leaving it out.
                if ($tries + 1 >= $maxAttempts) {
                    $batch['records'][] = ['ordinal' => $c['ordinal'],
                                           'state' => ScanStore::REC_UNSTABLE, 'version' => null];
                    $blocked++;
                } else {
                    $batch['records'][] = ['ordinal' => $c['ordinal'],
                                           'state' => ScanStore::REC_PENDING, 'version' => null];
                    $requeued++;
                }
                continue;
            }

            if (!array_key_exists($id, $data) || !is_array($data[$id])) {
                // Asked for and not returned. Either it was deleted during the
                // run - which is a tombstone, a real terminal state, so the run
                // is not stuck waiting for it - or the read is wrong about it,
                // which is worth another attempt first.
                if ($tries + 1 >= $maxAttempts) {
                    $batch['records'][] = ['ordinal' => $c['ordinal'],
                                           'state' => ScanStore::REC_TOMBSTONE, 'version' => null];
                    $blocked++;
                } else {
                    $batch['records'][] = ['ordinal' => $c['ordinal'],
                                           'state' => ScanStore::REC_PENDING, 'version' => null];
                    $requeued++;
                }
                continue;
            }

            $ev = $this->evaluate($id, $data[$id]);
            if (!empty($ev['why'])) {
                // The record was read and could not be examined. Reported as
                // unreadable rather than as clean - H-05 in one line.
                $batch['records'][] = ['ordinal' => $c['ordinal'],
                                       'state' => ScanStore::REC_UNREADABLE, 'version' => null];
                $blocked++;
                continue;
            }
            foreach ($ev['findings'] as $f) {
                $batch['findings'][] = $f;
            }
            // A uniqueness rule produces a CANDIDATE rather than a finding: no
            // record can be a duplicate on its own evidence. They travel in the
            // same batch so that a rolled-back read leaves no candidate behind
            // to make some other record look like a duplicate of it.
            if (isset($ev['candidates']) && is_array($ev['candidates'])) {
                foreach ($ev['candidates'] as $c) {
                    if (!isset($c['version'])) $c['version'] = isset($after[$id]) ? $after[$id] : null;
                    $batch['candidates'][] = $c;
                }
            }
            $batch['bytes'] += isset($ev['bytes']) ? (int) $ev['bytes'] : 0;
            $batch['records'][] = ['ordinal' => $c['ordinal'], 'state' => ScanStore::REC_DONE,
                                   'version' => isset($after[$id]) ? $after[$id] : null];
            $worked++;
        }

        $ok = $this->store->commitBatch($runId, $this->owner(), $epoch, 0, $batch);
        if (!$ok) {
            return ['ok' => false, 'worked' => 0, 'requeued' => 0, 'blocked' => 0, 'findings' => 0,
                    'why' => 'this scan was cancelled or taken over while these records were being '
                           . 'examined, so nothing from them was kept'];
        }
        return ['ok' => true, 'worked' => $worked, 'requeued' => $requeued, 'blocked' => $blocked,
                'findings' => count($batch['findings']),
                'candidates' => count($batch['candidates']), 'why' => null];
    }

    /** Evaluate one record, turning any throw into a reported failure. */
    private function evaluate($id, array $node)
    {
        try {
            $fn = $this->deps['evaluate'];
            $r = $fn($id, $node);
            if (!is_array($r)) {
                return ['findings' => [], 'bytes' => 0, 'contexts' => 0,
                        'why' => 'this record could not be examined'];
            }
            if (!isset($r['findings']) || !is_array($r['findings'])) $r['findings'] = [];
            return $r;
        } catch (\Throwable $e) {
            // Never propagates. A throw here would end the request with the
            // batch uncommitted AND the run left mid-phase, which is the state
            // that looks identical to a worker still running.
            return ['findings' => [], 'bytes' => 0, 'contexts' => 0,
                    'why' => 'this record could not be examined (' . get_class($e) . ')'];
        }
    }

    private function owner()
    {
        return isset($this->deps['owner']) ? (string) $this->deps['owner'] : 'worker';
    }

    private static function stop($why)
    {
        return ['ok' => false, 'worked' => 0, 'requeued' => 0, 'blocked' => 0, 'findings' => 0,
                'phase' => ScanPhase::TERMINAL, 'done' => true, 'stop' => 'refused', 'why' => $why];
    }
}
