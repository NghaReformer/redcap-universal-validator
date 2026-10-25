<?php
namespace INSPIRE\UniversalValidator;

require_once __DIR__ . '/ProjectShape.php';
require_once __DIR__ . '/Logic.php';
require_once __DIR__ . '/ExactDecimal.php';
require_once __DIR__ . '/ReferenceBudget.php';
require_once __DIR__ . '/CollectionIndex.php';

/** Pure per-record resolver. No network, log, browser, or authorization side effects. */
final class AddressResolver
{
    /**
     * How the page config is encoded (UniversalValidator, UV-001): the hex flags
     * write each of < > & ' " as a six-byte \uXXXX escape.
     */
    const PAGE_JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

    private $shape;
    private $record;
    private $limit;
    private $budget;
    private $shared = false;
    private $page = false;
    private $memo = [];
    /** Pooled collections (wargame P3): their full member lists, bindings by key, summaries, indexes. */
    private $poolMembers = [];
    private $pools = [];
    private $summaries = [];
    private $indexes = [];
    /** entered() answers, keyed by row and form. Saved rows never change. */
    private $entered = [];

    public function __construct(ProjectShape $shape, array $record, $limit = 10000, ?ReferenceBudget $budget = null)
    {
        $this->shape = $shape;
        $this->record = $record;
        $this->limit = max(1, (int)$limit);
        $this->budget = $budget ?? new ReferenceBudget();
    }

    /**
     * Saved-data evaluation (audit, scan): every host context of one record reads
     * the same saved rows, so a collection is read once per record instead of once
     * per host context. That work was quadratic in the number of instances and
     * spent the shared budget at about 220 of them. Results are identical to the
     * unshared path, which tests/temporal_adversarial_php.php proves differentially.
     * A browser payload never shares: its one context is unsaved and live.
     */
    public function shareAcrossContexts()
    {
        $this->shared = true;
        return $this;
    }

    /**
     * Browser payload: each value read is charged by the bytes it takes in the
     * page's JSON, not by its raw length. Members of 62 "<" were charged one unit
     * each and took 373 bytes on the page, so 4 x 9,000 of them made a 13.18 MB
     * page where as many plain members made 2.54 MB (wargame P6).
     */
    public function chargePageBytes()
    {
        $this->page = true;
        return $this;
    }

    /** Bytes of $value inside the page JSON, its quotes left out. */
    private static function pageBytes($value)
    {
        // Printable ASCII with nothing to escape is its own length, which is nearly every value.
        if (!preg_match('/[^\x20-\x7E]|[<>&\'"\\\\\/]/', $value)) return strlen($value);
        $json = json_encode($value, self::PAGE_JSON_FLAGS);
        // Not UTF-8: the page drops the rule (encodableRules), so the worst case is charged.
        return $json === false ? 6 * strlen($value) : strlen($json) - 2;
    }

