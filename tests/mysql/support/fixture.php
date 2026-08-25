<?php
/**
 * tests/mysql/support/fixture.php — the two-project fixture.
 *
 * WHY EVERY FIXTURE HERE CARRIES A SECOND PROJECT.
 *
 * The old suite hand-partitioned the exact axis the durable scan gets wrong. It
 * handed the planner a group id directly rather than letting the code derive
 * one, and each finalizer scenario picked its own private generation number -
 * 4242, 4243, 4244, 4245 - so no scenario's rows could ever meet another's.
 * That is the isolation production does not have. A generation in production is
 * always 1, for every project on the installation, so one project's findings and
 * another's sit in the same space and a statement that filters on generation
 * alone answers about both.
 *
 * Two hundred and eighty-six checks against a real server were blind to that,
 * because every one of them ran in a schema containing exactly one project.
 *
 * So: a case builds its own project, and it also plants a NEIGHBOUR - a second
 * project, on the same server, in the same tables, with its own run and its own
 * findings, created through the same store the module uses. A statement that
 * forgets its project predicate now returns the neighbour's rows too, and the
 * case's own assertions have to be scoped precisely enough to say so.
 *
 * THE NEIGHBOUR NOW SHARES THE GENERATION, which is the whole point of wave 4.
 * A generation is allocated per project, so project 900's first run and project
 * 901's first run are both generation 1 - exactly as two projects on a real
 * installation are - and a statement that filters on generation alone answers
 * about both of them. Holding the neighbour apart by generation was the last
 * place this suite still manufactured the isolation production does not have.
 *
 * The neighbour's records are named NB-n so a reader can tell them apart at a
 * glance, but nothing asserts on that prefix any more: it holds no value
 * previews, so the installation-wide retention clocks - which are
 * installation-wide on purpose - still count only what the case put in front of
 * them, and everything else is counted by project id.
 */

use INSPIRE\UniversalValidator\Scan\Schema;

/**
 * The project next door.
 *
 * The suite has always used pid+1 for "some other project" (700/701, 900/901),
 * so the convention is kept rather than invented.
 */
function uv_neighbour($pid) { return ((int) $pid) + 1; }

/**
 * The run sequence number a run was allocated.
 *
 * SqlScanStore::run() does not return run_seq - ArrayScanStore's does, and the
 * shared contract reads it - so a case that has to stamp valid_from_seq on a
 * finding the way a worker does has to read the column. That asymmetry is
 * reported rather than papered over; see the notes accompanying this wave.
 */
function uv_run_seq($db, $runId) {
    $r = $db->select('SELECT run_seq FROM ' . Schema::table('scan_run')
        . ' WHERE run_id = ?', array((int) $runId));
    return isset($r[0][0]) ? (int) $r[0][0] : 0;
}

/**
 * A second project with a live run and findings of its own.
 *
 * Built through SqlScanStore rather than through INSERTs, because the point is
 * for the neighbour to hold exactly what production would hold - including the
 * generation the store actually hands out, whatever that turns out to be. On a
 * fresh schema that is generation 1, the same number the case's own project
 * gets, which is the arrangement every real installation has and the one this
 * suite used to arrange its way out of.
 *
 * IT ALWAYS HAS FINDINGS NOW. There used to be a $withFindings flag, and it was
 * a concession to the schema rather than an option: a finding hung off a
 * generation alone, so a neighbour with findings was counted by anything that
 * aggregated a generation, and the rollup case had to plant a neighbour with
 * nothing in it to keep its totals readable. Every one of those statements
 * carries a project predicate now, so the flag would only be a way to make a
 * case easier to pass.
 *
 * @return array|null pid, run_id, generation_id, run_seq and the record ids it wrote
 */
