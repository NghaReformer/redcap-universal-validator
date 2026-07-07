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
        $out[] = ($s['type'] === 'id') ? ['id', $s['id'], (bool) $s['valid']] : ['junk', $s['text']];
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

echo sprintf("pooled_php: %d cases checked, %d mismatch(es)\n", $n, $fail);
exit($fail === 0 ? 0 : 1);
