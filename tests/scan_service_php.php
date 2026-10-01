<?php
/**
 * scan_service_php.php — the gate in front of the durable scan.
 *
 * ScanService is the composition root: it builds fourteen classes over the
 * framework and exposes four verbs. Most of what it does needs a database and
 * is checked in the matrix. What is checked HERE is the part that decides
 * whether any of it runs at all, because that decision is made before any
 * database is touched and it is the one this release depends on:
 *
 *   BOTH FLAGS OFF IS THE DEFAULT, and the plan requires a real-server pilot
 *   before either is turned on. A flag that defaults on is not a flag.
 *
 *   A REFUSAL MUST NOT DESCRIBE THE INSTALLATION. Someone who has just been
 *   told the answer is no should not learn from the same sentence whether the
 *   tables exist, which database user the module runs as, or what a class here
 *   is called.
 *
 * Run:  php tests/scan_service_php.php
 */

namespace {
    require_once __DIR__ . '/../php/ScanPageView.php';
    require_once __DIR__ . '/../php/ScanCapabilities.php';
    require_once __DIR__ . '/../php/Scan/Schema.php';
    require_once __DIR__ . '/../php/Scan/ScanDb.php';
    require_once __DIR__ . '/../php/Scan/DbError.php';
    require_once __DIR__ . '/../php/Scan/ScanStore.php';
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';
    require_once __DIR__ . '/../php/Scan/ScanOutcome.php';
    require_once __DIR__ . '/../php/Scan/ScanPhase.php';
    require_once __DIR__ . '/../php/Scan/ScanPolicy.php';
    require_once __DIR__ . '/../php/Scan/ScanAuthorization.php';
    require_once __DIR__ . '/../php/Scan/Hmac.php';
    require_once __DIR__ . '/../php/Scan/ReasonCode.php';
    require_once __DIR__ . '/../php/Scan/SqlScanStore.php';
    require_once __DIR__ . '/../php/Scan/WorkerSlots.php';
    require_once __DIR__ . '/../php/Scan/ScanRetention.php';
    require_once __DIR__ . '/../php/Scan/RecordManifestSource.php';
    require_once __DIR__ . '/../php/Scan/SourceFence.php';
    require_once __DIR__ . '/../php/Scan/ScanPlanner.php';
    require_once __DIR__ . '/../php/Scan/WorkBudget.php';
    require_once __DIR__ . '/../php/Scan/UniqueFinalizer.php';
    require_once __DIR__ . '/../php/Scan/CatchUp.php';
    require_once __DIR__ . '/../php/Scan/RollupBuilder.php';
    require_once __DIR__ . '/../php/Scan/ScanPromotion.php';
    require_once __DIR__ . '/../php/Scan/ScanWorker.php';
    require_once __DIR__ . '/../php/Scan/ScanStoreUnavailable.php';
    require_once __DIR__ . '/../php/Scan/ScanService.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    /**
     * A uniqueness phase with nothing to decide. The worker refuses to pass the
     * phase without a finalizer at all, and the real one needs a database; the
     * runs below are about what happens to records, not to duplicate groups.
     */
    class SvcNoDuplicates implements \INSPIRE\UniversalValidator\Scan\DuplicateFinalizer
    {
        public function step($generationId, $limit = 500, $readLimit = null)
        {
            return ['done' => true, 'groups' => 0, 'verified' => 0, 'emitted' => 0,
                    'published' => 0, 'collisions' => 0, 'read' => 0, 'why' => null];
        }
    }

    /**
     * A module with settings and, optionally, a database.
     *
     * No query() at all is a real REDCap build: the framework has not always
     * given modules their own handle, and a module that assumed one would fatal
     * on the page rather than explain itself.
     */
    class FakeModule
    {
        public $sys = [];
        public $proj = [];
        public $tables = [];       // which of our tables "exist"
        public $version = null;    // schema_version answer, null = table missing
        public $queries = [];
        private $hasQuery;

        public function __construct($hasQuery = true) { $this->hasQuery = $hasQuery; }

        public function getSystemSetting($k)
        {
            return isset($this->sys[$k]) ? $this->sys[$k] : null;
        }
        public function getProjectSetting($k, $pid = null)
        {
            return isset($this->proj[$k]) ? $this->proj[$k] : null;
        }
        public function query($sql, $params = [])
        {
            if (!$this->hasQuery) throw new \Exception('no query');
            $this->queries[] = $sql;
            if (strpos($sql, 'MAX(version)') !== false) {
                if ($this->version === null) throw new \Exception("Table doesn't exist");
                return [[$this->version]];
            }
            if (strpos($sql, 'information_schema.tables') !== false) {
                $t = isset($params[0]) ? $params[0] : '';
                return [[in_array($t, $this->tables, true) ? 1 : 0]];
            }
            return [];
        }
    }


    /**
     * A module healthy enough to reach the store, and a database that fails.
     *
     * The two halves matter equally. Every earlier check in this file stops at
     * available(), so nothing here had ever driven a verb past its gate; the
     * fixture below passes the gate and then breaks the ONE statement the test
     * names, which is how a storage failure arrives in production - not as a
     * broken installation, but as a working one whose server stopped answering
     * halfway through a request.
     */
    class StorageFaultModule extends FakeModule
    {
        /** @var ?string statements containing this fail */
        public $failOn = null;
        /** @var array list of [event, context] */
        public $logs = [];

        public function query($sql, $params = [])
        {
            if ($this->failOn !== null && strpos($sql, $this->failOn) !== false) {
                throw new \INSPIRE\UniversalValidator\Scan\ScanStoreUnavailable(
                    '[1213] Deadlock found when trying to get lock; the work was rolled back');
            }
            // The record index answers for this project, so the capability gate
            // passes and the refusal that follows is the one under test.
            if (strpos($sql, 'FROM redcap_record_list') !== false) return [['R1']];
            return parent::query($sql, $params);
        }

