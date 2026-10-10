<?php
/**
 * tests/mysql/cases/perf.php — what one request costs, counted.
 *
 * WHY STATEMENTS AND NOT SECONDS. The review measured the fixed cost of a
 * scan-work request at about 1.8 s against a 3.0 s work budget (P1), and the
 * cause was the shape of the traffic, not any one slow query: the entitlement
 * and the whole plan built twice per request (M12), every capability probe run
 * twice, eleven information_schema reads to establish that the tables exist
 * (M11), the change log opened four times, and the record census read by
 * promotion and again by the status that followed it (P11). A timing threshold
 * on a shared runner is noise; a statement count is a property of the code.
 * Every bound below fails when one of those duplications comes back.
 *
 * THROUGH THE COMPOSITION ROOT. Every statement here is sent by ScanService and
 * the real classes it assembles - the store, the worker, the planner, catch-up,
 * the finalizer, the rollup - over a real server. Only the framework edge is a
 * stand-in: the module's query(), which counts what passes through it, and its
 * durableScanContext(), which hands back a fixed rule list (building a plan
 * needs a data dictionary, and what is counted here is how many times the
 * service ASKS for one).
 *
 * THE BOUNDS ARE THE MEASURED COUNTS, NOT A BUDGET WITH HEADROOM. A change that
 * adds a statement to a poll should have to change this file and say why. The
 * worker's own statements are inside the work totals, so a store change that
 * adds one to a batch shows here as well; that is the point of counting the
 * request rather than the parts.
 */

use INSPIRE\UniversalValidator\Scan\Schema;
use INSPIRE\UniversalValidator\Scan\ScanService;
use INSPIRE\UniversalValidator\Scan\ScanStore;

// ScanService builds a CatchUp for every work request; nothing else in this
// suite does, so the shared bootstrap does not load it.
require_once __DIR__ . '/../../../php/Scan/CatchUp.php';

if (!class_exists('UvPerfModule', false)) {
    /** A designer with full export rights on the one instrument the rule lives on. */
    class UvPerfUser
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
     * The framework edge, and a counter on it.
     *
     * query() forwards to the real connection, as UvDagModule does, and keeps
     * the text of every statement. Everything ScanService sends goes through
     * here - ModuleDb wraps this, and Schema and ScanCapabilities call it
     * directly - so the log is the request's whole database traffic.
     */
    class UvPerfModule
    {
        private $c;
        private $pid;
        /** @var string[] every statement, in order */
        public $log = array();
        /** @var int how many times the service asked for a plan */
        public $plans = 0;

        public function __construct($c, $pid) { $this->c = $c; $this->pid = (int) $pid; }

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
            if ($k === 'log-hmac-key') return 'perf-secret-key';
            return null;
        }
        public function getProjectSetting($k, $pid = null)
        {
            return $k === ScanService::PROJ_FLAG ? '1' : null;
        }
        public function getUser() { return new UvPerfUser(); }
        public function log($event, $context = array()) { return 1; }

        /**
         * One rule on one instrument, and a read and an evaluator for it.
         *
         * The evaluator stores one finding per record, so the run carries
         * detail rows and the finishing phases have something to summarise.
         */
        public function durableScanContext($pid, array $opts = array(), $dag = null)
        {
            $this->plans++;
            // WHAT scanPlan() SENDS. Its dictionary and rule reads go through
            // REDCap's own API, not query(); the capability census is the part
            // that reaches the database, so it is asked here exactly as the
            // real plan asks it - from the census the service hands over when
            // it has one, by probing when it does not.
            $caps = (isset($opts['capabilities']) && is_array($opts['capabilities']))
                ? $opts['capabilities'] : \INSPIRE\UniversalValidator\ScanCapabilities::all($this, $pid);
            \INSPIRE\UniversalValidator\ScanCapabilities::policy($caps);
            $gen = array_key_exists('generation', $opts) ? $opts['generation'] : null;
            $seq = isset($opts['runSeq']) ? (int) $opts['runSeq'] : 0;
            $evaluate = $gen === null ? null : function ($id, $node) use ($pid, $gen, $seq) {
                return array('bytes' => 10, 'contexts' => 1, 'why' => null, 'problems' => array(),
                    'candidates' => array(), 'collapsed' => 0, 'findings' => array(array(
                    'project_id' => (int) $pid, 'generation_id' => (int) $gen,
                    'identity' => hash('sha256', 'perf' . $id, true), 'valid_from_seq' => $seq,
                    'record_hash' => hash('sha256', $id, true), 'record_id_bin' => (string) $id,
                    'instance' => 1, 'host_form' => 'demographics', 'field' => 'dob',
                    'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64),
                    'check_type' => 'required', 'reason_code' => 'required-blank')));
            };
            return array('ok' => true, 'why' => null,
                'rules' => array(array('type' => 'required', 'fields' => array('dob'))),
                'ownership' => array('dob' => 'demographics'),
                'structure' => array('longitudinal' => false),
                'plan' => array('hostFields' => array(0 => array('demographics' => array('dob'))),
                                'crossRefused' => array(), 'crossLookups' => array(), 'crossRecordLookups' => false),
                'read' => function ($ids) {
                    $out = array();
                    foreach ($ids as $id) $out[(string) $id] = array('1' => array('dob' => ''));
                    return array('ok' => true, 'data' => $out, 'why' => null);
                },
                'evaluate' => $evaluate, 'verifyFingerprint' => true, 'problems' => array());
        }

        /** How many statements of the log contain $needle. */
        public function count($needle)
        {
            $n = 0;
            foreach ($this->log as $s) if (strpos($s, $needle) !== false) $n++;
            return $n;
        }

        /**
         * The statements the bounds count: all of them, less the one read the
         * CLOCK decides. WorkerSlots::renew() confirms a renewal that changed
         * no row with a SELECT 1, and whether it changed one depends on whether
         * the renewal fell in the same second as the lease it renews - so a
         * request sends between zero and one of those per batch, run to run.
         */
        public function counted()
        {
            return count($this->log) - $this->count('SELECT 1 FROM ' . Schema::table('scan_worker_slot'));
        }

        /** The log, by kind, for the report line. */
        public function breakdown()
        {
            $kinds = array('version' => 'MAX(version)', 'tables' => 'information_schema.tables',
                'columns' => 'information_schema.columns', 'grants' => 'SHOW GRANTS',
                'runRow' => 'SELECT run_id, project_id, scope_dag', 'census' => 'SELECT state, COUNT(*)',
                'aggregates' => Schema::table('scan_aggregate'), 'logTable' => 'SELECT log_event_table',
                'rowCount' => 'ROW_COUNT()');
            $out = array();
            foreach ($kinds as $k => $needle) $out[] = $k . '=' . $this->count($needle);
            return 'statements=' . count($this->log) . ' counted=' . $this->counted() . ' ' . implode(' ', $out);
        }
    }
}

