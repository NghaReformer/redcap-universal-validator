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
    $store->commitBatch($rid, 'w', $epoch, array('bytes' => 0, 'findings' => array(),
        'records' => array(array('ordinal' => $claimed[0]['ordinal'],
                                 'claim' => $claimed[0]['claim'],
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

// VIOLATIONS ARE ACTIVE FINDINGS (B4a), on a real server and through the real
// summary.
//
// The duplicates-only project. UniqueFinalizer writes its findings straight
// into the findings table, so the run row's detail_rows - which only
// commitBatch() moves - stays at 0 however many it writes. The verdict used to
// read that counter, and a project whose one problem was duplicated values was
// certified clean. The row below is written the way the finalizer writes one:
// no batch, no counter, active. What has to find it is RollupBuilder, and what
// has to believe RollupBuilder is promote().
{
    $store = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA);
    $finish = function ($pid, $dupes, $unstored) use ($store, $dbA, $A) {
        $r = $store->startRun($pid, array('created_by' => 'alice', 'fence_open' => '10'));
        $rid = (int) $r['run']['run_id'];
        $gen = (int) $r['run']['generation_id'];
        $recs = array();
        foreach (array('D1', 'D2') as $id) {
            $recs[] = array('id_bin' => $id, 'hash' => hash('sha256', $id, true), 'dag' => null);
        }
        $store->writeManifest($rid, $recs);
        $epoch = (int) $store->run($pid, $rid)['lease_epoch'];
        $claimed = $store->claim($rid, 'w', $epoch, 5);
        $rows = array();
        foreach ($claimed as $i => $c) {
            $rows[] = array('ordinal' => $c['ordinal'], 'claim' => $c['claim'], 'version' => '500',
                'record_hash' => $c['hash'],
                'state' => ($unstored && $i === 1)
                    ? \INSPIRE\UniversalValidator\Scan\ScanStore::REC_UNSTORED
                    : \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE);
        }
        $store->commitBatch($rid, 'w', $epoch, array('bytes' => 0, 'findings' => array(), 'records' => $rows));
        for ($i = 0; $i < $dupes; $i++) {
            $st = $A->prepare('INSERT INTO ' . Schema::table('finding') . '
                (project_id, generation_id, finding_identity, valid_from_seq, active_slot,
                 record_hash, record_id_bin, event_id, instance, host_form, field, rule_source_id,
                 rule_revision, rule_ord, check_type, reason_code, reason_bits, severity,
                 group_hmac, stage_epoch)
                VALUES (?, ?, ?, 1, 1, ?, ?, 1, 1, \'fa\', \'mrn\', \'r-unique\', ?, 0, \'unique\',
                        \'duplicate\', 0, 1, ?, 1)');
            $ident = hash('sha256', 'dupe-' . $pid . '-' . $i, true);
            $rh = hash('sha256', 'D' . ($i + 1), true);
            $rid_bin = 'D' . ($i + 1);
            $rev = str_repeat('d', 64);
            $grp = hash('sha256', 'group-' . $pid, true);
            $st->bind_param('iisssss', $pid, $gen, $ident, $rh, $rid_bin, $rev, $grp);
            $st->execute();
        }
        foreach (array(\INSPIRE\UniversalValidator\Scan\ScanPhase::CATCH_UP,
                       \INSPIRE\UniversalValidator\Scan\ScanPhase::UNIQUE,
                       \INSPIRE\UniversalValidator\Scan\ScanPhase::ROLLUP) as $ph) {
            $store->advancePhase($rid, $epoch, $ph);
        }
        $store->setProgressState($rid, $epoch, array('fenceTarget' => '500', 'rollupCursor' => 0));
        $roll = new \INSPIRE\UniversalValidator\Scan\RollupBuilder($dbA, $store);
        while ($roll->step($pid, $rid, $epoch, $gen, 100)['done'] === false) { }
        // The caller CLAIMS nothing was found. It is not the caller's to say.
        $p = \INSPIRE\UniversalValidator\Scan\ScanPromotion::promote($store, $pid, $rid,
            array('uniqueDone' => true, 'rollupDone' => true, 'violations' => 0, 'ruleProblems' => 0));
        $raw = $A->query('SELECT terminal, coverage, clean IS NULL, clean, not_examined, detail_rows
            FROM ' . Schema::table('scan_run') . ' WHERE run_id = ' . $rid)->fetch_row();
        return array($p, $raw, $store->run($pid, $rid));
    };

    list($p, $raw, $row) = $finish(955, 2, false);
    check('duplicates only: no worker stored a finding, so the counter reads zero',
        (int) $raw[5] === 0);
    check('duplicates only: the run is complete over a proved window',
        $p['promoted'] === true && $raw[0] === 'complete' && $raw[1] === 'complete-through-fence');
    check('duplicates only: and it is NOT clean - a stored 0, from a summary that counted two',
        (int) $raw[2] === 0 && (int) $raw[3] === 0
        && \INSPIRE\UniversalValidator\Scan\ScanPromotion::findingsOf($store, $row) === 2);

    list($p, $raw, $row) = $finish(956, 0, false);
    check('duplicates only: the control - the same run with none - is a stored 1',
        $p['promoted'] === true && $raw[0] === 'complete' && (int) $raw[2] === 0 && (int) $raw[3] === 1);

    // ONE RECORD WHOSE RESULT COULD NOT BE STORED. It was not examined, so the
    // run cannot say complete, and the row says how many.
    list($p, $raw, $row) = $finish(957, 0, true);
    check('unstored: a record whose result was never stored makes the run partial',
        $p['promoted'] === true && $raw[0] === 'partial' && $raw[1] === 'partial');
    check('unstored: not clean, and counted on the row as the one record not examined',
        (int) $raw[2] === 0 && (int) $raw[3] === 0 && (int) $raw[4] === 1);
}

// NOTHING EXAMINED IS NOT A PASS (review OC-1), on a real server.
//
// Two runs that examined nothing and were stored clean=1: one whose every record
// was deleted before it could be read (two tombstones - the census summed them,
// so the run was not "empty"), and one whose manifest was emptied after it froze
// (manifest_total 2, no rows - the total said it was not empty). Both are
// finished, fenced and found nothing, so the census is the only thing that can
// refuse them.
{
    $store = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA);
    $finishEmpty = function ($pid, $how) use ($store, $dbA, $A) {
        $r = $store->startRun($pid, array('created_by' => 'alice', 'fence_open' => '10'));
        $rid = (int) $r['run']['run_id'];
        $gen = (int) $r['run']['generation_id'];
        $store->writeManifest($rid, array(
            array('id_bin' => 'G1', 'hash' => hash('sha256', 'G1', true), 'dag' => null),
            array('id_bin' => 'G2', 'hash' => hash('sha256', 'G2', true), 'dag' => null)));
        $epoch = (int) $store->run($pid, $rid)['lease_epoch'];
        if ($how === 'tombstoned') {
            $claimed = $store->claim($rid, 'w', $epoch, 5);
            $rows = array();
            foreach ($claimed as $c) {
                $rows[] = array('ordinal' => $c['ordinal'], 'claim' => $c['claim'], 'version' => null,
                    'record_hash' => $c['hash'],
                    'state' => \INSPIRE\UniversalValidator\Scan\ScanStore::REC_TOMBSTONE);
            }
            $store->commitBatch($rid, 'w', $epoch, array('bytes' => 0, 'findings' => array(),
                'records' => $rows));
        } else {
            $A->query('DELETE FROM ' . Schema::table('scan_record') . ' WHERE run_id = ' . $rid);
        }
        foreach (array(\INSPIRE\UniversalValidator\Scan\ScanPhase::CATCH_UP,
                       \INSPIRE\UniversalValidator\Scan\ScanPhase::UNIQUE,
                       \INSPIRE\UniversalValidator\Scan\ScanPhase::ROLLUP) as $ph) {
            $store->advancePhase($rid, $epoch, $ph);
        }
        $store->setProgressState($rid, $epoch, array('fenceTarget' => '500', 'rollupCursor' => 0));
        $roll = new \INSPIRE\UniversalValidator\Scan\RollupBuilder($dbA, $store);
        while ($roll->step($pid, $rid, $epoch, $gen, 100)['done'] === false) { }
        $p = \INSPIRE\UniversalValidator\Scan\ScanPromotion::promote($store, $pid, $rid,
            array('uniqueDone' => true, 'rollupDone' => true,
                  'blockingAggregates' => $store->blockingAggregates($rid)));
        $raw = $A->query('SELECT terminal, coverage, clean IS NULL, clean, manifest_total
            FROM ' . Schema::table('scan_run') . ' WHERE run_id = ' . $rid)->fetch_row();
        return array($p, $raw, $store->recordStates($rid));
    };

    list($p, $raw, $states) = $finishEmpty(958, 'tombstoned');
    check('nothing examined: the fixture deleted both records, and examined neither',
        (int) array_sum($states) === 2
        && !isset($states[\INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE]));
    check('nothing examined: a run whose every record was deleted finishes as an empty scope',
        $p['promoted'] === true && $raw[0] === 'partial' && $raw[1] === 'empty-scope');
    check('nothing examined: and is stored NOT clean - a 0, never the 1 it used to be',
        (int) $raw[2] === 0 && (int) $raw[3] === 0);

    list($p, $raw, $states) = $finishEmpty(959, 'emptied');
    check('nothing examined: a manifest emptied after it froze still says it had two records',
        (int) $raw[4] === 2 && $states === array());
    check('nothing examined: and finishes as an empty scope, stored not clean',
        $p['promoted'] === true && $raw[1] === 'empty-scope' && (int) $raw[2] === 0
        && (int) $raw[3] === 0);
}
