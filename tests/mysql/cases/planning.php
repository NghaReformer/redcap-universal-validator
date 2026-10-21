<?php
/**
 * tests/mysql/cases/planning.php — from a project to a frozen manifest.
 *
 * Run against the real record source and the real store, because planning is
 * almost entirely the joins between them - a planner tested against a fake
 * source would prove that the fake agrees with the planner.
 *
 * TWO PROJECTS. 900 is under test and 901 is planted in the record index, the
 * data table and the log, with its newest log entry above every entry here. A
 * manifest total, an out-of-scope count and an opening fence are each a claim
 * about ONE project, and each of them is a plausible-looking number when it is
 * wrong.
 *
 * THE GROUP FILTER IS A LITERAL '7', AND THAT IS NOW THE PRODUCTION SHAPE. It
 * used to be a note about a defect: the page resolved $rights['group_id'] to the
 * friendly DAG name and stored that as scope_dag, while every consumer here
 * compares against redcap_record_list.dag_id - so a scoped run listed every
 * record, appended none, and promoted an empty manifest to a clean certificate.
 * Wave 6 moved the page onto the id axis, so ScanPageView::scanScope()['dag'] is
 * the numeric id and this literal is what it produces. Nothing to derive.
 */

use INSPIRE\UniversalValidator\Scan\Schema;

$PID = 900;
$NEIGHBOUR = uv_neighbour($PID);

