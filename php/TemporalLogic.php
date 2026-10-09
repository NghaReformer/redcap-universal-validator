<?php
namespace INSPIRE\UniversalValidator;
require_once __DIR__.'/Logic.php';
require_once __DIR__.'/ExactDecimal.php';
require_once __DIR__.'/TemporalValue.php';

/** Three-valued expression evaluator shared by saved-data and browser preparation. */
final class TemporalLogic
{
    /**
     * $charge(units): bool spends evaluation budget on long exact arithmetic and
     * answers false once the budget is gone, which makes that comparison unknown.
     * Saved-data callers pass AddressResolver::charge(); without it nothing is charged.
     */
    public static function evaluate(array $ast, callable $read, $blank=true, $case=false, ?callable $charge=null)
    {
        switch($ast[0]) {
            case 'temporal': return self::evaluate($ast[1],$read,$blank,$case,$charge);
            case 'const': return (bool)$ast[1];
            case 'unknown': return null;
            case 'not': $r=self::evaluate($ast[1],$read,!$blank,$case,$charge); return $r===null?null:!$r;
            case 'and': case 'or':
                $unknown=false;$and=$ast[0]==='and';
                foreach($ast[1] as $child){$r=self::evaluate($child,$read,$blank,$case,$charge);if($r===null)$unknown=true;elseif($r!==$and)return !$and;}
                return $unknown?null:$and;
            case 'cmp': return self::compare($ast[1],self::value($ast[2],$read,$charge),self::value($ast[3],$read,$charge),$blank,$case,$charge);
        }
        return null;
    }
    public static function value(array $op, callable $read, ?callable $charge=null)
    {
        if($op[0]==='lit')return $op[1];
        if($op[0]==='unknown')return null;
        if($op[0]==='value')return $op[1];   // server-only: an aggregate the resolver already settled
        if($op[0]==='ref')return $read($op[1],$op[2]);
        if($op[0]==='guard'){
            foreach($op[1] as $g)if((string)$read($g[0],null)!==$g[1])return null;
            return self::value($op[2],$read,$charge);
        }
        if($op[0]==='date'){
            $v=self::value($op[3],$read,$charge);if($v===null||is_array($v))return null;
            // A saved blank is a RESOLVED answer ("not entered yet"), not an unknown:
            // it takes the caller's blank polarity exactly as a legacy comparison does.
            if(self::blank($v))return '';
            $r=TemporalValue::parse((string)$v,$op[1],$op[2]);return $r['state']==='ok'?['date'=>$r['type'],'value'=>$r['value'],'seconds'=>$r['seconds']]:null;
        }
        if($op[0]==='elapsed'){
            $a=self::value($op[2],$read,$charge);$b=self::value($op[3],$read,$charge);
            if($a===null||$b===null)return null;
            if($a===''||$b==='')return '';   // elapsed from/to a date not entered yet is itself blank
            if(!is_array($a)||!is_array($b)||!isset($a['date'],$b['date'])||$a['date']!==$b['date'])return null;
            $r=TemporalValue::elapsed(['state'=>'ok','type'=>$a['date'],'seconds'=>$a['seconds']],['state'=>'ok','type'=>$b['date'],'seconds'=>$b['seconds']],$op[1]);
            return $r['state']==='ok'?['numerator'=>$r['numerator'],'denominator'=>$r['denominator']]:null;
        }
        if($op[0]==='set'||$op[0]==='aggregate'){
            $v=[];foreach($op[2] as $member){$x=self::value($member,$read,$charge);if($x===null)return null;$v[]=$x;}
            if($op[0]==='set')return ['set'=>$op[1],'values'=>$v];
            $kind=$op[1];
            if($kind==='count')return (string)count($v);
            if($kind==='exists')return $v?'1':'0';
            $v=array_values(array_filter($v,function($x){return $x!=='';}));
            if($kind==='populated-count')return (string)count($v);
            if($kind==='distinct-count')return (string)count(array_unique($v,SORT_STRING));
            if(!$v)return null;
            if(!self::spend($charge,ExactDecimal::sumCost($v)))return null;
            $sum=ExactDecimal::sum($v);if($sum['state']!=='ok')return null;
            if($kind==='sum')return $sum['value'];
            if($kind==='average')return ['numerator'=>$sum['value'],'denominator'=>(string)count($v)];
            $best=$v[0];foreach($v as $x)if(Logic::evaluate(['cmp',$kind==='minimum'?'<':'>',['lit',$x],['lit',$best]],[]))$best=$x;
            return $best;
        }
        return null;
    }
    public static function compare($op,$a,$b,$blank,$case,?callable $charge=null)
    {
        if($a===null||$b===null)return null;
        if($op==='identical')return is_scalar($a)&&is_scalar($b)?trim((string)$a)===trim((string)$b):null;
        if(is_array($a)&&isset($a['set']) || is_array($b)&&isset($b['set'])){
            if(is_array($a)&&isset($a['set'])&&is_array($b)&&isset($b['set']))return null;
            $left=is_array($a)&&isset($a['set']);$set=$left?$a:$b;
            if(!$set['values'])return null;
            $and=$set['set']==='all';$unknown=false;
            foreach($set['values'] as $v){$r=self::compare($op,$left?$v:$a,$left?$b:$v,$blank,$case,$charge);if($r===null)$unknown=true;elseif($r!==$and)return !$and;}
            return $unknown?null:$and;
        }
        if(is_array($a)&&isset($a['date']) || is_array($b)&&isset($b['date'])){
            if(self::blank($a)||self::blank($b))return Logic::evaluate(['cmp',$op,['lit',is_array($a)?$a['value']:''],['lit',is_array($b)?$b['value']:'']],[],$blank,$case);
            if(!is_array($a)||!is_array($b)||!isset($a['date'],$b['date'])||$a['date']!==$b['date'])return null;
            return Logic::evaluate(['cmp',$op,['lit',$a['value']],['lit',$b['value']]],[],$blank,$case);
        }
        if(is_array($a)||is_array($b)){
            foreach(['a','b'] as $k)if(!is_array($$k))$$k=trim((string)$$k," \t\r\n");
            // Blank against an average or elapsed time: absence, not an unknown.
            if($a===''||$b==='')return Logic::evaluate(['cmp',$op,['lit',is_array($a)?($a['numerator']??''):''],['lit',is_array($b)?($b['numerator']??''):'']],[],$blank,$case);
            $a=is_array($a)?$a:['numerator'=>(string)$a,'denominator'=>'1'];$b=is_array($b)?$b:['numerator'=>(string)$b,'denominator'=>'1'];
            if(!isset($a['numerator'],$a['denominator'],$b['numerator'],$b['denominator']))return null;
            // x/1 against y/n needs one multiplication, not two: the long-hand product
            // of a 4,000-digit numerator by "1", once per set member, cost seconds
            // per comparison and was charged to no budget.
            if(!self::spend($charge,self::scaleCost($a['numerator'],$b['denominator'])+self::scaleCost($b['numerator'],$a['denominator'])))return null;
            $x=self::scaled($a['numerator'],$b['denominator']);$y=self::scaled($b['numerator'],$a['denominator']);
            if($x===null||$y===null)return null;$a=$x;$b=$y;
        }
        return Logic::evaluate(['cmp',$op,['lit',(string)$a],['lit',(string)$b]],[],$blank,$case);
    }
    /** Seconds in one window unit. */
    const WINDOW_UNITS = ['minutes' => 60, 'hours' => 3600, 'days' => 86400, 'weeks' => 604800];

