<?php
/**
 * Adversarial regression suite for event/instance references.
 *
 * Part 1 is DIFFERENTIAL: AddressResolver::shareAcrossContexts() reads a saved
 * collection once per record instead of once per host context. It must return
 * exactly what the unshared resolver returns, from every host context, for
 * every reference and binding shape, on randomized records. The only permitted
 * difference is the per-asker `self` flag on the members of a memoized
 * aggregate, which the shared path removes on purpose.
 *
 * Part 2 pins exact-decimal arithmetic against independently computed answers.
 */
require_once __DIR__ . '/../php/AddressResolver.php';
require_once __DIR__ . '/../php/TemporalLogic.php';
use INSPIRE\UniversalValidator\AddressResolver;
use INSPIRE\UniversalValidator\ExactDecimal;
use INSPIRE\UniversalValidator\ProjectShape;
use INSPIRE\UniversalValidator\ReferenceBudget;

$n = 0; $fail = 0;
function check($label, $cond) {
    global $n, $fail; $n++;
    if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
}

// A seeded generator: the same records on every runtime and every run.
$seed = 20260920;
function rnd($max) { global $seed; $seed = ($seed * 1103515245 + 12345) & 0x7fffffff; return $seed % $max; }
function pick(array $a) { return $a[rnd(count($a))]; }

// Two arms; a repeating instrument, a non-repeating one, and a repeating EVENT.
$shape = new ProjectShape([
    1 => ['name'=>'base_arm_1',  'arm'=>1, 'order'=>0, 'forms'=>['visit','labs','demo'], 'repeats'=>['visit','labs'], 'eventRepeats'=>false],
    2 => ['name'=>'follow_arm_1','arm'=>1, 'order'=>1, 'forms'=>['visit','demo'],        'repeats'=>['visit'],        'eventRepeats'=>false],
    3 => ['name'=>'diary_arm_1', 'arm'=>1, 'order'=>2, 'forms'=>['visit','labs'],        'repeats'=>[],               'eventRepeats'=>true],
    4 => ['name'=>'base_arm_2',  'arm'=>2, 'order'=>3, 'forms'=>['visit'],               'repeats'=>['visit'],        'eventRepeats'=>false],
], [
    'weight'=>['form'=>'visit','type'=>'text'], 'vkey'=>['form'=>'visit','type'=>'text'],
    'flags'=>['form'=>'visit','type'=>'checkbox'],
    'result'=>['form'=>'labs','type'=>'text'], 'lkey'=>['form'=>'labs','type'=>'text'],
    'sex'=>['form'=>'demo','type'=>'text'],
]);

function randomRecord() {
    $values = ['', '', '0', '5', '5', '07', '7.50', '-3', '12', 'abc', ' 9 ', '1000000000000000000000'];
    $keys = ['A', 'B', 'B', 'C', '', 'a'];
    $row = function ($form) use ($values, $keys) {
        if ($form === 'visit') return ['weight'=>pick($values), 'vkey'=>pick($keys), 'flags'=>['1'=>(string)rnd(2), '2'=>(string)rnd(2)], 'visit_complete'=>'2'];
        return ['result'=>pick($values), 'lkey'=>pick($keys), 'labs_complete'=>'2'];
    };
    $ids = function () { $out = []; $k = 0; for ($i = rnd(7); $i > 0; $i--) { $k += 1 + rnd(3); $out[] = $k; } return $out; };
    $record = [1=>['sex'=>pick(['1','2',''])], 2=>['sex'=>''], 'repeat_instances'=>[]];
    foreach ([1=>['visit','labs'], 2=>['visit'], 4=>['visit']] as $event=>$forms) {
        foreach ($forms as $form) foreach ($ids() as $id) $record['repeat_instances'][$event][$form][$id] = $row($form);
    }
    foreach ($ids() as $id) $record['repeat_instances'][3][''][$id] = $row('visit') + $row('labs');
    return $record;
}

/** Every host context a saved-data caller would evaluate from. */
function contexts(array $record) {
    $out = [['event'=>1,'instrument'=>'demo','instance'=>1,'values'=>$record[1]]];
    foreach ($record['repeat_instances'] as $event=>$buckets) foreach ($buckets as $bucket=>$rows) foreach ($rows as $id=>$row) {
        foreach ($bucket === '' ? ['visit','labs'] : [$bucket] as $form) {
            $out[] = ['event'=>$event,'instrument'=>$form,'instance'=>$id,
                      'values'=>array_filter($row, function ($v) { return $v !== ''; })];
        }
    }
    return $out;
}

