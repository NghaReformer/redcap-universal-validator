<?php
/**
 * hosting_php.php — WHERE a rule is evaluated, and what a scan may certify.
 *
 * The third cross-instrument review found one defect wearing five costumes: the
 * module knew how to RESOLVE a reference for a given context, but nothing said
 * which contexts a rule belongs to. Every caller therefore picked its own, and
 * each picked wrongly in a different way.
 *
 *   H-01  a condition settled entirely on the server shipped as a bare
 *         constant, so a stale page-load verdict kept its configured HARD block
 *   H-02  the scan ran every rule in every context: a populated repeating field
 *         reported blank from the base row, an event-1 field reported blank in
 *         event 2, and one rule reported BOTH unconfigurable and hard-violated
 *   H-03  the save audit's reverse-dependency pass logged one copy of a base
 *         host's violation per unrelated repeat row of an unrelated instrument
 *   H-04  the unique aggregator substituted '' for anything it could not read,
 *         so two records whose composite key lives on independently repeating
 *         forms collided and were reported as duplicates of each other
 *   H-05  a scan certified 'complete' with the dictionary unread, with a record
 *         returned empty, and could not survive a throw from rule discovery
 *
 * H-07 is the same defect a sixth time, one layer down: the repeat-metadata
 * cache stored a PER-FORM answer under a form-independent key, so on a build
 * without getRepeatingFormsEvents the single-form probe made by hostContextsFor()
 * became the answer given to contextResolution() for every other form. An
 * instance-less repeating instrument then read as a resolved blank and the scan
 * reported a HARD violation against a value it had never read.
 *
 * Every check below fails on the pre-fix tree. The counts are exact — "not
 * zero" would pass on the amplification bug that produced four logs where one
 * was due.
 *
 * Run:  php tests/hosting_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $logCalls = []; public $subSettings = []; public $projectSettings = [];
        public $systemSettings = []; public $projectIdReturn = null;
        /** H-05/M-03: rule DISCOVERY is a read like any other and can throw. */
        public $settingsThrow = false;
        public function getSubSettings($k, $pid = null) {
            if ($this->settingsThrow) throw new \RuntimeException('simulated settings backend failure');
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
        public function getUser() { return new TestUser('nurse'); }
    }
    class TestUser {
        private $n; public function __construct($n) { $this->n = $n; }
        public function getUsername() { return $this->n; }
        public function hasDesignRights() { return true; }
        public function getRights($pid = null) { return ['forms' => ['fa' => '1', 'fb' => '1', 'fc' => '1', 'fr' => '1', 'f1' => '1']]; }
    }
}

namespace {
    class REDCap {
        public static $data = []; public static $dictionary = [];
        public static $dictThrow = false;
        /** form => repeats?, per event. null = the metadata API is unavailable. */
        public static $repeating = null;
        /** [ ['event_id'=>.., 'form'=>..], ... ] or null = mapping unavailable. */
        public static $eventMappings = null;

        /**
         * H-06. Off by default so every scenario above keeps its exact behaviour.
         * When on, getData models REDCap faithfully: a BLANK field is omitted from
         * the output, and a record left with no rows at all is omitted entirely.
         * That is what made "record absent" indistinguishable from "read failed".
         */
        public static $filterByFields = false;
        /** 'ok' | 'nonarray' | 'otherrecords' — the two shapes that ARE failures. */
        public static $getDataMode = 'ok';
        /** A build where the record-id field cannot be established. */
        public static $pkAvailable = true;
        /**
         * H-07. A build that exposes isRepeatingForm but NOT the whole-event map.
         * That is the shape which forces repeatingFormsForEvent() onto its second,
         * PER-FORM source — the one whose answer describes only the forms it was
         * given. Off by default so every scenario above keeps its exact behaviour.
         */
        public static $repeatMapAvailable = true;

