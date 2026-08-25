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
 * The neighbour's records are named NB-n so no case can confuse them for its
 * own, and it holds no value previews, so the installation-wide retention
 * clocks - which are installation-wide on purpose - still count only what the
 * case put in front of them.
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
 * A second project with a live run and findings of its own.
 *
 * Built through SqlScanStore rather than through INSERTs, because the point is
 * for the neighbour to hold exactly what production would hold - including the
 * generation the store actually hands out, whatever that turns out to be.
 *
 * $withFindings is a concession rather than an option. A finding hangs off a
 * generation and generations are installation-wide, so a neighbour that has
 * findings is counted by anything that aggregates a generation - which is what
 * the rollup does. Until B1 makes the generation per project, the rollup case
 * plants a neighbouring RUN and no neighbouring findings, and says so where it
 * does it. Everywhere else the default applies and the findings are there.
 *
 * @return array|null pid, run_id, generation_id and the record ids it wrote
 */
function uv_plant_neighbour($db, $pid, $withFindings = true) {
    $nb = uv_neighbour($pid);
    $store = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($db);
    $started = $store->startRun($nb, array('created_by' => 'the-neighbour'));
    if ($started['ok'] !== true) return null;

    $runId = (int) $started['run']['run_id'];
    $gen   = (int) $started['run']['generation_id'];
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
        $batch['records'][] = array('ordinal' => $c['ordinal'],
            'state' => \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE, 'version' => 'nb');
        // No value_bin and no value_expires_at: the retention clocks are
        // installation-wide by design, and a neighbour carrying a preview would
        // change what they count rather than what they reach.
        if (!$withFindings) continue;
        $batch['findings'][] = array(
            'generation_id' => $gen, 'identity' => hash('sha256', 'nb-' . $c['id_bin'], true),
            'seq' => 1, 'record_hash' => $c['hash'], 'record_id_bin' => $c['id_bin'],
            'event_id' => null, 'instance' => 1, 'host_form' => 'nb_form', 'field' => 'nb',
            'rule_source_id' => 'nb-rule', 'rule_revision' => str_repeat('d', 64), 'rule_ord' => 1,
            'check_type' => 'required', 'reason_code' => 'required-blank',
        );
    }
    $store->commitBatch($runId, 'neighbour-worker', $epoch, 0, $batch);

    return array('pid' => $nb, 'run_id' => $runId, 'generation_id' => $gen,
                 'records' => array('NB-1', 'NB-2'));
}

/**
 * Clear one project's run state and leave the neighbour's where it is.
 *
 * uv_scan_record hangs off a run and uv_finding hangs off a GENERATION, and
 * generations are handed out per installation rather than per project - so for
 * the findings there is no project-scoped DELETE to write, and the neighbour's
 * rows have to be excluded by their record ids instead. That awkwardness is the
 * finding rather than a fixture convenience: a scan whose scoping worked would
 * make this function two statements and a project id.
 */
function uv_clear_project($db, $pid) {
    $runs = $db->select('SELECT run_id FROM ' . Schema::table('scan_run')
        . ' WHERE project_id = ' . (int) $pid);
    foreach ($runs as $r) {
        $db->exec('DELETE FROM ' . Schema::table('scan_record') . ' WHERE run_id = ?', array($r[0]));
        $db->exec('DELETE FROM ' . Schema::table('scan_aggregate') . ' WHERE run_id = ?', array($r[0]));
    }
    $db->exec('DELETE FROM ' . Schema::table('scan_run')
        . ' WHERE project_id = ' . (int) $pid);
    $db->exec('DELETE FROM ' . Schema::table('finding') . " WHERE record_id_bin NOT LIKE 'NB-%'");
}

/** How many findings the neighbour still has on record. */
function uv_neighbour_findings($db) {
    $r = $db->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . " WHERE record_id_bin LIKE 'NB-%'");
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
 * Named and allocated in one place so that when generations become
 * project-scoped there is one function to change rather than six literals to
 * find.
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
