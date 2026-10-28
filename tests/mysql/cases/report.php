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

// -- THE CSV, STREAMED OVER THE REAL STORE --------------------------------------
//
// ScanService::streamFindings() is what pages/export.php runs. Over this
// server's store it must hold every row of this project in scope, none of the
// neighbour's (whose ids sit below this project's, so every page reads on from
// inside the neighbour's range), and for a group reader none of another group.
$csvOf = function (array $scope) use ($dbA, $PID, $mine) {
    $store = new SqlScanStore($dbA);
    $d = new \INSPIRE\UniversalValidator\ScanDimensions();
    $d->hasDags = true;
    $d->singleEventProved = true;
    $report = new \INSPIRE\UniversalValidator\Scan\ScanReport(new FindingRowMapper([
        'pid' => $PID, 'now' => gmdate('Y-m-d H:i:s'), 'valuesState' => 'none', 'readerCeiling' => 'raw',
        'identifiers' => [], 'recordIds' => FindingRowMapper::IDS_RAW, 'recordIdField' => 'record_id']),
        $d, $store->run($PID, $mine['run']));
    $text = '';
    $flushes = 0;
    $out = \INSPIRE\UniversalValidator\Scan\ScanService::streamFindings($store, $report, $PID, $mine['gen'], $scope,
        [['schema', 'uv-scan-report/1']], ['banner' => null, 'row' => null], function ($s) use (&$text) { $text .= $s; },
        function () use (&$flushes) { $flushes++; });
    $lines = explode("\r\n", rtrim(substr($text, 3), "\r\n"));
    $at = array_search('dag', str_getcsv($lines[1]), true);
    $groups = [];
    foreach (array_slice($lines, 2, -1) as $l) {
        $c = str_getcsv($l);
        $groups[$c[$at]] = isset($groups[$c[$at]]) ? $groups[$c[$at]] + 1 : 1;
    }
    return ['out' => $out, 'text' => $text, 'groups' => $groups, 'flushes' => $flushes, 'last' => end($lines)];
};
$whole = $csvOf(['dag' => null]);
check('report csv: the file holds every one of this project\'s 1200 findings and ends on the trailer',
    $whole['out']['ok'] === true && $whole['out']['rows'] === 1200
    && strpos($whole['last'], '"# export_complete=1","rows=1200"') === 0);
check('report csv: none of the neighbour\'s records is in it', strpos($whole['text'], '"N-') === false
    && substr_count($whole['text'], '"M-') === 1200);
check('report csv: twelve keyset pages, a flush after each', $whole['out']['pages'] === 12
    && $whole['flushes'] === 12 + 2);
$northCsv = $csvOf(['dag' => 'north']);
check('report csv group: a north reader\'s file holds north\'s rows and no other group\'s',
    array_keys($northCsv['groups']) === ['north']
    && $northCsv['out']['rows'] === $expect($mine['findings'], ['dag_key' => 'north'])
    && strpos($northCsv['text'], '"N-') === false);
check('report csv group (control): the whole file holds three groups', count($whole['groups']) === 3);

// -- THE LABEL SNAPSHOT, READ BY ITS KEY (M5) -----------------------------------
//
// The neighbour stores a larger snapshot under the same generation number. A page
// of mine walks uq_dim_v2 inside (project, generation) whether it starts at
// the beginning or after a (kind, key) cursor, sorts nothing, and holds none
// of the neighbour's rows; the point read the finishing pass makes for
// labelDegraded is one key lookup.
$dimRows = function ($tag, $n) {
    $rows = [['kind' => 'state', 'key' => 'snapshot', 'label' => null, 'meta' => '{"v":1,"degraded":{}}']];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = ['kind' => 'field', 'key' => sprintf('f%04d', $i), 'label' => $tag . ' ' . $i, 'meta' => null];
    }
    for ($i = 1; $i <= 40; $i++) {
        $rows[] = ['kind' => 'rule', 'key' => (string) $i, 'label' => $tag . ' rule ' . $i, 'meta' => '{}'];
    }
    return $rows;
};
$ds = new SqlScanStore($dbA);
check('report dims: both projects keep a snapshot under generation ' . $mine['gen'],
    $ds->writeDimensions($NB, $mine['gen'], $dimRows('theirs', 1500)) === 1541
    && $ds->writeDimensions($PID, $mine['gen'], $dimRows('mine', 1200)) === 1241);
