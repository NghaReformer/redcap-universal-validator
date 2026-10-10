<?php
/** temporal_perf_decimal_micro: cost of the uncharged primitives on 4,000-digit operands. */
require_once __DIR__ . '/../php/TemporalLogic.php';
use INSPIRE\UniversalValidator\ExactDecimal;
use INSPIRE\UniversalValidator\Logic;
use INSPIRE\UniversalValidator\TemporalLogic;

$d = (int)($argv[1] ?? 4000);
$vals = []; for ($i = 0; $i < 200; $i++) $vals[] = (string)(1 + $i % 9) . str_repeat((string)($i % 10), $d - 1);
$t = function ($label, $reps, callable $f) { $s = hrtime(true); for ($i = 0; $i < $reps; $i++) $f(); printf("%-52s %8.3f ms/op\n", $label, (hrtime(true) - $s) / 1e6 / $reps); };
$t("ExactDecimal::sum of 200 x $d-digit values", 5, function () use ($vals) { ExactDecimal::sum($vals); });
$t("ExactDecimal::multiply($d digits, '200')", 20, function () use ($vals) { ExactDecimal::multiply($vals[0], '200'); });
$t("ExactDecimal::multiply($d digits, '12345')", 5, function () use ($vals) { ExactDecimal::multiply($vals[0], '12345'); });
$t("Logic cmp '<' on two $d-digit values", 200, function () use ($vals) { Logic::evaluate(['cmp','<',['lit',$vals[1]],['lit',$vals[2]]], []); });
$avg = ['numerator'=>ExactDecimal::sum($vals)['value'], 'denominator'=>'200'];
$t("TemporalLogic member <= average (one member)", 20, function () use ($vals, $avg) { TemporalLogic::compare('<=', $vals[3], $avg, true, false); });
$sum = ExactDecimal::sum($vals); printf("sum state=%s length=%d\n", $sum['state'], strlen($sum['value'] ?? ''));
// MAX_DIGITS applies to the SUM even for minimum/maximum, which need no arithmetic.
$nines = array_fill(0, 3, str_repeat('9', 4096));
printf("ExactDecimal::sum of three 4,096-digit 9s: state=%s\n", ExactDecimal::sum($nines)['state']);
