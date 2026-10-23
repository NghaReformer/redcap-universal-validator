<?php
/**
 * tools/build_growth_rows.php - write the rows script of each bundled growth
 * table (PRF-7).
 *
 * A form whose growth check may read any row of a table used to carry the
 * whole table, 46 to 92 KB of JSON, inline on every view. For a bundled
 * table the page now names a script instead (GrowthReference::pageIndex,
 * rowsPath), which the browser caches across forms and visits. This writes
 * those scripts: one per table file of data/references/index.json, named by
 * the first 16 hex digits of the file's SHA-256, filing the rows that
 * GrowthReference::table() reads under the full SHA-256 in
 * window.INSPIRE_UV_GROWTH_ROWS, where js/engine.js looks for them.
 *
 * Run it after adding or changing a bundled table, and commit js/growth/.
 * CI runs it with --check, which writes nothing and fails when a script is
 * missing, differs from what this would write, or belongs to no table.
 *
 * Usage: php tools/build_growth_rows.php [--check]
 */

require_once __DIR__ . '/../php/GrowthReference.php';

use INSPIRE\UniversalValidator\GrowthReference;

$check = in_array('--check', array_slice($argv, 1), true);
$root = dirname(__DIR__);
$dir = $root . '/js/growth';

$catalog = GrowthReference::catalog(null);
if ($catalog['problems']) {
    fwrite(STDERR, "the bundled references have problems:\n  " . implode("\n  ", $catalog['problems']) . "\n");
    exit(1);
}

$want = [];  // file name => script text
foreach ($catalog['references'] as $id => $entry) {
    $path = GrowthReference::rowsPath($entry['sha256']);
    if (isset($want[basename($path)])) continue;  // two entries may share one file
    $table = GrowthReference::table($entry);
    $rows = ['scale' => $table['scale'], 'first' => $table['first'], 'male' => $table['male'], 'female' => $table['female']];
    $json = json_encode($rows);
    if ($json === false) {
        fwrite(STDERR, 'the rows of ' . $entry['file'] . " cannot be written as JSON\n");
        exit(1);
    }
    $want[basename($path)] = "/* The rows of data/references/" . $entry['file'] . " for the growth checks on a page\n"
        . "   (PRF-7). js/engine.js reads them under the table's SHA-256. Written by\n"
        . "   tools/build_growth_rows.php; do not edit. */\n"
        . '(window.INSPIRE_UV_GROWTH_ROWS = window.INSPIRE_UV_GROWTH_ROWS || {})["' . $entry['sha256'] . '"] = ' . $json . ";\n";
}
ksort($want);

$have = [];
foreach (is_dir($dir) ? scandir($dir) : [] as $f) {
    if (substr($f, -3) === '.js') $have[$f] = file_get_contents($dir . '/' . $f);
}

if ($check) {
    $bad = [];
    foreach ($want as $f => $text) {
        if (!isset($have[$f])) $bad[] = 'js/growth/' . $f . ' is missing';
        elseif ($have[$f] !== $text) $bad[] = 'js/growth/' . $f . ' differs from its table';
    }
    foreach ($have as $f => $text) {
        if (!isset($want[$f])) $bad[] = 'js/growth/' . $f . ' belongs to no bundled table';
    }
    if ($bad) {
        fwrite(STDERR, implode("\n", $bad) . "\nRun php tools/build_growth_rows.php and commit js/growth/.\n");
        exit(1);
    }
    echo count($want) . " rows scripts match their tables\n";
    exit(0);
}

if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
    fwrite(STDERR, "cannot create js/growth\n");
    exit(1);
}
foreach ($have as $f => $text) {
    if (!isset($want[$f])) unlink($dir . '/' . $f);
}
foreach ($want as $f => $text) {
    if (!isset($have[$f]) || $have[$f] !== $text) file_put_contents($dir . '/' . $f, $text);
}
echo 'wrote ' . count($want) . " rows scripts to js/growth\n";