    /** context={event,instrument,instance,unsaved?:bool,values?:array}. */
    public function resolve(array $ref, array $context)
    {
        if (!$this->budget->take()) return self::problem('limit');
        if (!in_array($ref[0], ['ref','qref'], true)) return self::problem('invalid');
        $field = $this->shape->field($ref[1]);
        if (!$field || empty($field['form'])) return self::problem($this->shape->fieldsKnown()?'invalid':'unreadable');
        $form = $field['form'];
        $event = $this->shape->eventId(isset($ref[3]) ? $ref[3] : null, $context['event'], $form);
        if ($event['state'] !== 'ok') return self::problem($event['state']);
        $eventId = $event['event'];
        $bucket = $this->shape->bucket($eventId, $form);
        if ($bucket['state'] !== 'ok') return self::problem($bucket['state']);
        $target = $bucket['bucket'];
        $source = $this->shape->bucket($context['event'], $context['instrument']);
        if ($source['state'] !== 'ok') return self::problem($source['state']);
        $sameBucket = (string)$eventId === (string)$context['event'] && $target === $source['bucket'];
        $selector = isset($ref[4]) ? $ref[4] : null;
        $instance = isset($context['instance']) ? (int)$context['instance'] : 1;
        if ($selector === null) {
            if ($target !== null && !$sameBucket) return self::problem('ambiguous');
            $selected = $target === null ? null : $instance;
        } else {
            if ($target === null) return self::problem('invalid');
            if (in_array($selector, ['current-instance','previous-instance','next-instance'], true)) {
                if ($source['bucket'] === null || $instance < 1) return self::problem('invalid');
                if ($selector === 'next-instance' && $instance === PHP_INT_MAX) return self::problem('invalid');
                $selected = $instance + ($selector === 'previous-instance' ? -1 : ($selector === 'next-instance' ? 1 : 0));
            } elseif (preg_match('/^[1-9][0-9]*$/', (string)$selector)) {
                // Do not let integer overflow select a different instance.
                if (strlen((string)$selector) > strlen((string)PHP_INT_MAX)
                    || (strlen((string)$selector) === strlen((string)PHP_INT_MAX) && strcmp((string)$selector, (string)PHP_INT_MAX) > 0)) return self::problem('invalid');
                $selected = (int)$selector;
            } else {
                // Nothing here walks the instance list: a set is answered from its index.
                $index = $this->instances($eventId, $target, $form, $sameBucket ? $context : null, false);
                if ($index['state'] !== 'ok') return $index;
                $ids = $index['instances'];
                if (in_array($selector, ['any-instance','all-instances'], true)) {
                    if (!$ids) return self::problem('absent');
                    $members = $this->members($ref, $eventId, $target, $ids, $form, $context, $sameBucket, true, $pool);
                    if (isset($members['state'])) return $members;
                    $out = ['state'=>'ok','quantifier'=>$selector === 'any-instance' ? 'any' : 'all','members'=>$members];
                    if ($pool !== null) $out['pool'] = ['key'=>$pool,'skip'=>null];
                    return $out;
                }
                if (!in_array($selector, ['first-instance','last-instance'], true)) return self::problem('invalid');
                if (!$ids) return self::problem('absent');
                $selected = $selector === 'first-instance' ? reset($ids) : end($ids);
            }
        }
        if ($selected !== null && $selected < 1) return self::problem('absent');
        return $this->value($ref, $eventId, $target, $selected, $form, $context, $sameBucket);
    }

    /** Declarative bindings. Match sources are scalar field references, never executable logic. */
    public function resolveBinding(array $binding, array $context)
    {
        if (!$this->budget->take()) return self::problem('limit');
        // An aggregate with no matching key, no excluded entry and no relative
        // instance asks the same question from every host context of one event.
        $memoKey=null;
        if ($this->shared && empty($context['unsaved']) && isset($binding['aggregate']) && empty($binding['match'])
            && empty($binding['excludeCurrent'])
            && !in_array($binding['instance']??null,['current-instance','previous-instance','next-instance'],true)
            && !$this->overlays($binding['field']??null,$context)) {
            $memoKey='b|'.serialize([$binding,(string)$context['event'],(string)$context['instrument']]);
            if (isset($this->memo[$memoKey])) {
                // A settled number costs its caller nothing more, and neither does a
                // set: the caller answers any/all from its index (wargame P3).
                return $this->budget->take() ? $this->memo[$memoKey] : self::problem('limit');
            }
        }
        $result=$this->bindingResult($binding,$context);
        if ($memoKey!==null && $result['state']==='ok') {
            // `self` names the first asker's own entry, which means nothing to the
            // next one. Saved-data callers never read it; removing it makes any
            // future reader fail loudly instead of trusting a stale flag.
            if (isset($result['members'])) foreach ($result['members'] as $k=>$m) unset($result['members'][$k]['self']);
            if (isset($result['quantifier'])) {$this->poolMembers[$memoKey]=$result['members'];$result['pool']=['key'=>$memoKey,'skip'=>null];}
            $this->memo[$memoKey]=$result;
        }
        return $result;
    }

    /**
     * Spend evaluation budget on arithmetic done outside the resolver. TemporalRules
     * hands this to TemporalLogic, which charges each long multiplication: a
     * collection's members are handed out cheaply once cached, and each one scaled
     * against a 4,000-digit average cost seconds per save while charging nothing
     * (wargame P1).
     */
    public function charge($units)
    {
        return $this->budget->take($units);
    }

    /** True once the shared budget has refused a request. */
    public function exhausted()
    {
        return $this->budget->exhausted();
    }

