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
 * That arrangement is what makes the purge assertions the most important ones
 * in the suite. purgeRuns takes a project id and then deletes findings by
 * generation; while the generation was the literal 1 everywhere and uv_finding
 * carried no project column, purging ONE finished run of ONE project deleted
 * every project's findings on the whole installation. It was reproduced against
 * a real server exactly this way - two projects, one purge, zero findings left
 * anywhere - and this case is where that stays reproduced.
 *
 * AND EVERY CLOCK IS PAGED (wave 8, P6). The sections after the first drive each
 * paged statement with a page far smaller than ScanRetention::PAGE, so a sweep
 * takes several calls on a handful of rows, and EXPLAIN the statement the code
 * actually sent: the bound on each page is a key, and the key is asserted.
 */

use INSPIRE\UniversalValidator\Scan\Schema;
use INSPIRE\UniversalValidator\Scan\ScanRetention;
use INSPIRE\UniversalValidator\Scan\SqlScanStore;
use INSPIRE\UniversalValidator\Scan\ScanStore;
use INSPIRE\UniversalValidator\Scan\ScanOutcome;

$PID = 800;
$NEIGHBOUR = uv_neighbour($PID);

/**
 * A finished (or, with $finish false, live) run of $pid holding $n findings on
 * one record, each with a stored value expiring at $expires (null for none).
 */
$plantRun = function ($db, $pid, $n, $expires, $finish = true, array $runOpts = []) {
    $store = new SqlScanStore($db);
    $r = $store->startRun($pid, $runOpts + ['created_by' => 'alice']);
    if ($r['ok'] !== true) return null;
    $rid = (int) $r['run']['run_id'];
    $gen = (int) $r['run']['generation_id'];
    $id = 'P' . $pid . '-' . $rid;
    $store->writeManifest($rid, [['id_bin' => $id, 'hash' => hash('sha256', $id, true), 'dag' => null]]);
    $ep = (int) $store->run($pid, $rid)['lease_epoch'];
    $c = $store->claim($rid, 'w', $ep, 1);
    $fs = [];
    for ($i = 0; $i < $n; $i++) {
        $fs[] = ['ordinal' => $c[0]['ordinal'], 'project_id' => $pid, 'generation_id' => $gen,
                 'identity' => hash('sha256', $id . '-' . $i, true),
                 'record_hash' => $c[0]['hash'], 'record_id_bin' => $id,
                 'host_form' => 'fa', 'field' => 'x' . $i, 'rule_source_id' => 'r1',
                 'rule_revision' => str_repeat('c', 64), 'check_type' => 'required',
                 'reason_code' => 'required-blank',
                 'value_bin' => $expires === null ? null : 'SECRET-' . $i,
                 'value_fingerprint' => $expires === null ? null : hash('sha256', 'v' . $i, true),
                 'value_expires_at' => $expires];
    }
    $ok = $store->commitBatch($rid, 'w', $ep, ['bytes' => 0, 'findings' => $fs, 'records' => [
        ['ordinal' => $c[0]['ordinal'], 'record_hash' => $c[0]['hash'], 'state' => ScanStore::REC_DONE,
         'claim' => $c[0]['claim']]]]);
    if ($ok !== true) return null;
    if ($finish) {
        $store->finish($rid, ScanOutcome::derive(['fenced' => true, 'manifestDone' => true, 'violations' => 1]));
    }
    return ['run' => $rid, 'gen' => $gen];
};
$count = function ($db, $where, array $params) {
    $r = $db->select('SELECT COUNT(*) FROM ' . Schema::table('finding') . ' WHERE ' . $where, $params);
    return (int) $r[0][0];
};
$prefix = function ($key, $table, $n) use ($A) {
    return $key === '' ? [] : array_slice(uv_index_columns($A, Schema::table($table), $key), 0, $n);
};

