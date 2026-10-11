<?php
/**
 * exists_return_php.php — @UVEXISTS "return" on the server.
 *
 * A lookup that finds the value copies fields of the entry it found into
 * fields of this form ("return":{"field there":"[field here]"}). Pins:
 *   - the grammar: the map, the fields it writes, the refusals (a list, a
 *     field written twice, "surveys", a field the lookup reads, the cap),
 *     "event" allowed with "in":"record" only with "return";
 *   - the dictionary checks: fields there that hold one value on the form of
 *     "in" or a form that does not repeat, fields here on this form that REDCap
 *     does not fill itself, the kinds of value each field here can hold, an
 *     Identifier there only into an Identifier here, and the page config: the
 *     fields here and those holding numbers, never where the values come from;
 *   - the exists-check endpoint: values as the page shows them (a date in the
 *     field's order), the record ID found, several entries that agree, several
 *     that disagree (nothing copied, "conflict"), entries only in another Data
 *     Access Group (nothing copied, "withheld"), the form-rights gate on the
 *     copied fields, the full read a rule with "return" always makes;
 *   - the save: values written as REDCap stores them, only where they differ,
 *     blank and not-found emptying the fields, nothing written for a conflict,
 *     a save without a signed-in user, a saver without rights to the copied
 *     fields, or with "exists-return-no-save"; one lookup per save;
 *   - another project: the agreement's list of fields that may be copied, the
 *     "answer" mode never copying, the probe log naming the fields copied and
 *     the record they came from, and the Configure dialog checks;
 *   - the scan never asks for values.
 *
 * Run:  php tests/exists_return_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $PREFIX = 'universal_validator';
        public $logCalls = [];
        public $settingsBy = [];      // pid => key => value
        public $subSettingsBy = [];   // pid => key => rows
        public $systemSettings = [];
        public $projectIdReturn = null;
        public $enabledIn = [149, 300];
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
        public function isModuleEnabled($prefix, $pid = null) {
            return $prefix === $this->PREFIX && in_array((int) $pid, $this->enabledIn, true);
        }
        public function getProjectStatus($pid = null) { return in_array((int) $pid, [149, 300], true) ? 'DEV' : null; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return count($this->logCalls); }
        public function initializeJavascriptModuleObject() { return '<script></script>'; }
        public function getJavascriptModuleObjectName() { return 'ExternalModules.TEST.UniversalValidator'; }
        public $rateBuckets = [];
        public $lastInsertId = 0;
        public function query($sql, $params = []) {
            if (strpos($sql, 'SELECT LAST_INSERT_ID()') !== false) return [[$this->lastInsertId]];
            if (strpos($sql, 'SELECT ROW_COUNT()') !== false) return [[1]];
            if (strpos($sql, 'FROM redcap_projects') !== false) return in_array((int) $params[0], [149, 300], true) ? [[null]] : [];
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
    class REDCap {
        public static $data = [];          // pid => records
        public static $dictionaries = [];  // pid => dictionary
        public static $rightsBy = [];      // pid => user => rights row
        public static $repeats = [];       // pid => getRepeatingFormsEvents map
        public static $calls = [];
        public static $saves = [];
        public static $ddCalls = [];
        public static function reset() { self::$calls = []; self::$saves = []; self::$ddCalls = []; }
        public static function getData($p) {
            $pid = (int) ($p['project_id'] ?? 0);
            self::$calls[] = $p + ['_pid' => $pid];
            if (!isset(self::$data[$pid])) throw new \RuntimeException('no such project');
            $out = [];
            foreach (self::$data[$pid] as $rec => $node) {
                if (!empty($p['records']) && !in_array((string) $rec, array_map('strval', $p['records']), true)) continue;
                if (!empty($p['events'])) {
                    $keep = array_map('strval', $p['events']);
                    $n2 = [];
                    foreach ($node as $k => $v) {
                        if ($k === 'repeat_instances') {
                            foreach ($v as $ev => $byForm) if (in_array((string) $ev, $keep, true)) $n2['repeat_instances'][$ev] = $byForm;
                        } elseif (in_array((string) $k, $keep, true)) {
                            $n2[$k] = $v;
                        }
                    }
                    if (!$n2) continue;
                    $node = $n2;
                }
                if (!empty($p['filterLogic'])) {
                    preg_match_all("/(lower\\()?\\[([a-z0-9_]+)\\]\\)? = (?:'([^']*)'|(-?[0-9.]+))/", $p['filterLogic'], $mm, PREG_SET_ORDER);
                    $hit = false;
                    foreach ($node as $k => $row) {
                        if ($k === 'repeat_instances' || !is_array($row)) continue;
                        $ok = true;
                        foreach ($mm as $c) {
                            $s = isset($row[$c[2]]) ? (string) $row[$c[2]] : '';
                            $ok = $ok && ($c[1] !== '' ? strtolower($s) === $c[3] : $s === $c[3]);
                        }
                        if ($ok) { $hit = true; break; }
                    }
                    if (!$hit) continue;
                }
                $out[$rec] = $node;
            }
            return $out;
        }
        public static function saveData($pid, $format = 'array', $data = [], $overwrite = 'normal') {
            self::$saves[] = ['pid' => (int) $pid, 'data' => $data, 'overwrite' => $overwrite];
            foreach ($data as $rec => $nodes) {
                foreach ($nodes as $evt => $row) {
                    if ($evt === 'repeat_instances') {
                        foreach ($row as $ev => $byKey) foreach ($byKey as $rk => $byInst) foreach ($byInst as $inst => $vals) {
                            foreach ($vals as $f => $v) self::$data[(int) $pid][$rec]['repeat_instances'][$ev][$rk][$inst][$f] = $v;
                        }
                        continue;
                    }
                    foreach ($row as $f => $v) self::$data[(int) $pid][$rec][$evt][$f] = $v;
                }
            }
            return ['errors' => [], 'warnings' => [], 'ids' => array_keys($data), 'item_count' => 1];
        }
        public static function getDataDictionary($pid, $f = 'array') {
            self::$ddCalls[] = (int) $pid;
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
        public static $long = [149 => false, 300 => true];
        public static $events = [300 => [901 => 'lab_arm_1', 902 => 'followup_arm_1']];
        // the forms of each event (read when the project is longitudinal)
        public static $eventsFormsBy = [
            149 => [351 => ['enrol', 'lab_reg', 'result', 'rep_form', 'lab_meta', 'lab_key'], 352 => ['enrol', 'lab_reg', 'result']],
            300 => [901 => ['lab', 'lab2', 'lab3', 'lab_rep'], 902 => ['lab', 'lab2']],
        ];
        public $longitudinal;
        public $project;
        public $eventsForms;
        private $pid;
        public function __construct($pid) {
            $this->pid = (int) $pid;
            $this->longitudinal = self::$long[$this->pid] ?? false;
            $this->project = ['missing_data_codes' => $this->pid === 149 ? 'NA, Not applicable' : ''];
            $this->eventsForms = self::$eventsFormsBy[$this->pid] ?? null;
        }
        public function getUniqueEventNames() { return self::$events[$this->pid] ?? []; }
        public function getUniqueGroupNames() { return []; }
    }

    require_once __DIR__ . '/../UniversalValidator.php';

    use INSPIRE\UniversalValidator\AnnotationRules;

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
    function ux($json) { return '@UVEXISTS=' . $json; }
    const SITES = '1, North | 2, South';
    const RET = '"return":{"lab_site":"[r_site]","coll_date":"[r_date]","dose":"[r_dose]","record":"[r_from]"}';

    $DICT = [
        'record_id'  => f('enrol'),
        'home'       => f('enrol'),
        'nat_id'     => f('enrol', '', '', 'text', 'y'),
        'specimen_id'=> f('lab_reg'),
        'lab_site'   => f('lab_reg', '', '', 'dropdown', '', SITES),
        'coll_date'  => f('lab_reg', '', 'date_ymd'),
        'coll_dt'    => f('lab_reg', '', 'datetime_ymd'),
        'dose'       => f('lab_reg', '', 'number_1dp'),
        'dose_c'     => f('lab_reg', '', 'number_1dp_comma_decimal'),
        'count'      => f('lab_reg', '', 'integer'),
        'code_id'    => f('lab_reg'),
        'mail'       => f('lab_reg', '', 'email'),
        'donor'      => f('lab_reg', '', '', 'text', 'y'),
        'ticks'      => f('lab_reg', '', '', 'checkbox', '', SITES),
        'rep_val'    => f('rep_form'),
        'r_spec'     => f('result', ux('{"in":"[specimen_id]",' . RET . '}')),
        'r_site'     => f('result', '@READONLY', '', 'dropdown', '', SITES . ' | 3, East'),
        'r_date'     => f('result', '', 'date_dmy'),
        'r_dose'     => f('result', '', 'number_1dp'),
        'r_from'     => f('result'),
        'r_code'     => f('result'),
        'r_text'     => f('result', ux('{"in":"[specimen_id]","return":{"code_id":"[r_code]"}}')),
        'r_rec'      => f('result', ux('{"in":"record","return":{"home":"[r_home]"}}')),
        'r_home'     => f('result'),
        'r_rec_ev'   => f('result', ux('{"in":"record","event":"visit_arm_1","return":{"home":"[r_home2]"}}')),
        'r_home2'    => f('result'),
        'r_donor'    => f('result', ux('{"in":"[specimen_id]","return":{"donor":"[r_dn]"}}')),
        'r_dn'       => f('result', '', '', 'text', 'y'),
        'r_off'      => f('result', ux('{"in":"[specimen_id]","return":{"lab_site":"[r_off_site]"},"blockSave":"off"}')),
        'r_off_site' => f('result'),
        'site_note'  => f('lab_meta'),
        'r_meta'     => f('result', ux('{"in":"[specimen_id]","return":{"site_note":"[r_meta_note]"}}')),
        'r_meta_note'=> f('result'),
        'r_dag'      => f('result', ux('{"in":"[specimen_id]","scope":"dag","return":{"lab_site":"[r_dag_site]"}}')),
        'r_dag_site' => f('result'),
        'r_br'       => f('result', ux('{"in":"[specimen_id]","when":"[home]=\'A\'","return":{"lab_site":"[r_br_site]"}}')
                                . ' ' . ux('{"in":"[specimen_id]","when":"[home]=\'Z\'"}')),
        'r_br_site'  => f('result'),
        'r_rec_rep'  => f('result', ux('{"in":"record","return":{"rep_val":"[t_any2]"}}')),
        't_any2'     => f('result'),
        'r_m'        => f('result', ux('{"in":"[specimen_id]","match":{"code_id":"[r_mloc]"},"return":{"lab_site":"[r_m_site]"}}')),
        'r_mloc'     => f('result'),
        'r_m_site'   => f('result'),
        // copies only the record ID found: the searched form's rights still count
        'r_ronly'    => f('result', ux('{"in":"[specimen_id]","return":{"record":"[r_ronly_from]"}}')),
        'r_ronly_from'=> f('result'),
        // a "match" field there on a form of its own
        'key_code'   => f('lab_key'),
        'r_mk'       => f('result', ux('{"in":"[specimen_id]","match":{"key_code":"[r_mkloc]"},"return":{"lab_site":"[r_mk_site]"}}')),
        'r_mkloc'    => f('result'),
        'r_mk_site'  => f('result'),
        // number_1dp into number validation without a place count
        'r_anydp'    => f('result', ux('{"in":"[specimen_id]","return":{"dose":"[t_dose_any]"}}')),
    ];
    // Refused set-ups: field => [the "return" (or whole tag), the words the refusal holds]
    $BAD = [
        'b_ghost'  => ['{"ghost":"[t_any]"}', 'is not a field in this project'],
        'b_tick'   => ['{"ticks":"[t_any]"}', 'is a checkbox field'],
        'b_rep'    => ['{"rep_val":"[t_any]"}', 'is on the repeating form "rep_form"'],
        'b_form'   => ['{"code_id":"[home]"}', 'must be on that form'],
        'b_self'   => ['{"code_id":"[b_self]"}', 'is this field itself'],
        'b_pk'     => ['{"code_id":"[record_id]"}', 'is the record ID'],
        'b_calc'   => ['{"code_id":"[t_calc]"}', 'filled by REDCap\'s @CALCTEXT'],
        'b_kind'   => ['{"code_id":"[t_drop]"}', 'a choice field takes the code of a choice field'],
        'b_codes'  => ['{"lab_site":"[t_drop1]"}', 'has no choice "2"'],
        'b_date'   => ['{"coll_dt":"[t_date]"}', 'holds dates and "coll_dt" holds dates with a time to the minute'],
        'b_int'    => ['{"dose":"[t_int]"}', 'takes whole numbers'],
        'b_int1dp' => ['{"count":"[t_dose]"}', 'takes numbers with exactly 1 decimal place and "count" holds whole numbers'],
        'b_1dp2dp' => ['{"dose":"[t_dose2]"}', 'takes numbers with exactly 2 decimal places and "dose" holds 1 decimal place'],
        'b_rich'   => ['{"code_id":"[t_rich]"}', 'rich text editor (@RICHTEXT)'],
        'b_onto'   => ['{"code_id":"[t_onto]"}', 'searches an ontology (BioPortal)'],
        'b_mark'   => ['{"dose_c":"[t_dose]"}', 'decimal mark'],
        'b_nonum'  => ['{"code_id":"[t_dose]"}', 'holds numbers and "code_id" does not'],
        'b_valid'  => ['{"code_id":"[t_mail]"}', 'both need the same one'],
        'b_choice' => ['{"lab_site":"[t_int]"}', 'holds choice codes'],
        'b_ident'  => ['{"donor":"[t_any]"}', 'must be flagged Identifier too'],
        'b_slider' => ['{"code_id":"[t_slider]"}', 'is a slider field'],
    ];
    foreach ($BAD as $fname => $spec) $DICT[$fname] = f('result', ux('{"in":"[specimen_id]","return":' . $spec[0] . '}'));
    $DICT += [
        't_any'    => f('result'),
        't_calc'   => f('result', '@CALCTEXT(concat([home],"x"))'),
        't_drop'   => f('result', '', '', 'dropdown', '', SITES),
        't_drop1'  => f('result', '', '', 'dropdown', '', '1, North'),
        't_date'   => f('result', '', 'date_dmy'),
        't_int'    => f('result', '', 'integer'),
        't_dose'   => f('result', '', 'number_1dp'),
        't_dose2'  => f('result', '', 'number_2dp'),
        't_dose_any'=> f('result', '', 'number'),
        't_rich'   => f('result', '@RICHTEXT', '', 'notes'),
        't_onto'   => f('result', '', '', 'text', '', 'BIOPORTAL:ICD10'),
        't_mail'   => f('result', '', 'email'),
        't_slider' => f('result', '', '', 'slider'),
    ];
    foreach ($DICT as $k => $_) $DICT[$k]['field_name'] = $k;

    $DATA = [
        '1' => [351 => ['record_id' => '1', 'home' => 'A', 'specimen_id' => 'SP-1', 'lab_site' => '1', 'coll_date' => '2026-03-01',
                        'dose' => '7.5', 'code_id' => '007', 'donor' => 'Ada', 'site_note' => 'N1', 'redcap_data_access_group' => 'north']],
        '2' => [351 => ['record_id' => '2', 'home' => 'B', 'specimen_id' => 'SP-2', 'lab_site' => '2', 'coll_date' => '2026-04-02',
                        'dose' => '8', 'redcap_data_access_group' => 'south'],
                352 => ['record_id' => '2', 'home' => 'B2']],
        '3' => [351 => ['record_id' => '3', 'specimen_id' => 'SP-DUP', 'lab_site' => '1', 'redcap_data_access_group' => 'north']],
        '4' => [351 => ['record_id' => '4', 'specimen_id' => 'sp-dup', 'lab_site' => '2', 'redcap_data_access_group' => 'south']],
        '5' => [351 => ['record_id' => '5', 'specimen_id' => 'SP-SAME', 'lab_site' => '1', 'redcap_data_access_group' => 'north']],
        '6' => [351 => ['record_id' => '6', 'specimen_id' => 'SP-SAME', 'lab_site' => '1', 'redcap_data_access_group' => 'north']],
        '7' => [351 => ['record_id' => '7', 'specimen_id' => 'SP-SOUTH', 'lab_site' => '2', 'redcap_data_access_group' => 'south']],
        '8' => [351 => ['record_id' => '8', 'specimen_id' => 'SP-NA', 'lab_site' => 'NA', 'redcap_data_access_group' => 'north']],
        '9' => [351 => ['record_id' => '9', 'home' => 'Z', 'r_spec' => 'SP-1', 'r_site' => '', 'redcap_data_access_group' => 'north']],
        // three entries, two of them agreeing
        '10' => [351 => ['record_id' => '10', 'specimen_id' => 'SP-TRI', 'lab_site' => '1']],
        '11' => [351 => ['record_id' => '11', 'specimen_id' => 'SP-TRI', 'lab_site' => '1']],
        '12' => [351 => ['record_id' => '12', 'specimen_id' => 'SP-TRI', 'lab_site' => '2']],
        // values that are not valid UTF-8 (a Latin-1 import)
        '13' => [351 => ['record_id' => '13', 'specimen_id' => 'SP-U', 'code_id' => "A\xE9"]],
        '14' => [351 => ['record_id' => '14', 'specimen_id' => 'SP-U', 'code_id' => "B\xE9"]],
        '15' => [351 => ['record_id' => '15', 'specimen_id' => "SP-\xE91", 'lab_site' => '1']],
        '16' => [351 => ['record_id' => '16', 'specimen_id' => "SP-\xE92", 'lab_site' => '2']],
    ];
    $DATA['1'][351]['key_code'] = 'K1';
    $FULL = ['forms' => ['enrol' => '1', 'lab_reg' => '1', 'result' => '1', 'rep_form' => '1', 'lab_meta' => '1', 'lab_key' => '1'], 'group_id' => null, 'expiration' => ''];
    $NOLAB = ['forms' => ['enrol' => '1', 'lab_reg' => '0', 'result' => '1', 'lab_meta' => '1', 'lab_key' => '1'], 'group_id' => null, 'expiration' => ''];
    // may search the lab register, may not read the form one copied field is on
    $NOMETA = ['forms' => ['enrol' => '1', 'lab_reg' => '1', 'result' => '1', 'rep_form' => '1', 'lab_meta' => '0', 'lab_key' => '1'], 'group_id' => null, 'expiration' => ''];
    // may read the searched and the copied fields, not the form of a "match" field there
    $NOKEY = ['forms' => ['lab_key' => '0'] + $FULL['forms'], 'group_id' => null, 'expiration' => ''];

    function mod($user = 'nurse', array $settings = []) {
        global $DICT, $DATA, $FULL, $NOLAB, $NOMETA, $NOKEY;
        $GLOBALS['__TEST_USER'] = $user;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectIdReturn = 149;
        $m->settingsBy = [149 => array_merge(['log-values' => 'raw', 'missing-data-codes' => 'NA'], $settings), 300 => []];
        \REDCap::$dictionaries = [149 => $DICT];
        \REDCap::$data = [149 => $DATA];
        \REDCap::$repeats = [149 => [351 => ['rep_form' => null]]];
        \REDCap::$rightsBy = [149 => ['nurse' => $FULL, 'nolab' => $NOLAB, 'nometa' => $NOMETA, 'nokey' => $NOKEY, 'north' => ['group_id' => 7] + $FULL]];
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
    function ask($m, $field, $value, $group = null) {
        return $m->redcap_module_ajax('exists-check', ['field' => $field, 'values' => [$field => $value]],
            149, '9', 'result', 351, 1, null, null, null, '', '', $GLOBALS['__TEST_USER'], $group);
    }
    function save($m, $group = null, $surveyHash = null) { $m->redcap_save_record(149, '9', 'result', 351, $group, $surveyHash, $surveyHash === null ? null : 5, 1); }
    function written() {
        $out = [];
        foreach (\REDCap::$saves as $s) foreach ($s['data'] as $rec => $nodes) foreach ($nodes as $evt => $row) {
            foreach ($row as $k => $v) $out[$k] = $v;
        }
        return $out;
    }
    function row9(array $vals) { foreach ($vals as $k => $v) \REDCap::$data[149]['9'][351][$k] = $v; }
    function unconfigurable($m) {
        return array_values(array_map(function ($c) { return $c[1]['why']; },
            array_filter($m->logCalls, function ($c) { return $c[0] === 'uvalidate-unconfigurable'; })));
    }
    function fullReads($pid = 149) {
        return count(array_filter(\REDCap::$calls, function ($c) use ($pid) {
            return $c['_pid'] === $pid && empty($c['records']) && empty($c['filterLogic']);
        }));
    }

    // ---- 1) grammar ------------------------------------------------------------------------
    $parse = function ($ann) { $r = AnnotationRules::parseAllTags($ann); return $r ? $r[0] : null; };
    $err = function ($ann) use ($parse) { $r = $parse($ann); return isset($r['error']) ? $r['error'] : ''; };
    $r = $parse(ux('{"in":"[specimen_id]","return":{"Lab_Site":"[R_Site]","record":"[r_from]"}}'));
    check('grammar: the map, lower-cased, and the fields it writes', ($r['existsReturn'] ?? null) === ['lab_site' => 'r_site', 'record' => 'r_from']
        && $r['existsReturnFields'] === ['r_site', 'r_from']);
    check('grammar: a list is refused', strpos($err(ux('{"in":"[a]","return":["[b]"]}')), '"return" must be an object') !== false);
    check('grammar: an empty object is refused', strpos($err(ux('{"in":"[a]","return":{}}')), '"return" must be an object') !== false);
    check('grammar: a value that is not one field is refused', strpos($err(ux('{"in":"[a]","return":{"b":"c d"}}')), 'must be one field reference of this form') !== false);
    check('grammar: "record" is not a field here', strpos($err(ux('{"in":"[a]","return":{"b":"record"}}')), 'must be one field reference of this form') !== false);
    check('grammar: [record] is refused as everywhere', strpos($err(ux('{"in":"[a]","return":{"b":"[record]"}}')), 'record') !== false);
    check('grammar: two values to one field are refused', strpos($err(ux('{"in":"[a]","return":{"b":"[x]","c":"[x]"}}')), 'to the same field "[x]"') !== false);
    check('grammar: a field there named twice (letter case) is refused', strpos($err(ux('{"in":"[a]","return":{"b":"[x]","B":"[y]"}}')), 'names "b" twice') !== false);
    check('grammar: "surveys" is refused with "return"', strpos($err(ux('{"in":"[a]","surveys":true,"return":{"b":"[x]"}}')), 'cannot be used with "surveys"') !== false);
    $many = [];
    for ($i = 0; $i <= AnnotationRules::MAX_EXISTS_RETURN; $i++) $many['s' . $i] = '[t' . $i . ']';
    check('grammar: the cap', strpos($err(ux(json_encode(['in' => '[a]', 'return' => $many]))), 'limited to ' . AnnotationRules::MAX_EXISTS_RETURN) !== false);
    check('grammar: writing to a "match" field is refused', strpos($err(ux('{"in":"[a]","match":{"b":"[here]"},"return":{"c":"[here]"}}')), 'which this lookup reads or searches') !== false);
    check('grammar: writing to the searched field here is refused', strpos($err(ux('{"in":"[a]","return":{"c":"[a]"}}')), 'which this lookup reads or searches') !== false);
    check('grammar: ...but another project\'s searched field may share a name here', $err(ux('{"in":"[a]","project":300,"return":{"c":"[a]"}}')) === '');
    check('grammar: "event" with "in":"record" is allowed with "return"', $err(ux('{"in":"record","event":"lab_arm_1","return":{"c":"[x]"}}')) === '');
    check('grammar: ...and still refused without', strpos($err(ux('{"in":"record","event":"lab_arm_1"}')), 'does not apply to "in":"record"') !== false);
    check('grammar: "return" is listed among the valid keys', strpos($err(ux('{"in":"[a]","nope":1}')), 'caseSensitive, return') !== false);

    // ---- 2) dictionary checks and the page config ----------------------------------------------
    $m = mod();
    $cfg = page($m);
    foreach ($BAD as $fname => $spec) {
        check('refused: ' . $fname . ' (' . $spec[1] . ') got ' . json_encode(errOf($cfg, $fname)), strpos(errOf($cfg, $fname), $spec[1]) !== false);
    }
    $rule = ruleFor($cfg, 'r_spec');
    check('a sound rule has no error', $rule && empty($rule['configError']));
    check('the page gets the fields it fills', ($rule['existsReturnFields'] ?? null) === ['r_site', 'r_date', 'r_dose', 'r_from']);
    check('...and the ones that hold numbers', ($rule['existsReturnNumbers'] ?? null) === ['r_dose']);
    check('...never where the values come from', !isset($rule['existsReturn']) && strpos(json_encode($rule), 'lab_site') === false
        && strpos(json_encode($rule), 'coll_date') === false);
    check('an Identifier there into an Identifier here is fine', errOf($cfg, 'r_donor') === '');
    check('"record" copied needs no field there', errOf($cfg, 'r_rec') === '');
    check('"in":"record" copies no field of a repeating form', strpos(errOf($cfg, 'r_rec_rep'), 'is on the repeating form "rep_form"') !== false);
    check('a branched rule with "return" in one branch sets up', errOf($cfg, 'r_br') === '' && errOf($cfg, 'r_dag') === '' && errOf($cfg, 'r_meta') === '');
    check('number_1dp into number validation without a place count is fine', errOf($cfg, 'r_anydp') === '');
    // a longitudinal project needs "event" for "in":"record"
    \Project::$long[149] = true;
    $cfg2 = page(mod());
    check('longitudinal: "in":"record" with "return" needs "event"', strpos(errOf($cfg2, 'r_rec'), 'needs "event" in a longitudinal project') !== false);
    $m = mod();
    \REDCap::$repeats[149][352] = 'WHOLE';
    $cfg2 = page($m);
    check('longitudinal: "in":"record" with an "event" that repeats is refused', strpos(errOf($cfg2, 'r_rec_ev'), 'and that event repeats') !== false);
    $m = mod();
    \REDCap::$repeats[149][352] = ['' => null];
    check('...a repeating event listed with an empty form name too', strpos(errOf(page($m), 'r_rec_ev'), 'and that event repeats') !== false);
    $m = mod();
    check('...and an event that does not repeat is fine', errOf(page($m), 'r_rec_ev') === '');
    // each copied field's form must be in every event the entry can be found in
    $m = mod();
    $cfgL = page($m);
    check('longitudinal: a copied field on a form one event of the searched form lacks is refused (got ' . json_encode(errOf($cfgL, 'r_meta')) . ')',
        strpos(errOf($cfgL, 'r_meta'), 'is on the form "lab_meta", which is not in every event that holds "lab_reg"') !== false);
    check('...a field of the searched form, or of a form every such event holds, is fine', errOf($cfgL, 'r_spec') === '' && errOf($cfgL, 'r_off') === '');
    $d4 = $DICT;
    $d4['r_rec_ev2'] = f('result', ux('{"in":"record","event":"visit_arm_1","return":{"site_note":"[t_any3]"}}'));
    $d4['t_any3'] = f('result');
    foreach ($d4 as $k => $_) $d4[$k]['field_name'] = $k;
    $m = mod();
    \REDCap::$dictionaries = [149 => $d4];
    check('longitudinal: "in":"record" copying a form the "event" lacks is refused',
        strpos(errOf(page($m), 'r_rec_ev2'), 'which is not in every event "visit_arm_1" names') !== false);
    $keepEf = \Project::$eventsFormsBy[149];
    \Project::$eventsFormsBy[149] = null;
    $m = mod();
    check('longitudinal: the forms of each event cannot be read: refused', strpos(errOf(page($m), 'r_spec'), 'which forms each event holds in this project could not be read') !== false);
    \Project::$eventsFormsBy[149] = $keepEf;
    \Project::$long[149] = false;
    // unreadable repeating map
    $m = mod();
    \REDCap::$repeats = [149 => 'broken'];
    check('which forms repeat cannot be read: refused', strpos(errOf(page($m), 'r_spec'), 'which forms repeat') !== false);
    // two rules writing one field, and a rule reading a field another writes
    $d2 = $DICT;
    $d2['r_two'] = f('result', ux('{"in":"[specimen_id]","return":{"code_id":"[r_from]"}}'));
    $d2['r_reads'] = f('result', ux('{"in":"[specimen_id]","match":{"code_id":"[r_code]"},"return":{"lab_site":"[t_any]"}}'));
    $d2['r_selfwhen'] = f('result', ux('{"in":"[specimen_id]","when":"[t_mail]<>\'\'","return":{"mail":"[t_mail]"}}'));
    \REDCap::$dictionaries = [149 => $d2];
    $m = mod();
    \REDCap::$dictionaries = [149 => $d2];
    $cfg3 = page($m);
    check('two rules writing one field are both refused', strpos(errOf($cfg3, 'r_two'), 'shares "[r_from]"') !== false
        && strpos(errOf($cfg3, 'r_spec'), 'shares "[r_from]"') !== false);
    check('a returning rule that reads a returned field is refused, named by its tag', strpos(errOf($cfg3, 'r_reads'), '@UVEXISTS "return": it reads "[r_code]"') !== false);
    check('a rule whose "when" reads a field it fills itself is refused (got ' . json_encode(errOf($cfg3, 'r_selfwhen')) . ')',
        strpos(errOf($cfg3, 'r_selfwhen'), 'it reads "[t_mail]", which this rule returns a value to') !== false);

    // ---- 3) the exists-check endpoint ------------------------------------------------------------
    $m = mod();
    $r = ask($m, 'r_spec', 'sp-1');
    check('found: the values as the page shows them, keyed by the field here (got ' . json_encode($r) . ')',
        ($r['state'] ?? null) === 'found' && ($r['values'] ?? null) === ['r_site' => '1', 'r_date' => '01-03-2026', 'r_dose' => '7.5', 'r_from' => '1']);
    check('...a rule with "return" always reads in full (no narrowed read)', fullReads() === 1
        && !array_filter(\REDCap::$calls, function ($c) { return !empty($c['filterLogic']); }));
    check('...the read carries the copied fields', in_array('coll_date', \REDCap::$calls[0]['fields'], true));
    $m = mod();
    check('text is copied exactly: "007"', (ask($m, 'r_text', 'SP-1')['values'] ?? null) === ['r_code' => '007']);
    $m = mod();
    ask($m, 'r_spec', 'SP-1');
    check('one module asked about two values answers each (got ' . json_encode(ask($m, 'r_spec', 'SP-2')) . ')',
        (ask($m, 'r_spec', 'SP-2')['values']['r_site'] ?? null) === '2');
    $m = mod();
    $r = ask($m, 'r_spec', 'SP-404');
    check('not found: no values', ($r['state'] ?? null) === 'not-found' && !isset($r['values']) && !isset($r['fill']));
    $m = mod();
    $r = ask($m, 'r_spec', 'SP-DUP');
    check('two entries that disagree: found, nothing copied (got ' . json_encode($r) . ')', ($r['state'] ?? null) === 'found'
        && !isset($r['values']) && ($r['fill'] ?? null) === 'conflict' && ($r['count'] ?? null) === 2);
    $m = mod();
    $r = ask($m, 'r_off', 'SP-SAME');
    check('two entries that agree: copied', ($r['values'] ?? null) === ['r_off_site' => '1'] && !isset($r['fill']));
    $m = mod();
    check('...but copying "record" from two records is a disagreement', (ask($m, 'r_spec', 'SP-SAME')['fill'] ?? null) === 'conflict');
    $m = mod('north');
    $r = ask($m, 'r_spec', 'SP-SOUTH', 7);
    check('found only in another group: nothing copied (got ' . json_encode($r) . ')', ($r['state'] ?? null) === 'found'
        && !isset($r['values']) && ($r['fill'] ?? null) === 'withheld');
    $m = mod('north');
    $r = ask($m, 'r_spec', 'SP-DUP', 7);
    check('a group user sees only their group: the disagreeing entry of another group does not count', ($r['values']['r_site'] ?? null) === '1');
    $m = mod('north');
    $r = ask($m, 'r_spec', 'sp-dup', 7);
    check('...and is shown the entry of their own group, though another is spelled as typed (got ' . json_encode($r) . ')',
        ($r['record'] ?? null) === '3' && ($r['values']['r_site'] ?? null) === '1');
    $m = mod('north');
    $r = ask($m, 'r_rec', '2', 7);
    check('"in":"record": a record of another group copies nothing for a group user (got ' . json_encode($r) . ')',
        ($r['state'] ?? null) === 'found' && !isset($r['values']) && ($r['fill'] ?? null) === 'withheld');
    $m = mod();
    $r = ask($m, 'r_dag', 'SP-2');
    check('"scope":"dag": an entry of another group is not found (got ' . json_encode($r) . ')', ($r['state'] ?? null) === 'not-found');
    $m = mod();
    check('"scope":"dag": an entry of the record\'s group copies', (ask($m, 'r_dag', 'SP-1')['values'] ?? null) === ['r_dag_site' => '1']);
    $m = mod('nolab');
    $r = ask($m, 'r_off', 'SP-1');
    check('no rights to the form of a copied field: could not check, no values', ($r['state'] ?? null) === 'unknown' && !isset($r['values']));
    $m = mod('nometa');
    $r = ask($m, 'r_meta', 'SP-1');
    check('...also when the searched form is readable and only a copied field\'s form is not (got ' . json_encode($r) . ')',
        ($r['state'] ?? null) === 'unknown' && !isset($r['values']));
    $m = mod('nometa');
    check('...while the same user gets values from forms they may open', (ask($m, 'r_off', 'SP-1')['values'] ?? null) === ['r_off_site' => '1']);
    $m = mod();
    check('a copied field on another form that does not repeat', (ask($m, 'r_meta', 'SP-1')['values'] ?? null) === ['r_meta_note' => 'N1']);
    $m = mod();
    check('a saved Missing Data Code is no value to copy', (ask($m, 'r_spec', 'SP-NA')['values']['r_site'] ?? null) === '');
    $m = mod();
    $r = ask($m, 'r_rec', '2');
    check('"in":"record": the field of the record found', ($r['values'] ?? null) === ['r_home' => 'B']);
    $m = mod();
    $r = ask($m, 'r_rec_ev', '2');
    check('"in":"record" with "event": the field in that event (got ' . json_encode($r) . ')', ($r['values'] ?? null) === ['r_home2' => 'B2']);
    $m = mod();
    $r = $m->redcap_module_ajax('exists-check', ['field' => 'r_spec', 'values' => ['r_spec' => 'SP-1']],
        149, '9', 'result', 351, 1, 'hash', null, null, '', '', null, null);
    check('a survey caller gets nothing (the rule is not open to surveys)', !isset($r['values']) && isset($r['error']));
    $m = mod();
    $r = $m->redcap_module_ajax('exists-check', ['field' => 'r_spec', 'values' => ['r_spec' => 'SP-1']],
        149, '9', 'result', 351, 1, 'hash', null, null, '', '', 'nurse', null);
    $copiedRead = (bool) array_filter(\REDCap::$calls, function ($c) { return in_array('coll_date', $c['fields'] ?? [], true); });
    check('a signed-in caller on a survey page: found, no values, and no copied field read (got ' . json_encode($r) . ')',
        ($r['state'] ?? null) === 'found' && !isset($r['values']) && !isset($r['fill']) && !$copiedRead);
    $m = mod();
    $r = ask($m, 'r_spec', 'SP-TRI');
    check('three entries, two agreeing: the count is of entries (got ' . json_encode($r) . ')',
        ($r['fill'] ?? null) === 'conflict' && ($r['count'] ?? null) === 3);
    $m = mod();
    $r = ask($m, 'r_text', 'SP-U');
    check('two entries whose values are not valid UTF-8 and differ: a conflict, not one answer (got ' . json_encode(array_keys($r)) . ')',
        ($r['fill'] ?? null) === 'conflict' && !isset($r['values']));
    $m = mod('nokey');
    $r = ask($m, 'r_mk', 'SP-1');
    check('no rights to the form of a "match" field there: could not check, no values (got ' . json_encode($r) . ')',
        ($r['state'] ?? null) === 'unknown' && strpos((string) ($r['why'] ?? ''), 'every form this lookup reads') !== false);
    $m = mod('nolab');
    $r = ask($m, 'r_ronly', 'SP-1');
    check('copying only the record ID still needs the searched form', ($r['state'] ?? null) === 'unknown' && !isset($r['values']));

    // ---- 4) the save ------------------------------------------------------------------------------
    $m = mod();
    save($m);
    check('save: the values written as REDCap stores them (got ' . json_encode(written()) . ')',
        written() === ['r_site' => '1', 'r_date' => '2026-03-01', 'r_dose' => '7.5', 'r_from' => '1'] && \REDCap::$saves[0]['overwrite'] === 'overwrite');
    $reads = fullReads();
    check('save: one lookup for the verdict and the values (' . $reads . ' full reads)', $reads === 1);
    check('save: the module\'s own write is read back into the kept read', (bool) array_filter(\REDCap::$calls, function ($c) {
        return ($c['records'] ?? null) === ['9'] && in_array('coll_date', $c['fields'] ?? [], true);
    }));
    \REDCap::reset();
    save($m);
    check('save again: nothing differs, nothing written', !\REDCap::$saves);
    check('...and the project is not read in full again in this request (' . fullReads() . ')', fullReads() === 0);
    \REDCap::$data[149]['1'][351]['lab_site'] = '2';
    // the change reaches this request's kept read through the save of record 1
    $m->redcap_save_record(149, '1', 'lab_reg', 351, null, null, null, 1);
    \REDCap::reset();
    save($m);
    check('a later save looks again: the entry found changed since (got ' . json_encode(written()) . ')', written() === ['r_site' => '2']);
    check('...from the kept read, with record 1 read again (' . fullReads() . ' full reads)', fullReads() === 0);
    $m = mod();
    row9(['r_spec' => 'NA', 'r_site' => '2']);
    save($m);
    check('a Missing Data Code empties the fields', written() === ['r_site' => '']);
    $m = mod();
    row9(['r_br' => 'SP-1', 'r_br_site' => 'held']);
    save($m);
    check('a branch without "return" empties the fields of the branch that has one (got ' . json_encode(written()) . ')',
        array_key_exists('r_br_site', written()) && written()['r_br_site'] === '');
    $m = mod();
    row9(['home' => 'A', 'r_br' => 'SP-1']);
    save($m);
    check('...and the branch with it fills them', (written()['r_br_site'] ?? null) === '1');
    $m = mod('nometa');
    row9(['r_meta' => 'SP-1']);
    save($m);
    check('a saver who may search but not read a copied field\'s form: nothing written for it, a rule problem',
        !isset(written()['r_meta_note']) && strpos(implode(' ', unconfigurable($m)), 'may not open every form this lookup reads') !== false);
    $m = mod('nolab');
    row9(['r_ronly' => 'SP-1', 'r_ronly_from' => '']);
    save($m);
    check('a saver who may not open the searched form gets no record ID copied (got ' . json_encode(written()) . ')',
        !isset(written()['r_ronly_from']) && strpos(implode(' ', unconfigurable($m)), 'may not open every form this lookup reads') !== false);
    $m = mod('nokey');
    row9(['r_mk' => 'SP-1', 'r_mkloc' => 'K1', 'r_mk_site' => '']);
    save($m);
    check('a saver who may not open the form of a "match" field there: nothing written for it',
        !isset(written()['r_mk_site']) && strpos(implode(' ', unconfigurable($m)), 'may not open every form this lookup reads') !== false);
    $m = mod();
    row9(['r_mk' => 'SP-1', 'r_mkloc' => 'K1', 'r_mk_site' => '']);
    save($m);
    check('...while a saver who may open it gets the value', (written()['r_mk_site'] ?? null) === '1');
    $m = mod();
    row9(['r_spec' => 'SP-404', 'r_site' => '2', 'r_date' => '2026-01-01', 'r_dose' => '1.0', 'r_from' => '2']);
    save($m);
    check('not found: the fields are emptied', written() === ['r_site' => '', 'r_date' => '', 'r_dose' => '', 'r_from' => '']);
    $m = mod();
    row9(['r_spec' => '', 'r_site' => '2']);
    save($m);
    check('a blank value empties the fields', written() === ['r_site' => '']);
    $m = mod();
    row9(['r_spec' => 'SP-DUP', 'r_site' => '2']);
    save($m);
    $u = unconfigurable($m);
    check('a conflict writes nothing and is a rule problem (got ' . json_encode($u) . ')', !\REDCap::$saves && count($u) === 1
        && strpos(implode(' ', $u), 'different values to copy') !== false);
    $m = mod(null);
    save($m);
    check('a save with no signed-in user (a job, an API call) copies nothing, and that is no rule problem', !\REDCap::$saves && !unconfigurable($m));
    $m = mod(null);
    row9(['r_spec' => '', 'r_site' => '2']);
    save($m);
    check('...though a blank value still empties the fields: nothing is copied', written() === ['r_site' => '']);
    // a survey respondent's save neither fills nor empties the fields
    foreach ([null, 'nurse'] as $who) {
        $m = mod($who);
        save($m, null, 'hash');
        $found = !\REDCap::$saves;
        $m = mod($who);
        row9(['r_spec' => '', 'r_site' => '2']);
        save($m, null, 'hash');
        $blank = !\REDCap::$saves;
        $m = mod($who);
        row9(['home' => 'Q', 'r_br' => 'SP-1', 'r_br_site' => 'held']);
        save($m, null, 'hash');
        $inert = !\REDCap::$saves;
        check('a survey save (' . ($who === null ? 'no user' : 'a signed-in browser') . ') writes nothing: found ' . json_encode($found)
            . ', blank ' . json_encode($blank) . ', no branch ' . json_encode($inert), $found && $blank && $inert && !unconfigurable($m));
    }
    $m = mod();
    save($m, null, 'hash');
    check('...and asks for no values', !array_filter(\REDCap::$calls, function ($c) { return in_array('coll_date', $c['fields'] ?? [], true); }));
    $m = mod();
    row9(['r_m' => 'SP-2', 'r_mloc' => '', 'r_m_site' => 'held']);
    save($m);
    check('a blank "match" field: nothing to narrow by yet, the fields keep their values', !isset(written()['r_m_site']));
    $m = mod('nolab');
    save($m);
    check('a saver who may not read the copied fields: nothing written, a rule problem', !\REDCap::$saves
        && strpos(implode(' ', unconfigurable($m)), 'may not open every form this lookup reads') !== false);
    $m = mod('nurse', ['exists-return-no-save' => true]);
    save($m);
    check('"exists-return-no-save": nothing written', !\REDCap::$saves);
    $m = mod('nurse', ['exists-return-no-save' => true]);
    row9(['r_spec' => 'SP-DUP', 'r_site' => '2']);
    save($m);
    check('...no rule problem about values it would not write, and no copied field read',
        !\REDCap::$saves && strpos(implode(' ', unconfigurable($m)), 'to copy') === false
        && !array_filter(\REDCap::$calls, function ($c) { return in_array('coll_date', $c['fields'] ?? [], true); }));
    $m = mod('nurse', ['range-return-no-save' => true]);
    save($m);
    check('...and the @UVRANGE switch does not touch @UVEXISTS', isset(written()['r_site']));
    $m = mod('north');
    row9(['r_spec' => 'SP-SOUTH']);
    save($m, 7);
    check('a saver in a group: an entry only in another group copies nothing', !\REDCap::$saves
        && strpos(implode(' ', unconfigurable($m)), 'outside the Data Access Group') !== false);
    $m = mod();
    row9(['r_spec' => 'SP-1', 'r_dose' => '7.50']);
    save($m);
    check('a number field holding the same number spelled another way is left alone', !isset(written()['r_dose']));
    $m = mod();
    row9(['r_text' => 'SP-1', 'r_code' => '7']);
    save($m);
    check('a Text field holding "7" gets "007": text is not a number', (written()['r_code'] ?? null) === '007');

    // ---- 5) the scan never asks for values -------------------------------------------------------------
    $m = mod();
    $rf = new \ReflectionMethod($m, 'ruleFindings');
    $rf->setAccessible(true);
    $gr = new \ReflectionMethod($m, 'getRules');
    $gr->setAccessible(true);
    $rule = null;
    foreach ($gr->invoke($m, 149) as $rr) if (in_array('r_spec', $rr['fields'], true)) $rule = $rr;
    $res = [];
    $vals = ['r_spec' => 'SP-1'];
    $scanned = $rf->invoke($m, $rule, 0, $vals, [], null, 149, '9', 351, null, $res, []);
    check('the scan path works nothing out', !isset($scanned['returns']));
    $audited = $rf->invoke($m, $rule, 0, $vals, [], null, 149, '9', 351, null, $res, ['returns' => true]);
    check('the audit path does', ($audited['returns']['r_site'] ?? null) === '1');
    $rule = null;
    foreach ($gr->invoke($m, 149) as $rr) if (in_array('r_rec_ev', $rr['fields'], true)) $rule = $rr;
    $el = new \ReflectionMethod($m, 'existsLookup');
    $el->setAccessible(true);
    $rule2 = null;
    foreach ($gr->invoke($m, 149) as $rr) if (in_array('r_spec', $rr['fields'], true)) $rule2 = $rr;
    $a1 = $el->invoke($m, 149, $rule2, 'SP-1', [], 351, '9', ['callerGroup' => null, 'returns' => true]);
    $a2 = $el->invoke($m, 149, $rule2, 'SP-2', [], 351, '9', ['callerGroup' => null, 'returns' => true]);
    check('one save asking about two values gets an answer for each (the memo keys on the value)',
        ($a1['returned']['lab_site'] ?? null) === '1' && ($a2['returned']['lab_site'] ?? null) === '2');
    $u1 = $el->invoke($m, 149, $rule2, "SP-\xE91", [], 351, '9', ['callerGroup' => null, 'returns' => true]);
    $u2 = $el->invoke($m, 149, $rule2, "SP-\xE92", [], 351, '9', ['callerGroup' => null, 'returns' => true]);
    check('...also two values that are not valid UTF-8',
        ($u1['returned']['lab_site'] ?? null) === '1' && ($u2['returned']['lab_site'] ?? null) === '2');
    $a3 = $el->invoke($m, 149, $rule2, 'SP-1', [], 351, '9', ['callerGroup' => null]);
    check('a lookup for a save that writes nothing asks for no values', ($a3['state'] ?? null) === 'found' && !isset($a3['returned']));
    $sc = $rf->invoke($m, $rule, 0, ['r_rec_ev' => '1'], [], null, 149, '9', 351, null, $res, ['existsIndex' => true]);
    check('the scan: "in":"record" with "event" finds a record that has no row in that event (got ' . json_encode($sc) . ')',
        !$sc['invalid'] && !$sc['unconfigurable']);

    // ---- 6) another project ------------------------------------------------------------------------------
    $DICT_B = [
        'record_id'   => f('lab'),
        'specimen_id' => f('lab'),
        'site_code'   => f('lab'),
        'lab_date'    => f('lab', '', 'date_ymd'),
        'donor_name'  => f('lab', '', '', 'text', 'y'),
        'lab_note'    => f('lab2'),
        'rep_note'    => f('lab_rep'),
        'site_pick'   => f('lab', '', '', 'dropdown', '', '1, A | 2, B | 9, Z'),
        'v_note'      => f('lab3'),
    ];
    foreach ($DICT_B as $k => $_) $DICT_B[$k]['field_name'] = $k;
    $DICT_X = $DICT;
    $DICT_X['x_spec'] = f('result', ux('{"in":"[specimen_id]","project":300,"return":{"site_code":"[x_site]","lab_date":"[x_date]","record":"[x_from]"}}'));
    $DICT_X['x_site'] = f('result');
    $DICT_X['x_date'] = f('result', '', 'date_mdy');
    $DICT_X['x_from'] = f('result');
    $DICT_X['x_donor'] = f('result', ux('{"in":"[specimen_id]","project":300,"return":{"donor_name":"[x_dn]"}}'));
    $DICT_X['x_dn'] = f('result');
    $DICT_X['x_note'] = f('result', ux('{"in":"[specimen_id]","project":300,"return":{"lab_note":"[x_note_t]"}}'));
    $DICT_X['x_note_t'] = f('result');
    // refusals that would describe how project 300 is built
    $DICT_X['x_rep'] = f('result', ux('{"in":"[specimen_id]","project":300,"return":{"rep_note":"[x_rep_t]"}}'));
    $DICT_X['x_rep_t'] = f('result');
    $DICT_X['x_pick'] = f('result', ux('{"in":"[specimen_id]","project":300,"return":{"site_pick":"[x_pick_t]"}}'));
    $DICT_X['x_pick_t'] = f('result', '', '', 'dropdown', '', '1, A | 2, B');
    $DICT_X['x_v'] = f('result', ux('{"in":"[specimen_id]","project":300,"return":{"v_note":"[x_v_t]"}}'));
    $DICT_X['x_v_t'] = f('result');
    foreach ($DICT_X as $k => $_) $DICT_X[$k]['field_name'] = $k;
    function xmod($user = 'nurse', array $row = null, $logMode = 'hashed') {
        global $DICT_X, $DICT_B, $DATA, $FULL;
        $m = mod($user);
        $m->systemSettings['exists-system-cross-project'] = true;
        $m->settingsBy[300] = ['log-values' => $logMode];
        $m->subSettingsBy = [300 => ['exists-consumers' => [$row === null ? [
            'exists-consumer-project' => '149', 'exists-consumer-targets' => 'specimen_id',
            'exists-consumer-returns' => 'site_code, lab_date, record, lab_note, rep_note, site_pick, v_note', 'exists-consumer-mode' => 'rights'] : $row]]];
        \REDCap::$dictionaries = [149 => $DICT_X, 300 => $DICT_B];
        \REDCap::$data = [149 => $DATA, 300 => [
            'L1' => [901 => ['record_id' => 'L1', 'specimen_id' => 'LS-1', 'site_code' => 'North', 'lab_date' => '2026-02-01', 'donor_name' => 'Ada',
                             'lab_note' => 'kept cold']],
        ]];
        \REDCap::$repeats = [149 => [351 => ['rep_form' => null]], 300 => [901 => ['lab_rep' => null]]];
        \REDCap::$rightsBy[300] = ['nurse' => ['forms' => ['lab' => '1', 'lab2' => '0'], 'group_id' => null, 'expiration' => ''],
                                   'nolab' => ['forms' => ['lab' => '0', 'lab2' => '0'], 'group_id' => null, 'expiration' => '']];
        \REDCap::$rightsBy[149]['nolab'] = $FULL;
        return $m;
    }
    $m = xmod();
    $cfg = page($m);
    check('another project: a sound rule (got ' . json_encode(errOf($cfg, 'x_spec')) . ')', errOf($cfg, 'x_spec') === '');
    check('another project: a field not on the agreement\'s copy list gets the one refusal', strpos(errOf($cfg, 'x_donor'), 'cannot be searched from this project') !== false);
    foreach (['x_rep' => 'lab_rep', 'x_v' => 'lab3'] as $xf => $formThere) {
        check('another project: a refusal that would name its forms is the one "return" refusal (' . $xf . ', got ' . json_encode(errOf($cfg, $xf)) . ')',
            strpos(errOf($cfg, $xf), 'project 300 cannot give the values "return" asks for') !== false && strpos(errOf($cfg, $xf), $formThere) === false);
    }
    check('another project: a choice field missing a code there is refused without the codes (got ' . json_encode(errOf($cfg, 'x_pick')) . ')',
        strpos(errOf($cfg, 'x_pick'), 'lacks a choice code that "site_pick" of project 300 can hold') !== false && strpos(errOf($cfg, 'x_pick'), '"9"') === false);
    $r = ask($m, 'x_spec', 'ls-1');
    check('another project: the values, a date in this field\'s order, the record found (got ' . json_encode($r) . ')',
        ($r['values'] ?? null) === ['x_site' => 'North', 'x_date' => '02-01-2026', 'x_from' => 'L1'] && array_key_exists('record', $r) && $r['record'] === null);
    $probe = array_values(array_filter($m->logCalls, function ($c) { return $c[0] === 'uv-exists-probe'; }));
    check('the probe log there names the fields copied and the record they came from (hashed mode: raw record id)',
        ($probe[0][1]['returned'] ?? null) === 'site_code,lab_date,record' && ($probe[0][1]['copied_from'] ?? null) === 'L1'
        && strpos(json_encode($probe), 'North') === false);
    $m = xmod('nurse', null, 'none');
    ask($m, 'x_spec', 'LS-1');
    $probe = array_values(array_filter($m->logCalls, function ($c) { return $c[0] === 'uv-exists-probe'; }));
    check('...a log that keeps no values keeps the record as a keyed hash', !isset($probe[0][1]['copied_from']) && isset($probe[0][1]['copied_from_hmac']));
    $m = xmod();
    ask($m, 'x_spec', 'LS-404');
    $probe = array_values(array_filter($m->logCalls, function ($c) { return $c[0] === 'uv-exists-probe'; }));
    check('...a lookup that copied nothing names the fields the rule copies, and no record',
        ($probe[0][1]['returned'] ?? null) === 'site_code,lab_date,record' && !isset($probe[0][1]['copied_from'])
        && !isset($probe[0][1]['copied_from_hmac']));
    $m = xmod('nolab');
    $r = ask($m, 'x_spec', 'LS-1');
    check('another project: no rights there to a copied field: no values', ($r['state'] ?? null) === 'unknown' && !isset($r['values']));
    $m = xmod();
    $r = ask($m, 'x_note', 'LS-1');
    check('...also when the searched form there is readable and only a copied field\'s form is not (got ' . json_encode($r) . ')',
        ($r['state'] ?? null) === 'unknown' && !isset($r['values']));
    $m = xmod('nurse', ['exists-consumer-project' => '149', 'exists-consumer-targets' => 'specimen_id',
        'exists-consumer-returns' => 'site_code, lab_date, record', 'exists-consumer-mode' => 'answer']);
    check('another project: the "answer" mode never lets values be copied', strpos(errOf(page($m), 'x_spec'), 'cannot be searched from this project') !== false);
    $m = xmod();
    row9(['x_spec' => 'LS-1']);
    save($m);
    check('another project: the save writes the values as stored (got ' . json_encode(written()) . ')',
        (written()['x_site'] ?? null) === 'North' && (written()['x_date'] ?? null) === '2026-02-01' && (written()['x_from'] ?? null) === 'L1');
    $probe = array_values(array_filter($m->logCalls, function ($c) { return $c[0] === 'uv-exists-probe'; }));
    check('...asked there once for the verdict and the values', count($probe) === 1);
    $m = xmod();
    $m->settingsBy[149]['exists-return-no-save'] = true;
    row9(['x_spec' => 'LS-1']);
    save($m);
    $probe = array_values(array_filter($m->logCalls, function ($c) { return $c[0] === 'uv-exists-probe'; }));
    check('another project, "exists-return-no-save": nothing written, and the probe line there hands nothing over (got ' . json_encode($probe) . ')',
        !\REDCap::$saves && count($probe) === 1 && !isset($probe[0][1]['returned']) && !isset($probe[0][1]['copied_from']));
    $m = xmod();
    $r = $m->redcap_module_ajax('exists-check', ['field' => 'x_spec', 'values' => ['x_spec' => 'LS-1']],
        149, '9', 'result', 351, 1, 'hash', null, null, '', '', 'nurse', null);
    $probe = array_values(array_filter($m->logCalls, function ($c) { return $c[0] === 'uv-exists-probe'; }));
    check('another project, a signed-in caller on a survey page: no values, and none logged as handed over',
        !isset($r['values']) && count($probe) === 1 && !isset($probe[0][1]['returned']) && !isset($probe[0][1]['copied_from']));

    $m = xmod('nurse', ['exists-consumer-project' => '149', 'exists-consumer-targets' => 'specimen_id',
        'exists-consumer-returns' => 'site_code, lab_date', 'exists-consumer-mode' => 'rights']);
    check('another project: copying its record ID needs "record" on the list', strpos(errOf(page($m), 'x_spec'), 'cannot be searched from this project') !== false);
    $d = $DICT_X;
    unset($d['x_spec'], $d['x_note'], $d['x_rep'], $d['x_pick'], $d['x_v']);
    $m = xmod();
    \REDCap::$dictionaries[149] = $d;
    \REDCap::reset();
    $cfg = page($m);
    check('another project: a rule the agreement refuses never reads the dictionary there',
        strpos(errOf($cfg, 'x_donor'), 'cannot be searched') !== false && !in_array(300, \REDCap::$ddCalls, true));

    // ---- 7) the Configure dialog checks -------------------------------------------------------------------
    $csp = new \ReflectionMethod(\INSPIRE\UniversalValidator\UniversalValidator::class, 'crossSettingsProblems');
    $csp->setAccessible(true);
    $probs = function (array $row) use ($csp) {
        $s = [];
        foreach ($row as $k => $v) $s[$k] = [0 => $v];
        return $csp->invoke(null, $s, 300, ['record_id', 'specimen_id', 'site_code'], []);
    };
    check('dialog: a copy list under "answer" is refused', strpos(implode(' ', $probs(['exists-consumer-project' => '149',
        'exists-consumer-targets' => 'specimen_id', 'exists-consumer-returns' => 'site_code', 'exists-consumer-mode' => 'answer'])), 'copied only by users with rights') !== false);
    check('dialog: a field it may copy that is not a field', strpos(implode(' ', $probs(['exists-consumer-project' => '149',
        'exists-consumer-targets' => 'specimen_id', 'exists-consumer-returns' => 'site_code, ghost', 'exists-consumer-mode' => 'rights'])), '"ghost" (a field it may copy)') !== false);
    check('dialog: a name that is no field name', strpos(implode(' ', $probs(['exists-consumer-project' => '149',
        'exists-consumer-targets' => 'specimen_id', 'exists-consumer-returns' => 'site code!', 'exists-consumer-mode' => 'rights'])), 'must be field names') !== false);
    check('dialog: a sound copy list', $probs(['exists-consumer-project' => '149', 'exists-consumer-targets' => 'specimen_id',
        'exists-consumer-returns' => 'site_code, record', 'exists-consumer-mode' => 'rights']) === []);

    fwrite(STDOUT, "exists_return_php: $n checks, $fail failure(s)\n");
    exit($fail ? 1 : 0);
}
