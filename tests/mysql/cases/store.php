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
use INSPIRE\UniversalValidator\Scan\ScanPromotion;

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
                           'state' => ScanStore::REC_DONE, 'version' => 'v1',
                           'claim' => $c['claim']];
    $batch['findings'][] = [
        'ordinal' => $c['ordinal'],
        'project_id' => $PID,
        'generation_id' => $gen, 'identity' => hash('sha256', 'f' . $c['ordinal'], true),
        'valid_from_seq' => $runSeq,
        'record_hash' => $c['hash'], 'record_id_bin' => $c['id_bin'],
        'event_id' => null, 'instance' => 1, 'host_form' => 'fa', 'field' => 'x',
        'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64), 'rule_ord' => 1,
        'check_type' => 'required', 'reason_code' => 'required-blank',
    ];
}
check('store: a fenced batch commits', $storeA->commitBatch($runId, 'workerA', $epoch, $batch) === true);
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
                        'state' => ScanStore::REC_DONE, 'claim' => $claim[0]['claim']]],
         'findings' => [[
            'ordinal' => 4,
            'project_id' => $PID,
            'generation_id' => $gen, 'identity' => hash('sha256', 'f-lost', true),
            'valid_from_seq' => $runSeq,
            'record_hash' => hash('sha256', 'REC-4', true), 'record_id_bin' => 'REC-4',
            'instance' => 1, 'host_form' => 'fa', 'field' => 'x', 'rule_source_id' => 'r1',
            'rule_revision' => str_repeat('c', 64), 'check_type' => 'required',
            'reason_code' => 'required-blank']]];
$overtaken = $storeA->commitBatch($runId, 'workerA', $epoch, $lost);
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

