<?php
/**
 * justify_live_twin_php.php - run the REAL module over the @UVJUSTIFY live
 * fixture (docs/testbed/uvjustify_test_fields.csv) on a copy of pid 149's
 * shape, so every server-side "Expect" of
 * docs/testbed/JUSTIFY_LIVE_ACCEPTANCE.md is the module's own answer.
 *
 * REDCap's data and log are kept as the sheet leaves them: each save the
 * sheet makes (a form save, a survey page or the Data Import Tool) changes
 * the record and adds the row REDCap writes, with the user and the time, so
 * the write-once lock of section F reads its history through ValueHistory as
 * on the server. The project has the Missing Data Code UNK, as Setup 4 asks.
 *
 * It checks the configuration errors word for word, the justify rules each
 * page carries (and that no page carries the server's justifyBy), every log
 * entry of each save in the sheet's order (uvalidate-justified with
 * justified_by for a finding whose reason is given, invalid-id-saved for an
 * open one, and never the reason itself), the answers the lookup and the
 * duplicate check give the page, the legacy scan's rows, column, sentence
 * and counts, and the durable scan's rows, counts and outcome, on pid 149
 * and on the scratch project of section H. The page configs it builds are
 * the input of tests/justify_live_twin_js.cjs, which replays the sheet's
 * clicks and typing through js/engine.js; they are kept in
 * tests/justify_live_pages.json, and this suite fails when that file is not
 * what the module builds now.
 *
 * RUN:    php tests/justify_live_twin_php.php && node tests/justify_live_twin_js.cjs
 * WRITE:  php tests/justify_live_twin_php.php --write   (after a change to the pages)
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $logCalls = []; public $projectSettings = [];
        public function getSubSettings($k, $pid = null) { return []; }
        public function getProjectSetting($k, $pid = null) { return $this->projectSettings[$k] ?? null; }
        public function getSystemSetting($k) { return $k === 'log-hmac-key' ? str_repeat('a', 64) : null; }
        public function setSystemSetting($k, $v) {}
        public function getProjectId() { return \Project::$pid; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return count($this->logCalls); }
        public function initializeJavascriptModuleObject() { return '<script></script>'; }
        public function getJavascriptModuleObjectName() { return 'EM.T.UV'; }
        public function getUser() { return new TestUser('tester'); }
    }
    class TestUser {
        private $n; public function __construct($n) { $this->n = $n; }
        public function getUsername() { return $this->n; }
        public function hasDesignRights() { return true; }
        public function getRights($pid = null) { return \REDCap::$rights['tester'] ?? null; }
    }
}

namespace {
    class REDCap {
        public static $data = []; public static $dictionary = []; public static $rights = []; public static $mappings = null;
        public static function getInstrumentEventMappings($pid = null) { return self::$mappings; }
        public static function getData($p) {
            $d = self::$data;
            if (isset($p['records'])) {
                // REDCap matches record names without regard to letter case
                $want = array_map('strtolower', array_map('strval', (array) $p['records']));
                $d = array_filter($d, function ($k) use ($want) { return in_array(strtolower((string) $k), $want, true); }, ARRAY_FILTER_USE_KEY);
            }
            return $d;
        }
        public static function getDataDictionary($pid, $f = 'array') { return self::$dictionary; }
        public static function getUserRights($pid = null, $user = null) { return self::$rights; }
        public static function getGroupNames($u = false, $g = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
    }
    /**
     * pid 149, arm 1: event ids as on chpr-redcap.org; uv_justify_test at
     * visit_1_arm_1. Project::$scratch switches to the classic scratch project
     * of section H (one event, nothing repeats).
     */
    class Project {
        public static $pid = 149;
        public static $scratch = false;
        public $project = ['missing_data_codes' => "UNK, Unknown"];
        public $longitudinal = true;
        public $eventInfo = [
            351 => ['unique_event_name' => 'event_1_arm_1', 'arm_num' => 1],
            379 => ['unique_event_name' => 'baseline_arm_1', 'arm_num' => 1],
            380 => ['unique_event_name' => 'visit_1_arm_1', 'arm_num' => 1],
            381 => ['unique_event_name' => 'visit_2_arm_1', 'arm_num' => 1],
            382 => ['unique_event_name' => 'unscheduled_arm_1', 'arm_num' => 1],
            383 => ['unique_event_name' => 'followup_arm_1', 'arm_num' => 1],
        ];
        public $eventsForms = [
            351 => ['xe_enrol'],
            379 => ['xe_visit', 'xr_specimen'],
            380 => ['xe_visit', 'xr_specimen', 'xr_result', 'uv_justify_test'],
            381 => ['xe_visit', 'xr_result'],
            382 => ['xe_unsched'],
            383 => ['xe_visit', 'xe_close'],
        ];
        public function __construct($pid) {
            if (self::$scratch) {
                $this->project = ['missing_data_codes' => ''];
                $this->longitudinal = false;
                $this->eventInfo = [900 => ['unique_event_name' => 'event_1_arm_1', 'arm_num' => 1]];
                $this->eventsForms = [900 => ['my_first_instrument', 'uv_justify_test']];
            }
        }
        public function getRepeatingFormsEvents() {
            if (self::$scratch) return [];
            return [379 => ['xr_specimen' => ''], 380 => ['xr_specimen' => '', 'xr_result' => ''], 381 => ['xr_result' => ''], 382 => 'WHOLE'];
        }
        public function getUniqueEventNames() { return array_map(function ($r) { return $r['unique_event_name']; }, $this->eventInfo); }
    }

    require_once __DIR__ . '/../UniversalValidator.php';
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';
    require_once __DIR__ . '/writeonce_fake_log.php';

    use INSPIRE\UniversalValidator\ValueHistory;
    use INSPIRE\UniversalValidator\ScanColumns;
    use INSPIRE\UniversalValidator\ScanPageView;
    use INSPIRE\UniversalValidator\Scan\RollupBuilder;
    use INSPIRE\UniversalValidator\Scan\ScanPromotion;
    use INSPIRE\UniversalValidator\Scan\ScanOutcome;

    $fail = 0; $n = 0;
    function check($label, $ok, $got = null) {
        global $fail, $n; $n++;
        if (!$ok) { $fail++; fwrite(STDERR, "FAIL: $label" . ($got !== null ? ' (got ' . json_encode($got, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ')' : '') . "\n"); }
    }

    // ---- the dictionary: the fixture plus the pid-149 fields it names ---------------------------
    $BASE = [
        'record_id' => ['form_name' => 'xe_enrol', 'field_type' => 'text'],
        'xv_weight' => ['form_name' => 'xe_visit', 'field_type' => 'text', 'text_validation_type_or_show_slider_number' => 'number'],
    ];
    $FIX = []; $LABELS = []; $TYPES = []; $SECTIONS = [];
    $fh = fopen(__DIR__ . '/../docs/testbed/uvjustify_test_fields.csv', 'r');
    $head = fgetcsv($fh, 0, ',', '"', '');
    $read = 0; $section = '';
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $r = array_combine($head, $r);
        $read++;
        $f = $r['Variable / Field Name'];
        $FIX[$f] = ['form_name' => $r['Form Name'], 'field_type' => $r['Field Type'],
            'field_annotation' => $r['Field Annotation'], 'select_choices_or_calculations' => $r['Choices, Calculations, OR Slider Labels'],
            'text_validation_type_or_show_slider_number' => $r['Text Validation Type OR Show Slider Number'],
            'branching_logic' => $r['Branching Logic (Show field only if...)']];
        $LABELS[$f] = $r['Field Label'];
        if ($r['Section Header'] !== '') $section = $r['Section Header'];
        $SECTIONS[$f] = $section;
        $TYPES[$f] = ['type' => $r['Field Type'], 'validation' => $r['Text Validation Type OR Show Slider Number'],
            'choices' => $r['Choices, Calculations, OR Slider Labels'], 'branching' => $r['Branching Logic (Show field only if...)']];
    }
    fclose($fh);
    check('setup: the fixture holds 44 rows', $read === 44, $read);
    check('setup: the fixture adds one instrument only', count(array_unique(array_map(function ($m) { return $m['form_name']; }, $FIX))) === 1);
    check('setup: the fixture holds no SQL field (only an administrator can add one, J4)', !array_filter($FIX, function ($m) { return $m['field_type'] === 'sql'; }));
    $pad = function (array $d) {
        foreach ($d as &$m) $m += ['field_annotation' => '', 'select_choices_or_calculations' => '', 'text_validation_type_or_show_slider_number' => '', 'branching_logic' => ''];
        unset($m);
        return $d;
    };
    $DICT = $pad($BASE + $FIX);
    $FORM = 'uv_justify_test';
    $WO = ['uj_lock'];

    // ---- REDCap's log: one row per save the sheet makes ----------------------------------------
    $LOG = [];
    function logSave($at, array $values, $record, $user = 'tester', $event = 380, $kind = 'UPDATE') {
        global $LOG;
        $lines = [];
        foreach ($values as $f => $v) $lines[] = $f . " = '" . str_replace("'", "\\'", (string) $v) . "'";
        $LOG[] = [count($LOG) + 1, 149, $record, $event, $at, implode(",\n", $lines), 'redcap_data', $kind, $user];
    }
    function setPrivate($m, $prop, $value) {
        $p = new \ReflectionProperty(get_class($m), $prop);
        $p->setAccessible(true);
        $p->setValue($m, $value);
    }
    /** The module as one request sees it, over the data $D and the log so far. */
    function mod(array $data, ?array $dict = null) {
        global $DICT, $LOG, $WO;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectSettings = ['log-values' => 'raw'];
        \REDCap::$dictionary = $dict ?? $DICT;
        \REDCap::$data = $data;
        $forms = \Project::$scratch ? ['my_first_instrument', 'uv_justify_test'] : ['xe_enrol', 'xe_visit', 'xr_specimen', 'xr_result', 'xe_unsched', 'xe_close', 'uv_justify_test'];
        \REDCap::$rights = ['tester' => ['forms' => array_fill_keys($forms, '1')]];
        $map = [];
        foreach ((new \Project(\Project::$pid))->eventsForms as $e => $fs) foreach ($fs as $f) $map[] = ['event_id' => $e, 'form' => $f];
        \REDCap::$mappings = $map;
        if (!\Project::$scratch) {
            $db = new FakeHistoryLog();
            $db->rows = $LOG;
            $utc = new \DateTimeZone('UTC');
            $vo = new \ReflectionMethod(get_class($m), 'valueHistoryOptions');
            $vo->setAccessible(true);
            setPrivate($m, 'valueHistoryOverride', ValueHistory::forProject($db, 149, $WO, $utc, $utc, $vo->invoke($m, 149)));
            setPrivate($m, 'roleBookOverride', ['roles' => [], 'users' => []]);
            setPrivate($m, 'clockOverride', \INSPIRE\UniversalValidator\UniversalValidator::clockStamp(new \DateTimeImmutable('2026-10-26 12:00:00', $utc)));
        }
        return $m;
    }
    function configOf($html) {
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return json_decode($mm[1] ?? 'null', true);
    }
    function page($m, $record, $event = 380) {
        ob_start();
        $m->redcap_data_entry_form_top(\Project::$pid, $record, 'uv_justify_test', $event, null, 1);
        $html = ob_get_clean();
        return ['html' => $html, 'cfg' => configOf($html)];
    }
    function surveyPage($m, $record) {
        ob_start();
        $m->redcap_survey_page_top(149, $record, 'uv_justify_test', 380, null, 'hash', 77, 1);
        $html = ob_get_clean();
        return ['html' => $html, 'cfg' => configOf($html)];
    }
    function rulesOn($p, $f, $type = null) {
        $out = [];
        foreach ($p['cfg']['rules'] ?? [] as $r) {
            if (!in_array($f, $r['fields'] ?? [], true)) continue;
            if ($type !== null && ($r['type'] ?? '') !== $type) continue;
            $out[] = $r;
        }
        return $out;
    }
    function errOf($p, $f) {
        foreach (rulesOn($p, $f) as $r) if (!empty($r['configError'])) return (string) $r['configError'];
        return '';
    }
    /**
     * The log entries one save wrote for this instrument's fields, as
     * "message field type:reason [> justified_by]", sorted.
     */
    function entries($m) {
        $out = [];
        foreach ($m->logCalls as $c) {
            if (!in_array($c[0], ['invalid-id-saved', 'uvalidate-justified'], true)) continue;
            if (strpos((string) ($c[1]['field'] ?? ''), 'uj_') !== 0) continue;
            $out[] = $c[0] . ' ' . $c[1]['field'] . ' ' . $c[1]['type'] . ':' . $c[1]['reason'] . (isset($c[1]['justified_by']) ? ' > ' . $c[1]['justified_by'] : '');
        }
        sort($out);
        return $out;
    }
    /** The rule problems a save logged for the good fields (the uj_bad_* and uj_rr ones are configuration errors). */
    function problems($m) {
        $out = [];
        foreach ($m->logCalls as $c) if ($c[0] === 'uvalidate-unconfigurable' && !preg_match('/uj_bad_|uj_rr/', (string) $c[1]['fields'])) $out[] = $c[1];
        return $out;
    }
    /** Whether any log entry of the save holds $text: a reason is never logged. */
    function logHolds($m, $text) { return strpos(json_encode($m->logCalls, JSON_UNESCAPED_UNICODE), $text) !== false; }

    $D = [];
    foreach (['XE-1', 'XE-2', 'XE-3', 'XE-4'] as $rec) {
        $D[$rec] = [351 => ['record_id' => $rec], 380 => []];
        // the seed import made the records: REDCap logs each as an INSERT with its record id line
        logSave('20261020090000', ['record_id' => $rec], $rec, 'tester', 351, 'INSERT');
    }
    $pages = ['values' => [], 'ajax' => []];
    /**
     * The form for $rec as the sheet opens it at $step. The config goes to
     * the JS replay once per $cfgKey (a later step that opens the same page
     * must get the same config), the values the page opens with per step.
     */
    $open = function ($step, $cfgKey, $rec = 'XE-1') use (&$pages, &$D, $FIX) {
        $p = page(mod($D), $rec);
        if (!isset($pages[$cfgKey])) $pages[$cfgKey] = $p['cfg'];
        else check("$step: the page is the one the JS replay boots as \"$cfgKey\"", $p['cfg'] === $pages[$cfgKey]);
        $vals = [];
        foreach ($D[$rec][380] ?? [] as $f => $v) if (isset($FIX[$f]) && (string) $v !== '') $vals[$f] = (string) $v;
        ksort($vals);
        $pages['values'][$step] = (object) $vals;
        return $p;
    };
    /** A save of the form: the values change, REDCap logs the row, the hook runs. */
    $save = function ($rec, array $changes, $at) use (&$D) {
        foreach ($changes as $f => $v) $D[$rec][380][$f] = $v;
        if ($changes) logSave($at, $changes + ['uv_justify_test_complete' => '0'], $rec);
        $m = mod($D);
        $m->redcap_save_record(149, $rec, 'uv_justify_test', 380, null, null, null, 1);
        return $m;
    };
    /** A survey page's Next or Submit: the same, as the survey respondent, with the survey hash. */
    $surveySave = function ($rec, array $changes, $at) use (&$D) {
        foreach ($changes as $f => $v) $D[$rec][380][$f] = $v;
        if ($changes) logSave($at, $changes, $rec, '[survey respondent]');
        $m = mod($D);
        $m->redcap_save_record(149, $rec, 'uv_justify_test', 380, null, 'hash', 77, 1);
        return $m;
    };
    /** The Data Import Tool: the values change and REDCap logs the row; no module hook runs. */
    $import = function ($rec, array $changes, $at) use (&$D) {
        foreach ($changes as $f => $v) $D[$rec][380][$f] = $v;
        logSave($at, $changes, $rec);
    };
    /** What the lookup or the duplicate check answers the page, kept for the JS replay. */
    $ask = function ($step, $action, $field, $value, $rec) use (&$D, &$pages) {
        $r = mod($D)->redcap_module_ajax($action, ['field' => $field, 'values' => [$field => $value], 'cond' => []], 149, $rec, 'uv_justify_test', 380, 1,
            null, null, null, '', '', 'tester', null);
        $pages['ajax'][$rec . ' ' . $action . ' ' . $field . ' ' . $value] = $r;
        return $r;
    };

    // ---- Section A: install, and the configuration errors -----------------------------------------
    $p = $open('A1', 'form');
    $ERR = [
        'uj_rr' => '@UVJUSTIFY on "uj_rr": "reason" names "[uj_sql_why]", which is not a field of this project.',
        'uj_bad_bare' => '@UVJUSTIFY on "uj_bad_bare": @UVJUSTIFY needs the field that holds the reason, e.g. @UVJUSTIFY={"reason":"[weight_reason]"}.',
        'uj_bad_form' => '@UVJUSTIFY on "uj_bad_form": "reason" names "[record_id]", which is on the form "xe_enrol", not on "uv_justify_test" with this field — the reason is given on the form where the value is entered.',
        'uj_bad_type' => '@UVJUSTIFY on "uj_bad_type": "reason" names "[uj_signs]", a "checkbox" field — the reason is written in a Text or Notes field, or picked in a dropdown, radio or SQL field.',
        'uj_bad_self' => '@UVJUSTIFY on "uj_bad_self": "reason" names this field itself — name the field where the reason is written, e.g. "[uj_bad_self_reason]".',
        'uj_bad_none' => '@UVJUSTIFY on "uj_bad_none": no rule a reason can stand for checks this field — give it a rule of @UVALIDATE, @UVASSERT, @UVREQUIRED, @UVCHOICES, @UVWINDOW, @UVEXISTS, @UVRANGE or @UVDELTA (a reason does not stand for @UVUNIQUE or @UVWRITEONCE).',
        'uj_bad_twice' => 'field "uj_bad_twice" is given @UVJUSTIFY 2 times — this tag takes no "when" condition, so keep one.',
    ];
    foreach ($ERR as $f => $text) check("A1: $f refused word for word", errOf($p, $f) === $text, errOf($p, $f));
    $others = [];
    foreach (array_keys($FIX) as $f) if (!isset($ERR[$f]) && errOf($p, $f) !== '') $others[$f] = errOf($p, $f);
    check('A1: no other field of the form shows an error', $others === [], $others);
    // a refused @UVJUSTIFY leaves the field's other rules running
    foreach (['uj_rr', 'uj_bad_bare', 'uj_bad_form', 'uj_bad_type', 'uj_bad_self', 'uj_bad_twice'] as $f) {
        $live = array_filter(rulesOn($p, $f, 'range'), function ($r) { return empty($r['configError']); });
        check("A1: $f keeps its live @UVRANGE", (bool) $live, rulesOn($p, $f));
    }
    $pages['errors'] = [];
    foreach (array_keys($ERR) as $f) $pages['errors'][$f] = errOf($p, $f);
    $REASON = ['uj_req' => 'uj_req_why', 'uj_wt' => 'uj_wt_why', 'uj_id' => 'uj_id_why', 'uj_pool' => 'uj_pool_why', 'uj_dia' => 'uj_dia_why',
        'uj_dose' => 'uj_dose_why', 'uj_visit' => 'uj_visit_why', 'uj_wt2' => 'uj_wt2_why', 'uj_mother' => 'uj_mother_why', 'uj_temp' => 'uj_temp_why',
        'uj_off' => 'uj_off_why', 'uj_hr' => 'uj_hr_why', 'uj_ro' => 'uj_ro_why', 'uj_code' => 'uj_code_why', 'uj_lock' => 'uj_lock_why', 'uj_far' => 'uj_far_why'];
    foreach ($REASON as $f => $why) {
        $j = rulesOn($p, $f, 'justify');
        check("A2: $f carries its justify rule, naming $why", count($j) === 1 && ($j[0]['justifyReason'] ?? '') === $why && empty($j[0]['configError']), $j);
    }
    $vj = rulesOn($p, 'uj_visit', 'justify')[0] ?? [];
    check('A2: uj_visit\'s justify rule carries its own message and the French one', ($vj['message'] ?? '') === 'Say why this visit date is right.'
        && ($vj['i18n']['message']['fr'] ?? '') === 'Dites pourquoi cette date de visite est juste.', $vj);
    check('A2: no rule on the page carries the server\'s justifyBy', strpos($p['html'], 'justifyBy') === false);
    check('A2: the page knows the Missing Data Code UNK', ($p['cfg']['missingCodes'] ?? null) === ['UNK'], $p['cfg']['missingCodes'] ?? null);
    $modes = [];
    foreach ($REASON as $f => $why) foreach (rulesOn($p, $f) as $r) if (($r['type'] ?? '') !== 'justify' && empty($r['configError'])) $modes[$f][] = $r['type'];
    check('A2: one field per mode a reason stands for, plus the duplicate and the lock (which come in F)', $modes === [
        'uj_req' => ['required'], 'uj_wt' => ['range'], 'uj_id' => ['single'], 'uj_pool' => ['pooled'], 'uj_dia' => ['constraint'], 'uj_dose' => ['choices'],
        'uj_visit' => ['window'], 'uj_wt2' => ['delta'], 'uj_mother' => ['exists'], 'uj_temp' => ['range'], 'uj_off' => ['constraint'], 'uj_hr' => ['range'],
        'uj_ro' => ['range'], 'uj_code' => ['single', 'unique'], 'uj_lock' => ['range'], 'uj_far' => ['range']], $modes);

    // ---- Section B: each rule a reason stands for, on XE-1 ------------------------------------------
    // The log entries of each save. Every save audits every rule of the form,
    // so a finding saved with its reason is logged again by each later save.
    $LIVE = [];
    $J = function ($f, $what) use (&$REASON) { return "uvalidate-justified $f $what > " . $REASON[$f]; };
    $O = function ($f, $what) { return "invalid-id-saved $f $what"; };
    $step = function ($label, $m, array $add = [], array $drop = []) use (&$LIVE) {
        foreach ($drop as $e) $LIVE = array_values(array_diff($LIVE, [$e]));
        foreach ($add as $e) $LIVE[] = $e;
        sort($LIVE);
        check("$label: every log entry of the save", entries($m) === $LIVE, entries($m));
        check("$label: no rule problem for a field that is set up right", problems($m) === [], problems($m));
    };
    $quiet = function ($label, $m, array $texts) {
        foreach ($texts as $t) check("$label: the reason \"$t\" is in no log entry", !logHolds($m, $t));
    };
    $m = $save('XE-1', ['uj_req_why' => '1'], '20261026090000');
    $step('B1b', $m, [$J('uj_req', 'required:required-blank')]);
    $named = [];
    foreach ($m->logCalls as $c) if ($c[0] === 'uvalidate-unconfigurable') foreach (explode(', ', (string) $c[1]['fields']) as $f) $named[] = $f;
    check('B1b: the save writes no rule-problem entry (uvalidate-unconfigurable), not even for the refused tags of A1', $named === [], $named);
    $open('B2', 'form');
    $m = $save('XE-1', ['uj_wt' => '300.0', 'uj_wt_why' => 'Scale checked twice'], '20261026090500');
    $step('B2b', $m, [$J('uj_wt', 'range:hard-high')]);
    $quiet('B2b', $m, ['Scale checked twice']);
    $open('B3', 'form');
    $e = array_values(array_filter($m->logCalls, function ($c) { return $c[0] === 'uvalidate-justified' && $c[1]['field'] === 'uj_wt'; }))[0][1] ?? [];
    check('B2b: the uj_wt entry in full: the value, the record, event and instance, and the reason field\'s name', $e === [
        'field' => 'uj_wt', 'type' => 'range', 'algorithm' => 'range', 'reason' => 'hard-high', 'instrument' => 'uv_justify_test', 'event_id' => '380',
        'instance' => '1', 'justified_by' => 'uj_wt_why', 'record' => 'XE-1', 'value' => '300.0'], $e);
    $m = $save('XE-1', ['uj_id' => '79927398710', 'uj_id_why' => 'Number printed on the tube'], '20261026091000');
    $step('B3b', $m, [$J('uj_id', 'single:check-character')]);
    $quiet('B3b', $m, ['Number printed on the tube']);
    $open('B4', 'form');
    $m = $save('XE-1', ['uj_pool' => '79927398713 79927398710', 'uj_pool_why' => "Second tube relabelled\nat the lab"], '20261026091500');
    $step('B4b', $m, [$J('uj_pool', 'pooled:check-character')]);
    $quiet('B4b', $m, ['Second tube relabelled']);
    $open('B5', 'form');
    $m = $save('XE-1', ['uj_sys' => '80', 'uj_dia' => '90', 'uj_dia_why' => '1'], '20261026092000');
    $step('B5b', $m, [$J('uj_dia', 'constraint:assert:[uj_dia]<[uj_sys]')]);
    $open('B6', 'form');
    $m = $save('XE-1', ['uj_arm' => '2', 'uj_dose' => '1', 'uj_dose_why' => 'Dose by weight'], '20261026092500');
    $step('B6b', $m, [$J('uj_dose', 'choices:hidden-choice')]);
    $quiet('B6b', $m, ['Dose by weight']);
    $open('B7', 'form');
    $m = $save('XE-1', ['uj_anchor' => '2026-10-01', 'uj_visit' => '2026-10-20', 'uj_visit_why' => 'Clinic closed for a week'], '20261026093000');
    $step('B7b', $m, [$J('uj_visit', 'window:window-late')]);
    $quiet('B7b', $m, ['Clinic closed for a week']);
    $open('B8', 'form');
    $m = $save('XE-1', ['uj_wt2' => '302.5', 'uj_wt2_why' => '1'], '20261026093500');
    $step('B8b', $m, [$J('uj_wt2', 'delta:up')]);
    $open('B9', 'form');
    // B9: the lookup the page makes for XE-9, and for XE-2 (a saved record)
    check('B9: the lookup answers "not found" for XE-9, and names no record', $ask('B9', 'exists-check', 'uj_mother', 'XE-9', 'XE-1') === ['state' => 'not-found', 'record' => null]);
    check('B9: ... and "found" for XE-2', $ask('B9', 'exists-check', 'uj_mother', 'XE-2', 'XE-1') === ['state' => 'found', 'record' => null]);
    $m = $save('XE-1', ['uj_mother' => 'XE-9', 'uj_mother_why' => 'Mother enrolled at another site'], '20261026094000');
    $step('B9b', $m, [$J('uj_mother', 'exists:not-found')]);
    $quiet('B9b', $m, ['Mother enrolled at another site']);
    check('B9b: the save writes nine uvalidate-justified entries', count(array_filter(entries($m), function ($e) { return strpos($e, 'uvalidate-justified ') === 0; })) === 9, entries($m));
    $B9 = $LIVE;

    // ---- Section C: what is no reason ----------------------------------------------------------------
    $open('C1', 'form');
    // C1 to C4 change the page only and put each reason back; C4b saves what was there
    $m = $save('XE-1', [], '20261026094500');
    $step('C4b', $m);
    $open('C5', 'form');
    $m = $save('XE-1', ['uj_hr' => '250', 'uj_hr_show' => '1', 'uj_hr_why' => 'Athlete, resting rate checked'], '20261026095000');
    $step('C5b', $m, [$J('uj_hr', 'range:hard-high')]);
    $quiet('C5b', $m, ['Athlete, resting rate checked']);
    $open('C6', 'form');
    $m = $save('XE-1', [], '20261026095200');
    $step('C6b', $m);
    // C7: the device writes a weight into the read-only field (the Data Import Tool stands in for it)
    $import('XE-1', ['uj_ro' => '300.0'], '20261026095300');
    $open('C7', 'form');
    $m = $save('XE-1', [], '20261026095400');
    $step('C7', $m, [$O('uj_ro', 'range:hard-high')]);
    $m = $save('XE-1', ['uj_ro_why' => 'Device recalibrated'], '20261026095500');
    $step('C7b', $m, [$J('uj_ro', 'range:hard-high')], [$O('uj_ro', 'range:hard-high')]);
    $quiet('C7b', $m, ['Device recalibrated']);
    $open('D1', 'form');

    // ---- Section D: "Save anyway?", and a rule that never blocks --------------------------------------
    $m = $save('XE-1', ['uj_temp' => '43.0', 'uj_temp_why' => 'Fever confirmed with a second thermometer'], '20261026095700');
    $step('D2', $m, [$J('uj_temp', 'range:hard-high')]);
    $quiet('D2', $m, ['Fever confirmed with a second thermometer']);
    $open('D3', 'form');
    $m = $save('XE-1', ['uj_off' => '85'], '20261026100000');
    $step('D3', $m, [$O('uj_off', 'constraint:assert:[uj_off]>=90')]);
    $m = $save('XE-1', ['uj_off_why' => 'Known lung disease'], '20261026100500');
    $step('D4', $m, [$J('uj_off', 'constraint:assert:[uj_off]>=90')], [$O('uj_off', 'constraint:assert:[uj_off]>=90')]);
    $quiet('D4', $m, ['Known lung disease']);

    // ---- Section E: the Enter key -------------------------------------------------------------------
    $open('E1', 'form');
    $m = $save('XE-1', [], '20261026101000');
    $step('E2', $m);

    // ---- Section F: a reason does not release @UVUNIQUE or @UVWRITEONCE --------------------------------
    $open('F1', 'form');
    check('F1: the duplicate check finds UJ-1 free', $ask('F1', 'unique-check', 'uj_code', 'UJ-1', 'XE-1') === ['used' => false, 'record' => null]);
    $m = $save('XE-1', ['uj_code' => 'UJ-1', 'uj_code_why' => 'Code from the paper form'], '20261026101500');
    $step('F1b', $m, [$J('uj_code', 'single:format')]);
    $quiet('F1b', $m, ['Code from the paper form']);
    $open('F2', 'form');
    check('F2: the duplicate check finds UJ-001 free on XE-1', $ask('F2', 'unique-check', 'uj_code', 'UJ-001', 'XE-1') === ['used' => false, 'record' => null]);
    $m = $save('XE-1', ['uj_code' => 'UJ-001'], '20261026102000');
    $step('F2', $m, [], [$J('uj_code', 'single:format')]);
    $p = $open('F3', 'xe2', 'XE-2');
    check('F3: on XE-2 the duplicate check finds UJ-001 used, by XE-1 (staff see the record)', $ask('F3', 'unique-check', 'uj_code', 'UJ-001', 'XE-2') === ['used' => true, 'record' => 'XE-1']);
    $m = $save('XE-2', ['uj_req_why' => '1'], '20261026102500');
    check('F3c: XE-2 saves with its required reason only', entries($m) === [$J('uj_req', 'required:required-blank')], entries($m));
    $open('F4', 'form');
    $m = $save('XE-1', ['uj_lock' => '300.0', 'uj_lock_why' => 'Weighed in a cast'], '20261026103000');
    $step('F4b', $m, [$J('uj_lock', 'range:hard-high')]);
    $quiet('F4b', $m, ['Weighed in a cast']);
    $p = $open('F5', 'locked');
    check('F5: reopened, the enrolment weight is locked', (bool) rulesOn($p, 'uj_lock', 'writeonce'), rulesOn($p, 'uj_lock'));
    $m = $save('XE-1', ['uj_lock_why' => ''], '20261026103500');
    $step('F5b', $m, [$O('uj_lock', 'range:hard-high')], [$J('uj_lock', 'range:hard-high')]);
    $import('XE-1', ['uj_lock' => '310.0', 'uj_lock_why' => 'Weighed in a cast'], '20261026104000');
    $p = $open('F6', 'locked310');
    $m = $save('XE-1', [], '20261026104500');
    $step('F6', $m, [$O('uj_lock', 'writeonce:changed'), $J('uj_lock', 'range:hard-high')], [$O('uj_lock', 'range:hard-high')]);
    $wo = array_values(array_filter($m->logCalls, function ($c) { return $c[0] === 'invalid-id-saved' && ($c[1]['type'] ?? '') === 'writeonce'; }))[0][1] ?? [];
    check('F6: the write-once entry names when the lock was set and when the import changed it, and no reason field',
        ($wo['locked_at'] ?? '') === '2026-10-26 10:30' && ($wo['changed_at'] ?? '') === '2026-10-26 10:40' && !isset($wo['justified_by']), $wo);
    $F6 = $LIVE;

    // ---- Section G: a survey of five pages --------------------------------------------------------------
    $ps = surveyPage(mod($D), 'XE-4');
    $pages['survey'] = $ps['cfg'];
    $SURVEY = 'this check is not set up correctly; the study team can see the details on the data entry form.';
    foreach (array_keys($ERR) as $f) check("G1/G5: $f reads the survey's general wording", errOf($ps, $f) === $SURVEY, errOf($ps, $f));
    foreach ($REASON as $f => $why) {
        $j = rulesOn($ps, $f, 'justify');
        check("G1: the survey page pairs $f with $why too", count($j) === 1 && ($j[0]['justifyReason'] ?? '') === $why, $j);
    }
    check('G1: the survey page carries no justifyBy, and names no refused reason field', strpos($ps['html'], 'justifyBy') === false
        && strpos($ps['html'], 'uj_sql_why') === false && strpos($ps['html'], 'record_id]') === false);
    $pages['surveyErrors'] = [];
    foreach (array_keys($ERR) as $f) $pages['surveyErrors'][$f] = errOf($ps, $f);
    $m = $surveySave('XE-4', ['uj_req_why' => '2', 'uj_wt' => '300.0', 'uj_wt_why' => 'Scale checked twice', 'uv_justify_test_complete' => '0'], '20261026105000');
    $G = [$J('uj_req', 'required:required-blank'), $J('uj_wt', 'range:hard-high')];
    check('G1c: Next saves the first page: the two findings saved with their reasons', entries($m) === $G, entries($m));
    $quiet('G1c', $m, ['Scale checked twice']);
    $m = $surveySave('XE-4', [], '20261026105100');
    check('G2: Next on the second page logs the same two', entries($m) === $G, entries($m));
    $m = $surveySave('XE-4', ['uj_far' => '12'], '20261026105200');
    check('G4: Next on the third page, the hours fixed: the same two', entries($m) === $G, entries($m));
    $m = $surveySave('XE-4', [], '20261026105300');
    check('G4: Next on the fourth page: the same two', entries($m) === $G, entries($m));
    $m = $surveySave('XE-4', ['uv_justify_test_complete' => '2'], '20261026105400');
    check('G5: Submit: the same two', entries($m) === $G, entries($m));

    // ---- Section H: the scans --------------------------------------------------------------------------
    /** "record field type:reason open|justified" for each finding of the legacy scan on this instrument, with the stats. */
    $legacy = function (array $data, &$res = null, &$m = null) {
        $m = mod($data);
        $res = $m->scanProject(\Project::$pid);
        $out = [];
        foreach ($res['violations'] as $v) {
            if (strpos((string) $v['field'], 'uj_') !== 0) continue;
            $out[] = $v['record'] . ' ' . $v['field'] . ' ' . $v['type'] . ':' . $v['reason'] . ' '
                . (empty($v['justified']) ? 'open' : 'justified by ' . $v['justifiedBy']);
        }
        sort($out);
        return $out;
    };
    /** The durable scan of every record: its rows' justified flags, the tally, the rule problems and the outcome. */
    $durable = function (array $data) {
        $m = mod($data);
        $ctx = $m->durableScanContext(\Project::$pid, ['generation' => null]);
        $rows = [];
        $axes = new \ReflectionMethod(RollupBuilder::class, 'axes');
        $axes->setAccessible(true);
        $agg = [];
        $id = 0;
        foreach ($data as $rec => $node) {
            $dur = $m->durableEvaluateRecord($ctx['plan'], \Project::$pid, (string) $rec, $node, 1, str_repeat('k', 32), $ctx['plan']['ruleIds']);
            foreach ($dur['findings'] as $x) {
                $rows[] = $rec . ' ' . $x['field'] . ' ' . $x['check_type'] . ' ' . $x['justified'];
                foreach ($axes->invoke(null, [++$id, $x['host_form'], $x['reason_code'], $x['dag_key'], $x['check_type'], $x['justified']]) as $kind => $axis) {
                    $k = $kind . "\0" . $axis;
                    if (!isset($agg[$k])) $agg[$k] = ['kind' => $kind, 'axis1' => (string) $axis, 'cnt' => 0];
                    $agg[$k]['cnt']++;
                }
            }
        }
        sort($rows);
        $marks = ScanPromotion::marksFor($ctx['problems']);
        foreach ($marks as $mk) $agg[] = ['kind' => $mk['kind'], 'axis1' => $mk['axis1'], 'cnt' => 1];
        $t = ScanPromotion::tally(array_values($agg));
        $outcome = ScanOutcome::derive(['fenced' => true, 'manifestDone' => true, 'violations' => $t['violations'], 'ruleProblems' => $t['ruleProblems'], 'gaps' => 0]);
        $status = ['terminal' => $outcome['terminal'], 'clean' => $outcome['clean'] ? 1 : 0, 'ruleProblems' => $t['ruleProblems'],
            'findings' => $t['violations'], 'justified' => $t['justified']];
        return ['rows' => $rows, 'tally' => $t, 'outcome' => $outcome, 'found' => ScanPageView::foundText($status),
            'verdict' => ScanPageView::verdictText($status), 'coverage' => ScanPageView::coverageSentence($outcome['coverage'], $outcome['detail'])];
    };
    $JF = ['uj_dia', 'uj_dose', 'uj_hr', 'uj_id', 'uj_lock', 'uj_mother', 'uj_off', 'uj_pool', 'uj_req', 'uj_temp', 'uj_visit', 'uj_wt', 'uj_wt2'];
    $got = $legacy($D, $res, $lm);
    $want = [
        'XE-1 uj_dia constraint:assert:[uj_dia]<[uj_sys] justified by uj_dia_why', 'XE-1 uj_dose choices:hidden-choice justified by uj_dose_why',
        'XE-1 uj_hr range:hard-high justified by uj_hr_why', 'XE-1 uj_id single:check-character justified by uj_id_why',
        'XE-1 uj_lock range:hard-high justified by uj_lock_why', 'XE-1 uj_lock writeonce:changed open',
        'XE-1 uj_mother exists:not-found justified by uj_mother_why', 'XE-1 uj_off constraint:assert:[uj_off]>=90 justified by uj_off_why',
        'XE-1 uj_pool pooled:check-character justified by uj_pool_why', 'XE-1 uj_req required:required-blank justified by uj_req_why',
        'XE-1 uj_ro range:hard-high justified by uj_ro_why',
        'XE-1 uj_temp range:hard-high justified by uj_temp_why', 'XE-1 uj_visit window:window-late justified by uj_visit_why',
        'XE-1 uj_wt range:hard-high justified by uj_wt_why', 'XE-1 uj_wt2 delta:up justified by uj_wt2_why',
        'XE-2 uj_req required:required-blank justified by uj_req_why',
        'XE-4 uj_req required:required-blank justified by uj_req_why', 'XE-4 uj_wt range:hard-high justified by uj_wt_why',
    ];
    check('H1: the legacy scan rows: every finding saved with its reason marked, naming the reason field; only the lock open (XE-3, never opened, has no data on the form)', $got === $want, $got);
    check('H1: the legacy scan counts them apart: 17 saved with a reason, 1 open', ($res['stats']['justified'] ?? null) === 17 && ($res['stats']['violations'] ?? null) === 1, $res['stats']);
    check('H1: no reason text in any scan row', strpos(json_encode($res['violations'], JSON_UNESCAPED_UNICODE), 'Scale checked twice') === false
        && strpos(json_encode($res['violations'], JSON_UNESCAPED_UNICODE), 'Weighed in a cast') === false);
    $dims = $lm->scanDimensions(149, $res['rules'] ?? null);
    $cols = ScanColumns::all($dims);
    check('H1: the report shows the "Saved with a reason" column', in_array('justified', array_column($cols, 'key'), true)
        && array_values(array_filter($cols, function ($c) { return $c['key'] === 'justified'; }))[0]['label'] === 'Saved with a reason');
    $rowOf = function ($rec, $f, $type) use ($res, $dims, $cols) {
        foreach ($res['violations'] as $v) if ($v['record'] === $rec && $v['field'] === $f && $v['type'] === $type) return ScanColumns::row($v, $dims, $cols);
        return [];
    };
    $r = $rowOf('XE-1', 'uj_wt', 'range');
    check('H1: uj_wt\'s row: "Yes", and the problem names the reason field', ($r['justified'] ?? null) === 'Yes'
        && strpos((string) ($r['problem'] ?? ''), 'It was saved with a reason, given in [uj_wt_why].') !== false, $r);
    $r = $rowOf('XE-1', 'uj_lock', 'writeonce');
    check('H1: the write-once row is blank in that column, and its problem names no reason', ($r['justified'] ?? null) === ''
        && strpos((string) ($r['problem'] ?? ''), 'saved with a reason') === false, $r);
    $pages['H1_problem_wt'] = (string) ($rowOf('XE-1', 'uj_wt', 'range')['problem'] ?? '');
    $du = $durable($D);
    $flags = array_values(array_filter($du['rows'], function ($r) { return strpos($r, 'XE-1 uj_lock ') === 0 || strpos($r, 'XE-1 uj_wt ') === 0; }));
    check('H1: the durable rows carry the flag: 1 for uj_wt and for the range on the lock, 0 for the lock itself', $flags === ['XE-1 uj_lock range 1', 'XE-1 uj_lock writeonce 0',
        'XE-1 uj_wt range 1'], $flags);
    check('H1: the durable rows of XE-3, never opened: none', !array_filter($du['rows'], function ($r) { return strpos($r, 'XE-3 ') === 0; }));
    check('H1: the durable tally: 1 finding, 17 saved with a reason, 7 rule problems (the refused tags of A1)',
        $du['tally'] === ['violations' => 1, 'justified' => 17, 'ruleProblems' => 7, 'gaps' => 0], $du['tally']);
    check('H1: the panel line', $du['found'] === '1 finding so far, and 17 saved with a reason', $du['found']);
    check('H1: not certified, for the rule problems', $du['outcome']['clean'] === false
        && $du['verdict'] === '7 rules could not be evaluated, so this result does not certify the project.', [$du['outcome'], $du['verdict']]);

    // H2: a Missing Data Code imported as the reason
    $import('XE-1', ['uj_wt_why' => 'UNK'], '20261026110000');
    $got = $legacy($D, $res);
    check('H2: with UNK as its reason, uj_wt is open in the legacy scan', in_array('XE-1 uj_wt range:hard-high open', $got, true)
        && ($res['stats']['justified'] ?? null) === 16 && ($res['stats']['violations'] ?? null) === 2, [$got, $res['stats']]);
    $du = $durable($D);
    check('H2: and in the durable scan: 2 findings, 16 saved with a reason', $du['found'] === '2 findings so far, and 16 saved with a reason', $du['found']);
    $import('XE-1', ['uj_wt_why' => '   '], '20261026110500');
    check('H2b: spaces as the reason: open too', in_array('XE-1 uj_wt range:hard-high open', $legacy($D), true));
    $import('XE-1', ['uj_wt_why' => 'Scale checked twice'], '20261026111000');
    check('H2c: the reason imported again: justified again', in_array('XE-1 uj_wt range:hard-high justified by uj_wt_why', $legacy($D), true));

    // H3: the scratch project, whose only finding has its reason: clean
    $keep = [$DICT, $D];
    \Project::$scratch = true; \Project::$pid = 901;
    $SD = $pad(['record_id' => ['form_name' => 'my_first_instrument', 'field_type' => 'text'], 'uj_wt' => $FIX['uj_wt'], 'uj_wt_why' => $FIX['uj_wt_why']]);
    $SDATA = ['1' => [900 => ['record_id' => '1', 'uj_wt' => '300.0', 'uj_wt_why' => 'Scale checked twice']]];
    $sm = mod($SDATA, $SD);
    $sres = $sm->scanProject(901);
    check('H3: scratch project, legacy scan: no open finding, 1 saved with a reason', $sres['stats']['violations'] === 0 && $sres['stats']['justified'] === 1, $sres['stats']);
    $DICT = $SD;
    $du = $durable($SDATA);
    check('H3: the durable scan is clean, with the one finding saved with its reason', $du['outcome']['clean'] === true && $du['verdict'] === null
        && $du['tally'] === ['violations' => 0, 'justified' => 1, 'ruleProblems' => 0, 'gaps' => 0], [$du['outcome'], $du['tally']]);
    check('H3: the panel says so', $du['found'] === 'Nothing found yet, and 1 saved with a reason'
        && $du['coverage'] === 'Every record was checked, including changes made while it ran.', [$du['found'], $du['coverage']]);
    $SDATA['1'][900]['uj_wt_why'] = '';
    $du = $durable($SDATA);
    check('H3b: with the reason cleared, the same scan is not clean', $du['outcome']['clean'] === false && $du['found'] === '1 finding so far'
        && $du['verdict'] === 'This result does not certify the project.', [$du['outcome'], $du['found'], $du['verdict']]);
    list($DICT, $D) = $keep;
    \Project::$scratch = false; \Project::$pid = 149;

    // ---- Section J: what REDCap does that the code depends on -------------------------------------------
    // J2: a reason in a row branching logic hides, written by an import: the page asks (JS replay), the scans read it
    $import('XE-1', ['uj_hr_show' => '0'], '20261026111500');
    $open('J2', 'locked310');
    check('J2: the scans read the saved reason, as REDCap\'s branching never reaches them', in_array('XE-1 uj_hr range:hard-high justified by uj_hr_why', $legacy($D), true));
    $du = $durable($D);
    check('J2: the durable scan keeps the counts of H1', $du['found'] === '1 finding so far, and 17 saved with a reason', $du['found']);
    $import('XE-1', ['uj_hr_show' => '1'], '20261026112000');
    // J4: an administrator adds the SQL reason field
    $DICT = $pad($BASE + $FIX + ['uj_sql_why' => ['form_name' => 'uv_justify_test', 'field_type' => 'sql',
        'select_choices_or_calculations' => "select 1, 'Patient crying' union all select 2, 'Counted twice'"]]);
    $LABELS['uj_sql_why'] = 'Why the respiratory rate is right';
    $TYPES['uj_sql_why'] = ['type' => 'sql', 'validation' => '', 'choices' => '1, Patient crying | 2, Counted twice', 'branching' => ''];
    $p = $open('J4', 'sql');
    check('J4: with the SQL field in place, uj_rr\'s error is gone and it is paired with it', errOf($p, 'uj_rr') === ''
        && (rulesOn($p, 'uj_rr', 'justify')[0]['justifyReason'] ?? '') === 'uj_sql_why', rulesOn($p, 'uj_rr'));
    $REASON['uj_rr'] = 'uj_sql_why';
    $m = $save('XE-1', ['uj_rr' => '70', 'uj_sql_why' => '2'], '20261026112500');
    $step('J4b', $m, [$J('uj_rr', 'range:hard-high')]);

    // The labels, field types and sections the engine replay builds its page from.
    $pages['labels'] = $LABELS;
    $pages['types'] = $TYPES;
    $pages['sections'] = $SECTIONS;
    $json = json_encode($pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    $file = __DIR__ . '/justify_live_pages.json';
    if (in_array('--write', $argv ?? [], true)) {
        file_put_contents($file, $json);
        echo "wrote $file\n";
    } else {
        check('tests/justify_live_pages.json is what the module builds now (run with --write after a change)',
            is_file($file) && str_replace("\r\n", "\n", (string) file_get_contents($file)) === $json);
    }
    echo "justify_live_twin_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
