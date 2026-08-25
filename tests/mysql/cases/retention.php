<?php
/**
 * tests/mysql/cases/retention.php — three clocks, and nothing silently losing a
 * finding.
 *
 * Values expire, abandoned runs expire, and finished runs are purged. The three
 * are separate on purpose and the failure to engineer against is a report that
 * shrinks as it ages: that reads as the project having improved, which is the
 * misreading this module exists to prevent.
 *
 * TWO PROJECTS, AND ONE OF THE CLOCKS CROSSES THE LINE. 800 is under test and
 * 801 is planted beside it with findings of its own. Two of these clocks are
 * installation-wide by design - a cron owns them - and one, purgeRuns, takes a
 * project id and then deletes findings by GENERATION. Generations are handed out
 * per installation rather than per project today, so purging one project's run
 * reaches the other project's findings. Nothing here asserts that yet: the fix
 * is B1's, and an assertion written before it would land this refactor red. The
 * neighbour is here so that when the fix arrives the evidence is already in
 * place, and so no assertion below can quietly go back to being about a schema
 * that holds a single project.
 */

use INSPIRE\UniversalValidator\Scan\Schema;

$PID = 800;
$NEIGHBOUR = uv_neighbour($PID);

// -- ScanRetention: three clocks, and nothing silently loses a finding --------
{
    $ret = new \INSPIRE\UniversalValidator\Scan\ScanRetention($dbA);
    $store = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA);
    foreach (array('scan_record', 'finding', 'scan_run', 'scan_audit') as $t) {
        $A->query('DELETE FROM ' . Schema::table($t));
    }

    // The project next door, with its own run and its own findings, built
    // through the same store. Every count below now has to say which project it
    // is counting, and the two that did not have been scoped.
    $nb = uv_plant_neighbour($dbA, $PID);
    check('retention: a neighbouring project has findings of its own on record',
        is_array($nb) && uv_neighbour_findings($dbA) === 2);

    $r = $store->startRun($PID, array('created_by' => 'alice'));
    $rid = (int) $r['run']['run_id'];
    $gen = (int) $r['run']['generation_id'];
    $store->writeManifest($rid, array(
        array('id_bin' => 'R1', 'hash' => hash('sha256', 'R1', true), 'dag' => null)));
    $epoch = (int) $store->run($PID, $rid)['lease_epoch'];
    $store->claim($rid, 'w', $epoch, 1);
    $store->commitBatch($rid, 'w', $epoch, 0, array(
        'bytes' => 10,
        'records' => array(array('ordinal' => 1, 'state' => \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE)),
        'findings' => array(array(
            'generation_id' => $gen, 'identity' => hash('sha256', 'x', true), 'seq' => 1,
            'record_hash' => hash('sha256', 'R1', true), 'record_id_bin' => 'R1',
            'instance' => 1, 'host_form' => 'fa', 'field' => 'x', 'rule_source_id' => 'r1',
            'rule_revision' => str_repeat('c', 64), 'check_type' => 'required',
            'reason_code' => 'required-blank', 'value_bin' => 'SECRET',
            'value_expires_at' => '2000-01-01 00:00:00'))));

    check('retention: nothing expires before its time',
        $ret->expireValues('1999-01-01 00:00:00') === 0);
    check('retention: an overdue value is cleared', $ret->expireValues() === 1);
    $v = $ca->query('SELECT value_bin, value_expires_at FROM ' . Schema::table('finding')
        . " WHERE record_id_bin = 'R1'", array());
    check('retention: the value is gone', $v[0][0] === null && $v[0][1] === null);
    // The row survives: a report that shrinks as it ages reads as the project
    // having improved, which is the misreading this module exists to prevent.
    // NOT $n: check() keeps its counter in a global of that name, and assigning
    // to it here silently replaced an integer with a result set - the suite then
    // died incrementing an array, several checks later and nowhere near the
    // cause. Test-local names in a file that uses globals are a hazard.
    $kept = $ca->query('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . " WHERE record_id_bin = 'R1'", array());
    check('retention: but the FINDING remains', (int) $kept[0][0] === 1);

    // An active run is never purged, however old it looks.
    $A->query('UPDATE ' . Schema::table('scan_run')
        . " SET updated_at = '2000-01-01 00:00:00' WHERE run_id = " . $rid);
    check('retention: an ACTIVE run is never purged, whatever its age',
        $ret->purgeRuns($PID, 1) === 0);

    // Abandonment: the lease lapsed and nothing has moved.
    $A->query('UPDATE ' . Schema::table('scan_run')
        . " SET lease_expires_at = '2000-01-01 00:00:00' WHERE run_id = " . $rid);
    check('retention: an abandoned run is expired', $ret->expireAbandoned(1) === 1);
    $ex = $store->run(800, $rid);
    check('retention: as a real terminal state, never as complete',
        $ex['terminal'] === \INSPIRE\UniversalValidator\Scan\ScanOutcome::EXPIRED
        && $ex['coverage'] === \INSPIRE\UniversalValidator\Scan\ScanOutcome::COV_PARTIAL);
    check('retention: releasing the project slot for the next scan',
        $store->startRun($PID, array('created_by' => 'bob'))['ok'] === true);
    check('retention: and expiring it again does nothing', $ret->expireAbandoned(1) === 0);

    // Purge cascades to every child table.
    $A->query('UPDATE ' . Schema::table('scan_run')
        . " SET updated_at = '2000-01-01 00:00:00' WHERE run_id = " . $rid);
    check('retention: a finished, aged run purges', $ret->purgeRuns($PID, 1) === 1);
    $left = $ca->query('SELECT COUNT(*) FROM ' . Schema::table('scan_record')
        . ' WHERE run_id = ' . $rid, array());
    check('retention: taking its manifest rows with it', (int) $left[0][0] === 0);
    $lf = $ca->query('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE generation_id = ' . $gen, array());
    check('retention: and its findings, so no orphan outlives its run',
        (int) $lf[0][0] === 0);
}
