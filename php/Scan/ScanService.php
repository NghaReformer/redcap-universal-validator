<?php

namespace INSPIRE\UniversalValidator\Scan;

// Both live in the parent namespace: ScanPageView is shared with the pages, and
// ScanCapabilities answers what an installation may claim. Imported rather than
// duplicated - a second copy of a security decision is a second copy that ages
// differently from the first.
use INSPIRE\UniversalValidator\ScanPageView;
use INSPIRE\UniversalValidator\ScanCapabilities;

/**
 * Where the durable scan is assembled, and the only thing the outside world
 * calls.
 *
 * WHY A COMPOSITION ROOT. Fourteen classes under php/Scan/ each know one thing
 * and none of them know how to find the others. Something has to build the
 * store over the framework's database handle, hand the planner a manifest
 * source, give the worker a fence and an evaluator, and pass every one of them
 * the same policy — and if that wiring lives in the AJAX handler, then the AJAX
 * handler is the design. It lives here instead, so the entrypoints are four
 * short methods that authorize, delegate, and answer.
 *
 * FOUR VERBS AND NOTHING ELSE: start, work, status, cancel. Every one of them
 * re-derives the project, the user and the scope server-side; none of them
 * believes anything the client said about who it is or what it may see. The
 * client sends a run id, and a run id is a locator, never an authorization —
 * bound to the project before any answer distinguishes "no such run" from "not
 * yours".
 *
 * DISABLED BY DEFAULT, AND THAT IS A FEATURE OF THIS RELEASE. Two flags must
 * both be on: the system administrator enables the durable scan for the
 * installation, and the project turns it on for itself. Either off means the
 * page shows the same unavailable notice it has shown since Task 1. The plan
 * requires a real-server pilot before this is enabled anywhere, and a flag that
 * defaults on is not a flag.
 *
 * WHAT IT REFUSES TO BUILD. A missing schema, an installation that cannot list
 * records without exporting the project, a reader without full export rights, a
 * DAG-scoped start on a server that cannot prove group membership — each is
 * answered before a run exists, because a run that cannot finish honestly is
 * worse than no run: it holds the project's scan slot and it looks like
 * progress.
 *
 * PHP 7.4.
 */
final class ScanService
{
    /** Both must be true. Neither defaults on. */
    const SYS_FLAG  = 'scan-system-enable-durable';
    const PROJ_FLAG = 'scan-enable-durable';

    /** How long a worker's installation slot lease lasts without renewal. */
    const SLOT_TTL = 300;

    /**
     * How long a run may go without progress before the page says so.
     *
     * A THRESHOLD FOR A SENTENCE ON A PAGE, and nothing else: it stops no run
     * and retires none - that is staleHours and the reaper. It is deliberately
     * not in the policy and not in the fingerprint, because it decides nothing
     * about what a scan finds, and a value that did sit there would fail every
     * run in flight whenever somebody tuned a message.
     */
    const STALL_SECONDS = 120;

    /**
     * Phases whose work does not move `progress_at`. That column is written by
     * commitBatch() and freezeManifest() only - records finishing, the manifest
     * freezing - so through these three it stands still while the run is busy,
     * and `updated_at` (which their cursor writes do move) is the better clock.
     * In scanning it is the WORSE one: claim() refreshes it on a run whose every
     * batch is being refused, which is the wedge `progress_at` exists to show.
     */
    const PHASES_ON_THE_UPDATE_CLOCK = [ScanPhase::CATCH_UP, ScanPhase::UNIQUE, ScanPhase::ROLLUP];

    /** @var object the module */
    private $module;
    /** @var ?ScanDb */
    private $db;
    /** @var array project id => RecordManifestSource|null, opened at most once per request */
    private $sources = [];

    // -- answers kept for the life of this object, which is one request ------
    //
    // ONE INSTANCE, ONE REQUEST, and that is the whole safety argument for
    // keeping anything (P1, M12). The endpoint and the page each build a
    // ScanService per request, so nothing below can describe a configuration,
    // a schema or a user's rights from an earlier one. Every key carries the
    // project, because a cron pass may work several projects through one
    // instance. Nothing here is static: a static would outlive the request in
    // exactly the long-lived worker where that matters.

    /** @var array project id => available()'s answer */
    private $availability = [];
    /** @var array project id => ScanCapabilities::recordEnumeration()'s answer */
    private $enumeration = [];
    /** @var array project id => ScanCapabilities::all()'s answer, or null when it threw */
    private $capabilities = [];
    /** @var array project id => the change log as a fence, or null when there is none */
    private $fences = [];
    /** @var array entitlement key (see entitlement()) => its answer */
    private $entitlements = [];
    /** @var \ArrayObject the plans durableScanContext() built for this request */
    private $plans;
    /** @var ?ScanStore the store a test handed in; null is SqlScanStore over $db */
    private $givenStore;

    /**
     * @param object     $module the module
     * @param ?ScanStore $store  THE STORE, AND ONLY A TEST PASSES ONE. Production
     *                           builds SqlScanStore over the module's handle and
     *                           passes nothing. The seam exists because the path
     *                           from a work request to promotion could not be run
     *                           without a database, and so nothing ran it: the
     *                           merge of main left advanceRun() calling a method
     *                           the scan chain had deleted, and every work request
     *                           that reached promotion died with an Error until a
     *                           later wave happened to notice. tests/
     *                           scan_service_php.php now drives start, work and
     *                           status to a finished run over ArrayScanStore.
     */
    public function __construct($module, ?ScanStore $store = null)
    {
        $this->module = $module;
        $this->db = is_callable([$module, 'query']) ? new ModuleDb($module) : null;
        $this->plans = new \ArrayObject();
        $this->givenStore = $store;
    }

    /**
     * May the durable scan run here at all?
     *
     * Answered in this order deliberately: the flags first, because an
     * administrator who has not turned it on should not be told about schema
     * health; then the schema, because everything after it depends on tables
     * existing; then the capability gate, which is the one that decides whether
     * a scan can be bounded at all.
     *
     * ASKED ONCE PER REQUEST. The flags, the schema and the capability gate do
     * not change between two questions in one request, and the health check is
     * two statements that every work request pays (M11).
     *
     * @return array{ok:bool, why:?string, detail:?string}
     */
    public function available($pid)
    {
        $k = (int) $pid;
        if (!array_key_exists($k, $this->availability)) {
            $this->availability[$k] = $this->availableNow($pid);
        }
        return $this->availability[$k];
    }

    /** @see available() — the answer, worked out. */
    private function availableNow($pid)
    {
        if ($this->db === null) {
            return self::no('this REDCap build does not give modules their own database access, '
                . 'so the durable scan cannot run here');
        }
        if (!$this->flag(self::SYS_FLAG, null) || !$this->flag(self::PROJ_FLAG, $pid)) {
            return self::no('the durable validation scan is not enabled here');
        }
        $health = Schema::health($this->module);
        if (empty($health['ok'])) {
            // Reported, never repaired on the fly. A migration that runs
            // because someone opened a page is a migration nobody chose.
            return self::no('the scan\'s own tables are not ready on this installation',
                isset($health['why']) ? $health['why'] : null);
        }
        $caps = $this->recordEnumeration($pid);
        if ($caps['state'] !== ScanCapabilities::OK) {
            return self::no('this installation cannot list the project\'s records without '
                . 'exporting the whole project, so a bounded scan is not possible here',
                isset($caps['why']) ? $caps['why'] : null);
        }
        return ['ok' => true, 'why' => null, 'detail' => null];
    }

    /**
     * Start a run, or say why not.
     *
     * The order is the security argument. Scope and rights are re-derived from
     * the framework user - never from anything the client sent - and the
     * entitlement set comes from the PLAN, so a rule that reaches an instrument
     * the reader cannot open denies the whole report rather than quietly
     * narrowing it.
     *
     * @return array{ok:bool, busy:bool, run_id:?int, why:?string}
     */
    public function start($pid)
    {
        // THE STORAGE-FAILURE BOUNDARY IS HERE, one frame above the body, and
        // that is why the body is a method of its own. startRun() used to
        // report a missing table as "a validation scan is already running for
        // this project", so an administrator was told to wait for something
        // that could never finish; it now throws, and every store call between
        // here and it can throw the same way. One catch at the entrypoint
        // answers all of them with a sentence that says what actually happened,
        // and puts what the server said in the module log where it belongs.
        try {
            return $this->openRun($pid);
        } catch (ScanStoreUnavailable $e) {
            return self::noStart($this->storageFailed($pid, null, $e));
        }
    }

