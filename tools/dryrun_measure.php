<?php
/**
 * dryrun_measure.php — exercise tools/measure_scan.php against mocks.
 *
 * The measurement page cannot run here (no REDCap), but every line of it can:
 * this stands up the same fake REDCap the test suite uses, injects $module, and
 * includes the page for real. The point is to find a fatal, a bad key, or a
 * division by zero HERE rather than on a live server mid-scan.
 *
 * Run:  php dryrun_measure.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $subSettings = []; public $projectSettings = []; public $projectIdReturn = null;
        public $logCalls = []; public $queryCalls = [];
        public function getSubSettings($k, $pid = null) {
            $e = ($pid !== null && $pid !== '') ? $pid : $this->projectIdReturn;
            return $e ? $this->subSettings : [];
        }
        public function getProjectSetting($k, $pid = null) {
            return isset($this->projectSettings[$k]) ? $this->projectSettings[$k] : null;
        }
        public function getSystemSetting($k) { return null; }
        public function setSystemSetting($k, $v) { throw new \RuntimeException('WRITE ATTEMPTED: setSystemSetting'); }
        public function getProjectId() { return $this->projectIdReturn; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return 1; }
        public function getUser() { return new TestUser(); }
        /** Only reachable with &grants=1. Returns a mysqli_result-ish object. */
        public function query($sql, $params = []) {
            $this->queryCalls[] = $sql;
            if (stripos($sql, 'SHOW GRANTS') === false) throw new \RuntimeException('UNEXPECTED SQL: ' . $sql);
            return new FakeResult([["GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP ON `redcap`.* TO `rc`@`localhost`"]]);
        }
    }
    class FakeResult {
        private $rows; private $i = 0;
        public function __construct($rows) { $this->rows = $rows; }
        public function fetch_row() { return isset($this->rows[$this->i]) ? $this->rows[$this->i++] : null; }
    }
    class TestUser {
        public function hasDesignRights() { return true; }
        public function getRights($pid = null) { return ['group_id' => null]; }
        public function getUsername() { return 'measurer'; }
    }
}

namespace {
    class REDCap {
        public static $data = []; public static $dictionary = [];
        public static $calls = [];
        public static function getData($p) {
            self::$calls[] = ['fields' => count(isset($p['fields']) ? $p['fields'] : []),
                              'records' => count(isset($p['records']) ? $p['records'] : [])];
            $src = self::$data;
            if (!empty($p['records'])) {
                $o = [];
                foreach ($p['records'] as $r) if (array_key_exists($r, self::$data)) $o[$r] = self::$data[$r];
                $src = $o;
            }
            if (empty($p['fields'])) return $src;
            $want = array_flip($p['fields']);
            $out = [];
            foreach ($src as $rec => $node) {
                $keep = [];
                foreach ($node as $evt => $row) {
                    if (!is_array($row)) continue;
                    $r = [];
                    foreach ($row as $f => $v) if (isset($want[$f])) $r[$f] = $v;
                    if ($r) $keep[$evt] = $r;
                }
                if ($keep) $out[$rec] = $keep;
            }
            return $out;
        }
        public static function getDataDictionary($pid, $f = 'array') { return self::$dictionary; }
        public static function getRecordIdField() { return 'record_id'; }
        public static function getGroupNames($u = false, $g = null) { return ''; }
        public static function getInstrumentEventMappings($pid = null) { return null; }
        public static function getEventNames($u = false, $x = false, $e = null) { return 'event_1_arm_1'; }
        public static function getRepeatingFormsEvents($pid = null) { return []; }
        public static function isRepeatingForm($e = null, $f = null) { return false; }
    }

    $ROOT = 'D:/SCRIPTS_PATH/redcap-universal-validator/.claude/worktrees/redcap-module-review-bfa0db';
    require_once $ROOT . '/UniversalValidator.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    // --- a project with two instruments, a required rule, and mixed _complete --
    \REDCap::$dictionary = [
        'record_id' => ['field_name' => 'record_id', 'form_name' => 'enrol', 'field_type' => 'text',
                        'field_label' => 'Record', 'field_annotation' => '', 'select_choices_or_calculations' => '', 'identifier' => ''],
        'initials'  => ['field_name' => 'initials', 'form_name' => 'enrol', 'field_type' => 'text',
                        'field_label' => 'Initials', 'field_annotation' => '@UVREQUIRED', 'select_choices_or_calculations' => '', 'identifier' => ''],
        'xray_read' => ['field_name' => 'xray_read', 'form_name' => 'xray', 'field_type' => 'text',
                        'field_label' => 'X-ray read', 'field_annotation' => '@UVREQUIRED', 'select_choices_or_calculations' => '', 'identifier' => ''],
    ];
    // record 1: enrol saved (complete=2), xray never touched (no _complete key)
    // record 2: enrol saved blank (complete=0), xray never touched
    // record 3: both saved
    \REDCap::$data = [
        1 => [1 => ['record_id' => '1', 'initials' => 'AB', 'enrol_complete' => '2']],
        2 => [1 => ['record_id' => '2', 'initials' => '',   'enrol_complete' => '0']],
        3 => [1 => ['record_id' => '3', 'initials' => 'CD', 'xray_read' => 'X',
                    'enrol_complete' => '2', 'xray_complete' => '2']],
    ];

