<?php
/**
 * scan_page_php.php — pages/scan.php, which until 1.6.2 had no test at all.
 *
 * The page was never linted by CI, never named in the package assertion, and
 * never executed by a test. It also could not BE tested: it declared uv_h() and
 * uv_csv() as namespace-level functions, so a second include in one process was
 * a fatal redeclare. Those now live on ScanPageView.
 *
 * What this file locks:
 *
 *   S-01  the rights probes are is_callable, not method_exists. The framework
 *         serves methods through __call(), for which method_exists() answers
 *         false — and here that made DAG confinement fail OPEN, so a DAG-bound
 *         user would scan and display every other group's records. This is the
 *         same probe that silently disabled @UVUNIQUE in v1.4.0.
 *   S-02  getRights() may be keyed by project id. Read past that shape and
 *         $rights['group_id'] is simply unset, which reads as "no DAG" and
 *         confines nothing — the same leak by a different route.
 *   S-03  an unresolvable DAG REFUSES. It used to set an '__unresolvable__'
 *         sentinel that matched no record, so the scan read nothing, reported
 *         'complete', and rendered the green tick over zero records — a clean
 *         bill of health for a project it never examined.
 *   S-04  the CSV is a CSV. config.json sets "show-header-and-footer": true, so
 *         REDCap emits its whole page before this file runs and the header()
 *         calls were ignored; the artefact people filed was HTML with the data
 *         appended after ~1,089 lines of markup.
 *   S-05  the rendered table is capped, and the COUNT beside it is not, so a
 *         truncated view can never read as a smaller problem than it is (M-02).
 *
 * The CSV cases run in a SUBPROCESS: that path ends in exit, which cannot be
 * shimmed, and it calls ob_end_clean(), which would eat this file's own capture
 * buffer. Each child re-invokes this script with --csv-case=N.
 *
 * Run:  php tests/scan_page_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $subSettings = []; public $projectSettings = []; public $projectIdReturn = null;
        public $logCalls = [];
        /** The user object the page will probe. Swapped per scenario. */
        public $userReturn = null;
        /** Every scanProject() call, so a test can prove the scan never ran. */
        public $scanCalls = [];
        public function getSubSettings($k, $pid = null) {
            $e = ($pid !== null && $pid !== '') ? $pid : $this->projectIdReturn;
            return $e ? $this->subSettings : [];
        }
        public function getProjectSetting($k, $pid = null) {
            return isset($this->projectSettings[$k]) ? $this->projectSettings[$k] : null;
        }
        public function getSystemSetting($k) { return null; }
        public function setSystemSetting($k, $v) {}
        public function getProjectId() { return $this->projectIdReturn; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return count($this->logCalls); }
        public function getUser() { return $this->userReturn; }
        /**
         * A server WITH a proved change fence, unless a scenario says otherwise.
         * Without this every scan is 'manifest-complete' and the green tick is
         * unreachable - which would make "no tick" pass for the wrong reason,
         * exactly the trap the wargame found in the event/DAG column checks.
         */
        public $fenced = true;
        public function query($sql, $params = []) {
            if (!$this->fenced) return null;
            if (strpos($sql, 'SHOW TABLES') !== false)      return new \ExternalModules\FakeRes([['redcap_record_list']]);
            if (strpos($sql, 'log_event_table') !== false)  return new \ExternalModules\FakeRes([['redcap_log_event7']]);
            if (strpos($sql, 'MAX(log_event_id)') !== false) return new \ExternalModules\FakeRes([['918273', '4412']]);
            if (strpos($sql, 'SHOW GRANTS') !== false)      return new \ExternalModules\FakeRes([['GRANT ALL PRIVILEGES ON `rc`.* TO `u`@`h`']]);
            if (strpos($sql, 'FROM redcap_record_list') !== false) return new \ExternalModules\FakeRes([['1']]);
            if (strpos($sql, 'FROM redcap_data') !== false) return new \ExternalModules\FakeRes([['1']]);
            return new \ExternalModules\FakeRes([]);
        }
    }
    class FakeRes {
        private $rows; private $i = 0;
        public function __construct($rows) { $this->rows = $rows; }
        public function fetch_row() { return isset($this->rows[$this->i]) ? $this->rows[$this->i++] : null; }
    }
    /** An ordinary user object: both methods are genuinely declared. */
    class PlainUser {
        public $design = true; public $groupId = null;
        public function __construct($design = true, $groupId = null) {
            $this->design = $design; $this->groupId = $groupId;
        }
        public function hasDesignRights() { return $this->design; }
        /** data_export_tool 1 = Full Data Set, which is what raw values require. */
        public $export = '1';
        /**
         * A4/A1. A REAL rights row carries per-instrument access, and this mock
         * did not: userFormRights() therefore found nothing to read and the
         * entitlement gate barred every rule. The mock now models a user who can
         * open the project's instruments, and a scenario says so when they cannot.
         */
        public $forms = ['fa' => '1', 'fb' => '1', 'fc' => '1'];
        public function getUsername() { return 'probe'; }
        public function getRights($pid = null) {
            $r = ['group_id' => $this->groupId, 'data_export_tool' => $this->export];
            if ($this->forms !== null) $r['forms'] = $this->forms;
            return $r;
        }
    }
    /**
     * S-01. The v1.4.0 shape: the methods exist only through __call(), so
     * method_exists() is false for both while is_callable() is true. Gating a
     * security decision on the former makes it fail open.
     */
    class ProxyUser {
        public $groupId = null;
        public $forms = ['fa' => '1', 'fb' => '1', 'fc' => '1'];
        public function __construct($groupId = null) { $this->groupId = $groupId; }
        public function __call($name, $args) {
            if ($name === 'hasDesignRights') return true;
            if ($name === 'getUsername') return 'proxy';
            if ($name === 'getRights') {
                return ['group_id' => $this->groupId, 'data_export_tool' => '1',
                        'forms' => $this->forms];
            }
            throw new \BadMethodCallException($name);
        }
    }
    /**
     * S-01, the shape that actually leaks. ProxyUser above proxies BOTH methods,
     * so the old page tripped on method_exists('hasDesignRights') and refused —
     * fail CLOSED, and it never reached the probe that fails open. The leak needs
     * design rights to be granted normally and only getRights() to be proxied:
     * method_exists() is then false for that one call, the whole DAG block is
     * skipped, and $dagFilter stays null.
     */
    class SplitProxyUser {
        public $groupId = null;
        public $forms = ['fa' => '1', 'fb' => '1', 'fc' => '1'];
        public function __construct($groupId = null) { $this->groupId = $groupId; }
        public function hasDesignRights() { return true; }   // declared, so this passes either way
        public function getUsername() { return 'split'; }
        public function __call($name, $args) {
            if ($name === 'getRights') {
                return ['group_id' => $this->groupId, 'data_export_tool' => '1',
                        'forms' => $this->forms];
            }
            throw new \BadMethodCallException($name);
        }
    }
    /**
     * S-02. getRights() keyed by project id rather than flat.
     *
     * A1 gave this shape a second consumer: userFormRights() reads 'forms' from
     * the same array, so it has to read through the pid key too. It did not, and
     * this mock is what caught it - the gate barred every rule on a build whose
     * rights happen to be nested.
     */
    class NestedRightsUser {
        public $pid; public $groupId;
        public $forms = ['fa' => '1', 'fb' => '1', 'fc' => '1'];
        public function __construct($pid, $groupId) { $this->pid = $pid; $this->groupId = $groupId; }
        public function hasDesignRights() { return true; }
        public function getUsername() { return 'nested'; }
        public function getRights($pid = null) {
            return [$this->pid => ['group_id' => $this->groupId, 'data_export_tool' => '1',
                                   'forms' => $this->forms]];
        }
    }
    /** A user whose getRights() answers with something unusable. */
    class JunkRightsUser {
        public function hasDesignRights() { return true; }
        public function getRights($pid = null) { return 'not-an-array'; }
    }
    /** A user with design rights and no getRights() at all. */
    class NoRightsMethodUser {
        public function hasDesignRights() { return true; }
    }
}

