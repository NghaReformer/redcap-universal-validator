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
 * Review 2026-11-13. REQ-2: a field @HIDDEN, @HIDDEN-FORM or @HIDDEN-SURVEY
 * hides is not required in the audit and the scan either. REQ-3: a page turn
 * of an open survey response logs no required field of another form, and a
 * standing blank is logged once while the module log can be read. REQ-4: branching
 * logic REDCap may read differently from the dialect (a blank compared by
 * order, text that differs by letter case) leaves the field unchecked.
 *
 * The browser twins are tests/required_dom_js.cjs (hidden rows) and
 * tests/readonly_guard_dom_js.cjs (@READONLY options).
 *
 * Run:  php tests/required_pages_php.php
 */

namespace ExternalModules {
    if (PHP_SAPI !== 'cli') {
        // A test or a tool, never a page: answer a web request with 404 and stop.
        http_response_code(404);
        exit;
    }

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
    require_once __DIR__ . '/support/scan_facade.php';

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
        'email'     => f('enrol', '', '', 'text'),
        // page 2 (a section header starts it)
        'phone'     => f('enrol', '@UVREQUIRED={"when":"[consent]=\'1\'","blockSave":"hard"}', '', 'text', 'Contact'),
        'phone_alt' => f('enrol', '@UVREQUIRED={"when":"[consent]<>\'0\'","blockSave":"hard"}', '', 'text'),
        'mix'       => f('enrol', '@UVREQUIRED={"when":"[consent]=\'1\' and [phone]<>\'\'","blockSave":"hard"}', '', 'text'),
        // a choice filter on page 2 over page 1's answer (CHO-15)
        'pick'      => f('enrol', '@UVCHOICES={"when":"[consent]=\'1\'","hide":["9"]}', '', 'radio', '', '1, A | 9, Other'),
        // @UVASSERT on page 2 over page 1's answers (AST-2, AST-6)
        'email_c'   => f('enrol', '@UVASSERT={"assert":"[email]=[email_c]","message":"The two addresses differ","blockSave":"hard"}'),
        'gated'     => f('enrol', '@UVASSERT={"assert":"[gated]<>\'bad\'","when":"[consent]=\'1\'","blockSave":"hard"}'),
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
    check('survey page 2: a @UVCHOICES "when" over page 1 is settled the same way (got ' . json_encode(ruleOf($cfg, 'pick')['whenAst'] ?? null) . ')',
        (ruleOf($cfg, 'pick')['whenAst'] ?? null) === ['const', true]);

    $cfg = pageCfg(mod($resp('0')), '5', '2');
    check('survey page 2: consent 0 settles [consent]=\'1\' false', (ruleOf($cfg, 'phone')['whenAst'] ?? null) === ['const', false]);
    check('survey page 2: and [consent]<>\'0\' false', (ruleOf($cfg, 'phone_alt')['whenAst'] ?? null) === ['const', false]);
    check('survey page 2: not deferred (the value cannot change on this page)', empty(ruleOf($cfg, 'phone')['deferred']));

    $cfg = pageCfg(mod($resp('1')), '5', '1');
    check('survey page 1: consent is on this page, so the condition stays live (got ' . json_encode(ruleOf($cfg, 'phone')['whenAst'] ?? null) . ')',
        (ruleOf($cfg, 'phone')['whenAst'] ?? null) === ['cmp', '=', ['ref', 'consent', null], ['lit', '1']]);

    // AST-6: a survey of several pages whose page cannot be told. The whole
    // instrument was taken to be on the page, so a field of another page went
    // to the browser as a live reference and read blank there: two matching
    // addresses were held by the hard block. No field is known to be on the
    // page, so a rule with a condition is not checked there.
    $same = ['5' => [351 => ['record_id' => '5', 'consent' => '1', 'age' => '30', 'email' => 'a@x.org', 'email_c' => 'a@x.org']]];
    foreach ([null, '9', '2 or 1', '0'] as $badPage) {
        $cfg = pageCfg(mod($same), '5', $badPage);
        $label = 'survey of several pages, page ' . json_encode($badPage);
        foreach (['phone', 'email_c', 'gated'] as $field) {
            $r = ruleOf($cfg, $field);
            check($label . ': the rule on ' . $field . ' is deferred, never checked against a field that may not be there (got '
                . json_encode($r) . ')', $r !== null && !empty($r['deferred']));
            check($label . ': no live reference to another field goes to the page for ' . $field,
                strpos(json_encode($r), '["ref","consent"') === false && strpos(json_encode($r), '["ref","email"') === false);
        }
        check($label . ': no value of the response is in the page', strpos(json_encode($cfg), 'a@x.org') === false);
    }

