<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * The storage contract, and the invariants a correct implementation must hold.
 *
 * WHY AN INTERFACE AT ALL, WITH ONE PRODUCTION IMPLEMENTATION. Not for
 * pluggability — for the fake. Every behaviour that is decidable without a
 * database (state transitions, refusal to promote, retention arithmetic) is
 * tested against ArrayScanStore in the ordinary suite; only the invariants that
 * genuinely need InnoDB under two connections go to the database matrix. The
 * split is what keeps the slow suite small enough that it actually gets run.
 *
 * THE INVARIANTS. These are stated here because they belong to the CONTRACT, not
 * to one implementation, and because a future store that quietly drops one would
 * still satisfy every method signature:
 *
 *  I1  At most one active run per project. Enforced by the storage engine, never
 *      by a read-then-write check, which is a race by construction.
 *  I2  A batch's findings, its uniqueness candidates and its record states
 *      commit in ONE transaction, opened by a LOCKING READ of the run row that
 *      is held for the life of that transaction. The epoch, the cancellation
 *      flag and the terminal state are then compared in PHP, where "unchanged"
 *      is not mistaken for "absent".
 *
 *      IT IS NOT A COMPARE-AND-SET ON A CURSOR, and it must not become one.
 *      This invariant used to say "cursor last, conditioned on its own old
 *      value ... zero affected rows means roll everything back", which
 *      described neither implementation: there is no cursor advance in
 *      commitBatch at all (claim() moves it), and a CAS judged by affected rows
 *      is the exact bug documented at SqlScanStore.php:24-31 - MySQL reports
 *      rows CHANGED, not rows MATCHED, so writing a value that already held
 *      reports zero and rolls back a good batch. The parameter that carried the
 *      expected cursor was read by neither store and by no test; it is gone
 *      rather than implemented.
 *  I2b Within that transaction the fence is also PER RECORD. A batch may only
 *      write the rows it still holds - see the claim token on claim() - and it
 *      writes only the findings belonging to those rows. A run-wide fence
 *      cannot express this: it is either open, in which case a taken-over
 *      record's stale findings are inserted over the new holder's and the
 *      unique key kills the whole batch, or it is closed, in which case the
 *      records nobody took are discarded with it.
 *  I3  Nothing marks a record done except the transaction that scanned it. So a
 *      lost batch, a crash, a retry and an OOM are indistinguishable from "not
 *      attempted", which is the only safe reading.
 *  I3b An ATTEMPT, however, is counted for work that was attempted, not for
 *      work that was committed - so it is written by a transaction the failure
 *      cannot roll back. Counting it inside the batch made the retry cap
 *      unreachable by construction: the only path that incremented it was the
 *      one that had already succeeded, so a batch the database refused was
 *      retried without bound and the run never became terminal.
 *  I4  Completeness is a PREDICATE over record states, never an accumulated
 *      counter. A counter can be incremented twice; a predicate cannot.
 *  I5  A finding has one active version per identity per generation. Closing is
 *      an update to the old row, never a delete, so an "as of run N" view stays
 *      reproducible.
 *  I6  Every write that could disclose is bounded by the run's stored policy
 *      revision. A policy that tightened mid-run invalidates leases and blocks
 *      preview reads before the purge has even started.
 *
 * PHP 7.4: interface constants and method signatures only, no bodies.
 */
interface ScanStore
{
    /**
     * The one sentence a caller gets when the project s slot is already held.
     *
     * GENERIC BY CONSTRUCTION: no run id, no owner, no scope, no progress, no
     * timing. A group-restricted user learning that a project-wide run is in
     * progress learns that someone with wider rights is looking at their
     * project, and a run id would let them ask about it.
     *
     * IT LIVES HERE because three copies of it existed - one in each store and
     * one in ScanAuthorization::busy(), which was written to keep them
     * identical and which nothing ever called. Two of the three had already
     * drifted from the third by a sentence.
     */
    const BUSY_WHY = 'a validation scan is already running for this project. '
        . 'Try again when it has finished.';

