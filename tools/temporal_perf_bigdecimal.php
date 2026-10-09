<?php
/**
 * temporal_perf_bigdecimal: long saved numbers (a data-entry user can type them)
 * against averages. The budget charges a value's size once, when the column is
 * first read; the per-member rational multiply and the per-context re-sum are
 * not charged at all.
 * Usage: php tools/temporal_perf_bigdecimal.php [digits] [n1,n2,...]
 */
require __DIR__ . '/temporal_perf_harness.php';

$digits = (int)($argv[1] ?? 4000);
$ns = isset($argv[2]) ? array_map('intval', explode(',', $argv[2])) : [25, 50, 100, 200];
$forms = ['record_id'=>'fa','a_val'=>'fa','key_a'=>'fa','b_open'=>'fb'];
$shapes = [
    'all-instances <= avg'    => ['[a_val][all-instances]<={x}', ['x'=>['field'=>'a_val','aggregate'=>'average']]],
    'self <= avg excludeCur'  => ['[a_val]<={x}', ['x'=>['field'=>'a_val','aggregate'=>'average','excludeCurrent'=>true]]],
    'self <= max excludeCur'  => ['[a_val]<={x}', ['x'=>['field'=>'a_val','aggregate'=>'maximum','excludeCurrent'=>true]]],
    // Identical values: every member equals the average, so "all" never exits early.
    'SAME all-instances <= avg' => ['[a_val][all-instances]<={x}', ['x'=>['field'=>'a_val','aggregate'=>'average']]],
];
$only = $argv[3] ?? null;
foreach ($shapes as $label => [$expr, $refs]) {
    if ($only !== null && stripos($label, $only) === false) continue;
    foreach ($ns as $n) {
        $rows = []; $same = strpos($label, 'SAME') === 0;
        for ($i = 1; $i <= $n; $i++) $rows[$i] = ['a_val'=>$same ? '7'.str_repeat('3', $digits - 1) : (string)(1 + $i % 9) . str_repeat((string)($i % 10), $digits - 1), 'fa_complete'=>'2'];
        $data = [1=>[1=>['record_id'=>'1'], 'repeat_instances'=>[1=>['fa'=>$rows]]]];
        $m = perf_mod($forms, ['a_val'=>perf_tag(['assert'=>$expr,'references'=>$refs])], perf_std_events(), $data);
        [$res, $ms, $mb] = perf_measure(function () use ($m) { return $m->scanProject(PID); });
        perf_row("scan  $label d=$digits", $n, $ms, $mb, 'viol='.count($res['violations']).' unresolved='.count($res['unconfigurable']));
        $m->logCalls = [];
        [, $ms, $mb] = perf_measure(function () use ($m) { $m->redcap_save_record(PID, '1', 'fa', 1, null, null, null, 1); });
        perf_row("audit $label d=$digits", $n, $ms, $mb, 'invalid='.count(perf_logs($m,'invalid-id-saved')).' unconf='.count(perf_logs($m,'uvalidate-unconfigurable')));
    }
}
