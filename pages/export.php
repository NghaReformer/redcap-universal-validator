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
if ($scope['ok'] && empty($scope['mayExport'])) {
    // data_export_tool = 0 is REDCap saying No Access to the data export tool.
    // The ceiling downgraded what this file CONTAINED and nothing withheld the
    // file itself, so a user barred from REDCap's own exporter could still pull
    // a project-wide findings file from one URL. The on-screen report is
    // unaffected and still says everything their ceiling allows.
    $scope = ['ok' => false, 'dag' => null, 'valueCeiling' => 'locations', 'mayExport' => false,
              'why' => 'Your REDCap account has no access to the data export tool, so the scan '
                     . 'report cannot be downloaded. The scan page itself still works.'];
}
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

// Built BEFORE the scan, unconditionally. Building it lazily on the first
// finding left $dims null on a CLEAN project — the callback never fires, so
// nothing constructed it — and the metadata block below then fatalled on
// keyLegend(null). A clean project is the most common happy path and the one no
// test exported, so a 500 on "download my clean report" shipped unnoticed.
// Built before the scan for the header, then REBUILT from the scan's own
// rule snapshot below, so labels and ordinals come from one read.
$dims = $module->scanDimensions($pid);
$cols = ScanColumns::all($dims);
$rows = 0;
$sinkError = null;

$sink = new CallbackFindingSink(function (array $f) use ($dims, $cols, &$rows, $spool) {
    $row = ScanColumns::row($f, $dims, $cols);
    fwrite($spool, ScanPageView::csvRow(array_values($row)) . "\n");
    $rows++;
});

// A sink that throws would otherwise escape scanProject entirely: the page would
// have sent no headers, produced no file, and recorded nothing about the rows it
// had already written. Caught here so the file still arrives and SAYS it is short.
try {
    $result = $module->scanProject($pid, $scope['dag'], 200, $sink,
        ['valueCeiling' => $scope['valueCeiling'], 'enforceFormRights' => true]);
} catch (\Throwable $e) {
    $sinkError = get_class($e);
    $result = ['status' => 'failed', 'violations' => [], 'unconfigurable' => [],
               'incomplete' => ['the scan stopped on an error while writing the report: ' . get_class($e)],
               'stats' => ['records' => 0, 'contexts' => 0, 'rules' => 0, 'violations' => $rows]];
}

// The SAME predicate pages/scan.php uses. Rule problems do not affect status,
// so a project where every rule is a configuration error is 'complete' - and the
// export, which is the artefact people file and cite, was the weaker of the two.
// Re-derive the labels from the rule list the scan USED, so 'Rule 12' in a row
// and 'Rule 12' in the label table are the same rule.
if (!empty($result['rules'])) {
    $dims = $module->scanDimensions($pid, $result['rules']);
    $cols = ScanColumns::all($dims);
}
$complete = ($result['status'] === 'complete');
$fenced   = (isset($result['coverage']) && $result['coverage'] === 'complete-through-fence');
$clean    = $complete && $fenced && $result['stats']['violations'] === 0 && !$result['unconfigurable'];
$stamp    = date('Ymd_His');
$suffix   = $clean ? '' : ($complete ? '_NOT-CERTIFIED' : '_INCOMPLETE');
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
echo '# coverage: ' . (isset($result['coverage']) ? $result['coverage'] : 'partial') . "
";
foreach (array_slice(isset($result['limits']) ? $result['limits'] : [], 0, 10) as $lim) {
    echo '# limit: ' . str_replace(["
", "
"], ' ', $lim) . "
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
    // Four different reasons a file can have no finding rows, and they are not
    // the same claim. Only the first is a certification.
    echo ScanPageView::csvRow($pad([
        $clean      ? 'No violations found.'
      : (!$complete ? 'No violations among the records that could be read.'
      : ($result['unconfigurable']
            ? 'No violations found, but this project has rule problems listed below - some rules enforce nothing.'
            : 'No violations found in the records examined, but this server cannot prove the project did not '
              . 'change during the scan, so this is not a whole-project certification.'))])) . "\n";
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
