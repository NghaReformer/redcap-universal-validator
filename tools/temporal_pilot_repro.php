<?php
/**
 * temporal_pilot_repro.php — the live pilot's five failures, against a REAL
 * MySQL, through the shipped SqlScanStore.
 *
 * WHAT IT WAS FOR. Every pilot of the durable scan from 1.9.0 to 1.9.10 died
 * with "the database refused to store these findings", forty batches
 * identically, and the cause was never identified. This drives the real store
 * through the five shapes that produce it. On 1.9.10 it printed REFUSED for
 * scenarios 1, 2 and 5, and zero findings left across BOTH projects for
 * scenario 4.
 *
 * WHAT IT IS FOR NOW. The same five, as an after-state. On this tree it prints:
 *
 *   1  the SECOND scan of a project        COMMITTED   (findings 2 -> 4)
 *   2  two hidden codes ticked on one box  COMMITTED   (2 stored, not 0)
 *   4  purging ONE project's run           2 findings left, not 0
 *   5  a permanently refused batch         3 attempts, then UNSTORED and done
 *
 * The scenario labels below still describe the DEFECT each one was written to
 * catch, because that is what makes the output readable as a before-and-after.
 *
 * SCENARIO 5 WAS REWRITTEN, and the reason is worth keeping. It was written to
 * show that a refused batch never incremented `attempts`, so the retry cap
 * could never trip - and it used the two-hidden-codes checkbox to produce the
 * refusal. Fixing the identity made that batch COMMIT, so the harness stopped
 * being able to reach the path at all: the trigger was fixed, not the defect,
 * and for one release this file printed a green line over an unbounded retry
 * loop that was still there.
 *
 * It now drives the REAL ScanWorker against an evaluation that emits one
 * identity twice - a write the database will refuse every time, forever - and
 * shows the run reaching a terminal state instead of retrying. Any persistent
 * write error takes the same path.
 *
 * Run:
 *   UV_DB_HOST=127.0.0.1 UV_DB_PORT=33306 UV_DB_USER=root UV_DB_PASS=uvtest \
 *   UV_DB_NAME=uv_test php -d extension=mysqli tools/temporal_pilot_repro.php
 */

require_once __DIR__ . '/../php/Scan/Schema.php';
require_once __DIR__ . '/../php/Scan/ScanOutcome.php';
require_once __DIR__ . '/../php/Scan/ScanPhase.php';
require_once __DIR__ . '/../php/Scan/ScanStore.php';
require_once __DIR__ . '/../php/Scan/ScanDb.php';
require_once __DIR__ . '/../php/Scan/DbError.php';
require_once __DIR__ . '/../php/Scan/ScanStoreUnavailable.php';
require_once __DIR__ . '/../php/Scan/ScanAuthorization.php';
require_once __DIR__ . '/../php/Scan/SqlScanStore.php';
require_once __DIR__ . '/../php/Scan/Hmac.php';
require_once __DIR__ . '/../php/Scan/ScanRetention.php';
require_once __DIR__ . '/../php/Scan/WorkBudget.php';
require_once __DIR__ . '/../php/Scan/ScanWorker.php';

use INSPIRE\UniversalValidator\Scan\Schema;
use INSPIRE\UniversalValidator\Scan\SqlScanStore;
use INSPIRE\UniversalValidator\Scan\ScanStore;
use INSPIRE\UniversalValidator\Scan\ScanOutcome;
use INSPIRE\UniversalValidator\Scan\Hmac;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$c = new mysqli(getenv('UV_DB_HOST') ?: '127.0.0.1', getenv('UV_DB_USER') ?: 'root',
                getenv('UV_DB_PASS') ?: '', getenv('UV_DB_NAME') ?: 'uv_test',
                (int) (getenv('UV_DB_PORT') ?: 3306));
$c->set_charset('utf8mb4');
echo "server: " . $c->server_info . "\n";
$sqlMode = $c->query('SELECT @@sql_mode')->fetch_row()[0];
echo "sql_mode: $sqlMode\n";

function bindAll($st, array $vals) {
    $refs = [];
    foreach ($vals as $k => $v) $refs[$k] = &$vals[$k];
    array_unshift($refs, str_repeat('s', count($vals)));
    call_user_func_array([$st, 'bind_param'], $refs);
}

