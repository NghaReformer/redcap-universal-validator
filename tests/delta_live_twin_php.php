<?php
/**
 * delta_live_twin_php.php - run the REAL module over the @UVDELTA live fixture
 * (docs/testbed/uvdelta_test_fields.csv and uvdelta_test_fields_xr_specimen.csv)
 * on a copy of pid 149's shape, so every server-side "Expect" of
 * docs/testbed/DELTA_LIVE_ACCEPTANCE.md is the module's own answer.
 *
 * It checks the configuration errors word for word, the page fold of each
 * rule, every log entry of each save the sheet makes, in the sheet's order,
 * and the scan with its detail lines. The page configs it builds are the
 * input of tests/delta_live_twin_js.cjs, which replays the sheet's typing
 * through js/engine.js; they are kept in tests/delta_live_pages.json, and
 * this suite fails when that file is not what the module builds now.
 *
 * RUN:    php tests/delta_live_twin_php.php && node tests/delta_live_twin_js.cjs
 * WRITE:  php tests/delta_live_twin_php.php --write   (after a change to the pages)
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
    $LABELS = [];
    foreach (['uvdelta_test_fields.csv' => 27, 'uvdelta_test_fields_xr_specimen.csv' => 1] as $csv => $rows) {
        $fh = fopen(__DIR__ . '/../docs/testbed/' . $csv, 'r');
        $head = fgetcsv($fh, 0, ',', '"', '');
        $read = 0;
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $r = array_combine($head, $r);
            $read++;
            $dict[$r['Variable / Field Name']] = ['form_name' => $r['Form Name'], 'field_type' => $r['Field Type'],
                'field_annotation' => $r['Field Annotation'], 'select_choices_or_calculations' => $r['Choices, Calculations, OR Slider Labels'],
                'text_validation_type_or_show_slider_number' => $r['Text Validation Type OR Show Slider Number']];
            $LABELS[$r['Variable / Field Name']] = $r['Field Label'];
        }
        fclose($fh);
        check("$csv holds $rows row(s)", $read === $rows, $read);
    }
    // One instrument per file: the main file is appended as it is, and the
    // xr_specimen row goes after that instrument's own rows (REDCap keeps an
    // instrument's rows together).
    check('the main fixture adds one instrument only', count(array_unique(array_map(function ($f) use ($dict) { return $dict[$f]['form_name']; },
        array_filter(array_keys($LABELS), function ($f) { return $f !== 'ud_rep'; })))) === 1);
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
    // Section F and C0 word for word: what the sheet prints is what the module says.
    $F = [
        'ud_bad_email' => '@UVDELTA needs a number field — give this Text field integer or number validation, or none (it has "email").',
        'ud_bad_vs' => '"vs" field "xv_date" is not a number field — it needs integer or number validation, or none, or to be a calc or a slider.',
        'ud_bad_nofield' => '"vs" names "[ud_nope]", which is not a field in this project.',
        'ud_bad_nolimit' => '@UVDELTA needs a limit: "max" (the most the value may change, in its own unit) or "maxPct" (the most it may change as a percent of the earlier value), e.g. "maxPct":10.',
    ];
    $SHORT_OFF = function ($short) { return '"vs": "' . $short . '" reads another event or instance, which needs event and instance references — enable them in project settings first.'; };
    $want = $F + [
        'ud_bad_inst' => $SHORT_OFF('previous-instance'),
        'ud_wt' => $SHORT_OFF('previous-event'),
        'ud_height' => $SHORT_OFF('first-event'),
        'ud_twoev' => $SHORT_OFF('previous-event'),
        'ud_bl' => 'a "vs" value in another event or instance needs event and instance references — enable them in project settings first.',
    ];
    // a "vs" naming the field itself: the advice is a shorthand this form's events accept, which needs references on
    $SELF = '"vs" names this field itself — compare it with another entry, as "vs":"previous-event"';
    $want['ud_bad_self'] = $SELF . ' (this needs event and instance references, a project setting).';
    foreach ($want as $f => $text) check("references off: $f refused word for word", errOf($off, $f) === '@UVDELTA on "' . $f . '": ' . $text, errOf($off, $f));
    foreach (['ud_w2', 'ud_sbp2', 'ud_ht', 'ud_htcalc', 'ud_pct', 'ud_c', 'ud_pain', 'ud_two', 'ud_when', 'ud_snap'] as $f) {
        check("references off: $f configures", ruleOf($off, $f) !== null && errOf($off, $f) === '', errOf($off, $f));
    }
    $on = page(mod($SEED, true), 380, 'uv_delta_test');
    $pages['visit1_on'] = $on;
    foreach (['ud_wt', 'ud_height', 'ud_bl', 'ud_twoev'] as $f) check("references on: $f configures", errOf($on, $f) === '', errOf($on, $f));
    check('references on: previous-instance on a form that repeats nowhere is refused, naming each event',
        errOf($on, 'ud_bad_inst') === '@UVDELTA on "ud_bad_inst": "vs": "previous-instance" needs a form that repeats — "uv_delta_test" does not repeat at "baseline_arm_1", "visit_1_arm_1" and "visit_2_arm_1".',
        errOf($on, 'ud_bad_inst'));
    foreach ($F + ['ud_bad_self' => $SELF . '.'] as $f => $text) check("references on: $f refused word for word", errOf($on, $f) === '@UVDELTA on "' . $f . '": ' . $text, errOf($on, $f));

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

    // ---- Section D: repeating instances ------------------------------------------------------
    $D = $SEED;
    $D['XE-1']['repeat_instances'][379]['xr_specimen'][1]['ud_rep'] = '10.0';
    $D['XE-1']['repeat_instances'][379]['xr_specimen'][2]['ud_rep'] = '7.5';
    $pages['specimen2_on'] = page(mod($D, true), 379, 'xr_specimen', 2);
    check('D: instance 2 reads instance 1', (ruleOf($pages['specimen2_on'], 'ud_rep')['deltaVsOp'] ?? null) === ['lit', '10.0'], ruleOf($pages['specimen2_on'], 'ud_rep'));
    $m = save($D, true, 379, 'xr_specimen', 2);
    check('D2: 7.5 is 25% below 10.0', hits($m) === ['ud_rep@379#2:down [ud_rep][previous-instance]'], hits($m));
    $m = save($D, true, 379, 'xr_specimen', 1);
    check('saving instance 1: it compares with nothing, and the save re-checks instance 2', hits($m) === ['ud_rep@379#2:down [ud_rep][previous-instance]'], hits($m));

    // ---- Section E: the scan ------------------------------------------------------------------
    $E = $C;
    $E['XE-1'][379]['ud_wt'] = '70';
    $E['XE-1'][380] += ['ud_w1' => '70.5', 'ud_sbp1' => '120', 'ud_sbp2' => '145', 'ud_two' => '130', 'ud_snap' => '74'];
    $E['XE-1']['repeat_instances'] = $D['XE-1']['repeat_instances'];
    $res = mod($E, true)->scanProject(149);
    $got = [];
    foreach ($res['violations'] as $v) if ($v['type'] === 'delta') $got[] = $v['field'] . '@' . $v['event_id'] . '#' . ($v['instance'] ?? 1) . ':' . $v['reason'];
    sort($got);
    check('E: the scan finds every delta finding of the sheet', $got === ['ud_bl@380#1:up', 'ud_height@380#1:down', 'ud_rep@379#2:down',
        'ud_sbp2@380#1:up', 'ud_snap@380#1:up', 'ud_two@380#1:up', 'ud_twoev@380#1:up', 'ud_twoev@381#1:up', 'ud_twoev@381#1:up',
        'ud_wt@380#1:up'], $got);
    $m = mod($E, true);
    $dims = $m->scanDimensions(149, $res['rules'] ?? null);
    $cols = \INSPIRE\UniversalValidator\ScanColumns::all($dims);
    $E2 = [
        'ud_height' => 'The value fell more than allowed from the value it is compared with. Allowed a fall of at most 0.5 cm from the first event.',
        'ud_sbp2' => 'The two readings differ by more than 20 mmHg. Take a third. Allowed a change of at most 20 mmHg from [ud_sbp1].',
        'ud_wt' => 'The value rose more than allowed from the value it is compared with. Allowed a change of at most 10% from the previous event.',
    ];
    foreach ($res['violations'] as $v) if ($v['type'] === 'delta' && isset($E2[$v['field']])) {
        $line = \INSPIRE\UniversalValidator\ScanColumns::row($v, $dims, $cols)['problem'] ?? '';
        check('E2: the detail line of ' . $v['field'] . ' word for word', $line === $E2[$v['field']], $line);
    }

    // ---- Survey: what a survey page is sent -----------------------------------------------------
    $pages['visit1_survey_on'] = page(mod($C, true), 380, 'uv_delta_test', 1, 'survey');
    $r = ruleOf($pages['visit1_survey_on'], 'ud_snap');
    check('survey: a vs on another form is withheld', ($r['deltaVsOp'] ?? null) === ['withheld'], $r);

    // ---- The sheet in order: the saves it makes, then the scan of what it leaves ------------
    $S = $SEED;
    $S['XE-1'][380] += ['ud_w1' => '70.5', 'ud_w2' => '70.3', 'ud_sbp1' => '120', 'ud_sbp2' => '125', 'ud_ht0' => '150.0', 'ud_ht' => '152.0', 'ud_htcalc' => '152'];
    $step = function ($label, $m, array $want) { check('sheet ' . $label . ': every log entry', hits($m) === $want, hits($m)); };
    // A page the sheet opens, and the values of its form saved by then (the JS twin fills the inputs with them).
    $opened = function ($key, array $data, $refs, $evt, $form, $inst = 1) use (&$pages, $dict) {
        $pages[$key] = page(mod($data, $refs), $evt, $form, $inst);
        $row = $inst > 1 ? ($data['XE-1']['repeat_instances'][$evt][$form][$inst] ?? []) : ($data['XE-1'][$evt] ?? []);
        $vals = [];
        foreach ($row as $f => $v) if (isset($dict[$f]) && $dict[$f]['form_name'] === $form && (string) $v !== '') $vals[$f] = (string) $v;
        ksort($vals);
        $pages[$key . '_values'] = (object) $vals;
    };
    $step('A6', save($S, false, 380, 'uv_delta_test'), ['ud_htcalc@380#1:up [ud_ht0]']);
    $S['XE-1'][380] += ['ud_pct0' => '0', 'ud_c0' => '70,0', 'ud_c' => '72,5', 'ud_pain0' => '2', 'ud_pain' => '4', 'ud_two' => '130'];
    $step('A11', save($S, false, 380, 'uv_delta_test'), ['ud_htcalc@380#1:up [ud_ht0]', 'ud_two@380#1:up [ud_w1]']);
    $S['XE-1'][380] = array_merge($S['XE-1'][380], ['ud_two' => '', 'ud_grp' => '2', 'ud_when' => '80']);
    $step('A12b', save($S, false, 380, 'uv_delta_test'), ['ud_htcalc@380#1:up [ud_ht0]']);
    $S['XE-1'][380]['ud_sbp2'] = '145';
    $step('A13', save($S, false, 380, 'uv_delta_test'), ['ud_htcalc@380#1:up [ud_ht0]', 'ud_sbp2@380#1:up [ud_sbp1]']);
    $opened('sheet_b1', $S, false, 380, 'uv_delta_test');
    $S['XE-1'][380]['ud_snap'] = '74';
    $step('B2', save($S, false, 380, 'uv_delta_test'), ['ud_htcalc@380#1:up [ud_ht0]', 'ud_sbp2@380#1:up [ud_sbp1]', 'ud_snap@380#1:up [xv_weight]']);
    $S['XE-1'][380]['xv_weight'] = '73.5';
    $step('B3 (73.5)', save($S, false, 380, 'xe_visit'), []);
    $S['XE-1'][380]['xv_weight'] = '72';
    $step('B3 (72)', save($S, false, 380, 'xe_visit'), ['ud_snap@380#1:up [xv_weight]']);
    $S['XE-1'][379] += ['ud_wt' => '70', 'ud_height' => '170.0', 'ud_bl' => '70', 'ud_twoev' => '70'];
    $step('C1', save($S, true, 379, 'uv_delta_test'), []);
    $opened('sheet_c2', $S, true, 380, 'uv_delta_test');
    $S['XE-1'][380] += ['ud_wt' => '78', 'ud_height' => '169.4', 'ud_bl' => '85', 'ud_twoev' => '74'];
    $step('C3', save($S, true, 380, 'uv_delta_test'), ['ud_bl@380#1:up [baseline_arm_1][xv_weight]', 'ud_height@380#1:down [first-event-name][ud_height]',
        'ud_htcalc@380#1:up [ud_ht0]', 'ud_sbp2@380#1:up [ud_sbp1]', 'ud_snap@380#1:up [xv_weight]',
        'ud_twoev@380#1:up [previous-event-name][ud_twoev]', 'ud_wt@380#1:up [previous-event-name][ud_wt]']);
    $S['XE-1'][381] += ['ud_wt' => '80', 'ud_height' => '170.0', 'ud_twoev' => '82'];
    $step('C4', save($S, true, 381, 'uv_delta_test'), ['ud_bl@380#1:up [baseline_arm_1][xv_weight]', 'ud_height@380#1:down [first-event-name][ud_height]',
        'ud_twoev@380#1:up [previous-event-name][ud_twoev]', 'ud_twoev@381#1:up [first-event-name][ud_twoev]',
        'ud_twoev@381#1:up [previous-event-name][ud_twoev]', 'ud_wt@380#1:up [previous-event-name][ud_wt]']);
    $S['XE-1'][379]['ud_wt'] = '76';
    $step('C5', save($S, true, 379, 'uv_delta_test'), ['ud_bl@380#1:up [baseline_arm_1][xv_weight]', 'ud_height@380#1:down [first-event-name][ud_height]',
        'ud_twoev@380#1:up [previous-event-name][ud_twoev]', 'ud_twoev@381#1:up [first-event-name][ud_twoev]',
        'ud_twoev@381#1:up [previous-event-name][ud_twoev]']);
    $opened('sheet_d1', $S, true, 379, 'xr_specimen', 1);
    $S['XE-1']['repeat_instances'][379]['xr_specimen'][1]['ud_rep'] = '10.0';
    $step('D1', save($S, true, 379, 'xr_specimen', 1), []);
    $S['XE-1']['repeat_instances'][379]['xr_specimen'][2]['ud_rep'] = '7.5';
    $step('D2', save($S, true, 379, 'xr_specimen', 2), ['ud_rep@379#2:down [ud_rep][previous-instance]']);
    $res = mod($S, true)->scanProject(149);
    $got = [];
    foreach ($res['violations'] as $v) if ($v['type'] === 'delta') $got[] = $v['field'] . '@' . $v['event_id'] . '#' . ($v['instance'] ?? 1) . ':' . $v['reason'];
    sort($got);
    check('sheet E: the scan of what the sheet leaves', $got === ['ud_bl@380#1:up', 'ud_height@380#1:down', 'ud_htcalc@380#1:up', 'ud_rep@379#2:down',
        'ud_sbp2@380#1:up', 'ud_snap@380#1:up', 'ud_twoev@380#1:up', 'ud_twoev@381#1:up', 'ud_twoev@381#1:up'], $got);
    $bad = [];
    foreach ($res['unconfigurable'] as $u) foreach ($u['fields'] as $f) if (strpos($f, 'ud_') === 0) $bad[$f] = true;
    ksort($bad);
    check('sheet E: the six bad fields are rule problems', array_keys($bad) === ['ud_bad_email', 'ud_bad_inst', 'ud_bad_nofield', 'ud_bad_nolimit', 'ud_bad_self', 'ud_bad_vs'], array_keys($bad));

    // The labels the save dialog names, for the engine replay (the stub reads them from here).
    $pages['labels'] = $LABELS;
    $json = json_encode($pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    $file = __DIR__ . '/delta_live_pages.json';
    if (in_array('--write', $argv ?? [], true)) {
        file_put_contents($file, $json);
        echo "wrote $file\n";
    } else {
        check('tests/delta_live_pages.json is what the module builds now (run with --write after a change)',
            is_file($file) && str_replace("\r\n", "\n", (string) file_get_contents($file)) === $json);
    }
    echo "delta_live_twin_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
