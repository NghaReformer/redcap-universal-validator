<?php
/**
 * temporal_perf_eventlist: a binding's `events` list is capped only at 10,000
 * tokens and duplicates are allowed. A token naming an event where the record
 * has no non-repeating row costs no budget, yet each one walks every event of
 * the project (relative tokens) per host context. excludeCurrent disables the
 * memo, so the walk repeats for every host context of the save.
 * Usage: php tools/temporal_perf_eventlist.php [events] [tokens] [instances]
 */
require __DIR__ . '/temporal_perf_harness.php';
$E = (int)($argv[1] ?? 200); $T = (int)($argv[2] ?? 2900); $n = (int)($argv[3] ?? 500);
$forms = ['record_id'=>'fa','a_val'=>'fa','c_val'=>'fc'];
$events = [];
for ($e = 1; $e <= $E; $e++) $events[$e] = ["ev{$e}_arm_1", 1, ['fa','fc'], $e === $E ? ['fa'] : []];
$rows = []; for ($i = 1; $i <= $n; $i++) $rows[$i] = ['a_val'=>'1','fa_complete'=>'2'];
$data = [1=>[$E=>['record_id'=>'1'], 'repeat_instances'=>[$E=>['fa'=>$rows]]]];
$rule = ['assert'=>'{x}>=0','references'=>['x'=>['field'=>'c_val','events'=>array_fill(0, $T, 'previous-event-name'),'aggregate'=>'count','excludeCurrent'=>true]]];
$tag = perf_tag($rule);
$m = perf_mod($forms, ['a_val'=>$tag], $events, $data);
printf("annotation size %d bytes, %d events, %d tokens, %d host instances\n", strlen($tag), $E, $T, $n);
[, $ms, $mb] = perf_measure(function () use ($m, $E) { $m->redcap_save_record(PID, '1', 'fa', $E, null, null, null, 1); });
perf_row('audit (one save)', $n, $ms, $mb, 'invalid='.count(perf_logs($m,'invalid-id-saved')).' unconf='.count(perf_logs($m,'uvalidate-unconfigurable')));
[$p, $ms, $mb] = perf_measure(function () use ($m, $E) { return perf_render($m, 'fa', '1', $E, 1); });
perf_row('render (one page)', 1, $ms, $mb);
