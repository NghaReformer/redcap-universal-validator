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
 * @param callable|null $clocked (callable $clock) => ScanStore, fresh and empty,
 *                            reading elapsed time from $clock: how long one
 *                            straggler sweep may hold the run row is a matter of
 *                            seconds (review R3S-1), and the contract steps a
 *                            clock rather than waiting. Null skips those cases.
 * @param callable|null $dropPlan (ScanStore $store, int $runId) => void: delete
 *                            the run's plan row, which nothing in the module
 *                            does while a run plans (review R5-9). Null skips
 *                            that case.
 * @param callable|null $setRun (ScanStore $store, int $runId, array $cols) => void:
 *                            write run columns as stored, standing in for the
 *                            hours between two writes that the reaper's clocks
 *                            measure (review rv10a1-1). Null skips those cases.
 * @param callable|null $audits (ScanStore $store, int $runId, string $event) => int:
 *                            how many audit rows of that event the run has, which
 *                            the interface cannot read back (review rvfix-N3).
 *                            Null skips those cases.
 */
function storeContract(callable $newStore, $label, callable $age, ?callable $clocked = null,
                       ?callable $dropPlan = null, ?callable $setRun = null, ?callable $audits = null)
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
    // WHAT THE RUN KEEPS IS WHAT THE PLANNER SAID (W11A-N1). The SQL store
    // writes values_state from the start request; the array store dropped it
    // and every run it held read 'none', so a stored report built over it
    // never showed a kept value whatever the policy.
    $kept = $s->startRun(702, ['created_by' => 'alice', 'values_state' => 'raw']);
    $C('a run keeps the value mode it was started with',
        $kept['ok'] === true && $kept['run']['values_state'] === 'raw'
        && $s->run(702, (int) $kept['run']['run_id'])['values_state'] === 'raw');
    $plain = $s->startRun(703, []);
    $C('and a start that names none keeps none',
        $plain['ok'] === true && $s->run(703, (int) $plain['run']['run_id'])['values_state'] === 'none');

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
    $hR = function ($id) { return hash('sha256', $id, true); };
    $seen = $s->scannedVersions($runId, [$hR('REC-1'), $hR('REC-2')]);
    $C('and each record remembers the version it was examined at',
        isset($seen[$hR('REC-1')]['version']) && $seen[$hR('REC-1')]['version'] === '700');
    $C('with its terminal state beside it',
        (int) $seen[$hR('REC-1')]['state'] === ScanStore::REC_DONE);
    $C('a record nobody has reached yet reports no version',
        $s->scannedVersions($runId, [$hR('REC-5')])[$hR('REC-5')]['version'] === null);
    // W5F-N3. Asked by RECORD HASH, which is what uq_run_record (run_id,
    // record_hash) finds a row by; the record id has no index, and naming a
    // record by it finds nothing in either store.
    $C('W5F-N3: the versions are asked for and answered by record hash, and a record id names nothing',
        array_keys($seen) === [$hR('REC-1'), $hR('REC-2')]
        && $s->scannedVersions($runId, ['REC-1', 'REC-2']) === []);

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
        $s->requeue($runId, $epoch, [$hR('REC-1')]) === 0);

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
    // A RUN ALREADY STOPPING IS NOT STOPPED AGAIN (review rvfix-N3). A
    // disable reads the run and then cancels it as the system's; a user's
    // Stop between the two was audited twice and lost its sentence.
    $C('rvfix-N3: a second stop of a run already stopping is refused',
        $s->cancel(700, $runId, 'system') === false);
    $C('rvfix-N3: and writes nothing: the epoch stays where the first stop put it',
        (int) $s->run(700, $runId)['lease_epoch'] === $epoch + 1
        && $s->run(700, $runId)['phase'] === ScanPhase::CANCELLING);
    if ($audits !== null) {
        $C('rvfix-N3: and the stop is audited once, as its first asker\'s',
            $audits($s, $runId, 'cancel') === 1);
    }
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
    // EVERY APPEND IS A HEARTBEAT (review R3U-2): a planner appending pages
    // looked, to the page and to the reaper, exactly like one that had died.
    $C('an append moves the progress clock while the run is still planning',
        $s3->run(710, $rid3)['progress_at'] !== null
        && $s3->run(710, $rid3)['phase'] === 'planning');

    // THE FREEZE WAITS FOR THE SOURCE TO SAY DONE (B10). A walk that returned
    // because its request ran out of time has listed part of the project.
    $C('a freeze before the walk reached the end of its source is refused',
        $s3->freezeManifest($rid3) === false && $s3->run(710, $rid3)['phase'] === 'planning');
    $ep3p = (int) $s3->run(710, $rid3)['lease_epoch'];
    $cl3 = $s3->claimPlan($rid3, 'p1', $ep3p, 300, 3);
    $C('the planner takes the lease', is_array($cl3) && $cl3['state'] === 'ok');
    $C('an empty last page is still a page: zero added, not refused',
        $s3->appendManifest($rid3, [], ['owner' => 'p1', 'epoch' => $ep3p, 'cursor' => 'D',
            'carry' => null, 'listed' => 4, 'outOfScope' => 0, 'done' => true]) === 0);

    $total = $s3->freezeManifest($rid3);
    $C('freezing publishes the COUNT of what is there', $total === 4);
    $C('and the published total is what the run reports',
        (int) $s3->run(710, $rid3)['manifest_total'] === 4);
    $C('freezing moves the run to scanning', $s3->run(710, $rid3)['phase'] === 'scanning');
    // A manifest that could still grow after work started would let a run
    // redefine what "all" means halfway through. FALSE, not 0: zero is "nothing
    // new", and a planner must be able to tell that from "stop".
    $C('appending after the freeze is refused', $s3->appendManifest($rid3, [$mk('E')]) === false);
    $C('and so is claiming the planning lease', $s3->claimPlan($rid3, 'p1', $ep3p, 300, 3) === false);
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
        $s8b->findings(751, $gen8b, ['dag' => null], [], 0, 100) === []
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
        && $s9->findings(752, $gen9, ['dag' => null], [], 0, 100) === []);
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

    // A WINDOW THAT WAS ALL RETIRED IS NOT THE END (review R2S-5). The sweep
    // reads free rows a window of $limit at a time. Retired rows used to take
    // their places in the SQL store's window, so a window that was all dead
    // rows answered [] while free rows waited behind it, and the worker read
    // that as "records are still out with another worker". Five dead rows at
    // the limit, windows of two: two windows are all retired, the third
    // retires one and hands out the first live row - which in a window is the
    // unit both stores answer in.
    // A STILL CLOCK for the two cases that count windows: under load a real
    // clock ends the call on SWEEP_SECONDS first, and these assert the window
    // bound, not the time one (that is the clocked case further down).
    $still = function () use ($newStore, $clocked) {
        return $clocked === null ? $newStore() : $clocked(function () { return 0.0; });
    };
    $s13 = $still();
    $rid13 = (int) $s13->startRun(741, [])['run']['run_id'];
    $recs13 = [];
    for ($i = 1; $i <= 7; $i++) $recs13[] = $mk5('N' . $i);
    $s13->writeManifest($rid13, $recs13);
    $ep13 = (int) $s13->run(741, $rid13)['lease_epoch'];
    $s13->claim($rid13, 'dead', $ep13, 5);              // five rows, and then nothing ran
    $age($s13, $rid13, 1000);
    $ret13 = -1;
    $t13 = $s13->claimPending($rid13, 'w2', $ep13, 2, 900, 1, ScanStore::REC_UNREADABLE, $ret13);
    $C('sweep: a window whose every row was retired is followed by the next one',
        is_array($t13) && count($t13) === 1 && $t13[0]['id_bin'] === 'N6');
    $C('sweep: and every dead row in front of it was retired, and counted',
        $ret13 === 5 && (int) $s13->recordStates($rid13)[ScanStore::REC_UNREADABLE] === 5);

    // AND THE WALK IS BOUNDED, the same in both stores, so one call holds the
    // run row's lock for a bounded number of statements. Past the bound the
    // call answers [] with $retired > 0, which a caller must read as "ask
    // again" - and asking again carries on from where it stopped.
    $s14 = $still();
    $rid14 = (int) $s14->startRun(742, [])['run']['run_id'];
    $dead14 = ScanStore::SWEEP_WINDOWS + 2;
    $recs14 = [];
    for ($i = 1; $i <= $dead14 + 1; $i++) $recs14[] = $mk5('B' . $i);
    $s14->writeManifest($rid14, $recs14);
    $ep14 = (int) $s14->run(742, $rid14)['lease_epoch'];
    $s14->claim($rid14, 'dead', $ep14, $dead14);
    $age($s14, $rid14, 1000);
    $ret14 = -1;
    $t14 = $s14->claimPending($rid14, 'w2', $ep14, 1, 900, 1, ScanStore::REC_UNREADABLE, $ret14);
    $C('sweep: one call retires at most SWEEP_WINDOWS windows, and says how many',
        $t14 === [] && $ret14 === ScanStore::SWEEP_WINDOWS);
    $t14b = $s14->claimPending($rid14, 'w2', $ep14, 1, 900, 1, ScanStore::REC_UNREADABLE, $ret14);
    $C('sweep: and the next call carries on to the live row behind them',
        is_array($t14b) && count($t14b) === 1 && $t14b[0]['id_bin'] === 'B' . ($dead14 + 1)
        && $ret14 === 2);

    // AND WITHIN A TIME (review R3S-1). Sixteen windows is a count of
    // statements, not of seconds: each window used to walk every finished row
    // in front of the free ones, and sixteen of them held the run row for 13 s
    // at 500,000 records. A later window starts only while the call is younger
    // than SWEEP_SECONDS. The clock here moves 0.6 of that per reading, so the
    // first window runs, the second starts at 0.6 and the third would start at
    // 1.2: two windows of five dead rows are retired, and the call answers []
    // for the caller to ask again - which carries on from there.
    if ($clocked !== null) {
        $tick = 0.0;
        $s16 = $clocked(function () use (&$tick) {
            $now = $tick;
            $tick += 0.6 * ScanStore::SWEEP_SECONDS;
            return $now;
        });
        $rid16 = (int) $s16->startRun(744, [])['run']['run_id'];
        $recs16 = [];
        for ($i = 1; $i <= 6; $i++) $recs16[] = $mk5('K' . $i);
        $s16->writeManifest($rid16, $recs16);
        $ep16 = (int) $s16->run(744, $rid16)['lease_epoch'];
        $s16->claim($rid16, 'dead', $ep16, 5);
        $age($s16, $rid16, 1000);
        $ret16 = -1;
        $t16 = $s16->claimPending($rid16, 'w2', $ep16, 1, 900, 1, ScanStore::REC_UNREADABLE, $ret16);
        $C('sweep: a later window starts only while the call is younger than SWEEP_SECONDS',
            $t16 === [] && $ret16 === 2);
        $tick = 0.0;
        $t16b = $s16->claimPending($rid16, 'w2', $ep16, 1, 900, 1, ScanStore::REC_UNREADABLE, $ret16);
        $tick = 0.0;
        $t16c = $s16->claimPending($rid16, 'w2', $ep16, 1, 900, 1, ScanStore::REC_UNREADABLE, $ret16);
        $C('sweep: and asking again carries on to the live row behind them',
            $t16b === [] && is_array($t16c) && count($t16c) === 1 && $t16c[0]['id_bin'] === 'K6'
            && $ret16 === 1);
    }

    // -- THE ATTEMPT AND THE HAND-BACK ARE ONE WRITE (review R2S-4) ----------
    //
    // noteAttempts() counted, and the caller's releaseClaims() handed back, in
    // two transactions. When the second failed - or a takeover landed between
    // them - the row kept its token on an attempt already counted, aged into
    // "abandoned", and the straggler sweep charged the same failure again.
    // Nothing here calls releaseClaims(): the call that could fail is gone.
    $s15 = $newStore();
    $rid15 = (int) $s15->startRun(743, [])['run']['run_id'];
    $s15->writeManifest($rid15, [$mk5('G1'), $mk5('G2')]);
    $ep15 = (int) $s15->run(743, $rid15)['lease_epoch'];
    $c15 = $s15->claim($rid15, 'w1', $ep15, 1);
    $n15 = $s15->noteAttempts($rid15, $ep15, 'w1', [(int) $c15[0]['ordinal'] => (int) $c15[0]['claim']],
                              3, ScanStore::REC_UNSTORED);
    $C('give-back: an attempt counted short of the limit is handed back by the same call',
        $n15['counted'] === 1 && $n15['retired'] === 0
        && isset($n15['released']) && $n15['released'] === 1);
    $age($s15, $rid15, 1000);
    $t15 = $s15->claimPending($rid15, 'w2', $ep15, 10, 900, 3);
    $C('give-back: so it is never taken for a dead worker\'s row and charged a second time',
        is_array($t15) && count($t15) === 2 && $t15[0]['id_bin'] === 'G1'
        && $t15[0]['abandoned'] === false && (int) $t15[0]['attempts'] === 1);

    // A straggler (CLAIMED) goes back to PENDING, so another worker can take
    // it at once rather than when its claim has aged.
    $s16 = $newStore();
    $rid16 = (int) $s16->startRun(744, [])['run']['run_id'];
    $s16->writeManifest($rid16, [$mk5('H1'), $mk5('H2')]);
    $ep16 = (int) $s16->run(744, $rid16)['lease_epoch'];
    $s16->advancePhase($rid16, $ep16, ScanPhase::CATCH_UP);
    $c16 = $s16->claimPending($rid16, 'w1', $ep16, 1, 900, 3);
    $s16->noteAttempts($rid16, $ep16, 'w1', [(int) $c16[0]['ordinal'] => (int) $c16[0]['claim']],
                       3, ScanStore::REC_UNREADABLE);
    $t16 = $s16->claimPending($rid16, 'w2', $ep16, 10, 900, 3);
    $C('give-back: a straggler handed back is claimable at once, its attempt remembered',
        is_array($t16) && count($t16) === 2 && $t16[0]['id_bin'] === 'H1'
        && (int) $t16[0]['attempts'] === 1);

    // And in scanning the cursor moves back over it, as releaseClaims() moves
    // it, so the next claim() offers it again rather than leaving it to the
    // straggler sweep.
    $s17 = $newStore();
    $rid17 = (int) $s17->startRun(745, [])['run']['run_id'];
    $s17->writeManifest($rid17, [$mk5('J1'), $mk5('J2')]);
    $ep17 = (int) $s17->run(745, $rid17)['lease_epoch'];
    $c17 = $s17->claim($rid17, 'w1', $ep17, 1);
    $s17->noteAttempts($rid17, $ep17, 'w1', [(int) $c17[0]['ordinal'] => (int) $c17[0]['claim']],
                       3, ScanStore::REC_UNREADABLE);
    $t17 = $s17->claim($rid17, 'w2', $ep17, 1);
    $C('give-back: in scanning the next claim offers the handed-back record again',
        is_array($t17) && count($t17) === 1 && $t17[0]['id_bin'] === 'J1'
        && (int) $t17[0]['attempts'] === 1);

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

    // -- A FINISHING PHASE WRITES ONLY FOR A RUN IT STILL HOLDS (rv10a1-1) -----
    //
    // UniqueFinalizer asks this as the first statement of each emit and publish
    // transaction. A request mid-finalize when its run was expired kept
    // writing duplicates into the expired run; both stores refuse it now, in
    // the same four sentences and the same order.
    $s12 = $newStore();
    $rid12 = (int) $s12->startRun(755, [])['run']['run_id'];
    $s12->writeManifest($rid12, [$mk5('X')]);
    $ep12 = (int) $s12->run(755, $rid12)['lease_epoch'];
    foreach ([ScanPhase::SCANNING, ScanPhase::CATCH_UP, ScanPhase::UNIQUE] as $to12) {
        $s12->advancePhase($rid12, $ep12, $to12);
    }
    $C('rv10a1-1: a live run in unique-finalize may be written for, on its own epoch',
        $s12->holdRun($rid12, $ep12, ScanPhase::UNIQUE) === true);
    $C('rv10a1-1: the epoch after a cancel or a reap may not write for it',
        $s12->holdRun($rid12, $ep12 + 1, ScanPhase::UNIQUE) === ScanStore::HOLD_TAKEN_OVER);
    $C('rv10a1-1: nor may a run that does not exist',
        $s12->holdRun($rid12 + 999, $ep12, ScanPhase::UNIQUE) === ScanStore::HOLD_NO_RUN);
    $C('rv10a1-1: nor a write for a phase the run has left',
        $s12->holdRun($rid12, $ep12, ScanPhase::ROLLUP) === ScanStore::HOLD_MOVED);
    $s12->finish($rid12, ScanOutcome::derive(['failed' => true]));
    $C('rv10a1-1: nor a run that has ended, on the epoch it ended with',
        $s12->holdRun($rid12, $ep12, ScanPhase::UNIQUE) === ScanStore::HOLD_FINISHED);
    $s13 = $newStore();
    $rid13 = (int) $s13->startRun(756, [])['run']['run_id'];
    $s13->writeManifest($rid13, [$mk5('Y')]);
    $ep13 = (int) $s13->run(756, $rid13)['lease_epoch'];
    $s13->cancel(756, $rid13, 'admin');
    $C('rv10a1-1: and a cancel takes the run away from the worker that was writing for it',
        $s13->holdRun($rid13, $ep13, $s13->run(756, $rid13)['phase']) === ScanStore::HOLD_TAKEN_OVER);

    // -- A RUN THAT LEFT CATCH-UP SAYS SO, THROUGH ITS END (review rvf1-1) -----
    //
    // Stop and every ending overwrite the phase, so the row must keep the one
    // fact a group-scoped run's counts hang on: it walked its window to the
    // target fence. Written by the move out of catch-up and by nothing else.
    $sl = $newStore();
    $rl = (int) $sl->startRun(757, [])['run']['run_id'];
    $sl->writeManifest($rl, [$mk5('C1')]);
    $epL = (int) $sl->run(757, $rl)['lease_epoch'];
    $sl->advancePhase($rl, $epL, ScanPhase::SCANNING);
    $sl->advancePhase($rl, $epL, ScanPhase::CATCH_UP);
    $C('rvf1-1: a run in catch-up has not left it',
        (int) $sl->run(757, $rl)['catchup_dirty'] !== ScanStore::CAUGHT_UP);
    $sl->advancePhase($rl, $epL, ScanPhase::UNIQUE);
    $C('rvf1-1: the move out of catch-up writes that the run left it',
        (int) $sl->run(757, $rl)['catchup_dirty'] === ScanStore::CAUGHT_UP);
    $sl->advancePhase($rl, $epL, ScanPhase::ROLLUP);
    $sl->cancel(757, $rl, 'alice');
    $C('rvf1-1: and the row still says so once it is stopped',
        (int) $sl->run(757, $rl)['catchup_dirty'] === ScanStore::CAUGHT_UP);
    $sl->finish($rl, ScanOutcome::derive(['cancelled' => true]));
    $C('rvf1-1: and once it has ended',
        $sl->run(757, $rl)['phase'] === ScanPhase::TERMINAL
        && (int) $sl->run(757, $rl)['catchup_dirty'] === ScanStore::CAUGHT_UP);
    $rk = (int) $sl->startRun(758, [])['run']['run_id'];
    $sl->writeManifest($rk, [$mk5('C2')]);
    $epK = (int) $sl->run(758, $rk)['lease_epoch'];
    $sl->advancePhase($rk, $epK, ScanPhase::SCANNING);
    $sl->advancePhase($rk, $epK, ScanPhase::CATCH_UP);
    $sl->cancel(758, $rk, 'alice');
    $sl->finish($rk, ScanOutcome::derive(['cancelled' => true]));
    $C('rvf1-1: a run stopped inside catch-up never says it left it',
        (int) $sl->run(758, $rk)['catchup_dirty'] !== ScanStore::CAUGHT_UP);

    // -- A RUN NOBODY CAN FIND IS NOT COMPLETE (W6-N1) ------------------------
    //
    // The SQL store answered true here - no unfinished row, because no row at
    // all - and the in-memory store false, and this file used to pin the
    // disagreement rather than choose. Both fail closed now.
    $C('a run that does not exist is not complete', $s->manifestComplete(987654321) === false);

    // -- ONE ROW SHAPE, AND A CURSOR THAT NEVER REPEATS A ROW (B5-N1, M13) ----
    //
    // findings() is what the report reads. The SQL store answered twelve
    // positional columns and the in-memory store its whole internal row, paged
    // by a count of rows walked; neither was what FindingRowMapper takes.
    $scan = function ($store, $pid, array $findings, $how, $scope = null) use ($mk5) {
        $r = $store->startRun($pid, ['created_by' => 'alice', 'scope_dag' => $scope]);
        $rid = (int) $r['run']['run_id'];
        $gen = (int) $r['run']['generation_id'];
        $recs = [];
        foreach (array_keys($findings) as $i) $recs[] = $mk5('F' . $pid . '-' . $gen . '-' . $i);
        $store->writeManifest($rid, $recs);
        $ep = (int) $store->run($pid, $rid)['lease_epoch'];
        $c = $store->claim($rid, 'w1', $ep, count($recs));
        $batch = ['bytes' => 0, 'records' => [], 'findings' => []];
        foreach ($c as $j => $row) {
            $batch['records'][] = ['ordinal' => $row['ordinal'], 'record_hash' => $row['hash'],
                                   'state' => ScanStore::REC_DONE, 'claim' => $row['claim']];
            foreach ($findings[$j] as $k => $over) {
                $batch['findings'][] = array_merge([
                    'ordinal' => $row['ordinal'], 'project_id' => $pid, 'generation_id' => $gen,
                    'host_form' => 'fa', 'field' => 'x' . $k, 'check_type' => 'required',
                    'reason_code' => 'required-blank', 'record_id_bin' => $row['id_bin'],
                    'identity' => hash('sha256', $pid . '|' . $gen . '|' . $j . '|' . $k, true),
                    'record_hash' => $row['hash'], 'rule_source_id' => 'r1',
                    'rule_revision' => str_repeat('c', 64)], $over);
            }
        }
        $ok = $store->commitBatch($rid, 'w1', $ep, $batch);
        if ($how !== null) {
            $store->finish($rid, $how === 'complete'
                ? ScanOutcome::derive(['fenced' => true, 'manifestDone' => true, 'violations' => 1])
                : ScanOutcome::derive([$how => true, 'fenced' => true, 'manifestDone' => true]));
        }
        return ['run' => $rid, 'gen' => $gen, 'ok' => $ok];
    };
    // Every value the drivers may hand back as a number or a string, as a string.
    $flat = function (array $row) {
        $out = [];
        foreach ($row as $k => $v) $out[$k] = $v === null ? null : (string) $v;
        return $out;
    };

    $sf = $newStore();
    $nbF = $scan($sf, 791, [[[]]], 'complete');
    $one = $scan($sf, 790, [
        [['host_form' => 'visit', 'dag_key' => 'north', 'event_id' => 5, 'instance' => 2,
          'rule_ord' => 3, 'reason_bits' => 6, 'value_bin' => 'v-1', 'value_len' => 3,
          'value_truncated' => 0, 'value_expires_at' => '2031-01-01 00:00:00'],
         ['dag_key' => 'south']],
        [['dag_key' => 'north'],
         // A STAGED duplicate: the finalizer's row, invisible to every report
         // until its group is published. The SQL store writes it with
         // active_slot NULL; the in-memory store used to write it active.
         ['stage_epoch' => 3, 'group_hmac' => hash('sha256', 'grp', true)]],
        [[]],
    ], null);
    $C('rows: the batch the shape is read from commits', $one['ok'] === true);
    $all = $sf->findings(790, $one['gen'], ['dag' => null], [], 0, 100);
    $C('rows: every row is keyed by FindingRowMapper::INPUT_KEYS, in that order',
        $all !== [] && array_reduce($all, function ($ok, $row) {
            return $ok && array_keys($row) === FindingRowMapper::INPUT_KEYS;
        }, true));
    $C('rows: a staged duplicate is not an active finding', count($all) === 4);
    $ids = array_map(function ($r) { return (int) $r['finding_id']; }, $all);
    $sorted = $ids;
    sort($sorted);
    $C('rows: in finding_id order', $ids === $sorted && count(array_unique($ids)) === 4);
    $first = $flat($all[0]);
    $C('rows: carrying what the producer sent, as the columns hold it',
        $first['project_id'] === '790' && $first['host_form'] === 'visit'
        && $first['field'] === 'x0' && $first['event_id'] === '5' && $first['instance'] === '2'
        && $first['rule_source_id'] === 'r1' && $first['rule_ord'] === '3'
        && $first['check_type'] === 'required' && $first['reason_code'] === 'required-blank'
        && $first['reason_bits'] === '6' && $first['dag_key'] === 'north'
        && $first['value_bin'] === 'v-1' && $first['value_len'] === '3'
        && $first['value_truncated'] === '0'
        && $first['value_expires_at'] === '2031-01-01 00:00:00');
    $plain = $flat($all[3]);
    $C('rows: and the column defaults where it sent none',
        $plain['event_id'] === null && $plain['instance'] === '1' && $plain['rule_ord'] === '0'
        && $plain['reason_bits'] === '0' && $plain['dag_key'] === null
        && $plain['value_bin'] === null && $plain['value_len'] === null
        && $plain['value_truncated'] === '0' && $plain['value_expires_at'] === null);

    $p1 = $sf->findings(790, $one['gen'], ['dag' => null], [], 0, 2);
    $last = (int) $p1[count($p1) - 1]['finding_id'];
    $p2 = $sf->findings(790, $one['gen'], ['dag' => null], [], $last, 2);
    $p3 = $sf->findings(790, $one['gen'], ['dag' => null], [], (int) $p2[count($p2) - 1]['finding_id'], 2);
    $seen = [];
    foreach (array_merge($p1, $p2) as $r) $seen[] = (int) $r['finding_id'];
    $C('paging: page two starts after the last row of page one and repeats none of it',
        count($p1) === 2 && count($p2) === 2 && count(array_unique($seen)) === 4
        && min(array_map(function ($r) { return (int) $r['finding_id']; }, $p2)) > $last);
    $C('paging: and the page after the last row is empty', $p3 === []);
    $C('paging: a limit below one is one row, and above a hundred is a hundred',
        count($sf->findings(790, $one['gen'], ['dag' => null], [], 0, 0)) === 1
        && count($sf->findings(790, $one['gen'], ['dag' => null], [], 0, 1000)) === 4);
    $north = $sf->findings(790, $one['gen'], ['dag' => null], ['dag_key' => 'north'], 0, 100);
    $C('filter: by group, in both stores',
        count($north) === 2 && $north[0]['dag_key'] === 'north' && $north[1]['dag_key'] === 'north');
    $C('filter: by instrument',
        count($sf->findings(790, $one['gen'], ['dag' => null], ['host_form' => 'visit'], 0, 100)) === 1);
    $C('filter: an axis it does not know is ignored, not interpolated',
        count($sf->findings(790, $one['gen'], ['dag' => null], ['record_hash' => 'x', 'host_form' => ''], 0, 100)) === 4);
    $C('rows: and the neighbour\'s page answers for the neighbour',
        count($sf->findings(791, $nbF['gen'], ['dag' => null], [], 0, 100)) === 1
        && (int) $sf->findings(791, $nbF['gen'], ['dag' => null], [], 0, 100)[0]['project_id'] === 791);

    // -- THE SCOPE IS MANDATORY, AND IT CONFINES (B5) ---------------------------
    //
    // A read path that can be called without a confinement will be. Both
    // stores refuse a scope with no dag key, and one naming an empty group,
    // before any row is read.
    $throws = function (callable $f) {
        try { $f(); } catch (\InvalidArgumentException $e) { return true; }
        return false;
    };
    $C('scope: a page with no scope at all is refused',
        $throws(function () use ($sf, $one) { $sf->findings(790, $one['gen'], [], [], 0, 100); }));
    $C('scope: and one naming an empty group, which would read as the project',
        $throws(function () use ($sf, $one) { $sf->findings(790, $one['gen'], ['dag' => ''], [], 0, 100); }));
    $C('scope: and a filter value that is not text',
        $throws(function () use ($sf, $one) {
            $sf->findings(790, $one['gen'], ['dag' => null], ['host_form' => ['visit']], 0, 100);
        }));
    $inNorth = $sf->findings(790, $one['gen'], ['dag' => 'north'], [], 0, 100);
    $C('scope: a group reader is handed the group\'s rows and no other',
        count($inNorth) === 2 && $inNorth[0]['dag_key'] === 'north' && $inNorth[1]['dag_key'] === 'north');
    $C('scope: a filter naming another group cannot widen it',
        $sf->findings(790, $one['gen'], ['dag' => 'north'], ['dag_key' => 'south'], 0, 100) === []);
    $C('scope: the same group as scope and filter is one axis',
        count($sf->findings(790, $one['gen'], ['dag' => 'north'], ['dag_key' => 'north'], 0, 100)) === 2);
    $C('scope: a group with no rows here reads none, and not the rows with no group',
        $sf->findings(790, $one['gen'], ['dag' => 'west'], [], 0, 100) === []);

    // -- $through: WHERE THE NEXT PAGE STARTS, OR NULL AT THE END --------------
    $t1 = 'unset';
    $q1 = $sf->findings(790, $one['gen'], ['dag' => null], [], 0, 3, $t1);
    $C('through: a full page with more behind it names its last row',
        count($q1) === 3 && $t1 === (int) $q1[2]['finding_id']);
    $t2 = 'unset';
    $q2 = $sf->findings(790, $one['gen'], ['dag' => null], [], $t1, 3, $t2);
    $C('through: and the page that holds the last row says nothing follows',
        count($q2) === 1 && $t2 === null);
    $t3 = 'unset';
    $sf->findings(790, $one['gen'], ['dag' => null], [], 0, 4, $t3);
    $C('through: a page exactly as long as the rows left still says nothing follows', $t3 === null);
    $t4 = 'unset';
    $sf->findings(790, $one['gen'], ['dag' => 'north'], ['dag_key' => 'south'], 0, 4, $t4);
    $C('through: a page no row can match ends at once', $t4 === null);

    // -- TWO AXES WALK THE FIRST AXIS'S KEY IN BOUNDED WINDOWS -----------------
    //
    // No key holds two axes, so a page that kept one row in 20,000 read all
    // 20,000 for it. Both stores walk FILTER_WINDOW rows of the first axis at
    // a time, FILTER_WINDOWS windows a call, and say where they stopped.
    $sw = $newStore();
    $W = ScanStore::FILTER_WINDOW;
    $wide = [];
    for ($k = 0; $k < $W * ScanStore::FILTER_WINDOWS + 2; $k++) {
        $wide[] = ['host_form' => 'wide', 'reason_code' => 'r'];
    }
    $wide[1]['reason_code'] = 'x';
    $wide[count($wide) - 1]['reason_code'] = 'x';
    $big = $scan($sw, 798, [$wide], null);
    $C('windows: the wide batch commits', $big['ok'] === true);
    $allIds = [];
    $ta = 0;
    do {
        foreach ($sw->findings(798, $big['gen'], ['dag' => null], ['host_form' => 'wide'], $ta, 100, $ta) as $r) {
            $allIds[] = (int) $r['finding_id'];
        }
    } while ($ta !== null);
    $C('windows: one axis pages through every row, 100 at a time', count($allIds) === count($wide));
    $w1 = 'unset';
    $p1w = $sw->findings(798, $big['gen'], ['dag' => null], ['host_form' => 'wide', 'reason_code' => 'x'],
                         0, 100, $w1);
    $C('windows: two axes find the early row and stop after the last window',
        count($p1w) === 1 && (int) $p1w[0]['finding_id'] === $allIds[1]
        && $w1 === $allIds[$W * ScanStore::FILTER_WINDOWS - 1]);
    $w2 = 'unset';
    $p2w = $sw->findings(798, $big['gen'], ['dag' => null], ['host_form' => 'wide', 'reason_code' => 'x'],
                         $w1, 100, $w2);
    $C('windows: the next call carries on from there and finds the late row, and the end',
        count($p2w) === 1 && (int) $p2w[0]['finding_id'] === $allIds[count($allIds) - 1] && $w2 === null);
    $w3 = 'unset';
    $p3w = $sw->findings(798, $big['gen'], ['dag' => null], ['reason_code' => 'x', 'host_form' => 'wide'],
                         0, 100, $w3);
    $C('windows: the order a filter is written in does not change the walk',
        $p3w === $p1w && $w3 === $w1);
    $w4 = 'unset';
    $p4w = $sw->findings(798, $big['gen'], ['dag' => null], ['host_form' => 'wide', 'reason_code' => 'r'],
                         0, 5, $w4);
    $C('windows: a page filled inside the first window names its last row',
        count($p4w) === 5 && $w4 === (int) $p4w[4]['finding_id']);

    // ONE STAGED ROW PER IDENTITY PER PASS, the second unique key. A staged row
    // and an active row of one identity are two keys and both commit; two
    // staged rows of one identity in one pass are refused, by both stores.
    $sk = $newStore();
    $same = ['identity' => hash('sha256', 'staged-twice', true)];
    $staged = $same + ['stage_epoch' => 4, 'group_hmac' => hash('sha256', 'g', true)];
    $twoKeys = $scan($sk, 792, [[$same, $staged]], null);
    $C('staged: a staged row beside an active row of the same identity commits',
        $twoKeys['ok'] === true && count($sk->findings(792, $twoKeys['gen'], ['dag' => null], [], 0, 100)) === 1);
    $twice = $scan($sk, 793, [[$staged, $staged]], null);
    $C('staged: two staged rows of one identity in one pass are refused',
        is_string($twice['ok']) && strpos($twice['ok'], 'database refused') !== false);
    // And across batches: the key is held by the staged row ALREADY STORED, not
    // only by the batch that carries it. The in-memory store checked the batch
    // against itself and the active rows, so a second batch of the same pass
    // could stage the same identity again where MySQL refuses it.
    $sx = $newStore();
    $sr = $sx->startRun(794, ['created_by' => 'alice']);
    $srid = (int) $sr['run']['run_id'];
    $sgen = (int) $sr['run']['generation_id'];
    $sx->writeManifest($srid, [$mk5('S1'), $mk5('S2')]);
    $sep = (int) $sx->run(794, $srid)['lease_epoch'];
    $scl = $sx->claim($srid, 'w1', $sep, 2);
    $stageOne = function ($c) use ($sx, $srid, $sep, $sgen, $staged) {
        return $sx->commitBatch($srid, 'w1', $sep, ['bytes' => 0,
            'records' => [['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                           'state' => ScanStore::REC_DONE, 'claim' => $c['claim']]],
            'findings' => [$staged + [
                'ordinal' => $c['ordinal'], 'project_id' => 794, 'generation_id' => $sgen,
                'host_form' => 'fa', 'field' => 'x', 'check_type' => 'unique',
                'reason_code' => 'unique-duplicate', 'record_id_bin' => $c['id_bin'],
                'record_hash' => $c['hash'], 'rule_source_id' => 'r1',
                'rule_revision' => str_repeat('c', 64)]]]);
    };
    $firstStage = count($scl) === 2 ? $stageOne($scl[0]) : null;
    $againStage = count($scl) === 2 ? $stageOne($scl[1]) : null;
    $C('staged: a staged row already stored holds its key against a later batch',
        $firstStage === true && is_string($againStage)
        && strpos($againStage, 'database refused') !== false);

    // -- THE LABEL SNAPSHOT: ONCE PER GENERATION, IN ORDER, THIS PROJECT'S (M5) --
    //
    // 230 small rows and 20 of 60,000 bytes: more statements than one, by
    // count and by bytes, so the SQL store writes in a transaction.
    $sd = $newStore();
    $dr = function ($kind, $key, $label = null, $meta = null) {
        return ['kind' => $kind, 'key' => $key, 'label' => $label, 'meta' => $meta];
    };
    $snap = [];
    for ($i = 229; $i >= 0; $i--) $snap[] = $dr('field', sprintf('f%03d', $i), "Field $i \u{00e9}", '{"form":"fa"}');
    for ($i = 0; $i < 20; $i++) $snap[] = $dr('form', 'big' . $i, str_repeat("\u{00e9}", 30000));
    $snap[] = $dr('state', 'snapshot', null, '{"v":1}');
    $snap[] = $dr('event', 12, 'Visit 1');
    $snap[] = $dr('rule', '9', 'R9', '{"type":"x"}');
    $snap[] = $dr('rule', '10', 'R10', null);
    $C('dims: a snapshot is written whole', $sd->writeDimensions(810, 3, $snap) === count($snap));
    $walk = [];
    $after = null;
    $pages = 0;
    while ($pages++ < 20) {
        $pg = $sd->dimensions(810, 3, $after, 100);
        if (!$pg) break;
        foreach ($pg as $r) $walk[] = $r;
        $last = $pg[count($pg) - 1];
        $after = [$last['kind'], $last['key']];
    }
    $want = $snap;
    usort($want, function ($a, $b) {
        $c = strcmp($a['kind'], $b['kind']);
        return $c !== 0 ? $c : strcmp((string) $a['key'], (string) $b['key']);
    });
    foreach ($want as $k => $r) $want[$k]['key'] = (string) $r['key'];
    $C('dims: pages of 100 walk it in (kind, key) byte order, every row once, as written',
        $walk === $want && $pages === 4);
    $C('dims: a page is clamped to 1..DIM_PAGE rows',
        count($sd->dimensions(810, 3, null, 0)) === 1
        && count($sd->dimensions(810, 3, null, 100000)) === min(ScanStore::DIM_PAGE, count($snap)));
    $C('dims: one row by its kind and key, the event id as text',
        $sd->dimension(810, 3, 'event', 12) === $dr('event', '12', 'Visit 1')
        && $sd->dimension(810, 3, 'rule', '11') === null);
    $dThrows = function (callable $f, $class) {
        try { $f(); } catch (\Throwable $e) { return get_class($e) === $class || is_subclass_of($e, $class); }
        return false;
    };
    $C('dims: a second snapshot of the generation is refused, and the first is kept',
        $dThrows(function () use ($sd, $dr) { $sd->writeDimensions(810, 3, [$dr('form', 'other', 'X')]); },
                 'RuntimeException')
        && $sd->dimension(810, 3, 'form', 'other') === null
        && count($sd->dimensions(810, 3, null, 500)) === count($snap));
    $C('dims: another generation and another project read none of it',
        $sd->dimensions(810, 4) === [] && $sd->dimensions(811, 3) === []
        && $sd->dimension(811, 3, 'event', '12') === null);
    $C('dims: another project snapshots the same generation number on its own',
        $sd->writeDimensions(811, 3, [$dr('event', 12, 'Elsewhere')]) === 1
        && $sd->dimension(811, 3, 'event', '12')['label'] === 'Elsewhere'
        && $sd->dimension(810, 3, 'event', '12')['label'] === 'Visit 1');
    $bad = [
        'a kind in capitals, which the column would fold into another' => [$dr('Rule', '1')],
        'an empty key'                                => [$dr('rule', '')],
        'a key wider than the column'                 => [$dr('field', str_repeat('k', 192))],
        'the same kind and key twice'                 => [$dr('form', 'fa', 'A'), $dr('form', 'fa', 'B')],
        'a label that is not text'                    => [$dr('form', 'fa', 7)],
        'a label wider than TEXT'                     => [$dr('form', 'fa', str_repeat('x', 65536))],
        'a label that is not UTF-8'                   => [$dr('form', 'fa', "bad \xC3")],
    ];
    foreach ($bad as $what => $rows) {
        $C('dims: refused before anything is written: ' . $what,
            $dThrows(function () use ($sd, $rows, $dr) {
                $sd->writeDimensions(812, 1, array_merge([$dr('event', '1', 'ok')], $rows));
            }, 'InvalidArgumentException') && $sd->dimensions(812, 1) === []);
    }
    $C('dims: a snapshot needs its project and its generation',
        $dThrows(function () use ($sd, $dr) { $sd->writeDimensions(0, 1, [$dr('event', '1')]); }, 'InvalidArgumentException')
        && $dThrows(function () use ($sd, $dr) { $sd->writeDimensions(812, 0, [$dr('event', '1')]); }, 'InvalidArgumentException'));
    $C('dims: a cursor that is not a [kind, key] pair is refused',
        $dThrows(function () use ($sd) { $sd->dimensions(810, 3, ['rule']); }, 'InvalidArgumentException')
        && $dThrows(function () use ($sd) { $sd->dimensions(810, 3, ['RULE', '1']); }, 'InvalidArgumentException')
        && $dThrows(function () use ($sd) { $sd->dimension(810, 3, 'STATE', 'snapshot'); }, 'InvalidArgumentException'));

    // -- THE PREVIOUS GENERATION IS CLOSED WHEN A RUN COMPLETES (W4-D1) --------
    $sp = $newStore();
    $nb = $scan($sp, 796, [[[], []]], 'complete');
    $g1 = $scan($sp, 795, [[[], []]], 'complete');
    $C('supersede: a first complete run has nothing older, and says it is done',
        $sp->supersedePrevious(795, $g1['run'], 10)
            === ['done' => true, 'rows' => 0, 'cursor' => $g1['gen'], 'why' => null]);
    $g2 = $scan($sp, 795, [[[], [], []]], null);
    $live = $sp->supersedePrevious(795, $g2['run'], 10);
    $C('supersede: a run still in progress closes nothing, and is NOT done - it may yet complete',
        $live['done'] === false && $live['rows'] === 0 && is_string($live['why'])
        && count($sp->findings(795, $g1['gen'], ['dag' => null], [], 0, 100)) === 2);
    $C('superseded by: nothing has replaced a run while the later one is still in progress',
        $sp->supersededBy(795, $g1['run']) === null);
    $sp->finish($g2['run'], ScanOutcome::derive(['fenced' => true, 'manifestDone' => true,
                                                 'violations' => 1]));
    $C('supersede: the fixture run really did complete',
        $sp->run(795, $g2['run'])['terminal'] === ScanOutcome::COMPLETE);
    $C('supersede: another project\'s id does not reach this run',
        $sp->supersedePrevious(796, $g2['run'], 10)['rows'] === 0
        && count($sp->findings(795, $g1['gen'], ['dag' => null], [], 0, 100)) === 2);
    $a = $sp->supersedePrevious(795, $g2['run'], 1);
    $C('supersede: one page closes at most its limit, and is not done while it was full',
        $a['rows'] === 1 && $a['done'] === false && $a['cursor'] === 0
        && count($sp->findings(795, $g1['gen'], ['dag' => null], [], 0, 100)) === 1);
    $b = $sp->supersedePrevious(795, $g2['run'], 1);
    $c = $sp->supersedePrevious(795, $g2['run'], 1);
    $C('supersede: the next calls carry on where it stopped, and the one that comes up short is done',
        $b['rows'] === 1 && $b['done'] === false && $c['rows'] === 0 && $c['done'] === true
        && $c['cursor'] === $g2['gen']);
    $C('supersede: the older generation has no active finding left',
        $sp->findings(795, $g1['gen'], ['dag' => null], [], 0, 100) === []);
    $C('supersede: this run\'s own generation is untouched',
        count($sp->findings(795, $g2['gen'], ['dag' => null], [], 0, 100)) === 3);
    $C('supersede: and so is the project next door',
        count($sp->findings(796, $nb['gen'], ['dag' => null], [], 0, 100)) === 2);
    $C('supersede: asking again changes nothing',
        $sp->supersedePrevious(795, $g2['run'], 10)
            === ['done' => true, 'rows' => 0, 'cursor' => $g2['gen'], 'why' => null]);
    // A run that did NOT complete leaves the previous report the current one.
    foreach (['partial' => ['fenced' => true, 'manifestDone' => false, 'blocked' => true],
              'cancelled' => ['cancelled' => true, 'fenced' => true, 'manifestDone' => true]] as $how => $o) {
        $gx = $scan($sp, 795, [[[]]], null);
        $sp->finish($gx['run'], ScanOutcome::derive($o));
        $x = $sp->supersedePrevious(795, $gx['run'], 10);
        $C("supersede: a $how run closes nothing, and that is final",
            $x['done'] === true && $x['rows'] === 0 && is_string($x['why'])
            && count($sp->findings(795, $g2['gen'], ['dag' => null], [], 0, 100)) === 3);
    }
    // MORE THAN ONE OLDER GENERATION: four of them now - the first run's,
    // already closed, the second's three rows, and one row each from the
    // partial and the cancelled run. Walked oldest first, one generation per
    // call when each fits in a page, the cursor only ever rising.
    $gy = $scan($sp, 795, [[[]]], 'complete');
    $rest = 0;
    $calls = 0;
    $rising = true;
    $was = -1;
    do {
        $y = $sp->supersedePrevious(795, $gy['run'], 100);
        $rest += $y['rows'];
        $calls++;
        if ($y['cursor'] <= $was) $rising = false;
        $was = $y['cursor'];
    } while (!$y['done'] && $calls < 10);
    $C('supersede: several older generations are closed oldest first, one per call',
        $y['done'] === true && $calls === 4 && $rising && $y['cursor'] === $gy['gen']);
    $C('supersede: every older row is closed and the newest run alone is current',
        $rest === 5 && $sp->findings(795, $g2['gen'], ['dag' => null], [], 0, 100) === []
        && count($sp->findings(795, $gy['gen'], ['dag' => null], [], 0, 100)) === 1);
    $C('supersede: a run id nobody has is done with nothing to do',
        $sp->supersedePrevious(795, 987654321, 10)['done'] === true);
    // WHICH RUN REPLACED A REPORT (reviews rv11a1-5, rv11a2-1): the earliest
    // later whole-project run that completed. The partial and the cancelled
    // run between the second and the last replace nothing.
    $C('superseded by: a run is replaced by the next run of its project that completed',
        $sp->supersededBy(795, $g1['run']) === $g2['run'] && $sp->supersededBy(795, $g2['run']) === $gy['run']);
    $C('superseded by: a run that did not complete is replaced too, and replaces nothing',
        $sp->supersededBy(795, $gx['run']) === $gy['run']);
    $C('superseded by: the newest complete run is replaced by nothing',
        $sp->supersededBy(795, $gy['run']) === null);
    $C('superseded by: another project\'s id, and an id that is no run, are replaced by nothing',
        $sp->supersededBy(796, $g1['run']) === null && $sp->supersededBy(795, 987654321) === null
        && $sp->supersededBy(796, $nb['run']) === null);

    // ONLY A WHOLE-PROJECT RUN SUPERSEDES (review R8-1). A run scoped to one
    // data access group read that group's records and no others; it closed
    // every older generation regardless, so the other groups' findings were
    // stamped superseded by a run that never read them. A whole-project run
    // of three records, then a run scoped to group 11 of one record: the
    // scoped run closes nothing, and says why. The next whole-project run to
    // complete closes both older generations, the scoped one included.
    $sd = $newStore();
    $whole = $scan($sd, 797, [[[]], [[]], [[]]], 'complete');
    $group = $scan($sd, 797, [[[]]], 'complete', '11');
    $C('scope: the fixture runs completed, one of them scoped to a group',
        $sd->run(797, $group['run'])['terminal'] === ScanOutcome::COMPLETE
        && (string) $sd->run(797, $group['run'])['scope_dag'] === '11'
        && $sd->run(797, $whole['run'])['scope_dag'] === null);
    $g = $sd->supersedePrevious(797, $group['run'], 100);
    $C('scope: a run of one group closes nothing, is done, and says why',
        $g === ['done' => true, 'rows' => 0, 'cursor' => 0, 'why' => ScanStore::SUPERSEDE_SCOPED]);
    $C('scope: so every finding of the whole-project run is still current',
        count($sd->findings(797, $whole['gen'], ['dag' => null], [], 0, 100)) === 3);
    $C('scope: and asking again changes nothing',
        $sd->supersedePrevious(797, $group['run'], 100) === $g
        && count($sd->findings(797, $whole['gen'], ['dag' => null], [], 0, 100)) === 3);
    $C('superseded by: a run of one group replaces no report',
        $sd->supersededBy(797, $whole['run']) === null && $sd->supersededBy(797, $group['run']) === null);
    $next = $scan($sd, 797, [[[]]], 'complete');
    $C('superseded by: the next whole-project run replaces both, the group\'s run included',
        $sd->supersededBy(797, $whole['run']) === $next['run']
        && $sd->supersededBy(797, $group['run']) === $next['run'] && $sd->supersededBy(797, $next['run']) === null);
    $closed = 0;
    $calls = 0;
    do {
        $z = $sd->supersedePrevious(797, $next['run'], 100);
        $closed += $z['rows'];
        $calls++;
    } while (!$z['done'] && $calls < 10);
    $C('scope: the next whole-project run closes both older generations, the group\'s included',
        $z['done'] === true && $z['why'] === null && $closed === 4
        && $sd->findings(797, $whole['gen'], ['dag' => null], [], 0, 100) === []
        && $sd->findings(797, $group['gen'], ['dag' => null], [], 0, 100) === []
        && count($sd->findings(797, $next['gen'], ['dag' => null], [], 0, 100)) === 1);

    // -- PLANNING ACROSS REQUESTS: ONE LEASE, ONE TRANSACTION, COUNTED (B10) --
    //
    // Planning lists a slice per request. Each page and the walk's position
    // commit together, one planner holds the walk at a time, and a slice that
    // dies is counted before it is tried - so these are the claims a resumable
    // walk stands on, asked of both stores.
    $sp = $newStore();
    $rp = (int) $sp->startRun(798, ['created_by' => 'alice'])['run']['run_id'];
    $epP = (int) $sp->run(798, $rp)['lease_epoch'];
    $pg = function ($owner, $cursor, $listed, array $over = []) use ($epP) {
        return array_merge(['owner' => $owner, 'epoch' => $epP, 'cursor' => $cursor,
            'carry' => 'carry-' . $cursor, 'listed' => $listed, 'outOfScope' => 1,
            'done' => false, 'release' => false, 'leaseSeconds' => 300], $over);
    };
    $c1 = $sp->claimPlan($rp, 'p1', $epP, 300, 3);
    $C('plan: a fresh walk is at its start, and this claim is its first attempt',
        is_array($c1) && $c1['state'] === 'ok' && $c1['cursor'] === null && $c1['carry'] === null
        && $c1['pages'] === 0 && $c1['listed'] === 0 && $c1['attempts'] === 1);
    $C('plan: another planner is told the walk is held while the lease is live',
        $sp->claimPlan($rp, 'p2', $epP, 300, 3)['state'] === 'held');
    $C('plan: the holder appends a page with where the walk stands',
        $sp->appendManifest($rp, [$mk('A'), $mk('B')], $pg('p1', 'B', 3)) === 2);
    $C('plan: a planner that does not hold the lease appends nothing',
        $sp->appendManifest($rp, [$mk('X')], $pg('p2', 'X', 9)) === false
        && $sp->recordStates($rp) === [ScanStore::REC_PENDING => 2]);
    $C('plan: nor does one at an epoch the run no longer has',
        $sp->appendManifest($rp, [$mk('X')], $pg('p1', 'X', 9, ['epoch' => $epP + 1])) === false);
    $C('plan: nor one that names no owner',
        $sp->appendManifest($rp, [$mk('X')], $pg('', 'X', 9)) === false);
    $C('plan: a lease is released only by its holder',
        $sp->releasePlan($rp, 'p2') === false && $sp->releasePlan($rp, 'p1') === true);
    $c2 = $sp->claimPlan($rp, 'p2', $epP, 300, 3);
    $C('plan: once released, the next planner takes it and reads where the last page left the walk',
        is_array($c2) && $c2['state'] === 'ok' && $c2['cursor'] === 'B' && $c2['carry'] === 'carry-B'
        && $c2['pages'] === 1 && $c2['listed'] === 3 && $c2['outOfScope'] === 1);
    $C('plan: and the committed page put the attempt count back to zero before this claim',
        $c2['attempts'] === 1);
    $C('plan: a page appended with release hands the lease back in the same write',
        $sp->appendManifest($rp, [$mk('C')], $pg('p2', 'C', 4, ['release' => true])) === 1
        && $sp->claimPlan($rp, 'p3', $epP, 300, 3)['state'] === 'ok');
    // A SLICE THAT DIES IS COUNTED BEFORE IT IS TRIED (I3b). p3 took the lease
    // and never appended; nothing it could run afterwards counts that.
    $sp->releasePlan($rp, 'p3');
    $C('plan: claims that never got a page in keep counting',
        $sp->claimPlan($rp, 'p4', $epP, 300, 3)['attempts'] === 2
        && $sp->releasePlan($rp, 'p4') === true
        && $sp->claimPlan($rp, 'p5', $epP, 300, 3)['attempts'] === 3);
    $sp->releasePlan($rp, 'p5');
    $ex = $sp->claimPlan($rp, 'p6', $epP, 300, 3);
    $C('plan: and at the cap the walk is exhausted, with no lease handed out',
        is_array($ex) && $ex['state'] === 'exhausted' && $ex['attempts'] === 3
        && $sp->appendManifest($rp, [$mk('D')], $pg('p6', 'D', 5)) === false);
    $C('plan: a higher cap lets the same walk try again',
        $sp->claimPlan($rp, 'p6', $epP, 300, 5)['state'] === 'ok');
    $C('plan: the source saying done is written with the last page',
        $sp->appendManifest($rp, [$mk('D')], $pg('p6', 'D', 5, ['done' => true])) === 1
        && $sp->claimPlan($rp, 'p7', $epP, 300, 5)['state'] === 'done');
    $C('plan: and then the freeze counts every row, the refused ones not among them',
        $sp->freezeManifest($rp) === 4);
    // A STOP FENCES THE PLANNER OUT, whichever request it is in.
    $sq = $newStore();
    $rq = (int) $sq->startRun(799, ['created_by' => 'alice'])['run']['run_id'];
    $epQ = (int) $sq->run(799, $rq)['lease_epoch'];
    $sq->claimPlan($rq, 'p1', $epQ, 300, 3);
    $sq->cancel(799, $rq, 'bob');
    $C('plan: after Stop, the planner holding the lease appends nothing and cannot claim again',
        $sq->appendManifest($rq, [$mk('A')], ['owner' => 'p1', 'epoch' => $epQ, 'cursor' => 'A',
            'carry' => null, 'listed' => 1, 'outOfScope' => 0, 'done' => false]) === false
        && $sq->claimPlan($rq, 'p1', $epQ, 300, 3) === false
        && $sq->recordStates($rq) === []);

    // A LEASED APPEND THAT FINDS NO PLAN ROW IS REFUSED (review R5-9).
    // claimPlan() writes the row before it hands out a lease, so a missing one
    // was removed by something other than the walk, and the walk's position
    // went with it. The SQL store wrote the row with the page while the
    // in-memory store refused the page, and commit 2bd57cd said they agreed.
    // A claim answered `held` in between writes no row on either store: the
    // in-memory one used to, and its append then went through.
    if ($dropPlan !== null) {
        $sz = $newStore();
        $rz = (int) $sz->startRun(796, ['created_by' => 'alice'])['run']['run_id'];
        $epZ = (int) $sz->run(796, $rz)['lease_epoch'];
        $cz = $sz->claimPlan($rz, 'p1', $epZ, 300, 3);
        $dropPlan($sz, $rz);
        $held = $sz->claimPlan($rz, 'p2', $epZ, 300, 3);
        $C('plan row: a lease is handed out, and another planner is then told it is held',
            is_array($cz) && $cz['state'] === 'ok' && is_array($held) && $held['state'] === 'held');
        $C('plan row: a leased append whose plan row is gone is refused, and writes no record',
            $sz->appendManifest($rz, [$mk('A')], ['owner' => 'p1', 'epoch' => $epZ, 'cursor' => 'A',
                'carry' => 'c', 'listed' => 1, 'outOfScope' => 0, 'done' => false, 'release' => false,
                'leaseSeconds' => 300]) === false
            && $sz->recordStates($rz) === []);
        $again = $sz->claimPlan($rz, 'p1', $epZ, 300, 3);
        $C('plan row: and wrote no plan row either: the holder\'s next claim finds the walk at its start',
            is_array($again) && $again['state'] === 'ok' && $again['cursor'] === null
            && $again['carry'] === null && $again['pages'] === 0 && $again['attempts'] === 1);
    }

    // -- FINDINGS COUNTED AND NOT STORED (H9) ------------------------------
    //
    // Past the run's detail budget the worker leaves a finding out of the
    // batch and sends how many as `unstored`, ordinal => count. The store
    // counts the HELD records' under ScanStore::DETAIL_UNSTORED in the batch's
    // own commit, and nothing for a record it lost: that record's findings are
    // its new holder's to count.
    $sU = $newStore();
    $rU = (int) $sU->startRun(798, ['created_by' => 'alice'])['run']['run_id'];
    $sU->writeManifest($rU, [$mk('U1'), $mk('U2'), $mk('U3')]);
    $epU = (int) $sU->run(798, $rU)['lease_epoch'];
    $mineU = $sU->claim($rU, 'w1', $epU, 2);
    // The straggler sweep takes the first of the two back out from under w1.
    $takenU = $sU->claimPending($rU, 'w2', $epU, 1);
    $bU = function (array $rows) {
        $b = ['bytes' => 0, 'records' => [], 'findings' => [], 'unstored' => []];
        foreach ($rows as $c) {
            $b['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                               'state' => ScanStore::REC_DONE, 'version' => '1', 'claim' => $c['claim']];
            $b['unstored'][(int) $c['ordinal']] = 3;
        }
        return $b;
    };
    $leftOut = function (ScanStore $st, $rid) {
        $n = 0;
        foreach ($st->aggregates($rid) as $a) {
            if ((string) $a['kind'] === ScanStore::DETAIL_UNSTORED) $n += (int) $a['cnt'];
        }
        return $n;
    };
    $C('unstored: the fixture has one record of two taken over',
        count($mineU) === 2 && count($takenU) === 1
        && (int) $takenU[0]['ordinal'] === (int) $mineU[0]['ordinal']);
    $C('unstored: a batch carrying counts of findings left out commits',
        $sU->commitBatch($rU, 'w1', $epU, $bU($mineU)) === true);
    $C('unstored: the held record\'s count is kept, and the lost record\'s is not',
        $leftOut($sU, $rU) === 3);
    $C('unstored: no finding row is written for them, so the detail counter does not move',
        (int) $sU->run(798, $rU)['detail_rows'] === 0);
    $C('unstored: and the count is not a blocking fact',
        $sU->blockingAggregates($rU) === 0);
    // A refused batch counts nothing, as it stores nothing.
    $C('unstored: a batch refused at the fence counts nothing',
        $sU->commitBatch($rU, 'w2', $epU - 1, $bU($takenU)) !== true && $leftOut($sU, $rU) === 3);
    $C('unstored: the new holder counts its own record\'s',
        $sU->commitBatch($rU, 'w2', $epU, $bU($takenU)) === true && $leftOut($sU, $rU) === 6);
    $threw = false;
    try {
        $sU->commitBatch($rU, 'w1', $epU, ['bytes' => 0, 'records' => [], 'findings' => [],
                                           'unstored' => [1 => 'several']]);
    } catch (\Throwable $e) { $threw = true; }
    $C('unstored: a count that is not a whole number is refused loudly', $threw === true);

    // -- THE FINISHING PHASES' PROGRESS CLOCK (W7-N9) ----------------------
    //
    // setProgressState() moves progress_at only when told the step moved the
    // run forward, fenced on the lease like every cursor it writes. A run
    // that was never planned has a NULL clock, so any write to it shows.
    $sP = $newStore();
    $rP = (int) $sP->startRun(789, ['created_by' => 'alice'])['run']['run_id'];
    $epP = (int) $sP->run(789, $rP)['lease_epoch'];
    $C('progress: a fresh run has no progress clock', $sP->run(789, $rP)['progress_at'] === null);
    $C('progress: a cursor written alone does not move it',
        $sP->setProgressState($rP, $epP, ['rollupCursor' => 0]) === true
        && $sP->run(789, $rP)['progress_at'] === null);
    $C('progress: a stale lease cannot move it',
        $sP->setProgressState($rP, $epP + 1, ['progress' => true]) === false
        && $sP->run(789, $rP)['progress_at'] === null);
    $C('progress: told the run moved forward, the lease holder moves it, with no cursor beside it',
        $sP->setProgressState($rP, $epP, ['progress' => true]) === true
        && $sP->run(789, $rP)['progress_at'] !== null);
    // -- AN ABANDONED RUN IS EXPIRED BY ONE REAPER, THE SAME WAY IN BOTH (B6a)
    //
    // The clock is stepped, not waited for: $now is a day and an hour ahead,
    // so a run created a moment ago has made no progress for 25 hours.
    $sa = $newStore();
    $ra = $sa->startRun(785, ['created_by' => 'alice']);
    $rida = (int) $ra['run']['run_id'];
    $sa->writeManifest($rida, [$mk5('AB-1')]);
    $epA = (int) $sa->run(785, $rida)['lease_epoch'];
    $heldA = $sa->claim($rida, 'w1', $epA, 1);
    $nbA = $sa->startRun(786, ['created_by' => 'alice']);
    $later = time() + 25 * 3600;
    $C('reap: a run inside the stale window is left alone',
        $sa->expireAbandoned(785, 24, 'cron', time()) === 0
        && $sa->run(785, $rida)['phase'] !== ScanPhase::TERMINAL);
    $C('reap: once it has made no progress for longer than the window, it is expired',
        $sa->expireAbandoned(785, 24, 'cron', $later) === 1);
    $ex = $sa->run(785, $rida);
    $want = ScanOutcome::derive(['expired' => true]);
    $C('reap: the row is the one finish(derive([expired])) writes, column for column',
        $ex['phase'] === ScanPhase::TERMINAL && $ex['terminal'] === $want['terminal']
        && $ex['coverage'] === $want['coverage'] && $ex['detail'] === $want['detail']
        && $ex['terminal_reason'] === $want['why'] && $ex['clean'] === null
        && (int) $ex['gap_count'] === 0 && (int) $ex['rule_problem_count'] === 0
        && (int) $ex['not_examined'] === 0);
    $C('reap: the lease epoch moved in the same write (Risk 5)',
        (int) $ex['lease_epoch'] === $epA + 1);
    $C('reap: so a worker that comes back with the epoch it claimed under commits nothing',
        is_array($heldA) && count($heldA) === 1
        && $sa->commitBatch($rida, 'w1', $epA, ['bytes' => 0, 'findings' => [],
            'records' => [['ordinal' => $heldA[0]['ordinal'], 'record_hash' => $heldA[0]['hash'],
                           'state' => ScanStore::REC_DONE, 'claim' => $heldA[0]['claim']]]]) !== true
        && !isset($sa->recordStates($rida)[ScanStore::REC_DONE]));
    $C('reap: a second call finds nothing to do',
        $sa->expireAbandoned(785, 24, 'cron', $later) === 0);
    $C('reap: and the project slot is free for the next scan',
        $sa->startRun(785, ['created_by' => 'bob'])['ok'] === true);
    $C('reap: the project next door, as stale, is not touched by this project\'s reap',
        $sa->run(786, (int) $nbA['run']['run_id'])['phase'] !== ScanPhase::TERMINAL);
    // A LIVE LEASE IS A WORKER, however old the progress clock: the planner
    // here holds the walk for thirty hours.
    $sl = $newStore();
    $rl = (int) $sl->startRun(787, ['created_by' => 'alice'])['run']['run_id'];
    $sl->claimPlan($rl, 'p1', (int) $sl->run(787, $rl)['lease_epoch'], 30 * 3600, 3);
    $C('reap: a run whose lease is still live is not abandoned',
        $sl->expireAbandoned(787, 24, 'cron', $later) === 0);
    // -- A RECORD THAT LEFT THE RUN'S GROUP (review rv5b-1) ----------------
    //
    // It leaves the run: terminal, unread, and its findings in this run's
    // generation closed, as a re-examination closes them. By record hash, in
    // catch-up and at the current epoch only, as the other reconciliation
    // writes; and a deleted record stays a tombstone.
    $sd = $newStore();
    $rd = (int) $sd->startRun(746, [])['run']['run_id'];
    $sd->writeManifest($rd, [$mk5('L1'), $mk5('L2'), $mk5('L3'), $mk5('L4')]);
    $rowD = $sd->run(746, $rd);
    $epD = (int) $rowD['lease_epoch'];
    $genD = (int) $rowD['generation_id'];
    $hD = function ($id) { return hash('sha256', $id, true); };
    $findingD = function ($ord, $id) use ($genD, $rowD, $hD) {
        return ['ordinal' => $ord, 'project_id' => 746, 'generation_id' => $genD,
                'host_form' => 'fa', 'field' => 'x', 'check_type' => 'required',
                'reason_code' => 'required-blank', 'record_id_bin' => $id,
                'identity' => hash('sha256', 'd' . $id, true),
                'valid_from_seq' => (int) $rowD['run_seq'], 'record_hash' => $hD($id),
                'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64)];
    };
    $activeD = function () use ($sd, $genD) {
        $ids = [];
        foreach ($sd->findings(746, $genD, ['dag' => null], [], 0, 100) as $f) $ids[] = (string) $f['record_id_bin'];
        sort($ids);
        return $ids;
    };
    $bD = ['bytes' => 10, 'records' => [], 'findings' => []];
    foreach ($sd->claim($rd, 'w1', $epD, 4) as $c) {
        $bD['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                            'state' => ScanStore::REC_DONE, 'version' => '10', 'claim' => $c['claim']];
        $bD['findings'][] = $findingD($c['ordinal'], $c['id_bin']);
    }
    $sd->commitBatch($rd, 'w1', $epD, $bD);
    $C('depart: refused while the run is still scanning',
        $sd->depart($rd, $epD, [$hD('L2')]) === 0 && $sd->departedOf($rd, [$hD('L2')]) === []
        && $activeD() === ['L1', 'L2', 'L3', 'L4']);
    $sd->advancePhase($rd, $epD, ScanPhase::CATCH_UP);
    $C('depart: refused at another lease epoch', $sd->depart($rd, $epD + 1, [$hD('L2')]) === 0);
    $C('W5F-N3: in catch-up, a tombstone or a requeue that names records by id moves nothing',
        $sd->tombstone($rd, $epD, ['L3']) === 0 && $sd->requeue($rd, $epD, ['L4']) === 0
        && $sd->recordStates($rd) === [ScanStore::REC_DONE => 4]);
    $C('W5F-N3: by record hash, the tombstone moves its one row',
        $sd->tombstone($rd, $epD, [$hD('L3')]) === 1);
    // W5F-N4. A record deleted during the run has its findings in the run
    // closed with it, as depart() closes a departed one's: the run reports
    // on records the project holds, and counts this one as removed.
    $C('W5F-N4: a tombstone closes the deleted record\'s findings in the run, and no other record\'s',
        $activeD() === ['L1', 'L2', 'L4']);
    $stN4 = $sd->recordStates($rd);
    ksort($stN4);
    $C('W5F-N4: the run counts it removed and finished, not done',
        $stN4 === [ScanStore::REC_DONE => 3, ScanStore::REC_TOMBSTONE => 1]
        && (int) $sd->run(746, $rd)['manifest_done'] === 4);
    $C('W5F-N4: tombstoning it again moves nothing and closes nothing',
        $sd->tombstone($rd, $epD, [$hD('L3')]) === 0 && $activeD() === ['L1', 'L2', 'L4']);
    $nD = $sd->depart($rd, $epD, [$hD('L2'), $hD('L3')]);
    $stD = $sd->recordStates($rd);
    $C('depart: a record that left the group departs, and a deleted one stays a tombstone',
        $nD === 1 && isset($stD[ScanStore::REC_DEPARTED]) && $stD[ScanStore::REC_DEPARTED] === 1
        && $stD[ScanStore::REC_TOMBSTONE] === 1 && $stD[ScanStore::REC_DONE] === 2);
    $C('depart: its findings in the run are closed, and no other record\'s',
        $activeD() === ['L1', 'L4']);
    $C('depart: the run counts it finished, so it holds nothing open',
        (int) $sd->run(746, $rd)['manifest_done'] === 4 && $sd->manifestComplete($rd) === true);
    $C('depart: departedOf() names it, and no other',
        $sd->departedOf($rd, [$hD('L1'), $hD('L2'), $hD('L3')]) === [$hD('L2') => true]);
    $C('depart: departing it again moves nothing', $sd->depart($rd, $epD, [$hD('L2')]) === 0);
    // The worker's half: a record it finds in another group is committed
    // departed, and the commit closes its findings as a re-examination would.
    $sd->requeue($rd, $epD, [$hD('L4')]);
    $cD4 = $sd->claimPending($rd, 'w2', $epD, 10);
    $okD4 = is_array($cD4) && count($cD4) === 1 && $sd->commitBatch($rd, 'w2', $epD,
        ['bytes' => 0, 'records' => [['ordinal' => $cD4[0]['ordinal'], 'record_hash' => $cD4[0]['hash'],
            'state' => ScanStore::REC_DEPARTED, 'version' => null, 'claim' => $cD4[0]['claim']]],
         'findings' => []]) === true;
    $C('depart: a batch that commits a record departed closes its findings with it',
        $okD4 && $activeD() === ['L1']
        && $sd->departedOf($rd, [$hD('L4')]) === [$hD('L4') => true]);
    // A RUN SOMEBODY STOPPED IS NOT ABANDONED (review rv10a1-2), however long
    // it has sat in `cancelling`: it ends as cancelled, by the finisher Stop
    // uses, and an expiry would write "abandoned" over the stop.
    $sc = $newStore();
    $rc = (int) $sc->startRun(792, ['created_by' => 'alice'])['run']['run_id'];
    $sc->cancel(792, $rc, 'alice');
    $C('reap: a run left cancelling is not expired, however stale',
        $sc->expireAbandoned(792, 24, 'cron', $later) === 0
        && $sc->run(792, $rc)['phase'] === ScanPhase::CANCELLING && $sc->run(792, $rc)['terminal'] === null);

    // THE CLOCK IS THE STATUS PAGE'S (review rv10a1-1). In catch-up and the two
    // finalizers nothing moves progress_at and nothing renews the lease, and
    // every step moves updated_at: the last record finished thirty hours ago,
    // the finalizer touched the run five seconds ago, and the page calls it
    // working. Scanning keeps the progress clock, because there updated_at is
    // what a claim refreshes on a run whose every batch is refused.
    if ($setRun !== null) {
        $long = gmdate('Y-m-d H:i:s', time() - 30 * 3600);
        $recent = gmdate('Y-m-d H:i:s', time() - 5);
        foreach (array_merge(ScanPhase::ON_THE_UPDATE_CLOCK, [ScanPhase::SCANNING]) as $i => $ph) {
            $sp = $newStore();
            $pp = 788 + $i;
            $rp = (int) $sp->startRun($pp, ['created_by' => 'alice'])['run']['run_id'];
            $setRun($sp, $rp, ['phase' => $ph, 'progress_at' => $long, 'updated_at' => $recent,
                               'lease_expires_at' => null]);
            $onUpdate = $ph !== ScanPhase::SCANNING;
            $C('reap: in ' . $ph . ', a run touched seconds ago after thirty hours without a record '
                . ($onUpdate ? 'is working, not abandoned' : 'is abandoned: a touch is no progress there'),
                $sp->expireAbandoned($pp, 24, 'cron') === ($onUpdate ? 0 : 1));
            if (!$onUpdate) continue;
            $setRun($sp, $rp, ['updated_at' => $long]);
            $C('reap: and in ' . $ph . ', once nothing has touched it for the window either, it is expired',
                $sp->expireAbandoned($pp, 24, 'cron') === 1
                && $sp->run($pp, $rp)['terminal'] === ScanOutcome::EXPIRED);
        }

        // A RUN RETENTION HAS BEGUN REMOVING IS NO RUN (review rv10a1-3).
        // purgeRuns() marks the row before its first page and deletes it after
        // its last; in between, every reader answers as it will afterwards.
        $sm = $newStore();
        $rm = (int) $sm->startRun(793, ['created_by' => 'alice'])['run']['run_id'];
        $sm->finish($rm, ScanOutcome::derive(['fenced' => true, 'manifestDone' => true]));
        $rn = (int) $sm->startRun(793, ['created_by' => 'alice'])['run']['run_id'];
        $sm->finish($rn, ScanOutcome::derive(['fenced' => true, 'manifestDone' => true]));
        $C('remove: a finished run reads as itself', $sm->run(793, $rm) !== null);
        $setRun($sm, $rm, ['terminal_reason' => ScanStore::REMOVING_WHY]);
        $C('remove: once retention has begun removing it, it reads as no run, and the next run is untouched',
            $sm->run(793, $rm) === null && $sm->run(793, $rn) !== null);

        // A RUN STARTS AT ITS PROJECT'S REVISION NOW (W10A-N1). The retention
        // cron moves a project's runs to a higher revision when its privacy
        // policy tightens; a run started after that starts there, not at 1,
        // so a repeat of the bump does not reach it. Another project's
        // revision is not this one's.
        $sv = $newStore();
        $ra = $sv->startRun(794, ['created_by' => 'alice'])['run'];
        $C('revision: a project\'s first run starts at revision 1', (int) $ra['policy_revision'] === 1);
        $sv->finish((int) $ra['run_id'], ScanOutcome::derive(['fenced' => true, 'manifestDone' => true]));
        $setRun($sv, (int) $ra['run_id'], ['policy_revision' => 3]);
        $rb = $sv->startRun(794, ['created_by' => 'alice'])['run'];
        $rc = $sv->startRun(795, ['created_by' => 'alice'])['run'];
        $C('revision: a later run starts at the project\'s highest revision, and only its own project\'s',
            (int) $rb['policy_revision'] === 3 && (int) $sv->run(794, (int) $rb['run_id'])['policy_revision'] === 3
            && (int) $rc['policy_revision'] === 1);
    }

    // -- what the detail budget left out, per record (review rvf1-3) -------
    //
    // A record committed done keeps its left-out count on its row and the
    // run's open_unstored grows by it. A deleted or departed record takes it
    // back; a requeued one keeps it until its next reading replaces it
    // (reviews rvc2-2, rvc1-2).
    $su = $newStore();
    $ru = (int) $su->startRun(747, ['created_by' => 'alice'])['run']['run_id'];
    $hU = function ($id) { return hash('sha256', $id, true); };
    $su->writeManifest($ru, array_map(function ($id) use ($hU) {
        return ['id_bin' => $id, 'hash' => $hU($id), 'dag' => null];
    }, ['U1', 'U2', 'U3', 'U4']));
    $epU = (int) $su->run(747, $ru)['lease_epoch'];
    $openU = function () use ($su, $ru) {
        $v = $su->run(747, $ru)['open_unstored'];
        return $v === null ? null : (int) $v;
    };
    $C('rvf1-3: a run created now starts with nothing left out, not with no count',
        $openU() === 0);
    $bU = ['bytes' => 0, 'records' => [], 'findings' => [], 'unstored' => []];
    foreach ($su->claim($ru, 'w1', $epU, 4) as $c) {
        $done = $c['id_bin'] !== 'U4';
        $bU['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'], 'version' => '10',
                            'state' => $done ? ScanStore::REC_DONE : ScanStore::REC_TOMBSTONE,
                            'claim' => $c['claim']];
        $left = ['U1' => 2, 'U2' => 3, 'U3' => 0, 'U4' => 5];
        if ($left[$c['id_bin']] > 0) $bU['unstored'][$c['ordinal']] = $left[$c['id_bin']];
    }
    $C('rvf1-3: the batch commits', $su->commitBatch($ru, 'w1', $epU, $bU) === true);
    $C('rvf1-3: the run holds what its done records left out, and a record committed in any other state holds none',
        $openU() === 5);
    $su->advancePhase($ru, $epU, ScanPhase::CATCH_UP);
    $C('rvc2-2: a record sent back to be read again keeps its count until its next reading',
        $su->requeue($ru, $epU, [$hU('U1')]) === 1 && $openU() === 5);
    $C('rvf1-3: a record that left the run\'s group gives its count back',
        $su->depart($ru, $epU, [$hU('U2')]) === 1 && $openU() === 2);
    $C('rvf1-3: a record read again keeps only what its new reading left out',
        (function () use ($su, $ru, $epU, $openU) {
            $c = $su->claimPending($ru, 'w2', $epU, 10);
            if (!is_array($c) || count($c) !== 1) return false;
            $ok = $su->commitBatch($ru, 'w2', $epU, ['bytes' => 0, 'findings' => [],
                'records' => [['ordinal' => $c[0]['ordinal'], 'record_hash' => $c[0]['hash'],
                               'state' => ScanStore::REC_DONE, 'version' => '11', 'claim' => $c[0]['claim']]],
                'unstored' => [$c[0]['ordinal'] => 4]]) === true;
            return $ok && $openU() === 4;
        })());
    $C('rvf1-3: and a deleted record gives its count back',
        $su->tombstone($ru, $epU, [$hU('U1')]) === 1 && $openU() === 0);
    $C('rvf1-3: requeueing, departing or deleting again takes nothing more back',
        $su->requeue($ru, $epU, [$hU('U3')]) === 1 && $su->depart($ru, $epU, [$hU('U2')]) === 0
        && $su->tombstone($ru, $epU, [$hU('U1')]) === 0 && $openU() === 0);

    // -- what the run's records still hold, stored (review W5F-N2) ---------
    //
    // detail_rows counts every finding row the run wrote. open_findings
    // counts those of the readings its records hold, which are its active
    // rows: each held record keeps its stored count on its row, a deleted or
    // departed record takes it back with its rows, and a requeued one keeps
    // both until its next reading replaces them (reviews rvc2-2, rvc1-2).
    $sk = $newStore();
    $rk = (int) $sk->startRun(749, ['created_by' => 'alice'])['run']['run_id'];
    $rowK = $sk->run(749, $rk);
    $epK = (int) $rowK['lease_epoch'];
    $genK = (int) $rowK['generation_id'];
    $hK = function ($id) { return hash('sha256', $id, true); };
    $sk->writeManifest($rk, array_map(function ($id) use ($hK) {
        return ['id_bin' => $id, 'hash' => $hK($id), 'dag' => null];
    }, ['K1', 'K2', 'K3', 'K4']));
    $findingK = function ($ord, $id, $n) use ($genK, $rowK, $hK) {
        return ['ordinal' => $ord, 'project_id' => 749, 'generation_id' => $genK,
                'host_form' => 'fa', 'field' => 'x' . $n, 'check_type' => 'required',
                'reason_code' => 'required-blank', 'record_id_bin' => $id,
                'identity' => hash('sha256', 'k' . $id . '|' . $n, true),
                'valid_from_seq' => (int) $rowK['run_seq'], 'record_hash' => $hK($id),
                'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64)];
    };
    $openK = function () use ($sk, $rk) {
        $v = $sk->run(749, $rk)['open_findings'];
        return $v === null ? null : (int) $v;
    };
    $activeK = function () use ($sk, $genK) {
        return count($sk->findings(749, $genK, ['dag' => null], [], 0, 100));
    };
    $C('W5F-N2: a run created now starts holding no finding, not with no count', $openK() === 0);
    $bK = ['bytes' => 0, 'records' => [], 'findings' => []];
    foreach ($sk->claim($rk, 'w1', $epK, 4) as $c) {
        $bK['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'], 'version' => '10',
                            'state' => $c['id_bin'] === 'K4' ? ScanStore::REC_TOMBSTONE : ScanStore::REC_DONE,
                            'claim' => $c['claim']];
        $many = ['K1' => 2, 'K2' => 1, 'K3' => 0, 'K4' => 0];
        for ($i = 0; $i < $many[$c['id_bin']]; $i++) $bK['findings'][] = $findingK($c['ordinal'], $c['id_bin'], $i);
    }
    $C('W5F-N2: the batch commits', $sk->commitBatch($rk, 'w1', $epK, $bK) === true);
    $C('W5F-N2: the run holds the 3 findings its records stored, as the table does',
        $openK() === 3 && (int) $sk->run(749, $rk)['detail_rows'] === 3 && $activeK() === 3);
    $sk->advancePhase($rk, $epK, ScanPhase::CATCH_UP);
    $C('rvc2-2: a record sent back to be read again keeps its count and its findings, so the count is the rows',
        $sk->requeue($rk, $epK, [$hK('K1')]) === 1 && $openK() === 3 && $activeK() === 3);
    $C('W5F-N2: a record read again holds its new reading only, the old one superseded',
        (function () use ($sk, $rk, $epK, $openK, $activeK, $findingK) {
            $c = $sk->claimPending($rk, 'w2', $epK, 10);
            if (!is_array($c) || count($c) !== 1) return false;
            $ok = $sk->commitBatch($rk, 'w2', $epK, ['bytes' => 0,
                'findings' => [$findingK($c[0]['ordinal'], 'K1', 9)],
                'records' => [['ordinal' => $c[0]['ordinal'], 'record_hash' => $c[0]['hash'],
                               'state' => ScanStore::REC_DONE, 'version' => '11', 'claim' => $c[0]['claim']]]]) === true;
            return $ok && $openK() === 2 && $activeK() === 2
                && (int) $sk->run(749, $rk)['detail_rows'] === 4;
        })());
    $C('W5F-N2: a record that left the run\'s group gives its count back with its findings',
        $sk->depart($rk, $epK, [$hK('K2')]) === 1 && $openK() === 1 && $activeK() === 1);
    $C('W5F-N2: and a deleted record gives its count back with its findings',
        $sk->tombstone($rk, $epK, [$hK('K1')]) === 1 && $openK() === 0 && $activeK() === 0);
    $C('W5F-N2: requeueing, departing or deleting again takes nothing more back',
        $sk->requeue($rk, $epK, [$hK('K3')]) === 1 && $sk->depart($rk, $epK, [$hK('K2')]) === 0
        && $sk->tombstone($rk, $epK, [$hK('K1')]) === 0 && $openK() === 0
        && (int) $sk->run(749, $rk)['detail_rows'] === 4);

    // -- a requeued record's reading, replaced whatever comes next (reviews rvc2-2, rvc1-2)
    //
    // The run's count is its active rows in every state. requeue() leaves the
    // record its reading. The record's next commit replaces it in any
    // terminal state, so one that is then unreadable or unstable gives its
    // rows and counts up; so does one retired out of attempts, by
    // noteAttempts() or by a takeover in claimPending(). One written back
    // below done keeps its reading. These rows used to stay active with
    // nothing counting them.
    $sq = $newStore();
    $rq = (int) $sq->startRun(751, ['created_by' => 'alice'])['run']['run_id'];
    $rowQ = $sq->run(751, $rq);
    $epQ = (int) $rowQ['lease_epoch'];
    $genQ = (int) $rowQ['generation_id'];
    $hQ = function ($id) { return hash('sha256', 'q' . $id, true); };
    $sq->writeManifest($rq, array_map(function ($id) use ($hQ) {
        return ['id_bin' => $id, 'hash' => $hQ($id), 'dag' => null];
    }, ['Q1', 'Q2', 'Q3', 'Q4', 'Q5']));
    $countQ = function ($col) use ($sq, $rq) {
        $v = $sq->run(751, $rq)[$col];
        return $v === null ? null : (int) $v;
    };
    $activeQ = function () use ($sq, $genQ) {
        $out = [];
        foreach ($sq->findings(751, $genQ, ['dag' => null], [], 0, 100) as $f) $out[] = (string) $f['record_id_bin'];
        sort($out);
        return $out;
    };
    $bQ = ['bytes' => 0, 'records' => [], 'findings' => [], 'unstored' => []];
    foreach ($sq->claim($rq, 'w1', $epQ, 5) as $c) {
        $bQ['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'], 'version' => '10',
                            'state' => ScanStore::REC_DONE, 'claim' => $c['claim']];
        $bQ['findings'][] = ['ordinal' => $c['ordinal'], 'project_id' => 751, 'generation_id' => $genQ,
            'host_form' => 'fa', 'field' => 'x', 'check_type' => 'required', 'reason_code' => 'required-blank',
            'record_id_bin' => $c['id_bin'], 'identity' => hash('sha256', 'q|' . $c['id_bin'], true),
            'valid_from_seq' => (int) $rowQ['run_seq'], 'record_hash' => $c['hash'],
            'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64)];
        if ($c['id_bin'] === 'Q1') $bQ['unstored'][$c['ordinal']] = 2;
    }
    $C('rvc1-2: the fixture stores one finding for each of five records, and leaves two of one record out',
        $sq->commitBatch($rq, 'w1', $epQ, $bQ) === true && $countQ('open_findings') === 5
        && $countQ('open_unstored') === 2 && count($activeQ()) === 5);
    $sq->advancePhase($rq, $epQ, ScanPhase::CATCH_UP);
    $C('rvc2-2: four records sent back to be read again keep their readings: count, left-out count and rows',
        $sq->requeue($rq, $epQ, [$hQ('Q1'), $hQ('Q2'), $hQ('Q3'), $hQ('Q4')]) === 4
        && $countQ('open_findings') === 5 && $countQ('open_unstored') === 2 && count($activeQ()) === 5);
    $cQ = $sq->claimPending($rq, 'w2', $epQ, 10);
    $ordQ = [];
    foreach (is_array($cQ) ? $cQ : [] as $c) $ordQ[$c['id_bin']] = $c;
    $C('rvc1-2: the fixture takes the four back out', count($ordQ) === 4);
    $recQ = function ($id, $state) use ($ordQ) {
        return ['ordinal' => $ordQ[$id]['ordinal'], 'record_hash' => $ordQ[$id]['hash'], 'version' => '11',
                'state' => $state, 'claim' => $ordQ[$id]['claim']];
    };
    $okQ = count($ordQ) === 4 && $sq->commitBatch($rq, 'w2', $epQ, ['bytes' => 0, 'findings' => [],
        'records' => [$recQ('Q1', ScanStore::REC_UNREADABLE), $recQ('Q2', ScanStore::REC_UNSTABLE),
                      $recQ('Q3', ScanStore::REC_PENDING)]]) === true;
    $C('rvc1-2: a requeued record committed unreadable or unstable gives up its reading: rows and counts',
        $okQ && $countQ('open_findings') === 3 && $countQ('open_unstored') === 0
        && $activeQ() === ['Q3', 'Q4', 'Q5']);
    $C('rvc1-2: one written back to be read again keeps it',
        in_array('Q3', $activeQ(), true));
    $C('rvc1-2: a requeued record retired out of attempts gives up its reading',
        count($ordQ) === 4
        && $sq->noteAttempts($rq, $epQ, 'w2', [$ordQ['Q4']['ordinal'] => $ordQ['Q4']['claim']], 1,
                             ScanStore::REC_UNREADABLE)['retired'] === 1
        && $countQ('open_findings') === 2 && $activeQ() === ['Q3', 'Q5']);
    // Q3 is pending again with two attempts counted. A worker takes it and
    // dies; the takeover at the limit retires it.
    $dQ = $sq->claimPending($rq, 'w3', $epQ, 10);
    $age($sq, $rq, 1000);
    $retQ = -1;
    $C('rvc1-2: and so does one a takeover retires',
        is_array($dQ) && count($dQ) === 1
        && $sq->claimPending($rq, 'w4', $epQ, 10, 900, 3, ScanStore::REC_UNREADABLE, $retQ) === [] && $retQ === 1
        && $countQ('open_findings') === 1 && $activeQ() === ['Q5']);

    // A published duplicate group goes into the count with its rows
    // (ScanStore::notePublished(), reviews rvc2-2, CONS-N1).
    $sq->notePublished($rq, 3, 0);
    $C('CONS-N1: a published duplicate group adds the rows it made active', $countQ('open_findings') === 4);
    $sq->notePublished($rq, 2, 3);
    $C('CONS-N1: and takes back the rows of an earlier staging it closed', $countQ('open_findings') === 3);
    $sq->notePublished($rq, 0, 50);
    $C('CONS-N1: never below 0', $countQ('open_findings') === 0);
    if ($setRun !== null) {
        $setRun($sq, $rq, ['open_findings' => null]);
        $sq->notePublished($rq, 4, 0);
        $C('CONS-N1: and a run from before the count keeps NULL', $countQ('open_findings') === null);
    }

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
