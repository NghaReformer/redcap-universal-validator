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
            // How many rows a field has, counted as far as a limit
            // (FieldValues::moreRowsThan): __FIELD_ROWS, or none.
            if (strpos($sql, 'SELECT COUNT(*) FROM (SELECT 1 FROM redcap_data WHERE project_id = ? AND field_name = ?') !== false) {
                $GLOBALS['__COUNT_READS'][] = [$sql, $params];
                if (!empty($GLOBALS['__NO_TABLE'])) return false;
                return [[isset($GLOBALS['__FIELD_ROWS']) ? (int) $GLOBALS['__FIELD_ROWS'] : 0]];
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
        public static $repeats = [];         // event id => form => label, as REDCap lists what repeats
        public static function getRepeatingFormsEvents($pid = null) { return self::$repeats; }
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
        public function getRepeatingFormsEvents() { return REDCap::$repeats; }
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
    function mod($user, array $dict, array $data, array $rights, array $repeats = []) {
        $GLOBALS['__TEST_USER'] = $user;
        \REDCap::$repeats = $repeats;
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
    /** ask() from one event and instance of the record. */
    function askAt($m, $field, array $values, $record, $form, $event, $instance) {
        return $m->redcap_module_ajax('exists-check', ['field' => $field, 'values' => $values],
            149, $record, $form, $event, $instance, null, null, null, '', '', $GLOBALS['__TEST_USER'], null);
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
    // ---- a field too large to read whole: a person's live lookup answers at once -------
    // (reported 2026-11-11: with the value index not built, a lookup read every
    // saved value of the field while the person waited at Save.)
    $m = mod('nurse', $DB, $DATA_B, $FULL);
    $GLOBALS['__FIELD_ROWS'] = \INSPIRE\UniversalValidator\UniversalValidator::LIVE_WHOLE_READ_ROWS + 1;
    $GLOBALS['__COUNT_READS'] = [];
    $r = ask($m, 'r_plain', ['r_plain' => 'SP-77'], '1', 'result');
    if ($INDEX) {
        check('large field, index built: answered from the index, nothing counted',
            $r['state'] === 'found' && $GLOBALS['__COUNT_READS'] === [] && $GLOBALS['__TABLE_READS'] === []);
    } else {
        check('large field, no index answer: "could not check", and why (got ' . json_encode($r) . ')',
            $r['state'] === 'unknown' && ($r['why'] ?? null) === 'the lookup for this field is still being prepared');
        check('...after one bounded count, with no read of the field and no export',
            count($GLOBALS['__COUNT_READS']) === 1 && $GLOBALS['__TABLE_READS'] === [] && count(\REDCap::$calls) === 0
            && strpos($GLOBALS['__COUNT_READS'][0][0], 'LIMIT ' . (\INSPIRE\UniversalValidator\UniversalValidator::LIVE_WHOLE_READ_ROWS + 1) . ')') !== false);
        $m = mod('nurse', $DB, $DATA_B, $FULL);
        $GLOBALS['__FIELD_ROWS'] = \INSPIRE\UniversalValidator\UniversalValidator::LIVE_WHOLE_READ_ROWS;
        $r = ask($m, 'r_plain', ['r_plain' => 'SP-77'], '1', 'result');
        check('a field at the limit is read as before', $r['state'] === 'found' && count($GLOBALS['__TABLE_READS']) === 1);
        $m = mod('nurse', $DB, $DATA_B, $FULL);
        $GLOBALS['__FIELD_ROWS'] = \INSPIRE\UniversalValidator\UniversalValidator::LIVE_WHOLE_READ_ROWS + 1;
        $m->systemSettings = ['lookup-index-system-off' => '1'] + (isset($m->systemSettings) && is_array($m->systemSettings) ? $m->systemSettings : []);
        $GLOBALS['__COUNT_READS'] = [];
        $r = ask($m, 'r_plain', ['r_plain' => 'SP-77'], '1', 'result');
        check('large field, index turned off: read whatever its size, nothing counted',
            $r['state'] === 'found' && $GLOBALS['__COUNT_READS'] === [] && count($GLOBALS['__TABLE_READS']) === 1);
        // The save audit is not a person waiting: it reads in full.
        $m = mod('nurse', $DB, $DATA_B, $FULL);
        \REDCap::$data[149]['1'][351]['r_plain'] = 'SP-NONE';
        \REDCap::reset();
        $GLOBALS['__COUNT_READS'] = [];
        $m->redcap_save_record(149, '1', 'result', 351, null, null, null, 1);
        check('large field: the save audit still reads the field and logs the miss',
            $GLOBALS['__COUNT_READS'] === [] && count($GLOBALS['__TABLE_READS']) >= 1 && count(logsOf($m, 'invalid-id-saved')) === 1);
    }
    unset($GLOBALS['__FIELD_ROWS']);
    // ---- how long a save waits for a lookup: the system setting reaches the page --------
    foreach ([[null, null], ['', null], ['0', 0], ['800', 800], ['10000', 10000], ['10001', null], ['-1', null],
              ['1.5', null], ['abc', null], [' 250 ', 250], [2000, 2000]] as $case) {
        $m = mod('nurse', $DB, $DATA_B, $FULL);
        if ($case[0] !== null) $m->systemSettings['lookup-save-hold-ms'] = $case[0];
        $cfg = pageConfig($m, '1', 'result');
        check('lookup-save-hold-ms ' . json_encode($case[0]) . ' gives lookupHoldMs ' . json_encode($case[1])
            . ' (got ' . json_encode($cfg['lookupHoldMs'] ?? null) . ')',
            is_array($cfg) && ($cfg['lookupHoldMs'] ?? null) === $case[1] && array_key_exists('lookupHoldMs', $cfg) === ($case[1] !== null));
    }
    $m = mod('nurse', $DB, $DATA_B, $FULL);
    $m->systemSettings['lookup-save-hold-ms'] = '800';
    $cfg = pageConfig($m, '1', 'enrol');
    check('a page with no live lookup is not sent lookupHoldMs', !is_array($cfg) || !array_key_exists('lookupHoldMs', $cfg));
    // ---- quiet mode: the project setting reaches the page ----------------------------
    $m = mod('nurse', $DB, $DATA_B, $FULL);
    $cfg = pageConfig($m, '1', 'result');
    check('quiet-ok unset: the page is not sent quietOk', is_array($cfg) && !array_key_exists('quietOk', $cfg));
    $m = mod('nurse', $DB, $DATA_B, $FULL);
    $m->settingsBy[149]['quiet-ok'] = true;
    $cfg = pageConfig($m, '1', 'result');
    check('quiet-ok checked: the page is sent "quietOk": true', is_array($cfg) && ($cfg['quietOk'] ?? null) === true);
    $m->settingsBy[150]['quiet-ok'] = true;
    unset($m->settingsBy[149]['quiet-ok']);
    $cfg = pageConfig($m, '1', 'result');
    check('quiet-ok is read per project', is_array($cfg) && !array_key_exists('quietOk', $cfg));
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

    // ---- EXI-1: "in" on the same form leaves out the entry being edited, not the record ----
    // An aliquot names its parent sample, saved in another instance of the same record.
    $DE = ['record_id' => f('enrol'), 'sample_id' => f('result'),
           'parent' => f('result', '@UVEXISTS={"in":"[sample_id]","blockSave":"hard"}')];
    $DATA_E = ['1' => [351 => ['record_id' => '1'],
                       'repeat_instances' => [351 => ['result' => [1 => ['sample_id' => 'S-1', 'parent' => ''],
                                                                     2 => ['sample_id' => 'S-2', 'parent' => 'S-1']]]]]];
    $REP = [351 => ['result' => null]];
    foreach ([$REP, []] as $rp) {
        $how = $rp ? 'the form listed as repeating' : 'the repeat map naming nothing';
        list($a, $b) = both(function () use ($DE, $DATA_E, $FULL, $rp) {
            $m = mod('nurse', $DE, $DATA_E, $FULL, $rp);
            return core(askAt($m, 'parent', ['parent' => 'S-1', 'sample_id' => 'S-2'], '1', 'result', 351, 2));
        });
        check("EXI-1 a value saved in another instance of the record being edited is found ($how; got " . json_encode($a) . ')',
            $a['state'] === 'found' && $a['record'] === '1');
        check("EXI-1 ...without the data table too ($how)", $b['state'] === 'found');
        list($a, $b) = both(function () use ($DE, $DATA_E, $FULL, $rp) {
            $m = mod('nurse', $DE, $DATA_E, $FULL, $rp);
            return core(askAt($m, 'parent', ['parent' => 'S-1', 'sample_id' => 'S-9'], '1', 'result', 351, 1));
        });
        check("EXI-1 the saved value of the instance being edited does not count once the page changed it ($how; got " . json_encode($a) . ')',
            $a['state'] === 'not-found' && $b['state'] === 'not-found');
        list($a, $b) = both(function () use ($DE, $DATA_E, $FULL, $rp) {
            $m = mod('nurse', $DE, $DATA_E, $FULL, $rp);
            return core(askAt($m, 'parent', ['parent' => 'S-2', 'sample_id' => 'S-1'], '1', 'result', 351, 1));
        });
        check("EXI-1 instance 1 finds what instance 2 holds ($how)", $a['state'] === 'found' && $b['state'] === 'found');
    }
    $m = mod('nurse', $DE, $DATA_E, $FULL, $REP);
    $m->redcap_save_record(149, '1', 'result', 351, null, null, null, 2);
    check('EXI-1 the audit of the saved instance agrees: no finding', !logsOf($m, 'invalid-id-saved'));
    $m = mod('nurse', $DE, $DATA_E, $FULL, $REP);
    $o = $scan($m, 'parent', 'S-1', '1');
    check('EXI-1 the scan agrees: no finding', !$o['invalid'] && !$o['unconfigurable']);
    // Two events of one record, a form that does not repeat.
    $DATA_E2 = ['1' => [351 => ['record_id' => '1', 'hh_id' => 'H-A', 'hh_site' => 'A'],
                        352 => ['record_id' => '1', 'hh_id' => 'H-B', 'hh_site' => 'A']]];
    list($a, $b) = both(function () use ($DC, $DATA_E2, $FULL) {
        $m = mod('nurse', $DC, $DATA_E2, $FULL);
        return core(askAt($m, 'hh_ref', ['hh_ref' => 'H-A', 'hh_id' => 'H-B'], '1', 'hh', 352, 1));
    });
    check('EXI-1 a value saved in another event of the record being edited is found (got ' . json_encode($a) . ')',
        $a['state'] === 'found' && $a['record'] === '1' && $b['state'] === 'found');
    list($a, $b) = both(function () use ($DC, $DATA_E2, $FULL) {
        $m = mod('nurse', $DC, $DATA_E2, $FULL);
        return core(askAt($m, 'hh_ref', ['hh_ref' => 'H-B', 'hh_id' => 'H-Z'], '1', 'hh', 352, 1));
    });
    check('EXI-1 ...and the saved value of the event being edited does not count once the page changed it',
        $a['state'] === 'not-found' && $b['state'] === 'not-found');
    // The event row's value shows in every instance of another, repeating form of that event.
    $DATA_E3 = ['1' => [351 => ['record_id' => '1', 'hh_id' => 'H-A', 'hh_site' => 'A'],
                        'repeat_instances' => [351 => ['visits' => [1 => ['hh_id' => ''], 2 => ['hh_id' => '']]]]]];
    list($a, $b) = both(function () use ($DC, $DATA_E3, $FULL) {
        $m = mod('nurse', $DC, $DATA_E3, $FULL, [351 => ['visits' => null]]);
        return core(askAt($m, 'hh_ref', ['hh_ref' => 'H-A', 'hh_id' => 'H-Z'], '1', 'hh', 351, 1));
    });
    check('EXI-1 the event row being edited is left out of the instances of another form that carry it (got ' . json_encode($a) . ')',
        $a['state'] === 'not-found' && $b['state'] === 'not-found');

    // ---- EXI-3: "where" and "match" are judged on the caller's own group only ----------------
    // Reads NOT confined to the group, which is how a module's getData may run.
    $DG = [
        'record_id' => f('enrol'),
        'pid_code'  => f('enrol'),
        'hiv'       => f('enrol'),
        'dob'       => f('enrol'),
        'my_dob'    => f('result'),
        'g_where'   => f('result', '@UVEXISTS={"in":"[pid_code]","where":"[hiv]=\'1\'"}'),
        'g_match'   => f('result', '@UVEXISTS={"in":"[pid_code]","match":{"dob":"[my_dob]"}}'),
        'g_plain'   => f('result', '@UVEXISTS=[pid_code]'),
        'g_rec'     => f('result', '@UVEXISTS={"in":"record","where":"[hiv]=\'1\'"}'),
    ];
    $DATA_G = [
        '1' => [351 => ['record_id' => '1', 'pid_code' => 'N-POS', 'hiv' => '1', 'dob' => '1970-07-07', 'redcap_data_access_group' => 'north']],
        '2' => [351 => ['record_id' => '2', 'pid_code' => 'S-POS', 'hiv' => '1', 'dob' => '1990-01-02', 'redcap_data_access_group' => 'south']],
        '3' => [351 => ['record_id' => '3', 'pid_code' => 'S-NEG', 'hiv' => '0', 'dob' => '1985-05-05', 'redcap_data_access_group' => 'south']],
        '4' => [351 => ['record_id' => '4', 'pid_code' => 'N-NEG', 'hiv' => '0', 'redcap_data_access_group' => 'north']],
        '5' => [351 => ['record_id' => '5', 'redcap_data_access_group' => 'north']],
    ];
    $GN = ['north' => ['forms' => ['enrol' => '1', 'result' => '1'], 'group_id' => 7, 'expiration' => '']];
    $asks = [
        'where, another group, passes'  => ['g_where', ['g_where' => 'S-POS']],
        'where, another group, fails'   => ['g_where', ['g_where' => 'S-NEG']],
        'match, another group, right'   => ['g_match', ['g_match' => 'S-POS', 'my_dob' => '1990-01-02']],
        'match, another group, wrong'   => ['g_match', ['g_match' => 'S-POS', 'my_dob' => '1990-01-03']],
        'record ID, another group, passes' => ['g_rec', ['g_rec' => '2']],
        'record ID, another group, fails'  => ['g_rec', ['g_rec' => '3']],
    ];
    foreach ($asks as $label => list($fld, $vals)) {
        list($a, $b) = both(function () use ($DG, $DATA_G, $GN, $fld, $vals) {
            return core(ask(mod('north', $DG, $DATA_G, $GN), $fld, $vals, '5', 'result', 7));
        });
        check("EXI-3 $label: unknown, with nothing of what the entry holds (got " . json_encode($a) . ' / ' . json_encode($b) . ')',
            $a === ['state' => 'unknown', 'record' => null] && $b === $a);
    }
    list($a, $b) = both(function () use ($DG, $DATA_G, $GN) {
        return core(ask(mod('north', $DG, $DATA_G, $GN), 'g_where', ['g_where' => 'N-POS'], '5', 'result', 7));
    });
    check('EXI-3 the caller\'s own group is judged as before: found, named', $a === ['state' => 'found', 'record' => '1'] && $b === $a);
    list($a, $b) = both(function () use ($DG, $DATA_G, $GN) {
        return core(ask(mod('north', $DG, $DATA_G, $GN), 'g_where', ['g_where' => 'N-NEG'], '5', 'result', 7));
    });
    check('EXI-3 ...and "saved, but filtered" is said of their own group\'s entry (got ' . json_encode($a) . ')',
        $a === ['state' => 'not-found', 'record' => null, 'filtered' => true] && $b === $a);
    list($a, $b) = both(function () use ($DG, $DATA_G, $GN) {
        return core(ask(mod('north', $DG, $DATA_G, $GN), 'g_where', ['g_where' => 'NOWHERE'], '5', 'result', 7));
    });
    check('EXI-3 a value saved nowhere is still not found', $a === ['state' => 'not-found', 'record' => null] && $b === $a);
    list($a, $b) = both(function () use ($DG, $DATA_G, $GN) {
        return core(ask(mod('north', $DG, $DATA_G, $GN), 'g_plain', ['g_plain' => 'S-NEG'], '5', 'result', 7));
    });
    check('EXI-3 a rule that tests only the value still finds it in another group, unnamed', $a === ['state' => 'found', 'record' => null] && $b === $a);
    list($a, $b) = both(function () use ($DG, $DATA_G, $GN) {
        return core(ask(mod('north', $DG, $DATA_G, $GN), 'g_rec', ['g_rec' => '1'], '5', 'result', 7));
    });
    check('EXI-3 a record ID of their own group that passes "where" is found', $a['state'] === 'found' && $b['state'] === 'found');
    // The save audit of the same person judges the same way.
    $DATA_G['5'][351]['g_where'] = 'S-NEG';
    $m = mod('north', $DG, $DATA_G, $GN);
    $m->redcap_save_record(149, '5', 'result', 351, 7, null, null, 1);
    check('EXI-3 the audit of a person in a group reports no finding from another group\'s entry',
        !logsOf($m, 'invalid-id-saved') && count(array_filter(logsOf($m, 'uvalidate-unconfigurable'), function ($e) {
            return strpos((string) ($e['why'] ?? ''), 'another Data Access Group') !== false;
        })) === 1);
    // Staff in no group are answered in full.
    $r = core(ask(mod('nurse', $DG, $DATA_G, $FULL), 'g_where', ['g_where' => 'S-NEG'], '5', 'result'));
    check('EXI-3 staff in no group: judged on every entry', $r === ['state' => 'not-found', 'record' => null, 'filtered' => true]);

    // ---- EXI-9: the candidates are read in chunks, and no further than the answer needs -------
    $DK = ['record_id' => f('enrol'), 'site_code' => f('enrol'), 'site_kind' => f('enrol'), 'my_kind' => f('result'),
           'k_plain' => f('result', '@UVEXISTS=[site_code]'),
           'k_match' => f('result', '@UVEXISTS={"in":"[site_code]","match":{"site_kind":"[my_kind]"}}'),
           'k_ret'   => f('result', '@UVEXISTS={"in":"[site_code]","return":{"site_kind":"[k_kind]"}}'),
           'k_kind'  => f('result', '@READONLY')];
    $big = [];
    for ($i = 1; $i <= 1500; $i++) $big[(string) $i] = [351 => ['record_id' => (string) $i, 'site_code' => 'KLA', 'site_kind' => $i === 1400 ? 'B' : 'A']];
    $m = mod('nurse', $DK, $big, $FULL);
    $r = ask($m, 'k_plain', ['k_plain' => 'KLA'], '9999', 'result');
    check('EXI-9 a value 1,500 records hold: found from the first chunk of records (exported ' . \REDCap::$rowsOut . ')',
        $r['state'] === 'found' && \REDCap::$rowsOut <= 200);
    $m = mod('nurse', $DK, $big, $FULL);
    $r = ask($m, 'k_match', ['k_match' => 'KLA', 'my_kind' => 'B'], '9999', 'result');
    check('EXI-9 a "match" only a late record meets is still found (got ' . json_encode(core($r)) . ')', $r['state'] === 'found' && $r['record'] === '1400');
    $m = mod('nurse', $DK, $big, $FULL);
    $r = ask($m, 'k_match', ['k_match' => 'KLA', 'my_kind' => 'Z'], '9999', 'result');
    $most = 0;
    foreach (\REDCap::$calls as $c) $most = max($most, count($c['records'] ?? []));
    check('EXI-9 a miss reads every candidate, never more than a chunk in one read (largest read ' . $most . ' records)',
        $r['state'] === 'not-found' && \REDCap::$rowsOut === 1500 && $most <= 200);
    $m = mod('nurse', $DK, $big, $FULL);
    $r = ask($m, 'k_ret', ['k_ret' => 'KLA'], '9999', 'result');
    check('EXI-9 a rule that copies values reads on until two entries disagree, then stops (exported ' . \REDCap::$rowsOut . ')',
        ($r['fill'] ?? null) === 'conflict' && \REDCap::$rowsOut === 1400);
    $same = [];
    for ($i = 1; $i <= 450; $i++) $same[(string) $i] = [351 => ['record_id' => (string) $i, 'site_code' => 'KLA', 'site_kind' => 'A']];
    $m = mod('nurse', $DK, $same, $FULL);
    $r = ask($m, 'k_ret', ['k_ret' => 'KLA'], '9999', 'result');
    check('EXI-9 ...and copies them when every entry agrees', ($r['values'] ?? null) === ['k_kind' => 'A'] && \REDCap::$rowsOut === 450);

    // ---- EXI-13: a table row whose record cannot be read is not "found" by name ---------------
    // Staff in no group: REDCap confines nothing, so a record getData does not
    // hand back is a row left behind, or a record deleted in between.
    $m = mod('nurse', $DG, $DATA_G, $FULL);
    \REDCap::$confineTo = 'north';
    $r = core(ask($m, 'g_plain', ['g_plain' => 'S-NEG'], '5', 'result'));
    check('EXI-13 staff in no group, a row whose record is not handed back: could not check, no record named (got ' . json_encode($r) . ')',
        $r === ['state' => 'unknown', 'record' => null]);
    $m = mod('north', $DG, $DATA_G, $GN);
    \REDCap::$confineTo = 'north';
    $r = core(ask($m, 'g_plain', ['g_plain' => 'S-NEG'], '5', 'result', 7));
    check('EXI-13 a person in a group still learns the value is saved in another, unnamed', $r === ['state' => 'found', 'record' => null]);
    \REDCap::$confineTo = null;

    fwrite(STDOUT, "exists_table_php: $n checks, $fail failure(s)" . ($INDEX ? ' through the lookup index' : '') . "\n");
    if (!$INDEX) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --index', $rc);
        if ($rc !== 0) $fail++;
    }
    exit($fail ? 1 : 0);
}
