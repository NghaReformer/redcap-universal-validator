<?php
/**
 * exists_cross_php.php — @UVEXISTS in another project (2.4.0).
 *
 * Project 149 asks; project 300 answers. 301 does not have the module, 302 is
 * deleted. The mocks keep settings, dictionaries, data, rights and \Project per
 * project, and REDCap::getUserRights() is a trap: it always answers with the
 * rights of the project of the request (149), which here include the form of
 * project 300 that holds the searched field. Nothing may read it as rights in
 * project 300.
 *
 * Pins:
 *   - the grammar ("project", its combinations) and the annotation order: the
 *     server switch, the alias, the other project's agreement with ONE message
 *     for every reason and no read of that project's dictionary or data before
 *     it passes, then the field checks there;
 *   - the endpoint: "rights" (a rights row there, form rights, confinement to
 *     the user's group there, expiry, administrators) and "answer" (any
 *     signed-in user; identifiers refused); surveys only under "answer" with
 *     both sides agreeing; the record there never echoed; an empty read never
 *     "not found"; the two budgets, failing closed;
 *   - the probe log in the other project: every answer and refusal, the value
 *     only as that project's keyed hash, left out under its "none" mode;
 *   - the audit and the scan (one read there per request, one log line, users
 *     confined to a group there refused), and a group-confined scan here;
 *   - the Configure dialog checks of the alias and agreement rows.
 *
 * Run:  php tests/exists_cross_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $PREFIX = 'universal_validator';
        public $logCalls = [];
        public $settingsBy = [];      // pid => key => value
        public $subSettingsBy = [];   // pid => key => rows
        public $systemSettings = [];
        public $projectIdReturn = null;
        public $enabledIn = [149, 300, 302];
        public $deleted = [302];
        public $settingReads = [];    // [pid, key]
        public function getSubSettings($k, $pid = null) {
            $p = ($pid !== null && $pid !== '') ? (int) $pid : (int) $this->projectIdReturn;
            $this->settingReads[] = [$p, $k];
            return isset($this->subSettingsBy[$p][$k]) ? $this->subSettingsBy[$p][$k] : [];
        }
        public function getProjectSetting($k, $pid = null) {
            $p = ($pid !== null && $pid !== '') ? (int) $pid : (int) $this->projectIdReturn;
            $this->settingReads[] = [$p, $k];
            return isset($this->settingsBy[$p][$k]) ? $this->settingsBy[$p][$k] : null;
        }
        public function getSystemSetting($k) { return isset($this->systemSettings[$k]) ? $this->systemSettings[$k] : null; }
        public function setSystemSetting($k, $v) { $this->systemSettings[$k] = $v; }
        public function getProjectId() { return $this->projectIdReturn; }
        public function isModuleEnabled($prefix, $pid = null) {
            return $prefix === $this->PREFIX && in_array((int) $pid, $this->enabledIn, true);
        }
        // As the framework: a status for every project that has a row, a deleted
        // one included (only redcap_projects.date_deleted says it is deleted).
        public $projectsWithRows = [149, 150, 300, 301, 302];
        public function getProjectStatus($pid = null) { return in_array((int) $pid, $this->projectsWithRows, true) ? 'DEV' : null; }
        public function getUrl($p) { return '/x/' . $p; }
        /** As framework 11+: log() throws with no signed-in user unless config.json enables no-auth logging. */
        public function log($m, $p = []) {
            if ($this->getUser() === null) {
                $cfg = json_decode(file_get_contents(__DIR__ . '/../config.json'), true);
                if (empty($cfg['enable-no-auth-logging'])) throw new \Exception('log() needs a user unless enable-no-auth-logging is set');
            }
            $this->logCalls[] = [$m, $p];
            return count($this->logCalls);
        }
        public function initializeJavascriptModuleObject() { return '<script></script>'; }
        public function getJavascriptModuleObjectName() { return 'ExternalModules.TEST.UniversalValidator'; }
        public $rateBuckets = [];
        public $projectReads = [];
        public $lastInsertId = 0;
        public $queryThrows = false;      // the rate-bucket table only
        public $queryThrowsAll = false;
        public function query($sql, $params = []) {
            if ($this->queryThrows && strpos($sql, 'uv_rate_bucket') !== false) throw new \RuntimeException('no table');
            if ($this->queryThrowsAll) throw new \RuntimeException('no database');
            if (strpos($sql, 'SELECT LAST_INSERT_ID()') !== false) return [[$this->lastInsertId]];
            if (strpos($sql, 'SELECT ROW_COUNT()') !== false) return [[1]];
            if (strpos($sql, 'FROM redcap_projects') !== false) {
                $this->projectReads[] = (int) $params[0];
                if (!in_array((int) $params[0], $this->projectsWithRows, true)) return [];
                return [[in_array((int) $params[0], $this->deleted, true) ? '2026-10-01 09:00:00' : null]];
            }
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
        public function isSuperUser() { return $this->n === 'admin'; }
        public function getRights($pid = null) {
            return isset(\REDCap::$rightsBy[(int) $pid][$this->n]) ? \REDCap::$rightsBy[(int) $pid][$this->n] : null;
        }
    }
}

namespace {
    class REDCap {
        public static $data = [];          // pid => records
        public static $dictionaries = [];  // pid => dictionary
        public static $rightsBy = [];      // pid => user => rights row
        public static $calls = [];
        public static $ddCalls = [];
        public static $emptyFor = [];      // pids whose reads come back empty (a misapplied filter)
        public static $confineTo = [];     // pid => the one group a read shows
        public static $failFor = [];
        public static function reset() {
            self::$calls = self::$ddCalls = [];
            self::$emptyFor = self::$failFor = self::$confineTo = [];
        }
        private static function dagOf(array $node) {
            foreach ($node as $k => $v) {
                if ($k !== 'repeat_instances' && is_array($v) && isset($v['redcap_data_access_group'])) return $v['redcap_data_access_group'];
            }
            return '';
        }
        public static function getData($p) {
            $pid = (int) ($p['project_id'] ?? 0);
            self::$calls[] = $p + ['_pid' => $pid];
            if (!isset(self::$data[$pid])) throw new \RuntimeException('no such project');
            if (in_array($pid, self::$failFor, true)) throw new \RuntimeException('simulated failure');
            if (in_array($pid, self::$emptyFor, true)) return [];
            $out = [];
            foreach (self::$data[$pid] as $rec => $node) {
                if (isset(self::$confineTo[$pid]) && self::dagOf($node) !== self::$confineTo[$pid]) continue;
                if (!empty($p['records']) && !in_array((string) $rec, array_map('strval', $p['records']), true)) continue;
                if (!empty($p['events'])) {
                    $keep = array_map('strval', $p['events']);
                    $n2 = [];
                    foreach ($node as $k => $v) if ($k !== 'repeat_instances' && in_array((string) $k, $keep, true)) $n2[$k] = $v;
                    if (!$n2) continue;
                    $node = $n2;
                }
                if (!empty($p['filterLogic'])) {
                    preg_match_all("/\\[([a-z0-9_]+)\\] = '([^']*)'/", $p['filterLogic'], $mm, PREG_SET_ORDER);
                    $hit = false;
                    foreach ($node as $k => $row) {
                        if ($k === 'repeat_instances' || !is_array($row)) continue;
                        $ok = true;
                        foreach ($mm as $c) if (!isset($row[$c[1]]) || (string) $row[$c[1]] !== $c[2]) { $ok = false; break; }
                        if ($ok) { $hit = true; break; }
                    }
                    if (!$hit) continue;
                }
                $out[$rec] = $node;
            }
            return $out;
        }
        public static function getDataDictionary($pid, $f = 'array') {
            self::$ddCalls[] = (int) $pid;
            if (!isset(self::$dictionaries[(int) $pid])) throw new \RuntimeException('no such project');
            return self::$dictionaries[(int) $pid];
        }
        // The project of the request (149) only.
        public static function getEventNames($unique = false, $assoc = false, $id = null) { return [351 => 'enrolment_arm_1']; }
        public static function getGroupNames($unique = false, $g = null) { return [7 => 'north', 8 => 'south'][$g] ?? ''; }
        public static function getRecordIdField() { return 'record_id'; }
        /** THE TRAP: rights in the project of the request, whatever was meant. */
        public static function getUserRights($u = null) {
            $all = [];
            foreach (['nurse', 'outsider', 'lapsed', 'grouped', 'nogroup', 'nolab'] as $n) {
                $all[$n] = ['forms' => ['enrol' => '1', 'result' => '1', 'lab' => '1'], 'group_id' => null];
            }
            return $all;
        }
    }
    class Project {
        public static $events = [300 => [901 => 'lab_arm_1', 902 => 'followup_arm_1']];
        public static $groups = [300 => [51 => 'lab_north', 52 => 'lab_south']];
        private $pid;
        public function __construct($pid) { $this->pid = (int) $pid; }
        public function getUniqueEventNames() { return self::$events[$this->pid] ?? []; }
        public function getUniqueGroupNames() { return self::$groups[$this->pid] ?? []; }
    }