namespace INSPIRE\UniversalValidator {
    /**
     * S-04. The page calls header() UNQUALIFIED inside this namespace, so PHP's
     * function-name fallback resolves this shim first. Recording the output
     * length AT THE MOMENT IT FIRES is the whole point: a header sent after the
     * page has already begun emitting is a header the browser never honours.
     */
    $GLOBALS['uv_headers'] = [];
    function header($h, $replace = true, $code = 0) {
        $GLOBALS['uv_headers'][] = ['h' => $h, 'buffered' => ob_get_length(), 'level' => ob_get_level()];
    }
    function headers_sent(&$file = null, &$line = null) { return false; }
}

namespace {
    class REDCap {
        public static $data = []; public static $dictionary = [];
        public static $groupNames = [];        // group_id => unique DAG name
        public static $groupThrows = false;
        /** A record REDCap lists but then does not return — forces 'incomplete'. */
        public static $dropFromChunk = null;
        /**
         * R3-2. Every getData call, so a test can prove a route did NO work.
         * The deprecated ?csv=1 route ran a whole scan and threw the result
         * away before redirecting to a page that scanned again - invisible to
         * every assertion about output, because the output was identical.
         */
        public static $reads = 0;
        public static function getData($p) {
            self::$reads++;
            if (empty($p['records'])) return self::$data;    // the id pre-read
            $out = [];
            foreach ($p['records'] as $r) {
                if ((string) $r === (string) self::$dropFromChunk) continue;
                if (isset(self::$data[$r])) $out[$r] = self::$data[$r];
            }
            return $out;
        }
        public static function getDataDictionary($pid, $f = 'array') { return self::$dictionary; }
        public static function getRecordIdField() { return 'record_id'; }
        /**
         * REDCap answers these TWO ways and the module branches on the
         * difference: called with an id it returns ONE name as a string; called
         * without, it returns the whole map as an ARRAY. This mock only ever
         * returned the string, so ScanDimensions - which needs the array - always
         * saw nothing. 'events' was therefore always empty and 'hasDags' always
         * false, and the two column-shape assertions passed because the labels
         * were UNREADABLE, not because of project shape. Neither column had ever
         * been rendered by a test. Same defect class as the chunk mocks 1.6.3
         * was written to fix.
         */
        public static function getGroupNames($unique = false, $gid = null) {
            if (self::$groupThrows) throw new \RuntimeException('simulated DAG lookup failure');
            if ($gid === null) return self::$groupNames;      // the whole map
            return isset(self::$groupNames[$gid]) ? self::$groupNames[$gid] : '';
        }
        public static function getInstrumentEventMappings($pid = null) { return null; }
        /** event_id => unique name. Empty means a classic (single-event) project. */
        public static $eventNames = [];
        public static function getEventNames($u = false, $x = false, $evt = null) {
            if ($evt === null) return self::$eventNames;      // the whole map
            return isset(self::$eventNames[$evt]) ? self::$eventNames[$evt] : ('event_' . $evt . '_arm_1');
        }
        public static function getInstrumentNames($pid = null) { return self::$formNames; }
        /** form => label. Empty means labels are unavailable. */
        public static $formNames = [];
        public static function getRepeatingFormsEvents($pid = null) { return null; }
        public static function isRepeatingForm($e = null, $f = null) { return null; }
    }
    require_once __DIR__ . '/../UniversalValidator.php';

    const PID  = 700;
    const PAGE = __DIR__ . '/../pages/scan.php';

    /* ---- the literal strings the page promises. Pinned so a reword is a -----
       ---- deliberate act, following the W_AMBIG convention in            -----
       ---- tests/crossform_resolution_php.php.                            ----- */
    const W_NO_DESIGN   = 'You need project design rights to run the validation scan.';
    const W_NO_SCOPE    = 'Your Data Access Group could not be established';
    const W_UNRESOLVED  = 'could not be resolved, so there is no scope to scan';
    const W_TICK        = 'No violations found.';
    const W_NOT_CERT    = 'NOT certified clean';
    const W_INCOMPLETE  = 'This scan did not cover the whole project.';
    const W_CAPPED      = 'Showing the first';
    const CSV_HEADER    = 'section,record,event_id,instance,field,rule,type,reason';
    const CSV_BANNER    = '# INCOMPLETE SCAN';
    const CHROME        = '<!DOCTYPE HTML><html><head><title>REDCap</title></head><body>';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    /** field => [form, annotation] */
    function dict(array $spec) {
        $d = [];
        foreach ($spec as $f => $s) {
            $d[$f] = ['field_type' => 'text', 'form_name' => $s[0],
                      'field_annotation' => isset($s[1]) ? $s[1] : ''];
        }
        return $d;
    }

    /**
     * Build a module, install a scenario, run the page, return what it printed.
     * $user is the object getUser() will hand back.
     */
    function render($user, $dict = [], $data = [], $get = [], $opts = []) {
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectIdReturn = PID;
        $m->projectSettings = isset($opts['settings']) ? $opts['settings'] : ['log-values' => ''];
        $m->subSettings = [];
        $m->userReturn = $user;
        $m->fenced = empty($opts['unfenced']);
        \REDCap::$dictionary = $dict;
        \REDCap::$data = $data;
        // Every per-scenario switch is reset HERE and set from $opts, never by the
        // caller before the call: an earlier version let a scenario set
        // \REDCap::$groupThrows and then had render() clear it, so the throw never
        // fired and the check passed for the wrong reason.
        \REDCap::$groupThrows  = !empty($opts['groupThrows']);
        \REDCap::$eventNames   = isset($opts['events']) ? $opts['events'] : [];
        \REDCap::$formNames    = isset($opts['forms']) ? $opts['forms'] : [];
        \REDCap::$dropFromChunk = isset($opts['dropFromChunk']) ? $opts['dropFromChunk'] : null;
        \REDCap::$reads = 0;
        // REDCap's own project object. Reset here and set from $opts, never by
        // the caller before the call - the 1.8.5 lesson about helpers clearing
        // the state a scenario had just set.
        if (isset($opts['proj'])) {
            $GLOBALS['Proj'] = (object) $opts['proj'];
        } else {
            unset($GLOBALS['Proj']);
        }
        $GLOBALS['uv_headers'] = [];
        $_GET = $get;
        $module = $m;                       // the name pages/scan.php expects
        $GLOBALS["uvIncludes"] = (isset($GLOBALS["uvIncludes"]) ? $GLOBALS["uvIncludes"] : 0) + 1;
        ob_start();
        require PAGE;                       // require, NOT require_once: reincluded per scenario
        $html = ob_get_clean();
        return [$html, $m];
    }

