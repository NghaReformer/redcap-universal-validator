<?php
/**
 * growth_php.php — growth references for @UVRANGE (server side, 2.6.0).
 *
 * Pins php/GrowthReference.php:
 *   1. every case of tests/growth_fixture.json (axis, LMS lookup, z-score,
 *      rounding, the measurement); tests/growth_js.cjs runs the same cases
 *      through the browser twin,
 *   2. the bundled WHO references: the index reads with no problem, every
 *      table matches its SHA-256, and 2,880 z-scores computed by WHO's own R
 *      code (tests/who_golden.json, tools/who_golden.R) come out the same,
 *   3. 5,000 random points give the SHA-256 the fixture pins; growth_js.cjs
 *      checks the same hash, so the two runtimes agree on every point,
 *   4. a folder of extra references: added and replacing by id, and every
 *      refusal of a broken index entry or table.
 *
 * Run:  php tests/growth_php.php
 */

require_once __DIR__ . '/../php/Logic.php';
require_once __DIR__ . '/../php/GrowthReference.php';

use INSPIRE\UniversalValidator\GrowthReference as G;

$n = 0;
$fails = 0;
function check($label, $ok)
{
    global $n, $fails;
    $n++;
    if (!$ok) { $fails++; echo "FAIL: $label\n"; }
}
/** Two LMS triples (or null) as the same doubles. */
function sameLms($a, $b)
{
    if ($a === null || $b === null) return $a === $b;
    return count($a) === 3 && count($b) === 3
        && (float) $a[0] === (float) $b[0] && (float) $a[1] === (float) $b[1] && (float) $a[2] === (float) $b[2];
}
function sameState($got, $want)
{
    if (!is_array($got) || !isset($got['state']) || $got['state'] !== $want['state']) return false;
    foreach ($want as $k => $v) {
        if (!array_key_exists($k, $got)) return false;
        if (is_int($v) || is_float($v)) { if ((float) $got[$k] !== (float) $v) return false; }
        elseif ($got[$k] !== $v) return false;
    }
    return count($got) === count($want);
}

// ---- 1. the fixture ---------------------------------------------------------------

$fx = json_decode(file_get_contents(__DIR__ . '/growth_fixture.json'), true);
check('fixture loads', is_array($fx) && count($fx['axis']) > 20 && count($fx['zText']) > 10);
$R = $fx['references'];
foreach ($fx['axis'] as $c) {
    $got = G::axisValue($R[$c['ref']], $c['input']);
    check('axis: ' . $c['name'] . ' (got ' . json_encode($got) . ')', sameState($got, $c['want']));
}
foreach ($fx['lms'] as $c) {
    $got = G::lms($R[$c['ref']], $R[$c['ref']], $c['sex'], $c['x']);
    check('lms: ' . $c['name'] . ' (got ' . json_encode($got) . ')', sameLms($got, $c['want']));
}
foreach ($fx['zRaw'] as $c) {
    $z = G::zRaw($c['y'], $c['lms'], $c['restricted']);
    $got = $z === null ? null : G::zText($z);
    check('zRaw: ' . $c['name'] . ' (got ' . json_encode($got) . ')', $got === $c['zText']);
}
foreach ($fx['zText'] as $c) {
    check('zText(' . json_encode($c[0]) . ') => ' . $c[1] . ' (got ' . G::zText($c[0]) . ')', G::zText($c[0]) === $c[1]);
}
foreach ($fx['measure'] as $c) {
    $got = G::measure($c['text'], $c['comma']);
    check('measure(' . json_encode($c['text']) . ', comma ' . json_encode($c['comma']) . ') (got ' . json_encode($got) . ')',
        sameState($got, $c['want']));
}
foreach ($fx['zScore'] as $c) {
    $got = G::zScore($R[$c['ref']], $R[$c['ref']], $c['sex'], $c['x'], $c['y']);
    check('zScore: ' . $c['name'] . ' (got ' . json_encode($got) . ')', sameState($got, $c['want']));
}
check('zText: never exponent notation, never "-0.00"', G::zText(-0.0) === '0.00' && G::zText(1e-300) === '0.00'
    && G::zText(-1e-300) === '0.00');

// ---- 2. the bundled WHO references ------------------------------------------------

G::reset();
$cat = G::catalog();
check('bundled: the index reads with no problem (got ' . json_encode($cat['problems']) . ')', $cat['problems'] === []);
$ids = array_keys($cat['references']);
check('bundled: twelve references, in index order', $ids === ['who-wfa', 'who-lhfa', 'who-bfa', 'who-hcfa', 'who-acfa', 'who-ssfa',
    'who-tsfa', 'who-wfl', 'who-wfh', 'who2007-wfa', 'who2007-hfa', 'who2007-bfa']);
