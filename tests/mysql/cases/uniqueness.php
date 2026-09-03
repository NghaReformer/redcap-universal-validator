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
 * TWO PROJECTS, IN ONE GENERATION.
 *
 * 900 is the project under test and 901 is planted beside it with candidates of
 * its own, so the group HMAC - which is keyed on the project id - is exercised
 * against a real second project rather than against an empty table. Nothing this
 * project publishes may contain one of the neighbour's records, and that is
 * asserted below.
 *
 * The neighbour's candidates used to sit in a generation of their own, and that
 * was a concession rather than a design: the finalizer scoped every statement it
 * made by generation and by nothing else, so two projects sharing one made
 * discover() and status() answer about both at once. Every statement in
 * UniqueFinalizer carries a project predicate now, so the neighbour shares this
 * project's generation - which is what two projects on a real installation do -
 * and `status($GEN)['groups']` is a question with one project's answer.
 *
 * That is the whole of what this case exists to prove. A finalizer that
 * regressed to filtering by generation alone would discover the neighbour's
 * group, count it pending, and never settle - which is exactly what was
 * reproduced against a real server: status() answered "not done, 1 blocking"
 * over groups belonging to a different project, so the run never reached a
 * terminal state and never released its project's slot.
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
            (project_id, generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
             record_hash, record_id_bin, event_id, instance, host_form, field, version_scanned)
            VALUES (" . $PID . ", " . $GEN . ", 'r1', '" . str_repeat('c', 64) . "', UNHEX('" . $g . "'),
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
    // ids, IN THE SAME GENERATION. Two things are being asked at once, and both
    // used to be unaskable here.
    //
    // The first is the group hash, which is keyed on the project id: if the pid
    // ever left the HMAC, 901's R1 and 900's R1 would land in one group and this
    // module would report two participants at two different sites as duplicates
    // of each other.
    //
    // The second is every statement the finalizer makes. It scoped by
    // generation alone, and the neighbour was given a generation of its own so
    // that could not show - which meant the fixture was arranging the isolation
    // the code was missing. Sharing the generation is what makes discover(),
    // nextUnfinished(), status() and sweep() have to say which project they
    // mean.
    $putNb = function ($group, $rec) use ($A, $GEN, $KEY, $NEIGHBOUR) {
        $g = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_UNIQUE, $NEIGHBOUR, $group, $KEY));
        $h = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_RECORD, $NEIGHBOUR, $rec, $KEY));
        $A->query('INSERT INTO ' . Schema::table('unique_candidate') . "
            (project_id, generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
             record_hash, record_id_bin, event_id, instance, host_form, field, version_scanned)
            VALUES (" . $NEIGHBOUR . ", " . $GEN . ", 'r1', '" . str_repeat('c', 64) . "', UNHEX('" . $g . "'),
                    'project', UNHEX('" . $h . "'), '" . $rec . "', 1, 1, 'f', 'hospno', NULL)");
    };
    $putNb('AB12', 'R1');
    $putNb('AB12', 'R2');
    $nbCands = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('unique_candidate')
        . ' WHERE project_id = ? AND generation_id = ?', array($NEIGHBOUR, $GEN));
    check('unique: a second project holds the same value on the same record ids',
        (int) $nbCands[0][0] === 2);
    // In the SAME generation number as this project's, which is the whole point
    // of the arrangement and is asserted rather than assumed - a fixture that
    // drifted back to two generations would make every check below pass for the
    // wrong reason.
    $sameGen = $dbA->select('SELECT COUNT(DISTINCT generation_id) FROM '
        . Schema::table('unique_candidate'));
    check('unique: sharing this project\'s generation, as two projects on one server do',
        (int) $sameGen[0][0] === 1);
    $shared = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('unique_candidate') . ' a
        JOIN ' . Schema::table('unique_candidate') . ' b ON a.group_hmac = b.group_hmac
        WHERE a.project_id = ? AND b.project_id = ?', array($PID, $NEIGHBOUR));
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

    // THE PROJECT IS A CONSTRUCTOR FACT. emit() used to read it as
    // `isset($deps['pid']) ? $deps['pid'] : 0` at the one place it happened to
    // need one, and a silent 0 is the same shape as the constant generation:
    // every query in the file would then span the whole installation, and a
    // wrong project is not distinguishable from a right one by anyone reading
    // the report.
    foreach (array('absent' => array(), 'zero' => array('pid' => 0)) as $what => $over) {
        $refused = false;
        try {
            new \INSPIRE\UniversalValidator\Scan\UniqueFinalizer($dbA,
                array_merge(array('hmacKey' => $KEY, 'read' => $reader), $over));
        } catch (\InvalidArgumentException $e) {
            $refused = true;
        }
        check('unique: a finalizer built with a ' . $what . ' project is refused rather than '
            . 'defaulted', $refused === true);
    }

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
        . ' WHERE project_id = ? AND generation_id = ? AND reason_code = ?',
        array($PID, $GEN, 'duplicate'));
    check('unique: one finding for every record in a duplicate group',
        (int) $f[0][0] === 5);
    $active = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = ? AND generation_id = ? AND active_slot = 1',
        array($PID, $GEN));
    check('unique: and all of them are visible once their group is published',
        (int) $active[0][0] === 5);
    $lonely = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . " WHERE project_id = ? AND generation_id = ? AND record_id_bin = 'R6'",
        array($PID, $GEN));
    check('unique: a record whose value nobody shares is not reported',
        (int) $lonely[0][0] === 0);

    // A RETRIED PAGE MUST NOT DOUBLE THE REPORT. The staged rows are keyed by
    // identity within their epoch, which is a key the active-identity one cannot
    // supply: a staged row has no active slot, and MySQL counts every NULL in a
    // unique index as distinct.
    $A->query('UPDATE ' . Schema::table('unique_group')
        . " SET phase = 'emitting', emit_cursor = 0 WHERE project_id = " . $PID
        . ' AND generation_id = ' . $GEN . " AND phase = 'published'");
    $again = 0;
    while ($again++ < 50) {
        $r = $fin->step($GEN, 2);
        if ($r['done']) break;
    }
    $f2 = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE project_id = ? AND generation_id = ? AND reason_code = ?',
        array($PID, $GEN, 'duplicate'));
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
            (project_id, generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
             record_hash, record_id_bin, event_id, instance, host_form, field, version_scanned)
            VALUES (" . $PID . ", " . $gen2 . ", 'r1', '" . str_repeat('c', 64) . "', UNHEX('" . $g . "'),
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
        . ' WHERE project_id = ? AND generation_id = ?', array($PID, $GEN2));
    check('unique: no duplicate verdict is emitted for a group it could not decide',
        (int) $f3[0][0] === 0);

    // A reader that cannot answer is not evidence of anything either.
    $GEN3 = uv_generation('unreadable-values');
    $gen3 = $GEN3;
    $put3 = function ($group, $rec) use ($A, $gen3, $KEY, $PID) {
        $g = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_UNIQUE, $PID, $group, $KEY));
        $h = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_RECORD, $PID, $rec, $KEY));
        $A->query('INSERT INTO ' . Schema::table('unique_candidate') . "
            (project_id, generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
             record_hash, record_id_bin, event_id, instance, host_form, field, version_scanned)
            VALUES (" . $PID . ", " . $gen3 . ", 'r1', '" . str_repeat('c', 64) . "', UNHEX('" . $g . "'),
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
        . ' WHERE project_id = ? AND generation_id = ?', array($PID, $GEN3));
    check('unique: and nothing is reported about it', (int) $f4[0][0] === 0);

    // -- a record edited while its group was being decided --------------------
    $GEN4 = uv_generation('edited-mid-check');
    $gen4 = $GEN4;
    $put4 = function ($group, $rec, $ver) use ($A, $gen4, $KEY, $PID) {
        $g = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_UNIQUE, $PID, $group, $KEY));
        $h = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_RECORD, $PID, $rec, $KEY));
        $A->query('INSERT INTO ' . Schema::table('unique_candidate') . "
            (project_id, generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
             record_hash, record_id_bin, event_id, instance, host_form, field, version_scanned)
            VALUES (" . $PID . ", " . $gen4 . ", 'r1', '" . str_repeat('c', 64) . "', UNHEX('" . $g . "'),
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
        . ' WHERE project_id = ? AND generation_id = ?', array($PID, $GEN4));
    $fin4->step($GEN4, 10);
    $afterE = $dbA->select('SELECT candidate_epoch, phase, verify_cursor FROM '
        . Schema::table('unique_group') . ' WHERE project_id = ? AND generation_id = ?',
        array($PID, $GEN4));
    check('unique: a record edited mid-check restarts its group at a new epoch',
        (int) $afterE[0][0] === (int) $before[0][0] + 1);
    check('unique: from the beginning, not from where it stopped',
        (int) $afterE[0][2] === 0 && $afterE[0][1] === 'new');

    // Staged rows from an abandoned attempt are unreachable and swept in pages.
    $A->query('INSERT INTO ' . Schema::table('finding') . '
        (project_id, generation_id, finding_identity, valid_from_seq, active_slot, record_hash,
         record_id_bin, host_form, field, rule_source_id, rule_revision, rule_ord,
         check_type, reason_code, group_hmac, stage_epoch)
        SELECT ' . $PID . ', ' . $GEN4 . ", UNHEX(SHA2('stale', 256)), 1, NULL, record_hash,
               record_id_bin, 'f', 'hospno', 'r1', '" . str_repeat('c', 64) . "', 0, 'unique',
               'duplicate', group_hmac, 1
        FROM " . Schema::table('unique_candidate') . ' WHERE project_id = ' . $PID
        . ' AND generation_id = ' . $GEN4 . ' LIMIT 1');
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
            $rows[] = "(" . $PID . ", " . $gen . ", 'r1', '" . str_repeat('c', 64) . "', UNHEX('" . $g
                . "'), 'project', UNHEX('" . $h . "'), 'B" . $i . "', 1, 1, 'f', 'hospno', NULL)";
            if (count($rows) >= 500) {
                $A->query('INSERT INTO ' . Schema::table('unique_candidate') . '
                    (project_id, generation_id, rule_source_id, rule_revision, group_hmac,
                     scope_key, record_hash, record_id_bin, event_id, instance, host_form, field,
                     version_scanned) VALUES ' . implode(',', $rows));
                $rows = array();
            }
        }
        if ($rows) {
            $A->query('INSERT INTO ' . Schema::table('unique_candidate') . '
                (project_id, generation_id, rule_source_id, rule_revision, group_hmac,
                 scope_key, record_hash, record_id_bin, event_id, instance, host_form, field,
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
        . ' WHERE project_id = ? AND generation_id = ?', array($PID, $BIG));
    check('unique: reporting every record in it', (int) $bigF[0][0] === 20000);
    // 400x the candidates. If anything accumulated a group, this is where it
    // would show; the page size is the only thing that sets the footprint.
    check('unique: and costs no more memory than a group 400 times smaller',
        $bigPeak <= $smallPeak + (4 * 1024 * 1024));

    foreach (array('finding', 'unique_candidate', 'unique_group') as $t) {
        $A->query('DELETE FROM ' . Schema::table($t));
    }
}

// -- WHAT THE FINALIZER COSTS, MEASURED RATHER THAN ASSUMED -------------------
//
// Two defects the assertions above cannot see, because both are about the shape
// of the traffic and neither changes a single row of the answer.
//
//   P8  discover() issued ONE INSERT PER GROUP. Measured on this server before
//       the fix: 811 seconds for 100,000 groups direct, and worse again through
//       the External Modules wrapper, which runs a second statement per write to
//       read ROW_COUNT(). The rebuild plan's own non-negotiable - "query counts
//       scale as O(chunks), not O(findings)" - was simply not met.
//
//   P7  nextUnfinished() had no index carrying `phase`, so the optimiser used
//       the group key and the walk got SLOWER as groups settled: 2.88 ms with
//       none settled, 283.82 ms with all of them, and a flat 1.49-1.73 ms once
//       ix_pending existed. That shape - fine on an empty project, unusable on
//       a finished one - is the one a functional test never reaches.
{
    $KEY = 'cost-test-key';
    $GEN = uv_generation('finalizer-cost');
    foreach (array('finding', 'unique_candidate', 'unique_group') as $t) {
        $A->query('DELETE FROM ' . Schema::table($t));
    }

    // 1,200 groups of one candidate each. Singletons on purpose: they settle
    // immediately, which is exactly the state P7's measurement degrades in, and
    // they need no read closure to decide.
    $N = 1200;
    $vals = array();
    for ($i = 1; $i <= $N; $i++) {
        $g = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(
            \INSPIRE\UniversalValidator\Scan\Hmac::P_UNIQUE, $PID, 'cost-' . $i, $KEY));
        $h = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(
            \INSPIRE\UniversalValidator\Scan\Hmac::P_RECORD, $PID, 'CR-' . $i, $KEY));
        $vals[] = "(" . $PID . ", " . $GEN . ", 'r1', REPEAT('c',64), UNHEX('" . $g . "'), '',"
                . " UNHEX('" . $h . "'), 'CR-" . $i . "', 0, 1, 'fa', 'x', NULL)";
    }
    foreach (array_chunk($vals, 200) as $chunk) {
        $A->query('INSERT INTO ' . Schema::table('unique_candidate') . '
            (project_id, generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
             record_hash, record_id_bin, event_id, instance, host_form, field, version_scanned)
            VALUES ' . implode(',', $chunk));
    }
    $planted = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('unique_candidate')
        . ' WHERE project_id = ? AND generation_id = ?', array($PID, $GEN));
    check('cost: 1,200 candidate groups are on the table', (int) $planted[0][0] === $N);

    $rec = new UvRecordingDb($dbA);
    $finC = new \INSPIRE\UniversalValidator\Scan\UniqueFinalizer($rec,
        array('pid' => $PID, 'hmacKey' => $KEY, 'read' => function () {
            return array('ok' => true, 'values' => array(), 'why' => null);
        }));
    $made = $finC->discover($GEN, $N);
    check('cost: discovery creates a group row for every candidate group', $made === $N);

    $inserts = $rec->matching('exec', array('INSERT INTO ' . Schema::table('unique_group')));
    // ceil(1200 / DISCOVER_CHUNK). Written as the arithmetic rather than as 3,
    // so raising the chunk size does not require editing a magic number here -
    // and so the assertion still says what it means if someone lowers it.
    $expect = (int) ceil($N / \INSPIRE\UniversalValidator\Scan\UniqueFinalizer::DISCOVER_CHUNK);
    check('cost: written in one statement per chunk, not one per group',
        count($inserts) === $expect);
    if (count($inserts) !== $expect) {
        fwrite(STDERR, '  discovery issued ' . count($inserts) . ' inserts, expected '
            . $expect . "\n");
    }
    // O(chunks) is the claim, and a claim about growth needs the other end of
    // it: 1,200 rows written by 3 statements is only meaningful beside the fact
    // that 1,200 statements is what it used to be.
    check('cost: so the statement count is bounded by the chunk size, not the group count',
        count($inserts) < $N / 100);
    $rows = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('unique_group')
        . ' WHERE project_id = ? AND generation_id = ?', array($PID, $GEN));
    check('cost: and every group really is on the table', (int) $rows[0][0] === $N);
    $single = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('unique_group')
        . ' WHERE project_id = ? AND generation_id = ? AND phase = ?',
        array($PID, $GEN, \INSPIRE\UniversalValidator\Scan\UniqueFinalizer::G_SINGLETON));
    check('cost: each recorded as the singleton it is, not as pending work',
        (int) $single[0][0] === $N);

    // P7: THE STATEMENT THE CODE ISSUED, EXPLAINED. Not a copy of it - the
    // recording db hands back what nextUnfinished() actually sent, so an
    // optimiser plan proved here is the plan production gets. Every group above
    // is settled, which is the state the old plan was 100x slower in.
    $rec->log = array();
    $step = $finC->step($GEN, 10);
    check('cost: with every group settled the finalizer reports done', $step['done'] === true);
    $walk = $rec->matching('select', array(Schema::table('unique_group'), 'phase IN'));
    check('cost: and it asked for the next unfinished group to find that out',
        count($walk) >= 1);
    if ($walk) {
        $key = uv_explain_key($A, $walk[0][1], $walk[0][2]);
        check('cost: the pending-group walk uses ix_pending rather than the group key',
            $key === 'ix_pending');
        if ($key !== 'ix_pending') {
            fwrite(STDERR, '  the optimiser chose: ' . ($key === '' ? '(no index)' : $key) . "\n");
        }
    }

    foreach (array('finding', 'unique_candidate', 'unique_group') as $t) {
        $A->query('DELETE FROM ' . Schema::table($t));
    }
}
