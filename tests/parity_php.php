<?php
/**
 * parity_php.php — proves the PHP port matches the Python-generated fixture.
 *
 * Recomputes the check character(s) for every row of check_fixture.json (emitted
 * by the qrcode_generation Python engine) and fails on any mismatch. This is the
 * cross-repo, cross-runtime contract: if the PHP port ever drifts from the
 * canonical Python engine, CI turns red here.
 *
 * Run:  php tests/parity_php.php
 */

require_once __DIR__ . '/../php/CheckCharacter.php';

use INSPIRE\UniversalValidator\CheckCharacter;

$fixturePath = __DIR__ . '/check_fixture.json';
$fx = json_decode(file_get_contents($fixturePath), true);
if (!$fx || !isset($fx['compute'])) {
    fwrite(STDERR, "could not read compute rows from $fixturePath\n");
    exit(2);
}

$rows = $fx['compute'];
$n = 0;
$fail = 0;
$byAlgo = [];

foreach ($rows as $r) {
    $n++;
    $name = $r['name'];
    $byAlgo[$name] = ($byAlgo[$name] ?? 0) + 1;
    try {
        $got = CheckCharacter::compute($name, $r['payload']);
    } catch (\Throwable $e) {
        $got = '<<error: ' . $e->getMessage() . '>>';
    }
    if ($got !== $r['check']) {
        $fail++;
        fwrite(STDERR, sprintf(
            "MISMATCH  %-18s payload=%-14s expected=%-4s got=%s\n",
            $name, $r['payload'], var_export($r['check'], true), var_export($got, true)
        ));
    }
}

echo "algorithms covered: " . implode(', ', array_keys($byAlgo)) . "\n";
echo sprintf("parity_php: %d rows checked, %d mismatch(es)\n", $n, $fail);
exit($fail === 0 ? 0 : 1);
