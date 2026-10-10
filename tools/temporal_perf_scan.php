<?php
/**
 * temporal_perf_scan: budget reset between records, and how a direct scan
 * reports records the budget could not finish.
 * Records 1..3 each have 1,500 entries under [a_val][any-instance] (the budget
 * runs out part-way); record 4 is small and violating. The direct scan's
 * unresolved notes are keyed by rule and by the context's POSITION in its record,
 * with no record id, so the three unfinished records collapse into one note.
 */
require __DIR__ . '/temporal_perf_harness.php';

$forms = ['record_id'=>'fa','a_val'=>'fa','b_open'=>'fb'];
$big = []; for ($i = 1; $i <= 1500; $i++) $big[$i] = ['a_val'=>'5', 'fa_complete'=>'2'];
$data = [];
foreach ([1, 2, 3] as $r) $data[$r] = [1=>['record_id'=>(string)$r], 'repeat_instances'=>[1=>['fa'=>$big]]];
$data[4] = [1=>['record_id'=>'4'], 'repeat_instances'=>[1=>['fa'=>[1=>['a_val'=>'9','fa_complete'=>'2'], 2=>['a_val'=>'1','fa_complete'=>'2']]]]];
$m = perf_mod($forms, ['a_val'=>perf_tag(['assert'=>'[a_val]<=[a_val][all-instances]'])], perf_std_events(), $data);

[$res, $ms] = perf_measure(function () use ($m) { return $m->scanProject(PID); });
printf("direct scan: %.0f ms, violations=%d (record 4 violations=%d)\n", $ms, count($res['violations']),
    count(array_filter($res['violations'], function ($v) { return (string)$v['record'] === '4'; })));
printf("direct scan unresolved notes=%d for 3 unfinished records:\n", count($res['unconfigurable']));
foreach ($res['unconfigurable'] as $u) echo '  - ', $u['why'], "\n";
foreach (['incomplete', 'recordsScanned', 'records'] as $k) if (isset($res[$k])) echo "  $k: ", json_encode($res[$k]), "\n";

// Durable path: one record at a time, problems returned per record.
$ctx = $m->durableScanContext(PID, ['generation'=>null]);
foreach ([1, 4] as $r) {
    $d = $m->durableEvaluateRecord($ctx['plan'], PID, (string)$r, REDCap::$data[$r], 1, str_repeat('k', 32), $ctx['plan']['ruleIds']);
    printf("durable record %d: findings=%d problems=%d %s\n", $r, count($d['findings']), count($d['problems'] ?? []), json_encode(array_slice(array_column($d['problems'] ?? [], 'why'), 0, 2)));
}
