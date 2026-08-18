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
    $C('a claim at a stale epoch gets NOTHING', $s->claim($runId, 'w2', $epoch - 1, 2) === []);

    // -- committing ---------------------------------------------------------
    $batch = ['bytes' => 20, 'records' => [], 'findings' => []];
    foreach ($claim as $c) {
        $batch['records'][] = ['ordinal' => $c['ordinal'], 'state' => ScanStore::REC_DONE];
        $batch['findings'][] = ['generation_id' => 1, 'host_form' => 'fa', 'field' => 'x',
                                'check_type' => 'required', 'reason_code' => 'required-blank',
                                'record_id_bin' => $c['id_bin'],
                                'identity' => hash('sha256', 'f' . $c['ordinal'], true),
                                'seq' => 1, 'record_hash' => $c['hash'],
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
    $C('a fenced batch commits', $s->commitBatch($runId, 'w1', $epoch, 0, $batch) === true);
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
             'records' => [['ordinal' => 3, 'state' => ScanStore::REC_DONE]],
             'findings' => [['generation_id' => 1, 'host_form' => 'fa', 'field' => 'y',
                             'check_type' => 'required', 'reason_code' => 'required-blank',
                             'record_id_bin' => 'REC-3',
                             'identity' => hash('sha256', 'lost', true), 'seq' => 1,
                             'record_hash' => hash('sha256', 'REC-3', true),
                             'rule_source_id' => 'r1',
                             'rule_revision' => str_repeat('c', 64)]]];
    $C('an overtaken worker cannot commit',
        $s->commitBatch($runId, 'w1', $epoch, 0, $lost) === false);
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
        $batch['records'][] = ['ordinal' => $c['ordinal'], 'state' => ScanStore::REC_DONE];
    }
    $C('and commits what it finished', $s3->commitBatch($rid3, 'w1', $ep3, 0, $batch) === true);
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
        $s3->commitBatch($rid3, 'w2', $ep3, 0, ['bytes' => 0, 'findings' => [],
            'records' => [['ordinal' => $left[0]['ordinal'], 'state' => ScanStore::REC_DONE]]]) === true);
    $C('and the manifest is then complete', $s3->manifestComplete($rid3) === true);

    // Progress counts what BECAME terminal, not what was offered. A record
    // re-offered after a requeue would otherwise push the figure past the total.
    $C('progress never exceeds the manifest',
        (int) $s3->run(710, $rid3)['manifest_done'] === 4);
    $C('re-committing a finished record does not advance it again',
        $s3->commitBatch($rid3, 'w2', $ep3, 0, ['bytes' => 0, 'findings' => [],
            'records' => [['ordinal' => $first[0]['ordinal'], 'state' => ScanStore::REC_DONE]]]) === true
        && (int) $s3->run(710, $rid3)['manifest_done'] === 4);

    // Fencing applies to the straggler sweep exactly as it does to the cursor.
    $s4 = $newStore();
    $r4 = $s4->startRun(711, []);
    $rid4 = (int) $r4['run']['run_id'];
    $s4->writeManifest($rid4, [$mk('Z')]);
    $ep4 = (int) $s4->run(711, $rid4)['lease_epoch'];
    $C('a stale epoch claims no stragglers either',
        $s4->claimPending($rid4, 'w', $ep4 - 1, 5) === []);
    $C('a cancelled run offers no stragglers',
        $s4->cancel(711, $rid4, 'admin') === true
        && $s4->claimPending($rid4, 'w', (int) $s4->run(711, $rid4)['lease_epoch'], 5) === []);

    // -- worker slots -------------------------------------------------------
    $s2 = $newStore();
    $a = $s2->leaseSlot('w1', 1, 60);
    $b = $s2->leaseSlot('w2', 1, 60);
    $C('slots lease up to the configured count', $a !== null && $b !== null);
    $C('and the next worker finds none free', $s2->leaseSlot('w3', 1, 60) === null);
    $C('a stale holder releases nothing',
        $s2->releaseSlot($a['slot_no'], 'w1', $a['epoch'] + 5) === false);
    $C('someone else releases nothing either',
        $s2->releaseSlot($a['slot_no'], 'impostor', $a['epoch']) === false);
    $C('the real holder releases', $s2->releaseSlot($a['slot_no'], 'w1', $a['epoch']) === true);
    $C('freeing it for the next worker', $s2->leaseSlot('w3', 1, 60) !== null);
}
