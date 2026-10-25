<?php
/**
 * window_glue_php.php — @UVWINDOW: the server half of the glue parity suite (WIN-9).
 *
 * tests/window_fixture.json locks the pure verdict (TemporalLogic and its JS
 * twin). What each side feeds that verdict is written twice: verdictOf() in
 * js/engine.js and findingsWindow() here choose the anchor for "today", "now"
 * and a period, apply the clock margins, and leave out the today-relative
 * parts of a value that was not changed. This suite draws random rules with a
 * fixed seed, each with a value saved at one moment, and works out the
 * server's verdict through findingsWindow() with REDCap's log showing when the
 * value was saved: at that moment (a new value), or days before with the page
 * holding it as saved (an unchanged value). The cases and verdicts are in
 * tests/window_glue_fixture.json; tests/window_glue_dom_js.cjs boots the page
 * rule on each case and holds its verdict against the server's.
 *
 * A difference from the fixture fails: a change to the server's glue changes
 * the verdicts. When the change is meant, rewrite the fixture and run the DOM
 * half, which says whether the page still agrees:
 *
 *   php tests/window_glue_php.php --write
 *   node tests/window_glue_dom_js.cjs
 *
 * Run:  php tests/window_glue_php.php
 */
namespace ExternalModules {
    class AbstractExternalModule {
        public $logCalls = [];
        public $projectSettings = [];
        public $projectIdReturn = 149;
        public function getSubSettings($k, $pid = null) { return []; }
        public function getProjectSetting($k, $pid = null) { return isset($this->projectSettings[$k]) ? $this->projectSettings[$k] : null; }
        public function getSystemSetting($k) { return null; }
        public function setSystemSetting($k, $v) {}
        public function getProjectId() { return $this->projectIdReturn; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return count($this->logCalls); }
        public function initializeJavascriptModuleObject() { return ''; }
        public function getJavascriptModuleObjectName() { return 'X'; }
        public function getUser() { return null; }
    }
}
namespace {
    class REDCap {
        public static function getData($p) { return []; }
        public static function getDataDictionary($pid, $f = 'array') { return []; }
        public static function getRecordIdField() { return 'record_id'; }
    }
    require_once __DIR__ . '/../UniversalValidator.php';
    use INSPIRE\UniversalValidator\TemporalValue;

    /** REDCap's log as ValueStamps reads it: one save of field f per record. */
    final class GlueLog implements \INSPIRE\UniversalValidator\Scan\ScanDb
    {
        public $rows = [];   // record => [ts, data_values]
        public function select($sql, array $params = [])
        {
            if (strpos($sql, 'FROM redcap_projects') !== false) return [['redcap_log_event4']];
            if (isset($params[2])) return [];
            $r = isset($this->rows[(string) $params[1]]) ? $this->rows[(string) $params[1]] : null;
            return $r === null ? [] : [['1', $r[0], '351', $r[1], (string) $params[1]]];
        }
        public function exec($sql, array $params = []) {}
        public function execNoCount($sql, array $params = []) {}
        public function affected() { return 0; }
        public function begin() {}
        public function commit() {}
        public function rollback() {}
    }

    const GLUE_CASES = 900;
    const GLUE_SEED = 20261023;
    $FIXTURE = __DIR__ . '/window_glue_fixture.json';
    $write = in_array('--write', $argv, true);

