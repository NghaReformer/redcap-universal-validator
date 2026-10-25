<?php
/**
 * exists_table_php.php — @UVEXISTS read from the data table, rights on the
 * fields a rule fills, and the record being edited (EXS-1 to EXS-6, PRF-5).
 *
 * EXS-4: a lookup reads the records the data table shows holding the value,
 * then getData of those records only. A rule with "return" no longer exports
 * every record on every lookup and save, and a plain "not found" reads no
 * record at all.
 *
 * EXS-1: the data table is not confined to the caller's Data Access Group.
 * A value saved nowhere is "not found" for a user in a group, whether every
 * record is in their group or REDCap confines their reads. A value saved only
 * in another group is found there (the record is not named); a rule that
 * tests more than the value cannot be judged there and answers unknown.
 *
 * EXS-3: the save writes a returned value only into a form the person who
 * saved may change; otherwise the field keeps its value and one rule problem
 * is logged, only where the value would have changed.
 *
 * EXS-2: a page of a form the person may only read, or a locked one, carries
 * formReadOnly, and the browser then fills nothing (tests/exists_return_dom_js.cjs).
 *
 * EXS-6: "in" on the tagged field's own form is sent by the page; the record
 * being edited is judged by what the page holds there.
 *
 * EXS-5 / PRF-5: the scan index of a rule that tests only the value is built
 * from the data table, with no export of every record.
 *
 * Every lookup is asked again with the data table unreadable, and must give
 * the answer it gave before this change (the getData path stays the fallback).
 *
 * Run:  php tests/exists_table_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $PREFIX = 'universal_validator';
        public $logCalls = [];
        public $settingsBy = [];
        public $subSettingsBy = [];
        public $systemSettings = [];
        public $projectIdReturn = null;
        public function getSubSettings($k, $pid = null) {
            $p = ($pid !== null && $pid !== '') ? (int) $pid : (int) $this->projectIdReturn;
            return isset($this->subSettingsBy[$p][$k]) ? $this->subSettingsBy[$p][$k] : [];
        }
        public function getProjectSetting($k, $pid = null) {
            $p = ($pid !== null && $pid !== '') ? (int) $pid : (int) $this->projectIdReturn;
            return isset($this->settingsBy[$p][$k]) ? $this->settingsBy[$p][$k] : null;
        }
        public function getSystemSetting($k) { return isset($this->systemSettings[$k]) ? $this->systemSettings[$k] : null; }
        public function setSystemSetting($k, $v) { $this->systemSettings[$k] = $v; }
        public function getProjectId() { return $this->projectIdReturn; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return count($this->logCalls); }
        public function initializeJavascriptModuleObject() { return '<script></script>'; }
        public function getJavascriptModuleObjectName() { return 'ExternalModules.TEST.UniversalValidator'; }
        public $rateBuckets = [];
        public $lastInsertId = 0;
        public function query($sql, $params = []) {
            if (strpos($sql, 'SELECT data_table FROM redcap_projects') !== false) {
                return empty($GLOBALS['__NO_TABLE']) ? [['redcap_data']] : [];
            }
            // The named records' rows (FieldValues::recordsAmong, after the
            // lookup index named them in the --index run).
            if (strpos($sql, 'FROM redcap_data WHERE project_id = ? AND record IN (') !== false) {
                if (!empty($GLOBALS['__NO_TABLE'])) return false;
                $GLOBALS['__AMONG_READS'][] = [$sql, $params];
                return \uv_among_rows($sql, $params);
            }
            if (strpos($sql, 'FROM redcap_data WHERE project_id = ? AND field_name = ?') !== false) {
                $GLOBALS['__TABLE_READS'][] = [$sql, $params];
                return \REDCap::tableRows($sql, $params);
            }
            if (strpos($sql, 'FROM redcap_locking_data') !== false) {
                return in_array(implode('|', $params), \REDCap::$locked, true) ? [[1]] : [];
            }
            if (strpos($sql, 'FROM redcap_locking_records') !== false) {
                return in_array((string) $params[1], \REDCap::$lockedRecords, true) ? [[1]] : [];
            }
            if (strpos($sql, 'SELECT LAST_INSERT_ID()') !== false) return [[$this->lastInsertId]];
            if (strpos($sql, 'SELECT ROW_COUNT()') !== false) return [[1]];
            if (strpos($sql, 'uv_rate_bucket') !== false && strpos(ltrim($sql), 'INSERT') === 0) {
                $k = (int) $params[0] . '|' . (int) $params[1];
                $this->rateBuckets[$k] = isset($this->rateBuckets[$k]) ? $this->rateBuckets[$k] + 1 : 1;
                $this->lastInsertId = $this->rateBuckets[$k];
            }
            return [];
        }
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
        public function isSuperUser() { return false; }
        public function getRights($pid = null) {
            return isset(\REDCap::$rightsBy[(int) $pid][$this->n]) ? \REDCap::$rightsBy[(int) $pid][$this->n] : null;
        }
    }
}

namespace {
    ini_set('session.use_cookies', '0');
    ini_set('session.use_only_cookies', '0');
    ini_set('session.cache_limiter', '');
    ini_set('session.save_path', sys_get_temp_dir());

    class REDCap {
        public static $data = [];
        public static $dictionaries = [];
        public static $rightsBy = [];
        public static $calls = [];
        public static $saves = [];
        public static $rowsOut = 0;          // records handed back over all reads
        public static $confineTo = null;     // reads confined to one group, as REDCap does for a user in it
        public static $groupIds = ['north' => 7, 'south' => 8];
        public static $locked = [];          // "pid|record|event|form|instance" of locked forms
        public static $lockedRecords = [];
        public static function reset() { self::$calls = []; self::$saves = []; self::$rowsOut = 0; $GLOBALS['__TABLE_READS'] = []; $GLOBALS['__AMONG_READS'] = []; }
        private static function dagOf(array $node) {
            foreach ($node as $k => $v) {
                if ($k !== 'repeat_instances' && is_array($v) && isset($v['redcap_data_access_group'])) return $v['redcap_data_access_group'];
            }
            return '';
        }
        /** The data table's rows: (record, field, value), or (record, event, value), non-blank values only. */
        public static function tableRows($sql, array $params) {
            $pid = (int) $params[0];
            $field = (string) $params[1];
            $event = strpos($sql, 'AND event_id = ?') !== false ? (int) $params[2] : null;
            $byEvent = strpos($sql, 'SELECT record, event_id') !== false;
            $out = [];
            foreach (isset(self::$data[$pid]) ? self::$data[$pid] : [] as $rec => $node) {
                foreach ($node as $ek => $row) {
                    $rows = [];
                    if ($ek === 'repeat_instances') {
                        foreach ((array) $row as $evt => $byInstr) foreach ((array) $byInstr as $byInst) foreach ((array) $byInst as $irow) $rows[] = [$evt, $irow];
                    } else {
                        $rows[] = [$ek, $row];
                    }
                    foreach ($rows as list($evt, $r)) {
                        if ($event !== null && (int) $evt !== $event) continue;
                        $v = null;
                        if ($field === '__GROUPID__') {
                            $g = isset($r['redcap_data_access_group']) ? $r['redcap_data_access_group'] : '';
                            if ($g !== '') $v = (string) self::$groupIds[$g];
                        } elseif (isset($r[$field]) && !is_array($r[$field]) && (string) $r[$field] !== '') {
                            $v = (string) $r[$field];
                        }
                        if ($v !== null) $out[] = $byEvent ? [(string) $rec, (string) $evt, $v] : [(string) $rec, $field, $v];
                    }
                }
            }
            return $out;
        }
        public static function getData($p) {
            $pid = (int) ($p['project_id'] ?? 0);
            self::$calls[] = $p + ['_pid' => $pid];
            if (!isset(self::$data[$pid])) throw new \RuntimeException('no such project');
            $out = [];
            $want = !empty($p['records']) ? array_flip(array_map('strval', $p['records'])) : null;
            foreach (self::$data[$pid] as $rec => $node) {
                if ($want !== null && !isset($want[(string) $rec])) continue;
                if (self::$confineTo !== null && self::dagOf($node) !== self::$confineTo) continue;
                if (!empty($p['events'])) {
                    $keep = array_map('strval', $p['events']);
                    $n2 = [];
                    foreach ($node as $k => $v) {
                        if ($k === 'repeat_instances') {
                            foreach ($v as $ev => $byForm) if (in_array((string) $ev, $keep, true)) $n2['repeat_instances'][$ev] = $byForm;
                        } elseif (in_array((string) $k, $keep, true)) {
                            $n2[$k] = $v;
                        }
                    }
                    if (!$n2) continue;
                    $node = $n2;
                }
                if (!empty($p['filterLogic'])) {
                    preg_match_all("/(lower\\()?\\[([a-z0-9_]+)\\]\\)? = (?:'([^']*)'|(-?[0-9.]+))/", $p['filterLogic'], $mm, PREG_SET_ORDER);
                    $hit = false;
                    foreach ($node as $k => $row) {
                        if ($k === 'repeat_instances' || !is_array($row)) continue;
                        $ok = true;
                        foreach ($mm as $c) {
                            $s = isset($row[$c[2]]) ? (string) $row[$c[2]] : '';
                            $want2 = $c[3] !== '' ? $c[3] : $c[4];
                            $ok = $ok && ($c[1] !== '' ? strtolower($s) === $want2 : $s === $want2);
                        }
                        if ($ok) { $hit = true; break; }
                    }
                    if (!$hit) continue;
                }
                $out[$rec] = $node;
            }
            self::$rowsOut += count($out);
            return $out;
        }
        public static function saveData($pid, $format = 'array', $data = [], $overwrite = 'normal') {
            self::$saves[] = ['pid' => (int) $pid, 'data' => $data, 'overwrite' => $overwrite];
            foreach ($data as $rec => $nodes) {
                foreach ($nodes as $evt => $row) {
                    if ($evt === 'repeat_instances') continue;
                    foreach ($row as $f => $v) self::$data[(int) $pid][$rec][$evt][$f] = $v;
                }
            }
            return ['errors' => [], 'warnings' => [], 'ids' => array_keys($data), 'item_count' => 1];
        }
        public static function getDataDictionary($pid, $f = 'array') {
            if (!isset(self::$dictionaries[(int) $pid])) throw new \RuntimeException('no such project');
            return self::$dictionaries[(int) $pid];
        }
        public static function getRepeatingFormsEvents($pid = null) { return []; }
        public static function getEventNames($unique = false, $assoc = false, $id = null) { return [351 => 'enrolment_arm_1']; }
        public static function getGroupNames($unique = false, $g = null) { return [7 => 'north', 8 => 'south'][$g] ?? ''; }
        public static function getRecordIdField() { return 'record_id'; }
        public static function getUserRights($u = null) { return []; }
    }
    class Project {
        public $longitudinal = false;
        public $project;
        public $eventsForms = null;
        public function __construct($pid) { $this->project = ['missing_data_codes' => '']; }
        public function getUniqueEventNames() { return []; }
        public function getUniqueGroupNames() { return []; }
        public function getRepeatingFormsEvents() { return []; }
    }

    require_once __DIR__ . '/../UniversalValidator.php';
    require_once __DIR__ . '/support/return_targets.php';
    require_once __DIR__ . '/support/fake_lookup_index.php';

    // Run again, in a second process, with a lookup index that names the
    // records holding the value plus one that does not: every answer must
    // stay the same (tests/support/fake_lookup_index.php).
    $INDEX = in_array('--index', isset($argv) ? $argv : [], true);
    $fail = 0;
    $n = 0;
    function check($label, $cond) {
        global $fail, $n, $INDEX;
        $n++;
        if (!$cond) { $fail++; fwrite(STDERR, 'FAIL' . ($INDEX ? ' (lookup index)' : '') . ": $label\n"); }
    }
    function f($form, $ann = '', $validation = '', $type = 'text') {
        return ['field_name' => '', 'field_type' => $type, 'form_name' => $form, 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => $validation, 'identifier' => '',
                'select_choices_or_calculations' => ''];
    }
    function named(array $dd) { foreach ($dd as $k => $_) $dd[$k]['field_name'] = $k; return $dd; }
    function mod($user, array $dict, array $data, array $rights) {
        $GLOBALS['__TEST_USER'] = $user;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectIdReturn = 149;
        $m->settingsBy = [149 => ['log-values' => 'raw']];
        \REDCap::$dictionaries = [149 => \lock_return_targets(named($dict))];
        \REDCap::$data = [149 => $data];
        \REDCap::$rightsBy = [149 => $rights];
        \REDCap::$confineTo = null;
        \REDCap::$locked = [];
        \REDCap::$lockedRecords = [];
        \REDCap::reset();
        if ($GLOBALS['INDEX']) uv_use_fake_index($m);
        return $m;
    }
    function ask($m, $field, array $values, $record, $form, $group = null) {
        return $m->redcap_module_ajax('exists-check', ['field' => $field, 'values' => $values],
            149, $record, $form, 351, 1, null, null, null, '', '', $GLOBALS['__TEST_USER'], $group);
    }
    function askSurvey($m, $field, array $values, $record, $form) {
        return $m->redcap_module_ajax('exists-check', ['field' => $field, 'values' => $values],
            149, $record, $form, 351, 1, 'hash', null, null, '', '', null, null);
    }
    function logsOf($m, $type) {
        return array_values(array_map(function ($c) { return $c[1]; },
            array_filter($m->logCalls, function ($c) use ($type) { return $c[0] === $type; })));
    }
    function pageConfig($m, $record, $form) {
        ob_start();
        $m->redcap_data_entry_form_top(149, $record, $form, 351, null, 1);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return json_decode($mm[1] ?? 'null', true);
    }
    function ruleOf($cfg, $field) {
        foreach (($cfg['rules'] ?? []) as $r) if (in_array($field, $r['fields'] ?? [], true)) return $r;
        return null;
    }
    /** An answer without the fields a test does not compare. */
    function core(array $r) { return array_intersect_key($r, array_flip(['state', 'record', 'filtered', 'values', 'fill'])); }
    /** $fn's answer with the data table readable and unreadable. */
    function both(callable $fn) {
        $GLOBALS['__NO_TABLE'] = false;
        $a = $fn();
        $GLOBALS['__NO_TABLE'] = true;
        $b = $fn();
        $GLOBALS['__NO_TABLE'] = false;
        return [$a, $b];
    }

    $FULL = ['nurse' => ['forms' => ['enrol' => '1', 'lab_reg' => '1', 'result' => '1', 'hh' => '1'], 'group_id' => null, 'expiration' => '']];

    // ---- EXS-4: the records that hold the value, not the whole project -----------------
    $DB = [
        'record_id'   => f('enrol'),
        'specimen_id' => f('lab_reg'),
        'lab_site'    => f('lab_reg'),
        'r_plain'     => f('result', '@UVEXISTS=[specimen_id]'),
        'r_ret'       => f('result', '@UVEXISTS={"in":"[specimen_id]","return":{"lab_site":"[r_site]"}}'),
        'r_site'      => f('result'),
        'r_rec'       => f('result', '@UVEXISTS=record'),
    ];
    $N = 400;
    $DATA_B = [];
    for ($i = 1; $i <= $N; $i++) {
        $DATA_B[(string) $i] = [351 => ['record_id' => (string) $i, 'specimen_id' => 'SP-' . $i, 'lab_site' => 'S' . ($i % 7)]];
    }
    $DATA_B['40'][351]['specimen_id'] = 'sp-77 ';   // the same value in another spelling, in another record
    $m = mod('nurse', $DB, $DATA_B, $FULL);
    $r = ask($m, 'r_plain', ['r_plain' => 'SP-77'], '1', 'result');
    check('EXS-4 plain rule found: one read of the records that hold it (got ' . \REDCap::$rowsOut . ')',
        $r['state'] === 'found' && \REDCap::$rowsOut === 2 && count(\REDCap::$calls) === 1);
    check('...and one statement on the data table', $INDEX
        ? $GLOBALS['__TABLE_READS'] === [] && count($GLOBALS['__AMONG_READS']) === 1
        : count($GLOBALS['__TABLE_READS']) === 1);
    $m = mod('nurse', $DB, $DATA_B, $FULL);
    $r = ask($m, 'r_plain', ['r_plain' => 'SP-NONE'], '1', 'result');
    check('EXS-4 plain rule not found: no record is read (got ' . count(\REDCap::$calls) . ' reads)',
        $r['state'] === 'not-found' && count(\REDCap::$calls) === 0);
    $m = mod('nurse', $DB, $DATA_B, $FULL);
    $r = ask($m, 'r_ret', ['r_ret' => 'SP-5'], '1', 'result');
    check('EXS-4 return rule: only the record that holds the value is read (got ' . \REDCap::$rowsOut . ' records)',
        $r['state'] === 'found' && \REDCap::$rowsOut === 1 && ($r['values']['r_site'] ?? null) === 'S5');
    $m = mod('nurse', $DB, $DATA_B, $FULL);
    $r = ask($m, 'r_ret', ['r_ret' => 'SP-77'], '1', 'result');
    check('EXS-4 return rule: two entries with different values to copy still conflict', ($r['fill'] ?? null) === 'conflict');
    $m = mod('nurse', $DB, $DATA_B, $FULL);
    \REDCap::$data[149]['1'][351]['r_ret'] = 'SP-9';
    \REDCap::reset();
    $m->redcap_save_record(149, '1', 'result', 351, null, null, null, 1);
    check('EXS-4 save of one record with a return rule reads a handful of records, not the project (got ' . \REDCap::$rowsOut . ')',
        \REDCap::$rowsOut < 10 && (\REDCap::$data[149]['1'][351]['r_site'] ?? null) === 'S2');
    // Every answer is the one the getData path gives.
    foreach ([['r_plain', 'SP-77'], ['r_plain', 'sp-77'], ['r_plain', ' SP-40 '], ['r_plain', 'SP-NONE'], ['r_ret', 'SP-5'],
              ['r_ret', 'SP-77'], ['r_ret', 'SP-NONE'], ['r_rec', '17'], ['r_rec', '017'], ['r_rec', '999']] as list($fld, $val)) {
        list($a, $b) = both(function () use ($DB, $DATA_B, $FULL, $fld, $val) {
            return core(ask(mod('nurse', $DB, $DATA_B, $FULL), $fld, [$fld => $val], '1', 'result'));
        });
        // Of two records that hold the value, the export's narrowed read
        // returns the first its filter matched; the table path the one spelled
        // exactly as looked up, as findExisting promises. The verdict is the same.
        if ($fld === 'r_plain' && $val === 'sp-77') { unset($a['record'], $b['record']); }
        check("EXS-4 $fld=" . json_encode($val) . ': the table and the export agree (' . json_encode($a) . ' / ' . json_encode($b) . ')', $a === $b);
    }
    $r = ask(mod('nurse', $DB, $DATA_B, $FULL), 'r_plain', ['r_plain' => 'sp-77'], '1', 'result');
    check('EXS-4 of two holders, the one spelled exactly as looked up is named', $r['record'] === '40');

    // ---- EXS-1: a user in a Data Access Group ---------------------------------------------
    $DD = [
        'record_id'   => f('enrol'),
        'specimen_id' => f('lab_reg'),
        'site_code'   => f('lab_reg'),
        'home_site'   => f('enrol'),
        'r_spec'      => f('result', '@UVEXISTS={"in":"[specimen_id]","blockSave":"hard"}'),
        'r_match'     => f('result', '@UVEXISTS={"in":"[specimen_id]","match":{"site_code":"[home_site]"}}'),
        'r_rec'       => f('result', '@UVEXISTS=record'),
    ];
    $DATA_D = [
        '1' => [351 => ['record_id' => '1', 'specimen_id' => 'SP-1', 'home_site' => 'A', 'redcap_data_access_group' => 'north']],
        '2' => [351 => ['record_id' => '2', 'specimen_id' => 'SP-2', 'site_code' => 'A', 'redcap_data_access_group' => 'north']],
    ];
    $GR = ['north' => ['forms' => ['enrol' => '1', 'lab_reg' => '1', 'result' => '1'], 'group_id' => 7, 'expiration' => '']];
    $m = mod('north', $DD, $DATA_D, $GR);
    $r = ask($m, 'r_spec', ['r_spec' => 'SP-404'], '1', 'result', 7);
    check('EXS-1 every record in the caller\'s group, value saved nowhere: not found (got ' . json_encode($r) . ')', $r['state'] === 'not-found');
    \REDCap::$data[149]['1'][351]['r_spec'] = 'SP-404';
    $m->logCalls = [];
    $m->redcap_save_record(149, '1', 'result', 351, 7, null, null, 1);
    check('EXS-1 the audit by the group user logs a violation, not a rule problem',
        count(logsOf($m, 'invalid-id-saved')) === 1 && !logsOf($m, 'uvalidate-unconfigurable'));
    $DATA_D2 = $DATA_D + ['3' => [351 => ['record_id' => '3', 'specimen_id' => 'SP-3', 'site_code' => 'A', 'redcap_data_access_group' => 'south']]];
    $m = mod('north', $DD, $DATA_D2, $GR);
    \REDCap::$confineTo = 'north';
    $r = ask($m, 'r_spec', ['r_spec' => 'SP-404'], '1', 'result', 7);
    check('EXS-1 reads confined to the group, value saved nowhere: not found (got ' . json_encode($r) . ')', $r['state'] === 'not-found');
    $r = ask($m, 'r_spec', ['r_spec' => 'SP-3'], '1', 'result', 7);
    check('EXS-1 confined, value saved only in the other group: found, the record not named (got ' . json_encode($r) . ')',
        $r['state'] === 'found' && $r['record'] === null);
    $r = ask($m, 'r_match', ['r_match' => 'SP-3'], '1', 'result', 7);
    check('EXS-1 confined, a "match" rule cannot be judged on a record it cannot read: unknown (got ' . json_encode($r) . ')',
        $r['state'] === 'unknown');
    $r = ask($m, 'r_match', ['r_match' => 'SP-404'], '1', 'result', 7);
    check('EXS-1 confined, a "match" rule and a value saved nowhere: not found', $r['state'] === 'not-found');
    $r = ask($m, 'r_rec', ['r_rec' => '3'], '1', 'result', 7);
    check('EXS-1 confined, a record ID of the other group: found (got ' . json_encode($r) . ')', $r['state'] === 'found' && $r['record'] === null);
    $r = ask($m, 'r_rec', ['r_rec' => '404'], '1', 'result', 7);
    check('EXS-1 confined, a record ID that does not exist: not found (got ' . json_encode($r) . ')', $r['state'] === 'not-found');
    \REDCap::$confineTo = null;
    $m = mod('north', $DD, $DATA_D2, $GR);
    $r = ask($m, 'r_spec', ['r_spec' => 'SP-3'], '1', 'result', 7);
    check('EXS-1 not confined, value saved only in the other group: found, not named', $r['state'] === 'found' && $r['record'] === null);
    $GLOBALS['__NO_TABLE'] = true;
    $m = mod('north', $DD, $DATA_D, $GR);
    $r = ask($m, 'r_spec', ['r_spec' => 'SP-404'], '1', 'result', 7);
    check('EXS-1 without the data table the old answer stands: unknown', $r['state'] === 'unknown');
    $GLOBALS['__NO_TABLE'] = false;

    // ---- EXS-3: a returned value goes only into a form the saver may change ------------
    $DA = [
        'record_id'   => f('enrol'),
        'home_code'   => f('enrol'),
        'specimen_id' => f('lab_reg'),
        'code'        => f('lab_reg'),
        'lab_site'    => f('lab_reg'),
        'r_spec'      => f('result', '@UVEXISTS={"in":"[specimen_id]","match":{"code":"[home_code]"},"return":{"lab_site":"[r_site]"}}'),
        'r_site'      => f('result', '@READONLY'),
    ];
    $DATA_A = [
        '1' => [351 => ['record_id' => '1', 'specimen_id' => 'SP-1', 'code' => 'K1', 'lab_site' => 'North']],
        '2' => [351 => ['record_id' => '2', 'specimen_id' => 'SP-1', 'code' => 'K2', 'lab_site' => 'South']],
        '9' => [351 => ['record_id' => '9', 'home_code' => 'K1', 'r_spec' => 'SP-1', 'r_site' => 'North', 'result_complete' => '2']],
    ];
    foreach (['2' => false, '1' => true, '3' => true] as $level => $writes) {
        $RO = ['ro' => ['forms' => ['enrol' => '1', 'lab_reg' => '2', 'result' => (string) $level], 'group_id' => null, 'expiration' => '']];
        $m = mod('ro', $DA, $DATA_A, $RO);
        \REDCap::$data[149]['9'][351]['home_code'] = 'K2';
        $m->redcap_save_record(149, '9', 'enrol', 351, null, null, null, 1);
        $site = \REDCap::$data[149]['9'][351]['r_site'];
        $probs = array_values(array_filter(logsOf($m, 'uvalidate-unconfigurable'), function ($u) {
            return strpos($u['why'], 'may not edit the form "result"') !== false;
        }));
        if ($writes) {
            check("EXS-3 rights $level on the form the rule fills: the save writes it", $site === 'South' && !$probs);
        } else {
            check('EXS-3 read-only rights on the form the rule fills: nothing is written (got ' . json_encode($site) . ')',
                $site === 'North' && !\REDCap::$saves);
            check('...and one rule problem says why', count($probs) === 1);
        }
    }
    $RO = ['ro' => ['forms' => ['enrol' => '1', 'lab_reg' => '2', 'result' => '2'], 'group_id' => null, 'expiration' => '']];
    $m = mod('ro', $DA, $DATA_A, $RO);
    $m->redcap_save_record(149, '9', 'enrol', 351, null, null, null, 1);
    check('EXS-3 read only, but the value would not change: no rule problem',
        !array_filter(logsOf($m, 'uvalidate-unconfigurable'), function ($u) { return strpos($u['why'], 'may not edit') !== false; }));

    // ---- EXS-2: the page of a form that may only be read --------------------------------
    $m = mod('nurse', $DA, $DATA_A, $FULL);
    $cfg = pageConfig($m, '9', 'result');
    check('EXS-2 an editable form: no formReadOnly', is_array($cfg) && !array_key_exists('formReadOnly', $cfg));
    $RO2 = ['ro' => ['forms' => ['enrol' => '1', 'lab_reg' => '1', 'result' => '2'], 'group_id' => null, 'expiration' => '']];
    $m = mod('ro', $DA, $DATA_A, $RO2);
    check('EXS-2 read-only rights to the form: formReadOnly', (pageConfig($m, '9', 'result')['formReadOnly'] ?? null) === true);
    $m = mod('nurse', $DA, $DATA_A, $FULL);
    \REDCap::$locked = ['149|9|351|result|1'];
    check('EXS-2 a locked form: formReadOnly', (pageConfig($m, '9', 'result')['formReadOnly'] ?? null) === true);
    \REDCap::$locked = ['149|9|351|result|2'];
    check('EXS-2 another instance locked: this one is editable', !array_key_exists('formReadOnly', pageConfig($m, '9', 'result')));
    \REDCap::$locked = [];
    \REDCap::$lockedRecords = ['9'];
    check('EXS-2 a locked record: formReadOnly', (pageConfig($m, '9', 'result')['formReadOnly'] ?? null) === true);
    $m = mod('ro', $DB, $DATA_B, ['ro' => ['forms' => ['enrol' => '1', 'lab_reg' => '1', 'result' => '2'], 'group_id' => null, 'expiration' => '']]);
    $DBX = $DB; unset($DBX['r_ret']);
    $m = mod('ro', $DBX, $DATA_B, ['ro' => ['forms' => ['enrol' => '1', 'lab_reg' => '1', 'result' => '2'], 'group_id' => null, 'expiration' => '']]);
    check('EXS-2 no rule on the page fills a field: no flag, no lock read', !array_key_exists('formReadOnly', pageConfig($m, '1', 'result')));

    // ---- EXS-6: "in" on the same form -----------------------------------------------------
    $DC = [
        'record_id' => f('hh'),
        'hh_id'     => f('hh'),
        'hh_ref'    => f('hh', '@UVEXISTS={"in":"[hh_id]","blockSave":"hard"}'),
        'hh_site'   => f('hh'),
        'hh_ref_m'  => f('hh', '@UVEXISTS={"in":"[hh_id]","match":{"hh_site":"[hh_site]"}}'),
        'lab_id'    => f('enrol'),
        'other_ref' => f('hh', '@UVEXISTS=[lab_id]'),
    ];
    $DATA_C = [
        '1'  => [351 => ['record_id' => '1', 'hh_id' => 'H-1', 'hh_site' => 'A']],
        '20' => [351 => ['record_id' => '20', 'hh_id' => 'H-20', 'hh_site' => 'A']],
    ];
    $m = mod('nurse', $DC, $DATA_C, $FULL);
    $cfg = pageConfig($m, '21', 'hh');
    check('EXS-6 the page is told the searched field is on this form', (ruleOf($cfg, 'hh_ref')['existsSelfIn'] ?? null) === 'hh_id');
    check('EXS-6 ...and only then', !array_key_exists('existsSelfIn', ruleOf($cfg, 'other_ref') ?: ['existsSelfIn' => 1]));
    $r = ask($m, 'hh_ref', ['hh_ref' => 'H-21', 'hh_id' => 'H-21'], '21', 'hh');
    check('EXS-6 a new record whose own unsaved value is the one looked up: found (got ' . json_encode($r) . ')', $r['state'] === 'found');
    $r = ask($m, 'hh_ref', ['hh_ref' => 'h-21 ', 'hh_id' => 'H-21'], '21', 'hh');
    check('EXS-6 ...compared as the lookup compares (case, spaces)', $r['state'] === 'found');
    $r = ask($m, 'hh_ref', ['hh_ref' => 'H-21', 'hh_id' => 'H-22'], '21', 'hh');
    check('EXS-6 the page holds another value there: not found', $r['state'] === 'not-found');
    $r = ask($m, 'hh_ref', ['hh_ref' => 'H-20', 'hh_id' => 'H-99'], '20', 'hh');
    check('EXS-6 the saved value of the record being edited no longer counts once the page changed it (got ' . json_encode($r) . ')',
        $r['state'] === 'not-found');
    $r = ask($m, 'hh_ref', ['hh_ref' => 'H-1', 'hh_id' => 'H-99'], '20', 'hh');
    check('EXS-6 another record that holds it still counts', $r['state'] === 'found' && $r['record'] === '1');
    $r = ask($m, 'hh_ref', ['hh_ref' => 'H-20'], '20', 'hh');
    check('EXS-6 a page that sent no value there: the saved record counts, as before', $r['state'] === 'found');
    $r = ask($m, 'hh_ref_m', ['hh_ref_m' => 'H-21', 'hh_id' => 'H-21', 'hh_site' => 'A'], '21', 'hh');
    check('EXS-6 a "match" rule whose only holder is the record being edited: unknown, the save decides (got ' . json_encode($r) . ')',
        $r['state'] === 'unknown');
    \REDCap::$data[149]['21'] = [351 => ['record_id' => '21', 'hh_id' => 'H-21', 'hh_ref' => 'H-21']];
    $m->logCalls = [];
    $m->redcap_save_record(149, '21', 'hh', 351, null, null, null, 1);
    check('EXS-6 the audit of the saved record agrees: no finding', !array_filter(logsOf($m, 'invalid-id-saved'), function ($e) {
        return ($e['field'] ?? '') === 'hh_ref';
    }));
    $GLOBALS['__NO_TABLE'] = true;
    $m = mod('nurse', $DC, $DATA_C, $FULL);
    $r = ask($m, 'hh_ref', ['hh_ref' => 'H-20', 'hh_id' => 'H-99'], '20', 'hh');
    check('EXS-6 without the data table too: the changed value is what counts', $r['state'] === 'not-found');
    $GLOBALS['__NO_TABLE'] = false;

    // ---- EXS-5 / PRF-5: the scan index from the data table --------------------------------
    $cls = '\INSPIRE\UniversalValidator\UniversalValidator';
    $gr = new \ReflectionMethod($cls, 'getRules'); $gr->setAccessible(true);
    $fe = new \ReflectionMethod($cls, 'findingsExists'); $fe->setAccessible(true);
    $scan = function ($m, $field, $value, $rec, array $meta = []) use ($gr, $fe) {
        $rule = null;
        foreach ($gr->invoke($m, 149) as $rr) if (in_array($field, $rr['fields'] ?? [], true)) $rule = $rr;
        return $fe->invoke($m, $rule, 'exists', [$field => $value], [], null, 149, $rec, 351, [], $meta + ['dag' => null, 'existsIndex' => true]);
    };
    foreach (['1', '2'] as $rec) {
        $m = mod('nurse', $DB, $DATA_B, $FULL);
        $out = $scan($m, 'r_plain', 'SP-NONE', $rec);
        check("EXS-5 a work request for record $rec: no export, one statement (got " . count(\REDCap::$calls) . ' reads, '
            . count($GLOBALS['__TABLE_READS']) . ' statements)',
            count(\REDCap::$calls) === 0 && count($GLOBALS['__TABLE_READS']) === 2 && count($out['invalid']) === 1);
    }
    $DS = $DD + ['r_dag' => f('result', '@UVEXISTS={"in":"[specimen_id]","scope":"dag"}')];
    foreach ([['r_plain', 'SP-77', $DB, $DATA_B], ['r_plain', 'sp-77', $DB, $DATA_B], ['r_plain', 'SP-NONE', $DB, $DATA_B],
              ['r_rec', '017', $DB, $DATA_B], ['r_rec', '0', $DB, $DATA_B],
              ['r_dag', 'SP-3', $DS, $DATA_D2], ['r_dag', 'SP-2', $DS, $DATA_D2]] as list($fld, $val, $dict, $data)) {
        list($a, $b) = both(function () use ($dict, $data, $FULL, $fld, $val, $scan) {
            $m = mod('nurse', $dict, $data, $FULL);
            $o = $scan($m, $fld, $val, '1', ['dag' => 'north']);
            return [count($o['invalid']), count($o['unconfigurable'])];
        });
        check("EXS-5 scan $fld=" . json_encode($val) . ': the table index and the export agree (' . json_encode($a) . ' / ' . json_encode($b) . ')', $a === $b);
    }

    fwrite(STDOUT, "exists_table_php: $n checks, $fail failure(s)" . ($INDEX ? ' through the lookup index' : '') . "\n");
    if (!$INDEX) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --index', $rc);
        if ($rc !== 0) $fail++;
    }
    exit($fail ? 1 : 0);
}
