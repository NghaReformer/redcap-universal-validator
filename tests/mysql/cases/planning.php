<?php
/**
 * tests/mysql/cases/planning.php — from a project to a frozen manifest.
 *
 * Run against the real record source and the real store, because planning is
 * almost entirely the joins between them - a planner tested against a fake
 * source would prove that the fake agrees with the planner.
 *
 * TWO PROJECTS. 900 is under test and 901 is planted in the record index, the
 * data table and the log, with its newest log entry above every entry here. A
 * manifest total, an out-of-scope count and an opening fence are each a claim
 * about ONE project, and each of them is a plausible-looking number when it is
 * wrong.
 *
 * THE GROUP FILTER IS STILL HANDED IN AS A LITERAL '7'. That is the shape of the
 * problem this fixture exists to expose rather than a decision made here: the
 * scoped-run assertions below inject the group id the code is supposed to derive
 * from the group NAME the page passes. Wave 6 owns that comparison; when it
 * lands, the derivation belongs in this fixture and the literal goes.
 */

use INSPIRE\UniversalValidator\Scan\Schema;

$PID = 900;
$NEIGHBOUR = uv_neighbour($PID);

{
    uv_redcap_schema($A);
    uv_redcap_log_schema($A);
    uv_redcap_project($A, $PID);
    uv_redcap_neighbour_records($A, $PID);
    // The log this project has already accumulated. The opening fence is its
    // top, and the assertion below names 190 exactly, so this is a fixture with
    // a number in it rather than a fixture with a shape.
    $A->query("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event) VALUES
        (130, " . $PID . ", NULL, 'DATA_EXPORT'), (140, " . $PID . ", 'D3', 'UPDATE'),
        (160, " . $PID . ", 'D2', 'UPDATE'),  (170, " . $PID . ", 'D4', 'INSERT'),
        (180, " . $PID . ", 'D2', 'UPDATE'),  (190, " . $PID . ", '', 'MANAGE')");
    uv_redcap_neighbour_log($A, $PID);

    for ($i = 1; $i <= 25; $i++) {
        $A->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES ("
            . $PID . ", '" . sprintf('P%03d', $i) . "', "
            . ($i % 3 === 0 ? '7' : 'NULL') . ')');
    }
    // The neighbour, in the store as well as in the record tables: a planner
    // that walked the whole installation would find its records, and a store
    // that started runs installation-wide would find its run.
    $nb = uv_plant_neighbour($dbA, $PID);
    check('plan: a second project has records and a run of its own',
        is_array($nb) && uv_neighbour_findings($dbA, $PID) === 2);

    $store = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA);
    $planner = new \INSPIRE\UniversalValidator\Scan\ScanPlanner($store, 'test-secret-key');
    $srcP = \INSPIRE\UniversalValidator\Scan\RecordManifestSource::open($dbA, $PID, array('pk' => 'record_id'));
    $fenceP = \INSPIRE\UniversalValidator\Scan\SourceFence::forProject($dbA, $PID);
    $baseReq = array(
        'source' => $srcP['source'],
        'fence' => $fenceP['ok'] ? $fenceP['fence'] : null,
        'rules' => array(array('type' => 'required', 'fields' => array('dob'))),
        'settingsCount' => 0,
        'ownership' => array('dob' => 'demographics'),
        'structure' => array('longitudinal' => false),
        'choices' => array(),
        'policy' => \INSPIRE\UniversalValidator\Scan\ScanPolicy::resolve(),
        'engine' => '1.8.20',
        'createdBy' => 'alice',
        'pageSize' => 10,
    );

    $res = $planner->plan($PID, $baseReq);
    check('plan: a project with records and rules plans', $res['ok'] === true);
    check('plan: every record reaches the manifest',
        (int) $res['run']['manifest_total'] === 25);
    check('plan: and the run is ready to be worked',
        $res['run']['phase'] === 'scanning');
    check('plan: the walk was paged rather than read whole', $res['stats']['pages'] >= 3);
    check('plan: the opening fence is captured BEFORE the manifest',
        $dbA->select('SELECT fence_open FROM ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run')
            . ' WHERE run_id = ?', array($res['run']['run_id']))[0][0] === '190');

    // The project slot is the mutual exclusion, and busy must disclose nothing.
    $busy = $planner->plan($PID, $baseReq);
    check('plan: a second start is busy rather than a second run',
        $busy['ok'] === false && $busy['busy'] === true && $busy['run'] === null);
    check('plan: and names neither the owner nor any number',
        strpos($busy['why'], 'alice') === false && preg_match('/[0-9]/', $busy['why']) === 0);

    $rid = (int) $res['run']['run_id'];
    $fp1 = $res['run']['fingerprint'];
    $store->finish($rid, \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));

    // A group-scoped run must carry a group-scoped manifest. Building the whole
    // project and filtering at display time is the leak the persisted store
    // creates.
    $scoped = $planner->plan($PID, array_merge($baseReq, array('dagFilter' => '7')));
    check('plan: a group-scoped run lists only that group',
        $scoped['ok'] === true && (int) $scoped['run']['manifest_total'] === 8);
    check('plan: and says how many records it left out',
        $scoped['stats']['outOfScope'] === 17);
    check('plan: the scope is stored on the run, not applied later',
        $scoped['run']['scope_dag'] === '7');
    $store->finish((int) $scoped['run']['run_id'],
        \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));

    // Editing a rule must change what a later run compares against.
    $moved = $planner->plan($PID, array_merge($baseReq, array(
        'rules' => array(array('type' => 'required', 'fields' => array('dob'),
                               'when' => '[age] > 18')))));
    check('plan: changing a rule changes the run fingerprint',
        $moved['ok'] === true && $moved['run']['fingerprint'] !== $fp1);
    $store->finish((int) $moved['run']['run_id'],
        \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));

    // A planner that runs out of budget must finish its run terminally. An
    // abandoned run holds the project's scan slot and looks like one still
    // working, which is the worse of the two failures by a distance.
    $slow = $planner->plan($PID, array_merge($baseReq,
        array('deadline' => microtime(true) - 1)));
    check('plan: running out of time refuses', $slow['ok'] === false && $slow['busy'] === false);
    check('plan: and says it was time rather than something unnamed',
        strpos($slow['why'], 'time this server allows') !== false);
    $left = $dbA->select('SELECT phase, terminal FROM ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run')
        . ' WHERE project_id = ' . $PID . ' AND active_slot = 1');
    check('plan: the abandoned run does not keep the project slot', $left === array());
    check('plan: so the next attempt is not told the project is busy',
        $planner->plan($PID, $baseReq)['ok'] === true);
    $A->query('UPDATE ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run')
        . " SET active_slot = NULL, phase = 'terminal', terminal = 'cancelled'
            WHERE project_id = " . $PID . " AND active_slot = 1");

    // Records the server cannot tell apart are offered twice by the walk, on
    // purpose. They must land once.
    $A->query('DELETE FROM redcap_record_list');
    foreach (array('T1', 'T1 ', 'T1  ', 'T2') as $r) {
        $A->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES (" . $PID . ", '"
            . $r . "', NULL)");
    }
    $tie = $planner->plan($PID, array_merge($baseReq, array('pageSize' => 1)));
    check('plan: a re-offered page boundary does not duplicate a record',
        $tie['ok'] === true && (int) $tie['run']['manifest_total'] === 4);
    check('plan: and the walk reported offering more than it stored',
        $tie['stats']['listed'] >= $tie['stats']['appended']);
    $store->finish((int) $tie['run']['run_id'],
        \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));

    // A project with no rules is not a project with nothing wrong.
    //
    // Scoped, both the clearing and the count. "No run was created" used to be
    // asked of the whole table, which answers the same on a refusal as it does
    // on a schema that never had a run in it - and the neighbour's run, which
    // has nothing to do with this refusal, was what the blanket DELETE removed
    // to make that reading work.
    $A->query('DELETE FROM ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run')
        . ' WHERE project_id = ' . $PID);
    $noRules = $planner->plan($PID, array_merge($baseReq, array('rules' => array())));
    check('plan: a project with no rules is refused rather than certified',
        $noRules['ok'] === false
        && strpos($noRules['why'], 'no validation rules') !== false);
    // Interpolated rather than bound, and deliberately: mysqlnd hands back a
    // native int for a bound statement and a string for an unbound one, so
    // adding a placeholder here would have quietly changed what === compares.
    check('plan: and no run was created for it',
        $dbA->select('SELECT COUNT(*) FROM ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run')
            . ' WHERE project_id = ' . $PID)[0][0] === '0');
    check('plan: while the neighbouring project\'s run is left exactly where it was',
        $dbA->select('SELECT COUNT(*) FROM ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run')
            . ' WHERE project_id = ' . $NEIGHBOUR)[0][0] === '1');

}