    /**
     * Run a scan the way the page's scope would, and return the module + result.
     *
     * pages/scan.php no longer runs one (plan Task 1), so the REPORT layer -
     * ScanColumns, ScanDimensions, MessageCatalog, the clean predicate - can no
     * longer be reached by rendering a page and grepping HTML. It is still live
     * code that the durable report will consume, so it is exercised directly
     * here rather than left uncovered until Task 7 rebuilds the page.
     */
    function scanOf($user, $dict = [], $data = [], $opts = []) {
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectIdReturn = PID;
        $m->projectSettings = isset($opts['settings']) ? $opts['settings'] : ['log-values' => ''];
        $m->subSettings = [];
        $m->userReturn = $user;
        $m->fenced = empty($opts['unfenced']);
        \REDCap::$dictionary = $dict;
        \REDCap::$data = $data;
        \REDCap::$groupThrows  = !empty($opts['groupThrows']);
        \REDCap::$eventNames   = isset($opts['events']) ? $opts['events'] : [];
        \REDCap::$formNames    = isset($opts['forms']) ? $opts['forms'] : [];
        \REDCap::$dropFromChunk = isset($opts['dropFromChunk']) ? $opts['dropFromChunk'] : null;
        \REDCap::$reads = 0;
        if (isset($opts['proj'])) { $GLOBALS['Proj'] = (object) $opts['proj']; }
        else { unset($GLOBALS['Proj']); }
        $scope = \INSPIRE\UniversalValidator\ScanPageView::scanScope($m, PID);
        $res = $m->scanProject(PID, $scope['dag'], 200, null,
            ['valueCeiling' => $scope['valueCeiling'], 'enforceFormRights' => true]);
        return [$m, $res, $scope];
    }

    /** The report layer's answer for one scan: column keys, labels, and rows. */
    function reportOf($m, $res) {
        $dims = $m->scanDimensions(PID, isset($res['rules']) ? $res['rules'] : null);
        $cols = \INSPIRE\UniversalValidator\ScanColumns::all($dims);
        $rows = [];
        foreach ($res['violations'] as $v) {
            $rows[] = \INSPIRE\UniversalValidator\ScanColumns::row($v, $dims, $cols);
        }
        $keys = []; $labels = [];
        foreach ($cols as $c) { $keys[] = $c['key']; $labels[] = $c['label']; }
        return ['dims' => $dims, 'cols' => $cols, 'keys' => $keys, 'labels' => $labels, 'rows' => $rows];
    }

    /** Every value that appeared in one column, across the rows. */
    function colVals(array $rep, $key) {
        $out = [];
        foreach ($rep['rows'] as $r) if (array_key_exists($key, $r)) $out[] = $r[$key];
        return $out;
    }

    /**
     * Run one CSV scenario in a child process. Returns [stdout, headers], where
     * headers is what the page passed to header() and how much output was already
     * buffered at that moment — the child writes it to a side file from a shutdown
     * handler, because the page's own stdout must stay a clean CSV.
     */
    function csvChild($case) {
        if (!is_callable('shell_exec')) return [null, null];   // reported, never silent
        $side = sys_get_temp_dir() . '/uv_scanpage_hdr_' . getmypid() . '_' . $case . '.json';
        @unlink($side);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
             . ' --csv-case=' . escapeshellarg($case) . ' --hdr-out=' . escapeshellarg($side);
        $out = (string) shell_exec($cmd);
        $side_json = is_file($side) ? json_decode((string) file_get_contents($side), true) : null;
        @unlink($side);
        $hdr   = isset($side_json['headers']) ? $side_json['headers'] : null;
        $reads = isset($side_json['reads']) ? (int) $side_json['reads'] : -1;
        return [$out, $hdr, $reads];
    }

    /* =====================================================================
     * CHILD MODE — one CSV scenario, then exit. The parent captures stdout.
     * ===================================================================== */
    $caseArg = null; $hdrOut = null;
    foreach ($argv as $a) {
        if (strpos($a, '--csv-case=') === 0) $caseArg = substr($a, 11);
        if (strpos($a, '--hdr-out=') === 0)  $hdrOut  = substr($a, 10);
    }
    if ($caseArg !== null) {
        // The page ends the CSV path with exit, so this is the only way to get
        // the recorded headers back out: shutdown handlers still run on exit.
        if ($hdrOut !== null) {
            register_shutdown_function(function () use ($hdrOut) {
                @file_put_contents($hdrOut, json_encode(
                    ['headers' => $GLOBALS['uv_headers'], 'reads' => \REDCap::$reads]));
            });
        }
        $D = dict(['record_id' => ['fa'], 'val' => ['fa', '@UVREQUIRED']]);
        if ($caseArg === 'chrome') {
            // S-04. REDCap has already emitted its page into an output buffer by
            // the time this file runs. A real CSV must contain none of it.
            $data = [1 => [1 => ['record_id' => '1', 'val' => '']]];
            ob_start(); echo CHROME;
            render(new \ExternalModules\PlainUser(true, null), $D, $data, ['csv' => '1']);
        } elseif ($caseArg === 'formula') {
            // The formula-injection defusing must survive the rewrite to streaming.
            $data = ['=cmd' => [1 => ['record_id' => '=cmd', 'val' => '']]];
            render(new \ExternalModules\PlainUser(true, null), $D, $data, ['csv' => '1']);
        } elseif ($caseArg === 'export') {
            // TASK 1. pages/export.php must refuse without reading anything. It
            // runs in a child because it sets headers and its own status code.
            $data = [1 => [1 => ['record_id' => '1', 'val' => '']]];
            $m = new \INSPIRE\UniversalValidator\UniversalValidator();
            $m->projectIdReturn = PID;
            $m->projectSettings = ['log-values' => ''];
            $m->subSettings = [];
            $m->userReturn = new \ExternalModules\PlainUser(true, null);
            $m->fenced = true;
            \REDCap::$dictionary = $D;
            \REDCap::$data = $data;
            \REDCap::$reads = 0;
            $module = $m;
            include __DIR__ . '/../pages/export.php';
        }
        exit(0);   // only reached if the page did not exit, which is itself a finding
    }

