<?php
/**
 * numeric_php.php — PHP side of the exact-decimal comparison contract (H-03).
 *
 * Drives Logic::compare()/Logic::decCmp() (reached through the public
 * Logic::evaluate) over every case in tests/numeric_fixture.json.
 * tests/numeric_js.cjs drives the JS twins (QRID_whenCompare/QRID_whenDecCmp in
 * js/engine.js) over the SAME fixture and hashes the SAME verdicts, so a
 * comparator that drifts between the runtimes cannot pass both.
 *
 * The defect this locks: both runtimes used to cast a NUM_RE-shaped operand to
 * a float before comparing, so 9007199254740992 and 9007199254740993 compared
 * EQUAL and the documented @UVASSERT="[id]=[id_confirm]" recipe accepted two
 * different identifiers. Cases marked "floatTrap" are the ones where the float
 * cast still gives a different answer; the float verdict is recomputed below
 * and the case fails if it AGREES, so the sentinels cannot quietly go stale.
 *
 * Run:  php tests/numeric_php.php
 */

require_once __DIR__ . '/../php/Logic.php';

use INSPIRE\UniversalValidator\Logic;

$n = 0;
$fail = 0;
$shown = 0;

function check($label, $cond)
{
    global $n, $fail;
    $n++;
    if (!$cond) {
        $fail++;
        fwrite(STDERR, "FAIL: $label\n");
    }
}

/** At most a dozen detail lines, so a broken comparator stays readable. */
function detail($msg)
{
    global $shown;
    if ($shown++ < 12) fwrite(STDERR, "  $msg\n");
}

/** The six verdicts a single -1/0/1 comparison has to produce. */
function verdictFor($op, $cmp)
{
    switch ($op) {
        case '=':  return $cmp === 0;
        case '<>': return $cmp !== 0;
        case '<':  return $cmp < 0;
        case '>':  return $cmp > 0;
        case '<=': return $cmp <= 0;
        case '>=': return $cmp >= 0;
    }
    return null;
}

/** ['ref','a',null] or ['lit','<value>'] — both operand shapes reach compare(). */
function operandNode($kind, $slot, $value)
{
    return $kind === 'lit' ? ['lit', $value] : ['ref', $slot, null];
}

/**
 * Independent byte comparison of the trimmed operands, written here rather than
 * read from Logic: it is what proves a "string" case really fell through the
 * numeric branch instead of being read as a number.
 */
function byteCmp($a, $b)
{
    $c = strcmp(trim($a, " \t\r\n"), trim($b, " \t\r\n"));
    return $c < 0 ? -1 : ($c > 0 ? 1 : 0);
}

/** The comparison the module used to make — kept only to prove it was wrong. */
function floatCmp($a, $b)
{
    $fa = (float) trim($a, " \t\r\n");
    $fb = (float) trim($b, " \t\r\n");
    return $fa < $fb ? -1 : ($fa > $fb ? 1 : 0);
}

$fx = json_decode(file_get_contents(__DIR__ . '/numeric_fixture.json'), true);
check('fixture loads', is_array($fx)
    && isset($fx['ops'], $fx['shapes'], $fx['cases'], $fx['mustInclude'], $fx['digest'])
    && is_array($fx['cases']) && count($fx['cases']) > 0);
if ($fail) {
    fwrite(STDERR, "numeric_fixture.json is missing or malformed\n");
    exit(1);
}

// ---- the fixture itself: shape, uniqueness, and the cases nobody may drop ----
check('fixture ops are the six comparison operators',
    $fx['ops'] === ['=', '<>', '<', '>', '<=', '>=']);
check('fixture covers both operand shapes on both sides',
    $fx['shapes'] === ['ref_ref', 'ref_lit', 'lit_ref', 'lit_lit']);

$names = [];
foreach ($fx['cases'] as $c) {
    $label = isset($c['name']) ? $c['name'] : '(unnamed)';
    // Operands MUST be JSON strings: a bare JSON number would be parsed as a
    // float on the way in and the fixture would lose the very digits it pins.
    check("case is well formed: $label",
        isset($c['name'], $c['a'], $c['b'], $c['path'], $c['cmp'])
        && is_string($c['a']) && is_string($c['b'])
        && ($c['path'] === 'numeric' || $c['path'] === 'string')
        && in_array($c['cmp'], [-1, 0, 1], true));
    $names[] = $label;
}
check('case names are unique', count(array_unique($names)) === count($names));
foreach ($fx['mustInclude'] as $req) {
    check("required case present: $req", in_array($req, $names, true));
}

// ---- every case, every operator, every operand shape ----
$digestLines = [];
foreach ($fx['cases'] as $c) {
    $cmp = $c['cmp'];
    $values = ['a' => $c['a'], 'b' => $c['b']];

    foreach ($fx['shapes'] as $shape) {
        list($lk, $rk) = explode('_', $shape, 2);
        foreach ($fx['ops'] as $op) {
            $ast = ['cmp', $op,
                operandNode($lk, 'a', $c['a']),
                operandNode($rk, 'b', $c['b'])];
            $got = Logic::evaluate($ast, $values);
            $want = verdictFor($op, $cmp);
            if ($got !== $want) {
                detail("{$c['name']} [$shape] " . json_encode($c['a']) . " $op "
                    . json_encode($c['b']) . " => " . json_encode($got)
                    . ", expected " . json_encode($want) . " (cmp $cmp)");
            }
            check("verdict {$c['name']} [$shape] $op", $got === $want);
            $digestLines[] = $c['name'] . '|' . $shape . '|' . $op . '|' . ($got ? '1' : '0');
        }
    }

    // Which branch of compare() must run. NUM_RE is a public constant here; the
    // JS twin keeps its regex module-private, so the JS test proves the same
    // thing from the outside, through the string-path check below.
    $bothNumeric = preg_match(Logic::NUM_RE, trim($c['a'], " \t\r\n"))
        && preg_match(Logic::NUM_RE, trim($c['b'], " \t\r\n"));
    check("path {$c['name']}", $bothNumeric === ($c['path'] === 'numeric'));

    // A string-path case is pinned to plain byte order, computed independently.
    if ($c['path'] === 'string') {
        check("string path is byte order: {$c['name']}", byteCmp($c['a'], $c['b']) === $cmp);
    }

    // A floatTrap case only earns its keep while the old cast still disagrees.
    if (!empty($c['floatTrap'])) {
        $fc = floatCmp($c['a'], $c['b']);
        if ($fc === $cmp) {
            detail("{$c['name']} no longer traps the float cast (both say $cmp)");
        }
        check("float cast still disagrees: {$c['name']}", $fc !== $cmp);
    }
}

// ---- the cross-runtime lock: PHP and JS hash the same verdicts ----
$digest = hash('sha256', implode("\n", $digestLines));
if ($digest !== $fx['digest']) {
    fwrite(STDERR, "  fixture digest: {$fx['digest']}\n  php verdicts:   $digest\n"
        . "  the PHP comparator disagrees with the pinned verdicts; run tests/numeric_js.cjs\n"
        . "  too — whichever runtime matches the fixture is the one that is right.\n");
}
check('verdict digest matches the fixture (PHP == JS)', $digest === $fx['digest']);

printf("numeric_php: %d cases, %d checks, %d failure(s)\n", count($fx['cases']), $n, $fail);
exit($fail === 0 ? 0 : 1);
