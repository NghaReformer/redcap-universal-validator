<?php
// Temporal probe for H17: does schemaPrivilege() spin forever when query()
// hands back an ARRAY rather than a mysqli_result?
require __DIR__ . '/../php/ScanCapabilities.php';
use INSPIRE\UniversalValidator\ScanCapabilities;

final class ArrayQueryModule {
    public function query($sql, array $p = []) {
        // Exactly what the is_array() branch of fetchRow() exists to serve.
        return [['GRANT SELECT, INSERT ON `redcap`.* TO `u`@`%`']];
    }
}
$t0 = microtime(true);
$r = ScanCapabilities::schemaPrivilege(new ArrayQueryModule());
printf("returned in %.2fs: %s\n", microtime(true) - $t0, json_encode($r));
