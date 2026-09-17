<?php
require_once __DIR__.'/../php/AddressResolver.php';
use INSPIRE\UniversalValidator\AddressResolver;
use INSPIRE\UniversalValidator\ProjectShape;
use INSPIRE\UniversalValidator\ExactDecimal;
$n=0;
function check($x,$why){global $n;$n++;if(!$x)throw new RuntimeException($why);}
$shape=new ProjectShape([1=>['name'=>'visit_arm_1','arm'=>1,'order'=>1,'forms'=>['specimen','lab'],'repeats'=>['specimen','lab'],'eventRepeats'=>false]],
 ['sid'=>['form'=>'specimen'],'lid'=>['form'=>'lab'],'amount'=>['form'=>'lab']]);
$node=['repeat_instances'=>[1=>['specimen'=>[1=>['sid'=>'001']], 'lab'=>[1=>['lid'=>'001','amount'=>'9007199254740993.01'],2=>['lid'=>'1','amount'=>'0.09'],3=>['lid'=>'other','amount'=>'-1.1']]]]];
$r=new AddressResolver($shape,$node);$c=['event'=>1,'instrument'=>'specimen','instance'=>1];
$b=['field'=>'amount','match'=>['lid'=>'[sid]']];
check($r->resolveBinding($b,$c)['value']==='9007199254740993.01','exact matching preserves zeros');
$c['values']=['sid'=>'missing'];
check($r->resolveBinding($b,$c)['state']==='absent','changed live key');
check($r->resolveBinding($b+['aggregate'=>'count'],$c)['value']==='0','count tests absent matches');
unset($c['values']);
check($r->resolveBinding(['field'=>'amount'],$c)['state']==='ambiguous','no implicit first');
check($r->resolveBinding(['field'=>'amount','aggregate'=>'sum'],$c)['value']==='9007199254740992','exact signed sum');
check($r->resolveBinding(['field'=>'amount','aggregate'=>'minimum'],$c)['value']==='-1.1','minimum');
check($r->resolveBinding(['field'=>'amount','aggregate'=>'maximum'],$c)['value']==='9007199254740993.01','maximum');
check($r->resolveBinding(['field'=>'amount','aggregate'=>'average'],$c)['denominator']==='3','average retains rational');
check(count($r->resolveBinding(['field'=>'amount','aggregate'=>'any'],$c)['members'])===3,'any members');
$c=['event'=>1,'instrument'=>'lab','instance'=>1];
check($r->resolveBinding(['field'=>'amount','aggregate'=>'count','excludeCurrent'=>true],$c)['value']==='2','exclude exact current');
check(ExactDecimal::sum(['-0.1','0.2'])['value']==='0.1','decimal cancellation');
check(ExactDecimal::sum(['0.0001','-0.0001'])['value']==='0','canonical zero');
check(ExactDecimal::sum(['1e3'])['state']==='invalid','no exponent');
check(ExactDecimal::sum([str_repeat('9',4097)])['state']==='limit','arithmetic bound');
$budget=new \INSPIRE\UniversalValidator\ReferenceBudget(2);
$first=new AddressResolver($shape,$node,10000,$budget);
$second=new AddressResolver($shape,$node,10000,$budget);
$c=['event'=>1,'instrument'=>'specimen','instance'=>1];
check($first->resolve(['ref','sid',null],$c)['state']==='ok','first shared-budget lookup');
check($second->resolve(['ref','sid',null],$c)['state']==='limit','budget shared across host resolvers');
echo "binding_php: $n checks, 0 failures\n";
