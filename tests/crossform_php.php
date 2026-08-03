<?php
/**
 * crossform_php.php — cross-instrument @UVASSERT (v1.6.0).
 *
 * Before 1.6.0 a constraint whose "assert" referenced a field on ANOTHER
 * instrument was folded WHOLE to a boolean at page load, freezing the verdict
 * against the SAVED value of the field the user was about to type into. That
 * was wrong in both directions: a blank field folded to false hard-blocked a
 * correct entry that no amount of retyping could clear, and a value that was
 * valid at load stayed "OK" however badly it was edited afterwards.
 *
 * The fix keeps such a comparison LIVE by baking the off-page value in as a
 * ['lit', …] operand — but ONLY when the viewer is already entitled to read
 * that field (authenticated data entry, and rights to its instrument).
 * Otherwise the old fold stands and the rule is marked "deferred" so the
 * client states no verdict and never blocks; redcap_save_record still enforces.
 *
 * These tests pin BOTH halves: that an entitled viewer gets a live condition,
 * and that every un-entitled path leaks nothing and blocks nothing.
 *
 * Run:  php tests/crossform_php.php
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
        // The framework user object. $GLOBALS['__TEST_USER'] = null models a
        // context with no authenticated user (survey, cron, API).
        public function getUser() {
            $u = isset($GLOBALS['__TEST_USER']) ? $GLOBALS['__TEST_USER'] : null;
            return $u === null ? null : new TestUser($u);
        }
    }
    /**
     * Models the REAL framework User object, whose getRights($pid) is the
     * primary rights source. $GLOBALS['__TEST_USER_OBJ_RIGHTS'] = false makes
     * getRights unavailable, forcing the \REDCap::getUserRights() fallback —
     * both paths must reach the same verdict, because production has shipped
     * both framework shapes.
     */
    class TestUser {
        private $n;
        public function __construct($n) { $this->n = $n; }
        public function getUsername() { return $this->n; }
        public function hasDesignRights() { return true; }
        public function getRights($pid = null) {
            if (!empty($GLOBALS['__TEST_NO_USER_OBJ_RIGHTS'])) {
                throw new \BadMethodCallException('getRights unavailable in this framework build');
            }
            // A broken rights backend breaks BOTH sources — otherwise the
            // fail-closed test would be satisfied by a mock that quietly
            // succeeds where production would throw.
            if (\REDCap::$rightsThrows) throw new \RuntimeException('simulated rights failure');
            $all = \REDCap::$rights;
            return isset($all[$this->n]) ? $all[$this->n] : null;
        }
    }
}

namespace {
    class REDCap {
        public static $data = [];
        public static $dictionary = [];
        public static $rights = [];          // username => ['forms' => [form => 0|1|2|3]]
        public static $rightsThrows = false;
        public static function getData($p) { return self::$data; }
        public static function getDataDictionary($pid, $f = 'array') {
            if (!$pid) throw new \RuntimeException('needs pid');
            return self::$dictionary;
        }
        public static function getUserRights($pid = null, $user = null) {
            if (self::$rightsThrows) throw new \RuntimeException('simulated rights failure');
            return self::$rights;
        }
        public static function getGroupNames($u = false, $g = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
    }

    require_once __DIR__ . '/../UniversalValidator.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    const SECRET = 'ZZTOPSECRET77';

