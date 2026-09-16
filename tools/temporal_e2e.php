<?php
/**
 * temporal_e2e.php - drive ScanService::start() then ScanService::work() to a
 * terminal state against a REAL MySQL and the REAL UniversalValidator module.
 *
 * Stands up ExternalModules\AbstractExternalModule over one mysqli connection, a
 * \REDCap stand-in, and the REDCap-side tables the scan reads directly
 * (redcap_projects, redcap_log_event[N], redcap_record_list, redcap_data[N]).
 * The schema is installed through the real administrator path
 * (redcap_module_system_enable), and a FRESH module instance is built for every
 * work() pass because every pass is its own HTTP request in production.
 *
 * Everything is created inside a scratch database (default uv_e2e_scratch) so
 * concurrent probes against uv_test cannot drop the tables mid-run.
 *
 * RUN:
 *   cd <repo root>
 *   UV_DB_HOST=127.0.0.1 UV_DB_PORT=33306 UV_DB_USER=root UV_DB_PASS=root \
 *   UV_DB_NAME=uv_test php -d extension=mysqli tools/temporal_e2e.php
 *
 * ENV KNOBS
 *   UV_E2E_RUNS=2          consecutive scans of the same project (2 reproduces
 *                          the second-run duplicate-key failure)
 *   UV_E2E_N=12            records in the project
 *   UV_E2E_SLEEP=2500000   microseconds per REDCap::getData, to force multi-pass
 *   UV_E2E_ARMS=3          rows per record in redcap_record_list (multi-arm)
 *   UV_E2E_BLANK=1         add a record whose every scanned field is blank
 *   UV_E2E_DATATABLE=redcap_data5   per-project data table (redcap_data absent)
 *   UV_E2E_LOGSHARD=redcap_log_event7  sharded log table
 *   UV_E2E_EMPTYLIST=1     no redcap_record_list row for this project
 *   UV_E2E_PROJ2=1         scan a SECOND project afterwards (cross-project)
 *   UV_E2E_KEEP=1          leave the tables behind for inspection
 *   UV_E2E_SCENARIO=       plain | fpchange | editmid | editviol | delmid
 *                          | readfail | readfail-once | cancelmid
 *
 * THE RUNS THAT MATTER
 *   UV_E2E_RUNS=1 UV_E2E_N=60 UV_E2E_SLEEP=2500000 UV_E2E_SCENARIO=editviol
 *   UV_E2E_RUNS=1 UV_E2E_N=60 UV_E2E_SLEEP=2500000 UV_E2E_SCENARIO=readfail
 *   UV_E2E_RUNS=1 UV_E2E_N=60 UV_E2E_SLEEP=2500000 UV_E2E_SCENARIO=fpchange
 *   UV_E2E_PROJ2=1 UV_E2E_RUNS=1 UV_E2E_N=12
 *   UV_E2E_DATATABLE=redcap_data5 UV_E2E_EMPTYLIST=1 UV_E2E_RUNS=1
 */

namespace {
// ---------------------------------------------------------------------------
// 0. noise
// ---------------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '1');
$GLOBALS['UV_DIAG'] = [];
set_error_handler(function ($no, $str, $file, $line) {
    $GLOBALS['UV_DIAG'][] = "PHP[$no] $str  @ " . basename($file) . ":$line";
    return false;             // let PHP print it too
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "\n*** FATAL: " . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line'] . "\n";
    }
});

$HOST = getenv('UV_DB_HOST') ?: '127.0.0.1';
$USER = getenv('UV_DB_USER') ?: 'root';
$PASS = getenv('UV_DB_PASS'); if ($PASS === false) $PASS = 'root';
$NAME = getenv('UV_DB_NAME') ?: 'uv_test';
$PORT = (int) (getenv('UV_DB_PORT') ?: 33306);
$RUNS = (int) (getenv('UV_E2E_RUNS') ?: 2);
$SOURCE = getenv('UV_E2E_SOURCE') ?: 'list';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$C = new mysqli($HOST, $USER, $PASS, $NAME, $PORT);
$C->set_charset('utf8mb4');
// An isolated scratch schema: other reviewers' probes run against uv_test at the
// same time and drop the same uv_* tables out from under this run.
$SCRATCH = getenv('UV_E2E_DB') ?: 'uv_e2e_scratch';
$C->query('CREATE DATABASE IF NOT EXISTS ' . $SCRATCH);
$C->select_db($SCRATCH);
echo "scratch database: $SCRATCH\n";
echo "server: {$C->server_info}   sql_mode: "
   . $C->query('SELECT @@sql_mode')->fetch_row()[0] . "\n";