        public static function getData($p) {
            if (self::$getDataMode === 'nonarray') return false;
            if (self::$getDataMode === 'otherrecords') return ['999' => [1 => ['record_id' => '999']]];
            if (!self::$filterByFields || empty($p['fields'])) return self::$data;
            $want = array_flip($p['fields']);
            $strip = function (array $row) use ($want) {
                $r = [];
                foreach ($row as $f => $v) {
                    if (isset($want[$f]) && $v !== '' && $v !== null) $r[$f] = $v;
                }
                return $r;
            };
            $out = [];
            foreach (self::$data as $rec => $node) {
                $keep = [];
                foreach ($node as $k => $sub) {
                    if ($k === 'repeat_instances' || !is_array($sub)) continue;
                    $row = $strip($sub);
                    if ($row) $keep[$k] = $row;
                }
                if (isset($node['repeat_instances']) && is_array($node['repeat_instances'])) {
                    $ri = [];
                    foreach ($node['repeat_instances'] as $evt => $byForm) {
                        foreach ((is_array($byForm) ? $byForm : []) as $form => $byInst) {
                            foreach ((is_array($byInst) ? $byInst : []) as $i => $row) {
                                $r = $strip(is_array($row) ? $row : []);
                                if ($r) $ri[$evt][$form][$i] = $r;
                            }
                        }
                    }
                    if ($ri) $keep['repeat_instances'] = $ri;
                }
                if ($keep) $out[$rec] = $keep;
            }
            return $out;
        }
        public static function getDataDictionary($pid, $f = 'array') {
            if (self::$dictThrow) throw new \RuntimeException('simulated dictionary failure');
            return self::$dictionary;
        }
        public static function getUserRights($pid = null, $u = null) {
            return ['nurse' => ['forms' => ['fa' => '1', 'fb' => '1', 'fc' => '1', 'fr' => '1', 'f1' => '1']]];
        }
        public static function getGroupNames($a = false, $b = null) { return ''; }
        public static function getRecordIdField() { return self::$pkAvailable ? 'record_id' : ''; }
        public static function getInstrumentEventMappings($pid = null) { return self::$eventMappings; }
        public static function getEventNames($u = false, $x = false, $evt = null) { return 'event_' . $evt . '_arm_1'; }
        public static function getRepeatingFormsEvents($pid = null) {
            return self::$repeatMapAvailable ? self::$repeating : null;
        }
        public static function isRepeatingForm($e = null, $f = null) {
            if (self::$repeating === null) return null;
            return isset(self::$repeating[$e][$f]);
        }
    }
    require_once __DIR__ . '/../UniversalValidator.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    const PID = 700;

    /** field => [form, annotation] */
    function dict(array $spec) {
        $d = [];
        foreach ($spec as $f => $s) {
            $d[$f] = ['field_type' => 'text', 'form_name' => $s[0],
                      'field_annotation' => isset($s[1]) ? $s[1] : ''];
        }
        return $d;
    }
    function mkMod($dict, $data, $repeating = null, $mappings = null, $subs = []) {
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->subSettings = $subs; $m->projectSettings = ['log-values' => ''];
        $m->projectIdReturn = PID;
        \REDCap::$dictionary = $dict; \REDCap::$data = $data;
        \REDCap::$repeating = $repeating; \REDCap::$eventMappings = $mappings;
        \REDCap::$dictThrow = false;
        \REDCap::$filterByFields = false; \REDCap::$getDataMode = 'ok'; \REDCap::$pkAvailable = true;
        \REDCap::$repeatMapAvailable = true;
        return $m;
    }
    function viols($m) { return array_values(array_filter($m->logCalls, function ($c) { return $c[0] === 'invalid-id-saved'; })); }
    function unconf($m) { return array_values(array_filter($m->logCalls, function ($c) { return $c[0] === 'rule-unconfigurable'; })); }
    function vcount($res, $type = null) {
        $k = 0;
        foreach ($res['violations'] as $v) if ($type === null || $v['type'] === $type) $k++;
        return $k;
    }
    function render($m, $form, $rec = '1', $evt = 1, $inst = 1) {
        ob_start();
        $m->redcap_data_entry_form_top(PID, $rec, $form, $evt, null, $inst);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return json_decode(isset($mm[1]) ? $mm[1] : 'null', true);
    }
    function ruleOf($cfg, $f) {
        foreach ((isset($cfg['rules']) ? $cfg['rules'] : []) as $r) {
            if (in_array($f, isset($r['fields']) ? $r['fields'] : [], true)) return $r;
        }
        return null;
    }

