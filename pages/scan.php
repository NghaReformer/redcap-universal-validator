<?php
/**
 * Validation scan — the project page.
 *
 * WHAT THIS PAGE USED TO DO. It ran UniversalValidator::scanProject()
 * synchronously, inside one GET request, and rendered the whole result. That
 * design cannot keep its promise: it held every record id, every finding and
 * every unique candidate in one request's memory, so its cost grew with the
 * project rather than with the work; and because a GET started it, a refresh, a
 * second tab or the download button each launched another independent full pass
 * with nothing serialising them.
 *
 * WHAT IT DOES NOW. It asks ScanService whether the durable scan is available
 * here, and shows one of two pages:
 *
 *   NOT AVAILABLE  the notice this page has carried since Task 1. Either an
 *                  administrator has not enabled the durable scan, or this
 *                  project has not, or the installation cannot support it. A
 *                  scan that sometimes finishes is worse than no scan, because
 *                  the times it does teach people to trust the times it does not.
 *
 *   AVAILABLE      a panel that starts, resumes, watches and stops a run. The
 *                  work happens in js/scan.js over the authenticated AJAX
 *                  actions; this page renders the state BEFORE any script runs,
 *                  so somebody with scripting disabled still sees whether their
 *                  scan is going rather than an empty box.
 *
 * NOTHING ON THIS PAGE DECIDES ANYTHING. Rights, scope, schema health and
 * capability are all answered by ScanService, which the AJAX actions call too -
 * so a page that showed a Start button could not thereby make a scan startable.
 *
 * Live form validation and the post-save audit are unaffected; neither goes
 * through here.
 */

namespace INSPIRE\UniversalValidator;

/** @var UniversalValidator $module */

$pid = $module->getProjectId();
if (!$pid) { echo 'This page only works inside a project.'; return; }

// -- rights: design rights, and DAG confinement ------------------------------
// One implementation, shared with pages/export.php and with every AJAX action.
// A second copy of a security decision is a second copy that ages differently
// from the first.
$scope = ScanPageView::scanScope($module, $pid);
if (!$scope['ok']) { ScanPageView::refuse($scope['why']); return; }
$dagFilter = $scope['dag'];

$svc = new Scan\ScanService($module);
$available = $svc->available($pid);
$activeRun = $available['ok'] ? $svc->activeRun($pid) : null;

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

<?php if (!$available['ok']) { ?>
<?php
    // The notice comes FIRST, above the description of what the feature is for.
    // Explaining the feature and then revealing at the bottom that it does not
    // run is how a reader ends up filing a scan they never got.
    ScanPageView::unavailable($asked
        ? 'This link used to start a scan immediately. It no longer does, and nothing was run — '
          . 'no records were read and no report was produced.'
        : '');
?>
<p style="max-width:760px;color:#444"><b>Why:</b> <?php echo ScanPageView::h($available['why']); ?>.</p>
<?php if (!empty($available['detail'])) { ?>
<p style="max-width:760px;color:#666;font-size:12px"><?php echo ScanPageView::h($available['detail']); ?></p>
<?php } ?>

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

<?php } else { ?>

<p style="max-width:760px">
This runs <b>every configured rule</b> over <b>every saved record</b> in bounded batches. It
keeps going across several requests, so a large project does not have to finish inside one
page load, and it survives closing this tab — come back and it resumes where it stopped.
<?php if ($dagFilter !== null) { ?>
<br><b>Your scope:</b> records in your Data Access Group only.
<?php } ?>
</p>

<div id="uv-scan-panel" style="max-width:760px;border:1px solid #ddd;border-radius:6px;padding:14px">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <button id="uv-scan-start" class="btn btn-primary btn-sm"<?php echo $activeRun ? ' style="display:none"' : ''; ?>>
      Start a scan
    </button>
    <button id="uv-scan-resume" class="btn btn-secondary btn-sm"<?php echo $activeRun ? '' : ' style="display:none"'; ?>>
      Continue
    </button>
    <button id="uv-scan-cancel" class="btn btn-outline-danger btn-sm" style="display:none">
      Stop
    </button>
    <span id="uv-scan-phase" style="font-weight:600"></span>
  </div>

  <div style="margin-top:10px;background:#eee;border-radius:3px;height:8px;overflow:hidden">
    <div id="uv-scan-bar" class="uv-bar" style="width:0;height:8px;background:#0a7"></div>
  </div>
  <div style="margin-top:6px;font-size:13px;color:#444">
    <span id="uv-scan-counts"></span> &nbsp; <span id="uv-scan-found"></span>
  </div>
  <p id="uv-scan-done" style="display:none;margin-top:10px;font-size:13px"></p>
  <p id="uv-scan-note" style="margin-top:8px;font-size:13px;color:#a30"></p>

  <noscript>
    <p style="color:#a30">This page needs JavaScript to run a scan. Nothing has been run.</p>
  </noscript>
</div>

<p style="max-width:760px;color:#444;margin-top:14px;font-size:13px">
<b>Reading the result.</b> "Every record was checked" and "every record on the opening list was
checked" are different sentences, and this page shows whichever is true. A scan that could not
read a record, or could not decide whether two values are duplicates, says so instead of
reporting a clean project.
</p>

<script src="<?php echo $module->getUrl('js/scan.js'); ?>"></script>
<script>
(function () {
    // The module's own AJAX transport. Given to the client rather than built by
    // it, so the page keeps the one route the framework authenticates and the
    // client never constructs a URL of its own.
    window.UVScan.ajax = function (action, payload) {
        return <?php echo $module->getJavascriptModuleObjectName(); ?>.ajax(action, payload);
    };
    window.UVScan.attach({
        runId: <?php echo $activeRun === null ? 'null' : (int) $activeRun; ?>,
        // A run already in progress is WATCHED, not resumed: this tab may have
        // been opened beside another that is driving it, and two drivers would
        // both be refused by the lease anyway. The Continue button is how a
        // person says they want this tab to take over.
        autoResume: false
    });
})();
</script>

<?php } ?>

<p style="max-width:760px;color:#444;margin-top:14px">
<?php // The wording differs by branch on purpose. When the scan is unavailable
      // the sentence has to reassure - somebody who just read that a feature is
      // off needs to know which features are not - and "Still running" is the
      // phrase that does that work. When the scan IS available there is nothing
      // to reassure about, and the same phrase would read as a warning. ?>
<b><?php echo $available['ok'] ? 'Unaffected by any of this:' : 'Still running, and unaffected by this:'; ?></b>
live as-you-type validation on data-entry forms and surveys, the save-time audit that records
a violation after every write, and the uniqueness check. Nothing about day-to-day data entry
changes.
</p>