check('bundled: four tables a page', $cat['pageTables'] === 4);
foreach ($cat['references'] as $id => $e) {
    $ok = true;
    try { $t = G::table($e); } catch (\Throwable $ex) { $ok = false; $t = null; }
    $raw = file_get_contents(G::bundledDir() . '/' . $e['file']);
    check('bundled: ' . $id . ' reads and matches its sha256', $ok && hash('sha256', $raw) === $e['sha256']
        && count($t['male']) > 1 && count($t['male']) === count($t['female']));
    // the valid range stays inside the rows, so a value it accepts always has a row
    $hi = isset($e['valid']['max']) ? (float) $e['valid']['max'] : (float) $e['valid']['below'];
    $lastKey = $t['first'] + count($t['male']) - 1;
    $key = function ($x) use ($e, $t) { return $e['lookup'] === 'round' ? floor($x * $t['scale'] + 0.5) : floor($x * $t['scale']); };
    check('bundled: ' . $id . ' has a row at both ends of its valid range',
        $t['first'] <= $key((float) $e['valid']['min']) && $key($hi) <= $lastKey);
}
check('bundled: the WHO licence ships with the tables', is_file(G::bundledDir() . '/who/LICENSE')
    && strpos(file_get_contents(G::bundledDir() . '/who/LICENSE'), 'GNU GENERAL PUBLIC LICENSE') !== false);
$wfa = G::entry('who-wfa');
check('entry: by id, with its folder and id', $wfa !== null && $wfa['id'] === 'who-wfa' && $wfa['dir'] === G::bundledDir());
check('entry: unknown id or not a string is null', G::entry('nope') === null && G::entry(5) === null);
check('table: memoised', G::table($wfa) === G::table($wfa));

$golden = json_decode(file_get_contents(__DIR__ . '/who_golden.json'), true);
check('golden: 2,880 points', is_array($golden['points']) && count($golden['points']) === 2880);
$bad = 0; $seen = [];
foreach ($golden['points'] as $p) {
    $e = G::entry($p['ref']);
    $t = G::table($e);
    $a = G::axisValue($e, $e['axis'] === 'age' ? ['days' => $p['x']] : ['by' => $p['x']]);
    $z = null;
    if ($a['state'] === 'ok') {
        $m = G::measure($p['y']);
        if ($m['state'] === 'ok') {
            $s = G::zScore($e, $t, $p['sex'] === 1 ? 'male' : 'female', $a['x'], $m['y']);
            if ($s['state'] === 'ok') $z = $s['z'];
        }
    }
    $seen[$p['ref']] = true;
    if ($z !== $p['z']) {
        $bad++;
        if ($bad <= 10) echo 'golden mismatch: ', json_encode($p), ' got ', json_encode($z), "\n";
    }
}
check('golden: every point equals WHO\'s own code (' . $bad . ' mismatches)', $bad === 0);
check('golden: every bundled reference is covered', count($seen) === 12);

// ---- 3. 5,000 random points --------------------------------------------------------

/** MINSTD: the same integers in PHP and JavaScript (every product stays under 2^53). */
function minstd(&$s) { $s = ($s * 48271) % 2147483647; return $s; }
/** A non-negative decimal string in hundredths. */
function hund($s) { $p = explode('.', $s); return (int) $p[0] * 100 + (isset($p[1]) ? (int) str_pad(substr($p[1], 0, 2), 2, '0') : 0); }
function hundText($h) { return intdiv($h, 100) . '.' . str_pad((string) ($h % 100), 2, '0', STR_PAD_LEFT); }
$s = $fx['parity']['seed'];
$lines = [];
$states = [];
for ($i = 0; $i < $fx['parity']['points']; $i++) {
    $id = $ids[minstd($s) % count($ids)];
    $e = G::entry($id);
    $t = G::table($e);
    $sex = minstd($s) % 2 ? 'female' : 'male';
    $lo = hund($e['valid']['min']);
    $hi = isset($e['valid']['max']) ? hund($e['valid']['max']) + 1 : hund($e['valid']['below']);
    $xt = hundText($lo + minstd($s) % ($hi - $lo));
    $a = G::axisValue($e, $e['axis'] === 'age' ? [$e['axisUnit'] => $xt] : ['by' => $xt]);
    $k = $a['state'] === 'ok' ? (int) floor($a['x'] * $t['scale']) - $t['first'] : 0;
    $k = max(0, min(count($t[$sex]) - 1, $k));
    $yt = hundText((int) floor($t[$sex][$k][1] * (50 + minstd($s) % 120)));
    $out = $a['state'];
    if ($a['state'] === 'ok') {
        $m = G::measure($yt);
        $out = $m['state'];
        if ($m['state'] === 'ok') {
            $z = G::zScore($e, $t, $sex, $a['x'], $m['y']);
            $out = $z['state'] === 'ok' ? $z['z'] : $z['state'];
        }
    }
    $states[preg_match('/^-?[0-9]/', $out) ? 'z' : $out] = true;
    $lines[] = $id . '|' . $sex . '|' . $xt . '|' . $yt . '|' . $out;
}
$hash = hash('sha256', implode("\n", $lines));
check('parity: 5,000 points hash to the pinned SHA-256 (got ' . $hash . ')', $hash === $fx['parity']['sha256']);
check('parity: every point has a z-score', array_keys($states) === ['z']);
if (in_array('--print-parity', $argv, true)) echo $hash, "\n";