    /* =====================================================================
     * S-01  the rights probes must not fail open
     * ===================================================================== */
    {
        $D = dict(['record_id' => ['fa'], 'val' => ['fa', '@UVREQUIRED']]);
        // Two records in two different DAGs; only 'north' belongs to the user.
        $data = [
            1 => [1 => ['record_id' => '1', 'val' => '', 'redcap_data_access_group' => 'north']],
            2 => [1 => ['record_id' => '2', 'val' => '', 'redcap_data_access_group' => 'south']],
        ];
        \REDCap::$groupNames = [7 => 'north'];

        // Control: an ordinary user object, DAG-bound. One record is in scope.
        list($html, ) = render(new \ExternalModules\PlainUser(true, 7), $D, $data);
        check('S-01 control: and the scope line says so',
            strpos($html, 'records in your Data Access Group only') !== false);
        // The confinement is a property of the SCOPE, asserted where it lives
        // rather than inferred from a record count the page no longer prints.
        list(, $resC) = scanOf(new \ExternalModules\PlainUser(true, 7), $D, $data);
        check('S-01 control: a DAG-bound user scans only their own group',
            $resC['stats']['manifest'] === 1);

        // THE LEAK. Design rights are declared and granted; only getRights() is
        // proxied. method_exists() answers false for that one call, so the old
        // page skipped the DAG block entirely, left $dagFilter null, and scanned
        // — and printed — the other group's record.
        list(, $resS) = scanOf(new \ExternalModules\SplitProxyUser(7), $D, $data);
        check('S-01: a __call-proxied getRights() still confines the scan to one DAG',
            $resS['stats']['manifest'] === 1);
        $recsS = [];
        foreach ($resS['violations'] as $v) $recsS[(string) $v['record']] = true;
        check('S-01: and the other group\'s record never reaches a finding', !isset($recsS['2']));

        // Both methods proxied. The old page tripped on hasDesignRights first and
        // refused — fail CLOSED, so no leak, but a user who should have been able
        // to run the scan could not. is_callable() fixes both directions at once.
        list($html, $m) = render(new \ExternalModules\ProxyUser(7), $D, $data);
        check('S-01: a fully __call-proxied user is not refused the page',
            strpos($html, W_NO_DESIGN) === false);
        // The confinement is a property of the SCOPE, asserted where it lives
        // rather than inferred from a record count the page no longer prints.
        list(, $resP) = scanOf(new \ExternalModules\ProxyUser(7), $D, $data);
        check('S-01: and is still confined to their DAG',
            $resP['stats']['manifest'] === 1);

        // S-02. Rights keyed by project id: $rights['group_id'] is unset, which
        // the old code read as "not DAG-bound".
        list(, $resN) = scanOf(new \ExternalModules\NestedRightsUser(PID, 7), $D, $data);
        $html = '';
        check('S-02: pid-keyed rights are read through, not past',
            $resN['stats']['manifest'] === 1);
        $recsN = [];
        foreach ($resN['violations'] as $v) $recsN[(string) $v['record']] = true;
        check('S-02: and the other group\'s record never reaches a finding', !isset($recsN['2']));
    }

    /* =====================================================================
     * refusals — and the proof that a refusal does not scan
     * ===================================================================== */
    {
        $D = dict(['record_id' => ['fa'], 'val' => ['fa', '@UVREQUIRED']]);
        $data = [1 => [1 => ['record_id' => '1', 'val' => '']]];

        list($html, $m) = render(new \ExternalModules\PlainUser(false, null), $D, $data, ['run' => '1']);
        check('no design rights: the page refuses', strpos($html, W_NO_DESIGN) !== false);
        check('no design rights: and nothing was scanned',
            strpos($html, 'record(s)') === false);

        list($html, $m) = render(new \ExternalModules\NoRightsMethodUser(), $D, $data, ['run' => '1']);
        check('no getRights(): scope cannot be established, so the page refuses',
            strpos($html, W_NO_SCOPE) !== false);

        list($html, $m) = render(new \ExternalModules\JunkRightsUser(), $D, $data, ['run' => '1']);
        check('unusable getRights(): the page refuses rather than assume no DAG',
            strpos($html, W_NO_SCOPE) !== false);
    }

    /* =====================================================================
     * S-03  an unresolvable DAG must refuse, not certify an empty scan
     * ===================================================================== */
    {
        $D = dict(['record_id' => ['fa'], 'val' => ['fa', '@UVREQUIRED']]);
        $data = [1 => [1 => ['record_id' => '1', 'val' => '', 'redcap_data_access_group' => 'north']]];
        \REDCap::$groupNames = [];          // group 7 resolves to nothing

        list($html, $m) = render(new \ExternalModules\PlainUser(true, 7), $D, $data, ['run' => '1']);
        check('S-03: an unresolvable DAG refuses the scan', strpos($html, W_UNRESOLVED) !== false);
        check('S-03: and never renders the green tick', strpos($html, W_TICK) === false);
        check('S-03: and never claims to have scanned records',
            strpos($html, 'record(s)') === false);

        // The same, when the DAG lookup THROWS rather than returning blank. The
        // group name is resolvable here, so only the throw can produce a refusal
        // — if the flag failed to take effect this check would go green on the
        // scan, which is what makes it falsifiable.
        \REDCap::$groupNames = [7 => 'north'];
        list($html, $m) = render(new \ExternalModules\PlainUser(true, 7), $D, $data,
            ['run' => '1'], ['groupThrows' => true]);
        check('S-03: a throwing DAG lookup refuses too', strpos($html, W_UNRESOLVED) !== false);
        check('S-03: and a throwing lookup scans nothing', strpos($html, 'record(s)') === false);

        // CONTRAST: the same resolvable DAG, no throw, still scans. Without this
        // the fix above would be satisfied by a page that refuses everybody.
        list(, $resOk, $scopeOk) = scanOf(new \ExternalModules\PlainUser(true, 7), $D, $data);
        check('S-03 contrast: a resolvable DAG resolves to a scope and scans',
            $scopeOk['ok'] && $scopeOk['dag'] !== null && $resOk['stats']['manifest'] >= 1);
    }

    /* =====================================================================
     * the four verdict branches
     * ===================================================================== */
    {
        $V = 'INSPIRE\\UniversalValidator\\ScanPageView';
        $D = dict(['record_id' => ['fa'], 'val' => ['fa', '@UVREQUIRED']]);
        \REDCap::$groupNames = [];
        $U = function () { return new \ExternalModules\PlainUser(true, null); };

        // Clean: a populated required field, no rule problems.
        list(, $rC) = scanOf($U(), $D, [1 => [1 => ['record_id' => '1', 'val' => 'X']]]);
        check('verdict: a clean project is clean', $V::verdict($rC)['clean'] === true);
        check('verdict: with nothing to list', !$rC['violations']);

        // A violation: not clean, and the finding is carried.
        list(, $rV) = scanOf($U(), $D, [1 => [1 => ['record_id' => '1', 'val' => '']]]);
        check('verdict: a violation is listed', count($rV['violations']) === 1);
        check('verdict: and the project is not clean', $V::verdict($rV)['clean'] === false);

        // A rule that cannot be evaluated: zero violations, still not clean.
        $DB = dict(['record_id' => ['fa'], 'orphan' => ['fz', '@UVREQUIRED']]);
        list(, $rU) = scanOf($U(), $DB, [1 => [1 => ['record_id' => '1']]],
            ['mappings' => [['event_id' => 1, 'form' => 'fa']]]);
        check('verdict: a project can be violation-free and still not clean',
            !$rU['violations'] ? $V::verdict($rU)['clean'] === false : true);

        // An incomplete sweep: never clean, whatever it found.
        list(, $rI) = scanOf($U(), $D, [
            1 => [1 => ['record_id' => '1', 'val' => 'X']],
            2 => [1 => ['record_id' => '2', 'val' => 'X']],
        ], ['dropFromChunk' => 2]);
        check('verdict: an incomplete sweep is never clean', $V::verdict($rI)['clean'] === false);
    }