    /* =====================================================================
     * H-01  a condition settled on the server is a SNAPSHOT, not a fact
     * ===================================================================== */
    {
        // Every operand of the assert is on another instrument, so the whole
        // comparison is decided at render time. It used to ship as a bare
        // ["const",false] with the rule's configured HARD block intact: a stale
        // false blocked a valid save and a stale true accepted an invalid one.
        $D = dict([
            'record_id' => ['fa'],
            'a_val'     => ['fa', '@UVASSERT={"assert":"[b_open]=\'1\'","message":"gate","blockSave":"hard"}'],
            'b_open'    => ['fb'],
        ]);
        $data = [1 => [1 => ['record_id' => '1', 'a_val' => 'X', 'b_open' => '9']]];
        $r = ruleOf(render(mkMod($D, $data), 'fa'), 'a_val');
        check('H-01: a fully off-page assert still folds to a constant',
            $r !== null && isset($r['assertAst']) && $r['assertAst'][0] === 'const');
        check('H-01: and it now NAMES the off-page field it was read from',
            $r !== null && isset($r['snapshotFields']) && in_array('b_open', $r['snapshotFields'], true));

        // The same for the applicability GATE. A stale "when" switches a rule on
        // or off, which is exactly as wrong as a stale verdict.
        $D2 = dict([
            'record_id' => ['fa'],
            'a_val'     => ['fa', '@UVASSERT={"assert":"[a_val]=\'OK\'","when":"[b_open]=\'1\'","message":"gated","blockSave":"hard"}'],
            'b_open'    => ['fb'],
        ]);
        $r2 = ruleOf(render(mkMod($D2, [1 => [1 => ['record_id' => '1', 'a_val' => 'X', 'b_open' => '1']]]), 'fa'), 'a_val');
        check('H-01: a cross-instrument "when" gate is reported as a snapshot too',
            $r2 !== null && isset($r2['snapshotFields']) && in_array('b_open', $r2['snapshotFields'], true));
        check('H-01: the gate\'s own assert stays LIVE (only the gate was off-page)',
            $r2 !== null && isset($r2['assertAst']) && $r2['assertAst'][0] === 'cmp');

        // CONTROL: a same-instrument rule is not a snapshot and must keep its
        // block. Marking everything advisory would be a silent regression.
        $D3 = dict([
            'record_id' => ['fa'],
            'a_val'     => ['fa', '@UVASSERT={"assert":"[a_val]=[a_two]","message":"same form","blockSave":"hard"}'],
            'a_two'     => ['fa'],
        ]);
        $r3 = ruleOf(render(mkMod($D3, [1 => [1 => ['record_id' => '1', 'a_val' => 'X', 'a_two' => 'X']]]), 'fa'), 'a_val');
        check('H-01 control: a same-instrument assert carries NO snapshotFields',
            $r3 !== null && !isset($r3['snapshotFields']));
        check('H-01 control: and keeps its hard block',
            $r3 !== null && isset($r3['blockSave']) && $r3['blockSave'] === 'hard');
    }