const PID = 4242;

// ---------------------------------------------------------------------------
// 1. the framework stand-in
// ---------------------------------------------------------------------------
}

namespace ExternalModules {

    function bindAll($st, array $vals) {
        $refs = [];
        foreach ($vals as $k => $v) $refs[$k] = &$vals[$k];
        array_unshift($refs, str_repeat('s', count($vals)));
        call_user_func_array([$st, 'bind_param'], $refs);
    }

    /**
     * Everything UniversalValidator asks of the framework, backed by one real
     * mysqli connection - the same connection ROW_COUNT() and the transaction
     * statements run on, which is what ModuleDb requires.
     */
    /**
     * What the real External Modules framework hands back: a mysqli_result-like
     * cursor, NOT a PHP array. (Returning an array here makes
     * ScanCapabilities::schemaPrivilege() spin forever - see the report.)
     */
    class FakeResult
    {
        private $rows; private $i = 0;
        public $num_rows;
        public function __construct(array $rows) { $this->rows = $rows; $this->num_rows = count($rows); }
        public function fetch_row() { return isset($this->rows[$this->i]) ? $this->rows[$this->i++] : null; }
        public function fetch_assoc()
        {
            $r = $this->fetch_row();
            return $r === null ? null : $r;
        }
        public function free() { }
        public function toArray() { return $this->rows; }
    }

    class AbstractExternalModule
    {
        /** Set true to reproduce a framework build whose query() returns arrays. */
        public static $returnArrays = false;
        /** @var \mysqli */
        public static $conn;
        public static $sys = [];
        public static $proj = [];
        public $subSettings = [];
        public $projectIdReturn = null;
        public $logCalls = [];
        public static $sqlLog = [];
        public static $sqlErrors = [];

        public function query($sql, $params = [])
        {
            self::$sqlLog[] = $sql;
            try {
                if (!$params) {
                    $r = self::$conn->query($sql);
                    if ($r === true || $r === false) return self::wrap([]);
                    return self::wrap(self::rows($r));
                }
                $st = self::$conn->prepare($sql);
                bindAll($st, array_values($params));
                $st->execute();
                $res = $st->get_result();
                $out = ($res === false) ? [] : self::rows($res);
                $st->close();
                return self::wrap($out);
            } catch (\Throwable $e) {
                self::$sqlErrors[] = substr($e->getMessage(), 0, 300)
                    . '   <<< ' . substr(preg_replace('/\s+/', ' ', $sql), 0, 200);
                throw $e;
            }
        }
        private static function wrap(array $rows)
        {
            return self::$returnArrays ? $rows : new FakeResult($rows);
        }
        private static function rows($r)
        {
            $out = [];
            while ($row = $r->fetch_row()) $out[] = $row;
            $r->free();
            return $out;
        }

        public function getSystemSetting($k) { return isset(self::$sys[$k]) ? self::$sys[$k] : null; }
        public function setSystemSetting($k, $v) { self::$sys[$k] = $v; }
        public function getProjectSetting($k, $pid = null)
        {
            return isset(self::$proj[$k]) ? self::$proj[$k] : null;
        }
        public function getSubSettings($k, $pid = null) { return $this->subSettings; }
        public function getProjectId() { return $this->projectIdReturn; }
        public function getUrl($p) { return '/uv/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return count($this->logCalls); }
        public function initializeJavascriptModuleObject() { return '<script></script>'; }
        public function getJavascriptModuleObjectName() { return 'EM.UV'; }
        public function getUser() { return new User('designer'); }
    }

    /** A designer with design rights, full export rights and no DAG. */
    class User
    {
        private $n;
        public function __construct($n) { $this->n = $n; }
        public function getUsername() { return $this->n; }
        public function hasDesignRights() { return true; }
        public function isSuperUser() { return false; }
        public function getRights($pid = null)
        {
            return [
                'group_id'    => null,
                'design'      => '1',
                'data_export_tool' => '1',       // full export rights
                'forms'       => ['fa' => '1', 'fb' => '1', 'secret_form' => '1'],
                'forms_export' => ['fa' => '1', 'fb' => '1', 'secret_form' => '1'],
            ];
        }
    }
}

// ---------------------------------------------------------------------------
// 2. the REDCap stand-in
// ---------------------------------------------------------------------------
namespace {

    class REDCap
    {
        public static $dictionary = [];
        public static $data = [];
        public static $getDataCalls = 0;
        public static $calls = [];
        public static $readFail = false;
        public static $sleepPerRead = 0;