$refs = [
    ['ref','weight',null], ['ref','result',null], ['ref','sex',null], ['ref','flags','1'],
    ['qref','weight',null,null,'any-instance'], ['qref','weight',null,null,'all-instances'],
    ['qref','weight',null,null,'first-instance'], ['qref','weight',null,null,'last-instance'],
    ['qref','weight',null,null,'previous-instance'], ['qref','weight',null,null,'next-instance'],
    ['qref','weight',null,null,'current-instance'], ['qref','weight',null,null,'2'],
    ['qref','weight',null,'base_arm_1','any-instance'], ['qref','result',null,'base_arm_1','all-instances'],
    ['qref','flags','2','follow_arm_1','any-instance'], ['qref','weight',null,'diary_arm_1','any-instance'],
    ['qref','weight',null,'previous-event-name','last-instance'], ['qref','weight',null,'first-event-name','any-instance'],
    ['qref','weight',null,'base_arm_2','any-instance'], ['qref','sex',null,'base_arm_1',null],
];
$bindings = [];
foreach (['count','exists','populated-count','distinct-count','sum','minimum','maximum','average','any','all'] as $aggregate) {
    $bindings[] = ['field'=>'weight','aggregate'=>$aggregate];
    $bindings[] = ['field'=>'weight','aggregate'=>$aggregate,'excludeCurrent'=>true];
    $bindings[] = ['field'=>'result','event'=>'base_arm_1','aggregate'=>$aggregate];
    $bindings[] = ['field'=>'weight','events'=>['base_arm_1','follow_arm_1','diary_arm_1'],'aggregate'=>$aggregate];
    $bindings[] = ['field'=>'weight','events'=>'arm','arm'=>1,'aggregate'=>$aggregate];
    $bindings[] = ['field'=>'weight','instance'=>'any-instance','aggregate'=>$aggregate];
    // A relative instance is a different question from every host context.
    $bindings[] = ['field'=>'weight','instance'=>'previous-instance','aggregate'=>$aggregate];
    $bindings[] = ['field'=>'weight','instance'=>'current-instance','aggregate'=>$aggregate];
    $bindings[] = ['field'=>'result','event'=>'base_arm_1','match'=>['lkey'=>'[vkey]'],'aggregate'=>$aggregate];
}
$bindings[] = ['field'=>'result','event'=>'base_arm_1','match'=>['lkey'=>'[vkey]']];
$bindings[] = ['field'=>'weight','instance'=>'previous-instance'];
$bindings[] = ['field'=>'weight','event'=>'follow_arm_1','instance'=>'last-instance'];
$bindings[] = ['field'=>'weight','excludeCurrent'=>true,'match'=>['vkey'=>'[vkey]']];

/** `self` is per-asker; the shared memo drops it from aggregate members by design. */
function comparable(array $result, $memoizable) {
    if ($memoizable && isset($result['members'])) foreach ($result['members'] as $k=>$m) unset($result['members'][$k]['self']);
    return $result;
}

$records = 60; $compared = 0; $collections = 0;
for ($r = 0; $r < $records; $r++) {
    $record = randomRecord();
    $shared = (new AddressResolver($shape, $record, 10000, new ReferenceBudget(PHP_INT_MAX)))->shareAcrossContexts();
    foreach (contexts($record) as $context) {
        $plain = new AddressResolver($shape, $record, 10000, new ReferenceBudget(PHP_INT_MAX));
        foreach ($refs as $ref) {
            $a = $plain->resolve($ref, $context); $b = $shared->resolve($ref, $context);
            if (isset($a['members'])) $collections++;
            $compared++;
            if ($a !== $b) { check('shared resolve differs: ' . json_encode([$ref, $context['event'], $context['instrument'], $context['instance'], $a, $b]), false); break 3; }
        }
        foreach ($bindings as $binding) {
            $memoizable = isset($binding['aggregate']) && empty($binding['match']) && empty($binding['excludeCurrent']);
            $a = comparable($plain->resolveBinding($binding, $context), $memoizable);
            $b = comparable($shared->resolveBinding($binding, $context), $memoizable);
            $compared++;
            if ($a !== $b) { check('shared binding differs: ' . json_encode([$binding, $context['event'], $context['instrument'], $context['instance'], $a, $b]), false); break 3; }
        }
    }
}
check("shared and unshared resolvers agree on $compared resolutions", $compared > 20000);
check('the comparison exercised real collections', $collections > 1000);

