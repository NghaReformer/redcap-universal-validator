<?php
/**
 * writeonce_module_php.php — @UVWRITEONCE through the module.
 *
 * writeonce_php.php pins the verdict and the log reader; this file pins the
 * REDCap glue around them:
 *   - the field hook: calc and checkbox fields are refused by eligibility,
 *     and so is a field with @CALCTEXT, @CALCDATE or @SETVALUE,
 *   - the dictionary hook: every role must be a role of the project, by its
 *     name or its unique role name, in any letter case; roles that cannot be
 *     read refuse nothing,
 *   - a lock on a field another rule returns a value to is refused, naming
 *     that rule,
 *   - the page: a rule reaches the page only with the fields whose saved
 *     value is a value (not blank, not a Missing Data Code), only for a
 *     viewer who holds none of its roles, never on a new record; a survey
 *     respondent holds no role; roles that cannot be read lock nothing; the
 *     roles and "since" never reach the page,
 *   - the post-save audit logs changed / cleared as type 'writeonce' with
 *     locked_at and changed_at, lets a role move or release the lock, honours
 *     "since", and reports an unreadable or capped log, a save the log does
 *     not spell out, a gap, and roles that cannot be read as rule problems,
 *   - the scan finds the same, labels each reason, and its report names the
 *     day the value was locked.
 *
 * Run:  php tests/writeonce_module_php.php
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
    class REDCap {
        public static $data = [];
        public static $dictionary = [];
        public static $rights = [];
        public static $mappings = null;
        public static function getInstrumentEventMappings($pid = null) { return self::$mappings; }
        public static function getData($p) {
            $data = self::$data;
            if (isset($p['records'])) $data = array_intersect_key($data, array_fill_keys(array_map('strval', (array) $p['records']), true));
            return $data;
        }
        public static function getDataDictionary($pid, $f = 'array') {
            if (!$pid) throw new \RuntimeException('needs pid');
            return self::$dictionary;
        }
        public static function getUserRights($pid = null, $user = null) { return self::$rights; }
        public static function getGroupNames($u = false, $g = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
    }
    class Project {
        public static $codes = '';
        public $project;
        public $longitudinal = false;
        public $eventInfo = [351 => ['unique_event_name' => 'event_1_arm_1', 'arm_num' => 1]];
        public $eventsForms = [351 => ['enrol_form', 'visit_form']];
        public function __construct($pid) { $this->project = ['missing_data_codes' => self::$codes]; }
        public function getRepeatingFormsEvents() { return []; }
        public function getUniqueEventNames() { return [351 => 'event_1_arm_1']; }
    }

    require_once __DIR__ . '/../UniversalValidator.php';
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';
    require_once __DIR__ . '/writeonce_fake_log.php';

    use INSPIRE\UniversalValidator\ValueHistory;
    use INSPIRE\UniversalValidator\ModeRegistry;
    use INSPIRE\UniversalValidator\ScanColumns;

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    const PID = 149;
    \Project::$codes = "UNK, Unknown\nNASK, Not asked";
    function f($form, $ann = '', $validation = '', $type = 'text') {
        return ['field_type' => $type, 'form_name' => $form, 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => $validation];
    }
    $RIGHTS = ['nurse' => ['forms' => ['enrol_form' => '1', 'visit_form' => '1']], 'dm' => ['forms' => ['enrol_form' => '1', 'visit_form' => '1']]];
    // The project's roles: "Data manager" (unique name U-3DM), "Monitor"; dm holds Data manager.
    $ROLES = ['roles' => ['data manager' => true, 'u-3dm' => true, 'monitor' => true], 'users' => ['dm' => ['data manager', 'u-3dm']]];

    function setPrivate($m, $prop, $value) {
        $p = new \ReflectionProperty(get_class($m), $prop);
        $p->setAccessible(true);
        $p->setValue($m, $value);
    }
    /** The module as $user, with the dictionary, the data, the log rows ($log: null = no override) and the roles ($roles: false = unreadable). */
    function mod($dict, $data, $user = 'nurse', $log = null, $roles = null) {
        global $RIGHTS, $ROLES;
        $GLOBALS['__TEST_USER'] = $user;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectSettings = ['log-values' => 'raw'];
        $m->projectIdReturn = PID;
        \REDCap::$dictionary = $dict;
        \REDCap::$data = $data;
        \REDCap::$rights = $RIGHTS;
        setPrivate($m, 'roleBookOverride', $roles === null ? $ROLES : $roles);
        if ($log !== null) setPrivate($m, 'valueHistoryOverride', $log);
        return $m;
    }
    /** A ValueHistory over these log rows ([id, pk, event, ts, data_values, user, event type]). */
    function history(array $rows, array $fields) {
        $db = new FakeHistoryLog();
        foreach ($rows as $r) $db->rows[] = [$r[0], PID, $r[1], $r[2], $r[3], $r[4], 'redcap_data', isset($r[6]) ? $r[6] : 'UPDATE', $r[5]];
        $utc = new \DateTimeZone('UTC');
        return ValueHistory::forProject($db, PID, $fields, $utc, $utc);
    }
    function page($m, $ctx, $rec, $form, $evt = 351) {
        ob_start();
        if ($ctx === 'survey') $m->redcap_survey_page_top(PID, $rec, $form, $evt, null, 'hash', null, 1);
        else $m->redcap_data_entry_form_top(PID, $rec, $form, $evt, null, 1);
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
    function errOf($p, $f) { $r = ruleFor($p, $f); return $r && isset($r['configError']) ? (string) $r['configError'] : ''; }
    /** the fields a write-once rule locks on the page */
    function lockedOn($p) {
        $out = [];
        foreach ((isset($p['cfg']['rules']) ? $p['cfg']['rules'] : []) as $r) {
            if (($r['type'] ?? '') === 'writeonce' && empty($r['configError'])) foreach ($r['fields'] as $f) $out[] = $f;
        }
        sort($out);
        return $out;
    }
    function findings($m, $type = 'invalid-id-saved') {
        return array_values(array_map(function ($c) { return $c[1]; },
            array_filter($m->logCalls, function ($c) use ($type) { return $c[0] === $type; })));
    }
    function woBy($m) {
        $by = [];
        foreach (findings($m) as $e) if (($e['type'] ?? '') === 'writeonce') $by[$e['field']] = $e;
        return $by;
    }
    function problems($m, $field) {
        $out = [];
        foreach (findings($m, 'uvalidate-unconfigurable') as $e) {
            if (strpos((string) $e['fields'], $field) !== false) $out[] = (string) $e['why'];
        }
        return $out;
    }

    // ============================================================================
    // The hooks
    // ============================================================================
    $DICT = [
        'record_id'    => f('enrol_form'),
        'consent_date' => f('enrol_form', '@UVWRITEONCE', 'date_ymd'),
        'arm'          => f('enrol_form', '@UVWRITEONCE={"roles":["Data manager"]}', '', 'radio'),
        'site'         => f('enrol_form', '@UVWRITEONCE={"roles":["u-3dm","MONITOR"],"message":"Ask the data manager."}', '', 'dropdown'),
        'baseline_wt'  => f('enrol_form', '@UVWRITEONCE={"since":"2026-10-05"}', 'number'),
        'notes_w'      => f('enrol_form', '@UVWRITEONCE', '', 'notes'),
        'pain'         => f('enrol_form', '@UVWRITEONCE={"roles":["Data manager"],"message":"Pain is locked."}', '', 'slider'),
        'lookup'       => f('enrol_form', '@UVEXISTS={"in":"record","return":{"consent_date":"[ret_lock]"}}'),
        'ret_lock'     => f('enrol_form', '@UVWRITEONCE'),
        'bad_calc'     => f('enrol_form', '@UVWRITEONCE', '', 'calc'),
        'bad_chk'      => f('enrol_form', '@UVWRITEONCE', '', 'checkbox'),
        'bad_calctext' => f('enrol_form', '@UVWRITEONCE @CALCTEXT(if([arm]=1,"a","b"))'),
        'bad_setvalue' => f('enrol_form', "@UVWRITEONCE @SETVALUE='x'"),
        'bad_role'     => f('enrol_form', '@UVWRITEONCE={"roles":["Nobody","Monitor","Ghost"]}'),
        'bad_when'     => f('enrol_form', '@UVWRITEONCE={"when":"[arm]=\'1\'"}'),
        'bad_since'    => f('enrol_form', '@UVWRITEONCE={"since":"2026-02-30"}'),
        'bad_value'    => f('enrol_form', '@UVWRITEONCE=yes'),
        'v_text'       => f('visit_form', '@UVWRITEONCE'),
    ];
    $ROW = ['record_id' => '2', 'consent_date' => '2026-03-01', 'arm' => '1', 'site' => 'UNK', 'baseline_wt' => '',
            'notes_w' => "a note", 'pain' => '40', 'lookup' => '', 'ret_lock' => 'x',
            'bad_calc' => '1', 'bad_chk' => '', 'bad_calctext' => 'a', 'bad_setvalue' => 'x', 'bad_role' => 'x',
            'bad_when' => 'x', 'bad_since' => 'x', 'bad_value' => 'x', 'v_text' => 'v'];
    $DATA = ['2' => [351 => $ROW]];

    $p = page(mod($DICT, $DATA), 'form', '2', 'enrol_form');
    check('calc: refused by eligibility (got ' . errOf($p, 'bad_calc') . ')', strpos(errOf($p, 'bad_calc'), 'does not support "calc" fields') !== false);
    check('checkbox: refused by eligibility', strpos(errOf($p, 'bad_chk'), 'does not support "checkbox" fields') !== false);
    check('@CALCTEXT: refused, naming it (got ' . errOf($p, 'bad_calctext') . ')', strpos(errOf($p, 'bad_calctext'), 'cannot lock a field with @CALCTEXT') !== false);
    check('@SETVALUE: refused, naming it (got ' . errOf($p, 'bad_setvalue') . ')', strpos(errOf($p, 'bad_setvalue'), 'cannot lock a field with @SETVALUE') !== false);
    check('roles the project lacks: refused, naming each and no other, once (got ' . errOf($p, 'bad_role') . ')',
        errOf($p, 'bad_role') === '@UVWRITEONCE on "bad_role": "Nobody", "Ghost" are not roles of this project — use the role name the User Rights page shows, or its unique role name.');
    check('"when": refused (got ' . errOf($p, 'bad_when') . ')', strpos(errOf($p, 'bad_when'), 'when') !== false);
    check('a "since" that is not a date: refused', strpos(errOf($p, 'bad_since'), '"since" must be a date') !== false);
    check('a bare value: refused', strpos(errOf($p, 'bad_value'), 'takes no value') !== false);
    check('roles by unique name and in any letter case: accepted', errOf($p, 'site') === '');
    check('a lock on a field another rule returns to: refused, naming that rule (got ' . errOf($p, 'ret_lock') . ')',
        errOf($p, 'ret_lock') === '@UVWRITEONCE cannot lock "[ret_lock]": the rule on "lookup" returns a value to it, which is written again on every save.');
    check('... and the returning rule itself stands', errOf($p, 'lookup') === '');
    $pu = page(mod($DICT, $DATA, 'nurse', null, false), 'form', '2', 'enrol_form');
    check('roles that cannot be read refuse nothing', errOf($pu, 'bad_role') === '');
    // the grammar: roles, "since", the keys it takes
    $parse = function ($v) { $f = \INSPIRE\UniversalValidator\AnnotationRules::parseAllTags('@UVWRITEONCE=' . $v); return $f[0]; };
    $many = json_encode(['roles' => array_map(function ($i) { return 'r' . $i; }, range(1, 21))]);
    check('grammar: 21 roles refused, at most 20', strpos($parse($many)['error'] ?? '', 'names 21 roles — at most 20') !== false);
    check('grammar: 20 roles taken', count($parse(json_encode(['roles' => array_map(function ($i) { return 'r' . $i; }, range(1, 20))]))['writeonceRoles'] ?? []) === 20);
    check('grammar: a role name past 150 characters refused', strpos($parse(json_encode(['roles' => [str_repeat('a', 151)]]))['error'] ?? '', '1 to 150 characters') !== false);
    check('grammar: a role name with a control character refused', strpos($parse(json_encode(['roles' => ["Data\tmanager"]]))['error'] ?? '', '1 to 150 characters') !== false);
    check('grammar: an empty roles list refused', strpos($parse('{"roles":[]}')['error'] ?? '', 'at least one role') !== false);
    check('grammar: one role per name, whatever the case and spacing', ($parse('{"roles":["PI"," pi ","Pi","Monitor"]}')['writeonceRoles'] ?? null) === ['PI', 'Monitor']);
    check('grammar: roles must be a list of names', strpos($parse('{"roles":"PI"}')['error'] ?? '', 'must be a list of role names') !== false
        && strpos($parse('{"roles":{"a":"PI"}}')['error'] ?? '', 'must be a list of role names') !== false);
    check('grammar: an unknown key refused, naming the valid ones', strpos($parse('{"blockSave":"hard"}')['error'] ?? '', 'valid: roles, since, message') !== false);
    check('grammar: JSON that does not parse', strpos($parse('{roles:1}')['error'] ?? '', 'JSON does not parse') !== false);
    check('grammar: "since" must be a string', strpos($parse('{"since":20261101}')['error'] ?? '', '"since" must be a date') !== false);
    check('grammar: a message in several languages', ($parse('{"message":{"en":"Locked","fr":"Verrouillé"}}')['message'] ?? null) === 'Locked');

    // ============================================================================
    // The page
    // ============================================================================
    check('page: only fields holding a value are locked; blank and a code are not (got ' . json_encode(lockedOn($p)) . ')',
        lockedOn($p) === ['arm', 'consent_date', 'notes_w', 'pain']);
    check('page: the roles and "since" never reach the page', strpos($p['html'], 'writeonceRoles') === false
        && strpos($p['html'], 'writeonceSince') === false && stripos($p['html'], 'u-3dm') === false
        && strpos($p['html'], 'MONITOR') === false && strpos($p['html'], '2026-10-05') === false);
    check('page: the message does', (ruleFor(page(mod($DICT, ['2' => [351 => array_merge($ROW, ['site' => '2'])]]), 'form', '2', 'enrol_form'), 'site')['message'] ?? '') === 'Ask the data manager.');
    $pd = page(mod($DICT, $DATA, 'dm'), 'form', '2', 'enrol_form');
    check('page: a member of a named role is not locked out; a rule with no roles locks everyone (got ' . json_encode(lockedOn($pd)) . ')',
        lockedOn($pd) === ['consent_date', 'notes_w']);
    check('page: roles that cannot be read lock nothing of a rule that names roles (got ' . json_encode(lockedOn($pu)) . ')',
        lockedOn($pu) === ['consent_date', 'notes_w']);
    $ps = page(mod($DICT, $DATA, 'dm'), 'survey', '2', 'enrol_form');
    check('page: a survey respondent holds no role, even on a login a role holds (got ' . json_encode(lockedOn($ps)) . ')',
        lockedOn($ps) === ['arm', 'consent_date', 'notes_w', 'pain']);
    $psu = page(mod($DICT, $DATA, 'dm', null, false), 'survey', '2', 'enrol_form');
    check('page: ... and with roles that cannot be read, where a role nobody checked is not refused either (got ' . json_encode(lockedOn($psu)) . ')',
        lockedOn($psu) === ['arm', 'bad_role', 'consent_date', 'notes_w', 'pain']);
    $pn = page(mod($DICT, $DATA), 'form', '', 'enrol_form');
    check('page: a new record locks nothing', lockedOn($pn) === []);
    $pv = page(mod($DICT, $DATA), 'form', '2', 'visit_form');
    check('page: another form locks its own fields only', lockedOn($pv) === ['v_text']);
    // "since": nothing is locked before the day it names, in the project's clock
    $SINCE = ['2' => [351 => array_merge($ROW, ['baseline_wt' => '70'])]];
    foreach (['2026-10-04' => false, '2026-10-05' => true, '2026-11-30' => true] as $day => $locks) {
        $ms = mod($DICT, $SINCE);
        setPrivate($ms, 'clockOverride', \INSPIRE\UniversalValidator\UniversalValidator::clockStamp(new \DateTimeImmutable($day . ' 08:00:00', new \DateTimeZone('UTC'))));
        check('page: "since" 2026-10-05 on ' . $day . ': ' . ($locks ? 'locked' : 'not locked yet'),
            in_array('baseline_wt', lockedOn(page($ms, 'form', '2', 'enrol_form')), true) === $locks);
    }

    // ============================================================================
    // The audit
    // ============================================================================
    // consent_date: saved, then changed by nurse (no role)       -> changed
    // arm: saved 1, changed to 2 by dm (Data manager)            -> the lock moved, ok
    // site: UNK (a code, no lock), then 2                        -> locked at 2, ok
    // baseline_wt: since 2026-10-05; 70 before it, 72 after     -> changed, locked from 2026-10-05
    // notes_w: saved, then cleared by nurse                      -> cleared
    // pain: saved 40, cleared by dm, then 55 by nurse            -> released, locked again at 55, ok
    $LOG = [
        [1, '2', 351, '20261001090000', "record_id = '2',\nconsent_date = '2026-03-01',\narm = '1',\nsite = 'UNK',\nbaseline_wt = '70',\nnotes_w = 'a note',\npain = '40'", 'nurse', 'INSERT'],
        [2, '2', 351, '20261003101500', "arm = '2',\npain = ''", 'dm'],
        [3, '2', 351, '20261004080000', "site = '2',\npain = '55'", 'nurse'],
        [4, '2', 351, '20261006120000', "consent_date = '2026-04-01',\nbaseline_wt = '72',\nnotes_w = ''", 'nurse'],
    ];
    $FIELDS = ['consent_date', 'arm', 'site', 'baseline_wt', 'notes_w', 'pain'];
    $NOW = ['2' => [351 => array_merge($ROW, ['consent_date' => '2026-04-01', 'arm' => '2', 'site' => '2', 'baseline_wt' => '72',
        'notes_w' => '', 'pain' => '55'])]];
    $m = mod($DICT, $NOW, 'nurse', history($LOG, $FIELDS));
    $m->redcap_save_record(PID, '2', 'enrol_form', 351, null, null, null, 1);
    $by = woBy($m);
    check('audit: a value changed after the lock (got ' . json_encode(array_map(function ($e) { return $e['reason']; }, $by)) . ')',
        ($by['consent_date']['reason'] ?? null) === 'changed');
    check('... logged with when it was locked and when it was changed', ($by['consent_date']['locked_at'] ?? null) === '2026-10-01 09:00'
        && ($by['consent_date']['changed_at'] ?? null) === '2026-10-06 12:00');
    check('audit: a value cleared after the lock', ($by['notes_w']['reason'] ?? null) === 'cleared');
    check('audit: a named role moves the lock', !isset($by['arm']) && !problems($m, 'arm'));
    check('audit: a Missing Data Code locks nothing', !isset($by['site']) && !problems($m, 'site'));
    check('audit: "since" locks the value held on that day', ($by['baseline_wt']['reason'] ?? null) === 'changed'
        && ($by['baseline_wt']['locked_at'] ?? null) === '2026-10-05');
    check('audit: a named role releases the lock by clearing it; the next value locks again', !isset($by['pain']) && !problems($m, 'pain'));
    $keys = array_keys($by);
    sort($keys);
    check('audit: no other field, and nothing of another form (got ' . json_encode($keys) . ')', $keys === ['baseline_wt', 'consent_date', 'notes_w']);

    // a Missing Data Code saved over a locked value is a clearing: the evaluator is given the code (modes.json "answer")
    $CODE = [[1, '2', 351, '20261001090000', "consent_date = '2026-03-01'", 'nurse', 'INSERT'], [2, '2', 351, '20261002090000', "consent_date = 'UNK'", 'nurse']];
    $m = mod($DICT, ['2' => [351 => array_merge($ROW, ['consent_date' => 'UNK'])]], 'nurse', history($CODE, $FIELDS));
    $m->redcap_save_record(PID, '2', 'enrol_form', 351, null, null, null, 1);
    check('audit: a Missing Data Code saved over a locked value is "cleared" (got ' . json_encode(woBy($m)['consent_date'] ?? null) . ')',
        (woBy($m)['consent_date']['reason'] ?? null) === 'cleared');

    // the log values are only what the log mode allows
    $mh = mod($DICT, $NOW, 'nurse', history($LOG, $FIELDS));
    $mh->projectSettings['log-values'] = 'none';
    $mh->redcap_save_record(PID, '2', 'enrol_form', 351, null, null, null, 1);
    check('audit: the lock times are kept when the log keeps no value', (woBy($mh)['consent_date']['locked_at'] ?? null) === '2026-10-01 09:00');

    // rule problems, never a pass
    $AMB = [[1, '2', 351, '20261001090000', "consent_date = '2026-03-01',\nconsent_date = '2026-03-02'", 'nurse', 'INSERT']];
    $m = mod($DICT, $NOW, 'nurse', history($AMB, $FIELDS));
    $m->redcap_save_record(PID, '2', 'enrol_form', 351, null, null, null, 1);
    check('audit: a save the log does not spell out is a rule problem (got ' . json_encode(problems($m, 'consent_date')) . ')',
        !isset(woBy($m)['consent_date']) && strpos(implode(' ', problems($m, 'consent_date')), 'does not say plainly') !== false);
    $GAP = [[1, '2', 351, '20261001090000', "consent_date = '2026-03-01'", 'nurse', 'INSERT']];
    $m = mod($DICT, $NOW, 'nurse', history($GAP, $FIELDS));
    $m->redcap_save_record(PID, '2', 'enrol_form', 351, null, null, null, 1);
    check('audit: a value the log never shows saved is a rule problem', strpos(implode(' ', problems($m, 'consent_date')), 'does not show the save') !== false);
    $m = mod($DICT, $NOW, 'nurse', history($LOG, $FIELDS), false);
    $m->redcap_save_record(PID, '2', 'enrol_form', 351, null, null, null, 1);
    check('audit: roles that cannot be read, for a change that needs them, are a rule problem',
        !isset(woBy($m)['arm']) && strpos(implode(' ', problems($m, 'arm')), 'user roles could not be read') !== false);
    check('... while a rule with no roles is still judged', (woBy($m)['consent_date']['reason'] ?? null) === 'changed');
    $m = mod($DICT, $NOW, 'nurse', false);
    $m->redcap_save_record(PID, '2', 'enrol_form', 351, null, null, null, 1);
    check('audit: a log that cannot be read is a rule problem', !woBy($m) && strpos(implode(' ', problems($m, 'consent_date')), 'project log could not be read') !== false);
    $capped = new class {
        public function saves($record, $eventId, $instance, $field) { return ['state' => 'capped', 'saves' => []]; }
        public function logTimeOf($d) { return '20261005000000'; }
    };
    $m = mod($DICT, $NOW, 'nurse', $capped);
    $m->redcap_save_record(PID, '2', 'enrol_form', 351, null, null, null, 1);
    check('audit: a log past the cap is a rule problem', strpos(implode(' ', problems($m, 'consent_date')), 'more saves of this record than are read') !== false);
    $nosince = new class {
        public function saves($record, $eventId, $instance, $field) { return ['state' => 'read', 'saves' => []]; }
        public function logTimeOf($d) { return null; }
    };
    $m = mod($DICT, $NOW, 'nurse', $nosince);
    $m->redcap_save_record(PID, '2', 'enrol_form', 351, null, null, null, 1);
    check('audit: a "since" the reader cannot place is a rule problem', strpos(implode(' ', problems($m, 'baseline_wt')), '"since" is not a date this rule can read') !== false);
    $none = history([], $FIELDS);
    $m = mod($DICT, ['2' => [351 => array_merge($ROW, ['consent_date' => '', 'arm' => '', 'site' => '', 'notes_w' => '', 'pain' => ''])]], 'nurse', $none);
    $m->redcap_save_record(PID, '2', 'enrol_form', 351, null, null, null, 1);
    check('audit: a record the log has no row for, with nothing saved, is no finding and no problem', !woBy($m) && !problems($m, 'consent_date'));

    // ============================================================================
    // The scan
    // ============================================================================
    $m = mod($DICT, $NOW, 'nurse', history($LOG, $FIELDS));
    $res = $m->scanProject(PID);
    $sb = [];
    foreach ($res['violations'] as $v) if ($v['type'] === 'writeonce') $sb[$v['field']] = $v;
    check('scan: the same verdicts (got ' . json_encode(array_map(function ($v) { return $v['reason']; }, $sb)) . ')',
        ($sb['consent_date']['reason'] ?? null) === 'changed' && ($sb['notes_w']['reason'] ?? null) === 'cleared'
        && ($sb['baseline_wt']['reason'] ?? null) === 'changed' && !isset($sb['arm']) && !isset($sb['site']) && !isset($sb['pain']));
    check('scan: the violation carries the day it was locked as asOf', ($sb['consent_date']['asOf'] ?? null) === '2026-10-01 09:00');
    check('scan: misconfigured rules are rule problems, not data', !isset($sb['bad_role'])
        && (bool) array_filter($res['unconfigurable'], function ($u) { return in_array('bad_role', $u['fields'], true); }));
    $dims = $m->scanDimensions(PID, $res['rules'] ?? null);
    $cols = ScanColumns::all($dims);
    $row = ScanColumns::row($sb['consent_date'], $dims, $cols);
    check('report: the catalog wording and the lock day (got ' . json_encode($row['problem'] ?? null) . ')',
        ($row['problem'] ?? '') === 'The value was changed after its first save locked it. It was locked on 2026-10-01 09:00.');
    $row = ScanColumns::row($sb['notes_w'], $dims, $cols);
    check('report: a cleared value (got ' . json_encode($row['problem'] ?? null) . ')',
        ($row['problem'] ?? '') === 'The value was cleared after its first save locked it. It was locked on 2026-10-01 09:00.');
    check('scan labels per reason', ModeRegistry::issueLabel('writeonce', 'changed') === 'Changed after it was locked'
        && ModeRegistry::issueLabel('writeonce', 'cleared') === 'Cleared after it was locked'
        && ModeRegistry::issueLabel('writeonce', 'other') === 'Changed after it was locked');

    echo "writeonce_module_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
