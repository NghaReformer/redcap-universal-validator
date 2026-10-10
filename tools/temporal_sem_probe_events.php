<?php
/** Exploratory: event and instance selectors across arms, designation gaps and repeating events. */
require_once __DIR__ . '/temporal_sem_lib.php';
use INSPIRE\UniversalValidator\AddressResolver;
use INSPIRE\UniversalValidator\ProjectShape;

$shape = new ProjectShape([
    11 => ['name'=>'scr_arm_1',  'arm'=>1,'order'=>0,'forms'=>['demo'],               'repeats'=>[],       'eventRepeats'=>false],
    12 => ['name'=>'base_arm_1', 'arm'=>1,'order'=>1,'forms'=>['demo','visit'],       'repeats'=>['visit'],'eventRepeats'=>false],
    13 => ['name'=>'mid_arm_1',  'arm'=>1,'order'=>2,'forms'=>['demo'],               'repeats'=>[],       'eventRepeats'=>false],
    14 => ['name'=>'fu_arm_1',   'arm'=>1,'order'=>3,'forms'=>['demo','visit'],       'repeats'=>[],       'eventRepeats'=>false],
    15 => ['name'=>'diary_arm_1','arm'=>1,'order'=>4,'forms'=>['visit','lab'],        'repeats'=>[],       'eventRepeats'=>true],
    16 => ['name'=>'close_arm_1','arm'=>1,'order'=>5,'forms'=>['demo'],               'repeats'=>[],       'eventRepeats'=>false],
    21 => ['name'=>'base_arm_2', 'arm'=>2,'order'=>6,'forms'=>['demo','visit'],       'repeats'=>[],       'eventRepeats'=>false],
    22 => ['name'=>'fu_arm_2',   'arm'=>2,'order'=>7,'forms'=>['demo'],               'repeats'=>[],       'eventRepeats'=>false],
], [
    'age'=>['form'=>'demo','type'=>'text'], 'weight'=>['form'=>'visit','type'=>'text'], 'res'=>['form'=>'lab','type'=>'text'],
]);
$record = [
    11=>['age'=>'10'], 12=>['age'=>'20'], 13=>['age'=>'30'], 14=>['age'=>'40','weight'=>'140'], 16=>['age'=>'60'],
    21=>['age'=>'70','weight'=>'170'], 22=>['age'=>'80'],
    'repeat_instances'=>[
        12=>['visit'=>[1=>['weight'=>'121'],2=>['weight'=>'122'],4=>['weight'=>'124']]],
        15=>[''=>[1=>['weight'=>'151','res'=>'r1'],3=>['weight'=>'153','res'=>'']]],
    ],
];
$r = new AddressResolver($shape, $record);
function v($x) { return $x['state'] === 'ok' ? (!isset($x['value']) && isset($x['members']) ? 'set:' . implode(',', array_column($x['members'], 'value')) : $x['value']) : '!' . $x['state']; }
$ctx = function ($e, $f, $i = 1, $extra = []) { return array_replace(['event'=>$e,'instrument'=>$f,'instance'=>$i,'values'=>[],'unsaved'=>false], $extra); };

