<?php
/**
 * tests/mysql/cases/counting.php - one number for a run's findings in every
 * state, over SqlScanStore, the real UniqueFinalizer and the real
 * RollupBuilder (reviews rvc2-2, rvc1-2, CONS-N1).
 *
 * The walk itself is tests/scan_counting_walk.php, which
 * tests/scan_counting_php.php also drives over ArrayScanStore. Here the
 * duplicate groups are published by the finalizer the worker runs, so its
 * notePublished() count goes through the same transaction as the rows it
 * opens, and the summary is the one RollupBuilder builds from the rows.
 */

use INSPIRE\UniversalValidator\Scan\RollupBuilder;
use INSPIRE\UniversalValidator\Scan\ScanStore;
use INSPIRE\UniversalValidator\Scan\SqlScanStore;
use INSPIRE\UniversalValidator\Scan\UniqueFinalizer;

require_once __DIR__ . '/../../scan_counting_walk.php';

{
    $KEY = 'counting-key';

    // Publish the first $howMany groups the finalizer reaches. Every record
    // of a group holds the group's value when it is read again.
    $publish = function (ScanStore $s, array $run, array $groups, $howMany) use ($dbA, $KEY) {
        $truth = array();
        foreach ($groups as $g) foreach ($g['records'] as $id) $truth[$id] = $g['value'];
        $reader = function (array $locs) use ($truth) {
            $out = array();
            foreach ($locs as $l) {
                if (isset($truth[$l['record']])) $out[UniqueFinalizer::locKey($l)] = array($truth[$l['record']]);
            }
            return array('ok' => true, 'values' => $out, 'why' => null);
        };
        $fin = (new UniqueFinalizer($dbA, array('pid' => (int) $run['project_id'], 'hmacKey' => $KEY,
            'store' => $s, 'read' => $reader, 'ordinals' => array('r1' => 1))))
            ->forRun((int) $run['run_seq'], (int) $run['run_id'], (int) $run['lease_epoch']);
        // A group of one record (the partial run's R1) is settled unpublished.
        $want = min($howMany, count($groups));
        $published = 0;
        $steps = 0;
        $why = null;
        while ($published < $want && $steps++ < 200) {
            $r = $fin->step((int) $run['generation_id'], 50);
            $published += (int) $r['published'];
            if ($r['why'] !== null) $why = $r['why'];
            if ($r['done']) break;
        }
        check('counting: the finalizer published ' . $want . ' group(s) (published ' . $published
            . ($why !== null ? ', last said: ' . $why : '') . ')', $published === $want);
    };
    $rollup = function (ScanStore $s, array $run) use ($dbA) {
        $roll = new RollupBuilder($dbA, $s);
        $steps = 0;
        while ($steps++ < 50) {
            $st = $roll->step((int) $run['project_id'], (int) $run['run_id'], (int) $run['lease_epoch'],
                              (int) $run['generation_id'], 1000);
            if ($st['done']) break;
        }
        check('counting: the summary was built', $steps < 50);
    };

    // THE PROJECT NEXT DOOR (docs/TESTING.md): each state's project has a
    // neighbour with a run and two findings of its own in the same
    // generation number, so a count, a page or a summary that does not scope
    // by project would take them in.
    $planted = array();
    $before = function ($pid) use ($dbA, &$planted) {
        $planted[$pid] = uv_plant_neighbour($dbA, $pid);
    };

    countingWalk(function () use ($dbA) { return new SqlScanStore($dbA); }, 'sql-store',
                 array('key' => $KEY, 'publish' => $publish, 'rollup' => $rollup, 'before' => $before));

    $whole = count($planted) === 14;
    foreach ($planted as $pid => $nb) {
        $whole = $whole && is_array($nb) && uv_neighbour_findings($dbA, $pid) === 2;
    }
    check('counting: each of the fourteen projects had a neighbour, whose two findings no run here touched', $whole);
}
