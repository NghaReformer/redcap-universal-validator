<?php
/**
 * convert.php — turn an LMS table (CSV or tab-separated) into the module's
 * reference table format, "uv-lms-1".
 *
 * Command line only. Example, a WHO 2006 table:
 *
 *   php convert.php --in weianthro.txt --out who/who-wfa.json --x age
 *
 * and a CDC 2000 table, whose ages sit on half months (24.5 is the row for
 * ages from 24 up to 25 months, 25.5 for 25 up to 26, and so on); its extra
 * whole-month rows at 24 and 240 are left out with --min-x and --max-x:
 *
 *   php convert.php --in wtage.csv --out cdc-wfa.json --x Agemos --x-offset 0.5 --min-x 24.5 --max-x 239.5
 *
 * Options:
 *   --in FILE        the table; the first line names the columns
 *   --out FILE       where to write the JSON table
 *   --x NAME         the column holding the age, length or height
 *   --scale N        rows per unit of x (WHO length tables: 10, one row per mm). Default 1
 *   --x-offset D     subtracted from x before scaling (CDC half months: 0.5). Default 0
 *   --min-x D        skip rows below this x (before the offset)
 *   --max-x D        skip rows above this x (before the offset)
 *   --sex NAME       the sex column. Default "sex"
 *   --male CODE      the code of male rows in that column. Default 1
 *   --female CODE    the code of female rows. Default 2
 *   --l NAME, --m NAME, --s NAME   the L, M and S columns. Default l, m and s
 *
 * Column names match without regard to case. The rows of each sex must form
 * one unbroken run of keys (x minus the offset, times the scale, a whole
 * number), and both sexes must start at the same key. The script prints the
 * SHA-256 of what it wrote, for the "sha256" of the entry in index.json, and
 * a starting entry to edit. See README.md in this folder.
 */

if (PHP_SAPI !== 'cli') {
    // This file sits inside the module folder; it is a tool, never a page.
    http_response_code(404);
    exit;
}

function fail($msg)
{
    fwrite(STDERR, 'convert.php: ' . $msg . "\n");
    exit(1);
}

$opts = getopt('', ['in:', 'out:', 'x:', 'scale:', 'x-offset:', 'min-x:', 'max-x:',
                    'sex:', 'male:', 'female:', 'l:', 'm:', 's:']);
foreach (['in', 'out', 'x'] as $k) {
    if (!isset($opts[$k]) || !is_string($opts[$k]) || $opts[$k] === '') fail('--' . $k . ' is required (see the comment at the top of this file).');
}
$num = function ($k, $default) use ($opts) {
    if (!isset($opts[$k])) return $default;
    if (!is_string($opts[$k]) || !is_numeric($opts[$k])) fail('--' . $k . ' must be a number.');
    return (float) $opts[$k];
};
$scale = $num('scale', 1.0);
if ($scale <= 0 || floor($scale) != $scale) fail('--scale must be a whole number above 0.');
$scale = (int) $scale;
$offset = $num('x-offset', 0.0);
$minX = $num('min-x', null);
$maxX = $num('max-x', null);
$col = function ($k, $default) use ($opts) { return strtolower(isset($opts[$k]) ? (string) $opts[$k] : $default); };
$want = ['x' => $col('x', ''), 'sex' => $col('sex', 'sex'), 'l' => $col('l', 'l'), 'm' => $col('m', 'm'), 's' => $col('s', 's')];
$codes = ['male' => isset($opts['male']) ? (string) $opts['male'] : '1', 'female' => isset($opts['female']) ? (string) $opts['female'] : '2'];
if ($codes['male'] === $codes['female']) fail('--male and --female must differ.');

$text = @file_get_contents($opts['in']);
if ($text === false) fail('cannot read ' . $opts['in'] . '.');
if (substr($text, 0, 3) === "\xEF\xBB\xBF") $text = substr($text, 3);
$lines = preg_split('/\r\n|\r|\n/', $text);
$header = array_shift($lines);
$sep = strpos((string) $header, "\t") !== false ? "\t" : ',';
$names = array_map(function ($h) { return strtolower(trim($h, " \t\"")); }, str_getcsv((string) $header, $sep, '"', ''));
$at = [];
foreach ($want as $role => $name) {
    $i = array_search($name, $names, true);
    if ($i === false) fail('no column "' . $name . '" (the columns are: ' . implode(', ', $names) . ').');
    $at[$role] = $i;
}