// ---- 4. a folder of extra references ------------------------------------------------

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uv_growth_php_' . bin2hex(random_bytes(6));
mkdir($dir);
$rows = [[1, 10, 0.1], [1, 10, 0.1]];
$tableOk = json_encode(['format' => 'uv-lms-1', 'scale' => 1, 'first' => 0, 'male' => $rows, 'female' => $rows]);
file_put_contents($dir . '/t.json', $tableOk);
$base = ['title' => 'T', 'file' => 't.json', 'sha256' => hash('sha256', $tableOk), 'measure' => 'weight', 'unit' => 'kg',
         'axis' => 'age', 'axisUnit' => 'days', 'lookup' => 'round', 'valid' => ['min' => '0', 'below' => '1.5'], 'adjust' => 'none'];
function writeIndex($dir, $refs, $extra = [])
{
    file_put_contents($dir . '/index.json', json_encode(array_merge(['format' => 'uv-references-1', 'references' => $refs], $extra)));
    G::reset();
}
writeIndex($dir, ['who-wfa' => $base + ['x' => 1], 'mine' => $base]);
$c = G::catalog($dir);
check('extra: added and replacing by id', $c['problems'] === [] && count($c['references']) === 13
    && $c['references']['who-wfa']['title'] === 'T' && $c['references']['who-wfa']['dir'] === $dir
    && isset($c['references']['mine']) && $c['references']['who-lhfa']['dir'] === G::bundledDir());
check('extra: a path with a trailing separator is the same folder', G::catalog($dir . DIRECTORY_SEPARATOR)['references']['mine']['dir'] === $dir);
check('extra: blank means none', count(G::catalog('  ')['references']) === 12 && count(G::catalog(null)['references']) === 12);
check('extra: the table reads', G::table(G::entry('mine', $dir))['male'][0] === [1.0, 10.0, 0.1]);

$entryCases = [
    'an id with capitals'     => [['Mine' => $base], 'reference "Mine" has an id that is not 1 to 64 lower-case letters'],
    'an id too long'          => [[str_repeat('a', 65) => $base], 'has an id that is not 1 to 64'],
    'not an object'           => [['mine' => 'x'], 'reference "mine" is not an object.'],
    'no title'                => [['mine' => array_diff_key($base, ['title' => 1])], 'reference "mine" needs "title".'],
    'a blank unit'            => [['mine' => ['unit' => ''] + $base], 'needs "unit".'],
    'a file going up'         => [['mine' => ['file' => '../t.json'] + $base], 'has a "file" that is not a .json path inside its folder.'],
    'a file going up inside'  => [['mine' => ['file' => 'a/../../t.json'] + $base], 'has a "file" that is not a .json path'],
    'an absolute file'        => [['mine' => ['file' => '/etc/t.json'] + $base], 'has a "file" that is not a .json path'],
    'a file not .json'        => [['mine' => ['file' => 't.php'] + $base], 'has a "file" that is not a .json path'],
    'a sha256 in capitals'    => [['mine' => ['sha256' => strtoupper($base['sha256'])] + $base], 'needs "sha256" as 64 lower-case hex digits.'],
    'an unknown axis'         => [['mine' => ['axis' => 'weight'] + $base], 'has "axis" "weight"; it must be age, length or height.'],
    'cm on an age axis'       => [['mine' => ['axisUnit' => 'cm'] + $base], 'has "axisUnit" "cm"; with axis age it must be days or months.'],
    'days on a length axis'   => [['mine' => ['axis' => 'length'] + $base], 'with axis length it must be cm.'],
    'an unknown lookup'       => [['mine' => ['lookup' => 'nearest'] + $base], 'has "lookup" "nearest"; it must be round, floor or linear.'],
    'an unknown adjust'       => [['mine' => ['adjust' => 'cdc'] + $base], 'has "adjust" "cdc"; it must be none or who-restricted.'],
    'no valid range'          => [['mine' => array_diff_key($base, ['valid' => 1])], 'needs "valid"'],
    'a min that is a number'  => [['mine' => ['valid' => ['min' => 0, 'below' => '1']] + $base], 'needs "valid" "min" as a number in a string.'],
    'both max and below'      => [['mine' => ['valid' => ['min' => '0', 'max' => '1', 'below' => '1']] + $base], 'needs exactly one of "valid" "max" and "below".'],
    'neither max nor below'   => [['mine' => ['valid' => ['min' => '0']] + $base], 'needs exactly one of'],
    'a below that is text'    => [['mine' => ['valid' => ['min' => '0', 'below' => 'x']] + $base], 'needs "valid" "below" as a number in a string.'],
    // the browser reads a key that is there, even as null ("!== undefined")
    'a max of null'           => [['mine' => ['valid' => ['min' => '0', 'max' => null, 'below' => '9']] + $base], 'needs exactly one of "valid" "max" and "below".'],
    'a lone max of null'      => [['mine' => ['valid' => ['min' => '0', 'max' => null]] + $base], 'needs "valid" "max" as a number in a string.'],
    'a range ending early'    => [['mine' => ['valid' => ['min' => '5', 'max' => '1']] + $base], 'has a "valid" range that ends before it starts.'],
    // a bound past 2^63 / scale wrapped the whole-number row key
    'a bound past a million'  => [['mine' => ['valid' => ['min' => '0', 'max' => '10000000000000000000']] + $base], 'has a "valid" bound beyond 1000000 (or -1000000).'],
    'a min below -1000000'    => [['mine' => ['valid' => ['min' => '-1000001', 'max' => '1']] + $base], 'has a "valid" bound beyond 1000000'],
];
foreach ($entryCases as $label => $case) {
    writeIndex($dir, $case[0]);
    $c = G::catalog($dir);
    $p = implode(' ', $c['problems']);
    check('extra: refused, ' . $label . ' (got ' . json_encode($p) . ')', strpos($p, 'the reference folder in the module settings: ') === 0
        && strpos($p, $case[1]) !== false && count($c['references']) === 12);
}
$indexCases = [
    'not JSON'           => ['{', 'index.json is not valid JSON.'],
    'no format'          => ['{"references":{}}', 'index.json must say "format": "uv-references-1".'],
    'no references'      => ['{"format":"uv-references-1"}', 'index.json has no "references".'],
    'pageTables below 0' => ['{"format":"uv-references-1","references":{},"pageTables":-1}', '"pageTables" must be a whole number from 0 to 50.'],
    'pageTables past 50' => ['{"format":"uv-references-1","references":{},"pageTables":51}', '"pageTables" must be a whole number from 0 to 50.'],
    'pageTables as text' => ['{"format":"uv-references-1","references":{},"pageTables":"4"}', '"pageTables" must be a whole number'],
];
foreach ($indexCases as $label => $case) {
    file_put_contents($dir . '/index.json', $case[0]);
    G::reset();
    $p = implode(' ', G::catalog($dir)['problems']);
    check('extra index: refused, ' . $label . ' (got ' . json_encode($p) . ')', strpos($p, $case[1]) !== false
        && G::catalog($dir)['references'] === [] && G::entry('who-wfa', $dir) === null);
}
// A broken entry that replaces a bundled id takes the bundled one out too: an
// upper-case sha256 (as PowerShell prints it) must not leave WHO's table in use.
G::reset();
writeIndex($dir, ['who-wfa' => ['sha256' => strtoupper($base['sha256'])] + $base]);
check('extra: a broken replacement removes the bundled reference', G::entry('who-wfa', $dir) === null
    && count(G::catalog($dir)['references']) === 11
    && strpos((string) G::unavailableWhy('who-wfa', $dir), 'reference "who-wfa" needs "sha256" as 64 lower-case hex digits.') !== false
    && G::unavailableWhy('who-lhfa', $dir) === null && G::entry('who-lhfa', $dir) !== null);
