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
        /**
         * H-08. Microseconds to stall on every read, so a test can walk the scan
         * into its own execution deadline without needing a large fixture.
         */
        public static $sleepPerRead = 0;
        /** H-09. Every getData call's params, so a test can prove WHAT was asked for. */
        public static $getDataCalls = [];

        public static function getData($p) {
            self::$getDataCalls[] = ['fields' => isset($p['fields']) ? array_values((array) $p['fields']) : null,
                                     'records' => isset($p['records']) ? count($p['records']) : 0];
            if (self::$sleepPerRead > 0) usleep(self::$sleepPerRead);
            if (self::$getDataMode === 'nonarray') return false;
            if (self::$getDataMode === 'otherrecords') return ['999' => [1 => ['record_id' => '999']]];
            // A chunk read names the records it wants and gets ONLY those. Until
            // 1.6.3 this mock returned the whole project regardless, so a caller
            // that asked for the wrong slice still saw every record and behaved
            // identically — which made scanProject's chunking (the array_chunk at
            // :2182 and the chunk read at :2188) unfalsifiable. The record list
            // itself is read WITHOUT 'records', so the pre-read is unaffected.
            $src = self::$data;
            if (!empty($p['records'])) {
                $only = [];
                foreach ($p['records'] as $r) {
                    if (array_key_exists($r, self::$data)) $only[$r] = self::$data[$r];
                }
                $src = $only;
            }
            if (!self::$filterByFields || empty($p['fields'])) return $src;
            $want = array_flip($p['fields']);
            $strip = function (array $row) use ($want) {
                $r = [];
                foreach ($row as $f => $v) {
                    if (isset($want[$f]) && $v !== '' && $v !== null) $r[$f] = $v;
                }
                return $r;
            };
            $out = [];
            foreach ($src as $rec => $node) {
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
        REDCap::$sleepPerRead = 0; REDCap::$getDataCalls = [];
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

    /* =========================================================================
     * H-08  the scan stops on its own terms, and says so
     *
     * Measured on a live 39-record project with 329 rules: ~20s warm. An order
     * of magnitude more records does not fit in a default execution limit, so
     * running out of time is the EXPECTED exit on real data. Both limits kill
     * PHP with an uncatchable fatal: the process stops before scanProject can
     * return, the page renders nothing, and nothing anywhere records that the
     * project was not examined. That is the one failure mode the whole status
     * contract cannot express (M-03), so the scan has to stop before it hits
     * either wall and report what it did not reach.
     * ===================================================================== */
    {
        $D = dict(['record_id' => ['fa'], 'a_val' => ['fa', '@UVREQUIRED']]);
        $mkData = function ($n) {
            $d = [];
            for ($i = 1; $i <= $n; $i++) $d[$i] = [1 => ['record_id' => (string) $i, 'a_val' => '']];
            return $d;
        };

        // -- the halt decision --------------------------------------------------
        // Driven directly. Making PHP enforce a real execution limit inside a
        // test kills the test process, so the decision is a unit and the wiring
        // is proved separately by the memory path below.
        // Guarded so a tree without the method FAILS these checks rather than
        // dying on a ReflectionException — a fatal here would take every later
        // section with it and hide whether they pass.
        $UVC = '\INSPIRE\UniversalValidator\UniversalValidator';
        $halt = function ($deadline, $memCap, $now, $usage) use ($UVC) {
            if (!method_exists($UVC, 'scanHalt')) return '__no-such-method__';
            $r = new \ReflectionMethod($UVC, 'scanHalt'); $r->setAccessible(true);
            return $r->invoke(null, $deadline, $memCap, $now, $usage);
        };
        check('H-08: inside both bounds the scan continues',
            $halt(100.0, 1000, 50.0, 500) === null);
        check('H-08: past the deadline it stops for time',
            $halt(100.0, 1000, 100.0, 500) === 'time');
        check('H-08: at or over the memory cap it stops for memory',
            $halt(100.0, 1000, 50.0, 1000) === 'memory');
        // No limit known must never halt: a guard that fires on an unreadable
        // limit would stop healthy scans and report them as incomplete.
        check('H-08: with no limits known it never halts',
            $halt(null, null, 1e12, PHP_INT_MAX) === null);
        check('H-08: an unknown time limit does not mask a real memory one',
            $halt(null, 1000, 1e12, 1000) === 'memory');
        check('H-08: an unknown memory limit does not mask a real deadline',
            $halt(100.0, null, 100.0, PHP_INT_MAX) === 'time');

        // -- the memory limit, end to end ---------------------------------------
        // Hold a real allocation, then set a limit whose 70% sits below it. The
        // limit stays comfortably above ACTUAL usage, so nothing fatals; only
        // the guard's threshold is crossed.
        $ballast = str_repeat('x', 20 * 1024 * 1024);
        $oldMem = ini_get('memory_limit');
        ini_set('memory_limit', '30M');              // cap = 21M, usage > 21M
        $res3 = mkMod($D, $mkData(4))->scanProject(PID, null, 1);
        ini_set('memory_limit', (string) $oldMem);
        unset($ballast);

        check('H-08: a scan near the memory limit reports incomplete, never complete',
            $res3['status'] === 'incomplete');
        // X1. The headline count used to be the MANIFEST, set before the loop and
        // never revised: a scan halted at the first chunk boundary reported
        // "Scanned 400 record(s)" in bold while the truth sat in a bullet inside
        // a warning box, and the export's metadata line said 400 too.
        check('X1: a halted scan reports what it EXAMINED as the record count',
            $res3['stats']['records'] < 4);
        check('X1: while the manifest size stays available under its own name',
            isset($res3['stats']['manifest']) && $res3['stats']['manifest'] === 4);
        check('X1: so the two can never be read as the same number',
            $res3['stats']['records'] !== $res3['stats']['manifest']);
        check('H-08: and it names the memory limit as the reason',
            (bool) array_filter($res3['incomplete'], function ($s) {
                return strpos($s, 'memory limit') !== false;
            }));
        check('H-08: and it counts the records it did not check',
            (bool) array_filter($res3['incomplete'], function ($s) {
                return strpos($s, 'record(s) were not checked') !== false;
            }));
        // A short run under-reports DUPLICATES specifically: that is a wrong
        // negative rather than a missing row, and it has to be said out loud.
        check('H-08: and it warns that duplicates are under-reported',
            (bool) array_filter($res3['incomplete'], function ($s) {
                return strpos($s, 'duplicate values are under-reported') !== false;
            }));

        // CONTRAST: the same scan with headroom completes.
        $res4 = mkMod($D, $mkData(4))->scanProject(PID, null, 1);
        check('H-08 contrast: with memory headroom the same scan completes',
            $res4['status'] === 'complete');

        // -- the limit parser ---------------------------------------------------
        // Driven directly, NOT through ini_set: PHP refuses to set memory_limit
        // below current usage, so a test that went through the ini would quietly
        // assert against whatever the limit already was and pass on anything.
        $parse = function ($v) use ($UVC) {
            if (!method_exists($UVC, 'parseByteSize')) return -1;
            $r = new \ReflectionMethod($UVC, 'parseByteSize'); $r->setAccessible(true);
            return $r->invoke(null, $v);
        };
        check('H-08: memory_limit -1 means no cap', $parse('-1') === 0);
        check('H-08: shorthand suffixes are binary, not decimal',
            $parse('128M') === 134217728 && $parse('1G') === 1073741824 && $parse('512K') === 524288);
        check('H-08: a bare number is already bytes', $parse('67108864') === 67108864);
        check('H-08: a lower-case suffix parses the same', $parse('128m') === 134217728);
        // An unreadable limit must impose NO cap. A guard that fires on a
        // misparse would stop healthy scans and report them as incomplete —
        // failing safe here means declining to guess.
        check('H-08: an unparseable limit imposes no cap, rather than a wrong one',
            $parse('garbage') === 0);
    }

    /* =========================================================================
     * H-09  the record list is read as a list, never as the whole project
     *
     * When getRecordIdField() was unavailable the pre-read asked for every rule
     * field for every record in ONE unchunked call — the whole project in memory
     * before a single record had been examined, which defeats the chunk loop
     * entirely and dies as an OOM rather than as a reported result.
     * ===================================================================== */
    {
        // THREE rule fields, so "one field was requested" is falsifiable: with a
        // single-field read set the old whole-read-set fallback also asked for
        // exactly one field and this section passed for the wrong reason.
        $D = dict(['record_id' => ['fa'], 'a_val' => ['fa', '@UVREQUIRED'],
                   'b_val' => ['fb', '@UVREQUIRED'], 'c_val' => ['fb', '@UVREQUIRED']]);
        $data = [1 => [1 => ['record_id' => '1', 'a_val' => '', 'b_val' => '', 'c_val' => '']]];

        // Set the scenario AFTER mkMod, never before: mkMod resets pkAvailable
        // to true, so setting it first is silently undone and the scan runs the
        // ordinary path. Same shape as the render() reset that made an earlier
        // check unfalsifiable — the helper always wins.
        $m = mkMod($D, $data);
        \REDCap::$pkAvailable = false;
        \REDCap::$getDataCalls = [];
        $res = $m->scanProject(PID);
        \REDCap::$pkAvailable = true;

        check('H-09: the scan still runs when getRecordIdField() is unavailable',
            $res['status'] === 'complete');
        $first = isset(\REDCap::$getDataCalls[0]['fields']) ? \REDCap::$getDataCalls[0]['fields'] : null;
        check('H-09: and the record list is read with ONE field, not the whole read set',
            is_array($first) && count($first) === 1);
        check('H-09: which is the dictionary\'s first field — REDCap\'s record id',
            is_array($first) && $first[0] === 'record_id');
        check('H-09: and the scan still finds the violations it should',
            count($res['violations']) === 3);
    }

    /* =========================================================================
     * SINK  the extraction changed nothing
     *
     * 1.7.0 split scanProject() into scanPlan() + scanRecord() + a FindingSink,
     * so violations can be consumed as they are found rather than accumulated.
     * scanProject() keeps its signature and return shape, which means every
     * check above still passes — and that is the trap: passing proves the ARRAY
     * path is unchanged and says nothing about the streaming one, which is the
     * path a large project will actually use.
     *
     * So every scenario runs through BOTH and the results are compared. This is
     * the same differential shape the repo already uses to stop the PHP and JS
     * condition engines drifting apart (tests/when_fuzz_php.php).
     * ===================================================================== */
    {
        $UVC = '\INSPIRE\UniversalValidator\UniversalValidator';
        $hasSink = interface_exists('\INSPIRE\UniversalValidator\FindingSink');
        check('sink: the FindingSink seam exists', $hasSink);
        // Guarded: constructing the sinks on a tree without them is a fatal,
        // which would take every check after this point down with it.
        if (!$hasSink) {
            check('sink: the differential cannot run without the seam', false);
        } else {

        // Four shapes, chosen because they exercise different emit paths: the
        // ordinary per-record one, the whole-project unique tail, a record that
        // cannot be examined, and a project with nothing live to scan.
        $D  = dict(['record_id' => ['fa'], 'a_val' => ['fa', '@UVREQUIRED'],
                    'b_val' => ['fb', '@UVUNIQUE']]);
        $scenarios = [
            'plain violations' => [$D, [
                1 => [1 => ['record_id' => '1', 'a_val' => '', 'b_val' => 'X']],
                2 => [1 => ['record_id' => '2', 'a_val' => '', 'b_val' => 'Y']],
            ]],
            'unique duplicates across records' => [$D, [
                1 => [1 => ['record_id' => '1', 'a_val' => 'ok', 'b_val' => 'SAME']],
                2 => [1 => ['record_id' => '2', 'a_val' => 'ok', 'b_val' => 'SAME']],
            ]],
            'a record with no rows' => [$D, [
                1 => [1 => ['record_id' => '1', 'a_val' => '', 'b_val' => 'X']],
                2 => [],
            ]],
            'nothing live to scan' => [dict(['record_id' => ['fa'], 'plain' => ['fa']]), [
                1 => [1 => ['record_id' => '1', 'plain' => 'x']],
            ]],
        ];

        foreach ($scenarios as $name => $sc) {
            list($dd, $data) = $sc;

            $viaArray = mkMod($dd, $data)->scanProject(PID);

            $streamed = [];
            $cb = new \INSPIRE\UniversalValidator\CallbackFindingSink(
                function (array $v) use (&$streamed) { $streamed[] = $v; });
            $viaStream = mkMod($dd, $data)->scanProject(PID, null, 200, $cb);

            $counting = new \INSPIRE\UniversalValidator\CountingFindingSink();
            $viaCount = mkMod($dd, $data)->scanProject(PID, null, 200, $counting);

            check("sink [$name]: the streamed findings are identical, in the same order",
                $streamed === $viaArray['violations']);
            check("sink [$name]: status agrees",
                $viaStream['status'] === $viaArray['status']);
            check("sink [$name]: the incomplete notes agree",
                $viaStream['incomplete'] === $viaArray['incomplete']);
            check("sink [$name]: the rule problems agree",
                $viaStream['unconfigurable'] === $viaArray['unconfigurable']);
            check("sink [$name]: records, contexts and rules agree",
                $viaStream['stats']['records'] === $viaArray['stats']['records']
                && $viaStream['stats']['contexts'] === $viaArray['stats']['contexts']
                && $viaStream['stats']['rules'] === $viaArray['stats']['rules']);
            // The point of streaming: the rows are NOT kept...
            check("sink [$name]: a streaming scan keeps no rows in the result",
                $viaStream['violations'] === []);
            // ...but the COUNT survives, so "no violations" is never inferred
            // from an array that was simply never filled (M-02).
            check("sink [$name]: and the count survives anyway",
                $viaStream['stats']['violations'] === count($viaArray['violations']));
            check("sink [$name]: a counting sink agrees with both",
                $viaCount['stats']['violations'] === count($viaArray['violations']));
        }

        // A scenario that actually produced findings, so the comparison above is
        // not four rounds of comparing nothing to nothing.
        $probe = mkMod($D, $scenarios['plain violations'][1])->scanProject(PID);
        check('sink: the differential ran against real findings',
            count($probe['violations']) >= 2);
        $dup = mkMod($D, $scenarios['unique duplicates across records'][1])->scanProject(PID);
        check('sink: including duplicate findings from the whole-project tail',
            (bool) array_filter($dup['violations'], function ($v) { return $v['type'] === 'unique'; }));

        // The default is still the array sink: an existing caller that passes no
        // sink gets exactly what it always got.
        check('sink: the default keeps collecting, so old callers are unchanged',
            is_array($probe['violations']) && $probe['violations'] !== []);
        check('sink: and stats gained a violation count for everyone',
            $probe['stats']['violations'] === count($probe['violations']));
        }
    }

    /* =========================================================================
     * VALUE  the report shows the offending value, under an explicit policy
     *
     * Until 1.8.0 the scan deliberately withheld every value. That was honest
     * and not actionable: a data manager could not tell a typo from a systematic
     * import bug without opening each record. It is now a project setting.
     *
     * The rule that matters is the FAIL-CLOSED one. isIdentifier() answers "is
     * this field known to be an identifier", so an unreadable dictionary means
     * "nothing is" — correct for refusing to enable a survey feature, and exactly
     * backwards here, where it would mean "redact nothing". mustRedact() inverts
     * that: a dictionary we cannot read cannot clear a field, so it withholds
     * everything.
     * ===================================================================== */
    {
        $UVC = '\INSPIRE\UniversalValidator\UniversalValidator';
        $red = function ($ids, $field, $mode) use ($UVC) {
            if (!method_exists($UVC, 'mustRedact')) return '__no-such-method__';
            $r = new \ReflectionMethod($UVC, 'mustRedact'); $r->setAccessible(true);
            return $r->invoke(null, $ids, $field, $mode);
        };
        check('value: locations-only withholds everything', $red(['a' => true], 'b', 'locations') === true);
        check('value: raw withholds nothing, even an identifier',
            $red(['a' => true], 'a', 'raw') === false);
        check('value: identifiers mode withholds a flagged field',
            $red(['a' => true], 'a', 'identifier-redacted') === true);
        check('value: identifiers mode shows an unflagged field',
            $red(['a' => true], 'b', 'identifier-redacted') === false);
        // The one that would leak. A null identifier set is what
        // projectIdentifierFields() returns when the dictionary read FAILED.
        check('value: an unreadable dictionary withholds EVERY value, not none',
            $red(null, 'anything', 'identifier-redacted') === true);
        check('value: and raw mode is still explicit about it', $red(null, 'x', 'raw') === false);

        // End to end, through a real scan.
        $D = dict(['record_id' => ['fa'], 'secret' => ['fa', '@UVREQUIRED'],
                   'want' => ['fa'],
                   'code' => ['fa', '@UVASSERT={"assert":"[code]=[want]"}']]);
        $data = [1 => [1 => ['record_id' => '1', 'secret' => '', 'code' => 'nope', 'want' => 'yes']]];
        $valOf = function ($res, $field) {
            foreach ($res['violations'] as $v) if ($v['field'] === $field) return $v['value'];
            return '__absent__';
        };

        $m = mkMod($D, $data);
        $m->projectSettings = ['scan-value-storage' => 'raw'];
        $res = $m->scanProject(PID);
        check('value: raw mode shows the bad value', $valOf($res, 'code') === 'nope');
        check('value: and a required-blank shows nothing, because there is nothing',
            $valOf($res, 'secret') === null);

        $m = mkMod($D, $data);
        $m->projectSettings = ['scan-value-storage' => 'locations'];
        $res = $m->scanProject(PID);
        check('value: locations-only shows no value at all', $valOf($res, 'code') === null);

        // An External Modules dropdown stores NOTHING until the dialog is saved,
        // so an unset setting is what EVERY un-reconfigured project looks like,
        // and what every project looks like on upgrade. Landing on 'raw' there
        // would switch them all to full disclosure with nobody deciding.
        $m = mkMod($D, $data);
        $m->projectSettings = [];
        $res = $m->scanProject(PID);
        check('value: an unset setting discloses NOTHING, not everything',
            $valOf($res, 'code') === null);
        $m = mkMod($D, $data);
        $m->projectSettings = ['scan-value-storage' => 'wat'];
        check('value: an unrecognised setting also discloses nothing',
            $valOf($m->scanProject(PID), 'code') === null);

        // The reader's own export rights cap whatever the project chose.
        $m = mkMod($D, $data);
        $m->projectSettings = ['scan-value-storage' => 'raw'];
        check('value: a reader without full export rights never sees a raw value',
            $valOf($m->scanProject(PID, null, 200, null, ['valueCeiling' => 'locations']), 'code') === null);
        $m = mkMod($D, $data);
        $m->projectSettings = ['scan-value-storage' => 'raw'];
        check('value: and a de-identified reader is capped at redaction, not raw',
            $valOf($m->scanProject(PID, null, 200, null,
                ['valueCeiling' => 'identifier-redacted']), 'code') === 'nope');
        // A ceiling can only lower, never raise.
        $m = mkMod($D, $data);
        $m->projectSettings = ['scan-value-storage' => 'locations'];
        check('value: a permissive reader cannot raise a restrictive project setting',
            $valOf($m->scanProject(PID, null, 200, null, ['valueCeiling' => 'raw']), 'code') === null);

        // Truncation and non-text bytes are MARKED, never silently dropped or
        // pasted into a CSV where they would corrupt the file.
        $rv = function ($v, $plan) use ($UVC) {
            $r = new \ReflectionMethod($UVC, 'reportValue'); $r->setAccessible(true);
            return $r->invoke(null, $v, $plan);
        };
        $plan = ['valueMode' => 'raw', 'identifiers' => []];
        $long = str_repeat('x', 200);
        $out = $rv(['field' => 'f', 'value' => $long], $plan);
        check('value: an over-long value is truncated and says so',
            strpos($out, '(truncated)') !== false && mb_strlen($out, 'UTF-8') < 200);
        check('value: invalid UTF-8 is reported as bytes, not pasted into the report',
            strpos($rv(['field' => 'f', 'value' => "ab\xFF\xFEcd"], $plan), 'not valid text') !== false);
        check('value: a checkbox array is flattened rather than stringified as "Array"',
            $rv(['field' => 'f', 'value' => ['1', '2']], $plan) === '1, 2');
        check('value: a finding with no value key yields null',
            $rv(['field' => 'f'], $plan) === null);
    }


    /* =========================================================================
     * H-10  zero records IN SCOPE is not a clean project
     *
     * S-03 by a different route. 1.6.2 refused when the DAG NAME could not be
     * resolved; it did not refuse when the name resolved and matched nothing.
     * Three causes are indistinguishable from inside the scan - the group really
     * has no records, exportDataAccessGroups was not honoured, or the DAG name
     * and the exported group label disagree - and all three used to render the
     * green tick over "Scanned 0 record(s)".
     * ===================================================================== */
    {
        $D = dict(['record_id' => ['fa'], 'a_val' => ['fa', '@UVREQUIRED']]);
        $data = [1 => [1 => ['record_id' => '1', 'a_val' => '', 'redcap_data_access_group' => 'north']]];

        $res = mkMod($D, $data)->scanProject(PID, 'south');
        check('H-10: a DAG that matches no record is NOT reported complete',
            $res['status'] === 'incomplete');
        check('H-10: and it says the group had nothing in scope',
            (bool) array_filter($res['incomplete'], function ($s) {
                return strpos($s, 'no record was in scope') !== false;
            }));
        check('H-10: and says so is not evidence the data is clean',
            (bool) array_filter($res['incomplete'], function ($s) {
                return strpos($s, 'not evidence') !== false;
            }));

        $res2 = mkMod($D, [])->scanProject(PID);
        check('H-10: an empty project is reported, not certified',
            $res2['status'] === 'incomplete');

        // CONTRAST: a DAG that DOES match still scans and can still be clean.
        $clean = [1 => [1 => ['record_id' => '1', 'a_val' => 'ok', 'redcap_data_access_group' => 'north']]];
        $res3 = mkMod($D, $clean)->scanProject(PID, 'north');
        check('H-10 contrast: a matching DAG with clean data still completes',
            $res3['status'] === 'complete' && count($res3['violations']) === 0);
    }


    /* =========================================================================
     * X  the adversarial battle-test findings
     * (reports/scan-wargame-2026-08-17.md)
     * ===================================================================== */
    {
        $UVC = '\INSPIRE\UniversalValidator\UniversalValidator';
        $D = dict(['record_id' => ['fa'], 'a_val' => ['fa', '@UVREQUIRED']]);
        $mk = function ($n) {
            $d = [];
            for ($i = 1; $i <= $n; $i++) $d[$i] = [1 => ['record_id' => (string) $i, 'a_val' => '']];
            return $d;
        };

        // X1's halt case lives in the H-08 memory block above, where a halt
        // provably fires; by this point in the file memory_get_usage is already
        // past 30M, so ini_set silently refuses and nothing would trip.
        $ok = mkMod($D, $mk(6))->scanProject(PID, null, 1);
        check('X1: a complete scan reports every record it examined',
            $ok['stats']['records'] === 6);
        check('X1: and carries the manifest size under its OWN name, never sharing a label',
            isset($ok['stats']['manifest']) && $ok['stats']['manifest'] === 6);

        // X2. 'none' is the mode for sites where the RECORD ID is identifying.
        // Findings were hashed; the incomplete notes that name the same records
        // were not, and they are rendered on the page and written to the CSV.
        $m = mkMod($D, [1 => [1 => ['record_id' => '1', 'a_val' => '']], 2 => []]);
        $m->projectSettings = ['log-values' => 'none'];
        $res2 = $m->scanProject(PID);
        $notes = implode(' | ', $res2['incomplete']);
        check('X2: a note about an unreadable record does not name it in the clear',
            strpos($notes, 'record 2 ') === false);
        check('X2: and the note is still produced, not dropped',
            (bool) array_filter($res2['incomplete'], function ($x) {
                return strpos($x, 'no data rows') !== false || strpos($x, 'not returned') !== false;
            }));

        // X5. hashedIdentifier RETURNS null when no key can be had - it catches
        // its own failure - so the documented '[record id withheld]' fallback
        // could never fire and every Record cell rendered EMPTY.
        $rid = new \ReflectionMethod($UVC, 'reportRecordId');
        $rid->setAccessible(true);
        $m2 = new \INSPIRE\UniversalValidator\UniversalValidator();
        $out = $rid->invoke($m2, ['pid' => PID, 'hashRecordIds' => true], 'PATIENT-8');
        check('X5: an unavailable hash key never yields a blank Record cell',
            is_string($out) && $out !== '');
        check('X5: and never falls back to the raw id',
            strpos((string) $out, 'PATIENT-8') === false);

        // X3. A project-scope unique rule cannot be judged from one DAG.
        $DU = dict(['record_id' => ['fa'], 'sid' => ['fa', '@UVUNIQUE']]);
        $du = [
            1 => [1 => ['record_id' => '1', 'sid' => 'SAME', 'redcap_data_access_group' => 'north']],
            2 => [1 => ['record_id' => '2', 'sid' => 'SAME', 'redcap_data_access_group' => 'south']],
        ];
        $whole = mkMod($DU, $du)->scanProject(PID);
        check('X3: project-wide, the cross-group duplicate IS found',
            count(array_filter($whole['violations'], function ($v) { return $v['type'] === 'unique'; })) === 2);
        $scoped = mkMod($DU, $du)->scanProject(PID, 'north');
        check('X3: DAG-scoped, the rule is reported as unevaluable rather than silently passing',
            (bool) array_filter($scoped['unconfigurable'], function ($u) {
                return stripos($u['why'], 'whole project') !== false;
            }));
        check('X3: so the DAG scan can no longer read as clean',
            !empty($scoped['unconfigurable']));

        // X4. The author's label and message belong to EVERY rule kind. They
        // were read in the constraint|required|unique branch only, so the
        // check-character and pooled kinds the module is named after lost both.
        $ap = new \ReflectionMethod($UVC, 'applyAuthoring');
        $ap->setAccessible(true);
        $row = ['rule-note' => 'Specimen IDs', 'message' => 'Must be a valid specimen ID'];
        $r = $ap->invoke(null, ['type' => 'single', 'fields' => ['sid']], $row);
        check('X4: a single (check-character) rule keeps its author label',
            isset($r['note']) && $r['note'] === 'Specimen IDs');
        check('X4: and its author message, so MessageCatalog tier 1 is reachable',
            isset($r['message']) && $r['message'] === 'Must be a valid specimen ID');
        $r2 = $ap->invoke(null, ['type' => 'pooled', 'fields' => ['ids']], $row);
        check('X4: a pooled rule keeps them too',
            isset($r2['note']) && isset($r2['message']));
        $r3 = $ap->invoke(null, ['type' => 'required', 'fields' => ['x']], []);
        check('X4: and an unset label leaves the key unset rather than blank',
            !isset($r3['note']) && !isset($r3['message']));
    }

    echo "hosting_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
