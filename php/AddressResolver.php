<?php
namespace INSPIRE\UniversalValidator;

require_once __DIR__ . '/ProjectShape.php';
require_once __DIR__ . '/Logic.php';
require_once __DIR__ . '/ExactDecimal.php';
require_once __DIR__ . '/ReferenceBudget.php';

/** Pure per-record resolver. No network, log, browser, or authorization side effects. */
final class AddressResolver
{
    private $shape;
    private $record;
    private $limit;
    private $budget;

    public function __construct(ProjectShape $shape, array $record, $limit = 10000, ?ReferenceBudget $budget = null)
    {
        $this->shape = $shape;
        $this->record = $record;
        $this->limit = max(1, (int)$limit);
        $this->budget = $budget ?? new ReferenceBudget();
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
                $index = $this->instances($eventId, $target, $sameBucket ? $context : null);
                if ($index['state'] !== 'ok') return $index;
                $ids = $index['instances'];
                if (in_array($selector, ['any-instance','all-instances'], true)) {
                    if (!$ids) return self::problem('absent');
                    $members = [];
                    foreach ($ids as $id) {
                        $member = $this->value($ref, $eventId, $target, $id, $form, $context, $sameBucket);
                        if ($member['state'] !== 'ok') return $member;
                        $members[] = $member;
                    }
                    return ['state'=>'ok','quantifier'=>$selector === 'any-instance' ? 'any' : 'all','members'=>$members];
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
            } elseif ($bucket['bucket']===null) {
                $hasRow=array_key_exists($eventId,$this->record)||($same&&$field['form']===$context['instrument']&&!empty($context['unsaved']));
                $candidates=$hasRow?[$this->value(['ref',$binding['field'],null],$eventId,null,null,$field['form'],$context,$same)]:[];
            } else {
                $index=$this->instances($eventId,$bucket['bucket'],$same?$context:null);
                if ($index['state']!=='ok') return $index;
                $candidates=[];
                foreach ($index['instances'] as $id) $candidates[]=$this->value(['ref',$binding['field'],null],$eventId,$bucket['bucket'],$id,$field['form'],$context,$same);
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
                    $v=$this->value(['ref',$key,null],$eventId,$bucket['bucket'],$loc['instance'],$field['form'],$context,$same);
                    if ($v['state']!=='ok') return $v;
                    if ($v['value']!==$wanted) {$match=false;break;}
                }
                if ($match) $members[]=$member;
            }
        }
        $op=$binding['aggregate']??null;
        if ($op===null) {
            if (count($members)!==1) return self::problem($members?'ambiguous':'absent');
            return $members[0];
        }
        if ($op==='count' || $op==='exists') return ['state'=>'ok','value'=>(string)($op==='count'?count($members):($members?1:0)),'members'=>$members];
        $values=[];
        foreach ($members as $member) if ($member['value']!=='') $values[]=$member['value'];
        if ($op==='populated-count' || $op==='distinct-count') return ['state'=>'ok','value'=>(string)($op==='populated-count'?count($values):count(array_unique($values,SORT_STRING))),'members'=>$members];
        if (!$members) return self::problem('absent');
        if ($op==='any' || $op==='all') return ['state'=>'ok','quantifier'=>$op,'members'=>$members];
        if (!in_array($op,['sum','minimum','maximum','average'],true)) return self::problem('invalid');
        if (!$values) return self::problem('absent');
        $sum=ExactDecimal::sum($values);
        if ($sum['state']!=='ok') return $sum;
        if ($op==='average') return ['state'=>'ok','numerator'=>$sum['value'],'denominator'=>(string)count($values),'members'=>$members];
        if ($op==='sum') return $sum+['members'=>$members];
        $best=$values[0];
        foreach ($values as $value) {
            if (Logic::evaluate(['cmp',$op==='minimum'?'<':'>',['lit',$value],['lit',$best]],[])) $best=$value;
        }
        return ['state'=>'ok','value'=>$best,'members'=>$members];
    }

    private function instances($event, $bucket, $context)
    {
        $rows = $this->rows($event, $bucket);
        if ($rows === null) return self::problem('unreadable');
        if (count($rows) > $this->limit || !$this->budget->take(count($rows))) return self::problem('limit');
        $ids = [];
        foreach ($rows as $id=>$row) {
            if (!is_array($row) || !preg_match('/^[1-9][0-9]*$/', (string)$id) || (string)(int)$id !== (string)$id) return self::problem('unreadable');
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
        if ($bucket === null) {
            if (!array_key_exists($event,$this->record) && !($self && $form === $context['instrument'] && !empty($context['unsaved']))) return self::problem('absent');
            $row = isset($this->record[$event]) ? $this->record[$event] : [];
        } else {
            $rows = $this->rows($event, $bucket);
            if ($rows === null) return self::problem('unreadable');
            if (!array_key_exists($instance, $rows)) {
                if (!$self || empty($context['unsaved'])) return self::problem('absent');
                $row = [];
            } else $row = $rows[$instance];
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
        if (!$this->budget->take(1 + intdiv(strlen((string)$value),64))) return self::problem('limit');
        return ['state'=>'ok','value'=>(string)$value,'self'=>$live,
            'location'=>['event'=>$event,'instrument'=>$form,'bucket'=>$bucket,'instance'=>$instance,'field'=>$ref[1],'code'=>$ref[2]]];
    }

    private static function problem($state) { return ['state'=>$state]; }
}
