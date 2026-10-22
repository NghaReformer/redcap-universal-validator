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
 * WHAT THE DATABASE PROVES INSTEAD. Which rows a pass changes, through which
 * keys, in how many statements, and beside a second connection holding locks,
 * is asked of a real server in tests/mysql/cases/cron.php. This file holds the
 * wiring: the declarations, the refusals, the budget, the cursor between
 * passes, the project scoping of every delete and that nothing escapes.
 *
 * Run:  php tests/scan_cron_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $logCalls = []; public $subSettings = []; public $projectSettings = [];
        public $systemSettings = []; public $projectIdReturn = null;
        public $sql = [];
        /** Every [sql, params] pair, for the checks that look at what was bound. */
        public $bound = [];
        /** Statements answered by the stub; also the record of what ran. */
        public $queryThrows = false;
        public $tables = [];        // information_schema answer, for Schema::health
        /** @var callable|null ($sql, $params) => rows, or null to fall through */
        public $responder = null;
        public function getSubSettings($k, $pid = null) { return []; }
        public function getProjectSetting($k, $pid = null) {
            return isset($this->projectSettings[(int) $pid][$k]) ? $this->projectSettings[(int) $pid][$k] : null;
        }
        public function getSystemSetting($k) {
            return isset($this->systemSettings[$k]) ? $this->systemSettings[$k] : null;
        }
        public function setSystemSetting($k, $v) { $this->systemSettings[$k] = $v; }
        public function query($sql, $params = []) {
            $this->sql[] = $sql;
            $this->bound[] = [$sql, $params];
            if ($this->queryThrows) throw new \RuntimeException('simulated database failure');
            if (strpos($sql, 'SELECT table_name FROM information_schema.tables') !== false) {
                // health() names every table in one statement (M11): answer
                // with the ones present, one row each.
                $rows = [];
                foreach ($params as $p) {
                    if (in_array((string) $p, $this->tables, true)) $rows[] = [(string) $p];
                }
                return $rows;
            }
            if (strpos($sql, 'information_schema') !== false) {
                // The per-table COUNT(*) migrate() asks, bound as the parameter.
                $want = isset($params[0]) ? (string) $params[0] : '';
                return [[in_array($want, $this->tables, true) ? 1 : 0]];
            }
            // The version this build expects, so health() reports a current schema.
            if (strpos($sql, 'MAX(version)') !== false) {
                return [[\INSPIRE\UniversalValidator\Scan\Schema::VERSION]];
            }
            if ($this->responder !== null) {
                $r = call_user_func($this->responder, $sql, $params);
                if ($r !== null) return $r;
            }
            if (stripos($sql, 'ROW_COUNT()') !== false) return [[3]];
            // Aggregates always answer one row, and an empty table's MAX is NULL.
            if (strpos($sql, 'SELECT MAX(') !== false || strpos($sql, 'SELECT EXISTS(') !== false) return [[null]];
            return [];
        }
        public function getProjectId() { return $this->projectIdReturn; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return count($this->logCalls); }
        public function initializeJavascriptModuleObject() { return '<script></script>'; }
        public function getJavascriptModuleObjectName() { return 'EM.T.UV'; }
        public $user = null;
        public function getUser() { return $this->user; }
        /** The statements sent that contain every one of $needles. */
        public function sent(array $needles) {
            $out = [];
            foreach ($this->bound as $b) {
                $all = true;
                foreach ($needles as $n) if (strpos($b[0], $n) === false) { $all = false; break; }
                if ($all) $out[] = $b;
            }
            return $out;
        }
        public function logged($event) {
            $out = [];
            foreach ($this->logCalls as $L) if ($L[0] === $event) $out[] = $L[1];
            return $out;
        }
    }
}

