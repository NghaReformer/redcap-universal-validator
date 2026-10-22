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

    /** A user of the project with no design rights, who may not scan. */
    class UvCronPlainUser
    {
        public function hasDesignRights() { return false; }
        public function getRights($pid = null)
        {
            return array('design' => '0', 'data_export_tool' => '0', 'group_id' => null,
                         'forms' => array('demographics' => '1'));
        }
        public function getUsername() { return 'mallory'; }
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
        /** @var object|null who is signed in; the designer when null */
        public $user = null;

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
        public function getUser() { return $this->user !== null ? $this->user : new UvCronUser(); }
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
    // FIRST, SOMEBODY WHO MAY NOT SCAN PRESSES START (review rv10a2-2): they
    // are refused, and the run is left for somebody who may.
    $m->user = new UvCronPlainUser();
    $refused = (new ScanService($m))->start($SP);
    $m->user = null;
    $kept = $store->run($SP, $oldId);
    $byMallory = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_audit')
        . ' WHERE project_id = ? AND run_id = ?', array($SP, $oldId));
    check('start: a user without design rights is refused before the abandoned run is touched',
        $refused['ok'] === false && $kept['terminal'] === null && (int) $kept['lease_epoch'] === $oldEpoch
        && (int) $byMallory[0][0] === 0);
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
    // 974: its last record finished thirty hours ago and its finalizer touched
    // it ten seconds ago (review rv10a1-1). The status page calls it working;
    // the census must not name it.
    $fin = $plant(974, 1, null, false);
    $A->query('UPDATE ' . Schema::table('scan_run') . " SET phase = 'unique-finalize', progress_at = '"
        . gmdate('Y-m-d H:i:s', time() - 30 * 3600) . "', updated_at = '" . gmdate('Y-m-d H:i:s', time() - 10)
        . "', lease_expires_at = '" . gmdate('Y-m-d H:i:s', time() - 29 * 3600) . "' WHERE run_id = " . (int) $fin['run']);
    // 975: stopped by its user while a worker held it, and the worker never
    // came back (review rv10a1-2). The pass ends it as cancelled, as Stop
    // would have; an expiry would say it was abandoned.
    $cx = $plant(975, 1, null, false);
    (new SqlScanStore($dbA))->cancel(975, $cx['run'], 'alice');
    $A->query('UPDATE ' . Schema::table('scan_run') . " SET progress_at = '" . gmdate('Y-m-d H:i:s', time() - 30 * 3600)
        . "', lease_expires_at = NULL WHERE run_id = " . (int) $cx['run']);

    $logs = array();
    $rec = new UvRecordingDb($dbA);
    $mt = new \INSPIRE\UniversalValidator\Scan\ScanMaintenance($rec, array('policy' => $policyOf,
        'log' => function ($e, array $c) use (&$logs) { $logs[] = array($e, $c); }));
    $r = $mt->reap(array(), 60.0);
    echo '  reap pass: ' . json_encode($r['out']) . "\n";
    check('reap pass: finished in one pass, the lap complete', $r['out']['more'] === 0 && $r['state']['after'] === 0);
    check('reap pass: the abandoned run is expired', $runRow($stale['run'])[0] === ScanOutcome::EXPIRED
        && $r['out']['runs_expired'] === 1);
    $named974 = array_filter($rec->matching('select', array('SELECT run_id FROM', 'lease_expires_at')),
        function ($s) { return in_array(974, $s[2], true); });
    check('reap pass: a run finalising now is not named by the census, however old its last record',
        $runRow($fin['run'])[0] === null && $named974 === array());
    $cxRow = (new SqlScanStore($dbA))->run(975, $cx['run']);
    $cxExpired = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_audit')
        . ' WHERE project_id = ? AND event = ?', array(975, ScanStore::AUDIT_EXPIRE));
    check('reap pass: a stale run its user stopped ends as cancelled with what it found counted, never as abandoned',
        $cxRow['terminal'] === ScanOutcome::CANCELLED && $r['out']['runs_stopped'] === 1
        && strpos((string) $cxRow['terminal_reason'], 'abandoned') === false
        && (int) $cxRow['manifest_done'] === 1 && (int) $cxExpired[0][0] === 0);
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
    // THE CENSUS DOES NOT NAME A GROUP'S SCAN (review rv10a1-4). 973's scoped
    // run completed with its cursor below its generation, and always will:
    // it supersedes nothing. Named by the census, it would cost a project
    // visit and a list read on every pass, for ever.
    check('reap pass: and a completed scan of one group is no project\'s work in the census',
        $again['out']['projects'] === 0 && count($rec->log) === 2
        && $rec->matching('select', array('scope_dag IS NULL', 'ORDER BY generation_id')) === array());
    check('reap pass: nothing failed', $r['out']['failed'] === 0 && $again['out']['failed'] === 0);
}

