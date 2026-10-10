<?php
/**
 * exists_php.php — @UVEXISTS on the server (2.3.0).
 *
 * Pins:
 *   - the grammar and the dictionary hooks (what a lookup may name),
 *   - the page config: where the rule looks never reaches the browser,
 *   - the exists-check endpoint: found / not-found / unknown, exact and
 *     trimmed comparison of stored values, dates typed as shown, a narrowed
 *     miss confirmed by a full read, every failure answering unknown, the
 *     survey opt-in with its Identifier refusal (fail closed), the signed-in
 *     throttle and form-rights gate, the DAG-masked record echo,
 *   - the post-save audit: not-found logged as type exists, an unreadable
 *     lookup reported as a rule problem and never as a pass,
 *   - the scan: one read of the searched field per rule and request, and a
 *     group-confined scan reporting a cross-group rule as not evaluated.
 *   - the 2.3.0 review fixes: the page's branch values pick the branch, a
 *     DAG-confined read never answers "not found", exact storage kinds, the
 *     durable scan's coverage, the index's size, the caller's record must be
 *     in their group, the survey whole-field read budget, survey-safe errors,
 *     scan mode carried per call, fields the page leaves out read as saved,
 *     and the reverse dependencies of "match" fields.
 *
 * Run:  php tests/exists_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $logCalls = [];
        public $subSettings = [];
        public $projectSettings = [];
        public $systemSettings = [];
        public $projectIdReturn = null;
        public function getSubSettings($k, $pid = null) {
            $e = ($pid !== null && $pid !== '') ? $pid : $this->projectIdReturn;
            return $e ? $this->subSettings : [];
        }
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
        /** The rate-bucket table only, as in hook_php.php. */
        public $rateBuckets = [];
        public $lastInsertId = 0;
        public $queryThrows = false;
        public function query($sql, $params = []) {
            if ($this->queryThrows) throw new \RuntimeException('no table');
            if (strpos($sql, 'SELECT LAST_INSERT_ID()') !== false) return [[$this->lastInsertId]];
            if (strpos($sql, 'SELECT ROW_COUNT()') !== false) return [[1]];
            if (strpos($sql, 'uv_rate_bucket') !== false && strpos($sql, 'INSERT') === 0) {
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
        public function getRights($pid = null) {
            $all = \REDCap::$rights;
            return isset($all[$this->n]) ? $all[$this->n] : null;
        }
    }
}

namespace {
    /**
     * getData honours project_id, records, events and a simple filterLogic
     * ("[f] = 'v' and ..."). $narrowLies makes a FILTERED read wrongly come back
     * empty (a build whose filter misses a stored value); $failFull makes the
     * unfiltered read throw; $failAll makes every read throw.
     */
    class REDCap {
        public static $data = [];
        public static $dictionary = [];
        public static $rights = [];
        public static $events = [351 => 'enrolment_arm_1', 352 => 'visit_arm_1'];
        public static $groups = [7 => 'north', 8 => 'south'];
        public static $calls = [];
        public static $narrowLies = false;
        public static $failFull = false;
        public static $failAll = false;
        /** A build that hands back every event although one was asked for. */
        public static $ignoreEvents = false;
        /** Reads confined to one Data Access Group, as for a user in that group. */
        public static $confineTo = null;
        public static function reset() {
            self::$calls = [];
            self::$narrowLies = self::$failFull = self::$failAll = self::$ignoreEvents = false;
            self::$confineTo = null;
        }
        private static function dagOf(array $node) {
            foreach ($node as $k => $v) {
                if ($k !== 'repeat_instances' && is_array($v) && isset($v['redcap_data_access_group'])) return $v['redcap_data_access_group'];
            }
            return '';
        }
        public static function getData($p) {
            self::$calls[] = $p;
            if ((int) ($p['project_id'] ?? 0) !== 149) throw new \RuntimeException('wrong project');
            if (self::$failAll) throw new \RuntimeException('simulated failure value=SECRET');
            $filtered = isset($p['filterLogic']) && $p['filterLogic'] !== '';
            if ($filtered && self::$narrowLies) return [];
            if (!$filtered && self::$failFull && empty($p['records'])) throw new \RuntimeException('simulated full-read failure');
            $out = [];
            foreach (self::$data as $rec => $node) {
                if (self::$confineTo !== null && self::dagOf($node) !== self::$confineTo) continue;
                if (!empty($p['records']) && !in_array((string) $rec, array_map('strval', $p['records']), true)) continue;
                if (!empty($p['events']) && !self::$ignoreEvents) {
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
                if ($filtered && !self::matchesFilter($node, $p['filterLogic'])) continue;
                $out[$rec] = $node;
            }
            return $out;
        }
        private static function matchesFilter(array $node, $logic) {
            preg_match_all("/\\[([a-z0-9_]+)\\] = '([^']*)'/", $logic, $mm, PREG_SET_ORDER);
            $rows = [];
            foreach ($node as $k => $v) {
                if ($k === 'repeat_instances') {
                    foreach ($v as $ev => $byForm) foreach ($byForm as $byInst) foreach ($byInst as $row) {
                        $rows[] = array_merge(isset($node[$ev]) ? $node[$ev] : [], $row);
                    }
                } else {
                    $rows[] = $v;
                }
            }
            foreach ($rows as $row) {
                $ok = true;
                foreach ($mm as $c) if (!isset($row[$c[1]]) || (string) $row[$c[1]] !== $c[2]) { $ok = false; break; }
                if ($ok) return true;
            }
            return false;
        }
        public static function getDataDictionary($pid, $f = 'array') {
            if (!$pid) throw new \RuntimeException('needs pid');
            return self::$dictionary;
        }
        public static function getEventNames($unique = false, $assoc = false, $id = null) { return self::$events; }
        public static function getUserRights($u = null) { return self::$rights; }
        public static function getGroupNames($unique = false, $g = null) { return isset(self::$groups[$g]) ? self::$groups[$g] : ''; }
        public static function getRecordIdField() { return 'record_id'; }
    }

    require_once __DIR__ . '/../UniversalValidator.php';
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';