// A live value that differs from the saved one must not be answered from the memo.
$record = ['repeat_instances'=>[1=>['visit'=>[1=>['weight'=>'10','visit_complete'=>'2'], 2=>['weight'=>'20','visit_complete'=>'2']]]]];
$shared = (new AddressResolver($shape, $record))->shareAcrossContexts();
$sum = ['field'=>'weight','aggregate'=>'sum'];
$saved = $shared->resolveBinding($sum, ['event'=>1,'instrument'=>'visit','instance'=>1,'values'=>['weight'=>'10']]);
$edited = $shared->resolveBinding($sum, ['event'=>1,'instrument'=>'visit','instance'=>2,'values'=>['weight'=>'25']]);
check('memoized saved sum', $saved['state'] === 'ok' && $saved['value'] === '30');
check('an edited own value bypasses the memo', $edited['state'] === 'ok' && $edited['value'] === '35');
$unsaved = $shared->resolveBinding($sum, ['event'=>1,'instrument'=>'visit','instance'=>3,'unsaved'=>true,'values'=>['weight'=>'5']]);
check('an unsaved entry is never answered from the memo', $unsaved['state'] === 'ok' && $unsaved['value'] === '35');

// Sharing must lower the cost of a record, never raise its ceiling silently:
// 600 entries asked from 600 host contexts fits the default budget only when shared.
$rows = []; for ($i = 1; $i <= 600; $i++) $rows[$i] = ['weight'=>(string)($i % 9), 'visit_complete'=>'2'];
$record = ['repeat_instances'=>[1=>['visit'=>$rows]]];
$run = function ($share) use ($shape, $record, $rows) {
    $resolver = new AddressResolver($shape, $record, 10000, new ReferenceBudget());
    if ($share) $resolver->shareAcrossContexts();
    $ok = 0;
    foreach ($rows as $id=>$row) {
        $r = $resolver->resolveBinding(['field'=>'weight','aggregate'=>'average'], ['event'=>1,'instrument'=>'visit','instance'=>$id,'values'=>$row]);
        if ($r['state'] === 'ok') $ok++;
    }
    return $ok;
};
check('600 host contexts resolve within one budget when shared', $run(true) === 600);
check('the unshared path still reports its limit instead of a partial answer', $run(false) < 600);

// ---- Part 2: exact decimals --------------------------------------------------
$sums = [
    [['0.1','0.2'], '0.3'], [['-0.1','0.1'], '0'], [['+5','5.','.5'], '10.5'], [['-0','0.000'], '0'],
    [['999999999999999999','1'], '1000000000000000000'], [['9223372036854775807','1'], '9223372036854775808'],
    [['-9223372036854775808','-1'], '-9223372036854775809'], [['1e5'], null], [['0x10'], null], [[' 7 ','3'], '10'],
    [['100000000000000000000000000000','-99999999999999999999999999999.5'], '0.5'],
    [['0.000000000000000000000000000001','0.000000000000000000000000000002'], '0.000000000000000000000000000003'],
    [['12345678901234567','-12345678901234568'], '-1'], [['5','-5','0.00'], '0'], [['-1.5','-2.5'], '-4'],
];
foreach ($sums as $case) {
    $got = ExactDecimal::sum($case[0]);
    check('sum ' . json_encode($case[0]), $case[1] === null ? $got['state'] !== 'ok' : ($got['state'] === 'ok' && $got['value'] === $case[1]));
}
// Machine-integer fast path against the digit-string path, across the boundary.
$slow = new ReflectionMethod(ExactDecimal::class, 'addDigits'); $slow->setAccessible(true);
$fast = new ReflectionMethod(ExactDecimal::class, 'add'); $fast->setAccessible(true);
$mismatch = 0;
for ($i = 0; $i < 20000; $i++) {
    $digits = function () { $len = 1 + rnd(21); $s = ''; for ($k = 0; $k < $len; $k++) $s .= (string)rnd(10); $s = ltrim($s, '0'); if ($s === '') $s = '0'; return (rnd(2) && $s !== '0' ? '-' : '') . $s; };
    $a = $digits(); $b = $digits();
    if ($fast->invoke(null, $a, $b) !== $slow->invoke(null, $a, $b)) { $mismatch++; if ($mismatch < 4) fwrite(STDERR, "add($a,$b)\n"); }
}
check('integer fast path equals digit-string addition on 20,000 pairs', $mismatch === 0);
$big = str_repeat('9', 4000);
$t = microtime(true); $r = ExactDecimal::sum(array_fill(0, 200, $big)); $elapsed = microtime(true) - $t;
check('200 four-thousand-digit values still sum exactly', $r['state'] === 'ok' && $r['value'] === '19' . str_repeat('9', 3998) . '800');
check('and do so in linear time per addition', $elapsed < 2.0);

