<?php
/**
 * REPRO: "count"/"exists" (and scalar blank-vs-missing) decide that an instrument "has a row"
 * from the EVENT row / repeating-EVENT instance, not from the instrument's own completion marker.
 * The module fetches <form>_complete precisely to tell saved rows apart (TemporalIntegration::
 * temporalReadFields), but AddressResolver never reads it.
 *   1. non-repeating lab at fu_arm_1, never entered; the event row exists because demo was saved
 *   2. repeating EVENT diary: lab saved in instance 1 only; instance 2 exists because meas was saved
 *   3. scalar [fu_arm_1][lab_date]: "blank" when another form was saved in fu, "unresolved" when not
 * Data shape: REDCap getData(array) with the primary key requested (as temporalReadFields does)
 * returns an event row for every event holding any data, with blank values for other forms.
 * Run: php tools/temporal_sem_row_existence.php
 */
require_once __DIR__ . '/temporal_sem_lib.php';
use INSPIRE\UniversalValidator\AddressResolver;
use INSPIRE\UniversalValidator\ProjectShape;

$shape = new ProjectShape([
    1 => ['name'=>'base_arm_1','arm'=>1,'order'=>0,'forms'=>['demo','lab'],'repeats'=>[],'eventRepeats'=>false],
    2 => ['name'=>'fu_arm_1',  'arm'=>1,'order'=>1,'forms'=>['demo','lab'],'repeats'=>[],'eventRepeats'=>false],
    3 => ['name'=>'diary_arm_1','arm'=>1,'order'=>2,'forms'=>['meas','lab'],'repeats'=>[],'eventRepeats'=>true],
], ['record_id'=>['form'=>'demo'], 'age'=>['form'=>'demo'], 'lab_date'=>['form'=>'lab'], 'w'=>['form'=>'meas']]);
function out($x) { return $x['state'] === 'ok' ? $x['value'] : '!' . $x['state']; }
$demo = ['event'=>1,'instrument'=>'demo','instance'=>1,'values'=>[],'unsaved'=>false];

// 1. lab never entered anywhere; demo saved at base and fu.
$record = [1=>['record_id'=>'1','age'=>'30','lab_date'=>'','lab_complete'=>''],
           2=>['record_id'=>'1','age'=>'31','lab_date'=>'','lab_complete'=>'']];
$r = new AddressResolver($shape, $record);
sem_expect('1 exists(lab at fu_arm_1), lab never entered', out($r->resolveBinding(['field'=>'lab_date','event'=>'fu_arm_1','aggregate'=>'exists'], $demo)), '0');
sem_expect('1 count(lab over base+fu), lab never entered', out($r->resolveBinding(['field'=>'lab_date','events'=>['base_arm_1','fu_arm_1'],'aggregate'=>'count'], $demo)), '0');

// 2. repeating event: lab saved only in instance 1.
$record = ['repeat_instances'=>[3=>[''=>[
    1=>['record_id'=>'1','lab_date'=>'2024-01-01','lab_complete'=>'2','w'=>'','meas_complete'=>''],
    2=>['record_id'=>'1','lab_date'=>'','lab_complete'=>'','w'=>'5','meas_complete'=>'2']]]]];
$r = new AddressResolver($shape, $record);
sem_expect('2 count(lab in diary) with one saved lab entry', out($r->resolveBinding(['field'=>'lab_date','event'=>'diary_arm_1','aggregate'=>'count'], $demo)), '1');

// 3. the same "lab never entered at fu" is blank or unresolved depending on an unrelated form.
$withDemo = new AddressResolver($shape, [1=>['record_id'=>'1'], 2=>['record_id'=>'1','age'=>'31','lab_date'=>'','lab_complete'=>'']]);
$without  = new AddressResolver($shape, [1=>['record_id'=>'1']]);
$a = out($withDemo->resolve(['qref','lab_date',null,'fu_arm_1',null], $demo));
$b = out($without->resolve(['qref','lab_date',null,'fu_arm_1',null], $demo));
sem_expect('3 [fu_arm_1][lab_date] resolves the same whether or not demo was saved at fu', $a, $b);
sem_done('temporal_sem_row_existence');
