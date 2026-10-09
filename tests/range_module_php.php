<?php
/**
 * range_module_php.php — @UVRANGE through the module (2.5.0).
 *
 * range_php.php pins the verdict and the grammar; this file pins the REDCap
 * glue around them:
 *   - the field hook: a Text field must take numbers (none, integer or number
 *     validation in any form), a calc and a slider always do; a comma-decimal
 *     field and a calc are marked on the rule, and both ranges travel as
 *     display texts written with the field's own decimal mark,
 *   - branches per sex: two tags with "when" become one branched rule, and a
 *     selector on another form is baked in as a snapshot (never blocks),
 *   - the post-save audit logs soft-low / soft-high / hard-low / hard-high /
 *     not-a-number as type 'range', and reads a comma-decimal value saved
 *     either way,
 *   - the scan finds the same, labels each tier, and writes the detail line.
 *
 * Run:  php tests/range_module_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $logCalls = [];
        public $subSettings = [];
        public $projectSettings = [];
        public $systemSettings = [];
        public $projectIdReturn = null;
        public function getSubSettings($k, $pid = null) {
            $e = ($pid !== null && $pid !== '') ? $pid : $this->projectIdReturn;
            return $e ? $this->subSettings : [];
        }
        public function getProjectSetting($k, $pid = null) {
            $e = ($pid !== null && $pid !== '') ? $pid : $this->projectIdReturn;
            if (!$e) return null;
            return isset($this->projectSettings[$k]) ? $this->projectSettings[$k] : null;
        }
        public function getSystemSetting($k) { return isset($this->systemSettings[$k]) ? $this->systemSettings[$k] : null; }
        public function setSystemSetting($k, $v) { $this->systemSettings[$k] = $v; }
        public function getProjectId() { return $this->projectIdReturn; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return count($this->logCalls); }
        public function initializeJavascriptModuleObject() { return '<script></script>'; }
        public function getJavascriptModuleObjectName() { return 'ExternalModules.TEST.UniversalValidator'; }
        public function getUser() {
            $u = isset($GLOBALS['__TEST_USER']) ? $GLOBALS['__TEST_USER'] : null;
            return $u === null ? null : new TestUser($u);
        }
    }
    class TestUser {
        private $n;
        public function __construct($n) { $this->n = $n; }
        public function getUsername() { return $this->n; }
        public function hasDesignRights() { return true; }
        public function getRights($pid = null) {
            $all = \REDCap::$rights;
            return isset($all[$this->n]) ? $all[$this->n] : null;
        }
    }
}

namespace {
    class REDCap {
        public static $data = [];
        public static $dictionary = [];
        public static $rights = [];
        public static function getData($p) { return self::$data; }
        public static function getDataDictionary($pid, $f = 'array') {
            if (!$pid) throw new \RuntimeException('needs pid');
            return self::$dictionary;
        }
        public static function getUserRights($pid = null, $user = null) { return self::$rights; }
        public static function getGroupNames($u = false, $g = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
    }

    require_once __DIR__ . '/../UniversalValidator.php';
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    function f($form, $ann = '', $validation = '', $type = 'text') {
        return ['field_type' => $type, 'form_name' => $form, 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => $validation];
    }
    $HB = '@UVRANGE={"soft":[12,15.5],"hard":[3,25],"unit":"g/dL","when":"[sex]=\'2\'"} '
        . '@UVRANGE={"soft":[13.5,17.5],"hard":[3,25],"unit":"g/dL","when":"[sex]=\'1\'"}';
    // enrol_form holds the sex that picks the haemoglobin limits; labs_form the numbers.
    $DICT = [
        'record_id'  => f('enrol_form'),
        'sex'        => f('enrol_form', '', '', 'radio'),
        'hb'         => f('labs_form', $HB, 'number_1dp'),
        'sbp'        => f('labs_form', '@UVRANGE={"soft":[null,140],"softBlock":"off","hard":[40,250],"unit":"mmHg"}', 'integer'),
        'temp_c'     => f('labs_form', '@UVRANGE={"soft":[36,37.5],"hard":[30,43],"unit":"°C"}', 'number_1dp_comma_decimal'),
        'bmi_calc'   => f('labs_form', '@UVRANGE={"hard":[10,60]}', '', 'calc'),
        'pain'       => f('labs_form', '@UVRANGE={"soft":[0,7]}', 'number', 'slider'),
        'free_num'   => f('labs_form', '@UVRANGE={"hard":[0,100]}', ''),
        'weight'     => f('labs_form', '@UVRANGE={"hard":[0.5,250],"message":"Check the scale."}', 'number'),
        'bad_email'  => f('labs_form', '@UVRANGE={"hard":[0,1]}', 'email'),
        'bad_date'   => f('labs_form', '@UVRANGE={"hard":[0,1]}', 'date_ymd'),
        'bad_radio'  => f('labs_form', '@UVRANGE={"hard":[0,1]}', '', 'radio'),
        'bad_notes'  => f('labs_form', '@UVRANGE={"hard":[0,1]}', '', 'notes'),
        'bad_order'  => f('labs_form', '@UVRANGE={"hard":[10,1]}', 'number'),
    ];
    $DATA = ['2' => [351 => [
        'record_id' => '2', 'sex' => '1',
        'hb' => '18.2', 'sbp' => '150', 'temp_c' => '43,5', 'bmi_calc' => '70', 'pain' => '9',
        'free_num' => 'n/a', 'weight' => '0.4',
        'bad_email' => 'x@y', 'bad_date' => '2026-01-01', 'bad_radio' => '5', 'bad_notes' => '5', 'bad_order' => '5',
    ]]];
    $FULL = ['nurse' => ['forms' => ['enrol_form' => '1', 'labs_form' => '1']]];

    function mod($dict, $data, $rights, $user) {
        $GLOBALS['__TEST_USER'] = $user;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectSettings = ['log-values' => 'raw'];
        $m->projectIdReturn = 149;
        \REDCap::$dictionary = $dict;
        \REDCap::$data = $data;
        \REDCap::$rights = $rights;
        return $m;
    }
    function page($m, $ctx, $rec, $form) {
        ob_start();
        if ($ctx === 'survey') $m->redcap_survey_page_top(149, $rec, $form, 351, null, 'hash', null, 1);
        else $m->redcap_data_entry_form_top(149, $rec, $form, 351, null, 1);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return ['html' => $html, 'cfg' => json_decode(isset($mm[1]) ? $mm[1] : 'null', true)];
    }
    function ruleFor($p, $f) {
        foreach ((isset($p['cfg']['rules']) ? $p['cfg']['rules'] : []) as $r) {
            if (in_array($f, isset($r['fields']) ? $r['fields'] : [], true)) return $r;
        }
        return null;
    }
    function findings($m, $type = 'invalid-id-saved') {
        return array_values(array_map(function ($c) { return $c[1]; },
            array_filter($m->logCalls, function ($c) use ($type) { return $c[0] === $type; })));
    }

    // ---- 1) the field hook ------------------------------------------------------
    $p = page(mod($DICT, $DATA, $FULL, 'nurse'), 'form', '2', 'labs_form');
    $err = function ($field) use ($p) { $r = ruleFor($p, $field); return $r && isset($r['configError']) ? $r['configError'] : ''; };
    check('email validation: refused, names it', strpos($err('bad_email'), 'needs a number field') !== false
        && strpos($err('bad_email'), '(it has "email")') !== false);
    check('date validation: refused', strpos($err('bad_date'), '(it has "date_ymd")') !== false);
    check('radio: refused by eligibility', strpos($err('bad_radio'), 'does not support "radio" fields') !== false);
    check('notes: refused by eligibility', strpos($err('bad_notes'), 'does not support "notes" fields') !== false);
    check('limits in the wrong order: refused', strpos($err('bad_order'), 'the "hard" low limit (10) is above its high limit (1)') !== false);
    check('every refusal names @UVRANGE', strpos($err('bad_email'), '@UVRANGE on "bad_email"') === 0);
    foreach (['hb', 'sbp', 'temp_c', 'bmi_calc', 'pain', 'free_num', 'weight'] as $ok) {
        check($ok . ': no configuration error', ruleFor($p, $ok) !== null && $err($ok) === '');
    }

    $r = ruleFor($p, 'sbp');
    check('integer field: limits and texts travel', $r && $r['type'] === 'range' && !isset($r['rangeSoftLo'])
        && $r['rangeSoftHi'] === '140' && $r['rangeHardLo'] === '40' && $r['rangeHardHi'] === '250'
        && $r['rangeSoftText'] === 'at most 140 mmHg' && $r['rangeHardText'] === '40 to 250 mmHg'
        && $r['rangeSoftBlock'] === 'off' && empty($r['decimalComma']) && empty($r['rangeComputed']));
    $r = ruleFor($p, 'temp_c');
    check('comma-decimal field: marked, texts with a comma', $r && $r['decimalComma'] === true
        && $r['rangeSoftText'] === '36 to 37,5 °C' && $r['rangeHardText'] === '30 to 43 °C'
        && $r['rangeSoftHi'] === '37.5');
    $r = ruleFor($p, 'bmi_calc');
    check('calc: marked so it never blocks', $r && $r['rangeComputed'] === true && $r['rangeHardText'] === '10 to 60'
        && !isset($r['rangeSoftText']));
    $r = ruleFor($p, 'pain');
    check('slider: accepted, not computed', $r && empty($r['rangeComputed']) && $r['rangeSoftText'] === '0 to 7');
    $r = ruleFor($p, 'free_num');
    check('text without validation: accepted', $r && $r['rangeHardText'] === '0 to 100');
    $r = ruleFor($p, 'weight');
    check('message travels', $r && $r['message'] === 'Check the scale.');

    // ---- 2) branches per sex ------------------------------------------------------
    $r = ruleFor($p, 'hb');
    check('two tags: one branched rule', $r && isset($r['branches']) && count($r['branches']) === 2);
    $b = $r ? $r['branches'] : [];
    $female = null; $male = null;
    foreach ($b as $x) { if (strpos($x['when'] ?? '', "'2'") !== false) $female = $x; else $male = $x; }
    check('female branch: its own limits and text', $female && $female['rangeSoftLo'] === '12' && $female['rangeSoftHi'] === '15.5'
        && $female['rangeSoftText'] === '12 to 15.5 g/dL' && $female['rangeHardText'] === '3 to 25 g/dL');
    check('male branch: its own limits and text', $male && $male['rangeSoftLo'] === '13.5' && $male['rangeSoftText'] === '13.5 to 17.5 g/dL');
    check('sex on another form: a snapshot, so the branch never blocks',
        $r && (in_array('sex', $r['snapshotFields'] ?? [], true)
            || ($male && in_array('sex', $male['snapshotFields'] ?? [], true))));

    $both = $DICT;
    $both['sex'] = f('labs_form', '', '', 'radio');
    $p2 = page(mod($both, $DATA, $FULL, 'nurse'), 'form', '2', 'labs_form');
    $r2 = ruleFor($p2, 'hb');
    check('sex on the same page: live, no snapshot', $r2 && empty($r2['snapshotFields'])
        && !array_filter($r2['branches'], function ($x) { return !empty($x['snapshotFields']); }));

    $ps = page(mod($DICT, $DATA, $FULL, 'nurse'), 'survey', '2', 'labs_form');
    check('survey: the rule is on the page', ruleFor($ps, 'sbp') !== null && ruleFor($ps, 'sbp')['rangeHardHi'] === '250');

    // ---- 3) the post-save audit ---------------------------------------------------
    $m = mod($DICT, $DATA, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'labs_form', 351, null, null, null, 1);
    $by = [];
    foreach (findings($m) as $e) $by[$e['field']] = $e;
    check('audit: male haemoglobin above the male soft range', isset($by['hb']) && $by['hb']['type'] === 'range'
        && $by['hb']['reason'] === 'soft-high' && $by['hb']['value'] === '18.2');
    check('audit: soft-high logged even with softBlock off', ($by['sbp']['reason'] ?? null) === 'soft-high');
    check('audit: comma-decimal value saved with a comma', ($by['temp_c']['reason'] ?? null) === 'hard-high');
    check('audit: calc above hard', ($by['bmi_calc']['reason'] ?? null) === 'hard-high');
    check('audit: slider above soft', ($by['pain']['reason'] ?? null) === 'soft-high');
    check('audit: text that is not a number', ($by['free_num']['reason'] ?? null) === 'not-a-number');
    check('audit: below hard', ($by['weight']['reason'] ?? null) === 'hard-low');
    check('audit: misconfigured rules log nothing as data',
        !isset($by['bad_email']) && !isset($by['bad_date']) && !isset($by['bad_order']));
    // Saving enrol_form re-checks hb, whose limits follow sex (saved there);
    // the other labs values, all outside their ranges, belong to the save of
    // labs_form and are left alone.
    $m = mod($DICT, $DATA, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'enrol_form', 351, null, null, null, 1);
    $fields = array_map(function ($e) { return $e['field']; },
        array_filter(findings($m), function ($e) { return ($e['type'] ?? '') === 'range'; }));
    check('audit: a save of the selector form re-checks only the rule it selects (got ' . json_encode(array_values($fields)) . ')',
        array_values($fields) === ['hb']);
    // Identical tags on two forms are one rule with two fields; a save audits
    // only the field of the form saved.
    $D2 = $DICT + ['enrol_num' => f('enrol_form', '@UVRANGE={"hard":[0,100]}', '')];
    $V2 = $DATA; $V2['2'][351]['enrol_num'] = '150';
    $m = mod($D2, $V2, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'enrol_form', 351, null, null, null, 1);
    $fields = array_map(function ($e) { return $e['field']; },
        array_filter(findings($m), function ($e) { return ($e['type'] ?? '') === 'range'; }));
    sort($fields);
    check('audit: one rule on two forms audits the saved form only (got ' . json_encode($fields) . ')',
        $fields === ['enrol_num', 'hb']);

    $f2 = $DATA; $f2['2'][351]['sex'] = '2';
    $m = mod($DICT, $f2, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'labs_form', 351, null, null, null, 1);
    $by = []; foreach (findings($m) as $e) $by[$e['field']] = $e;
    check('audit: female branch picked, 18.2 is above 15.5', ($by['hb']['reason'] ?? null) === 'soft-high');
    $f2['2'][351]['hb'] = '13'; $f2['2'][351]['sex'] = '1';
    $m = mod($DICT, $f2, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'labs_form', 351, null, null, null, 1);
    $by = []; foreach (findings($m) as $e) $by[$e['field']] = $e;
    check('audit: 13 is below the male soft range', ($by['hb']['reason'] ?? null) === 'soft-low');
    $f2['2'][351]['sex'] = '';
    $m = mod($DICT, $f2, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'labs_form', 351, null, null, null, 1);
    $by = []; foreach (findings($m) as $e) $by[$e['field']] = $e;
    check('audit: no sex, no branch applies, nothing logged', !isset($by['hb']));

    $ok = $DATA;
    $ok['2'][351] = array_merge($ok['2'][351], ['hb' => '15', 'sbp' => '120', 'temp_c' => '36.8', 'bmi_calc' => '22.1',
        'pain' => '3', 'free_num' => '42', 'weight' => '70']);
    $m = mod($DICT, $ok, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'labs_form', 351, null, null, null, 1);
    $hits = array_filter(findings($m), function ($e) { return ($e['type'] ?? '') === 'range'; });
    check('audit: usual values log nothing (comma field saved with a point too)', !$hits);

    $blank = $DATA;
    foreach (['hb', 'sbp', 'temp_c', 'bmi_calc', 'pain', 'free_num', 'weight'] as $k) $blank['2'][351][$k] = '';
    $m = mod($DICT, $blank, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'labs_form', 351, null, null, null, 1);
    check('audit: blank values check nothing',
        !array_filter(findings($m), function ($e) { return ($e['type'] ?? '') === 'range'; }));

    // ---- 4) the scan ---------------------------------------------------------------
    $m = mod($DICT, $DATA, $FULL, 'nurse');
    $res = $m->scanProject(149);
    $by = []; foreach ($res['violations'] as $v) $by[$v['field']] = $v['reason'];
    check('scan: the same verdicts', ($by['hb'] ?? null) === 'soft-high' && ($by['temp_c'] ?? null) === 'hard-high'
        && ($by['free_num'] ?? null) === 'not-a-number' && ($by['weight'] ?? null) === 'hard-low');
    check('scan: misconfigured rules are rule problems, not data', !isset($by['bad_order'])
        && (bool) array_filter($res['unconfigurable'], function ($u) { return in_array('bad_order', $u['fields'], true); }));

    $dims = \INSPIRE\UniversalValidator\ScanDimensions::build(149, $DICT, [
        ['type' => 'range', 'fields' => ['sbp'], 'rangeSoftHi' => '140', 'rangeHardLo' => '40', 'rangeHardHi' => '250',
         'rangeSoftText' => 'at most 140 mmHg', 'rangeHardText' => '40 to 250 mmHg'],
        ['type' => 'range', 'fields' => ['pain'], 'rangeSoftLo' => '0', 'rangeSoftHi' => '7', 'rangeSoftText' => '0 to 7'],
    ]);
    $C = \INSPIRE\UniversalValidator\MessageCatalog::class;
    check('report detail: soft', $C::detail(['type' => 'range', 'reason' => 'soft-high'], $dims->rule(1)) === 'Expected at most 140 mmHg.');
    check('report detail: hard', $C::detail(['type' => 'range', 'reason' => 'hard-low'], $dims->rule(1)) === 'Allowed 40 to 250 mmHg.');
    check('report detail: no hard tier, no sentence', $C::detail(['type' => 'range', 'reason' => 'hard-high'], $dims->rule(2)) === '');
    check('report detail: not a number has none', $C::detail(['type' => 'range', 'reason' => 'not-a-number'], $dims->rule(1)) === '');
    check('report wording: catalog, per reason',
        $C::explain(['type' => 'range', 'reason' => 'soft-low', 'rule' => 1], $dims->rule(1))
        === ['text' => 'The value is lower than usual for this field.', 'source' => 'catalog']
        && $C::explain(['type' => 'range', 'reason' => 'not-a-number', 'rule' => 1], $dims->rule(1))
        === ['text' => 'The value is not a number.', 'source' => 'catalog']);
    // A branched rule (hb: limits per sex) speaks with the branch that judged
    // the value: the men's limits for record 2 (sex 1), not the women's, not none.
    $m = mod($DICT, $DATA, $FULL, 'nurse');
    $res = $m->scanProject(149);
    $hb = array_values(array_filter($res['violations'], function ($v) { return $v['field'] === 'hb'; }));
    check('scan: a branched finding names its branch', count($hb) === 1 && ($hb[0]['branch'] ?? null) === 1);
    $dims = $m->scanDimensions(149, $res['rules'] ?? null);
    $cols = \INSPIRE\UniversalValidator\ScanColumns::all($dims);
    $row = \INSPIRE\UniversalValidator\ScanColumns::row($hb[0], $dims, $cols);
    check('report: the branch\'s limits in the detail sentence (got ' . json_encode($row['problem'] ?? null) . ')',
        ($row['problem'] ?? '') === 'The value is higher than usual for this field. Expected 13.5 to 17.5 g/dL.');
    $wt = array_values(array_filter($res['violations'], function ($v) { return $v['field'] === 'weight'; }));
    check('scan: an unbranched finding names no branch', count($wt) === 1 && !array_key_exists('branch', $wt[0]));
    $row = \INSPIRE\UniversalValidator\ScanColumns::row($wt[0], $dims, $cols);
    check('report: an unbranched rule keeps its own message', ($row['problem'] ?? '') === 'Check the scale. Allowed 0.5 to 250.');

    check('scan labels per tier', \INSPIRE\UniversalValidator\ModeRegistry::issueLabel('range', 'soft-high') === 'Unusual value'
        && \INSPIRE\UniversalValidator\ModeRegistry::issueLabel('range', 'hard-low') === 'Implausible value'
        && \INSPIRE\UniversalValidator\ModeRegistry::issueLabel('range', 'not-a-number') === 'Not a number');

    fwrite(STDOUT, "range_module_php: $n checks, $fail failure(s)\n");
    exit($fail ? 1 : 0);
}
