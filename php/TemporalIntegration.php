<?php
namespace INSPIRE\UniversalValidator;
require_once __DIR__.'/TemporalMetadata.php';
require_once __DIR__.'/TemporalRules.php';

/** Project adapter; pure resolution/evaluation remains in the temporal components. */
trait TemporalIntegration
{
    private function temporalOptions($pid=null)
    {
        return ['qualified'=>in_array($this->getProjectSetting('enable-event-instance-refs',$pid),[true,1,'1','true'],true)];
    }
    private function temporalActivationProblems($pid)
    {
        $dd=$this->dataDictionary($pid);
        if (!$dd) return ['Event/instance preflight could not read project metadata.'];
        $shape=TemporalMetadata::load($pid,$dd);
        if (!$shape->events()) return ['Event/instance preflight could not read event metadata.'];
        $errors=[];$rules=[];
        foreach ($dd as $field=>$meta) {
            foreach (AnnotationRules::parseAllTags($meta['field_annotation'] ?? '', ['qualified'=>true]) as $rule) {
                if (isset($rule['type'])) {$rule['fields']=[$field];$rules[]=$rule;}
                if (!TemporalRules::extended($rule)) continue;
                foreach (array_merge(TemporalRules::validate($rule),TemporalRules::validateProject($rule,$shape)) as $error) $errors[]=$field.': '.$error;
                foreach (TemporalRules::fields($rule) as $source) if (!isset($dd[$source])) $errors[]=$field.': unknown source field '.$source.'.';
            }
        }
        foreach (Branching::fieldConflicts($rules) as $field=>$conflict) $errors[]=Branching::message($field,$conflict);
        return array_values(array_unique($errors));
    }
    private function temporalScanStructure($pid, array $rules)
    {
        foreach ($rules as $rule) if (TemporalRules::extended($rule)) {
            return ['dialect'=>TemporalRules::VERSION, 'enabled'=>$this->temporalOptions($pid)['qualified'],
                'project'=>TemporalMetadata::load($pid,$this->dataDictionary($pid) ?: [])->digest()];
        }
        return [];
    }
    private function temporalReadFields(array $rules,$pid)
    {
        $fields=[];$dd=$this->dataDictionary($pid)?:[];
        foreach($rules as $r)foreach(TemporalRules::fields($r) as $f){$fields[$f]=true;if(isset($dd[$f]['form_name']))$fields[$dd[$f]['form_name'].'_complete']=true;}
        // Dictionary order starts with this project's primary key, even in cron
        // where REDCap's ambient current project may be different.
        if($dd){$pk=array_key_first($dd);if(is_string($pk))$fields[$pk]=true;}
        return array_keys($fields);
    }
    private function temporalReadRecord($pid,$record,array $rules,?ProjectShape $shape=null,$event=null)
    {
        if($record===null||$record==='')return [];
        $request=['project_id'=>$pid,'return_format'=>'array','records'=>[$record],'fields'=>$this->temporalReadFields($rules,$pid)];
        if($shape && $event!==null){
            $events=[];$known=true;
            foreach($rules as $rule){$needed=TemporalRules::eventIds($rule,$shape,$event);if($needed===null){$known=false;break;}foreach($needed as $id)$events[$id]=true;}
            if($known && $events)$request['events']=array_keys($events);
        }
        $data=\REDCap::getData($request);
        if(!is_array($data)||($data&&!isset($data[$record]))||isset($data[$record])&&!is_array($data[$record]))throw new \RuntimeException('Extended reference data unavailable.');
        return $data[$record]??[];
    }
    private function temporalContext(array $ctx,$form,$unsaved=false)
    {
        return ['event'=>$ctx['event_id'],'instrument'=>$form,'instance'=>$ctx['instance'],'values'=>$ctx['values'],'unsaved'=>$unsaved];
    }
    private function temporalPrepared(array $rule,ProjectShape $shape,array $node,array $ctx,$browser=false,$mayRead=null)
    {
        $resolver=$this->temporalResolverFor($shape,$node,$browser);
        if(isset($rule['branches'])){
            $out=$rule;$problems=[];$active=[];$fallback=null;$selectorUnknown=false;
            foreach($rule['branches'] as $i=>$branch){
                $flat=array_merge($rule,$branch);unset($flat['branches']);
                $gate=$flat;unset($gate['assert']);
                $g=TemporalRules::compile($gate,$resolver,$shape,$ctx,$browser,$mayRead);
                if($browser){
                    $b=$this->temporalPrepared($flat,$shape,$node,$ctx,true,$mayRead);
                    if($b['problems']){
                        $b['rule']['deferred']=true;$b['rule']['deferredWhy']=['Extended validation unavailable: '.implode(', ',$b['problems']).'.'];
                        unset($b['rule']['references'],$b['rule']['uniqueRecordAsts']);
                        // Keep the successfully compiled selector so an inactive
                        // unresolved assertion does not poison another branch.
                        if(isset($g['rule']['whenAst']))$b['rule']['whenAst']=$g['rule']['whenAst'];
                    }
                    $out['branches'][$i]=$b['rule'];
                    if($g['problems'])$selectorUnknown=true;
                }else{
                    if(empty($branch['when']))$fallback=$i;
                    elseif($g['problems'])$selectorUnknown=true;
                    elseif(($g['rule']['when']??'')==='1=1')$active[]=$i;
                }
            }
            if($selectorUnknown)return ['rule'=>$out,'problems'=>['unresolved branch selector']];
            if($browser){
                $out['snapshotFields']=['saved event/instance values'];$out['blockSave']='off';return ['rule'=>$out,'problems'=>[]];}
            if(count($active)>1)return ['rule'=>$out,'problems'=>['multiple active branches']];
            $pick=$active?$active[0]:$fallback;
            if($pick===null){unset($out['branches']);$out['when']='1=0';return ['rule'=>$out,'problems'=>[]];}
            $flat=array_merge($rule,$rule['branches'][$pick]);unset($flat['branches']);
            return $this->temporalPrepared($flat,$shape,$node,$ctx,false,null);
        }
        $prepared=TemporalRules::compile($rule,$resolver,$shape,$ctx,$browser,$mayRead);
        if(!$browser && !$prepared['problems'] && ($prepared['rule']['when']??null)==='1=0')return $prepared;
        if(($rule['uniqueScope']??null)==='record'&&!$prepared['problems']){
            $tests=[];
            if ($browser && $mayRead) foreach (TemporalRules::fields($rule) as $requiredField) {
                $owner = $shape->field($requiredField)['form'] ?? null;
                if (!$owner || !$mayRead($owner)) return ['rule'=>$rule,'problems'=>['unauthorized']];
            }
            $results=[];
            foreach($rule['fields'] as $field){
                $meta=$shape->field($field);if(!$meta||$meta['form']!==$ctx['instrument'])continue;
                $parts=array_merge([$field],$rule['uniqueWith']??[]);$current=[];$currentOps=[];
                foreach($parts as $f){$v=$resolver->resolve(['ref',$f,null],$ctx);if($v['state']!=='ok')return ['rule'=>$rule,'problems'=>[$v['state']]];$current[$f]=$v['value'];$currentOps[$f]=($browser&&!empty($v['self']))?['ref',$f,null]:['lit',$v['value']];}
                $index=$this->temporalUniqueTuples($resolver,$node,$meta['form'],$field,$parts);
                if($index['problem']!==null)return ['rule'=>$rule,'problems'=>[$index['problem']]];
                $own=$ctx['event'].'|'.$ctx['instance'];
                if(!$browser){
                    // Saved data: is this entry's tuple held by any OTHER entry? One lookup.
                    $holders=$index['holders'][self::temporalTupleKey($current,$parts)]??[];
                    unset($holders[$own]);$results[$field]=!$holders;continue;
                }
                $clauses=[];$sent=[];
                foreach($index['tuples'] as $id=>$tuple){
                    if((string)$id===$own)continue;
                    // Entries holding the same tuple are one clause: the page asks "is my value taken", not "by how many".
                    $key=self::temporalTupleKey($tuple,$parts);if(isset($sent[$key]))continue;$sent[$key]=true;
                    $eq=[];foreach($parts as $f)$eq[]=['cmp','identical',$currentOps[$f],['lit',$tuple[$f]]];$clauses[]=['not',['and',$eq]];
                }
                $tests[$field]=['and',$clauses];
            }
            $prepared['rule']['message']=$rule['message']??'This value duplicates another event or repeat in this record.';
            if($browser){
                $prepared['rule']['uniqueRecordAsts']=[];
                foreach($tests as $field=>$tree)$prepared['rule']['uniqueRecordAsts'][$field]=['temporal',$tree];
                $prepared['rule']['snapshotFields']=['other entries in this record'];
            }else{
                $prepared['rule']['uniqueRecordResults']=$results;
            }
            // The tuple comparison is exact by its own operator ("identical"), in both
            // engines. The rule's caseSensitive flag belongs to its "when" gate and is left
            // as authored: forcing it on made the page read [site]='a' exactly while the
            // audit folded case, so a rule the server enforced never ran in the browser.
        }
        return $prepared;
    }
    private $temporalPid=null;
    private $temporalBudget=null;
    private $temporalResolver=null;
    private $temporalUniqueIndex=[];
    private $temporalShapes=[];

