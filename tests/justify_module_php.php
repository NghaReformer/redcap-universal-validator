<?php
/**
 * justify_module_php.php — @UVJUSTIFY through the module.
 *
 * justify_php.php pins whether a reason was given and the grammar; this file
 * pins the REDCap glue around them:
 *   - the field hook: the reason cannot be the field itself,
 *   - the dictionary hook: the reason field must exist, sit on the same form
 *     and be a Text, Notes, dropdown, radio or SQL field,
 *   - applyJustify: a field no rule a reason stands for checks is a
 *     configuration error on that field alone (a tag written alike on several
 *     fields is split), @UVUNIQUE and @UVWRITEONCE are named as rules a
 *     reason does not stand for, a rule refused for its own configuration
 *     still counts, and two @UVJUSTIFY on one field are refused,
 *   - the page: the justify rule carries its reason field and message, and
 *     no rule carries the server's justifyBy; a reason field does not make a
 *     locked field hold the save (markLockReads),
 *   - the post-save audit logs a broken rule whose reason is given as
 *     'uvalidate-justified' with justified_by (never the reason), leaves it
 *     'invalid-id-saved' while the reason is blank, spaces or a Missing Data
 *     Code, reads the reason of the same event and instance only,
 *   - the scan marks the same findings, counts them apart from the open ones,
 *     shows the "Saved with a reason" column and sentence, and the durable
 *     rows carry the flag.
 *
 * Run:  php tests/justify_module_php.php
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
        public static $unreadable = false;
        public $project;
        public $longitudinal;
        public $eventInfo;
        public $eventsForms;
        public function __construct($pid) {
            if (self::$unreadable) throw new \RuntimeException('project unreadable');
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

    use INSPIRE\UniversalValidator\ScanColumns;
    use INSPIRE\UniversalValidator\UniversalValidator;

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    const PID = 149;
    function f($form, $ann = '', $validation = '', $type = 'text', $choices = '') {
        return ['field_type' => $type, 'form_name' => $form, 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => $validation, 'select_choices_or_calculations' => $choices];
    }
    /** A classic project: one event (351) holding both forms, nothing repeats. */
    function classic() {
        \Project::$isLongitudinal = false;
        \Project::$eventInfoAll = [351 => ['unique_event_name' => 'event_1_arm_1', 'arm_num' => 1]];
        \Project::$eventsFormsAll = [351 => ['enrol_form', 'visit_form']];
        \Project::$repeats = [];
        \REDCap::$mappings = null;
    }
    /** A longitudinal project: enrolment (1), then two visits (2, 3) of visit_form, in one arm. */
    function longitudinal() {
        \Project::$isLongitudinal = true;
        \Project::$eventInfoAll = [1 => ['unique_event_name' => 'enrol_arm_1', 'arm_num' => 1],
            2 => ['unique_event_name' => 'visit1_arm_1', 'arm_num' => 1], 3 => ['unique_event_name' => 'visit2_arm_1', 'arm_num' => 1]];
        \Project::$eventsFormsAll = [1 => ['enrol_form'], 2 => ['visit_form'], 3 => ['visit_form']];
        \Project::$repeats = [];
        \REDCap::$mappings = [['event_id' => 1, 'form' => 'enrol_form'], ['event_id' => 2, 'form' => 'visit_form'],
            ['event_id' => 3, 'form' => 'visit_form']];
    }
    $FULL = ['nurse' => ['forms' => ['enrol_form' => '1', 'visit_form' => '1']]];

    function mod($dict, $data, $rights, array $settings = []) {
        $GLOBALS['__TEST_USER'] = 'nurse';
        $m = new UniversalValidator();
        $m->projectSettings = array_merge(['log-values' => 'raw'], $settings);
        $m->projectIdReturn = PID;
        \REDCap::$dictionary = $dict;
        \REDCap::$data = $data;
        \REDCap::$rights = $rights;
        return $m;
    }
    function page($m, $rec, $form, $evt = 351, $inst = 1) {
        ob_start();
        $m->redcap_data_entry_form_top(PID, $rec, $form, $evt, null, $inst);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return ['html' => $html, 'cfg' => json_decode(isset($mm[1]) ? $mm[1] : 'null', true)];
    }
    /** The page's rules on $f, of $type when given. */
    function rulesOn($p, $f, $type = null) {
        $out = [];
        foreach ((isset($p['cfg']['rules']) ? $p['cfg']['rules'] : []) as $r) {
            if (!in_array($f, isset($r['fields']) ? $r['fields'] : [], true)) continue;
            if ($type !== null && (isset($r['type']) ? $r['type'] : '') !== $type) continue;
            $out[] = $r;
        }
        return $out;
    }
    function justifyOn($p, $f) { $r = rulesOn($p, $f, 'justify'); return $r ? $r[0] : null; }
    function logged($m, $msg) {
        return array_values(array_map(function ($c) { return $c[1]; },
            array_filter($m->logCalls, function ($c) use ($msg) { return $c[0] === $msg; })));
    }
    /** The audit's findings on $field as "message:type:reason[:justified_by]". */
    function audit($m, $field) {
        $out = [];
        foreach (['invalid-id-saved', 'uvalidate-justified'] as $msg) {
            foreach (logged($m, $msg) as $e) {
                if ($e['field'] !== $field) continue;
                $out[] = $msg . ':' . $e['type'] . ':' . $e['reason'] . (isset($e['justified_by']) ? ':' . $e['justified_by'] : '');
            }
        }
        sort($out);
        return $out;
    }
    function save($dict, $data, $form, $evt = 351, $inst = 1, $rec = '2') {
        global $FULL;
        $m = mod($dict, $data, $FULL);
        $m->redcap_save_record(PID, $rec, $form, $evt, null, null, null, $inst);
        return $m;
    }

    // ============================================================================
    // A classic project.
    // ============================================================================
    classic();
    \Project::$codes = "UNK, Unknown\nNASK, Not asked";
    $J = function ($reason, $extra = '') { return '@UVJUSTIFY={"reason":"[' . $reason . ']"' . $extra . '}'; };
    $DICT = [
        'record_id'  => f('enrol_form'),
        'enrol_note' => f('enrol_form', '', '', 'notes'),
        'wt'         => f('visit_form', '@UVRANGE={"hard":[40,200],"hardBlock":"hard"} ' . $J('wt_why'), 'number'),
        'wt_why'     => f('visit_form', '', '', 'notes'),
        'vdate'      => f('visit_form', '@UVWINDOW={"notFuture":true} ' . $J('v_why', ',"message":"Say why the date is right."'), 'date_ymd'),
        'v_why'      => f('visit_form', '', '', 'dropdown', '1, Clinic closed | 2, Patient travelled'),
        'req'        => f('visit_form', '@UVREQUIRED={"blockSave":"hard"} ' . $J('req_why')),
        'req_why'    => f('visit_form', '', '', 'radio', '1, Not done | 2, Refused'),
        'f1'         => f('visit_form', '@UVRANGE={"hard":[0,10]} ' . $J('pair_why'), 'integer'),
        'f2'         => f('visit_form', '@UVRANGE={"hard":[0,10]} ' . $J('pair_why'), 'integer'),
        'f3'         => f('visit_form', $J('pair_why'), 'integer'),
        'pair_why'   => f('visit_form'),
        'pid'        => f('visit_form', '@UVUNIQUE ' . $J('pid_why')),
        'pid_why'    => f('visit_form'),
        'lock'       => f('visit_form', '@UVWRITEONCE ' . $J('lock_why')),
        'lock_why'   => f('visit_form'),
        'bad_self'   => f('visit_form', '@UVRANGE={"hard":[0,1]} ' . $J('bad_self'), 'integer'),
        'bad_none'   => f('visit_form', '@UVRANGE={"hard":[0,1]} ' . $J('nope'), 'integer'),
        'bad_form'   => f('visit_form', '@UVRANGE={"hard":[0,1]} ' . $J('enrol_note'), 'integer'),
        'bad_type'   => f('visit_form', '@UVRANGE={"hard":[0,1]} ' . $J('yn'), 'integer'),
        'yn'         => f('visit_form', '', '', 'yesno'),
        'bad_cb'     => f('visit_form', '@UVRANGE={"hard":[0,1]} ' . $J('cb'), 'integer'),
        'cb'         => f('visit_form', '', '', 'checkbox', '1, A | 2, B'),
        'bad_twice'  => f('visit_form', '@UVRANGE={"hard":[0,1]} ' . $J('pair_why') . ' ' . $J('wt_why'), 'integer'),
        'broken'     => f('visit_form', '@UVRANGE={"hard":[10,0]} ' . $J('broken_why'), 'integer'),
        'broken_why' => f('visit_form'),
        'br'         => f('visit_form', '@UVRANGE={"hard":[0,10],"when":"[br_sw]=\'1\'"} @UVRANGE={"hard":[0,5],"when":"[br_sw]<>\'1\'"} ' . $J('br_why'), 'integer'),
        'br_sw'      => f('visit_form'),
        'br_why'     => f('visit_form'),
    ];
    $ROW = ['record_id' => '2', 'enrol_note' => '', 'wt' => '30', 'wt_why' => '', 'vdate' => '2999-01-01', 'v_why' => '',
        'req' => '', 'req_why' => '', 'f1' => '11', 'f2' => '12', 'f3' => '13', 'pair_why' => '', 'pid' => 'P1', 'pid_why' => '',
        'lock' => 'x', 'lock_why' => '', 'bad_self' => '5', 'bad_none' => '5', 'bad_form' => '5', 'bad_type' => '5', 'yn' => '1',
        'bad_cb' => '5', 'bad_twice' => '5', 'broken' => '5', 'broken_why' => '', 'br' => '3', 'br_sw' => '1', 'br_why' => ''];
    $data = function (array $over = []) use ($ROW) { return ['2' => [351 => array_merge($ROW, $over)]]; };

    // ---- 1) the hooks and applyJustify, as the page sees them ---------------------------
    $p = page(mod($DICT, $data(), $FULL), '2', 'visit_form');
    // A @UVJUSTIFY the field or dictionary hook refuses is replaced by an error rule of its own.
    $err = function ($field) use ($p) {
        foreach (rulesOn($p, $field) as $r) {
            if (isset($r['configError']) && strpos((string) $r['configError'], '@UVJUSTIFY on "' . $field . '"') === 0) return (string) $r['configError'];
        }
        return '';
    };
    foreach (['wt', 'vdate', 'req', 'f1', 'f2'] as $ok) {
        $r = justifyOn($p, $ok);
        check($ok . ': a justify rule with no error (got ' . json_encode($r) . ')', $r !== null && $err($ok) === '');
    }
    check('the justify rule carries its reason field', justifyOn($p, 'wt')['justifyReason'] === 'wt_why');
    check('... and its message', justifyOn($p, 'vdate')['message'] === 'Say why the date is right.');
    $leak = false;
    foreach ($p['cfg']['rules'] as $r) {
        if (isset($r['justifyBy'])) $leak = true;
        foreach ((isset($r['branches']) ? $r['branches'] : []) as $b) if (isset($b['justifyBy'])) $leak = true;
    }
    check('no rule on the page carries the server\'s justifyBy', !$leak);
    check('f1 and f2 share one justify rule; f3 is split off with an error (got ' . $err('f3') . ')',
        strpos($err('f3'), '@UVJUSTIFY on "f3": no rule a reason can stand for checks this field') === 0
        && in_array('f1', justifyOn($p, 'f1')['fields'], true) && in_array('f2', justifyOn($p, 'f1')['fields'], true)
        && !in_array('f3', justifyOn($p, 'f1')['fields'], true));
    check('the error lists the rules a reason stands for (got ' . $err('f3') . ')',
        strpos($err('f3'), 'give it a rule of @UVALIDATE, @UVASSERT') !== false && strpos($err('f3'), '@UVDELTA') !== false);
    check('... and the ones it does not', strpos($err('f3'), '(a reason does not stand for @UVUNIQUE or @UVWRITEONCE)') !== false);
    check('a field with only @UVUNIQUE: the same error (got ' . $err('pid') . ')', strpos($err('pid'), 'no rule a reason can stand for checks this field') !== false);
    check('a field with only @UVWRITEONCE: the same error (got ' . $err('lock') . ')', strpos($err('lock'), 'no rule a reason can stand for checks this field') !== false);
    check('the reason cannot be the field itself (got ' . $err('bad_self') . ')', strpos($err('bad_self'), '"reason" names this field itself') !== false);
    check('the reason field must exist (got ' . $err('bad_none') . ')', strpos($err('bad_none'), '"[nope]", which is not a field of this project') !== false);
    check('the reason field must be on the same form (got ' . $err('bad_form') . ')',
        strpos($err('bad_form'), 'which is on the form "enrol_form", not on "visit_form"') !== false);
    check('a yes/no field takes no reason (got ' . $err('bad_type') . ')', strpos($err('bad_type'), 'a "yesno" field') !== false);
    check('nor does a checkbox (got ' . $err('bad_cb') . ')', strpos($err('bad_cb'), 'a "checkbox" field') !== false);
    $tw = rulesOn($p, 'bad_twice', 'justify');
    check('two @UVJUSTIFY on one field are refused (got ' . json_encode($tw) . ')', count($tw) === 1 && !empty($tw[0]['configError']));
    $br = rulesOn($p, 'broken');
    $brRange = array_values(array_filter($br, function ($r) { return $r['type'] !== 'justify'; }));
    check('a rule refused for its own configuration still counts: no justify error (got ' . $err('broken') . ')',
        $err('broken') === '' && $brRange && !empty($brRange[0]['configError']));

    // applyJustify and markLockReads on their own
    $aj = new \ReflectionMethod(UniversalValidator::class, 'applyJustify');
    $aj->setAccessible(true);
    $out = $aj->invoke(null, [
        ['type' => 'constraint', 'fields' => ['a', 'b'], 'assert' => '[a]>0'],
        ['type' => 'range', 'fields' => ['a'], 'rangeHardLo' => '0', 'configError' => 'bad'],
        ['type' => 'justify', 'fields' => ['a'], 'justifyReason' => 'why'],
    ]);
    check('applyJustify: the live rule gets justifyBy for the paired field only', $out[0]['justifyBy'] === ['a' => 'why']);
    check('applyJustify: a refused rule gets none', !isset($out[1]['justifyBy']));
    check('applyJustify: the justify rule is kept as it was', $out[2] === ['type' => 'justify', 'fields' => ['a'], 'justifyReason' => 'why']);
    $out = $aj->invoke(null, [['type' => 'constraint', 'fields' => ['a'], 'assert' => '[a]>0'],
        ['type' => 'justify', 'fields' => ['a'], 'justifyReason' => 'why', 'configError' => 'refused']]);
    check('applyJustify: a refused justify rule pairs nothing', !isset($out[0]['justifyBy']));
    $out = $aj->invoke(null, [['type' => 'justify', 'fields' => ['x', 'y'], 'justifyReason' => 'why']]);
    check('applyJustify: every field of a rule no other rule checks is an error of its own', count($out) === 2
        && strpos($out[0]['configError'], 'on "x"') !== false && $out[0]['fields'] === ['x']
        && strpos($out[1]['configError'], 'on "y"') !== false && $out[1]['fields'] === ['y']);
    $out = $aj->invoke(null, [['type' => 'constraint', 'fields' => ['a'], 'assert' => '[a]>0']]);
    check('applyJustify: no justify rule, nothing changes', $out === [['type' => 'constraint', 'fields' => ['a'], 'assert' => '[a]>0']]);
    $ml = new \ReflectionMethod(UniversalValidator::class, 'markLockReads');
    $ml->setAccessible(true);
    $out = $ml->invoke(null, [['type' => 'writeonce', 'fields' => ['a']],
        ['type' => 'constraint', 'fields' => ['a'], 'assert' => '[a]>0', 'justifyBy' => ['a' => 'why']]]);
    check('markLockReads: a reason field does not make a locked field hold the save', !isset($out[1]['lockReads']));
    $out = $ml->invoke(null, [['type' => 'writeonce', 'fields' => ['a']],
        ['type' => 'constraint', 'fields' => ['a'], 'assert' => '[a]>[b]', 'justifyBy' => ['a' => 'why']]]);
    check('markLockReads: another field the rule reads still does', isset($out[1]['lockReads']) && $out[1]['lockReads'] === ['a' => ['b']]);

    // ---- 2) the post-save audit ----------------------------------------------------------
    $m = save($DICT, $data(), 'visit_form');
    check('no reason: the broken rule is logged open (got ' . json_encode(audit($m, 'wt')) . ')', audit($m, 'wt') === ['invalid-id-saved:range:hard-low']);
    $m = save($DICT, $data(['wt_why' => 'scale recalibrated, re-weighed twice']), 'visit_form');
    check('a reason given: logged as justified, naming the reason field (got ' . json_encode(audit($m, 'wt')) . ')',
        audit($m, 'wt') === ['uvalidate-justified:range:hard-low:wt_why']);
    $j = logged($m, 'uvalidate-justified');
    $text = json_encode($m->logCalls);
    check('the reason itself is never logged', strpos($text, 'recalibrated') === false);
    check('the justified entry keeps the value, as the open one does (log-values raw)', $j && $j[0]['value'] === '30');
    foreach (['   ' => 'spaces', 'UNK' => 'a Missing Data Code', " NASK\n" => 'a Missing Data Code with spaces'] as $v => $what) {
        $m = save($DICT, $data(['wt_why' => $v]), 'visit_form');
        check($what . ' is no reason (got ' . json_encode(audit($m, 'wt')) . ')', audit($m, 'wt') === ['invalid-id-saved:range:hard-low']);
    }
    $m = save($DICT, $data(['v_why' => '2']), 'visit_form');
    check('a dropdown choice is a reason (got ' . json_encode(audit($m, 'vdate')) . ')', audit($m, 'vdate') === ['uvalidate-justified:window:future:v_why']);
    $m = save($DICT, $data(['req_why' => '1']), 'visit_form');
    check('a radio choice is a reason for a blank required field (got ' . json_encode(audit($m, 'req')) . ')',
        audit($m, 'req') === ['uvalidate-justified:required:required-blank:req_why']);
    $m = save($DICT, $data(['pair_why' => 'both above the scale']), 'visit_form');
    check('one reason field stands for two fields (got ' . json_encode([audit($m, 'f1'), audit($m, 'f2')]) . ')',
        audit($m, 'f1') === ['uvalidate-justified:range:hard-high:pair_why'] && audit($m, 'f2') === ['uvalidate-justified:range:hard-high:pair_why']);
    // a branched rule: judged by the branch its condition picks, then marked
    $m = save($DICT, $data(['br' => '8', 'br_sw' => '2']), 'visit_form');
    check('a branched rule, no reason: the branch picked logs it open (got ' . json_encode(audit($m, 'br')) . ')', audit($m, 'br') === ['invalid-id-saved:range:hard-high']);
    $m = save($DICT, $data(['br' => '8', 'br_sw' => '2', 'br_why' => 'scale reads high']), 'visit_form');
    check('... a reason given: justified (got ' . json_encode(audit($m, 'br')) . ')', audit($m, 'br') === ['uvalidate-justified:range:hard-high:br_why']);
    $m = save($DICT, $data(['br' => '8', 'br_sw' => '1', 'br_why' => 'scale reads high']), 'visit_form');
    check('... the other branch passes it: nothing logged', audit($m, 'br') === []);
    $m = save($DICT, $data(['wt' => '70', 'wt_why' => 'nothing wrong']), 'visit_form');
    check('a reason with nothing broken logs nothing', audit($m, 'wt') === []);
    $m = save($DICT, $data(['wt_why' => 'x']), 'visit_form');
    check('a reason never lifts a finding of another field', audit($m, 'f1') === ['invalid-id-saved:range:hard-high']);
    $m = mod($DICT, $data(['wt_why' => 'scale recalibrated']), $FULL, ['log-values' => 'none']);
    $m->redcap_save_record(PID, '2', 'visit_form', 351, null, null, null, 1);
    $j = logged($m, 'uvalidate-justified');
    check('log-values none: the justified entry has no value and no record id', $j && !isset($j[0]['value']) && !isset($j[0]['record'])
        && $j[0]['justified_by'] === 'wt_why');

    // Missing Data Codes that cannot be read: no value can be told apart from a code, so nothing is justified.
    \Project::$unreadable = true;
    $m = save($DICT, $data(['wt_why' => 'UNK']), 'visit_form');
    $m2 = save($DICT, $data(['wt_why' => 'scale recalibrated']), 'visit_form');
    \Project::$unreadable = false;
    check('codes that cannot be read: a code is not taken for a reason (got ' . json_encode(audit($m, 'wt')) . ')',
        audit($m, 'wt') === ['invalid-id-saved:range:hard-low']);
    check('... nor is any other text (got ' . json_encode(audit($m2, 'wt')) . ')', audit($m2, 'wt') === ['invalid-id-saved:range:hard-low']);

    // ---- 3) the scan ---------------------------------------------------------------------
    $m = mod($DICT, $data(['wt_why' => 'scale recalibrated', 'pair_why' => 'both above the scale']), $FULL);
    $res = $m->scanProject(PID);
    $by = [];
    foreach ($res['violations'] as $v) $by[$v['field']] = $v;
    check('scan: a finding with its reason is marked, with the reason field', !empty($by['wt']['justified']) && $by['wt']['justifiedBy'] === 'wt_why');
    check('scan: one without is not', empty($by['vdate']['justified']) && !isset($by['vdate']['justifiedBy']));
    $just = count(array_filter($res['violations'], function ($v) { return !empty($v['justified']); }));
    check('scan: justified counted apart (got ' . json_encode($res['stats']) . ')', $res['stats']['justified'] === 3 && $just === 3
        && $res['stats']['violations'] === count($res['violations']) - 3);
    check('scan: the reason itself is in no finding', strpos(json_encode($res['violations']), 'recalibrated') === false);
    $dims = $m->scanDimensions(PID, $res['rules'] ?? null);
    $cols = ScanColumns::all($dims);
    $keys = array_column($cols, 'key');
    check('report: the "Saved with a reason" column is shown', in_array('justified', $keys, true));
    $row = ScanColumns::row($by['wt'], $dims, $cols);
    check('report: Yes for a finding with its reason (got ' . json_encode($row['justified'] ?? null) . ')', ($row['justified'] ?? null) === 'Yes');
    check('report: the problem names the reason field (got ' . json_encode($row['problem'] ?? null) . ')',
        strpos((string) ($row['problem'] ?? ''), 'It was saved with a reason, given in [wt_why].') !== false);
    $row = ScanColumns::row($by['vdate'], $dims, $cols);
    check('report: blank for an open one', ($row['justified'] ?? null) === '' && strpos((string) ($row['problem'] ?? ''), 'saved with a reason') === false);
    $plain = ['record_id' => f('enrol_form'), 'w' => f('visit_form', '@UVRANGE={"hard":[0,1]}', 'integer')];
    $pm = mod($plain, ['2' => [351 => ['record_id' => '2', 'w' => '5']]], $FULL);
    $pres = $pm->scanProject(PID);
    check('report: no column on a project with no reason to give', !in_array('justified', array_column(ScanColumns::all($pm->scanDimensions(PID, $pres['rules'] ?? null)), 'key'), true));
    check('scan: no reasons, nothing counted apart', $pres['stats']['justified'] === 0 && $pres['stats']['violations'] === 1);

    // every finding with its reason: no open violation
    $allOk = $data(['vdate' => '2020-01-01', 'req' => 'x', 'wt_why' => 'w', 'pair_why' => 'p', 'bad_self' => '1', 'bad_none' => '1',
        'bad_form' => '1', 'bad_type' => '1', 'bad_cb' => '1', 'bad_twice' => '1', 'broken' => '5', 'f3' => '1']);
    $res = mod($DICT, $allOk, $FULL)->scanProject(PID);
    check('scan: with a reason for every finding, no open violation (got ' . json_encode($res['stats']) . ')',
        $res['stats']['violations'] === 0 && $res['stats']['justified'] === count($res['violations']) && $res['stats']['justified'] > 0);

    // the durable rows carry the flag
    $m = mod($DICT, $data(['wt_why' => 'scale recalibrated']), $FULL);
    $ctx = $m->durableScanContext(PID, ['generation' => null]);
    $dur = $m->durableEvaluateRecord($ctx['plan'], PID, '2', $data(['wt_why' => 'scale recalibrated'])['2'], 1, str_repeat('k', 32), $ctx['plan']['ruleIds']);
    $flag = [];
    foreach ($dur['findings'] as $x) $flag[$x['field']] = $x['justified'];
    check('durable: 1 for a finding with its reason, 0 for an open one (got ' . json_encode($flag) . ')',
        ($flag['wt'] ?? null) === 1 && ($flag['vdate'] ?? null) === 0);
    check('durable: the reason itself is in no row', strpos(json_encode($dur['findings']), 'recalibrated') === false);

    // ============================================================================
    // The reason of the same event and instance only.
    // ============================================================================
    longitudinal();
    \Project::$codes = '';
    $LD = ['record_id' => f('enrol_form'),
        'wt' => f('visit_form', '@UVRANGE={"hard":[40,200]} ' . $J('wt_why'), 'number'),
        'wt_why' => f('visit_form', '', '', 'notes')];
    $LDATA = ['2' => [1 => ['record_id' => '2'], 2 => ['wt' => '30', 'wt_why' => 'weighed in a cast'], 3 => ['wt' => '31', 'wt_why' => '']]];
    $m = save($LD, $LDATA, 'visit_form', 2);
    check('event 2: its own reason (got ' . json_encode(audit($m, 'wt')) . ')', audit($m, 'wt') === ['uvalidate-justified:range:hard-low:wt_why']);
    $m = save($LD, $LDATA, 'visit_form', 3);
    check('event 3: the reason given at event 2 does not stand for it (got ' . json_encode(audit($m, 'wt')) . ')',
        audit($m, 'wt') === ['invalid-id-saved:range:hard-low']);
    $res = mod($LD, $LDATA, $FULL)->scanProject(PID);
    $hits = [];
    foreach ($res['violations'] as $v) $hits[] = $v['event_id'] . ':' . (empty($v['justified']) ? 'open' : 'justified');
    sort($hits);
    check('scan: each event with its own reason (got ' . json_encode($hits) . ')', $hits === ['2:justified', '3:open']);

    classic();
    \Project::$repeats = [351 => ['visit_form' => '']];
    $RDATA = ['2' => [351 => ['record_id' => '2'], 'repeat_instances' => [351 => ['visit_form' => [
        1 => ['wt' => '30', 'wt_why' => 'child, not adult'],
        2 => ['wt' => '31', 'wt_why' => ''],
    ]]]]];
    $m = save($LD, $RDATA, 'visit_form', 351, 1);
    check('instance 1: its own reason (got ' . json_encode(audit($m, 'wt')) . ')', audit($m, 'wt') === ['uvalidate-justified:range:hard-low:wt_why']);
    $m = save($LD, $RDATA, 'visit_form', 351, 2);
    check('instance 2: the reason of instance 1 does not stand for it (got ' . json_encode(audit($m, 'wt')) . ')',
        audit($m, 'wt') === ['invalid-id-saved:range:hard-low']);
    $res = mod($LD, $RDATA, $FULL)->scanProject(PID);
    $hits = [];
    foreach ($res['violations'] as $v) $hits[] = ($v['instance'] ?? '?') . ':' . (empty($v['justified']) ? 'open' : 'justified');
    sort($hits);
    check('scan: each instance with its own reason (got ' . json_encode($hits) . ')', $hits === ['1:justified', '2:open']);

    fwrite(STDOUT, "justify_module_php: $n checks, $fail failure(s)\n");
    exit($fail ? 1 : 0);
}
