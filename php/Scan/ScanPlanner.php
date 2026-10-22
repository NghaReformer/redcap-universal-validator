<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * Everything a run must decide before it reads its first record — and freeze.
 *
 * This file holds the parts of planning that are pure: how a rule is named so a
 * stored finding still points at it a year later, and how the whole validation
 * configuration is reduced to one hash that says whether two runs were checking
 * the same thing. The manifest walk and the fence capture live in
 * RecordManifestSource and SourceFence; plan() below assembles all three.
 *
 * WHY IDENTITY IS THE FIRST PROBLEM. The legacy scan cited a rule by ORDINAL —
 * its position in the array returned by getRules(), which concatenates the
 * settings rules and then the annotation rules in dictionary field order. Adding
 * one settings row, or moving one field in the Online Designer, renumbers every
 * annotation rule after it. That was harmless while findings lived for the
 * length of one request. The moment they persist, a stored "rule 7" silently
 * starts pointing at a different rule, and nothing in the data can detect it.
 *
 * TWO KINDS OF NAME, FOR TWO KINDS OF RULE:
 *
 *   A settings rule is a row an administrator created and can edit in place. Its
 *   name should survive an edit, so a persistent id stored on the row is the
 *   right answer and is used whenever one is present. Where none is (every
 *   project today, and any row saved by an older build), the fallback is derived
 *   from the rule's CONTENT plus its occurrence among identical siblings — which
 *   is stable under reordering, the failure the ordinal had.
 *
 *   An annotation rule is not a row at all; it is text in a field's annotation.
 *   Its name is therefore where it lives: the fields it is written on, the tag
 *   family, and its occurrence among identical siblings.
 *
 * ONE MERGE HAPPENS UPSTREAM AND IS NOT UNDONE HERE. AnnotationRules::groupMulti
 * collapses byte-identical tags into a single rule carrying several fields, so
 * two identical tags on the same field are already one rule before identity is
 * computed. That is correct — they ARE one rule — but it means identity cannot
 * separate them, and a test pins the behaviour so a future change to grouping
 * shows up here rather than as renamed findings.
 *
 * REVISION IS SEPARATE FROM IDENTITY, deliberately. Editing a rule's pattern
 * keeps its identity and changes its revision: the finding still points at the
 * same rule, and the report can say the rule changed since the run. Collapsing
 * the two would make every edit look like a new rule and every report an
 * incomparable island.
 *
 * PHP 7.4: static methods, no promotion, no match.
 */
final class ScanPlanner
{
    /**
     * The inputs a fingerprint must cover. Missing one is an error, not a
     * default — see fingerprint().
     */
    private static $required = ['engine', 'rules', 'ownership', 'structure', 'choices',
                                'gapPolicy', 'valueMode'];

    /**
     * Inputs that must NEVER enter the fingerprint, and why, keyed by the name a
     * caller would plausibly use.
     *
     * Wording is the whole list. A message catalog edit changes what a finding
     * READS AS; it cannot change whether the finding exists. If wording
     * invalidated the fingerprint, fixing a typo would force every project to
     * re-scan 100,000 records, so typos would not get fixed.
     */
    private static $forbidden = [
        'messages'  => 'message wording cannot change which findings exist',
        'catalog'   => 'message wording cannot change which findings exist',
        'labels'    => 'a label is how a finding is displayed, not whether it is one',
        'wording'   => 'message wording cannot change which findings exist',
    ];

    /** @var ScanStore */
    private $store;
    /** @var string the module's protected secret */
    private $key;
    /** @var bool whether the slice step() is running has committed a page of the walk */
    private $moved = false;

    public function __construct(ScanStore $store, $key)
    {
        $this->store = $store;
        $this->key = $key;
    }