// -- AN EXPIRY AND ITS AUDIT ARE ONE WRITE (review rv10a1-5) --------------------
//
// The audit insert fails once. The expiry goes back with it, so the run is
// still stale for the next pass, which writes both; before, the run stayed
// expired with no record of who expired it, and no pass ever wrote one.
{
    $ab = $plant(976, 1, null, false);
    $A->query('UPDATE ' . Schema::table('scan_run') . " SET progress_at = '" . gmdate('Y-m-d H:i:s', time() - 30 * 3600)
        . "', lease_expires_at = NULL WHERE run_id = " . (int) $ab['run']);
    $abStore = new SqlScanStore($dbA);
    $abEpoch = (int) $abStore->run(976, $ab['run'])['lease_epoch'];
    $failAudit = true;
    $hook = new UvHookDb($dbA);
    $hook->before = function ($kind, $sql, array $params) use (&$failAudit) {
        if ($failAudit && $kind === 'exec' && strpos($sql, 'INSERT INTO ' . Schema::table('scan_audit')) !== false) {
            throw new \RuntimeException('Lock wait timeout exceeded; try restarting transaction', 1205);
        }
    };
    $expireAudits = function () use ($dbA, $ab) {
        return (int) $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_audit')
            . ' WHERE project_id = ? AND run_id = ? AND event = ?', array(976, $ab['run'], ScanStore::AUDIT_EXPIRE))[0][0];
    };
    $logs = array();
    $f1 = (new \INSPIRE\UniversalValidator\Scan\ScanMaintenance($hook, array('policy' => $policyOf,
        'log' => function ($e, array $c) use (&$logs) { $logs[] = array($e, $c); })))->reap(array(), 60.0);
    $abRow = $abStore->run(976, $ab['run']);
    check('expire: an audit that fails takes the expiry back with it, and the pass counts a failure, not an expiry',
        $abRow['terminal'] === null && (int) $abRow['lease_epoch'] === $abEpoch && $expireAudits() === 0
        && $f1['out']['runs_expired'] === 0 && $f1['out']['failed'] === 1);
    $failAudit = false;
    $f2 = $maint()->reap(array(), 60.0);
    check('expire: and the next pass writes the expiry and its audit together',
        $abStore->run(976, $ab['run'])['terminal'] === ScanOutcome::EXPIRED && $expireAudits() === 1
        && $f2['out']['runs_expired'] === 1);
}

// -- THE WRITE ASKS THE READ'S QUESTION AGAIN (review rv10a2-6) -----------------
//
// The run's worker comes back between expireAbandoned()'s read and its write:
// on the second connection it commits progress (977), or renews its lease
// without progress (978). The UPDATE carries the whole predicate again, so
// neither run is touched and nothing is audited.
{
    $races = array(
        977 => 'progress_at = UTC_TIMESTAMP()',
        978 => "lease_owner = 'w-back', lease_expires_at = UTC_TIMESTAMP() + INTERVAL 5 MINUTE",
    );
    foreach ($races as $rp => $back) {
        $rr = $plant($rp, 1, null, false);
        $A->query('UPDATE ' . Schema::table('scan_run') . " SET progress_at = '" . gmdate('Y-m-d H:i:s', time() - 30 * 3600)
            . "', lease_expires_at = NULL WHERE run_id = " . (int) $rr['run']);
        $hook = new UvHookDb($dbA);
        $hook->before = function ($kind, $sql, array $params) use ($B, $rr, $back) {
            if ($kind === 'exec' && strpos($sql, 'lease_epoch = lease_epoch + 1') !== false) {
                $B->query('UPDATE ' . Schema::table('scan_run') . ' SET ' . $back . ' WHERE run_id = ' . (int) $rr['run']);
            }
        };
        $n = (new SqlScanStore($hook))->expireAbandoned($rp, 24, 'cron');
        $after = (new SqlScanStore($dbA))->run($rp, $rr['run']);
        $aud = (int) $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_audit')
            . ' WHERE project_id = ? AND event = ?', array($rp, ScanStore::AUDIT_EXPIRE))[0][0];
        check('expire race (' . ($rp === 977 ? 'progress' : 'lease') . '): a run whose worker came back between the read '
            . 'and the write is left alone, and nothing is audited',
            $n === 0 && $after['terminal'] === null && $aud === 0);
    }
}