    \Project::$bySection = 0;
    $cfg = pageCfg(mod($resp('1')), '5', '1');
    check('survey on one page: every field is on it, the condition stays live',
        (ruleOf($cfg, 'phone')['whenAst'] ?? null) === ['cmp', '=', ['ref', 'consent', null], ['lit', '1']]);
    $cfg = pageCfg(mod($same), '5', null);
    check('survey on one page, page not given: nothing is deferred, both addresses are live (got ' . json_encode(ruleOf($cfg, 'email_c')) . ')',
        empty(ruleOf($cfg, 'email_c')['deferred']) && empty(ruleOf($cfg, 'phone')['deferred'])
        && (ruleOf($cfg, 'email_c')['assertAst'] ?? null) === ['cmp', '=', ['ref', 'email', null], ['ref', 'email_c', null]]);
    \Project::$bySection = 1;

    // AST-2: what a page of the survey does to a rule over another page's
    // answer. Nothing blocks on a value the page cannot see.
    $differ = ['5' => [351 => ['record_id' => '5', 'consent' => '1', 'age' => '30', 'email' => 'a@x.org', 'email_c' => 'b@x.org', 'gated' => 'bad']]];
    $cfg = pageCfg(mod($differ), '5', '2');
    $r = ruleOf($cfg, 'email_c');
    check('AST-2: [email]=[email_c] across pages is deferred on page 2: no verdict from a value the page cannot see (got ' . json_encode($r) . ')',
        !empty($r['deferred']) && strpos(json_encode($cfg), 'a@x.org') === false);
    $r = ruleOf($cfg, 'gated');
    check('AST-2: a "when" over page 1 is settled from the saved response and named as read once, so the page does not block on it (got '
        . json_encode($r) . ')',
        ($r['whenAst'] ?? null) === ['const', true] && count($r['snapshotFields'] ?? []) === 1 && !empty($r['snapshotGate'])
        && empty($r['deferred']));
    // The data entry form shows the whole instrument, so the study team is
    // told in the log, once per rule, when a respondent saves a page.
    $m = mod($differ);
    $_GET['__page__'] = '2';
    $m->redcap_save_record(149, '5', 'enrol', 351, null, 'surveyhash', '55', 1);
    unset($_GET['__page__']);
    $unc = logged($m, 'uvalidate-unconfigurable');
    $why = whyFor($unc, 'email_c');
    check('AST-2: a survey save logs that the rule on email_c reads [email] on another page and does not hold the save there (got '
        . json_encode($why) . ')',
        $why !== null && strpos($why, 'reads [email] on another page') !== false && strpos($why, 'does not hold the save') !== false);
    $why = whyFor($unc, 'gated');
    check('AST-2: and the rule on gated, for [consent]', $why !== null && strpos($why, 'reads [consent] on another page') !== false);
    $why = whyFor($unc, 'mix');
    check('AST-2: a rule that reads its own page too names only the other page\'s field (got ' . json_encode($why) . ')',
        $why !== null && strpos($why, 'reads [consent] on another page') !== false && strpos($why, '[phone]') === false);
    check('AST-2: the audit still reports the addresses that differ',
        in_array('email_c', fieldsOf(logged($m, 'invalid-id-saved')), true));
    $m = mod($differ);
    $m->redcap_save_record(149, '5', 'enrol', 351, null, null, null, 1);
    check('AST-2: a data entry save logs no such note: the form shows every field',
        whyFor(logged($m, 'uvalidate-unconfigurable'), 'email_c') === null);
    \Project::$bySection = 0;
    $m = mod($differ);
    $m->redcap_save_record(149, '5', 'enrol', 351, null, 'surveyhash', '55', 1);
    check('AST-2: nor does a survey shown on one page', whyFor(logged($m, 'uvalidate-unconfigurable'), 'email_c') === null);
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

