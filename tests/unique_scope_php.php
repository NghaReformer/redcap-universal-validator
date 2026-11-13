<?php
/**
 * unique_scope_php.php — @UVUNIQUE groups, composite placement, the throttle
 * answer and the save audit's shared read (UNQ-1, UNQ-2, UNQ-3, PRF-1).
 *
 * UNQ-1: under "scope":"dag" records in no group form one group of their own.
 * The live check, the post-save audit and the scan give that one answer over
 * the same four records. A record not saved yet is created in the user's
 * group, or in none.
 *
 * UNQ-2: a "with" field that shares no event with the tagged field, or sits on
 * a repeating instrument of its own in every event that holds both, is refused
 * when the rule is read. A part a context could not read is a rule problem in
 * the audit and "could not check" live, never compared as a blank.
 *
 * UNQ-3: a signed-in caller over the throttle gets "unknown", "throttled" and
 * a reason, as exists-check answers it, instead of an error the page hid.
 *
 * PRF-1: the unique rules of one save share one read of the project per event
 * filter, and give the verdicts separate reads gave.
 *
 * The browser twin is tests/unique_dom_js.cjs (asks on change and blur, and
 * shows "could not check").
 *
 * Run:  php tests/unique_scope_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        /** The legacy one-request scan (tests/support/scan_facade.php, QUA-3). */
        public function scanProject(...$a) { return \INSPIRE\UniversalValidator\scan_project($this, ...$a); }
        public $logCalls = [];
        public $projectSettings = [];
        public $systemSettings = [];
        public $projectIdReturn = null;
        public function getSubSettings($k, $pid = null) { return []; }
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
        public static $rateBuckets = [];
        public $lastInsertId = 0;
        public function query($sql, $params = []) {
            // The data table (Scan\FieldValues), unless this run leaves it out.
            if (strpos($sql, 'SELECT data_table FROM redcap_projects') !== false) {
                return empty($GLOBALS['__NO_TABLE']) ? [['redcap_data']] : [];
            }
            // The rows of records REDCap did not hand back (FieldValues::rowsOf).
            if (strpos($sql, 'SELECT record, event_id, field_name, `value`, instance FROM redcap_data') !== false) {
                if (!empty($GLOBALS['__NO_TABLE'])) return false;
                $GLOBALS['__RAW_READS'][] = $sql;
                return \REDCap::rawRows($sql, $params);
            }
            // The named records' rows (FieldValues::recordsAmong, after the
            // lookup index named them in the --index run).
            if (strpos($sql, 'FROM redcap_data WHERE project_id = ? AND record IN (') !== false) {
                if (!empty($GLOBALS['__NO_TABLE'])) return false;
                $GLOBALS['__AMONG_READS'][] = $sql;
                return \uv_among_rows($sql, $params);
            }
            // How many rows a field has, counted as far as a limit
            // (FieldValues::moreRowsThan): __FIELD_ROWS, or none.
            if (strpos($sql, 'SELECT COUNT(*) FROM (SELECT 1 FROM redcap_data WHERE project_id = ? AND field_name = ?') !== false) {
                $GLOBALS['__COUNT_READS'][] = $sql;
                if (!empty($GLOBALS['__NO_TABLE'])) return false;
                return [[isset($GLOBALS['__FIELD_ROWS']) ? (int) $GLOBALS['__FIELD_ROWS'] : 0]];
            }
            if (strpos($sql, 'FROM redcap_data WHERE project_id = ? AND field_name = ?') !== false) {
                $GLOBALS['__TABLE_READS'][] = $sql;
                return \REDCap::tableRows($sql, $params);
            }
            if (strpos($sql, 'SELECT LAST_INSERT_ID()') !== false) return [[$this->lastInsertId]];
            if (strpos($sql, 'SELECT ROW_COUNT()') !== false) return [[1]];
            if (strpos($sql, 'uv_rate_bucket') !== false && strpos(ltrim($sql), 'INSERT') === 0) {
                $k = (int) $params[0] . '|' . (int) $params[1];
                self::$rateBuckets[$k] = isset(self::$rateBuckets[$k]) ? self::$rateBuckets[$k] + 1 : 1;
                $this->lastInsertId = self::$rateBuckets[$k];
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
        public function getRights($pid = null) {
            $all = \REDCap::$rights;
            return isset($all[$this->n]) ? $all[$this->n] : null;
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
        public static $dictionary = [];
        public static $rights = [];
        public static $groups = [];          // group_id => unique name
        public static $calls = ['full' => 0, 'filtered' => 0, 'record' => 0];
        public static $events = [351 => 'enrolment_arm_1'];
        public static $mappings = null;
        public static $longitudinal = false;
        public static $repeating = null;     // getRepeatingFormsEvents answer, or null (not offered)
        public static $recordReads = [];     // the records list of each getData that named records
        /** The data table's rows of $data: (record, field, value), non-blank values only, as REDCap stores them. */
        public static function tableRows($sql, array $params) {
            $event = strpos($sql, 'AND event_id = ?') !== false ? (int) array_pop($params) : null;
            array_shift($params);
            $fields = array_flip($params);
            $out = [];
            $put = function ($rec, $row) use (&$out, $fields) {
                foreach ((array) $row as $f => $v) {
                    if (isset($fields[$f]) && !is_array($v) && (string) $v !== '') $out[] = [(string) $rec, $f, (string) $v];
                }
            };
            foreach (self::$data as $rec => $node) {
                foreach ($node as $ek => $row) {
                    if ($ek !== 'repeat_instances') {
                        if ($event === null || (int) $ek === $event) $put($rec, $row);
                        continue;
                    }
                    foreach ((array) $row as $evt => $byInstr) {
                        if ($event !== null && (int) $evt !== $event) continue;
                        foreach ((array) $byInstr as $byInst) foreach ((array) $byInst as $irow) $put($rec, $irow);
                    }
                }
            }
            return $out;
        }
        /** The unique group name a record node carries, or ''. */
        public static function groupOf(array $node) {
            foreach ($node as $ek => $row) {
                if ($ek !== 'repeat_instances' && isset($row['redcap_data_access_group'])) return (string) $row['redcap_data_access_group'];
            }
            return '';
        }
        /**
         * The data table's rows of the named records and fields, as
         * FieldValues::rowsOf reads them: [record, event_id, field_name,
         * value, instance]. A record's group is its "__GROUPID__" row.
         */
        public static function rawRows($sql, array $params) {
            preg_match('/record IN \(([?, ]*)\) AND field_name IN \(([?, ]*)\)/', $sql, $m);
            $nr = substr_count($m[1], '?');
            $nf = substr_count($m[2], '?');
            $want = array_flip(array_map('strval', array_slice($params, 1, $nr)));
            $fields = array_flip(array_slice($params, 1 + $nr, $nf));
            $event = strpos($sql, 'AND event_id = ?') !== false ? (int) $params[count($params) - 1] : null;
            $out = [];
            $put = function ($rec, $evt, $row, $inst) use (&$out, $fields, $event) {
                if ($event !== null && (int) $evt !== $event) return;
                foreach ((array) $row as $f => $v) {
                    if (isset($fields[$f]) && !is_array($v) && (string) $v !== '') $out[] = [(string) $rec, (string) $evt, $f, (string) $v, $inst];
                }
            };
            foreach (self::$data as $rec => $node) {
                if (!isset($want[(string) $rec])) continue;
                $gid = array_search(self::groupOf($node), self::$groups, true);
                if ($gid !== false && isset($fields['__GROUPID__']) && $event === null) $out[] = [(string) $rec, '351', '__GROUPID__', (string) $gid, null];
                foreach ($node as $ek => $row) {
                    if ($ek !== 'repeat_instances') { $put($rec, $ek, $row, null); continue; }
                    foreach ((array) $row as $evt => $byInstr) {
                        foreach ((array) $byInstr as $byInst) {
                            foreach ((array) $byInst as $inst => $irow) $put($rec, $evt, $irow, (int) $inst === 1 ? null : (int) $inst);
                        }
                    }
                }
            }
            return $out;
        }
        public static function getData($p) {
            $filtered = isset($p['filterLogic']) && $p['filterLogic'] !== '';
            if ($filtered) self::$calls['filtered']++;
            elseif (!empty($p['records'])) self::$calls['record']++;
            else self::$calls['full']++;
            if (!empty($p['records'])) self::$recordReads[] = array_map('strval', $p['records']);
            $clauses = [];
            if ($filtered) {
                foreach (preg_split('/ and /', $p['filterLogic']) as $part) {
                    $alts = [];
                    foreach (preg_split('/ or /', trim($part, '()')) as $alt) {
                        if (preg_match("/^lower\\(\\[([a-z0-9_]+)\\]\\) = '([^']*)'$/", $alt, $m)) $alts[] = [$m[1], $m[2], 'lower'];
                        elseif (preg_match("/^\\[([a-z0-9_]+)\\] = '([^']*)'$/", $alt, $m)) $alts[] = [$m[1], $m[2], 'eq'];
                        elseif (preg_match("/^\\[([a-z0-9_]+)\\] = ([-0-9.]+)$/", $alt, $m)) $alts[] = [$m[1], $m[2], 'num'];
                        else throw new \RuntimeException('mock cannot read filterLogic: ' . $alt);
                    }
                    $clauses[] = $alts;
                }
            }
            $out = [];
            foreach (self::$data as $rec => $node) {
                if (!empty($p['records']) && !in_array((string) $rec, array_map('strval', $p['records']), true)) continue;
                if (!empty($p['events'])) {
                    $node = array_intersect_key($node, array_flip(array_map('intval', $p['events'])) + ['repeat_instances' => 1]);
                }
                if ($filtered) {
                    $ok = false;
                    foreach ($node as $ek => $row) {
                        if ($ek === 'repeat_instances') continue;
                        $all = true;
                        foreach ($clauses as $alts) {
                            $any = false;
                            foreach ($alts as $c) {
                                $rv = isset($row[$c[0]]) ? (string) $row[$c[0]] : '';
                                if ($c[2] === 'eq' && $rv === $c[1]) $any = true;
                                if ($c[2] === 'lower' && strtolower($rv) === $c[1]) $any = true;
                                if ($c[2] === 'num' && is_numeric($rv) && (float) $rv == (float) $c[1]) $any = true;
                            }
                            if (!$any) { $all = false; break; }
                        }
                        if ($all) { $ok = true; break; }
                    }
                    if (!$ok) continue;
                }
                // __CONFINE: REDCap hands a user in a Data Access Group that
                // group's records only (UNQ-2 of 2026-11-13).
                if (!empty($GLOBALS['__CONFINE']) && self::groupOf($node) !== $GLOBALS['__CONFINE']) continue;
                $out[$rec] = $node;
            }
            return $out;
        }
        public static function getDataDictionary($pid, $f = 'array') {
            if (!$pid) throw new \RuntimeException('needs pid');
            return self::$dictionary;
        }
        public static function getEventNames($unique = false, $assoc = false, $id = null) {
            if ($id !== null) return isset(self::$events[$id]) ? self::$events[$id] : false;
            return self::$events;
        }
        public static function getInstrumentEventMappings($pid = null) { return self::$mappings; }
        public static function isLongitudinal() { return self::$longitudinal; }
        public static function getRepeatingFormsEvents($pid = null) { return self::$repeating; }
        public static function getUserRights($u = null) { return self::$rights; }
        public static function getGroupNames($unique = false, $g = null) {
            if ($g === null) return self::$groups;
            return isset(self::$groups[(int) $g]) ? self::$groups[(int) $g] : '';
        }
        public static function getRecordIdField() { return 'record_id'; }
    }
    class Project {
        public $project = ['missing_data_codes' => ''];
        public function __construct($pid) {}
    }

    require_once __DIR__ . '/../UniversalValidator.php';
    require_once __DIR__ . '/support/scan_facade.php';
    require_once __DIR__ . '/support/fake_lookup_index.php';
    require_once __DIR__ . '/support/ArrayScanStore.php';

    use INSPIRE\UniversalValidator\UniversalValidator;

    $n = 0; $fail = 0;
    // Every check runs three times: with the data table (the lookup's first
    // read), in a second process without it (the export it falls back to),
    // and in a third with a lookup index that names the records holding the
    // value plus one that does not (tests/support/fake_lookup_index.php).
    $TABLE = !in_array('--no-table', isset($argv) ? $argv : [], true);
    $INDEX = in_array('--index', isset($argv) ? $argv : [], true);
    $GLOBALS['__NO_TABLE'] = !$TABLE;
    function check($label, $cond) {
        global $n, $fail, $TABLE, $INDEX; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, 'FAIL' . ($TABLE ? ($INDEX ? ' (lookup index)' : '') : ' (no data table)') . ": $label\n"); }
    }
    function uvf($form, $ann = '') {
        return ['field_type' => 'text', 'form_name' => $form, 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => '', 'identifier' => '',
                'select_choices_or_calculations' => ''];
    }
    function logged($m, $type) {
        return array_values(array_map(function ($c) { return $c[1]; },
            array_filter($m->logCalls, function ($c) use ($type) { return $c[0] === $type; })));
    }
    function mk(array $data, $user = 'coord') {
        $GLOBALS['__TEST_USER'] = $user;
        \REDCap::$data = $data;
        \REDCap::$calls = ['full' => 0, 'filtered' => 0, 'record' => 0];
        \REDCap::$recordReads = [];
        $GLOBALS['__TABLE_READS'] = [];
        $GLOBALS['__AMONG_READS'] = [];
        $GLOBALS['__COUNT_READS'] = [];
        $m = new UniversalValidator();
        if ($GLOBALS['INDEX']) uv_use_fake_index($m);
        $m->projectSettings = ['log-values' => 'raw'];
        $m->projectIdReturn = 149;
        return $m;
    }
    function ask($m, $field, array $values, $record, $form, $event, $user, $group) {
        $GLOBALS['__TEST_USER'] = $user;
        return $m->redcap_module_ajax('unique-check', ['field' => $field, 'values' => $values],
            149, $record, $form, $event, 1, null, null, null, '', '', $user, $group);
    }
    function hits(array $res) {
        $out = [];
        foreach ($res['violations'] as $v) $out[] = $v['record'] . ':' . $v['field'];
        sort($out);
        return $out;
    }

    // ---- 1) UNQ-1: one meaning of "records in no group" ------------------------------
    \REDCap::$groups = [11 => 'site_a', 12 => 'site_b'];
    \REDCap::$rights = ['coord' => ['forms' => ['reg' => '1']], 'alice' => ['forms' => ['reg' => '1'], 'group_id' => 11]];
    \REDCap::$dictionary = ['record_id' => uvf('reg'), 'spec' => uvf('reg', '@UVUNIQUE={"scope":"dag"}')];
    $DAG = [
        '1' => [351 => ['record_id' => '1', 'spec' => 'X1', 'redcap_data_access_group' => 'site_a']],
        '2' => [351 => ['record_id' => '2', 'spec' => 'X1', 'redcap_data_access_group' => '']],
        '3' => [351 => ['record_id' => '3', 'spec' => 'Y1', 'redcap_data_access_group' => '']],
        '4' => [351 => ['record_id' => '4', 'spec' => 'Y1', 'redcap_data_access_group' => '']],
    ];
    $r = ask(mk($DAG), 'spec', ['spec' => 'X1'], '2', 'reg', 351, 'coord', null);
    check('dag live: an ungrouped record is not compared with a record of site_a (got ' . json_encode($r) . ')', ($r['used'] ?? null) === false);
    $r = ask(mk($DAG), 'spec', ['spec' => 'X1'], '1', 'reg', 351, 'coord', null);
    check('dag live: and site_a is not compared with an ungrouped record', ($r['used'] ?? null) === false);
    $r = ask(mk($DAG), 'spec', ['spec' => 'Y1'], '3', 'reg', 351, 'coord', null);
    check('dag live: two ungrouped records are compared with each other (got ' . json_encode($r) . ')',
        ($r['used'] ?? null) === true && ($r['record'] ?? null) === '4');
    $r = ask(mk($DAG), 'spec', ['spec' => 'Y1'], '5', 'reg', 351, 'coord', null);
    check('dag live: a new record of a user in no group is in no group', ($r['used'] ?? null) === true && ($r['record'] ?? null) === '3');
    $r = ask(mk($DAG), 'spec', ['spec' => 'Y1'], '5', 'reg', 351, 'alice', 11);
    check('dag live: a new record of a user in site_a is in site_a (Y1 is free there)', ($r['used'] ?? null) === false);
    $r = ask(mk($DAG), 'spec', ['spec' => 'X1'], '5', 'reg', 351, 'alice', 11);
    check('dag live: and X1 is used there', ($r['used'] ?? null) === true && ($r['record'] ?? null) === '1');
    $m = mk($DAG);
    ask($m, 'spec', ['spec' => 'Y1'], '3', 'reg', 351, 'coord', null);
    check('dag live: the group is read on its own, so the lookup narrows (a filtered read, no full one)',
        \REDCap::$calls['full'] === 0
        && ($TABLE ? \REDCap::$calls['filtered'] === 0 && count($GLOBALS['__TABLE_READS']) === ($INDEX ? 0 : 1) : \REDCap::$calls['filtered'] === 1));

    // ---- a field too large to read whole: a person's live lookup answers at once -------
    // (reported 2026-11-11: with the value index not built, a lookup read every
    // saved value of the field while the person waited at Save.)
    if ($TABLE) {
        $GLOBALS['__FIELD_ROWS'] = UniversalValidator::LIVE_WHOLE_READ_ROWS + 1;
        $m = mk($DAG);
        $r = ask($m, 'spec', ['spec' => 'Y1'], '3', 'reg', 351, 'coord', null);
        if ($INDEX) {
            check('large field, index built: answered from the index, nothing counted',
                ($r['used'] ?? null) === true && $GLOBALS['__COUNT_READS'] === [] && $GLOBALS['__TABLE_READS'] === []);
        } else {
            check('large field, no index answer: "could not check", and why (got ' . json_encode($r) . ')',
                ($r['unknown'] ?? null) === true && !array_key_exists('used', $r)
                && ($r['why'] ?? null) === 'the lookup for this field is still being prepared');
            check('...after one bounded count, with no read of the field and no export',
                count($GLOBALS['__COUNT_READS']) === 1 && $GLOBALS['__TABLE_READS'] === []
                && \REDCap::$calls['full'] === 0 && \REDCap::$calls['filtered'] === 0);
            $GLOBALS['__FIELD_ROWS'] = UniversalValidator::LIVE_WHOLE_READ_ROWS;
            $m = mk($DAG);
            $r = ask($m, 'spec', ['spec' => 'Y1'], '3', 'reg', 351, 'coord', null);
            check('a field at the limit is read as before', ($r['used'] ?? null) === true && count($GLOBALS['__TABLE_READS']) === 1);
            // The save audit runs inside the save request: it does not read
            // such a field either, and logs it as not checked (UNQ-3 of
            // 2026-11-13: 33 to 39 s at 400,000 values, on every save).
            $GLOBALS['__FIELD_ROWS'] = UniversalValidator::LIVE_WHOLE_READ_ROWS + 1;
            $m = mk($DAG);
            $GLOBALS['__COUNT_READS'] = [];
            $m->redcap_save_record(149, '3', 'reg', 351, null, null, null, 1);
            $unc = json_encode(logged($m, 'uvalidate-unconfigurable'));
            check('large field: the save audit reads neither the field nor the project',
                count($GLOBALS['__COUNT_READS']) === 1 && $GLOBALS['__TABLE_READS'] === [] && \REDCap::$calls['full'] === 0
                && \REDCap::$calls['filtered'] === 0);
            check('large field: ...and logs the field as not checked, never as passed or as a duplicate (got ' . $unc . ')',
                logged($m, 'invalid-id-saved') === [] && strpos($unc, 'field not checked') !== false
                && strpos($unc, 'value index is not built yet') !== false);
            // With the index turned off there is no other way to answer: the
            // bound holds all the same.
            $m = mk($DAG);
            $m->systemSettings['lookup-index-system-off'] = '1';
            $r = ask($m, 'spec', ['spec' => 'Y1'], '3', 'reg', 351, 'coord', null);
            check('large field, index turned off: "could not check", the field is not read (got ' . json_encode($r) . ')',
                ($r['unknown'] ?? null) === true && !array_key_exists('used', $r) && $GLOBALS['__TABLE_READS'] === []);
        }
        unset($GLOBALS['__FIELD_ROWS']);
    }

    $saved = function ($rec, $group) use ($DAG) {
        $m = mk($DAG);
        $m->redcap_save_record(149, $rec, 'reg', 351, $group, null, null, 1);
        return count(logged($m, 'invalid-id-saved'));
    };
    check('dag audit: record 2 (ungrouped, X1) is no duplicate of site_a', $saved('2', null) === 0);
    check('dag audit: record 1 (site_a, X1) is no duplicate of an ungrouped record', $saved('1', 11) === 0);
    check('dag audit: record 3 (ungrouped, Y1) is a duplicate of record 4', $saved('3', null) === 1);
    $res = mk($DAG)->scanProject(149);
    check('dag scan: records 3 and 4 only (got ' . json_encode(hits($res)) . ')', hits($res) === ['3:spec', '4:spec']);
    check('dag scan: and no rule problem for the ungrouped records', $res['unconfigurable'] === []);

    // ---- 2) UNQ-2: where a "with" field may sit ---------------------------------------
    \REDCap::$groups = [];
    \REDCap::$events = [351 => 'enrolment_arm_1', 352 => 'visit_arm_1'];
    \REDCap::$longitudinal = true;
    \REDCap::$rights = ['nurse' => ['forms' => ['enrol' => '1', 'lab' => '1', 'sites' => '1']]];
    $SPLIT = [
        '1' => [351 => ['record_id' => '1', 'site' => '1'], 352 => ['spec' => 'SP-9']],
        '2' => [351 => ['record_id' => '2', 'site' => '2'], 352 => ['spec' => 'SP-9']],
    ];
    \REDCap::$mappings = [
        ['arm_num' => 1, 'unique_event_name' => 'enrolment_arm_1', 'form' => 'enrol'],
        ['arm_num' => 1, 'unique_event_name' => 'visit_arm_1', 'form' => 'lab'],
    ];
    \REDCap::$dictionary = ['record_id' => uvf('enrol'), 'site' => uvf('enrol'),
                            'spec' => uvf('lab', '@UVUNIQUE={"with":["site"],"blockSave":"hard"}')];
    $r = ask(mk($SPLIT, 'nurse'), 'spec', ['spec' => 'SP-9'], '2', 'lab', 352, 'nurse', null);
    check('with on another event: refused when read, so the live check does not answer (got ' . json_encode($r) . ')',
        ($r['error'] ?? null) === 'not a checkable field');
    $m = mk($SPLIT, 'nurse');
    $m->redcap_save_record(149, '2', 'lab', 352, null, null, null, 1);
    check('with on another event: the audit logs no duplicate', logged($m, 'invalid-id-saved') === []);
    $res = mk($SPLIT, 'nurse')->scanProject(149);
    $why = json_encode($res['unconfigurable']);
    check('with on another event: the scan names the placement (got ' . $why . ')',
        hits($res) === [] && strpos($why, 'is collected in no event with') !== false);

    // the lab form on both events: the rule holds in 351, and in 352 the
    // composite part cannot be read
    \REDCap::$mappings[] = ['arm_num' => 1, 'unique_event_name' => 'enrolment_arm_1', 'form' => 'lab'];
    $r = ask(mk($SPLIT, 'nurse'), 'spec', ['spec' => 'SP-9'], '2', 'lab', 352, 'nurse', null);
    check('with read on an event without it: "could not check", with the reason (got ' . json_encode($r) . ')',
        ($r['unknown'] ?? null) === true && strpos((string) ($r['why'] ?? ''), 'not collected in this event') !== false);
    $m = mk($SPLIT, 'nurse');
    $m->redcap_save_record(149, '2', 'lab', 352, null, null, null, 1);
    $unc = json_encode(logged($m, 'uvalidate-unconfigurable'));
    check('with read on an event without it: the audit logs a rule problem, no duplicate (got ' . $unc . ')',
        logged($m, 'invalid-id-saved') === [] && strpos($unc, 'composite key') !== false);
    $BOTH = [
        '1' => [351 => ['record_id' => '1', 'site' => '1', 'spec' => 'SP-9']],
        '2' => [351 => ['record_id' => '2', 'site' => '2', 'spec' => 'SP-9']],
        '3' => [351 => ['record_id' => '3', 'site' => '1', 'spec' => 'SP-9']],
    ];
    $r = ask(mk($BOTH, 'nurse'), 'spec', ['spec' => 'SP-9', 'site' => '2'], '2', 'lab', 351, 'nurse', null);
    check('with on the same event: SP-9 at site 2 is free', ($r['used'] ?? null) === false);
    $r = ask(mk($BOTH, 'nurse'), 'spec', ['spec' => 'SP-9', 'site' => '1'], '3', 'lab', 351, 'nurse', null);
    check('with on the same event: SP-9 at site 1 is used by record 1', ($r['used'] ?? null) === true && ($r['record'] ?? null) === '1');

    // a "with" field on a repeating instrument of its own
    \REDCap::$mappings = [
        ['arm_num' => 1, 'unique_event_name' => 'enrolment_arm_1', 'form' => 'lab'],
        ['arm_num' => 1, 'unique_event_name' => 'enrolment_arm_1', 'form' => 'sites'],
    ];
    \REDCap::$repeating = [351 => ['sites' => '']];
    \REDCap::$dictionary = ['record_id' => uvf('lab'), 'site' => uvf('sites'),
                            'spec' => uvf('lab', '@UVUNIQUE={"with":["site"]}')];
    $res = mk([], 'nurse')->scanProject(149);
    $why = json_encode($res['unconfigurable']);
    check('with on its own repeating instrument: refused (got ' . $why . ')', strpos($why, 'repeating instrument') !== false);
    \REDCap::$repeating = [351 => ['lab' => '', 'sites' => '']];
    $res = mk([], 'nurse')->scanProject(149);
    check('both on repeating instruments: refused too', strpos(json_encode($res['unconfigurable']), 'repeating instrument') !== false);
    \REDCap::$repeating = [351 => ['lab' => '']];
    $res = mk([], 'nurse')->scanProject(149);
    check('only the tagged field repeats: accepted (the event row holds the site)',
        strpos(json_encode($res['unconfigurable']), 'repeating instrument') === false);
    \REDCap::$repeating = null;

    // ---- 3) UNQ-3: the throttle has its own answer ------------------------------------
    \REDCap::$events = [351 => 'enrolment_arm_1'];
    \REDCap::$longitudinal = false;
    \REDCap::$mappings = null;
    \REDCap::$rights = ['nurse' => ['forms' => ['reg' => '1']]];
    \REDCap::$dictionary = ['record_id' => uvf('reg'), 'spec' => uvf('reg', '@UVUNIQUE')];
    $THR = [];
    for ($i = 1; $i <= 5; $i++) $THR[(string) $i] = [351 => ['record_id' => (string) $i, 'spec' => 'S-' . $i]];
    session_start();
    $_SESSION = [];
    \ExternalModules\AbstractExternalModule::$rateBuckets = [];
    $m = mk($THR, 'nurse');
    for ($i = 0; $i < UniversalValidator::THROTTLE_SIGNED_IN; $i++) ask($m, 'spec', ['spec' => 'S-NEW-' . $i], '1', 'reg', 351, 'nurse', null);
    $r = ask($m, 'spec', ['spec' => 'S-3'], '1', 'reg', 351, 'nurse', null);
    check('throttle: the next check is "unknown", marked throttled, with a reason (got ' . json_encode($r) . ')',
        ($r['unknown'] ?? null) === true && ($r['throttled'] ?? null) === true && ($r['why'] ?? null) === 'too many checks in the last minute'
        && !isset($r['used']) && !isset($r['error']));
    $r = ask($m, 'spec', ['spec' => 'S-NEW-0'], '1', 'reg', 351, 'nurse', null);
    check('throttle: a value asked before is answered while new ones are refused (got ' . json_encode($r) . ')',
        isset($r['used']) && !isset($r['throttled']));
    $_SESSION = [];
    for ($i = 0; $i < 3 * UniversalValidator::THROTTLE_SIGNED_IN; $i++) $r = ask($m, 'spec', ['spec' => 'S-3'], '1', 'reg', 351, 'nurse', null);
    check('throttle: one value asked again and again is not counted', isset($r['used']) && !isset($r['throttled']));

    // ---- 3b) the person who saved is told on the next page what the audit found ------
    $_SESSION = [];
    $DUP = $THR;
    $DUP['6'] = [351 => ['record_id' => '6', 'spec' => 'S-1']];
    $LABELLED = \REDCap::$dictionary;
    $LABELLED['spec']['field_label'] = '<b>Specimen</b>   ID <script>x()</script>';
    \REDCap::$dictionary = $LABELLED;
    $top = function ($m, $pid = 149) { ob_start(); $m->redcap_every_page_top($pid); return (string) ob_get_clean(); };
    $m = mk($DUP, 'nurse');
    check('after save: a page with nothing to say prints nothing', $top($m) === '');
    $m->redcap_save_record(149, '6', 'reg', 351, null, null, null, 1);
    check('after save: the duplicate is logged as before', count(logged($m, 'invalid-id-saved')) === 1);
    $html = $top($m);
    check('after save: the next page says the value is already recorded, naming the record saved and the field (got ' . $html . ')',
        strpos($html, 'id="uv-after-save-notice"') !== false && strpos($html, 'Record <b>6</b>') !== false
        && strpos($html, 'already recorded in another record') !== false && strpos($html, '<code>spec</code>') !== false);
    check('after save: the field label is shown as text, its tags removed', strpos($html, 'Specimen ID x()') !== false && strpos($html, '<script>') === false);
    check('after save: neither the value nor the other record is in the notice', strpos($html, 'S-1') === false && strpos($html, 'Record <b>1</b>') === false);
    check('after save: shown once', $top($m) === '');
    // A save with nothing wrong says nothing.
    $m = mk($DUP, 'nurse');
    $m->redcap_save_record(149, '2', 'reg', 351, null, null, null, 1);
    check('after save: a save the audit passes says nothing', $top($m) === '');
    // Not for a survey respondent, an import, or a save with no one signed in.
    $m = mk($DUP, 'nurse');
    $m->redcap_save_record(149, '6', 'reg', 351, null, 'hash', null, 1);
    check('after save: a survey save keeps nothing for the next page', $_SESSION === [] || !isset($_SESSION['uvalidate_after_save_149']));
    $m = mk($DUP, 'nurse');
    $m->redcap_save_record(149, '6', null, 351, null, null, null, 1);
    check('after save: a save with no instrument (an import) keeps nothing', !isset($_SESSION['uvalidate_after_save_149']));
    $m = mk($DUP, null);
    $m->redcap_save_record(149, '6', 'reg', 351, null, null, null, 1);
    check('after save: a save with no one signed in keeps nothing', !isset($_SESSION['uvalidate_after_save_149']));
    // The project setting turns it off.
    $m = mk($DUP, 'nurse');
    $m->projectSettings['after-save-notice-off'] = true;
    $m->redcap_save_record(149, '6', 'reg', 351, null, null, null, 1);
    check('after save: "after-save-notice-off" keeps nothing, and the log entry is still written',
        !isset($_SESSION['uvalidate_after_save_149']) && count(logged($m, 'invalid-id-saved')) === 1);
    // Kept per project, for a limited time, and no more than the cap.
    $m = mk($DUP, 'nurse');
    $m->redcap_save_record(149, '6', 'reg', 351, null, null, null, 1);
    check('after save: another project\'s page does not show it', $top($m, 150) === '' && isset($_SESSION['uvalidate_after_save_149']));
    check('after save: no project, nothing', $top($m, null) === '');
    $_SESSION['uvalidate_after_save_149'][0]['t'] -= UniversalValidator::AFTER_SAVE_SECONDS;
    check('after save: a finding older than the kept time is dropped', $top($m) === '' && !isset($_SESSION['uvalidate_after_save_149']));
    for ($i = 0; $i < UniversalValidator::AFTER_SAVE_MAX + 5; $i++) $m->redcap_save_record(149, '6', 'reg', 351, null, null, null, 1);
    check('after save: no more than ' . UniversalValidator::AFTER_SAVE_MAX . ' findings are kept',
        count($_SESSION['uvalidate_after_save_149']) === UniversalValidator::AFTER_SAVE_MAX);
    $_SESSION['uvalidate_after_save_149'] = [['record' => '<i>9</i>', 'field' => 'spec', 'type' => 'unique', 'reason' => 'duplicate-value', 't' => time()],
        ['record' => '9', 'field' => 'spec', 'type' => 'unique', 'reason' => 'made-up', 't' => time()], 'text', ['record' => '9']];
    $html = $top($m);
    check('after save: what is shown is escaped, and an entry of another shape is left out (got ' . $html . ')',
        strpos($html, '&lt;i&gt;9&lt;/i&gt;') !== false && substr_count($html, '<li>') === 1);
    $GLOBALS['__TEST_USER'] = null;
    $_SESSION['uvalidate_after_save_149'] = [['record' => '9', 'field' => 'spec', 'type' => 'unique', 'reason' => 'duplicate-value', 't' => time()]];
    check('after save: a page with no one signed in shows nothing', $top($m) === '');
    $_SESSION = [];
    \REDCap::$dictionary = ['record_id' => uvf('reg'), 'spec' => uvf('reg', '@UVUNIQUE')];

    // ---- 4) PRF-1: the unique rules of one save share one read --------------------------
    $_SESSION = [];   // the signed-in throttle counts per session
    \REDCap::$rights = ['nurse' => ['forms' => ['reg' => '1']]];
    \REDCap::$dictionary = [
        'record_id' => uvf('reg'),
        'site'      => uvf('reg'),
        'u_plain'   => uvf('reg', '@UVUNIQUE'),
        'u_with'    => uvf('reg', '@UVUNIQUE={"with":["site"]}'),
        'u_also'    => uvf('reg', '@UVUNIQUE={"also":["scan_id"]}'),
        'u_case'    => uvf('reg', '@UVUNIQUE={"caseSensitive":true}'),
        'scan_id'   => uvf('reg'),
    ];
    $SH = [
        '1' => [351 => ['record_id' => '1', 'site' => '1', 'u_plain' => 'P1', 'u_with' => 'W1', 'u_also' => 'A1', 'u_case' => 'abc', 'scan_id' => '']],
        '2' => [351 => ['record_id' => '2', 'site' => '2', 'u_plain' => 'P2', 'u_with' => 'W1', 'u_also' => 'A2', 'u_case' => 'ABC', 'scan_id' => 'A9']],
        '3' => [351 => ['record_id' => '3', 'site' => '1', 'u_plain' => ' p1 ', 'u_with' => 'W1', 'u_also' => 'A9', 'u_case' => 'abc', 'scan_id' => '']],
    ];
    $m = mk($SH, 'nurse');
    $m->redcap_save_record(149, '3', 'reg', 351, null, null, null, 1);
    $bad = array_map(function ($e) { return $e['field']; }, logged($m, 'invalid-id-saved'));
    sort($bad);
    check('shared read: one full read for four unique fields, or none with the data table (got ' . json_encode(\REDCap::$calls) . ')',
        \REDCap::$calls['full'] === ($TABLE ? 0 : 1));
    check('shared read: the verdicts separate reads gave (got ' . json_encode($bad) . ')',
        $bad === ['u_also', 'u_case', 'u_plain', 'u_with']);
    $m = mk($SH, 'nurse');
    $m->redcap_save_record(149, '2', 'reg', 351, null, null, null, 1);
    $bad = array_map(function ($e) { return $e['field']; }, logged($m, 'invalid-id-saved'));
    sort($bad);
    check('shared read: record 2 (W1 at site 2, ABC with case counting) is no duplicate (got ' . json_encode($bad) . ')', $bad === []);

    // the shared read ends with the save: a later live check reads on its own
    $m = mk($SH, 'nurse');
    $m->redcap_save_record(149, '3', 'reg', 351, null, null, null, 1);
    \REDCap::$data['4'] = [351 => ['record_id' => '4', 'u_plain' => 'NEW-1']];
    $r = ask($m, 'u_plain', ['u_plain' => 'NEW-1'], '5', 'reg', 351, 'nurse', null);
    check('shared read: dropped after the save (a value saved since is found)', ($r['used'] ?? null) === true && ($r['record'] ?? null) === '4');

    // ---- UNQ-6: a new record's tentative id is not "this record" ------------------
    // Auto-numbering drew the page as record 5. Another user saved record 5
    // with the same value meanwhile; REDCap will save this page as record 6.
    \REDCap::$dictionary = ['record_id' => uvf('reg'), 'u_plain' => uvf('reg', '@UVUNIQUE')];
    $NEW = [
        '1' => [351 => ['record_id' => '1', 'u_plain' => 'OLD-1']],
        '5' => [351 => ['record_id' => '5', 'u_plain' => 'RACE-1']],
    ];
    $askNew = function ($m, array $extra) {
        $GLOBALS['__TEST_USER'] = 'nurse';
        return $m->redcap_module_ajax('unique-check', ['field' => 'u_plain', 'values' => ['u_plain' => 'RACE-1']] + $extra,
            149, '5', 'reg', 351, 1, null, null, null, '', '', 'nurse', null);
    };
    $r = $askNew(mk($NEW, 'nurse'), ['newRecord' => true]);
    check('UNQ-6: a page drawn as new compares with the record saved under its tentative id (got ' . json_encode($r) . ')',
        ($r['used'] ?? null) === true && ($r['record'] ?? null) === '5');
    $r = $askNew(mk($NEW, 'nurse'), ['newRecord' => '1']);
    check('UNQ-6: the flag sent as a form string counts too', ($r['used'] ?? null) === true);
    $r = $askNew(mk($NEW, 'nurse'), []);
    check('UNQ-6: with no flag the record is left out, as before (an older page)', ($r['used'] ?? null) === false);
    $r = $askNew(mk($NEW, 'nurse'), ['newRecord' => false]);
    check('UNQ-6: a false flag leaves the record out', ($r['used'] ?? null) === false);

    // The page says whether the record existed when it was drawn.
    $pageOf = function ($m, $record) {
        ob_start();
        $m->redcap_data_entry_form_top(149, $record, 'reg', 351, null, 1);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return json_decode($mm[1] ?? 'null', true);
    };
    $cfg = $pageOf(mk($NEW, 'nurse'), '7');
    check('UNQ-6: a page for a record not saved yet carries newRecord (got ' . json_encode($cfg['newRecord'] ?? null) . ')',
        is_array($cfg) && ($cfg['newRecord'] ?? null) === true);
    $cfg = $pageOf(mk($NEW, 'nurse'), '1');
    check('UNQ-6: a page for a saved record does not', is_array($cfg) && !array_key_exists('newRecord', $cfg));
    \REDCap::$dictionary = ['record_id' => uvf('reg'), 'u_plain' => uvf('reg', '@UVREQUIRED')];
    $cfg = $pageOf(mk($NEW, 'nurse'), '7');
    check('UNQ-6: nor a page with no uniqueness rule (no read is made for it)',
        is_array($cfg) && !array_key_exists('newRecord', $cfg) && \REDCap::$calls['record'] === 0);

    // ---- UNQ-7, PRF-2: the data table finds the records, getData builds only those --
    \REDCap::$dictionary = [
        'record_id' => uvf('reg'),
        'site'      => uvf('reg'),
        'u_plain'   => uvf('reg', '@UVUNIQUE'),
        'u_num'     => ['field_type' => 'text', 'form_name' => 'reg', 'identifier' => '',
                        'field_annotation' => '@UVUNIQUE', 'select_choices_or_calculations' => '',
                        'text_validation_type_or_show_slider_number' => 'integer'],
        'u_also'    => uvf('reg', '@UVUNIQUE={"also":["scan_id"]}'),
        'u_ev'      => uvf('reg', '@UVUNIQUE={"scope":"event"}'),
        'scan_id'   => uvf('reg'),
    ];
    $TB = [
        '1' => [351 => ['record_id' => '1', 'u_plain' => 'T-1', 'u_num' => '007', 'u_also' => '', 'scan_id' => 'SC-9', 'u_ev' => 'E-1']],
        '2' => [351 => ['record_id' => '2', 'u_plain' => ' t-2 ', 'u_num' => '8', 'u_also' => 'A-2', 'scan_id' => '']],
        '3' => [351 => ['record_id' => '3', 'u_plain' => 'OTHER', 'u_num' => '9'], 352 => ['u_ev' => 'E-2']],
    ];
    \REDCap::$events = [351 => 'enrolment_arm_1', 352 => 'visit_arm_1'];
    $m = mk($TB, 'nurse');
    $r = ask($m, 'u_plain', ['u_plain' => 'NEW-7'], '9', 'reg', 351, 'nurse', null);
    check('table: a miss is "free"', ($r['used'] ?? null) === false);
    check('table: ...and builds no record at all (got ' . json_encode(\REDCap::$calls) . ')',
        $TABLE ? \REDCap::$calls === ['full' => 0, 'filtered' => 0, 'record' => 0] : \REDCap::$calls['full'] === 1);
    $m = mk($TB, 'nurse');
    $r = ask($m, 'u_plain', ['u_plain' => 'T-2'], '9', 'reg', 351, 'nurse', null);
    check('table: a saved " t-2 " is found for "T-2" (trimmed, case ignored)', ($r['used'] ?? null) === true && ($r['record'] ?? null) === '2');
    check('table: ...building record 2 only (got ' . json_encode(\REDCap::$recordReads) . ')',
        !$TABLE || (\REDCap::$recordReads === [['2']] && \REDCap::$calls['full'] === 0 && \REDCap::$calls['filtered'] === 0));
    $r = ask(mk($TB, 'nurse'), 'u_num', ['u_num' => '7'], '9', 'reg', 351, 'nurse', null);
    check('table: in an integer field "7" finds the saved "007"', ($r['used'] ?? null) === true && ($r['record'] ?? null) === '1');
    $r = ask(mk($TB, 'nurse'), 'u_also', ['u_also' => 'sc-9'], '9', 'reg', 351, 'nurse', null);
    check('table: an "also" field is searched too', ($r['used'] ?? null) === true && ($r['record'] ?? null) === '1' && ($r['field'] ?? null) === 'scan_id');
    $m = mk($TB, 'nurse');
    $r = ask($m, 'u_plain', ['u_plain' => 'T-1'], '1', 'reg', 351, 'nurse', null);
    check('table: the record being checked is not compared with itself', ($r['used'] ?? null) === false);
    check('table: ...and is not even built', !$TABLE || \REDCap::$recordReads === []);
    $m = mk($TB, 'nurse');
    $r = ask($m, 'u_ev', ['u_ev' => 'E-2'], '9', 'reg', 351, 'nurse', null);
    check('table: under "scope":"event", a value saved in another event is no duplicate', ($r['used'] ?? null) === false);
    check('table: ...and the table is read for this event only',
        !$TABLE || ($INDEX
            ? $GLOBALS['__TABLE_READS'] === [] && count($GLOBALS['__AMONG_READS']) === 1 && strpos($GLOBALS['__AMONG_READS'][0], 'AND event_id = ?') !== false
            : count($GLOBALS['__TABLE_READS']) === 1 && strpos($GLOBALS['__TABLE_READS'][0], 'AND event_id = ?') !== false));
    check('table: ...and with the lookup index the field is never read whole', !$INDEX || $GLOBALS['__TABLE_READS'] === []);
    $r = ask(mk($TB, 'nurse'), 'u_ev', ['u_ev' => 'E-2'], '9', 'reg', 352, 'nurse', null);
    check('table: ...while in its own event it is one', ($r['used'] ?? null) === true && ($r['record'] ?? null) === '3');
    // The save audit takes the same road: no export of the project.
    $TB['4'] = [351 => ['record_id' => '4', 'u_plain' => 't-1']];
    $m = mk($TB, 'nurse');
    $m->redcap_save_record(149, '4', 'reg', 351, null, null, null, 1);
    $bad = array_map(function ($e) { return $e['field'] . '@' . ($e['record'] ?? ''); }, logged($m, 'invalid-id-saved'));
    check('table: the save audit finds the duplicate (got ' . json_encode($bad) . ')', in_array('u_plain@4', $bad, true));
    check('table: ...with no export of the project (got ' . json_encode(\REDCap::$calls) . ')', !$TABLE || \REDCap::$calls['full'] === 0);
    \REDCap::$events = [351 => 'enrolment_arm_1'];

    // ---- 6) a value another Data Access Group holds (UNQ-2 of 2026-11-13) --------------
    // REDCap hands a user in a group that group's records only (__CONFINE).
    // Uniqueness is a fact of the project: the value is a duplicate, and the
    // record that holds it is named to nobody.
    \REDCap::$groups = [11 => 'site_a', 12 => 'site_b'];
    \REDCap::$rights = ['coord' => ['forms' => ['reg' => '1', 'lab' => '1']],
                        'alice' => ['forms' => ['reg' => '1', 'lab' => '1'], 'group_id' => 11]];
    \REDCap::$dictionary = ['record_id' => uvf('reg'), 'spec' => uvf('reg', '@UVUNIQUE={"blockSave":"hard"}')];
    $X = [
        '1' => [351 => ['record_id' => '1', 'spec' => 'A1', 'redcap_data_access_group' => 'site_a']],
        '2' => [351 => ['record_id' => '2', 'spec' => 'B7', 'redcap_data_access_group' => 'site_b']],
        '3' => [351 => ['record_id' => '3', 'spec' => 'C3', 'redcap_data_access_group' => 'site_a']],
    ];
    $GLOBALS['__CONFINE'] = 'site_a';
    $GLOBALS['__RAW_READS'] = [];
    $r = ask(mk($X, 'alice'), 'spec', ['spec' => 'B7'], '1', 'reg', 351, 'alice', 11);
    if ($TABLE) {
        check('other group: a value site_b holds is "already used" for a user of site_a (got ' . json_encode($r) . ')',
            ($r['used'] ?? null) === true);
        check('other group: ...and the record is not named', array_key_exists('record', $r) && $r['record'] === null && !isset($r['field']));
        check('other group: ...read from the rows of that one record', count($GLOBALS['__RAW_READS']) === 2);
    } else {
        check('other group, no data table: a miss on a confined export is "could not check" (got ' . json_encode($r) . ')',
            ($r['unknown'] ?? null) === true && !array_key_exists('used', $r));
    }
    $r = ask(mk($X, 'alice'), 'spec', ['spec' => 'c3'], '1', 'reg', 351, 'alice', 11);
    check('other group: a value of the user\'s own group is used, and its record named',
        ($r['used'] ?? null) === true && ($r['record'] ?? null) === '3');
    $r = ask(mk($X, 'alice'), 'spec', ['spec' => 'Z9'], '1', 'reg', 351, 'alice', 11);
    check('other group: a value nobody holds is free (got ' . json_encode($r) . ')',
        $TABLE ? ($r['used'] ?? null) === false : ($r['unknown'] ?? null) === true);
    $X2 = $X;
    $X2['1'][351]['spec'] = 'B7';
    $m = mk($X2, 'alice');
    $m->redcap_save_record(149, '1', 'reg', 351, 11, null, null, 1);
    if ($TABLE) {
        $rows = logged($m, 'invalid-id-saved');
        check('other group: the save audit logs the duplicate (got ' . count($rows) . ')', count($rows) === 1);
        check('other group: ...about the saved record only', strpos(json_encode($m->logCalls), '"2"') === false);
    } else {
        check('other group, no data table: the audit says it could not compare, and logs no pass',
            logged($m, 'invalid-id-saved') === [] && strpos(json_encode(logged($m, 'uvalidate-unconfigurable')), 'could not be read') !== false);
    }
    // A user in no group is handed every record, and told which one.
    $GLOBALS['__CONFINE'] = null;
    $r = ask(mk($X, 'coord'), 'spec', ['spec' => 'B7'], '1', 'reg', 351, 'coord', null);
    check('no group: the record is named as before', ($r['used'] ?? null) === true && ($r['record'] ?? null) === '2');
    $GLOBALS['__CONFINE'] = 'site_a';

    // "scope":"dag" looks in the record's own group only.
    \REDCap::$dictionary['spec'] = uvf('reg', '@UVUNIQUE={"scope":"dag"}');
    $r = ask(mk($X, 'alice'), 'spec', ['spec' => 'B7'], '1', 'reg', 351, 'alice', 11);
    check('other group, "scope":"dag": the value of site_b is no duplicate in site_a (got ' . json_encode($r) . ')', ($r['used'] ?? null) === false);

    // An "also" field of a record in another group: used, the field not named.
    \REDCap::$dictionary = ['record_id' => uvf('reg'), 'scan_id' => uvf('reg'), 'spec' => uvf('reg', '@UVUNIQUE={"also":["scan_id"]}')];
    $XA = $X;
    $XA['2'][351]['scan_id'] = 'K-5';
    $r = ask(mk($XA, 'alice'), 'spec', ['spec' => 'k-5'], '1', 'reg', 351, 'alice', 11);
    check('other group, "also": used, with neither record nor field (got ' . json_encode($r) . ')',
        $TABLE ? (($r['used'] ?? null) === true && $r['record'] === null && !isset($r['field'])) : ($r['unknown'] ?? null) === true);

    // A composite key: the other group's record is compared entry by entry.
    \REDCap::$dictionary = ['record_id' => uvf('reg'), 'site' => uvf('reg'), 'spec' => uvf('reg', '@UVUNIQUE={"with":["site"]}')];
    \REDCap::$repeating = [];
    $XW = [
        '1' => [351 => ['record_id' => '1', 'spec' => 'A1', 'site' => '1', 'redcap_data_access_group' => 'site_a']],
        '2' => [351 => ['record_id' => '2', 'spec' => 'B7', 'site' => '2', 'redcap_data_access_group' => 'site_b']],
    ];
    if ($TABLE) {
        $r = ask(mk($XW, 'alice'), 'spec', ['spec' => 'B7', 'site' => '2'], '1', 'reg', 351, 'alice', 11);
        check('other group, "with": the same value and site is used (got ' . json_encode($r) . ')', ($r['used'] ?? null) === true && $r['record'] === null);
        $r = ask(mk($XW, 'alice'), 'spec', ['spec' => 'B7', 'site' => '1'], '1', 'reg', 351, 'alice', 11);
        check('other group, "with": the same value at another site is free (got ' . json_encode($r) . ')', ($r['used'] ?? null) === false);
        // the tagged field on a repeating instrument: each instance over the event's row
        \REDCap::$dictionary = ['record_id' => uvf('reg'), 'site' => uvf('reg'), 'spec' => uvf('lab', '@UVUNIQUE={"with":["site"]}')];
        \REDCap::$repeating = [351 => ['lab' => '']];
        $XR = [
            '1' => [351 => ['record_id' => '1', 'site' => '1', 'redcap_data_access_group' => 'site_a']],
            '2' => [351 => ['record_id' => '2', 'site' => '2', 'redcap_data_access_group' => 'site_b'],
                    'repeat_instances' => [351 => ['lab' => [1 => ['spec' => 'R1'], 2 => ['spec' => 'R2']]]]],
        ];
        $r = ask(mk($XR, 'alice'), 'spec', ['spec' => 'R2', 'site' => '2'], '1', 'lab', 351, 'alice', 11);
        check('other group, "with", repeating instrument: instance 2 at the same site is used (got ' . json_encode($r) . ')', ($r['used'] ?? null) === true);
        $r = ask(mk($XR, 'alice'), 'spec', ['spec' => 'R2', 'site' => '1'], '1', 'lab', 351, 'alice', 11);
        check('other group, "with", repeating instrument: at another site it is free', ($r['used'] ?? null) === false);
        // the layout cannot be read: not settled, never "free"
        \REDCap::$repeating = null;
        $r = ask(mk($XR, 'alice'), 'spec', ['spec' => 'R2', 'site' => '2'], '1', 'lab', 351, 'alice', 11);
        check('other group, "with", layout unread: "could not check" (got ' . json_encode($r) . ')', ($r['unknown'] ?? null) === true);
    }
    \REDCap::$repeating = null;
    $GLOBALS['__CONFINE'] = null;
    \REDCap::$groups = [];

    // ---- 7) a survey lookup asks its read budget before any read (UNQ-5 of 2026-11-13) -
    // With the lookup index unable to answer, 600 requests a minute each made
    // a filtered export before the budget of 60 was asked. Now the budget is
    // asked first and the field is read from the data table.
    \REDCap::$rights = [];
    \REDCap::$dictionary = ['record_id' => uvf('reg'), 'tok' => uvf('reg', '@UVUNIQUE={"surveys":true,"blockSave":"hard"}')];
    $SV = [];
    for ($i = 1; $i <= 5; $i++) $SV[(string) $i] = [351 => ['record_id' => (string) $i, 'tok' => 'T' . $i]];
    $sask = function ($m, $value) {
        $GLOBALS['__TEST_USER'] = null;
        return $m->redcap_module_ajax('unique-check', ['field' => 'tok', 'values' => ['tok' => $value]],
            149, null, 'reg', 351, 1, 'HASH', null, null, '', '', null, null);
    };
    $readSlot = '149|' . (((int) floor(time() / 60)) * UniversalValidator::RATE_TIERS + 2);
    $_SESSION = [];
    if ($TABLE && !$INDEX) {
        \ExternalModules\AbstractExternalModule::$rateBuckets = [];
        $m = mk($SV, null);
        $r = $sask($m, 't2');
        check('survey: a saved value is "already used" (got ' . json_encode($r) . ')', $r === ['used' => true, 'record' => null]);
        check('survey: ...from the data table, with no filtered export',
            \REDCap::$calls['filtered'] === 0 && \REDCap::$calls['full'] === 0 && count($GLOBALS['__TABLE_READS']) === 1);
        check('survey: ...and the read budget was asked for it',
            (\ExternalModules\AbstractExternalModule::$rateBuckets[$readSlot] ?? 0) === 1);
        \ExternalModules\AbstractExternalModule::$rateBuckets = [$readSlot => UniversalValidator::THROTTLE_SURVEY_FULL_READS];
        foreach (['t2', 'NOPE'] as $v) {
            $m = mk($SV, null);
            $r = $sask($m, $v);
            check('survey, budget spent: ' . $v . ' is "not known", and nothing is read (got ' . json_encode($r) . ')',
                $r === ['unknown' => true, 'record' => null] && \REDCap::$calls === ['full' => 0, 'filtered' => 0, 'record' => 0]
                && $GLOBALS['__TABLE_READS'] === []);
        }
        // a field too large to read whole: a survey does not read it either
        \ExternalModules\AbstractExternalModule::$rateBuckets = [];
        $GLOBALS['__FIELD_ROWS'] = UniversalValidator::LIVE_WHOLE_READ_ROWS + 1;
        $m = mk($SV, null);
        $r = $sask($m, 't2');
        check('survey, large field: "not known", the field is not read and nothing is exported (got ' . json_encode($r) . ')',
            $r === ['unknown' => true, 'record' => null] && $GLOBALS['__TABLE_READS'] === []
            && \REDCap::$calls === ['full' => 0, 'filtered' => 0, 'record' => 0]);
        unset($GLOBALS['__FIELD_ROWS']);
    }
    if ($INDEX) {
        // The index answers outside the budget: it reads the records it names.
        \ExternalModules\AbstractExternalModule::$rateBuckets = [$readSlot => UniversalValidator::THROTTLE_SURVEY_FULL_READS];
        $r = $sask(mk($SV, null), 't2');
        check('survey, index built: answered with the budget spent', $r === ['used' => true, 'record' => null]);
        $r = $sask(mk($SV, null), 'NOPE');
        check('survey, index built: a miss too', $r === ['used' => false, 'record' => null]);
    }
    \ExternalModules\AbstractExternalModule::$rateBuckets = [];

    echo "unique_scope_php: $n checks, $fail failure(s)" . ($TABLE ? ($INDEX ? ' through the lookup index' : '') : ' without the data table') . "\n";
    if ($TABLE && !$INDEX) {
        foreach (['--no-table', '--index'] as $mode) {
            passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $mode, $rc);
            if ($rc !== 0) $fail++;
        }
    }
    exit($fail ? 1 : 0);
}
