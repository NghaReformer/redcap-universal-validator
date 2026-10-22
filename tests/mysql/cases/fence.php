<?php
/**
 * tests/mysql/cases/fence.php — SourceFence over a real log table.
 *
 * The fence is how a run says what window it covered: an opening log id, a
 * per-record version, and a catch-up list of everything that moved in between.
 * Every one of those is a query with a project predicate in it, and the failure
 * mode when the predicate is missing is not an error - it is a run that
 * certifies a window belonging to a different project.
 *
 * TWO PROJECTS, AND THE NEIGHBOUR'S LOG SITS ABOVE THIS ONE'S ON PURPOSE. The
 * opening fence is the top of the log; with the neighbour's newest entry ordered
 * after every entry here, a fence that lost its scope opens at a number this
 * project has never seen, and says so out loud instead of passing.
 */

use INSPIRE\UniversalValidator\Scan\Schema;
use INSPIRE\UniversalValidator\Scan\RecordManifestSource;

$PID = 900;
$NEIGHBOUR = uv_neighbour($PID);

{
    // The three record tables and this project's row. The log table itself is
    // created below, because the first thing the walk case proves is what
    // happens when there is no log at all.
    uv_redcap_schema($A);
    uv_redcap_project($A, $PID);
    uv_redcap_neighbour_records($A, $PID);

    // The tables are here and they are full of the neighbour's rows. A project
    // with no row of its own still has no fence - which is a different question
    // from the walk case's "the tables do not exist", and the only one of the
    // two that a single-project schema could never ask.
    $nf = \INSPIRE\UniversalValidator\Scan\SourceFence::forProject($dbA, 4242);
    check('fence: a project with no row of its own cannot be fenced, however full the table is',
        $nf['ok'] === false);

    uv_redcap_log_schema($A);
    $A->query("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event) VALUES
        (100, " . $PID . ", 'D1', 'INSERT'), (110, " . $PID . ", 'D2', 'UPDATE'),
        (120, " . $PID . ", 'D1', 'UPDATE'), (130, " . $PID . ", NULL, 'DATA_EXPORT'),
        (140, " . $PID . ", 'D3', 'UPDATE'), (150, " . $NEIGHBOUR . ", 'X1', 'UPDATE')");
    // The neighbour's newest entries, ABOVE everything this project will ever
    // write. now() is a MAX over the log, so a fence that lost its project
    // predicate would open here rather than at 140 - and would then certify a
    // window built out of another project's edits.
    uv_redcap_neighbour_log($A, $PID);

    $ff = \INSPIRE\UniversalValidator\Scan\SourceFence::forProject($dbA, $PID);
    check('fence: a project with an ordered log can be fenced', $ff['ok'] === true);
    $fence = $ff['fence'];
    check('fence: the opening fence is the top of the log', $fence->now() === '140');
    check('fence: and it is a string, because a bigint is not an int everywhere',
        is_string($fence->now()));

    $v = $fence->versions(array('D1', 'D2', 'D9'));
    check('fence: each record carries its own latest version',
        $v['D1'] === '120' && $v['D2'] === '110');
    check('fence: a record with no history answers null, which is an answer',
        array_key_exists('D9', $v) && $v['D9'] === null);
    check('fence: another project\'s entries are not this project\'s versions',
        !array_key_exists('X1', $v));

    check('fence: the interval is covered while the opening entry survives',
        $fence->retained('140')['ok'] === true);
    // Some installations prune their log. A catch-up over a window it cannot see
    // would report "nothing changed" about changes it simply cannot read.
    $A->query('DELETE FROM redcap_log_event WHERE log_event_id < 130 AND project_id = ' . $PID);
    $gone = $fence->retained('100');
    check('fence: a pruned log refuses to certify the interval', $gone['ok'] === false);
    check('fence: and says the log was removed rather than that nothing changed',
        strpos($gone['why'], 'removed since') !== false);

    $A->query("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event) VALUES
        (160, " . $PID . ", 'D2', 'UPDATE'), (170, " . $PID . ", 'D4', 'INSERT'),
        (180, " . $PID . ", 'D2', 'UPDATE'), (190, " . $PID . ", '', 'MANAGE')");
    $chg = $fence->changedSince('130', '180', null, 10);
    $names = array();
    foreach ($chg as $c) $names[] = $c['id'];
    check('fence: only records changed inside the window are listed',
        $names === array('D2', 'D3', 'D4'));
    check('fence: and each carries the newest version in that window',
        $chg[0]['version'] === '180');
    check('fence: an entry with no record is not a record change',
        !in_array('', $names, true));
    $one = $fence->changedSince('130', '180', null, 1);
    $rest = $fence->changedSince('130', '180', $one[0]['id'], 10);
    check('fence: the change list pages by keyset rather than by offset',
        count($one) === 1 && $one[0]['id'] === 'D2'
        && count($rest) === 2 && $rest[0]['id'] === 'D3' && $rest[1]['id'] === 'D4');

    // A log table name that is not a log table name never reaches a statement.
    $A->query("UPDATE redcap_projects SET log_event_table = 'redcap_log_event; DROP TABLE x'
        WHERE project_id = " . $PID);
    check('fence: a log table name that is not one is refused',
        \INSPIRE\UniversalValidator\Scan\SourceFence::resolveTable($dbA, $PID) === null);
    // PHP's '$' also matches before a trailing newline; the anchor is '\z'.
    $A->query("UPDATE redcap_projects SET log_event_table = 'redcap_log_event\n'
        WHERE project_id = " . $PID);
    check('fence: nor is one with a trailing newline',
        \INSPIRE\UniversalValidator\Scan\SourceFence::resolveTable($dbA, $PID) === null);
    $A->query("UPDATE redcap_projects SET log_event_table = 'redcap_log_event7'
        WHERE project_id = " . $PID);
    check('fence: a sharded log table is accepted',
        \INSPIRE\UniversalValidator\Scan\SourceFence::resolveTable($dbA, $PID) === 'redcap_log_event7');

    // Log ids outgrow an int, and outgrow a float's exact range before that.
    check('fence: fences compare as numbers, not as strings',
        \INSPIRE\UniversalValidator\Scan\SourceFence::decCmp('9', '10') < 0 && \INSPIRE\UniversalValidator\Scan\SourceFence::decCmp('10', '9') > 0);
    check('fence: and stay exact past what a float can hold',
        \INSPIRE\UniversalValidator\Scan\SourceFence::decCmp('9007199254740993', '9007199254740992') > 0);
    check('fence: leading zeros are not a different number',
        \INSPIRE\UniversalValidator\Scan\SourceFence::decCmp('0042', '42') === 0);

}

/* ---------------------------------------------------------------------------
 * ScopeDrift over a real log, record index and store (review rv11a1-2)
 *
 * A group run's report is shown to its group's readers only while the group
 * holds what the run held at its fence. The check walks the log from the
 * fence a bounded page at a time, asks each changed record's group of the
 * record index, and its first log entry when it is in the group and was not
 * on the run's list. The neighbour's entries are interleaved with this
 * project's on purpose: a walk that read the log by position alone would
 * read them all, and one that lost its project predicate would read them as
 * this project's records.
 * ------------------------------------------------------------------------- */
{
    $DP = 910;
    $DN = uv_neighbour($DP);
    uv_redcap_project($A, $DP);
    $dput = function ($rec, $dag) use ($A, $DP) {
        $A->query('DELETE FROM redcap_record_list WHERE project_id = ' . $DP . " AND record = '" . $rec . "'");
        $A->query('DELETE FROM redcap_data WHERE project_id = ' . $DP . " AND record = '" . $rec . "'");
        if ($dag === false) return;
        $A->query('INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES (' . $DP . ", '" . $rec
            . "', " . ($dag === null ? 'NULL' : (int) $dag) . ')');
        $A->query('INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`) VALUES ('
            . $DP . ", 1, '" . $rec . "', 'record_id', '" . $rec . "')");
    };
    $dlog = function ($at, $rec) use ($A, $DP) {
        $A->query('INSERT INTO redcap_log_event (log_event_id, project_id, pk, event) VALUES (' . (int) $at
            . ', ' . $DP . ', ' . ($rec === null ? 'NULL' : "'" . $rec . "'") . ", 'UPDATE')");
    };
    // G1-G4 in group 7, H1 in group 31, all logged before the fence at 100000.
    foreach (array('G1' => 7, 'G2' => 7, 'G3' => 7, 'G4' => 7, 'H1' => 31) as $rec => $dag) $dput($rec, $dag);
    foreach (array('G1', 'G2', 'G3', 'G4', 'H1') as $i => $rec) $dlog(1000 * ($i + 1), $rec);
    $dlog(100000, 'G1');
    // The neighbour's entries fill every gap above the fence, naming one of
    // this project's record ids; this project's own entries land on ...500.
    $vals = array();
    for ($at = 100001; $at <= 109999; $at++) {
        if ($at % 1000 === 500) continue;
        $vals[] = '(' . $at . ', ' . $DN . ", 'G3', 'UPDATE')";
        if (count($vals) === 1000) {
            $A->query('INSERT INTO redcap_log_event (log_event_id, project_id, pk, event) VALUES ' . implode(',', $vals));
            $vals = array();
        }
    }
    if ($vals) $A->query('INSERT INTO redcap_log_event (log_event_id, project_id, pk, event) VALUES ' . implode(',', $vals));
    $A->query('ANALYZE TABLE redcap_log_event');

    $dsrc = RecordManifestSource::open($dbA, $DP, array('pk' => 'record_id'));
    $dstore = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA);
    $dplan = (new \INSPIRE\UniversalValidator\Scan\ScanPlanner($dstore, 'test-secret-key'))->plan($DP, uv_plan_request(array(
        'source' => $dsrc['source'], 'fence' => null, 'dagFilter' => '7',
        'rules' => array(array('type' => 'required', 'fields' => array('record_id'))),
        'ownership' => array('record_id' => 'demographics'),
    )));
    $drid = (int) $dplan['run']['run_id'];
    $dbA->exec('UPDATE ' . Schema::table('scan_run') . " SET fence_target = '100000' WHERE run_id = ?", array($drid));
    $drec = new UvRecordingDb($dbA);
    $dlogf = \INSPIRE\UniversalValidator\Scan\SourceFence::forProject($drec, $DP);
    $dscope = function (array $ids) use ($dsrc) { return $dsrc['source']->scopeOf($ids, '7'); };
    $dexist = function (array $ids) use ($dsrc) { return $dsrc['source']->exist($ids); };
    $drift = function ($page = 5000, $pages = 10) use ($dlogf, $dstore, $DP, $drid, $dscope, $dexist) {
        return \INSPIRE\UniversalValidator\Scan\ScopeDrift::check($dlogf['fence'], $dstore,
            $dstore->run($DP, $drid), $dscope, $dexist, $page, $pages);
    };
    $moved = \INSPIRE\UniversalValidator\Scan\ScanAuthorization::projectionStillValid(1, 0)['why'];
    check('drift: the fixture is a group-7 run of four records, fenced at 100000',
        $dplan['ok'] === true && (int) $dplan['run']['manifest_total'] === 4 && $dlogf['ok'] === true
        && $dstore->run($DP, $drid)['fence_target'] === '100000');
    check('drift: with nothing logged since the fence, the group is as the run left it', $drift()['ok'] === true);

    $dlog(101500, 'G2');
    $dlog(102500, null);
    check('drift: an edit in the group and an entry naming no record are not moves', $drift()['ok'] === true);
    $walk = $drec->matching('select', array('ORDER BY log_event_id'));
    $wkeys = $walk ? uv_explain_keys($A, $walk[0][1], $walk[0][2]) : array();
    check('drift: the walk reads along (project_id, log_event_id), not past the neighbour entries',
        count($wkeys) === 1 && uv_index_columns($A, 'redcap_log_event', $wkeys[0]) === array('project_id', 'log_event_id'));
    if (count($wkeys) !== 1 && $walk) {
        fwrite(STDERR, '  drift walk plan: ' . implode(' ', uv_explain_rows($A, $walk[0][1], $walk[0][2])) . "\n");
    }

    $dput('G3', 31);
    $dlog(103500, 'G3');
    $out = $drift();
    check('drift: a record moved out of the group refuses the report, final, with the H10 sentence',
        $out === array('ok' => false, 'movedIn' => 0, 'movedOut' => 1, 'why' => $moved, 'final' => true));
    $dput('G3', 7);
    $dlog(104500, 'G3');
    check('drift: moved back, the group again holds what the run held', $drift()['ok'] === true);

    $dput('N1', 7);
    $dlog(105500, 'N1');
    check('drift: a record created in the group after the fence is not a move', $drift()['ok'] === true);
    $dput('H1', 7);
    $dlog(106500, 'H1');
    $out = $drift();
    check('drift: a record that was in another group at the fence and is in this one now moved in',
        $out['ok'] === false && $out['movedIn'] === 1 && $out['movedOut'] === 0 && $out['final'] === true);
    $dput('H1', 31);
    $dlog(107500, 'H1');
    check('drift: and moved back out of it, the report is shown again', $drift()['ok'] === true);

    $dbA->exec('UPDATE ' . Schema::table('scan_record') . ' SET state = ? WHERE run_id = ? AND record_id_bin = ?',
        array(\INSPIRE\UniversalValidator\Scan\ScanStore::REC_DEPARTED, $drid, 'G2'));
    $dlog(108500, 'G2');
    $out = $drift();
    check('drift: a record the run let go of as departed, in the group now, moved in',
        $out['ok'] === false && $out['movedIn'] === 1);
    $dbA->exec('UPDATE ' . Schema::table('scan_record') . ' SET state = ? WHERE run_id = ? AND record_id_bin = ?',
        array(\INSPIRE\UniversalValidator\Scan\ScanStore::REC_PENDING, $drid, 'G2'));
    $dput('G4', false);
    $dlog(109500, 'G4');
    check('drift: a record deleted since the fence is not a move', $drift()['ok'] === true);

    // Nine entries since the fence. Three pages of three reach the top; two do not.
    check('drift: a walk that reaches the top of the log within its pages confirms the group',
        $drift(3, 3)['ok'] === true);
    $out = $drift(3, 2);
    check('drift: one with more entries since the fence than it may read refuses, final',
        $out['ok'] === false && $out['why'] === \INSPIRE\UniversalValidator\Scan\ScopeDrift::UNCONFIRMED
        && $out['final'] === true);
    $A->query('DELETE FROM redcap_log_event WHERE project_id = ' . $DP . ' AND log_event_id <= 100000');
    $out = $drift();
    check('drift: a log pruned past the fence cannot confirm the group, final',
        $out['ok'] === false && $out['why'] === \INSPIRE\UniversalValidator\Scan\ScopeDrift::UNCONFIRMED
        && $out['final'] === true);
}
