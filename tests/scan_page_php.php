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
        /** The legacy one-request scan (tests/support/scan_facade.php, QUA-3). */
        public function scanProject(...$a) { return \INSPIRE\UniversalValidator\scan_project($this, ...$a); }
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
        public $systemSettings = [];
        public function getSystemSetting($k) {
            return isset($this->systemSettings[$k]) ? $this->systemSettings[$k] : null;
        }
        public function setSystemSetting($k, $v) { $this->systemSettings[$k] = $v; }

        /**
         * The framework's JavaScript transport, and the ability to NOT have one.
         *
         * Absent from this stub until the first live pilot, which is exactly why
         * the panel shipped with a Start button that threw: the page printed the
         * object NAME without ever emitting the bootstrap that creates it, and
         * no test rendered that branch to notice.
         */
        public $jsmoAvailable = true;
        public $jsmoName = 'ExternalModules.INSPIRE.UniversalValidator';
        public $jsmoThrows = false;
        public function initializeJavascriptModuleObject() {
            if ($this->jsmoThrows) throw new \RuntimeException('transport failed');
            if (!$this->jsmoAvailable) return null;
            return '<script>/*JSMO-BOOT*/</script>';
        }
        public function getJavascriptModuleObjectName() {
            return $this->jsmoAvailable ? $this->jsmoName : '';
        }
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
        /** Whether the durable scan's ten tables exist on this installation. */
        public $tablesInstalled = true;
        /**
         * A run in progress, and the row the page reads to describe it.
         *
         * Absent until the panel learned to render its state before any script
         * runs: with no run to find, activeRun() answered null in every scenario
         * and the whole pre-render branch - including the noscript sentence that
         * used to say "Nothing has been run" over a live scan - was unreachable
         * from a test.
         */
        public $activeRunId = null;
        /**
         * The scope stamped on that run, and the project's real group list.
         *
         * M3 made activeRun() read scope_dag beside run_id and decide whether
         * THIS caller may touch it, so a fixture that answers one column tests
         * the null-scope branch and nothing else. $projectGroups is what
         * reapUnworkableScopes() validates against; an empty list means "this
         * project has no groups", which is a real answer and retires a scoped
         * run, so cases that must NOT reap have to populate it.
         */
        public $activeRunScope = null;
        public $projectGroups = [];
        public $runRow = null;
        public $recordStates = [];
        /**
         * When the run's worker lease runs out. Not a run column, so status()
         * asks for it on its own - and a quiet run is only STALLED while a
         * worker holds it (review CL-2). Null is no lease: a paused run.
         */
        public $leaseExpires = null;
        public function query($sql, $params = []) {
            if (!$this->fenced) return null;
            if (strpos($sql, 'SELECT lease_expires_at FROM') !== false) {
                return new \ExternalModules\FakeRes($this->runRow === null ? [] : [[$this->leaseExpires]]);
            }
            if (strpos($sql, 'uv_scan_run') !== false) {
                if (strpos($sql, 'active_slot = 1') !== false) {
                    return new \ExternalModules\FakeRes($this->activeRunId === null
                        ? [] : [[(string) $this->activeRunId, $this->activeRunScope]]);
                }
                if (strpos($sql, 'WHERE run_id = ?') !== false) {
                    return new \ExternalModules\FakeRes($this->runRow === null ? [] : [$this->runRow]);
                }
            }
            if (strpos($sql, 'uv_scan_record') !== false && strpos($sql, 'GROUP BY state') !== false) {
                return new \ExternalModules\FakeRes($this->recordStates);
            }
            if (strpos($sql, 'redcap_data_access_groups') !== false) {
                $g = [];
                foreach ($this->projectGroups as $id) $g[] = [(string) $id];
                return new \ExternalModules\FakeRes($g);
            }
            if (strpos($sql, 'SHOW TABLES') !== false)      return new \ExternalModules\FakeRes([['redcap_record_list']]);
            if (strpos($sql, 'log_event_table') !== false)  return new \ExternalModules\FakeRes([['redcap_log_event7']]);
            if (strpos($sql, 'MAX(log_event_id)') !== false) return new \ExternalModules\FakeRes([['918273', '4412']]);
            if (strpos($sql, 'SHOW GRANTS') !== false)      return new \ExternalModules\FakeRes([['GRANT ALL PRIVILEGES ON `rc`.* TO `u`@`h`']]);
            if (strpos($sql, 'FROM redcap_record_list') !== false) return new \ExternalModules\FakeRes([['1']]);
            if (strpos($sql, 'FROM redcap_data') !== false) return new \ExternalModules\FakeRes([['1']]);
            // The durable scan's own schema. `tablesInstalled = false` is a real
            // installation state - the migration has not been run - and the page
            // must refuse over it rather than offer a panel nothing can drive.
            if (strpos($sql, 'MAX(version)') !== false) {
                return new \ExternalModules\FakeRes([[$this->tablesInstalled
                    ? (string) \INSPIRE\UniversalValidator\Scan\Schema::VERSION : null]]);
            }
            // Schema::health() asks for every table in one read (M11) and is
            // answered with the names that exist; a capability probe still
            // asks COUNT(*) of one.
            if (strpos($sql, 'SELECT table_name FROM information_schema.tables') === 0) {
                $rows = [];
                if ($this->tablesInstalled) foreach ($params as $t) $rows[] = [$t];
                return new \ExternalModules\FakeRes($rows);
            }
            if (strpos($sql, 'information_schema.tables') !== false) {
                return new \ExternalModules\FakeRes([[$this->tablesInstalled ? '1' : '0']]);
            }
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
            // 'design' is a COLUMN in redcap_user_rights and the durable scan's
            // own gate reads it (ScanAuthorization::readable). This mock did not
            // carry it, so every status() in this file refused with "you need
            // project design rights" - which no assertion noticed, because until
            // the panel rendered its state before any script runs, nothing here
            // had ever read a status at all.
            $r = ['design' => ($this->design ? '1' : '0'), 'group_id' => $this->groupId,
                  'data_export_tool' => $this->export];
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
        $GLOBALS['uv_headers'][] = ['h' => $h, 'buffered' => ob_get_length(), 'level' => ob_get_level(),
                                    'code' => $code];
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
    require_once __DIR__ . '/support/scan_facade.php';
    require_once __DIR__ . '/scan_run_row.php';

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
        $m->systemSettings = isset($opts['system']) ? $opts['system'] : [];
        $m->tablesInstalled = !isset($opts['tables']) || $opts['tables'];
        $m->jsmoAvailable = !isset($opts['jsmo']) || $opts['jsmo'];
        $m->jsmoThrows = !empty($opts['jsmoThrows']);
        if (isset($opts['jsmoName'])) $m->jsmoName = $opts['jsmoName'];
        $m->activeRunId = isset($opts['activeRun']) ? $opts['activeRun'] : null;
        $m->activeRunScope = isset($opts['activeScope']) ? $opts['activeScope'] : null;
        $m->projectGroups = isset($opts['groups']) ? $opts['groups'] : [];
        $m->runRow = isset($opts['runRow']) ? $opts['runRow'] : null;
        $m->recordStates = isset($opts['recordStates']) ? $opts['recordStates'] : [];
        $m->leaseExpires = isset($opts['lease']) ? $opts['lease'] : null;
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
        // Both paths take 'dag', the numeric group id. scanProject() compares
        // the exported redcap_data_access_group NAME, and resolves the id to
        // that name itself through ScanPageView::dagNameOf().
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
            $data = [1 => [1 => ['record_id' => '1', 'val' => '', 'fa_complete' => '0']]];
            ob_start(); echo CHROME;
            render(new \ExternalModules\PlainUser(true, null), $D, $data, ['csv' => '1']);
        } elseif ($caseArg === 'formula') {
            // The formula-injection defusing must survive the rewrite to streaming.
            $data = ['=cmd' => [1 => ['record_id' => '=cmd', 'val' => '', 'fa_complete' => '0']]];
            render(new \ExternalModules\PlainUser(true, null), $D, $data, ['csv' => '1']);
        } elseif ($caseArg === 'export') {
            // TASK 1, then B5. pages/export.php streams a STORED run and reads no
            // record; a request that names no run is refused before anything is
            // read. It runs in a child because it sets headers and its own
            // status code.
            $data = [1 => [1 => ['record_id' => '1', 'val' => '', 'fa_complete' => '0']]];
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
            1 => [1 => ['record_id' => '1', 'val' => '', 'fa_complete' => '0', 'redcap_data_access_group' => 'north']],
            2 => [1 => ['record_id' => '2', 'val' => '', 'fa_complete' => '0', 'redcap_data_access_group' => 'south']],
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

        // C-1 SEAM. The scope value is compared downstream against three things
        // that all speak the NUMERIC group id: redcap_record_list.dag_id (via
        // ScanPlanner::stream and RecordManifestSource::scopeOf) and
        // $rights['group_id'] (via ScanAuthorization::scopeMatches). It used to
        // be the unique name, so every one of those comparisons failed: a
        // group-scoped run froze an empty manifest and called the project clean,
        // and its own creator was refused every follow-up call on it.
        list(, , $scopeC) = scanOf(new \ExternalModules\PlainUser(true, 7), $D, $data);
        check('C-1: the scope travels as the numeric group id',
            $scopeC['dag'] === '7');
        check('C-1: the group name is carried separately for display',
            $scopeC['dagName'] === 'north');
        check('C-1: the scope matches what the authorisation layer compares',
            (string) $scopeC['dag'] === (string) $scopeC['rights']['group_id']);

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
        $data = [1 => [1 => ['record_id' => '1', 'val' => '', 'fa_complete' => '0']]];

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
        $data = [1 => [1 => ['record_id' => '1', 'val' => '', 'fa_complete' => '0', 'redcap_data_access_group' => 'north']]];
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

        /* -----------------------------------------------------------------
         * B3  ONE DAG AXIS: the value the page PRODUCES is the value every
         *     consumer COMPARES
         *
         * The page resolved $rights['group_id'] to the friendly DAG name and
         * returned that as the scope. It is stored verbatim as
         * uv_scan_run.scope_dag and then compared, unchanged, against
         * redcap_record_list.dag_id and against $rights['group_id'] - three
         * id-shaped values. A group-scoped run therefore listed every record,
         * appended none, froze an empty manifest, and promoted it to
         * coverage=complete-through-fence clean=true; and it refused the
         * designer who started it scan-work, scan-status and scan-cancel on
         * their own run, which then held the project's only slot.
         *
         * THE VALUES BELOW ARE OBTAINED, NEVER CONSTRUCTED. Each check takes
         * the scope from the production producer and hands it to a production
         * consumer, so the assertion is about a join rather than about a
         * literal agreeing with itself - which is what 1,228 green checks did
         * while the seam was open, because no suite ever held both sides.
         * ----------------------------------------------------------------- */
        list($mG, $resG, $scG) = scanOf(new \ExternalModules\PlainUser(true, 7), $D, $data);
        check('B3 GATE 1a: the scope the page produces is the group ID, byte-identical to '
            . 'what every consumer compares',
            $scG['dag'] === '7' && $scG['dag'] === (string) $scG['rights']['group_id']);
        check('B3 GATE 1b: and the designer who started the run may work their own run',
            \INSPIRE\UniversalValidator\Scan\ScanAuthorization::mayWork(
                $scG['rights'], ['fa'], $scG['dag'])['ok'] === true);
        check('B3 GATE 1c: the friendly name travels beside the id, never instead of it',
            $scG['dagName'] === 'north' && $scG['dag'] !== $scG['dagName']);
        check('B3 GATE 1d: and the id round-trips back to that name through the production '
            . 'resolver, which is what the legacy scan compares on',
            \INSPIRE\UniversalValidator\ScanPageView::dagNameOf($scG['dag']) === $scG['dagName']
            && $resG['stats']['manifest'] === 1);
    }

    /* =====================================================================
     * the four verdict branches
     *
     * ONE PREDICATE, the production one. These called ScanPageView::verdict(),
     * a second implementation of "may this be called clean" that nothing in the
     * shipped tree used and that did not agree with Scan\ScanOutcome. It is
     * gone; what is asked here is what a legacy scanProject() result earns from
     * the predicate every other surface uses.
     * ===================================================================== */
    /** A scanProject() result, as the facts ScanOutcome::derive() decides on. */
    $cleanOf = function ($res) {
        $has = function ($k) use ($res) { return is_array($res) && isset($res[$k]); };
        $O = '\\INSPIRE\\UniversalValidator\\Scan\\ScanOutcome';
        return $O::mayClaimClean($O::derive([
            'fenced'       => $has('coverage') && $res['coverage'] === $O::FENCED,
            'manifestDone' => $has('status') && $res['status'] === 'complete',
            'violations'   => $has('violations') ? count($res['violations']) : 0,
            'ruleProblems' => $has('unconfigurable') ? count($res['unconfigurable']) : 0,
        ]));
    };
    {
        $V = 'INSPIRE\\UniversalValidator\\ScanPageView';
        $D = dict(['record_id' => ['fa'], 'val' => ['fa', '@UVREQUIRED']]);
        \REDCap::$groupNames = [];
        $U = function () { return new \ExternalModules\PlainUser(true, null); };

        // Clean: a populated required field, no rule problems.
        list(, $rC) = scanOf($U(), $D, [1 => [1 => ['record_id' => '1', 'val' => 'X']]]);
        check('verdict: a clean project is clean', $cleanOf($rC) === true);
        check('verdict: with nothing to list', !$rC['violations']);

        // A violation: not clean, and the finding is carried.
        list(, $rV) = scanOf($U(), $D, [1 => [1 => ['record_id' => '1', 'val' => '', 'fa_complete' => '0']]]);
        check('verdict: a violation is listed', count($rV['violations']) === 1);
        check('verdict: and the project is not clean', $cleanOf($rV) === false);

        // A rule that cannot be evaluated: zero violations, still not clean.
        $DB = dict(['record_id' => ['fa'], 'orphan' => ['fz', '@UVREQUIRED']]);
        list(, $rU) = scanOf($U(), $DB, [1 => [1 => ['record_id' => '1']]],
            ['mappings' => [['event_id' => 1, 'form' => 'fa']]]);
        check('verdict: a project can be violation-free and still not clean',
            !$rU['violations'] ? $cleanOf($rU) === false : true);

        // An incomplete sweep: never clean, whatever it found.
        list(, $rI) = scanOf($U(), $D, [
            1 => [1 => ['record_id' => '1', 'val' => 'X']],
            2 => [1 => ['record_id' => '2', 'val' => 'X']],
        ], ['dropFromChunk' => 2]);
        check('verdict: an incomplete sweep is never clean', $cleanOf($rI) === false);
        check('verdict: and the page class no longer carries a predicate of its own',
            !method_exists($V, 'verdict'));
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
        $data = [1 => [1 => ['record_id' => '1', 'val' => '', 'fa_complete' => '0']]];
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
        $plain = null;
        foreach ((array) $hdr as $h) {
            $names[] = $h['h'];
            if (strpos($h['h'], 'Content-Type: text/plain') === 0) $plain = $h;
        }
        check('B5: a request that names no run is refused as plain text with 403, as no run',
            $plain !== null && $plain['code'] === 403
            && strpos((string) $out, \INSPIRE\UniversalValidator\Scan\ScanService::NO_RUN) !== false);
        check('TASK1: a refusal is never offered as a download',
            !preg_grep('~^Content-Disposition~', $names));
        check('B5: and says plainly that no file was produced',
            strpos((string) $out, 'EXPORT REFUSED') !== false
            && strpos((string) $out, 'No file was produced') !== false);
        check('TASK1: it emits no CSV header row of its own',
            strpos((string) $out, CSV_HEADER) === false);
    }

    /* =====================================================================
     * M-02  an incomplete scan may not read as a clean one
     *
     * The predicate began as three local variables inside pages/scan.php, moved
     * to ScanPageView::verdict(), and is now Scan\ScanOutcome alone - so the
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
        check('M-02: an unreadable record makes the scan incomplete', $res['status'] !== 'complete');
        check('M-02: a scan with zero violations but incomplete coverage is NOT clean',
            !$res['violations'] && $cleanOf($res) === false);
        check('M-02: and the reason is recorded, not merely the verdict',
            (bool) $res['incomplete']);

        // CONTRAST: the same project, nothing dropped, IS clean. Without this the
        // three checks above pass on a predicate that never certifies anything.
        list(, $res2) = scanOf(new \ExternalModules\PlainUser(true, null), $D, $data);
        check('M-02 contrast: a complete clean scan is clean', $cleanOf($res2) === true);

        // Two checks used to follow, on hand-built results: that rule problems
        // block clean with no violations, and that a missing coverage key reads
        // as the weaker claim. Both are properties of ScanOutcome::derive() and
        // are asserted on it directly in tests/scan_security_php.php ("a rule
        // problem forbids clean", "a caller that forgets a field gets the WEAKER
        // claim"). Asking them again here, through this file's own adapter,
        // would test the adapter.
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

        // THE BYTES THE DEFUSING USED TO WALK PAST. The leading-byte scan ran
        // on the raw value and stopped at the first byte outside its skip set;
        // a control byte is outside that set, so the cell was judged "not a
        // formula" and got no apostrophe - and then scrub() deleted exactly
        // that byte, promoting the '=' to the emitted cell's first content
        // byte. Every prefix below is drawn from scrub()'s own removal set,
        // which is what made the bypass reliable rather than lucky: the
        // sanitiser was the thing that armed the payload.
        //
        // Asserted on the EMITTED cell rather than on an expected string,
        // because the property is "a spreadsheet cannot read this as a
        // formula", not "the output equals this literal".
        $liveFormula = function ($raw) use ($V) {
            $cell = $V::csv($raw);
            $inner = substr($cell, 1, -1);              // strip the unconditional quotes
            return $inner !== '' && strpos('=+-@', $inner[0]) !== false;
        };
        foreach (["\x01", "\x1A", "\x1B", "\x7F", "\x05", "\x0E", "\x1F"] as $b) {
            check('ScanPageView::csv defuses a formula behind a leading \x'
                . strtoupper(bin2hex($b)),
                !$liveFormula($b . '=1+1'));
        }
        check('ScanPageView::csv defuses the mixed whitespace-and-control prefix',
            !$liveFormula(" \x01 =cmd|'/c calc'"));
        check('ScanPageView::csv defuses a control byte in front of every formula lead',
            !$liveFormula("\x01+1") && !$liveFormula("\x01-1")
            && !$liveFormula("\x01@SUM(1)"));
        // Controls: the fix must not start quoting things that are not formulas.
        check('ScanPageView::csv still leaves a scrubbed non-formula alone',
            $V::csv("\x01plain") === '"plain"');
        check('ScanPageView::csv still leaves an interior = alone after scrubbing',
            $V::csv("a\x01=b") === '"a=b"');
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
            $cleanOf($res) === true);
        check('fence: and the coverage says so in its own words',
            $res['coverage'] === 'complete-through-fence');

        // The same clean project on a server that cannot prove one.
        list(, $res2) = scanOf(new \ExternalModules\PlainUser(true, null), $D, $cleanData,
            ['unfenced' => true]);
        check('fence: without one, the SAME clean project is NOT clean', $cleanOf($res2) === false);
        check('fence: while the sweep itself still reports complete', $res2['status'] === 'complete');
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
        $data = [1 => [7 => ['record_id' => '1', 'val' => '', 'fa_complete' => '0',
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

    /* =====================================================================
     * THE PANEL — the branch no test had ever rendered.
     *
     * Every scenario above renders the UNAVAILABLE page, because both feature
     * switches default off. That left the branch people will actually use with
     * zero coverage, and the first live pilot found it in one click: the page
     * printed the framework's JavaScript object NAME without emitting the
     * bootstrap that creates it, so Start threw "Cannot read properties of
     * undefined" before any request left the browser.
     * ===================================================================== */
    {
        $D = dict(['record_id' => ['fa'], 'val' => ['fa', '@UVREQUIRED']]);
        $data = [1 => [1 => ['record_id' => '1', 'val' => '', 'fa_complete' => '0']]];
        \REDCap::$groupNames = [];
        $U = function () { return new \ExternalModules\PlainUser(true, null); };
        $on = function ($extra = []) {
            return array_merge(['system' => ['scan-system-enable-durable' => '1'],
                                'settings' => ['scan-enable-durable' => '1']], $extra);
        };

        list($html, ) = render($U(), $D, $data, [], $on());
        check('PANEL: with both switches on the page offers a scan',
            strpos($html, 'uv-scan-panel') !== false);
        check('PANEL: and no longer says it is unavailable',
            strpos($html, 'Scan unavailable') === false);
        check('PANEL: with the controls a run needs',
            strpos($html, 'uv-scan-start') !== false
            && strpos($html, 'uv-scan-cancel') !== false
            && strpos($html, 'uv-scan-resume') !== false);
        check('PANEL: and the client that drives them', strpos($html, 'js/scan.js') !== false);

        // THE BOOTSTRAP MUST COME FIRST. The object name exists only once
        // initializeJavascriptModuleObject() has run, so printing the name above
        // it is a panel whose every button throws.
        $boot = strpos($html, 'JSMO-BOOT');
        $use  = strpos($html, 'ExternalModules.INSPIRE.UniversalValidator');
        check('PANEL: the JavaScript transport is bootstrapped', $boot !== false);
        check('PANEL: BEFORE the name only the bootstrap defines',
            $boot !== false && $use !== false && $boot < $use);

        // With no transport there is nothing to drive the panel with, so the
        // page explains rather than offering a control that cannot work.
        list($noJs, ) = render($U(), $D, $data, [], $on(['jsmo' => false]));
        check('PANEL: a build with no transport shows no panel',
            strpos($noJs, 'uv-scan-panel') === false);
        check('PANEL: it says the scan cannot be driven from this page',
            strpos($noJs, 'cannot be driven') !== false);
        check('PANEL: naming the missing piece for whoever has to fix it',
            strpos($noJs, 'transport') !== false);

        list($threw, ) = render($U(), $D, $data, [], $on(['jsmoThrows' => true]));
        check('PANEL: a transport that throws is caught, not fatal',
            strpos($threw, 'uv-scan-panel') === false
            && strpos($threw, 'cannot be driven') !== false);

        // Either switch off is the shipped state, and it must stay refusable.
        list($sysOnly, ) = render($U(), $D, $data, [],
            ['system' => ['scan-system-enable-durable' => '1']]);
        check('PANEL: the system switch alone offers nothing',
            strpos($sysOnly, 'uv-scan-panel') === false);
        list($prjOnly, ) = render($U(), $D, $data, [],
            ['settings' => ['scan-enable-durable' => '1']]);
        check('PANEL: nor does the project switch alone',
            strpos($prjOnly, 'uv-scan-panel') === false);

        // Tables missing is the state the pilot actually hit first. The page
        // refuses, and names which part is not ready.
        list($noTbl, ) = render($U(), $D, $data, [], $on(['tables' => false]));
        check('PANEL: an uninstalled schema offers no panel',
            strpos($noTbl, 'uv-scan-panel') === false);
        check('PANEL: and says the tables are the reason',
            strpos($noTbl, 'tables are not ready') !== false);

        /* -- THE ARIA CONTRACT ------------------------------------------------
         *
         * The module holds itself to this for a field's verdict: js/engine.js
         * builds a polite live region and wires aria-describedby, and
         * tests/a11y_dom_js.cjs asserts every part of it. The scan panel had not
         * one ARIA attribute on it - a bare div with an inline width for a
         * progress bar, and four text regions that changed every few seconds
         * with nothing to announce them. This is the one surface in the module
         * that ignored the standard the module advertises, and the reason it
         * could is that nothing in the suite had ever asserted an ARIA contract
         * over anything rendered from PHP.
         */
        check('PANEL: the progress bar says it is a progress bar',
            strpos($html, 'role="progressbar"') !== false);
        check('PANEL: with a range a reader can interpret',
            strpos($html, 'aria-valuemin="0"') !== false
            && strpos($html, 'aria-valuemax="100"') !== false);
        check('PANEL: and a name, because "progressbar" alone says nothing',
            strpos($html, 'aria-label="Validation scan progress"') !== false);
        check('PANEL: the panel is a region labelled by the page heading',
            strpos($html, 'role="region"') !== false
            && strpos($html, 'aria-labelledby="uv-scan-heading"') !== false
            && strpos($html, 'id="uv-scan-heading"') !== false);
        check('PANEL: and says whether it is busy',
            strpos($html, 'aria-busy="false"') !== false);
        // role=status/polite, never alert/assertive: none of these are
        // emergencies, and assertive would interrupt the reader mid-sentence
        // every few seconds. Same choice js/engine.js makes for a verdict.
        $liveRegion = 'role="status" aria-live="polite" aria-atomic="true"';
        check('PANEL: the explanation line is a polite live region',
            strpos($html, '<p id="uv-scan-note" ' . $liveRegion) !== false);
        check('PANEL: so is the completion sentence',
            strpos($html, '<p id="uv-scan-done" ' . $liveRegion) !== false);
        check('PANEL: with one atomic region for the progress a reader cannot see',
            strpos($html, '<span id="uv-scan-announce" ' . $liveRegion) !== false);
        check('PANEL: which is off-screen rather than absent',
            strpos($html, 'clip:rect(0 0 0 0)') !== false);

        // A button with no type inside REDCap's project form defaults to
        // type=submit, and one thrown handler away from preventDefault that is a
        // page navigation in the middle of a scan.
        check('PANEL: no control can submit the project form it sits inside',
            substr_count($html, 'type="button" id="uv-scan-') === 3);

        // N-C1. The client sets .uv-bar-indeterminate for a run with no total
        // yet. The class was defined NOWHERE - this module ships no stylesheet -
        // so the indeterminate bar rendered as a solid, full-width green one:
        // a scan that has examined nothing, looking exactly like a scan that has
        // examined everything. The class the client writes must ship with a rule
        // that defines it.
        // Asserted GENERALLY rather than by name, which is the same shape as the
        // wiring test one layer up: the suite proved the client WRITES a class
        // and nothing proved anybody DEFINES it.
        $clientJs = file_get_contents(__DIR__ . '/../js/scan.js');
        $assigned = [];
        if (preg_match_all('/className\s*=\s*([^;]+);/', $clientJs, $mm)) {
            foreach ($mm[1] as $expr) {
                if (!preg_match_all("/'([^']+)'/", $expr, $lit)) continue;
                foreach ($lit[1] as $l) {
                    foreach (preg_split('/\s+/', trim($l)) as $c) if ($c !== '') $assigned[$c] = true;
                }
            }
        }
        $undefined = [];
        foreach (array_keys($assigned) as $c) {
            if (strpos($html, '.' . $c) === false) $undefined[] = $c;
        }
        check('PANEL: every class the client assigns is defined by a rule the page ships',
            count($assigned) >= 2 && $undefined === []);
        check('PANEL: and stops moving for a reader who asked for less motion',
            strpos($html, 'prefers-reduced-motion') !== false
            && strpos($html, 'animation: none') !== false);

        /* -- THE VOCABULARY, HANDED OVER RATHER THAN DUPLICATED --------------- */
        $labelsAt = strpos($html, 'window.UVScan.labels');
        $attachAt = strpos($html, 'window.UVScan.attach');
        check('PANEL: the page hands the client its phase and coverage sentences',
            $labelsAt !== false);
        check('PANEL: before it attaches, because attach renders immediately',
            $labelsAt !== false && $attachAt !== false && $labelsAt < $attachAt);
        $missingLabel = [];
        foreach (\INSPIRE\UniversalValidator\ScanPageView::phaseLabels() as $k => $v) {
            if (strpos($html, $v) === false) $missingLabel[] = $k;
        }
        foreach (\INSPIRE\UniversalValidator\ScanPageView::coverageSentences() as $k => $v) {
            if (strpos($html, substr($v, 0, 40)) === false) $missingLabel[] = $k;
        }
        check('PANEL: every one of them, so the client needs no copy of its own',
            $missingLabel === []);

        /* -- L2: the transport NAME is validated before it is printed ---------
         *
         * Not an escaping bug and not reachable by a project user: the value
         * comes from the framework, which derives it from the module's installed
         * directory. It is a missing input contract. A name that is not a dotted
         * identifier - a hyphen from a directory called universal_validator-1.9,
         * which IS the External Modules convention - becomes a syntax error
         * inside the <script> block and silently breaks the whole panel with no
         * diagnostic at all.
         */
        foreach (['X;alert(1);//', 'uv-validator.ajaxObj', 'a b', str_repeat('n', 201)] as $bad) {
            list($badHtml, ) = render($U(), $D, $data, [], $on(['jsmoName' => $bad]));
            check('PANEL: a transport name that is not an identifier offers no panel',
                strpos($badHtml, 'uv-scan-panel') === false);
            check('PANEL: and says the scan cannot be driven from this page',
                strpos($badHtml, 'cannot be driven') !== false);
            check('PANEL: and the name itself is never printed',
                strpos($badHtml, $bad) === false);
        }

        /* -- L3: the page renders the state BEFORE any script runs ------------
         *
         * The file has claimed this in its own header comment since it was
         * written, and it was false: every value span was emitted empty, the bar
         * was hardcoded to zero, and the noscript block said "Nothing has been
         * run" while a run was on the server holding the project's slot. The
         * claim is now a test rather than a comment.
         */
        check('PANEL: with no run the page says so, and says it plainly',
            strpos($html, 'Nothing has been run') !== false);

        // BY NAME, through uv_run_row(). This row used to be a bare list in
        // the projection's column order, and it drifted: run_seq went into
        // SqlScanStore::run() and not into here, and every check below failed
        // at once with nothing saying why.
        $LIVE = ['run_id' => '55', 'project_id' => (string) PID, 'phase' => 'scanning',
                 'fingerprint' => 'fp', 'manifest_total' => '10', 'manifest_done' => '3',
                 'cursor_ordinal' => '3', 'lease_epoch' => '1', 'detail_rows' => '2',
                 'detail_bytes' => '400', 'fence_open' => '918273', 'fence_target' => '918273',
                 'progress_at' => gmdate('Y-m-d H:i:s')];
        $ROW = uv_run_row($LIVE);
        list($live, ) = render($U(), $D, $data, [], $on([
            'activeRun' => 55, 'runRow' => $ROW, 'recordStates' => [['100', '3'], ['0', '7']]]));
        check('PANEL: a run in progress is NOT described as nothing having been run',
            strpos($live, 'Nothing has been run') === false);
        check('PANEL: the noscript block says the scan is on the server',
            strpos($live, 'on the server right now') !== false);
        check('PANEL: and that it continues whatever this page can do',
            strpos($live, 'unaffected and continues') !== false);
        check('PANEL: Continue is offered rather than Start',
            strpos($live, 'id="uv-scan-resume" class="btn btn-secondary btn-sm">') !== false);
        check('PANEL: the phase is on the page before any script runs',
            strpos($live, '>Checking records</span>') !== false);
        check('PANEL: so are the counts',
            strpos($live, '3 of 10 records') !== false);
        check('PANEL: and the bar carries the position it is really at',
            strpos($live, 'aria-valuenow="30"') !== false
            && strpos($live, 'width:30%') !== false);
        check('PANEL: and the panel says it is busy',
            strpos($live, 'aria-busy="true"') !== false);

        // P10, through the real page and the real status(): the run row's own
        // storage counters reach the panel before any script runs.
        check('PANEL P10: a run that has stored rows says how many, and how much they hold',
            preg_match('/<div id="uv-scan-stored" style="margin-top[^"]*">Stored: 2 finding rows, '
                     . 'with 400 bytes of values<\/div>/', $live) === 1);
        check('PANEL P10: with no run the line is there for the client, hidden and empty',
            preg_match('/<div id="uv-scan-stored" style="display:none;[^"]*"><\/div>/', $html) === 1);
        list($none, ) = render($U(), $D, $data, [], $on([
            'activeRun' => 55, 'runRow' => uv_run_row(['detail_rows' => '0', 'detail_bytes' => '0'] + $LIVE),
            'recordStates' => [['100', '3'], ['0', '7']]]));
        check('PANEL P10: and a run that has stored nothing says nothing about storage',
            preg_match('/<div id="uv-scan-stored" style="display:none;[^"]*"><\/div>/', $none) === 1);

        // L5, through the real page and the real status(): the run row's own
        // progress clock decides whether the watcher is told anything.
        check('PANEL: a run that is moving carries the stalled node hidden and empty',
            preg_match('/<p id="uv-scan-stalled"[^>]*style="display:none;[^"]*"><\/p>/', $live) === 1);
        // A worker holds it - claim() took the lease a second ago and moved
        // updated_at - and nothing has finished for fifteen minutes: the wedge.
        $STALE = uv_run_row(['progress_at' => gmdate('Y-m-d H:i:s', time() - 900),
                             'updated_at' => gmdate('Y-m-d H:i:s', time() - 1)] + $LIVE);
        list($stale, ) = render($U(), $D, $data, [], $on([
            'activeRun' => 55, 'runRow' => $STALE, 'recordStates' => [['100', '3'], ['0', '7']],
            'lease' => gmdate('Y-m-d H:i:s', time() + 299)]));
        check('PANEL: a run a worker holds that has finished nothing for fifteen minutes says so',
            preg_match('/<p id="uv-scan-stalled"[^>]*style="margin-top[^"]*">No progress has been '
                     . 'recorded on this scan for 1[45] minutes\./', $stale) === 1);
        // CL-2. The same quiet run with nobody working on it - the tab that
        // drove it was closed, which this page tells people is safe - is
        // PAUSED, and is told to press Continue rather than to start again.
        $LEFT = uv_run_row(['progress_at' => gmdate('Y-m-d H:i:s', time() - 900),
                            'updated_at' => gmdate('Y-m-d H:i:s', time() - 900)] + $LIVE);
        list($left, ) = render($U(), $D, $data, [], $on([
            'activeRun' => 55, 'runRow' => $LEFT, 'recordStates' => [['100', '3'], ['0', '7']]]));
        check('PANEL: a run nobody is working on is paused, and is told to press Continue',
            preg_match('/<p id="uv-scan-stalled"[^>]*style="margin-top[^"]*">This scan is paused: '
                     . 'nothing has worked on it for 1[45] minutes/', $left) === 1
            && strpos($left, 'Press Continue to carry on') !== false);
        // The node only: the page also prints every label into its script block.
        $stalledP = function ($html) {
            return preg_match('/<p id="uv-scan-stalled"[^>]*>([^<]*)<\/p>/', $html, $m) === 1
                ? html_entity_decode($m[1], ENT_QUOTES) : null;
        };
        check('PANEL: and is NOT told to stop it and start a new one',
            is_string($stalledP($left)) && strpos($stalledP($left), 'start a new one') === false);
        // A RUN THAT WAS STOPPED sits in `cancelling` until a later request
        // finishes it. It was called stalled too.
        $STOPPED = uv_run_row(['phase' => 'cancelling',
            'progress_at' => gmdate('Y-m-d H:i:s', time() - 900),
            'cancel_requested_at' => gmdate('Y-m-d H:i:s', time() - 600)] + $LIVE);
        list($stopped, ) = render($U(), $D, $data, [], $on([
            'activeRun' => 55, 'runRow' => $STOPPED, 'recordStates' => [['100', '3'], ['0', '7']]]));
        check('PANEL: a stopped run says it is stopping and how to finish, never that it stalled',
            $stalledP($stopped) === \INSPIRE\UniversalValidator\ScanPageView::STOPPING);
        // And what it examined is not what merely settled: three done, two that
        // could not be stored, five still waiting.
        list($mixed, ) = render($U(), $D, $data, [], $on([
            'activeRun' => 55, 'runRow' => $ROW,
            'recordStates' => [['100', '3'], ['104', '2'], ['0', '5']]]));
        check('PANEL: records that could not be checked are said on the page, not counted as checked',
            strpos($mixed, '3 of 10 records checked; 2 could not be checked') !== false
            && strpos($mixed, 'aria-valuenow="50"') !== false);

        // A run whose total is not known yet is INDETERMINATE server-side too:
        // no aria-valuenow at all, rather than a zero that announces a stall.
        $PLANNING = uv_run_row(['phase' => 'planning', 'manifest_total' => '0'] + $LIVE);
        list($planning, ) = render($U(), $D, $data, [], $on([
            'activeRun' => 55, 'runRow' => $PLANNING, 'recordStates' => []]));
        check('PANEL: a run with no total yet reports no position at all',
            strpos($planning, 'aria-valuenow') === false);
        check('PANEL: and shows the indeterminate bar rather than an empty one',
            strpos($planning, 'class="uv-bar uv-bar-indeterminate"') !== false);
        check('PANEL: saying it is preparing rather than 0 of 0',
            strpos($planning, '>Preparing</span>') !== false);

        // A run the page cannot read the status of must not invent one. The
        // panel falls back to the empty shape and the client fills it in on its
        // first poll - but the noscript sentence still tells the truth, because
        // the run id is enough to know a scan exists.
        list($opaque, ) = render($U(), $D, $data, [], $on(['activeRun' => 55]));
        check('PANEL: an unreadable status does not become a made-up one',
            strpos($opaque, '3 of 10 records') === false);
        check('PANEL: but the page still says a scan is running',
            strpos($opaque, 'on the server right now') !== false);

        // OC-2, through the real status() and the real page. Two finished runs
        // that differ ONLY in what promotion decided: one certified, one refused
        // `clean` for a rule it could not evaluate. They rendered identically.
        $END = ['phase' => 'terminal', 'terminal' => 'complete', 'coverage' => 'complete-through-fence',
                'manifest_total' => '1', 'manifest_done' => '1', 'fence_target' => '918273'] + $LIVE;
        list($certHtml, ) = render($U(), $D, $data, [], $on([
            'activeRun' => 55, 'runRow' => uv_run_row(['clean' => '1'] + $END),
            'recordStates' => [['100', '1']]]));
        list($ruleHtml, ) = render($U(), $D, $data, [], $on([
            'activeRun' => 55,
            'runRow' => uv_run_row(['clean' => '0', 'rule_problem_count' => '1'] + $END),
            'recordStates' => [['100', '1']]]));
        $doneP = function ($html) {
            return preg_match('/<p id="uv-scan-done"[^>]*>([^<]*)<\/p>/', $html, $m) === 1
                ? html_entity_decode($m[1], ENT_QUOTES) : null;
        };
        check('PANEL: a certified run states what it checked, and no verdict sentence',
            $doneP($certHtml) === \INSPIRE\UniversalValidator\ScanPageView::coverageSentence(
                'complete-through-fence'));
        check('PANEL: the run refused clean for a rule problem says so before any script runs',
            strpos((string) $doneP($ruleHtml),
                   \INSPIRE\UniversalValidator\ScanPageView::RULE_PROBLEM) !== false
            && $doneP($ruleHtml) !== $doneP($certHtml));

        // Rights still decide. The panel is not a way around them.
        list($noRights, ) = render(new \ExternalModules\PlainUser(false, null), $D, $data, [], $on());
        check('PANEL: a user without design rights gets no panel',
            strpos($noRights, 'uv-scan-panel') === false);

        /* -----------------------------------------------------------------
         * M3  a run you may not touch is not the same as no run
         *
         * activeRun() answered a run id or null, and null carried two
         * meanings. The page read it as "no run", rendered Continue, printed
         * the id into the client, and every click came back refused with
         * nothing on the page saying why. While the DAG scope was on the wrong
         * axis that was the designer's OWN run; now that it is right it is
         * another group's, and a page load that emits its id is a page load
         * that enumerates other groups' runs.
         *
         * FOUR STATES, AND THE FOURTH IS THE MIGRATION. A run stamped with a
         * DAG NAME before the axis was fixed can never be worked by anybody
         * and holds the project's one slot with no reaper. It is retired at
         * exactly the moment somebody who cannot touch it looks at it.
         * ----------------------------------------------------------------- */
        \REDCap::$groupNames = [7 => 'north', 31 => 'south'];
        $DAG = function () { return new \ExternalModules\PlainUser(true, 7); };
        $act = function ($m) {
            $svc = new \INSPIRE\UniversalValidator\Scan\ScanService($m);
            return $svc->activeRun(PID,
                \INSPIRE\UniversalValidator\ScanPageView::scanScope($m, PID));
        };
        $BUSY = \INSPIRE\UniversalValidator\Scan\ScanStore::BUSY_WHY;
        $run = ['activeRun' => 55, 'runRow' => $ROW,
                'recordStates' => [['100', '3'], ['0', '7']], 'groups' => [7, 31]];

        // YOURS. The scope on the run is this user's own group id.
        list($htmlY, $mY) = render($DAG(), $D, $data, [],
            $on(array_merge($run, ['activeScope' => '7'])));
        $aY = $act($mY);
        check('M3: a run scoped to your own group is yours, and you get its id',
            $aY['state'] === 'yours' && $aY['run_id'] === 55 && $aY['why'] === null);

        // OTHER. The same user, a run scoped to the group next door.
        list($htmlO, $mO) = render($DAG(), $D, $data, [],
            $on(array_merge($run, ['activeScope' => '31'])));
        $aO = $act($mO);
        check('M3: another group\'s run is not yours, and its id is withheld',
            $aO['state'] === 'other' && $aO['run_id'] === null);
        // THE SAME SENTENCE THE STORE USES. A distinct one here would be an
        // oracle: it would tell somebody outside the scope that a run exists.
        check('M3: and the reason is the store\'s own busy wording, not a new sentence',
            $aO['why'] === $BUSY);
        check('M3: which discloses no id, no scope and no digit',
            strpos($aO['why'], '31') === false && strpos($aO['why'], '55') === false
            && preg_match('/[0-9]/', $aO['why']) === 0);

        // UNKNOWN. No rights in hand is not "no restriction".
        $svcU = new \INSPIRE\UniversalValidator\Scan\ScanService($mO);
        $aU = $svcU->activeRun(PID, null);
        check('M3: with no rights to compare against, nothing is offered',
            $aU['state'] === 'unknown' && $aU['run_id'] === null);

        // A SCOPE NOBODY CAN MATCH IS STILL 'other' HERE, AND THAT IS THE
        // POINT. scope_dag holding a DAG NAME is what every run started before
        // the axis fix carries; no group id can equal it, so this caller is
        // refused exactly as they are refused another group's run. The
        // RETIREMENT that unwedges it is proved in tests/mysql/cases/dag.php
        // and not here: it calls store()->finish(), which this fixture's db
        // cannot serve - it answers null to the UPDATE, the store raises
        // ScanStoreUnavailable, and the reaper swallows it. A control asserting
        // "nothing was retired" against a store that can retire nothing would
        // be green for a reason that has nothing to do with the code.
        list(, $mM) = render($DAG(), $D, $data, [],
            $on(array_merge($run, ['activeScope' => 'north'])));
        $aM = $act($mM);
        check('M3: a run scoped to a value no group id can equal is refused, not offered',
            $aM['state'] === 'other' && $aM['run_id'] === null && $aM['why'] === $BUSY);

        // THE PAGE, not just the service. This is where the defect was visible.
        check('M3 page: another group\'s run id never reaches the client',
            strpos($htmlO, 'runId: 55') === false && strpos($htmlO, 'runId: null') !== false);
        check('M3 page: and the reader is told the project is busy before they click',
            strpos($htmlO, \INSPIRE\UniversalValidator\ScanPageView::h($BUSY)) !== false);
        check('M3 page: the noscript block no longer says nothing has been run',
            strpos($htmlO, 'Nothing has been run') === false);
        check('M3 page contrast: your own run still reaches the client',
            strpos($htmlY, 'runId: 55') !== false);
        \REDCap::$groupNames = [];
    }

    /* =====================================================================
     * THE VOCABULARY — one table, and the guard that keeps it complete.
     *
     * The phase labels and the coverage sentences were literals inside
     * js/scan.js AND decided again in PHP, so the two halves of one page each
     * held half a vocabulary with no way of learning about the other. They live
     * here now and the client is handed them. What follows is the part that
     * matters for every wave after this one: a phase or a coverage value that
     * arrives without a sentence FAILS HERE, rather than shipping as a blank
     * paragraph on somebody's finished scan.
     * ===================================================================== */
    {
        $V = '\INSPIRE\UniversalValidator\ScanPageView';

        $phases = (new \ReflectionClass('\INSPIRE\UniversalValidator\Scan\ScanPhase'))->getConstants();
        $labelled = $V::phaseLabels();
        $missing = [];
        foreach ($phases as $name => $val) {
            if (is_string($val) && !isset($labelled[$val])) $missing[] = $name;
        }
        check('LABELS: every phase the state machine can reach has a sentence',
            $missing === []);
        check('LABELS: and none of them is the stored name',
            !in_array('rollup-finalize', array_values($labelled), true));

        $cov = $V::coverageSentences();
        $missingCov = [];
        foreach (['FENCED', 'MANIFEST', 'EMPTY_SCOPE', 'COV_PARTIAL', 'COV_FAILED'] as $c) {
            $val = constant('\INSPIRE\UniversalValidator\Scan\ScanOutcome::' . $c);
            if (!isset($cov[$val])) $missingCov[] = $c;
        }
        check('LABELS: every coverage a run can be promoted to has a sentence',
            $missingCov === []);
        check('LABELS: and each of them says something different',
            count(array_unique(array_values($cov))) === count($cov));

        // A finished run whose result this build does not recognise. Saying
        // NOTHING is the outcome the whole rebuild exists to prevent - a run
        // that examined nothing must not look like a run that examined
        // everything, and a blank certificate looks like both.
        $unknown = $V::coverageSentence('some-future-value');
        check('LABELS: an unrecognised coverage is never a blank certificate',
            trim($unknown) !== '');
        check('LABELS: it names the value, so somebody can find out what it meant',
            strpos($unknown, 'some-future-value') !== false);
        check('LABELS: and refuses to be read as complete',
            strpos($unknown, 'incomplete') !== false);
        $unknownTrunc = $V::coverageSentence('some-future-value', 'truncated');
        check('LABELS: the truncation note never becomes a headless sentence',
            $unknownTrunc[0] !== ' ' && strpos($unknownTrunc, 'not kept') !== false);
        // THE LIST ABOVE IS HAND-MAINTAINED AND IT ALREADY LAGGED ONCE. This
        // asks the PRODUCER instead: every coverage derive() can actually
        // return must be one this page can word, or a finished run renders the
        // unknown-value fallback where its own sentence should be.
        $produced = [];
        foreach ([['failed' => true], ['cancelled' => true], ['expired' => true],
                  ['emptyScope' => true], ['blocked' => true], [],
                  ['fenced' => true, 'manifestDone' => false],
                  ['fenced' => true, 'manifestDone' => true, 'truncated' => true],
                  ['fenced' => true, 'manifestDone' => true]] as $facts) {
            $produced[\INSPIRE\UniversalValidator\Scan\ScanOutcome::derive($facts)['coverage']] = true;
        }
        check('LABELS: every coverage derive() can produce has a sentence on this page',
            array_keys(array_diff_key($produced, $cov)) === []);
        // The sentence must not be misreadable as the fenced certificate, and
        // it must survive the page's own encoding: pages/scan.php prints this
        // table with JSON_HEX_APOS, and the render check in this file searches
        // the HTML for each sentence's literal opening. An apostrophe here
        // would break that silently.
        $esSentence = $V::coverageSentence(
            \INSPIRE\UniversalValidator\Scan\ScanOutcome::EMPTY_SCOPE);
        check('LABELS: the empty-scope sentence never claims a record was checked',
            stripos($esSentence, 'Every record was checked') === false);
        // R2O-1. NOR THAT NONE WAS. It opened "Nothing was checked", which is
        // false of a run whose records were checked and then deleted - the
        // commonest way in, and the one whose panel also counts findings.
        // What it may say is what is true of every way in: no record is left,
        // deleted checked or not, and the findings are on deleted records.
        check('LABELS: R2O-1 the empty-scope sentence does not say nothing was checked',
            stripos($esSentence, 'nothing was checked') === false
            && stripos($esSentence, 'before it could be checked') === false);
        check('LABELS: R2O-1 it says no record is left, deleted whether or not it had been checked',
            strpos($esSentence, 'no record left') !== false
            && strpos($esSentence, 'whether or not it had been checked') !== false);
        check('LABELS: R2O-1 it accounts for any findings, and still says nothing about the project',
            strpos($esSentence, 'Any findings it counted are on deleted records') !== false
            && strpos($esSentence, 'says nothing about the project') !== false);
        // Both shapes, as the panel renders them: findings counted by an
        // examination that came before the deletions, and none at all.
        $esStatus = function ($findings) {
            return ['ok' => true, 'phase' => 'terminal', 'terminal' => 'partial',
                'coverage' => \INSPIRE\UniversalValidator\Scan\ScanOutcome::EMPTY_SCOPE,
                'detail' => 'complete', 'total' => 2, 'done' => 0, 'settled' => 2, 'removed' => 2,
                'excluded' => 0, 'findings' => $findings, 'clean' => 0, 'ruleProblems' => 0,
                'active' => false];
        };
        $esChecked = $V::panelPrefill($esStatus(2));
        $esNever = $V::panelPrefill($esStatus(0));
        check('PREFILL: R2O-1 records checked then deleted keep their count, beside a sentence that allows it',
            $esChecked['found'] === '2 findings so far'
            && stripos($esChecked['done'], 'nothing was checked') === false
            && strpos($esChecked['done'], $esSentence) === 0
            && substr($esChecked['done'], -strlen($V::NOT_CERTIFIED)) === $V::NOT_CERTIFIED);
        check('PREFILL: R2O-1 and records deleted before any check read the same, true sentence',
            $esNever['found'] === 'Nothing found yet' && $esNever['done'] === $esChecked['done']);
        check('LABELS: and it survives json_encode into the page unaltered',
            strpos(json_encode($esSentence,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), '\\u') === false);
        check('LABELS: and it is stated even over a complete coverage',
            strpos($V::coverageSentence('complete-through-fence', 'truncated'), 'not kept') !== false);
        check('LABELS: an unrecognised phase falls back to its stored name',
            $V::phaseLabel('some-future-phase') === 'some-future-phase');

        // Null is not zero. A bar at 0% for the length of a planning phase reads
        // as a scan that has stalled, and people stop scans that look stalled.
        check('LABELS: an unknown total has no percentage, rather than a zero one',
            $V::pct(0, 0) === null && $V::pct(3, 0) === null);
        check('LABELS: a known one is floored, never rounded up past the truth',
            $V::pct(1, 3) === 33 && $V::pct(9, 10) === 90);
        check('LABELS: and it cannot exceed a hundred', $V::pct(11, 10) === 100);

        $mid = $V::panelPrefill(['ok' => true, 'phase' => 'scanning', 'terminal' => null,
            'coverage' => 'partial', 'detail' => 'complete', 'total' => 10, 'done' => 3,
            'findings' => 1, 'active' => true]);
        check('PREFILL: a running scan is described in words before any script runs',
            $mid['phase'] === 'Checking records' && strpos($mid['counts'], '3 of 10') === 0);
        check('PREFILL: with its findings so far, in the singular when there is one',
            $mid['found'] === '1 finding so far');
        check('PREFILL: and no completion sentence, because it has not completed',
            $mid['done'] === null && $mid['pct'] === 30 && $mid['active'] === true);

        $prep = $V::panelPrefill(['ok' => true, 'phase' => 'planning', 'terminal' => null,
            'total' => 0, 'done' => 0, 'findings' => 0, 'active' => true]);
        check('PREFILL: a run with no total says it is preparing, not 0 of 0',
            $prep['counts'] === 'Preparing' && $prep['pct'] === null);

        // H10 part 1: a group-scoped run that has not proved its scope gives
        // its status without counts. The panel says why in their place, shows
        // no findings line rather than "not counted, because the scan ended",
        // and draws the bar indeterminate.
        $AZ = '\INSPIRE\UniversalValidator\Scan\ScanAuthorization';
        $held = $V::panelPrefill($AZ::preFenceStatus(['ok' => true, 'phase' => 'scanning',
            'terminal' => null, 'total' => 10, 'done' => 3, 'settled' => 3, 'findings' => 2,
            'detailRows' => 2, 'detailBytes' => 40, 'active' => true]));
        check('PREFILL: H10 a run that has not proved its group scope says so in place of its counts',
            $held['counts'] === $AZ::WITHHELD && $held['found'] === '' && $held['pct'] === null
            && $held['stored'] === null && $held['phase'] === 'Checking records' && $held['active'] === true);
        check('PREFILL: H10 and a withheld status with no sentence still gets one, never a count',
            $V::countsText(['ok' => true, 'withheld' => true, 'total' => 10, 'done' => 3]) === $AZ::WITHHELD);

        // review rv5b-2 / rv5b-6. A withheld run that has ENDED is not
        // promised its counts; a stopped or expired one is not said to have
        // left records unchecked; and none says whether findings passed the
        // detail budget.
        $endedOf = function ($terminal, $coverage) use ($AZ) {
            return $AZ::preFenceStatus(['ok' => true, 'phase' => 'terminal', 'terminal' => $terminal,
                'coverage' => $coverage, 'detail' => 'truncated', 'total' => 5, 'done' => 5,
                'settled' => 5, 'findings' => 3, 'active' => false, 'clean' => false]);
        };
        foreach (['cancelled', 'expired'] as $t) {
            $pfE = $V::panelPrefill($endedOf($t, 'partial'));
            check("PREFILL rv5b-2: a withheld run that was $t is told its counts cannot be shown",
                $pfE['counts'] === $AZ::WITHHELD_ENDED);
            check("PREFILL rv5b-2: and says it was stopped, not that records went unchecked ($t)",
                strpos($pfE['done'], $V::STOPPED_WITHHELD) === 0
                && strpos($pfE['done'], 'could not be checked') === false);
            check("PREFILL rv5b-6: and says nothing of the detail budget ($t)",
                strpos($pfE['done'], $V::DETAIL_TRUNCATED) === false);
        }
        foreach (['partial' => 'partial', 'failed' => 'failed', 'complete' => 'manifest-complete']
                 as $t => $cov) {
            $pfE = $V::panelPrefill($endedOf($t, $cov));
            check("PREFILL rv5b-2: a withheld run that ended $t is not promised its counts either",
                $pfE['counts'] === $AZ::WITHHELD_ENDED);
            check("PREFILL rv5b-2 control: and keeps the sentence its coverage earns ($t)",
                strpos($pfE['done'], $V::coverageSentence($cov)) === 0);
            check("PREFILL rv5b-6: without a word about the detail budget ($t)",
                strpos($pfE['done'], $V::DETAIL_TRUNCATED) === false);
        }
        check('PREFILL rv5b-2 control: a stopped run that is not withheld keeps its coverage sentence',
            $V::endedSentence(['ok' => true, 'terminal' => 'cancelled', 'coverage' => 'partial'])
            === $V::coverageSentence('partial'));
        check('PREFILL rv5b-2: a withheld status with no sentence that has ended is not promised its counts',
            $V::countsText(['ok' => true, 'withheld' => true, 'terminal' => 'cancelled'])
            === $AZ::WITHHELD_ENDED);

        $end = $V::panelPrefill(['ok' => true, 'phase' => 'terminal', 'terminal' => 'complete',
            'coverage' => 'manifest-complete', 'detail' => 'complete', 'total' => 10,
            'done' => 10, 'findings' => 0, 'active' => false]);
        check('PREFILL: a finished run carries the sentence its coverage earns',
            strpos($end['done'], 'cannot prove') !== false);

        // L4. `done` was every record in a terminal state, so a run in which no
        // result could be stored read "12 of 12 records (100%)" over an empty
        // report. The number before "of" is records EXAMINED; the bar follows
        // what is settled, so it still reaches the end.
        $status = function (array $over) {
            return array_merge(['ok' => true, 'phase' => 'scanning', 'terminal' => null,
                'coverage' => 'partial', 'detail' => 'complete', 'total' => 10, 'done' => 3,
                'findings' => 0, 'active' => true], $over);
        };
        check('PREFILL: with nothing excluded the line is byte for byte what it always was',
            $V::panelPrefill($status(['settled' => 3, 'excluded' => 0, 'removed' => 0]))['counts']
                === '3 of 10 records  (30%)'
            && $V::panelPrefill($status([]))['counts'] === '3 of 10 records  (30%)');
        $ex = $V::panelPrefill($status(['done' => 8, 'settled' => 10, 'excluded' => 2, 'removed' => 0]));
        check('PREFILL: records that could not be checked are said, not folded into the total',
            $ex['counts'] === '8 of 10 records checked; 2 could not be checked  (100%)');
        check('PREFILL: and the bar still reaches the end, because the run did', $ex['pct'] === 100);
        check('PREFILL: a record deleted during the scan is its own clause',
            $V::panelPrefill($status(['done' => 7, 'settled' => 10, 'excluded' => 2, 'removed' => 1]))
                ['counts'] === '7 of 10 records checked; 2 could not be checked; '
                             . '1 deleted during the scan  (100%)');
        $nothing = $V::panelPrefill($status(['done' => 0, 'settled' => 10, 'excluded' => 10]));
        check('PREFILL: a run that examined NOTHING does not read as one that examined everything',
            strpos($nothing['counts'], '0 of 10 records checked') === 0
            && strpos($nothing['counts'], '10 of 10') === false);

        // L5. Whoever LOADS the page is watching; nothing on it drives a scan
        // until a button is pressed.
        $st = $V::panelPrefill($status(['stalled' => true, 'idleSeconds' => 330]));
        check('PREFILL: a run that has stopped getting anywhere says so before any script runs',
            is_string($st['stalled']) && strpos($st['stalled'], 'for 5 minutes') !== false
            && strpos($st['stalled'], '{') === false);
        check('PREFILL: a run that is moving says nothing of the kind',
            $V::panelPrefill($status(['stalled' => false, 'idleSeconds' => 3]))['stalled'] === null
            && $V::panelPrefill($status([]))['stalled'] === null);
        check('PREFILL: and a finished run is never stalled, whatever the status says',
            $V::panelPrefill($status(['stalled' => true, 'idleSeconds' => 9000, 'active' => false,
                'phase' => 'terminal', 'terminal' => 'partial']))['stalled'] === null);
        check('PREFILL: under two minutes still reads as a minute, never as zero',
            strpos($V::panelPrefill($status(['stalled' => true, 'idleSeconds' => 5]))['stalled'],
                   'for 1 minutes') !== false);
        // CL-2. Paused and stopping are the server's words for a quiet run that
        // is NOT the alarm, and each gets its own sentence.
        $pz = $V::panelPrefill($status(['paused' => true, 'idleSeconds' => 600]))['stalled'];
        check('PREFILL: a paused run is said to be paused, for how long, and to press Continue',
            is_string($pz) && strpos($pz, 'This scan is paused') === 0
            && strpos($pz, 'for 10 minutes') !== false && strpos($pz, 'Press Continue') !== false
            && strpos($pz, 'start a new one') === false);
        check('PREFILL: a run being stopped says so, whatever else it was sent',
            $V::panelPrefill($status(['stopping' => true, 'stalled' => true, 'paused' => true,
                'phase' => 'cancelling', 'idleSeconds' => 9000]))['stalled'] === $V::STOPPING);
        check('PREFILL: a stall outranks a pause when both arrive, because the server chose it',
            strpos($V::panelPrefill($status(['stalled' => true, 'paused' => true,
                'idleSeconds' => 300]))['stalled'], 'No progress has been recorded') === 0);
        check('PREFILL: and a finished run is never paused or stopping either',
            $V::panelPrefill($status(['paused' => true, 'stopping' => true, 'active' => false,
                'phase' => 'terminal', 'terminal' => 'cancelled']))['stalled'] === null);
        // R2O-3, RETIRED BY B10. A planning run that has gone quiet is paused
        // like any other: the list resumes from its cursor, so the panel names
        // Continue, and a stale flag from an older server is not a sentence.
        $ps = $V::panelPrefill($status(['paused' => true, 'phase' => 'planning',
            'total' => 0, 'idleSeconds' => 3 * 3600]))['stalled'];
        check('PREFILL: B10 a quiet planning run is paused, for how long, and told to press Continue',
            is_string($ps) && strpos($ps, 'for 180 minutes') !== false && strpos($ps, 'Press Continue') !== false);
        check('PREFILL: B10 the planning-stopped sentence is gone',
            !defined($V . '::PLANNING_STOPPED')
            && $V::panelPrefill($status(['planningStopped' => true, 'phase' => 'planning',
                'idleSeconds' => 3600]))['stalled'] === null);

        // OC-2. THE STORED DECISION IS SAID. A run refused `clean` because a
        // rule could not be evaluated, with nothing found, rendered byte for
        // byte what a certified run rendered: status() never reported the
        // decision, and the coverage sentence is about checking, not deciding.
        $fin = function (array $over) use ($status) {
            return $status(array_merge(['phase' => 'terminal', 'terminal' => 'complete',
                'coverage' => 'complete-through-fence', 'total' => 1, 'done' => 1, 'settled' => 1,
                'findings' => 0, 'active' => false], $over));
        };
        $certified = $V::panelPrefill($fin(['clean' => 1, 'ruleProblems' => 0]));
        $oneRule = $V::panelPrefill($fin(['clean' => 0, 'ruleProblems' => 1]));
        check('PREFILL: a certified run says what it checked and nothing more',
            $certified['done'] === $V::coverageSentence('complete-through-fence'));
        check('PREFILL: a run with a rule that could not be evaluated does NOT read as the certified one',
            $oneRule['done'] !== $certified['done']
            && $oneRule['done'] === $V::coverageSentence('complete-through-fence') . ' '
                                    . $V::RULE_PROBLEM);
        check('PREFILL: and says it in one plain sentence, counting the rules',
            strpos($V::panelPrefill($fin(['clean' => 0, 'ruleProblems' => 3]))['done'],
                   '3 rules could not be evaluated, so this result does not certify the project.')
                !== false);
        check('PREFILL: a run not certified for any other reason says it is not certified',
            substr($V::panelPrefill($fin(['clean' => 0, 'findings' => 2]))['done'],
                   -strlen($V::NOT_CERTIFIED)) === $V::NOT_CERTIFIED);
        check('PREFILL: so does a run with no decision at all, and a status that never said',
            strpos($V::panelPrefill($fin(['terminal' => 'cancelled', 'clean' => null,
                       'coverage' => 'partial']))['done'], $V::NOT_CERTIFIED) !== false
            && strpos($V::panelPrefill($fin([]))['done'], $V::NOT_CERTIFIED) !== false);
        check('PREFILL: and a run still going owes no verdict yet',
            $V::verdictText($status(['clean' => 0, 'ruleProblems' => 2])) === null);

        // OC-4. A run that ended before its findings could be counted reports
        // NULL, and the line must say so rather than cast it to "Nothing found".
        $ended = $status(['phase' => 'terminal', 'terminal' => 'cancelled', 'active' => false]);
        check('PREFILL: findings that were not counted are said to be not counted, never nothing found',
            $V::panelPrefill(array_merge($ended, ['findings' => null]))['found'] === $V::FOUND_UNKNOWN
            && strpos($V::FOUND_UNKNOWN, 'not counted') !== false);
        $noKey = $ended;
        unset($noKey['findings']);
        check('PREFILL: and so is a status that carries no count at all',
            $V::panelPrefill($noKey)['found'] === $V::FOUND_UNKNOWN);
        check('PREFILL: a counted zero is still nothing found, and a count is still a count',
            $V::panelPrefill(array_merge($ended, ['findings' => 0]))['found'] === 'Nothing found yet'
            && $V::panelPrefill(array_merge($ended, ['findings' => 2]))['found'] === '2 findings so far');
        // @UVJUSTIFY: the findings saved with a reason, said apart once the run is totalled
        check('PREFILL: findings saved with a reason are said apart',
            $V::panelPrefill(array_merge($ended, ['findings' => 3, 'justified' => 2]))['found'] === '3 findings so far, and 2 saved with a reason'
            && $V::panelPrefill(array_merge($ended, ['findings' => 3, 'justified' => '1']))['found'] === '3 findings so far, and 1 saved with a reason');
        check('PREFILL: ... and nothing is said of none, or of a count that is not a whole number',
            $V::panelPrefill(array_merge($ended, ['findings' => 3, 'justified' => 0]))['found'] === '3 findings so far'
            && $V::panelPrefill(array_merge($ended, ['findings' => 3, 'justified' => null]))['found'] === '3 findings so far'
            && $V::panelPrefill(array_merge($ended, ['findings' => 3, 'justified' => '1.5']))['found'] === '3 findings so far');

        // P10: WHAT THE RUN HAS STORED. tests/scan_client_js.cjs asks the
        // client the same table and expects the same strings, digit for digit.
        $sizes = [0 => '0 bytes', 1 => '1 byte', 400 => '400 bytes', 1023 => '1023 bytes',
                  1024 => '1.0 KB', 1075 => '1.0 KB', 1076 => '1.1 KB', 1048575 => '1.0 MB',
                  3565158 => '3.4 MB', 3617587 => '3.4 MB', 3617588 => '3.5 MB', 1073741824 => '1.0 GB',
                  2199023255552 => '2.0 TB', 1125899906842624 => '1,024.0 TB'];
        $badSize = [];
        foreach ($sizes as $b => $want) {
            if ($V::sizeText($b) !== $want) $badSize[] = $b . ' => ' . $V::sizeText($b);
        }
        check('PREFILL P10: sizes read as a person reads them, rounded the way the client rounds',
            $badSize === []);
        $stored = function ($rows, $bytes) use ($V) {
            return $V::panelPrefill(['ok' => true, 'phase' => 'scanning', 'terminal' => null,
                'total' => 10, 'done' => 3, 'findings' => 1, 'active' => true,
                'detailRows' => $rows, 'detailBytes' => $bytes])['stored'];
        };
        check('PREFILL P10: rows and the size of the values kept with them',
            $stored(12345, 3565158) === 'Stored: 12,345 finding rows, with 3.4 MB of values');
        check('PREFILL P10: one row, and no values kept, says only the row',
            $stored(1, 0) === 'Stored: 1 finding row');
        check('PREFILL P10: a run that stored nothing says nothing about storage',
            $stored(0, 0) === null && $stored(null, 500) === null && $stored(-3, 10) === null
            && $stored('12abc', 10) === null);
        check('PREFILL P10: a count that arrives as digits is still a count',
            $stored('7', '2048') === 'Stored: 7 finding rows, with 2.0 KB of values');
        check('PREFILL P10: and an unreadable status says nothing at all',
            $V::panelPrefill(null)['stored'] === null);

        // THE BROWSER HALF RENDERS FROM THESE, so they are the contract between
        // the two: js/scan.js holds a fallback for each and no other copy.
        $L = $V::labels();
        $sentences = ['stalled', 'paused', 'stopping',
                      'plain', 'checked', 'excluded', 'removed',
                      'foundUnknown', 'stored', 'storedValues',
                      'notCertified', 'ruleProblem', 'ruleProblems', 'withheld',
                      'withheldEnded', 'stoppedWithheld'];
        $all = '';
        foreach ($sentences as $k) {
            check("LABELS: the client is handed the '$k' sentence",
                isset($L[$k]) && is_string($L[$k]) && $L[$k] !== '');
            $all .= isset($L[$k]) ? $L[$k] : '';
        }
        check('LABELS: none of them carries an apostrophe into the script block',
            strpos($all, "'") === false);
        $js = file_get_contents(__DIR__ . '/../js/scan.js');
        foreach (['plain', 'checked', 'excluded', 'removed', 'notCertified', 'stored', 'storedValues',
                  'withheld', 'withheldEnded', 'stoppedWithheld'] as $k) {
            check("LABELS: and the client's fallback for '$k' is the page's own wording",
                strpos($js, "L.$k || '" . $L[$k] . "'") !== false);
        }
        // Wrapped onto a second line in the client, so the literal is looked for
        // on its own: still the page's wording, still the only other copy.
        foreach (['foundUnknown', 'ruleProblem', 'ruleProblems'] as $k) {
            check("LABELS: and the client's fallback for '$k' is the page's own wording",
                preg_match('/L\.' . $k . '\s+\|\| \'' . preg_quote($L[$k], '/') . '\'/', $js) === 1);
        }

        // Nothing to describe must produce nothing, never a claim. A refused or
        // unreadable status is exactly the case where inventing a reassuring
        // default is how a page ends up certifying a scan it never saw.
        foreach ([null, [], ['ok' => false, 'why' => 'no'], 'nonsense'] as $bad) {
            $none = $V::panelPrefill($bad);
            check('PREFILL: an unreadable status describes nothing rather than something',
                $none['phase'] === '' && $none['counts'] === '' && $none['found'] === ''
                && $none['pct'] === null && $none['done'] === null && $none['active'] === false
                && $none['stalled'] === null);
        }

        // L2. The transport name crosses into a <script> block, so it is proved
        // to be a dotted identifier rather than escaped - you cannot escape an
        // expression and still have it evaluate.
        foreach (['ExternalModules.INSPIRE.UniversalValidator', '$em', '_a.$b.c9'] as $good) {
            check('LABELS: a dotted identifier is accepted as a transport name',
                $V::isJsIdentifierPath($good) === true);
        }
        foreach (['', 'uv-validator.ajax', 'a b', 'X;alert(1)', '9lives', 'a..b', 'a.',
                  str_repeat('n', 201), null, 42, ['a']] as $bad) {
            check('LABELS: anything that is not one is refused before it is printed',
                $V::isJsIdentifierPath($bad) === false);
        }
    }

    {
        /* -----------------------------------------------------------------
         * SEC-2. A group id of "0", 0 or false is a group to resolve, not
         * "no group". empty() took all three for "no group", so the scope
         * was the whole project. No group has id 0, so the lookup finds no
         * name and the scan is refused.
         * ----------------------------------------------------------------- */
        \REDCap::$groupNames = [7 => 'north', 31 => 'south'];
        \REDCap::$groupThrows = false;
        foreach (['0', 0, false] as $gid) {
            $m = new \INSPIRE\UniversalValidator\UniversalValidator();
            $m->projectIdReturn = PID;
            $m->userReturn = new \ExternalModules\PlainUser(true, $gid);
            $sc = \INSPIRE\UniversalValidator\ScanPageView::scanScope($m, PID);
            check('SEC-2: group id ' . var_export($gid, true) . ' is refused, not read as the whole project',
                $sc['ok'] === false && $sc['dag'] === null && $sc['rights'] === null);
        }
        foreach ([null, ''] as $gid) {
            $m = new \INSPIRE\UniversalValidator\UniversalValidator();
            $m->projectIdReturn = PID;
            $m->userReturn = new \ExternalModules\PlainUser(true, $gid);
            $sc = \INSPIRE\UniversalValidator\ScanPageView::scanScope($m, PID);
            check('SEC-2: group id ' . var_export($gid, true) . ' still means no group',
                $sc['ok'] === true && $sc['dag'] === null);
        }
        \REDCap::$groupNames = [];
    }

    echo "scan_page_php: $n checks, $fail failure(s)
";
    exit($fail ? 1 : 0);
}
