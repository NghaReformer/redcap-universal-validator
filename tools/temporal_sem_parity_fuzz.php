<?php
/**
 * Differential fuzz: TemporalLogic::evaluate (PHP) vs QRID_temporalEvaluate (engine.js)
 * over compiled trees of the shapes TemporalRules::compile emits (lit/ref/date/elapsed/
 * aggregate/set/guard/value-free), with hostile numeric and date strings.
 * Usage: php tools/temporal_sem_parity_fuzz.php [cases]
 */
require_once __DIR__ . '/temporal_sem_lib.php';
use INSPIRE\UniversalValidator\TemporalLogic;

$N = (int)($argv[1] ?? 20000);
$seed = 7;
function rn($m) { global $seed; $seed = ($seed * 1103515245 + 12345) & 0x7fffffff; return $seed % $m; }
function pk(array $a) { return $a[rn(count($a))]; }

$nums = ['', ' ', '0', '-0', '+5', '5.', '.5', '-.5', '0.1', '0.2', '0.3', ' 12 ', "12\t", '1e3', '1000',
    '9007199254740993', '9007199254740992', '-3', '07', '7.50', 'abc', 'ABC', '10000000000000000000000.1', "\x0B9", '9 9', '٣'];
$dates = ['', ' ', '2024-02-29', '2023-02-29', '2024-1-5', '2024-01-05', '05-01-2024', '01-05-2024', '29-02-2024',
    '2024-03-10 02:30', '2024-03-10 03:30', '2024-03-10 24:00', '2024-03-10 23:59', '2024-03-10 02:30:59',
    '10-03-2024 02:30', '03-10-2024 02:30:15', '0000-01-01', '0001-01-01', '9999-12-31', '2024/02/29', '2024-02/29'];
$types = ['date', 'datetime', 'datetime_seconds'];
$fmts = ['ymd', 'dmy', 'mdy'];
$ops = ['=', '<>', '<', '>', '<=', '>=', 'identical'];
$aggs = ['count','exists','populated-count','distinct-count','sum','minimum','maximum','average'];

function numOperand(&$values, $depth = 0) {
    global $nums, $aggs;
    $k = rn(5);
    if ($k === 0) return ['lit', pk($nums)];
    if ($k === 1) { $f = 'f' . rn(4); $values[$f] = pk($nums); return ['ref', $f, null]; }
    if ($k === 2 || $k === 3) {
        $m = []; for ($i = rn(4); $i > 0; $i--) $m[] = rn(2) ? ['lit', pk($nums)] : (function () use (&$values, $nums) { $f = 'g' . rn(4); $values[$f] = pk($nums); return ['ref', $f, null]; })();
        return rn(3) ? ['aggregate', pk($aggs), $m] : ['set', rn(2) ? 'any' : 'all', $m];
    }
    return ['guard', [['k', rn(2) ? 'A' : 'a']], ['lit', pk($nums)]];
}
function dateOperand(&$values, $type) {
    global $dates, $fmts;
    $inner = rn(2) ? ['lit', pk($dates)] : (function () use (&$values, $dates) { $f = 'd' . rn(4); $values[$f] = pk($dates); return ['ref', $f, null]; })();
    return ['date', $type, pk($fmts), $inner];
}
function cmpNode(&$values) {
    global $ops, $types;
    $op = pk($ops);
    $k = rn(3);
    if ($k === 0) return ['cmp', $op, numOperand($values), numOperand($values)];
    $t = pk($types); $t2 = rn(5) ? $t : pk($types);
    if ($k === 1) return ['cmp', $op, dateOperand($values, $t), dateOperand($values, $t2)];
    $u = pk(['days','hours','minutes','seconds']);
    return ['cmp', $op, ['elapsed', $u, dateOperand($values, $t), dateOperand($values, $t2)], rn(2) ? numOperand($values) : ['lit', pk(['0','48','-1','1.5',''])]];
}
function tree(&$values, $d = 0) {
    $k = rn($d > 2 ? 1 : 4);
    if ($k === 0) return cmpNode($values);
    if ($k === 1) return ['not', tree($values, $d + 1)];
    $c = []; for ($i = 1 + rn(3); $i > 0; $i--) $c[] = tree($values, $d + 1);
    return [rn(2) ? 'and' : 'or', $c];
}

$cases = [];
for ($i = 0; $i < $N; $i++) {
    $values = ['k' => rn(2) ? 'A' : 'a'];
    $ast = ['temporal', tree($values)];
    $cases[] = ['ast' => $ast, 'values' => $values, 'blank' => (bool)rn(2), 'cs' => (bool)rn(2)];
}
$file = tempnam(sys_get_temp_dir(), 'semfz');
file_put_contents($file, json_encode($cases, JSON_INVALID_UTF8_SUBSTITUTE));
$js = json_decode(shell_exec('node ' . escapeshellarg(__DIR__ . '/temporal_sem_parity_batch.cjs') . ' ' . escapeshellarg($file)), true);
unlink($file);
$diff = 0; $shown = 0;
foreach ($cases as $i => $c) {
    $php = sem_php_eval($c['ast'], $c['values'], $c['blank'], $c['cs']);
    if ($php !== $js[$i]) {
        $diff++;
        if ($shown++ < 8) echo 'DIVERGE php=' . json_encode($php) . ' js=' . json_encode($js[$i]) . ' ' . json_encode($c) . "\n";
    }
}
echo "temporal_sem_parity_fuzz: $N cases, $diff PHP/JS divergences\n";
exit($diff ? 1 : 0);
