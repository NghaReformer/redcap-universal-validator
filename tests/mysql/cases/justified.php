<?php
/**
 * tests/mysql/cases/justified.php — uv_finding.justified (@UVJUSTIFY), schema
 * version 7.
 *
 * A finding a reason stands for is stored with justified = 1. The rollup counts
 * it on its own axis, apart from the open findings, and it is no bar to clean.
 * Each block here is a claim about what the server does with the column: how it
 * is defined on a fresh install and after the version-7 migration, the values
 * the store writes and reads back, the run's count of them (scan_run.
 * detail_justified, which a working run's status reads), the keys that leave
 * it out of a finding's identity, and the summary and the verdict built on it.
 *
 * THE NEIGHBOUR HAS A JUSTIFIED FINDING. Every block that counts plants project
 * pid+1 in the same generation, and one of its two findings is marked justified,
 * so a statement that lost its project predicate reads wrong on the axis this
 * version adds as well as on the old ones.
 *
 * THE MIGRATION RUNS LAST, because it drops the tables the runner built and
 * rebuilds them at version 6.
 */

use INSPIRE\UniversalValidator\Scan\Schema;
use INSPIRE\UniversalValidator\Scan\SqlScanStore;
use INSPIRE\UniversalValidator\Scan\ArrayScanStore;
use INSPIRE\UniversalValidator\Scan\ScanStore;
use INSPIRE\UniversalValidator\Scan\ScanPhase;
use INSPIRE\UniversalValidator\Scan\Hmac;
use INSPIRE\UniversalValidator\Scan\RollupBuilder;
use INSPIRE\UniversalValidator\Scan\ScanPromotion;
use INSPIRE\UniversalValidator\Scan\FindingRowMapper;

// toFinding() names a stored reason through ReasonCode, which nothing in the
// harness loads.
require_once __DIR__ . '/../../../php/Scan/ReasonCode.php';

$F = Schema::table('finding');

// The column as information_schema describes it. COLUMN_TYPE is spelt
// "tinyint(3) unsigned" on MySQL 5.7 and MariaDB and "tinyint unsigned" on
// 8.0, so the type and the sign are read apart.
$definition = function () use ($A, $F) {
    $r = $A->query("SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = '" . $F . "' AND column_name = 'justified'");
    $row = $r ? $r->fetch_row() : null;
    if ($r) $r->free();
    return $row === null ? null : [
        'type'     => strtolower((string) $row[0]),
        'unsigned' => stripos((string) $row[1], 'unsigned') !== false,
        'nullable' => (string) $row[2],
        'default'  => $row[3] === null ? null : (string) $row[3],
    ];
};
$WANT = ['type' => 'tinyint', 'unsigned' => true, 'nullable' => 'NO', 'default' => '0'];

// How many statements migrate() applies from version $from on. A for loop, not
// range(): range(7, 6) counts down.
$statementsFrom = function ($from) {
    $n = 0;
    for ($v = $from; $v <= Schema::VERSION; $v++) $n += count(Schema::statements($v));
    return $n;
};

// A run on one project, its manifest written and nothing claimed yet.
$open = function ($store, $pid, array $ids) {
    $r = $store->startRun($pid, ['created_by' => 'alice', 'fence_open' => '10']);
    $rid = (int) $r['run']['run_id'];
    $m = [];
    foreach ($ids as $id) $m[] = ['id_bin' => $id, 'hash' => hash('sha256', $id, true), 'dag' => null];
    $store->writeManifest($rid, $m);
    $run = $store->run($pid, $rid);
    return ['run' => $rid, 'gen' => (int) $run['generation_id'], 'epoch' => (int) $run['lease_epoch']];
};
// Claims by record id, whatever order the store hands them out in.
$byId = function ($claims) {
    $out = [];
    foreach (is_array($claims) ? $claims : [] as $c) $out[$c['id_bin']] = $c;
    return $out;
};
// One finding of a claimed record. No valid_from_seq: absent is the run's own
// sequence in both stores (M13), so the parity check below can share it.
$finding = function ($pid, $gen, array $c, $field, array $over = []) {
    return $over + [
        'ordinal' => $c['ordinal'], 'project_id' => $pid, 'generation_id' => $gen,
        'identity' => hash('sha256', 'jf-' . $pid . '-' . $c['id_bin'] . '-' . $field, true),
        'record_hash' => $c['hash'], 'record_id_bin' => $c['id_bin'],
        'event_id' => null, 'instance' => 1, 'host_form' => 'visit', 'field' => $field,
        'rule_source_id' => 'r-' . $field, 'rule_revision' => str_repeat('c', 64), 'rule_ord' => 1,
        'check_type' => 'required', 'reason_code' => 'required-blank',
    ];
};
$done = function (array $c) {
    return ['ordinal' => $c['ordinal'], 'claim' => $c['claim'], 'record_hash' => $c['hash'],
            'state' => ScanStore::REC_DONE, 'version' => '500'];
};
// The neighbour, with the first of its two findings justified.
$neighbour = function ($pid) use ($dbA, $A, $F) {
    $nb = uv_plant_neighbour($dbA, $pid);
    $A->query('UPDATE ' . $F . ' SET justified = 1 WHERE project_id = ' . uv_neighbour($pid)
        . ' ORDER BY finding_id LIMIT 1');
    $r = $dbA->select('SELECT COUNT(*), SUM(justified) FROM ' . $F . ' WHERE project_id = ?',
        [uv_neighbour($pid)]);
    return (is_array($nb) && (int) $r[0][0] === 2 && (int) $r[0][1] === 1) ? $nb : null;
};
$mapper = function ($pid) {
    return new FindingRowMapper(['pid' => $pid, 'now' => date('Y-m-d H:i:s'), 'valuesState' => 'raw',
        'readerCeiling' => 'raw', 'identifiers' => [], 'recordIds' => 'raw',
        'recordIdField' => 'record_id']);
};

