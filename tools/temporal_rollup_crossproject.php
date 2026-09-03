<?php
/** Does project A's rollup count project B's findings? generation_id is 1 for both. */
require_once __DIR__ . '/../php/Scan/Schema.php';
require_once __DIR__ . '/../php/Scan/ScanOutcome.php';
require_once __DIR__ . '/../php/Scan/ScanPhase.php';
require_once __DIR__ . '/../php/Scan/ScanStore.php';
require_once __DIR__ . '/../php/Scan/ScanDb.php';
require_once __DIR__ . '/../php/Scan/SqlScanStore.php';
require_once __DIR__ . '/../php/Scan/RollupBuilder.php';
require_once __DIR__ . '/../php/Scan/Hmac.php';

use INSPIRE\UniversalValidator\Scan\Schema;
use INSPIRE\UniversalValidator\Scan\SqlScanStore;
use INSPIRE\UniversalValidator\Scan\ScanStore;
use INSPIRE\UniversalValidator\Scan\RollupBuilder;
use INSPIRE\UniversalValidator\Scan\Hmac;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$c = new mysqli('127.0.0.1', 'root', 'root', 'uv_test', 33306);
$c->set_charset('utf8mb4');
function bindAll($st, array $v) { $r = []; foreach ($v as $k => $x) $r[$k] = &$v[$k]; array_unshift($r, str_repeat('s', count($v))); call_user_func_array([$st, 'bind_param'], $r); }
class Db implements INSPIRE\UniversalValidator\Scan\ScanDb {
    private $c; private $a = 0;
    public function __construct($c) { $this->c = $c; }
    public function select($s, array $p = []) { if (!$p) { $r = $this->c->query($s); return $r === true ? [] : $this->rows($r); } $st = $this->c->prepare($s); bindAll($st, array_values($p)); $st->execute(); $r = $st->get_result(); $o = $r === false ? [] : $this->rows($r); $st->close(); return $o; }
    public function exec($s, array $p = []) { if (!$p) { $this->c->query($s); $this->a = $this->c->affected_rows; return; } $st = $this->c->prepare($s); bindAll($st, array_values($p)); $st->execute(); $this->a = $st->affected_rows; $st->close(); }
    public function affected() { return $this->a; }
    public function begin() { $this->c->query('START TRANSACTION'); }
    public function commit() { $this->c->query('COMMIT'); }
    public function rollback() { $this->c->query('ROLLBACK'); }
    private function rows($r) { $o = []; while ($x = $r->fetch_row()) $o[] = $x; $r->free(); return $o; }
}
class Mod { private $c; public function __construct($c) { $this->c = $c; }
    public function query($s, $p = []) { if (!$p) { $r = $this->c->query($s); return $r === true ? [] : $this->rows($r); } $st = $this->c->prepare($s); bindAll($st, array_values($p)); $st->execute(); $r = $st->get_result(); $o = $r === false ? [] : $this->rows($r); $st->close(); return $o; }
    private function rows($r) { $o = []; while ($x = $r->fetch_row()) $o[] = $x; $r->free(); return $o; } }

foreach (array_reverse(Schema::tables()) as $t) $c->query('DROP TABLE IF EXISTS ' . $t);
Schema::migrate(new Mod($c));
$db = new Db($c); $store = new SqlScanStore($db); $KEY = str_repeat('k', 32);

function startAndFill($store, $db, $pid, $form, $dag, $n, $KEY) {
    $s = $store->startRun($pid, ['generation_id' => 1, 'created_by' => 'u', 'fingerprint' => str_repeat('f',64)]);
    $runId = (int) $s['run']['run_id'];
    $store->writeManifest($runId, [['id_bin' => 'r1', 'hash' => Hmac::raw(Hmac::P_RECORD, $pid, 'r1', $KEY), 'dag' => $dag]]);
    $epoch = (int) $store->run($pid, $runId)['lease_epoch'];
    $claim = $store->claim($runId, 'o', $epoch, 5);
    $batch = ['findings' => [], 'candidates' => [], 'records' => [], 'bytes' => 0];
    for ($i = 0; $i < $n; $i++) {
        $loc = ['record' => 'r1', 'event_id' => 1, 'instance' => 1, 'host_form' => $form,
                'field' => 'f' . $i, 'rule_source_id' => 'ann:x:validate:0', 'reason_code' => 'checkdigit'];
        $batch['findings'][] = ['generation_id' => 1, 'identity' => Hmac::findingIdentity($pid, $loc, $KEY),
            'seq' => $i + 1, 'record_hash' => Hmac::raw(Hmac::P_RECORD, $pid, 'r1', $KEY), 'record_id_bin' => 'r1',
            'event_id' => 1, 'instance' => 1, 'host_form' => $form, 'field' => 'f' . $i,
            'rule_source_id' => 'ann:x:validate:0', 'rule_revision' => str_repeat('a', 64), 'rule_ord' => 1,
            'check_type' => 'checkchar', 'reason_code' => 'checkdigit', 'dag_key' => $dag];
    }
    foreach ($claim as $row) $batch['records'][] = ['ordinal' => $row['ordinal'], 'state' => ScanStore::REC_DONE, 'version' => '1'];
    $r = $store->commitBatch($runId, 'o', $epoch, $batch);
    echo "project $pid: commit " . ($r === true ? 'ok' : $r) . "\n";
    return [$runId, $epoch];
}

list($runA, $epochA) = startAndFill($store, $db, 101, 'form_of_project_A', 'group_A', 2, $KEY);
$store->finish($runA, ['terminal' => 'succeeded', 'coverage' => 'partial', 'detail' => 'complete']);
list($runB, $epochB) = startAndFill($store, $db, 202, 'SECRET_form_of_project_B', 'SECRET_dag_B', 5, $KEY);

// Now roll up project A's run only.
$roll = new RollupBuilder($db, $store);
$s = $store->startRun(101, ['generation_id' => 1, 'created_by' => 'u', 'fingerprint' => str_repeat('f',64)]);
$runA2 = (int) $s['run']['run_id'];
$e2 = (int) $store->run(101, $runA2)['lease_epoch'];
do { $r = $roll->step($runA2, $e2, 1, 1000); } while (!$r['done']);
echo "\naggregates recorded against PROJECT 101's run:\n";
foreach ($db->select('SELECT kind, axis1, cnt FROM ' . Schema::table('scan_aggregate') . ' WHERE run_id = ?', [$runA2]) as $row) {
    printf("  %-14s %-28s %s\n", $row[0], $row[1], $row[2]);
}