        public static function getData($p)
        {
            self::$getDataCalls++;
            self::$calls[] = ['pid' => isset($p['project_id']) ? $p['project_id'] : null,
                              'records' => isset($p['records']) ? array_values((array) $p['records']) : null];
            if (self::$sleepPerRead > 0) usleep(self::$sleepPerRead);
            if (self::$readFail) throw new \RuntimeException('simulated export failure');
            $src = self::$data;
            if (!empty($p['records'])) {
                $only = [];
                foreach ($p['records'] as $r) {
                    if (array_key_exists((string) $r, self::$data)) $only[(string) $r] = self::$data[(string) $r];
                }
                $src = $only;
            }
            if (empty($p['fields'])) return $src;
            // REDCap omits a blank field and omits a record with no rows at all.
            $want = array_flip($p['fields']);
            $out = [];
            foreach ($src as $rec => $node) {
                $keep = [];
                foreach ($node as $ev => $row) {
                    if (!is_array($row)) continue;
                    $r = [];
                    foreach ($row as $f => $v) {
                        if (isset($want[$f]) && $v !== '' && $v !== null) $r[$f] = $v;
                    }
                    if ($r) $keep[$ev] = $r;
                }
                if ($keep) $out[$rec] = $keep;
            }
            return $out;
        }
        public static function getDataDictionary($pid, $fmt = 'array') { return self::$dictionary; }
        public static function getRecordIdField($pid = null) { return 'record_id'; }
        public static function getEventNames($u = false, $x = false, $evt = null) { return 'event_1_arm_1'; }
        public static function getInstrumentNames($f = null) { return ['fa' => 'Form A', 'fb' => 'Form B']; }
        public static function getGroupNames($a = false, $b = null) { return ''; }
        public static function getUserRights($pid = null, $u = null)
        {
            return ['designer' => ['forms' => ['fa' => '1', 'fb' => '1', 'secret_form' => '1'],
                                   'data_export_tool' => '1']];
        }
        public static function getInstrumentEventMappings($pid = null)
        {
            return [['event_id' => 1, 'form' => 'fa'], ['event_id' => 1, 'form' => 'fb'],
                    ['event_id' => 1, 'form' => 'secret_form']];
        }
        public static function getRepeatingFormsEvents($pid = null) { return []; }
        public static function isRepeatingForm($e = null, $f = null) { return false; }
    }

    require_once __DIR__ . '/../UniversalValidator.php';

    use INSPIRE\UniversalValidator\Scan\Schema;
    use INSPIRE\UniversalValidator\Scan\ScanService;

    \ExternalModules\AbstractExternalModule::$conn = $C;

    // ---------------------------------------------------------------------
    // 3. REDCap-side fixture tables
    // ---------------------------------------------------------------------
    function q($sql) { global $C; return $C->query($sql); }

    function dropFixtures()
    {
        $extra = array_filter([getenv('UV_E2E_DATATABLE'), getenv('UV_E2E_LOGSHARD')]);
        foreach (array_merge(['redcap_record_list', 'redcap_log_event', 'redcap_data',
                              'redcap_projects'], $extra) as $t) {
            q('DROP TABLE IF EXISTS ' . $t);
        }
    }
    dropFixtures();
    foreach (array_reverse(Schema::tables()) as $t) q('DROP TABLE IF EXISTS ' . $t);

