<?php
/**
 * tests/mysql/cases/store.php — SqlScanStore, the SAME class the module runs.
 *
 * The schema case asserts that the storage engine holds its invariants. This one
 * asserts that the store USES them correctly, which is a different claim: a
 * correct UNIQUE key with a store that catches the wrong exception still lets
 * two runs start. Only ScanDb differs between here and REDCap.
 *
 * The last section runs the cross-store contract - the identical assertions the
 * fast suite runs against ArrayScanStore - so the two implementations are judged
 * by one set rather than by two sets that agree with themselves.
 *
 * TWO PROJECTS. 700 is under test, 701 is the neighbour, and the neighbour holds
 * a live run and findings of its own IN THE SAME GENERATION. Every "does not
 * resolve across projects" claim below is worth something only because there is
 * genuinely something on the other side to resolve to, and only because the two
 * sides are not held apart by a generation number production would never give
 * them.
 *
 * AND THE SECTION BEFORE THE CONTRACT IS THE POINT OF THIS RELEASE. A run of
 * this file used to assert nothing whatever about the second scan of a project,
 * about a record examined twice inside one run, or about two findings at one
 * field - which is precisely the set of things that failed on every live pilot.
 * Each of those blocks names the incident it comes from.
 */

use INSPIRE\UniversalValidator\Scan\Schema;
use INSPIRE\UniversalValidator\Scan\SqlScanStore;
use INSPIRE\UniversalValidator\Scan\ScanStore;
use INSPIRE\UniversalValidator\Scan\ScanOutcome;
use INSPIRE\UniversalValidator\Scan\ScanPhase;
use INSPIRE\UniversalValidator\Scan\Hmac;

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
    is_array($nb) && uv_neighbour_findings($dbA, $PID) === 2);

// START: one wins, the other is told busy WITHOUT being told anything else.
$r1 = $storeA->startRun($PID, ['created_by' => 'alice']);
check('store: the first start succeeds', $r1['ok'] === true && $r1['busy'] === false);
$runId = (int) $r1['run']['run_id'];
$r2 = $storeB->startRun($PID, ['created_by' => 'bob']);
check('store: a second start on the same project is BUSY, not an error',
    $r2['ok'] === false && $r2['busy'] === true && $r2['run'] === null);
check('store: and busy names no run, owner or scope',
    preg_match('/\d/', $r2['why']) === 0 && stripos($r2['why'], 'alice') === false);
