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
        // Counted as the finalizer counts what it publishes, in the publish
        // transaction (ScanStore::notePublished(), reviews rvc2-2, CONS-N1).
        if ($dupes > 0) $store->notePublished($rid, $dupes, 0);
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
        } elseif ($how === 'examined') {
            // R2O-1. EXAMINED FIRST, THEN DELETED - the commonest way to an
            // empty scope, and the one the stored sentence denied. Each record
            // is read and stored DONE with a finding, then catch-up finds it
            // gone and tombstones it through the store path CatchUp::step()
            // takes. Since W5F-N4 that closes the findings, so the summary
            // has none of them to count.
            $claimed = $store->claim($rid, 'w', $epoch, 5);
            $batch = array('bytes' => 0, 'records' => array(), 'findings' => array());
            foreach ($claimed as $c) {
                $batch['records'][] = array('ordinal' => $c['ordinal'], 'claim' => $c['claim'],
                    'version' => '500', 'record_hash' => $c['hash'],
                    'state' => \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE);
                $batch['findings'][] = array('ordinal' => $c['ordinal'], 'project_id' => $pid,
                    'generation_id' => $gen, 'identity' => hash('sha256', 'ex-' . $c['id_bin'], true),
                    'valid_from_seq' => uv_run_seq($dbA, $rid),
                    'record_hash' => $c['hash'], 'record_id_bin' => $c['id_bin'],
                    'event_id' => null, 'instance' => 1, 'host_form' => 'fa', 'field' => 'x',
                    'rule_source_id' => 'r1', 'rule_revision' => str_repeat('e', 64), 'rule_ord' => 1,
                    'check_type' => 'required', 'reason_code' => 'required-blank');
            }
            $store->commitBatch($rid, 'w', $epoch, $batch);
            $store->advancePhase($rid, $epoch, \INSPIRE\UniversalValidator\Scan\ScanPhase::CATCH_UP);
            $store->tombstone($rid, $epoch, array(hash('sha256', 'G1', true), hash('sha256', 'G2', true)));
        } else {
            $A->query('DELETE FROM ' . Schema::table('scan_record') . ' WHERE run_id = ' . $rid);
        }
        foreach (array(\INSPIRE\UniversalValidator\Scan\ScanPhase::CATCH_UP,
                       \INSPIRE\UniversalValidator\Scan\ScanPhase::UNIQUE,
                       \INSPIRE\UniversalValidator\Scan\ScanPhase::ROLLUP) as $ph) {
            if ($how === 'examined' && $ph === \INSPIRE\UniversalValidator\Scan\ScanPhase::CATCH_UP) continue;
            $store->advancePhase($rid, $epoch, $ph);
        }
        $store->setProgressState($rid, $epoch, array('fenceTarget' => '500', 'rollupCursor' => 0));
        $roll = new \INSPIRE\UniversalValidator\Scan\RollupBuilder($dbA, $store);
        while ($roll->step($pid, $rid, $epoch, $gen, 100)['done'] === false) { }
        $p = \INSPIRE\UniversalValidator\Scan\ScanPromotion::promote($store, $pid, $rid,
            array('uniqueDone' => true, 'rollupDone' => true,
                  'blockingAggregates' => $store->blockingAggregates($rid)));
        $raw = $A->query('SELECT terminal, coverage, clean IS NULL, clean, manifest_total,
                terminal_reason
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

    // A RUN CANCELLED DURING UNIQUE-FINALIZE (review OC-4). The finalizer has
    // written two duplicates as active findings, the counter reads 0, and no
    // summary was built. The page read that 0 as "Nothing found yet"; what it
    // is told now is that the findings were not counted. A RUN FROM BEFORE
    // SCHEMA VERSION 8 (both counts NULL), whose finalizer moved no count; a
    // later run's publish counts its duplicates (notePublished(), reviews
    // rvc2-2, CONS-N1), and cases/counting.php stops one in unique-finalize.
    $r = $store->startRun(954, array('created_by' => 'alice', 'fence_open' => '10'));
    $rid = (int) $r['run']['run_id'];
    $gen = (int) $r['run']['generation_id'];
    $A->query('UPDATE ' . Schema::table('scan_run')
        . ' SET open_unstored = NULL, open_findings = NULL WHERE run_id = ' . $rid);
    $store->writeManifest($rid, array(
        array('id_bin' => 'D1', 'hash' => hash('sha256', 'D1', true), 'dag' => null),
        array('id_bin' => 'D2', 'hash' => hash('sha256', 'D2', true), 'dag' => null)));
    $epoch = (int) $store->run(954, $rid)['lease_epoch'];
    $claimed = $store->claim($rid, 'w', $epoch, 5);
    $rows = array();
    foreach ($claimed as $c) {
        $rows[] = array('ordinal' => $c['ordinal'], 'claim' => $c['claim'], 'version' => '500',
            'record_hash' => $c['hash'], 'state' => \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE);
    }
    $store->commitBatch($rid, 'w', $epoch, array('bytes' => 0, 'findings' => array(), 'records' => $rows));
    $store->advancePhase($rid, $epoch, \INSPIRE\UniversalValidator\Scan\ScanPhase::CATCH_UP);
    $store->advancePhase($rid, $epoch, \INSPIRE\UniversalValidator\Scan\ScanPhase::UNIQUE);
    for ($i = 0; $i < 2; $i++) {
        $st = $A->prepare('INSERT INTO ' . Schema::table('finding') . '
            (project_id, generation_id, finding_identity, valid_from_seq, active_slot,
             record_hash, record_id_bin, event_id, instance, host_form, field, rule_source_id,
             rule_revision, rule_ord, check_type, reason_code, reason_bits, severity,
             group_hmac, stage_epoch)
            VALUES (?, ?, ?, 1, 1, ?, ?, 1, 1, \'fa\', \'mrn\', \'r-unique\', ?, 0, \'unique\',
                    \'duplicate\', 0, 1, ?, 1)');
        $pidC = 954;
        $ident = hash('sha256', 'dupe-cancel-' . $i, true);
        $rh = hash('sha256', 'D' . ($i + 1), true);
        $rb = 'D' . ($i + 1);
        $rev = str_repeat('d', 64);
        $grp = hash('sha256', 'group-cancel', true);
        $st->bind_param('iisssss', $pidC, $gen, $ident, $rh, $rb, $rev, $grp);
        $st->execute();
    }
    $store->cancel(954, $rid, 'alice');
    $store->finish($rid, \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));
    $run = $store->run(954, $rid);
    $active = (int) $A->query('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = 954 AND generation_id = ' . $gen . ' AND active_slot = 1')->fetch_row()[0];
    check('cancelled in unique-finalize: two duplicates are active and the counter never moved',
        $run['terminal'] === 'cancelled' && $active === 2 && (int) $run['detail_rows'] === 0);
    check('cancelled in unique-finalize: the page is told they were not counted, never zero',
        \INSPIRE\UniversalValidator\Scan\ScanPromotion::findingsOf($store, $run) === null);

    // FINDINGS THE DETAIL BUDGET LEFT OUT, read back from the server (reviews
    // rv10b1-3, rv10b1-5). A commit holding a record whose 2 findings were not
    // stored writes them to the DETAIL_UNSTORED aggregate; the counter says 3
    // were stored. While the run works and once it is cancelled, the count is
    // the 3 and the 2 together.
    $r = $store->startRun(962, array('created_by' => 'alice', 'fence_open' => '10'));
    $rid = (int) $r['run']['run_id'];
    $store->writeManifest($rid, array(array('id_bin' => 'U1', 'hash' => hash('sha256', 'U1', true), 'dag' => null)));
    $epoch = (int) $store->run(962, $rid)['lease_epoch'];
    $claimed = $store->claim($rid, 'w', $epoch, 5);
    $rows = array();
    $left = array();
    foreach ($claimed as $c) {
        $rows[] = array('ordinal' => $c['ordinal'], 'claim' => $c['claim'], 'version' => '500',
            'record_hash' => $c['hash'], 'state' => \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE);
        $left[(int) $c['ordinal']] = 2;
    }
    $okU = $store->commitBatch($rid, 'w', $epoch, array('bytes' => 0, 'findings' => array(), 'records' => $rows,
                                                        'unstored' => $left));
    // Both counters: every row written, and (W5F-N2) the rows the run holds.
    $A->query('UPDATE ' . Schema::table('scan_run') . ' SET detail_rows = 3, open_findings = 3 WHERE run_id = ' . $rid);
    check('detail budget: the commit holding a record left out 2 of its findings',
        $okU === true && count($claimed) === 1);
    check('rv10b1-3: a run still working counts the 2 left out with the 3 stored',
        \INSPIRE\UniversalValidator\Scan\ScanPromotion::findingsOf($store, $store->run(962, $rid)) === 5);
    $store->cancel(962, $rid, 'alice');
    $store->finish($rid, \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));
    check('rv10b1-5: and so does the run once it is cancelled, with no summary built',
        \INSPIRE\UniversalValidator\Scan\ScanPromotion::findingsOf($store, $store->run(962, $rid)) === 5);

    list($p, $raw, $states) = $finishEmpty(959, 'emptied');
    check('nothing examined: a manifest emptied after it froze still says it had two records',
        (int) $raw[4] === 2 && $states === array());
    check('nothing examined: and finishes as an empty scope, stored not clean',
        $p['promoted'] === true && $raw[1] === 'empty-scope' && (int) $raw[2] === 0
        && (int) $raw[3] === 0);

    // R2O-1, ON A REAL SERVER. Every record examined, a finding stored for
    // each, then every one deleted. The classification is the one above - no
    // record is left to vouch for, so never clean - and the stored reason must
    // be true of this run as well as of the one that read nothing.
    list($p, $raw, $states) = $finishEmpty(957, 'examined');
    $rid957 = (int) $A->query('SELECT MAX(run_id) FROM ' . Schema::table('scan_run')
        . ' WHERE project_id = 957')->fetch_row()[0];
    $run957 = $store->run(957, $rid957);
    check('R2O-1: the fixture examined both records, stored a finding for each, then deleted both',
        $states === array(\INSPIRE\UniversalValidator\Scan\ScanStore::REC_TOMBSTONE => 2)
        && (int) $run957['detail_rows'] === 2);
    // W5F-N4: deleting them closed both findings, so the summary the real
    // rollup built counts none, and nothing of theirs is still active.
    $active957 = $A->query('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = 957 AND active_slot = 1')->fetch_row()[0];
    check('W5F-N4: the tombstones closed both findings, and the finished run counts none of them',
        (int) $active957 === 0
        && \INSPIRE\UniversalValidator\Scan\ScanPromotion::findingsOf($store, $run957) === 0);
    check('R2O-1: it is an empty scope, partial, stored NOT clean',
        $p['promoted'] === true && $raw[0] === 'partial' && $raw[1] === 'empty-scope'
        && (int) $raw[2] === 0 && (int) $raw[3] === 0);
    check('R2O-1: its stored reason does not say no record was examined',
        stripos((string) $raw[5], 'no record was examined') === false
        && stripos((string) $raw[5], 'before it could be read') === false);
    check('R2O-1: it says no record is left, deleted whether or not examined, findings and all - whole',
        strpos((string) $raw[5], 'no record is left') === 0
        && strpos((string) $raw[5], 'whether or not it had been examined') !== false
        && strpos((string) $raw[5], 'any findings it kept are on deleted records') !== false
        && $raw[5] === $p['outcome']['why']);
}