    /** @see start() — the body, so that the catch above is not a page of indent. */
    private function openRun($pid)
    {
        $gate = $this->available($pid);
        if (!$gate['ok']) return self::noStart($gate['why']);

        // FINISH ANY CANCELLED RUN BEFORE ASKING FOR THE SLOT.
        //
        // A run stopped by its user sits in `cancelling` until somebody writes
        // its terminal state, and while it sits there it holds the project's one
        // active slot. Without this, pressing Stop and then Start told the user
        // the project was busy - with their own cancelled run, indefinitely.
        // Reaped here rather than only in the worker, because the worker needs a
        // run id and the person starting a new scan does not have one.
        $this->reapCancelled($pid);

        $scope = ScanPageView::scanScope($this->module, $pid);
        if (empty($scope['ok'])) return self::noStart($scope['why']);
        $dag = $scope['dag'];

        $policy = $this->policy($pid);
        // PLANNING CONTEXT. start() needs the rule list and the ownership map
        // to authorise and to plan; the run - and therefore its generation -
        // does not exist yet. Asking for a null generation says that, and gets
        // back a context with no evaluator rather than one silently bound to
        // generation 1, which is what every run of every project used to write.
        $ctx = $this->module->durableScanContext($pid,
            $this->planOptions($pid, ['valueCeiling' => $policy['valueMode'], 'generation' => null,
                                      'policy' => $policy]), $dag);
        if (empty($ctx['ok'])) return self::noStart($ctx['why']);

        // Every instrument the run will read, from the plan rather than from a
        // list somebody maintains. A field the plan could not place on an
        // instrument is an unknown ownership, and unknown ownership is denial.
        // THE NAMES, NOT MERELY THE FACT. mayStart() prints them, and an
        // unplaceable field is a fixable dictionary problem: the field exists,
        // its form_name does not. A refusal that says only "at least one field"
        // sends an administrator looking through the whole dictionary.
        $forms = [];
        $unknown = [];
        foreach ($ctx['ownership'] as $field => $form) {
            if ($form === null || $form === '') { $unknown[] = (string) $field; continue; }
            $forms[$form] = true;
        }
        $auth = ScanAuthorization::mayStart($scope['rights'], array_keys($forms), $unknown);
        if (empty($auth['ok'])) return self::noStart($auth['why']);

        $src = RecordManifestSource::open($this->db, $pid,
            ['pk' => $this->recordIdField($pid)]);
        if (empty($src['ok'])) return self::noStart($src['why']);

        $fence = $this->fence($pid);
        if ($dag !== null) {
            // A group-scoped run is only honest where group changes can be
            // enumerated: without that, a record that moved between groups
            // during the run silently belongs to the wrong report.
            $prove = ScanAuthorization::mayStartDagScoped($fence !== null);
            if (empty($prove['ok'])) return self::noStart($prove['why']);
        }

        $planner = new ScanPlanner($this->store(), $this->key());
        $r = $planner->plan($pid, [
            'source'    => $src['source'],
            'fence'     => $fence,
            'dagFilter' => $dag,
            'rules'     => $ctx['rules'],
            'ownership' => $ctx['ownership'],
            'structure' => $ctx['structure'] ?? [],
            'policy'    => $policy,
            // Which other projects this run's results may hold answers from,
            // kept on the run for status() (review R3U-N1).
            'crossProjects' => (isset($ctx['plan']['crossAnswered']) && is_array($ctx['plan']['crossAnswered']))
                               ? $ctx['plan']['crossAnswered'] : [],
            'createdBy' => (string) $this->username(),
            'engine'    => $this->engineVersion(),
            // WHAT THE PLAN ALREADY KNOWS IT CANNOT EVALUATE. Config-broken,
            // unlocatable, unmapped-instrument and group-unscopable rules are
            // decided before a record is read; the planner records them so
            // ScanOutcome's `ruleProblems` term has a producer at all. Without
            // this line the term is dead and `clean` means only "no findings".
            'ruleProblems' => isset($ctx['problems']) ? $ctx['problems'] : [],
        ]);
        if (empty($r['ok'])) {
            return ['ok' => false, 'busy' => !empty($r['busy']), 'run_id' => null,
                    'why' => $r['why']];
        }
        $runId = (int) $r['run']['run_id'];
        $this->store()->audit($pid, $runId, 'start', (string) $this->username(), null);
        return ['ok' => true, 'busy' => false, 'run_id' => $runId, 'why' => null];
    }

    /**
     * Do one request's worth of work on a run, then answer.
     *
     * The SAME method serves the browser and cron; only the budget differs,
     * which is the whole reason there is one worker class. Two entrypoints that
     * were meant to agree would diverge on the day one of them was fixed.
     */
    public function work($pid, $runId, $mode = 'browser')
    {
        // As start(): one boundary for every way the storage can fail. The
        // worker catches the failures raised inside its own loop and answers
        // stop:'storage'; this catches the ones raised before it gets there -
        // the entitlement reads, the slot acquisition, the status read.
        try {
            return $this->advanceRun($pid, $runId, $mode);
        } catch (ScanStoreUnavailable $e) {
            return ['ok' => false, 'why' => $this->storageFailed($pid, $runId, $e)];
        }
    }

    /** @see work() — the body. */
    private function advanceRun($pid, $runId, $mode)
    {
        $gate = $this->available($pid);
        if (!$gate['ok']) return ['ok' => false, 'why' => $gate['why']];

        $store = $this->store();
        $run = $store->run($pid, $runId);
        if ($run === null) return ['ok' => false, 'why' => self::NO_RUN];

        $ent = $this->entitlement($pid, $run);
        if (empty($ent['ok'])) return ['ok' => false, 'why' => $ent['why']];
        $auth = ScanAuthorization::mayWork($ent['rights'], $ent['forms'], $run['scope_dag'],
                                           $ent['unknown']);
        if (empty($auth['ok'])) return ['ok' => false, 'why' => $auth['why']];

        $policy = $ent['policy'];
        $ctx = $ent['ctx'];

        // A STOPPED RUN IS ENDED HERE, BY THE ONE FINISHER (review R3U-3). The
        // worker ended a `cancelling` run itself, with an outcome that carried
        // no counts, so the row of a run stopped while a worker held it said
        // nothing about the rule problems, the records not examined or the
        // budget it had met. ScanPromotion::finishCancelled() is what Stop and
        // Start use, and it is used here before the worker is built. Before
        // the fingerprint, too: ending a stop evaluates nothing, and a run that
        // was stopped is not to be told to stop because its rules changed.
        if (isset($run['phase']) && $run['phase'] === ScanPhase::CANCELLING) {
            ScanPromotion::finishCancelled($store, $pid, $runId, self::limitsOf($policy));
            return ['ok' => true, 'worked' => 0, 'requeued' => 0, 'blocked' => 0, 'findings' => 0,
                    'phase' => ScanPhase::TERMINAL, 'done' => false, 'stop' => 'cancelled',
                    'why' => 'this scan was stopped, so the records after that point were never examined',
                    'status' => $this->statusOf($pid, $runId, [])];
        }

        if (!empty($ctx['verifyFingerprint'])) {
            $current = ScanPlanner::requestFingerprint(['rules'=>$ctx['rules'], 'ownership'=>$ctx['ownership'],
                'structure'=>$ctx['structure'] ?? [], 'policy'=>$policy, 'engine'=>$this->engineVersion()]);
            if (!ScanPlanner::fingerprintMatches($run['fingerprint'], $current)) {
                return ['ok'=>false, 'why'=>'Validation configuration or project structure changed. Stop this scan and start a new scan.'];
            }
        }
        $worker = new ScanWorker($store, $this->workerDeps($pid, $store, $run, $policy, $ctx, $mode));
        $r = $worker->work($pid, $runId);

        // A RUN WHOSE STORAGE JUST FAILED IS NOT A RUN TO PROMOTE. Promotion
        // reads statuses and counters and then writes a terminal verdict; doing
        // that on the strength of reads that are failing is how a database
        // blip becomes a permanent answer about a project. The run keeps its
        // slot and its phase, and the next request promotes it if it can.
        //
        // The status is not re-read either, for the same reason: asking a
        // database that has just refused one question to answer another one
        // either fails again or answers from a state nobody should trust. The
        // caller gets a refusal shaped like every other refusal.
        if (isset($r['stop']) && $r['stop'] === 'storage') {
            return array_merge($r, ['status' => ['ok' => false,
                                                 'why' => ScanStoreUnavailable::OPERATOR_TEXT]]);
        }

        // Promotion is attempted on every pass, not only the one that finishes.
        // It refuses until the run really is finishable, so asking early costs
        // one predicate - while not asking at all is how a run ends up complete
        // in every respect except the row that says so.
        $u = $this->finalizer($pid, $ctx)->status((int) $run['generation_id']);
        $p = ScanPromotion::promote($store, $pid, $runId, [
            // WHAT WAS FOUND IS NOT PASSED. Violations, rule problems, gaps,
            // what blocks coverage and the window's changes are read from the
            // store by promote() itself - ScanPromotion::tally() - so no caller
            // can feed the verdict a number from the wrong place, and a pass
            // that cannot promote reads none of them. The last two were read
            // here on every pass; the window's was a call to a method this
            // class no longer had, so every work request that reached this
            // line died with an Error (W8-N1).
            // The gap count is still permanently zero: nothing in the shipped
            // tree writes a 'collection-gap' aggregate, because no gap detector
            // exists. Do not infer from a green suite that gaps are being counted.
            'uniqueDone'     => $u['done'],
            'uniqueBlocking' => $u['blocking'],
            'rollupDone'     => ($r['phase'] === ScanPhase::ROLLUP && !empty($r['done'])),
            'maxFindings'    => $policy['maxFindings'],
            'maxBytes'       => $policy['maxBytes'],
            // A @UVEXISTS verdict depends on other records, which the catch-up
            // does not re-check when one of them changes (ScanPromotion::facts).
            'crossRecordLookups' => !empty($ctx['plan']['crossRecordLookups']),
        ]);

        // THE CENSUS PROMOTION READ IS THE ONE THE STATUS NEEDS (P11), and it
        // was asked for twice: once by promote(), once by status() straight
        // after it - an O(manifest) count, twice, on every work request.
        // promote() read it after the worker's last commit, so it describes
        // the run as the worker left it. It stands in for status()'s own read
        // ONLY when promote() wrote nothing after taking it: a promotion that
        // sent finish() ended the run, and a census taken before that write
        // must not describe the run after it - so then status() reads again.
        $known = empty($p['wrote']) ? ['run' => $p['run'], 'census' => $p['census']] : [];
        return array_merge($r, ['status' => $this->statusOf($pid, $runId, $known)]);
    }

