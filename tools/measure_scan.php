<?php
/**
 * measure_scan.php — a THROWAWAY measurement page. Delete it after use.
 *
 * The scan rebuild plan (reports/scan-rebuild-plan-2026-08-17.md) refuses to
 * draft a schema on an extrapolated number. This page takes the measurements
 * that decide the next three releases, on a real project, in one run.
 *
 * It is deliberately NOT part of the module:
 *   - it changes no shipped file, so nothing has to be reverted afterwards;
 *   - it is READ-ONLY. It issues no INSERT, UPDATE, DELETE or DDL of any kind,
 *     and the one grant question it answers it answers with SHOW GRANTS;
 *   - it is not declared in config.json, so it appears in no menu.
 *
 * WHAT IT ANSWERS (numbering follows the plan's "Measure before coding" list)
 *   #2  peak memory and wall time of the whole-project record-id read
 *   #3  THE GATE — does REDCap's getData dominate, or does our loop?
 *   #4  the untouched-form ratio: what <form>_complete actually holds
 *   #5  contexts per record
 *   #6  per-batch fixed cost (dictionary + rule rebuild), which sets batch size
 *   #10 findings per record — the number the whole storage design rests on
 *   #1  whether the REDCap DB user could create tables (SHOW GRANTS only)
 *
 * INSTALL
 *   1. Copy this file to the module directory on the server, as
 *        <redcap>/modules/universal_validator_v1.6.3/tools/measure_scan.php
 *   2. Visit, as a user with DESIGN RIGHTS on the project:
 *        /redcap_vXX/ExternalModules/index.php
 *          ?prefix=universal_validator&page=tools/measure_scan&pid=135
 *   3. Copy the JSON block at the bottom and send it back.
 *   4. DELETE THE FILE.
 *
 * START SMALL. The first run should be:            &limit=200
 * then                                             &limit=1000
 * and only then the whole project with no limit. Each run prints how long it
 * took, so you can extrapolate before committing to the full sweep.
 *
 * OPTIONAL FLAGS
 *   &limit=N     scan only the first N records (default: all)
 *   &grants=1    also run SHOW GRANTS (read-only) for measurement #1
 *   &pkfallback=1  measure the :2160 landmine — the full-readSet export that
 *                  happens when getRecordIdField() is unavailable. This one CAN
 *                  exhaust memory on a large project, which is the point; it is
 *                  off by default and should be run LAST, with a limit.
 */

namespace INSPIRE\UniversalValidator;

/** @var UniversalValidator $module */

$T0 = microtime(true);
$pid = $module->getProjectId();
if (!$pid) { echo 'This page only works inside a project.'; return; }

// -- rights: the same gate the scan page applies -----------------------------
try {
    $user = $module->getUser();
    if (!$user || !is_callable([$user, 'hasDesignRights']) || !$user->hasDesignRights()) {
        echo '<div style="margin:20px;padding:10px;color:#c62828">Design rights are required.</div>';
        return;
    }
} catch (\Throwable $e) {
    echo '<div style="margin:20px;padding:10px;color:#c62828">Could not verify rights.</div>';
    return;
}

@set_time_limit(0);
$limit      = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 0;
$doGrants   = isset($_GET['grants']) && $_GET['grants'] === '1';
$doFallback = isset($_GET['pkfallback']) && $_GET['pkfallback'] === '1';

$R = ['meta' => [], 'shape' => [], 'gate' => [], 'complete_status' => [], 'notes' => []];
$R['meta']['measured_at']  = date('c');
$R['meta']['project_id']   = (int) $pid;
$R['meta']['php_version']  = PHP_VERSION;
$R['meta']['memory_limit'] = ini_get('memory_limit');
$R['meta']['max_execution_time'] = ini_get('max_execution_time');
$R['meta']['record_limit'] = $limit ?: 'none (whole project)';