    use INSPIRE\UniversalValidator\AnnotationRules;
    use INSPIRE\UniversalValidator\ModeRegistry;

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    function f($form, $ann = '', $validation = '', $type = 'text', $ident = '') {
        return ['field_type' => $type, 'form_name' => $form, 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => $validation, 'identifier' => $ident,
                'select_choices_or_calculations' => in_array($type, ['radio', 'dropdown', 'checkbox'], true) ? '1, A | 2, B' : ''];
    }
    $DICT = [
        'record_id'   => f('enrol'),
        'home_site'   => f('enrol'),
        'nat_id'      => f('enrol', '', '', 'text', 'y'),
        'specimen_id' => f('lab_reg'),
        'site_code'   => f('lab_reg'),
        'spec_date'   => f('lab_reg', '', 'date_ymd'),
        'spec_tick'   => f('lab_reg', '', '', 'checkbox'),
        'res_site'    => f('result'),
        'res_spec'    => f('result', '@UVEXISTS=[specimen_id]'),
        'res_rec'     => f('result', '@UVEXISTS=record'),
        'res_m'       => f('result', '@UVEXISTS={"in":"[specimen_id]","match":{"site_code":"[res_site]"},"blockSave":"hard"}'),
        'res_off'     => f('result', '@UVEXISTS={"in":"[specimen_id]","match":{"site_code":"[home_site]"}}'),
        'res_s'       => f('result', '@UVEXISTS={"in":"[specimen_id]","surveys":true}'),
        'res_ev'      => f('result', '@UVEXISTS={"in":"[specimen_id]","event":"enrolment_arm_1"}'),
        'res_dag'     => f('result', '@UVEXISTS={"in":"[specimen_id]","scope":"dag"}'),
        'res_sev'     => f('result', '@UVEXISTS={"in":"[specimen_id]","scope":"event"}'),
        'res_date'    => f('result', '@UVEXISTS=[spec_date]', 'date_dmy'),
        'res_drop'    => f('result', '@UVEXISTS=[site_code]', '', 'dropdown'),
        'bad_ident'   => f('result', '@UVEXISTS={"in":"[nat_id]","surveys":true}'),
        'bad_recid'   => f('result', '@UVEXISTS={"in":"record","surveys":true}'),
        'bad_self'    => f('result', '@UVEXISTS=[bad_self]'),
        'bad_nofield' => f('result', '@UVEXISTS=[nope]'),
        'bad_multi'   => f('result', '@UVEXISTS=[spec_tick]'),
        'bad_family'  => f('result', '@UVEXISTS=[spec_date]'),
        'bad_event'   => f('result', '@UVEXISTS={"in":"[specimen_id]","event":"nowhere_arm_1"}'),
        'bad_local'   => f('result', '@UVEXISTS={"in":"[specimen_id]","match":{"site_code":"[bad_local]"}}'),
        'bad_notes'   => f('result', '@UVEXISTS=[specimen_id]', '', 'notes'),
        'res_uniq'    => f('result', '@UVUNIQUE'),
        'res_br'      => f('result', '@UVEXISTS={"in":"[specimen_id]","when":"[res_site]=\'A\'"} '
                                   . '@UVEXISTS={"in":"[site_code]","when":"[res_site]=\'B\'","blockSave":"hard"}'),
        'bad_twice'   => f('result', '@UVEXISTS=[specimen_id] @UVEXISTS=[site_code]'),
        'res_both'    => f('result', '@UVEXISTS=[specimen_id] @UVUNIQUE'),
        'res_rec_dag' => f('result', '@UVEXISTS={"in":"record","scope":"dag"}'),
        'lab_uniq'    => f('lab_reg', '@UVUNIQUE'),
        'spec_dt'     => f('lab_reg', '', 'datetime_seconds_ymd'),
        'spec_tm'     => f('lab_reg', '', 'time_hh_mm_ss'),
        'bad_dt'      => f('result', '@UVEXISTS=[spec_dt]', 'datetime_ymd'),
        'bad_tm'      => f('result', '@UVEXISTS=[spec_tm]', 'time'),
        'res_dt'      => f('result', '@UVEXISTS=[spec_dt]', 'datetime_seconds_mdy'),
        'res_br2'     => f('result', '@UVEXISTS={"in":"[specimen_id]","when":"[home_site]=\'A\'"} '
                                   . '@UVEXISTS={"in":"[site_code]","when":"[home_site]=\'B\'"}'),
        'res_ubr'     => f('result', '@UVUNIQUE={"when":"[res_site]=\'A\'","blockSave":"hard"} '
                                   . '@UVUNIQUE={"when":"[res_site]=\'B\'"}'),
        'res_uw'      => f('result', '@UVUNIQUE={"with":["res_site"]}'),
    ];
    $DATA = [
        '1' => [351 => ['record_id' => '1', 'home_site' => 'A', 'specimen_id' => ' SP-1 ', 'site_code' => 'A',
                        'spec_date' => '2026-03-01', 'redcap_data_access_group' => 'north']],
        '2' => [351 => ['record_id' => '2', 'home_site' => 'B', 'specimen_id' => 'SP-2', 'site_code' => 'B',
                        'redcap_data_access_group' => 'south'],
                352 => ['specimen_id' => 'SP-3', 'site_code' => 'B', 'redcap_data_access_group' => 'south']],
        '3' => [351 => ['record_id' => '3', 'home_site' => 'C', 'redcap_data_access_group' => ''],
                'repeat_instances' => [351 => ['lab_reg' => [1 => ['specimen_id' => 'SP-9', 'site_code' => 'C']]]]],
    ];
    $FULL = ['nurse' => ['forms' => ['enrol' => '1', 'lab_reg' => '1', 'result' => '1']]];
    $NOLAB = ['nurse' => ['forms' => ['enrol' => '1', 'lab_reg' => '0', 'result' => '1']]];

    function mod($user = 'nurse', $rights = null, $dict = null) {
        global $DICT, $DATA, $FULL;
        $GLOBALS['__TEST_USER'] = $user;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectSettings = ['log-values' => 'raw'];
        $m->projectIdReturn = 149;
        \REDCap::$dictionary = $dict === null ? $DICT : $dict;
        \REDCap::$data = $DATA;
        \REDCap::$rights = $rights === null ? $FULL : $rights;
        \REDCap::reset();
        return $m;
    }
    function page($m, $ctx, $rec, $form) {
        ob_start();
        if ($ctx === 'survey') $m->redcap_survey_page_top(149, $rec, $form, 351, null, 'hash', null, 1);
        else $m->redcap_data_entry_form_top(149, $rec, $form, 351, null, 1);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return ['html' => $html, 'cfg' => json_decode(isset($mm[1]) ? $mm[1] : 'null', true)];
    }
    function ruleFor($p, $f) {
        foreach ((isset($p['cfg']['rules']) ? $p['cfg']['rules'] : []) as $r) {
            if (in_array($f, isset($r['fields']) ? $r['fields'] : [], true)) return $r;
        }
        return null;
    }
    function findings($m, $type = 'invalid-id-saved') {
        return array_values(array_map(function ($c) { return $c[1]; },
            array_filter($m->logCalls, function ($c) use ($type) { return $c[0] === $type; })));
    }
    /** Staff call: signed in as $GLOBALS['__TEST_USER'], on the result form of $record. */
    function ask($m, $field, array $values, $record = '1', $group = null, $event = 351) {
        return $m->redcap_module_ajax('exists-check', ['field' => $field, 'values' => $values],
            149, $record, 'result', $event, 1, null, null, null, '', '', $GLOBALS['__TEST_USER'], $group);
    }
    /** Survey call: no user, a survey hash. */
    function askSurvey($m, $field, array $values, $record = '1') {
        return $m->redcap_module_ajax('exists-check', ['field' => $field, 'values' => $values],
            149, $record, 'result', 351, 1, 'hash', null, null, '', '', null, null);
    }
    function fullReads() {
        return count(array_filter(\REDCap::$calls, function ($c) { return empty($c['records']) && empty($c['filterLogic']); }));
    }

