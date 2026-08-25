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

// THE STATE, READ HERE RATHER THAN PROMISED HERE.
//
// This file has said since it was written that it "renders the state BEFORE any
// script runs, so somebody with scripting disabled still sees whether their scan
// is going rather than an empty box". It did not. Every value span was emitted
// empty, the bar was hardcoded to zero, and the noscript block said "Nothing has
// been run" over a run that was on the server at that moment. One extra read on
// a page that has already looked up the run id is what the sentence costs.
//
// A status that cannot be read is not fatal and is not hidden: the panel falls
// back to the empty shape and the client fills it in on its first poll.
$activeStatus = null;
if ($activeRun !== null) {
    try {
        $st = $svc->status($pid, $activeRun);
        if (is_array($st) && !empty($st['ok'])) $activeStatus = $st;
    } catch (\Throwable $e) {
        $activeStatus = null;
    }
}
$prefill = ScanPageView::panelPrefill($activeStatus);

// THE TRANSPORT, resolved BEFORE the page decides what to offer.
//
// The panel is useless without the framework's JavaScript module object: every
// button on it is one AJAX call. The live-validation path has always
// initialised it properly and this page did not, so the first real pilot loaded
// a panel whose Start button threw "Cannot read properties of undefined" - the
// namespace exists only once initializeJavascriptModuleObject() has run, and
// nothing here had run it. The mocked page test supplied UVScan.ajax directly,
// so it never exercised the bootstrap at all.
//
// Resolved here rather than inside the markup so that a build without the
// transport shows the unavailable notice instead of a control that cannot work.
$jsmo = null;
$jsmoWhy = null;
if ($available['ok']) {
    try {
        if (!is_callable([$module, 'initializeJavascriptModuleObject'])) {
            $jsmoWhy = 'this REDCap build does not expose the module JavaScript transport';
        } else {
            $boot = $module->initializeJavascriptModuleObject();
            $name = is_callable([$module, 'getJavascriptModuleObjectName'])
                  ? $module->getJavascriptModuleObjectName() : null;
            if (!is_string($name) || $name === '') {
                $jsmoWhy = 'the framework started no JavaScript transport for this module '
                         . '(it returned no module object name)';
            } elseif (!ScanPageView::isJsIdentifierPath($name)) {
                // A name this page will not print. Not because it is expected to
                // be hostile - it comes from the framework, which derives it from
                // the module's installed directory - but because anything that is
                // not a dotted identifier becomes a syntax error inside the
                // <script> block below, and a panel that fails with a syntax
                // error explains nothing to whoever has to fix it.
                $jsmoWhy = 'the framework returned a JavaScript transport name this page '
                         . 'cannot use';
            } else {
                // Older builds echo the bootstrap and return null; newer ones
                // hand back the markup. Both are supported, exactly as the
                // data-entry path supports them.
                $jsmo = ['boot' => (is_string($boot) ? $boot : ''), 'name' => $name];
            }
        }
    } catch (\Throwable $e) {
        $jsmoWhy = 'the framework threw ' . get_class($e)
                 . ' while starting the JavaScript transport';
    }
    if ($jsmo === null) {
        // A panel nobody can drive is worse than an explanation.
        $available = ['ok' => false,
                      'why' => 'the scan cannot be driven from this page',
                      'detail' => $jsmoWhy];
        $activeRun = null;
    }
}

// READ FROM BOTH METHODS. The legacy controls were GET-only, so a POST carrying
// the same parameters would have missed a GET-only check and fallen through to
// a page that looks like it simply found nothing. Whatever method asks for a
// scan is answered the same way, and the answer never depends on the verb.
$asked = (isset($_GET['run']) && $_GET['run'] === '1')
      || (isset($_POST['run']) && $_POST['run'] === '1')
      || (isset($_GET['csv']) && $_GET['csv'] === '1')
      || (isset($_POST['csv']) && $_POST['csv'] === '1');
?>
<h4 id="uv-scan-heading" style="margin-top:12px"><i class="fas fa-magnifying-glass"></i> Validation scan — Universal Field Validator</h4>

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

