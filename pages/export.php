<?php
/**
 * export.php — the CSV of one finished scan run, streamed from the stored run.
 *
 * THIN ON PURPOSE. Who may have the file, which rows it holds, how each cell
 * reads and what the file says about itself are decided in
 * Scan\ScanService::exportOpen() and streamFindings(), the same gate and the
 * same columns as the findings page. This page reads the run id, sends the
 * headers and hands the stream an echo and a flush.
 *
 * A REFUSAL IS NEVER A DOWNLOAD. It is plain text with no attachment header,
 * 403 when it is the answer and 503 when asking again may help, because a
 * browser must never save a file whose entire content is why there is no file.
 *
 * The previous exporter ran a complete scan of its own inside this request;
 * it is in the history at 1.8.8 and is not coming back. This one reads only
 * what a run already stored, a page at a time.
 *
 * A GET WITH NO TOKEN, AND WHAT THAT LEAVES (review rv11a1, observation (b)).
 * The external-module framework checks a `redcap_csrf_token` on POST requests
 * to module pages from framework version 8 (this module declares 14), and
 * $module->getCSRFToken() gives the token; it checks nothing on a GET, and
 * offers no token for one. The panel downloads this page through a link, so
 * a page on another site can send a signed-in user here by a top-level
 * navigation, which carries the session cookie under SameSite=Lax as well as
 * with none set. What that gets: the user's browser downloads a file the
 * user may already read, which the other site cannot see, and the server
 * streams one report. Every check in exportOpen() still applies; nothing is
 * written. REDCap's own SameSite setting was not confirmed. The residual
 * closes when the panel posts a form (run_id and redcap_csrf_token) in
 * place of the link, and this page then reads run_id from $_POST and refuses
 * a GET.
 */

namespace INSPIRE\UniversalValidator;

/** @var UniversalValidator $module */

$pid = (int) $module->getProjectId();
$runId = (isset($_GET['run_id']) && is_scalar($_GET['run_id'])) ? (int) $_GET['run_id'] : 0;

$export = ($pid > 0 && $runId > 0)
    ? (new Scan\ScanService($module))->exportOpen($pid, $runId)
    : ['ok' => false, 'http' => 403, 'why' => Scan\ScanService::NO_RUN];

if (empty($export['ok'])) {
    header('Content-Type: text/plain; charset=UTF-8', true, (int) $export['http']);
    header('Cache-Control: no-store');
    echo "# EXPORT REFUSED\n";
    foreach (explode("\n", wordwrap((string) $export['why'], 92)) as $line) {
        echo '# ' . $line . "\n";
    }
    echo "# No file was produced.\n";
    return;
}

// Whatever the framework buffered so far would otherwise reach the file ahead
// of its first line, and would hold every page until the end of the request.
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$export['stream'](
    function ($s) { echo $s; },
    function () { flush(); }
);
