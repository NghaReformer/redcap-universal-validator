<?php
/**
 * scan_cron_php.php — the scheduled maintenance is actually WIRED, and safe.
 *
 * php/Scan/ScanRetention.php shipped with working cleanup routines that nothing
 * ever called: config.json declared no `crons` key and UniversalValidator
 * declared no cron method, so the only callers were ScanRetention's own tests
 * (H-2). A project holds one scan slot, and a run whose browser closed keeps
 * holding it, so every later scan on that project was told the server was busy
 * — forever, because the reaper written to break that deadlock never ran.
 *
 * THE FIRST BLOCK IS THE POINT. Testing only the cron METHODS would reproduce
 * the defect one level up: delete the `crons` block from config.json and a
 * method-only suite stays green over a feature that never runs again. So the
 * declaration itself is asserted, and asserted against the class.
 *
 * Run:  php tests/scan_cron_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $logCalls = []; public $subSettings = []; public $projectSettings = [];
        public $systemSettings = []; public $projectIdReturn = null;
        public $sql = [];
        /** Statements answered by the stub; also the record of what ran. */
        public $queryThrows = false;
        public $tables = [];        // information_schema answer, for Schema::health
        public function getSubSettings($k, $pid = null) { return []; }
        public function getProjectSetting($k, $pid = null) { return null; }
        public function getSystemSetting($k) {
            return isset($this->systemSettings[$k]) ? $this->systemSettings[$k] : null;
        }
        public function setSystemSetting($k, $v) { $this->systemSettings[$k] = $v; }
        public function query($sql, $params = []) {
            $this->sql[] = $sql;
            if ($this->queryThrows) throw new \RuntimeException('simulated database failure');
            if (strpos($sql, 'information_schema') !== false) {
                // health() asks COUNT(*) per table name, bound as the parameter.
                $want = isset($params[0]) ? (string) $params[0] : '';
                return [[in_array($want, $this->tables, true) ? 1 : 0]];
            }
            if (strpos($sql, 'MAX(version)') !== false) return [[1]];
            if (stripos($sql, 'ROW_COUNT()') !== false) return [[3]];
            return [];
        }
        public function getProjectId() { return $this->projectIdReturn; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return count($this->logCalls); }
        public function initializeJavascriptModuleObject() { return '<script></script>'; }
        public function getJavascriptModuleObjectName() { return 'EM.T.UV'; }
        public function getUser() { return null; }
    }
}