// -- THE RETENTION PASS: revocation, purge, the value sweep ---------------------
{
    // The projects above are done with; the lap below starts at the first of
    // these, so the ticking clock's arithmetic is about them alone.
    foreach (array(970, 971, 972, 973, 974, 975, 976, 977, 978) as $p) uv_clear_project($dbA, $p);
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
    // 987: the intent of a revocation was stored and its bump fails this pass,
    // so the lap reaches 987 with its run still keeping raw values under a
    // policy that now keeps none (review rv10a1-4). Judged again, it would
    // store a second intent over the first, log a second revocation and fail
    // a second time; the lap sends nothing for it.
    $x1 = $plant(987, 1, '2999-01-01 00:00:00', true, $raw);
    $PRJ[987] = array('scan-value-storage' => 'locations');
    $hook = new UvHookDb($dbA);
    $hook->before = function ($kind, $sql, array $params) {
        if (strpos($sql, 'SELECT DISTINCT generation_id') !== false && (int) $params[0] === 986) {
            throw new \RuntimeException('the revocation read failed');
        }
        if (strpos($sql, 'SET policy_revision = ?') !== false && in_array(987, $params, true)) {
            throw new \RuntimeException('the revocation bump failed');
        }
    };
    $rec = new UvRecordingDb($hook);
    $logs = array();
    $mtw = new \INSPIRE\UniversalValidator\Scan\ScanMaintenance($rec, array('policy' => $policyOf,
        'log' => function ($e, array $c) use (&$logs) { $logs[] = array($e, $c); }));
    $intent987 = array('revision' => 2, 'bumped' => false, 'cursor' => null);
    $wait = $mtw->retain(array('revoke' => array(986 => array('revision' => 2, 'bumped' => true, 'cursor' => null),
        987 => $intent987)), 60.0);
    $said = array();
    $revoked = 0;
    foreach ($logs as $l) {
        if ($l[0] === 'scan-cron-step-failed') $said[] = $l[1]['step'] . ':' . $l[1]['project_id'];
        if ($l[0] === 'scan-cron-revoke') $revoked++;
    }
    check('purge: a project whose revocation is unfinished is not purged, though its run is past retention',
        $runRow($w1['run']) !== null && $wait['out']['runs_purged'] === 0 && isset($wait['state']['revoke'][986])
        && $said === array('revoke:986', 'revoke:987'));
    $judged = array_filter($rec->matching('select', array('SELECT values_state, policy_json')), function ($s) {
        return in_array(987, $s[2], true); });
    check('revoke: the lap sends nothing for a project whose revocation is in hand, and keeps its intent',
        $judged === array() && $revoked === 0 && $wait['state']['revoke'][987] === $intent987
        && $runRow($x1['run'])[4] === 'raw');
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

// -- WHAT A PASS COSTS (item 12) ------------------------------------------------
//
// Two hundred projects with scan data and nothing due: the census reads them in
// one statement, and nothing else is sent for any of them. Then one project with
// 50,000 overdue previews and one with 50,000 previews a tightened policy
// revoked: each is cleared a bounded window per statement, a few statements per
// pass, and the next pass resumes from the stored cursor. Plans, not timings.
{
    foreach (array('scan_record', 'scan_aggregate', 'scan_audit', 'scan_plan', 'unique_candidate',
                   'unique_group', 'scan_dim', 'finding', 'scan_run', 'scan_worker_slot') as $t) {
        $A->query('DELETE FROM ' . Schema::table($t));
    }
    (new \INSPIRE\UniversalValidator\Scan\WorkerSlots($dbA))->provision(2);
    $store = new SqlScanStore($dbA);
    for ($p = 2001; $p <= 2200; $p++) {
        $r = $store->startRun($p, array('created_by' => 'alice'));
        $store->finish((int) $r['run']['run_id'],
            ScanOutcome::derive(array('fenced' => true, 'manifestDone' => true)));
    }
    // Each one has closed what came before it: nothing is due anywhere.
    $A->query('UPDATE ' . Schema::table('scan_run') . ' SET supersede_cursor = generation_id');
    check('cost: two hundred projects have scan data',
        (int) $dbA->select('SELECT COUNT(DISTINCT project_id) FROM ' . Schema::table('scan_run'))[0][0] === 200);

    $recR = new UvRecordingDb($dbA);
    $rr = (new \INSPIRE\UniversalValidator\Scan\ScanMaintenance($recR, array('policy' => $policyOf)))->reap(array(), 60.0);
    $recT = new UvRecordingDb($dbA);
    $rt = (new \INSPIRE\UniversalValidator\Scan\ScanMaintenance($recT, array('policy' => $policyOf)))
        ->retain(array('sweep' => array('cursor' => 0, 'top' => null, 'finished' => time())), 60.0);
    echo '  idle passes over 200 projects: reap ' . count($recR->log) . ' statements, retention '
        . count($recT->log) . "\n";
    $named = function (array $log) {
        $n = 0;
        foreach ($log as $row) {
            foreach ($row[2] as $v) if (is_int($v) && $v >= 2001 && $v <= 2200) { $n++; break; }
        }
        return $n;
    };
    check('cost: the idle passes did nothing, and finished their lap',
        $rr['out']['projects'] === 0 && $rt['out']['projects'] === 0 && $rr['state']['after'] === 0
        && $rt['state']['after'] === 0 && $rr['out']['failed'] === 0 && $rt['out']['failed'] === 0);
    // The reap pass is the slot census (shrinkTo) and the project census; the
    // retention pass, whose sweep finished today, the project census alone.
    check('cost: two hundred idle projects cost the reap pass 2 statements and the retention pass 1',
        count($recR->log) === 2 && count($recT->log) === 1);
    check('cost: one census statement each, and no statement for any one project',
        count($recR->matching('select', array('GROUP_CONCAT(DISTINCT values_state)'))) === 1
        && count($recT->matching('select', array('GROUP_CONCAT(DISTINCT values_state)'))) === 1
        && $named($recR->log) === 0 && $named($recT->log) === 0);
    // THE CENSUS'S PLAN: a project-leading key read in key order, so the
    // groups come out sorted and LIMIT stops after a page of them.
    $cs = $recR->matching('select', array('GROUP_CONCAT(DISTINCT values_state)'));
    $ex = $A->query('EXPLAIN ' . uv_inline_params($A, $cs[0][1], $cs[0][2]))->fetch_assoc();
    echo '  census plan: key=' . $ex['key'] . ' rows=' . $ex['rows'] . ' extra=' . $ex['Extra'] . "\n";
    $cols = uv_index_columns($A, Schema::table('scan_run'), (string) $ex['key']);
    check('cost: the census reads a key that leads with project_id',
        isset($cols[0]) && $cols[0] === 'project_id');
    check('cost: and groups in that key\'s order, without a temporary table or a sort',
        stripos((string) $ex['Extra'], 'temporary') === false && stripos((string) $ex['Extra'], 'filesort') === false);

    /** 50,000 findings of one run of $pid, each with a stored value expiring at $expires. */
    $bulk = function ($pid, $gen, $n, $expires) use ($A) {
        $rows = array();
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = '(' . $pid . ', ' . $gen . ", UNHEX(SHA2('b" . $pid . '-' . $i . "', 256)), 1, 1,"
                . " UNHEX(SHA2('R1', 256)), 'R1', 'x', 'demographics', 0, 'required', 'required-blank', '"
                . str_repeat('c', 64) . "', 'r1', 'V', '" . $expires . "')";
            if (count($rows) === 1000 || $i === $n) {
                $A->query('INSERT INTO ' . Schema::table('finding') . ' (project_id, generation_id,'
                    . ' finding_identity, valid_from_seq, active_slot, record_hash, record_id_bin, field,'
                    . ' host_form, rule_ord, check_type, reason_code, rule_revision, rule_source_id,'
                    . ' value_bin, value_expires_at) VALUES ' . implode(',', $rows));
                $rows = array();
            }
        }
    };
    $raw = array('values_state' => 'raw', 'policy_json' => json_encode(array('valueMode' => 'raw', 'valueDays' => 30)));
    $ex1 = $plant(2300, 0, null, true, $raw);       // its previews are overdue
    $bulk(2300, $ex1['gen'], 50000, '2000-01-01 00:00:00');
    $PRJ[2300] = array('scan-value-storage' => 'raw');
    $rv1 = $plant(2301, 0, null, true, $raw);       // its project now keeps no values
    $bulk(2301, $rv1['gen'], 50000, '2999-01-01 00:00:00');
    $PRJ[2301] = array('scan-value-storage' => 'locations');
    $A->query('UPDATE ' . Schema::table('scan_run') . ' SET supersede_cursor = generation_id');

    // ONE SECOND PER CLEARING STATEMENT. The clock moves only when a value
    // UPDATE has been sent, so the budget is a count of them: 3 per pass,
    // 60% of it (rounded down to 1 after the first) for the lap's revocation.
    $rec = new UvRecordingDb($dbA);
    $clears = function () use ($rec) { return count($rec->matching('exec', array('value_bin = NULL'))); };
    $byClears = function () use ($clears) { return (float) $clears(); };
    $mt = new \INSPIRE\UniversalValidator\Scan\ScanMaintenance($rec, array('policy' => $policyOf, 'clock' => $byClears));
    $state = array();
    $passes = 0;
    $perPass = array();
    $resumed = true;
    $revokeCursors = array();
    do {
        $before = $clears();
        $sweepAt = isset($state['sweep']['top']) && $state['sweep']['top'] !== null ? (int) $state['sweep']['cursor'] : null;
        $from = count($rec->log);
        $r = $mt->retain($state, 3.0);
        $passes++;
        $perPass[] = $clears() - $before;
        // The first window this pass sent starts where the last pass stopped.
        if ($sweepAt !== null) {
            foreach (array_slice($rec->log, $from) as $row) {
                if ($row[0] === 'exec' && strpos($row[1], 'finding_id > ? AND finding_id <= ?') !== false
                    && strpos($row[1], 'value_expires_at <= ?') !== false) {
                    if ((int) $row[2][0] !== $sweepAt) $resumed = false;
                    break;
                }
            }
        }
        if (isset($r['state']['revoke'][2301]['cursor'])) $revokeCursors[] = $r['state']['revoke'][2301]['cursor'];
        $state = $r['state'];
    } while ($passes < 60 && (isset($state['revoke'][2301]) || $state['sweep']['top'] !== null));
    echo '  50,000 + 50,000 previews: ' . $passes . ' passes, clearing statements per pass '
        . json_encode($perPass) . "\n";
    check('cost: both projects are cleared, across several passes',
        $passes > 3 && $countWhere('project_id = ? AND value_bin IS NOT NULL', array(2300)) === 0
        && $countWhere('project_id = ? AND value_bin IS NOT NULL', array(2301)) === 0
        && $countWhere('project_id IN (?, ?)', array(2300, 2301)) === 100000);
    check('cost: no pass sent more clearing statements than its budget allows', max($perPass) <= 3);
    $windows = $rec->matching('exec', array('value_expires_at <= ?', 'finding_id > ? AND finding_id <= ?'));
    $wide = 0;
    foreach ($windows as $w) if ((int) $w[2][1] - (int) $w[2][0] > \INSPIRE\UniversalValidator\Scan\ScanRetention::PAGE) $wide++;
    check('cost: every sweep statement is one window of at most PAGE ids', count($windows) >= 10 && $wide === 0);
    check('cost: and each pass resumed the sweep from the window the last one stored', $resumed);
    $after = -1;
    $moving = true;
    foreach ($revokeCursors as $c) {
        if (!is_array($c) || (int) $c['pid'] !== 2301 || !isset($c['set'])) $moving = false;
        $pos = (int) $c['gen'] * 10 + (int) $c['slot'];
        if ((int) $c['after'] <= $after && $pos === (isset($lastPos) ? $lastPos : $pos)) $moving = false;
        $after = (int) $c['after'];
        $lastPos = $pos;
    }
    check('cost: the revocation stopped part way more than once, its whole cursor kept and moving on',
        count($revokeCursors) >= 2 && $moving);
    $slices = $rec->matching('select', array('FORCE INDEX (ix_page_v2)', 'ORDER BY finding_id LIMIT'));
    check('cost: every revocation page reads at most PAGE index entries',
        count($slices) >= 10 && count(array_filter($slices, function ($s) {
            return strpos($s[1], 'LIMIT ' . \INSPIRE\UniversalValidator\Scan\ScanRetention::PAGE . ')') === false; })) === 0);

    // THE PLANS of one statement of each kind, on the 50,000 rows.
    $sweepKey = uv_explain_key($A, $windows[0][1], $windows[0][2]);
    $sliceKeys = uv_explain_keys($A, $slices[0][1], $slices[0][2]);
    $revClear = $rec->matching('exec', array('value_bin = NULL', 'AND finding_id > ? AND finding_id <= ?', 'generation_id = ?'));
    $revKey = uv_explain_key($A, $revClear[0][1], $revClear[0][2]);
    echo '  plans: sweep window ' . $sweepKey . '; revocation page ' . json_encode($sliceKeys)
        . ' ' . json_encode(uv_explain_rows($A, $slices[0][1], $slices[0][2])) . '; revocation clear ' . $revKey . "\n";
    check('cost: a sweep window is a range of the primary key', $sweepKey === 'PRIMARY');
    check('cost: a revocation page walks ix_page_v2', in_array('ix_page_v2', $sliceKeys, true));
    $revCols = uv_index_columns($A, Schema::table('finding'), $revKey);
    check('cost: and its clear is a range of a key that bounds it (the primary key, or the project-generation-slot prefix)',
        $revKey === 'PRIMARY' || array_slice($revCols, 0, 3) === array('project_id', 'generation_id', 'active_slot'));
    foreach (array(2300, 2301) as $p) uv_clear_project($dbA, $p);
}