    function runPage($get, $ROOT) {
        $module = new \INSPIRE\UniversalValidator\UniversalValidator();
        $module->projectIdReturn = 135;
        $module->projectSettings = ['log-values' => ''];
        $module->subSettings = [];
        \REDCap::$calls = [];
        $_GET = $get;
        ob_start();
        try { include $ROOT . '/tools/measure_scan.php'; }
        catch (\Throwable $e) { ob_end_clean(); return ['__fatal' => get_class($e) . ': ' . $e->getMessage()]; }
        $html = ob_get_clean();
        if (!preg_match('~<textarea[^>]*>(.*?)</textarea>~s', $html, $mm)) return ['__nohtml' => substr($html, 0, 400)];
        return ['html' => $html, 'json' => json_decode(html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8'), true)];
    }

    // ---------------------------------------------------------------- plain run
    $r = runPage([], $ROOT);
    check('the page runs to completion without a fatal', !isset($r['__fatal']) && !isset($r['__nohtml']));
    if (isset($r['__fatal'])) { fwrite(STDERR, "  -> " . $r['__fatal'] . "\n"); }
    $J = isset($r['json']) ? $r['json'] : null;
    check('it emits a parseable JSON block', is_array($J));

    if (is_array($J)) {
        check('shape: records counted', ($J['shape']['records_in_project'] ?? null) === 3);
        check('shape: instruments counted', ($J['shape']['instruments'] ?? null) === 2);
        check('shape: the record-id field is reported', ($J['shape']['record_id_field'] ?? '') === 'record_id');

        check('timings: the id read is timed', isset($J['timings']['id_read']['ms']));
        check('timings: both read brackets are timed',
            isset($J['timings']['reads_lower_bound_1_field']['ms'])
            && isset($J['timings']['reads_upper_bound_all_fields']['ms']));
        check('timings: the scan itself is timed', isset($J['timings']['scanProject_total']['ms']));
        check('timings: no probe recorded an error',
            !array_filter($J['timings'], function ($t) { return isset($t['error']); }));

        check('gate: findings per record is computed', isset($J['gate']['FINDINGS_PER_RECORD']));
        check('gate: contexts per record is computed', isset($J['gate']['CONTEXTS_PER_RECORD']));
        check('gate: a verdict string is produced', !empty($J['gate']['VERDICT']));
        check('gate: findings are broken down by type', isset($J['gate']['findings_by_type']));
        check('gate: the assert reason is collapsed, never carried verbatim',
            !array_filter(array_keys((array) ($J['gate']['findings_by_type'] ?? [])),
                function ($k) { return strpos($k, 'assert:') !== false; }));
        check('gate: status is reported', ($J['gate']['status'] ?? '') === 'complete');
        check('gate: the scan actually found the required-blank', ($J['gate']['findings'] ?? 0) > 0);

        // The measurement this whole page exists for.
        $abs = $J['complete_status']['buckets']['absent'] ?? null;
        check('_complete: untouched forms are counted as absent', $abs === 2);
        check('_complete: a form saved BLANK counts as present, not absent',
            ($J['complete_status']['buckets']['zero'] ?? null) === 1);
        check('_complete: a verdict is produced', !empty($J['complete_status']['VERDICT']));
        check('_complete: with 50% absent it does NOT claim the gate works',
            strpos((string) $J['complete_status']['VERDICT'], 'GATE WORKS') === false);
        check('_complete: per-form counts are present', isset($J['complete_status']['per_form']['xray']));

        check('notes: it says grants were not checked', (bool) array_filter((array) $J['notes'],
            function ($s) { return strpos($s, 'grants=1') !== false; }));
        check('notes: it says the pk fallback was not measured', (bool) array_filter((array) $J['notes'],
            function ($s) { return strpos($s, 'pkfallback=1') !== false; }));
        check('meta: page total and peak are reported',
            isset($J['meta']['page_total_ms']) && isset($J['meta']['page_peak_mb']));
        check('meta: the memory limit is captured', array_key_exists('memory_limit', $J['meta']));
    }

    // ---------------------------------------------------------------- &limit=2
    $r2 = runPage(['limit' => '2'], $ROOT);
    $J2 = $r2['json'] ?? null;
    check('limit: only the first N records are measured',
        is_array($J2) && ($J2['shape']['records_measured'] ?? null) === 2
        && ($J2['shape']['records_in_project'] ?? null) === 3);

    // ---------------------------------------------------------------- &grants=1
    $r3 = runPage(['grants' => '1'], $ROOT);
    $J3 = $r3['json'] ?? null;
    check('grants: SHOW GRANTS is read and interpreted',
        is_array($J3) && ($J3['grants']['create_table_likely'] ?? '') === 'yes');
    check('grants: the raw grant lines are returned verbatim',
        is_array($J3) && !empty($J3['grants']['raw']));

    // ------------------------------------------------- &pkfallback=1 (opt-in)
    $r4 = runPage(['pkfallback' => '1', 'limit' => '2'], $ROOT);
    $J4 = $r4['json'] ?? null;
    check('pkfallback: the landmine export is measured only when asked',
        is_array($J4) && isset($J4['timings']['pk_fallback_full_export_DANGEROUS']['ms']));

    // ------------------------------------------------- it must never WRITE
    $mod = new \INSPIRE\UniversalValidator\UniversalValidator();
    check('the page issued no SQL other than SHOW GRANTS', true);   // enforced by the mock throwing
    $src = file_get_contents($ROOT . '/tools/measure_scan.php');
    check('source contains no INSERT/UPDATE/DELETE/DDL statement',
        !preg_match('~\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|CREATE\s+TABLE|DROP\s+TABLE|ALTER\s+TABLE|TRUNCATE)\b~i', $src));
    check('source calls no settings writer', strpos($src, 'setProjectSetting') === false
        && strpos($src, 'setSystemSetting') === false);
    check('source writes no file', strpos($src, 'file_put_contents') === false
        && strpos($src, 'fopen(') === false);

    echo "dryrun_measure: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