<?php
// A bar with no total is INDETERMINATE, never zero and never full. Zero for the
// length of a planning phase reads as a scan that has stalled, and people stop
// scans that look stalled; full reads as a scan that has FINISHED, which is this
// module's founding complaint reproduced in a progress bar. The class the client
// sets for that state was never defined anywhere - there is no stylesheet in
// this module - so the "indeterminate" bar rendered as a solid full-width green
// one. It is defined here, beside the only markup that uses it.
//
// The reduced-motion branch is not optional. A perpetually sliding bar is a
// vestibular trigger, and the module commits to WCAG 2.2 elsewhere. Under
// reduced motion the bar sits at a partial width, which still reads as "working,
// amount unknown" rather than "finished".
$barPct = ($activeStatus === null) ? 0 : $prefill['pct'];
?>
<style>
#uv-scan-panel .uv-bar { transition: width .25s linear; }
#uv-scan-panel .uv-bar-indeterminate {
  width: 35%;
  background-image: repeating-linear-gradient(45deg, rgba(255,255,255,.45) 0 8px, rgba(255,255,255,0) 8px 16px);
  animation: uv-bar-slide 1.1s linear infinite;
}
@keyframes uv-bar-slide { from { transform: translateX(-120%); } to { transform: translateX(320%); } }
@media (prefers-reduced-motion: reduce) {
  #uv-scan-panel .uv-bar-indeterminate { animation: none; }
}
</style>
<div id="uv-scan-panel" role="region" aria-labelledby="uv-scan-heading"
     aria-busy="<?php echo $prefill['active'] ? 'true' : 'false'; ?>"
     style="max-width:760px;border:1px solid #ddd;border-radius:6px;padding:14px">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <?php // type="button" on every one of them: these sit inside REDCap's project
          // form, and a control that reaches its default action submits the page
          // out from under a running scan. ?>
    <button type="button" id="uv-scan-start" class="btn btn-primary btn-sm"<?php echo $activeRun ? ' style="display:none"' : ''; ?>>
      Start a scan
    </button>
    <button type="button" id="uv-scan-resume" class="btn btn-secondary btn-sm"<?php echo $activeRun ? '' : ' style="display:none"'; ?>>
      Continue
    </button>
    <button type="button" id="uv-scan-cancel" class="btn btn-outline-danger btn-sm" style="display:none">
      Stop
    </button>
    <span id="uv-scan-phase" style="font-weight:600"><?php echo ScanPageView::h($prefill['phase']); ?></span>
  </div>

  <?php // The TRACK carries the semantics and the fill stays presentational. An
        // ABSENT aria-valuenow is what ARIA means by an indeterminate progress
        // bar, which is exactly the state a run with no total yet is in - so it
        // is omitted rather than set to a number that would be a lie. ?>
  <div id="uv-scan-bar-track" role="progressbar" aria-label="Validation scan progress"
       aria-valuemin="0" aria-valuemax="100"<?php echo $barPct === null ? '' : ' aria-valuenow="' . (int) $barPct . '"'; ?>
       style="margin-top:10px;background:#eee;border-radius:3px;height:8px;overflow:hidden">
    <div id="uv-scan-bar" class="uv-bar<?php echo $barPct === null ? ' uv-bar-indeterminate' : ''; ?>" aria-hidden="true"
         style="<?php echo $barPct === null ? '' : 'width:' . (int) $barPct . '%;'; ?>height:8px;background:#0a7"></div>
  </div>
  <div style="margin-top:6px;font-size:13px;color:#444">
    <span id="uv-scan-counts"><?php echo ScanPageView::h($prefill['counts']); ?></span> &nbsp;
    <span id="uv-scan-found"><?php echo ScanPageView::h($prefill['found']); ?></span>
  </div>
  <?php // One atomic live region for the whole panel, written on a change rather
        // than on every poll. The visible spans above are deliberately NOT live:
        // a poll lands every few seconds, and announcing each one makes the page
        // unusable with a screen reader. This is the shape js/engine.js already
        // uses for a field's verdict. ?>
  <span id="uv-scan-announce" role="status" aria-live="polite" aria-atomic="true"
        style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap"></span>
  <p id="uv-scan-done" role="status" aria-live="polite" aria-atomic="true"
     style="<?php echo $prefill['done'] === null ? 'display:none;' : ''; ?>margin-top:10px;font-size:13px"><?php
     echo $prefill['done'] === null ? '' : ScanPageView::h($prefill['done']); ?></p>
  <p id="uv-scan-note" role="status" aria-live="polite" aria-atomic="true"
     style="margin-top:8px;font-size:13px;color:#a30"></p>

  <noscript>
<?php if ($activeRun === null) { ?>
    <p style="color:#a30">This page needs JavaScript to start a scan. Nothing has been run.</p>
<?php } else { ?>
    <p style="color:#a30">A validation scan for this project is on the server right now, and the
    figures above are how far it had got when this page was loaded. This page needs JavaScript to
    follow it or to stop it; the scan itself is unaffected and continues either way.</p>
<?php } ?>
  </noscript>
</div>

<p style="max-width:760px;color:#444;margin-top:14px;font-size:13px">
<b>Reading the result.</b> "Every record was checked" and "every record on the opening list was
checked" are different sentences, and this page shows whichever is true. A scan that could not
read a record, or could not decide whether two values are duplicates, says so instead of
reporting a clean project.
</p>

<?php echo $jsmo['boot']; ?>
<script src="<?php echo $module->getUrl('js/scan.js'); ?>"></script>
<script>
(function () {
    // The module's own AJAX transport. Given to the client rather than built by
    // it, so the page keeps the one route the framework authenticates and the
    // client never constructs a URL of its own. The object name comes from the
    // framework and the bootstrap above is what creates it - printing the name
    // without the bootstrap is what broke the first pilot.
    // The vocabulary, printed BEFORE attach() so nothing renders without it.
    // The phase labels and the coverage sentences were literals in js/scan.js as
    // well as here, which is two copies of one vocabulary with no way of
    // learning about each other; there is now one table, in ScanPageView, and
    // the client holds none. The JSON_HEX_* flags are what make json_encode safe
    // inside a <script> block, even for values that are module constants.
    window.UVScan.labels = <?php echo json_encode(ScanPageView::labels(),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.UVScan.ajax = function (action, payload) {
        // Printed unescaped ON PURPOSE, and safe because it was PROVED to be a
        // dotted identifier above (ScanPageView::isJsIdentifierPath) - escaping
        // an expression would leave an expression that no longer evaluates.
        return <?php echo $jsmo['name']; ?>.ajax(action, payload);
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
