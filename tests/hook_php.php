<?php
/**
 * hook_php.php — server-side audit (redcap_save_record) regression tests.
 *
 * The check-character engine and parsers have parity tests; the REDCap glue that
 * actually drives the audit did NOT, which is how a live gap (an import that
 * produced no audit log) went unnoticed. This test mocks the External Modules
 * framework and the REDCap class so the whole redcap_save_record path — rule
 * loading from both channels, value reading, validation, and logging — can be
 * exercised without a REDCap runtime.
 *
 * The mocks are deliberately strict about project context: getSubSettings and
 * getProjectSetting return NOTHING unless a resolvable project id reaches them
 * (explicit argument, or getProjectId()). That models production import/API
 * contexts, where getProjectId() is null — the module MUST thread the hook's
 * project_id explicitly or these tests fail (SEC-002).
 *
 * Run:  php tests/hook_php.php
 */

namespace ExternalModules {
    // Minimal stand-in for the framework base class. Test scripts set the public
    // fixtures; the module calls these methods exactly as in production.
    class AbstractExternalModule {
        public $logCalls = [];
        public $subSettings = [];
        public $projectSettings = [];
        public $systemSettings = [];
        public $projectIdReturn = null;      // what getProjectId() returns (null models import/API)
        public $settingReads = [];           // records [method, key, pid] for threading assertions
        public function getSubSettings($key, $pid = null) {
            $this->settingReads[] = ['getSubSettings', $key, $pid];
            $effective = ($pid !== null && $pid !== '') ? $pid : $this->projectIdReturn;
            return $effective ? $this->subSettings : [];
        }
        public function getProjectSetting($key, $pid = null) {
            $this->settingReads[] = ['getProjectSetting', $key, $pid];
            $effective = ($pid !== null && $pid !== '') ? $pid : $this->projectIdReturn;
            if (!$effective) return null;
            return isset($this->projectSettings[$key]) ? $this->projectSettings[$key] : null;
        }
        public function getSystemSetting($key) {
            return isset($this->systemSettings[$key]) ? $this->systemSettings[$key] : null;
        }
        public function setSystemSetting($key, $value) { $this->systemSettings[$key] = $value; }
        public function getProjectId() { return $this->projectIdReturn; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($message, $parameters = []) { $this->logCalls[] = [$message, $parameters]; return count($this->logCalls); }
    }
}

namespace {
    // Mock REDCap. $dataThrows lets a test simulate a getData failure.
    class REDCap {
        public static $data = [];
        public static $dictionary = [];
        public static $dataThrows = false;
        public static $lastGetDataParams = null;
        public static function getData($params) {
            self::$lastGetDataParams = $params;
            if (self::$dataThrows) throw new \RuntimeException('simulated getData failure: value=SECRET123');
            return self::$data;
        }
        public static function getDataDictionary($pid, $format = 'array') {
            if (!$pid) throw new \RuntimeException('getDataDictionary requires a pid');
            return self::$dictionary;
        }
    }

    require_once __DIR__ . '/../UniversalValidator.php';