$rec = new UvRecordingDb($dbA);
$rs = new SqlScanStore($rec);
$got = [];
$after = null;
for ($p = 0; $p < 10; $p++) {
    $pg = $rs->dimensions($PID, $mine['gen'], $after);
    if (!$pg) break;
    foreach ($pg as $r) $got[] = $r;
    $last = $pg[count($pg) - 1];
    $after = [$last['kind'], $last['key']];
}
$theirsSeen = 0;
foreach ($got as $r) if ($r['label'] !== null && strpos($r['label'], 'theirs') === 0) $theirsSeen++;
check('report dims: my pages read all of mine and none of the neighbour\'s (' . count($got) . ')',
    count($got) === 1241 && $theirsSeen === 0);
$crafted = $rs->dimensions($PID, $mine['gen'], ['field', 'f1199']);
$craftedTheirs = 0;
foreach ($crafted as $r) if ($r['label'] !== null && strpos($r['label'], 'theirs') === 0) $craftedTheirs++;
check('report dims: a cursor past my last field, inside the neighbour\'s, reads my rules and none of theirs',
    count($crafted) === 41 && $crafted[0]['kind'] === 'rule' && $crafted[40]['kind'] === 'state'
    && $craftedTheirs === 0);
$bad = [];
$sorts = [];
$dimSelects = 0;
foreach ($rec->log as $q) {
    if ($q[0] !== 'select' || strpos($q[1], 'FROM ' . Schema::table('scan_dim')) === false) continue;
    $dimSelects++;
    $keys = uv_explain_keys($A, $q[1], $q[2]);
    if ($keys !== ['uq_dim_v2']) $bad[] = implode(',', $keys) . ' <- ' . implode(' ', uv_explain_rows($A, $q[1], $q[2]));
    if (stripos($extra($q[1], $q[2]), 'filesort') !== false) $sorts[] = $q[1];
}
check('report dims: every page reads uq_dim_v2 (' . $dimSelects . ' pages)', $dimSelects >= 3 && $bad === []);
if ($bad) fwrite(STDERR, '  dims: ' . implode("\n    ", array_unique($bad)) . "\n");
check('report dims: and none of them sorts', $sorts === []);
$before = $readNext();
$pg = $ds->dimensions($PID, $mine['gen'], ['field', 'f0100'], 100);
$read = $readNext() - $before;
check('report dims: a page of 100 after a cursor reads no more than 101 entries (read ' . $read . ')',
    count($pg) === 100 && $read <= 101);
$rec2 = new UvRecordingDb($dbA);
$point = (new SqlScanStore($rec2))->dimension($PID, $mine['gen'], 'state', 'snapshot');
$pq = $rec2->log[0];
check('report dims: the state row is one lookup on uq_dim_v2',
    $point !== null && count($rec2->log) === 1 && uv_explain_keys($A, $pq[1], $pq[2]) === ['uq_dim_v2']);

// -- A REPORT ANOTHER RUN REPLACED, AND A FILE THAT EARNS ITS TRAILER ---------
//
// Reviews rv11a1-5 and rv11a2-1. A later whole-project run that completes
// closes the older run's rows a page at a time; retention removes a run a page
// at a time. Either one under way while the older run's file was written cut
// the walk short, and the file still ended on export_complete=1. With the run
// to read again, streamFindings() ends such a file as one that lost rows.
// supersededBy() reads the project's own runs, never a finding.
$finishWhole = \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(['fenced' => true, 'manifestDone' => true,
                                                                    'violations' => 1, 'ruleProblems' => 0]);
