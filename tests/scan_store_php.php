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
    }, function ($store, $runId, array $cols) {
        // The stand-in for UPDATE uv_scan_run SET ... WHERE run_id = ?.
        $set = function ($runId, array $cols) {
            foreach ($cols as $k => $v) $this->runs[$runId][$k] = $v;
        };
        \Closure::bind($set, $store, \INSPIRE\UniversalValidator\Scan\ArrayScanStore::class)($runId, $cols);
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
            count($row) === 34 && $row['valid_to_seq'] === null && $row['active_slot'] === 1
            && $row['arm_id'] === null && $row['severity'] === 0 && $row['status_key'] === null
            && $row['value_binary'] === 0 && $row['stage_epoch'] === null
            && $row['as_of'] === null && $row['z_score'] === null && $row['growth_reference'] === null);
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

    // rvf1-3: A RUN THAT BEGAN BEFORE SCHEMA VERSION 6 has open_unstored
    // NULL, and keeps it: its count was not kept from its start, so neither
    // a commit nor a departure may start one. The SQL store's half is the
    // schema case's upgrade on a real server.
    {
        $old = new \INSPIRE\UniversalValidator\Scan\ArrayScanStore();
        $orid = (int) $old->startRun(748, ['created_by' => 'alice', 'open_unstored' => null,
                                           'open_findings' => null])['run']['run_id'];
        $old->writeManifest($orid, [['id_bin' => 'O1', 'hash' => hash('sha256', 'O1', true), 'dag' => null]]);
        $orow = $old->run(748, $orid);
        $oep = (int) $orow['lease_epoch'];
        $oc = $old->claim($orid, 'w1', $oep, 1);
        $old->commitBatch($orid, 'w1', $oep, ['bytes' => 0,
            'findings' => [['ordinal' => $oc[0]['ordinal'], 'project_id' => 748,
                'generation_id' => (int) $orow['generation_id'], 'host_form' => 'fa', 'field' => 'x',
                'check_type' => 'required', 'reason_code' => 'required-blank', 'record_id_bin' => 'O1',
                'identity' => hash('sha256', 'oO1', true), 'valid_from_seq' => (int) $orow['run_seq'],
                'record_hash' => $oc[0]['hash'], 'rule_source_id' => 'r1',
                'rule_revision' => str_repeat('c', 64)]],
            'records' => [['ordinal' => $oc[0]['ordinal'], 'record_hash' => $oc[0]['hash'],
                           'state' => \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE, 'version' => '1',
                           'claim' => $oc[0]['claim']]],
            'unstored' => [$oc[0]['ordinal'] => 3]]);
        $afterCommit = $old->run(748, $orid);
        $old->advancePhase($orid, $oep, \INSPIRE\UniversalValidator\Scan\ScanPhase::CATCH_UP);
        $old->depart($orid, $oep, [hash('sha256', 'O1', true)]);
        check('rvf1-3: a run from before the count keeps no count, through a commit and a departure',
            $afterCommit['open_unstored'] === null && $old->run(748, $orid)['open_unstored'] === null);
        check('W5F-N2: nor a count of what it holds, while detail_rows counts the row it wrote',
            $afterCommit['open_findings'] === null && (int) $afterCommit['detail_rows'] === 1
            && $old->run(748, $orid)['open_findings'] === null);
    }

    // W5F-N1: A RECORD WHOSE READING THE RUN NO LONGER HOLDS TAKES ITS
    // UNIQUENESS CANDIDATES WITH IT, as the SQL store deletes them (its half,
    // with the plan, is the MySQL store case): sent back to be read again,
    // deleted, or out of the run's group. Another run's candidates and the
    // other records' stay.
    {
        $cs = new \INSPIRE\UniversalValidator\Scan\ArrayScanStore();
        $crow = $cs->startRun(749, ['created_by' => 'alice'])['run'];
        $crid = (int) $crow['run_id'];
        $cep = (int) $crow['lease_epoch'];
        $ch = function ($id) { return hash('sha256', 'c' . $id, true); };
        $cs->writeManifest($crid, array_map(function ($id) use ($ch) {
            return ['id_bin' => $id, 'hash' => $ch($id), 'dag' => null];
        }, ['C1', 'C2', 'C3', 'C4']));
        $cand = function ($ord, $id, $gen) use ($ch) {
            return ['ordinal' => $ord, 'project_id' => 749, 'generation_id' => $gen,
                    'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64),
                    'group_hmac' => hash('sha256', 'gSAME', true), 'scope_key' => 'project',
                    'record_hash' => $ch($id), 'record_id_bin' => $id, 'event_id' => 1, 'instance' => 1,
                    'host_form' => 'f', 'field' => 'hospno', 'version' => '100'];
        };
        $cb = ['bytes' => 0, 'records' => [], 'findings' => [], 'candidates' => []];
        foreach ($cs->claim($crid, 'w', $cep, 4) as $c) {
            $cb['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'], 'version' => '100',
                                'state' => \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE, 'claim' => $c['claim']];
            $cb['candidates'][] = $cand($c['ordinal'], $c['id_bin'], (int) $crow['generation_id']);
            // The same record in another generation: not this run's.
            $cb['candidates'][] = array_merge($cand($c['ordinal'], $c['id_bin'], 99), ['field' => 'other']);
        }
        $cs->commitBatch($crid, 'w', $cep, $cb);
        $cs->advancePhase($crid, $cep, \INSPIRE\UniversalValidator\Scan\ScanPhase::CATCH_UP);
        $ofRun = function () use ($cs, $crow) {
            $ids = [];
            foreach ($cs->candidates() as $c) {
                if ((int) $c['generation_id'] === (int) $crow['generation_id']) $ids[] = (string) $c['record_id_bin'];
            }
            sort($ids);
            return $ids;
        };
        $before = $ofRun();
        $moved = [$cs->requeue($crid, $cep, [$ch('C1')]), $cs->tombstone($crid, $cep, [$ch('C2')]),
                  $cs->depart($crid, $cep, [$ch('C3')])];
        check('W5F-N1: a requeue, a tombstone and a departure each take their record\'s candidates, and only those',
            $before === ['C1', 'C2', 'C3', 'C4'] && $moved === [1, 1, 1] && $ofRun() === ['C4']);
        check('W5F-N1: and another generation\'s candidates of the same records stay',
            count($cs->candidates()) === 5);
    }

    echo "scan_store_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