// -- A FRESH INSTALL ----------------------------------------------------------
//
// The runner migrated this schema from nothing, so it is the shape a new
// installation gets.
{
    check('fresh install: the schema is at the build version, 6 or later',
        Schema::currentVersion($ca) === Schema::VERSION && Schema::VERSION >= 6);
    $got = $definition();
    check('fresh install: uv_finding.justified is TINYINT UNSIGNED NOT NULL DEFAULT 0 (got '
        . json_encode($got) . ')', $got === $WANT);

    // UniqueFinalizer's INSERT does not name the column, so the server's
    // default is what its rows hold.
    $A->query('INSERT INTO ' . $F . ' (project_id, generation_id, finding_identity, valid_from_seq,
            active_slot, record_hash, record_id_bin, host_form, field, rule_source_id, rule_revision,
            rule_ord, check_type, reason_code)
        VALUES (979, 1, UNHEX(SHA2("jd-1", 256)), 1, 1, UNHEX(SHA2("D-1", 256)), "D-1", "fa", "mrn",
            "r-unique", REPEAT("d", 64), 0, "unique", "duplicate")');
    $d = $dbA->select('SELECT justified FROM ' . $F . ' WHERE project_id = ?', [979]);
    check('fresh install: a row written without naming the column reads 0',
        count($d) === 1 && (string) $d[0][0] === '0');
    $A->query('DELETE FROM ' . $F . ' WHERE project_id = 979');

    // IDENTITY UNCHANGED. Neither uniqueness key of uv_finding may hold the
    // flag, or a finding saved again with its reason given would be a second
    // active row of the same problem rather than a new version of it.
    $keyed = $dbA->select("SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = '" . $F . "' AND column_name = 'justified'");
    check('identity: no key of uv_finding holds justified', (int) $keyed[0][0] === 0);
    check('identity: one active row per identity is (project_id, generation_id, finding_identity, active_slot)',
        uv_index_columns($A, $F, 'uq_active_identity_v2')
            === ['project_id', 'generation_id', 'finding_identity', 'active_slot']);
}