    private function bindingResult(array $binding, array $context)
    {
        $allowed=['field','event','events','arm','instance','match','aggregate','excludeCurrent'];
        if (array_diff(array_keys($binding),$allowed) || !isset($binding['field']) || !is_string($binding['field'])) return self::problem('invalid');
        $field=$this->shape->field($binding['field']);
        if (!$field || empty($field['form'])) return self::problem($this->shape->fieldsKnown()?'invalid':'unreadable');
        if (isset($binding['event']) && isset($binding['events'])) return self::problem('invalid');
        $eventTokens=isset($binding['events'])?$binding['events']:[$binding['event']??null];
        if ($eventTokens==='arm') {
            if (!isset($binding['arm'])) return self::problem('invalid');
            $eventTokens=[];
            foreach ($this->shape->events() as $event) {
                if (!isset($event['arm'],$event['forms'],$event['name']) || !is_array($event['forms'])) return self::problem('unreadable');
                if ((string)$event['arm']===(string)$binding['arm'] && in_array($field['form'],$event['forms'],true)) $eventTokens[]=$event['name'];
            }
            if (!$eventTokens) return self::problem('invalid');   // no such arm, or it never collects this instrument
        }
        if (!is_array($eventTokens) || count($eventTokens)>$this->limit) return self::problem('invalid');
        if (isset($binding['excludeCurrent']) && !is_bool($binding['excludeCurrent'])) return self::problem('invalid');
        $matches=$binding['match']??[];
        if (!is_array($matches)) return self::problem('invalid');
        if (!$matches && !isset($binding['aggregate']) && !isset($binding['events']) && empty($binding['excludeCurrent'])) {
            return $this->resolve(['qref',$binding['field'],null,$binding['event']??null,
                isset($binding['instance'])?(string)$binding['instance']:null],$context);
        }
        $keys=[];
        foreach ($matches as $target=>$source) {
            $meta=$this->shape->field($target);
            if (!$meta || ($meta['form']??null)!==$field['form'] || !is_string($source)
                || !preg_match('/^\[([a-z][a-z0-9_]*)\]$/D',$source,$m)) return self::problem('invalid');
            $key=$this->resolve(['ref',$m[1],null],$context);
            if ($key['state']!=='ok') return $key;
            if ($key['value']==='') return self::problem('absent');
            $keys[$target]=$key['value'];
        }
        if ($this->shared && empty($context['unsaved']) && !isset($binding['instance']) && ($keys || !empty($binding['excludeCurrent']))) {
            $pooled=$this->pooled($binding,$field['form'],$eventTokens,$keys,$context);
            if ($pooled!==null) return $pooled;
        }
        $members=[];$seen=[];
        foreach ($eventTokens as $token) {
            if ($token!==null && !is_string($token)) return self::problem('invalid');
            $event=$this->shape->eventId($token,$context['event'],$field['form']);
            if ($event['state']!=='ok') return self::problem($event['state']);
            $eventId=$event['event'];
            $bucket=$this->shape->bucket($eventId,$field['form']);
            if ($bucket['state']!=='ok') return self::problem($bucket['state']);
            $source=$this->shape->bucket($context['event'],$context['instrument']);
            if ($source['state']!=='ok') return self::problem($source['state']);
            $same=(string)$eventId===(string)$context['event'] && $bucket['bucket']===$source['bucket'];
            if (isset($binding['instance'])) {
                if ($keys) return self::problem('invalid');
                $selected=$this->resolve(['qref',$binding['field'],null,$token,(string)$binding['instance']],$context);
                if ($selected['state']!=='ok') return $selected;
                $candidates=isset($selected['members'])?$selected['members']:[$selected];
                // A pooled set came cheap; walking it here is charged as reading it was.
                if (isset($selected['pool']) && !$this->budget->take(intdiv(count($candidates),32))) return self::problem('limit');
            } elseif ($bucket['bucket']===null) {
                // An event row holds a member only where this form was entered (wargame C2).
                $hasRow=($same&&$field['form']===$context['instrument']&&!empty($context['unsaved']))||$this->entered($eventId,null,null,$field['form']);
                // value() charges a row it reads; an event with no row cost nothing,
                // so a long list of them was free work per host context (wargame P2).
                if (!$hasRow && !$this->budget->take()) return self::problem('limit');
                $candidates=$hasRow?[$this->value(['ref',$binding['field'],null],$eventId,null,null,$field['form'],$context,$same)]:[];
            } else {
                $index=$this->instances($eventId,$bucket['bucket'],$field['form'],$same?$context:null);
                if ($index['state']!=='ok') return $index;
                $candidates=$this->members(['ref',$binding['field'],null],$eventId,$bucket['bucket'],$index['instances'],$field['form'],$context,$same);
                if (isset($candidates['state'])) return $candidates;
            }
            $keyed=[];
            if ($this->shared && empty($context['unsaved']) && $keys && $bucket['bucket']!==null && !isset($binding['instance'])) {
                foreach ($keys as $key=>$wanted) {
                    $column=$this->members(['ref',$key,null],$eventId,$bucket['bucket'],$index['instances'],$field['form'],$context,$same);
                    if (isset($column['state'])) return $column;
                    foreach ($column as $m) $keyed[$key][$m['location']['instance']]=$m['value'];
                }
            }
            foreach ($candidates as $member) {
                if ($member['state']!=='ok') return $member;
                $loc=$member['location'];
                $identity=json_encode([$loc['event'],$loc['bucket'],$loc['instance'],$loc['field']]);
                if (isset($seen[$identity])) continue;
                $seen[$identity]=true;
                if (count($seen)>$this->limit) return self::problem('limit');
                if (!empty($binding['excludeCurrent']) && $member['self']) continue;
                $match=true;
                foreach ($keys as $key=>$wanted) {
                    if (isset($keyed[$key]) && array_key_exists($loc['instance'],$keyed[$key])) $found=$keyed[$key][$loc['instance']];
                    else {
                        $v=$this->value(['ref',$key,null],$eventId,$bucket['bucket'],$loc['instance'],$field['form'],$context,$same);
                        if ($v['state']!=='ok') return $v;
                        $found=$v['value'];
                    }
                    if ($found!==$wanted) {$match=false;break;}
                }
                if ($match) $members[]=$member;
            }
        }
        return $this->aggregate($binding['aggregate']??null,$members);
    }

