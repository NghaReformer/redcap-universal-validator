<?php
/**
 * growth_module_php.php — @UVRANGE growth references through the module (2.6.0).
 *
 * growth_php.php pins the z-score; this file pins the REDCap glue around it:
 *   - the grammar: "reference" with "sex", "male", "female" and one of "age"
 *     or "by"; what is refused without a reference and with one,
 *   - the dictionary hook: the reference must exist and read, its axis picks
 *     "age" or "by", and every input must be a field of the right kind; date
 *     types and formats and a decimal comma travel on the rule,
 *   - the page: inputs folded to ops (live, saved, withheld), limit texts as
 *     z-scores, only the tables in use (none for a rule with a withheld
 *     input), and the per-page table cap (deferredOnSave),
 *   - the post-save audit and the scan: soft / hard by z-score, a measurement
 *     at or below 0, blank and out-of-reference inputs that check nothing,
 *   - a folder of extra references (system setting "reference-data-dir"):
 *     added and replacing by id, and refused when its table does not match.
 *
 * Run:  php tests/growth_module_php.php
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
    class Project {
        public static $codes = '';
        public $project;
        public function __construct($pid) { $this->project = ['missing_data_codes' => self::$codes]; }
    }

    require_once __DIR__ . '/../UniversalValidator.php';
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';

    use INSPIRE\UniversalValidator\AnnotationRules;
    use INSPIRE\UniversalValidator\GrowthReference;
    use INSPIRE\UniversalValidator\ModeRegistry;

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    function f($form, $ann = '', $validation = '', $type = 'text', $choices = '') {
        return ['field_type' => $type, 'form_name' => $form, 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => $validation,
                'select_choices_or_calculations' => $choices];
    }
    function tag($ref, $inputs, $limits = '"soft":[-2,2],"hard":[-5,5]') {
        return '@UVRANGE={"reference":"' . $ref . '","sex":"[sex]","male":"1","female":"2",' . $inputs . ',' . $limits . '}';
    }
    $AGE = '"age":{"dob":"[dob]","at":"[visit_date]"}';
    $SEXES = '1, Male | 2, Female | 3, Not recorded';
    // enrol_form holds sex and the date of birth; anthro_form the measurements.
    $DICT = [
        'record_id'  => f('enrol_form'),
        'sex'        => f('enrol_form', '', '', 'radio', $SEXES),
        'dob'        => f('enrol_form', '', 'date_dmy'),
        'visit_date' => f('anthro_form', '', 'date_ymd'),
        'weight'     => f('anthro_form', tag('who-wfa', $AGE), 'number_1dp'),
        'height'     => f('anthro_form', '', 'number_1dp'),
        'wt_h'       => f('anthro_form', tag('who-wfh', '"by":"[height]"'), 'number_1dp'),
        'age_days'   => f('anthro_form', '', 'integer'),
        'hc'         => f('anthro_form', tag('who-hcfa', '"age":{"days":"[age_days]"}'), 'number_1dp'),
        'age_months' => f('anthro_form', '', 'number'),
        'bmi'        => f('anthro_form', tag('who2007-bfa', '"age":{"months":"[age_months]"}', '"hard":[-5,5]'), 'number_1dp'),
        'height_c'   => f('anthro_form', '', 'number_1dp_comma_decimal'),
        'wt_c'       => f('anthro_form', tag('who-wfh', '"by":"[height_c]"'), 'number_1dp_comma_decimal'),
    ];
    // Record 2: a boy born 10-01-2025, measured 2026-01-10 (365 days).
    $ROW = ['record_id' => '2', 'sex' => '1', 'dob' => '2025-01-10', 'visit_date' => '2026-01-10',
            'weight' => '6.6',  // z -3.40: unusual
            'height' => '80', 'wt_h' => '13',  // z 2.40: unusual
            'age_days' => '365', 'hc' => '30',  // far below: implausible
            'age_months' => '100', 'bmi' => '30',  // z 5.03: implausible
            'height_c' => '80,5', 'wt_c' => '6,0'];  // z -6.57: implausible
    $DATA = ['2' => [351 => $ROW]];
    $FULL = ['nurse' => ['forms' => ['enrol_form' => '1', 'anthro_form' => '1']]];

    function mod($dict, $data, $rights, $user, $sys = []) {
        $GLOBALS['__TEST_USER'] = $user;
        GrowthReference::reset();
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectSettings = ['log-values' => 'raw'];
        $m->systemSettings = $sys;
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
    function audit($dict, $data, $form = 'anthro_form', $sys = []) {
        global $FULL;
        $m = mod($dict, $data, $FULL, 'nurse', $sys);
        $m->redcap_save_record(149, '2', $form, 351, null, null, null, 1);
        $by = [];
        foreach (findings($m) as $e) if (($e['type'] ?? '') === 'range') $by[$e['field']] = $e['reason'];
        $un = [];
        foreach (findings($m, 'uvalidate-unconfigurable') as $e) $un[] = (string) $e['fields'] . ': ' . (string) $e['why'];
        $ne = [];
        foreach (findings($m, 'uvalidate-not-evaluated') as $e) $ne[$e['field']] = $e['why'];
        return [$by, $un, $ne, $m];
    }
    function errOf($tag, $opts = []) {
        $f = AnnotationRules::parseAllTags($tag, $opts);
        return isset($f[0]['error']) ? $f[0]['error'] : '';
    }
    function tmpdir() {
        $d = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uv_growth_' . bin2hex(random_bytes(6));
        mkdir($d);
        return $d;
    }
    function rmtree($d) {
        foreach (glob($d . DIRECTORY_SEPARATOR . '*') ?: [] as $x) is_dir($x) ? rmtree($x) : unlink($x);
        rmdir($d);
    }

    // ---- 1) the grammar --------------------------------------------------------------
    $ok = AnnotationRules::parseAllTags(tag('who-wfa', $AGE));
    check('a reference rule parses to its keys', isset($ok[0]) && !isset($ok[0]['error'])
        && $ok[0]['rangeReference'] === 'who-wfa' && $ok[0]['rangeSex'] === '[sex]' && $ok[0]['rangeMale'] === '1'
        && $ok[0]['rangeFemale'] === '2' && $ok[0]['rangeAgeDob'] === '[dob]' && $ok[0]['rangeAgeAt'] === '[visit_date]'
        && $ok[0]['rangeSoftLo'] === '-2' && $ok[0]['rangeHardHi'] === '5');
    $ok = AnnotationRules::parseAllTags('@UVRANGE={"reference":"who-wfh","sex":"[sex]","male":1,"female":2,"by":"[height]","hard":[-5,5]}');
    check('numeric sex codes are kept as text', !isset($ok[0]['error']) && $ok[0]['rangeMale'] === '1' && $ok[0]['rangeFemale'] === '2'
        && $ok[0]['rangeBy'] === '[height]');
    $ok = AnnotationRules::parseAllTags(tag('who-hcfa', '"age":{"days":"[age_days]"}') . ' ' . tag('who2007-bfa', '"age":{"months":"[m]"}'));
    check('age in days and in months parse', isset($ok[1]) && $ok[0]['rangeAgeDays'] === '[age_days]' && $ok[1]['rangeAgeMonths'] === '[m]');
    $refused = [
        'sex without a reference'   => ['@UVRANGE={"hard":[1,2],"sex":"[sex]"}', 'only apply with a "reference"'],
        'by without a reference'    => ['@UVRANGE={"hard":[1,2],"by":"[h]"}', 'only apply with a "reference"'],
        'reference not a string'    => ['@UVRANGE={"hard":[1,2],"reference":5}', '"reference" must be the id of a growth reference'],
        'reference blank'           => ['@UVRANGE={"hard":[1,2],"reference":" "}', '"reference" must be the id of a growth reference'],
        'reference with capitals'   => [tag('WHO-wfa', $AGE), '"reference" must be the id of a growth reference'],
        'unit with a reference'     => [tag('who-wfa', $AGE, '"hard":[-5,5],"unit":"kg"'), '"unit" does not apply with a "reference"'],
        'a z limit past 20'         => [tag('who-wfa', $AGE, '"hard":[-21,5]'), 'must lie between -20 and 20 — got -21'],
        'a high z limit past 20'    => [tag('who-wfa', $AGE, '"hard":[-5,20.5]'), 'must lie between -20 and 20 — got 20.5'],
        'no sex'                    => ['@UVRANGE={"reference":"who-wfa","male":"1","female":"2",' . $AGE . ',"hard":[-5,5]}', 'needs "sex"'],
        'no male code'              => ['@UVRANGE={"reference":"who-wfa","sex":"[sex]","female":"2",' . $AGE . ',"hard":[-5,5]}', 'needs "male"'],
        'female blank'              => ['@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":" ",' . $AGE . ',"hard":[-5,5]}', '"female" must be the code'],
        'male a list'               => ['@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":["1"],"female":"2",' . $AGE . ',"hard":[-5,5]}', '"male" must be the code'],
        'male and female the same'  => ['@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"1",' . $AGE . ',"hard":[-5,5]}', 'are the same code ("1")'],
        'male and female by case'   => ['@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"M","female":"m",' . $AGE . ',"hard":[-5,5]}', '"male" "M" and "female" "m" differ only in letter case, which this rule ignores. Add "caseSensitive": true'],
        'neither age nor by'        => [tag('who-wfa', '"message":"x"'), 'needs "age"'],
        'both age and by'           => [tag('who-wfa', $AGE . ',"by":"[height]"'), 'give "age" or "by", not both'],
        'age with dob only'         => [tag('who-wfa', '"age":{"dob":"[dob]"}'), '"age" must be {"dob":"[dob]","at":"[visit_date]"}'],
        'age with two units'        => [tag('who-wfa', '"age":{"days":"[a]","months":"[b]"}'), '"age" must be'],
        'age a string'              => [tag('who-wfa', '"age":"[age_days]"'), '"age" must be'],
        'age input not a string'    => [tag('who-wfa', '"age":{"days":5}'), '"age" "days" must be one field reference'],
        'sex not a string'          => ['@UVRANGE={"reference":"who-wfa","sex":1,"male":"1","female":"2",' . $AGE . ',"hard":[-5,5]}', '"sex" must be one field reference'],
        'sex a condition'           => ['@UVRANGE={"reference":"who-wfa","sex":"[sex]=\'1\'","male":"1","female":"2",' . $AGE . ',"hard":[-5,5]}', '"sex" must be exactly one field reference'],
        'by a checkbox code'        => [tag('who-wfh', '"by":"[h(1)]"'), '"by" must be exactly one field reference'],
        'dob in another event'      => [tag('who-wfa', '"age":{"dob":"[enrol_arm_1][dob]","at":"[visit_date]"}'), 'needs event and instance references'],
        'plausible, no reference'   => ['@UVRANGE={"hard":[1,2],"plausible":[0.5,30]}', '"plausible" and "measureScale" only apply with a "reference"'],
        'measureScale, no reference' => ['@UVRANGE={"hard":[1,2],"measureScale":0.001}', '"plausible" and "measureScale" only apply with a "reference"'],
        'plausible upside down'     => [tag('who-wfa', $AGE, '"hard":[-5,5],"plausible":[30,0.5]'), 'the "plausible" low limit (30) is above its high limit (0.5).'],
        'plausible not a pair'      => [tag('who-wfa', $AGE, '"hard":[-5,5],"plausible":5'), '"plausible" must be a list of two limits'],
        'plausible with no limit'   => [tag('who-wfa', $AGE, '"hard":[-5,5],"plausible":[null,null]'), '"plausible" needs at least one limit'],
        'plausible as text'         => [tag('who-wfa', $AGE, '"hard":[-5,5],"plausible":["a",30]'), 'the "plausible" low limit must be a number'],
        'measureScale of 0'         => [tag('who-wfa', $AGE, '"hard":[-5,5],"measureScale":0'), '"measureScale" must be a number from 0.000001 to 1000000, such as 0.001 for grams typed into a reference in kg — got 0.'],
        'measureScale past a million' => [tag('who-wfa', $AGE, '"hard":[-5,5],"measureScale":1e7'), '"measureScale" must be a number from 0.000001 to 1000000'],
        'measureScale negative'     => [tag('who-wfa', $AGE, '"hard":[-5,5],"measureScale":-0.001'), '"measureScale" must be a number from 0.000001'],
        'measureScale as a word'    => [tag('who-wfa', $AGE, '"hard":[-5,5],"measureScale":"grams"'), '"measureScale" must be a number such as 0.001 (grams typed into a reference in kg) — got "grams".'],
    ];
    foreach ($refused as $label => $pair) {
        $e = errOf($pair[0]);
        check('refused: ' . $label . ' (got ' . json_encode($e) . ')', $e !== '' && strpos($e, $pair[1]) !== false);
    }
    // The parser builds "dob" and "at" only as a pair; checkRange, which also
    // sees fragments built elsewhere, refuses one without the other.
    $half = AnnotationRules::checkRange(['type' => 'range', 'fields' => ['w'], 'rangeReference' => 'who-wfa',
        'rangeSex' => '[sex]', 'rangeMale' => '1', 'rangeFemale' => '2', 'rangeAgeDob' => '[dob]',
        'rangeHardLo' => '-5', 'rangeHardHi' => '5']);
    check('checkRange: "dob" without "at" (got ' . json_encode($half) . ')', in_array('"age" needs both "dob" and "at".', $half, true));
    check('dob in another event with references on: accepted',
        errOf(tag('who-wfa', '"age":{"dob":"[enrol_arm_1][dob]","at":"[visit_date]"}'), ['qualified' => true]) === '');
    check('a list of instances is refused even with references on',
        strpos(errOf(tag('who-wfa', '"age":{"dob":"[dob][all-instances]","at":"[visit_date]"}'), ['qualified' => true]),
            '"age" "dob" must be exactly one field reference') !== false);
    check('z limits of exactly -20 and 20 are fine', errOf(tag('who-wfa', $AGE, '"hard":[-20,20]')) === '');
    // "plausible" is in the reference's unit, not z-scores: 30 kg is no z limit past 20
    check('plausible and measureScale are taken, and plausible is not a z-score (got '
        . json_encode(errOf(tag('who-wfa', $AGE, '"hard":[-5,5],"plausible":[0.5,30],"measureScale":0.001'))) . ')',
        errOf(tag('who-wfa', $AGE, '"hard":[-5,5],"plausible":[0.5,30],"measureScale":0.001')) === '');
    check('a one-sided plausible and the measureScale bounds are taken',
        errOf(tag('who-wfa', $AGE, '"hard":[-5,5],"plausible":[null,30],"measureScale":0.000001')) === ''
        && errOf(tag('who-wfa', $AGE, '"hard":[-5,5],"measureScale":1000000')) === '');
    $pf = AnnotationRules::parseAllTags(tag('who-wfa', $AGE, '"hard":[-5,5],"plausible":[0.50,30],"measureScale":1e-3'));
    check('plausible and measureScale keep the digits typed (exponents written out)',
        ($pf[0]['rangePlausibleLo'] ?? null) === '0.50' && ($pf[0]['rangePlausibleHi'] ?? null) === '30'
        && ($pf[0]['rangeMeasureScale'] ?? null) === '0.001');
    check('male and female differing in case are fine with caseSensitive',
        errOf('@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"M","female":"m",' . $AGE . ',"hard":[-5,5],"caseSensitive":true}') === '');

    // ---- 2) the dictionary hook --------------------------------------------------------
    $p = page(mod($DICT, $DATA, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    $err = function ($p, $field) { $r = ruleFor($p, $field); return $r && isset($r['configError']) ? $r['configError'] : ''; };
    foreach (['weight', 'wt_h', 'hc', 'bmi', 'wt_c'] as $f) check($f . ': no configuration error (got ' . json_encode($err($p, $f)) . ')', ruleFor($p, $f) !== null && $err($p, $f) === '');
    $bad = $DICT + [
        'b_unknown' => f('anthro_form', tag('who-nope', $AGE), 'number'),
        'b_reach'   => f('anthro_form', tag('who-tsfa', $AGE, '"hard":[-7,5]'), 'number'),
        'b_reachok' => f('anthro_form', tag('who-tsfa', $AGE, '"hard":[-6.96,5]'), 'number'),
        'b_axis1'   => f('anthro_form', tag('who-wfh', $AGE), 'number'),
        'b_axis2'   => f('anthro_form', tag('who-wfa', '"by":"[height]"'), 'number'),
        'b_nofield' => f('anthro_form', tag('who-wfa', '"age":{"days":"[nope]"}'), 'number'),
        'b_self'    => f('anthro_form', tag('who-hcfa', '"age":{"days":"[b_self]"}'), 'number'),
        'b_dobtext' => f('anthro_form', tag('who-wfa', '"age":{"dob":"[height]","at":"[visit_date]"}'), 'number'),
        'b_daysdate' => f('anthro_form', tag('who-hcfa', '"age":{"days":"[visit_date]"}'), 'number'),
        'b_sexchk'  => f('anthro_form', '@UVRANGE={"reference":"who-wfa","sex":"[chk]","male":"1","female":"2",' . $AGE . ',"hard":[-5,5]}', 'number'),
        'b_code'    => f('anthro_form', '@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"M","female":"2",' . $AGE . ',"hard":[-5,5]}', 'number'),
        'b_yesno'   => f('anthro_form', '@UVRANGE={"reference":"who-wfa","sex":"[yn]","male":"1","female":"2",' . $AGE . ',"hard":[-5,5]}', 'number'),
        'b_textsex' => f('anthro_form', '@UVRANGE={"reference":"who-wfa","sex":"[sex_txt]","male":"M","female":"F",' . $AGE . ',"hard":[-5,5]}', 'number'),
        'b_daystxt' => f('anthro_form', tag('who-hcfa', '"age":{"days":"[age_txt]"}'), 'number'),
        'b_tf'      => f('anthro_form', '@UVRANGE={"reference":"who-wfa","sex":"[tf]","male":"1","female":"0",' . $AGE . ',"hard":[-5,5]}', 'number'),
        'b_tfbad'   => f('anthro_form', '@UVRANGE={"reference":"who-wfa","sex":"[tf]","male":"1","female":"2",' . $AGE . ',"hard":[-5,5]}', 'number'),
        'b_sqlsex'  => f('anthro_form', '@UVRANGE={"reference":"who-wfa","sex":"[sex_sql]","male":"M","female":"F",' . $AGE . ',"hard":[-5,5]}', 'number'),
        'b_calcsex' => f('anthro_form', '@UVRANGE={"reference":"who-wfa","sex":"[sex_calc]","male":"1","female":"2",' . $AGE . ',"hard":[-5,5]}', 'number'),
        'b_calcage' => f('anthro_form', tag('who-hcfa', '"age":{"days":"[age_calc]"}'), 'number'),
        'b_dts'     => f('anthro_form', tag('who-wfa', '"age":{"dob":"[dob_dts]","at":"[at_dt]"}'), 'number'),
        'b_monint'  => f('anthro_form', tag('who-wfa', '"age":{"months":"[age_mi]"}'), 'number'),
        'b_monint7' => f('anthro_form', tag('who2007-bfa', '"age":{"months":"[age_mi]"}'), 'number'),
        'b_monnum'  => f('anthro_form', tag('who-wfa', '"age":{"months":"[age_months]"}'), 'number'),
        'age_mi'    => f('anthro_form', '', 'integer'),
        // sex codes are matched with letter case ignored unless caseSensitive
        'b_sexfold'  => f('anthro_form', '@UVRANGE={"reference":"who-wfa","sex":"[sex_mf]","male":"m","female":"f",' . $AGE . ',"hard":[-5,5]}', 'number'),
        'b_sexcs'    => f('anthro_form', '@UVRANGE={"reference":"who-wfa","sex":"[sex_mf]","male":"m","female":"F",' . $AGE . ',"hard":[-5,5],"caseSensitive":true}', 'number'),
        'b_sexamb'   => f('anthro_form', '@UVRANGE={"reference":"who-wfa","sex":"[sex_amb]","male":"M","female":"F",' . $AGE . ',"hard":[-5,5]}', 'number'),
        'b_sexambcs' => f('anthro_form', '@UVRANGE={"reference":"who-wfa","sex":"[sex_amb]","male":"M","female":"F",' . $AGE . ',"hard":[-5,5],"caseSensitive":true}', 'number'),
        'sex_mf'    => f('enrol_form', '', '', 'radio', 'M, Male | F, Female'),
        'sex_amb'   => f('enrol_form', '', '', 'radio', 'm, male | M, Male | F, Female'),
        'chk'       => f('enrol_form', '', '', 'checkbox', '1, M | 2, F'),
        'yn'        => f('enrol_form', '', '', 'yesno'),
        'tf'        => f('enrol_form', '', '', 'truefalse'),
        'sex_txt'   => f('enrol_form'),
        'sex_sql'   => f('enrol_form', '', '', 'sql', 'select value, label from x'),
        'sex_calc'  => f('enrol_form', '', '', 'calc', 'if([sex]=1,1,2)'),
        'age_txt'   => f('anthro_form'),
        'age_calc'  => f('anthro_form', '', '', 'calc', 'datediff([dob],[visit_date],"d")'),
        'dob_dts'   => f('enrol_form', '', 'datetime_seconds_ymd'),
        'at_dt'     => f('anthro_form', '', 'datetime_dmy'),
    ];
    $p = page(mod($bad, $DATA, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    $cases = [
        'b_unknown'  => '"reference" "who-nope" is not a growth reference this server has — it has who-acfa, who-bfa',
        'b_axis1'    => '"who-wfh" is read by height — give "by", the field holding the height in cm, instead of "age".',
        'b_axis2'    => '"who-wfa" is read by age — give "age" instead of "by".',
        'b_nofield'  => '"age" "days" names "[nope]", which is not a field in this project.',
        'b_self'     => '"age" "days" names the measured field itself.',
        'b_dobtext'  => '"age" "dob" field "height" is not a date field',
        'b_daysdate' => '"age" "days" field "visit_date" is not a number field',
        'b_sexchk'   => '"sex" field "chk" is a checkbox field',
        'b_code'     => '"male" is "M", which is not a choice of "sex" field "sex" (its codes are 1, 2, 3).',
        'b_yesno'    => '"female" is "2", which is not a choice of "sex" field "yn" (its codes are 1, 0).',
        // a Text field with no validation could hold anything: a typo would only switch the check off
        'b_daystxt'  => '"age" "days" field "age_txt" is not a number field — it needs integer or number validation, or to be a calc.',
        'b_tfbad'    => '"female" is "2", which is not a choice of "sex" field "tf" (its codes are 1, 0).',
        // a limit no measurement can pass: below -6.97 for triceps skinfold
        'b_reach'    => '"reference" "who-tsfa": its "hard" low limit -7 is out of reach: with "who-tsfa" no measurement scores below -6.97 at ',
        // whole months on a table read by day
        'b_monint'   => '"age" "months" field "age_mi" holds whole months, but "who-wfa" is read by day, so an infant of 1 month and 29 days',
        'b_sexcs'    => '"male" is "m", which is not a choice of "sex" field "sex_mf" (its codes are M, F).',
        'b_sexamb'   => '"male" is "M", which matches the codes m and M of "sex" field "sex_amb" when letter case is ignored. Add "caseSensitive": true to tell them apart.',
    ];
    foreach ($cases as $f => $want) {
        check('dictionary: ' . $f . ' (got ' . json_encode($err($p, $f)) . ')', strpos($err($p, $f), $want) !== false
            && strpos($err($p, $f), '@UVRANGE on "' . $f . '"') === 0);
    }
    check('dictionary: a Text sex field takes any codes', $err($p, 'b_textsex') === '');
    check('dictionary: a limit just inside the reach is taken', $err($p, 'b_reachok') === '');
    foreach (['b_tf' => 'a true/false sex field with 1 and 0', 'b_sqlsex' => 'an SQL sex field takes any codes',
              'b_calcsex' => 'a calc sex field takes any codes', 'b_calcage' => 'a calc age in days',
              'b_dts' => 'datetime-with-seconds and datetime dates',
              'b_monint7' => 'whole months on a table read by month', 'b_monnum' => 'months as a number on a table read by day',
              'b_sexfold' => 'sex codes in another letter case', 'b_sexambcs' => 'codes differing in case, told apart with caseSensitive'] as $f => $label) {
        check('dictionary: ' . $label . ' (got ' . json_encode($err($p, $f)) . ')', ruleFor($p, $f) !== null && $err($p, $f) === '');
    }
    $r = ruleFor($p, 'b_dts');
    check('dictionary: datetime types and formats travel (got ' . json_encode([$r['rangeDobType'] ?? null, $r['rangeDobFormat'] ?? null,
        $r['rangeAtType'] ?? null, $r['rangeAtFormat'] ?? null]) . ')', $r && $r['rangeDobType'] === 'datetime_seconds'
        && $r['rangeDobFormat'] === 'ymd' && $r['rangeAtType'] === 'datetime' && $r['rangeAtFormat'] === 'dmy');

    // ---- 3) the page --------------------------------------------------------------------
    $p = page(mod($DICT, $DATA, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    $r = ruleFor($p, 'weight');
    check('page: dob on another form travels as its saved value, at as a live ref',
        $r && $r['rangeAgeDobOp'] === ['lit', '2025-01-10'] && $r['rangeAgeAtOp'] === ['ref', 'visit_date', null]
        && $r['rangeSexOp'] === ['lit', '1'] && in_array('sex', $r['snapshotFields'] ?? [], true)
        && in_array('dob', $r['snapshotFields'] ?? [], true));
    check('page: date types and formats travel', $r && $r['rangeDobType'] === 'date' && $r['rangeDobFormat'] === 'dmy'
        && $r['rangeAtType'] === 'date' && $r['rangeAtFormat'] === 'ymd');
    check('page: limits as z-score texts', $r && $r['rangeSoftText'] === 'z-score -2 to 2' && $r['rangeHardText'] === 'z-score -5 to 5');
    check('page: the reference id and the sex codes travel', $r && $r['rangeReference'] === 'who-wfa'
        && $r['rangeMale'] === '1' && $r['rangeFemale'] === '2');
    $r = ruleFor($p, 'wt_c');
    check('page: a comma input is marked, the measure too', $r && $r['rangeAxisComma'] === true && $r['decimalComma'] === true
        && $r['rangeByOp'] === ['ref', 'height_c', null] && $r['rangeSoftText'] === 'z-score -2 to 2');
    $r = ruleFor($p, 'wt_h');
    check('page: no comma input, not marked', $r && empty($r['rangeAxisComma']));
    $g = $p['cfg']['growth'] ?? [];
    $ids = array_keys($g); sort($ids);
    check('page: the tables in use, and only those, at most 4 (got ' . json_encode($ids) . ')',
        $ids === ['who-hcfa', 'who-wfa', 'who-wfh', 'who2007-bfa']);
    $wfa = $g['who-wfa'] ?? [];
    // The sex is saved on enrol_form (a boy): only the boys' rows travel.
    check('page: a table copy carries the index fields and the rows of the saved sex', ($wfa['title'] ?? '') === 'Weight-for-age, WHO 2006 (birth to 5 years)'
        && $wfa['axis'] === 'age' && $wfa['axisUnit'] === 'days' && $wfa['lookup'] === 'round' && $wfa['adjust'] === 'who-restricted'
        && $wfa['valid'] === ['min' => '0', 'below' => '1826.25'] && $wfa['scale'] === 1 && $wfa['first'] === 0
        && count($wfa['male']) === 1827 && $wfa['female'] === [] && !isset($wfa['sha256']) && !isset($wfa['file']));
    $deferred = array_filter($p['cfg']['rules'], function ($x) { return !empty($x['rangeReference']) && !empty($x['deferred']); });
    check('page: four tables fit, nothing deferred', !$deferred);
    $five = $DICT + ['muac' => f('anthro_form', tag('who-acfa', '"age":{"days":"[age_days]"}'), 'number_1dp')];
    $p5 = page(mod($five, $DATA, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    $r5 = ruleFor($p5, 'muac');
    check('page: with the sex saved each table is half a table, so five fit (got ' . json_encode(array_keys($p5['cfg']['growth'] ?? [])) . ')',
        $r5 && empty($r5['deferred']) && count($p5['cfg']['growth']) === 5);
    // The sex on this form: every row of both sexes, a whole table each.
    $LIVESEX = ['sex' => f('anthro_form', '', '', 'radio', $SEXES)] + $five;
    $p5 = page(mod($LIVESEX, $DATA, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    $r5 = ruleFor($p5, 'muac');
    check('page: a fifth whole table is one too many, its rule deferred with the reason (got ' . json_encode($r5['deferredWhy'] ?? null) . ')',
        $r5 && !empty($r5['deferred']) && count($p5['cfg']['growth']) === 4 && !isset($p5['cfg']['growth']['who-acfa'])
        && implode(' ', $r5['deferredWhy'] ?? []) === 'this form already carries the rows of 4 growth reference tables, and this one '
            . 'would take it past the most one page may carry (4), so this check runs when the record is saved.');
    check('page: the cap deferral is marked as checked on save', $r5 && ($r5['deferredOnSave'] ?? null) === true);
    $wt = ruleFor($p5, 'weight');
    check('page: a rule that fits is not marked', $wt && !isset($wt['deferredOnSave']));
    check('page: a whole table carries both sexes', count($p5['cfg']['growth']['who-wfa']['female'] ?? []) === 1827);
    // An age saved on another form: only the rows at that age travel, and they use none of the cap.
    $FIXED = $LIVESEX;
    $FIXED['age_days'] = f('enrol_form', '', 'integer');
    $p6 = page(mod($FIXED, $DATA, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    $hc = $p6['cfg']['growth']['who-hcfa'] ?? [];
    $entry = GrowthReference::entry('who-hcfa');
    $tbl = GrowthReference::table($entry);
    $want = GrowthReference::lms($entry, $tbl, 'male', 365.0);
    check('page: an age saved on another form sends the rows at that age, one either side (got ' . json_encode([$hc['first'] ?? null, count($hc['male'] ?? [])]) . ')',
        ($hc['first'] ?? null) === 364 && count($hc['male'] ?? []) === 3 && count($hc['female'] ?? []) === 3
        // == : the page copy went through JSON, which writes 1.0 as 1
        && $want !== null && GrowthReference::lms($entry, $hc, 'male', 365.0) == $want);
    $r6 = ruleFor($p6, 'muac');
    check('page: ... and use none of the cap: the fifth rule, at that age too, fits (got ' . json_encode(array_keys($p6['cfg']['growth'] ?? [])) . ')',
        $r6 && empty($r6['deferred']) && isset($p6['cfg']['growth']['who-acfa']));
    // One reference read at a live age by one rule and at a saved age by another: every row travels.
    $MIX = $DICT + ['age_fix' => f('enrol_form', '', 'integer'),
                    'hc_fix' => f('anthro_form', tag('who-hcfa', '"age":{"days":"[age_fix]"}'), 'number_1dp')];
    $mix = $DATA; $mix['2'][351]['age_fix'] = '365';
    $pm = page(mod($MIX, $mix, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    check('page: a live age and a saved age on one reference: every row of the saved sex travels (got '
        . json_encode([count($pm['cfg']['growth']['who-hcfa']['male'] ?? []), $pm['cfg']['growth']['who-hcfa']['first'] ?? null]) . ')',
        count($pm['cfg']['growth']['who-hcfa']['male'] ?? []) === 1827 && ($pm['cfg']['growth']['who-hcfa']['first'] ?? null) === 0);
    $NOAGE = $DATA; $NOAGE['2'][351]['age_days'] = '';
    $p7 = page(mod($FIXED, $NOAGE, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    check('page: a saved age that is blank needs no rows at all', ($p7['cfg']['growth']['who-hcfa']['male'] ?? null) === []);
    // A branch whose condition the server settled false never applies on this page: no table.
    $SET = $DICT + [
        'grp'    => f('enrol_form', '', '', 'radio', '1, A | 2, B'),
        'wt_set' => f('anthro_form', substr(tag('who-acfa', $AGE), 0, -1) . ',"when":"[grp]=\'2\'"} ' . tag('who-wfa', $AGE), 'number_1dp'),
    ];
    $set = $DATA; $set['2'][351]['grp'] = '1';
    $p8 = page(mod($SET, $set, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    check('page: a branch settled false by a saved value gets no table (got ' . json_encode(array_keys($p8['cfg']['growth'] ?? [])) . ')',
        !isset($p8['cfg']['growth']['who-acfa']) && isset($p8['cfg']['growth']['who-wfa']));
    // ... and is marked idle, so the page does not read the table it was not
    // sent as one it lost (a configuration error stopping both references)
    $r8 = ruleFor($p8, 'wt_set');
    $marks = $r8 ? array_map(function ($b) { return [$b['rangeReference'], !empty($b['growthIdle']), !empty($b['snapshotGate']),
        in_array('grp', $b['snapshotFields'] ?? [], true)]; }, $r8['branches'] ?? []) : [];
    check('page: the settled branch is idle; only its own reference reads grp when the page opens (got ' . json_encode($marks) . ')',
        $marks === [['who-acfa', true, true, true], ['who-wfa', false, false, false]]);
    // one rule settled false: the rule is idle
    $ONE = $DICT + ['grp' => f('enrol_form', '', '', 'radio', '1, A | 2, B'),
        'wt_one' => f('anthro_form', substr(tag('who-acfa', $AGE), 0, -1) . ',"when":"[grp]=\'2\'"}', 'number_1dp')];
    $one = $DATA; $one['2'][351]['grp'] = '1';
    $p8o = page(mod($ONE, $one, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    $r8o = ruleFor($p8o, 'wt_one');
    check('page: one rule settled false: no table, the rule is idle (got ' . json_encode($r8o) . ')',
        $r8o && !empty($r8o['growthIdle']) && !isset($p8o['cfg']['growth']['who-acfa']) && empty($r8o['deferred']));
    $set['2'][351]['grp'] = '2';
    $p8 = page(mod($SET, $set, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    check('page: ... and settled true, it does', isset($p8['cfg']['growth']['who-acfa']));
    // Only the branch that does not fit is deferred; the rule and its other branch stay.
    $BR5 = $LIVESEX + ['wt_b5' => f('anthro_form', tag('who-wfa', $AGE) . ' ' . tag('who-lhfa', $AGE), 'number_1dp')];
    unset($BR5['muac']);
    $p9 = page(mod($BR5, $DATA, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    $r9 = ruleFor($p9, 'wt_b5');
    $marks = $r9 ? array_map(function ($b) { return [$b['rangeReference'], !empty($b['deferred']), !empty($b['deferredOnSave'])]; }, $r9['branches'] ?? []) : [];
    check('page: of two references on one field, only the one past the cap is deferred (got ' . json_encode($marks) . ')',
        $r9 && empty($r9['deferred']) && $marks === [['who-wfa', false, false], ['who-lhfa', true, true]]);
    $none = ['record_id' => f('enrol_form'), 'x' => f('anthro_form', '@UVRANGE={"hard":[0,10]}', 'number')];
    $p0 = page(mod($none, $DATA, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    check('page: no growth rule, no tables', !isset($p0['cfg']['growth']));
    // A selector that cannot be resolved (a field on a repeating form read from a
    // form that does not repeat) sets aside its own reference only: on the page,
    // in the post-save audit and in the scan.
    $BLK = $DICT + [
        'grp_r'  => f('visit_rep', '', '', 'radio', '1, A | 2, B'),
        'wt_blk' => f('anthro_form', substr(tag('who-acfa', $AGE), 0, -1) . ',"when":"[grp_r]=\'2\'"} '
            . substr(tag('who-acfa', $AGE), 0, -1) . ',"soft":[-4,4]} ' . tag('who-wfa', $AGE), 'number_1dp'),
        'wt_blk1' => f('anthro_form', substr(tag('who-wfa', $AGE), 0, -1) . ',"when":"[grp_r]=\'2\'"} ' . tag('who-wfa', $AGE), 'number_1dp'),
        // the fixed limits set aside, who-wfa judged; and every group set aside
        'wt_lim' => f('anthro_form', '@UVRANGE={"hard":[1,40],"when":"[grp_r]=\'2\'"} ' . tag('who-wfa', $AGE), 'number_1dp'),
        'wt_all' => f('anthro_form', substr(tag('who-acfa', $AGE), 0, -1) . ',"when":"[grp_r]=\'2\'"} '
            . '@UVRANGE={"hard":[1,40],"when":"[grp_r]=\'1\'"}', 'number_1dp'),
        // one reference set aside, and a branch conflict in the fixed limits
        'wt_cf' => f('anthro_form', substr(tag('who-acfa', $AGE), 0, -1) . ',"when":"[grp_r]=\'2\'"} '
            . '@UVRANGE={"hard":[1,40],"when":"[sex]=\'1\'"} @UVRANGE={"hard":[1,30],"when":"[sex]<>\'2\'"}', 'number_1dp'),
    ];
    $blk = $DATA;
    $blk['2'][351]['wt_blk'] = '6.6'; $blk['2'][351]['wt_blk1'] = '6.6'; $blk['2'][351]['wt_lim'] = '6.6'; $blk['2'][351]['wt_all'] = '6.6'; $blk['2'][351]['wt_cf'] = '6.6';
    $blk['2']['repeat_instances'] = [351 => ['visit_rep' => [1 => ['grp_r' => '2'], 2 => ['grp_r' => '1']]]];
    $pb = page(mod($BLK, $blk, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    $rb = ruleFor($pb, 'wt_blk');
    $marks = $rb ? array_map(function ($b) { return [$b['rangeReference'], !empty($b['deferred']), !empty($b['selectorBlocked']),
        count($b['deferredWhy'] ?? [])]; }, $rb['branches'] ?? []) : [];
    check('page: an unresolved selector defers its own reference\'s branches only (got ' . json_encode([$marks, $rb['deferred'] ?? null]) . ')',
        $rb && empty($rb['deferred']) && $marks === [['who-acfa', true, true, 1], ['who-wfa', false, false, 0], ['who-acfa', true, true, 1]]);
    $rb1 = ruleFor($pb, 'wt_blk1');
    check('page: ... with one reference the field as a whole is deferred, as before (got ' . json_encode($rb1['deferredWhy'] ?? null) . ')',
        $rb1 && !empty($rb1['deferred']) && empty($rb1['branches'][0]['selectorBlocked'])
        && strpos(implode(' ', $rb1['deferredWhy'] ?? []), 'No branch can be chosen, so the field is not checked here.') !== false);
    list($by, $un) = audit($BLK, $blk);
    check('audit: an unresolved selector: who-wfa still judges (got ' . json_encode([$by, $un]) . ')', ($by['wt_blk'] ?? null) === 'soft-low');
    $ug = array_values(array_filter($un, function ($u) { return strpos($u, 'wt_blk: ') === 0; }));
    check('audit: ... and the reference set aside is named (got ' . json_encode($ug) . ')', count($ug) === 1
        && strpos($ug[0], 'a branch "when" condition for the reference "who-acfa" references "[grp_r]"') !== false
        && strpos($ug[0], 'No branch of it can be chosen, so that reference is not checked here; the rest of the field is.') !== false);
    $ul = array_values(array_filter($un, function ($u) { return strpos($u, 'wt_lim: ') === 0; }));
    check('audit: the fixed limits set aside are called limits, who-wfa judges (got ' . json_encode([$ul, $by['wt_lim'] ?? null]) . ')',
        count($ul) === 1 && ($by['wt_lim'] ?? null) === 'soft-low'
        && strpos($ul[0], 'a branch "when" condition for the limits without a reference references "[grp_r]"') !== false
        && strpos($ul[0], 'No branch of it can be chosen, so those limits are not checked here; the rest of the field is.') !== false);
    $uc = array_values(array_filter($un, function ($u) { return strpos($u, 'wt_cf: ') === 0; }));
    check('audit: a conflict beside a group set aside is reported alone (got ' . json_encode($uc) . ')',
        count($uc) === 1 && !isset($by['wt_cf']) && strpos($uc[0], '(branch conflict) — field skipped') !== false);
    $ua = array_values(array_filter($un, function ($u) { return strpos($u, 'wt_all: ') === 0; }));
    check('audit: every group set aside: each says the field is not checked (got ' . json_encode($ua) . ')',
        count($ua) === 2 && !isset($by['wt_all'])
        && count(array_filter($ua, function ($u) { return strpos($u, 'No branch can be chosen, so the field is not checked here.') !== false
            && strpos($u, 'the rest of the field') === false; })) === 2);
    $u1 = array_values(array_filter($un, function ($u) { return strpos($u, 'wt_blk1: ') === 0; }));
    check('audit: one reference: the whole field, in the words it had (got ' . json_encode($u1) . ')', count($u1) === 1 && !isset($by['wt_blk1'])
        && strpos($u1[0], 'wt_blk1: a branch "when" condition references "[grp_r]"') === 0
        && strpos($u1[0], 'No branch can be chosen, so the field is not checked here.') !== false);
    $res = mod($BLK, $blk, $FULL, 'nurse')->scanProject(149);
    $sv = []; foreach ($res['violations'] as $v) if ($v['field'] === 'wt_blk') $sv[] = $v['reason'];
    $su = array_values(array_filter($res['unconfigurable'], function ($u) { return in_array('wt_blk', (array) $u['fields'], true); }));
    check('scan: the same: who-wfa judged, who-acfa named (got ' . json_encode([$sv, $su]) . ')',
        $sv === ['soft-low'] && count($su) === 1 && strpos($su[0]['why'], 'for the reference "who-acfa"') !== false);
    // A survey respondent gets nothing of fields on other forms: those inputs are withheld.
    $ps = page(mod($DICT, $DATA, $FULL, 'nurse'), 'survey', '2', 'anthro_form');
    $r = ruleFor($ps, 'weight');
    check('survey: inputs on another form are withheld, the live one stays', $r && $r['rangeSexOp'] === ['withheld']
        && $r['rangeAgeDobOp'] === ['withheld'] && $r['rangeAgeAtOp'] === ['ref', 'visit_date', null]
        && strpos(json_encode($r), '2025-01-10') === false);
    check('survey: a rule with a withheld input gets no table, it cannot work out a z-score there (got '
        . json_encode(array_keys($ps['cfg']['growth'] ?? [])) . ')', !isset($ps['cfg']['growth']['who-wfa']));
    $ps5 = page(mod($five, $DATA, $FULL, 'nurse'), 'survey', '2', 'anthro_form');
    $r5 = ruleFor($ps5, 'muac');
    check('survey: withheld rules do not use up the page\'s table cap', $r5 && empty($r5['deferred']));
    // A user without rights to enrol_form gets the same as a survey.
    $pr = page(mod($DICT, $DATA, ['nurse' => ['forms' => ['enrol_form' => '0', 'anthro_form' => '1']]], 'nurse'), 'form', '2', 'anthro_form');
    $r = ruleFor($pr, 'weight');
    check('no rights to the inputs\' form: withheld', $r && $r['rangeSexOp'] === ['withheld'] && $r['rangeAgeDobOp'] === ['withheld']);

    // ---- 4) the post-save audit -----------------------------------------------------------
    list($by, $un) = audit($DICT, $DATA);
    check('audit: verdicts by z-score (got ' . json_encode($by) . ')', $by === ['weight' => 'soft-low', 'wt_h' => 'soft-high',
        'hc' => 'hard-low', 'bmi' => 'hard-high', 'wt_c' => 'hard-low']);
    check('audit: no rule problems (got ' . json_encode($un) . ')', !$un);
    $usual = $DATA;
    $usual['2'][351] = array_merge($ROW, ['weight' => '9.6', 'wt_h' => '10.5', 'hc' => '46', 'bmi' => '16', 'wt_c' => '10,5']);
    list($by) = audit($DICT, $usual);
    check('audit: usual values log nothing (got ' . json_encode($by) . ')', !$by);
    $girl = $DATA; $girl['2'][351]['sex'] = '2';
    list($by) = audit($DICT, $girl);
    check('audit: the female table judges a girl (6.6 kg at 1 year: z -2.56, still unusual)', ($by['weight'] ?? null) === 'soft-low');
    $girl['2'][351]['weight'] = '7.5';
    list($by) = audit($DICT, $girl);
    check('audit: 7.5 kg is usual for a girl (z -1.46), not for a boy (z -2.28)', !isset($by['weight']));
    $weird = $DATA;
    // bmi holds only spaces: a blank measurement (a plain '' never reaches the rule, getData leaves it out)
    $weird['2'][351] = array_merge($ROW, ['weight' => 'abc', 'wt_h' => '0', 'hc' => '-1', 'bmi' => '  ', 'wt_c' => '0,0']);
    list($by, $un) = audit($DICT, $weird);
    check('audit: not a number, 0 and below (got ' . json_encode($by) . ')', $by === ['weight' => 'not-a-number',
        'wt_h' => 'not-positive', 'hc' => 'not-positive', 'wt_c' => 'not-positive']);
    $noin = $DATA;
    $noin['2'][351]['sex'] = '';
    list($by, $un) = audit($DICT, $noin);
    check('audit: no sex, nothing checked, no problem', !$by && !$un);
    $noin['2'][351]['sex'] = '3';
    list($by, $un) = audit($DICT, $noin);
    check('audit: a sex code neither male nor female checks nothing', !$by && !$un);
    // A Text sex field: letter case is ignored unless the rule is caseSensitive.
    $TXT = $DICT;
    $TXT['sex_t'] = f('enrol_form');
    $txtTag = '@UVRANGE={"reference":"who-wfa","sex":"[sex_t]","male":"M","female":"F",' . $AGE . ',"soft":[-2,2],"hard":[-5,5]';
    $TXT['weight'] = f('anthro_form', $txtTag . '}', 'number_1dp');
    $txt = $DATA; $txt['2'][351]['sex_t'] = 'm';
    list($by, $un) = audit($TXT, $txt);
    check('audit: sex "m" is the male code "M" (got ' . json_encode([$by, $un]) . ')', ($by['weight'] ?? null) === 'soft-low' && !$un);
    $txt['2'][351]['sex_t'] = 'f'; $txt['2'][351]['weight'] = '7.5';
    list($by, $un) = audit($TXT, $txt);
    check('audit: sex "f" is the female code "F" (7.5 kg usual for a girl)', !isset($by['weight']) && !$un);
    $txt['2'][351]['weight'] = '6.0';
    list($by) = audit($TXT, $txt);
    check('audit: ... and judged on the female rows', ($by['weight'] ?? null) === 'soft-low');
    $TXT['weight'] = f('anthro_form', $txtTag . ',"caseSensitive":true}', 'number_1dp');
    list($by, $un) = audit($TXT, $txt);
    check('audit: with caseSensitive "f" is not "F", and nothing is checked', !isset($by['weight']) && !$un);
    $txt['2'][351]['sex_t'] = 'F';
    list($by) = audit($TXT, $txt);
    check('audit: ... "F" is', ($by['weight'] ?? null) === 'soft-low');
    $TXT['weight'] = f('anthro_form', $txtTag . '}', 'number_1dp');
    $txt['2'][351]['sex_t'] = 'm';
    $pt = page(mod($TXT, $txt, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    check('page: a saved sex "m" sends the rows of the male code "M" only (got ' . json_encode([count($pt['cfg']['growth']['who-wfa']['male'] ?? []),
        count($pt['cfg']['growth']['who-wfa']['female'] ?? [])]) . ')',
        count($pt['cfg']['growth']['who-wfa']['male'] ?? []) === 1827 && ($pt['cfg']['growth']['who-wfa']['female'] ?? null) === []);
    $TXT['weight'] = f('anthro_form', $txtTag . ',"caseSensitive":true}', 'number_1dp');
    $pt = page(mod($TXT, $txt, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    check('page: ... with caseSensitive, "m" is neither code: no rows', ($pt['cfg']['growth']['who-wfa']['male'] ?? null) === []
        && ($pt['cfg']['growth']['who-wfa']['female'] ?? null) === []);
    $noin['2'][351]['sex'] = '3'; $noin['2'][351]['weight'] = 'abc';
    list($by) = audit($DICT, $noin);
    check('audit: a value that is not a number is reported whatever the inputs', ($by['weight'] ?? null) === 'not-a-number');
    $old = $DATA;
    $old['2'][351] = array_merge($ROW, ['dob' => '2019-01-10', 'height' => '130', 'age_days' => '', 'age_months' => '250', 'height_c' => '44,9']);
    list($by, $un) = audit($DICT, $old);
    check('audit: outside the reference (age, height, blank age, length), nothing checked (got ' . json_encode([$by, $un]) . ')', !$by && !$un);
    $before = $DATA; $before['2'][351]['visit_date'] = '2024-12-31';
    list($by, $un) = audit($DICT, $before);
    check('audit: measured before birth, nothing checked', !isset($by['weight']) && !$un);
    $edge = $DATA; $edge['2'][351]['height'] = '120'; $edge['2'][351]['wt_h'] = '60';
    list($by) = audit($DICT, $edge);
    check('audit: the top of a "max" range is inside it (120 cm)', ($by['wt_h'] ?? null) === 'hard-high');
    $edge['2'][351]['height'] = '120.01';
    list($by) = audit($DICT, $edge);
    check('audit: just past it is outside', !isset($by['wt_h']));
    // Missing Data Codes: a code in an input is a blank input.
    \Project::$codes = "UNK, Unknown\nNASK, Not asked";
    $mdc = $DATA; $mdc['2'][351]['dob'] = 'UNK'; $mdc['2'][351]['height'] = 'NASK'; $mdc['2'][351]['hc'] = 'NASK';
    list($by, $un) = audit($DICT, $mdc);
    check('audit: an input holding a Missing Data Code checks nothing (got ' . json_encode([$by, $un]) . ')',
        !isset($by['weight']) && !isset($by['wt_h']) && !isset($by['hc']) && !$un);
    \Project::$codes = '';
    // A saved date that does not read as one is a rule problem for a value there is to judge.
    // A saved date that does not read as one is the value's problem, not the rule's:
    // the value is not evaluated, and the log says why (the browser's words).
    $baddate = $DATA; $baddate['2'][351]['dob'] = '10-01-2025';
    list($by, $un, $ne) = audit($DICT, $baddate);
    check('audit: an unreadable date of birth: not evaluated, not a rule problem (got ' . json_encode([$un, $ne]) . ')', !isset($by['weight'])
        && !$un && ($ne['weight'] ?? null) === 'the date of birth is not a date this rule can read');
    $baddate['2'][351]['weight'] = '';
    list($by, $un, $ne) = audit($DICT, $baddate);
    check('audit: ... and a blank value is not listed at all', !$un && !isset($ne['weight']));
    // Saving the form that holds the inputs re-checks the measurements.
    list($by) = audit($DICT, $DATA, 'enrol_form');
    check('audit: a save of enrol_form re-checks the rules whose inputs it holds (got ' . json_encode(array_keys($by)) . ')',
        isset($by['weight']));

    // ---- 5) the scan ------------------------------------------------------------------------
    // A scan that shows values: the project allows it and so does the reader.
    function rawScan($m) {
        $m->projectSettings['scan-value-storage'] = 'raw';
        return $m->scanProject(149, null, 200, null, ['valueCeiling' => 'raw']);
    }
    $m = mod($DICT, $DATA, $FULL, 'nurse');
    $res = rawScan($m);
    $by = []; foreach ($res['violations'] as $v) $by[$v['field']] = $v['reason'];
    ksort($by);
    check('scan: the same verdicts (got ' . json_encode($by) . ')', $by === ['bmi' => 'hard-high', 'hc' => 'hard-low',
        'weight' => 'soft-low', 'wt_c' => 'hard-low', 'wt_h' => 'soft-high']);
    $dims = $m->scanDimensions(149, $res['rules'] ?? null);
    $cols = \INSPIRE\UniversalValidator\ScanColumns::all($dims);
    $wt = array_values(array_filter($res['violations'], function ($v) { return $v['field'] === 'weight'; }));
    $row = \INSPIRE\UniversalValidator\ScanColumns::row($wt[0], $dims, $cols);
    check('scan: the detail names the z-score limits (got ' . json_encode($row['problem'] ?? null) . ')',
        ($row['problem'] ?? '') === 'The value is lower than usual for this field. Expected z-score -2 to 2. '
            . 'Its z-score is -3.40 on the growth reference who-wfa.');
    // The z-score gives the measurement back, so a scan that withholds the
    // value withholds it too; the reference is the rule's and stays.
    $m = mod($DICT, $DATA, $FULL, 'nurse');
    $res = $m->scanProject(149);
    $wt = array_values(array_filter($res['violations'], function ($v) { return $v['field'] === 'weight'; }));
    check('scan: values withheld, no z-score on the finding, the reference kept (got ' . json_encode($wt[0] ?? null) . ')',
        $wt && !empty($wt[0]['valueWithheld']) && !isset($wt[0]['z']) && ($wt[0]['reference'] ?? null) === 'who-wfa');
    $dims = $m->scanDimensions(149, $res['rules'] ?? null);
    $row = \INSPIRE\UniversalValidator\ScanColumns::row($wt[0], $dims, \INSPIRE\UniversalValidator\ScanColumns::all($dims));
    check('scan: values withheld, the detail has no z-score (got ' . json_encode($row['problem'] ?? null) . ')',
        ($row['problem'] ?? '') === 'The value is lower than usual for this field. Expected z-score -2 to 2.');
    // identifiers withheld: no z-score at all. The value of an identifier field
    // is redacted, and the z-score of any other one, with its value and the sex,
    // gives back the age, so a date of birth that is an identifier.
    $IDD = $DICT; $IDD['weight']['identifier'] = 'y'; $IDD['dob']['identifier'] = 'y';
    $m = mod($IDD, $DATA, $FULL, 'nurse');
    $m->projectSettings['scan-value-storage'] = 'identifier-redacted';
    $res = $m->scanProject(149, null, 200, null, ['valueCeiling' => 'raw']);
    $zs = [];
    foreach ($res['violations'] as $v) if (in_array($v['field'], ['weight', 'bmi'], true)) $zs[$v['field']] = [$v['value'], $v['z'] ?? null, $v['reference'] ?? null];
    check('scan: identifiers withheld, no z-score beside any value, the reference kept (got ' . json_encode($zs) . ')',
        ($zs['weight'] ?? null) === ['[identifier withheld]', null, 'who-wfa'] && ($zs['bmi'] ?? null) === ['30', null, 'who2007-bfa']);
    $m = mod($DICT, $weird, $FULL, 'nurse');
    $res = $m->scanProject(149);
    $np = array_values(array_filter($res['violations'], function ($v) { return $v['field'] === 'wt_h'; }));
    check('scan: not-positive found and labelled', count($np) === 1 && $np[0]['reason'] === 'not-positive'
        && ModeRegistry::issueLabel('range', 'not-positive') === 'Not a positive measurement');
    $dims = $m->scanDimensions(149, $res['rules'] ?? null);
    check('scan: not-positive has its catalog wording',
        \INSPIRE\UniversalValidator\MessageCatalog::explain(['type' => 'range', 'reason' => 'not-positive', 'rule' => 1], $dims->rule(1))
        === ['text' => 'The measurement is 0 or below.', 'source' => 'catalog']);
    check('scan: not-positive has its survey wording',
        \INSPIRE\UniversalValidator\MessageCatalog::explain(['type' => 'range', 'reason' => 'not-positive', 'rule' => 1], $dims->rule(1), 'survey')
        === ['text' => 'Please enter a measurement above 0.', 'source' => 'catalog']);
    // a hard finding's detail names the hard z-score limits
    $m = mod($DICT, $DATA, $FULL, 'nurse');
    $res = rawScan($m);
    $dims = $m->scanDimensions(149, $res['rules'] ?? null);
    $cols = \INSPIRE\UniversalValidator\ScanColumns::all($dims);
    $bm = array_values(array_filter($res['violations'], function ($v) { return $v['field'] === 'bmi'; }));
    $row = $bm ? \INSPIRE\UniversalValidator\ScanColumns::row($bm[0], $dims, $cols) : [];
    check('scan: a hard finding names the hard z-score limits (got ' . json_encode([$row['issue'] ?? null, $row['problem'] ?? null]) . ')',
        ($row['issue'] ?? '') === 'Implausible value'
        && ($row['problem'] ?? '') === 'The value is above the highest plausible value for this field. Allowed z-score -5 to 5. '
            . 'Its z-score is 5.03 on the growth reference who2007-bfa.');

    // ---- 5b) more inputs: trim, overflow, dates with a time, "when", branches ------------------
    // \v is not whitespace in the browser (QRID_whenTrim): the height is not a number there, so not here either
    $vt = $DATA; $vt['2'][351]['height'] = "80\x0B";
    list($by) = audit($DICT, $vt);
    check('audit: a height ending in a vertical tab is not a number, as in the browser', !isset($by['wt_h']));
    $huge = $DATA; $huge['2'][351]['weight'] = '1' . str_repeat('0', 320);
    list($by, $un) = audit($DICT, $huge);
    check('audit: a measurement past what a float holds is implausible, not a rule problem (got ' . json_encode([$by, $un]) . ')',
        ($by['weight'] ?? null) === 'hard-high' && !$un);
    $DT = $DICT + [
        'dob_dts' => f('enrol_form', '', 'datetime_seconds_ymd'),
        'at_dt'   => f('anthro_form', '', 'datetime_ymd'),
        'wt_dt'   => f('anthro_form', tag('who-wfa', '"age":{"dob":"[dob_dts]","at":"[at_dt]"}'), 'number_1dp'),
    ];
    $dt = $DATA;
    $dt['2'][351] += ['dob_dts' => '2025-01-10 23:59:59', 'at_dt' => '2026-01-10 00:01', 'wt_dt' => '6.6'];
    list($by, $un) = audit($DT, $dt);
    check('audit: dates with a time count their dates only (365 days, z -3.40) (got ' . json_encode([$by, $un]) . ')',
        ($by['wt_dt'] ?? null) === 'soft-low' && !$un);
    $dt['2'][351]['at_dt'] = '2026-01-09 23:59';
    list($by) = audit($DT, $dt);
    check('audit: ... and a day earlier is 364 days, still read by date', ($by['wt_dt'] ?? null) === 'soft-low');
    // the oedema recipe: "when" switches the rule off
    $OE = $DICT + [
        'oedema' => f('anthro_form', '', '', 'yesno'),
        'muac'   => f('anthro_form', substr(tag('who-acfa', $AGE, '"hard":[-5,5]'), 0, -1) . ',"when":"[oedema]<>\'1\'"}', 'number_1dp'),
    ];
    $oe = $DATA; $oe['2'][351] += ['oedema' => '0', 'muac' => '9'];
    list($by, $un) = audit($OE, $oe);
    check('audit: "when" true, the arm circumference is judged (z -5.72) (got ' . json_encode([$by, $un]) . ')',
        ($by['muac'] ?? null) === 'hard-low' && !$un);
    $res = mod($OE, $oe, $FULL, 'nurse')->scanProject(149);
    $mu = array_values(array_filter($res['violations'], function ($v) { return $v['field'] === 'muac'; }));
    check('scan: "when" true, judged', count($mu) === 1 && $mu[0]['reason'] === 'hard-low');
    $oe['2'][351]['oedema'] = '1';
    list($by, $un) = audit($OE, $oe);
    check('audit: "when" false (oedema), nothing checked', !isset($by['muac']) && !$un);
    $res = mod($OE, $oe, $FULL, 'nurse')->scanProject(149);
    check('scan: "when" false, nothing', !array_filter($res['violations'], function ($v) { return $v['field'] === 'muac'; }));
    $r = ruleFor(page(mod($OE, $oe, $FULL, 'nurse'), 'form', '2', 'anthro_form'), 'muac');
    check('page: the "when" travels with the growth rule', $r && ($r['when'] ?? null) === "[oedema]<>'1'" && empty($r['configError']));
    // a branched rule whose branches use different references: the page carries both tables
    $BR = $DICT + [
        'how'   => f('anthro_form', '', '', 'radio', '1, By age | 2, By height'),
        'wt_br' => f('anthro_form', substr(tag('who-wfa', $AGE), 0, -1) . ',"when":"[how]=\'1\'"} '
                                  . substr(tag('who-wfh', '"by":"[height]"'), 0, -1) . ',"when":"[how]=\'2\'"}', 'number_1dp'),
    ];
    $br = $DATA; $br['2'][351] += ['how' => '2', 'wt_br' => '13'];
    $p = page(mod($BR, $br, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    $r = ruleFor($p, 'wt_br');
    $refs = $r ? array_map(function ($b) { return $b['rangeReference'] ?? null; }, $r['branches'] ?? []) : [];
    check('page: a branched growth rule keeps each branch\'s reference (got ' . json_encode($refs) . ')', $refs === ['who-wfa', 'who-wfh']
        && isset($p['cfg']['growth']['who-wfa'], $p['cfg']['growth']['who-wfh']) && empty($r['deferred']));
    list($by) = audit($BR, $br);
    check('audit: the branch that applies judges (by height: 13 kg at 80 cm, z 2.40)', ($by['wt_br'] ?? null) === 'soft-high');

    // ---- 5d) several references on one field: each judges, all must pass ---------------------
    // 6.6 kg, a boy of 365 days and 80 cm: weight-for-age z -3.40 (unusual), weight-for-height
    // z -5.59 (implausible). 12.5 kg: -for-age z 2.39 (unusual), -for-height z 1.95 (fine).
    $TWO = $DICT + [
        'wt_two'  => f('anthro_form', tag('who-wfa', $AGE) . ' ' . tag('who-wfh', '"by":"[height]"'), 'number_1dp'),
        'wt_same' => f('anthro_form', tag('who-wfa', $AGE) . ' ' . tag('who-wfa', $AGE, '"hard":[-6,5]'), 'number_1dp'),
        'wt_fix'  => f('anthro_form', '@UVRANGE={"hard":[0.5,40]} ' . tag('who-wfa', $AGE), 'number_1dp'),
        'wt_band' => f('anthro_form', substr(tag('who-wfa', $AGE), 0, -1) . ',"when":"[age_days]<1826"} '
                                    . substr(tag('who2007-wfa', $AGE), 0, -1) . ',"when":"[age_days]>=1826"} '
                                    . tag('who-wfh', '"by":"[height]"'), 'number_1dp'),
    ];
    $two = $DATA; $two['2'][351] += ['wt_two' => '6.6', 'wt_same' => '6.6', 'wt_fix' => '6.6', 'wt_band' => '6.6'];
    $p = page(mod($TWO, $two, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    $r = ruleFor($p, 'wt_two');
    $refs = $r ? array_map(function ($b) { return [$b['rangeReference'] ?? null, $b['when'] ?? null]; }, $r['branches'] ?? []) : [];
    check('several references: two tags with no "when" and different references are one branched rule, an else each (got '
        . json_encode([$refs, $r['configError'] ?? null]) . ')',
        $refs === [['who-wfa', null], ['who-wfh', null]] && empty($r['configError']));
    check('several references: the page carries both tables', isset($p['cfg']['growth']['who-wfa'], $p['cfg']['growth']['who-wfh']));
    check('several references: two with no "when" and the SAME reference are refused, naming the reference (got '
        . json_encode($err($p, 'wt_same')) . ')',
        strpos($err($p, 'wt_same'), 'covered by 2 rules with no "when" condition and the same "reference"') !== false);
    check('several references: fixed limits are a reference of their own', $err($p, 'wt_fix') === '');
    $r = ruleFor($p, 'wt_band');
    check('several references: age bands and a third reference (got ' . json_encode($r['configError'] ?? null) . ')',
        $r && count($r['branches'] ?? []) === 3 && empty($r['configError']));
    $sevAudit = function ($dict, $data) {
        global $FULL;
        $m = mod($dict, $data, $FULL, 'nurse');
        $m->redcap_save_record(149, '2', 'anthro_form', 351, null, null, null, 1);
        $out = [];
        foreach (findings($m) as $e) if (($e['type'] ?? '') === 'range') $out[$e['field']][] = $e['reason'];
        foreach ($out as $k => $v) sort($out[$k]);
        $un = [];
        foreach (findings($m, 'uvalidate-unconfigurable') as $e) $un[] = (string) $e['fields'] . ': ' . (string) $e['why'];
        return [$out, $un];
    };
    list($sev, $un) = $sevAudit($TWO, $two);
    check('audit: each reference judges the weight, one finding each (got ' . json_encode([$sev, $un]) . ')',
        ($sev['wt_two'] ?? null) === ['hard-low', 'soft-low'] && !$un);
    check('audit: fixed limits pass, the reference does not', ($sev['wt_fix'] ?? null) === ['soft-low']);
    check('audit: the age band that applies, and the third reference', ($sev['wt_band'] ?? null) === ['hard-low', 'soft-low']);
    // A blank height leaves who-wfh without a z-score: that reference's value is
    // not evaluated, and who-wfa still judges.
    $nh = $two; $nh['2'][351]['height'] = '';
    list(, , $ne, $mm) = audit($TWO, $nh);
    $nel = array_values(array_filter(findings($mm, 'uvalidate-not-evaluated'), function ($e) { return $e['field'] === 'wt_two'; }));
    check('audit: two references, a blank height: who-wfh not evaluated, named (got ' . json_encode($nel) . ')',
        count($nel) === 1 && $nel[0]['reference'] === 'who-wfh' && $nel[0]['why'] === 'the height is blank');
    list($sev) = $sevAudit($TWO, $nh);
    check('audit: ... and who-wfa still judges', ($sev['wt_two'] ?? null) === ['soft-low']);
    // Two branches of ONE reference whose conditions are both true: a conflict, nothing judged.
    $CONF = $TWO + ['wt_conf' => f('anthro_form', substr(tag('who-wfa', $AGE), 0, -1) . ',"when":"[age_days]>0"} '
        . substr(tag('who-wfa', $AGE), 0, -1) . ',"when":"[age_days]>1"}', 'number_1dp')];
    $cf = $two; $cf['2'][351]['wt_conf'] = '6.6';
    list($sev, $un) = $sevAudit($CONF, $cf);
    check('audit: two true conditions on one reference are a branch conflict, nothing judged (got ' . json_encode([$sev['wt_conf'] ?? null, $un]) . ')',
        !isset($sev['wt_conf']) && count(array_filter($un, function ($u) { return strpos($u, 'wt_conf: more than one "when" condition is true') === 0; })) === 1);
    // Each scan finding of a field with several references names its reference (its locus).
    $res = mod($TWO, $two, $FULL, 'nurse')->scanProject(149);
    $loci = [];
    foreach ($res['violations'] as $v) if ($v['field'] === 'wt_two') $loci[] = $v['locus'];
    sort($loci);
    check('scan: each finding names its reference as its locus (got ' . json_encode($loci) . ')', $loci === ['who-wfa', 'who-wfh']);
    $two['2'][351]['wt_two'] = '12.5';
    list($sev) = $sevAudit($TWO, $two);
    check('audit: one reference out of range is one finding', ($sev['wt_two'] ?? null) === ['soft-high']);
    $two['2'][351]['wt_two'] = '9.5';
    list($sev) = $sevAudit($TWO, $two);
    check('audit: all references pass, nothing', !isset($sev['wt_two']));
    $two['2'][351]['wt_two'] = '6.6';
    $res = mod($TWO, $two, $FULL, 'nurse')->scanProject(149);
    $sc = [];
    foreach ($res['violations'] as $v) if ($v['field'] === 'wt_two') $sc[] = $v['reason'];
    sort($sc);
    check('scan: one finding per reference (got ' . json_encode($sc) . ')', $sc === ['hard-low', 'soft-low']);
    // The durable scan keeps both: each names its reference (the locus of its identity).
    $m = mod($TWO, $two, $FULL, 'nurse');
    $ctx = $m->durableScanContext(149, ['generation' => null]);
    $dur = $m->durableEvaluateRecord($ctx['plan'], 149, '2', $two['2'], 1, str_repeat('k', 32), $ctx['plan']['ruleIds']);
    $dw = array_values(array_filter($dur['findings'], function ($x) { return $x['field'] === 'wt_two'; }));
    check('durable: two findings on the field, two identities, nothing collapsed (got ' . count($dw) . ', ' . $dur['collapsed'] . ')',
        count($dw) === 2 && $dw[0]['identity'] !== $dw[1]['identity'] && $dur['collapsed'] === 0);
    // A finding's identity names its reference only when the rule has several: a field
    // with one growth rule keeps the identity it had.
    $m = mod($DICT, $DATA, $FULL, 'nurse');
    $m->projectSettings['scan-value-storage'] = 'raw';
    $ctx = $m->durableScanContext(149, ['generation' => null, 'valueCeiling' => 'raw']);
    $dur = $m->durableEvaluateRecord($ctx['plan'], 149, '2', $DATA['2'], 1, str_repeat('k', 32), $ctx['plan']['ruleIds']);
    $dw = array_values(array_filter($dur['findings'], function ($x) { return $x['field'] === 'weight'; }));
    $loc = ['record' => '2', 'event_id' => 351, 'instance' => 1, 'host_form' => 'anthro_form', 'field' => 'weight',
            'rule_source_id' => $dw ? $dw[0]['rule_source_id'] : '', 'reason_code' => \INSPIRE\UniversalValidator\Scan\ReasonCode::code('soft-low'), 'locus' => ''];
    check('durable: one growth rule, no locus in its identity',
        count($dw) === 1 && $dw[0]['identity'] === \INSPIRE\UniversalValidator\Scan\Hmac::findingIdentity(149, $loc, str_repeat('k', 32)));
    check('durable: the finding row carries its z-score and reference beside its value (got '
        . json_encode([$dw[0]['value_bin'] ?? null, $dw[0]['z_score'] ?? null, $dw[0]['growth_reference'] ?? null]) . ')',
        ($dw[0]['value_bin'] ?? null) === '6.6' && ($dw[0]['z_score'] ?? null) === '-3.40' && ($dw[0]['growth_reference'] ?? null) === 'who-wfa');
    $m = mod($DICT, $DATA, $FULL, 'nurse');
    $ctx = $m->durableScanContext(149, ['generation' => null]);
    $dur = $m->durableEvaluateRecord($ctx['plan'], 149, '2', $DATA['2'], 1, str_repeat('k', 32), $ctx['plan']['ruleIds']);
    $dw = array_values(array_filter($dur['findings'], function ($x) { return $x['field'] === 'weight'; }));
    check('durable: values withheld, no value and no z-score stored, the reference kept (got '
        . json_encode([$dw[0]['value_bin'] ?? null, $dw[0]['z_score'] ?? null, $dw[0]['growth_reference'] ?? null]) . ')',
        count($dw) === 1 && $dw[0]['value_bin'] === null && $dw[0]['z_score'] === null && $dw[0]['growth_reference'] === 'who-wfa');
    // two references on one field: each finding names its own
    $m = mod($TWO, $two, $FULL, 'nurse');
    $ctx = $m->durableScanContext(149, ['generation' => null]);
    $dur = $m->durableEvaluateRecord($ctx['plan'], 149, '2', $two['2'], 1, str_repeat('k', 32), $ctx['plan']['ruleIds']);
    $gr = [];
    foreach ($dur['findings'] as $x) if ($x['field'] === 'wt_two') $gr[] = $x['growth_reference'];
    sort($gr);
    check('durable: two references on one field, each finding names its own (got ' . json_encode($gr) . ')', $gr === ['who-wfa', 'who-wfh']);
    check('detail: the durable row\'s reference is used when the rule names none',
        \INSPIRE\UniversalValidator\MessageCatalog::detail(['type' => 'range', 'reason' => 'soft-low', 'z_score' => '-3.40',
            'growth_reference' => 'who-wfh'], []) === 'Its z-score is -3.40 on the growth reference who-wfh.');

    // ---- 5e) no z-score: "plausible", "measureScale", the z-score on findings, values not evaluated ----
    $PL = $DICT;
    $PL['weight'] = f('anthro_form', tag('who-wfa', $AGE, '"soft":[-2,2],"hard":[-5,5],"plausible":[2,30]'), 'number_1dp');
    $PL['wt_g'] = f('anthro_form', tag('who-wfa', $AGE, '"soft":[-2,2],"hard":[-5,5],"plausible":[2,30],"measureScale":0.001'), 'integer');
    $pl = $DATA; $pl['2'][351]['wt_g'] = '6600';
    list($by, $un, $ne, $m) = audit($PL, $pl);
    check('measureScale: 6600 g reads as 6.6 kg, the same z-score (got ' . json_encode([$by['wt_g'] ?? null, $by['weight'] ?? null]) . ')',
        ($by['wt_g'] ?? null) === 'soft-low' && ($by['weight'] ?? null) === 'soft-low' && !$ne);
    $logged = [];
    foreach (findings($m) as $e) if (($e['type'] ?? '') === 'range') $logged[$e['field']] = [$e['z'] ?? null, $e['reference'] ?? null];
    check('log: a growth finding carries its z-score and reference (got ' . json_encode($logged) . ')',
        ($logged['weight'] ?? null) === ['-3.40', 'who-wfa'] && ($logged['wt_g'] ?? null) === ['-3.40', 'who-wfa']
        && ($logged['bmi'] ?? null) === ['5.03', 'who2007-bfa']);
    // A log that keeps no value, or only its keyed hash, keeps no z-score: the
    // z-score, sex and age give the measurement back.
    foreach (['none', 'hashed'] as $lm) {
        $m = mod($PL, $pl, $FULL, 'nurse');
        $m->projectSettings['log-values'] = $lm;
        $m->redcap_save_record(149, '2', 'anthro_form', 351, null, null, null, 1);
        $logged = [];
        foreach (findings($m) as $e) if (($e['type'] ?? '') === 'range') $logged[$e['field']] = [$e['z'] ?? null, $e['reference'] ?? null, isset($e['value'])];
        check('log: log-values ' . $lm . ', the reference and no z-score or value (got ' . json_encode($logged) . ')',
            ($logged['weight'] ?? null) === [null, 'who-wfa', false] && ($logged['bmi'] ?? null) === [null, 'who2007-bfa', false]);
    }
    $p = page(mod($PL, $pl, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    $r = ruleFor($p, 'wt_g');
    check('page: plausible, its text in the reference\'s unit, and measureScale travel (got '
        . json_encode([$r['rangePlausibleLo'] ?? null, $r['rangePlausibleHi'] ?? null, $r['rangePlausibleText'] ?? null, $r['rangeMeasureScale'] ?? null]) . ')',
        $r && $r['rangePlausibleLo'] === '2' && $r['rangePlausibleHi'] === '30' && $r['rangePlausibleText'] === '2 to 30 kg'
        && $r['rangeMeasureScale'] === '0.001');
    // with a z-score the z-score decides, whatever "plausible" says
    $pl['2'][351]['weight'] = '1.5';
    list($by) = audit($PL, $pl);
    check('plausible: with a z-score, 1.5 kg is judged by its z-score, not by "plausible" (got ' . json_encode($by['weight'] ?? null) . ')',
        ($by['weight'] ?? null) === 'hard-low');
    // no date of birth: the plausible range judges, and the value is listed as not evaluated
    $nodob = $pl; $nodob['2'][351]['dob'] = ''; $nodob['2'][351]['weight'] = '95'; $nodob['2'][351]['wt_g'] = '95000';
    list($by, $un, $ne, $m) = audit($PL, $nodob);
    check('plausible: no date of birth, 95 kg is above the plausible range (got ' . json_encode([$by, $ne]) . ')',
        ($by['weight'] ?? null) === 'plausible-high' && ($by['wt_g'] ?? null) === 'plausible-high' && !$un
        && ($ne['weight'] ?? null) === 'the date of birth or the date of the measurement is blank');
    $logged = [];
    foreach (findings($m) as $e) if (($e['field'] ?? '') === 'weight') $logged = $e;
    check('log: a plausible finding names its reference and no z-score', ($logged['reference'] ?? null) === 'who-wfa' && !isset($logged['z']));
    $nel = array_values(array_filter(findings($m, 'uvalidate-not-evaluated'), function ($e) { return $e['field'] === 'weight'; }));
    check('log: the value not evaluated: rule, field, reason, reference, record, no value (got ' . json_encode($nel) . ')',
        count($nel) === 1 && $nel[0]['reference'] === 'who-wfa' && $nel[0]['record'] === '2' && $nel[0]['instrument'] === 'anthro_form'
        && ctype_digit($nel[0]['rule']) && !isset($nel[0]['value']));
    $nodob['2'][351]['weight'] = '1.5';
    list($by) = audit($PL, $nodob);
    check('plausible: ... 1.5 kg below it', ($by['weight'] ?? null) === 'plausible-low');
    $nodob['2'][351]['weight'] = '9';
    list($by, $un, $ne) = audit($PL, $nodob);
    check('plausible: ... 9 kg inside it: no finding, still not evaluated', !isset($by['weight']) && isset($ne['weight']));
    $nodob['2'][351]['weight'] = 'abc';
    list($by, $un, $ne) = audit($PL, $nodob);
    check('plausible: ... not a number is still not a number, and not listed as not evaluated',
        ($by['weight'] ?? null) === 'not-a-number' && !isset($ne['weight']));
    $nosex = $pl; $nosex['2'][351]['sex'] = '3'; $nosex['2'][351]['weight'] = '95';
    list($by, $un, $ne) = audit($PL, $nosex);
    check('plausible: a sex code that is neither: the plausible range judges (got ' . json_encode([$by['weight'] ?? null, $ne['weight'] ?? null]) . ')',
        ($by['weight'] ?? null) === 'plausible-high' && ($ne['weight'] ?? null) === 'the sex code is neither 1 (male) nor 2 (female)');
    $old = $pl; $old['2'][351]['dob'] = '2019-01-10'; $old['2'][351]['weight'] = '40';
    list($by, $un, $ne) = audit($PL, $old);
    check('plausible: outside the reference: judged, and the reason is the browser\'s (got ' . json_encode($ne['weight'] ?? null) . ')',
        ($by['weight'] ?? null) === 'plausible-high' && strpos((string) ($ne['weight'] ?? ''), 'the age is outside the reference (0 to under 1826.25 days)') === 0);
    $nodob['2'][351]['weight'] = '95';
    list($by, $un, $ne) = audit($DICT, $nodob);
    check('no "plausible": 95 kg with no date of birth: nothing judged, still listed', !isset($by['weight']) && isset($ne['weight']));
    $off = mod($PL, $nodob, $FULL, 'nurse');
    $off->projectSettings['log-values'] = 'off';
    $off->redcap_save_record(149, '2', 'anthro_form', 351, null, null, null, 1);
    check('log mode off: nothing is logged as not evaluated', !findings($off, 'uvalidate-not-evaluated'));
    $hashed = mod($PL, $nodob, $FULL, 'nurse');
    $hashed->projectSettings['log-values'] = 'none';
    $hashed->redcap_save_record(149, '2', 'anthro_form', 351, null, null, null, 1);
    $hn = findings($hashed, 'uvalidate-not-evaluated');
    check('log mode none: the record is not named', $hn && !isset($hn[0]['record']) && (isset($hn[0]['record_hmac']) || isset($hn[0]['hmac_unavailable'])));
    // the scan: the same verdicts, and the plausible range in the detail
    $m = mod($PL, $nodob, $FULL, 'nurse');
    $res = $m->scanProject(149);
    $dims = $m->scanDimensions(149, $res['rules'] ?? null);
    $cols = \INSPIRE\UniversalValidator\ScanColumns::all($dims);
    $wt = array_values(array_filter($res['violations'], function ($v) { return $v['field'] === 'wt_g'; }));
    $row = $wt ? \INSPIRE\UniversalValidator\ScanColumns::row($wt[0], $dims, $cols) : [];
    check('scan: a plausible finding, its label and detail (got ' . json_encode([$row['issue'] ?? null, $row['problem'] ?? null]) . ')',
        ($wt[0]['reason'] ?? null) === 'plausible-high' && ($wt[0]['reference'] ?? null) === 'who-wfa' && !isset($wt[0]['z'])
        && ($row['issue'] ?? '') === 'Implausible value'
        && ($row['problem'] ?? '') === 'The value is above the plausible range of its growth rule, which judged it because its z-score '
            . 'could not be worked out. Allowed 2 to 30 kg.');
    // the dictionary: "plausible" needs no reach check, and keeps the reference's unit
    $DP = $DICT + ['muac_p' => f('anthro_form', tag('who-acfa', $AGE, '"hard":[-5,5],"plausible":[5,30]'), 'number_1dp')];
    $p = page(mod($DP, $DATA, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    check('page: the plausible text names the reference\'s unit (cm for arm circumference)',
        (ruleFor($p, 'muac_p')['rangePlausibleText'] ?? null) === '5 to 30 cm');

    // ---- 5f) how the child was measured: WHO's 0.7 cm correction (RNG-3) -----------------------
    // "measured" names the field that says whether the child was measured lying
    // or standing. who-lhfa corrects the measurement (0.7 cm more for a length
    // measured standing under 731 days, 0.7 cm less for a height measured lying
    // from 731 days); who-wfl and who-wfh correct the length or height in "by".
    $MEAS = '"measured":{"field":"[measured]","lying":"1","standing":"2"}';
    $ok = AnnotationRules::parseAllTags(tag('who-lhfa', '"age":{"days":"[age_days]"},' . $MEAS));
    check('measured: parses to the field and its two codes', isset($ok[0]) && !isset($ok[0]['error'])
        && $ok[0]['rangeMeasured'] === '[measured]' && $ok[0]['rangeLying'] === '1' && $ok[0]['rangeStanding'] === '2');
    $ok = AnnotationRules::parseAllTags(tag('who-lhfa', '"age":{"days":"[age_days]"},"measured":{"field":"[m]","lying":1,"standing":2}'));
    check('measured: numeric codes are kept as text', !isset($ok[0]['error']) && $ok[0]['rangeLying'] === '1' && $ok[0]['rangeStanding'] === '2');
    $AD = '"age":{"days":"[age_days]"}';
    $mRefused = [
        'measured without a reference' => ['@UVRANGE={"hard":[1,2],' . $MEAS . '}', '"sex", "male", "female", "age", "by" and "measured" only apply with a "reference"'],
        'measured as a field name'     => [tag('who-lhfa', $AD . ',"measured":"[measured]"'), '"measured" must be {"field":"[measured]","lying":"1","standing":"2"}'],
        'measured without standing'    => [tag('who-lhfa', $AD . ',"measured":{"field":"[m]","lying":"1"}'), '"measured" must be {"field"'],
        'measured with another key'    => [tag('who-lhfa', $AD . ',"measured":{"field":"[m]","lying":"1","standing":"2","sitting":"3"}'), '"measured" must be {"field"'],
        'measured field not a string'  => [tag('who-lhfa', $AD . ',"measured":{"field":5,"lying":"1","standing":"2"}'), '"measured" "field" must be one field reference'],
        'measured field a condition'   => [tag('who-lhfa', $AD . ',"measured":{"field":"[m]=1","lying":"1","standing":"2"}'), '"measured" "field" must be exactly one field reference'],
        'measured field a checkbox code' => [tag('who-lhfa', $AD . ',"measured":{"field":"[m(1)]","lying":"1","standing":"2"}'), '"measured" "field" must be exactly one field reference'],
        'lying blank'                  => [tag('who-lhfa', $AD . ',"measured":{"field":"[m]","lying":" ","standing":"2"}'), '"measured" "lying" must be the code the field stores for a child measured lying'],
        'standing a list'              => [tag('who-lhfa', $AD . ',"measured":{"field":"[m]","lying":"1","standing":["2"]}'), '"measured" "standing" must be the code'],
        'the same code twice'          => [tag('who-lhfa', $AD . ',"measured":{"field":"[m]","lying":"1","standing":"1"}'), '"measured" "lying" and "standing" are the same code ("1").'],
        'codes differing by case'      => [tag('who-lhfa', $AD . ',"measured":{"field":"[m]","lying":"L","standing":"l"}'), '"measured" "lying" "L" and "standing" "l" differ only in letter case'],
    ];
    foreach ($mRefused as $label => $pair) {
        $e = errOf($pair[0]);
        check('measured refused: ' . $label . ' (got ' . json_encode($e) . ')', $e !== '' && strpos($e, $pair[1]) !== false);
    }
    check('measured: codes differing by case are fine with caseSensitive',
        errOf(tag('who-lhfa', $AD . ',"measured":{"field":"[m]","lying":"L","standing":"l"},"caseSensitive":true')) === '');
    $mHalf = AnnotationRules::checkRange(['type' => 'range', 'fields' => ['w'], 'rangeReference' => 'who-lhfa',
        'rangeSex' => '[sex]', 'rangeMale' => '1', 'rangeFemale' => '2', 'rangeAgeDays' => '[a]', 'rangeMeasured' => '[m]',
        'rangeLying' => '1', 'rangeHardLo' => '-5', 'rangeHardHi' => '5']);
    check('checkRange: "measured" without its "standing" code (got ' . json_encode($mHalf) . ')',
        in_array('"measured" needs "field", "lying" and "standing" together.', $mHalf, true));

    $Z36 = '"soft":[-3,3],"hard":[-6,6]';
    $MDICT = $DICT + [
        'measured' => f('anthro_form', '', '', 'radio', '1, Lying | 2, Standing | 9, Not known'),
        'len'      => f('anthro_form', tag('who-lhfa', $AD . ',' . $MEAS, $Z36), 'number_1dp'),
        'len_nom'  => f('anthro_form', tag('who-lhfa', $AD, $Z36), 'number_1dp'),
        'wt_l'     => f('anthro_form', tag('who-wfl', '"by":"[len]",' . $MEAS, '"soft":[-3,3],"hard":[-5,5]'), 'number_1dp'),
        'b_mwfa'   => f('anthro_form', tag('who-wfa', $AD . ',' . $MEAS), 'number'),
        'b_mbfa'   => f('anthro_form', tag('who-bfa', $AD . ',' . $MEAS), 'number'),
        'b_mcode'  => f('anthro_form', tag('who-lhfa', $AD . ',"measured":{"field":"[measured]","lying":"L","standing":"2"}'), 'number'),
        'b_mchk'   => f('anthro_form', tag('who-lhfa', $AD . ',"measured":{"field":"[chk]","lying":"1","standing":"2"}'), 'number'),
        'b_mnone'  => f('anthro_form', tag('who-lhfa', $AD . ',"measured":{"field":"[nope]","lying":"1","standing":"2"}'), 'number'),
        'b_mtext'  => f('anthro_form', tag('who-lhfa', $AD . ',"measured":{"field":"[how_txt]","lying":"L","standing":"S"}'), 'number'),
        'b_mret'   => f('anthro_form', tag('who-lhfa', $AD . ',"measured":{"field":"[how_txt]","lying":"L","standing":"S"},"return":{"z":"[how_txt]"}'), 'number'),
        'chk'      => f('enrol_form', '', '', 'checkbox', '1, M | 2, F'),
        'how_txt'  => f('anthro_form'),
    ];
    $p = page(mod($MDICT, $DATA, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    $mCases = [
        'b_mwfa'  => '"who-wfa" has no correction for how the child was measured, so "measured" does not apply. Where a value needs one, such as a BMI, correct the length or height in the calc that works the value out.',
        'b_mbfa'  => '"who-bfa" has no correction for how the child was measured, so "measured" does not apply.',
        'b_mcode' => '"measured" "lying" is "L", which is not a choice of "measured" field "measured" (its codes are 1, 2, 9).',
        'b_mchk'  => '"measured" field "chk" is a checkbox field — it must hold one code',
        'b_mnone' => '"measured" "field" names "[nope]", which is not a field in this project.',
        'b_mret'  => '"return" writes "z" to "[how_txt]", which this rule reads — give the value a field of its own.',
    ];
    foreach ($mCases as $f => $want) {
        check('measured dictionary: ' . $f . ' (got ' . json_encode($err($p, $f)) . ')', strpos($err($p, $f), $want) !== false
            && strpos($err($p, $f), '@UVRANGE on "' . $f . '"') === 0);
    }
    foreach (['len', 'len_nom', 'wt_l', 'b_mtext'] as $f) {
        check('measured dictionary: ' . $f . ' is taken (got ' . json_encode($err($p, $f)) . ')', ruleFor($p, $f) !== null && $err($p, $f) === '');
    }
    $r = ruleFor($p, 'len');
    check('measured page: the field travels as a live input with its two codes (got ' . json_encode([$r['rangeMeasuredOp'] ?? null,
        $r['rangeLying'] ?? null, $r['rangeStanding'] ?? null]) . ')', $r && ($r['rangeMeasuredOp'] ?? null) === ['ref', 'measured', null]
        && $r['rangeLying'] === '1' && $r['rangeStanding'] === '2');
    check('measured page: the page copy of who-lhfa carries its correction',
        ($p['cfg']['growth']['who-lhfa']['position'] ?? null) === GrowthReference::entry('who-lhfa')['position']);
    // A length and a position saved on another form: the page gets the rows at
    // the corrected length (75.0 cm standing is 75.7 on weight-for-length).
    $SDICT = ['record_id' => f('enrol_form'), 'sex' => f('enrol_form', '', '', 'radio', $SEXES),
              'how_s' => f('enrol_form', '', '', 'radio', '1, Lying | 2, Standing'),
              'len_s' => f('enrol_form', '', 'number_1dp'),
              'wt_s'  => f('anthro_form', tag('who-wfl', '"by":"[len_s]","measured":{"field":"[how_s]","lying":"1","standing":"2"}', '"hard":[-5,5]'), 'number_1dp')];
    $firstOf = function ($how, $dict = null) use ($SDICT, $FULL) {
        $d = ['2' => [351 => ['record_id' => '2', 'sex' => '1', 'how_s' => $how, 'len_s' => '75.0', 'wt_s' => '']]];
        $pp = page(mod($dict ?: $SDICT, $d, $FULL, 'nurse'), 'form', '2', 'anthro_form');
        return [$pp['cfg']['growth']['who-wfl']['first'] ?? null, count($pp['cfg']['growth']['who-wfl']['male'] ?? [])];
    };
    check('measured page: standing saved, the rows at 75.7 cm travel (got ' . json_encode($firstOf('2')) . ')', $firstOf('2') === [756, 3]);
    check('measured page: lying saved, the rows at 75.0 cm (got ' . json_encode($firstOf('1')) . ')', $firstOf('1') === [749, 3]);
    $live = $SDICT;
    $live['how_s'] = f('anthro_form', '', '', 'radio', '1, Lying | 2, Standing');
    check('measured page: a position typed on this page sends every row (got ' . json_encode($firstOf('2', $live)) . ')',
        $firstOf('2', $live)[0] === 450 && $firstOf('2', $live)[1] > 600);

    // The audit: the review's girl (600 days, 73.0 cm standing) is moderately,
    // not severely, stunted once corrected (z -2.91, WHO Anthro's); the same
    // length without "measured" scores -3.14.
    $zsOf = function ($m) { $o = []; foreach (findings($m) as $e) if (($e['type'] ?? '') === 'range') $o[$e['field']] = [$e['reason'], $e['z'] ?? null]; return $o; };
    $mrow = ['record_id' => '2', 'sex' => '2', 'age_days' => '600', 'measured' => '2', 'len' => '73.0', 'len_nom' => '73.0'];
    list($by, $un, $ne, $m) = audit($MDICT, ['2' => [351 => $mrow]]);
    $zs = $zsOf($m);
    check('measured audit: standing at 600 days, 73.0 cm passes (z -2.91); without "measured" it is low (z -3.14) (got ' . json_encode($zs) . ')',
        !isset($zs['len']) && ($zs['len_nom'] ?? null) === ['soft-low', '-3.14']);
    list($by, $un, $ne, $m) = audit($MDICT, ['2' => [351 => ['measured' => '1'] + $mrow]]);
    check('measured audit: lying under 731 days is not corrected (got ' . json_encode($zsOf($m)) . ')', ($zsOf($m)['len'] ?? null) === ['soft-low', '-3.14']);
    list($by, $un, $ne, $m) = audit($MDICT, ['2' => [351 => ['measured' => ''] + $mrow]]);
    check('measured audit: a blank position is not corrected, and is no problem (got ' . json_encode([$zsOf($m), $un]) . ')',
        ($zsOf($m)['len'] ?? null) === ['soft-low', '-3.14'] && !$un);
    list($by, $un, $ne, $m) = audit($MDICT, ['2' => [351 => ['measured' => '9'] + $mrow]]);
    check('measured audit: "Not known" is not corrected', ($zsOf($m)['len'] ?? null) === ['soft-low', '-3.14']);
    $boy = ['record_id' => '2', 'sex' => '1', 'age_days' => '1000', 'measured' => '1', 'len' => '83.3'];
    list($by, $un, $ne, $m) = audit($MDICT, ['2' => [351 => $boy]]);
    check('measured audit: a boy of 1000 days measured lying at 83.3 cm is scored at 82.6 (z -3.20) (got ' . json_encode($zsOf($m)) . ')',
        ($zsOf($m)['len'] ?? null) === ['soft-low', '-3.20']);
    // weight-for-length: the guide's boy, 75.0 cm standing and 7.5 kg, -3.16 corrected, -2.98 lying
    $wl = ['record_id' => '2', 'sex' => '1', 'age_days' => '300', 'measured' => '2', 'len' => '75.0', 'wt_l' => '7.5'];
    list($by, $un, $ne, $m) = audit($MDICT, ['2' => [351 => $wl]]);
    check('measured audit: weight-for-length by the corrected length (got ' . json_encode($zsOf($m)) . ')', ($zsOf($m)['wt_l'] ?? null) === ['soft-low', '-3.16']);
    list($by, $un, $ne, $m) = audit($MDICT, ['2' => [351 => ['measured' => '1'] + $wl]]);
    check('measured audit: measured lying, the length is the length (got ' . json_encode($zsOf($m)) . ')', !isset($zsOf($m)['wt_l']) && !$un);
    // past the table once corrected: 109.5 cm standing is 110.2, outside 45 to 110 cm
    list($by, $un, $ne, $m) = audit($MDICT, ['2' => [351 => ['len' => '109.5', 'wt_l' => '18'] + $wl]]);
    check('measured audit: a corrected length past the table is not evaluated (got ' . json_encode($ne) . ')',
        strpos((string) ($ne['wt_l'] ?? ''), 'the length is outside the reference (45 to 110 cm)') !== false);

    // ---- 5c) the docs: reference ids and the worked example --------------------------------------
    // Every reference id a document names is one the module ships or one of the
    // made-up examples in data/references/examples (the guide's Level 7, read as
    // a folder of extra references); "who-nope" is the refused example's unknown
    // id, and "cdc-wfa" the guide's reference of your own. (JSON in config.json
    // escapes its quotes, the testbed CSV doubles them.)
    $named = [];
    foreach (['docs/action_tag_validation_examples.md', 'README.md', 'docs/USER_GUIDE.md', 'site/content/uvrange.html',
              'config.json', 'docs/testbed/uvrange_growth_test_fields.csv', 'docs/testbed/RANGE_LIVE_ACCEPTANCE.md'] as $doc) {
        $text = str_replace(['\\"', '""'], '"', (string) file_get_contents(__DIR__ . '/../' . $doc));
        preg_match_all('/"reference"\s*:\s*"([^"]*)"/', $text, $mm);
        foreach ($mm[1] as $id) $named[$id][] = $doc;
    }
    $unknown = array_diff(array_keys($named), array_keys(GrowthReference::catalog(__DIR__ . '/../data/references/examples')['references']),
        ['who-nope', 'cdc-wfa']);
    check('docs: every reference id named is shipped (unknown: ' . json_encode(array_values($unknown)) . ')',
        count($named) >= 5 && !$unknown);
    // The Level 5 example: 6.6 kg for a boy of exactly one year.
    $e = GrowthReference::entry('who-wfa');
    $z = GrowthReference::zScore($e, GrowthReference::table($e), 'male', 365, 6.6);
    $doc = (string) file_get_contents(__DIR__ . '/../docs/action_tag_validation_examples.md');
    check('docs: the worked example\'s z-score and note (got ' . json_encode($z) . ')', ($z['z'] ?? null) === '-3.40'
        && strpos($doc, 'This value is lower than usual (z-score -3.40 on ' . $e['title'] . ', in ' . $e['unit'] . '; expected z-score -3 to 3).') !== false);

    // ---- 6) a folder of extra references ------------------------------------------------------
    $dir = tmpdir();
    $rows = array_fill(0, 1827, [1, 6.6, 0.1]);
    $table = json_encode(['format' => 'uv-lms-1', 'scale' => 1, 'first' => 0, 'male' => $rows, 'female' => $rows]);
    file_put_contents($dir . '/flat.json', $table);
    $index = function ($sha, $extra = []) {
        return json_encode(['format' => 'uv-references-1', 'references' => array_merge(['who-wfa' => [
            'title' => 'A flat test table', 'file' => 'flat.json', 'sha256' => $sha, 'measure' => 'weight', 'unit' => 'kg',
            'axis' => 'age', 'axisUnit' => 'days', 'lookup' => 'round', 'valid' => ['min' => '0', 'below' => '1826.25'],
            'adjust' => 'none']], $extra)]);
    };
    file_put_contents($dir . '/index.json', $index(hash('sha256', $table)));
    $SYS = ['reference-data-dir' => $dir];
    list($by, $un) = audit($DICT, $DATA, 'anthro_form', $SYS);
    check('extra folder: a reference with a shipped id replaces it (6.6 kg is the median there)', !isset($by['weight']) && !$un
        && ($by['wt_h'] ?? null) === 'soft-high');
    $p = page(mod($DICT, $DATA, $FULL, 'nurse', $SYS), 'form', '2', 'anthro_form');
    check('extra folder: the page carries its table', ($p['cfg']['growth']['who-wfa']['title'] ?? '') === 'A flat test table');
    file_put_contents($dir . '/index.json', $index(hash('sha256', $table), ['my-wfa' => [
        'title' => 'Mine', 'file' => 'flat.json', 'sha256' => hash('sha256', $table), 'measure' => 'weight', 'unit' => 'kg',
        'axis' => 'age', 'axisUnit' => 'days', 'lookup' => 'round', 'valid' => ['min' => '0', 'below' => '100'], 'adjust' => 'none']]));
    $mine = $DICT; $mine['weight'] = f('anthro_form', tag('my-wfa', '"age":{"days":"[age_days]"}'), 'number_1dp');
    $p = page(mod($mine, $DATA, $FULL, 'nurse', $SYS), 'form', '2', 'anthro_form');
    check('extra folder: a new id is usable', $err($p, 'weight') === '');
    $p = page(mod($mine, $DATA, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    check('without the folder that id is unknown', strpos($err($p, 'weight'), '"reference" "my-wfa" is not a growth reference this server has') !== false);
    file_put_contents($dir . '/index.json', $index(str_repeat('0', 64)));
    $p = page(mod($DICT, $DATA, $FULL, 'nurse', $SYS), 'form', '2', 'anthro_form');
    check('extra folder: a table that does not match its sha256 is refused (got ' . json_encode($err($p, 'weight')) . ')',
        strpos($err($p, 'weight'), '"reference" "who-wfa" cannot be used: the table flat.json does not match the "sha256" in index.json') !== false);
    list($by, $un) = audit($DICT, $DATA, 'anthro_form', $SYS);
    check('extra folder: a rule whose table fails is not audited as data (its configuration error stands)', !isset($by['weight']));
    file_put_contents($dir . '/index.json', '{"format":"nope"}');
    $p = page(mod($mine, $DATA, $FULL, 'nurse', $SYS), 'form', '2', 'anthro_form');
    check('extra folder: an unreadable index is named in the refusal (got ' . json_encode($err($p, 'weight')) . ')',
        strpos($err($p, 'weight'), 'cannot be used: the reference folder in the module settings: index.json must say "format": "uv-references-1".') !== false);
    // ... and it refuses a bundled id too: the folder may have meant to replace it
    $p = page(mod($DICT, $DATA, $FULL, 'nurse', $SYS), 'form', '2', 'anthro_form');
    check('extra folder: unreadable, a bundled reference is refused as well (got ' . json_encode($err($p, 'weight')) . ')',
        strpos($err($p, 'weight'), '"reference" "who-wfa" cannot be used: the reference folder in the module settings: index.json must say') !== false
        && !isset($p['cfg']['growth']));
    list($by, $un) = audit($DICT, $DATA, 'anthro_form', $SYS);
    check('extra folder: unreadable, the audit does not judge against the bundled table', !$by);
    file_put_contents($dir . '/index.json', json_encode(['format' => 'uv-references-1', 'pageTables' => 1, 'references' => new \stdClass()]));
    $p = page(mod($DICT, $DATA, $FULL, 'nurse', $SYS), 'form', '2', 'anthro_form');
    $kept = array_filter($p['cfg']['rules'], function ($x) { return !empty($x['rangeReference']) && empty($x['deferred']); });
    // the sex is saved, so half tables: two fit, and wt_c reads the who-wfh already sent
    check('extra folder: "pageTables" sets the cap (1 table: two half tables, three rules kept, the others deferred)',
        count($p['cfg']['growth'] ?? []) === 2 && count($kept) === 3);
    rmtree($dir);

    fwrite(STDOUT, "growth_module_php: $n checks, $fail failure(s)\n");
    exit($fail ? 1 : 0);
}
