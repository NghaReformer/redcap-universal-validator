<?php
namespace INSPIRE\UniversalValidator;

/** Bounded exact decimal sums; no floats or optional PHP extensions. */
final class ExactDecimal
{
    const MAX_DIGITS = 4096;

    public static function sum(array $values)
    {
        $sum='0'; $scale=0;
        foreach ($values as $value) {
            if (!is_scalar($value)) return ['state'=>'invalid'];
            // Logic::compare's trim set. PHP's default also strips NUL and VT, so
            // "9\x0B" summed as 9 and then lost every min/max comparison as text.
            $value=trim((string)$value," \t\r\n");
            if (!preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D',$value)) return ['state'=>'invalid'];
            if (strlen($value)>self::MAX_DIGITS) return ['state'=>'limit'];
            $negative=$value[0]==='-'; $value=ltrim($value,'+-');
            $parts=explode('.',$value,2); $fraction=$parts[1]??'';
            $nextScale=strlen($fraction); $digits=ltrim($parts[0].$fraction,'0');
            if ($digits==='') $digits='0';
            if ($negative && $digits!=='0') $digits='-'.$digits;
            $target=max($scale,$nextScale);
            $sum=self::add($sum.str_repeat('0',$target-$scale),$digits.str_repeat('0',$target-$nextScale));
            if (strlen($sum)>self::MAX_DIGITS) return ['state'=>'limit'];
            $scale=$target;
        }
        $negative=$sum[0]==='-'; $digits=ltrim($sum,'-');
        if ($scale) {
            $digits=str_pad($digits,$scale+1,'0',STR_PAD_LEFT);
            $digits=substr($digits,0,-$scale).'.'.substr($digits,-$scale);
            $digits=rtrim(rtrim($digits,'0'),'.');
        }
        $digits=ltrim($digits,'0');
        if ($digits==='' || $digits[0]==='.') $digits='0'.$digits;
        return ['state'=>'ok','value'=>($negative&&$digits!=='0'?'-':'').$digits];
    }

    /**
     * Evaluation-budget units for sum($values): one per 64 bytes of each value,
     * so values shorter than that are free and only long ones pay. Callers charge
     * this BEFORE summing; a 4,000-digit column re-summed per host context was
     * seconds of work that spent nothing (wargame P1).
     */
    public static function sumCost(array $values)
    {
        $units=0;
        foreach($values as $value)if(is_scalar($value))$units+=intdiv(strlen((string)$value),64);
        return $units;
    }

    /**
     * Budget units for multiply($a,$b), on the same scale as sumCost(): the longer
     * operand's 64-byte units once per 7-digit limb of the shorter, which is how
     * many limb products product() forms. A count or a time unit is one limb.
     */
    public static function multiplyCost($a,$b)
    {
        $long=max(strlen((string)$a),strlen((string)$b));$short=min(strlen((string)$a),strlen((string)$b));
        return intdiv($long*max(1,intdiv($short+6,7)),64);
    }

    public static function multiply($a,$b)
    {
        foreach([$a,$b] as $v)if(!is_string($v)||!preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D',$v))return null;
        $negative=($a[0]==='-')!==($b[0]==='-');$scale=0;
        foreach(['a','b'] as $k){$v=ltrim($$k,'+-');$p=explode('.',$v,2);$scale+=strlen($p[1]??'');$$k=ltrim(str_replace('.','',$v),'0');}
        if(strlen($a)+strlen($b)>self::MAX_DIGITS)return null;
        if($a===''||$b==='')return '0';
        $v=self::product($a,$b);
        if($scale){$v=str_pad($v,$scale+1,'0',STR_PAD_LEFT);$v=substr($v,0,-$scale).'.'.substr($v,-$scale);$v=rtrim(rtrim($v,'0'),'.');}
        return ($negative?'-':'').$v;
    }

    /**
     * |a| * |b| over non-empty digit strings without leading zeros, in limbs of
     * machine-integer width. The digit-at-a-time loop did strlen(a)*strlen(b)
     * steps: 4-7 ms for one 4,000-digit member scaled by an average's count,
     * repeated per member and per host context (wargame P1).
     * Column sums are carried once at the end, so each column must fit an int:
     * at most min(limbs) terms, because the two lengths together are at most
     * MAX_DIGITS. 64-bit, 7-digit limbs: 293 * (10^7-1)^2 < 3e16. 32-bit,
     * 2-digit limbs: 1,024 * 99^2 < 1.1e7.
     */
    private static function product($a,$b)
    {
        $width=PHP_INT_SIZE>=8?7:2;$base=(int)('1'.str_repeat('0',$width));
        if(strlen($a)<strlen($b)){$tmp=$a;$a=$b;$b=$tmp;}
        $x=self::toLimbs($a,$width);$y=self::toLimbs($b,$width);
        if(count($y)===1){
            // An average's count or an elapsed time's denominator: one pass, carried
            // as it goes. Each step is below base^2, so it fits an int.
            $m=$y[0];$carry=0;
            foreach($x as $k=>$xi){$v=$xi*$m+$carry;$carry=intdiv($v,$base);$x[$k]=$v-$carry*$base;}
            if($carry)$x[]=$carry;
            return self::fromLimbs($x,$width);
        }
        $out=array_fill(0,count($x)+count($y),0);
        foreach($y as $j=>$yj){
            if($yj===0)continue;
            foreach($x as $i=>$xi)$out[$i+$j]+=$xi*$yj;
        }
        $carry=0;
        foreach($out as $k=>$v){$v+=$carry;$carry=intdiv($v,$base);$out[$k]=$v-$carry*$base;}
        return self::fromLimbs($out,$width);
    }

    /** Least significant limb first. str_split and intval do the per-limb work in C. */
    private static function toLimbs($digits,$width)
    {
        $pad=($width-strlen($digits)%$width)%$width;
        return array_map('intval',array_reverse(str_split(str_repeat('0',$pad).$digits,$width)));
    }

    /** Canonical digits of least-significant-first limbs: no leading zeros, '0' for zero. */
    private static function fromLimbs(array $limbs,$width)
    {
        $digits=ltrim(vsprintf(str_repeat('%0'.$width.'d',count($limbs)),array_reverse($limbs)),'0');
        return $digits===''?'0':$digits;
    }

    private static function add($a,$b)
    {
        // Both operands fit a machine integer with room for the carry. That is
        // nearly every real value, and it is exact: no float is involved.
        if(PHP_INT_SIZE>=8&&strlen($a)<=18&&strlen($b)<=18)return (string)((int)$a+(int)$b);
        return self::addDigits($a,$b);
    }

    /**
     * Signed addition of canonical digit strings, in limbs of machine-integer
     * width. The earlier digit-at-a-time loop prepended to its result string,
     * which copies the whole string per digit: quadratic in the length, and a
     * column of 4,096-digit values is something a data-entry user can create.
     */
    private static function addDigits($a,$b)
    {
        $sa=$a[0]==='-'?-1:1; $sb=$b[0]==='-'?-1:1;
        $a=ltrim(ltrim($a,'-'),'0'); $b=ltrim(ltrim($b,'-'),'0');
        if($a==='')$a='0'; if($b==='')$b='0';
        if($sa===$sb){$out=self::limbs($a,$b,1);return ($sa<0&&$out!=='0'?'-':'').$out;}
        $cmp=strlen($a)===strlen($b)?strcmp($a,$b):(strlen($a)<strlen($b)?-1:1);
        if($cmp===0)return '0';
        if($cmp<0){$tmp=$a;$a=$b;$b=$tmp;$sa=$sb;}
        $out=self::limbs($a,$b,-1);
        return ($sa<0&&$out!=='0'?'-':'').$out;
    }

    /** |a| + |b|, or |a| - |b| with |a| >= |b|, over non-negative digit strings. */
    private static function limbs($a,$b,$sign)
    {
        $width=PHP_INT_SIZE>=8?15:8;$base=(int)('1'.str_repeat('0',$width));
        // The walk is over a's limbs: for a sum, a must be the longer one.
        if($sign>0&&strlen($a)<strlen($b)){$tmp=$a;$a=$b;$b=$tmp;}
        $x=self::toLimbs($a,$width);$y=self::toLimbs($b,$width);
        $out=[];$carry=0;
        foreach($x as $k=>$xk){
            $v=$xk+$sign*($y[$k]??0)+$carry;$carry=0;
            if($v>=$base){$v-=$base;$carry=1;}elseif($v<0){$v+=$base;$carry=-1;}
            $out[]=$v;
        }
        if($carry>0)$out[]=1;
        return self::fromLimbs($out,$width);
    }
}
