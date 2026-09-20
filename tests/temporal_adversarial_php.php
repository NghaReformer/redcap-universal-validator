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

echo "temporal_adversarial_php: $n checks, $fail failures\n";
exit($fail ? 1 : 0);
