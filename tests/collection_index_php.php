<?php
/**
 * CollectionIndex against the member-by-member loop it replaces (wargame P3).
 * TemporalLogic::compare() answers "any" and "all" from the index when a set
 * carries one; the same set given as plain values takes the loop. On random
 * collections, values, operators, sides, case modes, blank polarities and
 * skipped members, both must give the same answer.
 */
require_once __DIR__ . '/../php/TemporalLogic.php';
use INSPIRE\UniversalValidator\CollectionIndex;
use INSPIRE\UniversalValidator\Logic;
use INSPIRE\UniversalValidator\TemporalLogic;

$n = 0;
function check($cond, $label) {
    global $n; $n++;
    if (!$cond) throw new RuntimeException('FAIL: ' . $label);
}

// The work does not grow with the square of the members.
$big = [];
for ($i = 0; $i < 20000; $i++) $big[] = (string)($i % 997);
$index = new CollectionIndex($big);
$t = microtime(true);
for ($i = 0; $i < 20000; $i++) TemporalLogic::compare('<=', (string)($i % 997), ['set'=>'all', 'index'=>$index, 'skip'=>$i], true, false);
check(microtime(true) - $t < 5.0, '20,000 questions against 20,000 members take seconds, not minutes');

$seed = 20261023;
function rnd($max) { global $seed; $seed = ($seed * 1103515245 + 12345) & 0x7fffffff; return $seed % $max; }

$pool = ['', ' ', "\t", '0', '-0', '0.0', '5', '05', '5.0', ' 5 ', '+5', '-3', '-3.50', '.5', '0.5', '7.50', '12',
    '1000000000000000000000', '999999999999999999999', 'abc', 'ABC', 'Abc', 'abd', 'b', 'B', 'a b', '1e1', '1.2.3',
    '10', '9', 'x', 'X', '-', '+', '.', 'z9', '9z'];
$ops = CollectionIndex::OPS;
$modes = [false, true, Logic::EXACT];
$cases = 0;
for ($round = 0; $round < 800; $round++) {
    $size = 1 + rnd(9);
    $values = [];
    for ($i = 0; $i < $size; $i++) $values[] = $pool[rnd(count($pool))];
    $index = new CollectionIndex($values);
    $x = $pool[rnd(count($pool))];
    $skip = rnd(3) === 0 ? rnd($size) : null;
    $plain = $values;
    if ($skip !== null) array_splice($plain, $skip, 1);
    foreach (['any', 'all'] as $q) foreach ($ops as $op) foreach ($modes as $case) foreach ([true, false] as $blank) foreach ([true, false] as $left) {
        $indexed = ['set'=>$q, 'index'=>$index, 'skip'=>$skip];
        $listed = ['set'=>$q, 'values'=>$plain];
        $a = $left ? TemporalLogic::compare($op, $indexed, $x, $blank, $case) : TemporalLogic::compare($op, $x, $indexed, $blank, $case);
        $b = $left ? TemporalLogic::compare($op, $listed, $x, $blank, $case) : TemporalLogic::compare($op, $x, $listed, $blank, $case);
        $cases++;
        if ($a !== $b) check(false, json_encode(compact('values', 'skip', 'x', 'q', 'op', 'case', 'blank', 'left', 'a', 'b')));
    }
}
check($cases === 800 * 144, "the index and the loop agree on $cases comparisons");

// A value the index does not cover (an average) is compared member by member.
$index = new CollectionIndex(['1', '2', '3']);
$avg = ['numerator'=>'5', 'denominator'=>'2'];
check(TemporalLogic::compare('<', ['set'=>'any', 'index'=>$index, 'skip'=>null], $avg, true, false) === true, 'any member below an average of 2.5');
check(TemporalLogic::compare('<', ['set'=>'all', 'index'=>$index, 'skip'=>null], $avg, true, false) === false, 'not every member is below it');
check(TemporalLogic::compare('<', ['set'=>'all', 'index'=>$index, 'skip'=>2], $avg, true, false) === true, 'every member but the skipped 3 is below it');
// Skipping the only member leaves nothing to judge.
check(TemporalLogic::compare('=', ['set'=>'any', 'index'=>new CollectionIndex(['4']), 'skip'=>0], '4', true, false) === null, 'an empty collection is unknown');

echo "collection_index_php: $n checks, 0 failures\n";
