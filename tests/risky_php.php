<?php
/**
 * risky_php.php — locks the PHP risky-pattern heuristic and its server behavior.
 *
 * 1) Asserts CheckCharacter::riskyPattern() agrees with the browser twin on the
 *    shared list (tests/risky_patterns.json) — parity with tests/risky_js.cjs.
 * 2) Asserts a catastrophic pattern can no longer produce a false invalid-ID
 *    verdict on the server path, and returns quickly (the P2 finding).
 *
 * Run:  php tests/risky_php.php
 */

require_once __DIR__ . '/../php/CheckCharacter.php';

use INSPIRE\UniversalValidator\CheckCharacter;

$fx = json_decode(file_get_contents(__DIR__ . '/risky_patterns.json'), true);
$n = 0;
$fail = 0;

// ---- 1) predicate parity with the browser ----
foreach ($fx['risky'] as $p) {
    $n++;
    if (CheckCharacter::riskyPattern($p) !== true) {
        $fail++;
        fwrite(STDERR, 'EXPECTED RISKY but passed: ' . var_export($p, true) . "\n");
    }
}
foreach ($fx['safe'] as $p) {
    $n++;
    if (CheckCharacter::riskyPattern($p) !== false) {
        $fail++;
        fwrite(STDERR, 'EXPECTED SAFE but flagged: ' . var_export($p, true) . "\n");
    }
}

// ---- 2) single-field server path never false-flags a catastrophic pattern ----
foreach (['(a+)+$', '([A-Z]+)*$', '(x+x+)+y', '([0-9]{1,20}){1,20}'] as $p) {
    $n++;
    $value = str_repeat('a', 50000) . 'X';
    $t = microtime(true);
    $res = CheckCharacter::validateSingleField('none', 'normalized_id', '', $p, $value);
    $elapsed = microtime(true) - $t;
    if (empty($res['ok'])) {
        $fail++;
        fwrite(STDERR, "risky pattern produced an invalid verdict: $p -> " . json_encode($res) . "\n");
    }
    if ($elapsed > 1.0) {
        $fail++;
        fwrite(STDERR, sprintf("risky pattern took too long: %s -> %.3fs\n", $p, $elapsed));
    }
}

// ---- 3) pooled server path never false-flags on a catastrophic pattern ----
// (a) a bounded nested quantifier is now gated by riskyPattern -> unconfigurable
$n++;
$cfgA = ['algorithm' => 'none', 'source' => 'normalized_id', 'strip' => '',
         'idPattern' => '([0-9]{1,20}){1,20}', 'idLengths' => [20]];
$resA = CheckCharacter::validatePooledField($cfgA, str_repeat('1', 19) . 'x');
if (empty($resA['ok'])) {
    $fail++;
    fwrite(STDERR, 'bounded-nested pooled pattern gave an invalid verdict: ' . json_encode($resA) . "\n");
}

// (b) a heuristic-MISSED catastrophic pattern (optional-overlap alternation, which
// PCRE cannot auto-possessify) that hits the PCRE backtrack limit at match time
// must bail to "unconfigurable", NOT be logged as junk. riskyPattern does not
// catch this class (no inner + * or {n,m}); the patTest guard is what saves it. A
// tiny backtrack limit makes the engine failure deterministic. Uppercase pattern +
// value because the pooled cleaner uppercases before matching.
$n++;
$old = ini_get('pcre.backtrack_limit');
ini_set('pcre.backtrack_limit', '200');
$cfgB = ['algorithm' => 'none', 'source' => 'normalized_id', 'strip' => '',
         'idPattern' => '(A|A?)+$', 'idLengths' => [30]];
$resB = CheckCharacter::validatePooledField($cfgB, str_repeat('A', 29) . '9');
ini_set('pcre.backtrack_limit', $old === false ? '1000000' : $old);
if (empty($resB['ok'])) {
    $fail++;
    fwrite(STDERR, 'PCRE-error pooled path gave an invalid verdict instead of bailing: ' . json_encode($resB) . "\n");
}

echo sprintf("risky_php: %d checks, %d mismatch(es)\n", $n, $fail);
exit($fail === 0 ? 0 : 1);
