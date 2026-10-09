<?php
/**
 * REPRO: a whitespace-only saved value ("  ", e.g. from an API import) is blank to every
 * comparison (Logic::compare trims " \t\r\n"), but the aggregates treat it as populated:
 *   populated-count counts it, distinct-count counts it as a value,
 *   and sum/average/minimum/maximum become UNRESOLVED instead of ignoring it like a blank.
 * Same in the browser evaluator (QRID_temporalValue filters v!=='' only).
 * Run: php tools/temporal_sem_whitespace.php
 */
require_once __DIR__ . '/temporal_sem_lib.php';
use INSPIRE\UniversalValidator\AddressResolver;
use INSPIRE\UniversalValidator\ProjectShape;
use INSPIRE\UniversalValidator\Logic;

$shape = new ProjectShape([1 => ['name'=>'base_arm_1','arm'=>1,'order'=>0,'forms'=>['meas'],'repeats'=>['meas'],'eventRepeats'=>false]],
    ['w'=>['form'=>'meas']]);
$record = ['repeat_instances'=>[1=>['meas'=>[1=>['w'=>'5'], 2=>['w'=>'  '], 3=>['w'=>'7']]]]];
$ctx = ['event'=>1,'instrument'=>'meas','instance'=>1,'values'=>['w'=>'5'],'unsaved'=>false];
$r = new AddressResolver($shape, $record);
$out = function ($x) { return $x['state'] === 'ok' ? $x['value'] : '!' . $x['state']; };
sem_expect("comparison view: [w]='' is true for '  '", Logic::evaluate(Logic::parse("[w]=''")['ast'], ['w'=>'  ']), true);
sem_expect('populated-count', $out($r->resolveBinding(['field'=>'w','aggregate'=>'populated-count'], $ctx)), '2');
sem_expect('sum ignores the blank-equivalent row', $out($r->resolveBinding(['field'=>'w','aggregate'=>'sum'], $ctx)), '12');
// Browser twin: the same aggregate built from members.
$ast = ['temporal', ['cmp','=',['aggregate','sum',[['lit','5'],['lit','  '],['lit','7']]],['lit','12']]];
sem_expect('browser sum=12', sem_js_eval($ast, [], true), true);
sem_done('temporal_sem_whitespace');