namespace {

require_once __DIR__ . '/../UniversalValidator.php';

use INSPIRE\UniversalValidator\UniversalValidator;
use INSPIRE\UniversalValidator\Scan\ScanMaintenance;
use INSPIRE\UniversalValidator\Scan\ScanDb;
use INSPIRE\UniversalValidator\Scan\Schema;

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
check('wave 10 extends the two crons rather than adding a parallel set', count($byName) === 2);
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
    // THE PASS IS A FIXED SLICE WELL INSIDE ITS LIMIT, not a sweep that runs
    // until the framework kills it.
    $budget = ScanMaintenance::budgetFor((int) $c['cron_max_run_time']);
    check("cron $name: a pass is budgeted well inside cron_max_run_time",
        $budget >= 1 && $budget <= (int) $c['cron_max_run_time'] / 2);
}
check('the fallback when the framework passes no entry is no longer than either cron allows',
    UniversalValidator::SCAN_CRON_MAX_RUN_TIME <= min(array_map(function ($c) {
        return (int) $c['cron_max_run_time']; }, $byName)));

// THE POLICY THE PURGE APPLIES IS THE POLICY THE SCAN FROZE. Both read the
// settings ScanPolicy::resolve() names; a key added there and not here would be
// honoured by a scan and ignored by the purge.
$polSrc = file_get_contents($root . '/php/Scan/ScanPolicy.php');
preg_match_all("/'(scan-[a-z0-9-]+)'/", $polSrc, $mm);
$resolveKeys = array_values(array_unique($mm[1]));
sort($resolveKeys);
$cronKeys = array_merge(ScanMaintenance::SYSTEM_POLICY_KEYS, ScanMaintenance::PROJECT_POLICY_KEYS);
sort($cronKeys);
check('the maintenance policy reader reads every setting ScanPolicy::resolve() names, and no other',
    $resolveKeys === $cronKeys);

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

// ---- 4) the reap pass: the census, then the projects it names --------------
// Taken from Schema itself, so a table added later cannot leave this stale.
$tables = Schema::tables();
$RUN = Schema::table('scan_run');

/**
 * A census that names projects, and what is due in each: [pid, stale,
 * supersede, oldest finished, modes].
 */
$census = function (array $rows) use ($RUN) {
    return function ($sql, $params) use ($rows, $RUN) {
        if (strpos($sql, 'GROUP_CONCAT(DISTINCT values_state)') === false) return null;
        $out = [];
        foreach ($rows as $r) if ($r[0] > (int) end($params)) $out[] = $r;
        return $out;
    };
};

$m = new UniversalValidator();
$m->systemSettings = ['scan-system-enable-durable' => '1', 'scan-system-stale-run-hours' => '6'];
$m->tables = $tables;
$m->responder = $census([[42, 1, 0, null, 'locations']]);
$out = $m->uvScanReapCron(['cron_max_run_time' => '300']);
check('reap: the census reads every project with scan data, a page at a time',
    count($m->sent(['GROUP_CONCAT(DISTINCT values_state)', 'GROUP BY project_id', 'LIMIT '])) === 1);
$named = $m->sent(['SELECT run_id FROM ' . $RUN, 'active_slot = 1', 'progress_at']);
check('reap: the stale project it names is reaped through the store, by its own id',
    count($named) === 1 && $named[0][1][0] === 42);
check('reap: the slots above the limit are looked for on every pass (W1-N1)',
    count($m->sent(['SELECT slot_no FROM ' . Schema::table('scan_worker_slot'), 'slot_no > ?'])) === 1);
$logged = $m->logged('scan-cron');
check('reap: logged as scan-cron, naming the pass and carrying the counts',
    count($logged) === 1 && $logged[0]['what'] === 'reap' && array_key_exists('runs_expired', $logged[0])
    && array_key_exists('superseded', $logged[0]) && array_key_exists('slots_removed', $logged[0]));
check('reap: the summary is a one-liner for the cron table', is_string($out) && strpos($out, 'reap:') === 0);
$saved = json_decode((string) $m->getSystemSetting('scan-cron-state-reap'), true);
check('reap: the pass leaves its state for the next one, the lap finished',
    is_array($saved) && isset($saved['after']) && $saved['after'] === 0);