    // enroll_form: start_date, staff_code   |   visit_form: end_date, offref
    $DICT = [
        'record_id'  => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'enroll_form'],
        'start_date' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'enroll_form'],
        'staff_code' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'enroll_form'],
        'end_date'   => ['field_type' => 'text', 'form_name' => 'visit_form',
            'field_annotation' => '@UVASSERT={"assert":"[end_date]>=[start_date]","message":"End before start","blockSave":"hard"}'],
        'offref'     => ['field_type' => 'text', 'form_name' => 'visit_form',
            'field_annotation' => '@UVASSERT={"assert":"[offref]=[staff_code]","message":"Must match the staff code"}'],
    ];
    // end_date is EMPTY — the exact shape that used to hard-block a correct entry.
    $DATA = [2 => [351 => [
        'record_id' => '2', 'start_date' => '2026-05-10', 'staff_code' => SECRET,
    ]]];

    function mod($dict, $data, $rights, $user, $throws = false) {
        $GLOBALS['__TEST_USER'] = $user;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->subSettings = [];
        $m->projectSettings = ['log-values' => ''];
        $m->projectIdReturn = 149;
        \REDCap::$dictionary = $dict;
        \REDCap::$data = $data;
        \REDCap::$rights = $rights;
        \REDCap::$rightsThrows = $throws;
        return $m;
    }
    function page($m, $ctx, $rec, $form, $evt = 351) {
        ob_start();
        if ($ctx === 'survey') $m->redcap_survey_page_top(149, $rec, $form, $evt, null, 'hash', null, 1);
        else $m->redcap_data_entry_form_top(149, $rec, $form, $evt, null, 1);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return ['html' => $html, 'raw' => isset($mm[1]) ? $mm[1] : '', 'cfg' => json_decode(isset($mm[1]) ? $mm[1] : 'null', true)];
    }
    function ruleFor($p, $f) {
        foreach ((isset($p['cfg']['rules']) ? $p['cfg']['rules'] : []) as $r) {
            if (in_array($f, isset($r['fields']) ? $r['fields'] : [], true)) return $r;
        }
        return null;
    }
    /* full rights to both forms */
    $FULL = ['nurse' => ['forms' => ['enroll_form' => '1', 'visit_form' => '1']]];
    /* may edit visit_form, NO access to enroll_form */
    $PART = ['nurse' => ['forms' => ['enroll_form' => '0', 'visit_form' => '1']]];

    // ---- 1) entitled data-entry user: the comparison stays LIVE --------------
    $p = page(mod($DICT, $DATA, $FULL, 'nurse'), 'form', '2', 'visit_form');
    $r = ruleFor($p, 'end_date');
    $enc = json_encode(isset($r['assertAst']) ? $r['assertAst'] : null);
    check('entitled: an assertAst is shipped', $r && isset($r['assertAst']));
    check('entitled: the comparison is NOT frozen to a constant', strpos($enc, '"const"') === false);
    check('entitled: the live field stays a ref', strpos($enc, '["ref","end_date",null]') !== false);
    check('entitled: the off-page field is baked in as a literal',
        strpos($enc, '["lit","2026-05-10"]') !== false);
    check('entitled: the rule is NOT deferred', empty($r['deferred']));

    // ---- 2) that literal is the ONLY thing disclosed, and only for a field
    //         the user may already read ------------------------------------
    $p2 = page(mod($DICT, $DATA, $FULL, 'nurse'), 'form', '2', 'visit_form');
    $r2 = ruleFor($p2, 'offref');
    $enc2 = json_encode(isset($r2['assertAst']) ? $r2['assertAst'] : null);
    check('entitled: staff_code is baked in for a user with enroll_form rights',
        strpos($enc2, '["lit","' . SECRET . '"]') !== false);

    // ---- 3) NO rights to the referenced form: nothing is disclosed ----------
    $p3 = page(mod($DICT, $DATA, $PART, 'nurse'), 'form', '2', 'visit_form');
    $r3 = ruleFor($p3, 'offref');
    $enc3 = json_encode(isset($r3['assertAst']) ? $r3['assertAst'] : null);
    check('no rights: the value is NOT in the shipped AST', strpos($enc3, SECRET) === false);
    check('no rights: the value is NOT anywhere in the page', strpos($p3['html'], SECRET) === false);
    check('no rights: the comparison is folded to a constant', strpos($enc3, '"const"') !== false);
    check('no rights: the rule IS deferred (so it cannot block)', !empty($r3['deferred']));

    // ---- 4) survey: never disclosable, whatever the rights table says -------
    $p4 = page(mod($DICT, $DATA, $FULL, 'nurse'), 'survey', '2', 'visit_form');
    $r4 = ruleFor($p4, 'offref');
    $enc4 = json_encode(isset($r4['assertAst']) ? $r4['assertAst'] : null);
    check('survey: the value is NOT in the shipped AST', strpos($enc4, SECRET) === false);
    check('survey: the value is NOT anywhere in the page', strpos($p4['html'], SECRET) === false);
    check('survey: the rule IS deferred', !empty($r4['deferred']));
    $r4b = ruleFor($p4, 'end_date');
    check('survey: start_date is not disclosed either', strpos($p4['html'], '2026-05-10') === false);
    check('survey: the date rule is deferred too', !empty($r4b['deferred']));

    // ---- 5) fail closed: no authenticated user, and a rights-read failure ---
    $p5 = page(mod($DICT, $DATA, $FULL, null), 'form', '2', 'visit_form');
    $r5 = ruleFor($p5, 'offref');
    check('no authenticated user: nothing disclosed', strpos($p5['html'], SECRET) === false);
    check('no authenticated user: deferred', !empty($r5['deferred']));

    $p6 = page(mod($DICT, $DATA, $FULL, 'nurse', true), 'form', '2', 'visit_form');
    $r6 = ruleFor($p6, 'offref');
    check('rights lookup throws: nothing disclosed', strpos($p6['html'], SECRET) === false);
    check('rights lookup throws: deferred', !empty($r6['deferred']));

    $p7 = page(mod($DICT, $DATA, ['someone_else' => ['forms' => ['enroll_form' => '1']]], 'nurse'), 'form', '2', 'visit_form');
    check('rights table has no row for this user: nothing disclosed',
        strpos($p7['html'], SECRET) === false);
    check('rights table has no row for this user: deferred', !empty(ruleFor($p7, 'offref')['deferred']));

    // ---- 6) same-instrument is untouched: both refs stay live ---------------
    $same = $DICT;
    $same['start_date']['form_name'] = 'visit_form';
    $p8 = page(mod($same, $DATA, $FULL, 'nurse'), 'form', '2', 'visit_form');
    $enc8 = json_encode(ruleFor($p8, 'end_date')['assertAst']);
    check('same instrument: both refs stay live', strpos($enc8, '"lit"') === false
        && strpos($enc8, '["ref","start_date",null]') !== false);
    check('same instrument: not deferred', empty(ruleFor($p8, 'end_date')['deferred']));

    // ---- 7) read-only rights (level 2) still count as "may read" -----------
    $ro = ['nurse' => ['forms' => ['enroll_form' => '2', 'visit_form' => '1']]];
    $p9 = page(mod($DICT, $DATA, $ro, 'nurse'), 'form', '2', 'visit_form');
    check('read-only rights on the referenced form: value is baked in',
        strpos(json_encode(ruleFor($p9, 'offref')['assertAst']), '["lit","' . SECRET . '"]') !== false);

    // ---- 8) the server audit is unchanged and still enforces ---------------
    $bad = [2 => [351 => ['record_id' => '2', 'start_date' => '2026-05-10',
                          'staff_code' => SECRET, 'end_date' => '2026-01-01']]];
    $m = mod($DICT, $bad, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'visit_form', 351, null, null, null, 1);
    $logs = array_values(array_filter($m->logCalls, function ($c) { return $c[0] === 'invalid-id-saved'; }));
    $fields = array_map(function ($c) { return $c[1]['field']; }, $logs);
    check('audit: a cross-form violation is still caught', in_array('end_date', $fields, true));

    $ok = [2 => [351 => ['record_id' => '2', 'start_date' => '2026-01-01',
                         'staff_code' => SECRET, 'end_date' => '2026-05-10', 'offref' => SECRET]]];
    $m2 = mod($DICT, $ok, $FULL, 'nurse');
    $m2->redcap_save_record(149, '2', 'visit_form', 351, null, null, null, 1);
    check('audit: a satisfied cross-form assert logs nothing',
        count(array_filter($m2->logCalls, function ($c) { return $c[0] === 'invalid-id-saved'; })) === 0);

    // A deferred rule must still be audited — that is the whole point of
    // giving up the client verdict.
    $m3 = mod($DICT, $bad, $PART, 'nurse');
    $m3->redcap_save_record(149, '2', 'visit_form', 351, null, null, null, 1);
    $f3 = array_map(function ($c) { return $c[1]['field']; },
        array_values(array_filter($m3->logCalls, function ($c) { return $c[0] === 'invalid-id-saved'; })));
    check('audit: a DEFERRED rule is still enforced server-side', in_array('end_date', $f3, true));

    // ---- 9) a baked literal cannot break out of the <script> element -------
    $xss = $DICT;
    $xssData = [2 => [351 => ['record_id' => '2', 'staff_code' => '</script><img src=x onerror=alert(1)>']]];
    $p10 = page(mod($xss, $xssData, $FULL, 'nurse'), 'form', '2', 'visit_form');
    check('a hostile baked literal does not close the script element',
        strpos($p10['raw'], '</script>') === false);
    check('a hostile baked literal carries no raw < or > at all',
        strpos($p10['raw'], '<') === false && strpos($p10['raw'], '>') === false);
    $U = chr(92) . 'u003C';   // the six characters  \ u 0 0 3 C
    check('the payload IS present, hex-escaped (so the test is not vacuous)',
        stripos($p10['raw'], $U) !== false);
    check('the literal really was baked in (onerror text survives, escaped)',
        strpos($p10['raw'], 'onerror') !== false);

    // ---- 10) BOTH rights sources must agree ------------------------------
    // The framework-native User::getRights($pid) is primary; \REDCap::getUserRights()
    // is the fallback. A build that exposes only the latter must behave
    // identically — this is the shape that made @UVUNIQUE inert in v1.4.0.
    {
        foreach ([false, true] as $forceFallback) {
            $GLOBALS['__TEST_NO_USER_OBJ_RIGHTS'] = $forceFallback;
            $label = $forceFallback ? 'via REDCap::getUserRights fallback' : 'via User::getRights';

            $p = page(mod($DICT, $DATA, $FULL, 'nurse'), 'form', '2', 'visit_form');
            $r = ruleFor($p, 'offref');
            check("10: entitled user gets a baked literal ($label)",
                $r && strpos(json_encode($r['assertAst']), '["lit","' . SECRET . '"]') !== false);
            check("10: and is not deferred ($label)", $r && empty($r['deferred']));

            $p2 = page(mod($DICT, $DATA, $PART, 'nurse'), 'form', '2', 'visit_form');
            $r2 = ruleFor($p2, 'offref');
            check("10: un-entitled user leaks nothing ($label)",
                strpos($p2['html'], SECRET) === false);
            check("10: and IS deferred ($label)", $r2 && !empty($r2['deferred']));
        }
        $GLOBALS['__TEST_NO_USER_OBJ_RIGHTS'] = false;
    }

    // ---- 11) the pid is never passed as REDCap::getUserRights' first arg --
    // A rights table keyed by the PID (what you would get if the pid were
    // passed where the username list belongs) must NOT be mistaken for this
    // user's rights.
    {
        $GLOBALS['__TEST_NO_USER_OBJ_RIGHTS'] = true;
        $pidKeyed = ['149' => ['forms' => ['enroll_form' => '1', 'visit_form' => '1']]];
        $p = page(mod($DICT, $DATA, $pidKeyed, 'nurse'), 'form', '2', 'visit_form');
        check('11: a pid-keyed rights table grants nothing',
            strpos($p['html'], SECRET) === false);
        check('11: and the rule defers', !empty(ruleFor($p, 'offref')['deferred']));
        $GLOBALS['__TEST_NO_USER_OBJ_RIGHTS'] = false;
    }

    echo "crossform_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
