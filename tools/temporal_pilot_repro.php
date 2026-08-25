<?php
/**
 * temporal_pilot_repro.php — reproduce the live pilot's "the database refused to
 * store these findings" against a REAL MySQL, using the shipped SqlScanStore.
 *
 * Run:
 *   UV_DB_HOST=127.0.0.1 UV_DB_PORT=33306 UV_DB_USER=root UV_DB_PASS=root \
 *   UV_DB_NAME=uv_test php -d extension=mysqli tools/temporal_pilot_repro.php
 */

require_once __DIR__ . '/../php/Scan/Schema.php';
require_once __DIR__ . '/../php/Scan/ScanOutcome.php';
require_once __DIR__ . '/../php/Scan/ScanPhase.php';
require_once __DIR__ . '/../php/Scan/ScanStore.php';
require_once __DIR__ . '/../php/Scan/ScanDb.php';
require_once __DIR__ . '/../php/Scan/SqlScanStore.php';
require_once __DIR__ . '/../php/Scan/Hmac.php';

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
                'reason_code' => $s['reason_code']];
        $out[] = [
            'generation_id' => $gen,
            'identity' => Hmac::findingIdentity($pid, $loc, $key),
            'seq' => ++$seq,
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
    $started = $store->startRun($pid, ['generation_id' => 1, 'created_by' => 'tester',
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
    $epoch = (int) $store->run($pid, $runId)['lease_epoch'];
    $results = [];
    while (true) {
        $claim = $store->claim($runId, 'owner-1', $epoch, 2);
        if ($claim === false || !$claim) break;
        $batch = ['findings' => [], 'candidates' => [], 'records' => [], 'bytes' => 0];
        foreach ($claim as $row) {
            $rid = $row['id_bin'];
            foreach ($specFor($rid) as $f) $batch['findings'][] = $f;
            $batch['records'][] = ['ordinal' => $row['ordinal'], 'state' => ScanStore::REC_DONE,
                                   'version' => '1'];
        }
        $res = $store->commitBatch($runId, 'owner-1', $epoch, null, $batch);
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
$store->purgeRuns(111, date('Y-m-d H:i:s', time() + 3600));
$n = $c->query('SELECT COUNT(*) FROM ' . Schema::table('finding'))->fetch_row()[0];
echo "findings after purging project 111 only: $n  (project 222's findings should still be there)\n";

echo "\n=== SCENARIO 4: ScanRetention::purgeRuns deletes findings BY GENERATION (cross-project) ===\n";
require_once __DIR__ . '/../php/Scan/ScanRetention.php';
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
$ret = new INSPIRE\UniversalValidator\Scan\ScanRetention($db);
$purged = $ret->purgeRuns(111, 1);
$after = $c->query('SELECT COUNT(*) FROM ' . Schema::table('finding'))->fetch_row()[0];
echo "purged $purged run(s) of project 111 -> findings left across ALL projects: $after";
echo ($after == 0 && $before > 1) ? "  <-- PROJECT 222's FINDINGS WERE DELETED TOO\n" : "\n";

echo "\n=== SCENARIO 5: a refused batch does not increment attempts (retry cap never trips) ===\n";
foreach (array_reverse(Schema::tables()) as $t) $c->query('DROP TABLE IF EXISTS ' . $t);
Schema::migrate($m);
oneRun($store, 333, ['C1'], $spec2, 'first run (checkbox, refused)');
$row = $c->query('SELECT state, attempts FROM ' . Schema::table('scan_record'))->fetch_row();
echo "record state=" . $row[0] . " attempts=" . $row[1]
   . "  (state 0 = pending, so it is handed straight back; attempts stays 0 forever)\n";