    /** One record (or page) evaluation: a fresh budget, and nothing remembered from the last record. */
    private function temporalBegin($pid)
    {
        $this->temporalPid=$pid;$this->temporalBudget=new ReferenceBudget();
        $this->temporalResolver=null;$this->temporalUniqueIndex=[];
    }

    /**
     * Project metadata, once per request. A durable scan used to rebuild it for
     * EVERY record. Only a complete answer is kept, as dataDictionary() does, so
     * a transient metadata failure stays retryable.
     */
    private function temporalShape($pid,?array $dd=null)
    {
        $key=(string)$pid;
        if(isset($this->temporalShapes[$key]))return $this->temporalShapes[$key];
        $shape=TemporalMetadata::load($pid,$dd??($this->dataDictionary($pid)?:[]));
        $complete=$shape->fieldsKnown()&&$shape->events();
        foreach($shape->events() as $event)if(!is_array($event['forms']??null)||!is_bool($event['eventRepeats']??null)||!isset($event['arm'],$event['order']))$complete=false;
        if($complete)$this->temporalShapes[$key]=$shape;
        return $shape;
    }

    /** One resolver per record, so saved collections are read once rather than once per host context. */
    private function temporalResolverFor(ProjectShape $shape,array $node,$browser)
    {
        $held=$this->temporalResolver;
        if($held===null||$held['shape']!==$shape||$held['budget']!==$this->temporalBudget||$held['browser']!==(bool)$browser||$held['node']!==$node){
            $resolver=new AddressResolver($shape,$node,10000,$this->temporalBudget);
            if(!$browser)$resolver->shareAcrossContexts();
            $this->temporalUniqueIndex=[];
            $held=$this->temporalResolver=['shape'=>$shape,'budget'=>$this->temporalBudget,'browser'=>(bool)$browser,'node'=>$node,'resolver'=>$resolver];
        }
        return $held['resolver'];
    }

