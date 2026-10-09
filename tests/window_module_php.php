<?php
/**
 * window_module_php.php — @UVWINDOW through the module (2.2.0).
 *
 * window_php.php pins the verdict; this file pins the REDCap glue around it:
 *   - the field and dictionary hooks: a window needs a date field, a "from"
 *     field of the same kind, and a unit the field can hold; the types and
 *     display formats travel on the rule,
 *   - the page fold of the "from" date: a field of this page stays live, an
 *     entitled off-page value is baked in as its saved Y-M-D (a snapshot, so
 *     the rule never blocks), and a value the viewer may not read is never
 *     shipped at all (survey, no rights to its form),
 *   - the server clock reaches the page only when a rule reads it,
 *   - the post-save audit logs window-early / window-late / future, checks
 *     nothing against a blank "from" date, and re-checks a window when only
 *     the form holding its "from" date is saved (reverse dependency).
 *
 * Run:  php tests/window_module_php.php
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
        public static function getData($p) { return self::$data; }
        public static function getDataDictionary($pid, $f = 'array') {
            if (!$pid) throw new \RuntimeException('needs pid');
            return self::$dictionary;
        }
        public static function getUserRights($pid = null, $user = null) { return self::$rights; }
        public static function getGroupNames($u = false, $g = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
    }

    require_once __DIR__ . '/../UniversalValidator.php';
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    function f($form, $ann = '', $validation = '', $type = 'text') {
        return ['field_type' => $type, 'form_name' => $form, 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => $validation];
    }
    // baseline_form holds the anchor; visit_form the windows.
    $DICT = [
        'record_id'     => f('baseline_form'),
        'visit_date_bl' => f('baseline_form', '', 'date_dmy'),
        'dose_at'       => f('baseline_form', '', 'datetime_ymd'),
        'visit_date_2'  => f('visit_form', '@UVWINDOW={"from":"[visit_date_bl]","window":[21,35],"blockSave":"hard"}', 'date_dmy'),
        'collected'     => f('visit_form', '@UVWINDOW={"notFuture":true}', 'date_ymd'),
        'v_start'       => f('visit_form', '', 'date_ymd'),
        'v_end'         => f('visit_form', '@UVWINDOW={"from":"[v_start]","window":[0,7]}', 'date_mdy'),
        'bad_novalid'   => f('visit_form', '@UVWINDOW={"notFuture":true}', ''),
        'bad_number'    => f('visit_form', '@UVWINDOW={"notFuture":true}', 'number'),
        'bad_hours'     => f('visit_form', '@UVWINDOW={"from":"[v_start]","window":[0,2],"unit":"hours"}', 'date_ymd'),
        'bad_nofield'   => f('visit_form', '@UVWINDOW={"from":"[nope]","window":[0,2]}', 'date_ymd'),
        'bad_self'      => f('visit_form', '@UVWINDOW={"from":"[bad_self]","window":[0,2]}', 'date_ymd'),
        'bad_notdate'   => f('visit_form', '@UVWINDOW={"from":"[record_id]","window":[0,2]}', 'date_ymd'),
        'bad_family'    => f('visit_form', '@UVWINDOW={"from":"[dose_at]","window":[0,2]}', 'date_ymd'),
        'bad_notes'     => f('visit_form', '@UVWINDOW={"notFuture":true}', '', 'notes'),
    ];
    $DATA = ['2' => [351 => [
        'record_id' => '2', 'visit_date_bl' => '2026-03-01', 'dose_at' => '',
        'visit_date_2' => '2026-03-15', 'collected' => '2026-10-10',
        'v_start' => '2026-01-01', 'v_end' => '2026-01-05',
    ]]];
    $FULL = ['nurse' => ['forms' => ['baseline_form' => '1', 'visit_form' => '1']]];
    $PART = ['nurse' => ['forms' => ['baseline_form' => '0', 'visit_form' => '1']]];
    $CLOCK = ['today' => '2026-10-09', 'now' => '2026-10-09 14:30:00'];

    function mod($dict, $data, $rights, $user) {
        global $CLOCK;
        $GLOBALS['__TEST_USER'] = $user;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectSettings = ['log-values' => 'raw'];
        $m->projectIdReturn = 149;
        \REDCap::$dictionary = $dict;
        \REDCap::$data = $data;
        \REDCap::$rights = $rights;
        // Pin the server clock: the test must not depend on the day it runs.
        $rp = new \ReflectionProperty(\INSPIRE\UniversalValidator\UniversalValidator::class, 'clockMemo');
        $rp->setAccessible(true);
        $rp->setValue($m, $CLOCK);
        return $m;
    }
    function page($m, $ctx, $rec, $form) {
        ob_start();
        if ($ctx === 'survey') $m->redcap_survey_page_top(149, $rec, $form, 351, null, 'hash', null, 1);
        else $m->redcap_data_entry_form_top(149, $rec, $form, 351, null, 1);
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

    // ---- 1) the hooks: what a window needs from the dictionary -----------------
    $p = page(mod($DICT, $DATA, $FULL, 'nurse'), 'form', '2', 'visit_form');
    $err = function ($field) use ($p) { $r = ruleFor($p, $field); return $r && isset($r['configError']) ? $r['configError'] : ''; };
    check('no validation: refused, named', strpos($err('bad_novalid'), 'needs a date field') !== false);
    check('number validation: refused, names it', strpos($err('bad_number'), '(it has "number")') !== false);
    check('hours on a date field: refused', strpos($err('bad_hours'), '"unit" "hours" needs a datetime field') !== false);
    check('unknown "from" field: refused', strpos($err('bad_nofield'), '"[nope]", which is not a field') !== false);
    check('"from" itself: refused', strpos($err('bad_self'), 'names this field itself') !== false);
    check('"from" not a date: refused', strpos($err('bad_notdate'), 'is not a date field') !== false);
    check('date counted from a datetime: refused', strpos($err('bad_family'), 'holds dates with a time and this field holds dates') !== false);
    check('notes field: refused by eligibility', strpos($err('bad_notes'), 'does not support "notes" fields') !== false);
    check('every refusal names @UVWINDOW', strpos($err('bad_family'), '@UVWINDOW on "bad_family"') === 0);

    // ---- 2) the fold: live, snapshot, and nothing shipped when not entitled ---
    $r = ruleFor($p, 'visit_date_2');
    check('types and formats travel on the rule', $r && $r['dateType'] === 'date' && $r['dateFormat'] === 'dmy'
        && $r['fromType'] === 'date' && $r['fromFormat'] === 'dmy');
    check('entitled off-page "from": its saved Y-M-D is baked in', $r && $r['windowFromOp'] === ['lit', '2026-03-01']);
    check('...as a snapshot, so the rule cannot block', $r && $r['snapshotFields'] === ['visit_date_bl'] && empty($r['deferred']));
    $r = ruleFor($p, 'v_end');
    check('"from" on this page: a live ref', $r && $r['windowFromOp'] === ['ref', 'v_start', null] && empty($r['snapshotFields']));
    check('the server clock reaches the page', isset($p['cfg']['clock']) && $p['cfg']['clock'] === $CLOCK);

    $p = page(mod($DICT, $DATA, $PART, 'nurse'), 'form', '2', 'visit_form');
    $r = ruleFor($p, 'visit_date_2');
    check('no rights to the "from" form: deferred, nothing shipped', $r && !empty($r['deferred']) && !isset($r['windowFromOp']));
    check('no rights: the saved date is nowhere in the page', strpos($p['html'], '2026-03-01') === false);
    check('no rights: no reason that would name the form', empty($r['deferredWhy']));

    $p = page(mod($DICT, $DATA, $FULL, 'nurse'), 'survey', '2', 'visit_form');
    $r = ruleFor($p, 'visit_date_2');
    check('survey: deferred, nothing shipped', $r && !empty($r['deferred']) && !isset($r['windowFromOp']));
    check('survey: the saved date is nowhere in the page', strpos($p['html'], '2026-03-01') === false);
    check('survey: a "from" on the same page stays live', ruleFor($p, 'v_end')['windowFromOp'] === ['ref', 'v_start', null]);

    $p = page(mod($DICT, $DATA, $FULL, 'nurse'), 'form', '', 'visit_form');
    $r = ruleFor($p, 'visit_date_2');
    check('new record: the "from" date is blank, not unknown', $r && $r['windowFromOp'] === ['lit', ''] && empty($r['deferred']));

    $NOWIN = ['record_id' => f('baseline_form'), 'a' => f('visit_form', '@UVASSERT="[a]<>\'x\'"')];
    $p = page(mod($NOWIN, [], $FULL, 'nurse'), 'form', '2', 'visit_form');
    check('no window rule: no clock on the page', is_array($p['cfg']) && !array_key_exists('clock', $p['cfg']));

    // ---- 3) the post-save audit ---------------------------------------------------
    $m = mod($DICT, $DATA, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'visit_form', 351, null, null, null, 1);
    $by = [];
    foreach (findings($m) as $e) $by[$e['field']] = $e;
    check('audit: early visit logged', isset($by['visit_date_2']) && $by['visit_date_2']['type'] === 'window'
        && $by['visit_date_2']['reason'] === 'window-early' && $by['visit_date_2']['value'] === '2026-03-15');
    check('audit: tomorrow (server clock) is future', isset($by['collected']) && $by['collected']['reason'] === 'future');
    check('audit: a date inside its window is not logged', !isset($by['v_end']));
    check('audit: a misconfigured window logs nothing as data', !isset($by['bad_hours']) && !isset($by['bad_family']));

    $late = $DATA; $late['2'][351]['visit_date_2'] = '2026-04-06';
    $m = mod($DICT, $late, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'visit_form', 351, null, null, null, 1);
    $by = []; foreach (findings($m) as $e) $by[$e['field']] = $e;
    check('audit: late visit logged', isset($by['visit_date_2']) && $by['visit_date_2']['reason'] === 'window-late');

    $blank = $DATA; $blank['2'][351]['visit_date_bl'] = '';
    $m = mod($DICT, $blank, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'visit_form', 351, null, null, null, 1);
    $by = []; foreach (findings($m) as $e) $by[$e['field']] = $e;
    check('audit: a blank "from" date checks nothing', !isset($by['visit_date_2']));

    $junk = $DATA; $junk['2'][351]['v_end'] = '2026-02-30';
    $m = mod($DICT, $junk, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'visit_form', 351, null, null, null, 1);
    $u = findings($m, 'uvalidate-unconfigurable');
    check('audit: an impossible saved date is reported, not passed',
        (bool) array_filter($u, function ($e) { return $e['fields'] === 'v_end' && strpos($e['why'], 'not a date this rule can read') !== false; }));
    check('audit: ...and never logged as a violation', !array_filter(findings($m), function ($e) { return $e['field'] === 'v_end'; }));

    // ---- 4) reverse dependency: saving only the "from" form re-checks the window ---
    $m = mod($DICT, $DATA, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'baseline_form', 351, null, null, null, 1);
    $dep = array_values(array_filter(findings($m), function ($e) { return $e['field'] === 'visit_date_2'; }));
    check('reverse dependency: the window on visit_form is re-checked', count($dep) === 1 && $dep[0]['reason'] === 'window-early');
    check('reverse dependency: reported where the field lives', $dep && $dep[0]['instrument'] === 'visit_form');
    check('reverse dependency: an unrelated window is not re-audited',
        !array_filter(findings($m), function ($e) { return $e['field'] === 'collected'; }));

    // ---- 5) the scan: same verdicts, and one "today" per run --------------------
    $m = mod($DICT, $DATA, $FULL, 'nurse');
    $res = $m->scanProject(149);
    $by = []; foreach ($res['violations'] as $v) $by[$v['field']] = $v['reason'];
    check('scan: early visit and future date found', ($by['visit_date_2'] ?? null) === 'window-early' && ($by['collected'] ?? null) === 'future');
    check('scan: misconfigured windows are reported as rule problems, not data',
        !isset($by['bad_hours']) && (bool) array_filter($res['unconfigurable'], function ($u) { return in_array('bad_hours', $u['fields'], true); }));

    $plan = new \ReflectionMethod($m, 'scanPlan'); $plan->setAccessible(true);
    $record = new \ReflectionMethod($m, 'scanRecord'); $record->setAccessible(true);
    $runAt = function ($utc) use ($m, $plan, $record, $DATA) {
        $p = $plan->invoke($m, 149, ['clockAt' => $utc]);
        $found = [];
        $sink = new \INSPIRE\UniversalValidator\CallbackFindingSink(function (array $v) use (&$found) { $found[$v['field']] = $v['reason']; });
        $seen = []; $unconf = [];
        $record->invokeArgs($m, [$p, 149, '2', $DATA['2'], $sink, &$seen, &$unconf]);
        return ['plan' => $p, 'found' => $found];
    };
    $tz = date_default_timezone_get();
    date_default_timezone_set('UTC');
    $r1 = $runAt('2026-10-09 23:00:00');
    check('durable run: "today" is the day the run started', $r1['plan']['clock'] === ['today' => '2026-10-09', 'now' => '2026-10-09 23:00:00']);
    check('durable run started the day before: the date is future', ($r1['found']['collected'] ?? null) === 'future');
    $r2 = $runAt('2026-10-10 00:00:01');
    check('durable run started on that day: not future', !isset($r2['found']['collected']));
    date_default_timezone_set('Pacific/Kiritimati');   // UTC+14: REDCap stamps dates in server time
    $r3 = $runAt('2026-10-09 12:00:00');
    check('durable run: the stored UTC start is read in the server timezone', $r3['plan']['clock']['today'] === '2026-10-10');
    date_default_timezone_set($tz);
    $clock = new \ReflectionMethod($m, 'evaluationClock'); $clock->setAccessible(true);
    check('the scan clock does not outlive the record', $clock->invoke($m) === $CLOCK);
    $bad = $plan->invoke($m, 149, ['clockAt' => 'yesterday']);
    check('an unreadable run start falls back to the server clock', $bad['clock'] === $CLOCK);
    // The report: the issue label and the staff detail line come from the registry.
    $dims = \INSPIRE\UniversalValidator\ScanDimensions::build(149, $DICT, [
        ['type' => 'window', 'fields' => ['visit_date_2'], 'windowFrom' => '[visit_date_bl]', 'windowLo' => 21, 'windowHi' => 35, 'windowUnit' => 'days'],
        ['type' => 'window', 'fields' => ['test_date'], 'windowFrom' => '[dob]', 'windowLo' => 0, 'windowUnit' => 'days'],
    ]);
    check('report detail: the window that was missed',
        \INSPIRE\UniversalValidator\MessageCatalog::detail(['type' => 'window', 'reason' => 'window-early'], $dims->rule(1))
        === 'The window opens 21 days from [visit_date_bl].');
    check('report detail: the closing bound',
        \INSPIRE\UniversalValidator\MessageCatalog::detail(['type' => 'window', 'reason' => 'window-late'], $dims->rule(1))
        === 'The window closes 35 days from [visit_date_bl].');
    check('report detail: an open end has no sentence, not half of one',
        \INSPIRE\UniversalValidator\MessageCatalog::detail(['type' => 'window', 'reason' => 'window-late'], $dims->rule(2)) === '');
    check('report detail: future has none',
        \INSPIRE\UniversalValidator\MessageCatalog::detail(['type' => 'window', 'reason' => 'future'], $dims->rule(1)) === '');
    check('report wording: catalog, not fallback',
        \INSPIRE\UniversalValidator\MessageCatalog::explain(['type' => 'window', 'reason' => 'future', 'rule' => 1], $dims->rule(1))
        === ['text' => 'The date is in the future.', 'source' => 'catalog']);

    // The start the service hands the plan comes from the store, bound to the project.
    $store = new \INSPIRE\UniversalValidator\Scan\ArrayScanStore();
    $started = $store->startRun(149, ['created_by' => 'nurse']);
    $rid = $started['run']['run_id'];
    check('the store keeps when a run started', (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', (string) $store->runStartedAt(149, $rid)));
    check('...and tells no other project', $store->runStartedAt(150, $rid) === null && $store->runStartedAt(149, $rid + 99) === null);
    check('ScanService passes the run start as the plan clock',
        strpos(file_get_contents(__DIR__ . '/../php/Scan/ScanService.php'), "'clockAt'      => \$this->store()->runStartedAt(") !== false);

    fwrite(STDOUT, "window_module_php: $n checks, $fail failure(s)\n");
    exit($fail ? 1 : 0);
}
