<?php
/**
 * export.php — the scan report download, while the scan itself is withdrawn.
 *
 * This page built its file by running a COMPLETE scan of its own. It was
 * therefore a second production entry point into the synchronous path, and the
 * one an automated job or a retried download is most likely to hold open — the
 * same whole-project read, started again, with nothing serialising it against
 * the copy the screen had already started.
 *
 * Task 1 of reports/scan-rebuild-plan-2026-08-17.md requires the export-by-rerun
 * control to be disabled with the scan. It refuses before rights are considered,
 * because there is no file for anyone to be entitled to.
 *
 * The previous body — spool to php://temp, then emit a metadata block, a header
 * row and the findings — is deliberately NOT retained here as unreachable code.
 * Task 7 specifies a different exporter: one authenticated streaming response
 * built from the STORED run, with expected-count metadata and a mandatory
 * `export_complete=1` trailer, so a truncated download is detectable by its own
 * contents. Keeping the old writer around would invite it to be re-enabled
 * instead of replaced. It is in the history at 1.8.8 if it is ever wanted.
 */

namespace INSPIRE\UniversalValidator;

/** @var UniversalValidator $module */

// 503, not 403: this is "not available yet", not "not permitted". A monitor
// retrying on 503 is behaving correctly; one retrying on 403 is misconfigured.
// Plain text with NO attachment header, because a browser must never save a
// file whose entire content is an explanation of why there is no file.
header('Content-Type: text/plain; charset=UTF-8', true, 503);
header('Cache-Control: no-store');

echo "# EXPORT UNAVAILABLE\n";
foreach (explode("\n", wordwrap(ScanPageView::UNAVAILABLE, 92)) as $line) {
    echo '# ' . $line . "\n";
}
echo "# No scan was run and no report was produced.\n";
