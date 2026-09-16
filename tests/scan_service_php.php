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
                return [];                        // no data table on this build
            }
            if (strpos($sql, 'redcap_projects') !== false) return [];
            if (strpos($sql, 'FROM redcap_record_list') === false) return [];

            // boundaryGroup(): every id equal to one id.
            if (strpos($sql, 'record = ?') !== false) {
                $want = (string) $params[count($params) - 1];
                $out = [];
                foreach ($this->rows as $r) if ($r[0] === $want) $out[] = [$r[0]];
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
        public function affected() { return 0; }
        public function begin() {}
        public function commit() {}
        public function rollback() {}
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
}

namespace {
    echo "scan_service_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
