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
     * Left the run's Data Access Group while the run was open (review rv5b-1).
     *
     * A group-scoped run lists the group as planning read it, and a record can
     * be moved to another group afterwards. It is then another group's
     * record: reading it again would put that group's data into this run's
     * report, so it leaves the run instead. Terminal, never examined again,
     * and its findings in this run are closed with it.
     *
     * NOT IN NOT_EXAMINED and not blocking: it was not skipped, it stopped
     * being in scope, as a deleted record stops existing. Not a tombstone
     * either: the record is still in the project, and the run's figures say
     * nothing about it at all - the total a reader is shown leaves it out.
     */
    const REC_DEPARTED   = 105;

    /**
     * Terminal WITHOUT having been examined. One list, because two places ask:
     * promotion, where any of these caps coverage at partial, and the progress
     * figure, which must not call them done. A tombstone is not here - a record
     * deleted from the project was not skipped, it stopped existing.
     */
    const NOT_EXAMINED = [self::REC_UNREADABLE, self::REC_UNSTABLE, self::REC_UNSTORED];

    /**
     * What run() returns: these keys, in this order, from both stores.
     *
     * ONE LIST, ON THE INTERFACE. SqlScanStore used to carry a SELECT list and,
     * a few lines below it, a hand-kept list of keys to zip the row against.
     * Add a column to one and not the other and array_combine() returns false -
     * not an error, a false - so run() answered false, promote() asked
     * isset($run['phase']) of a boolean, and three test fixtures built as
     * positional arrays drifted the same way, twice. The SELECT list is now
     * imploded from this constant and a row of the wrong length throws.
     *
     * The first 22 are in their original order. Anything new is APPENDED, so a
     * fixture keyed by position stays valid for the columns it knew about.
     */
    const RUN_COLUMNS = [
        'run_id', 'project_id', 'scope_dag', 'phase', 'terminal', 'coverage', 'detail',
        'values_state', 'policy_revision', 'fingerprint', 'manifest_total', 'manifest_done',
        // run_seq is the interval a finding written by this run OPENS, so a
        // caller building a batch needs it as much as it needs the generation.
        'cursor_ordinal', 'lease_epoch', 'generation_id', 'run_seq', 'created_by', 'detail_rows',
        // fence_open, fence_target and cancel_requested_at because promotion
        // reads the run row to decide what it may claim: a fence it cannot see
        // is a fence it treats as absent, which silently downgrades every
        // fenced run to manifest-complete.
        'detail_bytes', 'fence_open', 'fence_target', 'cancel_requested_at',
        // Why it ended, the three clocks, and what promotion decided. `clean`
        // is NULL until a run finishes as complete or partial, and stays NULL
        // for one that failed, was cancelled or expired: those make no claim
        // about the project at all.
        'terminal_reason', 'created_at', 'updated_at', 'progress_at',
        'clean', 'gap_count', 'rule_problem_count', 'not_examined',
        // The policy the run started under, as JSON. status() reads which
        // other projects the run looked values up in from it (review R3U-N1):
        // the reader's plan can no longer say once the rule is gone.
        'policy_json',
        // The findings the detail budget left out of readings the run still
        // holds (schema version 6, review rvf1-3). NULL on a run that began
        // before the column existed, and that run is counted the old way.
        'open_unstored',
    ];

    /**
     * The keys every finding handed to commitBatch() must carry, non-null.
     *
     * Each names a NOT NULL column of uv_finding that has no default the store
     * could honestly supply. Both stores refuse a finding missing any of them,
     * and refuse the whole batch with it, before anything is written.
     *
     * WHY THE STORE ASKS RATHER THAN THE SERVER (review ST-2). A single-row
     * INSERT of a NULL into a NOT NULL column is error 1048 under every
     * sql_mode. A MULTI-row INSERT under a non-strict sql_mode is not: the
     * server stores the column's implicit default - '' or 0 - with a warning,
     * and the row count still matches what was sent. Batching the findings
     * INSERT (M9) therefore turned a refusal into a finding with an empty
     * instrument or an empty rule, on exactly the servers that are configured
     * leniently. The columns the store fills itself when a key is absent
     * (valid_from_seq, instance, rule_ord, the flags) are not listed.
     *
     * tests/mysql/cases/store.php compares this list with the NOT NULL columns
     * the server reports, so a column added to the table without being added
     * here fails the database matrix.
     */
    const FINDING_REQUIRED = [
        'generation_id', 'identity', 'record_hash', 'record_id_bin', 'host_form', 'field',
        'rule_source_id', 'rule_revision', 'check_type', 'reason_code',
    ];

    /**
     * Four uv_finding columns that are RESERVED: in the table, written with
     * their schema default by both stores, read by nothing (review W7-R7).
     *
     *   arm_id        the event's arm. The store is handed an event id and no
     *                 event metadata, so filling it would cost a read of
     *                 REDCap's event table per batch, which no reader needs.
     *   value_binary  whether the stored value is text. value_bin is cut at
     *                 byte 255, so "not valid UTF-8" also describes a text value
     *                 cut inside a character; FindingRowMapper decides from the
     *                 bytes themselves (W7-R5), and a second answer here would
     *                 be the wrong one for every cut value.
     *   severity      no rule kind has a severity.
     *   status_key    the form's completion status. Not read by the scan, and a
     *                 second read per record to fill a column nothing shows.
     *
     * None is in FindingRowMapper::INPUT_KEYS, so findings() does not return
     * them and the report cannot branch on a default. Giving one a producer is
     * a change to UniversalValidator's finding literal, not to the schema,
     * which already holds them.
     */
    const FINDING_RESERVED = ['arm_id', 'value_binary', 'severity', 'status_key'];

    /**
     * How many windows of $limit rows one claimPending() call may retire
     * before it answers. See claimPending(): a call that retires a whole
     * window looks at the next one, and this is where it stops looking, so one
     * call holds the run row's lock for a bounded number of statements however
     * many dead rows sit in front of the live ones. Both stores use it.
     */
    const SWEEP_WINDOWS = 16;

    /**
     * Seconds one claimPending() call may spend walking windows before it
     * answers (review R3S-1). SWEEP_WINDOWS bounds the number of statements,
     * and a count of statements is not a bound on time: each window used to
     * walk every finished row in front of the free ones, 98,499 rows a window
     * at 100,000 records and 498,499 at 500,000, while every commit, claim and
     * Stop of the run waited on the row lock behind it. The first window always
     * runs; a later one starts only while the call is younger than this, so an
     * answer of [] with rows retired can mean this bound was reached as well.
     */
    const SWEEP_SECONDS = 1.0;

    /**
     * The most rows one retention or supersede statement may touch.
     *
     * An unpaged DELETE of 500,000 findings was measured at 160 seconds as one
     * statement, holding its row locks throughout. Every statement that walks
     * findings, manifest rows or aggregates on behalf of retention is bounded
     * by this, and every method built on one answers whether there is more, so
     * a caller runs a fixed slice and comes back. ScanRetention::PAGE is this.
     */
    const PAGE = 5000;

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
     * THE PAGE AND WHERE THE WALK STANDS ARE ONE WRITE (B10). Planning runs
     * across requests, so a request can die anywhere. With $plan the page's
     * rows, the walk's cursor, carry and counters, and the run's progress
     * clock commit in one transaction: a request that dies has written both
     * or neither. Written apart, a page committed without its cursor is read
     * again by the next request and every later ordinal moves by a page; a
     * cursor committed without its page skips that page's records for good.
     *
     * $plan is ScanPlanner's, and is fenced: the run must still be planning,
     * at `epoch`, not asked to stop, and leased to `owner` by claimPlan(), and
     * the plan row claimPlan() wrote must still be there. A missing row is
     * refused, never written here: something other than the walk removed it,
     * and the walk's position went with it (review R5-9). It
     * carries the walk's state as absolute values - `cursor`, `carry` (opaque
     * to the store), `listed`, `outOfScope` - and `done` when the source said
     * it had nothing past this page. `release` hands the lease back with the
     * page, so a slice's last page and the end of its lease are one write;
     * otherwise the lease is renewed for `leaseSeconds`. A page with no rows
     * in scope is still a page: the cursor moves and the counters with it.
     *
     * EVERY APPEND IS A HEARTBEAT, with or without $plan: `updated_at` and
     * `progress_at` move with it. Planning used to write neither until the
     * freeze, so a planner appending its hundredth page looked exactly like
     * one that had died at the first - to the page, which said "stopped", and
     * to the abandoned-run sweep (review R3U-2).
     *
     * @param array|null $plan {owner:string, epoch:int, cursor:?string, carry:?string,
     *                          listed:int, outOfScope:int, done:bool, release:bool,
     *                          leaseSeconds:int}
     * @return int|false rows actually added, or FALSE when refused. Zero is an
     *         answer - every record was already there, or none was in scope -
     *         and a refusal is a different one: the walk must stop on it.
     */
    public function appendManifest($runId, array $records, ?array $plan = null);

    /**
     * Take the planning lease and read where the walk stands.
     *
     * ONE PLANNER AT A TIME. Two tabs driving the same run would each read the
     * same cursor and list the same page; the second append would be refused
     * and its page read wasted. A lease held by another owner and not yet
     * expired answers `held`, and the caller comes back later.
     *
     * THE ATTEMPT IS COUNTED HERE, before the slice is tried (I3b). A planning
     * request dies at max_execution_time or memory_limit, an uncatchable
     * fatal, so nothing it runs afterwards can count its failure. Counting at
     * the claim, in a transaction the slice cannot roll back, is how wave 5
     * counts a record's attempts for the same reason; appendManifest() puts
     * the count back to zero with the first page the slice commits. A slice
     * that dies before its first page leaves its count standing, and when
     * `maxAttempts` slices in a row have died on the same page the claim
     * answers `exhausted` instead of letting another try.
     *
     * @return array|false FALSE when the run is not planning, its epoch moved
     *         or it was asked to stop. Otherwise {state: 'ok'|'held'|'exhausted'|'done',
     *         cursor:?string, carry:?string, pages:int, listed:int, outOfScope:int,
     *         attempts:int}. Only `ok` holds the lease, and only `ok` writes the
     *         plan row when the run has none yet.
     */
    public function claimPlan($runId, $owner, $epoch, $leaseSeconds, $maxAttempts);

    /**
     * Give the planning lease back without listing anything.
     *
     * For a slice whose page read failed: its attempt was counted at the
     * claim, and the next request should not have to wait out the lease to
     * try again. Changes nothing unless $owner holds the lease.
     *
     * @return bool whether a lease was released
     */
    public function releasePlan($runId, $owner);

    /**
     * Publish the total and leave planning.
     *
     * The total is COUNTED from the rows rather than accumulated while writing
     * them, so a retried or partially applied append cannot make the published
     * total disagree with the manifest it describes.
     *
     * ONLY ONCE THE SOURCE HAS SAID IT IS DONE (B10). The persisted
     * `plan_done` is the gate, not the walk having returned: a walk that
     * returned because its request ran out of time has listed part of the
     * project, and a manifest frozen there certifies the rest as checked.
     *
     * @return int|false the frozen total, or false when the run was not
     *         planning or its walk has not reached the end of the source
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
     * ABANDONED MEANS STALE, NOT MERELY STAMPED (review R2S-1). With
     * $maxAttempts, a row left by a worker that died is charged the attempt
     * that worker could not count, and goes out alone. A row is that worker's
     * only when it is free, still carries a token AND that token is older than
     * $staleSeconds - the same age a CLAIMED row needs before it is free at
     * all. A scanning-phase claim stamps a token on rows it leaves PENDING, so
     * "free and stamped" also describes a LIVE worker still evaluating its last
     * scanning batch; charging those rows retired healthy records as unreadable
     * the moment a second tab reached catch-up. A fresh stamp is taken over
     * uncharged, as every straggler was before attempts were counted here.
     *
     * Rows retired here, out of attempts, are counted into the optional
     * `&$retired` out-parameter, so the caller can report them as the blocking
     * exclusions they are: such a row never reaches a batch, and nothing else
     * would say it was excluded. Both stores set it on every call, to 0 unless
     * a row was retired and the retirement was kept.
     *
     * A WINDOW THAT WAS ALL RETIRED IS NOT THE END (review R2S-5). The call
     * looks at free rows a window of $limit at a time, in ordinal order. When
     * every row of a full window was retired and nothing was claimed, it looks
     * at the next window in the same transaction, up to SWEEP_WINDOWS of them
     * and while the call is younger than SWEEP_SECONDS. The SQL store used to
     * answer [] after the first window while free rows remained behind it, and
     * the worker read that as "records are still out with another worker". An
     * answer of [] with $retired > 0 can therefore still mean a bound was
     * reached: the caller asks again rather than concluding anything from it.
     *
     * EACH WINDOW STARTS AFTER THE LAST ROW OF THE ONE BEFORE (review R3S-1).
     * Every row of that window was retired, so nothing at or below its last
     * ordinal is free any more; reading from the start of the run again only
     * made the next window walk the same ground.
     *
     * @return array|false  as claim(), with the same three-way distinction
     * @throws ScanStoreUnavailable when the storage failed rather than refused
     */
    public function claimPending($runId, $owner, $epoch, $limit, $staleSeconds = 900,
                                 $maxAttempts = null, $exhausted = self::REC_UNREADABLE,
                                 &$retired = null);

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
     * TRUE DOES NOT MEAN ANYTHING WAS KEPT. A batch whose every record was
     * taken over commits: there is nothing to refuse. It writes no finding, no
     * state and no mark, because it holds no record and so has no standing to
     * say anything about the run. A caller that remembers what it sent on
     * `true` therefore needs to know whether the batch held anything (review
     * ST-4). ScanWorker remembers the presence marks it has sent so that it
     * stops offering them, and a mark it remembers but the store dropped is
     * never offered again.
     *
     * So ArrayScanStore and SqlScanStore take an optional FIFTH parameter,
     * by reference: `&$heldCount = null`. They set it to the number of the
     * batch's records still held when it committed, and to 0 whenever nothing
     * was written: a refusal, a rollback, or a batch that held no record. It
     * is not in the signature above because adding it here would break every
     * implementation that predates it, including the test doubles that
     * override this method with four parameters. A caller passes a variable
     * set to null. When the variable comes back still null, the store does not
     * report holding, and the caller keeps its old reading of `true`.
     *
     * AND A SIXTH, `&$heldOrdinals = null`, for the same reason and in the
     * same way (review R2S-1). A count says how many records were kept and
     * not which, and a worker that reports what it examined needs which: a
     * batch whose records were taken over mid-evaluation used to report every
     * one of them as worked while the new holder committed them. Both stores
     * set it to the list of ordinals the commit held, and to [] whenever
     * nothing was written.
     *
     * FINDINGS COUNTED AND NOT STORED (H9). `$batch['unstored']` is ordinal =>
     * how many findings that record produced which the worker left out because
     * the run's detail budget was spent. The store keeps no detail for them,
     * and adds the count of the HELD records' to the DETAIL_UNSTORED aggregate
     * in the same transaction, behind the same fences: a record lost to
     * another worker is that worker's to count. Absent is none.
     *
     * AND PER RECORD (review rvf1-3). A held record committed done keeps its
     * count on its manifest row, and the run's open_unstored grows by it, in
     * the same transaction. The row's count is what requeue(), tombstone()
     * and depart() take back from open_unstored when the record leaves
     * done, so a finding left out of a reading the run no longer holds is no
     * longer counted. Any other committed state keeps 0.
     *
     * @return true|string true when it committed; otherwise the sentence to
     *         show, and the caller must discard everything it buffered.
     */
    public function commitBatch($runId, $owner, $epoch, array $batch);

    /**
     * The aggregate kind that counts findings a run found and did not store,
     * because its detail budget was spent (H9). ScanPromotion::tally() adds it
     * to what the summary counts, so `violations` is every finding; a run with
     * any is `truncated`, and one with none is not.
     *
     * RUN-WIDE AND ONLY EVER ADDED TO, so it cannot take back a record that
     * left the run (review rvf1-3). A run row whose open_unstored is not NULL
     * (every run created at schema version 6 or later) is judged on that
     * instead; this aggregate is read only for a run that began before it.
     */
    const DETAIL_UNSTORED = 'detail-unstored';

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
     * AND IT HANDS THE REST BACK, in the same transaction (review R2S-4). A row
     * counted here and not retired is released exactly as releaseClaims()
     * would release it: a straggler returns to PENDING, the claim is cleared,
     * the cursor moves back over it. The caller used to call releaseClaims()
     * next, in a transaction of its own; when that failed, or a takeover
     * landed between the two, the row kept a token on an attempt already
     * counted, aged into "abandoned", and the straggler sweep charged the same
     * failure again. Every caller released straight after, so the two are one
     * write now and a caller does not release these claims itself.
     *
     * @param array $claims   ordinal => claim token, as claim() reported them
     * @param int   $maxAttempts  the run's configured limit
     * @param int   $exhausted    the terminal state for rows that reach it -
     *                            REC_UNSTORED for a refused commit,
     *                            REC_UNREADABLE for a failed read
     * @return array{counted:int, retired:int, released:int}
     * @throws ScanStoreUnavailable when the storage failed rather than refused
     */
    public function noteAttempts($runId, $epoch, $owner, array $claims, $maxAttempts, $exhausted);

    /**
     * Is every manifest row terminal? A PREDICATE over states (I4), never a
     * comparison of counters.
     *
     * An existence test, and it must FAIL CLOSED: an answer that could not be
     * read is "not complete". A run with an EMPTY manifest answers true - the
     * predicate is vacuous there, and it is ScanPromotion's emptyScope fact, not
     * this method, that stops an empty run being certified.
     *
     * A RUN THAT DOES NOT EXIST IS NOT COMPLETE, from both stores (W6-N1). The
     * SQL store used to answer true for it - no unfinished row, because no row
     * at all - while the in-memory store answered false, and the contract had
     * pinned the disagreement rather than chosen. "Nothing is unfinished" about
     * a run nobody can find is the vacuous reading in the dangerous direction.
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

    /**
     * End this project's active run if it has stopped making progress (B6a).
     *
     * A run whose lease has lapsed and whose PROGRESS clock - progress_at, or
     * created_at before its first page - is older than $staleHours holds the
     * project's one active slot for nobody: its tab closed, its worker died or
     * its request was killed. It becomes terminally `expired` and the slot is
     * free.
     *
     * ONE STATEMENT DOES ALL OF IT, under the predicate, and three things are
     * in it on purpose:
     *
     *   THE LEASE EPOCH MOVES (Risk 5). A browser worker that returns after the
     *   reap still holds the epoch it claimed under; without the bump its next
     *   commit passes the fence and writes findings into a run already
     *   reported expired.
     *
     *   THE ROW IS finish(ScanOutcome::derive(['expired' => true]))'s ROW:
     *   terminal, coverage, detail, the derived sentence, clean NULL and the
     *   three counts at 0, exactly as reapUnworkableScopes() and every other
     *   writer of an expired run leaves it. A reader cannot tell which request
     *   ended it, and must not be able to.
     *
     *   THE PREDICATE IS REPEATED IN THE WRITE, so a run that made progress or
     *   renewed its lease between the read that named it and the write is
     *   left alone.
     *
     * AND IT IS AUDITED. A scan that vanished from "running" with no event is
     * indistinguishable from one that was never started; the audit row names
     * the run, the event `expire`, who reaped it and the window.
     *
     * ONE PROJECT. The cron finds the projects that have a stale run and calls
     * this for each; Start calls it for its own project. The read and the
     * write go through uq_project_active, so a project with nothing stale
     * costs one indexed read.
     *
     * @param int      $pid
     * @param int      $staleHours the window, in hours, at least 1
     * @param string   $actor      who the audit names: 'cron', or the user
     *                             whose Start found the run
     * @param int|null $now        unix seconds the window is measured back
     *                             from; null is now (a test steps it forward)
     * @return int runs expired: 0 or 1
     * @throws ScanStoreUnavailable when the storage failed rather than refused
     */
    public function expireAbandoned($pid, $staleHours, $actor, $now = null);

    /** The audit event an expired run is recorded under. */
    const AUDIT_EXPIRE = 'expire';

    // THE INSTALLATION-WIDE WORKER SEMAPHORE IS NOT PART OF THIS CONTRACT, and
    // leaseSlot()/releaseSlot() were removed from it rather than repaired.
    // WorkerSlots is the semaphore, wired at ScanService and ScanWorker; the
    // store's pair was a second implementation over the same table with no
    // caller, and the read-back in it returned the wrong slot number whenever
    // one owner held two. A resource with two mechanisms is a resource where
    // the unused mechanism collects the defects.

    /**
     * One keyset page of a generation's ACTIVE findings, already filtered.
     *
     * EACH ROW IS KEYED BY FindingRowMapper::INPUT_KEYS, in that order, from
     * both stores (B5-N1). The SQL store used to hand back twelve positional
     * columns and the in-memory one its whole internal row, so a report built
     * against either would have read nothing correctly from the other. The SQL
     * store selects exactly that list and zips it with array_combine(), the way
     * run() zips RUN_COLUMNS, and a row of the wrong width throws.
     *
     * KEYSET ON finding_id: rows come back with finding_id > $afterId, in
     * finding_id order, so the last row's finding_id is the next page's
     * $afterId and no row is ever returned on two pages. $limit is clamped to
     * 1..100. $filter takes host_form, reason_code, dag_key and check_type;
     * any other key is ignored rather than interpolated.
     */
    public function findings($projectId, $generationId, array $filter, $afterId, $limit);

    /** Aggregate rows (collection gaps, not-checked kinds, rule problems). */
    public function aggregates($runId);

    /**
     * Close the active findings of this project's OLDER generations, one page.
     *
     * When a run ends `complete`, every earlier generation of its project is
     * history. Each reader is scoped to one generation, so nothing reads them
     * as current - but "the current state of this project" stays a question
     * about the newest generation only until their rows are closed, and
     * valid_to_seq is how an "as of run N" view knows when they stopped being
     * true (W4-D1). Closing is active_slot = NULL and valid_to_seq = this run's
     * run_seq, never a delete (I5).
     *
     * ONE PAGE PER CALL, at most min($limit, PAGE) rows, inside one
     * transaction that holds this run's row FOR UPDATE and moves
     * scan_run.supersede_cursor with the page. The cursor is a GENERATION:
     * every older generation below it has been closed. A generation is
     * finished when a page closes fewer rows than it was allowed; the cursor
     * then moves past it. When no older generation is left the cursor becomes
     * the run's own generation, which is how a caller can tell a finished
     * supersede from one that has not started without calling it.
     *
     * NEVER this run's own generation, never a newer one, never another
     * project: the generations come from this project's own runs and must be
     * below this run's. Only a run that ended `complete` supersedes anything; a
     * partial, failed, cancelled or expired run leaves the previous report as
     * the current one, which is why this happens at promotion and not at start.
     *
     * Idempotent and safe from two requests at once: the second waits on the
     * run row and then finds the rows already closed or the cursor moved.
     *
     * ONLY A WHOLE-PROJECT RUN SUPERSEDES (review R8-1). A run scoped to one
     * data access group examined that group's records and no others, and
     * closing every older generation closed the other groups' findings as well
     * - each stamped as superseded by a run that never read its record, and
     * gone from any reading of the project's current state. A scoped run
     * therefore closes nothing and answers done with SUPERSEDE_SCOPED; the
     * older generations stay current until a whole-project run completes,
     * which closes all of them, scoped ones included. Closing only the group's
     * share was considered and is not done, for three reasons. The store
     * cannot name it: scope_dag is REDCap's group id and a finding's dag_key
     * the group's unique name, two axes. A group is not a fixed set of
     * records: a record that moved out of the group since the older run was
     * never read by this one, and closing its findings by their old group
     * closes what may be its only current evidence. And closing by this run's
     * own manifest needs a position inside an older generation, which
     * supersede_cursor (a generation) cannot hold: without one every page
     * walks again each out-of-scope row in front of it. Leaving the group's
     * older rows open over-reports - a record re-examined by the scoped run
     * shows its older findings beside its new ones until the next whole-project
     * run - which is the direction that hides nothing.
     *
     * @return array{done:bool, rows:int, cursor:int, why:?string} `done` is
     *         true when nothing is left for this run to close - including a
     *         run that may not supersede (`why` says which) - and false while
     *         a further call has work to do. A run still in progress is not
     *         done: it may yet complete.
     * @throws ScanStoreUnavailable when the storage failed; nothing is closed
     */
    public function supersedePrevious($pid, $runId, $limit);

    /** Why a scan of one data access group closed nothing. See supersedePrevious(). */
    const SUPERSEDE_SCOPED = 'this scan covered one data access group, so it supersedes nothing: the '
                           . 'previous reports stay current until a scan of the whole project completes';

    // expireValues() IS GONE FROM THIS CONTRACT TOO, for purgeRuns()'s reason.
    //
    // Each store carried its own unpaged installation-wide UPDATE of
    // value_expires_at beside ScanRetention::expireValues(), which the cron
    // calls; nothing in production called the store's. Two implementations of
    // the one clear that touches participant data is one more than can be
    // kept paged, scoped and tested.

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
     *
     * By RECORD HASH (W5F-N3), as depart() is: uq_run_record (run_id,
     * record_hash) finds the rows, where record_id_bin has no index and a
     * filter on it reads every row of the run.
     *
     * Each row's left-out count (commitBatch(), rvf1-3) is taken back from
     * the run's open_unstored and zeroed, in the same transaction: the
     * reading that left those findings out is the one being replaced. Its
     * stored findings stay active until the new reading supersedes them, so
     * the report is never blank for a record still being checked.
     *
     * @param string[] $hashes raw record hashes
     */
    public function requeue($runId, $epoch, array $hashes);

    /**
     * Mark records that no longer exist in the project.
     *
     * A deleted record can never reach `done`, so without a terminal state of
     * its own it would hold the run incomplete forever - the mirror of the
     * false-complete case, and the reason C3 called it worse.
     *
     * ITS FINDINGS GO WITH IT (W5F-N4). In one transaction, each row not
     * already a tombstone becomes one, and the record's active findings in
     * this run's generation are closed at the run's sequence, as depart()
     * and a worker's tombstone commit close them: the run reports on records
     * the project holds, and counts a deleted one as removed. Duplicate-value
     * findings (staged) are the finalizer's and are not touched. Its
     * left-out count is taken back as requeue() takes it.
     *
     * @param string[] $hashes raw record hashes, as requeue() takes them
     * @return int rows that became tombstones
     */
    public function tombstone($runId, $epoch, array $hashes);

    /**
     * Take records that left the run's group out of the run (review rv5b-1).
     *
     * By RECORD HASH, which is what uq_run_record (run_id, record_hash)
     * serves. In one transaction: each row not already departed or
     * tombstoned becomes REC_DEPARTED with no scanned version and no claim,
     * and the record's active findings in this run's generation are closed
     * at the run's sequence, as a re-examination closes them. A deleted
     * record stays a tombstone: gone is the stronger fact. Duplicate-value
     * findings (staged) are the finalizer's and are not touched. Its left-out
     * count is taken back as requeue() takes it (rvf1-3).
     *
     * Catch-up phase and current lease epoch only, like the other
     * reconciliation writes. A record departing while the worker reads it
     * goes through commitBatch() with REC_DEPARTED instead.
     *
     * @param string[] $hashes raw record hashes
     * @return int rows that departed
     */
    public function depart($runId, $epoch, array $hashes);

    /**
     * Which of these record hashes left this run's group, as a set.
     *
     * The duplicate finalizer asks it of each page of candidates: a departed
     * record's candidate was written while it was in the group, and
     * verifying it would re-read another group's record.
     *
     * @param string[] $hashes raw record hashes
     * @return array<string,true> raw hash => true, for the departed ones only
     */
    public function departedOf($runId, array $hashes);

    /** How many manifest rows are in each state. The input to the coverage predicate. */
    public function recordStates($runId);

    /**
     * The source version and state of each named record, keyed by raw hash.
     *
     * By record hash for the reason requeue() gives (W5F-N3). A hash the
     * run's manifest does not hold is absent from the answer.
     *
     * @param string[] $hashes raw record hashes
     * @return array<string,array{version:?string,state:int}>
     */
    public function scannedVersions($runId, array $hashes);

    /** Where reconciliation and rollup had reached. Survives the request. */
    public function progressState($runId);

    /**
     * Move those cursors, fenced on the lease epoch.
     *
     * `progress` => true also moves progress_at (W7-N9). The finishing phases
     * finish no records, so commitBatch() never moved it for them, and a long
     * unique-finalize or rollup read as stalled to the page and as abandoned to
     * the reaper while it worked. A caller passes it only for a step that moved
     * the run forward - a page summarised, groups found or decided, findings
     * written - never for a step that retried, or the reaper could no longer
     * tell a wedged run from a working one (see commitBatch()). Alone it is a
     * write: true when this lease still holds the run, false when it does not.
     */
    public function setProgressState($runId, $epoch, array $state);

    /**
     * Why a summary page may not be counted. One copy, both stores, as BUSY_WHY.
     *
     * The run is gone, or the lease moved, or the run has already ended. They
     * reach the worker as the reason it stopped, so each says what happened to
     * the page and names nothing about the run.
     */
    const ROLLUP_NO_RUN     = 'this run could not be read, so no summary was built';
    const ROLLUP_TAKEN_OVER = 'another worker took over this scan, so the summary page was '
        . 'discarded rather than counted twice';
    const ROLLUP_FINISHED   = 'this scan has already finished, so the summary page was '
        . 'discarded rather than added to a summary that is no longer being built';

    /**
     * Hold the run row for one summary page, and say where the summary has got to.
     *
     * CALLED INSIDE THE CALLER'S TRANSACTION, before the page's counts are
     * added, and the hold lasts until that transaction ends. The answer is the
     * rollup cursor as it stands under the hold, as an int, or one of the
     * ROLLUP_* sentences above when this worker may not add to the summary at
     * all: no such run, a different lease epoch, or a run that has ended.
     *
     * WHY THE EPOCH IS NOT ENOUGH. The epoch moves only on cancel, so two
     * workers sharing a run hold the same one. Both could read cursor 0, read
     * the same page of findings, and add it, and the counters ADD. The caller
     * compares the cursor this returns with the one its page started from and
     * discards the page when they differ. Under the hold, a second worker
     * waits for the first to commit and then sees the cursor it moved. A run
     * that has ended takes no page for the same reason it takes no late commit:
     * its stored verdict was read from the summary as it stood.
     */
    public function holdRollupCursor($runId, $epoch);

    /**
     * Why a finishing phase may not write for a run. One copy, both stores.
     *
     * Each reaches the worker as the reason it stopped, so each says what
     * happened to the work and names nothing about the run.
     */
    const HOLD_NO_RUN     = 'this run could not be read, so nothing more was written for it';
    const HOLD_TAKEN_OVER = 'this scan was stopped or taken over while this request was '
        . 'finishing it, so what this request found was not written';
    const HOLD_FINISHED   = 'this scan has already ended, so what this request found was not '
        . 'added to a result that has already been reported';
    const HOLD_MOVED      = 'this scan has moved past the step this request was working on, so '
        . 'what this request found was not written';

    /**
     * Hold the run row while a finishing phase writes for it (review rv10a1-1).
     *
     * CALLED INSIDE THE CALLER'S TRANSACTION, as its first statement, and the
     * hold lasts until that transaction ends. The answer is true when the run
     * exists, still has lease epoch $epoch, has not ended and is in phase
     * $phase; otherwise one of the HOLD_* sentences above, in this order: no
     * such run, a different epoch, ended, a different phase. The caller then
     * rolls back and writes nothing.
     *
     * WHY A HOLD AND NOT A CHECK. A cancel, the reaper and another user's
     * Start all end a run by writing its row. A plain read before a write
     * leaves a window in which the run ends and the write lands anyway: a
     * request that was mid-finalize when the cron expired its run went on
     * emitting and publishing duplicates into the run already reported
     * expired. Under the hold the row cannot change until the caller commits,
     * and a writer that ended the run first is seen by the read.
     *
     * @param int    $runId
     * @param int    $epoch the lease epoch the caller's work started on
     * @param string $phase one of ScanPhase's working phases
     * @return true|string
     */
    public function holdRun($runId, $epoch, $phase);

    /**
     * Add to a counted aggregate.
     *
     * ADDS rather than sets, because an aggregate is built from bounded pages
     * and a page that set the value would report only its own page. Callers that
     * can be retried must write it inside the same transaction as the cursor
     * that says the page is done.
     */
    public function addAggregate($runId, $kind, $axis1, $axis2, $cnt, $blocks = 0, $samples = null);

    /**
     * Record that something is PRESENT in this run. Once, however often it is said.
     *
     * The other verb ADDS, which is right for a count built from pages and wrong
     * for a fact. "Rule 4 cannot be evaluated" is true once per run whether one
     * record met it or a million did, whether one worker saw it or six, and
     * whether the planner that first noted it ran once or was resumed. Through
     * addAggregate() each of those is another +1, and the number the run ends
     * with measures how often the question was asked.
     *
     * So: the row (run, kind, axis1, axis2) exists with cnt = 1, and saying it
     * again changes nothing a reader can see. SUM(cnt) over a kind is then the
     * number of DISTINCT things marked, which is what ScanPromotion::tally()
     * reports. `blocks` only ever rises and the first `samples` is kept, as
     * addAggregate() does.
     *
     * Safe to retry and safe from two workers at once. A mark carried in
     * commitBatch()'s `marks` is written under that batch's fences; one written
     * directly is unfenced, which suits the planner - it holds the only lease.
     */
    public function markAggregate($runId, $kind, $axis1, $axis2, $blocks = 0, $samples = null);

    /** How many aggregate kinds block coverage. Zero is the only value that permits complete. */
    public function blockingAggregates($runId);
}