    // ---- REQ-10: a save with no instrument leaves required rules to the scan -------
    // Every rule was in scope, so each required field of a form nobody had
    // opened was logged as blank, while the scan asks only started forms.
    $enrolOnly = ['1' => [351 => ['record_id' => '1', 'consent' => '0', 'age' => '40']]];
    $m = mod($enrolOnly);
    $m->redcap_save_record(149, '1', null, 351, null, null, null, 1);
    $req = array_values(array_filter(logged($m, 'invalid-id-saved'), function ($e) { return ($e['type'] ?? '') === 'required'; }));
    check('REQ-10: a save with no instrument logs no required field of the visit form nobody opened (got ' . json_encode(fieldsOf($req)) . ')', $req === []);
    $m = mod($enrolOnly);
    $m->redcap_save_record(149, '1', 'visit', 351, null, null, null, 1);
    check('REQ-10 control: a save of the visit form still reports its empty required field',
        in_array('plain', fieldsOf(logged($m, 'invalid-id-saved')), true));

    // ---- review 2026-11-13, REQ-2: a field an action tag hides is not required ------
    // The page skips a field whose row is hidden. The audit and the scan read only the
    // branching logic, so a blank @HIDDEN field was logged on every save. @HIDDEN-FORM
    // and @HIDDEN-SURVEY hide on one kind of page: the page of the save decides, and
    // the scan, which has no page, skips a field no page of its instrument shows.
    // In the Project stub "enrol" is a survey and "visit" is not.
    $KEEP = $DICT;
    $DICT = [
        'record_id' => f('enrol'),
        'e_form'    => f('enrol', '@HIDDEN-FORM ' . $HARD),
        'e_survey'  => f('enrol', '@HIDDEN-SURVEY ' . $HARD),
        'e_both'    => f('enrol', '@HIDDEN-FORM @HIDDEN-SURVEY ' . $HARD),
        'h_plain'   => f('visit', '@HIDDEN ' . $HARD),
        'h_form'    => f('visit', $HARD . ' @hidden-form'),
        'h_survey'  => f('visit', '@HIDDEN-SURVEY ' . $HARD),
        'h_app'     => f('visit', '@HIDDEN-APP @HIDDEN-PDF ' . $HARD),
        'h_text'    => f('visit', '@PLACEHOLDER="not @HIDDEN" ' . $HARD),
        'shown'     => f('visit', $HARD),
    ];
    $blankVisit = ['1' => [351 => ['record_id' => '1', 'visit_complete' => '2', 'enrol_complete' => '2']]];
    $m = mod($blankVisit);
    $m->redcap_save_record(149, '1', 'visit', 351, null, null, null, 1);
    $bad = fieldsOf(logged($m, 'invalid-id-saved'));
    check('REQ-2 form save: @HIDDEN and @HIDDEN-FORM fields are not required, the rest are (got ' . json_encode($bad) . ')',
        $bad === ['h_app', 'h_survey', 'h_text', 'shown']);
    check('REQ-2 form save: and no rule problem is logged for them', logged($m, 'uvalidate-unconfigurable') === []);
    $m = mod($blankVisit);
    $m->redcap_save_record(149, '1', 'visit', 351, null, 'hash', '9', 1);
    $bad = fieldsOf(logged($m, 'invalid-id-saved'));
    check('REQ-2 survey save: @HIDDEN and @HIDDEN-SURVEY fields are not required, the rest are (got ' . json_encode($bad) . ')',
        $bad === ['h_app', 'h_form', 'h_text', 'shown']);
    $m = mod($blankVisit);
    $m->redcap_save_record(149, '1', 'enrol', 351, null, null, null, 1);
    $bad = fieldsOf(logged($m, 'invalid-id-saved'));
    check('REQ-2 form save of a survey instrument: only the field its form shows (got ' . json_encode($bad) . ')', $bad === ['e_survey']);
    $m = mod($blankVisit);
    $res = $m->scanProject(149);
    $hits = [];
    foreach ($res['violations'] as $v) $hits[] = $v['field'];
    sort($hits);
    check('REQ-2 scan: a field no page of its instrument shows is skipped, one a page shows is reported (got ' . json_encode($hits) . ')',
        $hits === ['e_form', 'e_survey', 'h_app', 'h_survey', 'h_text', 'shown']);

