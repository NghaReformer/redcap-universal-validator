<?php
require_once __DIR__ . '/../php/Logic.php';
require_once __DIR__ . '/../php/AddressResolver.php';
use INSPIRE\UniversalValidator\Logic;
use INSPIRE\UniversalValidator\ProjectShape;
use INSPIRE\UniversalValidator\AddressResolver;
$n=0;
function check($ok, $label) { global $n; $n++; if (!$ok) throw new RuntimeException($label); }
$fixture=json_decode(file_get_contents(__DIR__.'/qualified_fixture.json'),true);
foreach ($fixture['valid'] as $v) {
 $p=Logic::parse($v['expr'],['qualified'=>true]);
 check($p['ok'],$v['expr']);
 check(Logic::evaluate($p['ast'],$v['values'])===$v['expect'],'evaluate '.$v['expr']);
 if (Logic::qualifiedRefs($p['ast'])) {
  check(!Logic::parse($v['expr'])['ok'],'opt in required');
  $threw=false;
  try { Logic::evaluate($p['ast'],[]); } catch (UnexpectedValueException $e) { $threw=true; }
  check($threw,'missing qualified value throws');
  $threw=false;
  try { Logic::fold($p['ast'],[],[]); } catch (LogicException $e) { $threw=true; }
  check($threw,'raw qualified reference cannot be folded');
 }
}
foreach ($fixture['invalid'] as $expr) check(!Logic::parse($expr,['qualified'=>true])['ok'],'reject '.$expr);
$events=[
 10=>['name'=>'base_arm_1','arm'=>1,'order'=>1,'forms'=>['base','lab'],'repeats'=>['lab'],'eventRepeats'=>false],
 20=>['name'=>'gap_arm_1','arm'=>1,'order'=>2,'forms'=>['base'],'repeats'=>[],'eventRepeats'=>false],
 30=>['name'=>'visit_arm_1','arm'=>1,'order'=>3,'forms'=>['base','lab','drug'],'repeats'=>['lab','drug'],'eventRepeats'=>false],
 40=>['name'=>'visit_arm_2','arm'=>2,'order'=>1,'forms'=>['base','lab'],'repeats'=>[],'eventRepeats'=>true]];
$fields=['weight'=>['form'=>'lab'],'dose'=>['form'=>'drug'],'age'=>['form'=>'base'],'cb'=>['form'=>'lab']];
$shape=new ProjectShape($events,$fields);
$record=[30=>['age'=>'20'],'repeat_instances'=>[10=>['lab'=>[1=>['weight'=>'48']]],30=>['lab'=>[1=>['weight'=>'50'],3=>['weight'=>'53'],4=>[]],'drug'=>[3=>['dose'=>'2']]],40=>[''=>[1=>['age'=>'30','weight'=>'60']]]]];
$resolver=new AddressResolver($shape,$record);
$ctx=['event'=>30,'instrument'=>'lab','instance'=>3];
function resolve($expr, $context=null) { global $resolver,$ctx; $p=Logic::parse($expr."='0'",['qualified'=>true]); return $resolver->resolve($p['ast'][2],$context===null?$ctx:$context); }
check(resolve('[weight][previous-instance]')['state']==='absent','previous does not skip missing 2');
check(resolve('[weight][first-instance]')['value']==='50','first');
check(resolve('[weight][last-instance]')['value']==='','last blank row exists');
check(resolve('[weight][last-instance]')['state']==='ok','saved blank not absent');
check(resolve('[previous-event-name][weight][1]')['value']==='48','relative skips undesignated event');
check(resolve('[dose]')['state']==='ambiguous','no implicit pairing across forms');
check(resolve('[dose][3]')['value']==='2','explicit pairing');
check(resolve('[age][3]')['state']==='invalid','base cannot take instance');
check(resolve('[visit_arm_2][weight][1]')['value']==='60','explicit other arm');
check(resolve('[weight][any-instance]')['members'][1]['self']===true,'collection keeps self');
$new=$ctx; $new['instance']=5; $new['unsaved']=true; $new['values']=['weight'=>'55'];
check(resolve('[weight][last-instance]',$new)['value']==='55','new self index and value overlay');
check(count(resolve('[weight][all-instances]',$new)['members'])===4,'self included exactly once');
check(resolve('[cb(01)][4]')['value']==='0','blank checkbox member');
check(resolve('[weight][999999999999999999999]')['state']==='invalid','overflow rejected');
$events[30]['forms']=null;
$bad=new AddressResolver(new ProjectShape($events,$fields),$record);
check($bad->resolve(['qref','weight',null,null,'3'],$ctx)['state']==='unreadable','metadata unknown');
$limited=new AddressResolver($shape,$record,2);
check($limited->resolve(['qref','weight',null,null,'any-instance'],$ctx)['state']==='limit','no truncated collection verdict');
echo "qualified_php: $n checks, 0 failures\n";
