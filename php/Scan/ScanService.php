<?php

namespace INSPIRE\UniversalValidator\Scan;

// Both live in the parent namespace: ScanPageView is shared with the pages, and
// ScanCapabilities answers what an installation may claim. Imported rather than
// duplicated - a second copy of a security decision is a second copy that ages
// differently from the first.
use INSPIRE\UniversalValidator\ScanPageView;
use INSPIRE\UniversalValidator\ScanCapabilities;
// The report's labels, snapshotted at planning (M5), and its columns.
use INSPIRE\UniversalValidator\ScanDimensions;
use INSPIRE\UniversalValidator\ScanColumns;

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

    /** The system settings the disable and enable hooks write their unix time to (M17). */
    const DISABLED_AT = 'scan-disabled-at';
    const ENABLED_AT  = 'scan-enabled-at';

    /**
     * The same pair as project settings, written by the hooks that switch the
     * module off and on for one project (review rvf2-1). Their own keys: a
     * project value under the system keys would be read as the system's
     * wherever the framework lets a project setting stand in for one.
     */
    const PROJECT_DISABLED_AT = 'scan-project-disabled-at';
    const PROJECT_ENABLED_AT  = 'scan-project-enabled-at';

    /** What Start and work answer while the module is being switched off; see disabling(). */
    const OFF_SERVER = 'the module is being switched off on this server, so no scan can start or carry on';
    const OFF_PROJECT = 'the module is being switched off for this project, so no scan can start or carry on';

    /** How long after a disable, with no enable since, Start and work refuse; see disabling(). */
    const DISABLING_SECONDS = 600;

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
     *
     * The list is ScanPhase's, because the reaper reads the same clock: a run
     * this page reported working was expired as abandoned by the cron and by
     * another user's Start, since the reaper judged every phase by
     * `progress_at` alone (review rv10a1-1).
     */
    const PHASES_ON_THE_UPDATE_CLOCK = ScanPhase::ON_THE_UPDATE_CLOCK;

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
    /**
     * @var array crossRefusal() key => what the module answered: a work
     * request asks it for the work and again for the status it returns, and
     * both are the same reader in the same request (see entitlement()).
     */
    private $crossAnswers = [];
    /** @var \ArrayObject the plans durableScanContext() built for this request */
    private $plans;
    /** @var ?ScanStore the store a test handed in; null is SqlScanStore over $db */
    private $givenStore;
    /**
     * @var bool whether the planning slice of the work request being answered
     * committed a page of the walk. Set by the planner workerDeps() hands the
     * worker; read once, by disclosed().
     */
    private $planMoved = false;

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
            return self::no(self::NOT_ENABLED);
        }
        $off = $this->disabling($pid);
        if ($off !== null) return self::no($off === 'project' ? self::OFF_PROJECT : self::OFF_SERVER);
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

        // AND ANY RUN THAT STOPPED MAKING PROGRESS (B6a). A run whose tab
        // closed or whose worker died held the slot until the reap cron came
        // round - or for ever, where the cron is not running - and Start
        // answered "busy" with nobody's scan. The store's reaper uses the
        // progress clock and the stale-run window, moves the lease epoch in
        // the same statement and leaves an audit entry naming this user. After
        // the cancelled-run reap, so a run that was asked to stop ends as
        // cancelled, not expired. Swallowed as that reap is: a reap that fails
        // must not stop the start being attempted, and the slot check refuses
        // honestly if the run is still there.
        //
        // AFTER THE RIGHTS CHECK (review rv10a2-2). Asked before it, any
        // signed-in user of the project - no design rights, confined to a
        // group - ended the project's run by posting scan-start, and the audit
        // named them; the refusal they were then given came after the damage.
        // The cancelled-run reap stays first: it ends only a run somebody with
        // the right to stop it already stopped.
        $policy = $this->policy($pid);
        try {
            $this->store()->expireAbandoned($pid, $policy['staleHours'], (string) $this->username());
        } catch (\Throwable $e) {
        }

        // PLANNING CONTEXT. start() needs the rule list and the ownership map
        // to authorise and to plan; the run - and therefore its generation -
        // does not exist yet. Asking for a null generation says that, and gets
        // back a context with no evaluator rather than one silently bound to
        // generation 1, which is what every run of every project used to write.
        $ctx = $this->module->durableScanContext($pid,
            $this->planOptions($pid, ['valueCeiling' => $policy['valueMode'], 'generation' => null,
                                      'policy' => $policy]), $dag);
        if (empty($ctx['ok'])) return self::noStart(self::contextRefusal($ctx));

        // Every instrument the run will read, from the plan rather than from a
        // list somebody maintains. A field the plan could not place on an
        // instrument is an unknown ownership, and unknown ownership is denial.
        // THE NAMES, NOT MERELY THE FACT. mayStart() prints them, and an
        // unplaceable field is a fixable dictionary problem: the field exists,
        // its form_name does not. A refusal that says only "at least one field"
        // sends an administrator looking through the whole dictionary.
        $sets = self::formsOf($ctx);
        $auth = ScanAuthorization::mayStart($scope['rights'], $sets['forms'], $sets['unknown']);
        if (empty($auth['ok'])) return self::noStart($auth['why']);
        // A STARTER ANOTHER PROJECT REFUSES IS REFUSED THE START (review
        // rv10b1-2). Their plan skips the lookups that project would not
        // answer, and a run started on it was evaluated without those rules
        // on this person's passes and with them on everybody else's. status()
        // already refused them the run they had started (crossRefusal()).
        $cross = self::planCrossRefusal($ctx, 'start');
        if ($cross !== null) return self::noStart($cross['why']);

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

        // A RUN IS NOT STARTED ON A CONFIGURATION READ IN PART (review R5-4).
        // The fingerprint stored here is what every later request compares;
        // built from a read that failed, it would differ from the project's
        // real one and end the run at its first fully read request.
        if (!empty($ctx['unread'])) return self::noStart(self::configUnread($ctx['unread']));

        $planner = new ScanPlanner($this->store(), $this->key());
        // THE FINGERPRINT'S INPUTS ARE THE BAG configurationNow() HASHES (B7):
        // the rules, the ownership map, the structure and choice sets (M8), the
        // policy and the engine, built by one method so the stored fingerprint
        // and every later request's are made from the same keys.
        $r = $planner->plan($pid, array_merge($this->fingerprintInputs($ctx, $policy), [
            'source'    => $src['source'],
            'fence'     => $fence,
            'dagFilter' => $dag,
            // THE FIRST SLICE IS BUDGETED (B10, review R3U-2). plan() was given
            // no deadline and no memory cap here, so the whole list was walked
            // inside this request: past max_execution_time it died uncatchably,
            // and with no limit at all it ran on while the page told its reader
            // the run had stopped. A browser budget is seconds; the rest of the
            // list is listed by the work requests the page sends next, a slice
            // each. The start takes no worker slot: one slice is far inside the
            // slot's TTL, and a start refused for capacity is a start lost.
            'budget'    => new WorkBudget(['mode' => 'browser']),
            'owner'     => $this->owner(),
            'attempts'  => $policy['recordAttempts'],
            'leaseSeconds' => self::SLOT_TTL,
            'limits'    => self::limitsOf($policy),
            // Every lookup into another project the rules make, kept on the
            // run for status() (reviews R3U-N1, R4-1). A plan that does not
            // say is passed on as not saying, which status() refuses.
            'crossLookups' => (isset($ctx['plan']) && is_array($ctx['plan'])
                               && array_key_exists('crossLookups', $ctx['plan']))
                               ? $ctx['plan']['crossLookups'] : null,
            'createdBy' => (string) $this->username(),
            // The clock every record of the run is judged by (W10BF-3),
            // frozen into its policy; see durableScanContext().
            'asOf'      => isset($ctx['clock']) ? $ctx['clock'] : null,
            // WHAT THE PLAN ALREADY KNOWS IT CANNOT EVALUATE. Config-broken,
            // unlocatable, unmapped-instrument and group-unscopable rules are
            // decided before a record is read; the planner records them so
            // ScanOutcome's `ruleProblems` term has a producer at all. Without
            // this line the term is dead and `clean` means only "no findings".
            'ruleProblems' => isset($ctx['problems']) ? $ctx['problems'] : [],
            // The labels its report will use, as the project is now (M5).
            'dimensions' => $this->planningLabels($pid, $ctx),
        ]));
        if (empty($r['ok'])) {
            return ['ok' => false, 'busy' => !empty($r['busy']), 'run_id' => null,
                    'why' => $r['why']];
        }
        $runId = (int) $r['run']['run_id'];
        $this->store()->audit($pid, $runId, 'start', (string) $this->username(), null);
        // A RUN MADE WHILE THE MODULE WAS BEING SWITCHED OFF IS ENDED AGAIN
        // (review rvf2-1). Asked afresh, never from available()'s memo.
        $off = $this->disabling($pid);
        if ($off !== null) return self::noStart($this->endUnderDisable($pid, $runId, $off));
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

        // The reader before the run (L8): see readerScope().
        $no = $this->readerRefusal($pid);
        if ($no !== null) return ['ok' => false, 'why' => $no['why']];
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

        // THE CONFIGURATION AS IT IS NOW, FOR PROMOTION TO JUDGE (W7-N11).
        //
        // This compared the fingerprint itself and refused the request, and
        // gave promote() neither input - so a run whose configuration had moved
        // was refused work on every pass and sat holding the project until the
        // reaper expired it, and the `failed` terminal facts() decides for it
        // was unreachable from production. Both inputs are handed to promote()
        // now, on every pass; when either has moved the worker is not built at
        // all, and promote() ends the run there. M8 is what makes the
        // fingerprint mean something: the project's structure and its choice
        // codes are in it, from durableScanContext().
        //
        // A FINISHED RUN IS ANSWERED WITH ITS STATUS (review R5-8). The
        // fingerprint was compared first, so a late request from a second tab,
        // sent after a design edit, was told the run had been ended for a
        // change, about a run that had finished complete before it.
        if ((isset($run['terminal']) && $run['terminal'] !== null && $run['terminal'] !== '')
                || (isset($run['phase']) && $run['phase'] === ScanPhase::TERMINAL)) {
            return ['ok' => true, 'worked' => 0, 'requeued' => 0, 'blocked' => 0, 'findings' => 0,
                    'phase' => ScanPhase::TERMINAL, 'done' => true, 'stop' => null,
                    'why' => 'this scan has finished', 'status' => $this->statusOf($pid, $runId, [])];
        }
        // A CONFIGURATION READ IN PART JUDGES NOTHING (review R5-4). One read
        // that failed on an unchanged project - its design, its Missing Data
        // Codes, another project's settings behind a lookup - moved the
        // fingerprint, and promote() ended an hours-long run failed for a
        // change that never happened. The request is refused as one to retry;
        // only a configuration read in full may end a run.
        if (!empty($ctx['unread'])) {
            return ['ok' => false, 'worked' => 0, 'requeued' => 0, 'blocked' => 0, 'findings' => 0,
                    'phase' => isset($run['phase']) ? $run['phase'] : null, 'done' => false,
                    'stop' => 'unread', 'why' => self::configUnread($ctx['unread']),
                    'status' => $this->statusOf($pid, $runId, [])];
        }
        $config = $this->configurationNow($run, $policy, $ctx);
        $limits = self::limitsOf($policy);
        // A TIGHTENED PRIVACY POLICY STOPS THE RUN; IT DOES NOT FAIL IT (review
        // R5-5). Only the rules and the design moving is a run that describes
        // two configurations. When the policy revision moved, the worker is
        // built with it, and its own guard ends the run through
        // ScanPromotion::finishCancelled() with the privacy sentence - the
        // guard workerDeps() never armed, so it could not be reached.
        if ($config['moved'] && !$config['privacyMoved']) {
            ScanPromotion::promote($store, $pid, $runId, array_merge($limits, [
                'fingerprintNow' => $config['fingerprintNow'], 'engineNow' => $this->engineVersion(),
                'policyRevisionNow' => $config['policyRevisionNow']]));
            // rv10b1-6: an upgrade of the module is said to be one.
            $why = ScanPromotion::engineOf($run) !== $this->engineVersion()
                 ? self::ENGINE_CHANGED : self::CONFIG_CHANGED;
            return ['ok' => false, 'worked' => 0, 'requeued' => 0, 'blocked' => 0, 'findings' => 0,
                    'phase' => ScanPhase::TERMINAL, 'done' => false, 'stop' => 'changed',
                    'why' => $why, 'status' => $this->statusOf($pid, $runId, [])];
        }
        // A READER ANOTHER PROJECT REFUSES DOES NOT WORK THE RUN (reviews
        // rv5b-5, rv10b1-2). Two questions, one refusal:
        //   - the run's own lookups, asked for this reader as status() asks
        //     them. A reader refused the results was still let work, and the
        //     work answer's counters - findings among them - are results;
        //   - this reader's plan. A lookup it is refused is a rule its context
        //     skips, and the rule stays in the list, so the fingerprint above
        //     matched. The pass examined records without the rule and
        //     committed them as checked, and the run could finish clean.
        // Asked here, after the branches above that end a run or answer with
        // its status: those evaluate nothing, and a run must stay stoppable.
        $cross = $this->crossRefusal($pid, $run, 'work');
        if ($cross === null) $cross = self::planCrossRefusal($ctx, 'work');
        if ($cross !== null) {
            return ['ok' => false, 'why' => $cross['why'], 'final' => !empty($cross['final'])];
        }
        $this->planMoved = false;
        $worker = new ScanWorker($store, $this->workerDeps($pid, $store, $run, $policy, $ctx, $mode,
                                                           $config['policyRevisionNow'],
                                                           $config['fingerprintNow']));
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
            return $this->disclosed($run, $r, ['ok' => false,
                                               'why' => ScanStoreUnavailable::OPERATOR_TEXT]);
        }

        // Promotion is attempted on every pass, not only the one that finishes.
        // It refuses until the run really is finishable, so asking early costs
        // one predicate - while not asking at all is how a run ends up complete
        // in every respect except the row that says so.
        $u = $this->finalizer($pid, $ctx, $store)->status((int) $run['generation_id']);
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
            // W7-N11: what the configuration is now. They matched above, so on
            // this pass they decide nothing; they are here so that promotion is
            // never again judged without them.
            'fingerprintNow'    => $config['fingerprintNow'],
            'engineNow'         => $this->engineVersion(),
            'policyRevisionNow' => $config['policyRevisionNow'],
            // A @UVEXISTS verdict depends on other records, which the catch-up
            // does not re-check when one of them changes (ScanPromotion::facts).
            'crossRecordLookups' => !empty($ctx['plan']['crossRecordLookups']),
            // W7-N11: whether the report's labels are degraded, from the run's
            // snapshot. Read only on the pass that can finish, since promotion
            // refuses before the summary is done and the answer selects
            // wording only.
            'labelDegraded' => ($r['phase'] === ScanPhase::ROLLUP && !empty($r['done']))
                && $this->labelsDegraded($pid, (int) $run['generation_id']),
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
        return $this->disclosed($run, $r, $this->statusOf($pid, $runId, $known));
    }

    /**
     * The worker's answer as the reader may see it, with the status beside it.
     *
     * `moved` says whether this request moved the run at all: a counter above
     * zero, a planning page committed, or a new phase. It is what the page
     * paces itself by, and since review R5-1 `listed` alone cannot say it: a
     * group-scoped slice whose page held only other groups' records lists
     * nothing the reader may count, and the walk moved all the same.
     *
     * A RUN THAT HAS NOT PROVED ITS SCOPE GIVES NO COUNTS (H10 part 1). The
     * counters are what the run's counts are summed from, so a status that
     * withholds the counts withholds them here too.
     *
     * A STATUS THAT WAS REFUSED GIVES NO COUNTS EITHER, ON ANY RUN (review
     * rv5b-5). This held only for a group-scoped run, where unread is not
     * proved; on any other run a reader refused the status was still handed
     * what the request examined and found. What the reader may not be shown
     * as the run's counts they may not be shown as this request's.
     *
     * @param array $before the run row as this request first read it
     */
    private function disclosed(array $before, array $r, array $status)
    {
        $sum = 0;
        foreach (['worked', 'requeued', 'blocked', 'findings', 'listed'] as $k) {
            if (isset($r[$k])) $sum += max(0, (int) $r[$k]);
        }
        // `moved` from the worker: catch-up walked a page none of whose
        // changes the reader may count, or a batch's records all left the
        // group (review rv5b-3).
        $moved = $sum > 0 || $this->planMoved || !empty($r['moved'])
              || (isset($r['phase']) && isset($before['phase']) && (string) $r['phase'] !== (string) $before['phase']);
        $r['moved'] = $moved;
        if (!empty($status['withheld']) || empty($status['ok'])) {
            $r = ScanAuthorization::preFenceWork($r, $moved);
        }
        $r['status'] = $status;
        return $r;
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
    private function workerDeps($pid, ScanStore $store, array $run, array $policy, array $ctx, $mode,
                                $policyRevision = null, $fingerprint = null)
    {
        $deps = [
            'slots'     => new WorkerSlots($this->db),
            'slotTtl'   => self::SLOT_TTL,
            // The limit as the settings say it now (review R4-4, W1-N1).
            'slotLimit' => $policy['maxProjects'],
            'fence'     => $this->fence($pid),
            'read'      => $ctx['read'],
            'evaluate'  => $ctx['evaluate'],
            'budget'    => new WorkBudget(['mode' => $mode, 'startedAt' => microtime(true)]),
            'owner'     => $this->owner(),
            'attempts'  => $policy['recordAttempts'],
            // The budget a stopped run is judged against, for the worker's
            // own ending of a run it finds `cancelling` (review R3U-3).
            'limits'    => self::limitsOf($policy),
            'finalizer' => $this->finalizer($pid, $ctx, $store, (int) $run['run_id'],
                                            $run['scope_dag'] !== null),
            'catchup'   => $this->catchUp($pid, $store, $run['scope_dag']),
            // The SAME question catch-up asks, of the same opened source: is
            // this record still in the project. The worker asks it before it
            // will call a record it could not read a deleted one.
            'exists'    => $this->existsProbe($pid),
            'rollup'    => new RollupBuilder($this->db, $store),
            // THE REST OF THE RECORD LIST, a slice per request (B10). The
            // worker supplies the budget, its lease owner and epoch, the attempt
            // cap and its slot renewal; this binds what only the service has:
            // the opened source, the key the record hashes are made with, and
            // the run's scope. A source that cannot be opened is handed over as
            // null, so the slice counts it as a failed attempt rather than this
            // closure failing before anything is counted.
            'planner'   => function ($runId, array $slice) use ($pid, $store, $run) {
                $planner = new ScanPlanner($store, $this->key());
                $res = $planner->step($pid, $runId, array_merge($slice, [
                    'source'    => $this->openSource($pid),
                    'dagFilter' => isset($run['scope_dag']) ? $run['scope_dag'] : null,
                    'pageSize'  => ScanPlanner::PAGE_SIZE,
                ]));
                // Whether the walk moved, which `listed` no longer says for a
                // group-scoped slice whose page held only other groups'
                // records (review R5-1). See disclosed().
                $this->planMoved = is_array($res) && !empty($res['moved']);
                return $res;
            },
            'note'      => function ($event, array $context) {
                $this->note($event, $context);
            },
            // What this request's context could not evaluate, written beside
            // every batch it evaluates (review rv10b1-2). start() noted only
            // the problems of the plan it was built from.
            'planProblems' => (isset($ctx['problems']) && is_array($ctx['problems'])) ? $ctx['problems'] : [],
        ];
        // The privacy policy revision as the settings make it now (review
        // R5-5). The worker compares it with the run's and, when it moved,
        // stops the run before writing anything further. Null when this
        // context does not ask for the comparison.
        if ($policyRevision !== null) $deps['policyRevision'] = (int) $policyRevision;
        // The fingerprint configurationNow() built from fingerprintInputs(), so
        // the worker's own guard is armed with the value the service compared
        // (B7). Null, and the guard unarmed, when the context does not ask.
        if ($fingerprint !== null) {
            $deps['fingerprint'] = (string) $fingerprint;
            // And the evaluator revision it was built with, so the guard can
            // say an upgrade ended the run (review rv10b1-6).
            $deps['engine'] = $this->engineVersion();
        }
        // A GROUP-SCOPED RUN ASKS EACH RECORD'S GROUP BEFORE IT READS IT
        // (review rv5b-1), the question catch-up asks, of the same source. A
        // record moved to another group since planning is that group's
        // record, and reading it would put that group's data into this run.
        // An unscoped run asks nothing and pays nothing.
        if (isset($run['scope_dag']) && $run['scope_dag'] !== null) {
            $deps['scope'] = $this->scopeProbe($pid, $run['scope_dag']);
        }
        return $deps;
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
        $off = $this->readGate($pid);
        if ($off !== null) return $off;
        return $this->statusOf($pid, $runId, []);
    }

    /**
     * May a stored run be READ here at all: the two switches, and nothing
     * else available() asks (review rv11a1, observation (a)).
     *
     * SWITCHED OFF IS OFF FOR READING TOO. Start and work asked available(),
     * and status, scan-findings and the export asked nothing, so a scan an
     * administrator had switched off - for the installation or for one
     * project - still answered its counts, its findings and its file to
     * anyone who held a run id, while the page showed only the notice that
     * the scan is unavailable. A switch is how an installation withdraws a
     * feature, and a stored report is part of the feature. The records stay
     * in their tables, under retention, and read again once it is back on.
     *
     * NOT the rest of available(): whether records can be enumerated and
     * whether the tables are healthy are questions about running a scan, and
     * a table that cannot be read fails as storage. Not the switch-off
     * window either (disabling()), which only stops new work. And not
     * cancel(): stopping a run is what switching off asks for, and a run
     * nobody may stop holds the project's slot until the reaper ends it.
     * Asked first, before the reader: it says nothing about a run.
     *
     * @return ?array the refusal, or null when reading is on
     */
    private function readGate($pid)
    {
        if ($this->flag(self::SYS_FLAG, null) && $this->flag(self::PROJ_FLAG, $pid)) return null;
        return ['ok' => false, 'why' => self::NOT_ENABLED, 'final' => true];
    }

    /** What available() and the read verbs say while either switch is off. */
    const NOT_ENABLED = 'the durable validation scan is not enabled here';

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
        // The reader before the run (L8): see readerScope().
        $no = $this->readerRefusal($pid);
        if ($no !== null) return $no;
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
        // A run can hold answers another project gave whoever drove it. A
        // reader that project would not answer is refused the whole run, by
        // the same rule as an instrument they cannot read (ScanAuthorization:
        // whole-report denial, never filtered rows). Cancel does not ask: a
        // run must stay stoppable.
        //
        // ASKED OF THE LOOKUPS THE RUN MADE, NOT OF THE RULES AS THEY STAND
        // (reviews R3U-N1, R4-1, R4-2, R4-3). The reader's plan was asked
        // here, so a rule whose agreement was revoked, or that was deleted or
        // edited to read another form, changed who saw answers the run already
        // held. The run keeps every lookup its rules make (whoever drives it),
        // and the other project is asked about each one for this reader.
        $refusal = $this->crossRefusal($pid, $run);
        if ($refusal !== null) return $refusal;

        // The lease is not one of ScanStore::RUN_COLUMNS, so it is read here
        // unless the row already carries it. See progressOf() for why a quiet
        // run needs it before it may be called stalled.
        if (!array_key_exists('lease_expires_at', $run)) {
            $run['lease_expires_at'] = $this->leaseOf($pid, $runId);
        }
        $census = (isset($known['census']) && is_array($known['census']))
                ? $known['census'] : $store->recordStates($runId);
        $progress = self::progressOf($run, $census, time(), self::STALL_SECONDS);
        // cancelForms, NOT forms - see entitlement(). A run nobody may stop
        // holds the project's only slot with no reaper behind it.
        $cancel = ScanAuthorization::mayCancel($ent['rights'], $ent['cancelForms'], $run['scope_dag']);
        // A GROUP-SCOPED RUN SHOWS NO COUNTS UNTIL ITS SCOPE IS PROVED AT THE
        // TARGET FENCE (H10 part 1). Its list is the group as planning read it,
        // and records move between groups while it runs; until catch-up has
        // walked the window, a total, a findings count or a percentage is a
        // claim about a scope nobody has established. preFenceStatus() keeps
        // the phase, the verdict and the controls. The findings read is not
        // made for a status that will not carry it.
        $proved = ScanAuthorization::scopeProved($run);

        $full = [
            'ok'        => true,
            'run_id'    => (int) $run['run_id'],
            'phase'     => $run['phase'],
            'terminal'  => $run['terminal'],
            'coverage'  => $run['coverage'],
            'detail'    => $run['detail'],
            'values'    => $run['values_state'],
            // Less the records that left the group (review rv5b-1): the
            // manifest keeps their rows, and the group's reader is shown the
            // group's records only.
            'total'     => max(0, (int) $run['manifest_total'] - $progress['departed']),
            // EXAMINED, and only examined. This was every terminal state summed,
            // so a run in which every record failed to store read "12 of 12
            // records (100%)" above a report with nothing in it. What was not
            // examined is said separately, and `settled` - the sum - is what
            // moves the bar, so the bar still reaches the end.
            'done'      => $progress['done'],
            'excluded'  => $progress['excluded'],
            'removed'   => $progress['removed'],
            // Whether any record left the group, and not how many (review
            // rvf1-2): a run whose every record moved away was told they had
            // been deleted, and the page needs to know which happened.
            'leftGroup' => $progress['departed'] > 0,
            'settled'   => $progress['settled'],
            'stalled'   => $progress['stalled'],
            'paused'    => $progress['paused'],
            'stopping'  => $progress['stopping'],
            'idleSeconds' => $progress['idleSeconds'],
            'findings'  => $proved ? ScanPromotion::findingsOf($store, $run) : null,
            'detailRows'  => isset($run['detail_rows']) ? max(0, (int) $run['detail_rows']) : 0,
            'detailBytes' => isset($run['detail_bytes']) ? max(0, (int) $run['detail_bytes']) : 0,
            'scope'     => ($run['scope_dag'] === null ? 'project' : 'group'),
            // The day the run's date rules are judged as of (W10BF-3), or
            // null for a run whose rules read no clock.
            'asOf'      => ScanPlanner::frozenDay($run),
            'active'    => ScanPhase::isActive((string) $run['phase']),
            'mayCancel' => !empty($cancel['ok']),
            'why'       => null,
        ] + self::decisionOf($run);
        return $proved ? $full : ScanAuthorization::preFenceStatus($full);
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
     *             AND taken within the threshold. Scanning and planning take
     *             leases; nothing else does, so only those two can be stalled.
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
     * PLANNING IS READ LIKE SCANNING NOW (B10, review R3U-2). It used to be one
     * synchronous request inside start() that wrote nothing to the run row
     * until the freeze, so CL-3 exempted it, and R2O-3 bounded the exemption by
     * the run's AGE - which caught live planners: a big project still being
     * listed was told it had stopped, and the Stop that sentence asked for
     * ended it under the request doing the work. Planning is a slice per
     * request now, every page appended moves `progress_at`, and claimPlan()
     * takes the same lease claim() does. So a planning run is stalled, paused
     * or neither by the same rule as a scanning one, on the same clock.
     *
     * THERE IS NO "PLANNING STOPPED" READING ANY MORE. R2O-3 added one for a
     * planner that had listed nothing for 15 minutes, with the advice to press
     * Stop and start again, because a list that died part way could not be
     * resumed. Since B10 the cursor and the carry are committed with every
     * page, so Continue carries on listing from where it stopped, and that
     * advice - with Continue hidden beside it - threw away a resumable list.
     * A quiet planning run is paused or stalled, and says so.
     *
     * @param array $run    a ScanStore::run() row, plus `lease_expires_at` when
     *                      the caller has it; absent or unreadable is no lease
     * @param array $states ScanStore::recordStates(): state => count
     * @param int   $now    unix time
     * @return array{done:int, excluded:int, removed:int, settled:int, stalled:bool,
     *               paused:bool, stopping:bool, idleSeconds:int}
     */
    public static function progressOf(array $run, array $states, $now, $stallSeconds = self::STALL_SECONDS)
    {
        $n = function ($state) use ($states) {
            return isset($states[$state]) ? max(0, (int) $states[$state]) : 0;
        };
        $done = $n(ScanStore::REC_DONE);
        $excluded = 0;
        foreach (ScanStore::NOT_EXAMINED as $state) $excluded += $n($state);
        $removed = $n(ScanStore::REC_TOMBSTONE);
        // Records that left a group-scoped run's group (review rv5b-1). In no
        // figure a reader is shown: statusOf() takes them off the total, and
        // they are not settled, because they are not the run's any more.
        $departed = $n(ScanStore::REC_DEPARTED);

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
        // A planning run's clock is its last append (review R3U-2): progress_at,
        // which every appended page moves, and created_at only until the first
        // page.
        $quiet = $active && !$stopping && $clock !== null && $idle >= $threshold;
        // An unreadable lease is no lease, and an unreadable touch is no touch:
        // both fail toward `paused`, the reading that does not tell anybody to
        // throw the run away.
        $driven = self::workerHolds($run, $now, $threshold);

        return ['done' => $done, 'excluded' => $excluded, 'removed' => $removed,
                'departed' => $departed,
                'settled' => $done + $excluded + $removed,
                'stalled' => $quiet && $driven,
                'paused' => $quiet && !$driven,
                'stopping' => $stopping,
                'idleSeconds' => $idle];
    }

    /**
     * Is a worker there? `stalled` above and cancel() below ask the same
     * question, so it is answered once.
     *
     * The proof is the lease claim() takes, LIVE AND TAKEN WITHIN THE
     * THRESHOLD, in scanning - or the one claimPlan() takes, in planning (B10):
     * each writes the lease and `updated_at` together, a lease alone outlives
     * its request by five minutes, and nothing in any other phase renews one.
     * An unreadable lease or touch is no worker.
     *
     * PLANNING COUNTS, AND THAT MATTERS TO Stop AS MUCH AS TO THE PAGE. A
     * planner mid-slice is a worker: Stop leaves its run `cancelling`, and the
     * planner's next append is refused and finishes the stop itself (review
     * R3U-2), instead of Stop ending the run under a request still listing it.
     */
    private static function workerHolds(array $run, $now, $threshold)
    {
        $lease = self::clock($run, 'lease_expires_at');
        $touched = self::clock($run, 'updated_at');
        return isset($run['phase'])
               && ($run['phase'] === ScanPhase::SCANNING || $run['phase'] === ScanPhase::PLANNING)
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
        // The reader before the run (L8): see readerScope().
        // Asked of the host instruments, as mayCancel() asks (formsOf()).
        $no = $this->readerRefusal($pid, 'cancel');
        if ($no !== null) return ['ok' => false, 'why' => $no['why']];
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

    // -- the report (B5) -------------------------------------------------------

    /** Findings one scan-findings page answers when it is not told, and at most. */
    const FINDINGS_PAGE = 50;
    const FINDINGS_PAGE_MAX = 100;

    /**
     * Store calls one findings page may make while it has found no row
     * (review rv11a2-6): 10 x FILTER_WINDOWS x FILTER_WINDOW is 200,000
     * entries of the first filter's key, at most 80 bounded statements.
     */
    const FINDINGS_EMPTY_WALK = 10;

    /**
     * A scan-findings filter name => the findings column it narrows. The four
     * axes ScanStore::findings() takes (FindingRowMapper::PAGE_AXES), under
     * the names a page sends.
     */
    const FINDINGS_FILTERS = ['form' => 'host_form', 'reason' => 'reason_code',
                              'type' => 'check_type', 'group' => 'dag_key'];

    /** RollupBuilder's summary kind for each filter: what the page may offer in it. */
    const FINDINGS_FACETS = ['form' => RollupBuilder::K_FORM, 'reason' => RollupBuilder::K_REASON,
                             'type' => RollupBuilder::K_TYPE, 'group' => RollupBuilder::K_DAG];

    /**
     * One keyset page of a finished run's findings, as the `scan-findings`
     * action answers it (B5).
     *
     * @param array $req {
     *   after:  ?int     the `next` of the page before; absent or 0 for the first
     *   limit:  ?int     rows wanted, 1..FINDINGS_PAGE_MAX, FINDINGS_PAGE when absent
     *   filter: ?array   form, reason, type, group: one stored value each, '' for none
     * }
     * @return array see the wave 11 part A report for the shape; every refusal
     *         is ['ok' => false, 'why' => string, 'final' => bool]
     */
    public function findings($pid, $runId, array $req = [])
    {
        try {
            return $this->findingsPage($pid, $runId, $req);
        } catch (ScanStoreUnavailable $e) {
            return ['ok' => false, 'why' => $this->storageFailed($pid, $runId, $e), 'final' => false];
        }
    }

    /** @see findings() — the body. */
    private function findingsPage($pid, $runId, array $req)
    {
        $r = $this->reportOf($pid, $runId);
        if (empty($r['ok'])) return $r['answer'];

        $given = (isset($req['filter']) && is_array($req['filter'])) ? $req['filter'] : [];
        $filter = [];
        $applied = [];
        foreach (self::FINDINGS_FILTERS as $name => $col) {
            $v = array_key_exists($name, $given) ? $given[$name] : null;
            if ($v === null || $v === '') { $applied[$name] = ''; continue; }
            if (!is_string($v) && !is_int($v)) {
                return ['ok' => false, 'why' => 'a findings filter takes one value', 'final' => true];
            }
            $filter[$col] = $applied[$name] = (string) $v;
        }
        $after = (isset($req['after']) && is_scalar($req['after'])) ? max(0, (int) $req['after']) : 0;
        $limit = (isset($req['limit']) && is_scalar($req['limit'])) ? (int) $req['limit'] : self::FINDINGS_PAGE;
        $limit = max(1, min(self::FINDINGS_PAGE_MAX, $limit));

        $run = $r['run'];
        // `runScope`: what the run covered (review rv11a1-1), so the page can
        // say a coverage sentence is about one group, as the file now does.
        $out = ['ok' => true, 'run_id' => (int) $run['run_id'], 'withheld' => false, 'withheldWhy' => null,
                'runScope' => self::runScope($run),
                'columns' => $r['columns'], 'after' => $after, 'limit' => $limit, 'filter' => $applied,
                'report' => $r['report']->runFacts($r['expected'], $r['emptied']),
                'marks' => self::incompleteMarks($r['shown'], $r['emptied'])]
             + self::decisionOf($run);
        // A RUN THAT HAS NOT PROVED ITS GROUP SHOWS NO ROWS (H10 part 1), as its
        // status shows no counts: a row is a count of one. No facets either.
        if ($r['withheld'] !== null) return self::withheldAnswer($out, $r['withheld']);
        // AN EMPTY PAGE IS THE END, WITHIN A STATED BOUND (review rv11a2-6).
        // A page of two filters walks the first one's key in windows and may
        // come back short with `next` set (ScanStore::findings()): with 20,003
        // findings on one form and the only 3 of reason `rare` last, page 1
        // held no rows and next=20000, while the facet said `rare: 3`. An
        // empty answer asks again from where the walk stopped, so a page with
        // no rows and a `next` means FINDINGS_EMPTY_WALK store calls read that
        // many windows without a match.
        $store = $this->store();
        $from = $after;
        for ($call = 1; ; $call++) {
            $through = null;
            $rows = $store->findings($pid, (int) $run['generation_id'], $r['scope'], $filter,
                                     $from, $limit, $through);
            if ($rows || $through === null || $call >= self::FINDINGS_EMPTY_WALK) break;
            $from = $through;
        }
        $out['rows'] = $r['report']->present($rows);
        $out['next'] = $through;
        $out['facets'] = $this->facetsOf($r['aggregates'], $r['dims'], $r['scope']);
        return $out;
    }

    /**
     * The findings answer of a run that has not proved its group: what
     * ScanAuthorization::preFenceStatus() keeps of its status, and no more
     * (review rv11a1-3).
     *
     * A KEEP-LIST, for preFenceStatus()'s reason. status() withheld `detail`,
     * `ruleProblems` and `notExamined` (rv5b-6: whether the run kept every
     * finding is a statement about how many it made), and this answer, for the
     * same reader and the same run, handed out all three - in `report.detail`,
     * in the coverage sentence's "Some findings were not kept", in a
     * `_TRUNCATED` suffix and as two counts. reportOf() builds the report and
     * the marks of such a run with its detail unsaid, and this drops the rest.
     */
    private static function withheldAnswer(array $out, $why)
    {
        $keep = ['ok', 'run_id', 'runScope', 'columns', 'after', 'limit', 'filter', 'report', 'marks', 'clean'];
        $reportKeeps = ['schema', 'columns', 'legend', 'coverage', 'coverageSentence', 'mayCertify', 'suffix',
                        'valuesState', 'labelsDegraded', 'degradedSummary', 'expectedRows'];
        $kept = [];
        foreach ($keep as $k) if (array_key_exists($k, $out)) $kept[$k] = $out[$k];
        $report = [];
        foreach ($reportKeeps as $k) if (array_key_exists($k, $out['report'])) $report[$k] = $out['report'][$k];
        $kept['report'] = $report;
        return $kept + ['withheld' => true, 'withheldWhy' => $why, 'rows' => [], 'next' => null, 'facets' => null];
    }

    /**
     * Everything a stored run's report is built from, for THIS reader, or the
     * refusal: the gate that the findings page and the export share.
     *
     * THE ORDER IS THE GATE (L8, rv5b-4): the reader before the run row, then
     * the run, then the reader's entitlement to THIS run - instruments, scope,
     * and the other projects it looked values up in - and only then anything
     * read from it. A run outside the reader's group, a whole-project run
     * read by a group reader and an id that is no run all answer NO_RUN.
     *
     * THE LABELS ARE THE RUN'S (M5): its snapshot, or, for a run planned
     * before snapshots were kept, today's, marked degraded so the report says
     * so. THE IDENTIFIER FLAGS ARE BOTH: a field flagged in the snapshot or in
     * the dictionary now is withheld, because a flag added since the run is a
     * decision about who may see that field today. THE VALUE CEILING is the
     * reader's export rights floored by the project's value policy now, and
     * the record names come from the module's own posture (W7-R2).
     *
     * @return array{ok:bool, answer?:array, run?:array, shown?:array, report?:ScanReport,
     *               dims?:ScanDimensions, scope?:array, withheld?:?string,
     *               columns?:array, expected?:?int, aggregates?:array,
     *               emptied?:?array}
     */
    private function reportOf($pid, $runId)
    {
        $no = function ($why, $final) { return ['ok' => false, 'answer' => ['ok' => false, 'why' => $why, 'final' => $final]]; };
        $off = $this->readGate($pid);
        if ($off !== null) return ['ok' => false, 'answer' => $off];
        $refused = $this->readerRefusal($pid);
        if ($refused !== null) return ['ok' => false, 'answer' => $refused];
        $store = $this->store();
        $run = $store->run($pid, $runId);
        if ($run === null) return $no(self::NO_RUN, true);
        $ent = $this->entitlement($pid, $run);
        if (empty($ent['ok'])) return $no($ent['why'], !empty($ent['final']));
        $auth = ScanAuthorization::mayRead($ent['rights'], $ent['forms'], $run['scope_dag'], $ent['unknown']);
        if (empty($auth['ok'])) return $no($auth['why'], true);
        $cross = $this->crossRefusal($pid, $run);
        if ($cross !== null) return ['ok' => false, 'answer' => $cross];
        if ($run['terminal'] === null || $run['terminal'] === '') {
            return $no('this scan has not finished, so it has no report yet', false);
        }
        $reader = $this->readerScope($pid);
        // A REPORT A LATER RUN HAS REPLACED IS NOT SHOWN (reviews rv11a1-5,
        // rv11a2-1). A later whole-project run that completes closes this
        // run's findings a page at a time (supersedePrevious()), and the
        // report read nothing but active rows: in between it was a finished
        // report missing rows, and after it an empty one, with `expected_rows`
        // and the coverage sentence of the whole run above `rows=0`. Asked
        // after the reader's entitlement, so no run a reader may not touch
        // answers anything but NO_RUN; and a group reader is not told the id
        // of a whole-project run, which they may not read.
        $by = $store->supersededBy($pid, (int) $run['run_id']);
        if ($by !== null) {
            return $no($reader['dag'] === null ? sprintf(self::SUPERSEDED_BY, $by) : self::SUPERSEDED, true);
        }

        // The reader's group as a finding stores it: its unique name, which is
        // what readerScope() resolved the group id to. A group reader whose
        // group has no name was refused there.
        $scope = ['dag' => $reader['dag'] === null ? null : (string) $reader['dagName']];
        $withheld = null;
        // `shown`: the run as its report may describe it. A run that has not
        // proved its group does not say whether it kept every finding
        // (review rv11a1-3, see withheldAnswer()), so its detail is unsaid in
        // the coverage sentence, the suffix and the terminal row.
        $shown = $run;
        if (!ScanAuthorization::scopeProved($run)) {
            $withheld = ScanAuthorization::preFenceStatus(['terminal' => $run['terminal']])['withheldWhy'];
            $shown['detail'] = null;
        }
        // A GROUP'S REPORT IS SHOWN WHILE IT STILL DESCRIBES THE GROUP (review
        // rv11a1-2, H10 part 2). A record moved out of the group after the
        // run's fence stayed on its page and in its file, id and kept value
        // included, for a group that can no longer open it. Asked for a group
        // reader only: anyone else may open every group's records. A withheld
        // run shows no rows to drift.
        if ($withheld === null && $reader['dag'] !== null) {
            $drift = $this->scopeDrift($pid, $store, $run);
            if (empty($drift['ok'])) return $no($drift['why'], !empty($drift['final']));
        }

        $ctx = $ent['ctx'];
        $plan = (isset($ctx['plan']) && is_array($ctx['plan'])) ? $ctx['plan'] : [];
        $dims = $this->runDimensions($pid, (int) $run['generation_id'], $plan);
        $live = array_key_exists('identifiers', $plan) ? $plan['identifiers'] : null;
        $ids = (is_array($dims->identifiers) && is_array($live)) ? $dims->identifiers + $live : null;
        $posture = is_callable([$this->module, 'scanRecordIdPosture'])
            ? $this->module->scanRecordIdPosture($pid) : null;
        if (!is_array($posture)) {
            return $no('this installation cannot say how a report names records, so the report is not shown', true);
        }
        $mapper = new FindingRowMapper([
            'pid'           => (int) $pid,
            'now'           => gmdate('Y-m-d H:i:s'),
            'valuesState'   => $run['values_state'],
            'readerCeiling' => ScanPolicy::floor($reader['valueCeiling'], $ent['policy']['valueMode']),
            'identifiers'   => $ids,
        ] + $posture);
        $report = new ScanReport($mapper, $dims, $shown);
        $columns = [];
        foreach (ScanColumns::all($dims) as $c) $columns[] = ['key' => $c['key'], 'label' => $c['label']];

        // What the summary counted, for the facets and the expected count. A
        // run that ended without a verdict may have no summary, and its count
        // is then unknown rather than zero.
        $aggregates = ($withheld === null) ? $store->aggregates((int) $run['run_id']) : [];
        $expected = ($withheld === null && !in_array($run['terminal'], ScanOutcome::NO_VERDICT, true))
            ? self::expectedRows($aggregates, $scope) : null;
        // HOW AN EMPTY SCOPE EMPTIED (review rvf1-2, deferred to here). The
        // report's coverage sentence and its terminal row said every record
        // was deleted for a group whose records all moved to another group;
        // the census says which, and is read only for the one coverage whose
        // sentence depends on it. status() reads the same census every poll.
        $emptied = null;
        if ((string) $run['coverage'] === ScanOutcome::EMPTY_SCOPE) {
            $p = self::progressOf($run, $store->recordStates((int) $run['run_id']), time());
            $emptied = ['departed' => $p['departed'], 'removed' => $p['removed']];
        }
        return ['ok' => true, 'run' => $run, 'shown' => $shown, 'report' => $report, 'dims' => $dims,
                'scope' => $scope, 'withheld' => $withheld, 'columns' => $columns, 'expected' => $expected,
                'aggregates' => $aggregates, 'emptied' => $emptied];
    }

    /** Has a group run's group changed since its fence (ScopeDrift, review rv11a1-2)? */
    private function scopeDrift($pid, ScanStore $store, array $run)
    {
        return ScopeDrift::check($this->fence($pid), $store, $run,
            $this->scopeProbe($pid, $run['scope_dag']), $this->existsProbe($pid), $this->recordHasher($pid));
    }

    /**
     * string => string: a record id's raw record hash, as a run's manifest
     * stores it. The store finds a run's record by this hash (W5F-N3), so
     * catch-up and the drift check hash with the one closure.
     */
    private function recordHasher($pid)
    {
        $key = $this->key();
        return function ($id) use ($pid, $key) {
            return Hmac::raw(Hmac::P_RECORD, $pid, (string) $id, $key);
        };
    }

    /**
     * The labels a stored run's report uses: its snapshot (M5), read a page at
     * a time; for a run with none, the project's as they are now, with
     * degraded `snapshot` saying so.
     */
    private function runDimensions($pid, $generationId, array $plan)
    {
        $store = $this->store();
        $rows = [];
        $after = null;
        do {
            $page = $store->dimensions($pid, $generationId, $after);
            foreach ($page as $r) $rows[] = $r;
            $last = $page ? $page[count($page) - 1] : null;
            $after = $last === null ? null : [$last['kind'], $last['key']];
        } while ($after !== null && count($page) === ScanStore::DIM_PAGE);
        $d = ScanDimensions::fromSnapshot($rows);
        if ($d !== null) return $d;
        $d = null;
        try {
            if (is_callable([$this->module, 'scanDimensions'])) {
                $d = $this->module->scanDimensions($pid,
                    (isset($plan['allRules']) && is_array($plan['allRules'])) ? $plan['allRules'] : []);
            }
        } catch (\Throwable $e) {
            $d = null;
        }
        if (!($d instanceof ScanDimensions)) $d = new ScanDimensions();
        $d->rulesBySource = self::ruleOrdinals($plan);
        $d->degraded['snapshot'] = 'this scan was planned before its labels were kept with it, '
            . 'so names are shown as the project has them now';
        return $d;
    }

    /**
     * What each filter may be set to, from the run's summary: value, label
     * and count. A group reader's facets are their run's, which is their
     * group's; a finding with no group has no group value to filter on.
     */
    private function facetsOf(array $aggregates, ScanDimensions $dims, array $scope)
    {
        $dag = $scope['dag'];
        $out = [];
        foreach (self::FINDINGS_FACETS as $name => $kind) {
            // value => count, in the order the summary holds the values: one
            // value is several rows when a group run's summary is split by
            // group (RollupBuilder::step()), and one entry here.
            $counts = [];
            foreach ($aggregates as $a) {
                if (!isset($a['kind']) || $a['kind'] !== $kind) continue;
                $v = (string) $a['axis1'];
                if ($v === '') continue;
                // A GROUP READER'S COUNTS ARE THEIR GROUP'S (review rv11a1-4):
                // the group facet's own row, and of every other facet the
                // share filed under their group, which is what their pages
                // hold. A count over every group's rows promised rows the page
                // never returned, and told the reader how many other groups'
                // findings each form held. A summary written before it was
                // split names no group, and counts nothing for them.
                if ($dag !== null) {
                    $mine = $name === 'group' ? $v === $dag
                          : (isset($a['axis2']) && (string) $a['axis2'] === $dag);
                    if (!$mine) continue;
                }
                $counts["\0" . $v] = (isset($counts["\0" . $v]) ? $counts["\0" . $v] : 0) + max(0, (int) $a['cnt']);
            }
            $out[$name] = [];
            foreach ($counts as $k => $c) {
                $v = substr((string) $k, 1);
                $out[$name][] = ['value' => $v, 'label' => $name === 'form' ? $dims->form($v) : $v, 'count' => $c];
            }
        }
        return $out;
    }

    /**
     * README's three ways of saying a report is incomplete: see
     * ScanReport::incompleteMarks(), which makes them. The page and the export
     * take them from here, and the report's own `suffix` from there, so the
     * two cannot disagree (review rv11a2-5).
     *
     * The row ends on the run's coverage sentence, chosen as the panel and
     * runFacts() choose it: $emptied is the same pair runFacts() takes, so a
     * group whose records moved away is not told they were deleted.
     *
     * @param array|null $emptied ['departed' => int, 'removed' => int] from the census, or null
     * @return array{incomplete:bool, certified:bool, banner:?string, row:?string, suffix:string}
     */
    public static function incompleteMarks(array $run, ?array $emptied = null)
    {
        return ScanReport::incompleteMarks($run, $emptied);
    }

    /**
     * How many findings THIS reader's report holds, from the run's summary:
     * every stored one for an unconfined reader, and for a group reader the
     * count the summary filed under their group, because their pages hold out
     * a row stored under another group's name. A count the file can be
     * checked against has to be the count of the file.
     */
    private static function expectedRows(array $aggregates, array $scope)
    {
        if ($scope['dag'] === null) return ScanPromotion::tally($aggregates)['stored'];
        $n = 0;
        foreach ($aggregates as $a) {
            if (isset($a['kind']) && $a['kind'] === RollupBuilder::K_DAG
                    && (string) $a['axis1'] === $scope['dag']) {
                $n += max(0, (int) $a['cnt']);
            }
        }
        return $n;
    }

    // -- the export (B5) -------------------------------------------------------

    /** Findings one export page reads: the most a store page holds. */
    const EXPORT_PAGE = 100;

    /** The Issue cell of the terminal data row of a run that cannot certify (README). */
    const INCOMPLETE_LABEL = 'INCOMPLETE SCAN';

    /** The Issue cell of the data row a file that lost rows carries. */
    const LOST_LABEL = 'INCOMPLETE EXPORT';

    /** The line a file that stopped early ends on, in place of the trailer. */
    const ROWS_LOST = 'ROWS WERE LOST: the report could not be read to the end, so this file is not the whole report';

    /** Why a report a later whole-project run replaced is not shown, to a reader who may read that run. */
    const SUPERSEDED_BY = 'scan %1$d, a later scan of the whole project, has replaced this one, so the '
        . 'findings of this scan are closed and it is no longer shown as a report. Open scan %1$d instead.';

    /** The same, to a group reader, who is not told the id of a run they may not read. */
    const SUPERSEDED = 'a later scan of the whole project has replaced this one, so the findings of this '
        . 'scan are closed and it is no longer shown as a report. Run a new scan to see them as they are now.';

    /**
     * The CSV of a finished run's findings for THIS reader, or why not.
     *
     * THE PAGE'S GATE (reportOf()). A stored run is read on screen or as a
     * file only by a reader with Full Data Set export rights, asked before the
     * run row (readerRefusal(), W11A-N2), so the download needs no question of
     * its own about export rights. A run that has not proved its group is
     * refused rather than handed over as a file with no rows, which would
     * read as the report of a clean run.
     *
     * Nothing is read until the caller runs the stream, and the stream never
     * holds more than one page: the caller sends the headers in between.
     *
     * @return array a refusal ['ok' => false, 'http' => 403|503, 'why' => string],
     *               or ['ok' => true, 'filename' => string,
     *                   'stream' => callable(callable $write, callable $flush): array]
     *               where the stream answers streamFindings()'s array
     */
    public function exportOpen($pid, $runId)
    {
        try {
            $r = $this->reportOf($pid, $runId);
            if (empty($r['ok'])) return self::exportRefusal($r['answer']);
            if ($r['withheld'] !== null) return self::exportRefusal(['why' => $r['withheld'], 'final' => true]);
        } catch (ScanStoreUnavailable $e) {
            return self::exportRefusal(['why' => $this->storageFailed($pid, $runId, $e), 'final' => false]);
        }
        $run = $r['run'];
        $facts = $r['report']->runFacts($r['expected'], $r['emptied']);
        // THE RUN'S SCOPE, NOT THE READER'S (review rv11a1-1). This line was
        // the reader's, so an unconfined reader's file of a clean run over one
        // group said "whole project" above "Every record was checked" and
        // "certified yes": a certificate for groups the run never read.
        $scope = self::runScope($run);
        $group = $scope['kind'] === 'group';
        $head = [
            ['schema', ScanReport::SCHEMA],
            ['project', (string) (int) $pid],
            ['run', (string) (int) $run['run_id']],
            ['generated_at', gmdate('Y-m-d H:i:s') . ' UTC'],
            ['scope', $scope['label']],
            ['coverage', $facts['coverageSentence'] . ($group ? ' ' . sprintf(self::GROUP_ONLY, $scope['label']) : '')],
            ['certified', !$facts['mayCertify'] ? 'no' : ($group ? 'yes, for ' . $scope['label'] . ' only' : 'yes')],
            ['values', $facts['valuesState']],
            ['labels', $facts['labelsDegraded'] ? $facts['degradedSummary']
                : 'as the project was when this scan was planned'],
            ['columns', $facts['legend']],
            ['expected_rows', $facts['expectedRows'] === null ? 'unknown' : (string) $facts['expectedRows']],
        ];
        // README's three marks of a file that cannot certify (incompleteMarks()):
        // the banner and the terminal row go in the file, the suffix in its name.
        $marks = self::incompleteMarks($run, $r['emptied']);
        $filename = sprintf('uv-scan-%d-run%d-%s%s.csv', (int) $pid, (int) $run['run_id'], gmdate('Ymd'),
                            preg_replace('/[^A-Za-z0-9_-]/', '', $marks['suffix']));
        $store = $this->store();
        return ['ok' => true, 'filename' => $filename,
                'stream' => function (callable $write, callable $flush) use ($store, $r, $pid, $runId, $run, $head, $marks) {
                    // A group reader's file is checked for drift again at its
                    // end (review rv11a1-2), as its run is (rv11a2-1).
                    $drift = $r['scope']['dag'] === null ? null : function () use ($store, $pid, $run) {
                        return $this->scopeDrift($pid, $store, $run);
                    };
                    $out = self::streamFindings($store, $r['report'], (int) $pid, (int) $run['generation_id'],
                                                $r['scope'], $head, $marks, $write, $flush,
                                                ['run' => (int) $run['run_id'], 'expected' => $r['expected'],
                                                 'drift' => $drift]);
                    if ($out['error'] instanceof ScanStoreUnavailable) {
                        $this->storageFailed($pid, $runId, $out['error']);
                    }
                    return $out;
                }];
    }

    /** Said after the coverage of a run over one group, in its file: %s is runScope()'s label. */
    const GROUP_ONLY = 'This scan covered %s only, not the whole project.';

    /**
     * What a stored run covered, as its report states it: the run's own
     * scope_dag, resolved to the group's name for prose (review rv11a1-1).
     * A group reader may only read a run of their own group, so for them this
     * is their group; for an unconfined reader it is whichever group the run
     * was confined to, which is what a whole-project heading had hidden.
     *
     * @return array{kind:string, group:?string, label:string} kind 'project'
     *         or 'group'; group the unique name, null when it cannot be read
     */
    private static function runScope(array $run)
    {
        $dag = isset($run['scope_dag']) ? $run['scope_dag'] : null;
        if ($dag === null || $dag === '') return ['kind' => 'project', 'group' => null, 'label' => 'whole project'];
        $name = ScanPageView::dagNameOf($dag);
        return ['kind' => 'group', 'group' => $name, 'label' => $name !== null
            ? 'Data Access Group ' . $name : 'the Data Access Group with id ' . (string) $dag];
    }

    /** A refusal as the export page sends it: 403 when it is the answer, 503 when asking again may help. */
    private static function exportRefusal(array $no)
    {
        return ['ok' => false, 'http' => !empty($no['final']) ? 403 : 503, 'why' => (string) $no['why']];
    }

    /**
     * Write a stored run's report as CSV, a keyset page at a time.
     *
     * NEVER THE WHOLE REPORT IN MEMORY. One store page is read, written line by
     * line and flushed before the next is read, so what a request holds does
     * not grow with the run: a 50,000-finding export holds 100 rows at a time.
     *
     * THE FILE SAYS WHETHER IT IS WHOLE. A UTF-8 byte-order mark (spreadsheets
     * guess the encoding without one), the `# ` lines of $head, the header,
     * the rows, and last a `# export_complete=1` trailer with the row count.
     * A download cut off anywhere lacks the trailer; a store that fails
     * partway writes ROWS_LOST where the trailer would be.
     *
     * AND WHETHER THE RUN WAS (README, item 10). A run that cannot certify the
     * project opens on its banner, above everything else, and ends its rows
     * on a terminal data row, which survives deleting the comment lines and
     * sorting the sheet; the third mark, the filename suffix, is the caller's.
     * A file that lost rows says so in a data row too, for the same reason.
     *
     * AND THE TRAILER IS EARNED AT THE END, NOT ASSUMED (reviews rv11a1-5,
     * rv11a2-1). A file is read a page at a time over minutes, and in that
     * time a later whole-project run can close this run's rows, or retention
     * can begin removing it: each page then read fewer rows, the walk ended
     * early, and the file still closed on `export_complete=1`. With $whole,
     * the run is read again after the last page, and the file earns the
     * trailer only when the run is still there, nothing has replaced it, and
     * it wrote the number of rows its head promised; otherwise it ends as a
     * file that lost rows. A recheck that cannot be read is not a yes.
     *
     * @param array $head  [[key, value], ...] the lines about the report
     * @param array $marks incompleteMarks() of the run: `banner` and `row`, each
     *                     null when the run needs none
     * @param array $whole ['run' => int, 'expected' => ?int]: the run to read
     *                     again at the end and the rows its head promised (null:
     *                     not known, so not compared); [] asks nothing.
     *                     'drift' => ?callable: ScopeDrift asked again at the
     *                     end for a group reader's file (review rv11a1-2)
     * @return array{ok:bool, rows:int, pages:int, error:?\Throwable}
     */
    public static function streamFindings(ScanStore $store, ScanReport $report, $pid, $generationId,
                                          array $scope, array $head, array $marks, callable $write,
                                          callable $flush, array $whole = [])
    {
        $banner = isset($marks['banner']) ? $marks['banner'] : null;
        $terminal = isset($marks['row']) ? $marks['row'] : null;
        $write("\xEF\xBB\xBF");
        if ($banner !== null) $write($report->csvMetaLine($banner, '') . "\r\n");
        foreach ($head as $h) $write($report->csvMetaLine($h[0], $h[1]) . "\r\n");
        $write($report->csvHeaderLine() . "\r\n");
        $flush();
        $rows = 0;
        $pages = 0;
        $after = 0;
        try {
            do {
                $through = null;
                $page = $store->findings($pid, $generationId, $scope, [], $after, self::EXPORT_PAGE, $through);
                foreach ($page as $stored) {
                    $write($report->csvLine($stored) . "\r\n");
                    $rows++;
                }
                $page = null;
                $pages++;
                $flush();
                // A cursor that does not move would write the same page for ever.
                if ($through !== null && (int) $through <= (int) $after) {
                    throw new \RuntimeException('the findings cursor did not move');
                }
                $after = $through;
            } while ($after !== null);
            if (isset($whole['run'])) {
                $runId = (int) $whole['run'];
                $expected = array_key_exists('expected', $whole) ? $whole['expected'] : null;
                if ($store->run($pid, $runId) === null || $store->supersededBy($pid, $runId) !== null) {
                    throw new \RuntimeException('the run was replaced or removed while it was exported');
                }
                if ($expected !== null && $rows !== (int) $expected) {
                    throw new \RuntimeException('the export wrote ' . $rows . ' rows of ' . (int) $expected);
                }
                if (isset($whole['drift']) && empty(call_user_func($whole['drift'])['ok'])) {
                    throw new \RuntimeException('the group changed while it was exported');
                }
            }
        } catch (\Throwable $e) {
            // THE RUN'S OWN ROW TOO (review rv11a2-4). A file that lost rows
            // carried only INCOMPLETE EXPORT, so once its comment lines were
            // deleted nothing in it said the scan itself was stopped or cut.
            if ($terminal !== null) $write($report->csvNoticeLine(self::INCOMPLETE_LABEL, $terminal) . "\r\n");
            $write($report->csvNoticeLine(self::LOST_LABEL, self::ROWS_LOST) . "\r\n");
            $write($report->csvMetaLine(self::ROWS_LOST, 'rows=' . $rows) . "\r\n");
            $flush();
            return ['ok' => false, 'rows' => $rows, 'pages' => $pages, 'error' => $e];
        }
        if ($terminal !== null) $write($report->csvNoticeLine(self::INCOMPLETE_LABEL, $terminal) . "\r\n");
        $write($report->csvMetaLine('export_complete=1', 'rows=' . $rows) . "\r\n");
        $flush();
        return ['ok' => true, 'rows' => $rows, 'pages' => $pages, 'error' => null];
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
            // A RUN ALREADY STOPPING IS STOPPED, NOT FINISHED (review
            // rvfix-N3). cancel() no longer stops a `cancelling` run a second
            // time, so its false no longer means only "finished": a second
            // Stop, or one pressed after a stop a worker held, finds the run
            // stopping. It is finished below as this Stop's own would be,
            // with nothing audited twice.
            $now_ = $store->run($pid, $runId);
            if ($now_ === null || !isset($now_['phase']) || $now_['phase'] !== ScanPhase::CANCELLING) {
                return ['ok' => false, 'stopped' => false,
                        'why' => 'this scan had already finished, so there was nothing to stop'];
            }
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
     * What status() answers when a run holds answers another project would
     * not give this reader, or null when the run may be shown.
     *
     * The lookups come from the run's stored policy (`crossLookups`,
     * ScanPlanner::storedPolicy()), and the module asks the other project
     * about each one for the signed-in reader (crossLookupRefusals()):
     *   - a policy with no list: the run never recorded what it looked up and
     *     never will, so it is refused for good, with the remedy;
     *   - a list that cannot be read, or a module that cannot answer: refused,
     *     and asked again;
     *   - refused: final only when every refused lookup was refused by a
     *     decision (review R3U-1). One that rests on a read that failed is
     *     asked again, because the next ask may show the run.
     *
     * $verb is what the reader asked to do, and only the end of the sentence
     * depends on it: 'read' (status) or 'work' (advanceRun(), review rv5b-5).
     */
    private function crossRefusal($pid, array $run, $verb = 'read')
    {
        $unreadable = ['ok' => false, 'final' => false,
            'why' => 'which other projects this scan looked values up in could not be read, '
                   . self::crossTail($verb, true)];
        $raw = array_key_exists('policy_json', $run) ? $run['policy_json'] : null;
        $policy = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($policy)) return $unreadable;
        if (!array_key_exists('crossLookups', $policy)) {
            return ['ok' => false, 'final' => true,
                'why' => 'this scan did not record which other projects it looks values up in, so who may see '
                       . 'its results cannot be checked; start a new scan'];
        }
        if (!is_array($policy['crossLookups'])) return $unreadable;
        if (!$policy['crossLookups']) return null;
        $lookups = array_values($policy['crossLookups']);
        $key = json_encode([(int) $pid, $lookups, $this->username()]);
        if (!array_key_exists($key, $this->crossAnswers)) {
            try {
                $this->crossAnswers[$key] = is_callable([$this->module, 'crossLookupRefusals'])
                    ? $this->module->crossLookupRefusals((int) $pid, $lookups) : null;
            } catch (\Throwable $e) {
                $this->crossAnswers[$key] = null;
            }
        }
        $refused = $this->crossAnswers[$key];
        if (!is_array($refused)) return $unreadable;
        if (!$refused) return null;
        $decided = [];
        $unread = [];
        foreach ($refused as $r) {
            $b = (is_array($r) && isset($r['pid'])) ? (int) $r['pid'] : 0;
            if (is_array($r) && array_key_exists('unread', $r) && $r['unread'] === false) {
                $decided[$b] = true;
            } else {
                $unread[$b] = true;
            }
        }
        // A decision is as final as an instrument the reader cannot read:
        // polling again asks the same rights the same question.
        return ['ok' => false, 'why' => self::crossWhy(array_keys($decided), array_keys($unread), $verb),
                'final' => !$unread];
    }

    /**
     * The refusal for a reader whose own plan skips a lookup into another
     * project, or null when it skips none (review rv10b1-2).
     *
     * The plan marks such a rule skipped, or such a branch refused, and keeps
     * it in the rule list, so the fingerprint is the same whoever builds it.
     * A work pass by this reader would then examine records without the rule
     * and commit them as checked, beside records another reader checked with
     * it. start() and advanceRun() ask this; crossRefused is the plan's list
     * of the projects that refuse this reader, crossUnread the part of it
     * that rests on a read that failed.
     *
     * A PLAN THAT DOES NOT SAY IS REFUSED. buildScanPlan() sets both lists on
     * every return, so a context without them is not one to evaluate with.
     */
    private static function planCrossRefusal(array $ctx, $verb)
    {
        $plan = (isset($ctx['plan']) && is_array($ctx['plan'])) ? $ctx['plan'] : [];
        if (!isset($plan['crossRefused']) || !is_array($plan['crossRefused'])) {
            return ['ok' => false, 'final' => false,
                'why' => 'which other projects this scan looks values up in could not be read, '
                       . self::crossTail($verb, true)];
        }
        if (!$plan['crossRefused']) return null;
        $unread = (isset($plan['crossUnread']) && is_array($plan['crossUnread']))
                ? array_map('intval', $plan['crossUnread']) : array_map('intval', $plan['crossRefused']);
        $decided = array_diff(array_map('intval', $plan['crossRefused']), $unread);
        return ['ok' => false, 'why' => self::crossWhy($decided, $unread, $verb), 'final' => !$unread];
    }

    /**
     * The sentence for a run another project will not answer this reader's
     * lookups for. The decided projects are named when there are any; a
     * refusal that rests only on failed reads says so and says "yet".
     *
     * @param int[] $decided
     * @param int[] $unread
     */
    private static function crossWhy(array $decided, array $unread, $verb)
    {
        $decided = array_values(array_unique(array_map('intval', $decided)));
        $unread = array_values(array_unique(array_map('intval', $unread)));
        sort($decided);
        sort($unread);
        return $decided
            ? 'this scan looks values up in project ' . implode(', ', $decided)
              . ', which does not answer your lookups, ' . self::crossTail($verb, false)
            : 'this scan looks values up in project ' . implode(', ', $unread)
              . ', and whether it answers your lookups could not be read just now, '
              . self::crossTail($verb, true);
    }

    /** How a cross-project refusal ends, by what the reader asked to do. */
    private static function crossTail($verb, $yet)
    {
        if ($verb === 'start') $t = 'so you cannot start this scan';
        elseif ($verb === 'work') $t = 'so you cannot continue this scan';
        else $t = 'so its results are not shown to you';
        return $yet ? $t . ' yet' : $t;
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
     * The newest finished run of THIS reader's scope, for the findings panel
     * to open on, or null.
     *
     * The panel shows a finished run's report (B5), and a run that finished
     * after its tab was closed had no way back onto the page: activeRun()
     * answers only the run holding the slot. A group reader is offered the
     * newest finished run of their group, an unrestricted reader the newest
     * whole-project run - the scope a Start from this page would have.
     *
     * A LOCATOR, NOT A GRANT. The id is printed into the page for the client
     * to ask scan-findings about, and that verb decides everything again,
     * before the run row (L8): a reader it refuses is refused the same way for
     * this id as for any other. Nothing about the run but its id is read here.
     *
     * ONE ROW, THROUGH ix_project_created, newest first (run_id breaks a tie in
     * the same second; it is the key's own suffix, so there is no sort). A
     * project keeps one row per run, and retention purges old ones.
     *
     * @param array|null $scope ScanPageView::scanScope()'s answer, as the page holds it
     * @return int|null null for no such run, no readable scope, or a read that failed
     */
    public function finishedRun($pid, $scope = null)
    {
        if (!is_array($scope) || empty($scope['ok']) || !array_key_exists('dag', $scope)) return null;
        try {
            $sql = 'SELECT run_id FROM ' . Schema::table('scan_run')
                 . ' WHERE project_id = ? AND phase = ? AND '
                 . ($scope['dag'] === null ? 'scope_dag IS NULL' : 'scope_dag = ?')
                 . ' ORDER BY created_at DESC, run_id DESC LIMIT 1';
            $params = [(int) $pid, ScanPhase::TERMINAL];
            if ($scope['dag'] !== null) $params[] = (string) $scope['dag'];
            $r = $this->db->select($sql, $params);
            return (isset($r[0][0]) && ctype_digit((string) $r[0][0])) ? (int) $r[0][0] : null;
        } catch (\Throwable $e) {
            return null;
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

    /**
     * The wording every unknown run gets, whatever the real reason - and a
     * run outside the reader's group, which must read the same (rv5b-4).
     */
    const NO_RUN = ScanAuthorization::NO_RUN;

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

    /** "<pid>|<user>" => ScanPageView::scanScope() for this request. See readerScope(). */
    private $readers = [];

    /**
     * The reader's rights and scope, read once per request and BEFORE any run
     * row (L8).
     *
     * THE ORDER IS THE ORACLE. Every verb read the run first and asked about
     * the reader after: an id that is no run answered "no scan with that
     * reference", and an id that IS one answered with the reader's refusal -
     * "you need project design rights" - so a person refused the scan could
     * still learn which run ids exist. The reader is decided first now, so a
     * refused reader gets the same sentence whatever id they send.
     */
    private function readerScope($pid)
    {
        $key = (int) $pid . '|' . $this->username();
        if (!array_key_exists($key, $this->readers)) {
            $this->readers[$key] = ScanPageView::scanScope($this->module, $pid);
        }
        return $this->readers[$key];
    }

    /**
     * readerScope()'s refusal, or null for a reader who may go on to ask
     * about a run. FINAL unless the rights could not be READ (review R2O-4),
     * as entitlementNow() says it.
     *
     * $verb is 'cancel' for cancel() and 'read' for every other verb: they
     * are asked about different instrument sets (see formsOf()).
     */
    private function readerRefusal($pid, $verb = 'read')
    {
        $scope = $this->readerScope($pid);
        if (empty($scope['ok'])) {
            return ['ok' => false, 'why' => $scope['why'], 'final' => empty($scope['transient'])];
        }
        // AND WHAT EVERY RUN WOULD SAY OF THIS READER (W11A-N2): the export
        // level is a question about the reader alone, so it is answered before
        // the run row too. Every verb that asks this goes on to mayRead(),
        // mayWork() or mayCancel(), each of which asks the same first.
        $alone = ScanAuthorization::readerAlone($scope['rights']);
        if (empty($alone['ok'])) return ['ok' => false, 'why' => $alone['why'], 'final' => true];
        // AND THE INSTRUMENTS (W11A-N3). The entitlement was built for the run
        // row, so a designer barred from an instrument the rules read was
        // named the barred instruments for an id that is a run and told
        // NO_RUN for one that is not: the run ids of the project could be
        // told apart by somebody the scan refuses. Which instruments the
        // rules read is a fact about the project's rules and dictionary, not
        // about a run - the plan's ownership map does not depend on the group
        // it is built for - so it is decided here, from a planning context
        // for the reader's own scope, before any run row is read. A context
        // that cannot be built is refused here too, the same for every id;
        // it is never final, as entitlementNow() says it.
        $f = $this->readerForms($pid);
        if (empty($f['ok'])) return ['ok' => false, 'why' => $f['why'], 'final' => false];
        $auth = ($verb === 'cancel')
            ? ScanAuthorization::mayStart($scope['rights'], $f['cancelForms'])
            : ScanAuthorization::mayStart($scope['rights'], $f['forms'], $f['unknown']);
        if (empty($auth['ok'])) return ['ok' => false, 'why' => $auth['why'], 'final' => true];
        return null;
    }

    /** "<pid>|<user>" => readerForms()'s answer for this request. */
    private $readerForms = [];

    /**
     * The instruments this reader's rules read, from a planning context built
     * for the reader's own scope, once per request.
     *
     * THE SAME PLAN AS THE RUN'S, NOT A SECOND ONE. The plan memo is keyed by
     * the project, the group, the value ceiling and the user, and not by the
     * generation, so for a group reader - whose only readable runs are their
     * group's - and for an unrestricted reader of a whole-project run, the
     * entitlement built after the run row is a memo hit on this one.
     *
     * @return array{ok:bool, why?:?string, forms?:string[], unknown?:string[], cancelForms?:string[]}
     */
    private function readerForms($pid)
    {
        $key = (int) $pid . '|' . $this->username();
        if (!array_key_exists($key, $this->readerForms)) {
            $scope = $this->readerScope($pid);
            $policy = $this->policy($pid);
            $ctx = $this->module->durableScanContext($pid, $this->planOptions($pid, [
                'valueCeiling' => $policy['valueMode'], 'generation' => null, 'policy' => $policy,
            ]), $scope['dag']);
            // contextRefusal(), as entitlementNow() says it: a context refused
            // on a read that failed names what could not be read (W10BF-4).
            $this->readerForms[$key] = empty($ctx['ok'])
                ? ['ok' => false, 'why' => self::contextRefusal($ctx)]
                : ['ok' => true, 'why' => null] + self::formsOf($ctx);
        }
        return $this->readerForms[$key];
    }

    /** @see entitlement() — the answer, worked out. */
    private function entitlementNow($pid, array $run)
    {
        $scope = $this->readerScope($pid);
        if (empty($scope['ok'])) {
            // FINAL unless the rights could not be READ (review R2O-4): a
            // refusal on what they say is a decision, and one on a read that
            // threw is a moment - see status().
            return ['ok' => false, 'why' => $scope['why'], 'final' => empty($scope['transient'])];
        }
        // A RUN OUTSIDE THE READER'S GROUP IS NO RUN, BEFORE ANYTHING IS BUILT
        // FOR IT (review rv5b-4). The context below is built with the run's
        // own group, and a build that fails answers with its own sentence -
        // so a group user asking about another group's run could learn that
        // it exists from the way the refusal read. Refused here, as a final
        // answer, in the words an id that is not a run gets. Every verb asks
        // the same scope question after this; it just cannot be reached first.
        $touch = ScanAuthorization::mayTouchScope($scope['rights'], $run['scope_dag']);
        if (empty($touch['ok'])) return ['ok' => false, 'why' => $touch['why'], 'final' => true];
        $policy = $this->policy($pid);
        // The run is in hand, so the evaluator is bound to THIS run's
        // generation and sequence - the numbers its findings are written under
        // and the interval they open.
        $ctx = $this->module->durableScanContext($pid, $this->planOptions($pid, [
            'valueCeiling' => $policy['valueMode'],
            'generation'   => (int) $run['generation_id'],
            'runSeq'       => (int) $run['run_seq'],
            'policy'       => $policy,
            // The clock the run froze at planning (W10BF-3), so a record
            // judged after midnight is judged as of the same day.
            'asOf'         => ScanPlanner::frozenClock($run),
        ]), $run['scope_dag']);
        // Never final: the commonest reason is a rule list or a dictionary that
        // could not be read just now.
        if (empty($ctx['ok'])) return ['ok' => false, 'why' => self::contextRefusal($ctx), 'final' => false];

        return ['ok' => true, 'why' => null, 'rights' => $scope['rights']] + self::formsOf($ctx) + [
                // The CALLER's scope, kept beside the run's own. The two are
                // equal for any request the authorisation above let through -
                // they are compared as ids by mayWork() - and keeping both is
                // what let the seam be asserted rather than assumed.
                'policy' => $policy, 'ctx' => $ctx, 'dag' => $scope['dag']];
    }

    /**
     * The instruments a planning context reads, as the entitlement asks about
     * them: `forms`, `unknown` (fields no instrument could be found for) and
     * `cancelForms`. ONE DERIVATION for start(), the pre-run question
     * (readerForms(), W11A-N3) and the run's entitlement, so the three cannot
     * be asked about different sets.
     *
     * @return array{forms:string[], unknown:string[], cancelForms:string[]}
     */
    private static function formsOf(array $ctx)
    {
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
        // so openRun() answers busy for everyone on that project. Short of
        // switching the module off, the paths that release a slot are finish()
        // from a worker pass (gated by mayWork), reapCancelled() (needs phase
        // `cancelling`, which needs a successful cancel), and
        // ScanStore::expireAbandoned(), which the reap cron
        // (ScanMaintenance::reap()) and openRun() run only once the run has
        // gone staleHours without progress. Until then the whole project
        // would answer busy, and nobody could stop the run.
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
        return ['forms' => array_keys($forms), 'unknown' => $unknown, 'cancelForms' => array_keys($cancelForms)];
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
            // A LOG THAT COULD NOT BE READ IS NOT A PROJECT WITHOUT ONE (H11).
            // Null here builds a worker with no fence, and a worker with no
            // fence takes every record it reads as having held still - so one
            // failed read of the log let a whole request's records through
            // unproved. The request stops instead, as for any storage failure,
            // and the next one asks again; nothing is remembered.
            if (!empty($f['unreadable'])) {
                throw new ScanStoreUnavailable('the project change log could not be read');
            }
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

    /**
     * "Is each record in this run's group", as a callable: true, false, or
     * null for cannot tell (RecordManifestSource::scopeOf, review rv5b-1).
     *
     * Catch-up asks it of the change log's records, and the worker of each
     * batch before it reads one. With no source an unscoped run admits
     * everything and a scoped one can tell nothing: no group can be
     * established, and cannot tell neither admits a record nor takes one out.
     */
    private function scopeProbe($pid, $dag)
    {
        $source = $this->openSource($pid);
        return function (array $ids) use ($source, $dag) {
            $ids = array_map('strval', $ids);
            if ($source === null) return array_fill_keys($ids, $dag === null ? true : null);
            return $source->scopeOf($ids, $dag);
        };
    }

    private function catchUp($pid, ScanStore $store, $dag)
    {
        $source = $this->openSource($pid);
        return new CatchUp($store, [
            'fence'  => $this->fence($pid),
            'hash'   => $this->recordHasher($pid),
            'exists' => $this->existsProbe($pid),
            'scope'  => $this->scopeProbe($pid, $dag),
            'dag'    => function (array $ids) use ($source) {
                return $source === null ? array_fill_keys($ids, null) : $source->dagsOf($ids);
            },
        ]);
    }

    private function finalizer($pid, array $ctx, ScanStore $store, $runId = null, $scoped = false)
    {
        $read = $ctx['read'];
        $plan = (isset($ctx['plan']) && is_array($ctx['plan'])) ? $ctx['plan'] : [];
        // THE GROUPS THIS REQUEST HAS ALREADY READ (W7-R4). A duplicate's row
        // carries its record's Data Access Group, and a group is verified (its
        // records re-read through $read, which exports the group) just before
        // it is emitted, usually in the same request. Each read leaves its
        // records' groups here, so emitting the page after it reads nothing.
        $seen = new \ArrayObject();
        return new UniqueFinalizer($this->db, [
            'pid'      => $pid,
            // The store its emit and publish transactions hold the run row
            // through (review rv10a1-1): the worker's own, as RollupBuilder
            // is given it. Unless a test gave its own store, that store is
            // built over $this->db, so the hold and the writes are one
            // transaction on one connection.
            'store'    => $store,
            'hmacKey'  => $this->key(),
            'versions' => $this->fence($pid),
            // Which candidates' records left the run's group (review rv5b-1).
            // Their candidates are dropped from the group rather than re-read.
            // A GROUP-SCOPED RUN'S ONLY (review rvf1-4): a run over the whole
            // project has no group to leave, and the question is a
            // scan_record read on every verify page, about 250,000 reads on
            // 500,000 records in pairs.
            'departed' => (!$scoped || $store === null || $runId === null) ? null
                : function (array $hashes) use ($store, $runId) {
                    return $store->departedOf((int) $runId, $hashes);
                },
            // Re-reading a duplicate group goes through the SAME read the worker
            // uses, so a group is verified against the values the scan would
            // have seen rather than a second, differently-shaped export.
            'read'     => function (array $locs) use ($read, $plan, $seen) {
                $ids = [];
                foreach ($locs as $l) $ids[(string) $l['record']] = true;
                $got = $read(array_keys($ids));
                self::noteDags($got, $seen);
                return self::rereadValues($got, $locs, $plan);
            },
            'ordinals' => self::ruleOrdinals($plan),
            'dags'     => function (array $ids) use ($read, $seen) {
                return self::dagsFor($ids, $read, $seen);
            },
        ]);
    }

    /**
     * rule_source_id => the ordinal the plan gives that rule (W7-R4): the
     * number durableEvaluateRecord() writes as rule_ord, which is its key in
     * $plan['ruleIds'] plus one. Where two rules share a source id the lower
     * ordinal is kept, so the answer does not depend on the order of a loop.
     *
     * @return array<string, int>
     */
    public static function ruleOrdinals(array $plan)
    {
        $out = [];
        $ids = (isset($plan['ruleIds']) && is_array($plan['ruleIds'])) ? $plan['ruleIds'] : [];
        foreach ($ids as $i => $id) {
            if (!is_int($i) || !is_array($id) || !isset($id['source_id'])) continue;
            $sid = (string) $id['source_id'];
            if (!isset($out[$sid]) || $out[$sid] > $i + 1) $out[$sid] = $i + 1;
        }
        return $out;
    }

    /**
     * Each record's Data Access Group, from what this request has read, and
     * one read of the rest. The finalizer's `dags` dependency.
     *
     * A failed read is ok:false, never "no group". A record the read did not
     * return - deleted since it was verified - has none, which is what it has.
     *
     * @return array{ok:bool, dags:array<string, ?string>, read:int, why:?string}
     */
    public static function dagsFor(array $ids, callable $read, \ArrayObject $seen)
    {
        $miss = [];
        foreach ($ids as $id) {
            if (!$seen->offsetExists((string) $id)) $miss[(string) $id] = true;
        }
        if ($miss) {
            $got = $read(array_keys($miss));
            if (!is_array($got) || empty($got['ok']) || !isset($got['data']) || !is_array($got['data'])) {
                return ['ok' => false, 'dags' => [], 'read' => count($miss),
                        'why' => is_array($got) && isset($got['why']) ? $got['why'] : null];
            }
            self::noteDags($got, $seen);
            foreach (array_keys($miss) as $id) {
                if (!$seen->offsetExists((string) $id)) $seen[(string) $id] = null;
            }
        }
        $out = [];
        foreach ($ids as $id) $out[(string) $id] = $seen[(string) $id];
        return ['ok' => true, 'dags' => $out, 'read' => count($miss), 'why' => null];
    }

    /** Remember the group of every record a successful read returned. */
    private static function noteDags($got, \ArrayObject $seen)
    {
        if (!is_array($got) || empty($got['ok']) || !isset($got['data']) || !is_array($got['data'])) return;
        foreach ($got['data'] as $id => $node) {
            if (is_array($node)) $seen[(string) $id] = self::dagOfNode($node);
        }
    }

    /**
     * The exported Data Access Group unique name of one getData record node,
     * or null: the first non-empty `redcap_data_access_group` on any of its
     * rows, in the node's own order (getData puts the event rows before
     * repeat_instances). The rule
     * UniversalValidator::dagOfRecordNode() applies to the same node when it
     * writes every other finding's dag_key; tests/scan_service_php.php holds
     * the two equal.
     */
    public static function dagOfNode(array $node)
    {
        $rows = [];
        foreach ($node as $k => $n) {
            if ($k === 'repeat_instances') {
                if (!is_array($n)) continue;
                foreach ($n as $byInstr) {
                    if (!is_array($byInstr)) continue;
                    foreach ($byInstr as $byInst) {
                        if (!is_array($byInst)) continue;
                        foreach ($byInst as $row) {
                            if (is_array($row)) $rows[] = $row;
                        }
                    }
                }
            } elseif (is_array($n)) {
                $rows[] = $n;
            }
        }
        foreach ($rows as $row) {
            if (isset($row['redcap_data_access_group']) && !is_array($row['redcap_data_access_group'])
                && $row['redcap_data_access_group'] !== '') {
                return (string) $row['redcap_data_access_group'];
            }
        }
        return null;
    }

    /**
     * The finalizer's reader: each candidate's value out of one read of its
     * records ($got, as the worker's read returns it), in the form it is
     * compared in (comparableValue), keyed by UniqueFinalizer::locKey. A
     * value that is not there is left out, which the finalizer blocks on.
     */
    public static function rereadValues($got, array $locs, array $plan)
    {
        if (!is_array($got) || empty($got['ok'])) {
            return ['ok' => false, 'values' => [], 'why' => is_array($got) && isset($got['why']) ? $got['why'] : null];
        }
        $out = [];
        foreach ($locs as $l) {
            $v = self::valueAt($got['data'], $l);
            if ($v === null) continue;
            $out[UniqueFinalizer::locKey($l)] = [self::comparableValue($v, (string) $l['field'], $plan)];
        }
        return ['ok' => true, 'values' => $out, 'why' => null];
    }

    /**
     * How the finalizer compares a re-read @UVUNIQUE value: its
     * Logic::lookupKey, the form the scan grouped the candidates by. Letter
     * case is ignored unless every unique rule on the field keeps it
     * ($plan['uniqueExact']), and a field that holds numbers compares them by
     * value ($plan['numberMarks']). The raw text blocked every group the
     * comparison had joined: "SP-1" with "sp-1", "007" with "7".
     *
     * Where one rule on the field ignores case and another keeps it, the
     * values are compared folded: two values the scan grouped under either
     * rule are then equal here. A value edited since it was grouped is caught
     * by the version fence before this comparison.
     */
    public static function comparableValue($v, $field, array $plan)
    {
        $marks = (isset($plan['numberMarks']) && is_array($plan['numberMarks'])) ? $plan['numberMarks'] : [];
        $exact = (isset($plan['uniqueExact']) && is_array($plan['uniqueExact'])) ? $plan['uniqueExact'] : [];
        return \INSPIRE\UniversalValidator\Logic::lookupKey($v, empty($exact[$field]), isset($marks[$field]) ? $marks[$field] : null);
    }

    /**
     * One value out of a getData array, or null when it is not there.
     *
     * A value that is not where we looked is reported ABSENT rather than empty,
     * because the finalizer blocks on absent and would confirm on empty - and
     * confirming a duplicate nobody read is the one outcome that class exists to
     * prevent.
     *
     * The location's form ($loc['form'], the candidate's host_form) decides
     * where the value is: a form that repeats in this record's event keeps
     * EVERY instance, the first included, in repeat_instances, and a repeating
     * event keeps them under ''. Its base row carries the field blank, so
     * reading instance 1 there compared a blank with the grouped value and
     * blocked the group as a hash collision. A location with no form is read
     * as before.
     */
    private static function valueAt(array $data, array $loc)
    {
        $rec = isset($data[(string) $loc['record']]) ? $data[(string) $loc['record']] : null;
        if (!is_array($rec)) return null;
        $ev = $loc['event_id'];
        $inst = isset($loc['instance']) ? (int) $loc['instance'] : 1;
        $form = (isset($loc['form']) && is_string($loc['form']) && $loc['form'] !== '') ? $loc['form'] : null;

        if ($form !== null) {
            $ri = (isset($rec['repeat_instances'][$ev]) && is_array($rec['repeat_instances'][$ev]))
                ? $rec['repeat_instances'][$ev] : [];
            foreach ([$form, ''] as $bucket) {
                if (!isset($ri[$bucket]) || !is_array($ri[$bucket])) continue;
                $row = isset($ri[$bucket][$inst]) ? $ri[$bucket][$inst] : null;
                return (is_array($row) && array_key_exists($loc['field'], $row)) ? $row[$loc['field']] : null;
            }
            if ($inst > 1) return null;
            $node = isset($rec[$ev]) ? $rec[$ev] : null;
            return (is_array($node) && array_key_exists($loc['field'], $node)) ? $node[$loc['field']] : null;
        }

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

    /**
     * The effective policy: the system maximum, then whatever the project asked for.
     *
     * PLUS THE RECORD-ID POSTURE (W10BF-2). "log-values" = none is the mode
     * for a site whose record ids are themselves identifying, and the legacy
     * report hashes its ids under it (UniversalValidator::scanPlan()). This
     * policy never carried it, so ScanPolicy::tightened()'s hashRecordIds
     * branch could not fire: a project switched to "none" while a run was
     * working was not a tightening, and the run went on under the posture it
     * had begun with. Resolved here, as the legacy plan resolves it, and
     * frozen into policy_json with the rest. Not in ScanPolicy::resolve(),
     * whose other caller (the maintenance pass) does not read the setting
     * and would then state a posture it never asked about. A read that fails
     * is not "none", which is the legacy answer too (logMode()); a run frozen
     * that way and read as "none" later is a tightening, and stops.
     */
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
        $policy = ScanPolicy::resolve($sys, $prj);
        $policy['hashRecordIds'] = ($this->setting('log-values', $pid) === 'none');
        return $policy;
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
     * R3G-4), OR THE FORM A STORED FINDING OR SUMMARY IS READ IN (rv11a2-9).
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
     *   engine-2  @UVEXISTS and @UVUNIQUE ignore letter case unless the rule is
     *             caseSensitive, and compare numbers by value
     *   engine-3  @UVRANGE takes several references on one field, ignores the
     *             letter case of sex codes, and judges a value with no z-score
     *             by its "plausible" range
     *   engine-4  the started-form gate and its round-3 corrections (built as
     *             engine-2 on the scan branch, renumbered when it joined main,
     *             where engine-2 and engine-3 had already shipped)
     *   engine-5  a duplicate the scan's finalizer writes carries its rule's
     *             ordinal, its record's Data Access Group and the reason
     *             `duplicate-value`, which is part of its identity (W7-R4)
     *   engine-6  what a finding STORES moved, though no verdict or identity
     *             did (review rv11a2-9): a pooled finding's reason is kept as
     *             `pooled` with a mask, and a branched rule's finding names
     *             its branch (wave 11); a group run's summary is kept per
     *             group (rv11a1-4). A run that spanned the upgrade held both
     *             forms in one generation: one rule's findings in two reason
     *             buckets with two wordings, and a summary its group's reader
     *             counted short. The revision ends such a run, so the rule
     *             above reads "a verdict, or the form a report reads it in".
     */
    const ENGINE_REVISION = 'engine-6';

    /** The older name of ENGINE_REVISION, kept for code that still reads it. */
    const ENGINE_VERSION = self::ENGINE_REVISION;

    /** The fingerprint's `engine` input. One place, so start and work cannot disagree. */
    private function engineVersion()
    {
        return self::ENGINE_REVISION;
    }

    /** What a work request answers when an upgrade of this module ended the run (review rv10b1-6). */
    const ENGINE_CHANGED = 'This module was upgraded while this scan was running, and the new version judges '
        . 'some values differently, so the scan was ended rather than finished half under each version. '
        . 'Start a new scan.';

    /** What a work request answers when the run it was sent for has just been ended for this. */
    const CONFIG_CHANGED = 'The validation configuration or the project\'s structure changed while '
        . 'this scan was running, so the scan was ended rather than finished against two '
        . 'configurations. Start a new scan.';

    /**
     * What start and work answer when part of the configuration could not be
     * read (review R5-4). Not the end of anything: the run, if there is one,
     * is exactly as it was, and the next request reads again.
     */
    const CONFIG_UNREAD = 'Part of this project\'s configuration could not be read just now (%s), so '
        . 'this request did no scan work rather than judge the scan against a configuration it could '
        . 'not read in full. Nothing was lost; try again in a moment.';

    /** CONFIG_UNREAD, naming what could not be read. */
    private static function configUnread(array $unread)
    {
        return sprintf(self::CONFIG_UNREAD, implode(', ', array_map('strval', $unread)));
    }

    /**
     * Why a context that is not ok refuses (W10BF-4): its own sentence, unless
     * part of the configuration could not be read. Every rule refused because
     * the growth reference folder would not open is "no rules this scan can
     * evaluate", and that sentence sent a reader to fix rules the next read
     * could check.
     */
    private static function contextRefusal(array $ctx)
    {
        if (!empty($ctx['unread']) && is_array($ctx['unread'])) return self::configUnread($ctx['unread']);
        return isset($ctx['why']) ? $ctx['why'] : null;
    }

    /**
     * The fingerprint's inputs, as one argument bag (B7, Risk 6).
     *
     * TWO CALLERS, ONE BAG. openRun() hands it to ScanPlanner::plan(), which
     * stores the fingerprint it makes; configurationNow() hands it to
     * ScanPlanner::requestFingerprint() on every work request. Each used to
     * write its own literal, and two literals agree only for as long as
     * nobody edits one of them: given a different rule list, a different
     * settingsCount or one key fewer, the stored and the rebuilt fingerprints
     * differ on an unchanged project and every run is ended on its first work
     * pass with "the validation rules changed during this scan".
     *
     * NO settingsCount, on purpose and on both sides. Rule origin comes from
     * each rule's `_origin` (ScanPlanner::identifyAll()); the count is a
     * deprecated fallback no context supplies.
     *
     * @return array the keys ScanPlanner::requestFingerprint() reads
     */
    /**
     * The label snapshot a run is planned with (M5, W7-R11): the module's
     * ScanDimensions over the rule list the findings' ordinals refer to, plus
     * the source id each rule is stored under, as rows.
     *
     * LABELS NEVER STOP A SCAN. A module that cannot build them gives a
     * snapshot that says so (degraded `labels`), which is what makes the
     * finished run's `labelDegraded` true and its report say the labels are
     * raw, rather than a run with no snapshot that reads like one planned
     * before snapshots existed.
     */
    private function planningLabels($pid, array $ctx)
    {
        $plan = (isset($ctx['plan']) && is_array($ctx['plan'])) ? $ctx['plan'] : [];
        $d = null;
        try {
            if (is_callable([$this->module, 'scanDimensions'])) {
                $d = $this->module->scanDimensions($pid,
                    (isset($plan['allRules']) && is_array($plan['allRules'])) ? $plan['allRules'] : []);
            }
        } catch (\Throwable $e) {
            $d = null;
        }
        if (!($d instanceof ScanDimensions)) {
            $d = new ScanDimensions();
            $d->degraded['labels'] = 'the project\'s names could not be read when this scan was planned';
        }
        $d->rulesBySource = self::ruleOrdinals($plan);
        // Only the fields a rule names (review rv11a2-7): the snapshot is
        // read whole by every page and every export.
        return $d->ruleFieldsOnly()->toSnapshot();
    }

    /**
     * Whether the report of a run will show raw identifiers for some names
     * (W7-N11's `labelDegraded`), from the state row of the run's snapshot:
     * a degraded source recorded at planning, or no snapshot at all, whose
     * report is labelled from today's project. One point read.
     */
    private function labelsDegraded($pid, $generationId)
    {
        $row = $this->store()->dimension($pid, $generationId, 'state', 'snapshot');
        $state = ($row !== null && $row['meta'] !== null) ? json_decode($row['meta'], true) : null;
        return !is_array($state) || !empty($state['degraded']);
    }

    private function fingerprintInputs(array $ctx, array $policy)
    {
        return [
            'rules'     => isset($ctx['rules']) && is_array($ctx['rules']) ? $ctx['rules'] : [],
            // rv10b1-1: the instruments each rule is judged on, keyed as `rules`.
            'hosts'     => isset($ctx['plan']['hostFields']) && is_array($ctx['plan']['hostFields'])
                           ? $ctx['plan']['hostFields'] : [],
            'ownership' => isset($ctx['ownership']) && is_array($ctx['ownership']) ? $ctx['ownership'] : [],
            // M8: the project's design, as durableScanContext() reports it.
            'structure' => isset($ctx['structure']) ? $ctx['structure'] : [],
            'choices'   => isset($ctx['choices']) ? $ctx['choices'] : [],
            'policy'    => $policy,
            'engine'    => $this->engineVersion(),
        ];
    }

    /**
     * The two inputs promotion judges a moved configuration by (W7-N11), as
     * they are in this request, and whether either differs from the run's.
     *
     * THE FINGERPRINT is rebuilt from fingerprintInputs(), the bag openRun()
     * planned with, so an unchanged project matches and a changed one does not.
     *
     * THE POLICY REVISION has no stored "current" value to read: a revision is
     * a run's, bumped in place by ScanRetention::revokePreviews(). So it is
     * derived: the run's own revision while the policy in the settings is no
     * stricter than the one the run froze, and one past it once it is - what
     * revokePreviews() would move every run of the project to. A frozen
     * policy that cannot be read is treated as tightened: an unreadable
     * answer is never the reassuring one.
     *
     * Both null when the context does not ask for the check - a test's
     * context, never production's (durableScanContext() always asks).
     *
     * @return array{moved:bool, fingerprintNow:?string, policyRevisionNow:?int}
     */
    private function configurationNow(array $run, array $policy, array $ctx)
    {
        if (empty($ctx['verifyFingerprint'])) {
            return ['moved' => false, 'privacyMoved' => false, 'fingerprintNow' => null,
                    'policyRevisionNow' => null];
        }
        $fp = ScanPlanner::requestFingerprint($this->fingerprintInputs($ctx, $policy));
        $rev = isset($run['policy_revision']) ? (int) $run['policy_revision'] : 0;
        $frozen = isset($run['policy_json']) && is_string($run['policy_json'])
            ? json_decode($run['policy_json'], true) : null;
        $tighter = !is_array($frozen) || ScanPolicy::tightened($frozen, $policy);
        $revNow = $tighter ? $rev + 1 : $rev;
        return ['moved' => !ScanPlanner::fingerprintMatches(isset($run['fingerprint']) ? $run['fingerprint'] : null, $fp)
                           || $revNow !== $rev,
                // The privacy policy tightened (review R5-5): a stop, whatever
                // else moved with it, because the run writes nothing more.
                'privacyMoved' => $revNow !== $rev,
                'fingerprintNow' => $fp, 'policyRevisionNow' => $revNow];
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

    /**
     * Is the module being switched off on this server (review rv10a2-3)?
     *
     * The disable hook stops every active run, and the module keeps answering
     * requests until that hook returns. A Start in that window made a run the
     * hook's walk had already passed, which stayed active through the disable;
     * a work request took a slot the hook was freeing. So Start and work
     * refuse from the moment the hook writes DISABLED_AT, until an enable
     * writes ENABLED_AT after it. DISABLING_SECONDS bounds the refusal: it is
     * far longer than the hook may run (ScanMaintenance::DISABLE_SECONDS), so
     * a framework that enabled the module again without calling the enable
     * hook costs at most that wait, never a module that refuses every scan.
     *
     * AND FOR ONE PROJECT (review rvf2-1). The project disable hook wrote no
     * marker, so a Start in its window made a run its one read of the
     * project's active run had passed. It writes PROJECT_DISABLED_AT first
     * now, and the project enable hook PROJECT_ENABLED_AT, with the same rule.
     *
     * NEVER MEMOIZED. available() keeps its answer for the request, and
     * openRun() asks this again once its run exists; see endUnderDisable().
     *
     * @return ?string 'server' or 'project' while one is being switched off, else null
     */
    private function disabling($pid)
    {
        if (self::marked($this->setting(self::DISABLED_AT, null), $this->setting(self::ENABLED_AT, null))) {
            return 'server';
        }
        if ($pid !== null && self::marked($this->setting(self::PROJECT_DISABLED_AT, $pid),
                                          $this->setting(self::PROJECT_ENABLED_AT, $pid))) {
            return 'project';
        }
        return null;
    }

    /** A disable marker newer than its enable marker, and inside DISABLING_SECONDS. */
    private static function marked($off, $on)
    {
        if (!is_numeric($off)) return false;
        if (is_numeric($on) && (int) $on >= (int) $off) return false;
        return time() - (int) $off < self::DISABLING_SECONDS;
    }

    /**
     * End the run this Start has just made, because the module is being
     * switched off (review rvf2-1), and say why the Start is refused.
     *
     * available() is asked before the run exists, and once per request. A
     * Start past it when the disable hook wrote its marker could commit its
     * run after the hook's second walk, while the hook finished what it had
     * stopped, or after the hook returned; and the project hook reads the
     * project's active run once. Such a run held the project's slot through
     * the disable, and was expired as abandoned staleHours later. So the
     * Start asks again once its run exists. The marker is written before the
     * hook reads any run, so a run committed before it is the hook's to stop,
     * and one committed after it is found here.
     *
     * Stopped and finished as the hook stops and finishes one: cancelled,
     * audited as the system's, with the hook's sentence. A run the hook (or
     * anyone) stopped first is finished with the reason on its row.
     *
     * @param string $by 'server' or 'project', as disabling() answered
     */
    private function endUnderDisable($pid, $runId, $by)
    {
        $store = $this->store();
        $row = $store->run($pid, $runId);
        $ours = $row !== null && $row['phase'] !== ScanPhase::CANCELLING && $row['phase'] !== ScanPhase::TERMINAL
                && $store->cancel($pid, $runId, 'system');
        $in = $this->limits($pid);
        if ($ours) {
            $in['why'] = $by === 'project' ? ScanMaintenance::PROJECT_DISABLED_WHY : ScanMaintenance::DISABLED_WHY;
        }
        ScanPromotion::finishCancelled($store, $pid, $runId, $in);
        return $by === 'project' ? self::OFF_PROJECT : self::OFF_SERVER;
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
