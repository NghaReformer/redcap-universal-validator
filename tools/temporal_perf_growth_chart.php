<?php
/**
 * temporal_perf_growth_chart: the time GrowthCurve::build takes and the size
 * of the curves it sends, per bundled reference, for the targets of the
 * @UVRANGE chart plan (reports/uvrange-outputs-chart-plan-2026-10-16.md,
 * section 7): who-wfa with 7 lines for one sex under 15 ms, 7 lines for
 * both sexes under 40 KB. The table is read once first (as the page does,
 * through GrowthReference's memo), so the time is the curves' own.
 *
 * Usage: php tools/temporal_perf_growth_chart.php [runs]
 */
require_once __DIR__ . '/../php/GrowthCurve.php';

use INSPIRE\UniversalValidator\GrowthCurve as GC;
use INSPIRE\UniversalValidator\GrowthReference as G;

$runs = isset($argv[1]) ? max(1, (int) $argv[1]) : 20;
$zs = array_map(function ($p) { return G::normalQuantile($p / 100); }, [1, 3, 15, 50, 85, 97, 99]);
printf("%-14s %10s %10s %10s %8s\n", 'reference', 'best ms', 'median ms', 'bytes 2x', 'points');
foreach (G::catalog(null)['references'] as $id => $entry) {
    $table = G::table($entry);
    $span = GC::span($entry, null);
    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        $t = microtime(true);
        $b = GC::build($entry, $table, 'male', $zs, $span, null);
        $times[] = (microtime(true) - $t) * 1000;
    }
    sort($times);
    $size = 0;
    $points = 0;
    foreach (['male', 'female'] as $sex) {
        $s = GC::build($entry, $table, $sex, $zs, $span, null);
        $size += strlen(json_encode(['d' => $s['d'], 'lines' => $s['lines']]));
        foreach ($s['lines'] as $line) foreach ($line as $seg) $points += count($seg) / 2;
    }
    printf("%-14s %10.2f %10.2f %10d %8d\n", $id, $times[0], $times[intdiv(count($times), 2)], $size, $points);
}
