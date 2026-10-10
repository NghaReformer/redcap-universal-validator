<?php
/**
 * temporal_perf_eventlist_distinct: the largest list the configuration gate still
 * accepts after P2 - every event named once plus the relative tokens - asked from
 * every host context of one save. Companion to temporal_perf_eventlist.php, whose
 * duplicated list is now refused.
 * Usage: php tools/temporal_perf_eventlist_distinct.php [events] [instances]
 */
require __DIR__ . '/temporal_perf_harness.php';
$E = (int)($argv[1] ?? 200); $n = (int)($argv[2] ?? 200);
$forms = ['record_id'=>'fa','a_val'=>'fa','c_val'=>'fc'];
$events = [];
for ($e = 1; $e <= $E; $e++) $events[$e] = ["ev{$e}_arm_1", 1, ['fa','fc'], $e === $E ? ['fa'] : []];
$rows = []; for ($i = 1; $i <= $n; $i++) $rows[$i] = ['a_val'=>'1','fa_complete'=>'2'];
$data = [1=>[$E=>['record_id'=>'1'], 'repeat_instances'=>[$E=>['fa'=>$rows]]]];
$tokens = ['previous-event-name','first-event-name','last-event-name','event-name'];   // next-event-name: the host is the last event, so it would be absent
for ($e = 1; $e <= $E; $e++) $tokens[] = "ev{$e}_arm_1";
$rule = ['assert'=>'{x}>=0','references'=>['x'=>['field'=>'c_val','events'=>$tokens,'aggregate'=>'count','excludeCurrent'=>true]]];
$tag = perf_tag($rule);
$m = perf_mod($forms, ['a_val'=>$tag], $events, $data);
printf("annotation size %d bytes, %d events, %d tokens, %d host instances\n", strlen($tag), $E, count($tokens), $n);
[, $ms, $mb] = perf_measure(function () use ($m, $E) { $m->redcap_save_record(PID, '1', 'fa', $E, null, null, null, 1); });
perf_row('audit (one save)', $n, $ms, $mb, 'invalid='.count(perf_logs($m,'invalid-id-saved')).' unconf='.count(perf_logs($m,'uvalidate-unconfigurable')));
[$res, $ms, $mb] = perf_measure(function () use ($m) { return $m->scanProject(PID); });
perf_row('scan (one record)', $n, $ms, $mb, 'viol='.count($res['violations']).' unresolved='.count($res['unconfigurable']));
[$p, $ms, $mb] = perf_measure(function () use ($m, $E) { return perf_render($m, 'fa', '1', $E, 1); });
perf_row('render (one page)', 1, $ms, $mb);
