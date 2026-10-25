<?php
/**
 * scan_reason_bits_php.php — what a durable finding keeps of its reason, and
 * of the branch that judged it (W7-R1, R3G-2, wave 11).
 *
 *   RB-1  A POOLED REASON IS STORED AS THE CODE `pooled` AND A MASK. The
 *         engine joins a pooled field's problems with `;` and no prefix, so
 *         every stored row had reason_bits 0 and the joined names in
 *         reason_code, one GROUP BY bucket per combination. The mask is now
 *         produced from the joined form, the report reads it back as the
 *         engine's own words in the engine's order, and an unknown part keeps
 *         the joined form rather than being dropped.
 *
 *   RB-2  THE IDENTITY DOES NOT MOVE. The reason is split once and the split
 *         reused; the identity is still computed from the code identities
 *         have always been computed from. The identities below were computed
 *         by the release before this one and are held byte for byte.
 *
 *   RB-3  THE BRANCH THAT JUDGED A FINDING TRAVELS WITH IT (R3G-2). A durable
 *         finding did not carry it, so a branched @UVRANGE, @UVWINDOW or
 *         @UVASSERT finding in a stored run lost its branch's limits sentence
 *         and custom message, and an assert's "The rule requires" went empty.
 *         It is stored in reason_bits (branch + 1; under the code `pooled` in
 *         the top byte), and FindingRowMapper hands it back. The reviewer's
 *         comparison (tools/temporal_R3G_s2.php on the r3b tree) is this
 *         file's last section: the legacy and durable "What is wrong" cells of
 *         every finding must match.
 *
 * Run:  php tests/scan_reason_bits_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        /** The legacy one-request scan (tests/support/scan_facade.php, QUA-3). */
        public function scanProject(...$a) { return \INSPIRE\UniversalValidator\scan_project($this, ...$a); }
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
        public static function getDataDictionary($pid, $f = 'array') {
            if (!$pid) throw new \RuntimeException('needs pid');
            return self::$dictionary;
        }
        public static function getUserRights($pid = null, $user = null) { return self::$rights; }
        public static function getGroupNames($u = false, $g = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
    }

    require_once __DIR__ . '/../UniversalValidator.php';
    require_once __DIR__ . '/support/scan_facade.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    function f($form, $ann = '', $validation = '', $type = 'text') {
        return ['field_type' => $type, 'form_name' => $form, 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => $validation];
    }
    $HB = '@UVRANGE={"soft":[12,15.5],"hard":[3,25],"unit":"g/dL","when":"[sex]=\'2\'"} '
        . '@UVRANGE={"soft":[13.5,17.5],"hard":[3,25],"unit":"g/dL","message":"Repeat the male Hb.","when":"[sex]=\'1\'"}';
    $AS = '@UVASSERT={"assert":"[dose] <= 5","when":"[sex]=\'2\'"} '
        . '@UVASSERT={"assert":"[dose] <= 9","when":"[sex]=\'1\'"}';
    $DICT = [
        'record_id' => f('enrol_form'),
        'sex'       => f('enrol_form', '', '', 'radio'),
        'hb'        => f('labs_form', $HB, 'number_1dp'),
        'dose'      => f('labs_form', $AS, 'number'),
        'weight'    => f('labs_form', '@UVRANGE={"hard":[0.5,250],"message":"Check the scale."}', 'number'),
        'ids'       => f('labs_form', '@UVALIDATE={"type":"pooled","algorithm":"none","pattern":"[A-Z]{3}","idLengths":[3],"expectedIds":3}'),
        'ids_one'   => f('labs_form', '@UVALIDATE={"type":"pooled","algorithm":"none","pattern":"[A-Z]{3}","idLengths":[3]}'),
        // A branched POOLED rule: its branch has to go above the mask.
        'ids_br'    => f('labs_form', '@UVALIDATE={"type":"pooled","algorithm":"none","pattern":"[A-Z]{3}","idLengths":[3],"when":"[sex]=\'2\'"} '
                         . '@UVALIDATE={"type":"pooled","algorithm":"none","pattern":"[A-Z]{3}","idLengths":[3],"expectedIds":2,"note":"Male form IDs","when":"[sex]=\'1\'"}'),
    ];
    $DATA = ['2' => [351 => [
        'record_id' => '2', 'sex' => '1', 'hb' => '18.2', 'dose' => '12', 'weight' => '0.4',
        'ids' => 'ABC ABC 12', 'ids_one' => '12 34', 'ids_br' => 'ABC 9',
    ]]];
    $FULL = ['nurse' => ['forms' => ['enrol_form' => '1', 'labs_form' => '1']]];

    function mod($dict, $data, $rights, $user) {
        $GLOBALS['__TEST_USER'] = $user;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectSettings = ['log-values' => 'raw'];
        $m->systemSettings = ['log-hmac-key' => str_repeat('k', 64)];
        $m->projectIdReturn = 149;
        \REDCap::$dictionary = $dict;
        \REDCap::$data = $data;
        \REDCap::$rights = $rights;
        return $m;
    }
    /** The durable rows of record 2, keyed by field. */
    function durableRows($m, $data) {
        $ctx = $m->durableScanContext(149, ['generation' => 1, 'runSeq' => 1, 'valueCeiling' => 'raw'], null);
        $ev = $ctx['evaluate']('2', $data['2']);
        $out = [];
        foreach ($ev['findings'] as $r) $out[$r['field']][] = $r;
        return $out;
    }

    $rows = durableRows(mod($DICT, $DATA, $FULL, 'nurse'), $DATA);
    foreach (['hb', 'dose', 'weight', 'ids', 'ids_one'] as $fld) {
        check('fixture: the durable scan finds ' . $fld, isset($rows[$fld]) && count($rows[$fld]) === 1);
    }
    $one = function ($fld) use ($rows) { return isset($rows[$fld][0]) ? $rows[$fld][0] : []; };
    $legacyRes = mod($DICT, $DATA, $FULL, 'nurse')->scanProject(149);
    $legacy = [];
    foreach ($legacyRes['violations'] as $v) $legacy[$v['field']] = $v;

    /** One durable row as the store hands it back, keyed by FindingRowMapper::INPUT_KEYS. */
    function storedRow(array $durable, $id) {
        $row = $durable + ['finding_id' => $id, 'reason_bits' => 0];
        return array_intersect_key($row, array_flip(\INSPIRE\UniversalValidator\Scan\FindingRowMapper::INPUT_KEYS));
    }
    function rbMapper() {
        return new \INSPIRE\UniversalValidator\Scan\FindingRowMapper(['pid' => 149, 'now' => '2026-10-22 00:00:00',
            'recordIds' => 'raw', 'valuesState' => 'raw', 'readerCeiling' => 'raw', 'identifiers' => []]);
    }

    /* =====================================================================
     * RB-1  a pooled field's problems are one code and a mask
     * ===================================================================== */
    {
        $RC = '\INSPIRE\UniversalValidator\Scan\ReasonCode';
        $ids = $one('ids');
        check('RB-1: the engine still says it the joined way (the fixture is the case)',
            isset($legacy['ids']) && $legacy['ids']['reason'] === 'junk;duplicate;count');
        check('RB-1: the row stores the code pooled',
            isset($ids['reason_code']) && $ids['reason_code'] === 'pooled');
        check('RB-1: and the mask of exactly those three problems',
            isset($ids['reason_bits']) && $RC::pooledNames($ids['reason_bits']) === ['duplicate', 'junk', 'count']);
        check('RB-1: a second combination is the same code, so a summary has one bucket',
            isset($rows['ids_one'][0]['reason_code']) && $rows['ids_one'][0]['reason_code'] === 'pooled'
            && $rows['ids_one'][0]['reason_bits'] !== $ids['reason_bits']);
        $back = rbMapper()->toFinding(storedRow($ids, 1));
        check('RB-1: read back, the reason is the engine\'s own, in the engine\'s order',
            $back['reason'] === $legacy['ids']['reason']);
        check('RB-1: for the other combination too',
            rbMapper()->toFinding(storedRow($rows['ids_one'][0], 2))['reason'] === $legacy['ids_one']['reason']);
        $odd = $RC::split('junk;from-the-future', 'pooled');
        check('RB-1: a part the mask has no bit for keeps the joined code, and no mask',
            $odd['code'] === 'junk;from-the-future' && $odd['bits'] === 0);
        $single = $RC::split('check-character', 'single');
        check('RB-1: a single-value check saying check-character is not a pooled reason',
            $single['code'] === 'check-character' && $single['bits'] === 0);
        check('RB-1: and neither is a pooled reason split without its type',
            $RC::split('junk;duplicate')['code'] === 'junk;duplicate');
        check('RB-1: a mask of 0 still reads as the code it was stored under',
            rbMapper()->toFinding(storedRow(['reason_code' => 'junk;duplicate', 'reason_bits' => 0] + $ids, 3))['reason']
                === 'junk;duplicate');
    }

    /* =====================================================================
     * RB-2  no finding's identity moved
     *
     * Each identity below was computed by the tree before wave 11 (50c3c37)
     * over this fixture, and is held byte for byte. A change to how the
     * reason enters the identity renames every finding a run has stored,
     * which an earlier generation's rows and every catch-up re-examination
     * match on.
     * ===================================================================== */
    {
        $held = [
            'weight'  => '1f90aea99bd9157a78430b20d35e9f3a47d254980627abafcc3d85b9eee226fb',
            'ids'     => '0a74d09f647563d7459f1a30e92fa7dd22f9e7bc8add452b9392f43e27ccac55',
            'ids_one' => '76e4d7e5ec4b396e2ef0edc02d58dc03728161fdfafccbcb65afe20e03fe921d',
            'hb'      => '976f980d370d67351369f2123d57acbbaed807dd095324a40b8c0ec5281f0cfe',
            'dose'    => 'bbb4f194a57910d7a9282562d119cd13ba03e0ec5032697b262f1d1a2082ed2e',
        ];
        foreach ($held as $fld => $hex) {
            $r = $one($fld);
            check('RB-2: the identity of the ' . $fld . ' finding is the one it had before',
                isset($r['identity']) && bin2hex($r['identity']) === $hex);
        }
    }

    /* =====================================================================
     * RB-3  the branch that judged a finding speaks for it in a stored run
     *
     * The reviewer's comparison (R3G-2, tools/temporal_R3G_s2.php on the r3b
     * tree), committed: the legacy scan and the durable scan of one record,
     * each finding's "What is wrong" cell rendered by ScanColumns from the
     * same labels, and required equal. Before the branch was kept, the hb cell
     * lost the male branch's "Repeat the male Hb." and its 13.5-17.5 limits,
     * and the dose cell's "The rule requires" went empty.
     * ===================================================================== */
    {
        $RC = '\INSPIRE\UniversalValidator\Scan\ReasonCode';
        $mR = mod($DICT, $DATA, $FULL, 'nurse');
        $res = $mR->scanProject(149);
        $dims = $mR->scanDimensions(149, isset($res['rules']) ? $res['rules'] : null);
        $cols = \INSPIRE\UniversalValidator\ScanColumns::all($dims);
        check('RB-3: the legacy findings name their branch (the fixture is the case)',
            isset($legacy['hb']['branch']) && $legacy['hb']['branch'] === 1
            && isset($legacy['dose']['branch']) && $legacy['dose']['branch'] === 1
            && isset($legacy['ids_br']['branch']) && $legacy['ids_br']['branch'] === 1
            && !isset($legacy['weight']['branch']));
        check('RB-3: the durable rows keep it: branch + 1, and above the mask for a pooled one',
            $one('hb')['reason_bits'] === 2 && $one('dose')['reason_bits'] === 2
            && $RC::branchOf('pooled', $one('ids_br')['reason_bits']) === 1
            && $RC::maskOf('pooled', $one('ids_br')['reason_bits']) > 0
            && $one('weight')['reason_bits'] === 0 && $one('ids')['reason_bits'] === $RC::maskOf('pooled', $one('ids')['reason_bits']));
        $mapper = rbMapper();
        $id = 10;
        foreach (['hb', 'dose', 'weight', 'ids', 'ids_one', 'ids_br'] as $fld) {
            $was = \INSPIRE\UniversalValidator\ScanColumns::row($legacy[$fld], $dims, $cols);
            $back = $mapper->toFinding(storedRow($one($fld), $id++));
            $is = \INSPIRE\UniversalValidator\ScanColumns::row($back, $dims, $cols);
            check('RB-3: the ' . $fld . ' finding reads the same in the stored report as in the legacy one',
                $was['problem'] === $is['problem'] && $was['wording'] === $is['wording']
                && $was['rule_label'] === $is['rule_label']);
            if ($was['problem'] !== $is['problem']) {
                fwrite(STDERR, "  legacy:  " . $was['problem'] . "\n  durable: " . $is['problem'] . "\n");
            }
        }
        check('RB-3: the branch reaches the wording (the cells name the male branch)',
            strpos(\INSPIRE\UniversalValidator\ScanColumns::row($mapper->toFinding(storedRow($one('hb'), 30)), $dims, $cols)['problem'],
                   'Repeat the male Hb.') !== false
            && strpos(\INSPIRE\UniversalValidator\ScanColumns::row($mapper->toFinding(storedRow($one('dose'), 31)), $dims, $cols)['problem'],
                      '[dose] <= 9') !== false
            && \INSPIRE\UniversalValidator\ScanColumns::row($mapper->toFinding(storedRow($one('ids_br'), 34)), $dims, $cols)['rule_label']
                === 'Male form IDs');
        check('RB-3: a row with no branch reads back with none',
            $mapper->toFinding(storedRow($one('weight'), 32))['branch'] === null
            && $mapper->toFinding(storedRow($one('ids'), 33))['branch'] === null);
        check('RB-3: a branch past what fits above the mask is stored as none, not as another branch',
            $RC::bits(['code' => 'pooled', 'bits' => 3], 255) === 3 && $RC::bits(['code' => 'x', 'bits' => 0], 254) === 255
            && $RC::bits(['code' => 'x', 'bits' => 0], 'b') === 0 && $RC::branchOf('x', 0) === null);
    }

    echo "scan_reason_bits_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
