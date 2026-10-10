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

    /**
     * The temporal type and display format a REDCap validation name implies:
     * date_dmy -> date/dmy, datetime_mdy -> datetime/mdy, datetime_seconds_ymd
     * -> datetime_seconds/ymd. null for any other validation (or none).
     */
    public static function fromValidation($validation)
    {
        $v = is_string($validation) ? $validation : '';
        if (strpos($v, 'datetime_seconds_') === 0) $type = 'datetime_seconds';
        elseif (strpos($v, 'datetime_') === 0) $type = 'datetime';
        elseif (strpos($v, 'date_') === 0) $type = 'date';
        else return null;
        $format = substr($v, -3);
        if (!in_array($format, ['ymd', 'dmy', 'mdy'], true)) return null;
        return ['type' => $type, 'format' => $format];
    }

    /** date | datetime: the class a temporal type compares within. */
    public static function family($type)
    {
        return $type === 'date' ? 'date' : 'datetime';
    }

    /**
     * Seconds since the epoch, read as UTC wall-clock time (no timezone
     * conversion), back to the canonical form parse() produces for $type.
     * null outside the years 1-9999, which parse() cannot read back either.
     */
    public static function canonical($seconds, $type)
    {
        $d = (new \DateTimeImmutable('@' . (int) $seconds))->setTimezone(new \DateTimeZone('UTC'));
        $year = (int) $d->format('Y');
        if ($year < 1 || $year > 9999) return null;
        if ($type === 'date') return $d->format('Y-m-d');
        return $d->format('Y-m-d H:i:s');
    }

    /**
     * A canonical value written the way a REDCap field of this type and format
     * displays it: dmy -> 31-12-2026, mdy -> 12-31-2026, a datetime without
     * seconds drops them. Twin of QRID_temporalFormat (js/engine.js).
     */
    public static function format($canonical, $type, $format)
    {
        if (!is_string($canonical) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2})(?::(\d{2}))?)?$/D', $canonical, $m)) return '';
        $date = $format === 'dmy' ? $m[3] . '-' . $m[2] . '-' . $m[1]
              : ($format === 'mdy' ? $m[2] . '-' . $m[3] . '-' . $m[1] : $m[1] . '-' . $m[2] . '-' . $m[3]);
        if ($type === 'date') return $date;
        $time = (isset($m[4]) ? $m[4] : '00') . ':' . (isset($m[5]) ? $m[5] : '00');
        if ($type === 'datetime_seconds') $time .= ':' . (isset($m[6]) && $m[6] !== '' ? $m[6] : '00');
        return $date . ' ' . $time;
    }

    /** Days in month $m of year $y, proleptic Gregorian. */
    public static function daysInMonth($y, $m)
    {
        if ($m === 2) return ($y % 4 === 0 && ($y % 100 !== 0 || $y % 400 === 0)) ? 29 : 28;
        return in_array($m, [4, 6, 9, 11], true) ? 30 : 31;
    }

    /** A valid Y-M-D date as [year, month, day], or null. */
    private static function ymd($date)
    {
        if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m)) return null;
        $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
        if ($y < 1 || !checkdate($mo, $d, $y)) return null;
        return [$y, $mo, $d];
    }

    /**
     * Calendar arithmetic for @UVWINDOW months, years and periods. Every
     * function takes and returns Y-M-D dates (null for an invalid input or a
     * result outside the years 1-9999) and has a twin in js/engine.js
     * (QRID_shiftMonths and the rest); tests/window_fixture.json "calendar"
     * drives both.
     *
     * shiftMonths: $n calendar months on, the day clamped to the end of the
     * target month: Jan 31 + 1 month = Feb 28 (29 in a leap year), Feb 29 + 12
     * months = Feb 28. Never DateTime::modify, which overflows into March.
     */
    public static function shiftMonths($date, $n)
    {
        $p = self::ymd($date);
        if ($p === null || !is_int($n)) return null;
        $total = $p[0] * 12 + ($p[1] - 1) + $n;
        if ($total < 12 || $total > 9999 * 12 + 11) return null;
        $y = intdiv($total, 12);
        $mo = $total % 12 + 1;
        return sprintf('%04d-%02d-%02d', $y, $mo, min($p[2], self::daysInMonth($y, $mo)));
    }

    /** $n days on. */
    public static function shiftDays($date, $n)
    {
        $p = self::parse(is_string($date) ? $date : '', 'date', 'ymd');
        if ($p['state'] !== 'ok' || !is_int($n)) return null;
        return self::canonical($p['seconds'] + $n * 86400, 'date');
    }

    /** ISO day of the week: 1 Monday ... 7 Sunday. */
    public static function isoWeekday($date)
    {
        $p = self::parse(is_string($date) ? $date : '', 'date', 'ymd');
        if ($p['state'] !== 'ok') return null;
        $days = (int) floor($p['seconds'] / 86400);   // 1970-01-01 was a Thursday
        return (($days + 3) % 7 + 7) % 7 + 1;
    }

    /** The first day of the week, month, quarter or year holding $date. A week starts on $weekStart (monday or sunday). */
    public static function periodStart($date, $period, $weekStart = 'monday')
    {
        $p = self::ymd($date);
        if ($p === null) return null;
        switch ($period) {
            case 'week':
                if ($weekStart !== 'monday' && $weekStart !== 'sunday') return null;
                $back = (self::isoWeekday($date) - ($weekStart === 'sunday' ? 7 : 1) + 7) % 7;
                return self::shiftDays($date, -$back);
            case 'month':   return sprintf('%04d-%02d-01', $p[0], $p[1]);
            case 'quarter': return sprintf('%04d-%02d-01', $p[0], intdiv($p[1] - 1, 3) * 3 + 1);
            case 'year':    return sprintf('%04d-01-01', $p[0]);
        }
        return null;
    }

    /** The start of the period $n periods on from the period starting $start. */
    public static function periodShift($start, $period, $n)
    {
        if (!is_int($n)) return null;
        switch ($period) {
            case 'week':    return self::shiftDays($start, 7 * $n);
            case 'month':   return self::shiftMonths($start, $n);
            case 'quarter': return self::shiftMonths($start, 3 * $n);
            case 'year':    return self::shiftMonths($start, 12 * $n);
        }
        return null;
    }

    /** The last day of the period starting $start. */
    public static function periodEnd($start, $period)
    {
        $next = self::periodShift($start, $period, 1);
        return $next === null ? null : self::shiftDays($next, -1);
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