// ---- 5) one project's failure is logged, and the next project still runs ---
$m = new UniversalValidator();
$m->systemSettings = ['scan-system-enable-durable' => '1'];
$m->tables = $tables;
$inner = $census([[13, 1, 0, null, ''], [42, 1, 0, null, '']]);
$m->responder = function ($sql, $params) use ($inner, $RUN) {
    if (strpos($sql, 'SELECT run_id FROM ' . $RUN) !== false && isset($params[0]) && $params[0] === 13) {
        throw new \RuntimeException('Deadlock found when trying to get lock (1213)');
    }
    return $inner($sql, $params);
};
$out = $m->uvScanReapCron();
$failed = $m->logged('scan-cron-step-failed');
check('isolation: the failing project is logged with its step and a retry, never its raw message',
    count($failed) === 1 && $failed[0]['project_id'] === 13 && $failed[0]['retry'] === 'next pass'
    && $failed[0]['what'] === 'reap' && strpos(json_encode($failed[0]), 'SELECT') === false);
$after = $m->sent(['SELECT run_id FROM ' . $RUN, 'active_slot = 1']);
check('isolation: and the project after it is reaped all the same',
    count($after) === 2 && $after[1][1][0] === 42);
check('isolation: the pass itself is not a failed cron', strpos($out, 'failed, logged') === false
    && count($m->logged('scan-cron')) === 1 && $m->logged('scan-cron')[0]['failed'] === 1);

// ---- 6) THE DATA-LOSS GUARD: every DELETE is this project's ----------------
// purgeRuns() once deleted findings by generation alone while every project
// was generation 1, so one project's expired run took every project's
// findings. It is wired now that it is scoped and paged; what is asserted is
// that each DELETE the retention pass sends names the project, or the run it
// read for that project.
$m = new UniversalValidator();
$m->systemSettings = ['scan-system-enable-durable' => '1'];
$m->tables = $tables;
$inner = $census([[42, 0, 0, '2000-01-01 00:00:00', 'locations']]);
$m->responder = function ($sql, $params) use ($inner, $RUN) {
    if (strpos($sql, 'SELECT run_id, generation_id, terminal_reason FROM ' . $RUN) !== false) return [[7, 3, null]];
    if (strpos($sql, 'SELECT EXISTS(') !== false) return [[0]];
    if (strpos($sql, 'SELECT MAX(finding_id)') !== false) return [[0]];
    return $inner($sql, $params);
};
$m->uvScanExpireValuesCron(['cron_max_run_time' => '900']);
$deletes = $m->sent(['DELETE FROM']);
$scoped = true;
foreach ($deletes as $d) {
    $byProject = strpos($d[0], 'project_id = ?') !== false && in_array(42, $d[1], true);
    $byRun = strpos($d[0], 'run_id = ?') !== false && in_array(7, $d[1], true);
    if (!$byProject && !$byRun) $scoped = false;
}
check('purge: a project past its retention is purged', count($deletes) >= 8);
// rv10a1-3: the run reads as gone to every reader from the first page, so it
// is marked before anything it owns is deleted.
$firstMark = null;
$firstDelete = null;
foreach ($m->bound as $i => $b) {
    if ($firstMark === null && strpos($b[0], 'UPDATE ' . $RUN . ' SET terminal_reason = ?') !== false
        && in_array(\INSPIRE\UniversalValidator\Scan\ScanStore::REMOVING_WHY, $b[1], true)) $firstMark = $i;
    if ($firstDelete === null && strpos($b[0], 'DELETE FROM') !== false) $firstDelete = $i;
}
check('purge: the run is marked as being removed before anything it owns is deleted',
    $firstMark !== null && $firstDelete !== null && $firstMark < $firstDelete);
check('purge: and every DELETE names that project or its run', $scoped);
check('purge: findings are deleted by project AND generation, never by generation alone',
    count($m->sent(['DELETE FROM ' . Schema::table('finding'), 'project_id = ?', 'generation_id = ?'])) === 1);
check('purge: every child DELETE is a bounded page',
    count(array_filter($deletes, function ($d) use ($RUN) {
        return strpos($d[0], ' LIMIT ') === false && strpos($d[0], 'DELETE FROM ' . $RUN) === false; })) === 0);
// The value sweep clears the COLUMN, never the row.
$clears = $m->sent(['UPDATE ' . Schema::table('finding'), 'value_bin = NULL', 'finding_id > ?']);
check('expire-values: the sweep clears the value column a window of ids at a time', count($clears) >= 1);
check('expire-values: and never deletes a finding outside a purge',
    count($m->sent(['DELETE FROM ' . Schema::table('finding')])) === 1);