    // ---- 1) grammar ------------------------------------------------------------
    $parse = function ($ann, array $opts = []) { $all = AnnotationRules::parseAllTags($ann, $opts); return $all ? $all[0] : null; };
    $err = function ($ann) use ($parse) { $r = $parse($ann); return isset($r['error']) ? $r['error'] : ''; };
    $r = $parse('@UVEXISTS=record');
    check('record shorthand', ($r['type'] ?? null) === 'exists' && $r['existsIn'] === 'record' && !isset($r['existsTargets']));
    $r = $parse('@UVEXISTS=[Specimen_ID]');
    check('field shorthand, lower-cased', ($r['existsIn'] ?? null) === 'specimen_id' && $r['existsTargets'] === ['specimen_id']);
    $r = $parse('@UVEXISTS={"in":"[specimen_id]","match":{"site_code":"[site]","arm":"[site]"},"scope":"DAG"}');
    check('JSON: match pairs, locals deduplicated, targets listed', ($r['existsMatch'] ?? null) === ['site_code' => 'site', 'arm' => 'site']
        && $r['existsLocal'] === ['site'] && $r['existsTargets'] === ['specimen_id', 'site_code', 'arm'] && $r['existsScope'] === 'dag');
    $r = $parse('@UVEXISTS={"in":"record","surveys":false}');
    check('"surveys":false is the default, not stored', !isset($r['existsSurveys']) && !isset($r['error']));
    check('blank value refused', strpos($err('@UVEXISTS='), 'needs to know where to look') !== false);
    check('two fields refused', strpos($err('@UVEXISTS=[a][b]'), 'is not a place to look') !== false);
    check('unknown key refused', strpos($err('@UVEXISTS={"in":"record","site":5}'), 'unknown @UVEXISTS option(s): site') !== false);
    check('"in" missing refused', strpos($err('@UVEXISTS={"scope":"dag"}'), 'needs "in"') !== false);
    check('bad scope refused', strpos($err('@UVEXISTS={"in":"[a]","scope":"site"}'), '"scope" must be project, dag or event') !== false);
    check('bad event name refused', strpos($err('@UVEXISTS={"in":"[a]","event":"Visit 1"}'), 'unique event name') !== false);
    check('event with scope event refused', strpos($err('@UVEXISTS={"in":"[a]","event":"v_arm_1","scope":"event"}'), 'cannot be combined') !== false);
    check('record with event refused', strpos($err('@UVEXISTS={"in":"record","event":"v_arm_1"}'), 'does not apply to "in":"record"') !== false);
    check('record with match refused', strpos($err('@UVEXISTS={"in":"record","match":{"a":"[b]"}}'), 'a record ID has nothing to match') !== false);
    check('match as a list refused', strpos($err('@UVEXISTS={"in":"[a]","match":["[b]"]}'), '"match" must be an object') !== false);
    check('match value not a field refused', strpos($err('@UVEXISTS={"in":"[a]","match":{"b":"c"}}'), 'must be one field reference') !== false);
    check('match target twice (any case) refused', strpos($err('@UVEXISTS={"in":"[a]","match":{"B":"[c]","b":"[d]"}}'), 'names the target "b" twice') !== false);
    check('match target equal to "in" refused', strpos($err('@UVEXISTS={"in":"[a]","match":{"a":"[c]"}}'), 'is the field named in "in"') !== false);
    check('more than 5 match pairs refused', strpos($err('@UVEXISTS={"in":"[a]","match":{"b":"[x]","c":"[x]","d":"[x]","e":"[x]","f":"[x]","g":"[x]"}}'), 'limited to 5') !== false);
    check('"surveys" as a string refused', strpos($err('@UVEXISTS={"in":"[a]","surveys":"true"}'), 'true or false (unquoted)') !== false);
    check('bad blockSave refused', $err('@UVEXISTS={"in":"[a]","blockSave":"always"}') !== '');
    check('qualified "when" refused even with the feature on',
        strpos($parse('@UVEXISTS={"in":"[a]","when":"[enrolment_arm_1][b]=\'1\'"}', ['qualified' => true])['error'] ?? '',
            'does not support event or instance references') !== false);

    // ---- 2) the dictionary hooks -----------------------------------------------
    $p = page(mod(), 'form', '1', 'result');
    $cerr = function ($f) use ($p) { $r = ruleFor($p, $f); return $r && isset($r['configError']) ? $r['configError'] : ''; };
    check('Identifier target with the survey opt-in: refused', strpos($cerr('bad_ident'), 'field "nat_id" is an Identifier') !== false);
    check('"in" itself refused', strpos($cerr('bad_self'), '"in" names this field itself') !== false);
    check('unknown "in" field refused', strpos($cerr('bad_nofield'), '"in" field "nope" is not a field') !== false);
    check('checkbox "in" field refused', strpos($cerr('bad_multi'), 'is a checkbox field') !== false);
    check('date searched from a plain text field refused', strpos($cerr('bad_family'), '"spec_date" holds dates and "bad_family" holds no date or time') !== false);
    check('datetime to the second searched from one to the minute: refused (never equal as stored)',
        strpos($cerr('bad_dt'), '"spec_dt" holds dates with a time to the second and "bad_dt" holds dates with a time to the minute') !== false);
    check('time to the second searched from one to the minute: refused',
        strpos($cerr('bad_tm'), '"spec_tm" holds times to the second and "bad_tm" holds times to the minute') !== false);
    check('same storage kind in another display order: allowed', $cerr('res_dt') === '');
    check('unknown event refused', strpos($cerr('bad_event'), '"event" "nowhere_arm_1" is not an event') !== false);
    check('match by the field itself refused', strpos($cerr('bad_local'), 'uses this field itself') !== false);
    check('notes field refused by eligibility', strpos($cerr('bad_notes'), 'does not support "notes" fields') !== false);
    check('every refusal names @UVEXISTS', strpos($cerr('bad_self'), '@UVEXISTS on "bad_self"') === 0);
    check('valid rules carry no error', $cerr('res_spec') === '' && $cerr('res_rec') === '' && $cerr('res_m') === ''
        && $cerr('res_date') === '' && $cerr('res_drop') === '' && $cerr('res_ev') === '');
    // 2b) record lookup on surveys when the record ID field is an Identifier
    $D2 = $DICT; $D2['record_id']['identifier'] = 'y';
    $p2 = page(mod('nurse', null, $D2), 'form', '1', 'result');
    $r2 = ruleFor($p2, 'bad_recid');
    check('record lookup + surveys: refused when the record ID is an Identifier',
        $r2 && strpos($r2['configError'] ?? '', 'field "record_id" is an Identifier') !== false);
    check('...and allowed when it is not', ($r = ruleFor($p, 'bad_recid')) && empty($r['configError']) && !empty($r['existsSurveys']));

    // ---- 3) the page: where the rule looks stays on the server -------------------
    $r = ruleFor($p, 'res_m');
    check('page rule keeps what the browser needs', $r && $r['existsLocal'] === ['res_site'] && $r['blockSave'] === 'hard');
    $leak = false;
    foreach ($p['cfg']['rules'] as $rr) {
        foreach (['existsIn', 'existsEvent', 'existsScope', 'existsMatch', 'existsTargets'] as $k) {
            if (array_key_exists($k, $rr)) $leak = true;
            foreach ((isset($rr['branches']) ? $rr['branches'] : []) as $b) if (array_key_exists($k, $b)) $leak = true;
        }
    }
    check('no server key reaches the page config', !$leak);
    check('the searched field name is not on the page', strpos($p['html'], 'specimen_id') === false);
    check('the transport is initialised for the lookup', strpos($p['html'], 'jsmoName') !== false);
    $r = ruleFor($p, 'res_br');
    check('branches: one rule per field, one branch per tag', $r && count($r['branches'] ?? []) === 2 && empty($r['configError']));
    check('branches: no server key in any branch', $r && !array_key_exists('existsIn', $r['branches'][0]) && !array_key_exists('existsIn', $r['branches'][1]));
    check('two ungated rules on one field are refused', strpos($cerr('bad_twice'), 'rules with no "when" condition') !== false);
    $both = array_values(array_filter($p['cfg']['rules'], function ($r) { return in_array('res_both', $r['fields'] ?? [], true); }));
    check('@UVEXISTS and @UVUNIQUE on one field: two live rules', count($both) === 2 && !array_filter($both, function ($r) { return !empty($r['configError']); }));
    check('clientShape strips branches too', ModeRegistry::clientShape(['type' => 'exists', 'existsIn' => 'a',
        'branches' => [['when' => '1', 'existsIn' => 'b', 'existsMatch' => ['x' => 'y'], 'existsLocal' => ['y']]]])
        === ['type' => 'exists', 'branches' => [['when' => '1', 'existsLocal' => ['y']]]]);