    // Record states. Terminal states are >= 100 so "is this row finished" is a
    // comparison rather than a list that a new state can be forgotten from.
    const REC_PENDING   = 0;
    const REC_CLAIMED   = 1;
    const REC_DONE      = 100;
    const REC_UNREADABLE = 101;   // read failed after the configured attempts
    const REC_UNSTABLE   = 102;   // changed under us every time we looked
    const REC_TOMBSTONE  = 103;   // deleted from the project mid-run

    /**
     * Examined, and the result could not be STORED, after every attempt.
     *
     * A fifth state rather than a reuse of one of the four, because the four
     * make claims this one cannot. It is not TOMBSTONE - the record is still in
     * the project, and a run that recorded it as deleted would be lying about
     * the source. It is not UNREADABLE - the record was read and examined
     * perfectly well. It is not UNSTABLE - it held still. What failed is this
     * module's own write, and that is a different fact about a different system.
     *
     * ALWAYS BLOCKING. Terminal, so the run can finish and give the project its
     * slot back rather than retrying forever; and counted with the exclusions,
     * so it can never finish CLEAN. A scan that could not store what it found
     * has not checked the project, and the one outcome it must not produce is
     * the one that looks like a pass.
     */
    const REC_UNSTORED   = 104;

    /**
     * Create a run and take the project's active slot, or report busy.
     *
     * MUST fail rather than wait when the slot is held (I1). Returns the run on
     * success; on contention it returns a busy marker with NO information about
     * the run that holds the slot - see ScanAuthorization::busy().
     *
     * BUSY IS RETURNED WHEN AND ONLY WHEN THE SLOT IS GENUINELY HELD. Every
     * other write failure - a missing table, a value too long, a connection
     * that went away - throws ScanStoreUnavailable and is the caller's problem
     * rather than the operator's. The two used to be the same answer, which
     * told an administrator to wait for a scan that did not exist and could
     * never finish.
     *
     * @return array{ok:bool, busy:bool, run:?array, why:?string}
     * @throws ScanStoreUnavailable when the write failed for any reason other
     *         than the project's active slot already being taken
     */
    public function startRun($pid, array $run);

    /** One run by id, bound to $pid, or null. The id is a locator, not a right. */
    public function run($pid, $runId);

    /**
     * Freeze the manifest: write every in-scope record id with its ordinal, then
     * set the totals. MUST set totals before the run may leave planning, so a
     * run can never redefine what "all" means once work has started.
     */
    public function writeManifest($runId, array $records);

    /**
     * Add records to a manifest still being planned, continuing its ordinals.
     *
     * SEPARATE FROM writeManifest BECAUSE OF SIZE. A million-record manifest
     * cannot be handed over as one PHP array - that is the whole failure this
     * rebuild exists to remove - so planning streams pages into the store and
     * freezes at the end. Appending is idempotent on the record hash, because
     * the record walk may legitimately re-emit a page boundary.
     *
     * Refused unless the run is still planning: a manifest that can grow after
     * work has started is a manifest that can redefine what "all" means.
     *
     * @return int rows actually added
     */
    public function appendManifest($runId, array $records);

    /**
     * Publish the total and leave planning.
     *
     * The total is COUNTED from the rows rather than accumulated while writing
     * them, so a retried or partially applied append cannot make the published
     * total disagree with the manifest it describes.
     *
     * @return int|false the frozen total, or false when the run was not planning
     */
    public function freezeManifest($runId);