$rows = ['male' => [], 'female' => []];
foreach ($lines as $n => $line) {
    if (trim($line) === '') continue;
    $cells = str_getcsv($line, $sep, '"', '');
    $cell = function ($role) use ($cells, $at, $n) {
        $v = isset($cells[$at[$role]]) ? trim($cells[$at[$role]], " \t\"") : '';
        return $v;
    };
    $sexCode = $cell('sex');
    $sex = $sexCode === $codes['male'] ? 'male' : ($sexCode === $codes['female'] ? 'female' : null);
    if ($sex === null) fail('line ' . ($n + 2) . ': sex "' . $sexCode . '" is neither --male nor --female.');
    $x = $cell('x');
    if (!is_numeric($x)) fail('line ' . ($n + 2) . ': x "' . $x . '" is not a number.');
    $x = (float) $x;
    if ($minX !== null && $x < $minX) continue;
    if ($maxX !== null && $x > $maxX) continue;
    $k = ($x - $offset) * $scale;
    $key = (int) round($k);
    if (abs($k - $key) > 1e-6) fail('line ' . ($n + 2) . ': x ' . $x . ' is not on the grid of --scale ' . $scale . ' and --x-offset ' . $offset . '.');
    $lms = [];
    foreach (['l', 'm', 's'] as $p) {
        $v = $cell($p);
        if (!is_numeric($v)) fail('line ' . ($n + 2) . ': ' . strtoupper($p) . ' "' . $v . '" is not a number.');
        $lms[] = (float) $v;
    }
    if ($lms[1] <= 0 || $lms[2] <= 0) fail('line ' . ($n + 2) . ': M and S must be above 0.');
    if (isset($rows[$sex][$key])) fail('line ' . ($n + 2) . ': a second ' . $sex . ' row for x ' . $x . '.');
    $rows[$sex][$key] = $lms;
}

$first = null;
foreach (['male', 'female'] as $sex) {
    if (!$rows[$sex]) fail('no ' . $sex . ' rows.');
    ksort($rows[$sex]);
    $keys = array_keys($rows[$sex]);
    if ($keys !== range($keys[0], $keys[0] + count($keys) - 1)) fail('the ' . $sex . ' rows have a gap: every key from the first to the last is needed.');
    if ($first === null) $first = $keys[0];
    elseif ($first !== $keys[0]) fail('male rows start at key ' . $first . ', female rows at ' . $keys[0] . ': both sexes must start together.');
    $rows[$sex] = array_values($rows[$sex]);
}

if (function_exists('ini_set')) ini_set('serialize_precision', '-1');
$out = '{"format":"uv-lms-1","scale":' . $scale . ',"first":' . $first;
foreach (['male', 'female'] as $sex) {
    $out .= ',"' . $sex . '":[' . implode(',', array_map('json_encode', $rows[$sex])) . ']';
}
$out .= "}\n";
if (@file_put_contents($opts['out'], $out) === false) fail('cannot write ' . $opts['out'] . '.');

$last = function ($sex) use ($rows, $first, $scale, $offset) { return ($first + count($rows[$sex]) - 1) / $scale + $offset; };
fwrite(STDOUT, 'wrote ' . $opts['out'] . ': ' . count($rows['male']) . ' male and ' . count($rows['female']) . ' female rows, x '
    . ($first / $scale + $offset) . ' to ' . max($last('male'), $last('female')) . "\n");
fwrite(STDOUT, 'sha256 ' . hash('sha256', $out) . "\n");
// The starting entry covers the rows both sexes have. Without an offset a
// row is read for x near it ("round"); with one, a row keyed k is read for x
// from k up to the next key ("floor"), as CDC's half-month rows are.
$lastKey = $first + min(count($rows['male']), count($rows['female'])) - 1;
$valid = $offset == 0
    ? ['min' => (string) ($first / $scale), 'max' => (string) ($lastKey / $scale)]
    : ['min' => (string) ($first / $scale), 'below' => (string) (($lastKey + 1) / $scale)];
fwrite(STDOUT, "index.json entry to start from (edit title, measure, unit, axis, lookup, valid, adjust):\n");
fwrite(STDOUT, json_encode([
    'title' => '', 'source' => '', 'file' => basename($opts['out']), 'sha256' => hash('sha256', $out),
    'measure' => '', 'unit' => '', 'axis' => 'age', 'axisUnit' => 'days', 'lookup' => $offset == 0 ? 'round' : 'floor',
    'valid' => $valid, 'adjust' => 'none',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
