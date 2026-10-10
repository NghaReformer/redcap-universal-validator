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
        return [$by, $un];
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

    // ---- 2) the dictionary hook --------------------------------------------------------
    $p = page(mod($DICT, $DATA, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    $err = function ($p, $field) { $r = ruleFor($p, $field); return $r && isset($r['configError']) ? $r['configError'] : ''; };
    foreach (['weight', 'wt_h', 'hc', 'bmi', 'wt_c'] as $f) check($f . ': no configuration error (got ' . json_encode($err($p, $f)) . ')', ruleFor($p, $f) !== null && $err($p, $f) === '');
    $bad = $DICT + [
        'b_unknown' => f('anthro_form', tag('who-nope', $AGE), 'number'),
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
        'chk'       => f('enrol_form', '', '', 'checkbox', '1, M | 2, F'),
        'yn'        => f('enrol_form', '', '', 'yesno'),
        'sex_txt'   => f('enrol_form'),
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
    ];
    foreach ($cases as $f => $want) {
        check('dictionary: ' . $f . ' (got ' . json_encode($err($p, $f)) . ')', strpos($err($p, $f), $want) !== false
            && strpos($err($p, $f), '@UVRANGE on "' . $f . '"') === 0);
    }
    check('dictionary: a Text sex field takes any codes', $err($p, 'b_textsex') === '');

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
    check('page: a table copy carries the index fields and the rows', ($wfa['title'] ?? '') === 'Weight-for-age, WHO 2006 (birth to 5 years)'
        && $wfa['axis'] === 'age' && $wfa['axisUnit'] === 'days' && $wfa['lookup'] === 'round' && $wfa['adjust'] === 'who-restricted'
        && $wfa['valid'] === ['min' => '0', 'below' => '1826.25'] && $wfa['scale'] === 1 && $wfa['first'] === 0
        && count($wfa['male']) === 1827 && count($wfa['female']) === 1827 && !isset($wfa['sha256']) && !isset($wfa['file']));
    $deferred = array_filter($p['cfg']['rules'], function ($x) { return !empty($x['rangeReference']) && !empty($x['deferred']); });
    check('page: four tables fit, nothing deferred', !$deferred);
    $five = $DICT + ['muac' => f('anthro_form', tag('who-acfa', '"age":{"days":"[age_days]"}'), 'number_1dp')];
    $p5 = page(mod($five, $DATA, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    $r5 = ruleFor($p5, 'muac');
    check('page: a fifth table is one too many, its rule deferred with the reason (got ' . json_encode($r5['deferredWhy'] ?? null) . ')',
        $r5 && !empty($r5['deferred']) && count($p5['cfg']['growth']) === 4 && !isset($p5['cfg']['growth']['who-acfa'])
        && strpos(implode(' ', $r5['deferredWhy'] ?? []), 'already carries 4 growth reference tables, the most one page may carry (4)') !== false);
    check('page: the cap deferral is marked as checked on save', $r5 && ($r5['deferredOnSave'] ?? null) === true);
    $wt = ruleFor($p5, 'weight');
    check('page: a rule that fits is not marked', $wt && !isset($wt['deferredOnSave']));
    $none = ['record_id' => f('enrol_form'), 'x' => f('anthro_form', '@UVRANGE={"hard":[0,10]}', 'number')];
    $p0 = page(mod($none, $DATA, $FULL, 'nurse'), 'form', '2', 'anthro_form');
    check('page: no growth rule, no tables', !isset($p0['cfg']['growth']));
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
    $baddate = $DATA; $baddate['2'][351]['dob'] = '10-01-2025';
    list($by, $un) = audit($DICT, $baddate);
    check('audit: an unreadable date of birth is a rule problem (got ' . json_encode($un) . ')', !isset($by['weight'])
        && count(array_filter($un, function ($u) { return strpos($u, 'weight') === 0 && strpos($u, 'the date of birth [dob] is not a date this rule can read') !== false; })) === 1);
    $baddate['2'][351]['weight'] = '';
    list($by, $un) = audit($DICT, $baddate);
    check('audit: ... but not for a blank value', !$un);
    // Saving the form that holds the inputs re-checks the measurements.
    list($by) = audit($DICT, $DATA, 'enrol_form');
    check('audit: a save of enrol_form re-checks the rules whose inputs it holds (got ' . json_encode(array_keys($by)) . ')',
        isset($by['weight']));

    // ---- 5) the scan ------------------------------------------------------------------------
    $m = mod($DICT, $DATA, $FULL, 'nurse');
    $res = $m->scanProject(149);
    $by = []; foreach ($res['violations'] as $v) $by[$v['field']] = $v['reason'];
    ksort($by);
    check('scan: the same verdicts (got ' . json_encode($by) . ')', $by === ['bmi' => 'hard-high', 'hc' => 'hard-low',
        'weight' => 'soft-low', 'wt_c' => 'hard-low', 'wt_h' => 'soft-high']);
    $dims = $m->scanDimensions(149, $res['rules'] ?? null);
    $cols = \INSPIRE\UniversalValidator\ScanColumns::all($dims);
    $wt = array_values(array_filter($res['violations'], function ($v) { return $v['field'] === 'weight'; }));
    $row = \INSPIRE\UniversalValidator\ScanColumns::row($wt[0], $dims, $cols);
    check('scan: the detail names the z-score limits (got ' . json_encode($row['problem'] ?? null) . ')',
        ($row['problem'] ?? '') === 'The value is lower than usual for this field. Expected z-score -2 to 2.');
    $m = mod($DICT, $weird, $FULL, 'nurse');
    $res = $m->scanProject(149);
    $np = array_values(array_filter($res['violations'], function ($v) { return $v['field'] === 'wt_h'; }));
    check('scan: not-positive found and labelled', count($np) === 1 && $np[0]['reason'] === 'not-positive'
        && ModeRegistry::issueLabel('range', 'not-positive') === 'Not a positive measurement');
    $dims = $m->scanDimensions(149, $res['rules'] ?? null);
    check('scan: not-positive has its catalog wording',
        \INSPIRE\UniversalValidator\MessageCatalog::explain(['type' => 'range', 'reason' => 'not-positive', 'rule' => 1], $dims->rule(1))
        === ['text' => 'The measurement is 0 or below.', 'source' => 'catalog']);

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
        strpos($err($p, 'weight'), 'Problems reading the references: the reference folder in the module settings: index.json must say "format": "uv-references-1".') !== false);
    file_put_contents($dir . '/index.json', json_encode(['format' => 'uv-references-1', 'pageTables' => 1, 'references' => new \stdClass()]));
    $p = page(mod($DICT, $DATA, $FULL, 'nurse', $SYS), 'form', '2', 'anthro_form');
    $kept = array_filter($p['cfg']['rules'], function ($x) { return !empty($x['rangeReference']) && empty($x['deferred']); });
    check('extra folder: "pageTables" sets the cap (1 table, so 1 kept and the rules of the others deferred)',
        count($p['cfg']['growth'] ?? []) === 1 && count($kept) === 1);
    rmtree($dir);

    fwrite(STDOUT, "growth_module_php: $n checks, $fail failure(s)\n");
    exit($fail ? 1 : 0);
}