$saved = json_decode((string) $m->getSystemSetting('scan-cron-state-retention'), true);
check('expire-values: a finished sweep is remembered, so the next starts a day later',
    is_array($saved) && isset($saved['sweep']['finished']) && $saved['sweep']['top'] === null);

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

// ---- 8) the budget, and the cursor between passes ---------------------------
//
// ScanMaintenance itself, over a scripted connection and a clock that moves a
// second on every reading, so "the budget ran out" is a fact of the test.
final class ScriptDb implements ScanDb
{
    public $log = [];
    private $responder;
    private $affected = 0;
    public function __construct(callable $r) { $this->responder = $r; }
    public function select($sql, array $params = []) {
        $this->log[] = [$sql, $params];
        $r = call_user_func($this->responder, $sql, $params);
        return $r === null ? [] : $r;
    }
    public function exec($sql, array $params = []) { $this->log[] = [$sql, $params]; $this->affected = 0; }
    public function execNoCount($sql, array $params = []) { $this->log[] = [$sql, $params]; }
    public function affected() { return $this->affected; }
    public function begin() {}
    public function commit() {}
    public function rollback() {}
}
$policy = function ($pid) { return \INSPIRE\UniversalValidator\Scan\ScanPolicy::resolve([], []); };
$ticks = 0.0;
$clock = function () use (&$ticks) { return $ticks += 1.0; };
$db = new ScriptDb($census([[5, 1, 0, null, ''], [9, 1, 0, null, ''], [12, 1, 0, null, ''], [20, 1, 0, null, '']]));
$mt = new ScanMaintenance($db, ['policy' => $policy, 'clock' => $clock]);
$first = $mt->reap([], 4.5);
check('budget: a pass that runs out stops between projects, and says there is more',
    $first['out']['more'] === 1 && $first['out']['runs_expired'] === 0 && $first['out']['projects'] >= 1
    && $first['out']['projects'] < 4);
check('budget: and hands back where it stopped', $first['state']['after'] > 0 && $first['state']['after'] < 20);
$db->log = [];
$second = $mt->reap($first['state'], 100.0);
$cen = array_values(array_filter($db->log, function ($l) { return strpos($l[0], 'GROUP_CONCAT') !== false; }));
check('budget: the next pass starts after that project, not from the first',
    count($cen) >= 1 && end($cen[0][1]) === $first['state']['after']);
check('budget: and finishes the lap, so the pass after starts again from the first project',
    $second['state']['after'] === 0 && $second['out']['more'] === 0);
$stopped = (new ScanMaintenance($db, ['policy' => $policy, 'clock' => $clock,
    'stopped' => function () { return true; }]))->reap([], 100.0);
check('stop: a pass told to stop does no project work', $stopped['out']['stopped'] === 1
    && $stopped['out']['projects'] === 0);
// rv10a2-4: a project whose statement finds its table gone is not passed
// over. The pass stops before it, and hands back the project before it.
$gdb = new ScriptDb(function ($sql, $params) use ($census, $RUN) {
    if (strpos($sql, 'SELECT run_id FROM ' . $RUN) !== false && $params[0] === 9) {
        throw new \RuntimeException("Table 'redcap.uv_scan_run' doesn't exist");
    }
    return call_user_func($census([[5, 1, 0, null, ''], [9, 1, 0, null, ''], [12, 1, 0, null, '']]), $sql, $params);
});
$gone = (new ScanMaintenance($gdb, ['policy' => $policy]))->reap([], 100.0);
check('gone: a pass that finds a table missing stops, and resumes at that project, not after it',
    $gone['out']['stopped'] === 1 && $gone['out']['failed'] === 1 && $gone['state']['after'] === 5);
$gdb = new ScriptDb(function ($sql, $params) {
    throw new \RuntimeException("Table 'redcap.uv_finding' doesn't exist");
});
$owed = ['revoke' => [5 => ['revision' => 2], 9 => ['revision' => 3]], 'after' => 0];
$gone = (new ScanMaintenance($gdb, ['policy' => $policy]))->retain($owed, 100.0);
check('gone: and tries no revocation it still owes after the first, nor any project',
    $gone['out']['stopped'] === 1 && $gone['out']['failed'] === 1
    && array_filter($gdb->log, function ($l) { return in_array(9, $l[1], true)
        || strpos($l[0], 'GROUP_CONCAT(DISTINCT values_state)') !== false; }) === []
    && array_keys($gone['state']['revoke']) === [5, 9]);

