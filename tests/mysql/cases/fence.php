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
