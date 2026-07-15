<?php
/**
 * when_fuzz_php.php — differential fuzz: php/Logic.php vs the JS twin.
 *
 * tests/when_fixture.json locks the cases a human thought of; this locks the
 * ones nobody did. tests/gen_when_fuzz.cjs generates seeded conditions (valid
 * ones built from the grammar, plus mutated/hostile ones) and freezes what the
 * BROWSER twin does with each. Here the PHP engine recomputes every case; any
 * disagreement — accepted vs rejected, verdict, or referenced fields — fails.
 *
 * This is the test that would catch a numeric-vs-string comparison drifting
 * between the runtimes (PHP and JS disagree about "1e3"/"0x10"/" 2 " unless
 * the dialect pins it), which no hand-written case list reliably covers.
 *
 * Run:  node tests/gen_when_fuzz.cjs && php tests/when_fuzz_php.php
 */

require_once __DIR__ . '/../php/Logic.php';

use INSPIRE\UniversalValidator\Logic;

$fx = json_decode(file_get_contents(__DIR__ . '/when_fuzz.json'), true);
if (!is_array($fx) || empty($fx['cases'])) {
    fwrite(STDERR, "when_fuzz.json missing or empty — run: node tests/gen_when_fuzz.cjs\n");
    exit(1);
}

$n = 0; $fail = 0; $okBoth = 0; $rejBoth = 0; $shown = 0;
function report($msg)
{
    global $shown;
    if ($shown++ < 12) fwrite(STDERR, $msg);
}

foreach ($fx['cases'] as $c) {
    $n++;
    $r = Logic::parse($c['expr']);
    $phpOk = !empty($r['ok']);
    if ($phpOk !== (bool) $c['ok']) {
        $fail++;
        report("PARSE DISAGREE: " . json_encode($c['expr']) . "\n  js:  "
            . ($c['ok'] ? 'accepted' : 'rejected') . "\n  php: "
            . ($phpOk ? 'accepted' : 'rejected: ' . $r['error']) . "\n");
        continue;
    }
    if (!$phpOk) { $rejBoth++; continue; }
    $okBoth++;
    $vals = isset($c['values']) && is_array($c['values']) ? $c['values'] : [];
    $php = Logic::evaluate($r['ast'], $vals);
    if ($php !== $c['js']) {
        $fail++;
        report("EVAL DISAGREE: " . json_encode($c['expr']) . "\n  values: " . json_encode($c['values'])
            . "\n  js: " . json_encode($c['js']) . "  php: " . json_encode($php) . "\n");
        continue;
    }
    if (json_encode(Logic::referencedFields($r['ast'])) !== json_encode($c['jsRefs'])) {
        $fail++;
        report("REFS DISAGREE: " . json_encode($c['expr']) . "\n  js:  " . json_encode($c['jsRefs'])
            . "\n  php: " . json_encode(Logic::referencedFields($r['ast'])) . "\n");
    }
}

printf("when_fuzz_php: %d cases (both accept %d, both reject %d), %d disagreement(s)\n",
    $n, $okBoth, $rejBoth, $fail);
exit($fail === 0 ? 0 : 1);