{
    uv_redcap_schema($A);
    uv_redcap_log_schema($A);
    uv_redcap_project($A, $PID);
    uv_redcap_neighbour_records($A, $PID);
    // The log this project has already accumulated. The opening fence is its
    // top, and the assertion below names 190 exactly, so this is a fixture with
    // a number in it rather than a fixture with a shape.
    $A->query("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event) VALUES
        (130, " . $PID . ", NULL, 'DATA_EXPORT'), (140, " . $PID . ", 'D3', 'UPDATE'),
        (160, " . $PID . ", 'D2', 'UPDATE'),  (170, " . $PID . ", 'D4', 'INSERT'),
        (180, " . $PID . ", 'D2', 'UPDATE'),  (190, " . $PID . ", '', 'MANAGE')");
    uv_redcap_neighbour_log($A, $PID);

    for ($i = 1; $i <= 25; $i++) {
        $A->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES ("
            . $PID . ", '" . sprintf('P%03d', $i) . "', "
            . ($i % 3 === 0 ? '7' : 'NULL') . ')');
    }
    // The neighbour, in the store as well as in the record tables: a planner
    // that walked the whole installation would find its records, and a store
    // that started runs installation-wide would find its run.
    $nb = uv_plant_neighbour($dbA, $PID);
    check('plan: a second project has records and a run of its own',
        is_array($nb) && uv_neighbour_findings($dbA, $PID) === 2);

    $store = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA);
    $planner = new \INSPIRE\UniversalValidator\Scan\ScanPlanner($store, 'test-secret-key');
    $srcP = \INSPIRE\UniversalValidator\Scan\RecordManifestSource::open($dbA, $PID, array('pk' => 'record_id'));
    $fenceP = \INSPIRE\UniversalValidator\Scan\SourceFence::forProject($dbA, $PID);
    $baseReq = array(
        'source' => $srcP['source'],
        'fence' => $fenceP['ok'] ? $fenceP['fence'] : null,
        'rules' => array(array('type' => 'required', 'fields' => array('dob'))),
        'settingsCount' => 0,
        'ownership' => array('dob' => 'demographics'),
        'structure' => array('longitudinal' => false),
        'choices' => array(),
        'policy' => \INSPIRE\UniversalValidator\Scan\ScanPolicy::resolve(),
        'engine' => '1.8.20',
        'createdBy' => 'alice',
        'pageSize' => 10,
    );

    $res = $planner->plan($PID, $baseReq);
    check('plan: a project with records and rules plans', $res['ok'] === true);
    check('plan: every record reaches the manifest',
        (int) $res['run']['manifest_total'] === 25);
    check('plan: and the run is ready to be worked',
        $res['run']['phase'] === 'scanning');
    check('plan: the walk was paged rather than read whole', $res['stats']['pages'] >= 3);
    check('plan: the opening fence is captured BEFORE the manifest',
        $dbA->select('SELECT fence_open FROM ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run')
            . ' WHERE run_id = ?', array($res['run']['run_id']))[0][0] === '190');

    // The project slot is the mutual exclusion, and busy must disclose nothing.
    $busy = $planner->plan($PID, $baseReq);
    check('plan: a second start is busy rather than a second run',
        $busy['ok'] === false && $busy['busy'] === true && $busy['run'] === null);
    check('plan: and names neither the owner nor any number',
        strpos($busy['why'], 'alice') === false && preg_match('/[0-9]/', $busy['why']) === 0);

    $rid = (int) $res['run']['run_id'];
    $fp1 = $res['run']['fingerprint'];
    $store->finish($rid, \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));

    // A group-scoped run must carry a group-scoped manifest. Building the whole
    // project and filtering at display time is the leak the persisted store
    // creates.
    // THE SCOPE IS TAKEN FROM THE SOURCE, NOT WRITTEN DOWN. The literal '7' that
    // used to sit here injected the value the code is supposed to derive, so the
    // three assertions below proved the planner agrees with a constant rather
    // than with the record index. Asking dagsOf() for it first states the axis
    // as a property - the source reports a group as the numeric id REDCap
    // stores - and then feeds that answer to the consumer, which is the join a
    // hand-partitioned fixture can never make.
    $srcDags = $srcP['source']->dagsOf(array('P003', 'P001'));
    check('plan: the record index reports a group as the numeric id REDCap stores',
        $srcDags['P003'] === '7' && $srcDags['P001'] === null);
    $scoped = $planner->plan($PID, array_merge($baseReq, array('dagFilter' => $srcDags['P003'])));
    check('plan: a group-scoped run lists only that group',
        $scoped['ok'] === true && (int) $scoped['run']['manifest_total'] === 8);
    check('plan: and says how many records it left out',
        $scoped['stats']['outOfScope'] === 17);
    check('plan: the scope is stored on the run, not applied later',
        $scoped['run']['scope_dag'] === '7');
    $store->finish((int) $scoped['run']['run_id'],
        \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));

    // Editing a rule must change what a later run compares against.
    $moved = $planner->plan($PID, array_merge($baseReq, array(
        'rules' => array(array('type' => 'required', 'fields' => array('dob'),
                               'when' => '[age] > 18')))));
    check('plan: changing a rule changes the run fingerprint',
        $moved['ok'] === true && $moved['run']['fingerprint'] !== $fp1);
    $store->finish((int) $moved['run']['run_id'],
        \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));

    // A PLANNER THAT RUNS OUT OF BUDGET STOPS AT A PAGE AND CARRIES ON LATER
    // (B10). It used to finish its run failed - "did not finish within the
    // time this server allows" - so a project too big for one request could
    // never be scanned at all. The first page of a slice always runs, so a
    // request whose time was spent before planning began still moves the walk.
    $spent = function () {
        return new \INSPIRE\UniversalValidator\Scan\WorkBudget(
            array('mode' => 'browser', 'startedAt' => microtime(true) - 3600));
    };
    $slow = $planner->plan($PID, array_merge($baseReq, array('budget' => $spent())));
    $slowRun = $slow['run'];
    check('plan: running out of time is not a refusal: the run exists and is still planning',
        $slow['ok'] === true && $slowRun['phase'] === 'planning'
        && (int) $slowRun['manifest_total'] === 0);
    check('plan: and the slice listed exactly its one guaranteed page',
        $slow['stats']['pages'] === 1 && $slow['stats']['listed'] === 10);
    $slowId = (int) $slowRun['run_id'];
    $slices = 1;
    $last = null;
    while ($slices < 20) {
        $last = $planner->step($PID, $slowId, array_merge($baseReq, array(
            'budget' => $spent(), 'owner' => 'req' . $slices,
            'epoch' => (int) $slowRun['lease_epoch'], 'attempts' => 3, 'leaseSeconds' => 300)));
        $slices++;
        if (!empty($last['frozen']) || $last['phase'] !== 'planning') break;
    }
    check('plan: each later request lists one more page, and the last one freezes the manifest',
        $slices === 3 && !empty($last['frozen']) && $last['total'] === 25
        && $store->run($PID, $slowId)['phase'] === 'scanning');
    $store->finish($slowId, \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));
    check('plan: so the next attempt is not told the project is busy',
        $planner->plan($PID, $baseReq)['ok'] === true);
    $A->query('UPDATE ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run')
        . " SET active_slot = NULL, phase = 'terminal', terminal = 'cancelled'
            WHERE project_id = " . $PID . " AND active_slot = 1");

    // Records the server cannot tell apart are offered twice by the walk, on
    // purpose. They must land once.
    $A->query('DELETE FROM redcap_record_list');
    foreach (array('T1', 'T1 ', 'T1  ', 'T2') as $r) {
        $A->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES (" . $PID . ", '"
            . $r . "', NULL)");
    }
    $tie = $planner->plan($PID, array_merge($baseReq, array('pageSize' => 1)));
    check('plan: a re-offered page boundary does not duplicate a record',
        $tie['ok'] === true && (int) $tie['run']['manifest_total'] === 4);
    check('plan: and the walk reported offering more than it stored',
        $tie['stats']['listed'] >= $tie['stats']['appended']);
    $store->finish((int) $tie['run']['run_id'],
        \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));

    // A project with no rules is not a project with nothing wrong.
    //
    // Scoped, both the clearing and the count. "No run was created" used to be
    // asked of the whole table, which answers the same on a refusal as it does
    // on a schema that never had a run in it - and the neighbour's run, which
    // has nothing to do with this refusal, was what the blanket DELETE removed
    // to make that reading work.
    $A->query('DELETE FROM ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run')
        . ' WHERE project_id = ' . $PID);
    $noRules = $planner->plan($PID, array_merge($baseReq, array('rules' => array())));
    check('plan: a project with no rules is refused rather than certified',
        $noRules['ok'] === false
        && strpos($noRules['why'], 'no validation rules') !== false);
    // Interpolated rather than bound, and deliberately: mysqlnd hands back a
    // native int for a bound statement and a string for an unbound one, so
    // adding a placeholder here would have quietly changed what === compares.
    check('plan: and no run was created for it',
        $dbA->select('SELECT COUNT(*) FROM ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run')
            . ' WHERE project_id = ' . $PID)[0][0] === '0');
    check('plan: while the neighbouring project\'s run is left exactly where it was',
        $dbA->select('SELECT COUNT(*) FROM ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run')
            . ' WHERE project_id = ' . $NEIGHBOUR)[0][0] === '1');


    // AN EMPTY MANIFEST AGAINST A REAL STORE. freezeManifest() answers an INT,
    // so a census of zero passed the `=== false` guard, the run walked four
    // phases over nothing, and it promoted to complete-through-fence with clean
    // true - a certificate over nothing. The mocked suites use ArrayScanStore,
    // where the same case was green.
    $A->query('DELETE FROM redcap_record_list WHERE project_id = ' . $PID);
    $A->query('DELETE FROM ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run')
        . ' WHERE project_id = ' . $PID);
    $emptyPlan = $planner->plan($PID, $baseReq);
    check('plan: a project with no records is refused rather than certified',
        $emptyPlan['ok'] === false && $emptyPlan['busy'] === false
        && strpos($emptyPlan['why'], 'has no records') !== false);
    $emptyRow = $dbA->select('SELECT terminal, coverage FROM '
        . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run')
        . ' WHERE project_id = ' . $PID . ' AND active_slot IS NULL');
    check('plan: and the refused run is terminal, so it does not keep the project slot',
        isset($emptyRow[0]) && $emptyRow[0][0] === 'partial'
        && $emptyRow[0][1] === 'empty-scope');
    check('plan: the walk counters survive the refusal, so an operator can diagnose it',
        isset($emptyPlan['stats']['listed']) && (int) $emptyPlan['stats']['listed'] === 0);

    // A GROUP SCOPE THAT MATCHES NOTHING IS THE SAME HOLE, and it is the one a
    // scope compared on the wrong axis produces: the manifest is emptied
    // exactly as a genuinely empty group empties it, and the two are not
    // distinguishable from here.
    $A->query('DELETE FROM ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run')
        . ' WHERE project_id = ' . $PID);
    $A->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES ("
        . $PID . ", 'G1', 7)");
    $emptyDag = $planner->plan($PID, array_merge($baseReq, array('dagFilter' => '9')));
    check('plan: a group scope that matched no record is refused, not certified',
        $emptyDag['ok'] === false
        && strpos($emptyDag['why'], 'Data Access Group') !== false);
    check('plan: and the refusal does not assert the group is empty',
        strpos($emptyDag['why'], 'check the scan scope') !== false);
    check('plan: nor does it disclose how many records the project holds',
        preg_match('/[0-9]/', $emptyDag['why']) === 0);

}