namespace {

require_once __DIR__ . '/../UniversalValidator.php';

use INSPIRE\UniversalValidator\UniversalValidator;

$n = 0; $fail = 0;
function check($label, $cond)
{
    global $n, $fail;
    $n++;
    if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
}

$root = dirname(__DIR__);
$src  = file_get_contents($root . '/UniversalValidator.php');
$cfg  = json_decode(file_get_contents($root . '/config.json'), true);

// ---- 1) the declaration exists, and names methods that exist ---------------
// Without this block the whole file is vacuous: it would iterate zero crons and
// report success over a module that schedules nothing.
check('config.json parses', is_array($cfg));
check('config.json declares a non-empty crons array',
    isset($cfg['crons']) && is_array($cfg['crons']) && count($cfg['crons']) > 0);

$byName = [];
foreach (isset($cfg['crons']) && is_array($cfg['crons']) ? $cfg['crons'] : [] as $c) {
    if (isset($c['cron_name'])) $byName[$c['cron_name']] = $c;
}
foreach (['uv_scan_reap_abandoned', 'uv_scan_expire_values'] as $want) {
    check("cron declared: $want", isset($byName[$want]));
}
foreach ($byName as $name => $c) {
    foreach (['cron_name', 'cron_description', 'method', 'cron_frequency', 'cron_max_run_time'] as $k) {
        check("cron $name has $k", isset($c[$k]) && $c[$k] !== '');
    }
    check("cron $name names a PUBLIC method that exists on the module",
        isset($c['method']) && method_exists('INSPIRE\\UniversalValidator\\UniversalValidator', $c['method'])
        && (new ReflectionMethod('INSPIRE\\UniversalValidator\\UniversalValidator', $c['method']))->isPublic());
    // A run that may outlast its own interval overlaps itself on the next tick.
    check("cron $name cannot outlast its own interval",
        (int) $c['cron_max_run_time'] < (int) $c['cron_frequency']);
}

// ---- 2) it refuses cheaply when the feature is off -------------------------
$m = new UniversalValidator();
$m->systemSettings = [];                       // scan switch not set
$out = $m->uvScanReapCron();
check('flag off: reap does nothing', stripos($out, 'not enabled') !== false);
check('flag off: nothing was queried', $m->sql === []);
check('flag off: nothing was logged', $m->logCalls === []);

// ---- 3) it refuses when the tables are not there ---------------------------
$m = new UniversalValidator();
$m->systemSettings = ['scan-system-enable-durable' => '1'];
$m->tables = [];                                // no scan tables at all
$out = $m->uvScanExpireValuesCron();
check('schema missing: says so rather than working', stripos($out, 'not ready') !== false);
$skipped = false;
foreach ($m->logCalls as $L) if ($L[0] === 'scan-cron-skipped') $skipped = true;
check('schema missing: the skip is recorded', $skipped);
$ddl = false;
foreach ($m->sql as $q) if (stripos($q, 'CREATE TABLE') !== false) $ddl = true;
check('schema missing: a cron never migrates on a timer', !$ddl);

// ---- 4) the happy path actually reaps, and says what it did ----------------
// Taken from Schema itself, so a table added later cannot leave this stale.
$tables = \INSPIRE\UniversalValidator\Scan\Schema::tables();

$m = new UniversalValidator();
$m->systemSettings = ['scan-system-enable-durable' => '1', 'scan-system-stale-run-hours' => '6'];
$m->tables = $tables;
$out = $m->uvScanReapCron();
$upd = false;
foreach ($m->sql as $q) {
    if (stripos($q, 'UPDATE') !== false && stripos($q, 'active_slot') !== false) $upd = true;
}
check('reap: the slot-releasing UPDATE was issued', $upd);
$logged = null;
foreach ($m->logCalls as $L) if ($L[0] === 'scan-cron') $logged = $L[1];
check('reap: logged as scan-cron', $logged !== null);
check('reap: the log names what ran', $logged && $logged['what'] === 'reap');
check('reap: the log carries the count', $logged && array_key_exists('runs_expired', $logged));
check('reap: the summary is a one-liner for the cron table', is_string($out) && $out !== '');

// ---- 5) the value sweep clears the COLUMN, never the row -------------------
$m = new UniversalValidator();
$m->systemSettings = ['scan-system-enable-durable' => '1'];
$m->tables = $tables;
$m->uvScanExpireValuesCron();
$clears = false;
foreach ($m->sql as $q) {
    if (stripos($q, 'UPDATE') !== false && stripos($q, 'value_bin = NULL') !== false) $clears = true;
}
check('expire-values: clears the value column', $clears);
foreach ($m->sql as $q) {
    check('expire-values: never deletes a finding',
        !(stripos($q, 'DELETE') !== false && stripos($q, 'uv_finding') !== false));
}

// ---- 6) THE DATA-LOSS GUARD -----------------------------------------------
// ScanRetention::purgeRuns() deletes findings by generation_id, but uv_finding
// carries no project_id and every run in every project is written with
// generation_id = 1 (ScanPlanner never receives a 'generation'; SqlScanStore
// defaults it). Calling purgeRuns for one project would therefore delete EVERY
// project's findings on the installation. Neither cron may call it until
// generation_id is genuinely per project.
$m = new UniversalValidator();
$m->systemSettings = ['scan-system-enable-durable' => '1', 'scan-system-stale-run-hours' => '6'];
$m->tables = $tables;
$m->uvScanReapCron();
$m->uvScanExpireValuesCron();
$deletes = [];
foreach ($m->sql as $q) if (stripos($q, 'DELETE') !== false) $deletes[] = $q;
check('no cron issues any DELETE while generation_id is not per project',
    $deletes === []);
check('purgeRuns is not reachable from either cron',
    strpos($src, '->purgeRuns(') === false);
// ...and the reason is written down where the next person will look.
check('the withheld purge is explained in the source',
    strpos($src, 'generation_id') !== false && stripos($src, 'purgeRuns') !== false);

// ---- 7) a cron never throws ------------------------------------------------
// A throw out of a cron is mailed to administrators on every tick and nobody
// can act on it, so a database failure has to come back as a string.
$m = new UniversalValidator();
$m->systemSettings = ['scan-system-enable-durable' => '1'];
$m->tables = $tables;
$m->queryThrows = true;
$threw = false;
$out = '';
try { $out = $m->uvScanReapCron(); } catch (\Throwable $e) { $threw = true; }
check('a database failure does not escape the cron', !$threw);
check('a database failure comes back as a summary', is_string($out) && $out !== '');
$failLogged = false;
foreach ($m->logCalls as $L) if ($L[0] === 'scan-cron-failed' || $L[0] === 'scan-cron-skipped') $failLogged = true;
check('a database failure is recorded in the module log', $failLogged);

echo sprintf("scan_cron_php: %d checks, %d failure(s)\n", $n, $fail);
exit($fail === 0 ? 0 : 1);

}
