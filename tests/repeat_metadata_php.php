<?php
/**
 * repeat_metadata_php.php — where the module reads what repeats.
 *
 * REDCap 17.0.6 exposes the map of repeating forms and events as
 * \Project::getRepeatingFormsEvents() and has no static
 * \REDCap::getRepeatingFormsEvents. v2.3.0-rc.1 read only the static, so
 * every @UVEXISTS rule with "return" or "where" refused on that server ("which
 * forms repeat in this project could not be read"). The other suites stub the
 * static method, which is why they stayed green.
 *
 * One child process per REDCap shape, since a stub class cannot lose a method
 * within one process:
 *   project  the static is absent, \Project has the method (REDCap 17);
 *   static   the static is present, \Project lacks the method (older builds);
 *   throws   both present, \Project's throws: the static answers;
 *   odd      both present, \Project's answers a string: the static answers;
 *   false    both present, \Project's answers false: nothing repeats, and the
 *            static is not asked (the first source that answers decides);
 *   none     neither: "return" and "where" still refuse, failing closed.
 * Each mode runs on a longitudinal project (a repeating form in one event,
 * another event that repeats as a whole) and on a classic one (a repeating
 * form). Each pins the page config of "return" and "where" rules (sound rules
 * set up; a field on a repeating form and an "in":"record" event that repeats
 * as a whole still refused, which proves the map was read and not taken as
 * empty), repeatingLayout(), repeatingFormsFromMap(), eventLayout() and the
 * scan's repeatMetadata probe.
 *
 * Run:  php tests/repeat_metadata_php.php
 */

namespace ExternalModules {
    if (isset($argv[1])) {
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
            public function isModuleEnabled($prefix, $pid = null) { return (int) $pid === 149; }
            public function getProjectStatus($pid = null) { return (int) $pid === 149 ? 'DEV' : null; }
            public function getUrl($p) { return '/x/' . $p; }
            public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return count($this->logCalls); }
            public function initializeJavascriptModuleObject() { return '<script></script>'; }
            public function getJavascriptModuleObjectName() { return 'ExternalModules.TEST.UniversalValidator'; }
            public function query($sql, $params = []) {
                if (strpos($sql, 'FROM redcap_projects') !== false) return (int) $params[0] === 149 ? [[null]] : [];
                return [];
            }
            public function getUser() { return new TestUser('nurse'); }
        }
        class TestUser {
            private $n;
            public function __construct($n) { $this->n = $n; }
            public function getUsername() { return $this->n; }
            public function hasDesignRights() { return true; }
            public function isSuperUser() { return false; }
            public function getRights($pid = null) {
                return ['forms' => ['enrol' => '1', 'lab_reg' => '1', 'rep_form' => '1', 'visit' => '1', 'result' => '1'],
                        'group_id' => null, 'expiration' => ''];
            }
        }
    }
}

namespace {
    $MODES = ['project', 'static', 'throws', 'odd', 'false', 'none'];
    $LAYOUTS = ['long', 'classic'];

    // ---------------------------------------------------------------- parent
    if (!isset($argv[1])) {
        $total = 0; $failed = 0;
        foreach ($LAYOUTS as $layout) foreach ($MODES as $mode) {
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($mode)
                . ' ' . escapeshellarg($layout) . ' 2>&1';
            $lines = [];
            exec($cmd, $lines, $rc);
            $last = $lines ? end($lines) : '';
            if (!preg_match('/(\d+) checks, (\d+) failure/', $last, $mm)) {
                $total++; $failed++;
                fwrite(STDERR, "FAIL: mode $mode $layout did not report (exit $rc): " . implode("\n", $lines) . "\n");
                continue;
            }
            $total += (int) $mm[1];
            $failed += (int) $mm[2];
            foreach ($lines as $l) if (strpos($l, 'FAIL') === 0) fwrite(STDERR, "$l\n");
            if ($rc !== 0 && (int) $mm[2] === 0) { $failed++; fwrite(STDERR, "FAIL: mode $mode $layout exited $rc\n"); }
        }
        echo "repeat_metadata_php: $total checks, $failed failure(s)\n";
        exit($failed ? 1 : 0);
    }

