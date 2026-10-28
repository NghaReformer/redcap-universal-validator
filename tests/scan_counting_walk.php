<?php
/**
 * tests/scan_counting_walk.php - one number for a run's findings, in every
 * state, on any store (reviews rvc2-2, rvc1-2, CONS-N1).
 *
 * The panel's count (ScanPromotion::findingsOf()), the findings page's total
 * (ScanService::expectedRows()), the rows its pages list (ScanStore::
 * findings()), the CSV's rows and its expected_rows trailer
 * (ScanService::streamFindings(), which refuses `export_complete=1` when the
 * two differ) and, once the run has one, its summary (ScanPromotion::tally())
 * must agree. The walk drives one fixture into each state a run can be read
 * in: working, stopped, expired, failed, partial, complete, and stopped in
 * unique-finalize after one of its two duplicate groups was published. It
 * drives every state twice, the second time with R3's stored finding and
 * the one left out beside it saved with a reason (@UVJUSTIFY): the page's
 * count of rows saved with a reason (ScanService::justifiedRows()) is then
 * the rows its pages list with the flag, and the panel's
 * (ScanPromotion::justifiedOf()) adds the one left out (J3B-2). The
 * fixture has catch-up send two records back to be read again, so a run
 * stopped before the second is read again holds a reading from before the
 * requeue (rvc2-2), and the partial run's second record ends unreadable
 * (rvc1-2).
 *
 * Driven by tests/scan_counting_php.php over ArrayScanStore and by
 * tests/mysql/cases/counting.php over SqlScanStore, the real
 * UniqueFinalizer and the real RollupBuilder. The store-specific parts are
 * the hooks:
 *
 *   publish(ScanStore $s, array $run, array $groups, int $howMany): publish
 *     the first $howMany of the duplicate groups the fixture offered as
 *     candidates; $groups is [['value' => string, 'records' => [record id,
 *     ...]], ...] in that order
 *   rollup(ScanStore $s, array $run): build the run's summary
 *   key: the HMAC key the candidates' group hashes are keyed with
 *   before(int $pid), optional: called before each state's run of $pid is
 *     started; the next project id is left free for a neighbour
 */

use INSPIRE\UniversalValidator\Scan\Hmac;
use INSPIRE\UniversalValidator\Scan\FindingRowMapper;
use INSPIRE\UniversalValidator\Scan\ScanOutcome;
use INSPIRE\UniversalValidator\Scan\ScanPhase;
use INSPIRE\UniversalValidator\Scan\ScanPromotion;
use INSPIRE\UniversalValidator\Scan\ScanReport;
use INSPIRE\UniversalValidator\Scan\ScanService;
use INSPIRE\UniversalValidator\Scan\ScanStore;

/**
 * @param callable $newStore () => ScanStore, fresh and empty
 * @param string   $label    which store, for the check names
 * @param array    $hooks    see the file header
 */
