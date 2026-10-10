<?php
/**
 * unique_also_scan_php.php — the durable scan's @UVUNIQUE lookups.
 *
 * A lookup is a value a unique rule looks in rather than checks: a value in
 * an "also" field, or a value of a record the rule's "when" does not check
 * (collectUniqueLookups). It joins the duplicate groups of the rule: it counts
 * towards a group's records and is re-read with them, and it is never a
 * finding. The store marks it in uv_unique_candidate.lookup (schema version
 * 3), so:
 *
 *   - discover() settles a group with no checked value without verifying it;
 *   - emit() writes findings for checked values only (lookup = 0);
 *   - verify() re-reads every candidate where it is stored, by its form: a
 *     repeating instrument keeps instance 1 in repeat_instances, not in the
 *     event's base row.
 *
 * The full scan (scanProject), the endpoint and durableEvaluateRecord are
 * covered by hook_php.php; the SQL itself by tests/mysql/cases/uniqueness.php.
 *
 * Run:  php tests/unique_also_scan_php.php
 */

namespace {
    require_once __DIR__ . '/../php/Logic.php';
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

    use INSPIRE\UniversalValidator\Scan\ScanService;
    use INSPIRE\UniversalValidator\Scan\SqlScanStore;
    use INSPIRE\UniversalValidator\Scan\UniqueFinalizer;

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    /**
     * Answers the finalizer's three reads from canned rows and records every
     * statement with its parameters: discovery's GROUP BY ($groups), the
     * verify page ($verify) and the emit page ($emit, which the SQL itself
     * would filter to lookup = 0 - the canned rows are the rows MySQL returns).
     */
    class AlsoRecordingDb implements \INSPIRE\UniversalValidator\Scan\ScanDb
    {
        public $groups = [];
        public $verify = [];
        public $emit = [];
        public $selects = [];
        public $writes = [];
        public function select($sql, array $params = []) {
            $this->selects[] = [$sql, $params];
            if (strpos($sql, 'SELECT MAX(group_hmac)') !== false) return [[null]];
            if (strpos($sql, 'COUNT(DISTINCT record_hash)') !== false) return $this->groups;
            $after = isset($params[3]) ? (int) $params[3] : 0;
            $page = strpos($sql, 'version_scanned, host_form') !== false ? $this->verify
                  : (strpos($sql, 'rule_source_id, rule_revision') !== false ? $this->emit : []);
            return array_values(array_filter($page, function ($r) use ($after) { return $r[0] > $after; }));
        }
        public function exec($sql, array $params = []) { $this->writes[] = [$sql, $params]; }
        public function affected() { return 1; }
        public function begin() {}
        public function commit() {}
        public function rollback() {}
    }
    $key = str_repeat('k', 32);

    // ---- discover: a group of lookups only is settled, never verified ----
    $db = new AlsoRecordingDb();
    $db->groups = [
        [str_repeat("\1", 32), 2, 11, 0],   // two records, one value checked: a duplicate to verify
        [str_repeat("\2", 32), 2, 21, 1],   // two records, both lookups: nothing to report
        [str_repeat("\3", 32), 1, 31, 0],   // one record
    ];
    $fin = new UniqueFinalizer($db, ['pid' => 149, 'hmacKey' => $key]);
    check('discover: three groups written', $fin->discover(1, 100) === 3);
    $groupSql = '';
    foreach ($db->selects as $s) if (strpos($s[0], 'COUNT(DISTINCT record_hash)') !== false) $groupSql = $s[0];
    check('discover: asks whether each group holds a checked value', strpos($groupSql, 'MIN(lookup)') !== false);
    $ins = array_values(array_filter($db->writes, function ($w) { return strpos($w[0], 'INSERT INTO uv_unique_group') !== false; }));
    $phases = [];
    if ($ins) for ($i = 3; $i < count($ins[0][1]); $i += 5) $phases[] = $ins[0][1][$i];
    check('discover: only the group with a checked value is verified',
        $phases === [UniqueFinalizer::G_NEW, UniqueFinalizer::G_SINGLETON, UniqueFinalizer::G_SINGLETON]);

    // ---- emit: findings for checked values only ----
    $rev = str_repeat('a', 64);
    $db = new AlsoRecordingDb();
    $db->emit = [[11, str_repeat("\1", 32), '9', 351, 1, 'reg', 'typed_id', 'annotation:typed', $rev]];
    $fin = new UniqueFinalizer($db, ['pid' => 149, 'hmacKey' => $key]);
    $g = ['group_id' => 5, 'group_hmac' => str_repeat("\3", 32), 'candidate_epoch' => 2, 'emit_cursor' => 0];
    $r = $fin->emit(1, $g, 100);
    $emitSql = '';
    foreach ($db->selects as $s) if (strpos($s[0], 'rule_source_id, rule_revision') !== false) $emitSql = $s[0];
    check('emit: the page is read without lookups', strpos($emitSql, 'AND lookup = 0') !== false);
    $inserts = array_values(array_filter($db->writes, function ($w) { return strpos($w[0], 'INSERT INTO') !== false; }));
    check('emit: one finding written for the checked value', $r['emitted'] === 1 && count($inserts) === 1
        && in_array('typed_id', $inserts[0][1], true) && in_array('9', $inserts[0][1], true));
    $cursor = array_values(array_filter($db->writes, function ($w) { return strpos($w[0], 'SET emit_cursor') !== false; }));
    check('emit: the cursor moves to the last finding', $cursor && $cursor[0][1][0] === 11);
    $db = new AlsoRecordingDb();
    $fin = new UniqueFinalizer($db, ['pid' => 149, 'hmacKey' => $key]);
    $r = $fin->emit(1, $g, 100);
    check('emit: no checked value left publishes the group', $r['emitted'] === 0 && $r['published'] === true);

