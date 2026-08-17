<?php
/**
 * export.php — the validation scan report as a real CSV.
 *
 * WHY THIS IS A SEPARATE PAGE. config.json declares pages/scan.php as a project
 * link with "show-header-and-footer": true, so the External Modules router emits
 * REDCap's entire page before that file runs. Its header() calls then fire after
 * output has begun and the browser ignores them — which is why the artefact
 * people filed was an HTML page with the data appended after ~1,089 lines of
 * markup. scan.php works around that by tearing the output buffer down; a page
 * that is NOT a declared link is never wrapped in the first place, so there is
 * nothing to tear down and no buffer state to get wrong.
 *
 * Because it is not a declared link it also never passes through
 * redcap_module_link_check_display, so it re-derives rights itself — through the
 * SAME ScanPageView::scanScope() that scan.php uses, never a second copy.
 *
 * Reached via $module->getUrl('pages/export.php').
 */

namespace INSPIRE\UniversalValidator;

/** @var UniversalValidator $module */

$pid = $module->getProjectId();
if (!$pid) return;

$scope = ScanPageView::scanScope($module, $pid);
if (!$scope['ok']) {
    // No attachment header: a browser must not save a file whose entire content
    // is an error, because a 0-finding CSV and a refused one look identical once
    // the reason is out of sight.
    header('Content-Type: text/plain; charset=UTF-8', true, 403);
    echo "# EXPORT REFUSED\n# " . $scope['why'] . "\n";
    return;
}

// NO set_time_limit(0) here. scanProject() derives its halt deadline from
// ini_get('max_execution_time'); lifting the limit sets that to 0, which makes
// the deadline null and silently disables the guard added in 1.6.4. The screen
// scan would then stop for time and report 'incomplete' while the export of
// "the same" scan ran on and reported 'complete' — two coverage claims, two
// answers, and no way for the reader to know which one they are holding.

// Rows are spooled, not accumulated. php://temp keeps a couple of megabytes in
// memory and spills the rest to a temp file, so the export costs the same on a
// project with four million findings as on one with four — while still letting
// the status banner be written FIRST, which it cannot be if the status is only
// known after the last row.
$spool = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'r+');
if ($spool === false) {
    header('Content-Type: text/plain; charset=UTF-8', true, 500);
    echo "# EXPORT FAILED\n# a temporary buffer could not be opened; nothing was written\n";
    return;
}

$dims = null;
$cols = null;
$rows = 0;

$sink = new CallbackFindingSink(function (array $f) use (&$dims, &$cols, &$rows, $spool, $module, $pid) {
    if ($dims === null) {
        // Built on the first finding, not before the scan: by now the scan has
        // already read the dictionary and the rules, so this is a memory read.
        $dims = $module->scanDimensions($pid);
        $cols = ScanColumns::all($dims);
        fwrite($spool, ScanPageView::csvRow(ScanColumns::headers($cols)) . "\n");
    }
    $row = ScanColumns::row($f, $dims, $cols);
    fwrite($spool, ScanPageView::csvRow(array_values($row)) . "\n");
    $rows++;
});

$result = $module->scanProject($pid, $scope['dag'], 200, $sink,
    ['valueCeiling' => $scope['valueCeiling']]);

$complete = ($result['status'] === 'complete');
$stamp    = date('Ymd_His');
$suffix   = $complete ? '' : '_INCOMPLETE';
$name     = 'validation_scan_pid' . $pid . '_' . $stamp . $suffix . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');

// A file that cannot certify the project says so in three independent places:
// this banner, a terminal row that survives sorting and deleting comment lines,
// and the filename itself, which survives being renamed and forwarded.
echo "\xEF\xBB\xBF";              // BOM: Excel reads UTF-8 as Latin-1 without it
if (!$complete) {
    echo "# INCOMPLETE SCAN - this file does NOT certify the project as clean\n";
    foreach (array_slice($result['incomplete'], 0, 50) as $why) {
        echo '# ' . str_replace(["\r", "\n"], ' ', $why) . "\n";
    }
    if (count($result['incomplete']) > 50) {
        echo '# ...and ' . (count($result['incomplete']) - 50) . " more\n";
    }
}
echo '# columns: ' . ScanColumns::keyLegend($cols) . "
";
if ($dims->isDegraded()) echo '# ' . $dims->degradedSummary() . "
";
if ($sinkError !== null) {
    echo "# ROWS WERE LOST - this file is not a complete report: " . $sinkError . "
";
}
echo '# scan of project ' . $pid . ' at ' . date('c')
   . ($scope['dag'] !== null ? ' | scope: Data Access Group "' . $scope['dag'] . '" ONLY' : ' | scope: whole project')
   . ' | records ' . $result['stats']['records']
   . ' | rules ' . $result['stats']['rules']
   . ' | findings ' . $result['stats']['violations']
   . "\n";

// Unconditionally, so a clean scan still produces a parseable file. Emitting
// it only when a finding existed meant the easiest files to handle were the
// ones a header-driven consumer broke on.
echo ScanPageView::csvRow(ScanColumns::headers($cols)) . "
";

rewind($spool);
while (!feof($spool)) {
    $buf = fread($spool, 65536);
    if ($buf === false) break;
    echo $buf;
}
fclose($spool);

// Every trailer row is padded to the finding-row width. Mixed arity in one file
// is not valid rectangular CSV, and these rows are shorter than a finding.
$pad = function (array $vals) use ($cols) {
    return array_pad($vals, count($cols), '');
};

if ($rows === 0) {
    echo ScanPageView::csvRow($pad([$complete
        ? 'No violations found.'
        : 'No violations among the records that could be read.'])) . "\n";
}

// Rule problems and unreadable records, after the findings, as data rows rather
// than comments — a reader who strips the # lines still sees them.
foreach ($result['unconfigurable'] as $u) {
    echo ScanPageView::csvRow($pad(['rule-problem', 'rule ' . $u['rule'],
        implode(' ', (array) $u['fields']), $u['why']])) . "\n";
}
foreach ($result['incomplete'] as $why) {
    echo ScanPageView::csvRow($pad(['not-scanned', '', '', $why])) . "\n";
}
if (!$complete) {
    echo ScanPageView::csvRow($pad(['INCOMPLETE', '', '',
        'This scan did not cover the whole project. Rows above are real findings over the records that WERE read; '
        . 'absence of a row is not evidence a record is clean.'])) . "\n";
}