    // ---- 4) the endpoint ---------------------------------------------------------
    $m = mod();
    $r = ask($m, 'res_spec', ['res_spec' => 'SP-2']);
    check('found: names the record for staff', $r === ['state' => 'found', 'record' => '2']);
    check('found: one narrowed read was enough', count(\REDCap::$calls) === 1 && isset(\REDCap::$calls[0]['filterLogic'])
        && \REDCap::$calls[0]['fields'] === ['specimen_id']);
    $r = ask(mod(), 'res_spec', ['res_spec' => '  SP-1']);
    check('trimmed on both sides', ($r['state'] ?? null) === 'found');
    $r = ask(mod(), 'res_spec', ['res_spec' => 'sp-2']);
    check('case-sensitive', ($r['state'] ?? null) === 'not-found');
    $m = mod();
    $r = ask($m, 'res_spec', ['res_spec' => 'SP-404']);
    check('not found', $r === ['state' => 'not-found', 'record' => null]);
    check('not found: the narrowed miss was confirmed by a full read', count(\REDCap::$calls) === 2 && fullReads() === 1);
    $r = ask(mod(), 'res_spec', ['res_spec' => 'SP-9']);
    check('found in a repeating instance', $r === ['state' => 'found', 'record' => '3']);
    $m = mod(); \REDCap::$narrowLies = true;
    $r = ask($m, 'res_spec', ['res_spec' => 'SP-2']);
    check('a narrowed read that wrongly misses is overruled by the full read', ($r['state'] ?? null) === 'found');
    $m = mod(); \REDCap::$failFull = true;
    $r = ask($m, 'res_spec', ['res_spec' => 'SP-404']);
    check('a full read that fails answers unknown, not not-found', ($r['state'] ?? null) === 'unknown'
        && strpos($r['why'], 'could not be read') !== false);
    $m = mod(); \REDCap::$failAll = true;
    $r = ask($m, 'res_spec', ['res_spec' => 'SP-2']);
    check('every read failing answers unknown', ($r['state'] ?? null) === 'unknown');
    check('...and the failure text never leaks', strpos(json_encode($r), 'SECRET') === false);
    $r = ask(mod(), 'res_spec', ["res_spec" => "SP'1"]);
    check('a value unsafe to inline still gets an answer (full read)', ($r['state'] ?? null) === 'not-found');

    $r = ask(mod(), 'res_rec', ['res_rec' => '2']);
    check('record lookup: found, and never echoed', $r === ['state' => 'found', 'record' => null]);
    $r = ask(mod(), 'res_rec', ['res_rec' => '22']);
    check('record lookup: not found', ($r['state'] ?? null) === 'not-found');
    $m = mod('nurse', $NOLAB);
    $r = ask($m, 'res_rec', ['res_rec' => '2']);
    check('record lookup needs no rights to the searched forms', ($r['state'] ?? null) === 'found');

    $r = ask(mod(), 'res_date', ['res_date' => '01-03-2026']);
    check('a date typed as shown (D-M-Y) finds the stored Y-M-D', ($r['state'] ?? null) === 'found');
    $r = ask(mod(), 'res_drop', ['res_drop' => 'B']);
    check('a dropdown code is compared as stored', ($r['state'] ?? null) === 'found');

    $r = ask(mod(), 'res_m', ['res_m' => 'SP-2', 'res_site' => 'B']);
    check('match: found when the pair is saved together', ($r['state'] ?? null) === 'found');
    $r = ask(mod(), 'res_m', ['res_m' => 'SP-2', 'res_site' => 'A']);
    check('match: not found when only the value is saved', ($r['state'] ?? null) === 'not-found');
    $r = ask(mod(), 'res_m', ['res_m' => 'SP-2', 'res_site' => '']);
    check('match: a blank match field answers unknown', ($r['state'] ?? null) === 'unknown' && strpos($r['why'], '[res_site] is blank') !== false);
    $r = ask(mod(), 'res_off', ['res_off' => 'SP-1', 'home_site' => ''], '1');
    check('match: an off-page field is read from the saved record', ($r['state'] ?? null) === 'found');
    $r = ask(mod(), 'res_off', ['res_off' => 'SP-1', 'home_site' => ''], '2');
    check('match: ...whose saved value decides', ($r['state'] ?? null) === 'not-found');
    $r = ask(mod(), 'res_off', ['res_off' => 'SP-1'], '');
    check('match: a new record has no saved value, so unknown', ($r['state'] ?? null) === 'unknown');

    $r = ask(mod(), 'res_ev', ['res_ev' => 'SP-3']);
    check('event: a value saved only in another event is not found', ($r['state'] ?? null) === 'not-found');
    $r = ask(mod(), 'res_ev', ['res_ev' => 'SP-2']);
    check('event: found in the named event', ($r['state'] ?? null) === 'found');
    $m = mod(); ask($m, 'res_ev', ['res_ev' => 'SP-2']);
    check('event: the read asks for that event only', \REDCap::$calls[0]['events'] === [351]);
    $m = mod(); \REDCap::$ignoreEvents = true;
    $r = ask($m, 'res_ev', ['res_ev' => 'SP-3']);
    check('event: entries of other events are skipped even if the read returns them', ($r['state'] ?? null) === 'not-found');
    $r = ask(mod(), 'res_sev', ['res_sev' => 'SP-3'], '1', null, 352);
    check('scope event: found in the event of the entry', ($r['state'] ?? null) === 'found');
    $r = ask(mod(), 'res_sev', ['res_sev' => 'SP-3'], '1', null, 351);
    check('scope event: not found from another event', ($r['state'] ?? null) === 'not-found');

    $r = ask(mod(), 'res_dag', ['res_dag' => 'SP-1'], '1');
    check('scope dag: found inside the record\'s own group', ($r['state'] ?? null) === 'found');
    $r = ask(mod(), 'res_dag', ['res_dag' => 'SP-2'], '1');
    check('scope dag: another group\'s value is not found', ($r['state'] ?? null) === 'not-found');
    $r = ask(mod(), 'res_dag', ['res_dag' => 'SP-2'], '', 8);
    check('scope dag: a new record is looked up in the user\'s group', ($r['state'] ?? null) === 'found');
    $r = ask(mod(), 'res_dag', ['res_dag' => 'SP-9'], '', null);
    check('scope dag: a new record of a user with no group looks among records with none', ($r['state'] ?? null) === 'found');
    $r = ask(mod(), 'res_dag', ['res_dag' => 'SP-2'], '', 99);
    check('scope dag: a group whose name cannot be read answers unknown', ($r['state'] ?? null) === 'unknown');