    q('CREATE TABLE redcap_projects (
        project_id INT UNSIGNED NOT NULL PRIMARY KEY,
        log_event_table VARCHAR(64) NOT NULL DEFAULT "redcap_log_event",
        data_table VARCHAR(64) NOT NULL DEFAULT "redcap_data"
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    q('INSERT INTO redcap_projects VALUES (' . PID . ', "redcap_log_event", "redcap_data")');

    // Shape follows REDCap's own: log_event_id is the monotonic fence, pk is the
    // record id, one row per logged action.
    q('CREATE TABLE redcap_log_event (
        log_event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        project_id INT UNSIGNED NOT NULL,
        ts VARCHAR(14) NULL,
        user VARCHAR(255) NULL,
        event VARCHAR(20) NULL,
        object_type VARCHAR(50) NULL,
        pk VARCHAR(255) NULL,
        description TEXT NULL,
        KEY ix (project_id, log_event_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    q('CREATE TABLE redcap_record_list (
        project_id INT UNSIGNED NOT NULL,
        arm INT UNSIGNED NULL,
        record VARCHAR(255) NOT NULL,
        dag_id INT UNSIGNED NULL,
        KEY ix (project_id, record)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    q('CREATE TABLE redcap_data (
        project_id INT UNSIGNED NOT NULL,
        event_id INT UNSIGNED NOT NULL,
        record VARCHAR(255) NOT NULL,
        field_name VARCHAR(255) NOT NULL,
        value TEXT NULL,
        instance INT UNSIGNED NULL,
        KEY ix (project_id, record, field_name)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // ---------------------------------------------------------------------
    // 4. the project: dictionary, records, log
    // ---------------------------------------------------------------------
    \REDCap::$dictionary = [
        'record_id' => ['field_type' => 'text', 'form_name' => 'fa', 'field_annotation' => ''],
        'id_code'   => ['field_type' => 'text', 'form_name' => 'fa',
                        'field_annotation' => '@UVALIDATE={"algorithm":"none","pattern":"FC[0-9]{4}"}'],
        'site'      => ['field_type' => 'text', 'form_name' => 'fa',
                        'field_annotation' => '@UVREQUIRED'],
        'study_id'  => ['field_type' => 'text', 'form_name' => 'fb',
                        'field_annotation' => '@UVUNIQUE'],
    ];

    $N = (int) (getenv('UV_E2E_N') ?: 12);
    $SCEN = getenv('UV_E2E_SCENARIO') ?: 'plain';
    \REDCap::$sleepPerRead = (int) (getenv('UV_E2E_SLEEP') ?: 0);
    $data = [];
    $ins = $C->prepare('INSERT INTO redcap_record_list (project_id, arm, record, dag_id)
                        VALUES (?,1,?,NULL)');
    $insD = $C->prepare('INSERT INTO redcap_data (project_id, event_id, record, field_name, value, instance)
                         VALUES (?,1,?,?,?,1)');
    $insL = $C->prepare('INSERT INTO redcap_log_event (project_id, ts, user, event, object_type, pk, description)
                         VALUES (?,?,?,?,?,?,?)');
    for ($i = 1; $i <= $N; $i++) {
        $rec = (string) $i;
        // every third record breaks the pattern; every fourth leaves site blank;
        // records 2 and 5 share a study_id so @UVUNIQUE has something to find.
        $code = ($i % 3 === 0) ? 'XX99' : sprintf('FC%04d', $i);
        $site = ($i % 4 === 0) ? '' : 'S' . $i;
        $sid  = ($i === 5) ? 'DUP-2' : (($i === 2) ? 'DUP-2' : 'U' . $i);
        $data[$rec] = [1 => ['record_id' => $rec, 'id_code' => $code, 'site' => $site,
                             'study_id' => $sid]];
        $p = PID;
        \ExternalModules\bindAll($ins, [$p, $rec]);  $ins->execute();
        // REDCap's record list carries ONE ROW PER ARM on a multi-arm
        // longitudinal project.
        for ($arm = 2; $arm <= (int) (getenv('UV_E2E_ARMS') ?: 1); $arm++) {
            \ExternalModules\bindAll($ins, [$p, $rec]);  $ins->execute();
        }
        foreach (['record_id' => $rec, 'id_code' => $code, 'site' => $site, 'study_id' => $sid] as $f => $v) {
            \ExternalModules\bindAll($insD, [$p, $rec, $f, $v]); $insD->execute();
        }
        \ExternalModules\bindAll($insL, [$p, date('YmdHis'), 'designer', 'UPDATE', 'redcap_data',
                                         $rec, 'Update record']);
        $insL->execute();
    }
    if (getenv('UV_E2E_BLANK')) {
        // A REAL REDCap behaviour: a record whose every requested field is blank
        // comes back from getData() as NOTHING AT ALL. It exists, it is on the
        // record list, and @UVREQUIRED is exactly the rule that should fire on it.
        $rec = 'BLANK1';
        \ExternalModules\bindAll($ins, [PID, $rec]); $ins->execute();
        \ExternalModules\bindAll($insD, [PID, $rec, 'record_id', $rec]); $insD->execute();
        \ExternalModules\bindAll($insL, [PID, date('YmdHis'), 'designer', 'UPDATE',
            'redcap_data', $rec, 'Create record']); $insL->execute();
        $data[$rec] = [1 => ['record_id' => $rec, 'id_code' => '', 'site' => '',
                             'study_id' => '']];
        $N++;
        echo "fixture: plus record BLANK1 with every scanned field blank\n";
    }
    // A REDCap that shards its data table per project (redcap_projects.data_table)
    // and/or has no record-list cache row for this project.
    if (getenv('UV_E2E_DATATABLE')) {
        $dt = getenv('UV_E2E_DATATABLE');
        $C->query('RENAME TABLE redcap_data TO ' . $dt);
        $C->query('UPDATE redcap_projects SET data_table = "' . $dt . '" WHERE project_id = ' . PID);
        echo "fixture: project data table is $dt (redcap_data does not exist)\n";
    }
    if (getenv('UV_E2E_EMPTYLIST')) {
        $C->query('DELETE FROM redcap_record_list WHERE project_id = ' . PID);
        echo "fixture: redcap_record_list holds no row for this project\n";
    }
    if (getenv('UV_E2E_LOGSHARD')) {
        $sh = getenv('UV_E2E_LOGSHARD');
        $C->query('RENAME TABLE redcap_log_event TO ' . $sh);
        $C->query('UPDATE redcap_projects SET log_event_table = "' . $sh . '" WHERE project_id = ' . PID);
        echo "fixture: project log table is $sh\n";
    }
    \REDCap::$data = $data;
    echo "fixture: $N records, record_list + redcap_data + redcap_log_event populated\n";

    // ---------------------------------------------------------------------
    // 5. the module, installed through the real administrator path
    // ---------------------------------------------------------------------
    $mod = new \INSPIRE\UniversalValidator\UniversalValidator();
    $mod->projectIdReturn = PID;
    \ExternalModules\AbstractExternalModule::$sys = [
        ScanService::SYS_FLAG => '1',
        'log-hmac-key' => str_repeat('k', 64),
        'scan-system-max-concurrent-projects' => 2,
    ];
    \ExternalModules\AbstractExternalModule::$proj = [
        ScanService::PROJ_FLAG => '1',
        'log-values' => '',
        'scan-value-storage' => 'locations',
    ];

    // THE REAL INSTALL PATH: an administrator saving the system configuration.
    $mod->redcap_module_system_enable('1.9.10');
    echo "install: log = ";
    foreach ($mod->logCalls as $l) echo $l[0] . '(' . json_encode($l[1]) . ') ';
    echo "\n";
    $h = Schema::health($mod);
    echo "schema health: " . ($h['ok'] ? 'ok' : 'BROKEN: ' . $h['why']) . "\n";
    $slots = $C->query('SELECT COUNT(*) FROM uv_scan_worker_slot')->fetch_row()[0];
    echo "worker slots provisioned: $slots\n";

    $svc = new ScanService($mod);
    $a = $svc->available(PID);
    echo "available: " . ($a['ok'] ? 'yes' : 'NO - ' . $a['why'] . ' / ' . $a['detail']) . "\n";
    // Second opinion: can the SHIPPED bounded walk actually enumerate this
    // project? If it can while available() says no, the gate and the walk
    // disagree - and the gate is the one that decides.
    {
        $db = new \INSPIRE\UniversalValidator\Scan\ModuleDb($mod);
        $src = \INSPIRE\UniversalValidator\Scan\RecordManifestSource::open($db, PID,
            ['pk' => 'record_id']);
        echo "RecordManifestSource::open -> " . (empty($src['ok']) ? 'NO: ' . $src['why']
            : 'YES via ' . $src['source']->via());
        if (!empty($src['ok'])) {
            $pg = $src['source']->page(null, [], 5);
            echo "  (first page: " . count($pg['rows']) . " ids, ok="
               . var_export($pg['ok'], true) . ")";
        }
        echo "\n";
        $f = \INSPIRE\UniversalValidator\Scan\SourceFence::forProject($db, PID);
        echo "SourceFence::forProject -> " . (empty($f['ok']) ? 'NO: ' . $f['why']
            : 'YES on ' . $f['fence']->table() . ' @ ' . $f['fence']->now()) . "\n";
    }

    // ---------------------------------------------------------------------
    // 6. drive start() + work() to a terminal state
    // ---------------------------------------------------------------------
    function dumpRun($C, $runId)
    {
        $r = $C->query('SELECT phase, terminal, coverage, detail, manifest_total, manifest_done,
            cursor_ordinal, detail_rows, lease_epoch, active_slot, terminal_reason
            FROM uv_scan_run WHERE run_id = ' . (int) $runId)->fetch_assoc();
        return $r;
    }
    function states($C, $runId)
    {
        $out = [];
        $q = $C->query('SELECT state, COUNT(*) FROM uv_scan_record WHERE run_id = ' . (int) $runId
            . ' GROUP BY state');
        while ($row = $q->fetch_row()) $out[$row[0]] = (int) $row[1];
        ksort($out);
        return $out;
    }

    /**
     * Perturbations applied BETWEEN work() passes, so the run meets them the way
     * a real project would - while a worker is part way through it.
     */
    function scenarioHook($scen, $pass, $runId, $C)
    {
        if ($scen === 'readfail') {
            // Every export fails from pass 2 on.
            \REDCap::$readFail = ($pass >= 2);
            if ($pass === 2) echo "  [scenario] REDCap::getData now fails on every call
";
            return;
        }
        if ($scen === 'readfail-once') {
            \REDCap::$readFail = ($pass === 1);
            if ($pass === 1) echo "  [scenario] the FIRST export of this run will fail
";
            return;
        }
        if ($scen === 'fpchange' && $pass === 2) {
            // The designer adds a rule while the scan is running.
            $d = \REDCap::$dictionary;
            $d['id_code']['field_annotation'] =
                '@UVALIDATE={"algorithm":"none","pattern":"ZZ[0-9]{4}"}';
            \REDCap::$dictionary = $d;
            echo "  [scenario] the id_code @UVALIDATE PATTERN was changed mid-run "
               . "(FC[0-9]{4} -> ZZ[0-9]{4})
";
            return;
        }
        if ($scen === 'editmid' && $pass === 2) {
            // Record 1 is edited by a data entry clerk while the scan runs.
            $C->query("INSERT INTO redcap_log_event (project_id, ts, user, event, object_type, pk,
                description) VALUES (" . PID . ", '20260824120000', 'clerk', 'UPDATE',
                'redcap_data', '1', 'Update record')");
            $d = \REDCap::$data;
            $d['1'][1]['id_code'] = 'BROKEN';
            \REDCap::$data = $d;
            echo "  [scenario] record 1 edited mid-run (a log row and a new value)
";
            return;
        }
        if ($scen === 'editviol' && $pass === 2) {
            // Record 3 already produced a `format` finding in pass 1. Editing it
            // to a value that STILL fails the same rule makes catch-up requeue
            // it, and the second evaluation produces the SAME finding identity.
            $C->query("INSERT INTO redcap_log_event (project_id, ts, user, event, object_type, pk,
                description) VALUES (" . PID . ", '20260824120000', 'clerk', 'UPDATE',
                'redcap_data', '3', 'Update record')");
            $d = \REDCap::$data;
            $d['3'][1]['id_code'] = 'YY88';       // still fails FC[0-9]{4}
            \REDCap::$data = $d;
            echo "  [scenario] record 3 edited mid-run and STILL violates the same rule\n";
            return;
        }
        if ($scen === 'delmid' && $pass === 2) {
            $C->query("DELETE FROM redcap_record_list WHERE project_id = " . PID . " AND record = '7'");
            $C->query("DELETE FROM redcap_data WHERE project_id = " . PID . " AND record = '7'");
            $d = \REDCap::$data; unset($d['7']); \REDCap::$data = $d;
            $C->query("INSERT INTO redcap_log_event (project_id, ts, user, event, object_type, pk,
                description) VALUES (" . PID . ", '20260824120000', 'clerk', 'DELETE',
                'redcap_data', '7', 'Delete record')");
            echo "  [scenario] record 7 DELETED mid-run
";
            return;
        }
        if ($scen === 'cancelmid' && $pass === 2) {
            $svc = $GLOBALS['UV_SVC'];
            $r = $svc->cancel(PID, $runId);
            echo "  [scenario] cancel requested: " . json_encode($r) . "
";
            return;
        }
    }

    function freshService()
    {
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectIdReturn = PID;
        $GLOBALS['UV_MOD'] = $m;
        return new \INSPIRE\UniversalValidator\Scan\ScanService($m);
    }
    $GLOBALS['UV_SVC'] = $svc;
    $GLOBALS['UV_MOD'] = $mod;
    for ($runNo = 1; $runNo <= $RUNS; $runNo++) {
        echo "\n================ SCAN #$runNo ================\n";
        \REDCap::$getDataCalls = 0;
        $svc = freshService();
        $s = $svc->start(PID);
        if (empty($s['ok'])) {
            echo "start REFUSED: busy=" . var_export(!empty($s['busy']), true) . " why=" . $s['why'] . "\n";
            continue;
        }
        $runId = (int) $s['run_id'];
        echo "start: run_id=$runId\n";
        $r0 = dumpRun($C, $runId);
        echo "  after plan: phase={$r0['phase']} total={$r0['manifest_total']} "
           . "coverage={$r0['coverage']}\n";

        $pass = 0; $lastSig = null; $stall = 0; $totalFindings = 0;
        while ($pass < 200) {
            $pass++;
            scenarioHook($SCEN, $pass, $runId, $C);
            // A FRESH MODULE PER PASS: every work() is its own HTTP request.
            $svc = freshService();
            $GLOBALS['UV_SVC'] = $svc;
            try {
                $w = $svc->work(PID, $runId, 'browser');
            } catch (\Throwable $e) {
                echo "  pass $pass: UNCAUGHT " . get_class($e) . ': '
                   . substr($e->getMessage(), 0, 300) . "\n";
                break;
            }
            $st = isset($w['status']) ? $w['status'] : [];
            $row = dumpRun($C, $runId);
            $sig = json_encode([$row['phase'], $row['manifest_done'], $row['cursor_ordinal'],
                                $row['detail_rows'], states($C, $runId)]);
            printf("  pass %-2d phase=%-16s worked=%-3s req=%-3s blk=%-3s find=%-4s stop=%-12s "
                 . "done=%s | run: done=%s/%s findings=%s cov=%s term=%s states=%s\n",
                $pass,
                isset($w['phase']) ? $w['phase'] : '?',
                isset($w['worked']) ? $w['worked'] : '?',
                isset($w['requeued']) ? $w['requeued'] : '?',
                isset($w['blocked']) ? $w['blocked'] : '?',
                isset($w['findings']) ? $w['findings'] : '?',
                isset($w['stop']) ? (string) $w['stop'] : '-',
                var_export(!empty($w['done']), true),
                $row['manifest_done'], $row['manifest_total'], $row['detail_rows'],
                $row['coverage'], (string) $row['terminal'], json_encode(states($C, $runId)));
            if (!empty($w['why'])) echo "        why: " . $w['why'] . "\n";
            if (isset($st['why']) && $st['why'] !== null) echo "        status.why: " . $st['why'] . "\n";
            if (empty($w['ok']) && empty($w['why'])) echo "        (not ok, no why)\n";

            if ($row['phase'] === 'terminal') { echo "  TERMINAL reached\n"; break; }
            if ($sig === $lastSig) { $stall++; } else { $stall = 0; }
            $lastSig = $sig;
            if ($stall >= 4) { echo "  NO PROGRESS for 5 identical passes - giving up\n"; break; }
        }

        $row = dumpRun($C, $runId);
        echo "  final: " . json_encode($row) . "\n";
        echo "  record states: " . json_encode(states($C, $runId)) . "\n";
        $f = $C->query('SELECT COUNT(*) FROM uv_finding')->fetch_row()[0];
        $fa = $C->query('SELECT COUNT(*) FROM uv_finding WHERE active_slot = 1')->fetch_row()[0];
        $cand = $C->query('SELECT COUNT(*) FROM uv_unique_candidate')->fetch_row()[0];
        $grp = $C->query('SELECT COUNT(*) FROM uv_unique_group')->fetch_row()[0];
        $agg = $C->query('SELECT kind, axis1, cnt, blocks_coverage FROM uv_scan_aggregate
            WHERE run_id = ' . $runId);
        echo "  uv_finding=$f (active $fa)  candidates=$cand  groups=$grp\n";
        while ($x = $agg->fetch_row()) echo "  aggregate: " . json_encode($x) . "\n";
        echo "  REDCap::getData calls: " . \REDCap::$getDataCalls . "\n";
        echo "  status(): " . json_encode($svc->status(PID, $runId)) . "\n";
    }

    // ---------------------------------------------------------------------
    // 6b. A SECOND PROJECT on the same installation.
    //     generation_id is 1 for every project, and RollupBuilder selects
    //     findings by generation alone - so this is where one project's summary
    //     is checked against another project's findings.
    // ---------------------------------------------------------------------
    if (getenv('UV_E2E_PROJ2')) {
        $PID2 = 4343;
        $C->query('INSERT INTO redcap_projects VALUES (' . $PID2 . ', "redcap_log_event", "redcap_data")');
        $ins2 = $C->prepare('INSERT INTO redcap_record_list (project_id, arm, record, dag_id) VALUES (?,1,?,NULL)');
        $insD2 = $C->prepare('INSERT INTO redcap_data (project_id, event_id, record, field_name, value, instance) VALUES (?,1,?,?,?,1)');
        $insL2 = $C->prepare('INSERT INTO redcap_log_event (project_id, ts, user, event, object_type, pk, description) VALUES (?,?,?,?,?,?,?)');
        $d2 = [];
        for ($i = 1; $i <= 4; $i++) {
            $rec = 'B' . $i;
            // B1 and B2 share a study_id, so this project HAS a duplicate to find.
            $d2[$rec] = [1 => ['record_id' => $rec, 'id_code' => 'BAD!', 'site' => 'X',
                               'study_id' => ($i <= 2 ? (getenv('UV_E2E_DUPVAL') ?: 'BDUP')
                                                      : 'B' . $i)]];
            \ExternalModules\bindAll($ins2, [$PID2, $rec]); $ins2->execute();
            foreach ($d2[$rec][1] as $f => $v) {
                \ExternalModules\bindAll($insD2, [$PID2, $rec, $f, $v]); $insD2->execute();
            }
            \ExternalModules\bindAll($insL2, [$PID2, date('YmdHis'), 'x', 'UPDATE', 'redcap_data',
                                              $rec, 'Update record']); $insL2->execute();
        }
        // A DIFFERENT instrument name, so its appearance in project 1's summary
        // is unmistakable.
        $dict2 = \REDCap::$dictionary;
        foreach ($dict2 as $k => $v) $dict2[$k]['form_name'] = 'secret_form';
        \REDCap::$dictionary = $dict2;
        \REDCap::$data = $d2;

        $m2 = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m2->projectIdReturn = $PID2;
        $svc2 = new \INSPIRE\UniversalValidator\Scan\ScanService($m2);
        \REDCap::$calls = [];
        $s2 = $svc2->start($PID2);
        echo "\n== project $PID2 == start: " . json_encode($s2) . "\n";
        if (!empty($s2['ok'])) {
            for ($i = 0; $i < 10; $i++) {
                $m2 = new \INSPIRE\UniversalValidator\UniversalValidator();
                $m2->projectIdReturn = $PID2;
                $svc2 = new \INSPIRE\UniversalValidator\Scan\ScanService($m2);
                $w2 = $svc2->work($PID2, (int) $s2['run_id'], 'browser');
                if (!empty($w2['status']['terminal'])) break;
            }
            echo "   status: " . json_encode($svc2->status($PID2, (int) $s2['run_id'])) . "\n";
            $q = $C->query('SELECT kind, axis1, cnt FROM uv_scan_aggregate WHERE run_id = '
                . (int) $s2['run_id'] . " AND kind LIKE 'rollup-%' ORDER BY kind, axis1");
            echo "   project $PID2 SUMMARY (should mention only secret_form):\n";
            while ($r = $q->fetch_row()) echo "     " . json_encode($r) . "\n";
            echo "   getData calls made by project $PID2's worker:
";
            foreach (\REDCap::$calls as $cl) {
                if ((string) $cl['pid'] !== (string) $PID2) continue;
                echo '     project_id=' . $cl['pid'] . ' records='
                   . json_encode(array_slice((array) $cl['records'], 0, 8)) . "
";
            }
            $q = $C->query('SELECT COUNT(*) FROM uv_finding WHERE host_form = "secret_form"'
                . ' AND check_type = "unique"');
            echo "   project $PID2 duplicate findings (expected 2): "
               . $q->fetch_row()[0] . "
";
            $q = $C->query('SELECT COUNT(*) FROM uv_unique_candidate');
            echo "   unique_candidate rows total: " . $q->fetch_row()[0] . "
";
            $q = $C->query('SELECT generation_id, COUNT(*) FROM uv_unique_group GROUP BY generation_id');
            while ($r = $q->fetch_row()) echo "   unique_group rows in generation {$r[0]}: {$r[1]}
";
            $q = $C->query('SELECT project_id, COUNT(*) FROM uv_scan_run GROUP BY project_id');
            while ($r = $q->fetch_row()) echo "   runs for project {$r[0]}: {$r[1]}\n";
        }
    }

    // ---------------------------------------------------------------------
    // 7. what went wrong on the way
    // ---------------------------------------------------------------------
    echo "\n---- sql errors seen (" . count(\ExternalModules\AbstractExternalModule::$sqlErrors) . ") ----\n";
    $seen = [];
    foreach (\ExternalModules\AbstractExternalModule::$sqlErrors as $e) {
        $k = substr($e, 0, 120);
        if (isset($seen[$k])) { $seen[$k]++; continue; }
        $seen[$k] = 1;
        echo "  " . $e . "\n";
    }
    foreach ($seen as $k => $c) if ($c > 1) echo "  (x$c) " . $k . "\n";

    echo "---- php diagnostics (" . count($GLOBALS['UV_DIAG']) . ") ----\n";
    foreach (array_slice(array_unique($GLOBALS['UV_DIAG']), 0, 30) as $d) echo "  $d\n";

    echo "---- module log ----\n";
    foreach ($mod->logCalls as $l) echo "  " . $l[0] . ' ' . json_encode($l[1]) . "\n";

    if (!getenv('UV_E2E_KEEP')) {
        dropFixtures();
        foreach (array_reverse(Schema::tables()) as $t) q('DROP TABLE IF EXISTS ' . $t);
        echo "cleaned up.\n";
    } else {
        echo "fixtures KEPT (UV_E2E_KEEP set).\n";
    }
}
