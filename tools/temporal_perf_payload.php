<?php
/**
 * temporal_perf_payload: size of the injected page config for one data-entry
 * page, and the rule ASTs it carries (written to <outdir>/<scenario>.json for
 * tools/temporal_perf_engine.cjs to time per-keystroke evaluation).
 * Usage: php tools/temporal_perf_payload.php <outdir> [scenario-filter]
 */
require __DIR__ . '/temporal_perf_harness.php';

$out = $argv[1] ?? sys_get_temp_dir();
$only = $argv[2] ?? null;
$forms = ['record_id'=>'fa','a_val'=>'fa','f1'=>'fa','f2'=>'fa','f3'=>'fa','f4'=>'fa','b_open'=>'fb'];

$scenarios = [
    // 4,000-digit numbers: the per-member rational multiply and the per-keystroke re-sum.
    'avg_bigdigits_n1400'  => [1400, function ($i) { return ['a_val'=>(string)(1 + $i % 9).str_repeat((string)($i % 10), 3999)]; },
                               ['assert'=>'[a_val]<={x}','references'=>['x'=>['field'=>'a_val','aggregate'=>'average']]]],
    'allinst_avg_same_n1400' => [1400, function ($i) { return ['a_val'=>'7'.str_repeat('3', 3999)]; },
                               ['assert'=>'[a_val][all-instances]<={x}','references'=>['x'=>['field'=>'a_val','aggregate'=>'average']]]],
    'allinst_avg_same_n700' => [700, function ($i) { return ['a_val'=>'7'.str_repeat('3', 3999)]; },
                               ['assert'=>'[a_val][all-instances]<={x}','references'=>['x'=>['field'=>'a_val','aggregate'=>'average']]]],
    // Payload amplification: 63-byte values are charged 1 unit, and JSON_HEX_TAG writes "<" as 6 bytes.
    'hex_amplified_4x9000' => [9000, function ($i) { $v = str_repeat('<', 62).($i % 10); return ['f1'=>$v,'f2'=>$v,'f3'=>$v,'f4'=>$v]; },
                               ['assert'=>"[f1][any-instance]<>'x' or [f2][any-instance]<>'x' or [f3][any-instance]<>'x' or [f4][any-instance]<>'x'"]],
    'plain_4x9000'         => [9000, function ($i) { $v = str_repeat('a', 62).($i % 10); return ['f1'=>$v,'f2'=>$v,'f3'=>$v,'f4'=>$v]; },
                               ['assert'=>"[f1][any-instance]<>'x' or [f2][any-instance]<>'x' or [f3][any-instance]<>'x' or [f4][any-instance]<>'x'"]],
];

foreach ($scenarios as $name => [$n, $row, $rule]) {
    if ($only !== null && stripos($name, $only) === false) continue;
    $rows = []; for ($i = 1; $i <= $n; $i++) $rows[$i] = $row($i) + ['fa_complete'=>'2'];
    $data = [1=>[1=>['record_id'=>'1'], 'repeat_instances'=>[1=>['fa'=>$rows]]]];
    $m = perf_mod($forms, ['a_val'=>perf_tag($rule)], perf_std_events(), $data);
    [$p, $ms, $mb] = perf_measure(function () use ($m, $n) { return perf_render($m, 'fa', '1', 1, $n); });
    $r = null; foreach ($p['cfg']['rules'] ?? [] as $x) if (in_array('a_val', $x['fields'] ?? [], true)) $r = $x;
    perf_row("render $name", $n, $ms, $mb, sprintf('payload=%.2f MB deferred=%s', strlen($p['raw']) / 1048576, !empty($r['deferred']) ? implode('|', $r['deferredWhy']) : 'no'));
    file_put_contents("$out/$name.json", json_encode(['assertAst'=>$r['assertAst'] ?? null, 'current'=>$rows[$n]]));
}
