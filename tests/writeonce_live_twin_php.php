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
 * locks, every log entry of each save in the sheet's order, over one module
 * log that holds each entry once (PRF-9), and the scan
 * with its detail lines: on the visit (380), on a whole repeating event (382,
 * an import of two instances), on a survey whose first page is saved by Next,
 * and after a deletion of the form's data, which the check cannot place.
 * Section I's steps read what REDCap itself logs; for I0, I0b, I1, I1b (and
 * I1c), I2, I3, I3b, I5, I5b, I6, I7 and H4's wordings it checks the module's
 * answer for the log the step expects, and for the other log the step tells
 * the tester to record. I4 needs an SQL field, which the fixture does not hold, so it is
 * not here. The page configs it builds are the input of
 * tests/writeonce_live_twin_js.cjs, which replays the sheet's clicks through
 * js/engine.js; they are kept in tests/writeonce_live_pages.json, and this
 * suite fails when that file is not what the module builds now.
 *
 * RUN:    php tests/writeonce_live_twin_php.php && node tests/writeonce_live_twin_js.cjs
 * WRITE:  php tests/writeonce_live_twin_php.php --write   (after a change to the pages)
 */

namespace ExternalModules {
    class AbstractExternalModule {
        /** The legacy one-request scan (tests/support/scan_facade.php, QUA-3). */
        public function scanProject(...$a) { return \INSPIRE\UniversalValidator\scan_project($this, ...$a); }
        /** The module log of the project: every entry each request wrote, read back by queryLogs(). */
        public static $stored = [];
        public $logCalls = []; public $projectSettings = [];
        public function getSubSettings($k, $pid = null) { return []; }
        public function getProjectSetting($k, $pid = null) { return $this->projectSettings[$k] ?? null; }
        public function getSystemSetting($k) { return $k === 'log-hmac-key' ? str_repeat('a', 64) : null; }
        public function setSystemSetting($k, $v) {}
        public function getProjectId() { return 149; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; self::$stored[] = [$m, $p]; return count($this->logCalls); }
        /**
         * The one read the module makes of its log (PRF-9), answered from the
         * entries of earlier requests as the framework's log table would: an
         * entry whose fingerprint the log holds is not written again.
         */
        public function queryLogs($sql, $params) {
            if (!preg_match('/^select log_id, fingerprint, finding where message = \? and (record|record_hmac|instrument) = \?$/', $sql, $mm)) {
                throw new \Exception('unexpected log query: ' . $sql);
            }
            $rows = [];
            foreach (self::$stored as $i => $c) {
                if ($c[0] === $params[0] && isset($c[1][$mm[1]], $c[1]['fingerprint']) && (string) $c[1][$mm[1]] === (string) $params[1]) {
                    $rows[] = ['log_id' => $i + 1, 'fingerprint' => $c[1]['fingerprint'], 'finding' => $c[1]['finding'] ?? null];
                }
            }
            return new LogRows($rows);
        }
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
    class LogRows {
        private $r; public function __construct(array $r) { $this->r = $r; }
        public function fetch_assoc() { return array_shift($this->r); }
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
    /** pid 149, arm 1: event ids as on chpr-redcap.org; uv_writeonce_test at visit_1_arm_1 and at unscheduled_arm_1, which repeats as a whole. */
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
            382 => ['xe_unsched', 'uv_writeonce_test'],
            383 => ['xe_visit', 'xe_close'],
        ];
        public function __construct($pid) {}
        public function getRepeatingFormsEvents() {
            return [379 => ['xr_specimen' => ''], 380 => ['xr_specimen' => '', 'xr_result' => ''], 381 => ['xr_result' => ''], 382 => 'WHOLE'];
        }
        public function getUniqueEventNames() { return array_map(function ($r) { return $r['unique_event_name']; }, $this->eventInfo); }
    }