        public function log($event, $context = [])
        {
            $this->logs[] = [$event, $context];
            return 1;
        }

        /** Everything logged under one event name, as a flat list of contexts. */
        public function logged($event)
        {
            $out = [];
            foreach ($this->logs as $l) {
                if ($l[0] === $event) $out[] = $l[1];
            }
            return $out;
        }
    }

    // A build with no query() method at all. is_callable must answer false, so
    // it is a separate class rather than a flag on the one above.
    class NoDbModule
    {
        public $sys = [];
        public $proj = [];
        public function getSystemSetting($k) { return isset($this->sys[$k]) ? $this->sys[$k] : null; }
        public function getProjectSetting($k, $pid = null) { return isset($this->proj[$k]) ? $this->proj[$k] : null; }
    }

    /**
     * A ScanDb serving redcap_record_list for one project, and nothing else.
     *
     * DISPATCHED ON THE SQL, because the point of this fixture is that the REAL
     * RecordManifestSource issues the queries. A hand-written source would
     * prove the fake agrees with the planner.
     *
     * THREE RECORDS IN TWO GROUPS, and the middle one is elsewhere: 7, 31, 7.
     * The assertion that matters is appended === 2, which no single-group
     * fixture could distinguish from a filter that matched everything.
     */
    class FakeSourceDb implements \INSPIRE\UniversalValidator\Scan\ScanDb
    {
        public $rows = [['R001', '7'], ['R002', '31'], ['R003', '7']];

        public function select($sql, array $params = [])
        {
            if (strpos($sql, 'information_schema.columns') !== false) {
                if (isset($params[0]) && $params[0] === 'redcap_record_list') {
                    return [['project_id'], ['record'], ['dag_id']];
                }
                // The data table exists, because exist() confirms an absence
                // from the record-list cache against it before it will call a
                // record gone (review CL-1). It holds what the index holds.
                if (isset($params[0]) && $params[0] === 'redcap_data') {
                    return [['project_id'], ['record'], ['field_name']];
                }
                return [];
            }
            if (strpos($sql, 'redcap_projects') !== false) return [];
            if (strpos($sql, 'FROM redcap_data') !== false && strpos($sql, ' IN (') !== false) {
                $asked = array_map('strval', array_slice($params, 1));
                $out = [];
                foreach ($this->rows as $r) if (in_array($r[0], $asked, true)) $out[] = [$r[0]];
                return $out;
            }
            if (strpos($sql, 'FROM redcap_record_list') === false) return [];

            // boundaryGroup(): every id equal to one id.
            if (strpos($sql, 'record = ?') !== false) {
                $want = (string) $params[count($params) - 1];
                $out = [];
                foreach ($this->rows as $r) if ($r[0] === $want) $out[] = [$r[0]];
                return $out;
            }
            // exist(): which of these ids the project still holds. Every
            // parameter after the project id is one of the ids asked about.
            if (strpos($sql, ' IN (') !== false) {
                $asked = array_map('strval', array_slice($params, 1));
                $out = [];
                foreach ($this->rows as $r) if (in_array($r[0], $asked, true)) $out[] = [$r[0]];
                return $out;
            }
            $wantsDag = strpos($sql, 'dag_id') !== false;
            $after = null;
            if (strpos($sql, 'record >= ?') !== false) $after = (string) $params[1];
            $out = [];
            foreach ($this->rows as $r) {
                if ($after !== null && strcmp($r[0], $after) < 0) continue;
                $out[] = $wantsDag ? [$r[0], $r[1]] : [$r[0]];
            }
            if (preg_match('/LIMIT (\d+)/', $sql, $m)) $out = array_slice($out, 0, (int) $m[1]);
            return $out;
        }

        public function exec($sql, array $params = []) { return 0; }
        public function execNoCount($sql, array $params = []) {}
        public function affected() { return 0; }
        public function begin() {}
        public function commit() {}
        public function rollback() {}
    }

    /**
     * A module whose database is the record index FakeSourceDb serves, and
     * nothing else: no change log, so no fence, and no scan tables.
     *
     * Through the module's query(), because that is how ScanService reaches a
     * database - it wraps the module in ModuleDb - so the record source the
     * service opens for its existence probe is the real one, over this.
     */
    class SourceModule
    {
        public $src;
        public function __construct() { $this->src = new FakeSourceDb(); }
        public function query($sql, $params = [])
        {
            if (strpos($sql, 'information_schema.columns') !== false
                    || strpos($sql, 'redcap_record_list') !== false
                    || strpos($sql, 'redcap_projects') !== false) {
                return $this->src->select($sql, $params);
            }
            return [];
        }
    }

    /** A module whose user is a designer confined to group 7. */
    class DagUserModule
    {
        public function getUser() { return new \DagUser(); }
    }

    /**
     * AN OBJECT, NOT AN ARRAY. scanScope() calls hasDesignRights() and
     * getRights($pid) on whatever getUser() returns, through is_callable - so a
     * fixture that handed back an array would exercise the refusal path and
     * prove nothing about the scope.
     */
    class DagUser
    {
        public function hasDesignRights() { return true; }
        public function getRights($pid = null)
        {
            return ['design' => true, 'data_export_tool' => '1', 'group_id' => 7,
                    'forms' => ['fa' => '1']];
        }
    }

    /** Only what dagNameOf() reads. */
    class REDCap
    {
        public static $groupNames = [];
        public static function getGroupNames($unique = false, $groupId = null)
        {
            if ($groupId === null) return self::$groupNames;
            return isset(self::$groupNames[(int) $groupId]) ? self::$groupNames[(int) $groupId] : '';
        }
    }
}

namespace INSPIRE\UniversalValidator\Scan {

    // -- the flags -----------------------------------------------------------

    $mod = new \FakeModule();
    $svc = new ScanService($mod);
    $a = $svc->available(1);
    check('service: with nothing configured the durable scan is OFF', $a['ok'] === false);
    check('service: and says so in one plain sentence',
        strpos($a['why'], 'not enabled here') !== false);

