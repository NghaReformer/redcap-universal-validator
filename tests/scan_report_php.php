<?php
/**
 * The report's presentation layer: a STORED finding, shown.
 *
 * Remediation wave 7, `report-presentation-layer` (M5, and the leaf half of B5).
 * Two classes have no caller in the shipped tree until wave 11 -
 * Scan\FindingRowMapper and Scan\ScanReport - so this suite is the only thing
 * holding them to anything. It holds them to four things:
 *
 *   1. THE LEGACY REPORT. The same record, scanned by scanProject() and by the
 *      durable evaluator, stored, mapped and rendered, produces the same cells.
 *      A stored report that words a finding differently from the report staff
 *      have been reading is a second report.
 *   2. THE CONTRACT WITH THE STORE. FindingRowMapper::INPUT_KEYS, by name. Wave
 *      8 builds the findings read against it.
 *   3. DISCLOSURE. Every line of the value decision, in order, and the record-id
 *      posture the audit log already applies.
 *   4. THE FILE. Defused cells, and lines of one width.
 *
 * EVERY GROUP CARRIES A CONTROL: a check that the assertion can fail, made by
 * running the same predicate over an input that must not satisfy it. A group
 * whose control is missing is a group that may be asserting nothing.
 *
 * Run:  php tests/scan_report_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $logCalls = []; public $subSettings = []; public $projectSettings = [];
        public $systemSettings = []; public $projectIdReturn = null;
        public function getSubSettings($k, $pid = null) {
            $e = ($pid !== null && $pid !== '') ? $pid : $this->projectIdReturn;
            return $e ? $this->subSettings : [];
        }
        public function getProjectSetting($k, $pid = null) {
            $e = ($pid !== null && $pid !== '') ? $pid : $this->projectIdReturn;
            if (!$e) return null;
            return isset($this->projectSettings[$k]) ? $this->projectSettings[$k] : null;
        }
        public function getSystemSetting($k) {
            return isset($this->systemSettings[$k]) ? $this->systemSettings[$k] : null;
        }
        public function setSystemSetting($k, $v) { $this->systemSettings[$k] = $v; }
        public function setProjectSetting($k, $v, $pid = null) { $this->projectSettings[$k] = $v; }
        public function query($sql, $params = []) { return []; }
        public function getProjectId() { return $this->projectIdReturn; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return count($this->logCalls); }
        public function initializeJavascriptModuleObject() { return '<script></script>'; }
        public function getJavascriptModuleObjectName() { return 'EM.T.UV'; }
        public function getUser() { return new TestUser('nurse'); }
    }
    class TestUser {
        private $n; public function __construct($n) { $this->n = $n; }
        public function getUsername() { return $this->n; }
        public function hasDesignRights() { return true; }
        public function getRights($pid = null) { return ['forms' => ['fa' => '1']]; }
    }
}

namespace {
    class REDCap {
        public static $data = []; public static $dictionary = [];
        public static function getDataDictionary($pid, $f = 'array') { return self::$dictionary; }
        public static function getData($p) {
            $src = self::$data;
            if (!empty($p['records'])) {
                $only = [];
                foreach ($p['records'] as $r) {
                    if (array_key_exists($r, self::$data)) $only[$r] = self::$data[$r];
                }
                $src = $only;
            }
            return $src;
        }
        public static function getRecordIdField() { return 'record_id'; }
    }
}

namespace {
    require_once __DIR__ . '/../UniversalValidator.php';
    require_once __DIR__ . '/../php/Scan/FindingRowMapper.php';
    require_once __DIR__ . '/../php/Scan/ScanReport.php';
    require_once __DIR__ . '/scan_run_row.php';

    use INSPIRE\UniversalValidator\MessageCatalog;
    use INSPIRE\UniversalValidator\ScanColumns;
    use INSPIRE\UniversalValidator\ScanDimensions;
    use INSPIRE\UniversalValidator\ScanPageView;
    use INSPIRE\UniversalValidator\Scan\FindingRowMapper;
    use INSPIRE\UniversalValidator\Scan\Hmac;
    use INSPIRE\UniversalValidator\Scan\ReasonCode;
    use INSPIRE\UniversalValidator\Scan\ScanOutcome;
    use INSPIRE\UniversalValidator\Scan\ScanReport;
    use INSPIRE\UniversalValidator\Scan\ScanStore;

    const PID = 77;
    const NOW = '2026-09-20 12:00:00';
    const LATER = '2026-10-20 12:00:00';
    const EARLIER = '2026-08-20 12:00:00';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }
    /** True when $fn throws an instance of $class. */
    function throws($class, $fn) {
        try { $fn(); } catch (\Throwable $e) { return $e instanceof $class; }
        return false;
    }

    function dict(array $spec) {
        $d = [];
        foreach ($spec as $f => $s) {
            $d[$f] = ['field_type' => 'text', 'form_name' => 'fa', 'field_label' => 'Label of ' . $f,
                      'field_annotation' => isset($s[0]) ? $s[0] : '',
                      'identifier' => isset($s[1]) ? $s[1] : ''];
        }
        return $d;
    }

    function mkMod($dict, $data, $subs = [], $settings = []) {
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->subSettings = $subs;
        $m->projectSettings = $settings + ['log-values' => '', 'scan-value-storage' => 'raw'];
        $m->projectIdReturn = PID;
        \REDCap::$dictionary = $dict;
        \REDCap::$data = $data;
        return $m;
    }

    /**
     * A durable finding as the store would hand it back.
     *
     * OWED BY WAVE 8: neither store has a findings read, so this is the test's
     * own projection. It adds the two keys the evaluator does not produce - the
     * id the table assigns, and the mask nothing writes - and keeps exactly
     * INPUT_KEYS, so a key the evaluator stops producing fails here by name.
     */
    function stored(array $durable, $id = 1) {
        $row = $durable + ['finding_id' => $id, 'reason_bits' => 0];
        return array_intersect_key($row, array_flip(FindingRowMapper::INPUT_KEYS));
    }

    /**
     * The row Scan\UniqueFinalizer writes for one duplicate candidate.
     *
     * Built from the finalizer's INSERT, not from what a duplicate ought to
     * carry: rule ordinal 0, reason `duplicate`, no group, and NO VALUE. The
     * INSERT names no value column, so value_bin and value_len are NULL and the
     * rest take their column defaults. Section 1 holds this helper to that
     * INSERT. The fixture it replaces gave the duplicate value_bin 'ID-7',
     * which the finalizer has never written (RP-1).
     */
    function finalized(array $cand, $id) {
        $ev = $cand['event_id'];
        return [
            'finding_id' => $id, 'project_id' => $cand['project_id'], 'record_id_bin' => $cand['record_id_bin'],
            // UniqueFinalizer::eventOrNull(): the candidate's 0 sentinel is undone.
            'event_id' => ($ev === null || $ev === '' || (int) $ev === 0) ? null : $ev,
            'instance' => (int) $cand['instance'], 'host_form' => $cand['host_form'], 'field' => $cand['field'],
            'rule_source_id' => $cand['rule_source_id'], 'rule_ord' => 0, 'check_type' => 'unique',
            'reason_code' => 'duplicate', 'reason_bits' => 0, 'dag_key' => null,
            'value_bin' => null, 'value_len' => null, 'value_truncated' => 0, 'value_expires_at' => null,
        ];
    }

    /** A hand-written stored row; every key present, overridable by name. */
    function row(array $over = []) {
        $base = [
            'finding_id' => 9, 'project_id' => PID, 'record_id_bin' => 'R-1', 'event_id' => 1,
            'instance' => 1, 'host_form' => 'fa', 'field' => 'a_val', 'rule_source_id' => 'src-a',
            'rule_ord' => 1, 'check_type' => 'constraint', 'reason_code' => 'assert', 'reason_bits' => 0,
            'dag_key' => null, 'value_bin' => 'NO', 'value_len' => 2, 'value_truncated' => 0,
            'value_expires_at' => LATER,
        ];
        foreach ($over as $k => $v) {
            if (!array_key_exists($k, $base)) throw new \InvalidArgumentException("no stored key $k");
            $base[$k] = $v;
        }
        return $base;
    }

    /**
     * A run row BY NAME, which is how both stores answer run(). This project's,
     * and one that kept values as mapper() is told it did, unless overridden.
     * The project id is a string, as the driver hands it over.
     */
    function runRow(array $keyed = []) {
        return array_combine(ScanStore::RUN_COLUMNS,
            uv_run_row($keyed + ['project_id' => (string) PID, 'values_state' => 'raw']));
    }

    function mapper(array $over = []) {
        return new FindingRowMapper($over + ['pid' => PID, 'now' => NOW, 'valuesState' => 'raw',
                                             'readerCeiling' => 'raw', 'identifiers' => [],
                                             'recordIds' => 'raw', 'recordIdField' => 'record_id']);
    }

    /** A ScanDimensions filled by hand, the way wave 11 will fill one from a snapshot. */
    function dims(array $rules = [], array $bySource = []) {
        $d = new ScanDimensions();
        $d->rules = $rules;
        $d->rulesBySource = $bySource;
        $d->singleEventProved = true;
        return $d;
    }

    $RULE_A = ['type' => 'constraint', 'label' => 'A must be OK', 'message' => '', 'assert' => "[a_val]='OK'", 'fields' => ['a_val']];

    /* =====================================================================
     * 1. DIFFERENTIAL: the stored report is the legacy report
     * ===================================================================== */
    {
        $long = str_repeat('x', 130);
        $subs = [[
            'rule-type' => 'pooled', 'fields' => ['pool'], 'fields-csv' => '', 'when' => '', 'assert' => '',
            'message' => '', 'algorithm' => 'none', 'source' => '', 'suggest-fix' => '',
            'pattern' => '[0-9]{3}', 'strip' => '', 'keep-chars' => '', 'id-lengths' => '3',
            'id-min-len' => '', 'id-max-len' => '', 'expected-count' => '', 'block-save' => 'off',
        ]];
        $dict = dict([
            'record_id' => [],
            'a_val' => ['@UVASSERT={"assert":"[a_val]=\'OK\'","message":"","blockSave":"hard"}'],
            'b_val' => ['@UVASSERT={"assert":"[b_val]=\'OK\'","message":"Say OK here","blockSave":"hard"}'],
            'req'   => ['@UVREQUIRED'],
            'pool'  => [],
            'sid'   => ['@UVUNIQUE'],
        ]);
        // R-2 is clean except for the duplicate it shares with R-1.
        $data = ['R-1' => [1 => ['record_id' => 'R-1', 'a_val' => 'NO', 'b_val' => $long, 'req' => '',
                                 'pool' => '123 zz 123', 'sid' => 'ID-7']],
                 'R-2' => [1 => ['record_id' => 'R-2', 'a_val' => 'OK', 'b_val' => 'OK', 'req' => 'x',
                                 'pool' => '', 'sid' => 'ID-7']]];
        $m = mkMod($dict, $data, $subs);

        $legacy = $m->scanProject(PID, null, 200, null, ['valueCeiling' => 'raw']);
        $ctx = $m->durableScanContext(PID, ['valueCeiling' => 'raw', 'generation' => 7, 'runSeq' => 7]);
        check('differential: the durable context opens', !empty($ctx['ok']));
        $ev = $ctx['evaluate'];
        $found = [];
        $cands = [];
        foreach ($data as $rid => $node) {
            $out = $ev($rid, $node);
            foreach ($out['findings'] as $f) $found[] = $f;
            foreach ($out['candidates'] as $c) $cands[] = $c;
        }
        $d = $m->scanDimensions(PID);
        // What wave 11's snapshot supplies: the rule each source id names. With
        // it, the finalizer's ordinal-0 duplicate finds the legacy rule number.
        foreach ($ctx['plan']['ruleIds'] as $i => $id) $d->rulesBySource[$id['source_id']] = $i + 1;

        $byField = function (array $rows) {
            $out = [];
            foreach ($rows as $r) $out[$r['record'] . '|' . $r['field'] . '|' . $r['reason']] = $r;
            ksort($out);
            return $out;
        };
        // Uniqueness is the one kind the durable path does not report per
        // record: it stores candidates, and the finalizer writes the findings
        // later. Those rows are compared on their own, below.
        $was = [];
        $wasDup = [];
        foreach ($legacy['violations'] as $v) {
            if ($v['type'] === 'unique') $wasDup[$v['record']] = ScanColumns::row($v, $d);
            else $was[] = ScanColumns::row($v, $d);
        }
        $map = mapper();
        $is = [];
        foreach ($found as $i => $f) $is[] = ScanColumns::row($map->toFinding(stored($f, $i + 1)), $d);
        $isDup = [];
        foreach ($cands as $i => $c) {
            $isDup[$c['record_id_bin']] = ScanColumns::row($map->toFinding(finalized($c, 100 + $i)), $d);
        }
        ksort($wasDup); ksort($isDup);

        check('differential: the fixture produces findings on every rule kind it names',
            count($was) >= 4 && count($was) === count($is));
        check('differential: the stored report renders the legacy report, cell for cell',
            $byField($was) === $byField($is));

        $cells = $byField($is);
        $kinds = [];
        foreach ($cells as $c) $kinds[$c['check']] = true;
        check('differential: it covers an assertion, a required field and a pooled field (got '
            . implode(',', array_keys($kinds)) . ')',
            isset($kinds['constraint']) && isset($kinds['required']) && isset($kinds['pooled']));
        $pooled = null;
        foreach ($cells as $c) if ($c['check'] === 'pooled') $pooled = $c;
        check('differential: the pooled reason really is the joined form, so detail() had work to do',
            $pooled !== null && strpos($pooled['reason'], ';') !== false);
        $longCell = null;
        foreach ($cells as $c) if ($c['field'] === 'b_val') $longCell = $c;
        check('differential: a value the scan shortened is shown as the scan shortened it',
            $longCell !== null && strpos($longCell['value'], '(truncated)') !== false
            && strpos($longCell['value'], ScanColumns::VALUE_CUT) === false);

        // CONTROL. The comparison can fail: the same findings under a reader who
        // may see locations only are NOT the legacy raw report.
        $capped = mapper(['readerCeiling' => 'locations']);
        $other = [];
        foreach ($found as $i => $f) $other[] = ScanColumns::row($capped->toFinding(stored($f, $i + 1)), $d);
        check('differential (control): a capped reader does not get the raw report',
            $byField($was) !== $byField($other));

        // DUPLICATES (RP-1). The finalizer stores no value for a duplicate, so
        // the stored report cannot show 'ID-7' as the legacy report does. What
        // it must not do is say the field was EMPTY: on a run that kept values
        // that is what a NULL length meant, and every @UVUNIQUE finding read as
        // a blank field.
        check('differential: both records\' duplicate is in both reports',
            array_keys($wasDup) === ['R-1', 'R-2'] && array_keys($isDup) === ['R-1', 'R-2']);
        check('differential (control): the legacy report shows the duplicated value',
            isset($wasDup['R-1']) && $wasDup['R-1']['value'] === 'ID-7');
        $dupCell = isset($isDup['R-1']) ? $isDup['R-1']['value'] : null;
        check('differential: a stored duplicate is never shown as an empty field',
            $dupCell !== null && $dupCell !== '' && $dupCell === ScanColumns::VALUE_WORDS['not-kept']);
        $differ = [];
        foreach ($wasDup as $rid => $cellsWas) {
            if (!isset($isDup[$rid])) continue;
            foreach (array_keys(array_diff_assoc($cellsWas, $isDup[$rid])) as $k) $differ[$k] = true;
        }
        ksort($differ);
        // value: not stored (RP-1). reason and problem: the finalizer writes
        // `duplicate`, the legacy path `duplicate-value` (W7-R4). Every location
        // cell, the rule and the issue agree.
        check('differential: a stored duplicate differs from the legacy one only where W7-R4 and RP-1 say (got '
            . implode(',', array_keys($differ)) . ')',
            array_keys($differ) === ['problem', 'reason', 'value']);

        // The helper is the finalizer's INSERT, read from the finalizer. If a
        // later wave gives the duplicate its value, this fails and says which
        // fixture to change.
        $fin = (string) file_get_contents(__DIR__ . '/../php/Scan/UniqueFinalizer.php');
        $insCols = [];
        $insMarks = [];
        if (preg_match("/INSERT INTO ' \\. Schema::table\\('finding'\\) \\. '\\s*\\(([^)]*)\\)/", $fin, $mm)) {
            $insCols = array_map('trim', explode(',', $mm[1]));
        }
        // The placeholders are the last $marks line before that INSERT; the
        // file builds another INSERT's marks earlier on.
        $at = strpos($fin, "INSERT INTO ' . Schema::table('finding')");
        if ($at !== false && preg_match_all("/marks\\[\\] = '\\(([^)]*)\\)'/", substr($fin, 0, $at), $mm)) {
            $insMarks = array_map('trim', explode(',', end($mm[1])));
        }
        $written = (count($insCols) === count($insMarks) && $insCols) ? array_combine($insCols, $insMarks) : [];
        check('differential: the duplicate fixture is the row the finalizer writes - no value column, ordinal 0',
            $written !== []
            && array_intersect(['value_bin', 'value_len', 'value_truncated', 'value_expires_at', 'dag_key'],
                               array_keys($written)) === []
            && $written['rule_ord'] === '0' && $written['reason_bits'] === '0'
            && strpos($fin, "'unique', 'duplicate', \$g['group_hmac']") !== false);

        // The identifier flags are read twice, by two classes. Held together.
        $flagDict = dict(['record_id' => [], 'p' => ['', 'y'], 'q' => ['', 'Yes'], 'r' => ['', '1'],
                          's' => ['', ' TRUE '], 't' => ['', 'n'], 'u' => ['', '']]);
        $fm = mkMod($flagDict, []);
        $ref = new \ReflectionMethod($fm, 'projectIdentifierFields');
        $ref->setAccessible(true);
        $prod = $ref->invoke($fm, PID);
        $mine = ScanDimensions::build(PID, $flagDict, [])->identifiers;
        ksort($prod); ksort($mine);
        check('identifier flags: ScanDimensions reads them exactly as the scan does',
            $prod === $mine && array_keys($mine) === ['p', 'q', 'r', 's']);
        check('identifier flags: no dictionary is "could not tell", not "none"',
            ScanDimensions::build(PID, [], [])->identifiers === null);
    }

    /* =====================================================================
     * 2. CONTRACT: the row is named, whole, and this project's
     * ===================================================================== */
    {
        $map = mapper();
        check('contract: INPUT_KEYS is the 17 names wave 8 must return',
            count(FindingRowMapper::INPUT_KEYS) === 17
            && count(array_unique(FindingRowMapper::INPUT_KEYS)) === 17);
        check('contract (control): a whole row maps', is_array($map->toFinding(row())));
        check('contract: a positional row throws',
            throws('InvalidArgumentException', function () use ($map) { $map->toFinding(array_values(row())); }));

        // A MUTANT PER KEY. Each key removed in turn, and the message names it.
        $silent = [];
        $unnamed = [];
        foreach (FindingRowMapper::INPUT_KEYS as $k) {
            $mut = row(); unset($mut[$k]);
            try { $map->toFinding($mut); $silent[] = $k; }
            catch (\InvalidArgumentException $e) {
                if (strpos($e->getMessage(), $k) === false) $unnamed[] = $k;
            }
        }
        check('contract: removing any one key throws (' . implode(',', $silent) . ')', $silent === []);
        check('contract: and the exception names the key (' . implode(',', $unnamed) . ')', $unnamed === []);

        $nulls = row(['dag_key' => null, 'value_bin' => null, 'value_len' => null, 'value_expires_at' => null]);
        check('contract: a NULL column is a present column', is_array($map->toFinding($nulls)));

        check('contract: a row from another project throws rather than shows',
            throws('RuntimeException', function () use ($map) { $map->toFinding(row(['project_id' => PID + 1])); }));
        check('contract: the project id is compared as a number, as the driver returns it',
            is_array($map->toFinding(row(['project_id' => (string) PID]))));

        check('contract: no project, no mapper',
            throws('InvalidArgumentException', function () { new FindingRowMapper(['now' => NOW]); }));
        check('contract: no clock, no mapper',
            throws('InvalidArgumentException', function () { new FindingRowMapper(['pid' => PID]); }));
        check('contract: a clock in another form is no clock',
            throws('InvalidArgumentException', function () { new FindingRowMapper(['pid' => PID, 'now' => '1758369600']); }));

        $f = $map->toFinding(row());
        $sink = ['record', 'dag', 'event_id', 'instrument', 'instance', 'field', 'value', 'valueWithheld', 'type', 'reason', 'rule'];
        check('contract: the output is the sink shape the renderers already take, plus three',
            array_diff($sink, array_keys($f)) === []
            && array_values(array_diff(array_keys($f), $sink)) === ['valueState', 'ruleSource', 'findingId']);
    }

    /* =====================================================================
     * 3. VALUE STATES, one per line of the decision, in its order
     * ===================================================================== */
    {
        $state = function (array $rowOver, array $mapOver = []) {
            $f = mapper($mapOver)->toFinding(row($rowOver));
            return [$f['valueState'], $f['value'], $f['valueWithheld']];
        };
        $cell = function (array $rowOver, array $mapOver = []) {
            $r = ScanColumns::row(mapper($mapOver)->toFinding(row($rowOver)), dims());
            return $r['value'];
        };

        check('value (control): a kept, unexpired, permitted text value is shown',
            $state([]) === ['shown', 'NO', false] && $cell([]) === 'NO');

        // 1. nothing stored
        $empty = ['value_len' => null, 'value_bin' => null, 'value_expires_at' => null];
        $req = ['check_type' => 'required', 'reason_code' => 'required-blank'] + $empty;
        check('value: no length on a run that kept values, for a required field, is an EMPTY field',
            $state($req) === ['blank', null, false] && $cell($req) === '');
        // RP-1. Every other check is inert on a blank field, so for them a NULL
        // length on a kept-values run is a value nobody stored - the
        // finalizer's duplicates - and "the field was empty" would be false.
        check('value: no length on any other check is a value not kept, not an empty field',
            $state($empty) === ['not-kept', null, true]
            && $state(['check_type' => 'unique', 'reason_code' => 'duplicate'] + $empty)[0] === 'not-kept');
        check('value: and a check type nobody has heard of is not called empty either',
            $state(['check_type' => 'geofence', 'reason_code' => 'outside'] + $empty)[0] === 'not-kept');
        check('value: an identifier-redacted run kept values too, so its empty required field is EMPTY',
            $state($req, ['valuesState' => 'identifier-redacted', 'readerCeiling' => 'identifier-redacted'])
                === ['blank', null, false]);
        check('value: no length on a locations run says nothing about the field',
            $state(['value_len' => null, 'value_bin' => null], ['valuesState' => 'locations'])
                === ['not-kept', null, true]);
        check('value: and an unknown run mode is read as locations',
            $state(['value_len' => null, 'value_bin' => null], ['valuesState' => 'none'])[0] === 'not-kept');
        check('value: "not kept" and "empty" are different cells',
            $cell(['value_len' => null, 'value_bin' => null], ['valuesState' => 'locations']) !== '');

        // 2. expired
        check('value: purged by retention', $state(['value_bin' => null]) === ['expired', null, true]);
        check('value: past its date, whether or not the purge has run',
            $state(['value_expires_at' => EARLIER])[0] === 'expired');
        check('value: the boundary instant is expired, as the purge has it (<=)',
            $state(['value_expires_at' => NOW])[0] === 'expired');
        check('value: expiry is decided BEFORE the reader is asked',
            $state(['value_expires_at' => EARLIER], ['readerCeiling' => 'locations'])[0] === 'expired');

        // 3. reader ceiling
        check('value: a reader capped at locations sees no value',
            $state([], ['readerCeiling' => 'locations']) === ['withheld', null, true]);
        check('value: no ceiling given is the lowest ceiling',
            mapper(['readerCeiling' => null])->toFinding(row())['valueState'] === 'withheld');
        check('value: a misspelt ceiling is the lowest ceiling',
            $state([], ['readerCeiling' => 'RAW'])[0] === 'withheld');
        check('value: a reader cannot raise what the run kept',
            $state(['value_len' => null, 'value_bin' => null], ['valuesState' => 'locations', 'readerCeiling' => 'raw'])[0]
                === 'not-kept');
        check('value: withheld, expired and not kept are three different cells',
            count(array_unique([$cell([], ['readerCeiling' => 'locations']), $cell(['value_bin' => null]),
                $cell(['value_len' => null, 'value_bin' => null], ['valuesState' => 'locations'])])) === 3);
        // RP-9. Three different cells, and none of them the EMPTY cell: that is
        // what a blank field renders, and a withheld or expired value is not
        // one. The check above passed with either marker emptied.
        $markers = ['withheld' => $cell([], ['readerCeiling' => 'locations']),
                    'expired' => $cell(['value_bin' => null]), 'expired by date' => $cell(['value_expires_at' => EARLIER]),
                    'not kept' => $cell(['value_len' => null, 'value_bin' => null], ['valuesState' => 'locations'])];
        check('value: no marker is the empty cell a blank field renders ('
            . implode(',', array_keys(array_filter($markers, function ($v) { return $v === ''; }))) . ')',
            !in_array('', $markers, true)
            && $markers['withheld'] === ScanColumns::VALUE_WORDS['withheld']
            && $markers['expired'] === ScanColumns::VALUE_WORDS['expired']);
        // RP-9 / W7-R6. Nothing writes a kept value with no expiry, and calling
        // one expired would be false: it is shown.
        check('value: a kept value with no expiry date is shown, not expired',
            $state(['value_expires_at' => null]) === ['shown', 'NO', false]);

        // 4. identifiers
        check('value: the marker the scan stored is an identifier, under any ceiling',
            $state(['value_bin' => FindingRowMapper::IDENTIFIER_MARKER, 'value_len' => 21])
                === ['identifier', null, true]);
        check('value: a flagged field is withheld from a reader capped below raw',
            $state([], ['readerCeiling' => 'identifier-redacted', 'identifiers' => ['a_val' => true]])[0] === 'identifier');
        check('value: an unflagged field is shown to the same reader',
            $state([], ['readerCeiling' => 'identifier-redacted', 'identifiers' => ['other' => true]])[0] === 'shown');
        check('value: flags that could not be read withhold',
            $state([], ['readerCeiling' => 'identifier-redacted', 'identifiers' => null])[0] === 'identifier');
        check('value: a raw reader of a raw run is shown a flagged field, as the legacy report does',
            $state([], ['identifiers' => ['a_val' => true]])[0] === 'shown');
        // RP-8. A LIST of names - the shape a snapshot reader could plausibly
        // produce - was read as a map with no field in it, so an
        // identifier-redacted reader was shown the very field it named.
        check('value: identifiers given as a list of names refuses to build, rather than withhold nothing',
            throws('InvalidArgumentException', function () {
                mapper(['readerCeiling' => 'identifier-redacted', 'identifiers' => ['a_val']]);
            }));
        check('value: and so does a map with any numeric key',
            throws('InvalidArgumentException', function () {
                mapper(['identifiers' => ['b_val' => true, 0 => 'a_val']]);
            }));
        check('value (control): an empty map is "no identifiers", and builds',
            $state([], ['readerCeiling' => 'identifier-redacted', 'identifiers' => []])[0] === 'shown');

        // 5. the byte cut
        $e = "\xC3\xA9";                                     // e-acute, two bytes
        $full = str_repeat($e, 200);
        $bin = substr($full, 0, 255);                        // cut inside the 128th character
        check('value (control): the fixture really is cut inside a character',
            !mb_check_encoding($bin, 'UTF-8'));
        $cut = $state(['value_bin' => $bin, 'value_len' => 400, 'value_truncated' => 1]);
        check('value: a value cut inside a character loses the half character, not the cell',
            $cut[0] === 'truncated' && $cut[1] === str_repeat($e, 127));
        check('value: and the cell says it was cut short',
            $cell(['value_bin' => $bin, 'value_len' => 400, 'value_truncated' => 1])
                === str_repeat($e, 127) . ScanColumns::VALUE_CUT);
        // RP-9. The cut above ends mid-character, so the repair passed with
        // `>=` in place of `>`, which drops the last WHOLE character of every
        // cut value. Cuts that end on a character boundary keep all of it.
        $whole = [];
        foreach (['abcdef', 'ab' . $e, 'ab' . "\xE2\x82\xAC", 'ab' . "\xF0\x9F\x98\x80"] as $v) {
            if ($state(['value_bin' => $v, 'value_len' => 400, 'value_truncated' => 1]) !== ['truncated', $v, false]) {
                $whole[] = bin2hex($v);
            }
        }
        check('value: a cut that ends on a character boundary keeps its last character (' . implode(',', $whole) . ')',
            $whole === []);

        // A byte that could never start a character is not half of one. The
        // repair read any byte >= 0xC0 as a lead byte, so a stray 0xC0, 0xC1 or
        // 0xF5-0xFF at a cut end was dropped and the rest called "cut short",
        // when the bytes were never text. The same for a lead byte whose next
        // byte rules out every character (an overlong, a surrogate, or a code
        // point past U+10FFFF).
        $tailOf = function ($bytes) use ($state) {
            return $state(['value_bin' => 'abc' . $bytes, 'value_len' => 300, 'value_truncated' => 1]);
        };
        $wrongly = [];
        foreach (["\xC0", "\xC1", "\xF5", "\xF8", "\xFF", "\xE0\x80", "\xED\xA0", "\xF0\x80", "\xF4\x90", "\xC0\x80"] as $b) {
            if ($tailOf($b)[0] !== 'not-text') $wrongly[] = bin2hex($b);
        }
        check('value: a cut ending in a byte no character starts with is not text, not a cut ('
            . implode(',', $wrongly) . ')', $wrongly === []);
        $lost = [];
        foreach (["\xC3", "\xE2\x82", "\xF0\x9F\x98", "\xE0\xA0", "\xED\x9F", "\xF0\x90", "\xF4\x8F\xBF", "\xE0"] as $b) {
            if ($tailOf($b) !== ['truncated', 'abc', false]) $lost[] = bin2hex($b);
        }
        check('value (control): a cut inside any real character still loses only that character ('
            . implode(',', $lost) . ')', $lost === []);

        // 6. not text
        check('value: bytes that are not text never reach a cell',
            $state(['value_bin' => "ab\xFFcd", 'value_len' => 5]) === ['not-text', null, true]
            && strpos($cell(['value_bin' => "ab\xFFcd", 'value_len' => 5]), "\xFF") === false);
        check('value: the repair is for OUR cut only; an uncut invalid tail is not text',
            $state(['value_bin' => "ab\xC3", 'value_len' => 3, 'value_truncated' => 0])[0] === 'not-text');

        // The legacy path is untouched: no valueState, the old two-way cell.
        $old = dims();
        check('value: a finding with no valueState renders exactly as before',
            ScanColumns::row(['record' => '1', 'event_id' => 1, 'instance' => 1, 'field' => 'f', 'rule' => 1,
                              'value' => null, 'valueWithheld' => true], $old)['value'] === '[withheld by policy]'
            && ScanColumns::row(['record' => '1', 'event_id' => 1, 'instance' => 1, 'field' => 'f', 'rule' => 1,
                              'value' => 'v'], $old)['value'] === 'v');
        check('value: every state the mapper can produce is one the column knows or shows',
            array_keys(ScanColumns::VALUE_WORDS) === ['blank', 'not-kept', 'expired', 'withheld', 'identifier', 'not-text']);
    }

    /* =====================================================================
     * 4. RECORD IDS: the audit log's name for a record, or none
     * ===================================================================== */
    {
        $dict = dict(['record_id' => [], 'req' => ['@UVREQUIRED']]);
        $data = ['PATIENT-0042' => [1 => ['record_id' => 'PATIENT-0042', 'req' => '']]];
        $m = mkMod($dict, $data, [], ['log-values' => 'none']);
        $legacy = $m->scanProject(PID, null, 200, null, ['valueCeiling' => 'raw']);
        $shown = isset($legacy['violations'][0]['record']) ? $legacy['violations'][0]['record'] : null;
        $key = $m->getSystemSetting('log-hmac-key');
        check('record ids (control): under log-values=none the legacy report hashes the id',
            is_string($shown) && $shown !== 'PATIENT-0042' && strlen($shown) === 64);

        // RP-5, legacy half. The same leak lived in UniversalValidator's own
        // report: a finding ON the record-id field put the raw id in the Value
        // cell beside the hashed Record cell.
        $idDict = dict(['record_id' => ['@UVASSERT={"assert":"[record_id] = \'zzz\'"}'], 'req' => []]);
        $onIdLegacy = function ($logValues) use ($idDict, $data) {
            $mm = mkMod($idDict, $data, [], ['log-values' => $logValues]);
            $res = $mm->scanProject(PID, null, 200, null, ['valueCeiling' => 'raw']);
            foreach ($res['violations'] as $v) {
                if ($v['field'] === 'record_id') return $v;
            }
            return null;
        };
        $hashedRow = $onIdLegacy('none');
        check('record ids: the legacy report withholds the value of a finding on the record-id field',
            $hashedRow !== null && strpos(json_encode($hashedRow), 'PATIENT-0042') === false
            && $hashedRow['value'] === '[identifier withheld]');
        $rawRow = $onIdLegacy('');
        check('record ids (control): with raw ids the legacy report shows that value, as its Record cell does',
            $rawRow !== null && $rawRow['value'] === 'PATIENT-0042');

        $audit = function ($id) use ($key) { return hash_hmac('sha256', (string) $id, $key . '|' . PID); };
        $map = mapper(['recordIds' => 'hashed', 'hashRecordId' => $audit]);
        $f = $map->toFinding(row(['record_id_bin' => 'PATIENT-0042']));
        check('record ids: the stored report names the record as the legacy report and the audit log do',
            $f['record'] === $shown);
        check('record ids: the raw id is nowhere in the mapped finding',
            strpos(json_encode($f), 'PATIENT-0042') === false);
        $line = (new ScanReport($map, dims(), runRow()))->csvLine(row(['record_id_bin' => 'PATIENT-0042']));
        check('record ids: nor in the line of the file', strpos($line, 'PATIENT-0042') === false
            && strpos($line, $shown) !== false);

        // RP-5. A finding ON the record-id field carries the id as its value. The
        // Record cell was hashed and the Value cell beside it was not.
        $onId = row(['record_id_bin' => 'PATIENT-0042', 'field' => 'record_id', 'check_type' => 'single',
                     'reason_code' => 'format', 'value_bin' => 'PATIENT-0042', 'value_len' => 12]);
        $idLine = (new ScanReport($map, dims(), runRow()))->csvLine($onId);
        check('record ids: a finding on the record-id field does not put the raw id in its Value cell',
            strpos($idLine, 'PATIENT-0042') === false && strpos($idLine, $shown) !== false
            && $map->toFinding($onId)['valueState'] === 'identifier');
        check('record ids (control): another field\'s value is still shown under hashed ids',
            $map->toFinding(row(['record_id_bin' => 'PATIENT-0042']))['value'] === 'NO');
        check('record ids (control): with raw ids the record-id field\'s value is shown, as the Record cell is',
            mapper()->toFinding($onId)['value'] === 'PATIENT-0042');
        check('record ids: hashed with no record-id field named refuses to build',
            throws('InvalidArgumentException', function () use ($audit) {
                mapper(['recordIds' => 'hashed', 'hashRecordId' => $audit, 'recordIdField' => null]);
            }));

        // Hmac::P_RECORD is the scan's INTERNAL record hash. It is a different
        // formula, and a report hashed with it names records the log never has.
        $internal = bin2hex(Hmac::raw(Hmac::P_RECORD, PID, 'PATIENT-0042', $key));
        check('record ids (control): the internal record hash is NOT the audit name',
            $internal !== $shown);

        check('record ids: hashed with no hasher refuses to build',
            throws('InvalidArgumentException', function () { mapper(['recordIds' => 'hashed']); }));
        // RP-2. Every other disclosure input fails closed; this one defaulted to
        // raw, the most disclosing posture, so a caller that forgot it on a
        // log-values=none project got raw ids and no exception.
        check('record ids: no posture given refuses to build, rather than show raw ids',
            throws('InvalidArgumentException', function () {
                new FindingRowMapper(['pid' => PID, 'now' => NOW, 'valuesState' => 'raw',
                                      'readerCeiling' => 'raw', 'identifiers' => []]);
            }));
        check('record ids: a NULL posture is no posture',
            throws('InvalidArgumentException', function () { mapper(['recordIds' => null]); }));
        check('record ids: there is no third posture',
            throws('InvalidArgumentException', function () { mapper(['recordIds' => 'masked']); }));
        $calls = 0;
        $counting = mapper(['recordIds' => 'hashed', 'hashRecordId' => function ($id) use (&$calls) { $calls++; return 'h-' . strlen($id); }]);
        $counting->toFinding(row()); $counting->toFinding(row(['field' => 'b_val']));
        check('record ids: one record is hashed once, however many findings it has', $calls === 1);

        // RP-3. The memo of hashed ids had no bound, so a mapper kept one entry
        // per distinct record for its whole life - 85 MiB at 500,000 records,
        // out of memory at 1,000,000 under 128M - behind a csvLine() that
        // promises nothing is buffered. Streamed records must not accumulate.
        $stream = mapper(['recordIds' => 'hashed',
                          'hashRecordId' => function ($id) { return hash_hmac('sha256', $id, 'k'); }]);
        $probe = row();
        gc_collect_cycles();
        $before = memory_get_usage();
        for ($i = 0; $i < 50000; $i++) {
            $probe['record_id_bin'] = 'P' . str_pad((string) $i, 9, '0', STR_PAD_LEFT);
            $stream->toFinding($probe);
        }
        gc_collect_cycles();
        $grew = memory_get_usage() - $before;
        check('record ids: 50,000 distinct records streamed through one mapper keep no memory per record (grew '
            . round($grew / 1024) . ' KiB)', $grew < 512 * 1024);
        foreach ([['null', function () { return null; }], ['empty', function () { return ''; }],
                  ['throwing', function () { throw new \RuntimeException('no key'); }]] as $case) {
            $r = mapper(['recordIds' => 'hashed', 'hashRecordId' => $case[1]])->toFinding(row())['record'];
            check('record ids: a ' . $case[0] . ' hasher shows neither the id nor a blank',
                $r === FindingRowMapper::ID_UNAVAILABLE);
        }
        check('record ids (control): raw is raw', mapper()->toFinding(row())['record'] === 'R-1');
    }

    /* =====================================================================
     * 5. REASONS AND WORDING
     * ===================================================================== */
    {
        $staff = function ($key) { $c = json_decode(file_get_contents(__DIR__ . '/../php/messages/catalog.json'), true);
                                   return isset($c[$key]['staff']) ? $c[$key]['staff'] : null; };
        check('wording: the catalogue has the two entries the stored report needs',
            is_string($staff('pooled/check-character')) && is_string($staff('unique/duplicate')));

        // A bare `assert` is what is STORED; the expression comes from the rule.
        check('wording: a stored assertion says what the rule requires',
            MessageCatalog::detail(['type' => 'constraint', 'reason' => 'assert'], $RULE_A) === "The rule requires: [a_val]='OK'");
        check('wording: the engine form still does',
            MessageCatalog::detail(['type' => 'constraint', 'reason' => "assert:[a_val]='OK'"], $RULE_A)
                === "The rule requires: [a_val]='OK'");
        check('wording: with no rule to ask, a bare assertion adds nothing rather than "requires: "',
            MessageCatalog::detail(['type' => 'constraint', 'reason' => 'assert'], dims()->rule(1)) === '');
        check('wording (control): a reason with no detail has none',
            MessageCatalog::detail(['type' => 'single', 'reason' => 'format'], $RULE_A) === '');

        $d = MessageCatalog::detail(['type' => 'pooled', 'reason' => 'check-character;junk'], dims()->rule(1));
        check('wording: a joined pooled reason gets a sentence per part',
            strpos($d, $staff('pooled/check-character')) === 0 && strpos($d, $staff('pooled/junk')) !== false);
        $u = MessageCatalog::detail(['type' => 'pooled', 'reason' => 'junk;from-the-future'], dims()->rule(1));
        check('wording: a part nobody has words for is NAMED, not dropped',
            strpos($u, $staff('pooled/junk')) === 0 && strpos($u, 'Also reported: from-the-future.') !== false);
        check('wording: a part said twice is said once',
            substr_count(MessageCatalog::detail(['type' => 'pooled', 'reason' => 'junk; junk'], dims()->rule(1)),
                $staff('pooled/junk')) === 1);

        // The mask. Nothing writes it yet; when something does, it reads back.
        $names = ['length', 'charset', 'checkdigit', 'prefix', 'duplicate', 'no-id', 'check-character', 'junk', 'count'];
        check('reasons: bit positions are the wire format - the first five did not move',
            ReasonCode::pooledNames(0b11111) === array_slice($names, 0, 5));
        check('reasons: and the engine vocabulary is appended',
            ReasonCode::pooledNames(0b111111111) === $names);
        $bits = (1 << 6) | (1 << 7);
        check('reasons: a stored mask comes back as the joined form the wording takes',
            mapper()->toFinding(row(['check_type' => 'pooled', 'reason_code' => 'pooled', 'reason_bits' => $bits]))['reason']
                === 'check-character;junk');
        check('reasons: bits = 0 is "not recorded": the code stands as stored',
            mapper()->toFinding(row(['check_type' => 'pooled', 'reason_code' => 'check-character;junk']))['reason']
                === 'check-character;junk');
        // RP-8. A bit nobody has a name for was dropped without a word, unlike
        // an unknown joined part, which detail() names.
        $odd = (1 << 7) | (1 << 12);
        $oddF = mapper()->toFinding(row(['check_type' => 'pooled', 'reason_code' => 'pooled', 'reason_bits' => $odd]));
        check('reasons: a mask bit nobody has a name for is named by its position, not dropped',
            $oddF['reason'] === 'junk;bit-12' && ReasonCode::pooledNames(1 << 12) === ['bit-12']);
        check('reasons: and the sentence says it was reported',
            strpos(ScanColumns::row($oddF, dims())['problem'], 'Also reported: bit-12.') !== false);
        check('reasons: a mask on any other code is ignored',
            mapper()->toFinding(row(['reason_bits' => $bits]))['reason'] === 'assert');
        check('reasons: "unspecified" is a storage word and leaves as no reason',
            mapper()->toFinding(row(['reason_code' => ReasonCode::UNSPECIFIED]))['reason'] === ''
            && ReasonCode::code('') === ReasonCode::UNSPECIFIED);

        $cellOf = function ($reason) { return ScanColumns::row(['record' => '1', 'event_id' => 1, 'instance' => 1,
            'field' => 'f', 'rule' => 1, 'type' => 'constraint', 'reason' => $reason], dims())['reason']; };
        check('reasons: the Reason column shows the code ReasonCode decides',
            $cellOf("assert:[a]='1'") === 'assert' && $cellOf('format') === 'format' && $cellOf('') === '');
        check('reasons: an over-long code is cut the way the store cut it',
            $cellOf(str_repeat('k', 80)) === ReasonCode::code(str_repeat('k', 80)));
    }

    /* =====================================================================
     * 6. RULES: by source id, then by ordinal, and never "Rule 0"
     * ===================================================================== */
    {
        $RULE_B = ['type' => 'unique', 'label' => 'No duplicate IDs', 'message' => '', 'assert' => '', 'fields' => ['sid']];
        $d = dims([1 => $RULE_A, 2 => $RULE_B], ['src-b' => 2]);

        // What Scan\UniqueFinalizer writes: ordinal 0, no DAG, reason `duplicate`,
        // and no value at all - see finalized(), which section 1 holds to the
        // finalizer's INSERT.
        $dup = mapper()->toFinding(finalized(['project_id' => PID, 'record_id_bin' => 'R-1', 'event_id' => 0,
                                              'instance' => 1, 'host_form' => 'fa', 'field' => 'sid',
                                              'rule_source_id' => 'src-b'], 9));
        $r = ScanColumns::row($dup, $d);
        check('rules: a duplicate finding is found by its source id',
            $r['rule'] === '2' && $r['rule_label'] === 'No duplicate IDs');
        check('rules: it is a Duplicate value, worded from the catalogue',
            $r['issue'] === 'Duplicate value' && strpos($r['problem'], 'more than once') !== false
            && $r['wording'] !== 'fallback');
        check('rules: its value was not kept, which is not an empty field',
            $dup['valueState'] === 'not-kept' && $r['value'] === ScanColumns::VALUE_WORDS['not-kept']);
        $orphan = ScanColumns::row($dup, dims([1 => $RULE_A]));
        check('rules: with no source map, ordinal 0 is an empty cell and never "0"',
            $orphan['rule'] === '' && $orphan['rule_label'] === '');
        check('rules (control): ordinal 1 is shown as 1',
            ScanColumns::row(mapper()->toFinding(row()), dims([1 => $RULE_A]))['rule'] === '1');

        // The source id wins over a stale ordinal: a rule was added above it.
        $moved = mapper()->toFinding(row(['rule_ord' => 1, 'rule_source_id' => 'src-b', 'check_type' => 'unique',
                                          'reason_code' => 'duplicate']));
        check('rules: the source id outranks the ordinal the row carries',
            ScanColumns::row($moved, $d)['rule_label'] === 'No duplicate IDs');
        check('rules: an unknown source id falls back to the ordinal',
            ScanColumns::row(mapper()->toFinding(row(['rule_source_id' => 'gone'])), $d)['rule_label'] === 'A must be OK');
        check('rules: an ordinal nobody knows keeps its number and has no name',
            $d->ruleOf(['rule' => 12]) === ['type' => '', 'label' => '', 'message' => '', 'assert' => '', 'fields' => [], 'detail' => [], 'ordinal' => 12]);
        check('rules: a negative or non-numeric ordinal is no rule',
            $d->ruleOf(['rule' => -3])['ordinal'] === 0 && $d->ruleOf(['rule' => 'x'])['ordinal'] === 0
            && $d->ruleOf([])['ordinal'] === 0);

        // RP-6. The last-resort sentence names a rule by number. It named the
        // ordinal the row CARRIES while the Rule column shows the one ruleOf()
        // resolved, so one row named two rules - and "Rule 0" came back in the
        // sentence after W7-R4 took it out of the column.
        $RULE_G = ['type' => 'geofence', 'label' => 'Inside the study area', 'message' => '', 'assert' => '', 'fields' => ['a_val']];
        $dg = dims([1 => ['type' => 'x', 'label' => 'Old rule one', 'message' => '', 'assert' => '', 'fields' => []],
                    3 => $RULE_G], ['src-moved' => 3]);
        $gm = ScanColumns::row(mapper()->toFinding(row(['check_type' => 'geofence', 'reason_code' => 'outside',
                                                         'rule_ord' => 1, 'rule_source_id' => 'src-moved'])), $dg);
        check('rules: the sentence names the rule the Rule column shows',
            $gm['rule'] === '3' && $gm['wording'] === 'fallback'
            && strpos($gm['problem'], 'Rule 3 ') === 0 && strpos($gm['problem'], 'Rule 1 ') === false);
        $g0 = ScanColumns::row(mapper()->toFinding(row(['check_type' => 'geofence', 'reason_code' => 'outside',
                                                         'rule_ord' => 0, 'rule_source_id' => 'gone'])), dims());
        check('rules: a finding with no rule is never "Rule 0" in the sentence either',
            $g0['rule'] === '' && strpos($g0['problem'], 'Rule 0') === false && strpos($g0['problem'], 'Rule ? ') === 0);
        $none = ['type' => '', 'label' => '', 'message' => '', 'assert' => '', 'fields' => []];
        check('rules (control): a rule shape with no resolved ordinal keeps the finding\'s own number',
            strpos(MessageCatalog::explain(['type' => 'geofence', 'reason' => 'x', 'rule' => 7], $none)['text'], 'Rule 7 ') === 0);

        // AN UNKNOWN KIND DEGRADES TO GENERIC WORDING, never to a hole or a throw.
        $future = ScanColumns::row(mapper()->toFinding(row(['check_type' => 'geofence', 'reason_code' => 'outside'])), $d);
        check('rules: a check type nobody has heard of still renders every cell',
            $future['check'] === 'geofence' && $future['reason'] === 'outside' && $future['problem'] !== ''
            && $future['issue'] === 'Wrong value');
    }

    /* =====================================================================
     * 7. THE FILE
     * ===================================================================== */
    {
        $d = dims([1 => $RULE_A]);
        $report = new ScanReport(mapper(), $d, runRow(['values_state' => 'raw']));
        $cells = function ($line) { return str_getcsv($line); };

        $evil = $report->csvLine(row(['value_bin' => "\x01=1+1", 'value_len' => 5]));
        $parsed = $cells($evil);
        $valueAt = array_search('value', $cells($report->csvHeaderLine()), true);
        check('csv: a control byte in front of a formula does not get it through',
            $parsed[$valueAt] === "'=1+1");
        check('csv (control): an ordinary value is not given an apostrophe',
            $cells($report->csvLine(row()))[$valueAt] === 'NO');
        check('csv: a formula in the record id is defused too',
            $cells($report->csvLine(row(['record_id_bin' => '@SUM(A1)'])))[array_search('record', $cells($report->csvHeaderLine()), true)]
                === "'@SUM(A1)");

        $w = count($cells($report->csvHeaderLine()));
        check('csv: header, data and meta lines are one width',
            $w > 5 && count($parsed) === $w && count($cells($report->csvMetaLine('coverage', 'fenced-complete'))) === $w);
        check('csv: a meta line is marked, keeps its value, and is defused like data',
            $cells($report->csvMetaLine('note', '=cmd'))[0] === '# note'
            && $cells($report->csvMetaLine('note', '=cmd'))[1] === "'=cmd");
        check('csv: the header is machine keys, not labels',
            in_array('rule_label', $cells($report->csvHeaderLine()), true)
            && !in_array('Rule name', $cells($report->csvHeaderLine()), true));
        check('csv: every cell is quoted', preg_match('/^"[^"]*"(,"([^"]|"")*")*$/', $report->csvLine(row())) === 1);

        $rows = $report->present([row(), row(['finding_id' => 10, 'field' => 'b_val'])]);
        check('present: a row per stored row, keyed by column, carrying its id',
            count($rows) === 2 && $rows[1]['id'] === 10 && $rows[1]['cells']['field'] === 'b_val'
            && array_keys($rows[0]['cells']) === $cells($report->csvHeaderLine()));
        check('present: the table and the file are the same cells',
            array_values($rows[0]['cells']) === $cells($report->csvLine(row())));
        check('present: one bad row stops the report; it is never a shorter report',
            throws('InvalidArgumentException', function () use ($report) { $report->present([row(), ['field' => 'x']]); }));

        // RP-7. Only the Value cell was held to UTF-8. One Latin-1 byte in a
        // field label, a rule label or an assert expression reached the cells
        // as it was, json_encode() of the page's rows returned false, and
        // ScanPageView::h() rendered the cell blank.
        $ld = dims([1 => ['type' => 'constraint', 'label' => "Poids (kg) \xE9tabli", 'message' => '',
                          'assert' => "[a_val]<>'caf\xE9'", 'fields' => ['a_val']]]);
        $ld->fieldLabels = ['a_val' => "Poids \xE9tabli"];
        $lrows = (new ScanReport(mapper(), $ld, runRow()))->present([row()]);
        $badCells = [];
        foreach ($lrows[0]['cells'] as $k => $v) if (!mb_check_encoding($v, 'UTF-8')) $badCells[] = $k;
        check('cells: a label that is not UTF-8 leaves as text, in every column (bad: ' . implode(',', $badCells) . ')',
            $badCells === [] && json_encode($lrows) !== false);
        check('cells: and keeps every byte that was text, marking the one that was not',
            $lrows[0]['cells']['field_label'] === "Poids \u{FFFD}tabli"
            && strpos($lrows[0]['cells']['problem'], "caf\u{FFFD}") !== false);
        check('cells: the process-wide substitute character is put back', mb_substitute_character() === 63);
        $okd = dims([1 => $RULE_A]);
        $okd->fieldLabels = ['a_val' => "Poids \u{e9}tabli"];
        check('cells (control): a label that is already text is not touched',
            ScanColumns::row(mapper()->toFinding(row()), $okd)['field_label'] === "Poids \u{e9}tabli");
    }

    /* =====================================================================
     * 8. RUN FACTS: what may be said about the whole report
     * ===================================================================== */
    {
        $facts = function (array $run, $dimsObj = null, $expected = null) {
            $vs = isset($run['values_state']) ? $run['values_state'] : 'raw';
            return (new ScanReport(mapper(['valuesState' => $vs]), $dimsObj ?: dims(), runRow($run)))->runFacts($expected);
        };
        $complete = ['terminal' => ScanOutcome::COMPLETE, 'coverage' => ScanOutcome::FENCED,
                     'detail' => ScanOutcome::DETAIL_COMPLETE, 'values_state' => 'raw'];
        $sayClean = function ($x) { return stripos(json_encode($x), 'clean') !== false; };

        $ok = $facts($complete + ['clean' => 1]);
        check('facts (control): a complete, clean run may be certified', $ok['mayCertify'] === true && $ok['suffix'] === '');
        check('facts: a complete run that found something may not', $facts($complete + ['clean' => 0])['mayCertify'] === false);
        check('facts: a run with no stored decision is NOT CERTIFIED',
            $facts($complete + ['clean' => null])['mayCertify'] === false);
        $partial = ['terminal' => ScanOutcome::PARTIAL, 'coverage' => ScanOutcome::COV_PARTIAL,
                    'detail' => ScanOutcome::DETAIL_TRUNCATED, 'values_state' => 'locations', 'clean' => 1];
        $p = $facts($partial);
        check('facts: a partial run may not, whatever its row says',
            $p['mayCertify'] === false && $p['suffix'] === '_INCOMPLETE_TRUNCATED');
        check('facts: a running run is not an unmarked file',
            $facts(['terminal' => null, 'clean' => null])['suffix'] !== '');
        check('facts: nothing a caller could print says "clean"', !$sayClean($p) && !$sayClean($ok));
        check('facts (control): that predicate can see the word', $sayClean(runRow($partial)));

        check('facts: the coverage sentence is the page\'s own, truncation included',
            $p['coverageSentence'] === ScanPageView::coverageSentence(ScanOutcome::COV_PARTIAL, ScanOutcome::DETAIL_TRUNCATED)
            && strpos($p['coverageSentence'], 'not kept') !== false);
        check('facts: the keys are the ones wave 11 was promised',
            array_keys($p) === ['schema', 'columns', 'legend', 'coverage', 'detail', 'coverageSentence', 'mayCertify',
                                'suffix', 'valuesState', 'labelsDegraded', 'degradedSummary', 'expectedRows']);
        check('facts: schema, columns and legend describe the file',
            $p['schema'] === ScanReport::SCHEMA && in_array('record', $p['columns'], true)
            && strpos($p['legend'], 'record=Record') !== false && $p['valuesState'] === 'locations');
        check('facts: the expected row count is the caller\'s, and never negative',
            $p['expectedRows'] === null && $facts($partial, null, 12)['expectedRows'] === 12
            && $facts($partial, null, -4)['expectedRows'] === 0);

        $bad = dims(); $bad->degraded = ['events' => 'the event map could not be read'];
        $deg = $facts($complete + ['clean' => 1], $bad);
        check('facts: degraded labels are said, with the source named',
            $deg['labelsDegraded'] === true && strpos($deg['degradedSummary'], 'event map') !== false);
        check('facts (control): and not said when nothing degraded',
            $ok['labelsDegraded'] === false && $ok['degradedSummary'] === '');

        check('facts: a report with no run to report on refuses to build',
            throws('InvalidArgumentException', function () { new ScanReport(mapper(), dims(), ['terminal' => 'complete']); }));

        // RP-4. The mapper refuses a finding from another project; the report
        // took the run row it certifies from on trust, so a report for this
        // project could say "clean" from another project's run.
        $foreign = $complete + ['clean' => 1, 'project_id' => (string) (PID + 1)];
        check('facts: a report refuses another project\'s run row',
            throws('RuntimeException', function () use ($foreign) { new ScanReport(mapper(), dims(), runRow($foreign)); }));
        check('facts: and one with no project at all',
            throws('RuntimeException', function () use ($complete) {
                new ScanReport(mapper(), dims(), runRow($complete + ['clean' => 1, 'project_id' => null]));
            }));
        $noPid = runRow($complete + ['clean' => 1]);
        unset($noPid['project_id']);
        $said = '';
        try { new ScanReport(mapper(), dims(), $noPid); } catch (\InvalidArgumentException $e) { $said = $e->getMessage(); }
        check('facts: a run row without the column refuses to build, naming it',
            strpos($said, 'project_id') !== false);
        // The Value cells come from the mapper, the valuesState fact from the
        // run row; told different things, the heading and the cells disagree.
        check('facts: a mapper told raw cannot report a run that kept locations',
            throws('InvalidArgumentException', function () use ($complete) {
                new ScanReport(mapper(['valuesState' => 'raw']), dims(),
                               runRow(['values_state' => 'locations'] + $complete + ['clean' => 1]));
            }));
        check('facts: nor one told locations report a run that kept raw values',
            throws('InvalidArgumentException', function () use ($complete) {
                new ScanReport(mapper(['valuesState' => 'locations']), dims(), runRow($complete + ['clean' => 1]));
            }));
        check('facts (control): a mapper built for this run builds, whatever spelling the run row uses',
            !throws('Throwable', function () use ($complete) {
                new ScanReport(mapper(['valuesState' => 'locations']), dims(),
                               runRow(['values_state' => 'none'] + $complete + ['clean' => 1]));
            }));
    }

    /* =====================================================================
     * 9. A HAND-FILLED ScanDimensions IS AS GOOD AS A BUILT ONE
     * ===================================================================== */
    {
        $dict = dict(['record_id' => [], 'a_val' => ['@UVASSERT={"assert":"[a_val]=\'OK\'","message":"","blockSave":"hard"}']]);
        $m = mkMod($dict, []);
        $built = $m->scanDimensions(PID);
        $hand = new ScanDimensions();
        foreach (['rules', 'fieldLabels', 'fieldForms', 'forms', 'events', 'hasDags', 'singleEventProved',
                  'degraded', 'identifiers'] as $prop) {
            if (property_exists($built, $prop)) $hand->$prop = $built->$prop;
        }
        $f = mapper()->toFinding(row());
        check('dimensions: filled by hand, it renders what build() renders',
            ScanColumns::row($f, $hand) === ScanColumns::row($f, $built));
        check('dimensions (control): and an empty one does not',
            ScanColumns::row($f, new ScanDimensions()) !== ScanColumns::row($f, $built));
        check('dimensions: the rule snapshot reached the wording',
            strpos(ScanColumns::row($f, $hand)['problem'], "[a_val]='OK'") !== false);
    }

    echo "scan_report_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