    /** Components compare as TemporalLogic's "identical" does: exact strings after PHP's default trim. */
    private static function temporalTupleKey(array $tuple,array $parts)
    {
        $key=[];foreach($parts as $f)$key[]=trim((string)($tuple[$f]??''));
        return serialize($key);   // binary-safe, unlike json_encode on a value that is not UTF-8
    }

    /**
     * Every saved entry's uniqueness tuple on one instrument, read ONCE per
     * record. Each host context used to re-read every other entry, which is
     * quadratic: a record stopped being checkable at about 220 repeat instances
     * because the rescans alone spent the evaluation budget.
     * tuples: "event|instance" => [field => value] (entries whose own value is
     * blank are left out: blank is never a duplicate). holders: tuple key =>
     * the entries holding it.
     */
    private function temporalUniqueTuples(AddressResolver $resolver,array $node,$form,$field,array $parts)
    {
        $cacheKey=$form.'|'.implode(',',$parts);
        if(isset($this->temporalUniqueIndex[$cacheKey]))return $this->temporalUniqueIndex[$cacheKey];
        $index=['problem'=>null,'tuples'=>[],'holders'=>[]];$count=0;
        foreach($this->hostContextsFor(self::recordContexts($node),$form,$this->temporalPid) as $other){
            if(++$count>10000){$index['problem']='limit';break;}
            $oc=$this->temporalContext($other,$form);$tuple=[];
            foreach($parts as $f){$v=$resolver->resolve(['ref',$f,null],$oc);if($v['state']!=='ok'){$index['problem']=$v['state'];break 2;}$tuple[$f]=$v['value'];}
            if($tuple[$field]==='')continue;
            $id=$other['event_id'].'|'.$other['instance'];
            $index['tuples'][$id]=$tuple;$index['holders'][self::temporalTupleKey($tuple,$parts)][$id]=true;
        }
        return $this->temporalUniqueIndex[$cacheKey]=$index;
    }

