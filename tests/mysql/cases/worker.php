<?php
/**
 * tests/mysql/cases/worker.php — ScanWorker against the real store and a real
 * fence.
 *
 * The fast suite drives ScanWorker against the in-memory store, where the fence
 * is an array a test can move. What it cannot show is the worker meeting a real
 * transaction: the commit that rolls back, the claim that a second connection
 * cannot repeat, the version read that goes to a log table. Those are here.
 *
 * TWO PROJECTS. 900 is under test and 901 is planted with a run and findings of
 * its own, in the same generation. It matters most in the two rollback
 * assertions: "not one buffered finding reached the table" is a claim about this
 * run, and asked of the whole table it answers the same way on a schema that
 * only ever held one project.
 */

use INSPIRE\UniversalValidator\Scan\Schema;

$PID = 900;
$NEIGHBOUR = uv_neighbour($PID);

{
    uv_redcap_schema($A);
    uv_redcap_log_schema($A);
    uv_redcap_project($A, $PID);
    uv_redcap_neighbour_records($A, $PID);
    uv_redcap_neighbour_log($A, $PID);

    $store = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA);
    $planner = new \INSPIRE\UniversalValidator\Scan\ScanPlanner($store, 'test-secret-key');
    $baseReq = uv_plan_request();

    $nb = uv_plant_neighbour($dbA, $PID);
    check('worker: a second project is on the same store with findings of its own',
        is_array($nb) && uv_neighbour_findings($dbA, $PID) === 2);

    $A->query('DELETE FROM redcap_record_list WHERE project_id = ' . $PID);
    for ($i = 1; $i <= 12; $i++) {
        $A->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES (" . $PID . ", 'W"
            . $i . "', NULL)");
    }
    $A->query('DELETE FROM redcap_log_event WHERE project_id = ' . $PID);
    for ($i = 1; $i <= 12; $i++) {
        $A->query('INSERT INTO redcap_log_event (log_event_id, project_id, pk, event) VALUES ('
            . (1000 + $i) . ", " . $PID . ", 'W" . $i . "', 'UPDATE')");
    }
    uv_clear_project($dbA, $PID);

    $srcW = \INSPIRE\UniversalValidator\Scan\RecordManifestSource::open($dbA, $PID, array('pk' => 'record_id'));
    $fenceW = \INSPIRE\UniversalValidator\Scan\SourceFence::forProject($dbA, $PID);
    check('worker: the run it will work has a real fence', $fenceW['ok'] === true);
    $planned = $planner->plan($PID, array_merge($baseReq, array(
        'source' => $srcW['source'], 'fence' => $fenceW['fence'], 'pageSize' => 5)));
    check('worker: and a manifest of every record',
        $planned['ok'] === true && (int) $planned['run']['manifest_total'] === 12);
    $wrid = (int) $planned['run']['run_id'];
    $gen = (int) $planned['run']['generation_id'];
    $seq = uv_run_seq($dbA, $wrid);

    $readAll = function ($ids) {
        $out = array();
        foreach ($ids as $id) $out[$id] = array('1' => array('x' => ''));
        return array('ok' => true, 'data' => $out, 'why' => null);
    };
    // ScanWorker passes an evaluator's findings to the store UNCHANGED - it
    // stamps the record state and the record hash, and nothing else - so the
    // project and the run sequence are the evaluator's to supply here exactly
    // as they are ScanService's to supply in production.
    $findOne = function ($id, $node) use ($gen, $seq, $PID) {
        return array('bytes' => 12, 'contexts' => 1, 'why' => null, 'findings' => array(array(
            'project_id' => $PID,
            'generation_id' => $gen, 'identity' => hash('sha256', 'w' . $id, true),
            'valid_from_seq' => $seq,
            'record_hash' => hash('sha256', $id, true), 'record_id_bin' => $id,
            'instance' => 1, 'host_form' => 'f', 'field' => 'x', 'rule_source_id' => 'r1',
            'rule_revision' => str_repeat('c', 64), 'check_type' => 'required',
            'reason_code' => 'required-blank')));
    };
    $slots = new \INSPIRE\UniversalValidator\Scan\WorkerSlots($dbA);
    $A->query('DELETE FROM ' . Schema::table('scan_worker_slot'));
    $slots->provision(2);

    $worker = new \INSPIRE\UniversalValidator\Scan\ScanWorker($store, array(
        'slots' => $slots, 'fence' => $fenceW['fence'], 'read' => $readAll,
        'evaluate' => $findOne, 'owner' => 'browser-1', 'attempts' => 3,
        'budget' => new \INSPIRE\UniversalValidator\Scan\WorkBudget(array('mode' => 'cron', 'memoryLimit' => null,
            'timeLimit' => null, 'min' => 1, 'max' => 5, 'first' => 5,
            'startedAt' => microtime(true)))));
    $wres = $worker->work($PID, $wrid);
    check('worker: every record is examined against a real store', $wres['worked'] === 12);
    check('worker: and every finding is stored', $wres['findings'] === 12);
    // SCOPED BY PROJECT AND GENERATION, which is what a report query is scoped
    // by. Both projects' findings really do sit in the same generation number -
    // generation 1 is the first run of every project - so "how many findings
    // does generation 1 hold" is still a question about the installation. It is
    // the project half that turns it into a question about this run, and asking
    // it by record-id prefix instead would answer the same on a row written
    // with the wrong project id.
    $stored = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = ? AND generation_id = ?', array($PID, $gen));
    check('worker: the findings are really in the table', (int) $stored[0][0] === 12);
    check('worker: the manifest is complete as a predicate over states',
        $store->manifestComplete($wrid) === true);
    check('worker: and progress equals the manifest, never more',
        (int) $store->run($PID, $wrid)['manifest_done'] === 12);
    // The installation slot is a resource, not a lock the worker keeps.
    check('worker: the installation slot is handed back when the request ends',
        $slots->census()['held'] === 0);

    // A REAL EDIT DURING A REAL RUN. The record is touched in the log between
    // the worker's two version reads, which is the race the protocol exists for.
    uv_clear_project($dbA, $PID);
    $planned2 = $planner->plan($PID, array_merge($baseReq, array(
        'source' => $srcW['source'], 'fence' => $fenceW['fence'], 'pageSize' => 20)));
    $wrid2 = (int) $planned2['run']['run_id'];
    $gen2 = (int) $planned2['run']['generation_id'];
    $touched = false;
    $readAndEdit = function ($ids) use (&$touched, $A, $readAll, $PID) {
        if (!$touched) {
            $touched = true;
            // Someone saves W3 while the export is running.
            $A->query("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event)
                VALUES (2000, " . $PID . ", 'W3', 'UPDATE')");
        }
        return $readAll($ids);
    };
    $findNone = function ($id, $node) {
        return array('bytes' => 0, 'contexts' => 1, 'why' => null, 'findings' => array());
    };
    $worker2 = new \INSPIRE\UniversalValidator\Scan\ScanWorker($store, array(
        'slots' => $slots, 'fence' => $fenceW['fence'], 'read' => $readAndEdit,
        'evaluate' => $findNone, 'owner' => 'browser-2', 'attempts' => 3,
        'budget' => new \INSPIRE\UniversalValidator\Scan\WorkBudget(array('mode' => 'cron', 'memoryLimit' => null,
            'timeLimit' => null, 'min' => 1, 'max' => 20, 'first' => 20,
            'startedAt' => microtime(true)))));
    $wres2 = $worker2->work($PID, $wrid2);
    check('worker: a record saved during the export is requeued rather than reported',
        $wres2['requeued'] === 1);
    check('worker: and examined once it is stable', $wres2['worked'] === 12);
    check('worker: so the run still covers every record',
        $store->manifestComplete($wrid2) === true);
    $store->finish($wrid2, \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('fenced' => true, 'manifestDone' => true)));

    // A CANCEL FROM ANOTHER CONNECTION, arriving while this worker evaluates.
    // The epoch bump is what makes it beat the worker; nothing the worker had
    // buffered may reach the tables.
    uv_clear_project($dbA, $PID);
    $planned3 = $planner->plan($PID, array_merge($baseReq, array(
        'source' => $srcW['source'], 'fence' => $fenceW['fence'], 'pageSize' => 20)));
    $wrid3 = (int) $planned3['run']['run_id'];
    $gen3 = (int) $planned3['run']['generation_id'];
    $seq3 = uv_run_seq($dbA, $wrid3);
    $storeB = new \INSPIRE\UniversalValidator\Scan\SqlScanStore(new MysqliDb($B));
    $cancelMidway = function ($id, $node) use ($storeB, $wrid3, $gen3, $seq3, $PID) {
        // The SECOND connection cancels, exactly as an administrator's request
        // in another tab would.
        $storeB->cancel($PID, $wrid3, 'admin');
        return array('bytes' => 1, 'contexts' => 1, 'why' => null, 'findings' => array(array(
            'project_id' => $PID,
            'generation_id' => $gen3, 'identity' => hash('sha256', 'c' . $id, true),
            'valid_from_seq' => $seq3,
            'record_hash' => hash('sha256', $id, true), 'record_id_bin' => $id,
            'instance' => 1, 'host_form' => 'f', 'field' => 'x', 'rule_source_id' => 'r1',
            'rule_revision' => str_repeat('c', 64), 'check_type' => 'required',
            'reason_code' => 'required-blank')));
    };
    $worker3 = new \INSPIRE\UniversalValidator\Scan\ScanWorker($store, array(
        'slots' => $slots, 'fence' => $fenceW['fence'], 'read' => $readAll,
        'evaluate' => $cancelMidway, 'owner' => 'browser-3', 'attempts' => 3,
        'budget' => new \INSPIRE\UniversalValidator\Scan\WorkBudget(array('mode' => 'cron', 'memoryLimit' => null,
            'timeLimit' => null, 'min' => 1, 'max' => 20, 'first' => 20,
            'startedAt' => microtime(true)))));
    $wres3 = $worker3->work($PID, $wrid3);
    check('worker: a cancel from another connection stops the worker at its fence',
        $wres3['ok'] === false && $wres3['stop'] === 'fenced');
    $leftF = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = ? AND generation_id = ?', array($PID, $gen3));
    check('worker: and not one buffered finding reached the table',
        (int) $leftF[0][0] === 0);
    check('worker: while the neighbouring project keeps every finding it had',
        uv_neighbour_findings($dbA, $PID) === 2);
    $leftD = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_record')
        . ' WHERE run_id = ? AND state >= ?', array($wrid3, \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE));
    check('worker: nor was any record marked as examined', (int) $leftD[0][0] === 0);
    check('worker: the slot is still returned when the worker stops early',
        $slots->census()['held'] === 0);
    $A->query('UPDATE ' . Schema::table('scan_run')
        . " SET active_slot = NULL, phase = 'terminal', terminal = 'cancelled'
            WHERE run_id = " . $wrid3);

    foreach (array('redcap_log_event', 'redcap_record_list', 'redcap_data',
                   'redcap_projects') as $t) {
        $A->query('DROP TABLE IF EXISTS ' . $t);
    }
}
