<?php
/**
 * scan_sqlstore_fault_php.php — what the store does when the database refuses.
 *
 * THE GAP THIS FILLS. tests/scan_store_contract.php asserts what the store does
 * when it WORKS, against two implementations, and the database matrix runs the
 * same assertions against four real servers. Nothing anywhere asserted what it
 * does when a statement throws — and four methods answered a deadlock with the
 * value that means "another worker took you over", which is the sentence the
 * live pilot chased for five rounds.
 *
 * A REAL SERVER CANNOT BE ASKED FOR A DEADLOCK ON DEMAND, at least not in a
 * suite that has to finish in a second, so the ScanDb is scripted. That is
 * sound here and would not be elsewhere: the assertions are about control flow
 * inside SqlScanStore — which catch fires, whether the transaction was rolled
 * back, what leaves the method — and the class under test is the production
 * one, unmodified. The statements themselves are still checked against InnoDB
 * by tests/mysql/run.php.
 *
 * THE ONE ASSERTION THAT IS THE WHOLE FINDING is `advancePhase throws instead
 * of answering false`. ScanWorker reads false as "there is no next phase" and
 * reports the run DONE, so before this fix a deadlock certified a project on
 * the strength of a transaction that never ran.
 *
 * Run:  php tests/scan_sqlstore_fault_php.php
 */

namespace {
    require_once __DIR__ . '/../php/Scan/ScanOutcome.php';
    require_once __DIR__ . '/../php/Scan/ScanPhase.php';
    require_once __DIR__ . '/../php/Scan/ScanStore.php';
    require_once __DIR__ . '/../php/Scan/ScanStoreUnavailable.php';
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';
    require_once __DIR__ . '/../php/Scan/ScanDb.php';
    require_once __DIR__ . '/../php/Scan/DbError.php';
    require_once __DIR__ . '/../php/Scan/Schema.php';
    require_once __DIR__ . '/../php/Scan/Hmac.php';
    require_once __DIR__ . '/../php/Scan/SqlScanStore.php';
    require_once __DIR__ . '/scan_fault_support.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }
}

namespace INSPIRE\UniversalValidator\Scan {

    /**
     * Run $fn and say what came out: 'unavailable', 'other', or the returned
     * value wrapped so a false return is not mistaken for a caught throw.
     */
    function outcomeOf(callable $fn)
    {
        try {
            return ['kind' => 'returned', 'value' => $fn()];
        } catch (ScanStoreUnavailable $e) {
            return ['kind' => 'unavailable', 'detail' => $e->safeDetail(), 'e' => $e];
        } catch (\Throwable $e) {
            return ['kind' => 'other', 'class' => get_class($e), 'e' => $e];
        }
    }

    /** A live, scanning run for the fenced reads to find. */
    function scanningDb()
    {
        $db = new ScriptedDb();
        $db->rows = [
            // claim() and claimPending() read (epoch, phase, cancel, cursor).
            'FOR UPDATE' => [[0, ScanPhase::SCANNING, null, 0]],
        ];
        return $db;
    }

    // =====================================================================
    // H12 — the four hot-path fences
    // =====================================================================
    //
    // Each is broken at its FIRST statement inside the transaction, which is
    // where a lock-wait timeout or a deadlock actually lands.

    $db = scanningDb();
    $db->failAt('FOR UPDATE', 1);
    $r = outcomeOf(function () use ($db) {
        return (new SqlScanStore($db))->claim(1, 'w1', 0, 10);
    });
    check('fault: claim reports a deadlock as a storage failure, not as a refusal',
        $r['kind'] === 'unavailable');
    check('fault: and rolls the transaction back before it throws', $db->rollbacks === 1);
    check('fault: naming what the server said, for the log',
        isset($r['detail']) && strpos($r['detail'], 'Deadlock') !== false);
    check('fault: with the errno, which survives a wrapper that loses the text',
        isset($r['detail']) && strpos($r['detail'], '1213') !== false);

    $db = scanningDb();
    $db->failAt('FOR UPDATE', 1);
    $r = outcomeOf(function () use ($db) {
        return (new SqlScanStore($db))->claimPending(1, 'w1', 0, 10);
    });
    check('fault: claimPending does the same, rather than reporting no stragglers',
        $r['kind'] === 'unavailable' && $db->rollbacks === 1);

