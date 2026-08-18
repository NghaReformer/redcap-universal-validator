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
        list($html, $m) = render(new \ExternalModules\PlainUser(true, 7), $D, $data, ['run' => '1']);
        check('S-01 control: a DAG-bound user scans only their own group',
            strpos($html, '>1</b> record(s)') !== false);
        check('S-01 control: and the scope line says so',
            strpos($html, 'records in your Data Access Group only') !== false);

        // THE LEAK. Design rights are declared and granted; only getRights() is
        // proxied. method_exists() answers false for that one call, so the old
        // page skipped the DAG block entirely, left $dagFilter null, and scanned
        // — and printed — the other group's record.
        list($html, $m) = render(new \ExternalModules\SplitProxyUser(7), $D, $data, ['run' => '1']);
        check('S-01: a __call-proxied getRights() still confines the scan to one DAG',
            strpos($html, '>1</b> record(s)') !== false);
        check('S-01: and the other group\'s record id is never printed',
            strpos($html, '<td>2</td>') === false);

        // Both methods proxied. The old page tripped on hasDesignRights first and
        // refused — fail CLOSED, so no leak, but a user who should have been able
        // to run the scan could not. is_callable() fixes both directions at once.
        list($html, $m) = render(new \ExternalModules\ProxyUser(7), $D, $data, ['run' => '1']);
        check('S-01: a fully __call-proxied user can run the scan at all',
            strpos($html, W_NO_DESIGN) === false);
        check('S-01: and is still confined to their DAG',
            strpos($html, '>1</b> record(s)') !== false);

        // S-02. Rights keyed by project id: $rights['group_id'] is unset, which
        // the old code read as "not DAG-bound".
        list($html, $m) = render(new \ExternalModules\NestedRightsUser(PID, 7), $D, $data, ['run' => '1']);
        check('S-02: pid-keyed rights are read through, not past',
            strpos($html, '>1</b> record(s)') !== false);
        check('S-02: and the other group\'s record id is never printed',
            strpos($html, '<td>2</td>') === false);
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
        list($html, $m) = render(new \ExternalModules\PlainUser(true, 7), $D, $data, ['run' => '1']);
        check('S-03 contrast: a resolvable DAG still runs the scan',
            strpos($html, W_UNRESOLVED) === false && strpos($html, 'record(s)') !== false);
    }

    /* =====================================================================
     * the four verdict branches
     * ===================================================================== */
    {
        $D = dict(['record_id' => ['fa'], 'val' => ['fa', '@UVREQUIRED']]);
        \REDCap::$groupNames = [];

        // Clean: a populated required field, no rule problems.
        $clean = [1 => [1 => ['record_id' => '1', 'val' => 'X']]];
        list($html, $m) = render(new \ExternalModules\PlainUser(true, null), $D, $clean, ['run' => '1']);
        check('verdict: a clean project earns the tick', strpos($html, W_TICK) !== false);
        check('verdict: and the count is green, not the red it wears otherwise',
            strpos($html, '#2e7d32') !== false && strpos($html, '#c62828') === false);

        // Violations: red count, table rendered, no tick.
        $bad = [1 => [1 => ['record_id' => '1', 'val' => '']]];
        list($html, $m) = render(new \ExternalModules\PlainUser(true, null), $D, $bad, ['run' => '1']);
        check('verdict: a violation is listed', strpos($html, '<td>val</td>') !== false);
        check('verdict: the tick is withheld', strpos($html, W_TICK) === false);
        check('verdict: and the count is red', strpos($html, '#c62828') !== false);

        // The landing page runs nothing at all.
        list($html, $m) = render(new \ExternalModules\PlainUser(true, null), $D, $bad, []);
        check('verdict: without run=1 the page offers the button and scans nothing',
            strpos($html, 'Run the scan now') !== false && strpos($html, 'record(s),') === false);
    }

    /* =====================================================================
     * S-05  the table is capped; the count never is
     * ===================================================================== */
    {
        // One required field per form, 1,200 records, all blank.
        $D = dict(['record_id' => ['fa'], 'val' => ['fa', '@UVREQUIRED']]);
        $many = [];
        for ($i = 1; $i <= 1200; $i++) $many[$i] = [1 => ['record_id' => (string) $i, 'val' => '']];
        list($html, $m) = render(new \ExternalModules\PlainUser(true, null), $D, $many, ['run' => '1']);

        $rows = substr_count($html, '<tr>') - 1;          // less the header row
        check('S-05: the rendered table stops at the cap',
            $rows === \INSPIRE\UniversalValidator\ScanPageView::TABLE_MAX);
        check('S-05: the truncation is stated, not silent', strpos($html, W_CAPPED) !== false);
        check('S-05: and the COUNT still reports every violation, not the rendered number',
            strpos($html, '1200 violation(s)') !== false
            && strpos($html, '1000 violation(s)') === false);
        check('S-05: a capped view is never green', strpos($html, W_TICK) === false);
    }

    /* =====================================================================
     * S-04  the CSV is a CSV  (subprocess: that path ends in exit)
     * ===================================================================== */
    {
        // The legacy route is now a REDIRECT, not a second exporter. It used to
        // emit a different schema from pages/export.php - unquoted columns, no
        // value, no explanation, no BOM, no _INCOMPLETE suffix - so two live
        // formats answered the same question differently. The header assertions
        // that used to live here moved to the EXPORT section, which tests the
        // one exporter both routes now reach.
        list($out, $hdr, $reads) = csvChild('chrome');
        check('S-04: the legacy csv route still runs in a child process', $out !== null);
        // R3-2. The redirect needs nothing the scan produces, so it must not run
        // one. It used to sit BELOW a `$run || $csv` scan condition: the route
        // scanned the whole project, discarded the result unread, and redirected
        // to a page that scanned it again. Two full scans, one file, and no
        // assertion about output could see it because the output was identical.
        check('R3-2: the deprecated route performs NO reads before redirecting',
            $reads === 0);
        $names = [];
        foreach ((array) $hdr as $h) $names[] = $h['h'];
        check('S-04: it redirects rather than emitting a second format',
            (bool) preg_grep('~^Location: .*export\.php~', $names));
        check('S-04: and the redirect fires with NOTHING already buffered, or it is ignored',
            $hdr && (int) $hdr[0]['buffered'] === 0 && (int) $hdr[0]['level'] === 0);
        check('S-04: it emits no CSV of its own',
            !preg_grep('~^Content-Disposition~', $names)
            && strpos((string) $out, CSV_HEADER) === false);
    }

    /* =====================================================================
     * M-02  an incomplete scan may not read as a clean one
     * ===================================================================== */
    {
        $D = dict(['record_id' => ['fa'], 'val' => ['fa', '@UVREQUIRED']]);
        // Record 2 is listed by the id read and then not returned by the chunk
        // read — the shape scanProject records as 'incomplete'.
        $data = [
            1 => [1 => ['record_id' => '1', 'val' => 'X']],
            2 => [1 => ['record_id' => '2', 'val' => 'X']],
        ];
        \REDCap::$groupNames = [];
        list($html, $m) = render(new \ExternalModules\PlainUser(true, null), $D, $data,
            ['run' => '1'], ['dropFromChunk' => 2]);
        check('M-02: an unreadable record makes the scan incomplete',
            strpos($html, W_INCOMPLETE) !== false);
        check('M-02: a scan with zero violations but incomplete coverage is NOT certified',
            strpos($html, W_NOT_CERT) !== false);
        check('M-02: and the green tick is withheld even with no violations found',
            strpos($html, W_TICK) === false);
        check('M-02: and the count is not coloured green',
            strpos($html, '#2e7d32') === false);

        // CONTRAST: the same project, nothing dropped, earns the tick. Without
        // this the four checks above pass on a page that never certifies anything.
        list($html, $m) = render(new \ExternalModules\PlainUser(true, null), $D, $data, ['run' => '1']);
        check('M-02 contrast: a complete clean scan still earns the tick',
            strpos($html, W_TICK) !== false && strpos($html, W_NOT_CERT) === false);
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
     * EXPORT  pages/export.php — the report as a real CSV
     *
     * A page that is NOT a declared project link is never wrapped in REDCap's
     * chrome, so its header() calls fire with nothing buffered and there is no
     * output buffer to tear down. That is the structural version of the fix
     * scan.php makes by hand.
     * ===================================================================== */
    {
        $D = dict(['record_id' => ['fa'], 'want' => ['fa'],
                   'code' => ['fa', '@UVASSERT={"assert":"[code]=[want]"}']]);
        $data = [
            1 => [1 => ['record_id' => '1', 'code' => 'nope', 'want' => 'yes']],
            2 => [1 => ['record_id' => '2', 'code' => 'yes',  'want' => 'yes']],
        ];

        $exp = function ($user, $dict, $rows, $opts = []) {
            $m = new \INSPIRE\UniversalValidator\UniversalValidator();
            $m->projectIdReturn = PID;
            $m->projectSettings = isset($opts['settings']) ? $opts['settings'] : [];
            $m->subSettings = [];
            $m->userReturn = $user;
            \REDCap::$dictionary = $dict;
            \REDCap::$data = $rows;
            \REDCap::$groupThrows = !empty($opts['groupThrows']);
            \REDCap::$dropFromChunk = isset($opts['dropFromChunk']) ? $opts['dropFromChunk'] : null;
            $GLOBALS['uv_headers'] = [];
            $module = $m;
            ob_start();
            include __DIR__ . '/../pages/export.php';
            return [ob_get_clean(), $GLOBALS['uv_headers']];
        };

        $RAW = ['settings' => ['scan-value-storage' => 'raw']];
        list($out, $hdr) = $exp(new \ExternalModules\PlainUser(true, null), $D, $data, $RAW);
        $names = [];
        foreach ($hdr as $h) $names[] = $h['h'];

        check('export: a CSV content-type header is sent',
            (bool) preg_grep('~^Content-Type: text/csv~', $names));
        check('export: as an attachment with a filename',
            (bool) preg_grep('~^Content-Disposition: attachment.*validation_scan_pid~', $names));
        // The whole point of the separate page: nothing was buffered when the
        // headers fired, so no chrome can have preceded them.
        check('export: the headers fire with NOTHING already buffered',
            count($hdr) >= 2 && (int) $hdr[0]['buffered'] === 0);
        check('export: and no page chrome appears in the body',
            strpos($out, '<!DOCTYPE') === false && strpos($out, '<html') === false);

        // The header row is a CONTRACT, so it carries stable KEYS. Emitting
        // labels meant any wording change silently broke every consumer.
        check('export: the header row carries stable keys, not labels',
            strpos($out, '"instrument"') !== false && strpos($out, '"value"') !== false
            && strpos($out, '"problem"') !== false && strpos($out, '"rule_label"') !== false
            && strpos($out, '"Rule name"') === false);
        check('export: and a comment line maps those keys to labels for a human',
            strpos($out, '# columns: ') !== false && strpos($out, 'rule_label=Rule name') !== false);
        check('export: the reason code and the rule KIND survive to the report',
            strpos($out, '"reason"') !== false && strpos($out, '"check"') !== false
            && strpos($out, '"constraint"') !== false);
        check('export: the header row is present even before any finding row',
            strpos($out, '"issue","record"') !== false);
        check('export: the offending value is carried', strpos($out, '"nope"') !== false);
        check('export: the finding is explained in words, not just coded',
            strpos($out, 'does not satisfy') !== false);
        check('export: a clean run is not labelled incomplete',
            strpos($out, 'INCOMPLETE SCAN') === false);
        check('export: and the metadata line records scope and counts',
            strpos($out, 'scope: whole project') !== false && strpos($out, '# scan of project') !== false);

        // Refusal must NOT be saved as a file.
        list($out2, $hdr2) = $exp(new \ExternalModules\NoRightsMethodUser(), $D, $data);
        $names2 = [];
        foreach ($hdr2 as $h) $names2[] = $h['h'];
        check('export: a refused export says so', strpos($out2, 'EXPORT REFUSED') !== false);
        check('export: and is NOT offered as a download',
            !preg_grep('~Content-Disposition~', $names2));

        // An incomplete scan is marked three independent ways.
        list($out3, $hdr3) = $exp(new \ExternalModules\PlainUser(true, null), $D, $data,
            ['dropFromChunk' => 2]);
        $names3 = [];
        foreach ($hdr3 as $h) $names3[] = $h['h'];
        check('export: an incomplete scan carries the banner',
            strpos($out3, 'INCOMPLETE SCAN') !== false);
        check('export: names it in the FILENAME, which survives forwarding',
            (bool) preg_grep('~filename=.*_INCOMPLETE\.csv~', $names3));
        check('export: and in a terminal data row, which survives deleting the # lines',
            strpos($out3, '"INCOMPLETE"') !== false);
        check('export: the unreadable record is named as data, not only as a comment',
            strpos($out3, '"not-scanned"') !== false);

        // Values honour the policy here too.
        list($out4, ) = $exp(new \ExternalModules\PlainUser(true, null), $D, $data,
            ['settings' => ['scan-value-storage' => 'locations']]);
        // Withheld must not render as the empty cell a genuinely blank field
        // produces — otherwise the omission is invisible, which is what the
        // docs already claimed was not the case.
        check('export: locations-only mode carries no value',
            strpos($out4, '"nope"') === false);
        check('export: and says the value was withheld rather than leaving a blank',
            strpos($out4, '[withheld by policy]') !== false);

        // An un-reconfigured project is what EVERY project looks like on
        // upgrade. It must disclose nothing until someone decides otherwise.
        list($out5, ) = $exp(new \ExternalModules\PlainUser(true, null), $D, $data);
        check('export: a project nobody has configured discloses no value',
            strpos($out5, '"nope"') === false);

        // A3. data_export_tool = 0 is REDCap for No Access to the data export
        // tool. The ceiling used to downgrade what the file CONTAINED while the
        // file was still served, so a user barred from REDCap's own exporter
        // could pull a project-wide findings file from one URL.
        $noExport = new \ExternalModules\PlainUser(true, null);
        $noExport->export = '0';
        list($out6, $hdr6) = $exp($noExport, $D, $data, $RAW);
        $names6 = [];
        foreach ((array) $hdr6 as $h) $names6[] = $h['h'];
        check('A3: a reader with NO export rights is refused the file',
            strpos($out6, 'EXPORT REFUSED') !== false && strpos($out6, '"nope"') === false);
        check('A3: and it is not offered as a download at all',
            !preg_grep('~Content-Disposition~', $names6));
        check('A3: the refusal says what right is missing, and that the page still works',
            stripos($out6, 'data export tool') !== false && stripos($out6, 'scan page') !== false);
        // The SCREEN stays available to them, capped by the same ceiling.
        list($html6, ) = render($noExport, $D, $data, ['run' => '1'],
            ['settings' => ['scan-value-storage' => 'raw'], 'events' => [1 => 'event_1_arm_1']]);
        check('A3: but the on-screen report still runs, with the value still capped',
            strpos($html6, '<td>nope</td>') === false
            && strpos($html6, '[withheld by policy]') !== false);
        check('A3: and the page does not offer a download it would refuse',
            strpos($html6, 'pages/export.php') === false
            && stripos($html6, 'Download unavailable') !== false);

        $deident = new \ExternalModules\PlainUser(true, null);
        $deident->export = '2';
        list($out7, ) = $exp($deident, $D, $data, $RAW);
        check('export: a de-identified reader is capped at redaction, not raw',
            strpos($out7, '"nope"') !== false);   // 'code' is not an Identifier field
    }


    /* =====================================================================
     * COLUMNS  the screen and the file show the SAME thing
     * ===================================================================== */
    {
        $D = dict(['record_id' => ['fa'], 'want' => ['fa'],
                   'code' => ['fa', '@UVASSERT={"assert":"[code]=[want]"}']]);
        $data = [1 => [1 => ['record_id' => '1', 'code' => 'nope', 'want' => 'yes']]];
        \REDCap::$groupNames = [];
        // ONE event, not zero. A classic project's getEventNames() returns its
        // single event; an EMPTY map means the read failed, which is a different
        // project and a different report. This block used to leave it empty and
        // assert the classic outcome, so it passed whichever of the two the code
        // happened to implement.
        list($html, ) = render(new \ExternalModules\PlainUser(true, null), $D, $data, ['run' => '1'],
            ['settings' => ['scan-value-storage' => 'raw'], 'events' => [1 => 'event_1_arm_1']]);

        check('columns: the table shows the instrument', strpos($html, '<th>Instrument</th>') !== false);
        check('columns: and the value', strpos($html, '<th>Value</th>') !== false);
        check('columns: and a plain-language explanation',
            strpos($html, '<th>What is wrong</th>') !== false
            && strpos($html, 'does not satisfy') !== false);
        check('columns: and the rule name', strpos($html, '<th>Rule name</th>') !== false);
        check('columns: the offending value is rendered in a cell',
            strpos($html, '<td>nope</td>') !== false);
        // A classic project has ONE event, so the column is absent rather than
        // present-and-empty. Same for a project with no Data Access Groups.
        check('columns: a classic project shows no Event column',
            strpos($html, '<th>Event</th>') === false);
        check('columns: a project with no DAGs shows no DAG column',
            strpos($html, '<th>Data Access Group</th>') === false);

        // R3-5. Dropping the Event column is the CLAIM "every finding here is in
        // the same event", and an unreadable event map cannot support it. Two
        // findings in different events used to render as byte-identical rows
        // with nothing to tell them apart, while the degraded note said only
        // that the NAMES were missing - it did not put the ids back.
        $dataEv = [1 => [10 => ['record_id' => '1', 'code' => 'nope', 'want' => 'yes'],
                         20 => ['record_id' => '1', 'code' => 'nope', 'want' => 'yes']]];
        list($htmlEv, ) = render(new \ExternalModules\PlainUser(true, null), $D, $dataEv, ['run' => '1'],
            ['settings' => ['scan-value-storage' => 'raw']]);   // no 'events' => the map is unreadable
        check('R3-5: an unreadable event map KEEPS the Event column',
            strpos($htmlEv, '<th>Event</th>') !== false);
        check('R3-5: and falls back to the raw event id, so two events differ',
            strpos($htmlEv, '<td>10</td>') !== false && strpos($htmlEv, '<td>20</td>') !== false);
        check('R3-5: and says on the page WHY the ids are raw',
            strpos($htmlEv, 'Some labels could not be read, so raw identifiers are shown instead') !== false
            && strpos($htmlEv, 'no event names were returned') !== false);
        // The escaping still applies to every generated cell.
        $D2 = dict(['record_id' => ['fa'], 'want' => ['fa'],
                    'code' => ['fa', '@UVASSERT={"assert":"[code]=[want]"}']]);
        $data2 = [1 => [1 => ['record_id' => '1', 'code' => '<img src=x>', 'want' => 'yes']]];
        list($html2, ) = render(new \ExternalModules\PlainUser(true, null), $D2, $data2, ['run' => '1'],
            ['settings' => ['scan-value-storage' => 'raw']]);
        check('columns: a value containing markup is escaped, not rendered',
            strpos($html2, '<img src=x>') === false && strpos($html2, '&lt;img') !== false);
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
        $D = dict(['record_id' => ['fa'], 'val' => ['fa', '@UVREQUIRED']]);
        $cleanData = [1 => [1 => ['record_id' => '1', 'val' => 'X']]];
        \REDCap::$groupNames = [];

        // A server that CAN prove a fence: the tick is reachable.
        list($html, $m) = render(new \ExternalModules\PlainUser(true, null), $D, $cleanData, ['run' => '1']);
        check('fence: with a proved change fence a clean project earns the tick',
            strpos($html, W_TICK) !== false);

        // The same clean project on a server that cannot prove one.
        list($html2, $m2) = render(new \ExternalModules\PlainUser(true, null), $D, $cleanData,
            ['run' => '1'], ['unfenced' => true]);
        check('fence: without one, the SAME clean project does not earn the tick',
            strpos($html2, W_TICK) === false);
        check('fence: and it says why, rather than just withholding it',
            strpos($html2, 'cannot prove the project did not change') !== false);
        check('fence: while still reporting that nothing was found',
            strpos($html2, 'No violations found') !== false);
        // The distinction has to be legible, not merely present.
        check('fence: the unfenced verdict is not coloured as a pass',
            strpos($html2, '#2e7d32') === false);
    }


    /* =====================================================================
     * SHAPE  the Event and DAG columns, rendered for the first time
     *
     * These two were "covered" by assertions that they were ABSENT — which
     * passed because the mock returned a string where ScanDimensions needs an
     * array, so the labels were unreadable and the columns dropped for the wrong
     * reason. Absence has to be proved against a project shape, not against a
     * broken read, or the assertion is satisfied by the bug.
     * ===================================================================== */
    {
        $D = dict(['record_id' => ['fa'], 'want' => ['fa'],
                   'code' => ['fa', '@UVASSERT={"assert":"[code]=[want]"}']]);
        $data = [1 => [1 => ['record_id' => '1', 'code' => 'nope', 'want' => 'yes',
                             'redcap_data_access_group' => 'north']]];
        $RAW = ['settings' => ['scan-value-storage' => 'raw']];

        // A LONGITUDINAL project: two events, so the column belongs.
        \REDCap::$groupNames = [];
        list($h1, ) = render(new \ExternalModules\PlainUser(true, null), $D, $data, ['run' => '1'],
            array_merge($RAW, ['events' => [1 => 'baseline_arm_1', 2 => 'followup_arm_1']]));
        check('shape: a longitudinal project RENDERS the Event column',
            strpos($h1, '<th>Event</th>') !== false);
        check('shape: and the event is named, not shown as a raw id',
            strpos($h1, 'baseline_arm_1') !== false);

        // A CLASSIC project: one event, so the column is absent — and now that
        // is absence by shape, with the label source working.
        list($h2, ) = render(new \ExternalModules\PlainUser(true, null), $D, $data, ['run' => '1'],
            array_merge($RAW, ['events' => [1 => 'baseline_arm_1']]));
        check('shape: a classic project omits the Event column BY SHAPE',
            strpos($h2, '<th>Event</th>') === false);

        // Groups present: the DAG column belongs and carries the group.
        list($h3, ) = render(new \ExternalModules\PlainUser(true, null), $D, $data, ['run' => '1'], $RAW);
        // groupNames was reset above; set it for this scenario only.
        \REDCap::$groupNames = [7 => 'north', 8 => 'south'];
        list($h3, ) = render(new \ExternalModules\PlainUser(true, null), $D, $data, ['run' => '1'], $RAW);
        check('shape: a project WITH groups renders the DAG column',
            strpos($h3, '<th>Data Access Group</th>') !== false);
        check('shape: and the record\'s group appears in it',
            strpos($h3, '>north<') !== false);

        // No groups: absent by shape, with getGroupNames answering normally.
        \REDCap::$groupNames = [];
        list($h4, ) = render(new \ExternalModules\PlainUser(true, null), $D, $data, ['run' => '1'], $RAW);
        check('shape: a project with no groups omits the DAG column BY SHAPE',
            strpos($h4, '<th>Data Access Group</th>') === false);

        // Instrument labels, when readable, are shown instead of form names.
        list($h5, ) = render(new \ExternalModules\PlainUser(true, null), $D, $data, ['run' => '1'],
            array_merge($RAW, ['forms' => ['fa' => 'Enrolment']]));
        check('shape: an instrument label is preferred over its machine name',
            strpos($h5, '>Enrolment<') !== false);

        // W8: a label source that cannot be read is SAID, not silently dropped.
        list($h6, ) = render(new \ExternalModules\PlainUser(true, null), $D, $data, ['run' => '1'], $RAW);
        check('shape: unreadable label sources are reported on the page',
            strpos($h6, 'Some labels could not be read') !== false);
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

        // End to end: the same value reaches the table scrubbed.
        $D = dict(['record_id' => ['fa'], 'want' => ['fa'],
                   'code' => ['fa', '@UVASSERT={"assert":"[code]=[want]"}']]);
        $data = [1 => [1 => ['record_id' => '1', 'code' => "bad" . chr(27) . "[2J", 'want' => 'yes']]];
        list($html, ) = render(new \ExternalModules\PlainUser(true, null), $D, $data, ['run' => '1'],
            ['settings' => ['scan-value-storage' => 'raw'], 'events' => [1 => 'event_1_arm_1']]);
        check('R3-3: an ESC in a stored value never reaches the rendered page',
            strpos($html, chr(27)) === false && strpos($html, 'bad[2J') !== false);
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

    echo "scan_page_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