function countingWalk(callable $newStore, $label, array $hooks)
{
    $C = function ($what, $cond) use ($label) { \check("$label: $what", $cond); };
    $key = $hooks['key'];
    $groups = [['value' => 'GA', 'records' => ['R1', 'R2']], ['value' => 'GB', 'records' => ['R3', 'R4']]];
    $groupOf = ['R1' => 'GA', 'R2' => 'GA', 'R3' => 'GB', 'R4' => 'GB'];

    // One run of $pid, read to the point $how says. Returns the store and
    // the run row as it ends.
    $build = function ($pid, $how, $justify) use ($newStore, $key, $groups, $groupOf, $hooks) {
        $s = $newStore();
        $run = $s->startRun($pid, ['created_by' => 'alice', 'values_state' => 'raw'])['run'];
        $rid = (int) $run['run_id'];
        $gen = (int) $run['generation_id'];
        $ep = (int) $run['lease_epoch'];
        $hash = function ($id) use ($pid, $key) { return Hmac::raw(Hmac::P_RECORD, $pid, $id, $key); };
        $s->writeManifest($rid, array_map(function ($id) use ($hash) {
            return ['id_bin' => $id, 'hash' => $hash($id), 'dag' => null];
        }, ['R1', 'R2', 'R3', 'R4']));
        $finding = function ($c, $n, $justified) use ($pid, $gen, $run) {
            return ['justified' => $justified ? 1 : 0, 'ordinal' => $c['ordinal'], 'project_id' => $pid, 'generation_id' => $gen,
                'identity' => hash('sha256', $pid . '|' . $c['id_bin'] . '|' . $n, true),
                'valid_from_seq' => (int) $run['run_seq'], 'record_hash' => $c['hash'],
                'record_id_bin' => $c['id_bin'], 'event_id' => 1, 'instance' => 1, 'host_form' => 'fa',
                'field' => 'f' . $n, 'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64),
                'rule_ord' => 1, 'check_type' => 'required', 'reason_code' => 'required-blank', 'dag_key' => null];
        };
        $candidate = function ($c, $version) use ($pid, $gen, $key, $groupOf) {
            return ['ordinal' => $c['ordinal'], 'project_id' => $pid, 'generation_id' => $gen,
                'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64),
                'group_hmac' => Hmac::raw(Hmac::P_UNIQUE, $pid, $groupOf[$c['id_bin']], $key),
                'scope_key' => 'project', 'record_hash' => $c['hash'], 'record_id_bin' => $c['id_bin'],
                'event_id' => 1, 'instance' => 1, 'host_form' => 'fa', 'field' => 'code', 'version' => $version];
        };
        // A batch: [record id => [state, findings, left out, and of each how
        // many a reason stands for]] over the claims.
        $commit = function (array $claims, $owner, array $plan, $version) use ($s, $rid, $ep, $finding, $candidate) {
            $b = ['bytes' => 0, 'records' => [], 'findings' => [], 'candidates' => [], 'unstored' => [],
                  'unstoredJustified' => []];
            foreach ($claims as $c) {
                if (!isset($plan[$c['id_bin']])) continue;
                list($state, $n, $left) = $plan[$c['id_bin']];
                $nJustified = isset($plan[$c['id_bin']][3]) ? $plan[$c['id_bin']][3] : 0;
                $leftJustified = isset($plan[$c['id_bin']][4]) ? $plan[$c['id_bin']][4] : 0;
                $b['records'][] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'], 'version' => $version,
                                   'state' => $state, 'claim' => $c['claim']];
                for ($i = 0; $i < $n; $i++) $b['findings'][] = $finding($c, $version . '-' . $i, $i < $nJustified);
                if ($state === ScanStore::REC_DONE) $b['candidates'][] = $candidate($c, $version);
                if ($left > 0) $b['unstored'][$c['ordinal']] = $left;
                if ($leftJustified > 0) $b['unstoredJustified'][$c['ordinal']] = $leftJustified;
            }
            return $s->commitBatch($rid, $owner, $ep, $b) === true;
        };
        // Read: R1 two findings, R2 one, R3 one and one left out, R4 none.
        // With $justify, R3's two are saved with a reason.
        $ok = $commit($s->claim($rid, 'w1', $ep, 4), 'w1', [
            'R1' => [ScanStore::REC_DONE, 2, 0], 'R2' => [ScanStore::REC_DONE, 1, 0],
            'R3' => [ScanStore::REC_DONE, 1, 1, $justify ? 1 : 0, $justify ? 1 : 0],
            'R4' => [ScanStore::REC_DONE, 0, 0]], '10');
        $ok = $ok && $s->advancePhase($rid, $ep, ScanPhase::CATCH_UP);
        // Catch-up: R1 and R2 changed, so both go back to be read again. R1
        // is read again (one finding now); R2 is not, yet.
        $ok = $ok && $s->requeue($rid, $ep, [$hash('R1'), $hash('R2')]) === 2;
        $back = $s->claimPending($rid, 'w2', $ep, 10);
        $ok = $ok && is_array($back) && count($back) === 2
            && $commit($back, 'w2', ['R1' => [ScanStore::REC_DONE, 1, 0]], '11');
        // R2 is released, still holding its first reading.
        $rel = [];
        foreach ($back as $c) if ($c['id_bin'] === 'R2') $rel[$c['ordinal']] = $c['claim'];
        $s->releaseClaims($rid, $ep, 'w2', $rel);
        if ($how === 'working') return [$s, $s->run($pid, $rid), $ok];
        if (in_array($how, ['stopped', 'expired', 'failed'], true)) {
            if ($how === 'stopped') $ok = $ok && $s->cancel($pid, $rid, 'alice');
            $s->finish($rid, ScanOutcome::derive([$how === 'stopped' ? 'cancelled' : $how => true]));
            return [$s, $s->run($pid, $rid), $ok];
        }
        // The rest finish catch-up: R2 is read again, unreadable for the
        // partial run and with one finding for the others.
        $again = $s->claimPending($rid, 'w3', $ep, 10);
        $ok = $ok && is_array($again) && count($again) === 1
            && $commit($again, 'w3', ['R2' => $how === 'partial' ? [ScanStore::REC_UNREADABLE, 0, 0]
                                                                  : [ScanStore::REC_DONE, 1, 0]], '12');
        $ok = $ok && $s->manifestComplete($rid) && $s->advancePhase($rid, $ep, ScanPhase::UNIQUE);
        $live = $s->run($pid, $rid);
        if ($how === 'stopped-in-unique') {
            $hooks['publish']($s, $live, $groups, 1);
            $ok = $ok && $s->cancel($pid, $rid, 'alice');
            $s->finish($rid, ScanOutcome::derive(['cancelled' => true]));
            return [$s, $s->run($pid, $rid), $ok];
        }
        // The partial run's R2 offered no candidate, so its group is R1 alone.
        $hooks['publish']($s, $live, $how === 'partial' ? [$groups[1]] : $groups, 2);
        $ok = $ok && $s->advancePhase($rid, $ep, ScanPhase::ROLLUP);
        $hooks['rollup']($s, $s->run($pid, $rid));
        $s->finish($rid, ScanOutcome::derive(['fenced' => true, 'manifestDone' => true,
            'blocked' => $how === 'partial', 'violations' => 1, 'ruleProblems' => 0]));
        return [$s, $s->run($pid, $rid), $ok];
    };

    // What each reader is told about one run.
    $read = function (ScanStore $s, array $run) {
        $pid = (int) $run['project_id'];
        $gen = (int) $run['generation_id'];
        $rows = 0;
        $flagged = 0;
        $after = 0;
        do {
            $through = null;
            $page = $s->findings($pid, $gen, ['dag' => null], [], $after, 2, $through);
            $rows += count($page);
            foreach ($page as $f) if (!empty($f['justified'])) $flagged++;
            $after = $through;
        } while ($after !== null);
        $aggregates = $s->aggregates((int) $run['run_id']);
        $expected = ScanService::expectedRows($run, $aggregates, ['dag' => null]);
        $d = new \INSPIRE\UniversalValidator\ScanDimensions();
        $d->forms = ['fa' => 'Form A'];
        $d->singleEventProved = true;
        $d->rules = [1 => ['type' => 'required', 'label' => 'F is required', 'message' => '',
                           'messages' => [], 'assert' => '', 'fields' => [], 'detail' => []]];
        $d->rulesBySource = ['r1' => 1];
        $report = new ScanReport(new FindingRowMapper(['pid' => $pid, 'now' => gmdate('Y-m-d H:i:s'),
            'valuesState' => 'raw', 'readerCeiling' => 'raw', 'identifiers' => [],
            'recordIds' => FindingRowMapper::IDS_RAW, 'recordIdField' => 'record_id']), $d, $run);
        $text = '';
        $csv = ScanService::streamFindings($s, $report, $pid, $gen, ['dag' => null], [['schema', 'x']],
            ['banner' => null, 'row' => null], function ($t) use (&$text) { $text .= $t; }, function () {},
            ['run' => (int) $run['run_id'], 'expected' => $expected]);
        return ['count' => ScanPromotion::findingsOf($s, $run), 'left' => (int) $run['open_unstored'],
                'expected' => $expected, 'rows' => $rows, 'csv' => $csv['rows'], 'csvOk' => $csv['ok'] === true
                && strpos($text, '"# export_complete=1","rows=' . $csv['rows'] . '"') !== false,
                'summary' => ScanPromotion::tally($aggregates)['stored'],
                'flagged' => $flagged, 'justifiedRows' => ScanService::justifiedRows($run, $aggregates, ['dag' => null]),
                'justified' => ScanPromotion::justifiedOf($s, $run)];
    };

    // run state => [terminal, the rows it holds]. R1 one finding, R2 one,
    // R3 one (and one left out); GA and GB two duplicates each.
    $states = ['working'           => [null, 3],
               'stopped'           => [ScanOutcome::CANCELLED, 3],
               'expired'           => [ScanOutcome::EXPIRED, 3],
               'failed'            => [ScanOutcome::FAILED, 3],
               'partial'           => [ScanOutcome::PARTIAL, 2 + 2],
               'complete'          => [ScanOutcome::COMPLETE, 3 + 4],
               'stopped-in-unique' => [ScanOutcome::CANCELLED, 3 + 2]];
    // Every other project id, so a store can give each one a neighbour
    // (tests/mysql/support/fixture.php: uv_neighbour() is the id after).
    $pid = 860;
    foreach ([false, true] as $justify) {
        // the stored finding and the one left out that a reason stands for
        $j = $justify ? 1 : 0;
        foreach ($states as $state => $want) {
            $how = $state . ($justify ? ', R3 saved with a reason' : '');
            if (isset($hooks['before'])) $hooks['before']($pid);
            list($s, $run, $ok) = $build($pid, $state, $justify);
            $pid += 2;
            $C("one number ($how): the fixture reaches the state", $ok && $run !== null
                && $run['terminal'] === $want[0]);
            $got = $read($s, $run);
            $C("one number ($how): the page lists the rows the run holds (got " . json_encode($got) . ')',
                $got['rows'] === $want[1]);
            $C("one number ($how): the panel counts the open ones, and the one finding left out beside them",
                $got['count'] === $want[1] + 1 - 2 * $j && $got['left'] === 1);
            $C("one number ($how): the CSV writes them all and closes on its trailer",
                $got['csv'] === $want[1] && $got['csvOk']);
            if ($want[0] !== null) {
                $C("one number ($how): and the page total and the file's expected_rows are the same number",
                    $got['expected'] === $want[1]);
            }
            if ($want[0] === ScanOutcome::PARTIAL || $want[0] === ScanOutcome::COMPLETE) {
                $C("one number ($how): which is what the summary counted", $got['summary'] === $want[1]);
            }
            $C("one number ($how): the page and the file count the rows saved with a reason that their pages list (J3B-2)",
                $got['flagged'] === $j && $got['justifiedRows'] === $j);
            $C("one number ($how): and the panel adds the one left out with a reason", $got['justified'] === 2 * $j);
        }
    }
}