// ---- 9) the module switched off (M17) ---------------------------------------
$SLOT = Schema::table('scan_worker_slot');
$check9 = function ($label, array $sys, $throws = false) use ($tables, $RUN) {
    $m = new UniversalValidator();
    $m->systemSettings = $sys + ['scan-cron-state-reap' => '{"after":7}', 'scan-cron-state-retention' => '{"after":9}'];
    $m->tables = $tables;
    $m->responder = function ($sql, $params) use ($RUN) {
        // Run 5 of project 42 is active; once stopped, the second read
        // (only runs not yet stopped) finds nothing.
        if (strpos($sql, 'SELECT run_id, project_id FROM ' . $RUN) !== false) {
            return strpos($sql, 'phase <> ?') !== false ? [] : [[5, 42]];
        }
        if (stripos($sql, 'ROW_COUNT()') !== false) return [[1]];
        return null;
    };
    $m->queryThrows = $throws;
    $threw = false;
    try { $m->redcap_module_system_disable('9.9.9'); } catch (\Throwable $e) { $threw = true; }
    check($label . ': the disable hook never throws', !$threw);
    $at = $m->getSystemSetting(UniversalValidator::SCAN_DISABLED_AT);
    check($label . ': the stop marker is written, whatever else happens',
        is_numeric($at) && abs((int) $at - time()) <= 5);
    return $m;
};
$on = ['scan-system-enable-durable' => '1'];
$m = $check9('disable', $on);
check('disable: every leased worker slot is released, in one statement',
    count($m->sent(['UPDATE ' . $SLOT, 'owner = NULL', 'WHERE owner IS NOT NULL'])) === 1);
check('disable: the active runs are read a bounded keyset page at a time',
    count($m->sent(['SELECT run_id, project_id FROM ' . $RUN, 'active_slot = 1 AND run_id > ?', 'LIMIT '])) === 1);
$cancel = $m->sent(['UPDATE ' . $RUN, 'cancel_requested_at']);
check('disable: each page of active runs is stopped in one statement, by run id, with the reason on the row',
    count($cancel) === 1 && in_array(5, $cancel[0][1], true)
    && strpos($cancel[0][0], 'run_id IN (') !== false && strpos($cancel[0][0], 'lease_epoch = lease_epoch + 1') !== false
    && in_array(ScanMaintenance::DISABLED_WHY, $cancel[0][1], true));
check('disable: the stop is audited as the system\'s',
    count(array_filter($m->sent(['INSERT INTO ' . Schema::table('scan_audit')]), function ($b) {
        return in_array('system', $b[1], true); })) === 1);
// rv10a2-3: the runs are stopped before the slots are freed, and the active
// runs are read once more after the walk.
$order = [];
foreach ($m->bound as $i => $b) {
    if (strpos($b[0], 'UPDATE ' . $RUN) !== false && strpos($b[0], 'cancel_requested_at') !== false && !isset($order['stop'])) $order['stop'] = $i;
    if (strpos($b[0], 'UPDATE ' . $SLOT) !== false && strpos($b[0], 'WHERE owner IS NOT NULL') !== false) $order['slots'] = $i;
    if (strpos($b[0], 'active_slot = 1 AND phase <> ? AND run_id > ?') !== false && !isset($order['late'])) $order['late'] = $i;
    // finishCancelled() begins with the run's own row.
    if (strpos($b[0], 'FROM ' . $RUN . ' WHERE run_id = ? AND project_id = ?') !== false && !isset($order['finish'])) $order['finish'] = $i;
}
check('disable: every run is stopped before any worker slot is freed',
    isset($order['stop'], $order['slots']) && $order['stop'] < $order['slots']);
check('disable: and the active runs not yet stopped are read again after the walk',
    count($m->sent(['SELECT run_id, project_id FROM ' . $RUN, 'active_slot = 1 AND phase <> ? AND run_id > ?'])) === 1);