$streamOf = function ($pid, $run, callable $flush) use ($dbA) {
    $store = new SqlScanStore($dbA);
    $d = new \INSPIRE\UniversalValidator\ScanDimensions();
    $d->singleEventProved = true;
    $report = new \INSPIRE\UniversalValidator\Scan\ScanReport(new FindingRowMapper([
        'pid' => $pid, 'now' => gmdate('Y-m-d H:i:s'), 'valuesState' => 'none', 'readerCeiling' => 'raw',
        'identifiers' => [], 'recordIds' => FindingRowMapper::IDS_RAW, 'recordIdField' => 'record_id']),
        $d, $store->run($pid, $run['run']));
    $text = '';
    $out = \INSPIRE\UniversalValidator\Scan\ScanService::streamFindings($store, $report, $pid, $run['gen'],
        ['dag' => null], [['expected_rows', '1200']], ['banner' => null, 'row' => null],
        function ($s) use (&$text) { $text .= $s; }, $flush, ['run' => $run['run'], 'expected' => 1200]);
    $lines = explode("\r\n", rtrim(substr($text, 3), "\r\n"));
    return ['out' => $out, 'last' => end($lines),
            'lost' => strpos($text, 'export_complete') === false
                      && strpos(end($lines), '"# ' . \INSPIRE\UniversalValidator\Scan\ScanService::ROWS_LOST . '"') === 0];
};
$SUP = 884;
$old = $plant($SUP, 1200, 'OLD');
(new SqlScanStore($dbA))->finish($old['run'], $finishWhole);
$whole0 = $streamOf($SUP, $old, function () {});
check('report supersede (control): the older run read whole earns the trailer',
    $whole0['out']['ok'] === true && $whole0['out']['rows'] === 1200
    && strpos($whole0['last'], '"# export_complete=1","rows=1200"') === 0);
$new = $plant($SUP, 3, 'NEW');
check('report supersede: nothing replaces a run while the later one is open',
    (new SqlScanStore($dbA))->supersededBy($SUP, $old['run']) === null);
(new SqlScanStore($dbA))->finish($new['run'], $finishWhole);
$rec = new UvRecordingDb($dbA);
check('report supersede: once the later run completes, it is the run that replaced the older',
    (new SqlScanStore($rec))->supersededBy($SUP, $old['run']) === $new['run']
    && (new SqlScanStore($dbA))->supersededBy($SUP, $new['run']) === null
    && (new SqlScanStore($dbA))->supersededBy($SUP + 1, $old['run']) === null);
$sq = $rec->log[0];
$supKeys = uv_explain_keys($A, $sq[1], $sq[2]);
check('report supersede: the question reads the run by its key and the project\'s runs by theirs ('
    . implode(',', $supKeys) . ')',
    count($rec->log) === 1 && in_array('PRIMARY', $supKeys, true) && count($supKeys) === 2
    && count(array_intersect($supKeys, ['ix_project_created', 'uq_project_active'])) === 1);
$closed = 0;
$f = 0;
$mid = $streamOf($SUP, $old, function () use (&$f, &$closed, $dbB, $SUP, $new) {
    if (++$f !== 2) return;
    $s = new SqlScanStore($dbB);
    do {
        $p = $s->supersedePrevious($SUP, $new['run'], ScanStore::PAGE);
        $closed += $p['rows'];
    } while (!$p['done']);
});
check('report supersede: a file whose rows were closed after its first page ends as lost, not whole ('
    . $mid['out']['rows'] . ' rows, ' . $closed . ' closed)',
    $closed === 1200 && $mid['out']['rows'] === 100 && $mid['out']['ok'] === false && $mid['lost']);

$PUR = 886;
$gone = $plant($PUR, 1200, 'PUR');
(new SqlScanStore($dbA))->finish($gone['run'], $finishWhole);
$dbA->execNoCount('UPDATE ' . Schema::table('scan_run') . " SET updated_at = '2020-01-01 00:00:00' WHERE run_id = ?",
                  [$gone['run']]);
