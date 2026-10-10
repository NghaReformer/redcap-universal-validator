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
 * @param callable $age       (ScanStore $store, int $runId, int $seconds) => void:
 *                            make every claim on the run that much older. A dead
 *                            worker is a claim that has aged (review R2S-1), and
 *                            the contract cannot wait fifteen minutes to show one;
 *                            each store's harness knows where its clock is kept.
 */
function storeContract(callable $newStore, $label, callable $age)
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
    // ONE column list, on the interface. Both stores answer in exactly these
    // keys, in this order, so nothing a caller reads exists in only one store.
    $C('a run row is ScanStore::RUN_COLUMNS, no more, no fewer, in that order',
        array_keys($s->run(700, $runId)) === ScanStore::RUN_COLUMNS);
    $C('and startRun hands back the same shape',
        array_keys($r1['run']) === ScanStore::RUN_COLUMNS);
    $fresh = $s->run(700, $runId);
    $C('a run that has not ended has made no decision yet',
        $fresh['clean'] === null && $fresh['terminal_reason'] === null
        && (int) $fresh['gap_count'] === 0 && (int) $fresh['rule_problem_count'] === 0
        && (int) $fresh['not_examined'] === 0);
    $C('and it knows when it was created', (string) $fresh['created_at'] !== '');

    // -- manifest ----------------------------------------------------------
    // BEFORE ANY RECORD IS LISTED the predicate is vacuous, and both stores
    // answer true. Pinned because the SQL form changed from a count to an
    // existence test, and "no unfinished row" and "no row at all" are one
    // answer under either. What stops an empty run being certified is
    // ScanPromotion's emptyScope fact, not this method.
    $C('a manifest with nothing on it has nothing unfinished',
        $s->manifestComplete($runId) === true);
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
    $outcome = ScanOutcome::derive(['fenced' => true, 'manifestDone' => false, 'blocked' => true,
                                    'gaps' => 4]);
    $outcome['ruleProblems'] = 2;
    $outcome['notExamined'] = 3;
    $C('finishing succeeds once', $s->finish($runId, $outcome) === true);
    // THE RETRY CARRIES A DIFFERENT ANSWER, so "changes nothing" is a statement
    // about the row and not only about the return value. A second finaliser
    // that arrives with a cleaner outcome must not be able to launder the run.
    $rewrite = ScanOutcome::derive(['fenced' => true, 'manifestDone' => true]);
    $C('and a retried finaliser is refused', $s->finish($runId, $rewrite) === false);
    $done = $s->run(700, $runId);
    $C('the terminal state is recorded', $done['terminal'] === ScanOutcome::PARTIAL);
    $C('with the coverage it earned', $done['coverage'] === ScanOutcome::COV_PARTIAL);

    // -- the decision is ON THE ROW (H15) ------------------------------------
    // It used to live only in the array promote() returned. The run row said
    // how the run ended and nothing anywhere said what it had concluded.
    $C('a partial run is recorded as NOT clean, which is a 0 and not an absence',
        $done['clean'] !== null && (int) $done['clean'] === 0);
    $C('with the records it never examined', (int) $done['not_examined'] === 3);
    $C('the rule problems it met', (int) $done['rule_problem_count'] === 2);
    $C('the collection gaps it saw', (int) $done['gap_count'] === 4);
    $C('and why it ended as it did', $done['terminal_reason'] === $outcome['why']);
    $C('the refused retry rewrote none of it',
        $done['terminal'] !== ScanOutcome::COMPLETE && (int) $done['not_examined'] === 3);

    $sc = $newStore();
    $cid = (int) $sc->startRun(760, ['created_by' => 'alice'])['run']['run_id'];
    $C('a complete run with nothing found is recorded clean',
        $sc->finish($cid, ScanOutcome::derive(['fenced' => true, 'manifestDone' => true,
            'violations' => 0, 'ruleProblems' => 0])) === true
        && (int) $sc->run(760, $cid)['clean'] === 1
        && $sc->run(760, $cid)['clean'] !== null);
    $did = (int) $sc->startRun(761, ['created_by' => 'alice'])['run']['run_id'];
    $C('a complete run that found something is recorded not clean',
        $sc->finish($did, ScanOutcome::derive(['fenced' => true, 'manifestDone' => true,
            'violations' => 1])) === true
        && $sc->run(761, $did)['clean'] !== null && (int) $sc->run(761, $did)['clean'] === 0);
    // NULL, not 0. A cancelled run never reached the question, and a 0 would
    // read as "checked, and found something" to every report on the column.
    foreach ([762 => 'cancelled', 763 => 'failed', 764 => 'expired'] as $p => $how) {
        $xid = (int) $sc->startRun($p, ['created_by' => 'alice'])['run']['run_id'];
        $sc->finish($xid, ScanOutcome::derive([$how => true, 'fenced' => true,
            'manifestDone' => true]));
        $C("a $how run carries NO verdict rather than a negative one",
            $sc->run($p, $xid)['terminal'] === $how && $sc->run($p, $xid)['clean'] === null);
    }
    $eid = (int) $sc->startRun(765, ['created_by' => 'alice'])['run']['run_id'];
    $sc->finish($eid, ScanOutcome::derive(['emptyScope' => true]));
    $C('a run over an empty scope is not clean either',
        $sc->run(765, $eid)['clean'] !== null && (int) $sc->run(765, $eid)['clean'] === 0);
    $C('and the project slot is released, so the next scan may start',
        $s->startRun(700, ['created_by' => 'carol'])['ok'] === true);

    // -- a presence is recorded once (B4b) ------------------------------------
    // addAggregate ADDS, which is right for a count built from pages and wrong
    // for a fact. markAggregate is the other verb: the row exists with a count
    // of one, however many times and from however many workers it is said.
    $sm = $newStore();
    $mr = $sm->startRun(770, ['created_by' => 'alice']);
    $mid = (int) $mr['run']['run_id'];
    $of = function ($store, $id, $kind) {
        $rows = [];
        foreach ($store->aggregates($id) as $a) if ($a['kind'] === $kind) $rows[] = $a;
        return $rows;
    };
    $sm->markAggregate($mid, 'rule-problem', '4', 'unlocatable', 0, 'first said');
    $sm->markAggregate($mid, 'rule-problem', '4', 'unlocatable', 0, 'said again');
    $sm->markAggregate($mid, 'rule-problem', '4', 'unlocatable');
    $rows = $of($sm, $mid, 'rule-problem');
    $C('a fact marked three times is one row with a count of one',
        count($rows) === 1 && (int) $rows[0]['cnt'] === 1);
    $C('keeping the first explanation it was given', $rows[0]['samples'] === 'first said');
    $sm->markAggregate($mid, 'rule-problem', '4', 'config-error');
    $sm->markAggregate($mid, 'rule-problem', '5', 'unlocatable');
    $C('a different axis is a different fact', count($of($sm, $mid, 'rule-problem')) === 3);
    $C('a mark does not block coverage unless it says so', (int) $sm->blockingAggregates($mid) === 0);
    $sm->markAggregate($mid, 'rule-problem', '4', 'unlocatable', 1);
    $sm->markAggregate($mid, 'rule-problem', '4', 'unlocatable', 0);
    $C('blocking only ever rises, and counts the row once',
        (int) $sm->blockingAggregates($mid) === 1);
    // A row an earlier build counted up is brought BACK to a presence.
    $sm->addAggregate($mid, 'rule-problem', '9', 'legacy', 5);
    $sm->markAggregate($mid, 'rule-problem', '9', 'legacy');
    $legacy = array_values(array_filter($of($sm, $mid, 'rule-problem'),
        function ($a) { return $a['axis1'] === '9'; }));
    $C('marking a row that had been counted up resets it to one',
        count($legacy) === 1 && (int) $legacy[0]['cnt'] === 1);

    // IN A BATCH, BEHIND THE BATCH'S FENCES. A worker that was cancelled or
    // taken over adds nothing to a run that is no longer its own.
    $sm->writeManifest($mid, [['id_bin' => 'M-1', 'hash' => hash('sha256', 'M-1', true), 'dag' => null],
                              ['id_bin' => 'M-2', 'hash' => hash('sha256', 'M-2', true), 'dag' => null]]);
    $mep = (int) $sm->run(770, $mid)['lease_epoch'];
    $mc = $sm->claim($mid, 'w1', $mep, 1);
    $mark = ['kind' => 'rule-problem', 'axis1' => '12', 'axis2' => 'in-batch', 'blocks' => 0,
             'samples' => 'met while examining'];
    $rowOf = function ($c, $claim = null) {
        return ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'], 'state' => ScanStore::REC_DONE,
                'claim' => $claim === null ? $c['claim'] : $claim];
    };
    // HOW MANY RECORDS IT HELD comes back through the optional fifth
    // parameter, by reference, from both stores (review ST-4). -1 going in, so
    // a store that never set it cannot pass for one that answered 0.
    $heldN = -1;
    $C('a stale epoch refuses the batch',
        $sm->commitBatch($mid, 'w1', $mep - 1, ['bytes' => 0, 'findings' => [],
            'records' => [$rowOf($mc[0])], 'marks' => [$mark]], $heldN) !== true);
    $C('and reports that it held no record', $heldN === 0);
    $has = function () use ($sm, $mid, $of) {
        foreach ($of($sm, $mid, 'rule-problem') as $a) if ($a['axis1'] === '12') return true;
        return false;
    };
    $C('and its mark with it', $has() === false);
    // A batch that holds NONE of its rows commits - there is nothing to refuse -
    // and still has no standing to say anything about the run.
    $heldN = -1;
    $C('a batch whose every row was taken over commits nothing of its own',
        $sm->commitBatch($mid, 'w1', $mep, ['bytes' => 0, 'findings' => [],
            'records' => [$rowOf($mc[0], (int) $mc[0]['claim'] + 999)], 'marks' => [$mark]], $heldN) === true);
    $C('not even a mark', $has() === false);
    // THE ANSWER THE WORKER WAS MISSING. `true` alone read as "sent, so
    // written", and the worker stopped offering a mark the store had dropped.
    $C('and it says it held nothing, so the caller knows the mark was not written',
        $heldN === 0);
    $heldN = -1;
    $C('a batch that holds its row commits',
        $sm->commitBatch($mid, 'w1', $mep, ['bytes' => 0, 'findings' => [],
            'records' => [$rowOf($mc[0])], 'marks' => [$mark, $mark]], $heldN) === true);
    $C('and says it held that one row', $heldN === 1);
    $inBatch = array_values(array_filter($of($sm, $mid, 'rule-problem'),
        function ($a) { return $a['axis1'] === '12'; }));
    $C('and its mark lands once, with its explanation',
        count($inBatch) === 1 && (int) $inBatch[0]['cnt'] === 1
        && $inBatch[0]['samples'] === 'met while examining');
    $mc2 = $sm->claim($mid, 'w1', $mep, 1);
    $threw = false;
    try {
        $sm->commitBatch($mid, 'w1', $mep, ['bytes' => 0, 'findings' => [],
            'records' => [$rowOf($mc2[0])], 'marks' => [['kind' => 'rule-problem']]]);
    } catch (\RuntimeException $e) {
        $threw = strpos($e->getMessage(), 'presence mark') !== false;
    }
    $C('a mark with no axis is a programming error, said before anything is written',
        $threw === true && $sm->recordStates($mid) !== [ScanStore::REC_DONE => 2]);

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

    // THE SAME REFUSAL ACROSS A CHUNK BOUNDARY. The SQL store sends findings
    // 500 to a statement, so a batch of 600 is two INSERTs - and by the time the
    // second one collides, the first has already succeeded. "Refused entire"
    // then depends on the transaction, not on the statement, and that is the
    // half a chunked writer can get wrong: keep the first chunk, report the
    // refusal, and leave 500 findings behind for a record that was never marked
    // examined.
    $s8b = $newStore();
    $r8b = $s8b->startRun(751, []);
    $rid8b = (int) $r8b['run']['run_id'];
    $s8b->writeManifest($rid8b, [$mk5('V')]);
    $ep8b = (int) $s8b->run(751, $rid8b)['lease_epoch'];
    $gen8b = (int) $s8b->run(751, $rid8b)['generation_id'];
    $c8b = $s8b->claim($rid8b, 'w1', $ep8b, 1);
    $wide = ['bytes' => 1, 'findings' => [], 'records' => [[
        'ordinal' => $c8b[0]['ordinal'], 'record_hash' => $c8b[0]['hash'],
        'state' => ScanStore::REC_DONE, 'claim' => $c8b[0]['claim']]]];
    for ($i = 0; $i < 600; $i++) {
        // First and LAST share an identity; the 598 between them are distinct.
        $ident = hash('sha256', 'wide-' . ($i === 599 ? 0 : $i), true);
        $wide['findings'][] = ['ordinal' => $c8b[0]['ordinal'], 'project_id' => 751,
            'generation_id' => $gen8b, 'host_form' => 'fa', 'field' => 'x' . $i,
            'check_type' => 'required', 'reason_code' => 'required-blank',
            'record_id_bin' => 'V', 'identity' => $ident,
            'record_hash' => hash('sha256', 'V', true),
            'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64)];
    }
    $no = $s8b->commitBatch($rid8b, 'w1', $ep8b, $wide);
    $C('a repeat 599 findings apart is refused like one side by side',
        is_string($no) && strpos($no, 'database refused') !== false);
    $C('and none of the 599 good findings ahead of it was kept',
        $s8b->findings(751, $gen8b, [], 0, 100) === []
        && (int) $s8b->run(751, $rid8b)['detail_rows'] === 0);
    $C('nor the record marked examined', $s8b->manifestComplete($rid8b) === false);
    // THE CONTROL. Without it the three lines above pass for a store that
    // refuses every large batch.
    $wide['findings'][599]['identity'] = hash('sha256', 'wide-599', true);
    $C('while the same 600 with nothing repeated commit',
        $s8b->commitBatch($rid8b, 'w1', $ep8b, $wide) === true
        && (int) $s8b->run(751, $rid8b)['detail_rows'] === 600);

    // -- A FINDING MISSING A REQUIRED VALUE IS REFUSED, BY BOTH STORES (ST-2) --
    //
    // The SQL store sends findings as multi-row INSERTs, and a lenient
    // sql_mode stores '' for a NULL in a NOT NULL column there instead of
    // refusing it. So both stores refuse the batch themselves, before anything
    // is written, for every key ScanStore::FINDING_REQUIRED names.
    $s9 = $newStore();
    $r9 = $s9->startRun(752, []);
    $rid9 = (int) $r9['run']['run_id'];
    $s9->writeManifest($rid9, [$mk5('X')]);
    $ep9 = (int) $s9->run(752, $rid9)['lease_epoch'];
    $gen9 = (int) $s9->run(752, $rid9)['generation_id'];
    $c9 = $s9->claim($rid9, 'w1', $ep9, 1);
    $whole9 = function ($i) use ($c9, $gen9) {
        return ['ordinal' => $c9[0]['ordinal'], 'project_id' => 752, 'generation_id' => $gen9,
                'host_form' => 'fa', 'field' => 'x' . $i, 'check_type' => 'required',
                'reason_code' => 'required-blank', 'record_id_bin' => 'X',
                'identity' => hash('sha256', 'req-' . $i, true),
                'record_hash' => hash('sha256', 'X', true),
                'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64)];
    };
    $batch9 = function (array $findings) use ($c9) {
        return ['bytes' => 0, 'findings' => $findings, 'records' => [[
            'ordinal' => $c9[0]['ordinal'], 'record_hash' => $c9[0]['hash'],
            'state' => ScanStore::REC_DONE, 'claim' => $c9[0]['claim']]]];
    };
    $refusedEach = true;
    $named = true;
    $heldAfterRefusal = true;
    foreach (ScanStore::FINDING_REQUIRED as $k) {
        // The SECOND of two findings, so the SQL store would have sent both
        // rows in one statement - the shape the lenient server mishandles.
        $broken = $whole9(2);
        $broken[$k] = null;
        $h9 = -1;
        $no = $s9->commitBatch($rid9, 'w1', $ep9, $batch9([$whole9(1), $broken]), $h9);
        // The record WAS held when the insert failed; the rollback undid it.
        if ($h9 !== 0) $heldAfterRefusal = false;
        if (!is_string($no) || strpos($no, 'database refused') === false) $refusedEach = false;
        if (!is_string($no) || strpos($no, 'no ' . $k . ';') === false) $named = false;
    }
    $C('a finding with any required value missing refuses the whole batch', $refusedEach);
    $C('and the refusal names the value that was missing', $named);
    $C('and reports no record held, though the batch held one until it was refused',
        $heldAfterRefusal);
    $C('leaving the record unexamined and nothing stored',
        $s9->manifestComplete($rid9) === false
        && (int) $s9->run(752, $rid9)['detail_rows'] === 0
        && $s9->findings(752, $gen9, [], 0, 100) === []);
    // THE CONTROL: the same two findings, whole, commit.
    $C('while the same batch with every value present commits',
        $s9->commitBatch($rid9, 'w1', $ep9, $batch9([$whole9(1), $whole9(2)])) === true
        && (int) $s9->run(752, $rid9)['detail_rows'] === 2);

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

    // -- A ROW A DEAD WORKER LEFT CLAIMED ------------------------------------
    //
    // A request killed by memory_limit or max_execution_time inside the read or
    // the evaluation runs nothing after the fatal: no commit, no give-back, no
    // attempt counted. A record that kills PHP every time it is evaluated was
    // therefore taken over and retried for ever. A row that is free and still
    // carries a claim token is that dead worker's, because every way a live
    // worker lets go of a row clears the token - once the claim is as old as a
    // claimed row must be before it is free at all (review R2S-1, below).
    $s8 = $newStore();
    $rid8 = (int) $s8->startRun(735, [])['run']['run_id'];
    $s8->writeManifest($rid8, [$mk5('V'), $mk5('W'), $mk5('X')]);
    $ep8 = (int) $s8->run(735, $rid8)['lease_epoch'];
    $C('dead worker: the fixture claims two rows and then runs nothing',
        count($s8->claim($rid8, 'w1', $ep8, 2)) === 2);
    $age($s8, $rid8, 1000);
    $t8 = $s8->claimPending($rid8, 'w2', $ep8, 10, 900, 3);
    $C('dead worker: a takeover hands out an abandoned row ALONE, whatever the limit',
        is_array($t8) && count($t8) === 1 && $t8[0]['abandoned'] === true);
    $C('dead worker: charged the attempt the dead worker never counted',
        (int) $t8[0]['attempts'] === 1);
    // The second takeover dies too, holding its row fresh. The next abandoned
    // row also goes alone, and only then does the untouched one come out.
    $t8b = $s8->claimPending($rid8, 'w3', $ep8, 10, 900, 3);
    $C('dead worker: the next abandoned row goes alone as well',
        is_array($t8b) && count($t8b) === 1 && $t8b[0]['abandoned'] === true
        && $t8b[0]['ordinal'] !== $t8[0]['ordinal']);
    $t8c = $s8->claimPending($rid8, 'w4', $ep8, 10, 900, 3);
    $C('dead worker: a row nobody claimed is not abandoned, and is not charged',
        is_array($t8c) && count($t8c) === 1 && $t8c[0]['abandoned'] === false
        && (int) $t8c[0]['attempts'] === 0);

    // AT THE LIMIT the row is retired, as any record out of attempts is:
    // terminal, so the run can finish, and blocking, so it cannot finish clean.
    $s9 = $newStore();
    $rid9 = (int) $s9->startRun(736, [])['run']['run_id'];
    $s9->writeManifest($rid9, [$mk5('Y')]);
    $ep9 = (int) $s9->run(736, $rid9)['lease_epoch'];
    $s9->claim($rid9, 'w1', $ep9, 1);
    $age($s9, $rid9, 1000);
    $ret9 = -1;
    $C('dead worker: at the limit the abandoned row is retired, not handed out',
        $s9->claimPending($rid9, 'w2', $ep9, 10, 900, 1, ScanStore::REC_UNREADABLE, $ret9) === []);
    $C('dead worker: and the caller is told how many rows the call retired',
        $ret9 === 1);
    $st9 = $s9->recordStates($rid9);
    $C('dead worker: retired as UNREADABLE, with the attempt counted',
        isset($st9[ScanStore::REC_UNREADABLE]) && (int) $st9[ScanStore::REC_UNREADABLE] === 1);
    $C('dead worker: so the manifest is complete and the run can end',
        $s9->manifestComplete($rid9) === true);

    // A LIVE WORKER IS NOT A DEAD ONE (review R2S-1). claim() stamps the rows it
    // hands out in scanning and leaves them PENDING, and a PENDING row is free
    // at any age - so "free and still stamped" also describes a worker that is
    // alive and still evaluating its last scanning batch. A second driver that
    // reached catch-up (a second tab, a reload) charged those rows, and at an
    // attempt limit of 1 retired every one of them as unreadable before anybody
    // had examined it. A fresh stamp is taken over UNCHARGED, all of it at once.
    $s12 = $newStore();
    $rid12 = (int) $s12->startRun(739, [])['run']['run_id'];
    $s12->writeManifest($rid12, [$mk5('L1'), $mk5('L2'), $mk5('L3')]);
    $ep12 = (int) $s12->run(739, $rid12)['lease_epoch'];
    $s12->claim($rid12, 'w1', $ep12, 3);                 // w1 is alive, evaluating
    $ret12 = -1;
    $t12 = $s12->claimPending($rid12, 'w2', $ep12, 10, 900, 1, ScanStore::REC_UNREADABLE, $ret12);
    $C('live worker: a fresh scanning stamp is not abandoned, so nothing is retired',
        $ret12 === 0 && is_array($t12) && count($t12) === 3);
    $C('live worker: and nothing is charged - each row is taken over as it stood',
        is_array($t12) && count($t12) === 3 && $t12[0]['abandoned'] === false
        && (int) $t12[0]['attempts'] === 0 && (int) $t12[2]['attempts'] === 0);
    $C('live worker: no record became an exclusion nobody examined',
        !isset($s12->recordStates($rid12)[ScanStore::REC_UNREADABLE]));

    // A RELEASED row is not abandoned, and a caller that gives no attempt
    // limit keeps the old behaviour exactly.
    $s10 = $newStore();
    $rid10 = (int) $s10->startRun(737, [])['run']['run_id'];
    $s10->writeManifest($rid10, [$mk5('Z1'), $mk5('Z2')]);
    $ep10 = (int) $s10->run(737, $rid10)['lease_epoch'];
    $c10 = $s10->claim($rid10, 'w1', $ep10, 1);
    $s10->releaseClaims($rid10, $ep10, 'w1', [(int) $c10[0]['ordinal'] => (int) $c10[0]['claim']]);
    $t10 = $s10->claimPending($rid10, 'w2', $ep10, 10, 900, 3);
    $C('dead worker: a row its worker released is not abandoned, and goes out with the rest',
        is_array($t10) && count($t10) === 2 && $t10[0]['abandoned'] === false
        && $t10[1]['abandoned'] === false && (int) $t10[0]['attempts'] === 0);
    $s11 = $newStore();
    $rid11 = (int) $s11->startRun(738, [])['run']['run_id'];
    $s11->writeManifest($rid11, [$mk5('Q1'), $mk5('Q2')]);
    $ep11 = (int) $s11->run(738, $rid11)['lease_epoch'];
    $s11->claim($rid11, 'w1', $ep11, 2);
    $t11 = $s11->claimPending($rid11, 'w2', $ep11, 10);
    $C('dead worker: with no attempt limit a takeover is as it was - every row, no charge',
        is_array($t11) && count($t11) === 2 && (int) $t11[0]['attempts'] === 0
        && (int) $t11[1]['attempts'] === 0);

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

    // -- A SUMMARY PAGE IS HELD, AND THE CURSOR READ UNDER THE HOLD (OC-5) ----
    //
    // RollupBuilder compares this answer with the cursor its page started from.
    // Both stores answer the cursor as an int and refuse in the same three
    // sentences, so a worker stops for the same reason whichever store it has.
    $s10 = $newStore();
    $r10 = $s10->startRun(753, []);
    $rid10 = (int) $r10['run']['run_id'];
    $s10->writeManifest($rid10, [$mk5('V')]);
    $ep10 = (int) $s10->run(753, $rid10)['lease_epoch'];
    $C('a fresh run holds its summary cursor at 0', $s10->holdRollupCursor($rid10, $ep10) === 0);
    $s10->setProgressState($rid10, $ep10, ['rollupCursor' => 7]);
    $C('and answers the cursor a page moved it to',
        $s10->holdRollupCursor($rid10, $ep10) === 7);
    $C('a different lease epoch may not add a page',
        $s10->holdRollupCursor($rid10, $ep10 + 1) === ScanStore::ROLLUP_TAKEN_OVER);
    $C('nor may a run that does not exist',
        $s10->holdRollupCursor($rid10 + 999, $ep10) === ScanStore::ROLLUP_NO_RUN);
    $s10->finish($rid10, ScanOutcome::derive(['failed' => true]));
    $C('nor a run that has finished, on the epoch it finished with',
        $s10->holdRollupCursor($rid10, $ep10) === ScanStore::ROLLUP_FINISHED);
    $s11 = $newStore();
    $rid11 = (int) $s11->startRun(754, [])['run']['run_id'];
    $s11->writeManifest($rid11, [$mk5('W')]);
    $ep11 = (int) $s11->run(754, $rid11)['lease_epoch'];
    $s11->cancel(754, $rid11, 'admin');
    $C('and a cancel takes the summary away from the worker that held it',
        $s11->holdRollupCursor($rid11, $ep11) === ScanStore::ROLLUP_TAKEN_OVER);

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