check('disable: and only then is any stopped run finished',
    isset($order['late'], $order['finish']) && $order['late'] < $order['finish']);
$dl = $m->logged('scan-disable');
check('disable: what it did is logged', count($dl) === 1 && $dl[0]['slots_released'] === 1);
check('disable: with the opt-in off nothing is deleted or dropped',
    $m->sent(['DROP TABLE']) === [] && $m->sent(['DELETE FROM']) === []
    && $m->getSystemSetting('scan-cron-state-reap') === '{"after":7}');

$m = $check9('disable, scan switched off', []);
check('disable, scan switched off: no scan table is touched',
    $m->sent([Schema::table('scan_run')]) === [] && $m->sent([$SLOT]) === []);

$m = $check9('disable, database down', $on, true);
$dl = $m->logged('scan-disable');
check('disable, database down: the log says why nothing was stopped',
    count($dl) === 1 && $dl[0]['stopped'] === 0 && $dl[0]['why'] === 'the scan tables are not ready');

$m = $check9('disable, drop opted in', $on + [UniversalValidator::SCAN_DROP_ON_DISABLE => '1']);
$drops = array_map(function ($b) { return trim(substr($b[0], strlen('DROP TABLE IF EXISTS '))); }, $m->sent(['DROP TABLE IF EXISTS']));
$want = $tables;
sort($want);
$got = $drops;
sort($got);
check('disable, drop opted in: every table the module created is dropped', $got === $want);
check('disable, drop opted in: the version table last', end($drops) === Schema::table('schema_version'));
check('disable, drop opted in: after the runs were stopped, not instead',
    count($m->sent(['UPDATE ' . $RUN, 'cancel_requested_at'])) === 1);
check('disable, drop opted in: the crons\' cursors are forgotten with the rows',
    (string) $m->getSystemSetting('scan-cron-state-reap') === ''
    && (string) $m->getSystemSetting('scan-cron-state-retention') === '');
check('the drop opt-in is declared, as a checkbox, and off unless ticked',
    count(array_filter($cfg['system-settings'], function ($s) {
        return isset($s['key']) && $s['key'] === UniversalValidator::SCAN_DROP_ON_DISABLE
            && $s['type'] === 'checkbox' && !isset($s['default']); })) === 1);

// rv10a2-3: enabling writes its own marker, which ends the refusal Start and
// work give while a disable is newer (ScanService::disabling()).
$m = new UniversalValidator();
$m->systemSettings = [UniversalValidator::SCAN_DISABLED_AT => (string) (time() - 30)];
$m->tables = $tables;
$threw = false;
try { $m->redcap_module_system_enable('9.9.9'); } catch (\Throwable $e) { $threw = true; }
$en = $m->getSystemSetting(UniversalValidator::SCAN_ENABLED_AT);
check('enable: the enable marker is written, after the disable before it',
    !$threw && is_numeric($en) && (int) $en >= (int) $m->getSystemSetting(UniversalValidator::SCAN_DISABLED_AT)
    && abs((int) $en - time()) <= 5);

// A pass running when the module is switched off stops at its next unit; a
// marker from an earlier disable stops nothing.
$m = new UniversalValidator();
$m->systemSettings = $on + [UniversalValidator::SCAN_DISABLED_AT => (string) (time() + 100)];
$m->tables = $tables;
$m->responder = $census([[42, 1, 0, null, '']]);
$m->uvScanReapCron();
$cl = $m->logged('scan-cron');
check('stop marker: a pass that began before the disable does no project work',
    count($cl) === 1 && $cl[0]['stopped'] === 1 && $cl[0]['projects'] === 0
    && $m->sent(['GROUP_CONCAT(DISTINCT values_state)']) === []);
$m = new UniversalValidator();
$m->systemSettings = $on + [UniversalValidator::SCAN_DISABLED_AT => (string) (time() - 100)];
$m->tables = $tables;
$m->responder = $census([[42, 1, 0, null, '']]);
$m->uvScanReapCron();
$cl = $m->logged('scan-cron');
check('stop marker: an earlier disable stops no pass of the enable after it',
    count($cl) === 1 && $cl[0]['stopped'] === 0 && $cl[0]['projects'] === 1);

