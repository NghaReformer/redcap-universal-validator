<?php
/**
 * writeonce_live_twin_php.php - run the REAL module over the @UVWRITEONCE live
 * fixture (docs/testbed/uvwriteonce_test_fields.csv) on a copy of pid 149's
 * shape, so every server-side "Expect" of
 * docs/testbed/WRITEONCE_LIVE_ACCEPTANCE.md is the module's own answer.
 *
 * REDCap's log is the lock's only source, so the twin keeps one: each save
 * the sheet makes (a form save or the Data Import Tool) adds the row REDCap
 * writes, with the user and the time, and the module reads it through
 * ValueHistory. The roles are the project's as the sheet sets them up: no
 * "UV Data manager" role at first, then the role with the tester out of it,
 * then in it, then out again.
 *
 * It checks the configuration errors word for word, which fields each page
 * locks, every log entry of each save in the sheet's order, and the scan
 * with its detail lines. The page configs it builds are the input of
 * tests/writeonce_live_twin_js.cjs, which replays the sheet's clicks through
 * js/engine.js; they are kept in tests/writeonce_live_pages.json, and this
 * suite fails when that file is not what the module builds now.
 *
 * RUN:    php tests/writeonce_live_twin_php.php && node tests/writeonce_live_twin_js.cjs
 * WRITE:  php tests/writeonce_live_twin_php.php --write   (after a change to the pages)
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $logCalls = []; public $projectSettings = [];
        public function getSubSettings($k, $pid = null) { return []; }
        public function getProjectSetting($k, $pid = null) { return $this->projectSettings[$k] ?? null; }
        public function getSystemSetting($k) { return $k === 'log-hmac-key' ? str_repeat('a', 64) : null; }
        public function setSystemSetting($k, $v) {}
        public function getProjectId() { return 149; }
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
            if (isset($p['records'])) $d = array_intersect_key($d, array_fill_keys(array_map('strval', (array) $p['records']), true));
            return $d;
        }
        public static function getDataDictionary($pid, $f = 'array') { return self::$dictionary; }
        public static function getUserRights($pid = null, $user = null) { return self::$rights; }
        public static function getGroupNames($u = false, $g = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
    }
    /** pid 149, arm 1: event ids as on chpr-redcap.org; uv_writeonce_test at visit_1_arm_1 only. */
    class Project {
        public $project = ['missing_data_codes' => ''];
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
            380 => ['xe_visit', 'xr_specimen', 'xr_result', 'uv_writeonce_test'],
            381 => ['xe_visit', 'xr_result'],
            382 => ['xe_unsched'],
            383 => ['xe_visit', 'xe_close'],
        ];
        public function __construct($pid) {}
        public function getRepeatingFormsEvents() {
            return [379 => ['xr_specimen' => ''], 380 => ['xr_specimen' => '', 'xr_result' => ''], 381 => ['xr_result' => ''], 382 => 'WHOLE'];
        }
        public function getUniqueEventNames() { return array_map(function ($r) { return $r['unique_event_name']; }, $this->eventInfo); }
    }

    require_once __DIR__ . '/../UniversalValidator.php';
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';
    require_once __DIR__ . '/writeonce_fake_log.php';

    use INSPIRE\UniversalValidator\ValueHistory;
    use INSPIRE\UniversalValidator\ScanColumns;

    $fail = 0; $n = 0;
    function check($label, $ok, $got = null) {
        global $fail, $n; $n++;
        if (!$ok) { $fail++; fwrite(STDERR, "FAIL: $label" . ($got !== null ? ' (got ' . json_encode($got, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ')' : '') . "\n"); }
    }

    // ---- the dictionary: the fixture plus the record ID field --------------------------------
    $dict = ['record_id' => ['form_name' => 'xe_enrol', 'field_type' => 'text']];
    $LABELS = []; $TYPES = [];
    $fh = fopen(__DIR__ . '/../docs/testbed/uvwriteonce_test_fields.csv', 'r');
    $head = fgetcsv($fh, 0, ',', '"', '');
    $read = 0;
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $r = array_combine($head, $r);
        $read++;
        $f = $r['Variable / Field Name'];
        $dict[$f] = ['form_name' => $r['Form Name'], 'field_type' => $r['Field Type'],
            'field_annotation' => $r['Field Annotation'], 'select_choices_or_calculations' => $r['Choices, Calculations, OR Slider Labels'],
            'text_validation_type_or_show_slider_number' => $r['Text Validation Type OR Show Slider Number']];
        $LABELS[$f] = $r['Field Label'];
        $TYPES[$f] = ['type' => $r['Field Type'], 'validation' => $r['Text Validation Type OR Show Slider Number'],
            'choices' => $r['Choices, Calculations, OR Slider Labels']];
    }
    fclose($fh);
    check('the fixture holds 13 rows', $read === 13, $read);
    check('the fixture adds one instrument only', count(array_unique(array_map(function ($f) use ($dict) { return $dict[$f]['form_name']; }, array_keys($LABELS)))) === 1);
    foreach ($dict as &$m) $m += ['field_annotation' => '', 'select_choices_or_calculations' => '', 'text_validation_type_or_show_slider_number' => ''];
    unset($m);
    $FORM = 'uv_writeonce_test';
    $WO = array_values(array_filter(array_keys($LABELS), function ($f) use ($dict) { return strpos($dict[$f]['field_annotation'], '@UVWRITEONCE') !== false; }));

    // ---- the project's roles, as the sheet sets them up -------------------------------------
    $NO_ROLE  = ['roles' => [], 'users' => []];
    $ROLE_OUT = ['roles' => ['uv data manager' => true, 'u-149uvdm' => true], 'users' => []];
    $ROLE_IN  = ['roles' => ['uv data manager' => true, 'u-149uvdm' => true], 'users' => ['tester' => ['uv data manager', 'u-149uvdm']]];

    // ---- REDCap's log: one row per save the sheet makes --------------------------------------
    $LOG = [];
    /** A save of the form or an import: the row REDCap writes, its fields as "f = 'v'" lines. */
    function logSave($at, array $values, $user = 'tester') {
        global $LOG;
        $lines = [];
        foreach ($values as $f => $v) $lines[] = $f . " = '" . str_replace("'", "\\'", (string) $v) . "'";
        $LOG[] = [count($LOG) + 1, 149, 'XE-3', 380, $at, implode(",\n", $lines), 'redcap_data', 'UPDATE', $user];
    }
    function setPrivate($m, $prop, $value) {
        $p = new \ReflectionProperty(get_class($m), $prop);
        $p->setAccessible(true);
        $p->setValue($m, $value);
    }
    function mod(array $data, array $roles) {
        global $dict, $LOG, $WO;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectSettings = ['log-values' => 'raw'];
        \REDCap::$dictionary = $dict;
        \REDCap::$data = $data;
        \REDCap::$rights = ['tester' => ['forms' => array_fill_keys(['xe_enrol', 'xe_visit', 'xr_specimen', 'xr_result', 'uv_writeonce_test'], '1')]];
        $map = [];
        foreach ((new \Project(149))->eventsForms as $e => $forms) foreach ($forms as $f) $map[] = ['event_id' => $e, 'form' => $f];
        \REDCap::$mappings = $map;
        $db = new FakeHistoryLog();
        $db->rows = $LOG;
        $utc = new \DateTimeZone('UTC');
        setPrivate($m, 'valueHistoryOverride', ValueHistory::forProject($db, 149, $WO, $utc, $utc));
        setPrivate($m, 'roleBookOverride', $roles);
        setPrivate($m, 'clockOverride', \INSPIRE\UniversalValidator\UniversalValidator::clockStamp(new \DateTimeImmutable('2026-10-23 10:00:00', $utc)));
        return $m;
    }
    function page($m) {
        ob_start();
        $m->redcap_data_entry_form_top(149, 'XE-3', 'uv_writeonce_test', 380, null, 1);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return ['html' => $html, 'cfg' => json_decode($mm[1] ?? 'null', true)];
    }
    function ruleOf($p, $f) {
        foreach ($p['cfg']['rules'] ?? [] as $r) if (in_array($f, $r['fields'] ?? [], true)) return $r;
        return null;
    }
    function errOf($p, $f) { $r = ruleOf($p, $f); return $r['configError'] ?? ''; }
    function lockedOn($p) {
        $out = [];
        foreach ($p['cfg']['rules'] ?? [] as $r) if (($r['type'] ?? '') === 'writeonce' && empty($r['configError'])) foreach ($r['fields'] as $f) $out[] = $f;
        sort($out);
        return $out;
    }
    /** "field:reason locked_at > changed_at" for each write-once finding the save logged. */
    function hits($m) {
        $out = [];
        foreach ($m->logCalls as $c) {
            if ($c[0] !== 'invalid-id-saved' || ($c[1]['type'] ?? '') !== 'writeonce') continue;
            $out[] = $c[1]['field'] . ':' . $c[1]['reason'] . ' ' . ($c[1]['locked_at'] ?? '?') . ' > ' . ($c[1]['changed_at'] ?? '?');
        }
        sort($out);
        return $out;
    }
    /** the rule problems a save logged for the good fields (the bad ones are configuration errors) */
    function problems($m) {
        $out = [];
        foreach ($m->logCalls as $c) if ($c[0] === 'uvalidate-unconfigurable' && strpos((string) $c[1]['fields'], 'uw_bad_') === false) $out[] = $c[1];
        return $out;
    }
    function save(array $data, array $roles) {
        $m = mod($data, $roles);
        $m->redcap_save_record(149, 'XE-3', 'uv_writeonce_test', 380, null, null, null, 1);
        return $m;
    }
    /** "field:reason asOf" for each write-once finding of the scan, and the detail line of each */
    function scan(array $data, array $roles, &$lines = null) {
        $m = mod($data, $roles);
        $res = $m->scanProject(149);
        $dims = $m->scanDimensions(149, $res['rules'] ?? null);
        $cols = ScanColumns::all($dims);
        $out = []; $lines = [];
        foreach ($res['violations'] as $v) {
            if ($v['type'] !== 'writeonce') continue;
            $out[] = $v['field'] . ':' . $v['reason'] . ' ' . ($v['asOf'] ?? '?');
            $lines[$v['field']] = ScanColumns::row($v, $dims, $cols)['problem'] ?? '';
        }
        sort($out);
        $bad = [];
        foreach ($res['unconfigurable'] as $u) foreach ($u['fields'] as $f) if (strpos($f, 'uw_') === 0) $bad[$f] = true;
        ksort($bad);
        $lines['_problems'] = array_keys($bad);
        return $out;
    }
    $pages = [];
    $opened = function ($key, array $data, array $roles) use (&$pages, $dict, $FORM) {
        $p = page(mod($data, $roles));
        $pages[$key] = $p['cfg'];
        $vals = [];
        foreach ($data['XE-3'][380] ?? [] as $f => $v) if (isset($dict[$f]) && $dict[$f]['form_name'] === $FORM && (string) $v !== '') $vals[$f] = (string) $v;
        ksort($vals);
        $pages[$key . '_values'] = (object) $vals;
        return $p;
    };

    $D = ['XE-3' => [351 => ['record_id' => 'XE-3'], 380 => []]];

    // ---- Section A: configuration, before and after the role exists --------------------------
    $p = $opened('a1', $D, $NO_ROLE);
    $NOROLE = function ($f, $role) { return '@UVWRITEONCE on "' . $f . '": "' . $role . '" is not a role of this project — use the role name the User Rights page shows, or its unique role name.'; };
    check('A1: uw_arm refused until the role exists', errOf($p, 'uw_arm') === $NOROLE('uw_arm', 'UV Data manager'), errOf($p, 'uw_arm'));
    check('A1: uw_site the same', errOf($p, 'uw_site') === $NOROLE('uw_site', 'UV Data manager'), errOf($p, 'uw_site'));
    check('A1: uw_yn names the role as written', errOf($p, 'uw_yn') === $NOROLE('uw_yn', 'uv data MANAGER'), errOf($p, 'uw_yn'));
    $p = $opened('b1', $D, $ROLE_OUT);
    foreach (['uw_arm', 'uw_site', 'uw_yn', 'uw_consent', 'uw_notes', 'uw_pain', 'uw_weight'] as $f) check("A2: $f configures once the role exists", errOf($p, $f) === '', errOf($p, $f));
    $F = [
        'uw_bad_role' => $NOROLE('uw_bad_role', 'Nobody here'),
        'uw_bad_calc' => '@UVWRITEONCE on "uw_bad_calc": @UVWRITEONCE does not support "calc" fields — REDCap works a calc\'s value out again whenever the fields it reads change.',
        'uw_bad_setvalue' => '@UVWRITEONCE on "uw_bad_setvalue": @UVWRITEONCE cannot lock a field with @SETVALUE — REDCap writes its value again each time the form or survey page opens.',
        'uw_bad_when' => '@UVWRITEONCE on "uw_bad_when": unknown @UVWRITEONCE option(s): when — valid: roles, since, message.',
        'uw_bad_value' => '@UVWRITEONCE on "uw_bad_value": @UVWRITEONCE takes no value, or its settings as JSON, e.g. @UVWRITEONCE={"roles":["Data manager"]}.',
    ];
    foreach ($F as $f => $text) check("F: $f refused word for word", errOf($p, $f) === $text, errOf($p, $f));
    $F = []; foreach (array_keys($LABELS) as $f) if (strpos($f, 'uw_bad_') === 0) $F[$f] = errOf($p, $f);
    $pages['errors'] = $F;
    // A survey page is told only that a check is not set up: a role name is the study's own business.
    ob_start();
    mod($D, $ROLE_OUT)->redcap_survey_page_top(149, 'XE-3', 'uv_writeonce_test', 380, null, 'hash', null, 1);
    $sv = ob_get_clean();
    preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $sv, $mm);
    $ps = ['html' => $sv, 'cfg' => json_decode($mm[1] ?? 'null', true)];
    check('survey: a refused role is not named', errOf($ps, 'uw_bad_role') === 'this check is not set up correctly; the study team can see the details on the data entry form.'
        && strpos($sv, 'Nobody here') === false, errOf($ps, 'uw_bad_role'));

    // ---- Section B: the first save and the page lock (the tester holds no role) ---------------
    check('B1: nothing saved yet, nothing locked', lockedOn($p) === [], lockedOn($p));
    $B1 = ['uw_consent' => '2026-10-01', 'uw_arm' => '1', 'uw_yn' => '1', 'uw_notes' => 'First note', 'uw_pain' => '50', 'uw_weight' => '70.0', 'uw_free' => 'a'];
    $D['XE-3'][380] = $B1;
    logSave('20261023090000', $B1 + ['uv_writeonce_test_complete' => '0']);
    $m = save($D, $ROLE_OUT);
    check('B1: the first save logs no write-once finding and no rule problem', hits($m) === [] && problems($m) === [], [hits($m), problems($m)]);
    $p = $opened('b2', $D, $ROLE_OUT);
    check('B2: reopened, every saved field with the tag is locked; the blank site, the weight (lock from 2099) and the untagged field are not',
        lockedOn($p) === ['uw_arm', 'uw_consent', 'uw_notes', 'uw_pain', 'uw_yn'], lockedOn($p));
    check('B2: the roles and "since" never reach the page', strpos($p['html'], 'UV Data manager') === false && stripos($p['html'], 'u-149uvdm') === false
        && strpos($p['html'], '2099') === false && strpos($p['html'], 'writeonceRoles') === false);
    $D['XE-3'][380]['uw_site'] = '1';
    logSave('20261023090500', ['uw_site' => '1']);
    $m = save($D, $ROLE_OUT);
    check('B3: the site saved for the first time: no finding', hits($m) === [] && problems($m) === [], [hits($m), problems($m)]);
    $p = $opened('b4', $D, $ROLE_OUT);
    check('B4: the site is locked now, with its own message', in_array('uw_site', lockedOn($p), true)
        && (ruleOf($p, 'uw_site')['message'] ?? '') === 'Ask the data manager to change the site.', ruleOf($p, 'uw_site'));
    $m = save($D, $ROLE_OUT);
    check('B4: the change undone, the save writes nothing new: no finding', hits($m) === [], hits($m));
    $D['XE-3'][380]['uw_weight'] = '71.0';
    logSave('20261023091000', ['uw_weight' => '71.0']);
    $m = save($D, $ROLE_OUT);
    check('B5: the weight changes before its lock starts: no finding', hits($m) === [] && problems($m) === [], [hits($m), problems($m)]);
    check('B5: and is still not locked on the page', !in_array('uw_weight', lockedOn(page(mod($D, $ROLE_OUT))), true));

    // ---- Section C: the Data Import Tool, which the page never sees ---------------------------
    $D['XE-3'][380] = array_merge($D['XE-3'][380], ['uw_consent' => '2026-10-02', 'uw_site' => '2', 'uw_notes' => '']);
    logSave('20261023092000', ['uw_consent' => '2026-10-02', 'uw_site' => '2', 'uw_notes' => '']);
    $got = scan($D, $ROLE_OUT, $lines);
    check('C1: the scan finds the import: two changes and a clearing', $got === ['uw_consent:changed 2026-10-23 09:00', 'uw_notes:cleared 2026-10-23 09:00',
        'uw_site:changed 2026-10-23 09:05'], $got);
    check('C1: the detail lines', ($lines['uw_consent'] ?? '') === 'The value was changed after its first save locked it. It was locked on 2026-10-23 09:00.'
        && ($lines['uw_notes'] ?? '') === 'The value was cleared after its first save locked it. It was locked on 2026-10-23 09:00.'
        && ($lines['uw_site'] ?? '') === 'Ask the data manager to change the site. It was locked on 2026-10-23 09:05.', $lines);
    check('C1: the five bad fields are rule problems, never data', $lines['_problems'] === ['uw_bad_calc', 'uw_bad_role', 'uw_bad_setvalue', 'uw_bad_value', 'uw_bad_when'], $lines['_problems']);
    $p = $opened('c2', $D, $ROLE_OUT);
    check('C2: reopened, the imported values are what is locked; the cleared note is not',
        lockedOn($p) === ['uw_arm', 'uw_consent', 'uw_pain', 'uw_site', 'uw_yn'], lockedOn($p));
    $m = save($D, $ROLE_OUT);
    check('C3: saving the form as it is logs the three, with when each was locked and changed', hits($m) === [
        'uw_consent:changed 2026-10-23 09:00 > 2026-10-23 09:20', 'uw_notes:cleared 2026-10-23 09:00 > 2026-10-23 09:20',
        'uw_site:changed 2026-10-23 09:05 > 2026-10-23 09:20'], hits($m));

    // ---- Section D: the role --------------------------------------------------------------------
    $p = $opened('d1', $D, $ROLE_IN);
    check('D1: in the role, the role fields open; the others stay locked', lockedOn($p) === ['uw_consent', 'uw_pain'], lockedOn($p));
    $D['XE-3'][380]['uw_arm'] = '2';
    logSave('20261023093000', ['uw_arm' => '2']);
    $m = save($D, $ROLE_IN);
    check('D2: the arm changed by the role: no finding; the import of the site now counts as the role\'s', hits($m) === [
        'uw_consent:changed 2026-10-23 09:00 > 2026-10-23 09:20', 'uw_notes:cleared 2026-10-23 09:00 > 2026-10-23 09:20'], hits($m));
    $got = scan($D, $ROLE_IN);
    check('D3: the scan agrees', $got === ['uw_consent:changed 2026-10-23 09:00', 'uw_notes:cleared 2026-10-23 09:00'], $got);
    $p = $opened('d4', $D, $ROLE_OUT);
    check('D4: out of the role, the arm is locked at its new value', lockedOn($p) === ['uw_arm', 'uw_consent', 'uw_pain', 'uw_site', 'uw_yn'], lockedOn($p));
    $got = scan($D, $ROLE_OUT);
    check('D4: the scan reads the roles as they are now, so the arm and the site count as changes again', $got === [
        'uw_arm:changed 2026-10-23 09:00', 'uw_consent:changed 2026-10-23 09:00', 'uw_notes:cleared 2026-10-23 09:00', 'uw_site:changed 2026-10-23 09:05'], $got);

    // The labels and field types the engine replay builds its page from.
    $pages['labels'] = $LABELS;
    $pages['types'] = $TYPES;
    $json = json_encode($pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    $file = __DIR__ . '/writeonce_live_pages.json';
    if (in_array('--write', $argv ?? [], true)) {
        file_put_contents($file, $json);
        echo "wrote $file\n";
    } else {
        check('tests/writeonce_live_pages.json is what the module builds now (run with --write after a change)',
            is_file($file) && str_replace("\r\n", "\n", (string) file_get_contents($file)) === $json);
    }
    echo "writeonce_live_twin_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
