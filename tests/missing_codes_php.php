<?php
/**
 * missing_codes_php.php — REDCap Missing Data Codes on the server.
 *
 * A field marked with one of the project's missing data codes holds the code
 * itself, and REDCap does not validate it. Each mode says in php/modes.json
 * what a code means to it ("missingCodes"): "skip" leaves the field alone,
 * "answer" (@UVREQUIRED) counts it as filled in. This file pins:
 *   - parseMissingDataCodes: REDCap's "CODE, Label" lines,
 *   - the registry: every mode declares a policy, and only @UVREQUIRED answers,
 *   - the post-save audit: a field holding a code logs nothing for check,
 *     @UVASSERT, @UVWINDOW, @UVRANGE and @UVEXISTS, and @UVREQUIRED counts it;
 *     a @UVWINDOW "from" date holding a code is like a blank one;
 *     the same record without codes configured is reported (the control),
 *   - the scan: no finding for a code, and two records marked UNK are not
 *     duplicates of each other under @UVUNIQUE,
 *   - the endpoints: unique-check answers a code as not used, exists-check as
 *     nothing to look up, and a code in a "match" field as could not check,
 *   - the page config: the codes travel (never their labels), and only when
 *     the project has some.
 *
 * The browser twin is tests/missing_codes_dom_js.cjs.
 *
 * Run:  php tests/missing_codes_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $logCalls = [];
        public $subSettings = [];
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
        public $rateBuckets = [];
        public $lastInsertId = 0;
        public function query($sql, $params = []) {
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
    /** getData honours records and a simple filterLogic ("[f] = 'v' and ..."). */
    class REDCap {
        public static $data = [];
        public static $dictionary = [];
        public static $rights = [];
        public static function getData($p) {
            $filtered = isset($p['filterLogic']) && $p['filterLogic'] !== '';
            preg_match_all("/\\[([a-z0-9_]+)\\] = '([^']*)'/", $filtered ? $p['filterLogic'] : '', $mm, PREG_SET_ORDER);
            $out = [];
            foreach (self::$data as $rec => $node) {
                if (!empty($p['records']) && !in_array((string) $rec, array_map('strval', $p['records']), true)) continue;
                if ($filtered) {
                    $ok = false;
                    foreach ($node as $row) {
                        $all = true;
                        foreach ($mm as $c) if (!isset($row[$c[1]]) || (string) $row[$c[1]] !== $c[2]) { $all = false; break; }
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
        public static function getEventNames($unique = false, $assoc = false, $id = null) { return [351 => 'enrolment_arm_1']; }
        public static function getUserRights($u = null) { return self::$rights; }
        public static function getGroupNames($unique = false, $g = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
    }
    /** REDCap's \Project: only the missing data codes are read here. */
    class Project {
        public static $codes = '';
        public $project;
        public function __construct($pid) { $this->project = ['missing_data_codes' => self::$codes]; }
    }

    require_once __DIR__ . '/../UniversalValidator.php';
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';

    use INSPIRE\UniversalValidator\ModeRegistry;
    use INSPIRE\UniversalValidator\UniversalValidator;

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    // ---- 1) REDCap's "CODE, Label" lines ------------------------------------------
    check('parse: lines and labels', UniversalValidator::parseMissingDataCodes("UNK, Unknown\nNASK, Not asked\r\n-99, Refused")
        === ['UNK' => true, 'NASK' => true, '-99' => true]);
    check('parse: backslash-n separators, as REDCap writes choice lists',
        UniversalValidator::parseMissingDataCodes('NA, Not applicable\\nRF, Refused') === ['NA' => true, 'RF' => true]);
    check('parse: blank lines, a code without a label, spaces around the code',
        UniversalValidator::parseMissingDataCodes("\n  UNK  ,Unknown\n\nNI\n , no code") === ['UNK' => true, 'NI' => true]);
    check('parse: nothing', UniversalValidator::parseMissingDataCodes('') === [] && UniversalValidator::parseMissingDataCodes(null) === []);

    // ---- 2) the registry -------------------------------------------------------------
    $policies = [];
    foreach (ModeRegistry::all() as $m) $policies[$m['mode']] = ModeRegistry::missingCodes($m['mode']);
    check('every mode declares a policy', count($policies) === count(ModeRegistry::all()));
    check('only @UVREQUIRED counts a code as an answer', array_keys(array_filter($policies, function ($p) { return $p === 'answer'; })) === ['required']);

    // ---- the project -----------------------------------------------------------------
    function f($form, $ann = '', $validation = '', $type = 'text') {
        return ['field_type' => $type, 'form_name' => $form, 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => $validation, 'identifier' => '',
                'select_choices_or_calculations' => ''];
    }
    $DICT = [
        'record_id'   => f('enrol'),
        'specimen_id' => f('lab_reg'),
        'site_code'   => f('lab_reg'),
        'm_check'     => f('result', '@UVALIDATE'),
        'm_assert'    => f('result', '@UVASSERT={"assert":"[m_assert]<=10"}', 'number'),
        'm_window'    => f('result', '@UVWINDOW={"notFuture":true}', 'date_ymd'),
        'm_anchor'    => f('result', '', 'date_ymd'),
        'm_after'     => f('result', '@UVWINDOW={"from":"[m_anchor]","window":[0,30]}', 'date_ymd'),
        'm_range'     => f('result', '@UVRANGE={"hard":[0,250]}', 'number'),
        'm_exists'    => f('result', '@UVEXISTS=[specimen_id]'),
        'm_site'      => f('result'),
        'm_match'     => f('result', '@UVEXISTS={"in":"[specimen_id]","match":{"site_code":"[m_site]"}}'),
        'm_unique'    => f('result', '@UVUNIQUE'),
        'm_req'       => f('result', '@UVREQUIRED'),
    ];
    // m_assert is stored with spaces around the code: it is still the code.
    $CODED = ['m_check' => 'UNK', 'm_assert' => ' NASK ', 'm_window' => 'UNK', 'm_range' => '-99',
              'm_anchor' => 'UNK', 'm_after' => '2026-01-01',
              'm_exists' => 'UNK', 'm_site' => 'UNK', 'm_match' => 'SP-404', 'm_unique' => 'UNK', 'm_req' => 'NASK'];
    $DATA = [
        '1' => [351 => array_merge(['record_id' => '1', 'specimen_id' => 'SP-1', 'site_code' => 'A'], $CODED)],
        '2' => [351 => ['record_id' => '2', 'm_check' => 'X1', 'm_assert' => '50', 'm_window' => '2999-01-01',
                        'm_anchor' => '2025-01-01', 'm_after' => '2026-01-01',
                        'm_range' => '-5', 'm_exists' => 'SP-404', 'm_site' => 'A', 'm_match' => 'SP-404',
                        'm_unique' => 'U-1', 'm_req' => '']],
        '3' => [351 => ['record_id' => '3', 'm_unique' => 'U-1', 'm_req' => 'x']],
        '4' => [351 => ['record_id' => '4', 'm_unique' => 'UNK', 'm_req' => 'x']],
    ];
    $FULL = ['nurse' => ['forms' => ['enrol' => '1', 'lab_reg' => '1', 'result' => '1']]];
    $CODES = "UNK, Unknown\nNASK, Not asked\n-99, Refused";

    function mod($codes) {
        global $DICT, $DATA, $FULL;
        $GLOBALS['__TEST_USER'] = 'nurse';
        \Project::$codes = $codes;
        $m = new UniversalValidator();
        $m->projectSettings = ['log-values' => 'raw'];
        $m->projectIdReturn = 149;
        \REDCap::$dictionary = $DICT;
        \REDCap::$data = $DATA;
        \REDCap::$rights = $FULL;
        return $m;
    }
    function logged($m, $type) {
        return array_values(array_map(function ($c) { return $c[1]; },
            array_filter($m->logCalls, function ($c) use ($type) { return $c[0] === $type; })));
    }
    function fieldsOf(array $entries, $key = 'field') {
        $out = [];
        foreach ($entries as $e) foreach (explode(', ', (string) $e[$key]) as $f) $out[$f] = true;
        ksort($out);
        return array_keys($out);
    }
    function save($m, $rec) { $m->redcap_save_record(149, $rec, 'result', 351, null, null, null, 1); }

    // ---- 3) the post-save audit ---------------------------------------------------------
    $m = mod($CODES);
    save($m, '1');
    $bad = fieldsOf(logged($m, 'invalid-id-saved'));
    $unc = fieldsOf(logged($m, 'uvalidate-unconfigurable'), 'fields');
    check('audit: a code logs no finding (got ' . json_encode($bad) . ')', $bad === []);
    check('audit: a code is no rule problem either (got ' . json_encode($unc) . ')', $unc === []);

    $m = mod('');
    save($m, '1');
    $bad = fieldsOf(logged($m, 'invalid-id-saved'));
    check('control, no codes configured: the same values are findings (got ' . json_encode($bad) . ')',
        $bad === ['m_assert', 'm_check', 'm_exists', 'm_match', 'm_range', 'm_unique']);
    $unc = fieldsOf(logged($m, 'uvalidate-unconfigurable'), 'fields');
    check('control: @UVWINDOW cannot read UNK as a date, saved or "from" (got ' . json_encode($unc) . ')',
        in_array('m_window', $unc, true) && in_array('m_after', $unc, true));

    $m = mod($CODES);
    save($m, '2');
    $bad = fieldsOf(logged($m, 'invalid-id-saved'));
    check('control, ordinary bad values with codes configured: still findings (got ' . json_encode($bad) . ')',
        $bad === ['m_after', 'm_assert', 'm_check', 'm_exists', 'm_match', 'm_range', 'm_req', 'm_unique', 'm_window']);

    // ---- 4) the scan --------------------------------------------------------------------
    $m = mod($CODES);
    $res = $m->scanProject(149);
    $byRec = [];
    foreach ($res['violations'] as $v) $byRec[$v['record']][] = $v['field'];
    check('scan: no finding on the record holding codes (got ' . json_encode($byRec['1'] ?? []) . ')', empty($byRec['1']));
    check('scan: two records marked UNK are not duplicates', empty($byRec['4']));
    check('scan: a real duplicate is still found', in_array('m_unique', $byRec['2'] ?? [], true) && in_array('m_unique', $byRec['3'] ?? [], true));

    $m = mod('');
    $res = $m->scanProject(149);
    $dupUnk = array_filter($res['violations'], function ($v) { return $v['field'] === 'm_unique' && in_array($v['record'], ['1', '4'], true); });
    check('scan control, no codes: UNK in two records is a duplicate', count($dupUnk) === 2);

    // ---- 5) the endpoints --------------------------------------------------------------
    $ask = function ($m, $action, $field, array $values) {
        return $m->redcap_module_ajax($action, ['field' => $field, 'values' => $values],
            149, '9', 'result', 351, 1, null, null, null, '', '', 'nurse', null);
    };
    check('unique-check: a code is never "used"', ($ask(mod($CODES), 'unique-check', 'm_unique', ['m_unique' => 'UNK'])['used'] ?? null) === false);
    check('unique-check control: without codes UNK is used', ($ask(mod(''), 'unique-check', 'm_unique', ['m_unique' => 'UNK'])['used'] ?? null) === true);
    check('exists-check: a code is nothing to look up', ($ask(mod($CODES), 'exists-check', 'm_exists', ['m_exists' => 'UNK'])['error'] ?? null) === 'nothing to look up');
    check('exists-check control: without codes UNK is looked up', ($ask(mod(''), 'exists-check', 'm_exists', ['m_exists' => 'UNK'])['state'] ?? null) === 'not-found');
    // Codes are case-sensitive: "unk" is a value. Under a rule that ignores
    // letter case it still never matches a saved "UNK", which is a code.
    check('unique-check: "unk" is not a duplicate of a saved code "UNK"',
        ($ask(mod($CODES), 'unique-check', 'm_unique', ['m_unique' => 'unk'])['used'] ?? null) === false);
    check('unique-check control: without codes "unk" is used next to UNK',
        ($ask(mod(''), 'unique-check', 'm_unique', ['m_unique' => 'unk'])['used'] ?? null) === true);
    $m = mod($CODES); \REDCap::$data['3'][351]['specimen_id'] = 'UNK';
    check('exists-check: "unk" does not find a saved code "UNK"', ($ask($m, 'exists-check', 'm_exists', ['m_exists' => 'unk'])['state'] ?? null) === 'not-found');
    $m = mod(''); \REDCap::$data['3'][351]['specimen_id'] = 'UNK';
    check('exists-check control: without codes "unk" finds UNK', ($ask($m, 'exists-check', 'm_exists', ['m_exists' => 'unk'])['state'] ?? null) === 'found');
    $m = mod($CODES); \REDCap::$data['3'][351]['specimen_id'] = 'UNK'; \REDCap::$data['5'] = [351 => ['record_id' => '5', 'm_unique' => 'unk', 'm_exists' => 'unk', 'm_req' => 'x']];
    save($m, '5');
    check('audit: "unk" is neither a duplicate of nor found by a saved code',
        fieldsOf(logged($m, 'invalid-id-saved')) === ['m_exists']);
    $m = mod($CODES); \REDCap::$data['3'][351]['specimen_id'] = 'UNK'; \REDCap::$data['5'] = [351 => ['record_id' => '5', 'm_unique' => 'unk', 'm_exists' => 'unk', 'm_req' => 'x']];
    $res = $m->scanProject(149);
    $five = [];
    foreach ($res['violations'] as $v) if ($v['record'] === '5') $five[] = $v['field'];
    check('scan: the same answers for "unk" (got ' . json_encode($five) . ')', $five === ['m_exists']);
    $r = $ask(mod($CODES), 'exists-check', 'm_match', ['m_match' => 'SP-1', 'm_site' => 'UNK']);
    check('exists-check: a code in a match field cannot be matched (got ' . json_encode($r) . ')',
        ($r['state'] ?? null) === 'unknown' && strpos((string) ($r['why'] ?? ''), 'missing data code') !== false);

    // ---- 5b) @UVUNIQUE "also": a code saved in an "also" field matches nothing ----------
    $alsoMod = function ($codes) use ($DICT, $DATA) {
        $m = mod($codes);
        \REDCap::$dictionary = $DICT + [
            'm_typed' => f('result', '@UVUNIQUE={"also":["m_scan"]}'),
            'm_scan'  => f('result'),
        ];
        \REDCap::$data = $DATA;
        \REDCap::$data['1'][351]['m_scan'] = 'UNK';
        \REDCap::$data['2'][351]['m_scan'] = 'P-7';
        return $m;
    };
    check('also: "unk" typed is not a duplicate of a code saved in an "also" field',
        ($ask($alsoMod($CODES), 'unique-check', 'm_typed', ['m_typed' => 'unk'])['used'] ?? null) === false);
    check('also control: without codes "unk" is found in the "also" field',
        ($ask($alsoMod(''), 'unique-check', 'm_typed', ['m_typed' => 'unk'])['field'] ?? null) === 'm_scan');
    check('also: an ordinary value in the "also" field is still found',
        ($ask($alsoMod($CODES), 'unique-check', 'm_typed', ['m_typed' => 'P-7'])['used'] ?? null) === true);
    $m = $alsoMod($CODES);
    \REDCap::$data['5'] = [351 => ['record_id' => '5', 'm_typed' => 'unk']];
    $res = $m->scanProject(149);
    $typed = array_values(array_filter($res['violations'], function ($v) { return $v['field'] === 'm_typed'; }));
    check('also scan: a code in an "also" field joins no duplicate group (got ' . json_encode($typed) . ')', $typed === []);
    $m = $alsoMod('');
    \REDCap::$data['5'] = [351 => ['record_id' => '5', 'm_typed' => 'unk']];
    $res = $m->scanProject(149);
    $typed = array_values(array_filter($res['violations'], function ($v) { return $v['field'] === 'm_typed'; }));
    check('also scan control: without codes it does', count($typed) === 1 && $typed[0]['record'] === '5');

    // ---- 6) the page --------------------------------------------------------------------
    $cfgOf = function ($m) {
        ob_start();
        $m->redcap_data_entry_form_top(149, '1', 'result', 351, null, 1);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return [json_decode(isset($mm[1]) ? $mm[1] : 'null', true), isset($mm[1]) ? $mm[1] : ''];
    };
    list($cfg, $raw) = $cfgOf(mod($CODES));
    check('page: the codes travel', ($cfg['missingCodes'] ?? null) === ['UNK', 'NASK', '-99']);
    check('page: their labels do not', strpos($raw, 'Not asked') === false && strpos($raw, 'Refused') === false);
    list($cfg) = $cfgOf(mod(''));
    check('page: no codes, no key', is_array($cfg) && !array_key_exists('missingCodes', $cfg));

    echo "missing_codes_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
