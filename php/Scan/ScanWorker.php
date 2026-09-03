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
 * NOTHING IS BELIEVED BECAUSE IT WAS TRUE A MOMENT AGO, and it takes TWO fences
 * to say that, not one. Every write is fenced on the run's lease epoch, which
 * cancellation bumps - so a worker cancelled mid-evaluation discovers it at its
 * final compare-and-set and discards everything it buffered rather than
 * committing into a run that has already been finished and exported. But the
 * epoch is bumped by cancellation and by NOTHING ELSE; the sentence that used to
 * stand here said "and by lease takeover", and that was simply untrue. Takeover
 * is fenced per record, on the claim token claim() stamps and commitBatch
 * matches, because a run-wide bump would throw away every OTHER worker's
 * in-flight batch to invalidate one record's.
 *
 * AND NOTHING WEDGES. A batch that cannot be committed and a batch that cannot
 * be read both hand their records back and count an attempt against them, in a
 * transaction the failure cannot roll back. Records that run out of attempts
 * become reported exclusions, so the run reaches a terminal state and releases
 * the project's slot instead of retrying a failing write until the tab is
 * closed.
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
     *   note:           ?callable(string $event, array $context): void
     *                                 where a storage failure is recorded. The
     *                                 worker's own answer never carries what
     *                                 the server said - see noteStorageFailure.
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

        // A CANCELLED RUN HAS TO BE FINISHED BY SOMEBODY.
        //
        // `cancelling` exists so the epoch bump lands BEFORE the terminal write,
        // which is what makes a worker mid-evaluation fail its compare-and-set
        // instead of committing into a finished run. That is the whole point of
        // the phase - but nothing then completed the transition, so a cancelled
        // run sat in `cancelling` holding the project's one active slot, and no
        // new scan could start until the abandoned-run sweep caught it hours
        // later. The live pilot left one there within a minute of pressing Stop.
        //
        // Whoever arrives next finishes it. finish() is idempotent, so two
        // workers arriving together is not a race.
        if ($phase === ScanPhase::CANCELLING) {
            $this->store->finish($runId, ScanOutcome::derive([
                'cancelled' => true,
                'manifestDone' => $this->store->manifestComplete($runId),
            ]));
            return ['ok' => true, 'worked' => 0, 'requeued' => 0, 'blocked' => 0, 'findings' => 0,
                    'phase' => ScanPhase::TERMINAL, 'done' => false, 'stop' => 'cancelled',
                    'why' => 'this scan was stopped, so the records after that point were never '
                           . 'examined'];
        }

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
        // THE SEMAPHORE IS INSIDE THE STORAGE-FAILURE GUARD, not before it.
        // acquire() is a compare-and-set over the slot table and it fails the
        // same way everything else does; leaving it outside would send a
        // database fault back as "this server is running as many scans as it
        // allows at once", which is the 1.9.5 sentence over a pool that is free.
        try {
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

            // KEEPING THE SLOT IS THE WORKER'S JOB, and until now nobody did
            // it. A slot is leased with a TTL and renew() had no caller at all,
            // so a batch that outlived the TTL kept working while the semaphore
            // considered its slot free - another worker could lease the same
            // slot, and the installation-wide concurrency limit was exceeded by
            // however many workers were in that state. Not reachable through
            // the browser today, where a pass is budgeted at three seconds
            // against a 300-second TTL; reachable the moment a cron pass or a
            // budgeted planning phase runs longer.
            //
            // A closure rather than another two parameters on loop(): what the
            // loop needs is the ABILITY to keep the slot, not the slot itself,
            // and a worker built without a semaphore keeps working exactly as
            // it did before.
            $keep = null;
            if ($slot !== null && $slots instanceof WorkerSlots) {
                $ttl = isset($this->deps['slotTtl']) ? (int) $this->deps['slotTtl'] : 300;
                $keep = function () use ($slots, $slot, $ttl) {
                    return $slots->renew($slot['slot_no'], $this->owner(), $slot['epoch'], $ttl);
                };
            }
            return $this->loop($pid, $runId, $phase, $epoch,
                (int) $run['generation_id'], $opts, $keep);
        } catch (ScanStoreUnavailable $e) {
            // THE DATABASE FAILED, WHICH IS NOT THE SAME AS BEING FENCED OUT.
            //
            // Until the store learned to say so, a deadlock inside claim() came
            // back as `false` and left through refused() — ok:true, stop:
            // 'fenced', "this scan could not take more work just now" — and the
            // browser, reading ok:true, re-issued scan-work immediately against
            // a database that was already in trouble. Worse, the same swallow
            // inside advancePhase() read as "there is no next phase" and the
            // run was reported DONE.
            //
            // So: ok is FALSE, which is what stops the client pumping, and done
            // is FALSE, which is the one thing that must never be guessed. The
            // counters are zero because the transaction that failed rolled back
            // and I3 leaves its records exactly as unexamined as they were.
            $this->noteStorageFailure($runId, $e);
            return ['ok' => false, 'worked' => 0, 'requeued' => 0, 'blocked' => 0,
                    'findings' => 0, 'phase' => $phase, 'done' => false, 'stop' => 'storage',
                    'why' => ScanStoreUnavailable::OPERATOR_TEXT];
        } finally {
            // Still inside the finally, deliberately: a storage failure must
            // give the installation its worker slot back, or one bad afternoon
            // exhausts the pool for everybody.
            if ($slot !== null && $slots instanceof WorkerSlots) {
                $slots->release($slot['slot_no'], $this->owner(), $slot['epoch']);
            }
        }
    }

    /**
     * Send what the server said somewhere an administrator can read it.
     *
     * THE PAYLOAD NEVER CARRIES IT. The worker's answer goes to a browser, and
     * a server error message is the one string in this module most likely to
     * have a participant's data in the middle of it. Threading the detail
     * through the payload and asking the caller to remove it before answering
     * would work exactly until the second caller — so the detail leaves by a
     * different door, and the door is optional: a worker built without a `note`
     * is a worker whose failures are silent, which the composition root is
     * responsible for not doing.
     *
     * Wrapped, because the log write goes to the database that just failed.
     */
    private function noteStorageFailure($runId, ScanStoreUnavailable $e)
    {
        $note = isset($this->deps['note']) ? $this->deps['note'] : null;
        if (!is_callable($note)) return;
        try {
            $note('scan storage failure', ['run_id' => (int) $runId,
                                           'detail' => $e->safeDetail()]);
        } catch (\Throwable $ignored) {
            // A failure while reporting a failure is not worth a second one.
        }
    }

    /** Claim, evaluate and commit until the budget says stop or the phase is empty. */
    private function loop($pid, $runId, $phase, $epoch, $generationId, array $opts, $keep = null)
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

            // ONE RENEWAL PER TURN OF THE LOOP, at the top, so the lease is
            // extended before the work rather than after it. False means the
            // slot was taken over, and the answer to that is to stop: the
            // installation has already allocated this capacity to someone else,
            // and a worker that keeps going is precisely the excess the
            // semaphore exists to prevent. Not an error - the run is untouched
            // and resumable, which is what `ok:true` says.
            if (is_callable($keep) && !$keep()) {
                return ['ok' => true, 'worked' => $worked, 'requeued' => $requeued,
                        'blocked' => $blocked, 'findings' => $found, 'phase' => $phase,
                        'done' => false, 'stop' => 'slot',
                        'why' => 'this scan lost its place in the server queue to another '
                               . 'scan while it was working; it will continue shortly'];
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
                    $r = $roll->step($pid, $runId, $epoch, $generationId, $budget->claim());
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
                if ($claimed === false) return self::refused($worked, $requeued, $blocked,
                                                             $found, $phase);
                if (!$claimed) {
                    // NOT WHILE RECORDS ARE STILL OUT. Catch-up sweeps
                    // stragglers BY STATE, and a row claimed by a worker that
                    // has not committed is invisible to that sweep until its
                    // claim goes stale. Advancing here abandons it: the live
                    // pilot reached rollup-finalize with 3 of 39 examined and
                    // told the client it was done.
                    if (!$this->store->manifestComplete($runId)) {
                        return self::waiting($worked, $requeued, $blocked, $found, $phase);
                    }
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

            if ($claimed === false) {
                return self::refused($worked, $requeued, $blocked, $found, $phase);
            }
            if (!$claimed) {
                // Nothing left to hand out here. Whether the run may move on is
                // a question about the MANIFEST, not about this claim: a phase
                // with nothing to do still has to be ENTERED, so "it ran and
                // found nothing" stays distinguishable from "it never ran" - but
                // a phase must never be LEFT over records nobody examined.
                //
                // Scanning is the one exception, and only forwards: its cursor
                // legitimately reaches the end while rows sit claimed by a
                // worker that has not committed. Those are stragglers, and
                // catch-up sweeps them by state - which is exactly why catch-up
                // refuses to advance while any remain.
                if ($phase !== ScanPhase::SCANNING && !$this->store->manifestComplete($runId)) {
                    return self::waiting($worked, $requeued, $blocked, $found, $phase);
                }
                $next = ScanPhase::next($phase);
                if ($next !== null && $this->store->advancePhase($runId, $epoch, $next)) {
                    $phase = $next;
                    continue;
                }
                // `done` is a claim about the WHOLE RUN, so it is a predicate
                // over record states - never the fact that this loop ran out of
                // work to do.
                $done = $this->store->manifestComplete($runId);
                break;
            }

            $t0 = microtime(true);
            $m0 = memory_get_usage(true);
            $r = $this->batch($pid, $runId, $epoch, $claimed);
            if (!$r['ok']) {
                // A refused commit means this worker was overtaken, cancelled,
                // or writing into a database that will not have it; a failed
                // read means the project could not be exported just now.
                // Everything buffered is already discarded and the records have
                // been handed back, so stopping is the only correct response -
                // whatever this worker did next would be done on behalf of a run
                // that no longer wants it, or against a source that is failing.
                //
                // THE BLOCKED COUNT COMES THROUGH. A record that has now run out
                // of attempts became a reported exclusion inside that give-back,
                // and it is the one number from a failed batch that is real.
                //
                // The stop reason is the BATCH's, not a constant. It used to be
                // 'fenced' whatever had happened, so "REDCap would not give us
                // the records" and "another worker took this run over" reached
                // the operator as the same sentence.
                return ['ok' => false, 'worked' => $worked, 'requeued' => $requeued,
                        'blocked' => $blocked + (int) $r['blocked'], 'findings' => $found,
                        'phase' => $phase, 'done' => false,
                        'stop' => isset($r['stop']) && $r['stop'] !== null ? $r['stop'] : 'fenced',
                        'why' => $r['why']];
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
     * This worker may not claim right now. Not an error, and not the end.
     *
     * A cancelled run, a moved epoch, a phase that changed underneath us, or a
     * read that failed all arrive here. Every one of them means stop; none of
     * them means the run is finished, and saying `done` over any of them is how
     * a scan certifies a project it barely looked at.
     */
    private static function refused($worked, $requeued, $blocked, $found, $phase)
    {
        return ['ok' => true, 'worked' => $worked, 'requeued' => $requeued,
                'blocked' => $blocked, 'findings' => $found, 'phase' => $phase,
                'done' => false, 'stop' => 'fenced',
                'why' => 'this scan could not take more work just now; it will continue where '
                       . 'it stopped'];
    }

    /** Records are still out with another worker. Resumable, and honest about why. */
    private static function waiting($worked, $requeued, $blocked, $found, $phase)
    {
        return ['ok' => true, 'worked' => $worked, 'requeued' => $requeued,
                'blocked' => $blocked, 'findings' => $found, 'phase' => $phase,
                'done' => false, 'stop' => 'waiting',
                'why' => 'some records are still being examined, or were left behind by a worker '
                       . 'that stopped; this scan will pick them up shortly'];
    }

    /**
     * One claimed batch, read stably and committed as a unit.
     *
     * @return array{ok:bool, worked:int, requeued:int, blocked:int, findings:int, why:?string}
     */
    private function batch($pid, $runId, $epoch, array $claimed)
    {
        $ids = [];
        // ordinal => claim token, the shape every write about these rows takes.
        // Built once, from the claim itself, so no later step can invent one.
        $claims = [];
        foreach ($claimed as $c) {
            $ids[] = $c['id_bin'];
            $claims[(int) $c['ordinal']] = isset($c['claim']) ? (int) $c['claim'] : 0;
        }

        $maxAttempts = isset($this->deps['attempts']) ? max(1, (int) $this->deps['attempts']) : 3;

        $fence = isset($this->deps['fence']) ? $this->deps['fence'] : null;
        $before = ($fence instanceof RecordVersions) ? $fence->versions($ids) : [];

        $read = $this->deps['read'];
        $got = $read($ids);
        if (empty($got['ok'])) {
            // A FAILED READ IS NOT AN EMPTY ONE. Committing these records as
            // examined-and-clean is the exact mistake the module was built to
            // prevent, so nothing is committed.
            //
            // BUT IT IS ALSO NOT A NON-EVENT, which is what this used to be. It
            // returned here without committing, without releasing the claim and
            // without counting an attempt: the rows stayed CLAIMED, the
            // straggler sweep could not see them for fifteen minutes, and the
            // phase machine correctly refused to advance over records nobody
            // had examined. One transient REDCap export failure therefore held
            // the project's only scan slot until somebody noticed. It funnels
            // through the same give-back as a refused commit now, and a record
            // whose read keeps failing eventually becomes a reported exclusion
            // rather than a permanent wait.
            return $this->giveBack($runId, $epoch, $claims, $maxAttempts,
                ScanStore::REC_UNREADABLE, 'read',
                isset($got['why']) ? $got['why']
                    : 'the records could not be read from the project just now');
        }
        $data = isset($got['data']) && is_array($got['data']) ? $got['data'] : [];

        $after = ($fence instanceof RecordVersions) ? $fence->versions($ids) : [];

        $batch = ['bytes' => 0, 'records' => [], 'findings' => [], 'candidates' => []];
        $worked = 0; $requeued = 0; $blocked = 0;

        foreach ($claimed as $c) {
            $id = $c['id_bin'];
            $tries = isset($c['attempts']) ? (int) $c['attempts'] : 0;
            // EVERY record row carries the token its claim gave it. The store
            // fences each state write on it and drops the findings of any row
            // whose token no longer matches, which is what stops one taken-over
            // record from killing the batch it travelled in.
            $claim = isset($c['claim']) ? (int) $c['claim'] : 0;

            $moved = ($fence instanceof RecordVersions)
                && (!array_key_exists($id, $before) || !array_key_exists($id, $after)
                    || $before[$id] !== $after[$id]);
            if ($moved) {
                // One more attempt, unless it has already had them all. A record
                // that is edited every time we look at it is a real fact about
                // the project, and reporting it is better than either retrying
                // forever or quietly leaving it out.
                if ($tries + 1 >= $maxAttempts) {
                    $batch['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                                           'state' => ScanStore::REC_UNSTABLE, 'version' => null,
                                           'claim' => $claim];
                    $blocked++;
                } else {
                    $batch['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                                           'state' => ScanStore::REC_PENDING, 'version' => null,
                                           'claim' => $claim];
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
                    $batch['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                                           'state' => ScanStore::REC_TOMBSTONE, 'version' => null,
                                           'claim' => $claim];
                    $blocked++;
                } else {
                    $batch['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                                           'state' => ScanStore::REC_PENDING, 'version' => null,
                                           'claim' => $claim];
                    $requeued++;
                }
                continue;
            }

            $ev = $this->evaluate($id, $data[$id]);
            if (!empty($ev['why'])) {
                // The record was read and could not be examined. Reported as
                // unreadable rather than as clean - H-05 in one line.
                $batch['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                                       'state' => ScanStore::REC_UNREADABLE, 'version' => null,
                                       'claim' => $claim];
                $blocked++;
                continue;
            }
            foreach ($ev['findings'] as $f) {
                // WHICH CLAIMED RECORD THIS CAME FROM. The store needs it to
                // decide whether to write the finding at all: evidence about a
                // record another worker has taken over is that worker's to
                // commit, and inserting our copy of it is what used to make the
                // unique key refuse the whole batch.
                $f['ordinal'] = $c['ordinal'];
                $batch['findings'][] = $f;
            }
            // A uniqueness rule produces a CANDIDATE rather than a finding: no
            // record can be a duplicate on its own evidence. They travel in the
            // same batch so that a rolled-back read leaves no candidate behind
            // to make some other record look like a duplicate of it.
            if (isset($ev['candidates']) && is_array($ev['candidates'])) {
                // $cand, NOT $c. The outer loop variable is the CLAIMED RECORD,
                // and reusing its name here overwrote it - so the record row
                // appended two lines below carried a candidate's fields and no
                // ordinal at all. Every record that produced a uniqueness
                // candidate was committed against the wrong ordinal, which on a
                // project with a @UVUNIQUE rule is most of them.
                foreach ($ev['candidates'] as $cand) {
                    if (!isset($cand['version'])) {
                        $cand['version'] = isset($after[$id]) ? $after[$id] : null;
                    }
                    $cand['ordinal'] = $c['ordinal'];
                    $batch['candidates'][] = $cand;
                }
            }
            $batch['bytes'] += isset($ev['bytes']) ? (int) $ev['bytes'] : 0;
            $batch['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                                   'state' => ScanStore::REC_DONE,
                                   'version' => isset($after[$id]) ? $after[$id] : null,
                                   'claim' => $claim];
            $worked++;
        }

        $ok = $this->store->commitBatch($runId, $this->owner(), $epoch, $batch);
        if ($ok !== true) {
            $why = is_string($ok) ? $ok
                 : 'this scan was stopped or taken over while these records were being '
                 . 'examined, so nothing from them was kept';
            // SAY IT SOMEWHERE AN ADMINISTRATOR CAN READ IT, before anything
            // else happens. The store has already redacted this sentence, and
            // it is the only place the reason a record ends up UNSTORED is
            // written down - by the time the give-back below has run, the row
            // says it was excluded and nothing says why.
            $this->noteBatchRefused($runId, $why);
            return $this->giveBack($runId, $epoch, $claims, $maxAttempts,
                ScanStore::REC_UNSTORED, 'fenced', $why);
        }
        return ['ok' => true, 'stop' => null, 'worked' => $worked, 'requeued' => $requeued,
                'blocked' => $blocked, 'findings' => count($batch['findings']),
                'candidates' => count($batch['candidates']), 'why' => null];
    }

    /**
     * Nothing was committed. Count the attempt, then hand the records back.
     *
     * THE ORDER IS THE FIX. Counting first means the count is written by a
     * transaction the failed one cannot roll back - which is the whole of B8:
     * `attempts` was incremented only inside commitBatch, so it moved only when
     * the commit had already succeeded. The retry cap was therefore unreachable
     * by the one path that needed it, and any persistent write error retried
     * without bound while holding the project's only scan slot.
     *
     * Releasing second means a record that has just run out of attempts is
     * already terminal by the time the release looks at it, so it is not handed
     * back into a loop it can no longer leave.
     *
     * BOTH PATHS COME HERE - a whole-batch read failure and a refused commit -
     * because they are the same shape: work was attempted, nothing was stored,
     * and the rows must not be left looking untouched.
     */
    private function giveBack($runId, $epoch, array $claims, $maxAttempts, $exhausted, $stop, $why)
    {
        $retired = 0;
        $note = $this->store->noteAttempts($runId, $epoch, $this->owner(), $claims,
                                           $maxAttempts, $exhausted);
        if (is_array($note) && isset($note['retired'])) $retired = (int) $note['retired'];
        // Whatever is left is handed straight back, so another worker can take
        // it now rather than in fifteen minutes' time. A row this call just
        // retired is terminal and is not released - see the method note.
        $this->store->releaseClaims($runId, $epoch, $this->owner(), $claims);
        return ['ok' => false, 'stop' => $stop, 'worked' => 0, 'requeued' => 0,
                'blocked' => $retired, 'findings' => 0, 'candidates' => 0, 'why' => $why];
    }

    /** Where a refused batch is written down. Wrapped: the log is a database too. */
    private function noteBatchRefused($runId, $why)
    {
        $note = isset($this->deps['note']) ? $this->deps['note'] : null;
        if (!is_callable($note)) return;
        try {
            $note('scan batch refused', ['run_id' => (int) $runId, 'detail' => (string) $why]);
        } catch (\Throwable $ignored) {
            // A failure while reporting a failure is not worth a second one.
        }
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