// -- WRITTEN AND READ BACK ----------------------------------------------------
//
// One batch holds a finding with its reason given, one sent with justified 0 and
// one sent with no flag at all, which is every finding a rule a reason cannot
// stand for produces. The store writes 1, 0 and 0, findings() returns them under
// the mapper's name for the column, and the mapper reads true, false, false.
$PID = 980;
{
    $s = new SqlScanStore($dbA);
    check('store: the neighbour holds two findings in the same tables, one justified',
        $neighbour($PID) !== null);

    $o = $open($s, $PID, ['J-1', 'J-2']);
    $c = $byId($s->claim($o['run'], 'w', $o['epoch'], 2));
    $batch = function ($gen, array $c) use ($PID, $finding, $done) {
        return ['bytes' => 0, 'records' => [$done($c['J-1']), $done($c['J-2'])], 'findings' => [
            $finding($PID, $gen, $c['J-1'], 'with_reason', ['justified' => 1]),
            $finding($PID, $gen, $c['J-1'], 'no_flag'),
            $finding($PID, $gen, $c['J-2'], 'zero', ['justified' => 0]),
        ]];
    };
    $ok = $s->commitBatch($o['run'], 'w', $o['epoch'], $batch($o['gen'], $c));
    check('store: a batch with one justified finding and two open ones commits', $ok === true);
    if ($ok !== true) fwrite(STDERR, '  the batch said: ' . (string) $ok . "\n");

    $raw = [];
    foreach ($dbA->select('SELECT field, justified FROM ' . $F . ' WHERE project_id = ? AND generation_id = ?',
            [$PID, $o['gen']]) as $r) {
        $raw[(string) $r[0]] = (string) $r[1];
    }
    ksort($raw);
    check('store: stored as 1 for the reason given, 0 for a 0 and 0 for no flag (got ' . json_encode($raw) . ')',
        $raw === ['no_flag' => '0', 'with_reason' => '1', 'zero' => '0']);

    $page = $s->findings($PID, $o['gen'], [], 0, 100);
    $read = [];
    $mapped = [];
    $m = $mapper($PID);
    foreach ($page as $row) {
        $read[(string) $row['field']] = (string) $row['justified'];
        $mapped[(string) $row['field']] = $m->toFinding($row)['justified'];
    }
    ksort($read);
    ksort($mapped);
    check('store: findings() returns the column as the mapper names it, for this project only',
        count($page) === 3 && $read === $raw);
    check('store: and the mapper reads true for the justified finding, false for the open ones',
        $mapped === ['no_flag' => false, 'with_reason' => true, 'zero' => false]);

    // THE SAME BATCH, THE IN-MEMORY STORE. The fast suites run against
    // ArrayScanStore, so its answer has to be this server's answer.
    $mem = new ArrayScanStore();
    $om = $open($mem, $PID, ['J-1', 'J-2']);
    $cm = $byId($mem->claim($om['run'], 'w', $om['epoch'], 2));
    $okm = $mem->commitBatch($om['run'], 'w', $om['epoch'], $batch($om['gen'], $cm));
    $readm = [];
    foreach ($mem->findings($PID, $om['gen'], [], 0, 100) as $row) {
        $readm[(string) $row['field']] = (string) $row['justified'];
    }
    ksort($readm);
    check('store: ArrayScanStore stores and returns the same flags (got ' . json_encode($readm) . ')',
        $okm === true && $readm === $read);

    // THE RUN'S COUNTERS. detail_rows counts the three stored rows and
    // detail_justified the one with its reason given, through the batch's own
    // UPDATE, so the status poll of a working run says them apart: two
    // findings so far, one saved with a reason. Both stores agree.
    $cnt = $dbA->select('SELECT detail_rows, detail_justified FROM ' . Schema::table('scan_run') . ' WHERE run_id = ?', [$o['run']]);
    check('store: the run counts 3 stored rows, 1 of them with a reason (got ' . json_encode($cnt) . ')',
        (int) $cnt[0][0] === 3 && (int) $cnt[0][1] === 1);
    $sqlRun = $s->run($PID, $o['run']);
    $memRun = $mem->run($PID, $om['run']);
    check('store: run() reads the counter back, and ArrayScanStore holds the same',
        (int) $sqlRun['detail_justified'] === 1 && (int) $memRun['detail_justified'] === 1 && (int) $memRun['detail_rows'] === 3);
    check('store: a working run shows 2 findings so far and 1 saved with a reason',
        ScanPromotion::findingsOf($s, $sqlRun) === 2 && ScanPromotion::justifiedOf($s, $sqlRun) === 1);

    // THE READ THAT NOW SELECTS justified, on the key it was built for.
    // Asserted on the statement sent.
    $rec = new UvRecordingDb($dbA);
    (new SqlScanStore($rec))->findings($PID, $o['gen'], [], 0, 100);
    $q = $rec->matching('select', ['FROM ' . $F]);
    $keys = $q ? uv_explain_keys($A, $q[0][1], $q[0][2]) : [];
    check('store: the findings page that selects justified is read through ix_page_v2',
        count($q) === 1 && strpos($q[0][1], 'justified') !== false && $keys === ['ix_page_v2']);
    if ($keys !== ['ix_page_v2']) {
        fwrite(STDERR, '  findings plan: ' . ($q ? implode(' ', uv_explain_rows($A, $q[0][1], $q[0][2])) : '-') . "\n");
    }
}