    $r = ask(mod(), 'res_rec_dag', ['res_rec_dag' => '1'], '1');
    check('record lookup, scope dag: a record of the same group is found', ($r['state'] ?? null) === 'found');
    $r = ask(mod(), 'res_rec_dag', ['res_rec_dag' => '2'], '1');
    check('record lookup, scope dag: a record of another group is not', ($r['state'] ?? null) === 'not-found');
    // The lookup itself refuses a blank "match" value, whoever calls it.
    $m = mod();
    $fe = new \ReflectionMethod($m, 'findExisting'); $fe->setAccessible(true);
    $rule = ['type' => 'exists', 'existsIn' => 'specimen_id', 'existsMatch' => ['site_code' => 'res_site']];
    check('findExisting: a blank match value answers unknown', $fe->invoke($m, 149, $rule, 'SP-2', ['res_site' => ' '], 351, null, true)['state'] === 'unknown');
    check('findExisting: ...and a set one answers', $fe->invoke($m, 149, $rule, 'SP-2', ['res_site' => 'B'], 351, null, true)['state'] === 'found');
    $r = ask(mod(), 'res_spec', ['res_spec' => 'SP-2'], '1', 7);
    check('a user in another group gets found, without the record', $r === ['state' => 'found', 'record' => null]);
    $r = ask(mod(), 'res_spec', ['res_spec' => 'SP-2'], '2', 8);
    check('a user in the record\'s group gets the record', $r === ['state' => 'found', 'record' => '2']);

    $m = mod('nurse', $NOLAB);
    $r = ask($m, 'res_spec', ['res_spec' => 'SP-2']);
    check('no rights to the searched form: unknown, nothing read', ($r['state'] ?? null) === 'unknown'
        && strpos($r['why'], 'every form this lookup reads') !== false && \REDCap::$calls === []);
    check('...and the reason does not name the searched field', strpos($r['why'], 'specimen') === false);
    $m = mod('nurse', ['nurse' => ['forms' => ['enrol' => '0', 'lab_reg' => '1', 'result' => '1']]]);
    $r = ask($m, 'res_off', ['res_off' => 'SP-1']);
    check('no rights to an off-page match field: unknown', ($r['state'] ?? null) === 'unknown'
        && strpos($r['why'], 'every form this lookup reads') !== false && strpos($r['why'], 'home_site') === false);
    $m = mod('nurse', []);
    $r = ask($m, 'res_spec', ['res_spec' => 'SP-2']);
    check('rights that cannot be read: unknown (fail closed)', ($r['state'] ?? null) === 'unknown');
    $D4 = $DICT; unset($D4['specimen_id']['form_name']);
    $r = ask(mod('nurse', null, $D4), 'res_spec', ['res_spec' => 'SP-2']);
    check('a searched field no form can be found for: unknown (fail closed)', ($r['state'] ?? null) === 'unknown');

    // A branched rule answers from the branch the saved record selects.
    $BR = $DATA; $BR['1'][351]['res_site'] = 'A'; $BR['2'][351]['res_site'] = 'B';
    $m = mod(); \REDCap::$data = $BR;
    check('branch A looks in specimen_id', (ask($m, 'res_br', ['res_br' => 'SP-1'], '1')['state'] ?? null) === 'found');
    $m = mod(); \REDCap::$data = $BR;
    check('branch A does not look in site_code', (ask($m, 'res_br', ['res_br' => 'B'], '1')['state'] ?? null) === 'not-found');
    $m = mod(); \REDCap::$data = $BR;
    check('branch B looks in site_code', (ask($m, 'res_br', ['res_br' => 'B'], '2')['state'] ?? null) === 'found');
    $m = mod(); \REDCap::$data = $BR;
    check('no branch selected: nothing to answer', isset(ask($m, 'res_br', ['res_br' => 'B'], '3')['error']));

    // unique-check got the same form-rights gate for signed-in callers.
    $u = function ($m, $field) {
        return $m->redcap_module_ajax('unique-check', ['field' => $field, 'values' => [$field => 'U-1']],
            149, '1', 'result', 351, 1, null, null, null, '', '', 'nurse', null);
    };
    check('unique-check: answered for a readable form', isset($u(mod(), 'lab_uniq')['used']));
    check('unique-check: refused for a form the caller may not open', ($u(mod('nurse', $NOLAB), 'lab_uniq')['error'] ?? null) === 'not a checkable field');
    check('unique-check: refused when rights cannot be read', ($u(mod('nurse', []), 'lab_uniq')['error'] ?? null) === 'not a checkable field');

    check('no rule on the field: error', isset(ask(mod(), 'res_site', ['res_site' => 'A'])['error']));
    check('a misconfigured rule is never asked', isset(ask(mod(), 'bad_self', ['bad_self' => 'x'])['error']));
    check('a bad field name: error', isset(ask(mod(), 'Res-Spec', ['x' => 'y'])['error']));
    check('too many values: error', isset(ask(mod(), 'res_spec', array_fill_keys(['a','b','c','d','e','f','g','h','res_spec'], 'x'))['error']));
    check('an over-long value: error', isset(ask(mod(), 'res_spec', ['res_spec' => str_repeat('x', 1025)])['error']));
    check('a blank value: error, nothing read', isset(ask($m = mod(), 'res_spec', ['res_spec' => '  '])['error']) && \REDCap::$calls === []);

    // Surveys
    $r = askSurvey(mod(null), 'res_spec', ['res_spec' => 'SP-2']);
    check('survey: refused without the opt-in', ($r['error'] ?? null) === 'not enabled on surveys');
    $r = askSurvey(mod(null), 'res_s', ['res_s' => 'SP-2']);
    check('survey: found / not-found only, no record, no reason', $r === ['state' => 'found', 'record' => null]);
    $m = mod(null); \REDCap::$failAll = true;
    $r = askSurvey($m, 'res_s', ['res_s' => 'SP-2']);
    check('survey: unknown carries no reason', $r === ['state' => 'unknown', 'record' => null, 'why' => null]);
    $D3 = $DICT; $D3['specimen_id']['identifier'] = 'y';
    $r = askSurvey(mod(null, null, $D3), 'res_s', ['res_s' => 'SP-2']);
    check('survey: a rule refused for an Identifier target is never answered', isset($r['error']) && !isset($r['state']));
    // The endpoint re-checks the flags itself: rules loaded, then a target flagged.
    $m = mod(null);
    askSurvey($m, 'res_s', ['res_s' => 'SP-2']);
    $dd = new \ReflectionProperty($m, 'ddCache'); $dd->setAccessible(true);
    $dd->setValue($m, [149 => $D3]);
    $r = askSurvey($m, 'res_s', ['res_s' => 'SP-2']);
    check('survey: the endpoint refuses on its own when a touched field is an Identifier', ($r['error'] ?? null) === 'not enabled on surveys');

