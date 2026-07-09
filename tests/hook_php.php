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
 * Run:  php tests/hook_php.php
 */

namespace ExternalModules {
    // Minimal stand-in for the framework base class. Test scripts set the public
    // fixtures; the module calls these methods exactly as in production.
    class AbstractExternalModule {
        public $logCalls = [];
        public $subSettings = [];
        public $projectSettings = [];
        public $projectIdReturn = null;      // what getProjectId() returns (null models import/API)
        public function getSubSettings($key) { return $this->subSettings; }
        public function getProjectSetting($key) { return $this->projectSettings[$key] ?? null; }
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
        public static function getData($params) {
            if (self::$dataThrows) throw new \RuntimeException('simulated getData failure');
            return self::$data;
        }
        public static function getDataDictionary($pid, $format = 'array') {
            if (!$pid) throw new \RuntimeException('getDataDictionary requires a pid');
            return self::$dictionary;
        }
    }

    require_once __DIR__ . '/../UniversalValidator.php';

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
        'record_id'   => ['field_type' => 'text', 'field_annotation' => ''],
        'main_id_1'   => ['field_type' => 'text', 'field_annotation' => ''],
        'main_id_2'   => ['field_type' => 'text', 'field_annotation' => ''],
        'main_id_tag' => ['field_type' => 'text', 'field_annotation' => '@UVALIDATE'], // bare -> default check
    ];
    // Classic project getData shape: [record][event][field]
    $classicData = [2 => [351 => [
        'record_id'   => '2',
        'main_id_1'   => '1ABC-00002E', // invalid check (dialog rule)
        'main_id_2'   => '2XYZ-K93B7I', // valid
        'main_id_tag' => '8QRS-55556E', // invalid check (annotation rule)
    ]]];

    function newModule($subs, $dict, $data, $pidReturn, $throws = false) {
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->subSettings = $subs;
        $m->projectSettings = ['log-values' => ''];
        $m->projectIdReturn = $pidReturn;
        \REDCap::$dictionary = $dict;
        \REDCap::$data = $data;
        \REDCap::$dataThrows = $throws;
        return $m;
    }
    function invalidLogs($m) {
        return array_values(array_filter($m->logCalls, function ($c) { return $c[0] === 'invalid-id-saved'; }));
    }
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

    // ---- 2) F2 fix: import/API context (getProjectId()==null) still audits ----
    //         BOTH dialog and annotation rules, using the hook's project_id.
    $m = newModule($dialogRules, $dictionary, $classicData, null); // getProjectId() null
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    $fields = loggedFields($m);
    check('dialog rule audited when getProjectId() is null', in_array('main_id_1', $fields, true));
    check('annotation rule audited when getProjectId() is null (F2 fix)', in_array('main_id_tag', $fields, true));

    // ---- 3) F3 fix: a getData failure surfaces as a visible audit-error log ----
    $m = newModule($dialogRules, $dictionary, $classicData, 149, true); // getData throws
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    $errs = array_values(array_filter($m->logCalls, function ($c) { return $c[0] === 'uvalidate-audit-error'; }));
    check('getData failure is logged, not swallowed (F3 fix)', count($errs) === 1);

    // ---- 4) reason + privacy: hashed mode stores a value hash, not the raw value ----
    $m = newModule($dialogRules, $dictionary, $classicData, 149);
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    $one = invalidLogs($m)[0][1];
    check('reason recorded', in_array($one['reason'], ['check-character', 'format'], true));
    check('raw value NOT stored by default', !isset($one['value']));
    check('value hash stored by default', isset($one['value_sha256']));

    // ---- 5) no rules -> no logs (and no crash) ----
    $m = newModule([], [], [2 => [351 => ['main_id_1' => '1ABC-00002E']]], 149);
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    check('no rules -> no audit logs', count($m->logCalls) === 0);

    // ---- 6) value present but valid on every rule -> no logs ----
    $goodData = [2 => [351 => ['main_id_1' => '1ABC-00001E', 'main_id_2' => '2XYZ-K93B7I', 'main_id_tag' => '8QRS-55555E']]];
    $m = newModule($dialogRules, $dictionary, $goodData, 149);
    $m->redcap_save_record(149, '2', 'id_validation_test', 351, null, null, null, 1);
    check('all-valid save -> no invalid-id logs', count(invalidLogs($m)) === 0);

    echo sprintf("hook_php: %d checks, %d failure(s)\n", $n, $fail);
    exit($fail === 0 ? 0 : 1);
}