    /** The cases, drawn the same way on every run (mt_rand is seeded and portable since PHP 7.1). */
    function glue_cases()
    {
        mt_srand(GLUE_SEED);
        $pick = function (array $a) { return $a[mt_rand(0, count($a) - 1)]; };
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $db = new GlueLog();
        $vs = \INSPIRE\UniversalValidator\ValueStamps::forProject($db, 149, new \DateTimeZone('UTC'), new \DateTimeZone('UTC'));
        $rp = new \ReflectionProperty($m, 'valueStampsOverride'); $rp->setAccessible(true); $rp->setValue($m, $vs);
        $clockP = new \ReflectionProperty($m, 'clockOverride'); $clockP->setAccessible(true);
        $fw = new \ReflectionMethod($m, 'findingsWindow'); $fw->setAccessible(true);
        $cases = [];
        for ($i = 0; $i < GLUE_CASES; $i++) {
            $type = $pick(['date', 'datetime', 'datetime_seconds']);
            $fmt = $pick(['ymd', 'dmy', 'mdy']);
            $nowS = gmmktime(mt_rand(0, 23), mt_rand(0, 59), mt_rand(0, 59), mt_rand(1, 12), mt_rand(1, 28), $pick([2024, 2026, 2028]));
            // a clock just before or just after the turn of a period
            $afterTurn = false;
            if (mt_rand(0, 9) === 0) $nowS = gmmktime(23, 59, mt_rand(0, 59), 12, 31, 2025);
            elseif (mt_rand(0, 9) === 0) { $nowS = gmmktime(0, mt_rand(0, 1), mt_rand(0, 59), 1, 1, 2026); $afterTurn = true; }
            $nowC = gmdate('Y-m-d H:i:s', $nowS);
            $clock = ['today' => substr($nowC, 0, 10), 'now' => $nowC];
            $rule = ['type' => 'window', 'fields' => ['f'], 'dateType' => $type, 'dateFormat' => $fmt, 'blockSave' => 'hard'];
            $kind = $pick(['field', 'field', 'today', 'now', 'literal', 'period', 'period_from', 'clock_only']);
            $anchorS = null; $fromType = null; $fromFormat = null;
            if ($kind === 'today' && $type !== 'date') $kind = 'now';
            if ($kind === 'now' && $type === 'date') $kind = 'today';
            // just after the turn, a period counted from now: the page counts
            // it from the plain now, as the server does, not from now less the
            // margin, which still falls in the period before
            if ($afterTurn && $type !== 'date' && mt_rand(0, 1)) $kind = 'period';
            if ($kind === 'field' || $kind === 'period_from') {
                $fromType = $type === 'date' ? 'date' : $pick(['datetime', 'datetime_seconds']);
                $fromFormat = $pick(['ymd', 'dmy', 'mdy']);
                $anchorS = $nowS + mt_rand(-200, 200) * 86400 + mt_rand(-86400, 86400);
                $rule['windowFrom'] = '[a]'; $rule['fromType'] = $fromType; $rule['fromFormat'] = $fromFormat;
            } elseif ($kind === 'today' || $kind === 'now') {
                $rule['windowAnchor'] = $kind;
            } elseif ($kind === 'literal') {
                $litS = $nowS + mt_rand(-200, 200) * 86400;
                $rule['windowAnchor'] = $type === 'date' ? gmdate('Y-m-d', $litS) : gmdate('Y-m-d H:i', $litS);
            }
            if ($kind === 'period' || $kind === 'period_from') {
                $rule['windowPeriod'] = $pick(['week', 'month', 'quarter', 'year']);
                if (!isset($rule['windowFrom'])) $rule['windowAnchor'] = $type === 'date' ? 'today' : ($afterTurn ? 'now' : $pick(['today', 'now']));
                $o = $pick([0, -1, 1, -2]); $o2 = $o + $pick([0, 0, 1, 2]);
                if ($o !== 0 || $o2 !== 0) { $rule['windowOffLo'] = $o; $rule['windowOffHi'] = $o2; }
                if ($rule['windowPeriod'] === 'week') $rule['windowWeekStart'] = $pick(['monday', 'sunday']);
            } elseif ($kind !== 'clock_only') {
                $unit = $type === 'date' ? $pick(['days', 'weeks', 'months', 'years']) : $pick(['days', 'weeks', 'months', 'hours', 'minutes']);
                $lo = $pick([null, -30, -7, -1, 0, 1, 21, -60, -120]);
                $hi = $lo === null ? $pick([0, 7, 30, 60]) : $lo + $pick([0, 1, 7, 14, 60]);
                if ($lo !== null) $rule['windowLo'] = $lo;
                if ($hi !== null && mt_rand(0, 5) > 0) $rule['windowHi'] = $hi;
                if (!isset($rule['windowLo']) && !isset($rule['windowHi'])) $rule['windowHi'] = 0;
                $rule['windowUnit'] = $unit;
            }
            if (mt_rand(0, 2) === 0 || $kind === 'clock_only') $rule['windowNotFuture'] = true;
            if (mt_rand(0, 3) === 0 || ($kind === 'clock_only' && empty($rule['windowNotFuture']))) $rule['windowNotPast'] = true;
            // the value: near now, or near the anchor
            $centre = ($anchorS !== null && mt_rand(0, 1)) ? $anchorS : $nowS;
            $span = $pick([60, 600, 3600, 86400, 86400 * 10, 86400 * 60, 'margin']);
            if ($afterTurn && $kind === 'period') $span = 600;
            // 'margin': 100 to 760 seconds from now, where the page's 120 s and
            // the server's 720 s around now give different verdicts
            $vS = $span === 'margin' ? $nowS + (mt_rand(0, 1) ? 1 : -1) * mt_rand(100, 760) : $centre + mt_rand(-$span, $span);
            $vCan = gmdate('Y-m-d H:i:s', $vS);
            $stored = TemporalValue::format($vCan, $type, 'ymd');        // REDCap stores Y-M-D
            $typed = TemporalValue::format($vCan, $type, $fmt);           // the field shows its own format
            $values = ['f' => $stored];
            $anchorTyped = null;
            if ($anchorS !== null) {
                $aCan = gmdate('Y-m-d H:i:s', $anchorS);
                $values['a'] = TemporalValue::format($aCan, $fromType, 'ymd');
                $anchorTyped = TemporalValue::format($aCan, $fromType, $fromFormat);
            }
            // One case in four: the value was saved days before and is saved
            // again unchanged, so the page holds it as saved.
            $unchanged = mt_rand(0, 3) === 0;
            $savedS = $unchanged ? $nowS - mt_rand(1, 90) * 86400 - mt_rand(0, 86399) : $nowS;
            $rec = 'r' . $i;
            $db->rows[$rec] = [gmdate('YmdHis', $savedS), "f = '" . $stored . "'"];
            $clockP->setValue($m, $clock);
            $res = $fw->invoke($m, $rule, 'window', $values, [], null, 149, $rec, 351, [], ['instance' => 1]);
            $server = $res['invalid'] ? $res['invalid'][0]['reason'] : ($res['unconfigurable'] ? 'unconfigurable' : 'none');
            $cases[] = ['kind' => $kind, 'rule' => $rule, 'clock' => $clock, 'typed' => $typed, 'anchorTyped' => $anchorTyped,
                        'stored' => $values, 'unchanged' => $unchanged, 'server' => $server];
        }
        return $cases;
    }