// rv10a2-4: a disable that drops the tables lands while a pass works the
// first of 300 projects in its census page (the reviewer's
// temporal_rv10a2_drop_race.php). The pass read the marker a moment before,
// so only the missing table can tell it. It stops at the first failure, not
// one log entry for each project left, and leaves the cursor the drop emptied.
$rows = [];
for ($p = 1; $p <= 300; $p++) $rows[] = [$p, 1, 0, null, ''];
$m = new UniversalValidator();
$m->systemSettings = $on + [UniversalValidator::SCAN_DROP_ON_DISABLE => '1',
    'scan-cron-state-reap' => json_encode(['after' => 0])];
$m->tables = $tables;
$dropped = false;
$emptied = null;
$m->responder = function ($sql, $params) use (&$dropped, &$emptied, $rows, $RUN, $m) {
    if ($dropped && strpos($sql, 'uv_') !== false) {
        throw new \RuntimeException("Table 'redcap.uv_scan_run' doesn't exist");
    }
    if (strpos($sql, 'GROUP_CONCAT(DISTINCT values_state)') !== false) {
        $out = [];
        foreach ($rows as $r) if ($r[0] > (int) end($params)) $out[] = $r;
        return $out;
    }
    if (strpos($sql, 'SELECT run_id FROM ' . $RUN) !== false && $params[0] === 1 && !$dropped) {
        // Another request: the administrator disables the module, opted in to the drop.
        $m->redcap_module_system_disable('9.9.9');
        $emptied = $m->getSystemSetting('scan-cron-state-reap');
        $dropped = true;
        return [];
    }
    if (strpos($sql, 'SELECT run_id, project_id FROM ' . $RUN) !== false) return [];
    return null;
};
$m->uvScanReapCron(['cron_max_run_time' => '300']);
$cl = $m->logged('scan-cron');
check('drop race: a pass whose tables went stops at the first statement that finds one missing',
    count($m->logged('scan-cron-step-failed')) === 1 && count($cl) === 1 && $cl[0]['stopped'] === 1);
check('drop race: and leaves the cursor the drop emptied, not its own over it',
    $emptied === '' && $m->getSystemSetting('scan-cron-state-reap') === '');
check('a missing table is known by the server\'s sentence, through the store\'s wrapper too',
    \INSPIRE\UniversalValidator\Scan\DbError::isMissingTable(new \RuntimeException("Table 'redcap.uv_scan_run' doesn't exist"))
    && \INSPIRE\UniversalValidator\Scan\DbError::isMissingTable(new \RuntimeException('query failed', 1146))
    && \INSPIRE\UniversalValidator\Scan\DbError::isMissingTable(new \INSPIRE\UniversalValidator\Scan\ScanStoreUnavailable('x',
        new \RuntimeException(str_repeat('x', 5000) . " Table 'redcap.uv_finding' doesn't exist")))
    && !\INSPIRE\UniversalValidator\Scan\DbError::isMissingTable(new \RuntimeException("Unknown column 'x' in 'field list'"))
    && !\INSPIRE\UniversalValidator\Scan\DbError::isMissingTable(new \RuntimeException('Lock wait timeout exceeded', 1205)));

// ---- 10) memo lifetime: one module instance, many projects (W8-N10) ---------
//
// A page request is one project and one user; a cron pass is many projects
// through the same instance. What the instance remembers about one project
// must not be the answer for the next.
$rc = new ReflectionClass(UniversalValidator::class);
$own = [];
foreach ($rc->getProperties() as $p) {
    if (!$p->isStatic() && $p->getDeclaringClass()->getName() === $rc->getName()) $own[] = $p->getName();
}
$listed = array_merge(UniversalValidator::SCAN_MEMOS, UniversalValidator::SCAN_NOT_MEMOS);
sort($own);
$sortedListed = $listed;
sort($sortedListed);
check('memos: every instance property of the module is a memo the cron resets or is named as not one',
    $own === $sortedListed && count(array_unique($listed)) === count($listed));