writeIndex($dir, [], ['pageTables' => 0]);
check('extra index: pageTables 0 is allowed and taken', G::catalog($dir)['pageTables'] === 0);
G::reset();
$missing = $dir . DIRECTORY_SEPARATOR . 'nowhere';
// Fail closed: the folder may hold replacements for any bundled id, so a folder
// that cannot be read leaves no reference at all (a configuration error on every
// growth rule) rather than the tables the administrator meant to replace. The
// folder's path is not in the text, which reaches the page.
$broken = 'the reference folder in the module settings: the folder has no readable index.json.';
check('extra: a folder with no index is a problem, and no reference is used',
    implode(' ', G::catalog($missing)['problems']) === $broken && G::catalog($missing)['references'] === []
    && G::catalog($missing)['broken'] === $broken && G::unavailableWhy('who-wfa', $missing) === $broken
    && strpos($broken, $missing) === false);
check('extra: without a folder nothing is unavailable', G::unavailableWhy('who-wfa') === null && G::unavailableWhy('nope') === null);

$tableErr = function ($content, $entryOver = []) use ($dir, $base) {
    if ($content !== null) file_put_contents($dir . '/t2.json', $content);
    $e = array_merge($base, ['file' => 't2.json', 'sha256' => hash('sha256', (string) $content), 'id' => 'mine', 'dir' => $dir], $entryOver);
    G::reset();
    try { G::table($e); return ''; } catch (\RuntimeException $ex) { return $ex->getMessage(); }
};
$t = function (array $over) use ($rows) {
    return json_encode(array_merge(['format' => 'uv-lms-1', 'scale' => 1, 'first' => 0, 'male' => $rows, 'female' => $rows], $over));
};
$tableCases = [
    'a good table'            => [$t([]), ''],
    'not the format'          => [$t(['format' => 'lms']), 'the table t2.json is not a "uv-lms-1" table.'],
    'not JSON'                => ['{', 'is not a "uv-lms-1" table.'],
    'scale 0'                 => [$t(['scale' => 0]), 'needs "scale" as a whole number from 1 to 1000.'],
    'scale 1001'              => [$t(['scale' => 1001]), 'needs "scale" as a whole number from 1 to 1000.'],
    'scale as text'           => [$t(['scale' => '1']), 'needs "scale"'],
    'first a fraction'        => [$t(['first' => 0.5]), 'needs "first" as a whole number from'],
    'no female rows'          => [$t(['female' => []]), 'needs a list of "female" rows.'],
    'rows keyed'              => [$t(['male' => ['a' => [1, 10, 0.1]]]), 'needs a list of "male" rows.'],
    'a row of two'            => [$t(['male' => [[1, 10]]]), 'row 0 of "male" is not [L, M, S].'],
    'a row keyed'             => [$t(['male' => [['l' => 1, 'm' => 10, 's' => 0.1]]]), 'row 0 of "male" is not [L, M, S].'],
    'a number as text'        => [$t(['female' => [[1, '10', 0.1]]]), 'row 0 of "female" holds something that is not a finite number.'],
    'M of 0'                  => [$t(['male' => [[1, 10, 0.1], [1, 0, 0.1]]]), 'row 1 of "male" has M or S at or below 0.'],
    'S below 0'               => [$t(['male' => [[1, 10, -0.1]]]), 'row 0 of "male" has M or S at or below 0.'],
    'S of 1e-17'              => [$t(['male' => [[1, 10, 1e-17], [1, 10, 0.1]]]), 'row 0 of "male" has an S below 0.0001.'],
    'L of 2.22e-16'           => [$t(['female' => [[1, 10, 0.1], [2.22e-16, 10, 0.035]]]), 'row 1 of "female" has an L of 2.22E-16; write an L of 0 as 0 (any other L must be at least 0.000001 in size).'],
    'an L of 0 is fine'       => [$t(['female' => [[0, 10, 0.1], [0, 10, 0.1]]]), ''],
    'first past a billion'    => [$t(['first' => 1000000001]), 'needs "first" as a whole number from -1000000000 to 1000000000.'],
];
foreach ($tableCases as $label => $case) {
    $got = $tableErr($case[0]);
    check('table: ' . $label . ' (got ' . json_encode($got) . ')', $case[1] === '' ? $got === '' : strpos($got, $case[1]) !== false);
}
// 1e400 decodes as INF: it would break json_encode of the whole page config.
check('table: a number past what a float holds', strpos($tableErr(str_replace('10', '1e400', $t([]))),
    'row 0 of "male" holds something that is not a finite number.') !== false);