    /**
     * Create a run and list the first slice of its manifest.
     *
     * THE ORDER IS THE SAFETY. Everything that can refuse does so BEFORE a run
     * exists, because a refused start costs a message and an abandoned run costs
     * the project its scan slot until something expires it. Once the run does
     * exist, every remaining failure finishes it terminally rather than leaving
     * it - a planner that dies quietly is indistinguishable from one still
     * working, and the difference is a project that cannot scan again.
     *
     * ONE SLICE, NOT THE WHOLE LIST (B10). This walked, hashed and inserted the
     * entire record list inside the scan-start request, and ScanService passed
     * it no deadline and no memory cap. A project large enough to outlast
     * max_execution_time died mid-walk, an uncatchable fatal, and the run sat
     * in `planning` holding the project's slot with nothing able to move it.
     * Now the start lists what its budget allows - see step() - and hands back
     * a run still planning when there is more; the browser's work requests
     * list the rest, a slice each, through ScanWorker. A run whose list
     * finished inside the first slice comes back frozen, as it always did.
     *
     * @param array $req {
     *   source:        RecordManifestSource, required
     *   fence:         ?SourceFence
     *   rules:         array   the live rule list, as getRules() produced it
     *   settingsCount: int     how many of those came from settings
     *   ownership:     array   field => owning form
     *   structure:     array   events, arms, repeating shape
     *   choices:       array
     *   policy:        array   from ScanPolicy::resolve()
     *   engine:        string  the validation engine's version
     *   dagFilter:     ?string scope this run to one group. THE NUMERIC GROUP
     *                  ID, as a string - never the friendly DAG name. It is
     *                  compared at step() against redcap_record_list.dag_id
     *                  and stored verbatim as scope_dag, which
     *                  ScanAuthorization then compares against
     *                  $rights['group_id']. ScanPageView::scanScope() is the
     *                  only production producer and returns the id as 'dag';
     *                  its 'dagName' is for prose and must never arrive here.
     *   createdBy:     string
     *   generation:    int
     *   pageSize:      int
     *   budget:        WorkBudget|false  what the first slice may spend. ABSENT
     *                  IS A BROWSER BUDGET, not "no limit": an unbounded walk
     *                  is the defect, so a caller has to ask for one by
     *                  passing false, and only a test does.
     *   owner:         string  the planning lease's owner; one is made up when absent
     *   attempts:      int     slices in a row that may die on one page (step())
     *   leaseSeconds:  int     how long the planning lease lasts unrenewed
     * }
     * @return array{ok:bool, busy:bool, run:?array, why:?string, stats:array}
     */
    public function plan($pid, array $req)
    {
        $src = isset($req['source']) ? $req['source'] : null;
        if (!($src instanceof RecordManifestSource)) {
            return self::no('this installation cannot list the project\'s records without '
                . 'exporting it, so no scan may start');
        }
        $dag = isset($req['dagFilter']) && $req['dagFilter'] !== '' ? $req['dagFilter'] : null;
        if ($src->isEmpty()) {
            // A PROJECT WITH NO RECORDS IS REFUSED BEFORE A RUN EXISTS, as it
            // was when open() refused it. open() hands the empty walk out now
            // (review R5-7) so that a run whose records are all deleted while
            // it is listing them can end over it; a start has nothing to list.
            return self::no(self::emptyWhy($dag));
        }
        if ($dag !== null && !$src->hasDag()) {
            // Running anyway would build a project-wide manifest for a user
            // entitled to one group. The persisted report is readable later by
            // whoever can open the page, so the scope has to be right when the
            // manifest is written, not when it is displayed.
            return self::no('this installation cannot tell which group a record belongs to, so a '
                . 'group-scoped scan cannot be run here');
        }
        $rules = isset($req['rules']) && is_array($req['rules']) ? $req['rules'] : [];
        if (!$rules) {
            return self::no('this project has no validation rules, so there is nothing to scan for');
        }

        $ids = self::identifyAll($rules, isset($req['settingsCount']) ? $req['settingsCount'] : null);
        try {
            $fp = self::requestFingerprint($req);
        } catch (\InvalidArgumentException $e) {
            // A fingerprint that could not be built is not a fingerprint that
            // can be skipped: without one, a resumed run cannot tell whether the
            // configuration moved underneath it.
            return self::no('this run could not be described well enough to detect a later '
                . 'configuration change, so it was not started');
        }

        // The opening fence, BEFORE the manifest. Captured after it, any change
        // during the walk would fall outside the window the run believes it
        // covers - which is the one gap a fence exists to close.
        $fence = isset($req['fence']) && $req['fence'] instanceof SourceFence ? $req['fence'] : null;
        $open = $fence === null ? null : $fence->now();

        $started = $this->store->startRun($pid, [
            'created_by'    => isset($req['createdBy']) ? $req['createdBy'] : '',
            'scope_dag'     => $dag,
            // NO generation here. It was one of three places that defaulted
            // it to 1, and between them no caller ever supplied one - so
            // every run of every project on the installation wrote
            // generation 1, and the second scan of anything re-inserted
            // identities that were already active. The store allocates it
            // now, from a per-project sequence, and is the only thing that
            // can: allocation has to be atomic with the run insert or two
            // starts race for the same number.
            'run_kind'      => isset($req['runKind']) ? $req['runKind'] : 'full',
            'baseline_generation' => isset($req['baseline']) ? $req['baseline'] : null,
            'fingerprint'   => $fp,
            'fence_open'    => $open,
            'policy_json'   => json_encode(self::storedPolicy($req)),
            'values_state'  => isset($req['policy']['valueMode'])
                               ? $req['policy']['valueMode'] : 'none',
        ]);
        if (empty($started['ok'])) {
            // Busy travels unchanged, including its deliberately uninformative
            // wording: which run holds the slot is never disclosed.
            return ['ok' => false, 'busy' => true, 'run' => null,
                    'why' => isset($started['why']) ? $started['why'] : null, 'stats' => []];
        }
        $run = $started['run'];
        $runId = (int) $run['run_id'];

        // WHAT THIS RUN ALREADY KNOWS IT CANNOT CHECK, written before it reads a
        // record. The plan knows at this point which rules have a configuration
        // error, cannot be located on an instrument, sit on an instrument no
        // event collects, or cannot be decided under a group scope. None of that
        // was ever recorded, so ScanOutcome's `ruleProblems` term had no
        // producer and `clean` silently meant "no findings" - a project whose
        // rules enforce nothing got the same certificate as one whose rules all
        // pass.
        //
        // A FAILURE HERE FINISHES THE RUN rather than leaving it. Everything
        // after startRun() in this method obeys that rule - see the method note,
        // an abandoned run holds the project scan slot - and it matters twice as
        // much here: a scan that could not record what it cannot check must not
        // be the scan that goes on to certify the project without it.
        $problems = isset($req['ruleProblems']) && is_array($req['ruleProblems'])
            ? $req['ruleProblems'] : [];
        try {
            ScanPromotion::noteRuleProblems($this->store, $runId, $problems);
        } catch (\Throwable $e) {
            $this->store->finish($runId, ScanOutcome::derive(['failed' => true]));
            return self::no('this scan could not record which of the project rules it is unable '
                . 'to evaluate, so it was not started rather than started without them');
        }

        // THE FIRST SLICE. Its own budget unless the caller brought one: see
        // the `budget` note above for why absence is not "no limit".
        $slice = $this->step($pid, $runId, array_merge($req, [
            'source'    => $src,
            'dagFilter' => $dag,
            'epoch'     => isset($run['lease_epoch']) ? (int) $run['lease_epoch'] : 0,
            'owner'     => (isset($req['owner']) && is_string($req['owner']) && $req['owner'] !== '')
                           ? $req['owner'] : 'planner:' . bin2hex(random_bytes(6)),
            'budget'    => array_key_exists('budget', $req)
                           ? $req['budget'] : new WorkBudget(['mode' => 'browser']),
        ]));
        $stats = $slice['stats'];

        if (!empty($slice['frozen'])) {
            if (!empty($slice['empty'])) {
                // step() has finished the run as an empty scope, with the rule
                // problems this method recorded above. A refusal, as it always
                // was: see step() for why an empty manifest is not a clean one.
                return ['ok' => false, 'busy' => false, 'run' => null, 'stats' => $stats,
                        'why' => $slice['why']];
            }
            return ['ok' => true, 'busy' => false, 'run' => $this->store->run($pid, $runId),
                    'why' => null, 'stats' => $stats];
        }
        if (!empty($slice['ok']) && $slice['phase'] === ScanPhase::PLANNING) {
            // MORE TO LIST. The run exists, is leased to nobody, and the next
            // work request lists the next slice.
            return ['ok' => true, 'busy' => false, 'run' => $this->store->run($pid, $runId),
                    'why' => null, 'stats' => $stats];
        }
        if ($slice['phase'] === ScanPhase::PLANNING) {
            // The first page could not be read. A later slice would try again,
            // but nobody has been told there is a run to drive yet: the start
            // is refused and the run ended, as it was before planning resumed.
            // Terminal, not abandoned - see the method note.
            $this->store->finish($runId, ScanOutcome::derive(['failed' => true]));
        }
        return ['ok' => false, 'busy' => false, 'run' => null, 'why' => $slice['why'],
                'stats' => $stats];
    }

