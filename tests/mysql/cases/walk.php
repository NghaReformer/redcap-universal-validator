<?php
/**
 * tests/mysql/cases/walk.php — RecordManifestSource against REDCap-shaped tables.
 *
 * This class is almost entirely SQL, so a mock of it would be a mock of the
 * thing being tested. What it needs instead is tables shaped like REDCap's, on a
 * real server, with a real collation - because the one behaviour that decides
 * whether a record can be silently skipped is how the server compares two record
 * ids, and no PHP fixture has an opinion about that.
 *
 * TWO PROJECTS. 900 is under test; 901 is planted with rows in BOTH sources,
 * because choosing between the record index and the data table is itself a
 * per-project decision. A project with no record-index rows of its own must fall
 * through to the data table even when the project next door has plenty - and
 * with one project in the schema, "has no rows" and "the table is empty" are the
 * same sentence.
 */

use INSPIRE\UniversalValidator\Scan\Schema;

$PID = 900;
$NEIGHBOUR = uv_neighbour($PID);

{

    // Nothing exists yet: the walk must refuse BEFORE a run is created, rather
    // than fall back to exporting the project - which is the failure the whole
    // rebuild exists to remove.
    foreach (array('redcap_record_list', 'redcap_data', 'redcap_projects',
                   'redcap_log_event') as $t) {
        $A->query('DROP TABLE IF EXISTS ' . $t);
    }
    $none = \INSPIRE\UniversalValidator\Scan\RecordManifestSource::open($dbA, 42, array('pk' => 'record_id'));
    check('walk: with no usable source the walk is refused, not softened',
        $none['ok'] === false && $none['source'] === null);
    check('walk: and the refusal says what is missing',
        strpos($none['why'], 'without exporting the whole project') !== false);
    $nf = \INSPIRE\UniversalValidator\Scan\SourceFence::forProject($dbA, 42);
    check('fence: with no project row there is no fence', $nf['ok'] === false);

    // The three tables, and the deliberately absent UNIQUE key on
    // (project_id, record), now live in support/fixture.php - the tie handling
    // below is defensive code for a source that permits two ids the server
    // considers equal, and a unique key would make that state unreachable.
    uv_redcap_schema($A);
    uv_redcap_project($A, $PID);
    // AND THE PROJECT NEXT DOOR, in both sources at once. Every count below is a
    // claim about ONE project's records, and until there were two projects in
    // the schema none of them could tell "this project's rows" from "the rows".
    uv_redcap_neighbour_records($A, $PID);
    $nbRows = $dbA->select('SELECT COUNT(*) FROM redcap_record_list WHERE project_id = ?',
        array($NEIGHBOUR));
    check('walk: a second project has records in the same tables',
        (int) $nbRows[0][0] === 2);

    // 25 records, so paging is exercised rather than described.
    for ($i = 1; $i <= 25; $i++) {
        $A->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES (" . $PID . ", '"
            . sprintf('R%03d', $i) . "', " . ($i % 3 === 0 ? '7' : 'NULL') . ')');
    }
    $open = \INSPIRE\UniversalValidator\Scan\RecordManifestSource::open($dbA, $PID, array('pk' => 'record_id'));
    check('walk: the record index is preferred when it answers for this project',
        $open['ok'] === true && $open['source']->via() === 'redcap_record_list');
    check('walk: and it can report which group a record is in',
        $open['source']->hasDag() === true);

    $src = $open['source'];
    $seen = array();
    $cursor = null; $carry = array(); $pages = 0;
    while ($pages++ < 50) {
        $pg = $src->page($cursor, $carry, 10);
        if (!$pg['ok']) { check('walk: paging stayed usable', false); break; }
        foreach ($pg['rows'] as $r) $seen[] = $r['id'];
        $cursor = $pg['cursor']; $carry = $pg['emitted'];
        if ($pg['done']) break;
    }
    check('walk: every record is listed', count($seen) === 25);
    check('walk: exactly once', count(array_unique($seen)) === 25);
    check('walk: in order', $seen === array_values($seen) && $seen[0] === 'R001'
        && $seen[24] === 'R025');
    check('walk: and it finished in bounded pages', $pages <= 5);

    $pg = $src->page(null, array(), 3);
    check('walk: a group is carried with the record it belongs to',
        $pg['rows'][2]['id'] === 'R003' && $pg['rows'][2]['dag'] === '7');
    check('walk: and an ungrouped record says so rather than guessing',
        $pg['rows'][0]['dag'] === null);

    // THE CASE THAT DECIDES WHETHER A RECORD CAN BE SKIPPED. utf8mb4_unicode_ci
    // ignores trailing spaces, so the server considers 'T1' and 'T1 ' the same
    // value while they are different records. A keyset walk using `>` would step
    // over the second one and the run would certify a record it never read.
    $A->query('DELETE FROM redcap_record_list');
    // FORCED, not assumed. MySQL 8.0 defaults its schemas to utf8mb4_0900_ai_ci,
    // which is NO PAD, so trailing spaces are significant there and this
    // scenario would quietly test nothing; MariaDB 10.x defaults to a PAD SPACE
    // collation, where they are not. Naming the collation makes the boundary
    // machinery run on every server in the matrix rather than on whichever ones
    // happen to pad - and it is a real collation a REDCap installation can have.
    $A->query('ALTER TABLE redcap_record_list
        MODIFY record VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
    foreach (array('T1', 'T1 ', 'T1  ', 'T2') as $r) {
        $A->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES (" . $PID . ", '"
            . $r . "', NULL)");
    }
    // THE COLUMN'S COLLATION, NOT THE CONNECTION'S - and the two really do
    // disagree. This schema's columns are utf8mb4_unicode_ci, which pads; MySQL
    // 8.0's default connection collation is utf8mb4_0900_ai_ci, which does not.
    // So `SELECT 'T1' = 'T1 '` answers 0 on MySQL and 1 on MariaDB while the
    // COLUMN answers the same on both. That is exactly why the page boundary is
    // established by querying the source table rather than by comparing two
    // bound parameters: the parameter form asks a different question that
    // happens to have the same shape, and it would have passed on MariaDB.
    $eq = $dbA->select("SELECT COUNT(*) FROM redcap_record_list
        WHERE project_id = " . $PID . " AND record = 'T1'");
    check('walk: the record COLUMN treats those three ids as one value',
        isset($eq[0][0]) && (int) $eq[0][0] === 3);

    $seen = array(); $cursor = null; $carry = array(); $pages = 0;
    while ($pages++ < 20) {
        $pg = $src->page($cursor, $carry, 1);      // one at a time: every page is a boundary
        if (!$pg['ok']) { check('walk: tie paging stayed usable', false); break; }
        foreach ($pg['rows'] as $r) $seen[] = $r['id'];
        $cursor = $pg['cursor']; $carry = $pg['emitted'];
        if ($pg['done']) break;
    }
    sort($seen);
    check('walk: ids the server cannot tell apart are still all listed',
        count($seen) === 4);
    check('walk: exactly once each, by their real bytes',
        $seen === array('T1', 'T1 ', 'T1  ', 'T2'));

    // ONE ROW PER ARM (PRF-8). A record in two arms is two rows of REDCap's
    // record index with the same id. The walk lists it once, also when the
    // two rows fall on either side of a page boundary.
    $A->query('DELETE FROM redcap_record_list');
    foreach (array(array(1, 'M1'), array(2, 'M1'), array(1, 'M2'), array(2, 'M3'), array(1, 'M3')) as $ar) {
        $A->query("INSERT INTO redcap_record_list (project_id, arm, record, dag_id) VALUES (" . $PID . ", "
            . $ar[0] . ", '" . $ar[1] . "', NULL)");
    }
    foreach (array(1, 2, 10) as $size) {
        $seen = array(); $cursor = null; $carry = array(); $pages = 0;
        while ($pages++ < 20) {
            $pg = $src->page($cursor, $carry, $size);
            if (!$pg['ok']) break;
            foreach ($pg['rows'] as $r) $seen[] = $r['id'];
            $cursor = $pg['cursor']; $carry = $pg['emitted'];
            if ($pg['done']) break;
        }
        check('walk: a record in two arms is listed once, in pages of ' . $size . ' (got ' . json_encode($seen) . ')',
            $seen === array('M1', 'M2', 'M3'));
    }

    // The fallback source: no record index for this project at all.
    $A->query('DELETE FROM redcap_record_list');
    for ($i = 1; $i <= 4; $i++) {
        // Two events per record: the walk must list each record once, not once
        // per event.
        $A->query("INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`)
            VALUES (" . $PID . ", 1, 'D" . $i . "', 'record_id', 'D" . $i . "'),
                   (" . $PID . ", 2, 'D" . $i . "', 'record_id', 'D" . $i . "')");
    }
    $A->query("INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`)
        VALUES (" . $PID . ", 1, 'D2', '__GROUPID__', '31')");
    $recFb = new UvRecordingDb($dbA);
    $fb = \INSPIRE\UniversalValidator\Scan\RecordManifestSource::open($recFb, $PID, array('pk' => 'record_id'));
    check('walk: an empty record index falls through to the data table',
        $fb['ok'] === true && strpos($fb['source']->via(), 'redcap_data') === 0);
    $recFb->log = array();
    $pg = $fb['source']->page(null, array(), 10);
    // PRF-8: the fallback page is told to use the (project_id, record, ...)
    // index REDCap's data table has, so it reads records in order and sorts
    // nothing; under (project_id, field_name) it sorted the whole project.
    $pageSql = $recFb->matching('select', array('ORDER BY record'));
    $plan = $pageSql ? $A->query('EXPLAIN ' . uv_inline_params($A, $pageSql[0][1], $pageSql[0][2]))->fetch_assoc() : null;
    $planCols = ($plan && $plan['key'] !== null) ? uv_index_columns($A, 'redcap_data', $plan['key']) : array();
    check('walk: the fallback page walks an index that leads with (project_id, record) (got '
        . json_encode($plan ? array($plan['key'], $plan['Extra']) : null) . ')',
        array_slice($planCols, 0, 2) === array('project_id', 'record')
        && strpos($pageSql[0][1], 'FORCE INDEX (`proj_record_field`)') !== false);
    check('walk: and sorts nothing', $plan && stripos((string) $plan['Extra'], 'filesort') === false);
    $ids = array();
    foreach ($pg['rows'] as $r) $ids[] = $r['id'];
    check('walk: a record held in several events is listed once',
        $ids === array('D1', 'D2', 'D3', 'D4'));
    check('walk: and its group comes from the project\'s own group rows',
        $pg['rows'][1]['dag'] === '31' && $pg['rows'][0]['dag'] === null);

    // Without the record-id field name there is no bounded walk of the data
    // table, and refusing is the only honest answer.
    $noPk = \INSPIRE\UniversalValidator\Scan\RecordManifestSource::open($dbA, $PID, array());
    check('walk: no record-id field means no walk, and it says so',
        $noPk['ok'] === false
        && strpos($noPk['why'], 'record-id field could not be determined') !== false);

    // A table name can never be a bound parameter, so the allowlist is the only
    // thing between redcap_projects and an interpolated identifier.
    $A->query("UPDATE redcap_projects SET data_table = 'redcap_data; DROP TABLE x'
        WHERE project_id = " . $PID);
    // Nor read as the default (review rv5a-1): redcap_data holds none of a
    // project whose own table cannot be named.
    check('walk: a data-table name that is not one is refused, not interpolated',
        \INSPIRE\UniversalValidator\Scan\RecordManifestSource::dataTable($dbA, $PID) === null);
    $A->query("UPDATE redcap_projects SET data_table = NULL WHERE project_id = " . $PID);
    check('walk: and a project with no data table named is in REDCap\'s default one',
        \INSPIRE\UniversalValidator\Scan\RecordManifestSource::dataTable($dbA, $PID) === 'redcap_data');
    $A->query("UPDATE redcap_projects SET data_table = 'redcap_data' WHERE project_id = " . $PID);
}

// THE RECORD LIST IS A CACHE (review CL-1). REDCap builds redcap_record_list in
// the background and says how far it got in redcap_record_counts. A list that
// holds C1 while the data table holds C1 and C2 - a record created or edited
// while the cache was being rebuilt - must neither leave C2 off the manifest nor
// report it deleted.
$CACHE = 910;
{
    $MS = '\INSPIRE\UniversalValidator\Scan\RecordManifestSource';
    uv_redcap_project($A, $CACHE);
    $A->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES (" . $CACHE . ", 'C1', NULL)");
    foreach (array(array('C1', 'record_id'), array('C2', 'record_id'), array('C2', 'dob')) as $row) {
        $A->query("INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`)
            VALUES (" . $CACHE . ", 1, '" . $row[0] . "', '" . $row[1] . "', 'x')");
    }

    // A REDCap that keeps no status: the index is judged by the probe, as it
    // always was - and an absence from it is now confirmed against the data.
    $o = $MS::open($dbA, $CACHE, array('pk' => 'record_id'));
    check('cache: with no status table, a non-empty index is walked as before',
        $o['ok'] === true && $o['source']->via() === 'redcap_record_list');
    $ans = $o['source']->exist(array('C1', 'C2', 'C9'));
    check('cache: a record the index lacks but the data table holds is still there',
        $ans['C1'] === true && $ans['C2'] === true);
    check('cache: and a record neither holds is gone', $ans['C9'] === false);

    uv_redcap_counts_schema($A);
    uv_redcap_counts($A, $CACHE, 'PROCESSING');
    $o = $MS::open($dbA, $CACHE, array('pk' => 'record_id'));
    check('cache: a list REDCap is still building is not walked',
        $o['ok'] === true && strpos($o['source']->via(), 'redcap_data') === 0);
    $ids = array();
    foreach ($o['source']->page(null, array(), 10)['rows'] as $r) $ids[] = $r['id'];
    check('cache: so the record the list lacks is on the manifest', $ids === array('C1', 'C2'));

    uv_redcap_counts($A, $CACHE, 'COMPLETE');
    $o = $MS::open($dbA, $CACHE, array('pk' => 'record_id'));
    check('cache: a list REDCap reports complete is walked',
        $o['ok'] === true && $o['source']->via() === 'redcap_record_list');
    check('cache: and still confirms an absence against the data before calling it a deletion',
        $o['source']->exist(array('C2'))['C2'] === true);
    uv_redcap_counts($A, $CACHE, 'FIX_SORT');
    $o = $MS::open($dbA, $CACHE, array('pk' => 'record_id'));
    check('cache: FIX_SORT is complete, with only its order to repair',
        $o['ok'] === true && $o['source']->via() === 'redcap_record_list');

    $A->query('DELETE FROM redcap_record_counts WHERE project_id = ' . $CACHE);
    $o = $MS::open($dbA, $CACHE, array('pk' => 'record_id'));
    check('cache: a project REDCap has no status for walks the data table',
        $o['ok'] === true && strpos($o['source']->via(), 'redcap_data') === 0);
    $A->query('DROP TABLE redcap_record_counts');
}
