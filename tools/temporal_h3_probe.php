<?php
// Temporal probe: what does the H3 sanitiser actually emit for the exact
// failure tests/mysql/run.php:757 claims proves "the column is named"?
namespace INSPIRE\UniversalValidator\Scan;
require __DIR__ . '/../php/Scan/Schema.php';
require __DIR__ . '/../php/Scan/ScanDb.php';
require __DIR__ . '/../php/Scan/ScanStore.php';
require __DIR__ . '/../php/Scan/SqlScanStore.php';
require __DIR__ . '/../php/Scan/Hmac.php';
require __DIR__ . '/../php/Scan/DbError.php';
require __DIR__ . '/../php/Scan/ScanOutcome.php';
require __DIR__ . '/../php/Scan/ScanPhase.php';

final class Db implements ScanDb {
    private $m;
    public function __construct($m) { $this->m = $m; }
    public function select($sql, array $p = []) { return $this->query($sql, $p); }
    public function query($sql, array $p = []) {
        $st = $this->m->prepare($sql);
        if (!$st) throw new \RuntimeException($this->m->error);
        if ($p) $st->bind_param(str_repeat('s', count($p)), ...array_map(function($x){ return $x; }, $p));
        if (!$st->execute()) throw new \RuntimeException($st->error);
        $r = $st->get_result(); $out = [];
        if ($r) while ($row = $r->fetch_row()) $out[] = $row;
        return $out;
    }
    public function exec($sql, array $p = []) { $this->query($sql, $p); return true; }
    public function affected() { return $this->m->affected_rows; }
    public function begin() { $this->m->begin_transaction(); }
    public function commit() { $this->m->commit(); }
    public function rollback() { $this->m->rollback(); }
}

$m = new \mysqli(getenv('UV_DB_HOST'), getenv('UV_DB_USER'), getenv('UV_DB_PASS'),
                 getenv('UV_DB_NAME'), (int) getenv('UV_DB_PORT'));
$db = new Db($m);
Schema::migrate($db);
foreach (['scan_record','finding','scan_run'] as $t) $m->query('DELETE FROM ' . Schema::table($t));

$store = new SqlScanStore($db, 'k');
$r   = $store->startRun(900, ['created_by' => 'alice']);
$rid = (int) $r['run']['run_id'];
$store->writeManifest($rid, [['id_bin' => 'R1', 'hash' => hash('sha256','R1',true), 'dag' => null]]);
$epoch = (int) $store->run(900, $rid)['lease_epoch'];
$store->claim($rid, 'w', $epoch, 1);

$bad = ['bytes' => 10,
    'records'  => [['ordinal' => 1, 'state' => ScanStore::REC_DONE]],
    'findings' => [[
        'generation_id' => 1, 'identity' => hash('sha256','bad',true), 'seq' => 1,
        'record_hash' => hash('sha256','R1',true), 'record_id_bin' => 'R1',
        'instance' => 1, 'host_form' => 'fa', 'field' => 'x', 'rule_source_id' => 'r1',
        'rule_revision' => str_repeat('c',64), 'check_type' => 'required',
        'reason_code' => str_repeat('z',200)]]];   // column is VARCHAR(64)

$refused = $store->commitBatch($rid, 'w', $epoch, $bad);
echo "MESSAGE THE OPERATOR SEES:\n  " . $refused . "\n\n";
printf("  contains 'reason_code' (the column name) : %s\n", strpos($refused,'reason_code') !== false ? 'YES' : 'NO');
printf("  contains 'too long'    (the generic half): %s\n", strpos($refused,'too long')    !== false ? 'YES' : 'NO');
echo "\n  tests/mysql/run.php:757 asserts (A || B). It is passing on B alone.\n";