// -- THE SAME FINDING, ITS REASON GIVEN LATER -----------------------------------
//
// What the store does is version the finding, not update the row: a record
// re-examined inside a run has its active findings closed at the run's sequence
// and its new evidence inserted. The reason is entered between the two
// examinations, so the identity is the same and only the flag differs. It must
// commit (the unique key does not hold the flag), leave one active row for the
// identity, and leave the closed version as it was stored.
$REEX = 982;
{
    $s = new SqlScanStore($dbA);
    $loc = ['record' => 'X-1', 'event_id' => null, 'instance' => 1, 'host_form' => 'visit',
            'field' => 'weight', 'rule_source_id' => 'r-weight', 'reason_code' => 'out-of-range',
            'locus' => ''];
    $ident = Hmac::findingIdentity($REEX, $loc, 'justify-identity-key');
    check('re-examined: the identity builder ignores a justified key',
        Hmac::findingIdentity($REEX, $loc + ['justified' => 1], 'justify-identity-key') === $ident);

    $o = $open($s, $REEX, ['X-1']);
    $seq = uv_run_seq($dbA, $o['run']);
    $one = function (array $c, $justified) use ($REEX, $o, $ident, $finding, $done) {
        return ['bytes' => 0, 'records' => [$done($c)], 'findings' => [
            $finding($REEX, $o['gen'], $c, 'weight', ['identity' => $ident, 'justified' => $justified,
                'check_type' => 'range', 'reason_code' => 'out-of-range'])]];
    };
    $versions = function () use ($dbA, $F, $REEX, $ident) {
        return $dbA->select('SELECT active_slot, valid_to_seq, justified FROM ' . $F
            . ' WHERE project_id = ? AND finding_identity = ? ORDER BY finding_id', [$REEX, $ident]);
    };

    $first = $s->claim($o['run'], 'w', $o['epoch'], 1);
    check('re-examined: the record is first stored open',
        is_array($first) && $s->commitBatch($o['run'], 'w', $o['epoch'], $one($first[0], 0)) === true);
    check('re-examined: catch-up sends it back to pending',
        $s->advancePhase($o['run'], $o['epoch'], ScanPhase::CATCH_UP)
        && $s->requeue($o['run'], $o['epoch'], ['X-1']) === 1);
    $back = $s->claimPending($o['run'], 'w', $o['epoch'], 1);
    $second = is_array($back) && count($back) === 1
        ? $s->commitBatch($o['run'], 'w', $o['epoch'], $one($back[0], 1)) : 'not offered again';
    check('re-examined: stored again with its reason given, the batch commits', $second === true);
    if ($second !== true) fwrite(STDERR, '  the re-examination said: ' . (string) $second . "\n");
    $v = $versions();
    check('re-examined: two versions of one identity, the open one closed at the run sequence, the justified one active',
        count($v) === 2
        && $v[0][0] === null && (int) $v[0][1] === $seq && (int) $v[0][2] === 0
        && (int) $v[1][0] === 1 && $v[1][1] === null && (int) $v[1][2] === 1);
    $page = $s->findings($REEX, $o['gen'], [], 0, 100);
    check('re-examined: the report holds one finding for it, and that one justified',
        count($page) === 1 && (string) $page[0]['justified'] === '1');

    // The reason is emptied again: the next version is open.
    check('re-examined: sent back once more', $s->requeue($o['run'], $o['epoch'], ['X-1']) === 1);
    $again = $s->claimPending($o['run'], 'w', $o['epoch'], 1);
    $third = is_array($again) && count($again) === 1
        ? $s->commitBatch($o['run'], 'w', $o['epoch'], $one($again[0], 0)) : 'not offered again';
    $v = $versions();
    $active = array_values(array_filter($v, function ($r) { return $r[0] !== null; }));
    check('re-examined: with its reason emptied it is open again, still one active row',
        $third === true && count($v) === 3 && count($active) === 1 && (int) $active[0][2] === 0
        && (int) $v[1][2] === 1 && $v[1][0] === null);
}

// -- THE SAME IDENTITY TWICE IN ONE BATCH, APART ONLY IN THE FLAG ---------------
//
// The other side of the same key. Two active rows that differ in justified
// alone are one problem stored twice, and uq_active_identity_v2 refuses them,
// so the whole batch is refused and nothing of it is kept.
$TWICE = 983;
{
    $s = new SqlScanStore($dbA);
    $o = $open($s, $TWICE, ['T-1']);
    $c = $s->claim($o['run'], 'w', $o['epoch'], 1);
    $ident = hash('sha256', 'one-problem', true);
    $res = $s->commitBatch($o['run'], 'w', $o['epoch'], ['bytes' => 0, 'records' => [$done($c[0])],
        'findings' => [$finding($TWICE, $o['gen'], $c[0], 'x', ['identity' => $ident, 'justified' => 0]),
                       $finding($TWICE, $o['gen'], $c[0], 'x', ['identity' => $ident, 'justified' => 1])]]);
    check('one batch: one identity twice, apart only in justified, is refused entire',
        is_string($res) && strpos($res, 'refused') !== false);
    $kept = $dbA->select('SELECT COUNT(*) FROM ' . $F . ' WHERE project_id = ?', [$TWICE]);
    check('one batch: and nothing of it was kept, the record included',
        (int) $kept[0][0] === 0 && !isset($s->recordStates($o['run'])[ScanStore::REC_DONE]));
}