// RETENTION: a value expires without the finding disappearing. Through
// ScanRetention, which is the only value sweep now: the store's own unpaged
// copy of it had no production caller and was deleted (wave 8).
$A->query('UPDATE ' . Schema::table('finding') . " SET value_bin = 'secret', z_score = '-3.40',
    value_expires_at = '2000-01-01 00:00:00' WHERE record_id_bin = 'REC-1'");
check('store: an expired value is cleared',
    (new \INSPIRE\UniversalValidator\Scan\ScanRetention($dbA))->expireValues(gmdate('Y-m-d H:i:s')) >= 1);
$v = $ca->query('SELECT value_bin, z_score FROM ' . Schema::table('finding')
    . " WHERE record_id_bin = 'REC-1'", []);
check('store: the value is gone, and a z-score with it', $v[0][0] === null && $v[0][1] === null);
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
    $finding = function ($gen, $seq, $ordinal) use ($SECOND, $rec, $identity) {
        return ['ordinal' => $ordinal,
                'project_id' => $SECOND, 'generation_id' => $gen, 'identity' => $identity,
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
        $s->commitBatch($rid1, 'w', $ep1, ['bytes' => 0,
            'records' => [['ordinal' => $c1[0]['ordinal'], 'record_hash' => $c1[0]['hash'],
                           'state' => ScanStore::REC_DONE, 'claim' => $c1[0]['claim']]],
            'findings' => [$finding($gen1, uv_run_seq($dbA, $rid1), $c1[0]['ordinal'])]]) === true);
    $s->finish($rid1, ScanOutcome::derive(['fenced' => true, 'manifestDone' => true]));

    $two = $s->startRun($SECOND, ['created_by' => 'bob']);
    check('store: a second scan of the same project may start', $two['ok'] === true);
    $rid2 = (int) $two['run']['run_id'];
    $gen2 = (int) $two['run']['generation_id'];
    check('store: and is handed a generation the first run did not use', $gen2 > $gen1);
    $s->writeManifest($rid2, [$rec]);
    $ep2 = (int) $s->run($SECOND, $rid2)['lease_epoch'];
    $c2 = $s->claim($rid2, 'w', $ep2, 1);
    $again = $s->commitBatch($rid2, 'w', $ep2, ['bytes' => 0,
        'records' => [['ordinal' => $c2[0]['ordinal'], 'record_hash' => $c2[0]['hash'],
                       'state' => ScanStore::REC_DONE, 'claim' => $c2[0]['claim']]],
        'findings' => [$finding($gen2, uv_run_seq($dbA, $rid2), $c2[0]['ordinal'])]]);
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
    $batch = function ($ordinal, $claim) use ($REEXAMINED, $gen, $seq, $hash, $identity) {
        return ['bytes' => 0,
                'records' => [['ordinal' => $ordinal, 'record_hash' => $hash,
                               'state' => ScanStore::REC_DONE, 'claim' => $claim]],
                'findings' => [['ordinal' => $ordinal,
                                'project_id' => $REEXAMINED, 'generation_id' => $gen,
                                'identity' => $identity, 'valid_from_seq' => $seq,
                                'record_hash' => $hash, 'record_id_bin' => 'X-1',
                                'instance' => 1, 'host_form' => 'fa', 'field' => 'x',
                                'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64),
                                'check_type' => 'required', 'reason_code' => 'required-blank']]];
    };
    $first = $s->claim($rid, 'w', $ep, 1);
    check('store: the first examination of the record commits',
        $s->commitBatch($rid, 'w', $ep, $batch($first[0]['ordinal'], $first[0]['claim'])) === true);

    // Someone saves the record. Catch-up is the one sanctioned exception to the
    // frozen manifest, so the requeue only exists inside that phase.
    check('store: the run may enter catch-up', $s->advancePhase($rid, $ep, ScanPhase::CATCH_UP));
    check('store: where a finished record may be sent back to pending',
        $s->requeue($rid, $ep, [$hash]) === 1);
    $back = $s->claimPending($rid, 'w', $ep, 1);
    check('store: and the straggler sweep offers it again', is_array($back) && count($back) === 1);

    $second = $s->commitBatch($rid, 'w', $ep, $batch($back[0]['ordinal'], $back[0]['claim']));
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
    $one = function ($identity) use ($CHECKBOX, $gen, $seq, $hash, $where, $c) {
        return ['ordinal' => $c[0]['ordinal'],
                'project_id' => $CHECKBOX, 'generation_id' => $gen, 'identity' => $identity,
                'valid_from_seq' => $seq, 'record_hash' => $hash, 'record_id_bin' => 'C-1',
                'event_id' => null, 'instance' => 1, 'host_form' => $where['host_form'],
                'field' => $where['field'], 'rule_source_id' => $where['rule_source_id'],
                'rule_revision' => str_repeat('c', 64), 'check_type' => 'choices',
                'reason_code' => $where['reason_code']];
    };
    $both = $s->commitBatch($rid, 'w', $ep, ['bytes' => 0,
        'records' => [['ordinal' => $c[0]['ordinal'], 'record_hash' => $hash,
                       'state' => ScanStore::REC_DONE, 'claim' => $c[0]['claim']]],
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
    $mk = function ($identity, $rec, $ordinal) use ($REPEATED, $gen, $seq) {
        return ['ordinal' => $ordinal,
                'project_id' => $REPEATED, 'generation_id' => $gen, 'identity' => $identity,
                'valid_from_seq' => $seq, 'record_hash' => $rec['hash'],
                'record_id_bin' => $rec['id_bin'], 'instance' => 1, 'host_form' => 'fa',
                'field' => 'x', 'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64),
                'check_type' => 'required', 'reason_code' => 'required-blank'];
    };
    $same = hash('sha256', 'emitted-twice-by-mistake', true);
    $refused = $s->commitBatch($rid, 'w', $ep, ['bytes' => 0,
        'records' => [['ordinal' => $c[0]['ordinal'], 'record_hash' => $c[0]['hash'],
                       'state' => ScanStore::REC_DONE, 'claim' => $c[0]['claim']],
                      ['ordinal' => $c[1]['ordinal'], 'record_hash' => $c[1]['hash'],
                       'state' => ScanStore::REC_DONE, 'claim' => $c[1]['claim']]],
        // The third finding is perfectly good, and that is why it is here: the
        // claim is that NOTHING from the batch is stored, not that the repeat
        // was dropped.
        'findings' => [$mk($same, $recs[0], $c[0]['ordinal']),
                       $mk($same, $recs[0], $c[0]['ordinal']),
                       $mk(hash('sha256', 'a-different-finding', true), $recs[1],
                           $c[1]['ordinal'])]]);
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
        $f['ordinal'] = $c[0]['ordinal'];
        return ['bytes' => 0,
                'records' => [['ordinal' => $c[0]['ordinal'], 'record_hash' => $hash,
                               'state' => ScanStore::REC_DONE, 'claim' => $c[0]['claim']]],
                'findings' => [$f]];
    };
    $absent = $s->commitBatch($rid, 'w', $ep, $with(null, 'absent'));
    check('store: a finding that names no project is refused', $absent !== true);
    $zero = $s->commitBatch($rid, 'w', $ep, $with(0, 'zero'));
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
// WHAT A BATCH OF FINDINGS COSTS (M9).
//
// commitBatch holds the run row FOR UPDATE while it writes, and it used to
// write one INSERT per finding: measured here, a record with 4,000 findings held
// that lock for 15.9 seconds. The number that matters is the statement count,
// and it is read off the statements the store actually sent.
$COST = 780;
{
    $rec = new UvRecordingDb($dbA);
    $s = new SqlScanStore($rec);
    $r = $s->startRun($COST, ['created_by' => 'alice']);
    $rid = (int) $r['run']['run_id'];
    $gen = (int) $r['run']['generation_id'];
    $seq = uv_run_seq($dbA, $rid);
    $recs = [];
    for ($i = 1; $i <= 2; $i++) {
        $recs[] = ['id_bin' => 'M-' . $i, 'hash' => hash('sha256', 'M-' . $i, true), 'dag' => null];
    }
    $s->writeManifest($rid, $recs);
    $ep = (int) $s->run($COST, $rid)['lease_epoch'];
    $c = $s->claim($rid, 'w', $ep, 2);
    $N = SqlScanStore::FINDING_CHUNK + 1;
    $fs = [];
    for ($i = 0; $i < $N; $i++) {
        $which = $i % 2;
        $f = ['ordinal' => $c[$which]['ordinal'],
              'project_id' => $COST, 'generation_id' => $gen,
              'identity' => hash('sha256', 'cost-' . $i, true),
              'valid_from_seq' => $seq, 'record_hash' => $recs[$which]['hash'],
              'record_id_bin' => $recs[$which]['id_bin'], 'instance' => 1, 'host_form' => 'fa',
              'field' => 'x' . $i, 'rule_source_id' => 'r1',
              'rule_revision' => str_repeat('c', 64),
              'check_type' => 'required', 'reason_code' => 'required-blank'];
        // ONE STAGED ROW among the ordinary ones. active_slot and stage_epoch
        // are per-row values in a multi-row statement, and a writer that
        // hoisted either into the statement would publish this row early.
        if ($i === 7) { $f['stage_epoch'] = 3; $f['group_hmac'] = hash('sha256', 'g', true); }
        $fs[] = $f;
    }
    $rec->log = [];
    $ok = $s->commitBatch($rid, 'w', $ep, ['bytes' => 0, 'findings' => $fs,
        'records' => [['ordinal' => $c[0]['ordinal'], 'record_hash' => $c[0]['hash'],
                       'state' => ScanStore::REC_DONE, 'claim' => $c[0]['claim']],
                      ['ordinal' => $c[1]['ordinal'], 'record_hash' => $c[1]['hash'],
                       'state' => ScanStore::REC_DONE, 'claim' => $c[1]['claim']]]]);
    check('cost: a batch one finding larger than a chunk commits', $ok === true);
    $ins = $rec->matching('exec', ['INSERT INTO ' . Schema::table('finding')]);
    check('cost: written in one statement per chunk, not one per finding',
        count($ins) === (int) ceil($N / SqlScanStore::FINDING_CHUNK) && count($ins) < $N / 100);
    $soft = false; $most = 0; $counted = true;
    foreach ($ins as $row) {
        if (stripos($row[1], 'DUPLICATE') !== false || stripos($row[1], 'IGNORE') !== false) $soft = true;
        $most = max($most, count($row[2]));
        if ($row[3] !== true) $counted = false;
    }
    check('cost: as plain INSERTs, so a collision is still refused rather than absorbed',
        $soft === false);
    check('cost: inside the placeholder limit', $most > 0 && $most <= 65535);
    check('cost: and counted, because a short write here reads as a cleaner project',
        $counted === true);
    $stored = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = ?', [$COST]);
    check('cost: every finding is there', (int) $stored[0][0] === $N
        && (int) $s->run($COST, $rid)['detail_rows'] === $N);
    $staged = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = ? AND active_slot IS NULL AND stage_epoch = 3', [$COST]);
    $active = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = ? AND active_slot = 1 AND stage_epoch IS NULL', [$COST]);
    check('cost: the one staged row stayed staged and its neighbours went active',
        (int) $staged[0][0] === 1 && (int) $active[0][0] === $N - 1);
    // THE LOCK COMES FIRST AND THE INSERTS LAST. Moving the FOR UPDATE after
    // the inserts would shorten the lock and reopen the race the class docblock
    // describes; this is the order, read from the wire.
    $pos = ['lock' => null, 'close' => null, 'insert' => null];
    foreach ($rec->log as $i => $row) {
        if ($pos['lock'] === null && strpos($row[1], 'FOR UPDATE') !== false) $pos['lock'] = $i;
        if ($pos['close'] === null && strpos($row[1], 'SET active_slot = NULL') !== false) $pos['close'] = $i;
        if ($pos['insert'] === null && strpos($row[1], 'INSERT INTO ' . Schema::table('finding')) !== false) $pos['insert'] = $i;
    }
    check('cost: the run is locked, then old evidence closed, then new evidence written',
        $pos['lock'] !== null && $pos['close'] !== null && $pos['insert'] !== null
        && $pos['lock'] < $pos['close'] && $pos['close'] < $pos['insert']);
}

// AND THE REFUSAL SURVIVES THE CHUNKING. Two statements now, so by the time the
// second collides the first has succeeded - "refused entire" is held by the
// transaction, and this is where that is proved against a real one.
$WIDE = 781;
{
    $s = new SqlScanStore($dbA);
    $r = $s->startRun($WIDE, ['created_by' => 'alice']);
    $rid = (int) $r['run']['run_id'];
    $gen = (int) $r['run']['generation_id'];
    $seq = uv_run_seq($dbA, $rid);
    $one = ['id_bin' => 'W-1', 'hash' => hash('sha256', 'W-1', true), 'dag' => null];
    $s->writeManifest($rid, [$one]);
    $ep = (int) $s->run($WIDE, $rid)['lease_epoch'];
    $c = $s->claim($rid, 'w', $ep, 1);
    $N = SqlScanStore::FINDING_CHUNK + 1;
    $fs = [];
    for ($i = 0; $i < $N; $i++) {
        $fs[] = ['ordinal' => $c[0]['ordinal'], 'project_id' => $WIDE, 'generation_id' => $gen,
                 // the LAST repeats the FIRST, a whole chunk later
                 'identity' => hash('sha256', 'wide-' . ($i === $N - 1 ? 0 : $i), true),
                 'valid_from_seq' => $seq, 'record_hash' => $one['hash'],
                 'record_id_bin' => 'W-1', 'instance' => 1, 'host_form' => 'fa',
                 'field' => 'x' . $i, 'rule_source_id' => 'r1',
                 'rule_revision' => str_repeat('c', 64),
                 'check_type' => 'required', 'reason_code' => 'required-blank'];
    }
    $refused = $s->commitBatch($rid, 'w', $ep, ['bytes' => 0, 'findings' => $fs,
        'records' => [['ordinal' => $c[0]['ordinal'], 'record_hash' => $c[0]['hash'],
                       'state' => ScanStore::REC_DONE, 'claim' => $c[0]['claim']]]]);
    check('cost: a repeat in the SECOND chunk refuses the batch',
        is_string($refused) && strpos($refused, 'database refused') !== false);
    $left = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = ?', [$WIDE]);
    $done = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_record')
        . ' WHERE run_id = ? AND state >= ?', [$rid, ScanStore::REC_DONE]);
    check('cost: and takes the first chunk back out with it',
        (int) $left[0][0] === 0 && (int) $done[0][0] === 0);
}

// "IS THE MANIFEST COMPLETE", AND WHAT IT COSTS TO ASK (P11).
//
// The state it is asked in most is the one where the answer is yes - that is
// how a worker learns it has finished - and there a COUNT(*) has nothing to
// count while the wrong index still walks every row to find that out. The plan
// is asserted on the statement the store sent, with every row terminal, which
// is exactly where PRIMARY and ix_run_state part company.
$ASKED = 782;
{
    $rec = new UvRecordingDb($dbA);
    $s = new SqlScanStore($rec);
    $r = $s->startRun($ASKED, ['created_by' => 'alice']);
    $rid = (int) $r['run']['run_id'];
    $recs = [];
    for ($i = 1; $i <= 1200; $i++) {
        $recs[] = ['id_bin' => 'Q-' . $i, 'hash' => hash('sha256', 'Q-' . $i, true), 'dag' => null];
    }
    $s->writeManifest($rid, $recs);
    check('asked: a manifest nobody has started is not complete', $s->manifestComplete($rid) === false);
    $A->query('UPDATE ' . Schema::table('scan_record') . ' SET state = ' . (int) ScanStore::REC_DONE
        . ' WHERE run_id = ' . $rid);
    $rec->log = [];
    check('asked: with every row terminal it is', $s->manifestComplete($rid) === true);
    $q = $rec->matching('select', [Schema::table('scan_record'), 'state <']);
    check('asked: and that cost one statement', count($q) === 1);
    if ($q) {
        $keys = uv_explain_keys($A, $q[0][1], $q[0][2]);
        check('asked: answered from the state index rather than by walking the manifest',
            in_array('ix_run_state', $keys, true));
        if (!in_array('ix_run_state', $keys, true)) {
            fwrite(STDERR, '  the optimiser chose: ' . ($keys ? implode(', ', $keys) : '(no index)') . "\n");
        }
    }
    $A->query('UPDATE ' . Schema::table('scan_record') . ' SET state = ' . (int) ScanStore::REC_PENDING
        . ' WHERE run_id = ' . $rid . ' AND ordinal = 600');
    check('asked: one row put back makes it incomplete again', $s->manifestComplete($rid) === false);
    // THE DIVERGENCE THAT WAS PINNED HERE IS CHOSEN NOW (W6-N1). The SQL store
    // answered true for a run id that does not exist - nothing unfinished -
    // where ArrayScanStore answered false. Both fail closed, and the run is
    // looked up by its primary key inside the same statement.
    check('asked: a run that does not exist is not complete',
        $s->manifestComplete(999999999) === false);
    $rec->log = [];
    $s->manifestComplete($rid);
    $q = $rec->matching('select', [Schema::table('scan_record'), 'state <']);
    $keys = $q ? uv_explain_keys($A, $q[0][1], $q[0][2]) : [];
    check('asked: and the run lookup costs a primary-key probe beside the state index',
        count($q) === 1 && in_array('ix_run_state', $keys, true) && in_array('PRIMARY', $keys, true));
}

// THE STRAGGLER SWEEP READS ITS WINDOW, NOT THE FINISHED ROWS IN FRONT OF IT
// (review R3S-1).
//
// claimPending() looks at free rows a window at a time, and every window used
// to be the same statement run from the start of the run: the server served
// its ORDER BY from PRIMARY and tested every row on the way, so in catch-up -
// where nearly every row is finished - each window walked the finished prefix
// with the run row locked FOR UPDATE. Measured at 98,499 rows a window on
// 100,000 records. Here 2,950 finished rows stand in front of 30 rows a dead
// worker left at the attempt limit and 20 free ones; windows of ten retire the
// dead rows three at a time and the fourth hands out ten free rows.
//
// What is asserted is what the server did with the statements the store sent,
// at the moment it sent them: the plan, EXPLAINed inside the store's
// transaction just before the window runs; the rows each window actually read
// (Handler_read_next, taken on the store's own connection around each window -
// a FLUSH STATUS there would commit the transaction); and where each window
// started. The rows read are the stronger of the two. Without its own hint
// MariaDB re-planned this ORDER BY ... LIMIT at execution - at 100,000 rows its
// EXPLAIN named PRIMARY with a sort while the window read 499 rows of
// ix_run_state - and a later window may properly take PRIMARY from where it
// starts, because the range behind that point is short.
//
// Not 788: the supersede case below plants its neighbour project there.
$SWEPT = 795;
{
    $probe = new class($dbA, $A) extends UvRecordingDb {
        /** @var int[] rows read by each window statement, in the order sent */
        public $reads = [];
        /** @var string[][] per window, the key of each EXPLAIN row on the record table */
        public $plans = [];
        private $conn;
        private $table;
        public function __construct($inner, $conn) {
            parent::__construct($inner);
            $this->conn = $conn;
            $this->table = Schema::table('scan_record');
        }
        private function readNext() {
            $r = $this->conn->query("SHOW SESSION STATUS LIKE 'Handler_read_next'");
            $row = $r->fetch_row();
            $r->free();
            return (int) $row[1];
        }
        public function select($sql, array $params = []) {
            if (strpos($sql, 'UNION ALL') === false) return parent::select($sql, $params);
            $keys = [];
            $e = $this->conn->query('EXPLAIN ' . uv_inline_params($this->conn, $sql, $params));
            while ($row = $e->fetch_assoc()) {
                if ((string) $row['table'] === $this->table) $keys[] = (string) $row['key'];
            }
            $e->free();
            $this->plans[] = $keys;
            $before = $this->readNext();
            $out = parent::select($sql, $params);
            $this->reads[] = $this->readNext() - $before;
            return $out;
        }
    };
    // A still clock: these count windows and rows read, and a loaded server
    // must not end a call on SWEEP_SECONDS first.
    $s = new SqlScanStore($probe, function () { return 0.0; });
    $rid = (int) $s->startRun($SWEPT, ['created_by' => 'alice'])['run']['run_id'];
    $recs = [];
    for ($i = 1; $i <= 3000; $i++) {
        $recs[] = ['id_bin' => 'W-' . $i, 'hash' => hash('sha256', 'W-' . $i, true), 'dag' => null];
    }
    $s->writeManifest($rid, $recs);
    $t = Schema::table('scan_record');
    $A->query('UPDATE ' . $t . ' SET state = ' . (int) ScanStore::REC_DONE
        . ' WHERE run_id = ' . $rid . ' AND ordinal <= 2950');
    $A->query('UPDATE ' . $t . " SET claim_owner = 'dead', claim_seq = 7,"
        . ' updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR)'
        . ' WHERE run_id = ' . $rid . ' AND ordinal BETWEEN 2951 AND 2980');
    $A->query('UPDATE ' . Schema::table('scan_run') . " SET phase = '" . ScanPhase::CATCH_UP
        . "' WHERE run_id = " . $rid);
    // Statistics as a table that has been written for a while would have them.
    $A->query('ANALYZE TABLE ' . $t)->free();
    $ep = (int) $s->run($SWEPT, $rid)['lease_epoch'];
    $probe->log = [];
    $probe->reads = [];
    $ret = -1;
    $got = $s->claimPending($rid, 'w2', $ep, 10, 900, 1, ScanStore::REC_UNREADABLE, $ret);
    $ords = [];
    foreach ((array) $got as $g) $ords[] = (int) $g['ordinal'];
    check('sweep plan: the dead rows are retired and the first free rows handed out',
        $ret === 30 && $ords === range(2981, 2990));
    $windows = $probe->matching('select', ['UNION ALL', $t]);
    $starts = [];
    foreach ($windows as $w) $starts[] = (int) $w[2][3] . '/' . (int) $w[2][7];
    check('sweep plan: each window starts after the last row of the one before',
        $starts === ['0/0', '2960/2960', '2970/2970', '2980/2980']);
    $plans = [];
    foreach ($probe->plans as $p) $plans[] = implode('+', $p);
    echo '  sweep window starts: ' . implode(' ', $starts) . ' | rows read per window: '
        . implode(',', $probe->reads) . ' | plans: ' . implode(' ', $plans) . "\n";
    // Each window reads at most the free rows behind where it starts - fifty
    // here, ten of them when the server walks the range in order and stops,
    // all of them when it chooses to sort so few - and never the finished
    // rows in front. (At this size the old statement sorted the fifty too; the
    // walk of the finished rows is pinned by the 2,000-row case below, where
    // it read 1,009 for a window of ten.)
    check('sweep plan: and each reads the free rows behind it, not the 2,950 finished rows in front',
        count($probe->reads) === 4 && max($probe->reads) <= 50 + 4);
    // THE FIRST WINDOW, the one that starts at the beginning of the run, is
    // planned through the state index in both branches. MySQL 8.0 may name it
    // beside PRIMARY (an intersection that uses the primary key only to
    // filter), never PRIMARY alone - which is the walk of the finished prefix.
    $first = isset($probe->plans[0]) ? $probe->plans[0] : [];
    $viaState = count($first) === 2;
    foreach ($first as $k) {
        if (!in_array('ix_run_state', explode(',', $k), true)) $viaState = false;
    }
    check('sweep plan: the first window reads both free states through ix_run_state', $viaState);

    // AND STOPS AT THE WINDOW WHEN MANY ROWS ARE FREE. 2,000 free rows behind
    // 1,000 finished ones, a window of ten. The old statement walked PRIMARY
    // from the start of the run: 1,009 rows. A plain FORCE INDEX
    // (ix_run_state) reads the state index, and on MySQL then sorts every row
    // of the state to find the first ten: 2,000 here, and 100,000 a window
    // with 100,000 pending.
    $rid2 = (int) $s->startRun($SWEPT + 1, ['created_by' => 'alice'])['run']['run_id'];
    $recs = [];
    for ($i = 1; $i <= 3000; $i++) {
        $recs[] = ['id_bin' => 'X-' . $i, 'hash' => hash('sha256', 'X-' . $i, true), 'dag' => null];
    }
    $s->writeManifest($rid2, $recs);
    $A->query('UPDATE ' . $t . ' SET state = ' . (int) ScanStore::REC_DONE
        . ' WHERE run_id = ' . $rid2 . ' AND ordinal <= 1000');
    $A->query('UPDATE ' . Schema::table('scan_run') . " SET phase = '" . ScanPhase::CATCH_UP
        . "' WHERE run_id = " . $rid2);
    $A->query('ANALYZE TABLE ' . $t)->free();
    $ep2 = (int) $s->run($SWEPT + 1, $rid2)['lease_epoch'];
    $probe->reads = [];
    $probe->plans = [];
    $got2 = $s->claimPending($rid2, 'w2', $ep2, 10, 900, 1, ScanStore::REC_UNREADABLE, $ret);
    echo '  sweep with 2,000 free: rows read ' . implode(',', $probe->reads) . "\n";
    check('sweep plan: with 2,000 rows free, the window reads about ten of them, not all of them',
        is_array($got2) && count($got2) === 10 && (int) $got2[0]['ordinal'] === 1001
        && count($probe->reads) === 1 && $probe->reads[0] <= 2 * 10 + 4);
}

// THE DECISION IS ON THE ROW (H15), read back RAW.
//
// The contract below reads these through run(), and run() goes through a
// driver that hands every value back as a string or null. "NULL, not 0" is a
// statement about the column, so it is asked of the column: `clean IS NULL` is
// a question only the server can answer, and a store that bound 0 where it
// meant NULL would pass any check written against PHP's loose reading of it.
$DECIDED = 783;
{
    $s = new SqlScanStore($dbA);
    $raw = function ($rid) use ($A) {
        $q = $A->query('SELECT clean IS NULL, clean, gap_count, rule_problem_count, not_examined,
            terminal_reason, active_slot IS NULL FROM ' . Schema::table('scan_run')
            . ' WHERE run_id = ' . (int) $rid);
        return $q->fetch_row();
    };
    $rid = (int) $s->startRun($DECIDED, ['created_by' => 'alice'])['run']['run_id'];
    $row = $raw($rid);
    check('decided: a live run has no verdict yet', (int) $row[0] === 1);
    $o = ScanOutcome::derive(['fenced' => true, 'manifestDone' => true, 'blocked' => true, 'gaps' => 7]);
    $o['ruleProblems'] = 2;
    $o['notExamined'] = 5;
    check('decided: finishing takes', $s->finish($rid, $o) === true);
    $row = $raw($rid);
    check('decided: a partial run is a stored 0, not an absent value',
        (int) $row[0] === 0 && (int) $row[1] === 0);
    check('decided: with its three counts beside it',
        (int) $row[2] === 7 && (int) $row[3] === 2 && (int) $row[4] === 5);
    check('decided: the reason it ended, and the slot given back',
        $row[5] === $o['why'] && (int) $row[6] === 1);
    $clean = ScanOutcome::derive(['fenced' => true, 'manifestDone' => true,
                                  'violations' => 0, 'ruleProblems' => 0]);
    check('decided: a second finaliser with a better story is refused',
        $s->finish($rid, $clean) === false);
    $again = $raw($rid);
    check('decided: and rewrote nothing', $again === $row);

    $rid2 = (int) $s->startRun($DECIDED, ['created_by' => 'alice'])['run']['run_id'];
    $s->finish($rid2, $clean);
    $row = $raw($rid2);
    check('decided: a complete run that found nothing is a stored 1',
        (int) $row[0] === 0 && (int) $row[1] === 1);

    foreach (['cancelled', 'failed', 'expired'] as $how) {
        $rid3 = (int) $s->startRun($DECIDED, ['created_by' => 'alice'])['run']['run_id'];
        $s->finish($rid3, ScanOutcome::derive([$how => true, 'fenced' => true, 'manifestDone' => true]));
        $row = $raw($rid3);
        check("decided: a $how run stores NULL, which is not the same as not clean",
            (int) $row[0] === 1 && $row[1] === null);
    }
}

// A PRESENCE IS RECORDED ONCE (B4b), and what makes that true is a unique key
// on a real server - so it is asked of one, from two connections, with one of
// them inside an open transaction when the other speaks.
$MARKED = 784;
{
    $sa = new SqlScanStore($dbA);
    $sb = new SqlScanStore($dbB);
    $rid = (int) $sa->startRun($MARKED, ['created_by' => 'alice'])['run']['run_id'];
    $agg = function () use ($A, $rid) {
        $q = $A->query('SELECT axis1, axis2, cnt, blocks_coverage, samples FROM '
            . Schema::table('scan_aggregate') . ' WHERE run_id = ' . (int) $rid
            . " AND kind = 'rule-problem' ORDER BY axis1, axis2");
        $out = [];
        while ($row = $q->fetch_row()) $out[] = $row;
        return $out;
    };
    $sa->markAggregate($rid, 'rule-problem', '4', 'unlocatable', 0, 'first said');
    $sb->markAggregate($rid, 'rule-problem', '4', 'unlocatable', 0, 'said again');
    $sa->markAggregate($rid, 'rule-problem', '4', 'unlocatable');
    $rows = $agg();
    check('marked: one fact from two connections is one row with a count of one',
        count($rows) === 1 && (int) $rows[0][2] === 1);
    check('marked: keeping the explanation it was first given', $rows[0][4] === 'first said');
    // A row counted up by the ADDING verb - which is what every installation
    // that ran the previous build holds - comes back to a presence.
    $sa->addAggregate($rid, 'rule-problem', '9', 'legacy', 5);
    $sb->markAggregate($rid, 'rule-problem', '9', 'legacy');
    $rows = $agg();
    check('marked: a row an earlier build counted up is reset to one',
        count($rows) === 2 && (int) $rows[1][2] === 1);
    check('marked: and the statement was uncounted - nothing reads what it changed',
        (function () use ($dbA, $rid) {
            $rec = new UvRecordingDb($dbA);
            (new SqlScanStore($rec))->markAggregate($rid, 'rule-problem', '4', 'unlocatable');
            $w = $rec->matching('exec', [Schema::table('scan_aggregate')]);
            return count($w) === 1 && $w[0][3] === false;
        })());

    // IN A BATCH, BEHIND THE BATCH'S FENCES.
    $sa->writeManifest($rid, [['id_bin' => 'MK-1', 'hash' => hash('sha256', 'MK-1', true), 'dag' => null],
                              ['id_bin' => 'MK-2', 'hash' => hash('sha256', 'MK-2', true), 'dag' => null]]);
    $ep = (int) $sa->run($MARKED, $rid)['lease_epoch'];
    $c = $sa->claim($rid, 'w1', $ep, 1);
    $mark = ['kind' => 'rule-problem', 'axis1' => '12', 'axis2' => 'in-batch', 'samples' => 'met while examining'];
    $one = ['ordinal' => $c[0]['ordinal'], 'record_hash' => $c[0]['hash'],
            'state' => ScanStore::REC_DONE, 'claim' => $c[0]['claim']];
    $has12 = function () use ($agg) {
        foreach ($agg() as $r) if ($r[0] === '12') return true;
        return false;
    };
    check('marked: a batch at a stale epoch is refused',
        $sa->commitBatch($rid, 'w1', $ep - 1, ['bytes' => 0, 'findings' => [], 'records' => [$one],
            'marks' => [$mark]]) !== true);
    check('marked: and its mark was rolled back with it', $has12() === false);
    check('marked: a batch that holds its row commits',
        $sa->commitBatch($rid, 'w1', $ep, ['bytes' => 0, 'findings' => [], 'records' => [$one],
            'marks' => [$mark, $mark]]) === true);
    $rows = array_values(array_filter($agg(), function ($r) { return $r[0] === '12'; }));
    check('marked: and its mark is there, once',
        count($rows) === 1 && (int) $rows[0][2] === 1 && $rows[0][4] === 'met while examining');

    // A SENTENCE CUT INSIDE A CHARACTER. samples is utf8mb4 and the server is
    // strict, so a byte cut through the two-byte 'ô' is error 1366 - and the
    // mark is inside the batch, so the batch went with it, on every retry.
    $c2 = $sa->claim($rid, 'w1', $ep, 1);
    $two = ['ordinal' => $c2[0]['ordinal'], 'record_hash' => $c2[0]['hash'],
            'state' => ScanStore::REC_DONE, 'claim' => $c2[0]['claim']];
    $cut = ScanPromotion::marksFor([['rule' => 13, 'code' => 'branch-conflict',
        'why' => str_repeat('w', ScanPromotion::SAMPLE_BYTES - 2) . "C\xC3\xB4te d'Ivoire"]]);
    check('marked: a batch whose mark was cut at a character boundary commits on a strict server',
        $sa->commitBatch($rid, 'w1', $ep, ['bytes' => 0, 'findings' => [], 'records' => [$two],
            'marks' => $cut]) === true);
    $rows = array_values(array_filter($agg(), function ($r) { return $r[0] === '13'; }));
    check('marked: and its sample is the sentence up to the character it could not split',
        count($rows) === 1 && substr($rows[0][4], -1) === 'C'
        && strlen($rows[0][4]) === ScanPromotion::SAMPLE_BYTES - 1);
}

// A LENIENT SERVER DOES NOT GET TO INVENT A VALUE (review ST-2). Findings go to
// the server 500 to a statement, and under a non-strict sql_mode a MULTI-row
// INSERT answers a NULL in a NOT NULL column by storing the type's implicit
// default - '' for host_form - and counting the row as written. A single-row
// INSERT refused the same NULL with 1048, so batching quietly turned a refused
// batch into a finding with no instrument. Asked of the server itself, with the
// session made lenient for the one commit.
$LENIENT = 785;
{
    $s = new SqlScanStore($dbA);
    $rid = (int) $s->startRun($LENIENT, ['created_by' => 'alice'])['run']['run_id'];
    $gen = (int) $s->run($LENIENT, $rid)['generation_id'];
    $seq = uv_run_seq($dbA, $rid);
    $s->writeManifest($rid, [['id_bin' => 'LN-1', 'hash' => hash('sha256', 'LN-1', true), 'dag' => null]]);
    $ep = (int) $s->run($LENIENT, $rid)['lease_epoch'];
    $c = $s->claim($rid, 'w1', $ep, 1);
    $f = function ($i) use ($LENIENT, $gen, $seq, $c) {
        return ['ordinal' => $c[0]['ordinal'], 'project_id' => $LENIENT, 'generation_id' => $gen,
                'identity' => hash('sha256', 'lenient-' . $i, true), 'valid_from_seq' => $seq,
                'record_hash' => $c[0]['hash'], 'record_id_bin' => 'LN-1', 'host_form' => 'visit',
                'field' => 'f' . $i, 'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64),
                'check_type' => 'required', 'reason_code' => 'required-blank'];
    };
    $broken = $f(2);
    $broken['host_form'] = null;
    $mode = $A->query('SELECT @@SESSION.sql_mode')->fetch_row();
    $A->query("SET SESSION sql_mode = ''");
    try {
        $got = $s->commitBatch($rid, 'w1', $ep, ['bytes' => 0, 'findings' => [$f(1), $broken],
            'records' => [['ordinal' => $c[0]['ordinal'], 'record_hash' => $c[0]['hash'],
                           'state' => ScanStore::REC_DONE, 'claim' => $c[0]['claim']]]]);
    } finally {
        $A->query("SET SESSION sql_mode = '" . $A->real_escape_string((string) $mode[0]) . "'");
    }
    check('lenient: a finding with no instrument is refused under a non-strict sql_mode',
        is_string($got) && strpos($got, 'no host_form;') !== false);
    $kept = $A->query('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = ' . $LENIENT)->fetch_row();
    check('lenient: and nothing was stored, rather than a row with an empty instrument',
        (int) $kept[0] === 0);

    // THE LIST AND THE TABLE AGREE. Every NOT NULL column the server cannot
    // default must be one the store refuses a NULL for, or one it fills itself
    // when the key is absent. A column added to uv_finding without a line in
    // ScanStore::FINDING_REQUIRED fails here rather than on a lenient server.
    $q = $A->query("SELECT COLUMN_NAME FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = '" . Schema::table('finding') . "'
          AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'");
    $server = [];
    while ($row = $q->fetch_row()) $server[] = strtolower((string) $row[0]);
    sort($server);
    $byStore = ['valid_from_seq', 'rule_ord'];     // filled by findingRow() when absent
    foreach (ScanStore::FINDING_REQUIRED as $k) $byStore[] = ($k === 'identity') ? 'finding_identity' : $k;
    sort($byStore);
    check('lenient: every NOT NULL column without a default is refused when NULL or filled by the store',
        $server === $byStore);
    if ($server !== $byStore) {
        fwrite(STDERR, '  server: ' . implode(',', $server) . "\n  store:  " . implode(',', $byStore) . "\n");
    }
}

// ONE ROW SHAPE, ASKED OF BOTH STORES AT ONCE (B5-N1, M13).
//
// The contract below judges each store against FindingRowMapper::INPUT_KEYS
// separately. This puts the same batch through the in-memory store and this
// one and compares what they answer, value by value, so a column one of them
// defaults differently fails here by name.
$PARITY = 786;
{
    $build = function ($store, $pid) {
        $r = $store->startRun($pid, ['created_by' => 'alice']);
        $rid = (int) $r['run']['run_id'];
        $gen = (int) $r['run']['generation_id'];
        $store->writeManifest($rid, [['id_bin' => 'PA-1', 'hash' => hash('sha256', 'PA-1', true), 'dag' => 'north'],
                                     ['id_bin' => 'PA-2', 'hash' => hash('sha256', 'PA-2', true), 'dag' => null]]);
        $ep = (int) $store->run($pid, $rid)['lease_epoch'];
        $c = $store->claim($rid, 'w', $ep, 2);
        $base = function ($row, $i) use ($pid, $gen) {
            // No valid_from_seq anywhere: the run's own sequence is the default
            // both stores must agree on.
            return ['ordinal' => $row['ordinal'], 'project_id' => $pid, 'generation_id' => $gen,
                    'identity' => hash('sha256', 'parity-' . $i, true),
                    'record_hash' => $row['hash'], 'record_id_bin' => $row['id_bin'],
                    'host_form' => 'visit', 'field' => 'f' . $i, 'rule_source_id' => 'src-' . $i,
                    'rule_revision' => str_repeat('c', 64), 'check_type' => 'required',
                    'reason_code' => 'required-blank'];
        };
        $fs = [
            $base($c[0], 1) + ['event_id' => 12, 'instance' => 3, 'rule_ord' => 4, 'reason_bits' => 5,
                               'dag_key' => 'north', 'value_bin' => "b\xC3\xA9", 'value_len' => 3,
                               'value_truncated' => 1, 'value_expires_at' => '2031-02-03 04:05:06'],
            $base($c[0], 2),
            $base($c[1], 3) + ['stage_epoch' => 2, 'group_hmac' => hash('sha256', 'pg', true)],
            $base($c[1], 4) + ['value_bin' => '', 'value_len' => 0],
        ];
        $ok = $store->commitBatch($rid, 'w', $ep, ['bytes' => 0, 'findings' => $fs, 'records' => [
            ['ordinal' => $c[0]['ordinal'], 'record_hash' => $c[0]['hash'], 'state' => ScanStore::REC_DONE, 'claim' => $c[0]['claim']],
            ['ordinal' => $c[1]['ordinal'], 'record_hash' => $c[1]['hash'], 'state' => ScanStore::REC_DONE, 'claim' => $c[1]['claim']]]]);
        return ['run' => $rid, 'gen' => $gen, 'ok' => $ok,
                'rows' => $store->findings($pid, $gen, [], 0, 100)];
    };
    $flat = function (array $rows) {
        $out = [];
        foreach ($rows as $row) {
            $f = [];
            foreach ($row as $k => $v) {
                if ($k === 'finding_id') continue;      // an id each store numbers its own way
                $f[$k] = $v === null ? null : (string) $v;
            }
            $out[] = $f;
        }
        return $out;
    };
    $mem = $build(new \INSPIRE\UniversalValidator\Scan\ArrayScanStore(), $PARITY);
    $sql = $build(new SqlScanStore($dbA), $PARITY);
    check('parity: the same batch commits in both stores', $mem['ok'] === true && $sql['ok'] === true);
    check('parity: and both answer FindingRowMapper::INPUT_KEYS, in that order, on every row',
        count($sql['rows']) === 3 && count($mem['rows']) === 3
        && array_keys($sql['rows'][0]) === \INSPIRE\UniversalValidator\Scan\FindingRowMapper::INPUT_KEYS
        && array_keys($mem['rows'][2]) === array_keys($sql['rows'][2]));
    $a = $flat($mem['rows']);
    $b = $flat($sql['rows']);
    check('parity: with the same values, defaults included, row for row', $a === $b);
    if ($a !== $b) {
        foreach ($a as $i => $row) {
            foreach ($row as $k => $v) {
                $w = isset($b[$i]) && array_key_exists($k, $b[$i]) ? $b[$i][$k] : '(absent)';
                if ($v !== $w) fwrite(STDERR, "  row $i $k: memory=" . var_export($v, true)
                    . ' server=' . var_export($w, true) . "\n");
            }
        }
    }
    $from = $dbA->select('SELECT COUNT(*), MIN(valid_from_seq), MAX(valid_from_seq) FROM '
        . Schema::table('finding') . ' WHERE project_id = ? AND generation_id = ?', [$PARITY, $sql['gen']]);
    $seq = uv_run_seq($dbA, $sql['run']);
    check('parity: a finding that names no interval opens at its run\'s sequence, not at 0',
        (int) $from[0][0] === 4 && (int) $from[0][1] === $seq && (int) $from[0][2] === $seq && $seq > 0);
    $staged = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = ? AND active_slot IS NULL AND stage_epoch = 2', [$PARITY]);
    check('parity: the staged row is stored staged, which is why neither store returns it',
        (int) $staged[0][0] === 1);
    // THE READ IS THE KEY IT WAS BUILT FOR. Asserted on the statement sent.
    $rec = new UvRecordingDb($dbA);
    (new SqlScanStore($rec))->findings($PARITY, $sql['gen'], [], 0, 100);
    $q = $rec->matching('select', ['FROM ' . Schema::table('finding')]);
    $keys = $q ? uv_explain_keys($A, $q[0][1], $q[0][2]) : [];
    check('parity: a page of findings is read through ix_page_v2',
        count($q) === 1 && $keys === ['ix_page_v2']);
    if ($keys !== ['ix_page_v2']) fwrite(STDERR, '  findings plan: ' . implode(' ', uv_explain_rows($A, $q[0][1], $q[0][2])) . "\n");
}

// THE PREVIOUS GENERATION, CLOSED A PAGE AT A TIME WHEN A RUN COMPLETES (W4-D1).
//
// The contract asserts what is closed. This asserts what it costs and what the
// rows say afterwards: the page is the three-column prefix of the active-page
// key, the closed rows carry the closing run's sequence, the run row carries
// the finished cursor, and a request that cannot hold the run row closes
// nothing at all.
$SUPER = 787;
{
    $sa = new SqlScanStore($dbA);
    $nbS = uv_plant_neighbour($dbA, $SUPER);
    $done = ScanOutcome::derive(['fenced' => true, 'manifestDone' => true, 'violations' => 1]);
    $runWith = function ($n) use ($sa, $SUPER, $done) {
        $r = $sa->startRun($SUPER, ['created_by' => 'alice']);
        $rid = (int) $r['run']['run_id'];
        $gen = (int) $r['run']['generation_id'];
        $sa->writeManifest($rid, [['id_bin' => 'SP-' . $gen, 'hash' => hash('sha256', 'SP-' . $gen, true), 'dag' => null]]);
        $ep = (int) $sa->run($SUPER, $rid)['lease_epoch'];
        $c = $sa->claim($rid, 'w', $ep, 1);
        $fs = [];
        for ($i = 0; $i < $n; $i++) {
            $fs[] = ['ordinal' => $c[0]['ordinal'], 'project_id' => $SUPER, 'generation_id' => $gen,
                     'identity' => hash('sha256', 'sp-' . $gen . '-' . $i, true),
                     'record_hash' => $c[0]['hash'], 'record_id_bin' => $c[0]['id_bin'],
                     'host_form' => 'fa', 'field' => 'x' . $i, 'rule_source_id' => 'r1',
                     'rule_revision' => str_repeat('c', 64), 'check_type' => 'required',
                     'reason_code' => 'required-blank'];
        }
        $sa->commitBatch($rid, 'w', $ep, ['bytes' => 0, 'findings' => $fs, 'records' => [
            ['ordinal' => $c[0]['ordinal'], 'record_hash' => $c[0]['hash'], 'state' => ScanStore::REC_DONE,
             'claim' => $c[0]['claim']]]]);
        $sa->finish($rid, $done);
        return ['run' => $rid, 'gen' => $gen];
    };
    $old = $runWith(5);
    $new = $runWith(2);
    check('supersede: the neighbour shares the older run\'s generation, as two projects do',
        is_array($nbS) && (int) $nbS['generation_id'] === $old['gen']);

    // A REQUEST THAT CANNOT HOLD THE RUN ROW CLOSES NOTHING. Connection A holds
    // it; B's page waits, gives up, and reports the storage unavailable.
    $B->query('SET SESSION innodb_lock_wait_timeout = 1');
    $A->query('START TRANSACTION');
    $A->query('SELECT run_id FROM ' . Schema::table('scan_run') . ' WHERE run_id = ' . $new['run'] . ' FOR UPDATE');
    $blocked = null;
    try {
        (new SqlScanStore($dbB))->supersedePrevious($SUPER, $new['run'], 10);
        $blocked = 'returned';
    } catch (\INSPIRE\UniversalValidator\Scan\ScanStoreUnavailable $e) {
        $blocked = 'unavailable';
    }
    $A->query('ROLLBACK');
    $B->query('SET SESSION innodb_lock_wait_timeout = 50');
    $activeOld = function () use ($dbA, $SUPER, $old) {
        $r = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
            . ' WHERE project_id = ? AND generation_id = ? AND active_slot = 1', [$SUPER, $old['gen']]);
        return (int) $r[0][0];
    };
    check('supersede: a second request waits on the run row, and closes nothing when it cannot have it',
        $blocked === 'unavailable' && $activeOld() === 5);

    $rec = new UvRecordingDb($dbA);
    $sr = new SqlScanStore($rec);
    $pages = [];
    do {
        $rec->log = [];
        $p = $sr->supersedePrevious($SUPER, $new['run'], 2);
        $pages[] = $p['rows'];
    } while (!$p['done'] && count($pages) < 10);
    check('supersede: pages of 2 close 5 rows as 2, 2, 1, and the short page finishes',
        $pages === [2, 2, 1] && $p['done'] === true && $p['cursor'] === $new['gen']);
    $rec->log = [];
    $sr->supersedePrevious($SUPER, $new['run'], 2);
    check('supersede: a finished supersede sends no write at all',
        $rec->matching('exec', ['UPDATE']) === []);
    $rows = $dbA->select('SELECT COUNT(*), MIN(valid_to_seq), MAX(valid_to_seq) FROM '
        . Schema::table('finding') . ' WHERE project_id = ? AND generation_id = ? AND active_slot IS NULL',
        [$SUPER, $old['gen']]);
    $seqNew = uv_run_seq($dbA, $new['run']);
    check('supersede: every older row is closed at the closing run\'s sequence',
        $activeOld() === 0 && (int) $rows[0][0] === 5
        && (int) $rows[0][1] === $seqNew && (int) $rows[0][2] === $seqNew);
    $cur = $dbA->select('SELECT supersede_cursor FROM ' . Schema::table('scan_run') . ' WHERE run_id = ?',
        [$new['run']]);
    check('supersede: and the run row carries the finished cursor, readable without calling',
        (int) $cur[0][0] === $new['gen']);
    check('supersede: the closing run\'s own rows and the neighbour\'s are untouched',
        count($sa->findings($SUPER, $new['gen'], [], 0, 100)) === 2
        && uv_neighbour_findings($dbA, $SUPER) === 2);

    // THE PLAN. One more older run to close, recorded, and the page EXPLAINed.
    $third = $runWith(3);
    $rec->log = [];
    $sr->supersedePrevious($SUPER, $third['run'], 2);
    $u = $rec->matching('exec', ['UPDATE ' . Schema::table('finding'), 'active_slot = NULL']);
    $plan = $u ? uv_explain_rows($A, $u[0][1], $u[0][2]) : [];
    $key = $u ? uv_explain_key($A, $u[0][1], $u[0][2]) : '';
    check('supersede: the page is read through a key that begins (project_id, generation_id, active_slot)',
        count($u) === 1 && $key !== ''
        && array_slice(uv_index_columns($A, Schema::table('finding'), $key), 0, 3)
            === ['project_id', 'generation_id', 'active_slot']);
    $m = $rec->matching('select', ['MIN(generation_id)']);
    $mkey = $m ? uv_explain_key($A, $m[0][1], $m[0][2]) : '';
    check('supersede: and the older generations are found through a project-leading run key',
        $mkey !== '' && array_slice(uv_index_columns($A, Schema::table('scan_run'), $mkey), 0, 1) === ['project_id']);
    echo '  plan supersede page: ' . implode(' ', $plan) . ' | older: ' . $mkey . "\n";

    // ONE GROUP'S SCAN CLOSES NOTHING, AND SENDS NOTHING THAT COULD (review
    // R8-1). Older generations with active rows are still here - the third
    // run's supersede has done one page - and a run scoped to group 42
    // completes over one record. Its call reads its own run row and stops:
    // no older generation is looked up, no finding is written, its cursor
    // does not move, and no row anywhere carries its sequence as an end.
    $activeBefore = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = ? AND active_slot = 1', [$SUPER]);
    $gr = $sa->startRun($SUPER, ['created_by' => 'alice', 'scope_dag' => '42']);
    $grid = (int) $gr['run']['run_id'];
    $sa->writeManifest($grid, [['id_bin' => 'SP-G', 'hash' => hash('sha256', 'SP-G', true), 'dag' => '42']]);
    $gep = (int) $sa->run($SUPER, $grid)['lease_epoch'];
    $gc = $sa->claim($grid, 'w', $gep, 1);
    $sa->commitBatch($grid, 'w', $gep, ['bytes' => 0, 'findings' => [], 'records' => [
        ['ordinal' => $gc[0]['ordinal'], 'record_hash' => $gc[0]['hash'], 'state' => ScanStore::REC_DONE,
         'claim' => $gc[0]['claim']]]]);
    $sa->finish($grid, $done);
    $rec->log = [];
    $gp = $sr->supersedePrevious($SUPER, $grid, 100);
    $gseq = uv_run_seq($dbA, $grid);
    $activeAfter = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = ? AND active_slot = 1', [$SUPER]);
    $endedBy = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = ? AND valid_to_seq = ?', [$SUPER, $gseq]);
    $gcur = $dbA->select('SELECT supersede_cursor, terminal FROM ' . Schema::table('scan_run')
        . ' WHERE run_id = ?', [$grid]);
    check('supersede: a completed scan of one group closes nothing and says why',
        $gp['done'] === true && $gp['rows'] === 0 && $gp['why'] === ScanStore::SUPERSEDE_SCOPED
        && (string) $gcur[0][1] === ScanOutcome::COMPLETE);
    check('supersede: every active finding of the project is still active, none ended at its sequence',
        (int) $activeBefore[0][0] > 0 && (int) $activeAfter[0][0] === (int) $activeBefore[0][0]
        && (int) $endedBy[0][0] === 0 && (int) $gcur[0][0] === 0);
    check('supersede: and the call read its run row and nothing else',
        count($rec->log) === 1 && $rec->log[0][0] === 'select'
        && strpos($rec->log[0][1], 'FOR UPDATE') !== false);
}

// A RECORD THAT LEFT THE RUN'S GROUP (review rv5b-1): every statement depart()
// and departedOf() send is read through a key. The manifest rows by
// uq_run_record (run_id, record_hash), the findings by a key that begins
// (project_id, generation_id, record_hash), as commitBatch()'s close is.
{
    $DPID = 964;
    $ds = new SqlScanStore($dbA);
    $dr = $ds->startRun($DPID, ['created_by' => 'alice', 'scope_dag' => '7']);
    $drid = (int) $dr['run']['run_id'];
    $dids = [];
    for ($i = 1; $i <= 40; $i++) $dids[] = sprintf('DP%02d', $i);
    $ds->writeManifest($drid, array_map(function ($id) {
        return ['id_bin' => $id, 'hash' => hash('sha256', $id, true), 'dag' => '7'];
    }, $dids));
    $dep = (int) $ds->run($DPID, $drid)['lease_epoch'];
    $dgen = (int) $ds->run($DPID, $drid)['generation_id'];
    $dseq = (int) uv_run_seq($dbA, $drid);
    $dbatch = ['bytes' => 0, 'records' => [], 'findings' => []];
    foreach ($ds->claim($drid, 'w', $dep, 40) as $c) {
        $dbatch['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                                'state' => ScanStore::REC_DONE, 'version' => '5', 'claim' => $c['claim']];
        $dbatch['findings'][] = ['ordinal' => $c['ordinal'], 'project_id' => $DPID, 'generation_id' => $dgen,
            'host_form' => 'fa', 'field' => 'x', 'check_type' => 'required',
            'reason_code' => 'required-blank', 'record_id_bin' => $c['id_bin'],
            'identity' => hash('sha256', 'dp' . $c['id_bin'], true), 'valid_from_seq' => $dseq,
            'record_hash' => $c['hash'], 'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64)];
    }
    $ds->commitBatch($drid, 'w', $dep, $dbatch);
    $ds->advancePhase($drid, $dep, ScanPhase::CATCH_UP);
    $drec = new UvRecordingDb($dbA);
    $dsr = new SqlScanStore($drec);
    $dn = $dsr->depart($drid, $dep, [hash('sha256', 'DP07', true), hash('sha256', 'DP08', true)]);
    $dof = $dsr->departedOf($drid, [hash('sha256', 'DP07', true), hash('sha256', 'DP09', true)]);
    check('rv5b-1 depart: two records of forty leave the run, and departedOf() names the one asked',
        $dn === 2 && $dof === [hash('sha256', 'DP07', true) => true]);
    $keyOf = function ($kind, array $needles) use ($drec, $A) {
        $q = $drec->matching($kind, $needles);
        if (!$q) return '';
        return uv_explain_key($A, str_replace('FOR UPDATE', '', $q[0][1]), $q[0][2]);
    };
    $rt = Schema::table('scan_record');
    $kSel = $keyOf('select', ['FROM ' . $rt, 'record_hash IN', 'NOT IN']);
    $kUpd = $keyOf('exec', ['UPDATE ' . $rt, 'record_hash IN']);
    $kOf  = $keyOf('select', ['SELECT record_hash, state FROM ' . $rt]);
    $kFind = $keyOf('exec', ['UPDATE ' . Schema::table('finding'), 'record_hash IN']);
    check('rv5b-1 depart: the manifest rows are found, moved and asked about through uq_run_record',
        $kSel === 'uq_run_record' && $kUpd === 'uq_run_record' && $kOf === 'uq_run_record');
    check('rv5b-1 depart: and the findings are closed through a key that begins (project, generation, record)',
        $kFind !== '' && array_slice(uv_index_columns($A, Schema::table('finding'), $kFind), 0, 3)
            === ['project_id', 'generation_id', 'record_hash']);
    echo '  plan depart: ' . $kSel . ' ' . $kUpd . ' ' . $kOf . ' ' . $kFind . "\n";

    // CATCH-UP'S OTHER READS AND WRITES BY RECORD HASH (W5F-N3).
    // scannedVersions(), requeue() and tombstone() filtered
    // `record_id_bin IN (...)`, a column no index holds, so each read the
    // run's whole primary-key range. Asked by hash, each goes through
    // uq_run_record, as depart() does above.
    $nrec = new UvRecordingDb($dbA);
    $nsr = new SqlScanStore($nrec);
    $nh = function ($id) { return hash('sha256', $id, true); };
    $nsv = $nsr->scannedVersions($drid, [$nh('DP11'), $nh('DP12'), $nh('NOT-ON-IT')]);
    $nrq = $nsr->requeue($drid, $dep, [$nh('DP11')]);
    $ntb = $nsr->tombstone($drid, $dep, [$nh('DP12')]);
    $nst = $ds->scannedVersions($drid, [$nh('DP11'), $nh('DP12')]);
    check('W5F-N3 catch-up by hash: the versions come back keyed by hash, the record not on the run left out',
        array_keys($nsv) === [$nh('DP11'), $nh('DP12')] && $nsv[$nh('DP11')]['version'] === '5');
    check('W5F-N3 catch-up by hash: requeue and tombstone move the one row each was given',
        $nrq === 1 && $ntb === 1
        && $nst[$nh('DP11')]['state'] === ScanStore::REC_PENDING && $nst[$nh('DP11')]['version'] === null
        && $nst[$nh('DP12')]['state'] === ScanStore::REC_TOMBSTONE);
    $nKey = function ($kind, array $needles) use ($nrec, $A) {
        $out = [];
        foreach ($nrec->matching($kind, $needles) as $q) $out[] = uv_explain_key($A, $q[1], $q[2]);
        return $out;
    };
    $nSel = $nKey('select', ['SELECT record_hash, version_scanned, state FROM ' . $rt]);
    $nUpd = $nKey('exec', ['UPDATE ' . $rt, 'record_hash IN']);
    // A one-row UPDATE may be planned as an index merge of uq_run_record
    // with PRIMARY (MySQL 8.0 says "uq_run_record,PRIMARY"): the unique key
    // is in it either way, and a filter on record_id_bin plans PRIMARY alone.
    $viaHash = function (array $keys, $n) {
        if (count($keys) !== $n) return false;
        foreach ($keys as $k) {
            if (!in_array('uq_run_record', explode(',', $k), true)) return false;
        }
        return true;
    };
    check('W5F-N3 catch-up by hash: scannedVersions() reads through uq_run_record',
        $viaHash($nSel, 1));
    check('W5F-N3 catch-up by hash: and requeue() and tombstone() write through it',
        $viaHash($nUpd, 2));
    check('W5F-N3 catch-up by hash: no statement filters on the unindexed record id',
        $nrec->matching('select', ['record_id_bin IN']) === []
        && $nrec->matching('exec', ['record_id_bin IN']) === []);
    echo '  plan catch-up: ' . implode(' ', $nSel) . ' ' . implode(' ', $nUpd) . "\n";

    // A CATCH-UP TOMBSTONE CLOSES THE DELETED RECORD'S FINDINGS (W5F-N4), as
    // depart() does: by a key that begins (project, generation, record), at
    // the run's sequence, and never a duplicate-value (staged) finding, which
    // is the finalizer's. DP12 was tombstoned above; DP13's finding is made a
    // published duplicate here, and DP13 and DP14 are deleted together.
    $ft = Schema::table('finding');
    $activeOf = function ($id) use ($dbA, $ft, $DPID, $dgen) {
        $r = $dbA->select('SELECT COUNT(*) FROM ' . $ft . ' WHERE project_id = ? AND generation_id = ?
            AND record_id_bin = ? AND active_slot = 1', [$DPID, $dgen, $id]);
        return (int) $r[0][0];
    };
    $endedAt = $dbA->select('SELECT MIN(valid_to_seq), MAX(valid_to_seq) FROM ' . $ft
        . ' WHERE project_id = ? AND generation_id = ? AND record_id_bin = ?', [$DPID, $dgen, 'DP12']);
    check('W5F-N4 tombstone: the deleted record\'s finding is closed, at the run\'s sequence',
        $activeOf('DP12') === 0 && (int) $endedAt[0][0] === $dseq && (int) $endedAt[0][1] === $dseq);
    check('W5F-N4 tombstone: and the records beside it keep theirs',
        $activeOf('DP11') === 1 && $activeOf('DP13') === 1 && $activeOf('DP14') === 1);
    $A->query('UPDATE ' . $ft . " SET stage_epoch = 1 WHERE project_id = $DPID AND record_id_bin = 'DP13'");
    $trec = new UvRecordingDb($dbA);
    $tn = (new SqlScanStore($trec))->tombstone($drid, $dep, [$nh('DP13'), $nh('DP14'), $nh('DP12')]);
    check('W5F-N4 tombstone: two more records deleted, the one already a tombstone not moved again',
        $tn === 2);
    check('W5F-N4 tombstone: the plain finding is closed, the duplicate-value one left to the finalizer',
        $activeOf('DP14') === 0 && $activeOf('DP13') === 1 && $activeOf('DP11') === 1);
    $tSel = $trec->matching('select', ['FROM ' . $rt, 'record_hash IN', 'FOR UPDATE']);
    $tFind = $trec->matching('exec', ['UPDATE ' . $ft, 'record_hash IN']);
    $tFindKey = $tFind ? uv_explain_key($A, $tFind[0][1], $tFind[0][2]) : '';
    check('W5F-N4 tombstone: the rows that move are chosen through uq_run_record, under their locks',
        count($tSel) === 1
        && $viaHash([uv_explain_key($A, str_replace('FOR UPDATE', '', $tSel[0][1]), $tSel[0][2])], 1));
    check('W5F-N4 tombstone: and the findings are closed through a key that begins (project, generation, record)',
        count($tFind) === 1 && $tFindKey !== ''
        && array_slice(uv_index_columns($A, $ft, $tFindKey), 0, 3)
            === ['project_id', 'generation_id', 'record_hash']);
    // A requeue closes nothing: the record is read again, and its next
    // commit supersedes what it had. Of the requeue and the tombstone above,
    // only the tombstone sent a close.
    check('W5F-N4 tombstone: a requeue sends no finding close',
        count($nrec->matching('exec', ['UPDATE ' . $ft])) === 1);
    echo '  plan tombstone: ' . $tFindKey . "\n";
}

// WHAT THE DETAIL BUDGET LEFT OUT, AND WHAT THE RUN HOLDS, ON A REAL SERVER
// (reviews rvf1-3, W5F-N2). The contract above drives the counts; this is
// the two things only a server shows: a run from before schema version 6
// (open_unstored and open_findings NULL) keeps NULL through a commit, a
// requeue and a departure, where `NULL + ?` and the GREATEST(CAST(...))
// take-back could have errored or started a count; and a take-back larger
// than the count stops at 0 instead of failing the unsigned column.
{
    $UPID = 965;
    $us = new SqlScanStore($dbA);
    $ur = $us->startRun($UPID, ['created_by' => 'alice']);
    $urid = (int) $ur['run']['run_id'];
    $us->writeManifest($urid, [['id_bin' => 'N1', 'hash' => hash('sha256', 'N1', true), 'dag' => null],
                               ['id_bin' => 'N2', 'hash' => hash('sha256', 'N2', true), 'dag' => null]]);
    $uep = (int) $us->run($UPID, $urid)['lease_epoch'];
    $A->query('UPDATE ' . Schema::table('scan_run')
        . ' SET open_unstored = NULL, open_findings = NULL WHERE run_id = ' . $urid);
    $urow = $us->run($UPID, $urid);
    $uc = $us->claim($urid, 'w', $uep, 2);
    $ub = ['bytes' => 0, 'findings' => [], 'records' => [], 'unstored' => []];
    foreach ($uc as $c) {
        $ub['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                            'state' => ScanStore::REC_DONE, 'version' => '1', 'claim' => $c['claim']];
        $ub['unstored'][$c['ordinal']] = 2;
        $ub['findings'][] = ['ordinal' => $c['ordinal'], 'project_id' => $UPID,
            'generation_id' => (int) $urow['generation_id'], 'host_form' => 'fa', 'field' => 'x',
            'check_type' => 'required', 'reason_code' => 'required-blank', 'record_id_bin' => $c['id_bin'],
            'identity' => hash('sha256', 'n' . $c['id_bin'], true), 'valid_from_seq' => (int) $urow['run_seq'],
            'record_hash' => $c['hash'], 'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64)];
    }
    $okU = $us->commitBatch($urid, 'w', $uep, $ub) === true;
    $afterU = $us->run($UPID, $urid);
    $nullAfterCommit = $afterU['open_unstored'];
    $rowsK = $dbA->select('SELECT stored_findings FROM ' . Schema::table('scan_record')
        . ' WHERE run_id = ? ORDER BY ordinal', [$urid]);
    $us->advancePhase($urid, $uep, ScanPhase::CATCH_UP);
    $rq = $us->requeue($urid, $uep, [hash('sha256', 'N1', true)]);
    $dp = $us->depart($urid, $uep, [hash('sha256', 'N2', true)]);
    check('rvf1-3 server: a run from before the count keeps NULL through a commit, a requeue and a departure',
        $okU && $nullAfterCommit === null && $rq === 1 && $dp === 1
        && $us->run($UPID, $urid)['open_unstored'] === null);
    check('W5F-N2 server: and keeps no count of what it holds either, while each row says what it stored',
        $afterU['open_findings'] === null && (int) $afterU['detail_rows'] === 2
        && (int) $rowsK[0][0] === 1 && (int) $rowsK[1][0] === 1
        && $us->run($UPID, $urid)['open_findings'] === null);
    // The rows still say what each reading left out, then nothing once it
    // left done, whatever the run's count.
    $rowsU = $dbA->select('SELECT record_id_bin, unstored, stored_findings FROM ' . Schema::table('scan_record')
        . ' WHERE run_id = ? ORDER BY ordinal', [$urid]);
    check('rvf1-3 server: and each row\'s own count is zeroed when it leaves done',
        (int) $rowsU[0][1] === 0 && (int) $rowsU[1][1] === 0);
    check('W5F-N2 server: and so is each row\'s stored count',
        (int) $rowsU[0][2] === 0 && (int) $rowsU[1][2] === 0);
    $A->query('UPDATE ' . Schema::table('scan_run')
        . ' SET open_unstored = 1, open_findings = 1 WHERE run_id = ' . $urid);
    $A->query('UPDATE ' . Schema::table('scan_record') . ' SET state = ' . ScanStore::REC_DONE
        . ', unstored = 5, stored_findings = 4 WHERE run_id = ' . $urid . " AND record_id_bin = 'N1'");
    $tb = $us->tombstone($urid, $uep, [hash('sha256', 'N1', true)]);
    $afterTb = $us->run($UPID, $urid);
    check('rvf1-3 server: a take-back larger than the count stops at 0 rather than failing the write',
        $tb === 1 && (int) $afterTb['open_unstored'] === 0);
    check('W5F-N2 server: on the held count too',
        $afterTb['open_findings'] !== null && (int) $afterTb['open_findings'] === 0);
}

// A RECORD WHOSE READING THE RUN NO LONGER HOLDS TAKES ITS UNIQUENESS
// CANDIDATES WITH IT (W5F-N1). Sent back to be read again, deleted or out of
// the run's group, its candidates in the run's generation are deleted in the
// transaction that moves it, through ix_record (schema version 6): a record
// read again with a new value kept its old candidate in the old value's
// group, and a deleted record kept one with nothing behind it, and the
// duplicate check blocked both groups. 300 other records' candidates in the
// same generation make the plan the one a real project gets.
{
    $CPID = 966;
    $cs = new SqlScanStore($dbA);
    $cr = $cs->startRun($CPID, ['created_by' => 'alice'])['run'];
    $crid = (int) $cr['run_id'];
    $cgen = (int) $cr['generation_id'];
    $cep = (int) $cr['lease_epoch'];
    $ch = function ($id) { return hash('sha256', 'c' . $id, true); };
    $cs->writeManifest($crid, array_map(function ($id) use ($ch) {
        return ['id_bin' => $id, 'hash' => $ch($id), 'dag' => null];
    }, ['C1', 'C2', 'C3', 'C4']));
    $ct = Schema::table('unique_candidate');
    $cand = function ($ord, $id, $group, $version) use ($CPID, $cgen, $ch) {
        return ['ordinal' => $ord, 'project_id' => $CPID, 'generation_id' => $cgen,
                'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64),
                'group_hmac' => hash('sha256', 'g' . $group, true), 'scope_key' => 'project',
                'record_hash' => $ch($id), 'record_id_bin' => $id, 'event_id' => 1, 'instance' => 1,
                'host_form' => 'f', 'field' => 'hospno', 'version' => $version];
    };
    $cb0 = ['bytes' => 0, 'records' => [], 'findings' => [], 'candidates' => []];
    foreach ($cs->claim($crid, 'w', $cep, 4) as $c) {
        $cb0['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'], 'version' => '100',
                             'state' => ScanStore::REC_DONE, 'claim' => $c['claim']];
        $cb0['candidates'][] = $cand($c['ordinal'], $c['id_bin'], 'SAME', '100');
    }
    $okC = $cs->commitBatch($crid, 'w', $cep, $cb0) === true;
    $fill = [];
    for ($i = 0; $i < 300; $i++) {
        $fill[] = '(' . $CPID . ', ' . $cgen . ", 'r1', '" . str_repeat('c', 64) . "', UNHEX(SHA2('g" . $i
                . "', 256)), 'project', UNHEX(SHA2('f" . $i . "', 256)), 'F" . $i . "', 1, 1, 'f', 'hospno', '1')";
    }
    $A->query('INSERT INTO ' . $ct . ' (project_id, generation_id, rule_source_id, rule_revision, group_hmac,
        scope_key, record_hash, record_id_bin, event_id, instance, host_form, field, version_scanned)
        VALUES ' . implode(',', $fill));
    $A->query('ANALYZE TABLE ' . $ct);
    $candOf = function () use ($dbA, $ct, $CPID, $cgen) {
        $out = [];
        foreach ($dbA->select('SELECT record_id_bin, COUNT(*) FROM ' . $ct . " WHERE project_id = ?
                AND generation_id = ? AND record_id_bin LIKE 'C%' GROUP BY record_id_bin ORDER BY record_id_bin",
                [$CPID, $cgen]) as $row) {
            $out[(string) $row[0]] = (int) $row[1];
        }
        return $out;
    };
    check('W5F-N1 server: the first reading wrote a candidate for each of the four records',
        $okC && $candOf() === ['C1' => 1, 'C2' => 1, 'C3' => 1, 'C4' => 1]);
    $cs->advancePhase($crid, $cep, ScanPhase::CATCH_UP);
    $crec = new UvRecordingDb($dbA);
    $csr = new SqlScanStore($crec);
    $moved = [$csr->requeue($crid, $cep, [$ch('C1')]), $csr->tombstone($crid, $cep, [$ch('C2')]),
              $csr->depart($crid, $cep, [$ch('C3')])];
    check('W5F-N1 server: a requeue, a tombstone and a departure each take their record\'s candidates, and only those',
        $moved === [1, 1, 1] && $candOf() === ['C4' => 1]
        && (int) $dbA->select('SELECT COUNT(*) FROM ' . $ct . " WHERE project_id = ? AND generation_id = ?
            AND record_id_bin LIKE 'F%'", [$CPID, $cgen])[0][0] === 300);
    $cDel = $crec->matching('exec', ['DELETE FROM ' . $ct]);
    $cKeys = [];
    foreach ($cDel as $q) {
        $cKeys[] = uv_explain_key($A, $q[1], $q[2]);
    }
    check('W5F-N1 server: each in one DELETE through ix_record (project_id, generation_id, record_hash)',
        count($cDel) === 3 && $cKeys === ['ix_record', 'ix_record', 'ix_record']
        && uv_index_columns($A, $ct, 'ix_record') === ['project_id', 'generation_id', 'record_hash']);
    // The record sent back is read again with a new value: its candidate is
    // the new reading's, in the new value's group, and no other.
    $cc = $cs->claimPending($crid, 'w2', $cep, 10);
    $okC2 = is_array($cc) && count($cc) === 1 && $cs->commitBatch($crid, 'w2', $cep, ['bytes' => 0,
        'findings' => [], 'records' => [['ordinal' => $cc[0]['ordinal'], 'record_hash' => $cc[0]['hash'],
            'version' => '101', 'state' => ScanStore::REC_DONE, 'claim' => $cc[0]['claim']]],
        'candidates' => [$cand($cc[0]['ordinal'], 'C1', 'NEW', '101')]]) === true;
    $c1 = $dbA->select('SELECT group_hmac, version_scanned FROM ' . $ct . " WHERE project_id = ?
        AND generation_id = ? AND record_id_bin = 'C1'", [$CPID, $cgen]);
    check('W5F-N1 server: read again, the record holds the new reading\'s candidate only',
        $okC2 && count($c1) === 1 && $c1[0][0] === hash('sha256', 'gNEW', true) && (string) $c1[0][1] === '101');
    echo '  plan candidates by record: ' . implode(' ', $cKeys) . "\n";
}

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
// This store keeps a claim's time in updated_at, which the claim writes.
$age = function ($store, $runId, $seconds) use ($A) {
    $A->query('UPDATE ' . Schema::table('scan_record')
        . ' SET updated_at = DATE_SUB(updated_at, INTERVAL ' . (int) $seconds . ' SECOND)'
        . ' WHERE run_id = ' . (int) $runId . ' AND claim_seq <> 0');
};
// The same tables, cleared the same way, behind a store that reads elapsed
// time from the contract's clock.
$clocked = function ($clock) use ($fresh, $dbA) {
    $fresh();
    return new SqlScanStore($dbA, $clock);
};
// What nothing in the module does while a run plans: its plan row deleted.
$dropPlan = function ($store, $runId) use ($A) {
    $A->query('DELETE FROM ' . Schema::table('scan_plan') . ' WHERE run_id = ' . (int) $runId);
};
\INSPIRE\UniversalValidator\Scan\storeContract($fresh, 'sql-store', $age, $clocked, $dropPlan);
