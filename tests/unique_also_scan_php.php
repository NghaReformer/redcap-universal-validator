<?php
/**
 * unique_also_scan_php.php — the durable scan and @UVUNIQUE "also".
 *
 * A value saved in an "also" field joins the duplicate groups of the rule
 * that searches it: it counts towards the group's records and is re-read
 * like any candidate, but it is never a finding, because the rule checks its
 * own fields and not the fields it searches. The worker stores such a value
 * as an ordinary candidate (the store has no column that says otherwise), so
 * the line is drawn where candidates become findings: UniqueFinalizer::emit
 * asks ScanService's 'reportable' dependency, built from the plan's rules.
 *
 * The full scan (scanProject) and the endpoint are covered by hook_php.php;
 * the SQL itself by tests/mysql/cases/uniqueness.php.
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
    use INSPIRE\UniversalValidator\Scan\UniqueFinalizer;

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    /** Answers the emit page from $page and records every write with its parameters. */
    class AlsoRecordingDb implements \INSPIRE\UniversalValidator\Scan\ScanDb
    {
        public $page = [];
        public $writes = [];
        public function select($sql, array $params = []) {
            if (strpos($sql, 'host_form, field, rule_source_id') !== false) {
                $after = (int) $params[3];
                return array_values(array_filter($this->page, function ($r) use ($after) { return $r[0] > $after; }));
            }
            return [];
        }
        public function exec($sql, array $params = []) { $this->writes[] = [$sql, $params]; }
        public function affected() { return 1; }
        public function begin() {}
        public function commit() {}
        public function rollback() {}
    }

    // ---- which candidates are findings ----
    // Rule 0 checks typed_id and searches scan_id; rule 1 checks scan_id and searches typed_id.
    $plan = [
        'live' => [
            ['type' => 'unique', 'fields' => ['typed_id'], 'uniqueAlso' => ['scan_id']],
            ['type' => 'unique', 'fields' => ['scan_id', 'other_id'], 'uniqueAlso' => ['typed_id']],
        ],
        'ruleIds' => [
            ['source_id' => 'annotation:typed', 'revision' => str_repeat('a', 64)],
            ['source_id' => 'annotation:scan', 'revision' => str_repeat('b', 64)],
        ],
    ];
    $hosts = ScanService::ruleHostFields($plan);
    check('host fields per rule source id', $hosts === [
        'annotation:typed' => ['typed_id' => true],
        'annotation:scan'  => ['scan_id' => true, 'other_id' => true],
    ]);
    check('a rule\'s own field is reported', ScanService::reportableCandidate($hosts, 'annotation:typed', 'typed_id'));
    check('an "also" field is not reported under the rule that searched it',
        !ScanService::reportableCandidate($hosts, 'annotation:typed', 'scan_id'));
    check('the same field is reported under its own rule', ScanService::reportableCandidate($hosts, 'annotation:scan', 'scan_id'));
    check('a rule the plan does not name is reported, never silenced',
        ScanService::reportableCandidate($hosts, 'unnamed:7', 'scan_id'));
    check('a plan with no rules names none', ScanService::ruleHostFields([]) === []
        && ScanService::ruleHostFields(['live' => [['fields' => ['x']]]]) === []);

    // ---- emit writes findings for the rule's own fields only ----
    $rev = str_repeat('a', 64);
    $db = new AlsoRecordingDb();
    $db->page = [
        [11, str_repeat("\1", 32), '9', 351, 1, 'reg', 'typed_id', 'annotation:typed', $rev],
        [12, str_repeat("\2", 32), '1', 351, 1, 'reg', 'scan_id', 'annotation:typed', $rev],   // the "also" value
    ];
    $fin = new UniqueFinalizer($db, ['pid' => 149, 'hmacKey' => str_repeat('k', 32),
        'reportable' => function ($src, $field) use ($hosts) { return ScanService::reportableCandidate($hosts, $src, $field); }]);
    $g = ['group_id' => 5, 'group_hmac' => str_repeat("\3", 32), 'candidate_epoch' => 2, 'emit_cursor' => 0];
    $r = $fin->emit(1, $g, 100);
    $inserts = array_values(array_filter($db->writes, function ($w) { return strpos($w[0], 'INSERT INTO') !== false; }));
    check('emit: one finding written', $r['emitted'] === 1 && count($inserts) === 1);
    check('emit: the finding is the typed ID, not the scanned value it repeats',
        in_array('typed_id', $inserts[0][1], true) && !in_array('scan_id', $inserts[0][1], true)
        && in_array('9', $inserts[0][1], true) && !in_array('1', $inserts[0][1], true));
    $cursor = array_values(array_filter($db->writes, function ($w) { return strpos($w[0], 'SET emit_cursor') !== false; }));
    check('emit: the cursor moves past the unreported candidate', $cursor && $cursor[0][1][0] === 12);

    // a page of "also" values only: nothing written, the cursor still moves
    $db = new AlsoRecordingDb();
    $db->page = [[21, str_repeat("\2", 32), '1', 351, 1, 'reg', 'scan_id', 'annotation:typed', $rev]];
    $fin = new UniqueFinalizer($db, ['pid' => 149, 'hmacKey' => str_repeat('k', 32),
        'reportable' => function ($src, $field) use ($hosts) { return ScanService::reportableCandidate($hosts, $src, $field); }]);
    $r = $fin->emit(1, $g, 100);
    check('emit: a page of "also" values writes no finding', $r['emitted'] === 0 && $r['published'] === false
        && !array_filter($db->writes, function ($w) { return strpos($w[0], 'INSERT INTO') !== false; }));
    check('emit: and moves the cursor past them',
        count($db->writes) === 1 && strpos($db->writes[0][0], 'SET emit_cursor') !== false && $db->writes[0][1][0] === 21);

    // without the dependency every candidate is a finding, as before
    $db = new AlsoRecordingDb();
    $db->page = [
        [31, str_repeat("\1", 32), '9', 351, 1, 'reg', 'typed_id', 'annotation:typed', $rev],
        [32, str_repeat("\2", 32), '1', 351, 1, 'reg', 'scan_id', 'annotation:typed', $rev],
    ];
    $fin = new UniqueFinalizer($db, ['pid' => 149, 'hmacKey' => str_repeat('k', 32)]);
    $r = $fin->emit(1, $g, 100);
    check('emit without "reportable": every candidate is written', $r['emitted'] === 2);

    // ---- the re-read compares an "also" value the way its group was keyed ----
    $p = ['uniqueExact' => ['typed_id' => true, 'scan_id' => true], 'numberMarks' => ['n_a' => 'point', 'n_b' => 'point']];
    check('re-read: exact fields keep letter case on both sides',
        ScanService::comparableValue('Ab-1', 'typed_id', $p) === ScanService::comparableValue('Ab-1', 'scan_id', $p)
        && ScanService::comparableValue('Ab-1', 'typed_id', $p) !== ScanService::comparableValue('ab-1', 'scan_id', $p));
    check('re-read: number fields compare by value on both sides',
        ScanService::comparableValue('007', 'n_a', $p) === ScanService::comparableValue('7', 'n_b', $p));

    echo sprintf("unique_also_scan_php: %d checks, %d failure(s)\n", $n, $fail);
    exit($fail === 0 ? 0 : 1);
}
