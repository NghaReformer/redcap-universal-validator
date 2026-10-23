<?php
/**
 * faults_php.php — caught exceptions leave a trace (QUA-5).
 *
 * 47 catch blocks in the module held no statement, so a fault on a fail-open
 * path left nothing behind. Each now hands its exception to Faults::swallow(),
 * which counts the place and the class (never the message), and the module
 * writes the counts to its log at the end of a request when the project's
 * debug setting is on (tests/hook_php.php checks that part).
 *
 * This suite checks the counter, and lints the shipped PHP: no catch block may
 * be empty, each Faults call names its place as Class::method, and each file
 * that calls Faults loads it, so a file a test loads on its own still works.
 *
 * Run:  php tests/faults_php.php
 */

require_once __DIR__ . '/../php/Faults.php';

use INSPIRE\UniversalValidator\Faults;

$n = 0;
$fail = 0;
function check($label, $cond)
{
    global $n, $fail;
    $n++;
    if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
}

// ---- the counter ----------------------------------------------------------
Faults::take();
check('nothing counted at the start', Faults::peek() === ['places' => [], 'dropped' => 0]);
Faults::swallow(new \RuntimeException('a value 12345'), 'A::one');
Faults::swallow(new \RuntimeException('another'), 'A::one');
Faults::swallow(new \LogicException('x'), 'A::one');
Faults::swallow(new \TypeError('y'), 'B::two');
$p = Faults::peek();
check('a place and class are counted together (got ' . json_encode($p) . ')',
    $p['places'] === ['A::one|RuntimeException' => 2, 'A::one|LogicException' => 1, 'B::two|TypeError' => 1]);
check('no message is kept', strpos(json_encode($p), '12345') === false);
check('peek() leaves the counts', Faults::peek() === $p);
check('take() hands them over', Faults::take() === $p);
check('...and clears them', Faults::peek() === ['places' => [], 'dropped' => 0]);
for ($i = 0; $i < Faults::MAX_PLACES + 7; $i++) Faults::swallow(new \Exception('e'), 'P::m' . $i);
Faults::swallow(new \Exception('e'), 'P::m0');
$p = Faults::take();
check('at most MAX_PLACES places are kept, the rest are counted as dropped',
    count($p['places']) === Faults::MAX_PLACES && $p['dropped'] === 7 && $p['places']['P::m0|Exception'] === 2);

// ---- the lint ---------------------------------------------------------------
$root = dirname(__DIR__);
$files = array_merge([$root . '/UniversalValidator.php'], glob($root . '/php/*.php'),
    glob($root . '/php/Scan/*.php'), glob($root . '/pages/*.php'));
$empty = [];
$badPlace = [];
$unloaded = [];
$calls = 0;
foreach ($files as $f) {
    $src = file_get_contents($f);
    $rel = substr($f, strlen($root) + 1);
    $t = token_get_all($src);
    $count = count($t);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($t[$i]) || $t[$i][0] !== T_CATCH) continue;
        $j = $i;
        while ($j < $count && $t[$j] !== '{') $j++;
        $depth = 0;
        $statement = false;
        for ($k = $j; $k < $count; $k++) {
            $tok = $t[$k];
            if ($tok === '{') { $depth++; if ($k !== $j) $statement = true; continue; }
            if ($tok === '}') { $depth--; if ($depth === 0) break; continue; }
            if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            $statement = true;
        }
        if (!$statement) $empty[] = $rel . ':' . $t[$i][2];
    }
    if (preg_match_all('/Faults::swallow\(\$\w+, \'([^\']*)\'\)/', $src, $m)) {
        foreach ($m[1] as $place) {
            $calls++;
            if (!preg_match('/^[A-Za-z]+::[A-Za-z_][A-Za-z0-9_]*$/', $place)) $badPlace[] = $rel . ': ' . $place;
        }
    }
    if (basename($f) !== 'Faults.php' && strpos($src, 'Faults::swallow(') !== false
        && basename($f) !== 'UniversalValidator.php'
        && strpos($src, "require_once __DIR__ . '/Faults.php';") === false
        && strpos($src, "require_once __DIR__ . '/../Faults.php';") === false) {
        $unloaded[] = $rel;
    }
}
check('no catch block in the shipped PHP is empty (got ' . json_encode($empty) . ')', $empty === []);
check('the module hands caught exceptions to Faults (' . $calls . ' calls)', $calls >= 47);
check('each Faults call names its place as Class::method (got ' . json_encode($badPlace) . ')', $badPlace === []);
check('each file that calls Faults loads it (got ' . json_encode($unloaded) . ')', $unloaded === []);

echo "faults_php: $n checks, $fail failure(s)\n";
exit($fail ? 1 : 0);