// ---- Part 3: wargame 2026-09-23 P1 and P2 ------------------------------------
// Independent digit-at-a-time references for the limb arithmetic.
function ref_mul($a, $b) {
    $neg = ($a[0] === '-') !== ($b[0] === '-'); $a = ltrim($a, '-'); $b = ltrim($b, '-');
    $out = array_fill(0, strlen($a) + strlen($b), 0);
    for ($i = strlen($a) - 1; $i >= 0; $i--) for ($j = strlen($b) - 1; $j >= 0; $j--) {
        $v = $out[$i + $j + 1] + (int)$a[$i] * (int)$b[$j]; $out[$i + $j + 1] = $v % 10; $out[$i + $j] += intdiv($v, 10);
    }
    $r = ltrim(implode('', $out), '0'); if ($r === '') return '0';
    return ($neg ? '-' : '') . $r;
}
function ref_abs_add($a, $b) {
    $out = ''; $c = 0; $i = strlen($a) - 1; $j = strlen($b) - 1;
    while ($i >= 0 || $j >= 0 || $c) { $v = ($i >= 0 ? (int)$a[$i--] : 0) + ($j >= 0 ? (int)$b[$j--] : 0) + $c; $out .= $v % 10; $c = intdiv($v, 10); }
    $r = ltrim(strrev($out), '0'); return $r === '' ? '0' : $r;
}
function ref_abs_sub($a, $b) {   // |a| >= |b|
    $out = ''; $c = 0; $i = strlen($a) - 1; $j = strlen($b) - 1;
    while ($i >= 0) { $v = (int)$a[$i--] - ($j >= 0 ? (int)$b[$j--] : 0) - $c; $c = $v < 0 ? 1 : 0; if ($v < 0) $v += 10; $out .= $v; }
    $r = ltrim(strrev($out), '0'); return $r === '' ? '0' : $r;
}
function ref_add($a, $b) {
    $sa = $a[0] === '-'; $sb = $b[0] === '-'; $a = ltrim($a, '-'); $b = ltrim($b, '-');
    if ($sa === $sb) { $r = ref_abs_add($a, $b); return ($sa && $r !== '0' ? '-' : '') . $r; }
    $cmp = strlen($a) === strlen($b) ? strcmp($a, $b) : (strlen($a) < strlen($b) ? -1 : 1);
    if ($cmp === 0) return '0';
    if ($cmp > 0) { $r = ref_abs_sub($a, $b); return ($sa ? '-' : '') . $r; }
    $r = ref_abs_sub($b, $a); return ($sb ? '-' : '') . $r;
}
$int = function ($max) { $len = 1 + rnd($max); $s = (string)(1 + rnd(9)); for ($k = 1; $k < $len; $k++) $s .= (string)rnd(10); return (rnd(2) ? '-' : '') . $s; };
$bad = 0;
for ($i = 0; $i < 3000; $i++) {
    $a = $int($i % 100 === 0 ? 2000 : 60); $b = $int($i % 100 === 1 ? 2000 : ($i % 3 ? 7 : 40));
    if (ExactDecimal::multiply($a, $b) !== ref_mul($a, $b)) { $bad++; if ($bad < 4) fwrite(STDERR, "multiply($a,$b)\n"); }
}
foreach (['9999999', '10000000', '99999999999999', '100000000000000', str_repeat('9', 2048)] as $a) foreach (['9999999', '10000000', '-1', str_repeat('9', 2048)] as $b) {
    if (ExactDecimal::multiply($a, $b) !== ref_mul($a, $b)) { $bad++; fwrite(STDERR, "multiply edge $a,$b\n"); }
}
check('P1: limb multiplication equals digit-at-a-time multiplication on 3,020 pairs', $bad === 0);
check('P1: multiplication keeps decimal scale and sign', ExactDecimal::multiply('-0.25', '0.4') === '-0.1' && ExactDecimal::multiply('12.50', '8') === '100');
$bad = 0;
for ($i = 0; $i < 3000; $i++) {
    $vals = []; $want = '0'; $k = 1 + rnd(6);
    for ($m = 0; $m < $k; $m++) { $v = $int(rnd(4) ? 40 : 700); $vals[] = $v; $want = ref_add($want, $v); }
    $got = ExactDecimal::sum($vals);
    if ($got['state'] !== 'ok' || $got['value'] !== $want) { $bad++; if ($bad < 4) fwrite(STDERR, 'sum ' . json_encode($vals) . "\n"); }
}
check('P1: limb summation equals digit-at-a-time summation on 3,000 lists', $bad === 0);
check('P1: values under 64 bytes cost no budget', ExactDecimal::sumCost(['123', str_repeat('9', 63)]) === 0 && ExactDecimal::multiplyCost(str_repeat('9', 63), '40') === 0);
check('P1: long values cost one unit per 64 bytes', ExactDecimal::sumCost([str_repeat('9', 4000)]) === 62 && ExactDecimal::multiplyCost(str_repeat('9', 4000), '40') === 62);

