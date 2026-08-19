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
 *  I2  A batch's findings, its record states and its cursor advance commit in
 *      ONE transaction, cursor last, conditioned on its own old value and the
 *      lease epoch. Zero affected rows means roll everything back: another
 *      worker moved past us, or a cancellation bumped the epoch.
 *  I3  Nothing marks a record done except the transaction that scanned it. So a
 *      lost batch, a crash, a retry and an OOM are indistinguishable from "not
 *      attempted", which is the only safe reading.
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
    // Record states. Terminal states are >= 100 so "is this row finished" is a
    // comparison rather than a list that a new state can be forgotten from.
    const REC_PENDING   = 0;
    const REC_CLAIMED   = 1;
    const REC_DONE      = 100;
    const REC_UNREADABLE = 101;   // read failed after the configured attempts
    const REC_UNSTABLE   = 102;   // changed under us every time we looked
    const REC_TOMBSTONE  = 103;   // deleted from the project mid-run

    /**
     * Create a run and take the project's active slot, or report busy.
     *
     * MUST fail rather than wait when the slot is held (I1). Returns the run on
     * success; on contention it returns a busy marker with NO information about
     * the run that holds the slot - see ScanAuthorization::busy().
     *
     * @return array{ok:bool, busy:bool, run:?array, why:?string}
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
     * @return array|false
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
     */
    public function claimPending($runId, $owner, $epoch, $limit, $staleSeconds = 900);

    /**
     * Commit one batch: findings, record terminal states, aggregates, and the
     * cursor advance, in one transaction with the cursor LAST (I2).
     *
     * @return bool true when the compare-and-set held; false means the caller
     *              must discard everything it buffered and stop.
     */
    public function commitBatch($runId, $owner, $epoch, $expectCursor, array $batch);

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
     * @return bool false when the transition was refused or the fence had moved
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

    /** Lease/renew an installation-wide worker slot, or null when none is free. */
    public function leaseSlot($owner, $runId, $ttlSeconds);

    /** Release a slot held by $owner at $epoch. A stale holder releases nothing. */
    public function releaseSlot($slotNo, $owner, $epoch);

    /** One keyset page of findings for a generation, already filtered. */
    public function findings($generationId, array $filter, $afterId, $limit);

    /** Aggregate rows (collection gaps, not-checked kinds, rule problems). */
    public function aggregates($runId);

    /** Expire stored value previews whose TTL has passed. Returns rows affected. */
    public function expireValues($now);

    /** Purge runs past their retention. Returns runs removed. */
    public function purgeRuns($pid, $olderThan);

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