// R2O-5, ON A REAL SERVER. Stop on a run nobody is working ends it in the same
// request and frees the slot; the worker whose batch was in flight is refused
// when it comes back, and writes nothing. A run a worker does hold is left in
// `cancelling` for that worker, as it was.
{
    $store = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA);
    $stopRun = new \ReflectionMethod('INSPIRE\\UniversalValidator\\Scan\\ScanService', 'stopRun');
    $stopRun->setAccessible(true);
    // The body uses only the store it is handed.
    $svc = new \INSPIRE\UniversalValidator\Scan\ScanService(new \stdClass());
    $T = Schema::table('scan_run');
    $open = function ($pid) use ($store) {
        $r = $store->startRun($pid, array('created_by' => 'alice', 'fence_open' => '10'));
        $rid = (int) $r['run']['run_id'];
        $store->writeManifest($rid, array(
            array('id_bin' => 'S1', 'hash' => hash('sha256', 'S1', true), 'dag' => null),
            array('id_bin' => 'S2', 'hash' => hash('sha256', 'S2', true), 'dag' => null)));
        return $rid;
    };
    $before = function ($pid, $rid) use ($store, $A, $T) {
        $run = $store->run($pid, $rid);
        $run['lease_expires_at'] = $A->query('SELECT lease_expires_at FROM ' . $T
            . ' WHERE run_id = ' . (int) $rid)->fetch_row()[0];
        return $run;
    };

    $rid = $open(960);
    $epoch = (int) $store->run(960, $rid)['lease_epoch'];
    $gen = (int) $store->run(960, $rid)['generation_id'];
    $claimed = $store->claim($rid, 'w', $epoch, 1);
    // The tab that took that batch was closed: its lease has run out.
    $A->query('UPDATE ' . $T . " SET lease_expires_at = UTC_TIMESTAMP() - INTERVAL 5 SECOND,
        updated_at = UTC_TIMESTAMP() - INTERVAL 10 MINUTE WHERE run_id = " . (int) $rid);
    $res = $stopRun->invoke($svc, $store, 960, $rid, 'alice', $before(960, $rid), time());
    $raw = $A->query('SELECT phase, terminal, active_slot FROM ' . $T . ' WHERE run_id = '
        . (int) $rid)->fetch_row();
    check('R2O-5: Stop on a run nobody holds ends it in the same request, as cancelled',
        $res['ok'] === true && $res['stopped'] === true
        && $raw[0] === 'terminal' && $raw[1] === 'cancelled' && $raw[2] === null);
    check('R2O-5: and the project slot is free - a new scan starts',
        $store->startRun(960, array('created_by' => 'bob'))['ok'] === true);
    $batch = array('bytes' => 0, 'records' => array(), 'findings' => array());
    foreach ($claimed as $c) {
        $batch['records'][] = array('ordinal' => $c['ordinal'], 'claim' => $c['claim'],
            'version' => '500', 'record_hash' => $c['hash'],
            'state' => \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE);
        $batch['findings'][] = array('ordinal' => $c['ordinal'], 'project_id' => 960,
            'generation_id' => $gen, 'identity' => hash('sha256', 'late-' . $c['id_bin'], true),
            'valid_from_seq' => uv_run_seq($dbA, $rid),
            'record_hash' => $c['hash'], 'record_id_bin' => $c['id_bin'],
            'event_id' => null, 'instance' => 1, 'host_form' => 'fa', 'field' => 'x',
            'rule_source_id' => 'r1', 'rule_revision' => str_repeat('f', 64), 'rule_ord' => 1,
            'check_type' => 'required', 'reason_code' => 'required-blank');
    }
    check('R2O-5: the worker that comes back with its batch is refused',
        count($claimed) === 1 && $store->commitBatch($rid, 'w', $epoch, $batch) !== true);
    check('R2O-5: and cannot claim more', $store->claim($rid, 'w', $epoch, 5) === false);
    $late = (int) $A->query('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = 960 AND generation_id = ' . $gen)->fetch_row()[0];
    $after = $store->run(960, $rid);
    check('R2O-5: so the finished run holds nothing it wrote after the stop',
        $late === 0 && (int) $after['detail_rows'] === 0 && $after['terminal'] === 'cancelled'
        && !isset($store->recordStates($rid)[\INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE]));

    // A WORKER HOLDS IT: claimed a moment ago, lease live. Left for the worker.
    $rid = $open(961);
    $epoch = (int) $store->run(961, $rid)['lease_epoch'];
    $store->claim($rid, 'w', $epoch, 1);
    $res = $stopRun->invoke($svc, $store, 961, $rid, 'alice', $before(961, $rid), time());
    $raw = $A->query('SELECT phase, terminal, active_slot FROM ' . $T . ' WHERE run_id = '
        . (int) $rid)->fetch_row();
    check('R2O-5: a run a worker holds is only asked to stop, and keeps its slot for that worker',
        $res['ok'] === true && $res['stopped'] === false
        && $raw[0] === 'cancelling' && $raw[1] === null && (int) $raw[2] === 1);
    check('R2O-5: pressing Stop again ends it, since nothing holds a stopping run',
        $stopRun->invoke($svc, $store, 961, $rid, 'alice', $before(961, $rid), time())['stopped'] === true
        && $store->run(961, $rid)['terminal'] === 'cancelled');
}

