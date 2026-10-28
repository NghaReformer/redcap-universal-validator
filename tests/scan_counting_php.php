<?php
/**
 * scan_counting_php.php - one number for a run's findings in every state,
 * over ArrayScanStore (reviews rvc2-2, rvc1-2, CONS-N1). The walk is
 * tests/scan_counting_walk.php; tests/mysql/cases/counting.php drives the
 * same walk over the real store, finalizer and summary.
 *
 * This store has no finalizer and no summary builder of its own, so the
 * hooks stand in for them as the SQL classes write: a published group's rows
 * made active and counted with notePublished() in one step, and a summary
 * that counts the generation's active rows on every axis.
 *
 * Run:  php tests/scan_counting_php.php
 */

namespace {
    require_once __DIR__ . '/../php/ScanDimensions.php';
    require_once __DIR__ . '/../php/MessageCatalog.php';
    require_once __DIR__ . '/../php/ScanColumns.php';
    require_once __DIR__ . '/../php/ScanPageView.php';
    require_once __DIR__ . '/../php/ScanCapabilities.php';
    require_once __DIR__ . '/../php/Scan/ScanReport.php';
    require_once __DIR__ . '/../php/Scan/Schema.php';
    require_once __DIR__ . '/../php/Scan/ScanDb.php';
    require_once __DIR__ . '/../php/Scan/DbError.php';
    require_once __DIR__ . '/../php/Scan/ScanStore.php';
    require_once __DIR__ . '/support/ArrayScanStore.php';
    require_once __DIR__ . '/../php/Scan/FindingRowMapper.php';
    require_once __DIR__ . '/../php/Scan/ScanOutcome.php';
    require_once __DIR__ . '/../php/Scan/ScanPhase.php';
    require_once __DIR__ . '/../php/Scan/ScanPolicy.php';
    require_once __DIR__ . '/../php/Scan/ScanAuthorization.php';
    require_once __DIR__ . '/../php/Scan/Hmac.php';
    require_once __DIR__ . '/../php/Scan/ReasonCode.php';
    require_once __DIR__ . '/../php/Scan/SqlScanStore.php';
    require_once __DIR__ . '/../php/Scan/WorkerSlots.php';
    require_once __DIR__ . '/../php/Scan/ScanRetention.php';
    require_once __DIR__ . '/../php/Scan/RecordManifestSource.php';
    require_once __DIR__ . '/../php/Scan/SourceFence.php';
    require_once __DIR__ . '/../php/Scan/ScanPlanner.php';
    require_once __DIR__ . '/../php/Scan/WorkBudget.php';
    require_once __DIR__ . '/../php/Scan/UniqueFinalizer.php';
    require_once __DIR__ . '/../php/Scan/CatchUp.php';
    require_once __DIR__ . '/../php/Scan/RollupBuilder.php';
    require_once __DIR__ . '/../php/Scan/ScanPromotion.php';
    require_once __DIR__ . '/../php/Scan/ScanWorker.php';
    require_once __DIR__ . '/../php/Scan/ScanStoreUnavailable.php';
    require_once __DIR__ . '/../php/Scan/ScanService.php';
    require_once __DIR__ . '/../php/Logic.php';
    require_once __DIR__ . '/scan_counting_walk.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }
}

namespace INSPIRE\UniversalValidator\Scan {
    $KEY = 'counting-key';