    /**
     * Everything the worker is handed for one request.
     *
     * A METHOD OF ITS OWN SO IT CAN BE ASKED (review CL-5/EE-3). This was an
     * array literal inside advanceRun(), which is reachable only through a
     * module, a user, an entitlement and a database - so deleting its `exists`
     * line left every suite green, and a record the export left out and the
     * project no longer held became UNREADABLE rather than a TOMBSTONE,
     * capping the run's coverage at partial for a record that was simply
     * deleted. tests/scan_service_php.php now builds a worker from this and
     * runs it over such a record.
     */
    private function workerDeps($pid, ScanStore $store, array $run, array $policy, array $ctx, $mode)
    {
        return [
            'slots'     => new WorkerSlots($this->db),
            'slotTtl'   => self::SLOT_TTL,
            'fence'     => $this->fence($pid),
            'read'      => $ctx['read'],
            'evaluate'  => $ctx['evaluate'],
            'budget'    => new WorkBudget(['mode' => $mode, 'startedAt' => microtime(true)]),
            'owner'     => $this->owner(),
            'attempts'  => $policy['recordAttempts'],
            // The budget a stopped run is judged against, for the worker's
            // own ending of a run it finds `cancelling` (review R3U-3).
            'limits'    => self::limitsOf($policy),
            'finalizer' => $this->finalizer($pid, $ctx),
            'catchup'   => $this->catchUp($pid, $store, $run['scope_dag']),
            // The SAME question catch-up asks, of the same opened source: is
            // this record still in the project. The worker asks it before it
            // will call a record it could not read a deleted one.
            'exists'    => $this->existsProbe($pid),
            'rollup'    => new RollupBuilder($this->db, $store),
            'note'      => function ($event, array $context) {
                $this->note($event, $context);
            },
        ];
    }

    /**
     * What a caller entitled to see this run may know about it.
     *
     * Independent dimensions, not a formatted sentence: collapsing them is what
     * produced a report that was true about the run and false about the project.
     * The caller decides how to say it; this decides what is true.
     *
     * A REFUSAL SAYS WHETHER ASKING AGAIN CAN HELP (review R2O-4). `final` is
     * true when the next ask will get the same answer: there is no such run
     * here, or this caller may not read it. Everything else - a read that
     * threw, a rule list that could not be loaded, and the endpoint's own
     * catch-all for a database blip, which never reaches this method to be
     * marked - carries no `final`, and a watching page keeps asking on its
     * ladder. It used to treat every refusal as the last word, so one blip
     * left a tab that never learned the run had finished.
     *
     * WHAT THE RUN HAS STORED (P10). `detailRows` and `detailBytes` are the
     * counters commitBatch() keeps on the run row - finding rows written and the
     * bytes of the values kept with them - so an operator can see what a run is
     * adding to the installation's tables while it runs. Nothing rendered them.
     * They are not the findings count: the finalizer's duplicates are not in
     * them and superseded rows still are.
     */
    public function status($pid, $runId)
    {
        return $this->statusOf($pid, $runId, []);
    }

    /**
     * status(), with what this request has already read about the run.
     *
     * $known: `run`, the row as ScanStore::run() returns it, and `census`, its
     * recordStates(), each used in place of reading it again. advanceRun()
     * passes what promotion read, and only when nothing was written since; see
     * there. Anything absent or not an array is read here.
     */
    private function statusOf($pid, $runId, array $known)
    {
        $store = $this->store();
        $run = (isset($known['run']) && is_array($known['run'])) ? $known['run'] : $store->run($pid, $runId);
        if ($run === null) return ['ok' => false, 'why' => self::NO_RUN, 'final' => true];

        $ent = $this->entitlement($pid, $run);
        if (empty($ent['ok'])) {
            return ['ok' => false, 'why' => $ent['why'], 'final' => !empty($ent['final'])];
        }
        $auth = ScanAuthorization::mayRead($ent['rights'], $ent['forms'], $run['scope_dag'],
                                           $ent['unknown']);
        if (empty($auth['ok'])) return ['ok' => false, 'why' => $auth['why'], 'final' => true];
        // A run can hold answers another project gave the person who ran it.
        // A reader that project would not answer is refused the whole run, by
        // the same rule as an instrument they cannot read (ScanAuthorization:
        // whole-report denial, never filtered rows). The plan is re-derived
        // for THIS reader, so the list is theirs. Cancel does not ask: a run
        // must stay stoppable.
        $plan = isset($ent['ctx']['plan']) && is_array($ent['ctx']['plan']) ? $ent['ctx']['plan'] : [];
        $refused = (isset($plan['crossRefused']) && is_array($plan['crossRefused']))
                 ? array_values(array_map('intval', $plan['crossRefused'])) : [];
        // WHAT THE RUN LOOKED UP, NOT ONLY WHAT THE RULES LOOK UP NOW (review
        // R3U-N1). crossRefused comes from the reader's plan, and a rule whose
        // agreement was revoked, whose alias was removed or that was deleted
        // is not in it - so revoking an agreement used to WIDEN who saw the
        // answers that project had already given. The run keeps the projects
        // it consulted; one the reader's plan does not vouch for is refused.
        $consulted = self::consultedProjects($run);
        if ($consulted === null) {
            return ['ok' => false, 'final' => false,
                    'why' => 'which other projects this scan looked values up in could not be read, so '
                           . 'its results are not shown to you yet'];
        }
        $answered = (isset($plan['crossAnswered']) && is_array($plan['crossAnswered']))
                  ? array_map('intval', $plan['crossAnswered']) : [];
        $unvouched = array_values(array_diff($consulted, $answered, $refused));
        if ($refused || $unvouched) {
            // FINAL ONLY WHEN EVERY REFUSAL WAS A DECISION (review R3U-1). This
            // was always final, but crossRefused also lists a project whose
            // rights, module state or agreement could not be READ just now -
            // the read fails closed, as it must - so one database blip ended a
            // watching tab's watch for good while the next ask would have shown
            // the run. crossUnread names those; a plan that does not say is
            // not known to be a decision, and is asked again.
            $unread = (isset($plan['crossUnread']) && is_array($plan['crossUnread']))
                    ? array_values(array_intersect($refused, array_map('intval', $plan['crossUnread']))) : null;
            $decided = $unread === null ? [] : array_values(array_diff($refused, $unread));
            if ($decided) {
                $why = 'this scan looks values up in project ' . implode(', ', $decided)
                     . ', which does not answer your lookups, so its results are not shown to you';
            } elseif ($refused) {
                $why = 'this scan looks values up in project ' . implode(', ', $refused)
                     . ', and whether it answers your lookups could not be read just now, so its results '
                     . 'are not shown to you yet';
            } else {
                $why = 'this scan looked values up in project ' . implode(', ', $unvouched)
                     . ', and this project\'s rules no longer show that it answers your lookups, so its '
                     . 'results are not shown to you';
            }
            return ['ok' => false, 'why' => $why,
                    // A decision is as final as an instrument the reader cannot
                    // read: polling again asks the same rights the same question.
                    // A project the rules no longer vouch for is not one: the
                    // rule may be broken by a read that failed (CROSS_UNAVAILABLE
                    // says the same for every reason), so it is asked again.
                    'final' => $unread !== null && !$unvouched && count($decided) === count($refused)];
        }

        // The lease is not one of ScanStore::RUN_COLUMNS, so it is read here
        // unless the row already carries it. See progressOf() for why a quiet
        // run needs it before it may be called stalled.
        if (!array_key_exists('lease_expires_at', $run)) {
            $run['lease_expires_at'] = $this->leaseOf($pid, $runId);
        }
        $census = (isset($known['census']) && is_array($known['census']))
                ? $known['census'] : $store->recordStates($runId);
        $progress = self::progressOf($run, $census, time(),
            self::STALL_SECONDS, $this->planningSeconds());
        // cancelForms, NOT forms - see entitlement(). A run nobody may stop
        // holds the project's only slot with no reaper behind it.
        $cancel = ScanAuthorization::mayCancel($ent['rights'], $ent['cancelForms'], $run['scope_dag']);

        return [
            'ok'        => true,
            'run_id'    => (int) $run['run_id'],
            'phase'     => $run['phase'],
            'terminal'  => $run['terminal'],
            'coverage'  => $run['coverage'],
            'detail'    => $run['detail'],
            'values'    => $run['values_state'],
            'total'     => (int) $run['manifest_total'],
            // EXAMINED, and only examined. This was every terminal state summed,
            // so a run in which every record failed to store read "12 of 12
            // records (100%)" above a report with nothing in it. What was not
            // examined is said separately, and `settled` - the sum - is what
            // moves the bar, so the bar still reaches the end.
            'done'      => $progress['done'],
            'excluded'  => $progress['excluded'],
            'removed'   => $progress['removed'],
            'settled'   => $progress['settled'],
            'stalled'   => $progress['stalled'],
            'paused'    => $progress['paused'],
            'stopping'  => $progress['stopping'],
            'planningStopped' => $progress['planningStopped'],
            'idleSeconds' => $progress['idleSeconds'],
            'findings'  => ScanPromotion::findingsOf($store, $run),
            'detailRows'  => isset($run['detail_rows']) ? max(0, (int) $run['detail_rows']) : 0,
            'detailBytes' => isset($run['detail_bytes']) ? max(0, (int) $run['detail_bytes']) : 0,
            'scope'     => ($run['scope_dag'] === null ? 'project' : 'group'),
            'active'    => ScanPhase::isActive((string) $run['phase']),
            'mayCancel' => !empty($cancel['ok']),
            'why'       => null,
        ] + self::decisionOf($run);
    }

