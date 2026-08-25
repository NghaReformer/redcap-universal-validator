<?php
/**
 * tests/mysql/cases/store.php — SqlScanStore, the SAME class the module runs.
 *
 * The schema case asserts that the storage engine holds its invariants. This one
 * asserts that the store USES them correctly, which is a different claim: a
 * correct UNIQUE key with a store that catches the wrong exception still lets
 * two runs start. Only ScanDb differs between here and REDCap.
 *
 * The second half runs the cross-store contract - the identical assertions the
 * fast suite runs against ArrayScanStore - so the two implementations are judged
 * by one set rather than by two sets that agree with themselves.
 *
 * TWO PROJECTS. 700 is under test, 701 is the neighbour, and the neighbour holds
 * a live run and findings of its own. Every "does not resolve across projects"
 * claim below is worth something only because there is genuinely something on
 * the other side to resolve to.
 */

use INSPIRE\UniversalValidator\Scan\Schema;
use INSPIRE\UniversalValidator\Scan\SqlScanStore;
use INSPIRE\UniversalValidator\Scan\ScanStore;
use INSPIRE\UniversalValidator\Scan\ScanOutcome;

$PID = 700;
$NEIGHBOUR = uv_neighbour($PID);

for ($i = 1; $i <= 2; $i++) {
    $A->query('INSERT INTO ' . Schema::table('scan_worker_slot') . ' (slot_no, epoch) VALUES (' . $i . ', 0)');
}

$storeA = new SqlScanStore($dbA);
$storeB = new SqlScanStore($dbB);

// THE NEIGHBOUR, BUILT THROUGH THE SAME STORE. Two projects on one
// installation is the ordinary case and it was the one case this suite never
// had: a schema holding a single project cannot tell a statement that scopes by
// project from one that does not. Planted before anything below runs, so every
// count, every locator and every slot claim has something on the other side of
// the fence to be wrong about.
$nb = uv_plant_neighbour($dbA, $PID);
check('store: the neighbouring project has a run and findings of its own',
    is_array($nb) && uv_neighbour_findings($dbA) === 2);

// START: one wins, the other is told busy WITHOUT being told anything else.
$r1 = $storeA->startRun($PID, ['created_by' => 'alice']);
check('store: the first start succeeds', $r1['ok'] === true && $r1['busy'] === false);
$runId = (int) $r1['run']['run_id'];
$r2 = $storeB->startRun($PID, ['created_by' => 'bob']);
check('store: a second start on the same project is BUSY, not an error',
    $r2['ok'] === false && $r2['busy'] === true && $r2['run'] === null);
check('store: and busy names no run, owner or scope',
    preg_match('/\d/', $r2['why']) === 0 && stripos($r2['why'], 'alice') === false);

// The run id is a LOCATOR. It must not resolve across projects.
check('store: a run id from another project does not resolve',
    $storeB->run($NEIGHBOUR, $runId) === null);
check('store: but resolves for its own', $storeA->run($PID, $runId) !== null);

// MANIFEST: totals are set with the rows, in one transaction.
$recs = [];
for ($i = 1; $i <= 7; $i++) {
    $recs[] = ['id_bin' => 'REC-' . $i, 'hash' => hash('sha256', 'REC-' . $i, true),
               'dag' => $i % 2 ? 'north' : null];
}
check('store: the manifest writes every record', $storeA->writeManifest($runId, $recs) === 7);
$run = $storeA->run($PID, $runId);
check('store: and publishes the total with them', (int) $run['manifest_total'] === 7);
check('store: leaving the run ready to scan', $run['phase'] === 'scanning');
check('store: an empty manifest is not complete-by-vacuum',
    $storeA->manifestComplete($runId) === false);

// CLAIM: fenced on the epoch.
$epoch = (int) $run['lease_epoch'];
$claim = $storeA->claim($runId, 'workerA', $epoch, 3);
check('store: a claim returns the requested range', count($claim) === 3);
check('store: in ordinal order', $claim[0]['ordinal'] === 1 && $claim[2]['ordinal'] === 3);
check('store: carrying the worker locator, not a hash',
    $claim[0]['id_bin'] === 'REC-1');
// REFUSED, not empty. Both were [], and a worker reading "you may not claim"
// as "there is nothing left" walked the first live pilot's 39-record run to its
// final phase having examined three records.
$stale = $storeB->claim($runId, 'workerB', $epoch - 1, 3);
check('store: a claim at a STALE epoch is refused', $stale === false);
check('store: and that is distinguishable from an empty run', $stale !== []);

// COMMIT: findings + record states + counters, atomically and fenced.
$batch = ['bytes' => 40, 'records' => [], 'findings' => []];
foreach ($claim as $c) {
    $batch['records'][] = ['ordinal' => $c['ordinal'], 'state' => ScanStore::REC_DONE,
                           'version' => 'v1'];
    $batch['findings'][] = [
        'generation_id' => 1, 'identity' => hash('sha256', 'f' . $c['ordinal'], true),
        'seq' => 1, 'record_hash' => $c['hash'], 'record_id_bin' => $c['id_bin'],
        'event_id' => null, 'instance' => 1, 'host_form' => 'fa', 'field' => 'x',
        'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64), 'rule_ord' => 1,
        'check_type' => 'required', 'reason_code' => 'required-blank',
    ];
}
check('store: a fenced batch commits', $storeA->commitBatch($runId, 'workerA', $epoch, 0, $batch) === true);
$run = $storeA->run($PID, $runId);
check('store: advancing manifest_done by the records it finished',
    (int) $run['manifest_done'] === 3);