    /** A binding's answer over its members: the one member, a count, a set or a number. */
    private function aggregate($op, array $members)
    {
        if ($op===null) {
            if (count($members)!==1) return self::problem($members?'ambiguous':'absent');
            return $members[0];
        }
        if ($op==='count' || $op==='exists') return ['state'=>'ok','value'=>(string)($op==='count'?count($members):($members?1:0)),'members'=>$members];
        $values=[];
        // Blank as Logic::compare sees it: a value of spaces is no value (wargame C6).
        foreach ($members as $member) if (trim($member['value']," \t\r\n")!=='') $values[]=$member['value'];
        if ($op==='populated-count' || $op==='distinct-count') return ['state'=>'ok','value'=>(string)($op==='populated-count'?count($values):count(array_unique($values,SORT_STRING))),'members'=>$members];
        if (!$members) return self::problem('absent');
        if ($op==='any' || $op==='all') return ['state'=>'ok','quantifier'=>$op,'members'=>$members];
        if (!in_array($op,['sum','minimum','maximum','average'],true)) return self::problem('invalid');
        if (!$values) return self::problem('absent');
        // Reading charged each value once; summing long values again per host
        // context (excludeCurrent, match) is charged here (wargame P1).
        $cost=ExactDecimal::sumCost($values);
        if ($cost>0 && !$this->budget->take($cost)) return self::problem('limit');
        if ($op==='minimum' || $op==='maximum') {
            // Each value must be a number, but their sum may be too long (wargame P7).
            $checked=ExactDecimal::check($values);
            if ($checked['state']!=='ok') return $checked;
        } else {
            $sum=ExactDecimal::sum($values);
            if ($sum['state']!=='ok') return $sum;
            if ($op==='average') return ['state'=>'ok','numerator'=>$sum['value'],'denominator'=>(string)count($values),'members'=>$members];
            return $sum+['members'=>$members];
        }
        $best=$values[0];
        foreach ($values as $value) {
            if (Logic::evaluate(['cmp',$op==='minimum'?'<':'>',['lit',$value],['lit',$best]],[])) $best=$value;
        }
        return ['state'=>'ok','value'=>$best,'members'=>$members];
    }

