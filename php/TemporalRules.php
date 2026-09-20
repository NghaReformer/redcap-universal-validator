<?php
namespace INSPIRE\UniversalValidator;
require_once __DIR__.'/TemporalLogic.php';
require_once __DIR__.'/AddressResolver.php';

/** Pure compilation and dependency discovery. No compiled metadata is persisted on rules. */
final class TemporalRules
{
    const VERSION=1;
    public static function extended(array $rule)
    {
        if(isset($rule['references'])||($rule['uniqueScope']??null)==='record')return true;
        foreach(['when','assert'] as $key)if(isset($rule[$key])){$p=Logic::parse($rule[$key],['qualified'=>true]);if(!empty($p['ok'])&&Logic::qualifiedRefs($p['ast']))return true;}
        foreach($rule['branches']??[] as $b)if(self::extended($b))return true;
        return false;
    }
    public static function fields(array $rule)
    {
        $out=array_merge($rule['fields']??[],$rule['uniqueWith']??[]);
        foreach(['when','assert'] as $key)if(isset($rule[$key])){
            $p=Logic::parse($rule[$key],['qualified'=>true]);if(empty($p['ok']))continue;
            foreach(Logic::referencedFields($p['ast']) as $r)$out[]=$r[0];
            foreach(Logic::qualifiedRefs($p['ast']) as $r)if($r[0]==='qref')$out[]=$r[1];
        }
        foreach($rule['references']??[] as $b)if(is_array($b)){
            if(isset($b['field'])&&is_string($b['field']))$out[]=$b['field'];
            foreach(is_array($b['match']??null)?$b['match']:[] as $target=>$source){$out[]=$target;if(is_string($source)&&preg_match('/^\[([a-z][a-z0-9_]*)\]$/D',(string)$source,$m))$out[]=$m[1];}
            if(isset($b['elapsedFrom'])&&is_string($b['elapsedFrom'])){$p=Logic::parse($b['elapsedFrom']."=''",['qualified'=>true]);if(!empty($p['ok'])){foreach(Logic::referencedFields($p['ast']) as $r)$out[]=$r[0];foreach(Logic::qualifiedRefs($p['ast']) as $r)if($r[0]==='qref')$out[]=$r[1];}}
        }
        foreach($rule['branches']??[] as $b)$out=array_merge($out,self::fields($b));
        return array_values(array_unique($out));
    }
    public static function validate(array $rule)
    {
        $errors=[];$refs=$rule['references']??[];
        if(!is_array($refs)||count($refs)>20)return ['references must be an object with at most 20 bindings.'];
        foreach($refs as $alias=>$b){
            if(!is_string($alias)||!preg_match('/^[a-z][a-z0-9_]*$/D',$alias)||!is_array($b)){$errors[]='Invalid reference binding.';continue;}
            $allowed=['field','event','events','arm','instance','match','aggregate','excludeCurrent','type','elapsedFrom','unit'];
            if(array_diff(array_keys($b),$allowed)||empty($b['field'])||!is_string($b['field'])||!preg_match('/^[a-z][a-z0-9_]*$/D',$b['field']))$errors[]='Binding '.$alias.' has an invalid field or option.';
            if(isset($b['event'])&&(!is_string($b['event'])||isset($b['events'])))$errors[]='Binding '.$alias.' must select event or events, not both.';
            if(isset($b['events'])&&!(is_array($b['events'])||$b['events']==='arm'))$errors[]='Binding '.$alias.' events must be a list or "arm".';
            if(($b['events']??null)==='arm'&&!isset($b['arm']))$errors[]='Binding '.$alias.' needs an explicit arm.';
            if(isset($b['aggregate'])&&!in_array($b['aggregate'],['count','exists','populated-count','distinct-count','sum','minimum','maximum','average','any','all'],true))$errors[]='Binding '.$alias.' has an invalid aggregate.';
            if(isset($b['type'])&&!in_array($b['type'],['date','datetime','datetime_seconds'],true))$errors[]='Binding '.$alias.' has an invalid date type.';
            if(isset($b['type'])&&(isset($b['aggregate'])||in_array($b['instance']??null,['any-instance','all-instances'],true)))$errors[]='Typed dates cannot be aggregated; compare scalar dates or elapsed times.';
            if(isset($b['elapsedFrom'])&&(!isset($b['type'])||!is_string($b['elapsedFrom'])||!in_array($b['unit']??null,['days','hours','minutes','seconds'],true)))$errors[]='Elapsed bindings need type, elapsedFrom and a supported unit.';
            if(isset($b['match'])&&!is_array($b['match']))$errors[]='Binding '.$alias.' match must be an object.';
            if(isset($b['excludeCurrent'])&&!is_bool($b['excludeCurrent']))$errors[]='excludeCurrent must be Boolean.';
            if(isset($b['event']) && is_string($b['event']) && !preg_match('/^[a-z][a-z0-9_-]*$/D',(string)$b['event']))$errors[]='Binding '.$alias.' has an invalid event.';
            if(isset($b['events']) && is_array($b['events']))foreach($b['events'] as $event)if(!is_string($event)||!preg_match('/^[a-z][a-z0-9_-]*$/D',$event)){$errors[]='Binding '.$alias.' has an invalid event list.';break;}
            if(isset($b['arm']) && (!is_scalar($b['arm']) || !preg_match('/^[1-9][0-9]*$/D',(string)$b['arm'])))$errors[]='Binding '.$alias.' needs a positive arm number.';
            if(isset($b['instance']) && ((!is_int($b['instance'])&&!is_string($b['instance'])) ||
                (!preg_match('/^[1-9][0-9]*$/D',(string)$b['instance'])&&!in_array($b['instance'],['current-instance','previous-instance','next-instance','first-instance','last-instance','any-instance','all-instances'],true))))$errors[]='Binding '.$alias.' has an invalid instance selector.';
            if(isset($b['match'])&&is_array($b['match']))foreach($b['match'] as $target=>$source)if(!is_string($target)||!preg_match('/^[a-z][a-z0-9_]*$/D',$target)||!is_string($source)||!preg_match('/^\[([a-z][a-z0-9_]*)\]$/D',$source)){$errors[]='Binding '.$alias.' matching keys need field names and plain source references.';break;}
            if(!empty($b['match'])&&isset($b['instance']))$errors[]='Binding '.$alias.' cannot combine matching keys with an instance selector.';
            if(isset($b['unit'])&&!isset($b['elapsedFrom']))$errors[]='Binding '.$alias.' unit needs elapsedFrom.';
            if(isset($b['elapsedFrom'])&&is_string($b['elapsedFrom'])){
                $parsed=Logic::parse($b['elapsedFrom']."=''",['qualified'=>true]);
                if(empty($parsed['ok'])||$parsed['ast'][0]!=='cmp'||!in_array($parsed['ast'][2][0],['ref','qref'],true)
                    ||($parsed['ast'][2][0]==='qref'&&in_array($parsed['ast'][2][4],['any-instance','all-instances'],true)))$errors[]='Binding '.$alias.' elapsedFrom must be one scalar reference.';
            }

        }
        foreach(['when','assert'] as $key)if(isset($rule[$key])){
            $p=Logic::parse($rule[$key],['qualified'=>true]);if(empty($p['ok']))continue;
            foreach(Logic::qualifiedRefs($p['ast']) as $r)if($r[0]==='binding'&&!array_key_exists($r[1],$refs))$errors[]='Undefined reference binding {'.$r[1].'}.';
            $collection=function($operand)use($refs){
                return ($operand[0]==='qref'&&in_array($operand[4],['any-instance','all-instances'],true))
                    ||($operand[0]==='binding'&&(in_array($refs[$operand[1]]['aggregate']??null,['any','all'],true)||in_array($refs[$operand[1]]['instance']??null,['any-instance','all-instances'],true)));
            };
            $check=function($tree)use(&$check,$collection,&$errors){
                if($tree[0]==='cmp'&&$collection($tree[2])&&$collection($tree[3]))$errors[]='A comparison accepts only one collection operand.';
                elseif($tree[0]==='not')$check($tree[1]);
                elseif(in_array($tree[0],['and','or'],true))foreach($tree[1] as $child)$check($child);
            };$check($p['ast']);

        }
        return $errors;
    }

