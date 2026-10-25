<?php
/**
 * rule_scope_php.php — a live lookup builds only the rules of its field (PRF-3).
 *
 * getRules($pid, [$field]) reads the settings rules, the tags of that field,
 * and every tag that may return a value. For
 * every field of the projects below, the rules that name the field come out
 * of that build as they come out of the full one (other fields of a merged
 * rule aside, which a lookup never reads). The lookups use it, the page does
 * not, and the project setting is read once per rule build, not once per tag.
 *
 * Run:  php tests/rule_scope_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $logCalls = []; public $subSettings = []; public $projectSettings = [];
        public $systemSettings = []; public $projectIdReturn = null;
        public static $settingReads = [];
        public function getSubSettings($k, $pid = null) {
            $e = ($pid !== null && $pid !== '') ? $pid : $this->projectIdReturn;
            return $e ? $this->subSettings : [];
        }
        public function getProjectSetting($k, $pid = null) {
            self::$settingReads[$k] = (self::$settingReads[$k] ?? 0) + 1;
            $e = ($pid !== null && $pid !== '') ? $pid : $this->projectIdReturn;
            if (!$e) return null;
            return isset($this->projectSettings[$k]) ? $this->projectSettings[$k] : null;
        }
        public function getSystemSetting($k) {
            if ($k === 'log-hmac-key') return str_repeat('a', 64);
            return $this->systemSettings[$k] ?? null;
        }
        public function setSystemSetting($k, $v) { $this->systemSettings[$k] = $v; }
        public function getProjectId() { return $this->projectIdReturn; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return count($this->logCalls); }
        public function query($sql, $params = []) {
            if (stripos($sql, 'LAST_INSERT_ID()') !== false && stripos($sql, 'SELECT') === 0) return [[1]];
            if (stripos($sql, 'ROW_COUNT()') !== false) return [[1]];
            return [];
        }
        public function initializeJavascriptModuleObject() { return '<script></script>'; }
        public function getJavascriptModuleObjectName() { return 'EM.T.UV'; }
        public function getUser() {
            $u = isset($GLOBALS['__TEST_USER']) ? $GLOBALS['__TEST_USER'] : null;
            return $u === null ? null : new TestUser($u);
        }
    }
    class TestUser {
        private $n; public function __construct($n) { $this->n = $n; }
        public function getUsername() { return $this->n; }
        public function hasDesignRights() { return true; }
        public function isSuperUser() { return false; }
        public function getRights($pid = null) {
            $all = \REDCap::$rights;
            return isset($all[$this->n]) ? $all[$this->n] : null;
        }
    }
}

namespace {
    ini_set('session.use_cookies', '0');
    ini_set('session.use_only_cookies', '0');
    ini_set('session.cache_limiter', '');
    ini_set('session.save_path', sys_get_temp_dir());

    class REDCap {
        public static $data = []; public static $dictionary = []; public static $rights = [];
        public static function getRepeatingFormsEvents($pid) { return []; }
        public static function getEventNames($u = false, $a = false) { return [1 => 'event_1_arm_1']; }
        public static function isLongitudinal() { return false; }
        public static function getData($p) {
            $data = self::$data;
            if (isset($p['records'])) $data = array_intersect_key($data, array_fill_keys(array_map('strval', $p['records']), true));
            return $data;
        }
        public static function getDataDictionary($pid, $f = 'array') {
            if (!$pid) throw new \RuntimeException('needs pid');
            return self::$dictionary;
        }
        public static function getUserRights($u = null) { return self::$rights; }
        public static function getGroupNames($a = false, $b = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
        public static function getInstrumentEventMappings($pid = null) { return null; }
        public static function getInstrumentNames($f = null, $pid = null) { return []; }
    }
    class Project {
        public $project; public $longitudinal = false; public $eventsForms = [];
        public function __construct($pid) { $this->project = ['missing_data_codes' => '']; }
        public function getRepeatingFormsEvents() { return []; }
    }

    require_once __DIR__ . '/../UniversalValidator.php';
    require_once __DIR__ . '/support/return_targets.php';
    use ExternalModules\AbstractExternalModule as EMA;

    $n = 0; $fail = 0;
    function check($label, $cond) { global $n, $fail; $n++; if (!$cond) { $fail++; echo "FAIL: $label\n"; } }

    const PID = 149;

    function f($form, $ann = '', $type = 'text', $validation = '', $choices = '') {
        return ['field_name' => '', 'form_name' => $form, 'field_type' => $type, 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => $validation,
                'select_choices_or_calculations' => $choices, 'identifier' => '',
                'field_label' => 'Label', 'branching_logic' => '', 'required_field' => ''];
    }
    function named(array $d) { foreach ($d as $k => &$row) $row['field_name'] = $k; unset($row); return $d; }

    function mod(array $dict, array $subSettings = [], array $settings = []) {
        $GLOBALS['__TEST_USER'] = 'nurse';
        $forms = [];
        foreach ($dict as $m) $forms[$m['form_name']] = '1';
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->subSettings = $subSettings;
        $m->projectSettings = ['log-values' => 'raw'] + $settings;
        $m->projectIdReturn = PID;
        \REDCap::$dictionary = \lock_return_targets($dict);
        \REDCap::$data = ['1' => [1 => ['record_id' => '1']]];
        \REDCap::$rights = ['nurse' => ['forms' => $forms, 'group_id' => null]];
        return $m;
    }
    function rules($m, $fields = null) {
        $call = \Closure::bind(function ($pid, $fields) { return $this->getRules($pid, $fields); }, $m, get_class($m));
        return $call(PID, $fields);
    }
    /** The rules naming $field, each reduced to that one field: what a lookup for it reads. */
    function touching(array $rules, $field) {
        $out = [];
        foreach ($rules as $r) {
            if (empty($r['fields']) || !is_array($r['fields']) || !in_array($field, $r['fields'], true)) continue;
            $r['fields'] = [$field];
            $out[] = $r;
        }
        return $out;
    }
    /** Every field of $dict: the build for it alone agrees with the full build. */
    function agree($label, array $dict, array $subSettings = []) {
        $full = rules(mod($dict, $subSettings));
        $bad = [];
        $smaller = 0;
        foreach (array_keys($dict) as $field) {
            $one = rules(mod($dict, $subSettings), [$field]);
            if (touching($one, $field) !== touching($full, $field)) $bad[] = $field;
            if (count($one) < count($full)) $smaller++;
        }
        check($label . ': every field\'s rules match the full build (differ: ' . implode(', ', $bad) . ')', !$bad);
        return [$full, $smaller];
    }

    // ---- 1) sharing, merging, returns, clashes and a settings rule ----------
    $SEL = '1, One | 2, Two';
    $dict = named([
        'record_id' => f('form_a'),
        'sex'       => f('form_a', '', 'radio', '', '1, Male | 2, Female'),
        'dob'       => f('form_a', '', 'text', 'date_ymd'),
        'visit'     => f('form_a', '', 'text', 'date_ymd'),
        'sel'       => f('form_a', '', 'radio', '', $SEL),
        // identical fragments on three fields (two forms) merge into one rule
        'uid1'      => f('form_a', '@UVUNIQUE'),
        'uid2'      => f('form_a', '@UVUNIQUE'),
        // two gated tags on one field become a branch rule
        'shared'    => f('form_a', '@UVUNIQUE={"when":"[sel]=\'1\'"} @UVUNIQUE={"when":"[sel]=\'2\'","scope":"dag"}'),
        // a settings rule claims uid3 and s_two; s_two has its own tag too, so
        // the full build moves s_two out of it and the build for uid3 does not
        'uid3'      => f('form_a'),
        's_two'     => f('form_b', '@UVUNIQUE={"when":"[sel]=\'1\'"}'),
        'spec'      => f('form_a', '@UVEXISTS={"in":"[ref_id]","return":{"record":"[src_rec]"}}'),
        'src_rec'   => f('form_a'),
        'wt'        => f('form_a', '@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit]"},"soft":[-2,2],"hard":[-5,5],"return":{"z":"[wfa_z]"}}', 'text', 'number_1dp'),
        'wfa_z'     => f('form_a'),
        'bad1'      => f('form_a', '@UVUNIQUE=bogus'),
        'ref_id'    => f('form_b'),
        'b_uid'     => f('form_b', '@UVUNIQUE'),
        // returns to src_rec as spec does: both are refused (a target is on the tag's form)
        'clash1'    => f('form_a', '@UVEXISTS={"in":"[ref_id]","message":"one","return":{"record":"[src_rec]"}}'),
        // "return" spelled with a JSON escape
        'esc'       => f('form_a', '@UVEXISTS={"in":"[ref_id]","message":"two","\u0072eturn":{"record":"[src_rec]"}}'),
        // a returning rule that reads a returned field is refused
        'b_x'       => f('form_b', '@UVEXISTS={"in":"[wfa_z]","return":{"record":"[b_out]"}}'),
        'b_out'     => f('form_b'),
        'b_note'    => f('form_b', '@UVASSERT={"assert":"[b_note]<>\'x\'","when":"[sel]=\'2\'"}'),
    ]);
    $row = function ($type, array $fields) {
        return ['rule-type' => $type, 'fields' => $fields, 'fields-csv' => '', 'when' => '', 'assert' => '', 'message' => '',
                'unique-with' => '', 'unique-scope' => '', 'unique-surveys' => '', 'algorithm' => '', 'source' => '',
                'suggest-fix' => '', 'pattern' => '', 'strip' => '', 'keep-chars' => '', 'id-lengths' => '',
                'id-min-len' => '', 'id-max-len' => '', 'expected-count' => '', 'block-save' => 'off'];
    };
    [$full] = agree('fixture project', $dict, [$row('unique', ['uid3', 's_two'])]);

    // The fixture holds what it says it holds, so the agreement above means something.
    $byField = function ($f) use ($full) { return touching($full, $f); };
    $merged = array_values(array_filter($full, function ($r) { return isset($r['fields']) && in_array('uid1', $r['fields'], true); }));
    check('fixture: identical @UVUNIQUE tags merge into one rule (uid1, uid2, b_uid)',
        count($merged) === 1 && $merged[0]['fields'] === ['uid1', 'uid2', 'b_uid']);
    check('fixture: two gated tags on "shared" become a branch rule', count($byField('shared')) === 1 && !empty($byField('shared')[0]['branches']));
    check('fixture: the settings rule and the tag on s_two become a branch rule',
        count($byField('s_two')) === 1 && !empty($byField('s_two')[0]['branches']));
    $err = function ($f) use ($byField) { $r = $byField($f); return isset($r[0]['configError']) ? $r[0]['configError'] : ''; };
    check('fixture: spec, clash1 and esc share a return target and are refused',
        strpos($err('spec'), 'src_rec') !== false && strpos($err('clash1'), 'src_rec') !== false && strpos($err('esc'), 'src_rec') !== false);
    check('fixture: b_x reads a returned field and is refused', strpos($err('b_x'), 'wfa_z') !== false);
    check('fixture: a bad tag is a configuration error', strpos($err('bad1'), 'bogus') !== false);

    check('scope: a field on no rule gets none', touching(rules(mod($dict), ['record_id']), 'record_id') === []);

    // ---- 2) a larger project of every tag, cross-form references ------------
    $big = ['record_id' => f('form_01'), 'sex' => f('form_01', '', 'radio', '', '1, Male | 2, Female'),
            'dob' => f('form_01', '', 'text', 'date_ymd'), 'ref_id' => f('form_01')];
    $k = 0;
    for ($fi = 1; $fi <= 6; $fi++) {
        $form = sprintf('form_%02d', $fi); $p = sprintf('f%02d', $fi);
        $big[$p . '_sel'] = f($form, '', 'radio', '', '1, A | 2, B | 3, C');
        $big[$p . '_num0'] = f($form, '', 'text', 'number');
        $big[$p . '_d0'] = f($form, '', 'text', 'date_ymd');
        $big[$p . '_vd'] = f($form, '', 'text', 'date_ymd');
        $big[$p . '_ret'] = f($form);
        for ($i = 0; $i < 12; $i++, $k++) {
            $name = sprintf('%s_x%02d', $p, $i);
            $other = sprintf('f%02d_num0', ($fi % 6) + 1);
            switch ($k % 9) {
                case 0: $big[$name] = f($form, '@UVALIDATE=luhn'); break;
                case 1: $big[$name] = f($form, '@UVASSERT={"assert":"[' . $name . ']>=[' . $other . ']","when":"[' . $p . '_sel]=\'1\'"}', 'text', 'number'); break;
                case 2: $big[$name] = f($form, '@UVREQUIRED="[' . $p . '_sel]=\'2\'"'); break;
                case 3: $big[$name] = f($form, '@UVUNIQUE'); break;
                case 4: $big[$name] = f($form, '@UVCHOICES={"when":"[' . $p . '_sel]=\'1\'","show":["1","2"]}', 'radio', '', '1, A | 2, B | 3, C'); break;
                case 5: $big[$name] = f($form, '@UVWINDOW={"from":"[' . $p . '_d0]","window":[0,30]}', 'text', 'date_ymd'); break;
                case 6: $big[$name] = f($form, '@UVEXISTS={"in":"[ref_id]"}'); break;
                case 7: $big[$name] = f($form, '@UVRANGE={"hard":[0,300],"soft":[10,200]}', 'text', 'number'); break;
                case 8: $big[$name] = f($form, '@UVEXISTS={"in":"[ref_id]","return":{"record":"[' . $p . '_ret]"}}'); break;
            }
        }
    }
    $big = named($big);
    [$bigFull, $smaller] = agree('72-tag project', $big);
    check('72-tag project: a lookup builds fewer rules than the page (' . $smaller . ' of ' . count($big) . ' fields)',
        $smaller === count($big));

    // ---- 3) the page builds every rule; the lookups build their field's -----
    $m = mod($big);
    ob_start(); $m->redcap_data_entry_form_top(PID, '1', 'form_01', 1, null, 1); ob_get_clean();
    $memo = \Closure::bind(function () { return array_keys($this->rulesMemo); }, $m, get_class($m));
    check('page: the rule list is the full one', $memo() === [PID . '|qualified=0']);
    foreach (['unique-check' => 'f01_x03', 'exists-check' => 'f01_x06'] as $action => $field) {
        $mm = mod($big);
        $mm->redcap_module_ajax($action, ['field' => $field, 'values' => [$field => 'A-1']],
            PID, '1', 'form_01', 1, 1, null, null, null, '', '', 'nurse', null);
        $keys = \Closure::bind(function () { return array_keys($this->rulesMemo); }, $mm, get_class($mm))();
        check($action . ': builds the rules of "' . $field . '" only (memo ' . json_encode($keys) . ')',
            $keys === [PID . '|qualified=0|fields=' . $field]);
    }

    // ---- 4) the event/instance setting is read once per rule build ----------
    EMA::$settingReads = [];
    rules(mod($big));
    $reads = EMA::$settingReads['enable-event-instance-refs'] ?? 0;
    check('setting: one read of enable-event-instance-refs for a 72-tag build (read ' . $reads . ')', $reads === 1);
    // A later build reads the stored value again, so a change between builds counts.
    $m2 = mod($big, [], ['enable-event-instance-refs' => false]);
    rules($m2);
    $m2->projectSettings['enable-event-instance-refs'] = true;
    $key2 = \Closure::bind(function () { return array_keys($this->rulesMemo); }, $m2, get_class($m2));
    rules($m2);
    check('setting: a build after the setting changed reads the new value', in_array(PID . '|qualified=1', $key2(), true));

    echo "rule_scope_php: $n checks, $fail failure(s)\n";
    exit($fail === 0 ? 0 : 1);
}