// The rows must reach every position "valid" admits under the lookup (rows 0 and 1 here).
$cover = [
    'round, below 1.5 (1.49 rounds to row 1)'      => ['round', ['min' => '0', 'below' => '1.5'], true],
    'round, below 1.51 (1.505 rounds to row 2)'    => ['round', ['min' => '0', 'below' => '1.51'], false],
    'round, max 1.5 (rounds to row 2)'             => ['round', ['min' => '0', 'max' => '1.5'], false],
    'round, max 1.49'                              => ['round', ['min' => '0', 'max' => '1.49'], true],
    'round, below 2 (1.999 rounds to row 2)'       => ['round', ['min' => '0', 'below' => '2'], false],
    'round, min -0.5 (rounds to row 0)'            => ['round', ['min' => '-0.5', 'below' => '1.5'], true],
    'round, min -0.6 (rounds to row -1)'           => ['round', ['min' => '-0.6', 'below' => '1.5'], false],
    'floor, below 2'                               => ['floor', ['min' => '0', 'below' => '2'], true],
    'floor, max 2'                                 => ['floor', ['min' => '0', 'max' => '2'], false],
    'floor, below 2.1'                             => ['floor', ['min' => '0', 'below' => '2.1'], false],
    'linear, max 1'                                => ['linear', ['min' => '0', 'max' => '1'], true],
    'linear, below 1 (0.99 reads rows 0 and 1)'    => ['linear', ['min' => '0', 'below' => '1'], true],
    'linear, max 1.1 (reads row 2)'                => ['linear', ['min' => '0', 'max' => '1.1'], false],
];
foreach ($cover as $label => $c) {
    $got = $tableErr($t([]), ['lookup' => $c[0], 'valid' => $c[1]]);
    check('table: rows cover "valid", ' . $label . ' (got ' . json_encode($got) . ')',
        $c[2] ? $got === '' : strpos($got, 'but its "valid" range needs rows') !== false);
}
check('table: a sex with fewer rows is named', strpos($tableErr($t(['female' => [[1, 10, 0.1]]])),
    'has "female" rows 0 to 0, but its "valid" range needs rows 0 to 1') !== false);
