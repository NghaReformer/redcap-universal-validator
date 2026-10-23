<?php
/**
 * growth_return_module_php.php — what an @UVRANGE rule returns, written when a
 * record is saved (2.7.0).
 *
 * growth_php.php pins the values (the "outputs" fixture section) and
 * growth_return_dom_js.cjs the page; this file pins the save:
 *   - the values a rule with "return" works out are written once, in one
 *     save, only where a field differs, and the write itself is not audited,
 *   - a field already holding the same number (another spelling) is left,
 *     and a second save of the same record writes nothing,
 *   - a blank input empties the fields (the rule owns them), a rule problem
 *     leaves them and is logged once,
 *   - a rule that reads a returned field is judged after the fill,
 *   - the project setting "range-return-no-save", a failed write, the scan,
 *   - branches (the active one fills, the others are emptied), repeating
 *     forms, a form saved for an input on another form, and a rule with an
 *     input in another event (the extended path),
 *   - the grammar and the dictionary hook: what is refused, defaults, and
 *     two rules writing one field.
 *
 * Run:  php tests/growth_return_module_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        /** The legacy one-request scan (tests/support/scan_facade.php, QUA-3). */
        public function scanProject(...$a) { return \INSPIRE\UniversalValidator\scan_project($this, ...$a); }
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
        public static $repeats = [];
        public static $eventMappings = null;
        /** every saveData call: ['data' => ..., 'overwrite' => ...] */
        public static $saves = [];
        /** non-empty: saveData answers with these errors and writes nothing */
        public static $saveErrors = [];
        /** a callable given each save's data: errors to answer with for that save, [] to write it */
        public static $saveErrorsFor = null;
        /** called after a save is applied, as the hook REDCap fires for it */
        public static $onSave = null;
        public static $getDataCalls = 0;
        public static function getRepeatingFormsEvents($pid) { return self::$repeats; }
        public static function getInstrumentEventMappings($pid = null) { return self::$eventMappings; }
        public static function getData($p) {
            self::$getDataCalls++;
            $data = self::$data;
            if (isset($p['records'])) $data = array_intersect_key($data, array_fill_keys($p['records'], true));
            if (!empty($p['events'])) {
                $keep = array_fill_keys($p['events'], true);
                foreach ($data as &$record) {
                    foreach ($record as $event => $row) {
                        if ($event !== 'repeat_instances' && !isset($keep[$event])) unset($record[$event]);
                    }
                    if (isset($record['repeat_instances'])) $record['repeat_instances'] = array_intersect_key($record['repeat_instances'], $keep);
                }
                unset($record);
            }
            if (!empty($p['fields'])) {
                $keep = array_fill_keys($p['fields'], true);
                foreach ($data as &$record) {
                    foreach ($record as $event => &$row) {
                        if ($event === 'repeat_instances') {
                            foreach ($row as &$forms) foreach ($forms as &$instances) foreach ($instances as &$values) {
                                $values = array_intersect_key($values, $keep);
                            }
                            unset($forms, $instances, $values);
                        } else {
                            $row = array_intersect_key($row, $keep);
                        }
                    }
                    unset($row);
                }
                unset($record);
            }
            return $data;
        }
        public static function saveData($pid, $format = 'array', $data = [], $overwrite = 'normal') {
            self::$saves[] = ['pid' => $pid, 'format' => $format, 'data' => $data, 'overwrite' => $overwrite];
            if (self::$saveErrors) return ['errors' => self::$saveErrors, 'warnings' => [], 'ids' => [], 'item_count' => 0];
            if (self::$saveErrorsFor) {
                $why = call_user_func(self::$saveErrorsFor, $data);
                if ($why) return ['errors' => $why, 'warnings' => [], 'ids' => [], 'item_count' => 0];
            }
            foreach ($data as $rec => $nodes) {
                foreach ($nodes as $evt => $row) {
                    if ($evt === 'repeat_instances') {
                        foreach ($row as $e => $forms) foreach ($forms as $form => $insts) foreach ($insts as $i => $vals) {
                            foreach ($vals as $f => $v) {
                                if ($v === '') unset(self::$data[$rec]['repeat_instances'][$e][$form][$i][$f]);
                                else self::$data[$rec]['repeat_instances'][$e][$form][$i][$f] = $v;
                            }
                        }
                    } else {
                        foreach ($row as $f => $v) {
                            if ($v === '') unset(self::$data[$rec][$evt][$f]);
                            else self::$data[$rec][$evt][$f] = $v;
                        }
                    }
                }
            }
            if (self::$onSave) { $cb = self::$onSave; $cb($pid, $data); }
            return ['errors' => [], 'warnings' => [], 'ids' => array_keys($data), 'item_count' => 1];
        }
        public static function getDataDictionary($pid, $f = 'array') {
            if (!$pid) throw new \RuntimeException('needs pid');
            return self::$dictionary;
        }
        public static function getUserRights($pid = null, $user = null) { return self::$rights; }
        public static function getGroupNames($u = false, $g = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
    }
    class Project {
        public static $codes = '';
        public $project;
        public function __construct($pid) { $this->project = ['missing_data_codes' => self::$codes]; }
    }

    require_once __DIR__ . '/../UniversalValidator.php';
    require_once __DIR__ . '/support/scan_facade.php';

    use INSPIRE\UniversalValidator\AnnotationRules;
    use INSPIRE\UniversalValidator\GrowthOutputs;
    use INSPIRE\UniversalValidator\GrowthReference;

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    const PID = 149;
    const EVT = 351;
    function f($form, $ann = '', $validation = '', $type = 'text', $choices = '') {
        return ['field_type' => $type, 'form_name' => $form, 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => $validation,
                'select_choices_or_calculations' => $choices];
    }
    /** @UVRANGE on a weight: who-wfa by sex and dates, soft -2..2 and hard -5..5, plus $extra keys. */
    function tag($extra = '', $sex = '[sex]', $dob = '[dob]') {
        return '@UVRANGE={"reference":"who-wfa","sex":"' . $sex . '","male":"1","female":"2","age":{"dob":"' . $dob
            . '","at":"[visit_date]"},"soft":[-2,2],"hard":[-5,5]' . ($extra === '' ? '' : ',' . $extra) . '}';
    }
    const RET_ALL = '"return":{"z":"[wfa_z]","lower":"[wfa_lo]","upper":"[wfa_hi]","range":"[wfa_rng]"}';
    function dict(array $over = [], $weightAnn = null) {
        $d = [
            'record_id'  => f('enrol_form'),
            'sex'        => f('enrol_form', '', '', 'radio', '1, Male | 2, Female'),
            'dob'        => f('enrol_form', '', 'date_dmy'),
            'visit_date' => f('anthro_form', '', 'date_ymd'),
            'weight'     => f('anthro_form', $weightAnn === null ? tag(RET_ALL) : $weightAnn, 'number_1dp'),
            'wfa_z'      => f('anthro_form'),
            'wfa_lo'     => f('anthro_form', '', 'number_1dp'),
            'wfa_hi'     => f('anthro_form', '', 'number_1dp'),
            'wfa_rng'    => f('anthro_form'),
        ];
        foreach ($over as $k => $v) { if ($v === null) unset($d[$k]); else $d[$k] = $v; }
        return $d;
    }
    /** A boy born 10 Jan 2025 and weighed on 10 Jan 2026 (365 days). */
    function row(array $over = []) {
        return array_merge(['record_id' => '2', 'sex' => '1', 'dob' => '2025-01-10', 'visit_date' => '2026-01-10', 'weight' => '9.6'], $over);
    }
    function mod($dict, $data, array $settings = []) {
        $GLOBALS['__TEST_USER'] = 'nurse';
        GrowthReference::reset();
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectSettings = array_merge(['log-values' => 'raw'], $settings);
        $m->projectIdReturn = PID;
        \REDCap::$dictionary = $dict;
        \REDCap::$data = $data;
        \REDCap::$rights = ['nurse' => ['forms' => ['enrol_form' => '1', 'anthro_form' => '1']]];
        \REDCap::$repeats = [];
        \REDCap::$eventMappings = null;
        \REDCap::$saves = [];
        \REDCap::$saveErrors = [];
        \REDCap::$saveErrorsFor = null;
        \REDCap::$onSave = null;
        \REDCap::$getDataCalls = 0;
        return $m;
    }
    function save($m, $form = 'anthro_form', $inst = 1, $rec = '2', $evt = EVT) {
        $m->redcap_save_record(PID, $rec, $form, $evt, null, null, null, $inst);
    }
    function logs($m, $type) {
        return array_values(array_map(function ($c) { return $c[1]; },
            array_filter($m->logCalls, function ($c) use ($type) { return $c[0] === $type; })));
    }
    function store($rec = '2', $evt = EVT) { return isset(\REDCap::$data[$rec][$evt]) ? \REDCap::$data[$rec][$evt] : []; }
    function written($i = 0) { return isset(\REDCap::$saves[$i]['data']) ? \REDCap::$saves[$i]['data'] : null; }
    /** What GrowthReference works out for the boy of row() with these inputs. */
    function expected($y, $lo = '-2', $hi = '2', $dec = 1, $sexKey = 'male', $days = 365, $comma = false) {
        $entry = GrowthReference::entry('who-wfa', null);
        $table = GrowthReference::table($entry);
        $a = GrowthReference::axisValue($entry, ['comma' => false, 'dobDays' => 0, 'atDays' => $days]);
        return GrowthReference::outputs($entry, $table, $sexKey, $a['x'], $y, ['lo' => $lo, 'hi' => $hi], $dec, $comma);
    }
    function wantFields($o) {
        return ['wfa_z' => (string) $o['z'], 'wfa_lo' => (string) $o['lower'], 'wfa_hi' => (string) $o['upper'], 'wfa_rng' => (string) $o['range']];
    }
    function pick(array $row, array $fields) { return array_intersect_key($row, array_flip($fields)); }
    const TARGETS = ['wfa_z', 'wfa_lo', 'wfa_hi', 'wfa_rng'];

    // ---- 1) a save fills the fields, once ---------------------------------------------------
    $e = expected(9.6);
    $want = wantFields($e);
    check('the example outputs exist (z, bounds, range)', $want['wfa_z'] !== '' && $want['wfa_lo'] !== '' && $want['wfa_hi'] !== ''
        && preg_match('/^\d+\.\d to \d+\.\d kg$/', $want['wfa_rng']) === 1);
    check('the range is the bounds in words', $want['wfa_rng'] === $want['wfa_lo'] . ' to ' . $want['wfa_hi'] . ' kg');
    $m = mod(dict(), ['2' => [EVT => row()]]);
    $hooked = 0;
    \REDCap::$onSave = function () use ($m, &$hooked) { $hooked++; save($m); };   // REDCap fires the hook for the write too
    save($m);
    check('one save writes the four fields', count(\REDCap::$saves) === 1);
    check('... in the "overwrite" mode, so an empty value clears', \REDCap::$saves[0]['overwrite'] === 'overwrite' && \REDCap::$saves[0]['format'] === 'array');
    check('... to the event row of the record (got ' . json_encode(written()) . ')',
        written() === ['2' => [EVT => $want]]);
    check('the record now holds them', pick(store(), TARGETS) === $want);
    check('the hook the write fires is not audited again (' . $hooked . ' nested call)',
        $hooked === 1 && count(\REDCap::$saves) === 1 && !logs($m, 'uvalidate-audit-error'));
    check('nothing is logged as invalid or unconfigurable', !logs($m, 'invalid-id-saved') && !logs($m, 'uvalidate-unconfigurable'));
    // a second save has nothing to write
    \REDCap::$saves = [];
    save($m);
    check('the same record saved again writes nothing', !\REDCap::$saves);

    // ---- 2) a number already there, in another spelling, stays --------------------------------
    $same = $want;
    $same['wfa_lo'] = $want['wfa_lo'] . '0';        // 7.80 for 7.8
    $m = mod(dict(), ['2' => [EVT => row($same)]]);
    save($m);
    check('7.80 is not rewritten as 7.8 (nothing else differs: no write)', !\REDCap::$saves && store()['wfa_lo'] === $same['wfa_lo']);
    $stale = $want;
    $stale['wfa_hi'] = '99.9';
    $m = mod(dict(), ['2' => [EVT => row($stale)]]);
    save($m);
    check('a field that differs is written alone (got ' . json_encode(written()) . ')',
        written() === ['2' => [EVT => ['wfa_hi' => $want['wfa_hi']]]]);

    // ---- 3) nothing to report empties the fields; a problem leaves them ------------------------
    $m = mod(dict(), ['2' => [EVT => row(array_merge($want, ['sex' => '']))]]);
    save($m);
    check('no sex: every filled field is emptied, with "" (got ' . json_encode(written()) . ')',
        written() === ['2' => [EVT => ['wfa_z' => '', 'wfa_lo' => '', 'wfa_hi' => '', 'wfa_rng' => '']]]);
    check('... and the record holds none', pick(store(), TARGETS) === []);
    $m = mod(dict(), ['2' => [EVT => row(['weight' => ''])]]);
    save($m);
    $noZ = wantFields(expected(null));
    check('no measurement: the bounds and the range, no z-score (got ' . json_encode(written()) . ')',
        written() === ['2' => [EVT => ['wfa_lo' => $noZ['wfa_lo'], 'wfa_hi' => $noZ['wfa_hi'], 'wfa_rng' => $noZ['wfa_rng']]]]
        && $noZ['wfa_z'] === '' && $noZ['wfa_lo'] !== '');
    $m = mod(dict(), ['2' => [EVT => row(array_merge($want, ['dob' => 'not-a-date']))]]);
    save($m);
    // a saved date the rule cannot read says nothing (GrowthReference::inputsFor), as on the page
    check('a date this rule cannot read: the fields are emptied (got ' . json_encode(written()) . ')',
        written() === ['2' => [EVT => array_fill_keys(TARGETS, '')]]);
    check('... and it is not a rule problem', !logs($m, 'uvalidate-unconfigurable'));
    $m = mod(dict(), ['2' => [EVT => row(array_merge($want, ['weight' => '']))]]);
    $m2 = null;
    save($m);
    $un = logs($m, 'uvalidate-unconfigurable');
    check('a blank measurement with a good record: no problem logged', !$un);

    // a Missing Data Code in the measurement is a blank one: no z-score, the bounds all the same
    \Project::$codes = "UNK, Unknown