    /* =====================================================================
     * H-02  the scan evaluated every rule in every context
     * ===================================================================== */
    {
        // A populated field on a REPEATING form, reported blank because the scan
        // also evaluated the rule in the record's base row.
        $D = dict(['record_id' => ['fa'], 'r_val' => ['fr', '@UVREQUIRED']]);
        $data = [1 => [
            1 => ['record_id' => '1'],
            'repeat_instances' => [1 => ['fr' => [1 => ['r_val' => 'FILLED']]]],
        ]];
        $res = mkMod($D, $data, [1 => ['fr' => null]])->scanProject(PID);
        check('H-02: a populated repeating field is not reported blank from the base row',
            vcount($res) === 0);
        check('H-02: and the scan still completes', $res['status'] === 'complete');

        // The same rule with the instance genuinely blank must STILL fire —
        // otherwise the fix above is just a disabled check.
        $blank = [1 => [
            1 => ['record_id' => '1'],
            'repeat_instances' => [1 => ['fr' => [1 => ['r_val' => ''], 2 => ['r_val' => 'X']]]],
        ]];
        $res = mkMod($D, $blank, [1 => ['fr' => null]])->scanProject(PID);
        check('H-02 contrast: a genuinely blank instance IS reported', vcount($res) === 1);
        check('H-02 contrast: at the instance that is actually blank',
            count($res['violations']) === 1 && (string) $res['violations'][0]['instance'] === '1');
    }
    {
        // A field collected only in event 1, reported blank in event 2.
        $D = dict(['record_id' => ['f1'], 'e1_val' => ['f1', '@UVREQUIRED']]);
        $data = [1 => [1 => ['record_id' => '1', 'e1_val' => 'X'], 2 => ['record_id' => '1']]];
        $map = [['event_id' => 1, 'form' => 'f1'], ['event_id' => 2, 'form' => 'fb']];
        $res = mkMod($D, $data, [], $map)->scanProject(PID);
        check('H-02: a rule is not evaluated in an event its form is not mapped to',
            vcount($res) === 0);
    }
    {
        // A NON-repeating host asserting against a field on a repeating form.
        // Old behaviour: unconfigurable in the base row AND a hard violation in
        // the repeat row, for one record, from one rule.
        $D = dict([
            'record_id' => ['fa'],
            'a_val'     => ['fa', '@UVASSERT={"assert":"[a_val]=[b_rep]","message":"x","blockSave":"hard"}'],
            'b_rep'     => ['fb'],
        ]);
        $data = [1 => [
            1 => ['record_id' => '1', 'a_val' => 'X'],
            'repeat_instances' => [1 => ['fb' => [1 => ['b_rep' => 'Y']]]],
        ]];
        $res = mkMod($D, $data, [1 => ['fb' => null]])->scanProject(PID);
        check('H-02: no violation is invented in the REFERENCED form\'s repeat row',
            vcount($res) === 0);
        check('H-02: the undefined pairing is reported once, as a rule problem',
            count($res['unconfigurable']) === 1);

        // Blank on the other repeating instrument is the same undefined pairing,
        // not a resolved blank that happens to fail the assert.
        $blank = [1 => [
            1 => ['record_id' => '1', 'a_val' => 'X'],
            'repeat_instances' => [1 => ['fb' => [1 => ['b_rep' => '']]]],
        ]];
        $res = mkMod($D, $blank, [1 => ['fb' => null]])->scanProject(PID);
        check('H-02: an omitted blank cross-repeat reference invents no violation either',
            vcount($res) === 0);
    }

    /* =====================================================================
     * H-03  reverse-dependency amplification and misattribution
     * ===================================================================== */
    {
        // The rule lives on fa. fa does NOT repeat. fc repeats three times and
        // has nothing to do with the rule. Saving fb (the referenced form) used
        // to log the one violation FOUR times: once as fb, then once per fc row.
        $D = dict([
            'record_id' => ['fb'],
            'a_val'     => ['fa', '@UVASSERT={"assert":"[a_val]=[b_open]","message":"must match","blockSave":"hard"}'],
            'b_open'    => ['fb'],
            'unrelated' => ['fc'],
        ]);
        $data = [1 => [
            1 => ['record_id' => '1', 'a_val' => 'OLD', 'b_open' => 'NEW'],
            'repeat_instances' => [1 => ['fc' => [
                1 => ['unrelated' => 'p'], 2 => ['unrelated' => 'q'], 3 => ['unrelated' => 'r'],
            ]]],
        ]];
        $m = mkMod($D, $data, [1 => ['fc' => null]]);
        $m->redcap_save_record(PID, '1', 'fb', 1, null, null, null, 1);
        $v = viols($m);
        check('H-03: a base host logs its violation EXACTLY once', count($v) === 1);
        check('H-03: at the host instrument, not the saved one',
            count($v) === 1 && $v[0][1]['instrument'] === 'fa');
        check('H-03: and at instance 1, not an unrelated form\'s repeat row',
            count($v) === 1 && (string) $v[0][1]['instance'] === '1');
        check('H-03: no rule problem is invented for the unrelated repeat rows',
            count(unconf($m)) === 0);
    }
    {
        // A genuinely REPEATING host: every instance is audited, once each, and
        // the trigger form's base row produces no spurious rule problem.
        $D = dict([
            'record_id' => ['fb'],
            'a_val'     => ['fa', '@UVASSERT={"assert":"[a_val]=[b_open]","message":"must match","blockSave":"hard"}'],
            'b_open'    => ['fb'],
        ]);
        $data = [1 => [
            1 => ['record_id' => '1', 'b_open' => 'NEW'],
            'repeat_instances' => [1 => ['fa' => [
                1 => ['a_val' => 'OLD'], 2 => ['a_val' => 'NEW'], 3 => ['a_val' => 'OTHER'],
            ]]],
        ]];
        $m = mkMod($D, $data, [1 => ['fa' => null]]);
        $m->redcap_save_record(PID, '1', 'fb', 1, null, null, null, 1);
        $v = viols($m);
        $inst = array_map(function ($c) { return (string) $c[1]['instance']; }, $v);
        sort($inst);
        check('H-03: a repeating host has BOTH broken instances found', count($v) === 2);
        check('H-03: instances 1 and 3, and the matching instance 2 is left alone',
            $inst === ['1', '3']);
        check('H-03: no false rule problem for the trigger form\'s base row',
            count(unconf($m)) === 0);
    }