    /**
     * The @UVWINDOW verdict for one value. Pure: no clock is read here, the
     * caller passes the server's wall-clock time. Twin of QRID_windowVerdict
     * (js/engine.js); tests/window_fixture.json drives both.
     *
     * $spec: lo / hi (whole numbers or null = open), unit (minutes, hours,
     * days, weeks), notFuture (bool), type (the field's temporal type) and
     * fromType (the anchor's; null when the rule has no anchor).
     * $value and $anchor are written in $valueFormat / $anchorFormat (ymd for
     * saved data, the field's own format for what the browser reads). $anchor
     * is null when the rule has no anchor. $clock is ['today' => 'Y-m-d',
     * 'now' => 'Y-m-d H:i:s'], only read for notFuture.
     *
     * Arithmetic is in whole seconds of UTC wall-clock time, so a daylight
     * saving change never moves a bound. verdict:
     *   inert          the field is blank, or nothing applied: the anchor is
     *                  blank and there is no notFuture (a green "OK" would claim
     *                  a check that never ran)
     *   unknown        a value cannot be read as a date (partly typed, 31-02,
     *                  a type mismatch, no usable clock)
     *   future         after today (date) or now (datetime)
     *   window-early   before anchor + lo units
     *   window-late    after anchor + hi units
     *   ok             none of the above
     * earliest / latest: the bounds in canonical form, null when open or when
     * the anchor is blank (a blank anchor switches the window off).
     */
    public static function windowVerdict(array $spec, $value, $valueFormat, $anchor, $anchorFormat, $clock)
    {
        $out = ['verdict' => 'ok', 'earliest' => null, 'latest' => null];
        if (!is_string($value) || self::blank($value)) { $out['verdict'] = 'inert'; return $out; }
        $type = isset($spec['type']) ? $spec['type'] : null;
        $v = TemporalValue::parse(trim($value, " \t\r\n"), $type, $valueFormat);
        if ($v['state'] !== 'ok') { $out['verdict'] = 'unknown'; return $out; }
        $lo = isset($spec['lo']) ? $spec['lo'] : null;
        $hi = isset($spec['hi']) ? $spec['hi'] : null;
        $windowReason = null;
        $checked = false;
        if (($lo !== null || $hi !== null) && $anchor !== null && is_string($anchor) && !self::blank($anchor)) {
            $checked = true;
            $fromType = isset($spec['fromType']) ? $spec['fromType'] : null;
            $a = TemporalValue::parse(trim($anchor, " \t\r\n"), $fromType, $anchorFormat);
            $unit = isset($spec['unit']) ? $spec['unit'] : 'days';
            if ($a['state'] !== 'ok' || TemporalValue::family($type) !== TemporalValue::family($fromType)
                || !is_string($unit) || !isset(self::WINDOW_UNITS[$unit])
                || (TemporalValue::family($type) === 'date' && !in_array($unit, ['days', 'weeks'], true))) {
                $out['verdict'] = 'unknown';
                return $out;
            }
            $u = self::WINDOW_UNITS[$unit];
            $diff = $v['seconds'] - $a['seconds'];
            if ($lo !== null) $out['earliest'] = TemporalValue::canonical($a['seconds'] + (int) $lo * $u, $v['type']);
            if ($hi !== null) $out['latest'] = TemporalValue::canonical($a['seconds'] + (int) $hi * $u, $v['type']);
            if ($lo !== null && $diff < (int) $lo * $u) $windowReason = 'window-early';
            elseif ($hi !== null && $diff > (int) $hi * $u) $windowReason = 'window-late';
        }
        if (!empty($spec['notFuture'])) {
            $today = is_array($clock) && isset($clock['today']) ? $clock['today'] : null;
            $now = is_array($clock) && isset($clock['now']) ? TemporalValue::parse((string) $clock['now'], 'datetime_seconds', 'ymd') : null;
            if (!is_string($today) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $today) || !$now || $now['state'] !== 'ok') {
                $out['verdict'] = 'unknown';
                return $out;
            }
            $checked = true;
            $future = $v['type'] === 'date' ? strcmp($v['value'], $today) > 0 : $v['seconds'] > $now['seconds'];
            if ($future) { $out['verdict'] = 'future'; return $out; }
        }
        if ($windowReason !== null) $out['verdict'] = $windowReason;
        elseif (!$checked) $out['verdict'] = 'inert';
        return $out;
    }

    /** What scaled() spends: nothing when it multiplies by 1, which it skips. */
    private static function scaleCost($numerator,$denominator)
    {
        return $denominator==='1'?0:ExactDecimal::multiplyCost($numerator,$denominator);
    }
    /** Units under 1 are free, so short values never touch the budget. */
    private static function spend(?callable $charge,$units)
    {
        return $units<1||$charge===null||$charge($units);
    }
    private static function scaled($numerator,$denominator)
    {
        if($denominator!=='1')return ExactDecimal::multiply($numerator,$denominator);
        return is_string($numerator)&&preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D',$numerator)&&strlen($numerator)<=ExactDecimal::MAX_DIGITS?$numerator:null;
    }
    /** Blank exactly as Logic::compare sees it: empty after trimming space, tab, CR and LF. */
    private static function blank($v)
    {
        return is_scalar($v)&&trim((string)$v," \t\r\n")==='';
    }
}