check('store: and counting the findings it retained', (int) $run['detail_rows'] === 3);
check('store: still not complete with four records left',
    $storeA->manifestComplete($runId) === false);

// A worker whose epoch moved must commit NOTHING - not "some of it".
$storeB->cancel($PID, $runId, 'admin');
$after = $storeA->run($PID, $runId);
check('store: cancel bumps the epoch', (int) $after['lease_epoch'] === $epoch + 1);
$rows0 = $storeA->run($PID, $runId)['detail_rows'];
$lost = ['bytes' => 10, 'records' => [['ordinal' => 4, 'state' => ScanStore::REC_DONE]],
         'findings' => [[
            'generation_id' => 1, 'identity' => hash('sha256', 'f-lost', true), 'seq' => 1,
            'record_hash' => hash('sha256', 'REC-4', true), 'record_id_bin' => 'REC-4',
            'instance' => 1, 'host_form' => 'fa', 'field' => 'x', 'rule_source_id' => 'r1',
            'rule_revision' => str_repeat('c', 64), 'check_type' => 'required',
            'reason_code' => 'required-blank']]];
$overtaken = $storeA->commitBatch($runId, 'workerA', $epoch, 0, $lost);
check('store: an overtaken worker cannot commit', $overtaken !== true);
// The refusal names WHICH fence stopped it. During the pilot a run failed its
// very first commit and one message covered a stopped run, a taken-over run and
// a database that would not write. Here the cause is a CANCELLATION - which is
// checked before the epoch precisely because it is the more specific answer:
// a cancel bumps the epoch too, so reporting the takeover would be true and
// useless.
check('store: and is told the scan was stopped, the more specific of the two',
    is_string($overtaken) && strpos($overtaken, 'was stopped') !== false);
check('store: and left NO finding behind',
    (int) $storeA->run($PID, $runId)['detail_rows'] === (int) $rows0);
$left = $ca->query('SELECT COUNT(*) FROM ' . Schema::table('finding')
    . " WHERE record_id_bin = 'REC-4'", []);
check('store: the rolled-back finding row does not exist', (int) $left[0][0] === 0);
$st4 = $ca->query('SELECT state FROM ' . Schema::table('scan_record')
    . ' WHERE run_id = ' . $runId . ' AND ordinal = 4', []);
check('store: and its record is still pending, so it can be re-claimed',
    (int) $st4[0][0] === ScanStore::REC_PENDING);

// FINISH: releases the slot, idempotently.
$outcome = ScanOutcome::derive(['fenced' => true, 'manifestDone' => false, 'blocked' => true]);
check('store: finishing a run succeeds once', $storeA->finish($runId, $outcome) === true);
check('store: and a retried finaliser changes nothing',
    $storeA->finish($runId, $outcome) === false);
check('store: the slot is released, so the next scan may start',
    $storeB->startRun($PID, ['created_by' => 'carol'])['ok'] === true);

// SLOTS USED TO BE ASSERTED HERE TWICE. Six checks stood here driving
// ScanStore::leaseSlot() and ::releaseSlot() - a second semaphore over the same
// uv_scan_worker_slot table that no production caller ever used, while
// WorkerSlots::acquire()/release() did the real work. Both were deleted rather
// than repaired, so these went with them; the live pair is asserted in
// cases/slots.php, which is where it always should have been.

// RETENTION: a value expires without the finding disappearing.
$A->query('UPDATE ' . Schema::table('finding') . " SET value_bin = 'secret',
    value_expires_at = '2000-01-01 00:00:00' WHERE record_id_bin = 'REC-1'");
check('store: an expired value is cleared', $storeA->expireValues(gmdate('Y-m-d H:i:s')) >= 1);
$v = $ca->query('SELECT value_bin FROM ' . Schema::table('finding')
    . " WHERE record_id_bin = 'REC-1'", []);
check('store: the value is gone', $v[0][0] === null);
$cnt = $ca->query('SELECT COUNT(*) FROM ' . Schema::table('finding')
    . " WHERE record_id_bin = 'REC-1'", []);
check('store: but the finding remains - a report must not shrink as it ages',
    (int) $cnt[0][0] === 1);

// -- the SHARED contract, against SqlScanStore --------------------------------
//
// The identical assertions run in the fast suite against ArrayScanStore. Two
// independent implementations judged by one set disagree wherever the contract
// is ambiguous - which is exactly how the affected()-versus-FOR-UPDATE fence bug
// would have surfaced, since an in-memory store has no notion of "rows changed".
//
// Each scenario needs a clean slate, so the factory truncates rather than
// reconnecting: the contract must not know which store it is judging, and must
// not be handed a store that remembers the previous scenario's run.
require_once __DIR__ . '/../../scan_store_contract.php';

$fresh = function () use ($A, $storeA) {
    foreach (array('scan_record', 'finding', 'scan_run', 'scan_worker_slot', 'scan_audit') as $t) {
        $A->query('DELETE FROM ' . Schema::table($t));
    }
    for ($i = 1; $i <= 2; $i++) {
        $A->query('INSERT INTO ' . Schema::table('scan_worker_slot')
            . ' (slot_no, epoch) VALUES (' . $i . ', 0)');
    }
    return $storeA;
};
\INSPIRE\UniversalValidator\Scan\storeContract($fresh, 'sql-store');
