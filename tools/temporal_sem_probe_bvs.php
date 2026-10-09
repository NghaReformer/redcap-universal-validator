<?php
/** Exploratory: browser (JS on compiled AST, live = saved values in display format) vs audit (PHP) verdicts. */
require_once __DIR__ . '/temporal_sem_lib.php';
use INSPIRE\UniversalValidator\ProjectShape;

$shape = new ProjectShape([
    1 => ['name'=>'base_arm_1','arm'=>1,'order'=>0,'forms'=>['visit','meas','demo'],'repeats'=>['meas'],'eventRepeats'=>false],
    2 => ['name'=>'fu_arm_1',  'arm'=>1,'order'=>1,'forms'=>['visit','meas'],'repeats'=>['meas'],'eventRepeats'=>false],
], [
    'visit_date'=>['form'=>'visit','type'=>'text','validation'=>'date_dmy'],
    'visit_dt'=>['form'=>'visit','type'=>'text','validation'=>'datetime_mdy'],
    'weight'=>['form'=>'meas','type'=>'text','validation'=>'number'],
    'mkey'=>['form'=>'meas','type'=>'text'],
    'mdate'=>['form'=>'meas','type'=>'text','validation'=>'date_mdy'],
    'limit'=>['form'=>'demo','type'=>'text'],
]);
$record = [
    1=>['visit_date'=>'2023-12-31','visit_dt'=>'2023-12-31 23:00','limit'=>'10'],
    2=>['visit_date'=>'2024-01-05','visit_dt'=>'2024-01-01 01:00'],
    'repeat_instances'=>[
        1=>['meas'=>[1=>['weight'=>'0.1','mkey'=>'A','mdate'=>'2024-02-29'],2=>['weight'=>'0.2','mkey'=>'a','mdate'=>'2024-03-01'],3=>['weight'=>'0.3','mkey'=>'A','mdate'=>'']]],
    ],
];
$display = ['visit_date'=>function ($v) { return $v === '' ? '' : substr($v,8,2).'-'.substr($v,5,2).'-'.substr($v,0,4); },
            'visit_dt'=>function ($v) { return $v === '' ? '' : substr($v,5,2).'-'.substr($v,8,2).'-'.substr($v,0,4).substr($v,10); },
            'mdate'=>function ($v) { return $v === '' ? '' : substr($v,5,2).'-'.substr($v,8,2).'-'.substr($v,0,4); }];

function scenario($label, $shape, $record, $display, array $rule, array $ctx, $key = 'assert') {
    $server = sem_server($shape, $record, $rule, $ctx, $key);
    $ast = sem_browser($shape, $record, $rule, $ctx + ['values'=>$ctx['saved']], $key);
    if (is_string($ast)) { sem_expect("$label [browser deferred]", $ast, $server); return; }
    $live = [];
    foreach ($ctx['saved'] as $f=>$v) $live[$f] = isset($display[$f]) ? $display[$f]($v) : $v;
    $js = sem_js_eval($ast, $live, $key === 'assert');
    if (is_string($server)) { sem_expect("$label [server $server] js", $js, null); return; }
    sem_expect("$label browser(js)==audit(php)", $js, $server);
}
$fu = ['event'=>2,'instrument'=>'visit','instance'=>1,'saved'=>$record[2]];
$fu['values'] = $record[2];
// Documented example: legacy comparison of a date across events
scenario('doc example [visit_date]>=[previous-event-name][visit_date], date_dmy', $shape, $record, $display,
    ['assert'=>'[visit_date]>=[previous-event-name][visit_date]'], $fu);
// Typed version
scenario('typed date dmy live vs saved', $shape, $record, $display,
    ['assert'=>'{v}>={p}','references'=>['v'=>['field'=>'visit_date','type'=>'date'],'p'=>['field'=>'visit_date','event'=>'previous-event-name','type'=>'date']]], $fu);
scenario('typed datetime mdy elapsed hours', $shape, $record, $display,
    ['assert'=>'{h}<=2','references'=>['h'=>['field'=>'visit_dt','type'=>'datetime','elapsedFrom'=>'[base_arm_1][visit_dt]','unit'=>'hours']]], $fu);
// Aggregates from a meas instance (current member live)
foreach ([1,2,3] as $i) {
    $m = ['event'=>1,'instrument'=>'meas','instance'=>$i,'saved'=>$record['repeat_instances'][1]['meas'][$i]];
    $m['values'] = $m['saved'];
    scenario("sum==0.6 from meas $i", $shape, $record, $display, ['assert'=>'{s}=0.6','references'=>['s'=>['field'=>'weight','aggregate'=>'sum']]], $m);
    scenario("avg*3 exact: [weight]<={a} from meas $i", $shape, $record, $display, ['assert'=>'[weight]<={a}','references'=>['a'=>['field'=>'weight','aggregate'=>'average']]], $m);
    scenario("avg excludeCurrent from meas $i", $shape, $record, $display, ['assert'=>'[weight]>={a}','references'=>['a'=>['field'=>'weight','aggregate'=>'average','excludeCurrent'=>true]]], $m);
    scenario("distinct mkey from meas $i", $shape, $record, $display, ['assert'=>'{d}=2','references'=>['d'=>['field'=>'mkey','aggregate'=>'distinct-count']]], $m);
    scenario("match mkey count from meas $i", $shape, $record, $display, ['assert'=>'{c}=2','references'=>['c'=>['field'=>'weight','match'=>['mkey'=>'[mkey]'],'aggregate'=>'count']]], $m);
    scenario("typed mdate mdy vs first-instance from meas $i", $shape, $record, $display, ['assert'=>'{d}>={f}','references'=>['d'=>['field'=>'mdate','type'=>'date'],'f'=>['field'=>'mdate','instance'=>'first-instance','type'=>'date']]], $m);
    scenario("any-instance weight>0.25 from meas $i", $shape, $record, $display, ['assert'=>"[weight][any-instance]>0.25"], $m);
    scenario("when prev-instance unresolved on 1 (or true)", $shape, $record, $display, ['when'=>"[mkey]='A' or [weight][previous-instance]>'5'"], $m, 'when');
}
sem_done('temporal_sem_probe_bvs');
