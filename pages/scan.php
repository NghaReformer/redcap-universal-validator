<?php
/**
 * Validation scan — run every configured rule over every saved record.
 *
 * The retrospective sweep the per-save audit cannot give you: legacy data,
 * Data Import Tool and API writes (save-hook coverage is version-dependent),
 * and records entered before a rule existed. Read-only: this page never
 * writes data, so it runs on GET.
 *
 * Access: design rights required (re-checked here; the sidebar link is
 * already limited by redcap_module_link_check_display). A user working
 * inside a Data Access Group only ever sees their own group's records.
 * Values are shown or withheld per the scan-value-storage project setting; see
 * UniversalValidator::mustRedact(), which fails CLOSED. Before 1.8.0 the report
 * named where the
 * problem is (record / event / instance / field / reason); the value itself
 * stays behind REDCap's own access control on the record pages.
 *
 * All logic lives in UniversalValidator::scanProject() (unit-tested via
 * tests/hook_php.php); this file is presentation only.
 */

namespace INSPIRE\UniversalValidator;

/** @var UniversalValidator $module */

$pid = $module->getProjectId();
if (!$pid) { echo 'This page only works inside a project.'; return; }

// -- rights: design rights, and DAG confinement ------------------------------
// One implementation, shared with pages/export.php. A second copy of a security
// decision is a second copy that ages differently from the first.
$scope = ScanPageView::scanScope($module, $pid);
if (!$scope['ok']) { ScanPageView::refuse($scope['why']); return; }
$dagFilter = $scope['dag'];

$run = isset($_GET['run']) && $_GET['run'] === '1';
$csv = isset($_GET['csv']) && $_GET['csv'] === '1';

// The escaping and CSV-quoting helpers were namespace-level functions declared
// here. A bare function declaration cannot be guarded, so including this page
// twice in one process was a fatal redeclare — one reason it has never had a
// test. They are now ScanPageView::h() and ScanPageView::csv()
// (php/ScanPageView.php, require_once'd from the module), so the page can be
// included as often as a test needs.

$result = null;
if ($run || $csv) {
    $result = $module->scanProject($pid, $dagFilter, 200, null,
        ['valueCeiling' => $scope['valueCeiling']]);
}

$complete = is_array($result) && isset($result['status']) && $result['status'] === 'complete';
// A clean bill of health needs all three: the scan finished, it found nothing,
// and no rule was left unevaluated. A project whose every rule is broken has
// zero violations and is not clean — the green tick belonged to the violation
// count alone, which is the narrower claim (M-02).
$clean = $complete && !$result['violations'] && !$result['unconfigurable'];