    /**
     * Claim the next bounded ordinal range for $owner at $epoch.
     *
     * Returns the claimed rows, `[]` when nothing is left, or FALSE when this
     * worker may not claim right now - a cancelled run, a moved epoch, a phase
     * that changed underneath it, or a read that failed.
     *
     * THOSE LAST TWO ARE DIFFERENT ANSWERS AND MUST STAY DIFFERENT. `[]` means
     * move on to the next phase; `false` means stop and come back. Conflating
     * them is what let the first live pilot walk a 39-record run to its final
     * phase having examined three records, reporting `done` on the way out.
     *
     * AND A READ THAT FAILED IS A THIRD ANSWER, which is why it is no longer in
     * that list. `false` means the fence looked and said no. A deadlock, a
     * lock-wait timeout or a dropped connection means the fence never got to
     * look, and an implementation that answers `false` over one of those hands
     * the worker a sentence about contention for a database that is down.
     *
     * EVERY ROW CARRIES A CLAIM TOKEN, and it is not decoration. The row shape
     * is {ordinal, id_bin, hash, dag, attempts, version, claim}, where `claim`
     * is a number stamped on the STORED row inside this same transaction. The
     * worker hands it back on every subsequent write about that row -
     * commitBatch(), releaseClaims(), noteAttempts() - and a write whose token
     * no longer matches the row touches nothing.
     *
     * WHY NOT THE LEASE EPOCH. Because it does not move. `lease_epoch + 1`
     * occurs in exactly one statement in this codebase, inside cancel(), so
     * takeover fencing did not exist: a second worker re-claimed a stale
     * worker's rows while both held the same epoch, and the first worker's
     * later commit passed the fence and inserted findings for records the
     * second had already committed - which, the identity key being what it is,
     * refused the whole batch. Bumping the epoch on takeover is the obvious fix
     * and is wrong: it invalidates the fence for the WHOLE RUN rather than for
     * the records that actually changed hands, so every other worker of that
     * run loses its in-flight batch too. The claim belongs on the row.
     *
     * @return array|false
     * @throws ScanStoreUnavailable when the storage failed rather than refused
     */
    public function claim($runId, $owner, $epoch, $limit);

    /**
     * Claim records the first pass left behind, by STATE rather than by cursor.
     *
     * The ordinal cursor only ever moves forward, so a record requeued after a
     * stable-read failure sits below it and claim() can never offer it again.
     * Without this the run would wait forever for a row nothing could reach, and
     * would hold the project's scan slot while doing it.
     *
     * Also reclaims rows a dead worker left claimed, after $staleSeconds. That
     * window is the one place this class trades promptness for safety: too short
     * and two workers evaluate the same record, which is wasteful but correct;
     * too long and a crash costs a delay. Neither can produce a false complete,
     * because a record is only marked done by the transaction that scanned it.
     *
     * Rows come back with a claim token, exactly as claim()'s do, and taking a
     * straggler over REPLACES the token the previous holder was given. That is
     * the whole of the takeover fence: the old holder's commit then matches no
     * row and is discarded, without disturbing any other worker of the run.
     *
     * @return array|false  as claim(), with the same three-way distinction
     * @throws ScanStoreUnavailable when the storage failed rather than refused
     */
    public function claimPending($runId, $owner, $epoch, $limit, $staleSeconds = 900);

    /**
     * Commit one batch: record states, findings and uniqueness candidates, in
     * one transaction behind a locking read of the run row (I2).
     *
     * THE RECORD STATES ARE WRITTEN FIRST, not last, and the reason the old
     * order existed does not survive inspection: inside one transaction a crash
     * rolls back every statement regardless of the order they were issued in,
     * so "states last" bought nothing. What the new order buys is real - the
     * per-record fence (I2b) is evaluated by those UPDATEs, so writing them
     * first is what tells the rest of the transaction WHICH records this worker
     * still holds. Findings and candidates are then inserted only for those.
     *
     * Every entry of $batch['records'] carries the `claim` token claim() gave
     * it, and every finding and candidate carries the `ordinal` of the record
     * that produced it. Both are required: a row without a token cannot be
     * fenced, and a finding that cannot be attributed to a claimed record
     * cannot be held back when that record is lost.
     *
     * @return true|string true when it committed; otherwise the sentence to
     *         show, and the caller must discard everything it buffered.
     */
    public function commitBatch($runId, $owner, $epoch, array $batch);