    /**
     * What promotion DECIDED about a finished run, as status() reports it.
     *
     * NONE OF THIS WAS REPORTED. promote() stores `clean`, `rule_problem_count`
     * and `not_examined` on the row, and status() returned none of them - so a
     * run refused `clean` because a rule could not be evaluated, with nothing
     * found, read "Finished - 1 of 1 records (100%) - Nothing found yet - Every
     * record was checked", byte for byte what a certified run reads. The page
     * cannot say what it is not told.
     *
     * `clean` STAYS TRI-STATE: 1 certified, 0 decided and not certified, null
     * no decision (still running, or a run that ended without a verdict). The
     * counts mean something only where `clean` is not null; see W7-N10.
     *
     * @return array{clean:?int, ruleProblems:int, notExamined:int}
     */
    public static function decisionOf(array $run)
    {
        $c = array_key_exists('clean', $run) ? $run['clean'] : null;
        $n = function ($k) use ($run) {
            return isset($run[$k]) ? max(0, (int) $run[$k]) : 0;
        };
        return [
            'clean'        => ($c === null || $c === '') ? null : ((int) $c === 1 ? 1 : 0),
            'ruleProblems' => $n('rule_problem_count'),
            'notExamined'  => $n('not_examined'),
        ];
    }

    /**
     * Where a run has got to, and whether it is still getting anywhere.
     *
     * Static and free of the store, the module and the clock, so it can be asked
     * of a row and a census directly - status() is otherwise reachable only
     * through an entitlement, which is why it had no test at all.
     *
     * STALLED IS FOR SOMEBODY WATCHING, NOT DRIVING. A tab that is driving the
     * scan already knows when a pass makes no progress and already says so; the
     * tab that cannot know is the second one, or the one opened an hour later,
     * looking at a bar that does not move and a phase that says "Checking
     * records". Only an active run can be stalled: a finished one is not going
     * anywhere on purpose.
     *
     * A QUIET RUN IS NOT A STALLED ONE (review CL-2). Only an open tab drives a
     * scan, the page attaches without resuming and tells people closing the tab
     * is safe - so a run nobody is driving is the ordinary state of a PAUSED
     * run. It was called stalled two minutes after the tab closed, with the
     * sentence "stop it and start a new one" beside the Continue button that
     * was the right answer. Three readings of a quiet run now, and only one is
     * the alarm:
     *
     *   stalled   a worker is there and nothing finishes. The only proof of a
     *             worker the row carries is the lease claim() takes, and claim()
     *             writes `lease_expires_at` and `updated_at` together. A live
     *             lease ALONE is not that proof: it outlives the request that
     *             took it by five minutes (SqlScanStore::claim), so a tab closed
     *             three minutes ago still "holds" it. So the lease must be live
     *             AND taken within the threshold. Nothing outside scanning
     *             renews a lease, so only scanning can be stalled.
     *   paused    nobody is working it. Press Continue. In the three finishing
     *             phases a quiet run cannot be told from an abandoned one, and
     *             this is the reading that sends somebody to Continue rather
     *             than to throw the run away.
     *   stopping  Stop was pressed and nothing has finished it. cancel()
     *             finishes a stop at once unless a worker held the run (review
     *             R2O-5), so this is a run whose worker has not yet come back,
     *             or one whose finish failed. Never stalled: it is waiting for
     *             exactly that request.
     *
     * A run still PLANNING is none of them (review CL-3). Planning is one
     * synchronous request inside start(), progress_at stays NULL until the
     * manifest freezes, and the clock was therefore created_at: a long listing
     * read as stalled while appendManifest() was busy writing. Nothing a reader
     * can press would help it, so nothing is said - FOR A WHILE.
     *
     *   planningStopped  still planning past the planning window (review
     *             R2O-3). CL-3's silence had no end: when the request that was
     *             planning dies, at its time or memory limit, the run stays in
     *             `planning` holding the project's only slot until the stale
     *             reaper, Continue is told the run is "not in a phase that takes
     *             work" for ever, and the page said nothing at all. No request
     *             can plan for longer than ScanPolicy::planningSeconds(), so a
     *             run older than that is not being planned by anybody, and the
     *             only thing that helps it is Stop. Never stalled or paused:
     *             both of those send the reader to a button that cannot work.
     *
     * @param array $run    a ScanStore::run() row, plus `lease_expires_at` when
     *                      the caller has it; absent or unreadable is no lease
     * @param array $states ScanStore::recordStates(): state => count
     * @param int   $now    unix time
     * @param ?int  $planningSeconds the planning window; null is the default
     *                      window, ScanPolicy::planningSeconds(null, null)
     * @return array{done:int, excluded:int, removed:int, settled:int, stalled:bool,
     *               paused:bool, stopping:bool, planningStopped:bool, idleSeconds:int}
     */
    public static function progressOf(array $run, array $states, $now, $stallSeconds = self::STALL_SECONDS,
                                      $planningSeconds = null)
    {
        $n = function ($state) use ($states) {
            return isset($states[$state]) ? max(0, (int) $states[$state]) : 0;
        };
        $done = $n(ScanStore::REC_DONE);
        $excluded = 0;
        foreach (ScanStore::NOT_EXAMINED as $state) $excluded += $n($state);
        $removed = $n(ScanStore::REC_TOMBSTONE);

        $phase = isset($run['phase']) ? (string) $run['phase'] : '';
        $at = function ($k) use ($run) { return self::clock($run, $k); };
        $clock = $at('progress_at');
        if ($clock === null) $clock = $at('created_at');
        if (in_array($phase, self::PHASES_ON_THE_UPDATE_CLOCK, true)) {
            $updated = $at('updated_at');
            if ($updated !== null && ($clock === null || $updated > $clock)) $clock = $updated;
        }
        // No readable clock is NOT a stall: an unknown is never reported as the
        // alarming reading of it.
        $idle = $clock === null ? 0 : max(0, (int) $now - $clock);
        $active = ScanPhase::isActive($phase) && empty($run['terminal']);
        $threshold = max(1, (int) $stallSeconds);

        $stopping = $active && $phase === ScanPhase::CANCELLING;
        $quiet = $active && !$stopping && $phase !== ScanPhase::PLANNING
                 && $clock !== null && $idle >= $threshold;
        // Planning is exempt from the two above for a WINDOW, not for ever. No
        // readable clock is still not the alarming reading.
        $window = $planningSeconds === null
            ? ScanPolicy::planningSeconds(null, null) : max(1, (int) $planningSeconds);
        $planningStopped = $active && $phase === ScanPhase::PLANNING
                           && $clock !== null && $idle >= $window;
        // An unreadable lease is no lease, and an unreadable touch is no touch:
        // both fail toward `paused`, the reading that does not tell anybody to
        // throw the run away.
        $driven = self::workerHolds($run, $now, $threshold);

        return ['done' => $done, 'excluded' => $excluded, 'removed' => $removed,
                'settled' => $done + $excluded + $removed,
                'stalled' => $quiet && $driven,
                'paused' => $quiet && !$driven,
                'stopping' => $stopping,
                'planningStopped' => $planningStopped,
                'idleSeconds' => $idle];
    }