    require_once __DIR__ . '/../UniversalValidator.php';
    require_once __DIR__ . '/support/scan_facade.php';
    require_once __DIR__ . '/support/ArrayScanStore.php';
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
    $LABELS = []; $TYPES = []; $NOTES = [];
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
        $NOTES[$f] = $r['Field Note'];
        $TYPES[$f] = ['type' => $r['Field Type'], 'validation' => $r['Text Validation Type OR Show Slider Number'],
            'choices' => $r['Choices, Calculations, OR Slider Labels']];
    }
    fclose($fh);
    check('the fixture holds 13 rows', $read === 13, $read);
    check('the field notes name no role and no "since" date: REDCap shows them on the page, where B2 looks for neither',
        !array_filter($NOTES, function ($t) { return stripos($t, 'data manager') !== false || strpos($t, '2099') !== false; }), $NOTES);
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
    /**
     * A save of the form or an import: the row REDCap writes, its fields as
     * "f = 'v'" lines after an "[instance = N]" line past the first instance.
     * $kind 'DELETE' with its description is a deletion.
     */
    function logSave($at, array $values, $user = 'tester', $record = 'XE-3', $event = 380, $instance = 1, $kind = 'UPDATE', $descr = null) {
        global $LOG;
        $lines = $instance > 1 ? ['[instance = ' . (int) $instance . ']'] : [];
        foreach ($values as $f => $v) $lines[] = $f . " = '" . str_replace("'", "\\'", (string) $v) . "'";
        $row = [count($LOG) + 1, 149, $record, $event, $at, implode(",\n", $lines), 'redcap_data', $kind, $user];
        if ($descr !== null) $row[] = $descr;
        $LOG[] = $row;
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
        // the reader told what the module tells it: the dictionary's order, its free-text
        // fields, the record id field and the arms (review W2-4: the order check runs here too)
        $vo = new \ReflectionMethod(get_class($m), 'valueHistoryOptions');
        $vo->setAccessible(true);
        setPrivate($m, 'valueHistoryOverride', ValueHistory::forProject($db, 149, $WO, $utc, $utc, $vo->invoke($m, 149)));
        setPrivate($m, 'roleBookOverride', $roles);
        setPrivate($m, 'clockOverride', \INSPIRE\UniversalValidator\UniversalValidator::clockStamp(new \DateTimeImmutable('2026-10-23 10:00:00', $utc)));
        return $m;
    }
    function page($m, $record = 'XE-3', $event = 380, $instance = 1) {
        ob_start();
        $m->redcap_data_entry_form_top(149, $record, 'uv_writeonce_test', $event, null, $instance);
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
    /** "field@event" for each refused rule ("configuration error:") among log entries, sorted. */
    function refusals(array $calls) {
        $out = [];
        foreach ($calls as $c) {
            if ($c[0] === 'uvalidate-unconfigurable' && strpos((string) ($c[1]['why'] ?? ''), 'configuration error:') === 0) $out[] = $c[1]['fields'] . '@' . $c[1]['event_id'];
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
    /** One save, over the module log the saves before it left (PRF-9: a finding logged once is not logged again). */
    function save(array $data, array $roles, $record = 'XE-3', $event = 380, $instance = 1) {
        $m = mod($data, $roles);
        $m->redcap_save_record(149, $record, 'uv_writeonce_test', $event, null, null, null, $instance);
        return $m;
    }
    /** One save over an empty module log: it logs every finding and rule problem the audit makes. */
    function firstSave(array $data, array $roles, $record = 'XE-3', $event = 380, $instance = 1) {
        \ExternalModules\AbstractExternalModule::$stored = [];
        return save($data, $roles, $record, $event, $instance);
    }
    /** What a save finds: the same save over an empty module log, which is then put back as it was. */
    function found(array $data, array $roles, $record = 'XE-3', $event = 380, $instance = 1) {
        $keep = \ExternalModules\AbstractExternalModule::$stored;
        $m = firstSave($data, $roles, $record, $event, $instance);
        \ExternalModules\AbstractExternalModule::$stored = $keep;
        return $m;
    }
    function surveyPage($m, $record) {
        ob_start();
        $m->redcap_survey_page_top(149, $record, 'uv_writeonce_test', 380, null, 'hash', null, 1);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return ['html' => $html, 'cfg' => json_decode($mm[1] ?? 'null', true)];
    }
    /** "record@event#instance field:reason asOf" for each write-once finding of the scan, and the rule problems of the good fields */
    function scanAt(array $data, array $roles, &$problems = null) {
        $m = mod($data, $roles);
        $res = $m->scanProject(149);
        $out = []; $problems = [];
        foreach ($res['violations'] as $v) {
            if ($v['type'] !== 'writeonce') continue;
            $out[] = $v['record'] . '@' . $v['event_id'] . '#' . ($v['instance'] ?? 1) . ' ' . $v['field'] . ':' . $v['reason'] . ' ' . ($v['asOf'] ?? '?');
        }
        sort($out);
        foreach ($res['unconfigurable'] as $u) {
            if (!array_filter($u['fields'], function ($f) { return strpos($f, 'uw_bad_') === false; })) continue;
            $problems[] = $u;
        }
        return $out;
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
    // the fixture import made the records: REDCap logs each as an INSERT with its record id line
    logSave('20261020090000', ['record_id' => 'XE-3'], 'tester', 'XE-3', 351, 1, 'INSERT');
    logSave('20261020090000', ['record_id' => 'XE-4'], 'tester', 'XE-4', 351, 1, 'INSERT');

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
    // the note runs over two lines: the log writes it whole, and the reader reads it whole
    $B1 = ['uw_consent' => '2026-10-01', 'uw_arm' => '1', 'uw_yn' => '1', 'uw_notes' => "First note\nsecond line", 'uw_pain' => '50', 'uw_weight' => '70.0', 'uw_free' => 'a'];
    $D['XE-3'][380] = $B1;
    logSave('20261023090000', $B1 + ['uv_writeonce_test_complete' => '0']);
    $m = save($D, $ROLE_OUT);
    check('B1: the first save logs no write-once finding and no rule problem', hits($m) === [] && problems($m) === [], [hits($m), problems($m)]);
    $BAD = ['uw_bad_calc', 'uw_bad_role', 'uw_bad_setvalue', 'uw_bad_value', 'uw_bad_when'];
    $at = function ($evt) use ($BAD) { return array_map(function ($f) use ($evt) { return $f . '@' . $evt; }, $BAD); };
    check('B1: ... and, as the form\'s first save at the visit, the five refused rules, once each (VAL-15, PRF-9)', refusals($m->logCalls) === $at(380), refusals($m->logCalls));
    $p = $opened('b2', $D, $ROLE_OUT);
    check('B2: reopened, every saved field with the tag is locked; the blank site, the weight (lock from 2099) and the untagged field are not',
        lockedOn($p) === ['uw_arm', 'uw_consent', 'uw_notes', 'uw_pain', 'uw_yn'], lockedOn($p));
    check('B2: the roles and "since" never reach the page', strpos($p['html'], 'UV Data manager') === false && stripos($p['html'], 'u-149uvdm') === false
        && strpos($p['html'], '2099') === false && strpos($p['html'], 'writeonceRoles') === false);
    // B2d: Enter saves the form with every value as it was, so REDCap logs nothing
    $m = save($D, $ROLE_OUT);
    check('B2d: an Enter save with the date put back: no finding and no rule problem', hits($m) === [] && problems($m) === [], [hits($m), problems($m)]);
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
    // B6: the rule on the locked note reads the free text, which the person may change
    $p = $opened('b6', $D, $ROLE_OUT);
    $assertOn = function ($p, $f) {
        foreach ($p['cfg']['rules'] ?? [] as $r) if (($r['type'] ?? '') === 'constraint' && in_array($f, $r['fields'] ?? [], true)) return $r;
        return null;
    };
    check('B6/B7: the rule on the locked note names the free text it reads', ($assertOn($p, 'uw_notes')['lockReads'] ?? null) === ['uw_notes' => ['uw_free']], $assertOn($p, 'uw_notes'));
    $D['XE-3'][380]['uw_free'] = 'ok';
    logSave('20261023091100', ['uw_free' => 'ok']);
    $m = save($D, $ROLE_OUT);
    check('B6: the free text put right saves: no finding and no rule problem', hits($m) === [] && problems($m) === [], [hits($m), problems($m)]);
    // B7: an import breaks the rule before the page opens
    $D['XE-3'][380]['uw_free'] = 'stop';
    logSave('20261023091200', ['uw_free' => 'stop']);
    $opened('b7', $D, $ROLE_OUT);
    $m = save($D, $ROLE_OUT);
    check('B7: the Enter save logs no write-once finding and no rule problem', hits($m) === [] && problems($m) === [], [hits($m), problems($m)]);
    $D['XE-3'][380]['uw_free'] = 'ok';
    logSave('20261023091300', ['uw_free' => 'ok']);
    $m = save($D, $ROLE_OUT);
    check('B7b: the free text put right: no finding and no rule problem', hits($m) === [] && problems($m) === [], [hits($m), problems($m)]);

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
    $d2 = found($D, $ROLE_IN);
    check('D2: the arm changed by the role: no finding; the import of the site now counts as the role\'s', hits($d2) === [
        'uw_consent:changed 2026-10-23 09:00 > 2026-10-23 09:20', 'uw_notes:cleared 2026-10-23 09:00 > 2026-10-23 09:20'], hits($d2));
    check('D2: the two findings left are the ones C3 logged, so the save writes no entry (PRF-9)', hits($m) === [], hits($m));
    $got = scan($D, $ROLE_IN);
    check('D3: the scan agrees', $got === ['uw_consent:changed 2026-10-23 09:00', 'uw_notes:cleared 2026-10-23 09:00'], $got);
    $p = $opened('d4', $D, $ROLE_OUT);
    check('D4: out of the role, the arm is locked at its new value', lockedOn($p) === ['uw_arm', 'uw_consent', 'uw_pain', 'uw_site', 'uw_yn'], lockedOn($p));
    $got = scan($D, $ROLE_OUT);
    check('D4: the scan reads the roles as they are now, so the arm and the site count as changes again', $got === [
        'uw_arm:changed 2026-10-23 09:00', 'uw_consent:changed 2026-10-23 09:00', 'uw_notes:cleared 2026-10-23 09:00', 'uw_site:changed 2026-10-23 09:05'], $got);

    // ---- Section E: the Data Import Tool on a whole repeating event (review L14) -------------------
    $D['XE-3']['repeat_instances'][382][''][1] = ['uw_consent' => '2026-10-03'];
    logSave('20261023094000', ['uw_consent' => '2026-10-03', 'uv_writeonce_test_complete' => '0'], 'tester', 'XE-3', 382, 1);
    $m = save($D, $ROLE_OUT, 'XE-3', 382, 1);
    check('E1: the first save at instance 1 of the repeating event: no finding', hits($m) === [] && problems($m) === [], [hits($m), problems($m)]);
    check('E1: ... and the five refused rules, logged by the form\'s first save at this event', refusals($m->logCalls) === $at(382), refusals($m->logCalls));
    check('E1: reopened, it is locked', lockedOn(page(mod($D, $ROLE_OUT), 'XE-3', 382, 1)) === ['uw_consent']);
    $D['XE-3']['repeat_instances'][382][''][1]['uw_consent'] = '2026-10-04';
    $D['XE-3']['repeat_instances'][382][''][2] = ['uw_consent' => '2026-10-09'];
    logSave('20261023095000', ['uw_consent' => '2026-10-04'], 'tester', 'XE-3', 382, 1);
    logSave('20261023095000', ['uw_consent' => '2026-10-09'], 'tester', 'XE-3', 382, 2);
    $got = scanAt($D, $ROLE_OUT, $prob);
    check('E2: the scan finds the import at instance 1; instance 2, first saved by the import, is locked by it', $got === [
        'XE-3@380#1 uw_arm:changed 2026-10-23 09:00', 'XE-3@380#1 uw_consent:changed 2026-10-23 09:00', 'XE-3@380#1 uw_notes:cleared 2026-10-23 09:00',
        'XE-3@380#1 uw_site:changed 2026-10-23 09:05', 'XE-3@382#1 uw_consent:changed 2026-10-23 09:40'] && $prob === [], [$got, $prob]);
    check('E3: instance 2 opens locked at the imported date', lockedOn(page(mod($D, $ROLE_OUT), 'XE-3', 382, 2)) === ['uw_consent']);
    $m = save($D, $ROLE_OUT, 'XE-3', 382, 1);
    check('E4: saving instance 1 as it is logs the change, at event 382', hits($m) === ['uw_consent:changed 2026-10-23 09:40 > 2026-10-23 09:50'], hits($m));

    // ---- Section G: a survey, whose first page Next saves -----------------------------------------
    $D['XE-4'] = [351 => ['record_id' => 'XE-4'], 380 => []];
    $ps = surveyPage(mod($D, $ROLE_OUT), 'XE-4');
    check('G1: the survey for XE-4 opens with nothing locked', lockedOn($ps) === [], lockedOn($ps));
    $G2 = ['uw_consent' => '2026-10-05', 'uw_arm' => '1', 'uw_free' => 's'];
    $D['XE-4'][380] = $G2;
    logSave('20261023100000', $G2 + ['uv_writeonce_test_complete' => '0'], '[survey respondent]', 'XE-4');
    $m = save($D, $ROLE_OUT, 'XE-4');
    check('G2: Next saves the first page: no finding', hits($m) === [] && problems($m) === [], [hits($m), problems($m)]);
    $ps = surveyPage(mod($D, $ROLE_OUT), 'XE-4');
    $pages['g3'] = $ps['cfg'];
    check('G3: Previous finds the answers locked, the role field too',
        lockedOn($ps) === ['uw_arm', 'uw_consent'], lockedOn($ps));
    check('G3: the survey page names no role', stripos($ps['html'], 'data manager') === false && stripos($ps['html'], 'u-149uvdm') === false);
    check('G3: a respondent holds no role, even on a login that holds one', lockedOn(surveyPage(mod($D, $ROLE_IN), 'XE-4')) === ['uw_arm', 'uw_consent']);
    // Submit saves the second page: REDCap logs only the form status, which changed
    logSave('20261023100500', ['uv_writeonce_test_complete' => '2'], '[survey respondent]', 'XE-4');
    $m = save($D, $ROLE_OUT, 'XE-4');
    check('G4: Submit saves the second page: no finding and no rule problem', hits($m) === [] && problems($m) === [], [hits($m), problems($m)]);

    // ---- Section H: a deletion of the form's data, which the check cannot place -------------------
    logSave('20261023101000', ['uw_consent' => '', 'uw_arm' => '', 'uw_free' => ''], 'tester', 'XE-4', 380, 1, 'DELETE', 'Delete all record data for single form');
    $D['XE-4'][380] = [];
    check('H1: after the deletion the form opens with nothing locked', lockedOn(page(mod($D, $ROLE_OUT), 'XE-4')) === []);
    $D['XE-4'][380] = ['uw_consent' => '2026-10-06'];
    logSave('20261023101500', ['uw_consent' => '2026-10-06', 'uv_writeonce_test_complete' => '0'], 'tester', 'XE-4');
    $m = save($D, $ROLE_OUT, 'XE-4');
    $why = implode(' ', array_map(function ($p) { return (string) ($p['why'] ?? ''); }, problems($m)));
    check('H2: the next save logs no finding, and a rule problem for the consent date that names the deletion', hits($m) === []
        && strpos($why, 'the project log holds a deletion this check cannot place (part of the record, or one arm of it) — field not checked') !== false, [hits($m), problems($m)]);
    $got = scanAt($D, $ROLE_OUT, $prob);
    check('H3: the scan lists XE-4\'s consent date as not checked, never as a pass or a finding',
        !array_filter($got, function ($g) { return strpos($g, 'XE-4') === 0; })
        && (bool) array_filter($prob, function ($u) { return in_array('uw_consent', $u['fields'], true) && strpos((string) ($u['why'] ?? ''), 'deletion') !== false; }), [$got, $prob]);
    $all = refusals(\ExternalModules\AbstractExternalModule::$stored);
    $want = array_merge($at(380), $at(382));
    sort($want);
    check('A to H: the module log holds the refused rules of B1 and of E1, and no save logged them again', $all === $want, $all);

    // ---- Section I: what REDCap writes to its log, as far as the module's answer goes ------------
    // Each step reads what REDCap logs on the real server; the twin checks what the module answers for
    // the shape the sheet expects, and for the other shape the step says to record. Each runs on its own
    // copy of the log, so the steps above are left as they are, and its first save on an empty module log.
    $KEEP = $LOG;
    $one = function ($rec, array $values) { return [$rec => [351 => ['record_id' => $rec], 380 => $values]]; };
    $why = function (array $prob, $f) {
        $out = [];
        // the scan lists a problem's fields, the audit's log entry names them in one text
        foreach ($prob as $u) if (in_array($f, is_array($u['fields']) ? $u['fields'] : preg_split('/[\s,]+/', (string) $u['fields']), true)) $out[] = (string) ($u['why'] ?? '');
        return implode(' | ', $out);
    };
    // I0: the row that made a record, its lines in another order than today's data dictionary (a field moved
    // since): the record is still read from there (review W4-1). From the first Text or Notes value before the
    // line out of order the row's values are not plain; the record id line is no such value (review W5-2).
    $LOG = $KEEP;
    logSave('20261023102000', ['record_id' => 'XE-M', 'uw_consent' => '2026-10-07', 'uw_free' => 'f', 'uw_arm' => '1'], 'tester', 'XE-M', 380, 1, 'INSERT');
    logSave('20261023102100', ['uw_notes' => 'n'], 'tester', 'XE-M', 380);
    logSave('20261023102200', ['uw_notes' => 'm'], 'tester', 'XE-M', 380);
    $got = scanAt($one('XE-M', ['uw_arm' => '1', 'uw_consent' => '2026-10-07', 'uw_free' => 'f', 'uw_notes' => 'm']), $ROLE_OUT, $prob);
    check('I0: a creating row out of today\'s dictionary order still starts the record: a later change is found',
        $got === ['XE-M@380#1 uw_notes:changed 2026-10-23 10:21'], [$got, $prob]);
    check('I0: ... and the fields that row wrote from its first Text value on are not checked, as not spelled out, never as a record not made',
        strpos($why($prob, 'uw_consent'), 'does not say plainly') !== false && strpos($why($prob, 'uw_arm'), 'does not say plainly') !== false
        && strpos(json_encode($prob), 'record being made') === false, $prob);
    $LOG = $KEEP;
    logSave('20261023102000', ['record_id' => 'XE-M', 'uw_arm' => '1', 'uw_consent' => '2026-10-07'], 'tester', 'XE-M', 380, 1, 'INSERT');
    logSave('20261023102100', ['uw_arm' => '2', 'uw_consent' => '2026-10-08'], 'tester', 'XE-M', 380);
    $got = scanAt($one('XE-M', ['uw_arm' => '2', 'uw_consent' => '2026-10-08']), $ROLE_OUT, $prob);
    check('I0: a line out of order that follows only the record id line and a radio button\'s is read: both changes are found (review W5-2)',
        $got === ['XE-M@380#1 uw_arm:changed 2026-10-23 10:20', 'XE-M@380#1 uw_consent:changed 2026-10-23 10:20'] && $prob === [], [$got, $prob]);
    // A field moved after the record was made (the fifth review's module probe): uw_free, which no tag names,
    // moved above uw_arm in the data dictionary, then an import changes a locked field (review W5-2).
    $moved = function (array $made, array $import, array $now) use (&$LOG, $KEEP, &$dict, $one, $ROLE_OUT) {
        $LOG = $KEEP;
        logSave('20261023102300', $made, 'tester', 'XE-9', 380, 1, 'INSERT');
        $saved = $dict;
        $new = [];
        foreach ($dict as $f => $meta) {
            if ($f === 'uw_free') continue;
            if ($f === 'uw_arm') $new['uw_free'] = $dict['uw_free'];
            $new[$f] = $meta;
        }
        $dict = $new;
        logSave('20261023102400', $import, 'tester', 'XE-9', 380);
        $m = firstSave($one('XE-9', $now), $ROLE_OUT, 'XE-9');
        $dict = $saved;
        return $m;
    };
    $m = $moved(['record_id' => 'XE-9', 'uw_consent' => '2026-10-01', 'uw_arm' => '1', 'uw_free' => 'a'], ['uw_consent' => '2026-10-02'],
        ['uw_consent' => '2026-10-02', 'uw_arm' => '1', 'uw_free' => 'a']);
    check('I0, a field moved: a Text locked field the creating row wrote before the line out of order is not checked, as not spelled out',
        hits($m) === [] && strpos($why(problems($m), 'uw_consent'), 'does not say plainly') !== false, [hits($m), problems($m)]);
    $m = $moved(['record_id' => 'XE-9', 'uw_arm' => '1', 'uw_site' => '2', 'uw_free' => 'a'], ['uw_arm' => '2'],
        ['uw_arm' => '2', 'uw_site' => '2', 'uw_free' => 'a']);
    check('I0, a field moved: a radio button locked before any Text value of the creating row is judged: the import is found',
        hits($m) === ['uw_arm:changed 2026-10-23 10:23 > 2026-10-23 10:24'] && problems($m) === [], [hits($m), problems($m)]);
    // I0b: a record made from a form with an embedded field ({uw_free} in uw_consent's label), then an import.
    $made = function (array $lines) use (&$LOG, $KEEP, $one, $ROLE_OUT, &$prob) {
        $LOG = $KEEP;
        logSave('20261023103000', $lines, 'tester', 'XE-E', 380, 1, 'INSERT');
        logSave('20261023103100', ['uw_consent' => '2026-10-08'], 'tester', 'XE-E', 380);
        return scanAt($one('XE-E', ['uw_consent' => '2026-10-08', 'uw_notes' => 'n', 'uw_free' => 'e']), $ROLE_OUT, $prob);
    };
    $got = $made(['record_id' => 'XE-E', 'uw_consent' => '2026-10-07', 'uw_notes' => 'n', 'uw_free' => 'e', 'uv_writeonce_test_complete' => '0']);
    check('I0b: the creating row in the dictionary\'s order: the import is found', $got === ['XE-E@380#1 uw_consent:changed 2026-10-23 10:30'] && $prob === [], [$got, $prob]);
    $got = $made(['record_id' => 'XE-E', 'uw_consent' => '2026-10-07', 'uw_free' => 'e', 'uw_notes' => 'n', 'uv_writeonce_test_complete' => '0']);
    check('I0b: the embedded field written straight after its host: the record is started, the row\'s fields are not checked as not spelled out',
        $got === [] && strpos($why($prob, 'uw_consent'), 'does not say plainly') !== false && strpos(json_encode($prob), 'record being made') === false, [$got, $prob]);
    $got = $made(['uw_consent' => '2026-10-07', 'record_id' => 'XE-E', 'uw_notes' => 'n', 'uw_free' => 'e']);
    check('I0b: the record id line after a Text value: every field not checked, saying the row could not be read plainly',
        $got === [] && strpos($why($prob, 'uw_consent'), 'the row of the project log that made the record could not be read plainly') !== false
        && strpos($why($prob, 'uw_arm'), 'could not be read plainly') !== false, [$got, $prob]);
    // I1: an import with its columns out of order. REDCap writes the row in the dictionary's order, or the
    // two fields read as not spelled out (the other shape the step names).
    $imp = function (array $lines) use (&$LOG, $KEEP, $one, $ROLE_OUT, &$prob) {
        $LOG = $KEEP;
        logSave('20261020090000', ['record_id' => 'XE-1'], 'tester', 'XE-1', 351, 1, 'INSERT');
        logSave('20261023102400', ['uw_consent' => '2026-10-07', 'uw_notes' => 'first'], 'tester', 'XE-1', 380);
        logSave('20261023102500', $lines, 'tester', 'XE-1', 380);
        return scanAt($one('XE-1', ['uw_consent' => '2026-10-08', 'uw_notes' => 'second']), $ROLE_OUT, $prob);
    };
    $got = $imp(['uw_consent' => '2026-10-08', 'uw_notes' => 'second']);
    check('I1: the import row in the dictionary\'s order: two findings, locked at I1\'s save',
        $got === ['XE-1@380#1 uw_consent:changed 2026-10-23 10:24', 'XE-1@380#1 uw_notes:changed 2026-10-23 10:24'] && $prob === [], [$got, $prob]);
    $got = $imp(['uw_notes' => 'second', 'uw_consent' => '2026-10-08']);
    check('I1: ... the row in the CSV\'s order: no finding, and the consent date not checked, as not spelled out',
        $got === [] && strpos($why($prob, 'uw_consent'), 'does not say plainly') !== false, [$got, $prob]);   // the scan names a rule's fields once per reason
    // I1b (and I1c, its API JSON form): the reversed CSV makes a new record, XE-N, then an import changes its
    // consent date. The creating row has the record id line first, or last as the CSV has it (review W5-5).
    $newImp = function (array $lines) use (&$LOG, $KEEP, $one, $ROLE_OUT, &$prob) {
        $LOG = $KEEP;
        logSave('20261023102800', $lines, 'tester', 'XE-N', 380, 1, 'INSERT');
        logSave('20261023102900', ['uw_consent' => '2026-10-08'], 'tester', 'XE-N', 380);
        return scanAt($one('XE-N', ['uw_consent' => '2026-10-08', 'uw_notes' => 'first']), $ROLE_OUT, $prob);
    };
    $got = $newImp(['record_id' => 'XE-N', 'uw_consent' => '2026-10-07', 'uw_notes' => 'first']);
    check('I1b: record id first, then the dictionary\'s order: the change is found, locked at the import that made the record',
        $got === ['XE-N@380#1 uw_consent:changed 2026-10-23 10:28'] && $prob === [], [$got, $prob]);
    $got = $newImp(['record_id' => 'XE-N', 'uw_notes' => 'first', 'uw_consent' => '2026-10-07']);
    check('I1b: record id first, then the CSV\'s order: the record is started, and the consent date is not checked, as not spelled out',
        $got === [] && strpos($why($prob, 'uw_consent'), 'does not say plainly') !== false && strpos(json_encode($prob), 'record being made') === false, [$got, $prob]);
    $got = $newImp(['uw_notes' => 'first', 'uw_consent' => '2026-10-07', 'record_id' => 'XE-N']);
    check('I1b: the CSV\'s order with the record id last: not checked, saying the row that made the record could not be read plainly',
        $got === [] && strpos($why($prob, 'uw_consent'), 'the row of the project log that made the record could not be read plainly') !== false, [$got, $prob]);
    $got = $newImp(['uw_consent' => '2026-10-07', 'uw_notes' => 'first', 'record_id' => 'XE-N']);
    check('I1b: the dictionary\'s order with the record id last: the same reason',
        $got === [] && strpos($why($prob, 'uw_consent'), 'the row of the project log that made the record could not be read plainly') !== false, [$got, $prob]);
    // I2: a save of a form with an embedded field ({uw_free} in uw_consent's label), then an import.
    $emb = function (array $lines) use (&$LOG, $KEEP, $one, $ROLE_OUT, &$prob) {
        $LOG = $KEEP;
        logSave('20261020090000', ['record_id' => 'XE-2'], 'tester', 'XE-2', 351, 1, 'INSERT');
        logSave('20261023102600', $lines, 'tester', 'XE-2', 380);
        logSave('20261023102700', ['uw_consent' => '2026-10-08'], 'tester', 'XE-2', 380);
        return scanAt($one('XE-2', ['uw_consent' => '2026-10-08', 'uw_notes' => 'n', 'uw_free' => 'e']), $ROLE_OUT, $prob);
    };
    $got = $emb(['uw_consent' => '2026-10-07', 'uw_notes' => 'n', 'uw_free' => 'e', 'uv_writeonce_test_complete' => '0']);
    check('I2: the save lists the embedded field in the dictionary\'s order: the import is one finding', $got === ['XE-2@380#1 uw_consent:changed 2026-10-23 10:26'] && $prob === [], [$got, $prob]);
    $got = $emb(['uw_consent' => '2026-10-07', 'uw_free' => 'e', 'uw_notes' => 'n', 'uv_writeonce_test_complete' => '0']);
    check('I2: ... the embedded field straight after its host: the consent date is not checked, with the reason of I1',
        $got === [] && strpos($why($prob, 'uw_consent'), 'does not say plainly') !== false, [$got, $prob]);
    // I3 and I3b: a renamed record, read both ways REDCap may log it.
    $LOG = $KEEP;
    logSave('20261023104000', ['record_id' => 'XE-R1', 'uw_consent' => '2026-10-07', 'uv_writeonce_test_complete' => '0'], 'tester', 'XE-R1', 380, 1, 'INSERT');
    logSave('20261023104100', ['record_id' => 'XE-R2'], 'tester', 'XE-R2', 380);
    $m = firstSave($one('XE-R2', ['uw_consent' => '2026-10-07']), $ROLE_OUT, 'XE-R2');
    check('I3 (a): the old rows stay under XE-R1: the save of XE-R2 is no finding, and a rule problem for the record not made',
        hits($m) === [] && strpos($why(problems($m), 'uw_consent'), 'the project log does not show the record being made') !== false, [hits($m), problems($m)]);
    check('I3: XE-R2 opens locked at the saved date', lockedOn(page(mod($one('XE-R2', ['uw_consent' => '2026-10-07']), $ROLE_OUT), 'XE-R2')) === ['uw_consent']);
    logSave('20261023104500', ['uw_consent' => '2026-10-08'], 'tester', 'XE-R2', 380);
    $got = scanAt($one('XE-R2', ['uw_consent' => '2026-10-08']), $ROLE_OUT, $prob);
    check('I3b (a): the import after the rename is not checked, for the same reason, never passed', $got === []
        && strpos($why($prob, 'uw_consent'), 'the project log does not show the record being made') !== false, [$got, $prob]);
    $LOG = $KEEP;
    logSave('20261023104000', ['record_id' => 'XE-R1', 'uw_consent' => '2026-10-07', 'uv_writeonce_test_complete' => '0'], 'tester', 'XE-R2', 380, 1, 'INSERT');
    logSave('20261023104100', ['record_id' => 'XE-R2'], 'tester', 'XE-R2', 380);
    $m = firstSave($one('XE-R2', ['uw_consent' => '2026-10-07']), $ROLE_OUT, 'XE-R2');
    check('I3 (b): the old rows moved to XE-R2: no finding and no rule problem', hits($m) === [] && problems($m) === [], [hits($m), problems($m)]);
    logSave('20261023104500', ['uw_consent' => '2026-10-08'], 'tester', 'XE-R2', 380);
    $got = scanAt($one('XE-R2', ['uw_consent' => '2026-10-08']), $ROLE_OUT, $prob);
    check('I3b (b): the import after the rename is found, locked at I3\'s save', $got === ['XE-R2@380#1 uw_consent:changed 2026-10-23 10:40'] && $prob === [], [$got, $prob]);
    // I5: an erase of all records writes one row for the project (pk = the project id, no event).
    $LOG = [];
    logSave('20261023105000', ['record_id' => 'XE-1', 'uw_consent' => '2026-10-07'], 'tester', 'XE-1', 380, 1, 'INSERT');
    logSave('20261023105100', [], 'tester', '149', null, 1, 'DELETE', 'Erase all data');
    logSave('20261023105200', ['record_id' => 'XE-1', 'uw_consent' => '2026-10-08'], 'tester', 'XE-1', 380, 1, 'INSERT');
    $m = firstSave($one('XE-1', ['uw_consent' => '2026-10-08']), $ROLE_OUT, 'XE-1');
    check('I5: the save after the erase writes no entry: the save before it no longer counts', hits($m) === [] && problems($m) === [], [hits($m), problems($m)]);
    logSave('20261023105300', ['uw_consent' => '2026-10-09'], 'tester', 'XE-1', 380);
    $m = save($one('XE-1', ['uw_consent' => '2026-10-09']), $ROLE_OUT, 'XE-1');
    check('I5: the import after it is a change, locked at the second save', hits($m) === ['uw_consent:changed 2026-10-23 10:52 > 2026-10-23 10:53'], hits($m));
    // I5b: the production move's "delete all records", on a copy. Either shape the step lets REDCap write
    // starts every history: one row for the project in its own words, or one "Delete record" row per record.
    foreach (['one row for the project' => ['149', null, 'Delete all records'], 'one row per record' => ['XE-2', 380, 'Delete record']] as $shape => $del) {
        $LOG = [];
        logSave('20261023105500', ['record_id' => 'XE-2', 'uw_consent' => '2026-10-07'], 'tester', 'XE-2', 380, 1, 'INSERT');
        logSave('20261023105600', [], 'tester', $del[0], $del[1], 1, 'DELETE', $del[2]);
        logSave('20261023105700', ['record_id' => 'XE-2', 'uw_consent' => '2026-10-08'], 'tester', 'XE-2', 380, 1, 'INSERT');
        $m = firstSave($one('XE-2', ['uw_consent' => '2026-10-08']), $ROLE_OUT, 'XE-2');
        check("I5b ($shape): the save after the production move writes no entry", hits($m) === [] && problems($m) === [], [hits($m), problems($m)]);
        logSave('20261023105800', ['uw_consent' => '2026-10-09'], 'tester', 'XE-2', 380);
        $m = save($one('XE-2', ['uw_consent' => '2026-10-09']), $ROLE_OUT, 'XE-2');
        check("I5b ($shape): the import after it is a change, locked at the save after the move", hits($m) === ['uw_consent:changed 2026-10-23 10:57 > 2026-10-23 10:58'], hits($m));
    }
    // I6: one instance of the repeating event deleted (XE-3, unscheduled_arm_1 instance 2). A description that
    // says one event leaves the visit's history as it was; one with more after it cannot be placed.
    $inst = function ($descr) use (&$LOG, $KEEP, $D, $ROLE_OUT, &$prob) {
        $LOG = $KEEP;
        logSave('20261023110000', [], 'tester', 'XE-3', 382, 2, 'DELETE', $descr);
        $d = $D;
        unset($d['XE-3']['repeat_instances'][382][''][2]);
        return scanAt(['XE-3' => $d['XE-3']], $ROLE_OUT, $prob);
    };
    // H4's three wordings are the ones passed over at another event
    foreach (['Delete all record data for single event', 'Delete all record data for single form', 'Delete all data on event'] as $descr) {
        $got = $inst($descr);
        check("H4, I6: an instance deleted at the repeating event, described as \"$descr\": the visit's findings stand, the repeating event is not checked",
            $got === ['XE-3@380#1 uw_arm:changed 2026-10-23 09:00', 'XE-3@380#1 uw_consent:changed 2026-10-23 09:00',
            'XE-3@380#1 uw_notes:cleared 2026-10-23 09:00', 'XE-3@380#1 uw_site:changed 2026-10-23 09:05'] && strpos($why($prob, 'uw_consent'), 'deletion') !== false, [$got, $prob]);
    }
    $got = $inst('Delete all record data for single event (instance 2)');
    check('I6: ... described with more after it (an instance number, say): every event of the record is not checked',
        $got === [] && strpos($why($prob, 'uw_arm'), 'holds a deletion this check cannot place') !== false, [$got, $prob]);
    // I7: a locked field renamed after it holds data (review W4-3). uw_consent stands for the sheet's
    // uw_ren2, and "uw_consent_old", which the data dictionary no longer has, for uw_ren. (a) The log keeps
    // the earlier save under the old name: the page still locks, the audit reports the field not checked,
    // and an import before any save under the new name is taken for its first save. (b) The log shows the
    // earlier save under the new name: the import is a finding.
    $ren = function ($name) use (&$LOG, $KEEP) {
        $LOG = $KEEP;
        logSave('20261020090000', ['record_id' => 'XE-1'], 'tester', 'XE-1', 351, 1, 'INSERT');
        logSave('20261023111000', [$name => '2026-10-07', 'uv_writeonce_test_complete' => '0'], 'tester', 'XE-1', 380);
    };
    $ren('uw_consent_old');
    check('I7: the renamed field opens locked at the saved value', lockedOn(page(mod($one('XE-1', ['uw_consent' => '2026-10-07']), $ROLE_OUT), 'XE-1')) === ['uw_consent']);
    $m = firstSave($one('XE-1', ['uw_consent' => '2026-10-07']), $ROLE_OUT, 'XE-1');
    check('I7 (a): the save after the rename is no finding, and a rule problem: the log does not show the save of the value it holds',
        hits($m) === [] && strpos($why(problems($m), 'uw_consent'), 'the project log does not show the save of the value it holds') !== false, [hits($m), problems($m)]);
    logSave('20261023111100', ['uw_consent' => '2026-10-08'], 'tester', 'XE-1', 380);
    $got = scanAt($one('XE-1', ['uw_consent' => '2026-10-08']), $ROLE_OUT, $prob);
    check('I7 (a): the import after the rename is taken for the first save under the new name: no finding and no rule problem (the documented limit)',
        $got === [] && $why($prob, 'uw_consent') === '', [$got, $prob]);
    $ren('uw_consent');
    $m = firstSave($one('XE-1', ['uw_consent' => '2026-10-07']), $ROLE_OUT, 'XE-1');
    check('I7 (b): the earlier save shown under the new name: no finding and no rule problem', hits($m) === [] && problems($m) === [], [hits($m), problems($m)]);
    logSave('20261023111100', ['uw_consent' => '2026-10-08'], 'tester', 'XE-1', 380);
    $got = scanAt($one('XE-1', ['uw_consent' => '2026-10-08']), $ROLE_OUT, $prob);
    check('I7 (b): ... and the import is a finding', $got === ['XE-1@380#1 uw_consent:changed 2026-10-23 11:10'] && $prob === [], [$got, $prob]);
    $LOG = $KEEP;

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