// -- A PASS STOPPED INSIDE A PROJECT RESUMES AT THAT PROJECT (review rv10a1-6) --
//
// 2400 and 2401 each have three finished runs past their retention, a purge
// call per run. The pass is told to stop once 2400's first run is gone, so it
// stops inside 2400; the cursor it hands back is the project before, and the
// next pass works 2400 first. Handing back 2400 itself would leave its last
// two runs for a whole lap.
{
    $old = gmdate('Y-m-d H:i:s', time() - 20 * 86400);
    foreach (array(2400, 2401) as $p) {
        for ($i = 0; $i < 3; $i++) {
            $x = $plant($p, 1);
            $A->query('UPDATE ' . Schema::table('scan_run') . " SET updated_at = '" . $old . "' WHERE run_id = " . (int) $x['run']);
        }
        $PRJ[$p] = array('scan-run-retention-days' => '10');
    }
    $gone = false;
    $hook = new UvHookDb($dbA);
    $hook->before = function ($kind, $sql, array $params) use (&$gone) {
        if ($kind === 'exec' && strpos($sql, 'DELETE FROM ' . Schema::table('scan_run')) !== false
            && in_array(2400, $params, true)) {
            $gone = true;
        }
    };
    $entered = array();
    $lapOf = function ($db, callable $stopped) use ($policyOf, &$entered) {
        return new \INSPIRE\UniversalValidator\Scan\ScanMaintenance($db, array('policy' => $policyOf,
            'stopped' => $stopped, 'enter' => function ($pid) use (&$entered) { $entered[] = (int) $pid; }));
    };
    $runsOf = function ($p) use ($dbA) {
        return (int) $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_run') . ' WHERE project_id = ?', array($p))[0][0];
    };
    $p1 = $lapOf($hook, function () use (&$gone) { return $gone; })->retain(array(), 60.0);
    $entered1 = $entered;
    $left1 = $runsOf(2400);
    $entered = array();
    $p2 = $lapOf($dbA, function () { return false; })->retain($p1['state'], 60.0);
    check('lap: a pass stopped inside a project hands back the project before it',
        $p1['out']['stopped'] === 1 && $entered1 === array(2400) && $p1['state']['after'] === 2399
        && $left1 === 2);
    check('lap: and the next pass works that project first, and finishes both',
        isset($entered[0]) && $entered[0] === 2400 && $runsOf(2400) === 0 && $runsOf(2401) === 0);
    foreach (array(2400, 2401) as $p) uv_clear_project($dbA, $p);
}

