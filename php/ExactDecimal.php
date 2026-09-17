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
            $value=trim((string)$value);
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

    public static function multiply($a,$b)
    {
        foreach([$a,$b] as $v)if(!is_string($v)||!preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D',$v))return null;
        $negative=($a[0]==='-')!==($b[0]==='-');$scale=0;
        foreach(['a','b'] as $k){$v=ltrim($$k,'+-');$p=explode('.',$v,2);$scale+=strlen($p[1]??'');$$k=ltrim(str_replace('.','',$v),'0');}
        if(strlen($a)+strlen($b)>self::MAX_DIGITS)return null;
        if($a===''||$b==='')return '0';
        $out=array_fill(0,strlen($a)+strlen($b),0);
        for($i=strlen($a)-1;$i>=0;$i--)for($j=strlen($b)-1;$j>=0;$j--){$n=$out[$i+$j+1]+(int)$a[$i]*(int)$b[$j];$out[$i+$j+1]=$n%10;$out[$i+$j]+=intdiv($n,10);}
        $v=ltrim(implode('',$out),'0');
        if($scale){$v=str_pad($v,$scale+1,'0',STR_PAD_LEFT);$v=substr($v,0,-$scale).'.'.substr($v,-$scale);$v=rtrim(rtrim($v,'0'),'.');}
        return ($negative?'-':'').$v;
    }

    private static function add($a,$b)
    {
        $sa=$a[0]==='-'?-1:1; $sb=$b[0]==='-'?-1:1;
        $a=ltrim(ltrim($a,'-'),'0'); $b=ltrim(ltrim($b,'-'),'0');
        if($a==='')$a='0'; if($b==='')$b='0';
        if($sa===$sb){
            $out='';$carry=0;$i=strlen($a)-1;$j=strlen($b)-1;
            while($i>=0||$j>=0||$carry){
                $v=($i>=0?(int)$a[$i--]:0)+($j>=0?(int)$b[$j--]:0)+$carry;
                $out=(string)($v%10).$out;$carry=intdiv($v,10);
            }
            return ($sa<0&&$out!=='0'?'-':'').$out;
        }
        $cmp=strlen($a)===strlen($b)?strcmp($a,$b):(strlen($a)<strlen($b)?-1:1);
        if($cmp===0)return '0';
        if($cmp<0){$tmp=$a;$a=$b;$b=$tmp;$sa=$sb;}
        $out='';$borrow=0;$j=strlen($b)-1;
        for($i=strlen($a)-1;$i>=0;$i--){
            $v=(int)$a[$i]-$borrow-($j>=0?(int)$b[$j--]:0);$borrow=$v<0?1:0;
            if($v<0)$v+=10;$out=(string)$v.$out;
        }
        $out=ltrim($out,'0');if($out==='')$out='0';
        return ($sa<0&&$out!=='0'?'-':'').$out;
    }
}