    // BOTH, not either. A system administrator enabling it for the installation
    // does not enable it for a project that has not asked, and vice versa.
    $mod->sys[ScanService::SYS_FLAG] = '1';
    check('service: the system switch alone is not enough',
        $svc->available(1)['ok'] === false);

    $mod2 = new \FakeModule();
    $mod2->proj[ScanService::PROJ_FLAG] = '1';
    check('service: the project switch alone is not enough either',
        (new ScanService($mod2))->available(1)['ok'] === false);

    // Only a real "on" is on. A stray string is not a truthy setting here,
    // because a settings store that returns 'off' or 'no' would otherwise turn
    // the feature ON.
    $mod3 = new \FakeModule();
    $mod3->sys[ScanService::SYS_FLAG] = 'off';
    $mod3->proj[ScanService::PROJ_FLAG] = 'off';
    check('service: "off" does not read as on', (new ScanService($mod3))->available(1)['ok'] === false);
    $mod3->sys[ScanService::SYS_FLAG] = 'no';
    $mod3->proj[ScanService::PROJ_FLAG] = 'no';
    check('service: nor does "no"', (new ScanService($mod3))->available(1)['ok'] === false);

    // -- the flag answer discloses nothing about the installation ------------
    //
    // The order in available() is deliberate: the flags are checked BEFORE the
    // schema, so someone who has not turned the feature on cannot use the
    // refusal to find out whether the tables were ever created.
    $quiet = new \FakeModule();
    $quiet->version = 1;                              // schema present and healthy
    $quiet->tables = Schema::tables();
    $q = (new ScanService($quiet))->available(1);
    check('service: a disabled installation is not told about its schema',
        $q['ok'] === false && $q['detail'] === null
        && strpos($q['why'], 'table') === false);

    // -- past the flags, the schema is the next gate -------------------------
    $on = new \FakeModule();
    $on->sys[ScanService::SYS_FLAG] = '1';
    $on->proj[ScanService::PROJ_FLAG] = '1';
    $on->version = 1;
    $on->tables = array_slice(Schema::tables(), 0, 3);   // three of ten
    $r = (new ScanService($on))->available(1);
    check('service: an incomplete schema refuses', $r['ok'] === false);
    check('service: and says it is the tables',
        strpos($r['why'], 'tables are not ready') !== false);
    check('service: with a diagnostic for the administrator, kept separate',
        is_string($r['detail']) && strpos($r['detail'], 'missing') !== false);

    // A schema that cannot be READ is not a schema that is absent, and the two
    // must not lead to the same action - one installs, the other refuses.
    $unread = new \FakeModule();
    $unread->sys[ScanService::SYS_FLAG] = '1';
    $unread->proj[ScanService::PROJ_FLAG] = '1';
    $unread->version = null;      // the version query throws "doesn't exist" -> version 0
    $u = (new ScanService($unread))->available(1);
    check('service: a fresh installation with no tables refuses rather than half-installing',
        $u['ok'] === false);

    // -- no database at all --------------------------------------------------
    //
    // A real REDCap build. The module must explain itself rather than fatal on
    // the page, and it must do so even before the flags: there is nothing an
    // administrator could switch on that would help.
    $nodb = new \NoDbModule();
    $nodb->sys[ScanService::SYS_FLAG] = '1';
    $nodb->proj[ScanService::PROJ_FLAG] = '1';
    $nd = (new ScanService($nodb))->available(1);
    check('service: a build with no module database refuses', $nd['ok'] === false);
    check('service: naming the reason an administrator can act on',
        strpos($nd['why'], 'database access') !== false);

    // -- every unknown run gets ONE sentence ---------------------------------
    //
    // "No such run" and "not your run" must be indistinguishable, or a project
    // link becomes a way to count the runs on every other project.
    check('service: there is a single wording for an unreachable run',
        is_string(ScanService::NO_RUN) && strlen(ScanService::NO_RUN) > 20);
    check('service: it names no project, no run and no owner',
        preg_match('/\d/', ScanService::NO_RUN) === 0
        && stripos(ScanService::NO_RUN, 'permission') === false
        && stripos(ScanService::NO_RUN, 'denied') === false);

    // -- the verbs refuse while disabled -------------------------------------
    //
    // Not just the page: each AJAX verb re-asks. redcap_module_ajax() guards the
    // action NAME and hands the caller's identity straight through, so a verb
    // that trusted the page's gate would be reachable by anyone who could form
    // the request.
    $offSvc = new ScanService(new \FakeModule());
    check('service: start refuses while disabled', $offSvc->start(1)['ok'] === false);
    check('service: work refuses while disabled', $offSvc->work(1, 5)['ok'] === false);
    check('service: and neither reveals whether run 5 exists',
        $offSvc->work(1, 5)['why'] === $offSvc->work(1, 99999)['why']);

    // -- A STORAGE FAILURE IS NOT A 500, AND NOT A LEAK ----------------------
    //
    // ScanService is the last frame before the AJAX handler. Now that the store
    // throws rather than answering a deadlock with the fence's own vocabulary,
    // something has to decide what an operator is told and what an
    // administrator is told, and this is the only place that can decide both.
    //
    // The stand-in raises the failure at the query seam, which is where the
    // real one arrives from: ModuleDb::exec() throws ScanStoreUnavailable when
    // the server will not say how many rows a write changed, and SqlScanStore's
    // read methods deliberately do not catch it.
    $ready = new \StorageFaultModule();
    $ready->sys[ScanService::SYS_FLAG] = '1';
    $ready->proj[ScanService::PROJ_FLAG] = '1';
    $ready->version = Schema::VERSION;
    $ready->tables = Schema::tables();
    check('service: the fixture is otherwise healthy, so the next refusal is the fault',
        (new ScanService($ready))->available(1)['ok'] === true);

