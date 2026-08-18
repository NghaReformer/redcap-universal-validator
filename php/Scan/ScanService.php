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
        $gate = $this->available($pid);
        if (!$gate['ok']) return self::noStart($gate['why']);

        $scope = ScanPageView::scanScope($this->module, $pid);
        if (empty($scope['ok'])) return self::noStart($scope['why']);
        $dag = $scope['dag'];

        $policy = $this->policy($pid);
        $ctx = $this->module->durableScanContext($pid, ['valueCeiling' => $policy['valueMode']], $dag);
        if (empty($ctx['ok'])) return self::noStart($ctx['why']);

        // Every instrument the run will read, from the plan rather than from a
        // list somebody maintains. A field the plan could not place on an
        // instrument is an unknown ownership, and unknown ownership is denial.
        $forms = [];
        $unknown = false;
        foreach ($ctx['ownership'] as $field => $form) {
            if ($form === null || $form === '') { $unknown = true; continue; }
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
            'policy'    => $policy,
            'createdBy' => (string) $this->username(),
            'engine'    => $this->engineVersion(),
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
        ]);
        $r = $worker->work($pid, $runId);

        // Promotion is attempted on every pass, not only the one that finishes.
        // It refuses until the run really is finishable, so asking early costs
        // one predicate - while not asking at all is how a run ends up complete
        // in every respect except the row that says so.
        $u = $this->finalizer($pid, $ctx)->status((int) $run['generation_id']);
        ScanPromotion::promote($store, $pid, $runId, [
            'blockingAggregates' => $store->blockingAggregates($runId),
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
        $cancel = ScanAuthorization::mayCancel($ent['rights'], $ent['forms'], $run['scope_dag'],
                                               $run['created_by'], $this->username());

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
        $auth = ScanAuthorization::mayCancel($ent['rights'], $ent['forms'], $run['scope_dag'],
                                             $run['created_by'], $this->username());
        if (empty($auth['ok'])) return ['ok' => false, 'why' => $auth['why']];

        $ok = $store->cancel($pid, $runId, (string) $this->username());
        return ['ok' => $ok,
                'why' => $ok ? null : 'this scan had already finished, so there was nothing to stop'];
    }

    /** The run this project is currently working on, if any, for the page to resume. */
    public function activeRun($pid)
    {
        try {
            $r = $this->db->select('SELECT run_id FROM ' . Schema::table('scan_run')
                . ' WHERE project_id = ? AND active_slot = 1', [$pid]);
            return isset($r[0][0]) ? (int) $r[0][0] : null;
        } catch (\Throwable $e) {
            return null;
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
        $ctx = $this->module->durableScanContext($pid,
            ['valueCeiling' => $policy['valueMode']], $run['scope_dag']);
        if (empty($ctx['ok'])) return ['ok' => false, 'why' => $ctx['why']];

        $forms = [];
        $unknown = false;
        foreach ($ctx['ownership'] as $field => $form) {
            if ($form === null || $form === '') { $unknown = true; continue; }
            $forms[$form] = true;
        }
        return ['ok' => true, 'why' => null, 'rights' => $scope['rights'],
                'forms' => array_keys($forms), 'unknown' => $unknown,
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
}