    // ---- verify: every candidate re-read, each with its form ----
    $db = new AlsoRecordingDb();
    $db->verify = [
        [41, '9', 351, 1, 'typed_id', 'v1', 'reg'],
        [42, '1', 351, 1, 'scan_lab', 'v1', 'lab'],    // a lookup on a repeating form, instance 1
    ];
    $asked = null;
    $fin = new UniqueFinalizer($db, ['pid' => 149, 'hmacKey' => $key,
        'read' => function (array $locs) use (&$asked) {
            $asked = $locs;
            $out = [];
            foreach ($locs as $l) $out[UniqueFinalizer::locKey($l)] = ['=p-001'];
            return ['ok' => true, 'values' => $out];
        }]);
    $vg = ['group_id' => 6, 'group_hmac' => str_repeat("\4", 32), 'candidate_epoch' => 1,
           'verify_cursor' => 0, 'representative' => null];
    $r = $fin->verify(1, $vg, 100);
    check('verify: both candidates re-read and equal', $r['checked'] === 2 && $r['collision'] === false);
    check('verify: each location carries the form it is stored on',
        $asked !== null && $asked[0]['form'] === 'reg' && $asked[1]['form'] === 'lab');

    // ---- the re-read finds a value where it is stored ----
    $data = [
        '1' => [
            351 => ['record_id' => '1', 'scan_id' => 'P-001', 'scan_lab' => '', 'ev_id' => ''],
            'repeat_instances' => [
                351 => ['lab' => [1 => ['scan_lab' => 'P-001'], 2 => ['scan_lab' => 'P-002']]],
                352 => ['' => [1 => ['ev_id' => 'E-1'], 3 => ['ev_id' => 'E-3']]],
            ],
        ],
    ];
    $got = ['ok' => true, 'data' => $data];
    $loc = function ($field, $form, $inst, $ev = 351) {
        return ['record' => '1', 'event_id' => $ev, 'instance' => $inst, 'field' => $field, 'form' => $form];
    };
    $read = function (array $l) use ($got) {
        $r = ScanService::rereadValues($got, [$l], []);
        $k = UniqueFinalizer::locKey($l);
        return isset($r['values'][$k]) ? $r['values'][$k][0] : null;
    };
    check('re-read: a base-form value comes from the base row', $read($loc('scan_id', 'reg', 1)) === '=p-001');
    check('re-read: instance 1 of a repeating form comes from repeat_instances, not the blank base row',
        $read($loc('scan_lab', 'lab', 1)) === '=p-001');
    check('re-read: a later instance of a repeating form', $read($loc('scan_lab', 'lab', 2)) === '=p-002');
    check('re-read: an instance that is not there is absent, not blank', $read($loc('scan_lab', 'lab', 5)) === null);
    check('re-read: a repeating event keeps its instances under ""',
        $read($loc('ev_id', 'visit', 1, 352)) === '=e-1' && $read($loc('ev_id', 'visit', 3, 352)) === '=e-3');
    $noForm = $loc('scan_lab', null, 2);
    check('re-read: a location without a form is read as before', $read($noForm) === '=p-002');

    // ---- the store writes the lookup flag ----
    $db = new AlsoRecordingDb();
    $store = new SqlScanStore($db);
    $insert = new \ReflectionMethod($store, 'insertCandidate');
    $insert->setAccessible(true);
    $cand = ['project_id' => 149, 'generation_id' => 1, 'rule_source_id' => 'annotation:typed', 'rule_revision' => $rev,
             'group_hmac' => str_repeat("\1", 32), 'scope_key' => 'project', 'record_hash' => str_repeat("\5", 32),
             'record_id_bin' => '1', 'event_id' => 351, 'instance' => 1, 'host_form' => 'reg', 'field' => 'scan_id'];
    $insert->invoke($store, $cand + ['lookup' => 1]);
    $insert->invoke($store, $cand);
    check('store: the lookup column is written', strpos($db->writes[0][0], 'version_scanned, lookup)') !== false
        && strpos($db->writes[0][0], 'lookup = VALUES(lookup)') !== false);
    check('store: 1 for a lookup, 0 for a checked value', end($db->writes[0][1]) === 1 && end($db->writes[1][1]) === 0);

    // ---- the re-read compares values the way their group was keyed ----
    $p = ['uniqueExact' => ['typed_id' => true, 'scan_id' => true], 'numberMarks' => ['n_a' => 'point', 'n_b' => 'point']];
    check('re-read: exact fields keep letter case on both sides',
        ScanService::comparableValue('Ab-1', 'typed_id', $p) === ScanService::comparableValue('Ab-1', 'scan_id', $p)
        && ScanService::comparableValue('Ab-1', 'typed_id', $p) !== ScanService::comparableValue('ab-1', 'scan_id', $p));
    check('re-read: number fields compare by value on both sides',
        ScanService::comparableValue('007', 'n_a', $p) === ScanService::comparableValue('7', 'n_b', $p));

    echo sprintf("unique_also_scan_php: %d checks, %d failure(s)\n", $n, $fail);
    exit($fail === 0 ? 0 : 1);
}