    $ready->failOn = 'FROM ' . Schema::table('scan_run');
    $svcF = new ScanService($ready);
    $w = $svcF->work(1, 5);
    check('service: a storage failure during work is a refusal, not an uncaught throw',
        is_array($w) && $w['ok'] === false);
    check('service: and the operator gets the one fixed sentence',
        $w['why'] === ScanStoreUnavailable::OPERATOR_TEXT);
    check('service: which names no table, column, value or error number',
        preg_match('/\d/', $w['why']) === 0
        && stripos($w['why'], 'uv_') === false && stripos($w['why'], 'deadlock') === false);

    $logged = $ready->logged('scan storage failure');
    check('service: what the server said reaches the module log',
        count($logged) === 1 && strpos($logged[0]['detail'], 'Deadlock') !== false);
    check('service: with the run and project it happened on',
        $logged[0]['run_id'] === 5 && $logged[0]['project_id'] === 1);
    check('service: and none of it reaches the browser',
        strpos(json_encode($w), 'Deadlock') === false);

    // START TAKES THE SAME BOUNDARY, through the same helper, and it cannot be
    // driven from here: every gate in front of startRun() - the availability
    // probe, the scope read, the manifest source - catches Throwable and turns
    // it into its own refusal, so nothing a stand-in can raise reaches the
    // catch except by going all the way through the planner. The store half of
    // it is proved in tests/scan_sqlstore_fault_php.php, where a write failure
    // over an EMPTY project slot throws instead of reporting contention; the
    // service half needs the database matrix, and is named for it.

    // -- reason codes --------------------------------------------------------
    //
    // The column is 64 characters and `assert:` carries up to 507 of expression.
    // Storing that per finding is a gigabyte of the same sentence, and it
    // destroys both the index and the GROUP BY the summary needs.
    check('reason: an assert keeps its kind and sheds its expression',
        ReasonCode::code('assert:total_dose <= max_dose * 3') === 'assert');
    $sp = ReasonCode::split('assert:a > b');
    check('reason: and the expression comes back for the RULE to store',
        $sp['detail'] === 'a > b');
    check('reason: a plain reason is its own code',
        ReasonCode::code('required-blank') === 'required-blank');
    check('reason: an empty reason is still a code, never a blank column',
        ReasonCode::code('') === 'unspecified');

    // Pooled problems are a closed five-element set: a mask indexes and groups,
    // a joined string does neither.
    $p = ReasonCode::split('pooled:length;checkdigit');
    check('reason: pooled becomes a bitmask', $p['code'] === 'pooled' && $p['bits'] === (1 | 4));
    check('reason: and the mask reads back as the same problems',
        ReasonCode::pooledNames($p['bits']) === ['length', 'checkdigit']);
    check('reason: an unknown pooled problem does not corrupt the mask',
        ReasonCode::split('pooled:length;invented')['bits'] === 1);

