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

// (b) a heuristic-MISSED pattern that hits the PCRE backtrack limit at match
// time must bail to "unconfigurable", NOT be logged as junk. Since the gate now
// catches ambiguous-repetition shapes ((a|aa)+, (A|A?)+), the remaining missed
// class is POLYNOMIAL backtracking (overlapping stars, A*A*A*9); the patTest
// guard is what saves it. The required literal ('9') is present in the subject
// so PCRE2's prescan cannot short-circuit, but the subject ends in 'A' so the
// match only fails after exploring the quadratic split space. A tiny backtrack
// limit makes the engine failure deterministic. Uppercase pattern + value
// because the pooled cleaner uppercases before matching.
$n++;
if (CheckCharacter::riskyPattern('A*A*A*9') !== false) {
    $fail++;
    fwrite(STDERR, "A*A*A*9 unexpectedly gated — test 3(b) no longer exercises the PCRE-error guard\n");
}
$old = ini_get('pcre.backtrack_limit');
ini_set('pcre.backtrack_limit', '100');
$cfgB = ['algorithm' => 'none', 'source' => 'normalized_id', 'strip' => '',
         'idPattern' => 'A*A*A*9', 'idLengths' => [30]];
$resB = CheckCharacter::validatePooledField($cfgB, str_repeat('A', 28) . '9A');
ini_set('pcre.backtrack_limit', $old === false ? '1000000' : $old);
if (empty($resB['ok'])) {
    $fail++;
    fwrite(STDERR, 'PCRE-error pooled path gave an invalid verdict instead of bailing: ' . json_encode($resB) . "\n");
}

// ---- 4) pattern-source length cap (mirrors the JS gate) ----
$n++;
if (CheckCharacter::riskyPattern(str_repeat('A', 513)) !== true) {
    $fail++;
    fwrite(STDERR, "513-char pattern source was not gated\n");
}
$n++;
if (CheckCharacter::riskyPattern(str_repeat('A', 512)) !== false) {
    $fail++;
    fwrite(STDERR, "512-char plain pattern source was wrongly gated\n");
}

echo sprintf("risky_php: %d checks, %d mismatch(es)\n", $n, $fail);
exit($fail === 0 ? 0 : 1);