class Db implements INSPIRE\UniversalValidator\Scan\ScanDb {
    private $c; private $aff = 0;
    public function __construct($c) { $this->c = $c; }
    public function select($sql, array $params = []) {
        if (!$params) { $r = $this->c->query($sql); return $r === true ? [] : $this->rows($r); }
        $st = $this->c->prepare($sql); bindAll($st, array_values($params)); $st->execute();
        $r = $st->get_result(); $out = $r === false ? [] : $this->rows($r); $st->close(); return $out;
    }
    public function exec($sql, array $params = []) {
        if (!$params) { $this->c->query($sql); $this->aff = $this->c->affected_rows; return; }
        $st = $this->c->prepare($sql); bindAll($st, array_values($params)); $st->execute();
        $this->aff = $st->affected_rows; $st->close();
    }
    public function affected() { return $this->aff; }
    public function begin() { $this->c->query('START TRANSACTION'); }
    public function commit() { $this->c->query('COMMIT'); }
    public function rollback() { $this->c->query('ROLLBACK'); }
    private function rows($r) { $out = []; while ($row = $r->fetch_row()) $out[] = $row; $r->free(); return $out; }
}

/** A module stand-in for Schema::migrate(). */
class Mod {
    private $c;
    public function __construct($c) { $this->c = $c; }
    public function query($sql, $params = []) {
        if (!$params) { $r = $this->c->query($sql); return $r === true ? [] : $this->rows($r); }
        $st = $this->c->prepare($sql); bindAll($st, array_values($params)); $st->execute();
        $r = $st->get_result(); $out = $r === false ? [] : $this->rows($r); $st->close(); return $out;
    }
    private function rows($r) { $out = []; while ($row = $r->fetch_row()) $out[] = $row; $r->free(); return $out; }
}

// -- clean slate --------------------------------------------------------------
foreach (array_reverse(Schema::tables()) as $t) $c->query('DROP TABLE IF EXISTS ' . $t);
$m = new Mod($c);
$mig = Schema::migrate($m);
echo "migrate: " . ($mig['ok'] ? 'ok' : 'FAILED ' . $mig['why']) . "\n\n";

$db = new Db($c);
$store = new SqlScanStore($db);
$PID = 149;
$KEY = str_repeat("k", 32);

/** The findings ONE record produces, exactly as durableEvaluateRecord builds them. */
function findingsFor($pid, $recordId, $key, $gen, $spec) {
    $out = [];
    $seq = 0;
    foreach ($spec as $s) {
        $loc = ['record' => (string) $recordId, 'event_id' => $s['event_id'],
                'instance' => $s['instance'], 'host_form' => $s['host_form'],
                'field' => $s['field'], 'rule_source_id' => $s['rule_source_id'],
                'reason_code' => $s['reason_code'],
                // WHICH ticked hidden code. Two options ticked on one checkbox
                // are two problems at one location, and this is what tells them
                // apart. The real evaluator sets it from the choice code; here
                // the fixture's own 'value' is that code.
                'locus' => isset($s['locus']) ? (string) $s['locus']
                         : (isset($s['value']) ? (string) $s['value'] : '')];
        $out[] = [
            'project_id' => (int) $pid,
            'generation_id' => $gen,
            'identity' => Hmac::findingIdentity($pid, $loc, $key),
            'valid_from_seq' => 1,
            'record_hash' => Hmac::raw(Hmac::P_RECORD, $pid, (string) $recordId, $key),
            'record_id_bin' => (string) $recordId,
            'event_id' => $s['event_id'],
            'instance' => $s['instance'],
            'host_form' => $s['host_form'],
            'field' => $s['field'],
            'rule_source_id' => $s['rule_source_id'],
            'rule_revision' => str_repeat('a', 64),
            'rule_ord' => $s['rule_ord'],
            'check_type' => $s['check_type'],
            'reason_code' => $s['reason_code'],
            'dag_key' => null,
            'value_bin' => $s['value'],
            'value_len' => strlen($s['value']),
            'value_truncated' => 0,
            'value_fingerprint' => Hmac::raw(Hmac::P_VALUE, $pid, $s['value'], $key),
        ];
    }
    return $out;
}