// R3U-3, ON A REAL SERVER. One stopped run, one row, whichever request ends
// it. Stop on a run nobody drives used to write derive(['cancelled' => true])
// with no counts, while promote() wrote the same stopped run with its tally:
// detail `complete` beside `truncated`, 0 beside 1 rule problem, 0 beside 1
// record not examined, 0 beside 2 gaps.
if (!class_exists('UvBudgetModule')) {
    /** Settings only: a detail budget of two findings, everything else the default. */
    class UvBudgetModule {
        public function getSystemSetting($k) { return null; }
        public function getProjectSetting($k, $pid = null) {
            return $k === 'scan-max-detail-findings' ? '2' : null;
        }
    }
}
{
    $store = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA);
    $stopRun = new \ReflectionMethod('INSPIRE\\UniversalValidator\\Scan\\ScanService', 'stopRun');
    $stopRun->setAccessible(true);
    $svc = new \INSPIRE\UniversalValidator\Scan\ScanService(new UvBudgetModule());
    $T = Schema::table('scan_run');
    $build = function ($pid) use ($store, $dbA) {
        $r = $store->startRun($pid, array('created_by' => 'alice', 'fence_open' => '10'));
        $rid = (int) $r['run']['run_id'];
        $m = array();
        foreach (array('U1', 'U2', 'U3', 'U4') as $id) {
            $m[] = array('id_bin' => $id, 'hash' => hash('sha256', $id, true), 'dag' => null);
        }
        $store->writeManifest($rid, $m);
        \INSPIRE\UniversalValidator\Scan\ScanPromotion::noteRuleProblems($store, $rid,
            array(array('rule' => 3, 'why' => 'rule 3 is broken', 'code' => 'c3')));
        $store->addAggregate($rid, \INSPIRE\UniversalValidator\Scan\ScanPromotion::GAP_KINDS, 'fa', 'x', 2, 0);
        $run = $store->run($pid, $rid);
        $ep = (int) $run['lease_epoch'];
        $seq = uv_run_seq($dbA, $rid);
        $b = array('bytes' => 0, 'records' => array(), 'findings' => array());
        foreach ($store->claim($rid, 'w', $ep, 3) as $i => $c) {
            $b['records'][] = array('ordinal' => $c['ordinal'], 'claim' => $c['claim'],
                'version' => $i < 2 ? '500' : null, 'record_hash' => $c['hash'],
                'state' => $i < 2 ? \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE
                                  : \INSPIRE\UniversalValidator\Scan\ScanStore::REC_UNREADABLE);
            if ($i < 2) {
                $b['findings'][] = array('ordinal' => $c['ordinal'], 'project_id' => $pid,
                    'generation_id' => (int) $run['generation_id'],
                    'identity' => hash('sha256', 'f-' . $pid . '-' . $c['id_bin'], true),
                    'valid_from_seq' => $seq, 'record_hash' => $c['hash'], 'record_id_bin' => $c['id_bin'],
                    'event_id' => null, 'instance' => 1, 'host_form' => 'fa', 'field' => 'x',
                    'rule_source_id' => 'r1', 'rule_revision' => str_repeat('f', 64), 'rule_ord' => 1,
                    'check_type' => 'required', 'reason_code' => 'required-blank');
                // The budget of two was met (H9): the second record found one
                // more finding, which the gate left out and counted.
                if ($i === 1) $b['unstored'] = array((int) $c['ordinal'] => 1);
            }
        }
        return array($rid, $store->commitBatch($rid, 'w', $ep, $b) === true);
    };
    $row = function ($rid) use ($A, $T) {
        $r = $A->query('SELECT phase, terminal, coverage, detail, clean, rule_problem_count, not_examined,
            gap_count FROM ' . $T . ' WHERE run_id = ' . (int) $rid)->fetch_assoc();
        foreach ($r as $k => $v) $r[$k] = $v === null ? null : (string) $v;
        return $r;
    };
    $before = function ($pid, $rid) use ($store, $A, $T) {
        $run = $store->run($pid, $rid);
        $run['lease_expires_at'] = $A->query('SELECT lease_expires_at FROM ' . $T
            . ' WHERE run_id = ' . (int) $rid)->fetch_row()[0];
        return $run;
    };

    list($ridS, $okS) = $build(970);
    $A->query('UPDATE ' . $T . ' SET updated_at = UTC_TIMESTAMP() - INTERVAL 10 MINUTE WHERE run_id = ' . (int) $ridS);
    $res = $stopRun->invoke($svc, $store, 970, $ridS, 'alice', $before(970, $ridS), time());
    list($ridP, $okP) = $build(972);
    $store->cancel(972, $ridP, 'alice');
    $pol = \INSPIRE\UniversalValidator\Scan\ScanPolicy::resolve(array(), array('scan-max-detail-findings' => '2'));
    \INSPIRE\UniversalValidator\Scan\ScanPromotion::promote($store, 972, $ridP, array('uniqueDone' => false,
        'uniqueBlocking' => 0, 'rollupDone' => false, 'maxFindings' => $pol['maxFindings'],
        'maxBytes' => $pol['maxBytes'], 'crossRecordLookups' => false));
    check('R3U-3: the fixture committed both batches', $okS && $okP);
    check('R3U-3: Stop on a run nobody drives ends it', $res['ok'] === true && $res['stopped'] === true);
    check('R3U-3: and stores the row promote() stores for the same stopped run', $row($ridS) === $row($ridP));
    check('R3U-3: the truncation, the rule problem, the record not examined and the gaps, all kept',
        $row($ridS) === array('phase' => 'terminal', 'terminal' => 'cancelled', 'coverage' => 'partial',
            'detail' => 'truncated', 'clean' => null, 'rule_problem_count' => '1', 'not_examined' => '1',
            'gap_count' => '2'));
}