    /**
     * List one budgeted slice of a run's manifest, and freeze it when the
     * source says there is nothing left (B10).
     *
     * THE SLICE IS FENCED, COUNTED AND RESUMABLE:
     *
     *   claimPlan() leases the run's planning to this request and counts the
     *   attempt before anything is tried - wave 5's rule for records, I3b,
     *   because a request that dies at its time or memory limit runs no code
     *   afterwards that could count it. maxAttempts is the worker's own
     *   (policy `recordAttempts`); a page that has killed that many slices in
     *   a row is retired, and with it the run, which ends `failed` with
     *   PLAN_GAVE_UP rather than sitting in `planning` holding the project.
     *
     *   Each page, its cursor and carry, the counters and the run's progress
     *   clock are one appendManifest() transaction. A request that dies leaves
     *   both or neither, and the next one carries on from the last page that
     *   committed.
     *
     *   The FIRST page of a slice always runs, so every request moves the walk
     *   even when the host spent the budget before the scan began (review
     *   BU-2's rule); after it, the budget decides. Whether another page will
     *   follow is decided BEFORE the append, so the last page of a slice hands
     *   the lease back in the same write.
     *
     *   An append refused because the run left planning - Stop, the reaper, a
     *   finish elsewhere - ends the walk AT ONCE, and the answer says the run
     *   was stopped (review R3U-2). The walk used to ignore the refusal, read
     *   the rest of the project for nothing, and then answer the Start tab with
     *   "the record list could not be frozen".
     *
     * ONE PAGE IN MEMORY. A page is read, hashed, appended and dropped; the
     * carry between pages is the boundary group, at most TIE_CAP ids. Nothing
     * earlier is read again: the cursor is a keyset position.
     *
     * @param array $req {
     *   source:       ?RecordManifestSource  null is a source that could not be opened,
     *                 which is a failed attempt like a page that could not be read
     *   dagFilter:    ?string  the run's scope_dag - see plan()
     *   pageSize:     int      rows per page; 1000 when absent. See PAGE_SIZE.
     *   budget:       ?WorkBudget|false  null or false is unbounded
     *   owner:        string   the lease's owner, the same for the whole request
     *   epoch:        int      the run's lease_epoch as this request read it
     *   attempts:     int      slices in a row that may die on one page
     *   leaseSeconds: int
     *   keep:         ?callable(): bool  renews the worker's installation slot;
     *                 false stops the slice after the page in hand
     *   limits:       array    maxFindings and maxBytes, for a stopped run's finish
     * }
     * AN EXCEPTION THAT LEAVES A SLICE HANDS THE LEASE BACK FIRST (review
     * R5-6). A page whose append failed at the store - a deadlock, a lock wait,
     * a lost connection - left the lease held, so for the next five minutes
     * every request was told another request was listing the project, while
     * none was. The attempt was counted at the claim, so the release costs the
     * retry rule nothing. It is best effort: a database that has just failed
     * may refuse that write too, and the lease then lapses as it always did.
     *
     * @return array{ok:bool, phase:string, frozen:bool, empty:bool, total:?int,
     *               listed:int, moved:bool, stop:?string, why:?string, stats:array}
     *         `listed` is what THIS request listed inside the run's scope (review
     *         R5-1); `moved` whether it committed a page of the walk at all, in
     *         scope or not, which is what the page paces itself by; `stats` the
     *         walk so far, every row read, and never shown.
     */
    public function step($pid, $runId, array $req)
    {
        $this->moved = false;
        try {
            $out = $this->listSlice($pid, $runId, $req);
            $out['moved'] = $this->moved;
            return $out;
        } catch (\Throwable $e) {
            $owner = (isset($req['owner']) && is_string($req['owner'])) ? $req['owner'] : '';
            if ($owner !== '') {
                try {
                    $this->store->releasePlan($runId, $owner);
                } catch (\Throwable $ignored) {
                    // The failure the caller is about to hear about is the one that matters.
                }
            }
            throw $e;
        }
    }