// -- THE SUMMARY --------------------------------------------------------------
//
// Seven active findings over four records: four with a reason, three open. Two
// CLOSED versions with a reason are kept for an "as of run N" view and must not
// be counted, and the neighbour's justified finding sits in the same
// generation. The standing axis reads open 3, justified 4; every axis totals 7.
$ROLL = 984;
{
    $s = new SqlScanStore($dbA);
    $nb = $neighbour($ROLL);
    $o = $open($s, $ROLL, ['R-1', 'R-2', 'R-3', 'R-4']);
    check('rollup: the neighbour, with a justified finding, is in this run\'s generation',
        $nb !== null && (int) $nb['generation_id'] === $o['gen']);
    $c = $byId($s->claim($o['run'], 'w', $o['epoch'], 4));
    $fs = [];
    foreach (['a', 'b', 'c'] as $f) $fs[] = $finding($ROLL, $o['gen'], $c['R-1'], $f, ['justified' => 1]);
    foreach (['a', 'b'] as $f) $fs[] = $finding($ROLL, $o['gen'], $c['R-2'], $f, ['dag_key' => 'site_a']);
    $fs[] = $finding($ROLL, $o['gen'], $c['R-3'], 'a', ['justified' => 1, 'host_form' => 'followup']);
    $fs[] = $finding($ROLL, $o['gen'], $c['R-3'], 'b', ['check_type' => 'constraint', 'reason_code' => 'assert']);
    $recs = [];
    foreach (['R-1', 'R-2', 'R-3', 'R-4'] as $id) $recs[] = $done($c[$id]);
    check('rollup: the seven findings commit',
        $s->commitBatch($o['run'], 'w', $o['epoch'], ['bytes' => 0, 'records' => $recs, 'findings' => $fs]) === true);
    $A->query('INSERT INTO ' . $F . ' (project_id, generation_id, finding_identity, valid_from_seq,
            valid_to_seq, active_slot, record_hash, record_id_bin, host_form, field, rule_source_id,
            rule_revision, rule_ord, check_type, reason_code, justified)
        VALUES (' . $ROLL . ', ' . $o['gen'] . ', UNHEX(SHA2("closed-1", 256)), 1, 1, NULL,
                UNHEX(SHA2("R-4", 256)), "R-4", "visit", "old1", "r-old", REPEAT("c", 64), 1,
                "required", "required-blank", 1),
               (' . $ROLL . ', ' . $o['gen'] . ', UNHEX(SHA2("closed-2", 256)), 1, 1, NULL,
                UNHEX(SHA2("R-4", 256)), "R-4", "visit", "old2", "r-old", REPEAT("c", 64), 1,
                "required", "required-blank", 1)');

    $rec = new UvRecordingDb($dbA);
    $roll = new RollupBuilder($rec, $s);
    $pages = 0;
    while ($pages++ < 50) {
        if ($roll->step($ROLL, $o['run'], $o['epoch'], $o['gen'], 2)['done']) break;
    }
    check('rollup: built in bounded pages of two', $pages > 2 && $pages < 50);
    $totals = function () use ($s, $o) {
        $by = [];
        $standing = [];
        foreach ($s->aggregates($o['run']) as $a) {
            $by[$a['kind']] = (isset($by[$a['kind']]) ? $by[$a['kind']] : 0) + (int) $a['cnt'];
            if ($a['kind'] === RollupBuilder::K_STANDING) $standing[(string) $a['axis1']] = (int) $a['cnt'];
        }
        ksort($by);
        ksort($standing);
        return [$by, $standing];
    };
    list($by, $standing) = $totals();
    check('rollup: open and justified are counted apart, active rows of this project only (got '
        . json_encode($standing) . ')',
        $standing === [RollupBuilder::STANDING_JUSTIFIED => 4, RollupBuilder::STANDING_OPEN => 3]);
    $kinds = RollupBuilder::KINDS;
    sort($kinds);
    check('rollup: every axis, the standing one included, totals the seven active findings (got '
        . json_encode($by) . ')',
        array_keys($by) === $kinds && min($by) === 7 && max($by) === 7);
    $t = ScanPromotion::tally($s->aggregates($o['run']));
    check('rollup: so the tally reads three violations and four justified',
        $t['violations'] === 3 && $t['justified'] === 4);
    $roll->step($ROLL, $o['run'], $o['epoch'], $o['gen'], 2);
    check('rollup: a finished summary does not grow when it is asked again',
        $totals() === [$by, $standing]);

    // THE PAGE THAT NOW READS justified, every one as sent, EXPLAINed on this
    // server. Its predicate is ix_page_v2's prefix and its order is that key's
    // order, so that is the key. Left to choose, MySQL 8.0 read the first page
    // through ix_filter_form_v2 and sorted the generation, and MariaDB 10.11
    // read the last through PRIMARY from the cursor, past every project's
    // findings.
    $q = $rec->matching('select', ['FROM ' . $F]);
    $plans = [];
    foreach ($q as $page) $plans[] = implode(' ', uv_explain_keys($A, $page[1], $page[2]));
    check('rollup: every page reads justified through ix_page_v2 (got ' . json_encode($plans) . ')',
        count($q) > 2 && strpos($q[0][1], 'justified') !== false
        && array_unique($plans) === ['ix_page_v2']);
    echo '  plan rollup pages: ' . ($q ? implode(' ', uv_explain_rows($A, $q[0][1], $q[0][2])) . ' .. '
        . implode(' ', uv_explain_rows($A, $q[count($q) - 1][1], $q[count($q) - 1][2])) : '-') . "\n";
}

