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
    require_once __DIR__ . '/../php/Scan/FindingRowMapper.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    require_once __DIR__ . '/scan_store_contract.php';
    require_once __DIR__ . '/scan_fault_support.php';

    \INSPIRE\UniversalValidator\Scan\storeContract(function () {
        return new \INSPIRE\UniversalValidator\Scan\ArrayScanStore();
    }, 'array-store', function ($store, $runId, $seconds) {
        // This store keeps a claim's time in claimed_at; see ageClaims().
        \INSPIRE\UniversalValidator\Scan\ageClaims($store, $runId, $seconds);
    }, function ($clock) {
        return new \INSPIRE\UniversalValidator\Scan\ArrayScanStore($clock);
    }, function ($store, $runId) {
        // The stand-in for DELETE FROM uv_scan_plan WHERE run_id = ?.
        $drop = function ($runId) { unset($this->plans[$runId]); };
        \Closure::bind($drop, $store, \INSPIRE\UniversalValidator\Scan\ArrayScanStore::class)($runId);
    });

    // THE STORED ROW, which only this store can show whole. findings() returns
    // FindingRowMapper's keys and valid_from_seq is not one of them, so the
    // contract cannot see the default; tests/mysql/cases/store.php compares the
    // two stores' rows column by column on a real server.
    {
        $s = new \INSPIRE\UniversalValidator\Scan\ArrayScanStore();
        $r = $s->startRun(760, ['created_by' => 'alice']);
        $rid = (int) $r['run']['run_id'];
        $s->writeManifest($rid, [['id_bin' => 'V1', 'hash' => hash('sha256', 'V1', true), 'dag' => null]]);
        $ep = (int) $s->run(760, $rid)['lease_epoch'];
        $c = $s->claim($rid, 'w1', $ep, 1);
        $s->commitBatch($rid, 'w1', $ep, ['bytes' => 0,
            'records' => [['ordinal' => $c[0]['ordinal'], 'record_hash' => $c[0]['hash'],
                           'state' => \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE,
                           'claim' => $c[0]['claim']]],
            'findings' => [['ordinal' => $c[0]['ordinal'], 'project_id' => 760,
                'generation_id' => (int) $r['run']['generation_id'],
                'identity' => hash('sha256', 'v', true), 'record_hash' => $c[0]['hash'],
                'record_id_bin' => 'V1', 'host_form' => 'fa', 'field' => 'x',
                'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64),
                'check_type' => 'required', 'reason_code' => 'required-blank']]]);
        $row = $s->allFindings()[0];
        check('array-store: a finding sent with no valid_from_seq opens at the run\'s own sequence',
            $row['valid_from_seq'] === (int) $s->run(760, $rid)['run_seq'] && $row['valid_from_seq'] > 0);
        check('array-store: and the stored row has every column the schema has, defaults filled',
            count($row) === 31 && $row['valid_to_seq'] === null && $row['active_slot'] === 1
            && $row['arm_id'] === null && $row['severity'] === 0 && $row['status_key'] === null
            && $row['value_binary'] === 0 && $row['stage_epoch'] === null);
    }

    echo "scan_store_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
