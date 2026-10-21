<?php
/**
 * tests/mysql/cases/cron.php — the scheduled maintenance, and the reaper Start
 * runs, against a real server.
 *
 * WHAT IS PROVED HERE AND NOT IN THE FAST SUITE. tests/scan_cron_php.php checks
 * the wiring: the declarations, the refusals, the budget and that nothing
 * escapes a cron. Whether a pass does the right work in the right number of
 * statements, through which keys, and what a second connection holding locks
 * does to it, is a property of InnoDB and is asked of one here.
 */

use INSPIRE\UniversalValidator\Scan\Schema;
use INSPIRE\UniversalValidator\Scan\ScanService;
use INSPIRE\UniversalValidator\Scan\ScanStore;
use INSPIRE\UniversalValidator\Scan\ScanOutcome;
use INSPIRE\UniversalValidator\Scan\SqlScanStore;

// ScanService builds a CatchUp for every work request; the shared bootstrap
// does not load it.
require_once __DIR__ . '/../../../php/Scan/CatchUp.php';

if (!class_exists('UvCronModule', false)) {
    /** A designer with full export rights on the one instrument the rule lives on. */
    class UvCronUser
    {
        public function hasDesignRights() { return true; }
        public function getRights($pid = null)
        {
            return array('design' => true, 'data_export_tool' => '1', 'group_id' => null,
                         'forms' => array('demographics' => '1'));
        }
        public function getUsername() { return 'alice'; }
    }

    /**
     * The framework edge: query() over the real connection, settings from two
     * arrays, and the one rule perf.php's module plans with.
     */
    class UvCronModule
    {
        private $c;
        public $log = array();
        public $sys = array();
        public $proj = array();

        public function __construct($c) { $this->c = $c; }

        public function query($sql, $params = array())
        {
            $this->log[] = $sql;
            if (!$params) return $this->c->query($sql);
            $st = $this->c->prepare($sql);
            bindAll($st, array_values($params));
            $st->execute();
            $r = $st->get_result();
            return $r === false ? true : $r;
        }
        public function getSystemSetting($k)
        {
            if ($k === ScanService::SYS_FLAG) return '1';
            if ($k === 'log-hmac-key') return 'cron-secret-key';
            return isset($this->sys[$k]) ? $this->sys[$k] : null;
        }
        public function getProjectSetting($k, $pid = null)
        {
            if ($k === ScanService::PROJ_FLAG) return '1';
            return isset($this->proj[(int) $pid][$k]) ? $this->proj[(int) $pid][$k] : null;
        }
        public function getUser() { return new UvCronUser(); }
        public function log($event, $context = array()) { return 1; }
        public function durableScanContext($pid, array $opts = array(), $dag = null)
        {
            $caps = (isset($opts['capabilities']) && is_array($opts['capabilities']))
                ? $opts['capabilities'] : \INSPIRE\UniversalValidator\ScanCapabilities::all($this, $pid);
            \INSPIRE\UniversalValidator\ScanCapabilities::policy($caps);
            return array('ok' => true, 'why' => null,
                'rules' => array(array('type' => 'required', 'fields' => array('dob'))),
                'ownership' => array('dob' => 'demographics'),
                'structure' => array('longitudinal' => false),
                'plan' => array('hostFields' => array(0 => array('demographics' => array('dob'))),
                                'crossRefused' => array(), 'crossLookups' => array(), 'crossRecordLookups' => false),
                'read' => function ($ids) { return array('ok' => true, 'data' => array(), 'why' => null); },
                'evaluate' => null, 'verifyFingerprint' => true, 'problems' => array());
        }
    }

    /**
     * The real statements of a store method, with the COMMIT held back until
     * the test says. What another connection meets is then exactly the locks
     * that method takes, for as long as the test needs it to meet them.
     */
    class UvHeldCommitDb implements \INSPIRE\UniversalValidator\Scan\ScanDb
    {
        private $inner;
        public $pending = false;
        public function __construct(\INSPIRE\UniversalValidator\Scan\ScanDb $inner) { $this->inner = $inner; }
        public function select($sql, array $params = array()) { return $this->inner->select($sql, $params); }
        public function exec($sql, array $params = array()) { $this->inner->exec($sql, $params); }
        public function execNoCount($sql, array $params = array()) { $this->inner->execNoCount($sql, $params); }
        public function affected() { return $this->inner->affected(); }
        public function begin() { $this->inner->begin(); }
        public function commit() { $this->pending = true; }
        public function rollback() { $this->pending = false; $this->inner->rollback(); }
        public function release() { if ($this->pending) { $this->pending = false; $this->inner->commit(); } }
    }

    /** A ScanDb that lets the test act between two statements of the code under test. */
    class UvHookDb implements \INSPIRE\UniversalValidator\Scan\ScanDb
    {
        private $inner;
        public $before = null;
        public function __construct(\INSPIRE\UniversalValidator\Scan\ScanDb $inner) { $this->inner = $inner; }
        private function hook($kind, $sql, array $params)
        {
            if ($this->before !== null) call_user_func($this->before, $kind, $sql, $params);
        }
        public function select($sql, array $params = array()) { $this->hook('select', $sql, $params); return $this->inner->select($sql, $params); }
        public function exec($sql, array $params = array()) { $this->hook('exec', $sql, $params); $this->inner->exec($sql, $params); }
        public function execNoCount($sql, array $params = array()) { $this->hook('exec', $sql, $params); $this->inner->execNoCount($sql, $params); }
        public function affected() { return $this->inner->affected(); }
        public function begin() { $this->inner->begin(); }
        public function commit() { $this->inner->commit(); }
        public function rollback() { $this->inner->rollback(); }
    }
}