    /* =====================================================================
     * H-04  the unique aggregator ignored resolution and host scope
     * ===================================================================== */
    {
        // uid lives on the base form; the composite partner lives on an
        // independently repeating form, so the tuple (uid, b_rep) is undefined.
        // Substituting '' made both records key on (SAME, '') — a false duplicate.
        $D = dict([
            'record_id' => ['fa'],
            'uid'       => ['fa', '@UVUNIQUE={"with":["b_rep"]}'],
            'b_rep'     => ['fb'],
        ]);
        $data = [
            1 => [1 => ['record_id' => '1', 'uid' => 'SAME'],
                  'repeat_instances' => [1 => ['fb' => [1 => ['b_rep' => 'A']]]]],
            2 => [1 => ['record_id' => '2', 'uid' => 'SAME'],
                  'repeat_instances' => [1 => ['fb' => [1 => ['b_rep' => 'B']]]]],
        ];
        $res = mkMod($D, $data, [1 => ['fb' => null]])->scanProject(PID);
        check('H-04: an undefined composite pairing is NOT a duplicate',
            vcount($res, 'unique') === 0);
        check('H-04: it is reported as a rule problem instead',
            count($res['unconfigurable']) === 1);
    }
    {
        // A REAL duplicate on a base field, with unrelated repeat rows present.
        // Each record used to be collected once per context: 2 records x 4
        // contexts = 8 findings for one duplicate pair.
        $D = dict([
            'record_id' => ['fa'],
            'uid'       => ['fa', '@UVUNIQUE'],
            'unrelated' => ['fc'],
        ]);
        $rep = ['repeat_instances' => [1 => ['fc' => [1 => ['unrelated' => 'p'], 2 => ['unrelated' => 'q'], 3 => ['unrelated' => 'r']]]]];
        $data = [
            1 => [1 => ['record_id' => '1', 'uid' => 'DUP']] + $rep,
            2 => [1 => ['record_id' => '2', 'uid' => 'DUP']] + $rep,
        ];
        $res = mkMod($D, $data, [1 => ['fc' => null]])->scanProject(PID);
        check('H-04: a real duplicate is reported once per record, not once per row',
            vcount($res, 'unique') === 2);
    }

