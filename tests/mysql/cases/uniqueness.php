<?php
/**
 * tests/mysql/cases/uniqueness.php — deciding duplicates without holding the
 * project.
 *
 * Uniqueness is the only check here that is a property of the whole project
 * rather than of one record, so it is the only one that cannot be finished while
 * scanning. Everything below is about the two ways that goes wrong: deciding
 * something the module cannot actually prove, and holding a group in memory.
 *
 * TWO PROJECTS, AND ONE THING THIS CASE STILL CANNOT ASSERT.
 *
 * 900 is the project under test and 901 is planted beside it with candidates of
 * its own, so the group HMAC - which is keyed on the project id - is exercised
 * against a real second project rather than against an empty table. Nothing this
 * project publishes may contain one of the neighbour's records, and that is
 * asserted below.
 *
 * The neighbour's candidates sit in their OWN generation, and that is a
 * concession rather than a design. The finalizer scopes every statement it makes
 * by generation and by nothing else, and generations are handed out per
 * installation today, so two projects sharing one would make discover() and
 * status() answer about both of them at once. Putting the neighbour in the same
 * generation is therefore the assertion wave 4 gets to write - after B1 makes
 * the generation per project, `status($gen)['groups']` becomes a question with
 * one project's answer and this fixture can drop the separate generation. The
 * numbers themselves are allocated by uv_generation() rather than written out,
 * so that change is one function rather than six literals.
 */

use INSPIRE\UniversalValidator\Scan\Schema;

$PID = 900;
$NEIGHBOUR = uv_neighbour($PID);