    // ---- REQ-4: branching logic REDCap may read differently is not decided ----------
    // REDCap compares text as typed and answers an ordered comparison with a blank
    // side; the condition dialect folds case and has no answer there. Where the two
    // readings differ the requirement is not checked, and says so.
    $DICT = [
        'record_id' => f('enrol'),
        'age'       => f('visit'),
        'code'      => f('visit'),
        'minor_q'   => f('visit', $HARD, '[age] < 18'),
        'not_adult' => f('visit', $HARD, 'not([age] >= 18)'),
        'case_q'    => f('visit', $HARD, "[code] = 'a'"),
        'has_age'   => f('visit', $HARD, "[age] <> ''"),
    ];
    $visit = function (array $v) { return ['1' => [351 => array_merge(['record_id' => '1', 'age' => '', 'code' => ''], $v)]]; };
    $m = mod($visit(['age' => '', 'code' => 'A']));
    $m->redcap_save_record(149, '1', 'visit', 351, null, null, null, 1);
    $bad = fieldsOf(logged($m, 'invalid-id-saved'));
    $unc = logged($m, 'uvalidate-unconfigurable');
    check('REQ-4: "[age] < 18" with no age, and "[code] = \'a\'" with "A", are no finding (got ' . json_encode($bad) . ')', $bad === []);
    foreach (['minor_q', 'not_adult', 'case_q'] as $q) {
        $why = whyFor($unc, $q);
        check('REQ-4: [' . $q . '] is reported as not checked (got ' . json_encode($why) . ')',
            $why !== null && strpos($why, 'branching logic of [' . $q . ']') !== false && strpos($why, 'not checked') !== false);
    }
    check('REQ-4: "[age] <> \'\'" reads the same either way and is decided', whyFor($unc, 'has_age') === null);
    $m = mod($visit(['age' => '12', 'code' => 'a']));
    $m->redcap_save_record(149, '1', 'visit', 351, null, null, null, 1);
    $bad = fieldsOf(logged($m, 'invalid-id-saved'));
    check('REQ-4: with an age of 12 and the code as written, every shown field is a finding (got ' . json_encode($bad) . ')',
        $bad === ['case_q', 'has_age', 'minor_q', 'not_adult'] && logged($m, 'uvalidate-unconfigurable') === []);
    $m = mod($visit(['age' => '40', 'code' => 'b']));
    $m->redcap_save_record(149, '1', 'visit', 351, null, null, null, 1);
    $bad = fieldsOf(logged($m, 'invalid-id-saved'));
    check('REQ-4: with an age of 40 and another code, the hidden fields are skipped without a word (got ' . json_encode($bad) . ')',
        $bad === ['has_age'] && logged($m, 'uvalidate-unconfigurable') === []);
    $m = mod($visit(['age' => '', 'code' => 'A']));
    \REDCap::$data['1'][351]['visit_complete'] = '0';
    $res = $m->scanProject(149);
    check('REQ-4 scan: the same fields are no violation (got ' . json_encode(array_column($res['violations'], 'field')) . ')',
        $res['violations'] === []);

