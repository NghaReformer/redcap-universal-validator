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
        public static function getData($p) {
            $filtered = isset($p['filterLogic']) && $p['filterLogic'] !== '';
            if ($filtered) self::$calls['filtered']++;
            elseif (!empty($p['records'])) self::$calls['record']++;
            else self::$calls['full']++;
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
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';

    use INSPIRE\UniversalValidator\UniversalValidator;

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
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
        $m = new UniversalValidator();
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
        \REDCap::$calls['filtered'] === 1 && \REDCap::$calls['full'] === 0);

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
        ($r['unknown'] ?? null) === true && ($r['throttled'] ?? null) === true && strpos((string) ($r['why'] ?? ''), 'wait a moment') !== false
        && !isset($r['used']) && !isset($r['error']));

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
    check('shared read: one full read for four unique fields (got ' . json_encode(\REDCap::$calls) . ')', \REDCap::$calls['full'] === 1);
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

    echo "unique_scope_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
