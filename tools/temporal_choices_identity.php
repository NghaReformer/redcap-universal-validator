<?php
/**
 * temporal_choices_identity.php — drive the REAL evaluation path
 * (durableScanContext -> durableEvaluateRecord) for a checkbox @UVCHOICES rule
 * and show what identities the findings carry.
 *
 * Run:  php tools/temporal_choices_identity.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $subSettings = []; public $projectSettings = []; public $systemSettings = [];
        public $projectIdReturn = 700; public $logCalls = [];
        public function getSubSettings($k, $pid = null) { return $this->subSettings; }
        public function getProjectSetting($k, $pid = null) {
            return isset($this->projectSettings[$k]) ? $this->projectSettings[$k] : null;
        }
        public function getSystemSetting($k) {
            return isset($this->systemSettings[$k]) ? $this->systemSettings[$k] : null;
        }
        public function setSystemSetting($k, $v) { $this->systemSettings[$k] = $v; }
        public function query($sql, $params = []) { return []; }
        public function getProjectId() { return $this->projectIdReturn; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return 1; }
        public function getUser() { return new TestUser('nurse'); }
    }
    class TestUser {
        private $n; public function __construct($n) { $this->n = $n; }
        public function getUsername() { return $this->n; }
        public function hasDesignRights() { return true; }
        public function getRights($pid = null) { return ['forms' => ['fa' => '1'], 'design' => 1,
                                                        'data_export_tool' => '1']; }
    }
}

namespace {
    class REDCap {
        public static $dictionary = []; public static $data = [];
        public static function getData($p) { return self::$data; }
        public static function getRecordIdField() { return 'record_id'; }
        public static function getDataDictionary($pid = null, $fmt = 'array') { return self::$dictionary; }
        public static function getEventNames($unique = false) { return [1 => 'event_1_arm_1']; }
        public static function getInstrumentNames() { return ['fa' => 'Form A']; }
        public static function getGroupNames($unique = false, $gid = null) { return []; }
    }
    require_once __DIR__ . '/../UniversalValidator.php';

    // One checkbox field, three options, a @UVCHOICES rule hiding TWO of them.
    REDCap::$dictionary = [
        'record_id' => ['field_type' => 'text', 'form_name' => 'fa', 'field_annotation' => '',
                        'field_label' => 'Record', 'select_choices_or_calculations' => ''],
        'symptoms'  => ['field_type' => 'checkbox', 'form_name' => 'fa',
                        'field_label' => 'Symptoms',
                        'select_choices_or_calculations' => '1, Cough | 2, Fever | 3, Rash',
                        'field_annotation' => '@UVCHOICES={"hide":["2","3"]}'],
    ];
    // Record 1 has BOTH hidden options ticked.
    REDCap::$data = ['1' => [1 => ['record_id' => '1',
                                   'symptoms' => ['1' => '0', '2' => '1', '3' => '1']]]];

    $m = new \INSPIRE\UniversalValidator\UniversalValidator();
    $m->projectIdReturn = 700;

    $ctx = $m->durableScanContext(700, ['valueCeiling' => 'raw']);
    if (empty($ctx['ok'])) { echo "context refused: " . $ctx['why'] . "\n"; exit(1); }

    $ev = call_user_func($ctx['evaluate'], '1', REDCap::$data['1']);
    echo "findings produced for ONE record: " . count($ev['findings']) . "\n";
    $ids = [];
    foreach ($ev['findings'] as $i => $f) {
        $hex = bin2hex($f['identity']);
        $ids[$hex] = isset($ids[$hex]) ? $ids[$hex] + 1 : 1;
        printf("  #%d field=%s reason=%s value=%s identity=%s...\n",
            $i + 1, $f['field'], $f['reason_code'],
            var_export($f['value_bin'], true), substr($hex, 0, 24));
    }
    $dupes = 0;
    foreach ($ids as $hex => $n) if ($n > 1) $dupes++;
    echo $dupes > 0
        ? "\nCOLLISION: $dupes identity value(s) produced more than once in ONE record.\n"
          . "uv_finding.uq_active_identity (generation_id, finding_identity, active_slot) rejects\n"
          . "the second insert, and SqlScanStore::commitBatch rolls the WHOLE batch back.\n"
        : "\nno identity collision\n";
    echo "rule problems returned by the evaluator: " . count($ev['problems'])
       . " (ScanWorker never reads this key)\n";
}
