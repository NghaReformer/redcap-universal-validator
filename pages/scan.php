<?php
/**
 * Validation scan — the project link, while the scan itself is withdrawn.
 *
 * WHAT THIS PAGE USED TO DO. It ran UniversalValidator::scanProject()
 * synchronously, inside one GET request, and rendered the whole result. That
 * design cannot keep its promise: it held every record id, every finding and
 * every unique candidate in one request's memory, so its cost grew with the
 * project rather than with the work; and because a GET started it, a refresh, a
 * second tab or the download button each launched another independent full pass
 * with nothing serialising them.
 *
 * WHAT IT DOES NOW. Nothing but say so. Task 1 of
 * reports/scan-rebuild-plan-2026-08-17.md requires the production synchronous
 * scan and the export-by-rerun control to be disabled, with an explicit notice,
 * until the durable worker exists. A scan that sometimes finishes is worse than
 * no scan, because the times it does teach people to trust the times it does not.
 *
 * The rights check still runs first and still refuses, because who may see this
 * page is not contingent on what the page currently offers — and because
 * ScanPageView::scanScope() is the same implementation the durable pages will
 * use, so it must not be allowed to rot while unused.
 *
 * Live form validation and the post-save audit are unaffected; neither goes
 * through here.
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

// READ FROM BOTH METHODS. The legacy controls were GET-only, so a POST carrying
// the same parameters would have missed a GET-only check and fallen through to
// a page that looks like it simply found nothing. Whatever method asks for a
// scan is answered the same way, and the answer never depends on the verb.
$asked = (isset($_GET['run']) && $_GET['run'] === '1')
      || (isset($_POST['run']) && $_POST['run'] === '1')
      || (isset($_GET['csv']) && $_GET['csv'] === '1')
      || (isset($_POST['csv']) && $_POST['csv'] === '1');
?>
<h4 style="margin-top:12px"><i class="fas fa-magnifying-glass"></i> Validation scan — Universal Field Validator</h4>

<?php
// The notice comes FIRST, above the description of what the feature is for.
// Explaining the feature and then revealing at the bottom that it does not run
// is how a reader ends up filing a scan they never got.
ScanPageView::unavailable($asked
    ? 'This link used to start a scan immediately. It no longer does, and nothing was run — '
      . 'no records were read and no report was produced.'
    : '');
?>

<p style="max-width:760px">
When it returns, this page runs <b>every configured rule</b> (check-character / format,
constraint, required, unique, choice filter) over <b>every saved record</b>, and reports each
violation with the instrument, the value, and what is wrong in plain words. That covers what
live form validation cannot: values imported through the Data Import Tool or the API, and
records entered before a rule existed.
<?php if ($dagFilter !== null) { ?>
<br><b>Your scope:</b> records in your Data Access Group only.
<?php } ?>
</p>

<p style="max-width:760px;color:#444">
<b>Still running, and unaffected by this:</b> live as-you-type validation on data-entry forms
and surveys, the save-time audit that records a violation after every write, and the
uniqueness check. Nothing about day-to-day data entry changes.
</p>