// -- A RUN BEING REMOVED IS NO RUN TO ITS READERS (review rv10a1-3) -------------
//
// A large run is purged a page per call across passes, its row last. Pages of
// one row stand in for a run of 500,000 records. From the first page on,
// status() answers as it will once the row is gone; before, the run read
// `complete` with 0 records done and 0 findings while its three were stored.
{
    uv_redcap_project($A, 2402);
    // The policy a planner freezes says which other projects the run asked:
    // none. A run without that is refused to every reader anyway.
    $pr = $plant(2402, 3, null, true, array('policy_json' => json_encode(array('crossLookups' => array()))));
    $A->query('INSERT INTO ' . Schema::table('scan_aggregate') . " (run_id, kind, axis1, axis2, cnt) VALUES ("
        . (int) $pr['run'] . ", 'rollup-instrument', 'fa', '', 3)");
    $aged = gmdate('Y-m-d H:i:s', time() - 100 * 86400);
    $A->query('UPDATE ' . Schema::table('scan_run') . " SET updated_at = '" . $aged . "' WHERE run_id = " . (int) $pr['run']);
    $readers = new ScanService(new UvCronModule($A));
    $before = $readers->status(2402, $pr['run']);
    $ret = new \INSPIRE\UniversalValidator\Scan\ScanRetention($dbA);
    $c1 = $ret->purgeRuns(2402, 90, 1);
    $mid = (new ScanService(new UvCronModule($A)))->status(2402, $pr['run']);
    $markedAge = $dbA->select('SELECT updated_at FROM ' . Schema::table('scan_run') . ' WHERE run_id = ?', array($pr['run']));
    check('remove: a run reads as itself before its purge begins', $before['ok'] === true && $before['findings'] === 3);
    check('remove: from its first page, a run being removed is no run to status(), while its findings are still stored',
        $c1['done'] === false && $mid['ok'] === false && $mid['why'] === ScanService::NO_RUN
        && $countWhere('project_id = ? AND generation_id = ?', array(2402, $pr['gen'])) === 3);
    check('remove: and the mark leaves the age the purge is judged by alone',
        isset($markedAge[0][0]) && (string) $markedAge[0][0] === $aged);
    $calls = 1;
    while ($calls < 50 && empty($ret->purgeRuns(2402, 90, 1)['done'])) $calls++;
    check('remove: and the purge still finishes, taking the row last',
        $runRow($pr['run']) === null && $countWhere('project_id = ?', array(2402)) === 0 && $calls > 3);
    uv_clear_project($dbA, 2402);
}

