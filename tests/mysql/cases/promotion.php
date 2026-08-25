<?php
/**
 * tests/mysql/cases/promotion.php — which terminal state a run has earned.
 *
 * Promotion is where a run stops being a process and becomes a claim, so the
 * only interesting assertions are the refusals: a manifest with a record still
 * pending, and a window nobody proved stood still. "Complete" and
 * "manifest-complete" are different sentences and the run has to be able to say
 * which one it is entitled to.
 *
 * TWO PROJECTS. 950 is under test and 951 is planted beside it, because the last
 * thing promotion does is release the project's scan slot - and a slot released
 * for the right project is indistinguishable from a slot released for all of
 * them when there is only one project in the schema.
 */

use INSPIRE\UniversalValidator\Scan\Schema;

$PID = 950;
$NEIGHBOUR = uv_neighbour($PID);

{
    $store = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA);
    $nb = uv_plant_neighbour($dbA, $PID);
    check('promote: a second project holds a run of its own throughout',
        is_array($nb) && (int) $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_run')
            . ' WHERE project_id = ' . $NEIGHBOUR . ' AND active_slot = 1')[0][0] === 1);

    // One record, one opening fence and no target fence: the state in which
    // "every record examined" and "the project stood still while we did it" come
    // apart, which is the only state promotion has to think about.
    $r = $store->startRun($PID, array('created_by' => 'alice', 'fence_open' => '10'));
    $rid = (int) $r['run']['run_id'];
    $gen = (int) $r['run']['generation_id'];
    $store->writeManifest($rid, array(
        array('id_bin' => 'R1', 'hash' => hash('sha256', 'R1', true), 'dag' => null)));
    $epoch = (int) $store->run($PID, $rid)['lease_epoch'];
    $roll = new \INSPIRE\UniversalValidator\Scan\RollupBuilder($dbA, $store);

    $store->setProgressState($rid, $epoch, array('rollupCursor' => 0));
    // THE PROJECT IS THE FIRST ARGUMENT NOW. It was called with four arguments
    // after the signature grew one, so $rid arrived as the project and $epoch as
    // the run - progressState() then answered null and the loop exited on its
    // first turn having summarised nothing. It cost nothing here, because this
    // block only needs the rollup to be settled, and that is exactly why a
    // wrong call could sit in it unnoticed.
    while ($roll->step($PID, $rid, $epoch, $gen, 100)['done'] === false) { }

    // Still scanning, with a record pending: not promotable, whatever else is
    // true. A cursor at the end of the manifest is not a manifest at its end.
    $p = \INSPIRE\UniversalValidator\Scan\ScanPromotion::promote($store, $PID, $rid, array('uniqueDone' => true,
        'rollupDone' => true));
    check('promote: a run with a record still pending is refused',
        $p['promoted'] === false && strpos($p['why'], 'still waiting') !== false);

    // Finish the record and walk the phases to the end.
    $claimed = $store->claim($rid, 'w', $epoch, 5);
    $store->commitBatch($rid, 'w', $epoch, 0, array('bytes' => 0, 'findings' => array(),
        'records' => array(array('ordinal' => $claimed[0]['ordinal'],
                                 'state' => \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE, 'version' => '500'))));
    $store->advancePhase($rid, $epoch, \INSPIRE\UniversalValidator\Scan\ScanPhase::CATCH_UP);
    $store->advancePhase($rid, $epoch, \INSPIRE\UniversalValidator\Scan\ScanPhase::UNIQUE);
    $store->advancePhase($rid, $epoch, \INSPIRE\UniversalValidator\Scan\ScanPhase::ROLLUP);

    // No target fence yet: every record examined, and no proof the project
    // stood still. That is a different sentence from complete, and the run has
    // to be able to say which.
    $p = \INSPIRE\UniversalValidator\Scan\ScanPromotion::promote($store, $PID, $rid,
        array('uniqueDone' => true, 'rollupDone' => true));
    check('promote: without a proved window the run finishes as manifest-complete',
        $p['promoted'] === true
        && $p['outcome']['coverage'] === \INSPIRE\UniversalValidator\Scan\ScanOutcome::MANIFEST);
    check('promote: with a filename suffix that says so',
        $p['outcome']['suffix'] === '_MANIFEST_ONLY');
    check('promote: the terminal state is stored on the run',
        $store->run($PID, $rid)['terminal'] === \INSPIRE\UniversalValidator\Scan\ScanOutcome::PARTIAL);
    check('promote: and the project slot is released',
        $store->startRun($PID, array('created_by' => 'bob'))['ok'] === true);
    check('promote: a second finaliser cannot reopen it',
        \INSPIRE\UniversalValidator\Scan\ScanPromotion::promote($store, $PID, $rid, array())['promoted'] === false);
    check('promote: and the neighbouring project\'s slot was never touched',
        (int) $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_run')
            . ' WHERE project_id = ' . $NEIGHBOUR . ' AND active_slot = 1')[0][0] === 1);

}
