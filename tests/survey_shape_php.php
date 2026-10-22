<?php
/**
 * survey_shape_php.php — what a public survey page's config carries (B8 of the
 * 2026-10-21 review: AST-5, LIVE-2, CHO-6, WIN-3).
 *
 * A survey link is public, and the page shows a respondent no rule detail. Its
 * config must not carry any either:
 *   1) AST-5: a condition over another form is settled on the server to
 *      ["const", bool]; next to the condition text that constant states the
 *      saved value ("[hiv]='1'" + true). The text goes, the snapshot keeps
 *      only that there is one, and a rule deferred as a whole sends a fixed
 *      constant. Staff forms keep everything.
 *   2) LIVE-2, CHO-6: the page carried every configuration error of the
 *      project, with field names, codes and conditions of other forms. A
 *      survey page keeps only the errors of its own fields (of this page, on a
 *      survey shown one section per page), each as type, fields and a generic
 *      text.
 *   3) WIN-3 and the reasons: deferredWhy, windowFromOpWhy and
 *      windowNotFutureOff name other fields, the repeating layout and time
 *      zones; a survey page drops them.
 *
 * Run:  php tests/survey_shape_php.php
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
        public static function getData($p) { return self::$data; }
        public static function getDataDictionary($pid, $f = 'array') { return self::$dictionary; }
        public static function getUserRights($pid = null, $user = null) { return self::$rights; }
        public static function getGroupNames($u = false, $g = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
    }
    /** The survey of "s1" is shown one section per page. */
    class Project {
        public $project = ['missing_data_codes' => ''];
        public $forms = [];
        public $surveys = [];
        public function __construct($pid) {
            $this->forms = ['s1' => ['survey_id' => 7], 'lab_form' => []];
            $this->surveys = [7 => ['form_name' => 's1', 'question_by_section' => 1]];
        }
    }

    require_once __DIR__ . '/../UniversalValidator.php';

    use INSPIRE\UniversalValidator\ModeRegistry;

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    function f($form, $ann = '', $type = 'text', $validation = '', $choices = '', $section = '') {
        return ['field_type' => $type, 'form_name' => $form, 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => $validation,
                'select_choices_or_calculations' => $choices, 'section_header' => $section];
    }
    // lab_form: staff only. s1: the survey, page 1 (s_q, s_dose, s_bad) and
    // page 2 (s_p2, s_p2bad, which starts at a section header).
    $DICT = [
        'record_id' => f('lab_form'),
        'hiv'       => f('lab_form', '', 'radio', '', '0, Negative | 1, Positive'),
        'lab_max'   => f('lab_form', '', 'text', 'number'),
        'lab_bad'   => f('lab_form', '@UVCHOICES={"show":["7","8"]}', 'radio', '', '1, One | 2, Two'),
        's_q'       => f('s1', '@UVASSERT={"assert":"[s_q]<>\'\'","when":"[hiv]=\'1\'","message":"Answer it"}'),
        's_dose'    => f('s1', '@UVASSERT={"assert":"[s_dose]<=[lab_max]","message":"Too much","blockSave":"hard"}', 'text', 'number'),
        's_bad'     => f('s1', '@UVASSERT={"assert":"[s_bad]>"}'),
        's_p2'      => f('s1', '', 'text', '', '', 'Page 2'),
        's_p2bad'   => f('s1', '@UVASSERT={"assert":"[nope_field]=\'1\'"}'),
    ];
    $DATA = ['1' => [351 => ['record_id' => '1', 'hiv' => '1', 'lab_max' => '40', 's_q' => '', 's_dose' => '55']]];
    $FULL = ['nurse' => ['forms' => ['lab_form' => '1', 's1' => '1']]];

    function mod($dict, $data, $user) {
        $GLOBALS['__TEST_USER'] = $user;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectSettings = ['log-values' => ''];
        $m->projectIdReturn = 149;
        \REDCap::$dictionary = $dict;
        \REDCap::$data = $data;
        \REDCap::$rights = $GLOBALS['FULL'];
        return $m;
    }
    function page($m, $ctx, $form, $surveyPage = null) {
        if ($surveyPage === null) unset($_GET['__page__']); else $_GET['__page__'] = (string) $surveyPage;
        ob_start();
        if ($ctx === 'survey') $m->redcap_survey_page_top(149, '1', $form, 351, null, 'hash', null, 1);
        else $m->redcap_data_entry_form_top(149, '1', $form, 351, null, 1);
        $html = ob_get_clean();
        unset($_GET['__page__']);
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return ['html' => $html, 'cfg' => json_decode(isset($mm[1]) ? $mm[1] : 'null', true)];
    }
    function ruleFor($p, $f) {
        foreach ((isset($p['cfg']['rules']) ? $p['cfg']['rules'] : []) as $r) {
            if (in_array($f, isset($r['fields']) ? $r['fields'] : [], true)) return $r;
        }
        return null;
    }
    function errorFields($p) {
        $out = [];
        foreach ((isset($p['cfg']['rules']) ? $p['cfg']['rules'] : []) as $r) {
            if (!empty($r['configError'])) foreach ($r['fields'] as $f) $out[] = $f;
        }
        sort($out);
        return $out;
    }

    // ---- 1) AST-5: a condition over another form ------------------------------------
    $sv = page(mod($DICT, $DATA, null), 'survey', 's1', 1);
    $q = ruleFor($sv, 's_q');
    check('survey: the rule over another form is sent (got ' . json_encode($q) . ')', $q !== null);
    check('survey: its condition text is a placeholder', $q && ($q['when'] ?? null) === '(condition)');
    check('survey: the page still gets the answer to evaluate', $q && ($q['whenAst'] ?? null) === ['const', true]);
    check('survey: the snapshot is kept as present, without the field name', $q && ($q['snapshotFields'] ?? null) === ['']);
    check('survey: "hiv" and its literal appear nowhere in the rule',
        $q && strpos(json_encode($q), 'hiv') === false && strpos(json_encode($q), "'1'") === false);
    check('survey: the test of its own field keeps a live AST', $q && isset($q['assertAst']) && strpos(json_encode($q['assertAst']), 's_q') !== false);
    $dose = ruleFor($sv, 's_dose');
    check('survey: a rule deferred as a whole is sent, deferred', $dose && !empty($dose['deferred']));
    check('survey: ...with a placeholder and a fixed constant, never its settled answer',
        $dose && ($dose['assert'] ?? null) === '(condition)' && ($dose['assertAst'] ?? null) === ['const', true]);
    check('survey: ...and no reason naming the other form', $dose && !isset($dose['deferredWhy']) && strpos(json_encode($dose), 'lab_max') === false);
    // The settled answer of the deferred test would be false: 55 > 40. The
    // page must not carry it, whatever the saved values are.
    $sv0 = page(mod($DICT, ['1' => [351 => ['record_id' => '1', 'hiv' => '0', 'lab_max' => '99']]], null), 'survey', 's1', 1);
    check('survey: the deferred rule sends the same constant for other saved values',
        (ruleFor($sv0, 's_dose')['assertAst'] ?? null) === ['const', true]);
    check('survey: the settled gate follows the saved value, as the page behaves',
        (ruleFor($sv0, 's_q')['whenAst'] ?? null) === ['const', false] && (ruleFor($sv0, 's_q')['when'] ?? null) === '(condition)');
    $st = page(mod($DICT, $DATA, 'nurse'), 'form', 's1');
    $qs = ruleFor($st, 's_q');
    check('staff form: the condition text and the field names of the snapshot stay (got ' . json_encode($qs) . ')',
        $qs && ($qs['when'] ?? null) === "[hiv]='1'" && ($qs['snapshotFields'] ?? null) === ['hiv']);

    // ---- 2) LIVE-2, CHO-6: configuration errors ---------------------------------------
    check('survey page 1: only its own field\'s error is sent (got ' . json_encode(errorFields($sv)) . ')', errorFields($sv) === ['s_bad']);
    $bad = ruleFor($sv, 's_bad');
    check('survey: the error keeps only type, fields and a generic text',
        $bad && array_keys($bad) === ['type', 'fields', 'configError']
        && $bad['configError'] === ModeRegistry::surveyShape()['configErrorText']);
    check('survey: no other form\'s error text, field or codes reach the page',
        strpos($sv['html'], 'lab_bad') === false && strpos($sv['html'], '"7"') === false && strpos($sv['html'], 'nope_field') === false);
    $sv2 = page(mod($DICT, $DATA, null), 'survey', 's1', 2);
    check('survey page 2: its own field\'s error, not page 1\'s (got ' . json_encode(errorFields($sv2)) . ')', errorFields($sv2) === ['s_p2bad']);
    $svAll = page(mod($DICT, $DATA, null), 'survey', 's1');
    check('survey without a page number: the errors of the whole instrument', errorFields($svAll) === ['s_bad', 's_p2bad']);
    check('staff form: every configuration error, with its text',
        errorFields($st) === ['lab_bad', 's_bad', 's_p2bad'] && strpos(ruleFor($st, 'lab_bad')['configError'], '7') !== false);

    // ---- 3) WIN-3 and the reasons a rule is not checked ----------------------------
    $win = ['type' => 'window', 'fields' => ['s_visit'], 'windowFrom' => '[lab_date]', 'windowFromOp' => ['withheld'],
            'windowFromOpWhy' => ['references "[lab_date]", which is on a different repeating instrument'],
            'windowNotFutureOff' => ['The server and the project are in different time zones.'],
            'deferred' => true, 'deferredOnSave' => true, 'deferredWhy' => ['references "[lab_date]"'],
            'when' => "[hiv]='1'", 'whenAst' => ['const', true],
            'growthChartWhy' => ['chart' => 'the page cap'],
            'branches' => [['when' => "[hiv]='0'", 'whenAst' => ['const', false], 'deferredWhy' => ['x'], 'snapshotFields' => ['hiv']]]];
    $s = ModeRegistry::clientShape($win, true);
    check('survey: the window diagnostics are dropped',
        !isset($s['windowFromOpWhy']) && !isset($s['windowNotFutureOff']) && !isset($s['deferredWhy']));
    check('survey: a rule checked on save keeps its live conditions, without text',
        $s['when'] === '(condition)' && $s['whenAst'] === ['const', true] && $s['windowFromOp'] === ['withheld']);
    check('survey: a chart reason keeps only that there is one', ($s['growthChartWhy'] ?? null) === ['chart' => '']);
    check('survey: each branch is shaped too',
        $s['branches'][0]['when'] === '(condition)' && $s['branches'][0]['snapshotFields'] === ['']
        && !isset($s['branches'][0]['deferredWhy']) && $s['branches'][0]['whenAst'] === ['const', false]);
    $f = ModeRegistry::clientShape($win, false);
    check('staff form: nothing of it is removed', $f === $win);
    $err = ModeRegistry::clientShape(['type' => 'choices', 'fields' => ['x'], 'configError' => '@UVCHOICES code "7" is not one of 1, 2',
        'choicesShow' => ['7'], 'when' => "[hiv]='1'"], true);
    check('survey: any mode\'s configuration error is generic (CHO-6)',
        $err === ['type' => 'choices', 'fields' => ['x'], 'configError' => ModeRegistry::surveyShape()['configErrorText']]);

    echo "survey_shape_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
