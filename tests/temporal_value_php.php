<?php
require_once __DIR__.'/../php/TemporalValue.php';
use INSPIRE\UniversalValidator\TemporalValue as T;
$n=0;
function check($x,$why){global $n;$n++;if(!$x)throw new RuntimeException($why);}
check(T::parse('2024-02-29','date')['state']==='ok','leap year');
check(T::parse('2023-02-29','date')['state']==='invalid','nonleap');
check(T::parse('2026-04-31','date')['state']==='invalid','invalid day');
check(T::parse('29/02/2024','date','dmy')['value']==='2024-02-29','display dmy');
check(T::parse('02/29/2024','date','mdy')['value']==='2024-02-29','display mdy');
check(T::parse('2026-09-16 24:00','datetime')['state']==='invalid','hour rollover refused');
check(T::parse('2026-09-16 12:00:60','datetime_seconds')['state']==='invalid','seconds rollover refused');
check(T::parse('2026-09-16 12:00Z','datetime')['state']==='invalid','timezone suffix refused');
check(T::parse('','date')['state']==='absent','blank');
$a=T::parse('2024-02-28','date');$b=T::parse('2024-03-01','date');
check(T::elapsed($a,$b,'days')['numerator']==='172800','calendar leap delta');
check(T::elapsed($b,$a,'days')['numerator']==='-172800','signed delta');
check(T::elapsed($a,$b,'hours')['state']==='invalid','date requires days');
$c=T::parse('2026-09-16 23:59:59','datetime_seconds');$d=T::parse('2026-09-17 00:00:00','datetime_seconds');
check(T::elapsed($c,$d,'seconds')['numerator']==='1','midnight');
check(T::elapsed($a,$c,'days')['state']==='invalid','mixed types');
date_default_timezone_set('America/New_York');
check(T::elapsed(T::parse('2026-03-08 01:30','datetime'),T::parse('2026-03-08 03:30','datetime'),'hours')['numerator']==='7200','wall-clock not DST');
echo "temporal_value_php: $n checks, 0 failures\n";