$f = 0;
$purged = 0;
$midP = $streamOf($PUR, $gone, function () use (&$f, &$purged, $dbB, $PUR) {
    if (++$f !== 2) return;
    $p = (new \INSPIRE\UniversalValidator\Scan\ScanRetention($dbB))->purgeRuns($PUR, 90, 500);
    $purged += $p['rows'];
});
check('report purge: a file whose run retention began removing after its first page ends as lost ('
    . $midP['out']['rows'] . ' rows, ' . $purged . ' removed)',
    $purged > 0 && (new SqlScanStore($dbA))->run($PUR, $gone['run']) === null
    && $midP['out']['ok'] === false && $midP['lost']);

// -- THE RUN THE FINDINGS PANEL OPENS ON (B5) -----------------------------------
//
// ScanService::finishedRun(): the newest finished run of the reader's own
// scope, one row through ix_project_created, newest first with no sort. Runs
// of the neighbour, unfinished runs and runs of another scope are never it.
if (!class_exists('UvReportModule', false)) {
    /** The framework edge for ScanService over the real connection, keeping each statement. */
    class UvReportModule
    {
        private $c;
        /** @var array[] [sql, params] per statement */
        public $log = [];
        public function __construct($c) { $this->c = $c; }
        public function query($sql, $params = [])
        {
            $this->log[] = [$sql, $params];
            if (!$params) return $this->c->query($sql);
            $st = $this->c->prepare($sql);
            bindAll($st, array_values($params));
            $st->execute();
            $r = $st->get_result();
            return $r === false ? true : $r;
        }
    }
}
$runT = Schema::table('scan_run');
$endRun = function ($rid) use ($A, $runT) {
    $A->query("UPDATE $runT SET phase = 'terminal', terminal = 'complete', active_slot = NULL WHERE run_id = "
        . (int) $rid);
};
$fs = new SqlScanStore($dbA);
// The planted runs hold each project's slot; end them, then add, in order:
// a whole-project run, a newer north run, and a whole-project run left going.
$endRun($mine['run']);
$endRun($theirs['run']);
$newest = (int) $fs->startRun($PID, ['created_by' => 'alice'])['run']['run_id'];
$endRun($newest);
$north = (int) $fs->startRun($PID, ['created_by' => 'alice', 'scope_dag' => '7'])['run']['run_id'];
$endRun($north);
$going =(int) $fs->startRun($PID, ['created_by' => 'alice'])['run']['run_id'];
$neighbourNewer = (int) $fs->startRun($NB, ['created_by' => 'bob'])['run']['run_id'];
$endRun($neighbourNewer);
$fm = new UvReportModule($A);
$fsvc = new INSPIRE\UniversalValidator\Scan\ScanService($fm);
$whole = $fsvc->finishedRun($PID, ['ok' => true, 'dag' => null]);
$inNorth = $fsvc->finishedRun($PID, ['ok' => true, 'dag' => '7']);
$inSouth = $fsvc->finishedRun($PID, ['ok' => true, 'dag' => '9']);
check('report panel: the newest finished whole-project run is offered, not a newer group run, the one still going nor the neighbour\'s',
    $north > $newest && $going > $north && $neighbourNewer > $newest
    && $whole === ['run_id' => $newest, 'newer' => null, 'replaced' => false]);
check('report panel: a group reader is offered their group\'s run, and a group with none is offered nothing',
    $inNorth === ['run_id' => $north, 'newer' => null, 'replaced' => false]
    && $inSouth === ['run_id' => null, 'newer' => null, 'replaced' => false]);
check('report panel: and a scope that cannot be read is offered nothing, without a statement',
    count($fm->log) === 3 && $fsvc->finishedRun($PID, ['ok' => false, 'dag' => null]) === null && count($fm->log) === 3);

