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
            // WHAT THE PLAN ALREADY KNOWS IT CANNOT EVALUATE. Config-broken,
            // unlocatable, unmapped-instrument and group-unscopable rules are
            // decided before a record is read; the planner records them so
            // ScanOutcome's `ruleProblems` term has a producer at all. Without
            // this line the term is dead and `clean` means only "no findings".
            'ruleProblems' => isset($ctx['problems']) ? $ctx['problems'] : [],
        ]));
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
                'fingerprintNow' => $config['fingerprintNow'],
                'policyRevisionNow' => $config['policyRevisionNow']]));
            return ['ok' => false, 'worked' => 0, 'requeued' => 0, 'blocked' => 0, 'findings' => 0,
                    'phase' => ScanPhase::TERMINAL, 'done' => false, 'stop' => 'changed',
                    'why' => self::CONFIG_CHANGED, 'status' => $this->statusOf($pid, $runId, [])];
        }
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
            // W7-N11: what the configuration is now. They matched above, so on
            // this pass they decide nothing; they are here so that promotion is
            // never again judged without them.
            'fingerprintNow'    => $config['fingerprintNow'],
            'policyRevisionNow' => $config['policyRevisionNow'],
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
            'finalizer' => $this->finalizer($pid, $ctx),
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
                return $planner->step($pid, $runId, array_merge($slice, [
                    'source'    => $this->openSource($pid),
                    'dagFilter' => isset($run['scope_dag']) ? $run['scope_dag'] : null,
                    'pageSize'  => ScanPlanner::PAGE_SIZE,
                ]));
            },
            'note'      => function ($event, array $context) {
                $this->note($event, $context);
            },
        ];
        // The privacy policy revision as the settings make it now (review
        // R5-5). The worker compares it with the run's and, when it moved,
        // stops the run before writing anything further. Null when this
        // context does not ask for the comparison.
        if ($policyRevision !== null) $deps['policyRevision'] = (int) $policyRevision;
        // The fingerprint configurationNow() built from fingerprintInputs(), so
        // the worker's own guard is armed with the value the service compared
        // (B7). Null, and the guard unarmed, when the context does not ask.
        if ($fingerprint !== null) $deps['fingerprint'] = (string) $fingerprint;
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
     */
    private function crossRefusal($pid, array $run)
    {
        $unreadable = ['ok' => false, 'final' => false,
            'why' => 'which other projects this scan looked values up in could not be read, so its results '
                   . 'are not shown to you yet'];
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
        try {
            $refused = is_callable([$this->module, 'crossLookupRefusals'])
                     ? $this->module->crossLookupRefusals((int) $pid, array_values($policy['crossLookups']))
                     : null;
        } catch (\Throwable $e) {
            $refused = null;
        }
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
        ksort($decided);
        ksort($unread);
        $why = $decided
            ? 'this scan looks values up in project ' . implode(', ', array_keys($decided))
              . ', which does not answer your lookups, so its results are not shown to you'
            : 'this scan looks values up in project ' . implode(', ', array_keys($unread))
              . ', and whether it answers your lookups could not be read just now, so its results '
              . 'are not shown to you yet';
        // A decision is as final as an instrument the reader cannot read:
        // polling again asks the same rights the same question.
        return ['ok' => false, 'why' => $why, 'final' => !$unread];
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
        $plan = (isset($ctx['plan']) && is_array($ctx['plan'])) ? $ctx['plan'] : [];
        // THE GROUPS THIS REQUEST HAS ALREADY READ (W7-R4). A duplicate's row
        // carries its record's Data Access Group, and a group is verified (its
        // records re-read through $read, which exports the group) just before
        // it is emitted, usually in the same request. Each read leaves its
        // records' groups here, so emitting the page after it reads nothing.
        $seen = new \ArrayObject();
        return new UniqueFinalizer($this->db, [
            'pid'      => $pid,
            'hmacKey'  => $this->key(),
            'versions' => $this->fence($pid),
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
     */
    const ENGINE_REVISION = 'engine-5';

    /** The older name of ENGINE_REVISION, kept for code that still reads it. */
    const ENGINE_VERSION = self::ENGINE_REVISION;

    /** The fingerprint's `engine` input. One place, so start and work cannot disagree. */
    private function engineVersion()
    {
        return self::ENGINE_REVISION;
    }

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
    private function fingerprintInputs(array $ctx, array $policy)
    {
        return [
            'rules'     => isset($ctx['rules']) && is_array($ctx['rules']) ? $ctx['rules'] : [],
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