    /** The body of step(), which owns the release on the way out. */
    private function listSlice($pid, $runId, array $req)
    {
        $owner = (isset($req['owner']) && is_string($req['owner'])) ? $req['owner'] : '';
        $epoch = isset($req['epoch']) ? (int) $req['epoch'] : 0;
        $cap = isset($req['attempts']) ? max(1, (int) $req['attempts']) : ScanPolicy::D_RECORD_ATTEMPTS;
        $lease = isset($req['leaseSeconds']) ? max(1, (int) $req['leaseSeconds']) : self::LEASE_SECONDS;
        $budget = (isset($req['budget']) && $req['budget'] instanceof WorkBudget) ? $req['budget'] : null;
        $keep = (isset($req['keep']) && is_callable($req['keep'])) ? $req['keep'] : null;
        $src = (isset($req['source']) && $req['source'] instanceof RecordManifestSource)
            ? $req['source'] : null;
        $dag = isset($req['dagFilter']) && $req['dagFilter'] !== '' ? (string) $req['dagFilter'] : null;
        // P4: DO NOT REDUCE THIS TO MAKE PLANNING FASTER. Measured, 6.3 s of an
        // 8 s plan was database wait spread over 809 queries; the cost is per
        // page, so halving the page doubles the queries and the wait with them.
        $pageSize = isset($req['pageSize']) ? max(1, (int) $req['pageSize']) : self::PAGE_SIZE;
        $listedNow = 0;
        $appended = 0;
        $stats = ['pages' => 0, 'listed' => 0, 'appended' => 0, 'outOfScope' => 0];

        // A REQUEST THAT CANNOT HOLD EVEN ONE PAGE says so before it takes the
        // lease: the next request would arrive with the same footprint.
        if ($budget !== null) {
            $gate = $budget->admit();
            if ($gate !== null && empty($gate['resumable'])) {
                return self::slice(false, ScanPhase::PLANNING, $gate['stop'], $gate['why'], 0, $stats);
            }
        }

        $claim = $this->store->claimPlan($runId, $owner, $epoch, $lease, $cap);
        if ($claim === false) {
            return $this->notPlanning($pid, $runId, $req, 0, $stats);
        }
        $stats['pages'] = (int) $claim['pages'];
        $stats['listed'] = (int) $claim['listed'];
        $stats['outOfScope'] = (int) $claim['outOfScope'];
        if ($claim['state'] === 'held') {
            return self::slice(true, ScanPhase::PLANNING, 'held', self::PLAN_HELD, 0, $stats);
        }
        if ($claim['state'] === 'exhausted') {
            // RETIRED, as a record out of attempts is - and a manifest with a
            // page nobody can list is no manifest, so the run ends with it.
            // Through the finish every unrecoverable failure takes; the
            // sentence is the stored reason, because it is the only record of
            // why this run never examined anything.
            $out = array_merge(ScanOutcome::derive(['failed' => true]), ['why' => self::PLAN_GAVE_UP]);
            $this->store->finish($runId, $out);
            return self::slice(false, ScanPhase::TERMINAL, 'failed', self::PLAN_GAVE_UP, 0, $stats);
        }

        if ($claim['state'] === 'ok') {
            $at = self::resumeOn($claim['carry'], $src, $claim['cursor']);
            $src = $at['source'];
            $cursor = $at['cursor'];
            $carry = $at['carry'];
            if ($at['restart']) {
                $stats['listed'] = 0;
                $stats['outOfScope'] = 0;
            }
            if ($src === null) {
                $this->store->releasePlan($runId, $owner);
                return self::slice(false, ScanPhase::PLANNING, 'read', self::NO_SOURCE, 0, $stats);
            }

            while (true) {
                $mark = $budget === null ? null : $budget->mark('manifest');
                $pg = $src->page($cursor, $carry, $pageSize);
                if (!empty($pg['untrusted'])) {
                    // THE INDEX STOPPED BEING THE CACHE THIS WALK OPENED (review
                    // R5-3): REDCap reset or rebuilt it after open(). The page
                    // is not used, nothing is frozen, and the walk moves to the
                    // data table, which is whole, in this same slice.
                    //
                    // Moved rather than ended. A slice that only ended would
                    // leave the next request to open the index again, and an
                    // index REDCap keeps rebuilding would end every slice at its
                    // first page: no page appended, an attempt counted at every
                    // claim, and the run retired with PLAN_GAVE_UP over a project
                    // nothing was wrong with.
                    //
                    // FROM THE FIRST RECORD. A cursor is a position in one
                    // table's collation and the data table's need not be the
                    // index's; the records already appended are offered again
                    // and land once (the manifest's unique key). It happens once
                    // per run: the carry now names the data table, and a walk
                    // that has used the data table stays on it (resumeOn()).
                    $data = $src->dataWalk();
                    if ($data === null || $data === $src) {
                        $this->store->releasePlan($runId, $owner);
                        return self::slice(false, ScanPhase::PLANNING, 'read',
                            isset($pg['why']) ? $pg['why'] : self::NO_SOURCE, $listedNow, $stats);
                    }
                    $src = $data;
                    $cursor = null;
                    $carry = [];
                    $stats['listed'] = 0;
                    $stats['outOfScope'] = 0;
                    continue;
                }
                if (empty($pg['ok'])) {
                    // The attempt was counted when the lease was taken. The lease
                    // goes back now, so trying again does not wait it out.
                    $this->store->releasePlan($runId, $owner);
                    return self::slice(false, ScanPhase::PLANNING, 'read',
                        isset($pg['why']) ? $pg['why'] : self::NO_SOURCE, $listedNow, $stats);
                }
                $batch = [];
                foreach ($pg['rows'] as $row) {
                    // THE WALK'S OWN COUNT, every row read: it is kept on the
                    // plan row and never shown. $listedNow, which the work
                    // answer reports, is counted below, inside the scope.
                    $stats['listed']++;
                    // BOTH SIDES ARE GROUP IDS. $row['dag'] is
                    // redcap_record_list.dag_id (or the __GROUPID__ value on the
                    // data-table fallback), which REDCap stores as the numeric
                    // group id; $dag is scanScope()['dag'], which is the same. A
                    // friendly name on either side matches nothing, and the failure
                    // is silent in the worst possible direction: every record is
                    // counted out of scope, the manifest freezes at zero, and the
                    // run promotes to a clean certificate over nothing. The
                    // outOfScope counter below does NOT catch it - ScanService::start
                    // discards the whole stats array, and outOfScope === listed is
                    // produced identically by a group that genuinely holds no
                    // records. The gate is the domain test, not the counter.
                    if ($dag !== null && (string) $row['dag'] !== (string) $dag) {
                        $stats['outOfScope']++;
                        continue;
                    }
                    // ONLY WHAT THE SCOPE ADMITS IS REPORTED (review R5-1).
                    // Counted per row read, this told a reader confined to one
                    // group how many records the other groups hold, a page at
                    // a time; CatchUp::step() answers its `listed` the same way.
                    $listedNow++;
                    $batch[] = ['id_bin' => $row['id'],
                                'hash' => Hmac::raw(Hmac::P_RECORD, $pid, $row['id'], $this->key),
                                'dag' => $row['dag']];
                }
                $done = !empty($pg['done']);
                // WILL ANOTHER PAGE FOLLOW? Asked before the append, so a slice's
                // last page hands the lease back in its own transaction. The
                // page that ends the list hands it back too: the freeze needs no
                // lease, and one left on a frozen run is a worker holding it as
                // far as Stop can tell - a Stop pressed in the two minutes after
                // a small project's Start waited for a worker that never came.
                $more = false;
                $stop = null;
                $why = null;
                if (!$done) {
                    $gate = $budget === null ? null : $budget->admit();
                    if ($gate !== null) {
                        $stop = $gate['stop'];
                        $why = $gate['why'];
                    } elseif ($keep !== null && !$keep()) {
                        $stop = 'slot';
                        $why = self::LOST_SLOT;
                    } else {
                        $more = true;
                    }
                }
                $n = $this->store->appendManifest($runId, $batch, [
                    'owner' => $owner, 'epoch' => $epoch,
                    'cursor' => $pg['cursor'],
                    'carry' => self::carryOut($src->via(), $pg['emitted']),
                    'listed' => $stats['listed'], 'outOfScope' => $stats['outOfScope'],
                    'done' => $done, 'release' => !$more, 'leaseSeconds' => $lease,
                ]);
                if ($n === false) {
                    // Nothing of this page was written; it is not counted.
                    $stats['listed'] -= count($pg['rows']);
                    $stats['outOfScope'] -= (count($pg['rows']) - count($batch));
                    return $this->notPlanning($pid, $runId, $req, $listedNow - count($batch), $stats);
                }
                $this->moved = true;
                $appended += (int) $n;
                $stats['appended'] = $appended;
                $stats['pages']++;
                if ($budget !== null) $budget->observe($mark, max(1, count($pg['rows'])));
                $cursor = $pg['cursor'];
                $carry = $pg['emitted'];
                unset($pg, $batch);
                if ($done) break;
                if (!$more) {
                    return self::slice(true, ScanPhase::PLANNING, $stop, $why, $listedNow, $stats);
                }
            }
        }

        // THE SOURCE SAID DONE, IN A COMMITTED WRITE. freezeManifest() checks
        // that for itself; a walk that merely returned is not enough.
        $total = $this->store->freezeManifest($runId);
        if ($total === false) {
            return $this->notPlanning($pid, $runId, $req, $listedNow, $stats);
        }
        $stats['total'] = (int) $total;

        // AN EMPTY MANIFEST IS NOT A CLEAN PROJECT.
        //
        // freezeManifest() answers `false` for a refusal and an INT for a
        // census, and the guard above tests only for `false` - so 0 fell through
        // into `ok => true`. The run then walked four phases over nothing,
        // promoted with `pending === 0` reading as a finished manifest, and the
        // page printed "Every record was checked, including changes made while
        // it ran." beside "Preparing" and "Nothing found yet". Reproduced end to
        // end against these classes before this line was written.
        //
        // Closed HERE, and not only at promotion, because a refusal costs a
        // sentence while a started run costs the project its scan slot and shows
        // somebody a progress bar for a scan that will certify nothing. The
        // promotion-side fact stays as the backstop for a manifest emptied after
        // it was frozen, which this check cannot see.
        //
        // WITH THE RULE PROBLEMS THE RUN RECORDED (review EE-4). finish()
        // writes rule_problem_count from the outcome, and derive() does not
        // carry the count - promote() adds it. They are read back from the
        // store rather than handed over, because the slice that freezes is not
        // necessarily the request that noted them.
        if ((int) $total === 0) {
            $noted = ScanPromotion::tally($this->store->aggregates($runId))['ruleProblems'];
            $empty = ScanOutcome::derive(['emptyScope' => true, 'ruleProblems' => $noted]);
            $empty['ruleProblems'] = (int) $noted;
            $this->store->finish($runId, $empty);
            // The group wording does NOT assert that the group is empty,
            // because this code cannot tell that apart from a scope compared on
            // the wrong axis - and a false statement about the project, made by
            // the guard that exists to stop false statements, would be the
            // worst possible place for one. No counts in the sentence either:
            // how many records the project holds is not something a
            // group-scoped user is entitled to. The walk counters travel in
            // `stats`: `listed` and `outOfScope` distinguish a genuinely empty
            // group from a scope value compared on the wrong axis.
            $why = self::emptyWhy($dag);
            $out = self::slice(false, ScanPhase::TERMINAL, 'empty', $why, $listedNow, $stats);
            $out['frozen'] = true;
            $out['empty'] = true;
            $out['total'] = 0;
            return $out;
        }
        $out = self::slice(true, ScanPhase::SCANNING, null, null, $listedNow, $stats);
        $out['frozen'] = true;
        $out['total'] = (int) $total;
        return $out;
    }

