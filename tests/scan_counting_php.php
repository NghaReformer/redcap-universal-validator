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
    // every axis.
    $rollup = function (ScanStore $s, array $run) {
        $n = count($s->findings((int) $run['project_id'], (int) $run['generation_id'], ['dag' => null], [], 0, 1000));
        foreach (RollupBuilder::KINDS as $kind) $s->addAggregate((int) $run['run_id'], $kind, 'x', '', $n);
    };

    \countingWalk(function () { return new ArrayScanStore(); }, 'array-store',
                  ['key' => $KEY, 'publish' => $publish, 'rollup' => $rollup]);
}

namespace {
    echo "scan_counting_php: $n checks, $fail failure(s)\n";
    exit($fail > 0 ? 1 : 0);
}