// -- THE MODULE SWITCHED OFF (M17) ---------------------------------------------
//
// 1001 is mid-scan, its worker holding a slot; 1002 was stopped by a user and
// is still `cancelling`; 1003 finished. Disabling frees the slot and ends the
// two active runs as stopped, with the sentence that says why, and deletes
// nothing.
{
    $A->query('DELETE FROM ' . Schema::table('scan_worker_slot'));
    $slots = new \INSPIRE\UniversalValidator\Scan\WorkerSlots($dbA);
    $slots->provision(2);
    $store = new SqlScanStore($dbA);
    $live = $plant(1001, 2, null, false);
    $liveEpoch = (int) $store->run(1001, $live['run'])['lease_epoch'];
    $lease = $slots->acquire('w', $live['run'], 600);
    $stop = $plant(1002, 1, null, false);
    $store->cancel(1002, $stop['run'], 'alice');
    $done = $plant(1003, 1);
    $doneRow = $runRow($done['run']);
    $everything = function () use ($dbA) {
        $n = 0;
        foreach (Schema::tables() as $t) $n += (int) $dbA->select('SELECT COUNT(*) FROM ' . $t)[0][0];
        return $n;
    };
    $rowsBefore = $everything();
    $logs = array();
    $r = $maint()->disable();
    echo '  disable: ' . json_encode($r) . "\n";
    check('disable: the leased slot is freed, and both active runs are stopped',
        $r['slots_released'] === 1 && $r['runs_stopped'] === 2 && $r['failed'] === 0
        && $slots->census()['held'] === 0);
    foreach (array(array(1001, $live), array(1002, $stop)) as $pair) {
        $row = $store->run($pair[0], $pair[1]['run']);
        $slot = $dbA->select('SELECT active_slot FROM ' . Schema::table('scan_run') . ' WHERE run_id = ?',
            array($pair[1]['run']));
        check('disable: ' . $pair[0] . ' ends as stopped, with the reason, and leaves the project free',
            $row['terminal'] === ScanOutcome::CANCELLED && $slot[0][0] === null
            && $row['terminal_reason'] === \INSPIRE\UniversalValidator\Scan\ScanMaintenance::DISABLED_WHY);
    }
    $au = $dbA->select('SELECT actor FROM ' . Schema::table('scan_audit')
        . " WHERE project_id = ? AND run_id = ? AND event = 'cancel' ORDER BY audit_id", array(1001, $live['run']));
    check('disable: the stop is audited as the system\'s', count($au) === 1 && $au[0][0] === 'system');
    check('disable: a finished run is not touched', $runRow($done['run']) === $doneRow);
    check('disable: the worker that was mid-batch is refused at its next fence',
        $store->commitBatch($live['run'], 'w', $liveEpoch, array('bytes' => 0, 'findings' => array(),
            'records' => array())) !== true
        && $slots->renew($lease['slot_no'], 'w', $lease['epoch'], 600) === false);
    // Nothing deleted: every table is still there, and only rows were added
    // (the audit) or changed in place (the runs and the slot).
    check('disable: nothing is deleted without the opt-in', $everything() >= $rowsBefore
        && $countWhere('project_id = ?', array(1001)) === 2);
    $again = $maint()->disable();
    check('disable: a second disable finds nothing to stop', $again['runs_stopped'] === 0
        && $again['slots_released'] === 0 && $again['failed'] === 0);
    check('disable: and the project can be scanned again once enabled',
        $store->startRun(1001, array('created_by' => 'alice'))['ok'] === true);
}