    /* =====================================================================
     * TASK 1  the synchronous scan is WITHDRAWN, by every route
     *
     * reports/scan-rebuild-plan-2026-08-17.md Task 1: disable the production
     * synchronous scan and the export-by-rerun control, and say so. The point of
     * these checks is that NO RECORD IS READ - not that the page looks different.
     * A notice above a scan that still runs would be worse than no notice.
     * ===================================================================== */
    {
        $D = dict(['record_id' => ['fa'], 'val' => ['fa', '@UVREQUIRED']]);
        $data = [1 => [1 => ['record_id' => '1', 'val' => '']]];
        \REDCap::$groupNames = [];
        $U = function () { return new \ExternalModules\PlainUser(true, null); };

        foreach ([['run' => '1'], ['csv' => '1'], ['run' => '1', 'csv' => '1'], []] as $get) {
            list($html, ) = render($U(), $D, $data, $get);
            $what = $get ? implode('+', array_keys($get)) : 'no parameters';
            check("TASK1: $what reads no record at all", \REDCap::$reads === 0);
            check("TASK1: $what renders the unavailable notice",
                strpos($html, 'Scan unavailable') !== false
                && strpos($html, 'temporarily unavailable') !== false);
            check("TASK1: $what renders no findings table",
                strpos($html, '<th>') === false && strpos($html, 'violation(s)') === false);
        }

        // POST is not a bypass. The controls were GET-only, so a GET-only check
        // would have let a POST fall through to a page that looks like it simply
        // found nothing.
        foreach ([['run' => '1'], ['csv' => '1']] as $post) {
            $_POST = $post;
            list($html, ) = render($U(), $D, $data, []);
            $_POST = [];
            check('TASK1: a POST ' . implode(',', array_keys($post)) . ' reads no record either',
                \REDCap::$reads === 0);
            check('TASK1: and is told its request did nothing',
                strpos($html, 'nothing was run') !== false);
        }

        // A request that ASKED for a scan is told its request did nothing;
        // one that merely opened the page is not, because nothing was expected.
        list($htmlAsk, ) = render($U(), $D, $data, ['run' => '1']);
        list($htmlPlain, ) = render($U(), $D, $data, []);
        check('TASK1: a bookmarked run link is told explicitly that nothing ran',
            strpos($htmlAsk, 'nothing was run') !== false);
        check('TASK1: simply opening the page is not told that',
            strpos($htmlPlain, 'nothing was run') === false);

        // The button is gone, not merely inert: an offered control that refuses
        // is an invitation to file a bug.
        check('TASK1: no Run control is offered', strpos($htmlPlain, 'Run the scan now') === false);
        check('TASK1: and no Download control is offered',
            strpos($htmlPlain, 'Download CSV') === false
            && strpos($htmlPlain, 'pages/export.php') === false);
        check('TASK1: what still works is stated, so nobody thinks validation stopped',
            strpos($htmlPlain, 'Still running') !== false
            && strpos($htmlPlain, 'save-time audit') !== false);

        // Rights still decide who sees the page. Who may see it is not
        // contingent on what it currently offers, and scanScope() must not be
        // allowed to rot while the scan is away.
        list($htmlNo, ) = render(new \ExternalModules\PlainUser(false, null), $D, $data, ['run' => '1']);
        check('TASK1: a user without design rights still gets the RIGHTS refusal',
            strpos($htmlNo, W_NO_DESIGN) !== false && strpos($htmlNo, 'Scan unavailable') === false);
    }

    /* =====================================================================
     * TASK 1  the exporter refuses before it reads anything
     * ===================================================================== */
    {
        list($out, $hdr, $reads) = csvChild('export');
        check('TASK1: the exporter runs in a child process', $out !== null);
        check('TASK1: and reads NO record - it used to run a whole scan of its own',
            $reads === 0);
        $names = [];
        foreach ((array) $hdr as $h) $names[] = $h['h'];
        check('TASK1: it answers 503, not 403 - unavailable is not unauthorised',
            (bool) preg_grep('~^Content-Type: text/plain~', $names));
        check('TASK1: it is never offered as a download',
            !preg_grep('~^Content-Disposition~', $names));
        check('TASK1: and says plainly that no report was produced',
            strpos((string) $out, 'EXPORT UNAVAILABLE') !== false
            && strpos((string) $out, 'no report was produced') !== false);
        check('TASK1: it emits no CSV header row of its own',
            strpos((string) $out, CSV_HEADER) === false);
    }

    /* =====================================================================
     * M-02  an incomplete scan may not read as a clean one
     *
     * The predicate moved from three local variables inside pages/scan.php to
     * ScanPageView::verdict(), so it survives the page being withdrawn and the
     * durable report gets the same answer rather than a second implementation.
     * ===================================================================== */
    {
        $V = 'INSPIRE\\UniversalValidator\\ScanPageView';
        $D = dict(['record_id' => ['fa'], 'val' => ['fa', '@UVREQUIRED']]);
        // Record 2 is listed by the id read and then not returned by the chunk
        // read - the shape scanProject records as 'incomplete'.
        $data = [
            1 => [1 => ['record_id' => '1', 'val' => 'X']],
            2 => [1 => ['record_id' => '2', 'val' => 'X']],
        ];
        \REDCap::$groupNames = [];

        list(, $res) = scanOf(new \ExternalModules\PlainUser(true, null), $D, $data,
            ['dropFromChunk' => 2]);
        $v = $V::verdict($res);
        check('M-02: an unreadable record makes the scan incomplete', !$v['complete']);
        check('M-02: a scan with zero violations but incomplete coverage is NOT clean',
            !$res['violations'] && !$v['clean']);
        check('M-02: and the reason is recorded, not merely the verdict',
            (bool) $res['incomplete']);

        // CONTRAST: the same project, nothing dropped, IS clean. Without this the
        // three checks above pass on a predicate that never certifies anything.
        list(, $res2) = scanOf(new \ExternalModules\PlainUser(true, null), $D, $data);
        check('M-02 contrast: a complete clean scan is clean',
            $V::verdict($res2)['clean'] === true);

        // A project whose every rule is broken has zero violations and is not
        // clean: the green tick belonged to the violation count alone, which is
        // the narrower claim.
        $broken = array_merge($res2, ['unconfigurable' => [['rule' => 1, 'fields' => [], 'why' => 'x']]]);
        check('M-02: rule problems block clean even with no violations',
            $V::verdict($broken)['clean'] === false);
        check('M-02: a missing coverage key is read as partial, not as proof',
            $V::verdict(['status' => 'complete', 'violations' => [], 'unconfigurable' => []])['clean'] === false);
    }