// -- ScanRetention: three clocks, and nothing silently loses a finding --------
{
    $ret = new ScanRetention($dbA);
    $store = new SqlScanStore($dbA);
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
            'state' => ScanStore::REC_DONE)),
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
            'z_score' => '-3.40', 'growth_reference' => 'who-wfa',
            'value_expires_at' => '2000-01-01 00:00:00'))));

    check('retention: nothing expires before its time',
        $ret->expireValues('1999-01-01 00:00:00') === 0);
    check('retention: the preview counts what the scoped sweep will clear',
        $ret->preview($PID, [])['values_to_clear'] === 1);
    check('retention: an overdue value is cleared', $ret->expireValues() === 1);
    $v = $ca->query('SELECT value_bin, value_expires_at, z_score, growth_reference FROM ' . Schema::table('finding')
        . " WHERE record_id_bin = 'R1'", array());
    check('retention: the value is gone', $v[0][0] === null && $v[0][1] === null);
    // the z-score gives the measurement back, so it goes with the value; the reference is the rule's
    check('retention: the z-score goes with it, the reference stays (got ' . json_encode($v[0]) . ')',
        $v[0][2] === null && $v[0][3] === 'who-wfa');
    // The row survives: a report that shrinks as it ages reads as the project
    // having improved, which is the misreading this module exists to prevent.
    // NOT $n: check() keeps its counter in a global of that name, and assigning
    // to it here silently replaced an integer with a result set - the suite then
    // died incrementing an array, several checks later and nowhere near the
    // cause. Test-local names in a file that uses globals are a hazard.
    $kept = $ca->query('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . " WHERE record_id_bin = 'R1'", array());
    check('retention: but the FINDING remains', (int) $kept[0][0] === 1);

    // A tightened value policy clears the project's stored previews at once,
    // and a growth finding's z-score with its value.
    $A->query('UPDATE ' . Schema::table('finding') . " SET value_bin = 'SECRET', z_score = '-3.40'
        WHERE record_id_bin = 'R1' AND project_id = " . $PID);
    $ret->revokePreviews($PID, 99);
    $v = $ca->query('SELECT value_bin, z_score, growth_reference FROM ' . Schema::table('finding')
        . " WHERE record_id_bin = 'R1' AND project_id = " . $PID, array());
    check('retention: a revoked preview takes its z-score with it, the reference stays (got ' . json_encode($v) . ')',
        $v[0][0] === null && $v[0][1] === null && $v[0][2] === 'who-wfa');

    // An active run is never purged, however old it looks.
    $A->query('UPDATE ' . Schema::table('scan_run')
        . " SET updated_at = '2000-01-01 00:00:00' WHERE run_id = " . $rid);
    check('retention: an ACTIVE run is never purged, whatever its age',
        $ret->purgeRuns($PID, 1) === ['done' => true, 'rows' => 0, 'runs' => 0, 'cursor' => null]);

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

    // IN A FINALISING PHASE updated_at IS PROGRESS TOO (review rv10a1-1):
    // nothing there moves progress_at, every step moves updated_at, and the
    // status page reads the later of the two. The installation-wide read
    // names no project for such a run, however old its last record.
    $phaseWas = $store->run(800, $rid)['phase'];
    $A->query('UPDATE ' . Schema::table('scan_run') . " SET phase = 'unique-finalize',"
        . " progress_at = '2000-01-01 00:00:00', updated_at = UTC_TIMESTAMP() WHERE run_id = " . $rid);
    $recR = new UvRecordingDb($dbA);
    check('retention: a run finalising now is not abandoned, and no project is named for it',
        (new \INSPIRE\UniversalValidator\Scan\ScanRetention($recR))->expireAbandoned(1) === 0
        && $recR->matching('select', array('SELECT run_id FROM')) === array());
    // Nor is a run somebody stopped, however stale (review rv10a1-2): it ends
    // as cancelled, by the finisher Stop uses.
    $A->query('UPDATE ' . Schema::table('scan_run') . " SET phase = 'cancelling', updated_at = '2000-01-01 00:00:00'"
        . ' WHERE run_id = ' . $rid);
    $recR = new UvRecordingDb($dbA);
    check('retention: a stale run left cancelling is not abandoned, and no project is named for it',
        (new \INSPIRE\UniversalValidator\Scan\ScanRetention($recR))->expireAbandoned(1) === 0
        && $recR->matching('select', array('SELECT run_id FROM')) === array());
    $A->query('UPDATE ' . Schema::table('scan_run') . " SET phase = '" . $phaseWas . "',"
        . ' progress_at = UTC_TIMESTAMP() WHERE run_id = ' . $rid);

    // Now age the PROGRESS clock, which is the one the predicate reads.
    $A->query('UPDATE ' . Schema::table('scan_run')
        . " SET progress_at = '2000-01-01 00:00:00' WHERE run_id = " . $rid);
    $epBefore = (int) $store->run(800, $rid)['lease_epoch'];
    check('retention: an abandoned run is expired', $ret->expireAbandoned(1) === 1);
    $ex = $store->run(800, $rid);
    check('retention: as a real terminal state, never as complete',
        $ex['terminal'] === ScanOutcome::EXPIRED
        && $ex['coverage'] === ScanOutcome::COV_PARTIAL);
    // THE ROW EVERY OTHER WRITER OF AN EXPIRED RUN LEAVES (B6a). This UPDATE
    // wrote its own sentence and left detail, clean and the counts alone.
    $want = ScanOutcome::derive(['expired' => true]);
    check('retention: the reaped row is finish(derive([expired]))\'s, sentence and decision included',
        $ex['terminal_reason'] === $want['why'] && $ex['detail'] === $want['detail']
        && $ex['clean'] === null && (int) $ex['not_examined'] === 0);
    // Risk 5: a browser worker returning after the reap must fail the fence.
    check('retention: and the lease epoch moved in the same write',
        (int) $ex['lease_epoch'] === $epBefore + 1);
    $au = $dbA->select('SELECT event, actor, detail FROM ' . Schema::table('scan_audit')
        . ' WHERE project_id = ? AND run_id = ?', [$PID, $rid]);
    check('retention: and the reap left an audit entry naming the run, the reaper and the window (got '
        . json_encode($au) . ')',
        count($au) === 1 && $au[0][0] === ScanStore::AUDIT_EXPIRE && $au[0][1] === 'cron'
        && json_decode((string) $au[0][2], true) === ['stale_hours' => 1]);
    check('retention: releasing the project slot for the next scan',
        $store->startRun($PID, array('created_by' => 'bob'))['ok'] === true);
    check('retention: and expiring it again does nothing', $ret->expireAbandoned(1) === 0);

    // Purge cascades to every child table.
    $A->query('UPDATE ' . Schema::table('scan_run')
        . " SET updated_at = '2000-01-01 00:00:00' WHERE run_id = " . $rid);
    $p = $ret->purgeRuns($PID, 1);
    check('retention: a finished, aged run purges, and that was the last one due',
        $p['runs'] === 1 && $p['done'] === true && $p['cursor'] === $rid);
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

// -- THE VALUE SWEEP, A WINDOW OF IDS AT A TIME -------------------------------
//
// A primary-key range rather than a LIMIT, because value_expires_at has no
// index: a LIMIT page re-reads everything an earlier page cleared. Windows of
// 4 ids over 13 overdue previews here, 9 of this project's and 4 of the
// neighbour's, so a scoped sweep and an installation-wide one can be told apart.
$EXP = 810;
{
    $ret = new ScanRetention($dbA);
    $mine = $plantRun($dbA, $EXP, 9, '2000-01-01 00:00:00');
    $theirs = $plantRun($dbA, uv_neighbour($EXP), 4, '2000-01-01 00:00:00');
    $future = $plantRun($dbA, $EXP, 2, '2999-01-01 00:00:00');
    check('expire: the fixture holds 9 + 4 overdue previews and 2 that are not due',
        is_array($mine) && is_array($theirs) && is_array($future)
        && $count($dbA, 'value_bin IS NOT NULL AND value_expires_at <= ?', ['2001-01-01 00:00:00']) === 13);
    check('expire: the preview of one project counts that project\'s share only',
        $ret->preview($EXP, [])['values_to_clear'] === 9);

    $rec = new UvRecordingDb($dbA);
    $paged = new ScanRetention($rec);
    $cursor = 0; $perPage = []; $calls = 0;
    do {
        $p = $paged->expireValuesPage($cursor, $EXP, null, 4);
        $perPage[] = $p['rows'];
        $cursor = $p['cursor'];
        $calls++;
    } while (!$p['done'] && $calls < 1000);
    check('expire: a scoped sweep is finished, a window at a time',
        $p['done'] === true && $cursor >= $p['top'] && array_sum($perPage) === 9 && max($perPage) <= 4);
    check('expire: and cleared this project\'s overdue previews and no one else\'s',
        $count($dbA, 'project_id = ? AND value_bin IS NOT NULL AND value_expires_at <= ?', [$EXP, '2001-01-01'])
            === 0
        && $count($dbA, 'project_id = ? AND value_bin IS NOT NULL', [uv_neighbour($EXP)]) === 4
        && $count($dbA, 'project_id = ? AND value_bin IS NOT NULL', [$EXP]) === 2);
    $u = $rec->matching('exec', ['UPDATE ' . Schema::table('finding'), 'finding_id > ?']);
    $plan = $u ? uv_explain_rows($A, $u[0][1], $u[0][2]) : [];
    $key = $u ? uv_explain_key($A, $u[0][1], $u[0][2]) : '';
    echo '  plan expire (scoped): ' . implode(' ', $plan) . "\n";
    check('expire: each scoped window is bounded by a key: the id range, or this project\'s rows',
        $key === 'PRIMARY' || $prefix($key, 'finding', 1) === ['project_id']);

    $rec->log = [];
    $all = $paged->expireValues();
    $u = $rec->matching('exec', ['UPDATE ' . Schema::table('finding'), 'finding_id > ?']);
    $plan = $u ? uv_explain_rows($A, $u[0][1], $u[0][2]) : [];
    echo '  plan expire (installation): ' . implode(' ', $plan) . ' windows=' . count($u) . "\n";
    check('expire: the installation-wide sweep clears the rest, and only what is due',
        $all === 4 && $count($dbA, 'value_bin IS NOT NULL', []) === 2);
    check('expire: through the primary key, one PAGE window per statement',
        count($u) >= 1 && uv_explain_key($A, $u[0][1], $u[0][2]) === 'PRIMARY'
        && $u[0][2][1] - $u[0][2][0] === ScanRetention::PAGE);
    check('expire: the rows stay, only the values go',
        $count($dbA, 'project_id IN (?, ?)', [$EXP, uv_neighbour($EXP)]) === 15);
}

// -- REVOCATION: THE BUMP AT ONCE, THE CLEAR A PAGE AT A TIME -----------------
//
// Project 820 has two generations of previews - the newer one with a row the
// previous run's supersede closed, so the closed slice is walked too - and the
// neighbour holds previews in a generation of the same number. Pages of 2.
$REV = 820;
{
    $old = $plantRun($dbA, $REV, 3, '2999-01-01 00:00:00');
    $new = $plantRun($dbA, $REV, 4, '2999-01-01 00:00:00');
    $nbr = $plantRun($dbA, uv_neighbour($REV), 3, '2999-01-01 00:00:00');
    check('revoke: the neighbour\'s previews sit in a generation of the same number',
        is_array($nbr) && is_array($old) && $nbr['gen'] === $old['gen']);
    // One of the newer generation's rows closed, so it is in the NULL slice.
    $A->query('UPDATE ' . Schema::table('finding') . ' SET active_slot = NULL, valid_to_seq = 99
        WHERE project_id = ' . $REV . ' AND generation_id = ' . (int) $new['gen'] . ' ORDER BY finding_id LIMIT 1');

    $rec = new UvRecordingDb($dbA);
    $ret = new ScanRetention($rec);
    $first = $ret->revokePreviews($REV, 7, null, 2);
    check('revoke: the first call marks every run of the project revoked at once',
        $first['runs'] === 2 && $first['rows'] === 2 && $first['done'] === false);
    $states = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_run')
        . " WHERE project_id = ? AND values_state = 'expired' AND policy_revision = 7", [$REV]);
    check('revoke: so nothing is served from them while the clearing goes on',
        (int) $states[0][0] === 2);
    $calls = 1; $rows = [$first['rows']]; $cur = $first['cursor']; $runsAfter = 0;
    do {
        $p = $ret->revokePreviews($REV, 7, $cur, 2);
        $rows[] = $p['rows'];
        $runsAfter += $p['runs'];
        $cur = $p['cursor'];
        $calls++;
    } while (!$p['done'] && $calls < 20);
    check('revoke: a later call finds the bump made and carries on clearing, two rows at most',
        $p['done'] === true && $runsAfter === 0 && max($rows) <= 2 && array_sum($rows) === 7);
    echo '  revoke rows per call: ' . implode(',', $rows) . "\n";
    check('revoke: every preview of the project is gone, closed rows included',
        $count($dbA, 'project_id = ? AND (value_bin IS NOT NULL OR value_fingerprint IS NOT NULL'
            . ' OR value_expires_at IS NOT NULL)', [$REV]) === 0
        && $count($dbA, 'project_id = ?', [$REV]) === 7);
    check('revoke: and the neighbour\'s, in the same generation number, are untouched',
        $count($dbA, 'project_id = ? AND value_bin IS NOT NULL', [uv_neighbour($REV)]) === 3);

    $rec->log = [];
    $ret->revokePreviews($REV, 8, null, 2);
    $read = $rec->matching('select', ['SELECT COUNT(*), MAX(finding_id) FROM (SELECT finding_id']);
    $keys = $read ? uv_explain_keys($A, $read[0][1], $read[0][2]) : [];
    echo '  plan revoke page bound: ' . ($read ? implode(' ', uv_explain_rows($A, $read[0][1], $read[0][2])) : '-') . "\n";
    check('revoke: the page bound is read through ix_page_v2, in finding_id order',
        in_array('ix_page_v2', $keys, true));
    $u = $rec->matching('exec', ['UPDATE ' . Schema::table('finding'), 'finding_id <= ?']);
    $key = $u ? uv_explain_key($A, $u[0][1], $u[0][2]) : '';
    echo '  plan revoke clear: ' . ($u ? implode(' ', uv_explain_rows($A, $u[0][1], $u[0][2])) : '-') . "\n";
    check('revoke: and the clear through the same three-column prefix, or the id range',
        $key === 'PRIMARY' || $prefix($key, 'finding', 3) === ['project_id', 'generation_id', 'active_slot']);
    $bump = $rec->matching('exec', ['UPDATE ' . Schema::table('scan_run'), 'policy_revision < ?']);
    $bkey = $bump ? uv_explain_key($A, $bump[0][1], $bump[0][2]) : '';
    check('revoke: and the bump touches this project\'s runs through a project-leading key',
        $bkey !== '' && $prefix($bkey, 'scan_run', 1) === ['project_id']);
}

// -- A RUN STARTED AFTER A BUMP IS NOT REACHED BY ITS REPEAT (W10A-N1) ---------
//
// Project 822's finished run keeps previews; the cron moves the project to
// revision 5. A run started after that was planned under the policy that
// caused the bump, and starts at 5. A pass that died between the bump and
// its checkpoint repeats the bump with the same revision: the new run, and
// the previews it writes, are left alone.
{
    $T = 822;
    $before = $plantRun($dbA, $T, 2, '2999-01-01 00:00:00');
    $ret = new ScanRetention($dbA);
    $ret->revokePreviews($T, 5, null, 50);
    $after = $plantRun($dbA, $T, 2, '2999-01-01 00:00:00', false, ['values_state' => 'value']);
    $row = $dbA->select('SELECT policy_revision, values_state FROM ' . Schema::table('scan_run')
        . ' WHERE run_id = ?', [$after['run']]);
    check('revision: a run started after the bump starts at the revision it wrote',
        is_array($before) && is_array($after) && (int) $row[0][0] === 5 && $row[0][1] === 'value');
    $again = $ret->revokePreviews($T, 5, null, 50);
    $row = $dbA->select('SELECT policy_revision, values_state FROM ' . Schema::table('scan_run')
        . ' WHERE run_id = ?', [$after['run']]);
    check('revision: so a repeat of that bump moves no run, and the new run keeps its previews',
        $again['runs'] === 0 && (int) $row[0][0] === 5 && $row[0][1] === 'value'
        && $count($dbA, 'project_id = ? AND generation_id = ? AND value_bin IS NOT NULL', [$T, $after['gen']]) === 2);
    uv_clear_project($dbA, $T);
}

// -- A REVOKE CURSOR IS GOOD ONLY FOR THE PROJECT IT WAS ISSUED FOR (R8-3) ----
//
// Project 824's previews are planted first, so every one of its finding ids
// sits below project 826's, in a generation of the same number. A cursor
// issued by 826's first page, handed to 824's sweep, must not let that sweep
// skip 824's rows at or below the cursor's position: it starts 824 over.
{
    $Q = 824; $P = 826;
    $q = $plantRun($dbA, $Q, 4, '2999-01-01 00:00:00');
    $p = $plantRun($dbA, $P, 4, '2999-01-01 00:00:00');
    check('revoke cursor: the two projects\' previews share a generation number',
        is_array($q) && is_array($p) && $q['gen'] === $p['gen']);
    $ret = new ScanRetention($dbA);
    $first = $ret->revokePreviews($P, 3, null, 2);
    check('revoke cursor: the other project\'s first page stops inside its slice',
        $first['done'] === false && is_array($first['cursor']) && (int) $first['cursor']['after'] > 0);
    $cur = $first['cursor'];
    $calls = 0;
    do {
        $r = $ret->revokePreviews($Q, 3, $cur, 2);
        $cur = $r['cursor'];
        $calls++;
    } while (!$r['done'] && $calls < 20);
    check('revoke cursor: a cursor issued for another project starts this one over, so every preview goes',
        $r['done'] === true
        && $count($dbA, 'project_id = ? AND (value_bin IS NOT NULL OR value_fingerprint IS NOT NULL'
            . ' OR value_expires_at IS NOT NULL)', [$Q]) === 0);
    check('revoke cursor: and the project that issued it keeps the previews its sweep has not reached',
        $count($dbA, 'project_id = ? AND value_bin IS NOT NULL', [$P]) === 2);
}

// -- PURGE: CHILDREN FIRST, A PAGE AT A TIME, THE RUN ROW LAST ----------------
$PUR = 830;
{
    $aged = $plantRun($dbA, $PUR, 5, null);
    $keep = $plantRun($dbA, $PUR, 2, null);            // finished, but not old
    $nbp = $plantRun($dbA, uv_neighbour($PUR), 3, null);
    check('purge: the neighbour shares the aged run\'s generation number',
        is_array($aged) && is_array($nbp) && $aged['gen'] === $nbp['gen']);
    // Children of every kind the cascade names, so each DELETE has rows.
    foreach ([['collection-gap', 'a'], ['collection-gap', 'b'], ['not-checked', 'c']] as $ag) {
        (new SqlScanStore($dbA))->addAggregate($aged['run'], $ag[0], $ag[1], '', 1);
    }
    // writeManifest() leaves a plan row since wave 9 (plan_done is the
    // freeze's gate); the statement still makes sure there is one.
    $A->query('INSERT INTO ' . Schema::table('scan_plan') . ' (run_id, updated_at) VALUES ('
        . (int) $aged['run'] . ", UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE run_id = run_id");
    foreach (['unique_candidate', 'unique_group', 'scan_dim'] as $t) {
        $cols = ['unique_candidate' => '(project_id, generation_id, rule_source_id, rule_revision, group_hmac,
                     scope_key, record_hash, record_id_bin, event_id, instance, host_form, field)',
                 'unique_group' => '(project_id, generation_id, group_hmac, phase)',
                 'scan_dim' => '(project_id, generation_id, kind, dim_key)'][$t];
        foreach ([$PUR, uv_neighbour($PUR)] as $p) {
            for ($i = 0; $i < 3; $i++) {
                $vals = ['unique_candidate' => "($p, {$aged['gen']}, 'r', REPEAT('c', 64), UNHEX(SHA2('g$i', 256)),
                             'project', UNHEX(SHA2('r$i', 256)), 'R$i', 0, 1, 'fa', 'x')",
                         'unique_group' => "($p, {$aged['gen']}, UNHEX(SHA2('g$i', 256)), 'published')",
                         'scan_dim' => "($p, {$aged['gen']}, 'form', 'k$i')"][$t];
                $A->query('INSERT INTO ' . Schema::table($t) . ' ' . $cols . ' VALUES ' . $vals);
            }
        }
    }
    $A->query('UPDATE ' . Schema::table('scan_run') . " SET updated_at = '2000-01-01 00:00:00'"
        . ' WHERE run_id = ' . (int) $aged['run']);
    $runLeft = function () use ($dbA, $aged) {
        $r = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_run') . ' WHERE run_id = ?', [$aged['run']]);
        return (int) $r[0][0];
    };

    $rec = new UvRecordingDb($dbA);
    $ret = new ScanRetention($rec);
    $calls = []; $runRowStayed = true;
    do {
        $p = $ret->purgeRuns($PUR, 30, 2);
        $calls[] = $p['rows'] . '/' . $p['runs'];
        if (!$p['done'] && $p['runs'] === 0 && $runLeft() !== 1) $runRowStayed = false;
    } while (!$p['done'] && count($calls) < 50);
    echo '  purge rows/runs per call: ' . implode(',', $calls) . "\n";
    check('purge: resumable across calls, and finished', $p['done'] === true && count($calls) > 3);
    check('purge: the run row stays until the call in which every child answered short',
        $runRowStayed && $runLeft() === 0 && $p['runs'] === 1);
    $deletes = $rec->matching('exec', ['DELETE FROM']);
    $over = false;
    foreach ($deletes as $d) if (strpos($d[1], ' LIMIT 2') === false && strpos($d[1], Schema::table('scan_run')) === false) $over = true;
    check('purge: every child DELETE was bounded by the page', $over === false && count($deletes) > 8);
    check('purge: the aged run\'s children are gone, table by table',
        $count($dbA, 'project_id = ? AND generation_id = ?', [$PUR, $aged['gen']]) === 0
        && (int) $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_aggregate') . ' WHERE run_id = ?', [$aged['run']])[0][0] === 0
        && (int) $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_plan') . ' WHERE run_id = ?', [$aged['run']])[0][0] === 0
        && (int) $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('unique_candidate') . ' WHERE project_id = ?', [$PUR])[0][0] === 0
        && (int) $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('unique_group') . ' WHERE project_id = ?', [$PUR])[0][0] === 0
        && (int) $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_dim') . ' WHERE project_id = ?', [$PUR])[0][0] === 0);
    check('purge: the run that is not old yet keeps everything',
        $count($dbA, 'project_id = ? AND generation_id = ?', [$PUR, $keep['gen']]) === 2);
    check('purge: and the neighbour, in the same generation number, loses nothing',
        $count($dbA, 'project_id = ?', [uv_neighbour($PUR)]) === 3
        && (int) $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('unique_candidate') . ' WHERE project_id = ?', [uv_neighbour($PUR)])[0][0] === 3
        && (int) $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_dim') . ' WHERE project_id = ?', [uv_neighbour($PUR)])[0][0] === 3);

    // THE PLANS: each DELETE through a key that leads with what it filters on.
    $want = ['scan_record' => ['run_id'], 'scan_aggregate' => ['run_id'], 'scan_plan' => ['run_id'],
             'finding' => ['project_id', 'generation_id'], 'unique_candidate' => ['project_id', 'generation_id'],
             'unique_group' => ['project_id', 'generation_id'], 'scan_dim' => ['project_id', 'generation_id']];
    foreach ($want as $t => $lead) {
        // STATISTICS AS THEY ARE NOW, not as InnoDB's background thread last
        // sampled them. These tables held a handful of rows a moment ago and
        // hold none now, and on so few rows MySQL 5.7 picks a table scan or
        // the key by when that thread happened to run: in wave 9 the
        // scan_aggregate DELETE showed no key on three runs in a row, and the
        // key on the same tree with two reads added in front of the EXPLAIN.
        // Base 5b693e0 showed the key. Analyzed first, it shows the key on
        // every server.
        $an = $A->query('ANALYZE TABLE ' . Schema::table($t));
        if ($an instanceof \mysqli_result) $an->free();
        $d = $rec->matching('exec', ['DELETE FROM ' . Schema::table($t) . ' WHERE']);
        $key = $d ? uv_explain_key($A, $d[0][1], $d[0][2]) : '';
        echo '  plan purge ' . $t . ': ' . ($d ? implode(' ', uv_explain_rows($A, $d[0][1], $d[0][2])) : '-') . "\n";
        check('purge: ' . $t . ' is deleted through a key leading with ' . implode(', ', $lead),
            $key !== '' && $prefix($key, $t, count($lead)) === $lead);
    }

    // A GENERATION ANOTHER RUN STILL NAMES. An incremental run reports into its
    // baseline's generation, so purging the baseline run must leave the rows
    // the incremental run still reports.
    $base = $plantRun($dbA, 840, 3, null);
    $inc = $plantRun($dbA, 840, 0, null, true, ['run_kind' => 'incremental',
                                                 'baseline_generation' => $base['gen']]);
    check('purge: the incremental fixture really shares its baseline\'s generation',
        is_array($inc) && $inc['gen'] === $base['gen']);
    $A->query('UPDATE ' . Schema::table('scan_run') . " SET updated_at = '2000-01-01 00:00:00'"
        . ' WHERE run_id = ' . (int) $base['run']);
    $calls = 0;
    do { $p = $ret->purgeRuns(840, 30, 2); $calls++; } while (!$p['done'] && $calls < 50);
    check('purge: a generation another run still names keeps its findings',
        $count($dbA, 'project_id = ? AND generation_id = ?', [840, $base['gen']]) === 3
        && (int) $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_run') . ' WHERE project_id = ?', [840])[0][0] === 1);
}