    // UniqueFinalizer::publishRows() for this store: the group's duplicate
    // rows written active, one per candidate, and counted in the same step.
    $publish = function (ScanStore $s, array $run, array $groups, $howMany) use ($KEY) {
        $pid = (int) $run['project_id'];
        $gen = (int) $run['generation_id'];
        foreach (array_slice($groups, 0, $howMany) as $g) {
            $h = Hmac::raw(Hmac::P_UNIQUE, $pid, $g['value'], $KEY);
            $rows = [];
            foreach ($s->candidates() as $c) {
                if ((int) $c['generation_id'] !== $gen || $c['group_hmac'] !== $h) continue;
                $rows[] = ['project_id' => $pid, 'generation_id' => $gen,
                    'identity' => hash('sha256', 'dup|' . $c['record_id_bin'], true), 'stage_epoch' => 1,
                    'valid_from_seq' => (int) $run['run_seq'], 'record_hash' => $c['record_hash'],
                    'record_id_bin' => $c['record_id_bin'], 'event_id' => 1, 'instance' => 1,
                    'host_form' => 'fa', 'field' => 'code', 'rule_source_id' => 'r1',
                    'rule_revision' => str_repeat('c', 64), 'rule_ord' => 1, 'check_type' => 'unique',
                    'reason_code' => 'duplicate-value', 'dag_key' => null, 'group_hmac' => $h];
            }
            if (count($rows) < 2) continue;              // a value on one record is no duplicate
            $put = function (array $rows, $seq) {
                foreach ($rows as $f) {
                    $row = self::stored($f, $seq, $this->nextFinding++);
                    $row['active_slot'] = 1;
                    $this->findings[] = $row;
                }
            };
            \Closure::bind($put, $s, ArrayScanStore::class)($rows, (int) $run['run_seq']);
            $s->notePublished((int) $run['run_id'], count($rows), 0);
        }
    };
    // RollupBuilder for this store: every active row of the generation, on
    // every axis, and on the standing axis open apart from saved with a
    // reason.
    $rollup = function (ScanStore $s, array $run) {
        $rows = $s->findings((int) $run['project_id'], (int) $run['generation_id'], ['dag' => null], [], 0, 1000);
        $justified = count(array_filter($rows, function ($f) { return !empty($f['justified']); }));
        foreach (RollupBuilder::KINDS as $kind) {
            if ($kind !== RollupBuilder::K_STANDING) {
                $s->addAggregate((int) $run['run_id'], $kind, 'x', '', count($rows));
                continue;
            }
            $s->addAggregate((int) $run['run_id'], $kind, RollupBuilder::STANDING_OPEN, '', count($rows) - $justified);
            if ($justified) $s->addAggregate((int) $run['run_id'], $kind, RollupBuilder::STANDING_JUSTIFIED, '', $justified);
        }
    };

    \countingWalk(function () { return new ArrayScanStore(); }, 'array-store',
                  ['key' => $KEY, 'publish' => $publish, 'rollup' => $rollup]);