function uv_plant_neighbour($db, $pid) {
    $nb = uv_neighbour($pid);
    $store = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($db);
    $started = $store->startRun($nb, array('created_by' => 'the-neighbour'));
    if ($started['ok'] !== true) return null;

    $runId = (int) $started['run']['run_id'];
    $gen   = (int) $started['run']['generation_id'];
    $seq   = uv_run_seq($db, $runId);
    $recs = array(
        array('id_bin' => 'NB-1', 'hash' => hash('sha256', 'NB-1', true), 'dag' => null),
        array('id_bin' => 'NB-2', 'hash' => hash('sha256', 'NB-2', true), 'dag' => null),
    );
    $store->writeManifest($runId, $recs);
    $epoch = (int) $store->run($nb, $runId)['lease_epoch'];
    $claim = $store->claim($runId, 'neighbour-worker', $epoch, 2);
    if (!is_array($claim)) return null;

    $batch = array('bytes' => 0, 'records' => array(), 'findings' => array());
    foreach ($claim as $c) {
        // record_hash travels with the state because commitBatch closes that
        // record's own prior findings from it. A neighbour built without one
        // would exercise a path no worker takes.
        $batch['records'][] = array('ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
            'state' => \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE, 'version' => 'nb');
        // No value_bin and no value_expires_at: the retention clocks are
        // installation-wide by design, and a neighbour carrying a preview would
        // change what they count rather than what they reach.
        $batch['findings'][] = array(
            // The project is what makes this row the neighbour's rather than
            // nobody's. SqlScanStore refuses a missing or zero one outright.
            'project_id' => $nb,
            'generation_id' => $gen, 'identity' => hash('sha256', 'nb-' . $c['id_bin'], true),
            'valid_from_seq' => $seq,
            'record_hash' => $c['hash'], 'record_id_bin' => $c['id_bin'],
            'event_id' => null, 'instance' => 1, 'host_form' => 'nb_form', 'field' => 'nb',
            'rule_source_id' => 'nb-rule', 'rule_revision' => str_repeat('d', 64), 'rule_ord' => 1,
            'check_type' => 'required', 'reason_code' => 'required-blank',
        );
    }
    // A refused commit returns null rather than an array, so a case asserting
    // is_array() on this fails where the neighbour was planted rather than
    // several checks later where its rows are missing.
    if ($store->commitBatch($runId, 'neighbour-worker', $epoch, 0, $batch) !== true) return null;

    return array('pid' => $nb, 'run_id' => $runId, 'generation_id' => $gen, 'run_seq' => $seq,
                 'records' => array('NB-1', 'NB-2'));
}

/**
 * Clear one project's run state and leave the neighbour's where it is.
 *
 * TWO STATEMENTS AND A PROJECT ID, which is what this function was always meant
 * to be. uv_finding carried no project at all, so the findings DELETE had to
 * exclude the neighbour by its record-id prefix - and a fixture that identifies
 * a project by how its records are named is a fixture that would keep working
 * if the project column went away again.
 */
function uv_clear_project($db, $pid) {
    $runs = $db->select('SELECT run_id FROM ' . Schema::table('scan_run')
        . ' WHERE project_id = ' . (int) $pid);
    foreach ($runs as $r) {
        $db->exec('DELETE FROM ' . Schema::table('scan_record') . ' WHERE run_id = ?', array($r[0]));
        $db->exec('DELETE FROM ' . Schema::table('scan_aggregate') . ' WHERE run_id = ?', array($r[0]));
    }
    $db->exec('DELETE FROM ' . Schema::table('scan_run')
        . ' WHERE project_id = ?', array((int) $pid));
    $db->exec('DELETE FROM ' . Schema::table('finding')
        . ' WHERE project_id = ?', array((int) $pid));
}

/**
 * How many findings the neighbour still has on record.
 *
 * By project, not by record-id prefix. The prefix was the only handle there was
 * while uv_finding held no project, and it answers the same on a row written
 * with the wrong project id as on a row written with the right one.
 */
function uv_neighbour_findings($db, $pid) {
    $r = $db->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = ?', array(uv_neighbour($pid)));
    return isset($r[0][0]) ? (int) $r[0][0] : 0;
}

/**
 * A generation number for a scenario that has no run to take one from.
 *
 * The unique finalizer works on a GENERATION rather than on a run, so those
 * cases have never had a store to ask - and the old suite answered by writing
 * 4242, 4243, 4244, 4245, 4250 and 4251 into six separate scenarios. Six
 * literals nobody could collide with is exactly the isolation production does
 * not have, and it is a large part of why a suite this size never noticed that
 * a generation is installation-wide rather than per project.
 *
 * WHAT IT MAY AND MAY NOT BE USED FOR, now that generations are per project.
 * Two scenarios of the SAME project needing different generations is a real
 * shape - that is what successive runs of one project are - and this is how
 * they are named. Holding two PROJECTS apart with it is not, and there is no
 * longer a caller that does: the neighbour shares the project under test's
 * generation everywhere, because that is what a second project on a real
 * installation has.
 */
function uv_generation($name) {
    static $seen = array();
    static $next = 4242;
    if (!isset($seen[$name])) {
        $seen[$name] = $next;
        $next++;
    }
    return $seen[$name];
}

