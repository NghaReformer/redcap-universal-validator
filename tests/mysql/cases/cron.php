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
