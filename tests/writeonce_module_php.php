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
 *     respondent holds no role; roles that cannot be read lock the field
 *     for everyone and say so; the
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
        public $queryLog = [];
        public $queryAnswer = null;
        public function query($sql, $params = []) {
            $this->queryLog[] = [$sql, $params];
            return $this->queryAnswer === null ? [] : call_user_func($this->queryAnswer, $sql, $params);
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
    class REDCap {
        public static $data = [];
        public static $dictionary = [];
        public static $rights = [];
        public static $mappings = null;
        public static $failGetData = false;
        public static function getInstrumentEventMappings($pid = null) { return self::$mappings; }
        public static function getData($p) {
            if (self::$failGetData) throw new \RuntimeException('data unreadable');
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
    check('grammar: a "since" written day first is quoted as written (got ' . ($parse('{"since":"01/11/2026"}')['error'] ?? '') . ')',
        ($parse('{"since":"01/11/2026"}')['error'] ?? '') === '"since" must be a date written YYYY-MM-DD, e.g. "2026-11-01" — got "01/11/2026".');
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
    check('page: the user name compares without letter case or spaces', lockedOn(page(mod($DICT, $DATA, ' DM '), 'form', '2', 'enrol_form')) === ['consent_date', 'notes_w']);
    check('page: roles that cannot be read lock a rule that names roles too (got ' . json_encode(lockedOn($pu)) . ')',
        lockedOn($pu) === ['arm', 'bad_role', 'consent_date', 'notes_w', 'pain']);
    check('... and only such a rule says the role could not be checked',
        !empty(ruleFor($pu, 'arm')['writeonceRolesUnread']) && empty(ruleFor($pu, 'consent_date')['writeonceRolesUnread'])
        && empty(ruleFor($pd, 'consent_date')['writeonceRolesUnread']));
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
    check('audit: a log past the cap is a rule problem', strpos(implode(' ', problems($m, 'consent_date')), 'more saves of this record and event than are read') !== false);
    // a history the reader could not finish says why
    foreach (['delete' => 'holds a deletion this check cannot place (part of the record, or one arm of it)', 'noevent' => 'holds a save of this field that names no event',
              'unnamed' => 'the log reader was not told to keep this field', 'read' => 'the project log could not be read',
              'start' => 'the project log does not show the record being made (it was renamed, or came with a copied project), so its first saves are not read',
              'startrow' => 'the row of the project log that made the record could not be read plainly (its record id line comes after a Text or Notes value), so its first saves are not read',
              'rename' => 'the project log could not be read',
              'other' => 'the project log could not be read'] as $why => $says) {
        $unk = new class($why) {
            private $why;
            public function __construct($why) { $this->why = $why; }
            public function saves($record, $eventId, $instance, $field) { return ['state' => 'unknown', 'saves' => [], 'why' => $this->why]; }
            public function logTimeOf($d) { return '20261005000000'; }
        };
        $m = mod($DICT, $NOW, 'nurse', $unk);
        $m->redcap_save_record(PID, '2', 'enrol_form', 351, null, null, null, 1);
        check('audit: a history the reader could not finish (' . $why . ') is a rule problem that says why (got ' . json_encode(problems($m, 'consent_date')) . ')',
            !woBy($m) && strpos(implode(' ', problems($m, 'consent_date')), $says . ' — field not checked') !== false);
    }
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

    // The row that made the record, written before a field was moved (review W4-1): the reader is told what the
    // module tells it (every Text field free text, the record id field included). The record stays started: only
    // that row's values are not plain, and a field first saved later is judged.
    $asModule = function ($m, array $rows) use ($FIELDS) {
        $db = new FakeHistoryLog();
        foreach ($rows as $r) $db->rows[] = [$r[0], PID, $r[1], $r[2], $r[3], $r[4], 'redcap_data', isset($r[6]) ? $r[6] : 'UPDATE', $r[5]];
        $o = new \ReflectionMethod(get_class($m), 'valueHistoryOptions');
        $o->setAccessible(true);
        $utc = new \DateTimeZone('UTC');
        setPrivate($m, 'valueHistoryOverride', ValueHistory::forProject($db, PID, $FIELDS, $utc, $utc, $o->invoke($m, PID)));
        return $m;
    };
    $MOVED = [
        [1, '2', 351, '20261001090000', "record_id = '2',\narm = '1',\nconsent_date = '2026-03-01'", 'nurse', 'INSERT'],
        [2, '2', 351, '20261002090000', "notes_w = 'a note',\npain = '40'", 'nurse'],
        [3, '2', 351, '20261003090000', "consent_date = '2026-04-01',\nnotes_w = ''", 'nurse'],
    ];
    $NOWM = ['2' => [351 => array_merge($ROW, ['consent_date' => '2026-04-01', 'arm' => '1', 'notes_w' => '', 'pain' => '40'])]];
    $m = $asModule(mod($DICT, $NOWM), $MOVED);
    $m->redcap_save_record(PID, '2', 'enrol_form', 351, null, null, null, 1);
    $all = [];
    foreach (findings($m, 'uvalidate-unconfigurable') as $e) $all[] = (string) $e['why'];
    check('audit (module options): a creating row out of today\'s dictionary order still starts the record (got ' . json_encode(array_keys(woBy($m))) . ' / ' . json_encode($all) . ')',
        (woBy($m)['notes_w']['reason'] ?? null) === 'cleared' && !preg_grep('/record being made|record could not be read plainly/', $all));
    check('... and the fields that row saved are rule problems, "not plain"', strpos(implode(' ', problems($m, 'consent_date')), 'does not say plainly') !== false
        && strpos(implode(' ', problems($m, 'arm')), 'does not say plainly') !== false && !problems($m, 'pain'));
    $TYPED = [
        [1, '2', 351, '20261001090000', "consent_date = '2026-03-01',\nrecord_id = '2'", 'nurse', 'INSERT'],
        [2, '2', 351, '20261003090000', "consent_date = '2026-04-01'", 'nurse'],
    ];
    $m = $asModule(mod($DICT, $NOWM), $TYPED);
    $m->redcap_save_record(PID, '2', 'enrol_form', 351, null, null, null, 1);
    check('audit (module options): a creating row whose record id line follows a Text value: every field not checked, saying so (got ' . json_encode(problems($m, 'consent_date')) . ')',
        !woBy($m) && strpos(implode(' ', problems($m, 'consent_date')), 'the row of the project log that made the record could not be read plainly') !== false
        && strpos(implode(' ', problems($m, 'pain')), 'could not be read plainly') !== false);

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

    // ============================================================================
    // A rule on a locked field that reads another field (review M3): lockReads
    // ============================================================================
    $LDICT = [
        'record_id' => f('enrol_form'),
        'wt'        => f('enrol_form', '@UVWRITEONCE @UVASSERT="[wt]<[ht]"', 'number'),
        'ht'        => f('enrol_form', '', 'number'),
        'wt2'       => f('enrol_form', '@UVWRITEONCE @UVASSERT="[wt2]>10"', 'number'),
        'free'      => f('enrol_form', '@UVASSERT="[free]<[ht]"', 'number'),
        'dm_only'   => f('enrol_form', '@UVWRITEONCE={"roles":["Data manager"]} @UVASSERT="[dm_only]<[ht]"', 'number'),
    ];
    $LDATA = ['2' => [351 => ['record_id' => '2', 'wt' => '70', 'ht' => '150', 'wt2' => '20', 'free' => '1', 'dm_only' => '5']]];
    // review W2-2: two locked fields that read each other, and a field read from another form
    $XDICT = $LDICT + [
        'la'        => f('enrol_form', '@UVWRITEONCE @UVASSERT="[la]<[lb]"', 'number'),
        'lb'        => f('enrol_form', '@UVWRITEONCE @UVASSERT="[lb]>[la]"', 'number'),
        'lx'        => f('enrol_form', '@UVWRITEONCE @UVASSERT="[lx]<[vt]"', 'number'),
        'ly'        => f('enrol_form', '@UVWRITEONCE @UVASSERT="[ly]<[vt] and [ly]<[ht]"', 'number'),
        'vt'        => f('visit_form', '', 'number'),
    ];
    $XDATA = ['2' => [351 => $LDATA['2'][351] + ['la' => '1', 'lb' => '2', 'lx' => '3', 'ly' => '4', 'vt' => '9']]];
    // review W3-6 and W3-1: a checkbox read, and calcs the person cannot change
    $CDICT = $LDICT + [
        'preg'      => ['field_type' => 'checkbox', 'form_name' => 'enrol_form', 'field_annotation' => '', 'select_choices_or_calculations' => '1, Yes | 2, Not sure'],
        'lc'        => f('enrol_form', '@UVWRITEONCE @UVASSERT="[lc]<100 or [preg(1)]=\'0\'"', 'number'),
        'bmi_l'     => ['field_type' => 'calc', 'form_name' => 'enrol_form', 'field_annotation' => '', 'select_choices_or_calculations' => '[wt2]*2'],
        'bmi_o'     => ['field_type' => 'calc', 'form_name' => 'enrol_form', 'field_annotation' => '', 'select_choices_or_calculations' => '[ht]/2'],
        'bmi_n'     => ['field_type' => 'calc', 'form_name' => 'enrol_form', 'field_annotation' => '', 'select_choices_or_calculations' => '[bmi_o]+[wt2]'],
        'bmi_e'     => ['field_type' => 'calc', 'form_name' => 'enrol_form', 'field_annotation' => '', 'select_choices_or_calculations' => '[event_1_arm_1][ht]+1'],
        'txt_c'     => f('enrol_form', '@CALCTEXT(if([wt2]>10, "big", "small"))'),
        'lcalc'     => f('enrol_form', '@UVWRITEONCE @UVASSERT="[lcalc]<[bmi_l] or [lcalc]<[bmi_o] or [lcalc]<[bmi_n] or [lcalc]<[bmi_e] or [lcalc]<>[txt_c]"', 'number'),
        // review W4-5: this event named outright or as [event-name], and another event
        'bmi_x'     => ['field_type' => 'calc', 'form_name' => 'enrol_form', 'field_annotation' => '', 'select_choices_or_calculations' => '[event_2_arm_1][ht]+1'],
        'bmi_en'    => ['field_type' => 'calc', 'form_name' => 'enrol_form', 'field_annotation' => '', 'select_choices_or_calculations' => '[event-name][ht]*3'],
        'lx_e'      => f('enrol_form', '@UVWRITEONCE @UVASSERT="[lx_e]<[bmi_e]"', 'number'),
        'lx_x'      => f('enrol_form', '@UVWRITEONCE @UVASSERT="[lx_x]<[bmi_x]"', 'number'),
        'lx_n'      => f('enrol_form', '@UVWRITEONCE @UVASSERT="[lx_n]<[bmi_en]"', 'number'),
        // review W4-8: a checkbox option read by a calc, @CALCDATE, and a calc that also carries @CALCTEXT
        'cb_c'      => ['field_type' => 'calc', 'form_name' => 'enrol_form', 'field_annotation' => '', 'select_choices_or_calculations' => "if([preg(1)]='1', 1, 0)"],
        'lx_cb'     => f('enrol_form', '@UVWRITEONCE @UVASSERT="[lx_cb]<[cb_c]"', 'number'),
        'dob_o'     => f('enrol_form', '', 'date_ymd'),
        'due_d'     => f('enrol_form', "@CALCDATE([dob_o], 30, 'd')", 'date_ymd'),
        'lx_d'      => f('enrol_form', '@UVWRITEONCE @UVASSERT="[lx_d]<>[due_d]"', 'date_ymd'),
        'wt_o'      => f('enrol_form', '', 'number'),
        'both_c'    => ['field_type' => 'calc', 'form_name' => 'enrol_form', 'field_annotation' => '@CALCTEXT([wt_o])', 'select_choices_or_calculations' => '[ht]+0'],
        'lx_b'      => f('enrol_form', '@UVWRITEONCE @UVASSERT="[lx_b]<[both_c]"', 'number'),
        // review W4-2: a field hidden on one kind of page only
        'hs'        => f('enrol_form', '@HIDDEN-SURVEY', 'number'),
        'hf'        => f('enrol_form', '@HIDDEN-FORM', 'number'),
        'lx_h'      => f('enrol_form', '@UVWRITEONCE @UVASSERT="[lx_h]<[hs] or [lx_h]<[hf]"', 'number'),
        // a calc of another form, worked out from a field of this one: not worked out on this page
        'vcalc'     => ['field_type' => 'calc', 'form_name' => 'visit_form', 'field_annotation' => '', 'select_choices_or_calculations' => '[ht]*2'],
        'lx_v'      => f('enrol_form', '@UVWRITEONCE @UVASSERT="[lx_v]<[vcalc]"', 'number'),
    ];
    $CDATA = ['2' => [351 => $LDATA['2'][351] + ['lc' => '120', 'lcalc' => '5', 'lx_e' => '5', 'lx_x' => '5', 'lx_n' => '5', 'lx_cb' => '5',
        'lx_d' => '2026-01-01', 'lx_b' => '5', 'lx_h' => '5', 'lx_v' => '5']]];
    $ruleOf = function ($p, $type, $f) {
        foreach ($p['cfg']['rules'] ?? [] as $r) if (($r['type'] ?? '') === $type && in_array($f, $r['fields'] ?? [], true)) return $r;
        return null;
    };
    $p = page(mod($LDICT, $LDATA), 'form', '2', 'enrol_form');
    check('lockReads: a rule on a locked field that reads another field names that field (got ' . json_encode($ruleOf($p, 'constraint', 'wt')) . ')',
        ($ruleOf($p, 'constraint', 'wt')['lockReads'] ?? null) === ['wt' => ['ht']]);
    check('lockReads: none for a rule that reads the locked field alone', !isset($ruleOf($p, 'constraint', 'wt2')['lockReads']));
    check('lockReads: none for a rule on a field nothing locks', !isset($ruleOf($p, 'constraint', 'free')['lockReads']));
    check('lockReads: none on the lock itself', !isset($ruleOf($p, 'writeonce', 'wt')['lockReads']));
    check('lockReads: a role holder has no lock, so no lockReads', ($pd = page(mod($LDICT, $LDATA, 'dm'), 'form', '2', 'enrol_form'))
        && $ruleOf($pd, 'writeonce', 'dm_only') === null && !isset($ruleOf($pd, 'constraint', 'dm_only')['lockReads'])
        && ($ruleOf($p, 'constraint', 'dm_only')['lockReads'] ?? null) === ['dm_only' => ['ht']]);
    $ps = page(mod($LDICT, $LDATA), 'survey', '2', 'enrol_form');
    check('lockReads: sent to a survey too', ($ruleOf($ps, 'constraint', 'wt')['lockReads'] ?? null) === ['wt' => ['ht']]);
    $px = page(mod($XDICT, $XDATA), 'form', '2', 'enrol_form');
    check('lockReads: two locked fields that read each other are not marked (review W2-2)',
        !isset($ruleOf($px, 'constraint', 'la')['lockReads']) && !isset($ruleOf($px, 'constraint', 'lb')['lockReads']));
    check('lockReads: nor one that reads only a field of another form', !isset($ruleOf($px, 'constraint', 'lx')['lockReads']));
    check('lockReads: only the fields the person can change here are named', ($ruleOf($px, 'constraint', 'ly')['lockReads'] ?? null) === ['ly' => ['ht']]);
    $pc = page(mod($CDICT, $CDATA), 'form', '2', 'enrol_form');
    check('lockReads: a checkbox is named once per option, as the page reads it (review W3-6) (got ' . json_encode($ruleOf($pc, 'constraint', 'lc')['lockReads'] ?? null) . ')',
        ($ruleOf($pc, 'constraint', 'lc')['lockReads'] ?? null) === ['lc' => ['preg(1)', 'preg(2)']]);
    check('lockReads: a calc is never named; in its place come the fields it is worked out from that the person may change, through other calcs too, once each (reviews W3-1, W4-2) (got '
        . json_encode($ruleOf($pc, 'constraint', 'lcalc')['lockReads'] ?? null) . ')',
        ($ruleOf($pc, 'constraint', 'lcalc')['lockReads'] ?? null) === ['lcalc' => ['ht']]);
    $lrOf = function ($p, $f) use ($ruleOf) { return $ruleOf($p, 'constraint', $f)['lockReads'] ?? null; };
    check('lockReads: a calc that names this event outright reads this event\'s field (review W4-5) (got ' . json_encode($lrOf($pc, 'lx_e')) . ')', $lrOf($pc, 'lx_e') === ['lx_e' => ['ht']]);
    check('lockReads: ... and so does one that names [event-name] (got ' . json_encode($lrOf($pc, 'lx_n')) . ')', $lrOf($pc, 'lx_n') === ['lx_n' => ['ht']]);
    check('lockReads: ... while another event\'s field is left out (got ' . json_encode($lrOf($pc, 'lx_x')) . ')', $lrOf($pc, 'lx_x') === null);
    check('lockReads: a calc that reads one checkbox option names that option alone (review W4-8) (got ' . json_encode($lrOf($pc, 'lx_cb')) . ')', $lrOf($pc, 'lx_cb') === ['lx_cb' => ['preg(1)']]);
    check('lockReads: a @CALCDATE field names what it is worked out from (got ' . json_encode($lrOf($pc, 'lx_d')) . ')', $lrOf($pc, 'lx_d') === ['lx_d' => ['dob_o']]);
    check('lockReads: a calc that also carries @CALCTEXT is read from both (got ' . json_encode($lrOf($pc, 'lx_b')) . ')', $lrOf($pc, 'lx_b') === ['lx_b' => ['ht', 'wt_o']]);
    check('lockReads: a calc of another form names nothing, though it is worked out from a field of this one (got ' . json_encode($lrOf($pc, 'lx_v')) . ')', $lrOf($pc, 'lx_v') === null);
    check('lockReads: a field hidden on surveys only is named on a form; one hidden on forms only is not (review W4-2) (got ' . json_encode($lrOf($pc, 'lx_h')) . ')', $lrOf($pc, 'lx_h') === ['lx_h' => ['hs']]);
    $pcs = page(mod($CDICT, $CDATA), 'survey', '2', 'enrol_form');
    check('lockReads: ... and the other way round on a survey (got ' . json_encode($lrOf($pcs, 'lx_h')) . ')', $lrOf($pcs, 'lx_h') === ['lx_h' => ['hf']]);

    // markLockReads itself: the fields the person cannot change or REDCap writes as the page starts (review W4-2),
    // and a cycle of calcs in either order of the rules (review W4-4)
    $mark = new \ReflectionMethod('INSPIRE\\UniversalValidator\\UniversalValidator', 'markLockReads');
    $mark->setAccessible(true);
    $lr = function (array $rules, array $dd, $context = null, $event = null) use ($mark) {
        $out = [];
        foreach ($mark->invoke(null, $rules, array_fill_keys(array_keys($dd), true), $dd, $context, $event) as $r) if (isset($r['lockReads'])) $out += $r['lockReads'];
        return $out;
    };
    $t = function ($ann, $type = 'text', $calc = '') { return ['field_type' => $type, 'field_annotation' => $ann, 'select_choices_or_calculations' => $calc]; };
    $FDD = ['lk' => $t(''), 'ro' => $t('@READONLY'), 'rof' => $t('@READONLY-FORM'), 'ros' => $t('@READONLY-SURVEY'), 'hid' => $t('@HIDDEN'),
            'hidf' => $t('@HIDDEN-FORM'), 'hids' => $t('@HIDDEN-SURVEY'), 'hida' => $t('@HIDDEN-APP @HIDDEN-PDF @READONLY-APP'), 'nw' => $t('@NOW'),
            'td' => $t('@TODAY'), 'tds' => $t('@TODAY-SERVER'), 'nwu' => $t('@NOW-UTC'), 'lat' => $t('@LATITUDE'), 'lon' => $t('@LONGITUDE'),
            'sv' => $t("@SETVALUE='x'"), 'df' => $t("@DEFAULT='x'"), 'lo' => $t('@readonly'), 'open' => $t('@NOWHERE @DEFAULTS @HIDDENX'),
            'c_fix' => $t('', 'calc', '[ro]+[nw]+[sv]'), 'c_open' => $t('', 'calc', '[open]*2')];
    $reads = [];
    foreach (array_keys($FDD) as $f) $reads[] = '[' . $f . "]<>'x'";
    // each field once: a condition names at most 20 fields
    $FRULES = [['type' => 'writeonce', 'fields' => ['lk']], ['type' => 'constraint', 'fields' => ['lk'], 'assert' => implode(' or ', $reads)]];
    check('lockReads: on a form, @READONLY, @HIDDEN and their -FORM variants, @NOW, @TODAY, @LATITUDE, @LONGITUDE, @SETVALUE and @DEFAULT are left out (got '
        . json_encode($lr($FRULES, $FDD, 'form')) . ')', $lr($FRULES, $FDD, 'form') === ['lk' => ['ros', 'hids', 'hida', 'open']]);
    check('lockReads: on a survey, the -SURVEY variants (got ' . json_encode($lr($FRULES, $FDD, 'survey')) . ')', $lr($FRULES, $FDD, 'survey') === ['lk' => ['rof', 'hidf', 'hida', 'open']]);
    check('lockReads: where the page is not known, every variant (got ' . json_encode($lr($FRULES, $FDD)) . ')', $lr($FRULES, $FDD) === ['lk' => ['hida', 'open']]);
    $CYC = ['f1' => $t(''), 'f2' => $t(''), 'a' => $t('', 'calc', 'if([c]="", [x], [x])'), 'c' => $t('', 'calc', '[a]'), 'x' => $t('')];
    $wo = ['type' => 'writeonce', 'fields' => ['f1', 'f2']];
    $r1 = ['type' => 'constraint', 'fields' => ['f1'], 'assert' => '[f1] <> [a]'];
    $r2 = ['type' => 'constraint', 'fields' => ['f2'], 'assert' => '[f2] <> [c]'];
    check('lockReads: a cycle of calcs, the rule that reads the first calc first (got ' . json_encode($lr([$wo, $r1, $r2], $CYC)) . ')', $lr([$wo, $r1, $r2], $CYC) === ['f1' => ['x'], 'f2' => ['x']]);
    check('lockReads: ... and last (got ' . json_encode($lr([$wo, $r2, $r1], $CYC)) . ')', $lr([$wo, $r2, $r1], $CYC) === ['f2' => ['x'], 'f1' => ['x']]);
    check('lockReads: ... and each rule alone', $lr([$wo, $r2], $CYC) === ['f2' => ['x']] && $lr([$wo, $r1], $CYC) === ['f1' => ['x']]);
    $CYC['x'] = $t('@READONLY');
    check('lockReads: a cycle of calcs that nothing the person may change feeds names nothing', $lr([$wo, $r1, $r2], $CYC) === []);
    check('lockReads: the page\'s own event named outright counts only when the page\'s event is known',
        $lr([$wo, ['type' => 'constraint', 'fields' => ['f1'], 'assert' => '[f1] <> [e]']], ['f1' => $t(''), 'f2' => $t(''), 'x' => $t(''), 'e' => $t('', 'calc', '[visit_1_arm_1][x]')], null, 'visit_1_arm_1') === ['f1' => ['x']]
        && $lr([$wo, ['type' => 'constraint', 'fields' => ['f1'], 'assert' => '[f1] <> [e]']], ['f1' => $t(''), 'f2' => $t(''), 'x' => $t(''), 'e' => $t('', 'calc', '[visit_1_arm_1][x]')]) === []);
    check('lockReads: the event of a pair is no field, and a smart event other than [event-name] is another event',
        $lr([$wo, ['type' => 'constraint', 'fields' => ['f1'], 'assert' => '[f1] <> [e]']], ['f1' => $t(''), 'f2' => $t(''), 'x' => $t(''), 'y' => $t(''), 'z' => $t(''),
            'e' => $t('', 'calc', '[z][y]+[previous-event-name][y]+[x][2]')]) === ['f1' => ['x']]);

    // what the log reader is told of the project: the dictionary's order and whether a DELETE may cover one arm
    $opts = function ($m) {
        $r = new \ReflectionMethod(get_class($m), 'valueHistoryOptions');
        $r->setAccessible(true);
        return $r->invoke($m, PID);
    };
    $o = $opts(mod($LDICT, $LDATA));
    check('reader options: the data dictionary\'s order, its Text fields, the record id field, and one arm (got ' . json_encode($o) . ')',
        $o === ['order' => ['record_id' => 0, 'wt' => 1, 'ht' => 2, 'wt2' => 3, 'free' => 4, 'dm_only' => 5],
                'freeText' => ['record_id' => true, 'wt' => true, 'ht' => true, 'wt2' => true, 'free' => true, 'dm_only' => true],
                'recordIdField' => 'record_id', 'multiArm' => false]);
    $o = $opts(mod(['record_id' => f('enrol_form'), 'n' => f('enrol_form', '', '', 'notes'), 'r' => f('enrol_form', '', '', 'radio'),
        'd' => f('enrol_form', '', '', 'dropdown'), 'c' => f('enrol_form', '', '', 'calc')], $LDATA));
    check('reader options: Text and Notes fields are free text, choice and calc fields are not (got ' . json_encode($o['freeText']) . ')',
        $o['freeText'] === ['record_id' => true, 'n' => true]);
    $GLOBALS['Proj'] = new class {
        public $project_id = PID;
        public $eventInfo = [351 => ['unique_event_name' => 'event_1_arm_1', 'arm_num' => 1], 352 => ['unique_event_name' => 'event_1_arm_2', 'arm_num' => 2]];
        public function getUniqueEventNames() { return [351 => 'event_1_arm_1', 352 => 'event_1_arm_2']; }
        public function getRepeatingFormsEvents() { return []; }
    };
    $o = $opts(mod($LDICT, $LDATA));
    unset($GLOBALS['Proj']);
    check('reader options: two arms, where a DELETE may cover one (got ' . json_encode($o['multiArm']) . ')', $o['multiArm'] === true);
    // arms that cannot be read count as several (review W2-15 U2)
    $GLOBALS['Proj'] = new class {
        public $project_id = PID;
        public $eventInfo = [351 => ['unique_event_name' => 'event_1_arm_1']];
        public function getUniqueEventNames() { return [351 => 'event_1_arm_1']; }
        public function getRepeatingFormsEvents() { return []; }
    };
    $o = $opts(mod($LDICT, $LDATA));
    unset($GLOBALS['Proj']);
    check('reader options: an event whose arm is not known counts as several arms (got ' . json_encode($o['multiArm']) . ')', $o['multiArm'] === true);
    $GLOBALS['Proj'] = new class {
        public $project_id = PID;
        public $eventInfo = [];
        public function getUniqueEventNames() { return []; }
        public function getRepeatingFormsEvents() { return []; }
    };
    $o = $opts(mod($LDICT, $LDATA));
    unset($GLOBALS['Proj']);
    check('reader options: events that cannot be read count as several arms (got ' . json_encode($o['multiArm']) . ')', $o['multiArm'] === true);

    // the role book is read with today's date, so expired rights hold no role (review W2-15 U3)
    $m = mod($LDICT, $LDATA);
    setPrivate($m, 'roleBookOverride', null);
    $m->queryAnswer = function ($sql, $params) { return strpos($sql, 'FROM redcap_projects') !== false ? [] : []; };
    $hr = new \ReflectionMethod(get_class($m), 'userHoldsRole');
    $hr->setAccessible(true);
    $hr->invoke($m, PID, 'dm', ['Data manager']);
    $asked = array_values(array_filter($m->queryLog, function ($q) { return strpos($q[0], 'FROM redcap_user_rights') !== false; }));
    check('roles: the rights are compared with today (got ' . json_encode($asked[0][1] ?? null) . ')',
        $asked && $asked[0][1] === [PID, (new \DateTimeImmutable('now'))->format('Y-m-d')]);

    // role names in any letter case and spacing (review W2-9)
    $intl = ['roles' => ['équipe médicale' => true, 'data manager' => true], 'users' => ['dm' => ['équipe médicale']]];
    $IDICT = ['record_id' => f('enrol_form'), 'ri' => f('enrol_form', '@UVWRITEONCE={"roles":["ÉQUIPE  Médicale"]}'),
              'rd' => f('enrol_form', '@UVWRITEONCE={"roles":["Data   Manager"]}')];
    $pi = page(mod($IDICT, ['2' => [351 => ['record_id' => '2', 'ri' => 'x', 'rd' => 'y']]], 'nurse', null, $intl), 'form', '2', 'enrol_form');
    check('roles: a name in other letter case, accented, with other spacing, is the project\'s role (got ' . errOf($pi, 'ri') . ' / ' . errOf($pi, 'rd') . ')',
        errOf($pi, 'ri') === '' && errOf($pi, 'rd') === '');
    $pi = page(mod($IDICT, ['2' => [351 => ['record_id' => '2', 'ri' => 'x', 'rd' => 'y']]], 'dm', null, $intl), 'form', '2', 'enrol_form');
    check('roles: ... and its holder is not locked out', !in_array('ri', lockedOn($pi), true) && in_array('rd', lockedOn($pi), true));

    // an SQL field's saved code reaches the page; a date's does not (review W2-5)
    $SDICT = ['record_id' => f('enrol_form'), 'sq' => f('enrol_form', '@UVWRITEONCE', '', 'sql'), 'dd' => f('enrol_form', '@UVWRITEONCE', '', 'dropdown'),
              'dt' => f('enrol_form', '@UVWRITEONCE', 'date_dmy')];
    $psq = page(mod($SDICT, ['2' => [351 => ['record_id' => '2', 'sq' => '7', 'dd' => '3', 'dt' => '2026-03-01']]]), 'form', '2', 'enrol_form');
    $wr = $ruleOf($psq, 'writeonce', 'sq');
    check('saved code: sent for an SQL field and a dropdown, not for a date shown in its own format (got ' . json_encode($wr['writeonceSaved'] ?? null) . ')',
        ($wr['writeonceSaved'] ?? null) === ['sq' => '7', 'dd' => '3']);

    // saved values that cannot be read: the rule stays, flagged, and locks nothing (review W2-11)
    $mu = mod($LDICT, $LDATA);
    \REDCap::$failGetData = true;
    $pu = page($mu, 'form', '2', 'enrol_form');
    \REDCap::$failGetData = false;
    $wu = $ruleOf($pu, 'writeonce', 'wt');
    check('values unread: the rule reaches the page flagged, with its fields (got ' . json_encode($wu) . ')',
        $wu !== null && ($wu['writeonceValuesUnread'] ?? null) === true && in_array('wt', $wu['fields'], true) && !isset($wu['writeonceSaved']));

    // ============================================================================
    // Locks on returned fields, and the refusals in order
    // ============================================================================
    $RDICT = [
        'record_id' => f('enrol_form'),
        'src1'      => f('enrol_form'),
        'src2'      => f('enrol_form'),
        'lk'        => f('enrol_form', '@UVEXISTS={"in":"record","return":{"src1":"[r1]","src2":"[r2]"}}'),
        'r1'        => f('enrol_form', '@UVWRITEONCE={"message":"R"}'),
        'r2'        => f('enrol_form', '@UVWRITEONCE={"message":"R"}'),
        // two lookups returning to one field are both refused, so neither returns to it: the lock stands
        'lk_a'      => f('enrol_form', '@UVEXISTS={"in":"record","return":{"src1":"[r3]"}}'),
        'lk_b'      => f('enrol_form', '@UVEXISTS={"in":"record","return":{"src2":"[r3]"}}'),
        'r3'        => f('enrol_form', '@UVWRITEONCE'),
        'low'       => f('enrol_form', "@UVWRITEONCE @setvalue='x'"),
        'low2'      => f('enrol_form', '@UVWRITEONCE @prefill="x"'),
        'rich'      => f('enrol_form', '@UVWRITEONCE @richtext', '', 'notes'),
        'dlc'       => f('enrol_form', '@UVWRITEONCE @DOWNLOAD-COUNT(consent_file)'),
        'onto'      => array_merge(f('enrol_form', '@UVWRITEONCE'), ['select_choices_or_calculations' => ' BIOPORTAL:ICD10']),
        'onto2'     => array_merge(f('enrol_form', '@UVWRITEONCE'), ['select_choices_or_calculations' => 'FHIR:snomed-ct']),
    ];
    $RDATA = ['2' => [351 => ['record_id' => '2', 'src1' => '', 'src2' => '', 'lk' => '', 'r1' => 'a', 'r2' => 'b', 'lk_a' => '', 'lk_b' => '', 'r3' => 'c', 'low' => 'x', 'low2' => 'x', 'rich' => 'x', 'dlc' => '3', 'onto' => 'x', 'onto2' => 'x']]];
    $p = page(mod($RDICT, $RDATA), 'form', '2', 'enrol_form');
    $at = function ($p, $f) {
        foreach ($p['cfg']['rules'] ?? [] as $i => $r) if (($r['type'] ?? '') === 'writeonce' && in_array($f, $r['fields'] ?? [], true)) return $i;
        return null;
    };
    check('returned: each refused lock of one rule is its own refusal (got ' . errOf($p, 'r1') . ' / ' . errOf($p, 'r2') . ')',
        errOf($p, 'r1') === '@UVWRITEONCE cannot lock "[r1]": the rule on "lk" returns a value to it, which is written again on every save.' && strpos(errOf($p, 'r2'), 'cannot lock "[r2]"') !== false);
    check('returned: ... in field order, the first in the rule\'s own place', $at($p, 'r1') !== null && $at($p, 'r1') < $at($p, 'r2'));
    check('returned: a lookup refused for another reason returns nothing, so the lock on its field stands (got ' . errOf($p, 'r3') . ')',
        errOf($p, 'lk') === '' && errOf($p, 'r3') === '' && strpos(errOf($p, 'lk_a'), '[r3]') !== false && strpos(errOf($p, 'lk_b'), '[r3]') !== false
        && in_array('r3', lockedOn($p), true));
    check('@SETVALUE and @PREFILL refused whatever their letter case (got ' . errOf($p, 'low') . ' / ' . errOf($p, 'low2') . ')',
        strpos(errOf($p, 'low'), 'cannot lock a field with @SETVALUE') !== false && strpos(errOf($p, 'low2'), 'cannot lock a field with @PREFILL') !== false);
    $pdup = page(mod(['record_id' => f('enrol_form'), 'dup' => f('enrol_form', '@UVWRITEONCE @UVWRITEONCE={"message":"x"}')],
        ['2' => [351 => ['record_id' => '2', 'dup' => 'v']]]), 'form', '2', 'enrol_form');
    check('a field given @UVWRITEONCE twice: refused, saying the tag takes no condition (got ' . json_encode($pdup['cfg']['rules'] ?? null) . ')',
        errOf($pdup, 'dup') !== '' && strpos(errOf($pdup, 'dup'), '@UVWRITEONCE 2 times — this tag takes no "when" condition, so keep one.') !== false
        && lockedOn($pdup) === []);
    check('@RICHTEXT refused (got ' . errOf($p, 'rich') . ')', strpos(errOf($p, 'rich'), 'cannot lock a field with @RICHTEXT') !== false);
    check('@DOWNLOAD-COUNT refused (review W3-10) (got ' . errOf($p, 'dlc') . ')', strpos(errOf($p, 'dlc'), 'cannot lock a field with @DOWNLOAD-COUNT') !== false
        && !in_array('dlc', lockedOn($p), true));
    check('a BioPortal ontology search refused (got ' . errOf($p, 'onto') . ')', strpos(errOf($p, 'onto'), 'cannot lock a field that searches an ontology') !== false);
    check('... and any other ontology service (review W2-14) (got ' . errOf($p, 'onto2') . ')', strpos(errOf($p, 'onto2'), 'cannot lock a field that searches an ontology') !== false);

    echo "writeonce_module_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