    // A module whose log backend throws on the first detection write — used by
    // the per-rule isolation test (a framework hiccup on one rule must not
    // silently kill the audit of every later rule).
    class FlakyLogModule extends \INSPIRE\UniversalValidator\UniversalValidator {
        public $failFirstDetectionLog = false;
        private $detections = 0;
        public function log($message, $parameters = []) {
            if ($this->failFirstDetectionLog && $message === 'invalid-id-saved' && $this->detections++ === 0) {
                throw new \RuntimeException('simulated log backend failure');
            }
            return parent::log($message, $parameters);
        }
    }

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail;
        $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    // Rules: one dialog single rule (with a pattern) + reused across tests.
    $dialogRules = [[
        'rule-type' => 'single', 'fields' => ['main_id_1', 'main_id_2'], 'fields-csv' => '',
        'algorithm' => 'iso7064_mod37_36', 'source' => '', 'pattern' => '[1-8][A-Z]{3}-[0-9A-Z]{6}',
        'strip' => '', 'keep-chars' => '', 'id-lengths' => '', 'id-min-len' => '', 'id-max-len' => '',
        'expected-count' => '', 'block-save' => 'off',
    ]];
    $dictionary = [
        'record_id'   => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'id_validation_test'],
        'main_id_1'   => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'id_validation_test'],
        'main_id_2'   => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'id_validation_test'],
        'main_id_tag' => ['field_type' => 'text', 'field_annotation' => '@UVALIDATE', 'form_name' => 'id_validation_test'], // bare -> default check
        'other_field' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'other_form'],
    ];
    // Classic project getData shape: [record][event][field]
    $classicData = [2 => [351 => [
        'record_id'   => '2',
        'main_id_1'   => '1ABC-00002E', // invalid check (dialog rule)
        'main_id_2'   => '2XYZ-K93B7I', // valid
        'main_id_tag' => '8QRS-55556E', // invalid check (annotation rule)
    ]]];

    function newModule($subs, $dict, $data, $pidReturn, $throws = false, $projectSettings = null) {
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->subSettings = $subs;
        $m->projectSettings = $projectSettings === null ? ['log-values' => ''] : $projectSettings;
        $m->projectIdReturn = $pidReturn;
        \REDCap::$dictionary = $dict;
        \REDCap::$data = $data;
        \REDCap::$dataThrows = $throws;
        \REDCap::$lastGetDataParams = null;
        return $m;
    }
    function logsOf($m, $kind) {
        return array_values(array_filter($m->logCalls, function ($c) use ($kind) { return $c[0] === $kind; }));
    }
    function invalidLogs($m) { return logsOf($m, 'invalid-id-saved'); }
    function loggedFields($m) {
        return array_map(function ($c) { return $c[1]['field']; }, invalidLogs($m));
    }

    // ---- 1) baseline: a form save with getProjectId() working logs both invalids ----
    $m = newModule($dialogRules, $dictionary, $classicData, 149);
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    $fields = loggedFields($m);
    check('dialog-rule invalid is audited', in_array('main_id_1', $fields, true));
    check('annotation-rule invalid is audited', in_array('main_id_tag', $fields, true));
    check('valid field is NOT audited', !in_array('main_id_2', $fields, true));

    // ---- 2) import/API context (getProjectId()==null): the strict mocks return
    //         NO settings without an explicit pid, so this passing proves the
    //         module threads the hook's project_id into getSubSettings AND
    //         getProjectSetting (SEC-002). Instrument is null, as on imports.
    $m = newModule($dialogRules, $dictionary, $classicData, null); // getProjectId() null
    $m->redcap_save_record(149, '2', null, 351, null, null, null, 1);
    $fields = loggedFields($m);
    check('dialog rule audited when getProjectId() is null', in_array('main_id_1', $fields, true));
    check('annotation rule audited when getProjectId() is null', in_array('main_id_tag', $fields, true));
    $subReads = array_values(array_filter($m->settingReads, function ($r) { return $r[0] === 'getSubSettings'; }));
    $modeReads = array_values(array_filter($m->settingReads, function ($r) { return $r[0] === 'getProjectSetting' && $r[1] === 'log-values'; }));
    check('getSubSettings received the explicit hook pid', $subReads && $subReads[0][2] == 149);
    check('log-values read received the explicit hook pid', $modeReads && $modeReads[0][2] == 149);

    // ---- 3) a getData failure surfaces as a visible audit-error log ----
    $m = newModule($dialogRules, $dictionary, $classicData, 149, true); // getData throws
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    $errs = logsOf($m, 'uvalidate-audit-error');
    check('getData failure is logged, not swallowed', count($errs) === 1);
    check('audit-error names the exception class + place', isset($errs[0][1]['error'], $errs[0][1]['where']) && $errs[0][1]['error'] === 'RuntimeException');
    check('exception MESSAGE is not logged without debug mode', !isset($errs[0][1]['detail']));
    check('audit-error carries the raw record in hashed (default) mode', isset($errs[0][1]['record']) && $errs[0][1]['record'] === '2');

    // debug-log opt-in adds the (truncated) message
    $m = newModule($dialogRules, $dictionary, $classicData, 149, true, ['log-values' => '', 'debug-log' => true]);
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    $errs = logsOf($m, 'uvalidate-audit-error');
    check('debug mode logs the exception detail', isset($errs[0][1]['detail']) && strpos($errs[0][1]['detail'], 'simulated getData failure') === 0);

    // ---- 4) privacy modes ------------------------------------------------------
    // hashed (default): keyed HMAC of the value, raw record, never the raw value
    $m = newModule($dialogRules, $dictionary, $classicData, 149);
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    $one = invalidLogs($m)[0][1];
    check('reason recorded', in_array($one['reason'], ['check-character', 'format'], true));
    check('raw value NOT stored by default', !isset($one['value']));
    check('value keyed-HMAC stored by default', isset($one['value_hmac']));
    check('record stays raw in hashed mode', isset($one['record']) && $one['record'] === '2');
    $key = $m->systemSettings['log-hmac-key'];
    check('an HMAC key was generated and persisted', is_string($key) && strlen($key) === 64 && ctype_xdigit($key));
    check('value_hmac is the project-scoped keyed hash',
        $one['value_hmac'] === hash_hmac('sha256', '1ABC-00002E', $key . '|149'));
    // same module, same key on a second save (no rotation per request)
    $before = $m->systemSettings['log-hmac-key'];
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    check('HMAC key is stable across saves', $m->systemSettings['log-hmac-key'] === $before);

    // strict ("none"): record as keyed HMAC, no value at all
    $m = newModule($dialogRules, $dictionary, $classicData, 149, false, ['log-values' => 'none']);
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    $one = invalidLogs($m)[0][1];
    check('strict mode omits the value entirely', !isset($one['value']) && !isset($one['value_hmac']));
    check('strict mode hashes the record id', isset($one['record_hmac']) && !isset($one['record']));

    // strict mode ON THE EXCEPTION PATH: the audit-error entry must honor it too (SEC-003)
    $m = newModule($dialogRules, $dictionary, $classicData, 149, true, ['log-values' => 'none']);
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    $errs = logsOf($m, 'uvalidate-audit-error');
    check('strict mode: audit-error has NO raw record', count($errs) === 1 && !isset($errs[0][1]['record']));
    check('strict mode: audit-error record is keyed-hashed', isset($errs[0][1]['record_hmac']));

    // raw: explicit opt-in stores both raw
    $m = newModule($dialogRules, $dictionary, $classicData, 149, false, ['log-values' => 'raw']);
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    $one = invalidLogs($m)[0][1];
    check('raw mode stores the raw value', isset($one['value']) && $one['value'] === '1ABC-00002E');

    // off: no detection logs; an audit ERROR is still visible but identifier-free
    $m = newModule($dialogRules, $dictionary, $classicData, 149, false, ['log-values' => 'off']);
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    check('off mode logs no detections', count(invalidLogs($m)) === 0);
    $m = newModule($dialogRules, $dictionary, $classicData, 149, true, ['log-values' => 'off']);
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    $errs = logsOf($m, 'uvalidate-audit-error');
    check('off mode: audit-error is logged without ANY record identifier',
        count($errs) === 1 && !isset($errs[0][1]['record']) && !isset($errs[0][1]['record_hmac']));

    // ---- 5) no rules -> no logs (and no crash) ----
    $m = newModule([], [], [2 => [351 => ['main_id_1' => '1ABC-00002E']]], 149);
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    check('no rules -> no audit logs', count($m->logCalls) === 0);

    // ---- 6) value present but valid on every rule -> no logs ----
    $goodData = [2 => [351 => ['main_id_1' => '1ABC-00001E', 'main_id_2' => '2XYZ-K93B7I', 'main_id_tag' => '8QRS-55555E']]];
    $m = newModule($dialogRules, $dictionary, $goodData, 149);
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    check('all-valid save -> no invalid-id logs', count(invalidLogs($m)) === 0);

    // ---- 7) fast-entry rule with ONLY unknown field names is NOT silently
    //         dropped — it surfaces a config-error rule in the injected config. ----
    $csvRule = [[
        'rule-type' => 'single', 'fields' => [], 'fields-csv' => 'studyid specimenid',
        'algorithm' => 'iso7064_mod37_36', 'source' => '', 'pattern' => '', 'strip' => '',
        'keep-chars' => '', 'id-lengths' => '', 'id-min-len' => '', 'id-max-len' => '',
        'expected-count' => '', 'block-save' => 'off',
    ]];
    $dd = ['study_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'f1'],
           'specimen_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'f1']];
    $m = newModule($csvRule, $dd, [], 149);
    ob_start();
    $m->redcap_data_entry_form_top(149, '1', 'id_validation_test', 351, null, 1);
    $html = ob_get_clean();
    $cfg = null;
    if (preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm)) {
        $cfg = json_decode($mm[1], true);
    }
    $errRule = null;
    if ($cfg && isset($cfg['rules'])) {
        foreach ($cfg['rules'] as $r) { if (!empty($r['configError'])) { $errRule = $r; break; } }
    }
    check('all-invalid fast-entry emits a config-error rule (not silently dropped)', $errRule !== null);
    check('config-error rule keeps the typed field names', $errRule && $errRule['fields'] === ['studyid', 'specimenid']);
    check('config error names the unknown fields', $errRule && strpos($errRule['configError'], 'not in this project') !== false);
    check('form hook injects context=form', $cfg && isset($cfg['context']) && $cfg['context'] === 'form');

    // survey hook injects context=survey (client uses it to mute technical notices)
    $m = newModule($csvRule, $dd, [], 149);
    ob_start();
    $m->redcap_survey_page_top(149, '1', 'id_validation_test', 351, null, 'hash', null, 1);
    $html = ob_get_clean();
    $cfg = null;
    if (preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm)) {
        $cfg = json_decode($mm[1], true);
    }
    check('survey hook injects context=survey', $cfg && isset($cfg['context']) && $cfg['context'] === 'survey');
    check('no config GLOBAL is written by the injector (engine parses the JSON node)',
        strpos($html, 'INSPIRE_VALIDATOR_CONFIG') === false);

    // ---- 8) instrument scoping (PER-001): saving an unrelated instrument must
    //          not re-validate (and re-log) another form's old invalid value. ----
    $m = newModule($dialogRules, $dictionary, $classicData, 149);
    $m->redcap_save_record(149, '2', 'other_form', 351, null, null, null, 1);
    check('unrelated-instrument save audits nothing', count(invalidLogs($m)) === 0);
    check('unrelated-instrument save reads no data at all', \REDCap::$lastGetDataParams === null);
    // unknown instrument name (hook quirk) -> conservative: validate everything
    $m = newModule($dialogRules, $dictionary, $classicData, 149);
    $m->redcap_save_record(149, '2', 'not_a_real_form', 351, null, null, null, 1);
    check('unknown instrument falls back to auditing all fields', in_array('main_id_1', loggedFields($m), true));

    // ---- 9) event scoping (COR-001): with an event id supplied, a value that
    //          exists only under ANOTHER event must never be validated/logged. ----
    $m = newModule($dialogRules, $dictionary, $classicData, 149); // data only under event 351
    $m->redcap_save_record(149, '2', 'id_validation_test', 352, null, null, null, 1);
    check('cross-event value is NOT audited under the saved event', count(invalidLogs($m)) === 0);
    // same save with the right event still logs, tagged with that event
    $m = newModule($dialogRules, $dictionary, $classicData, 149);
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    $one = invalidLogs($m)[0][1];
    check('right-event save logs with the event id', $one['event_id'] === '351');
    // no event id at all (import context): the whole-record fallback still finds it
    $m = newModule($dialogRules, $dictionary, $classicData, 149);
    $m->redcap_save_record(149, '2', null, null, null, null, null, 1);
    check('no-event context still audits via the record scan', in_array('main_id_1', loggedFields($m), true));

    // ---- 10) repeating instruments: exact instance only ----
    $repeatData = [2 => ['repeat_instances' => [351 => ['id_validation_test' => [
        1 => ['main_id_1' => '1ABC-00001E'],   // valid
        2 => ['main_id_1' => '1ABC-00002E'],   // invalid
    ]]]]];
    $m = newModule($dialogRules, $dictionary, $repeatData, 149);
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 2);
    $logs = invalidLogs($m);
    check('repeat instance 2 invalid -> exactly one log', count($logs) === 1);
    check('log carries instance=2', $logs[0][1]['instance'] === '2');
    $m = newModule($dialogRules, $dictionary, $repeatData, 149);
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    check('repeat instance 1 valid -> no log', count(invalidLogs($m)) === 0);

    // ---- 11) duplicate-claimed fields are skipped, mirroring the client ----
    $dupRules = [
        $dialogRules[0],
        [
            'rule-type' => 'single', 'fields' => ['main_id_1'], 'fields-csv' => '',
            'algorithm' => 'damm', 'source' => '', 'pattern' => '', 'strip' => '',
            'keep-chars' => '', 'id-lengths' => '', 'id-min-len' => '', 'id-max-len' => '',
            'expected-count' => '', 'block-save' => 'off',
        ],
    ];
    $m = newModule($dupRules, $dictionary, $classicData, 149);
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    check('field claimed by two rules is not audited by either', !in_array('main_id_1', loggedFields($m), true));
    check('other fields of the shared rule still audited', in_array('main_id_tag', loggedFields($m), true));
    // since 0.9.0 sharing without "when" is a named config error (two rules
    // with conditions would instead become a branch rule — see test 23)
    $m = newModule($dupRules, $dictionary, $classicData, 149);
    $r = injectedRuleFor($m, 'main_id_1');
    check('unconditional sharing surfaces the two-unconditional config error',
        $r && !empty($r['configError'])
        && strpos($r['configError'], 'at most ONE unconditional rule') !== false);

    // ---- 12) an unevaluable rule logs "unconfigurable" instead of passing silently ----
    // A{1,40}A{1,40}A{1,40}9 passes the ReDoS gate (bounded work, capped by the
    // pattern rather than the input length, so not an input-scaling ReDoS) but
    // three overlapping bounded quantifiers trip the PCRE backtrack limit at
    // match time -> pooled parse bails -> logged.
    $pcreRule = [[
        'rule-type' => 'pooled', 'fields' => ['main_id_1'], 'fields-csv' => '',
        'algorithm' => 'none', 'source' => '', 'pattern' => 'A{1,40}A{1,40}A{1,40}9', 'strip' => '',
        'keep-chars' => '', 'id-lengths' => '30', 'id-min-len' => '', 'id-max-len' => '',
        'expected-count' => '', 'block-save' => 'off',
    ]];
    $pcreData = [2 => [351 => ['main_id_1' => str_repeat('A', 28) . '9A']]];
    $m = newModule($pcreRule, $dictionary, $pcreData, 149);
    $oldLimit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '100');
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    ini_set('pcre.backtrack_limit', $oldLimit === false ? '1000000' : $oldLimit);
    check('PCRE engine failure logs an unconfigurable entry, not a silent pass',
        count(logsOf($m, 'uvalidate-unconfigurable')) === 1);
    check('PCRE engine failure logs no false invalid', count(invalidLogs($m)) === 0);

    // ---- 13) per-rule isolation: a failure while auditing one rule must not
    //           stop later rules. Simulated by a log backend that throws on the
    //           FIRST detection write (rule 1's invalid field); the annotation
    //           rule after it must still be audited and logged. ----
    $m = new FlakyLogModule();
    $m->subSettings = $dialogRules;
    $m->projectSettings = ['log-values' => ''];
    $m->projectIdReturn = 149;
    $m->failFirstDetectionLog = true;
    \REDCap::$dictionary = $dictionary;
    \REDCap::$data = $classicData;
    \REDCap::$dataThrows = false;
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    check('later rules still audited after one rule fails', in_array('main_id_tag', loggedFields($m), true));
    $stageErrs = logsOf($m, 'uvalidate-audit-error');
    check('the failed rule leaves a per-rule audit-error entry',
        count($stageErrs) === 1 && strpos($stageErrs[0][1]['stage'], 'rule 1') === 0);

    // non-scalar stored settings values are discarded, never warnings/poison
    $weirdRules = [[
        'rule-type' => 'single', 'fields' => ['main_id_1'], 'fields-csv' => '',
        'algorithm' => 'iso7064_mod37_36', 'source' => '', 'pattern' => '', 'strip' => ['not', 'a', 'string'],
        'keep-chars' => '', 'id-lengths' => '', 'id-min-len' => '', 'id-max-len' => '',
        'expected-count' => '', 'block-save' => 'off',
    ]];
    $m = newModule($weirdRules, $dictionary, $classicData, 149);
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    check('non-scalar stored setting is ignored, rule still audits (default strip)',
        in_array('main_id_1', loggedFields($m), true));

    // ---- 14) COR-004: non-ASCII value on a format rule fails OPEN (no format log) ----
    $regexRule = [[
        'rule-type' => 'single', 'fields' => ['main_id_1'], 'fields-csv' => '',
        'algorithm' => 'none', 'source' => '', 'pattern' => 'FC[0-9]{4}', 'strip' => '',
        'keep-chars' => '', 'id-lengths' => '', 'id-min-len' => '', 'id-max-len' => '',
        'expected-count' => '', 'block-save' => 'off',
    ]];
    $uniData = [2 => [351 => ['main_id_1' => "FC12\u{0663}4"]]]; // Arabic-Indic digit
    $m = newModule($regexRule, $dictionary, $uniData, 149);
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    check('non-ASCII value is not format-audited (outside proven parity subset)', count(invalidLogs($m)) === 0);

    // ---- 15) COR-003: a dialog rule on a non-text field becomes a config error ----
    $ddRadio = $dictionary;
    $ddRadio['gender'] = ['field_type' => 'radio', 'field_annotation' => '', 'form_name' => 'id_validation_test'];
    $radioRule = [[
        'rule-type' => 'single', 'fields' => ['gender'], 'fields-csv' => '',
        'algorithm' => 'iso7064_mod37_36', 'source' => '', 'pattern' => '', 'strip' => '',
        'keep-chars' => '', 'id-lengths' => '', 'id-min-len' => '', 'id-max-len' => '',
        'expected-count' => '', 'block-save' => 'off',
    ]];
    $m = newModule($radioRule, $ddRadio, $classicData, 149);
    ob_start();
    $m->redcap_data_entry_form_top(149, '1', 'id_validation_test', 351, null, 1);
    $html = ob_get_clean();
    check('non-text picker field surfaces a config error',
        strpos($html, 'only Text and Notes fields') !== false);

    // ---- 16) validateSettings: the save-time gate ----
    $m = newModule([], $dictionary, [], 149);
    $m->projectIdReturn = 149;
    $flat = [
        'rules'      => [true, true],
        'rule-type'  => ['single', 'pooled'],
        'fields'     => [['main_id_1'], ['main_id_2']],
        'fields-csv' => ['', ''],
        'algorithm'  => ['iso7064_mod37_36', 'none'],
        'source'     => ['', ''],
        'pattern'    => ['', '(a|aa)+'],
        'strip'      => ['', ''],
        'keep-chars' => ['', ''],
        'id-lengths' => ['', ''],
        'id-min-len' => ['', ''],
        'id-max-len' => ['', ''],
        'expected-count' => ['', ''],
        'block-save' => ['off', 'off'],
    ];
    $msg = $m->validateSettings($flat);
    check('validateSettings rejects a catastrophic pattern at save time',
        is_string($msg) && strpos($msg, 'Rule 2') !== false && strpos($msg, 'backtracking') !== false);
    $flat['pattern'][1] = 'FC[0-9]{4}';
    check('validateSettings passes a sound rule set', $m->validateSettings($flat) === null);
    $flat['id-max-len'] = ['', '200'];
    $msg = $m->validateSettings($flat);
    check('validateSettings rejects lengths beyond the work cap', is_string($msg) && strpos($msg, '64') !== false);
    check('validateSettings ignores non-rule settings shapes', $m->validateSettings(['enabled' => true]) === null);

    // ---- 16b) a settings-DIALOG rule may use an algorithm SHORTHAND ----
    // settingRowToRule canonicalizes the algorithm (UniversalValidator.php) before
    // the whitelist check, exactly as the annotation channel does — so "9710"
    // resolves to iso7064_mod97_10 and the save-time gate accepts it. Without that
    // canonicalization the gate would reject the shorthand as an unknown algorithm.
    // (The dialog dropdown stores canonical names, so this guards the hand-edited
    // setting / future free-text field the code comment promises to support, and
    // locks the dialog-channel line the shorthand tests otherwise never drive.)
    $synFlat = [
        'rules'      => [true],
        'rule-type'  => ['single'],
        'fields'     => [['main_id_1']],
        'fields-csv' => [''],
        'algorithm'  => ['9710'],            // shorthand for iso7064_mod97_10
        'source'     => [''],
        'pattern'    => [''],
        'strip'      => [''],
        'keep-chars' => [''],
        'id-lengths' => [''],
        'id-min-len' => [''],
        'id-max-len' => [''],
        'expected-count' => [''],
        'block-save' => ['off'],
    ];
    check('validateSettings accepts an algorithm shorthand (dialog channel canonicalizes)',
        $m->validateSettings($synFlat) === null);
    $synFlat['algorithm'] = ['bogus99'];     // genuinely unknown -> still rejected
    check('validateSettings still rejects a truly unknown algorithm',
        is_string($m->validateSettings($synFlat)));

    // ---- 17) an @UVALIDATE algorithm shorthand is resolved and audited server-side ----
    // @UVALIDATE=3736 must behave exactly like the canonical iso7064_mod37_36 on
    // the audit path, and the log must record the canonical name (not "3736").
    $synDd = [
        'record_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'f'],
        'syn_id'    => ['field_type' => 'text', 'field_annotation' => '@UVALIDATE=3736', 'form_name' => 'f'],
    ];
    $synData = [2 => [351 => ['syn_id' => '1ABC-00002E']]]; // invalid check under mod37,36
    $m = newModule([], $synDd, $synData, 149);
    $m->redcap_save_record(149, '2', 'f', 351, null, null, null, 1);
    check('algorithm shorthand @UVALIDATE=3736 is audited', in_array('syn_id', loggedFields($m), true));
    $synLog = invalidLogs($m);
    check('shorthand audit logs the CANONICAL algorithm name',
        $synLog && $synLog[0][1]['algorithm'] === 'iso7064_mod37_36');

    // ---- 18) "when" conditions gate the server audit ----
    $whenDict = [
        'record_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'f'],
        'sid'       => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'f'],
        'stype'     => ['field_type' => 'radio', 'field_annotation' => '', 'form_name' => 'f',
                        'select_choices_or_calculations' => '1, Sputum | 2, Blood'],
        'consent'   => ['field_type' => 'checkbox', 'field_annotation' => '', 'form_name' => 'f',
                        'select_choices_or_calculations' => '0, Verbal | 1, Written'],
        'elig'      => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'other_form'],
    ];
    function whenRule($when) {
        return [[
            'rule-type' => 'single', 'fields' => ['sid'], 'fields-csv' => '', 'when' => $when,
            'algorithm' => 'iso7064_mod37_36', 'source' => '', 'pattern' => '', 'strip' => '',
            'keep-chars' => '', 'id-lengths' => '', 'id-min-len' => '', 'id-max-len' => '',
            'expected-count' => '', 'block-save' => 'off',
        ]];
    }
    $badSid = '1ABC-00002E'; // invalid mod37,36 check

    // condition FALSE -> the invalid value is NOT logged (and not "unconfigurable" either)
    $m = newModule(whenRule("[stype]='2'"), $whenDict, [2 => [351 => ['sid' => $badSid, 'stype' => '1']]], 149);
    $m->redcap_save_record(149, '2', 'f', 351, null, null, null, 1);
    check('when false: invalid value is NOT audited', count(invalidLogs($m)) === 0);
    check('when false: no unconfigurable entry', count(logsOf($m, 'uvalidate-unconfigurable')) === 0);

    // condition TRUE -> logged as usual, and the ref rode the ONE getData call
    $m = newModule(whenRule("[stype]='2'"), $whenDict, [2 => [351 => ['sid' => $badSid, 'stype' => '2']]], 149);
    $m->redcap_save_record(149, '2', 'f', 351, null, null, null, 1);
    check('when true: invalid value IS audited', in_array('sid', loggedFields($m), true));
    check('condition ref is part of the single getData read',
        \REDCap::$lastGetDataParams && in_array('stype', \REDCap::$lastGetDataParams['fields'], true));

    // checkbox refs arrive as code=>0/1 arrays from getData and resolve '1'/'0'
    $m = newModule(whenRule("[consent(1)]='1'"), $whenDict,
        [2 => [351 => ['sid' => $badSid, 'consent' => ['0' => '0', '1' => '1']]]], 149);
    $m->redcap_save_record(149, '2', 'f', 351, null, null, null, 1);
    check('checkbox ref checked: rule audited', in_array('sid', loggedFields($m), true));
    $m = newModule(whenRule("[consent(1)]='1'"), $whenDict,
        [2 => [351 => ['sid' => $badSid, 'consent' => ['0' => '1', '1' => '0']]]], 149);
    $m->redcap_save_record(149, '2', 'f', 351, null, null, null, 1);
    check('checkbox ref unchecked: rule skipped', count(invalidLogs($m)) === 0);

    // a ref on ANOTHER instrument is still read and honored (refs are not form-filtered)
    $m = newModule(whenRule("[elig]='yes'"), $whenDict,
        [2 => [351 => ['sid' => $badSid, 'elig' => 'yes']]], 149);
    $m->redcap_save_record(149, '2', 'f', 351, null, null, null, 1);
    check('off-instrument ref gates the rule (true case)', in_array('sid', loggedFields($m), true));
    // a missing ref value evaluates as '' -> condition false here -> skipped
    $m = newModule(whenRule("[elig]='yes'"), $whenDict, [2 => [351 => ['sid' => $badSid]]], 149);
    $m->redcap_save_record(149, '2', 'f', 351, null, null, null, 1);
    check('missing ref value evaluates as empty (rule skipped)', count(invalidLogs($m)) === 0);

    // a when-less rule behaves exactly as before (regression guard for the new plumbing)
    $m = newModule(whenRule(''), $whenDict, [2 => [351 => ['sid' => $badSid]]], 149);
    $m->redcap_save_record(149, '2', 'f', 351, null, null, null, 1);
    check('blank when box: rule audits unconditionally', in_array('sid', loggedFields($m), true));

    // an unparseable when never reaches the audit: every channel turns it into a
    // config-error rule, which the audit skips wholesale (client shows the error)
    $m = newModule(whenRule("datediff([a],[b],'d')>3"), $whenDict, [2 => [351 => ['sid' => $badSid]]], 149);
    $m->redcap_save_record(149, '2', 'f', 351, null, null, null, 1);
    check('unparseable when -> config-error rule -> no invalid log', count(invalidLogs($m)) === 0);

    // ---- 19) "when" carriage + save-time gate on the dialog channel ----
    // the condition travels into the injected client config verbatim
    $m = newModule(whenRule("[stype]='2'"), $whenDict, [], 149);
    ob_start();
    $m->redcap_data_entry_form_top(149, '1', 'f', 351, null, 1);
    $html = ob_get_clean();
    $cfg = null;
    if (preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm)) {
        $cfg = json_decode($mm[1], true);
    }
    $sidRule = null;
    if ($cfg && isset($cfg['rules'])) {
        foreach ($cfg['rules'] as $r) { if (isset($r['fields']) && $r['fields'] === ['sid']) { $sidRule = $r; break; } }
    }
    check('when reaches the injected client config', $sidRule && isset($sidRule['when']) && $sidRule['when'] === "[stype]='2'");

    // validateSettings (save-time gate) rejects bad conditions before storage
    $m = newModule([], $whenDict, [], 149);
    $wFlat = [
        'rules'      => [true],
        'rule-type'  => ['single'],
        'fields'     => [['sid']],
        'fields-csv' => [''],
        'when'       => ["[stype]="],
        'algorithm'  => ['iso7064_mod37_36'],
        'source'     => [''],
        'pattern'    => [''],
        'strip'      => [''],
        'keep-chars' => [''],
        'id-lengths' => [''],
        'id-min-len' => [''],
        'id-max-len' => [''],
        'expected-count' => [''],
        'block-save' => ['off'],
    ];
    $msg = $m->validateSettings($wFlat);
    check('validateSettings rejects a malformed when', is_string($msg) && strpos($msg, '"when"') !== false);
    $wFlat['when'] = ["[nosuch]='1'"];
    $msg = $m->validateSettings($wFlat);
    check('validateSettings rejects a when referencing an unknown field',
        is_string($msg) && strpos($msg, 'not a field') !== false);
    $wFlat['when'] = ["[consent]='1'"];
    $msg = $m->validateSettings($wFlat);
    check('validateSettings demands a checkbox (code)', is_string($msg) && strpos($msg, 'is a checkbox') !== false);
    $wFlat['when'] = ["[consent(9)]='1'"];
    $msg = $m->validateSettings($wFlat);
    check('validateSettings rejects an unknown checkbox code',
        is_string($msg) && strpos($msg, 'no choice code') !== false);
    $wFlat['when'] = ["[stype(1)]='1'"];
    $msg = $m->validateSettings($wFlat);
    check('validateSettings rejects a (code) on a non-checkbox',
        is_string($msg) && strpos($msg, 'only checkbox fields') !== false);
    $wFlat['when'] = ["[stype]='2' and [consent(1)]='1'"];
    check('validateSettings passes a sound conditional rule', $m->validateSettings($wFlat) === null);

    // ---- 20) annotation channel: dictionary ref checks surface on the field ----
    $annDict = $whenDict;
    $annDict['tagged'] = ['field_type' => 'text', 'form_name' => 'f',
        'field_annotation' => '@UVALIDATE={"when":"[ghost]=\'1\'"}'];
    $m = newModule([], $annDict, [], 149);
    ob_start();
    $m->redcap_data_entry_form_top(149, '1', 'f', 351, null, 1);
    $html = ob_get_clean();
    check('annotation when with an unknown ref -> visible config error',
        strpos($html, 'not a field in this project') !== false);
    // and a sound annotation condition still gates the audit end-to-end
    $annDict['tagged']['field_annotation'] = '@UVALIDATE={"when":"[stype]=\'2\'"}';
    $m = newModule([], $annDict, [2 => [351 => ['tagged' => $badSid, 'stype' => '1']]], 149);
    $m->redcap_save_record(149, '2', 'f', 351, null, null, null, 1);
    check('annotation when false: tagged field not audited', count(invalidLogs($m)) === 0);
    $m = newModule([], $annDict, [2 => [351 => ['tagged' => $badSid, 'stype' => '2']]], 149);
    $m->redcap_save_record(149, '2', 'f', 351, null, null, null, 1);
    check('annotation when true: tagged field audited', in_array('tagged', loggedFields($m), true));

    // ---- 21) SEC-005: conditions are folded, record values never reach the page ----
    // In $whenDict, stype/consent live on the rendered form "f"; elig lives on
    // "other_form". A condition over elig cannot be resolved in the browser —
    // and elig's VALUE must never be shipped there (a survey respondent, or a
    // user without rights to other_form, can read anything the page carries).
    function pageCfg($m, $ctx, $record, $instrument) {
        ob_start();
        if ($ctx === 'survey') $m->redcap_survey_page_top(149, $record, $instrument, 351, null, 'h', null, 1);
        else $m->redcap_data_entry_form_top(149, $record, $instrument, 351, null, 1);
        $html = ob_get_clean();
        if (!preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm)) return null;
        return ['html' => $html, 'raw' => $mm[1], 'cfg' => json_decode($mm[1], true)];
    }
    function ruleFor($p, $field) {
        if (!$p || empty($p['cfg']['rules'])) return null;
        foreach ($p['cfg']['rules'] as $r) {
            if (isset($r['fields']) && in_array($field, $r['fields'], true)) return $r;
        }
        return null;
    }
    $SECRET = 'ELIGIBLE_PATIENT_042';
    $foldRule = whenRule("[stype]='2' or [consent(1)]='1' or [elig]='" . $SECRET . "'");
    $foldData = [2 => [351 => [
        'sid'     => '2XYZ-K93B7I',
        'stype'   => '1',
        'consent' => ['0' => '1', '1' => '0'], // sequential numeric codes
        'elig'    => $SECRET,
    ]]];
    $p = pageCfg(newModule($foldRule, $whenDict, $foldData, 149), 'form', '2', 'f');
    check('no whenValues snapshot is emitted at all', $p && !isset($p['cfg']['whenValues']));
    $r = ruleFor($p, 'sid');
    check('the rule ships a folded whenAst', $r && isset($r['whenAst']) && is_array($r['whenAst']));
    // [stype] and [consent(1)] are on this form -> stay live refs (the browser
    // reads them); [elig] is not -> folded to the boolean it evaluates to.
    $enc = json_encode($r['whenAst'] ?? null);
    check('on-form refs stay live in the folded AST',
        strpos($enc, '["ref","stype",null]') !== false && strpos($enc, '["ref","consent","1"]') !== false);
    check('off-form comparison is folded to a constant', strpos($enc, '["const",true]') !== false);
    check('off-form ref is GONE from the shipped AST', strpos($enc, '"elig"') === false);

    // survey pages carry the same folded shape and no values either
    $p = pageCfg(newModule($foldRule, $whenDict, $foldData, 149), 'survey', '2', 'f');
    $r = ruleFor($p, 'sid');
    check('survey page: no whenValues', $p && !isset($p['cfg']['whenValues']));
    check('survey page: folded AST shipped', $r && isset($r['whenAst']));

    // the record's actual values appear NOWHERE in the emitted page (the
    // condition's own literals are designer config and may of course appear)
    foreach (['form', 'survey'] as $ctx) {
        $p = pageCfg(newModule(whenRule("[elig]<>''"), $whenDict, $foldData, 149), $ctx, '2', 'f');
        check($ctx . ' page: the off-form record value is not in the page',
            $p && strpos($p['html'], $SECRET) === false);
    }

    // a brand-new record: nothing to read, off-form comparisons fold against ''
    $p = pageCfg(newModule($foldRule, $whenDict, $foldData, 149), 'form', null, 'f');
    $r = ruleFor($p, 'sid');
    check('null record: still no whenValues', $p && !isset($p['cfg']['whenValues']));
    check('null record: off-form comparison folds to false (empty value)',
        $r && strpos(json_encode($r['whenAst']), '["const",false]') !== false);

    // rules without when: no folding work and no extra getData at render time
    $m = newModule($dialogRules, $dictionary, $classicData, 149);
    $p = pageCfg($m, 'form', '2', 'id_validation_test');
    check('when-less rules: no whenAst', $p && !isset(ruleFor($p, 'main_id_1')['whenAst']));
    check('when-less rules: no render-time getData', \REDCap::$lastGetDataParams === null);

    // a condition over ONLY on-form fields needs no values read either
    $m = newModule(whenRule("[stype]='2'"), $whenDict, $foldData, 149);
    $p = pageCfg($m, 'form', '2', 'f');
    $r = ruleFor($p, 'sid');
    check('on-form-only condition keeps its ref live',
        $r && strpos(json_encode($r['whenAst']), '["ref","stype",null]') !== false);
    check('on-form-only condition ships no constant',
        $r && strpos(json_encode($r['whenAst']), '"const"') === false);

    // ---- 22) suggestFix: dialog checkbox carriage into the injected config ----
    function sfRule($sf) {
        $row = [
            'rule-type' => 'single', 'fields' => ['sid'], 'fields-csv' => '',
            'algorithm' => 'iso7064_mod37_36', 'source' => '', 'pattern' => '', 'strip' => '',
            'keep-chars' => '', 'id-lengths' => '', 'id-min-len' => '', 'id-max-len' => '',
            'expected-count' => '', 'block-save' => 'off',
        ];
        if ($sf !== null) $row['suggest-fix'] = $sf;
        return [$row];
    }
    function injectedRuleFor($m, $field) {
        ob_start();
        $m->redcap_data_entry_form_top(149, '2', 'f', 351, null, 1);
        $html = ob_get_clean();
        if (!preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm)) return null;
        $cfg = json_decode($mm[1], true);
        if (!$cfg || !isset($cfg['rules'])) return null;
        foreach ($cfg['rules'] as $r) {
            if (isset($r['fields']) && in_array($field, $r['fields'], true)) return $r;
        }
        return null;
    }
    $m = newModule(sfRule(true), $whenDict, [], 149);
    $r = injectedRuleFor($m, 'sid');
    check('suggest-fix checkbox (bool true) carries suggestFix:true', $r && isset($r['suggestFix']) && $r['suggestFix'] === true);
    $m = newModule(sfRule('true'), $whenDict, [], 149);
    $r = injectedRuleFor($m, 'sid');
    check('suggest-fix checkbox (string "true") carries suggestFix:true', $r && isset($r['suggestFix']) && $r['suggestFix'] === true);
    $m = newModule(sfRule(null), $whenDict, [], 149);
    $r = injectedRuleFor($m, 'sid');
    check('no checkbox -> rule carries NO suggestFix key (engine default off)', $r && !isset($r['suggestFix']));
    // the engine-level default (hint absent without opt-in) is locked client-side by a11y_dom_js

    // ---- 23) branched validation: the audit picks the active branch ----
    function branchRow($algo, $when) {
        return [
            'rule-type' => 'single', 'fields' => ['sid'], 'fields-csv' => '', 'when' => $when,
            'algorithm' => $algo, 'source' => '', 'pattern' => '', 'strip' => '',
            'keep-chars' => '', 'id-lengths' => '', 'id-min-len' => '', 'id-max-len' => '',
            'expected-count' => '', 'block-save' => 'off',
        ];
    }
    $branchRules = [branchRow('verhoeff', "[stype]='1'"), branchRow('iso7064_mod37_36', "[stype]='2'")];
    $badVerhoeff = '2364'; // verhoeff check digit for 236 is 3

    // stype=2 -> the mod37,36 branch audits (and its ref rode the one getData)
    $m = newModule($branchRules, $whenDict, [2 => [351 => ['sid' => $badSid, 'stype' => '2']]], 149);
    $m->redcap_save_record(149, '2', 'f', 351, null, null, null, 1);
    $logs = invalidLogs($m);
    check('branch 2 active: audited under its algorithm',
        count($logs) === 1 && $logs[0][1]['algorithm'] === 'iso7064_mod37_36' && $logs[0][1]['field'] === 'sid');
    check('branch refs ride the single getData',
        \REDCap::$lastGetDataParams && in_array('stype', \REDCap::$lastGetDataParams['fields'], true));

    // stype=1 -> the verhoeff branch audits the same field
    $m = newModule($branchRules, $whenDict, [2 => [351 => ['sid' => $badVerhoeff, 'stype' => '1']]], 149);
    $m->redcap_save_record(149, '2', 'f', 351, null, null, null, 1);
    $logs = invalidLogs($m);
    check('branch 1 active: audited under verhoeff',
        count($logs) === 1 && $logs[0][1]['algorithm'] === 'verhoeff');

    // neither condition true, no else -> the field is inert (no logs of any kind)
    $m = newModule($branchRules, $whenDict, [2 => [351 => ['sid' => $badSid, 'stype' => '9']]], 149);
    $m->redcap_save_record(149, '2', 'f', 351, null, null, null, 1);
    check('no branch active, no else: inert',
        count(invalidLogs($m)) === 0 && count(logsOf($m, 'uvalidate-unconfigurable')) === 0);

    // an unconditional sibling becomes the ELSE branch
    $elseRules = [branchRow('verhoeff', "[stype]='1'"), branchRow('iso7064_mod37_36', '')];
    $m = newModule($elseRules, $whenDict, [2 => [351 => ['sid' => $badSid, 'stype' => '9']]], 149);
    $m->redcap_save_record(149, '2', 'f', 351, null, null, null, 1);
    $logs = invalidLogs($m);
    check('else branch fires when no condition is true',
        count($logs) === 1 && $logs[0][1]['algorithm'] === 'iso7064_mod37_36');
    $m = newModule($elseRules, $whenDict, [2 => [351 => ['sid' => $badVerhoeff, 'stype' => '1']]], 149);
    $m->redcap_save_record(149, '2', 'f', 351, null, null, null, 1);
    $logs = invalidLogs($m);
    check('a true condition beats the else', count($logs) === 1 && $logs[0][1]['algorithm'] === 'verhoeff');

    // overlapping conditions both true -> conflict logged, nothing validated
    $overlapRules = [branchRow('verhoeff', "[stype]<>'9'"), branchRow('iso7064_mod37_36', "[elig]='yes'")];
    $m = newModule($overlapRules, $whenDict, [2 => [351 => ['sid' => $badSid, 'stype' => '1', 'elig' => 'yes']]], 149);
    $m->redcap_save_record(149, '2', 'f', 351, null, null, null, 1);
    $unc = logsOf($m, 'uvalidate-unconfigurable');
    check('branch conflict -> one unconfigurable entry naming both conditions',
        count($unc) === 1 && strpos($unc[0][1]['why'], 'branch conflict') !== false
        && strpos($unc[0][1]['why'], "[stype]<>'9'") !== false);
    check('branch conflict -> no invalid log (never a guessed algorithm)', count(invalidLogs($m)) === 0);

    // the branch rule reaches the client with branches, each carrying its own
    // folded condition (SEC-005) — never a value
    $m = newModule($branchRules, $whenDict, [2 => [351 => ['sid' => '2XYZ-K93B7I', 'stype' => '2']]], 149);
    $r = injectedRuleFor($m, 'sid');
    check('client receives the branch rule', $r && isset($r['branches']) && count($r['branches']) === 2
        && !isset($r['when']) && !isset($r['algorithm']));
    check('every branch ships a folded whenAst',
        $r && isset($r['branches'][0]['whenAst'], $r['branches'][1]['whenAst']));
    check('branch conditions over an on-form field stay live refs',
        $r && strpos(json_encode($r['branches'][0]['whenAst']), '["ref","stype",null]') !== false);
    // an off-form branch condition folds; its value never ships. The literal
    // ("ELIGIBLE") is the designer's own text and may appear in the page — the
    // RECORD's value ("SEEKRET") must not, so the two are kept distinct here.
    $offRules = [branchRow('verhoeff', "[elig]='ELIGIBLE'"), branchRow('iso7064_mod37_36', "[stype]='2'")];
    $offData = [2 => [351 => ['sid' => '2XYZ-K93B7I', 'elig' => 'SEEKRET']]];
    $m = newModule($offRules, $whenDict, $offData, 149);
    $r = injectedRuleFor($m, 'sid');
    check('off-form branch condition is folded to a constant',
        $r && json_encode($r['branches'][0]['whenAst']) === '["const",false]');
    ob_start();
    $m2 = newModule($offRules, $whenDict, $offData, 149);
    $m2->redcap_data_entry_form_top(149, '2', 'f', 351, null, 1);
    $html = ob_get_clean();
    check('off-form branch value is not in the page', strpos($html, 'SEEKRET') === false);

    // annotation channel: TWO tags on one field become a working branch rule
    $annDict2 = $whenDict;
    $annDict2['tag2'] = ['field_type' => 'text', 'form_name' => 'f',
        'field_annotation' => '@UVALIDATE={"algorithm":"verhoeff","when":"[stype]=\'1\'"} '
            . '@UVALIDATE={"algorithm":"iso7064_mod37_36","when":"[stype]=\'2\'"}'];
    $m = newModule([], $annDict2, [2 => [351 => ['tag2' => $badSid, 'stype' => '2']]], 149);
    $m->redcap_save_record(149, '2', 'f', 351, null, null, null, 1);
    $logs = invalidLogs($m);
    check('two-tag annotation: active branch audits the field',
        count($logs) === 1 && $logs[0][1]['field'] === 'tag2' && $logs[0][1]['algorithm'] === 'iso7064_mod37_36');

    // cross-channel sharing (dialog + annotation) is a legal branch rule
    $xDict = $whenDict;
    $xDict['sid']['field_annotation'] = '@UVALIDATE={"algorithm":"iso7064_mod37_36","when":"[stype]=\'2\'"}';
    $m = newModule([branchRow('verhoeff', "[stype]='1'")], $xDict, [2 => [351 => ['sid' => $badSid, 'stype' => '2']]], 149);
    $m->redcap_save_record(149, '2', 'f', 351, null, null, null, 1);
    $logs = invalidLogs($m);
    check('dialog + annotation sharing one field works as branches',
        count($logs) === 1 && $logs[0][1]['algorithm'] === 'iso7064_mod37_36');

    // ---- 24) validateSettings: cross-rule sharing legality at save time ----
    function twoRuleFlat($when1, $when2, $type2 = 'single') {
        return [
            'rules'      => [true, true],
            'rule-type'  => ['single', $type2],
            'fields'     => [['sid'], ['sid']],
            'fields-csv' => ['', ''],
            'when'       => [$when1, $when2],
            'algorithm'  => ['verhoeff', 'iso7064_mod37_36'],
            'source'     => ['', ''],
            'suggest-fix' => ['', ''],
            'pattern'    => ['', ''],
            'strip'      => ['', ''],
            'keep-chars' => ['', ''],
            'id-lengths' => ['', ''],
            'id-min-len' => ['', ''],
            'id-max-len' => ['', ''],
            'expected-count' => ['', ''],
            'block-save' => ['off', 'off'],
        ];
    }
    $m = newModule([], $whenDict, [], 149);
    $msg = $m->validateSettings(twoRuleFlat('', ''));
    check('save gate: two unconditional rules on one field rejected, rows named',
        is_string($msg) && strpos($msg, 'Rule 1 and Rule 2') !== false
        && strpos($msg, 'at most ONE unconditional rule') !== false);
    $msg = $m->validateSettings(twoRuleFlat("[stype]='2'", "[stype]='2'"));
    check('save gate: identical conditions rejected',
        is_string($msg) && strpos($msg, 'identical condition') !== false);
    $msg = $m->validateSettings(twoRuleFlat("[stype]='1'", "[stype]='2'", 'pooled'));
    check('save gate: single/pooled mix rejected',
        is_string($msg) && strpos($msg, 'same field type') !== false);
    check('save gate: a legal 2-branch set passes',
        $m->validateSettings(twoRuleFlat("[stype]='1'", "[stype]='2'")) === null);
    check('save gate: conditional + else passes',
        $m->validateSettings(twoRuleFlat("[stype]='1'", '')) === null);

    echo sprintf("hook_php: %d checks, %d failure(s)\n", $n, $fail);
    exit($fail === 0 ? 0 : 1);
}