    // ---- REQ-3: a required rule whose "when" reads another form -------------------
    // Saving the form a "when" reads judges the rule on its own form too, as the scan
    // does (hosting_php.php, R3G-1): "an AE happened" is when the AE form is due. Two
    // things keep that from filling the log. A page turn of an open survey response
    // logs no required field, on this form or another (the fix). And a standing
    // finding is logged once, not on every save (PRF-9), which needs the module log:
    // the stub the review probed with had none, so every save wrote again.
    $DICT = [
        'record_id' => f('enrol'),
        'had_ae'    => f('enrol', '', '', 'yesno'),
        'max'       => f('enrol'),
        'ae_term'   => f('visit', '@UVREQUIRED={"when":"[had_ae]=\'1\'","blockSave":"hard"}'),
        'dose'      => f('visit', '@UVASSERT={"assert":"[dose]<=[max]","blockSave":"hard"}'),
    ];
    $rec = function (array $v) { return ['1' => [351 => array_merge(['record_id' => '1', 'had_ae' => '1', 'max' => '10', 'dose' => '50'], $v)]]; };
    $depLogs = function ($m) {
        $out = array_map(function ($e) { return $e['field'] . '@' . ($e['instrument'] ?? ''); }, logged($m, 'invalid-id-saved'));
        sort($out);
        return $out;
    };
    $m = mod($rec(['enrol_complete' => '0']));
    $m->redcap_save_record(149, '1', 'enrol', 351, null, 'hash', '9', 1);
    check('REQ-3: a survey page turn of an open response logs no required field of another form, a value check there still is (got ' . json_encode($depLogs($m)) . ')',
        $depLogs($m) === ['dose@visit']);
    $m = mod($rec(['enrol_complete' => '2']));
    $m->redcap_save_record(149, '1', 'enrol', 351, null, 'hash', '9', 1);
    check('REQ-3: the finished response logs it, on its own form (got ' . json_encode($depLogs($m)) . ')',
        $depLogs($m) === ['ae_term@visit', 'dose@visit']);
    $m = mod($rec(['enrol_complete' => '0']));
    $m->redcap_save_record(149, '1', 'enrol', 351, null, null, null, 1);
    check('REQ-3: a data entry save logs it whatever the form\'s status (got ' . json_encode($depLogs($m)) . ')',
        $depLogs($m) === ['ae_term@visit', 'dose@visit']);
    $m = mod($rec(['enrol_complete' => '2', 'had_ae' => '0']));
    $m->redcap_save_record(149, '1', 'enrol', 351, null, null, null, 1);
    check('REQ-3: a "when" that is false logs no required field', $depLogs($m) === ['dose@visit']);
    // one request per save, the module log read back as the framework's table would be
    $withLog = function (array $stored, array $data) {
        $m = new class extends UniversalValidator {
            public $stored = [];
            public function queryLogs($sql, $params)
            {
                if (!preg_match('/ and (record|record_hmac|instrument) = \?$/', $sql, $mm)) throw new \Exception('unexpected log query: ' . $sql);
                $rows = [];
                foreach ($this->stored as $i => $c) {
                    if ($c[0] === $params[0] && isset($c[1][$mm[1]]) && (string) $c[1][$mm[1]] === $params[1] && isset($c[1]['fingerprint'])) {
                        $rows[] = ['log_id' => $i + 1, 'fingerprint' => $c[1]['fingerprint'], 'finding' => $c[1]['finding'] ?? null];
                    }
                }
                return new class($rows) {
                    private $r;
                    public function __construct($r) { $this->r = $r; }
                    public function fetch_assoc() { return array_shift($this->r); }
                };
            }
        };
        mod($data);
        $m->stored = $stored;
        $m->projectSettings = ['log-values' => 'raw'];
        $m->projectIdReturn = 149;
        $m->systemSettings['log-hmac-key'] = str_repeat('ab', 32);
        $m->redcap_save_record(149, '1', 'enrol', 351, null, null, null, 1);
        return $m;
    };
    $s1 = $withLog([], $rec(['enrol_complete' => '2']));
    check('REQ-3: with the module log, the first save logs the blank (got ' . json_encode($depLogs($s1)) . ')',
        in_array('ae_term@visit', $depLogs($s1), true));
    $s2 = $withLog($s1->logCalls, $rec(['enrol_complete' => '2']));
    check('REQ-3: a second save of the same record logs nothing more (got ' . json_encode($depLogs($s2)) . ')', $depLogs($s2) === []);
    $s3 = $withLog(array_merge($s1->logCalls, $s2->logCalls), $rec(['enrol_complete' => '2', 'ae_term' => 'Rash']));
    check('REQ-3: and nothing once the field is answered', !in_array('ae_term@visit', $depLogs($s3), true));
    $DICT = $KEEP;

    echo "required_pages_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