// R3U-5, ON A REAL SERVER. Two tabs press Stop together, on two connections:
// A bumps, B bumps, B finishes, and A's finish then changes nothing. A was
// answered ok:true stopped:false over a run that had ended, and its page said
// "Stopping the scan." beside the finished panel. A asks again now.
if (!class_exists('UvFinishHookDb')) {
    /** A ScanDb that runs $beforeFinish once, just before the first terminal write. */
    final class UvFinishHookDb implements \INSPIRE\UniversalValidator\Scan\ScanDb {
        public $inner; public $beforeFinish;
        public function __construct($inner) { $this->inner = $inner; }
        public function select($sql, array $params = []) { return $this->inner->select($sql, $params); }
        public function exec($sql, array $params = []) {
            if ($this->beforeFinish && strpos($sql, 'SET phase = ?, terminal = ?') !== false) {
                $h = $this->beforeFinish; $this->beforeFinish = null; $h();
            }
            return $this->inner->exec($sql, $params);
        }
        public function execNoCount($sql, array $params = []) { return $this->inner->execNoCount($sql, $params); }
        public function affected() { return $this->inner->affected(); }
        public function begin() { $this->inner->begin(); }
        public function commit() { $this->inner->commit(); }
        public function rollback() { $this->inner->rollback(); }
    }
}
{
    $storeB = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbB);
    $hook = new UvFinishHookDb(new MysqliDb($A));
    $storeA = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($hook);
    $stopRun = new \ReflectionMethod('INSPIRE\\UniversalValidator\\Scan\\ScanService', 'stopRun');
    $stopRun->setAccessible(true);
    $svc = new \INSPIRE\UniversalValidator\Scan\ScanService(new \stdClass());
    $T = Schema::table('scan_run');
    $r = $storeB->startRun(974, array('created_by' => 'alice', 'fence_open' => '10'));
    $rid = (int) $r['run']['run_id'];
    $storeB->writeManifest($rid, array(array('id_bin' => 'T1', 'hash' => hash('sha256', 'T1', true), 'dag' => null)));
    $beforeOf = function () use ($storeB, $B, $T, $rid) {
        $run = $storeB->run(974, $rid);
        $run['lease_expires_at'] = $B->query('SELECT lease_expires_at FROM ' . $T
            . ' WHERE run_id = ' . (int) $rid)->fetch_row()[0];
        return $run;
    };
    $resB = null;
    $hook->beforeFinish = function () use (&$resB, $stopRun, $svc, $storeB, $rid, $beforeOf) {
        $resB = $stopRun->invoke($svc, $storeB, 974, $rid, 'tab-B', $beforeOf(), time());
    };
    $resA = $stopRun->invoke($svc, $storeA, 974, $rid, 'tab-A', $beforeOf(), time());
    $end = $storeB->run(974, $rid);
    check('R3U-5: tab B, which finished the run, is told it stopped',
        is_array($resB) && $resB['ok'] === true && $resB['stopped'] === true);
    check('R3U-5: tab A, whose finish found it already ended, is told it stopped too',
        $resA['ok'] === true && $resA['stopped'] === true && $resA['why'] === null);
    check('R3U-5: and the run did end, cancelled, once',
        $end['phase'] === 'terminal' && $end['terminal'] === 'cancelled');
}