    /** Events needed for one rendered host. null means metadata cannot narrow safely. */
    public static function eventIds(array $rule,ProjectShape $shape,$current)
    {
        if(!$shape->event($current))return null;
        $ids=[$current=>true];$unknown=false;
        $add=function($field,$token)use($shape,$current,&$ids,&$unknown){
            $meta=$shape->field($field);if(!$meta){$unknown=true;return;}
            $event=$shape->eventId($token,$current,$meta['form']);
            if($event['state']==='ok')$ids[$event['event']]=true;
            elseif($event['state']==='unreadable')$unknown=true;
        };
        foreach(['when','assert'] as $key)if(isset($rule[$key])){
            $p=Logic::parse($rule[$key],['qualified'=>true]);if(empty($p['ok']))continue;
            foreach(Logic::qualifiedRefs($p['ast']) as $ref)if($ref[0]==='qref')$add($ref[1],$ref[3]);
        }
        foreach($rule['references']??[] as $binding){
            if(!is_array($binding)||!isset($binding['field']))continue;
            if(($binding['events']??null)==='arm'){
                foreach($shape->events() as $id=>$event){if(!isset($event['arm'])){$unknown=true;continue;}
                    if((string)$event['arm']===(string)($binding['arm']??''))$ids[$id]=true;}
            }else foreach($binding['events']??[$binding['event']??null] as $token)$add($binding['field'],$token);
            if(isset($binding['elapsedFrom'])){
                $p=Logic::parse($binding['elapsedFrom']."=''",['qualified'=>true]);
                if(!empty($p['ok']))foreach(Logic::qualifiedRefs($p['ast']) as $ref)if($ref[0]==='qref')$add($ref[1],$ref[3]);
            }
        }
        if(($rule['uniqueScope']??null)==='record')foreach($shape->events() as $id=>$event){
            if(!isset($event['forms'])){$unknown=true;continue;}
            foreach($rule['fields']??[] as $field)if(in_array($shape->field($field)['form']??null,$event['forms'],true))$ids[$id]=true;
        }
        foreach($rule['branches']??[] as $branch){
            $flat=array_merge($rule,$branch);unset($flat['branches']);$events=self::eventIds($flat,$shape,$current);
            if($events===null)$unknown=true;else foreach($events as $id)$ids[$id]=true;
        }
        return $unknown?null:array_keys($ids);
    }