    /**
     * Hand claimed records back so another worker can take them immediately.
     *
     * Claiming and committing are separate transactions - they must be, because
     * the evaluation between them takes time - so a rolled-back batch leaves its
     * rows CLAIMED, and a claimed row is invisible to the straggler sweep until
     * it goes stale. Combined with a phase machine that refuses to advance over
     * unexamined records, that is a deadlock rather than a delay.
     *
     * FENCED TWICE. On the run's lease epoch, and per row on the claim token -
     * a worker whose rows were taken over must not be able to pull them back
     * out of the new holder's hands, and the epoch alone cannot say that
     * because takeover does not move it.
     *
     * @param array $claims ordinal => claim token, as claim() reported them
     * @return int rows handed back
     * @throws ScanStoreUnavailable when the storage failed rather than refused
     */
    public function releaseClaims($runId, $epoch, $owner, array $claims);

    /**
     * Count one attempt against records this worker tried and could not finish,
     * and retire the ones that have now run out of attempts.
     *
     * THE POINT IS THE TRANSACTION IT IS NOT IN. `attempts` was incremented in
     * exactly one place - the record UPDATE inside commitBatch - so it moved
     * only when the commit succeeded. A batch the database refused rolled that
     * increment back with everything else, `recordAttempts` could never be
     * reached, the run never became terminal, and it held the project's one
     * active slot while retrying the same failing write forever. Any persistent
     * write error did this, not only the duplicate key that started it.
     *
     * So the caller invokes this AFTER the failing transaction has rolled back,
     * and it opens its own. The two paths that need it are a whole-batch read
     * failure and a refused commit; both leave records that were genuinely
     * attempted, and neither may leave them looking untouched.
     *
     * Saturating, not wrapping: `attempts` is a TINYINT UNSIGNED and a run that
     * somehow reached 255 must not roll over to 0 and start again, nor be
     * silently clamped by a permissive sql_mode.
     *
     * @param array $claims   ordinal => claim token, as claim() reported them
     * @param int   $maxAttempts  the run's configured limit
     * @param int   $exhausted    the terminal state for rows that reach it -
     *                            REC_UNSTORED for a refused commit,
     *                            REC_UNREADABLE for a failed read
     * @return array{counted:int, retired:int}
     * @throws ScanStoreUnavailable when the storage failed rather than refused
     */
    public function noteAttempts($runId, $epoch, $owner, array $claims, $maxAttempts, $exhausted);

    /**
     * Is every manifest row terminal? A PREDICATE over states (I4), never a
     * comparison of counters.
     */
    public function manifestComplete($runId);

    /**
     * Move the run one step along the phase chain, fenced on the lease epoch.
     *
     * The transition is checked against ScanPhase before it is written, so a
     * worker cannot advance a run past a phase that never ran. Fenced, because a
     * cancelled or taken-over run must not be walked forward by whoever was
     * working it a moment ago.
     *
     * FALSE MUST MEAN REFUSED AND NOTHING ELSE. ScanWorker reads it as "there is
     * no next phase" and reports the run DONE, so an implementation that
     * answered false over a failed write would certify a project on the
     * strength of a transaction that never ran.
     *
     * @return bool false when the transition was refused or the fence had moved
     * @throws ScanStoreUnavailable when the storage failed rather than refused
     */
    public function advancePhase($runId, $epoch, $to);

    /**
     * Move the run to a terminal state and release the project slot.
     *
     * Idempotent: calling it twice is not an error, because a retried finaliser
     * must not be able to reopen a finished run.
     */
    public function finish($runId, array $outcome);

    /** Request cancellation: sets the flag, the phase, and bumps the lease epoch. */
    public function cancel($pid, $runId, $actor);