NASK, Not asked";
    $m = mod(dict(), ['2' => [EVT => row(['weight' => 'UNK'])]]);
    save($m);
    check('a Missing Data Code in the measurement: the bounds are written, no z-score (got ' . json_encode(written()) . ')',
        written() === ['2' => [EVT => ['wfa_lo' => $noZ['wfa_lo'], 'wfa_hi' => $noZ['wfa_hi'], 'wfa_rng' => $noZ['wfa_rng']]]]);
    \Project::$codes = '';

    // a measurement that is a violation is logged once: the write is not audited a second time
    $m = mod(dict(), ['2' => [EVT => row(['weight' => '3.0'])]]);
    \REDCap::$onSave = function () use ($m) { save($m); };
    save($m);
    $range = array_values(array_filter(logs($m, 'invalid-id-saved'), function ($e) { return $e['type'] === 'range'; }));
    check('a hard-low weight is logged once although the write fires the hook again (' . count($range) . ')',
        count($range) === 1 && $range[0]['reason'] === 'hard-low' && count(\REDCap::$saves) === 1);
    check('... and its z-score is still returned, past the limits',
        isset(written()['2'][EVT]['wfa_z']) && (float) written()['2'][EVT]['wfa_z'] < -5);

    // "returnLimits":"hard" makes the bounds from the hard limits
    $m = mod(dict([], tag('"returnLimits":"hard","decimals":1,' . RET_ALL)), ['2' => [EVT => row()]]);
    save($m);
    check('returnLimits hard: the bounds come from -5 and 5 (got ' . json_encode(written()) . ')',
        written() === ['2' => [EVT => wantFields(expected(9.6, '-5', '5'))]]
        && wantFields(expected(9.6, '-5', '5'))['wfa_lo'] !== $want['wfa_lo']);

    // ---- 4) a rule that reads a returned field is judged after the fill -----------------------------
    $req = dict(['wfa_z' => f('anthro_form', '@UVREQUIRED')]);
    $m = mod($req, ['2' => [EVT => row()]]);
    save($m);
    check('@UVREQUIRED on the returned z-score: filled first, so not reported (got ' . json_encode(logs($m, 'invalid-id-saved')) . ')',
        count(\REDCap::$saves) === 1 && !logs($m, 'invalid-id-saved'));
    $m = mod($req, ['2' => [EVT => row()]], ['range-return-no-save' => true]);
    save($m);
    check('with the write off, the blank z-score is reported', !\REDCap::$saves && count(logs($m, 'invalid-id-saved')) === 1);

    // ---- 5) the project setting, a failed write, the scan -----------------------------------------------
    $m = mod(dict(), ['2' => [EVT => row()]], ['range-return-no-save' => true]);
    save($m);
    check('"range-return-no-save": nothing is written, nothing is logged', !\REDCap::$saves && pick(store(), TARGETS) === []
        && !logs($m, 'uvalidate-audit-error'));
    $m = mod(dict(), ['2' => [EVT => row()]]);
    \REDCap::$saveErrors = ['2' => 'the record is locked'];
    save($m);
    $err = logs($m, 'uvalidate-audit-error');
    check('a write REDCap refuses is logged as an audit error, the save is not disturbed', count($err) === 1
        && $err[0]['stage'] === 'return values');
    // ruleFindings works the values out only when its meta asks: the audit
    // always does, the scan for a mode that compares them (5b)
    $m = mod(dict(), ['2' => [EVT => row()]]);
    $rf = new \ReflectionMethod($m, 'ruleFindings');
    $rf->setAccessible(true);
    $rules = (new \ReflectionMethod($m, 'getRules'));
    $rules->setAccessible(true);
    $all = $rules->invoke($m, PID);
    $rule = null;
    foreach ($all as $r) if (in_array('weight', $r['fields'], true)) $rule = $r;
    check('the rule carries its return keys', $rule && isset($rule['rangeReturn']) && $rule['rangeReturn']['z'] === 'wfa_z'
        && $rule['rangeDecimals'] === 1 && $rule['rangeReturnComma'] === ['z' => false, 'lower' => false, 'upper' => false, 'range' => false]);
    $vals = ['sex' => '1', 'dob' => '2025-01-10', 'visit_date' => '2026-01-10', 'weight' => '9.6'];
    $res = [];
    $r1 = $rf->invoke($m, $rule, 0, $vals, [], null, PID, '2', EVT, null, $res, []);
    check('no "returns" in meta: nothing worked out', !isset($r1['returns']));
    $r2 = $rf->invoke($m, $rule, 0, $vals, [], null, PID, '2', EVT, null, $res, ['returns' => true]);
    check('the audit path does', isset($r2['returns']) && $r2['returns'] === $want);

    // ---- 5b) the scan reports a returned value out of date (RNG-1) ---------------------------------------
    // REDCap does not run the save hook for a Data Import Tool import, so an
    // imported weight leaves the z-score of the old one. The scan compares what
    // the next save would write with what the fields hold, and writes nothing.
    $staleOf = function ($res) {
        return array_values(array_filter($res['violations'], function ($v) { return $v['reason'] === 'returned-stale'; }));
    };
    $fieldsOf = function (array $st) { $f = array_map(function ($v) { return $v['field']; }, $st); sort($f); return $f; };
    $old = wantFields(expected(9.6));
    $m = mod(dict(), ['2' => [EVT => row(['weight' => '7.7']) + $old]]);
    $res = $m->scanProject(PID);
    $st = $staleOf($res);
    check('scan: an imported weight leaves the old z-score, reported out of date (got ' . json_encode($st) . ')',
        count($st) === 1 && $st[0]['field'] === 'wfa_z' && $st[0]['type'] === 'range' && (string) $st[0]['record'] === '2'
        && $st[0]['instrument'] === 'anthro_form' && (int) $st[0]['rule'] >= 1);
    check('scan: ... the weight itself is judged as before', (bool) array_filter($res['violations'], function ($v) {
        return $v['field'] === 'weight' && $v['reason'] !== 'returned-stale';
    }));
    check('scan: ... and nothing is written', !\REDCap::$saves);
    $m = mod(dict(), ['2' => [EVT => row() + $old]]);
    check('scan: returned values that match the saved inputs are not reported', !$staleOf($m->scanProject(PID)));
    $spelled = $old;
    $spelled['wfa_lo'] = $old['wfa_lo'] . '0';
    $spelled['wfa_z'] = $old['wfa_z'] . '0';
    $m = mod(dict(), ['2' => [EVT => row() + $spelled]]);
    check('scan: the same number spelled another way is not out of date, as the save would not write it',
        !$staleOf($m->scanProject(PID)));
    $m = mod(dict(), ['2' => [EVT => row()]]);
    check('scan: a record imported without its returned values is reported once per field',
        $fieldsOf($staleOf($m->scanProject(PID))) === ['wfa_hi', 'wfa_lo', 'wfa_rng', 'wfa_z']);
    $m = mod(dict(), ['2' => [EVT => row(['weight' => '']) + $old]]);
    check('scan: a weight emptied by an import leaves a z-score the save would empty; the bounds need no weight',
        $fieldsOf($staleOf($m->scanProject(PID))) === ['wfa_z']);
    $m = mod(dict(), ['2' => [EVT => row(['sex' => '']) + $old]]);
    check('scan: with the sex emptied every returned field is out of date',
        $fieldsOf($staleOf($m->scanProject(PID))) === ['wfa_hi', 'wfa_lo', 'wfa_rng', 'wfa_z']);
    $m = mod(dict(), ['2' => [EVT => row(['weight' => '7.7']) + $old]], ['range-return-no-save' => true]);
    check('scan: with "range-return-no-save" the fields are the project\'s to fill and are not compared',
        !$staleOf($m->scanProject(PID)));
    $label = \INSPIRE\UniversalValidator\ModeRegistry::issueLabel('range', 'returned-stale');
    check('scan: the finding has a label of its own (got ' . json_encode($label) . ')', $label === 'Returned value out of date');


    // ---- 6) branches own the fields of every branch ---------------------------------------------------------
    $both = tag('"when":"[sex]=\'1\'","return":{"z":"[wfa_z]"}') . ' ' . tag('"when":"[sex]=\'2\'","return":{"range":"[wfa_rng]"}');
    $m = mod(dict([], $both), ['2' => [EVT => row(['wfa_rng' => '1.0 to 2.0 kg'])]]);
    save($m);
    check('a boy: the first branch fills the z-score and empties the other branch\'s range (got ' . json_encode(written()) . ')',
        written() === ['2' => [EVT => ['wfa_z' => $want['wfa_z'], 'wfa_rng' => '']]]);
    $m = mod(dict([], $both), ['2' => [EVT => row(['sex' => '2', 'wfa_z' => '9.99'])]]);
    save($m);
    $girl = expected(9.6, '-2', '2', 1, 'female');
    check('a girl: the second branch fills the range and empties the z-score (got ' . json_encode(written()) . ')',
        written() === ['2' => [EVT => ['wfa_z' => '', 'wfa_rng' => (string) $girl['range']]]] && (string) $girl['range'] !== '');
    $m = mod(dict([], $both), ['2' => [EVT => row(['sex' => '', 'wfa_z' => '1.50', 'wfa_rng' => '1.0 to 2.0 kg'])]]);
    save($m);
    check('neither condition is true: both fields are emptied', written() === ['2' => [EVT => ['wfa_z' => '', 'wfa_rng' => '']]]);
    $gated = tag('"when":"[sex]=\'1\'",' . RET_ALL);
    $m = mod(dict([], $gated), ['2' => [EVT => row(array_merge($want, ['sex' => '2']))]]);
    save($m);
    check('a rule whose "when" is false empties what it owns', written() === ['2' => [EVT => ['wfa_z' => '', 'wfa_lo' => '', 'wfa_hi' => '', 'wfa_rng' => '']]]);

    // ---- 7) a repeating form: the instance that was saved --------------------------------------------------------
    \REDCap::$repeats = [];
    $rep = ['2' => [EVT => ['record_id' => '2', 'sex' => '1', 'dob' => '2025-01-10'],
        'repeat_instances' => [EVT => ['anthro_form' => [
            1 => ['visit_date' => '2026-01-10', 'weight' => '9.6', 'anthro_form_complete' => '2'],
            2 => ['visit_date' => '2026-01-10', 'weight' => '11.0', 'anthro_form_complete' => '2'],
        ]]]]];
    $m = mod(dict(), $rep);
    \REDCap::$repeats = [EVT => ['anthro_form' => '']];
    save($m, 'anthro_form', 2);
    $w2 = wantFields(expected(11.0));
    check('instance 2 is written to its own row (got ' . json_encode(written()) . ')',
        written() === ['2' => ['repeat_instances' => [EVT => ['anthro_form' => [2 => $w2]]]]]);
    check('... and instance 1 is untouched', !isset(\REDCap::$data['2']['repeat_instances'][EVT]['anthro_form'][1]['wfa_z']));

    // ---- 8) the form of an input is saved: the dependant is recomputed where it lives ----------------------------
    $m = mod(dict(), ['2' => [EVT => row(array_merge($want, ['sex' => '2']))]]);
    save($m, 'enrol_form');
    $gw = wantFields(expected(9.6, '-2', '2', 1, 'female'));
    check('sex saved on enrol_form: the fields on anthro_form are recomputed for a girl (got ' . json_encode(written()) . ')',
        written() === ['2' => [EVT => array_diff_assoc($gw, $want)]] && array_diff_assoc($gw, $want) !== []);
    $m = mod(dict(), ['2' => [EVT => row($want)]]);
    save($m, 'enrol_form');
    check('a form no rule depends on and an unchanged record write nothing', !\REDCap::$saves);

    // ---- 9) the dictionary hook and the grammar -------------------------------------------------------------------------
    $opt = ['decimalComma' => false];
    function refused($dict, $needle, $label) {
        $m = mod($dict, ['2' => [EVT => row()]]);
        ob_start();
        $m->redcap_data_entry_form_top(PID, '2', 'anthro_form', EVT, null, 1);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        $cfg = json_decode(isset($mm[1]) ? $mm[1] : 'null', true);
        $err = '';
        foreach ((isset($cfg['rules']) ? $cfg['rules'] : []) as $r) {
            if (in_array('weight', isset($r['fields']) ? $r['fields'] : [], true) && !empty($r['configError'])) $err = $r['configError'];
        }
        check($label . ' (got ' . json_encode($err) . ')', $err !== '' && strpos($err, $needle) !== false);
    }
    refused(dict(['wfa_z' => null]), 'is not a field in this project', 'a target that is not a field is refused');
    refused(dict(['wfa_z' => f('anthro_form', '', '', 'notes')]), 'goes into a Text field', 'a target that is not a Text field is refused');
    refused(dict(['wfa_z' => f('enrol_form')]), 'must be on that form', 'a target on another form is refused');
    refused(dict(['wfa_rng' => f('anthro_form', '', 'integer')]), 'would refuse a text such as', 'a range target with validation is refused');
    refused(dict(['wfa_z' => f('anthro_form', '', 'integer')]), 'cannot hold a number written with decimals', 'an integer z-score target is refused');
    refused(dict(['wfa_z' => f('anthro_form', '', 'number_1dp')]), 'takes exactly 1 decimal place and this value is written with 2', 'a z-score target with one decimal is refused');
    refused(dict(['wfa_lo' => f('anthro_form', '', 'number_1dp')], tag('"decimals":2,' . RET_ALL)), 'takes exactly 1 decimal place and this value is written with 2', 'bounds with more decimals than the field takes are refused');
    // REDCap's number_Ndp takes exactly N places: "7.5" fails number_2dp, "8" fails number_1dp
    refused(dict(['wfa_lo' => f('anthro_form', '', 'number_2dp')]), 'takes exactly 2 decimal places and this value is written with 1', 'bounds with fewer decimals than the field takes are refused');
    refused(dict(['wfa_z' => f('anthro_form', '', 'number_3dp')]), 'takes exactly 3 decimal places and this value is written with 2', 'a z-score target with three decimals is refused');
    refused(dict([], tag('"return":{"z":"[weight]"}')), 'is the measured field itself', 'returning to the measured field is refused');
    refused(dict([], tag('"return":{"z":"[sex]"}')), 'which this rule reads', 'returning to an input is refused');
    refused(dict([], tag('"return":{"z":"[record_id]"}')), 'is the record ID', 'returning to the record id is refused');
    // REDCap's own writers would overwrite the returned value (returnTargetBasics, every mode).
    foreach (['@CALCTEXT(if([sex]=\'1\',\'m\',\'f\'))' => 'CALCTEXT', '@CALCDATE([dob],1,\'y\')' => 'CALCDATE', '@SETVALUE=\'1\'' => 'SETVALUE',
              '@HIDDEN @setvalue="2"' => 'SETVALUE'] as $note => $which) {
        refused(dict(['wfa_z' => f('anthro_form', $note)]), 'filled by REDCap\'s @' . $which, 'a target REDCap fills with @' . $which . ' is refused');
    }
    $m = mod(dict(['wfa_z' => f('anthro_form', '@READONLY @UVCALCTEXT_NOT_A_TAG')]), ['2' => [EVT => row()]]);
    $all = $rules->invoke($m, PID);
    $okRead = true;
    foreach ($all as $r) if (in_array('weight', $r['fields'], true) && !empty($r['configError'])) $okRead = false;
    check('@READONLY on a target, or a longer tag name that only contains CALCTEXT, is no refusal', $okRead);
    $clash = dict(['other' => f('anthro_form', tag('"return":{"z":"[wfa_z]"}'), 'number_1dp')]);
    $m = mod($clash, ['2' => [EVT => row()]]);
    $all = $rules->invoke($m, PID);
    $msgs = [];
    foreach ($all as $r) if (!empty($r['configError'])) $msgs[] = $r['configError'];
    check('two rules returning to one field are both refused, naming each other (' . count($msgs) . ')',
        count($msgs) === 2 && strpos($msgs[0], 'shares "[wfa_z]"') !== false && strpos($msgs[0], 'the rule on "') !== false);
    $e = AnnotationRules::parseAllTags(tag('"return":{"z":"[wfa_z]"},"soft":[-1,1]'));
    $e2 = AnnotationRules::parseAllTags('@UVRANGE={"hard":[1,2],"return":{"z":"[a]"}}');
    check('"return" without a reference is refused', isset($e2[0]['error']) && strpos($e2[0]['error'], 'only apply with a "reference"') !== false);
    $e3 = AnnotationRules::parseAllTags(tag('"return":{"lower":"[wfa_lo]"}', '[sex]', '[dob]'));
    check('"return" of a bound needs the limit it comes from (the rule has soft)', !isset($e3[0]['error']));
    $e4 = AnnotationRules::parseAllTags('@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"hard":[-5,5],"return":{"lower":"[wfa_lo]"}}');
    check('... and refuses one the rule lacks (soft limits are the default)', isset($e4[0]['error']) && strpos($e4[0]['error'], 'low "soft" limit') !== false);
    $e5 = AnnotationRules::parseAllTags('@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"hard":[-5,5],"return":{"lower":"[wfa_lo]"},"returnLimits":"hard"}');
    check('... unless "returnLimits":"hard" says where they come from', !isset($e5[0]['error']) && $e5[0]['rangeReturnLimits'] === 'hard');
    $e6 = AnnotationRules::parseAllTags('@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"return":{"z":"[wfa_z]"}}');
    check('a rule returning only the z-score needs no limits', !isset($e6[0]['error']) && !isset($e6[0]['rangeSoftLo']));
    foreach ([['"return":{"z":"[a]","range":"[a]"}', 'to the same field'], ['"return":{"zz":"[a]"}', 'unknown "return" value'],
              ['"return":["z"]', 'must say which field gets which value'], ['"return":{"z":"a"}', 'must be one field of this form'],
              ['"returnLimits":"soft"', 'only applies with "return"'], ['"decimals":2', 'only applies with "return"'],
              ['"return":{"z":"[a]"},"decimals":9', '"decimals" must be a whole number from 0 to 6'],
              ['"return":{"z":"[a]"},"returnLimits":"medium"', '"returnLimits" must be "soft" or "hard"']] as $pair) {
        $p = AnnotationRules::parseAllTags(tag($pair[0]));
        check('refused ' . $pair[0] . ' (got ' . json_encode(isset($p[0]['error']) ? $p[0]['error'] : null) . ')',
            isset($p[0]['error']) && strpos($p[0]['error'], $pair[1]) !== false);
    }

    // ---- 10) what the page is given ---------------------------------------------------------------------------------------------
    function page($m, $form = 'anthro_form') {
        ob_start();
        $m->redcap_data_entry_form_top(PID, '2', $form, EVT, null, 1);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return json_decode(isset($mm[1]) ? $mm[1] : 'null', true);
    }
    $m = mod(dict(['wfa_z' => f('anthro_form', '', 'number_comma_decimal'), 'wfa_hi' => f('anthro_form', '', 'number_1dp_comma_decimal')]),
        ['2' => [EVT => row()]]);
    $cfg = page($m);
    $r = null;
    foreach ($cfg['rules'] as $x) if (in_array('weight', $x['fields'], true)) $r = $x;
    check('the page carries the targets (got ' . json_encode($r['rangeReturn'] ?? null) . ')',
        $r && $r['rangeReturn'] === ['z' => 'wfa_z', 'lower' => 'wfa_lo', 'upper' => 'wfa_hi', 'range' => 'wfa_rng']);
    check('... the decimals of the measured field (number_1dp: 1)', $r['rangeDecimals'] === 1);
    check('... and the decimal mark each field takes', $r['rangeReturnComma'] === ['z' => true, 'lower' => false, 'upper' => true, 'range' => false]);
    // whole-number bounds go into integer fields (number_1dp would refuse "8")
    $m = mod(dict(['weight' => f('anthro_form', tag(RET_ALL), 'integer'), 'wfa_lo' => f('anthro_form', '', 'integer'),
        'wfa_hi' => f('anthro_form', '', 'integer')]), ['2' => [EVT => row()]]);
    $cfg = page($m);
    foreach ($cfg['rules'] as $x) if (in_array('weight', $x['fields'], true)) $r = $x;
    check('a measured integer field returns bounds with no decimals', $r['rangeDecimals'] === 0);
    $m = mod(dict(['weight' => f('anthro_form', tag('"decimals":3,' . RET_ALL), 'number_1dp'), 'wfa_lo' => f('anthro_form'), 'wfa_hi' => f('anthro_form')]),
        ['2' => [EVT => row()]]);
    $cfg = page($m);
    foreach ($cfg['rules'] as $x) if (in_array('weight', $x['fields'], true)) $r = $x;
    check('"decimals" is kept as written', $r['rangeDecimals'] === 3);

    // ---- 11) the table cap defers the rule; the save still fills it ---------------------------------------------------------------
    $many = [];
    foreach (['who-lhfa', 'who-bfa', 'who-hcfa', 'who-acfa'] as $i => $ref) {
        $many['m' . $i] = f('anthro_form', '@UVRANGE={"reference":"' . $ref . '","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"hard":[-5,5]}', 'number_1dp');
    }
    // the rules are taken in dictionary order: the measured weight goes last, past the cap
    $capDict = dict($many);
    $w = $capDict['weight'];
    unset($capDict['weight']);
    $capDict['weight'] = $w;
    // a folder of extra references whose index sets the cap to one table's rows
    $capDir = sys_get_temp_dir() . '/uv_return_cap_' . getmypid();
    if (!is_dir($capDir)) mkdir($capDir);
    file_put_contents($capDir . '/index.json', json_encode(['format' => 'uv-references-1', 'pageTables' => 1, 'references' => new \stdClass()]));
    $m = mod($capDict, ['2' => [EVT => row()]]);
    $m->systemSettings = ['reference-data-dir' => $capDir];
    $cfg = page($m);
    $r = null;
    foreach ($cfg['rules'] as $x) if (in_array('weight', $x['fields'], true)) $r = $x;
    check('past the cap the rule is deferred to the save, and says it fills its fields then (got '
        . json_encode($r['deferredWhy'] ?? null) . ')',
        $r && !empty($r['deferredOnSave']) && strpos(implode(' ', $r['deferredWhy']), 'and the fields it returns are filled then') !== false);
    save($m);
    check('... and the save fills them', count(\REDCap::$saves) >= 1 && pick(store(), TARGETS) === $want);
    unlink($capDir . '/index.json');
    rmdir($capDir);

    // ---- 12) an input in another event (the extended path) ---------------------------------------------------------------------------
    $evdict = dict(['weight' => f('anthro_form', tag(RET_ALL, '[enrol_arm_1][sex]', '[enrol_arm_1][dob]'), 'number_1dp')]);
    $m = mod($evdict, ['2' => [1 => ['record_id' => '2', 'sex' => '1', 'dob' => '2025-01-10'],
        2 => ['visit_date' => '2026-01-10', 'weight' => '9.6']]], ['enable-event-instance-refs' => true]);
    \REDCap::$eventMappings = [['event_id' => 1, 'form' => 'enrol_form'], ['event_id' => 2, 'form' => 'anthro_form']];
    $GLOBALS['Proj'] = (object) ['project_id' => PID, 'longitudinal' => true,
        'eventInfo' => [1 => ['unique_event_name' => 'enrol_arm_1', 'arm_num' => 1], 2 => ['unique_event_name' => 'visit_arm_1', 'arm_num' => 1]],
        'eventsForms' => [1 => ['enrol_form'], 2 => ['anthro_form']]];
    save($m, 'anthro_form', 1, '2', 2);
    check('an input read from another event: the fields are filled in the event of the measurement (got ' . json_encode(written()) . ')',
        written() === ['2' => [2 => $want]] && !logs($m, 'uvalidate-unconfigurable'));

    /** A project with an enrolment event (1) and two visits (2 and 3) of anthro_form. */
    function evMod($dict, $data) {
        $m = mod($dict, $data, ['enable-event-instance-refs' => true]);
        \REDCap::$eventMappings = [['event_id' => 1, 'form' => 'enrol_form'], ['event_id' => 2, 'form' => 'anthro_form'],
            ['event_id' => 3, 'form' => 'anthro_form']];
        $GLOBALS['Proj'] = (object) ['project_id' => PID, 'longitudinal' => true,
            'eventInfo' => [1 => ['unique_event_name' => 'enrol_arm_1', 'arm_num' => 1], 2 => ['unique_event_name' => 'visit_arm_1', 'arm_num' => 1],
                3 => ['unique_event_name' => 'visit2_arm_1', 'arm_num' => 1]],
            'eventsForms' => [1 => ['enrol_form'], 2 => ['anthro_form'], 3 => ['anthro_form']]];
        return $m;
    }
    /** tag() with the sex and the date of birth read from the enrolment event */
    function evTag($extra) { return tag($extra, '[enrol_arm_1][sex]', '[enrol_arm_1][dob]'); }
    function rulesOf($m) {
        $g = new \ReflectionMethod($m, 'getRules');
        $g->setAccessible(true);
        return $g->invoke($m, PID);
    }
    /** an array with its keys in order, to compare two answers whatever order the groups were judged in */
    function sorted(array $a) { ksort($a); return $a; }
    function ruleOn($m, $field) {
        foreach (rulesOf($m) as $r) if (in_array($field, $r['fields'], true)) return $r;
        return null;
    }
    $enrol = ['record_id' => '2', 'sex' => '1', 'dob' => '2025-01-10'];
    $visit = ['visit_date' => '2026-01-10', 'weight' => '9.6'];

    // ---- 13) the extended path owns the fields of the rule as written ---------------------------------------------
    $evBoth = evTag('"when":"[enrol_arm_1][sex]=\'1\'","return":{"z":"[wfa_z]"}') . ' '
        . evTag('"when":"[enrol_arm_1][sex]=\'2\'","return":{"range":"[wfa_rng]"}');
    $m = evMod(dict([], $evBoth), ['2' => [1 => $enrol, 2 => $visit + ['wfa_rng' => '1.0 to 2.0 kg']]]);
    save($m, 'anthro_form', 1, '2', 2);
    check('extended branches, a boy: the z-score is filled and the girl branch\'s range emptied (got ' . json_encode(written()) . ')',
        written() === ['2' => [2 => ['wfa_z' => $want['wfa_z'], 'wfa_rng' => '']]] && !logs($m, 'uvalidate-unconfigurable'));
    $m = evMod(dict([], $evBoth), ['2' => [1 => ['sex' => ''] + $enrol, 2 => $visit + ['wfa_z' => '1.50', 'wfa_rng' => '1.0 to 2.0 kg']]]);
    save($m, 'anthro_form', 1, '2', 2);
    check('extended branches, no branch applies: both fields are emptied (got ' . json_encode(written()) . ')',
        written() === ['2' => [2 => ['wfa_z' => '', 'wfa_rng' => '']]]);
    $m = evMod(dict([], evTag('"when":"[enrol_arm_1][sex]=\'1\'",' . RET_ALL)), ['2' => [1 => ['sex' => '2'] + $enrol, 2 => $visit + $want]]);
    save($m, 'anthro_form', 1, '2', 2);
    check('an extended rule whose "when" is false empties what it owns', written() === ['2' => [2 => array_fill_keys(TARGETS, '')]]);

    // two references on one field: each group fills its own fields, a group with no branch empties its own
    $wfl = '@UVRANGE={"reference":"who-wfl","sex":"[enrol_arm_1][sex]","male":"1","female":"2","by":"[length]",'
        . '"soft":[-2,2],"hard":[-5,5],"when":"[length]<>\'\'","return":{"z":"[wfl_z]"}}';
    $grp = dict(['length' => f('anthro_form', '', 'number_1dp'), 'wfl_z' => f('anthro_form')], evTag('"return":{"z":"[wfa_z]"}') . ' ' . $wfl);
    $m = evMod($grp, ['2' => [1 => $enrol, 2 => $visit + ['length' => '75.0']]]);
    $entryL = GrowthReference::entry('who-wfl', null);
    $wantL = (string) GrowthReference::outputs($entryL, GrowthReference::table($entryL), 'male',
        GrowthReference::axisValue($entryL, ['by' => '75.0'])['x'], 9.6, ['lo' => '-2', 'hi' => '2'], 1)['z'];
    check('the rule has a group per reference', ($r = ruleOn($m, 'weight')) && count($r['branches']) === 2 && empty($r['configError']));
    save($m, 'anthro_form', 1, '2', 2);
    check('two references, extended: each fills its own z-score (got ' . json_encode(written()) . ')',
        isset(written()['2'][2]) && sorted(written()['2'][2]) === ['wfa_z' => $want['wfa_z'], 'wfl_z' => $wantL] && $wantL !== '');
    $m = evMod($grp, ['2' => [1 => $enrol, 2 => $visit + ['wfa_z' => $want['wfa_z'], 'wfl_z' => '0.50']]]);
    save($m, 'anthro_form', 1, '2', 2);
    check('... no length: the weight-for-length branch applies nowhere and empties its field alone (got ' . json_encode(written()) . ')',
        written() === ['2' => [2 => ['wfl_z' => '']]]);

    // ruleFindings itself: the fields come from the rule as written; a group the audit could not judge keeps its own
    $m = mod(dict(['length' => f('anthro_form', '', 'number_1dp'), 'wfl_z' => f('anthro_form')],
        tag('"return":{"z":"[wfa_z]"}') . ' ' . str_replace('[enrol_arm_1][sex]', '[sex]', $wfl)), ['2' => [EVT => row()]]);
    $orig = ruleOn($m, 'weight');
    $vals = ['sex' => '1', 'dob' => '2025-01-10', 'visit_date' => '2026-01-10', 'weight' => '9.6', 'length' => ''];
    $res = [];
    $all = $rf->invoke($m, $orig, 0, $vals, [], null, PID, '2', EVT, null, $res, ['returns' => true]);
    check('ruleFindings, two groups: one fills, the other (no length) is emptied (got ' . json_encode($all['returns'] ?? null) . ')',
        sorted($all['returns'] ?? []) === ['wfa_z' => $want['wfa_z'], 'wfl_z' => '']);
    $kept = $rf->invoke($m, $orig, 0, $vals, [], null, PID, '2', EVT, null, $res, ['returns' => true, 'returnKeep' => ['who-wfl']]);
    check('... a group in "returnKeep" keeps its field (got ' . json_encode($kept['returns'] ?? null) . ')',
        ($kept['returns'] ?? null) === ['wfa_z' => $want['wfa_z']]);
    $copy = $orig;
    $copy['branches'] = array_values(array_filter($orig['branches'], function ($b) { return $b['rangeReference'] === 'who-wfa'; }));
    $own = $rf->invoke($m, $copy, 0, $vals, [], null, PID, '2', EVT, null, $res, ['returns' => true, 'returnOwner' => $orig]);
    check('... a copy without a group still empties that group\'s field, the rule as written owns it (got '
        . json_encode($own['returns'] ?? null) . ')', sorted($own['returns'] ?? []) === ['wfa_z' => $want['wfa_z'], 'wfl_z' => '']);
    $bare = $rf->invoke($m, $copy, 0, $vals, [], null, PID, '2', EVT, null, $res, ['returns' => true]);
    check('... and without the owner the copy says nothing of it', ($bare['returns'] ?? null) === ['wfa_z' => $want['wfa_z']]);
    // a group whose branch applies but whose input this context could not read keeps its field
    $m = mod(dict(['length' => f('anthro_form', '', 'number_1dp'), 'wfl_z' => f('anthro_form'),
        'sex2' => f('anthro_form', '', '', 'radio', '1, Male | 2, Female')],
        tag('"return":{"z":"[wfa_z]"}') . ' @UVRANGE={"reference":"who-wfl","sex":"[sex2]","male":"1","female":"2","by":"[length]",'
        . '"hard":[-5,5],"return":{"z":"[wfl_z]"}}'), ['2' => [EVT => row()]]);
    $unread = ['sex2' => 'unreadable'];
    $held = $rf->invoke($m, ruleOn($m, 'weight'), 0, $vals + ['sex2' => '1'], [], null, PID, '2', EVT, null, $unread, ['returns' => true]);
    check('... a group whose input could not be read keeps its field, the other fills (got ' . json_encode($held['returns'] ?? null) . ')',
        ($held['returns'] ?? null) === ['wfa_z' => $want['wfa_z']] && $held['unconfigurable']);
    // a branch conflict in one reference leaves the whole field unjudged: nothing is returned, as on the page
    $wflWhen = function ($when) {
        return '@UVRANGE={"reference":"who-wfl","sex":"[sex]","male":"1","female":"2","by":"[length]","hard":[-5,5],"when":"' . $when
            . '","return":{"z":"[wfl_z]"}}';
    };
    $m = mod(dict(['length' => f('anthro_form', '', 'number_1dp'), 'wfl_z' => f('anthro_form')],
        tag('"return":{"z":"[wfa_z]"}') . ' ' . $wflWhen("[sex]='1'") . ' ' . $wflWhen("[length]<>''")), ['2' => [EVT => row()]]);
    $cf = $rf->invoke($m, ruleOn($m, 'weight'), 0, ['length' => '75.0'] + $vals, [], null, PID, '2', EVT, null, $res, ['returns' => true]);
    check('... a branch conflict in one reference: nothing is returned (got ' . json_encode($cf['returns'] ?? null) . ')',
        !isset($cf['returns']) && strpos(json_encode($cf['unconfigurable']), 'branch conflict') !== false);

    // ---- 14) the rules that return values are judged and written first, on both paths ----------------------------------
    $evRet = evTag(RET_ALL);
    $m = evMod(dict(['wfa_z' => f('anthro_form', '@UVREQUIRED')], $evRet), ['2' => [1 => $enrol, 2 => $visit]]);
    save($m, 'anthro_form', 1, '2', 2);
    check('an extended rule fills the z-score before @UVREQUIRED reads it (got ' . json_encode(logs($m, 'invalid-id-saved')) . ')',
        written() === ['2' => [2 => $want]] && !logs($m, 'invalid-id-saved'));
    $m = evMod(dict(['wfa_z' => f('anthro_form', '@UVREQUIRED={"when":"[enrol_arm_1][sex]<>\'\'"}')], $evRet), ['2' => [1 => $enrol, 2 => $visit]]);
    save($m, 'anthro_form', 1, '2', 2);
    check('... and before an extended @UVREQUIRED reads it (got ' . json_encode(logs($m, 'invalid-id-saved')) . ')',
        written() === ['2' => [2 => $want]] && !logs($m, 'invalid-id-saved'));
    $m = evMod(dict(['wfa_z' => f('anthro_form', '@UVREQUIRED')], $evRet), ['2' => [1 => $enrol, 2 => $visit]]);
    $calls = 0;
    \REDCap::$saveErrorsFor = function () use (&$calls) { $calls++; throw new \RuntimeException('disk full'); };
    save($m, 'anthro_form', 1, '2', 2);
    check('a write that throws is logged, and the other rules are still judged (got ' . json_encode(logs($m, 'uvalidate-audit-error')) . ')',
        $calls === 1 && count(logs($m, 'uvalidate-audit-error')) === 1 && count(logs($m, 'invalid-id-saved')) === 1);

    // ---- 15) each context is written by a save of its own, the saved one first ------------------------------------------
    $two = ['2' => [1 => $enrol, 2 => $visit, 3 => ['visit_date' => '2026-01-10', 'weight' => '11.0']]];
    $w11 = wantFields(expected(11.0));
    $m = evMod(dict([], $evRet), $two);
    save($m, 'anthro_form', 1, '2', 2);
    check('visit 1 saved: both visits are filled, one save each, visit 1 first (got ' . json_encode(array_column(\REDCap::$saves, 'data')) . ')',
        count(\REDCap::$saves) === 2 && written(0) === ['2' => [2 => $want]] && written(1) === ['2' => [3 => $w11]]);
    $m = evMod(dict([], $evRet), $two);
    save($m, 'anthro_form', 1, '2', 3);
    check('visit 2 saved: visit 2 is written first', count(\REDCap::$saves) === 2 && written(0) === ['2' => [3 => $w11]]);
    $m = evMod(dict([], $evRet), $two);
    \REDCap::$saveErrorsFor = function ($data) { return isset($data['2'][3]) ? ['2' => 'the form is locked'] : []; };
    save($m, 'anthro_form', 1, '2', 2);
    $err = logs($m, 'uvalidate-audit-error');
    check('a locked visit 2: visit 1 is written all the same, and the refusal is logged once (got ' . json_encode($err) . ')',
        pick(store('2', 2), TARGETS) === $want && pick(store('2', 3), TARGETS) === [] && count($err) === 1
        && $err[0]['stage'] === 'return values' && count(\REDCap::$saves) === 2);
    $m = evMod(dict([], $evRet), $two);
    \REDCap::$saveErrorsFor = function ($data) { if (isset($data['2'][2])) throw new \RuntimeException('disk full'); return []; };
    save($m, 'anthro_form', 1, '2', 2);
    check('a write of visit 1 that throws: visit 2 is still written (got ' . json_encode(pick(store('2', 3), TARGETS)) . ')',
        pick(store('2', 2), TARGETS) === [] && pick(store('2', 3), TARGETS) === $w11 && count(logs($m, 'uvalidate-audit-error')) === 1);

    // ---- 16) "measureScale" and "unit": the bounds in the unit the field holds ----------------------------------------------
    $entry = GrowthReference::entry('who-wfa', null);
    $a365 = GrowthReference::axisValue($entry, ['comma' => false, 'dobDays' => 0, 'atDays' => 365]);
    $grams = GrowthReference::measure('9600', false, '0.001');
    $gWant = GrowthReference::outputs($entry, GrowthReference::table($entry), 'male', $a365['x'], $grams['y'],
        ['lo' => '-2', 'hi' => '2'], 0, false, '0.001', 'g');
    $m = mod(dict(['weight' => f('anthro_form', tag('"measureScale":0.001,"unit":"g",' . RET_ALL), 'integer'), 'wfa_lo' => f('anthro_form', '', 'integer'),
        'wfa_hi' => f('anthro_form', '', 'integer')]), ['2' => [EVT => row(['weight' => '9600'])]]);
    save($m);
    check('grams typed into a table in kg: the bounds are whole grams and the range says g (got ' . json_encode(written()) . ')',
        written() === ['2' => [EVT => wantFields($gWant)]] && preg_match('/^\d{4,5} to \d{4,5} g$/', $gWant['range']) === 1
        // the kg bounds are rounded inward to 0.1 kg; the gram bounds lie within those 100 g
        && $gWant['z'] === $want['wfa_z'] && (int) $gWant['lower'] <= 1000 * (float) $want['wfa_lo'] && (int) $gWant['lower'] > 1000 * (float) $want['wfa_lo'] - 100
        && (int) $gWant['upper'] >= 1000 * (float) $want['wfa_hi'] && (int) $gWant['upper'] < 1000 * (float) $want['wfa_hi'] + 100);
    $m = mod(dict(['weight' => f('anthro_form', tag('"measureScale":0.001,' . RET_ALL), 'integer'), 'wfa_lo' => f('anthro_form', '', 'integer'),
        'wfa_hi' => f('anthro_form', '', 'integer')]), ['2' => [EVT => row(['weight' => '9600'])]]);
    save($m);
    check('... without "unit" the range names no unit (not the table\'s kg) (got ' . json_encode(written()['2'][EVT]['wfa_rng'] ?? null) . ')',
        (written()['2'][EVT]['wfa_rng'] ?? null) === $gWant['lower'] . ' to ' . $gWant['upper']);
    $p = AnnotationRules::parseAllTags(tag('"measureScale":0.001,"unit":"g",' . RET_ALL));
    check('"unit" with a reference is taken beside "measureScale"', !isset($p[0]['error']) && $p[0]['rangeUnit'] === 'g');
    $p = AnnotationRules::parseAllTags(tag('"unit":"g",' . RET_ALL));
    check('... and refused without it', isset($p[0]['error']) && strpos($p[0]['error'], 'unless "measureScale" is set') !== false);

    // ---- 17) a whole-number bound goes into an integer field --------------------------------------------------------------------
    $intDict = dict(['wfa_lo' => f('anthro_form', '', 'integer'), 'wfa_hi' => f('anthro_form', '', 'integer')], tag('"decimals":0,' . RET_ALL));
    $m = mod($intDict, ['2' => [EVT => row()]]);
    check('integer bound fields with "decimals":0 are taken', ($r = ruleOn($m, 'weight')) && empty($r['configError']));
    save($m);
    $i0 = wantFields(expected(9.6, '-2', '2', 0));
    check('... and get whole numbers (got ' . json_encode(written()) . ')', written() === ['2' => [EVT => $i0]]
        && ctype_digit($i0['wfa_lo']) && ctype_digit($i0['wfa_hi']));
    refused(dict(['wfa_lo' => f('anthro_form', '', 'integer')]), 'set "decimals" to 0', 'an integer bound field with decimals is refused');

    // ---- 18) a rule that returns values reads no returned value; two references share no field -----------------------------------
    $chain = dict(['height' => f('anthro_form', '@UVRANGE={"reference":"who-lhfa","sex":"[sex]","male":"1","female":"2",'
        . '"age":{"dob":"[dob]","at":"[visit_date]"},"hard":[-5,5],"when":"[wfa_z]<>\'\'","return":{"z":"[lh_z]"}}', 'number_1dp'),
        'lh_z' => f('anthro_form')]);
    $m = mod($chain, ['2' => [EVT => row()]]);
    $h = ruleOn($m, 'height');
    check('a rule reading another rule\'s returned field is refused, naming it (got ' . json_encode($h['configError'] ?? null) . ')',
        $h && strpos((string) ($h['configError'] ?? ''), 'it reads "[wfa_z]", which the rule on "weight" returns a value to') !== false);
    check('... and the rule that writes is not', empty(ruleOn($m, 'weight')['configError']));
    $m = mod(dict([], tag('"when":"[wfa_z]=\'\'",' . RET_ALL)), ['2' => [EVT => row()]]);
    check('a rule reading its own returned field is refused (got ' . json_encode(ruleOn($m, 'weight')['configError'] ?? null) . ')',
        strpos((string) (ruleOn($m, 'weight')['configError'] ?? ''), 'which this rule returns a value to') !== false);
    $m = mod(dict(['length' => f('anthro_form', '', 'number_1dp')], tag('"return":{"z":"[wfa_z]"}') . ' '
        . '@UVRANGE={"reference":"who-wfl","sex":"[sex]","male":"1","female":"2","by":"[length]","hard":[-5,5],"return":{"z":"[wfa_z]"}}'),
        ['2' => [EVT => row()]]);
    check('two references returning to one field are refused (got ' . json_encode(ruleOn($m, 'weight')['configError'] ?? null) . ')',
        strpos((string) (ruleOn($m, 'weight')['configError'] ?? ''), '"[wfa_z]" is returned to by both reference "who-wfa" and reference "who-wfl"') !== false);
    $m = mod(dict([], tag('"when":"[sex]=\'1\'","return":{"z":"[wfa_z]"}') . ' ' . tag('"when":"[sex]=\'2\'","return":{"z":"[wfa_z]"}')),
        ['2' => [EVT => row()]]);
    check('two branches of one reference may share a field (one is active)', empty(ruleOn($m, 'weight')['configError']));

    // ---- 19) the live fixture: section J of docs/testbed/RANGE_LIVE_ACCEPTANCE.md expects these -------------------------------
    $fx = fopen(__DIR__ . '/../docs/testbed/uvrange_return_test_fields.csv', 'r');
    fgetcsv($fx, 0, ',', '"', '\\');
    $live = ['record_id' => f('uv_growth_return')];
    while (($c = fgetcsv($fx, 0, ',', '"', '\\')) !== false) {
        if (count($c) < 18) continue;
        $live[$c[0]] = f($c[1], $c[17], $c[7], $c[3], $c[5]);
    }
    fclose($fx);
    $m = mod($live, ['2' => [EVT => ['record_id' => '2', 'ugr_sex' => '1', 'ugr_dob' => '2025-01-10', 'ugr_visit' => '2026-01-10',
        'ugr_weight' => '9.6', 'ugr_weight_g' => '9600']]]);
    // The person who saves the form may change it: the save writes returned
    // values only into such a form (EXS-3).
    \REDCap::$rights['nurse']['forms']['uv_growth_return'] = '1';
    $errs = [];
    foreach (rulesOf($m) as $r) $errs[$r['fields'][0]] = isset($r['configError']) ? (string) $r['configError'] : '';
    check('live fixture: the four good rules are taken (got ' . json_encode(array_intersect_key($errs,
        array_flip(['ugr_weight', 'ugr_head', 'ugr_height', 'ugr_weight_g']))) . ')',
        $errs['ugr_weight'] === '' && $errs['ugr_head'] === '' && $errs['ugr_height'] === '' && $errs['ugr_weight_g'] === '');
    check('live fixture: the four bad rules are refused for their own reason (J1)',
        strpos($errs['ugr_bad_notes'], 'a returned value goes into a Text field') !== false
        && strpos($errs['ugr_bad_clash_a'], 'a field takes the value of one rule') !== false
        && strpos($errs['ugr_bad_clash_b'], 'a field takes the value of one rule') !== false
        && strpos($errs['ugr_bad_chain'], 'it reads "[ugr_z]", which the rule on "ugr_weight" returns a value to') !== false);
    save($m, 'uv_growth_return');
    check('live fixture: a boy of 365 days, 9.6 kg and 9600 g (J2, J3, J15) (got ' . json_encode(written()) . ')',
        written() === ['2' => [EVT => ['ugr_z' => '-0.04', 'ugr_low' => '7.8', 'ugr_high' => '11.9', 'ugr_range' => '7.8 to 11.9 kg',
            'ugr_height_range' => '64 to 87 cm', 'ugr_range_g' => '7737 to 11989 g']]]);

    // ---- 14) the percentile and the values at a z-score or percentile (php/growth_outputs.json) -------------
    /** What GrowthReference works out for the boy of row(): output $of with $param, to $places. */
    function expectedOutput($of, $param, $places, $y = 9.6, $sex = 'male') {
        $entry = GrowthReference::entry('who-wfa', null);
        $table = GrowthReference::table($entry);
        $a = GrowthReference::axisValue($entry, ['comma' => false, 'dobDays' => 0, 'atDays' => 365]);
        return GrowthReference::output($entry, $table, $sex, $a['x'], $y, $of, $param, $places);
    }
    // the values docs/action_tag_validation_examples.md (Level 6), README.md, USER_GUIDE.md and CHANGELOG.md print
    foreach ([['male', 'percentile', null, 9.6, '48.3'], ['male', 'valueAtZ', '-2', null, '7.7'],
              ['male', 'valueAtPercentile', '50', null, '9.6'], ['male', 'valueAtPercentile', '97', null, '11.8'],
              ['female', 'percentile', null, 9.6, '71.6'], ['female', 'valueAtZ', '-2', null, '7.0'],
              ['female', 'valueAtPercentile', '50', null, '8.9'], ['female', 'valueAtPercentile', '97', null, '11.3'],
              ['male', 'percentile', null, 6.6, '0.1']] as $c) {
        $got = expectedOutput($c[1], $c[2], 1, $c[3], $c[0]);
        check("outputs: the documented $c[0] $c[1]" . ($c[2] === null ? '' : ":$c[2]") . " is $c[4] (got " . json_encode($got) . ')', $got === $c[4]);
    }
    $ret = '"return":{"percentile":"[wfa_pct]","valueAtZ:-2":"[wfa_m2]","valueAtPercentile:50":"[wfa_p50]"}';
    $newDict = function (array $over = [], $ann = null) use ($ret) {
        return dict(array_merge(['wfa_pct' => f('anthro_form', '', 'number_1dp'), 'wfa_m2' => f('anthro_form', '', 'number_1dp'),
            'wfa_p50' => f('anthro_form')], $over), $ann === null ? tag($ret) : $ann);
    };
    $want = ['wfa_pct' => expectedOutput('percentile', null, 1), 'wfa_m2' => expectedOutput('valueAtZ', '-2', 1),
             'wfa_p50' => expectedOutput('valueAtPercentile', '50', 1)];
    check('outputs: the example values exist (got ' . json_encode($want) . ')',
        preg_match('/^\d+\.\d$/', $want['wfa_pct']) === 1 && preg_match('/^\d+\.\d$/', $want['wfa_m2']) === 1
        && preg_match('/^\d+\.\d$/', $want['wfa_p50']) === 1);
    check('outputs: the median is the measurement at z 0', $want['wfa_p50'] === expectedOutput('valueAtZ', '0', 1));
    $m = mod($newDict(), ['2' => [EVT => row()]]);
    save($m);
    check('outputs: a save writes the percentile and the reference values (got ' . json_encode(written()) . ')',
        written() === ['2' => [EVT => $want]]);
    \REDCap::$saves = [];
    save($m);
    check('outputs: saved again, nothing to write', !\REDCap::$saves);
    $m = mod($newDict(), ['2' => [EVT => row(['weight' => ''])]]);
    save($m);
    check('outputs: no measurement leaves the percentile empty, the reference values are written (got ' . json_encode(written()) . ')',
        written() === ['2' => [EVT => ['wfa_m2' => $want['wfa_m2'], 'wfa_p50' => $want['wfa_p50']]]]);
    // no limits needed when no value is made from one
    $free = '@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},' . $ret . '}';
    $p = AnnotationRules::parseAllTags($free);
    check('outputs: a rule returning only the percentile and reference values needs no limits (got '
        . json_encode(isset($p[0]['error']) ? $p[0]['error'] : null) . ')', !isset($p[0]['error']) && !isset($p[0]['rangeSoftLo']));
    $m = mod($newDict([], $free), ['2' => [EVT => row()]]);
    save($m);
    check('outputs: ... and it writes them', written() === ['2' => [EVT => $want]]);
    $p = AnnotationRules::parseAllTags('@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"return":{"percentile":"[a]","lower":"[b]"}}');
    check('outputs: a bound beside them still needs its limit', isset($p[0]['error']) && strpos($p[0]['error'], 'needs "soft" or "hard" limits') !== false);
    // the grammar
    $many = [];
    for ($i = 0; $i < 17; $i++) $many[] = '"valueAtZ:' . sprintf('%.1f', ($i - 8) / 2) . '":"[f' . $i . ']"';
    foreach ([['"return":{"valueAtZ:9":"[a]"}', 'a number from -6 to 6 with at most 2 decimals'],
              ['"return":{"valueAtPercentile":"[a]"}', 'takes the percentile after a colon'],
              ['"return":{"percentile:3":"[a]"}', 'unknown "return" value "percentile:3"'],
              ['"return":{"valueAtPercentile:97":"a"}', 'such as "[wfa_p97]"'],
              ['"return":{"percentile":"[a]"},"percentileDecimals":5', '"percentileDecimals" must be a whole number from 0 to 3'],
              ['"return":{"percentile":"[a]"},"percentileDecimals":"1"', '"percentileDecimals" must be a whole number from 0 to 3'],
              ['"percentileDecimals":2', '"percentileDecimals" only applies with "return"'],
              ['"return":{"z":"[a]"},"percentileDecimals":2', 'and "return" has none'],
              ['"return":{' . implode(',', $many) . '}', '"return" is limited to 16 values']] as $pair) {
        $p = AnnotationRules::parseAllTags(tag($pair[0]));
        check('outputs: refused ' . substr($pair[0], 0, 60) . ' (got ' . json_encode(isset($p[0]['error']) ? $p[0]['error'] : null) . ')',
            isset($p[0]['error']) && strpos($p[0]['error'], $pair[1]) !== false);
    }
    $p = AnnotationRules::parseAllTags('@UVRANGE={"hard":[1,2],"percentileDecimals":1}');
    check('outputs: "percentileDecimals" without a reference is refused', isset($p[0]['error'])
        && strpos($p[0]['error'], 'only apply with a "reference"') !== false);
    $p = AnnotationRules::parseAllTags(tag('"return":{"percentile":"[a]","valueAtZ:-2.25":"[b]"},"percentileDecimals":2'));
    check('outputs: keys and percentileDecimals are kept as written', !isset($p[0]['error'])
        && $p[0]['rangeReturn'] === ['percentile' => 'a', 'valueAtZ:-2.25' => 'b'] && $p[0]['rangePercentileDecimals'] === 2);
    // the fields the values go to
    refused($newDict(['wfa_pct' => f('anthro_form', '', 'number_2dp')]), 'takes exactly 2 decimal places and this value is written with 1 — give it number validation without a place count, or set "percentileDecimals" to 2',
        'outputs: a percentile field with two places and the default one decimal is refused');
    refused($newDict(['wfa_pct' => f('anthro_form', '', 'integer')]), 'set "percentileDecimals" to 0',
        'outputs: an integer percentile field needs "percentileDecimals":0');
    refused($newDict(['wfa_m2' => f('anthro_form', '', 'number_2dp')]), 'or set "decimals" to 2',
        'outputs: a reference value takes "decimals"');
    refused($newDict(['wfa_p50' => f('anthro_form', '', 'date_ymd')]), 'cannot hold a number written with decimals',
        'outputs: a date field cannot take a reference value');
    refused($newDict(['wfa_p50' => f('anthro_form', '', '', 'notes')]), 'goes into a Text field',
        'outputs: a Notes field cannot take a reference value');
    $m = mod($newDict(['wfa_pct' => f('anthro_form', '', 'integer')], tag('"return":{"percentile":"[wfa_pct]"},"percentileDecimals":0')),
        ['2' => [EVT => row()]]);
    $cfg = page($m);
    $r = null;
    foreach ($cfg['rules'] as $x) if (in_array('weight', $x['fields'], true)) $r = $x;
    check('outputs: an integer field takes a percentile written without decimals', $r && empty($r['configError']));
    // what the page is given
    $m = mod($newDict(['wfa_m2' => f('anthro_form', '', 'number_comma_decimal')]), ['2' => [EVT => row()]]);
    $cfg = page($m);
    $r = null;
    foreach ($cfg['rules'] as $x) if (in_array('weight', $x['fields'], true)) $r = $x;
    check('outputs: the page carries the keys as written (got ' . json_encode($r['rangeReturn'] ?? null) . ')',
        $r && $r['rangeReturn'] === ['percentile' => 'wfa_pct', 'valueAtZ:-2' => 'wfa_m2', 'valueAtPercentile:50' => 'wfa_p50']);
    check('outputs: ... percentileDecimals resolved to its default of one', $r['rangePercentileDecimals'] === 1);
    check('outputs: ... and the decimal mark of each field', $r['rangeReturnComma'] === ['percentile' => false, 'valueAtZ:-2' => true, 'valueAtPercentile:50' => false]);
    $m = mod(dict(), ['2' => [EVT => row()]]);
    $cfg = page($m);
    $r = null;
    foreach ($cfg['rules'] as $x) if (in_array('weight', $x['fields'], true)) $r = $x;
    check('outputs: a rule without a percentile carries no percentileDecimals (its page is as before)',
        $r && !array_key_exists('rangePercentileDecimals', $r));

    // ---- 15) review fixes: one value once, keys that shape nothing, hints, a catalog that cannot be read -----------------
    foreach ([['"valueAtZ:2":"[a]","valueAtZ:2.0":"[b]"', '"return" "valueAtZ:2" and "valueAtZ:2.0" are the same value'],
              ['"valueAtZ:0":"[a]","valueAtZ:-0":"[b]"', 'are the same value'],
              ['"valueAtPercentile:50":"[a]","valueAtPercentile:50.00":"[b]"', 'are the same value']] as $pair) {
        $p = AnnotationRules::parseAllTags(tag('"return":{' . $pair[0] . '}'));
        check('review: refused ' . $pair[0] . ' (got ' . json_encode(isset($p[0]['error']) ? $p[0]['error'] : null) . ')',
            isset($p[0]['error']) && strpos($p[0]['error'], $pair[1]) !== false);
    }
    $p = AnnotationRules::parseAllTags(tag('"return":{"valueAtZ:2":"[a]","valueAtZ:2.5":"[b]","valueAtZ:-2":"[c]"}'));
    check('review: different values at a z-score are kept', !isset($p[0]['error']) && count($p[0]['rangeReturn']) === 3);
    $sixteen = [];
    for ($i = 0; $i < 16; $i++) $sixteen[] = '"valueAtZ:' . sprintf('%.1f', ($i - 8) / 2) . '":"[f' . $i . ']"';
    $p = AnnotationRules::parseAllTags(tag('"return":{' . implode(',', $sixteen) . '}'));
    check('review: 16 values are the most, and are taken (got ' . json_encode(isset($p[0]['error']) ? $p[0]['error'] : null) . ')',
        !isset($p[0]['error']) && count($p[0]['rangeReturn']) === 16);
    $free = '@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"}';
    foreach ([[$free . ',"return":{"z":"[a]"},"decimals":3}',
               '"decimals" sets the decimals of "lower", "upper", "range", "valueAtZ" and "valueAtPercentile", and "return" has none of them.'],
              [$free . ',"return":{"percentile":"[a]"},"decimals":2}', '"decimals" sets the decimals of'],
              [$free . ',"return":{"percentile":"[a]"},"returnLimits":"hard"}',
               '"returnLimits" picks the limits "lower", "upper" and "range" are made from, and "return" has none of them.'],
              [$free . ',"return":{"z":"[a]"},"percentileDecimals":2}', '"percentileDecimals" sets the decimals of "percentile", and "return" has none.'],
              [tag('"return":{"z":"[a]"},"returnLimits":"soft"'), '"returnLimits" picks the limits']] as $pair) {
        $p = AnnotationRules::parseAllTags($pair[0]);
        check('review: a key that shapes nothing returned is refused: ' . substr($pair[0], strpos($pair[0], '"return"')) . ' (got '
            . json_encode(isset($p[0]['error']) ? $p[0]['error'] : null) . ')', isset($p[0]['error']) && strpos($p[0]['error'], $pair[1]) !== false);
    }
    foreach ([tag('"return":{"range":"[a]"},"returnLimits":"hard","decimals":0'), tag('"return":{"lower":"[a]"},"decimals":2'),
              $free . ',"return":{"valueAtZ:-2":"[a]"},"decimals":2}', $free . ',"return":{"percentile":"[a]","z":"[b]"},"percentileDecimals":0}'] as $ok) {
        $p = AnnotationRules::parseAllTags($ok);
        check('review: a key that shapes a returned value is kept: ' . substr($ok, strpos($ok, '"return"')) . ' (got '
            . json_encode(isset($p[0]['error']) ? $p[0]['error'] : null) . ')', !isset($p[0]['error']));
    }
    $p = AnnotationRules::parseAllTags('@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"hard":[-5,5],"return":{"upper":"[a]"}}');
    check('review: the limit message names the key written (got ' . json_encode(isset($p[0]['error']) ? $p[0]['error'] : null) . ')',
        isset($p[0]['error']) && strpos($p[0]['error'], '"return" "upper" is made from the high "soft" limit') !== false);
    // a place count the key cannot reach is not offered
    $m = mod($newDict(['wfa_pct' => f('anthro_form', '', 'number_4dp')]), ['2' => [EVT => row()]]);
    $cfg = page($m);
    $err = '';
    foreach ($cfg['rules'] as $x) if (in_array('weight', $x['fields'], true) && !empty($x['configError'])) $err = $x['configError'];
    check('review: a percentile field with four places is told to drop the count, not to set percentileDecimals to 4 (got ' . json_encode($err) . ')',
        strpos($err, 'takes exactly 4 decimal places') !== false && strpos($err, 'percentileDecimals" to 4') === false
        && strpos($err, 'without a place count.') !== false);
    refused($newDict(['wfa_pct' => f('anthro_form', '', 'number_3dp')]), 'or set "percentileDecimals" to 3',
        'review: a place count the key can reach is still offered');
    // the catalog cannot be read: the rules that return values are refused, every other rule still runs
    $mixed = $newDict(['other' => f('anthro_form', '@UVRANGE={"soft":[1,2],"hard":[0,5]}', 'number_1dp')]);
    GrowthOutputs::useFile(__DIR__ . '/no_such_growth_outputs.json');
    $m = mod($mixed, ['2' => [EVT => row(['other' => '9'])]]);
    $cfg = null;
    $threw = null;
    try { $cfg = page($m); } catch (\Throwable $t) { $threw = $t->getMessage(); }
    $wErr = null;
    $other = null;
    foreach (($cfg && isset($cfg['rules'])) ? $cfg['rules'] : [] as $x) {
        if (in_array('weight', $x['fields'], true)) $wErr = isset($x['configError']) ? $x['configError'] : '';
        if (in_array('other', $x['fields'], true)) $other = $x;
    }
    check('review: without the catalog the page still opens (got ' . json_encode($threw) . ')', $threw === null && is_array($cfg));
    check('review: ... the rule that returns values says why (got ' . json_encode($wErr) . ')',
        is_string($wErr) && strpos($wErr, '"return" cannot be checked: growth output catalog no_such_growth_outputs.json could not be read') !== false);
    check('review: ... and another rule on the form is still given to the page', $other !== null && empty($other['configError']));
    $threw = null;
    try { save($m); } catch (\Throwable $t) { $threw = $t->getMessage(); }
    check('review: ... the save runs (got ' . json_encode($threw) . '), writes nothing and logs no audit error',
        $threw === null && !\REDCap::$saves && !logs($m, 'uvalidate-audit-error'));
    check('review: ... and the other rule is still audited', (bool) array_filter($m->logCalls, function ($c) {
        return isset($c[1]['field']) && $c[1]['field'] === 'other';
    }));
    // a rule parsed while the catalog was readable keeps its fields when it no longer is
    GrowthOutputs::useFile(null);
    $m = mod($newDict(), ['2' => [EVT => row()]]);
    $all = $rules->invoke($m, PID);
    $rule = null;
    foreach ($all as $x) if (in_array('weight', $x['fields'], true)) $rule = $x;
    GrowthOutputs::useFile(__DIR__ . '/no_such_growth_outputs.json');
    $rr = new \ReflectionMethod($m, 'returnsRange');
    $rr->setAccessible(true);
    $got = $rr->invoke($m, $rule, row(), [], null, PID, [], []);
    check('review: returnsRange with no catalog leaves the fields as they are (got ' . json_encode($got) . ')',
        is_array($got) && $got['values'] === null && strpos((string) $got['problem'], 'cannot be checked') !== false);
    GrowthOutputs::useFile(null);

    // ---- 20) how the child was measured: the z-score of the corrected length (RNG-3) ----------------------------
    // The review's girl: born 10 Jan 2025, measured standing on 2 Sep 2026 (600
    // days) at 73.0 cm. The z-score and the percentile are those of 73.7 cm
    // (WHO Anthro's -2.91); the bounds are the reference's at her age, whatever
    // the position.
    $LHD = dict(['len' => f('anthro_form', '@UVRANGE={"reference":"who-lhfa","sex":"[sex]","male":"1","female":"2",'
            . '"age":{"dob":"[dob]","at":"[visit_date]"},"measured":{"field":"[how]","lying":"1","standing":"2"},'
            . '"soft":[-2,2],"hard":[-6,6],"return":{"z":"[len_z]","lower":"[len_lo]","upper":"[len_hi]","percentile":"[len_pct]"}}', 'number_1dp'),
        'how' => f('anthro_form', '', '', 'radio', '1, Lying | 2, Standing'),
        'len_z' => f('anthro_form'), 'len_lo' => f('anthro_form', '', 'number_1dp'), 'len_hi' => f('anthro_form', '', 'number_1dp'),
        'len_pct' => f('anthro_form', '', 'number_1dp')], '');
    $lhfa = GrowthReference::entry('who-lhfa', null);
    $lhT = GrowthReference::table($lhfa);
    $lhWant = function ($y) use ($lhfa, $lhT) {
        $o = GrowthReference::outputs($lhfa, $lhT, 'female', 600.0, $y, ['lo' => '-2', 'hi' => '2'], 1, false);
        return ['len_z' => (string) $o['z'], 'len_lo' => (string) $o['lower'], 'len_hi' => (string) $o['upper'],
                'len_pct' => (string) GrowthReference::output($lhfa, $lhT, 'female', 600.0, $y, 'percentile', null, 1, null)];
    };
    $girl = row(['sex' => '2', 'visit_date' => '2026-09-02', 'weight' => '', 'len' => '73.0', 'how' => '2']);
    $m = mod($LHD, ['2' => [EVT => $girl]]);
    save($m);
    $got = pick(store(), ['len_z', 'len_lo', 'len_hi', 'len_pct']);
    check('measured: standing, the returned z-score and percentile are those of 73.7 cm (got ' . json_encode($got) . ')',
        $got === $lhWant(73.0 + 0.7) && $got['len_z'] === '-2.91');
    $m = mod($LHD, ['2' => [EVT => ['how' => '1'] + $girl]]);
    save($m);
    $lying = pick(store(), ['len_z', 'len_lo', 'len_hi', 'len_pct']);
    check('measured: lying under 731 days, those of 73.0 cm as typed (got ' . json_encode($lying) . ')',
        $lying === $lhWant(73.0) && $lying['len_z'] === '-3.14');
    check('measured: the bounds do not move with the position', $lying['len_lo'] === $got['len_lo'] && $lying['len_hi'] === $got['len_hi']);

    echo "growth_return_module_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
