<?php
/**
 * tests/mysql/cases/fault.php — what a real failure does.
 *
 * The rebuild plan asks for injected DATABASE failures rather than mocked ones,
 * because the question is what the store does when the server says no - and a
 * mock that throws whatever the test chose proves only that the test can throw.
 * Both faults below are produced by the server: a value longer than its column,
 * and a write to a table excluded from a held LOCK TABLES.
 *
 * TWO PROJECTS. 900 is under test, 901 is planted beside it, and the two counts
 * that used to ask the whole finding table whether anything had leaked now say
 * which project they mean.
 */

use INSPIRE\UniversalValidator\Scan\Schema;

$PID = 900;
$NEIGHBOUR = uv_neighbour($PID);

// -- fault injection: what a real failure does -------------------------------
//
// The plan asks for injected database failures rather than mocked ones, because
// the question is what the STORE does when the server says no - and a mock that
// throws whatever the test chose proves only that the test can throw.
{
    $store = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA);
    foreach (array('scan_record', 'finding', 'scan_run') as $t) {
        $A->query('DELETE FROM ' . Schema::table($t));
    }

    // The project next door, holding two findings that this rollback must leave
    // exactly where they are. "The batch left nothing behind" used to be asked
    // of the whole table, which cannot tell a clean rollback from a schema with
    // only one project in it.
    $nb = uv_plant_neighbour($dbA, $PID);
    check('fault: a neighbouring project holds findings this rollback must not touch',
        is_array($nb) && uv_neighbour_findings($dbA, $PID) === 2);
    $r = $store->startRun($PID, array('created_by' => 'alice'));
    $rid = (int) $r['run']['run_id'];
    $store->writeManifest($rid, array(
        array('id_bin' => 'R1', 'hash' => hash('sha256', 'R1', true), 'dag' => null),
        array('id_bin' => 'R2', 'hash' => hash('sha256', 'R2', true), 'dag' => null)));
    $epoch = (int) $store->run($PID, $rid)['lease_epoch'];
    $claimed = $store->claim($rid, 'w', $epoch, 2);

    // A finding whose reason_code exceeds its column. The batch must roll back
    // ENTIRELY - a half-written batch would mark records done whose findings
    // were never stored, which is the one outcome that produces a confidently
    // clean report over unexamined data.
    //
    // EVERYTHING ELSE ABOUT THIS ROW IS VALID, and that is load-bearing. The
    // store refuses a finding with no project before it ever reaches the
    // server, so a fixture that left project_id off would produce a refusal
    // from PHP and the four assertions below - which are about what MySQL said
    // - would be asserting against the wrong sentence entirely.
    $gen = (int) $r['run']['generation_id'];
    $bad = array(
        'bytes' => 10,
        'records' => array(array('ordinal' => 1, 'claim' => $claimed[0]['claim'],
                                 'record_hash' => hash('sha256', 'R1', true),
            'state' => \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE)),
        'findings' => array(array(
            'project_id' => $PID,
            'generation_id' => $gen, 'identity' => hash('sha256', 'bad', true),
            'valid_from_seq' => uv_run_seq($dbA, $rid),
            'record_hash' => hash('sha256', 'R1', true), 'record_id_bin' => 'R1',
            'instance' => 1, 'host_form' => 'fa', 'field' => 'x', 'rule_source_id' => 'r1',
            'rule_revision' => str_repeat('c', 64), 'check_type' => 'required',
            'reason_code' => str_repeat('z', 200))));   // column is VARCHAR(64)
    $refused = $store->commitBatch($rid, 'w', $epoch, $bad);
    check('fault: a batch whose write fails does not commit', $refused !== true);
    check('fault: and names the database as the cause, not a phantom cancellation',
        is_string($refused) && strpos($refused, 'database refused') !== false);
    // AND SAYS WHAT THE SERVER SAID. "(Exception)" is the framework's wrapper
    // and describes nothing; three rounds of the live pilot were spent on it.
    // The reason_code column here is VARCHAR(64) and the value is 200 bytes, so
    // the server's own words are the diagnosis.
    // TWO CHECKS, AND (not OR). This was one check joined by `||`, and it was
    // passing on "too long" alone: MySQL single-quotes its identifiers rather
    // than backticking them, so the blanket value-redaction had been erasing
    // the column name since the day 1.9.9 promised to print it. A disjunction
    // cannot fail while either half is effectively a constant, so the test that
    // was supposed to guard the diagnosis was the reason nobody noticed it had
    // never worked. Assert each half on its own line.
    check('fault: quoting the server, so the column is named',
        strpos($refused, 'reason_code') !== false);
    check('fault: and the server\'s own words for what was wrong with it',
        strpos($refused, 'too long') !== false);
    // The VALUE never travels with the diagnosis. MySQL puts it in single
    // quotes, and an error string nobody audited is not a disclosure channel.
    check('fault: but never the value that caused it',
        strpos($refused, str_repeat('z', 20)) === false);
    // AND THE ANSWER IS BOUNDED WHATEVER ARRIVES. The framework puts the failing
    // statement in the message, and a findings batch is one multi-row INSERT
    // with tens of thousands of placeholders - so the input here can be
    // megabytes. Running a backtracking pattern over that is how 1.9.9 turned a
    // reported error into an empty 200 with no body: PCRE gives up, preg_replace
    // returns null, and the null travels on inside a catch already handling a
    // failure. Cut first, then redact.
    check('fault: and the reason is bounded rather than however long the server was',
        strlen($refused) <= 200);
    $f = $ca->query('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . " WHERE record_id_bin IN ('R1','R2')", array());
    check('fault: leaving no partial findings', (int) $f[0][0] === 0);
    check('fault: and the neighbouring project\'s findings are untouched by the rollback',
        uv_neighbour_findings($dbA, $PID) === 2);
    $st = $ca->query('SELECT state FROM ' . Schema::table('scan_record')
        . ' WHERE run_id = ' . $rid . ' AND ordinal = 1', array());
    check('fault: and the record still PENDING, so the work is re-claimable',
        (int) $st[0][0] === \INSPIRE\UniversalValidator\Scan\ScanStore::REC_PENDING);
    check('fault: the run is not marked as having done it',
        (int) $store->run($PID, $rid)['manifest_done'] === 0);

    // A revoked privilege is a failure the store cannot retry its way out of.
    // It must report false rather than throw past its caller: the worker needs
    // to stop, and a fatal would leave the run with no terminal state at all.
    $A->query('CREATE TABLE IF NOT EXISTS uv_readonly_probe (id INT)');
    $A->query('LOCK TABLES uv_readonly_probe READ');
    $ok = true;
    try {
        // Writing to a table not named in LOCK TABLES is refused while the lock
        // is held - a real server-side write failure, not a simulated one.
        $store->commitBatch($rid, 'w', $epoch, array(
            'bytes' => 0, 'records' => array(), 'findings' => array()));
    } catch (\Throwable $e) {
        $ok = false;
    }
    $A->query('UNLOCK TABLES');
    $A->query('DROP TABLE IF EXISTS uv_readonly_probe');
    check('fault: a refused write returns rather than escaping as a fatal', $ok === true);
}