/** One whole run: start, manifest, claim, commit, finish. Returns the commit results. */
function oneRun($store, $pid, $records, $specFor, $label) {
    $started = $store->startRun($pid, ['created_by' => 'tester',
        'fingerprint' => str_repeat('f', 64), 'policy_json' => '{}', 'values_state' => 'raw']);
    if (empty($started['ok'])) { echo "$label: START REFUSED (" . $started['why'] . ")\n"; return null; }
    $runId = (int) $started['run']['run_id'];
    $rows = [];
    foreach ($records as $r) {
        $rows[] = ['id_bin' => (string) $r,
                   'hash' => Hmac::raw(Hmac::P_RECORD, $pid, (string) $r, str_repeat('k', 32)),
                   'dag' => null];
    }
    $store->writeManifest($runId, $rows);
    $runRow = $store->run($pid, $runId);
    $epoch  = (int) $runRow['lease_epoch'];
    // THE RUN'S OWN GENERATION, not a literal. This harness was written when
    // every run of every project was generation 1 - which is the defect it
    // exists to demonstrate - so the fixtures below still say 1 and are
    // corrected here from whatever the store actually allocated.
    $gen    = (int) $runRow['generation_id'];
    $rseq   = (int) $runRow['run_seq'];
    $results = [];
    while (true) {
        $claim = $store->claim($runId, 'owner-1', $epoch, 2);
        if ($claim === false || !$claim) break;
        $batch = ['findings' => [], 'candidates' => [], 'records' => [], 'bytes' => 0];
        foreach ($claim as $row) {
            $rid = $row['id_bin'];
            foreach ($specFor($rid) as $f) {
                $f['generation_id']  = $gen;
                $f['valid_from_seq'] = $rseq;
                $batch['findings'][] = $f;
            }
            $batch['records'][] = ['ordinal' => $row['ordinal'], 'record_hash' => $row['hash'],
                                   'state' => ScanStore::REC_DONE,
                                   'version' => '1'];
        }
        $res = $store->commitBatch($runId, 'owner-1', $epoch, $batch);
        $results[] = $res;
        echo "$label: batch of " . count($claim) . " records -> "
           . ($res === true ? "COMMITTED" : "REFUSED: $res") . "\n";
        if ($res !== true) break;   // the worker requeues and retries the same rows
    }
    $store->finish($runId, ScanOutcome::derive(['manifestDone' => true, 'fenced' => true]));
    return $results;
}

$spec = function ($rid) use ($PID, $KEY) {
    return findingsFor($PID, $rid, $KEY, 1, [
        ['event_id' => 13, 'instance' => 1, 'host_form' => 'demographics', 'field' => 'mrn',
         'rule_source_id' => 'ann:aaaa:validate:0', 'reason_code' => 'checkdigit',
         'rule_ord' => 1, 'check_type' => 'checkchar', 'value' => 'AB12-9'],
    ]);
};

echo "=== SCENARIO 1: the SAME project scanned twice (generation_id is always 1) ===\n";
oneRun($store, $PID, ['1001', '1002'], $spec, 'run 1');
$n = $c->query('SELECT COUNT(*) FROM ' . Schema::table('finding'))->fetch_row()[0];
echo "findings stored after run 1: $n\n";
oneRun($store, $PID, ['1001', '1002'], $spec, 'run 2');
$n = $c->query('SELECT COUNT(*) FROM ' . Schema::table('finding'))->fetch_row()[0];
echo "findings stored after run 2: $n\n\n";

echo "=== SCENARIO 2: two findings on ONE field in ONE context (a checkbox with two hidden choices) ===\n";
$c->query('DELETE FROM ' . Schema::table('finding'));
$c->query('DELETE FROM ' . Schema::table('scan_run'));
$c->query('DELETE FROM ' . Schema::table('scan_record'));
$spec2 = function ($rid) use ($PID, $KEY) {
    // @UVCHOICES on a CHECKBOX field: UniversalValidator.php:576 emits one
    // finding per checked hidden code, all with reason 'hidden-choice'.
    return findingsFor($PID, $rid, $KEY, 1, [
        ['event_id' => 13, 'instance' => 1, 'host_form' => 'visit', 'field' => 'symptoms',
         'rule_source_id' => 'ann:bbbb:choices:0', 'reason_code' => 'hidden-choice',
         'rule_ord' => 2, 'check_type' => 'choices', 'value' => '3'],
        ['event_id' => 13, 'instance' => 1, 'host_form' => 'visit', 'field' => 'symptoms',
         'rule_source_id' => 'ann:bbbb:choices:0', 'reason_code' => 'hidden-choice',
         'rule_ord' => 2, 'check_type' => 'choices', 'value' => '7'],
    ]);
};
oneRun($store, $PID, ['2001'], $spec2, 'checkbox run');
$n = $c->query('SELECT COUNT(*) FROM ' . Schema::table('finding'))->fetch_row()[0];
echo "findings stored: $n\n\n";