// Every member scaled against an average is charged when it is long, and only then.
require_once __DIR__ . '/../php/TemporalRules.php';
use INSPIRE\UniversalValidator\TemporalLogic;
use INSPIRE\UniversalValidator\TemporalRules;
$avgTree = function ($value, $count) {
    $members = array_fill(0, $count, ['lit', $value]);
    $sum = ExactDecimal::sum(array_fill(0, $count, $value))['value'];
    return ['cmp', '<=', ['set', 'all', $members], ['value', ['numerator'=>$sum, 'denominator'=>(string)$count]]];
};
$spent = 0; $calls = 0;
$counter = function ($u) use (&$spent, &$calls) { $spent += $u; $calls++; return true; };
$short = TemporalLogic::evaluate($avgTree('12345', 40), function () { return ''; }, true, false, $counter);
check('P1: short members are never charged', $short === true && $calls === 0);
$long = TemporalLogic::evaluate($avgTree('7' . str_repeat('3', 3999), 40), function () { return ''; }, true, false, $counter);
check('P1: each long member scaled against an average is charged', $long === true && $spent >= 40 * 62);
check('P1: a refused charge makes the comparison unknown',
    TemporalLogic::evaluate($avgTree('7' . str_repeat('3', 3999), 40), function () { return ''; }, true, false, function () { return false; }) === null);
check('P1: without a charge callback the verdict is unchanged',
    TemporalLogic::evaluate($avgTree('7' . str_repeat('3', 3999), 40), function () { return ''; }) === true);

// A sum over long values re-run per host context (excludeCurrent) is charged.
$bigShape = new ProjectShape([1=>['name'=>'visit_arm_1','arm'=>1,'order'=>1,'forms'=>['fa'],'repeats'=>['fa'],'eventRepeats'=>false]],
    ['a_val'=>['form'=>'fa','type'=>'text']]);
$bigRecord = function ($n, $value) { $rows = []; for ($i = 1; $i <= $n; $i++) $rows[$i] = ['a_val'=>$value]; return ['repeat_instances'=>[1=>['fa'=>$rows]]]; };
$ctx = ['event'=>1,'instrument'=>'fa','instance'=>1];
$avg = ['field'=>'a_val','aggregate'=>'average','excludeCurrent'=>true];
$r = new AddressResolver($bigShape, $bigRecord(4, str_repeat('9', 4000)), 10000, new ReferenceBudget(300));
check('P1: summing long values spends budget beyond the reads', $r->resolveBinding($avg, $ctx)['state'] === 'limit');
$r = new AddressResolver($bigShape, $bigRecord(4, '9999'), 10000, new ReferenceBudget(300));
check('P1: the same budget sums short values', $r->resolveBinding($avg, $ctx)['state'] === 'ok');

