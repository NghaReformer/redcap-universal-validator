<?php
namespace INSPIRE\UniversalValidator;

/** Explicit typed operations; never changes legacy string-comparison semantics. */
final class TemporalValue
{
    /** Normalize a date or datetime with strict calendar validation and no timezone conversion. */
    public static function parse($value, $type, $format = 'ymd')
    {
        if (!is_string($value) || $value === '') return ['state'=>'absent'];
        if (!in_array($type, ['date','datetime','datetime_seconds'], true)
            || !in_array($format, ['ymd','dmy','mdy'], true)) return ['state'=>'invalid'];
        $pattern = $format === 'ymd' ? '(\d{4})[-\/](\d{2})[-\/](\d{2})' : '(\d{2})[-\/](\d{2})[-\/](\d{4})';
        if ($type !== 'date') $pattern .= ' (\d{2}):(\d{2})' . ($type === 'datetime_seconds' ? ':(\d{2})' : '');
        if (!preg_match('/^'.$pattern.'$/D', $value, $m)) return ['state'=>'invalid'];
        if ($format === 'ymd') { $year=(int)$m[1]; $month=(int)$m[2]; $day=(int)$m[3]; }
        elseif ($format === 'dmy') { $year=(int)$m[3]; $month=(int)$m[2]; $day=(int)$m[1]; }
        else { $year=(int)$m[3]; $month=(int)$m[1]; $day=(int)$m[2]; }
        if ($year < 1 || !checkdate($month,$day,$year)) return ['state'=>'invalid'];
        $hour=$type==='date'?0:(int)$m[4]; $minute=$type==='date'?0:(int)$m[5];
        $second=$type==='datetime_seconds'?(int)$m[6]:0;
        if ($hour>23 || $minute>59 || $second>59) return ['state'=>'invalid'];
        $canonical=sprintf('%04d-%02d-%02d %02d:%02d:%02d',$year,$month,$day,$hour,$minute,$second);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$canonical,new \DateTimeZone('UTC'));
        if ($date === false) return ['state'=>'invalid'];
        return ['state'=>'ok','type'=>$type==='date'?'date':'datetime',
            'value'=>$type==='date'?substr($canonical,0,10):$canonical,'seconds'=>$date->getTimestamp()];
    }

    /** Exact signed elapsed result, represented as numerator/denominator. */
    public static function elapsed(array $from, array $to, $unit)
    {
        if (($from['state']??null)!=='ok') return ['state'=>$from['state']??'invalid'];
        if (($to['state']??null)!=='ok') return ['state'=>$to['state']??'invalid'];
        if ($from['type']!==$to['type']) return ['state'=>'invalid'];
        $units=['days'=>86400,'hours'=>3600,'minutes'=>60,'seconds'=>1];
        if (!isset($units[$unit]) || ($from['type']==='date' && $unit!=='days')) return ['state'=>'invalid'];
        return ['state'=>'ok','numerator'=>(string)($to['seconds']-$from['seconds']),'denominator'=>(string)$units[$unit]];
    }
}