/**
 * The REDCap-shaped tables, as a real installation has them.
 *
 * No UNIQUE key on (project_id, record) on purpose: the tie handling the walk
 * tests exercise is defensive code for a source that permits two ids the server
 * considers equal, and a unique key would make that state unreachable - which is
 * exactly why a real REDCap rarely produces it, and no reason to leave the
 * handling untested.
 */
function uv_redcap_schema($conn) {
    $conn->query('CREATE TABLE redcap_projects (project_id INT PRIMARY KEY,
        log_event_table VARCHAR(64) NULL, data_table VARCHAR(64) NULL) ENGINE=InnoDB');
    $conn->query('CREATE TABLE redcap_record_list (project_id INT NOT NULL,
        record VARCHAR(100) NOT NULL, dag_id INT NULL, KEY (project_id, record)) ENGINE=InnoDB');
    $conn->query('CREATE TABLE redcap_data (project_id INT NOT NULL, event_id INT NOT NULL,
        record VARCHAR(100) NOT NULL, field_name VARCHAR(100) NOT NULL,
        `value` TEXT NULL, instance INT NULL,
        KEY (project_id, field_name, record)) ENGINE=InnoDB');
}

/**
 * The log table, separately.
 *
 * The walk case asserts what happens when there is no log at all, so creating it
 * alongside the other three would remove the state that case exists to test.
 */
function uv_redcap_log_schema($conn) {
    $conn->query('CREATE TABLE redcap_log_event (log_event_id BIGINT PRIMARY KEY,
        project_id INT NOT NULL, pk VARCHAR(100) NULL, event VARCHAR(32) NULL,
        KEY (project_id, log_event_id)) ENGINE=InnoDB');
}

/** One project row, pointing at the tables the walk and the fence resolve. */
function uv_redcap_project($conn, $pid, $log = 'redcap_log_event', $data = 'redcap_data') {
    $conn->query("INSERT INTO redcap_projects (project_id, log_event_table, data_table)
        VALUES (" . (int) $pid . ", '" . $log . "', '" . $data . "')");
}

/**
 * The neighbour's records, in the REDCap-shaped tables.
 *
 * Its ids deliberately sort both before and after anything a case names R, P, W
 * or D, so a walk that lost its project predicate cannot produce a plausible
 * ordering by accident. It gets rows in BOTH sources, because choosing between
 * them is itself a per-project decision: a project with no record-index rows of
 * its own must fall through to the data table even when the project next door
 * has plenty.
 */
function uv_redcap_neighbour_records($conn, $pid) {
    $nb = uv_neighbour($pid);
    uv_redcap_project($conn, $nb);
    foreach (array('AAA-1', 'ZZZ-9') as $rec) {
        $conn->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES ("
            . $nb . ", '" . $rec . "', 99)");
        $conn->query("INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`)
            VALUES (" . $nb . ", 1, '" . $rec . "', 'record_id', '" . $rec . "')");
    }
    return $nb;
}

/**
 * The neighbour's log entries, ABOVE the case's own.
 *
 * The opening fence is the top of the log, so a fence query missing its project
 * predicate would open at the neighbour's newest entry and certify a window this
 * project never had. Sitting above is what makes that visible.
 */
function uv_redcap_neighbour_log($conn, $pid, $topLogId = 900000) {
    $nb = uv_neighbour($pid);
    $conn->query("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event) VALUES ("
        . (int) $topLogId . ", " . $nb . ", 'AAA-1', 'UPDATE'), ("
        . ((int) $topLogId + 1) . ", " . $nb . ", 'ZZZ-9', 'UPDATE')");
    return $nb;
}

/**
 * The planner request the planning and worker cases both start from.
 *
 * 'source' and 'fence' are deliberately absent: they are the two things a case
 * has to build for itself, and a default that silently supplied them is how a
 * case ends up planning against a source it never looked at.
 */
function uv_plan_request(array $over = array()) {
    return array_merge(array(
        'rules' => array(array('type' => 'required', 'fields' => array('dob'))),
        'settingsCount' => 0,
        'ownership' => array('dob' => 'demographics'),
        'structure' => array('longitudinal' => false),
        'choices' => array(),
        'policy' => \INSPIRE\UniversalValidator\Scan\ScanPolicy::resolve(),
        'engine' => '1.8.20',
        'createdBy' => 'alice',
        'pageSize' => 10,
    ), $over);
}
