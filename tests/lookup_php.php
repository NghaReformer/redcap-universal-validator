<?php
/**
 * lookup_php.php — PHP side of the @UVEXISTS / @UVUNIQUE comparison contract.
 *
 * Drives Logic::lookupKey and TemporalLogic::sameFlags over every case in
 * tests/lookup_fixture.json. tests/lookup_js.cjs drives the JS twins
 * (QRID_lookupKey, QRID_sameFlags) over the SAME file, so a key that drifts
 * between the runtimes cannot pass both: the browser's record-scope
 * uniqueness check and the server's would then disagree about what "the
 * same value" means.
 *
 * Run:  php tests/lookup_php.php
 */

require_once __DIR__ . '/../php/Logic.php';
require_once __DIR__ . '/../php/TemporalLogic.php';

use INSPIRE\UniversalValidator\Logic;
use INSPIRE\UniversalValidator\TemporalLogic;

$n = 0;
$fail = 0;

function check($label, $cond)
{
    global $n, $fail;
    $n++;
    if (!$cond) {
        $fail++;
        fwrite(STDERR, "FAIL: $label\n");
    }
}

$fx = json_decode(file_get_contents(__DIR__ . '/lookup_fixture.json'), true);
if (!is_array($fx) || empty($fx['keys']) || empty($fx['same'])) {
    fwrite(STDERR, "lookup_fixture.json is missing or empty\n");
    exit(1);
}

foreach ($fx['keys'] as $c) {
    $got = Logic::lookupKey($c['in'], $c['fold'], $c['mark']);
    check('key ' . json_encode([$c['in'], $c['fold'], $c['mark']]) . ' = ' . json_encode($c['key']) . ', got ' . json_encode($got),
        $got === $c['key']);
}
foreach ($fx['same'] as $c) {
    $got = TemporalLogic::sameFlags($c['op']);
    check('sameFlags ' . $c['op'], $got === $c['flags']);
    if ($got !== null) check('sameOp is the inverse of sameFlags for ' . $c['op'],
        TemporalLogic::sameFlags(TemporalLogic::sameOp($got[0], $got[1])) === $got);
}

// Equal keys mean equal values for the comparison, nothing more: a number
// never equals a text, even one spelled the same.
check('a number key never equals a text key', Logic::lookupKey('7', true, 'point') !== Logic::lookupKey('7', true, null));
check('the trim keeps a no-break space inside a value', Logic::lookupTrim("a\xC2\xA0b") === "a\xC2\xA0b");
check('the trim takes no byte of another character ending in A0', Logic::lookupTrim("x\xE0\xA0") === "x\xE0\xA0");
// Long runs of space at either end or inside: a regex trim ran out of stack here
// and gave back an empty value.
$sp = str_repeat(" \t", 10000);
$nb = str_repeat("\xC2\xA0", 10000);
check('the trim takes a long run of space and no-break spaces off each end',
    Logic::lookupTrim($sp . $nb . 'SP-1' . $nb . $sp) === 'SP-1');
check('the trim keeps a long run of space inside a value', Logic::lookupTrim('A' . $sp . 'B') === 'A' . $sp . 'B');
check('the trim leaves nothing of a value that is all space', Logic::lookupTrim($sp . $nb) === '');
check('the trim keeps a lone C2 or A0 byte at an end', Logic::lookupTrim("\xA0x\xC2") === "\xA0x\xC2");

echo "lookup_php: $n checks, $fail failure(s)\n";
exit($fail ? 1 : 0);
