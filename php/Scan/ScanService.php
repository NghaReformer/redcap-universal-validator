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

    /** @var object the module */
    private $module;
    /** @var ?ScanDb */
    private $db;

    public function __construct($module)
    {
        $this->module = $module;
        $this->db = is_callable([$module, 'query']) ? new ModuleDb($module) : null;
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
     * @return array{ok:bool, why:?string, detail:?string}
     */
    public function available($pid)
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
        $caps = ScanCapabilities::recordEnumeration($this->module, $pid);
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
            ['valueCeiling' => $policy['valueMode'], 'generation' => null,
             'policy' => $policy], $dag);
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
        if (!empty($ctx['verifyFingerprint'])) {
            $current = ScanPlanner::requestFingerprint(['rules'=>$ctx['rules'], 'ownership'=>$ctx['ownership'],
                'structure'=>$ctx['structure'] ?? [], 'policy'=>$policy, 'engine'=>$this->engineVersion()]);
            if (!ScanPlanner::fingerprintMatches($run['fingerprint'], $current)) {
                return ['ok'=>false, 'why'=>'Validation configuration or project structure changed. Stop this scan and start a new scan.'];
            }
        }
        $worker = new ScanWorker($store, [
            'slots'     => new WorkerSlots($this->db),
            'slotTtl'   => self::SLOT_TTL,
            'fence'     => $this->fence($pid),
            'read'      => $ctx['read'],
            'evaluate'  => $ctx['evaluate'],
            'budget'    => new WorkBudget(['mode' => $mode, 'startedAt' => microtime(true)]),
            'owner'     => $this->owner(),
            'attempts'  => $policy['recordAttempts'],
            'finalizer' => $this->finalizer($pid, $ctx),
            'catchup'   => $this->catchUp($pid, $store, $run['scope_dag']),
            'rollup'    => new RollupBuilder($this->db, $store),
            'note'      => function ($event, array $context) {
                $this->note($event, $context);
            },
        ]);
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
        ScanPromotion::promote($store, $pid, $runId, [
            'blockingAggregates' => $store->blockingAggregates($runId),
            // PERMANENTLY ZERO, AND KNOWN TO BE. Nothing in the shipped tree
            // writes a 'collection-gap' aggregate, because no gap detector
            // exists. It is left wired rather than deleted because it is
            // harmless - gaps never block clean, and the only thing a zero
            // reaches is mustShowGaps, which no production caller reads. Do not
            // infer from a green suite that gaps are being counted.
            'gapCount'       => $this->aggregateTotal($store, $runId, 'collection-gap'),
            'ruleProblems'   => $this->aggregateTotal($store, $runId, 'rule-problem'),
            'uniqueDone'     => $u['done'],
            'uniqueBlocking' => $u['blocking'],
            'rollupDone'     => ($r['phase'] === ScanPhase::ROLLUP && !empty($r['done'])),
            'maxFindings'    => $policy['maxFindings'],
            'maxBytes'       => $policy['maxBytes'],
        ]);
        return array_merge($r, ['status' => $this->status($pid, $runId)]);
    }

    /**
     * What a caller entitled to see this run may know about it.
     *
     * Independent dimensions, not a formatted sentence: collapsing them is what
     * produced a report that was true about the run and false about the project.
     * The caller decides how to say it; this decides what is true.
     */
    public function status($pid, $runId)
    {
        $store = $this->store();
        $run = $store->run($pid, $runId);
        if ($run === null) return ['ok' => false, 'why' => self::NO_RUN];

        $ent = $this->entitlement($pid, $run);
        if (empty($ent['ok'])) return ['ok' => false, 'why' => $ent['why']];
        $auth = ScanAuthorization::mayRead($ent['rights'], $ent['forms'], $run['scope_dag'],
                                           $ent['unknown']);
        if (empty($auth['ok'])) return ['ok' => false, 'why' => $auth['why']];

        $done = 0;
        foreach ($store->recordStates($runId) as $st => $n) {
            if ((int) $st >= ScanStore::REC_DONE) $done += (int) $n;
        }
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
            'done'      => $done,
            'findings'  => (int) $run['detail_rows'],
            'scope'     => ($run['scope_dag'] === null ? 'project' : 'group'),
            'active'    => ScanPhase::isActive((string) $run['phase']),
            'mayCancel' => !empty($cancel['ok']),
            'why'       => null,
        ];
    }

    /** Ask a run to stop. The epoch bump is what makes it beat a working worker. */
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

        $ok = $store->cancel($pid, $runId, (string) $this->username());
        return ['ok' => $ok,
                'why' => $ok ? null : 'this scan had already finished, so there was nothing to stop'];
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
            foreach ($rows as $row) {
                $runId = (int) $row[0];
                $this->store()->finish($runId, ScanOutcome::derive([
                    'cancelled' => true,
                    'manifestDone' => $this->store()->manifestComplete($runId),
                ]));
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
     */
    private function entitlement($pid, array $run)
    {
        $scope = ScanPageView::scanScope($this->module, $pid);
        if (empty($scope['ok'])) {
            return ['ok' => false, 'why' => $scope['why']];
        }
        $policy = $this->policy($pid);
        // The run is in hand, so the evaluator is bound to THIS run's
        // generation and sequence - the numbers its findings are written under
        // and the interval they open.
        $ctx = $this->module->durableScanContext($pid, [
            'valueCeiling' => $policy['valueMode'],
            'generation'   => (int) $run['generation_id'],
            'runSeq'       => (int) $run['run_seq'],
            'policy'       => $policy,
        ], $run['scope_dag']);
        if (empty($ctx['ok'])) return ['ok' => false, 'why' => $ctx['why']];

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
        return new SqlScanStore($this->db);
    }

    /** The project's change log, or null when this installation has none. */
    private function fence($pid)
    {
        $f = SourceFence::forProject($this->db, $pid);
        return empty($f['ok']) ? null : $f['fence'];
    }

    private function catchUp($pid, ScanStore $store, $dag)
    {
        $src = RecordManifestSource::open($this->db, $pid, ['pk' => $this->recordIdField($pid)]);
        $source = empty($src['ok']) ? null : $src['source'];
        $key = $this->key();
        return new CatchUp($store, [
            'fence'  => $this->fence($pid),
            'hash'   => function ($id) use ($pid, $key) {
                return Hmac::raw(Hmac::P_RECORD, $pid, (string) $id, $key);
            },
            'exists' => function (array $ids) use ($source) {
                return $source === null ? array_fill_keys($ids, true) : $source->exist($ids);
            },
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

    private function aggregateTotal(ScanStore $store, $runId, $kind)
    {
        $n = 0;
        foreach ($store->aggregates($runId) as $a) {
            if (isset($a['kind']) && $a['kind'] === $kind) $n += (int) $a['cnt'];
        }
        return $n;
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

    private function engineVersion()
    {
        return defined('UV_ENGINE_VERSION') ? UV_ENGINE_VERSION : 'engine-1';
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