// -- A DISABLE OVER MORE THAN ONE PAGE, WITH NO TIME TO FINISH (rv10a2-3, rv10a2-5)
//
// DISABLE_PAGE + 1 active runs, and a budget of nothing: every run is stopped,
// the one on the second page too, in a few statements, and none is finished.
// A Start in another request lands while the walk stops the last page; the
// second read stops its run too. What the walk left is finished later, with
// the disable's sentence, by whichever request reaches it.
{
    foreach (array(1001, 1002, 1003) as $p) uv_clear_project($dbA, $p);
    $A->query('DELETE FROM ' . Schema::table('scan_worker_slot'));
    (new \INSPIRE\UniversalValidator\Scan\WorkerSlots($dbA))->provision(2);
    $store = new SqlScanStore($dbA);
    $N = \INSPIRE\UniversalValidator\Scan\ScanMaintenance::DISABLE_PAGE + 1;
    $A->query('START TRANSACTION');
    for ($p = 0; $p < $N; $p++) $store->startRun(3000 + $p, array('created_by' => 'u' . $p));
    $A->query('COMMIT');
    $lastId = (int) $dbA->select('SELECT MAX(run_id) FROM ' . Schema::table('scan_run'))[0][0];
    $late = null;
    $storeB = new SqlScanStore($dbB);
    $hook = new UvHookDb($dbA);
    $hook->before = function ($kind, $sql, array $params) use (&$late, $lastId, $storeB) {
        if ($late === null && $kind === 'exec' && strpos($sql, 'cancel_requested_at') !== false
            && in_array($lastId, $params, true)) {
            $late = $storeB->startRun(3999, array('created_by' => 'late-user'));
        }
    };
    $rec = new UvRecordingDb($hook);
    $d = (new \INSPIRE\UniversalValidator\Scan\ScanMaintenance($rec, array('policy' => $policyOf)))->disable(0.0);
    echo '  disable over ' . ($N + 1) . ' runs, no time to finish: ' . json_encode($d) . ', '
        . count($rec->log) . " statements\n";
    $count = function ($where, array $params) use ($dbA) {
        return (int) $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_run') . ' WHERE ' . $where, $params)[0][0];
    };
    $why = \INSPIRE\UniversalValidator\Scan\ScanMaintenance::DISABLED_WHY;
    check('disable: more runs than one page are every one stopped, the second page too, with the reason on the row',
        $count('active_slot = 1 AND phase = ? AND terminal_reason = ?', array('cancelling', $why)) === $N + 1
        && $count('active_slot = 1 AND phase <> ?', array('cancelling')) === 0
        && $d['runs_left'] === $N + 1 && $d['runs_stopped'] === 0 && $d['failed'] === 0);
    check('disable: a run started while the walk went on is stopped by the second read',
        is_array($late) && $late['ok'] === true && $d['late'] === 1
        && $store->run(3999, (int) $late['run']['run_id'])['phase'] === 'cancelling');
    check('disable: every stop is audited once, as the system\'s',
        (int) $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_audit')
            . " WHERE project_id >= 3000 AND event = 'cancel' AND actor = 'system'")[0][0] === $N + 1);
    check('disable: and stopping them costs statements by the page, not by the run',
        count($rec->log) <= 12);
    // Left for later: a request with no reason of its own finishes the run
    // with the disable's, Start's reap and the cron's alike.
    $r0 = (int) $dbA->select('SELECT run_id FROM ' . Schema::table('scan_run') . ' WHERE project_id = 3000')[0][0];
    \INSPIRE\UniversalValidator\Scan\ScanPromotion::finishCancelled($store, 3000, $r0, array());
    $A->query('UPDATE ' . Schema::table('scan_run') . " SET created_at = '" . gmdate('Y-m-d H:i:s', time() - 30 * 3600)
        . "' WHERE project_id = 3001");
    $rp = $maint()->reap(array('after' => 3000), 60.0);
    $r1 = (int) $dbA->select('SELECT run_id FROM ' . Schema::table('scan_run') . ' WHERE project_id = 3001')[0][0];
    check('disable: a run it left stopping is finished later as cancelled, with the disable\'s sentence',
        $store->run(3000, $r0)['terminal'] === ScanOutcome::CANCELLED && $store->run(3000, $r0)['terminal_reason'] === $why
        && $rp['out']['runs_stopped'] === 1
        && $store->run(3001, $r1)['terminal'] === ScanOutcome::CANCELLED && $store->run(3001, $r1)['terminal_reason'] === $why);
    foreach (array('scan_run', 'scan_audit') as $t) {
        $A->query('DELETE FROM ' . Schema::table($t) . ' WHERE project_id >= 3000');
    }
}

// -- THE OPT-IN DROP, last: it leaves nothing for a section after it -------------
{
    $ours = function () use ($A) {
        $names = Schema::tables();
        $in = "'" . implode("','", $names) . "'";
        $r = $A->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
            . ' AND table_name IN (' . $in . ')');
        return (int) $r->fetch_row()[0];
    };
    check('drop: every table is there before', $ours() === count(Schema::tables()));
    $rec = new UvRecordingDb($dbA);
    $n = (new \INSPIRE\UniversalValidator\Scan\ScanMaintenance($rec, array('policy' => $policyOf)))->dropTables();
    $drops = $rec->matching('exec', array('DROP TABLE IF EXISTS'));
    check('drop: every table the module created is dropped, and nothing else',
        $n === count(Schema::tables()) && $ours() === 0 && count($drops) === $n);
    check('drop: the version table last, so a drop that stops part way still says what is missing',
        strpos(end($drops)[1], Schema::table('schema_version')) !== false);
}