// The ends are found with lms()'s own floating-point arithmetic, not from the
// decimal digits of "valid": 0.8999999999999999 (the double just under 0.9)
// times 10 is 9.0, so "floor" below 0.9 at scale 10 reads row 9 ...
$rowsOf = function ($count) { return array_fill(0, $count, [1, 10, 0.1]); };
check('table: 0.8999999999999999 * 10 is 9.0 in floating point', floor(0.8999999999999999 * 10) === 9.0);
$got = $tableErr($t(['scale' => 10, 'male' => $rowsOf(9), 'female' => $rowsOf(9)]), ['lookup' => 'floor', 'valid' => ['min' => '0', 'below' => '0.9']]);
check('table: floor, scale 10, below 0.9 needs row 9 (got ' . json_encode($got) . ')', strpos($got, 'needs rows 0 to 9 (a row is 1/10 days)') !== false);
check('table: floor, scale 10, below 0.9, rows 0 to 9 accepted',
    $tableErr($t(['scale' => 10, 'male' => $rowsOf(10), 'female' => $rowsOf(10)]), ['lookup' => 'floor', 'valid' => ['min' => '0', 'below' => '0.9']]) === '');
$e9 = array_merge($base, ['id' => 'x', 'dir' => $dir, 'lookup' => 'floor']);
check('lms: ... and lms() does read row 9 there', G::lms($e9, ['scale' => 10, 'first' => 0, 'male' => array_merge($rowsOf(9), [[2, 10, 0.1]])],
    'male', 0.8999999999999999) === [2, 10, 0.1]);
// ... while "linear" at 1.1 * 100 = 110.00000000000001 reads row 110 alone
// (its share of the way to row 111 is 0), so rows 0 to 110 are enough.
check('table: linear, scale 100, max 1.1, rows 0 to 110 accepted',
    $tableErr($t(['scale' => 100, 'male' => $rowsOf(111), 'female' => $rowsOf(111)]), ['lookup' => 'linear', 'valid' => ['min' => '0', 'max' => '1.1']]) === '');
$got = $tableErr($t(['scale' => 100, 'male' => $rowsOf(110), 'female' => $rowsOf(110)]), ['lookup' => 'linear', 'valid' => ['min' => '0', 'max' => '1.1']]);
check('table: linear, scale 100, max 1.1, rows 0 to 109 refused (got ' . json_encode($got) . ')', strpos($got, 'needs rows 0 to 110') !== false);
// Two entries may share one file with different "valid" ranges: the second is
// checked although the file was read for the first.
file_put_contents($dir . '/t2.json', $t([]));
G::reset();
$narrow = array_merge($base, ['file' => 't2.json', 'sha256' => hash('sha256', $t([])), 'id' => 'narrow', 'dir' => $dir]);
G::table($narrow);
try { G::table(['id' => 'wide', 'valid' => ['min' => '0', 'below' => '5']] + $narrow); $got = ''; } catch (\RuntimeException $ex) { $got = $ex->getMessage(); }
check('table: a second entry on the same file is checked against its own range (got ' . json_encode($got) . ')',
    strpos($got, 'needs rows 0 to 5') !== false);
try { G::table(['id' => 'wide', 'lookup' => 'linear', 'valid' => ['min' => '0', 'max' => '1.5']] + $narrow); $got = ''; } catch (\RuntimeException $ex) { $got = $ex->getMessage(); }
check('table: ... and against its own lookup (got ' . json_encode($got) . ')', strpos($got, 'needs rows 0 to 2') !== false);

// ---- limits a reference can never pass
// Without "adjust", L below 0 gives a z-score ceiling of 1/(|L|*S): with L -2 and
// S 0.13 (as in CDC's BMI tables), even a BMI of a million scores 3.85.
$limitErr = function ($rowsM, $rowsF, $adjust, $limits, $scale = 1) use ($dir, $base, $t) {
    $c = $t(['scale' => $scale, 'male' => $rowsM, 'female' => $rowsF]);
    file_put_contents($dir . '/t3.json', $c);
    G::reset();
    $e = array_merge($base, ['file' => 't3.json', 'sha256' => hash('sha256', $c), 'id' => 'mine', 'dir' => $dir, 'adjust' => $adjust,
                             'valid' => ['min' => '0', 'max' => (string) ((count($rowsM) - 1) / $scale)]]);
    return G::limitProblem($e, G::table($e), $limits);
};
$cdc = [[-2, 17, 0.13], [-2, 17, 0.13]];
check('limits: z of a BMI of a million is 3.85 with L -2, S 0.13', G::zText(G::zRaw(1e6, [-2, 17, 0.13], false)) === '3.85');
check('limits: a hard high limit of 5 is out of reach there', $limitErr($cdc, $cdc, 'none', ['hard' => ['-5', '5']])
    === 'its "hard" high limit 5 is out of reach: with "mine" no measurement scores above 3.85 at 0 days (male), so a high limit must be below 3.84.');
// z stays under 3.8462: the edge is 3.84 (3.8462 - 0.01, to hundredths, + 0.01)
check('limits: 3.84 is refused', strpos((string) $limitErr($cdc, $cdc, 'none', ['hard' => [null, '3.84']]), 'must be below 3.84.') !== false);
check('limits: 3.839 and 3.83 can be passed', $limitErr($cdc, $cdc, 'none', ['hard' => [null, '3.839']]) === null
    && $limitErr($cdc, $cdc, 'none', ['hard' => [null, '3.83']]) === null
    && G::zText(G::zRaw(1e6, [-2, 17, 0.13], false)) > '3.839');
