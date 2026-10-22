<?php
/**
 * tests/mysql/cases/report.php — the findings read the report pages through,
 * on a real server (wave 11, B5).
 *
 * Three things only a server can show:
 *
 *   - THE PLAN. Every page names its key: ix_page_v2 unfiltered, the axis's
 *     ix_filter_* for one axis, and the first axis's key, walked in bounded
 *     windows, for two. Each is EXPLAINed as sent, with no sort, and the rows
 *     a page reads are counted on the connection that read them.
 *   - THE PROJECT. Two projects share generation 1, which is what every real
 *     installation has. No page of one project, however its cursor or filter
 *     is written, holds a row of the other.
 *   - THE GROUP. A reader confined to a group gets the group's rows and no
 *     other, whatever filter or cursor they send.
 */

use INSPIRE\UniversalValidator\Scan\Schema;
use INSPIRE\UniversalValidator\Scan\SqlScanStore;
use INSPIRE\UniversalValidator\Scan\ScanStore;
use INSPIRE\UniversalValidator\Scan\FindingRowMapper;

$PID = 880;
$NB = uv_neighbour($PID);

/**
 * One run of $pid with $n findings over three forms, two reasons, two check
 * types and two groups plus none, every axis value a fixed function of the
 * finding's number so a test can count what a filter must return.
 */
$plant = function ($pid, $n, $prefix) use ($dbA) {
    $store = new SqlScanStore($dbA);
    $r = $store->startRun($pid, ['created_by' => 'alice']);
    $rid = (int) $r['run']['run_id'];
    $gen = (int) $r['run']['generation_id'];
    $recs = [];
    $per = 50;
    $records = (int) ceil($n / $per);
    for ($i = 0; $i < $records; $i++) {
        $recs[] = ['id_bin' => $prefix . '-' . $i, 'hash' => hash('sha256', $prefix . '-' . $pid . '-' . $i, true),
                   'dag' => null];
    }
    $store->writeManifest($rid, $recs);
    $ep = (int) $store->run($pid, $rid)['lease_epoch'];
    $claims = $store->claim($rid, 'w', $ep, $records);
    $findings = [];
    $records2 = [];
    $k = 0;
    foreach ($claims as $c) {
        $records2[] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                       'state' => ScanStore::REC_DONE, 'claim' => $c['claim']];
        for ($j = 0; $j < $per && $k < $n; $j++, $k++) {
            $findings[] = ['ordinal' => $c['ordinal'], 'project_id' => $pid, 'generation_id' => $gen,
                'identity' => hash('sha256', $prefix . '|' . $k, true),
                'record_hash' => $c['hash'], 'record_id_bin' => $c['id_bin'],
                'host_form' => ['fa', 'fb', 'fc'][$k % 3], 'field' => 'f' . $k,
                'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64),
                'check_type' => ['required', 'range'][$k % 2],
                'reason_code' => ['blank', 'high'][($k >> 1) % 2],
                'dag_key' => [null, 'north', 'south'][intdiv($k, 3) % 3]];
        }
    }
    $ok = $store->commitBatch($rid, 'w', $ep, ['bytes' => 0, 'records' => $records2, 'findings' => $findings]);
    return ['run' => $rid, 'gen' => $gen, 'ok' => $ok, 'findings' => $findings];
};

// The neighbour first, so its finding ids sit below this project's: a cursor
// written into the neighbour's range is then a cursor this project's pages
// read on from, which is the crafted cursor worth trying.
$theirs = $plant($NB, 600, 'N');
$mine = $plant($PID, 1200, 'M');
check('report: both projects\' findings commit', $mine['ok'] === true && $theirs['ok'] === true);
check('report: and share generation 1, as every installation does',
    $mine['gen'] === $theirs['gen'] && $mine['gen'] === 1);

/** What a filter must return: the planted findings of $pid that match every axis, counted. */
$expect = function (array $planted, array $axes) {
    $n = 0;
    foreach ($planted as $f) {
        foreach ($axes as $col => $v) {
            if ((string) $f[$col] !== (string) $v || $f[$col] === null) continue 2;
        }
        $n++;
    }
    return $n;
};

/** Every page of a read, walked through $through, with every statement it sent. */
$walk = function ($pid, $gen, array $scope, array $filter, $after = 0, $limit = 100) use ($dbA) {
    $rec = new UvRecordingDb($dbA);
    $store = new SqlScanStore($rec);
    $rows = [];
    $pages = 0;
    $through = $after;
    do {
        $page = $store->findings($pid, $gen, $scope, $filter, $through, $limit, $through);
        foreach ($page as $r) $rows[] = $r;
        $pages++;
    } while ($through !== null && $pages < 1000);
    return ['rows' => $rows, 'pages' => $pages, 'log' => $rec->log];
};

/** The Extra column of every EXPLAIN row, joined. */
$extra = function ($sql, array $params) use ($A) {
    $r = $A->query('EXPLAIN ' . uv_inline_params($A, $sql, $params));
    $out = [];
    while ($row = $r->fetch_assoc()) $out[] = isset($row['Extra']) ? (string) $row['Extra'] : '';
    $r->free();
    return implode(' | ', $out);
};