    /* =====================================================================
     * H-05  what a scan may certify
     * ===================================================================== */
    {
        // A settings rule survives a dictionary failure; annotation rules cannot,
        // because annotations LIVE in the dictionary. Scanning the survivor and
        // calling the project clean hides every rule that vanished.
        $row = ['rule-type' => 'required', 'fields' => ['a_val'], 'fields-csv' => '', 'when' => '',
                'assert' => '', 'message' => 'needed', 'algorithm' => 'iso7064_mod37_36',
                'source' => '', 'suggest-fix' => '', 'pattern' => '', 'strip' => '',
                'keep-chars' => '', 'id-lengths' => '', 'id-min-len' => '', 'id-max-len' => '',
                'expected-count' => '', 'block-save' => 'hard'];
        $m = mkMod(dict(['record_id' => ['fa'], 'a_val' => ['fa']]),
                   [1 => [1 => ['record_id' => '1', 'a_val' => 'X']]], [], null, [$row]);
        \REDCap::$dictThrow = true;
        $res = $m->scanProject(PID);
        check('H-05: an unreadable dictionary can never be reported as complete',
            $res['status'] !== 'complete');
        check('H-05: and the reason is stated', !empty($res['incomplete']));
    }
    {
        // REDCap returned the record, with nothing in it. Zero contexts is not
        // zero violations.
        $D = dict(['record_id' => ['fa'], 'a_val' => ['fa', '@UVREQUIRED']]);
        $res = mkMod($D, [1 => []])->scanProject(PID);
        check('H-05: a record returned with no data rows makes the scan incomplete',
            $res['status'] === 'incomplete');
        check('H-05: and it is named', count($res['incomplete']) === 1);
    }
    {
        // Rule discovery throwing used to escape scanProject entirely: the
        // operator got a PHP error page and no record of a partial scan (M-03).
        $D = dict(['record_id' => ['fa'], 'a_val' => ['fa', '@UVREQUIRED']]);
        $m = mkMod($D, [1 => [1 => ['record_id' => '1', 'a_val' => 'X']]]);
        $m->settingsThrow = true;
        $threw = false;
        try { $res = $m->scanProject(PID); } catch (\Throwable $e) { $threw = true; $res = null; }
        check('M-03: a settings-backend failure does not escape the scan', !$threw);
        check('M-03: it is reported as a failed scan', $res && $res['status'] === 'failed');
        check('M-03: with a non-sensitive reason', $res && count($res['incomplete']) === 1);
    }
    {
        // CONTROL: the ordinary path still certifies a clean project, or the
        // three checks above are satisfied by a scan that never says 'complete'.
        $D = dict(['record_id' => ['fa'], 'a_val' => ['fa', '@UVREQUIRED']]);
        $res = mkMod($D, [1 => [1 => ['record_id' => '1', 'a_val' => 'X']]])->scanProject(PID);
        check('H-05 control: a fully readable clean project IS complete',
            $res['status'] === 'complete' && !$res['violations']);
    }

