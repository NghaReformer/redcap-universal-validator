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
    /**
     * Seconds between the duplicate phase's progress writes in one request
     * (review rv10b2-2). Well inside the two minutes after which the page
     * calls a run stalled, and far inside the reaper's staleHours.
     */
    const PROGRESS_EVERY = 30;

    /** @var ScanStore */
    private $store;
    /** @var array */
    private $deps;
    /** @var array run id => mark key => true: rule problems this worker has already committed */
    private $marked = [];
    /**
     * @var bool this request moved the run in a way no counter shows (review
     * rv5b-3): a catch-up page whose changes were all another group's, or a
     * batch whose every record left the group. work() answers `moved`.
     */
    private $walked = false;

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
     *   claimStale:     int           seconds before a claim's worker is taken to have
     *                                 stopped; 900 when absent. See claimStale().
     *   fingerprint:    string        the configuration as it is NOW
     *   policyRevision: int           the privacy policy as it is NOW
     *   slotTtl:        int
     *   slotLimit:      ?int          scan-system-max-concurrent-projects as resolved
     *                                 now; no slot numbered above it is taken. Absent
     *                                 is no limit beyond the rows the table holds.
     *   planner:        ?callable(int $runId, array $slice): array  lists one slice
     *                   of a run still planning - ScanPlanner::step() with the
     *                   source bound. $slice carries budget, owner, epoch,
     *                   attempts, leaseSeconds, keep and limits. Absent, a
     *                   planning run is "not in a phase that takes work".
     *   note:           ?callable(string $event, array $context): void
     *                                 where a storage failure is recorded. The
     *                                 worker's own answer never carries what
     *                                 the server said - see noteStorageFailure.
     *   clock:          ?callable(): float  seconds, for spacing the duplicate
     *                                 phase's progress writes (PROGRESS_EVERY);
     *                                 microtime(true) when absent
     *   scope:          ?callable(string[] $ids): array<string,?bool>  a
     *                                 group-scoped run's question: is each
     *                                 record in the run's group (review rv5b-1)
     *   planProblems:   ?array        the rule problems of the context `evaluate`
     *                                 was built from, {rule, fields, why}; marked
     *                                 with every batch that evaluated a record
     *                                 (review rv10b1-2)
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
     * `listed` counts what the finishing phases walked - change-log rows,
     * duplicate groups, summarised findings - so a request that moved a phase
     * along without examining a record still reads as movement (see loop()).
     * It is absent from the answers given before the loop starts.
     *
     * `moved` is present, and true, when the request moved the run in a way
     * no counter shows (review rv5b-3) - see $walked.
     *
     * @return array{ok:bool, worked:int, requeued:int, blocked:int, findings:int,
     *               listed?:int, moved?:bool, phase:string, done:bool, stop:?string,
     *               why:?string}
     */
    public function work($pid, $runId, array $opts = [])
    {
        $this->walked = false;
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
        // workers arriving together is not a race. Through the one finisher
        // Stop and the reap use (review R3U-3): this branch used to write the
        // run without its counts or its truncation, so the stored row depended
        // on which request ended the run.
        if ($phase === ScanPhase::CANCELLING) {
            ScanPromotion::finishCancelled($this->store, $pid, $runId, $this->limits());
            return ['ok' => true, 'worked' => 0, 'requeued' => 0, 'blocked' => 0, 'findings' => 0,
                    'phase' => ScanPhase::TERMINAL, 'done' => false, 'stop' => 'cancelled',
                    'why' => 'this scan was stopped, so the records after that point were never '
                           . 'examined'];
        }

        // PLANNING TAKES WORK NOW (B10): a slice of the record list, through the
        // planner the composition root hands in. It is not a phase a worker
        // may CLAIM records in - mayWork() still says no, and the manifest is
        // not frozen - so it is let through here by name, and only when there
        // is a planner to run it. Without one the old answer stands.
        $planner = (isset($this->deps['planner']) && is_callable($this->deps['planner']))
            ? $this->deps['planner'] : null;
        $planning = ($phase === ScanPhase::PLANNING && $planner !== null);
        if (!ScanPhase::mayWork($phase) && !$planning) {
            return ['ok' => true, 'worked' => 0, 'requeued' => 0, 'blocked' => 0, 'findings' => 0,
                    'phase' => $phase, 'done' => ($phase === ScanPhase::TERMINAL), 'stop' => null,
                    'why' => 'this scan is not in a phase that takes work'];
        }
        $epoch = (int) $run['lease_epoch'];

        // THE PRIVACY POLICY TIGHTENED. The run stores the policy it began
        // under, and continuing would keep writing value previews the project
        // has just decided it does not want. Tightening takes effect at once by
        // design; loosening waits for the next run.
        //
        // ASKED BEFORE THE FINGERPRINT (review R5-5, B7). The value mode is a
        // fingerprint input, so a tightened mode moves both, and a tightened
        // policy is a stop whatever else moved with it. With the fingerprint
        // asked first, the run that ScanService hands over to this guard was
        // ended failed instead.
        if (isset($this->deps['policyRevision'])
                && (int) $run['policy_revision'] !== (int) $this->deps['policyRevision']) {
            ScanPromotion::finishCancelled($this->store, $pid, $runId, array_merge($this->limits(),
                ['why' => 'the project\'s privacy settings were tightened while this scan was '
                        . 'running, so it was stopped before writing anything further']));
            return self::stop('this project\'s privacy settings changed during the scan, so it was '
                . 'stopped; run it again to get a report under the new settings');
        }

        // THE CONFIGURATION MOVED. A run cannot be half-checked against rules
        // that no longer exist and still describe the project, and there is no
        // partial claim that would be true - so it ends, and says so.
        //
        // THROUGH PROMOTION, the finisher ScanService uses for the same fact
        // (B7). This wrote derive(['failed' => true]) itself: a row with no
        // counts and a sentence of its own, so one event ended a run two ways
        // depending on which guard saw it first - the shape review R3U-3 removed
        // from the stopped runs. ScanService arms this guard with the
        // fingerprint it has just compared, so in production it agrees with the
        // service's check; a worker built anywhere else is held to it alone.
        if (isset($this->deps['fingerprint'])
                && !ScanPlanner::fingerprintMatches($run['fingerprint'], $this->deps['fingerprint'])) {
            // The evaluator revision, when the composition root says which it
            // is, so an upgrade is named as one (review rv10b1-6).
            $engine = isset($this->deps['engine']) && is_string($this->deps['engine']) ? $this->deps['engine'] : null;
            ScanPromotion::promote($this->store, $pid, $runId, array_merge($this->limits(),
                ['fingerprintNow' => (string) $this->deps['fingerprint'], 'engineNow' => $engine]));
            if ($engine !== null && ScanPromotion::engineOf($run) !== $engine) {
                return self::stop('this module was upgraded during this scan, so it was stopped and '
                    . 'must be run again');
            }
            return self::stop('the validation rules changed during this scan, so it was stopped '
                . 'and must be run again');
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
                // The installation's limit as it is NOW, so a lowered setting
                // holds from the next request (review R4-4): no slot numbered
                // above it is taken, whatever rows the table still has.
                $limit = isset($this->deps['slotLimit']) ? (int) $this->deps['slotLimit'] : null;
                $slot = $slots->acquire($this->owner(), $runId, $ttl, $limit);
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
            // One budget for the request, whichever phases it spends it on.
            if (!isset($this->deps['budget']) || !($this->deps['budget'] instanceof WorkBudget)) {
                $this->deps['budget'] = new WorkBudget();
            }

            // THE LIST FIRST, A SLICE PER REQUEST (B10), under the slot and the
            // renewal every other pass has (W1-N2). A slice is budgeted like a
            // batch, but one slow page can still outlast the slot's TTL, and
            // the renewal - asked once per page - is what keeps the semaphore
            // honest through it. The attempt cap is this worker's own
            // maxAttempts(): a planning slice that keeps dying is retired by the
            // rule a record is, not by a second one.
            $listed = 0;
            if ($planning) {
                $ttl = isset($this->deps['slotTtl']) ? (int) $this->deps['slotTtl'] : 300;
                $p = $planner($runId, [
                    'budget' => $this->deps['budget'], 'owner' => $this->owner(), 'epoch' => $epoch,
                    'attempts' => $this->maxAttempts(), 'leaseSeconds' => max(1, $ttl),
                    'keep' => $keep, 'limits' => $this->limits()]);
                if (!is_array($p) || !isset($p['phase'])) {
                    // An answer this worker cannot read is not a slice that
                    // listed nothing and is fine.
                    return ['ok' => false, 'worked' => 0, 'requeued' => 0, 'blocked' => 0,
                            'findings' => 0, 'listed' => 0, 'phase' => $phase, 'done' => false,
                            'stop' => 'read', 'why' => 'the project\'s record list could not be read'];
                }
                $listed = isset($p['listed']) ? max(0, (int) $p['listed']) : 0;
                if (empty($p['frozen']) || empty($p['ok'])) {
                    return ['ok' => !empty($p['ok']), 'worked' => 0, 'requeued' => 0, 'blocked' => 0,
                            'findings' => 0, 'listed' => $listed, 'phase' => (string) $p['phase'],
                            'done' => false, 'stop' => isset($p['stop']) ? $p['stop'] : null,
                            'why' => isset($p['why']) ? $p['why'] : null];
                }
                // Frozen in this request: the same request goes on to examine
                // records with what is left of its budget.
                $phase = (string) $p['phase'];
            }

            // The run_seq is what a duplicate finding is stamped with, so the
            // finalizer is bound to it below; a row without one binds nothing
            // and the finalizer then writes nothing (review R8-5).
            $seq = isset($run['run_seq']) ? (int) $run['run_seq'] : 0;
            $r = $this->loop($pid, $runId, $phase, $epoch,
                (int) $run['generation_id'], $seq, $opts, $keep);
            if ($listed > 0) $r['listed'] = (isset($r['listed']) ? (int) $r['listed'] : 0) + $listed;
            if ($this->walked) $r['moved'] = true;
            return $r;
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
    private function loop($pid, $runId, $phase, $epoch, $generationId, $runSeq, array $opts, $keep = null)
    {
        $budget = isset($this->deps['budget']) ? $this->deps['budget'] : new WorkBudget();
        $worked = 0; $requeued = 0; $blocked = 0; $found = 0; $stop = null; $why = null;
        $done = false;
        // WHAT THE FINISHING PHASES WALKED (review R2S-2): change-log rows,
        // duplicate groups and the records a verify page checked, findings
        // summarised. None of them is a record examined, so none moved worked,
        // findings or blocked, and a request that summarised a page and stayed
        // in the same phase looked to the browser like one that did nothing -
        // js/scan.js progressed() backed off to 30 s between requests. It
        // already counts `listed` as movement (planning lists records and
        // examines none), so the movement is reported there.
        $listed = 0;
        // When this request last moved progress_at in the duplicate phase,
        // by $this->now(); null until it has (review rv10b2-2).
        $clockAt = null;

        while (true) {
            // admit(), not mustStop() (review BU-2). Asked before the first
            // claim, mustStop() was a question about the host request: past
            // the reserve before the scan did anything, every request stopped
            // here, said "continues in the next one" and claimed nothing, and
            // the run never moved. admit() lets the first unit through when one
            // fits under the limit itself, and says plainly when even that
            // will not fit - ok:false, so the browser stops asking, because the
            // next request would arrive with the same footprint.
            $gate = $budget->admit();
            if ($gate !== null) {
                if (empty($gate['resumable'])) {
                    return ['ok' => false, 'worked' => $worked, 'requeued' => $requeued,
                            'blocked' => $blocked, 'findings' => $found, 'listed' => $listed,
                            'phase' => $phase, 'done' => false, 'stop' => $gate['stop'],
                            'why' => $gate['why']];
                }
                $stop = $gate['stop'];
                $why = $gate['why'];
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
                        'blocked' => $blocked, 'findings' => $found, 'listed' => $listed,
                        'phase' => $phase, 'done' => false, 'stop' => 'slot',
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
                            'blocked' => $blocked, 'findings' => $found, 'listed' => $listed,
                            'phase' => $phase, 'done' => false, 'stop' => 'unconfigured',
                            'why' => 'this scan has no way to decide duplicate values, so it '
                                   . 'stopped rather than reporting that it found none'];
                }
                // THE FINDINGS IT WRITES ARE THIS RUN'S. A finalizer that
                // writes is bound to the run's sequence here, the one place
                // that has the run row, so no caller can build one that dates
                // duplicates to some other run (review R8-5). And to the run
                // id and the lease epoch this request holds, so a write after
                // a cancel, a reap or another user's Start is refused rather
                // than added to a run already reported ended (review rv10a1-1).
                if ($fin instanceof SequencedFinalizer && $runSeq >= 1) {
                    $fin = $fin->forRun($runSeq, (int) $runId, (int) $epoch);
                }
                // TWO CLAIMS, because a step is one of two different things
                // and nobody knows which until it has run (review BU-1). A page
                // of group or candidate rows costs a kilobyte a row; a verify
                // page re-reads whole records through the scan's own read and
                // costs what a scanning batch costs. Handing both the one claim
                // let cheap pages double it to the maximum, and the verify page
                // after them re-read 500 records and died at memory_limit with
                // this worker's slot still leased.
                $mark = $budget->mark('groups');
                $r = $fin->step($generationId, $budget->claim('groups'), $budget->claim('records'));
                $found += $r['emitted'];
                $blocked += $r['collisions'];
                // Groups discovered, candidates verified, groups published:
                // movement that is neither a finding nor an exclusion.
                $listed += max(0, (int) $r['groups']) + max(0, (int) $r['verified'])
                         + (isset($r['published']) ? max(0, (int) $r['published']) : 0);
                // WHAT THE STEP MOVED. Forward is groups found, candidates
                // verified, findings written, groups published or decided
                // undecidable: the run nearer its end. A step that only re-read
                // records (a group restarted because a record changed) did
                // work and moved nothing forward.
                //
                // NOR DID VERIFYING AGAIN WHAT AN EARLIER EPOCH VERIFIED
                // (review rv10b2-1). A group restarted on a later page went
                // back over page 1, every candidate of it counted as verified,
                // and the clock moved on every request while the group
                // restarted for ever: the reaper never saw the run as idle, and
                // the browser, told something moved, asked again at once.
                // `revisited` is the part of `verified` below the group's
                // furthest point; only what lies past it is new ground.
                $revisited = isset($r['revisited']) ? max(0, (int) $r['revisited']) : 0;
                $forward = max(0, (int) $r['groups'])
                         + max(0, max(0, (int) $r['verified']) - $revisited)
                         + max(0, (int) $r['emitted']) + max(0, (int) $r['collisions'])
                         + (isset($r['published']) ? max(0, (int) $r['published']) : 0);
                $reread = isset($r['read']) ? max(0, (int) $r['read']) : 0;
                $stepWhy = isset($r['why']) && $r['why'] !== null ? (string) $r['why'] : null;
                // A STEP THAT DID NOTHING AND SAYS WHY IS AN ANSWER (R3S-N4).
                // The why used to be dropped and the step taken again, so a
                // finalizer that could not write (NO_SEQ, a group in a state
                // this build does not know) was retried until the request's
                // budget ended, and every request after it did the same with
                // nothing said. The next step would meet the same refusal, so
                // the loop stops and the browser is told what it was; ok:false,
                // as for a rollup page the lease refused.
                if (empty($r['done']) && $forward === 0 && $reread === 0 && $stepWhy !== null) {
                    return ['ok' => false, 'worked' => $worked, 'requeued' => $requeued,
                            'blocked' => $blocked, 'findings' => $found, 'listed' => $listed,
                            'phase' => $phase, 'done' => false, 'stop' => 'finalizer',
                            'why' => $stepWhy];
                }
                if ($r['done']) {
                    $next = ScanPhase::next($phase);
                    if ($next !== null && $this->store->advancePhase($runId, $epoch, $next)) {
                        $phase = $next;
                        continue;
                    }
                    $done = true;
                    break;
                }
                // THE PROGRESS CLOCK (W7-N9). This phase finishes no records,
                // so commitBatch() never moved progress_at for it, and the
                // finalizer writes nothing to the run row: a long
                // unique-finalize read as stalled to the page after two minutes
                // and as abandoned to the reaper after staleHours, while it
                // worked. A step that moved forward says so through the store,
                // fenced on the lease; one that only retried does not, so a
                // wedged finalizer still ages. Refused means this worker no
                // longer holds the run, which is the scanning phase's fence
                // refusal and gets its answer.
                //
                // AT MOST ONCE PER PROGRESS_EVERY SECONDS (review rv10b2-2).
                // Every forward step wrote it, and a write costs about three
                // statements through the framework (the UPDATE, its
                // ROW_COUNT(), and in the same second the SELECT that tells
                // "unchanged" from "refused"): 100 groups of two cost 301
                // writes and 290 such reads. The first forward step of a
                // request writes it, then one each PROGRESS_EVERY seconds.
                // Emit and publish no longer lean on this write to notice a
                // lost lease: each holds the run itself (review rv10a1-1).
                if ($forward > 0) {
                    $t = $this->now();
                    if ($clockAt === null || $t - $clockAt >= self::PROGRESS_EVERY) {
                        if (!$this->store->setProgressState($runId, $epoch, ['progress' => true])) {
                            return self::refused($worked, $requeued, $blocked, $found, $phase, $listed);
                        }
                        $clockAt = $t;
                    }
                }
                // A step that re-read records WAS a batch of records, and is
                // measured as one, so the next verify page is sized by what
                // re-reading records actually cost.
                $units = max(1, $r['verified'] + $r['emitted'] + $r['groups']);
                if ($reread > 0) {
                    $mark['kind'] = 'records';
                    $units = $reread;
                }
                $adj = $budget->observe($mark, $units);
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
                    $mark = $budget->mark('rows');
                    $r = $roll->step($pid, $runId, $epoch, $generationId, $budget->claim('rows'));
                    $listed += max(0, (int) $r['rows']);
                    if (!$r['done']) {
                        if ($r['rows'] === 0 && $r['why'] !== null) {
                            // The lease moved under us. Its page was discarded
                            // rather than counted twice; stopping is the rest of
                            // that decision.
                            return ['ok' => false, 'worked' => $worked, 'requeued' => $requeued,
                                    'blocked' => $blocked, 'findings' => $found,
                                    'listed' => $listed, 'phase' => $phase, 'done' => false,
                                    'stop' => 'fenced', 'why' => $r['why']];
                        }
                        $adj = $budget->observe($mark, max(1, $r['rows']));
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
                // With the attempt limit, so a row a dead worker left claimed is
                // charged the attempt it died on and handed out alone (see
                // SqlScanStore::claimPending()).
                //
                // AND WITH WHAT IT RETIRED (review R2S-1). A row out of
                // attempts is retired inside the claim, never reaches a batch,
                // and was reported by nobody: the request said blocked=0 while
                // records became blocking exclusions. They are counted here and
                // written down where an administrator can read why.
                $retired = 0;
                $claimed = $this->store->claimPending($runId, $this->owner(), $epoch,
                                                      $budget->claim('records'), $this->claimStale(),
                                                      $this->maxAttempts(), ScanStore::REC_UNREADABLE,
                                                      $retired);
                if ((int) $retired > 0) {
                    $blocked += (int) $retired;
                    $this->noteRetired($runId, (int) $retired);
                }
                if ($claimed === false) return self::refused($worked, $requeued, $blocked,
                                                             $found, $phase, $listed);
                if (!$claimed && (int) $retired > 0) {
                    // A CALL THAT ONLY RETIRED SAYS NOTHING ABOUT WHAT IS LEFT
                    // (review R2S-5). The store walks a bounded number of
                    // windows of dead rows per call, so [] here can mean it
                    // stopped looking, not that it found nothing to hand out.
                    // Reading it as "records are still out with another
                    // worker" backed the browser off over rows that were free.
                    // Every such call made rows terminal, so asking again
                    // always moves, and the budget still decides when to stop.
                    continue;
                }
                if (!$claimed) {
                    // NOT WHILE RECORDS ARE STILL OUT. Catch-up sweeps
                    // stragglers BY STATE, and a row claimed by a worker that
                    // has not committed is invisible to that sweep until its
                    // claim goes stale. Advancing here abandons it: the live
                    // pilot reached rollup-finalize with 3 of 39 examined and
                    // told the client it was done.
                    if (!$this->store->manifestComplete($runId)) {
                        return self::waiting($worked, $requeued, $blocked, $found, $phase,
                                             $listed);
                    }
                    $cu = isset($this->deps['catchup']) ? $this->deps['catchup'] : null;
                    if ($cu instanceof CatchUp) {
                        $mark = $budget->mark('changes');
                        $r = $cu->step($pid, $runId, $epoch, $budget->claim('changes'));
                        // What the reader is shown is the in-scope part of
                        // the page; the budget is told the whole page, which
                        // is what it cost (review R5-1).
                        $listed += max(0, (int) $r['listed']);
                        // The cursor moved even when no change on the page
                        // was the reader's to count (review rv5b-3).
                        if (!empty($r['moved'])) $this->walked = true;
                        if (!$r['done']) {
                            $adj = $budget->observe($mark, max(1, $r['scanned']));
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
                $claimed = $this->store->claim($runId, $this->owner(), $epoch,
                                               $budget->claim('records'));
            }

            if ($claimed === false) {
                return self::refused($worked, $requeued, $blocked, $found, $phase, $listed);
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
                    return self::waiting($worked, $requeued, $blocked, $found, $phase, $listed);
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

            $mark = $budget->mark('records');
            $r = $this->batch($pid, $runId, $epoch, $claimed, $budget);
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
                        'listed' => $listed, 'phase' => $phase, 'done' => false,
                        'stop' => isset($r['stop']) && $r['stop'] !== null ? $r['stop'] : 'fenced',
                        'why' => $r['why']];
            }
            $worked   += $r['worked'];
            $requeued += $r['requeued'];
            $blocked  += $r['blocked'];
            $found    += $r['findings'];
            // Records that left the group are progress no counter shows.
            if (!empty($r['departed'])) $this->walked = true;

            $adj = $budget->observe($mark,
                max(1, isset($r['examined']) ? (int) $r['examined'] : count($claimed)));
            // A RECORD DROPPED BESIDE OTHERS IS TRIED AGAIN ALONE, now (review
            // R2S-3). It went back uncharged and the batch's read data is
            // gone; the budget takes records one at a time for the rest of the
            // request and lets the next one through whatever it measures, so
            // the retry cannot be lost to the stop this batch would otherwise
            // have earned - see WorkBudget::alone().
            if (isset($r['cut']) && $r['cut'] === 'retry') {
                $budget->alone('records');
                continue;
            }
            // A batch memory cut short ends the request: what it examined is
            // committed and the rest is back in the queue, and this request is
            // the one that is short of memory.
            if (!empty($r['cut'])) {
                $stop = 'memory';
                $why = $r['why'];
                break;
            }
            if ($adj['stop'] !== null) {
                $stop = $adj['stop'];
                $why = $adj['why'];
                break;
            }
        }

        return ['ok' => true, 'worked' => $worked, 'requeued' => $requeued, 'blocked' => $blocked,
                'findings' => $found, 'listed' => $listed, 'phase' => $phase, 'done' => $done,
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
    private static function refused($worked, $requeued, $blocked, $found, $phase, $listed = 0)
    {
        return ['ok' => true, 'worked' => $worked, 'requeued' => $requeued,
                'blocked' => $blocked, 'findings' => $found, 'listed' => $listed,
                'phase' => $phase, 'done' => false, 'stop' => 'fenced',
                'why' => 'this scan could not take more work just now; it will continue where '
                       . 'it stopped'];
    }

    /** Records are still out with another worker. Resumable, and honest about why. */
    private static function waiting($worked, $requeued, $blocked, $found, $phase, $listed = 0)
    {
        return ['ok' => true, 'worked' => $worked, 'requeued' => $requeued,
                'blocked' => $blocked, 'findings' => $found, 'listed' => $listed,
                'phase' => $phase, 'done' => false, 'stop' => 'waiting',
                'why' => 'some records are still being examined, or were left behind by a worker '
                       . 'that stopped; this scan will pick them up shortly'];
    }

    /**
     * One claimed batch, read stably and committed as a unit.
     *
     * $budget is told what is held after the read and after each record
     * (WorkBudget::sample()). Below PHP 8.2 that is the only in-batch figure:
     * the peak is request-wide, and a batch that stays under an earlier
     * high-water mark shows nothing by the time it is observed.
     *
     * @return array{ok:bool, worked:int, requeued:int, blocked:int, findings:int, why:?string}
     */
    private function batch($pid, $runId, $epoch, array $claimed, ?WorkBudget $budget = null)
    {
        $ids = [];
        // ordinal => claim token, the shape every write about these rows takes.
        // Built once, from the claim itself, so no later step can invent one.
        $claims = [];
        foreach ($claimed as $c) {
            $ids[] = $c['id_bin'];
            $claims[(int) $c['ordinal']] = isset($c['claim']) ? (int) $c['claim'] : 0;
        }

        $maxAttempts = $this->maxAttempts();

        $fence = isset($this->deps['fence']) ? $this->deps['fence'] : null;
        $before = ($fence instanceof RecordVersions) ? $fence->versions($ids) : [];

        // EACH RECORD'S GROUP, ASKED BEFORE IT IS READ (review rv5b-1). A
        // group-scoped run's list is the group as planning read it, and a
        // record moved to another group since then is that group's record:
        // it leaves the run unread. Asked between the two version reads, so
        // a move during the read moves the version and the record is
        // requeued by the stable-read rule below rather than decided on a
        // stale answer. Cannot tell is set aside with an attempt counted, so
        // a group that can never be told ends as a reported exclusion.
        // Only a scoped run has the dependency; an unscoped one asks nothing.
        $group = [];
        if (isset($this->deps['scope']) && is_callable($this->deps['scope'])) {
            try {
                $ans = call_user_func($this->deps['scope'], $ids);
            } catch (\Throwable $e) {
                $ans = [];
            }
            foreach ($ids as $id) {
                $v = (is_array($ans) && array_key_exists((string) $id, $ans)) ? $ans[(string) $id] : null;
                $group[(string) $id] = ($v === true) ? true : (($v === false) ? false : null);
            }
        }
        $toRead = [];
        foreach ($ids as $id) {
            if (!$group || $group[(string) $id] === true) $toRead[] = $id;
        }

        $read = $this->deps['read'];
        $got = ($toRead || !$group) ? $read($toRead) : ['ok' => true, 'data' => [], 'why' => null];
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
        if ($budget !== null) $budget->sample(false);

        $after = ($fence instanceof RecordVersions) ? $fence->versions($ids) : [];

        $batch = ['bytes' => 0, 'records' => [], 'findings' => [], 'candidates' => [], 'marks' => []];
        $worked = 0; $requeued = 0; $blocked = 0;
        // ordinal => which of the three counters its row adds to, so the
        // counters can be taken again over the rows the commit actually held.
        $outcome = [];

        $moved = function ($id) use ($fence, $before, $after) {
            return ($fence instanceof RecordVersions)
                && (!array_key_exists($id, $before) || !array_key_exists($id, $after)
                    || $before[$id] !== $after[$id]);
        };
        // WHICH OF THE RECORDS THE READ LEFT OUT ARE REALLY GONE. Asked once for
        // the whole batch, and only about records on their LAST attempt: below
        // the cap the answer changes nothing, because the record is tried again
        // either way.
        $lastChance = [];
        foreach ($claimed as $c) {
            $id = $c['id_bin'];
            if ($moved($id)) continue;
            if ($group && $group[(string) $id] !== true) continue;    // not read: see above
            if (array_key_exists($id, $data) && is_array($data[$id])) continue;
            $tries = isset($c['attempts']) ? (int) $c['attempts'] : 0;
            if ($tries + 1 >= $maxAttempts) $lastChance[] = $id;
        }
        $gone = $this->confirmedGone($lastChance);
        // Rule problems this batch met and this worker has not yet recorded,
        // keyed so one broken rule met by every record in the batch is one mark.
        $marks = [];

        // CUT SHORT BY MEMORY (review EE-1). The batch was sized before it was
        // claimed, and the first batch of a request has nothing behind it, so
        // the budget is asked after every record whether the next one still
        // fits - see WorkBudget::sample(). 'stop' keeps this record and takes
        // no more; 'drop' lets this record's result go as well, and becomes
        // 'retry' when other records' read data was held beside it (R2S-3).
        // Both shapes are ordinal => claim token, as giveBack() and
        // releaseClaims() take them.
        $cut = null;
        $setAside = [];    // evaluated, not kept: an attempt is counted against it
        $untouched = [];   // never reached: handed straight back, no attempt

        // THE DETAIL BUDGET GATES HERE (H9), between evaluate() and the commit.
        // ScanPolicy::budgetSpent() had no caller: every finding was stored,
        // and only promotion noticed afterwards that the run had gone past
        // the setting - which says a run "keeps COUNTING every finding and
        // stops storing detail". Evaluation is untouched, so every finding is
        // still found and counted; past the budget a finding is left out of
        // the batch and its record's count of them travels as `unstored`,
        // which the store adds to ScanStore::DETAIL_UNSTORED for the records
        // it holds. Once spent, nothing more is stored for the rest of the
        // run, so a run's stored findings are a prefix and not a sample.
        //
        // ONE OVERCOUNT, IN THE DIRECTION THAT CANNOT CERTIFY. A record that
        // changes during the run is examined again in catch-up. When its
        // findings were stored the first time, commitBatch() closes them and
        // these are counted in their place; when they were left out the first
        // time, they are counted again, because a count has no row to close.
        // Only records edited after the budget was spent are affected, and the
        // run is already truncated and so never clean.
        //
        // WHAT THE RUN HAS STORED IS READ FOR EVERY BATCH (review rv10b1-4),
        // from the run row, after this batch's records were read. It was read
        // once, when the request began, and moved only by this worker's own
        // commits, so a second worker's request running beside it stored a
        // whole request of findings past the budget - the budget times the
        // workers, where W10-N2 said a batch each. Read here, it holds what
        // every worker had committed by the time this batch is judged; two
        // batches judged at the same moment can still both store theirs,
        // which is the batch each. One read of the run row by its key. A row
        // that cannot be read leaves no room: the commit after it is refused
        // anyway, and an unread counter is never taken for room left.
        $limits = $this->limits();
        $gated = isset($limits['maxFindings']) || isset($limits['maxBytes']);
        $rowsNow = 0;
        $bytesNow = 0;
        if ($gated) {
            $now = $this->store->run($pid, $runId);
            if (is_array($now)) {
                $rowsNow = isset($now['detail_rows']) ? max(0, (int) $now['detail_rows']) : 0;
                $bytesNow = isset($now['detail_bytes']) ? max(0, (int) $now['detail_bytes']) : 0;
            } else {
                $rowsNow = PHP_INT_MAX;
                $bytesNow = PHP_INT_MAX;
            }
        }
        $unstored = [];    // ordinal => findings found and left out

        foreach ($claimed as $c) {
            $id = $c['id_bin'];
            $tries = isset($c['attempts']) ? (int) $c['attempts'] : 0;
            // EVERY record row carries the token its claim gave it. The store
            // fences each state write on it and drops the findings of any row
            // whose token no longer matches, which is what stops one taken-over
            // record from killing the batch it travelled in.
            $claim = isset($c['claim']) ? (int) $c['claim'] : 0;

            if ($cut !== null) {
                $untouched[(int) $c['ordinal']] = $claim;
                continue;
            }

            if ($moved($id)) {
                // One more attempt, unless it has already had them all. A record
                // that is edited every time we look at it is a real fact about
                // the project, and reporting it is better than either retrying
                // forever or quietly leaving it out.
                if ($tries + 1 >= $maxAttempts) {
                    $batch['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                                           'state' => ScanStore::REC_UNSTABLE, 'version' => null,
                                           'claim' => $claim];
                    $blocked++;
                    $outcome[(int) $c['ordinal']] = 'blocked';
                } else {
                    $batch['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                                           'state' => ScanStore::REC_PENDING, 'version' => null,
                                           'claim' => $claim];
                    $requeued++;
                    $outcome[(int) $c['ordinal']] = 'requeued';
                }
                continue;
            }

            if ($group && $group[(string) $id] === false) {
                // In another group now, or in none, and its version held
                // still while that was asked: it leaves the run, unread, and
                // the store closes what the run found in it before.
                $batch['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                                       'state' => ScanStore::REC_DEPARTED, 'version' => null,
                                       'claim' => $claim];
                $outcome[(int) $c['ordinal']] = 'departed';
                continue;
            }
            if ($group && $group[(string) $id] === null) {
                // Its group could not be told. Not read and not decided: one
                // attempt counted, after the commit, as for a set-aside record.
                $setAside[(int) $c['ordinal']] = $claim;
                continue;
            }

            if (!array_key_exists($id, $data) || !is_array($data[$id])) {
                // Asked for and not returned. Either it was deleted during the
                // run, or the read is wrong about it - and the read being wrong
                // is worth another attempt first.
                //
                // OUT OF ATTEMPTS, THE TWO ARE NOT THE SAME STATE. This wrote a
                // TOMBSTONE for both, on the strength of an absence: an export
                // that dropped a record three times running - a rights filter, a
                // truncated response, an id the export normalises differently -
                // recorded it as deleted. A tombstone does not block coverage,
                // so the run went on to certify a project with a record in it
                // that nobody examined. A tombstone is now a CONFIRMED deletion;
                // anything less is a record that could not be read, which blocks.
                if ($tries + 1 >= $maxAttempts) {
                    $batch['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                                           'state' => isset($gone[(string) $id])
                                               ? ScanStore::REC_TOMBSTONE : ScanStore::REC_UNREADABLE,
                                           'version' => null, 'claim' => $claim];
                    $blocked++;
                    $outcome[(int) $c['ordinal']] = 'blocked';
                } else {
                    $batch['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                                           'state' => ScanStore::REC_PENDING, 'version' => null,
                                           'claim' => $claim];
                    $requeued++;
                    $outcome[(int) $c['ordinal']] = 'requeued';
                }
                continue;
            }

            $ev = $this->evaluate($id, $data[$id]);
            $verdict = ($budget !== null) ? $budget->sample() : null;
            if ($verdict === 'drop') {
                // Its findings go first, before anything else asks for memory.
                // It is not written as examined, so it cannot be certified.
                $ev = null;
                if (count($data) > 1) {
                    // NOT ALONE, SO NOT ITS FAULT ALONE (review R2S-3). The
                    // arena sample() compared with the limit holds every
                    // record this batch read, and charging this one for its
                    // neighbours' data excluded healthy records one request
                    // at a time: the batch was rebuilt the same way each time
                    // and the same first record charged. It goes back
                    // uncharged with the rest, the read data is freed below,
                    // and the loop retries it alone - see WorkBudget::alone().
                    $untouched[(int) $c['ordinal']] = $claim;
                    $cut = 'retry';
                    continue;
                }
                // ALONE, the drop is the record's own: it is counted as
                // attempted, so it cannot loop. See below.
                $setAside[(int) $c['ordinal']] = $claim;
                $cut = 'drop';
                continue;
            }
            if ($verdict === 'stop') $cut = 'stop';
            if (!empty($ev['why'])) {
                // The record was read and could not be examined. Reported as
                // unreadable rather than as clean - H-05 in one line.
                $batch['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                                       'state' => ScanStore::REC_UNREADABLE, 'version' => null,
                                       'claim' => $claim];
                $blocked++;
                $outcome[(int) $c['ordinal']] = 'blocked';
                continue;
            }
            // A record's value bytes are evaluate()'s figure while all of its
            // findings are kept, and the kept findings' previews once any is
            // left out: the counter must not grow by bytes nobody stored.
            $recBytes = isset($ev['bytes']) ? (int) $ev['bytes'] : 0;
            $left = 0;
            $keptBytes = 0;
            foreach ($ev['findings'] as $f) {
                if ($gated && ScanPolicy::budgetSpent($limits, $rowsNow, $bytesNow)) {
                    $left++;
                    continue;
                }
                $cost = (isset($f['value_bin']) && is_string($f['value_bin'])) ? strlen($f['value_bin']) : 0;
                $rowsNow++;
                $bytesNow += $cost;
                $keptBytes += $cost;
                // WHICH CLAIMED RECORD THIS CAME FROM. The store needs it to
                // decide whether to write the finding at all: evidence about a
                // record another worker has taken over is that worker's to
                // commit, and inserting our copy of it is what used to make the
                // unique key refuse the whole batch.
                $f['ordinal'] = $c['ordinal'];
                $batch['findings'][] = $f;
            }
            if ($left > 0) {
                $unstored[(int) $c['ordinal']] = $left;
                $recBytes = $keptBytes;
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
            // WHAT THE EVALUATION COULD NOT EVALUATE. This was returned by every
            // call and read by nobody: a rule that fails at run time - a
            // reference that resolves on no event, a budget exhausted - left the
            // records it met looking examined and the run able to say clean.
            // Deduped three times over, because the same rule is met by every
            // record: within the batch here, within this worker by $this->marked,
            // and across workers by the store, where a mark is a presence.
            if (isset($ev['problems']) && is_array($ev['problems']) && $ev['problems']) {
                foreach (ScanPromotion::marksFor($ev['problems']) as $m) {
                    $k = $m['axis1'] . "\0" . $m['axis2'];
                    if (isset($this->marked[$runId][$k])) continue;
                    $marks[$k] = $m;
                }
            }
            $batch['bytes'] += $recBytes;
            $batch['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                                   'state' => ScanStore::REC_DONE,
                                   'version' => isset($after[$id]) ? $after[$id] : null,
                                   'claim' => $claim];
            $worked++;
            $outcome[(int) $c['ordinal']] = 'worked';
        }

        // A 'stop' on the last record cut nothing off. The budget still hears
        // what the batch cost when it is observed; the request is not told a
        // batch was cut short that was not.
        if ($cut === 'stop' && !$untouched) $cut = null;

        // THE READ DATA IS NOT NEEDED PAST HERE, and it is the largest thing
        // this batch holds: let it go before the commit asks for memory, and
        // before a record the batch dropped is retried alone (R2S-3). $got
        // holds the same array, so it goes too.
        $ev = null;
        $data = null;
        $got = null;
        if ($cut === 'retry' && $budget !== null) $budget->reclaim();

        // THE CONTEXT'S OWN PROBLEMS GO WITH EVERY BATCH IT EVALUATED (review
        // rv10b1-2). start() noted the problems of the plan it was built from
        // and nothing else did, so a rule this request's context could not
        // evaluate was left out of these records with nothing written to say
        // so, and the run could finish clean. Behind the record fences, like
        // the records' own problems; a mark is a presence, so a problem start()
        // already noted adds nothing.
        if ($worked > 0) {
            foreach ($this->planMarks() as $k => $m) {
                if (!isset($this->marked[$runId][$k])) $marks[$k] = $m;
            }
        }
        $batch['marks'] = array_values($marks);
        $batch['unstored'] = $unstored;
        // null when the store does not say how many records it still held (a
        // store written before the out-parameter existed); 0 when it held none.
        // The same for WHICH it held.
        $held = null;
        $heldOrd = null;
        $ok = $this->store->commitBatch($runId, $this->owner(), $epoch, $batch, $held, $heldOrd);
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
            // Records a cut kept the batch from reaching were never attempted,
            // so they go back without an attempt counted against them.
            $out = $this->giveBack($runId, $epoch, array_diff_key($claims, $untouched), $maxAttempts,
                ScanStore::REC_UNSTORED, 'fenced', $why);
            if ($untouched) $this->store->releaseClaims($runId, $epoch, $this->owner(), $untouched);
            return $out;
        }
        // Remembered only once COMMITTED. A refused batch wrote nothing, so the
        // next one must offer these again.
        //
        // AND ONLY WHEN THE COMMIT HELD A RECORD (review ST-4). A batch whose
        // every claim was taken by another worker commits as `true` and writes
        // no marks, because the marks travel behind the record fences. Taking
        // `true` alone as "written" skipped those marks for the rest of the
        // request; if the other worker's own commit then failed, the problem
        // was never recorded and the run could claim clean.
        if ($held !== 0) {
            foreach ($marks as $k => $m) $this->marked[$runId][$k] = true;
        }

        // WHAT THE COMMIT HELD, NOT WHAT WAS SENT (review R2S-1). A record
        // taken over while this worker evaluated it - by a second tab's
        // straggler sweep, say - is committed by its new holder, and this
        // commit drops it. Counting it here as well reported it twice: the
        // worker that lost every record of its batch answered worked=4 for a
        // commit that wrote nothing. So the counters are taken again over the
        // rows the store says it kept. A store that does not say keeps the old
        // reading, as $held does above.
        //
        // FINDINGS ARE WHAT WAS FOUND, stored or not (H9): the ones the detail
        // budget left out are counted with them, for the held records only.
        // What the run has stored is the next batch's read of the run row.
        $findings = count($batch['findings']) + array_sum($unstored);
        $candidates = count($batch['candidates']);
        $keep = null;
        $departed = count(array_keys($outcome, 'departed', true));
        if (is_array($heldOrd)) {
            $keep = array_flip(array_map('intval', $heldOrd));
            $tally = ['worked' => 0, 'requeued' => 0, 'blocked' => 0, 'departed' => 0];
            foreach ($outcome as $o => $k) {
                if (isset($keep[$o])) $tally[$k]++;
            }
            $departed = $tally['departed'];
            $worked = $tally['worked'];
            $requeued = $tally['requeued'];
            $blocked = $tally['blocked'];
            $findings = 0;
            foreach ($batch['findings'] as $f) {
                if (isset($keep[(int) $f['ordinal']])) $findings++;
            }
            foreach ($unstored as $o => $k) {
                if (isset($keep[$o])) $findings += $k;
            }
            $candidates = 0;
            foreach ($batch['candidates'] as $f) {
                if (isset($keep[(int) $f['ordinal']])) $candidates++;
            }
        }
        // WHAT THE CUT LEFT, settled after the commit and outside it. A record
        // set aside WAS attempted: its attempt is counted in a transaction of
        // its own (noteAttempts, the same door a failed read or a refused
        // commit uses, which hands it back in that same write), so a record
        // whose findings never fit runs out of attempts and ends as one that
        // could not be examined - reported and blocking - instead of being
        // handed back for ever. Records never reached are handed back as they
        // were, with no attempt.
        if ($setAside) {
            $note = $this->store->noteAttempts($runId, $epoch, $this->owner(), $setAside,
                                               $maxAttempts, ScanStore::REC_UNREADABLE);
            if (is_array($note) && isset($note['retired'])) $blocked += (int) $note['retired'];
        }
        if ($untouched) {
            $this->store->releaseClaims($runId, $epoch, $this->owner(), $untouched);
        }
        // 'retry' carries no sentence: the request is not stopping, it is
        // taking the dropped record again, alone.
        return ['ok' => true, 'stop' => null, 'worked' => $worked, 'requeued' => $requeued,
                'blocked' => $blocked, 'findings' => $findings, 'candidates' => $candidates,
                'departed' => $departed,
                'examined' => count($claimed) - count($untouched), 'cut' => $cut,
                'why' => $cut === 'drop' ? WorkBudget::SET_ASIDE
                       : ($cut === 'stop' ? WorkBudget::CUT_SHORT : null)];
    }

    /**
     * Of these record ids, the ones the project confirms it no longer holds.
     *
     * ONLY AN EXPLICIT `false` CONFIRMS. No resolver, a resolver that throws, an
     * answer that is not an array, an id the answer leaves out, a truthy value,
     * a null: every one of those is "cannot tell", and cannot tell is NOT GONE.
     * The cost of that direction is a record reported as unreadable when it was
     * in fact deleted, which caps one run's coverage; the other direction drops
     * a live record out of a report that then says it covered the project.
     *
     * @param string[] $ids
     * @return array<string,bool> id => true, for the confirmed ones only
     */
    private function confirmedGone(array $ids)
    {
        if (!$ids) return [];
        $probe = isset($this->deps['exists']) ? $this->deps['exists'] : null;
        if (!is_callable($probe)) return [];
        try {
            $answer = $probe($ids);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($answer)) return [];
        $gone = [];
        foreach ($ids as $id) {
            $k = (string) $id;
            if (array_key_exists($k, $answer) && $answer[$k] === false) $gone[$k] = true;
        }
        return $gone;
    }

    /** How many attempts a record gets before it is retired as one that could not be examined. */
    private function maxAttempts()
    {
        return isset($this->deps['attempts']) ? max(1, (int) $this->deps['attempts']) : 3;
    }

    /**
     * Nothing was committed. Count the attempt and hand the records back.
     *
     * COUNTING IS THE FIX. It is written by a transaction the failed one cannot
     * roll back - which is the whole of B8: `attempts` was incremented only
     * inside commitBatch, so it moved only when the commit had already
     * succeeded. The retry cap was therefore unreachable by the one path that
     * needed it, and any persistent write error retried without bound while
     * holding the project's only scan slot.
     *
     * AND THE HAND-BACK IS THE SAME WRITE (review R2S-4). A record that has
     * just run out of attempts is retired, and whatever is left is released so
     * another worker can take it now rather than in fifteen minutes' time -
     * both inside noteAttempts(). They were two transactions, and a release
     * that failed, or a takeover between them, left a counted row still
     * stamped: the straggler sweep later found it stale and charged the same
     * failure a second time.
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
        return ['ok' => false, 'stop' => $stop, 'worked' => 0, 'requeued' => 0,
                'blocked' => $retired, 'findings' => 0, 'candidates' => 0, 'why' => $why];
    }

    /**
     * How old a claim must be before its worker is taken to have stopped.
     *
     * Fifteen minutes unless the composition root says otherwise: long enough
     * that a live batch is never mistaken for a dead one, short enough that a
     * crash costs a delay rather than the run. See ScanStore::claimPending().
     */
    private function claimStale()
    {
        return isset($this->deps['claimStale']) ? max(1, (int) $this->deps['claimStale']) : 900;
    }

    /**
     * Where records retired by the straggler sweep are written down.
     *
     * The worker that left them died without saying so, and the sweep that
     * retired them examined nothing - so this line is the only record of why
     * they became exclusions. Wrapped, as noteBatchRefused() is.
     */
    private function noteRetired($runId, $n)
    {
        $note = isset($this->deps['note']) ? $this->deps['note'] : null;
        if (!is_callable($note)) return;
        try {
            $note('scan records retired', ['run_id' => (int) $runId,
                'detail' => $n . ' record(s) left claimed by a worker that stopped had no attempts '
                          . 'left, so they were reported as not examined rather than tried again']);
        } catch (\Throwable $ignored) {
            // A failure while reporting a failure is not worth a second one.
        }
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

    /**
     * The detail budget a run this worker ends as cancelled is judged against
     * (review R3U-3): ScanService hands the numbers it gives promote(), so the
     * stopped row is truncated or not whichever request ends it. [] when the
     * caller did not say, which judges the run against no budget.
     */
    private function limits()
    {
        return (isset($this->deps['limits']) && is_array($this->deps['limits'])) ? $this->deps['limits'] : [];
    }

    /** Seconds, from the `clock` dep when a test gives one. */
    private function now()
    {
        $c = isset($this->deps['clock']) ? $this->deps['clock'] : null;
        return is_callable($c) ? (float) $c() : microtime(true);
    }

    private function owner()
    {
        return isset($this->deps['owner']) ? (string) $this->deps['owner'] : 'worker';
    }

    /** The context's rule problems as marks, keyed as batch() keys its own. */
    private function planMarks()
    {
        $p = (isset($this->deps['planProblems']) && is_array($this->deps['planProblems']))
           ? $this->deps['planProblems'] : [];
        $out = [];
        foreach (ScanPromotion::marksFor($p) as $m) $out[$m['axis1'] . "\0" . $m['axis2']] = $m;
        return $out;
    }

    private static function stop($why)
    {
        return ['ok' => false, 'worked' => 0, 'requeued' => 0, 'blocked' => 0, 'findings' => 0,
                'phase' => ScanPhase::TERMINAL, 'done' => true, 'stop' => 'refused', 'why' => $why];
    }
}