    // ----------------------------------------------------------------- child
    $MODE = $argv[1];
    $LAYOUT = isset($argv[2]) ? $argv[2] : 'long';
    if (!in_array($MODE, $MODES, true) || !in_array($LAYOUT, $LAYOUTS, true)) {
        echo "unknown mode $MODE $LAYOUT: 0 checks, 1 failure(s)\n"; exit(1);
    }
    $LONG = $LAYOUT === 'long';

    // What repeats in pid 149: rep_form in event 351, and (longitudinal) event
    // 353 as a whole.
    if ($LONG) {
        define('REPEATS', [351 => ['rep_form' => null], 353 => 'WHOLE']);
        define('EVENTS', [351 => 'enrolment_arm_1', 352 => 'visit_arm_1', 353 => 'repeat_arm_1']);
        define('EVENT_FORMS', [351 => ['enrol', 'lab_reg', 'rep_form', 'result'], 352 => ['enrol', 'result'], 353 => ['enrol', 'visit']]);
    } else {
        define('REPEATS', [351 => ['rep_form' => null]]);
        define('EVENTS', [351 => 'event_1_arm_1']);
        define('EVENT_FORMS', [351 => ['enrol', 'lab_reg', 'rep_form', 'result']]);
    }

    class REDCapBase {
        public static $staticCalls = 0;
        public static function getData($p) { return []; }
        public static function getDataDictionary($pid, $f = 'array') {
            if ((int) $pid !== 149) throw new \RuntimeException('no such project');
            return $GLOBALS['DICT'];
        }
        public static function getEventNames($unique = false, $assoc = false, $id = null) { return EVENTS; }
        public static function getInstrumentEventMappings($pid = null) {
            $rows = [];
            foreach (EVENT_FORMS as $ev => $forms) foreach ($forms as $f) $rows[] = ['event_id' => $ev, 'form' => $f];
            return $rows;
        }
        public static function isLongitudinal() { return $GLOBALS['LONG']; }
        public static function getGroupNames($unique = false, $g = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
        public static function getUserRights($u = null) { return []; }
    }
    if (in_array($MODE, ['project', 'none'], true)) {
        class REDCap extends REDCapBase {}
    } else {
        class REDCap extends REDCapBase {
            public static function getRepeatingFormsEvents($pid = null) { self::$staticCalls++; return REPEATS; }
        }
    }

    class ProjectBase {
        public $longitudinal;
        public $project = ['missing_data_codes' => ''];
        public $eventsForms;
        public function __construct($pid) { $this->longitudinal = $GLOBALS['LONG']; $this->eventsForms = EVENT_FORMS; }
        public function getUniqueEventNames() { return EVENTS; }
        public function getUniqueGroupNames() { return []; }
    }
    if ($MODE === 'project') {
        class Project extends ProjectBase { public function getRepeatingFormsEvents() { return REPEATS; } }
    } elseif ($MODE === 'throws') {
        class Project extends ProjectBase { public function getRepeatingFormsEvents() { throw new \RuntimeException('not loaded'); } }
    } elseif ($MODE === 'odd') {
        class Project extends ProjectBase { public function getRepeatingFormsEvents() { return 'WHOLE'; } }
    } elseif ($MODE === 'false') {
        class Project extends ProjectBase { public function getRepeatingFormsEvents() { return false; } }
    } else {
        class Project extends ProjectBase {}
    }

    require_once __DIR__ . '/../UniversalValidator.php';

    use INSPIRE\UniversalValidator\ScanCapabilities;

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail, $MODE, $LAYOUT; $n++;
        if (!$cond) { $fail++; echo "FAIL: [$MODE $LAYOUT] $label\n"; }
    }
    function f($form, $ann = '', $validation = '', $type = 'text', $choices = '') {
        return ['field_name' => '', 'field_type' => $type, 'form_name' => $form, 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => $validation, 'identifier' => '',
                'select_choices_or_calculations' => $choices];
    }
    function ux($json) { return '@UVEXISTS=' . $json; }
    const SITES = '1, North | 2, South';