// End to end: [a][all-instances] <= average, from every host context of one record.
$rule = ['assert'=>'[a_val][all-instances]<={x}', 'references'=>['x'=>['field'=>'a_val','aggregate'=>'average']]];
$run = function ($n, $value) use ($bigShape, $bigRecord, $rule) {
    $node = $bigRecord($n, $value);
    $resolver = (new AddressResolver($bigShape, $node, 10000, new ReferenceBudget()))->shareAcrossContexts();
    $out = ['limit'=>0, 'pass'=>0, 'other'=>0];
    for ($i = 1; $i <= $n; $i++) {
        $p = TemporalRules::compile($rule, $resolver, $bigShape, ['event'=>1,'instrument'=>'fa','instance'=>$i,'values'=>$node['repeat_instances'][1]['fa'][$i]]);
        if (in_array('limit', $p['problems'], true)) $out['limit']++;
        elseif (!$p['problems'] && $p['rule']['assert'] === '1=1') $out['pass']++;
        else $out['other']++;
    }
    return $out;
};
$t = microtime(true); $big = $run(160, '7' . str_repeat('3', 3999)); $elapsed = microtime(true) - $t;
check('P1: 160 four-thousand-digit members end in seconds, not minutes', $elapsed < 15.0);
check('P1: and the contexts past the budget say so', $big['limit'] > 0 && $big['other'] === 0);
check('P1: short values give every context its verdict', $run(160, '12345') === ['limit'=>0, 'pass'=>160, 'other'=>0]);

// P2: a repeated `events` entry is refused when the rule is configured.
$list = function (array $events) { return ['references'=>['x'=>['field'=>'c_val','events'=>$events,'aggregate'=>'count']]]; };
$errors = TemporalRules::validate($list(['previous-event-name', 'base_arm_1', 'previous-event-name']));
check('P2: a repeated events entry is refused', (bool)preg_grep('/lists event previous-event-name more than once/', $errors));
check('P2: a list naming each event once is accepted', TemporalRules::validate($list(['previous-event-name', 'base_arm_1', 'follow_arm_1'])) === []);

// P2: an event without a row costs budget like an event with one.
$events = []; $fields = ['c_val'=>['form'=>'fc','type'=>'text']];
for ($e = 1; $e <= 30; $e++) $events[$e] = ['name'=>"ev{$e}_arm_1",'arm'=>1,'order'=>$e,'forms'=>['fc'],'repeats'=>[],'eventRepeats'=>false];
$rowless = new ProjectShape($events, $fields);
$names = []; for ($e = 1; $e <= 30; $e++) $names[] = "ev{$e}_arm_1";
$r = new AddressResolver($rowless, [], 10000, new ReferenceBudget(10));
check('P2: thirty row-less events spend more than a budget of ten', $r->resolveBinding(['field'=>'c_val','events'=>$names,'aggregate'=>'count'], ['event'=>30,'instrument'=>'fc','instance'=>1])['state'] === 'limit');
$r = new AddressResolver($rowless, [], 10000, new ReferenceBudget(100));
check('P2: and a budget that covers them still counts zero', $r->resolveBinding(['field'=>'c_val','events'=>$names,'aggregate'=>'count'], ['event'=>30,'instrument'=>'fc','instance'=>1])['value'] === '0');

// P2: event tokens resolve once per shape, with the same answers.
$events = [];
for ($e = 1; $e <= 500; $e++) $events[$e] = ['name'=>"ev{$e}_arm_1",'arm'=>1,'order'=>$e,'forms'=>['fc'],'repeats'=>[],'eventRepeats'=>false];
$wide = new ProjectShape($events, $fields);
check('P2: relative and named tokens answer as before',
    $wide->eventId('previous-event-name', 41, 'fc') === ['state'=>'ok','event'=>40] && $wide->eventId('previous-event-name', '41', 'fc') === ['state'=>'ok','event'=>40]
    && $wide->eventId('first-event-name', 41, 'fc') === ['state'=>'ok','event'=>1] && $wide->eventId('ev500_arm_1', 41, 'fc') === ['state'=>'ok','event'=>500]
    && $wide->eventId('event-name', '41', 'fc') === ['state'=>'ok','event'=>41] && $wide->eventId('no_such_arm_1', 41, 'fc') === ['state'=>'invalid']
    && $wide->eventId('previous-event-name', 1, 'fc') === ['state'=>'absent'] && $wide->eventId('previous-event-name', 999, 'fc') === ['state'=>'unreadable']);
$t = microtime(true);
for ($i = 0; $i < 100000; $i++) $wide->eventId($i % 2 ? 'last-event-name' : 'ev500_arm_1', 250, 'fc');
check('P2: 100,000 lookups over 500 events do not walk the events each time', microtime(true) - $t < 2.0);

echo "temporal_adversarial_php: $n checks, $fail failures\n";
exit($fail ? 1 : 0);
