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
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';

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
    function findings($m, $type = 'invalid-id-saved') {
        return array_values(array_map(function ($c) { return $c[1]; },
            array_filter($m->logCalls, function ($c) use ($type) { return $c[0] === $type; })));
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
    check('"vs" naming the field itself: refused, says how to name another entry (got ' . $err('bad_self') . ')',
        strpos($err('bad_self'), '"vs" names this field itself') !== false && strpos($err('bad_self'), '"vs":"previous-event"') !== false);
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
        && strpos($e, '"vs":"[visit1_arm_1][weight][last-instance]"') !== false);
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
    check('... a named event on a form at several events is another entry there: accepted', empty(ruleFor($p, 's_ok')['configError']));
    check('... and so is the first event of a form at several events, which is this entry at the first one only',
        empty(ruleFor($p, 's_vfe')['configError']));
    // An instance of a form that does not repeat: the resolver can only say
    // "invalid" there, on every save (round-3 review F4).
    $flatWhy = function ($f, $events) { return '@UVDELTA on "' . $f . '": "vs" names an instance of "[' . $f . ']", but its form does not repeat at "'
        . implode('", "', $events) . '" — an instance can be named only where the form repeats.'; };
    foreach (['s_inst' => [$p, ['visit1_arm_1', 'visit2_arm_1', 'visit3_arm_1']], 's_both' => [$p, ['visit1_arm_1', 'visit2_arm_1', 'visit3_arm_1']],
              'n_vis' => [$p, ['visit1_arm_1', 'visit2_arm_1', 'visit3_arm_1']],
              's_fi' => [$pe, ['enrol_arm_1']], 's_li' => [$pe, ['enrol_arm_1']], 'n_one' => [$pe, ['enrol_arm_1']]] as $sf => $c) {
        $e = ruleFor($c[0], $sf)['configError'] ?? '';
        check('"vs" ' . $sf . ' names an instance of a form that does not repeat: refused, naming the events (got ' . $e . ')', $e === $flatWhy($sf, $c[1]));
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
        (ruleFor($pm, 'n_vis')['configError'] ?? '') === $flatWhy('n_vis', ['visit1_arm_1', 'visit2_arm_1']));
    // one event: no event to name
    classic();
    $one = ['record_id' => f('enrol_form'), 'n_cl' => f('enrol_form', '@UVDELTA={"vs":"[n_cl][1]","max":1}', 'number')];
    $pc = page(mod($one, ['2' => [351 => ['record_id' => '2']]], $FULL, $REFS), 'form', '2', 'enrol_form');
    check('... a classic project: refused, with no event named (got ' . json_encode(ruleFor($pc, 'n_cl')['configError'] ?? null) . ')',
        (ruleFor($pc, 'n_cl')['configError'] ?? '') === '@UVDELTA on "n_cl": "vs" names an instance of "[n_cl]", but its form does not repeat — an instance can be named only where the form repeats.');
    longitudinal();

    // ============================================================================
    // A "vs" that cannot be read is a rule problem, never a blank.
    // ============================================================================
    // Plain path, longitudinal: w_enrol is collected only at enrolment, so a
    // visit cannot read it.
    longitudinal();
    $OFF = $LD + ['w_off' => f('visit_form', '@UVDELTA={"vs":"[w_enrol]","max":1}', 'number')];
    $V = $LDATA; $V['2'][3]['w_off'] = '99';
    $m = mod($OFF, $V, $FULL, $REFS);
    $m->redcap_save_record(PID, '2', 'visit_form', 3, null, null, null, 1);
    $why = problems($m, 'w_off');
    check('a plain "vs" not collected in this event: a rule problem (got ' . json_encode($why) . ')', !isset(deltaBy($m, 3)['w_off'])
        && (bool) array_filter($why, function ($w) { return strpos($w, 'the "vs" value references "[w_enrol]", which is not collected in this event') !== false; }));
    // Plain path, repeating: a field of a non-repeating form compared with one
    // on a form that repeats has no instance to pair with.
    classic();
    \Project::$repeats = [351 => ['visit_form' => '']];
    $AMB = $RD + ['e_w' => f('enrol_form', '@UVDELTA={"vs":"[weight]","max":1}', 'number')];
    $V = $RDATA; $V['2'][351]['e_w'] = '99';
    $m = mod($AMB, $V, $FULL, $REFS);
    $m->redcap_save_record(PID, '2', 'enrol_form', 351, null, null, null, 1);
    $why = problems($m, 'e_w');
    check('a plain "vs" on another repeating form: a rule problem (got ' . json_encode($why) . ')', !isset(deltaBy($m)['e_w'])
        && (bool) array_filter($why, function ($w) { return strpos($w, 'which is on a different repeating instrument') !== false; }));
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
