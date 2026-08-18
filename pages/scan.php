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

// ORDER MATTERS. This block used to sit BELOW the scan, under a `$run || $csv`
// run condition, so the deprecated download route ran a complete scan, threw the
// result away unread, and redirected to a page that scanned the project again:
// twice the database load and twice the wall clock for a file that could only
// ever have come from the second run. The redirect needs nothing the scan
// produces, so it belongs above it.
if ($csv) {
    // DEPRECATED, and now a redirect rather than a second exporter.
    //
    // This route emitted a DIFFERENT schema from pages/export.php - unquoted
    // section/record/event_id columns, no value, no plain-language explanation,
    // no BOM and no _INCOMPLETE filename suffix - so two live formats answered
    // the same question differently and any consumer had to know which URL
    // produced its file. One exporter now, reached both ways.
    //
    // The buffer teardown stays because a Location: header is a header like any
    // other: the router has already emitted REDCap's whole page, so without
    // discarding it the redirect is ignored exactly as the CSV headers were.
    while (ob_get_level() > 0) {
        if (!@ob_end_clean()) break;
    }
    if (ob_get_level() > 0 || headers_sent()) {
        ScanPageView::refuse('This download link has moved. Use the Download CSV button on the '
            . 'scan page, which points at the current exporter.');
        return;
    }
    header('Location: ' . $module->getUrl('pages/export.php'), true, 302);
    return;
}

$result = null;
if ($run) {
    // enforceFormRights: this is a request made BY a user, so it can be scoped
    // to that user's per-instrument rights. scanProject() is also reachable with
    // no user context, which is why the scoping is something a caller asks for
    // rather than something scanPlan() assumes.
    $result = $module->scanProject($pid, $dagFilter, 200, null,
        ['valueCeiling' => $scope['valueCeiling'], 'enforceFormRights' => true]);
}

$complete = is_array($result) && isset($result['status']) && $result['status'] === 'complete';
// A clean bill of health needs all three: the scan finished, it found nothing,
// and no rule was left unevaluated. A project whose every rule is broken has
// zero violations and is not clean — the green tick belonged to the violation
// count alone, which is the narrower claim (M-02).
// COVERAGE is a separate axis from status. A run that read every record on its
// opening list, on a server where no change fence can be proved, is
// 'manifest-complete': it cannot know the project did not move underneath it,
// and per the rebuild plan that must never render as complete or clean.
$coverage = isset($result['coverage']) ? $result['coverage'] : 'partial';
$fenced   = ($coverage === 'complete-through-fence');
$clean    = $complete && $fenced && !$result['violations'] && !$result['unconfigurable'];

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
  <?php if (!empty($scope['mayExport'])) { ?>
  &nbsp;<a class="btn btn-defaultrc btn-xs" href="<?php echo ScanPageView::h($exportUrl); ?>">Download CSV</a>
  <?php } else { ?>
  <?php /* The export page refuses this reader anyway; offering the button would
           be an invitation to a 403. Say why here instead. */ ?>
  &nbsp;<span class="text-muted" style="font-size:11px">Download unavailable — your account has no
    access to REDCap's data export tool.</span>
  <?php } ?>
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
    $dims = $module->scanDimensions($pid, isset($result['rules']) ? $result['rules'] : null);
    $cols = ScanColumns::all($dims);
  ?>
  <?php if ($dims->isDegraded()) { ?>
  <?php /* A label source that could not be read falls back to the RAW key - an
           event id instead of a name - which on screen is indistinguishable
           from data. degraded[] recorded why from the start and nothing ever
           displayed it, so the fallback was invisible: the one outcome this
           module rejects everywhere else. */ ?>
  <p style="max-width:760px;color:#8a6d00"><b>&#9888; Some labels could not be read,
  so the columns below show raw identifiers rather than names:</b>
  <?php echo ScanPageView::h($dims->degradedSummary()); ?></p>
  <?php } ?>
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
<?php } elseif ($result['unconfigurable']) { ?>
<p style="color:#8a6d00"><b>&#9888; No violations found among the rules that could be evaluated
&mdash; but the rule problems above were not checked at all.</b></p>
<?php } elseif (!$fenced) { ?>
<?php /* Every record on the opening list was examined, and that is all this
         server can prove. Without a change fence the scan cannot know a record
         was not added or edited while it read, so "clean" is a claim it has not
         earned — however complete the sweep looked. */ ?>
<p style="color:#8a6d00"><b>&#9888; No violations found in the <?php echo (int) $result['stats']['records']; ?>
record(s) examined &mdash; but this server cannot prove the project did not change during the
scan, so the result is NOT a whole-project certification.</b></p>
<?php if (!empty($result['limits'])) { ?>
<ul style="color:#8a6d00;max-width:760px">
  <?php foreach (array_slice($result['limits'], 0, 6) as $lim) { ?>
  <li><?php echo ScanPageView::h($lim); ?></li>
  <?php } ?>
</ul>
<?php } ?>
<?php } else { ?>
<p style="color:#2e7d32"><b>&#10003; No violations found.</b></p>
<?php } ?>

<?php } ?>