    // An unknown reason PASSES THROUGH. A future rule type must degrade to
    // generic wording, never to a silent hole.
    check('reason: a reason nobody has seen is kept, not dropped',
        ReasonCode::code('out-of-range') === 'out-of-range');
    $long = ReasonCode::code(str_repeat('x', 200));
    check('reason: an over-long code is cut to the column', strlen($long) <= ReasonCode::MAX);
    check('reason: and marked, so it is visibly odd rather than quietly wrong',
        substr($long, -1) === '~');

/* =========================================================================
 * B3  ONE DAG AXIS, END TO END, WITH NO DATABASE
 *
 * This file had no occurrence of 'dag' at all, and that absence is why the
 * seam stayed open through 1,228 green checks: scan_security_php was
 * internally consistent on DAG NAMES, the planning matrix was internally
 * consistent on numeric IDS, and neither suite ever held both sides of the
 * comparison at once.
 *
 * The join below does. ScanPageView::scanScope() is the real producer, driven
 * by a module whose getUser() answers a real rights array; RecordManifestSource
 * is the real walker over a fake ScanDb serving redcap_record_list; ScanPlanner
 * is the real planner over ArrayScanStore. The scope value is never written
 * down - it is taken from the producer and handed to the consumer.
 *
 * WHY appended IS THE ASSERTION AND outOfScope IS NOT. outOfScope === listed is
 * produced identically by a group that genuinely holds no records and by a
 * scope compared on the wrong axis, and ScanService::start discards the stats
 * array anyway. Measured against the shipped tree: listed 3, appended 0,
 * outOfScope 3, manifest_total 0 - and an empty frozen manifest is exactly what
 * promotes to coverage=complete-through-fence clean=true. Against the fixed
 * tree: listed 3, appended 2, outOfScope 1, manifest_total 2.
 * ===================================================================== */
{
    $db  = new \FakeSourceDb();
    $mod = new \DagUserModule();
    \REDCap::$groupNames = [7 => 'north', 31 => 'south'];

    $scope = \INSPIRE\UniversalValidator\ScanPageView::scanScope($mod, 149);
    check('B3 GATE 2: the page produces a scope for a DAG-bound designer',
        $scope['ok'] === true && $scope['dag'] === '7');

    $open = RecordManifestSource::open($db, 149, ['pk' => null]);
    check('B3 GATE 2: and the record index is usable as a source',
        $open['ok'] === true && $open['source']->hasDag());

    $planner = new ScanPlanner(new ArrayScanStore(), str_repeat('k', 32));
    $r = $planner->plan(149, [
        'source'    => $open['source'],
        'fence'     => null,
        'dagFilter' => $scope['dag'],          // TAKEN, never written down
        'rules'     => [['type' => 'required', 'fields' => ['a']]],
        'ownership' => ['a' => 'fa'],
        'policy'    => [],
        'createdBy' => 'u',
        'engine'    => '1',
    ]);

    check('B3 GATE 2: a group-scoped run planned from the page\'s own scope APPENDS the '
        . 'records in that group',
        $r['stats']['listed'] === 3 && $r['stats']['appended'] === 2
        && $r['stats']['outOfScope'] === 1);
    check('B3 GATE 2: the frozen manifest is not empty, so nothing can promote over nothing',
        (int) $r['run']['manifest_total'] === 2);
    check('B3 GATE 2: the run stores the id it was scoped by',
        $r['run']['scope_dag'] === '7');
    check('B3 GATE 2: and the user who started it may work it',
        ScanAuthorization::mayWork($scope['rights'], ['fa'], $r['run']['scope_dag'])['ok'] === true);
}

/* =====================================================================
 * EE-4  AN EMPTY SCOPE KEEPS THE RULE PROBLEMS THE PLANNER RECORDED
 *
 * The planner notes rule problems before it reads a record, then finishes a run
 * whose manifest froze empty. That finish wrote rule_problem_count = 0 beside
 * the marks it had just written: derive() does not carry the count, promote()
 * adds it, and this path never goes through promote(). The run is stored
 * clean = 0, so its counts are ones a report reads.
 * ===================================================================== */
{
    $es = new ArrayScanStore();
    $esOpen = RecordManifestSource::open(new \FakeSourceDb(), 149, ['pk' => null]);
    $esr = (new ScanPlanner($es, str_repeat('k', 32)))->plan(149, [
        'source' => $esOpen['source'], 'fence' => null,
        'dagFilter' => '99',                     // a group no record is in
        'rules' => [['type' => 'required', 'fields' => ['a']]], 'ownership' => ['a' => 'fa'],
        'policy' => [], 'createdBy' => 'u', 'engine' => '1',
        'ruleProblems' => [['rule' => 1, 'fields' => ['a'], 'why' => 'x', 'code' => 'c1'],
                           ['rule' => 2, 'fields' => ['b'], 'why' => 'y', 'code' => 'c2']]]);
    $esRow = $es->run(149, 1);
    check('EE-4: a run whose manifest froze empty is refused and finished as an empty scope',
        $esr['ok'] === false && $esRow['coverage'] === ScanOutcome::EMPTY_SCOPE
        && $esRow['clean'] !== null && (int) $esRow['clean'] === 0);
    check('EE-4: and keeps the two rule problems the planner recorded, rather than a 0',
        (int) $esRow['rule_problem_count'] === 2
        && ScanPromotion::tally($es->aggregates(1))['ruleProblems'] === 2);
}

/* =====================================================================
 * WHERE A RUN HAS GOT TO, AND WHETHER IT IS GETTING ANYWHERE (L4, L5)
 *
 * status() is reachable only through a module, a user and an entitlement,
 * which is why it had no test. What it SAYS about progress is progressOf(),
 * which takes a row, a census and a clock.
 * ===================================================================== */
{
    $NOW = strtotime('2026-09-20 12:00:00 UTC');
    $ago = function ($seconds) use ($NOW) { return gmdate('Y-m-d H:i:s', $NOW - $seconds); };
    $row = function (array $over) use ($ago) {
        return array_merge(['phase' => ScanPhase::SCANNING, 'terminal' => null,
            'created_at' => $ago(3600), 'updated_at' => $ago(1), 'progress_at' => $ago(10)], $over);
    };
    $census = [ScanStore::REC_PENDING => 4, ScanStore::REC_CLAIMED => 1, ScanStore::REC_DONE => 8,
               ScanStore::REC_UNREADABLE => 1, ScanStore::REC_UNSTABLE => 2,
               ScanStore::REC_TOMBSTONE => 3, ScanStore::REC_UNSTORED => 5];
    $p = ScanService::progressOf($row([]), $census, $NOW);
    check('progress: done is records EXAMINED and nothing else', $p['done'] === 8);
    check('progress: every not-examined state is an exclusion, once each', $p['excluded'] === 8);
    check('progress: a deleted record is neither', $p['removed'] === 3);
    check('progress: settled is the three together, and leaves out what is still waiting',
        $p['settled'] === 19);
    // The bug, in one line: twelve records, none examined, and the old sum.
    $none = ScanService::progressOf($row([]), [ScanStore::REC_UNSTORED => 12], $NOW);
    check('progress: a run where every record failed to store examined NOTHING',
        $none['done'] === 0 && $none['excluded'] === 12 && $none['settled'] === 12);
    check('progress: an empty census is all zeroes rather than an error',
        ScanService::progressOf($row([]), [], $NOW)['settled'] === 0);

    check('stall: a run that finished a record ten seconds ago is not stalled',
        $p['stalled'] === false && $p['paused'] === false && $p['idleSeconds'] === 10);
    // A worker holds the run: claim() took the lease a second ago, as it does
    // on every pass of a tab that is driving it.
    $held = function ($s) use ($NOW) { return gmdate('Y-m-d H:i:s', $NOW + $s); };
    $s = ScanService::progressOf($row(['progress_at' => $ago(500), 'lease_expires_at' => $held(299)]),
        $census, $NOW);
    check('stall: one a worker holds that has finished nothing for longer than the threshold is',
        $s['stalled'] === true && $s['paused'] === false && $s['idleSeconds'] === 500);
    // THE WEDGE. claim() refreshes updated_at on a run whose every batch is
    // being refused, which is exactly the run a watcher needs to be told about.
    check('stall: in scanning, a fresh updated_at does NOT hide it',
        ScanService::progressOf($row(['progress_at' => $ago(500), 'updated_at' => $ago(1),
            'lease_expires_at' => $held(299)]), $census, $NOW)['stalled'] === true);
    // The other three phases never move progress_at, so their cursor writes are
    // the only clock there is.
    foreach (ScanService::PHASES_ON_THE_UPDATE_CLOCK as $ph) {
        $busy = ScanService::progressOf($row(['phase' => $ph, 'progress_at' => $ago(5000),
            'updated_at' => $ago(3)]), $census, $NOW);
        check("stall: in $ph a moving cursor is progress", $busy['stalled'] === false
            && $busy['paused'] === false && $busy['idleSeconds'] === 3);
        // NOTHING IN THIS PHASE RENEWS A LEASE, so a cursor that has stopped
        // cannot be told from a run nobody is driving - and it is given the
        // reading that sends somebody to Continue, even with a lease left over
        // from scanning.
        $stuck = ScanService::progressOf($row(['phase' => $ph, 'progress_at' => $ago(5000),
            'updated_at' => $ago(4000), 'lease_expires_at' => $held(60)]), $census, $NOW);
        check("stall: and in $ph a cursor that has stopped is PAUSED, not stalled",
            $stuck['paused'] === true && $stuck['stalled'] === false && $stuck['idleSeconds'] === 4000);
    }

    // CL-2. A RUN NOBODY IS DRIVING IS PAUSED. Only an open tab works a scan,
    // and the page says closing it is safe; the run that tab leaves behind was
    // called stalled two minutes later, and the page told its next reader to
    // stop it and start a new one.
    $closed = ScanService::progressOf($row(['progress_at' => $ago(600), 'updated_at' => $ago(600)]),
        $census, $NOW);
    check('CL-2: a run whose tab was closed ten minutes ago, with no lease, is paused - not stalled',
        $closed['paused'] === true && $closed['stalled'] === false && $closed['idleSeconds'] === 600);
    check('CL-2: and so is one whose lease ran out',
        ScanService::progressOf($row(['progress_at' => $ago(600), 'updated_at' => $ago(1),
            'lease_expires_at' => $ago(5)]), $census, $NOW)['paused'] === true);
    // A LEASE OUTLIVES ITS REQUEST by five minutes. The tab closed three
    // minutes ago: its lease is still "live", and nobody has touched the run
    // since. That is the closed tab, not the wedge.
    $leftover = ScanService::progressOf($row(['progress_at' => $ago(180), 'updated_at' => $ago(180),
        'lease_expires_at' => $held(120)]), $census, $NOW);
    check('CL-2: a lease left by a tab closed three minutes ago does not make the run stalled',
        $leftover['paused'] === true && $leftover['stalled'] === false);
    check('CL-2: an unreadable lease is no lease, so it can never make a run look stalled',
        ScanService::progressOf($row(['progress_at' => $ago(600), 'lease_expires_at' => 'not a date']),
            $census, $NOW)['stalled'] === false);
    // A STOPPED RUN sits in `cancelling` until a later request finishes it.
    $stop = ScanService::progressOf($row(['phase' => ScanPhase::CANCELLING,
        'progress_at' => $ago(9000), 'updated_at' => $ago(1), 'lease_expires_at' => $held(299)]),
        $census, $NOW);
    check('CL-2: a run that was asked to stop is stopping - never stalled, never paused',
        $stop['stopping'] === true && $stop['stalled'] === false && $stop['paused'] === false);
    check('CL-2: and a run that was not asked to stop is not stopping',
        $closed['stopping'] === false && $s['stopping'] === false);
    // CL-3. PLANNING is one synchronous request inside start(), timed from
    // created_at because progress_at stays NULL until the manifest freezes.
    $plan = ScanService::progressOf(['phase' => ScanPhase::PLANNING, 'terminal' => null,
        'created_at' => $ago(150), 'updated_at' => $ago(1), 'progress_at' => null,
        'lease_expires_at' => $held(299)], [ScanStore::REC_PENDING => 400000], $NOW);
    check('CL-3: a run still listing its records for two and a half minutes is not stalled',
        $plan['stalled'] === false && $plan['paused'] === false && $plan['stopping'] === false);
    check('stall: the list of those phases is the three finishing ones, and not scanning',
        count(ScanService::PHASES_ON_THE_UPDATE_CLOCK) === 3
        && !in_array(ScanPhase::SCANNING, ScanService::PHASES_ON_THE_UPDATE_CLOCK, true));
    check('stall: a run that has never finished a record is timed from when it was created',
        ScanService::progressOf($row(['progress_at' => null, 'created_at' => $ago(900)]),
            [], $NOW)['idleSeconds'] === 900);
    check('stall: a FINISHED run is never stalled, however long ago it ended',
        ScanService::progressOf($row(['phase' => ScanPhase::TERMINAL, 'terminal' => 'complete',
            'progress_at' => $ago(999999)]), $census, $NOW)['stalled'] === false);
    check('stall: a row with no readable clock is not reported as the alarming reading of it',
        ScanService::progressOf(['phase' => ScanPhase::SCANNING, 'terminal' => null,
            'created_at' => 'not a date'], $census, $NOW) ['stalled'] === false);
    check('stall: the threshold is the caller\'s to tighten',
        ScanService::progressOf($row(['lease_expires_at' => $held(299)]), $census, $NOW, 5)['stalled'] === true
        && ScanService::progressOf($row(['lease_expires_at' => $held(299)]), $census, $NOW, 11)['stalled'] === false
        && ScanService::progressOf($row([]), $census, $NOW, 5)['paused'] === true);
    // UTC IN, UTC OUT. The store stamps gmdate(); read back in a server zone
    // east of Greenwich, every run would look hours idle.
    $tz = date_default_timezone_get();
    date_default_timezone_set('Africa/Douala');
    $east = ScanService::progressOf($row([]), $census, $NOW);
    date_default_timezone_set('America/Los_Angeles');
    $west = ScanService::progressOf($row([]), $census, $NOW);
    date_default_timezone_set($tz);
    check('stall: the idle time does not depend on the server\'s time zone',
        $east['idleSeconds'] === 10 && $west['idleSeconds'] === 10);
    check('stall: the threshold is a sentence on a page, so it is in neither the policy nor the fingerprint',
        !array_key_exists('stallSeconds', ScanPolicy::resolve([], []))
        && ScanService::STALL_SECONDS === 120);
}

/* =====================================================================
 * OC-2  STATUS REPORTS WHAT PROMOTION DECIDED
 *
 * promote() stores clean, rule_problem_count and not_examined; status() returned
 * none of them, so the page could not tell a certified run from one refused
 * clean over a rule problem. decisionOf() is the part of status() that reads
 * them, and it is reachable without an entitlement.
 * ===================================================================== */
{
    $d = function (array $row) { return ScanService::decisionOf($row); };
    check('OC-2: a certified run reports clean as 1',
        $d(['clean' => '1', 'rule_problem_count' => '0', 'not_examined' => '0'])
            === ['clean' => 1, 'ruleProblems' => 0, 'notExamined' => 0]);
    check('OC-2: a run refused clean over a rule problem reports 0 and the count',
        $d(['clean' => '0', 'rule_problem_count' => '1', 'not_examined' => '0'])
            === ['clean' => 0, 'ruleProblems' => 1, 'notExamined' => 0]);
    check('OC-2: no decision stays NO decision - null, never a 0 that reads as "found something"',
        $d(['clean' => null, 'rule_problem_count' => '0', 'not_examined' => '0'])['clean'] === null
        && $d([])['clean'] === null);
    check('OC-2: records not examined are reported',
        $d(['clean' => '0', 'not_examined' => '3'])['notExamined'] === 3);
    check('OC-2: a value other than 1 is never read as certified',
        $d(['clean' => '2'])['clean'] === 0 && $d(['clean' => 'yes'])['clean'] === 0);
    // THE ROW status() IS GIVEN. A run finished by the real promotion over the
    // in-memory store: one record examined, nothing found, one rule problem.
    $ps = new ArrayScanStore();
    $prid = (int) $ps->startRun(830, ['created_by' => 'alice', 'fence_open' => '10'])['run']['run_id'];
    $ps->writeManifest($prid, [['id_bin' => 'R1', 'hash' => hash('sha256', 'R1', true)]]);
    $pep = (int) $ps->run(830, $prid)['lease_epoch'];
    $pc = $ps->claim($prid, 'w', $pep, 5);
    $ps->commitBatch($prid, 'w', $pep, ['bytes' => 0, 'findings' => [], 'records' => [
        ['ordinal' => $pc[0]['ordinal'], 'record_hash' => $pc[0]['hash'], 'claim' => $pc[0]['claim'],
         'state' => ScanStore::REC_DONE, 'version' => '500']]]);
    ScanPromotion::noteRuleProblems($ps, $prid, [['rule' => 1, 'fields' => ['a'], 'why' => 'x']]);
    $ps->setProgressState($prid, $pep, ['fenceTarget' => '500']);
    ScanPromotion::promote($ps, 830, $prid, ['uniqueDone' => true, 'rollupDone' => true]);
    $prow = $ps->run(830, $prid);
    check('OC-2: a run promotion refused over a rule problem reaches status() as not certified',
        $prow['terminal'] === ScanOutcome::COMPLETE
        && ScanService::decisionOf($prow) === ['clean' => 0, 'ruleProblems' => 1, 'notExamined' => 0]);
}

/* =====================================================================
 * OC-4  A RUN WITH NO VERDICT NEVER SAYS ZERO OVER FINDINGS IT HAS
 *
 * status() reports findingsOf(). For a cancelled, failed or expired run that
 * was the stored-row counter, which the uniqueness finalizer never moves: a run
 * cancelled during unique-finalize said "Nothing found yet" over the duplicates
 * it had written. The run row cannot say whether a summary was built, so a run
 * with no verdict answers with the larger of the counter and whatever summary
 * exists - and with NULL, "not counted", when both are zero.
 * ===================================================================== */
{
    $agg = new ArrayScanStore();
    $aid = (int) $agg->startRun(820, ['created_by' => 'alice'])['run']['run_id'];
    $bare = new ArrayScanStore();
    $bid = (int) $bare->startRun(821, ['created_by' => 'alice'])['run']['run_id'];
    foreach (RollupBuilder::KINDS as $kind) $agg->addAggregate($aid, $kind, 'x', null, 2);
    $as = function (ArrayScanStore $s, $pid, $id, array $over) {
        return ScanPromotion::findingsOf($s, array_merge($s->run($pid, $id), $over));
    };
    foreach (ScanOutcome::NO_VERDICT as $how) {
        check("OC-4: a $how run with nothing stored and nothing summarised is NOT COUNTED, never zero",
            $as($bare, 821, $bid, ['terminal' => $how, 'detail_rows' => 0]) === null);
        check("OC-4: a $how run whose summary holds findings the counter missed reports them",
            $as($agg, 820, $aid, ['terminal' => $how, 'detail_rows' => 0]) === 2);
        check("OC-4: and a $how run answers with the larger of the two, whichever it is",
            $as($agg, 820, $aid, ['terminal' => $how, 'detail_rows' => 5]) === 5
            && $as($bare, 821, $bid, ['terminal' => $how, 'detail_rows' => 3]) === 3);
    }
    check('OC-4: a run that reached a verdict still answers from its summary alone',
        $as($agg, 820, $aid, ['terminal' => ScanOutcome::COMPLETE, 'detail_rows' => 9]) === 2
        && $as($bare, 821, $bid, ['terminal' => ScanOutcome::COMPLETE, 'detail_rows' => 9]) === 0);
    check('OC-4: and a run still working reports its counter, as it did',
        $as($agg, 820, $aid, ['terminal' => null, 'detail_rows' => 4]) === 4);
}

/* =====================================================================
 * CL-5 / EE-3  THE SERVICE GIVES ITS WORKER A WAY TO TELL "DELETED"
 *
 * ScanService hands the worker an `exists` probe over the project's record
 * index, and the worker will only call a record it could not read a TOMBSTONE
 * when the probe confirms it is gone. Deleting that one line from the service
 * left every suite green: each worker test builds its own dependencies, and
 * the service's were reachable only through a module, a user, an entitlement
 * and a database. A record deleted mid-run then ended UNREADABLE, which caps
 * coverage at partial.
 *
 * So the worker below is built from the service's own dependencies
 * (workerDeps(), reached by reflection - it is private and stays so), over the
 * real RecordManifestSource on a stand-in record index. Three are replaced,
 * because they need tables this fixture does not have and are not under test:
 * the installation slots (null is unrationed), the uniqueness finalizer, and
 * the summary builder. The change log is absent, so there is no fence: the
 * deleted record leaves no log entry to be found by.
 * ===================================================================== */
{
    $cs = new ArrayScanStore();
    $cid = (int) $cs->startRun(149, ['created_by' => 'alice'])['run']['run_id'];
    $cs->writeManifest($cid, [['id_bin' => 'R001', 'hash' => hash('sha256', 'R001', true)],
                              ['id_bin' => 'GONE1', 'hash' => hash('sha256', 'GONE1', true)]]);
    $svcC = new ScanService(new \SourceModule());
    $depsOf = new \ReflectionMethod(ScanService::class, 'workerDeps');
    $depsOf->setAccessible(true);
    $ctxC = [
        // The export returns R001 and leaves GONE1 out, every time it is asked.
        'read' => function (array $ids) {
            $d = [];
            foreach ($ids as $id) if ((string) $id === 'R001') $d['R001'] = ['1' => ['a' => 'x']];
            return ['ok' => true, 'data' => $d, 'why' => null];
        },
        'evaluate' => function ($id, $node) {
            return ['findings' => [], 'bytes' => 0, 'contexts' => 1, 'why' => null];
        },
    ];
    $deps = $depsOf->invoke($svcC, 149, $cs, $cs->run(149, $cid), ScanPolicy::resolve([], []),
                            $ctxC, 'browser');
    check('CL-5: the service hands its worker an existence probe',
        isset($deps['exists']) && is_callable($deps['exists']));
    $deps = array_merge($deps, ['slots' => null, 'finalizer' => new \SvcNoDuplicates(),
                                'rollup' => null]);
    $cpasses = 0;
    do {
        $cpasses++;
        $cres = (new ScanWorker($cs, $deps))->work(149, $cid);
    } while (empty($cres['done']) && $cpasses < 20);
    $cstates = $cs->recordStates($cid);
    check('CL-5: a record missing from the export and from the project ends as a TOMBSTONE',
        isset($cstates[ScanStore::REC_TOMBSTONE]) && (int) $cstates[ScanStore::REC_TOMBSTONE] === 1
        && empty($cstates[ScanStore::REC_UNREADABLE]));
    check('CL-5: and the record that is there was examined',
        isset($cstates[ScanStore::REC_DONE]) && (int) $cstates[ScanStore::REC_DONE] === 1);
    $cp = ScanPromotion::promote($cs, 149, $cid, ['uniqueDone' => true, 'rollupDone' => true]);
    check('CL-5: so the run covers its whole list - a deleted record is not a hole',
        $cp['promoted'] === true && $cp['outcome']['coverage'] === ScanOutcome::MANIFEST
        && $cp['outcome']['coverage'] !== ScanOutcome::COV_PARTIAL);
}

/* =====================================================================
 * CL-6  THE LARGEST ATTEMPT SETTING STILL ENDS A RECORD
 *
 * The attempt counter saturates at 254 and the worker ends a record when
 * tries + 1 >= the setting, so `scan-system-record-attempts` = 300 made that
 * comparison false for ever: a record the read leaves out was requeued on every
 * pass, reported `requeued > 0`, and the browser read that as progress and asked
 * again at 0 ms. The policy is clamped; this drives the real worker under the
 * policy the setting resolves to, one record per pass, and asks that the record
 * that is gone from the project gets an ending.
 * ===================================================================== */
{
    $policy = ScanPolicy::resolve(['scan-system-record-attempts' => '300'], []);
    $store = new ArrayScanStore();
    $runId = (int) $store->startRun(800, ['created_by' => 'alice'])['run']['run_id'];
    $store->writeManifest($runId, [['id_bin' => 'A', 'hash' => hash('sha256', 'A', true)],
                                   ['id_bin' => 'GONE', 'hash' => hash('sha256', 'GONE', true)]]);
    $read = function ($ids) {
        $d = [];
        foreach ($ids as $id) if ($id !== 'GONE') $d[$id] = ['1' => ['x' => 'v']];
        return ['ok' => true, 'data' => $d, 'why' => null];
    };
    $passes = 0;
    $states = [];
    while ($passes < 400) {
        $passes++;
        $w = new ScanWorker($store, [
            'read' => $read, 'attempts' => $policy['recordAttempts'], 'owner' => 'w1',
            'evaluate' => function ($id, $node) {
                return ['findings' => [], 'bytes' => 0, 'contexts' => 1, 'why' => null];
            },
            'finalizer' => new \SvcNoDuplicates(),
            'exists' => function (array $ids) { return array_fill_keys($ids, false); },
            'budget' => new WorkBudget(['mode' => 'browser', 'memoryLimit' => null,
                'timeLimit' => null, 'min' => 1, 'max' => 1, 'first' => 1,
                'startedAt' => microtime(true) - 2.8])]);
        $res = $w->work(800, $runId);
        $states = $store->recordStates($runId);
        if (!empty($res['done'])) break;
    }
    check('CL-6: an attempt setting past the counter is held at the counter\'s ceiling',
        $policy['recordAttempts'] === 254);
    check('CL-6: and under it a record the project no longer holds reaches an ending',
        isset($states[ScanStore::REC_TOMBSTONE]) && (int) $states[ScanStore::REC_TOMBSTONE] === 1
        && empty($states[ScanStore::REC_PENDING]) && empty($states[ScanStore::REC_CLAIMED]));
    check('CL-6: in a bounded number of passes, not by running out of test',
        $passes < 400);
}
}

namespace {
    echo "scan_service_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
