<?php
namespace INSPIRE\UniversalValidator;
require_once __DIR__.'/Logic.php';
require_once __DIR__.'/ExactDecimal.php';
require_once __DIR__.'/TemporalValue.php';

/** Three-valued expression evaluator shared by saved-data and browser preparation. */
final class TemporalLogic
{
    public static function evaluate(array $ast, callable $read, $blank=true, $case=false)
    {
        switch($ast[0]) {
            case 'temporal': return self::evaluate($ast[1],$read,$blank,$case);
            case 'const': return (bool)$ast[1];
            case 'unknown': return null;
            case 'not': $r=self::evaluate($ast[1],$read,!$blank,$case); return $r===null?null:!$r;
            case 'and': case 'or':
                $unknown=false;$and=$ast[0]==='and';
                foreach($ast[1] as $child){$r=self::evaluate($child,$read,$blank,$case);if($r===null)$unknown=true;elseif($r!==$and)return !$and;}
                return $unknown?null:$and;
            case 'cmp': return self::compare($ast[1],self::value($ast[2],$read),self::value($ast[3],$read),$blank,$case);
        }
        return null;
    }
    public static function value(array $op, callable $read)
    {
        if($op[0]==='lit')return $op[1];
        if($op[0]==='unknown')return null;
        if($op[0]==='ref')return $read($op[1],$op[2]);
        if($op[0]==='guard'){
            foreach($op[1] as $g)if((string)$read($g[0],null)!==$g[1])return null;
            return self::value($op[2],$read);
        }
        if($op[0]==='date'){
            $v=self::value($op[3],$read);if($v===null||is_array($v))return null;
            $r=TemporalValue::parse((string)$v,$op[1],$op[2]);return $r['state']==='ok'?['date'=>$r['type'],'value'=>$r['value'],'seconds'=>$r['seconds']]:null;
        }
        if($op[0]==='elapsed'){
            $a=self::value($op[2],$read);$b=self::value($op[3],$read);
            if(!is_array($a)||!is_array($b)||!isset($a['date'],$b['date'])||$a['date']!==$b['date'])return null;
            $r=TemporalValue::elapsed(['state'=>'ok','type'=>$a['date'],'seconds'=>$a['seconds']],['state'=>'ok','type'=>$b['date'],'seconds'=>$b['seconds']],$op[1]);
            return $r['state']==='ok'?['numerator'=>$r['numerator'],'denominator'=>$r['denominator']]:null;
        }
        if($op[0]==='set'||$op[0]==='aggregate'){
            $v=[];foreach($op[2] as $member){$x=self::value($member,$read);if($x===null)return null;$v[]=$x;}
            if($op[0]==='set')return ['set'=>$op[1],'values'=>$v];
            $kind=$op[1];
            if($kind==='count')return (string)count($v);
            if($kind==='exists')return $v?'1':'0';
            $v=array_values(array_filter($v,function($x){return $x!=='';}));
            if($kind==='populated-count')return (string)count($v);
            if($kind==='distinct-count')return (string)count(array_unique($v,SORT_STRING));
            if(!$v)return null;
            $sum=ExactDecimal::sum($v);if($sum['state']!=='ok')return null;
            if($kind==='sum')return $sum['value'];
            if($kind==='average')return ['numerator'=>$sum['value'],'denominator'=>(string)count($v)];
            $best=$v[0];foreach($v as $x)if(Logic::evaluate(['cmp',$kind==='minimum'?'<':'>',['lit',$x],['lit',$best]],[]))$best=$x;
            return $best;
        }
        return null;
    }
    public static function compare($op,$a,$b,$blank,$case)
    {
        if($a===null||$b===null)return null;
        if($op==='identical')return is_scalar($a)&&is_scalar($b)?trim((string)$a)===trim((string)$b):null;
        if(is_array($a)&&isset($a['set']) || is_array($b)&&isset($b['set'])){
            if(is_array($a)&&isset($a['set'])&&is_array($b)&&isset($b['set']))return null;
            $left=is_array($a)&&isset($a['set']);$set=$left?$a:$b;
            if(!$set['values'])return null;
            $and=$set['set']==='all';$unknown=false;
            foreach($set['values'] as $v){$r=self::compare($op,$left?$v:$a,$left?$b:$v,$blank,$case);if($r===null)$unknown=true;elseif($r!==$and)return !$and;}
            return $unknown?null:$and;
        }
        if(is_array($a)&&isset($a['date']) || is_array($b)&&isset($b['date'])){
            if(!is_array($a)||!is_array($b)||!isset($a['date'],$b['date'])||$a['date']!==$b['date'])return null;
            return Logic::evaluate(['cmp',$op,['lit',$a['value']],['lit',$b['value']]],[],$blank,$case);
        }
        if(is_array($a)||is_array($b)){
            $a=is_array($a)?$a:['numerator'=>(string)$a,'denominator'=>'1'];$b=is_array($b)?$b:['numerator'=>(string)$b,'denominator'=>'1'];
            if(!isset($a['numerator'],$a['denominator'],$b['numerator'],$b['denominator']))return null;
            $x=ExactDecimal::multiply($a['numerator'],$b['denominator']);$y=ExactDecimal::multiply($b['numerator'],$a['denominator']);
            if($x===null||$y===null)return null;$a=$x;$b=$y;
        }
        return Logic::evaluate(['cmp',$op,['lit',(string)$a],['lit',(string)$b]],[],$blank,$case);
    }
}