    /* =====================================================================
     * H-06  an existing record whose requested fields are all still BLANK
     *
     * Found on a live REDCap 17.0.6 project (pid 151), not by a mock. A
     * same-form control pair — both fields on the page being rendered,
     * blockSave "hard" — shipped as ["const",false] with deferred:true and
     * "reading its saved value failed", because REDCap omits blank fields from
     * getData and therefore returned no node for the record at all. Entering a
     * value of 5 against a minimum of 10 produced no verdict and saved cleanly.
     * Saving once so the fields held values made the same page validate and
     * block correctly, which is what identified the trigger.
     * ===================================================================== */
    {
        $D = dict([
            'record_id' => ['fa'],
            'other'     => ['fa'],
            'a_val'     => ['fa', '@UVASSERT={"assert":"[a_val]>=[a_min]","message":"control","blockSave":"hard"}'],
            'a_min'     => ['fa'],
        ]);
        // The record EXISTS and holds data — but not in either field the rule names.
        $data = [1 => [1 => ['record_id' => '1', 'other' => 'X', 'a_val' => '', 'a_min' => '']]];

        $m = mkMod($D, $data);
        \REDCap::$filterByFields = true;              // model REDCap: blanks are omitted
        $r = ruleOf(render($m, 'fa'), 'a_val');
        check('H-06: a blank-so-far same-form rule is NOT deferred',
            $r !== null && empty($r['deferred']));
        check('H-06: and it ships LIVE refs, not a settled constant',
            $r !== null && isset($r['assertAst']) && $r['assertAst'][0] === 'cmp');
        check('H-06: both operands stay live (nothing was baked or frozen)',
            $r !== null && $r['assertAst'][2][0] === 'ref' && $r['assertAst'][3][0] === 'ref');
        check('H-06: no "reading its saved value failed" is claimed',
            $r !== null && empty($r['deferredWhy']));
        check('H-06: the configured hard block survives',
            $r !== null && $r['blockSave'] === 'hard');

        // The same, on a build where the record-id field cannot be established:
        // the read then genuinely returns nothing, and an EMPTY result still has
        // to mean "all blank", not "the read failed".
        $m2 = mkMod($D, $data);
        \REDCap::$filterByFields = true; \REDCap::$pkAvailable = false;
        $r2 = ruleOf(render($m2, 'fa'), 'a_val');
        check('H-06: with no record-id field to lean on, an EMPTY result is still blank, not failed',
            $r2 !== null && empty($r2['deferred']) && $r2['assertAst'][0] === 'cmp');

        // CONTRAST 1: a read that genuinely fails must STILL defer. Without this
        // the fix would just be H-04 reopened — a failed read judged as blank.
        $m3 = mkMod($D, $data);
        \REDCap::$filterByFields = true; \REDCap::$getDataMode = 'nonarray';
        $r3 = ruleOf(render($m3, 'fa'), 'a_val');
        check('H-06 contrast: a non-array result is still unreadable, and defers',
            $r3 !== null && !empty($r3['deferred'])
            && !empty($r3['deferredWhy']) && strpos($r3['deferredWhy'][0], 'reading its saved value failed') !== false);

        // CONTRAST 2: a result carrying OTHER records but not the one asked for is
        // anomalous — REDCap answered a question we did not ask — and must not be
        // read as "our record is blank".
        $m4 = mkMod($D, $data);
        \REDCap::$filterByFields = true; \REDCap::$getDataMode = 'otherrecords';
        $r4 = ruleOf(render($m4, 'fa'), 'a_val');
        check('H-06 contrast: a result for a DIFFERENT record is still unreadable',
            $r4 !== null && !empty($r4['deferred']));

        // The rule must actually WORK once it is live: a real violation is caught.
        $bad = [1 => [1 => ['record_id' => '1', 'a_val' => '5', 'a_min' => '10']]];
        $m5 = mkMod($D, $bad);
        \REDCap::$filterByFields = true;
        $m5->redcap_save_record(PID, '1', 'fa', 1, null, null, null, 1);
        check('H-06: and the audit still catches the violation it describes',
            count(viols($m5)) === 1 && viols($m5)[0][1]['field'] === 'a_val');

        // A blank-so-far CROSS-repeat reference must still be refused, not read as
        // a resolved blank — the record-id field keeps the repeat buckets in the
        // result, which is what resolveOne needs to see the other instrument.
        $DR = dict([
            'record_id' => ['fa'],
            'a_val'     => ['fa', '@UVASSERT={"assert":"[a_val]=[b_rep]","message":"x","blockSave":"hard"}'],
            'b_rep'     => ['fb'],
        ]);
        $rdata = [1 => [
            1 => ['record_id' => '1', 'a_val' => ''],
            'repeat_instances' => [1 => ['fb' => [1 => ['b_rep' => '']]]],
        ]];
        $m6 = mkMod($DR, $rdata, [1 => ['fb' => null]]);
        \REDCap::$filterByFields = true;
        $r6 = ruleOf(render($m6, 'fa'), 'a_val');
        check('H-06: a blank reference on another repeating instrument is still refused',
            $r6 !== null && !empty($r6['deferred'])
            && !empty($r6['deferredWhy'])
            && strpos($r6['deferredWhy'][0], 'different repeating instrument') !== false);
    }