// ONE SENTENCE, ONE OWNER. There were three copies of it - one in each store and
// one in ScanAuthorization::busy(), which existed to keep them identical and
// which nothing ever called - and two of the three had already drifted from the
// third. Asserting the constant rather than the words is the point: a test that
// spelled the sentence out would be a fourth copy to drift.
check('store: and it is the contract\'s own sentence, not this store\'s copy of it',
    $r2['why'] === ScanStore::BUSY_WHY);

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
//
// THE GENERATION AND THE SEQUENCE ARE READ FROM THE RUN, never written here.
// Both used to be the literal 1, which is precisely what shipped: the store
// defaulted the generation in three places, no caller ever supplied one, and
// the second scan of any project re-inserted identities that were still active.
// A fixture that keeps writing 1 cannot tell that apart from a fix.
$gen = (int) $run['generation_id'];
$runSeq = uv_run_seq($dbA, $runId);
$batch = ['bytes' => 40, 'records' => [], 'findings' => []];
foreach ($claim as $c) {
    // record_hash on the record row is what lets commitBatch close this
    // record's own prior findings inside the transaction that writes the new
    // ones. A record row without it commits, and silently exercises a path no
    // worker takes.
    $batch['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                           'state' => ScanStore::REC_DONE, 'version' => 'v1'];
    $batch['findings'][] = [
        'project_id' => $PID,
        'generation_id' => $gen, 'identity' => hash('sha256', 'f' . $c['ordinal'], true),
        'valid_from_seq' => $runSeq,
        'record_hash' => $c['hash'], 'record_id_bin' => $c['id_bin'],
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

// THE READ, WHICH IS WHERE THIS WOULD HAVE BECOME A DISCLOSURE. findings() had
// no production caller at all, which is the only reason a page filtered by
// generation alone - with the generation the literal 1 for every project - was
// stored corruption rather than one project's report showing another project's
// records. Both projects hold findings in generation 1 here, so the two pages
// below are the same question asked twice with different projects.
check('store: this project and the neighbour really are in one generation',
    (int) $nb['generation_id'] === $gen);
check('store: a page of findings answers for the project it was asked about',
    count($storeA->findings($PID, $gen, [], 0, 100)) === 3);
check('store: and the neighbour\'s page for the neighbour\'s',
    count($storeA->findings($NEIGHBOUR, $gen, [], 0, 100)) === 2);
check('store: still not complete with four records left',
    $storeA->manifestComplete($runId) === false);

// A worker whose epoch moved must commit NOTHING - not "some of it".
$storeB->cancel($PID, $runId, 'admin');
$after = $storeA->run($PID, $runId);
check('store: cancel bumps the epoch', (int) $after['lease_epoch'] === $epoch + 1);
$rows0 = $storeA->run($PID, $runId)['detail_rows'];
$lost = ['bytes' => 10,
         'records' => [['ordinal' => 4, 'record_hash' => hash('sha256', 'REC-4', true),
                        'state' => ScanStore::REC_DONE]],
         'findings' => [[
            'project_id' => $PID,
            'generation_id' => $gen, 'identity' => hash('sha256', 'f-lost', true),
            'valid_from_seq' => $runSeq,
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

// -- WHAT THE PER-PROJECT GENERATION MADE ASSERTABLE --------------------------
//
// Everything from here to the shared contract is a claim that could not be made
// against the version-1 schema, because the generation was the literal 1 for
// every run of every project and uv_finding carried no project column at all.
// Each block names the incident it comes from.

// THE SECOND SCAN OF A PROJECT.
//
// This is the one that killed five live pilots and it had never been asserted
// anywhere, against a real server or otherwise. Nothing superseded a previous
// run's rows and every run reported generation 1, so the second scan of any
// project re-inserted identities that were still active, uq_active_identity
// refused them, and the batch rolled back - forty times, identically, with no
// exit, because a rolled-back commit could not increment the attempt counter
// that was supposed to give up.
$SECOND = 720;
{
    $s = new SqlScanStore($dbA);
    $rec = ['id_bin' => 'S-1', 'hash' => hash('sha256', 'S-1', true), 'dag' => null];
    // ONE IDENTITY, REUSED DELIBERATELY. A finding identity is location plus
    // rule plus reason and deliberately not the value, so a project whose data
    // has not been fixed between two scans produces exactly the same bytes the
    // second time. That is the point: it is what makes the interval real, and
    // it is what the second scan used to die on.
    $identity = hash('sha256', 'the-same-finding-both-times', true);
    $finding = function ($gen, $seq) use ($SECOND, $rec, $identity) {
        return ['project_id' => $SECOND, 'generation_id' => $gen, 'identity' => $identity,
                'valid_from_seq' => $seq, 'record_hash' => $rec['hash'],
                'record_id_bin' => $rec['id_bin'], 'instance' => 1,
                'host_form' => 'fa', 'field' => 'x', 'rule_source_id' => 'r1',
                'rule_revision' => str_repeat('c', 64), 'check_type' => 'required',
                'reason_code' => 'required-blank'];
    };

    $one = $s->startRun($SECOND, ['created_by' => 'alice']);
    $rid1 = (int) $one['run']['run_id'];
    $gen1 = (int) $one['run']['generation_id'];
    $s->writeManifest($rid1, [$rec]);
    $ep1 = (int) $s->run($SECOND, $rid1)['lease_epoch'];
    $c1 = $s->claim($rid1, 'w', $ep1, 1);
    check('store: the first scan of a fresh project commits its findings',
        $s->commitBatch($rid1, 'w', $ep1, 0, ['bytes' => 0,
            'records' => [['ordinal' => $c1[0]['ordinal'], 'record_hash' => $c1[0]['hash'],
                           'state' => ScanStore::REC_DONE]],
            'findings' => [$finding($gen1, uv_run_seq($dbA, $rid1))]]) === true);
    $s->finish($rid1, ScanOutcome::derive(['fenced' => true, 'manifestDone' => true]));

    $two = $s->startRun($SECOND, ['created_by' => 'bob']);
    check('store: a second scan of the same project may start', $two['ok'] === true);
    $rid2 = (int) $two['run']['run_id'];
    $gen2 = (int) $two['run']['generation_id'];
    check('store: and is handed a generation the first run did not use', $gen2 > $gen1);
    $s->writeManifest($rid2, [$rec]);
    $ep2 = (int) $s->run($SECOND, $rid2)['lease_epoch'];
    $c2 = $s->claim($rid2, 'w', $ep2, 1);
    $again = $s->commitBatch($rid2, 'w', $ep2, 0, ['bytes' => 0,
        'records' => [['ordinal' => $c2[0]['ordinal'], 'record_hash' => $c2[0]['hash'],
                       'state' => ScanStore::REC_DONE]],
        'findings' => [$finding($gen2, uv_run_seq($dbA, $rid2))]]);
    check('store: and the SECOND scan commits the same finding rather than rolling back',
        $again === true);
    if ($again !== true) fwrite(STDERR, '  the second scan said: ' . (string) $again . "\n");
    // BOTH ROWS SURVIVE, one per generation. An "as of run N" view is the whole
    // reason closing is an update rather than a delete, and a second scan that
    // overwrote its predecessor would answer the same as one that could not
    // commit at all from the caller's side.
    $vers = $dbA->select('SELECT generation_id, active_slot FROM ' . Schema::table('finding')
        . ' WHERE project_id = ? AND finding_identity = ? ORDER BY finding_id',
        [$SECOND, $identity]);
    check('store: keeping both generations of it on record',
        count($vers) === 2 && (int) $vers[0][0] === $gen1 && (int) $vers[1][0] === $gen2);
    check('store: each active in its own generation, which is what the interval means',
        count($vers) === 2 && (int) $vers[0][1] === 1 && (int) $vers[1][1] === 1);
}

// A RECORD RE-EXAMINED INSIDE ONE RUN.
//
// The other half of the same failure, and the one that did not need a second
// scan to reach: a record edited during a run is requeued by catch-up,
// re-examined, and produces the same violation again - so commitBatch inserted
// an identity its own first pass had already committed as active. On a FIRST
// run of a fresh installation.
$REEXAMINED = 730;
{
    $s = new SqlScanStore($dbA);
    $hash = hash('sha256', 'X-1', true);
    $r = $s->startRun($REEXAMINED, ['created_by' => 'alice']);
    $rid = (int) $r['run']['run_id'];
    $gen = (int) $r['run']['generation_id'];
    $seq = uv_run_seq($dbA, $rid);
    $identity = hash('sha256', 'still-blank-both-times', true);
    $s->writeManifest($rid, [['id_bin' => 'X-1', 'hash' => $hash, 'dag' => null]]);
    $ep = (int) $s->run($REEXAMINED, $rid)['lease_epoch'];
    $batch = function ($ordinal) use ($REEXAMINED, $gen, $seq, $hash, $identity) {
        return ['bytes' => 0,
                'records' => [['ordinal' => $ordinal, 'record_hash' => $hash,
                               'state' => ScanStore::REC_DONE]],
                'findings' => [['project_id' => $REEXAMINED, 'generation_id' => $gen,
                                'identity' => $identity, 'valid_from_seq' => $seq,
                                'record_hash' => $hash, 'record_id_bin' => 'X-1',
                                'instance' => 1, 'host_form' => 'fa', 'field' => 'x',
                                'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64),
                                'check_type' => 'required', 'reason_code' => 'required-blank']]];
    };
    $first = $s->claim($rid, 'w', $ep, 1);
    check('store: the first examination of the record commits',
        $s->commitBatch($rid, 'w', $ep, 0, $batch($first[0]['ordinal'])) === true);

    // Someone saves the record. Catch-up is the one sanctioned exception to the
    // frozen manifest, so the requeue only exists inside that phase.
    check('store: the run may enter catch-up', $s->advancePhase($rid, $ep, ScanPhase::CATCH_UP));
    check('store: where a finished record may be sent back to pending',
        $s->requeue($rid, $ep, ['X-1']) === 1);
    $back = $s->claimPending($rid, 'w', $ep, 1);
    check('store: and the straggler sweep offers it again', is_array($back) && count($back) === 1);

    $second = $s->commitBatch($rid, 'w', $ep, 0, $batch($back[0]['ordinal']));
    check('store: a record re-examined inside one run commits the same finding again',
        $second === true);
    if ($second !== true) fwrite(STDERR, '  the re-examination said: ' . (string) $second . "\n");
    $rows = $dbA->select('SELECT active_slot, valid_to_seq FROM ' . Schema::table('finding')
        . ' WHERE project_id = ? AND finding_identity = ? ORDER BY finding_id',
        [$REEXAMINED, $identity]);
    check('store: leaving exactly one active row for that identity',
        count($rows) === 2 && $rows[0][0] === null && (int) $rows[1][0] === 1);
    // CLOSED, NOT DELETED, and closed AT A SEQUENCE. valid_to_seq used to be
    // written from a per-record ordinal while valid_from_seq came from the run,
    // which put the two ends of the interval in different number spaces.
    check('store: with the superseded row carrying the run sequence that closed it',
        count($rows) === 2 && $rows[0][1] !== null && (int) $rows[0][1] === $seq);
}

// TWO FINDINGS AT ONE LOCATION. The checkbox case.
//
// A @UVCHOICES rule on a checkbox emits one finding per ticked hidden code, all
// with reason `hidden-choice`, so two ticked hidden options at one field were
// byte-identical in every hashed field: the unique key refused the second, the
// whole batch rolled back, and a first scan of a fresh project stored nothing at
// all for records it had examined correctly. `locus` is the discriminator, and
// it is part of the LOCATION rather than of the value.
$CHECKBOX = 740;
{
    $s = new SqlScanStore($dbA);
    $KEY = 'checkbox-identity-key';
    $hash = hash('sha256', 'C-1', true);
    $where = ['record' => 'C-1', 'event_id' => null, 'instance' => 1, 'host_form' => 'fa',
              'field' => 'symptoms', 'rule_source_id' => 'r-choices',
              'reason_code' => 'hidden-choice'];
    // The real identity builder, not a fabricated pair of hashes: the claim is
    // about what Hmac::findingIdentity() does with a locus, and two hashes this
    // file made up would prove only that two different strings differ.
    $idA = Hmac::findingIdentity($CHECKBOX, array_merge($where, ['locus' => '3']), $KEY);
    $idB = Hmac::findingIdentity($CHECKBOX, array_merge($where, ['locus' => '7']), $KEY);
    check('store: two ticked hidden choices at one field are two identities', $idA !== $idB);

    $r = $s->startRun($CHECKBOX, ['created_by' => 'alice']);
    $rid = (int) $r['run']['run_id'];
    $gen = (int) $r['run']['generation_id'];
    $seq = uv_run_seq($dbA, $rid);
    $s->writeManifest($rid, [['id_bin' => 'C-1', 'hash' => $hash, 'dag' => null]]);
    $ep = (int) $s->run($CHECKBOX, $rid)['lease_epoch'];
    $c = $s->claim($rid, 'w', $ep, 1);
    $one = function ($identity) use ($CHECKBOX, $gen, $seq, $hash, $where) {
        return ['project_id' => $CHECKBOX, 'generation_id' => $gen, 'identity' => $identity,
                'valid_from_seq' => $seq, 'record_hash' => $hash, 'record_id_bin' => 'C-1',
                'event_id' => null, 'instance' => 1, 'host_form' => $where['host_form'],
                'field' => $where['field'], 'rule_source_id' => $where['rule_source_id'],
                'rule_revision' => str_repeat('c', 64), 'check_type' => 'choices',
                'reason_code' => $where['reason_code']];
    };
    $both = $s->commitBatch($rid, 'w', $ep, 0, ['bytes' => 0,
        'records' => [['ordinal' => $c[0]['ordinal'], 'record_hash' => $hash,
                       'state' => ScanStore::REC_DONE]],
        'findings' => [$one($idA), $one($idB)]]);
    check('store: and both store, rather than the batch being refused entire', $both === true);
    if ($both !== true) fwrite(STDERR, '  the checkbox batch said: ' . (string) $both . "\n");
    $at = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = ? AND generation_id = ? AND record_id_bin = ? AND field = ?
             AND reason_code = ? AND active_slot = 1',
        [$CHECKBOX, $gen, 'C-1', 'symptoms', 'hidden-choice']);
    check('store: leaving both active at the same location', (int) $at[0][0] === 2);
}

// THE SAME IDENTITY TWICE IN ONE BATCH.
//
// Refused ENTIRE, which is the half a store can get wrong quietly. Keeping the
// first and dropping the second would look like success to the caller and would
// hide the defect that costs the real store a rolled-back batch. Everything the
// batch carried has to be absent afterwards, not just the repeat.
$REPEATED = 750;
{
    $s = new SqlScanStore($dbA);
    $r = $s->startRun($REPEATED, ['created_by' => 'alice']);
    $rid = (int) $r['run']['run_id'];
    $gen = (int) $r['run']['generation_id'];
    $seq = uv_run_seq($dbA, $rid);
    $recs = [];
    for ($i = 1; $i <= 2; $i++) {
        $recs[] = ['id_bin' => 'D-' . $i, 'hash' => hash('sha256', 'D-' . $i, true), 'dag' => null];
    }
    $s->writeManifest($rid, $recs);
    $ep = (int) $s->run($REPEATED, $rid)['lease_epoch'];
    $c = $s->claim($rid, 'w', $ep, 2);
    $mk = function ($identity, $rec) use ($REPEATED, $gen, $seq) {
        return ['project_id' => $REPEATED, 'generation_id' => $gen, 'identity' => $identity,
                'valid_from_seq' => $seq, 'record_hash' => $rec['hash'],
                'record_id_bin' => $rec['id_bin'], 'instance' => 1, 'host_form' => 'fa',
                'field' => 'x', 'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64),
                'check_type' => 'required', 'reason_code' => 'required-blank'];
    };
    $same = hash('sha256', 'emitted-twice-by-mistake', true);
    $refused = $s->commitBatch($rid, 'w', $ep, 0, ['bytes' => 0,
        'records' => [['ordinal' => $c[0]['ordinal'], 'record_hash' => $c[0]['hash'],
                       'state' => ScanStore::REC_DONE],
                      ['ordinal' => $c[1]['ordinal'], 'record_hash' => $c[1]['hash'],
                       'state' => ScanStore::REC_DONE]],
        // The third finding is perfectly good, and that is why it is here: the
        // claim is that NOTHING from the batch is stored, not that the repeat
        // was dropped.
        'findings' => [$mk($same, $recs[0]), $mk($same, $recs[0]),
                       $mk(hash('sha256', 'a-different-finding', true), $recs[1])]]);
    check('store: a batch carrying one identity twice is refused', $refused !== true);
    check('store: and says the database refused it rather than a phantom cancellation',
        is_string($refused) && strpos($refused, 'database refused') !== false);
    $none = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = ?', [$REPEATED]);
    check('store: with nothing from it stored, the good finding included',
        (int) $none[0][0] === 0);
    $states = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_record')
        . ' WHERE run_id = ? AND state >= ?', [$rid, ScanStore::REC_DONE]);
    check('store: and neither record marked examined, so the work is re-claimable',
        (int) $states[0][0] === 0);
}

// THE SEQUENCE IS PER PROJECT, AND IT ONLY GOES UP.
//
// Two projects starting runs alternately on one installation. A single
// installation-wide counter hands out 1,3,5 and 2,4,6 here and still looks
// monotonic from inside either project - which is why the assertion is the
// exact sequence rather than "it increased".
$LEFT = 760; $RIGHT = 761;
{
    $s = new SqlScanStore($dbA);
    $done = ScanOutcome::derive(['fenced' => true, 'manifestDone' => true]);
    $gotL = []; $gotR = [];
    for ($i = 0; $i < 3; $i++) {
        $l = $s->startRun($LEFT, ['created_by' => 'alice']);
        $rgt = $s->startRun($RIGHT, ['created_by' => 'bob']);
        $gotL[] = (int) $l['run']['generation_id'];
        $gotR[] = (int) $rgt['run']['generation_id'];
        $s->finish((int) $l['run']['run_id'], $done);
        $s->finish((int) $rgt['run']['run_id'], $done);
    }
    check('store: each project counts its own generations from one, without gaps',
        $gotL === [1, 2, 3] && $gotR === [1, 2, 3]);
    check('store: so interleaved starts take nothing from each other', $gotL === $gotR);
    // WHAT WAS HANDED OUT IS WHAT WAS WRITTEN. A store that allocated correctly
    // and then stored something else would satisfy every check above, and the
    // rows are what every later query filters on.
    $onRows = $dbA->select('SELECT generation_id FROM ' . Schema::table('scan_run')
        . ' WHERE project_id = ? ORDER BY run_id', [$LEFT]);
    $flat = [];
    foreach ($onRows as $row) $flat[] = (int) $row[0];
    check('store: and the runs carry the numbers they were handed', $flat === $gotL);
}

// A ROW THAT BELONGS TO NO PROJECT IS REFUSED RATHER THAN WRITTEN.
//
// project_id carries DEFAULT 0 in the schema and permanently - the default is
// what let ADD COLUMN succeed against a populated table - so the column cannot
// fail loud and SqlScanStore::mustProject() does it instead. A row written with
// 0 belongs to nothing: invisible to its own report, immune to its own project's
// retention, and indistinguishable from the version-1 rows the migration
// deletes. Silence is exactly how the constant generation shipped.
$UNOWNED = 770;
{
    $s = new SqlScanStore($dbA);
    $hash = hash('sha256', 'U-1', true);
    $r = $s->startRun($UNOWNED, ['created_by' => 'alice']);
    $rid = (int) $r['run']['run_id'];
    $gen = (int) $r['run']['generation_id'];
    $seq = uv_run_seq($dbA, $rid);
    $s->writeManifest($rid, [['id_bin' => 'U-1', 'hash' => $hash, 'dag' => null]]);
    $ep = (int) $s->run($UNOWNED, $rid)['lease_epoch'];
    $c = $s->claim($rid, 'w', $ep, 1);
    // A DIFFERENT IDENTITY PER ATTEMPT, and that is not tidiness. Sharing one
    // would let the second attempt be refused by the unique key over the row the
    // first attempt should never have written - so a store that stopped
    // refusing would still fail only one of these two checks, and the other
    // would pass for a reason that has nothing to do with what it says.
    $with = function ($project, $which) use ($gen, $seq, $hash, $c) {
        $f = ['generation_id' => $gen, 'identity' => hash('sha256', 'unowned-' . $which, true),
              'valid_from_seq' => $seq, 'record_hash' => $hash, 'record_id_bin' => 'U-1',
              'instance' => 1, 'host_form' => 'fa', 'field' => 'x', 'rule_source_id' => 'r1',
              'rule_revision' => str_repeat('c', 64), 'check_type' => 'required',
              'reason_code' => 'required-blank'];
        if ($project !== null) $f['project_id'] = $project;
        return ['bytes' => 0,
                'records' => [['ordinal' => $c[0]['ordinal'], 'record_hash' => $hash,
                               'state' => ScanStore::REC_DONE]],
                'findings' => [$f]];
    };
    $absent = $s->commitBatch($rid, 'w', $ep, 0, $with(null, 'absent'));
    check('store: a finding that names no project is refused', $absent !== true);
    $zero = $s->commitBatch($rid, 'w', $ep, 0, $with(0, 'zero'));
    check('store: and a project id of zero is refused too, which the column cannot do',
        $zero !== true);
    // ASKED OF THE WHOLE TABLE, on purpose. "Nothing belongs to no project" is
    // an installation-wide statement, and scoping it to this run would let a row
    // written under some other run's transaction pass unnoticed.
    $orphans = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = 0');
    check('store: leaving nothing anywhere in the table belonging to no project',
        (int) $orphans[0][0] === 0);
    $st = $dbA->select('SELECT state FROM ' . Schema::table('scan_record')
        . ' WHERE run_id = ? AND ordinal = ?', [$rid, $c[0]['ordinal']]);
    check('store: and the record still pending, so a corrected worker can redo it',
        (int) $st[0][0] === ScanStore::REC_PENDING);
}

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
