<?php
/**
 * tests/mysql/cases/rollup.php — the summary, and the two ways a summary lies.
 *
 * A summary is the first thing a reader believes and the last thing anyone
 * checks. The two failures worth engineering against are both silent: counting
 * a page twice (the cursor and the counters were not written together) and
 * counting rows the report will not show (closed versions of findings that were
 * superseded).
 *
 * TWO PROJECTS, AND THE NEIGHBOUR HAS FINDINGS OF ITS OWN. It used to be the one
 * case that planted a neighbour with nothing in it, and the reason was the bug:
 * RollupBuilder aggregated a GENERATION, generations were handed out per
 * installation, and two findings belonging to project 951 were counted into
 * project 950's instrument axis - so every total below would have read 62 and
 * the case would have been red for a defect it was not written to find.
 *
 * The generation is per project now and step() takes one, so the neighbour holds
 * its two findings IN THE SAME GENERATION NUMBER as this project's sixty, and
 * every axis total below is an assertion that the summary counts this project
 * and not the installation. That is the third way a summary lies, and it is the
 * one nobody could see: an inflated total points at findings the list underneath
 * it will never show, and a reader cannot reconcile the two.
 */

use INSPIRE\UniversalValidator\Scan\Schema;

$PID = 950;
$NEIGHBOUR = uv_neighbour($PID);

