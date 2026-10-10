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
    /**
     * A lookup equality, "same:FOLD:MARK" (FOLD fold|exact, MARK text|point|comma),
     * as [fold, mark] for Logic::lookupKey, or null for a malformed one. The older
     * "identical" is "same:exact:text". Twin: QRID_sameFlags.
     */
    public static function sameFlags($op)
    {
        if($op==='identical')return [false,null];
        $p=explode(':',(string)$op);
        if(count($p)!==3||$p[0]!=='same'||($p[1]!=='fold'&&$p[1]!=='exact')||!in_array($p[2],['text','point','comma'],true))return null;
        return [$p[1]==='fold',$p[2]==='text'?null:$p[2]];
    }
    /** The lookup equality for [fold, mark]: the inverse of sameFlags. */
    public static function sameOp($fold,$mark)
    {
        return 'same:'.($fold?'fold':'exact').':'.($mark===null?'text':$mark);
    }
    public static function compare($op,$a,$b,$blank,$case,?callable $charge=null)
    {
        if($a===null||$b===null)return null;
        if($op==='identical'||strncmp((string)$op,'same:',5)===0){
            $f=self::sameFlags($op);
            return $f!==null&&is_scalar($a)&&is_scalar($b)?Logic::lookupKey($a,$f[0],$f[1])===Logic::lookupKey($b,$f[0],$f[1]):null;
        }
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
    /** The margin a date and time gets past "now" (twin of QRID_CLOCK_SLACK_S). */
    const CLOCK_SLACK_S = 120;
    /** The most the page allows each way for a computer clock that is off (twin of QRID_DEVICE_ERROR_MAX_S). */
    const DEVICE_ERROR_MAX_S = 600;
    /**
     * The margin a date and time gets after saving: the page's margin plus the
     * most it allows for a computer clock that is off. The audit and the scan
     * cannot know that computer's clock, so they allow the most the page
     * could have, and never flag a time the page accepted.
     */
    const AFTER_SAVE_SLACK_S = self::CLOCK_SLACK_S + self::DEVICE_ERROR_MAX_S;

    /**
     * $clock with the margin a date and time gets: futureNow $seconds after
     * "now" (for notFuture) and pastNow as much before it (for notPast and the
     * early end of a window counted from "now"). "now" and "today" are
     * unchanged; $clock comes back as it is when its "now" does not read.
     */
    public static function clockSlack(array $clock, $seconds = self::CLOCK_SLACK_S)
    {
        $now = isset($clock['now']) ? TemporalValue::parse((string) $clock['now'], 'datetime_seconds', 'ymd') : null;
        if (!$now || $now['state'] !== 'ok') return $clock;
        $later = TemporalValue::canonical($now['seconds'] + (int) $seconds, 'datetime');
        $earlier = TemporalValue::canonical($now['seconds'] - (int) $seconds, 'datetime');
        if ($later !== null) $clock['futureNow'] = $later;
        if ($earlier !== null) $clock['pastNow'] = $earlier;
        return $clock;
    }

    /**
     * A period by name, as the page shows it (twin of QRID_windowPeriodLabel):
     * "last month", "the 3 months before this one", "the month of [visit_date]".
     * $relative: counted from today or now; otherwise $name is the "from".
     */
    public static function periodLabel($period, $offLo, $offHi, $name, $relative)
    {
        $p = (string) $period;
        $a = (int) $offLo;
        $b = (int) $offHi;
        $plural = function ($n) use ($p) { return $n . ' ' . $p . ($n === 1 ? '' : 's'); };
        $one = function ($o) use ($p, $name, $relative, $plural) {
            if ($relative) {
                if ($o === 0) return 'this ' . $p;
                if ($o === -1) return 'last ' . $p;
                if ($o === 1) return 'next ' . $p;
                return 'the ' . $p . ' ' . $plural(abs($o)) . ($o < 0 ? ' ago' : ' from now');
            }
            $of = 'the ' . $p . ' of ' . $name;
            return $o === 0 ? $of : $plural(abs($o)) . ($o < 0 ? ' before ' : ' after ') . $of;
        };
        if ($a === $b) return $one($a);
        if ($relative && $b === -1) return 'the ' . $plural(-$a) . ' before this one';
        if ($relative && $a === 1) return 'the ' . $plural($b) . ' after this one';
        if ($relative && $b === 0) return 'this ' . $p . ' and the ' . $plural(-$a) . ' before it';
        if ($relative && $a === 0) return 'this ' . $p . ' and the ' . $plural($b) . ' after it';
        if (!$relative && $b < 0) return (-$b) . ' to ' . $plural(-$a) . ' before the ' . $p . ' of ' . $name;
        if (!$relative && $a > 0) return $a . ' to ' . $plural($b) . ' after the ' . $p . ' of ' . $name;
        return $one($a) . ' to ' . $one($b);
    }

    /**
     * The scan report's derived detail of a @UVWINDOW rule (scan.detailDerive
     * in php/modes.json): windowPeriodText, the period by name, for a rule
     * with a period.
     */
    public static function periodDetail(array $r)
    {
        if (!isset($r['windowPeriod']) || !is_string($r['windowPeriod']) || $r['windowPeriod'] === '') return [];
        $kw = isset($r['windowAnchor']) && is_string($r['windowAnchor']) && $r['windowAnchor'] !== '' ? $r['windowAnchor'] : null;
        $from = isset($r['windowFrom']) && is_string($r['windowFrom']) && $r['windowFrom'] !== '' ? $r['windowFrom'] : null;
        $relative = $from === null && ($kw === null || $kw === 'today' || $kw === 'now');
        $name = $from !== null ? $from : (string) $kw;
        return ['windowPeriodText' => self::periodLabel($r['windowPeriod'],
            isset($r['windowOffLo']) ? $r['windowOffLo'] : 0, isset($r['windowOffHi']) ? $r['windowOffHi'] : 0, $name, $relative)];
    }

    /**
     * The verdict for a window counted from a moment known only to within a
     * margin (a "from" of "now", which a computer a little off fills in): a
     * value passes when some anchor between $anchorLo and $anchorHi accepts
     * it. The earliest bound comes from $anchorLo, the latest from $anchorHi.
     * Twin of QRID_windowVerdictSpread.
     */
    public static function windowVerdictSpread(array $spec, $value, $valueFormat, $anchorLo, $anchorHi, $anchorFormat, $clock)
    {
        $lo = self::windowVerdict($spec, $value, $valueFormat, $anchorLo, $anchorFormat, $clock);
        if ($anchorHi === $anchorLo) return $lo;
        $hi = self::windowVerdict($spec, $value, $valueFormat, $anchorHi, $anchorFormat, $clock);
        $windowish = ['ok', 'window-early', 'window-late'];
        if (!in_array($lo['verdict'], $windowish, true)) return $lo;
        if (!in_array($hi['verdict'], $windowish, true)) return $hi;
        $verdict = $lo['verdict'] === 'window-early' ? 'window-early' : ($hi['verdict'] === 'window-late' ? 'window-late' : 'ok');
        return ['verdict' => $verdict, 'earliest' => $lo['earliest'], 'latest' => $hi['latest']];
    }

    /** The temporal type of a "from" keyword's or written date's anchor: today a date, now a date and time to the second. */
    public static function keywordAnchorType($anchor)
    {
        if ($anchor === 'today') return 'date';
        if ($anchor === 'now') return 'datetime_seconds';
        return self::anchorType($anchor);
    }

    /** Seconds in one window unit. */
    const WINDOW_UNITS = ['minutes' => 60, 'hours' => 3600, 'days' => 86400, 'weeks' => 604800];
    /** Calendar units: months in one. A month is not a fixed number of seconds. */
    const CALENDAR_UNITS = ['months' => 1, 'years' => 12];
    /** The periods a window may name. */
    const WINDOW_PERIODS = ['week', 'month', 'quarter', 'year'];

    /** Every unit name a window takes, in the order a message lists them. */
    public static function windowUnitNames()
    {
        return array_merge(array_keys(self::WINDOW_UNITS), array_keys(self::CALENDAR_UNITS));
    }

    /**
     * The temporal type of a date written in a rule ("from": "2026-01-01"):
     * date, datetime (to the minute) or datetime_seconds, or null when it is
     * not a date. Twin of QRID_anchorType.
     */
    public static function anchorType($text)
    {
        if (!is_string($text)) return null;
        $type = strlen($text) === 10 ? 'date' : (strlen($text) === 16 ? 'datetime' : 'datetime_seconds');
        return TemporalValue::parse($text, $type, 'ymd')['state'] === 'ok' ? $type : null;
    }

    /** Whether a field of $type may count a window in $unit: a date field in days, weeks, months or years. */
    public static function unitFits($unit, $type)
    {
        if (!is_string($unit) || (!isset(self::WINDOW_UNITS[$unit]) && !isset(self::CALENDAR_UNITS[$unit]))) return false;
        return TemporalValue::family($type) !== 'date' || ($unit !== 'minutes' && $unit !== 'hours');
    }

    /**
     * The @UVWINDOW verdict for one value. Pure: no clock is read here, the
     * caller passes the clock to judge by (the server's wall-clock time, or
     * the time the value was saved). Twin of QRID_windowVerdict
     * (js/engine.js); tests/window_fixture.json drives both.
     *
     * $spec: lo / hi (whole numbers or null = open), unit (minutes, hours,
     * days, weeks, months, years), or period (week, month, quarter, year)
     * with offLo / offHi (whole periods, default 0) and weekStart (monday or
     * sunday); notFuture and notPast (bool); type (the field's temporal type)
     * and fromType (the anchor's; null when the rule has no anchor).
     * $value and $anchor are written in $valueFormat / $anchorFormat (ymd for
     * saved data, the field's own format for what the browser reads). $anchor
     * is null when the rule has no anchor, and false when it has one that is
     * not available (unresolved, or not sent to the page). $clock is
     * ['today' => 'Y-m-d', 'now' => 'Y-m-d H:i:s'], read for notFuture and
     * notPast; optional 'futureNow' / 'pastNow' replace 'now' for one of them
     * (the page's margin for a computer clock that is a little off).
     *
     * Minutes to weeks count whole seconds of UTC wall-clock time, so a
     * daylight saving change never moves a bound. Months and years move the
     * calendar date and keep the time, the day clamped to the end of the
     * month. A period runs from the first day of the anchor's week, month,
     * quarter or year, moved by offLo periods, to the last day of the period
     * offHi periods on; a datetime field takes it from 00:00:00 to 23:59:59.
     * verdict:
     *   inert          the field is blank, or nothing applied: the anchor is
     *                  blank and there is no notFuture or notPast (a green "OK"
     *                  would claim a check that never ran)
     *   unknown        a value cannot be read as a date (partly typed, 31-02,
     *                  a type mismatch, no usable clock, a bound past 9999)
     *   future         after today (date) or now (datetime)
     *   past           before today (date) or now (datetime)
     *   window-early   before the earliest bound
     *   window-late    after the latest bound
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
        $period = isset($spec['period']) ? $spec['period'] : null;
        $unit = isset($spec['unit']) ? $spec['unit'] : 'days';
        $hasWindow = $lo !== null || $hi !== null || $period !== null;
        $windowReason = null;
        $checked = false;
        // The window part cannot be judged: its anchor is unavailable ($anchor
        // false: unresolved, or not sent), unreadable, or of another kind. That
        // never stops "notFuture" or "notPast", which do not depend on it.
        $windowUnknown = false;
        $a = null;
        if ($hasWindow && $anchor === false) {
            $windowUnknown = true;
        } elseif ($hasWindow && is_string($anchor) && !self::blank($anchor)) {
            $fromType = isset($spec['fromType']) ? $spec['fromType'] : null;
            $a = TemporalValue::parse(trim($anchor, " \t\r\n"), $fromType, $anchorFormat);
            // A period reads only the anchor's day, so it may count from either kind of date.
            if ($a['state'] !== 'ok' || ($period === null
                    && (TemporalValue::family($type) !== TemporalValue::family($fromType) || !self::unitFits($unit, $type)))) {
                $windowUnknown = true;
            }
        }
        if (!$windowUnknown && $hasWindow && is_string($anchor) && !self::blank($anchor)) {
            // A field without seconds reads its anchor to the minute too, so the
            // bounds it is judged by are the bounds its message can show.
            if ($type === 'datetime') $a['seconds'] -= (($a['seconds'] % 60) + 60) % 60;
            if ($period !== null || isset(self::CALENDAR_UNITS[$unit])) {
                $b = self::calendarBounds($spec, $a, $v['type']);
                if ($b === null) {
                    $windowUnknown = true;
                } else {
                    $checked = true;
                    $out['earliest'] = $b[0];
                    $out['latest'] = $b[1];
                    if ($b[0] !== null && strcmp($v['value'], $b[0]) < 0) $windowReason = 'window-early';
                    elseif ($b[1] !== null && strcmp($v['value'], $b[1]) > 0) $windowReason = 'window-late';
                }
            } else {
                $checked = true;
                $u = self::WINDOW_UNITS[$unit];
                $diff = $v['seconds'] - $a['seconds'];
                if ($lo !== null) $out['earliest'] = TemporalValue::canonical($a['seconds'] + (int) $lo * $u, $v['type']);
                if ($hi !== null) $out['latest'] = TemporalValue::canonical($a['seconds'] + (int) $hi * $u, $v['type']);
                if ($lo !== null && $diff < (int) $lo * $u) $windowReason = 'window-early';
                elseif ($hi !== null && $diff > (int) $hi * $u) $windowReason = 'window-late';
            }
        }
        $notFuture = !empty($spec['notFuture']);
        $notPast = !empty($spec['notPast']);
        if ($notFuture || $notPast) {
            $today = is_array($clock) && isset($clock['today']) ? $clock['today'] : null;
            $now = is_array($clock) && isset($clock['now']) ? TemporalValue::parse((string) $clock['now'], 'datetime_seconds', 'ymd') : null;
            if (!is_string($today) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $today) || !$now || $now['state'] !== 'ok') {
                // No clock: a window the value broke is still a finding.
                $out['verdict'] = $windowReason !== null ? $windowReason : 'unknown';
                return $out;
            }
            $checked = true;
            if ($notFuture) {
                $limit = self::clockMoment($clock, 'futureNow', $now);
                if ($v['type'] === 'date' ? strcmp($v['value'], $today) > 0 : $v['seconds'] > $limit) {
                    $out['verdict'] = 'future';
                    return $out;
                }
            }
            if ($notPast) {
                $limit = self::clockMoment($clock, 'pastNow', $now);
                if ($v['type'] === 'date' ? strcmp($v['value'], $today) < 0 : $v['seconds'] < $limit) {
                    $out['verdict'] = 'past';
                    return $out;
                }
            }
        }
        if ($windowUnknown) $out['verdict'] = 'unknown';
        elseif ($windowReason !== null) $out['verdict'] = $windowReason;
        elseif (!$checked) $out['verdict'] = 'inert';
        return $out;
    }

    /** The clock's $key moment in seconds, or 'now' when it has none that reads. */
    private static function clockMoment(array $clock, $key, array $now)
    {
        if (isset($clock[$key])) {
            $m = TemporalValue::parse((string) $clock[$key], 'datetime_seconds', 'ymd');
            if ($m['state'] === 'ok') return $m['seconds'];
        }
        return $now['seconds'];
    }

    /**
     * [earliest, latest] of a months/years window or a period, in the value's
     * canonical form (null for an open end), or null when a bound falls
     * outside the years 1-9999 or the spec cannot be read.
     */
    private static function calendarBounds(array $spec, array $a, $valueType)
    {
        $anchor = TemporalValue::canonical($a['seconds'], 'datetime');
        if ($anchor === null) return null;
        $day = substr($anchor, 0, 10);
        if (isset($spec['period'])) {
            $period = $spec['period'];
            $start = TemporalValue::periodStart($day, $period, isset($spec['weekStart']) ? $spec['weekStart'] : 'monday');
            $offLo = isset($spec['offLo']) ? $spec['offLo'] : 0;
            $offHi = isset($spec['offHi']) ? $spec['offHi'] : 0;
            $first = $start === null ? null : TemporalValue::periodShift($start, $period, $offLo);
            $lastStart = $start === null ? null : TemporalValue::periodShift($start, $period, $offHi);
            $last = $lastStart === null ? null : TemporalValue::periodEnd($lastStart, $period);
            if ($first === null || $last === null) return null;
            return $valueType === 'date' ? [$first, $last] : [$first . ' 00:00:00', $last . ' 23:59:59'];
        }
        $k = self::CALENDAR_UNITS[$spec['unit']];
        $time = $valueType === 'date' ? '' : substr($anchor, 10);
        $out = [null, null];
        foreach (['lo' => 0, 'hi' => 1] as $key => $i) {
            if (!isset($spec[$key])) continue;
            $d = TemporalValue::shiftMonths($day, (int) $spec[$key] * $k);
            if ($d === null) return null;
            $out[$i] = $d . $time;
        }
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