    /** Metadata-only preflight; unavailable metadata is left to the resolver. */
    public static function validateProject(array $rule, ProjectShape $shape)
    {
        $errors=[];$references=[];
        foreach(['when','assert'] as $key)if(isset($rule[$key])){
            $p=Logic::parse($rule[$key],['qualified'=>true]);
            if(!empty($p['ok']))foreach(Logic::qualifiedRefs($p['ast']) as $ref)if($ref[0]==='qref')$references[]=[$ref[1],$ref[3],$ref[4]];
        }
        foreach($rule['references']??[] as $alias=>$binding)if(is_array($binding)&&is_string($binding['field']??null)){
            $field=$binding['field'];$meta=$shape->field($field);
            if(($meta['type']??null)==='checkbox')$errors[]='Binding '.$alias.' needs a scalar field; use a qualified checkbox-code reference for checkbox values.';
            if(isset($binding['type'])&&$meta){
                $validation=$meta['validation']??'';
                $expected=strpos($validation,'datetime_seconds_')===0?'datetime_seconds':(strpos($validation,'datetime_')===0?'datetime':(strpos($validation,'date_')===0?'date':null));
                if($expected!==$binding['type'])$errors[]='Binding '.$alias.' date type must match the field validation type.';
            }
            foreach(is_array($binding['match']??null)?$binding['match']:[] as $target=>$source){
                $key=$shape->field($target);
                if(!$key||!$meta||$key['form']!==$meta['form']||($key['type']??null)==='checkbox')$errors[]='Binding '.$alias.' needs scalar matching keys on the target instrument.';
            }
            if(($binding['events']??null)==='arm'&&$meta&&$shape->events()){
                // A misspelt arm matched no event and answered a confident count of 0.
                $collected=false;
                foreach($shape->events() as $event)if((string)($event['arm']??'')===(string)($binding['arm']??'')&&in_array($meta['form'],is_array($event['forms']??null)?$event['forms']:[],true))$collected=true;
                if(!$collected)$errors[]='Binding '.$alias.' names arm '.(is_scalar($binding['arm']??null)?$binding['arm']:'?').', where no event collects this instrument.';
            }
            $events=is_array($binding['events']??null)?$binding['events']:[$binding['event']??null];
            foreach($events as $event)$references[]=[$field,$event,$binding['instance']??null];
        }
        foreach($references as $ref){
            list($field,$event,$instance)=$ref;
            if($event===null||!is_string($event)||in_array($event,['event-name','previous-event-name','next-event-name','first-event-name','last-event-name'],true))continue;
            $meta=$shape->field($field);if(!$meta||!$shape->events())continue;
            $resolved=$shape->eventId($event,null,$meta['form']);
            if($resolved['state']!=='ok'){$errors[]='Unknown event reference: '.$event.'.';continue;}
            $bucket=$shape->bucket($resolved['event'],$meta['form']);
            if($bucket['state']==='missing')$errors[]='Referenced instrument is not designated in '.$event.'.';
            if($bucket['state']==='ok'&&$bucket['bucket']===null&&$instance!==null)$errors[]='Instance selector targets a non-repeating instrument in '.$event.'.';
        }
        foreach($rule['branches']??[] as $branch)$errors=array_merge($errors,self::validateProject($branch,$shape));
        return array_values(array_unique($errors));
    }

