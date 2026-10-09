<?php
/**
 * REPRO: an elapsedFrom field whose validation does not match the binding's "type" passes
 * every configuration check (validate + validateProject), yet can never resolve at runtime:
 * the saved date '2024-01-01' is parsed as a datetime and is always "unresolved".
 * The binding's own field IS checked against its type (validateProject), elapsedFrom is not.
 * Run: php tools/temporal_sem_elapsed_type.php
 */
require_once __DIR__ . '/temporal_sem_lib.php';
use INSPIRE\UniversalValidator\ProjectShape;
use INSPIRE\UniversalValidator\TemporalRules;

$shape = new ProjectShape([
    1 => ['name'=>'base_arm_1','arm'=>1,'order'=>0,'forms'=>['visit'],'repeats'=>[],'eventRepeats'=>false],
], [
    'consent_date'=>['form'=>'visit','type'=>'text','validation'=>'date_ymd'],          // a DATE field
    'result_time' =>['form'=>'visit','type'=>'text','validation'=>'datetime_ymd'],      // a DATETIME field
]);
$rule = ['assert'=>'{h}<=48','references'=>['h'=>['field'=>'result_time','type'=>'datetime',
    'elapsedFrom'=>'[consent_date]','unit'=>'hours']]];
$errors = array_merge(TemporalRules::validate($rule), TemporalRules::validateProject($rule, $shape));
sem_expect('configuration errors reported for a date elapsedFrom on a datetime binding', count($errors) > 0, true);
$record = [1=>['consent_date'=>'2024-01-01','result_time'=>'2024-01-02 10:00']];
$ctx = ['event'=>1,'instrument'=>'visit','instance'=>1,'values'=>$record[1]];
sem_expect('runtime verdict', sem_server($shape, $record, $rule, $ctx), 'deferred:unresolved');
sem_done('temporal_sem_elapsed_type');
