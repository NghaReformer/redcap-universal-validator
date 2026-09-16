<?php
namespace INSPIRE\UniversalValidator;
function boom() { throw new \RuntimeException('migration failed'); }
try {
    try { boom(); } catch (Throwable $e) { echo "CAUGHT (unqualified)\n"; }
    echo "no exception escaped\n";
} catch (\Throwable $e) {
    echo "ESCAPED: unqualified catch(Throwable) did NOT catch -> " . get_class($e) . "\n";
}