    /**
     * Is a worker there? `stalled` above and cancel() below ask the same
     * question, so it is answered once.
     *
     * The proof is the lease claim() takes, LIVE AND TAKEN WITHIN THE
     * THRESHOLD, in scanning: claim() writes the lease and `updated_at`
     * together, a lease alone outlives its request by five minutes, and
     * nothing outside scanning renews one. An unreadable lease or touch is no
     * worker.
     */
    private static function workerHolds(array $run, $now, $threshold)
    {
        $lease = self::clock($run, 'lease_expires_at');
        $touched = self::clock($run, 'updated_at');
        return isset($run['phase']) && $run['phase'] === ScanPhase::SCANNING
               && $lease !== null && $lease > (int) $now
               && $touched !== null && ((int) $now - $touched) < max(1, (int) $threshold);
    }

    /** A stored time as unix seconds, or null when absent or unreadable. */
    private static function clock(array $run, $k)
    {
        if (!isset($run[$k]) || $run[$k] === null || $run[$k] === '') return null;
        // The store writes UTC (gmdate); read it back as UTC whatever the
        // server's own zone is, or every run looks hours idle or hours ahead.
        $t = strtotime((string) $run[$k] . ' UTC');
        return $t === false ? null : $t;
    }

    /**
     * When the run's worker lease runs out, or null when there is none or it
     * cannot be read.
     *
     * NULL FAILS TOWARD `paused`. A lease that cannot be read is treated as no
     * lease, so a read failure here can never make a run look stalled.
     *
     * @return ?string a UTC DATETIME as the store wrote it
     */
    private function leaseOf($pid, $runId)
    {
        try {
            $r = $this->db->select('SELECT lease_expires_at FROM ' . Schema::table('scan_run')
                . ' WHERE run_id = ? AND project_id = ?', [$runId, $pid]);
            return (isset($r[0][0]) && is_string($r[0][0]) && $r[0][0] !== '') ? $r[0][0] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Ask a run to stop. The epoch bump is what makes it beat a working worker.
     *
     * `stopped` says whether the run has ENDED, not merely been asked to.
     */
    public function cancel($pid, $runId)
    {
        $store = $this->store();
        $run = $store->run($pid, $runId);
        if ($run === null) return ['ok' => false, 'why' => self::NO_RUN];

        $ent = $this->entitlement($pid, $run);
        if (empty($ent['ok'])) return ['ok' => false, 'why' => $ent['why']];
        // cancelForms, NOT forms - see entitlement().
        $auth = ScanAuthorization::mayCancel($ent['rights'], $ent['cancelForms'], $run['scope_dag']);
        if (empty($auth['ok'])) return ['ok' => false, 'why' => $auth['why']];

        // Read as status() reads it, and BEFORE the stop: the stop writes
        // updated_at itself, which would make every run look freshly worked.
        if (!array_key_exists('lease_expires_at', $run)) {
            $run['lease_expires_at'] = $this->leaseOf($pid, $runId);
        }
        return $this->stopRun($store, $pid, $runId, (string) $this->username(), $run, time());
    }

    /**
     * Bump the epoch, then finish the stop at once when no worker holds the run
     * (review R2O-5).
     *
     * A STOP NOBODY FINISHES HELD THE PROJECT. Only a later work request, or
     * the next Start, wrote the terminal state of a `cancelling` run - so Stop
     * on a run nobody was driving answered "Press Continue to finish stopping
     * it", and the run kept the project's only slot until somebody did. Now,
     * when no worker holds it (workerHolds(), the test that tells `stalled`
     * from `paused`), it is finished here, through the path reapCancelled()
     * takes, and the slot is free when the button returns. A run a worker DOES
     * hold is left as it was: that worker's own request finishes it - promote()
     * ends a cancelled run whatever is outstanding - and the page says it is
     * stopping until then.
     *
     * FENCED BY THE ORDER, as `cancelling` always was. The epoch is bumped
     * first, so every write a worker sends against the run - claim,
     * commitBatch, catch-up's re-states, the rollup page, advancePhase - names
     * an epoch the run no longer has and is refused, and from `cancelling` the
     * only transition left is to `terminal`, which is what finish() writes.
     * finish() needs the slot still held, so a second finisher changes
     * nothing. A worker that read the run before the stop and comes back after
     * it is refused at its first fenced write, and the run it would have
     * written into is already over. (The uniqueness finalizer writes against
     * the generation, not the run, exactly as it did when a worker's own
     * request finished the stop; nothing here changes that.)
     *
     * $before is the row as read before the stop, with `lease_expires_at`.
     */
    private function stopRun(ScanStore $store, $pid, $runId, $actor, array $before, $now)
    {
        if (!$store->cancel($pid, $runId, $actor)) {
            return ['ok' => false, 'stopped' => false,
                    'why' => 'this scan had already finished, so there was nothing to stop'];
        }
        $stopped = false;
        if (!self::workerHolds($before, $now, self::STALL_SECONDS)) {
            try {
                $stopped = ScanPromotion::finishCancelled($store, $pid, $runId, $this->limits($pid));
                if (!$stopped) {
                    // ENDED BY SOMEBODY ELSE IS STILL ENDED (review R3U-5).
                    // finish() changes only a run that holds its slot, so a
                    // second Stop pressed with this one, or the worker's own
                    // request, can end the run between the bump above and the
                    // finish here - and this tab was told stopped:false, and
                    // said "Stopping the scan." beside a finished panel. Asked
                    // again: a run now cancelled has stopped. One that ended
                    // any other way is left to the status, which says how.
                    $after = $store->run($pid, $runId);
                    $stopped = $after !== null && isset($after['phase'])
                        && $after['phase'] === ScanPhase::TERMINAL
                        && isset($after['terminal']) && $after['terminal'] === ScanOutcome::CANCELLED;
                }
            } catch (\Throwable $e) {
                // The stop itself stands; the run stays `cancelling`, and the
                // next Continue or Start finishes it, as before this existed.
                $stopped = false;
            }
        }
        return ['ok' => true, 'stopped' => $stopped, 'why' => null];
    }

    /**
     * The other projects a run's stored results may hold answers from, as its
     * start recorded them (ScanPlanner::storedPolicy(), review R3U-N1).
     *
     * [] for a policy that names none. null when the policy cannot be read or
     * names them in a shape that is not a list of project ids: an unreadable
     * list is not an empty one, and status() refuses on null.
     *
     * @return int[]|null
     */
    private static function consultedProjects(array $run)
    {
        $raw = array_key_exists('policy_json', $run) ? $run['policy_json'] : null;
        if (!is_string($raw)) return null;
        $policy = json_decode($raw, true);
        if (!is_array($policy)) return null;
        if (!array_key_exists('crossProjects', $policy)) return [];
        if (!is_array($policy['crossProjects'])) return null;
        $ids = [];
        foreach ($policy['crossProjects'] as $p) {
            if (is_int($p) && $p > 0) { $ids[$p] = true; continue; }
            if (is_string($p) && ctype_digit($p) && (int) $p > 0) { $ids[(int) $p] = true; continue; }
            return null;
        }
        return array_keys($ids);
    }

    /**
     * The detail budget a run is judged against when it ends: the same
     * numbers advanceRun() hands promote(), so a stopped run is called
     * truncated by whichever request ends it, or by none (review R3U-3).
     */
    private function limits($pid)
    {
        return self::limitsOf($this->policy($pid));
    }

    /** @see limits() - from a policy already in hand. */
    private static function limitsOf(array $policy)
    {
        return ['maxFindings' => $policy['maxFindings'], 'maxBytes' => $policy['maxBytes']];
    }

    /**
     * The run this project is working on, and whether THIS caller may work it.
     *
     * M3: THREE ANSWERS, NOT TWO. This returned a bare run id or null, and null
     * meant both "there is no run" and "there is one you cannot touch" - so the
     * page rendered a Continue button, printed the run id into the client, and
     * every click came back refused with nothing on the page explaining why.
     * When the scope axis was wrong that was the designer's OWN run; now that it
     * is right it is another group's, and handing out its id makes a page load
     * an enumeration of other groups' runs. A run id is a locator and never an
     * authorisation, so emitting one is not a breach - but there is no reason
     * to, and the button it enables is a lie either way.
     *
     * THE SCOPE GATE ONLY, AND ON PURPOSE. mayTouchScope() skips the export and
     * instrument checks, because asking for those means rebuilding the whole
     * plan on every page load. It is necessary, not sufficient: work(), status()
     * and cancel() each re-ask mayWork() and can still refuse what this offered.
     *
     * $scope is ScanPageView::scanScope()'s answer, passed in rather than
     * re-read: the page has already computed it, and two readings of the same
     * user's rights in one request can legitimately differ.
     *
     * @return array{run_id:?int, state:string, why:?string}
     *         state is one of 'none', 'yours', 'other', 'unknown'. run_id is
     *         non-null ONLY for 'yours'.
     */
    public function activeRun($pid, $scope = null)
    {
        try {
            $r = $this->db->select('SELECT run_id, scope_dag FROM ' . Schema::table('scan_run')
                . ' WHERE project_id = ? AND active_slot = 1', [$pid]);
            if (!isset($r[0][0])) return ['run_id' => null, 'state' => 'none', 'why' => null];
            $rights = (is_array($scope) && isset($scope['rights'])) ? $scope['rights'] : null;
            if ($rights === null) {
                // No rights in hand is not "no restriction". Fail closed: the
                // page offers nothing and the verbs still answer honestly.
                return ['run_id' => null, 'state' => 'unknown', 'why' => ScanStore::BUSY_WHY];
            }
            $may = ScanAuthorization::mayTouchScope($rights, $r[0][1]);
            if (!empty($may['ok'])) {
                return ['run_id' => (int) $r[0][0], 'state' => 'yours', 'why' => null];
            }

            // THE MIGRATION, SITED WHERE THE WEDGE ACTUALLY SHOWS.
            //
            // A run started before the axis fix carries a DAG NAME in
            // scope_dag, which no group id can equal - so nobody, including the
            // designer who started it, can work, read or cancel it, and it
            // holds the project's one active slot forever. The 1->2 data
            // migration (Schema::upgradeDataV2) retires exactly this shape, but
            // an installation already AT version 2 never re-enters that path,
            // and version 2 is every installation carrying this build. There is
            // deliberately no version 3 for it: a version bump is a second
            // ALTER pass over an ~800 MB uv_finding and disables the scan on
            // every piloting installation until an administrator re-saves the
            // configuration - to retire at most a handful of rows.
            //
            // HERE, AND NOT IN available() OR openRun(). available() states in
            // its own body that it reports rather than repairs, because a
            // migration that runs because someone opened a page is a migration
            // nobody chose; openRun() is reached only by pressing Start, which
            // is the button a wedged run hides. This branch is the one moment
            // the defect is observable AND the observer is the person it
            // blocks, and it costs nothing on the path where the scope matches.
            if ($this->reapUnworkableScopes($pid) > 0) {
                $again = $this->db->select('SELECT run_id FROM ' . Schema::table('scan_run')
                    . ' WHERE project_id = ? AND active_slot = 1', [$pid]);
                if (!isset($again[0][0])) {
                    return ['run_id' => null, 'state' => 'none', 'why' => null];
                }
            }
            // BUSY_WHY, and nothing more specific. That this project is busy is
            // already disclosed to anyone who presses Start - the store answers
            // with this same sentence - so repeating it here reveals nothing
            // new, and saying anything MORE would confirm the run's scope to
            // somebody outside it.
            return ['run_id' => null, 'state' => 'other', 'why' => ScanStore::BUSY_WHY];
        } catch (\Throwable $e) {
            return ['run_id' => null, 'state' => 'unknown', 'why' => null];
        }
    }

    /**
     * Retire any active run whose scope no user of this project could match.
     *
     * THE AUTHORITY IS THE PROJECT'S OWN GROUP LIST. A run is unworkable when
     * its scope_dag is not one of this project's current group ids. That covers
     * both populations exactly: a run stamped with a friendly DAG name before
     * the axis was fixed, and a run scoped to a group that has since been
     * deleted. Neither can ever be worked by anybody, and both hold the slot.
     * Testing the SHAPE of the value instead - ctype_digit, say - would be a
     * guess: a DAG whose label is a year has an all-digit unique name, and the
     * cost of guessing wrong here is destroying a live run.
     *
     * READ FROM THE TABLE, NOT FROM REDCap::getGroupNames(). That function
     * answers for the AMBIENT project - the one $Proj and PROJECT_ID name - and
     * this method is handed a $pid. On a request where the two differ it would
     * validate this project's runs against another project's groups, and every
     * scope would look invalid: the failure mode is retiring live runs, which
     * is the one outcome worse than leaving a wedged one alone.
     *
     * FAILS TOWARD LEAVING RUNS ALONE. A group list that cannot be read at all
     * throws, is caught, and retires nothing. The next request tries again.
     *
     * @return int runs retired
     */
    private function reapUnworkableScopes($pid)
    {
        try {
            $valid = [];
            foreach ($this->db->select('SELECT group_id FROM redcap_data_access_groups
                WHERE project_id = ?', [$pid]) as $g) {
                $valid[(string) $g[0]] = true;
            }
            $rows = $this->db->select('SELECT run_id, scope_dag FROM ' . Schema::table('scan_run')
                . ' WHERE project_id = ? AND active_slot = 1 AND scope_dag IS NOT NULL', [$pid]);
            $n = 0;
            foreach ($rows as $row) {
                if (isset($valid[(string) $row[1]])) continue;
                // `expired`, because that is what Schema::upgradeDataV2 writes
                // for the same condition - the two migrations must not leave
                // rows a reader can tell apart.
                $this->store()->finish((int) $row[0], ScanOutcome::derive(['expired' => true]));
                // The run id and the project are enough to act on. The scope
                // value is deliberately NOT logged: it may be another group's
                // name, and this record is readable by whoever can read the
                // module log.
                $this->note('scan run retired: unworkable scope',
                    ['project_id' => (int) $pid, 'run_id' => (int) $row[0]]);
                $n++;
            }
            return $n;
        } catch (\Throwable $e) {
            // As reapCancelled(): a reap that fails must not stop the caller
            // getting an honest answer about the run that is still there.
            return 0;
        }
    }

    /**
     * Write the terminal state of any run this project left in `cancelling`.
     *
     * The phase is a deliberate two-step - bump the epoch, then finish - so that
     * a worker already evaluating fails its compare-and-set rather than
     * committing into a finished run. The second step had no owner, so the run
     * stayed active and the project stayed busy.
     *
     * Idempotent and silent: finish() refuses to reopen anything, and a project
     * with nothing cancelled does no work here.
     *
     * @return int runs finished
     */
    private function reapCancelled($pid)
    {
        try {
            $rows = $this->db->select('SELECT run_id FROM ' . Schema::table('scan_run')
                . ' WHERE project_id = ? AND active_slot = 1 AND phase = ?',
                [$pid, ScanPhase::CANCELLING]);
            $n = 0;
            $store = $this->store();
            $limits = $rows ? $this->limits($pid) : [];
            foreach ($rows as $row) {
                ScanPromotion::finishCancelled($store, $pid, (int) $row[0], $limits);
                $n++;
            }
            return $n;
        } catch (\Throwable $e) {
            // A reap that fails must not stop a start from being ATTEMPTED; the
            // slot check below will refuse honestly if the run is still there.
            return 0;
        }
    }

    // -- assembly ------------------------------------------------------------

    /** The wording every unknown run gets, whatever the real reason. */
    const NO_RUN = 'no scan with that reference is running for this project';

    /**
     * Rights, entitlement forms and the plan, re-derived NOW.
     *
     * Every read and every work request goes through this rather than trusting
     * the run: rights revoked during a run stop further reads, and the run id
     * does not restore them.
     *
     * ONCE PER REQUEST FOR ONE READER OF ONE RUN (M12). A work request asked
     * twice - for the work, and for the status it returns - and each answer
     * rebuilt the plan. "Re-derived now" means per request, and a second answer
     * one call later is the same request. The key is everything the answer is
     * made of: the project, the run's scope and the generation and sequence
     * the evaluator is bound to, and the reader, whose rights, value ceiling
     * and cross-project answers (`crossRefused`) are all in it.
     */
    private function entitlement($pid, array $run)
    {
        $key = json_encode([(int) $pid,
            isset($run['scope_dag']) ? (string) $run['scope_dag'] : null,
            isset($run['generation_id']) ? (int) $run['generation_id'] : null,
            isset($run['run_seq']) ? (int) $run['run_seq'] : null,
            $this->username()]);
        if (!array_key_exists($key, $this->entitlements)) {
            $this->entitlements[$key] = $this->entitlementNow($pid, $run);
        }
        return $this->entitlements[$key];
    }

    /** @see entitlement() — the answer, worked out. */
    private function entitlementNow($pid, array $run)
    {
        $scope = ScanPageView::scanScope($this->module, $pid);
        if (empty($scope['ok'])) {
            // FINAL unless the rights could not be READ (review R2O-4): a
            // refusal on what they say is a decision, and one on a read that
            // threw is a moment - see status().
            return ['ok' => false, 'why' => $scope['why'], 'final' => empty($scope['transient'])];
        }
        $policy = $this->policy($pid);
        // The run is in hand, so the evaluator is bound to THIS run's
        // generation and sequence - the numbers its findings are written under
        // and the interval they open.
        $ctx = $this->module->durableScanContext($pid, $this->planOptions($pid, [
            'valueCeiling' => $policy['valueMode'],
            'generation'   => (int) $run['generation_id'],
            'runSeq'       => (int) $run['run_seq'],
            'policy'       => $policy,
        ]), $run['scope_dag']);
        // Never final: the commonest reason is a rule list or a dictionary that
        // could not be read just now.
        if (empty($ctx['ok'])) return ['ok' => false, 'why' => $ctx['why'], 'final' => false];

        // NULL AND '' ARE "COULD NOT BE PLACED", AND THIS LOOP IS THE WHOLE
        // FAIL-CLOSED PROPERTY. A field the plan could not put on an instrument
        // is a field whose access cannot be checked, so it sets $unknown and
        // mayStart() refuses - it is never merely dropped from the set, which
        // would be a silent widening of what the run may read.
        $forms = [];
        $unknown = [];
        foreach ($ctx['ownership'] as $field => $form) {
            if ($form === null || $form === '') { $unknown[] = (string) $field; continue; }
            $forms[$form] = true;
        }
        // TWO SETS, BECAUSE READING A RUN AND STOPPING ONE ARE DIFFERENT
        // QUESTIONS.
        //
        // 'forms' is the read set: every instrument the run actually reads,
        // operands included. That is the right entitlement for start, work and
        // read, and widening it is the fix this commit exists for.
        //
        // Feeding it to CANCEL as well would be a new way for a run to become
        // permanently unstoppable. A run legitimately started before this
        // deploy, on a project where a when/assert/with operand sits on an
        // instrument the actor cannot read, would become unworkable AND
        // unreadable AND uncancellable at once - and it holds active_slot = 1,
        // so openRun() answers busy for everyone on that project. The three
        // paths that release a slot are finish() from a worker pass (gated by
        // mayWork), reapCancelled() (needs phase `cancelling`, which needs a
        // successful cancel), and ScanRetention::expireAbandoned(), which has
        // no caller (tests/scan_wiring_php.php). The exit would be a DBA.
        //
        // mayCancel's own docblock forbids this shape in the general case -
        // "Ownership would only add a way for a wedged run to become
        // unstoppable" - and keying it on instrument entitlement rather than on
        // creator identity does not make it a different shape.
        //
        // So cancel keeps the NARROWER, host-only set: the instruments the
        // rules themselves live on, which is what every gate was asked about
        // before this commit. It is not a hole: mayCancel still requires full
        // export rights, design rights and an exact scope match, and cancelling
        // reads no record value at all.
        $cancelForms = [];
        foreach ((isset($ctx['plan']['hostFields']) && is_array($ctx['plan']['hostFields'])
                  ? $ctx['plan']['hostFields'] : []) as $hosts) {
            foreach ((is_array($hosts) ? $hosts : []) as $form => $_) {
                if ($form !== null && $form !== '') $cancelForms[$form] = true;
            }
        }
        return ['ok' => true, 'why' => null, 'rights' => $scope['rights'],
                'forms' => array_keys($forms), 'unknown' => $unknown,
                'cancelForms' => array_keys($cancelForms),
                // The CALLER's scope, kept beside the run's own. The two are
                // equal for any request the authorisation above let through -
                // they are compared as ids by mayWork() - and keeping both is
                // what let the seam be asserted rather than assumed.
                'policy' => $policy, 'ctx' => $ctx, 'dag' => $scope['dag']];
    }

    private function store()
    {
        return $this->givenStore !== null ? $this->givenStore : new SqlScanStore($this->db);
    }

    /**
     * The project's change log, or null when this installation has none.
     *
     * OPENED ONCE PER REQUEST. Opening it is two reads - the table name and the
     * top of the log - and a work request opened it four times: for the
     * worker, for catch-up, and for the uniqueness finalizer twice. Whether a
     * project has a change log does not change inside a request; where its top
     * is does, and that is read through the fence each time it is asked.
     */
    private function fence($pid)
    {
        $k = (int) $pid;
        if (!array_key_exists($k, $this->fences)) {
            $f = SourceFence::forProject($this->db, $pid);
            $this->fences[$k] = empty($f['ok']) ? null : $f['fence'];
        }
        return $this->fences[$k];
    }

    /**
     * The record-enumeration probe, asked once per request: available() gates
     * on it, and the capability census the plan needs contains it.
     */
    private function recordEnumeration($pid)
    {
        $k = (int) $pid;
        if (!isset($this->enumeration[$k])) {
            $this->enumeration[$k] = ScanCapabilities::recordEnumeration($this->module, $pid);
        }
        return $this->enumeration[$k];
    }

    /**
     * Every capability probe, once per request, reusing the enumeration answer
     * available() already has. Null when the probes threw: the plan then asks
     * for itself, and its own catch caps the run at the weakest coverage.
     */
    private function capabilities($pid)
    {
        $k = (int) $pid;
        if (!array_key_exists($k, $this->capabilities)) {
            try {
                $known = isset($this->enumeration[$k])
                       ? ['recordEnumeration' => $this->enumeration[$k]] : [];
                $this->capabilities[$k] = ScanCapabilities::all($this->module, $pid, $known);
                $this->enumeration[$k] = $this->capabilities[$k]['recordEnumeration'];
            } catch (\Throwable $e) {
                $this->capabilities[$k] = null;
            }
        }
        return $this->capabilities[$k];
    }

    /**
     * What durableScanContext() is asked with: the caller's options, plus this
     * request's plan memo and capability census, so neither is rebuilt for a
     * second question in the same request.
     */
    private function planOptions($pid, array $opts)
    {
        $opts['memo'] = $this->plans;
        $caps = $this->capabilities($pid);
        if ($caps !== null) $opts['capabilities'] = $caps;
        return $opts;
    }

    /**
     * The project's record source, opened at most once per request.
     *
     * Opening it resolves the data table and the record-id field, which is two
     * reads; catch-up and the worker both need it in the same request.
     *
     * @return RecordManifestSource|null null when it cannot be opened
     */
    private function openSource($pid)
    {
        $k = (int) $pid;
        if (!array_key_exists($k, $this->sources)) {
            $src = RecordManifestSource::open($this->db, $pid, ['pk' => $this->recordIdField($pid)]);
            $this->sources[$k] = empty($src['ok']) ? null : $src['source'];
        }
        return $this->sources[$k];
    }

    /**
     * "Are these records still in the project", as a callable.
     *
     * WITH NO SOURCE EVERYTHING IS STILL THERE, which is exist()'s own failure
     * direction: a record is never called deleted because a table could not be
     * opened.
     */
    private function existsProbe($pid)
    {
        $source = $this->openSource($pid);
        return function (array $ids) use ($source) {
            return $source === null
                ? array_fill_keys(array_map('strval', $ids), true) : $source->exist($ids);
        };
    }

    private function catchUp($pid, ScanStore $store, $dag)
    {
        $source = $this->openSource($pid);
        $key = $this->key();
        return new CatchUp($store, [
            'fence'  => $this->fence($pid),
            'hash'   => function ($id) use ($pid, $key) {
                return Hmac::raw(Hmac::P_RECORD, $pid, (string) $id, $key);
            },
            'exists' => $this->existsProbe($pid),
            'scope'  => function (array $ids) use ($source, $dag) {
                // No source means no way to establish a group. For a scoped run
                // that is a refusal to admit; for an unscoped one it is
                // irrelevant, and inScope() draws that distinction.
                if ($source === null) return array_fill_keys($ids, $dag === null);
                return $source->inScope($ids, $dag);
            },
            'dag'    => function (array $ids) use ($source) {
                return $source === null ? array_fill_keys($ids, null) : $source->dagsOf($ids);
            },
        ]);
    }

    private function finalizer($pid, array $ctx)
    {
        $read = $ctx['read'];
        return new UniqueFinalizer($this->db, [
            'pid'      => $pid,
            'hmacKey'  => $this->key(),
            'versions' => $this->fence($pid),
            // Re-reading a duplicate group goes through the SAME read the worker
            // uses, so a group is verified against the values the scan would
            // have seen rather than a second, differently-shaped export.
            'read'     => function (array $locs) use ($read) {
                $ids = [];
                foreach ($locs as $l) $ids[(string) $l['record']] = true;
                $got = $read(array_keys($ids));
                if (empty($got['ok'])) {
                    return ['ok' => false, 'values' => [], 'why' => $got['why']];
                }
                $out = [];
                foreach ($locs as $l) {
                    $v = self::valueAt($got['data'], $l);
                    if ($v === null) continue;
                    $out[UniqueFinalizer::locKey($l)] = [$v];
                }
                return ['ok' => true, 'values' => $out, 'why' => null];
            },
        ]);
    }

    /**
     * One value out of a getData array, or null when it is not there.
     *
     * A value that is not where we looked is reported ABSENT rather than empty,
     * because the finalizer blocks on absent and would confirm on empty - and
     * confirming a duplicate nobody read is the one outcome that class exists to
     * prevent.
     */
    private static function valueAt(array $data, array $loc)
    {
        $rec = isset($data[(string) $loc['record']]) ? $data[(string) $loc['record']] : null;
        if (!is_array($rec)) return null;
        $ev = $loc['event_id'];
        $inst = isset($loc['instance']) ? (int) $loc['instance'] : 1;

        if ($inst > 1 && isset($rec['repeat_instances'][$ev])) {
            foreach ($rec['repeat_instances'][$ev] as $form => $rows) {
                if (isset($rows[$inst]) && is_array($rows[$inst])
                        && array_key_exists($loc['field'], $rows[$inst])) {
                    return $rows[$inst][$loc['field']];
                }
            }
            return null;
        }
        $node = isset($rec[$ev]) ? $rec[$ev] : null;
        if (is_array($node) && array_key_exists($loc['field'], $node)) return $node[$loc['field']];
        return null;
    }

    /** The effective policy: the system maximum, then whatever the project asked for. */
    private function policy($pid)
    {
        $sys = [];
        foreach (['scan-system-max-value-retention-days', 'scan-system-max-run-retention-days',
                  'scan-system-max-detail-findings', 'scan-system-max-detail-bytes',
                  'scan-system-max-concurrent-projects', 'scan-system-stale-run-hours',
                  'scan-system-record-attempts'] as $k) {
            $sys[$k] = $this->setting($k, null);
        }
        $prj = [];
        foreach (['scan-value-storage', 'scan-value-retention-days', 'scan-run-retention-days',
                  'scan-max-detail-findings', 'scan-max-detail-bytes'] as $k) {
            $prj[$k] = $this->setting($k, $pid);
        }
        return ScanPolicy::resolve($sys, $prj);
    }

    /** The system setting that overrides ScanPolicy::D_PLANNING_MINUTES. */
    const PLANNING_SETTING = 'scan-system-planning-minutes';

    /**
     * How long a run may plan before the page says planning stopped, read
     * where it is used - see ScanPolicy::planningSeconds() for why it is not
     * part of the frozen policy. THIS request's time limit stands in for the
     * planning request's: both are the installation's PHP setting, and
     * openRun() does not change it.
     */
    private function planningSeconds()
    {
        return ScanPolicy::planningSeconds($this->setting(self::PLANNING_SETTING, null),
                                           ini_get('max_execution_time'));
    }

    /**
     * The project's HMAC secret.
     *
     * Hmac::raw throws on a missing key rather than falling back to an unkeyed
     * hash, so an installation with no secret cannot silently produce record
     * hashes anyone could enumerate. available() has already refused by then in
     * every path that matters, and this is the backstop.
     */
    private function key()
    {
        return $this->setting('log-hmac-key', null);
    }

    private function recordIdField($pid)
    {
        try {
            if (is_callable(['\REDCap', 'getRecordIdField'])) {
                $f = \REDCap::getRecordIdField($pid);
                if (is_string($f) && $f !== '') return $f;
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    /**
     * The evaluator's revision: BUMP IT WHENEVER A VERDICT CAN CHANGE (review
     * R3G-4).
     *
     * It is the `engine` input of every run's fingerprint, so a durable run
     * started under one evaluator and worked under another is refused ("the
     * configuration changed") instead of finishing as a report half judged one
     * way and half the other. A verdict can change when a check judges a value
     * differently, when a gate admits or drops a different set of rules for a
     * record, when a reason or a finding's identity moves, or when a rule type
     * is added or removed. Wording alone is not a verdict.
     *
     * engineVersion() read a UV_ENGINE_VERSION constant that nothing defined,
     * so the input was 'engine-1' on every run of every release, and a run
     * that spanned an upgrade mixed two evaluators.
     *
     *   engine-1  every release before this constant
     *   engine-2  the started-form gate and its round-3 corrections
     */
    const ENGINE_REVISION = 'engine-2';

    /** The fingerprint's `engine` input. One place, so start and work cannot disagree. */
    private function engineVersion()
    {
        return self::ENGINE_REVISION;
    }

    private function username()
    {
        try {
            if (is_callable([$this->module, 'getUser'])) {
                $u = $this->module->getUser();
                if ($u && is_callable([$u, 'getUsername'])) return (string) $u->getUsername();
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    /** This worker's name, for the leases. Distinct per user and per request. */
    private function owner()
    {
        return substr('u:' . $this->username() . ':' . substr(md5(uniqid('', true)), 0, 8), 0, 64);
    }

    private function flag($key, $pid)
    {
        $v = $this->setting($key, $pid);
        return ($v === true || $v === 1 || $v === '1' || $v === 'true');
    }

    private function setting($key, $pid)
    {
        try {
            if ($pid === null) {
                return is_callable([$this->module, 'getSystemSetting'])
                     ? $this->module->getSystemSetting($key) : null;
            }
            return is_callable([$this->module, 'getProjectSetting'])
                 ? $this->module->getProjectSetting($key, $pid) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function no($why, $detail = null)
    {
        return ['ok' => false, 'why' => $why, 'detail' => $detail];
    }

    private static function noStart($why)
    {
        return ['ok' => false, 'busy' => false, 'run_id' => null, 'why' => $why];
    }

    /**
     * Record a storage failure, and answer with the sentence the operator gets.
     *
     * TWO AUDIENCES AND THEY NEVER SWAP. The return value is
     * ScanStoreUnavailable::OPERATOR_TEXT - one fixed sentence, no table name,
     * no column, no value, no error number - and it is the only thing that
     * reaches the page. What the server said goes to the module log, which is
     * the module's admin-only surface. Reporting nothing is how the pilot spent
     * five rounds on misattributed causes; reporting the server's own text to
     * the browser is how a batch of participant data leaves through an error
     * message. This is the one place the module gets to choose both.
     */
    private function storageFailed($pid, $runId, ScanStoreUnavailable $e)
    {
        $this->note('scan storage failure', ['project_id' => $pid, 'run_id' => $runId,
                                             'detail' => $e->safeDetail()]);
        return ScanStoreUnavailable::OPERATOR_TEXT;
    }

    /**
     * One line in the module log, and never an exception of its own.
     *
     * The log write goes through the same database connection that has just
     * failed, so this is expected to fail too; a throw from here would replace
     * the diagnosis with a second, less useful failure.
     */
    private function note($event, array $context)
    {
        try {
            if (is_callable([$this->module, 'log'])) $this->module->log($event, $context);
        } catch (\Throwable $ignored) {
        }
    }
}
