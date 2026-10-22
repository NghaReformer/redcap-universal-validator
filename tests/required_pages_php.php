<?php
/**
 * required_pages_php.php — @UVREQUIRED and what REDCap shows (REQ-1, REQ-4).
 *
 * REQ-1: REDCap hides a field whose branching logic is false, leaves it out of
 * its own required check and erases its value on save. The post-save audit
 * and the scan read the field's branching logic in the condition dialect and
 * skip a hidden field. Logic the dialect cannot read, or that references a
 * value the context could not resolve, is a rule problem and never a finding.
 *
 * REQ-4: a survey shown one section per page renders only the current page's
 * fields. A "when" over a field answered on another page is settled on the
 * server from the saved response, as a field of another form is, instead of
 * going to the browser as a live reference to a field that is not there.
 *
 * The browser twins are tests/required_dom_js.cjs (hidden rows) and
 * tests/readonly_guard_dom_js.cjs (@READONLY options).
 *
 * Run:  php tests/required_pages_php.php
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
        public $lastInsertId = 0;
        public function query($sql, $params = []) {
            if (strpos($sql, 'SELECT LAST_INSERT_ID()') !== false) return [[$this->lastInsertId]];
            if (strpos($sql, 'SELECT ROW_COUNT()') !== false) return [[1]];
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
    class REDCap {
        public static $data = [];
        public static $dictionary = [];
        public static $rights = [];
        /** Every getData call's field list, so a test can see what was read. */
        public static $readFields = [];
        public static function getData($p) {
            if (isset($p['fields'])) self::$readFields[] = (array) $p['fields'];
            $out = [];
            foreach (self::$data as $rec => $node) {
                if (!empty($p['records']) && !in_array((string) $rec, array_map('strval', (array) $p['records']), true)) continue;
                $out[$rec] = $node;
            }
            return $out;
        }
        public static function getDataDictionary($pid, $f = 'array') {
            if (!$pid) throw new \RuntimeException('needs pid');
            return self::$dictionary;
        }
        public static function getEventNames($unique = false, $assoc = false, $id = null) { return [351 => 'enrolment_arm_1']; }
        public static function getUserRights($u = null) { return self::$rights; }
        public static function getGroupNames($unique = false, $g = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
    }
    /** REDCap's \Project: the forms' survey ids and the surveys' paging. */
    class Project {
        public static $bySection = 1;
        public $project = ['missing_data_codes' => ''];
        public $forms = [];
        public $surveys = [];
        public function __construct($pid) {
            $this->forms = ['enrol' => ['survey_id' => 7], 'visit' => []];
            $this->surveys = [7 => ['form_name' => 'enrol', 'question_by_section' => self::$bySection]];
        }
    }

    require_once __DIR__ . '/../UniversalValidator.php';

    use INSPIRE\UniversalValidator\UniversalValidator;

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    function f($form, $ann = '', $branching = '', $type = 'text', $header = '', $choices = '') {
        return ['field_type' => $type, 'form_name' => $form, 'field_annotation' => $ann,
                'branching_logic' => $branching, 'section_header' => $header,
                'text_validation_type_or_show_slider_number' => '', 'identifier' => '',
                'select_choices_or_calculations' => $choices];
    }
    $HARD = '@UVREQUIRED={"blockSave":"hard"}';
    $DICT = [
        'record_id' => f('enrol'),
        // page 1 of the survey
        'consent'   => f('enrol', '', '', 'yesno'),
        'age'       => f('enrol', '', '', 'text'),
        // page 2 (a section header starts it)
        'phone'     => f('enrol', '@UVREQUIRED={"when":"[consent]=\'1\'","blockSave":"hard"}', '', 'text', 'Contact'),
        'phone_alt' => f('enrol', '@UVREQUIRED={"when":"[consent]<>\'0\'","blockSave":"hard"}', '', 'text'),
        'mix'       => f('enrol', '@UVREQUIRED={"when":"[consent]=\'1\' and [phone]<>\'\'","blockSave":"hard"}', '', 'text'),
        // page 3
        'notes'     => f('enrol', '', '', 'notes', 'Notes'),
        // the visit form: branching logic
        'sex'       => f('visit', '', '', 'radio', '', '1, Male | 2, Female'),
        'preg'      => f('visit', $HARD, "[sex]='2'"),
        'sym'       => f('visit', '', '', 'checkbox', '', '1, Cough | 2, Fever'),
        'fever_c'   => f('visit', $HARD, "[sym(2)]='1'"),
        'adult_q'   => f('visit', $HARD, '[age]>=18'),
        'odd'       => f('visit', $HARD, 'datediff([dob],"today","y")>=18'),
        'plain'     => f('visit', $HARD),
    ];
    $FULL = ['nurse' => ['forms' => ['enrol' => '1', 'visit' => '1']]];

    function mod(array $data) {
        global $DICT, $FULL;
        $GLOBALS['__TEST_USER'] = 'nurse';
        $m = new UniversalValidator();
        $m->projectSettings = ['log-values' => 'raw'];
        $m->projectIdReturn = 149;
        \REDCap::$dictionary = $DICT;
        \REDCap::$data = $data;
        \REDCap::$rights = $FULL;
        \REDCap::$readFields = [];
        return $m;
    }
    function logged($m, $type) {
        return array_values(array_map(function ($c) { return $c[1]; },
            array_filter($m->logCalls, function ($c) use ($type) { return $c[0] === $type; })));
    }
    function fieldsOf(array $entries, $key = 'field') {
        $out = [];
        foreach ($entries as $e) foreach (explode(', ', (string) $e[$key]) as $x) $out[$x] = true;
        ksort($out);
        return array_keys($out);
    }
    function whyFor(array $entries, $field) {
        foreach ($entries as $e) if (strpos((string) $e['fields'], $field) !== false) return (string) ($e['why'] ?? $e['reason'] ?? json_encode($e));
        return null;
    }
    $row = function (array $v) { return array_merge(['record_id' => '1', 'age' => '', 'consent' => ''], $v); };

    // ---- 1) the audit skips a field its branching logic hides -----------------------
    $male = ['1' => [351 => $row(['sex' => '1', 'preg' => '', 'sym' => ['1' => '1', '2' => '0'], 'fever_c' => '',
                                  'adult_q' => '', 'odd' => '', 'plain' => 'x'])]];
    $m = mod($male);
    $m->redcap_save_record(149, '1', 'visit', 351, null, null, null, 1);
    $bad = fieldsOf(logged($m, 'invalid-id-saved'));
    check('audit: hidden by [sex]=\'2\' (Male), [sym(2)]=\'1\' (no fever), [age]>=18 (age blank) — no finding (got ' . json_encode($bad) . ')',
        $bad === []);
    $read = array_merge([], ...\REDCap::$readFields);
    check('audit: the branching logic\'s fields are read with the rule\'s (got ' . json_encode(array_values(array_unique($read))) . ')',
        in_array('sex', $read, true) && in_array('sym', $read, true) && in_array('age', $read, true));
    $unc = logged($m, 'uvalidate-unconfigurable');
    $why = whyFor($unc, 'odd');
    check('audit: logic the dialect cannot read is a rule problem (got ' . json_encode($why) . ')',
        $why !== null && strpos($why, 'branching logic of [odd]') !== false && strpos($why, 'not checked') !== false);
    check('audit: and never a finding', !in_array('odd', $bad, true));

    $female = ['1' => [351 => $row(['sex' => '2', 'preg' => '', 'sym' => ['1' => '0', '2' => '1'], 'fever_c' => '',
                                    'age' => '40', 'adult_q' => '', 'odd' => '', 'plain' => ''])]];
    $m = mod($female);
    $m->redcap_save_record(149, '1', 'visit', 351, null, null, null, 1);
    $bad = fieldsOf(logged($m, 'invalid-id-saved'));
    check('audit: shown by its branching logic, blank is a finding (got ' . json_encode($bad) . ')',
        $bad === ['adult_q', 'fever_c', 'plain', 'preg']);

    $answered = ['1' => [351 => $row(['sex' => '2', 'preg' => 'neg', 'sym' => ['2' => '1'], 'fever_c' => '38.5',
                                      'age' => '40', 'adult_q' => 'y', 'odd' => 'x', 'plain' => 'x'])]];
    $m = mod($answered);
    $m->redcap_save_record(149, '1', 'visit', 351, null, null, null, 1);
    check('audit: answered fields — no finding and no rule problem',
        logged($m, 'invalid-id-saved') === [] && logged($m, 'uvalidate-unconfigurable') === []);

    // ---- 2) the scan gives the same answers -----------------------------------------
    $m = mod(['1' => $male['1'], '2' => ['351' => array_merge($female['1'][351], ['record_id' => '2'])],
              '3' => ['351' => array_merge($answered['1'][351], ['record_id' => '3'])]]);
    \REDCap::$data['1'][351]['visit_complete'] = '0';
    \REDCap::$data['2'][351]['visit_complete'] = '0';
    \REDCap::$data['3'][351]['visit_complete'] = '0';
    $res = $m->scanProject(149);
    $hits = [];
    foreach ($res['violations'] as $v) if (($DICT[$v['field']]['form_name'] ?? '') === 'visit') $hits[] = $v['record'] . ':' . $v['field'];
    sort($hits);
    check('scan: on the visit form, only where the branching logic shows the field (got ' . json_encode($hits) . ')',
        $hits === ['2:adult_q', '2:fever_c', '2:plain', '2:preg']);
    $scanUnc = json_encode(isset($res['unconfigurable']) ? $res['unconfigurable'] : []);
    check('scan: the unreadable logic is a rule problem there too (got ' . $scanUnc . ')',
        strpos($scanUnc, 'branching logic of [odd]') !== false);

    // ---- 3) a multi-page survey settles another page's fields on the server ---------
    function pageCfg($m, $record, $page) {
        if ($page === null) unset($_GET['__page__']); else $_GET['__page__'] = $page;
        ob_start();
        $m->redcap_survey_page_top(149, $record, 'enrol', 351, null, 'h', null, 1);
        $html = ob_get_clean();
        if (!preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm)) return null;
        return json_decode($mm[1], true);
    }
    function ruleOf($cfg, $field) {
        foreach (($cfg['rules'] ?? []) as $r) if (in_array($field, $r['fields'] ?? [], true)) return $r;
        return null;
    }
    $resp = function ($consent) { return ['5' => [351 => ['record_id' => '5', 'consent' => $consent, 'age' => '30']]]; };

    \Project::$bySection = 1;
    $cfg = pageCfg(mod($resp('1')), '5', '2');
    $r = ruleOf($cfg, 'phone');
    check('survey page 2: [consent]=\'1\', answered 1 on page 1, ships as a settled true (got ' . json_encode($r['whenAst'] ?? null) . ')',
        ($r['whenAst'] ?? null) === ['const', true]);
    $r = ruleOf($cfg, 'phone_alt');
    check('survey page 2: [consent]<>\'0\' with consent 1 is settled true', ($r['whenAst'] ?? null) === ['const', true]);
    $r = ruleOf($cfg, 'mix');
    check('survey page 2: the page-1 half is settled, the page-2 half stays live (got ' . json_encode($r['whenAst'] ?? null) . ')',
        json_encode($r['whenAst'] ?? null) === json_encode(['and', [['const', true], ['cmp', '<>', ['ref', 'phone', null], ['lit', '']]]]));
    check('survey page 2: no value of the response is in the page', strpos(json_encode($cfg), '"30"') === false);

    $cfg = pageCfg(mod($resp('0')), '5', '2');
    check('survey page 2: consent 0 settles [consent]=\'1\' false', (ruleOf($cfg, 'phone')['whenAst'] ?? null) === ['const', false]);
    check('survey page 2: and [consent]<>\'0\' false', (ruleOf($cfg, 'phone_alt')['whenAst'] ?? null) === ['const', false]);
    check('survey page 2: not deferred (the value cannot change on this page)', empty(ruleOf($cfg, 'phone')['deferred']));

    $cfg = pageCfg(mod($resp('1')), '5', '1');
    check('survey page 1: consent is on this page, so the condition stays live (got ' . json_encode(ruleOf($cfg, 'phone')['whenAst'] ?? null) . ')',
        (ruleOf($cfg, 'phone')['whenAst'] ?? null) === ['cmp', '=', ['ref', 'consent', null], ['lit', '1']]);

    $cfg = pageCfg(mod($resp('1')), '5', null);
    check('survey, page not given: unchanged, the condition stays live',
        (ruleOf($cfg, 'phone')['whenAst'] ?? null) === ['cmp', '=', ['ref', 'consent', null], ['lit', '1']]);
    $cfg = pageCfg(mod($resp('1')), '5', '9');
    check('survey, a page that does not exist: unchanged',
        (ruleOf($cfg, 'phone')['whenAst'] ?? null) === ['cmp', '=', ['ref', 'consent', null], ['lit', '1']]);
    $cfg = pageCfg(mod($resp('1')), '5', '2 or 1');
    check('survey, a page that is not a number: unchanged',
        (ruleOf($cfg, 'phone')['whenAst'] ?? null) === ['cmp', '=', ['ref', 'consent', null], ['lit', '1']]);

    \Project::$bySection = 0;
    $cfg = pageCfg(mod($resp('1')), '5', '1');
    check('survey on one page: every field is on it, the condition stays live',
        (ruleOf($cfg, 'phone')['whenAst'] ?? null) === ['cmp', '=', ['ref', 'consent', null], ['lit', '1']]);
    \Project::$bySection = 1;

    // the data entry form is one page whatever __page__ says
    $_GET['__page__'] = '2';
    $m = mod($resp('1'));
    ob_start();
    $m->redcap_data_entry_form_top(149, '5', 'enrol', 351, null, 1);
    $html = ob_get_clean();
    preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
    $cfg = json_decode($mm[1] ?? 'null', true);
    check('data entry form: not paged, the condition stays live',
        (ruleOf($cfg, 'phone')['whenAst'] ?? null) === ['cmp', '=', ['ref', 'consent', null], ['lit', '1']]);
    unset($_GET['__page__']);
    check('data entry form: no partialSave in the config (REQ-7)', $cfg !== null && !isset($cfg['partialSave']));

    // ---- 5) REQ-7: a survey page names the saves that do not finish the response ---
    $cfg = pageCfg(mod($resp('1')), '5', '1');
    check('survey page: partialSave names Previous Page and Save & Return Later, and the required type (got '
        . json_encode($cfg['partialSave'] ?? null) . ')',
        ($cfg['partialSave'] ?? null) === ['buttons' => ['submit-btn-saveprevpage', 'submit-btn-savereturnlater'], 'types' => ['required']]);

    // ---- 6) REQ-5: a survey response still open is not judged for @UVREQUIRED ------
    // REDCap saves each page a respondent leaves; phone (page 2) is blank while
    // the response is open. The instrument's status says when it is complete.
    $open = function ($status) {
        $row = ['record_id' => '5', 'consent' => '1', 'age' => '30', 'phone' => '', 'phone_alt' => '', 'mix' => ''];
        if ($status !== null) $row['enrol_complete'] = $status;
        return ['5' => [351 => $row]];
    };
    $reqLogs = function ($m) { return fieldsOf(array_filter(logged($m, 'invalid-id-saved'), function ($e) { return ($e['type'] ?? '') === 'required'; })); };
    $_GET['__page__'] = '1';
    $m = mod($open('0'));
    $m->redcap_save_record(149, '5', 'enrol', 351, null, 'surveyhash', '55', 1);
    check('survey, page 1 submitted (status 0): page 2\'s blank phone is not logged (got ' . json_encode($reqLogs($m)) . ')', $reqLogs($m) === []);
    $read = array_merge([], ...\REDCap::$readFields);
    check('survey: the instrument\'s status is read with the rule\'s fields', in_array('enrol_complete', $read, true));
    unset($_GET['__page__']);
    $m = mod($open('2'));
    $m->redcap_save_record(149, '5', 'enrol', 351, null, 'surveyhash', '55', 1);
    check('survey completed (status 2): the blank phone is logged (got ' . json_encode($reqLogs($m)) . ')', in_array('phone', $reqLogs($m), true));
    $m = mod($open(null));
    $m->redcap_save_record(149, '5', 'enrol', 351, null, 'surveyhash', '55', 1);
    check('survey, status not readable: judged, as before', in_array('phone', $reqLogs($m), true));
    $m = mod($open('0'));
    $m->redcap_save_record(149, '5', 'enrol', 351, null, null, null, 1);
    check('a data entry save of an incomplete form is judged: staff saved it', in_array('phone', $reqLogs($m), true));
    $m = mod($open('0'));
    $m->redcap_save_record(149, '5', 'enrol', 351, null, null, null, 1);
    check('a data entry save does not read the status for this', !in_array('enrol_complete', array_merge([], ...\REDCap::$readFields), true));

    // ---- 7) REQ-8: one blank test, the rows of tests/blank_fixture.json -----------
    $bf = json_decode(file_get_contents(__DIR__ . '/blank_fixture.json'), true);
    foreach ($bf['rows'] as $brow) {
        $m = mod(['1' => [351 => $row(['sex' => '1', 'sym' => ['1' => '0', '2' => '0'], 'preg' => 'x', 'fever_c' => 'x',
                                       'adult_q' => 'x', 'odd' => 'x', 'plain' => $brow['value']])]]);
        $m->redcap_save_record(149, '1', 'visit', 351, null, null, null, 1);
        $isFinding = in_array('plain', fieldsOf(logged($m, 'invalid-id-saved')), true);
        check('audit, blank fixture "' . $brow['name'] . '": ' . ($brow['blank'] ? 'a finding' : 'an answer'), $isFinding === $brow['blank']);
    }

    echo "required_pages_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
