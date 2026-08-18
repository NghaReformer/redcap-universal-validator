<?php
/**
 * scan_store_php.php — the store contract, against the in-memory implementation.
 *
 * The SAME assertions in tests/scan_store_contract.php also run against
 * SqlScanStore on MySQL 5.7/8.0 and MariaDB 10.5/10.11 in tests/mysql/run.php.
 * Two independent implementations judged by one assertion set disagree wherever
 * the contract is ambiguous, which is where the bugs are - the technique this
 * repository already uses to keep the PHP and JavaScript engines from drifting.
 *
 * What passing HERE does not prove: the concurrency invariants. Single-process
 * PHP has no second connection, so "the engine refuses a second active run" is
 * this class checking an array. The evidence for that lives in the database
 * matrix, and this file cannot substitute for it.
 *
 * Run:  php tests/scan_store_php.php
 */

namespace {
    require_once __DIR__ . '/../php/Scan/ScanOutcome.php';
    require_once __DIR__ . '/../php/Scan/ScanPhase.php';
    require_once __DIR__ . '/../php/Scan/ScanStore.php';
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    require_once __DIR__ . '/scan_store_contract.php';

    \INSPIRE\UniversalValidator\Scan\storeContract(function () {
        return new \INSPIRE\UniversalValidator\Scan\ArrayScanStore(2);
    }, 'array-store');

    echo "scan_store_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