$PID = 960;
$RECORDS = 6;

{
    uv_redcap_schema($A);
    uv_redcap_log_schema($A);
    uv_redcap_project($A, $PID);
    for ($i = 1; $i <= $RECORDS; $i++) {
        $A->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES ("
            . $PID . ", 'P" . $i . "', NULL)");
        $A->query("INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`)
            VALUES (" . $PID . ", 1, 'P" . $i . "', 'record_id', 'P" . $i . "')");
        $A->query('INSERT INTO redcap_log_event (log_event_id, project_id, pk, event) VALUES ('
            . (5000 + $i) . ', ' . $PID . ", 'P" . $i . "', 'UPDATE')");
    }
    $A->query('DELETE FROM ' . Schema::table('scan_worker_slot'));
    (new \INSPIRE\UniversalValidator\Scan\WorkerSlots($dbA))->provision(2);

    $m = new UvPerfModule($A, $PID);
    $store = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA);

    /**
     * One request: a fresh service, as the endpoint builds one, and the log it
     * leaves.
     *
     * WITH NO MEMORY LIMIT, for the length of the request only. The worker's
     * budget samples the allocator arena, which moves in 2 MB steps with
     * whatever this process did before, and at the CLI's 128M it sometimes
     * stops a batch early: measured, the same request sent 53, 77 or 79
     * statements across three runs.
     *
     * AND ON THE CRON CLOCK. The work requests below pass mode 'cron', which
     * changes one thing in ScanService: the budget's deadline, 20 s instead of
     * the browser's 3 s (WorkBudget::target()). Every statement is the same
     * code. On a host busy with other suites the 3 s deadline passed before
     * the first claim - measured, the same request sent 24, 53, 67 or 78
     * statements and the finishing request did not finish - so the browser
     * clock made the count the machine's. A request the deadline still stops
     * fails the 'stopped by the clock' check, by name, rather than a bound.
     */
    $request = function ($verb) use ($m) {
        $m->log = array();
        $m->plans = 0;
        $limit = ini_get('memory_limit');
        ini_set('memory_limit', '-1');
        try {
            $svc = new ScanService($m);
            $answer = $verb($svc);
        } finally {
            ini_set('memory_limit', $limit);
        }
        return $answer;
    };
    $report = function ($what) use ($m) {
        echo '  perf: ' . str_pad($what, 22) . ' ' . $m->breakdown() . " plans=" . $m->plans . "\n";
    };

    /* -----------------------------------------------------------------------
     * START. One plan, one health check, one capability census.
     * -------------------------------------------------------------------- */
    $started = $request(function ($svc) use ($PID) { return $svc->start($PID); });
    $report('start');
    if (getenv('UV_PERF_DEBUG')) {
        foreach ($m->log as $i => $s) {
            echo '    ' . $i . ': ' . preg_replace('/\s+/', ' ', substr($s, 0, 150)) . "\n";
        }
    }
    check('perf: the service starts a run over the real store',
        $started['ok'] === true && (int) $started['run_id'] > 0);
    $runId = (int) $started['run_id'];
    check('perf: start builds the plan once', $m->plans === 1);
    check('perf: start asks whether the tables exist in one statement and the version in one',
        $m->count('MAX(version)') === 1
        && $m->count('SELECT table_name FROM information_schema.tables') === 1);
    check('perf: start probes the record index for the capability gate once',
        $m->count('FROM redcap_record_list WHERE project_id = ? LIMIT 1') === 1);
    check('perf: start asks the grants once', $m->count('SHOW GRANTS') === 1);
    $startCount = $m->counted();

    /* -----------------------------------------------------------------------
     * A STATUS POLL, which a watching tab sends on its ladder.
     * -------------------------------------------------------------------- */
    $st = $request(function ($svc) use ($PID, $runId) { return $svc->status($PID, $runId); });
    $report('status (scanning)');
    check('perf: status answers', $st['ok'] === true && $st['total'] === $RECORDS);
    check('perf: a status poll builds the plan once', $m->plans === 1);
    check('perf: and reads the census once', $m->count('SELECT state, COUNT(*)') === 1);
    check('perf: and the run row once', $m->count('SELECT run_id, project_id, scope_dag') === 1);
    $pollCount = $m->counted();

    /* -----------------------------------------------------------------------
     * A WORK REQUEST THAT CANNOT FINISH THE RUN: another tab holds two claims.
     * This is the steady state of a scan, and the request the client sends
     * back to back.
     * -------------------------------------------------------------------- */
    $run = $store->run($PID, $runId);
    $epoch = (int) $run['lease_epoch'];
    $held = $store->claim($runId, 'other-tab', $epoch, 2);
    check('perf: another tab holds two records', is_array($held) && count($held) === 2);
    // HELD AS catch-up HOLDS THEM. A scanning claim leaves its rows pending, and
    // catch-up takes a pending row back at once - so a tab that merely claimed
    // in scanning holds nothing a second request can see. claimPending() marks
    // its rows CLAIMED and they stay out of reach until they go stale; that is
    // the state written here, with the token the claim was given.
    $ords = array();
    foreach ($held as $c) $ords[] = (int) $c['ordinal'];
    $dbA->exec('UPDATE ' . Schema::table('scan_record') . ' SET state = ?, updated_at = ?
        WHERE run_id = ? AND ordinal IN (' . implode(',', $ords) . ')',
        array(ScanStore::REC_CLAIMED, gmdate('Y-m-d H:i:s'), $runId));
    $w = $request(function ($svc) use ($PID, $runId) { return $svc->work($PID, $runId, 'cron'); });
    $report('work (records left)');
    if (getenv('UV_PERF_DEBUG')) {
        // UV_PERF_DEBUG=1 prints the request statement by statement, which is
        // where to start when a bound below goes red.
        foreach ($m->log as $i => $s) {
            echo '    ' . $i . ': ' . preg_replace('/\s+/', ' ', substr($s, 0, 150)) . "\n";
        }
    }
    check('perf: the work request was not stopped by the clock (a loaded host; rerun)',
        !isset($w['stop']) || $w['stop'] !== 'time');
    check('perf: the work request answers with a status',
        $w['ok'] === true && isset($w['status']['ok']) && $w['status']['ok'] === true);
    check('perf: and examined the records nobody else held', (int) $w['worked'] === $RECORDS - 2);
    check('perf: a work request builds the plan once, for the work and the status it returns',
        $m->plans === 1);
    check('perf: it asks whether the tables exist in one statement and the version in one',
        $m->count('MAX(version)') === 1
        && $m->count('SELECT table_name FROM information_schema.tables') === 1);
    check('perf: it probes the record index for the capability gate once, not once per caller',
        $m->count('FROM redcap_record_list WHERE project_id = ? LIMIT 1') === 1);
    check('perf: it asks the grants once', $m->count('SHOW GRANTS') === 1);
    check('perf: it opens the change log once for the fence, plus the capability probe',
        $m->count('SELECT log_event_table') === 2);
    check('perf: it reads the census once - the status reuses what promotion read',
        $m->count('SELECT state, COUNT(*)') === 1);
    check('perf: a run that is not ready reads none of its aggregates',
        $m->count('FROM ' . Schema::table('scan_aggregate')) === 0);
    check('perf: and the status it returns describes the run as promotion saw it',
        (int) $w['status']['done'] === $RECORDS - 2 && $w['status']['terminal'] === null);
    $workCount = $m->counted();

    /* -----------------------------------------------------------------------
     * THE WORK REQUEST THAT FINISHES IT. The other tab's records are done;
     * this request runs the finishing phases and promotes - a write - so the
     * status after it reads the census again.
     * -------------------------------------------------------------------- */
    $gen = (int) $run['generation_id'];
    $batch = array('bytes' => 0, 'records' => array(), 'findings' => array());
    foreach ($held as $c) {
        $batch['records'][] = array('ordinal' => $c['ordinal'], 'state' => ScanStore::REC_DONE,
            'version' => '5000', 'claim' => $c['claim'], 'record_hash' => $c['hash']);
    }
    check('perf: the other tab commits what it held',
        $store->commitBatch($runId, 'other-tab', $epoch, $batch) === true);
    $f = $request(function ($svc) use ($PID, $runId) { return $svc->work($PID, $runId, 'cron'); });
    $report('work (finishes)');
    if (getenv('UV_PERF_DEBUG')) {
        foreach ($m->log as $i => $s) {
            echo '    ' . $i . ': ' . preg_replace('/\s+/', ' ', substr($s, 0, 150)) . "\n";
        }
    }
    check('perf: the finishing request was not stopped by the clock (a loaded host; rerun)',
        !isset($f['stop']) || $f['stop'] !== 'time');
    check('perf: the request that finishes the run says so',
        isset($f['status']['terminal']) && $f['status']['terminal'] !== null);
    check('perf: it still builds the plan once', $m->plans === 1);
    check('perf: and reads the census twice: once to decide, once AFTER the write that ended the run',
        $m->count('SELECT state, COUNT(*)') === 2);
    $finishCount = $m->counted();

    /* -----------------------------------------------------------------------
     * P10, against a real run row: what the run stored is reported.
     * -------------------------------------------------------------------- */
    check('perf: P10 status reports the detail rows the run stored',
        isset($f['status']['detailRows']) && $f['status']['detailRows'] === $RECORDS - 2
        && isset($f['status']['detailBytes']) && $f['status']['detailBytes'] === 10 * ($RECORDS - 2));

    $after = $request(function ($svc) use ($PID, $runId) { return $svc->status($PID, $runId); });
    $report('status (finished)');
    check('perf: a finished run is polled with one census read and one plan',
        $after['ok'] === true && $m->count('SELECT state, COUNT(*)') === 1 && $m->plans === 1);
    $donePollCount = $m->counted();

    // THE BOUNDS, measured on this tree on MySQL 5.7 and MariaDB 10.5, which
    // send the same statements; see the header for why they are exact, and
    // counted() for the one read they leave out. Before wave 8 the same five
    // requests sent 49, 8, 107, 106 and 9 statements in all (the two work
    // numbers include any renewal confirm reads) - with aggregateTotal() put
    // back, because without it each work request died at promotion (W8-N1).
    // Wave 9 put ten statements on the start, all of them B10's: the planning
    // lease (claimPlan(): begin, the run row and the plan row locked, the plan
    // row written, the lease written, commit), the page's plan row locked and
    // its cursor and heartbeat written in the page's own transaction, and the
    // plan_done gate the freeze reads. A start sent 35 before them.
    $bounds = array(
        'start'                              => array($startCount, 45),
        'a status poll'                      => array($pollCount, 8),
        'a work request with records left'   => array($workCount, 76),
        'the work request that finishes'     => array($finishCount, 77),
        'a status poll of a finished run'    => array($donePollCount, 9),
    );
    foreach ($bounds as $what => $b) {
        if ($b[0] > $b[1]) fwrite(STDERR, '  perf: ' . $what . ' sent ' . $b[0] . ', bound ' . $b[1] . "\n");
        check('perf: ' . $what . ' sends no more statements than it was measured at', $b[0] <= $b[1]);
    }
}