    /* =====================================================================
     * H-07  the repeat-metadata cache answered a question it was never asked
     * ===================================================================== */
    {
        // repeatingFormsForEvent() has two sources. The first reads the whole
        // event's map and is independent of $forms. The second probes ONE FORM AT
        // A TIME, so its answer describes only the forms it was handed — and it
        // was cached under the bare (pid|event) key. In the scan, hostContextsFor()
        // asks about a single host form and runs BEFORE contextResolution() asks
        // about the whole read set, so on any build without getRepeatingFormsEvents
        // the one-form answer was served to the all-forms caller and every form it
        // had not asked about read as NON-repeating.
        //
        // The fixture is the H-02 cross-repeat assert. fb repeats but has NO
        // instances yet — the one case the bucket-presence fallback is blind to,
        // and therefore the case where the poisoned answer does real harm. The
        // verdict must not depend on WHICH metadata source answered.
        $D = dict([
            'record_id' => ['fa'],
            'a_val'     => ['fa', '@UVASSERT={"assert":"[a_val]=[b_rep]","message":"x","blockSave":"hard"}'],
            'b_rep'     => ['fb'],
        ]);
        $data = [1 => [1 => ['record_id' => '1', 'a_val' => 'X']]];

        // Control: the whole-event map IS available. This is the H-02 verdict.
        $res = mkMod($D, $data, [1 => ['fb' => 1]])->scanProject(PID);
        check('H-07 control: with the event map, an instance-less repeating reference is a rule problem',
            vcount($res) === 0 && count($res['unconfigurable']) === 1);

        // The same project on a build that exposes only isRepeatingForm. Before
        // the fix this reported a HARD violation against a value it never read,
        // and dropped the rule problem that should have been raised instead.
        $m = mkMod($D, $data, [1 => ['fb' => 1]]);
        \REDCap::$repeatMapAvailable = false;
        $res = $m->scanProject(PID);
        check('H-07: the per-form probe invents no violation against an unread reference',
            vcount($res) === 0);
        check('H-07: and still reports the undefined pairing as a rule problem',
            count($res['unconfigurable']) === 1);
        check('H-07: and the scan still completes', $res['status'] === 'complete');

        // The same fixture WITH an instance present, both ways round: the bucket
        // fallback covered this case even before the fix, so it is the control
        // that proves the two sources agree rather than that one was disabled.
        $inst = [1 => [
            1 => ['record_id' => '1', 'a_val' => 'X'],
            'repeat_instances' => [1 => ['fb' => [1 => ['b_rep' => 'Y']]]],
        ]];
        $m = mkMod($D, $inst, [1 => ['fb' => 1]]);
        \REDCap::$repeatMapAvailable = false;
        $res = $m->scanProject(PID);
        check('H-07 control: a populated cross-repeat reference is unchanged either way',
            vcount($res) === 0 && count($res['unconfigurable']) === 1);

        // Direct probe of the mechanism, in the scan's real call order: one host
        // form first, then the whole read set. Both forms repeat, so the second
        // answer must name both — serving the first answer hides 'fb'.
        $m2 = mkMod($D, $data, [1 => ['fa' => 1, 'fb' => 1]]);
        \REDCap::$repeatMapAvailable = false;
        $probe = new \ReflectionMethod($m2, 'repeatingFormsForEvent');
        $probe->setAccessible(true);
        $one = $probe->invoke($m2, PID, 1, ['fa']);          // hostContextsFor()
        $all = $probe->invoke($m2, PID, 1, ['fa', 'fb']);    // contextResolution()
        check('H-07: a one-form answer does not become the answer for every form',
            is_array($one) && isset($one['fa']) && !isset($one['fb'])
            && is_array($all) && isset($all['fa']) && isset($all['fb']));

        // CONTRAST: the fix must not turn "cannot tell" into "nothing repeats".
        // With NEITHER source available the answer is still null, so resolveOne
        // keeps falling back to bucket presence rather than reading a resolved
        // blank — the H-02 posture the null return exists to protect.
        $m3 = mkMod($D, $data, null);
        \REDCap::$repeatMapAvailable = false;
        $probe3 = new \ReflectionMethod($m3, 'repeatingFormsForEvent');
        $probe3->setAccessible(true);
        check('H-07 contrast: with no metadata source at all the answer is still null',
            $probe3->invoke($m3, PID, 1, ['fa', 'fb']) === null);

        // CONTRAST: a form that genuinely does not repeat is still reported as
        // not repeating — otherwise the fix is just a disabled check.
        $m4 = mkMod($D, $data, [1 => ['fb' => 1]]);
        \REDCap::$repeatMapAvailable = false;
        $probe4 = new \ReflectionMethod($m4, 'repeatingFormsForEvent');
        $probe4->setAccessible(true);
        $ans = $probe4->invoke($m4, PID, 1, ['fa', 'fb']);
        check('H-07 contrast: a non-repeating form is still absent from the set',
            is_array($ans) && !isset($ans['fa']) && isset($ans['fb']));
    }

    echo "hosting_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