// Relative events on demo from mid_arm_1 (13): prev designated = base(12), next = fu(14)
sem_expect('prev-event demo from mid', v($r->resolve(['qref','age',null,'previous-event-name',null], $ctx(13,'demo'))), '20');
sem_expect('next-event demo from mid', v($r->resolve(['qref','age',null,'next-event-name',null], $ctx(13,'demo'))), '40');
sem_expect('first-event demo from mid', v($r->resolve(['qref','age',null,'first-event-name',null], $ctx(13,'demo'))), '10');
sem_expect('last-event demo from mid', v($r->resolve(['qref','age',null,'last-event-name',null], $ctx(13,'demo'))), '60');
// visit is designated in 12, 14, 15 only; from close (16) previous visit event = diary (15, repeating event) -> needs instance
sem_expect('prev-event visit from close (repeating event) is ambiguous', v($r->resolve(['qref','weight',null,'previous-event-name',null], $ctx(16,'demo'))), '!ambiguous');
sem_expect('prev-event visit from close, last-instance', v($r->resolve(['qref','weight',null,'previous-event-name','last-instance'], $ctx(16,'demo'))), '153');
// From mid (13): previous visit event is base (12, repeating form) -> ambiguous without selector
sem_expect('prev-event visit from mid needs selector', v($r->resolve(['qref','weight',null,'previous-event-name',null], $ctx(13,'demo'))), '!ambiguous');
sem_expect('next-event visit from mid = fu non-repeating', v($r->resolve(['qref','weight',null,'next-event-name',null], $ctx(13,'demo'))), '140');
// Current is first/last
sem_expect('prev-event from first event (scr) absent', v($r->resolve(['qref','age',null,'previous-event-name',null], $ctx(11,'demo'))), '!absent');
sem_expect('next-event from last arm-1 event absent', v($r->resolve(['qref','age',null,'next-event-name',null], $ctx(16,'demo'))), '!absent');
sem_expect('first-event from first = itself', v($r->resolve(['qref','age',null,'first-event-name',null], $ctx(11,'demo'))), '10');
// Arm 2 relative selectors stay in arm 2
sem_expect('arm2 prev-event from fu_arm_2', v($r->resolve(['qref','age',null,'previous-event-name',null], $ctx(22,'demo'))), '70');
sem_expect('arm2 first-event', v($r->resolve(['qref','age',null,'first-event-name',null], $ctx(22,'demo'))), '70');
sem_expect('arm2 last-event visit from base_arm_2 = itself', v($r->resolve(['qref','weight',null,'last-event-name',null], $ctx(22,'demo'))), '170');
// Explicit other-arm name
sem_expect('explicit other arm', v($r->resolve(['qref','age',null,'base_arm_1',null], $ctx(22,'demo'))), '20');

// Repeating event: instrument lab (not in "repeats") inside repeating event is repeating
sem_expect('lab in diary from diary/visit inst 3: same bucket', v($r->resolve(['ref','res',null], $ctx(15,'visit',3))), '');
sem_expect('lab in diary previous-instance from 3 (2 missing) absent', v($r->resolve(['qref','res',null,null,'previous-instance'], $ctx(15,'visit',3))), '!absent');
sem_expect('lab first-instance from diary', v($r->resolve(['qref','res',null,null,'first-instance'], $ctx(15,'visit',3))), 'r1');
sem_expect('next-instance on last (3) absent', v($r->resolve(['qref','weight',null,null,'next-instance'], $ctx(15,'visit',3))), '!absent');
sem_expect('any-instance diary from base_arm_2 via name', v($r->resolve(['qref','weight',null,'diary_arm_1','any-instance'], $ctx(21,'demo'))), 'set:151,153');
// Repeating form: previous-instance across gap
sem_expect('base visit inst 4 prev = 3 missing', v($r->resolve(['qref','weight',null,null,'previous-instance'], $ctx(12,'visit',4))), '!absent');
sem_expect('base visit inst 4 last', v($r->resolve(['qref','weight',null,null,'last-instance'], $ctx(12,'visit',2))), '124');
// unsaved new instance 5: current-instance is live blank; last-instance is the new one
$u = $ctx(12,'visit',5,['unsaved'=>true,'values'=>['weight'=>'99']]);
sem_expect('unsaved current-instance live', v($r->resolve(['qref','weight',null,null,'current-instance'], $u)), '99');
sem_expect('unsaved last-instance includes the new one', v($r->resolve(['qref','weight',null,null,'last-instance'], $u)), '99');
sem_expect('unsaved previous-instance = 4', v($r->resolve(['qref','weight',null,null,'previous-instance'], $u)), '124');
// Instance selector on non-repeating target is invalid
sem_expect('instance selector on non-repeating', v($r->resolve(['qref','age',null,null,'1'], $ctx(12,'visit',1))), '!invalid');
// Relative instance from a non-repeating context into a repeating bucket elsewhere
sem_expect('relative from non-repeating ctx invalid', v($r->resolve(['qref','weight',null,'base_arm_1','previous-instance'], $ctx(13,'demo'))), '!invalid');
// Binding with integer instance
sem_expect('binding instance int 2', v($r->resolveBinding(['field'=>'weight','event'=>'base_arm_1','instance'=>2], $ctx(13,'demo'))), '122');
// Aggregate across arm 1 events: visit collected in 12 (3 rows), 14 (1 row), 15 (2 rows) = 6
sem_expect('arm count visit', v($r->resolveBinding(['field'=>'weight','events'=>'arm','arm'=>1,'aggregate'=>'count'], $ctx(13,'demo'))), '6');
sem_expect('arm sum visit', v($r->resolveBinding(['field'=>'weight','events'=>'arm','arm'=>1,'aggregate'=>'sum'], $ctx(13,'demo'))), (string)(121+122+124+140+151+153));
sem_done('temporal_sem_probe_events');