    /**
     * A matched or excludeCurrent binding from saved data, answered from one
     * pool per record (wargame P3). The loop in bindingResult() filtered every
     * entry again from every host context: quadratic in the entries. Here the
     * entries are collected once and grouped by their matching keys; a host
     * context takes its group and leaves out its own entry. Null: answer with
     * the loop (a live value differs from the saved one, or reading a member
     * or a key failed, which the loop reports in its own order).
     */
    private function pooled(array $binding, $form, array $eventTokens, array $keys, array $context)
    {
        if ($this->overlays($binding['field'],$context)) return null;
        foreach ($keys as $target=>$wanted) if ($this->overlays($target,$context)) return null;
        $poolKey='q|'.serialize([$binding['field'],$eventTokens,array_keys($keys),(string)$context['event'],(string)$context['instrument']]);
        if (!array_key_exists($poolKey,$this->pools)) $this->pools[$poolKey]=$this->collect($poolKey,$binding['field'],$form,$eventTokens,array_keys($keys),$context);
        elseif (!$this->budget->take()) return self::problem('limit');
        $pool=$this->pools[$poolKey];
        if ($pool===null || isset($pool['state'])) return $pool;
        $groupKey=$keys?$poolKey.'|'.serialize(array_values($keys)):$poolKey;
        $group=$pool[$groupKey]??['members'=>[],'event'=>null,'instances'=>[]];
        $own=$group['event']??($group['instances'][(int)($context['instance']??1)]??null);
        $members=$group['members'];$skip=null;
        if ($own!==null) {
            if (!empty($binding['excludeCurrent'])) {$skip=$own;array_splice($members,$own,1);}
            else $members[$own]['self']=true;
        }
        $op=$binding['aggregate']??null;
        if (in_array($op,[null,'count','exists','any','all'],true) || !$members) {
            $result=$this->aggregate($op,$members);
            if (isset($result['quantifier'])) $result['pool']=['key'=>$groupKey,'skip'=>$skip];
            return $result;
        }
        $s=$this->summary($groupKey,$group['members']);
        // Long values are charged per host context and summed in full (wargame P1).
        if ($s['long']) return $this->aggregate($op,$members);
        $mine=$skip===null?null:$group['members'][$skip]['value'];
        $minePopulated=$mine!==null && trim($mine," \t\r\n")!=='';
        $populated=$s['populated']-($minePopulated?1:0);
        if ($op==='populated-count') return ['state'=>'ok','value'=>(string)$populated,'members'=>$members];
        if ($op==='distinct-count') return ['state'=>'ok','value'=>(string)(count($s['multiplicity'])-($minePopulated&&$s['multiplicity'][$mine]===1?1:0)),'members'=>$members];
        if (!in_array($op,['sum','minimum','maximum','average'],true)) return self::problem('invalid');
        if (!$populated) return self::problem('absent');
        foreach ($s['invalid'] as $at) if ($at!==$skip) return ['state'=>'invalid'];
        if ($op==='minimum' || $op==='maximum') {
            foreach ($s[$op] as $entry) if ($entry[0]!==$skip) return ['state'=>'ok','value'=>$entry[1],'members'=>$members];
        }
        $sum=$s['sum'];
        if ($minePopulated && !in_array($skip,$s['invalid'],true)) {
            $t=ltrim(trim($mine," \t\r\n"),'+');
            $sum=ExactDecimal::sum([$sum,$t[0]==='-'?substr($t,1):'-'.$t])['value'];
        }
        if ($op==='average') return ['state'=>'ok','numerator'=>$sum,'denominator'=>(string)$populated,'members'=>$members];
        return ['state'=>'ok','value'=>$sum,'members'=>$members];
    }

    /**
     * The entries a pooled binding chooses from, read once per record and
     * grouped by their key values: [groupKey => [members, event => position of
     * the asker's own event row, instances => [instance => position]]], or a
     * problem every host context meets, or null (see pooled()). The checks and
     * their order are the loop's in bindingResult().
     */
    private function collect($poolKey, $fieldName, $form, array $eventTokens, array $keyNames, array $context)
    {
        $groups=[];$seen=[];
        foreach ($eventTokens as $token) {
            if ($token!==null && !is_string($token)) return self::problem('invalid');
            $event=$this->shape->eventId($token,$context['event'],$form);
            if ($event['state']!=='ok') return self::problem($event['state']);
            $eventId=$event['event'];
            $bucket=$this->shape->bucket($eventId,$form);
            if ($bucket['state']!=='ok') return self::problem($bucket['state']);
            $source=$this->shape->bucket($context['event'],$context['instrument']);
            if ($source['state']!=='ok') return self::problem($source['state']);
            $same=(string)$eventId===(string)$context['event'] && $bucket['bucket']===$source['bucket'];
            $columns=[];
            if ($bucket['bucket']===null) {
                $hasRow=$this->entered($eventId,null,null,$form);
                if (!$hasRow && !$this->budget->take()) return self::problem('limit');
                $candidates=$hasRow?[$this->value(['ref',$fieldName,null],$eventId,null,null,$form,$context,$same)]:[];
            } else {
                $index=$this->instances($eventId,$bucket['bucket'],$form,null);
                if ($index['state']!=='ok') return $index;
                // The saved view: the asker's own entry is marked per host context below.
                $candidates=$this->members(['ref',$fieldName,null],$eventId,$bucket['bucket'],$index['instances'],$form,$context,false);
                if (isset($candidates['state'])) return $candidates;
                foreach ($keyNames as $key) {
                    $column=$this->members(['ref',$key,null],$eventId,$bucket['bucket'],$index['instances'],$form,$context,false);
                    if (isset($column['state'])) return $column;
                    foreach ($column as $m) $columns[$key][$m['location']['instance']]=$m['value'];
                }
            }
            foreach ($candidates as $member) {
                if ($member['state']!=='ok') return $member;
                $loc=$member['location'];
                $identity=json_encode([$loc['event'],$loc['bucket'],$loc['instance'],$loc['field']]);
                if (isset($seen[$identity])) continue;
                $seen[$identity]=true;
                if (count($seen)>$this->limit) return self::problem('limit');
                $tuple=[];
                foreach ($keyNames as $key) {
                    if (isset($columns[$key]) && array_key_exists($loc['instance'],$columns[$key])) {$tuple[]=$columns[$key][$loc['instance']];continue;}
                    $v=$this->value(['ref',$key,null],$eventId,$bucket['bucket'],$loc['instance'],$form,$context,$same);
                    if ($v['state']!=='ok') return null;
                    $tuple[]=$v['value'];
                }
                $groupKey=$keyNames?$poolKey.'|'.serialize($tuple):$poolKey;
                $at=isset($groups[$groupKey])?count($groups[$groupKey]['members']):0;
                if ($at===0) $groups[$groupKey]=['members'=>[],'event'=>null,'instances'=>[]];
                $groups[$groupKey]['members'][]=$member;
                if (!empty($member['self'])) $groups[$groupKey]['event']=$at;
                elseif ($same && $bucket['bucket']!==null && $form===$context['instrument']) $groups[$groupKey]['instances'][(int)$loc['instance']]=$at;
            }
        }
        foreach ($groups as $groupKey=>$group) $this->poolMembers[$groupKey]=$group['members'];
        return $groups;
    }