    /** Compile operands to plain live refs or permitted snapshots. $browser=false emits literals only. */
    public static function compile(array $rule,AddressResolver $resolver,ProjectShape $shape,array $context,$browser=false,?callable $mayRead=null)
    {
        $snapshot=false;$problems=[];$live=false;$denied=false;
        $member=function(array $r,$typed=null)use($context,$browser,$mayRead,$shape,&$snapshot,&$live,&$denied,&$problems){
            if(($r['state']??null)!=='ok'){$problems[]=$r['state']??'unreadable';return ['unknown'];}
            $loc=$r['location'];$onPage=$browser&&!empty($r['self']);
            if($onPage){$live=true;$node=['ref',$loc['field'],$loc['code']];}
            else{$snapshot=$snapshot||$browser;if($browser&&$mayRead&&!$mayRead($loc['instrument']))$denied=true;$node=['lit',$r['value']];}
            if($typed){$format='ymd';if($onPage){$v=$shape->field($loc['field'])['validation']??'';if(substr($v,-3)==='dmy')$format='dmy';elseif(substr($v,-3)==='mdy')$format='mdy';}$node=['date',$typed,$format,$node];}
            return $node;
        };
        $operand=function(array $op)use(&$operand,$rule,$resolver,$context,$member,$browser,$mayRead,&$live,&$denied,&$problems){
            if($op[0]==='lit')return $op;
            if($op[0]==='binding'){
                $b=$rule['references'][$op[1]]??null;if(!is_array($b)){$problems[]='invalid';return ['unknown'];}
                $type=$b['type']??null;$from=$b['elapsedFrom']??null;$unit=$b['unit']??null;unset($b['type'],$b['elapsedFrom'],$b['unit']);
                $r=$resolver->resolveBinding($b,$context);if($r['state']!=='ok'){$problems[]=$r['state'];return ['unknown'];}
                if(isset($b['aggregate'])&&!$browser&&!in_array($b['aggregate'],['any','all'],true)){
                    // Saved data has no live member, so the resolver's exact answer is final;
                    // rebuilding it from the member list repeated the whole sum per host context.
                    $node=isset($r['numerator'])?['value',['numerator'=>$r['numerator'],'denominator'=>$r['denominator']]]:['lit',$r['value']];
                }
                elseif(isset($b['aggregate'])){$members=[];foreach($r['members']??[] as $m)$members[]=$member($m);$kind=$b['aggregate'];$node=[in_array($kind,['any','all'],true)?'set':'aggregate',$kind,$members];}
                elseif(isset($r['members'])){$members=[];foreach($r['members'] as $m)$members[]=$member($m);$node=['set',$r['quantifier'],$members];}
                else $node=$member($r,$type);
                if($from!==null){$p=Logic::parse($from."=''",['qualified'=>true]);if(empty($p['ok'])||!in_array($p['ast'][2][0],['ref','qref'],true)){$problems[]='invalid';return ['unknown'];}$node=['elapsed',$unit,$member($resolver->resolve($p['ast'][2],$context),$type),$node];}
                $guards=[];foreach($b['match']??[] as $source){preg_match('/^\[([a-z][a-z0-9_]*)\]$/D',$source,$m);if(isset($m[1])){$v=$resolver->resolve(['ref',$m[1],null],$context);if($browser && empty($v['self']) && $mayRead && isset($v['location']) && !$mayRead($v['location']['instrument']))$denied=true;if($browser&&!empty($v['self'])){$live=true;$guards[]=[$m[1],$v['value']];}}}
                return $guards?['guard',$guards,$node]:$node;
            }
            $r=$resolver->resolve($op,$context);
            if(isset($r['members'])){$members=[];foreach($r['members'] as $m)$members[]=$member($m);return ['set',$r['quantifier'],$members];}
            return $member($r);
        };
        $walk=function(array $ast)use(&$walk,$operand){
            if($ast[0]==='cmp')return ['cmp',$ast[1],$operand($ast[2]),$operand($ast[3])];
            if($ast[0]==='not')return ['not',$walk($ast[1])];
            if($ast[0]==='and'||$ast[0]==='or')return [$ast[0],array_map($walk,$ast[1])];return $ast;
        };
        $compiled=$rule;
        foreach(['when','assert'] as $key)if(isset($rule[$key])){
            if(!$browser && $key==='assert' && ($compiled['when']??null)==='1=0' && !$problems)continue;
            $p=Logic::parse($rule[$key],['qualified'=>true]);if(empty($p['ok'])){$problems[]='invalid';continue;}
            $tree=$walk($p['ast']);
            // The browser evaluates its own tree; only saved-data callers need the verdict here.
            $value=$browser?null:TemporalLogic::evaluate($tree,function($f,$c)use($context){$v=$context['values'][$f]??'';return $c===null?$v:(is_array($v)&&($v[$c]??'0')==='1'?'1':'0');},$key==='assert',!empty($rule['caseSensitive']));
            if(!$browser){if($key==='assert')$compiled['_temporalAssertLabel']=$rule[$key];if($value===null)$problems[]='unresolved';$compiled[$key]=$value?'1=1':'1=0';unset($compiled[$key.'Ast']);}
            else $compiled[$key.'Ast']=['temporal',$tree];
        }
        // A survey/no-rights comparison with no live operands may disclose only its Boolean result.
        if($browser&&$denied){
            if($live)$problems[]='unauthorized';
            else foreach(['when','assert'] as $key)if(isset($compiled[$key.'Ast'])){$v=TemporalLogic::evaluate($compiled[$key.'Ast'],function(){return '';},$key==='assert',!empty($rule['caseSensitive']));$compiled[$key.'Ast']=$v===null?['unknown']:['const',$v];if($v===null)$problems[]='unresolved';}
        }
        if($problems){$compiled['deferred']=true;$compiled['deferredWhy']=['Extended reference unavailable: '.implode(', ',array_unique($problems)).'.'];if($browser)foreach(['when','assert'] as $key)if(isset($compiled[$key.'Ast']))$compiled[$key.'Ast']=['const',false];}
        if($snapshot)$compiled['snapshotFields']=['saved event/instance values'];
        if($browser){unset($compiled['references']);$compiled['blockSave']='off';}
        return ['rule'=>$compiled,'problems'=>array_values(array_unique($problems))];
    }
}
