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
        // JSMO plumbing (framework AJAX transport for @UVUNIQUE)
        public function initializeJavascriptModuleObject() { return '<script>/* jsmo bootstrap */</script>'; }
        public function getJavascriptModuleObjectName() { return 'ExternalModules.TEST.UniversalValidator'; }
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
        public static $groupNames = [];   // group_id => unique DAG name
        public static function getGroupNames($unique = false, $group_id = null) {
            return isset(self::$groupNames[$group_id]) ? self::$groupNames[$group_id] : '';
        }
        public static function getRecordIdField() { return 'record_id'; }
    }

    require_once __DIR__ . '/../UniversalValidator.php';

    // A module shaped like the REAL External Modules framework, which exposes
    // initializeJavascriptModuleObject()/getJavascriptModuleObjectName()
    // through __call() rather than declaring them. method_exists() returns
    // FALSE for such a method — which is how @UVUNIQUE shipped inert to a live
    // REDCap while every mock (whose stub DECLARES the methods) passed. This
    // class exists so that mistake cannot come back.
    class ProxyJsmoModule extends \INSPIRE\UniversalValidator\UniversalValidator {
        public $jsmoCalls = [];
        // Hide the stub's real declarations so only __call can serve them.
        public function initializeJavascriptModuleObject() { return $this->__call('initializeJavascriptModuleObject', []); }
        public function getJavascriptModuleObjectName() { return $this->__call('getJavascriptModuleObjectName', []); }
        public function __call($name, $args) {
            $this->jsmoCalls[] = $name;
            if ($name === 'initializeJavascriptModuleObject') { echo '<script>/* proxied jsmo */</script>'; return null; }
            if ($name === 'getJavascriptModuleObjectName') return 'ExternalModules.PROXY.UniversalValidator';
            throw new \BadMethodCallException($name);
        }
    }

    // A module whose framework offers NO JSMO at all (older/other build): the
    // absence must be LOGGED, never swallowed.
    class NoJsmoModule extends \INSPIRE\UniversalValidator\UniversalValidator {
        public function initializeJavascriptModuleObject() { throw new \BadMethodCallException('unavailable'); }
        public function getJavascriptModuleObjectName() { return null; }
    }

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

    // ---- @UVASSERT constraint mode: server audit ------------------------------
    // Constraints come through the annotation channel; the audit evaluates the
    // "assert" against the saved values and logs a failure as type "constraint".
    $cDict = [
        'record_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'cf'],
        'start'     => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'cf'],
        'end'       => ['field_type' => 'text', 'form_name' => 'cf',
                        'field_annotation' => '@UVASSERT={"assert":"[end]>=[start]","message":"end before start"}'],
        'grade'     => ['field_type' => 'dropdown', 'form_name' => 'cf',
                        'field_annotation' => "@UVASSERT=\"[grade]<>'9'\""],
    ];
    // violated: end (Jan 5) < start (Jan 10); grade = 9 (disallowed)
    $cData = [2 => [351 => ['record_id' => '2', 'start' => '2024-01-10', 'end' => '2024-01-05', 'grade' => '9']]];
    $m = newModule([], $cDict, $cData, 149);
    $m->redcap_save_record(149, '2', 'cf', 351, null, null, null, 1);
    $cf = loggedFields($m);
    check('constraint end<start audited', in_array('end', $cf, true));
    check('constraint on dropdown audited', in_array('grade', $cf, true));
    $endLog = null;
    foreach (invalidLogs($m) as $L) if ($L[1]['field'] === 'end') $endLog = $L[1];
    check('constraint log typed "constraint"', $endLog && $endLog['type'] === 'constraint');
    check('constraint reason names the assert', $endLog && strpos($endLog['reason'], 'assert:') === 0);

    // satisfied: end >= start and grade != 9 -> nothing logged
    $cData2 = [2 => [351 => ['record_id' => '2', 'start' => '2024-01-01', 'end' => '2024-02-01', 'grade' => '2']]];
    $m = newModule([], $cDict, $cData2, 149);
    $m->redcap_save_record(149, '2', 'cf', 351, null, null, null, 1);
    check('constraint satisfied -> no audit', count(invalidLogs($m)) === 0);

    // empty validated field -> inert (emptiness is @UVREQUIRED's job)
    $cData3 = [2 => [351 => ['record_id' => '2', 'start' => '2024-01-01', 'end' => '', 'grade' => '']]];
    $m = newModule([], $cDict, $cData3, 149);
    $m->redcap_save_record(149, '2', 'cf', 351, null, null, null, 1);
    check('empty constrained field -> inert (no audit)', count(invalidLogs($m)) === 0);

    // when-gate false -> constraint not enforced
    $cDictW = $cDict;
    $cDictW['end']['field_annotation'] = '@UVASSERT={"assert":"[end]>=[start]","when":"[grade]=\'1\'"}';
    $cDataW = [2 => [351 => ['record_id' => '2', 'start' => '2024-01-10', 'end' => '2024-01-05', 'grade' => '2']]];
    $m = newModule([], $cDictW, $cDataW, 149);
    $m->redcap_save_record(149, '2', 'cf', 351, null, null, null, 1);
    $endLogged = in_array('end', loggedFields($m), true);
    check('constraint when-gate false -> not audited', !$endLogged);

    // composition: @UVALIDATE (bad check) + @UVASSERT (bad assert) on ONE field
    // both audit independently (no false-duplicate skip)
    $cDictX = [
        'record_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'cf'],
        'other'     => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'cf'],
        'pid'       => ['field_type' => 'text', 'form_name' => 'cf',
                        'field_annotation' => '@UVALIDATE=verhoeff @UVASSERT="[pid]=[other]"'],
    ];
    $cDataX = [2 => [351 => ['record_id' => '2', 'pid' => '12345', 'other' => '99999']]]; // bad verhoeff AND pid!=other
    $m = newModule([], $cDictX, $cDataX, 149);
    $m->redcap_save_record(149, '2', 'cf', 351, null, null, null, 1);
    $pidLogs = array_filter(invalidLogs($m), function ($L) { return $L[1]['field'] === 'pid'; });
    $types = array_map(function ($L) { return $L[1]['type']; }, $pidLogs);
    check('compose: check rule audited pid', in_array('single', $types, true) || in_array('verhoeff', $types, true) || count($pidLogs) >= 1);
    check('compose: constraint rule audited pid independently', in_array('constraint', $types, true));

    // ---- @UVREQUIRED required mode: server audit ------------------------------
    // Required is the INVERSE emptiness rule: a blank field logs (type
    // "required", reason "required-blank") while the requirement is in force.
    $rDict = [
        'record_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'rf'],
        'consent'   => ['field_type' => 'radio', 'field_annotation' => '', 'form_name' => 'rf',
                        'select_choices_or_calculations' => '1, Yes | 0, No'],
        'phone'     => ['field_type' => 'text', 'form_name' => 'rf',
                        'field_annotation' => "@UVREQUIRED=\"[consent]='1'\""],
        'site'      => ['field_type' => 'dropdown', 'form_name' => 'rf',
                        'field_annotation' => '@UVREQUIRED'],
    ];
    // consent given, phone blank (missing from data = blank), site blank
    $rData = [2 => [351 => ['record_id' => '2', 'consent' => '1']]];
    $m = newModule([], $rDict, $rData, 149);
    $m->redcap_save_record(149, '2', 'rf', 351, null, null, null, 1);
    $rf = loggedFields($m);
    check('required-when true + blank -> audited', in_array('phone', $rf, true));
    check('unconditional required + blank -> audited', in_array('site', $rf, true));
    $phoneLog = null;
    foreach (invalidLogs($m) as $L) if ($L[1]['field'] === 'phone') $phoneLog = $L[1];
    check('required log typed "required"', $phoneLog && $phoneLog['type'] === 'required');
    check('required reason is required-blank', $phoneLog && $phoneLog['reason'] === 'required-blank');

    // consent NOT given -> phone requirement off; site still required
    $rData2 = [2 => [351 => ['record_id' => '2', 'consent' => '0']]];
    $m = newModule([], $rDict, $rData2, 149);
    $m->redcap_save_record(149, '2', 'rf', 351, null, null, null, 1);
    $rf = loggedFields($m);
    check('required-when false + blank -> NOT audited', !in_array('phone', $rf, true));
    check('unconditional required still audited', in_array('site', $rf, true));

    // both filled -> nothing logged
    $rData3 = [2 => [351 => ['record_id' => '2', 'consent' => '1', 'phone' => '677001122', 'site' => '3']]];
    $m = newModule([], $rDict, $rData3, 149);
    $m->redcap_save_record(149, '2', 'rf', 351, null, null, null, 1);
    check('required satisfied -> no audit', count(invalidLogs($m)) === 0);

    // whitespace-only value counts as blank
    $rData4 = [2 => [351 => ['record_id' => '2', 'consent' => '1', 'phone' => '   ', 'site' => '3']]];
    $m = newModule([], $rDict, $rData4, 149);
    $m->redcap_save_record(149, '2', 'rf', 351, null, null, null, 1);
    check('whitespace-only counts as blank', in_array('phone', loggedFields($m), true));

    // @UVREQUIRED on a calc field -> config error, not a rule
    $rDictC = $rDict;
    $rDictC['score'] = ['field_type' => 'calc', 'form_name' => 'rf', 'field_annotation' => '@UVREQUIRED'];
    $m = newModule([], $rDictC, $rData3, 149);
    $m->redcap_save_record(149, '2', 'rf', 351, null, null, null, 1);
    check('required-on-calc rejected: no false detection on the calc field',
        !in_array('score', loggedFields($m), true));

    // composition: @UVREQUIRED + @UVALIDATE on one field — blank triggers only
    // required (check is inert on blank); a bad filled value triggers only check
    $rDictX = [
        'record_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'rf'],
        'pid'       => ['field_type' => 'text', 'form_name' => 'rf',
                        'field_annotation' => '@UVALIDATE=verhoeff @UVREQUIRED'],
    ];
    $m = newModule([], $rDictX, [2 => [351 => ['record_id' => '2']]], 149); // pid blank
    $m->redcap_save_record(149, '2', 'rf', 351, null, null, null, 1);
    $types = array_map(function ($L) { return $L[1]['type']; }, invalidLogs($m));
    check('compose blank: required fires, check stays inert',
        $types === ['required']);
    $m = newModule([], $rDictX, [2 => [351 => ['record_id' => '2', 'pid' => '12345']]], 149); // bad verhoeff, filled
    $m->redcap_save_record(149, '2', 'rf', 351, null, null, null, 1);
    $types = array_map(function ($L) { return $L[1]['type']; }, invalidLogs($m));
    check('compose filled-bad: check fires, required satisfied',
        count($types) === 1 && $types[0] !== 'required');

    // ---- @UVCHOICES choices mode: server audit --------------------------------
    // A saved value that is a currently-hidden choice logs (type "choices",
    // reason "hidden-choice"); values are NEVER cleared, only reported.
    $cDict = [
        'record_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'cf'],
        'country'   => ['field_type' => 'radio', 'field_annotation' => '', 'form_name' => 'cf',
                        'select_choices_or_calculations' => '1, Cameroon | 2, Nigeria'],
        'site'      => ['field_type' => 'radio', 'form_name' => 'cf',
                        'select_choices_or_calculations' => '101, Douala | 102, Yaounde | 201, Lagos',
                        'field_annotation' => '@UVCHOICES={"when":"[country]=\'1\'","show":["101","102"]} '
                            . '@UVCHOICES={"when":"[country]=\'2\'","show":["201"]}'],
        'reach'     => ['field_type' => 'checkbox', 'form_name' => 'cf',
                        'select_choices_or_calculations' => '1, Radio | 2, TV | 9, Legacy',
                        'field_annotation' => '@UVCHOICES={"hide":["9"]}'],
    ];
    function choicesLog($m, $field) {
        foreach (invalidLogs($m) as $L) if ($L[1]['field'] === $field) return $L[1];
        return null;
    }
    // country=1 makes site a Cameroon-only list: a saved Lagos site is hidden
    $m = newModule([], $cDict, [2 => [351 => ['record_id' => '2', 'country' => '1', 'site' => '201']]], 149);
    $m->redcap_save_record(149, '2', 'cf', 351, null, null, null, 1);
    $L = choicesLog($m, 'site');
    check('hidden radio value -> audited', $L !== null);
    check('choices log typed "choices"', $L && $L['type'] === 'choices');
    check('choices reason is hidden-choice', $L && $L['reason'] === 'hidden-choice');
    // a shown value passes
    $m = newModule([], $cDict, [2 => [351 => ['record_id' => '2', 'country' => '1', 'site' => '101']]], 149);
    $m->redcap_save_record(149, '2', 'cf', 351, null, null, null, 1);
    check('shown radio value -> no audit', choicesLog($m, 'site') === null);
    // the OTHER branch hides the first branch's sites
    $m = newModule([], $cDict, [2 => [351 => ['record_id' => '2', 'country' => '2', 'site' => '101']]], 149);
    $m->redcap_save_record(149, '2', 'cf', 351, null, null, null, 1);
    check('other branch active: its hidden set applies', choicesLog($m, 'site') !== null);
    // no branch active (no else) -> the field is inert, nothing hidden
    $m = newModule([], $cDict, [2 => [351 => ['record_id' => '2', 'site' => '201']]], 149);
    $m->redcap_save_record(149, '2', 'cf', 351, null, null, null, 1);
    check('no active branch -> filter off, nothing audited', choicesLog($m, 'site') === null);
    // checkbox: a checked hidden code is the violation; unchecked is fine
    $m = newModule([], $cDict, [2 => [351 => ['record_id' => '2',
        'reach' => ['1' => '1', '2' => '0', '9' => '1']]]], 149);
    $m->redcap_save_record(149, '2', 'cf', 351, null, null, null, 1);
    $L = choicesLog($m, 'reach');
    check('checked hidden checkbox code -> audited', $L !== null && $L['reason'] === 'hidden-choice');
    $m = newModule([], $cDict, [2 => [351 => ['record_id' => '2',
        'reach' => ['1' => '1', '2' => '1', '9' => '0']]]], 149);
    $m->redcap_save_record(149, '2', 'cf', 351, null, null, null, 1);
    check('unchecked hidden checkbox code -> no audit', choicesLog($m, 'reach') === null);
    // a value outside the field's own choice list (missing-data code) is out of scope
    $m = newModule([], $cDict, [2 => [351 => ['record_id' => '2', 'country' => '1', 'site' => '-99']]], 149);
    $m->redcap_save_record(149, '2', 'cf', 351, null, null, null, 1);
    check('out-of-dictionary value (MDC) -> never flagged', choicesLog($m, 'site') === null);
    // @UVCHOICES on a text field / unknown code / matrix membership -> config
    // errors (visible in the injected config), never audit detections
    $cDictBad = $cDict;
    $cDictBad['freetext'] = ['field_type' => 'text', 'form_name' => 'cf',
        'field_annotation' => '@UVCHOICES={"hide":["1"]}'];
    $cDictBad['site2'] = ['field_type' => 'radio', 'form_name' => 'cf',
        'select_choices_or_calculations' => '1, A | 2, B',
        'field_annotation' => '@UVCHOICES={"show":["999"]}'];
    $cDictBad['mx'] = ['field_type' => 'radio', 'form_name' => 'cf', 'matrix_group_name' => 'grid1',
        'select_choices_or_calculations' => '1, A | 2, B',
        'field_annotation' => '@UVCHOICES={"hide":["1"]}'];
    $m = newModule([], $cDictBad, [2 => [351 => ['record_id' => '2',
        'freetext' => '1', 'site2' => '999', 'mx' => '1']]], 149);
    ob_start();
    $m->redcap_data_entry_form_top(149, '2', 'cf', 351, null, 1);
    $html = ob_get_clean();
    $cfg = null;
    if (preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm)) {
        $cfg = json_decode($mm[1], true);
    }
    $cfgErrs = [];
    foreach (($cfg ? $cfg['rules'] : []) as $r) {
        if (!empty($r['configError'])) $cfgErrs[$r['fields'][0]] = $r['configError'];
    }
    check('choices on a text field -> configError', isset($cfgErrs['freetext'])
        && strpos($cfgErrs['freetext'], 'radio, dropdown or checkbox') !== false);
    check('unknown code -> configError naming the real codes', isset($cfgErrs['site2'])
        && strpos($cfgErrs['site2'], '"999"') !== false && strpos($cfgErrs['site2'], '1, 2') !== false);
    check('matrix field -> configError', isset($cfgErrs['mx'])
        && strpos($cfgErrs['mx'], 'matrix') !== false);
    $m->logCalls = [];
    $m->redcap_save_record(149, '2', 'cf', 351, null, null, null, 1);
    check('config-error choices rules produce no detections',
        choicesLog($m, 'freetext') === null && choicesLog($m, 'site2') === null && choicesLog($m, 'mx') === null);
    // the injected site rule is a branch rule carrying choicesAll (the full list)
    $siteRule = null;
    foreach (($cfg ? $cfg['rules'] : []) as $r) {
        if ($r['fields'] === ['site'] && $r['type'] === 'choices') $siteRule = $r;
    }
    check('site rule ships as a choices branch rule', $siteRule && isset($siteRule['branches'])
        && count($siteRule['branches']) === 2);
    check('branches carry choicesAll from the dictionary',
        $siteRule && $siteRule['branches'][0]['choicesAll'] === ['101', '102', '201']);

    // fixture: the hidden-set contract shared with tests/choices_dom_js.cjs —
    // for every case, a saved value is flagged IFF the fixture says it hides
    $fx = json_decode(file_get_contents(__DIR__ . '/choices_fixture.json'), true);
    check('choices fixture loads', is_array($fx) && !empty($fx['cases']));
    foreach ($fx['cases'] as $case) {
        $enumParts = [];
        foreach ($case['all'] as $c) $enumParts[] = $c . ', Label ' . $c;
        $tagCfg = isset($case['show'])
            ? '{"show":' . json_encode($case['show']) . '}'
            : '{"hide":' . json_encode($case['hide']) . '}';
        $fxDict = [
            'record_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'xf'],
            'pick'      => ['field_type' => 'radio', 'form_name' => 'xf',
                            'select_choices_or_calculations' => implode(' | ', $enumParts),
                            'field_annotation' => '@UVCHOICES=' . $tagCfg],
        ];
        foreach ($case['all'] as $code) {
            $m = newModule([], $fxDict, [2 => [351 => ['record_id' => '2', 'pick' => $code]]], 149);
            $m->redcap_save_record(149, '2', 'xf', 351, null, null, null, 1);
            $flagged = choicesLog($m, 'pick') !== null;
            check('fixture "' . $case['name'] . '": code ' . $code,
                $flagged === in_array($code, $case['hidden'], true));
        }
    }

    // ---- Configure-dialog channel for the new modes (Phase 3) -----------------
    // Constraint and Required rules built from dialog rows (settingRowToRule),
    // not annotations — same audit semantics, same shared validator.
    $dlgDict = [
        'record_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'df'],
        'start'     => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'df'],
        'end'       => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'df'],
        'grade'     => ['field_type' => 'dropdown', 'field_annotation' => '', 'form_name' => 'df',
                        'select_choices_or_calculations' => '1, A | 2, B | 9, Unknown'],
        'score'     => ['field_type' => 'calc', 'field_annotation' => '', 'form_name' => 'df',
                        'select_choices_or_calculations' => '[start]*2'],
    ];
    function dlgRow($over) {
        return array_merge([
            'rule-type' => 'single', 'fields' => [], 'fields-csv' => '', 'when' => '',
            'assert' => '', 'message' => '', 'algorithm' => 'iso7064_mod37_36', 'source' => '',
            'suggest-fix' => '', 'pattern' => '', 'strip' => '', 'keep-chars' => '',
            'id-lengths' => '', 'id-min-len' => '', 'id-max-len' => '',
            'expected-count' => '', 'block-save' => 'off',
        ], $over);
    }
    // dialog constraint on a DROPDOWN (extended field type), violated
    $dlgRules = [dlgRow(['rule-type' => 'constraint', 'fields' => ['grade'],
                         'assert' => "[grade]<>'9'", 'message' => 'Pick a real grade'])];
    $m = newModule($dlgRules, $dlgDict, [2 => [351 => ['record_id' => '2', 'grade' => '9']]], 149);
    $m->redcap_save_record(149, '2', 'df', 351, null, null, null, 1);
    $one = invalidLogs($m);
    check('dialog constraint on dropdown audited', count($one) === 1
        && $one[0][1]['field'] === 'grade' && $one[0][1]['type'] === 'constraint');
    // satisfied -> silent
    $m = newModule($dlgRules, $dlgDict, [2 => [351 => ['record_id' => '2', 'grade' => '2']]], 149);
    $m->redcap_save_record(149, '2', 'df', 351, null, null, null, 1);
    check('dialog constraint satisfied -> no audit', count(invalidLogs($m)) === 0);
    // dialog constraint: irrelevant algorithm/pattern boxes are IGNORED, not leaked
    $dlgRules2 = [dlgRow(['rule-type' => 'constraint', 'fields' => ['end'],
                          'assert' => '[end]>=[start]', 'algorithm' => 'verhoeff',
                          'pattern' => '(a+)+'])]; // risky pattern would be rejected if read
    $m = newModule($dlgRules2, $dlgDict, [2 => [351 => ['record_id' => '2', 'start' => '5', 'end' => '3']]], 149);
    $m->redcap_save_record(149, '2', 'df', 351, null, null, null, 1);
    check('dialog constraint ignores algorithm/pattern boxes (no configError, audits)',
        count(invalidLogs($m)) === 1 && count(logsOf($m, 'uvalidate-unconfigurable')) === 0);

    // dialog required with a when-gate
    $dlgRules3 = [dlgRow(['rule-type' => 'required', 'fields' => ['end'],
                          'when' => "[grade]='1'", 'message' => 'End date needed for grade A'])];
    $m = newModule($dlgRules3, $dlgDict, [2 => [351 => ['record_id' => '2', 'grade' => '1']]], 149);
    $m->redcap_save_record(149, '2', 'df', 351, null, null, null, 1);
    $one = invalidLogs($m);
    check('dialog required-when true + blank -> audited', count($one) === 1
        && $one[0][1]['field'] === 'end' && $one[0][1]['type'] === 'required');
    $m = newModule($dlgRules3, $dlgDict, [2 => [351 => ['record_id' => '2', 'grade' => '2']]], 149);
    $m->redcap_save_record(149, '2', 'df', 351, null, null, null, 1);
    check('dialog required-when false -> no audit', count(invalidLogs($m)) === 0);

    // dialog compose: check rule + constraint rule on ONE field, both audit
    $dlgRules4 = [
        dlgRow(['fields' => ['end'], 'algorithm' => 'verhoeff']),
        dlgRow(['rule-type' => 'constraint', 'fields' => ['end'], 'assert' => '[end]>=[start]']),
    ];
    $m = newModule($dlgRules4, $dlgDict, [2 => [351 => ['record_id' => '2', 'start' => '99999', 'end' => '12345']]], 149);
    $m->redcap_save_record(149, '2', 'df', 351, null, null, null, 1);
    $types = array_map(function ($L) { return $L[1]['type']; }, invalidLogs($m));
    sort($types);
    check('dialog compose: check + constraint both audit one field',
        $types === ['constraint', 'single']);

    // ---- validateSettings gate for the new modes ----
    function modeFlat($rows) {
        $keys = ['rule-type', 'fields', 'fields-csv', 'when', 'assert', 'message',
                 'unique-with', 'unique-scope', 'unique-surveys', 'algorithm',
                 'source', 'suggest-fix', 'pattern', 'strip', 'keep-chars', 'id-lengths',
                 'id-min-len', 'id-max-len', 'expected-count', 'block-save'];
        $flat = ['rules' => array_fill(0, count($rows), true)];
        foreach ($keys as $k) {
            $flat[$k] = [];
            foreach ($rows as $r) $flat[$k][] = isset($r[$k]) ? $r[$k] : '';
        }
        return $flat;
    }
    $m = newModule([], $dlgDict, [], 149);
    $msg = $m->validateSettings(modeFlat([
        ['rule-type' => 'constraint', 'fields' => ['end'], 'algorithm' => 'iso7064_mod37_36', 'block-save' => 'off'],
    ]));
    check('save gate: constraint without an assert rejected',
        is_string($msg) && strpos($msg, 'assert') !== false);
    $msg = $m->validateSettings(modeFlat([
        ['rule-type' => 'constraint', 'fields' => ['end'], 'assert' => "datediff([a],[b],'d')>3",
         'algorithm' => 'iso7064_mod37_36', 'block-save' => 'off'],
    ]));
    check('save gate: assert with a function rejected (dialect subset)',
        is_string($msg) && strpos($msg, 'assert') !== false);
    $msg = $m->validateSettings(modeFlat([
        ['rule-type' => 'required', 'fields' => ['score'], 'algorithm' => 'iso7064_mod37_36', 'block-save' => 'off'],
    ]));
    check('save gate: required-on-calc rejected',
        is_string($msg) && strpos($msg, 'not calc') !== false);
    $msg = $m->validateSettings(modeFlat([
        ['rule-type' => 'single', 'fields' => ['end'], 'algorithm' => 'verhoeff', 'block-save' => 'off'],
        ['rule-type' => 'constraint', 'fields' => ['end'], 'assert' => '[end]>=[start]', 'block-save' => 'off'],
    ]));
    check('save gate: check + constraint on one field COMPOSE (no false conflict)', $msg === null);
    $msg = $m->validateSettings(modeFlat([
        ['rule-type' => 'constraint', 'fields' => ['grade'], 'assert' => "[grade]<>'9'",
         'message' => 'Pick a real grade', 'block-save' => 'hard'],
    ]));
    check('save gate: sound dropdown constraint passes', $msg === null);
    $msg = $m->validateSettings(modeFlat([
        ['rule-type' => 'constraint', 'fields' => ['end'], 'assert' => '[nosuch]>3', 'block-save' => 'off'],
    ]));
    check('save gate: assert referencing an unknown field rejected',
        is_string($msg) && strpos($msg, 'nosuch') !== false);

    // ---- @UVUNIQUE unique mode: AJAX endpoint + audit backstop (Phase 4) -----
    $uqDict = [
        'record_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'uf'],
        'pid'       => ['field_type' => 'text', 'form_name' => 'uf', 'field_annotation' => '@UVUNIQUE'],
        'spec'      => ['field_type' => 'text', 'form_name' => 'uf',
                        'field_annotation' => '@UVUNIQUE={"with":["site"],"message":"Specimen already registered"}'],
        'site'      => ['field_type' => 'dropdown', 'field_annotation' => '', 'form_name' => 'uf',
                        'select_choices_or_calculations' => '1, North | 2, South'],
        'plain'     => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'uf'],
        'surv'      => ['field_type' => 'text', 'form_name' => 'uf',
                        'field_annotation' => '@UVUNIQUE={"surveys":true}'],
    ];
    $uqData = [
        '1' => [351 => ['record_id' => '1', 'pid' => 'AB100', 'spec' => 'S-77', 'site' => '1',
                        'surv' => 'X9', 'redcap_data_access_group' => 'north']],
        '2' => [351 => ['record_id' => '2', 'pid' => 'AB200', 'spec' => 'S-77', 'site' => '2',
                        'redcap_data_access_group' => 'south']],
    ];
    function ajaxCall($m, $payload, $record = '3', $survey = null, $user = 'staff1', $gid = null) {
        return $m->redcap_module_ajax('unique-check', $payload, 149, $record, 'uf', 351, 1,
            $survey, null, null, 'DataEntry/index.php', '', $user, $gid);
    }
    // collision found, own record excluded, staff sees the record id
    $m = newModule([], $uqDict, $uqData, 149);
    $r = ajaxCall($m, ['field' => 'pid', 'values' => ['pid' => 'AB100']]);
    check('ajax: duplicate detected with record id for staff', $r['used'] === true && $r['record'] === '1');
    $r = ajaxCall($m, ['field' => 'pid', 'values' => ['pid' => 'AB100']], '1');
    check('ajax: own record never collides with itself', $r['used'] === false);
    $r = ajaxCall($m, ['field' => 'pid', 'values' => ['pid' => 'FRESH1']]);
    check('ajax: unused value is free', $r['used'] === false && $r['record'] === null);
    check('ajax: trimming applies', ajaxCall($m, ['field' => 'pid', 'values' => ['pid' => '  AB100  ']])['used'] === true);
    // anti-oracle: a field with no unique rule is refused
    $r = ajaxCall($m, ['field' => 'plain', 'values' => ['plain' => 'AB100']]);
    check('ajax: non-unique field refused (no oracle)', isset($r['error']));
    $r = ajaxCall($m, ['field' => 'no_such', 'values' => []]);
    check('ajax: unknown field refused', isset($r['error']));
    // composite key: same spec at a DIFFERENT site is free; same site collides
    $r = ajaxCall($m, ['field' => 'spec', 'values' => ['spec' => 'S-77', 'site' => '1']]);
    check('ajax: composite collision (same spec + same site)', $r['used'] === true && $r['record'] === '1');
    $r = ajaxCall($m, ['field' => 'spec', 'values' => ['spec' => 'S-77', 'site' => '9']]);
    check('ajax: composite free (same spec, other site)', $r['used'] === false);
    // surveys: opt-in enforced; opted-in answers boolean-only
    $r = ajaxCall($m, ['field' => 'pid', 'values' => ['pid' => 'AB100']], '3', 'shash', null);
    check('ajax: survey refused without opt-in', isset($r['error']));
    $r = ajaxCall($m, ['field' => 'surv', 'values' => ['surv' => 'X9']], '3', 'shash', null);
    check('ajax: opted-in survey answers used=true, no record id', $r['used'] === true && $r['record'] === null);
    // DAG masking: staff in DAG 'south' colliding with a 'north' record
    \REDCap::$groupNames = [7 => 'south'];
    $r = ajaxCall($m, ['field' => 'pid', 'values' => ['pid' => 'AB100']], '3', null, 'staff1', 7);
    check('ajax: record id masked outside the user\'s DAG', $r['used'] === true && $r['record'] === null);
    $r = ajaxCall($m, ['field' => 'pid', 'values' => ['pid' => 'AB200']], '3', null, 'staff1', 7);
    check('ajax: record id shown inside the user\'s DAG', $r['used'] === true && $r['record'] === '2');
    \REDCap::$groupNames = [];
    // payload hygiene
    check('ajax: overlong value refused', isset(ajaxCall($m, ['field' => 'pid', 'values' => ['pid' => str_repeat('x', 1200)]])['error']));
    check('ajax: bad field name refused', isset(ajaxCall($m, ['field' => 'pid; DROP', 'values' => []])['error']));
    check('ajax: unknown action refused', isset($m->redcap_module_ajax('other', [], 149, '3', 'uf', 351, 1, null, null, null, '', '', 'u', null)['error']));

    // scope=event: same value on ANOTHER event is free
    $uqDictE = $uqDict;
    $uqDictE['pid']['field_annotation'] = '@UVUNIQUE=event';
    $uqDataE = ['1' => [352 => ['record_id' => '1', 'pid' => 'EV100']]]; // other event only
    $m = newModule([], $uqDictE, $uqDataE, 149);
    // the mock getData ignores the events param — emulate the filter by checking
    // what the module ASKED for instead
    ajaxCall($m, ['field' => 'pid', 'values' => ['pid' => 'EV100']]);
    $p = \REDCap::$lastGetDataParams;
    check('ajax: event scope restricts the getData read to the saved event',
        is_array($p) && isset($p['events']) && $p['events'] === [351]);

    // audit backstop: a collision in SAVED data logs type "unique"
    $m = newModule([], $uqDict, [
        '1' => [351 => ['record_id' => '1', 'pid' => 'DUP-1']],
        '2' => [351 => ['record_id' => '2', 'pid' => 'DUP-1']],
    ], 149);
    $m->redcap_save_record(149, '2', 'uf', 351, null, null, null, 1);
    $uLogs = array_values(array_filter(invalidLogs($m), function ($L) { return $L[1]['type'] === 'unique'; }));
    check('audit: saved duplicate logged as type unique', count($uLogs) === 1
        && $uLogs[0][1]['field'] === 'pid' && $uLogs[0][1]['reason'] === 'duplicate-value');
    $m = newModule([], $uqDict, [
        '1' => [351 => ['record_id' => '1', 'pid' => 'ONLY-1']],
    ], 149);
    $m->redcap_save_record(149, '1', 'uf', 351, null, null, null, 1);
    check('audit: unique value -> no unique log', count(array_filter(invalidLogs($m), function ($L) { return $L[1]['type'] === 'unique'; })) === 0);

    // jsmoName + bootstrap injected only when a unique rule is live
    $m = newModule([], $uqDict, [], 149);
    ob_start();
    $m->redcap_data_entry_form_top(149, '1', 'uf', 351, null, 1);
    $html = ob_get_clean();
    $cfg = null;
    if (preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm)) {
        $cfg = json_decode($mm[1], true);
    }
    check('unique page: jsmoName in the injected config',
        $cfg && isset($cfg['jsmoName']) && $cfg['jsmoName'] === 'ExternalModules.TEST.UniversalValidator');
    check('unique page: JSMO bootstrap script emitted', strpos($html, '/* jsmo bootstrap */') !== false);
    $m = newModule($dialogRules, $dictionary, [], 149); // no unique rules
    ob_start();
    $m->redcap_data_entry_form_top(149, '1', 'id_validation_test', 351, null, 1);
    $html = ob_get_clean();
    check('non-unique page: no JSMO, no jsmoName',
        strpos($html, 'jsmoName') === false && strpos($html, '/* jsmo bootstrap */') === false);

    // dialog channel: unique rule with bad "with" rejected at save time
    $m = newModule([], $uqDict, [], 149);
    $msg = $m->validateSettings(modeFlat([
        ['rule-type' => 'unique', 'fields' => ['pid'], 'unique-with' => 'no_such_field', 'block-save' => 'off'],
    ]));
    check('save gate: unique with unknown composite field rejected',
        is_string($msg) && strpos($msg, 'no_such_field') !== false);
    $msg = $m->validateSettings(modeFlat([
        ['rule-type' => 'unique', 'fields' => ['pid'], 'unique-with' => 'pid', 'block-save' => 'off'],
    ]));
    check('save gate: self-composite rejected', is_string($msg) && strpos($msg, 'must not name') !== false);
    $msg = $m->validateSettings(modeFlat([
        ['rule-type' => 'unique', 'fields' => ['spec'], 'unique-with' => 'site', 'unique-scope' => 'event', 'block-save' => 'hard'],
    ]));
    check('save gate: sound unique rule passes', $msg === null);

    // ---- scanProject: the retrospective sweep (Phase 5) -----------------------
    // A project with all four rule kinds and seeded violations across records;
    // the scan must find each one via the SAME dispatch as the audit.
    $scDict = [
        'record_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'sf'],
        'pid'       => ['field_type' => 'text', 'form_name' => 'sf', 'field_annotation' => '@UVALIDATE=verhoeff'],
        'start'     => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'sf'],
        'end'       => ['field_type' => 'text', 'form_name' => 'sf',
                        'field_annotation' => '@UVASSERT={"assert":"[end]>=[start]","message":"m"}'],
        'phone'     => ['field_type' => 'text', 'form_name' => 'sf', 'field_annotation' => '@UVREQUIRED'],
        'sid'       => ['field_type' => 'text', 'form_name' => 'sf', 'field_annotation' => '@UVUNIQUE'],
    ];
    // 75 = valid verhoeff check digit for payload 7; 12345 is invalid.
    $scData = [
        '1' => [351 => ['record_id' => '1', 'pid' => '12345', 'start' => '2024-05-01',
                        'end' => '2024-04-01', 'phone' => '', 'sid' => 'DUP-9',
                        'redcap_data_access_group' => 'north']],
        '2' => [351 => ['record_id' => '2', 'pid' => '70', 'start' => '2024-01-01',
                        'end' => '2024-02-01', 'phone' => '677', 'sid' => 'DUP-9',
                        'redcap_data_access_group' => 'south']],
        '3' => [351 => ['record_id' => '3', 'pid' => '70', 'start' => '2024-01-01',
                        'end' => '2024-02-01', 'phone' => '678', 'sid' => 'FREE-1',
                        'redcap_data_access_group' => 'north']],
    ];
    $m = newModule([], $scDict, $scData, 149);
    $res = $m->scanProject(149);
    check('scan: stats count records and rules', $res['stats']['records'] === 3 && $res['stats']['rules'] === 4);
    function scanHits($res, $type) {
        return array_values(array_filter($res['violations'], function ($v) use ($type) { return $v['type'] === $type; }));
    }
    $chk = scanHits($res, 'single');
    check('scan: bad check character found (record 1 only)',
        count($chk) === 1 && $chk[0]['record'] === '1' && $chk[0]['field'] === 'pid');
    $con = scanHits($res, 'constraint');
    check('scan: violated constraint found (record 1 only)',
        count($con) === 1 && $con[0]['record'] === '1' && $con[0]['field'] === 'end');
    $req = scanHits($res, 'required');
    check('scan: blank required found (record 1 only)',
        count($req) === 1 && $req[0]['record'] === '1' && $req[0]['field'] === 'phone'
        && $req[0]['reason'] === 'required-blank');
    $unq = scanHits($res, 'unique');
    $unqRecs = array_map(function ($v) { return $v['record']; }, $unq);
    sort($unqRecs);
    check('scan: duplicate pair flagged on BOTH records',
        count($unq) === 2 && $unqRecs === ['1', '2'] && $unq[0]['field'] === 'sid');
    check('scan: no value is ever in the report',
        !array_filter($res['violations'], function ($v) { return isset($v['value']); }));

    // DAG filter: scanning as a 'north' user sees only north records — and the
    // duplicate pair (split across DAGs, project scope) is NOT reported because
    // the south record is outside the visible set.
    $res = $m->scanProject(149, 'north');
    check('scan: DAG filter scopes the record set', $res['stats']['records'] === 2);
    $recs = array_unique(array_map(function ($v) { return $v['record']; }, $res['violations']));
    check('scan: DAG filter never names another group\'s record', !in_array('2', $recs, true));

    // unique scope=dag in the scan: same value in two DAGs is NOT a duplicate
    $scDictD = $scDict;
    $scDictD['sid']['field_annotation'] = '@UVUNIQUE=dag';
    $m = newModule([], $scDictD, $scData, 149);
    $res = $m->scanProject(149);
    check('scan: dag-scoped unique ignores cross-DAG repeats', count(scanHits($res, 'unique')) === 0);

    // repeating instruments: a violation on instance 2 carries its instance
    $scDataR = [
        '1' => [
            351 => ['record_id' => '1', 'start' => '2024-05-01', 'phone' => 'x', 'pid' => '70', 'sid' => 'A1'],
            'repeat_instances' => [351 => ['sf' => [
                1 => ['end' => '2024-06-01'],
                2 => ['end' => '2024-01-01'],   // violates end>=start
            ]]],
        ],
    ];
    $m = newModule([], $scDict, $scDataR, 149);
    $res = $m->scanProject(149);
    $con = scanHits($res, 'constraint');
    check('scan: repeat instance violation carries its instance number',
        count($con) === 1 && $con[0]['instance'] === 2 && $con[0]['record'] === '1');

    // chunking: a small chunk size still scans every record
    $m = newModule([], $scDict, $scData, 149);
    $res = $m->scanProject(149, null, 1);
    check('scan: chunked read covers all records',
        $res['stats']['records'] === 3 && count(scanHits($res, 'unique')) === 2);

    // unconfigurable rules are reported once, not per record
    $scDictU = $scDict;
    $scDictU['end']['field_annotation'] = '@UVASSERT={"assert":"[end]>=[start]","when":"[ghost]=\'1\'"}';
    // (unknown ref caught at config time -> configError rule -> excluded from live rules)
    $m = newModule([], $scDictU, $scData, 149);
    $res = $m->scanProject(149);
    check('scan: config-error rules are excluded, not evaluated',
        count(scanHits($res, 'constraint')) === 0);

    // ---- @UVUNIQUE survey opt-in is REFUSED on Identifier fields --------------
    // Security-scan advisory (15 Jul 2026): the survey path is unauthenticated,
    // so an "already used" answer on an IDENTIFIER would let anyone with the
    // survey link test whether a named person is enrolled. REDCap already knows
    // which fields those are, so the module refuses rather than warns.
    $idDict = [
        'record_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'if'],
        'nat_id'    => ['field_type' => 'text', 'form_name' => 'if', 'identifier' => 'y',
                        'field_annotation' => '@UVUNIQUE={"surveys":true}'],
        'token'     => ['field_type' => 'text', 'form_name' => 'if', 'identifier' => '',
                        'field_annotation' => '@UVUNIQUE={"surveys":true}'],
        'nat_id2'   => ['field_type' => 'text', 'form_name' => 'if', 'identifier' => 'y',
                        'field_annotation' => '@UVUNIQUE'],
    ];
    $idData = ['1' => [351 => ['record_id' => '1', 'nat_id' => 'ID-1', 'token' => 'TK-1', 'nat_id2' => 'ID-2']]];
    $m = newModule([], $idDict, $idData, 149);
    ob_start();
    $m->redcap_data_entry_form_top(149, '2', 'if', 351, null, 1);
    $html = ob_get_clean();
    check('annotation: survey opt-in on an Identifier is a visible config error',
        strpos($html, 'cannot be enabled on a field REDCap marks as an Identifier') !== false);
    $cfg = null;
    if (preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm)) {
        $cfg = json_decode($mm[1], true);
    }
    $liveFieldsOf = function ($cfg) {
        $out = [];
        foreach (($cfg['rules'] ?? []) as $r) {
            if (!empty($r['configError'])) continue;
            foreach (($r['fields'] ?? []) as $f) $out[] = $f;
        }
        return $out;
    };
    $liveF = $liveFieldsOf($cfg);
    check('  the refused rule is NOT live', !in_array('nat_id', $liveF, true));
    check('  a NON-identifier survey opt-in still works', in_array('token', $liveF, true));
    check('  unique on an Identifier is fine WITHOUT the survey opt-in', in_array('nat_id2', $liveF, true));

    // endpoint defence in depth: even if such a rule existed, surveys get nothing
    $m = newModule([], $idDict, $idData, 149);
    $r = $m->redcap_module_ajax('unique-check', ['field' => 'nat_id', 'values' => ['nat_id' => 'ID-1']],
        149, '2', 'if', 351, 1, 'shash', null, null, '', '', null, null);
    check('endpoint: survey check on an Identifier field refused', isset($r['error']));

    // THE UNAUTHENTICATED, NON-SURVEY CALLER (no session, no survey hash).
    // "unique-check" is in no-auth-ajax-actions, so this request is reachable by
    // anyone. v1.4.1 keyed its guards on $survey_hash, so omitting the hash
    // skipped the opt-in check, the Identifier refusal AND the rate limit, and
    // still answered used/free — the exact oracle the guard exists to prevent.
    // Guards must key on AUTHENTICATION, not on survey-ness.
    $anon = function ($m, $field, $val) {
        return $m->redcap_module_ajax('unique-check', ['field' => $field, 'values' => [$field => $val]],
            149, '2', 'if', 351, 1, null, null, null, '', '', null, null);  // no hash, no user
    };
    $m = newModule([], $idDict, $idData, 149);
    $r = $anon($m, 'nat_id2', 'ID-2');   // Identifier, unique rule, surveys NOT opted in
    check('anon caller (no hash, no user): Identifier field refused, no oracle',
        isset($r['error']) && !isset($r['used']));
    $r = $anon($m, 'nat_id', 'ID-1');    // Identifier + surveys:true -> refused config, but be sure
    check('anon caller: Identifier + survey opt-in still refused', isset($r['error']) && !isset($r['used']));
    $r = $anon($m, 'token', 'TK-1');     // non-identifier WITH surveys opt-in -> allowed, boolean only
    check('anon caller: opted-in non-identifier answers boolean only',
        isset($r['used']) && $r['used'] === true && $r['record'] === null);
    // a unique rule WITHOUT the survey opt-in must never answer an anon caller
    $noOptIn = $idDict;
    $noOptIn['plain2'] = ['field_type' => 'text', 'form_name' => 'if', 'identifier' => '',
                          'field_annotation' => '@UVUNIQUE'];
    $m = newModule([], $noOptIn, ['1' => [351 => ['record_id' => '1', 'plain2' => 'P-1']]], 149);
    $r = $anon($m, 'plain2', 'P-1');
    check('anon caller: unique rule without the survey opt-in refused',
        isset($r['error']) && !isset($r['used']));
    // ...while the SAME field answers a logged-in staff user in full
    $r = $m->redcap_module_ajax('unique-check', ['field' => 'plain2', 'values' => ['plain2' => 'P-1']],
        149, '2', 'if', 351, 1, null, null, null, '', '', 'staff1', null);
    check('staff caller: same field answers with the record id',
        isset($r['used']) && $r['used'] === true && $r['record'] === '1');
    // ... while staff (authenticated) still get the full answer on that field.
    // (newModule() rebinds the shared \REDCap::$data static, so restore the
    // identifier dataset first — the anon block above pointed it elsewhere.)
    $m = newModule([], $idDict, $idData, 149);
    $r = $m->redcap_module_ajax('unique-check', ['field' => 'nat_id2', 'values' => ['nat_id2' => 'ID-2']],
        149, '2', 'if', 351, 1, null, null, null, '', '', 'staff1', null);
    check('endpoint: staff check on an Identifier field still answers',
        isset($r['used']) && $r['used'] === true && $r['record'] === '1');

    // F3: the identifier oracle must FAIL CLOSED when the data dictionary is
    // unreadable. A settings-channel unique rule with the survey opt-in survives a
    // null dictionary (settingRowToRule skips field/type/identifier gating when the
    // dd is unavailable), and projectIdentifierFields() then returns null — under
    // which isIdentifier(null, …) is false. findCollision() needs no dictionary, so
    // it could still resolve the value; the endpoint must refuse the unauthenticated
    // answer rather than reopen the existence oracle. Empty dict [] => dataDictionary()
    // is null; the value SECRET-ID exists in another record so a leak WOULD occur.
    $survSettingRule = [[
        'rule-type' => 'unique', 'fields' => ['nat_id'], 'unique-surveys' => '1',
    ]];
    $m = newModule($survSettingRule, [],
        ['1' => [351 => ['record_id' => '1', 'nat_id' => 'SECRET-ID']]], 149);
    $r = $m->redcap_module_ajax('unique-check', ['field' => 'nat_id', 'values' => ['nat_id' => 'SECRET-ID']],
        149, '2', 'if', 351, 1, null, null, null, '', '', null, null);  // anon: no hash, no user
    check('F3: anon oracle fails CLOSED when the dictionary is unreadable (no used/free leaked)',
        isset($r['error']) && !isset($r['used']));

    // H-01: the Identifier refusal must cover COMPOSITE "with" fields, not only the
    // primary field. A survey uniqueness answer whose key includes an identifying
    // value (surveys:true with ["dob"]) is the same unauthenticated existence-oracle
    // risk the single-field refusal closes — on the primary field OR any with-field.
    $compDict = [
        'record_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'if'],
        'tok' => ['field_type' => 'text', 'form_name' => 'if', 'identifier' => '',
                  'field_annotation' => '@UVUNIQUE={"surveys":true,"with":["dob"]}'],
        'dob' => ['field_type' => 'text', 'form_name' => 'if', 'identifier' => 'y', 'field_annotation' => ''],
    ];
    $compData = ['1' => [351 => ['record_id' => '1', 'tok' => 'TK-9', 'dob' => '1980-01-01']]];
    $m = newModule([], $compDict, $compData, 149);
    ob_start();
    $m->redcap_data_entry_form_top(149, '2', 'if', 351, null, 1);
    $html = ob_get_clean();
    check('H-01: annotation survey opt-in with an Identifier "with" field is a config error',
        strpos($html, 'Identifier') !== false && strpos($html, 'dob') !== false);
    $cfgC = null;
    if (preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm)) {
        $cfgC = json_decode($mm[1], true);
    }
    check('H-01: the composite-Identifier rule is NOT live', !in_array('tok', $liveFieldsOf($cfgC), true));
    // endpoint defence in depth: an anon caller gets no used/free for that rule
    $m = newModule([], $compDict, $compData, 149);
    $r = $m->redcap_module_ajax('unique-check', ['field' => 'tok', 'values' => ['tok' => 'TK-9', 'dob' => '1980-01-01']],
        149, '2', 'if', 351, 1, null, null, null, '', '', null, null);
    check('H-01: anon composite check involving an Identifier with-field gets no oracle',
        isset($r['error']) && !isset($r['used']));
    // settings save-gate refuses the same combination, naming the identifier field
    $m = newModule([], $compDict, [], 149);
    $msg = $m->validateSettings(modeFlat([
        ['rule-type' => 'unique', 'fields' => ['tok'], 'unique-with' => 'dob',
         'unique-surveys' => '1', 'block-save' => 'off'],
    ]));
    check('H-01: save gate refuses survey opt-in with an Identifier composite field',
        is_string($msg) && strpos($msg, 'Identifier') !== false && strpos($msg, 'dob') !== false);
    // control: a NON-identifier composite field is still allowed
    $okDict = $compDict;
    $okDict['tok'] = ['field_type' => 'text', 'form_name' => 'if', 'identifier' => '',
                      'field_annotation' => '@UVUNIQUE={"surveys":true,"with":["site"]}'];
    $okDict['site'] = ['field_type' => 'text', 'form_name' => 'if', 'identifier' => '', 'field_annotation' => ''];
    $m = newModule([], $okDict, ['1' => [351 => ['record_id' => '1', 'tok' => 'TK-9', 'site' => 'N']]], 149);
    ob_start();
    $m->redcap_data_entry_form_top(149, '2', 'if', 351, null, 1);
    $html = ob_get_clean();
    $cfgOK = null;
    if (preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm)) {
        $cfgOK = json_decode($mm[1], true);
    }
    check('H-01 control: survey opt-in with a NON-identifier composite field still works',
        in_array('tok', $liveFieldsOf($cfgOK), true));

    // H-03: a cross-instrument / repeating composite @UVUNIQUE must agree across the
    // live endpoint, the post-save audit, and the scan. Rule on a REPEATING lab
    // field (specimen_id) composited with an EVENT-level field (site) on another
    // instrument. Records 1 and 2 both hold (specimen_id=S77, site=1), the two
    // components split across the event node (site) and a repeat instance (specimen).
    $xiDict = [
        'record_id'   => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'enroll'],
        'site'        => ['field_type' => 'text', 'form_name' => 'enroll', 'identifier' => '', 'field_annotation' => ''],
        'specimen_id' => ['field_type' => 'text', 'form_name' => 'lab', 'identifier' => '',
                          'field_annotation' => '@UVUNIQUE={"with":["site"]}'],
    ];
    $xiData = [
        '1' => [351 => ['record_id' => '1', 'site' => '1'],
                'repeat_instances' => [351 => ['lab' => [1 => ['specimen_id' => 'S77']]]]],
        '2' => [351 => ['record_id' => '2', 'site' => '1'],
                'repeat_instances' => [351 => ['lab' => [1 => ['specimen_id' => 'S77']]]]],
    ];
    // (a) post-save AUDIT of record 2 now detects it (leg B: findCollision compares
    //     merged contexts, not single raw rows). Missed before the fix.
    $m = newModule([], $xiDict, $xiData, 149);
    $m->redcap_save_record(149, '2', 'lab', 351, null, null, null, 1);
    $uniqAudit = array_values(array_filter(invalidLogs($m), function ($c) {
        return isset($c[1]['type']) && $c[1]['type'] === 'unique';
    }));
    check('H-03 audit: cross-instrument composite duplicate is detected (was missed)',
        count($uniqAudit) >= 1);
    // (b) the LIVE endpoint resolves the off-instrument "site" server-side (leg A):
    //     the client sends site="" because site is off the lab page, but the server
    //     reads the saved value and finds the collision. False "available" before.
    $m = newModule([], $xiDict, $xiData, 149);
    $r = $m->redcap_module_ajax('unique-check',
        ['field' => 'specimen_id', 'values' => ['specimen_id' => 'S77', 'site' => '']],
        149, '2', 'lab', 351, 1, null, null, null, '', '', 'staff1', null);
    check('H-03 endpoint: off-page composite value resolved server-side -> collision found',
        isset($r['used']) && $r['used'] === true);
    // control: a genuinely new specimen still reads free (no false positive)
    $r = $m->redcap_module_ajax('unique-check',
        ['field' => 'specimen_id', 'values' => ['specimen_id' => 'S99', 'site' => '']],
        149, '2', 'lab', 351, 1, null, null, null, '', '', 'staff1', null);
    check('H-03 endpoint: a new specimen still reads free', isset($r['used']) && $r['used'] === false);
    // (c) the SCAN detects the same duplicate — audit and scan now AGREE.
    $m = newModule([], $xiDict, $xiData, 149);
    $res = $m->scanProject(149);
    $uniqScan = array_values(array_filter($res['violations'], function ($v) { return $v['type'] === 'unique'; }));
    check('H-03 scan: same cross-instrument duplicate detected (audit == scan)', count($uniqScan) >= 2);

    // M-05: a config-broken rule enforces nothing; the scan must DISCLOSE it rather
    // than imply a clean project. Mixed set: one live @UVREQUIRED (scan runs fully)
    // and one config-error @UVALIDATE (unknown algorithm).
    $m5Dict = [
        'record_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'f'],
        'code' => ['field_type' => 'text', 'form_name' => 'f', 'identifier' => '',
                   'field_annotation' => '@UVALIDATE={"algorithm":"bogus"}'],
        'good' => ['field_type' => 'text', 'form_name' => 'f', 'identifier' => '',
                   'field_annotation' => '@UVREQUIRED'],
    ];
    $m = newModule([], $m5Dict, ['1' => [351 => ['record_id' => '1', 'code' => 'ABC', 'good' => '']]], 149);
    $res = $m->scanProject(149);
    check('M-05: a config-error rule is surfaced in the scan, not silently skipped',
        count(array_filter($res['unconfigurable'], function ($u) {
            return strpos($u['why'], 'configuration error') !== false;
        })) >= 1);
    check('M-05: the config-error rule produced no phantom violations',
        count(array_filter($res['violations'], function ($v) { return $v['field'] === 'code'; })) === 0);
    check('M-05: the live rule still ran (blank required detected)',
        count(array_filter($res['violations'], function ($v) { return $v['field'] === 'good' && $v['type'] === 'required'; })) === 1);

    // L-01: distinct composite tuples must not collide when a value contains the raw
    // separator byte. (X<US>Y, Z) and (X, Y<US>Z) are DISTINCT but joined with 0x1F
    // they share a key -> a false duplicate. json_encode keys keep them apart.
    $us = "\x1f";
    $l1Dict = [
        'record_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'f'],
        'a' => ['field_type' => 'text', 'form_name' => 'f', 'identifier' => '', 'field_annotation' => '@UVUNIQUE={"with":["b"]}'],
        'b' => ['field_type' => 'text', 'form_name' => 'f', 'identifier' => '', 'field_annotation' => ''],
    ];
    $m = newModule([], $l1Dict, [
        '1' => [351 => ['record_id' => '1', 'a' => 'X' . $us . 'Y', 'b' => 'Z']],
        '2' => [351 => ['record_id' => '2', 'a' => 'X', 'b' => 'Y' . $us . 'Z']],
    ], 149);
    $res = $m->scanProject(149);
    check('L-01: distinct composite tuples with a raw separator are not a false duplicate',
        count(array_filter($res['violations'], function ($v) { return $v['type'] === 'unique'; })) === 0);
    // sanity: a genuine composite duplicate is still detected
    $m = newModule([], $l1Dict, [
        '1' => [351 => ['record_id' => '1', 'a' => 'X', 'b' => 'Z']],
        '2' => [351 => ['record_id' => '2', 'a' => 'X', 'b' => 'Z']],
    ], 149);
    $res = $m->scanProject(149);
    check('L-01 sanity: a genuine composite duplicate is still detected',
        count(array_filter($res['violations'], function ($v) { return $v['type'] === 'unique'; })) >= 2);

    // F9: inject only rules whose fields are on the RENDERED instrument, so a
    // rule-heavy project does not stack one document.body MutationObserver per
    // project rule on every form (the 1.5.1 perf issue). Off-form rules stay
    // covered by the post-save audit and the Validation scan.
    $f9Dict = [
        'record_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'fa'],
        'a_code' => ['field_type' => 'text', 'form_name' => 'fa', 'identifier' => '', 'field_annotation' => '@UVALIDATE'],
        'b_code' => ['field_type' => 'text', 'form_name' => 'fb', 'identifier' => '', 'field_annotation' => '@UVALIDATE'],
    ];
    $renderForm = function ($m, $instrument) {
        ob_start();
        $m->redcap_data_entry_form_top(149, '1', $instrument, 351, null, 1);
        $html = ob_get_clean();
        $cfg = null;
        if (preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm)) {
            $cfg = json_decode($mm[1], true);
        }
        return $cfg;
    };
    $liveA = $liveFieldsOf($renderForm(newModule([], $f9Dict, [], 149), 'fa'));
    check('F9: rendering form fa injects its own rule', in_array('a_code', $liveA, true));
    check('F9: rendering form fa does NOT inject the other form\'s rule', !in_array('b_code', $liveA, true));
    $liveB = $liveFieldsOf($renderForm(newModule([], $f9Dict, [], 149), 'fb'));
    check('F9: rendering form fb injects only its own rule',
        in_array('b_code', $liveB, true) && !in_array('a_code', $liveB, true));
    // a rule that covers fields on BOTH forms is injected on each with only that
    // form's fields (so the browser binds only the on-page ones).
    $f9Dict2 = [
        'record_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'fa'],
        'x1' => ['field_type' => 'text', 'form_name' => 'fa', 'identifier' => '', 'field_annotation' => '@UVREQUIRED'],
        'x2' => ['field_type' => 'text', 'form_name' => 'fb', 'identifier' => '', 'field_annotation' => '@UVREQUIRED'],
    ];
    // x1 and x2 carry byte-identical tags -> one rule covering both fields; on form
    // fa only x1 must remain in that rule's field list.
    $cfgA2 = $renderForm(newModule([], $f9Dict2, [], 149), 'fa');
    $reqRuleFields = [];
    foreach (($cfgA2['rules'] ?? []) as $rr) {
        if (empty($rr['configError']) && ($rr['type'] ?? '') === 'required') $reqRuleFields = $rr['fields'] ?? [];
    }
    check('F9: a cross-form rule injects only the rendered form\'s field',
        in_array('x1', $reqRuleFields, true) && !in_array('x2', $reqRuleFields, true));

    // F4: the live endpoint narrows the collision query with a filterLogic so the
    // whole project is not exported on every no-auth call. The exact comparison and
    // the post-save audit stay authoritative, so correctness is unchanged.
    $f4Dict = [
        'record_id' => ['field_type' => 'text', 'field_annotation' => '', 'form_name' => 'if'],
        'pid_f' => ['field_type' => 'text', 'form_name' => 'if', 'identifier' => '', 'field_annotation' => '@UVUNIQUE'],
    ];
    $m = newModule([], $f4Dict, ['1' => [351 => ['record_id' => '1', 'pid_f' => 'UNIQ-1']]], 149);
    \REDCap::$lastGetDataParams = null;
    $r = $m->redcap_module_ajax('unique-check', ['field' => 'pid_f', 'values' => ['pid_f' => 'UNIQ-1']],
        149, '2', 'if', 351, 1, null, null, null, '', '', 'staff1', null);
    check('F4: live endpoint still finds the collision', isset($r['used']) && $r['used'] === true);
    check('F4: live endpoint narrows the query with a filterLogic on the candidate value',
        isset(\REDCap::$lastGetDataParams['filterLogic'])
        && strpos(\REDCap::$lastGetDataParams['filterLogic'], 'pid_f') !== false
        && strpos(\REDCap::$lastGetDataParams['filterLogic'], 'UNIQ-1') !== false);
    // a value that cannot be safely inlined (contains a quote) falls back to the full scan
    $m = newModule([], $f4Dict, ['1' => [351 => ['record_id' => '1', 'pid_f' => "A'B"]]], 149);
    \REDCap::$lastGetDataParams = null;
    $r = $m->redcap_module_ajax('unique-check', ['field' => 'pid_f', 'values' => ['pid_f' => "A'B"]],
        149, '2', 'if', 351, 1, null, null, null, '', '', 'staff1', null);
    check('F4: an unsafe value falls back to the full scan (no filterLogic)',
        !isset(\REDCap::$lastGetDataParams['filterLogic']));
    check('F4: the fallback full scan still finds the collision', isset($r['used']) && $r['used'] === true);
    // the post-save AUDIT never narrows — it keeps the authoritative full scan
    $m = newModule([], $f4Dict, [
        '1' => [351 => ['record_id' => '1', 'pid_f' => 'UNIQ-1']],
        '2' => [351 => ['record_id' => '2', 'pid_f' => 'UNIQ-1']],
    ], 149);
    \REDCap::$lastGetDataParams = null;
    $m->redcap_save_record(149, '2', 'if', 351, null, null, null, 1);
    check('F4: the post-save audit does NOT narrow (full-scan authoritative)',
        !isset(\REDCap::$lastGetDataParams['filterLogic']));

    // dialog channel refuses the same combination at save time
    $m = newModule([], $idDict, [], 149);
    $msg = $m->validateSettings(modeFlat([
        ['rule-type' => 'unique', 'fields' => ['nat_id'], 'unique-surveys' => '1', 'block-save' => 'off'],
    ]));
    check('save gate: survey opt-in on an Identifier rejected',
        is_string($msg) && strpos($msg, 'Identifier') !== false);
    $msg = $m->validateSettings(modeFlat([
        ['rule-type' => 'unique', 'fields' => ['token'], 'unique-surveys' => '1', 'block-save' => 'off'],
    ]));
    check('save gate: survey opt-in on a non-Identifier passes', $msg === null);
    $msg = $m->validateSettings(modeFlat([
        ['rule-type' => 'unique', 'fields' => ['nat_id'], 'block-save' => 'hard'],
    ]));
    check('save gate: Identifier unique WITHOUT surveys passes', $msg === null);

    // The identifier map must reach settingRowToRule from the CALLER's explicit
    // pid. Resolving it inside the method would fall back to getProjectId(),
    // which is null on import/API contexts (SEC-002) — the dictionary would come
    // back empty and the guard would silently pass. Model that context here:
    // getProjectId() returns null, so a guard that relied on it fails open.
    $m = newModule([], $idDict, $idData, null); // getProjectId() === null
    $m->subSettings = [[
        'rule-type' => 'unique', 'fields' => ['nat_id'], 'fields-csv' => '', 'when' => '',
        'assert' => '', 'message' => '', 'unique-with' => '', 'unique-scope' => '',
        'unique-surveys' => '1', 'algorithm' => 'iso7064_mod37_36', 'source' => '',
        'suggest-fix' => '', 'pattern' => '', 'strip' => '', 'keep-chars' => '',
        'id-lengths' => '', 'id-min-len' => '', 'id-max-len' => '',
        'expected-count' => '', 'block-save' => 'off',
    ]];
    ob_start();
    $m->redcap_data_entry_form_top(149, '2', 'if', 351, null, 1); // hook pid IS known
    $html = ob_get_clean();
    check('dialog rule: Identifier guard holds when getProjectId() is null (SEC-002)',
        strpos($html, 'cannot be enabled on a field REDCap marks as an Identifier') !== false);

    // ---- JSMO transport is found through __call() (live-found regression) -----
    // The framework proxies these methods via __call(); method_exists() is FALSE
    // for them, which silently skipped the whole JSMO block on a real REDCap and
    // left @UVUNIQUE inert (found on pid 149, v1.4.0). is_callable() honours
    // __call(), so the transport must be found in BOTH shapes.
    $jm = new \ProxyJsmoModule();
    $jm->subSettings = [];
    $jm->projectSettings = ['log-values' => ''];
    $jm->projectIdReturn = 149;
    \REDCap::$dictionary = $uqDict;
    \REDCap::$data = $uqData;
    \REDCap::$dataThrows = false;
    ob_start();
    $jm->redcap_data_entry_form_top(149, '3', 'uf', 351, null, 1);
    $html = ob_get_clean();
    $cfg = null;
    if (preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm)) {
        $cfg = json_decode($mm[1], true);
    }
    check('JSMO found via __call(): jsmoName reaches the client',
        $cfg && isset($cfg['jsmoName']) && $cfg['jsmoName'] === 'ExternalModules.PROXY.UniversalValidator');
    check('JSMO found via __call(): the bootstrap is emitted',
        strpos($html, '/* proxied jsmo */') !== false);
    check('JSMO found via __call(): both framework methods were actually called',
        in_array('initializeJavascriptModuleObject', $jm->jsmoCalls, true)
        && in_array('getJavascriptModuleObjectName', $jm->jsmoCalls, true));
    check('JSMO found via __call(): no "no transport" log written',
        count(logsOf($jm, 'uvalidate-no-unique-transport')) === 0);

    // No transport at all -> LOGGED, never silent; the page still renders and
    // the other modes keep working.
    $nj = new \NoJsmoModule();
    $nj->subSettings = [];
    $nj->projectSettings = ['log-values' => ''];
    $nj->projectIdReturn = 149;
    ob_start();
    $nj->redcap_data_entry_form_top(149, '3', 'uf', 351, null, 1);
    $html = ob_get_clean();
    $cfg = null;
    if (preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm)) {
        $cfg = json_decode($mm[1], true);
    }
    $ntLogs = logsOf($nj, 'uvalidate-no-unique-transport');
    check('no JSMO: the absence is logged, not swallowed', count($ntLogs) === 1);
    check('no JSMO: the log says why and what it means',
        $ntLogs && strpos($ntLogs[0][1]['why'], 'threw') !== false
        && strpos($ntLogs[0][1]['effect'], 'inert') !== false);
    check('no JSMO: jsmoName absent, so the client fails open', $cfg && !isset($cfg['jsmoName']));
    check('no JSMO: the rest of the config still ships', $cfg && !empty($cfg['rules']));

    echo sprintf("hook_php: %d checks, %d failure(s)\n", $n, $fail);
    exit($fail === 0 ? 0 : 1);
}
