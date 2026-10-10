<?php
/**
 * temporal_perf_allinst: [a_val]<=[a_val][all-instances] with ordinary short
 * values that all pass, so "all" never exits early. The shared column is read
 * once, but every host context still compares against every member and the
 * budget charges 1 unit per 32 members. One save's audit and one record's scan.
 * Usage: php tools/temporal_perf_allinst.php [n1,n2,...]
 */
require __DIR__ . '/temporal_perf_harness.php';
$ns = isset($argv[1]) ? array_map('intval', explode(',', $argv[1])) : [500, 1500, 3000];
$forms = ['record_id'=>'fa','a_val'=>'fa','b_open'=>'fb'];
foreach ($ns as $n) {
    $rows = []; for ($i = 1; $i <= $n; $i++) $rows[$i] = ['a_val'=>'5', 'fa_complete'=>'2'];
    $m = perf_mod($forms, ['a_val'=>perf_tag(['assert'=>'[a_val]<=[a_val][all-instances]'])], perf_std_events(),
        [1=>[1=>['record_id'=>'1'], 'repeat_instances'=>[1=>['fa'=>$rows]]]]);
    [, $ms, $mb] = perf_measure(function () use ($m) { $m->redcap_save_record(PID, '1', 'fa', 1, null, null, null, 1); });
    perf_row('audit all-instances (no early exit)', $n, $ms, $mb, 'unconf='.count(perf_logs($m, 'uvalidate-unconfigurable')));
    [$res, $ms, $mb] = perf_measure(function () use ($m) { return $m->scanProject(PID); });
    perf_row('scan  all-instances (no early exit)', $n, $ms, $mb, 'unresolved='.count($res['unconfigurable']));
}