{
    $store = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA);
    // The neighbouring run AND its findings - see the file header.
    $nb = uv_plant_neighbour($dbA, $PID);
    check('rollup: a second project has a run in the same tables',
        is_array($nb) && (int) $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_run')
            . ' WHERE project_id = ' . $NEIGHBOUR)[0][0] === 1);
    check('rollup: with two findings of its own that no total here may include',
        uv_neighbour_findings($dbA, $PID) === 2);
    $r = $store->startRun($PID, array('created_by' => 'alice', 'fence_open' => '10'));
    $rid = (int) $r['run']['run_id'];
    $gen = (int) $r['run']['generation_id'];
    // The two projects really are in one generation. Without this the axis
    // totals below would be about a schema that had partitioned itself, which
    // is the arrangement this whole fixture exists to stop making.
    check('rollup: in the generation this project is also using',
        (int) $nb['generation_id'] === $gen);
    $store->writeManifest($rid, array(
        array('id_bin' => 'R1', 'hash' => hash('sha256', 'R1', true), 'dag' => null)));
    $epoch = (int) $store->run($PID, $rid)['lease_epoch'];

    // 60 active findings across two instruments, three reasons and two groups,
    // plus 5 CLOSED ones that no report will show.
    $forms   = array('demographics', 'chest_xray');
    $reasons = array('required-blank', 'out-of-range', 'check-character');
    $dags    = array('site_a', 'site_b');
    $rows = array();
    for ($i = 1; $i <= 60; $i++) {
        $rows[] = "(" . $PID . ", " . $gen . ", UNHEX(SHA2('f" . $i . "', 256)), 1, 1,
            UNHEX(SHA2('R1', 256)), 'R1', 'x', '" . $forms[$i % 2] . "', 0, 'assert',
            '" . $reasons[$i % 3] . "', '" . $dags[$i % 2] . "', '" . str_repeat('c', 64) . "', 'r1')";
    }
    for ($i = 61; $i <= 65; $i++) {
        // active_slot NULL: superseded by a later version of the same finding.
        $rows[] = "(" . $PID . ", " . $gen . ", UNHEX(SHA2('f" . $i . "', 256)), 1, NULL,
            UNHEX(SHA2('R1', 256)), 'R1', 'x', 'demographics', 0, 'assert',
            'required-blank', 'site_a', '" . str_repeat('c', 64) . "', 'r1')";
    }
    $A->query('INSERT INTO ' . Schema::table('finding') . '
        (project_id, generation_id, finding_identity, valid_from_seq, active_slot, record_hash,
         record_id_bin, field, host_form, rule_ord, check_type, reason_code, dag_key,
         rule_revision, rule_source_id) VALUES ' . implode(',', $rows));

    $roll = new \INSPIRE\UniversalValidator\Scan\RollupBuilder($dbA, $store);

    // Bounded pages, and the cursor survives them. Seven pages of ten.
    $pages = 0;
    while ($pages++ < 50) {
        $st = $roll->step($PID, $rid, $epoch, $gen, 10);
        if ($st['done']) break;
    }
    check('rollup: the summary is built in bounded pages', $pages > 1 && $pages < 50);
    check('rollup: and the cursor reached the last active finding',
        (int) $store->progressState($rid)['rollupCursor'] > 0);

    $by = array();
    foreach ($store->aggregates($rid) as $a) {
        $by[$a['kind']] = (isset($by[$a['kind']]) ? $by[$a['kind']] : 0) + (int) $a['cnt'];
    }
    // Every axis must total the same thing: the number of findings a reader
    // will actually see. An axis that disagreed would be a summary nobody could
    // reconcile against the list below it.
    //
    // SIXTY, NOT SIXTY-TWO. The neighbouring project holds two active findings
    // in this same generation, so each of these four numbers is also the answer
    // to "does the summary know which project it is summarising". A rollup that
    // lost its project predicate reads 62 on every one of them at once.
    check('rollup: the instrument axis totals the active findings',
        isset($by[\INSPIRE\UniversalValidator\Scan\RollupBuilder::K_FORM]) && $by[\INSPIRE\UniversalValidator\Scan\RollupBuilder::K_FORM] === 60);
    check('rollup: so does the reason axis',
        isset($by[\INSPIRE\UniversalValidator\Scan\RollupBuilder::K_REASON]) && $by[\INSPIRE\UniversalValidator\Scan\RollupBuilder::K_REASON] === 60);
    check('rollup: so does the group axis',
        isset($by[\INSPIRE\UniversalValidator\Scan\RollupBuilder::K_DAG]) && $by[\INSPIRE\UniversalValidator\Scan\RollupBuilder::K_DAG] === 60);
    check('rollup: so does the check axis',
        isset($by[\INSPIRE\UniversalValidator\Scan\RollupBuilder::K_TYPE]) && $by[\INSPIRE\UniversalValidator\Scan\RollupBuilder::K_TYPE] === 60);
    // The five closed rows are kept so an "as of run N" view stays reproducible,
    // and counting them would report a problem twice.
    check('rollup: superseded findings are NOT counted', $by[\INSPIRE\UniversalValidator\Scan\RollupBuilder::K_FORM] !== 65);

    $split = array();
    foreach ($store->aggregates($rid) as $a) {
        if ($a['kind'] === \INSPIRE\UniversalValidator\Scan\RollupBuilder::K_FORM) $split[$a['axis1']] = (int) $a['cnt'];
    }
    check('rollup: and it splits by instrument rather than lumping',
        count($split) === 2 && array_sum($split) === 60);

    // Running it again from a settled cursor adds nothing. This is the
    // idempotence a refreshed browser tab depends on.
    $roll->step($PID, $rid, $epoch, $gen, 10);
    $after = array();
    foreach ($store->aggregates($rid) as $a) {
        $after[$a['kind']] = (isset($after[$a['kind']]) ? $after[$a['kind']] : 0) + (int) $a['cnt'];
    }
    check('rollup: a finished summary does not grow when it is asked again',
        $after[\INSPIRE\UniversalValidator\Scan\RollupBuilder::K_FORM] === 60);

    // A PAGE WHOSE CURSOR CANNOT BE WRITTEN MUST NOT BE COUNTED. The counters
    // add, so a page applied twice inflates the summary against the findings it
    // describes - and nothing downstream can detect that. The write is one
    // transaction; here the lease has moved, so it rolls back.
    $A->query('DELETE FROM ' . Schema::table('scan_aggregate'));
    $store->setProgressState($rid, $epoch, array('rollupCursor' => 0));
    $stale = $roll->step($PID, $rid, $epoch + 99, $gen, 10);
    check('rollup: a page whose lease has moved is not counted',
        $stale['done'] === false && $stale['rows'] === 0);
    $none = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_aggregate')
        . ' WHERE run_id = ?', array($rid));
    check('rollup: it wrote nothing at all, rather than half a page',
        (int) $none[0][0] === 0);
    check('rollup: and the cursor did not move either',
        (int) $store->progressState($rid)['rollupCursor'] === 0);
}