    $n = 0; $fail = 0;
    function check($label, $cond) { global $n, $fail; $n++; if (!$cond) { $fail++; echo "FAIL: $label\n"; } }

    $cases = glue_cases();
    // One case a line, so a diff names the cases that changed.
    $lines = [];
    foreach ($cases as $c) $lines[] = json_encode($c, JSON_UNESCAPED_SLASHES);
    $json = '{"seed":' . GLUE_SEED . ',"cases":[' . "\n" . implode(",\n", $lines) . "\n]}\n";
    if ($write) {
        file_put_contents($FIXTURE, $json);
        echo 'wrote ' . count($cases) . " cases to tests/window_glue_fixture.json\n";
    }
    $old = is_file($FIXTURE) ? json_decode(file_get_contents($FIXTURE), true) : null;
    check('the fixture exists and holds ' . GLUE_CASES . ' cases', is_array($old) && isset($old['cases']) && count($old['cases']) === GLUE_CASES);
    $diff = 0;
    foreach ($cases as $i => $c) {
        $o = isset($old['cases'][$i]) ? $old['cases'][$i] : null;
        if ($o === $c) continue;
        if ($diff++ < 5) {
            echo '  case ' . $i . ': server now ' . $c['server'] . ', fixture ' . ($o === null ? 'none' : json_encode($o['server'])) . ' | '
                . json_encode(['rule' => $c['rule'], 'clock' => $c['clock'], 'stored' => $c['stored'], 'unchanged' => $c['unchanged']]) . "\n";
        }
    }
    check('every server verdict matches tests/window_glue_fixture.json (' . $diff . ' differ; if the change is meant, '
        . 'run php tests/window_glue_php.php --write, then node tests/window_glue_dom_js.cjs)', $diff === 0);
    // The cases reach every path of the glue, so the DOM half tests something.
    $seen = [];
    foreach ($cases as $c) {
        $seen['kind:' . $c['kind']] = true;
        $seen['type:' . $c['rule']['dateType']] = true;
        $seen['server:' . $c['server']] = true;
        if ($c['unchanged']) $seen['unchanged'] = true;
        if ($c['unchanged'] && $c['server'] !== 'none') $seen['unchanged-bad'] = true;
    }
    foreach (['kind:field', 'kind:today', 'kind:now', 'kind:literal', 'kind:period', 'kind:period_from', 'kind:clock_only',
              'type:date', 'type:datetime', 'type:datetime_seconds', 'server:none', 'server:future', 'server:past',
              'server:window-early', 'server:window-late', 'unchanged', 'unchanged-bad'] as $k) {
        check('the cases include ' . $k, isset($seen[$k]));
    }
    echo "window_glue_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
