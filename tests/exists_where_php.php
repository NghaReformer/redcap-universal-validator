<?php
/**
 * exists_where_php.php — @UVEXISTS "where" on the server, in one project.
 *
 * "where" is a condition on the entry the lookup finds: only an entry that
 * holds the value AND meets it counts as found. Pins:
 *   - the grammar: the rule keys, the fields joining the searched fields, the
 *     refusals (no field, [record], event references, "&&", the field cap,
 *     "whereMessage" without "where");
 *   - the dictionary checks: fields that exist, a checkbox tested by code, a
 *     date or number literal written as REDCap stores it, two fields of the
 *     same kind, and fields the entry found can hold (not another repeating
 *     form, not a form missing from an event the entry can be in; a record ID
 *     lookup needs "event" in a longitudinal project);
 *   - the page config: "whereMessage" and its languages, never the condition
 *     or its fields;
 *   - evaluation: and/or/not with parentheses in any letter case, the rule's
 *     letter case, a blank side of ">" failing, Missing Data Codes as plain
 *     values, "007" equal to 7, Y-M-D dates, decimal-comma numbers by value, a
 *     checkbox of the event row read from a repeat instance, the narrowed read
 *     confirmed by a whole read of its candidates, a record-ID lookup over
 *     letter-case variants and records with no row in "event";
 *   - outcomes: "filtered" to staff, plain "not found" to a survey, the rights
 *     gate covering the "where" form, "return" copying only from entries that
 *     pass, the audit's reason, two rules with different "where" in one save
 *     and one scan, and the scan index keeping whether an entry passes.
 *
 * Run:  php tests/exists_where_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $PREFIX = 'universal_validator';
        public $logCalls = [];
        public $settingsBy = [];
        public $subSettingsBy = [];
        public $systemSettings = [];
        public $projectIdReturn = null;
        public function getSubSettings($k, $pid = null) {
            $p = ($pid !== null && $pid !== '') ? (int) $pid : (int) $this->projectIdReturn;
            return isset($this->subSettingsBy[$p][$k]) ? $this->subSettingsBy[$p][$k] : [];
        }
        public function getProjectSetting($k, $pid = null) {
            $p = ($pid !== null && $pid !== '') ? (int) $pid : (int) $this->projectIdReturn;
            return isset($this->settingsBy[$p][$k]) ? $this->settingsBy[$p][$k] : null;
        }
        public function getSystemSetting($k) { return isset($this->systemSettings[$k]) ? $this->systemSettings[$k] : null; }
        public function setSystemSetting($k, $v) { $this->systemSettings[$k] = $v; }
        public function getProjectId() { return $this->projectIdReturn; }
        public function isModuleEnabled($prefix, $pid = null) { return (int) $pid === 149; }
        public function getProjectStatus($pid = null) { return (int) $pid === 149 ? 'DEV' : null; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return count($this->logCalls); }
        public function initializeJavascriptModuleObject() { return '<script></script>'; }
        public function getJavascriptModuleObjectName() { return 'ExternalModules.TEST.UniversalValidator'; }
        public $rateBuckets = [];
        public $lastInsertId = 0;
        public function query($sql, $params = []) {
            if (strpos($sql, 'SELECT LAST_INSERT_ID()') !== false) return [[$this->lastInsertId]];
            if (strpos($sql, 'SELECT ROW_COUNT()') !== false) return [[1]];
            if (strpos($sql, 'FROM redcap_projects') !== false) return (int) $params[0] === 149 ? [[null]] : [];
            if (strpos($sql, 'uv_rate_bucket') !== false && strpos($sql, 'INSERT') === 0) {
                $k = (int) $params[0] . '|' . (int) $params[1];
                $this->rateBuckets[$k] = isset($this->rateBuckets[$k]) ? $this->rateBuckets[$k] + 1 : 1;
                $this->lastInsertId = $this->rateBuckets[$k];
            }
            return [];
        }
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
        public function isSuperUser() { return false; }
        public function getRights($pid = null) {
            return isset(\REDCap::$rightsBy[(int) $pid][$this->n]) ? \REDCap::$rightsBy[(int) $pid][$this->n] : null;
        }
    }
}

namespace {
    /**
     * REDCap's array export as the lookups meet it: only the fields asked
     * for, a checkbox as its codes in EVERY row (all "0" in a row of another
     * form, as REDCap exports it), and filterLogic judged row by row, so a
     * filtered read returns only the rows that match: a repeat instance
     * without its event row. That is the read "where" must not trust.
     */
    class REDCap {
        public static $data = [];
        public static $dictionaries = [];
        public static $rightsBy = [];
        public static $repeats = [];
        public static $calls = [];
        public static $saves = [];
        public static function reset() { self::$calls = []; self::$saves = []; }
        public static function getData($p) {
            $pid = (int) ($p['project_id'] ?? 0);
            self::$calls[] = $p + ['_pid' => $pid];
            if (!isset(self::$data[$pid])) throw new \RuntimeException('no such project');
            $dd = self::$dictionaries[$pid] ?? [];
            $fields = isset($p['fields']) ? array_map('strval', (array) $p['fields']) : null;
            $conds = [];
            if (!empty($p['filterLogic'])) {
                preg_match_all("/(lower\\()?\\[([a-z0-9_]+)\\]\\)? = (?:'([^']*)'|(-?[0-9.]+))/", $p['filterLogic'], $conds, PREG_SET_ORDER);
            }
            $keep = !empty($p['events']) ? array_map('strval', $p['events']) : null;
            $out = [];
            foreach (self::$data[$pid] as $rec => $node) {
                if (!empty($p['records']) && !in_array((string) $rec, array_map('strval', $p['records']), true)) continue;
                $n2 = [];
                foreach ($node as $k => $v) {
                    if ($k === 'repeat_instances') {
                        foreach ($v as $ev => $byForm) {
                            if ($keep !== null && !in_array((string) $ev, $keep, true)) continue;
                            foreach ($byForm as $form => $byInst) foreach ($byInst as $inst => $row) {
                                $row = self::project($row, $fields, $dd);
                                if ($conds && !self::rowMatches($row, $conds)) continue;
                                $n2['repeat_instances'][$ev][$form][$inst] = $row;
                            }
                        }
                        continue;
                    }
                    if ($keep !== null && !in_array((string) $k, $keep, true)) continue;
                    $row = self::project($v, $fields, $dd);
                    if ($conds && !self::rowMatches($row, $conds)) continue;
                    $n2[$k] = $row;
                }
                if ($n2) $out[$rec] = $n2;
            }
            return $out;
        }
        private static function project(array $row, $fields, array $dd) {
            $out = [];
            foreach ($fields === null ? array_keys($dd) : $fields as $f) {
                if (($dd[$f]['field_type'] ?? '') === 'checkbox') {
                    $box = [];
                    foreach (\INSPIRE\UniversalValidator\Logic::parseChoiceCodes($dd[$f]['select_choices_or_calculations']) as $c) {
                        $box[$c] = (isset($row[$f][$c]) && (string) $row[$f][$c] === '1') ? '1' : '0';
                    }
                    $out[$f] = $box;
                } else {
                    $out[$f] = array_key_exists($f, $row) ? $row[$f] : '';
                }
            }
            if (isset($row['redcap_data_access_group'])) $out['redcap_data_access_group'] = $row['redcap_data_access_group'];
            return $out;
        }
        private static function rowMatches(array $row, array $conds) {
            foreach ($conds as $c) {
                $s = (isset($row[$c[2]]) && !is_array($row[$c[2]])) ? (string) $row[$c[2]] : '';
                $want = isset($c[4]) && $c[4] !== '' ? $c[4] : $c[3];
                if ($c[1] !== '' ? strtolower($s) !== $want : $s !== $want) return false;
            }
            return true;
        }
        public static function saveData($pid, $format = 'array', $data = [], $overwrite = 'normal') {
            self::$saves[] = ['pid' => (int) $pid, 'data' => $data, 'overwrite' => $overwrite];
            return ['errors' => [], 'warnings' => [], 'ids' => array_keys($data), 'item_count' => 1];
        }
        public static function getDataDictionary($pid, $f = 'array') {
            if (!isset(self::$dictionaries[(int) $pid])) throw new \RuntimeException('no such project');
            return self::$dictionaries[(int) $pid];
        }
        public static function getRepeatingFormsEvents($pid = null) { return isset(self::$repeats[(int) $pid]) ? self::$repeats[(int) $pid] : []; }
        public static function getEventNames($unique = false, $assoc = false, $id = null) { return [351 => 'enrolment_arm_1', 352 => 'visit_arm_1']; }
        public static function getGroupNames($unique = false, $g = null) { return [7 => 'north', 8 => 'south'][$g] ?? ''; }
        public static function getRecordIdField() { return 'record_id'; }
        public static function getUserRights($u = null) { return []; }
    }
    class Project {
        public $longitudinal = true;
        public $project = ['missing_data_codes' => 'NA, Not applicable'];
        public $eventsForms = [351 => ['enrol', 'lab_reg', 'rep_form', 'result'], 352 => ['enrol', 'result']];
        public function __construct($pid) {}
        public function getUniqueEventNames() { return [351 => 'enrolment_arm_1', 352 => 'visit_arm_1']; }
        public function getUniqueGroupNames() { return []; }
    }

    require_once __DIR__ . '/../UniversalValidator.php';
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';

    use INSPIRE\UniversalValidator\AnnotationRules;
    use INSPIRE\UniversalValidator\Logic;
    use INSPIRE\UniversalValidator\MessageCatalog;
    use INSPIRE\UniversalValidator\ScanDimensions;

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }
    function f($form, $ann = '', $validation = '', $type = 'text', $ident = '', $choices = '') {
        return ['field_name' => '', 'field_type' => $type, 'form_name' => $form, 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => $validation, 'identifier' => $ident,
                'select_choices_or_calculations' => $choices];
    }
    function w($json) { return '@UVEXISTS=' . $json; }
    const YN = '0, No | 1, Yes';
    const ST = '1, Active | 2, Paused | 3, Withdrawn';
    const AB = '1, A | 2, B';
    const SITES = '1, North | 2, South';

    $DICT = [
        'record_id'   => f('enrol'),
        'home'        => f('enrol'),
        'home2'       => f('enrol'),
        'consent'     => f('enrol', '', '', 'radio', '', YN),
        'status'      => f('enrol', '', '', 'dropdown', '', ST),
        'cb'          => f('enrol', '', '', 'checkbox', '', AB),
        'specimen_id' => f('lab_reg'),
        'lab_status'  => f('lab_reg'),
        'lab_date'    => f('lab_reg', '', 'date_ymd'),
        'lab_dt'      => f('lab_reg', '', 'datetime_ymd'),
        'lab_num'     => f('lab_reg', '', 'number_1dp_comma_decimal'),
        'lab_cnt'     => f('lab_reg', '', 'integer'),
        'lab_site'    => f('lab_reg', '', '', 'dropdown', '', SITES),
        'rep_id'      => f('rep_form'),
        'rep_flag'    => f('rep_form'),
        // the rules, on the result form (in both events)
        'w_and'   => f('result', w('{"in":"[specimen_id]","where":"[consent]=\'1\' and ([status]<>\'3\' or [status]=\'\')",'
                      . '"whereMessage":{"default":"Registered but not eligible.","fr":"Enregistré mais non éligible."},"blockSave":"hard"}')),
        'w_upper' => f('result', w('{"in":"[specimen_id]","where":"[consent]=\'1\' AND NOT [status]=\'3\'"}')),
        'w_c0'    => f('result', w('{"in":"[specimen_id]","where":"[consent]=\'0\'"}')),
        'w_cs'    => f('result', w('{"in":"[specimen_id]","where":"[lab_status]=\'Ready\'","caseSensitive":true}')),
        'w_ci'    => f('result', w('{"in":"[specimen_id]","where":"[lab_status]=\'Ready\'"}')),
        'w_blank' => f('result', w('{"in":"[specimen_id]","where":"[lab_cnt]>2"}')),
        'w_mdc'   => f('result', w('{"in":"[specimen_id]","where":"[lab_status]<>\'NA\'"}')),
        'w_num'   => f('result', w('{"in":"[specimen_id]","where":"[lab_cnt]=7"}')),
        'w_date'  => f('result', w('{"in":"[specimen_id]","where":"[lab_date]>=\'2026-03-01\'"}')),
        'w_comma' => f('result', w('{"in":"[specimen_id]","where":"[lab_num]>3.4"}')),
        'w_cb'    => f('result', w('{"in":"[rep_id]","where":"[cb(2)]=\'1\'"}')),
        'w_rep'   => f('result', w('{"in":"[rep_id]","where":"[rep_flag]=\'1\' and [status]<>\'3\'"}')),
        'w_rec'   => f('result', w('{"in":"record","event":"enrolment_arm_1","where":"[consent]=\'1\'"}')),
        'w_ret'   => f('result', w('{"in":"[specimen_id]","where":"[consent]=\'1\'","return":{"lab_site":"[t_site]"}}')),
        't_site'  => f('result', '', '', 'dropdown', '', SITES),
        'w_s'     => f('result', w('{"in":"[specimen_id]","where":"[consent]=\'1\'","surveys":true}')),
        'w_m'     => f('result', w('{"in":"[specimen_id]","match":{"lab_site":"[t_msite]"},"where":"[consent]=\'1\'"}')),
        't_msite' => f('result', '', '', 'dropdown', '', SITES),
    ];
    // Refused set-ups: field => [the tag's JSON, the words the refusal holds]
    $BAD = [
        'b_ghost'  => ['{"in":"[specimen_id]","where":"[ghost]=\'1\'"}', 'the "where" condition references "[ghost]", which is not a field'],
        'b_cbcode' => ['{"in":"[specimen_id]","where":"[cb]=\'1\'"}', '"[cb]" is a checkbox'],
        'b_cbbad'  => ['{"in":"[specimen_id]","where":"[cb(9)]=\'1\'"}', 'has no choice code "9"'],
        'b_date'   => ['{"in":"[specimen_id]","where":"[lab_date]>\'01-03-2026\'"}', 'write it as Y-M-D'],
        'b_dt'     => ['{"in":"[specimen_id]","where":"[lab_dt]>=\'2026-03-01 8:30\'"}', 'write it as Y-M-D'],
        'b_comma'  => ['{"in":"[specimen_id]","where":"[lab_num]>\'3,4\'"}', 'with a decimal point, such as \'3.4\''],
        'b_kind'   => ['{"in":"[specimen_id]","where":"[lab_date]=[lab_status]"}', 'so both must be the same kind'],
        'b_mark'   => ['{"in":"[specimen_id]","where":"[lab_num]=[lab_cnt]"}', 'same decimal mark'],
        'b_rep'    => ['{"in":"[specimen_id]","where":"[rep_flag]=\'1\'"}', '"where" field "[rep_flag]" is on the repeating form "rep_form"'],
        'b_event'  => ['{"in":"[home2]","where":"[lab_status]=\'x\'"}', 'is on the form "lab_reg", which is not in every event'],
        'b_recev'  => ['{"in":"record","where":"[consent]=\'1\'"}', '"where" with "in":"record" needs "event" in a longitudinal project'],
        'b_recrep' => ['{"in":"record","event":"enrolment_arm_1","where":"[rep_flag]=\'1\'"}', 'is on the repeating form "rep_form" —'],
    ];
    foreach ($BAD as $fname => $spec) $DICT[$fname] = f('result', w($spec[0]));
    foreach ($DICT as $k => $_) $DICT[$k]['field_name'] = $k;

    $DATA = [
        '1' => [351 => ['record_id' => '1', 'home' => 'A', 'consent' => '1', 'status' => '1', 'cb' => ['1' => '0', '2' => '1'],
                        'specimen_id' => 'SP-OK', 'lab_status' => 'ready', 'lab_cnt' => '007', 'lab_date' => '2026-03-05',
                        'lab_num' => '3,5', 'lab_site' => '1'],
                'repeat_instances' => [351 => ['rep_form' => [1 => ['rep_id' => 'R-1', 'rep_flag' => '1']]]]],
        '2' => [351 => ['record_id' => '2', 'consent' => '0', 'status' => '3', 'specimen_id' => 'SP-NOCON', 'lab_status' => 'Ready',
                        'lab_date' => '2026-02-01', 'lab_num' => '3,3', 'lab_site' => '2'],
                'repeat_instances' => [351 => ['rep_form' => [1 => ['rep_id' => 'R-2', 'rep_flag' => '1']]]]],
        '3' => [351 => ['record_id' => '3', 'consent' => '1', 'status' => '3', 'specimen_id' => 'SP-WD']],
        '4' => [351 => ['record_id' => '4', 'consent' => '1', 'specimen_id' => 'SP-BLANKST']],
        // one value in two records: only the second meets "where"
        '5' => [351 => ['record_id' => '5', 'consent' => '0', 'specimen_id' => 'SP-TWO', 'lab_site' => '1']],
        '6' => [351 => ['record_id' => '6', 'consent' => '1', 'specimen_id' => 'SP-TWO', 'lab_site' => '2']],
        '7' => [351 => ['record_id' => '7', 'consent' => '1', 'specimen_id' => 'SP-NA', 'lab_status' => 'NA']],
        // record IDs that differ in letter case, and one with no row in the event searched
        'AB-1' => [351 => ['record_id' => 'AB-1', 'consent' => '0']],
        'ab-1' => [351 => ['record_id' => 'ab-1', 'consent' => '1']],
        'XY-9' => [351 => ['record_id' => 'XY-9', 'consent' => '0']],
        'NR-1' => [352 => ['record_id' => 'NR-1', 'consent' => '1']],
        // the record being edited
        '9' => [351 => ['record_id' => '9', 'home' => 'A', 'consent' => '1']],
    ];
    $FULL = ['forms' => ['enrol' => '1', 'lab_reg' => '1', 'rep_form' => '1', 'result' => '1'], 'group_id' => null, 'expiration' => ''];
    $NOENROL = ['forms' => ['enrol' => '0'] + $FULL['forms'], 'group_id' => null, 'expiration' => ''];

    function mod($user = 'nurse') {
        global $DICT, $DATA, $FULL, $NOENROL;
        $GLOBALS['__TEST_USER'] = $user;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectIdReturn = 149;
        $m->settingsBy = [149 => ['log-values' => 'raw']];
        \REDCap::$dictionaries = [149 => $DICT];
        \REDCap::$data = [149 => $DATA];
        \REDCap::$repeats = [149 => [351 => ['rep_form' => null]]];
        \REDCap::$rightsBy = [149 => ['nurse' => $FULL, 'noenrol' => $NOENROL]];
        \REDCap::reset();
        return $m;
    }
    function page($m) {
        ob_start();
        $m->redcap_data_entry_form_top(149, '9', 'result', 351, null, 1);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return json_decode(isset($mm[1]) ? $mm[1] : 'null', true);
    }
    function ruleFor($cfg, $f) {
        foreach ((isset($cfg['rules']) ? $cfg['rules'] : []) as $r) {
            if (in_array($f, isset($r['fields']) ? $r['fields'] : [], true)) return $r;
        }
        return null;
    }
    function errOf($cfg, $f) { $r = ruleFor($cfg, $f); return (string) ($r['configError'] ?? ''); }
    function ask($m, $field, $value) {
        return $m->redcap_module_ajax('exists-check', ['field' => $field, 'values' => [$field => $value]],
            149, '9', 'result', 351, 1, null, null, null, '', '', $GLOBALS['__TEST_USER'], null);
    }
    function askSurvey($m, $field, $value) {
        return $m->redcap_module_ajax('exists-check', ['field' => $field, 'values' => [$field => $value]],
            149, '9', 'result', 351, 1, 'hash', null, null, '', '', null, null);
    }
    /** The answer, as "found", "filtered", "not-found" or "unknown". */
    function outcome($r) {
        if (!is_array($r) || !isset($r['state'])) return 'error:' . json_encode($r);
        return $r['state'] === 'not-found' && !empty($r['filtered']) ? 'filtered' : $r['state'];
    }
    function findings($m, $type = 'invalid-id-saved') {
        return array_values(array_map(function ($c) { return $c[1]; },
            array_filter($m->logCalls, function ($c) use ($type) { return $c[0] === $type; })));
    }

    // ---- 1) grammar ------------------------------------------------------------------------
    $parse = function ($ann) { $r = AnnotationRules::parseAllTags($ann, ['qualified' => true]); return $r ? $r[0] : null; };
    $err = function ($ann) use ($parse) { $r = $parse($ann); return isset($r['error']) ? $r['error'] : ''; };
    $r = $parse(w('{"in":"[specimen_id]","where":" [Consent]=\'1\' OR [status]=\'2\' ","whereMessage":"Not eligible"}'));
    check('grammar: the condition (trimmed), its fields, the message', ($r['existsWhere'] ?? null) === "[Consent]='1' OR [status]='2'"
        && $r['existsWhereFields'] === ['consent', 'status'] && $r['whereMessage'] === 'Not eligible');
    check('grammar: the "where" fields join the searched fields', $r['existsTargets'] === ['specimen_id', 'consent', 'status']);
    $r = $parse(w('{"in":"record","where":"[consent]=\'1\'"}'));
    check('grammar: with "in":"record" the "where" fields are the searched fields', $r['existsTargets'] === ['consent']);
    $r = $parse(w('{"in":"[sid]","project":"lab","where":"[c(1)]=\'1\'"}'));
    check('grammar: another project\'s "where" fields are its searched fields', $r['existsRemoteTargets'] === ['sid', 'c']
        && !isset($r['existsTargets']));
    $r = $parse(w('{"in":"[sid]","where":"[a]=\'1\'","whereMessage":{"default":"x","fr":"y"}}'));
    check('grammar: "whereMessage" takes a language map', $r['whereMessage'] === 'x' && $r['i18n'] === ['whereMessage' => ['fr' => 'y']]);
    check('grammar: "&&" refused with the word to write', strpos($err(w('{"in":"[a]","where":"[b]=\'1\' && [c]=\'2\'"}')),
        'the "where" condition uses "&&" — write "and" instead.') !== false);
    check('grammar: a condition with no field refused', strpos($err(w('{"in":"[a]","where":"\'1\'=\'1\'"}')), 'must test at least one field') !== false);
    check('grammar: [record] refused', strpos($err(w('{"in":"[a]","where":"[record]=\'1\'"}')), 'cannot test [record]') !== false);
    check('grammar: an event reference refused', strpos($err(w('{"in":"[a]","where":"[enrolment_arm_1][b]=\'1\'"}')),
        'takes no event or instance references') !== false);
    check('grammar: a "where" that is not a string refused', strpos($err(w('{"in":"[a]","where":7}')), '"where" must be a condition') !== false);
    check('grammar: "whereMessage" without "where" refused', strpos($err(w('{"in":"[a]","whereMessage":"x"}')), '"whereMessage" needs "where"') !== false);
    $eleven = implode(' or ', array_map(function ($i) { return "[f$i]='1'"; }, range(1, 11)));
    check('grammar: more than 10 fields refused', strpos($err(w('{"in":"[a]","where":"' . $eleven . '"}')), 'at most 10 fields') !== false);
    $ten = implode(' or ', array_map(function ($i) { return "[f$i]='1'"; }, range(1, 10)));
    check('grammar: 10 fields accepted', $err(w('{"in":"[a]","where":"' . $ten . '"}')) === '');
    check('grammar: a hand-edited field list is refused', in_array('the fields of "where" do not match its condition — save the rule again.',
        AnnotationRules::checkExists(['type' => 'exists', 'existsIn' => 'a', 'existsWhere' => "[b]='1'", 'existsWhereFields' => ['c']]), true));
    check('grammar: "return" may not write a "where" field', strpos($err(w('{"in":"[a]","where":"[b]=\'1\'","return":{"c":"[b]"}}')),
        'which this lookup reads or searches') !== false);

    // ---- 2) dictionary checks and the page config --------------------------------------------
    $m = mod();
    $cfg = page($m);
    foreach ($BAD as $fname => $spec) {
        check('dictionary: ' . $fname . ' refused (' . $spec[1] . ')', strpos(errOf($cfg, $fname), $spec[1]) !== false);
    }
    foreach (['w_and', 'w_upper', 'w_c0', 'w_cs', 'w_ci', 'w_blank', 'w_mdc', 'w_num', 'w_date', 'w_comma', 'w_cb', 'w_rep',
              'w_rec', 'w_ret', 'w_s', 'w_m'] as $fname) {
        check('dictionary: ' . $fname . ' accepted', ruleFor($cfg, $fname) !== null && errOf($cfg, $fname) === '');
    }
    $ra = ruleFor($cfg, 'w_and');
    check('page: the rule carries "whereMessage" and its languages', ($ra['whereMessage'] ?? null) === 'Registered but not eligible.'
        && ($ra['i18n']['whereMessage']['fr'] ?? null) === 'Enregistré mais non éligible.');
    $json = json_encode($cfg);
    check('page: the condition and its fields never reach the page', strpos($json, 'existsWhere') === false
        && strpos($json, '[status]') === false && strpos($json, 'existsTargets') === false);

    // ---- 3) the live check: evaluation -------------------------------------------------------
    $cases = [
        // field, value, outcome
        ['w_and', 'SP-OK', 'found'],
        ['w_and', 'SP-BLANKST', 'found'],      // [status]='' in the OR
        ['w_and', 'SP-WD', 'filtered'],        // withdrawn
        ['w_and', 'SP-NOCON', 'filtered'],     // no consent
        ['w_and', 'SP-404', 'not-found'],
        ['w_upper', 'SP-OK', 'found'],         // AND / NOT in capitals
        ['w_upper', 'SP-WD', 'filtered'],
        ['w_c0', 'SP-NOCON', 'found'],
        ['w_cs', 'SP-OK', 'filtered'],         // 'ready' is not 'Ready' under caseSensitive
        ['w_ci', 'SP-OK', 'found'],
        ['w_blank', 'SP-OK', 'found'],         // '007' > 2
        ['w_blank', 'SP-NOCON', 'filtered'],   // blank: no answer, so it fails
        ['w_mdc', 'SP-NA', 'filtered'],        // a Missing Data Code is a plain value
        ['w_mdc', 'SP-OK', 'found'],
        ['w_num', 'SP-OK', 'found'],           // '007' = 7
        ['w_date', 'SP-OK', 'found'],
        ['w_date', 'SP-NOCON', 'filtered'],
        ['w_comma', 'SP-OK', 'found'],         // '3,5' read as 3.5
        ['w_comma', 'SP-NOCON', 'filtered'],   // '3,3'
        ['w_cb', 'R-1', 'found'],              // the event row's box, read from a repeat instance
        ['w_rep', 'R-1', 'found'],
        ['w_rep', 'R-2', 'filtered'],          // the event row says withdrawn
        ['w_rec', 'AB-1', 'found'],            // AB-1 fails, ab-1 passes
        ['w_rec', 'XY-9', 'filtered'],
        ['w_rec', 'NR-1', 'filtered'],         // no row in enrolment_arm_1
        ['w_rec', 'ZZ-0', 'not-found'],
        ['w_rec', '9', 'not-found'],           // the record being edited never finds itself
        ['w_m', 'SP-TWO', 'found'],
    ];
    foreach ($cases as $c) {
        $m = mod();
        $payload = [$c[0] => $c[1]];
        if ($c[0] === 'w_m') $payload['t_msite'] = '2';
        $r = $m->redcap_module_ajax('exists-check', ['field' => $c[0], 'values' => $payload],
            149, '9', 'result', 351, 1, null, null, null, '', '', 'nurse', null);
        check('live: ' . $c[0] . ' ' . $c[1] . ' is ' . $c[2], outcome($r) === $c[2]);
    }
    $m = mod();
    $r = $m->redcap_module_ajax('exists-check', ['field' => 'w_m', 'values' => ['w_m' => 'SP-TWO', 't_msite' => '1']],
        149, '9', 'result', 351, 1, null, null, null, '', '', 'nurse', null);
    check('live: "match" and "where" judge the same entry', outcome($r) === 'filtered');

    // The narrowed read names candidates only: R-2's matching row comes back
    // without its event row, which says withdrawn.
    $m = mod();
    ask($m, 'w_rep', 'R-2');
    $narrow = array_values(array_filter(\REDCap::$calls, function ($c) { return !empty($c['filterLogic']); }));
    $confirm = array_values(array_filter(\REDCap::$calls, function ($c) {
        return empty($c['filterLogic']) && ($c['records'] ?? null) === ['2'];
    }));
    check('narrowed read: one filtered read, then its candidates read whole', count($narrow) === 1 && count($confirm) === 1
        && in_array('status', $confirm[0]['fields'], true) && in_array('rep_flag', $confirm[0]['fields'], true));
    $m = mod();
    ask($m, 'w_rep', 'R-1');
    check('narrowed read: a hit in the confirming read is final (no whole-field read)',
        !array_filter(\REDCap::$calls, function ($c) { return empty($c['filterLogic']) && empty($c['records']); }));

    // ---- 4) who is told what -----------------------------------------------------------------
    $m = mod();
    check('survey: a filtered value is a plain "not found"', askSurvey($m, 'w_s', 'SP-NOCON') === ['state' => 'not-found', 'record' => null]);
    $m = mod();
    check('survey: found', askSurvey($m, 'w_s', 'SP-OK') === ['state' => 'found', 'record' => null]);
    $m = mod();
    $r = ask($m, 'w_s', 'SP-NOCON');
    check('staff: the same value is filtered', outcome($r) === 'filtered' && $r['record'] === null);
    $m = mod('noenrol');
    $r = ask($m, 'w_and', 'SP-OK');
    check('rights: no access to the "where" form gets the generic answer', ($r['state'] ?? null) === 'unknown'
        && ($r['why'] ?? null) === 'you do not have access to every form this lookup reads');

    // ---- 5) "return" copies only from entries that pass --------------------------------------
    $m = mod();
    $r = ask($m, 'w_ret', 'SP-TWO');
    check('return: the one passing entry gives the values (no conflict with the failing one)',
        outcome($r) === 'found' && ($r['values'] ?? null) === ['t_site' => '2'] && !isset($r['fill']));
    $m = mod();
    $r = ask($m, 'w_ret', 'SP-NOCON');
    check('return: a filtered value copies nothing', outcome($r) === 'filtered' && !isset($r['values']));

    // ---- 6) the post-save audit --------------------------------------------------------------
    $AUD = $DATA;
    $AUD['9'][351] += ['w_and' => 'SP-WD', 'w_c0' => 'SP-NOCON', 'w_upper' => 'SP-404', 'w_ci' => 'SP-OK', 'w_rec' => 'XY-9',
                       'w_ret' => 'SP-NOCON', 't_site' => '1'];
    $m = mod();
    \REDCap::$data = [149 => $AUD];
    $m->redcap_save_record(149, '9', 'result', 351, null, null, null, 1);
    $by = [];
    foreach (findings($m) as $e) $by[$e['field']] = $e;
    check('audit: a value that fails "where" is logged with reason filtered', ($by['w_and']['reason'] ?? null) === 'filtered'
        && $by['w_and']['type'] === 'exists');
    check('audit: a different "where" on the same value gets its own answer', !isset($by['w_c0']));
    check('audit: a value not saved anywhere stays not-found', ($by['w_upper']['reason'] ?? null) === 'not-found');
    check('audit: a value that passes is not logged', !isset($by['w_ci']));
    check('audit: a record-ID lookup that fails "where" is filtered', ($by['w_rec']['reason'] ?? null) === 'filtered');
    $wrote = [];
    foreach (\REDCap::$saves as $s) foreach ($s['data'] as $rec => $nodes) foreach ($nodes as $evt => $row) $wrote += $row;
    check('audit: a filtered "return" empties the field it fills', array_key_exists('t_site', $wrote) && $wrote['t_site'] === '');

    // ---- 7) the scan -------------------------------------------------------------------------
    $m = mod();
    \REDCap::$data = [149 => $AUD];
    $res = $m->scanProject(149, null, 200, null, ['valueCeiling' => 'raw']);
    $hits = [];
    foreach ($res['violations'] as $v) if ($v['type'] === 'exists') $hits[$v['field']] = $v['reason'] ?? null;
    ksort($hits);
    check('scan: the same verdicts as the audit, reasons included',
        $hits === ['w_and' => 'filtered', 'w_rec' => 'filtered', 'w_ret' => 'filtered', 'w_upper' => 'not-found']);
    $idx = array_values(array_filter(\REDCap::$calls, function ($c) {
        return empty($c['filterLogic']) && isset($c['fields']) && $c['fields'][0] === 'specimen_id';
    }));
    $sets = array_unique(array_map(function ($c) { return implode(',', $c['fields']); }, $idx));
    check('scan: rules with different "where" build their own index, each reading its "where" fields',
        in_array('specimen_id,consent,status', $sets, true) && in_array('specimen_id,consent', $sets, true));

    // ---- 8) the report's wording ---------------------------------------------------------------
    // A filtered finding is explained as the page explains it: "whereMessage"
    // (its default text), or else the catalog's sentence, never "message".
    $explain = function ($ann, $reason) {
        $rule = AnnotationRules::parseAllTags($ann, ['qualified' => true])[0];
        $snap = ScanDimensions::build(149, [], [$rule])->rule(1);
        return MessageCatalog::explain(['type' => 'exists', 'reason' => $reason, 'rule' => 1], $snap, 'staff');
    };
    $both = w('{"in":"[specimen_id]","where":"[consent]=\'1\'","message":"Register it first.",'
        . '"whereMessage":{"default":"Not eligible.","fr":"Non éligible."}}');
    $e = $explain($both, 'filtered');
    check('report: a filtered finding reads "whereMessage" in its default text', $e['text'] === 'Not eligible.' && $e['source'] === 'rule-message');
    $e = $explain($both, 'not-found');
    check('report: a value saved nowhere reads "message"', $e['text'] === 'Register it first.' && $e['source'] === 'rule-message');
    $e = $explain(w('{"in":"[specimen_id]","where":"[consent]=\'1\'","message":"Register it first."}'), 'filtered');
    check('report: a filtered finding without "whereMessage" reads the catalog, never "message"', $e['source'] === 'catalog'
        && $e['text'] === "The value is saved, but not in an entry that meets this rule's conditions.");
    $e = $explain(w('{"in":"[specimen_id]","message":"Register it first."}'), 'not-found');
    check('report: a rule without "where" is explained as before', $e['text'] === 'Register it first.');

    fwrite(STDOUT, "exists_where_php: $n checks, $fail failure(s)\n");
    exit($fail ? 1 : 0);
}