// ScanRetention is the ONLY purge now. The store had one too - it removed
// the run, its records and its aggregates but NOT its findings - and the two
// had already drifted on what their second argument meant.
$ret = new INSPIRE\UniversalValidator\Scan\ScanRetention($db);
echo "=== SCENARIO 3: retention purge deletes by generation_id ONLY ===\n";
$c->query('DELETE FROM ' . Schema::table('finding'));
$c->query('DELETE FROM ' . Schema::table('scan_run'));
$c->query('DELETE FROM ' . Schema::table('scan_record'));
oneRun($store, 111, ['A1'], $spec, 'project 111');
oneRun($store, 222, ['B1'], function ($rid) use ($KEY) {
    return findingsFor(222, $rid, $KEY, 1, [
        ['event_id' => 1, 'instance' => 1, 'host_form' => 'f', 'field' => 'g',
         'rule_source_id' => 'ann:cccc:validate:0', 'reason_code' => 'checkdigit',
         'rule_ord' => 1, 'check_type' => 'checkchar', 'value' => 'X'],
    ]);
}, 'project 222');
$n = $c->query('SELECT COUNT(*) FROM ' . Schema::table('finding'))->fetch_row()[0];
echo "findings across both projects: $n\n";
// purgeRuns(pid, olderThan) on project 111 only
$ret->purgeRuns(111, date('Y-m-d H:i:s', time() + 3600));
$n = $c->query('SELECT COUNT(*) FROM ' . Schema::table('finding'))->fetch_row()[0];
echo "findings after purging project 111 only: $n  (project 222's findings should still be there)\n";

echo "\n=== SCENARIO 4: ScanRetention::purgeRuns deletes findings BY GENERATION (cross-project) ===\n";
foreach (array_reverse(Schema::tables()) as $t) $c->query('DROP TABLE IF EXISTS ' . $t);
Schema::migrate($m);
oneRun($store, 111, ['A1'], $spec, 'project 111');
oneRun($store, 222, ['B1'], function ($rid) use ($KEY) {
    return findingsFor(222, $rid, $KEY, 1, [
        ['event_id' => 1, 'instance' => 1, 'host_form' => 'f', 'field' => 'g',
         'rule_source_id' => 'ann:cccc:validate:0', 'reason_code' => 'checkdigit',
         'rule_ord' => 1, 'check_type' => 'checkchar', 'value' => 'X'],
    ]);
}, 'project 222');
$before = $c->query('SELECT COUNT(*) FROM ' . Schema::table('finding'))->fetch_row()[0];
echo "findings, both projects: $before\n";
// age project 111's run so it is past retention
$c->query("UPDATE " . Schema::table('scan_run') . " SET updated_at = '2000-01-01 00:00:00' WHERE project_id = 111");
$purged = $ret->purgeRuns(111, 1);
$after = $c->query('SELECT COUNT(*) FROM ' . Schema::table('finding'))->fetch_row()[0];
echo "purged $purged run(s) of project 111 -> findings left across ALL projects: $after";
echo ($after == 0 && $before > 1) ? "  <-- PROJECT 222's FINDINGS WERE DELETED TOO\n" : "\n";

echo "\n=== SCENARIO 5: a permanently refused batch has a way out (it used to retry forever) ===\n";
foreach (array_reverse(Schema::tables()) as $t) $c->query('DROP TABLE IF EXISTS ' . $t);
Schema::migrate($m);

