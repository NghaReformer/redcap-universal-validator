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

// Wargame C2: an event row, or an instance of a repeating event, is a form's
// entry only where that form was entered there. getData returns the row when
// any form saved data in it, with blanks (and a blank status, asked for with
// returnBlankForGrayFormStatus) for the forms nobody opened.
$shape=new ProjectShape([
    1=>['name'=>'base_arm_1','arm'=>1,'order'=>0,'forms'=>['demo','lab'],'repeats'=>[],'eventRepeats'=>false],
    2=>['name'=>'fu_arm_1','arm'=>1,'order'=>1,'forms'=>['demo','lab'],'repeats'=>[],'eventRepeats'=>false],
    3=>['name'=>'diary_arm_1','arm'=>1,'order'=>2,'forms'=>['meas','lab'],'repeats'=>[],'eventRepeats'=>true]],
 ['record_id'=>['form'=>'demo'],'age'=>['form'=>'demo'],'lab_date'=>['form'=>'lab'],'lab_tests'=>['form'=>'lab','type'=>'checkbox'],'w'=>['form'=>'meas']],true,'record_id');
$demo=['event'=>1,'instrument'=>'demo','instance'=>1,'values'=>[],'unsaved'=>false];
$out=function($x){return $x['state']==='ok'?$x['value']:'!'.$x['state'];};
$blankLab=['lab_date'=>'','lab_tests'=>['1'=>'0','2'=>'0'],'lab_complete'=>''];
$r=new AddressResolver($shape,[1=>['record_id'=>'1','age'=>'30']+$blankLab,2=>['record_id'=>'1','age'=>'31']+$blankLab]);
check($out($r->resolveBinding(['field'=>'lab_date','event'=>'fu_arm_1','aggregate'=>'exists'],$demo))==='0','C2: exists is 0 for a form never entered in an event other forms saved data in');
check($out($r->resolveBinding(['field'=>'lab_date','events'=>['base_arm_1','fu_arm_1'],'aggregate'=>'count'],$demo))==='0','C2: count over events is 0 for a form never entered, the asker\'s own event included');
check($out($r->resolve(['qref','lab_date',null,'fu_arm_1',null],$demo))==='!absent','C2: another event\'s field of a form never entered there is a missing row');
$none=new AddressResolver($shape,[1=>['record_id'=>'1','age'=>'30']+$blankLab]);
check($out($none->resolve(['qref','lab_date',null,'fu_arm_1',null],$demo))==='!absent','C2: ... the same answer as for an event with no row at all');
check($out($r->resolve(['ref','lab_date',null],$demo))==='','C2: the asker\'s own row still reads another form\'s field as blank, as REDCap\'s logic does');
$entered=function(array $lab)use($shape,$demo,$out){
    $r=new AddressResolver($shape,[1=>['record_id'=>'1','age'=>'30'],2=>['record_id'=>'1','age'=>'31']+$lab]);
    return $out($r->resolveBinding(['field'=>'lab_date','event'=>'fu_arm_1','aggregate'=>'count'],$demo));
};
check($entered(['lab_date'=>'','lab_tests'=>['1'=>'0','2'=>'0'],'lab_complete'=>'0'])==='1','C2: a form saved with every field blank is an entry (its status says so)');
check($entered(['lab_date'=>'2024-01-01','lab_tests'=>['1'=>'0','2'=>'0'],'lab_complete'=>''])==='1','C2: a value with no status is an entry (an import that left the status out)');
check($entered(['lab_date'=>'','lab_tests'=>['1'=>'0','2'=>'1'],'lab_complete'=>''])==='1','C2: a ticked checkbox code is an entry');
check($entered(['lab_date'=>'  ','lab_tests'=>['1'=>'0','2'=>'0']])==='0','C2: whitespace and unticked boxes are not, nor is a missing status');
$pkOnly=new AddressResolver($shape,[1=>['record_id'=>'1','age'=>'30'],2=>['record_id'=>'1','age'=>'','demo_complete'=>'','lab_date'=>'2024-01-01','lab_complete'=>'2']]);
check($out($pkOnly->resolveBinding(['field'=>'age','event'=>'fu_arm_1','aggregate'=>'exists'],$demo))==='0','C2: the record id, stored in every row, does not make its form an entry');
$own=['event'=>2,'instrument'=>'lab','instance'=>1,'values'=>['lab_date'=>''],'unsaved'=>true];
check($out($r->resolveBinding(['field'=>'lab_date','events'=>['base_arm_1','fu_arm_1'],'aggregate'=>'count'],$own))==='1','C2: the entry being typed counts before anything of it is saved');
// A repeating event: lab entered in instances 1 and 3, instance 2 holds only meas.
$diary=['repeat_instances'=>[3=>[''=>[
    1=>['record_id'=>'1','lab_date'=>'2024-01-01','lab_complete'=>'2','w'=>'','meas_complete'=>''],
    2=>['record_id'=>'1','lab_date'=>'','lab_complete'=>'','w'=>'5','meas_complete'=>'2'],
    3=>['record_id'=>'1','lab_date'=>'2024-03-01','lab_complete'=>'1','w'=>'','meas_complete'=>'']]]]];
foreach([false,true] as $shared){
    $r=new AddressResolver($shape,$diary);if($shared)$r->shareAcrossContexts();$tag=$shared?' (shared)':'';
    check($out($r->resolveBinding(['field'=>'lab_date','event'=>'diary_arm_1','aggregate'=>'count'],$demo))==='2','C2: a repeating event counts only the instances the form was entered in'.$tag);
    check($out($r->resolveBinding(['field'=>'w','event'=>'diary_arm_1','aggregate'=>'count'],$demo))==='1','C2: ... per form, over the same instances'.$tag);
    $m=$r->resolve(['qref','lab_date',null,'diary_arm_1','all-instances'],$demo);
    check(isset($m['members'])&&array_map(function($x){return $x['location']['instance'];},$m['members'])===[1,3],'C2: all-instances holds the entered instances only'.$tag);
    $meas=['event'=>3,'instrument'=>'meas','instance'=>2,'values'=>[],'unsaved'=>false];
    check($out($r->resolve(['qref','lab_date',null,null,'previous-instance'],$meas))==='2024-01-01','C2: previous-instance of an entry is the instance before it'.$tag);
    check($out($r->resolve(['qref','w',null,null,'next-instance'],$meas))==='!absent','C2: an instance the form was not entered in is a missing row'.$tag);
    check($out($r->resolve(['ref','lab_date',null],$meas))==='','C2: the host\'s own instance reads another form\'s field as blank'.$tag);
}
echo "binding_php: $n checks, 0 failures\n";
