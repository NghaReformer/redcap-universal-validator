<?php
namespace INSPIRE\UniversalValidator;
if (PHP_SAPI !== 'cli') {
    // A test or a tool, never a page: answer a web request with 404 and stop.
    http_response_code(404);
    exit;
}

function boom() { throw new \RuntimeException('migration failed'); }
try {
    try { boom(); } catch (Throwable $e) { echo "CAUGHT (unqualified)\n"; }
    echo "no exception escaped\n";
} catch (\Throwable $e) {
    echo "ESCAPED: unqualified catch(Throwable) did NOT catch -> " . get_class($e) . "\n";
}