/** Wall-clock + peak-memory around one callable. Never lets a probe kill the page. */
function uv_time($label, callable $fn, array &$R)
{
    $m0 = memory_get_usage(true);
    $p0 = memory_get_peak_usage(true);
    $t0 = microtime(true);
    $out = null; $err = null;
    try { $out = $fn(); } catch (\Throwable $e) { $err = get_class($e) . ': ' . $e->getMessage(); }
    $ms = (microtime(true) - $t0) * 1000;
    $rec = ['ms' => round($ms, 1),
            'mem_delta_mb'  => round((memory_get_usage(true) - $m0) / 1048576, 1),
            'peak_after_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'peak_rose_mb'  => round((memory_get_peak_usage(true) - $p0) / 1048576, 1)];
    if ($err !== null) $rec['error'] = $err;
    $R['timings'][$label] = $rec;
    return $out;
}

// ---------------------------------------------------------------------------
// Project shape
// ---------------------------------------------------------------------------
$dd = [];
try { $dd = \REDCap::getDataDictionary($pid, 'array'); } catch (\Throwable $e) {}
$forms = [];
foreach ((array) $dd as $f => $meta) {
    if (!empty($meta['form_name'])) $forms[$meta['form_name']] = true;
}
$forms = array_keys($forms);
$R['shape']['fields']      = count($dd);
$R['shape']['instruments'] = count($forms);

$pk = null;
try { if (is_callable(['\REDCap', 'getRecordIdField'])) $pk = \REDCap::getRecordIdField(); } catch (\Throwable $e) {}
$R['shape']['record_id_field'] = $pk ?: '(UNAVAILABLE — the :2160 fallback would fire)';

// ---------------------------------------------------------------------------
// #2  the whole-project record-id read
// ---------------------------------------------------------------------------
$ids = uv_time('id_read', function () use ($pid, $pk) {
    $d = \REDCap::getData(['project_id' => $pid, 'return_format' => 'array',
        'fields' => $pk ? [$pk] : [], 'exportDataAccessGroups' => true]);
    return is_array($d) ? array_keys($d) : [];
}, $R);
$ids = is_array($ids) ? $ids : [];
$R['shape']['records_in_project'] = count($ids);
if ($limit) $ids = array_slice($ids, 0, $limit);
$R['shape']['records_measured'] = count($ids);
if (!$ids) { echo '<pre>No records found — nothing to measure.</pre>'; return; }

// ---------------------------------------------------------------------------
// #3  THE GATE.  T_loop = T_total - T_reads.
//
// The engine's read set is private, so the read cost is BRACKETED instead of
// guessed: a lower bound (one field) and an upper bound (every field in the
// dictionary). The real read set sits between them. If even the UPPER bound is
// small next to the whole scan, the loop dominates and the Tier 1 hoists are
// worth doing. If the LOWER bound already dominates, they are noise. Only an
// answer that lands between the two brackets is inconclusive.
// ---------------------------------------------------------------------------
$CHUNK = 200;
$chunks = array_chunk($ids, $CHUNK);
$R['gate']['chunk_size'] = $CHUNK;
$R['gate']['chunk_count'] = count($chunks);

uv_time('reads_lower_bound_1_field', function () use ($chunks, $pid, $pk) {
    foreach ($chunks as $c) {
        \REDCap::getData(['project_id' => $pid, 'return_format' => 'array',
            'records' => $c, 'fields' => $pk ? [$pk] : [], 'exportDataAccessGroups' => true]);
    }
}, $R);

uv_time('reads_upper_bound_all_fields', function () use ($chunks, $pid, $dd) {
    $all = array_keys((array) $dd);
    foreach ($chunks as $c) {
        \REDCap::getData(['project_id' => $pid, 'return_format' => 'array',
            'records' => $c, 'fields' => $all, 'exportDataAccessGroups' => true]);
    }
}, $R);

// The scan itself. DAG filter deliberately null: we are measuring cost, and a
// confined scan would measure a subset.
$res = uv_time('scanProject_total', function () use ($module, $pid, $limit) {
    return $module->scanProject($pid);
}, $R);

if (is_array($res)) {
    $n = count($res['violations']);
    $R['gate']['status']          = $res['status'];
    $R['gate']['records_scanned'] = (int) $res['stats']['records'];
    $R['gate']['contexts']        = (int) $res['stats']['contexts'];
    $R['gate']['live_rules']      = (int) $res['stats']['rules'];
    $R['gate']['unconfigurable']  = count($res['unconfigurable']);
    $R['gate']['incomplete_notes'] = count($res['incomplete']);
    $R['gate']['findings']        = $n;

    // #10 and #5 — the two numbers the storage design rests on.
    $recs = max(1, (int) $res['stats']['records']);
    $R['gate']['FINDINGS_PER_RECORD'] = round($n / $recs, 2);
    $R['gate']['CONTEXTS_PER_RECORD'] = round(((int) $res['stats']['contexts']) / $recs, 2);

    $by = [];
    foreach ($res['violations'] as $v) {
        $k = $v['type'] . '/' . $v['reason'];
        if (strpos($k, 'assert:') !== false) $k = $v['type'] . '/assert';
        $by[$k] = (isset($by[$k]) ? $by[$k] : 0) + 1;
    }
    arsort($by);
    $R['gate']['findings_by_type'] = array_slice($by, 0, 15, true);
}

$tot = isset($R['timings']['scanProject_total']['ms']) ? $R['timings']['scanProject_total']['ms'] : 0;
$lo  = isset($R['timings']['reads_lower_bound_1_field']['ms']) ? $R['timings']['reads_lower_bound_1_field']['ms'] : 0;
$hi  = isset($R['timings']['reads_upper_bound_all_fields']['ms']) ? $R['timings']['reads_upper_bound_all_fields']['ms'] : 0;
$R['gate']['loop_ms_if_reads_are_cheap'] = round($tot - $lo, 1);
$R['gate']['loop_ms_if_reads_are_dear']  = round($tot - $hi, 1);
$R['gate']['reads_share_lower_pct'] = $tot > 0 ? round(100 * $lo / $tot, 1) : null;
$R['gate']['reads_share_upper_pct'] = $tot > 0 ? round(100 * $hi / $tot, 1) : null;
$R['gate']['VERDICT'] =
    ($tot <= 0) ? 'no timing'
    : (($hi / $tot) > 0.6 ? 'READS DOMINATE — skip the Tier 1 hoists (1.6.4), go to 1.6.5'
    : (($lo / $tot) < 0.25 ? 'LOOP DOMINATES — the Tier 1 hoists are worth doing'
    : 'INCONCLUSIVE — the true read set sits between the brackets; report both'));

// ---------------------------------------------------------------------------
// #6  per-batch fixed cost: a fresh scan of ONE record is almost entirely
//     setup (dictionary + rules + event mappings). That is what every AJAX
//     batch would repay, and it is what sets the batch size.
// ---------------------------------------------------------------------------
uv_time('one_record_scan_setup_dominated', function () use ($module, $pid) {
    return $module->scanProject($pid, '__uv_measure_no_such_dag__');
}, $R);
$R['gate']['fixed_setup_ms_approx'] = isset($R['timings']['one_record_scan_setup_dominated']['ms'])
    ? $R['timings']['one_record_scan_setup_dominated']['ms'] : null;

// ---------------------------------------------------------------------------
// #4  THE UNTOUCHED-FORM RATIO — the highest-risk assumption in the plan.
//
// Nothing in the module has ever read <form>_complete. The gate that removes
// ~95% of the findings assumes it is ABSENT for an instrument nobody opened and
// PRESENT for one that was saved, even blank. If REDCap writes a row for every
// designated instrument regardless, the gate delivers nothing.
// ---------------------------------------------------------------------------
$cf = [];
foreach ($forms as $f) $cf[] = $f . '_complete';
$buckets = ['absent' => 0, 'blank' => 0, 'zero' => 0, 'one' => 0, 'two' => 0, 'other' => 0];
$perForm = [];
uv_time('complete_status_read', function () use ($chunks, $pid, $cf, &$buckets, &$perForm, $forms) {
    foreach ($chunks as $c) {
        $d = \REDCap::getData(['project_id' => $pid, 'return_format' => 'array',
            'records' => $c, 'fields' => $cf]);
        if (!is_array($d)) continue;
        foreach ($d as $rec => $node) {
            foreach ((array) $node as $evt => $row) {
                if ($evt === 'repeat_instances' || !is_array($row)) continue;
                foreach ($forms as $f) {
                    $k = $f . '_complete';
                    if (!isset($perForm[$f])) $perForm[$f] = ['absent' => 0, 'present' => 0];
                    if (!array_key_exists($k, $row)) { $buckets['absent']++; $perForm[$f]['absent']++; continue; }
                    $perForm[$f]['present']++;
                    $v = (string) $row[$k];
                    if ($v === '')       $buckets['blank']++;
                    elseif ($v === '0')  $buckets['zero']++;
                    elseif ($v === '1')  $buckets['one']++;
                    elseif ($v === '2')  $buckets['two']++;
                    else                 $buckets['other']++;
                }
            }
        }
    }
}, $R);
$R['complete_status']['buckets']  = $buckets;
$R['complete_status']['per_form'] = $perForm;
$seen = array_sum($buckets);
$R['complete_status']['record_form_pairs_seen'] = $seen;
$R['complete_status']['absent_pct'] = $seen ? round(100 * $buckets['absent'] / $seen, 1) : null;
$R['complete_status']['VERDICT'] =
    ($seen === 0) ? 'no data'
    : ($buckets['absent'] === 0
        ? 'GATE IS DEAD — _complete is present for every record/form pair, so "untouched" cannot be detected this way. DROP the gate.'
        : ($buckets['absent'] / $seen > 0.8
            ? 'GATE WORKS — most record/form pairs have no _complete row at all'
            : 'GATE IS WEAK — measure the finding reduction directly before relying on it'));

// ---------------------------------------------------------------------------
// #1  could the DB user create tables?  (read-only: SHOW GRANTS)
// ---------------------------------------------------------------------------
if ($doGrants) {
    try {
        $q = $module->query('SHOW GRANTS FOR CURRENT_USER()', []);
        $g = [];
        while ($row = $q->fetch_row()) $g[] = $row[0];
        $R['grants']['raw'] = $g;
        $all = strtoupper(implode(' | ', $g));
        $R['grants']['create_table_likely'] =
            (strpos($all, 'ALL PRIVILEGES') !== false || strpos($all, 'CREATE') !== false) ? 'yes' : 'NO — the persistence design must change';
    } catch (\Throwable $e) {
        $R['grants']['error'] = get_class($e) . ': ' . $e->getMessage();
        $R['notes'][] = 'SHOW GRANTS failed; $module->query() may be unavailable on this framework version.';
    }
} else {
    $R['notes'][] = 'Grants not checked. Re-run with &grants=1 for measurement #1.';
}

// ---------------------------------------------------------------------------
// The :2160 landmine, opt-in and last: the full-readSet whole-project export
// that fires when getRecordIdField() is unavailable.
// ---------------------------------------------------------------------------
if ($doFallback) {
    uv_time('pk_fallback_full_export_DANGEROUS', function () use ($pid, $dd, $ids) {
        $d = \REDCap::getData(['project_id' => $pid, 'return_format' => 'array',
            'records' => $ids, 'fields' => array_keys((array) $dd), 'exportDataAccessGroups' => true]);
        return is_array($d) ? count($d) : 0;
    }, $R);
} else {
    $R['notes'][] = 'The :2160 pk-fallback export was not measured. Re-run with &pkfallback=1 and a &limit to size it.';
}

$R['meta']['page_total_ms'] = round((microtime(true) - $T0) * 1000, 1);
$R['meta']['page_peak_mb']  = round(memory_get_peak_usage(true) / 1048576, 1);

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------
$json = json_encode($R, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
?>
<h4 style="margin-top:12px">Validation scan — measurement run</h4>
<p style="max-width:760px;color:#555">Read-only. Nothing was written. <b>Delete
<code>tools/measure_scan.php</code> when you are done.</b></p>

<table class="table table-sm" style="max-width:760px">
  <tr><th style="width:320px">Records in project</th><td><?php echo (int) $R['shape']['records_in_project']; ?></td></tr>
  <tr><th>Records measured</th><td><?php echo (int) $R['shape']['records_measured']; ?></td></tr>
  <tr><th>Findings per record (#10)</th><td><b><?php echo $h(isset($R['gate']['FINDINGS_PER_RECORD']) ? $R['gate']['FINDINGS_PER_RECORD'] : '?'); ?></b></td></tr>
  <tr><th>Contexts per record (#5)</th><td><?php echo $h(isset($R['gate']['CONTEXTS_PER_RECORD']) ? $R['gate']['CONTEXTS_PER_RECORD'] : '?'); ?></td></tr>
  <tr><th>Whole scan</th><td><?php echo $h($tot); ?> ms, peak <?php echo $h($R['meta']['page_peak_mb']); ?> MB</td></tr>
  <tr><th>Reads as share of scan</th><td><?php echo $h($R['gate']['reads_share_lower_pct']); ?>% – <?php echo $h($R['gate']['reads_share_upper_pct']); ?>%</td></tr>
  <tr><th>Gate verdict (#3)</th><td><b><?php echo $h($R['gate']['VERDICT']); ?></b></td></tr>
  <tr><th>_complete absent</th><td><?php echo $h($R['complete_status']['absent_pct']); ?>% of record/form pairs</td></tr>
  <tr><th>Untouched-form gate (#4)</th><td><b><?php echo $h($R['complete_status']['VERDICT']); ?></b></td></tr>
</table>

<p style="max-width:760px"><b>Copy everything below and send it back.</b></p>
<textarea style="width:100%;height:420px;font-family:monospace;font-size:11px"
  onclick="this.select()"><?php echo $h($json); ?></textarea>