    $db = scanningDb();
    $db->failAt('FOR UPDATE', 1);
    $r = outcomeOf(function () use ($db) {
        return (new SqlScanStore($db))->releaseClaims(1, 0, [1, 2, 3]);
    });
    check('fault: releaseClaims does not report zero rows handed back',
        $r['kind'] === 'unavailable' && $db->rollbacks === 1);

    // THE ONE THAT REPORTED A RUN DONE. advancePhase's false is read by
    // ScanWorker as "there is no next phase".
    $db = new ScriptedDb();
    $db->rows = ['FOR UPDATE' => [[ScanPhase::SCANNING, 0, null]]];
    $db->failAt('FOR UPDATE', 1);
    $r = outcomeOf(function () use ($db) {
        return (new SqlScanStore($db))->advancePhase(1, 0, ScanPhase::CATCH_UP);
    });
    check('fault: advancePhase throws rather than answering "no next phase"',
        $r['kind'] === 'unavailable' && $db->rollbacks === 1);

    // A GENUINE REFUSAL STILL RETURNS ITS OLD VALUE. This is the half of the
    // change that is easy to lose: only failure moved, and the fence's own
    // vocabulary is untouched.
    $db = scanningDb();
    $r = outcomeOf(function () use ($db) {
        return (new SqlScanStore($db))->claim(1, 'w1', 7, 10);   // epoch 7 != 0
    });
    check('fault: a moved epoch is still a plain false, not an exception',
        $r['kind'] === 'returned' && $r['value'] === false);

    $db = new ScriptedDb();
    $db->rows = ['FOR UPDATE' => [[0, ScanPhase::SCANNING, '2026-01-01 00:00:00', 0]]];
    $r = outcomeOf(function () use ($db) {
        return (new SqlScanStore($db))->claimPending(1, 'w1', 0, 10);
    });
    check('fault: a cancelled run is still refused rather than reported empty',
        $r['kind'] === 'returned' && $r['value'] === false);

    $db = new ScriptedDb();       // no run row at all
    $r = outcomeOf(function () use ($db) {
        return (new SqlScanStore($db))->advancePhase(1, 0, ScanPhase::CATCH_UP);
    });
    check('fault: a run that does not exist is still a plain false',
        $r['kind'] === 'returned' && $r['value'] === false);

    // A ROLLBACK THAT FAILS TOO must not replace the diagnosis. This is the
    // shape of a connection that has gone away: the statement throws, and so
    // does the attempt to undo it.
    $db = scanningDb();
    $db->rollbackFails = true;
    $db->failAt('FOR UPDATE', 1);
    $r = outcomeOf(function () use ($db) {
        return (new SqlScanStore($db))->claim(1, 'w1', 0, 10);
    });
    check('fault: a rollback that fails does not hide what actually failed',
        $r['kind'] === 'unavailable' && strpos($r['detail'], 'Deadlock') !== false);

    // NOTHING THE SERVER SAID IS FIT TO PUT ON A PAGE, so the class carries two
    // strings and only one of them is public.
    check('fault: the operator sentence names no table, column, value or number',
        preg_match('/\d/', ScanStoreUnavailable::OPERATOR_TEXT) === 0
        && stripos(ScanStoreUnavailable::OPERATOR_TEXT, 'sql') === false
        && stripos(ScanStoreUnavailable::OPERATOR_TEXT, 'uv_') === false);
    check('fault: and the exception message is fixed, so an uncaught throw discloses nothing',
        (new ScanStoreUnavailable('Duplicate entry for key uv_finding.uq_active_identity'))
            ->getMessage() === 'scan storage unavailable');

    // The detail is built by DbError and by nothing else, so a value inside the
    // server's text is withheld here exactly as it is everywhere else.
    $leaky = new \RuntimeException(
        "Duplicate entry 'PATIENT-0042' for key 'uv_finding.uq_active_identity'", 1062);
    $u = ScanStoreUnavailable::from($leaky);
    check('fault: the logged detail keeps the key name',
        strpos($u->safeDetail(), 'uq_active_identity') !== false);
    check('fault: and withholds the value that collided',
        strpos($u->safeDetail(), 'PATIENT-0042') === false);