    require_once __DIR__ . '/../UniversalValidator.php';
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';

    use INSPIRE\UniversalValidator\AnnotationRules;

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }
    function f($form, $ann = '', $validation = '', $type = 'text', $ident = '') {
        return ['field_type' => $type, 'form_name' => $form, 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => $validation, 'identifier' => $ident,
                'select_choices_or_calculations' => ''];
    }
    function ux($json) { return '@UVEXISTS=' . $json; }

    $DICT_A = [
        'record_id'  => f('enrol'),
        'site'       => f('enrol'),
        'nat_id'     => f('enrol', '', '', 'text', 'y'),
        'x_spec'     => f('result', ux('{"in":"[specimen_id]","project":300}')),
        'x_alias'    => f('result', ux('{"in":"[specimen_id]","project":"lab"}')),
        'x_rec'      => f('result', ux('{"in":"record","project":300}')),
        'x_m'        => f('result', ux('{"in":"[specimen_id]","project":300,"match":{"site_code":"[site]"}}')),
        'x_s'        => f('result', ux('{"in":"[specimen_id]","project":300,"surveys":true}')),
        'x_ev'       => f('result', ux('{"in":"[specimen_id]","project":300,"event":"lab_arm_1"}')),
        'x_date'     => f('result', ux('{"in":"[spec_date]","project":300}'), 'date_dmy'),
        'x_donor'    => f('result', ux('{"in":"[donor_name]","project":300}')),
        'x_srec'     => f('result', ux('{"in":"record","project":300,"surveys":true}')),
        'bad_family' => f('result', ux('{"in":"[spec_date]","project":300}')),
        'bad_ghost'  => f('result', ux('{"in":"[ghost]","project":300}')),
        'bad_secret' => f('result', ux('{"in":"[secret_code]","project":300}')),
        'bad_nomod'  => f('result', ux('{"in":"[specimen_id]","project":301}')),
        'bad_gone'   => f('result', ux('{"in":"[specimen_id]","project":302}')),
        'bad_none'   => f('result', ux('{"in":"[specimen_id]","project":999}')),
        'bad_self'   => f('result', ux('{"in":"[specimen_id]","project":149}')),
        'bad_alias'  => f('result', ux('{"in":"[specimen_id]","project":"nolab"}')),
        'bad_event'  => f('result', ux('{"in":"[specimen_id]","project":300,"event":"nowhere_arm_1"}')),
        'bad_local'  => f('result', ux('{"in":"[specimen_id]","project":300,"match":{"site_code":"[nope]"}}')),
    ];
    $DICT_B = [
        'record_id'   => f('lab'),
        'specimen_id' => f('lab'),
        'site_code'   => f('lab'),
        'spec_date'   => f('lab', '', 'date_ymd'),
        'secret_code' => f('lab'),
        'donor_name'  => f('lab', '', '', 'text', 'y'),
        'ghost_note'  => f('lab'),
    ];
    $DATA_A = ['1' => [351 => ['record_id' => '1', 'site' => 'A']]];
    $DATA_B = [
        'L1' => [901 => ['record_id' => 'L1', 'specimen_id' => 'SP-1', 'site_code' => 'A', 'spec_date' => '2026-03-01',
                         'donor_name' => 'Ada', 'redcap_data_access_group' => 'lab_north']],
        'L2' => [901 => ['record_id' => 'L2', 'specimen_id' => 'SP-2', 'site_code' => 'B', 'redcap_data_access_group' => 'lab_south']],
        'L3' => [902 => ['record_id' => 'L3', 'specimen_id' => 'SP-EV2', 'redcap_data_access_group' => 'lab_south']],
    ];
    $RIGHTS_A = ['forms' => ['enrol' => '1', 'result' => '1'], 'group_id' => null, 'expiration' => ''];
    $RIGHTS_B = ['forms' => ['lab' => '1'], 'group_id' => null, 'expiration' => ''];
    function consent($mode = 'rights', $targets = 'specimen_id, site_code, spec_date, record, ghost, donor_name', $surveys = false, $project = '149') {
        return ['exists-consumer-project' => $project, 'exists-consumer-targets' => $targets,
                'exists-consumer-mode' => $mode, 'exists-consumer-surveys' => $surveys];
    }
    /** A module: 149 asks as $user, 300 answers under $rows. */
    function mod($user = 'nurse', array $rows = null, $switch = true) {
        global $DICT_A, $DICT_B, $DATA_A, $DATA_B, $RIGHTS_A, $RIGHTS_B;
        $GLOBALS['__TEST_USER'] = $user;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectIdReturn = 149;
        $m->settingsBy = [149 => ['log-values' => 'raw'], 300 => []];
        $m->subSettingsBy = [
            149 => ['exists-project-aliases' => [['exists-alias' => 'Lab', 'exists-alias-project' => '300']]],
            300 => ['exists-consumers' => $rows === null ? [consent()] : $rows],
            // 301 (module not enabled) and 302 (deleted) would agree: only those
            // facts refuse them.
            301 => ['exists-consumers' => [consent()]],
            302 => ['exists-consumers' => [consent()]],
        ];
        if ($switch) $m->systemSettings['exists-system-cross-project'] = true;
        \REDCap::$dictionaries = [149 => $DICT_A, 300 => $DICT_B, 301 => $DICT_B, 302 => $DICT_B];
        \REDCap::$data = [149 => $DATA_A, 300 => $DATA_B, 301 => $DATA_B, 302 => $DATA_B];
        $users = ['nurse', 'outsider', 'lapsed', 'grouped', 'nogroup', 'nolab', 'admin'];
        \REDCap::$rightsBy = [149 => array_fill_keys($users, $RIGHTS_A), 300 => [
            'nurse'   => $RIGHTS_B,
            'lapsed'  => ['expiration' => date('Y-m-d')] + $RIGHTS_B,
            'grouped' => ['group_id' => 51] + $RIGHTS_B,
            'nogroup' => ['group_id' => 99] + $RIGHTS_B,
            'nolab'   => ['forms' => ['lab' => '0']] + $RIGHTS_B,
        ]];
        \REDCap::reset();
        return $m;
    }
    function page($m, $ctx = 'form') {
        ob_start();
        if ($ctx === 'survey') $m->redcap_survey_page_top(149, '1', 'result', 351, null, 'hash', null, 1);
        else $m->redcap_data_entry_form_top(149, '1', 'result', 351, null, 1);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return json_decode(isset($mm[1]) ? $mm[1] : 'null', true);
    }
    function ruleFor($cfg, $f) {
        foreach ((isset($cfg['rules']) ? $cfg['rules'] : []) as $r) {
            if (in_array($f, isset($r['fields']) ? $r['fields'] : [], true)) return $r;
        }
        return null;
    }
    function errOf($cfg, $f) { $r = ruleFor($cfg, $f); return (string) ($r['configError'] ?? ''); }
    /** Staff call, as the user the module was made for (the framework user and $user_id agree). */
    function ask($m, $field, array $values) {
        return $m->redcap_module_ajax('exists-check', ['field' => $field, 'values' => $values],
            149, '1', 'result', 351, 1, null, null, null, '', '', $GLOBALS['__TEST_USER'], null);
    }
    function askSurvey($m, $field, array $values) {
        return $m->redcap_module_ajax('exists-check', ['field' => $field, 'values' => $values],
            149, '1', 'result', 351, 1, 'hash', null, null, '', '', null, null);
    }
    function readsOf($pid) { return array_values(array_filter(\REDCap::$calls, function ($c) use ($pid) { return $c['_pid'] === $pid; })); }
    function logsOf($m, $msg) {
        return array_values(array_map(function ($c) { return $c[1]; },
            array_filter($m->logCalls, function ($c) use ($msg) { return $c[0] === $msg; })));
    }
    $UNAVAILABLE = function ($pid) { return 'project ' . $pid . ' cannot be searched from this project.'; };

    // ---- 1) grammar -----------------------------------------------------------------
    $parse = function ($ann) { $r = AnnotationRules::parseAllTags($ann); return $r ? $r[0] : null; };
    $r = $parse(ux('{"in":"[specimen_id]","project":300,"match":{"site_code":"[site]"}}'));
    check('grammar: a project id is kept as text', ($r['existsProject'] ?? null) === '300');
    check('grammar: the searched fields are the other project\'s', ($r['existsRemoteTargets'] ?? null) === ['specimen_id', 'site_code']
        && !isset($r['existsTargets']));
    check('grammar: the match field here is still a field of this record', ($r['existsLocal'] ?? null) === ['site']);
    check('grammar: an alias is kept lower-case', ($parse(ux('{"in":"record","project":"Lab-2"}'))['existsProject'] ?? null) === 'lab-2');
    foreach (['true', '0', '12.5', '"1lab"', '""', '"a b"', '[300]'] as $bad) {
        $e = $parse(ux('{"in":"record","project":' . $bad . '}'));
        check('grammar: "project":' . $bad . ' is refused', isset($e['error']) && strpos($e['error'], '"project" must be') !== false);
    }
    foreach (['dag', 'event'] as $sc) {
        $e = $parse(ux('{"in":"[specimen_id]","project":300,"scope":"' . $sc . '"}'));
        check('grammar: "scope":"' . $sc . '" with "project" is refused', isset($e['error'])
            && strpos($e['error'], 'not shared between projects') !== false);
    }
    $e = $parse(ux('{"in":"[specimen_id]","project":300,"event":"lab_arm_1"}'));
    check('grammar: "event" names an event of the other project', !isset($e['error']) && ($e['existsEvent'] ?? null) === 'lab_arm_1');

    // ---- 2) annotation: switch, alias, agreement, then the other project's dictionary ----
    $m = mod('nurse', null, false);
    $cfg = page($m);
    check('switch off: a cross-project rule is a setup error', strpos(errOf($cfg, 'x_spec'), 'turned off on this REDCap server') !== false);
    check('switch off: the other project is not touched', !readsOf(300) && !in_array(300, \REDCap::$ddCalls, true)
        && !array_filter($m->settingReads, function ($s) { return $s[0] === 300; }));

    $m = mod();
    $cfg = page($m);
    foreach (['x_spec', 'x_alias', 'x_rec', 'x_m', 'x_ev', 'x_date'] as $f) {
        check('annotation: ' . $f . ' is set up', ruleFor($cfg, $f) !== null && errOf($cfg, $f) === '');
    }
    $x = ruleFor($cfg, 'x_spec');
    foreach (['existsProject', 'existsPid', 'existsRemoteTargets', 'existsIn', 'existsTargets'] as $k) {
        check('page config: ' . $k . ' never reaches the page', !array_key_exists($k, $x));
    }
    foreach (['bad_nomod' => 301, 'bad_gone' => 302, 'bad_none' => 999, 'bad_secret' => 300] as $f => $pid) {
        check('one message for every refusal: ' . $f, strpos(errOf($cfg, $f), $UNAVAILABLE($pid)) !== false);
    }
    check('refusal: the message does not say why', strpos(errOf($cfg, 'bad_secret'), 'secret_code') === false
        && strpos(errOf($cfg, 'bad_nomod'), 'enabled there') === false);
    check('alias: an unknown alias names this project\'s setting', strpos(errOf($cfg, 'bad_alias'), '"project":"nolab" is not an alias') !== false);
    check('alias: this project is refused', strpos(errOf($cfg, 'bad_self'), 'names this project') !== false);
    check('after the agreement: a field missing there is named', strpos(errOf($cfg, 'bad_ghost'), '"ghost" is not a field of project 300') !== false);
    check('after the agreement: date kinds are compared across projects', strpos(errOf($cfg, 'bad_family'), 'holds dates and "bad_family" holds no date or time') !== false);
    check('"event": an unknown event there gets the one message, not a list of its events',
        strpos(errOf($cfg, 'bad_event'), $UNAVAILABLE(300)) !== false && strpos(errOf($cfg, 'bad_event'), 'nowhere') === false);
    check('after the agreement: the match field here must exist', strpos(errOf($cfg, 'bad_local'), '"nope" is not a field in this project') !== false);
    check('rights agreement: "surveys" is refused', strpos(errOf($cfg, 'x_s'), 'does not answer survey respondents') !== false);
    check('rights agreement: an Identifier there may be searched', errOf($cfg, 'x_donor') === '');

    // No read of the other project's dictionary or data until its agreement passes.
    $DA = $DICT_A;
    $only = ['record_id' => $DA['record_id'], 'site' => $DA['site'], 'bad_secret' => $DA['bad_secret'], 'bad_nomod' => $DA['bad_nomod']];
    $m = mod();
    \REDCap::$dictionaries[149] = $only;
    page($m);
    check('refusal: no dictionary of the other project is read', !array_intersect([300, 301], \REDCap::$ddCalls));
    check('refusal: no data of the other project is read', !readsOf(300) && !readsOf(301));

    $cfgFor = function ($rows) { return page(mod('nurse', $rows)); };
    check('no row for this project: refused', strpos(errOf($cfgFor([consent('rights', 'specimen_id', false, '150')]), 'x_spec'), $UNAVAILABLE(300)) !== false);
    check('two rows for this project: refused', strpos(errOf($cfgFor([consent(), consent()]), 'x_spec'), $UNAVAILABLE(300)) !== false);
    check('an unknown mode: refused', strpos(errOf($cfgFor([consent('everyone')]), 'x_spec'), $UNAVAILABLE(300)) !== false);
    check('a target list with a bad name: refused', strpos(errOf($cfgFor([consent('rights', 'specimen_id, 1bad')]), 'x_spec'), $UNAVAILABLE(300)) !== false);
    check('a record-ID lookup needs "record" listed', strpos(errOf($cfgFor([consent('rights', 'specimen_id')]), 'x_rec'), $UNAVAILABLE(300)) !== false);
    check('match targets must be listed too', strpos(errOf($cfgFor([consent('rights', 'specimen_id')]), 'x_m'), $UNAVAILABLE(300)) !== false);
    check('a blank mode means rights', errOf($cfgFor([consent('')]), 'x_spec') === ''
        && strpos(errOf($cfgFor([consent('')]), 'x_s'), 'does not answer survey') !== false);
    $cfg = $cfgFor([consent('answer', 'specimen_id, site_code, spec_date, record, donor_name', true)]);
    check('answer + surveys agreement: "surveys" is allowed', errOf($cfg, 'x_s') === '');
    check('answer agreement: an Identifier there is refused', strpos(errOf($cfg, 'x_donor'), '"donor_name" of project 300 is an Identifier there') !== false);
    $cfg = $cfgFor([consent('rights', 'specimen_id', true)]);
    check('"surveys" in a rights agreement counts for nothing', strpos(errOf($cfg, 'x_s'), 'does not answer survey') !== false);
    $cfg = page(mod());
    $sv = page(mod(), 'survey');
    check('survey page: a cross-project setup error is generic', errOf($sv, 'x_s') !== '' && strpos(errOf($sv, 'x_s'), 'project') === false);

    // ---- 3) the endpoint under "rights" ------------------------------------------------
    $m = mod();
    $r = ask($m, 'x_spec', ['x_spec' => 'SP-1']);
    check('rights: found', ($r['state'] ?? null) === 'found');
    check('rights: the record there is never echoed', array_key_exists('record', $r) && $r['record'] === null);
    $r = ask($m, 'x_spec', ['x_spec' => 'SP-404']);
    check('rights: not found', ($r['state'] ?? null) === 'not-found');
    check('rights: the reads go to the other project', (bool) readsOf(300) && !array_filter(\REDCap::$calls, function ($c) {
        return $c['_pid'] === 149 && in_array('specimen_id', $c['fields'] ?? [], true); }));
    $r = ask(mod(), 'x_rec', ['x_rec' => 'L2']);
    check('rights: a record ID there is found, and not echoed', ($r['state'] ?? null) === 'found' && $r['record'] === null);
    check('rights: a missing record ID is not found', (ask(mod(), 'x_rec', ['x_rec' => 'L9'])['state'] ?? null) === 'not-found');
    check('rights: match uses this record\'s value', (ask(mod(), 'x_m', ['x_m' => 'SP-1'])['state'] ?? null) === 'found'
        && (ask(mod(), 'x_m', ['x_m' => 'SP-2'])['state'] ?? null) === 'not-found');
    check('rights: a match value sent by the page counts', (ask(mod(), 'x_m', ['x_m' => 'SP-2', 'site' => 'B'])['state'] ?? null) === 'found');
    check('rights: "event" stays in that event there', (ask(mod(), 'x_ev', ['x_ev' => 'SP-1'])['state'] ?? null) === 'found'
        && (ask(mod(), 'x_ev', ['x_ev' => 'SP-EV2'])['state'] ?? null) === 'not-found');
    check('rights: a date typed as shown is looked up as stored', (ask(mod(), 'x_date', ['x_date' => '01-03-2026'])['state'] ?? null) === 'found');

    $m = mod('outsider');
    $r = ask($m, 'x_spec', ['x_spec' => 'SP-1']);
    check('rights: no rights row there is unknown, whatever getUserRights() says', ($r['state'] ?? null) === 'unknown'
        && strpos($r['why'], 'no rights in the project') === false && strpos($r['why'], 'do not have rights in the project this lookup searches') !== false);
    check('rights: ...and the other project is not read', !readsOf(300));
    $r = ask(mod('lapsed'), 'x_spec', ['x_spec' => 'SP-1']);
    check('rights: rights that expire today are none', ($r['state'] ?? null) === 'unknown');
    $r = ask(mod('nolab'), 'x_spec', ['x_spec' => 'SP-1']);
    check('rights: no access to the form there is unknown', ($r['state'] ?? null) === 'unknown' && strpos($r['why'], 'every form') !== false);
    check('rights: a record-ID lookup needs no form there', (ask(mod('nolab'), 'x_rec', ['x_rec' => 'L1'])['state'] ?? null) === 'found');
    check('rights: an administrator needs no rights row', (ask(mod('admin'), 'x_spec', ['x_spec' => 'SP-2'])['state'] ?? null) === 'found');
    $m = mod('grouped');
    check('rights: a user in a group there is confined to it (found inside)', (ask($m, 'x_spec', ['x_spec' => 'SP-1'])['state'] ?? null) === 'found');
    check('rights: ...and a value of another group there is not found', (ask($m, 'x_spec', ['x_spec' => 'SP-2'])['state'] ?? null) === 'not-found');
    $r = ask(mod('nogroup'), 'x_spec', ['x_spec' => 'SP-1']);
    check('rights: a group there that cannot be named is unknown', ($r['state'] ?? null) === 'unknown' && strpos($r['why'], 'could not be read') !== false);
    $r = ask(mod('nogroup'), 'x_spec', ['x_spec' => 'SP-1']);
    $m = mod(); \REDCap::$emptyFor = [300];
    $r = ask($m, 'x_spec', ['x_spec' => 'SP-404']);
    check('an empty read there is never "not found"', ($r['state'] ?? null) === 'unknown' && strpos($r['why'], 'showed no records') !== false);
    $m = mod('grouped'); \REDCap::$confineTo = [300 => 'lab_south'];
    $r = ask($m, 'x_spec', ['x_spec' => 'SP-404']);
    check('confined: a read that shows none of the caller\'s group there is unknown', ($r['state'] ?? null) === 'unknown');
    $m = mod(); \REDCap::$failFor = [300];
    check('a failed read there is unknown', (ask($m, 'x_spec', ['x_spec' => 'SP-1'])['state'] ?? null) === 'unknown');
    $r = ask(mod('stranger'), 'x_spec', ['x_spec' => 'SP-1']);
    check('rights here are still needed', ($r['state'] ?? null) === 'unknown' && strpos($r['why'], 'every form this lookup reads') !== false);

    // ---- 4) the endpoint under "answer" -------------------------------------------------
    $ANS = [consent('answer', 'specimen_id, site_code, record')];
    $m = mod('outsider', $ANS);
    check('answer: a user with no rights there gets found', (ask($m, 'x_spec', ['x_spec' => 'SP-2'])['state'] ?? null) === 'found');
    check('answer: ...and not found', (ask($m, 'x_spec', ['x_spec' => 'SP-404'])['state'] ?? null) === 'not-found');
    $m = mod('grouped', $ANS);
    check('answer: a user in a group there is not confined', (ask($m, 'x_spec', ['x_spec' => 'SP-2'])['state'] ?? null) === 'found');
    $m = mod('grouped', $ANS); \REDCap::$confineTo = [300 => 'lab_north'];
    $r = ask($m, 'x_spec', ['x_spec' => 'SP-2']);
    check('answer: a read that shows only the caller\'s group there is not "not found"', ($r['state'] ?? null) === 'unknown'
        && strpos($r['why'], 'another Data Access Group') !== false);
    $m = mod('outsider', [consent('answer', 'specimen_id, donor_name')]);
    $rf = new \ReflectionMethod($m, 'crossLookup'); $rf->setAccessible(true);
    $r = $rf->invoke($m, 149, ['type' => 'exists', 'existsIn' => 'donor_name', 'existsPid' => 300, 'existsRemoteTargets' => ['donor_name']],
        'Ada', [], 'staff');
    check('answer: an Identifier there is refused at run time too', $r['state'] === 'unknown' && strpos($r['why'], 'Identifier') !== false);

    // ---- 5) surveys -----------------------------------------------------------------------
    $SV = [consent('answer', 'specimen_id', true)];
    $m = mod(null, $SV);
    $r = askSurvey($m, 'x_s', ['x_s' => 'SP-1']);
    check('survey: answered under answer + surveys', ($r['state'] ?? null) === 'found' && $r['record'] === null && ($r['why'] ?? null) === null);
    check('survey: not found too', (askSurvey(mod(null, $SV), 'x_s', ['x_s' => 'SP-404'])['state'] ?? null) === 'not-found');
    check('survey: a rule without "surveys" is not answered', isset(askSurvey(mod(null, $SV), 'x_spec', ['x_spec' => 'SP-1'])['error']));
    $r = askSurvey(mod(null, [consent('answer', 'specimen_id', false)]), 'x_s', ['x_s' => 'SP-1']);
    check('survey: not answered when the other project does not allow it', !isset($r['state']) || $r['state'] !== 'found');
    $m = mod(null, $SV);
    askSurvey($m, 'x_s', ['x_s' => 'SP-1']);
    $dd = new \ReflectionProperty($m, 'ddCache'); $dd->setAccessible(true);
    $cache = $dd->getValue($m); $cache[300]['specimen_id']['identifier'] = 'y'; $dd->setValue($m, $cache);
    $ids = new \ReflectionProperty($m, 'crossIds'); $ids->setAccessible(true); $ids->setValue($m, []);
    $r = askSurvey($m, 'x_s', ['x_s' => 'SP-1']);
    check('survey: an Identifier there is refused at run time', ($r['state'] ?? null) === 'unknown' && ($r['why'] ?? null) === null);

    // A record-ID lookup there reads that project's record IDs, not this one's:
    // this project's record-ID field being an Identifier does not matter, the
    // other project's does.
    $SVR = [consent('answer', 'specimen_id, record', true)];
    $m = mod('nurse', $SVR);
    \REDCap::$dictionaries[149]['record_id']['identifier'] = 'y';
    check('survey: this project\'s record-ID flag does not refuse a lookup of the other project\'s', errOf(page($m), 'x_srec') === '');
    $m = mod(null, $SVR);
    \REDCap::$dictionaries[149]['record_id']['identifier'] = 'y';
    $r = askSurvey($m, 'x_srec', ['x_srec' => 'L1']);
    check('survey: ...and it is answered', ($r['state'] ?? null) === 'found');
    $m = mod('nurse', $SVR);
    \REDCap::$dictionaries[300]['record_id']['identifier'] = 'y';
    check('survey: the other project\'s record-ID flag refuses it', strpos(errOf(page($m), 'x_srec'), 'is an Identifier there') !== false);

    // ---- 6) the probe log in the other project -------------------------------------------
    $m = mod();
    ask($m, 'x_spec', ['x_spec' => 'SP-1']);
    $p = logsOf($m, 'uv-exists-probe');
    check('probe log: one line per lookup', count($p) === 1);
    check('probe log: in the other project, naming this one', ($p[0]['project_id'] ?? null) === 300 && ($p[0]['source_project'] ?? null) === '149');
    check('probe log: channel, user, field, result', ($p[0]['channel'] ?? null) === 'staff' && ($p[0]['user'] ?? null) === 'nurse'
        && ($p[0]['field'] ?? null) === 'specimen_id' && ($p[0]['result'] ?? null) === 'found');
    check('probe log: the value only as a keyed hash', isset($p[0]['value_hash']) && strlen($p[0]['value_hash']) === 64
        && strpos(json_encode($m->logCalls), 'SP-1') === false);
    $m = mod(); $m->settingsBy[300]['log-values'] = 'none';
    ask($m, 'x_spec', ['x_spec' => 'SP-1']);
    $p = logsOf($m, 'uv-exists-probe');
    check('probe log: no value under the other project\'s "none" mode', count($p) === 1 && !isset($p[0]['value_hash']));
    $m = mod(); $m->settingsBy[300]['log-values'] = 'raw';
    ask($m, 'x_spec', ['x_spec' => 'SP-1']);
    check('probe log: never the raw value, even under "raw" there', strpos(json_encode($m->logCalls), 'SP-1') === false);
    $m = mod('outsider');
    ask($m, 'x_spec', ['x_spec' => 'SP-1']);
    $p = logsOf($m, 'uv-exists-probe');
    check('probe log: a refusal is logged', count($p) === 1 && $p[0]['result'] === 'refused' && $p[0]['user'] === 'outsider');
    $m = mod(null, $SV);
    askSurvey($m, 'x_s', ['x_s' => 'SP-1']);
    $p = logsOf($m, 'uv-exists-probe');
    check('probe log: a survey lookup is logged as such', count($p) === 1 && $p[0]['channel'] === 'survey' && $p[0]['user'] === 'survey');
    $hk = new \ReflectionMethod($m, 'hashedIdentifier'); $hk->setAccessible(true);
    check('probe log: the hash is keyed to the other project', $p[0]['value_hash'] === $hk->invoke($m, 300, 'sp-1')
        && $p[0]['value_hash'] !== $hk->invoke($m, 149, 'sp-1'));
    check('probe log: a lookup that ignores letter case hashes the value in lower case, and says so',
        ($p[0]['case'] ?? null) === 'ignored');
    // The record being edited belongs to this project: a record of that project
    // with the same ID is still found.
    $m = mod();
    $r = $m->redcap_module_ajax('exists-check', ['field' => 'x_rec', 'values' => ['x_rec' => 'L1']],
        149, 'L1', 'result', 351, 1, null, null, null, '', '', $GLOBALS['__TEST_USER'], null);
    check('record lookup there: a record with the ID of the record being edited here is found',
        ($r['state'] ?? null) === 'found');
    $m = mod('nurse', null, false);
    ask($m, 'x_spec', ['x_spec' => 'SP-1']);
    check('probe log: nothing is written with the switch off', !logsOf($m, 'uv-exists-probe'));
    // A rule set up earlier in the request, then the switch or the module gone:
    // the lookup is refused, and nothing is written where nothing may be asked.
    $direct = function ($m, $pid) {
        $rf = new \ReflectionMethod($m, 'crossLookup'); $rf->setAccessible(true);
        return $rf->invoke($m, 149, ['type' => 'exists', 'existsIn' => 'specimen_id', 'existsPid' => $pid,
            'existsRemoteTargets' => ['specimen_id']], 'SP-1', [], 'staff');
    };
    $m = mod('nurse', null, false);
    $r = $direct($m, 300);
    check('switch off: a direct lookup is refused and not logged there', $r['state'] === 'unknown' && !logsOf($m, 'uv-exists-probe') && !readsOf(300));
    $m = mod();
    $r = $direct($m, 301);
    check('module not enabled there: refused and not logged there', $r['state'] === 'unknown' && !logsOf($m, 'uv-exists-probe') && !readsOf(301));
    $m = mod();
    $m->subSettingsBy[300]['exists-consumers'] = [consent('rights', 'site_code')];
    $r = $direct($m, 300);
    $p = logsOf($m, 'uv-exists-probe');
    check('agreement withdrawn: refused, and the refusal is logged there', $r['state'] === 'unknown' && count($p) === 1
        && $p[0]['result'] === 'refused' && !readsOf(300));

    // ---- 7) budgets ------------------------------------------------------------------------
    $m = mod(); $m->systemSettings['exists-system-cross-project-per-minute'] = '2';
    $states = [];
    for ($i = 0; $i < 3; $i++) $states[] = ask($m, 'x_spec', ['x_spec' => 'SP-1'])['state'] ?? null;
    check('budget: the other project answers its limit per minute', $states === ['found', 'found', 'unknown']);
    $p = logsOf($m, 'uv-exists-probe');
    check('budget: an over-budget lookup is logged as throttled', end($p)['result'] === 'throttled');
    check('budget: it is counted in tier 3 for the other project', (bool) array_filter(array_keys($m->rateBuckets), function ($k) {
        list($pid, $b) = explode('|', $k);
        return $pid === '300' && ((int) $b) % \INSPIRE\UniversalValidator\UniversalValidator::RATE_TIERS === 3; }));
    foreach (['abc', '0', '-5', ''] as $bad) {
        $m = mod(); $m->systemSettings['exists-system-cross-project-per-minute'] = $bad;
        check('budget: a setting of ' . json_encode($bad) . ' keeps the default', (ask($m, 'x_spec', ['x_spec' => 'SP-1'])['state'] ?? null) === 'found');
    }
    $m = mod(); $m->queryThrows = true;
    $r = ask($m, 'x_spec', ['x_spec' => 'SP-1']);
    check('budget: a counter that cannot be kept fails closed', ($r['state'] ?? null) === 'unknown' && !readsOf(300));
    @session_start();
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        $m = mod(); $m->systemSettings['exists-system-cross-user-per-minute'] = '3';
        $states = [];
        for ($i = 0; $i < 4; $i++) $states[] = ask($m, 'x_spec', ['x_spec' => 'SP-1'])['state'] ?? null;
        check('budget: one session gets its limit per minute there', $states === ['found', 'found', 'found', 'unknown']);
        $_SESSION = [];
    } else {
        check('budget: a session could be started in this runtime', false);
    }

    // ---- 8) the post-save audit -------------------------------------------------------------
    $m = mod();
    \REDCap::$data[149]['1'][351] += ['x_spec' => 'SP-404', 'x_rec' => 'L1'];
    $m->redcap_save_record(149, '1', 'result', 351, null, null, null, 1);
    $inv = array_values(array_filter(logsOf($m, 'invalid-id-saved'), function ($e) { return $e['field'] === 'x_spec'; }));
    check('audit: not found is logged here as type exists', count($inv) === 1 && $inv[0]['type'] === 'exists' && $inv[0]['reason'] === 'not-found'
        && !isset($inv[0]['project_id']));
    check('audit: found is not logged', !array_filter(logsOf($m, 'invalid-id-saved'), function ($e) { return $e['field'] === 'x_rec'; }));
    check('audit: each lookup is logged there as an audit', count(array_filter(logsOf($m, 'uv-exists-probe'), function ($p) {
        return $p['channel'] === 'audit' && $p['project_id'] === 300; })) === 2);
    $m = mod('outsider');
    \REDCap::$data[149]['1'][351] += ['x_spec' => 'SP-404'];
    $m->redcap_save_record(149, '1', 'result', 351, null, null, null, 1);
    check('audit: a saving user with no rights there is a rule problem, not a pass', (bool) array_filter(logsOf($m, 'uvalidate-unconfigurable'),
        function ($e) { return strpos($e['fields'], 'x_spec') !== false && strpos($e['why'], 'lookup-unavailable') !== false
            && strpos($e['why'], 'of project 300') !== false; }));
    $m = mod(null);
    \REDCap::$data[149]['1'][351] += ['x_spec' => 'SP-404'];
    $m->redcap_save_record(149, '1', 'result', 351, null, null, null, 1);
    check('audit: a save with no user under "rights" is a rule problem', (bool) array_filter(logsOf($m, 'uvalidate-unconfigurable'),
        function ($e) { return strpos($e['fields'], 'x_spec') !== false; }) && !logsOf($m, 'invalid-id-saved'));
    $m = mod(null, $SV);
    \REDCap::$data[149]['1'][351] += ['x_spec' => 'SP-404'];
    $m->redcap_save_record(149, '1', 'result', 351, null, null, null, 1);
    check('audit: a survey save is checked under answer + surveys', (bool) array_filter(logsOf($m, 'invalid-id-saved'),
        function ($e) { return $e['field'] === 'x_spec' && $e['reason'] === 'not-found'; }));

    // ---- 9) the scan --------------------------------------------------------------------------
    $scan = function ($m, $dag = null) { return $m->scanProject(149, $dag, 200, null, ['valueCeiling' => 'raw']); };
    $m = mod();
    \REDCap::$data[149]['1'][351] += ['x_spec' => 'SP-404', 'x_rec' => 'L1'];
    \REDCap::$data[149]['2'] = [351 => ['record_id' => '2', 'site' => 'B', 'x_spec' => 'SP-2', 'x_rec' => 'L7']];
    $res = $scan($m);
    $hits = [];
    foreach ($res['violations'] as $v) if ($v['type'] === 'exists') $hits[] = $v['record'] . '/' . $v['field'];
    sort($hits);
    check('scan: values not found there are reported', $hits === ['1/x_spec', '2/x_rec']);
    $idx = array_filter(readsOf(300), function ($c) { return empty($c['filterLogic']) && ($c['fields'] ?? null) === ['specimen_id']; });
    check('scan: the other project is read once per searched field', count($idx) === 1);
    check('scan: no per-record lookups there', !array_filter(readsOf(300), function ($c) { return !empty($c['filterLogic']); }));
    $ir = logsOf($m, 'uv-exists-index-read');
    check('scan: each read there leaves one log line there', count($ir) >= 1 && $ir[0]['project_id'] === 300
        && $ir[0]['source_project'] === '149' && $ir[0]['result'] === 'read');
    check('scan: no probe line per record', !logsOf($m, 'uv-exists-probe'));
    $m = mod('grouped');
    \REDCap::$data[149]['1'][351] += ['x_spec' => 'SP-404'];
    $res = $scan($m);
    $un = array_filter($res['unconfigurable'], function ($u) { return in_array('x_spec', $u['fields'], true); });
    check('scan: a user in a group there gets the rule reported, not evaluated', (bool) array_filter($un, function ($u) {
        return strpos($u['why'], 'Data Access Group of project 300') !== false; }) && !readsOf(300));
    $m = mod('outsider');
    \REDCap::$data[149]['1'][351] += ['x_spec' => 'SP-404'];
    $res = $scan($m);
    check('scan: a user with no rights there gets the rule reported', (bool) array_filter($res['unconfigurable'], function ($u) {
        return in_array('x_spec', $u['fields'], true) && strpos($u['why'], 'NOT evaluated') !== false; }));
    $m = mod(); \REDCap::$emptyFor = [300];
    \REDCap::$data[149]['1'][351] += ['x_spec' => 'SP-404'];
    $res = $scan($m);
    $unavailable = function ($res) { return (bool) array_filter($res['unconfigurable'], function ($u) {
        return in_array('x_spec', $u['fields'], true) && strpos($u['why'], 'lookup-unavailable') !== false; }); };
    check('scan: an empty read there is never "not found"', !array_filter($res['violations'], function ($v) { return $v['type'] === 'exists'; })
        && $unavailable($res));
    check('scan: ...and is not followed by a second, uncounted read of the record IDs there', count(readsOf(300)) === 1);
    $m = mod(); $m->systemSettings['exists-system-cross-project-per-minute'] = '1';
    \REDCap::$data[149]['1'][351] += ['x_spec' => 'SP-404', 'x_rec' => 'L1'];
    $res = $scan($m);
    $ir = logsOf($m, 'uv-exists-index-read');
    check('scan: a read over the budget there is not made, and says so', (bool) array_filter($ir, function ($l) { return $l['result'] === 'throttled'; })
        // The first index (specimen_id) spends the budget of 1; the record-ID
        // index is then refused: x_rec is reported, and only the first index
        // reached the other project, with its two reads (the record IDs, then
        // the searched field by record). Its "not found" rests on those reads:
        // the scan makes no further, uncounted read there.
        && (bool) array_filter($res['unconfigurable'], function ($u) {
            return in_array('x_rec', $u['fields'], true) && strpos($u['why'], 'lookup-unavailable') !== false; })
        && count(readsOf(300)) === 2);
    $m = mod();
    \REDCap::$data[149]['1'][351] += ['x_spec' => 'SP-404', 'redcap_data_access_group' => 'north'];
    $res = $scan($m, 7);
    check('group scan here: a rule that searches another project is still evaluated',
        !array_filter($res['unconfigurable'], function ($u) { return in_array('x_spec', $u['fields'], true); })
        && (bool) array_filter($res['violations'], function ($v) { return $v['field'] === 'x_spec'; }));
    $plan = new \ReflectionMethod($m, 'scanPlan'); $plan->setAccessible(true);
    $p = $plan->invoke($m, 149, []);
    check('scan plan: the other project\'s fields are not this project\'s read set',
        !isset($p['readSet']['specimen_id']) && !isset($p['ownership']['specimen_id']));

    // ---- 10) the Configure dialog --------------------------------------------------------------
    $v = function ($settings, $pid = 300) {
        $m = mod(); $m->projectIdReturn = $pid;
        return (string) $m->validateSettings($settings + ['rules' => []]);
    };
    check('dialog: a complete agreement row saves', $v(['exists-consumer-project' => ['149'], 'exists-consumer-targets' => ['specimen_id, record'],
        'exists-consumer-mode' => ['rights'], 'exists-consumer-surveys' => [false]]) === '');
    check('dialog: a blank row is ignored', $v(['exists-consumer-project' => [''], 'exists-consumer-targets' => [''],
        'exists-consumer-mode' => [''], 'exists-consumer-surveys' => [false]]) === '');
    $e = $v(['exists-consumer-project' => ['149', '149', '300', ''], 'exists-consumer-targets' => ['nope', 'specimen_id', 'specimen_id', 'x'],
        'exists-consumer-mode' => ['answer', 'rights', '', ''], 'exists-consumer-surveys' => [false, true, false, false]]);
    check('dialog: an unknown field is named', strpos($e, 'row 1: "nope" is not a field in this project') !== false);
    check('dialog: a project listed twice is refused', strpos($e, 'row 2: project 149 is listed twice') !== false);
    check('dialog: surveys need the answer mode', strpos($e, 'row 2: survey respondents can be answered only') !== false);
    check('dialog: this project is not listed', strpos($e, 'row 3: this project does not need to be listed') !== false);
    check('dialog: a row needs a project', strpos($e, 'row 4: choose the project that may ask') !== false);
    $e = $v(['exists-consumer-project' => ['149'], 'exists-consumer-targets' => ['donor_name'], 'exists-consumer-mode' => ['answer'],
        'exists-consumer-surveys' => [false]]);
    check('dialog: an Identifier cannot be searched under the answer mode', strpos($e, '"donor_name" is an Identifier') !== false);
    $e = $v(['exists-consumer-project' => ['149'], 'exists-consumer-targets' => [''], 'exists-consumer-mode' => ['rights'],
        'exists-consumer-surveys' => [false]]);
    check('dialog: a row needs fields', strpos($e, 'list the fields that project may search') !== false);
    $e = $v(['exists-alias' => ['lab', 'Lab', '9lab', ''], 'exists-alias-project' => ['300', '300', '300', '301']], 149);
    check('dialog: an alias used twice is refused', strpos($e, 'alias 2: the alias "lab" is set up twice') !== false);
    check('dialog: an alias must start with a letter', strpos($e, 'alias 3: the alias must start with a letter') !== false);
    check('dialog: an alias with a project but no name is refused', strpos($e, 'alias 4: the alias must start with a letter') !== false);
    $e = $v(['exists-alias' => ['me'], 'exists-alias-project' => ['149']], 149);
    check('dialog: an alias for this project is refused', strpos($e, 'stands for this project') !== false);
    $e = $v(['exists-alias' => ['lab'], 'exists-alias-project' => ['']], 149);
    check('dialog: an alias needs a project', strpos($e, 'choose the project the alias stands for') !== false);

    // ---- 11) rights in this project are never rights there ------------------------------------
    $m = mod('outsider');
    $ri = new \ReflectionMethod($m, 'userRightsIn'); $ri->setAccessible(true);
    check('userRightsIn: no row there is null, though getUserRights() lists the form', $ri->invoke($m, 300) === null);
    $uf = new \ReflectionMethod($m, 'userFormRights'); $uf->setAccessible(true);
    check('userFormRights: never answers for another project from getUserRights()', $uf->invoke($m, 300) === null);
    check('userFormRights: still answers for this project', is_array($uf->invoke($m, 149)));

    // ---- 12) review fixes (2026-10-10) ----------------------------------------------------------
    $RT = \INSPIRE\UniversalValidator\UniversalValidator::RATE_TIERS;
    $slot = function ($tier) use ($RT) { return ((int) floor(time() / 60)) * $RT + $tier; };
    $tiersOf = function ($m, $pid) use ($RT) {
        $t = [];
        foreach (array_keys($m->rateBuckets) as $k) {
            list($p, $b) = explode('|', $k);
            if ((int) $p === $pid) $t[((int) $b) % $RT] = true;
        }
        ksort($t);
        return array_keys($t);
    };

    // M1: survey lookups and survey saves are logged there. The framework's
    // log() throws with no signed-in user unless config.json allows it (the
    // stub above does the same), and logCrossProbe swallows that.
    $cfgJson = json_decode(file_get_contents(__DIR__ . '/../config.json'), true);
    check('M1: config.json enables logging with no signed-in user', ($cfgJson['enable-no-auth-logging'] ?? null) === true);
    $m = mod(null, $SV);
    \REDCap::$data[149]['1'][351] += ['x_spec' => 'SP-404'];
    $m->redcap_save_record(149, '1', 'result', 351, null, null, null, 1);
    $p = array_values(array_filter(logsOf($m, 'uv-exists-probe'), function ($l) { return $l['channel'] === 'audit'; }));
    check('M1: a save with no user is logged there, as "(no user)", not "survey"', count($p) >= 1 && $p[0]['user'] === '(no user)');

    // M3: each kind of caller has its own counter there; the audit is not one of the live ones.
    $m = mod('nurse', [consent('answer', 'specimen_id, site_code, record', true)]);
    ask($m, 'x_spec', ['x_spec' => 'SP-1']);
    askSurvey($m, 'x_s', ['x_s' => 'SP-1']);
    \REDCap::$data[149]['1'][351] += ['x_spec' => 'SP-404'];
    $m->redcap_save_record(149, '1', 'result', 351, null, null, null, 1);
    check('M3: staff, survey and audit lookups are counted in tiers 3, 4 and 5', $tiersOf($m, 300) === [3, 4, 5]);
    $m = mod('nurse', [consent('answer', 'specimen_id, site_code, record', true)]);
    $m->rateBuckets['300|' . $slot(4)] = \INSPIRE\UniversalValidator\UniversalValidator::THROTTLE_CROSS_SURVEY;
    $r = askSurvey($m, 'x_s', ['x_s' => 'SP-1']);
    check('M3: a spent survey budget there refuses survey callers', ($r['state'] ?? null) === 'unknown');
    check('M3: ...and staff are still answered', (ask($m, 'x_spec', ['x_spec' => 'SP-1'])['state'] ?? null) === 'found');
    \REDCap::$data[149]['1'][351] += ['x_spec' => 'SP-404'];
    $m->redcap_save_record(149, '1', 'result', 351, null, null, null, 1);
    check('M3: ...and so is the post-save audit', (bool) array_filter(logsOf($m, 'invalid-id-saved'),
        function ($e) { return $e['field'] === 'x_spec' && $e['reason'] === 'not-found'; }));
    $m = mod(); $m->systemSettings['exists-system-cross-project-per-minute'] = '2';
    $m->rateBuckets['300|' . $slot(3)] = 2;
    \REDCap::$data[149]['1'][351] += ['x_spec' => 'SP-404'];
    $m->redcap_save_record(149, '1', 'result', 351, null, null, null, 1);
    check('M3: a spent staff budget does not switch the audit off', (bool) array_filter(logsOf($m, 'invalid-id-saved'),
        function ($e) { return $e['field'] === 'x_spec' && $e['reason'] === 'not-found'; }));
    $m = mod(); $m->systemSettings['exists-system-cross-project-per-minute'] = '2';
    for ($i = 0; $i < 6; $i++) ask($m, 'x_spec', ['x_spec' => 'SP-1']);
    $res = array_map(function ($l) { return $l['result']; }, logsOf($m, 'uv-exists-probe'));
    check('M3: one "throttled" line per budget and minute, not one per refusal', $res === ['found', 'found', 'throttled']);
    $m = mod(); $m->queryThrows = true;
    for ($i = 0; $i < 3; $i++) ask($m, 'x_spec', ['x_spec' => 'SP-1']);
    check('M3: a counter that cannot be kept is logged once per request',
        array_map(function ($l) { return $l['result']; }, logsOf($m, 'uv-exists-probe')) === ['throttled']);

    // Correctness 1: the audit never confines the lookup to the saver's group there.
    $m = mod('grouped');
    \REDCap::$data[149]['1'][351] += ['x_spec' => 'SP-2'];   // saved there, in lab_south
    $m->redcap_save_record(149, '1', 'result', 351, null, null, null, 1);
    check('C1: a saver in a group there: no "not found" for a value of another group',
        !array_filter(logsOf($m, 'invalid-id-saved'), function ($e) { return $e['field'] === 'x_spec'; }));
    check('C1: ...it is a rule problem instead', (bool) array_filter(logsOf($m, 'uvalidate-unconfigurable'), function ($e) {
        return strpos($e['fields'], 'x_spec') !== false && strpos($e['why'], 'Data Access Group of the other project') !== false; }));
    check('C1: ...logged there as refused, and that project is not read', !readsOf(300)
        && array_map(function ($l) { return $l['result']; }, logsOf($m, 'uv-exists-probe')) === ['refused']);
    check('C1: live, the same user is still confined to their group', (ask(mod('grouped'), 'x_spec', ['x_spec' => 'SP-1'])['state'] ?? null) === 'found');

    // Correctness 2: the audit does not spend the per-session window.
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        $m = mod(); $m->systemSettings['exists-system-cross-user-per-minute'] = '2';
        ask($m, 'x_spec', ['x_spec' => 'SP-1']);
        ask($m, 'x_spec', ['x_spec' => 'SP-1']);
        check('C2: the session window is spent', (ask($m, 'x_spec', ['x_spec' => 'SP-1'])['state'] ?? null) === 'unknown');
        \REDCap::$data[149]['1'][351] += ['x_spec' => 'SP-404'];
        $m->redcap_save_record(149, '1', 'result', 351, null, null, null, 1);
        check('C2: ...and the audit still checks the save', (bool) array_filter(logsOf($m, 'invalid-id-saved'),
            function ($e) { return $e['field'] === 'x_spec' && $e['reason'] === 'not-found'; }));
        $_SESSION = [];
        $m = mod(); $m->systemSettings['exists-system-cross-user-per-minute'] = '2';
        for ($i = 0; $i < 5; $i++) ask($m, 'x_spec', ['x_spec' => 'SP-1']);
        check('C2: a spent session window is logged there once a minute, not once per refusal',
            array_map(function ($l) { return $l['result']; }, logsOf($m, 'uv-exists-probe')) === ['found', 'found', 'throttled']);
        $_SESSION = [];
        $m = mod('nurse', [consent('answer', 'specimen_id, site_code, record', true)]);
        $m->systemSettings['exists-system-cross-user-per-minute'] = '2';
        $r = [];
        for ($i = 0; $i < 3; $i++) $r[] = askSurvey($m, 'x_s', ['x_s' => 'SP-1'])['state'] ?? null;
        check('M3: a survey respondent\'s session has the same window, below the shared survey count', $r === ['found', 'found', 'unknown']
            && ($m->rateBuckets['300|' . $slot(4)] ?? 0) === 2);
        $_SESSION = [];
    } else {
        check('C2: a session could be started in this runtime', false);
    }

    // Correctness 3: another project's record IDs are read only within the budget, and the read is logged.
    $SVR = [consent('answer', 'specimen_id, record', true)];
    $m = mod(null, $SVR);
    $m->rateBuckets['149|' . $slot(2)] = 60;   // the survey full-read budget of this project, spent
    $r = askSurvey($m, 'x_srec', ['x_srec' => 'L9']);
    $pk = array_filter(readsOf(300), function ($c) { return empty($c['records']) && ($c['fields'] ?? null) === ['record_id']; });
    check('C3: a spent survey read budget: no read of the record IDs there', !$pk && ($r['state'] ?? null) === 'unknown');
    $m = mod();
    ask($m, 'x_rec', ['x_rec' => 'L9']);
    $p = logsOf($m, 'uv-exists-probe');
    check('C3: a staff record-ID miss reads the record IDs there, and says so', count($p) === 1 && $p[0]['result'] === 'not-found'
        && ($p[0]['extra_read'] ?? null) === 'record ids');
    $m = mod();
    ask($m, 'x_spec', ['x_spec' => 'SP-404']);
    $p = logsOf($m, 'uv-exists-probe');
    check('C3: a field miss rests on its own full read: no extra read there', !isset($p[0]['extra_read'])
        && !array_filter(readsOf(300), function ($c) { return ($c['fields'] ?? null) === ['record_id']; }));
    $m = mod();
    ask($m, 'x_rec', ['x_rec' => 'L1']);
    check('C3: a record-ID hit makes no extra read', !isset(logsOf($m, 'uv-exists-probe')[0]['extra_read']));

    // Correctness 4: a branched rule is skipped only in its refused branches.
    $m = mod('outsider');
    \REDCap::$dictionaries[149]['x_br'] = f('result', ux('{"in":"[site]","when":"[site]=\'A\'"}') . ' '
        . ux('{"in":"[specimen_id]","project":300,"when":"[site]=\'B\'"}'));
    \REDCap::$data[149]['1'][351] += ['x_br' => '77'];
    \REDCap::$data[149]['2'] = [351 => ['record_id' => '2', 'site' => 'B', 'x_br' => 'SP-1']];
    $res = $scan($m);
    check('C4: the branch that searches this project is still checked', (bool) array_filter($res['violations'],
        function ($v) { return $v['field'] === 'x_br' && $v['record'] === '1'; }));
    check('C4: the refused branch is reported, not checked', (bool) array_filter($res['unconfigurable'], function ($u) {
        return in_array('x_br', $u['fields'], true) && strpos($u['why'], 'this branch looks the value up in another project') !== false; })
        && !array_filter($res['violations'], function ($v) { return $v['field'] === 'x_br' && $v['record'] === '2'; }));
    check('C4: and the other project is not read', !readsOf(300));
    $m = mod('outsider');
    \REDCap::$dictionaries[149]['x_br'] = f('result', ux('{"in":"[specimen_id]","project":300,"when":"[site]=\'A\'"}') . ' '
        . ux('{"in":"record","project":300,"when":"[site]=\'B\'"}'));
    \REDCap::$data[149]['1'][351] += ['x_br' => 'SP-404'];
    $res = $scan($m);
    $un = array_filter($res['unconfigurable'], function ($u) { return in_array('x_br', $u['fields'], true); });
    check('C4: a rule with every branch refused is reported once, as a whole', count($un) === 1
        && strpos(reset($un)['why'], 'this rule looks the value up in another project') !== false
        && !array_filter($res['violations'], function ($v) { return $v['field'] === 'x_br'; }) && !readsOf(300));

    // Correctness 5: the scan says that changes there do not re-open it, whatever its coverage.
    $m = mod();
    $plan = new \ReflectionMethod($m, 'scanPlan'); $plan->setAccessible(true);
    $p = $plan->invoke($m, 149, []);
    check('C5: the limit names the other project on a ' . ($p['policy']['maxCompletion'] ?? '?') . ' scan',
        (bool) array_filter($p['policy']['limits'] ?? [], function ($l) { return strpos($l, 'search project 300') !== false
            && strpos($l, 'do not re-open this scan') !== false; }));

    // Correctness 8: the dialog reads each column by instance, so a gap cannot shift rows.
    $e = $v(['exists-consumer-project' => [0 => '150', 2 => '151'], 'exists-consumer-targets' => [0 => 'specimen_id', 2 => 'nope'],
             'exists-consumer-mode' => [0 => 'rights', 2 => 'rights'], 'exists-consumer-surveys' => [0 => false, 2 => false]]);
    check('C8: a problem in row 3 is reported as row 3', strpos($e, 'row 3: "nope" is not a field') !== false
        && strpos($e, 'row 2') === false && strpos($e, 'row 1') === false);

    // L1: "record" is the record ID; a field of that name cannot be searched or matched.
    foreach (['@UVEXISTS=[record]', ux('{"in":"[record]","project":300}'), ux('{"in":"[specimen_id]","match":{"record":"[site]"}}'),
              ux('{"in":"[specimen_id]","match":{"site_code":"[record]"}}')] as $tag) {
        $e = $parse($tag);
        check('L1: refused: ' . $tag, isset($e['error']) && strpos($e['error'], 'a field named "record" cannot be used') !== false);
    }
    check('L1: checkFragment refuses a "record" match target too', (bool) array_filter(AnnotationRules::checkFragment(
        ['type' => 'exists', 'existsIn' => 'specimen_id', 'existsMatch' => ['record' => 'site']]),
        function ($x) { return strpos($x, 'a field named "record"') !== false; }));
    check('L1: record (no brackets) still means the record ID', ($parse('@UVEXISTS=record')['existsIn'] ?? null) === 'record');

    // L2: a deleted project has a status, and a row with date_deleted: only the row tells.
    $m = mod();
    $cfg = page($m);
    check('L2: a deleted project is refused although it reports a status', $m->getProjectStatus(302) === 'DEV'
        && strpos(errOf($cfg, 'bad_gone'), $UNAVAILABLE(302)) !== false && in_array(302, $m->projectReads, true));
    $m = mod(); $m->queryThrowsAll = true;
    $mn = new \ReflectionMethod($m, 'moduleEnabledIn'); $mn->setAccessible(true);
    check('L2: a project table that cannot be read refuses (fails closed)', $mn->invoke($m, 300) === false);
    $m = mod();
    check('L2: a live project with the module passes', $mn->invoke($m, 300) === true);

    // M2: a stored run is not shown to a reader the other project would not answer.
    class CrossRunModule extends \INSPIRE\UniversalValidator\UniversalValidator {
        public $runRow = null;
        public function query($sql, $params = []) {
            if (strpos($sql, 'FROM ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run')) !== false
                    && strpos($sql, 'WHERE run_id = ?') !== false) {
                return $this->runRow === null ? [] : [$this->runRow];
            }
            return parent::query($sql, $params);
        }
    }
    $runFor = function ($user) {
        $base = mod($user);
        $m = new CrossRunModule();
        foreach (['projectIdReturn', 'settingsBy', 'subSettingsBy', 'systemSettings'] as $k) $m->$k = $base->$k;
        foreach (['nurse', 'outsider'] as $u) {
            \REDCap::$rightsBy[149][$u] = ['design' => '1', 'data_export_tool' => '1'] + \REDCap::$rightsBy[149][$u];
        }
        $m->runRow = ['77', '149', null, 'scanning', null, 'partial', 'complete', 'none', '1', 'fp', '10', '3', '3', '1',
                      '1', '1', 'nurse', '2', '400', '9', '9', null];
        return (new \INSPIRE\UniversalValidator\Scan\ScanService($m))->status(149, 77);
    };
    $st = $runFor('outsider');
    check('M2: a reader with no rights there is refused the run', empty($st['ok'])
        && strpos((string) $st['why'], 'project 300, which does not answer your lookups') !== false);
    $st = $runFor('nurse');
    check('M2: a reader the other project answers sees it', !empty($st['ok']));

    echo "exists_cross_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