    /** Rows per page when the caller does not say. See the P4 note in step(). */
    const PAGE_SIZE = 1000;

    /** The planning lease when the caller does not say: ScanService::SLOT_TTL. */
    const LEASE_SECONDS = 300;

    /** The stored reason of a run whose planning was retired. Under 255 bytes: it is terminal_reason. */
    const PLAN_GAVE_UP = 'planning could not finish: listing this project\'s records failed on the '
        . 'same page in every attempt it was given, so the scan was ended without examining a record';

    /** A slice that found another request listing. */
    const PLAN_HELD = 'another request is listing this project\'s records; this one will continue '
        . 'shortly';

    /** A slice that could not open the source at all. */
    const NO_SOURCE = 'this project\'s record list could not be opened, so no records were listed';

    /** A slice whose worker slot was taken. The worker's own sentence for the same event. */
    const LOST_SLOT = 'this scan lost its place in the server queue to another scan while it was '
        . 'working; it will continue shortly';

    /** A walk whose run was stopped under it. */
    const PLAN_STOPPED = 'this scan was stopped while it was listing the project\'s records, so no '
        . 'record was examined';

    /** A run, or a start, over a project that holds no records. */
    const EMPTY_PROJECT = 'this project has no records, so there was nothing to scan';

    /** The same for a group-scoped one. See step() for why it asserts nothing about the group. */
    const EMPTY_GROUP = 'no records were found in the Data Access Group this scan was scoped '
        . 'to, so there was nothing to scan. If you expect records in that '
        . 'group, ask an administrator to check the scan scope before treating '
        . 'this as an empty group.';

    /** The empty-scope sentence for a run scoped to $dag, or to the whole project when null. */
    public static function emptyWhy($dag)
    {
        return ($dag === null || $dag === '') ? self::EMPTY_PROJECT : self::EMPTY_GROUP;
    }

    /**
     * An append, a claim or the freeze was refused: the run is not planning
     * under this request any more. Say what it is doing instead, truthfully
     * (review R3U-2).
     *
     * STOPPED is finished here, through the one finisher Stop and the worker
     * use, and reported as stopped. ANOTHER PLANNER that took the lease over
     * is reported as `held`: the walk is in hands that are moving it. FROZEN by
     * another request carries on as frozen. Anything else is refused.
     */
    private function notPlanning($pid, $runId, array $req, $listedNow, array $stats)
    {
        $limits = (isset($req['limits']) && is_array($req['limits'])) ? $req['limits'] : [];
        $run = $this->store->run($pid, $runId);
        $phase = ($run !== null && isset($run['phase'])) ? (string) $run['phase'] : '';
        // A STOP IS NOT A FAILURE: ok is true for it, as the worker answers a
        // run it finds `cancelling`. plan() refuses on any terminal phase.
        if ($phase === ScanPhase::CANCELLING) {
            ScanPromotion::finishCancelled($this->store, $pid, $runId, $limits);
            return self::slice(true, ScanPhase::TERMINAL, 'cancelled', self::PLAN_STOPPED,
                               $listedNow, $stats);
        }
        if ($phase === ScanPhase::TERMINAL) {
            $stopped = isset($run['terminal']) && $run['terminal'] === ScanOutcome::CANCELLED;
            return self::slice($stopped, ScanPhase::TERMINAL, $stopped ? 'cancelled' : 'finished',
                $stopped ? self::PLAN_STOPPED
                         : 'this scan ended while it was listing the project\'s records, so it '
                           . 'took no more work', $listedNow, $stats);
        }
        if ($phase === ScanPhase::PLANNING) {
            return self::slice(true, ScanPhase::PLANNING, 'held', self::PLAN_HELD, $listedNow, $stats);
        }
        if ($run !== null && ScanPhase::mayWork($phase)) {
            // Another request froze the manifest between this one's last page and its freeze.
            $out = self::slice(true, $phase, null, null, $listedNow, $stats);
            $out['frozen'] = true;
            $out['total'] = isset($run['manifest_total']) ? (int) $run['manifest_total'] : null;
            return $out;
        }
        return self::slice(false, $phase === '' ? ScanPhase::TERMINAL : $phase, 'refused',
            'no scan with that reference is running for this project', $listedNow, $stats);
    }

    private static function slice($ok, $phase, $stop, $why, $listed, array $stats)
    {
        return ['ok' => (bool) $ok, 'phase' => $phase, 'frozen' => false, 'empty' => false,
                'total' => null, 'listed' => max(0, (int) $listed), 'stop' => $stop, 'why' => $why,
                'stats' => $stats];
    }

    /**
     * The carry, as the plan row keeps it: the source it belongs to, and the
     * boundary group's ids hex-encoded, because an id is bytes and the column
     * is a blob the store does not read. At most TIE_CAP ids of at most 255
     * bytes, which is why plan_carry is a MEDIUMBLOB.
     */
    private static function carryOut($via, array $emitted)
    {
        $hex = [];
        foreach ($emitted as $id) $hex[] = bin2hex((string) $id);
        return json_encode(['v' => 1, 'via' => (string) $via, 'carry' => $hex]);
    }