// -- THE VERDICT --------------------------------------------------------------
//
// A run whose only findings have their reason given is clean: complete over a
// proved window, a stored clean = 1, nothing to show and every finding counted
// as saved with a reason. The control is the same run with one finding open.
// The caller's own count is not asked for; promote() reads the summary.
{
    $s = new SqlScanStore($dbA);
    $finish = function ($pid, array $flags) use ($s, $open, $byId, $finding, $done, $dbA, $A) {
        $ids = [];
        foreach ($flags as $i => $unused) $ids[] = 'P-' . ($i + 1);
        $o = $open($s, $pid, $ids);
        $c = $byId($s->claim($o['run'], 'w', $o['epoch'], count($ids)));
        $b = ['bytes' => 0, 'records' => [], 'findings' => []];
        foreach ($ids as $i => $id) {
            $b['records'][] = $done($c[$id]);
            $b['findings'][] = $finding($pid, $o['gen'], $c[$id], 'x', ['justified' => $flags[$i]]);
        }
        $ok = $s->commitBatch($o['run'], 'w', $o['epoch'], $b);
        foreach ([ScanPhase::CATCH_UP, ScanPhase::UNIQUE, ScanPhase::ROLLUP] as $ph) {
            $s->advancePhase($o['run'], $o['epoch'], $ph);
        }
        $s->setProgressState($o['run'], $o['epoch'], ['fenceTarget' => '500', 'rollupCursor' => 0]);
        $roll = new RollupBuilder($dbA, $s);
        while ($roll->step($pid, $o['run'], $o['epoch'], $o['gen'], 100)['done'] === false) { }
        $p = ScanPromotion::promote($s, $pid, $o['run'], ['uniqueDone' => true, 'rollupDone' => true]);
        $raw = $A->query('SELECT terminal, coverage, clean, detail_rows FROM ' . Schema::table('scan_run')
            . ' WHERE run_id = ' . (int) $o['run'])->fetch_row();
        return [$ok, $p, $raw, $s->run($pid, $o['run'])];
    };

    list($ok, $p, $raw, $run) = $finish(986, [1, 1, 1]);
    check('verdict: three records, each with a finding its reason stands for, are stored',
        $ok === true && (int) $raw[3] === 3);
    check('verdict: the run is complete over a proved window',
        $p['promoted'] === true && $raw[0] === 'complete' && $raw[1] === 'complete-through-fence');
    check('verdict: and clean, a stored 1, since no finding is open',
        $p['outcome']['clean'] === true && $raw[2] !== null && (int) $raw[2] === 1);
    check('verdict: it has no findings to show and three saved with a reason',
        ScanPromotion::findingsOf($s, $run) === 0 && ScanPromotion::justifiedOf($s, $run) === 3);

    list($ok, $p, $raw, $run) = $finish(987, [1, 0, 1]);
    check('verdict (control): with one finding open the run is complete and NOT clean, a stored 0',
        $ok === true && $p['promoted'] === true && $raw[0] === 'complete'
        && $p['outcome']['clean'] === false && $raw[2] !== null && (int) $raw[2] === 0);
    check('verdict (control): one finding to show, two saved with a reason',
        ScanPromotion::findingsOf($s, $run) === 1 && ScanPromotion::justifiedOf($s, $run) === 2);
}

