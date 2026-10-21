<?php
/**
 * temporal_delta_live_check.php - run the REAL module over the @UVDELTA live
 * fixture (docs/testbed/uvdelta_test_fields.csv) on a copy of pid 149's shape,
 * so every server-side "Expect" of docs/testbed/DELTA_LIVE_ACCEPTANCE.md is the
 * module's own answer.
 *
 * It checks the configuration errors, the page fold of each rule, the audit of
 * each save the sheet makes and the scan, and writes the page configs to
 * temporal_delta_live_pages.json in the system temp folder for tools/temporal_delta_live_check.cjs,
 * which replays the sheet's typing through js/engine.js.
 *
 * RUN:  php tools/temporal_delta_live_check.php && node tools/temporal_delta_live_check.cjs
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $logCalls = []; public $projectSettings = [];
        public function getSubSettings($k, $pid = null) { return []; }
        public function getProjectSetting($k, $pid = null) { return $this->projectSettings[$k] ?? null; }
        public function getSystemSetting($k) { return $k === 'log-hmac-key' ? str_repeat('a', 64) : null; }
        public function setSystemSetting($k, $v) {}
        public function getProjectId() { return 149; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return count($this->logCalls); }
        public function initializeJavascriptModuleObject() { return '<script></script>'; }
        public function getJavascriptModuleObjectName() { return 'EM.T.UV'; }
        public function getUser() { return new TestUser('tester'); }
    }
    class TestUser {
        private $n; public function __construct($n) { $this->n = $n; }
        public function getUsername() { return $this->n; }
        public function hasDesignRights() { return true; }
        public function getRights($pid = null) { return \REDCap::$rights['tester'] ?? null; }
    }
}

namespace {
    class REDCap {
        public static $data = []; public static $dictionary = []; public static $rights = []; public static $mappings = null;
        public static function getInstrumentEventMappings($pid = null) { return self::$mappings; }
        public static function getData($p) {
            $d = self::$data;
            if (isset($p['records'])) $d = array_intersect_key($d, array_fill_keys(array_map('strval', (array) $p['records']), true));
            return $d;
        }
        public static function getDataDictionary($pid, $f = 'array') { return self::$dictionary; }
        public static function getUserRights($pid = null, $user = null) { return self::$rights; }
        public static function getGroupNames($u = false, $g = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
    }
    /** pid 149, arm 1: event ids as on chpr-redcap.org. */
    class Project {
        public $project = ['missing_data_codes' => ''];
        public $longitudinal = true;
        public $eventInfo = [
            351 => ['unique_event_name' => 'event_1_arm_1', 'arm_num' => 1],
            379 => ['unique_event_name' => 'baseline_arm_1', 'arm_num' => 1],
            380 => ['unique_event_name' => 'visit_1_arm_1', 'arm_num' => 1],
            381 => ['unique_event_name' => 'visit_2_arm_1', 'arm_num' => 1],
            382 => ['unique_event_name' => 'unscheduled_arm_1', 'arm_num' => 1],
            383 => ['unique_event_name' => 'followup_arm_1', 'arm_num' => 1],
        ];
        public $eventsForms = [
            351 => ['xe_enrol'],
            379 => ['xe_visit', 'xr_specimen', 'uv_delta_test'],
            380 => ['xe_visit', 'xr_specimen', 'xr_result', 'uv_delta_test'],
            381 => ['xe_visit', 'xr_result', 'uv_delta_test'],
            382 => ['xe_unsched'],
            383 => ['xe_visit', 'xe_close'],
        ];
        public function __construct($pid) {}
        public function getRepeatingFormsEvents() {
            return [379 => ['xr_specimen' => ''], 380 => ['xr_specimen' => '', 'xr_result' => ''], 381 => ['xr_result' => ''], 382 => 'WHOLE'];
        }
        public function getUniqueEventNames() { return array_map(function ($r) { return $r['unique_event_name']; }, $this->eventInfo); }
    }

    require_once __DIR__ . '/../UniversalValidator.php';

    $fail = 0; $n = 0;
    function check($label, $ok, $got = null) {
        global $fail, $n; $n++;
        if (!$ok) { $fail++; fwrite(STDERR, "FAIL: $label" . ($got !== null ? ' (got ' . json_encode($got) . ')' : '') . "\n"); }
    }

    // ---- the dictionary: the fixture plus the pid-149 fields it reads -------------
    $dict = [
        'record_id' => ['form_name' => 'xe_enrol', 'field_type' => 'text'],
        'xv_date'   => ['form_name' => 'xe_visit', 'field_type' => 'text', 'text_validation_type_or_show_slider_number' => 'date_ymd'],
        'xv_weight' => ['form_name' => 'xe_visit', 'field_type' => 'text', 'text_validation_type_or_show_slider_number' => 'number'],
        'xs_hb'     => ['form_name' => 'xr_specimen', 'field_type' => 'text', 'text_validation_type_or_show_slider_number' => 'number'],
        'xr_value'  => ['form_name' => 'xr_result', 'field_type' => 'text', 'text_validation_type_or_show_slider_number' => 'number'],
    ];
    $fh = fopen(__DIR__ . '/../docs/testbed/uvdelta_test_fields.csv', 'r');
    $head = fgetcsv($fh, 0, ',', '"', '');
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $r = array_combine($head, $r);
        $dict[$r['Variable / Field Name']] = ['form_name' => $r['Form Name'], 'field_type' => $r['Field Type'],
            'field_annotation' => $r['Field Annotation'], 'select_choices_or_calculations' => $r['Choices, Calculations, OR Slider Labels'],
            'text_validation_type_or_show_slider_number' => $r['Text Validation Type OR Show Slider Number']];
    }
    fclose($fh);
    foreach ($dict as &$m) $m += ['field_annotation' => '', 'select_choices_or_calculations' => '', 'text_validation_type_or_show_slider_number' => ''];
    unset($m);

    // XE-1's seed values the fixture reads (docs/testbed/event_instance/seed_data.csv).
    $SEED = ['XE-1' => [
        351 => ['record_id' => 'XE-1'],
        379 => ['xv_weight' => '70'], 380 => ['xv_weight' => '72'], 381 => ['xv_weight' => '74'],
        'repeat_instances' => [379 => ['xr_specimen' => [1 => ['xs_hb' => '12'], 2 => ['xs_hb' => '12']]]],
    ]];

    function mod(array $data, $refs) {
        global $dict;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectSettings = ['log-values' => 'raw', 'enable-event-instance-refs' => $refs];
        \REDCap::$dictionary = $dict;
        \REDCap::$data = $data;
        \REDCap::$rights = ['tester' => ['forms' => array_fill_keys(['xe_enrol', 'xe_visit', 'xr_specimen', 'xr_result', 'uv_delta_test'], '1')]];
        $map = [];
        foreach ((new \Project(149))->eventsForms as $e => $forms) foreach ($forms as $f) $map[] = ['event_id' => $e, 'form' => $f];
        \REDCap::$mappings = $map;
        return $m;
    }
    function page($m, $evt, $form, $inst = 1, $ctx = 'form') {
        ob_start();
        if ($ctx === 'survey') $m->redcap_survey_page_top(149, 'XE-1', $form, $evt, null, 'hash', null, $inst);
        else $m->redcap_data_entry_form_top(149, 'XE-1', $form, $evt, null, $inst);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return json_decode($mm[1] ?? 'null', true);
    }
    function ruleOf($cfg, $f) {
        foreach ($cfg['rules'] ?? [] as $r) if (in_array($f, $r['fields'] ?? [], true)) return $r;
        return null;
    }
    function errOf($cfg, $f) { $r = ruleOf($cfg, $f); return $r['configError'] ?? ''; }
    function logs($m, $type) {
        return array_values(array_map(function ($c) { return $c[1]; }, array_filter($m->logCalls, function ($c) use ($type) { return $c[0] === $type; })));
    }
    /**
     * "field@event#instance:reason vs" for each delta finding; with $evt, only
     * that event's. The extended audit re-checks every entry of the record
     * that holds the form, so a save logs the other events' findings too.
     */
    function hits($m, $evt = null) {
        $out = [];
        foreach (logs($m, 'invalid-id-saved') as $e) {
            if ($e['type'] !== 'delta' || ($evt !== null && $e['event_id'] !== (string) $evt)) continue;
            $out[] = $e['field'] . '@' . $e['event_id'] . '#' . $e['instance'] . ':' . $e['reason'] . ' ' . $e['vs'];
        }
        sort($out);
        return $out;
    }
    function save(array $data, $refs, $evt, $form, $inst = 1) {
        $m = mod($data, $refs);
        $m->redcap_save_record(149, 'XE-1', $form, $evt, null, null, null, $inst);
        return $m;
    }
    $pages = [];

    // ---- Section F and C0: configuration errors with references off, then on -------------
    $off = page(mod($SEED, false), 380, 'uv_delta_test');
    $pages['visit1_off'] = $off;
    $want = [
        'ud_bad_email' => '@UVDELTA needs a number field',
        'ud_bad_self' => '"vs" names this field itself',
        'ud_bad_vs' => '"vs" field "xv_date" is not a number field',
        'ud_bad_nofield' => '"vs" names "[ud_nope]", which is not a field in this project.',
        'ud_bad_nolimit' => 'needs a limit',
        'ud_bad_inst' => 'needs event and instance references',
        'ud_wt' => 'needs event and instance references',
        'ud_height' => 'needs event and instance references',
        'ud_twoev' => 'needs event and instance references',
        'ud_bl' => 'another event or instance needs event and instance references',
    ];
    foreach ($want as $f => $text) check("references off: $f refused with \"$text\"", strpos(errOf($off, $f), $text) !== false, errOf($off, $f));
    foreach (['ud_w2', 'ud_sbp2', 'ud_ht', 'ud_htcalc', 'ud_pct', 'ud_c', 'ud_pain', 'ud_two', 'ud_when', 'ud_snap'] as $f) {
        check("references off: $f configures", ruleOf($off, $f) !== null && errOf($off, $f) === '', errOf($off, $f));
    }
    $on = page(mod($SEED, true), 380, 'uv_delta_test');
    $pages['visit1_on'] = $on;
    foreach (['ud_wt', 'ud_height', 'ud_bl', 'ud_twoev'] as $f) check("references on: $f configures", errOf($on, $f) === '', errOf($on, $f));
    check('references on: previous-instance on a form that does not repeat is refused',
        strpos(errOf($on, 'ud_bad_inst'), '"vs": "previous-instance" needs a form that repeats — "uv_delta_test" does not repeat in this project.') !== false,
        errOf($on, 'ud_bad_inst'));
    foreach (array_keys($want) as $f) if (strpos($f, 'ud_bad_') === 0) {
        if ($f === 'ud_bad_inst') continue;
        check("references on: $f still refused", errOf($on, $f) !== '');
    }
    // Every error is printed for the sheet's section F.
    foreach (array_keys($want) as $f) if (strpos($f, 'ud_bad_') === 0) echo "F  $f: " . errOf($on, $f) . "\n";

    // ---- Section A, B: the page fold at visit_1 -------------------------------------------
    $r = ruleOf($off, 'ud_w2');
    check('A: a vs on the page is live', ($r['deltaVsOp'] ?? null) === ['ref', 'ud_w1', null] && $r['blockSave'] === 'hard', $r);
    check('A: a calc is marked', !empty(ruleOf($off, 'ud_htcalc')['deltaComputed']));
    check('A: comma marks travel', !empty(ruleOf($off, 'ud_c')['decimalComma']) && !empty(ruleOf($off, 'ud_c')['deltaVsComma']));
    $r = ruleOf($off, 'ud_snap');
    check('B: xv_weight at visit_1 (72) is a snapshot', ($r['deltaVsOp'] ?? null) === ['lit', '72'] && in_array('xv_weight', $r['snapshotFields'] ?? [], true), $r);
    $r = ruleOf($on, 'ud_wt');
    check('C: the page at visit_1 reads baseline\'s ud_wt (blank in the seed)', ($r['deltaVsOp'] ?? null) === ['lit', ''] && $r['blockSave'] === 'off', $r);
    $r = ruleOf($on, 'ud_bl');
    check('C: ud_bl reads xv_weight at baseline (70)', ($r['deltaVsOp'] ?? null) === ['lit', '70'], $r);

    // ---- Section A: saves -------------------------------------------------------------------
    // A13: "Save anyway" with ud_sbp2 145 against 120, ud_two 130 (fails the 5% line only).
    $d = $SEED;
    $d['XE-1'][380] += ['ud_w1' => '70.5', 'ud_sbp1' => '120', 'ud_sbp2' => '145', 'ud_two' => '130'];
    $m = save($d, false, 380, 'uv_delta_test');
    check('A13: the audit logs the two changes saved anyway', hits($m) === ['ud_sbp2@380#1:up [ud_sbp1]', 'ud_two@380#1:up [ud_w1]'], hits($m));
    // A14: a calc that rose: saved, logged.
    $d['XE-1'][380] = array_merge($d['XE-1'][380], ['ud_sbp2' => '125', 'ud_two' => '', 'ud_ht0' => '150.0', 'ud_ht' => '152.0', 'ud_htcalc' => '152']);
    $m = save($d, false, 380, 'uv_delta_test');
    check('A14: the calc\'s rise is logged, the height\'s rise is not', hits($m) === ['ud_htcalc@380#1:up [ud_ht0]'], hits($m));

    // ---- Section B: the snapshot saves and is logged ------------------------------------------
    $d = $SEED; $d['XE-1'][380] += ['ud_snap' => '74'];
    $m = save($d, false, 380, 'uv_delta_test');
    check('B2: ud_snap 74 against xv_weight 72 is logged', hits($m) === ['ud_snap@380#1:up [xv_weight]'], hits($m));
    // B3: xv_weight corrected to 73.5 on xe_visit: saving xe_visit re-checks ud_snap.
    $d['XE-1'][380]['xv_weight'] = '73.5';
    $m = save($d, false, 380, 'xe_visit');
    check('B3: saving xe_visit with 73.5 re-checks ud_snap, now within 1 kg', hits($m) === [], hits($m));
    $d['XE-1'][380]['xv_weight'] = '72';
    $m = save($d, false, 380, 'xe_visit');
    check('B3: and back to 72 logs it again', hits($m) === ['ud_snap@380#1:up [xv_weight]'], hits($m));

    // ---- Section C: other events --------------------------------------------------------------
    $C = $SEED;
    $C['XE-1'][379] += ['ud_wt' => '70', 'ud_height' => '170.0', 'ud_bl' => '70', 'ud_twoev' => '70'];
    $m = save($C, true, 379, 'uv_delta_test');
    check('C1: baseline compares with nothing (no earlier event has the form)', hits($m) === [] && !logs($m, 'uvalidate-unconfigurable'),
        [hits($m), logs($m, 'uvalidate-unconfigurable')]);
    $pages['baseline_on'] = page(mod($C, true), 379, 'uv_delta_test');
    $C['XE-1'][380] += ['ud_wt' => '78', 'ud_height' => '169.4', 'ud_bl' => '85', 'ud_twoev' => '74'];
    $pages['visit1_on_after_baseline'] = page(mod($C, true), 380, 'uv_delta_test');
    $m = save($C, true, 380, 'uv_delta_test');
    check('C3: visit_1 against baseline', hits($m) === ['ud_bl@380#1:up [baseline_arm_1][xv_weight]',
        'ud_height@380#1:down [first-event-name][ud_height]', 'ud_twoev@380#1:up [previous-event-name][ud_twoev]',
        'ud_wt@380#1:up [previous-event-name][ud_wt]'], hits($m));
    // C4: visit_2 against visit_1 (previous) and baseline (first).
    $C['XE-1'][381] += ['ud_wt' => '80', 'ud_height' => '170.0', 'ud_twoev' => '82'];
    $pages['visit2_on'] = page(mod($C, true), 381, 'uv_delta_test');
    $m = save($C, true, 381, 'uv_delta_test');
    check('C4: visit_2: 80 is 2.6% above 78; 82 is 10.8% above 74 and 17.1% above 70', hits($m, 381) === [
        'ud_twoev@381#1:up [first-event-name][ud_twoev]', 'ud_twoev@381#1:up [previous-event-name][ud_twoev]'], hits($m, 381));
    check('C4: the same save re-checks visit_1, whose findings stand', count(hits($m, 380)) === 4, hits($m, 380));
    // C5: correcting baseline re-checks the later visits that compare with it.
    $C['XE-1'][379]['ud_wt'] = '76';
    $m = save($C, true, 379, 'uv_delta_test');
    check('C5: baseline 76 clears the weight of visit_1 (78 is 2.6% above 76); its other findings stand', hits($m, 380) === [
        'ud_bl@380#1:up [baseline_arm_1][xv_weight]', 'ud_height@380#1:down [first-event-name][ud_height]',
        'ud_twoev@380#1:up [previous-event-name][ud_twoev]'], hits($m, 380));
    echo "C5 re-check after correcting baseline: " . json_encode(hits($m)) . "\n";

    // ---- Section D: repeating instances ------------------------------------------------------
    $D = $SEED;
    $D['XE-1']['repeat_instances'][379]['xr_specimen'][1]['ud_rep'] = '10.0';
    $D['XE-1']['repeat_instances'][379]['xr_specimen'][2]['ud_rep'] = '7.5';
    $pages['specimen2_on'] = page(mod($D, true), 379, 'xr_specimen', 2);
    check('D: instance 2 reads instance 1', (ruleOf($pages['specimen2_on'], 'ud_rep')['deltaVsOp'] ?? null) === ['lit', '10.0'], ruleOf($pages['specimen2_on'], 'ud_rep'));
    $m = save($D, true, 379, 'xr_specimen', 2);
    check('D2: 7.5 is 25% below 10.0', hits($m) === ['ud_rep@379#2:down [ud_rep][previous-instance]'], hits($m));
    $m = save($D, true, 379, 'xr_specimen', 1);
    check('D3: instance 1 compares with nothing; its save re-checks instance 2', hits($m) === ['ud_rep@379#2:down [ud_rep][previous-instance]'], hits($m));

    // ---- Section E: the scan ------------------------------------------------------------------
    $E = $C;
    $E['XE-1'][379]['ud_wt'] = '70';
    $E['XE-1'][380] += ['ud_w1' => '70.5', 'ud_sbp1' => '120', 'ud_sbp2' => '145', 'ud_two' => '130', 'ud_snap' => '74'];
    $E['XE-1']['repeat_instances'] = $D['XE-1']['repeat_instances'];
    $res = mod($E, true)->scanProject(149);
    $got = [];
    foreach ($res['violations'] as $v) if ($v['type'] === 'delta') $got[] = $v['field'] . '@' . $v['event_id'] . '#' . ($v['instance'] ?? 1) . ':' . $v['reason'];
    sort($got);
    echo "E  scan: " . count($got) . " delta findings: " . json_encode($got) . "\n";
    check('E: the scan finds every delta finding of the sheet', $got === ['ud_bl@380#1:up', 'ud_height@380#1:down', 'ud_rep@379#2:down',
        'ud_sbp2@380#1:up', 'ud_snap@380#1:up', 'ud_two@380#1:up', 'ud_twoev@380#1:up', 'ud_twoev@381#1:up', 'ud_twoev@381#1:up',
        'ud_wt@380#1:up'], $got);
    $m = mod($E, true);
    $dims = $m->scanDimensions(149, $res['rules'] ?? null);
    $cols = \INSPIRE\UniversalValidator\ScanColumns::all($dims);
    foreach ($res['violations'] as $v) if ($v['type'] === 'delta' && in_array($v['field'], ['ud_wt', 'ud_sbp2', 'ud_height'], true)) {
        echo "E  report " . $v['field'] . ': ' . (\INSPIRE\UniversalValidator\ScanColumns::row($v, $dims, $cols)['problem'] ?? '') . "\n";
    }

    // ---- Survey: what a survey page is sent -----------------------------------------------------
    $pages['visit1_survey_on'] = page(mod($C, true), 380, 'uv_delta_test', 1, 'survey');
    $r = ruleOf($pages['visit1_survey_on'], 'ud_snap');
    check('survey: a vs on another form is withheld', ($r['deltaVsOp'] ?? null) === ['withheld'], $r);

    // ---- The sheet in order: the saves it makes, then the scan of what it leaves ------------
    $S = $SEED;
    $S['XE-1'][380] += ['ud_w1' => '70.5', 'ud_w2' => '70.3', 'ud_sbp1' => '120', 'ud_sbp2' => '125', 'ud_ht0' => '150.0', 'ud_ht' => '152.0', 'ud_htcalc' => '152'];
    check('sheet A6: the save logs the calc only', hits(save($S, false, 380, 'uv_delta_test')) === ['ud_htcalc@380#1:up [ud_ht0]']);
    $S['XE-1'][380] += ['ud_pct0' => '0', 'ud_c0' => '70,0', 'ud_c' => '72,5', 'ud_pain0' => '2', 'ud_pain' => '4', 'ud_two' => '130'];
    check('sheet A11: "save anyway" logs ud_two and the calc again', hits(save($S, false, 380, 'uv_delta_test'))
        === ['ud_htcalc@380#1:up [ud_ht0]', 'ud_two@380#1:up [ud_w1]']);
    $S['XE-1'][380] = array_merge($S['XE-1'][380], ['ud_two' => '', 'ud_grp' => '2', 'ud_when' => '80']);
    check('sheet A12: Skip, saved: the calc again, nothing for ud_when', hits(save($S, false, 380, 'uv_delta_test')) === ['ud_htcalc@380#1:up [ud_ht0]']);
    $S['XE-1'][380]['ud_sbp2'] = '145';
    check('sheet A13: "save anyway" logs ud_sbp2', hits(save($S, false, 380, 'uv_delta_test')) === ['ud_htcalc@380#1:up [ud_ht0]', 'ud_sbp2@380#1:up [ud_sbp1]']);
    $S['XE-1'][380]['ud_snap'] = '74';
    check('sheet B2', in_array('ud_snap@380#1:up [xv_weight]', hits(save($S, false, 380, 'uv_delta_test')), true));
    $S['XE-1'][380]['xv_weight'] = '73.5';
    check('sheet B3: xv_weight 73.5 saved on xe_visit: nothing for ud_snap', !array_filter(hits(save($S, false, 380, 'xe_visit')), function ($h) { return strpos($h, 'ud_snap') === 0; }));
    $S['XE-1'][380]['xv_weight'] = '72';
    check('sheet B3: back to 72: ud_snap logged again', hits(save($S, false, 380, 'xe_visit')) === ['ud_snap@380#1:up [xv_weight]']);
    $S['XE-1'][379] += ['ud_wt' => '70', 'ud_height' => '170.0', 'ud_bl' => '70', 'ud_twoev' => '70'];
    check('sheet C1', hits(save($S, true, 379, 'uv_delta_test'), 379) === []);
    $S['XE-1'][380] += ['ud_wt' => '78', 'ud_height' => '169.4', 'ud_bl' => '85', 'ud_twoev' => '74'];
    // the plain rules of the form (ud_htcalc, ud_sbp2, ud_snap) are judged again too
    check('sheet C3', array_values(array_filter(hits(save($S, true, 380, 'uv_delta_test'), 380), function ($h) {
        return (bool) preg_match('/^ud_(wt|height|bl|twoev)@/', $h); })) === ['ud_bl@380#1:up [baseline_arm_1][xv_weight]',
        'ud_height@380#1:down [first-event-name][ud_height]', 'ud_twoev@380#1:up [previous-event-name][ud_twoev]', 'ud_wt@380#1:up [previous-event-name][ud_wt]']);
    $S['XE-1'][381] += ['ud_wt' => '80', 'ud_height' => '170.0', 'ud_twoev' => '82'];
    check('sheet C4', count(hits(save($S, true, 381, 'uv_delta_test'), 381)) === 2);
    $S['XE-1'][379]['ud_wt'] = '76';
    check('sheet C5', !array_filter(hits(save($S, true, 379, 'uv_delta_test')), function ($h) { return strpos($h, 'ud_wt@') === 0; }));
    $S['XE-1']['repeat_instances'][379]['xr_specimen'][1]['ud_rep'] = '10.0';
    check('sheet D1', hits(save($S, true, 379, 'xr_specimen', 1), 379) === []);
    $S['XE-1']['repeat_instances'][379]['xr_specimen'][2]['ud_rep'] = '7.5';
    check('sheet D2', in_array('ud_rep@379#2:down [ud_rep][previous-instance]', hits(save($S, true, 379, 'xr_specimen', 2)), true));
    $res = mod($S, true)->scanProject(149);
    $got = [];
    foreach ($res['violations'] as $v) if ($v['type'] === 'delta') $got[] = $v['field'] . '@' . $v['event_id'] . '#' . ($v['instance'] ?? 1) . ':' . $v['reason'];
    sort($got);
    echo "sheet E scan: " . json_encode($got) . "\n";
    check('sheet E: the scan of what the sheet leaves', $got === ['ud_bl@380#1:up', 'ud_height@380#1:down', 'ud_htcalc@380#1:up', 'ud_rep@379#2:down',
        'ud_sbp2@380#1:up', 'ud_snap@380#1:up', 'ud_twoev@380#1:up', 'ud_twoev@381#1:up', 'ud_twoev@381#1:up'], $got);
    $bad = [];
    foreach ($res['unconfigurable'] as $u) foreach ($u['fields'] as $f) if (strpos($f, 'ud_') === 0) $bad[$f] = true;
    ksort($bad);
    echo "sheet E rule problems: " . json_encode(array_keys($bad)) . "\n";
    check('sheet E: the six bad fields are rule problems', array_keys($bad) === ['ud_bad_email', 'ud_bad_inst', 'ud_bad_nofield', 'ud_bad_nolimit', 'ud_bad_self', 'ud_bad_vs'], array_keys($bad));

    file_put_contents(sys_get_temp_dir() . '/temporal_delta_live_pages.json', json_encode($pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo "temporal_delta_live_check: $n checks, $fail failure(s); page configs in " . sys_get_temp_dir() . "/temporal_delta_live_pages.json\n";
    exit($fail ? 1 : 0);
}
