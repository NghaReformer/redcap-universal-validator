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
    'a range ending early'    => [['mine' => ['valid' => ['min' => '5', 'max' => '1']] + $base], 'has a "valid" range that ends before it starts.'],
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
    check('extra index: refused, ' . $label . ' (got ' . json_encode($p) . ')', strpos($p, $case[1]) !== false);
}
writeIndex($dir, [], ['pageTables' => 0]);
check('extra index: pageTables 0 is allowed and taken', G::catalog($dir)['pageTables'] === 0);
G::reset();
$missing = $dir . DIRECTORY_SEPARATOR . 'nowhere';
check('extra: a folder with no index is a problem, the bundled references stay',
    implode(' ', G::catalog($missing)['problems']) === 'the reference folder in the module settings: no readable index.json in ' . $missing . '.'
    && count(G::catalog($missing)['references']) === 12);

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
    'first a fraction'        => [$t(['first' => 0.5]), 'needs "first" as a whole number.'],
    'no female rows'          => [$t(['female' => []]), 'needs a list of "female" rows.'],
    'rows keyed'              => [$t(['male' => ['a' => [1, 10, 0.1]]]), 'needs a list of "male" rows.'],
    'a row of two'            => [$t(['male' => [[1, 10]]]), 'row 0 of "male" is not [L, M, S].'],
    'a row keyed'             => [$t(['male' => [['l' => 1, 'm' => 10, 's' => 0.1]]]), 'row 0 of "male" is not [L, M, S].'],
    'a number as text'        => [$t(['female' => [[1, '10', 0.1]]]), 'row 0 of "female" holds something that is not a number.'],
    'M of 0'                  => [$t(['male' => [[1, 10, 0.1], [1, 0, 0.1]]]), 'row 1 of "male" has M or S at or below 0.'],
    'S below 0'               => [$t(['male' => [[1, 10, -0.1]]]), 'row 0 of "male" has M or S at or below 0.'],
];
foreach ($tableCases as $label => $case) {
    $got = $tableErr($case[0]);
    check('table: ' . $label . ' (got ' . json_encode($got) . ')', $case[1] === '' ? $got === '' : strpos($got, $case[1]) !== false);
}
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
