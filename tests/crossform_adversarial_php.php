<?php
/**
 * crossform_adversarial_php.php — red-team of the 1.6.0 cross-form fix.
 *
 * crossform_php.php covers the intended paths. This file attacks the edges the
 * happy path never reaches, in the spirit of the 1.5.8 adversarial pass:
 *
 *   A. checkbox refs baked as literals resolve to '1'/'0', not the raw map
 *   B. $frozen propagates out of and/or/not nesting (a by-ref through recursion)
 *   C. a condition MIXING a disclosable and a non-disclosable off-page ref must
 *      defer as a whole — one baked literal must not buy trust for the rest
 *   D. a comparison with NO live side folds but must NOT mark the rule deferred
 *      (it could never react anyway, and deferring would needlessly drop a
 *      correct save-block)
 *   E. branch-level deferral is carried per branch, not smeared across the rule
 *   F. a frozen "when" gate does NOT defer the rule when the assert is live
 *      (the gate references a field the user cannot change from this page, so a
 *      page-load verdict is correct — same semantics as REDCap branching)
 *   G. condition-text de-duplication across rules cannot leak one rule's frozen
 *      flag onto another rule whose condition merely looks the same
 *   H. an empty referenced value bakes as '' and does not crash or leak
 *   I. repeating instruments bake the CURRENT instance's value
 *   J. an off-page ref to a field that does not exist at all
 *   K. non-constraint modes (single/pooled/unique/choices) are untouched
 *
 * Run:  php tests/crossform_adversarial_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $logCalls = []; public $subSettings = []; public $projectSettings = [];
        public $systemSettings = []; public $projectIdReturn = null;
        public function getSubSettings($k, $pid = null) {
            $e = ($pid !== null && $pid !== '') ? $pid : $this->projectIdReturn;
            return $e ? $this->subSettings : [];
        }
        public function getProjectSetting($k, $pid = null) {
            $e = ($pid !== null && $pid !== '') ? $pid : $this->projectIdReturn;
            if (!$e) return null;
            return isset($this->projectSettings[$k]) ? $this->projectSettings[$k] : null;
        }
        public function getSystemSetting($k) { return null; }
        public function setSystemSetting($k, $v) {}
        public function getProjectId() { return $this->projectIdReturn; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return count($this->logCalls); }
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
    }
}

namespace {
    class REDCap {
        public static $data = []; public static $dictionary = []; public static $rights = [];
        public static function getData($p) { return self::$data; }
        public static function getDataDictionary($pid, $f = 'array') {
            if (!$pid) throw new \RuntimeException('needs pid');
            return self::$dictionary;
        }
        public static function getUserRights($pid = null, $u = null) { return self::$rights; }
        public static function getGroupNames($a = false, $b = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
    }
    require_once __DIR__ . '/../UniversalValidator.php';
    use INSPIRE\UniversalValidator\Logic;

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }
    function fold($expr, $values, $live, $disclosable, &$frozen = false) {
        $p = Logic::parse($expr);
        if (empty($p['ok'])) return ['PARSE_ERROR', $p];
        return Logic::fold($p['ast'], $values, $live, $disclosable, $frozen);
    }
    function enc($ast) { return json_encode($ast); }

    // ---- A. checkbox refs bake as '1'/'0' ---------------------------------
    {
        $vals = ['cb' => ['1' => '1', '2' => '0']];
        $live = ['mine' => true];
        $disc = ['cb' => true];
        $a = fold("[mine]=[cb(1)]", $vals, $live, $disc);
        check('A: a checked checkbox ref bakes as "1"', strpos(enc($a), '["lit","1"]') !== false);
        $b = fold("[mine]=[cb(2)]", $vals, $live, $disc);
        check('A: an unchecked checkbox ref bakes as "0"', strpos(enc($b), '["lit","0"]') !== false);
        check('A: the raw code map never reaches the AST', strpos(enc($a), '{"1"') === false);
        // and un-disclosable checkbox still folds whole
        $fz = false;
        $c = fold("[mine]=[cb(1)]", $vals, $live, [], $fz);
        check('A: an un-disclosable checkbox folds to a const', strpos(enc($c), '"const"') !== false);
        check('A: and marks the rule frozen', $fz === true);
    }

    // ---- B. $frozen escapes and/or/not nesting ----------------------------
    {
        $vals = ['off' => 'x', 'a' => '1'];
        $live = ['a' => true, 'b' => true];
        $fz = false;
        fold("([a]=[off]) and ([b]='1')", $vals, $live, [], $fz);
        check('B: frozen propagates out of "and"', $fz === true);
        $fz = false;
        fold("([b]='1') or ([a]=[off])", $vals, $live, [], $fz);
        check('B: frozen propagates out of "or"', $fz === true);
        $fz = false;
        fold("not ([a]=[off])", $vals, $live, [], $fz);
        check('B: frozen propagates out of "not"', $fz === true);
        $fz = false;
        fold("not (([a]=[off]) and ([b]='1'))", $vals, $live, [], $fz);
        check('B: frozen propagates out of nested not/and', $fz === true);
        $fz = false;
        fold("[a]='1' and [b]='2'", $vals, $live, [], $fz);
        check('B: an all-live condition is NOT frozen', $fz === false);
    }

    // ---- C. mixed disclosable / non-disclosable -> defer as a whole -------
    {
        $vals = ['ok_f' => 'V1', 'secret' => 'S1', 'a' => '', 'b' => ''];
        $live = ['a' => true, 'b' => true];
        $disc = ['ok_f' => true];                 // secret deliberately NOT disclosable
        $fz = false;
        $ast = fold("[a]=[ok_f] and [b]=[secret]", $vals, $live, $disc, $fz);
        $e = enc($ast);
        check('C: the disclosable side is baked', strpos($e, '["lit","V1"]') !== false);
        check('C: the secret is NOT in the AST', strpos($e, 'S1') === false);
        check('C: the secret comparison is folded to a const', strpos($e, '"const"') !== false);
        check('C: the rule is marked frozen (so it will defer)', $fz === true);
    }

    // ---- D. no live side -> fold, but do NOT defer ------------------------
    {
        $vals = ['o1' => 'x', 'o2' => 'x'];
        $live = ['mine' => true];
        $fz = false;
        $ast = fold("[o1]=[o2]", $vals, $live, [], $fz);
        check('D: an all-off-page comparison folds to a const', strpos(enc($ast), '"const"') !== false);
        check('D: and does NOT mark the rule frozen (nothing live was lost)', $fz === false);
        // literal-only comparisons likewise
        $fz = false;
        fold("'1'='1'", $vals, $live, [], $fz);
        check('D: a literal-only comparison does not freeze', $fz === false);
    }

    // ---- E..K need the full module (rights, dictionary, page render) ------
    $DICT = [
        'record_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'fa'],
        'a_val'     => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'fa'],
        'a_gate'    => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'fa'],
        'b_open'    => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'fb'],   // readable
        'c_secret'  => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'fc'],   // NOT readable
    ];
    $RIGHTS = ['nurse' => ['forms' => ['fa' => '1', 'fb' => '1', 'fc' => '0']]];
    $DATA = [1 => [1 => ['record_id' => '1', 'b_open' => 'OPENVAL', 'c_secret' => 'SECRETVAL']]];

    function mkMod($dict, $rights, $data, $user = 'nurse') {
        $GLOBALS['__TEST_USER'] = $user;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->subSettings = []; $m->projectSettings = ['log-values' => '']; $m->projectIdReturn = 700;
        \REDCap::$dictionary = $dict; \REDCap::$rights = $rights; \REDCap::$data = $data;
        return $m;
    }
    function render($m, $form, $rec = '1', $evt = 1, $inst = 1) {
        ob_start();
        $m->redcap_data_entry_form_top(700, $rec, $form, $evt, null, $inst);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return ['html' => $html, 'raw' => isset($mm[1]) ? $mm[1] : '',
                'cfg' => json_decode(isset($mm[1]) ? $mm[1] : 'null', true)];
    }
    function ruleOf($p, $f) {
        foreach ((isset($p['cfg']['rules']) ? $p['cfg']['rules'] : []) as $r) {
            if (in_array($f, isset($r['fields']) ? $r['fields'] : [], true)) return $r;
        }
        return null;
    }

    // ---- E. branch-level deferral is per branch ---------------------------
    {
        $d = $DICT;
        // two @UVASSERT tags -> branches. One references the readable field,
        // the other the unreadable one.
        $d['a_val']['field_annotation'] =
            '@UVASSERT={"assert":"[a_val]=[b_open]","when":"[a_gate]=\'1\'","message":"open","blockSave":"hard"}'
          . ' @UVASSERT={"assert":"[a_val]=[c_secret]","when":"[a_gate]=\'2\'","message":"secret","blockSave":"hard"}';
        $p = render(mkMod($d, $RIGHTS, $DATA), 'fa');
        $r = ruleOf($p, 'a_val');
        check('E: the branched rule is injected', $r !== null && !empty($r['branches']));
        if ($r && !empty($r['branches'])) {
            $open = null; $secret = null;
            foreach ($r['branches'] as $b) {
                if (isset($b['message']) && $b['message'] === 'open') $open = $b;
                if (isset($b['message']) && $b['message'] === 'secret') $secret = $b;
            }
            check('E: the readable branch is NOT deferred', $open !== null && empty($open['deferred']));
            check('E: the readable branch baked its literal',
                $open !== null && strpos(json_encode($open['assertAst']), '["lit","OPENVAL"]') !== false);
            check('E: the unreadable branch IS deferred', $secret !== null && !empty($secret['deferred']));
            check('E: the unreadable branch leaked nothing',
                $secret !== null && strpos(json_encode($secret['assertAst']), 'SECRETVAL') === false);
        }
        check('E: the secret never appears anywhere in the page', strpos($p['html'], 'SECRETVAL') === false);
    }

    // ---- F. a frozen "when" with a LIVE assert must not defer -------------
    // NOTE the deliberate split between the designer's own literal
    // ('GATELITERAL', which the module has always shipped as part of the
    // condition text) and the stored record VALUE ('SECRETVAL', which must
    // never leave the server). Using one string for both would make this test
    // unable to tell a real leak from the designer's own words.
    {
        $d = $DICT;
        $d['a_val']['field_annotation'] =
            '@UVASSERT={"assert":"[a_val]=[a_gate]","when":"[c_secret]=\'GATELITERAL\'","message":"m","blockSave":"hard"}';
        $p = render(mkMod($d, $RIGHTS, $DATA), 'fa');
        $r = ruleOf($p, 'a_val');
        check('F: the assert stays live (both fields on this form)',
            $r && strpos(json_encode($r['assertAst']), '"const"') === false);
        check('F: a frozen WHEN does not defer the rule', $r && empty($r['deferred']));
        check('F: the when is folded to a constant', $r && strpos(json_encode($r['whenAst']), '"const"') !== false);
        check('F: the stored VALUE of the gate field never reaches the page',
            strpos($p['html'], 'SECRETVAL') === false);
        check('F: (control) the designer\'s own literal IS shipped, as always',
            strpos($p['html'], 'GATELITERAL') !== false);
    }

    // ---- G. shared condition text cannot smear a frozen flag --------------
    {
        $d = $DICT;
        // rule 1: assert over the SECRET (will freeze).
        $d['a_val']['field_annotation'] = '@UVASSERT={"assert":"[a_val]=[c_secret]","message":"m1"}';
        // rule 2: a DIFFERENT field, assert entirely live.
        $d['a_gate']['field_annotation'] = '@UVASSERT={"assert":"[a_gate]=[a_val]","message":"m2"}';
        $p = render(mkMod($d, $RIGHTS, $DATA), 'fa');
        $r1 = ruleOf($p, 'a_val');
        $r2 = ruleOf($p, 'a_gate');
        check('G: the secret-referencing rule is deferred', $r1 && !empty($r1['deferred']));
        check('G: the all-live rule is NOT deferred', $r2 && empty($r2['deferred']));
        check('G: the all-live rule stayed live',
            $r2 && strpos(json_encode($r2['assertAst']), '"const"') === false);
    }

    // ---- H. an EMPTY referenced value bakes as '' ------------------------
    {
        $d = $DICT;
        $d['a_val']['field_annotation'] = '@UVASSERT={"assert":"[a_val]=[b_open]","message":"m"}';
        $empty = [1 => [1 => ['record_id' => '1']]];          // b_open never entered
        $p = render(mkMod($d, $RIGHTS, $empty), 'fa');
        $r = ruleOf($p, 'a_val');
        $e = json_encode($r['assertAst']);
        check('H: an empty referenced value still yields a live comparison',
            strpos($e, '"const"') === false);
        check('H: it bakes as an empty literal', strpos($e, '["lit",""]') !== false);
        check('H: the rule is not deferred', empty($r['deferred']));
    }

    // ---- I. repeating instrument: the CURRENT instance is baked -----------
    {
        $d = $DICT;
        $d['a_val']['field_annotation'] = '@UVASSERT={"assert":"[a_val]=[b_open]","message":"m"}';
        $rep = [1 => [
            1 => ['record_id' => '1'],
            'repeat_instances' => [1 => ['fb' => [
                1 => ['b_open' => 'INST-ONE'],
                2 => ['b_open' => 'INST-TWO'],
            ]]],
        ]];
        // rendering fa, instance 2 -> readValues is scoped by instrument "fa",
        // so the fb repeat rows are not picked up; the value must not silently
        // come from the WRONG instance.
        $p1 = render(mkMod($d, $RIGHTS, $rep), 'fa', '1', 1, 1);
        $p2 = render(mkMod($d, $RIGHTS, $rep), 'fa', '1', 1, 2);
        $e1 = json_encode(ruleOf($p1, 'a_val')['assertAst']);
        $e2 = json_encode(ruleOf($p2, 'a_val')['assertAst']);
        check('I: instance 1 never bakes instance 2\'s value', strpos($e1, 'INST-TWO') === false);
        check('I: instance 2 never bakes instance 1\'s value', strpos($e2, 'INST-ONE') === false);
    }

    // ---- J. a ref to a field that does not exist -------------------------
    {
        $d = $DICT;
        $d['a_val']['field_annotation'] = '@UVASSERT={"assert":"[a_val]=[does_not_exist]","message":"m"}';
        $p = render(mkMod($d, $RIGHTS, $DATA), 'fa');
        $r = ruleOf($p, 'a_val');
        check('J: an unknown ref surfaces as a visible config error',
            $r !== null && !empty($r['configError']));
        check('J: and the config error names the problem',
            $r !== null && stripos($r['configError'], 'not a field') !== false);
    }

    // ---- K. non-constraint modes are untouched ---------------------------
    {
        $d = $DICT;
        $d['a_val']['field_annotation'] = '@UVALIDATE';
        $d['a_gate']['field_annotation'] = '@UVREQUIRED={"when":"[c_secret]=\'GATELITERAL\'"}';
        $p = render(mkMod($d, $RIGHTS, $DATA), 'fa');
        $rv = ruleOf($p, 'a_val');
        $rq = ruleOf($p, 'a_gate');
        check('K: a check rule carries no deferred flag', $rv !== null && empty($rv['deferred']));
        check('K: a required rule with a frozen when is not deferred',
            $rq !== null && empty($rq['deferred']));
        check('K: the required rule\'s when still folded (no leak)',
            $rq !== null && strpos(json_encode(isset($rq['whenAst']) ? $rq['whenAst'] : null), '"const"') !== false);
        check('K: no stored record VALUE in the page for any mode',
            strpos($p['html'], 'SECRETVAL') === false);
    }

    // ---- L. the audit is indifferent to all of the above -----------------
    {
        $d = $DICT;
        $d['a_val']['field_annotation'] = '@UVASSERT={"assert":"[a_val]=[c_secret]","message":"m","blockSave":"hard"}';
        $bad = [1 => [1 => ['record_id' => '1', 'a_val' => 'WRONG', 'c_secret' => 'SECRETVAL']]];
        $m = mkMod($d, $RIGHTS, $bad);
        $m->redcap_save_record(700, '1', 'fa', 1, null, null, null, 1);
        $logged = array_map(function ($c) { return $c[1]['field']; },
            array_values(array_filter($m->logCalls, function ($c) { return $c[0] === 'invalid-id-saved'; })));
        check('L: a deferred rule is still fully enforced by the audit',
            in_array('a_val', $logged, true));

        $good = [1 => [1 => ['record_id' => '1', 'a_val' => 'SECRETVAL', 'c_secret' => 'SECRETVAL']]];
        $m2 = mkMod($d, $RIGHTS, $good);
        $m2->redcap_save_record(700, '1', 'fa', 1, null, null, null, 1);
        check('L: and a satisfied one logs nothing',
            count(array_filter($m2->logCalls, function ($c) { return $c[0] === 'invalid-id-saved'; })) === 0);
    }

    echo "crossform_adversarial_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
