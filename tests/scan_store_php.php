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
    require_once __DIR__ . '/support/ArrayScanStore.php';
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
            count($row) === 35 && $row['valid_to_seq'] === null && $row['active_slot'] === 1
            && $row['arm_id'] === null && $row['severity'] === 0 && $row['status_key'] === null
            && $row['value_binary'] === 0 && $row['stage_epoch'] === null
            && $row['as_of'] === null && $row['z_score'] === null && $row['growth_reference'] === null
            && $row['justified'] === 0);
    }

    // THE RUN COUNTS ITS STORED ROWS WITH A REASON (@UVJUSTIFY) apart, beside
    // detail_rows, so a working run can say them apart from its open findings
    // (ScanPromotion::findingsOf, justifiedOf). Only rows it stored count: a
    // finding of a record the batch does not hold adds nothing.
    // tests/mysql/cases/justified.php asks the SQL store the same.
    {
        $s = new \INSPIRE\UniversalValidator\Scan\ArrayScanStore();
        $r = $s->startRun(761, ['created_by' => 'alice']);
        $rid = (int) $r['run']['run_id'];
        $s->writeManifest($rid, [['id_bin' => 'J1', 'hash' => hash('sha256', 'J1', true), 'dag' => null],
                                 ['id_bin' => 'J2', 'hash' => hash('sha256', 'J2', true), 'dag' => null]]);
        $ep = (int) $s->run(761, $rid)['lease_epoch'];
        check('array-store: a new run has stored no row with a reason', (int) $s->run(761, $rid)['detail_justified'] === 0);
        $c = $s->claim($rid, 'w1', $ep, 2);
        $f = function (array $c, $field, array $over = []) use ($r) {
            return $over + ['ordinal' => $c['ordinal'], 'project_id' => 761, 'generation_id' => (int) $r['run']['generation_id'],
                'identity' => hash('sha256', 'j-' . $c['ordinal'] . '-' . $field, true), 'record_hash' => $c['hash'],
                'record_id_bin' => 'J', 'host_form' => 'fa', 'field' => $field, 'rule_source_id' => 'r1',
                'rule_revision' => str_repeat('c', 64), 'check_type' => 'range', 'reason_code' => 'hard-high'];
        };
        $rec = function (array $c) {
            return ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'], 'state' => \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE,
                    'claim' => $c['claim'], 'version' => '1'];
        };
        $s->commitBatch($rid, 'w1', $ep, ['bytes' => 0, 'records' => [$rec($c[0])],
            'findings' => [$f($c[0], 'a', ['justified' => 1]), $f($c[0], 'b'), $f($c[0], 'c', ['justified' => 0]),
                           $f($c[1], 'd', ['justified' => 1])]]);
        $run = $s->run(761, $rid);
        check('array-store: a batch counts its stored rows and, apart, the ones with a reason (got '
            . $run['detail_rows'] . ', ' . $run['detail_justified'] . ')',
            (int) $run['detail_rows'] === 3 && (int) $run['detail_justified'] === 1);
        $s->commitBatch($rid, 'w1', $ep, ['bytes' => 0, 'records' => [$rec($c[1])],
            'findings' => [$f($c[1], 'd', ['justified' => 1]), $f($c[1], 'e', ['justified' => 1])]]);
        $run = $s->run(761, $rid);
        check('array-store: and the next batch adds to both (got ' . $run['detail_rows'] . ', ' . $run['detail_justified'] . ')',
            (int) $run['detail_rows'] === 5 && (int) $run['detail_justified'] === 3);
    }

    // A growth finding's z-score, in this store's own row shape: stored with
    // its reference, and read back only beside its value, since it gives the
    // value back.
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
                'value_expires_at' => '2000-01-01 00:00:00'],
               ['ordinal' => $c['ordinal'], 'project_id' => 700, 'generation_id' => (int) $run['generation_id'],
                'host_form' => 'fa', 'field' => 'wt2', 'check_type' => 'range', 'reason_code' => 'soft-low',
                'record_id_bin' => 'R1', 'identity' => hash('sha256', 'wt2', true), 'valid_from_seq' => (int) $run['run_seq'],
                'record_hash' => $c['hash'], 'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64),
                'z_score' => '-1.00', 'growth_reference' => 'who-wfa']]]);
        $f = $s->findings(700, (int) $run['generation_id'], ['dag' => null], [], 0, 10);
        check('array-store: the z-score reads back beside its value only (got ' . json_encode($f) . ')',
            count($f) === 2 && $f[0]['z_score'] === '-3.40' && $f[1]['z_score'] === null && $f[1]['growth_reference'] === 'who-wfa');
        $kept = array_values(array_filter($s->allFindings(), function ($r) { return $r['field'] === 'wt2'; }));
        check('array-store: a z-score with no value beside it is stored, only not read back (got ' . json_encode($kept) . ')',
            count($kept) === 1 && $kept[0]['z_score'] === '-1.00' && $kept[0]['growth_reference'] === 'who-wfa');
    }

    // Value expiry. Neither store sweeps values any more: ScanRetention is the
    // one sweep (wave 8), and the rows it clears are asserted on a real server
    // in tests/mysql/cases/retention.php and store.php. Here, its statements:
    // the value, its fingerprint and a growth finding's z-score go together,
    // since each gives the value back; the finding and its reference stay.
    {
        require_once __DIR__ . '/../php/Scan/Schema.php';
        require_once __DIR__ . '/../php/Scan/ScanRetention.php';
        $db = new \INSPIRE\UniversalValidator\Scan\ScriptedDb();
        $db->rows = ['MAX(finding_id)' => [[10]]];
        (new \INSPIRE\UniversalValidator\Scan\ScanRetention($db))->expireValuesPage(0, 700, '2026-01-01 00:00:00');
        $sweep = array_values(array_filter($db->log, function ($q) { return strpos($q, 'SET value_bin = NULL') !== false; }));
        check('retention: the expiry sweep clears the z-score with the value and its fingerprint',
            count($sweep) === 1 && strpos($sweep[0], 'value_fingerprint = NULL') !== false
            && strpos($sweep[0], 'z_score = NULL') !== false && strpos($sweep[0], 'growth_reference') === false);
        // Every statement that clears a value clears its z-score, the revoke
        // path included (it needs a slice page to reach its UPDATE).
        $src = file_get_contents(__DIR__ . '/../php/Scan/ScanRetention.php');
        preg_match_all("/SET (?:f\\.)?value_bin = NULL[^']*/", $src, $m);
        $bare = array_filter($m[0], function ($q) { return strpos($q, 'z_score = NULL') === false; });
        check('retention: no statement clears a value and leaves its z-score (' . count($m[0]) . ' found)',
            count($m[0]) >= 2 && $bare === []);
    }

    echo "scan_store_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