    // J3B-7: THE VERDICT COUNTS THE OPEN FINDINGS THE PANEL COUNTS. R1 stores
    // one open finding. In S10 the detail budget also left out one of R1's,
    // with a reason. In S11 R2's first reading left out one with a reason,
    // and catch-up reads R2 again and finds nothing, so the run's summary
    // still counts the left-out one and its row does not. Both runs end with
    // one open finding, so neither is clean, through the real promote().
    $verdict = function ($pid, array $first, array $reread) use ($KEY, $rollup) {
        $s = new ArrayScanStore();
        $run = $s->startRun($pid, ['created_by' => 'alice', 'values_state' => 'raw'])['run'];
        $rid = (int) $run['run_id'];
        $ep = (int) $run['lease_epoch'];
        $gen = (int) $run['generation_id'];
        $hash = function ($id) use ($pid, $KEY) { return Hmac::raw(Hmac::P_RECORD, $pid, $id, $KEY); };
        $s->writeManifest($rid, [['id_bin' => 'R1', 'hash' => $hash('R1'), 'dag' => null],
                                 ['id_bin' => 'R2', 'hash' => $hash('R2'), 'dag' => null]]);
        // plan: record id => [stored, of them with a reason, left out, of them with a reason]
        $commit = function (array $claims, $owner, array $plan, $version) use ($s, $rid, $ep, $pid, $gen, $run) {
            $b = ['bytes' => 0, 'records' => [], 'findings' => [], 'unstored' => [], 'unstoredJustified' => []];
            foreach ($claims as $c) {
                if (!isset($plan[$c['id_bin']])) continue;
                list($n, $nj, $left, $leftJ) = $plan[$c['id_bin']];
                $b['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'], 'version' => $version,
                                   'state' => ScanStore::REC_DONE, 'claim' => $c['claim']];
                for ($i = 0; $i < $n; $i++) {
                    $b['findings'][] = ['ordinal' => $c['ordinal'], 'project_id' => $pid, 'generation_id' => $gen,
                        'identity' => hash('sha256', $pid . '|' . $c['id_bin'] . '|' . $version . '|' . $i, true),
                        'valid_from_seq' => (int) $run['run_seq'], 'record_hash' => $c['hash'],
                        'record_id_bin' => $c['id_bin'], 'event_id' => 1, 'instance' => 1, 'host_form' => 'fa',
                        'field' => 'f' . $i, 'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64),
                        'rule_ord' => 1, 'check_type' => 'range', 'reason_code' => 'hard-high', 'dag_key' => null,
                        'justified' => $i < $nj ? 1 : 0];
                }
                if ($left > 0) $b['unstored'][$c['ordinal']] = $left;
                if ($leftJ > 0) $b['unstoredJustified'][$c['ordinal']] = $leftJ;
            }
            return $s->commitBatch($rid, $owner, $ep, $b) === true;
        };
        $ok = $commit($s->claim($rid, 'w1', $ep, 2), 'w1', $first, '10');
        $ok = $ok && $s->advancePhase($rid, $ep, ScanPhase::CATCH_UP);
        if ($reread) {
            $ok = $ok && $s->requeue($rid, $ep, array_map($hash, array_keys($reread))) === count($reread);
            $back = $s->claimPending($rid, 'w2', $ep, 10);
            $ok = $ok && is_array($back) && $commit($back, 'w2', $reread, '11');
        }
        // catch-up proved its window, as CatchUp writes it
        \Closure::bind(function ($rid) { $this->runs[$rid]['fence_target'] = '100'; }, $s, ArrayScanStore::class)($rid);
        $ok = $ok && $s->manifestComplete($rid) && $s->advancePhase($rid, $ep, ScanPhase::UNIQUE)
                  && $s->advancePhase($rid, $ep, ScanPhase::ROLLUP);
        $rollup($s, $s->run($pid, $rid));
        $p = ScanPromotion::promote($s, $pid, $rid, ['uniqueDone' => true, 'rollupDone' => true]);
        $after = $s->run($pid, $rid);
        return ['ok' => $ok && !empty($p['promoted']), 'terminal' => $after['terminal'], 'clean' => (int) $after['clean'],
                'open' => ScanPromotion::findingsOf($s, $after), 'justified' => ScanPromotion::justifiedOf($s, $after)];
    };
    $s10 = $verdict(51, ['R1' => [1, 0, 1, 1], 'R2' => [0, 0, 0, 0]], []);
    check('J3B-7 (S10): one open finding stored and one left out with a reason: not clean (got ' . json_encode($s10) . ')',
        $s10['ok'] && $s10['terminal'] === ScanOutcome::PARTIAL && $s10['clean'] === 0
        && $s10['open'] === 1 && $s10['justified'] === 1);
    $s11 = $verdict(53, ['R1' => [1, 0, 0, 0], 'R2' => [0, 0, 1, 1]], ['R2' => [0, 0, 0, 0]]);
    check('J3B-7 (S11): one open finding, and a left-out one with a reason read away: complete, not clean (got '
        . json_encode($s11) . ')',
        $s11['ok'] && $s11['terminal'] === ScanOutcome::COMPLETE && $s11['clean'] === 0
        && $s11['open'] === 1 && $s11['justified'] === 0);
}

namespace {
    echo "scan_counting_php: $n checks, $fail failure(s)\n";
    exit($fail > 0 ? 1 : 0);
}