    /**
     * Counts, problems, sum and order of one pooled group's populated values,
     * computed once: what aggregate() derives from them, less one entry.
     */
    private function summary($groupKey, array $members)
    {
        if (isset($this->summaries[$groupKey])) return $this->summaries[$groupKey];
        $s=['populated'=>0,'multiplicity'=>[],'invalid'=>[],'long'=>false];$valid=[];$numbers=[];
        foreach ($members as $at=>$member) {
            $value=$member['value'];$t=trim($value," \t\r\n");
            if ($t==='') continue;
            $s['populated']++;$s['multiplicity'][$value]=($s['multiplicity'][$value]??0)+1;
            if (ExactDecimal::sumCost([$value])>0) $s['long']=true;
            if (!preg_match(Logic::NUM_RE,$t)) $s['invalid'][]=$at;
            else {$valid[]=[$at,$value,$t];$numbers[]=$t;}
        }
        if (!$s['long']) {
            $s['sum']=$numbers?ExactDecimal::sum($numbers)['value']:'0';
            // The first entry holding the least (greatest) value, as the loop keeps it.
            $order=function($sign)use($valid){$list=$valid;usort($list,function($a,$b)use($sign){$c=$sign*Logic::numCompare($a[2],$b[2]);return $c?:$a[0]-$b[0];});return $list;};
            $s['minimum']=$order(1);$s['maximum']=$order(-1);
        }
        return $this->summaries[$groupKey]=$s;
    }

    /**
     * The CollectionIndex of a pooled set ($pool of a shared result), built once
     * per record from its members with $convert (member => the value the
     * evaluator reads), or null when one member does not convert. TemporalRules
     * evaluates "any" and "all" against it from every host context (wargame P3).
     */
    public function collectionIndex(array $pool, callable $convert)
    {
        $key=$pool['key'];
        if (!array_key_exists($key,$this->indexes)) {
            $values=[];
            foreach ($this->poolMembers[$key]??[] as $member) {
                $value=$convert($member);
                if ($value===null) {$values=null;break;}
                $values[]=$value;
            }
            $this->indexes[$key]=$values===null?null:new CollectionIndex($values);
        }
        return $this->indexes[$key];
    }