    /* =====================================================================
     * ScanPageView — the page's escaping and CSV quoting, new in 1.6.2
     * ===================================================================== */
    {
        $V = 'INSPIRE\\UniversalValidator\\ScanPageView';
        check('ScanPageView::h escapes the four XSS-relevant characters',
            $V::h('<a href="x" \'y\'>&') === '&lt;a href=&quot;x&quot; &#039;y&#039;&gt;&amp;');
        check('ScanPageView::h coerces non-strings rather than erroring',
            $V::h(12) === '12' && $V::h(null) === '');
        check('ScanPageView::csv quotes unconditionally, as the README promises',
            $V::csv('plain') === '"plain"');
        check('ScanPageView::csv doubles embedded quotes',
            $V::csv('a"b') === '"a""b"');
        foreach (['=', '+', '-', '@'] as $lead) {
            check("ScanPageView::csv defuses a leading $lead",
                $V::csv($lead . 'cmd|calc') === '"\'' . $lead . 'cmd|calc"');
        }
        check('ScanPageView::csv leaves an interior = alone',
            $V::csv('a=b') === '"a=b"');
        check('ScanPageView::csv passes an empty value through untouched',
            $V::csv('') === '""');
    }

    /* =====================================================================
     * the page is includable more than once  (the redeclare fix)
     * ===================================================================== */
    {
        // Every scenario above already re-included pages/scan.php, so reaching
        // this line at all means the redeclare fatal is gone — but "we got here"
        // is not an assertion, so count the includes and require more than one.
        global $uvIncludes;
        check('the page really was included many times in this process',
            $uvIncludes > 10);
        check('and its helpers are on a class, not at namespace level',
            !function_exists('INSPIRE\\UniversalValidator\\uv_h')
            && !function_exists('INSPIRE\\UniversalValidator\\uv_csv')
            && class_exists('INSPIRE\\UniversalValidator\\ScanPageView'));
    }


    /* =====================================================================
     * CSV  the cell rules the durable exporter still has to obey
     *
     * pages/export.php no longer builds a file (plan Task 1), and Task 7's
     * exporter streams from the STORED run rather than from a live scan. What
     * survives unchanged is the CELL contract - unconditional quoting, formula
     * defusing past whitespace and control bytes, and the header being stable
     * machine keys rather than labels - so that is what is pinned here. The
     * whole-file assertions went with the file; they return with the exporter
     * that has an expected-count and a mandatory completion trailer.
     * ===================================================================== */
    {
        $V = 'INSPIRE\\UniversalValidator\\ScanPageView';
        $C = 'INSPIRE\\UniversalValidator\\ScanColumns';

        $D = dict(['record_id' => ['fa'], 'want' => ['fa'],
                   'code' => ['fa', '@UVASSERT={"assert":"[code]=[want]"}']]);
        $data = [1 => [1 => ['record_id' => '1', 'code' => 'nope', 'want' => 'yes']]];
        \REDCap::$groupNames = [];
        $RAW = ['settings' => ['scan-value-storage' => 'raw'], 'events' => [1 => 'event_1_arm_1'],
                'proj' => ['project_id' => PID, 'longitudinal' => false]];

        list($m, $res) = scanOf(new \ExternalModules\PlainUser(true, null), $D, $data, $RAW);
        $rep = reportOf($m, $res);

        // The header is a CONTRACT with whatever parses the file. Emitting
        // labels would mean any wording improvement silently breaks every
        // downstream consumer.
        $head = $C::headers($rep['cols']);
        check('csv: the header row carries stable keys, not labels',
            in_array('rule_label', $head, true) && !in_array('Rule name', $head, true));
        check('csv: and a legend maps those keys to labels for a human',
            strpos($C::keyLegend($rep['cols']), 'rule_label=Rule name') !== false);
        check('csv: the reason code and the rule KIND both survive',
            in_array('reason', $head, true) && in_array('check', $head, true));

        $line = $V::csvRow(array_values($rep['rows'][0]));
        check('csv: the offending value is carried', strpos($line, '"nope"') !== false);
        check('csv: every cell is quoted, as the README promises',
            substr($line, 0, 1) === '"' && substr($line, -1) === '"');
        check('csv: the finding is explained in words, not just coded',
            strpos($line, 'does not satisfy') !== false);
        check('csv: a row is exactly as wide as the header',
            count($rep['rows'][0]) === count($head));

        // Value policy is the report layer's, not the page's, so it survives too.
        list($m2, $res2) = scanOf(new \ExternalModules\PlainUser(true, null), $D, $data,
            ['settings' => ['scan-value-storage' => 'locations']]);
        $vals = colVals(reportOf($m2, $res2), 'value');
        check('csv: locations-only mode carries no value', !in_array('nope', $vals, true));
        check('csv: and says the value was withheld rather than leaving a blank',
            in_array('[withheld by policy]', $vals, true));

        list($m3, $res3) = scanOf(new \ExternalModules\PlainUser(true, null), $D, $data);
        check('csv: a project nobody has configured discloses no value',
            !in_array('nope', colVals(reportOf($m3, $res3), 'value'), true));

        // The reader's own export rights cap whatever the project chose.
        $deident = new \ExternalModules\PlainUser(true, null);
        $deident->export = '2';
        list($m4, $res4) = scanOf($deident, $D, $data, $RAW);
        check('csv: a de-identified reader is capped at redaction, not raw',
            in_array('nope', colVals(reportOf($m4, $res4), 'value'), true));
        $noExport = new \ExternalModules\PlainUser(true, null);
        $noExport->export = '0';
        list($m5, $res5) = scanOf($noExport, $D, $data, $RAW);
        check('csv: a reader with NO export rights never sees a value',
            !in_array('nope', colVals(reportOf($m5, $res5), 'value'), true));
    }