check('limits: a soft high limit is checked too', strpos((string) $limitErr($cdc, $cdc, 'none', ['soft' => [null, '3.9']]),
    'its "soft" high limit 3.9 is out of reach') === 0);
check('limits: open limits and a low limit with no floor are fine', $limitErr($cdc, $cdc, 'none', ['soft' => [null, null], 'hard' => ['-20', null]]) === null);
// L above 0 gives a floor of -1/(L*S); the row and sex that set it are named.
$mRows = [[1, 10, 0.1], [1, 10, 0.1]];
$fRows = [[1, 10, 0.1], [1, 10, 0.2]];
check('limits: a floor set by one row of one sex is named there', $limitErr($mRows, $fRows, 'none', ['hard' => ['-6', '6']])
    === 'its "hard" low limit -6 is out of reach: with "mine" no measurement scores below -5.00 at 1 days (female), so a low limit must be above -5.00.');
check('limits: -5 is refused, -4.999 and -4.99 can be passed', $limitErr($mRows, $fRows, 'none', ['hard' => ['-5', '6']]) !== null
    && $limitErr($mRows, $fRows, 'none', ['hard' => ['-4.999', '6']]) === null
    && $limitErr($mRows, $fRows, 'none', ['hard' => ['-4.99', '6']]) === null
    && G::zText(G::zRaw(1e-9, [1, 10, 0.2], false)) === '-5.00');
check('limits: the position is in axis units', strpos((string) $limitErr($mRows, $fRows, 'none', ['hard' => ['-6', null]], 10), 'at 0.1 days (female)') !== false);
// WHO's restricted method has no ceiling and, below -3 SD, the floor
// -3 - SD-3/(SD-2 - SD-3): with L 0.5 and S 0.1, -11.26.
$rRows = [[0.5, 10, 0.1], [0.5, 10, 0.1]];
check('limits: restricted, the floor below -3 SD', strpos((string) $limitErr($rRows, $rRows, 'who-restricted', ['hard' => ['-12', null]]),
    'no measurement scores below -11.26') !== false && $limitErr($rRows, $rRows, 'who-restricted', ['hard' => ['-11.24', '20']]) === null
    && $limitErr($rRows, $rRows, 'who-restricted', ['hard' => ['-11.249', null]]) === null
    && strpos((string) $limitErr($rRows, $rRows, 'who-restricted', ['hard' => ['-11.25', null]]), 'must be above -11.25.') !== false);
check('limits: restricted, the same row without "adjust" has the floor -20', $limitErr($rRows, $rRows, 'none', ['hard' => ['-19.99', null]]) === null
    && strpos((string) $limitErr($rRows, $rRows, 'none', ['hard' => ['-20', null]]), 'below -20.00') !== false);
// The bounds are kept per file and "adjust": a second entry on the same file
// with another "adjust" gets its own, in the same request.
$limitErr($rRows, $rRows, 'none', []);   // writes t3.json and resets the memos
$eN = array_merge($base, ['file' => 't3.json', 'id' => 'mine', 'dir' => $dir, 'adjust' => 'none',
    'sha256' => hash('sha256', (string) file_get_contents($dir . '/t3.json')), 'valid' => ['min' => '0', 'max' => '1']]);
$eW = ['adjust' => 'who-restricted'] + $eN;
check('limits: two entries on one file, without and with "adjust", in one request',
    G::limitProblem($eN, G::table($eN), ['hard' => ['-19.99', null]]) === null
    && strpos((string) G::limitProblem($eW, G::table($eW), ['hard' => ['-19.99', null]]), 'below -11.26') !== false);
// zRange is zRaw's twin: at a measurement near 0, or very large, zRaw lands on
// the bound from inside, and where zRange says there is none, it goes past 20.
$zRange = new \ReflectionMethod(G::class, 'zRange');
$zRange->setAccessible(true);
$bad = [];
foreach ([-2, -1, -0.3, 0, 0.3, 1, 2] as $L) {
    foreach ([0.05, 0.1, 0.13, 0.2] as $S) {
        foreach ([false, true] as $restricted) {
            list($floor, $ceil) = $zRange->invoke(null, $L, $S, $restricted);
            $lo = G::zRaw(10 * 1e-30, [$L, 10, $S], $restricted);
            $hi = G::zRaw(10 * 1e30, [$L, 10, $S], $restricted);
            $okLo = $floor === null ? ($lo === null || $lo < -20) : ($lo !== null && $lo > $floor - 1e-9 && $lo - $floor < 1e-6);
            $okHi = $ceil === null ? ($hi === null || $hi > 20) : ($hi !== null && $hi < $ceil + 1e-9 && $ceil - $hi < 1e-6);
            if (!$okLo || !$okHi) $bad[] = json_encode([$L, $S, $restricted, $floor, $lo, $ceil, $hi]);
        }
    }
}
check('limits: zRange agrees with zRaw at the extremes (bad: ' . implode(' ', $bad) . ')', $bad === []);
// The bundled tables: triceps skinfold-for-age never scores below -6.97 (girls).
$tsfa = G::entry('who-tsfa');
check('limits: who-tsfa refuses a hard low limit of -7', strpos((string) G::limitProblem($tsfa, G::table($tsfa), ['hard' => ['-7', '5']]),
    'its "hard" low limit -7 is out of reach: with "who-tsfa" no measurement scores below -6.97 at ') === 0);