/*
 * PLANNING ACROSS MANY REQUESTS, AT SCALE, WITH ONE OF THEM KILLED (B10).
 *
 * 20,002 records - 20,000 plus a pair the collation calls equal, placed so a
 * page boundary falls inside it - planned twice: once in one call, and once a
 * page per request through a budget that is already spent, with the seventh
 * request's connection killed between the page's INSERT and the write that
 * records where the walk stands. The two manifests must be the same rows at
 * the same ordinals: 1..N, no gap, no repeat. A page committed without its
 * cursor is re-listed by the next request and moves every later ordinal by a
 * page; a carry that does not survive between requests re-offers the tie and
 * leaves a gap. Either shows up as a difference here.
 */
{
    $BIG = 902;
    $NBIG = 20000;
    $T = function ($t) { return \INSPIRE\UniversalValidator\Scan\Schema::table($t); };
    uv_redcap_project($A, $BIG);
    for ($from = 1; $from <= $NBIG; $from += 1000) {
        $vals = array();
        for ($i = $from; $i < $from + 1000 && $i <= $NBIG; $i++) {
            $vals[] = '(' . $BIG . ", 'R" . sprintf('%06d', $i) . "', NULL)";
        }
        $A->query('INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES ' . implode(',', $vals));
    }
    // THE TIE: 'r001000' collates equal to 'R001000' under every _ci collation
    // in the matrix, and both sort after R000999 - so page one (1,000 rows)
    // ends inside the pair and the carry has to cross a request.
    $A->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES
        (" . $BIG . ", 'r001000', NULL), (" . $BIG . ", 'r015000', NULL)");
    $N = $NBIG + 2;
    $bigSrc = \INSPIRE\UniversalValidator\Scan\RecordManifestSource::open($dbA, $BIG, array('pk' => 'record_id'));
    $bigReq = uv_plan_request(array('source' => $bigSrc['source'], 'pageSize' => 1000));
    $count = function ($sql, array $p) use ($dbA) {
        $r = $dbA->select($sql, $p);
        return isset($r[0][0]) ? (int) $r[0][0] : -1;
    };

    // ONE CALL, the reference.
    $t0 = microtime(true);
    $one = (new \INSPIRE\UniversalValidator\Scan\ScanPlanner(
        new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA), 'test-secret-key'))
        ->plan($BIG, array_merge($bigReq, array('budget' => false)));
    $oneSecs = microtime(true) - $t0;
    $oneId = (int) $one['run']['run_id'];
    check('scale: the one-call plan lists every record', $one['ok'] === true
        && (int) $one['run']['manifest_total'] === $N);
    (new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA))->finish($oneId,
        \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));

    // A PAGE PER REQUEST. Each request is a fresh store and planner, as a fresh
    // PHP request would be, with a budget spent before it began.
    $spentBig = function () {
        return new \INSPIRE\UniversalValidator\Scan\WorkBudget(
            array('mode' => 'browser', 'startedAt' => microtime(true) - 3600));
    };
    $first = (new \INSPIRE\UniversalValidator\Scan\ScanPlanner(
        new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA), 'test-secret-key'))
        ->plan($BIG, array_merge($bigReq, array('budget' => $spentBig(), 'owner' => 'req0')));
    $bigId = (int) $first['run']['run_id'];
    $bigEpoch = (int) $first['run']['lease_epoch'];
    check('scale: the first request lists one page and leaves the run planning',
        $first['ok'] === true && $first['run']['phase'] === 'planning'
        && $count('SELECT COUNT(*) FROM ' . $T('scan_record') . ' WHERE run_id = ?', array($bigId)) === 1000);
    $slice = function ($db, $owner, $src = null) use ($BIG, $bigId, $bigEpoch, $bigReq, $spentBig) {
        $p = new \INSPIRE\UniversalValidator\Scan\ScanPlanner(
            new \INSPIRE\UniversalValidator\Scan\SqlScanStore($db), 'test-secret-key');
        return $p->step($BIG, $bigId, array_merge($bigReq, array('budget' => $spentBig(),
            'owner' => $owner, 'epoch' => $bigEpoch, 'attempts' => 3, 'leaseSeconds' => 300),
            $src === null ? array() : array('source' => $src)));
    };

    // The third connection, the one that is killed.
    $C = uv_connect(getenv('UV_DB_HOST') ?: '127.0.0.1', getenv('UV_DB_USER') ?: 'root',
        getenv('UV_DB_PASS') ?: '', $A->query('SELECT DATABASE()')->fetch_row()[0],
        (int) (getenv('UV_DB_PORT') ?: 3306));
    $iso = getenv('UV_DB_ISOLATION');
    if ($iso !== false && $iso !== '') {
        $C->query('SET SESSION TRANSACTION ISOLATION LEVEL ' . strtoupper(trim($iso)));
    }
    $killDb = new class(new MysqliDb($C), $A, (int) $C->thread_id, $T('scan_plan'))
        implements \INSPIRE\UniversalValidator\Scan\ScanDb {
        public $inner; public $killer; public $tid; public $table; public $killed = false;
        public function __construct($inner, $killer, $tid, $table) {
            $this->inner = $inner; $this->killer = $killer; $this->tid = $tid; $this->table = $table;
        }
        public function select($sql, array $params = []) { return $this->inner->select($sql, $params); }
        public function exec($sql, array $params = []) { $this->seam($sql); $this->inner->exec($sql, $params); }
        public function execNoCount($sql, array $params = []) { $this->seam($sql); $this->inner->execNoCount($sql, $params); }
        public function affected() { return $this->inner->affected(); }
        public function begin() { $this->inner->begin(); }
        public function commit() { $this->inner->commit(); }
        public function rollback() { $this->inner->rollback(); }
        // THE FAULT SEAM: every row of the page has been written, the write
        // that records where the walk stands is next, and the request's
        // connection dies before it is sent.
        private function seam($sql) {
            $s = ltrim($sql);
            $plan = stripos($s, 'UPDATE ' . $this->table) === 0
                 || stripos($s, 'INSERT INTO ' . $this->table) === 0;
            if ($this->killed || !$plan || strpos($sql, 'plan_cursor') === false) {
                return;                // the claim's attempt count is not the page's write
            }
            $this->killed = true;
            $this->killer->query('KILL CONNECTION ' . $this->tid);
            throw new \RuntimeException('the request died between the page write and the cursor write');
        }
    };

    $requests = 1;
    $threw = null;
    $before = null;
    $afterKill = null;
    $explainLog = null;
    $done = false;
    $where = function () use ($count, $T, $bigId) {
        return array(
            'rows' => $count('SELECT COUNT(*) FROM ' . $T('scan_record') . ' WHERE run_id = ?', array($bigId)),
            'pages' => $count('SELECT plan_pages FROM ' . $T('scan_plan') . ' WHERE run_id = ?', array($bigId)));
    };
    while ($requests < 60 && !$done) {
        if ($requests === 6) {
            $before = $where();
            try {
                $slice($killDb, 'killed');
            } catch (\Throwable $e) {
                $threw = $e;
            }
            $afterKill = $where();
            $requests++;
            continue;
        }
        if ($requests === 3) {
            // Every statement of one ordinary request, the source's included.
            $rec = new UvRecordingDb($dbA);
            $recSrc = \INSPIRE\UniversalValidator\Scan\RecordManifestSource::open($rec, $BIG,
                array('pk' => 'record_id'));
            $rec->log = array();
            $r = $slice($rec, 'req' . $requests, $recSrc['source']);
            $explainLog = $rec->log;
        } else {
            $r = $slice($dbA, 'req' . $requests);
        }
        $requests++;
        if ($requests === 8) {
            // Read after the killed request and before the one that follows
            // it got a page in: what the dead slice left behind.
            check('scale: the request after the killed one is held off by the dead lease until it lapses',
                $r['ok'] === true && $r['stop'] === 'held' && $r['listed'] === 0);
            check('scale: and the dead slice was counted as an attempt, by a write the kill could not undo',
                $count('SELECT plan_attempts FROM ' . $T('scan_plan') . ' WHERE run_id = ?', array($bigId)) === 1);
            // Waiting out the lease, without waiting.
            $A->query('UPDATE ' . $T('scan_run') . " SET lease_expires_at = '"
                . gmdate('Y-m-d H:i:s', time() - 5) . "' WHERE run_id = " . $bigId);
            continue;
        }
        $done = !empty($r['frozen']) || $r['phase'] !== 'planning';
    }
    check('scale: the seventh request really was killed in the seam',
        $killDb->killed === true && $threw instanceof \Throwable);
    check('scale: six pages were in before it', $before === array('rows' => 6000, 'pages' => 6));
    check('scale: and it committed neither the page nor where the walk stood',
        $afterKill === $before);
    $after = $dbA->select('SELECT phase, manifest_total FROM ' . $T('scan_run') . ' WHERE run_id = ?', array($bigId));
    check('scale: the walk resumed after the kill and froze the whole manifest',
        $done && $after[0][0] === 'scanning' && (int) $after[0][1] === $N);
    // 21 pages, each in a request of its own, plus the killed request and the
    // one the dead lease held off.
    check('scale: it took a page per request: ' . $requests . ' requests for ' . $N . ' records',
        $requests === 23 && $where()['pages'] === 21);

    // THE SAME MANIFEST AS THE ONE-CALL PLAN, row for row.
    $same = $count('SELECT COUNT(*) FROM ' . $T('scan_record') . ' a JOIN ' . $T('scan_record')
        . ' b ON b.run_id = ? AND b.ordinal = a.ordinal AND b.record_id_bin = a.record_id_bin
        WHERE a.run_id = ?', array($oneId, $bigId));
    check('scale: every record is at the same ordinal as in the one-call plan', $same === $N);
    foreach (array('one call' => $oneId, 'many requests' => $bigId) as $how => $rid) {
        $shape = $dbA->select('SELECT COUNT(*), MIN(ordinal), MAX(ordinal), COUNT(DISTINCT record_id_bin)
            FROM ' . $T('scan_record') . ' WHERE run_id = ?', array($rid));
        check('scale: ' . $how . ': ordinals 1..N with no gap and no record twice',
            (int) $shape[0][0] === $N && (int) $shape[0][1] === 1 && (int) $shape[0][2] === $N
            && (int) $shape[0][3] === $N);
    }

    // THE PAGE STATEMENTS, BY PLAN. Every statement one request sends for its
    // page is bounded by an index: the keyset page and its boundary group on
    // (project_id, record), the run and plan rows by primary key, and the
    // next ordinal as one dive on the manifest's primary key. Nothing re-reads
    // an earlier page, so the cost of a request does not grow with the walk.
    $explain = function ($sql, array $params) use ($A) {
        $q = trim(preg_replace('/\s+FOR UPDATE\s*$/i', '', $sql));
        $r = $A->query('EXPLAIN ' . uv_inline_params($A, $q, $params));
        $keys = array(); $extra = '';
        while ($row = $r->fetch_assoc()) {
            if (isset($row['key']) && $row['key'] !== null) $keys[] = (string) $row['key'];
            $extra .= isset($row['Extra']) ? (string) $row['Extra'] . ';' : ';';
        }
        $r->free();
        return array('keys' => $keys, 'extra' => $extra);
    };
    $seen = array();
    foreach ((array) $explainLog as $st) {
        list(, $sql, $params) = $st;
        $flat = preg_replace('/\s+/', ' ', $sql);
        if (strpos($flat, 'FROM redcap_record_list') !== false && strpos($flat, 'ORDER BY') !== false) {
            $kind = 'page';
        } elseif (strpos($flat, 'FROM redcap_record_list') !== false && strpos($flat, 'record = ?') !== false) {
            $kind = 'boundary';
        } elseif (strpos($flat, 'MAX(ordinal)') !== false) {
            $kind = 'next ordinal';
        } elseif (strpos($flat, 'FROM ' . $T('scan_run')) !== false) {
            $kind = 'run row';
        } elseif (strpos($flat, 'FROM ' . $T('scan_plan')) !== false) {
            $kind = 'plan row';
        } elseif (strpos($flat, 'UPDATE ' . $T('scan_plan')) === 0) {
            $kind = 'plan write';
        } elseif (strpos($flat, 'UPDATE ' . $T('scan_run')) === 0) {
            $kind = 'run write';
        } else {
            continue;
        }
        $e = $explain($sql, $params);
        $seen[$kind] = true;
        if ($kind === 'page' || $kind === 'boundary') {
            $cols = isset($e['keys'][0]) ? uv_index_columns($A, 'redcap_record_list', $e['keys'][0]) : array();
            check('scale: the ' . $kind . ' statement walks (project_id, record)',
                array_slice($cols, 0, 2) === array('project_id', 'record'));
            check('scale: and sorts nothing', stripos($e['extra'], 'filesort') === false);
        } elseif ($kind === 'next ordinal') {
            check('scale: the next ordinal is one dive on the primary key, not a scan of the manifest',
                stripos($e['extra'], 'optimized away') !== false || $e['keys'] === array('PRIMARY'));
        } else {
            // Every table by its primary key: the page's fence reads the run
            // row and its plan row in one join (review R5-9).
            check('scale: the ' . $kind . ' is found by its primary key',
                $e['keys'] !== array() && array_values(array_unique($e['keys'])) === array('PRIMARY'));
        }
    }
    check('scale: a request\'s page statements were all captured and asserted',
        count($seen) === 7);
    echo '  planning ' . $N . ' records: one call ' . sprintf('%.2f', $oneSecs) . " s\n";
    try { $C->close(); } catch (\Throwable $ignored) { }
}

/*
 * A PLANNING PHASE THAT IS MOVING IS NOT ABANDONED (B10).
 *
 * The abandoned-run sweep reads COALESCE(progress_at, created_at). A planning
 * run listing a big project over hours used to have no progress_at until the
 * freeze, so the sweep judged it on its age and expired it under the planner.
 * Every appended page is progress now.
 */
{
    $SW = 904;
    $T = function ($t) { return \INSPIRE\UniversalValidator\Scan\Schema::table($t); };
    uv_redcap_project($A, $SW);
    $A->query('INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES ('
        . $SW . ", 'S1', NULL), (" . $SW . ", 'S2', NULL), (" . $SW . ", 'S3', NULL)");
    $swSrc = \INSPIRE\UniversalValidator\Scan\RecordManifestSource::open($dbA, $SW, array('pk' => 'record_id'));
    $sw = (new \INSPIRE\UniversalValidator\Scan\ScanPlanner(
        new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA), 'test-secret-key'))
        ->plan($SW, uv_plan_request(array('source' => $swSrc['source'], 'pageSize' => 1,
            'budget' => new \INSPIRE\UniversalValidator\Scan\WorkBudget(
                array('startedAt' => microtime(true) - 3600)))));
    $swId = (int) $sw['run']['run_id'];
    $A->query('UPDATE ' . $T('scan_run') . " SET created_at = '"
        . gmdate('Y-m-d H:i:s', time() - 30 * 3600) . "' WHERE run_id = " . $swId);
    $ret = new \INSPIRE\UniversalValidator\Scan\ScanRetention($dbA);
    check('sweep: a planning run created thirty hours ago that appended a page just now is not expired',
        $sw['run']['phase'] === 'planning' && $ret->expireAbandoned(24) === 0
        && $dbA->select('SELECT phase FROM ' . $T('scan_run') . ' WHERE run_id = ?', array($swId))[0][0] === 'planning');
    $A->query('UPDATE ' . $T('scan_run') . " SET progress_at = '"
        . gmdate('Y-m-d H:i:s', time() - 25 * 3600) . "' WHERE run_id = " . $swId);
    check('sweep: one whose last page was twenty-five hours ago is',
        $ret->expireAbandoned(24) === 1);
}

