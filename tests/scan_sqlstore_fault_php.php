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
    require_once __DIR__ . '/../php/Scan/WorkerSlots.php';
    require_once __DIR__ . '/../php/Scan/ScanRetention.php';
    // CL-1 and the catch-up resolver: a record-list cache that is not the
    // project, and a probe that cannot answer.
    require_once __DIR__ . '/../php/Scan/RecordManifestSource.php';
    require_once __DIR__ . '/../php/Scan/SourceFence.php';
    require_once __DIR__ . '/../php/Scan/ScanPlanner.php';
    require_once __DIR__ . '/../php/Scan/RollupBuilder.php';
    require_once __DIR__ . '/../php/Scan/ScanPromotion.php';
    require_once __DIR__ . '/../php/Scan/CatchUp.php';
    // R5: the planning walk under a changing record-list cache, budgeted.
    require_once __DIR__ . '/../php/Scan/WorkBudget.php';
    require_once __DIR__ . '/../php/Scan/ScanPolicy.php';
    require_once __DIR__ . '/scan_fault_support.php';
    require_once __DIR__ . '/scan_run_row.php';

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
            // AND, BEFORE EITHER OPENS A TRANSACTION, the claim token: which
            // project this run belongs to, so the per-project sequence can be
            // drawn on. A stub that did not answer this would send both methods
            // down the "no such run" path and every fence assertion below would
            // pass without the fence having been reached.
            'SELECT project_id FROM' => [[77]],
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
        return (new SqlScanStore($db))->releaseClaims(1, 0, 'w1', [1 => 9, 2 => 9, 3 => 9]);
    });
    check('fault: releaseClaims does not report zero rows handed back',
        $r['kind'] === 'unavailable' && $db->rollbacks === 1);

    // THE STATEMENT BEFORE THE TRANSACTION. Allocating a claim token is a read
    // and a write against uv_project_seq, and it happens OUTSIDE claim()'s try
    // so the sequence row is not locked for the life of a claim. Outside the
    // try is also outside the classification the rest of this file asserts, so
    // it gets its own: a deadlock there must still be a storage failure and not
    // a fence refusal, or the worker is told to come back later about a
    // database that is down.
    $db = scanningDb();
    $db->failAt('SELECT project_id FROM', 1);
    $r = outcomeOf(function () use ($db) {
        return (new SqlScanStore($db))->claim(1, 'w1', 0, 10);
    });
    check('fault: a fault allocating the claim token is a storage failure too',
        $r['kind'] === 'unavailable' && strpos($r['detail'], 'Deadlock') !== false);
    check('fault: and no transaction was opened to roll back',
        $db->rollbacks === 0 && !in_array('BEGIN', $db->log, true));

    // A RUN THAT IS GONE IS STILL A REFUSAL, from the same statement. The token
    // allocator answering zero must reach the caller as claim()'s ordinary
    // false - the answer its own fence would have given one statement later -
    // rather than as an exception about a missing project.
    $db = new ScriptedDb();       // no run row at all
    $r = outcomeOf(function () use ($db) {
        return (new SqlScanStore($db))->claim(1, 'w1', 0, 10);
    });
    check('fault: a run with no project refuses rather than throwing',
        $r['kind'] === 'returned' && $r['value'] === false);

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
    // BY NAME. run() selects ScanStore::RUN_COLUMNS and refuses a row of any
    // other length; this stub was once a bare positional list, and it drifted
    // when run_seq joined the projection.
    $db->rows = ['SELECT run_id FROM' => [[42]],
                 'SELECT run_id, project_id' => [uv_run_row(['run_id' => 42,
                    'project_id' => 700, 'created_by' => 'alice'])]];
    $r = outcomeOf(function () use ($db) {
        return (new SqlScanStore($db))->startRun(700, []);
    });
    check('start: a successful start still succeeds',
        $r['kind'] === 'returned' && $r['value']['ok'] === true);
    // ok:true was not enough. The projection can drift, array_combine can
    // refuse the row, and startRun still answers ok with `run` set to false -
    // which is what happened when run_seq was added to run() and this stub was
    // not. Ask for the run itself.
    check('start: and hands back the run row it just created',
        isset($r['value']['run']) && is_array($r['value']['run'])
        && (int) $r['value']['run']['run_id'] === 42
        && (int) $r['value']['run']['project_id'] === 700);
    $probes = 0;
    foreach ($db->log as $sql) {
        if (strpos($sql, 'SELECT 1 FROM') !== false) $probes++;
    }
    check('start: and asks the contention question zero times when nothing failed',
        $probes === 0);

    // =====================================================================
    // THE RUN ROW HAS ONE COLUMN LIST, AND A ROW THAT DISAGREES IS REFUSED
    // =====================================================================
    //
    // array_combine() used to answer FALSE for a row of the wrong length (it
    // throws from PHP 8), so on the 7.4 floor a drifted projection read as "no
    // such run" and said nothing. run() now names both lengths.
    $short = uv_run_row(['run_id' => 42, 'project_id' => 700]);
    array_pop($short);
    $db = new ScriptedDb();
    $db->rows = ['SELECT run_id, project_id' => [$short]];
    $r = outcomeOf(function () use ($db) { return (new SqlScanStore($db))->run(700, 42); });
    check('run: a row one column short is refused, not read as a run',
        $r['kind'] === 'other' && $r['class'] === 'LogicException');
    check('run: and the refusal names both lengths',
        $r['kind'] === 'other'
        && strpos($r['e']->getMessage(), (string) count($short)) !== false
        && strpos($r['e']->getMessage(), (string) count(ScanStore::RUN_COLUMNS)) !== false);
    $db = new ScriptedDb();
    $db->rows = ['SELECT run_id, project_id' => [uv_run_row(['run_id' => 42, 'project_id' => 700])]];
    $r = outcomeOf(function () use ($db) { return (new SqlScanStore($db))->run(700, 42); });
    check('run: a full row comes back keyed by RUN_COLUMNS, in that order',
        $r['kind'] === 'returned' && array_keys($r['value']) === ScanStore::RUN_COLUMNS);
    $sent = '';
    foreach ($db->log as $sql) {
        if (strpos($sql, 'SELECT run_id, project_id') !== false) $sent = $sql;
    }
    $named = true;
    foreach (ScanStore::RUN_COLUMNS as $c) {
        if (!preg_match('/\b' . preg_quote($c, '/') . '\b/', $sent)) $named = false;
    }
    check('run: and the statement selects every one of them', $sent !== '' && $named);
    $db = new ScriptedDb();
    $r = outcomeOf(function () use ($db) { return (new SqlScanStore($db))->run(700, 42); });
    check('run: no row is still "no such run"',
        $r['kind'] === 'returned' && $r['value'] === null);

    // =====================================================================
    // P11 - "is the manifest complete" fails CLOSED
    // =====================================================================
    //
    // The cheap way to ask is `SELECT 1 ... LIMIT 1` and call no row "complete".
    // No row is also what an unreadable answer looks like: ModuleDb::rows()
    // returns [] for any result shape it does not recognise. So the statement
    // is an EXISTS, which always answers with one row, and an empty result
    // keeps meaning "I could not tell".

    $db = new ScriptedDb();       // answers every SELECT with no rows at all
    check('complete: an answer that never arrived is NOT complete',
        (new SqlScanStore($db))->manifestComplete(1) === false);
    $asked = '';
    foreach ($db->log as $stmt) {
        if (strpos($stmt, Schema::table('scan_record')) !== false) $asked = $stmt;
    }
    check('complete: the question is an existence test over the state index',
        strpos($asked, 'SELECT EXISTS(') === 0 && strpos($asked, 'FORCE INDEX (ix_run_state)') !== false
        && stripos($asked, 'COUNT(') === false);
    // TWO ANSWERS IN ONE ROW since W6-N1: is any manifest row unfinished, and
    // does the run exist at all. Yes needs both, read as numbers.
    $db = new ScriptedDb();
    $db->rows = ['SELECT EXISTS(' => [[0, 1]]];
    check('complete: no unfinished row of a run that exists is complete',
        (new SqlScanStore($db))->manifestComplete(1) === true);
    $db = new ScriptedDb();
    $db->rows = ['SELECT EXISTS(' => [['1', '1']]];
    check('complete: one unfinished row is not, whatever type the driver gave it',
        (new SqlScanStore($db))->manifestComplete(1) === false);
    $db = new ScriptedDb();
    $db->rows = ['SELECT EXISTS(' => [[null, 1]]];
    check('complete: and a NULL answer is not complete either',
        (new SqlScanStore($db))->manifestComplete(1) === false);
    $db = new ScriptedDb();
    $db->rows = ['SELECT EXISTS(' => [[0, 0]]];
    check('complete: a run that does not exist is NOT complete (W6-N1)',
        (new SqlScanStore($db))->manifestComplete(1) === false);
    $db = new ScriptedDb();
    $db->rows = ['SELECT EXISTS(' => [[0]]];
    check('complete: and half an answer is not one',
        (new SqlScanStore($db))->manifestComplete(1) === false);
    $askedRun = '';
    foreach ($db->log as $stmt) {
        if (strpos($stmt, Schema::table('scan_record')) !== false) $askedRun = $stmt;
    }
    check('complete: the run is looked up in the same statement, by its primary key',
        strpos($askedRun, 'EXISTS(SELECT 1 FROM ' . Schema::table('scan_run') . ' WHERE run_id = ?)') !== false);

    // =====================================================================
    // M9 - findings go to the server a chunk at a time, not one by one
    // =====================================================================
    //
    // commitBatch holds the run row FOR UPDATE while it writes. One INSERT per
    // finding held that lock for 15.9 s on a record with 4,000 findings; one per
    // chunk holds it for 0.85. This is the statement count, without a server.

    /** Answers the row count a real server would give for a multi-row INSERT. */
    $countingDb = new class extends ScriptedDb {
        /** @var int|null when set, the finding INSERT reports this instead */
        public $findingRows = null;
        public function affected()
        {
            if (strpos($this->lastWrite, 'INSERT INTO ' . Schema::table('finding')) !== false) {
                if ($this->findingRows !== null) return $this->findingRows;
                return substr_count($this->lastWrite, '),(') + 1;
            }
            return parent::affected();
        }
    };
    $mkFinding = function ($i) {
        return ['ordinal' => 1, 'project_id' => 700, 'generation_id' => 5,
            'identity' => str_pad((string) $i, 32, 'i'), 'record_hash' => str_repeat('h', 32),
            'record_id_bin' => 'R1', 'host_form' => 'f', 'field' => 'x' . $i,
            'rule_source_id' => 's', 'rule_revision' => str_repeat('0', 64),
            'check_type' => 'assert', 'reason_code' => 'assert'];
    };
    $mkBatch = function ($n) use ($mkFinding) {
        $fs = [];
        for ($i = 0; $i < $n; $i++) $fs[] = $mkFinding($i);
        return ['records' => [['ordinal' => 1, 'state' => ScanStore::REC_DONE, 'claim' => 9,
                               'record_hash' => str_repeat('h', 32)]],
                'findings' => $fs, 'candidates' => []];
    };
    $fenceRow = [[0, null, 700, 5, 5, null]];

    $cdb = clone $countingDb;
    $cdb->rows = ['FOR UPDATE' => $fenceRow];
    $N = SqlScanStore::FINDING_CHUNK + 1;
    $got = (new SqlScanStore($cdb))->commitBatch(1, 'w1', 0, $mkBatch($N));
    $inserts = [];
    foreach ($cdb->log as $stmt) {
        if (strpos($stmt, 'INSERT INTO ' . Schema::table('finding')) !== false) $inserts[] = $stmt;
    }
    check('batched: one finding more than a chunk commits', $got === true);
    check('batched: in two statements, not in ' . $N, count($inserts) === 2);
    check('batched: the first carries a full chunk and the second the one left over',
        count($inserts) === 2
        && substr_count($inserts[0], '),(') + 1 === SqlScanStore::FINDING_CHUNK
        && substr_count($inserts[1], '),(') === 0);
    // A PLAIN INSERT. Either softer form stores one of two colliding findings
    // and reports success, which is the defect the unique key exists to refuse.
    $soft = false;
    foreach ($inserts as $stmt) {
        if (stripos($stmt, 'DUPLICATE') !== false || stripos($stmt, 'IGNORE') !== false) $soft = true;
    }
    check('batched: and neither statement softens a collision into a success', $soft === false);
    // The column list and the placeholders come from one array. If they ever
    // disagreed the server would refuse; here the arithmetic is checked instead.
    $lastInsert = $inserts ? $inserts[count($inserts) - 1] : '';
    $colCount = preg_match('/\(([a-z_, \r\n]+)\)\s*VALUES/', $lastInsert, $m)
        ? count(explode(',', $m[1])) : 0;
    check('batched: every named column has exactly one placeholder',
        $colCount === 32 && substr_count($lastInsert, '?') === $colCount);
    check('batched: a chunk stays inside the server placeholder limit',
        SqlScanStore::FINDING_CHUNK * 29 <= 65535);

    // A SERVER THAT STORED FEWER ROWS THAN IT WAS SENT. Nothing in MySQL does
    // this for a plain INSERT that succeeded - which is the point of asking: if
    // it ever happens the project would read cleaner than it is.
    $short = clone $countingDb;
    $short->rows = ['FOR UPDATE' => $fenceRow];
    $short->findingRows = 2;
    $got = (new SqlScanStore($short))->commitBatch(1, 'w1', 0, $mkBatch(3));
    check('batched: a short count refuses the batch rather than keeping part of it',
        is_string($got) && strpos($got, 'database refused') !== false && $short->rollbacks === 1);

    $none = clone $countingDb;
    $none->rows = ['FOR UPDATE' => $fenceRow];
    $got = (new SqlScanStore($none))->commitBatch(1, 'w1', 0, $mkBatch(0));
    $sent = 0;
    foreach ($none->log as $stmt) {
        if (strpos($stmt, 'INSERT INTO ' . Schema::table('finding')) !== false) $sent++;
    }
    check('batched: a batch with no findings sends no findings statement at all',
        $got === true && $sent === 0);

    // A CHUNK THAT FAILS PART WAY THROUGH TAKES THE WHOLE BATCH WITH IT. The
    // second INSERT is refused after the first has succeeded; only the
    // transaction can undo the first, so the rollback is what is asserted.
    $mid = clone $countingDb;
    $mid->rows = ['FOR UPDATE' => $fenceRow];
    $mid->failAt('INSERT INTO ' . Schema::table('finding'), 2, new \RuntimeException(
        'Lock wait timeout exceeded; try restarting transaction', 1205));
    $got = (new SqlScanStore($mid))->commitBatch(1, 'w1', 0, $mkBatch(SqlScanStore::FINDING_CHUNK * 2));
    check('batched: a second chunk the server refuses refuses the whole batch',
        is_string($got) && strpos($got, 'database refused') !== false
        && $mid->rollbacks === 1 && !in_array('COMMIT', $mid->log, true));

    // ST-2: A NULL IN A NOT NULL COLUMN NEVER REACHES THE SERVER. Under a
    // non-strict sql_mode a multi-row INSERT stores '' for it and still counts
    // the row, so the count check cannot catch it; the store has to refuse
    // first. This double accepts anything, which is what a lenient server does.
    foreach (ScanStore::FINDING_REQUIRED as $k) {
        $nul = clone $countingDb;
        $nul->rows = ['FOR UPDATE' => $fenceRow];
        $b = $mkBatch(2);
        $b['findings'][1][$k] = null;
        $got = (new SqlScanStore($nul))->commitBatch(1, 'w1', 0, $b);
        $sent = 0;
        foreach ($nul->log as $stmt) {
            if (strpos($stmt, 'INSERT INTO ' . Schema::table('finding')) !== false) $sent++;
        }
        check('null: a finding with no ' . $k . ' refuses the batch before any finding is sent',
            is_string($got) && strpos($got, 'no ' . $k . ';') !== false
            && $sent === 0 && $nul->rollbacks === 1);
    }

    // =====================================================================
    // EE-1 - commitBatch holds one chunk of rows, never the whole batch twice
    // =====================================================================
    //
    // insertFindings() built a 29-column row for EVERY finding before it sent
    // the first chunk, so a batch was held twice over. 25 records of 4,000
    // findings added 138.8 MB and died at memory_limit=256M, before
    // noteAttempts() could count the attempt - so the claims stayed held and
    // the same batch was claimed and killed again for ever. The bound below is
    // stated in chunks rather than megabytes so it means the same on 7.4 and
    // 8.x: what commitBatch adds must be a small multiple of ONE chunk of the
    // batch, whatever the batch's size.

    /** Answers the fence, counts rows, and notes memory as each finding INSERT arrives. */
    $memDb = new class implements ScanDb {
        public $base = 0;
        public $worst = 0;
        public $inserts = 0;
        private $aff = -1;
        private $uncounted = false;
        public function select($sql, array $params = [])
        {
            return strpos($sql, 'FOR UPDATE') !== false ? [[0, null, 700, 5, 5, null]] : [];
        }
        public function exec($sql, array $params = [])
        {
            $this->uncounted = false;
            if (strpos($sql, 'INSERT INTO ' . Schema::table('finding')) !== false) {
                $this->inserts++;
                $this->worst = max($this->worst, memory_get_usage(false) - $this->base);
                $this->aff = intdiv(count($params), 32);
                return;
            }
            $this->aff = 1;
        }
        public function execNoCount($sql, array $params = []) { $this->uncounted = true; }
        public function affected()
        {
            if ($this->uncounted) throw new \LogicException('uncounted');
            return $this->aff;
        }
        public function begin() {}
        public function commit() {}
        public function rollback() {}
    };
    $records = 10;
    $perRecord = 2000;
    $big = ['bytes' => 0, 'records' => [], 'findings' => [], 'candidates' => [], 'marks' => []];
    gc_collect_cycles();
    $empty = memory_get_usage(false);
    for ($r = 1; $r <= $records; $r++) {
        $rh = hash('sha256', 'rec' . $r, true);
        $big['records'][] = ['ordinal' => $r, 'record_hash' => $rh, 'state' => ScanStore::REC_DONE,
                             'version' => '9', 'claim' => 7];
        for ($i = 1; $i <= $perRecord; $i++) {
            // Shaped as UniversalValidator::durableEvaluateRecord() builds one,
            // with the ordinal ScanWorker::batch() adds.
            $big['findings'][] = ['ordinal' => $r, 'project_id' => 700, 'generation_id' => 5,
                'identity' => hash('sha256', "id-$r-$i", true), 'valid_from_seq' => 5,
                'record_hash' => $rh, 'record_id_bin' => 'REC-' . $r, 'event_id' => 101,
                'instance' => $i, 'host_form' => 'visit', 'field' => 'v_' . ($i % 50),
                'rule_source_id' => 'rule-' . ($i % 50), 'rule_revision' => str_repeat('a', 64),
                'rule_ord' => $i % 50, 'check_type' => 'required', 'reason_code' => 'required-blank',
                'dag_key' => null, 'value_bin' => null, 'value_len' => null, 'value_truncated' => 0,
                'value_fingerprint' => null, 'value_expires_at' => null];
        }
    }
    $total = $records * $perRecord;
    $perFinding = (memory_get_usage(false) - $empty) / $total;
    gc_collect_cycles();
    $memDb->base = memory_get_usage(false);
    $got = (new SqlScanStore($memDb))->commitBatch(1, 'w1', 0, $big);
    $chunkBytes = $perFinding * SqlScanStore::FINDING_CHUNK;
    check('memory: a batch of ' . $total . ' findings commits, a chunk at a time',
        $got === true && $memDb->inserts === (int) ceil($total / SqlScanStore::FINDING_CHUNK));
    check('memory: and what commitBatch adds is a few chunks, not a second copy of the batch'
        . ' (added ' . round($memDb->worst / 1048576, 1) . ' MB, one chunk is about '
        . round($chunkBytes / 1048576, 2) . ' MB)',
        $memDb->worst < 4 * $chunkBytes);
    unset($big);

    // =====================================================================
    // B4b - a mark carried in a batch REACHES THE SERVER
    // =====================================================================
    //
    // It did not, the first time. commitBatch already held a local called
    // $marks - the placeholder string for the supersede - and the list of
    // presence marks shared the name, so any batch that closed a record's old
    // findings overwrote the list with "?,?" and wrote no mark at all. The
    // in-memory store has no placeholders and passed; four real servers said
    // so. This is that finding without a server: the batch below finishes a
    // record WITH a record hash, which is what builds the supersede.
    $aggregateWrites = function ($db) {
        $at = [];
        foreach ($db->log as $i => $stmt) {
            if (strpos($stmt, 'INSERT INTO ' . Schema::table('scan_aggregate')) !== false) $at[] = $i;
        }
        return $at;
    };
    $firstAt = function ($db, $needle) {
        foreach ($db->log as $i => $stmt) if (strpos($stmt, $needle) !== false) return $i;
        return -1;
    };
    $mark = ['kind' => 'rule-problem', 'axis1' => '4', 'axis2' => 'unlocatable', 'samples' => 'why'];

    $mk = clone $countingDb;
    $mk->rows = ['FOR UPDATE' => $fenceRow];
    $withMark = $mkBatch(1);
    $withMark['marks'] = [$mark];
    $got = (new SqlScanStore($mk))->commitBatch(1, 'w1', 0, $withMark);
    $at = $aggregateWrites($mk);
    check('marks: a batch that supersedes a record still writes its mark',
        $got === true && count($at) === 1
        && $firstAt($mk, 'SET active_slot = NULL, valid_to_seq') >= 0);
    check('marks: as a presence - the duplicate arm SETS the count, it does not add to it',
        count($at) === 1 && strpos($mk->log[$at[0]], 'cnt = 1') !== false
        && strpos($mk->log[$at[0]], 'cnt = cnt') === false);
    check('marks: after the findings and before the counters, inside the one transaction',
        count($at) === 1
        && $at[0] > $firstAt($mk, 'INSERT INTO ' . Schema::table('finding'))
        && $at[0] < $firstAt($mk, 'SET manifest_done = manifest_done'));

    // NO RECORD HELD, NO STANDING. The row UPDATE matches nothing, so this
    // worker lost every record it offered; the batch still commits - there is
    // nothing to refuse - and says nothing about the run.
    $lost = clone $countingDb;
    $lost->rows = ['FOR UPDATE' => $fenceRow];
    $lost->affectedNext = 0;
    $got = (new SqlScanStore($lost))->commitBatch(1, 'w1', 0, $withMark);
    check('marks: a batch that held none of its records writes none of its marks',
        $got === true && $aggregateWrites($lost) === []);

    $bad = clone $countingDb;
    $bad->rows = ['FOR UPDATE' => $fenceRow];
    $withBad = $mkBatch(1);
    $withBad['marks'] = [['kind' => 'rule-problem', 'axis1' => '4']];
    $r = outcomeOf(function () use ($bad, $withBad) {
        return (new SqlScanStore($bad))->commitBatch(1, 'w1', 0, $withBad);
    });
    check('marks: one with no axis is refused before the transaction is opened',
        $r['kind'] === 'other' && $r['class'] === 'RuntimeException' && $bad->log === []);

    // =====================================================================
    // CL-1 - REDCap's record list is a cache, and a cache is not the project
    // =====================================================================
    //
    // redcap_record_list is built by REDCap in the background, and
    // redcap_record_counts.record_list_status says how far it got. exist()
    // answered "gone" for any id the list did not hold, and open() walked the
    // list whenever it held one row for the project. A half-built list
    // therefore tombstoned live records - through catch-up and through the
    // worker's H18 path - and could leave records off the manifest entirely.

    /**
     * REDCap's three tables, as far as RecordManifestSource reads them.
     * $index is the cache; $data is [record, field_name] rows; $status is the
     * cache's record_list_status, or null for no row; $statusColumn false
     * models a REDCap that keeps no status at all.
     */
    $cacheDb = function (array $index, array $data, $status = null, $statusColumn = true) {
        return new class($index, $data, $status, $statusColumn) implements ScanDb {
            public $index; public $data; public $status; public $statusColumn;
            public $dataFails = false; public $statusFails = false; public $hasData = true;
            public $log = [];
            public function __construct($i, $d, $s, $c)
            {
                $this->index = $i; $this->data = $d; $this->status = $s; $this->statusColumn = $c;
            }
            public function select($sql, array $params = [])
            {
                $this->log[] = $sql;
                if (strpos($sql, 'information_schema.columns') !== false) {
                    if ($params[0] === 'redcap_record_list') return [['project_id'], ['record'], ['dag_id']];
                    if ($params[0] === 'redcap_data') {
                        return $this->hasData ? [['project_id'], ['record'], ['field_name']] : [];
                    }
                    if ($params[0] === 'redcap_record_counts') {
                        return $this->statusColumn ? [['project_id'], ['record_count'], ['record_list_status']]
                                                   : [['project_id'], ['record_count']];
                    }
                    return [];
                }
                if (strpos($sql, 'FROM redcap_record_counts') !== false) {
                    if ($this->statusFails) throw new \RuntimeException('Lock wait timeout exceeded', 1205);
                    return $this->status === null ? [] : [[$this->status]];
                }
                if (strpos($sql, 'FROM redcap_projects') !== false) {
                    return $this->hasData ? [['redcap_data']] : [];
                }
                if (strpos($sql, 'FROM redcap_record_list') !== false) {
                    $ids = $this->index;
                    $want = array_slice($params, 1);
                } else {
                    if ($this->dataFails && strpos($sql, 'DISTINCT record') !== false) {
                        throw new \RuntimeException('Lock wait timeout exceeded', 1205);
                    }
                    $field = strpos($sql, 'field_name = ?') !== false ? $params[1] : null;
                    $ids = [];
                    foreach ($this->data as $row) {
                        if ($field === null || $row[1] === $field) $ids[] = $row[0];
                    }
                    $ids = array_values(array_unique($ids));
                    $want = array_slice($params, $field === null ? 1 : 2);
                }
                sort($ids);
                if (strpos($sql, ' IN (') !== false) {
                    $ids = array_values(array_intersect($ids, array_map('strval', $want)));
                } elseif (preg_match('/record (>?=) \? /', $sql, $cmp)) {
                    $at = (string) $params[count($params) - 1];
                    $ids = array_values(array_filter($ids, function ($r) use ($cmp, $at) {
                        return $cmp[1] === '=' ? $r === $at : strcmp($r, $at) >= 0;
                    }));
                }
                if (preg_match('/LIMIT (\d+)/', $sql, $lim)) $ids = array_slice($ids, 0, (int) $lim[1]);
                // The index walk selects the group beside the record.
                $dag = strpos($sql, 'dag_id') !== false;
                return array_map(function ($r) use ($dag) { return $dag ? [$r, null] : [$r]; }, $ids);
            }
            public function exec($sql, array $params = []) {}
            public function execNoCount($sql, array $params = []) {}
            public function affected() { return 0; }
            public function begin() {}
            public function commit() {}
            public function rollback() {}
        };
    };
    // R1 is in the cache; R2 is only in the data table - created, or edited,
    // while the cache was being rebuilt; R9 is nowhere.
    $data = [['R1', 'record_id'], ['R1', 'dob'], ['R2', 'record_id'], ['R2', 'dob']];

    $o = RecordManifestSource::open($cacheDb(['R1'], $data, null, false), 800, ['pk' => 'record_id']);
    check('cache: with no status kept, a non-empty index is still the walk it was',
        $o['ok'] === true && $o['source']->via() === 'redcap_record_list');
    // A list REDCap calls COMPLETE can still be stale by the time it is asked,
    // so exist() confirms an absence whatever the status said at open().
    $db = $cacheDb(['R1'], $data, 'COMPLETE');
    $open = RecordManifestSource::open($db, 800, ['pk' => 'record_id']);
    $ans = $open['source']->exist(['R1', 'R2', 'R9']);
    check('cache: a record the cache lacks but the data table holds is still there',
        $open['source']->via() === 'redcap_record_list' && $ans['R1'] === true && $ans['R2'] === true);
    check('cache: and a record neither holds is gone', $ans['R9'] === false);

    $db->dataFails = true;
    $ans = $open['source']->exist(['R2', 'R9']);
    check('cache: a confirmation that cannot be read answers "still there"',
        $ans['R2'] === true && $ans['R9'] === true);

    // A source whose data table could not be resolved has nothing to confirm
    // an absence against.
    $none = $cacheDb(['R1'], $data, null, false);
    $none->hasData = false;
    $nOpen = RecordManifestSource::open($none, 800, ['pk' => 'record_id']);
    check('cache: and with no data table to ask, an absence is not a deletion',
        $nOpen['ok'] === true && $nOpen['source']->exist(['R9'])['R9'] === true);

    // THE STATUS. Anything but COMPLETE or FIX_SORT walks the data table.
    foreach (['NOT_STARTED' => false, 'PROCESSING' => false, 'COMPLETE' => true,
              'FIX_SORT' => true] as $status => $index) {
        $o = RecordManifestSource::open($cacheDb(['R1'], $data, $status), 800, ['pk' => 'record_id']);
        check('cache: a list REDCap reports ' . $status . ' is ' . ($index ? '' : 'not ') . 'walked',
            $o['ok'] === true
            && ($index ? $o['source']->via() === 'redcap_record_list'
                       : strpos($o['source']->via(), 'redcap_data') === 0));
    }
    $o = RecordManifestSource::open($cacheDb(['R1'], $data, null), 800, ['pk' => 'record_id']);
    check('cache: a project REDCap has no status row for walks the data table',
        $o['ok'] === true && strpos($o['source']->via(), 'redcap_data') === 0);
    $sf = $cacheDb(['R1'], $data, 'COMPLETE');
    $sf->statusFails = true;
    $o = RecordManifestSource::open($sf, 800, ['pk' => 'record_id']);
    check('cache: a status that cannot be read walks the data table',
        $o['ok'] === true && strpos($o['source']->via(), 'redcap_data') === 0);
    $o = RecordManifestSource::open($cacheDb(['R1'], $data, 'PROCESSING', false), 800, ['pk' => 'record_id']);
    check('cache: a REDCap that keeps no status column is judged by the probe, as before',
        $o['ok'] === true && $o['source']->via() === 'redcap_record_list');
    $p = RecordManifestSource::open($cacheDb(['R1'], $data, 'PROCESSING'), 800, ['pk' => 'record_id']);
    $pg = $p['source']->page(null, [], 10);
    $ids = [];
    foreach ($pg['rows'] as $r) $ids[] = $r['id'];
    check('cache: so a record the half-built list lacks is still on the manifest',
        $ids === ['R1', 'R2']);
    $o = RecordManifestSource::open($cacheDb(['R1'], $data, 'PROCESSING'), 800, []);
    check('cache: and without a record-id field the refusal says the index is incomplete',
        $o['ok'] === false && strpos($o['why'], 'record index is not complete') !== false
        && strpos($o['why'], 'record-id field could not be determined') !== false);

    // =====================================================================
    // CATCH-UP - "gone" is an explicit false, and a probe that throws is not one
    // =====================================================================

    /** A change log with a fixed window, as tests/scan_worker_php.php builds one. */
    $window = function (array $changed) {
        return new class($changed) implements ChangeLog {
            private $changed;
            public function __construct($c) { $this->changed = $c; }
            public function now() { return '500'; }
            public function retained($open) { return ['ok' => true, 'why' => null]; }
            public function changedSince($after, $upTo, $afterId, $limit)
            {
                $out = [];
                foreach ($this->changed as $id => $v) {
                    if ($afterId !== null && strcmp((string) $id, (string) $afterId) <= 0) continue;
                    $out[] = ['id' => (string) $id, 'version' => $v];
                }
                return $out;
            }
        };
    };
    /** A run in catch-up whose records were all examined at version 100. */
    $inCatchUp = function (array $ids) {
        $store = new ArrayScanStore();
        $r = $store->startRun(800, ['created_by' => 'alice', 'fence_open' => '1']);
        $runId = (int) $r['run']['run_id'];
        $recs = [];
        foreach ($ids as $id) $recs[] = ['id_bin' => $id, 'hash' => hash('sha256', $id, true), 'dag' => null];
        $store->writeManifest($runId, $recs);
        $epoch = (int) $store->run(800, $runId)['lease_epoch'];
        $batch = ['bytes' => 0, 'records' => [], 'findings' => []];
        foreach ($store->claim($runId, 'w1', $epoch, count($ids)) as $c) {
            $batch['records'][] = ['ordinal' => $c['ordinal'], 'state' => ScanStore::REC_DONE,
                                   'version' => '100', 'claim' => $c['claim']];
        }
        $store->commitBatch($runId, 'w1', $epoch, $batch);
        $store->advancePhase($runId, $epoch, ScanPhase::CATCH_UP);
        return [$store, $runId, $epoch];
    };
    $hash = function ($id) { return hash('sha256', $id, true); };

    // THE CACHE AND CATCH-UP TOGETHER, which is the reviewer's case: R2 was
    // EDITED during the run and the half-built list does not hold it.
    list($store, $runId, $epoch) = $inCatchUp(['R1', 'R2']);
    $src = RecordManifestSource::open($cacheDb(['R1'], $data, 'COMPLETE'), 800,
        ['pk' => 'record_id'])['source'];
    $cu = new CatchUp($store, ['fence' => $window(['R2' => '400']), 'hash' => $hash,
        'exists' => function (array $ids) use ($src) { return $src->exist($ids); }]);
    $res = $cu->step(800, $runId, $epoch, 100);
    check('catch-up: a record edited during the run and missing from the cache is requeued',
        $res['requeued'] === 1 && $res['gone'] === 0
        && $store->recordState($runId, 2) === ScanStore::REC_PENDING);

    // W7-N4 RESIDUE: anything but an explicit false is "cannot tell".
    foreach (['null' => null, 'zero' => 0, 'an empty string' => '', "'0'" => '0'] as $what => $v) {
        list($store, $runId, $epoch) = $inCatchUp(['A', 'B']);
        $cu = new CatchUp($store, ['fence' => $window(['A' => '400', 'B' => '400']), 'hash' => $hash,
            'exists' => function (array $ids) use ($v) { return ['A' => $v, 'B' => false]; }]);
        $res = $cu->step(800, $runId, $epoch, 100);
        check('catch-up: a probe answering ' . $what . ' does not tombstone the record',
            $store->recordState($runId, 1) === ScanStore::REC_PENDING && $res['requeued'] === 1);
        check('catch-up: while an explicit false beside it still does (' . $what . ')',
            $store->recordState($runId, 2) === ScanStore::REC_TOMBSTONE && $res['gone'] === 1);
    }

    // A PROBE THAT THROWS answers "still there" for the page, rather than
    // ending the step.
    list($store, $runId, $epoch) = $inCatchUp(['A', 'B']);
    $cu = new CatchUp($store, ['fence' => $window(['A' => '400', 'B' => '400']), 'hash' => $hash,
        'exists' => function (array $ids) { throw new \RuntimeException('MySQL server has gone away', 2006); }]);
    $r = outcomeOf(function () use ($cu, $runId, $epoch) { return $cu->step(800, $runId, $epoch, 100); });
    check('catch-up: a probe that throws does not escape the step',
        $r['kind'] === 'returned');
    check('catch-up: and every record it was asked about is requeued, none tombstoned',
        $r['kind'] === 'returned' && $r['value']['requeued'] === 2 && $r['value']['gone'] === 0
        && $store->recordState($runId, 1) === ScanStore::REC_PENDING
        && $store->recordState($runId, 2) === ScanStore::REC_PENDING);

    // -- OC-5: TWO WORKERS OF ONE RUN, ONE SUMMARY PAGE ------------------------
    //
    // Both workers hold the same lease epoch, so the epoch fence cannot tell
    // them apart. The ScanDb below answers the findings read from a fixed page
    // and runs $onBegin once, at the first begin(): the moment between "read
    // the cursor and the page" and "add the page", which is where the other
    // worker lives. tests/mysql/cases/rollup.php does the same on two real
    // connections.
    $pageDb = new class implements ScanDb {
        public $page = [];
        public $onBegin = null;
        public function select($sql, array $params = []) {
            if (strpos($sql, 'finding_id > ?') === false) return [];
            $after = (int) $params[2];
            $lim = preg_match('/LIMIT (\d+)/', $sql, $m) ? (int) $m[1] : PHP_INT_MAX;
            $out = [];
            foreach ($this->page as $r) {
                if ($r[0] > $after && count($out) < $lim) $out[] = $r;
            }
            return $out;
        }
        public function exec($sql, array $params = []) {}
        public function execNoCount($sql, array $params = []) {}
        public function affected() { return 0; }
        public function begin() {
            $f = $this->onBegin;
            $this->onBegin = null;
            if ($f) $f();
        }
        public function commit() {}
        public function rollback() {}
    };
    $pageDb->page = [[1, 'fa', 'required-blank', null, 'required'],
                     [2, 'fa', 'out-of-range', 'site_a', 'assert'],
                     [3, 'fb', 'required-blank', null, 'required']];
    $formTotal = function (ArrayScanStore $s, $rid) {
        $n = 0;
        foreach ($s->aggregates($rid) as $a) {
            if ($a['kind'] === RollupBuilder::K_FORM) $n += (int) $a['cnt'];
        }
        return $n;
    };
    $rollRun = function ($pid) {
        $s = new ArrayScanStore();
        $rid = (int) $s->startRun($pid, [])['run']['run_id'];
        $s->writeManifest($rid, [['id_bin' => 'R1', 'hash' => hash('sha256', 'R1', true), 'dag' => null]]);
        $run = $s->run($pid, $rid);
        return [$s, $rid, (int) $run['lease_epoch'], (int) $run['generation_id']];
    };

    list($s, $rid, $ep, $gen) = $rollRun(960);
    $first = new RollupBuilder($pageDb, $s);
    $second = new RollupBuilder($pageDb, $s);
    $pageDb->onBegin = function () use ($second, $rid, $ep, $gen) {
        $second->step(960, $rid, $ep, $gen, 10);
    };
    $a = $first->step(960, $rid, $ep, $gen, 10);
    check('rollup: a page another worker of the run counted first is not counted again',
        $formTotal($s, $rid) === 3);
    check('rollup: the worker that lost the page is told nothing went wrong, and carries on',
        $a === ['done' => false, 'rows' => 0, 'why' => null]);
    check('rollup: and its next step reads from where the other worker got to',
        $first->step(960, $rid, $ep, $gen, 10)['done'] === true && $formTotal($s, $rid) === 3);

    // A RUN THAT ENDED WHILE THE PAGE WAS READ takes nothing from it. The epoch
    // has not moved - finishing does not move it - so before the hold this page
    // landed in the summary of a run whose verdict had already been read.
    list($s, $rid, $ep, $gen) = $rollRun(961);
    $late = new RollupBuilder($pageDb, $s);
    $pageDb->onBegin = function () use ($s, $rid) {
        $s->finish($rid, ScanOutcome::derive(['failed' => true]));
    };
    $b = $late->step(961, $rid, $ep, $gen, 10);
    check('rollup: a page read before the run finished is not added after it',
        $formTotal($s, $rid) === 0 && (int) $s->progressState($rid)['rollupCursor'] === 0);
    check('rollup: and the worker is told why, so it stops',
        $b['rows'] === 0 && $b['why'] === ScanStore::ROLLUP_FINISHED);

    // =====================================================================
    // Wave 8: store parity, the paged retention, and what each does when the
    // database answers badly
    // =====================================================================

    /** A ScriptedDb that keeps each statement's parameters beside it. */
    $paramDb = function () {
        return new class extends ScriptedDb {
            public $sent = [];
            public function exec($sql, array $params = [])
            {
                $this->sent[] = [$sql, $params];
                parent::exec($sql, $params);
            }
            public function execNoCount($sql, array $params = [])
            {
                $this->sent[] = [$sql, $params];
                parent::execNoCount($sql, $params);
            }
            public function select($sql, array $params = [])
            {
                $this->sent[] = [$sql, $params];
                return parent::select($sql, $params);
            }
            public function affected()
            {
                if (strpos($this->lastWrite, 'INSERT INTO ' . Schema::table('finding')) !== false) {
                    return substr_count($this->lastWrite, '),(') + 1;
                }
                return parent::affected();
            }
            /** Every sent statement containing all of $needles. */
            public function with(array $needles)
            {
                $out = [];
                foreach ($this->sent as $s) {
                    $hit = true;
                    foreach ($needles as $n) if (strpos($s[0], $n) === false) $hit = false;
                    if ($hit) $out[] = $s;
                }
                return $out;
            }
        };
    };

    // -- M13: a finding that names no valid_from_seq opens at the RUN's -------
    //
    // The SQL store wrote 0 - an interval opened before the project's first
    // run - while ArrayScanStore wrote the run's sequence. The fence row here
    // carries run_seq 9 and generation 5, so the two cannot be mistaken.
    $vdb = $paramDb();
    $vdb->rows = ['FOR UPDATE' => [[0, null, 700, 5, 9, null]]];
    $noSeq = $mkFinding(1);
    $withSeq = $mkFinding(2);
    $withSeq['valid_from_seq'] = 4;
    $got = (new SqlScanStore($vdb))->commitBatch(1, 'w1', 0, ['records' => [['ordinal' => 1,
        'state' => ScanStore::REC_DONE, 'claim' => 9, 'record_hash' => str_repeat('h', 32)]],
        'findings' => [$noSeq, $withSeq]]);
    $ins = $vdb->with(['INSERT INTO ' . Schema::table('finding')]);
    // findingRow() puts valid_from_seq fourth: project, generation, identity, seq.
    check('valid_from: a finding with none opens at the run\'s own sequence',
        $got === true && count($ins) === 1 && (int) $ins[0][1][3] === 9);
    check('valid_from: and one that names its own keeps it',
        count($ins) === 1 && count($ins[0][1]) === 64 && (int) $ins[0][1][32 + 3] === 4);

    // -- B5-N1: findings() answers INPUT_KEYS, zipped, or refuses -------------
    $fdb = $paramDb();
    $wide = [];
    foreach (FindingRowMapper::INPUT_KEYS as $i => $k) $wide[] = 'v' . $i;
    $fdb->rows = ['FROM ' . Schema::table('finding') => [$wide, $wide]];
    $page = (new SqlScanStore($fdb))->findings(700, 5, ['dag_key' => 'n', 'nope' => 'x'], 0, 50);
    check('rows: findings() keys every row by FindingRowMapper::INPUT_KEYS, in order',
        count($page) === 2 && array_keys($page[0]) === FindingRowMapper::INPUT_KEYS
        && $page[0]['finding_id'] === 'v0' && $page[1]['value_expires_at'] === 'v16');
    $sel = $fdb->with(['FROM ' . Schema::table('finding')]);
    // z_score is read only beside value_bin (SqlScanStore::findings()).
    $selectList = array_map(function ($k) {
        return $k === 'z_score' ? 'CASE WHEN value_bin IS NULL THEN NULL ELSE z_score END AS z_score' : $k;
    }, FindingRowMapper::INPUT_KEYS);
    check('rows: the SELECT list is that list and nothing else',
        count($sel) === 1 && strpos($sel[0][0], 'SELECT ' . implode(', ', $selectList) . "\n") === 0);
    check('rows: a known axis is bound, an unknown one never reaches the statement',
        count($sel) === 1 && strpos($sel[0][0], 'dag_key = ?') !== false
        && strpos($sel[0][0], 'nope') === false && $sel[0][1] === [700, 5, 0, 'n']);
    $fdb->rows = ['FROM ' . Schema::table('finding') => [array_slice($wide, 0, 12)]];
    $r = outcomeOf(function () use ($fdb) {
        return (new SqlScanStore($fdb))->findings(700, 5, [], 0, 50);
    });
    check('rows: a row of the old twelve columns is a LogicException, never a false row',
        $r['kind'] === 'other' && $r['class'] === 'LogicException');

    // -- W6-N3: provisioning reports the rows the server added ----------------
    //
    // Three slots asked for, one inserted: the other two were written by a
    // settings save that landed between the MAX read and the insert. The
    // count used to be the number of statements sent.
    $pdb = $paramDb();
    $pdb->affectedNext = 1;
    check('slots: provisioning answers what the server inserted, not what it was sent',
        (new WorkerSlots($pdb))->provision(3) === 1);
    $pIns = $pdb->with(['INSERT IGNORE INTO ' . Schema::table('scan_worker_slot')]);
    check('slots: in one statement for the whole gap',
        count($pIns) === 1 && $pIns[0][1] === [1, 2, 3]
        && substr_count($pIns[0][0], '(?, 0)') === 3);
    $full = $paramDb();
    $full->rows = ['MAX(slot_no)' => [[5]]];
    check('slots: and sends nothing when the table already holds the limit',
        (new WorkerSlots($full))->provision(3) === 0
        && $full->with(['INSERT IGNORE']) === []);

    // -- W4-D1: supersedePrevious fails closed --------------------------------
    //
    // run row: generation 7, run_seq 7, complete, cursor 0, whole project. One
    // older generation, 3, with active rows to close.
    $supDb = function ($older) use ($paramDb) {
        $db = $paramDb();
        $db->rows = [
            'FOR UPDATE' => [[7, 7, ScanOutcome::COMPLETE, 0, null]],
            'MIN(generation_id)' => $older,
        ];
        return $db;
    };
    $db = $supDb([[3]]);
    $db->failAt('UPDATE ' . Schema::table('finding'), 1);
    $r = outcomeOf(function () use ($db) { return (new SqlScanStore($db))->supersedePrevious(70, 1, 10); });
    check('supersede: a deadlock in the page is unavailable, not "done"',
        $r['kind'] === 'unavailable' && $db->rollbacks === 1 && !in_array('COMMIT', $db->log, true));
    $db = $supDb([]);
    $r = outcomeOf(function () use ($db) { return (new SqlScanStore($db))->supersedePrevious(70, 1, 10); });
    check('supersede: an older-generation read with no answer is not "nothing older"',
        $r['kind'] === 'unavailable' && $db->with(['UPDATE ']) === [] && $db->rollbacks === 1);
    $db = $supDb([[3]]);
    $db->affectedNext = 10;
    $r = (new SqlScanStore($db))->supersedePrevious(70, 1, 10);
    $page = $db->with(['UPDATE ' . Schema::table('finding')]);
    check('supersede: a full page closes its limit and leaves the cursor for the next call',
        $r === ['done' => false, 'rows' => 10, 'cursor' => 0, 'why' => null]
        && $db->with(['supersede_cursor = ?']) === []);
    check('supersede: through the project, the older generation and the active slot only',
        count($page) === 1 && $page[0][1] === [7, 70, 3]
        && strpos($page[0][0], 'WHERE project_id = ? AND generation_id = ? AND active_slot = 1') !== false
        && strpos($page[0][0], 'LIMIT 10') !== false);
    $db = $supDb([[3]]);
    $db->affectedNext = 0;          // the page came up short, and so did the cursor's CAS
    $r = (new SqlScanStore($db))->supersedePrevious(70, 1, 10);
    check('supersede: a cursor that did not move as expected gives the page back',
        $r['done'] === false && $r['rows'] === 0 && $r['why'] === SqlScanStore::SUPERSEDE_MOVED
        && $db->rollbacks === 1 && !in_array('COMMIT', $db->log, true));
    $limitDb = $supDb([[3]]);
    $limitDb->affectedNext = 0;
    (new SqlScanStore($limitDb))->supersedePrevious(70, 1, 999999);
    $capped = $limitDb->with(['UPDATE ' . Schema::table('finding')]);
    check('supersede: a page is never more than PAGE rows, whatever it is asked for',
        count($capped) === 1 && strpos($capped[0][0], 'LIMIT ' . ScanStore::PAGE) !== false);
    foreach ([null => false, ScanOutcome::PARTIAL => true] as $terminal => $done) {
        $db = $paramDb();
        $db->rows = ['FOR UPDATE' => [[7, 7, $terminal === '' ? null : $terminal, 0, null]]];
        $r = (new SqlScanStore($db))->supersedePrevious(70, 1, 10);
        check('supersede: a run ' . ($terminal === '' ? 'still in progress' : 'that ended ' . $terminal)
            . ' closes nothing' . ($done ? ', and that is final' : ', and is not done'),
            $r['done'] === $done && $r['rows'] === 0 && is_string($r['why'])
            && $db->with(['UPDATE ']) === []);
    }
    // ONE GROUP'S SCAN SUPERSEDES NOTHING (review R8-1), and a run row that
    // does not say what its scope was is not read as a whole-project one.
    foreach (['scoped to a group' => [[7, 7, ScanOutcome::COMPLETE, 0, '12']],
              'whose scope did not come back' => [[7, 7, ScanOutcome::COMPLETE, 0]]] as $how => $row) {
        $db = $paramDb();
        $db->rows = ['FOR UPDATE' => $row, 'MIN(generation_id)' => [[3]]];
        $db->affectedNext = 10;
        $r = (new SqlScanStore($db))->supersedePrevious(70, 1, 10);
        check('supersede: a complete run ' . $how . ' closes nothing, and that is final',
            $r === ['done' => true, 'rows' => 0, 'cursor' => 0, 'why' => ScanStore::SUPERSEDE_SCOPED]
            && $db->with(['UPDATE ']) === [] && $db->with(['MIN(generation_id)']) === []);
    }

    // -- P6: the paged retention, statement by statement ----------------------
    $rdb = $paramDb();
    $rdb->rows = ['MAX(finding_id)' => [[12000]]];
    $rdb->affectedNext = 7;
    $ret = new ScanRetention($rdb);
    $p = $ret->expireValuesPage(5000, 42, '2030-01-01 00:00:00');
    $w = $rdb->with(['UPDATE ' . Schema::table('finding')]);
    check('expire: one window of PAGE ids through the primary key, and the next cursor',
        $p === ['done' => false, 'rows' => 7, 'cursor' => 10000, 'top' => 12000]
        && count($w) === 1 && strpos($w[0][0], 'finding_id > ? AND finding_id <= ?') !== false
        && $w[0][1] === [5000, 10000, '2030-01-01 00:00:00', 42]);
    check('expire: the window reaching the top id is the last',
        $ret->expireValuesPage(10000)['done'] === true);
    $rdb->sent = [];
    $ret->expireValuesPage(0, null, '2030-01-01 00:00:00', 1000000);
    $w = $rdb->with(['UPDATE ' . Schema::table('finding')]);
    check('expire: a window asked for wider than PAGE is PAGE ids wide',
        count($w) === 1 && $w[0][1] === [0, ScanRetention::PAGE, '2030-01-01 00:00:00']);
    $rdb = $paramDb();                 // MAX answers no row at all
    $r = outcomeOf(function () use ($rdb) { return (new ScanRetention($rdb))->expireValuesPage(0); });
    check('expire: a top id that could not be read is not "done"',
        $r['kind'] === 'other' && $r['class'] === 'RuntimeException');
    $rdb = $paramDb();
    $rdb->rows = ['MAX(finding_id)' => [[12000]]];
    $rdb->affectedNext = 2;
    check('expire: the whole sweep is a window at a time, and adds up',
        (new ScanRetention($rdb))->expireValues('2030-01-01 00:00:00') === 6
        && count($rdb->with(['UPDATE ' . Schema::table('finding')])) === 3);
    $r = outcomeOf(function () use ($rdb) { return (new ScanRetention($rdb))->expireValuesPage(0, 0); });
    check('expire: a project scope of 0 is refused rather than read as "the leftovers"',
        $r['kind'] === 'other' && $r['class'] === 'InvalidArgumentException');
    $pdb = $paramDb();
    $pdb->rows = ['FROM ' . Schema::table('scan_run') => [[2]]];      // the findings count answers nothing
    $r = outcomeOf(function () use ($pdb) { return (new ScanRetention($pdb))->preview(70, []); });
    check('preview: a count that could not be read is not shown as nothing to clear',
        $r['kind'] === 'other' && $r['class'] === 'RuntimeException');
    $pdb = $paramDb();
    $pdb->rows = ['FROM ' . Schema::table('finding') => [[4]], 'FROM ' . Schema::table('scan_run') => [[2]]];
    $pv = (new ScanRetention($pdb))->preview(70, ['runDays' => 30]);
    $pq = $pdb->with(['FROM ' . Schema::table('finding')]);
    check('preview: counts this project\'s share only',
        $pv === ['values_to_clear' => 4, 'runs_to_purge' => 2]
        && count($pq) === 1 && strpos($pq[0][0], 'project_id = ?') !== false && $pq[0][1][0] === 70);

    // purgeRuns: the run row goes last, and only after every child answered short.
    $udb = $paramDb();
    $udb->rows = ['ORDER BY run_id LIMIT 1' => [[31, 4]], 'SELECT EXISTS(' => [[0]]];
    $udb->affectedNext = 3;
    $pr = (new ScanRetention($udb))->purgeRuns(70, 30, 3);
    check('purge: a child that gives up a full page ends the call, the run row still there',
        $pr === ['done' => false, 'rows' => 3, 'runs' => 0, 'cursor' => 31]
        && count($udb->with(['DELETE FROM'])) === 1
        && $udb->with(['DELETE FROM ' . Schema::table('scan_run')]) === []);
    $udb = $paramDb();
    $udb->rows = ['ORDER BY run_id LIMIT 1' => [[31, 4]], 'SELECT EXISTS(' => [[0]]];
    $udb->affectedNext = 1;
    $pr = (new ScanRetention($udb))->purgeRuns(70, 30, 3);
    $order = [];
    foreach ($udb->with(['DELETE FROM']) as $s) {
        preg_match('/DELETE FROM (\S+)/', $s[0], $m);
        $order[] = $m[1];
    }
    $T = function ($s) { return Schema::table($s); };
    check('purge: children first - manifest, aggregates, plan, then the generation, then the run',
        $order === [$T('scan_record'), $T('scan_aggregate'), $T('scan_plan'), $T('finding'),
                    $T('unique_candidate'), $T('unique_group'), $T('scan_dim'), $T('scan_run')]);
    check('purge: and the generation\'s tables by project AND generation, a page at a time',
        count($udb->with(['DELETE FROM ' . $T('finding') . ' WHERE project_id = ? AND generation_id = ? LIMIT 3'])) === 1);
    $udb = $paramDb();
    $udb->rows = ['ORDER BY run_id LIMIT 1' => [[31, 4]], 'SELECT EXISTS(' => [[1]]];
    $udb->affectedNext = 0;
    (new ScanRetention($udb))->purgeRuns(70, 30, 3);
    check('purge: a generation another run still names keeps its findings',
        $udb->with(['DELETE FROM ' . $T('finding')]) === []
        && count($udb->with(['DELETE FROM ' . $T('scan_run')])) === 1);
    $udb = $paramDb();
    $udb->rows = ['ORDER BY run_id LIMIT 1' => [[31, 4]]];      // EXISTS answers nothing
    $r = outcomeOf(function () use ($udb) { return (new ScanRetention($udb))->purgeRuns(70, 30); });
    check('purge: an unreadable "is this generation shared" deletes nothing',
        $r['kind'] === 'other' && $udb->with(['DELETE FROM']) === []);

    // revokePreviews: the bump, then one bounded page through ix_page_v2.
    $vdb = $paramDb();
    $vdb->rows = ['SELECT DISTINCT generation_id' => [[3], [8]],
                  'SELECT COUNT(*), MAX(finding_id)' => [[2, 41]]];
    $vdb->affectedNext = 2;
    $rv = (new ScanRetention($vdb))->revokePreviews(70, 5, null, 2);
    $clear = $vdb->with(['UPDATE ' . $T('finding')]);
    $issued = $rv['cursor'];
    check('revoke: the bump first, and a full page leaves the cursor inside the slice',
        $rv['runs'] === 2 && $rv['rows'] === 2 && $rv['done'] === false
        && is_array($issued) && $issued['gen'] === 3 && $issued['slot'] === 1 && $issued['after'] === 41
        && count($clear) === 1 && $clear[0][1] === [70, 3, 0, 41]
        && strpos($clear[0][0], 'AND active_slot = 1') !== false
        && strpos($clear[0][0], 'AND finding_id > ? AND finding_id <= ?') !== false);
    // A short page clears its rows and ends the call at the next slice: one
    // UPDATE per call, however little the slice held.
    $vdb = $paramDb();
    $vdb->rows = ['SELECT DISTINCT generation_id' => [[3], [8]],
                  'SELECT COUNT(*), MAX(finding_id)' => [[1, 41]]];
    $vdb->affectedNext = 1;
    $rv = (new ScanRetention($vdb))->revokePreviews(70, 5, null, 2);
    check('revoke: a short page is one UPDATE, and the cursor moves to the next slice',
        $rv['runs'] === 1 && $rv['rows'] === 1 && $rv['done'] === false
        && $rv['cursor']['gen'] === 3 && $rv['cursor']['slot'] === 0 && $rv['cursor']['after'] === 0
        && count($vdb->with(['UPDATE ' . $T('finding')])) === 1);

    // A CURSOR IS THIS PROJECT'S, FOR THESE GENERATIONS, OR IT STARTS AGAIN
    // (review R8-3). It skips every row of its slice at or below `after`, and
    // it was a bare {gen, slot, after}: another project's cursor named a
    // generation number this one also has, and the revocation answered done
    // with every preview still stored. The cursor issued above, for project 70
    // walking generations 3 and 8, resumes at 41 for project 70 - and starts
    // from 0 for project 71, for project 70 once the generations it walks have
    // changed, and for a cursor that names no project at all.
    $resume = function ($pid, array $gens, $cursor) use ($paramDb) {
        $db = $paramDb();
        $rows = [];
        foreach ($gens as $g) $rows[] = [$g];
        $db->rows = ['SELECT DISTINCT generation_id' => $rows,
                     'SELECT COUNT(*), MAX(finding_id)' => [[2, 90]]];
        $db->affectedNext = 0;
        (new ScanRetention($db))->revokePreviews($pid, 5, $cursor, 2);
        $read = $db->with(['SELECT COUNT(*), MAX(finding_id)']);
        return $read ? $read[0][1] : null;
    };
    check('revoke: the cursor this project was given resumes where it stopped',
        $resume(70, [3, 8], $issued) === [70, 3, 41]);
    check('revoke: another project\'s cursor starts again from the first slice',
        $resume(71, [3, 8], $issued) === [71, 3, 0]);
    check('revoke: so does this project\'s, once the generations it walks have changed',
        $resume(70, [3, 8, 9], $issued) === [70, 3, 0]);
    check('revoke: and a cursor that names no project',
        $resume(70, [3, 8], ['gen' => 3, 'slot' => 1, 'after' => 41]) === [70, 3, 0]);
    $vdb = $paramDb();
    $vdb->rows = ['SELECT DISTINCT generation_id' => [[3]],
                  'SELECT COUNT(*), MAX(finding_id)' => [[2, 41]]];
    $vdb->affectedNext = 2;
    (new ScanRetention($vdb))->revokePreviews(70, 5, null, 1000000);
    $bound = $vdb->with(['SELECT COUNT(*), MAX(finding_id)']);
    check('revoke: a page asked for larger than PAGE reads PAGE rows',
        count($bound) === 1 && strpos($bound[0][0], 'LIMIT ' . ScanRetention::PAGE . ') page') !== false);
    $vdb = $paramDb();
    $vdb->rows = ['SELECT DISTINCT generation_id' => [[3], [8]],
                  'SELECT COUNT(*), MAX(finding_id)' => [[0, null]]];
    $vdb->affectedNext = 0;
    $rv = (new ScanRetention($vdb))->revokePreviews(70, 5, ['gen' => 3, 'slot' => 1, 'after' => 41]);
    check('revoke: empty slices are walked without a write, to the end',
        $rv === ['runs' => 0, 'rows' => 0, 'done' => true, 'cursor' => null]
        && $vdb->with(['UPDATE ' . $T('finding')]) === []
        && count($vdb->with(['SELECT COUNT(*), MAX(finding_id)'])) === 4);
    $vdb = $paramDb();
    $vdb->rows = ['SELECT DISTINCT generation_id' => [[3]]];      // the page bound answers nothing
    $r = outcomeOf(function () use ($vdb) { return (new ScanRetention($vdb))->revokePreviews(70, 5); });
    check('revoke: a page bound that could not be read clears nothing and says so',
        $r['kind'] === 'other' && $vdb->with(['UPDATE ' . $T('finding')]) === []);

    // =====================================================================
    // R5 - THE PLANNING WALK WHILE REDCAP CHANGES ITS RECORD-LIST CACHE
    // =====================================================================
    //
    // The walk spans requests and REDCap rebuilds redcap_record_list in the
    // background, so the source can stop being the cache it was opened as in
    // the middle of a slice, or open as a different table on the next
    // request. Whatever it does, every record must be listed exactly once.

    /**
     * REDCap's tables for one project, as the walk reads them, with a hook
     * before each page. $index is the cache, $data the record-id rows of the
     * data table, $extra ids holding rows on other fields only. The status
     * row carries time_of_list_cache when $stampColumn is set.
     */
    $churnDb = function (array $ids) {
        return new class($ids) implements ScanDb {
            public $index; public $data; public $extra = [];
            public $status = 'COMPLETE'; public $stamp = '2026-10-21 08:00:00';
            public $statusColumn = true; public $stampColumn = true; public $statusFails = false;
            public $dataTable = 'redcap_data'; public $hasData = true; public $dataFails = false;
            public $pages = ['index' => 0, 'data' => 0]; public $statusReads = 0;
            /** @var ?callable(object $db, string $which, int $n): void, before a page is answered */
            public $onPage = null;
            public function __construct(array $ids) { $this->index = $ids; $this->data = $ids; }
            public function select($sql, array $params = [])
            {
                if (strpos($sql, 'information_schema.columns') !== false) {
                    $t = $params[0];
                    if ($t === 'redcap_record_list') return [['project_id'], ['record'], ['dag_id']];
                    if ($t === $this->dataTable || $t === 'redcap_data') {
                        return $this->hasData ? [['project_id'], ['record'], ['field_name']] : [];
                    }
                    if ($t === 'redcap_record_counts') {
                        if (!$this->statusColumn) return [['project_id'], ['record_count']];
                        $c = [['project_id'], ['record_list_status']];
                        if ($this->stampColumn) $c[] = ['time_of_list_cache'];
                        return $c;
                    }
                    return [];
                }
                if (strpos($sql, 'FROM redcap_record_counts') !== false) {
                    $this->statusReads++;
                    if ($this->statusFails) throw new \RuntimeException('Lock wait timeout exceeded', 1205);
                    if ($this->status === null) return [];
                    return [strpos($sql, 'time_of_list_cache') !== false
                        ? [$this->status, $this->stamp] : [$this->status]];
                }
                if (strpos($sql, 'FROM redcap_projects') !== false) {
                    return $this->hasData ? [[$this->dataTable]] : [];
                }
                if (in_array('__GROUPID__', $params, true)) return [];
                preg_match('/LIMIT (\d+)/', $sql, $m);
                $lim = isset($m[1]) ? (int) $m[1] : PHP_INT_MAX;
                $isIndex = strpos($sql, 'FROM redcap_record_list') !== false;
                if (strpos($sql, 'ORDER BY') !== false && $lim > 1 && strpos($sql, ' IN (') === false
                        && strpos($sql, 'record = ?') === false) {
                    $w = $isIndex ? 'index' : 'data';
                    $this->pages[$w]++;
                    // Before the page is answered: the hook may change what it reads.
                    if (is_callable($this->onPage)) call_user_func($this->onPage, $this, $w, $this->pages[$w]);
                }
                $p = $params;
                array_shift($p);                                          // the project id
                if ($isIndex) {
                    $ids = $this->index;
                } else {
                    if ($this->dataFails) throw new \RuntimeException('Lock wait timeout exceeded', 1205);
                    if (strpos($sql, 'field_name = ?') !== false) {
                        array_shift($p);
                        $ids = $this->data;
                    } else {
                        $ids = array_values(array_unique(array_merge($this->data, $this->extra)));
                    }
                }
                sort($ids, SORT_STRING);
                if (strpos($sql, ' IN (') !== false) {
                    $ids = array_values(array_intersect($ids, array_map('strval', $p)));
                } elseif (strpos($sql, 'record = ?') !== false) {
                    $ids = in_array((string) $p[0], $ids, true) ? [(string) $p[0]] : [];
                } else {
                    if (strpos($sql, '>= ?') !== false) {
                        $at = (string) $p[0];
                        $ids = array_values(array_filter($ids, function ($r) use ($at) {
                            return strcmp($r, $at) >= 0;
                        }));
                    }
                }
                $ids = array_slice($ids, 0, $lim);
                $dag = strpos($sql, 'dag_id') !== false;
                return array_map(function ($r) use ($dag) { return $dag ? [$r, null] : [$r]; }, $ids);
            }
            public function exec($sql, array $params = []) {}
            public function execNoCount($sql, array $params = []) {}
            public function affected() { return 0; }
            public function begin() {}
            public function commit() {}
            public function rollback() {}
        };
    };
    $thousand = [];
    for ($i = 1; $i <= 1000; $i++) $thousand[] = sprintf('R%04d', $i);
    $walkKey = str_repeat('k', 32);
    $walkReq = ['rules' => [['type' => 'required', 'fields' => ['a']]], 'ownership' => ['a' => 'fa'],
                'policy' => [], 'createdBy' => 'u', 'engine' => '1', 'pageSize' => 100];
    /** Plan a project whole in one call, over $db as it is now. */
    $planWhole = function ($db) use ($walkKey, $walkReq) {
        $store = new ArrayScanStore();
        $open = RecordManifestSource::open($db, 88, ['pk' => 'record_id']);
        $res = (new ScanPlanner($store, $walkKey))->plan(88, array_merge($walkReq,
            ['source' => $open['source'], 'budget' => false]));
        return [$store, $res, $open];
    };
    /** The record ids on a run's manifest, in ordinal order, repeats included. */
    $manifestIds = function (ArrayScanStore $store, $runId) {
        $p = new \ReflectionProperty(ArrayScanStore::class, 'records');
        $p->setAccessible(true);
        $all = $p->getValue($store);
        $out = [];
        foreach (isset($all[$runId]) ? $all[$runId] : [] as $rec) $out[] = (string) $rec['id_bin'];
        return $out;
    };
    $whole = function ($store, $res) use ($manifestIds, $thousand) {
        if (empty($res['ok']) || !isset($res['run']['run_id'])) return false;
        $got = $manifestIds($store, (int) $res['run']['run_id']);
        $sorted = $got;
        sort($sorted, SORT_STRING);
        return $sorted === $thousand && (int) $store->run(88, (int) $res['run']['run_id'])['manifest_total'] === 1000;
    };

    // R5-3: REDCAP RESETS THE CACHE ON PAGE 4 OF THE SAME SLICE. The index
    // was judged complete once, at open(); page 4 came back empty, the walk
    // was called done and the manifest froze at 300 of 1000.
    $db = $churnDb($thousand);
    $db->onPage = function ($d, $which, $n) {
        if ($which === 'index' && $n === 4) { $d->index = []; $d->status = 'NOT_STARTED'; $d->stamp = null; }
    };
    list($s3, $r3) = $planWhole($db);
    check('R5-3: a cache REDCap resets in the middle of a slice leaves no record off the manifest',
        $whole($s3, $r3));
    check('R5-3: the walk went on from the data table, in the same slice',
        $db->pages['data'] > 0 && $r3['run']['phase'] === ScanPhase::SCANNING);

    // Half built rather than empty: PROCESSING, and only every other record.
    $db = $churnDb($thousand);
    $db->onPage = function ($d, $which, $n) {
        if ($which === 'index' && $n === 4) {
            $d->index = array_values(array_filter($d->index, function ($id) {
                return ((int) substr($id, 1)) % 2 === 0;
            }));
            $d->status = 'PROCESSING';
        }
    };
    list($s3, $r3) = $planWhole($db);
    check('R5-3: a cache found half rebuilt in the middle of a walk leaves no hole in the manifest',
        $whole($s3, $r3));

    // Torn down and built again between the page and the question: the
    // status reads COMPLETE both times, and only the build time moved. The
    // page was read from the list mid-build and lacked R0350.
    $db = $churnDb($thousand);
    $full = $thousand;
    $db->onPage = function ($d, $which, $n) use ($full) {
        if ($which !== 'index') return;
        if ($n === 4) {
            $d->index = array_values(array_diff($full, ['R0350']));
            $d->stamp = '2026-10-21 08:00:07';
        } elseif ($n === 5) {
            $d->index = $full;
        }
    };
    list($s3, $r3) = $planWhole($db);
    check('R5-3: a list rebuilt between a page and its check is caught by the time it was built',
        $whole($s3, $r3) && in_array('R0350', $manifestIds($s3, (int) $r3['run']['run_id']), true));

    // A status that can no longer be read is not a complete one.
    $db = $churnDb($thousand);
    $db->onPage = function ($d, $which, $n) {
        if ($which === 'index' && $n === 3) $d->statusFails = true;
    };
    list($s3, $r3) = $planWhole($db);
    check('R5-3: a status that cannot be read after a page moves the walk to the data table',
        $whole($s3, $r3) && $db->pages['data'] > 0);

    // THE COST. A status-kept index is asked once per page and once at open;
    // an index REDCap keeps no status for is never asked, as before.
    $db = $churnDb($thousand);
    list($s3, $r3) = $planWhole($db);
    check('R5-3: a trusted index is walked whole, with one status read per page and one at open',
        $whole($s3, $r3) && $db->pages['data'] === 0 && $db->statusReads === $db->pages['index'] + 1);
    $db = $churnDb($thousand);
    $db->statusColumn = false;
    list($s3, $r3) = $planWhole($db);
    check('R5-3: an index with no status kept is walked as it was, asking nothing per page',
        $whole($s3, $r3) && $db->pages['data'] === 0 && $db->statusReads === 0);

    // NOWHERE TO GO. The index stops being trustworthy and the data table
    // cannot be walked: the slice ends, nothing is frozen, and the lease goes
    // back so the next request can try.
    $db = $churnDb($thousand);
    $db->hasData = false;
    $db->onPage = function ($d, $which, $n) {
        if ($which === 'index' && $n === 2) { $d->index = []; $d->status = 'NOT_STARTED'; }
    };
    $s3 = new ArrayScanStore();
    $o3 = RecordManifestSource::open($db, 88, ['pk' => 'record_id']);
    $p3 = (new ScanPlanner($s3, $walkKey))->plan(88, array_merge($walkReq,
        ['source' => $o3['source'], 'budget' => new WorkBudget(['startedAt' => microtime(true) - 3600])]));
    $rid3 = (int) $p3['run']['run_id'];
    $sl = (new ScanPlanner($s3, $walkKey))->step(88, $rid3, array_merge($walkReq, ['source' => $o3['source'],
        'budget' => false, 'owner' => 'tab', 'epoch' => (int) $s3->run(88, $rid3)['lease_epoch']]));
    check('R5-3: with no data table to move to, the slice ends without freezing anything',
        $sl['ok'] === false && $sl['phase'] === ScanPhase::PLANNING && $sl['stop'] === 'read'
        && $sl['why'] === RecordManifestSource::UNTRUSTED
        && $s3->run(88, $rid3)['phase'] === ScanPhase::PLANNING && count($manifestIds($s3, $rid3)) === 100);
    check('R5-3: and hands the lease back',
        $s3->claimPlan($rid3, 'next', (int) $s3->run(88, $rid3)['lease_epoch'], 300, 5)['state'] === 'ok');
}

namespace {
    echo "scan_sqlstore_fault_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
