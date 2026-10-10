<?php
/**
 * temporal_perf_curve: wall time / memory / completeness of scan, audit and page
 * render for one record with n repeat instances on the host form, per rule shape.
 * Usage: php tools/temporal_perf_curve.php [n1,n2,...] [shape-filter]
 */
require __DIR__ . '/temporal_perf_harness.php';

$ns = isset($argv[1]) ? array_map('intval', explode(',', $argv[1])) : [100, 1000, 5000];
$only = $argv[2] ?? null;
$forms = ['record_id'=>'fa','a_val'=>'fa','key_a'=>'fa','b_open'=>'fb','key_b'=>'fb'];

$shapes = [
    'avg (memo)'        => ['[a_val]<={x}', ['x'=>['field'=>'a_val','aggregate'=>'average']]],
    'avg excludeCurrent'=> ['[a_val]<={x}', ['x'=>['field'=>'a_val','aggregate'=>'average','excludeCurrent'=>true]]],
    'distinct-count'    => ['{x}>=1', ['x'=>['field'=>'a_val','aggregate'=>'distinct-count']]],
    'count excludeCur'  => ['{x}>=0', ['x'=>['field'=>'a_val','aggregate'=>'count','excludeCurrent'=>true]]],
    'match fb by key'   => ['[a_val]>={x}', ['x'=>['field'=>'b_open','event'=>'baseline_arm_1','match'=>['key_b'=>'[key_a]']]]],
    'match fa self key' => ['{x}>=0', ['x'=>['field'=>'a_val','aggregate'=>'count','match'=>['key_a'=>'[key_a]']]]],
    'previous-instance' => ['[a_val]>=[a_val][previous-instance]', null],
    'any-instance'      => ['[a_val]<=[a_val][any-instance]', null],
    'max (memo)'        => ['[a_val]<={x}', ['x'=>['field'=>'a_val','aggregate'=>'maximum']]],
    'record unique'     => [null, null],
];

foreach ($shapes as $label => [$expr, $refs]) {
    if ($only !== null && stripos($label, $only) === false) continue;
    foreach ($ns as $n) {
        $rows = []; $fb = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[$i] = ['a_val'=>(string)($i % 97), 'key_a'=>'k'.($i % 50), 'fa_complete'=>'2'];
        }
        for ($i = 1; $i <= 50; $i++) $fb[$i] = ['b_open'=>(string)$i, 'key_b'=>'k'.($i-1), 'fb_complete'=>'2'];
        $data = [1=>[1=>['record_id'=>'1'], 'repeat_instances'=>[1=>['fa'=>$rows, 'fb'=>$fb]]]];
        if ($expr === null) $ann = ['a_val'=>'@UVUNIQUE={"scope":"record"}'];
        else { $rule = ['assert'=>$expr]; if ($refs) $rule['references'] = $refs; $ann = ['a_val'=>perf_tag($rule)]; }
        $m = perf_mod($forms, $ann, perf_std_events(), $data);

        [$res, $ms, $mb] = perf_measure(function () use ($m) { return $m->scanProject(PID); });
        $unc = array_column($res['unconfigurable'], 'why');
        perf_row("scan   $label", $n, $ms, $mb, 'viol='.count($res['violations']).' unresolved='.count($unc).($unc?' ['.substr($unc[0],0,60).']':''));

        $m->logCalls = [];
        [, $ms, $mb] = perf_measure(function () use ($m) { $m->redcap_save_record(PID, '1', 'fa', 1, null, null, null, 1); });
        $inv = count(perf_logs($m, 'invalid-id-saved')); $un = perf_logs($m, 'uvalidate-unconfigurable');
        perf_row("audit  $label", $n, $ms, $mb, "invalid-logs=$inv unconf-logs=".count($un).($un?' ['.substr($un[0][1]['why'],0,60).']':''));

        [$p, $ms, $mb] = perf_measure(function () use ($m, $n) { return perf_render($m, 'fa', '1', 1, $n); });
        $r = null; foreach ($p['cfg']['rules'] ?? [] as $x) if (in_array('a_val', $x['fields'] ?? [], true)) $r = $x;
        perf_row("render $label", $n, $ms, $mb, 'payload='.strlen($p['raw']).'B deferred='.(!empty($r['deferred'])?implode('|',$r['deferredWhy']):'no'));
    }
}