/*
 * R3U-2: A LIVE PLANNER IS NOT TOLD IT STOPPED, AND A STOP ENDS THE WALK.
 *
 * Two connections. A plans; B is a second tab, sixteen minutes into the run
 * by the run's own clock (created_at moved back, not waited). The planning
 * clock is the last append, so B is told nothing alarming; and when B presses
 * Stop the walk ends at its next append and says the scan was stopped. It
 * used to read the rest of the project after the Stop and answer the Start
 * tab with "the record list could not be frozen".
 */
{
    $LIVE = 903;
    $T = function ($t) { return \INSPIRE\UniversalValidator\Scan\Schema::table($t); };
    uv_redcap_project($A, $LIVE);
    $vals = array();
    for ($i = 1; $i <= 5000; $i++) $vals[] = '(' . $LIVE . ", 'L" . sprintf('%05d', $i) . "', NULL)";
    foreach (array_chunk($vals, 1000) as $chunk) {
        $A->query('INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES ' . implode(',', $chunk));
    }
    $svc = new \INSPIRE\UniversalValidator\Scan\ScanService(new \stdClass());
    $stopRun = new \ReflectionMethod(\INSPIRE\UniversalValidator\Scan\ScanService::class, 'stopRun');
    $stopRun->setAccessible(true);
    $storeB = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbB);

    foreach (array('with the planner\'s lease' => true, 'with no lease, as the reviewer read it' => false)
             as $how => $withLease) {
        $hook = new class(new MysqliDb($A)) implements \INSPIRE\UniversalValidator\Scan\ScanDb {
            public $inner; public $onPage3; public $pages = 0; public $afterStop = 0; public $stopped = false;
            public function __construct($inner) { $this->inner = $inner; }
            public function select($sql, array $params = []) {
                if (strpos($sql, 'redcap_record_list') !== false && strpos($sql, 'ORDER BY') !== false) {
                    $this->pages++;
                    if ($this->stopped) $this->afterStop++;
                    if ($this->pages === 3 && $this->onPage3) { $f = $this->onPage3; $f(); }
                }
                return $this->inner->select($sql, $params);
            }
            public function exec($sql, array $params = []) { $this->inner->exec($sql, $params); }
            public function execNoCount($sql, array $params = []) { $this->inner->execNoCount($sql, $params); }
            public function affected() { return $this->inner->affected(); }
            public function begin() { $this->inner->begin(); }
            public function commit() { $this->inner->commit(); }
            public function rollback() { $this->inner->rollback(); }
        };
        $seen = array();
        $hook->onPage3 = function () use ($B, $storeB, $svc, $stopRun, $LIVE, $T, $hook, $withLease, &$seen) {
            $rid = (int) $B->query('SELECT run_id FROM ' . $T('scan_run') . ' WHERE project_id = '
                . $LIVE . ' AND active_slot = 1')->fetch_row()[0];
            $B->query('UPDATE ' . $T('scan_run') . ' SET created_at = created_at - INTERVAL 16 MINUTE'
                . ' WHERE run_id = ' . $rid);
            $run = $storeB->run($LIVE, $rid);
            $run['lease_expires_at'] = $withLease
                ? $B->query('SELECT lease_expires_at FROM ' . $T('scan_run') . ' WHERE run_id = ' . $rid)->fetch_row()[0]
                : null;
            $seen['rid'] = $rid;
            $seen['rows'] = (int) $B->query('SELECT COUNT(*) FROM ' . $T('scan_record')
                . ' WHERE run_id = ' . $rid)->fetch_row()[0];
            $seen['progress'] = \INSPIRE\UniversalValidator\Scan\ScanService::progressOf($run,
                $storeB->recordStates($rid), time(), 120);
            $seen['stop'] = $stopRun->invoke($svc, $storeB, $LIVE, $rid, 'bob', $run, time());
            $seen['phaseAfterStop'] = $storeB->run($LIVE, $rid)['phase'];
            $hook->stopped = true;
        };
        $src = \INSPIRE\UniversalValidator\Scan\RecordManifestSource::open($hook, $LIVE, array('pk' => 'record_id'));
        $hook->pages = 0;          // open()'s probe is not a page of the walk
        $res = (new \INSPIRE\UniversalValidator\Scan\ScanPlanner(
            new \INSPIRE\UniversalValidator\Scan\SqlScanStore($hook), 'test-secret-key'))
            ->plan($LIVE, uv_plan_request(array('source' => $src['source'], 'pageSize' => 1000,
                'budget' => false)));
        $p = $seen['progress'];
        check('R3U-2 ' . $how . ': a planner two pages in, on a run sixteen minutes old, is neither stalled nor paused',
            !array_key_exists('planningStopped', $p) && $p['stalled'] === false && $p['paused'] === false);
        check('R3U-2 ' . $how . ': Stop is honoured: '
            . ($withLease ? 'left for the planner to finish' : 'ended at once'),
            $seen['stop']['ok'] === true
            && ($withLease ? ($seen['stop']['stopped'] === false && $seen['phaseAfterStop'] === 'cancelling')
                           : ($seen['stop']['stopped'] === true && $seen['phaseAfterStop'] === 'terminal')));
        $rid = $seen['rid'];
        $final = $storeB->run($LIVE, $rid);
        check('R3U-2 ' . $how . ': the walk stops at its next append and reads no further page',
            $hook->afterStop === 0
            && (int) $B->query('SELECT COUNT(*) FROM ' . $T('scan_record') . ' WHERE run_id = ' . $rid)
                ->fetch_row()[0] === $seen['rows']);
        check('R3U-2 ' . $how . ': and says the scan was stopped, which it was',
            $res['ok'] === false
            && $res['why'] === \INSPIRE\UniversalValidator\Scan\ScanPlanner::PLAN_STOPPED
            && $final['phase'] === 'terminal' && $final['terminal'] === 'cancelled');
    }
}

