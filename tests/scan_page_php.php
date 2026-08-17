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
        public function getRights($pid = null) {
            return ['group_id' => $this->groupId, 'data_export_tool' => $this->export];
        }
    }
    /**
     * S-01. The v1.4.0 shape: the methods exist only through __call(), so
     * method_exists() is false for both while is_callable() is true. Gating a
     * security decision on the former makes it fail open.
     */
    class ProxyUser {
        public $groupId = null;
        public function __construct($groupId = null) { $this->groupId = $groupId; }
        public function __call($name, $args) {
            if ($name === 'hasDesignRights') return true;
            if ($name === 'getRights') return ['group_id' => $this->groupId];
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
        public function __construct($groupId = null) { $this->groupId = $groupId; }
        public function hasDesignRights() { return true; }   // declared, so this passes either way
        public function __call($name, $args) {
            if ($name === 'getRights') return ['group_id' => $this->groupId];
            throw new \BadMethodCallException($name);
        }
    }
    /** S-02. getRights() keyed by project id rather than flat. */
    class NestedRightsUser {
        public $pid; public $groupId;
        public function __construct($pid, $groupId) { $this->pid = $pid; $this->groupId = $groupId; }
        public function hasDesignRights() { return true; }
        public function getRights($pid = null) { return [$this->pid => ['group_id' => $this->groupId]]; }
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
        public static function getData($p) {
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
        public static function getGroupNames($unique = false, $gid = null) {
            if (self::$groupThrows) throw new \RuntimeException('simulated DAG lookup failure');
            return isset(self::$groupNames[$gid]) ? self::$groupNames[$gid] : '';
        }
        public static function getInstrumentEventMappings($pid = null) { return null; }
        public static function getEventNames($u = false, $x = false, $evt = null) { return 'event_' . $evt . '_arm_1'; }
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
        \REDCap::$dictionary = $dict;
        \REDCap::$data = $data;
        // Every per-scenario switch is reset HERE and set from $opts, never by the
        // caller before the call: an earlier version let a scenario set
        // \REDCap::$groupThrows and then had render() clear it, so the throw never
        // fired and the check passed for the wrong reason.
        \REDCap::$groupThrows  = !empty($opts['groupThrows']);
        \REDCap::$dropFromChunk = isset($opts['dropFromChunk']) ? $opts['dropFromChunk'] : null;
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
        $hdr = is_file($side) ? json_decode((string) file_get_contents($side), true) : null;
        @unlink($side);
        return [$out, $hdr];
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
                @file_put_contents($hdrOut, json_encode($GLOBALS['uv_headers']));
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
        list($out, $hdr) = csvChild('chrome');
        check('S-04: the CSV scenario actually ran in a child process', $out !== null);
        check('S-04: the CSV carries the column header', strpos((string) $out, CSV_HEADER) !== false);
        check('S-04: and none of REDCap\'s page chrome',
            strpos((string) $out, '<!DOCTYPE') === false && strpos((string) $out, '<html') === false);
        check('S-04: the file BEGINS with the report, not with markup',
            strncmp(ltrim((string) $out), CSV_HEADER, strlen(CSV_HEADER)) === 0
            || strncmp(ltrim((string) $out), CSV_BANNER, strlen(CSV_BANNER)) === 0);
        check('S-04: and it contains the violation row',
            strpos((string) $out, '"violation","1"') !== false);

        // The headers themselves. This is what the header() shim was recording
        // and what nothing was reading: the whole defect was headers sent AFTER
        // output had begun, so "buffered === 0 when it fired" is the property.
        $names = [];
        foreach ((array) $hdr as $h) $names[] = $h['h'];
        check('S-04: the CSV content-type header was actually sent',
            (bool) preg_grep('~^Content-Type: text/csv~', $names));
        check('S-04: with a filename attachment header',
            (bool) preg_grep('~^Content-Disposition: attachment~', $names));
        check('S-04: and both fired with NOTHING already buffered — the actual bug',
            $hdr && count($hdr) >= 2
            && (int) $hdr[0]['buffered'] === 0 && (int) $hdr[0]['level'] === 0);

        // The formula defusing must survive the rewrite to streamed writes.
        list($out, $hdr) = csvChild('formula');
        check('S-04: a record id that opens with = is still defused',
            strpos((string) $out, '"\'=cmd"') !== false);
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

        // The reader's own export rights cap the project's choice. Design rights
        // are independent of export rights in REDCap, and the scan reads through
        // getData() with no user, so nothing else would stop this.
        $noExport = new \ExternalModules\PlainUser(true, null);
        $noExport->export = '0';
        list($out6, ) = $exp($noExport, $D, $data, $RAW);
        check('export: a reader with NO export rights never sees a value, whatever the project set',
            strpos($out6, '"nope"') === false && strpos($out6, '[withheld by policy]') !== false);

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
        list($html, ) = render(new \ExternalModules\PlainUser(true, null), $D, $data, ['run' => '1'],
            ['settings' => ['scan-value-storage' => 'raw']]);

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
        // The escaping still applies to every generated cell.
        $D2 = dict(['record_id' => ['fa'], 'want' => ['fa'],
                    'code' => ['fa', '@UVASSERT={"assert":"[code]=[want]"}']]);
        $data2 = [1 => [1 => ['record_id' => '1', 'code' => '<img src=x>', 'want' => 'yes']]];
        list($html2, ) = render(new \ExternalModules\PlainUser(true, null), $D2, $data2, ['run' => '1'],
            ['settings' => ['scan-value-storage' => 'raw']]);
        check('columns: a value containing markup is escaped, not rendered',
            strpos($html2, '<img src=x>') === false && strpos($html2, '&lt;img') !== false);
    }

    echo "scan_page_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