    /**
     * One field's value in every listed instance of one (event, bucket), in id
     * order, or a problem. Shared mode reads the saved column once per record and
     * re-reads only the caller's own entry, which is the one member whose `self`
     * flag and live overlay depend on who is asking.
     */
    private function members($ref, $event, $bucket, array $ids, $form, $context, $sameBucket, $pooled = false, &$pool = null)
    {
        $pool = null;
        if (!$this->shared || !empty($context['unsaved'])) {
            $out = [];
            foreach ($ids as $id) {
                $member = $this->value($ref, $event, $bucket, $id, $form, $context, $sameBucket);
                if ($member['state'] !== 'ok') return $member;
                $out[] = $member;
            }
            return $out;
        }
        $key = 'm|' . $event . '|' . $bucket . '|' . $ref[1] . '|' . ($ref[2] === null ? '' : '(' . $ref[2] . ')');
        if (!isset($this->memo[$key])) {
            $column = [];
            foreach ($ids as $id) {
                // No context: a saved view, never anybody's own entry.
                $member = $this->value($ref, $event, $bucket, $id, $form, ['instance'=>0,'instrument'=>null], false);
                if ($member['state'] !== 'ok') return $member;
                $column[$id] = $member;
            }
            $this->memo[$key] = $column;
            $this->poolMembers[$key] = array_values($column);
        // A pooled caller answers from the collection's index, not by walking it (wargame P3).
        } elseif (!$this->budget->take(1 + ($pooled ? 0 : intdiv(count($ids), 32)))) return self::problem('limit');
        $column = $this->memo[$key];
        $own = (int)($context['instance'] ?? 1);
        $saved = true;
        if ($sameBucket && isset($column[$own])) {
            $member = $this->value($ref, $event, $bucket, $own, $form, $context, true);
            if ($member['state'] !== 'ok') return $member;
            $saved = $member['value'] === $column[$own]['value'];
            $column[$own] = $member;
        }
        // The index holds saved values: a live value that differs from one is not in it.
        if ($pooled && $saved) $pool = $key;
        return array_values($column);
    }

    /** Whether the caller's live values would change what is saved for $field. */
    private function overlays($field, array $context)
    {
        if (!is_string($field) || !isset($context['values']) || !is_array($context['values'])
            || !array_key_exists($field, $context['values'])) return false;
        $meta = $this->shape->field($field);
        if (!$meta || ($meta['form'] ?? null) !== ($context['instrument'] ?? null)) return false;
        $bucket = $this->shape->bucket($context['event'], $meta['form']);
        if ($bucket['state'] !== 'ok') return true;
        if ($bucket['bucket'] === null) $row = $this->record[$context['event']] ?? [];
        else {
            $rows = $this->rows($context['event'], $bucket['bucket']);
            $row = is_array($rows) ? ($rows[(int)($context['instance'] ?? 1)] ?? []) : [];
        }
        $saved = is_array($row) && array_key_exists($field, $row) ? $row[$field] : '';
        return $context['values'][$field] !== $saved;
    }

    private function instances($event, $bucket, $form, $context, $walk = true)
    {
        // Every form of a repeating event shares its instances, but not its entries.
        $indexKey = 'i|' . $event . '|' . $bucket . ($bucket === '' ? '|' . $form : '');
        if ($this->shared && ($context === null || empty($context['unsaved'])) && isset($this->memo[$indexKey])) {
            return $this->budget->take(1 + ($walk ? intdiv(count($this->memo[$indexKey]['instances']), 32) : 0))
                ? $this->memo[$indexKey] : self::problem('limit');
        }
        $index = $this->instanceIndex($event, $bucket, $form, $context);
        if ($this->shared && $index['state'] === 'ok' && ($context === null || empty($context['unsaved']))) $this->memo[$indexKey] = $index;
        return $index;
    }

    private function instanceIndex($event, $bucket, $form, $context)
    {
        $rows = $this->rows($event, $bucket);
        if ($rows === null) return self::problem('unreadable');
        if (count($rows) > $this->limit || !$this->budget->take(count($rows))) return self::problem('limit');
        $ids = [];
        foreach ($rows as $id=>$row) {
            if (!is_array($row) || !preg_match('/^[1-9][0-9]*$/', (string)$id) || (string)(int)$id !== (string)$id) return self::problem('unreadable');
            // An instance of a repeating event is an entry of the forms entered in it
            // (wargame C2); a repeating form's own instance is always its entry.
            if ($bucket === '' && !$this->entered($event, '', (int)$id, $form)) continue;
            $ids[(int)$id] = true;
        }
        if ($context !== null && !empty($context['unsaved']) && (int)$context['instance'] > 0) $ids[(int)$context['instance']] = true;
        if (count($ids) > $this->limit) return self::problem('limit');
        $ids = array_keys($ids); sort($ids, SORT_NUMERIC);
        return ['state'=>'ok','instances'=>$ids];
    }

