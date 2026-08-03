<?php
/**
 * crossform_resolution_php.php — the THREE-STATE resolution work (v1.6.1).
 *
 * v1.6.0 shipped cross-instrument @UVASSERT by reading the referenced field's
 * saved value on the server. readValues() returned a plain field => value map,
 * so a field that was never read looked exactly like a field that was read and
 * found blank: both were simply absent from the map, and Logic::operandValue
 * rendered both as ''. The module then validated confidently against a value it
 * had never seen.
 *
 * 1.6.1 makes the read report a per-field RESOLUTION:
 *
 *   'ok'         located in a node this context may read (the value may be
 *                empty — a saved blank IS an answer and folds as ['lit',''])
 *   'missing'    the form is not designated for this event
 *   'ambiguous'  the field lives in a DIFFERENT repeating instrument, so which
 *                instance pairs with this one is undefined
 *   'unreadable' the read itself threw, came back malformed, or the record was
 *                not in the result
 *
 * Anything but 'ok' means "no answer": Logic::fold() refuses to bake it, sets
 * $frozen (the rule ships 'deferred'), and records it in $blocked; the audit and
 * the project scan skip the rule and emit an 'unconfigurable' note.
 *
 * Every check below is a counterexample from the 1.6.0 review, asserting the
 * EXACT resolved value rather than merely the absence of a wrong one:
 *
 *   H-01  a reference across two INDEPENDENTLY repeating instruments is refused
 *         as ambiguous, never guessed — and the four shapes that must STILL
 *         resolve (repeating -> base, same instrument, repeating EVENT, and the
 *         mirror image: non-repeating host -> repeating referenced field)
 *   H-04  a failed read is not a blank — with the saved-blank CONTRAST, which is
 *         the whole point of the distinction
 *   M-03  an unknown data dictionary means nothing is live AND forces deferral
 *   M-01  a field on a form not designated for this event defers, and the
 *         fail-open path (mapping unavailable) keeps pre-1.6.1 behaviour
 *   H-02  saving the REFERENCED instrument audits the dependent constraint,
 *         while an unrelated instrument still reads ZERO data (PER-001)
 *
 * Run:  php tests/crossform_resolution_php.php
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

        /**
         * PER-001 instrumentation: every \REDCap::getData() the module makes.
         * A save on an instrument with neither a rule nor a dependant must read
         * NOTHING, so this counter staying at 0 is the assertion.
         */
        public static $getDataCalls = 0;

        /**
         * H-04. 'ok' returns the fixture; the other three are the distinct read
         * failures that used to be indistinguishable from an empty record.
         *   'throw'    the API raised
         *   'nonarray' the API answered something that is not an array
         *   'norecord' the read succeeded but this record is not in the result
         */
        public static $getDataMode = 'ok';

        /**
         * M-01. null models a build/project where the instrument-event mapping
         * cannot be established — the module must then NOT claim 'missing'.
         */
        public static $eventMappings = null;

        public static function getData($p) {
            self::$getDataCalls++;
            if (self::$getDataMode === 'throw') throw new \RuntimeException('simulated getData failure');
            if (self::$getDataMode === 'nonarray') return false;
            if (self::$getDataMode === 'norecord') return ['999' => [1 => ['record_id' => '999']]];
            return self::$data;
        }
        public static function getDataDictionary($pid, $f = 'array') {
            if (!$pid) throw new \RuntimeException('needs pid');
            return self::$dictionary;
        }
        public static function getUserRights($pid = null, $u = null) { return self::$rights; }
        public static function getGroupNames($a = false, $b = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
        public static function getInstrumentEventMappings($pid = null) { return self::$eventMappings; }
    }
    require_once __DIR__ . '/../UniversalValidator.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    const PID = 700;
    /** Every form readable, so nothing below is ever refused for RIGHTS reasons
     *  (crossform_php.php owns that axis) — a refusal here is a RESOLUTION one. */
    $RIGHTS = ['nurse' => ['forms' => ['fa' => '1', 'fb' => '1', 'fc' => '1']]];

    /** A text-field dictionary: field => form, plus optional annotations. */
    function dict(array $formOf, array $ann = []) {
        $d = [];
        foreach ($formOf as $f => $form) {
            $d[$f] = ['field_type' => 'text', 'form_name' => $form,
                      'field_annotation' => isset($ann[$f]) ? $ann[$f] : ''];
        }
        return $d;
    }
    /** The cross-form constraint under test, hard-blocking so a false verdict would bite. */
    function tag($expr) {
        return '@UVASSERT={"assert":"' . $expr . '","message":"cross-form","blockSave":"hard"}';
    }
    /** The three-field layout most sections use: host on fa, referenced on fb. */
    function crossDict($hostForm = 'fa', $refForm = 'fb') {
        return dict(['record_id' => 'fa', 'a_val' => $hostForm, 'b_open' => $refForm],
                    ['a_val' => tag('[a_val]=[b_open]')]);
    }

    function mkMod($dict, $rights, $data, $subs = [], $user = 'nurse') {
        $GLOBALS['__TEST_USER'] = $user;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->subSettings = $subs; $m->projectSettings = ['log-values' => ''];
        $m->projectIdReturn = PID;
        \REDCap::$dictionary = $dict; \REDCap::$rights = $rights; \REDCap::$data = $data;
        \REDCap::$getDataCalls = 0; \REDCap::$getDataMode = 'ok'; \REDCap::$eventMappings = null;
        return $m;
    }
    function render($m, $form, $rec = '1', $evt = 1, $inst = 1) {
        ob_start();
        $m->redcap_data_entry_form_top(PID, $rec, $form, $evt, null, $inst);
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
    function assertAst($p, $f) {
        $r = ruleOf($p, $f);
        return json_encode(($r && isset($r['assertAst'])) ? $r['assertAst'] : null);
    }
    /** The 'deferredWhy' strings the page carries, flattened for matching. */
    function why($p, $f) {
        $r = ruleOf($p, $f);
        return ($r && isset($r['deferredWhy']) && is_array($r['deferredWhy'])) ? $r['deferredWhy'] : [];
    }
    function logsOf($m, $type) {
        return array_values(array_filter($m->logCalls, function ($c) use ($type) { return $c[0] === $type; }));
    }
    function invalid($m) { return logsOf($m, 'invalid-id-saved'); }
    function unconf($m)  { return logsOf($m, 'uvalidate-unconfigurable'); }
    /**
     * How many entries of $list carry $needle. $list is either a list of arrays
     * (log params, scan rows) read at $key, or a plain list of strings (the
     * deferredWhy notices), in which case $key is ignored.
     */
    function saying(array $list, $key, $needle) {
        return count(array_filter($list, function ($e) use ($key, $needle) {
            $s = is_array($e) ? (isset($e[$key]) ? (string) $e[$key] : '') : (string) $e;
            return $s !== '' && strpos($s, $needle) !== false;
        }));
    }

    // The exact wording resolutionProblem() produces, so a reworded message has
    // to be looked at rather than silently passing a `stripos(..., 'repeat')`.
    const W_AMBIG = 'on a different repeating instrument';
    const W_MISS  = 'not collected in this event';
    const W_READ  = 'reading its saved value failed';

    /* =====================================================================
     * H-01  two INDEPENDENTLY repeating instruments
     * ===================================================================== */
    {
        $D = crossDict();
        // fa instance 1 pairs with fb instance 1 only by coincidence of numbering.
        // REDCap defines no such pairing, so the module must refuse — even though
        // every pair here MATCHES, which is what makes the old behaviour a FALSE
        // violation rather than a lucky pass: pre-1.6.1 b_open read as '' and
        // "PAIR-ONE" = "" was logged on every save.
        $matched = [1 => [
            1 => ['record_id' => '1'],
            'repeat_instances' => [1 => [
                'fa' => [1 => ['a_val' => 'PAIR-ONE'], 2 => ['a_val' => 'PAIR-TWO']],
                'fb' => [1 => ['b_open' => 'PAIR-ONE'], 2 => ['b_open' => 'PAIR-TWO']],
            ]],
        ]];

        $p = render(mkMod($D, $RIGHTS, $matched), 'fa', '1', 1, 1);
        $r = ruleOf($p, 'a_val');
        check('H-01 render: the rule is injected', $r !== null);
        check('H-01 render: NOTHING is baked — the whole comparison is a constant',
            assertAst($p, 'a_val') === '["const",false]');
        check('H-01 render: no instance value reaches the page at all',
            strpos($p['html'], 'PAIR-ONE') === false && strpos($p['html'], 'PAIR-TWO') === false);
        check('H-01 render: the rule is deferred (it can never block)', $r && !empty($r['deferred']));
        check('H-01 render: exactly one deferral reason is given', count(why($p, 'a_val')) === 1);
        check('H-01 render: and it names the repeating-instrument ambiguity',
            saying(why($p, 'a_val'), 0, W_AMBIG) === 1);
        check('H-01 render: the reason names the field that could not be resolved',
            saying(why($p, 'a_val'), 0, '"[b_open]"') === 1);
        check('H-01 render: nothing is snapshotted (nothing was read)',
            $r !== null && !isset($r['snapshotFields']));

        // The audit: a genuinely consistent pair must not be logged as broken.
        $m = mkMod($D, $RIGHTS, $matched);
        $m->redcap_save_record(PID, '1', 'fa', 1, null, null, null, 1);
        check('H-01 audit: ZERO violations for a matching cross-repeat pair', count(invalid($m)) === 0);
        check('H-01 audit: the refusal is surfaced, not silent', count(unconf($m)) === 1);
        check('H-01 audit: the note names the repeating-instrument ambiguity',
            saying(array_column(unconf($m), 1), 'why', W_AMBIG) === 1);

        // ...and a genuinely WRONG pair is ALSO not logged. "Not logged" here
        // means unresolvable, not valid — so the unconfigurable note must still
        // be there, otherwise this would be a silent pass.
        $mismatched = $matched;
        $mismatched[1]['repeat_instances'][1]['fb'] = [1 => ['b_open' => 'OTHER-ONE'], 2 => ['b_open' => 'OTHER-TWO']];
        $m2 = mkMod($D, $RIGHTS, $mismatched);
        $m2->redcap_save_record(PID, '1', 'fa', 1, null, null, null, 1);
        check('H-01 audit: a WRONG cross-repeat pair is not logged as a violation either',
            count(invalid($m2)) === 0);
        check('H-01 audit: it is reported as unresolvable instead of passing silently',
            count(unconf($m2)) === 1
            && saying(array_column(unconf($m2), 1), 'why', W_AMBIG) === 1);

        // The project scan reaches the same verdict through its own context path.
        $m3 = mkMod($D, $RIGHTS, $mismatched);
        $res = $m3->scanProject(PID);
        check('H-01 scan: ZERO violations', count($res['violations']) === 0);
        check('H-01 scan: one unconfigurable entry for the unresolvable b_open reference',
            saying($res['unconfigurable'], 'why', '"[b_open]"') === 1);
        check('H-01 scan: it names the repeating-instrument ambiguity',
            saying($res['unconfigurable'], 'why', W_AMBIG) >= 1);
        check('H-01 scan: the scan really did walk the repeating rows (not a no-op)',
            $res['stats']['contexts'] >= 5);
    }

    /* ---- H-01a: repeating host -> NON-repeating referenced field ---------- */
    {
        $D = crossDict();
        // fb does not repeat, so its single base-row value pairs with every fa
        // instance. Unambiguous — this must keep working.
        $data = [1 => [
            1 => ['record_id' => '1', 'b_open' => 'BASE-VALUE'],
            'repeat_instances' => [1 => ['fa' => [
                1 => ['a_val' => 'INST-ONE'], 2 => ['a_val' => 'INST-TWO'],
            ]]],
        ]];
        $p = render(mkMod($D, $RIGHTS, $data), 'fa', '1', 1, 2);
        $r = ruleOf($p, 'a_val');
        check('H-01a: the base-row value is baked in, host stays live',
            assertAst($p, 'a_val') === '["cmp","=",["ref","a_val",null],["lit","BASE-VALUE"]]');
        check('H-01a: the rule is NOT deferred', $r && empty($r['deferred']));
        check('H-01a: the baked field is named as a render-time snapshot (M-02)',
            $r && isset($r['snapshotFields']) && $r['snapshotFields'] === ['b_open']);
    }

    /* ---- H-01b: BOTH fields on the SAME repeating instrument -------------- */
    {
        $D = dict(['record_id' => 'fa', 'a_val' => 'fa', 'b_open' => 'fa'],
                  ['a_val' => tag('[a_val]=[b_open]')]);
        $data = [1 => [
            1 => ['record_id' => '1'],
            'repeat_instances' => [1 => ['fa' => [
                1 => ['a_val' => 'SAME-ONE', 'b_open' => 'SAME-ONE'],
                2 => ['a_val' => 'HOST-TWO', 'b_open' => 'REF-TWO'],
            ]]],
        ]];
        $p = render(mkMod($D, $RIGHTS, $data), 'fa', '1', 1, 2);
        $r = ruleOf($p, 'a_val');
        check('H-01b: both refs stay LIVE — the browser reads both from the page',
            assertAst($p, 'a_val') === '["cmp","=",["ref","a_val",null],["ref","b_open",null]]');
        check('H-01b: nothing is baked, so nothing is snapshotted',
            $r && !isset($r['snapshotFields']));
        check('H-01b: the rule is NOT deferred', $r && empty($r['deferred']));

        // ...and the audit reads THIS instance, not the record's first row.
        $m = mkMod($D, $RIGHTS, $data);
        $m->redcap_save_record(PID, '1', 'fa', 1, null, null, null, 2);
        check('H-01b audit: instance 2 (HOST-TWO vs REF-TWO) is logged', count(invalid($m)) === 1
            && invalid($m)[0][1]['field'] === 'a_val');
        $m2 = mkMod($D, $RIGHTS, $data);
        $m2->redcap_save_record(PID, '1', 'fa', 1, null, null, null, 1);
        check('H-01b audit: instance 1 (SAME-ONE vs SAME-ONE) is silent', count(invalid($m2)) === 0);
    }

    /* ---- H-01c: the repeating EVENT bucket -------------------------------- */
    {
        $D = crossDict();
        // "" is the repeating-EVENT bucket: every form in the event shares the
        // instance, so instance N of fa and instance N of fb ARE the same row.
        $data = [1 => ['repeat_instances' => [1 => ['' => [
            1 => ['record_id' => '1', 'a_val' => 'EVT-ONE-A', 'b_open' => 'EVT-ONE-B'],
            2 => ['record_id' => '1', 'a_val' => 'EVT-TWO-A', 'b_open' => 'EVT-TWO-B'],
        ]]]]];
        $p2 = render(mkMod($D, $RIGHTS, $data), 'fa', '1', 1, 2);
        check('H-01c: instance 2 bakes instance 2\'s value',
            assertAst($p2, 'a_val') === '["cmp","=",["ref","a_val",null],["lit","EVT-TWO-B"]]');
        check('H-01c: instance 1\'s value is nowhere on the page',
            strpos($p2['html'], 'EVT-ONE-B') === false);
        check('H-01c: instance 2 is not deferred', empty(ruleOf($p2, 'a_val')['deferred']));
        $p1 = render(mkMod($D, $RIGHTS, $data), 'fa', '1', 1, 1);
        check('H-01c: instance 1 bakes instance 1\'s value',
            assertAst($p1, 'a_val') === '["cmp","=",["ref","a_val",null],["lit","EVT-ONE-B"]]');
    }

    /* ---- H-01d: NON-repeating host -> repeating referenced field ---------- */
    {
        $D = crossDict();
        // The mirror image of H-01: the host is a plain base-row field, the
        // referenced field repeats. There is still no defined instance to pick.
        $data = [1 => [
            1 => ['record_id' => '1', 'a_val' => 'HOSTVALUE'],
            'repeat_instances' => [1 => ['fb' => [
                1 => ['b_open' => 'HOSTVALUE'], 2 => ['b_open' => 'HOSTVALUE'],
            ]]],
        ]];
        $p = render(mkMod($D, $RIGHTS, $data), 'fa', '1', 1, 1);
        $r = ruleOf($p, 'a_val');
        check('H-01d: a repeating referenced field is refused, not guessed',
            assertAst($p, 'a_val') === '["const",false]');
        check('H-01d: the rule is deferred', $r && !empty($r['deferred']));
        check('H-01d: only the repeating side is blamed', count(why($p, 'a_val')) === 1
            && saying(why($p, 'a_val'), 0, '"[b_open]"') === 1
            && saying(why($p, 'a_val'), 0, W_AMBIG) === 1);
    }

    /* =====================================================================
     * H-04  a failed read is NOT a blank
     * ===================================================================== */
    {
        $D = crossDict();
        $data = [1 => [1 => ['record_id' => '1', 'a_val' => 'SAME', 'b_open' => 'SAME']]];

        foreach (['throw' => 'getData throws', 'nonarray' => 'getData returns a non-array',
                  'norecord' => 'the record is absent from the result'] as $mode => $label) {
            $m = mkMod($D, $RIGHTS, $data);
            \REDCap::$getDataMode = $mode;
            $p = render($m, 'fa', '1', 1, 1);
            $r = ruleOf($p, 'a_val');
            check("H-04 ($label): nothing is baked", assertAst($p, 'a_val') === '["const",false]');
            check("H-04 ($label): no value reaches the page", strpos($p['html'], 'SAME') === false);
            check("H-04 ($label): the rule is deferred", $r && !empty($r['deferred']));
            check("H-04 ($label): the reason says the READ failed, not that the field is blank",
                saying(why($p, 'a_val'), 0, W_READ) >= 1);

            // The audit must not turn the failed read into a violation either.
            // An empty value map is indistinguishable from "every field is
            // blank" for EVERY rule kind — @UVREQUIRED would call a populated
            // field empty and a check rule would pass an invalid ID — so the
            // whole save's audit stops and says so, rather than per-rule notes.
            // 'debug-log' is on so the reason itself can be pinned, not just the
            // exception class.
            $m2 = mkMod($D, $RIGHTS, $data);
            $m2->projectSettings['debug-log'] = '1';
            \REDCap::$getDataMode = $mode;
            $m2->redcap_save_record(PID, '1', 'fa', 1, null, null, null, 1);
            check("H-04 ($label) audit: ZERO false violations", count(invalid($m2)) === 0);
            check("H-04 ($label) audit: nothing is quietly passed as a config note",
                count(unconf($m2)) === 0);
            $err = logsOf($m2, 'uvalidate-audit-error');
            check("H-04 ($label) audit: exactly one visible audit-error entry",
                count($err) === 1 && $err[0][1]['stage'] === 'audit');
            check("H-04 ($label) audit: and it explains that nothing was checked",
                count($err) === 1 && strpos($err[0][1]['detail'],
                    $mode === 'throw' ? 'simulated getData failure'
                                      : 'could not be read, so no rule was checked') !== false);
        }

        // THE CONTRAST. This is the whole point of the three-state work: a field
        // that really was read and really is blank stays 'ok' and bakes as ''.
        $blank = [1 => [1 => ['record_id' => '1', 'a_val' => 'SAME', 'b_open' => '']]];
        $p = render(mkMod($D, $RIGHTS, $blank), 'fa', '1', 1, 1);
        $r = ruleOf($p, 'a_val');
        check('H-04 contrast: a saved-blank reference bakes as an EMPTY literal',
            assertAst($p, 'a_val') === '["cmp","=",["ref","a_val",null],["lit",""]]');
        check('H-04 contrast: and the rule is NOT deferred', $r && empty($r['deferred']));
        check('H-04 contrast: and carries no deferral reason', $r && !isset($r['deferredWhy']));
        check('H-04 contrast: the empty value is still a render-time snapshot',
            $r && isset($r['snapshotFields']) && $r['snapshotFields'] === ['b_open']);

        // Same verdict when REDCap omits the blank field entirely, which is what
        // getData actually does — absence alone must never mean unresolvable.
        $absent = [1 => [1 => ['record_id' => '1', 'a_val' => 'SAME']]];
        $p2 = render(mkMod($D, $RIGHTS, $absent), 'fa', '1', 1, 1);
        check('H-04 contrast: an ABSENT (never entered) reference also bakes as ""',
            assertAst($p2, 'a_val') === '["cmp","=",["ref","a_val",null],["lit",""]]');
        check('H-04 contrast: and is not deferred', empty(ruleOf($p2, 'a_val')['deferred']));

        // ...and the audit still enforces against that blank: 'SAME' <> '' is a
        // real violation, so the three failure modes above are silent because
        // the READ failed, not because the audit went quiet.
        $m = mkMod($D, $RIGHTS, $blank);
        $m->redcap_save_record(PID, '1', 'fa', 1, null, null, null, 1);
        check('H-04 contrast audit: a real mismatch against a blank IS logged',
            count(invalid($m)) === 1 && invalid($m)[0][1]['field'] === 'a_val'
            && invalid($m)[0][1]['type'] === 'constraint');
        check('H-04 contrast audit: and it is a violation, not an unconfigurable note',
            count(unconf($m)) === 0);
    }

    /* =====================================================================
     * M-03  an unknown data dictionary
     * ===================================================================== */
    {
        // With no dictionary the module cannot tell which fields are on the
        // page. Pre-1.6.1 it declared every reference live, so the browser read
        // fields that are not in the DOM as '' and validated against them. The
        // rule now comes from the settings dialog, which survives a missing
        // dictionary — an annotation rule could not, since annotations LIVE in
        // the dictionary.
        $row = ['rule-type' => 'constraint', 'fields' => ['a_val'], 'fields-csv' => '', 'when' => '',
                'assert' => '[a_val]=[b_open]', 'message' => 'cross-form',
                'algorithm' => 'iso7064_mod37_36', 'source' => '', 'suggest-fix' => '',
                'pattern' => '', 'strip' => '', 'keep-chars' => '', 'id-lengths' => '',
                'id-min-len' => '', 'id-max-len' => '', 'expected-count' => '', 'block-save' => 'hard'];
        // Values chosen EQUAL so the server-side fold settles to TRUE: the rule
        // must be deferred on the grounds that nothing is live, not because the
        // constant happened to be false.
        $data = [1 => [1 => ['record_id' => '1', 'a_val' => 'MMVALUE', 'b_open' => 'MMVALUE']]];
        $p = render(mkMod([], $RIGHTS, $data, [$row]), 'fa', '1', 1, 1);
        $r = ruleOf($p, 'a_val');
        check('M-03: the rule is still injected (the dictionary is not the rule source here)',
            $r !== null && empty($r['configError']));
        check('M-03: NO live ["ref",...] operand is shipped for an off-page field',
            strpos(assertAst($p, 'a_val'), '"ref"') === false);
        check('M-03: the whole condition is settled server-side',
            assertAst($p, 'a_val') === '["const",true]');
        check('M-03: and the rule is deferred despite the constant being TRUE',
            $r && !empty($r['deferred']));
        check('M-03: no record value is baked or leaked',
            strpos($p['html'], 'MMVALUE') === false && $r !== null && !isset($r['snapshotFields']));
    }

    /* =====================================================================
     * M-01  a form not designated for this event
     * ===================================================================== */
    {
        $D = crossDict();
        // b_open's value is deliberately PRESENT in the data. What makes it
        // unreadable here is the project's instrument-event mapping, not the
        // absence of a value — which is exactly what the fail-open case below
        // holds constant.
        $data = [1 => [1 => ['record_id' => '1', 'a_val' => 'X', 'b_open' => 'X']]];
        $offEvent = ['arm1' => [
            ['event_id' => 1, 'form' => 'fa'],
            ['event_id' => 2, 'form' => 'fb'],   // fb is collected in event 2 only
        ]];

        $m = mkMod($D, $RIGHTS, $data);
        \REDCap::$eventMappings = $offEvent;
        $p = render($m, 'fa', '1', 1, 1);
        $r = ruleOf($p, 'a_val');
        check('M-01: an off-event reference is not baked',
            assertAst($p, 'a_val') === '["const",false]');
        check('M-01: the rule is deferred', $r && !empty($r['deferred']));
        check('M-01: the reason says the field is not collected in this event',
            count(why($p, 'a_val')) === 1 && saying(why($p, 'a_val'), 0, W_MISS) === 1
            && saying(why($p, 'a_val'), 0, '"[b_open]"') === 1);

        $m2 = mkMod($D, $RIGHTS, $data);
        \REDCap::$eventMappings = $offEvent;
        $m2->redcap_save_record(PID, '1', 'fa', 1, null, null, null, 1);
        check('M-01 audit: ZERO violations from an off-event reference', count(invalid($m2)) === 0);
        check('M-01 audit: reported as unresolvable instead', count(unconf($m2)) === 1
            && saying(array_column(unconf($m2), 1), 'why', W_MISS) === 1);

        // The REALISTIC shape of an off-event reference: the value is not in the
        // data at all, because the form is not collected here. Evaluating that
        // compares "X" against "" and logs a violation for correct data on every
        // single save — the M-01 defect itself.
        $noValue = [1 => [1 => ['record_id' => '1', 'a_val' => 'X']]];
        $m2b = mkMod($D, $RIGHTS, $noValue);
        \REDCap::$eventMappings = $offEvent;
        $m2b->redcap_save_record(PID, '1', 'fa', 1, null, null, null, 1);
        check('M-01 audit: an absent off-event value is NOT logged as a violation',
            count(invalid($m2b)) === 0);
        check('M-01 audit: it is surfaced as unresolvable', count(unconf($m2b)) === 1
            && saying(array_column(unconf($m2b), 1), 'why', W_MISS) === 1);

        // The mapping is genuinely read: designate fb for THIS event and the
        // same data resolves normally.
        $m3 = mkMod($D, $RIGHTS, $data);
        \REDCap::$eventMappings = ['arm1' => [
            ['event_id' => 1, 'form' => 'fa'], ['event_id' => 1, 'form' => 'fb'],
        ]];
        $p3 = render($m3, 'fa', '1', 1, 1);
        check('M-01 control: a form designated for this event resolves and bakes',
            assertAst($p3, 'a_val') === '["cmp","=",["ref","a_val",null],["lit","X"]]');
        check('M-01 control: and is not deferred', empty(ruleOf($p3, 'a_val')['deferred']));

        // FAIL-OPEN: no usable mapping must NOT be read as "missing". Classic
        // projects and builds without the API keep pre-1.6.1 behaviour.
        $m4 = mkMod($D, $RIGHTS, $data);   // mkMod resets $eventMappings to null
        $p4 = render($m4, 'fa', '1', 1, 1);
        $r4 = ruleOf($p4, 'a_val');
        check('M-01 fail-open: an unavailable mapping does NOT claim "missing"',
            assertAst($p4, 'a_val') === '["cmp","=",["ref","a_val",null],["lit","X"]]');
        check('M-01 fail-open: the rule is not deferred', $r4 && empty($r4['deferred']));
        check('M-01 fail-open: and no deferral reason is invented', $r4 && !isset($r4['deferredWhy']));

        $m5 = mkMod($D, $RIGHTS, $data);
        $m5->redcap_save_record(PID, '1', 'fa', 1, null, null, null, 1);
        check('M-01 fail-open audit: the rule is evaluated normally (matching pair, silent)',
            count(invalid($m5)) === 0 && count(unconf($m5)) === 0);

        // The exact cost of failing open, pinned rather than implied: with no
        // mapping the same absent value IS evaluated as a blank and IS logged.
        // That is pre-1.6.1 behaviour, deliberately preserved — deferring on a
        // guess would silence rules that work today.
        $m6 = mkMod($D, $RIGHTS, $noValue);
        $m6->redcap_save_record(PID, '1', 'fa', 1, null, null, null, 1);
        check('M-01 fail-open audit: without a mapping the blank is still evaluated (unchanged)',
            count(invalid($m6)) === 1 && invalid($m6)[0][1]['field'] === 'a_val'
            && count(unconf($m6)) === 0);
    }

    /* =====================================================================
     * H-02  reverse dependencies, and PER-001
     * ===================================================================== */
    {
        // fc exists only to be irrelevant: no rule, and no rule references it.
        $D = dict(['record_id' => 'fa', 'a_val' => 'fa', 'b_open' => 'fb', 'c_other' => 'fc'],
                  ['a_val' => tag('[a_val]=[b_open]')]);
        $broken = [1 => [1 => ['record_id' => '1', 'a_val' => 'AAA', 'b_open' => 'BBB', 'c_other' => 'CCC']]];
        $valid  = [1 => [1 => ['record_id' => '1', 'a_val' => 'AAA', 'b_open' => 'AAA', 'c_other' => 'CCC']]];

        // Saving the REFERENCED instrument breaks a pair that the host form's
        // own save left valid. Before 1.6.1 nothing checked it.
        $m = mkMod($D, $RIGHTS, $broken);
        $m->redcap_save_record(PID, '1', 'fb', 1, null, null, null, 1);
        check('H-02: saving the REFERENCED instrument audits the dependent constraint',
            count(invalid($m)) === 1 && invalid($m)[0][1]['field'] === 'a_val'
            && invalid($m)[0][1]['type'] === 'constraint');
        $callsForReferenced = \REDCap::$getDataCalls;

        $m2 = mkMod($D, $RIGHTS, $valid);
        $m2->redcap_save_record(PID, '1', 'fb', 1, null, null, null, 1);
        check('H-02: a satisfied dependent constraint logs nothing',
            count($m2->logCalls) === 0);

        // PER-001: an instrument with neither a rule nor a dependant must not
        // read a single row. The widening is per-rule, never project-wide.
        $m3 = mkMod($D, $RIGHTS, $broken);
        $m3->redcap_save_record(PID, '1', 'fc', 1, null, null, null, 1);
        check('H-02/PER-001: an unrelated instrument reads ZERO data',
            \REDCap::$getDataCalls === 0);
        check('H-02/PER-001: and logs nothing at all', count($m3->logCalls) === 0);
        check('H-02/PER-001: (the counter works — the dependent save DID read)',
            $callsForReferenced > 0);

        // No regression: the host instrument still audits its own rule.
        $m4 = mkMod($D, $RIGHTS, $broken);
        $m4->redcap_save_record(PID, '1', 'fa', 1, null, null, null, 1);
        check('H-02: the HOST instrument still audits its own constraint',
            count(invalid($m4)) === 1 && invalid($m4)[0][1]['field'] === 'a_val');
    }

    echo "crossform_resolution_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
