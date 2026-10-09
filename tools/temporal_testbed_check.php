<?php
/**
 * temporal_testbed_check.php - run the REAL module over the pid-149 event/instance
 * test bed (docs/testbed/event_instance/*.csv) before it is uploaded live.
 *
 * It renders every designated (record, event, form, instance) page and replays a
 * save of each one, then compares the audit's findings with
 * expected_violations.csv. It also lists every configuration error per form, so
 * the xe_negative fields can be checked against their notes.
 *
 * RUN:  php tools/temporal_testbed_check.php [--pages] [--scale]
 *   --pages   print every page's rule states (configError / deferred / blockSave)
 *   --scale   also load seed_scale.csv and time the page + audit on XE-SCALE
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $logCalls = []; public $projectSettings = [];
        public function getSubSettings($k, $pid = null) { return []; }
        public function getProjectSetting($k, $pid = null) { return $this->projectSettings[$k] ?? null; }
        public function getSystemSetting($k) { return $k === 'log-hmac-key' ? str_repeat('a', 64) : null; }
        public function setSystemSetting($k, $v) {}
        public function getProjectId() { return \TB::PID; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return count($this->logCalls); }
        public function initializeJavascriptModuleObject() { return '<script></script>'; }
        public function getJavascriptModuleObjectName() { return 'EM.T.UV'; }
        public function getUser() { return new TestUser('tester'); }
    }
    class TestUser {
        private $n; public function __construct($n) { $this->n = $n; }
        public function getUsername() { return $this->n; }
        public function hasDesignRights() { return true; }
    }
}

namespace {
    final class TB { const PID = 149; }
    $DIR = __DIR__ . '/../docs/testbed/event_instance/';
    $opts = getopt('', ['pages', 'scale', 'trace:', 'off']);

    function csvRows($path) {
        $fh = fopen($path, 'r'); $head = fgetcsv($fh, 0, ',', '"', '');
        $out = [];
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) $out[] = array_combine($head, $r);
        fclose($fh); return $out;
    }

    // ---- metadata from the generated CSVs -------------------------------------
    $events = csvRows($DIR . 'events.csv');
    $EID = []; $info = [];
    foreach ($events as $i => $e) {
        $id = 101 + $i; $EID[$e['unique_event_name']] = $id;
        $info[$id] = ['unique_event_name' => $e['unique_event_name'], 'arm_num' => (int) $e['arm_num'], 'day_offset' => $e['day_offset']];
    }
    $eventsForms = []; $mappings = [];
    foreach (csvRows($DIR . 'mappings.csv') as $m) {
        $id = $EID[$m['unique_event_name']];
        $eventsForms[$id][] = $m['form'];
        $mappings[] = ['arm_num' => $m['arm_num'], 'unique_event_name' => $m['unique_event_name'], 'event_id' => $id, 'form' => $m['form']];
    }
    $repeats = [];
    foreach (csvRows($DIR . 'repeating.csv') as $r) {
        $id = $EID[$r['event_name']];
        if ($r['form_name'] === '') $repeats[$id] = 'WHOLE'; else $repeats[$id][$r['form_name']] = '';
    }
    $dict = ['record_id' => ['field_name' => 'record_id', 'form_name' => 'id_validation_test', 'field_type' => 'text',
        'field_annotation' => '', 'select_choices_or_calculations' => '', 'text_validation_type_or_show_slider_number' => '', 'identifier' => '', 'field_label' => 'Record ID']];
    $formFields = [];
    foreach (csvRows($DIR . 'dictionary_additions.csv') as $d) {
        $dict[$d['Variable / Field Name']] = [
            'field_name' => $d['Variable / Field Name'], 'form_name' => $d['Form Name'], 'field_type' => $d['Field Type'],
            'field_label' => $d['Field Label'], 'select_choices_or_calculations' => $d['Choices, Calculations, OR Slider Labels'],
            'text_validation_type_or_show_slider_number' => $d['Text Validation Type OR Show Slider Number'],
            'identifier' => '', 'field_annotation' => $d['Field Annotation'],
        ];
        $formFields[$d['Form Name']][] = $d['Variable / Field Name'];
    }

    class TestProj {
        public $project_id = TB::PID; public $longitudinal = true; public $eventInfo; public $eventsForms; public $repeats;
        public function getUniqueEventNames() { $o = []; foreach ($this->eventInfo as $id => $e) $o[$id] = $e['unique_event_name']; return $o; }
        public function getRepeatingFormsEvents() { return $this->repeats; }
        public function isRepeatingEvent($id) { return ($this->repeats[$id] ?? null) === 'WHOLE'; }
    }
    $P = new TestProj(); $P->eventInfo = $info; $P->eventsForms = $eventsForms; $P->repeats = $repeats;
    $GLOBALS['Proj'] = $P;

    class REDCap {
        public static $data = []; public static $dictionary = []; public static $mappings = []; public static $repeats = [];
        public static $eventNames = []; public static $reads = 0;
        public static function getRepeatingFormsEvents($pid) { return self::$repeats; }
        public static function getInstrumentEventMappings($pid = null) { return self::$mappings; }
        public static function getEventNames($unique = false, $arms = false, $id = null) {
            return $id === null ? self::$eventNames : (self::$eventNames[$id] ?? false);
        }
        public static function getDataDictionary($pid, $f = 'array') { return self::$dictionary; }
        public static function getUserRights($pid = null, $u = null) {
            $forms = []; foreach (self::$dictionary as $m) $forms[$m['form_name']] = '1';
            return ['tester' => ['forms' => $forms, 'group_id' => '']];
        }
        public static function getGroupNames($a = false, $b = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
        public static function getData($p) {
            self::$reads++;
            $data = self::$data;
            if (isset($p['records'])) $data = array_intersect_key($data, array_fill_keys((array) $p['records'], true));
            if (!empty($p['events'])) {
                $keep = [];
                foreach ((array) $p['events'] as $e) $keep[is_numeric($e) ? (int) $e : ($GLOBALS['EID'][$e] ?? $e)] = true;
                foreach ($data as &$rec) {
                    foreach ($rec as $ev => $row) if ($ev !== 'repeat_instances' && !isset($keep[$ev])) unset($rec[$ev]);
                    if (isset($rec['repeat_instances'])) $rec['repeat_instances'] = array_intersect_key($rec['repeat_instances'], $keep);
                } unset($rec);
            }
            if (!empty($p['fields'])) {
                $keep = array_fill_keys((array) $p['fields'], true);
                foreach ($data as &$rec) foreach ($rec as $ev => &$row) {
                    if ($ev === 'repeat_instances') {
                        foreach ($row as &$forms) foreach ($forms as &$insts) foreach ($insts as &$vals) $vals = array_intersect_key($vals, $keep);
                        unset($forms, $insts, $vals);
                    } else $row = array_intersect_key($row, $keep);
                } unset($rec, $row);
            }
            return $data;
        }
    }
    $GLOBALS['EID'] = $EID;
    REDCap::$dictionary = $dict; REDCap::$mappings = $mappings; REDCap::$repeats = $repeats;
    foreach ($info as $id => $e) REDCap::$eventNames[$id] = $e['unique_event_name'];

    // ---- seed data into getData('array') shape --------------------------------
    $formOf = []; foreach ($dict as $f => $m) $formOf[$f] = $m['form_name'];
    $checkbox = []; foreach ($dict as $f => $m) if ($m['field_type'] === 'checkbox') $checkbox[$f] = true;
    function loadSeed($rows, $EID, $formOf, $checkbox, $repeats) {
        $data = []; $contexts = [];
        foreach ($rows as $r) {
            $rec = $r['record_id']; $ev = $EID[$r['redcap_event_name']];
            $rf = $r['redcap_repeat_instrument']; $ri = $r['redcap_repeat_instance'];
            $vals = [];
            foreach ($r as $k => $v) {
                if (in_array($k, ['redcap_event_name', 'redcap_repeat_instrument', 'redcap_repeat_instance'], true)) continue;
                if ($v === '' && $k !== 'record_id') continue;
                if (preg_match('/^(.+)___(.+)$/', $k, $m) && isset($checkbox[$m[1]])) { $vals[$m[1]][$m[2]] = $v; continue; }
                $vals[$k] = $v;
            }
            $vals['record_id'] = $rec;
            if ($ri === '') {
                $data[$rec][$ev] = array_merge($data[$rec][$ev] ?? [], $vals);
                foreach ($vals as $k => $_) {
                    $f = preg_replace('/_complete$/', '', $k);
                    if (isset($formOf[$k])) $contexts[$rec][$ev . '|' . $formOf[$k] . '|1'] = [$ev, $formOf[$k], 1];
                    elseif ($k !== $f) $contexts[$rec][$ev . '|' . $f . '|1'] = [$ev, $f, 1];
                }
            } else {
                $data[$rec]['repeat_instances'][$ev][$rf][(int) $ri] = $vals;
                $forms = [];
                foreach ($vals as $k => $_) {
                    if (isset($formOf[$k]) && $formOf[$k] !== 'id_validation_test') $forms[$formOf[$k]] = true;
                    elseif (substr($k, -9) === '_complete') $forms[substr($k, 0, -9)] = true;
                }
                if ($rf !== '') $forms = [$rf => true];
                foreach ($forms as $f => $_) $contexts[$rec][$ev . '|' . $f . '|' . $ri] = [$ev, $f, (int) $ri];
            }
        }
        return [$data, $contexts];
    }
    $seed = csvRows($DIR . 'seed_data.csv');
    if (isset($opts['scale'])) $seed = array_merge($seed, csvRows($DIR . 'seed_scale.csv'));
    [$DATA, $CONTEXTS] = loadSeed($seed, $EID, $formOf, $checkbox, $repeats);
    REDCap::$data = $DATA;

    require_once __DIR__ . '/../UniversalValidator.php';
    function mod() {
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectSettings = ['log-values' => 'raw', 'enable-event-instance-refs' => !isset($GLOBALS['opts']['off'])];
        return $m;
    }
    $NAME = array_flip($EID);

    // ---- 1. configuration errors per form ---------------------------------------
    echo "== configuration errors (record XE-1 context) ==\n";
    $pageStates = [];
    foreach ($eventsForms as $ev => $forms) foreach ($forms as $form) {
        if (!isset($formFields[$form])) continue;
        $key = $form; if (isset($pageStates[$key])) continue;
        $inst = 1; $m = mod();
        ob_start(); $m->redcap_data_entry_form_top(TB::PID, 'XE-1', $form, $ev, null, $inst); $html = ob_get_clean();
        preg_match('#id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        $cfg = json_decode($mm[1] ?? 'null', true);
        $pageStates[$key] = true;
        foreach (($cfg['rules'] ?? []) as $r) {
            if (!empty($r['configError'])) printf("  %-14s %-18s %s\n", $form, implode(',', $r['fields'] ?? []), substr(is_string($r['configError']) ? $r['configError'] : json_encode($r['configError']), 0, 160));
        }
        foreach (($cfg['configErrors'] ?? []) as $e) printf("  %-14s (page) %s\n", $form, substr(json_encode($e), 0, 200));
    }

    // ---- 2. per-page rule states --------------------------------------------------
    if (isset($opts['pages'])) {
        echo "\n== page states ==\n";
        foreach ($CONTEXTS as $rec => $ctxs) foreach ($ctxs as [$ev, $form, $inst]) {
            if (!isset($formFields[$form])) continue;
            $m = mod(); ob_start(); $m->redcap_data_entry_form_top(TB::PID, (string) $rec, $form, $ev, null, $inst); $html = ob_get_clean();
            preg_match('#id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
            $cfg = json_decode($mm[1] ?? 'null', true);
            printf("  %s %s %s#%d bytes=%d\n", $rec, $NAME[$ev], $form, $inst, strlen($mm[1] ?? ''));
            foreach (($cfg['rules'] ?? []) as $r) {
                $s = [];
                foreach (['configError', 'deferred', 'deferredWhy', 'blockSave', 'mode'] as $k) if (isset($r[$k])) $s[] = $k . '=' . substr(json_encode($r[$k]), 0, 90);
                printf("      %-16s %s\n", implode(',', $r['fields'] ?? []), implode(' ', $s));
            }
        }
    }

    // ---- 3. replay a save of every saved context; collect the audit -------------
    $found = []; $other = [];
    $t0 = microtime(true);
    foreach ($CONTEXTS as $rec => $ctxs) foreach ($ctxs as [$ev, $form, $inst]) {
        $m = mod();
        $m->redcap_save_record(TB::PID, (string) $rec, $form, $ev, null, null, null, $inst);
        foreach ($m->logCalls as [$type, $p]) {
            if ($type === 'invalid-id-saved') {
                $fk = $p['record'] . '|' . $NAME[(int) $p['event_id']] . '|' . $p['instance'] . '|' . $p['field'];
                $found[$fk] = $p['type'] . '/' . $p['reason'];
                if (isset($opts['trace']) && strpos($fk, $opts['trace']) !== false) echo "  TRACE $fk <- save of $rec {$NAME[$ev]} $form#$inst
";
            } else {
                $other[$type . ' ' . json_encode($p)] = true;
            }
        }
    }
    $elapsed = microtime(true) - $t0;
    $expected = [];
    foreach (csvRows($DIR . 'expected_violations.csv') as $e) {
        $unres = stripos($e['why'], 'UNRESOLVED') !== false;
        $expected[$e['record'] . '|' . $e['event'] . '|' . $e['instance'] . '|' . $e['field']] = $unres ? 'unresolved' : 'violation';
    }
    echo "\n== audit vs expected_violations.csv ==\n";
    $bad = 0;
    foreach ($expected as $k => $kind) {
        $hit = isset($found[$k]);
        if ($kind === 'violation' && !$hit) { echo "  MISSING  $k\n"; $bad++; }
        if ($kind === 'unresolved' && $hit) { echo "  FALSE+   $k ({$found[$k]}) - expected unresolved\n"; $bad++; }
    }
    foreach ($found as $k => $why) if (!isset($expected[$k])) { echo "  EXTRA    $k ($why)\n"; $bad++; }
    echo "\n== other log entries ==\n";
    foreach (array_keys($other) as $o) echo '  ' . substr($o, 0, 260) . "\n";
    printf("\nmismatches=%d found=%d expected=%d audit_time=%.2fs reads=%d peak_mem=%.1fMB\n",
        $bad, count($found), count($expected), $elapsed, REDCap::$reads, memory_get_peak_usage(true) / 1048576);
}