    private function foldTemporalRules(array $rules,$pid,$record,$instrument,$event,$instance,$context)
    {
        $this->temporalPid=$pid;$extended=[];$legacy=[];
        foreach($rules as $i=>$r){if(empty($r['configError'])&&TemporalRules::extended($r))$extended[$i]=$r;else $legacy[$i]=$r;}
        $out=$this->foldRuleConditions($legacy,$pid,$record,$instrument,$event,$instance,$context);
        if(!$extended)return $out;
        $this->temporalBegin($pid);
        try{
            $dd=$this->dataDictionary($pid)?:[];$shape=$this->temporalShape($pid,$dd);$node=$this->temporalReadRecord($pid,$record,$extended,$shape,$event);
            $rights=$context==='survey'?null:$this->userFormRights($pid);
            $mayRead=function($form)use($rights){return self::mayReadForm($rights,$form);};
            $ctx=['event'=>$event,'instrument'=>$instrument,'instance'=>$instance,'unsaved'=>true,'values'=>[]];
            $bucket=$shape->bucket($event,$instrument);
            if($bucket['state']==='ok') $ctx['values']=$bucket['bucket']===null?($node[$event]??[]):($node['repeat_instances'][$event][$bucket['bucket']][$instance]??[]);
            foreach($extended as $i=>$r){
                // Do not ship complete record-local uniqueness tuples unless every component is disclosable.
                $p=$this->temporalPrepared($r,$shape,$node,$ctx,true,$mayRead);$out[$i]=$p['rule'];
                if($p['problems']){$out[$i]['deferred']=true;$out[$i]['deferredWhy']=['Extended validation unavailable: '.implode(', ',$p['problems']).'.'];
                    foreach(['whenAst','assertAst','uniqueRecordAsts'] as $k)unset($out[$i][$k]);unset($out[$i]['references']);
                    if(isset($out[$i]['branches']))foreach($out[$i]['branches'] as &$b){$b['deferred']=true;unset($b['whenAst'],$b['assertAst'],$b['uniqueRecordAsts'],$b['references']);}unset($b);
                }
            }
        }catch(\Throwable $e){foreach($extended as $i=>$r){$out[$i]=['type'=>$r['type'],'fields'=>$r['fields'],'deferred'=>true,'deferredWhy'=>['Extended source data unavailable.'],'blockSave'=>'off'];}}
        ksort($out);return array_values($out);
    }

    private function auditTemporalRules(array $rules,$pid,$record,$instrument,$event,$instance,$logMode)
    {
        $extended=[];$dd=$this->dataDictionary($pid)?:[];
        foreach($rules as $i=>$r){if(!empty($r['configError'])||!TemporalRules::extended($r))continue;
            foreach(TemporalRules::fields($r) as $f)if($instrument===null||($dd[$f]['form_name']??null)===$instrument){$extended[$i]=$r;break;}}
        if(!$extended)return;
        $this->temporalBegin($pid);
        try {$node=$this->temporalReadRecord($pid,$record,$extended);$shape=$this->temporalShape($pid,$dd);$all=self::recordContexts($node);
            if (!$all) throw new \RuntimeException('No readable host contexts.');
        } catch (\Throwable $e) {
            foreach ($extended as $i=>$rule) $this->logUnconfigurable($i,$rule['fields'],'Extended audit incomplete: source data unavailable; run the validation scan.',$instrument,$event,$instance);
            return;
        }
        $limit=(int)$this->getProjectSetting('qualified-audit-max-contexts',$pid);if($limit<1)$limit=500;$count=0;
        $dupes=array_fill_keys(self::duplicateFields($rules),true);
        $stopped=false;
        foreach($extended as $i=>$rule)foreach($this->ruleHostForms($rule,$pid)['forms'] as $form=>$fields){
            // Once the audit stops, every rule it did not reach is named. Returning
            // after the first notice left the later rules unchecked AND unmentioned.
            if($stopped){$this->logUnconfigurable($i,$fields,'Extended audit incomplete: context limit or evaluation budget reached; run the validation scan.',$form,$event,$instance);continue;}
            foreach($this->hostContextsFor($all,$form,$pid) as $ctx){
                if(++$count>$limit || $this->temporalBudget->exhausted()){$this->logUnconfigurable($i,$fields,'Extended audit incomplete: context limit or evaluation budget reached; run the validation scan.',$form,$ctx['event_id'],$ctx['instance']);$stopped=true;break;}
                $prepared=$this->temporalPrepared($rule,$shape,$node,$this->temporalContext($ctx,$form));
                if($prepared['problems']){$this->logUnconfigurable($i,$fields,'Extended validation unavailable: '.implode(', ',$prepared['problems']),$form,$ctx['event_id'],$ctx['instance']);continue;}
                $this->auditRule($prepared['rule'],$i,$ctx['values'],$dupes,array_fill_keys($fields,true),$logMode,$pid,$record,$form,$ctx['event_id'],$ctx['instance']);
            }
        }
    }
}