    // THE INSTALLATION-WIDE WORKER SEMAPHORE IS NOT PART OF THIS CONTRACT, and
    // leaseSlot()/releaseSlot() were removed from it rather than repaired.
    // WorkerSlots is the semaphore, wired at ScanService and ScanWorker; the
    // store's pair was a second implementation over the same table with no
    // caller, and the read-back in it returned the wrong slot number whenever
    // one owner held two. A resource with two mechanisms is a resource where
    // the unused mechanism collects the defects.

    /** One keyset page of findings for a generation, already filtered. */
    public function findings($projectId, $generationId, array $filter, $afterId, $limit);

    /** Aggregate rows (collection gaps, not-checked kinds, rule problems). */
    public function aggregates($runId);

    /** Expire stored value previews whose TTL has passed. Returns rows affected. */
    public function expireValues($now);

    // purgeRuns() IS GONE FROM THIS CONTRACT, DELIBERATELY.
    //
    // There were two of them. This one removed the run, its records and its
    // aggregates; ScanRetention::purgeRuns() removed those AND the findings,
    // candidates, groups and dimensions. Whichever a caller happened to reach
    // decided how much survived a retention pass, and the two had already
    // drifted on what their second argument meant - a day count in one, a
    // datetime in the other. A store with a partial cascade beside a retention
    // class with the full one is not two options; it is one of them being
    // wrong, silently, depending on the call site.
    //
    // ScanRetention owns retention. The store owns rows.

    /** Record an audit event. Never per page fetch - see the plan's §4 note. */
    public function audit($pid, $runId, $event, $actor, $detail);

    // -- reconciliation ------------------------------------------------------
    //
    // The manifest is frozen at the end of planning so that a run cannot
    // redefine what "all" means while it works. Catch-up is the ONE sanctioned
    // exception, and it is a separate set of methods rather than a relaxation of
    // the planning ones precisely so the exception is visible: every one of
    // these refuses outside the `catch-up` phase and outside the run's current
    // lease epoch.

    /**
     * Add records discovered by reconciliation, and republish the total.
     *
     * A record created after the manifest was frozen is not "extra"; it is a
     * record the run would otherwise certify a project without having examined
     * (C3). The total moves with it, because a total that did not would make
     * completeness a comparison against a number the run knows to be wrong.
     */
    public function reconcileAdd($runId, $epoch, array $records);

    /**
     * Send terminal records back to pending, because the source moved.
     *
     * The attempt counter is NOT reset: a record that keeps being edited must
     * still reach its attempt limit and become a blocking exclusion, or a
     * project someone is actively working in could hold a run open forever.
     */
    public function requeue($runId, $epoch, array $recordIds);

    /**
     * Mark records that no longer exist in the project.
     *
     * A deleted record can never reach `done`, so without a terminal state of
     * its own it would hold the run incomplete forever - the mirror of the
     * false-complete case, and the reason C3 called it worse.
     */
    public function tombstone($runId, $epoch, array $recordIds);

    /** How many manifest rows are in each state. The input to the coverage predicate. */
    public function recordStates($runId);

    /** The source version each named record was last scanned at, keyed by id. */
    public function scannedVersions($runId, array $recordIds);

    /** Where reconciliation and rollup had reached. Survives the request. */
    public function progressState($runId);

    /** Move those cursors, fenced on the lease epoch. */
    public function setProgressState($runId, $epoch, array $state);

    /**
     * Add to a counted aggregate.
     *
     * ADDS rather than sets, because an aggregate is built from bounded pages
     * and a page that set the value would report only its own page. Callers that
     * can be retried must write it inside the same transaction as the cursor
     * that says the page is done.
     */
    public function addAggregate($runId, $kind, $axis1, $axis2, $cnt, $blocks = 0, $samples = null);

    /** How many aggregate kinds block coverage. Zero is the only value that permits complete. */
    public function blockingAggregates($runId);
}
