<?php
namespace INSPIRE\UniversalValidator;
require_once __DIR__.'/TemporalLogic.php';
require_once __DIR__.'/AddressResolver.php';
require_once __DIR__.'/ModeRegistry.php';

/** Pure compilation and dependency discovery. No compiled metadata is persisted on rules. */
final class TemporalRules
{
    const VERSION=1;
    public static function extended(array $rule)
    {
        if(isset($rule['references'])||($rule['uniqueScope']??null)==='record')return true;
        foreach(ModeRegistry::condKeys() as $key)if(isset($rule[$key])){$p=Logic::parse($rule[$key],['qualified'=>true]);if(!empty($p['ok'])&&Logic::qualifiedRefs($p['ast']))return true;}
        foreach(self::operands($rule) as list(,$op))if($op[0]==='qref')return true;
        foreach($rule['branches']??[] as $b)if(self::extended($b))return true;
        return false;
    }
    /** The operand references ("from" of @UVWINDOW, ...) of one rule node: [registry entry, parsed ref or qref] pairs. */
    private static function operands(array $rule)
    {
        $out=[];
        foreach(ModeRegistry::operandKeys() as $rk)if(isset($rule[$rk['key']])){$op=ModeRegistry::operandRef($rule[$rk['key']],['qualified'=>true]);if($op!==null)$out[]=[$rk,$op];}
        return $out;
    }
    /**
     * Every field one rule reads. $lookups false leaves out the field lists a
     * rule only searches (refKeys role "lookup", such as @UVUNIQUE "also"):
     * saving one of those does not re-check the rule.
     */
    public static function fields(array $rule, $lookups = true)
    {
        $out=$rule['fields']??[];foreach(ModeRegistry::refKeys('fieldList') as $rk){if(!$lookups&&($rk['role']??null)==='lookup')continue;$out=array_merge($out,is_array($rule[$rk['key']]??null)?$rule[$rk['key']]:[]);}
        foreach(ModeRegistry::condKeys() as $key)if(isset($rule[$key])){
            $p=Logic::parse($rule[$key],['qualified'=>true]);if(empty($p['ok']))continue;
            foreach(Logic::referencedFields($p['ast']) as $r)$out[]=$r[0];
            foreach(Logic::qualifiedRefs($p['ast']) as $r)if($r[0]==='qref')$out[]=$r[1];
        }
        foreach(self::operands($rule) as list(,$op))$out[]=$op[1];
        foreach($rule['references']??[] as $b)if(is_array($b)){
            if(isset($b['field'])&&is_string($b['field']))$out[]=$b['field'];
            foreach(is_array($b['match']??null)?$b['match']:[] as $target=>$source){$out[]=$target;if(is_string($source)&&preg_match('/^\[([a-z][a-z0-9_]*)\]$/D',(string)$source,$m))$out[]=$m[1];}
            if(isset($b['elapsedFrom'])&&is_string($b['elapsedFrom'])){$p=Logic::parse($b['elapsedFrom']."=''",['qualified'=>true]);if(!empty($p['ok'])){foreach(Logic::referencedFields($p['ast']) as $r)$out[]=$r[0];foreach(Logic::qualifiedRefs($p['ast']) as $r)if($r[0]==='qref')$out[]=$r[1];}}
        }
        foreach($rule['branches']??[] as $b)$out=array_merge($out,self::fields($b,$lookups));
        return array_values(array_unique($out));
    }
    /**
     * The fields a rule reads in entries other than its host context's own row: a
     * qualified reference, a binding (its field, its match targets, a qualified
     * elapsedFrom) and, for record-scope uniqueness, its own fields. A save of an
     * instrument none of these name changes only the host contexts that share the
     * saved row, so the save audit re-checks just those (wargame P4).
     */
    public static function crossFields(array $rule)
    {
        $out=($rule['uniqueScope']??null)==='record'?($rule['fields']??[]):[];
        foreach(ModeRegistry::condKeys() as $key)if(isset($rule[$key])){
            $p=Logic::parse($rule[$key],['qualified'=>true]);if(empty($p['ok']))continue;
            foreach(Logic::qualifiedRefs($p['ast']) as $r)if($r[0]==='qref')$out[]=$r[1];
        }
        foreach(self::operands($rule) as list(,$op))if($op[0]==='qref')$out[]=$op[1];
        foreach($rule['references']??[] as $b)if(is_array($b)){
            if(isset($b['field'])&&is_string($b['field']))$out[]=$b['field'];
            foreach(is_array($b['match']??null)?$b['match']:[] as $target=>$source)$out[]=$target;
            if(isset($b['elapsedFrom'])&&is_string($b['elapsedFrom'])){$p=Logic::parse($b['elapsedFrom']."=''",['qualified'=>true]);if(!empty($p['ok']))foreach(Logic::qualifiedRefs($p['ast']) as $r)if($r[0]==='qref')$out[]=$r[1];}
        }
        foreach($rule['branches']??[] as $b)$out=array_merge($out,self::crossFields($b));
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
            if(isset($b['events']) && is_array($b['events'])){
                $listed=[];
                foreach($b['events'] as $event){
                    if(!is_string($event)||!preg_match('/^[a-z][a-z0-9_-]*$/D',$event)){$errors[]='Binding '.$alias.' has an invalid event list.';break;}
                    // A repeat adds no member (members are de-duplicated) but was walked
                    // again for every host context: a 64 KB list of one relative token
                    // made a save's audit run for minutes (wargame P2).
                    if(isset($listed[$event])){$errors[]='Binding '.$alias.' lists event '.$event.' more than once.';break;}
                    $listed[$event]=true;
                }
            }
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
        foreach(ModeRegistry::condKeys() as $key)if(isset($rule[$key])){
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
        foreach(ModeRegistry::condKeys() as $key)if(isset($rule[$key])){
            $p=Logic::parse($rule[$key],['qualified'=>true]);if(empty($p['ok']))continue;
            foreach(Logic::qualifiedRefs($p['ast']) as $ref)if($ref[0]==='qref')$add($ref[1],$ref[3]);
        }
        foreach(self::operands($rule) as list(,$op))if($op[0]==='qref')$add($op[1],$op[3]);
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
        foreach(ModeRegistry::condKeys() as $key)if(isset($rule[$key])){
            $p=Logic::parse($rule[$key],['qualified'=>true]);
            if(!empty($p['ok']))foreach(Logic::qualifiedRefs($p['ast']) as $ref)if($ref[0]==='qref')$references[]=[$ref[1],$ref[3],$ref[4],true];
        }
        // [field, event, instance, true: reads one entry | false: a set or count | null: withheld when
        // unresolved, so the rest of the rule is checked without it].
        foreach(self::operands($rule) as list($rk,$op))if($op[0]==='qref')$references[]=[$op[1],$op[3],$op[4],ModeRegistry::withholds($rk,$rule)?null:true];
        foreach($rule['references']??[] as $alias=>$binding)if(is_array($binding)&&is_string($binding['field']??null)){
            $field=$binding['field'];$meta=$shape->field($field);
            if(($meta['type']??null)==='checkbox')$errors[]='Binding '.$alias.' needs a scalar field; use a qualified checkbox-code reference for checkbox values.';
            if(isset($binding['type'])&&$meta){
                $validation=$meta['validation']??'';
                $tv=TemporalValue::fromValidation($validation);$expected=$tv?$tv['type']:null;
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
            $one=!isset($binding['aggregate'])&&empty($binding['match'])&&!isset($binding['events'])&&empty($binding['excludeCurrent']);
            foreach($events as $event)$references[]=[$field,$event,$binding['instance']??null,$one];
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
        // A branch has no fields of its own: a reference only it reads sets aside that branch alone.
        $errors=array_merge($errors,self::unresolvable($references,$rule['fields']??[],$shape));
        foreach($rule['branches']??[] as $branch)$errors=array_merge($errors,self::validateProject($branch,$shape));
        return array_values(array_unique($errors));
    }

    /**
     * References that no host context of the rule can resolve, which a page or a save
     * could only defer, every time (wargame C3). A relative instance needs the host's
     * entry and its target to repeat. A reference that reads one entry of a repeating
     * instrument without an instance selector must be the host's own entry. A host
     * context is an event that collects the instrument of one of $hostFields; one
     * where the event reference itself does not resolve is no evidence either way.
     * A withheld operand (null) stops only its own part of the rule, so it is left to
     * the run time, as is a branch, whose failure sets aside that branch alone.
     */
    private static function unresolvable(array $references,$hostFields,ProjectShape $shape)
    {
        $hosts=[];
        foreach(is_array($hostFields)?$hostFields:[] as $hostField){
            $form=$shape->field($hostField)['form']??null;if($form===null)continue;
            foreach($shape->events() as $id=>$event){$b=$shape->bucket($id,$form);if($b['state']==='ok')$hosts[]=[$id,$b['bucket']];}
        }
        $errors=[];
        if($hosts)foreach($references as $ref){
            list($field,$event,$instance,$one)=$ref;
            if($one===null)continue;
            $relative=in_array($instance,['current-instance','previous-instance','next-instance'],true);
            if(!$relative&&!($one&&$instance===null))continue;
            $form=$shape->field($field)['form']??null;if($form===null)continue;
            $judged=false;$fine=false;$repeats=false;
            foreach($hosts as list($id,$hostBucket)){
                $target=$event===null?['state'=>'ok','event'=>$id]:$shape->eventId($event,$id,$form);
                if($target['state']!=='ok')continue;
                $bucket=$shape->bucket($target['event'],$form);if($bucket['state']!=='ok')continue;
                $judged=true;
                if($relative){$repeats=$repeats||$hostBucket!==null;$fine=$fine||($hostBucket!==null&&$bucket['bucket']!==null);}
                else $fine=$fine||$bucket['bucket']===null||((string)$target['event']===(string)$id&&$bucket['bucket']===$hostBucket);
            }
            if(!$judged||$fine)continue;
            if(!$relative)$errors[]='Reference to ['.$field.']'.($event===null?'':' in '.$event).' is ambiguous: its instrument repeats there, so it needs an instance selector.';
            elseif(!$repeats)$errors[]='Relative instance selector '.$instance.' needs a repeating source: the instrument of this rule does not repeat in any event.';
            else $errors[]='Relative instance selector '.$instance.' targets a non-repeating instrument.';
        }
        return $errors;
    }

    /** Whether a compiled condition reads a value live on the page: a plain reference or a guard's key. */
    private static function readsLive(array $node)
    {
        if(($node[0]??null)==='ref'||($node[0]??null)==='guard')return true;
        foreach($node as $child)if(is_array($child)&&self::readsLive($child))return true;
        return false;
    }

    /** Compile operands to plain live refs or permitted snapshots. $browser=false emits literals only. */
    /** The text mode of $rule's condition $key: Logic::EXACT for a test of an "exact" rule, else caseSensitive. */
    private static function textMode(array $rule,$key)
    {
        if(!empty($rule['exact'])&&in_array($key,ModeRegistry::condKeys('test'),true))return Logic::EXACT;
        return !empty($rule['caseSensitive']);
    }

    public static function compile(array $rule,AddressResolver $resolver,ProjectShape $shape,array $context,$browser=false,?callable $mayRead=null)
    {
        $snapshot=false;$problems=[];$live=false;$denied=false;$matched=false;
        // Inside a condition, a value that cannot be resolved is an unknown the and/or may
        // still decide around: "[flag]='1' or [result][previous-instance]='1'" is true on
        // instance 1 when flag is 1 (wargame C5). Its reason waits in $pending and becomes a
        // problem only when the condition stays undecided. An invalid reference is always one.
        $cond=false;$pending=[];
        $unresolved=function($state)use(&$cond,&$pending,&$problems){if($cond&&$state!=='invalid')$pending[]=$state;else $problems[]=$state;};
        // Whether the page may not read $form. Then nothing of its entries may reach the page,
        // not even whether one exists or how many match: a protected read that is empty,
        // missing or ambiguous counts as denied, and its reason is "unresolved" whatever it
        // was. Each gave a different answer where one match was refused (wargame S1).
        $protected=function($form)use($browser,$mayRead){return $browser&&$mayRead&&!$mayRead($form);};
        $member=function(array $r,$typed=null)use($context,$browser,$mayRead,$shape,&$snapshot,&$live,&$denied,&$problems,$unresolved){
            if(($r['state']??null)!=='ok'){$unresolved($r['state']??'unreadable');return ['unknown'];}
            $loc=$r['location'];$onPage=$browser&&!empty($r['self']);
            if($onPage){$live=true;$node=['ref',$loc['field'],$loc['code']];}
            else{$snapshot=$snapshot||$browser;if($browser&&$mayRead&&!$mayRead($loc['instrument']))$denied=true;
                // A field that holds numbers is read as its number (12,5 as 12.5), as Logic::evaluate reads it (AST-1).
                $fm=$shape->field($loc['field']);$mark=is_array($fm)?Logic::numberMark($fm['type']??'',$fm['validation']??''):null;
                $node=['lit',($mark!==null&&($loc['code']??null)===null&&is_string($r['value']))?Logic::numberValue($r['value'],$loc['field'],[$loc['field']=>$mark]):$r['value']];}
            if($typed){$format='ymd';if($onPage){$tv=TemporalValue::fromValidation($shape->field($loc['field'])['validation']??'');if($tv)$format=$tv['format'];}$node=['date',$typed,$format,$node];}
            return $node;
        };
        // A set of saved entries. Every host context of a record asks about the same saved
        // collection, so saved-data callers index it once and count from the index; each
        // context compared every member, which was quadratic (wargame P3).
        $collection=function(array $r,$quantifier)use($member,$resolver,$browser){
            if(!$browser&&isset($r['pool'])){
                $index=$resolver->collectionIndex($r['pool'],function(array $m)use($member){$node=$member($m);return $node[0]==='lit'?$node[1]:null;});
                if($index)return ['value',['set'=>$quantifier,'index'=>$index,'skip'=>$r['pool']['skip']]];
            }
            $members=[];foreach($r['members'] as $m)$members[]=$member($m);return ['set',$quantifier,$members];
        };
        $operand=function(array $op)use(&$operand,$rule,$resolver,$context,$member,$collection,$browser,$mayRead,$shape,&$snapshot,&$live,&$denied,&$problems,&$matched,$unresolved,$protected){
            if($op[0]==='lit')return $op;
            if($op[0]==='binding'){
                $b=$rule['references'][$op[1]]??null;if(!is_array($b)){$problems[]='invalid';return ['unknown'];}
                $type=$b['type']??null;$from=$b['elapsedFrom']??null;$unit=$b['unit']??null;unset($b['type'],$b['elapsedFrom'],$b['unit']);
                $r=$resolver->resolveBinding($b,$context);
                // Only a plain read of the entry being typed is not about the protected entries:
                // a collection or an aggregate has no "self", and a match chose among them.
                if($protected($shape->field($b['field'])['form']??null)&&($r['state']!=='ok'||!empty($b['match'])||empty($r['self']))){
                    $denied=true;if($r['state']!=='ok')$r=['state'=>'unresolved'];
                }
                if($r['state']!=='ok'){$unresolved($r['state']);return ['unknown'];}
                // Which entries a set or an aggregate holds was read when the page was built,
                // even when it holds none: an entry added in another tab changes it.
                if($browser&&(isset($b['aggregate'])||isset($r['members'])))$snapshot=true;
                if(isset($b['aggregate'])&&!$browser&&!in_array($b['aggregate'],['any','all'],true)){
                    // Saved data has no live member, so the resolver's exact answer is final;
                    // rebuilding it from the member list repeated the whole sum per host context.
                    $node=isset($r['numerator'])?['value',['numerator'=>$r['numerator'],'denominator'=>$r['denominator']]]:['lit',$r['value']];
                }
                elseif(isset($b['aggregate'])&&in_array($b['aggregate'],['any','all'],true))$node=$collection($r,$b['aggregate']);
                elseif(isset($b['aggregate'])){$members=[];foreach($r['members']??[] as $m)$members[]=$member($m);$node=['aggregate',$b['aggregate'],$members];}
                elseif(isset($r['members']))$node=$collection($r,$r['quantifier']);
                else $node=$member($r,$type);
                if($from!==null){$p=Logic::parse($from."=''",['qualified'=>true]);if(empty($p['ok'])||!in_array($p['ast'][2][0],['ref','qref'],true)){$problems[]='invalid';return ['unknown'];}
                    $fr=$resolver->resolve($p['ast'][2],$context);
                    if($fr['state']!=='ok'&&$protected($shape->field($p['ast'][2][1])['form']??null)){$denied=true;$fr=['state'=>'unresolved'];}
                    $node=['elapsed',$unit,$member($fr,$type),$node];}
                // The row a "match" chooses was found among the saved entries, even when it is this one.
                if($browser&&!empty($b['match']))$matched=true;
                $guards=[];foreach($b['match']??[] as $source){preg_match('/^\[([a-z][a-z0-9_]*)\]$/D',$source,$m);if(isset($m[1])){$v=$resolver->resolve(['ref',$m[1],null],$context);if($browser && empty($v['self']) && $mayRead && isset($v['location']) && !$mayRead($v['location']['instrument']))$denied=true;if($browser&&!empty($v['self'])){$live=true;$guards[]=[$m[1],$v['value']];}}}
                return $guards?['guard',$guards,$node]:$node;
            }
            $r=$resolver->resolve($op,$context);
            if(($r['state']!=='ok'||isset($r['members']))&&$protected($shape->field($op[1])['form']??null)){$denied=true;if($r['state']!=='ok')$r=['state'=>'unresolved'];}
            // Which entries the set holds was read when the page was built, as for a binding.
            if(isset($r['members'])){if($browser)$snapshot=true;return $collection($r,$r['quantifier']);}
            return $member($r);
        };
        $walk=function(array $ast)use(&$walk,$operand){
            if($ast[0]==='cmp')return ['cmp',$ast[1],$operand($ast[2]),$operand($ast[3])];
            if($ast[0]==='not')return ['not',$walk($ast[1])];
            if($ast[0]==='and'||$ast[0]==='or')return [$ast[0],array_map($walk,$ast[1])];return $ast;
        };
        // Long exact arithmetic spends the same budget as the reads that fed it.
        $charge=function($units)use($resolver){return $resolver->charge($units);};
        $compiled=$rule;
        foreach(ModeRegistry::condKeys() as $key)if(isset($rule[$key])){
            if(!$browser && in_array($key,ModeRegistry::condKeys('test'),true) && ($compiled['when']??null)==='1=0' && !$problems)continue;
            $p=Logic::parse($rule[$key],['qualified'=>true]);if(empty($p['ok'])){$problems[]='invalid';continue;}
            $cond=true;$pending=[];$tree=$walk($p['ast']);$cond=false;
            $test=in_array($key,ModeRegistry::condKeys('test'),true);
            // The browser evaluates its own tree; only saved-data callers need the verdict here.
            $value=$browser?null:TemporalLogic::evaluate($tree,function($f,$c)use($context){$v=$context['values'][$f]??'';return $c===null?$v:(is_array($v)&&($v[$c]??'0')==='1'?'1':'0');},$test,self::textMode($rule,$key),$charge);
            if(!$browser){if($key==='assert')$compiled['_temporalAssertLabel']=$rule[$key];if($value===null){$problems=array_merge($problems,$pending);$problems[]=$resolver->exhausted()?'limit':'unresolved';}$compiled[$key]=$value?'1=1':'1=0';unset($compiled[$key.'Ast']);}
            elseif($pending){
                // The page decides around the unknown with its live values. A tree with none
                // to read stays undecided there too, so it is deferred here with its reasons;
                // one decided whatever they hold is sent as its answer. Either way the
                // unknown part may change once its entry is saved, so the rule reads a snapshot.
                $snapshot=true;
                $settled=TemporalLogic::evaluate($tree,function(){return null;},$test,self::textMode($rule,$key),$charge);
                if($settled===null&&!self::readsLive($tree))$problems=array_merge($problems,$pending);
                $compiled[$key.'Ast']=$settled===null?['temporal',$tree]:['const',$settled];
            }
            else $compiled[$key.'Ast']=['temporal',$tree];
        }
        // An operand is a VALUE the verdict reads, not a comparison, so it never folds to a
        // Boolean: the browser gets a live ref or a permitted snapshot, saved-data callers the
        // value itself.
        //  - No saved entry yet (no row in that event, no such instance) is a blank value:
        //    the window waits for it, as it waits for a blank field.
        //  - One the viewer may not read is ['withheld']: nothing of it is sent, and the
        //    browser checks only what does not depend on it.
        //  - One that is the host field itself, in this same entry, is marked: a field is
        //    never judged against its own value (on the browser the live ref names it).
        // Whether a condition read a snapshot: then the whole rule's verdict may be stale.
        $condSnapshot=$snapshot;
        foreach(self::operands($rule) as list($rk,$op)){
            if(!$browser && ($compiled['when']??null)==='1=0' && !$problems)continue;
            $r=$resolver->resolve($op,$context);
            if(($r['state']??null)==='absent'){$form=$shape->field($op[1])['form']??null;$r=['state'=>'ok','value'=>'','self'=>false,'location'=>['instrument'=>$form,'field'=>$op[1],'code'=>null]];}
            //  - One of an instrument this viewer may not read is withheld whatever its state, with
            //    no reason: an ambiguous entry must look like one the viewer may not see (wargame S1).
            if(!isset($r['members'])&&($r['state']??null)!=='ok'&&$protected($shape->field($op[1])['form']??null)){$compiled[$rk['op']]=['withheld'];continue;}
            //  - One that cannot be resolved, of a rule that checks something without it
            //    (ModeRegistry::withholds: @UVWINDOW "notFuture" or "notPast"), is withheld with its
            //    reason, and the rule stays live for the rest.
            if(!isset($r['members'])&&($r['state']??null)!=='ok'&&ModeRegistry::withholds($rk,$rule)){
                $why='Extended reference unavailable: '.($r['state']??'unreadable').'.';
                if($browser){$compiled[$rk['op']]=['withheld'];$compiled[$rk['op'].'Why']=[$why];}
                else{$compiled[$rk['value']]=null;$compiled[$rk['value'].'Why']=$why;}
                continue;
            }
            if(isset($r['members'])){$problems[]='invalid';$node=['unknown'];}
            elseif(($r['state']??null)==='ok'&&$browser&&empty($r['self'])&&$mayRead&&!$mayRead($r['location']['instrument']??null))$node=['withheld'];
            else $node=$member($r);
            if($browser)$compiled[$rk['op']]=$node;
            else{
                $compiled[$rk['value']]=$node[0]==='lit'?(string)$node[1]:null;
                if(!empty($r['self'])&&in_array($r['location']['field']??null,$rule['fields']??[],true))$compiled[$rk['value'].'Self']=$r['location']['field'];
            }
        }
        // A survey/no-rights comparison with no live operands may disclose only its Boolean result.
        // With a live operand nothing is sent, and the one reason given is the same whatever
        // the protected entries hold (wargame S1).
        if($browser&&$denied){
            // It read saved entries, even where a match found only the one being typed.
            $snapshot=true;
            if($live)$problems=['unauthorized'];
            else foreach(ModeRegistry::condKeys() as $key)if(isset($compiled[$key.'Ast'])){$v=TemporalLogic::evaluate($compiled[$key.'Ast'],function(){return '';},in_array($key,ModeRegistry::condKeys('test'),true),self::textMode($rule,$key),$charge);$compiled[$key.'Ast']=$v===null?['unknown']:['const',$v];if($v===null)$problems[]=$resolver->exhausted()?'limit':'unresolved';}
        }
        if($problems){$compiled['deferred']=true;$compiled['deferredWhy']=['Extended reference unavailable: '.implode(', ',array_unique($problems)).'.'];if($browser){foreach(ModeRegistry::condKeys() as $key)if(isset($compiled[$key.'Ast']))$compiled[$key.'Ast']=['const',false];foreach(ModeRegistry::operandKeys() as $rk)unset($compiled[$rk['op']]);}}
        if($snapshot)$compiled['snapshotFields']=['saved event/instance values'];
        // Whether this rule reads anything that is not live on this page: a saved value, a set, a
        // row a "match" chose, a condition on a form this viewer may not read, or a value that could
        // not be resolved. An operand withheld from this viewer is not read at all: the page judges
        // without it, as it does a rule with no event or instance reference.
        $offPage=$browser&&($snapshot||$denied||$problems||$matched);
        // extendedAdvisory: the same "never blocks here" for a mode with no blockSave of its own (@UVRANGE).
        // A mode whose page turns only its snapshot parts advisory (ModeRegistry::snapshotAdvisory:
        // @UVWINDOW, whose "notFuture" reads the live field alone) keeps its blockSave when no
        // condition read a snapshot.
        if($browser){
            unset($compiled['references']);
            if(!(ModeRegistry::snapshotAdvisory(ModeRegistry::modeOfType($rule['type']??''))&&!$condSnapshot&&!$denied))$compiled['blockSave']='off';
            $compiled['extendedAdvisory']=true;
        }
        // stale: a condition read a value from when the page was built, or one this
        // viewer may not read; a branch chosen by it may be the wrong branch.
        return ['rule'=>$compiled,'problems'=>array_values(array_unique($problems)),'stale'=>$browser&&($condSnapshot||$denied),'offPage'=>$offPage];
    }
}
