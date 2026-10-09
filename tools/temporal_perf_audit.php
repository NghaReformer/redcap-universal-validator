<?php
/**
 * temporal_perf_audit: what one save of one repeat entry audits and logs.
 *  A. The saved entry itself is not audited when it lies beyond the first 500
 *     host contexts in record order (the limit truncates in data order, not
 *     starting from the entry that changed).
 *  B. Every save re-logs the violation of every OTHER host context, so the log
 *     grows by up to 500 identical findings per save (legacy rules log 1).
 * Usage: php tools/temporal_perf_audit.php [n]
 */
require __DIR__ . '/temporal_perf_harness.php';

$n = (int)($argv[1] ?? 600);
$forms = ['record_id'=>'fa','a_val'=>'fa','b_open'=>'fb'];
$rows = []; for ($i = 1; $i <= $n; $i++) $rows[$i] = ['a_val'=>'5', 'fa_complete'=>'2'];
$data = [1=>[1=>['record_id'=>'1'], 'repeat_instances'=>[1=>['fa'=>$rows, 'fb'=>[1=>['b_open'=>'1','fb_complete'=>'2']]]]]];

$instancesLogged = function ($m) { $s = []; foreach (perf_logs($m, 'invalid-id-saved') as $c) $s[$c[1]['instance']] = true; return $s; };

// ---- extended rule: every entry violates ([a_val]=5 > 1) --------------------
$m = perf_mod($forms, ['a_val'=>perf_tag(['assert'=>'[a_val]<=[baseline_arm_1][b_open][1]'])], perf_std_events(), $data);
$m->redcap_save_record(PID, '1', 'fa', 1, null, null, null, $n);
$logged = $instancesLogged($m); $unc = perf_logs($m, 'uvalidate-unconfigurable');
printf("A extended: save of fa instance %d -> invalid logs=%d, saved instance logged=%s, incomplete notices=%d (notice names instance %s)\n",
    $n, count(perf_logs($m, 'invalid-id-saved')), isset($logged[$n]) ? 'YES' : 'NO', count($unc), $unc ? $unc[0][1]['instance'] : '-');

$m->logCalls = [];
$m->redcap_save_record(PID, '1', 'fa', 1, null, null, null, 3);
$m->redcap_save_record(PID, '1', 'fa', 1, null, null, null, 3);
printf("B extended: two saves of fa instance 3 -> invalid logs=%d (distinct instances=%d)\n", count(perf_logs($m, 'invalid-id-saved')), count($instancesLogged($m)));

// ---- the same constraint, written without extended syntax --------------------
$m = perf_mod($forms, ['a_val'=>perf_tag(['assert'=>'[a_val]<=1'])], perf_std_events(), $data);
$m->redcap_save_record(PID, '1', 'fa', 1, null, null, null, 3);
$m->redcap_save_record(PID, '1', 'fa', 1, null, null, null, 3);
printf("B legacy:   two saves of fa instance 3 -> invalid logs=%d\n", count(perf_logs($m, 'invalid-id-saved')));
$m->logCalls = [];
$m->redcap_save_record(PID, '1', 'fa', 1, null, null, null, $n);
printf("A legacy:   save of fa instance %d -> saved instance logged=%s\n", $n, isset($instancesLogged($m)[$n]) ? 'YES' : 'NO');