    /**
     * The carry back: the source it was written over and the boundary ids,
     * `via` null for a walk that has not begun (no cursor, no carry), or null
     * for a carry that cannot be read.
     *
     * @return ?array{via:?string, ids:string[]}
     */
    private static function carryIn($raw, $cursor)
    {
        if ($raw === null || $raw === '') return $cursor === null ? ['via' => null, 'ids' => []] : null;
        $c = json_decode((string) $raw, true);
        if (!is_array($c) || !isset($c['v']) || (int) $c['v'] !== 1 || !isset($c['via'])
                || !is_string($c['via']) || !isset($c['carry']) || !is_array($c['carry'])) {
            return null;
        }
        $out = [];
        foreach ($c['carry'] as $h) {
            if (!is_string($h) || !ctype_xdigit($h) || strlen($h) % 2 !== 0) return null;
            $out[] = (string) hex2bin($h);
        }
        return ['via' => $c['via'], 'ids' => $out];
    }

    /**
     * Where this slice takes the walk up, and over which source (review R5-2).
     *
     * A cursor is a position in one table's collation, and the carry is the
     * set of ids that collation calls equal to it. open() prefers the record
     * index and falls back to the data table, and which one answers changes
     * between requests while REDCap rebuilds its cache - so the walk used to
     * start again from the first record at every change, and with the source
     * alternating it never ended, while each request listed rows and moved
     * progress_at, so nothing called it stalled or reaped it.
     *
     * THE DATA TABLE IS STICKY. A walk whose carry names a data table goes on
     * over the data table, whatever this request opened: the data table is
     * whole, and nothing about the index becoming complete again makes the
     * walk's own position wrong. So the source can change under a walk once,
     * from the index to the data table (here, or mid-slice in listSlice()),
     * and that change starts the walk again from the first record. Twice only
     * if REDCap moves the project to another data table while it is listed.
     * The rework is bounded by what was listed before the change, and what it
     * offers again lands once on the manifest's unique key.
     *
     * NOT A CURSOR CARRIED ACROSS. redcap_record_list.record and the data
     * table's record are separate columns whose collations each installation
     * set when the table was created, and REDCap has created them in different
     * versions; ordering, case folding and trailing-space padding are all the
     * collation's. A cursor is only portable where both columns share one, and
     * no test suite can prove that of an installation it has not seen.
     *
     * @return array{source:?RecordManifestSource, cursor:?string, carry:string[], restart:bool}
     */
    private static function resumeOn($raw, $src, $cursor)
    {
        if (!($src instanceof RecordManifestSource)) {
            return ['source' => null, 'cursor' => $cursor, 'carry' => [], 'restart' => false];
        }
        $c = self::carryIn($raw, $cursor);
        if ($c === null) {
            // A carry that cannot be read: nothing this file writes, so start again.
            return ['source' => $src, 'cursor' => null, 'carry' => [], 'restart' => true];
        }
        if ($c['via'] === null) {
            return ['source' => $src, 'cursor' => null, 'carry' => [], 'restart' => false];
        }
        if ($c['via'] !== RecordManifestSource::VIA_INDEX && $src->via() === RecordManifestSource::VIA_INDEX) {
            // The walk has used the data table: it stays there. A data table
            // that cannot be walked now is a source that cannot be opened.
            $src = $src->dataWalk();
            if ($src === null) {
                return ['source' => null, 'cursor' => $cursor, 'carry' => [], 'restart' => false];
            }
        }
        if ($c['via'] === $src->via()) {
            return ['source' => $src, 'cursor' => $cursor, 'carry' => $c['ids'], 'restart' => false];
        }
        return ['source' => $src, 'cursor' => null, 'carry' => [], 'restart' => true];
    }

    private static function no($why)
    {
        return ['ok' => false, 'busy' => false, 'run' => null, 'why' => $why, 'stats' => []];
    }

    // -- canonical encoding --------------------------------------------------

    /**
     * A byte string that is the same for equal values and different for unequal
     * ones, with no dependency on character encoding.
     *
     * NOT json_encode, and this is the module's own hard-won reason. The L-01
     * finding recorded that record values can carry invalid UTF-8 from a Latin-1
     * import; json_encode returns FALSE on those, and the substitute flag
     * collapses every distinct invalid byte to U+FFFD — a lossy, data-
     * constructible collision, which is exactly the defect L-01 was. A
     * fingerprint built on it would report two different configurations as the
     * same one.
     *
     * Every value is tagged with its type before its contents, so 1, "1", 1.0
     * and true encode differently. PHP's array keys make that necessary rather
     * than fussy: $a["1"] and $a[1] are the same key, and without the tag a map
     * keyed by strings would collide with one keyed by integers.
     *
     * Strings are hex, which doubles the length and is worth it: the alternative
     * is a delimiter, and a delimiter is a thing a value can contain.
     */
    public static function canonical($v)
    {
        if ($v === null) return 'n';
        if (is_bool($v)) return $v ? 'b1' : 'b0';
        if (is_int($v)) return 'i' . $v;
        if (is_float($v)) {
            // NAN != NAN, so it has no canonical form as a number; INF has no
            // decimal one. Both are named rather than printed, because
            // sprintf's output for them varies by platform.
            if (is_nan($v)) return 'dNAN';
            if (is_infinite($v)) return ($v > 0 ? 'dINF' : 'd-INF');
            // 17 significant digits round-trips every IEEE 754 double.
            return 'd' . sprintf('%.17G', $v);
        }
        if (is_string($v)) return 's' . strlen($v) . ':' . bin2hex($v);
        if (is_array($v)) {
            if (self::isList($v)) {
                $out = 'l' . count($v) . ':';
                foreach ($v as $x) $out .= self::canonical($x) . ',';
                return $out . ';';
            }
            $keys = array_keys($v);
            // SORT_STRING, not the default: the default sort compares numeric
            // strings numerically, so ["10" => a, "9" => b] would order
            // differently from ["10" => a, "9" => b] read back as integers.
            // Byte order is the one comparison both PHP and this file agree on.
            usort($keys, function ($a, $b) {
                return strcmp((string) $a, (string) $b);
            });
            $out = 'm' . count($v) . ':';
            foreach ($keys as $k) {
                $out .= self::canonical($k) . '=' . self::canonical($v[$k]) . ',';
            }
            return $out . ';';
        }
        if (is_object($v)) {
            // An object in a fingerprint input is a caller mistake, and a silent
            // cast to array would hash its private state. Say so instead.
            throw new \InvalidArgumentException('canonical(): objects have no canonical form ('
                . get_class($v) . ')');
        }
        // Resources and anything else: refuse rather than guess.
        throw new \InvalidArgumentException('canonical(): unsupported type ' . gettype($v));
    }