    $DICT = [
        'record_id'   => f('enrol'),
        'home'        => f('enrol'),
        'consent'     => f('enrol', '', '', 'radio', '0, No | 1, Yes'),
        'specimen_id' => f('lab_reg'),
        'lab_site'    => f('lab_reg', '', '', 'dropdown', SITES),
        'rep_id'      => f('rep_form'),
        'rep_flag'    => f('rep_form'),
        'v_note'      => f('visit'),
        // sound: copy a field of the form searched, test a field of a form that does not repeat
        'x_ret'       => f('result', ux('{"in":"[specimen_id]","return":{"lab_site":"[t_site]"}}')),
        't_site'      => f('result', '', '', 'dropdown', SITES),
        'x_where'     => f('result', ux('{"in":"[specimen_id]","where":"[consent]=\'1\'"}')),
        'x_rec_ev'    => f('result', ux($LONG ? '{"in":"record","event":"enrolment_arm_1","return":{"home":"[t_home]"}}'
                                              : '{"in":"record","return":{"home":"[t_home]"}}')),
        't_home'      => f('result'),
        // refused only when the map is read: a field on the repeating form
        'b_ret_rep'   => f('result', ux('{"in":"[specimen_id]","return":{"rep_flag":"[t_flag]"}}')),
        't_flag'      => f('result'),
        'b_where_rep' => f('result', ux('{"in":"[specimen_id]","where":"[rep_flag]=\'1\'"}')),
        // refused only when the map is read: an "in":"record" event that repeats
        // as a whole (classic: a field of the record on the repeating form)
        'b_rec'       => f('result', ux($LONG ? '{"in":"record","event":"repeat_arm_1","return":{"home":"[t_home2]"}}'
                                              : '{"in":"record","return":{"rep_flag":"[t_home2]"}}')),
        't_home2'     => f('result'),
    ];
    foreach ($DICT as $k => $_) $DICT[$k]['field_name'] = $k;
    $GLOBALS['DICT'] = $DICT;

    function mod() {
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectIdReturn = 149;
        $m->settingsBy = [149 => ['log-values' => 'raw']];
        return $m;
    }
    function page($m) {
        ob_start();
        $m->redcap_data_entry_form_top(149, '9', 'result', 351, null, 1);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return json_decode(isset($mm[1]) ? $mm[1] : 'null', true);
    }
    function errOf($cfg, $f) {
        foreach ((isset($cfg['rules']) ? $cfg['rules'] : []) as $r) {
            if (in_array($f, isset($r['fields']) ? $r['fields'] : [], true)) return (string) ($r['configError'] ?? '');
        }
        return 'NO RULE';
    }
    function priv($m, $name, ...$args) {
        $r = new \ReflectionMethod($m, $name);
        $r->setAccessible(true);
        return $r->invoke($m, ...$args);
    }

    $readable = !in_array($MODE, ['none'], true);     // some source answers
    $mapRead  = !in_array($MODE, ['none', 'false'], true);   // and the answer is the map
    $UNREAD = 'which forms repeat in this project could not be read';

    $cfg = page(mod());
    check('the page config was injected', is_array($cfg) && isset($cfg['rules']));

    // --- the page config: "return" and "where" ---------------------------
    foreach (['x_ret' => '"return"', 'x_where' => '"where"', 'x_rec_ev' => '"return"'] as $f => $key) {
        $e = errOf($cfg, $f);
        if ($readable) check("$f sets up (got: $e)", $e === '');
        else check("$f refuses because nothing could be read (got: $e)", strpos($e, $UNREAD) !== false && strpos($e, $key) !== false);
    }
    $e = errOf($cfg, 'b_ret_rep');
    if ($mapRead) check("b_ret_rep: the copied field is on a repeating form (got: $e)", strpos($e, 'is on the repeating form "rep_form"') !== false);
    elseif ($readable) check("b_ret_rep: nothing repeats per the first answer, so it sets up (got: $e)", $e === '');
    else check("b_ret_rep: refuses, nothing read (got: $e)", strpos($e, $UNREAD) !== false);
    $e = errOf($cfg, 'b_where_rep');
    if ($mapRead) check("b_where_rep: the tested field is on a repeating form (got: $e)", strpos($e, '"where" field "[rep_flag]" is on the repeating form "rep_form"') !== false);
    elseif ($readable) check("b_where_rep: sets up when nothing repeats (got: $e)", $e === '');
    else check("b_where_rep: refuses, nothing read (got: $e)", strpos($e, $UNREAD) !== false);
    $e = errOf($cfg, 'b_rec');
    $why = $LONG ? 'and that event repeats' : 'is on the repeating form "rep_form" — the lookup finds one entry';
    if ($mapRead) check("b_rec: the record's entry repeats (got: $e)", strpos($e, $why) !== false);
    elseif ($readable) check("b_rec: sets up when nothing repeats (got: $e)", $e === '');
    else check("b_rec: refuses, nothing read (got: $e)", strpos($e, $UNREAD) !== false);