final class MemoUser
{
    public $forms;
    public function __construct(array $forms) { $this->forms = $forms; }
    public function isSuperUser() { return false; }
    public function getRights($pid) { return [$pid => ['forms' => $this->forms, 'group_id' => null, 'expiration' => '']]; }
}
$rightsIn = new ReflectionMethod(UniversalValidator::class, 'userRightsIn');
$rightsIn->setAccessible(true);
$memo = new ReflectionProperty(UniversalValidator::class, 'rightsIn');
$memo->setAccessible(true);

$m = new UniversalValidator();
$m->systemSettings = $on;
$m->tables = $tables;
$m->user = new MemoUser(['demographics' => '1']);
check('memos: the rights read really is remembered within a request',
    $rightsIn->invoke($m, 42)['forms'] === ['demographics' => '1']
    && ($m->user->forms = ['demographics' => '0']) && $rightsIn->invoke($m, 42)['forms'] === ['demographics' => '1']);
// Project 42's work reads the rights in 42; what 43's work finds remembered is recorded.
$at43 = 'not reached';
$inner = $census([[42, 1, 0, null, ''], [43, 1, 0, null, '']]);
$m->responder = function ($sql, $params) use ($inner, $RUN, $m, $rightsIn, $memo, &$at43) {
    if (strpos($sql, 'SELECT run_id FROM ' . $RUN) !== false && isset($params[0])) {
        if ($params[0] === 42) $rightsIn->invoke($m, 42);
        if ($params[0] === 43) $at43 = $memo->getValue($m);
    }
    return $inner($sql, $params);
};
$m->uvScanReapCron();
check('memos: the next project in the same pass starts with nothing remembered', $at43 === []);
$m->user->forms = ['demographics' => '1', 'visits' => '1'];
$m->responder = $census([[42, 1, 0, null, '']]);
$m->uvScanReapCron();
check('memos: a second pass in the same process reads the rights as they are now',
    $rightsIn->invoke($m, 42)['forms'] === ['demographics' => '1', 'visits' => '1']);
// The retention pass: a revocation carried on for 43, then 42 purged in the lap.
$m = new UniversalValidator();
$m->systemSettings = $on;
$m->tables = $tables;
$m->user = new MemoUser(['demographics' => '1']);
$memo->setValue($m, [99 => ['forms' => true, 'group' => null]]);
$seen = [];
$inner = $census([[42, 0, 0, '2000-01-01 00:00:00', '']]);
$m->responder = function ($sql, $params) use ($inner, $RUN, $m, $rightsIn, $memo, &$seen) {
    if (strpos($sql, 'SELECT DISTINCT generation_id') !== false && $params[0] === 43) {
        $seen['43'] = $memo->getValue($m);
        $rightsIn->invoke($m, 43);
    }
    if (strpos($sql, 'SELECT run_id, generation_id, terminal_reason FROM ' . $RUN) !== false && $params[0] === 42 && !isset($seen['42'])) {
        $seen['42'] = $memo->getValue($m);
    }
    return $inner($sql, $params);
};
$m->systemSettings['scan-cron-state-retention'] = json_encode(['revoke' => [43 => ['revision' => 2, 'bumped' => true, 'cursor' => null]]]);
$m->uvScanExpireValuesCron(['cron_max_run_time' => '900']);
check('memos: a revocation carried on, and the lap\'s next project, each start with nothing remembered',
    isset($seen['43'], $seen['42']) && $seen['43'] === [] && $seen['42'] === []);

// And every memo, not only the one read above.
$defaults = $rc->getDefaultProperties();
foreach (UniversalValidator::SCAN_MEMOS as $p) {
    $rp = new ReflectionProperty(UniversalValidator::class, $p);
    $rp->setAccessible(true);
    $rp->setValue($m, ['stale' => 'from the project before']);
}
$m->responder = $census([[42, 1, 0, null, '']]);
$m->uvScanReapCron();
$left = [];
foreach (UniversalValidator::SCAN_MEMOS as $p) {
    $rp = new ReflectionProperty(UniversalValidator::class, $p);
    $rp->setAccessible(true);
    if ($rp->getValue($m) !== $defaults[$p]) $left[] = $p;
}
check('memos: every memo is back to its default once a project is entered (left: ' . implode(', ', $left) . ')',
    $left === []);

echo sprintf("scan_cron_php: %d checks, %d failure(s)\n", $n, $fail);
exit($fail === 0 ? 0 : 1);

}