    // Signed-in throttle (needs a real session)
    @session_start();
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        $m = mod();
        $last = null;
        for ($i = 0; $i < 60; $i++) $last = ask($m, 'res_spec', ['res_spec' => 'SP-2']);
        check('throttle: 60 lookups a minute are answered', ($last['state'] ?? null) === 'found');
        $r = ask($m, 'res_spec', ['res_spec' => 'SP-2']);
        check('throttle: the 61st answers unknown', ($r['state'] ?? null) === 'unknown' && strpos($r['why'], 'too many checks') !== false);
        $r = $m->redcap_module_ajax('unique-check', ['field' => 'res_uniq', 'values' => ['res_uniq' => 'U-1']],
            149, '1', 'result', 351, 1, null, null, null, '', '', 'nurse', null);
        check('throttle: one budget covers unique-check too', ($r['error'] ?? null) === 'too many checks — slow down');
        $_SESSION = [];
        $r = $m->redcap_module_ajax('unique-check', ['field' => 'res_uniq', 'values' => ['res_uniq' => 'U-1']],
            149, '1', 'result', 351, 1, null, null, null, '', '', 'nurse', null);
        check('throttle: a fresh minute answers unique-check again', isset($r['used']));
        $_SESSION = [];
    } else {
        check('throttle: a session could be started in this runtime', false);
    }

    // ---- 5) the post-save audit ----------------------------------------------------
    $AUD = $DATA;
    $AUD['1'][351] += ['res_spec' => 'SP-404', 'res_rec' => '2', 'res_m' => 'SP-2', 'res_site' => 'A', 'res_date' => '2026-03-01'];
    $m = mod(); \REDCap::$data = $AUD;
    $m->redcap_save_record(149, '1', 'result', 351, null, null, null, 1);
    $by = []; foreach (findings($m) as $e) $by[$e['field']] = $e;
    check('audit: not found is logged as type exists', isset($by['res_spec']) && $by['res_spec']['type'] === 'exists'
        && $by['res_spec']['reason'] === 'not-found' && $by['res_spec']['value'] === 'SP-404');
    check('audit: a pair saved apart is not found', isset($by['res_m']) && $by['res_m']['reason'] === 'not-found');
    check('audit: found values are not logged', !isset($by['res_rec']) && !isset($by['res_date']));
    $AB = $AUD; $AB['1'][351] += ['res_br' => 'SP-2'];
    $AB['1'][351]['res_site'] = 'B';
    $m = mod(); \REDCap::$data = $AB;
    $m->redcap_save_record(149, '1', 'result', 351, null, null, null, 1);
    $by = []; foreach (findings($m) as $e) $by[$e['field']] = $e;
    check('audit: the selected branch decides (SP-2 is not a site code)', isset($by['res_br']) && $by['res_br']['reason'] === 'not-found');
    $m = mod(); \REDCap::$data = $AUD;
    \REDCap::$failFull = true;
    $m->redcap_save_record(149, '1', 'result', 351, null, null, null, 1);
    $u = findings($m, 'uvalidate-unconfigurable');
    check('audit: a lookup that cannot finish is a rule problem', (bool) array_filter($u, function ($e) {
        return strpos($e['fields'], 'res_spec') !== false && strpos($e['why'], 'lookup-unavailable') !== false; }));
    check('audit: ...and never logged as a violation', !array_filter(findings($m), function ($e) { return $e['field'] === 'res_spec'; }));

    // ---- 6) the scan -----------------------------------------------------------------
    $SC = $DATA;
    $SC['1'][351] += ['res_spec' => 'SP-1', 'res_m' => 'SP-2', 'res_site' => 'B'];   // SP-1 is stored padded
    $SC['2'][351] += ['res_spec' => 'SP-404', 'res_m' => 'SP-2', 'res_site' => 'A'];
    $SC['3'][351] += ['res_spec' => 'SP-9', 'res_rec' => '77'];
    $m = mod(); \REDCap::$data = $SC;
    $res = $m->scanProject(149, null, 200, null, ['valueCeiling' => 'raw']);
    $hits = [];
    foreach ($res['violations'] as $v) if ($v['type'] === 'exists') $hits[] = $v['record'] . '/' . $v['field'];
    sort($hits);
    check('scan: not-found values reported', $hits === ['2/res_m', '2/res_spec', '3/res_rec']);
    $idxReads = array_filter(\REDCap::$calls, function ($c) {
        return empty($c['records']) && empty($c['filterLogic']) && ($c['fields'] ?? null) === ['specimen_id'];
    });
    check('scan: the searched field is read once for every record', count($idxReads) === 1);
    check('scan: no narrowed per-record lookups', !array_filter(\REDCap::$calls, function ($c) { return !empty($c['filterLogic']); }));
    $m = mod(); \REDCap::$data = $SC; \REDCap::$failFull = true;
    $res = $m->scanProject(149, null, 200, null, ['valueCeiling' => 'raw']);
    check('scan: an index that cannot be read reports rule problems, not passes',
        !array_filter($res['violations'], function ($v) { return $v['type'] === 'exists'; })
        && $res['status'] !== 'complete');
    $m = mod(); \REDCap::$data = $SC;
    $res = $m->scanProject(149, 7, 200, null, ['valueCeiling' => 'raw']);
    $un = array_filter($res['unconfigurable'], function ($u) { return strpos($u['why'], 'across every Data Access Group') !== false; });
    $unFields = []; foreach ($un as $u) foreach ($u['fields'] as $uf) $unFields[$uf] = true;
    check('group scan: a cross-group exists rule is not evaluated', isset($unFields['res_spec']) && isset($unFields['res_m']));
    check('group scan: a "scope":"dag" rule still is', !isset($unFields['res_dag']));
    $plan = new \ReflectionMethod($m, 'scanPlan'); $plan->setAccessible(true);
    $own = $plan->invoke($m, 149, [])['ownership'];
    check('scan entitlement: the searched and match fields are owned by their form',
        ($own['specimen_id'] ?? null) === 'lab_reg' && ($own['site_code'] ?? null) === 'lab_reg' && ($own['home_site'] ?? null) === 'enrol');

    // ---- 7) review fixes (2.3.0) --------------------------------------------------------
    /** Any live call, with an optional "cond" part. */
    $call = function ($m, $action, $field, array $values, array $extra = [], $record = '1', $group = null, $user = 'nurse', $hash = null) {
        return $m->redcap_module_ajax($action, ['field' => $field, 'values' => $values] + $extra,
            149, $record, 'result', 351, 1, $hash, null, null, '', '', $user, $group);
    };
    $BR = $DATA; $BR['1'][351]['res_site'] = 'A'; $BR['2'][351]['res_site'] = 'B';