// -- THE PLAN, PER FILTER ------------------------------------------------------
//
// [label, scope, filter, the key every statement must name]
$cases = [
    ['unfiltered',              ['dag' => null], [],                                        'ix_page_v2'],
    ['by instrument',           ['dag' => null], ['host_form' => 'fb'],                     'ix_filter_form_v2'],
    ['by reason',               ['dag' => null], ['reason_code' => 'high'],                 'ix_filter_reason_v2'],
    ['by check type',           ['dag' => null], ['check_type' => 'range'],                 'ix_filter_type'],
    ['by group',                ['dag' => null], ['dag_key' => 'south'],                    'ix_filter_dag_v2'],
    ['a group reader',          ['dag' => 'north'], [],                                     'ix_filter_dag_v2'],
    ['a group reader by form',  ['dag' => 'north'], ['host_form' => 'fc'],                  'ix_filter_form_v2'],
    ['two filters',             ['dag' => null], ['reason_code' => 'blank', 'check_type' => 'required'],
                                                                                            'ix_filter_reason_v2'],
];
foreach ($cases as $c) {
    list($what, $scope, $filter, $key) = $c;
    $w = $walk($PID, $mine['gen'], $scope, $filter);
    $axes = FindingRowMapper::pageAxes($scope, $filter)['axes'];
    $want = $expect($mine['findings'], $axes);
    check('report plan ' . $what . ': every matching row, each once (' . count($w['rows']) . ' of ' . $want . ')',
        count($w['rows']) === $want
        && count(array_unique(array_map(function ($r) { return (int) $r['finding_id']; }, $w['rows']))) === $want);
    $bad = [];
    $sorts = [];
    foreach ($w['log'] as $q) {
        if ($q[0] !== 'select' || strpos($q[1], 'FROM ' . Schema::table('finding')) === false) continue;
        $keys = uv_explain_keys($A, $q[1], $q[2]);
        if ($keys !== [$key]) $bad[] = implode(',', $keys) . ' <- ' . implode(' ', uv_explain_rows($A, $q[1], $q[2]));
        if (stripos($extra($q[1], $q[2]), 'filesort') !== false) $sorts[] = $q[1];
    }
    check('report plan ' . $what . ': every statement reads ' . $key, $bad === []);
    if ($bad) fwrite(STDERR, '  ' . $what . ': ' . implode("\n    ", array_unique($bad)) . "\n");
    check('report plan ' . $what . ': and none of them sorts', $sorts === []);
}

// -- THE ROWS ONE PAGE READS ---------------------------------------------------
//
// Asked of the connection that read them, around one page. A page over one
// axis reads its limit and one more; a page over two reads at most a window
// of the first axis's key and the rows inside it.
$readNext = function () use ($A) {
    $r = $A->query("SHOW SESSION STATUS LIKE 'Handler_read_next'");
    $row = $r->fetch_row();
    $r->free();
    return (int) $row[1];
};
$s = new SqlScanStore($dbA);
foreach ([['unfiltered', ['dag' => null], []], ['by instrument', ['dag' => null], ['host_form' => 'fa']],
          ['a group reader', ['dag' => 'south'], []]] as $c) {
    $before = $readNext();
    $page = $s->findings($PID, $mine['gen'], $c[1], $c[2], 0, 20);
    $read = $readNext() - $before;
    check('report reads ' . $c[0] . ': one page of 20 reads no more than 21 entries (read ' . $read . ')',
        count($page) === 20 && $read <= 21);
}
$before = $readNext();
$page = $s->findings($PID, $mine['gen'], ['dag' => 'north'], ['host_form' => 'fc'], 0, 20);
$read = $readNext() - $before;
check('report reads two axes: bounded by the window and the rows in it (read ' . $read . ')',
    count($page) === 20 && $read <= 2 * ScanStore::FILTER_WINDOW + 21);

// -- ONE PROJECT'S FINDINGS NEVER APPEAR IN ANOTHER'S PAGE -----------------------
$theirIds = [];
$all = $walk($NB, $theirs['gen'], ['dag' => null], []);
foreach ($all['rows'] as $r) $theirIds[(int) $r['finding_id']] = true;
check('report leak: the neighbour reads its own 600', count($theirIds) === 600);
$leaks = 0;
$seen = 0;
$probes = [
    ['from the start', ['dag' => null], [], 0],
    // A cursor inside the neighbour's range, one below its first row.
    ['a cursor on the neighbour\'s rows', ['dag' => null], [], min(array_keys($theirIds)) - 1],
    ['a filter on the neighbour\'s group', ['dag' => null], ['dag_key' => 'north'], 0],
    ['a group reader with a crafted cursor', ['dag' => 'south'], [], min(array_keys($theirIds)) - 1],
    ['two filters from the neighbour\'s range', ['dag' => null], ['host_form' => 'fa', 'reason_code' => 'high'],
     min(array_keys($theirIds)) - 1],
];
foreach ($probes as $p) {
    $w = $walk($PID, $mine['gen'], $p[1], $p[2], $p[3]);
    $n = 0;
    foreach ($w['rows'] as $r) {
        $seen++;
        if ((int) $r['project_id'] !== $PID || isset($theirIds[(int) $r['finding_id']])) $n++;
    }
    $leaks += $n;
    check('report leak: ' . $p[0] . ' holds none of the neighbour\'s rows', $n === 0 && $w['rows'] !== []);
}
check('report leak: the probes read rows at all, so the checks above saw something', $seen > 0 && $leaks === 0);

// -- A GROUP READER SEES THE GROUP'S FINDINGS AND NO OTHER ----------------------
foreach ([['no filter', []], ['a filter naming the other group', ['dag_key' => 'south']],
          ['a filter naming no group', ['dag_key' => '']], ['a form filter', ['host_form' => 'fb']]] as $c) {
    $w = $walk($PID, $mine['gen'], ['dag' => 'north'], $c[1]);
    $other = 0;
    foreach ($w['rows'] as $r) if ($r['dag_key'] !== 'north') $other++;
    check('report group: ' . $c[0] . ' shows a north reader no row outside north', $other === 0);
}
$north = $walk($PID, $mine['gen'], ['dag' => 'north'], []);
check('report group: and every north row', count($north['rows']) === $expect($mine['findings'], ['dag_key' => 'north']));
