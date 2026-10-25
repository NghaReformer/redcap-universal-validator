<?php
/**
 * delta_module_php.php — @UVDELTA through the module.
 *
 * delta_php.php pins the verdict and the grammar; this file pins the REDCap
 * glue around them:
 *   - the field hook: a Text field must take numbers (integer or number
 *     validation in any form, or none), a calc and a slider always do; a
 *     comma-decimal field and a calc are marked, a shorthand is written out
 *     for this field, a plain "vs" naming the field itself is refused, and
 *     the limits travel as a display text with the field's own decimal mark,
 *   - the dictionary hook: the "vs" field must exist and hold numbers, its
 *     decimal comma travels, and a shorthand must have an earlier entry to
 *     name (a form that repeats, a form on two or more events, and no event
 *     shorthand on a form that repeats on its own),
 *   - the page: a "vs" on this page is a live ref, one on another form a
 *     snapshot, one the viewer may not read is withheld, and an
 *     event/instance rule is advisory,
 *   - the post-save audit logs up / down as type 'delta', checks nothing
 *     against a blank earlier value or a Missing Data Code, and reports a
 *     value or an earlier value that is not a number as a rule problem,
 *   - the extended path: the previous event, the first event, a named event
 *     and the previous instance, read from saved data; the first visit and
 *     an event with nothing saved compare with nothing,
 *   - the scan finds the same, labels each direction, and writes the
 *     detail line.
 *
 * Run:  php tests/delta_module_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        /** The legacy one-request scan (tests/support/scan_facade.php, QUA-3). */
        public function scanProject(...$a) { return \INSPIRE\UniversalValidator\scan_project($this, ...$a); }
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
        public static $mappings = null;
        public static function getInstrumentEventMappings($pid = null) { return self::$mappings; }
        public static function getData($p) {
            $data = self::$data;
            if (isset($p['records'])) $data = array_intersect_key($data, array_fill_keys(array_map('strval', (array) $p['records']), true));
            return $data;
        }
        public static function getDataDictionary($pid, $f = 'array') {
            if (!$pid) throw new \RuntimeException('needs pid');
            return self::$dictionary;
        }
        public static function getUserRights($pid = null, $user = null) { return self::$rights; }
        public static function getGroupNames($u = false, $g = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
    }
    /** The project's design, as REDCap's \Project offers it: set the statics before each mod(). */
    class Project {
        public static $codes = '';
        public static $isLongitudinal = false;
        public static $eventInfoAll = [];
        public static $eventsFormsAll = [];
        public static $repeats = [];
        public $project;
        public $longitudinal;
        public $eventInfo;
        public $eventsForms;
        public function __construct($pid) {
            $this->project = ['missing_data_codes' => self::$codes];
            $this->longitudinal = self::$isLongitudinal;
            $this->eventInfo = self::$eventInfoAll;
            $this->eventsForms = self::$eventsFormsAll;
        }
        public function getRepeatingFormsEvents() { return self::$repeats; }
        public function getUniqueEventNames() {
            $out = [];
            foreach (self::$eventInfoAll as $id => $row) $out[$id] = $row['unique_event_name'];
            return $out;
        }
    }

    require_once __DIR__ . '/../UniversalValidator.php';
    require_once __DIR__ . '/support/scan_facade.php';
    require_once __DIR__ . '/support/ArrayScanStore.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    const PID = 149;
    function f($form, $ann = '', $validation = '', $type = 'text') {
        return ['field_type' => $type, 'form_name' => $form, 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => $validation];
    }
    /** A classic project: one event (351) holding both forms, nothing repeats. */
    function classic() {
        \Project::$isLongitudinal = false;
        \Project::$eventInfoAll = [351 => ['unique_event_name' => 'event_1_arm_1', 'arm_num' => 1]];
        \Project::$eventsFormsAll = [351 => ['enrol_form', 'visit_form']];
        \Project::$repeats = [];
        \REDCap::$mappings = null;
    }
    /** A longitudinal project: enrolment (1), then three visits (2, 3, 4) of visit_form, in one arm. */
    function longitudinal(array $repeats = []) {
        \Project::$isLongitudinal = true;
        \Project::$eventInfoAll = [1 => ['unique_event_name' => 'enrol_arm_1', 'arm_num' => 1],
            2 => ['unique_event_name' => 'visit1_arm_1', 'arm_num' => 1], 3 => ['unique_event_name' => 'visit2_arm_1', 'arm_num' => 1],
            4 => ['unique_event_name' => 'visit3_arm_1', 'arm_num' => 1]];
        \Project::$eventsFormsAll = [1 => ['enrol_form'], 2 => ['visit_form'], 3 => ['visit_form'], 4 => ['visit_form']];
        \Project::$repeats = $repeats;
        \REDCap::$mappings = [['event_id' => 1, 'form' => 'enrol_form'], ['event_id' => 2, 'form' => 'visit_form'],
            ['event_id' => 3, 'form' => 'visit_form'], ['event_id' => 4, 'form' => 'visit_form']];
    }
    $FULL = ['nurse' => ['forms' => ['enrol_form' => '1', 'visit_form' => '1']]];
    $PART = ['nurse' => ['forms' => ['enrol_form' => '0', 'visit_form' => '1']]];

    function mod($dict, $data, $rights, array $settings = []) {
        $GLOBALS['__TEST_USER'] = 'nurse';
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectSettings = array_merge(['log-values' => 'raw'], $settings);
        $m->projectIdReturn = PID;
        \REDCap::$dictionary = $dict;
        \REDCap::$data = $data;
        \REDCap::$rights = $rights;
        return $m;
    }
    function page($m, $ctx, $rec, $form, $evt = 351, $inst = 1) {
        ob_start();
        if ($ctx === 'survey') $m->redcap_survey_page_top(PID, $rec, $form, $evt, null, 'hash', null, $inst);
        else $m->redcap_data_entry_form_top(PID, $rec, $form, $evt, null, $inst);
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
    /**
     * The entries of one audit of $type. A save also logs each refused rule
     * with fields on the form that the module log does not hold yet (VAL-15,
     * PRF-9, tests/hook_php.php; this stub has no queryLogs, so every save
     * logs them); those "configuration error:" entries are left out here, so
     * a rule problem means one the save itself met.
     */
    function findings($m, $type = 'invalid-id-saved') {
        return array_values(array_map(function ($c) { return $c[1]; },
            array_filter($m->logCalls, function ($c) use ($type) {
                return $c[0] === $type && !($type === 'uvalidate-unconfigurable'
                    && strpos((string) ($c[1]['why'] ?? ''), 'configuration error:') === 0);
            })));
    }
    /**
     * delta findings of one audit, field => finding; with $evt (and $inst),
     * only those of that entry: a save also re-checks the entries that
     * compare with the one saved.
     */
    function deltaBy($m, $evt = null, $inst = null) {
        $by = [];
        foreach (findings($m) as $e) {
            if (($e['type'] ?? '') !== 'delta') continue;
            if ($evt !== null && $e['event_id'] !== (string) $evt) continue;
            if ($inst !== null && $e['instance'] !== (string) $inst) continue;
            $by[$e['field']] = $e;
        }
        return $by;
    }
    /** the rule problems of one audit naming $field, as their "why" texts */
    function problems($m, $field) {
        $out = [];
        foreach (findings($m, 'uvalidate-unconfigurable') as $e) {
            if (strpos((string) $e['fields'], $field) !== false) $out[] = (string) $e['why'];
        }
        return $out;
    }

    // ============================================================================
    // A classic project: the "vs" is another field of the same record.
    // ============================================================================
    classic();
    $DICT = [
        'record_id'   => f('enrol_form'),
        'sex'         => f('enrol_form', '', '', 'radio'),
        'dob'         => f('enrol_form', '', 'date_ymd'),
        'w_screen'    => f('enrol_form', '', 'number_1dp'),
        'h_screen'    => f('enrol_form', '', 'integer'),
        'w_src_comma' => f('enrol_form', '', 'number_1dp_comma_decimal'),
        'w_free'      => f('enrol_form', '', ''),
        'w_prev'      => f('visit_form', '', 'number'),
        'weight'      => f('visit_form', '@UVDELTA={"vs":"[w_screen]","max":5,"maxPct":10,"unit":"kg","blockSave":"hard"}', 'number_1dp'),
        'w_same'      => f('visit_form', '@UVDELTA={"vs":"[w_prev]","max":2,"blockSave":"confirm"}', 'number'),
        'w_comma'     => f('visit_form', '@UVDELTA={"vs":"[w_src_comma]","max":2.5}', 'number_1dp_comma_decimal'),
        'w_vs_comma'  => f('visit_form', '@UVDELTA={"vs":"[w_src_comma]","max":1}', 'number'),
        'bmi'         => f('visit_form', '@UVDELTA={"vs":"[w_screen]","max":3}', '', 'calc'),
        'pain'        => f('visit_form', '@UVDELTA={"vs":"[h_screen]","maxPct":50,"direction":"up"}', 'number', 'slider'),
        'height'      => f('visit_form', '@UVDELTA={"vs":"[h_screen]","max":0,"direction":"down","unit":"cm"}', 'integer'),
        'free'        => f('visit_form', '@UVDELTA={"vs":"[w_screen]","max":1}', ''),
        'w_vs_free'   => f('visit_form', '@UVDELTA={"vs":"[w_free]","max":1}', 'number'),
        'msg_w'       => f('visit_form', '@UVDELTA={"vs":"[w_screen]","max":1,"message":"Re-weigh."}', 'number'),
        'bad_email'   => f('visit_form', '@UVDELTA={"vs":"[w_screen]","max":1}', 'email'),
        'bad_date'    => f('visit_form', '@UVDELTA={"vs":"[w_screen]","max":1}', 'date_ymd'),
        'bad_radio'   => f('visit_form', '@UVDELTA={"vs":"[w_screen]","max":1}', '', 'radio'),
        'bad_notes'   => f('visit_form', '@UVDELTA={"vs":"[w_screen]","max":1}', '', 'notes'),
        'bad_self'    => f('visit_form', '@UVDELTA={"vs":"[bad_self]","max":1}', 'number'),
        'bad_nofield' => f('visit_form', '@UVDELTA={"vs":"[nope]","max":1}', 'number'),
        'bad_vsradio' => f('visit_form', '@UVDELTA={"vs":"[sex]","max":1}', 'number'),
        'bad_vsdate'  => f('visit_form', '@UVDELTA={"vs":"[dob]","max":1}', 'number'),
        'bad_short'   => f('visit_form', '@UVDELTA={"vs":"previous-event","max":1}', 'number'),
    ];
    $ROW = [
        'record_id' => '2', 'sex' => '1', 'dob' => '2000-01-01', 'w_screen' => '70.0', 'h_screen' => '150',
        'w_src_comma' => '70,0', 'w_free' => 'n/a', 'w_prev' => '60',
        'weight' => '76.5', 'w_same' => '63', 'w_comma' => '72,6', 'w_vs_comma' => '71.5', 'bmi' => '74', 'pain' => '230',
        'height' => '149', 'free' => 'n/a', 'w_vs_free' => '5', 'msg_w' => '72',
        'bad_email' => 'x@y', 'bad_date' => '2026-01-01', 'bad_radio' => '5', 'bad_notes' => '5', 'bad_self' => '5',
        'bad_nofield' => '5', 'bad_vsradio' => '5', 'bad_vsdate' => '5', 'bad_short' => '5',
    ];
    $DATA = ['2' => [351 => $ROW]];

    // ---- 1) the field hook ------------------------------------------------------
    $p = page(mod($DICT, $DATA, $FULL), 'form', '2', 'visit_form');
    $err = function ($field) use ($p) { $r = ruleFor($p, $field); return $r && isset($r['configError']) ? $r['configError'] : ''; };
    check('email validation: refused, names it (got ' . $err('bad_email') . ')', strpos($err('bad_email'), 'needs a number field') !== false
        && strpos($err('bad_email'), '(it has "email")') !== false);
    check('date validation: refused', strpos($err('bad_date'), '(it has "date_ymd")') !== false);
    check('radio: refused by eligibility', strpos($err('bad_radio'), 'does not support "radio" fields') !== false);
    check('notes: refused by eligibility', strpos($err('bad_notes'), 'does not support "notes" fields') !== false);
    check('"vs" naming the field itself on a classic project, where it has no other entry: refused, suggesting none (got ' . $err('bad_self') . ')',
        $err('bad_self') === '@UVDELTA on "bad_self": "vs" names this field itself — compare it with another entry.');
    check('every refusal names @UVDELTA', strpos($err('bad_email'), '@UVDELTA on "bad_email"') === 0
        && strpos($err('bad_self'), '@UVDELTA on "bad_self"') === 0);
    check('a shorthand without event and instance references: refused (got ' . $err('bad_short') . ')',
        strpos($err('bad_short'), 'needs event and instance references') !== false);

    // ---- 2) the dictionary hook -----------------------------------------------------
    check('"vs" not a field: refused (got ' . $err('bad_nofield') . ')', strpos($err('bad_nofield'), '"[nope]", which is not a field in this project') !== false);
    check('"vs" a radio: refused', strpos($err('bad_vsradio'), '"vs" field "sex" is not a number field') !== false);
    check('"vs" a date: refused', strpos($err('bad_vsdate'), '"vs" field "dob" is not a number field') !== false);
    foreach (['weight', 'w_same', 'w_comma', 'w_vs_comma', 'bmi', 'pain', 'height', 'free', 'w_vs_free', 'msg_w'] as $ok) {
        check($ok . ': no configuration error (got ' . $err($ok) . ')', ruleFor($p, $ok) !== null && $err($ok) === '');
    }

    $r = ruleFor($p, 'weight');
    check('limits, unit and texts travel', $r && $r['type'] === 'delta' && $r['deltaMax'] === '5' && $r['deltaMaxPct'] === '10'
        && $r['deltaUnit'] === 'kg' && $r['deltaText'] === 'a change of at most 5 kg and 10%' && $r['deltaVsLabel'] === '[w_screen]'
        && $r['deltaGroup'] === '[w_screen] both' && empty($r['decimalComma']) && empty($r['deltaComputed']) && empty($r['deltaVsComma']));
    $r = ruleFor($p, 'w_comma');
    check('comma field: marked, its vs too, the text with a comma', $r && $r['decimalComma'] === true && $r['deltaVsComma'] === true
        && $r['deltaText'] === 'a change of at most 2,5' && $r['deltaMax'] === '2.5');
    $r = ruleFor($p, 'w_vs_comma');
    check('a point field compared with a comma field: the vs alone marked', $r && empty($r['decimalComma']) && $r['deltaVsComma'] === true);
    check('calc: marked so it never blocks', ruleFor($p, 'bmi')['deltaComputed'] === true);
    check('slider: accepted, not computed, "rise" in the text', empty(ruleFor($p, 'pain')['deltaComputed'])
        && ruleFor($p, 'pain')['deltaText'] === 'a rise of at most 50%' && ruleFor($p, 'pain')['deltaDirection'] === 'up');
    check('a limit of 0: "no fall"', ruleFor($p, 'height')['deltaText'] === 'no fall');
    check('message travels', ruleFor($p, 'msg_w')['message'] === 'Re-weigh.');

    // One "vs", two directions: both tags judge (the heart-rate recipe, review D4).
    $HR = ['record_id' => f('enrol_form'), 'hr0' => f('enrol_form', '', 'integer'),
        'hr' => f('visit_form', '@UVDELTA={"vs":"[hr0]","maxPct":10,"direction":"down"} @UVDELTA={"vs":"[hr0]","maxPct":20,"direction":"up"}', 'integer')];
    foreach (['85' => 'down', '115' => null, '125' => 'up', '92' => null] as $hv => $want) {
        $m = mod($HR, ['2' => [351 => ['record_id' => '2', 'hr0' => '100', 'hr' => (string) $hv]]], $FULL);
        $m->redcap_save_record(PID, '2', 'visit_form', 351, null, null, null, 1);
        check('heart rate ' . $hv . ' from 100 (fall at most 10%, rise at most 20%): ' . json_encode($want) . ' (got ' . json_encode(deltaBy($m)) . ')',
            (deltaBy($m)['hr']['reason'] ?? null) === $want && !problems($m, 'hr'));
    }
    $hp = page(mod($HR, ['2' => [351 => ['record_id' => '2', 'hr0' => '100']]], $FULL), 'form', '2', 'visit_form');
    $hr = ruleFor($hp, 'hr');
    check('... the page gets both, one group each', $hr && isset($hr['branches']) && count($hr['branches']) === 2
        && array_column($hr['branches'], 'deltaGroup') === ['[hr0] down', '[hr0] up']);

    // Long numbers pay the record's evaluation budget; a spent budget is a rule problem.
    $fdx = new \ReflectionMethod(\INSPIRE\UniversalValidator\UniversalValidator::class, 'findingsDelta');
    $fdx->setAccessible(true);
    $long = '1' . str_repeat('0', 3000);
    $mb = mod($DICT, $DATA, $FULL);
    $bp = new \ReflectionProperty(\INSPIRE\UniversalValidator\UniversalValidator::class, 'temporalBudget');
    $bp->setAccessible(true);
    $bp->setValue($mb, new \INSPIRE\UniversalValidator\ReferenceBudget(10));
    $res = $fdx->invoke($mb, ['type' => 'delta', 'fields' => ['weight'], 'deltaVs' => '[w_screen]', 'deltaMax' => '1'],
        'delta', ['weight' => $long, 'w_screen' => $long], [], null, PID, '2', 351, []);
    check('a spent budget: the change is not worked out, and says so (got ' . json_encode($res['unconfigurable']) . ')', !$res['invalid']
        && ($res['unconfigurable'][0]['why'] ?? '') === 'the evaluation budget for this record is spent, so the change was not worked out — field skipped');
    $res = $fdx->invoke(mod($DICT, $DATA, $FULL), ['type' => 'delta', 'fields' => ['weight'], 'deltaVs' => '[w_screen]', 'deltaMax' => '1'],
        'delta', ['weight' => $long . '0', 'w_screen' => '1'], [], null, PID, '2', 351, []);
    check('a budget left: a long change is judged', ($res['invalid'][0]['reason'] ?? null) === 'up');
    // One budget per save: what the last record spent is not this one's (round 2).
    $LONGDATA = $DATA;
    $LONGDATA['2'][351]['weight'] = $long . '0';
    $LONGDATA['2'][351]['w_screen'] = $long;
    $mb = mod($DICT, $LONGDATA, $FULL);
    $bp->setValue($mb, new \INSPIRE\UniversalValidator\ReferenceBudget(10));
    $mb->redcap_save_record(PID, '2', 'visit_form', 351, null, null, null, 1);
    check('a save starts its own budget: the record before spent it, this one is judged (got ' . json_encode(problems($mb, 'weight')) . ')',
        (deltaBy($mb)['weight']['reason'] ?? null) === 'up' && !problems($mb, 'weight'));
    $res = $fdx->invoke(mod($DICT, $DATA, $FULL), ['type' => 'delta', 'fields' => ['weight'], 'deltaVs' => '[w_screen]', 'deltaMax' => '1'],
        'delta', ['weight' => '1' . str_repeat('0', 4096), 'w_screen' => '1'], [], null, PID, '2', 351, []);
    check('a value too long to compare: a rule problem that says so (got ' . json_encode($res['unconfigurable']) . ')',
        ($res['unconfigurable'][0]['why'] ?? '') === 'the value and its "vs" value are too long to compare — field skipped');

    // ---- 3) the page: live, snapshot, withheld --------------------------------------
    $r = ruleFor($p, 'w_same');
    check('a vs on this page: a live ref, no snapshot, its own blockSave (got ' . json_encode($r['deltaVsOp'] ?? null) . ')',
        $r && ($r['deltaVsOp'] ?? null) === ['ref', 'w_prev', null] && empty($r['snapshotFields']) && $r['blockSave'] === 'confirm');
    $r = ruleFor($p, 'weight');
    check('a vs on another form: its saved value as a snapshot (got ' . json_encode([$r['deltaVsOp'] ?? null, $r['snapshotFields'] ?? null]) . ')',
        $r && ($r['deltaVsOp'] ?? null) === ['lit', '70.0'] && in_array('w_screen', $r['snapshotFields'] ?? [], true));
    $pp = page(mod($DICT, $DATA, $PART), 'form', '2', 'visit_form');
    $r = ruleFor($pp, 'weight');
    check('a vs on a form this viewer may not read: withheld, its value not sent (got ' . json_encode($r['deltaVsOp'] ?? null) . ')',
        $r && ($r['deltaVsOp'] ?? null) === ['withheld'] && strpos($pp['html'], '70.0') === false);
    $ps = page(mod($DICT, $DATA, $FULL), 'survey', '2', 'visit_form');
    $r = ruleFor($ps, 'weight');
    check('a survey: a vs on another form is withheld (got ' . json_encode($r['deltaVsOp'] ?? null) . ')',
        $r && ($r['deltaVsOp'] ?? null) === ['withheld']);
    check('a survey: a vs on the page stays live', (ruleFor($ps, 'w_same')['deltaVsOp'] ?? null) === ['ref', 'w_prev', null]);

    // ---- 4) the post-save audit -------------------------------------------------------
    $m = mod($DICT, $DATA, $FULL);
    $m->redcap_save_record(PID, '2', 'visit_form', 351, null, null, null, 1);
    $by = deltaBy($m);
    check('a rise past max: up (got ' . json_encode($by['weight'] ?? null) . ')', isset($by['weight']) && $by['weight']['reason'] === 'up'
        && $by['weight']['value'] === '76.5' && $by['weight']['algorithm'] === 'delta' && $by['weight']['vs'] === '[w_screen]');
    check('a vs on the page: up', ($by['w_same']['reason'] ?? null) === 'up');
    check('comma field against a comma field: 72,6 is 2.6 above 70,0', ($by['w_comma']['reason'] ?? null) === 'up');
    check('a point field against a comma field', ($by['w_vs_comma']['reason'] ?? null) === 'up');
    check('calc: up', ($by['bmi']['reason'] ?? null) === 'up');
    check('slider, direction up: 230 is 53% above 150', ($by['pain']['reason'] ?? null) === 'up');
    check('max 0 down: a fall of 1', ($by['height']['reason'] ?? null) === 'down');
    check('message rule: up', ($by['msg_w']['reason'] ?? null) === 'up');
    check('a value that is not a number: a rule problem, not a finding', !isset($by['free'])
        && (bool) array_filter(problems($m, 'free'), function ($w) { return strpos($w, 'the saved value is not a number') !== false; }));
    check('an earlier value that is not a number: a rule problem naming the vs', !isset($by['w_vs_free'])
        && (bool) array_filter(problems($m, 'w_vs_free'), function ($w) { return strpos($w, 'the "vs" value [w_free] is not a number') !== false; }));
    check('misconfigured rules log nothing as data', !array_intersect(array_keys($by),
        ['bad_email', 'bad_date', 'bad_radio', 'bad_notes', 'bad_self', 'bad_nofield', 'bad_vsradio', 'bad_vsdate', 'bad_short']));

    $ok = $DATA;
    $ok['2'][351] = array_merge($ok['2'][351], ['weight' => '75', 'w_same' => '58', 'w_comma' => '72,5', 'w_vs_comma' => '71',
        'bmi' => '67', 'pain' => '20', 'height' => '190', 'free' => '70.5', 'w_vs_free' => '', 'msg_w' => '69']);
    $m = mod($DICT, $ok, $FULL);
    $m->redcap_save_record(PID, '2', 'visit_form', 351, null, null, null, 1);
    check('changes within the limits, or the other way, log nothing (got ' . json_encode(array_keys(deltaBy($m))) . ')', !deltaBy($m));
    check('... and no rule problem for them', !problems($m, 'weight') && !problems($m, 'free'));

    $blank = $DATA;
    foreach (['w_screen', 'h_screen', 'w_src_comma', 'w_prev', 'w_free'] as $k) $blank['2'][351][$k] = '';
    $m = mod($DICT, $blank, $FULL);
    $m->redcap_save_record(PID, '2', 'visit_form', 351, null, null, null, 1);
    check('a blank earlier value checks nothing', !deltaBy($m) && !problems($m, 'weight') && !problems($m, 'w_vs_free'));

    \Project::$codes = "UNK, Unknown\nNASK, Not asked";
    $mdc = $DATA;
    $mdc['2'][351]['w_screen'] = 'UNK';
    $mdc['2'][351]['w_same'] = 'NASK';
    $m = mod($DICT, $mdc, $FULL);
    $m->redcap_save_record(PID, '2', 'visit_form', 351, null, null, null, 1);
    $by = deltaBy($m);
    check('a Missing Data Code as the earlier value: nothing checked, no rule problem',
        !isset($by['weight']) && !isset($by['bmi']) && !isset($by['msg_w']) && !problems($m, 'weight'));
    check('a Missing Data Code as the value: nothing checked', !isset($by['w_same']) && !problems($m, 'w_same'));
    check('other fields still checked', ($by['height']['reason'] ?? null) === 'down');
    \Project::$codes = '';

    // Saving the form that holds the vs re-checks the rule that reads it.
    $m = mod($DICT, $DATA, $FULL);
    $m->redcap_save_record(PID, '2', 'enrol_form', 351, null, null, null, 1);
    $by = deltaBy($m);
    check('a save of the vs form re-checks the rules that read it (got ' . json_encode(array_keys($by)) . ')',
        ($by['weight']['reason'] ?? null) === 'up' && ($by['height']['reason'] ?? null) === 'down');
    check('... but not one whose vs is on the form not saved', !isset($by['w_same']));

    // A "when" gates the audit as it gates the page.
    $DW = $DICT + ['grp' => f('visit_form'),
        'w_when' => f('visit_form', '@UVDELTA={"vs":"[w_screen]","max":1,"when":"[grp]=\'a\' OR [grp]=\'b\'"}', 'number')];
    foreach ([['a', true], ['B', true], ['c', false], ['', false]] as $case) {
        $V = $DATA; $V['2'][351] += ['grp' => $case[0], 'w_when' => '90'];
        $m = mod($DW, $V, $FULL);
        $m->redcap_save_record(PID, '2', 'visit_form', 351, null, null, null, 1);
        check('audit: "when" with grp=' . json_encode($case[0]) . ($case[1] ? ' applies' : ' does not apply'), isset(deltaBy($m)['w_when']) === $case[1]);
    }

    // Two tags on one field with different vs: each judges, a failure of either is logged once.
    $DT = $DICT + ['w_two' => f('visit_form', '@UVDELTA={"vs":"[w_prev]","maxPct":5} @UVDELTA={"vs":"[w_screen]","maxPct":15}', 'number')];
    $r = ruleFor(page(mod($DT, $DATA, $FULL), 'form', '2', 'visit_form'), 'w_two');
    check('two tags with different vs: one rule, two groups, no conflict', $r && count($r['branches'] ?? []) === 2 && empty($r['configError']));
    foreach ([['62', []], ['64', ['[w_prev]']], ['82', ['[w_prev]', '[w_screen]']], ['80', ['[w_prev]']]] as $case) {
        // 62: 3.3% from 60 and 11.4% from 70 (both within); 64: 6.7% from 60; 82: 36.7% and 17.1%; 80: 33.3% and 14.3%
        $V = $DATA; $V['2'][351]['w_two'] = $case[0];
        $m = mod($DT, $V, $FULL);
        $m->redcap_save_record(PID, '2', 'visit_form', 351, null, null, null, 1);
        $vs = array_map(function ($e) { return $e['vs'] ?? null; },
            array_values(array_filter(findings($m), function ($e) { return ($e['field'] ?? '') === 'w_two'; })));
        sort($vs);
        check('two tags, w_two=' . $case[0] . ': one finding per comparison failed, each naming its vs (got ' . json_encode($vs) . ')',
            $vs === $case[1]);
    }

    // ---- 5) the scan (classic) ---------------------------------------------------------
    $m = mod($DICT, $DATA, $FULL);
    $res = $m->scanProject(PID);
    $by = [];
    foreach ($res['violations'] as $v) if ($v['type'] === 'delta') $by[$v['field']] = $v;
    check('scan: the same verdicts (got ' . json_encode(array_map(function ($v) { return $v['reason']; }, $by)) . ')',
        ($by['weight']['reason'] ?? null) === 'up' && ($by['height']['reason'] ?? null) === 'down'
        && ($by['w_comma']['reason'] ?? null) === 'up' && ($by['bmi']['reason'] ?? null) === 'up' && !isset($by['free']));
    check('scan: misconfigured rules are rule problems, not data', !isset($by['bad_self'])
        && (bool) array_filter($res['unconfigurable'], function ($u) { return in_array('bad_self', $u['fields'], true); }));
    check('scan: a value that is not a number is a rule problem',
        (bool) array_filter($res['unconfigurable'], function ($u) { return in_array('free', $u['fields'], true); }));

    $dims = $m->scanDimensions(PID, $res['rules'] ?? null);
    $cols = \INSPIRE\UniversalValidator\ScanColumns::all($dims);
    $row = \INSPIRE\UniversalValidator\ScanColumns::row($by['weight'], $dims, $cols);
    check('report: the catalog wording and the limits in the detail (got ' . json_encode($row['problem'] ?? null) . ')',
        ($row['problem'] ?? '') === 'The value rose more than allowed from the value it is compared with. Allowed a change of at most 5 kg and 10% from [w_screen].');
    $row = \INSPIRE\UniversalValidator\ScanColumns::row($by['height'], $dims, $cols);
    check('report: a fall (got ' . json_encode($row['problem'] ?? null) . ')',
        ($row['problem'] ?? '') === 'The value fell more than allowed from the value it is compared with. Allowed no fall from [h_screen].');
    $row = \INSPIRE\UniversalValidator\ScanColumns::row($by['msg_w'], $dims, $cols);
    check('report: a rule message speaks for it (got ' . json_encode($row['problem'] ?? null) . ')',
        ($row['problem'] ?? '') === 'Re-weigh. Allowed a change of at most 1 from [w_screen].');
    check('scan labels per direction', \INSPIRE\UniversalValidator\ModeRegistry::issueLabel('delta', 'up') === 'Rose more than allowed'
        && \INSPIRE\UniversalValidator\ModeRegistry::issueLabel('delta', 'down') === 'Fell more than allowed'
        && \INSPIRE\UniversalValidator\ModeRegistry::issueLabel('delta', 'other') === 'Changed more than allowed');

    // ============================================================================
    // A longitudinal project: the previous event, the first event, a named event.
    // ============================================================================
    longitudinal();
    $REFS = ['enable-event-instance-refs' => true];
    $LD = [
        'record_id' => f('enrol_form'),
        'w_enrol'   => f('enrol_form', '', 'number_1dp'),
        'weight'    => f('visit_form', '@UVDELTA={"vs":"previous-event","maxPct":10,"unit":"kg","blockSave":"hard"}', 'number_1dp'),
        'height'    => f('visit_form', '@UVDELTA={"vs":"first-event","max":0,"direction":"down"}', 'integer'),
        'w_bl'      => f('visit_form', '@UVDELTA={"vs":"[enrol_arm_1][w_enrol]","maxPct":20}', 'number_1dp'),
        'w_last'    => f('visit_form', '@UVDELTA={"vs":"[visit1_arm_1][w_last]","max":1}', 'number'),
        'bad_inst'  => f('visit_form', '@UVDELTA={"vs":"previous-instance","max":1}', 'number'),
        'bad_once'  => f('enrol_form', '@UVDELTA={"vs":"previous-event","max":1}', 'number'),
    ];
    $LDATA = ['2' => [
        1 => ['record_id' => '2', 'w_enrol' => '60.0'],
        2 => ['weight' => '70.0', 'height' => '150', 'w_bl' => '70.0', 'w_last' => '5'],
        3 => ['weight' => '78.0', 'height' => '149', 'w_bl' => '75.0', 'w_last' => '7'],
        4 => ['weight' => '79.0', 'height' => '', 'w_bl' => '', 'w_last' => ''],
    ]];

    $p = page(mod($LD, $LDATA, $FULL, $REFS), 'form', '2', 'visit_form', 3);
    $err = function ($field) use ($p) { $r = ruleFor($p, $field); return $r && isset($r['configError']) ? $r['configError'] : ''; };
    foreach (['weight', 'height', 'w_bl', 'w_last'] as $ok) {
        check('longitudinal ' . $ok . ': no configuration error (got ' . $err($ok) . ')', ruleFor($p, $ok) !== null && $err($ok) === '');
    }
    check('each shorthand has its own name for the message (got ' . json_encode([ruleFor($p, 'weight')['deltaVsLabel'] ?? null,
        ruleFor($p, 'height')['deltaVsLabel'] ?? null]) . ')',
        (ruleFor($p, 'weight')['deltaVsLabel'] ?? null) === 'the previous event' && (ruleFor($p, 'height')['deltaVsLabel'] ?? null) === 'the first event');
    check('an instance shorthand on a form that does not repeat: refused (got ' . $err('bad_inst') . ')',
        strpos($err('bad_inst'), '"vs": "previous-instance" needs a form that repeats') !== false);
    $pe = page(mod($LD, $LDATA, $FULL, $REFS), 'form', '2', 'enrol_form', 1);
    $e = ruleFor($pe, 'bad_once')['configError'] ?? '';
    check('an event shorthand on a form on one event: refused (got ' . $e . ')',
        strpos($e, '"vs": "previous-event" needs the form on two or more events') !== false && strpos($e, 'is on one event') !== false);

    $rw = function ($m) {
        $g = new \ReflectionMethod($m, 'getRules');
        $g->setAccessible(true);
        foreach ($g->invoke($m, PID) as $r) if (in_array('weight', $r['fields'], true)) return $r;
        return null;
    };
    $r = $rw(mod($LD, $LDATA, $FULL, $REFS));
    check('the shorthand is written out for the field, and labelled', $r && $r['deltaVs'] === '[previous-event-name][weight]'
        && $r['deltaVsName'] === 'previous-event' && $r['deltaVsLabel'] === 'the previous event'
        && $r['deltaGroup'] === '[previous-event-name][weight] both');

    $r = ruleFor($p, 'weight');
    check('the page at visit 2: the previous visit\'s weight, advisory (got ' . json_encode([$r['deltaVsOp'] ?? null, $r['blockSave'] ?? null]) . ')',
        $r && ($r['deltaVsOp'] ?? null) === ['lit', '70.0'] && $r['extendedAdvisory'] === true && $r['blockSave'] === 'off');
    $r = ruleFor($p, 'height');
    check('the page at visit 2: the first visit\'s height', $r && ($r['deltaVsOp'] ?? null) === ['lit', '150']);
    $r = ruleFor($p, 'w_bl');
    check('the page: a named event\'s value', $r && ($r['deltaVsOp'] ?? null) === ['lit', '60.0']);
    $r = ruleFor(page(mod($LD, $LDATA, $FULL, $REFS), 'form', '2', 'visit_form', 2), 'weight');
    check('the page at the first visit: nothing earlier, a blank value (got ' . json_encode($r['deltaVsOp'] ?? null) . ')',
        $r && ($r['deltaVsOp'] ?? null) === ['lit', '']);
    $r = ruleFor(page(mod($LD, $LDATA, $PART, $REFS), 'form', '2', 'visit_form', 3), 'w_bl');
    check('a named event on a form this viewer may not read: withheld (got ' . json_encode($r['deltaVsOp'] ?? null) . ')',
        $r && ($r['deltaVsOp'] ?? null) === ['withheld']);
    // A condition that reads this entry and a form this viewer may not read
    // cannot run on the page; the save, which reads every form, checks it,
    // and the page is told so (deferredChecked, review B3).
    $DCK = $LD + ['w_dck' => f('visit_form', '@UVDELTA={"vs":"[visit1_arm_1][w_dck]","max":1,"when":"[enrol_arm_1][w_enrol]<[w_dck]"}', 'number')];
    $r = ruleFor(page(mod($DCK, $LDATA, $PART, $REFS), 'form', '2', 'visit_form', 3), 'w_dck');
    check('a deferral only this viewer meets: deferredChecked (got ' . json_encode($r) . ')', $r && !empty($r['deferred'])
        && ($r['deferredChecked'] ?? null) === true && strpos(json_encode($r['deferredWhy'] ?? []), 'unauthorized') !== false);
    $r = ruleFor(page(mod($DCK, $LDATA, $FULL, $REFS), 'form', '2', 'visit_form', 3), 'w_dck');
    check('... a viewer who may read both forms gets the rule', $r && empty($r['deferred']) && !isset($r['deferredChecked']));
    $D2 = $LDATA; $D2['2'][2]['w_dck'] = '70'; $D2['2'][3]['w_dck'] = '75';
    $m = mod($DCK, $D2, $PART, $REFS);
    $m->redcap_save_record(PID, '2', 'visit_form', 3, null, null, null, 1);
    check('... and the save does check it (got ' . json_encode(deltaBy($m, 3)) . ')', (deltaBy($m, 3)['w_dck']['reason'] ?? null) === 'up');
    $r = ruleFor(page(mod($LD, $LDATA, $FULL, $REFS), 'form', '2', 'visit_form', 2), 'w_last');
    check('a vs that is this very entry: a live ref to the field itself (no change)',
        $r && ($r['deltaVsOp'] ?? null) === ['ref', 'w_last', null]);

    $m = mod($LD, $LDATA, $FULL, $REFS);
    $m->redcap_save_record(PID, '2', 'visit_form', 3, null, null, null, 1);
    $by = deltaBy($m, 3);
    check('visit 2: 78 is 11.4% above the previous 70 (got ' . json_encode(array_keys($by)) . ')', ($by['weight']['reason'] ?? null) === 'up');
    check('visit 2: 149 is below the first visit\'s 150', ($by['height']['reason'] ?? null) === 'down');
    check('visit 2: 75 is 25% above enrolment\'s 60', ($by['w_bl']['reason'] ?? null) === 'up');
    check('visit 2: 7 is 2 above visit 1\'s 5', ($by['w_last']['reason'] ?? null) === 'up');
    check('visit 2: no rule problem', !findings($m, 'uvalidate-unconfigurable'));

    $m = mod($LD, $LDATA, $FULL, $REFS);
    $m->redcap_save_record(PID, '2', 'visit_form', 2, null, null, null, 1);
    $by = deltaBy($m, 2);
    check('the first visit compares with nothing (got ' . json_encode(array_keys($by)) . ')', !isset($by['weight']) && !isset($by['height']));
    check('the first visit: w_bl still compares with enrolment (70 is 16.7% above 60)', !isset($by['w_bl']));
    check('the first visit: a vs that is this very entry is never a change', !isset($by['w_last']));
    check('the first visit: no rule problem', !findings($m, 'uvalidate-unconfigurable'));
    $later = deltaBy($m, 3);
    check('saving the first visit re-checks the visit that compares with it (got ' . json_encode(array_keys($later)) . ')',
        ($later['weight']['reason'] ?? null) === 'up' && ($later['height']['reason'] ?? null) === 'down'
        && ($later['w_last']['reason'] ?? null) === 'up' && $later['weight']['vs'] === '[previous-event-name][weight]');

    $m = mod($LD, $LDATA, $FULL, $REFS);
    $m->redcap_save_record(PID, '2', 'visit_form', 4, null, null, null, 1);
    check('visit 3: 79 is 1.3% above 78; blanks check nothing (got ' . json_encode(deltaBy($m, 4)) . json_encode(findings($m, 'uvalidate-unconfigurable')) . ')',
        !deltaBy($m, 4) && !findings($m, 'uvalidate-unconfigurable'));

    $gap = $LDATA;
    unset($gap['2'][3]);
    $m = mod($LD, $gap, $FULL, $REFS);
    $m->redcap_save_record(PID, '2', 'visit_form', 4, null, null, null, 1);
    check('visit 3 with nothing saved at visit 2: the previous event is visit 2, blank, so nothing is checked',
        !isset(deltaBy($m, 4)['weight']) && !findings($m, 'uvalidate-unconfigurable'));

    $res = mod($LD, $LDATA, $FULL, $REFS)->scanProject(PID);
    $hits = [];
    foreach ($res['violations'] as $v) if ($v['type'] === 'delta') $hits[] = $v['field'] . '@' . $v['event_id'] . ':' . $v['reason'];
    sort($hits);
    check('scan: the same verdicts, at visit 2 only (got ' . json_encode($hits) . ')',
        $hits === ['height@3:down', 'w_bl@3:up', 'w_last@3:up', 'weight@3:up']);

    // ============================================================================
    // Repeating instances: visit_form repeats on its own.
    // ============================================================================
    $RD = [
        'record_id' => f('enrol_form'),
        'weight'    => f('visit_form', '@UVDELTA={"vs":"previous-instance","max":2}', 'number'),
        'w_first'   => f('visit_form', '@UVDELTA={"vs":"first-instance","maxPct":10,"direction":"down"}', 'number'),
    ];
    $RDATA = ['2' => [351 => ['record_id' => '2'], 'repeat_instances' => [351 => ['visit_form' => [
        1 => ['weight' => '70', 'w_first' => '100'],
        2 => ['weight' => '73', 'w_first' => '95'],
        3 => ['weight' => '74', 'w_first' => '89'],
    ]]]]];
    classic();
    \Project::$repeats = [351 => ['visit_form' => '']];
    $r = ruleFor(page(mod($RD, $RDATA, $FULL, $REFS), 'form', '2', 'visit_form', 351, 2), 'weight');
    check('repeating: an instance shorthand is accepted (got ' . json_encode($r['configError'] ?? null) . ')', $r && empty($r['configError'])
        && ($r['deltaVsOp'] ?? null) === ['lit', '70']);
    $m = mod($RD, $RDATA, $FULL, $REFS);
    $m->redcap_save_record(PID, '2', 'visit_form', 351, null, null, null, 2);
    check('instance 2: 73 is 3 above instance 1 (got ' . json_encode(deltaBy($m)) . ')',
        (deltaBy($m, 351, 2)['weight']['reason'] ?? null) === 'up' && !isset(deltaBy($m, 351, 2)['w_first']));
    $m = mod($RD, $RDATA, $FULL, $REFS);
    $m->redcap_save_record(PID, '2', 'visit_form', 351, null, null, null, 3);
    check('instance 3: 74 is 1 above instance 2; 89 is 11% below the first (got ' . json_encode(deltaBy($m)) . ')',
        !isset(deltaBy($m, 351, 3)['weight']) && (deltaBy($m, 351, 3)['w_first']['reason'] ?? null) === 'down');
    $m = mod($RD, $RDATA, $FULL, $REFS);
    $m->redcap_save_record(PID, '2', 'visit_form', 351, null, null, null, 1);
    check('instance 1: nothing earlier (got ' . json_encode(deltaBy($m)) . ')', !deltaBy($m, 351, 1) && !findings($m, 'uvalidate-unconfigurable'));
    check('... and the save re-checks instance 2, which compares with it', (deltaBy($m, 351, 2)['weight']['reason'] ?? null) === 'up');
    $res = mod($RD, $RDATA, $FULL, $REFS)->scanProject(PID);
    $hits = [];
    foreach ($res['violations'] as $v) if ($v['type'] === 'delta') $hits[] = $v['field'] . '#' . ($v['instance'] ?? '?') . ':' . $v['reason'];
    sort($hits);
    check('scan: each instance against the one before (got ' . json_encode($hits) . ')', $hits === ['w_first#3:down', 'weight#2:up']);

    // An event shorthand on a form that repeats on its own names no instance: refused.
    longitudinal([2 => ['visit_form' => ''], 3 => ['visit_form' => ''], 4 => ['visit_form' => '']]);
    $ev = ['record_id' => f('enrol_form'), 'weight' => f('visit_form', '@UVDELTA={"vs":"previous-event","max":2}', 'number'),
        'w_ok' => f('visit_form', '@UVDELTA={"vs":"[previous-event-name][w_ok][last-instance]","max":2}', 'number')];
    $p = page(mod($ev, ['2' => [1 => ['record_id' => '2']]], $FULL, $REFS), 'form', '2', 'visit_form', 3);
    $e = ruleFor($p, 'weight')['configError'] ?? '';
    check('an event shorthand on a form that repeats: refused, names the events and a reference that works (got ' . $e . ')',
        strpos($e, '"vs": "previous-event" names "visit1_arm_1" and "visit2_arm_1", where "visit_form" repeats') !== false
        && substr($e, -strlen('as "vs":"[previous-event-name][weight][last-instance]".')) === 'as "vs":"[previous-event-name][weight][last-instance]".');
    check('... written out with an instance it is accepted', empty(ruleFor($p, 'w_ok')['configError']));

    // A repeating event: the instance shorthand names the event's own instances.
    longitudinal([2 => 'WHOLE', 3 => 'WHOLE', 4 => 'WHOLE']);
    $iv = ['record_id' => f('enrol_form'), 'weight' => f('visit_form', '@UVDELTA={"vs":"previous-instance","max":2}', 'number')];
    $p = page(mod($iv, ['2' => [1 => ['record_id' => '2']]], $FULL, $REFS), 'form', '2', 'visit_form', 2);
    check('an instance shorthand on a form of repeating events: accepted (got ' . json_encode(ruleFor($p, 'weight')['configError'] ?? null) . ')',
        empty(ruleFor($p, 'weight')['configError']));

    // Each entry resolves its own shorthand, so the layout is judged at every
    // event that holds the form, arm by arm (review S1).
    $layoutErr = function (array $repeats, $ann, array $eventsForms = null, array $eventInfo = null) use ($FULL, $REFS) {
        longitudinal($repeats);
        if ($eventsForms !== null) \Project::$eventsFormsAll = $eventsForms;
        if ($eventInfo !== null) \Project::$eventInfoAll = $eventInfo;
        $d = ['record_id' => f('enrol_form'), 'weight' => f('visit_form', $ann, 'number')];
        $r = ruleFor(page(mod($d, ['2' => [1 => ['record_id' => '2']]], $FULL, $REFS), 'form', '2', 'visit_form', 3), 'weight');
        return $r['configError'] ?? '';
    };
    $e = $layoutErr([2 => ['visit_form' => '']], '@UVDELTA={"vs":"previous-instance","max":2}');
    check('an instance shorthand on a form that repeats at one visit of three: refused, names the others (got ' . $e . ')',
        strpos($e, '"visit_form" does not repeat at "visit2_arm_1" and "visit3_arm_1"') !== false);
    $e = $layoutErr([2 => 'WHOLE'], '@UVDELTA={"vs":"previous-instance","max":2}');
    check('an instance shorthand where only the first visit repeats as a whole: refused (got ' . $e . ')',
        strpos($e, 'does not repeat at "visit2_arm_1" and "visit3_arm_1"') !== false);
    $e = $layoutErr([2 => 'WHOLE'], '@UVDELTA={"vs":"previous-event","max":2}');
    check('an event shorthand that names a visit repeating as a whole: refused (got ' . $e . ')',
        strpos($e, 'names "visit1_arm_1", where "visit_form" repeats') !== false);
    $e = $layoutErr([4 => ['visit_form' => '']], '@UVDELTA={"vs":"previous-event","max":2}');
    check('an event shorthand on a form that repeats only at the last visit: accepted, it never names that visit (got ' . $e . ')', $e === '');
    $e = $layoutErr([4 => ['visit_form' => '']], '@UVDELTA={"vs":"first-event","max":2}');
    check('... first-event too', $e === '');
    $twoArms = [1 => ['unique_event_name' => 'enrol_arm_1', 'arm_num' => 1], 2 => ['unique_event_name' => 'visit_arm_1', 'arm_num' => 1],
                5 => ['unique_event_name' => 'enrol_arm_2', 'arm_num' => 2], 6 => ['unique_event_name' => 'visit_arm_2', 'arm_num' => 2]];
    $e = $layoutErr([], '@UVDELTA={"vs":"previous-event","max":2}', [1 => ['enrol_form'], 2 => ['visit_form'], 5 => ['enrol_form'], 6 => ['visit_form']], $twoArms);
    check('an event shorthand on a form on one event in each of two arms: refused (got ' . $e . ')',
        strpos($e, 'needs the form on two or more events of one arm') !== false && strpos($e, 'no two events of the same arm') !== false);
    // The plain reference the refusal of case (c) used to send people to works at every visit.
    longitudinal([4 => ['visit_form' => '']]);
    $c = ['record_id' => f('enrol_form'), 'weight' => f('visit_form', '@UVDELTA={"vs":"previous-event","max":2}', 'number')];
    $m = mod($c, ['2' => [1 => ['record_id' => '2'], 3 => ['weight' => '60'],
                          'repeat_instances' => [4 => ['visit_form' => [1 => ['weight' => '90']]]]]], $FULL, $REFS);
    $m->redcap_save_record(PID, '2', 'visit_form', 4, null, null, null, 1);
    check('the last visit, which repeats, compares each instance with the visit before (got ' . json_encode(deltaBy($m, 4)) . ')',
        (deltaBy($m, 4, 1)['weight']['reason'] ?? null) === 'up' && !problems($m, 'weight'));

    // Every reference a refusal suggests is accepted when written in its place
    // (round-6 review L1-L4).
    $sug = function ($ref) { return ' — write it as "' . $ref . '".'; };
    // the refusal lists what the search tried (review R8-3)
    $none = function ($f, $tried) { return ' — and none of the references to "[' . $f . ']" this check tried (' . $tried . ') works from every event where this field is entered.'; };
    $triedEnrol = 'this event, the previous or first event, or the event "enrol_arm_1", with the instance as written, none, the last or the previous one';
    $roundTrip = function (array $dict, $field, $form, $evt, $err, $rights, $settings) {
        if (!preg_match('/ — write it as "([^"]+)"/u', $err, $mm)) return false;
        $dict[$field]['field_annotation'] = '@UVDELTA={"vs":"' . $mm[1] . '","max":1}';
        $r = ruleFor(page(mod($dict, ['2' => [1 => ['record_id' => '2']]], $rights, $settings), 'form', '2', $form, $evt), $field);
        return $r !== null && empty($r['configError']);
    };

    // A written-out reference that always names this same entry (review S2).
    longitudinal();
    $self = ['record_id' => f('enrol_form'),
        's_inst' => f('visit_form', '@UVDELTA={"vs":"[s_inst][current-instance]","max":1}', 'number'),
        's_evt'  => f('visit_form', '@UVDELTA={"vs":"[event-name][s_evt]","max":1}', 'number'),
        's_both' => f('visit_form', '@UVDELTA={"vs":"[event-name][s_both][current-instance]","max":1}', 'number'),
        's_one'  => f('enrol_form', '@UVDELTA={"vs":"[enrol_arm_1][s_one]","max":1}', 'number'),
        's_fe'   => f('enrol_form', '@UVDELTA={"vs":"[first-event-name][s_fe]","max":1}', 'number'),
        's_le'   => f('enrol_form', '@UVDELTA={"vs":"[last-event-name][s_le]","max":1}', 'number'),
        's_fi'   => f('enrol_form', '@UVDELTA={"vs":"[s_fi][first-instance]","max":1}', 'number'),
        's_li'   => f('enrol_form', '@UVDELTA={"vs":"[s_li][last-instance]","max":1}', 'number'),
        's_vfe'  => f('visit_form', '@UVDELTA={"vs":"[first-event-name][s_vfe]","max":1}', 'number'),
        's_ok'   => f('visit_form', '@UVDELTA={"vs":"[visit1_arm_1][s_ok]","max":1}', 'number'),
        'n_one'  => f('enrol_form', '@UVDELTA={"vs":"[n_one][1]","max":1}', 'number'),
        'n_vis'  => f('visit_form', '@UVDELTA={"vs":"[n_vis][2]","max":1}', 'number')];
    $p = page(mod($self, ['2' => [1 => ['record_id' => '2']]], $FULL, $REFS), 'form', '2', 'visit_form', 3);
    $pe = page(mod($self, ['2' => [1 => ['record_id' => '2']]], $FULL, $REFS), 'form', '2', 'enrol_form', 1);
    foreach (['s_evt' => $p, 's_one' => $pe, 's_fe' => $pe, 's_le' => $pe] as $sf => $pg) {
        $e = ruleFor($pg, $sf)['configError'] ?? '';
        check('"vs" ' . $sf . ' names this same entry: refused (got ' . $e . ')', strpos($e, '"vs" names this field in this same entry') !== false);
    }
    $e = ruleFor($p, 's_evt')['configError'] ?? '';
    check('... the refusal suggests another entry (got ' . $e . ')',
        $e === '@UVDELTA on "s_evt": "vs" names this field in this same entry, so the change would always be 0 — compare it with another entry, as "[previous-event-name][s_evt]".');
    check('... and only another entry: none where the form is on one event and does not repeat',
        (ruleFor($pe, 's_one')['configError'] ?? '') === '@UVDELTA on "s_one": "vs" names this field in this same entry, so the change would always be 0 — compare it with another entry.');
    check('... a named event on a form at several events is another entry there: accepted', empty(ruleFor($p, 's_ok')['configError']));
    check('... and so is the first event of a form at several events, which is this entry at the first one only',
        empty(ruleFor($p, 's_vfe')['configError']));
    // An instance of a form that does not repeat: the resolver can only say
    // "invalid" there, on every save (round-3 review F4).
    $flatWhy = function ($f, $written, $form, $events, $tail) { return '@UVDELTA on "' . $f . '": "vs" names "' . $written . '", but its form "' . $form
        . '" does not repeat at "' . implode('", "', $events) . '", and an instance can be named only where the form repeats' . $tail; };
    $V3 = ['visit1_arm_1', 'visit2_arm_1', 'visit3_arm_1'];
    foreach (['s_inst' => [$p, '[s_inst][current-instance]', 'visit_form', $V3, $sug('[previous-event-name][s_inst]'), 3],
              's_both' => [$p, '[event-name][s_both][current-instance]', 'visit_form', $V3, $sug('[previous-event-name][s_both]'), 3],
              'n_vis' => [$p, '[n_vis][2]', 'visit_form', $V3, $sug('[previous-event-name][n_vis]'), 3],
              's_fi' => [$pe, '[s_fi][first-instance]', 'enrol_form', ['enrol_arm_1'], $none('s_fi', $triedEnrol), 1],
              's_li' => [$pe, '[s_li][last-instance]', 'enrol_form', ['enrol_arm_1'],
                         $none('s_li', 'this event, the previous or first event, or the event "enrol_arm_1", with the instance as written, none, the previous or the first one'), 1],
              'n_one' => [$pe, '[n_one][1]', 'enrol_form', ['enrol_arm_1'],
                          $none('n_one', 'this event, the previous or first event, or the event "enrol_arm_1", with the instance as written, none, the last, the previous or the first one'), 1]] as $sf => $c) {
        $e = ruleFor($c[0], $sf)['configError'] ?? '';
        check('"vs" ' . $sf . ' names an instance of a form that does not repeat: refused, naming the events (got ' . $e . ')',
            $e === $flatWhy($sf, $c[1], $c[2], $c[3], $c[4]));
        if (strpos($c[4], 'none of the references') === false) check('... and the reference it suggests is accepted', $roundTrip($self, $sf, $c[2], $c[5], $e, $FULL, $REFS));
    }
    longitudinal([1 => ['enrol_form' => '']]);
    $pr = page(mod($self, ['2' => [1 => ['record_id' => '2']]], $FULL, $REFS), 'form', '2', 'enrol_form', 1);
    check('... the first instance of a form that repeats is another entry: accepted (got ' . json_encode(ruleFor($pr, 's_fi')['configError'] ?? null) . ')',
        empty(ruleFor($pr, 's_fi')['configError']) && empty(ruleFor($pr, 's_li')['configError']));
    check('... and so is instance 1 of it', empty(ruleFor($pr, 'n_one')['configError']));
    $selfR = $self + ['s_cur' => f('enrol_form', '@UVDELTA={"vs":"[s_cur][current-instance]","max":1}', 'number')];
    $pr = page(mod($selfR, ['2' => [1 => ['record_id' => '2']]], $FULL, $REFS), 'form', '2', 'enrol_form', 1);
    check('... while the current instance of a form that repeats is this same entry: refused (got ' . json_encode(ruleFor($pr, 's_cur')['configError'] ?? null) . ')',
        strpos(ruleFor($pr, 's_cur')['configError'] ?? '', '"vs" names this field in this same entry') !== false);
    // visit_form repeats at the last visit only: an instance named from the others is refused, naming them
    longitudinal([4 => ['visit_form' => '']]);
    $pm = page(mod($self, ['2' => [1 => ['record_id' => '2']]], $FULL, $REFS), 'form', '2', 'visit_form', 3);
    check('... a form that repeats at some events only: the others are named (got ' . json_encode(ruleFor($pm, 'n_vis')['configError'] ?? null) . ')',
        (ruleFor($pm, 'n_vis')['configError'] ?? '') === $flatWhy('n_vis', '[n_vis][2]', 'visit_form', ['visit1_arm_1', 'visit2_arm_1'], $sug('[previous-event-name][n_vis]')));
    // one event: no event to name
    classic();
    $one = ['record_id' => f('enrol_form'), 'n_cl' => f('enrol_form', '@UVDELTA={"vs":"[n_cl][1]","max":1}', 'number')];
    $pc = page(mod($one, ['2' => [351 => ['record_id' => '2']]], $FULL, $REFS), 'form', '2', 'enrol_form');
    check('... a classic project: refused, with no event named (got ' . json_encode(ruleFor($pc, 'n_cl')['configError'] ?? null) . ')',
        (ruleFor($pc, 'n_cl')['configError'] ?? '') === '@UVDELTA on "n_cl": "vs" names "[n_cl][1]", but its form "enrol_form" does not repeat, and an instance can be named only where the form repeats'
        . ' — and none of the references to "[n_cl]" this check tried (with the instance as written, none, the last, the previous or the first one) works.');
    // A relative instance counts from this field's own instance, and an event
    // named outright must hold the "vs" form: anything else the resolver
    // answers "invalid" or "missing" on every save (round-4 review 5).
    longitudinal([1 => ['rep_form' => '']]);
    \Project::$eventsFormsAll[1] = ['enrol_form', 'rep_form'];
    \REDCap::$mappings[] = ['event_id' => 1, 'form' => 'rep_form'];
    $ownWhy = function ($f, $sel) { return '@UVDELTA on "' . $f . '": "vs" names "[w_rep][' . $sel . ']", but this field\'s form "enrol_form" does not repeat at "enrol_arm_1"'
        . ', and [current-instance], [previous-instance] and [next-instance] count from this field\'s own instance — write it as "[w_rep][last-instance]".'; };
    $REP = ['record_id' => f('enrol_form'), 'w0' => f('enrol_form', '', 'number'), 'w_rep' => f('rep_form', '', 'number'),
        'o_prev' => f('enrol_form', '@UVDELTA={"vs":"[w_rep][previous-instance]","max":1}', 'number'),
        'o_cur'  => f('enrol_form', '@UVDELTA={"vs":"[w_rep][current-instance]","max":1}', 'number'),
        'o_next' => f('enrol_form', '@UVDELTA={"vs":"[w_rep][next-instance]","max":1}', 'number'),
        'o_two'  => f('enrol_form', '@UVDELTA={"vs":"[w_rep][2]","max":1}', 'number'),
        'o_last' => f('enrol_form', '@UVDELTA={"vs":"[w_rep][last-instance]","max":1}', 'number'),
        'o_ev'   => f('enrol_form', '@UVDELTA={"vs":"[visit1_arm_1][w_rep][1]","max":1}', 'number'),
        'o_evok' => f('enrol_form', '@UVDELTA={"vs":"[enrol_arm_1][w_rep][1]","max":1}', 'number'),
        'r_prev' => f('rep_form', '@UVDELTA={"vs":"[w_rep][previous-instance]","max":1}', 'number')];
    $RFULL = ['nurse' => ['forms' => ['enrol_form' => '1', 'visit_form' => '1', 'rep_form' => '1']]];
    $po = page(mod($REP, ['2' => [1 => ['record_id' => '2']]], $RFULL, $REFS), 'form', '2', 'enrol_form', 1);
    foreach (['o_prev' => 'previous-instance', 'o_cur' => 'current-instance', 'o_next' => 'next-instance'] as $sf => $sel) {
        check('"vs" ' . $sf . ': a relative instance from a form that does not repeat: refused (got ' . json_encode(ruleFor($po, $sf)['configError'] ?? null) . ')',
            (ruleFor($po, $sf)['configError'] ?? '') === $ownWhy($sf, $sel));
        check('... and the reference it suggests is accepted', $roundTrip($REP, $sf, 'enrol_form', 1, ruleFor($po, $sf)['configError'] ?? '', $RFULL, $REFS));
    }
    check('... a numbered or last instance of a form that repeats there is read from any form: accepted',
        empty(ruleFor($po, 'o_two')['configError']) && empty(ruleFor($po, 'o_last')['configError']));
    check('"vs" names an event that does not hold its form: refused, with the plain reference that reads the same entry (got ' . json_encode(ruleFor($po, 'o_ev')['configError'] ?? null) . ')',
        (ruleFor($po, 'o_ev')['configError'] ?? '') === '@UVDELTA on "o_ev": "vs" names "[visit1_arm_1][w_rep][1]", but "w_rep" is on the form "rep_form", which is not designated at "visit1_arm_1" — write it as "[w_rep][1]".');
    check('... and the reference it suggests is accepted', $roundTrip($REP, 'o_ev', 'enrol_form', 1, ruleFor($po, 'o_ev')['configError'] ?? '', $RFULL, $REFS));
    check('... an event that holds it: accepted', empty(ruleFor($po, 'o_evok')['configError']));
    $pq = page(mod($REP, ['2' => [1 => ['record_id' => '2']]], $RFULL, $REFS), 'form', '2', 'rep_form', 1);
    check('... and a relative instance on the form that repeats: accepted', empty(ruleFor($pq, 'r_prev')['configError']));
    longitudinal();

    // ============================================================================
    // A "vs" that cannot be read is a rule problem, never a blank.
    // ============================================================================
    // w_enrol is collected only at enrolment, so no visit can read it plain:
    // refused when the dictionary is read, naming the events and the reference
    // to write instead (round-5 review N3).
    longitudinal();
    $OFF = $LD + ['w_off' => f('visit_form', '@UVDELTA={"vs":"[w_enrol]","max":1}', 'number')];
    $V = $LDATA; $V['2'][3]['w_off'] = '99';
    foreach (['references on' => [$REFS, ''], 'references off' => [[], ' (this needs event and instance references, a project setting)']] as $how => $c) {
        $po = page(mod($OFF, $V, $FULL, $c[0]), 'form', '2', 'visit_form', 3);
        check('a plain "vs" collected in no event of this field, ' . $how . ': refused (got ' . json_encode(ruleFor($po, 'w_off')['configError'] ?? null) . ')',
            (ruleFor($po, 'w_off')['configError'] ?? '') === '@UVDELTA on "w_off": "vs" names "[w_enrol]", but "w_enrol" is on the form "enrol_form", which is not designated at '
            . '"visit1_arm_1", "visit2_arm_1", "visit3_arm_1" where this field is entered — write it as "[enrol_arm_1][w_enrol]"' . $c[1] . '.');
        check('... and the reference it suggests is accepted', $roundTrip($OFF, 'w_off', 'visit_form', 3, ruleFor($po, 'w_off')['configError'] ?? '', $FULL, $REFS));
    }
    $m = mod($OFF, $V, $FULL, $REFS);
    $m->redcap_save_record(PID, '2', 'visit_form', 3, null, null, null, 1);
    check('... and a save logs no finding for it', !isset(deltaBy($m, 3)['w_off']));
    // The evaluator still reports a plain "vs" a save cannot read (the layout
    // changed after the rule was read, or the read failed): never a blank.
    $fd = new \ReflectionMethod(\INSPIRE\UniversalValidator\UniversalValidator::class, 'findingsDelta');
    $fd->setAccessible(true);
    $plainRule = ['type' => 'delta', 'fields' => ['w_off'], 'deltaVs' => '[w_enrol]', 'deltaMax' => '1', 'deltaDirection' => 'both', 'deltaGroup' => '[w_enrol] both'];
    foreach (['missing' => 'which is not collected in this event', 'unreadable' => 'reading its saved value failed'] as $state => $text) {
        $res = $fd->invoke(mod($OFF, $V, $FULL), $plainRule, 'delta', ['w_off' => '99', 'w_enrol' => '70'], [], null, PID, '2', 3, ['w_enrol' => $state]);
        check('the evaluator: a plain "vs" a save reads as ' . $state . ' is a rule problem, not a blank (got ' . json_encode($res) . ')',
            !$res['invalid'] && strpos($res['unconfigurable'][0]['why'] ?? '', $text) !== false);
    }
    // A field of a non-repeating form compared with one on a form that
    // repeats, naming no instance, has no instance to pair with on any save:
    // refused, with references on or off (round-4 review 5).
    classic();
    \Project::$repeats = [351 => ['visit_form' => '']];
    $AMB = $RD + ['e_w' => f('enrol_form', '@UVDELTA={"vs":"[weight]","max":1}', 'number'),
                  'e_ok' => f('enrol_form', '@UVDELTA={"vs":"[weight][last-instance]","max":1}', 'number')];
    $V = $RDATA; $V['2'][351]['e_w'] = '99';
    foreach (['references on' => [$REFS, ''], 'references off' => [[], ' (this needs event and instance references, a project setting)']] as $how => $c) {
        $pa = page(mod($AMB, $V, $FULL, $c[0]), 'form', '2', 'enrol_form');
        check('a "vs" on another repeating form with no instance, ' . $how . ': refused (got ' . json_encode(ruleFor($pa, 'e_w')['configError'] ?? null) . ')',
            (ruleFor($pa, 'e_w')['configError'] ?? '') === '@UVDELTA on "e_w": "vs" names "[weight]" with no instance, but its form "visit_form" repeats, '
            . 'so which instance it means is not said — write it as "[weight][last-instance]"' . $c[1] . '.');
    }
    $pa = page(mod($AMB, $V, $FULL, $REFS), 'form', '2', 'enrol_form');
    $e = ruleFor($pa, 'e_w')['configError'] ?? '';
    $AMBt = $AMB; $AMBt['e_w']['field_annotation'] = '@UVDELTA={"vs":"[weight][last-instance]","max":1}';
    check('... and the reference it suggests is accepted',
        empty(ruleFor(page(mod($AMBt, $V, $FULL, $REFS), 'form', '2', 'enrol_form'), 'e_w')['configError']) && strpos($e, '"[weight][last-instance]"') !== false);
    $m = mod($AMB, $V, $FULL, $REFS);
    $m->redcap_save_record(PID, '2', 'enrol_form', 351, null, null, null, 1);
    check('... and a save logs no finding for it', !isset(deltaBy($m)['e_w']));
    check('... naming the instance: accepted', empty(ruleFor(page(mod($AMB, $V, $FULL, $REFS), 'form', '2', 'enrol_form'), 'e_ok')['configError']));
    // longitudinal: a named event where the form repeats, and the field's own row
    longitudinal([1 => ['rep_form' => '']]);
    \Project::$eventsFormsAll[1] = ['enrol_form', 'rep_form'];
    \REDCap::$mappings[] = ['event_id' => 1, 'form' => 'rep_form'];
    $AMB2 = ['record_id' => f('enrol_form'), 'w_rep' => f('rep_form', '', 'number'), 'w_rep2' => f('rep_form', '', 'number'),
        'a_ev' => f('enrol_form', '@UVDELTA={"vs":"[enrol_arm_1][w_rep]","max":1}', 'number'),
        'a_own' => f('rep_form', '@UVDELTA={"vs":"[w_rep2]","max":1}', 'number')];
    $RFULL = ['nurse' => ['forms' => ['enrol_form' => '1', 'visit_form' => '1', 'rep_form' => '1']]];
    $pa = page(mod($AMB2, ['2' => [1 => ['record_id' => '2']]], $RFULL, $REFS), 'form', '2', 'enrol_form', 1);
    check('a named event where the "vs" form repeats, with no instance: refused, naming it (got ' . json_encode(ruleFor($pa, 'a_ev')['configError'] ?? null) . ')',
        (ruleFor($pa, 'a_ev')['configError'] ?? '') === '@UVDELTA on "a_ev": "vs" names "[enrol_arm_1][w_rep]" with no instance, but its form "rep_form" repeats at "enrol_arm_1", '
        . 'so which instance it means is not said — write it as "[enrol_arm_1][w_rep][last-instance]".');
    check('... and the reference it suggests is accepted', $roundTrip($AMB2, 'a_ev', 'enrol_form', 1, ruleFor($pa, 'a_ev')['configError'] ?? '', $RFULL, $REFS));
    $pa = page(mod($AMB2, ['2' => [1 => ['record_id' => '2']]], $RFULL, $REFS), 'form', '2', 'rep_form', 1);
    check('... a field of the same repeating form, in its own row: accepted', empty(ruleFor($pa, 'a_own')['configError']));

    // round-5 review: each event the reference is read from, as the resolver reads it
    $FOUR = ['nurse' => ['forms' => ['enrol_form' => '1', 'visit_form' => '1', 'rep_form' => '1', 'a_form' => '1', 'b_form' => '1', 'c_form' => '1', 'd_form' => '1']]];
    $layout = function (array $forms, array $repeats) {
        longitudinal($repeats);
        \Project::$eventsFormsAll = $forms;
        \REDCap::$mappings = [];
        foreach ($forms as $e => $fs) foreach ($fs as $fm) \REDCap::$mappings[] = ['event_id' => $e, 'form' => $fm];
    };
    $errAt = function ($dict, $field, $form, $evt) use ($FOUR, $REFS) {
        return ruleFor(page(mod($dict, ['2' => [1 => ['record_id' => '2']]], $FOUR, $REFS), 'form', '2', $form, $evt), $field)['configError'] ?? '';
    };
    // N2: a_form is held once at enrolment and repeats at the visits; b_form repeats at the visits only.
    // From enrolment and visit 1 the previous event with b_form is absent, never invalid.
    $layout([1 => ['enrol_form', 'a_form'], 2 => ['visit_form', 'a_form', 'b_form'], 3 => ['visit_form', 'a_form', 'b_form'], 4 => ['visit_form', 'a_form', 'b_form']],
        [2 => ['a_form' => '', 'b_form' => ''], 3 => ['a_form' => '', 'b_form' => ''], 4 => ['a_form' => '', 'b_form' => '']]);
    $N2 = ['record_id' => f('enrol_form'), 'wb' => f('b_form', '', 'number'),
        'wa' => f('a_form', '@UVDELTA={"vs":"[previous-event-name][wb][current-instance]","max":1}', 'number')];
    check('N2: a relative instance where the event token finds nothing at the events that hold the form once: accepted (got ' . json_encode($errAt($N2, 'wa', 'a_form', 3)) . ')',
        $errAt($N2, 'wa', 'a_form', 3) === '');
    // N4: [next-event-name] finds no event holding enrol_form from any visit
    $layout([1 => ['enrol_form'], 2 => ['visit_form'], 3 => ['visit_form'], 4 => ['visit_form']], []);
    $N4 = ['record_id' => f('enrol_form'), 'w_enrol' => f('enrol_form', '', 'number'),
        'n4' => f('visit_form', '@UVDELTA={"vs":"[next-event-name][w_enrol]","max":1}', 'number'),
        'n4ok' => f('visit_form', '@UVDELTA={"vs":"[first-event-name][w_enrol]","max":1}', 'number')];
    check('N4: a relative event that finds nothing from any event: refused (got ' . json_encode($errAt($N4, 'n4', 'visit_form', 2)) . ')',
        $errAt($N4, 'n4', 'visit_form', 2) === '@UVDELTA on "n4": "vs" names "[next-event-name][w_enrol]", but from no event where this field is entered does "[next-event-name]" '
        . 'find an event holding "enrol_form", so nothing would ever be compared — write it as "[enrol_arm_1][w_enrol]".');
    check('... and the reference it suggests is accepted', $roundTrip($N4, 'n4', 'visit_form', 2, $errAt($N4, 'n4', 'visit_form', 2), $FOUR, $REFS));
    check('... one that finds an event: accepted', $errAt($N4, 'n4ok', 'visit_form', 2) === '');
    // N7 (a, b): a named event, and a relative one, where the "vs" form repeats, read with no instance
    $layout([1 => ['enrol_form', 'rep_form'], 2 => ['visit_form', 'rep_form'], 3 => ['visit_form', 'rep_form'], 4 => ['visit_form']],
        [1 => ['rep_form' => ''], 2 => ['rep_form' => ''], 3 => ['rep_form' => '']]);
    $N7 = ['record_id' => f('enrol_form'), 'w_rep' => f('rep_form', '', 'number'), 'w_rep2' => f('rep_form', '', 'number'),
        'v_ev' => f('visit_form', '@UVDELTA={"vs":"[enrol_arm_1][w_rep]","max":1}', 'number'),
        'r_pe' => f('rep_form', '@UVDELTA={"vs":"[previous-event-name][w_rep2]","max":1}', 'number')];
    check('N7a: a named event where the form repeats, read from another form: refused (got ' . json_encode($errAt($N7, 'v_ev', 'visit_form', 2)) . ')',
        strpos($errAt($N7, 'v_ev', 'visit_form', 2), '"vs" names "[enrol_arm_1][w_rep]" with no instance, but its form "rep_form" repeats at "enrol_arm_1"') !== false);
    check('N7b: the previous event\'s row of the same repeating form is not this field\'s own row: refused, naming each event (got ' . json_encode($errAt($N7, 'r_pe', 'rep_form', 2)) . ')',
        strpos($errAt($N7, 'r_pe', 'rep_form', 2), '"vs" names "[previous-event-name][w_rep2]" with no instance, but its form "rep_form" repeats at "enrol_arm_1", "visit1_arm_1"') !== false);
    // N7 (c): a relative instance from a form held once at two events names both
    $layout([1 => ['enrol_form', 'rep_form'], 2 => ['enrol_form', 'rep_form', 'visit_form'], 3 => ['visit_form'], 4 => ['visit_form']],
        [1 => ['rep_form' => ''], 2 => ['rep_form' => '']]);
    $N7c = ['record_id' => f('enrol_form'), 'w_rep' => f('rep_form', '', 'number'),
        'o_prev' => f('enrol_form', '@UVDELTA={"vs":"[w_rep][previous-instance]","max":1}', 'number')];
    check('N7c: every event where this field\'s form is held once is named (got ' . json_encode($errAt($N7c, 'o_prev', 'enrol_form', 1)) . ')',
        strpos($errAt($N7c, 'o_prev', 'enrol_form', 1), 'this field\'s form "enrol_form" does not repeat at "enrol_arm_1", "visit1_arm_1"') !== false);
    // N8: another form of the same whole repeating event is this entry's own row
    $layout([1 => ['enrol_form'], 2 => ['visit_form'], 3 => ['visit_form'], 4 => ['visit_form', 'c_form', 'd_form']], [4 => 'WHOLE']);
    $N8 = ['record_id' => f('enrol_form'), 'wd' => f('d_form', '', 'number'),
        'wc' => f('c_form', '@UVDELTA={"vs":"[wd]","max":1}', 'number')];
    check('N8: a form of the same whole repeating event, read with no instance: accepted (got ' . json_encode($errAt($N8, 'wc', 'c_form', 4)) . ')',
        $errAt($N8, 'wc', 'c_form', 4) === '');

    // Round-6 review. L1: the instance written stays in the quote and in the
    // suggestion, and the suggestion is checked like any reference.
    $layout([1 => ['enrol_form', 'rep_form'], 2 => ['visit_form'], 3 => ['visit_form'], 4 => ['visit_form']], [1 => ['rep_form' => '']]);
    $L1 = ['record_id' => f('enrol_form'), 'w_rep' => f('rep_form', '', 'number'),
        'l1'  => f('visit_form', '@UVDELTA={"vs":"[w_rep][last-instance]","max":1}', 'number'),
        'l1p' => f('visit_form', '@UVDELTA={"vs":"[w_rep]","max":1}', 'number'),
        'l1e' => f('visit_form', '@UVDELTA={"vs":"[event-name][w_rep][2]","max":1}', 'number'),
        'l1n' => f('visit_form', '@UVDELTA={"vs":"[next-event-name][w_rep][2]","max":1}', 'number')];
    foreach (['l1' => ['[w_rep][last-instance]', '[enrol_arm_1][w_rep][last-instance]'], 'l1p' => ['[w_rep]', '[enrol_arm_1][w_rep][last-instance]'],
              'l1e' => ['[event-name][w_rep][2]', '[enrol_arm_1][w_rep][2]'], 'l1n' => ['[next-event-name][w_rep][2]', '[enrol_arm_1][w_rep][2]']] as $lf => $c) {
        $e = $errAt($L1, $lf, 'visit_form', 2);
        check('L1: ' . $lf . ' quotes "' . $c[0] . '" and suggests "' . $c[1] . '" (got ' . json_encode($e) . ')',
            strpos($e, '"vs" names "' . $c[0] . '", but') !== false && substr($e, -strlen($sug($c[1]))) === $sug($c[1]));
        check('... which is accepted', $roundTrip($L1, $lf, 'visit_form', 2, $e, $FOUR, $REFS));
    }
    $e = ruleFor(page(mod($L1, ['2' => [1 => ['record_id' => '2']]], $FOUR, []), 'form', '2', 'visit_form', 2), 'l1p')['configError'] ?? '';
    check('... with references off the suggestion says it needs them (got ' . json_encode($e) . ')',
        substr($e, -strlen(' — write it as "[enrol_arm_1][w_rep][last-instance]" (this needs event and instance references, a project setting).'))
        === ' — write it as "[enrol_arm_1][w_rep][last-instance]" (this needs event and instance references, a project setting).');
    // L2: with two arms, an event named outright would read nothing from the
    // other arm, so the suggestion is a relative event every arm resolves.
    $layout([1 => ['enrol_form'], 2 => ['visit_form'], 5 => ['enrol_form'], 6 => ['visit_form']], []);
    \Project::$eventInfoAll = $twoArms;
    $L2 = ['record_id' => f('enrol_form'), 'w_enrol' => f('enrol_form', '', 'number'),
        'l2' => f('visit_form', '@UVDELTA={"vs":"[w_enrol]","max":1}', 'number')];
    $e = $errAt($L2, 'l2', 'visit_form', 6);
    check('L2: two arms: the suggestion is an event each arm finds, not one arm\'s event (got ' . json_encode($e) . ')',
        substr($e, -strlen($sug('[first-event-name][w_enrol]'))) === $sug('[first-event-name][w_enrol]'));
    check('... which is accepted', $roundTrip($L2, 'l2', 'visit_form', 6, $e, $FOUR, $REFS));
    $L2ok = $L2; $L2ok['l2']['field_annotation'] = '@UVDELTA={"vs":"[first-event-name][w_enrol]","max":1}';
    $m = mod($L2ok, ['2' => [5 => ['record_id' => '2', 'w_enrol' => '50'], 6 => ['l2' => '99']]], $FOUR, $REFS);
    $m->redcap_save_record(PID, '2', 'visit_form', 6, null, null, null, 1);
    check('... and it compares a save in the second arm (got ' . json_encode(deltaBy($m, 6)) . ')', (deltaBy($m, 6)['l2']['reason'] ?? null) === 'up');
    // L3: a form that repeats at one visit and is held once at the others:
    // [last-instance] would be refused at the others, so the visit is named.
    $layout([1 => ['enrol_form'], 2 => ['visit_form', 'rep_form'], 3 => ['visit_form', 'rep_form'], 4 => ['visit_form', 'rep_form']], [2 => ['rep_form' => '']]);
    $L3 = ['record_id' => f('enrol_form'), 'w_rep' => f('rep_form', '', 'number'),
        'l3' => f('visit_form', '@UVDELTA={"vs":"[w_rep]","max":1}', 'number')];
    $e = $errAt($L3, 'l3', 'visit_form', 2);
    check('L3: the suggestion names the visit where the form repeats (got ' . json_encode($e) . ')',
        strpos($e, 'repeats at "visit1_arm_1"') !== false && substr($e, -strlen($sug('[visit1_arm_1][w_rep][last-instance]'))) === $sug('[visit1_arm_1][w_rep][last-instance]'));
    check('... which is accepted', $roundTrip($L3, 'l3', 'visit_form', 2, $e, $FOUR, $REFS));
    // ... and across two arms, where the form repeats in one and is held once
    // in the other, no reference reads it from both: nothing is suggested.
    $layout([1 => ['visit_form', 'rep_form'], 2 => ['enrol_form'], 5 => ['visit_form', 'rep_form'], 6 => ['enrol_form']], [1 => ['rep_form' => '']]);
    \Project::$eventInfoAll = $twoArms;
    $e = $errAt($L3, 'l3', 'visit_form', 1);
    check('... with no reference that works, none is suggested (got ' . json_encode($e) . ')',
        $e === '@UVDELTA on "l3": "vs" names "[w_rep]" with no instance, but its form "rep_form" repeats at "enrol_arm_1", so which instance it means is not said' . $none('w_rep', 'this event, or the previous, first or last event, with no instance, the last or the previous one'));
    // L4: a "vs" form designated at no event
    $layout([1 => ['enrol_form'], 2 => ['visit_form'], 3 => ['visit_form'], 4 => ['visit_form']], []);
    $L4 = ['record_id' => f('enrol_form'), 'w_b' => f('b_form', '', 'number'),
        'l4'  => f('visit_form', '@UVDELTA={"vs":"[w_b]","max":1}', 'number'),
        'l4p' => f('visit_form', '@UVDELTA={"vs":"[previous-event-name][w_b]","max":1}', 'number')];
    foreach (['l4' => '[w_b]', 'l4p' => '[previous-event-name][w_b]'] as $lf => $w) {
        check('L4: ' . $lf . ' on a form designated at no event: said so, nothing suggested (got ' . json_encode($errAt($L4, $lf, 'visit_form', 2)) . ')',
            $errAt($L4, $lf, 'visit_form', 2) === '@UVDELTA on "' . $lf . '": "vs" names "' . $w . '", but "w_b" is on the form "b_form", which is not designated at any event.');
    }
    // Round-7 review. N1: an event named outright as written is kept for the
    // suggestion only when every event of the field's form is in its arm.
    $layout([1 => ['enrol_form', 'rep_form'], 2 => ['visit_form'], 5 => ['enrol_form', 'rep_form'], 6 => ['visit_form']], []);
    \Project::$eventInfoAll = $twoArms;
    $N1 = ['record_id' => f('enrol_form'), 'w_rep' => f('rep_form', '', 'number'),
        'n1' => f('visit_form', '@UVDELTA={"vs":"[enrol_arm_1][w_rep][1]","max":1}', 'number')];
    $e = $errAt($N1, 'n1', 'visit_form', 6);
    check('N1: two arms: an instance named on one arm\'s event is refused with a relative event, not that event without the instance (got ' . json_encode($e) . ')',
        strpos($e, '"vs" names "[enrol_arm_1][w_rep][1]", but its form "rep_form" does not repeat') !== false
        && substr($e, -strlen($sug('[first-event-name][w_rep]'))) === $sug('[first-event-name][w_rep]'));
    check('... which is accepted', $roundTrip($N1, 'n1', 'visit_form', 6, $e, $FOUR, $REFS));
    $layout([1 => ['enrol_form', 'rep_form'], 2 => ['visit_form'], 3 => ['visit_form'], 4 => ['visit_form']], []);
    $e = $errAt($N1, 'n1', 'visit_form', 2);
    check('... in one arm the event as written is kept (got ' . json_encode($e) . ')', substr($e, -strlen($sug('[enrol_arm_1][w_rep]'))) === $sug('[enrol_arm_1][w_rep]'));
    // N3: the next event is never offered (review R8-7). Two arms; b_form
    // repeats before and after the field in arm 1 and is held once everywhere
    // in arm 2, so only the next event would read it the same way from both.
    $layout([1 => ['b_form'], 2 => ['enrol_form'], 3 => ['b_form'], 4 => ['b_form'], 5 => ['b_form'], 6 => ['enrol_form'], 7 => ['b_form'], 8 => ['b_form']],
        [1 => ['b_form' => ''], 4 => ['b_form' => '']]);
    \Project::$eventInfoAll = [];
    foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $ev) \Project::$eventInfoAll[$ev] = ['unique_event_name' => 'e' . $ev . '_arm_' . ($ev < 5 ? 1 : 2), 'arm_num' => $ev < 5 ? 1 : 2];
    $N3 = ['record_id' => f('enrol_form'), 'w_b' => f('b_form', '', 'number'), 'n3' => f('enrol_form', '@UVDELTA={"vs":"[w_b]","max":1}', 'number')];
    $e = $errAt($N3, 'n3', 'enrol_form', 6);
    check('N3: only the next event would read the "vs" form the same way from every arm: nothing is suggested (got ' . json_encode($e) . ')',
        substr($e, -strlen($none('w_b', 'the previous, first or last event, or this event, with no instance, the last or the previous one')))
        === $none('w_b', 'the previous, first or last event, or this event, with no instance, the last or the previous one'));
    check('... while the next event written by hand is accepted', $roundTrip($N3, 'n3', 'enrol_form', 6, $sug('[next-event-name][w_b]'), $FOUR, $REFS));
    // N2: a project of one event tries this event with each instance.
    classic();
    \Project::$repeats = [351 => ['visit_form' => '']];
    $N2r = ['record_id' => f('enrol_form'), 'n2r' => f('visit_form', '@UVDELTA={"vs":"[n2r][current-instance]","max":1}', 'number')];
    $e = ruleFor(page(mod($N2r, ['2' => [351 => ['record_id' => '2']]], $FULL, $REFS), 'form', '2', 'visit_form'), 'n2r')['configError'] ?? '';
    check('N2: the field itself on a repeating form of a classic project: the previous instance, never the last, which is this entry at the last (got ' . json_encode($e) . ')',
        $e === '@UVDELTA on "n2r": "vs" names this field in this same entry, so the change would always be 0 — compare it with another entry, as "[n2r][previous-instance]".');
    \Project::$repeats = [];
    $N2 = ['record_id' => f('enrol_form'), 'w_src' => f('enrol_form', '', 'number'),
        'n2' => f('visit_form', '@UVDELTA={"vs":"[w_src][1]","max":1}', 'number')];
    $e = ruleFor(page(mod($N2, ['2' => [351 => ['record_id' => '2']]], $FULL, $REFS), 'form', '2', 'visit_form'), 'n2')['configError'] ?? '';
    check('N2: a classic project: an instance of a form that does not repeat is refused, suggesting the plain field (got ' . json_encode($e) . ')',
        $e === '@UVDELTA on "n2": "vs" names "[w_src][1]", but its form "enrol_form" does not repeat, and an instance can be named only where the form repeats' . $sug('[w_src]'));
    $N2ok = $N2; $N2ok['n2']['field_annotation'] = '@UVDELTA={"vs":"[w_src]","max":1}';
    check('... which is accepted with references off', empty(ruleFor(page(mod($N2ok, ['2' => [351 => ['record_id' => '2']]], $FULL, []), 'form', '2', 'visit_form'), 'n2')['configError']));
    // N4: the field hook's refusal of a plain "vs" naming the field itself
    // suggests only what the dictionary hook accepts.
    $N4 = ['record_id' => f('enrol_form'), 'n4' => f('visit_form', '@UVDELTA={"vs":"[n4]","max":1}', 'number')];
    $n4At = function ($settings, $evt) use ($N4, $FOUR) {
        return ruleFor(page(mod($N4, ['2' => [1 => ['record_id' => '2'], 351 => ['record_id' => '2']]], $FOUR, $settings), 'form', '2', 'visit_form', $evt), 'n4')['configError'] ?? '';
    };
    $SELF = '@UVDELTA on "n4": "vs" names this field itself — compare it with another entry';
    check('N4: a classic project, nothing repeats: no suggestion (got ' . json_encode($n4At($REFS, 351)) . ')', $n4At($REFS, 351) === $SELF . '.');
    \Project::$repeats = [351 => ['visit_form' => '']];
    check('N4: a classic project where the form repeats: previous-instance (got ' . json_encode($n4At($REFS, 351)) . ')',
        $n4At($REFS, 351) === $SELF . ', as "vs":"previous-instance".');
    longitudinal();
    check('N4: visits of one form: previous-event (got ' . json_encode($n4At($REFS, 2)) . ')', $n4At($REFS, 2) === $SELF . ', as "vs":"previous-event".');
    check('... with references off it says they are needed', $n4At([], 2) === $SELF . ', as "vs":"previous-event"' . ' (this needs event and instance references, a project setting).');
    $layout([1 => ['enrol_form', 'visit_form'], 5 => ['enrol_form', 'visit_form']], []);
    \Project::$eventInfoAll = [1 => ['unique_event_name' => 'enrol_arm_1', 'arm_num' => 1], 5 => ['unique_event_name' => 'enrol_arm_2', 'arm_num' => 2]];
    check('N4: the form on one event in each of two arms: nothing to suggest (got ' . json_encode($n4At($REFS, 1)) . ')', $n4At($REFS, 1) === $SELF . '.');
    // The previous event is where visit_form repeats, so "previous-event" is
    // refused there; the previous instance is offered instead.
    $layout([1 => ['enrol_form'], 2 => ['visit_form'], 3 => ['visit_form'], 4 => ['visit_form']],
        [2 => ['visit_form' => ''], 3 => ['visit_form' => ''], 4 => ['visit_form' => '']]);
    check('N4: visits where the form repeats: previous-instance, not the previous-event the dictionary hook refuses (got ' . json_encode($n4At($REFS, 3)) . ')',
        $n4At($REFS, 3) === $SELF . ', as "vs":"previous-instance".');
    // A form designated at no event: no shorthand and no reference can be
    // checked, so none is offered.
    $layout([1 => ['enrol_form'], 2 => ['a_form'], 3 => ['a_form']], []);
    check('N4: the field\'s form designated at no event: nothing to suggest (got ' . json_encode($n4At($REFS, 2)) . ')', $n4At($REFS, 2) === $SELF . '.');
    // N1 again: in one arm the event as written is kept even where another
    // event holding the "vs" form comes first.
    $layout([1 => ['enrol_form', 'rep_form'], 2 => ['visit_form'], 3 => ['visit_form', 'rep_form'], 4 => ['visit_form']], []);
    $N1b = ['record_id' => f('enrol_form'), 'w_rep' => f('rep_form', '', 'number'),
        'n1' => f('visit_form', '@UVDELTA={"vs":"[visit2_arm_1][w_rep][1]","max":1}', 'number')];
    $e = $errAt($N1b, 'n1', 'visit_form', 2);
    check('N1: one arm, the "vs" form at two events: the event as written is kept, not the first one holding the form (got ' . json_encode($e) . ')',
        substr($e, -strlen($sug('[visit2_arm_1][w_rep]'))) === $sug('[visit2_arm_1][w_rep]'));
    check('... which is accepted', $roundTrip($N1b, 'n1', 'visit_form', 2, $e, $FOUR, $REFS));

    // ---- the eighth review (R8-1 to R8-10) ----
    $errsOf = function ($p, $f) {
        $out = [];
        foreach ((isset($p['cfg']['rules']) ? $p['cfg']['rules'] : []) as $r) {
            if (in_array($f, isset($r['fields']) ? $r['fields'] : [], true) && !empty($r['configError'])) $out[] = $r['configError'];
        }
        return $out;
    };
    $pageAt = function ($dict, $form, $evt, $settings) use ($FOUR) {
        return page(mod($dict, ['2' => [1 => ['record_id' => '2']]], $FOUR, $settings), 'form', '2', $form, $evt);
    };
    // R8-1: visit_form repeats at every visit. From another event [previous-instance]
    // counts back from this entry's instance number, so the last instance is offered.
    $layout([1 => ['enrol_form'], 2 => ['visit_form'], 3 => ['visit_form'], 4 => ['visit_form']],
        [2 => ['visit_form' => ''], 3 => ['visit_form' => ''], 4 => ['visit_form' => '']]);
    $R1 = ['record_id' => f('enrol_form'), 's5' => f('visit_form', '@UVDELTA={"vs":"[previous-event-name][s5]","max":1}', 'number')];
    $e = $errAt($R1, 's5', 'visit_form', 3);
    check('R8-1: the field itself at the previous event, where the form repeats: its last instance (got ' . json_encode($e) . ')',
        substr($e, -strlen($sug('[previous-event-name][s5][last-instance]'))) === $sug('[previous-event-name][s5][last-instance]'));
    check('... which is accepted', $roundTrip($R1, 's5', 'visit_form', 3, $e, $FOUR, $REFS));
    $R1['s5']['field_annotation'] = '@UVDELTA={"vs":"[first-event-name][s5]","max":1}';
    $e = $errAt($R1, 's5', 'visit_form', 3);
    check('R8-1, R9-6: the first event, this entry\'s own at the first visit and another elsewhere: only its first instance fits both (got ' . json_encode($e) . ')',
        substr($e, -strlen($sug('[first-event-name][s5][first-instance]'))) === $sug('[first-event-name][s5][first-instance]'));
    check('... which is accepted', $roundTrip($R1, 's5', 'visit_form', 3, $e, $FOUR, $REFS));
    $R1['s5']['field_annotation'] = '@UVDELTA={"vs":"[event-name][s5]","max":1}';
    check('R8-1: [event-name] names this entry: another event is offered, not the event as written (got ' . json_encode($errAt($R1, 's5', 'visit_form', 3)) . ')',
        $errAt($R1, 's5', 'visit_form', 3) === '@UVDELTA on "s5": "vs" names this field in this same entry, so the change would always be 0 — compare it with another entry, as "[previous-event-name][s5][last-instance]".');
    // R8-2, R8-7 and R9-6: visit_form repeats at the first visit only. From there
    // [last-instance] is the newest entry, perhaps this one, and [previous-instance]
    // counts from this entry's instance at the other visits; the last event and a later
    // visit named outright are later visits. The first visit's first instance is an
    // earlier entry from every visit, or this one at instance 1.
    $layout([1 => ['enrol_form'], 2 => ['visit_form'], 3 => ['visit_form'], 4 => ['visit_form']], [2 => ['visit_form' => '']]);
    $R2 = ['record_id' => f('enrol_form'), 'h1' => f('visit_form', '@UVDELTA={"vs":"[h1]","max":1}', 'number')];
    $e = $errAt($R2, 'h1', 'visit_form', 3);
    check('R8-2, R8-7, R9-6: only the first visit\'s first instance reads an earlier entry from every visit (got ' . json_encode($e) . ')',
        $e === '@UVDELTA on "h1": "vs" names this field itself — compare it with another entry, as "vs":"[first-event-name][h1][first-instance]".');
    $R2ok = $R2; $R2ok['h1']['field_annotation'] = '@UVDELTA={"vs":"[first-event-name][h1][first-instance]","max":1}';
    check('... which is accepted', $errAt($R2ok, 'h1', 'visit_form', 3) === '');
    $m = mod($R2ok, ['2' => [3 => ['h1' => '90'], 'repeat_instances' => [2 => ['visit_form' => [1 => ['h1' => '70'], 2 => ['h1' => '72']]]]]], $FOUR, $REFS);
    $m->redcap_save_record(PID, '2', 'visit_form', 3, null, null, null, 1);
    check('... and compares each entry with the first visit\'s first instance (got ' . json_encode(deltaBy($m)) . ')',
        (deltaBy($m, 3, 1)['h1']['reason'] ?? null) === 'up' && (deltaBy($m, 2, 2)['h1']['reason'] ?? null) === 'up');
    $R2['h1']['field_annotation'] = '@UVDELTA={"vs":"[first-event-name][h1]","max":1}';
    $e = $errAt($R2, 'h1', 'visit_form', 3);
    check('R9-6: ... the dictionary hook offers it too (got ' . json_encode($e) . ')',
        substr($e, -strlen($sug('[first-event-name][h1][first-instance]'))) === $sug('[first-event-name][h1][first-instance]'));
    $R2['h1']['field_annotation'] = '@UVDELTA={"vs":"first-event","max":1}';
    $e = $errAt($R2, 'h1', 'visit_form', 3);
    check('R9-3, R9-6: ... and so does the refusal of the shorthand, from its own event (got ' . json_encode($e) . ')',
        substr($e, -strlen('name the event and the instance, as "vs":"[first-event-name][h1][first-instance]".'))
        === 'name the event and the instance, as "vs":"[first-event-name][h1][first-instance]".');
    // R8-9 M1, M19: no shorthand works, so the written reference is offered, with the
    // note that it needs references when they are off.
    $layout([1 => ['enrol_form'], 2 => ['visit_form'], 3 => ['visit_form']], [2 => ['visit_form' => '']]);
    $R9 = ['record_id' => f('enrol_form'), 'm1' => f('visit_form', '@UVDELTA={"vs":"[m1]","max":1}', 'number')];
    $SELF1 = '@UVDELTA on "m1": "vs" names this field itself — compare it with another entry, as "vs":"[previous-event-name][m1][last-instance]"';
    check('R8-9 M1: no shorthand fits: the written reference (got ' . json_encode($errAt($R9, 'm1', 'visit_form', 3)) . ')', $errAt($R9, 'm1', 'visit_form', 3) === $SELF1 . '.');
    $e = ruleFor($pageAt($R9, 'visit_form', 3, []), 'm1')['configError'] ?? '';
    check('R8-9 M19: ... with references off it says they are needed (got ' . json_encode($e) . ')',
        $e === $SELF1 . ' (this needs event and instance references, a project setting).');
    // R8-9 M4: the field's form is in arm 2 only, so an event of arm 1 as written is not kept.
    $layout([1 => ['a_form'], 5 => ['a_form'], 6 => ['visit_form']], []);
    \Project::$eventInfoAll = [1 => ['unique_event_name' => 'e1_arm_1', 'arm_num' => 1],
        5 => ['unique_event_name' => 'e5_arm_2', 'arm_num' => 2], 6 => ['unique_event_name' => 'e6_arm_2', 'arm_num' => 2]];
    $R4 = ['record_id' => f('enrol_form'), 'wa' => f('a_form', '', 'number'), 'm4' => f('visit_form', '@UVDELTA={"vs":"[e1_arm_1][wa][1]","max":1}', 'number')];
    $e = $errAt($R4, 'm4', 'visit_form', 6);
    check('R8-9 M4: an event of another arm as written is not kept: one of this arm (got ' . json_encode($e) . ')', substr($e, -strlen($sug('[e5_arm_2][wa]'))) === $sug('[e5_arm_2][wa]'));
    // R8-10: written by hand, an event of another arm is accepted: a record entered in both arms has a value there.
    $R4['m4']['field_annotation'] = '@UVDELTA={"vs":"[e1_arm_1][wa]","max":1}';
    check('R8-10: an event of another arm written by hand is accepted', $errAt($R4, 'm4', 'visit_form', 6) === '');
    // R8-9 M6: a relative event as written is kept when only the instance is wrong.
    $layout([1 => ['enrol_form'], 2 => ['visit_form', 'b_form'], 3 => ['visit_form', 'b_form'], 4 => ['visit_form', 'b_form']], []);
    $R6 = ['record_id' => f('enrol_form'), 'wb' => f('b_form', '', 'number'), 'm6' => f('visit_form', '@UVDELTA={"vs":"[previous-event-name][wb][2]","max":1}', 'number')];
    $e = $errAt($R6, 'm6', 'visit_form', 3);
    check('R8-9 M6: the previous event as written is kept, without the instance (got ' . json_encode($e) . ')',
        substr($e, -strlen($sug('[previous-event-name][wb]'))) === $sug('[previous-event-name][wb]'));
    // R8-9 M8: another form's field at an event where that form repeats: its last instance,
    // not [previous-instance], which counts back from this entry's instance.
    $layout([1 => ['enrol_form', 'b_form'], 2 => ['visit_form'], 3 => ['visit_form'], 4 => ['visit_form']],
        [1 => ['b_form' => ''], 2 => ['visit_form' => ''], 3 => ['visit_form' => ''], 4 => ['visit_form' => '']]);
    $R8 = ['record_id' => f('enrol_form'), 'wb' => f('b_form', '', 'number'), 'm8' => f('visit_form', '@UVDELTA={"vs":"[enrol_arm_1][wb]","max":1}', 'number')];
    $e = $errAt($R8, 'm8', 'visit_form', 3);
    check('R8-9 M8: another form\'s field: the last instance first (got ' . json_encode($e) . ')',
        substr($e, -strlen($sug('[enrol_arm_1][wb][last-instance]'))) === $sug('[enrol_arm_1][wb][last-instance]'));
    // An event that always lands on this entry's own is left to the plain reference,
    // unless it is the event as written.
    $layout([1 => ['enrol_form', 'rep_form'], 2 => ['visit_form'], 3 => ['visit_form'], 4 => ['visit_form']], [1 => ['rep_form' => '']]);
    $RK = ['record_id' => f('enrol_form'), 'w_rep' => f('rep_form', '', 'number'), 'k' => f('enrol_form', '@UVDELTA={"vs":"[event-name][w_rep]","max":1}', 'number')];
    $e = $errAt($RK, 'k', 'enrol_form', 1);
    check('[event-name] as written is kept, with an instance (got ' . json_encode($e) . ')',
        substr($e, -strlen($sug('[event-name][w_rep][last-instance]'))) === $sug('[event-name][w_rep][last-instance]'));
    // R8-4: a suggestion never repeats the branch group of another tag on the field.
    longitudinal();
    $R5 = function ($ann) {
        return ['record_id' => f('enrol_form'), 'flag' => f('visit_form', '', 'text'), 'w4' => f('visit_form', $ann, 'number')];
    };
    $e = $errsOf($pageAt($R5('@UVDELTA={"vs":"previous-event","max":1} @UVDELTA={"vs":"[w4]","max":5}'), 'visit_form', 3, $REFS), 'w4');
    check('R8-4: a field that already compares with the previous event is offered the first (got ' . json_encode($e) . ')',
        $e === ['@UVDELTA on "w4": "vs" names this field itself — compare it with another entry, as "vs":"first-event".']);
    check('... which is accepted next to it', $errsOf($pageAt($R5('@UVDELTA={"vs":"previous-event","max":1} @UVDELTA={"vs":"first-event","max":5}'), 'visit_form', 3, $REFS), 'w4') === []);
    $e = $errsOf($pageAt($R5('@UVDELTA={"vs":"previous-event","max":1} @UVDELTA={"vs":"[event-name][w4]","max":5}'), 'visit_form', 3, $REFS), 'w4');
    check('R8-4: ... and so is a written reference (got ' . json_encode($e) . ')',
        $e === ['@UVDELTA on "w4": "vs" names this field in this same entry, so the change would always be 0 — compare it with another entry, as "[first-event-name][w4]".']);
    check('... which is accepted next to it', $errsOf($pageAt($R5('@UVDELTA={"vs":"previous-event","max":1} @UVDELTA={"vs":"[first-event-name][w4]","max":5}'), 'visit_form', 3, $REFS), 'w4') === []);
    $e = $errsOf($pageAt($R5('@UVDELTA={"vs":"previous-event","max":1,"when":"[flag]=\'1\'"} @UVDELTA={"vs":"[w4]","max":5}'), 'visit_form', 3, $REFS), 'w4');
    check('R8-4: a tag under another condition takes nothing (got ' . json_encode($e) . ')',
        $e === ['@UVDELTA on "w4": "vs" names this field itself — compare it with another entry, as "vs":"previous-event".']);
    $e = $errsOf($pageAt($R5('@UVDELTA={"vs":"previous-event","max":1,"direction":"up"} @UVDELTA={"vs":"[w4]","max":5}'), 'visit_form', 3, $REFS), 'w4');
    check('R8-4: nor does a tag that limits one direction only (got ' . json_encode($e) . ')',
        $e === ['@UVDELTA on "w4": "vs" names this field itself — compare it with another entry, as "vs":"previous-event".']);

    // ---- the ninth review (R9-1 to R9-9) ----
    $fieldErrs = function (array $dict, $field, $form, $evt, $ann) use ($errsOf, $pageAt, $REFS) {
        $dict[$field]['field_annotation'] = $ann;
        return $errsOf($pageAt($dict, $form, $evt, $REFS), $field);
    };
    $SELF9 = '@UVDELTA on "w9": "vs" names this field itself — compare it with another entry, as "vs":"';
    $SAME9 = '@UVDELTA on "w9": "vs" names this field in this same entry, so the change would always be 0 — compare it with another entry, as "';
    $T9 = ['record_id' => f('enrol_form'), 'w9' => f('visit_form', '', 'number')];
    // R9-1: two conditions are one when Branching says so: spacing, quote style,
    // parentheses and keyword case aside, and letter case unless a tag is caseSensitive.
    longitudinal();
    $W9 = ['record_id' => f('enrol_form'), 'flag' => f('visit_form', '', 'text'), 'w9' => f('visit_form', '', 'number')];
    foreach (['spacing' => ["[flag] = '1'", "[flag]='1'"], 'a leading space' => [" [flag]='1'", "[flag]='1'"],
              'quote style' => ["[flag]='1'", '[flag]=\"1\"'], 'parentheses' => ["([flag]='1')", "[flag]='1'"],
              'letter case' => ["[flag]='Yes'", "[flag]='yes'"],
              'keyword case' => ["[flag]='1' and [flag]<>'2'", "[flag]='1' AND [flag]<>'2'"]] as $how => $w) {
        $held = '@UVDELTA={"vs":"previous-event","max":1,"when":"' . $w[0] . '"} @UVDELTA=';
        $e = $fieldErrs($W9, 'w9', 'visit_form', 3, $held . '{"vs":"[w9]","max":5,"when":"' . $w[1] . '"}');
        check('R9-1: the same condition in other words (' . $how . '): the previous event is held, the first is offered (got ' . json_encode($e) . ')',
            $e === [$SELF9 . 'first-event".']);
        check('... which is accepted next to it', $fieldErrs($W9, 'w9', 'visit_form', 3, $held . '{"vs":"first-event","max":5,"when":"' . $w[1] . '"}') === []);
        $held = '@UVDELTA={"vs":"[previous-event-name][w9]","max":1,"when":"' . $w[0] . '"} @UVDELTA=';
        $e = $fieldErrs($W9, 'w9', 'visit_form', 3, $held . '{"vs":"[event-name][w9]","max":5,"when":"' . $w[1] . '"}');
        check('... and the dictionary hook offers the first event too (got ' . json_encode($e) . ')', $e === [$SAME9 . '[first-event-name][w9]".']);
        check('... which is accepted next to it', $fieldErrs($W9, 'w9', 'visit_form', 3, $held . '{"vs":"[first-event-name][w9]","max":5,"when":"' . $w[1] . '"}') === []);
    }
    // 'Yes' and 'yes' are two conditions when one tag is caseSensitive, but "Yes"
    // makes both true, a runtime conflict (review R10-1): the previous event is held.
    $held = '@UVDELTA={"vs":"previous-event","max":1,"when":"[flag]=\'Yes\'","caseSensitive":true} @UVDELTA=';
    $e = $fieldErrs($W9, 'w9', 'visit_form', 3, $held . '{"vs":"[w9]","max":5,"when":"[flag]=\'yes\'"}');
    check('R9-1, R10-1: \'Yes\' and \'yes\' when one tag is caseSensitive: both true for "Yes", so the previous event is held (got ' . json_encode($e) . ')',
        $e === [$SELF9 . 'first-event".']);
    check('... and the first event is accepted next to it', $fieldErrs($W9, 'w9', 'visit_form', 3, $held . '{"vs":"first-event","max":5,"when":"[flag]=\'yes\'"}') === []);

    // R9-2: a token that finds no event from some event of the field's form says something
    // else than the plain reference: arm 2 has no b_form, so there the first event reads
    // nothing, where the plain reference is refused.
    $twoBase = [1 => ['unique_event_name' => 'enrol_arm_1', 'arm_num' => 1], 5 => ['unique_event_name' => 'enrol_arm_2', 'arm_num' => 2]];
    $K9 = ['record_id' => f('enrol_form'), 'w_b' => f('b_form', '', 'number'), 'k9' => f('enrol_form', '@UVDELTA={"vs":"[w_b]","max":1}', 'number')];
    foreach (['held once' => [[], '[first-event-name][w_b]'], 'repeating' => [[1 => ['b_form' => '']], '[first-event-name][w_b][last-instance]']] as $how => $c) {
        $layout([1 => ['enrol_form', 'b_form'], 5 => ['enrol_form']], $c[0]);
        \Project::$eventInfoAll = $twoBase;
        $e = $errAt($K9, 'k9', 'enrol_form', 1);
        check('R9-2: b_form ' . $how . ' in arm 1 and absent from arm 2: the first event is offered (got ' . json_encode($e) . ')',
            $e === '@UVDELTA on "k9": "vs" names "[w_b]", but "w_b" is on the form "b_form", which is not designated at "enrol_arm_2" where this field is entered' . $sug($c[1]));
        check('... which is accepted', $roundTrip($K9, 'k9', 'enrol_form', 1, $e, $FOUR, $REFS));
    }

    // R9-3: the shorthand refusal's example keeps the shorthand's own event, with an
    // instance, or gives none: another event would be another entry.
    $layout([1 => ['enrol_form'], 2 => ['visit_form'], 3 => ['visit_form'], 4 => ['visit_form']], [3 => ['visit_form' => '']]);
    $e = $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"previous-event","max":1}');
    check('R9-3: "previous-event" names a repeating visit that comes after one held once: no example (got ' . json_encode($e) . ')',
        $e === ['@UVDELTA on "w9": "vs": "previous-event" names "visit2_arm_1", where "visit_form" repeats, and does not say which instance to compare with — name the event and the instance.']);
    longitudinal([2 => ['visit_form' => ''], 3 => ['visit_form' => ''], 4 => ['visit_form' => '']]);
    $e = $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"first-event","max":1}');
    check('R9-3: "first-event" where every visit repeats: the first event with its first instance, not the previous event (got ' . json_encode($e) . ')',
        $e === ['@UVDELTA on "w9": "vs": "first-event" names "visit1_arm_1", where "visit_form" repeats, and does not say which instance to compare with — name the event and the instance, as "vs":"[first-event-name][w9][first-instance]".']);
    check('... which is accepted', $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"[first-event-name][w9][first-instance]","max":1}') === []);
    $held = '@UVDELTA={"vs":"[previous-event-name][w9][last-instance]","max":1} @UVDELTA=';
    $e = $fieldErrs($T9, 'w9', 'visit_form', 3, $held . '{"vs":"previous-event","max":5}');
    check('R9-3, R9-9 N5: ... and outside the group another tag holds (got ' . json_encode($e) . ')',
        $e === ['@UVDELTA on "w9": "vs": "previous-event" names "visit1_arm_1" and "visit2_arm_1", where "visit_form" repeats, and does not say which instance to compare with — name the event and the instance, as "vs":"[previous-event-name][w9][first-instance]".']);
    check('... which is accepted next to it', $fieldErrs($T9, 'w9', 'visit_form', 3, $held . '{"vs":"[previous-event-name][w9][first-instance]","max":5}') === []);

    // R9-4: the event as written goes by the rule the others do: the next event is not
    // kept, nor, on the field's own form, the last event or a later visit named outright.
    $layout([1 => ['enrol_form', 'b_form'], 2 => ['visit_form', 'b_form'], 3 => ['visit_form', 'b_form'], 4 => ['visit_form', 'b_form']], []);
    $K9 = ['record_id' => f('enrol_form'), 'wv' => f('visit_form', '', 'number'), 'w_b' => f('b_form', '', 'number'),
        'kn' => f('visit_form', '@UVDELTA={"vs":"[next-event-name][wv][1]","max":1}', 'number'),
        'kl' => f('visit_form', '@UVDELTA={"vs":"[last-event-name][kl][1]","max":1}', 'number'),
        'kv' => f('visit_form', '@UVDELTA={"vs":"[visit3_arm_1][kv][1]","max":1}', 'number'),
        'k1' => f('visit_form', '@UVDELTA={"vs":"[visit1_arm_1][k1][1]","max":1}', 'number'),
        'kb' => f('enrol_form', '@UVDELTA={"vs":"[next-event-name][w_b][1]","max":1}', 'number')];
    foreach (['kn' => ['visit_form', 3, '[previous-event-name][wv]', 'the next event, another field of its own form'],
              'kl' => ['visit_form', 3, '[previous-event-name][kl]', 'the last event, the field itself'],
              'kv' => ['visit_form', 3, '[previous-event-name][kv]', 'a later visit named outright, the field itself'],
              'k1' => ['visit_form', 3, '[visit1_arm_1][k1]', 'the first visit named outright, the field itself, is kept'],
              'kb' => ['enrol_form', 1, '[visit1_arm_1][w_b]', 'the next event, another form']] as $kf => $c) {
        $e = $errAt($K9, $kf, $c[0], $c[1]);
        check('R9-4: ' . $c[3] . ' (got ' . json_encode($e) . ')', strpos($e, 'and an instance can be named only where the form repeats') !== false
            && substr($e, -strlen($sug($c[2]))) === $sug($c[2]));
        check('... which is accepted', $roundTrip($K9, $kf, $c[0], $c[1], $e, $FOUR, $REFS));
    }

    // R9-5: for the field itself [next-instance] fits nowhere, [current-instance] not at
    // another event, and an instance numbered after the first not at its own event.
    classic();
    \Project::$repeats = [351 => ['visit_form' => '']];
    $I9 = ['record_id' => f('enrol_form'),
        'in' => f('visit_form', '@UVDELTA={"vs":"[previous-event-name][in][next-instance]","max":1}', 'number'),
        'i2' => f('visit_form', '@UVDELTA={"vs":"[previous-event-name][i2][2]","max":1}', 'number')];
    $pc = page(mod($I9, ['2' => [351 => ['record_id' => '2']]], $FULL, $REFS), 'form', '2', 'visit_form');
    foreach (['in' => '[previous-event-name][in][next-instance]', 'i2' => '[previous-event-name][i2][2]'] as $sf => $w) {
        $e = ruleFor($pc, $sf)['configError'] ?? '';
        check('R9-5: one event where the form repeats: ' . $w . ' gets the previous instance (got ' . json_encode($e) . ')',
            $e === '@UVDELTA on "' . $sf . '": "vs" names "' . $w . '", but from no event where this field is entered does "[previous-event-name]" find an event holding "visit_form", '
            . 'so nothing would ever be compared' . $sug('[' . $sf . '][previous-instance]'));
    }
    longitudinal([2 => ['visit_form' => ''], 3 => ['visit_form' => ''], 4 => ['visit_form' => '']]);
    $I9 = ['record_id' => f('enrol_form'),
        'in' => f('visit_form', '@UVDELTA={"vs":"[enrol_arm_1][in][next-instance]","max":1}', 'number'),
        'ic' => f('visit_form', '@UVDELTA={"vs":"[enrol_arm_1][ic][current-instance]","max":1}', 'number')];
    foreach (['in' => 'next', 'ic' => 'current'] as $sf => $how) {
        $e = $errAt($I9, $sf, 'visit_form', 3);
        check('R9-5: every visit repeats: the ' . $how . ' instance as written is dropped, the previous visit\'s last instance offered (got ' . json_encode($e) . ')',
            substr($e, -strlen($sug('[previous-event-name][' . $sf . '][last-instance]'))) === $sug('[previous-event-name][' . $sf . '][last-instance]'));
        check('... which is accepted', $roundTrip($I9, $sf, 'visit_form', 3, $e, $FOUR, $REFS));
    }

    // R9-7: each suggestion is checked against what the tags before it will hold once
    // their own suggestions are written in; a tag that failed to parse holds the group
    // its JSON names.
    longitudinal();
    $e = $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"[event-name][w9]","max":1} @UVDELTA={"vs":"[w9]","maxPct":10}');
    check('R9-7: two refused tags: the second is offered what the first will not hold (got ' . json_encode($e) . ')',
        $e === [$SAME9 . '[previous-event-name][w9]".', $SELF9 . 'first-event".']);
    check('... and both written back are accepted',
        $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"[previous-event-name][w9]","max":1} @UVDELTA={"vs":"first-event","maxPct":10}') === []);
    $e = $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"[w9]","max":1} @UVDELTA={"vs":"[event-name][w9]","maxPct":10}');
    check('R9-7: ... and the other way round, the field hook refusing the first (got ' . json_encode($e) . ')',
        $e === [$SELF9 . 'previous-event".', $SAME9 . '[first-event-name][w9]".']);
    check('... both written back are accepted',
        $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"previous-event","max":1} @UVDELTA={"vs":"[first-event-name][w9]","maxPct":10}') === []);
    $e = $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"previous-event","max":"1 kg"} @UVDELTA={"vs":"[w9]","max":5}');
    check('R9-7: a tag that failed to parse holds the group its "vs" names (got ' . json_encode($e) . ')',
        count($e) === 2 && $e[1] === $SELF9 . 'first-event".');
    check('... and the suggestion is accepted once the other tag is fixed',
        $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"previous-event","max":1} @UVDELTA={"vs":"first-event","max":5}') === []);

    // R9-9: the groups the other tags hold reach every path that suggests a reference.
    // N8, N39: the field hook's shorthand, both tags limiting one direction.
    $e = $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"previous-event","max":1,"direction":"up"} @UVDELTA={"vs":"[w9]","max":5,"direction":"up"}');
    check('R9-9 N8, N39: a shorthand another tag holds in the same direction is passed over (got ' . json_encode($e) . ')', $e === [$SELF9 . 'first-event".']);
    $e = $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"[w9]","max":5} @UVDELTA={"vs":"previous-event","max":1,"direction":"up"}');
    check('R8-4, R9-7: a tag after the refused one, limiting one direction, holds its own group only (got ' . json_encode($e) . ')', $e === [$SELF9 . 'previous-event".']);
    // On its own form the one event named outright is the first that holds it (review
    // R8-7): with the previous event, the first event and that one held, nothing is left.
    $e = $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"previous-event","max":1} @UVDELTA={"vs":"first-event","max":2} '
        . '@UVDELTA={"vs":"[visit1_arm_1][w9]","max":3} @UVDELTA={"vs":"[w9]","max":5}');
    // word for word: the rule deltaGroupsTaken applies, as the docs say it (reviews R11-2, R12-1)
    $HELD10 = ' (an entry another @UVDELTA tag on this field compares with in the same direction is not suggested, '
        . 'unless just one of the two tags has a "when", or each "when" tests one field, written without an event or instance '
        . '(each checkbox box counts as its own field), for equality with a value, alone or as part of an "and", '
        . 'and no value equals both as conditions compare values).';
    check('R8-7, R10-1: no later visit named outright once the earlier references are held, and the refusal says why (got ' . json_encode($e) . ')',
        $e === ['@UVDELTA on "w9": "vs" names this field itself — compare it with another entry' . $HELD10]);
    // N3: the field hook's written reference, where visit_form repeats at visit 1 only
    // and no shorthand fits (M1's layout).
    $layout([1 => ['enrol_form'], 2 => ['visit_form'], 3 => ['visit_form']], [2 => ['visit_form' => '']]);
    $held = '@UVDELTA={"vs":"[previous-event-name][w9][last-instance]","max":1} @UVDELTA=';
    $e = $fieldErrs($T9, 'w9', 'visit_form', 3, $held . '{"vs":"[w9]","max":5}');
    check('R9-9 N3: the field hook\'s written reference passes over the one another tag holds (got ' . json_encode($e) . ')',
        $e === [$SELF9 . '[previous-event-name][w9][first-instance]".']);
    check('... which is accepted next to it', $fieldErrs($T9, 'w9', 'visit_form', 3, $held . '{"vs":"[previous-event-name][w9][first-instance]","max":5}') === []);
    // N4, N7, N40: the dictionary hook's refusal of a reference it cannot read.
    longitudinal([2 => ['visit_form' => ''], 3 => ['visit_form' => ''], 4 => ['visit_form' => '']]);
    foreach (['' => 'N4', ',"direction":"up"' => 'N4, N7, N40, direction up'] as $dir => $how) {
        $held = '@UVDELTA={"vs":"[previous-event-name][w9][last-instance]","max":1' . $dir . '} @UVDELTA=';
        $e = $fieldErrs($T9, 'w9', 'visit_form', 3, $held . '{"vs":"[previous-event-name][w9]","max":5' . $dir . '}');
        check('R9-9 ' . $how . ': a reference with no instance gets the instance the other tag does not hold (got ' . json_encode($e) . ')',
            $e === ['@UVDELTA on "w9": "vs" names "[previous-event-name][w9]" with no instance, but its form "visit_form" repeats at "visit1_arm_1", "visit2_arm_1", '
            . 'so which instance it means is not said — write it as "[previous-event-name][w9][first-instance]".']);
        check('... which is accepted next to it', $fieldErrs($T9, 'w9', 'visit_form', 3, $held . '{"vs":"[previous-event-name][w9][first-instance]","max":5' . $dir . '}') === []);
    }
    // N43: another form's field gets up to five events named outright. visit_form is at
    // visit 1 only, where the first event holding b_form is visit 1 itself, left to the
    // plain reference; the next one is named.
    $layout([1 => ['enrol_form'], 2 => ['visit_form', 'b_form'], 3 => ['a_form'], 4 => ['b_form']], []);
    $N9 = ['record_id' => f('enrol_form'), 'w_b' => f('b_form', '', 'number'), 'n9' => f('visit_form', '@UVDELTA={"vs":"[enrol_arm_1][w_b]","max":1}', 'number')];
    $e = $errAt($N9, 'n9', 'visit_form', 2);
    check('R9-9 N43: another form: the second event holding it, named outright (got ' . json_encode($e) . ')',
        $e === '@UVDELTA on "n9": "vs" names "[enrol_arm_1][w_b]", but "w_b" is on the form "b_form", which is not designated at "enrol_arm_1"' . $sug('[visit3_arm_1][w_b]'));
    check('... which is accepted', $roundTrip($N9, 'n9', 'visit_form', 2, $e, $FOUR, $REFS));

    // ---- the tenth review (R10-1 to R10-4) ----
    // R10-1: a tag with a "when" meets every other tag with one, unless the two
    // conditions can never be true at once: two that are true together validate
    // nothing (a runtime conflict). Only equality tests of one plain field against
    // values no value equals both count as never true at once.
    longitudinal();
    $X10 = ['record_id' => f('enrol_form'), 'flag' => f('visit_form', '', 'text'), 'y' => f('visit_form', '', 'text'), 'w9' => f('visit_form', '', 'number')];
    $whenTag = function ($vs, $max, $when, $extra = '') { return '@UVDELTA={"vs":"' . $vs . '","max":' . $max . ',"when":"' . $when . '"' . $extra . '}'; };
    $CS = ',"caseSensitive":true';
    foreach ([
        'overlapping, [flag] > 0 and [flag] = 1' => ["[flag] > 0", "[flag] = 1", 'first-event'],
        'numbers by value, 1 and 01' => ["[flag] = 1", "[flag] = '01'", 'first-event'],
        'numbers by value, 1 and 1.0' => ["[flag] = 1", "[flag] = 1.0", 'first-event'],
        'a space inside the quotes' => ["[flag] = '1'", "[flag] = ' 1'", 'first-event'],
        '<> is not an equality test' => ["[flag] <> '1'", "[flag] = '2'", 'first-event'],
        'an "or" needs nothing' => ["[flag] = '1' or [y] = '1'", "[flag] = '2'", 'first-event'],
        'a field at another event' => ["[visit1_arm_1][flag] = '1'", "[flag] = '2'", 'first-event'],
        'one condition, never true, said twice' => ["[flag] = '1' and [flag] = '2'", "[flag] = '2' and [flag] = '1'", 'first-event'],
        'never true at once, 1 and 2' => ["[flag] = '1'", "[flag] = '2'", 'previous-event'],
        'never true at once, either way round' => ["'1' = [flag]", "[flag] = '2'", 'previous-event'],
        'never true at once, members of an "and"' => ["[flag] = '1' and [y] = '1'", "[y] = '3' and [flag] = '2'", 'previous-event'],
        'never true at once, a number and a text' => ["[flag] = 1", "[flag] = 'a'", 'previous-event'],
        "never true at once, 'Yes' and 'yes' when both tags are caseSensitive" => ["[flag] = 'Yes'", "[flag] = 'yes'", 'previous-event', $CS, $CS],
    ] as $how => $c) {
        $held = $whenTag('previous-event', 1, $c[0], isset($c[3]) ? $c[3] : '') . ' ';
        $own = isset($c[4]) ? $c[4] : '';
        $e = $fieldErrs($X10, 'w9', 'visit_form', 3, $held . $whenTag('[w9]', 5, $c[1], $own));
        check('R10-1: ' . $how . ': ' . $c[2] . ' is offered (got ' . json_encode($e) . ')', $e === [$SELF9 . $c[2] . '".']);
        check('... which is accepted next to it', $fieldErrs($X10, 'w9', 'visit_form', 3, $held . $whenTag($c[2], 5, $c[1], $own)) === []);
    }
    $e = $fieldErrs($X10, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"previous-event","max":1} ' . $whenTag('[w9]', 5, "[flag] = '1'"));
    check('R10-1: a tag without a "when" is the else branch of a conditional one: the previous event is offered (got ' . json_encode($e) . ')',
        $e === [$SELF9 . 'previous-event".']);
    // ... and at run time conditions that are never true at once pick one branch each
    $X10['w9']['field_annotation'] = $whenTag('previous-event', 1, "[flag] = '1'") . ' ' . $whenTag('previous-event', 60, "[flag] = '2'");
    foreach (['1' => 'up', '2' => null] as $flag => $reason) {
        $m = mod($X10, ['2' => [2 => ['w9' => '50', 'flag' => $flag], 3 => ['w9' => '100', 'flag' => $flag]]], $FOUR, $REFS);
        $m->redcap_save_record(PID, '2', 'visit_form', 3, null, null, null, 1);
        check('R10-1: run time, flag ' . $flag . ': one branch validates, no conflict (got ' . json_encode([deltaBy($m, 3), problems($m, 'w9')]) . ')',
            (deltaBy($m, 3)['w9']['reason'] ?? null) === $reason && !problems($m, 'w9'));
    }

    // R10-2: a tag that failed to parse is read leniently for the group it holds;
    // one whose "vs" cannot be read at all leaves no suggestion beside it.
    foreach (['single quotes' => "{'vs':'previous-event','max':1}",
              'a trailing comma' => '{"vs":"previous-event","max":1,}',
              'curly quotes' => "{\u{201C}vs\u{201D}:\u{201C}previous-event\u{201D},\u{201C}max\u{201D}:1}",
              'curly quotes around one value' => "{\"vs\":\"previous-event\",\"unit\":\u{201C}kg\u{201D},\"max\":1}",
              'single quotes, and an apostrophe in a double-quoted message' => "{'vs':'previous-event','message':\"don't\",'max':1}",
              'single quotes around a message in double quotes' => "{'vs':'previous-event','message':'a \"big\" change','max':1}",
              'single quotes, and a "when"' => "{'vs':'previous-event','max':1,'when':\"[flag]='1'\"}"] as $how => $bad) {
        $own = strpos($bad, 'when') !== false ? ',"when":"[flag]=\'1\'"' : '';
        $e = $fieldErrs($X10, 'w9', 'visit_form', 3, '@UVDELTA=' . $bad . ' @UVDELTA={"vs":"[w9]","max":5' . $own . '}');
        check('R10-2: a tag with ' . $how . ' holds the group its "vs" names (got ' . json_encode($e) . ')',
            count($e) === 2 && $e[1] === $SELF9 . 'first-event".');
    }
    $UNREAD10 = ' (nothing is suggested while another @UVDELTA tag on this field cannot be read; fix that tag first).';
    foreach (['bare words' => '@UVDELTA={vs:previous-event,max:1}', 'no value' => '@UVDELTA', 'no "vs"' => '@UVDELTA={"max":1}'] as $how => $bad) {
        $e = $fieldErrs($T9, 'w9', 'visit_form', 3, $bad . ' @UVDELTA={"vs":"[w9]","max":5}');
        check('R10-2: a tag whose "vs" cannot be read (' . $how . '): nothing is offered beside it, and the refusal says why (got ' . json_encode($e) . ')',
            count($e) === 2 && $e[1] === '@UVDELTA on "w9": "vs" names this field itself — compare it with another entry' . $UNREAD10);
    }
    $e = $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={vs:x} @UVDELTA={"vs":"[event-name][w9]","max":5}');
    check('R10-2: ... the same-entry refusal too (got ' . json_encode($e) . ')',
        count($e) === 2 && $e[1] === '@UVDELTA on "w9": "vs" names this field in this same entry, so the change would always be 0 — compare it with another entry' . $UNREAD10);
    // R10-1 says why too: the earlier references are held by the other tags.
    $e = $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"previous-event","max":1} @UVDELTA={"vs":"first-event","max":2} '
        . '@UVDELTA={"vs":"[visit1_arm_1][w9]","max":3} @UVDELTA={"vs":"[event-name][w9]","max":5}');
    check('R10-1: ... and the same-entry refusal says when the other tags hold every entry it could offer (got ' . json_encode($e) . ')',
        $e === ['@UVDELTA on "w9": "vs" names this field in this same entry, so the change would always be 0 — compare it with another entry' . $HELD10]);
    longitudinal([2 => ['visit_form' => ''], 3 => ['visit_form' => ''], 4 => ['visit_form' => '']]);
    $REPWHY = '"vs" names "[previous-event-name][w9]" with no instance, but its form "visit_form" repeats at "visit1_arm_1", "visit2_arm_1", so which instance it means is not said';
    $e = $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={vs:x} @UVDELTA={"vs":"[previous-event-name][w9]","max":5}');
    check('R10-2: ... the refusal of a reference it cannot read says only why (got ' . json_encode($e) . ')',
        count($e) === 2 && $e[1] === '@UVDELTA on "w9": ' . $REPWHY . $UNREAD10);
    $SHORTWHY = '"vs": "previous-event" names "visit1_arm_1" and "visit2_arm_1", where "visit_form" repeats, and does not say which instance to compare with — name the event and the instance';
    $e = $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={vs:x} @UVDELTA={"vs":"previous-event","max":5}');
    check('R10-2: ... and so does the shorthand refusal (got ' . json_encode($e) . ')', count($e) === 2 && $e[1] === '@UVDELTA on "w9": ' . $SHORTWHY . $UNREAD10);
    $e = $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"[previous-event-name][w9][last-instance]","max":1} '
        . '@UVDELTA={"vs":"[previous-event-name][w9][first-instance]","max":2} @UVDELTA={"vs":"previous-event","max":5}');
    check('R10-1: the shorthand refusal says when the other tags hold both instances it could offer (got ' . json_encode($e) . ')',
        $e === ['@UVDELTA on "w9": ' . $SHORTWHY . $HELD10]);
    classic();
    \Project::$repeats = [351 => ['visit_form' => '']];
    $DEADWHY = '"vs" names "[previous-event-name][w9]", but from no event where this field is entered does "[previous-event-name]" find an event holding "visit_form", '
        . 'so nothing would ever be compared — and none of the references to "[w9]" this check tried (with no instance, the last, the previous or the first one) works';
    $e = $errsOf(page(mod(['record_id' => f('enrol_form'), 'w9' => f('visit_form', '@UVDELTA={"vs":"previous-instance","max":1} '
        . '@UVDELTA={"vs":"first-instance","max":2} @UVDELTA={"vs":"[previous-event-name][w9]","max":5}', 'number')],
        ['2' => [351 => ['record_id' => '2']]], $FULL, $REFS), 'form', '2', 'visit_form'), 'w9');
    check('R10-1: a reference it cannot read whose instances the other tags hold: none works, and why (got ' . json_encode($e) . ')',
        $e === ['@UVDELTA on "w9": ' . $DEADWHY . $HELD10]);
    \Project::$repeats = [];
    $e = $errsOf(page(mod(['record_id' => f('enrol_form'), 'w9' => f('visit_form', '@UVDELTA={"vs":"previous-instance","max":1} @UVDELTA={"vs":"[w9]","max":5}', 'number')],
        ['2' => [351 => ['record_id' => '2']]], $FULL, $REFS), 'form', '2', 'visit_form'), 'w9');
    check('R10-1: a held shorthand that would not fit anyway is not given as the reason (got ' . json_encode($e) . ')',
        count($e) === 2 && $e[1] === '@UVDELTA on "w9": "vs" names this field itself — compare it with another entry.');

    // R10-3: each tag's answer is worked out once for its place, and afresh for each
    // build of the rules.
    longitudinal();
    $e = $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"[w9]","max":1} @UVDELTA={"vs":"[w9]","max":1}');
    check('R10-3: two tags written the same are told apart by their place (got ' . json_encode($e) . ')',
        $e === [$SELF9 . 'previous-event".', $SELF9 . 'first-event".']);
    $m = mod(['record_id' => f('enrol_form'), 'w9' => f('visit_form', '@UVDELTA={"vs":"[w9]","max":1}', 'number')],
        ['2' => [1 => ['record_id' => '2']]], $FOUR, []);
    $off = ruleFor(page($m, 'form', '2', 'visit_form', 3), 'w9')['configError'] ?? '';
    $m->projectSettings['enable-event-instance-refs'] = true;
    $on = ruleFor(page($m, 'form', '2', 'visit_form', 3), 'w9')['configError'] ?? '';
    check('R10-3: the rules built again with references on are worked out again (got ' . json_encode([$off, $on]) . ')',
        $off === $SELF9 . 'previous-event" (this needs event and instance references, a project setting).' && $on === $SELF9 . 'previous-event".');
    $two = ['record_id' => f('enrol_form'), 'wa' => f('visit_form', '@UVDELTA={"vs":"previous-event","max":1} @UVDELTA={"vs":"[wa]","max":5}', 'number'),
        'wb' => f('visit_form', '@UVDELTA={"vs":"[wb]","max":5}', 'number')];
    $pg = $pageAt($two, 'visit_form', 3, $REFS);
    check('R10-3: each field\'s tags are its own (got ' . json_encode([$errsOf($pg, 'wa'), $errsOf($pg, 'wb')]) . ')',
        $errsOf($pg, 'wa') === ['@UVDELTA on "wa": "vs" names this field itself — compare it with another entry, as "vs":"first-event".']
        && $errsOf($pg, 'wb') === ['@UVDELTA on "wb": "vs" names this field itself — compare it with another entry, as "vs":"previous-event".']);

    // R10-4: what a tag that failed to parse holds, key by key (the reviewer's X1-X5).
    foreach (['its "when" (X1)' => ['{"vs":"previous-event","max":"x","when":"[flag]=\'1\'"}', '{"vs":"[w9]","max":5,"when":"[flag]=\'1\'"}', 'first-event'],
              'its direction (X2)' => ['{"vs":"previous-event","max":"x","direction":"up"}', '{"vs":"[w9]","max":5,"direction":"up"}', 'first-event'],
              'its caseSensitive (X3)' => ['{"vs":"previous-event","max":"x","when":"[flag]=\'Yes\'","caseSensitive":true}',
                  '{"vs":"[w9]","max":5,"when":"[flag]=\'yes\'","caseSensitive":true}', 'previous-event'],
              'a shorthand in capitals (X4)' => ['{"vs":"PREVIOUS-EVENT","max":"x"}', '{"vs":"[w9]","max":5}', 'first-event'],
              'a caseSensitive that is not true as ignoring case' => ['{"vs":"previous-event","max":1,"when":"[flag]=\'Yes\'","caseSensitive":"yes"}',
                  '{"vs":"[w9]","max":5,"when":"[flag]=\'yes\'","caseSensitive":true}', 'first-event']] as $how => $c) {
        $e = $fieldErrs($X10, 'w9', 'visit_form', 3, '@UVDELTA=' . $c[0] . ' @UVDELTA=' . $c[1]);
        check('R10-4: a tag that failed to parse keeps ' . $how . ' (got ' . json_encode($e) . ')', count($e) === 2 && $e[1] === $SELF9 . $c[2] . '".');
    }
    $e = $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"first-event","max":1} @UVDELTA={"vs":"previous-event","max":"x"} @UVDELTA={"vs":"[w9]","max":5}');
    check('R10-4 X5: a tag that failed to parse, second of three, is read from its own value (got ' . json_encode($e) . ')',
        count($e) === 2 && $e[1] === $SELF9 . '[visit1_arm_1][w9]".');
    // X9, X10: a refused reference and a refused shorthand hand their suggestions on.
    longitudinal([2 => ['visit_form' => ''], 3 => ['visit_form' => ''], 4 => ['visit_form' => '']]);
    $e = $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"[previous-event-name][w9]","max":1} @UVDELTA={"vs":"[previous-event-name][w9]","max":5}');
    check('R10-4 X9: two references with no instance: the second gets the instance the first will not hold (got ' . json_encode($e) . ')',
        $e === ['@UVDELTA on "w9": ' . $REPWHY . ' — write it as "[previous-event-name][w9][last-instance]".',
                '@UVDELTA on "w9": ' . $REPWHY . ' — write it as "[previous-event-name][w9][first-instance]".']);
    $e = $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"previous-event","max":1} @UVDELTA={"vs":"[previous-event-name][w9]","max":5}');
    check('R10-4 X10: a refused shorthand, then a reference with no instance: the reference gets the other instance (got ' . json_encode($e) . ')',
        $e === ['@UVDELTA on "w9": ' . $SHORTWHY . ', as "vs":"[previous-event-name][w9][last-instance]".',
                '@UVDELTA on "w9": ' . $REPWHY . ' — write it as "[previous-event-name][w9][first-instance]".']);
    check('... both written back are accepted', $fieldErrs($T9, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"[previous-event-name][w9][last-instance]","max":1} '
        . '@UVDELTA={"vs":"[previous-event-name][w9][first-instance]","max":5}') === []);

    // ---- the eleventh review (R11-1 to R11-3) ----
    // R11-1: a tag that failed to parse and names a key the parser does not know (letter
    // case counts), or has a "vs", "direction" or "when" that is not a text or that the
    // parser refuses, could hold any group or condition once fixed: nothing is offered
    // beside it until it is fixed.
    longitudinal();
    $X11 = $X10 + ['z' => f('visit_form', '', 'text'), 'w_fa' => f('enrol_form', '', 'number'),
        'cb' => f('visit_form', '', '', 'checkbox') + ['select_choices_or_calculations' => '1, A | 2, B']];
    $UNREAD11 = '@UVDELTA on "w9": "vs" names this field itself — compare it with another entry' . $UNREAD10;
    $ONE = ',"when":"[flag] = \'1\'"';
    foreach (['a "when" that is not a text' => ['{"vs":"previous-event","max":1,"when":["[flag] > 0"]}', $ONE, '{"vs":"previous-event","max":1,"when":"[flag] > 0"}'],
              'a "when" of true' => ['{"vs":"previous-event","max":1,"when":true}', $ONE, '{"vs":"previous-event","max":1,"when":"[flag] > 0"}'],
              'a "when" that does not parse' => ['{"vs":"previous-event","max":1,"when":"[flag] > > 0"}', $ONE, '{"vs":"previous-event","max":1,"when":"[flag] > 0"}'],
              '"When" in capitals' => ['{"vs":"previous-event","max":1,"When":"[flag] > 0"}', $ONE, '{"vs":"previous-event","max":1,"when":"[flag] > 0"}'],
              'a "direction" that is not a text' => ['{"vs":"previous-event","max":1,"direction":["up"]}', ',"direction":"up"', '{"vs":"previous-event","max":1,"direction":"up"}'],
              'a "direction" the parser refuses' => ['{"vs":"previous-event","max":1,"direction":"upward"}', ',"direction":"up"', '{"vs":"previous-event","max":1,"direction":"up"}'],
              '"Direction" in capitals' => ['{"vs":"previous-event","max":1,"Direction":"up"}', ',"direction":"up"', '{"vs":"previous-event","max":1,"direction":"up"}'],
              'a shorthand misspelt' => ['{"vs":"previous_event","max":1}', '', '{"vs":"previous-event","max":1}'],
              'a blank "vs" (R11-3 P)' => ['{"vs":" ","max":1}', '', '{"vs":"previous-event","max":1}'],
              'a "vs" missing a bracket' => ['{"vs":"[visit1_arm_1][w9","max":1}', '', '{"vs":"[previous-event-name][w9]","max":1}']] as $how => $c) {
        $e = $fieldErrs($X11, 'w9', 'visit_form', 3, '@UVDELTA=' . $c[0] . ' @UVDELTA={"vs":"[w9]","max":5' . $c[1] . '}');
        check('R11-1: beside a tag that failed to parse with ' . $how . ' nothing is offered, and the refusal says why (got ' . json_encode($e) . ')',
            count($e) === 2 && $e[1] === $UNREAD11);
        $e = $fieldErrs($X11, 'w9', 'visit_form', 3, '@UVDELTA=' . $c[2] . ' @UVDELTA={"vs":"[w9]","max":5' . $c[1] . '}');
        check('... with that tag fixed, the first event is offered (got ' . json_encode($e) . ')', $e === [$SELF9 . 'first-event".']);
        check('... which is accepted next to it',
            $fieldErrs($X11, 'w9', 'visit_form', 3, '@UVDELTA=' . $c[2] . ' @UVDELTA={"vs":"first-event","max":5' . $c[1] . '}') === []);
    }
    // a "when" or "vs" that names another event is read as written: references fix it
    $e = $fieldErrs($X11, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"previous-event","max":"x","when":"[visit1_arm_1][flag] = \'1\'"} @UVDELTA={"vs":"[w9]","max":5' . $ONE . '}');
    check('R11-1: a tag that failed to parse with a "when" naming another event holds its group (got ' . json_encode($e) . ')',
        count($e) === 2 && $e[1] === $SELF9 . 'first-event".');
    // the reviewer's case: a reference to another form, refused by the dictionary hook
    $e = $fieldErrs($X11, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"[w_fa]","max":1} @UVDELTA={"vs":"[enrol_arm_1][w_fa","max":2}');
    check('R11-1: a reference refused beside a tag whose "vs" misses a bracket: nothing is offered (got ' . json_encode($e) . ')',
        count($e) === 2 && $e[0] === '@UVDELTA on "w9": "vs" names "[w_fa]", but "w_fa" is on the form "enrol_form", which is not designated at '
        . '"visit1_arm_1", "visit2_arm_1", "visit3_arm_1" where this field is entered' . $UNREAD10);
    $e = $fieldErrs($X11, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"[w_fa]","max":1} @UVDELTA={"vs":"[enrol_arm_1][w_fa]","max":2}');
    check('... with that tag fixed, another reference is offered (got ' . json_encode($e) . ')', count($e) === 1 && strpos($e[0], ' — write it as "') !== false);
    $fixed = count($e) === 1 && preg_match('/write it as "([^"]+)"\.$/', $e[0], $mm) ? $mm[1] : '?';
    check('... which is accepted next to it (' . $fixed . ')',
        $fieldErrs($X11, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"' . $fixed . '","max":1} @UVDELTA={"vs":"[enrol_arm_1][w_fa]","max":2}') === []);

    // R11-2: the refusal that suggests nothing because other tags hold every entry says
    // the rule the search applies, as the docs say it ($HELD10): conditions that never
    // hold at once but are not equality tests still meet.
    $e =$fieldErrs($X11, 'w9', 'visit_form', 3, $whenTag('previous-event', 1, '[flag] < 3') . ' ' . $whenTag('first-event', 1, '[flag] < 3') . ' '
        . $whenTag('[visit1_arm_1][w9]', 1, '[flag] < 3') . ' ' . $whenTag('[event-name][w9]', 1, '[flag] > 5'));
    check('R11-2: conditions that never hold at once but are not equality tests: nothing offered, and the note says why (got ' . json_encode($e) . ')',
        $e === ['@UVDELTA on "w9": "vs" names this field in this same entry, so the change would always be 0 — compare it with another entry' . $HELD10]);

    // R11-3 A, B, C, E: what Branching::whenExclusive reads from each condition.
    foreach ([
        'two boxes of one checkbox (A)' => ["[cb(1)] = '1'", "[cb(2)] = '0'", 'first-event'],
        'one box of a checkbox, ticked and not' => ["[cb(1)] = '1'", "[cb(1)] = '0'", 'previous-event'],
        'a field compared with a field (B)' => ['[flag] = [y]', "[flag] = '1'", 'first-event'],
        'an "and" inside an "and" (C)' => ["([flag] = '1' and [y] = '1') and [z] = '1'", "[flag] = '2'", 'previous-event'],
        'one field tested twice, never true (E)' => ["[flag] = '1' and [flag] = '2'", "[flag] = '1'", 'previous-event'],
    ] as $how => $c) {
        $held = $whenTag('previous-event', 1, $c[0]) . ' ';
        $e = $fieldErrs($X11, 'w9', 'visit_form', 3, $held . $whenTag('[w9]', 5, $c[1]));
        check('R11-3: ' . $how . ': ' . $c[2] . ' is offered (got ' . json_encode($e) . ')', $e === [$SELF9 . $c[2] . '".']);
        check('... which is accepted next to it', $fieldErrs($X11, 'w9', 'visit_form', 3, $held . $whenTag($c[2], 5, $c[1])) === []);
    }
    // R11-3 D: a condition that does not parse may overlap any other. A tag that failed
    // on one cannot be read at all (R11-1), so only a direct call reaches this.
    check('R11-3 D: a condition that does not parse is never exclusive',
        \INSPIRE\UniversalValidator\Branching::whenExclusive(['when' => "[flag] = = '1'"], ['when' => "[flag] = '2'"]) === false
        && \INSPIRE\UniversalValidator\Branching::whenExclusive(['when' => "[flag] = '2'"], ['when' => "[flag] = = '1'"]) === false);

    // R11-3 F, G, H, I, N: the lenient read of a tag that failed to parse.
    foreach (['an escaped quote, then an apostrophe, in a double-quoted message (F)' => "{'vs':'previous-event','max':1,'message':\"say \\\"it's\\\" ok\"}",
              'a trailing comma before ] (G)' => '{"vs":"previous-event","max":1,"references":["flag",]}',
              'single curly quotes (H)' => "{\u{2018}vs\u{2019}:\u{2018}previous-event\u{2019},\u{2018}max\u{2019}:1}",
              'a trailing comma and a space before } (I)' => '{"vs":"previous-event","max":1, }',
              'a single curly quote inside double curly quotes (N)' => "{\"vs\":\"previous-event\",\"max\":1,\"message\":\u{201C}it\u{2018}s\u{201D}}"] as $how => $bad) {
        $e = $fieldErrs($X11, 'w9', 'visit_form', 3, '@UVDELTA=' . $bad . ' @UVDELTA={"vs":"[w9]","max":5}');
        check('R11-3: a tag with ' . $how . ' holds the group its "vs" names (got ' . json_encode($e) . ')',
            count($e) === 2 && $e[1] === $SELF9 . 'first-event".');
    }

    // R11-3 L: only @UVDELTA's own failed tags are read as @UVDELTA values, so a failed
    // tag of another mode does not shift which value each is read from.
    $e = $fieldErrs($X11, 'w9', 'visit_form', 3, '@UVRANGE={bad} @UVDELTA={"vs":"[w9]","max":5} @UVDELTA={"vs":"previous-event","max":"x"}');
    check('R11-3 L: a failed @UVRANGE before the @UVDELTA tags shifts nothing (got ' . json_encode($e) . ')',
        count($e) === 3 && $e[1] === $SELF9 . 'first-event".');

    // R11-3 J: the tags as written are read afresh for each build of the rules. With
    // references off the first tag fails to parse and holds its own "vs"; with them on
    // the dictionary hook refuses it and it holds the reference it is offered.
    $m = mod(['record_id' => f('enrol_form'), 'w9' => f('visit_form', '@UVDELTA={"vs":"[event-name][w9]","max":1} @UVDELTA={"vs":"[w9]","maxPct":10}', 'number')],
        ['2' => [1 => ['record_id' => '2']]], $FOUR, []);
    $off = $errsOf(page($m, 'form', '2', 'visit_form', 3), 'w9');
    $m->projectSettings['enable-event-instance-refs'] = true;
    $on = $errsOf(page($m, 'form', '2', 'visit_form', 3), 'w9');
    check('R11-3 J: the tags read again once references are on (got ' . json_encode([$off, $on]) . ')',
        count($off) === 2 && $off[1] === $SELF9 . 'previous-event" (this needs event and instance references, a project setting).'
        && $on === [$SAME9 . '[previous-event-name][w9]".', $SELF9 . 'first-event".']);

    // ---- the twelfth review (R12-1 to R12-3) ----
    // R12-1: the held note ($HELD10) says what Logic::requiredValues counts: a field
    // written with an event or an instance needs nothing, and each box of a checkbox is
    // a field of its own, so none of these pairs leaves an entry free.
    longitudinal();
    foreach (['one field at another event' => ["[visit1_arm_1][flag] = '1'", "[visit1_arm_1][flag] = '2'"],
              'one field with an instance' => ["[flag][1] = '1'", "[flag][1] = '2'"],
              'two boxes of one checkbox' => ["[cb(1)] = '1'", "[cb(2)] = '0'"]] as $how => $c) {
        $e = $fieldErrs($X11, 'w9', 'visit_form', 3, $whenTag('previous-event', 1, $c[0]) . ' ' . $whenTag('first-event', 1, $c[0]) . ' '
            . $whenTag('[visit1_arm_1][w9]', 1, $c[0]) . ' ' . $whenTag('[event-name][w9]', 1, $c[1]));
        check('R12-1: ' . $how . ': nothing offered, and the note says why (got ' . json_encode($e) . ')',
            $e === ['@UVDELTA on "w9": "vs" names this field in this same entry, so the change would always be 0 — compare it with another entry' . $HELD10]);
    }

    // R12-2: a "when" the parser refuses beside the tag's own "references" even with
    // references on (a {binding} they do not define, a collection binding compared
    // with another collection) makes a tag that failed to parse unreadable. A binding
    // they define, and references refused for themselves, leave the tag read.
    $BIND = ',"references":{"p":{"field":"flag"}}';
    foreach (['a {binding} its "references" does not define' => ['{"vs":"previous-event","max":"x","when":"{p} = \'1\'"}', ',"when":"[flag] = \'2\'"'],
              'a collection {binding} compared with another collection' => ['{"vs":"previous-event","max":"x","when":"{p} = [y][any-instance]",'
                  . '"references":{"p":{"field":"flag","aggregate":"any"}}}', ',"when":"[flag] = \'2\'"'],
              'only that {binding} wrong, beside a tag without a "when"' => ['{"vs":"previous-event","max":1,"when":"{p} = \'1\'"}', '']] as $how => $c) {
        $e = $fieldErrs($X11, 'w9', 'visit_form', 3, '@UVDELTA=' . $c[0] . ' @UVDELTA={"vs":"[w9]","max":5' . $c[1] . '}');
        check('R12-2: beside a tag that failed to parse with ' . $how . ' nothing is offered, and the refusal says why (got ' . json_encode($e) . ')',
            count($e) === 2 && $e[1] === $UNREAD11);
    }
    $e = $fieldErrs($X11, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"previous-event","max":"x","when":"{p} = \'1\'"' . $BIND . '} @UVDELTA={"vs":"[w9]","max":5,"when":"[flag] = \'2\'"}');
    check('R12-2: a tag that failed to parse with a {binding} its "references" defines holds its group (got ' . json_encode($e) . ')',
        count($e) === 2 && $e[1] === $SELF9 . 'first-event".');
    check('... which is accepted next to it once that tag is fixed', $fieldErrs($X11, 'w9', 'visit_form', 3,
        '@UVDELTA={"vs":"previous-event","max":1,"when":"{p} = \'1\'"' . $BIND . '} @UVDELTA={"vs":"first-event","max":5,"when":"[flag] = \'2\'"}') === []);
    $e = $fieldErrs($X11, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"previous-event","max":1,"when":"{p} = \'1\'","references":{"p":{"field":"flag","bogus":1}}} '
        . '@UVDELTA={"vs":"[w9]","max":5,"when":"[flag] = \'2\'"}');
    check('R12-2: a tag whose "references" are refused for themselves holds its group (got ' . json_encode($e) . ')',
        count($e) === 2 && $e[1] === $SELF9 . 'first-event".');

    // R12-3: the lenient read, key by key. The reviewer's N5, K9, W4 and N1 offered an
    // entry that is refused, or validates nothing at times, once that tag is fixed
    // (N1 threw a TypeError on PHP 8 instead); K6 read a key the parser does not take.
    foreach (['"direction":null (N5)' => ['{"vs":"previous-event","max":1,"direction":null}', ',"direction":"up"'],
              'a misspelt key, "wehn" (K9)' => ['{"vs":"previous-event","max":1,"wehn":"[flag] = \'1\'"}', ',"when":"[flag] > 0"'],
              'a "when" of only spaces, beside a tag with a "when" (W4)' => ['{"vs":"previous-event","max":"x","when":"  "}', ',"when":"[flag] > 0"'],
              'an extra key the tag does not take, "label" (K6)' => ['{"vs":"previous-event","max":1,"label":"x"}', ''],
              'a "vs" that is a list (N1)' => ['{"vs":["previous-event"],"max":1}', ''],
              'a "vs" that is a number (N1)' => ['{"vs":5,"max":1}', '']] as $how => $c) {
        try {
            $e = $fieldErrs($X11, 'w9', 'visit_form', 3, '@UVDELTA=' . $c[0] . ' @UVDELTA={"vs":"[w9]","max":5' . $c[1] . '}');
        } catch (\Throwable $t) {
            $e = [get_class($t) . ': ' . $t->getMessage()];
        }
        check('R12-3: beside a tag that failed to parse with ' . $how . ' nothing is offered, and the refusal says why (got ' . json_encode($e) . ')',
            count($e) === 2 && $e[1] === $UNREAD11);
    }
    // N4, D1, D2, T1: what the parser reads once that tag is fixed, the lenient read
    // reads too, so the suggestion beside it stands.
    foreach (['"when":null, no condition (N4)' => ['{"vs":"previous-event","max":"x","when":null}', '', '{"vs":"previous-event","max":1,"when":null}'],
              'direction "both" (D1)' => ['{"vs":"previous-event","max":"x","direction":"both"}', '', '{"vs":"previous-event","max":1,"direction":"both"}'],
              'direction "down" (D2)' => ['{"vs":"previous-event","max":"x","direction":"down"}', ',"direction":"down"', '{"vs":"previous-event","max":1,"direction":"down"}'],
              'a "vs" with spaces around it (T1)' => ['{"vs":" previous-event ","max":"x"}', '', '{"vs":" previous-event ","max":1}']] as $how => $c) {
        $e = $fieldErrs($X11, 'w9', 'visit_form', 3, '@UVDELTA=' . $c[0] . ' @UVDELTA={"vs":"[w9]","max":5' . $c[1] . '}');
        check('R12-3: a tag that failed to parse with ' . $how . ' holds the group its "vs" names (got ' . json_encode($e) . ')',
            count($e) === 2 && $e[1] === $SELF9 . 'first-event".');
        check('... which is accepted next to it once that tag is fixed',
            $fieldErrs($X11, 'w9', 'visit_form', 3, '@UVDELTA=' . $c[2] . ' @UVDELTA={"vs":"first-event","max":5' . $c[1] . '}') === []);
    }
    // G1 (round 11's Q): a tag that failed to parse holds the group its JSON names, not
    // the one its refusal would suggest once fixed, since how it is fixed is not known.
    // Either way the suggestions go together once it is fixed.
    $e = $fieldErrs($X11, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"[w9]","max":"x"} @UVDELTA={"vs":"[w9]","max":5}');
    check('R12-3 G1: a tag that failed to parse and names the field itself holds its own "vs", not its suggestion (got ' . json_encode($e) . ')',
        count($e) === 2 && $e[1] === $SELF9 . 'previous-event".');
    $e = $fieldErrs($X11, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"[w9]","max":1} @UVDELTA={"vs":"previous-event","max":5}');
    check('... fixed, that tag is offered the first event (got ' . json_encode($e) . ')', $e === [$SELF9 . 'first-event".']);
    check('... and the two are accepted together',
        $fieldErrs($X11, 'w9', 'visit_form', 3, '@UVDELTA={"vs":"first-event","max":1} @UVDELTA={"vs":"previous-event","max":5}') === []);
    classic();
    // Extended path: a "vs" the resolver could not read arrives without a
    // value and with the reason, and the evaluator reports it.
    $fd = new \ReflectionMethod(\INSPIRE\UniversalValidator\UniversalValidator::class, 'findingsDelta');
    $fd->setAccessible(true);
    $res = $fd->invoke(mod($RD, $RDATA, $FULL, $REFS), ['type' => 'delta', 'fields' => ['weight'], 'deltaVs' => '[previous-event-name][weight]',
        'deltaVsValue' => null, 'deltaVsValueWhy' => 'Extended reference unavailable: ambiguous.', 'deltaMax' => '1'],
        'delta', ['weight' => '99'], [], null, PID, '2', 351, []);
    check('an unresolved event/instance "vs": a rule problem with its reason (got ' . json_encode($res) . ')', !$res['invalid']
        && count($res['unconfigurable']) === 1
        && strpos($res['unconfigurable'][0]['why'], 'the "vs" value [previous-event-name][weight] could not be resolved (Extended reference unavailable: ambiguous)') === 0);

    fwrite(STDOUT, "delta_module_php: $n checks, $fail failure(s)\n");
    exit($fail ? 1 : 0);
}