// -- AN INSTALLATION AT VERSION 6, WITH FINDINGS ----------------------------
//
// Built from Schema::statements(1) to (6) and their version rows, the shape a
// 2.3.0-rc.3 installation holds, with a run and findings stored the way a
// version-6 build wrote them. The migration adds the column, keeps every row
// at 0 and records version 7. Run again it does nothing. With the column added and the
// version row lost (the state an interrupted migration leaves) it skips the
// ALTER and records the version.
{
    foreach (array_reverse(Schema::tables()) as $t) $A->query('DROP TABLE IF EXISTS ' . $t);
    for ($v = 1; $v <= 6; $v++) {
        foreach (Schema::statements($v) as $item) $A->query(is_array($item) ? $item['sql'] : $item);
        $A->query('INSERT INTO ' . Schema::table('schema_version') . ' (version, applied_at) VALUES ('
            . $v . ', NOW())');
    }
    check('v6: the fixture reports version 6 and has no justified column',
        Schema::currentVersion($ca) === 6 && $definition() === null);
    $h = Schema::health($ca);
    check('v6: which health() refuses, naming the version it is at', $h['ok'] === false && $h['version'] === 6);

    // startRun()'s INSERT and writeManifest() name no version-7 column, so a
    // run can be planted through the store. startRun() then reads the row back
    // through run(), which names detail_justified (version 7) and so fails at
    // version 6; the row is written by then, and its id, generation and epoch
    // are read here. The findings are written as a version-6 build wrote them,
    // with no justified in the column list.
    $s5 = new SqlScanStore($dbA);
    $open5 = function ($pid, array $ids) use ($s5, $dbA) {
        try {
            $s5->startRun($pid, ['created_by' => 'alice', 'fence_open' => '10']);
        } catch (\Throwable $e) {
            // the read-back of a version-6 row, as above
        }
        $r = $dbA->select('SELECT run_id, generation_id, lease_epoch FROM ' . Schema::table('scan_run')
            . ' WHERE project_id = ? AND active_slot = 1', [$pid]);
        $rid = (int) $r[0][0];
        $m = [];
        foreach ($ids as $id) $m[] = ['id_bin' => $id, 'hash' => hash('sha256', $id, true), 'dag' => null];
        $s5->writeManifest($rid, $m);
        return ['run' => $rid, 'gen' => (int) $r[0][1], 'epoch' => (int) $r[0][2]];
    };
    $o = $open5(990, ['NEW-1']);
    $on = $open5(991, ['NB-1']);
    $seq = uv_run_seq($dbA, $o['run']);
    $row = function ($pid, $gen, $seq, $name, $rec, $closed, $field, $z, $ref) {
        return '(' . (int) $pid . ', ' . (int) $gen . ', UNHEX(SHA2("' . $name . '", 256)), ' . (int) $seq . ', '
            . ($closed ? (int) $seq . ', NULL' : 'NULL, 1') . ', UNHEX(SHA2("' . $rec . '", 256)), "' . $rec
            . '", "visit", "' . $field . '", "r-' . $field . '", REPEAT("c", 64), 1, "range", "out-of-range", '
            . ($z === null ? 'NULL' : '"' . $z . '"') . ', ' . ($ref === null ? 'NULL' : '"' . $ref . '"') . ')';
    };
    $A->query('INSERT INTO ' . $F . ' (project_id, generation_id, finding_identity, valid_from_seq,
            valid_to_seq, active_slot, record_hash, record_id_bin, host_form, field, rule_source_id,
            rule_revision, rule_ord, check_type, reason_code, z_score, growth_reference) VALUES '
        . $row(990, $o['gen'], $seq, 'v5-1', 'OLD-1', false, 'weight', '-3.40', 'who-wfa') . ', '
        . $row(990, $o['gen'], $seq, 'v5-2', 'OLD-2', false, 'height', null, null) . ', '
        . $row(990, $o['gen'], $seq, 'v5-3', 'OLD-1', true, 'weight', '-2.10', 'who-wfa') . ', '
        . $row(991, $on['gen'], uv_run_seq($dbA, $on['run']), 'v5-nb', 'NB-1', false, 'weight', null, null));
    $count = function () use ($dbA, $F) {
        $r = $dbA->select('SELECT COUNT(*) FROM ' . $F);
        return (int) $r[0][0];
    };
    check('v6: four findings stored at version 6, over two projects', $count() === 4);

    $r = Schema::migrate($ca);
    check('v6 to v7: the migration runs from 6 and applies version 7',
        $r['ok'] === true && $r['from'] === 6 && $r['to'] === Schema::VERSION
        && $r['applied'] === $statementsFrom(7));
    if ($r['ok'] !== true) fwrite(STDERR, '  migrate said: ' . (string) $r['why'] . "\n");
    $got = $definition();
    check('v6 to v7: the column is defined as a fresh install defines it (got ' . json_encode($got) . ')',
        $got === $WANT);
    $zero = $dbA->select('SELECT COUNT(*) FROM ' . $F . ' WHERE justified <> 0');
    check('v6 to v7: every row stored at version 6 is kept and reads 0',
        $count() === 4 && (int) $zero[0][0] === 0);
    $rc = $A->query("SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = '" . Schema::table('scan_run') . "' AND column_name = 'detail_justified'")->fetch_row();
    check('v6 to v7: the run table gains detail_justified, BIGINT UNSIGNED NOT NULL DEFAULT 0 (got ' . json_encode($rc) . ')',
        is_array($rc) && strtolower((string) $rc[0]) === 'bigint' && stripos((string) $rc[1], 'unsigned') !== false
        && (string) $rc[2] === 'NO' && (string) $rc[3] === '0');
    $runs0 = $dbA->select('SELECT COUNT(*), SUM(detail_justified) FROM ' . Schema::table('scan_run') . ' WHERE run_id IN (?, ?)', [$o['run'], $on['run']]);
    check('v6 to v7: and the two runs from version 6 read 0 there',
        (int) $runs0[0][0] === 2 && (int) $runs0[0][1] === 0);
    $vers = [];
    foreach ($dbA->select('SELECT version FROM ' . Schema::table('schema_version') . ' ORDER BY version') as $vr) {
        $vers[] = (int) $vr[0];
    }
    check('v6 to v7: the version rows end at the build version, 1 to 6 kept (got ' . json_encode($vers) . ')',
        $vers === range(1, Schema::VERSION));
    check('v6 to v7: and health() is ok', Schema::health($ca)['ok'] === true);

    $m = $mapper(990);
    $open5 = true;
    $page = $s5->findings(990, $o['gen'], [], 0, 100);
    foreach ($page as $p) $open5 = $open5 && (string) $p['justified'] === '0' && $m->toFinding($p)['justified'] === false;
    check('v6 to v7: the store reads the two active rows from version 6 back as open',
        count($page) === 2 && $open5);

    // The migrated table takes the store's version-7 INSERT, and the summary
    // counts the old rows as open beside the new justified one.
    $c = $s5->claim($o['run'], 'w', $o['epoch'], 1);
    $ok = is_array($c) ? $s5->commitBatch($o['run'], 'w', $o['epoch'], ['bytes' => 0,
        'records' => [$done($c[0])],
        'findings' => [$finding(990, $o['gen'], $c[0], 'reason_given', ['justified' => 1])]]) : 'no claim';
    $j = $dbA->select('SELECT justified FROM ' . $F . ' WHERE project_id = ? AND field = ?', [990, 'reason_given']);
    check('v6 to v7: a justified finding written after the migration is stored as 1',
        $ok === true && count($j) === 1 && (string) $j[0][0] === '1');
    check('v6 to v7: and the run counts it in detail_justified', (int) $s5->run(990, $o['run'])['detail_justified'] === 1);
    $roll = new RollupBuilder($dbA, $s5);
    while ($roll->step(990, $o['run'], $o['epoch'], $o['gen'], 100)['done'] === false) { }
    $standing = [];
    foreach ($s5->aggregates($o['run']) as $a) {
        if ($a['kind'] === RollupBuilder::K_STANDING) $standing[(string) $a['axis1']] = (int) $a['cnt'];
    }
    ksort($standing);
    check('v6 to v7: the summary counts the two old rows open and the new one justified (got '
        . json_encode($standing) . ')',
        $standing === [RollupBuilder::STANDING_JUSTIFIED => 1, RollupBuilder::STANDING_OPEN => 2]);

    $r2 = Schema::migrate($ca);
    check('v6 to v7: running it again changes nothing',
        $r2['ok'] === true && $r2['from'] === Schema::VERSION && $r2['applied'] === 0
        && $definition() === $WANT && $count() === 5);

    // HALF APPLIED: the ALTER took and the version row did not.
    $A->query('DELETE FROM ' . Schema::table('schema_version') . ' WHERE version >= 7');
    check('half applied: the column is there and the version row says 6',
        Schema::currentVersion($ca) === 6 && $definition() === $WANT);
    $r3 = Schema::migrate($ca);
    check('half applied: the migration skips the ALTER it would repeat and records the version',
        $r3['ok'] === true && $r3['from'] === 6 && $r3['applied'] === $statementsFrom(8)
        && Schema::currentVersion($ca) === Schema::VERSION);
    if ($r3['ok'] !== true) fwrite(STDERR, '  migrate said: ' . (string) $r3['why'] . "\n");
    $sum = $dbA->select('SELECT COUNT(*), SUM(justified) FROM ' . $F);
    check('half applied: the column and the rows are as they were',
        $definition() === $WANT && (int) $sum[0][0] === 5 && (int) $sum[0][1] === 1
        && Schema::health($ca)['ok'] === true);
}