    // =====================================================================
    // M10 — busy is a fact about the table, not about the error
    // =====================================================================

    // CONTENTION. The insert fails and the probe finds the slot held, which is
    // the only case that may answer busy.
    $db = new ScriptedDb();
    $db->rows = ['active_slot = 1' => [[1]], 'LAST_INSERT_ID' => [[3]]];
    $db->failAt('INSERT INTO ' . Schema::table('scan_run'), 1, new \RuntimeException(
        "Duplicate entry '700-1' for key 'uv_scan_run.uq_project_active'", 1062));
    $r = outcomeOf(function () use ($db) {
        return (new SqlScanStore($db))->startRun(700, ['created_by' => 'alice']);
    });
    check('start: a genuine slot collision still answers busy',
        $r['kind'] === 'returned' && $r['value']['ok'] === false && $r['value']['busy'] === true);
    // IN THE CONTRACT'S WORDS, not this file's copy of them. The literal that
    // stood here was one of THREE copies of the sentence - one per store, plus
    // a helper written to keep them identical that nothing ever called - and
    // two of the three had already drifted from the third. Asserting the
    // constant means a later edit to the wording cannot leave one store
    // disagreeing with the other while both suites stay green.
    check('start: in the contract one refusal sentence, naming nobody and nothing',
        $r['kind'] === 'returned'
        && $r['value']['why'] === ScanStore::BUSY_WHY
        && preg_match('/\d/', $r['value']['why']) === 0);

    // A FAULT. Same failed insert, but no run holds the slot — a missing table,
    // a column too short, a connection that dropped. Telling an operator to
    // wait for this is a wait that never ends.
    $db = new ScriptedDb();       // the probe finds no active run
    $db->failAt('INSERT INTO ' . Schema::table('scan_run'), 1, new \RuntimeException(
        "Data too long for column 'created_by' at row 1", 1406));
    $r = outcomeOf(function () use ($db) {
        return (new SqlScanStore($db))->startRun(700, ['created_by' => str_repeat('x', 5000)]);
    });
    check('start: a write failure over an EMPTY slot is not reported as busy',
        $r['kind'] === 'unavailable');
    check('start: and the log gets the column the server named',
        isset($r['detail']) && strpos($r['detail'], 'created_by') !== false);

    // THE PROBE ITSELF FAILING is also the answer: a database that cannot be
    // read from is not a database holding a slot.
    $db = new ScriptedDb();
    $db->failAt('INSERT INTO ' . Schema::table('scan_run'), 1, new \RuntimeException('MySQL server has gone away', 2006));
    $db->failAt('active_slot = 1', 1, new \RuntimeException('MySQL server has gone away', 2006));
    $r = outcomeOf(function () use ($db) {
        return (new SqlScanStore($db))->startRun(700, []);
    });
    check('start: when the probe fails too, that is a fault and not contention',
        $r['kind'] === 'unavailable');

    // THE PROBE COSTS NOTHING ON THE HAPPY PATH. It is on the failure branch
    // only, so an ordinary start is still one insert and one read.
    $db = new ScriptedDb();
    $db->rows = ['SELECT run_id FROM' => [[42]],
                 'SELECT run_id, project_id' => [[42, 700, null, 'planning', null, 'partial',
                    'complete', 'none', 1, str_repeat('0', 64), 0, 0, 0, 0, 1, 'alice', 0, 0,
                    null, null, null]]];
    $r = outcomeOf(function () use ($db) {
        return (new SqlScanStore($db))->startRun(700, []);
    });
    check('start: a successful start still succeeds',
        $r['kind'] === 'returned' && $r['value']['ok'] === true);
    $probes = 0;
    foreach ($db->log as $sql) {
        if (strpos($sql, 'SELECT 1 FROM') !== false) $probes++;
    }
    check('start: and asks the contention question zero times when nothing failed',
        $probes === 0);
}

namespace {
    echo "scan_sqlstore_fault_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
