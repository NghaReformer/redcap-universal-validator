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
     * Claim the next bounded ordinal range for $owner at $epoch.
     *
     * Returns the claimed rows, or an empty array when nothing is left. A claim
     * is itself fenced: a worker whose epoch has moved gets nothing rather than
     * a range it would fail to commit later.
     */
    public function claim($runId, $owner, $epoch, $limit);

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
}