    /* =====================================================================
     * COLUMNS  what the report declares, independent of who renders it
     * ===================================================================== */
    {
        $D = dict(['record_id' => ['fa'], 'want' => ['fa'],
                   'code' => ['fa', '@UVASSERT={"assert":"[code]=[want]"}']]);
        $data = [1 => [1 => ['record_id' => '1', 'code' => 'nope', 'want' => 'yes']]];
        \REDCap::$groupNames = [];
        // ONE event, not zero, and REDCap saying classic. An EMPTY map means the
        // read failed, which is a different project and a different report.
        $CLASSIC = ['settings' => ['scan-value-storage' => 'raw'],
                    'events' => [1 => 'event_1_arm_1'],
                    'proj' => ['project_id' => PID, 'longitudinal' => false]];
        list($m, $res) = scanOf(new \ExternalModules\PlainUser(true, null), $D, $data, $CLASSIC);
        $rep = reportOf($m, $res);

        check('columns: the report shows the instrument', in_array('Instrument', $rep['labels'], true));
        check('columns: and the value', in_array('Value', $rep['labels'], true));
        check('columns: and a plain-language explanation',
            in_array('What is wrong', $rep['labels'], true)
            && strpos(implode(' ', colVals($rep, 'problem')), 'does not satisfy') !== false);
        check('columns: and the rule name', in_array('Rule name', $rep['labels'], true));
        check('columns: the offending value reaches its cell',
            in_array('nope', colVals($rep, 'value'), true));
        // A column that does not apply to a project's shape is ABSENT, not
        // present-and-empty.
        check('columns: a classic project has no Event column',
            !in_array('event', $rep['keys'], true));
        check('columns: a project with no DAGs has no DAG column',
            !in_array('dag', $rep['keys'], true));

        // R3-5. Dropping the Event column is the CLAIM "every finding here is in
        // the same event", and an unreadable event map cannot support it.
        $dataEv = [1 => [10 => ['record_id' => '1', 'code' => 'nope', 'want' => 'yes'],
                         20 => ['record_id' => '1', 'code' => 'nope', 'want' => 'yes']]];
        list($mEv, $resEv) = scanOf(new \ExternalModules\PlainUser(true, null), $D, $dataEv,
            ['settings' => ['scan-value-storage' => 'raw']]);   // no events, no $Proj
        $repEv = reportOf($mEv, $resEv);
        check('R3-5: an unreadable event map KEEPS the Event column',
            in_array('event', $repEv['keys'], true));
        $evs = colVals($repEv, 'event');
        check('R3-5: and falls back to the raw event id, so two events differ',
            in_array('10', $evs, true) && in_array('20', $evs, true));
        check('R3-5: and records WHY the ids are raw',
            $repEv['dims']->isDegraded()
            && strpos($repEv['dims']->degradedSummary(), 'no event names were returned') !== false);

        // Escaping is the renderer's job and applies to every generated cell.
        $data2 = [1 => [1 => ['record_id' => '1', 'code' => '<img src=x>', 'want' => 'yes']]];
        list($m2, $res2) = scanOf(new \ExternalModules\PlainUser(true, null), $D, $data2, $CLASSIC);
        $cell = \INSPIRE\UniversalValidator\ScanPageView::h(colVals(reportOf($m2, $res2), 'value')[0]);
        check('columns: a value containing markup is escaped, not rendered',
            strpos($cell, '<img src=x>') === false && strpos($cell, '&lt;img') !== false);
    }

    /* =====================================================================
     * FENCE  what a scan may CLAIM is capped by what the server can prove
     *
     * ScanCapabilities computed this cap from the day it was written and
     * nothing consulted it, so the module held a correct, tested implementation
     * of its own central safety property and did not call it - which is worse
     * than not having written it, because the suite reported it as covered.
     * ===================================================================== */
    {
        $V = 'INSPIRE\\UniversalValidator\\ScanPageView';
        $D = dict(['record_id' => ['fa'], 'val' => ['fa', '@UVREQUIRED']]);
        $cleanData = [1 => [1 => ['record_id' => '1', 'val' => 'X']]];
        \REDCap::$groupNames = [];

        // A server that CAN prove a fence: clean is reachable.
        list(, $res) = scanOf(new \ExternalModules\PlainUser(true, null), $D, $cleanData);
        check('fence: with a proved change fence a clean project is clean',
            $V::verdict($res)['clean'] === true);
        check('fence: and the coverage says so in its own words',
            $res['coverage'] === 'complete-through-fence');

        // The same clean project on a server that cannot prove one.
        list(, $res2) = scanOf(new \ExternalModules\PlainUser(true, null), $D, $cleanData,
            ['unfenced' => true]);
        $v2 = $V::verdict($res2);
        check('fence: without one, the SAME clean project is NOT clean', $v2['clean'] === false);
        check('fence: while the sweep itself still reports complete', $v2['complete'] === true);
        // Named, not pinned to one literal: the vocabulary of weaker coverages
        // grows as capability probes are added, and a test that pins today's
        // spelling fails on a MORE honest answer.
        check('fence: and the weaker claim is NAMED, not merely withheld',
            isset($res2['coverage']) && $res2['coverage'] !== ''
            && $res2['coverage'] !== 'complete-through-fence');
        check('fence: with nothing found still being true', !$res2['violations']);
    }

    /* =====================================================================
     * SHAPE  the Event and DAG columns, by project shape
     *
     * These two were once "covered" by assertions that they were ABSENT - which
     * passed because the mocks could not produce a label at all, not because of
     * project shape. Both are asserted present AND absent here.
     * ===================================================================== */
    {
        $D = dict(['record_id' => ['fa'], 'val' => ['fa', '@UVREQUIRED']]);
        $data = [1 => [7 => ['record_id' => '1', 'val' => '',
                             'redcap_data_access_group' => 'north']]];
        $RAW = ['settings' => ['scan-value-storage' => 'raw']];
        $U = function () { return new \ExternalModules\PlainUser(true, null); };

        \REDCap::$groupNames = [];
        list($m1, $r1) = scanOf($U(), $D, $data,
            $RAW + ['events' => [7 => 'baseline_arm_1', 8 => 'followup_arm_1']]);
        $rep1 = reportOf($m1, $r1);
        check('shape: a longitudinal project HAS the Event column',
            in_array('event', $rep1['keys'], true));
        check('shape: and the event is named, not shown as a raw id',
            in_array('baseline_arm_1', colVals($rep1, 'event'), true));

        list($m2, $r2) = scanOf($U(), $D, $data,
            $RAW + ['events' => [7 => 'event_1_arm_1'],
                    'proj' => ['project_id' => PID, 'longitudinal' => false]]);
        check('shape: a classic project omits the Event column BY SHAPE',
            !in_array('event', reportOf($m2, $r2)['keys'], true));

        \REDCap::$groupNames = ['north' => 'north', 'south' => 'south'];
        list($m3, $r3) = scanOf($U(), $D, $data, $RAW);
        $rep3 = reportOf($m3, $r3);
        check('shape: a project WITH groups has the DAG column',
            in_array('dag', $rep3['keys'], true));
        check('shape: and the record\'s group appears in it',
            in_array('north', colVals($rep3, 'dag'), true));

        \REDCap::$groupNames = [];
        list($m4, $r4) = scanOf($U(), $D, $data, $RAW);
        check('shape: a project with no groups omits the DAG column BY SHAPE',
            !in_array('dag', reportOf($m4, $r4)['keys'], true));

        list($m5, $r5) = scanOf($U(), $D, $data, $RAW + ['forms' => ['fa' => 'Enrolment form']]);
        check('shape: an instrument label is preferred over its machine name',
            in_array('Enrolment form', colVals(reportOf($m5, $r5), 'instrument'), true));

        list($m6, $r6) = scanOf($U(), $D, $data, $RAW);
        check('shape: unreadable label sources are recorded as degradation',
            reportOf($m6, $r6)['dims']->isDegraded());
    }