if ($csv) {
    // config.json declares "show-header-and-footer": true for this page, so the
    // External Modules router has ALREADY emitted REDCap's entire page — doctype,
    // nav, script bundles — before a line of this file runs. The two header()
    // calls below were therefore ignored, and the artefact people filed was a
    // 3,000-line HTML page with the data appended after line ~1,089. Discard the
    // buffered chrome first.
    //
    // ob_end_CLEAN, never ob_end_flush: flushing would push the chrome out AHEAD
    // of the rows and corrupt the file a different way.
    while (ob_get_level() > 0) {
        if (!@ob_end_clean()) break;    // a buffer that refuses to be deleted
    }
    // Two ways the chrome can still be there, and BOTH must stop the download.
    // A buffer that refused to be deleted (ob_start() with a callback, or one
    // opened without the erasable flag) still holds everything REDCap wrote, and
    // the rows below would simply be appended to it — the same HTML-plus-CSV
    // hybrid this release exists to remove, only now silently. And if the chrome
    // was already flushed to the client there is nothing left to salvage at all.
    // Say so rather than hand over a file that looks like a report and is not one.
    if (ob_get_level() > 0 || headers_sent()) {
        ScanPageView::refuse('The report could not be sent as a file because this page had already '
            . 'started sending output. Re-run the scan and use Download CSV again; if it keeps '
            . 'happening, report it — the on-screen table above is still accurate.');
        return;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="validation_scan_pid' . (int) $pid . '_' . date('Ymd_His') . '.csv"');
    // Streamed a row at a time. This used to build one array of formatted lines
    // and then implode it into a single contiguous string, so the whole report
    // existed three times over — as findings, as lines, and as one blob — before
    // a byte was written. Memory here is now flat in the number of findings.
    $fh = fopen('php://output', 'w');
    // The CSV is the artefact people file and cite, so an incomplete pass has to
    // say so IN the file. A downloaded "0 violations" from a scan that could not
    // read half the project would otherwise circulate as a clean result.
    if (!$complete) {
        fwrite($fh, "# INCOMPLETE SCAN - this file does NOT certify the project as clean\n");
        foreach (array_slice($result['incomplete'], 0, 50) as $why) {
            fwrite($fh, '# ' . str_replace(["\r", "\n", ','], ' ', $why) . "\n");
        }
    }
    // Three sections, because a violation is not the only finding a scan
    // produces. Exporting violations alone made a rule that could not be
    // evaluated at all — the one an auditor most needs to see — visible only on
    // screen, and invisible in the file that gets filed (M-02).
    fwrite($fh, "section,record,event_id,instance,field,rule,type,reason\n");
    foreach ($result['violations'] as $v) {
        fwrite($fh, implode(',', [ScanPageView::csv('violation'), ScanPageView::csv($v['record']), ScanPageView::csv($v['event_id']), ScanPageView::csv($v['instance']),
            ScanPageView::csv($v['field']), ScanPageView::csv($v['rule']), ScanPageView::csv($v['type']), ScanPageView::csv($v['reason'])]) . "\n");
    }
    foreach ($result['unconfigurable'] as $u) {
        fwrite($fh, implode(',', [ScanPageView::csv('rule-problem'), ScanPageView::csv(''), ScanPageView::csv(''), ScanPageView::csv(''),
            ScanPageView::csv(implode(' ', $u['fields'])), ScanPageView::csv($u['rule']), ScanPageView::csv('unconfigurable'), ScanPageView::csv($u['why'])]) . "\n");
    }
    foreach ($result['incomplete'] as $why) {
        fwrite($fh, implode(',', [ScanPageView::csv('not-scanned'), ScanPageView::csv(''), ScanPageView::csv(''), ScanPageView::csv(''),
            ScanPageView::csv(''), ScanPageView::csv(''), ScanPageView::csv($result['status']), ScanPageView::csv($why)]) . "\n");
    }
    fclose($fh);
    exit;
}

$self = $module->getUrl('pages/scan.php');
// The download goes to a page that is NOT a declared project link, so the
// router never wraps it in REDCap's chrome and its headers fire with nothing
// buffered — no buffer teardown, and no way for that teardown to go wrong.
// The &csv=1 route on THIS page still works and is still tested; it is
// deprecated and no longer linked, and it goes once export.php has been
// exercised on a live server.
$exportUrl = $module->getUrl('pages/export.php');
?>
<h4 style="margin-top:12px"><i class="fas fa-magnifying-glass"></i> Validation scan — Universal Field Validator</h4>
<p style="max-width:760px">
Runs <b>every configured rule</b> (check-character / format, constraint, required, unique,
choice filter) over <b>every saved record</b> and lists each violation. This covers what live
form validation cannot: values imported through the Data Import Tool or the API,
and records entered before a rule existed. The report shows <i>where</i> each
problem is, and — depending on this project's <b>Validation scan report</b>
setting — the offending value beside it.
<?php if ($dagFilter !== null) { ?>
<br><b>Scope:</b> records in your Data Access Group only.
<?php } ?>
</p>

<?php if (!$run) { ?>
<p>
  <a class="btn btn-primary btn-sm" href="<?php echo ScanPageView::h($self . '&run=1'); ?>">Run the scan now</a>
</p>
<p style="color:#666;max-width:700px;font-size:12px">Records are read in chunks; on a very large
project the scan may take a while — leave the page open until the table appears.</p>
<?php } else { ?>

<p>
  Scanned <b><?php echo (int) $result['stats']['records']; ?></b> record(s),
  <b><?php echo (int) $result['stats']['contexts']; ?></b> row(s), against
  <b><?php echo (int) $result['stats']['rules']; ?></b> rule(s) —
  <?php /* Green is a claim about the PROJECT, not about the violation count. A
           scan that could not read everything has not earned it — colouring an
           incomplete pass green put the reassuring number first and the caveat
           below the fold (M-02). */ ?>
  <b style="color:<?php echo ($clean ? '#2e7d32' : '#c62828'); ?>">
    <?php echo count($result['violations']); ?> violation(s)</b><?php echo $complete ? '' : ' so far'; ?>.
  <?php /* Every executed scan offers its evidence file, not only the ones that
           found something: "0 violations, incomplete, and here is why" is a
           result worth filing. */ ?>
  &nbsp;<a class="btn btn-defaultrc btn-xs" href="<?php echo ScanPageView::h($exportUrl); ?>">Download CSV</a>
  &nbsp;<a class="btn btn-defaultrc btn-xs" href="<?php echo ScanPageView::h($self . '&run=1'); ?>">Re-run</a>
</p>

<?php if (!$complete) { ?>
<div style="margin:8px 0;padding:8px 12px;border:1px solid #d9a441;background:#fdf6e3;color:#7a5c00;border-radius:4px;max-width:760px">
  <b>&#9888; This scan did not cover the whole project.</b>
  <p style="margin:4px 0 0">Treat the result below as partial. Re-run the scan; if it keeps
  failing, the records it could not read are still unchecked.</p>
  <?php if (!empty($result['incomplete'])) { ?>
  <ul style="margin:4px 0 0 18px">
    <?php foreach (array_slice($result['incomplete'], 0, 20) as $why) { ?>
      <li><?php echo ScanPageView::h($why); ?></li>
    <?php } ?>
    <?php if (count($result['incomplete']) > 20) { ?>
      <li>&hellip; and <?php echo (int) (count($result['incomplete']) - 20); ?> more</li>
    <?php } ?>
  </ul>
  <?php } ?>
</div>
<?php } ?>

<?php if ($result['unconfigurable']) { ?>
<div style="margin:8px 0;padding:8px 12px;border:1px solid #e0b4b0;background:#fbeceb;color:#c62828;border-radius:4px;max-width:760px">
  <b>&#9888; Rule problems (these rules could not be fully evaluated):</b>
  <ul style="margin:4px 0 0 18px">
  <?php foreach ($result['unconfigurable'] as $u) { ?>
    <li>Rule <?php echo (int) $u['rule']; ?> (<?php echo ScanPageView::h(implode(', ', $u['fields'])); ?>):
        <?php echo ScanPageView::h($u['why']); ?></li>
  <?php } ?>
  </ul>
</div>
<?php } ?>

<?php if ($result['violations']) {
    /* The table is capped, the COUNT above it never is. One violation per row is
       fine at 39 records and is ~25 MB of markup and 1.5M DOM nodes at 4,000 —
       enough to lock the browser tab on the report the operator came to read. The
       cap governs what is RENDERED only; the count line above, the green/red
       colour, and the CSV all still speak for the whole result, so a truncated
       view can never read as a smaller problem than it is. */
    $shown = array_slice($result['violations'], 0, ScanPageView::TABLE_MAX);
    $hidden = count($result['violations']) - count($shown);
?>
<table class="table table-striped table-sm" style="max-width:900px">
  <?php
    // The SAME descriptor list the export uses. One declaration of what a report
    // shows, so the screen and the file can never disagree about it, and adding
    // a column never means editing this markup.
    $dims = $module->scanDimensions($pid);
    $cols = ScanColumns::all($dims);
  ?>
  <thead><tr>
    <?php foreach ($cols as $c) { ?><th><?php echo ScanPageView::h($c['label']); ?></th><?php } ?>
  </tr></thead>
  <tbody>
  <?php foreach ($shown as $v) { $row = ScanColumns::row($v, $dims, $cols); ?>
    <tr>
      <?php foreach ($cols as $c) { ?><td><?php echo ScanPageView::h($row[$c['key']]); ?></td><?php } ?>
    </tr>
  <?php } ?>
  </tbody>
</table>
<?php if ($hidden > 0) { ?>
<p style="color:#c62828;max-width:900px"><b>&#9888; Showing the first
<?php echo count($shown); ?> of <?php echo count($result['violations']); ?> violation(s).</b>
<?php echo (int) $hidden; ?> more are not displayed — download the CSV for the complete report.</p>
<?php } ?>
<?php } elseif (!$complete) { ?>
<?php /* A scan that could not read everything must never read as a clean bill of
         health: "no violations" from an incomplete pass is an assurance the scan
         did not earn. */ ?>
<p style="color:#8a6d00"><b>&#9888; No violations found in the part of the project that could be
read &mdash; but this scan did not complete, so the project is NOT certified clean.</b></p>
<?php } elseif (!$clean) { ?>
<p style="color:#8a6d00"><b>&#9888; No violations found among the rules that could be evaluated
&mdash; but the rule problems above were not checked at all.</b></p>
<?php } else { ?>
<p style="color:#2e7d32"><b>&#10003; No violations found.</b></p>
<?php } ?>

<?php } ?>
