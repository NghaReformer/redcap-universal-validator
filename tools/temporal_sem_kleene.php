<?php
/**
 * REPRO: one unresolved operand defers the WHOLE condition, even when and/or already has an
 * answer. TemporalLogic/QRID_temporalEvaluate implement three-valued and/or (true OR unknown =
 * true; false AND unknown = false), but TemporalRules::compile adds a "problem" for every
 * unresolved member, and any problem defers the rule in both the audit and the page.
 * Effect: @UVREQUIRED when "[flag]='1' or [result][previous-instance]='1'" is never enforced on
 * instance 1 (where previous-instance is unresolved), although [flag]='1' already decides it.
 * Run: php tools/temporal_sem_kleene.php
 */
require_once __DIR__ . '/temporal_sem_lib.php';
use INSPIRE\UniversalValidator\ProjectShape;
use INSPIRE\UniversalValidator\TemporalLogic;

$shape = new ProjectShape([1 => ['name'=>'base_arm_1','arm'=>1,'order'=>0,'forms'=>['lab'],'repeats'=>['lab'],'eventRepeats'=>false]],
    ['flag'=>['form'=>'lab'], 'result'=>['form'=>'lab']]);
$record = ['repeat_instances'=>[1=>['lab'=>[1=>['flag'=>'1','result'=>'0']]]]];
$ctx = ['event'=>1,'instrument'=>'lab','instance'=>1,'values'=>$record['repeat_instances'][1]['lab'][1]];
$or  = ['when'=>"[flag]='1' or [result][previous-instance]='1'"];
$and = ['when'=>"[flag]='0' and [result][previous-instance]='1'"];
// The evaluator's own answer for these shapes:
sem_expect('evaluator: true or unknown', TemporalLogic::evaluate(['or',[['const',true],['unknown']]], function(){return '';}, false), true);
sem_expect('evaluator: false and unknown', TemporalLogic::evaluate(['and',[['const',false],['unknown']]], function(){return '';}, false), false);
// What the audit and the page do with the rule:
sem_expect('audit: or-gate with decided left side', sem_server($shape, $record, $or, $ctx, 'when'), true);
sem_expect('page:  or-gate with decided left side', is_array(sem_browser($shape, $record, $or, $ctx, 'when')), true);
sem_expect('audit: and-gate with decided left side', sem_server($shape, $record, $and, $ctx, 'when'), false);
sem_done('temporal_sem_kleene');
