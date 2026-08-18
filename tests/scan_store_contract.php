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
    $C('a fenced batch commits', $s->commitBatch($runId, 'w1', $epoch, 0, $batch) === true);
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