    // S1: the branch the page enforces is the branch the server answers from.
    $m = mod(); \REDCap::$data = $BR;
    $r = $call($m, 'exists-check', 'res_br', ['res_br' => 'B'], ['cond' => ['res_site' => 'B']]);
    check('cond: an unsaved selector change picks the branch (B looks in site_code)', ($r['state'] ?? null) === 'found');
    $m = mod(); \REDCap::$data = $BR;
    $r = $call($m, 'exists-check', 'res_br', ['res_br' => 'SP-2'], ['cond' => ['res_site' => 'A']], '');
    check('cond: a new record is answered from the page\'s branch', ($r['state'] ?? null) === 'found');
    $m = mod(); \REDCap::$data = $BR;
    check('no cond on a new record: no branch, nothing to answer', isset($call($m, 'exists-check', 'res_br', ['res_br' => 'SP-2'], [], '')['error']));
    $m = mod(); \REDCap::$data = $BR;
    $r = $call($m, 'exists-check', 'res_br2', ['res_br2' => 'SP-1'], ['cond' => ['home_site' => 'B']]);
    check('cond: a field of another form keeps its saved value (branch A, specimen_id)', ($r['state'] ?? null) === 'found');
    foreach ([['cond' => 'x'], ['cond' => array_fill_keys(array_map(function ($i) { return 'f' . $i; }, range(1, 41)), 'x')],
              ['cond' => ['Bad-Key' => 'x']], ['cond' => ['res_site' => ['1' => ['deep']]]], ['cond' => ['res_site' => str_repeat('x', 1025)]]] as $i => $bad) {
        check('cond: malformed part ' . $i . ' refused', ($call(mod(), 'exists-check', 'res_spec', ['res_spec' => 'SP-2'], $bad)['error'] ?? null) === 'malformed request');
    }
    $lc = new \ReflectionMethod(mod(), 'liveCondValues'); $lc->setAccessible(true);
    $mm = mod();
    check('cond: a checkbox arrives as code => 1/0, other fields of the form are dropped',
        $lc->invoke($mm, ['cond' => ['res_site' => ['1' => '1', '2' => 'x'], 'specimen_id' => 'SP-1']], 149, 'result') === ['res_site' => ['1' => '1', '2' => '0']]);
    $m = mod(); \REDCap::$data = $BR;
    check('unique-check: no cond on a new record, no branch', ($call($m, 'unique-check', 'res_ubr', ['res_ubr' => 'U-1'], [], '')['error'] ?? null) === 'not a checkable field');
    $m = mod(); \REDCap::$data = $BR;
    check('unique-check: the page\'s branch values are honoured too', isset($call($m, 'unique-check', 'res_ubr', ['res_ubr' => 'U-1'], ['cond' => ['res_site' => 'A']], '')['used']));

    // S2: a DAG-confined read never turns into "not found".
    $m = mod(); \REDCap::$confineTo = 'north';
    $r = ask($m, 'res_spec', ['res_spec' => 'SP-2'], '1', 7);
    check('dag read: a value saved only in another group is unknown, not not-found', ($r['state'] ?? null) === 'unknown'
        && strpos($r['why'], 'another Data Access Group') !== false);
    $m = mod(); \REDCap::$confineTo = 'north';
    check('dag read: a value in the caller\'s own group is still found', (ask($m, 'res_spec', ['res_spec' => 'SP-1'], '1', 7)['state'] ?? null) === 'found');
    $m = mod(); \REDCap::$confineTo = 'north';
    check('dag read: a record ID of another group is unknown too', (ask($m, 'res_rec', ['res_rec' => '2'], '1', 7)['state'] ?? null) === 'unknown');
    $m = mod(); \REDCap::$confineTo = 'north';
    check('dag read: "scope":"dag" never needed the other groups', (ask($m, 'res_dag', ['res_dag' => 'SP-404'], '1', 7)['state'] ?? null) === 'not-found');
    check('dag read: reads that see the other groups keep "not found"', (ask(mod(), 'res_spec', ['res_spec' => 'SP-404'], '1', 7)['state'] ?? null) === 'not-found');
    check('dag read: a caller in no group keeps "not found"', (ask(mod(), 'res_spec', ['res_spec' => 'SP-404'], '1', null)['state'] ?? null) === 'not-found');
    $AG = $DATA; $AG['1'][351] += ['res_spec' => 'SP-2'];
    $m = mod(); \REDCap::$data = $AG; \REDCap::$confineTo = 'north';
    $m->redcap_save_record(149, '1', 'result', 351, 7, null, null, 1);
    check('dag audit: no violation from a confined read', !array_filter(findings($m), function ($e) { return $e['field'] === 'res_spec'; }));
    check('dag audit: ...a rule problem that says why', (bool) array_filter(findings($m, 'uvalidate-unconfigurable'), function ($e) {
        return strpos($e['fields'], 'res_spec') !== false && strpos($e['why'], 'another Data Access Group') !== false; }));

    // S5: the index keeps one hit per (group, event) under a key.
    $IX = $DATA;
    for ($i = 4; $i <= 60; $i++) $IX[(string) $i] = [351 => ['record_id' => (string) $i, 'site_code' => 'B', 'redcap_data_access_group' => 'south']];
    $IX['1'][351]['res_drop'] = 'B';   // one lookup builds the index
    $m = mod(); \REDCap::$data = $IX;
    $m->scanProject(149, null, 200, null, ['valueCeiling' => 'raw']);
    $ip = new \ReflectionProperty($m, 'existsIndexes'); $ip->setAccessible(true);
    $siteIdx = null;
    foreach ($ip->getValue($m) as $k => $idx) if (is_array($idx) && strpos($k, '"site_code"') !== false) $siteIdx = $idx;
    check('index: 58 records sharing a value cost one entry per (group, event)', $siteIdx !== null && count($siteIdx['B']) === 2);
    check('index: the key names the project', (bool) array_filter(array_keys($ip->getValue($m)), function ($k) { return strpos($k, '[149,') === 0; }));

    // S6 + S4: a group-confined scan does not evaluate what it says it cannot.
    $SG = $SC; $SG['1'][351]['res_spec'] = 'SP-404';
    $m = mod(); \REDCap::$data = $SG;
    $res = $m->scanProject(149, 7, 200, null, ['valueCeiling' => 'raw']);
    check('group scan: no findings from a rule reported as not evaluated',
        !array_filter($res['violations'], function ($v) { return in_array($v['field'], ['res_spec', 'res_m', 'res_rec'], true); }));
    $planOf = function ($m, $dag = null) { $p = new \ReflectionMethod($m, 'scanPlan'); $p->setAccessible(true); return $p->invoke($m, 149, [], $dag); };
    check('plan: a project with @UVEXISTS rules has cross-record lookups', $planOf(mod())['crossRecordLookups'] === true);
    check('plan: so does a group scan that still evaluates a "scope":"dag" rule', $planOf(mod(), 7)['crossRecordLookups'] === true);
    $D5 = array_filter($DICT, function ($f) { return strpos($f['field_annotation'], '@UVEXISTS') === false; });
    check('plan: a project without them has none', $planOf(mod('nurse', null, $D5))['crossRecordLookups'] === false);
    $P = \INSPIRE\UniversalValidator\Scan\ScanPromotion::class; $O = \INSPIRE\UniversalValidator\Scan\ScanOutcome::class;
    $run = ['fence_target' => '500', 'manifest_total' => 3, 'detail_rows' => 0, 'detail_bytes' => 0];
    $ok = ['uniqueDone' => true, 'rollupDone' => true];
    $f = $P::facts($run, [\INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE => 3], $ok + ['crossRecordLookups' => true, 'windowChanges' => 2]);
    $o = $O::derive($f['facts']);
    check('promotion: lookups + changes in the window cap the run at manifest-complete', $o['coverage'] === $O::MANIFEST
        && strpos($o['why'], '@UVEXISTS') !== false);
    $f = $P::facts($run, [\INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE => 3], $ok + ['crossRecordLookups' => true, 'windowChanges' => 0]);
    check('promotion: lookups with no change in the window keep the fence', $O::derive($f['facts'])['coverage'] === $O::FENCED);
    $f = $P::facts($run, [\INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE => 3], $ok + ['windowChanges' => 5]);
    check('promotion: changes without lookups keep the fence', $O::derive($f['facts'])['coverage'] === $O::FENCED);
    // Wiring pin: the durable worker hands both facts to promotion (the path
    // needs a database, so the matrix runs it; this keeps the hand-off honest).
    $svcSrc = file_get_contents(__DIR__ . '/../php/Scan/ScanService.php');
    check('durable scan: promotion is told about cross-record lookups and window changes',
        strpos($svcSrc, "'crossRecordLookups' => !empty(\$ctx['plan']['crossRecordLookups'])") !== false
        && strpos($svcSrc, "'windowChanges'  => \$this->aggregateTotal(\$store, \$runId, CatchUp::K_WINDOW)") !== false);