    /** An absent bucket differs from a malformed bucket. */
    private function rows($event, $bucket)
    {
        if (!array_key_exists('repeat_instances', $this->record)) return [];
        $repeats = $this->record['repeat_instances'];
        if (!is_array($repeats)) return null;
        if (!array_key_exists($event, $repeats)) return [];
        if (!is_array($repeats[$event])) return null;
        if (!array_key_exists($bucket, $repeats[$event])) return [];
        return is_array($repeats[$event][$bucket]) ? $repeats[$event][$bucket] : null;
    }

    private function value($ref, $event, $bucket, $instance, $form, $context, $sameBucket)
    {
        $self = $sameBucket && ($bucket === null || (int)$instance === (int)$context['instance']);
        // The caller's own row reads blank where another form of it was not
        // entered, as REDCap's logic does. Another row is that form's entry only
        // where the form was entered there: REDCap returns a row for every event
        // or instance holding any data (wargame C2).
        if ($bucket === null) {
            if (!array_key_exists($event,$this->record) && !($self && $form === $context['instrument'] && !empty($context['unsaved']))) return self::problem('absent');
            if (!$self && !$this->entered($event, null, null, $form)) return self::problem('absent');
            $row = isset($this->record[$event]) ? $this->record[$event] : [];
        } else {
            $rows = $this->rows($event, $bucket);
            if ($rows === null) return self::problem('unreadable');
            if (!array_key_exists($instance, $rows)) {
                if (!$self || empty($context['unsaved'])) return self::problem('absent');
                $row = [];
            } elseif (!$self && $bucket === '' && !$this->entered($event, '', (int)$instance, $form)) return self::problem('absent');
            else $row = $rows[$instance];
        }
        if (!is_array($row)) return self::problem('unreadable');
        // Only values belonging to this instrument are live in a repeating event.
        $live = $self && $form === $context['instrument'];
        if ($live && isset($context['values']) && is_array($context['values'])) $row = array_replace($row, $context['values']);
        $value = array_key_exists($ref[1], $row) ? $row[$ref[1]] : '';
        if ($ref[2] !== null) {
            if ($value !== '' && !is_array($value)) return self::problem('unreadable');
            $value = is_array($value) && isset($value[$ref[2]]) && (string)$value[$ref[2]] === '1' ? '1' : '0';
        } elseif (is_array($value) || is_object($value)) return self::problem('invalid');
        $bytes = $this->page ? self::pageBytes((string)$value) : strlen((string)$value);
        if (!$this->budget->take(1 + intdiv($bytes,64))) return self::problem('limit');
        return ['state'=>'ok','value'=>(string)$value,'self'=>$live,
            'location'=>['event'=>$event,'instrument'=>$form,'bucket'=>$bucket,'instance'=>$instance,'field'=>$ref[1],'code'=>$ref[2]]];
    }

    /**
     * Whether $form was entered in one saved row: an event's row ($bucket null)
     * or an instance of a repeating event ($bucket ''). REDCap returns such a
     * row whenever anything was saved in it, with blanks for the forms nobody
     * opened there, so the row alone counted a never-entered form once per
     * event (wargame C2). The form's status field holding a value is evidence,
     * and so is one of its fields holding one (an import that left the status
     * out); a ticked checkbox code counts, an unticked one does not, and the
     * record-id field, stored in every row, never does. This is the signal the
     * scan's started-form gate reads (ScanRuns::formStartedIn()); the callers
     * read every status field with returnBlankForGrayFormStatus, so a form
     * nobody opened reads blank there, not '0'.
     */
    private function entered($event, $bucket, $instance, $form)
    {
        $key = $event . '|' . ($bucket === null ? 'e' : 'r' . $instance) . '|' . $form;
        if (isset($this->entered[$key])) return $this->entered[$key];
        if ($bucket === null) $row = $this->record[$event] ?? null;
        else {
            $rows = $this->rows($event, $bucket);
            $row = is_array($rows) ? ($rows[$instance] ?? null) : null;
        }
        $found = false;
        if (is_array($row)) {
            $pk = $this->shape->recordIdField();
            foreach ($row as $f => $v) {
                $f = (string)$f;
                if ($f === $pk) continue;
                if ($f !== $form . '_complete' && ($this->shape->field($f)['form'] ?? null) !== $form) continue;
                if (is_array($v)) {
                    foreach ($v as $c) if ((string)$c === '1') { $found = true; break 2; }
                    continue;
                }
                if (is_scalar($v) && trim((string)$v) !== '') { $found = true; break; }
            }
        }
        return $this->entered[$key] = $found;
    }

    private static function problem($state) { return ['state'=>$state]; }
}
