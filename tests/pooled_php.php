<?php
/**
 * pooled_php.php — proves the PHP pooled parser matches the frozen browser output.
 *
 * Recomputes CheckCharacter::pooledParse() for every case in pooled_fixture.json
 * (generated from the verified js/engine.js parser) and fails on any mismatch.
 * This is the cross-runtime contract for the server-side pooled auditor: if the
 * PHP port ever drifts from the browser parser, CI turns red here.
 *
 * Run:  php tests/pooled_php.php
 */

require_once __DIR__ . '/../php/CheckCharacter.php';

use INSPIRE\UniversalValidator\CheckCharacter;

$fx = json_decode(file_get_contents(__DIR__ . '/pooled_fixture.json'), true);
if (!$fx || !isset($fx['cases'])) {
    fwrite(STDERR, "could not read cases from pooled_fixture.json\n");
    exit(2);
}

function canon(array $segs)
{
    $out = [];
    foreach ($segs as $s) {
        // The alternate index is part of the contract: it decides whether a
        // member is reported as check-verified or shape-only.
        $out[] = ($s['type'] === 'id')
            ? ['id', $s['id'], (bool) $s['valid'], isset($s['alt']) ? (int) $s['alt'] : -1]
            : ['junk', $s['text']];
    }
    return $out;
}

$n = 0;
$fail = 0;
foreach ($fx['cases'] as $c) {
    $n++;
    $segs = CheckCharacter::pooledParse($c['config'], $c['input']);
    if ($segs === null) {
        $fail++;
        fwrite(STDERR, "NULL (unconfigurable) for [{$c['label']}]\n");
        continue;
    }
    $got = json_encode(canon($segs));
    $exp = json_encode(canon($c['segs']));
    if ($got !== $exp) {
        $fail++;
        fwrite(STDERR, "MISMATCH [{$c['label']}]\n  expected $exp\n  got      $got\n");
    }
}

// ---- scan cap: the work budget must hold every ORDINARY rule at the ceiling
// and shrink only the expensive tail. The budget's unit is nominal - one step
// is a substr, a regex test and, unless the pattern rejects first, a full
// check-character computation - so it was recalibrated from 2,000,000 against
// measured cost. These lock which configurations that recalibration moves.
$capOf = function (array $cfg) {
    $lo = 1; $hi = 5000;
    while ($lo < $hi) {
        $mid = intdiv($lo + $hi + 1, 2);
        if (CheckCharacter::pooledParse($cfg, str_repeat('Q', $mid)) !== null) $lo = $mid; else $hi = $mid - 1;
    }
    return $lo;
};
$MOD = 'iso7064_mod37_36';
foreach ([
    ['the 8..14 default range',      ['algorithm' => $MOD, 'strip' => ''], 4096],
    ['an exact-length rule',         ['algorithm' => $MOD, 'strip' => '', 'idLengths' => [9]], 4096],
    ['a 64-char single format',      ['algorithm' => $MOD, 'strip' => '',
                                      'idPattern' => '[0-9A-Z]{64}', 'idLengths' => [64]], 4096],
    ['the four-family mixed rule',   ['strip' => '-', 'alternates' => [
        ['label' => 'GHIT', 'pattern' => 'FC[1-9]-[0-9]{4}',         'algorithm' => 'none', 'lengths' => [8]],
        ['label' => 'SK',   'pattern' => 'SK[1-5]-[0-9]{4}[0-9A-Z]', 'algorithm' => $MOD,   'lengths' => [9]],
        ['label' => 'DT',   'pattern' => 'DT[1-2]-[0-9]{5}[0-9A-Z]', 'algorithm' => $MOD,   'lengths' => [10]],
        ['label' => 'ST',   'pattern' => 'ST[1-5]-[0-9]{5}[0-9A-Z]', 'algorithm' => $MOD,   'lengths' => [10]]]], 4096],
] as $case) {
    $n++;
    $got = $capOf($case[1]);
    if ($got !== $case[2]) {
        $fail++;
        fwrite(STDERR, "scan cap for {$case[0]}: expected {$case[2]}, got $got\n");
    }
}
// the wide tail: 32 candidate pairs over 64-character members sits on the floor
$n++;
$wide = [];
for ($a = 0; $a < 8; $a++) {
    $lens = [];
    for ($k = 0; $k < 4; $k++) $lens[] = 33 + $a * 4 + $k;
    $wide[] = ['label' => "F$a", 'pattern' => '[0-9A-Z]{33,64}', 'algorithm' => $MOD, 'lengths' => $lens];
}
$got = $capOf(['strip' => '', 'alternates' => $wide]);
if ($got !== 256) {
    $fail++;
    fwrite(STDERR, "scan cap for 32 pairs x 64: expected the 256 floor, got $got\n");
}
// and a single-format rule with the same pair count costs the same - the branch
// did not widen the ceiling, it only made this shape reachable
$n++;
$got2 = $capOf(['algorithm' => $MOD, 'strip' => '', 'idPattern' => '[0-9A-Z]{33,64}', 'idLengths' => range(33, 64)]);
if ($got2 !== $got) {
    $fail++;
    fwrite(STDERR, "single-format 32 lengths capped at $got2 but 32 alternate pairs at $got\n");
}


echo sprintf("pooled_php: %d cases checked, %d mismatch(es)\n", $n, $fail);
exit($fail === 0 ? 0 : 1);