// -- the unique finalizer: deciding duplicates without holding the project ----
//
// Uniqueness is the only check here that is a property of the whole project
// rather than of one record, so it is the only one that cannot be finished while
// scanning. Everything below is about the two ways that goes wrong: deciding
// something the module cannot actually prove, and holding a group in memory.
{
    $KEY = 'finalizer-test-key';
    $GEN  = uv_generation('duplicates');
    foreach (array('finding', 'unique_candidate', 'unique_group') as $t) {
        $A->query('DELETE FROM ' . Schema::table($t));
    }

    // Candidates as the worker writes them: a keyed group hash and a location,
    // never the value. Written with interpolated TEST-CONTROLLED literals and
    // UNHEX for the binary columns rather than through bind_param: this file has
    // already lost an afternoon to a type string that did not match its variable
    // count, and a fixture helper is not where that risk belongs.
    $put = function ($group, $rec, $field, $version = 'null') use ($A, $GEN, $KEY, $PID) {
        $g = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_UNIQUE, $PID, $group, $KEY));
        $h = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_RECORD, $PID, $rec, $KEY));
        $v = ($version === 'null') ? 'NULL' : ("'" . $version . "'");
        $A->query('INSERT INTO ' . Schema::table('unique_candidate') . "
            (generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
             record_hash, record_id_bin, event_id, instance, host_form, field, version_scanned)
            VALUES (" . $GEN . ", 'r1', '" . str_repeat('c', 64) . "', UNHEX('" . $g . "'),
                    'project', UNHEX('" . $h . "'), '" . $rec . "', 1, 1, 'f', '" . $field . "', " . $v . ")");
    };

    // Two records sharing a value, three sharing another, one on its own.
    $put('AB12', 'R1', 'hospno');
    $put('AB12', 'R2', 'hospno');
    $put('CD34', 'R3', 'hospno');
    $put('CD34', 'R4', 'hospno');
    $put('CD34', 'R5', 'hospno');
    $put('EF56', 'R6', 'hospno');

    // THE PROJECT NEXT DOOR, holding the same value on records with the same
    // ids. The group hash is keyed on the project id, so this is the fixture
    // that decides whether that keying is real: if the pid ever left the HMAC,
    // 901's R1 and 900's R1 would land in one group and this module would
    // report two participants at two different sites as duplicates of each
    // other. Its own generation, for the reason in the file header.
    $GEN_NB = uv_generation('the-neighbour');
    $putNb = function ($group, $rec) use ($A, $GEN_NB, $KEY, $NEIGHBOUR) {
        $g = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_UNIQUE, $NEIGHBOUR, $group, $KEY));
        $h = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_RECORD, $NEIGHBOUR, $rec, $KEY));
        $A->query('INSERT INTO ' . Schema::table('unique_candidate') . "
            (generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
             record_hash, record_id_bin, event_id, instance, host_form, field, version_scanned)
            VALUES (" . $GEN_NB . ", 'r1', '" . str_repeat('c', 64) . "', UNHEX('" . $g . "'),
                    'project', UNHEX('" . $h . "'), '" . $rec . "', 1, 1, 'f', 'hospno', NULL)");
    };
    $putNb('AB12', 'R1');
    $putNb('AB12', 'R2');
    $nbCands = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('unique_candidate')
        . ' WHERE generation_id = ?', array($GEN_NB));
    check('unique: a second project holds the same value on the same record ids',
        (int) $nbCands[0][0] === 2);
    $shared = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('unique_candidate') . ' a
        JOIN ' . Schema::table('unique_candidate') . ' b ON a.group_hmac = b.group_hmac
        WHERE a.generation_id = ? AND b.generation_id = ?', array($GEN, $GEN_NB));
    check('unique: and shares no group with this one, because the hash is keyed on the project',
        (int) $shared[0][0] === 0);

    // The re-read the finalizer verifies against. Every record in a group really
    // does hold the same value here.
    $truth = array('R1' => 'AB12', 'R2' => 'AB12', 'R3' => 'CD34', 'R4' => 'CD34',
                   'R5' => 'CD34', 'R6' => 'EF56');
    $reader = function ($locs) use (&$truth) {
        $out = array();
        foreach ($locs as $l) {
            if (!isset($truth[$l['record']])) continue;
            $out[\INSPIRE\UniversalValidator\Scan\UniqueFinalizer::locKey($l)] = array($truth[$l['record']]);
        }
        return array('ok' => true, 'values' => $out, 'why' => null);
    };

    $fin = new \INSPIRE\UniversalValidator\Scan\UniqueFinalizer($dbA, array('pid' => $PID, 'hmacKey' => $KEY, 'read' => $reader));

    check('unique: nothing is settled before anything has run',
        $fin->status($GEN)['done'] === true && $fin->status($GEN)['groups'] === 0);

    $made = $fin->discover($GEN, 100);
    check('unique: every candidate group is discovered', $made === 3);
    $st = $fin->status($GEN);
    check('unique: a group with one record in it is settled without being work',
        $st['groups'] === 3 && $st['pending'] === 2);

    // Drive it to completion one bounded step at a time, exactly as a request
    // with a budget would.
    $steps = 0;
    while ($steps++ < 100) {
        $r = $fin->step($GEN, 2);
        if ($r['done']) break;
    }
    check('unique: finalization completes in bounded steps', $steps < 100);
    $st = $fin->status($GEN);
    check('unique: both real groups are published',
        $st['done'] === true && $st['published'] === 2 && $st['blocking'] === 0);

    $f = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE generation_id = ? AND reason_code = ?', array($GEN, 'duplicate'));
    check('unique: one finding for every record in a duplicate group',
        (int) $f[0][0] === 5);
    $active = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE generation_id = ? AND active_slot = 1', array($GEN));
    check('unique: and all of them are visible once their group is published',
        (int) $active[0][0] === 5);
    $lonely = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . " WHERE generation_id = ? AND record_id_bin = 'R6'", array($GEN));
    check('unique: a record whose value nobody shares is not reported',
        (int) $lonely[0][0] === 0);

    // A RETRIED PAGE MUST NOT DOUBLE THE REPORT. The staged rows are keyed by
    // identity within their epoch, which is a key the active-identity one cannot
    // supply: a staged row has no active slot, and MySQL counts every NULL in a
    // unique index as distinct.
    $A->query('UPDATE ' . Schema::table('unique_group')
        . " SET phase = 'emitting', emit_cursor = 0 WHERE generation_id = " . $GEN
        . " AND phase = 'published'");
    $again = 0;
    while ($again++ < 50) {
        $r = $fin->step($GEN, 2);
        if ($r['done']) break;
    }
    $f2 = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE generation_id = ? AND reason_code = ?', array($GEN, 'duplicate'));
    check('unique: re-emitting a group writes the same rows rather than a second set',
        (int) $f2[0][0] === 5);

    // -- two different values under one hash ---------------------------------
    //
    // Not producible by data entry, and checked anyway: the alternative to
    // checking is ASSERTING that two participants share a hospital number.
    // Partitioning the group by value would be the tempting response and would
    // turn a hash failure into a confident wrong report.
    $GEN2 = uv_generation('hash-collision');
    $gen2 = $GEN2;
    $put2 = function ($group, $rec) use ($A, $gen2, $KEY, $PID) {
        $g = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_UNIQUE, $PID, $group, $KEY));
        $h = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_RECORD, $PID, $rec, $KEY));
        $A->query('INSERT INTO ' . Schema::table('unique_candidate') . "
            (generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
             record_hash, record_id_bin, event_id, instance, host_form, field, version_scanned)
            VALUES (" . $gen2 . ", 'r1', '" . str_repeat('c', 64) . "', UNHEX('" . $g . "'),
                    'project', UNHEX('" . $h . "'), '" . $rec . "', 1, 1, 'f', 'hospno', NULL)");
    };
    $put2('SAME', 'X1');
    $put2('SAME', 'X2');
    $liar = function ($locs) {
        $out = array();
        foreach ($locs as $l) {
            // X1 and X2 landed in one group, and their values disagree.
            $out[\INSPIRE\UniversalValidator\Scan\UniqueFinalizer::locKey($l)] = array($l['record'] === 'X1' ? 'one' : 'two');
        }
        return array('ok' => true, 'values' => $out, 'why' => null);
    };
    $fin2 = new \INSPIRE\UniversalValidator\Scan\UniqueFinalizer($dbA, array('pid' => $PID, 'hmacKey' => $KEY, 'read' => $liar));
    $n2 = 0;
    while ($n2++ < 50) {
        $r = $fin2->step($GEN2, 10);
        if ($r['done']) break;
    }
    $st2 = $fin2->status($GEN2);
    check('unique: a group whose values disagree is marked undecidable',
        $st2['blocking'] === 1 && $st2['published'] === 0);
    check('unique: and finalization still settles rather than looping',
        $st2['done'] === true);
    $f3 = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE generation_id = ?', array($GEN2));
    check('unique: no duplicate verdict is emitted for a group it could not decide',
        (int) $f3[0][0] === 0);

    // A reader that cannot answer is not evidence of anything either.
    $GEN3 = uv_generation('unreadable-values');
    $gen3 = $GEN3;
    $put3 = function ($group, $rec) use ($A, $gen3, $KEY, $PID) {
        $g = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_UNIQUE, $PID, $group, $KEY));
        $h = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_RECORD, $PID, $rec, $KEY));
        $A->query('INSERT INTO ' . Schema::table('unique_candidate') . "
            (generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
             record_hash, record_id_bin, event_id, instance, host_form, field, version_scanned)
            VALUES (" . $gen3 . ", 'r1', '" . str_repeat('c', 64) . "', UNHEX('" . $g . "'),
                    'project', UNHEX('" . $h . "'), '" . $rec . "', 1, 1, 'f', 'hospno', NULL)");
    };
    $put3('Q', 'Y1');
    $put3('Q', 'Y2');
    $broken = function ($locs) {
        return array('ok' => false, 'values' => array(), 'why' => 'the export timed out');
    };
    $fin3 = new \INSPIRE\UniversalValidator\Scan\UniqueFinalizer($dbA, array('pid' => $PID, 'hmacKey' => $KEY, 'read' => $broken));
    $n3 = 0;
    while ($n3++ < 50) {
        $r = $fin3->step($GEN3, 10);
        if ($r['done']) break;
    }
    check('unique: values that could not be re-read block the group rather than confirming it',
        $fin3->status($GEN3)['blocking'] === 1);
    $f4 = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE generation_id = ?', array($GEN3));
    check('unique: and nothing is reported about it', (int) $f4[0][0] === 0);

    // -- a record edited while its group was being decided --------------------
    $GEN4 = uv_generation('edited-mid-check');
    $gen4 = $GEN4;
    $put4 = function ($group, $rec, $ver) use ($A, $gen4, $KEY, $PID) {
        $g = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_UNIQUE, $PID, $group, $KEY));
        $h = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_RECORD, $PID, $rec, $KEY));
        $A->query('INSERT INTO ' . Schema::table('unique_candidate') . "
            (generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
             record_hash, record_id_bin, event_id, instance, host_form, field, version_scanned)
            VALUES (" . $gen4 . ", 'r1', '" . str_repeat('c', 64) . "', UNHEX('" . $g . "'),
                    'project', UNHEX('" . $h . "'), '" . $rec . "', 1, 1, 'f', 'hospno', '" . $ver . "')");
    };
    $put4('M', 'Z1', '100');
    $put4('M', 'Z2', '100');
    $moving = new MovingVersions();
    $moving->v = array('Z1' => '100', 'Z2' => '999');   // Z2 changed since it was scanned
    $fin4 = new \INSPIRE\UniversalValidator\Scan\UniqueFinalizer($dbA, array('pid' => $PID, 'hmacKey' => $KEY,
        'read' => $reader, 'versions' => $moving));
    $fin4->discover($GEN4, 10);
    $before = $dbA->select('SELECT candidate_epoch FROM ' . Schema::table('unique_group')
        . ' WHERE generation_id = ?', array($GEN4));
    $fin4->step($GEN4, 10);
    $afterE = $dbA->select('SELECT candidate_epoch, phase, verify_cursor FROM '
        . Schema::table('unique_group') . ' WHERE generation_id = ?', array($GEN4));
    check('unique: a record edited mid-check restarts its group at a new epoch',
        (int) $afterE[0][0] === (int) $before[0][0] + 1);
    check('unique: from the beginning, not from where it stopped',
        (int) $afterE[0][2] === 0 && $afterE[0][1] === 'new');

    // Staged rows from an abandoned attempt are unreachable and swept in pages.
    $A->query('INSERT INTO ' . Schema::table('finding') . '
        (generation_id, finding_identity, valid_from_seq, active_slot, record_hash,
         record_id_bin, host_form, field, rule_source_id, rule_revision, rule_ord,
         check_type, reason_code, group_hmac, stage_epoch)
        SELECT ' . $GEN4 . ", UNHEX(SHA2('stale', 256)), 1, NULL, record_hash, record_id_bin,
               'f', 'hospno', 'r1', '" . str_repeat('c', 64) . "', 0, 'unique', 'duplicate',
               group_hmac, 1
        FROM " . Schema::table('unique_candidate') . ' WHERE generation_id = ' . $GEN4 . ' LIMIT 1');
    check('unique: rows from an abandoned pass are swept', $fin4->sweep($GEN4, 100) === 1);
    check('unique: and sweeping again finds nothing', $fin4->sweep($GEN4, 100) === 0);

    // -- the group that holds the whole project -------------------------------
    //
    // A rule on a field where every record holds the same value puts every
    // record in ONE group. The property that matters is that the memory this
    // costs does not grow with the group, so it is measured against a small
    // group rather than asserted.
    $BIG = uv_generation('one-huge-group'); $SMALL = uv_generation('one-small-group');
    $bulk = function ($gen, $n) use ($A, $KEY, $PID) {
        $rows = array();
        for ($i = 1; $i <= $n; $i++) {
            $g = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_UNIQUE, $PID, 'ONE', $KEY));
            $h = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_RECORD, $PID, 'B' . $i, $KEY));
            $rows[] = "(" . $gen . ", 'r1', '" . str_repeat('c', 64) . "', UNHEX('" . $g
                . "'), 'project', UNHEX('" . $h . "'), 'B" . $i . "', 1, 1, 'f', 'hospno', NULL)";
            if (count($rows) >= 500) {
                $A->query('INSERT INTO ' . Schema::table('unique_candidate') . '
                    (generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
                     record_hash, record_id_bin, event_id, instance, host_form, field,
                     version_scanned) VALUES ' . implode(',', $rows));
                $rows = array();
            }
        }
        if ($rows) {
            $A->query('INSERT INTO ' . Schema::table('unique_candidate') . '
                (generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
                 record_hash, record_id_bin, event_id, instance, host_form, field,
                 version_scanned) VALUES ' . implode(',', $rows));
        }
    };
    $same = function ($locs) {
        $out = array();
        foreach ($locs as $l) $out[\INSPIRE\UniversalValidator\Scan\UniqueFinalizer::locKey($l)] = array('ONE');
        return array('ok' => true, 'values' => $out, 'why' => null);
    };
    $bulk($SMALL, 50);
    $bulk($BIG, 20000);

    $finS = new \INSPIRE\UniversalValidator\Scan\UniqueFinalizer($dbA, array('pid' => $PID, 'hmacKey' => $KEY, 'read' => $same));
    $m0 = memory_get_usage(true);
    $k = 0; while ($k++ < 200) { if ($finS->step($SMALL, 500)['done']) break; }
    $smallPeak = memory_get_usage(true) - $m0;

    $finB = new \INSPIRE\UniversalValidator\Scan\UniqueFinalizer($dbA, array('pid' => $PID, 'hmacKey' => $KEY, 'read' => $same));
    $m1 = memory_get_usage(true);
    $k = 0; while ($k++ < 2000) { if ($finB->step($BIG, 500)['done']) break; }
    $bigPeak = memory_get_usage(true) - $m1;

    check('unique: a 20,000-record group finalizes', $finB->status($BIG)['done'] === true
        && $finB->status($BIG)['published'] === 1);
    $bigF = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE generation_id = ?', array($BIG));
    check('unique: reporting every record in it', (int) $bigF[0][0] === 20000);
    // 400x the candidates. If anything accumulated a group, this is where it
    // would show; the page size is the only thing that sets the footprint.
    check('unique: and costs no more memory than a group 400 times smaller',
        $bigPeak <= $smallPeak + (4 * 1024 * 1024));

    foreach (array('finding', 'unique_candidate', 'unique_group') as $t) {
        $A->query('DELETE FROM ' . Schema::table($t));
    }
}