    /** A PHP list: sequential integer keys from zero. */
    private static function isList(array $a)
    {
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i) return false;
            $i++;
        }
        return true;
    }

    // -- rule identity -------------------------------------------------------

    /**
     * The stable name and revision of one rule.
     *
     * @param array  $rule       as getRules() produced it
     * @param string $origin     'settings' | 'annotation'
     * @param int    $occurrence which identical sibling this is, zero-based
     * `stem` is the name WITHOUT the occurrence suffix. It is returned rather
     * than recovered by a caller, because recovering it means stripping a
     * trailing ":<digits>" and a persistent id is allowed to end in one.
     *
     * @return array{source_id:string, revision:string, origin:string, stem:string}
     */
    public static function identify(array $rule, $origin, $occurrence = 0)
    {
        $rev = self::revision($rule);
        $occ = (int) $occurrence;

        if ($origin === 'settings') {
            // A persistent id stored on the row is the right answer, because it
            // survives editing the rule - which is the one thing a content hash
            // cannot do. Every project today has none, so the fallback below is
            // what actually runs; it is content-derived and therefore stable
            // under the reordering that broke the ordinal.
            foreach (['uid', 'rule-uid', 'ruleUid'] as $k) {
                if (isset($rule[$k]) && is_string($rule[$k]) && $rule[$k] !== '') {
                    // An issued id is already unique; occurrence never applies
                    // to it, so the stem IS the name.
                    return ['source_id' => 'uid:' . $rule[$k], 'revision' => $rev,
                            'origin' => $origin, 'stem' => 'uid:' . $rule[$k]];
                }
            }
            $stem = 'set:' . substr($rev, 0, 32);
            return ['source_id' => $stem . ':' . $occ, 'revision' => $rev,
                    'origin' => $origin, 'stem' => $stem];
        }

        // An annotation rule is text living on fields, so its name is where it
        // lives. The field list is sorted: groupMulti builds it in dictionary
        // order, and moving a field in the Online Designer must not rename the
        // rule written on it.
        $fields = isset($rule['fields']) && is_array($rule['fields']) ? $rule['fields'] : [];
        $fields = array_map('strval', $fields);
        sort($fields, SORT_STRING);
        $where = substr(hash('sha256', self::canonical($fields)), 0, 32);
        $stem = 'ann:' . $where . ':' . self::family($rule);
        return ['source_id' => $stem . ':' . $occ, 'revision' => $rev,
                'origin' => $origin, 'stem' => $stem];
    }

    /**
     * A rule's content hash: what changing the rule changes.
     *
     * `fields` is excluded on purpose. Which fields a rule covers is part of
     * WHERE it applies, and every finding already records its own field; folding
     * it in would make adding one field to a rule read as a different rule, and
     * every finding on the untouched fields would be attributed to a rule that
     * no longer exists.
     */
    public static function revision(array $rule)
    {
        unset($rule['fields']);
        // Authoring metadata that exists to be read by a person: a renamed rule
        // is the same rule. Excluded so an administrator can improve a label
        // without invalidating a 100,000-record baseline.
        unset($rule['label'], $rule['note'], $rule['ruleNote'], $rule['message'],
              $rule['uid'], $rule['rule-uid'], $rule['ruleUid']);
        // Where the rule came from is already in the source_id's prefix
        // (`set:` / `ann:` / `uid:`). Hashing it here as well would churn every
        // existing revision for no gain, and would break this function's stated
        // contract of hashing only what CHANGING the rule changes.
        unset($rule['_origin']);
        return hash('sha256', self::canonical($rule));
    }

    /**
     * Which tag family a rule belongs to.
     *
     * Derived from `type` rather than from the tag text, because the tag text is
     * not on the rule by the time it reaches here - groupMulti drops `_tag`.
     * Unknown types answer 'other' rather than throwing: a rule type this build
     * does not recognise still needs a name, and refusing to name it would make
     * a future type unscannable rather than merely unlabelled.
     */
    private static function family(array $rule)
    {
        $t = isset($rule['type']) ? (string) $rule['type'] : '';
        if ($t === '') return 'other';
        if (!preg_match('/^[a-z][a-z0-9_-]*\z/i', $t)) return 'other';
        return strtolower($t);
    }

    /**
     * Name a whole rule list at once, handling identical siblings.
     *
     * Occurrence is assigned within each (origin, name-without-occurrence)
     * group, in list order, so two rules that are identical in every respect
     * still get distinct names - and the SAME distinct names on the next run,
     * because the list order of identical siblings is itself derived from
     * content.
     *
     * KEYS ARE PART OF THE ANSWER. This used to walk array_values($rules) and
     * append with $out[], which re-indexed the result densely - while its one
     * caller passes $plan['live'], a SPARSE array whose keys are rule ordinals
     * (scanPlan skips config-broken rules and the per-instrument-rights gate
     * unsets more), and then looks the identity up by ordinal - 1. One dropped
     * rule therefore shifted every later rule's name by one and pushed the last
     * one off the end into the `unnamed:` fallback. Because rule_source_id is
     * hashed into Hmac::findingIdentity(), that did not merely mislabel a
     * finding: it gave the finding the identity of a rule that did not produce
     * it, which is the key the supersede logic matches on.
     *
     * ORIGIN COMES FROM THE RULE, NOT FROM A COUNT. $settingsCount was a
     * positional boundary no caller ever supplied, and it could not have been
     * made correct: Branching::resolve() drops rules that lost every field to a
     * branch rule and appends synthesized ones, so no integer survives it. It
     * remains only as a fallback for a caller holding an untagged list.
     *
     * @param array $rules         as getRules() produced them; keys preserved
     * @param ?int  $settingsCount DEPRECATED positional fallback, used only for
     *                             rules carrying no _origin key
     * @return array parallel to $rules, INCLUDING ITS KEYS
     */
    public static function identifyAll(array $rules, $settingsCount = null)
    {
        $seen = [];
        $out = [];
        $pos = 0;
        foreach ($rules as $k => $r) {
            $origin = (isset($r['_origin']) && is_string($r['_origin']) && $r['_origin'] !== '')
                ? (string) $r['_origin']
                : (($pos < (int) $settingsCount) ? 'settings' : 'annotation');
            $pos++;
            // Probe with occurrence zero to learn the un-numbered part of the
            // name, then count how many of those we have already issued.
            $probe = self::identify($r, $origin, 0);
            $stem = $probe['stem'];
            $n = isset($seen[$stem]) ? $seen[$stem] : 0;
            $seen[$stem] = $n + 1;
            $out[$k] = self::identify($r, $origin, $n);
        }
        return $out;
    }

    /**
     * What the fingerprint says about collection gaps: this, always.
     *
     * The fingerprint used to read policy.collectionGaps, which was the literal
     * 'separate' on every installation - so it contributed a constant, and every
     * stored fingerprint was computed over that constant. The policy now says
     * 'unimplemented', because that is true; had the fingerprint followed it,
     * every run in flight at the moment of the upgrade would have been judged
     * "the configuration moved" and failed, over a word that decides nothing
     * about which findings a scan produces. So the wire value stays what it
     * always was and stops being read from anywhere. If gap handling ever does
     * change what a scan finds, that is a NEW fingerprint input with its own
     * key, not a new value for this one.
     */
    const GAP_POLICY_WIRE = 'separate';

    /**
     * Recompute precisely the semantic inputs used when the run was planned.
     *
     * EACH RULE ENTRY CARRIES WHERE THE RULE APPLIES (review rv10b1-1): its
     * fields, sorted, and the instruments it is judged on, sorted. revision()
     * leaves `fields` out so a settings rule keeps its name when a field is
     * added to it, and the entry was the rule's id, revision and origin and
     * nothing else - so a field added to a settings rule between two requests
     * of one run moved no input when the field was already read by another
     * rule, the records examined before it were never judged on it, and the
     * run finished complete and clean. The identity is untouched: only the
     * fingerprint learns the field list. An annotation rule's id is built from
     * its fields already, so for it the list repeats what the id says.
     *
     * The instruments are `hosts[key]`, keyed as `rules` are: the plan's
     * hostFields, the forms the evaluator walks for the rule. They must stay
     * the same for every requester, as every input here must; the durable
     * plan never narrows them by instrument rights. A bag without `hosts`
     * gives every rule none, on both sides alike.
     */
    public static function requestFingerprint(array $req)
    {
        $rules = isset($req['rules']) && is_array($req['rules']) ? $req['rules'] : [];
        $ids = self::identifyAll($rules, $req['settingsCount'] ?? null);
        $hosts = isset($req['hosts']) && is_array($req['hosts']) ? $req['hosts'] : [];
        $ruleSpec = [];
        foreach ($ids as $k => $id) {
            $fields = (isset($rules[$k]['fields']) && is_array($rules[$k]['fields']))
                    ? array_map('strval', $rules[$k]['fields']) : [];
            sort($fields, SORT_STRING);
            $forms = (isset($hosts[$k]) && is_array($hosts[$k])) ? array_map('strval', array_keys($hosts[$k])) : [];
            sort($forms, SORT_STRING);
            $ruleSpec[] = ['id'=>$id['source_id'], 'rev'=>$id['revision'], 'origin'=>$id['origin'],
                           'fields'=>$fields, 'forms'=>$forms];
        }
        usort($ruleSpec, function ($a,$b) { $c=strcmp($a['id'],$b['id']); return $c!==0?$c:strcmp($a['rev'],$b['rev']); });
        return self::fingerprint([
            'engine'    => isset($req['engine']) ? $req['engine'] : '',
            'rules'     => $ruleSpec,
            'ownership' => isset($req['ownership']) ? $req['ownership'] : [],
            'structure' => isset($req['structure']) ? $req['structure'] : [],
            'choices'   => isset($req['choices']) ? $req['choices'] : [],
            'gapPolicy' => self::GAP_POLICY_WIRE,
            'valueMode' => isset($req['policy']['valueMode'])
                           ? $req['policy']['valueMode'] : 'locations',
        ]);
    }

    /**
     * The policy the run row keeps: the request's policy, plus every lookup
     * into another project the run's rules make (reviews R3U-N1, R4-1, R4-2).
     *
     * NOT IN THE FINGERPRINT. A work request compares the fingerprint against
     * one it builds from the settings policy alone, so a key added to the
     * fingerprinted policy here would make every run fail its own first pass.
     * The lookups are covered there already: a rule revision hashes the
     * project and the fields a lookup searches.
     *
     * $req['crossLookups'] is the start plan's list (UniversalValidator::
     * crossLookupOf() entries). A request without the key (or with null) stores none, and
     * ScanService::status() refuses a run whose policy does not say, so a
     * caller that forgets it fails closed; start() always passes it. Entries
     * are kept as given, sorted and without repeats: status() refuses any it
     * cannot read.
     */
    public static function storedPolicy(array $req)
    {
        $policy = (isset($req['policy']) && is_array($req['policy'])) ? $req['policy'] : [];
        if (array_key_exists('crossLookups', $req) && $req['crossLookups'] !== null) {
            $l = is_array($req['crossLookups']) ? $req['crossLookups'] : [false];
            $keyed = [];
            foreach ($l as $e) $keyed[json_encode($e)] = $e;
            ksort($keyed, SORT_STRING);
            $policy['crossLookups'] = array_values($keyed);
        }
        // THE EVALUATOR'S REVISION THE RUN BEGAN UNDER (review rv10b1-6), so a
        // run ended because the module was upgraded can say so rather than
        // blame the project. The fingerprint folds it into one hash, from
        // which nothing can be read back. ScanPromotion::engineOf() reads it.
        if (isset($req['engine']) && is_string($req['engine']) && $req['engine'] !== '') {
            $policy['engine'] = $req['engine'];
        }
        return $policy;
    }

    // -- fingerprint ---------------------------------------------------------

    /**
     * One hash over everything that decides WHICH findings a scan produces.
     *
     * A run stores it; a resumed or incremental run compares it. A mismatch
     * means the configuration moved underneath the run, and the only safe answer
     * is a full pass — never a quiet continuation, which would produce a report
     * half of which was checked against rules that no longer exist.
     *
     * MISSING INPUTS THROW. A fingerprint that silently omitted, say, the
     * repeating-instrument structure would be a fingerprint that fails to notice
     * the change it exists to notice, and it would fail QUIETLY — the worst
     * available direction. Extra inputs are folded in rather than rejected,
     * because the safe direction for an unrecognised input is that it counts.
     */
    public static function fingerprint(array $spec)
    {
        foreach (self::$required as $k) {
            if (!array_key_exists($k, $spec)) {
                throw new \InvalidArgumentException('fingerprint(): missing input "' . $k
                    . '"; a fingerprint that omits an input cannot notice it changing');
            }
        }
        foreach (self::$forbidden as $k => $why) {
            if (array_key_exists($k, $spec)) {
                throw new \InvalidArgumentException('fingerprint(): "' . $k
                    . '" must not be an input - ' . $why);
            }
        }
        return hash('sha256', 'uv-scan-fp/1' . self::canonical($spec));
    }

    /**
     * Did the configuration move? Reported as a comparison rather than a bare
     * boolean so a caller can say WHICH way, and a report can say so too.
     */
    public static function fingerprintMatches($stored, $current)
    {
        if (!is_string($stored) || !is_string($current)) return false;
        if (strlen($stored) !== 64 || strlen($current) !== 64) return false;
        // Length-constant, because the fingerprint is derived from project
        // configuration a survey respondent can never see and an unprivileged
        // user should not be able to probe by timing a resume.
        return hash_equals($stored, $current);
    }
}
