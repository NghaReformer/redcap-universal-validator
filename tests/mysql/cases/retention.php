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
 * 801 is planted beside it with findings of its own, IN THE SAME GENERATION -
 * because a generation is per project now, so the first run of each of them is
 * generation 1, exactly as on a real installation.
 *
 * That arrangement is what makes the last assertion in this file the most
 * important one in the suite. purgeRuns takes a project id and then deletes
 * findings by generation; while the generation was the literal 1 everywhere and
 * uv_finding carried no project column, purging ONE finished run of ONE project
 * deleted every project's findings on the whole installation. It was reproduced
 * against a real server exactly this way - two projects, one purge, zero
 * findings left anywhere - and this case is where that stays reproduced.
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
        is_array($nb) && uv_neighbour_findings($dbA, $PID) === 2);

    $r = $store->startRun($PID, array('created_by' => 'alice'));
    $rid = (int) $r['run']['run_id'];
    $gen = (int) $r['run']['generation_id'];
    $store->writeManifest($rid, array(
        array('id_bin' => 'R1', 'hash' => hash('sha256', 'R1', true), 'dag' => null)));
    $seq = uv_run_seq($dbA, $rid);
    // THE TWO PROJECTS REALLY DO SHARE A GENERATION. Asserted rather than
    // assumed: every project-crossing claim in this file is worth nothing if
    // the two happen to have landed in different generation numbers, and that
    // is exactly how this suite used to arrange for its purges to look safe.
    check('retention: the neighbour shares this project\'s generation, as two projects do',
        (int) $nb['generation_id'] === $gen);
    $epoch = (int) $store->run($PID, $rid)['lease_epoch'];
    $claimed = $store->claim($rid, 'w', $epoch, 1);
    $store->commitBatch($rid, 'w', $epoch, array(
        'bytes' => 10,
        'records' => array(array('ordinal' => 1, 'claim' => $claimed[0]['claim'],
                                 'record_hash' => hash('sha256', 'R1', true),
            'state' => \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE)),
        'findings' => array(array(
            // commitBatch refuses a finding with no ordinal: it is what
            // attributes the evidence to a record this worker proved it
            // still holds. ScanWorker stamps it; a hand-built batch must.
            'ordinal' => 1,
            'project_id' => $PID,
            'generation_id' => $gen, 'identity' => hash('sha256', 'x', true),
            'valid_from_seq' => $seq,
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
    //
    // "NOTHING HAS MOVED" IS progress_at, NOT updated_at, and the difference is
    // the whole point of the column. updated_at moves on every touch, including
    // a touch that did nothing - so a worker looping on a batch it can never
    // commit keeps updated_at fresh forever and would never be reaped. This
    // test used to backdate updated_at alone, which stopped being the question
    // being asked; the run had committed a batch a moment earlier, so its
    // progress_at was genuinely current and refusing to expire it was correct.
    $A->query('UPDATE ' . Schema::table('scan_run')
        . " SET lease_expires_at = '2000-01-01 00:00:00' WHERE run_id = " . $rid);
    check('retention: a run that made progress recently is NOT abandoned, '
        . 'however old updated_at looks',
        $ret->expireAbandoned(1) === 0);

    // Now age the PROGRESS clock, which is the one the predicate reads.
    $A->query('UPDATE ' . Schema::table('scan_run')
        . " SET progress_at = '2000-01-01 00:00:00' WHERE run_id = " . $rid);
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
    $lf = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = ? AND generation_id = ?', array($PID, $gen));
    check('retention: and its findings, so no orphan outlives its run',
        (int) $lf[0][0] === 0);

    // THE ROOT CAUSE, REPRODUCED AGAINST A REAL SERVER.
    //
    // This is the check the whole two-project fixture was built for, and it was
    // red before wave 4. The two projects hold the same generation number - the
    // assertion near the top of this case says so - and purgeRuns deletes
    // findings, candidates, groups and dimensions by generation. Without the
    // project half of each of those four predicates, purging one finished run
    // of project 800 takes project 801's findings with it: a report that empties
    // itself because somebody else's scan aged out, which reads to the person
    // holding it as their project having no problems left.
    check('retention: purging one project leaves the neighbour\'s findings exactly where they were',
        uv_neighbour_findings($dbA, $PID) === 2);
    // And its run too. The findings are the disclosure-shaped half of the
    // failure; a neighbouring run deleted out from under a live worker is the
    // corruption-shaped half, and one predicate covers both.
    $nbRun = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_run')
        . ' WHERE project_id = ?', array($NEIGHBOUR));
    check('retention: and its run, which nothing about this purge concerns',
        (int) $nbRun[0][0] === 1);
}