    // --- the readers ------------------------------------------------------
    $m = mod();
    $layout = priv($m, 'repeatingLayout', 149);
    if ($mapRead) {
        check('repeatingLayout: rep_form repeats', isset($layout['forms']['rep_form']) && count($layout['forms']) === 1);
        check('repeatingLayout: the repeating events',
            $LONG ? (isset($layout['events'][353]) && count($layout['events']) === 1) : $layout['events'] === []);
    } elseif ($readable) {
        check('repeatingLayout: false reads as nothing repeating', $layout === ['forms' => [], 'events' => []]);
    } else {
        check('repeatingLayout: null when nothing could be read', $layout === null);
    }

    $forms = priv($m, 'repeatingFormsFromMap', 149, 351);
    $whole = priv($m, 'repeatingFormsFromMap', 149, 353);
    if ($mapRead) {
        check('repeatingFormsFromMap: event 351 holds rep_form', $forms === ['rep_form' => true]);
        if ($LONG) check('repeatingFormsFromMap: a whole-event node is not a form list', $whole === null);
    } else {
        check('repeatingFormsFromMap: null without a map (per-form probe next)', $forms === null);
    }

    $events = priv($m, 'eventLayout', 149);
    $by = [];
    foreach (is_array($events) ? $events : [] as $row) {
        $key = is_array($row['forms']) ? implode(',', array_keys($row['forms'])) : '*';
        $by[$key] = $row;
    }
    check('eventLayout: one row per event', is_array($events) && count($events) === count(EVENTS));
    $e351 = $by['enrol,lab_reg,rep_form,result'] ?? null;
    $e353 = $by['enrol,visit'] ?? null;
    if ($mapRead) {
        check('eventLayout: rep_form repeats in event 351', $e351 && $e351['repeat'] === ['rep_form' => true] && $e351['repeatEvent'] === false);
        if ($LONG) check('eventLayout: event 353 repeats as a whole', $e353 && $e353['repeatEvent'] === true);
    } else {
        check('eventLayout: repeat state unknown without a map', $e351 && $e351['repeat'] === null && $e351['repeatEvent'] === false
            && (!$LONG || ($e353 && $e353['repeatEvent'] === false)));
    }

    // --- which source answered --------------------------------------------
    $calls = \REDCap::$staticCalls;
    if ($MODE === 'project') check('no static method exists to call', !is_callable(['\REDCap', 'getRepeatingFormsEvents']));
    if ($MODE === 'false')   check('the static is not asked once the Project answered', $calls === 0);
    if (in_array($MODE, ['throws', 'odd', 'static'], true)) check('the static answered', $calls > 0);

    // --- the scan's capability probe --------------------------------------
    $probe = ScanCapabilities::repeatMetadata(149);
    $via = (string) ($probe['via'] ?? '');
    $ok = ($probe['state'] ?? '') === ScanCapabilities::OK;
    if (in_array($MODE, ['project', 'throws', 'odd', 'false'], true)) {
        check("repeatMetadata names the Project method (got: $via)", $ok && $via === 'Project::getRepeatingFormsEvents');
    } elseif ($MODE === 'static') {
        check("repeatMetadata names the static method (got: $via)", $ok && $via === 'getRepeatingFormsEvents');
    } else {
        check("repeatMetadata: no source (got: $via)", !$ok);
    }
    check('repeatMetadata without a project skips the Project probe',
        $MODE !== 'project' || (ScanCapabilities::repeatMetadata()['state'] ?? '') !== ScanCapabilities::OK);

    echo "repeat_metadata_php [$MODE $LAYOUT]: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
