<?php
/**
 * condition_kinds_php.php — what an "assert" or "when" may compare (AST-4 of
 * the 2026-10-21 review).
 *
 * Conditions compare values as REDCap stores them: dates as Y-M-D, times as
 * HH:MM, numbers with a decimal point. A literal written the way a D-M-Y form
 * shows dates compared as text, so [dob]>='01-01-2000' passed every date
 * before 2000 on the page, in the audit and in the scan, and nothing at
 * configuration said so. The @UVEXISTS "where" already had this check; every
 * "assert" and "when" now gets it, in the annotations, in the project
 * settings and in the Configure dialog's save gate:
 *   1) a date, datetime, time or minutes:seconds literal not written the
 *      stored way is refused;
 *   2) a literal with a decimal comma compared with a number field is refused;
 *   3) two fields that hold different kinds (a date and a datetime, a date and
 *      plain text) are refused;
 *   4) two number fields with different decimal marks are fine here, because
 *      a condition reads each by its own mark (AST-1), unlike "where".
 *
 * Run:  php tests/condition_kinds_php.php
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
        public static function getDataDictionary($pid, $f = 'array') { return self::$dictionary; }
        public static function getUserRights($pid = null, $user = null) { return self::$rights; }
        public static function getGroupNames($u = false, $g = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
    }
    class Project {
        public $project = ['missing_data_codes' => ''];
        public $forms = ['visit' => []];
        public $surveys = [];
        public function __construct($pid) {}
    }

    require_once __DIR__ . '/../UniversalValidator.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    function f($ann = '', $validation = '', $type = 'text', $choices = '') {
        return ['field_type' => $type, 'form_name' => 'visit', 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => $validation,
                'select_choices_or_calculations' => $choices, 'section_header' => ''];
    }
    function a($assert, $extra = '') {
        return '@UVASSERT={"assert":"' . str_replace('"', '\\"', $assert) . '"' . $extra . '}';
    }
    // The fields compared, and one tagged field per case: name => [annotation, words the refusal holds or null]
    $BASE = [
        'record_id' => f(),
        'dob'       => f('', 'date_dmy'),
        'enrol'     => f('', 'date_ymd'),
        'seen_at'   => f('', 'datetime_dmy'),
        'dose_time' => f('', 'time'),
        'lap'       => f('', 'time_mm_ss'),
        'hb'        => f('', 'number_1dp_comma_decimal'),
        'wt'        => f('', 'number'),
        'note'      => f(),
        'sex'       => f('', '', 'radio', '1, Male | 2, Female'),
        'cb'        => f('', '', 'checkbox', '1, A | 2, B'),
    ];
    $CASES = [
        // refused
        'k_dmy'      => [a("[dob]>='01-01-2000'"), '"assert" compares "[dob]", which holds dates, with \'01-01-2000\''],
        'k_dt'       => [a("[seen_at]>='2026-03-01 8:30'"), 'write it as Y-M-D'],
        'k_time'     => [a("[dose_time]<'8h30'"), 'write the time as REDCap stores it'],
        'k_lap'      => [a("[lap]<'2:30'"), 'such as \'02:30\''],
        'k_comma'    => [a("[hb]>='12,5'"), 'write numbers in "assert" with a decimal point, such as \'12.5\''],
        'k_fields'   => [a("[enrol]<=[seen_at]"), 'with "[seen_at]", which holds dates with a time to the minute'],
        'k_text'     => [a("[dob]<[note]"), 'so both must be the same kind'],
        'k_when'     => [a("[wt]>0", ',"when":"[dob]<\'31/12/1999\'"'), '"when" compares "[dob]"'],
        'k_nested'   => [a("[sex]='1' and ([wt]>2 or not [enrol]>='2026/01/01')"), '"assert" compares "[enrol]"'],
        'k_required' => ['@UVREQUIRED={"when":"[enrol]>=\'1.1.2026\'"}', '"when" compares "[enrol]"'],
        // accepted
        'k_ok_date'  => [a("[dob]>='2000-01-01'"), null],
        'k_ok_dt'    => [a("[seen_at]>='2026-03-01 08:30'"), null],
        'k_ok_dates' => [a("[dob]<=[enrol]"), null],
        'k_ok_marks' => [a("[hb]<=[wt]"), null],
        'k_ok_point' => [a("[hb]>=12.5"), null],
        'k_ok_text'  => [a("[note]<>'01-01-2000'"), null],
        'k_ok_radio' => [a("[sex]='1'"), null],
        'k_ok_cb'    => [a("[cb(1)]='1'"), null],
        'k_ok_blank' => [a("[dob]<>''"), null],
        'k_ok_time'  => [a("[dose_time]>='08:30'"), null],
    ];
    $DICT = $BASE;
    foreach ($CASES as $fname => $c) $DICT[$fname] = f($c[0]);
    foreach ($DICT as $k => $_) $DICT[$k]['field_name'] = $k;
    $RIGHTS = ['nurse' => ['forms' => ['visit' => '1']]];

    function mod($dict, $sub = []) {
        $GLOBALS['__TEST_USER'] = 'nurse';
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectSettings = ['log-values' => ''];
        $m->projectIdReturn = 149;
        $m->subSettings = $sub;
        \REDCap::$dictionary = $dict;
        \REDCap::$data = ['1' => [351 => ['record_id' => '1']]];
        \REDCap::$rights = $GLOBALS['RIGHTS'];
        return $m;
    }
    function pageRules($m) {
        ob_start();
        $m->redcap_data_entry_form_top(149, '1', 'visit', 351, null, 1);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        $cfg = json_decode(isset($mm[1]) ? $mm[1] : 'null', true);
        $out = [];
        foreach ((isset($cfg['rules']) ? $cfg['rules'] : []) as $r) {
            foreach ((isset($r['fields']) ? $r['fields'] : []) as $f) $out[$f] = $r;
        }
        return $out;
    }

    // ---- 1) the annotation channel --------------------------------------------------
    $rules = pageRules(mod($DICT));
    foreach ($CASES as $fname => $c) {
        $err = isset($rules[$fname]['configError']) ? (string) $rules[$fname]['configError'] : '';
        if ($c[1] === null) {
            check("annotation $fname: accepted (got " . json_encode($err) . ')', isset($rules[$fname]) && $err === '');
        } else {
            check("annotation $fname: refused with " . json_encode($c[1]) . ' (got ' . json_encode($err) . ')',
                strpos($err, $c[1]) !== false);
        }
    }
    check('annotation: the refusal names the tag', strpos((string) ($rules['k_dmy']['configError'] ?? ''), '@UVASSERT') !== false);

    // ---- 2) the project settings (rules stored by the Configure dialog) -------------
    $row = function ($type, $field, $assert, $when = '') {
        return ['rule-type' => $type, 'fields' => [$field], 'fields-csv' => '', 'when' => $when, 'assert' => $assert,
                'message' => '', 'algorithm' => 'iso7064_mod37_36', 'block-save' => 'off'];
    };
    $sub = [$row('constraint', 'note', "[dob]>='01-01-2000'"), $row('constraint', 'record_id', "[dob]>='2000-01-01'"),
            $row('single', 'wt', '', "[hb]>'12,5'"), $row('required', 'lap', '', "[enrol]<[seen_at]")];
    $rules = pageRules(mod($BASE + ['k_none' => f()], $sub));
    check('settings: a constraint over a D-M-Y literal is refused',
        strpos((string) ($rules['note']['configError'] ?? ''), "with '01-01-2000'") !== false);
    check('settings: the same constraint written Y-M-D is accepted', isset($rules['record_id']) && empty($rules['record_id']['configError']));
    check('settings: a check rule\'s "when" with a comma literal is refused',
        strpos((string) ($rules['wt']['configError'] ?? ''), 'write numbers in "when" with a decimal point') !== false);
    check('settings: a required rule\'s "when" over a date and a datetime is refused',
        strpos((string) ($rules['lap']['configError'] ?? ''), 'so both must be the same kind') !== false);

    // ---- 3) the Configure dialog's save gate ----------------------------------------
    $flat = function ($assert) {
        return ['rules' => [true], 'rule-type' => ['constraint'], 'fields' => [['note']], 'fields-csv' => [''],
                'when' => [''], 'assert' => [$assert], 'message' => [''], 'block-save' => ['off']];
    };
    $m = mod($BASE);
    $msg = $m->validateSettings($flat("[dob]>='01-01-2000'"));
    check('dialog: saving a D-M-Y literal is refused (got ' . json_encode($msg) . ')',
        is_string($msg) && strpos($msg, 'Rule 1') !== false && strpos($msg, 'write it as Y-M-D') !== false);
    check('dialog: the Y-M-D literal saves', $m->validateSettings($flat("[dob]>='2000-01-01'")) === null);

    // ---- 4) "where" keeps asking for the same decimal mark --------------------------
    $rules = pageRules(mod($BASE + ['spec' => f('@UVEXISTS={"in":"[note]","where":"[hb]=[wt]"}')]));
    check('"where" still refuses two number fields with different decimal marks',
        strpos((string) ($rules['spec']['configError'] ?? ''), 'decimal') !== false);

    echo "condition_kinds_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