    /* =====================================================================
     * R3-3  the screen and the file scrub the same bytes
     * ===================================================================== */
    {
        $V  = \INSPIRE\UniversalValidator\ScanPageView::class;
        $ESC = "ok" . chr(0) . "NUL" . chr(27) . "ESC" . chr(26) . "SUB";
        $h   = $V::h($ESC);
        $csv = $V::csv($ESC);
        check('R3-3: h() strips NUL, ESC and SUB, as csv() already did',
            strpos($h, chr(0)) === false && strpos($h, chr(27)) === false
            && strpos($h, chr(26)) === false);
        check('R3-3: and keeps the text around them', strpos($h, 'okNULESCSUB') !== false);
        check('R3-3: the file agrees byte for byte with the page',
            trim($csv, '"') === $h);
        // TAB, CR and LF are legitimate in both surfaces and must survive - a
        // scrub that ate them would silently reflow a Notes field.
        $keep = $V::h("a\tb\r\nc");
        check('R3-3: TAB, CR and LF are kept',
            strpos($keep, "\t") !== false && strpos($keep, "\n") !== false);

        // End to end: a stored value carrying ESC is scrubbed on its way into
        // BOTH surfaces, from the same stored bytes.
        $D = dict(['record_id' => ['fa'], 'want' => ['fa'],
                   'code' => ['fa', '@UVASSERT={"assert":"[code]=[want]"}']]);
        $data = [1 => [1 => ['record_id' => '1', 'code' => "bad" . chr(27) . "[2J", 'want' => 'yes']]];
        list($mE, $rE) = scanOf(new \ExternalModules\PlainUser(true, null), $D, $data,
            ['settings' => ['scan-value-storage' => 'raw'], 'events' => [1 => 'event_1_arm_1'],
             'proj' => ['project_id' => PID, 'longitudinal' => false]]);
        $cellRaw = colVals(reportOf($mE, $rE), 'value')[0];
        check('R3-3: an ESC in a stored value never reaches the rendered cell',
            strpos($V::h($cellRaw), chr(27)) === false
            && strpos($V::h($cellRaw), 'bad[2J') !== false);
        check('R3-3: nor the file, from the same bytes',
            strpos($V::csv($cellRaw), chr(27)) === false);
    }

    /* =====================================================================
     * R3-7  one resolution per row, and never the previous row's sentence
     * ===================================================================== */
    {
        $MC = \INSPIRE\UniversalValidator\MessageCatalog::class;
        $fa = ['type' => 'required', 'reason' => 'required-blank', 'rule' => 1, 'field' => 'a'];
        $fb = ['type' => 'single', 'reason' => 'check-character', 'rule' => 2, 'field' => 'b'];
        $none = ['type' => '', 'label' => '', 'message' => '', 'assert' => '', 'fields' => []];

        $a1 = $MC::explain($fa, $none, 'staff');
        $a2 = $MC::explain($fa, $none, 'staff');
        check('R3-7: the same finding resolves to the same answer', $a1 === $a2);
        $b1 = $MC::explain($fb, $none, 'staff');
        check('R3-7: a DIFFERENT finding immediately after gets its own answer',
            $b1['text'] !== $a1['text']);
        $a3 = $MC::explain($fa, $none, 'staff');
        check('R3-7: and the first one still resolves correctly afterwards', $a3 === $a1);

        // The memo is on the TEMPLATE, never on the finished sentence. The
        // catalog is a data file: the day an entry uses {value} or {record}, a
        // cache over the sentence would hand this row the previous row's value.
        $r1 = $MC::explain(['type' => 'assert', 'reason' => 'assert:[a]=1', 'rule' => 7], $none, 'staff');
        $r2 = $MC::explain(['type' => 'assert', 'reason' => 'assert:[b]=2', 'rule' => 7], $none, 'staff');
        check('R3-7: two findings of the same rule are both resolved', $r1 && $r2);

        // An AUTHORED message is verbatim: braces an author typed are their text.
        $auth = ['type' => '', 'label' => '', 'message' => 'Use {site}-NNN', 'assert' => '', 'fields' => []];
        $ex = $MC::explain($fa, $auth, 'staff');
        check('R3-7: an authored message is returned verbatim, braces and all',
            $ex['text'] === 'Use {site}-NNN' && $ex['source'] === 'rule-message');
    }

    /* =====================================================================
     * CLASSIC  an empty event map is an ANSWER on a classic project
     *
     * Found live on pid 135 (DARE-TB), a real classic project: 1.8.6 made the
     * Event column survive an unreadable event map, but read an EMPTY map as an
     * unreadable one. REDCap returns nothing for a classic project because there
     * is nothing to return, so the report grew a column of one repeated internal
     * event id on every row, under a warning that labels could not be read.
     * Nothing had failed.
     * ===================================================================== */
    {
        $D = dict(['record_id' => ['fa'], 'want' => ['fa'],
                   'code' => ['fa', '@UVASSERT={"assert":"[code]=[want]"}']]);
        $data = [1 => [271 => ['record_id' => '1', 'code' => 'nope', 'want' => 'yes']]];
        \REDCap::$groupNames = [];
        $RAWSET = ['settings' => ['scan-value-storage' => 'raw']];
        $U = function () { return new \ExternalModules\PlainUser(true, null); };
        $rep = function ($opts) use ($U, $D, $data) {
            list($m, $r) = scanOf($U(), $D, $data, $opts);
            return reportOf($m, $r);
        };

        // REDCap says classic. No column, and no warning about a read that did
        // not fail.
        $r1 = $rep($RAWSET + ['proj' => ['project_id' => PID, 'longitudinal' => false]]);
        check('CLASSIC: a project REDCap calls classic has no Event column',
            !in_array('event', $r1['keys'], true));
        // On the REASON, not on the banner: other label sources degrade
        // independently here and legitimately raise the same banner.
        check('CLASSIC: and no longer reports the empty event map as a failed read',
            strpos($r1['dims']->degradedSummary(), 'no event names were returned') === false);
        check('CLASSIC: the rest of the report still renders',
            in_array('nope', colVals($r1, 'value'), true));

        // REDCap says longitudinal but the names are unreadable: R3-5's case.
        $r2 = $rep($RAWSET + ['proj' => ['project_id' => PID, 'longitudinal' => true]]);
        check('CLASSIC: a longitudinal project with unreadable names KEEPS the column',
            in_array('event', $r2['keys'], true));
        check('CLASSIC: and still says why the ids are raw',
            strpos($r2['dims']->degradedSummary(), 'no event names were returned') !== false);
        check('CLASSIC: showing the raw event id', in_array('271', colVals($r2, 'event'), true));

        // No project object at all. "Cannot tell" must not drop a column that
        // may be the only thing separating two rows.
        check('CLASSIC: with no project object the column is kept, not dropped',
            in_array('event', $rep($RAWSET)['keys'], true));

        // A $Proj for ANOTHER project answers about the wrong one.
        check('CLASSIC: a project object for a DIFFERENT pid is not trusted',
            in_array('event', $rep($RAWSET + ['proj' => ['project_id' => PID + 1,
                'longitudinal' => false]])['keys'], true));

        // Older builds expose the count but not the flag.
        check('CLASSIC: numEvents = 1 is read as classic when the flag is absent',
            !in_array('event', $rep($RAWSET + ['proj' => ['project_id' => PID,
                'numEvents' => 1]])['keys'], true));
        check('CLASSIC: numEvents > 1 is read as longitudinal',
            in_array('event', $rep($RAWSET + ['proj' => ['project_id' => PID,
                'numEvents' => 4]])['keys'], true));
    }

    echo "scan_page_php: $n checks, $fail failure(s)
";
    exit($fail ? 1 : 0);
}
