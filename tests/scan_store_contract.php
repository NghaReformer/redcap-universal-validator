<?php
/**
 * scan_store_contract.php — ONE assertion set, run against every ScanStore.
 *
 * Included by tests/scan_store_php.php (against ArrayScanStore, in the fast
 * suite) and by tests/mysql/run.php (against SqlScanStore, on four real
 * servers). That is the whole idea: two independent implementations judged by
 * identical assertions disagree wherever the contract is ambiguous, and
 * ambiguity is where the bugs are. It is the technique this repository already
 * uses to keep the PHP and JavaScript rule engines from drifting.
 *
 * It defines one function and runs nothing on its own. The caller supplies a
 * factory, because some stores need tearing down between scenarios and the
 * contract must not know which.
 *
 * The caller must already have defined check($label, $cond).
 */

namespace INSPIRE\UniversalValidator\Scan;

/**
 * @param callable $newStore  () => ScanStore, fresh and empty
 * @param string   $label     which implementation, for the check names
 */
function storeContract(callable $newStore, $label)
{
    $C = function ($what, $cond) use ($label) { \check("$label: $what", $cond); };

    // -- one active run per project ----------------------------------------
    $s = $newStore();
    $r1 = $s->startRun(700, ['created_by' => 'alice']);
    $C('the first start on a project succeeds', $r1['ok'] === true && $r1['busy'] === false);
    $runId = (int) $r1['run']['run_id'];

    $r2 = $s->startRun(700, ['created_by' => 'bob']);
    $C('a second start is BUSY rather than an error', $r2['ok'] === false && $r2['busy'] === true);
    $C('and busy hands back no run to look at', $r2['run'] === null);
    // Non-disclosure: busy must not distinguish who holds the slot, or it is an
    // oracle for anyone who can press the button.
    $C('busy names no owner and no number',
        stripos($r2['why'], 'alice') === false && preg_match('/\d/', $r2['why']) === 0);

    $C('a different project is unaffected', $s->startRun(701, [])['ok'] === true);

    // -- the run id is a locator, never an authorisation -------------------
    $C('a run id does not resolve under another project', $s->run(701, $runId) === null);
    $C('but does under its own', $s->run(700, $runId) !== null);

    // -- manifest ----------------------------------------------------------
    $recs = [];
    for ($i = 1; $i <= 5; $i++) {
        $recs[] = ['id_bin' => 'REC-' . $i, 'hash' => hash('sha256', 'REC-' . $i, true),
                   'dag' => null];
    }
    $C('the manifest writes every record', $s->writeManifest($runId, $recs) === 5);
    $run = $s->run(700, $runId);
    $C('and publishes the total with them', (int) $run['manifest_total'] === 5);
    $C('leaving the run ready to scan', $run['phase'] === 'scanning');
    $C('an unstarted manifest is not complete', $s->manifestComplete($runId) === false);

    // -- claiming is fenced ------------------------------------------------
    $epoch = (int) $run['lease_epoch'];
    $claim = $s->claim($runId, 'w1', $epoch, 2);
    $C('a claim returns the requested range', count($claim) === 2);
    $C('in ordinal order, from the start', $claim[0]['ordinal'] === 1 && $claim[1]['ordinal'] === 2);
    $C('carrying the worker locator rather than a hash', $claim[0]['id_bin'] === 'REC-1');
    // THE CLAIM TOKEN. Every subsequent write about these rows carries it back,
    // and a write whose token no longer matches the row touches nothing. It is
    // what makes takeover expressible at all: lease_epoch moves on cancellation
    // and on nothing else, so before this a second worker could re-claim a
    // stale worker's rows while both held the same epoch, and the first
    // worker's commit sailed through the fence.
    $C('every claimed row carries a claim token',
        isset($claim[0]['claim']) && (int) $claim[0]['claim'] > 0);
    $C('and one claim stamps one token on all of its rows',
        (int) $claim[0]['claim'] === (int) $claim[1]['claim']);
    $C('a later claim gets a DIFFERENT token, or the fence cannot tell them apart',
        (int) $s->claim($runId, 'w1', $epoch, 1)[0]['claim'] > (int) $claim[0]['claim']);
    // REFUSED AND EMPTY ARE DIFFERENT ANSWERS. `[]` means the run has nothing
    // more to hand out and the caller may move on; `false` means this worker may
    // not have any right now and must stop. Both were `[]`, and the first live
    // pilot walked a 39-record run to its final phase having examined three
    // records, reporting `done` on the way out.
    $C('a claim at a stale epoch is REFUSED, not empty',
        $s->claim($runId, 'w2', $epoch - 1, 2) === false);
    $C('and a straggler sweep at a stale epoch is refused too',
        $s->claimPending($runId, 'w2', $epoch - 1, 2) === false);
    // The distinction has to survive ===, because that is how the worker reads
    // it: an empty array and false are both falsy, which is exactly how they got
    // conflated in the first place.
    $C('the two answers are not merely both falsy',
        $s->claim($runId, 'w2', $epoch - 1, 2) !== []);

    // -- committing ---------------------------------------------------------
    // EVERY ROW NAMES ITS PROJECT AND ITS GENERATION, and the record rows carry
    // their hash. Both are new contract rather than fixture tidying: a finding
    // with no project belongs to nothing - invisible to its own report and
    // immune to its own project's retention - and without the record hash the
    // store cannot close that record's previous evidence in the transaction
    // that writes the new evidence, which is what wedged every re-examined
    // record before this release.
    $gen = (int) $run['generation_id'];
    $batch = ['bytes' => 20, 'records' => [], 'findings' => []];
    foreach ($claim as $c) {
        $batch['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                               'state' => ScanStore::REC_DONE, 'claim' => $c['claim']];
        $batch['findings'][] = ['ordinal' => $c['ordinal'],
                                'project_id' => 700, 'generation_id' => $gen,
                                'host_form' => 'fa', 'field' => 'x',
                                'check_type' => 'required', 'reason_code' => 'required-blank',
                                'record_id_bin' => $c['id_bin'],
                                'identity' => hash('sha256', 'f' . $c['ordinal'], true),
                                'valid_from_seq' => (int) $run['run_seq'],
                                'record_hash' => $c['hash'],
                                'rule_source_id' => 'r1',
                                'rule_revision' => str_repeat('c', 64)];
    }
    foreach ($batch['records'] as $i => $rec) {
        // The source version each record was examined AT travels with its
        // state. Catch-up compares it against the change log to decide whether
        // an edit is already inside the reading we hold, so a store that
        // dropped it would requeue every record the log mentions on every run.
        $batch['records'][$i]['version'] = '700';
    }
    $C('a fenced batch commits', $s->commitBatch($runId, 'w1', $epoch, $batch) === true);
    $seen = $s->scannedVersions($runId, ['REC-1', 'REC-2']);
    $C('and each record remembers the version it was examined at',
        isset($seen['REC-1']['version']) && $seen['REC-1']['version'] === '700');
    $C('with its terminal state beside it',
        (int) $seen['REC-1']['state'] === ScanStore::REC_DONE);
    $C('a record nobody has reached yet reports no version',
        $s->scannedVersions($runId, ['REC-5'])['REC-5']['version'] === null);

    // The state census is what the coverage predicate reads. Counted from the
    // rows, never accumulated: a counter can be incremented twice.
    $st = $s->recordStates($runId);
    $C('the state census counts what was finished',
        isset($st[ScanStore::REC_DONE]) && (int) $st[ScanStore::REC_DONE] === 2);
    $C('and what is still waiting',
        isset($st[ScanStore::REC_PENDING]) && (int) $st[ScanStore::REC_PENDING] === 3);

    // Reconciliation refuses outside the catch-up phase. The manifest is frozen
    // for a reason, and this is the ONE sanctioned exception - so it is only
    // available where it is sanctioned.
    $C('records may not be added while the run is still scanning',
        $s->reconcileAdd($runId, $epoch, [['id_bin' => 'REC-9',
            'hash' => hash('sha256', 'REC-9', true), 'dag' => null]]) === 0);
    $C('nor may a finished record be requeued from outside catch-up',
        $s->requeue($runId, $epoch, ['REC-1']) === 0);

    // Aggregates ADD. A page that set the value would report only its own page.
    $s->addAggregate($runId, 'collection-gap', 'chest_xray', null, 3860);
    $s->addAggregate($runId, 'collection-gap', 'chest_xray', null, 140);
    $found = null;
    foreach ($s->aggregates($runId) as $a) {
        if ($a['kind'] === 'collection-gap' && $a['axis1'] === 'chest_xray') $found = $a;
    }
    $C('an aggregate accumulates across pages', $found !== null && (int) $found['cnt'] === 4000);
    $C('and a non-blocking one does not block',
        $s->blockingAggregates($runId) === 0);
    $s->addAggregate($runId, 'unread-record', null, null, 1, 1);
    $C('while a blocking one does', $s->blockingAggregates($runId) === 1);
    $run = $s->run(700, $runId);
    $C('advancing manifest_done by what it finished', (int) $run['manifest_done'] === 2);
    $C('and counting the findings it retained', (int) $run['detail_rows'] === 2);
    $C('three records left means not complete', $s->manifestComplete($runId) === false);

    // -- cancellation beats an in-flight worker ----------------------------
    $C('cancel succeeds on an active run', $s->cancel(700, $runId, 'admin') === true);
    $after = $s->run(700, $runId);
    $C('and bumps the lease epoch', (int) $after['lease_epoch'] === $epoch + 1);
    $before = (int) $after['detail_rows'];
    $lost = ['bytes' => 5,
             'records' => [['ordinal' => 3, 'state' => ScanStore::REC_DONE,
                            'claim' => (int) $claim[0]['claim']]],
             'findings' => [['ordinal' => 3, 'generation_id' => 1, 'host_form' => 'fa', 'field' => 'y',
                             'check_type' => 'required', 'reason_code' => 'required-blank',
                             'record_id_bin' => 'REC-3',
                             'identity' => hash('sha256', 'lost', true), 'seq' => 1,
                             'record_hash' => hash('sha256', 'REC-3', true),
                             'rule_source_id' => 'r1',
                             'rule_revision' => str_repeat('c', 64)]]];
    // The commit reports WHICH fence refused, so a caller can tell a stopped run
    // from a taken-over one from a database that would not write. `!== true` is
    // the failure test; the string is the diagnosis.
    $refusal = $s->commitBatch($runId, 'w1', $epoch, $lost);
    $C('an overtaken worker cannot commit', $refusal !== true);
    $C('and is told which fence refused it',
        is_string($refusal) && strlen($refusal) > 20);

    // RELEASING CLAIMS. A rolled-back batch leaves its rows claimed, and a
    // claimed row is invisible to the straggler sweep until it goes stale - so
    // with a phase machine that refuses to advance over unexamined records, the
    // two safe behaviours combine into a deadlock unless the worker hands them
    // back.
    $C('a stale epoch releases nothing',
        $s->releaseClaims($runId, $epoch - 5, 'w1',
            [1 => (int) $claim[0]['claim'], 2 => (int) $claim[1]['claim']]) === 0);
    $C('and left no finding behind', (int) $s->run(700, $runId)['detail_rows'] === $before);
    $C('nor advanced the done count',
        (int) $s->run(700, $runId)['manifest_done'] === 2);

    // -- finishing ----------------------------------------------------------
    $outcome = ScanOutcome::derive(['fenced' => true, 'manifestDone' => false, 'blocked' => true]);
    $C('finishing succeeds once', $s->finish($runId, $outcome) === true);
    $C('and a retried finaliser changes nothing', $s->finish($runId, $outcome) === false);
    $done = $s->run(700, $runId);
    $C('the terminal state is recorded', $done['terminal'] === ScanOutcome::PARTIAL);
    $C('with the coverage it earned', $done['coverage'] === ScanOutcome::COV_PARTIAL);
    $C('and the project slot is released, so the next scan may start',
        $s->startRun(700, ['created_by' => 'carol'])['ok'] === true);

    // -- streaming a manifest, and the records the cursor leaves behind ------
    //
    // A million-record manifest cannot arrive as one PHP array, so planning
    // appends pages and freezes at the end. Everything below is about what that
    // splitting makes possible to get wrong.
    $s3 = $newStore();
    $r3 = $s3->startRun(710, ['created_by' => 'alice']);
    $rid3 = (int) $r3['run']['run_id'];
    $mk = function ($id) {
        return ['id_bin' => $id, 'hash' => hash('sha256', $id, true), 'dag' => null];
    };

    $C('appending a page adds its records', $s3->appendManifest($rid3, [$mk('A'), $mk('B')]) === 2);
    $C('and the run is not scanning yet', $s3->run(710, $rid3)['phase'] === 'planning');
    $C('a second page continues rather than restarting',
        $s3->appendManifest($rid3, [$mk('C')]) === 1);
    // The record walk re-reads its page boundary so it cannot skip an id the
    // database considers equal to the cursor, so the same record IS offered
    // twice, by design. It must land once.
    $C('re-offering a record already in the manifest adds nothing',
        $s3->appendManifest($rid3, [$mk('C'), $mk('D')]) === 1);

    $total = $s3->freezeManifest($rid3);
    $C('freezing publishes the COUNT of what is there', $total === 4);
    $C('and the published total is what the run reports',
        (int) $s3->run(710, $rid3)['manifest_total'] === 4);
    $C('freezing moves the run to scanning', $s3->run(710, $rid3)['phase'] === 'scanning');
    // A manifest that could still grow after work started would let a run
    // redefine what "all" means halfway through.
    $C('appending after the freeze is refused', $s3->appendManifest($rid3, [$mk('E')]) === 0);
    $C('and freezing twice is refused rather than repeated',
        $s3->freezeManifest($rid3) === false);
    $C('so the total did not move', (int) $s3->run(710, $rid3)['manifest_total'] === 4);

    // THE STRAGGLER. The ordinal cursor only moves forward, so a record left
    // pending below it is unreachable by claim() forever - the run would wait
    // for a row nothing could offer while holding the project's scan slot.
    $ep3 = (int) $s3->run(710, $rid3)['lease_epoch'];
    $first = $s3->claim($rid3, 'w1', $ep3, 4);
    $C('the first pass claims the whole manifest', count($first) === 4);
    // Commit three of them and leave one behind, as a stable-read failure would.
    $batch = ['bytes' => 0, 'records' => [], 'findings' => []];
    foreach (array_slice($first, 0, 3) as $c) {
        $batch['records'][] = ['ordinal' => $c['ordinal'], 'state' => ScanStore::REC_DONE,
                               'claim' => $c['claim']];
    }
    $C('and commits what it finished', $s3->commitBatch($rid3, 'w1', $ep3, $batch) === true);
    $C('leaving the run incomplete', $s3->manifestComplete($rid3) === false);
    $C('the cursor pass cannot reach the record it left behind',
        $s3->claim($rid3, 'w1', $ep3, 10) === []);

    $left = $s3->claimPending($rid3, 'w2', $ep3, 10);
    $C('but a state-based claim can', count($left) === 1);
    $C('and it is the one that was skipped',
        $left[0]['ordinal'] === $first[3]['ordinal']);
    $C('claiming by state hands the same row to nobody else',
        $s3->claimPending($rid3, 'w3', $ep3, 10) === []);
    // Claimed is not terminal, and a claimed row must still be committable -
    // otherwise the straggler sweep could take a record and never finish it.
    $C('a claimed record still commits',
        $s3->commitBatch($rid3, 'w2', $ep3, ['bytes' => 0, 'findings' => [],
            'records' => [['ordinal' => $left[0]['ordinal'], 'state' => ScanStore::REC_DONE,
                           'claim' => $left[0]['claim']]]]) === true);
    $C('and the manifest is then complete', $s3->manifestComplete($rid3) === true);

    // Progress counts what BECAME terminal, not what was offered. A record
    // re-offered after a requeue would otherwise push the figure past the total.
    $C('progress never exceeds the manifest',
        (int) $s3->run(710, $rid3)['manifest_done'] === 4);
    $C('re-committing a finished record does not advance it again',
        $s3->commitBatch($rid3, 'w2', $ep3, ['bytes' => 0, 'findings' => [],
            'records' => [['ordinal' => $first[0]['ordinal'], 'state' => ScanStore::REC_DONE,
                           'claim' => $first[0]['claim']]]]) === true
        && (int) $s3->run(710, $rid3)['manifest_done'] === 4);

    // Fencing applies to the straggler sweep exactly as it does to the cursor.
    $s4 = $newStore();
    $r4 = $s4->startRun(711, []);
    $rid4 = (int) $r4['run']['run_id'];
    $s4->writeManifest($rid4, [$mk('Z')]);
    $ep4 = (int) $s4->run(711, $rid4)['lease_epoch'];
    $C('a stale epoch is refused the stragglers rather than told there are none',
        $s4->claimPending($rid4, 'w', $ep4 - 1, 5) === false);
    // A CANCELLED RUN IS THE CASE THAT COST MOST. Returning [] here reads as
    // "this phase is finished", so the worker advanced - and kept advancing, to
    // the end of the chain, over records nobody had looked at.
    $C('a cancelled run REFUSES stragglers rather than reporting none left',
        $s4->cancel(711, $rid4, 'admin') === true
        && $s4->claimPending($rid4, 'w', (int) $s4->run(711, $rid4)['lease_epoch'], 5) === false);

    // -- THE PER-RECORD FENCE, AND THE WAY OUT OF A WEDGED RUN ---------------
    //
    // Everything below is one release's worth of findings, and all of it is
    // about the same shape: work that was ATTEMPTED and could not be stored.
    // Before it, `attempts` moved only inside the transaction a refused batch
    // rolls back, so the retry cap could not be reached by the one path that
    // needed it; a taken-over record's stale findings killed the batch it
    // travelled in; and a run that had already finished still accepted commits.

    $s5 = $newStore();
    $r5 = $s5->startRun(720, ['created_by' => 'alice']);
    $rid5 = (int) $r5['run']['run_id'];
    $mk5 = function ($id) {
        return ['id_bin' => $id, 'hash' => hash('sha256', $id, true), 'dag' => null];
    };
    $s5->writeManifest($rid5, [$mk5('P'), $mk5('Q'), $mk5('R')]);
    $ep5 = (int) $s5->run(720, $rid5)['lease_epoch'];
    $gen5 = (int) $s5->run(720, $rid5)['generation_id'];

    // w1 takes the first two; the straggler sweep then takes the FIRST one back
    // out from under it, which is what a worker that looked dead gets.
    $mine = $s5->claim($rid5, 'w1', $ep5, 2);
    $C('the first worker claims two records', count($mine) === 2);
    $stolen = $s5->claimPending($rid5, 'w2', $ep5, 1);
    $C('and the straggler sweep takes the first of them over',
        count($stolen) === 1 && (int) $stolen[0]['ordinal'] === (int) $mine[0]['ordinal']);
    $C('replacing its claim token rather than bumping the run lease',
        (int) $stolen[0]['claim'] !== (int) $mine[0]['claim']
        && (int) $s5->run(720, $rid5)['lease_epoch'] === $ep5);

    // THE BATCH DOES NOT DIE WITH THE RECORD IT LOST. This is the whole point
    // of fencing per record: w1 still holds Q, and Q must commit.
    $f5 = function ($ord, $bin, $tag) use ($gen5) {
        return ['ordinal' => $ord, 'project_id' => 720, 'generation_id' => $gen5,
                'host_form' => 'fa', 'field' => 'x', 'check_type' => 'required',
                'reason_code' => 'required-blank', 'record_id_bin' => $bin,
                'identity' => hash('sha256', $tag, true),
                'record_hash' => hash('sha256', $bin, true),
                'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64)];
    };
    $mixed = ['bytes' => 0, 'records' => [], 'findings' => []];
    foreach ($mine as $c) {
        $mixed['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                               'state' => ScanStore::REC_DONE, 'version' => '1',
                               'claim' => $c['claim']];
        $mixed['findings'][] = $f5($c['ordinal'], $c['id_bin'], 'f' . $c['ordinal']);
    }
    $C('a batch holding one lost record still commits',
        $s5->commitBatch($rid5, 'w1', $ep5, $mixed) === true);
    $st5 = $s5->recordStates($rid5);
    $C('the record it still held is finished',
        isset($st5[ScanStore::REC_DONE]) && (int) $st5[ScanStore::REC_DONE] === 1);
    $C('the record it lost is left with its new holder',
        isset($st5[ScanStore::REC_CLAIMED]) && (int) $st5[ScanStore::REC_CLAIMED] === 1);
    $C('and only the held record\'s finding was written',
        (int) $s5->run(720, $rid5)['detail_rows'] === 1);
    $C('so progress counts one record, not two',
        (int) $s5->run(720, $rid5)['manifest_done'] === 1);

    // A ROW WITH NO TOKEN IS NOT WRITEABLE, and it fails loudly rather than
    // being fenced out quietly - a caller that forgot the token has a bug, and
    // silently writing nothing is how that bug reaches a pilot.
    $threw = false;
    try {
        $s5->commitBatch($rid5, 'w1', $ep5, ['bytes' => 0, 'findings' => [],
            'records' => [['ordinal' => 3, 'record_hash' => hash('sha256', 'R', true),
                           'state' => ScanStore::REC_DONE]]]);
    } catch (\Throwable $e) { $threw = true; }
    $C('a batch record with no claim token is refused loudly', $threw === true);

    $threw = false;
    try {
        $orphan = $f5(0, 'Q', 'orphan');
        unset($orphan['ordinal']);
        $s5->commitBatch($rid5, 'w1', $ep5, ['bytes' => 0, 'records' => [],
                                             'findings' => [$orphan]]);
    } catch (\Throwable $e) { $threw = true; }
    $C('and a finding that names no record is refused loudly too', $threw === true);

    // A REFUSED BATCH CHANGES NOTHING AT ALL.
    //
    // THE CONTRACT WAS MISSING THIS, and the gap was not academic. "A batch
    // carrying one identity twice is refused entire" was asserted only against
    // the real server, where a transaction rolls back for free; the in-memory
    // store wrote its record states first and then returned the refusal, so it
    // reported a permanently refused record as DONE. Two stores judged by one
    // assertion set are only worth having if the assertion set covers the case,
    // and the case here is the one the whole retry cap exists for: what a
    // project is left in by a write that failed.
    $s8 = $newStore();
    $r8 = $s8->startRun(750, []);
    $rid8 = (int) $r8['run']['run_id'];
    $s8->writeManifest($rid8, [$mk5('V'), $mk5('W')]);
    $ep8 = (int) $s8->run(750, $rid8)['lease_epoch'];
    $gen8 = (int) $s8->run(750, $rid8)['generation_id'];
    $c8 = $s8->claim($rid8, 'w1', $ep8, 2);
    $twice = hash('sha256', 'emitted-twice-by-mistake', true);
    $f8 = function ($ord, $bin, $identity) use ($gen8) {
        return ['ordinal' => $ord, 'project_id' => 750, 'generation_id' => $gen8,
                'host_form' => 'fa', 'field' => 'x', 'check_type' => 'required',
                'reason_code' => 'required-blank', 'record_id_bin' => $bin,
                'identity' => $identity, 'record_hash' => hash('sha256', $bin, true),
                'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64)];
    };
    $bad = ['bytes' => 7, 'records' => [], 'findings' => [
        $f8($c8[0]['ordinal'], 'V', $twice),
        $f8($c8[0]['ordinal'], 'V', $twice),
        // A perfectly good finding, on the OTHER record. It is here because the
        // claim is that NOTHING from the batch survives, not that the repeat
        // was dropped.
        $f8($c8[1]['ordinal'], 'W', hash('sha256', 'a-different-finding', true))]];
    foreach ($c8 as $c) {
        $bad['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                             'state' => ScanStore::REC_DONE, 'claim' => $c['claim']];
    }
    $no = $s8->commitBatch($rid8, 'w1', $ep8, $bad);
    $C('a batch carrying one identity twice is refused', $no !== true);
    $C('and says the database refused it, not that the fence did',
        is_string($no) && strpos($no, 'database refused') !== false);
    $st8 = $s8->recordStates($rid8);
    $C('with no record left marked examined',
        !isset($st8[ScanStore::REC_DONE]) || (int) $st8[ScanStore::REC_DONE] === 0);
    $C('and both still claimable, so a corrected worker can redo them',
        (int) $s8->run(750, $rid8)['manifest_done'] === 0
        && $s8->manifestComplete($rid8) === false);
    $C('nor one detail row counted for the good finding it also carried',
        (int) $s8->run(750, $rid8)['detail_rows'] === 0);

    // -- THE ATTEMPT IS COUNTED FOR WORK ATTEMPTED ---------------------------
    //
    // B8 in four assertions. The counter must move in a transaction the failed
    // one cannot roll back, or a record whose write keeps being refused is
    // retried without bound while its run holds the project's only scan slot.
    $s6 = $newStore();
    $r6 = $s6->startRun(730, []);
    $rid6 = (int) $r6['run']['run_id'];
    $s6->writeManifest($rid6, [$mk5('S')]);
    $ep6 = (int) $s6->run(730, $rid6)['lease_epoch'];

    $c6 = $s6->claim($rid6, 'w1', $ep6, 1);
    $claims6 = [(int) $c6[0]['ordinal'] => (int) $c6[0]['claim']];
    $C('a record starts with no attempts against it', (int) $c6[0]['attempts'] === 0);

    $C('a stale epoch counts no attempt',
        $s6->noteAttempts($rid6, $ep6 - 1, 'w1', $claims6, 2,
                          ScanStore::REC_UNSTORED)['counted'] === 0);
    $C('and neither does a token this worker no longer holds',
        $s6->noteAttempts($rid6, $ep6, 'w1', [(int) $c6[0]['ordinal'] => 999999], 2,
                          ScanStore::REC_UNSTORED)['counted'] === 0);

    $n6 = $s6->noteAttempts($rid6, $ep6, 'w1', $claims6, 2, ScanStore::REC_UNSTORED);
    $C('an attempt is counted for work that was attempted',
        $n6['counted'] === 1 && $n6['retired'] === 0);
    $C('and nothing is retired before the limit is reached',
        $s6->manifestComplete($rid6) === false);

    $s6->releaseClaims($rid6, $ep6, 'w1', $claims6);
    $again = $s6->claimPending($rid6, 'w1', $ep6, 1);
    $C('the released record is claimable again', count($again) === 1);
    $C('and it remembers the attempt', (int) $again[0]['attempts'] === 1);

    $n6 = $s6->noteAttempts($rid6, $ep6, 'w1',
        [(int) $again[0]['ordinal'] => (int) $again[0]['claim']], 2, ScanStore::REC_UNSTORED);
    $C('the second attempt reaches the limit and retires the record',
        $n6['counted'] === 1 && $n6['retired'] === 1);
    $st6 = $s6->recordStates($rid6);
    $C('into UNSTORED, which is not a tombstone and not unreadable',
        isset($st6[ScanStore::REC_UNSTORED]) && (int) $st6[ScanStore::REC_UNSTORED] === 1);
    // THE POINT OF ALL OF IT: the run can now end. A record that can never be
    // stored used to hold the manifest incomplete forever, the phase machine
    // correctly refused to advance over it, and the project's slot was held
    // until somebody went into the database.
    $C('so the manifest is complete and the run can finish',
        $s6->manifestComplete($rid6) === true);
    $C('and a retired record is not handed back out',
        $s6->claimPending($rid6, 'w1', $ep6, 5) === []);

    // -- A FINISHED RUN TAKES NO MORE WORK -----------------------------------
    //
    // Measured on a real server: a run retired by the stale-run sweep accepted
    // a late commit from the worker that had been abandoned, wrote its findings
    // and advanced manifest_done - so a run that had ended kept growing, and
    // its stored coverage verdict stopped describing its own counters.
    $s7 = $newStore();
    $r7 = $s7->startRun(740, []);
    $rid7 = (int) $r7['run']['run_id'];
    $s7->writeManifest($rid7, [$mk5('T'), $mk5('U')]);
    $ep7 = (int) $s7->run(740, $rid7)['lease_epoch'];
    $c7 = $s7->claim($rid7, 'w1', $ep7, 1);
    $s7->finish($rid7, ScanOutcome::derive(['failed' => true]));
    $late = $s7->commitBatch($rid7, 'w1', $ep7, ['bytes' => 0, 'findings' => [],
        'records' => [['ordinal' => $c7[0]['ordinal'], 'record_hash' => $c7[0]['hash'],
                       'state' => ScanStore::REC_DONE, 'claim' => $c7[0]['claim']]]]);
    $C('a run that has already finished refuses a late commit', $late !== true);
    $C('and says so rather than blaming the fence',
        is_string($late) && stripos($late, 'already finished') !== false);
    $C('leaving its progress where it ended',
        (int) $s7->run(740, $rid7)['manifest_done'] === 0);

    // -- WORKER SLOTS ARE NOT ASSERTED HERE ANY MORE ------------------------
    //
    // Six assertions used to sit at the end of this function, driving the
    // store's own leaseSlot()/releaseSlot(). Those methods are gone: they were
    // a second implementation of WorkerSlots over the same table, with no
    // production caller, and the read-back inside them named the wrong slot
    // whenever one owner held two. The contract never noticed because it leased
    // with three DISTINCT owners w1/w2/w3, so it could not reach the case.
    //
    // The assertions were RELOCATED, not deleted - see the slot section of
    // tests/scan_moduledb_php.php, which drives WorkerSlots against a real
    // server and adds the repeat-owner case the shape of this one excluded.
}