// WHICH REPORT IS CURRENT (reviews rv11b1-1, rv11b2-1, rv11b2-2, rv11b2-5),
// on a project of its own, in order: a whole-project run that completes,
// one stopped after it, a group run that completes, then a whole-project
// run that completes and supersedes the generations below it.
$PNL = 887;
$endAs = function ($rid, $terminal, $reason = null) use ($A, $runT) {
    $A->query("UPDATE $runT SET phase = 'terminal', terminal = '" . $A->real_escape_string($terminal)
        . "', terminal_reason = " . ($reason === null ? 'NULL' : "'" . $A->real_escape_string($reason) . "'")
        . ', active_slot = NULL WHERE run_id = ' . (int) $rid);
};
$startIn = function ($pid, $dag = null) use ($fs) {
    $o = ['created_by' => 'alice'];
    if ($dag !== null) $o['scope_dag'] = $dag;
    return (int) $fs->startRun($pid, $o)['run']['run_id'];
};
$w1 = $startIn($PNL);
$endAs($w1, 'complete');
$w2 = $startIn($PNL);
$endAs($w2, 'cancelled');
// Every id offered is asked of the store at once: no run it calls gone or
// replaced (the questions every verb and reportOf() ask) may be offered.
$offered = 0;
$disagree = [];
$offer = function ($dag) use ($fsvc, $fs, $PNL, &$offered, &$disagree) {
    $o = $fsvc->finishedRun($PNL, ['ok' => true, 'dag' => $dag]);
    foreach (is_array($o) ? ['run_id', 'newer'] : [] as $k) {
        if ($o[$k] === null) continue;
        $offered++;
        if ($fs->run($PNL, $o[$k]) === null || $fs->supersededBy($PNL, $o[$k]) !== null) $disagree[] = $o[$k];
    }
    return $o;
};
check('rv11b2-5 report panel: a run stopped after the complete one leaves that one current, and is named as newer',
    $offer(null) === ['run_id' => $w1, 'newer' => $w2, 'replaced' => false]);
$g1 = $startIn($PNL, '7');
$endAs($g1, 'complete');
check('rv11b1-1 report panel: a group run after the last whole-project run is its group\'s current report',
    $offer('7') === ['run_id' => $g1, 'newer' => null, 'replaced' => false]);
$w3 = $startIn($PNL);
$endAs($w3, 'complete');
check('rv11b1-1 report panel: once a later whole-project run completes, the group is told its report was replaced',
    $offer('7') === ['run_id' => null, 'newer' => null, 'replaced' => true]);
check('rv11b1-1 report panel: and the whole-project reader is offered that run, with nothing newer',
    $offer(null) === ['run_id' => $w3, 'newer' => null, 'replaced' => false]);
$g2 = $startIn($PNL, '7');
$endAs($g2, 'expired');
check('rv11b1-1 report panel: a group run after it is offered again, though it did not complete',
    $offer('7') === ['run_id' => $g2, 'newer' => null, 'replaced' => false]);
$endAs($w3, 'complete', ScanStore::REMOVING_WHY);
check('rv11b2-2 report panel: a run retention is removing is never offered, and what it superseded stays superseded',
    $offer(null) === ['run_id' => null, 'newer' => null, 'replaced' => false]);
check('report panel: no run it offered is one the store calls gone or replaced (' . $offered . ' offers)',
    $offered >= 5 && $disagree === []);

$bad = [];
$sorts = [];
$three = ['ix_project_created', 'ix_project_created', 'ix_project_created'];
foreach ($fm->log as $q) {
    $keys = uv_explain_keys($A, $q[0], $q[1]);
    if ($keys !== $three) $bad[] = implode(',', $keys) . ' <- ' . implode(' ', uv_explain_rows($A, $q[0], $q[1]));
    if (stripos($extra($q[0], $q[1]), 'filesort') !== false) $sorts[] = $q[0];
}
check('report panel: each branch of each read names ix_project_created (' . count($fm->log) . ' reads)', $bad === []);
if ($bad) fwrite(STDERR, '  panel: ' . implode("\n    ", array_unique($bad)) . "\n");
check('report panel: and none of them sorts', $sorts === []);