uv_redcap_schema($A);
uv_redcap_log_schema($A);

// -- START UNSTICKS A PROJECT WHOSE RUN WAS ABANDONED (B6a) -------------------
//
// A run whose tab closed holds the project's one active slot. Start used to
// reap only a CANCELLED run, so it answered "busy" over nobody's scan until the
// reap cron came round, and for ever where the cron was not running.
$SP = 970;
{
    uv_redcap_project($A, $SP);
    foreach (array('S1', 'S2') as $rec) {
        $A->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES (" . $SP . ", '" . $rec . "', NULL)");
        $A->query("INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`)
            VALUES (" . $SP . ", 1, '" . $rec . "', 'record_id', '" . $rec . "')");
    }
    $A->query('INSERT INTO redcap_log_event (log_event_id, project_id, pk, event) VALUES (7001, ' . $SP . ", 'S1', 'UPDATE')");
    $store = new SqlScanStore($dbA);
    $old = $store->startRun($SP, array('created_by' => 'bob'));
    $oldId = (int) $old['run']['run_id'];
    $oldEpoch = (int) $old['run']['lease_epoch'];
    // Thirty hours without progress, no lease: the default window is 24.
    $A->query('UPDATE ' . Schema::table('scan_run') . " SET created_at = '"
        . gmdate('Y-m-d H:i:s', time() - 30 * 3600) . "', progress_at = NULL, lease_expires_at = NULL"
        . ' WHERE run_id = ' . $oldId);
    $m = new UvCronModule($A);
    $started = (new ScanService($m))->start($SP);
    check('start: a project held by an abandoned run starts a new scan (got ' . json_encode($started) . ')',
        $started['ok'] === true && (int) $started['run_id'] > $oldId);
    $was = $store->run($SP, $oldId);
    $want = ScanOutcome::derive(array('expired' => true));
    check('start: the abandoned run is expired with the row every reaper writes',
        $was['terminal'] === ScanOutcome::EXPIRED && $was['terminal_reason'] === $want['why']
        && $was['clean'] === null && (int) $was['lease_epoch'] === $oldEpoch + 1);
    $au = $dbA->select('SELECT event, actor FROM ' . Schema::table('scan_audit')
        . ' WHERE project_id = ? AND run_id = ?', array($SP, $oldId));
    check('start: and the audit names the user whose Start found it',
        count($au) === 1 && $au[0][0] === ScanStore::AUDIT_EXPIRE && $au[0][1] === 'alice');

    // A run that is merely quiet, inside the window, is not anybody's to end.
    $live = (int) $started['run_id'];
    $again = (new ScanService($m))->start($SP);
    check('start: a live run inside the window still makes the project busy',
        $again['ok'] === false && $again['busy'] === true
        && $store->run($SP, $live)['terminal'] === null);
}

// ============================================================================
// THE PASSES (wave 10, B6). ScanMaintenance over the real store, with the
// settings as two arrays and, where a test needs the budget to run out, a clock
// that moves a second each time it is read.
// ============================================================================

require_once __DIR__ . '/../../../php/Scan/ScanMaintenance.php';

$SYS = array('scan-system-max-concurrent-projects' => '2', 'scan-system-stale-run-hours' => '24');
$PRJ = array();
$policyOf = function ($pid) use (&$SYS, &$PRJ) {
    return \INSPIRE\UniversalValidator\Scan\ScanPolicy::resolve($SYS,
        $pid === null ? array() : (isset($PRJ[(int) $pid]) ? $PRJ[(int) $pid] : array()));
};
$logs = array();
$maint = function (array $extra = array()) use ($dbA, $policyOf, &$logs) {
    return new \INSPIRE\UniversalValidator\Scan\ScanMaintenance($dbA, $extra + array(
        'policy' => $policyOf,
        'log' => function ($event, array $ctx) use (&$logs) { $logs[] = array($event, $ctx); },
    ));
};
/** A clock that moves $step seconds every time it is read. */
$ticking = function ($step = 1.0) {
    $t = 0.0;
    return function () use (&$t, $step) { return $t += $step; };
};

/**
 * A run of $pid with $n findings on one record, each with a stored value
 * expiring at $expires (null: none), finished complete unless $finish is false.
 */
$plant = function ($pid, $n, $expires = null, $finish = true, array $runOpts = array()) use ($dbA) {
    $store = new SqlScanStore($dbA);
    $r = $store->startRun($pid, $runOpts + array('created_by' => 'alice'));
    if ($r['ok'] !== true) return null;
    $rid = (int) $r['run']['run_id'];
    $gen = (int) $r['run']['generation_id'];
    $id = 'C' . $pid . '-' . $rid;
    $store->writeManifest($rid, array(array('id_bin' => $id, 'hash' => hash('sha256', $id, true), 'dag' => null)));
    $ep = (int) $store->run($pid, $rid)['lease_epoch'];
    $c = $store->claim($rid, 'w', $ep, 1);
    $fs = array();
    for ($i = 0; $i < $n; $i++) {
        $fs[] = array('ordinal' => $c[0]['ordinal'], 'project_id' => $pid, 'generation_id' => $gen,
            'identity' => hash('sha256', $id . '-' . $i, true),
            'record_hash' => $c[0]['hash'], 'record_id_bin' => $id,
            'host_form' => 'fa', 'field' => 'x' . $i, 'rule_source_id' => 'r1',
            'rule_revision' => str_repeat('c', 64), 'check_type' => 'required',
            'reason_code' => 'required-blank',
            'value_bin' => $expires === null ? null : 'SECRET-' . $i,
            'value_fingerprint' => $expires === null ? null : hash('sha256', 'v' . $i, true),
            'value_expires_at' => $expires);
    }
    $ok = $store->commitBatch($rid, 'w', $ep, array('bytes' => 0, 'findings' => $fs, 'records' => array(
        array('ordinal' => $c[0]['ordinal'], 'record_hash' => $c[0]['hash'], 'state' => ScanStore::REC_DONE,
              'claim' => $c[0]['claim']))));
    if ($ok !== true) return null;
    if ($finish) {
        $store->finish($rid, ScanOutcome::derive(array('fenced' => true, 'manifestDone' => true, 'violations' => 1)));
    }
    return array('run' => $rid, 'gen' => $gen, 'seq' => uv_run_seq($dbA, $rid));
};
$countWhere = function ($where, array $params) use ($dbA) {
    $r = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding') . ' WHERE ' . $where, $params);
    return (int) $r[0][0];
};
$runRow = function ($runId) use ($dbA) {
    $r = $dbA->select('SELECT terminal, supersede_cursor, generation_id, policy_revision, values_state, updated_at FROM '
        . Schema::table('scan_run') . ' WHERE run_id = ?', array($runId));
    return isset($r[0]) ? $r[0] : null;
};

// -- THE REAP PASS: stale runs, slots above the limit, previous reports --------
{
    $A->query('DELETE FROM ' . Schema::table('scan_worker_slot'));
    (new \INSPIRE\UniversalValidator\Scan\WorkerSlots($dbA))->provision(5);

    // 971: a run abandoned for thirty hours.
    $stale = $plant(971, 1, null, false);
    $A->query('UPDATE ' . Schema::table('scan_run') . " SET created_at = '" . gmdate('Y-m-d H:i:s', time() - 30 * 3600)
        . "', progress_at = '" . gmdate('Y-m-d H:i:s', time() - 30 * 3600) . "', lease_expires_at = NULL"
        . ' WHERE run_id = ' . (int) $stale['run']);
    // 972: three completed whole-project scans, each with two findings.
    $g1 = $plant(972, 2);
    $g2 = $plant(972, 2);
    $g3 = $plant(972, 2);
    // 973: a whole-project scan, then one of a single group, both complete.
    $w1 = $plant(973, 2);
    $s2 = $plant(973, 1, null, true, array('scope_dag' => '7'));

    $logs = array();
    $rec = new UvRecordingDb($dbA);
    $mt = new \INSPIRE\UniversalValidator\Scan\ScanMaintenance($rec, array('policy' => $policyOf,
        'log' => function ($e, array $c) use (&$logs) { $logs[] = array($e, $c); }));
    $r = $mt->reap(array(), 60.0);
    echo '  reap pass: ' . json_encode($r['out']) . "\n";
    check('reap pass: finished in one pass, the lap complete', $r['out']['more'] === 0 && $r['state']['after'] === 0);
    check('reap pass: the abandoned run is expired', $runRow($stale['run'])[0] === ScanOutcome::EXPIRED
        && $r['out']['runs_expired'] === 1);
    check('reap pass: the free slots above the lowered limit are gone (W1-N1)',
        $r['out']['slots_removed'] === 3
        && (int) $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_worker_slot'))[0][0] === 2);
    check('reap pass: the older generations of the completed scans are closed, the newest stays current',
        $countWhere('project_id = ? AND generation_id = ? AND active_slot = 1', array(972, $g1['gen'])) === 0
        && $countWhere('project_id = ? AND generation_id = ? AND active_slot = 1', array(972, $g2['gen'])) === 0
        && $countWhere('project_id = ? AND generation_id = ? AND active_slot = 1', array(972, $g3['gen'])) === 2);
    // OLDEST FIRST: each run closes what it superseded with its own sequence,
    // so an "as of" reading sees generation 1 end at run 2 and 2 end at run 3.
    check('reap pass: oldest run first, so each generation ends at the run that followed it',
        $countWhere('project_id = ? AND generation_id = ? AND valid_to_seq = ?', array(972, $g1['gen'], $g2['seq'])) === 2
        && $countWhere('project_id = ? AND generation_id = ? AND valid_to_seq = ?', array(972, $g2['gen'], $g3['seq'])) === 2);
    check('reap pass: every completed run\'s supersede is finished, its cursor at its own generation',
        (int) $runRow($g2['run'])[1] === $g2['gen'] && (int) $runRow($g3['run'])[1] === $g3['gen']);
    // R8-1: a group's scan supersedes nothing, and is not listed to try.
    check('reap pass: a scan of one group closes nothing of the whole-project scan before it',
        $countWhere('project_id = ? AND generation_id = ? AND active_slot = 1', array(973, $w1['gen'])) === 2
        && (int) $runRow($s2['run'])[1] === 0);
    $fenced = $rec->matching('select', array('supersede_cursor', 'FOR UPDATE'));
    $askedScoped = false;
    foreach ($fenced as $f) if ((int) $f[2][0] === $s2['run']) $askedScoped = true;
    check('reap pass: and the group\'s scan is never asked to supersede', !$askedScoped);
    $rec->log = array();
    $again = $mt->reap(array(), 60.0);
    check('reap pass: a second pass has nothing to do and sends no supersede',
        $again['out']['superseded'] === 0 && $again['out']['runs_expired'] === 0
        && $rec->matching('select', array('supersede_cursor', 'FOR UPDATE')) === array());
    check('reap pass: nothing failed', $r['out']['failed'] === 0 && $again['out']['failed'] === 0);
}

// -- THE RETENTION PASS: revocation, purge, the value sweep ---------------------
{
    // The projects above are done with; the lap below starts at the first of
    // these, so the ticking clock's arithmetic is about them alone.
    foreach (array(970, 971, 972, 973) as $p) uv_clear_project($dbA, $p);
    $old = gmdate('Y-m-d H:i:s', time() - 20 * 86400);
    // 980: kept raw values under a 30-day window; the project now keeps none.
    $raw = array('values_state' => 'raw', 'policy_json' => json_encode(array('valueMode' => 'raw', 'valueDays' => 30)));
    $v1 = $plant(980, 3, '2999-01-01 00:00:00', true, $raw);
    $v2 = $plant(980, 3, '2999-01-01 00:00:00', true, $raw);
    // Finished twenty days ago, inside the default ninety: its age must survive the bump.
    $A->query('UPDATE ' . Schema::table('scan_run') . " SET updated_at = '" . $old . "' WHERE run_id = " . (int) $v1['run']);
    $PRJ[980] = array('scan-value-storage' => 'locations');
    // 981: kept raw values, and still allowed to.
    $k1 = $plant(981, 2, '2999-01-01 00:00:00', true, $raw);
    $PRJ[981] = array('scan-value-storage' => 'raw');
    // 982: a finished run twenty days old, a project that keeps runs ten.
    $p1 = $plant(982, 4);
    $p2 = $plant(982, 1);
    $A->query('UPDATE ' . Schema::table('scan_run') . " SET updated_at = '" . $old . "' WHERE run_id = " . (int) $p1['run']);
    $PRJ[982] = array('scan-run-retention-days' => '10');
    // 983: as old, in a project that keeps them the default ninety days.
    $q1 = $plant(983, 1);
    $A->query('UPDATE ' . Schema::table('scan_run') . " SET updated_at = '" . $old . "' WHERE run_id = " . (int) $q1['run']);
    // 984: overdue previews, for the installation-wide sweep.
    $e1 = $plant(984, 3, '2000-01-01 00:00:00');

    // FIRST PASS, cut short after a few units, so the revocation is left part
    // way: two generations, one UPDATE per call.
    $states = array();
    $mt = $maint(array('clock' => $ticking(1.0), 'save' => function (array $s) use (&$states) { $states[] = $s; }));
    $r1 = $mt->retain(array(), 5.0);
    echo '  retain pass 1: ' . json_encode($r1['out']) . ' state=' . json_encode($r1['state']) . "\n";
    $bumped = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_run')
        . " WHERE project_id = ? AND values_state = 'expired'", array(980));
    check('revoke: a project that now keeps less than its runs kept has them revoked at once',
        (int) $bumped[0][0] === 2 && $r1['out']['runs_revoked'] === 2);
    check('revoke: the intent was saved before the bump, and the bump after it (checkpoints)',
        count($states) >= 2 && $states[0]['revoke'][980]['bumped'] === false
        && $states[1]['revoke'][980]['bumped'] === true);
    $cur = isset($r1['state']['revoke'][980]['cursor']) ? $r1['state']['revoke'][980]['cursor'] : null;
    check('revoke: a revocation the budget stopped keeps its WHOLE cursor, project and digest included (R8-3)',
        is_array($cur) && isset($cur['pid'], $cur['set'], $cur['gen'], $cur['slot'], $cur['after'])
        && (int) $cur['pid'] === 980);
    check('revoke: and its project is not purged while it runs', $r1['out']['runs_purged'] === 0);
    check('revoke: the bump leaves a run\'s age alone, so a tightening does not postpone its purge',
        $runRow($v1['run'])[5] === $old && $runRow($v1['run'])[4] === 'expired');
    check('revoke: a project still allowed what it kept is left alone',
        $countWhere('project_id = ? AND value_bin IS NOT NULL', array(981)) === 2
        && $runRow($k1['run'])[4] === 'raw');

    // A run started between passes, under the policy now in force, keeps what
    // it stores: the next pass only clears, it does not bump again.
    $v3 = $plant(980, 1, '2999-01-01 00:00:00', true, array('values_state' => 'locations',
        'policy_json' => json_encode(array('valueMode' => 'locations', 'valueDays' => 30))));
    $r2 = $maint()->retain($r1['state'], 60.0);
    echo '  retain pass 2: ' . json_encode($r2['out']) . "\n";
    check('revoke: the next pass carries the clearing on from the saved cursor, and finishes',
        !isset($r2['state']['revoke'][980]) && $r2['out']['runs_revoked'] === 0
        && $countWhere('project_id = ? AND generation_id IN (?, ?) AND value_bin IS NOT NULL',
            array(980, $v1['gen'], $v2['gen'])) === 0);
    check('revoke: without bumping the run started since',
        $runRow($v3['run'])[4] === 'locations'
        && $countWhere('project_id = ? AND generation_id = ? AND value_bin IS NOT NULL', array(980, $v3['gen'])) === 1);
    check('revoke: findings stay, only their values go', $countWhere('project_id = ?', array(980)) === 7);

    check('purge: a finished run past its project\'s retention is removed with everything it owned',
        $runRow($p1['run']) === null && $countWhere('project_id = ? AND generation_id = ?', array(982, $p1['gen'])) === 0);
    check('purge: the project\'s newer run stays', $runRow($p2['run']) !== null
        && $countWhere('project_id = ? AND generation_id = ?', array(982, $p2['gen'])) === 1);
    check('purge: the same age in a project that keeps runs longer is kept', $runRow($q1['run']) !== null);
    check('expire: overdue previews are cleared by the sweep, the findings kept',
        $countWhere('project_id = ? AND value_bin IS NOT NULL', array(984)) === 0
        && $countWhere('project_id = ?', array(984)) === 3);
    check('expire: the finished sweep is remembered', isset($r2['state']['sweep']['finished'])
        && $r2['state']['sweep']['top'] === null);
    // Within a day of a finished sweep, the next pass does not walk the table.
    $rec = new UvRecordingDb($dbA);
    $mt3 = new \INSPIRE\UniversalValidator\Scan\ScanMaintenance($rec, array('policy' => $policyOf));
    $r3 = $mt3->retain($r2['state'], 60.0);
    check('expire: a sweep finished today is not started again until a day has passed',
        $rec->matching('exec', array('value_bin = NULL', 'finding_id > ?')) === array());
    $r4 = $mt3->retain(array('sweep' => array('cursor' => 0, 'top' => null, 'finished' => time() - 90000)), 60.0);
    check('expire: and is started again after it', count($rec->matching('exec', array('value_bin = NULL', 'finding_id > ?'))) >= 1);
    check('retain: nothing failed', $r1['out']['failed'] === 0 && $r2['out']['failed'] === 0 && $r3['out']['failed'] === 0);

    // A PROJECT'S PURGE WAITS FOR ITS REVOCATION (R8-3). The continuation of
    // 986's revocation fails this pass, so the lap reaches 986 with budget in
    // hand and a run past retention: purging it would change the generations
    // the stored cursor's digest names, and the revocation would start again.
    $w1 = $plant(986, 2);
    $A->query('UPDATE ' . Schema::table('scan_run') . " SET updated_at = '" . $old . "', values_state = 'expired'"
        . ' WHERE run_id = ' . (int) $w1['run']);
    $PRJ[986] = array('scan-run-retention-days' => '10');
    $hook = new UvHookDb($dbA);
    $hook->before = function ($kind, $sql, array $params) {
        if (strpos($sql, 'SELECT DISTINCT generation_id') !== false && (int) $params[0] === 986) {
            throw new \RuntimeException('the revocation read failed');
        }
    };
    $logs = array();
    $mtw = new \INSPIRE\UniversalValidator\Scan\ScanMaintenance($hook, array('policy' => $policyOf,
        'log' => function ($e, array $c) use (&$logs) { $logs[] = array($e, $c); }));
    $wait = $mtw->retain(array('revoke' => array(986 => array('revision' => 2, 'bumped' => true, 'cursor' => null))), 60.0);
    $said = array();
    foreach ($logs as $l) if ($l[0] === 'scan-cron-step-failed') $said[] = $l[1]['step'] . ':' . $l[1]['project_id'];
    check('purge: a project whose revocation is unfinished is not purged, though its run is past retention',
        $runRow($w1['run']) !== null && $wait['out']['runs_purged'] === 0 && isset($wait['state']['revoke'][986])
        && $said === array('revoke:986'));
    $done = $maint()->retain($wait['state'], 60.0);
    check('purge: and is purged by the pass that finishes the revocation',
        !isset($done['state']['revoke'][986]) && $runRow($w1['run']) === null && $done['out']['failed'] === 0);
}

// -- SUPERSEDE BESIDE A LIVE COMMIT, UNDER REPEATABLE READ (W8-N5) -------------
//
// The cron closes a completed run's older generations while the project's next
// scan is committing batches into its own. Both write uv_finding rows of one
// project. The store says the two never wait on each other's rows and meet only
// at the next-key lock that ends a closed range; this measures that, on two
// connections, both at REPEATABLE READ whatever level the harness runs at.
//
// Each meeting is the real statements of one side with its COMMIT held, and the
// real call of the other side with a one-second lock wait. What is asserted is
// what must hold whatever the server decides: neither side is left half done,
// and the side that gave up finishes when it asks again.
{
    $levels = array('READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE', 'READ UNCOMMITTED');
    $isoRead = function ($c) {
        foreach (array('@@transaction_isolation', '@@tx_isolation') as $v) {
            try { $r = $c->query('SELECT ' . $v); } catch (\Throwable $e) { continue; }
            if ($r instanceof \mysqli_result) {
                $row = $r->fetch_row();
                return strtoupper(str_replace('-', ' ', (string) $row[0]));
            }
        }
        return null;
    };
    $wasA = $isoRead($A);
    $wasB = $isoRead($B);
    foreach (array($A, $B) as $c) $c->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    check('w8-n5: both connections are at REPEATABLE READ for the measurement',
        $isoRead($A) === 'REPEATABLE READ' && $isoRead($B) === 'REPEATABLE READ');

    /** The next scan of $pid, started, its one record claimed, its batch built. */
    $liveBatch = function ($pid, $n) use ($dbA) {
        $store = new SqlScanStore($dbA);
        $r = $store->startRun($pid, array('created_by' => 'alice'));
        $rid = (int) $r['run']['run_id'];
        $gen = (int) $r['run']['generation_id'];
        $id = 'L' . $pid;
        $store->writeManifest($rid, array(array('id_bin' => $id, 'hash' => hash('sha256', $id, true), 'dag' => null)));
        $ep = (int) $store->run($pid, $rid)['lease_epoch'];
        $c = $store->claim($rid, 'w', $ep, 1);
        $fs = array();
        for ($i = 0; $i < $n; $i++) {
            $fs[] = array('ordinal' => $c[0]['ordinal'], 'project_id' => $pid, 'generation_id' => $gen,
                'identity' => hash('sha256', 'live-' . $id . '-' . $i, true),
                'record_hash' => $c[0]['hash'], 'record_id_bin' => $id,
                'host_form' => 'fa', 'field' => 'y' . $i, 'rule_source_id' => 'r1',
                'rule_revision' => str_repeat('c', 64), 'check_type' => 'required',
                'reason_code' => 'required-blank');
        }
        return array('run' => $rid, 'gen' => $gen, 'ep' => $ep, 'n' => $n,
            'batch' => array('bytes' => 0, 'findings' => $fs, 'records' => array(
                array('ordinal' => $c[0]['ordinal'], 'record_hash' => $c[0]['hash'],
                      'state' => ScanStore::REC_DONE, 'claim' => $c[0]['claim']))));
    };
    $commit = function (\INSPIRE\UniversalValidator\Scan\ScanDb $db, array $live) {
        try {
            // A batch the server would not take comes back as the refusal's
            // text, which is how the worker learns to hand its claim back.
            $r = (new SqlScanStore($db))->commitBatch($live['run'], 'w', $live['ep'], $live['batch']);
            return $r === true ? 'ok' : 'refused: ' . (string) $r;
        } catch (\INSPIRE\UniversalValidator\Scan\ScanStoreUnavailable $e) {
            return 'unavailable: ' . $e->safeDetail();
        }
    };
    $supersede = function (\INSPIRE\UniversalValidator\Scan\ScanDb $db, $pid, $runId) {
        try {
            $p = (new SqlScanStore($db))->supersedePrevious($pid, $runId, ScanStore::PAGE);
            return $p['done'] ? 'ok' : 'not done: ' . json_encode($p);
        } catch (\INSPIRE\UniversalValidator\Scan\ScanStoreUnavailable $e) {
            return 'unavailable: ' . $e->safeDetail();
        }
    };
    $ms = function ($t) { return (int) round((microtime(true) - $t) * 1000); };

    // Four meetings: which side holds its commit, and whether the completed
    // run's own generation has rows (it ends the closed range in ix_page_v2)
    // or is empty (the range then ends at the live scan's rows, or past them).
    $meetings = array(
        array(990, 'commit held', 2), array(992, 'commit held', 0),
        array(994, 'supersede held', 2), array(996, 'supersede held', 0),
    );
    $measured = array();
    foreach ($meetings as $mt) {
        list($pid, $held, $mRows) = $mt;
        $g1 = $plant($pid, 3);
        $m = $plant($pid, $mRows);
        $live = $liveBatch($pid, 2);
        if ($held === 'commit held') {
            $hb = new UvHeldCommitDb($dbB);
            $first = $commit($hb, $live);
            $A->query('SET SESSION innodb_lock_wait_timeout = 1');
            $t = microtime(true);
            $second = $supersede($dbA, $pid, $m['run']);
            $waited = $ms($t);
            $A->query('SET SESSION innodb_lock_wait_timeout = DEFAULT');
            $hb->release();
            $loser = $second === 'ok' ? null : 'supersede';
        } else {
            $ha = new UvHeldCommitDb($dbA);
            $first = $supersede($ha, $pid, $m['run']);
            $B->query('SET SESSION innodb_lock_wait_timeout = 1');
            $t = microtime(true);
            $second = $commit($dbB, $live);
            $waited = $ms($t);
            $B->query('SET SESSION innodb_lock_wait_timeout = DEFAULT');
            $ha->release();
            $loser = $second === 'ok' ? null : 'commit';
        }
        $line = $held . ', completed run with ' . $mRows . ' rows: first ' . $first . '; second ' . $second
            . ' after ' . $waited . ' ms';
        echo '  w8-n5: ' . $line . "\n";
        $measured[] = array($pid, $held, $mRows, $loser, $waited);

        // NOTHING HALF DONE. The side that gave up wrote nothing.
        $g1Open = $countWhere('project_id = ? AND generation_id = ? AND active_slot = 1', array($pid, $g1['gen']));
        $liveRows = $countWhere('project_id = ? AND generation_id = ? AND active_slot = 1', array($pid, $live['gen']));
        $cursor = (int) $runRow($m['run'])[1];
        check('w8-n5 (' . $line . '): the held side finished', $first === 'ok');
        check('w8-n5 (' . $held . ', ' . $mRows . ' rows): neither side is left half done',
            ($loser === 'supersede' ? ($g1Open === 3 && $cursor < $m['gen']) : ($g1Open === 0 && $cursor === $m['gen']))
            && ($loser === 'commit' ? $liveRows === 0 : $liveRows === 2));
        // AND THE ONE THAT GAVE UP FINISHES WHEN IT ASKS AGAIN: the next pass,
        // or the worker's retry of the same claim.
        $retry = $loser === 'supersede' ? $supersede($dbA, $pid, $m['run'])
            : ($loser === 'commit' ? $commit($dbB, $live) : 'ok');
        check('w8-n5 (' . $held . ', ' . $mRows . ' rows): the side that gave up finishes when asked again',
            $retry === 'ok'
            && $countWhere('project_id = ? AND generation_id = ? AND active_slot = 1', array($pid, $g1['gen'])) === 0
            && $countWhere('project_id = ? AND generation_id = ? AND valid_to_seq = ?', array($pid, $g1['gen'], $m['seq'])) === 3
            && $countWhere('project_id = ? AND generation_id = ? AND active_slot = 1', array($pid, $live['gen'])) === 2);
    }
    foreach (array(990, 992, 994, 996) as $p) uv_clear_project($dbA, $p);

    // -- A DEADLOCK IS RETRY-NEXT-PASS, never a failed cron ----------------------
    //
    // A real one, made on purpose. B changes the older generation's rows of 998
    // (so InnoDB picks the cron as the lighter victim), the cron's supersede
    // takes 998's run row, and just before its page UPDATE B asks for that run
    // row too: B waits on the cron, the cron then waits on B, and the server
    // ends the cycle. 999 is due as well and comes after 998 in the lap.
    $d1 = $plant(998, 3);
    $dm = $plant(998, 1);
    $o1 = $plant(999, 2);
    $om = $plant(999, 1);
    $B->query('START TRANSACTION');
    $B->query('UPDATE ' . Schema::table('finding') . " SET value_expires_at = '2001-01-01 00:00:00'"
        . ' WHERE project_id = 998 AND generation_id = ' . (int) $d1['gen']);
    $fired = false;
    $hook = new UvHookDb($dbA);
    $hook->before = function ($kind, $sql, array $params) use (&$fired, $B, $dm) {
        if ($fired || $kind !== 'exec' || strpos($sql, 'ix_page_v2') === false || (int) $params[1] !== 998) return;
        $fired = true;
        $B->query('SELECT run_id FROM ' . Schema::table('scan_run') . ' WHERE run_id = ' . (int) $dm['run']
            . ' FOR UPDATE', MYSQLI_ASYNC);
        usleep(300000);     // B's request is queued behind the cron's lock
    };
    $logs = array();
    $mt = new \INSPIRE\UniversalValidator\Scan\ScanMaintenance($hook, array('policy' => $policyOf,
        'log' => function ($e, array $c) use (&$logs) { $logs[] = array($e, $c); }));
    $threw = null;
    $t = microtime(true);
    try {
        $r = $mt->reap(array(), 60.0);
    } catch (\Throwable $e) {
        $threw = get_class($e);
        $r = null;
    }
    $took = $ms($t);
    // B now has the run row the cron gave back; let it go.
    $bGot = null;
    $links = array($B); $errs = array($B); $rej = array($B);
    if (\mysqli::poll($links, $errs, $rej, 5) > 0) {
        try { $res = $B->reap_async_query(); $bGot = $res instanceof \mysqli_result ? 'the run row' : 'nothing'; }
        catch (\Throwable $e) { $bGot = 'its own error: ' . $e->getMessage(); }
    }
    $B->query('ROLLBACK');
    $failedLog = null;
    foreach ($logs as $l) {
        if ($l[0] === 'scan-cron-step-failed' && (int) $l[1]['project_id'] === 998) $failedLog = $l[1];
    }
    echo '  w8-n5 deadlock: pass ' . json_encode($r === null ? $threw : $r['out']) . ' in ' . $took
        . ' ms; B got ' . json_encode($bGot) . '; logged ' . json_encode($failedLog) . "\n";
    check('w8-n5: the pass itself does not fail when one project deadlocks', $threw === null && $r !== null);
    check('w8-n5: the deadlock is logged for that project, redacted, as retry next pass',
        $failedLog !== null && $failedLog['retry'] === 'next pass' && $r['out']['failed'] === 1
        && stripos((string) $failedLog['detail'], 'deadlock') !== false);
    check('w8-n5: the deadlocked project closed nothing, its cursor where it was',
        $countWhere('project_id = ? AND generation_id = ? AND active_slot = 1', array(998, $d1['gen'])) === 3
        && (int) $runRow($dm['run'])[1] < $dm['gen']);
    check('w8-n5: the project after it in the lap was still superseded',
        $countWhere('project_id = ? AND generation_id = ? AND active_slot = 1', array(999, $o1['gen'])) === 0
        && (int) $runRow($om['run'])[1] === $om['gen']);
    $next = $maint()->reap(array(), 60.0);
    check('w8-n5: the next pass finishes the project that deadlocked',
        $next['out']['failed'] === 0
        && $countWhere('project_id = ? AND generation_id = ? AND active_slot = 1', array(998, $d1['gen'])) === 0
        && (int) $runRow($dm['run'])[1] === $dm['gen']);

    foreach (array(array($A, $wasA), array($B, $wasB)) as $pair) {
        if (in_array($pair[1], $levels, true)) {
            $pair[0]->query('SET SESSION TRANSACTION ISOLATION LEVEL ' . $pair[1]);
        }
    }
    check('w8-n5: both connections are back at the level the harness set',
        $isoRead($A) === $wasA && $isoRead($B) === $wasB);
    foreach (array(998, 999) as $p) uv_clear_project($dbA, $p);
}