/*
 * R5-6: A SLICE WHOSE PAGE THE STORE REFUSED HANDS ITS LEASE BACK.
 *
 * The page's write of where the walk stands fails as a lock wait would, on
 * a connection that stays usable. The transaction rolls back, the planner
 * hears ScanStoreUnavailable - and the lease it held used to stay with it,
 * so the next request was told for five minutes that another request was
 * listing the project.
 */
{
    $LOCKED = 920;
    $T = function ($t) { return \INSPIRE\UniversalValidator\Scan\Schema::table($t); };
    uv_redcap_project($A, $LOCKED);
    $vals = array();
    for ($i = 1; $i <= 30; $i++) $vals[] = '(' . $LOCKED . ", 'K" . sprintf('%03d', $i) . "', NULL)";
    $A->query('INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES ' . implode(',', $vals));
    $lockSrc = \INSPIRE\UniversalValidator\Scan\RecordManifestSource::open($dbA, $LOCKED, array('pk' => 'record_id'));
    $spentL = function () {
        return new \INSPIRE\UniversalValidator\Scan\WorkBudget(
            array('mode' => 'browser', 'startedAt' => microtime(true) - 3600));
    };
    $lockReq = uv_plan_request(array('source' => $lockSrc['source'], 'pageSize' => 10));
    $lp = (new \INSPIRE\UniversalValidator\Scan\ScanPlanner(
        new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA), 'test-secret-key'))
        ->plan($LOCKED, array_merge($lockReq, array('budget' => $spentL(), 'owner' => 'req0')));
    $lockId = (int) $lp['run']['run_id'];
    $lockEpoch = (int) $lp['run']['lease_epoch'];
    $waits = new class(new MysqliDb($A), $T('scan_plan')) implements \INSPIRE\UniversalValidator\Scan\ScanDb {
        public $inner; public $table; public $failed = 0;
        public function __construct($inner, $table) { $this->inner = $inner; $this->table = $table; }
        public function select($sql, array $params = []) { return $this->inner->select($sql, $params); }
        public function exec($sql, array $params = []) { $this->seam($sql); $this->inner->exec($sql, $params); }
        public function execNoCount($sql, array $params = []) { $this->seam($sql); $this->inner->execNoCount($sql, $params); }
        public function affected() { return $this->inner->affected(); }
        public function begin() { $this->inner->begin(); }
        public function commit() { $this->inner->commit(); }
        public function rollback() { $this->inner->rollback(); }
        // The page's write of where the walk stands, refused once.
        private function seam($sql) {
            if ($this->failed === 0 && stripos(ltrim($sql), 'UPDATE ' . $this->table) === 0
                    && strpos($sql, 'plan_cursor') !== false) {
                $this->failed++;
                throw new \RuntimeException('Lock wait timeout exceeded; try restarting transaction');
            }
        }
    };
    $lockThrew = null;
    try {
        (new \INSPIRE\UniversalValidator\Scan\ScanPlanner(
            new \INSPIRE\UniversalValidator\Scan\SqlScanStore($waits), 'test-secret-key'))
            ->step($LOCKED, $lockId, array_merge($lockReq, array('budget' => $spentL(),
                'owner' => 'tabA', 'epoch' => $lockEpoch, 'attempts' => 3, 'leaseSeconds' => 300)));
    } catch (\Throwable $e) {
        $lockThrew = $e;
    }
    check('R5-6: the page write was refused, and the planner heard it as the storage failing',
        $waits->failed === 1
        && $lockThrew instanceof \INSPIRE\UniversalValidator\Scan\ScanStoreUnavailable);
    $after = $dbA->select('SELECT lease_owner FROM ' . $T('scan_run') . ' WHERE run_id = ?', array($lockId));
    check('R5-6: nothing of the page was kept, and the lease went back with it',
        array_key_exists(0, $after) && $after[0][0] === null
        && (int) $dbA->select('SELECT COUNT(*) FROM ' . $T('scan_record') . ' WHERE run_id = ?',
            array($lockId))[0][0] === 10);
    $next = (new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA))->claimPlan($lockId, 'tabA-continue',
        $lockEpoch, 300, 3);
    check('R5-6: so the next request takes the walk on, rather than being told it is held',
        is_array($next) && $next['state'] === 'ok' && $next['pages'] === 1 && $next['attempts'] === 2);
    (new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA))->finish($lockId,
        \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));
}