// THE REAL WORKER, not a hand-rolled imitation of it. The whole finding is
// about the ORDER of three store calls - commit, count the attempt, hand the
// records back - and a harness that made those calls itself would prove only
// that the harness knows the order. ScanWorker is what has to know it.
//
// The evaluation below emits ONE IDENTITY TWICE for the same record, which is
// what a @UVCHOICES checkbox with two ticked hidden codes did before the locus
// discriminator landed. It is used here as a PERMANENT write refusal: the
// database says no, it will say no again next time, and nothing the worker can
// do will change that. On 1.9.10 the consequences were:
//
//   the batch rolled back, taking the `attempts` increment with it, because the
//   only statement that incremented it lived inside the failing transaction;
//   `recordAttempts` was therefore unreachable; the run never became terminal;
//   and it held the project's one active scan slot until somebody went into the
//   database. Forty identical batches is what the pilot logged.
{
    $PID5 = 333;
    $rec5 = 'C1';
    $hash5 = Hmac::raw(Hmac::P_RECORD, $PID5, $rec5, str_repeat('k', 32));
    $started = $store->startRun($PID5, ['created_by' => 'pilot']);
    $rid5 = (int) $started['run']['run_id'];
    $store->writeManifest($rid5, [['id_bin' => $rec5, 'hash' => $hash5, 'dag' => null]]);
    $row5 = $store->run($PID5, $rid5);
    $gen5 = (int) $row5['generation_id'];
    $seq5 = (int) $row5['run_seq'];

    $ATTEMPTS = 3;
    $same = Hmac::raw(Hmac::P_FINDING, $PID5, 'the-same-identity-twice', str_repeat('k', 32));
    $one = [
        'project_id' => $PID5, 'generation_id' => $gen5, 'identity' => $same,
        'valid_from_seq' => $seq5, 'record_hash' => $hash5, 'record_id_bin' => $rec5,
        'event_id' => 13, 'instance' => 1, 'host_form' => 'visit', 'field' => 'symptoms',
        'rule_source_id' => 'ann:bbbb:choices:0', 'rule_revision' => str_repeat('c', 64),
        'rule_ord' => 2, 'check_type' => 'choices', 'reason_code' => 'hidden-choice',
    ];
    $worker = new INSPIRE\UniversalValidator\Scan\ScanWorker($store, [
        'read'     => function (array $ids) use ($rec5) {
            $out = [];
            foreach ($ids as $id) $out[$id] = ['record_id' => $rec5];
            return ['ok' => true, 'data' => $out, 'why' => null];
        },
        'evaluate' => function ($id, array $node) use ($one) {
            return ['findings' => [$one, $one], 'bytes' => 0, 'contexts' => 1, 'why' => null];
        },
        'budget'   => new INSPIRE\UniversalValidator\Scan\WorkBudget(['mode' => 'browser']),
        'owner'    => 'pilot-worker',
        'attempts' => $ATTEMPTS,
        'note'     => function ($event, array $ctx) {
            echo "  log: $event - " . (isset($ctx['detail']) ? $ctx['detail'] : '') . "\n";
        },
    ]);

    $state = function () use ($c, $rid5) {
        return $c->query('SELECT state, attempts FROM ' . Schema::table('scan_record')
            . ' WHERE run_id = ' . $rid5)->fetch_row();
    };
    for ($pass = 1; $pass <= $ATTEMPTS + 2; $pass++) {
        $r = $worker->work($PID5, $rid5);
        $st = $state();
        echo "pass $pass: stop=" . (string) $r['stop']
           . " blocked=" . (int) $r['blocked']
           . " -> record state=" . $st[0] . " attempts=" . $st[1] . "\n";
        if ((int) $st[0] >= 100) break;
    }
    $st = $state();
    $unstored = INSPIRE\UniversalValidator\Scan\ScanStore::REC_UNSTORED;
    echo "final: state=" . $st[0] . " attempts=" . $st[1]
       . ((int) $st[0] === $unstored
            ? "  <-- UNSTORED: terminal, blocking, and the run can now end\n"
            : "  <-- STILL NOT TERMINAL: the run is wedged\n");
    echo "manifest complete: " . ($store->manifestComplete($rid5) ? 'yes' : 'no')
       . "  (this is what releases the project's scan slot)\n";
    $left = $c->query('SELECT COUNT(*) FROM ' . Schema::table('finding'))->fetch_row()[0];
    echo "findings stored from the refused batches: $left  (nothing was half-written)\n";
}