check('limits: who-tsfa takes -6.96, -6.961 and -6.9605', G::limitProblem($tsfa, G::table($tsfa), ['hard' => ['-6.96', '5']]) === null
    && G::limitProblem($tsfa, G::table($tsfa), ['hard' => ['-6.961', '5']]) === null
    && G::limitProblem($tsfa, G::table($tsfa), ['hard' => ['-6.9605', '5']]) === null);
check('limits: who-tsfa refuses -6.97 (a score must fall below it)', strpos((string) G::limitProblem($tsfa, G::table($tsfa), ['hard' => ['-6.97', '5']]),
    '(female), so a low limit must be above -6.97.') !== false);
// Rows that would make the bounds meaningless are refused when the table is
// read, and a bound past every allowed limit limits nothing.
check('limits: zRange with L*S vanishing, or S near 0 under the restriction, gives no bound',
    $zRange->invoke(null, 1e-300, 1e-20, true) === [null, null] && $zRange->invoke(null, 2.22e-16, 0.035, false) === [null, null]
    && $zRange->invoke(null, 1, 1e-17, true) === [null, null] && $zRange->invoke(null, 1, 0.04, false) === [null, null]
    // 1e-300 * 1e-30 underflows to 0: no division by it
    && $zRange->invoke(null, 1e-300, 1e-30, false) === [null, null] && $zRange->invoke(null, -1e-300, 1e-30, false) === [null, null]);
$bad = [];
foreach (G::catalog()['references'] as $id => $e) {
    if (G::limitProblem($e, G::table($e), ['soft' => ['-3', '3'], 'hard' => ['-6', '6']]) !== null) $bad[] = $id;
}
check('limits: every bundled reference takes soft -3 to 3 and hard -6 to 6 (refused: ' . implode(', ', $bad) . ')', $bad === []);
// A "linear" lookup also reads between rows; there the bound must not pass the
// row bounds by anything near the 0.01 margin.
$worst = 0.0;
foreach (G::catalog()['references'] as $id => $e) {
    if ($e['lookup'] !== 'linear') continue;
    $tb = G::table($e);
    foreach (['male', 'female'] as $sex) {
        $rs = $tb[$sex];
        for ($i = 0; $i + 1 < count($rs); $i++) {
            $ends = [$zRange->invoke(null, $rs[$i][0], $rs[$i][2], $e['adjust'] === 'who-restricted'),
                     $zRange->invoke(null, $rs[$i + 1][0], $rs[$i + 1][2], $e['adjust'] === 'who-restricted')];
            foreach ([0.25, 0.5, 0.75] as $u) {
                $mid = $zRange->invoke(null, $rs[$i][0] + $u * ($rs[$i + 1][0] - $rs[$i][0]), $rs[$i][2] + $u * ($rs[$i + 1][2] - $rs[$i][2]),
                                       $e['adjust'] === 'who-restricted');
                if ($mid[0] !== null) $worst = max($worst, $mid[0] - max($ends[0][0], $ends[1][0]));
                if ($mid[1] !== null) $worst = max($worst, min($ends[0][1], $ends[1][1]) - $mid[1]);
            }
        }
    }
}
check('limits: between the rows of the bundled linear tables the bound moves less than 0.001 past the rows (' . $worst . ')', $worst < 0.001);
check('table: a sha256 that does not match', $tableErr($t([]), ['sha256' => str_repeat('a', 64)])
    === 'the table t2.json does not match the "sha256" in index.json; it was changed or damaged.');
@unlink($dir . '/t2.json');
check('table: a file that is not there', $tableErr(null) === 'the table t2.json of "mine" cannot be read.');
file_put_contents($dir . '/t2.json', str_repeat(' ', G::MAX_TABLE_BYTES + 1));
G::reset();
$e = array_merge($base, ['file' => 't2.json', 'sha256' => hash('sha256', str_repeat(' ', G::MAX_TABLE_BYTES + 1)), 'id' => 'mine', 'dir' => $dir]);
try { G::table($e); $got = ''; } catch (\RuntimeException $ex) { $got = $ex->getMessage(); }
check('table: larger than 4 MB, refused before it is read', $got === 'the table t2.json is larger than 4 MB.');
check('pageCopy: the index fields and the rows, nothing else',
    array_keys(G::pageCopy(G::entry('who-wfa'), G::table(G::entry('who-wfa'))))
    === ['title', 'measure', 'unit', 'axis', 'axisUnit', 'lookup', 'valid', 'adjust', 'scale', 'first', 'male', 'female']);
foreach (glob($dir . '/*') as $x) unlink($x);
rmdir($dir);

echo "growth_php: $n checks, $fails failure(s)\n";
exit($fails ? 1 : 0);