    // S7: the record a DAG user names must be in their group.
    $m = mod();
    $r = ask($m, 'res_off', ['res_off' => 'SP-1'], '2', 7);
    check('record of another group: unknown', ($r['state'] ?? null) === 'unknown' && strpos($r['why'], 'not in your Data Access Group') !== false);
    check('...and none of its values was read', !array_filter(\REDCap::$calls, function ($c) {
        return ($c['records'] ?? null) === ['2'] && ($c['fields'] ?? null) !== ['record_id']; }));
    check('record of another group: unique-check refuses', ($call(mod(), 'unique-check', 'res_uniq', ['res_uniq' => 'U-1'], [], '2', 7)['error'] ?? null) === 'record not available');
    check('a record of an unreadable group name: refused', ($call(mod(), 'unique-check', 'res_uniq', ['res_uniq' => 'U-1'], [], '1', 99)['error'] ?? null) === 'record not available');
    check('a record not saved yet: answered', isset($call(mod(), 'unique-check', 'res_uniq', ['res_uniq' => 'U-1'], [], 'NEW-1', 7)['used']));
    check('a caller in no group: never refused', isset($call(mod(), 'unique-check', 'res_uniq', ['res_uniq' => 'U-1'], [], '2', null)['used']));

    // S8: an unauthenticated miss costs a whole-field read, within a budget.
    $m = mod(null);
    $r = askSurvey($m, 'res_s', ['res_s' => 'SP-404']);
    check('survey budget: a miss within the budget is answered', $r === ['state' => 'not-found', 'record' => null]);
    $tier2 = ((int) floor(time() / 60)) * \INSPIRE\UniversalValidator\UniversalValidator::RATE_TIERS + 2;
    $m->rateBuckets['149|' . $tier2] = \INSPIRE\UniversalValidator\UniversalValidator::THROTTLE_SURVEY_FULL_READS;
    \REDCap::$calls = [];
    $r = askSurvey($m, 'res_s', ['res_s' => 'SP-405']);
    check('survey budget: past it, a miss is unknown and nothing more is read', ($r['state'] ?? null) === 'unknown' && fullReads() === 0);
    check('survey budget: a hit needs no whole-field read and is still answered', (askSurvey($m, 'res_s', ['res_s' => 'SP-2'])['state'] ?? null) === 'found');
    $m = mod(null); $m->queryThrows = true;
    check('survey budget: a counter that cannot be kept refuses the read (fail closed)', (askSurvey($m, 'res_s', ['res_s' => 'SP-404'])['state'] ?? null) === 'unknown');
    $m = mod(); $m->rateBuckets['149|' . $tier2] = 10000;
    check('survey budget: staff are not on it', (ask($m, 'res_spec', ['res_spec' => 'SP-404'])['state'] ?? null) === 'not-found');

    // S9: a survey page never learns where a misconfigured rule looks.
    $ps = page(mod(null), 'survey', '1', 'result');
    $leaks = array_filter(['nat_id', 'nowhere_arm_1', 'spec_date', 'specimen_id', 'spec_tick', 'spec_dt'], function ($w) use ($ps) { return strpos($ps['html'], $w) !== false; });
    check('survey page: no configuration error names a field or event the lookup reads', !$leaks);
    if ($leaks) { foreach ($leaks as $w) { $at = strpos($ps['html'], $w); fwrite(STDERR, "LEAK $w: " . substr($ps['html'], max(0, $at - 200), 300) . "
"); } }
    $rb = ruleFor($ps, 'bad_ident');
    check('survey page: the rule still says it is misconfigured', $rb && !empty($rb['configError']));
    check('data entry page: staff still get the detail', strpos(ruleFor(page(mod(), 'form', '1', 'result'), 'bad_event')['configError'], 'nowhere_arm_1') !== false);

    // S10: a scan's index never answers a later call in the same instance.
    $m = mod(); \REDCap::$data = $SC;
    $m->scanProject(149, null, 200, null, ['valueCeiling' => 'raw']);
    $later = $SC; $later['3']['repeat_instances'][351]['lab_reg'][2] = ['specimen_id' => 'SP-77', 'site_code' => 'C'];
    $later['1'][351]['res_spec'] = 'SP-77';
    \REDCap::$data = $later; $m->logCalls = [];
    $m->redcap_save_record(149, '1', 'result', 351, null, null, null, 1);
    check('after a scan, the audit reads what is saved now', !array_filter(findings($m), function ($e) { return $e['field'] === 'res_spec'; }));

    // J6: a field the page leaves out is read as saved.
    $m = mod(); \REDCap::$data = $BR;
    check('match field of this form left out (another survey page): its saved value is used',
        (ask($m, 'res_m', ['res_m' => 'SP-2'], '2')['state'] ?? null) === 'found');
    $m = mod(); \REDCap::$data = $BR;
    check('match field of this form sent blank: nothing to match yet', (ask($m, 'res_m', ['res_m' => 'SP-2', 'res_site' => ''], '2')['state'] ?? null) === 'unknown');
    $UW = $BR; $UW['2'][351]['res_uw'] = 'U-9';
    $m = mod(); \REDCap::$data = $UW;
    check('unique "with" field left out: its saved value decides (used with B)',
        ($call($m, 'unique-check', 'res_uw', ['res_uw' => 'U-9'], [], '3')['used'] ?? null) === false);
    $UW['3'][351]['res_site'] = 'B';
    $m = mod(); \REDCap::$data = $UW;
    check('unique "with" field left out: ...the same saved B collides', ($call($m, 'unique-check', 'res_uw', ['res_uw' => 'U-9'], [], '3')['used'] ?? null) === true);

    // J7: saving the form of a "match" field re-audits the lookup; saving the
    // form it searches does not (a value there moves other records, not this one).
    $RD = $DATA; $RD['1'][351] += ['res_off' => 'SP-1', 'res_spec' => 'SP-404']; $RD['1'][351]['home_site'] = 'B';
    $m = mod(); \REDCap::$data = $RD;
    $m->redcap_save_record(149, '1', 'enrol', 351, null, null, null, 1);
    check('reverse dependency: saving the "match" field\'s form re-audits the lookup',
        (bool) array_filter(findings($m), function ($e) { return $e['field'] === 'res_off' && $e['reason'] === 'not-found'; }));
    $m = mod(); \REDCap::$data = $RD;
    $m->redcap_save_record(149, '1', 'lab_reg', 351, null, null, null, 1);
    check('reverse dependency: saving the searched form does not re-audit this record\'s lookups',
        !array_filter(findings($m), function ($e) { return $e['field'] === 'res_spec'; }));

    echo "exists_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
