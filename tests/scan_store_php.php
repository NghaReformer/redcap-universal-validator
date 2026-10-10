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
        return new \INSPIRE\UniversalValidator\Scan\ArrayScanStore();
    }, 'array-store');

    // Value expiry, in this store's own row shape (SqlScanStore's is asserted in
    // tests/mysql/cases/store.php): the value, its fingerprint and a growth
    // finding's z-score go, since each gives the value back; the finding and
    // its reference stay.
    {
        $s = new \INSPIRE\UniversalValidator\Scan\ArrayScanStore();
        $st = $s->startRun(700, ['created_by' => 'alice']);
        $rid = (int) $st['run']['run_id'];
        $s->writeManifest($rid, [['id_bin' => 'R1', 'hash' => hash('sha256', 'R1', true), 'dag' => null]]);
        $run = $s->run(700, $rid);
        $claim = $s->claim($rid, 'w1', (int) $run['lease_epoch'], 1);
        $c = $claim[0];
        $s->commitBatch($rid, 'w1', (int) $run['lease_epoch'], ['bytes' => 10,
            'records' => [['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                           'state' => \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE, 'claim' => $c['claim'], 'version' => '1']],
            'findings' => [['ordinal' => $c['ordinal'], 'project_id' => 700, 'generation_id' => (int) $run['generation_id'],
                'host_form' => 'fa', 'field' => 'wt', 'check_type' => 'range', 'reason_code' => 'soft-low',
                'record_id_bin' => 'R1', 'identity' => hash('sha256', 'wt', true), 'valid_from_seq' => (int) $run['run_seq'],
                'record_hash' => $c['hash'], 'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64),
                'value_bin' => '6.6', 'value_fingerprint' => 'fp', 'z_score' => '-3.40', 'growth_reference' => 'who-wfa',
                'value_expires_at' => '2000-01-01 00:00:00']]]);
        check('array-store: an overdue value expires', $s->expireValues('2026-01-01 00:00:00') === 1);
        $f = $s->findings(700, (int) $run['generation_id'], [], 0, 10);
        check('array-store: the value, its fingerprint and the z-score go; the finding and its reference stay (got ' . json_encode($f) . ')',
            count($f) === 1 && $f[0]['value_bin'] === null && $f[0]['value_fingerprint'] === null
            && $f[0]['z_score'] === null && $f[0]['growth_reference'] === 'who-wfa');
    }

    echo "scan_store_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
