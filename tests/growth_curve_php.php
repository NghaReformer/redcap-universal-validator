<?php
/**
 * growth_curve_php.php — the reference curves of an @UVRANGE growth chart
 * (php/GrowthCurve.php).
 *
 * The claim GrowthCurve makes is that a drawn curve lies within MAX_ERROR
 * (0.5) of the exact one, vertically, in the chart's own units, even at the
 * largest zoom the chart allows (a window 1/8 of the span wide). This file
 * measures that claim instead of assuming it: for every bundled reference,
 * both sexes and several sets of lines (the restricted method beyond +-3 SD
 * included), it decodes the payload the browser gets, evaluates the
 * simplified polyline at every table row (and halfway between rows for a
 * reference read by linear interpolation), compares it with
 * GrowthReference::valueAt, and divides the worst error in each window of
 * the narrowest width by the y range the browser gives that window. Also:
 *   - the payload is whole numbers, with a gap where no measurement above 0
 *     has the z-score, and its size for 7 lines and both sexes,
 *   - "measureScale" is undone (the curves are in the field's unit),
 *   - an explicit span, a span outside the reference, the rows of a table of
 *     reference values (curveXs, tableRows) and their cap,
 *   - simplify() on its own, and the curve key,
 *   - the geometry constants equal the engine's (QRID_CHART).
 *
 * Run:  php tests/growth_curve_php.php
 */

require_once __DIR__ . '/../php/GrowthCurve.php';

use INSPIRE\UniversalValidator\GrowthCurve as GC;
use INSPIRE\UniversalValidator\GrowthReference as G;

$n = 0;
$fails = 0;
function check($label, $ok)
{
    global $n, $fails;
    $n++;
    if (!$ok) { $fails++; echo "FAIL: $label\n"; }
}

/**
 * The worst vertical error, in chart units, of curve set $b over every
 * window of the narrowest width the chart shows, as the browser would draw
 * it; and the number of points compared.
 */
function worstPx(array $entry, array $table, $sex, array $zs, array $span, $scale, array $b)
{
    $ts = $table['scale'];
    $rows = $table[$sex];
    $first = $table['first'];
    $f = $scale === null ? 1.0 : (float) $scale;
    $p = pow(10, $b['d']);
    $restricted = $entry['adjust'] === 'who-restricted';
    list($lo, $hi) = $span;
    // the x of every point compared: the rows inside the span, and halfway
    // between two of them when the table is read by linear interpolation
    $xs = [];
    $kLo = max($first, (int) ceil($lo * $ts - 1e-9));
    $kHi = min($first + count($rows) - 1, (int) floor($hi * $ts + 1e-9));
    for ($k = $kLo; $k <= $kHi; $k++) {
        $xs[] = $k / $ts;
        if ($entry['lookup'] === 'linear' && $k < $kHi) $xs[] = ($k + 0.5) / $ts;
    }
    $m = count($xs);
    $err = array_fill(0, $m, 0.0);
    $top = array_fill(0, $m, null);
    $bot = array_fill(0, $m, null);
    foreach ($zs as $li => $z) {
        // the polyline the browser draws, as [x, y] runs
        $runs = [];
        foreach ($b['lines'][$li] as $seg) {
            $pts = [];
            for ($i = 0; $i + 1 < count($seg); $i += 2) $pts[] = [$seg[$i] / $ts, $seg[$i + 1] / $p];
            $runs[] = $pts;
        }
        $r = 0;
        $i = 0;
        foreach ($xs as $xi => $x) {
            $lms = G::lms($entry, $table, $sex, $x);
            $exact = $lms === null ? null : G::valueAt($z, $lms, $restricted);
            // the drawn value at x: the run holding x, the segment holding x
            $drawn = null;
            foreach ($runs as $pts) {
                $c = count($pts);
                if ($c === 0 || $x < $pts[0][0] - 1e-12 || $x > $pts[$c - 1][0] + 1e-12) continue;
                if ($c === 1) { $drawn = $pts[0][1]; break; }
                $lo2 = 0;
                $hi2 = $c - 1;
                while ($hi2 - $lo2 > 1) {
                    $mid = ($lo2 + $hi2) >> 1;
                    if ($pts[$mid][0] <= $x) $lo2 = $mid; else $hi2 = $mid;
                }
                $a = $pts[$lo2];
                $bb = $pts[$hi2];
                $drawn = $a[1] + ($bb[1] - $a[1]) * ($x - $a[0]) / ($bb[0] - $a[0]);
                break;
            }
            if ($exact === null) {
                // no measurement has this z-score here: the drawing must leave a gap
                if ($drawn !== null) $err[$xi] = INF;
                continue;
            }
            if ($drawn === null) { $err[$xi] = INF; continue; }
            $e = abs($drawn - $exact / $f);
            if ($e > $err[$xi]) $err[$xi] = $e;
            if ($top[$xi] === null || $drawn > $top[$xi]) $top[$xi] = $drawn;
            if ($bot[$xi] === null || $drawn < $bot[$xi]) $bot[$xi] = $drawn;
        }
    }
    // every window of the narrowest width, less one row (a window between
    // rows holds the rows of one that starts at a row and is that much
    // narrower): its error over the y range the browser would give it
    $w = ($hi - $lo) / GC::MAX_ZOOM - 1 / $ts;
    $worst = 0.0;
    $j = 0;
    for ($i = 0; $i < $m; $i++) {
        $e = 0.0;
        $t = null;
        $bo = null;
        for ($j = $i; $j < $m && $xs[$j] <= $xs[$i] + $w + 1e-12; $j++) {
            if ($err[$j] > $e) $e = $err[$j];
            if ($top[$j] !== null && ($t === null || $top[$j] > $t)) $t = $top[$j];
            if ($bot[$j] !== null && ($bo === null || $bot[$j] < $bo)) $bo = $bot[$j];
        }
        if ($t === null) continue;
        $range = max($t - $bo, GC::Y_FLOOR * abs($t));
        $px = $e * GC::PLOT_HEIGHT / $range;
        if ($px > $worst) $worst = $px;
        if ($j >= $m) break;
    }
    return [$worst, $m];
}

// ---- 1. the error bound on every bundled reference ------------------------------------
$cat = G::catalog(null);
$lineSets = [
    'z -3..3'           => [-3, -2, -1, 0, 1, 2, 3],
    'z beyond 3 SD'     => [-5, -4, 4, 5],
    'percentiles 3..97' => array_map(function ($p) { return G::normalQuantile($p / 100); }, [3, 15, 50, 85, 97]),
];
$refs = 0;
$worstAll = 0.0;
$where = '';
foreach ($cat['references'] as $id => $entry) {
    $table = G::table($entry);
    $refs++;
    foreach (['male', 'female'] as $sex) {
        foreach ($lineSets as $setName => $zs) {
            $span = GC::span($entry, null);
            $b = GC::build($entry, $table, $sex, $zs, $span, null);
            if ($b === null) { check("build $id $sex $setName gives curves", false); continue; }
            list($px, $pts) = worstPx($entry, $table, $sex, $zs, $span, null, $b);
            if ($px > $worstAll) { $worstAll = $px; $where = "$id $sex $setName"; }
            check("$id $sex $setName: within " . GC::MAX_ERROR . " of the exact curves at 8x zoom (worst "
                . sprintf('%.3f', $px) . " over $pts points)", $px <= GC::MAX_ERROR);
        }
    }
}
check('every bundled reference was measured (' . $refs . ')', $refs >= 12);
echo 'growth_curve_php: worst error ' . sprintf('%.3f', $worstAll) . " (of 0.5) on $where\n";

// an explicit span, and a scale: the error is in the field's own unit
$wfa = $cat['references']['who-wfa'];
$wfaT = G::table($wfa);
$zs = [-2, 0, 2];
$b = GC::build($wfa, $wfaT, 'female', $zs, [0.0, 365.0], '0.001');
list($px) = worstPx($wfa, $wfaT, 'female', $zs, [0.0, 365.0], '0.001', $b);
check('a span of the first year, the curves in grams (worst ' . sprintf('%.3f', $px) . ')', $px <= GC::MAX_ERROR);
$plain = GC::build($wfa, $wfaT, 'female', [0], [0.0, 365.0], null);
$grams = GC::build($wfa, $wfaT, 'female', [0], [0.0, 365.0], '0.001');
$y0 = $plain['lines'][0][0][1] / pow(10, $plain['d']);
$g0 = $grams['lines'][0][0][1] / pow(10, $grams['d']);
check('measureScale is undone: the median at birth is 3.2322 kg, 3232.2 g (got ' . $y0 . ', ' . $g0 . ')',
    abs($y0 - 3.2322) < 0.001 && abs($g0 - 3232.2) < 1);
check('the curves reach one row past each end of the span', $plain['lines'][0][0][0] === 0
    && end($plain['lines'][0][0]) !== false && $plain['lines'][0][0][count($plain['lines'][0][0]) - 2] === 366);

// ---- 2. the payload ------------------------------------------------------------------
check('the payload is whole numbers only', (function () use ($b) {
    foreach ($b['lines'] as $line) foreach ($line as $seg) foreach ($seg as $v) if (!is_int($v)) return false;
    return is_int($b['d']);
})());
$seven = [-3, -2, -1, 0, 1, 2, 3];
$both = 0;
foreach (['male', 'female'] as $sex) {
    $s = GC::build($wfa, $wfaT, $sex, $seven, GC::span($wfa, null), null);
    $both += strlen(json_encode(['d' => $s['d'], 'lines' => $s['lines']]));
}
check('7 lines of who-wfa, both sexes, take under 40 KB (' . $both . ' bytes)', $both < 40000);
foreach ($cat['references'] as $id => $entry) {
    $t = G::table($entry);
    $size = 0;
    foreach (['male', 'female'] as $sex) {
        $s = GC::build($entry, $t, $sex, $seven, GC::span($entry, null), null);
        $size += strlen(json_encode(['d' => $s['d'], 'lines' => $s['lines']]));
    }
    check("$id: 7 lines, both sexes, under 40 KB ($size bytes)", $size < 40000);
}
// a line with no measurement above 0 for part of the span is cut there
$fake = ['id' => 'fake', 'adjust' => 'none', 'lookup' => 'round', 'axis' => 'age', 'axisUnit' => 'days',
         'valid' => ['min' => '0', 'below' => '10'], 'unit' => 'kg'];
$rows = [];
// rows 3 to 5: 1 + L*S*z = 1 - 0.3*5 < 0 at z -5, so no measurement has that z-score there
for ($k = 0; $k < 10; $k++) $rows[] = [1.0, 10.0, ($k >= 3 && $k <= 5) ? 0.3 : 0.1];
$fakeT = ['scale' => 1, 'first' => 0, 'male' => $rows, 'female' => $rows];
$gap = GC::build($fake, $fakeT, 'male', [-5, 0], [0.0, 10.0], null);
check('a curve without a value at some rows is drawn in pieces (got ' . json_encode($gap['lines'][0]) . ')',
    count($gap['lines'][0]) === 2 && $gap['lines'][0][0][0] === 0 && end($gap['lines'][0][0]) > 0
    && $gap['lines'][0][1][0] === 6 && count($gap['lines'][1]) === 1);
check('no curve with a point in the span: nothing to draw', GC::build($fake, $fakeT, 'male', [0], [20.0, 30.0], null) === null);

// ---- 3. simplify, span, key ---------------------------------------------------------
$xs = range(0, 10);
$ys = array_map(function ($x) { return $x * 2.0; }, $xs);
check('simplify: a straight line keeps its two ends', GC::simplify($xs, $ys, 0, 10, 0.01) === [0, 10]);
$ys[5] = 11.0;   // one unit off the line
check('simplify: a point past the tolerance is kept', GC::simplify($xs, $ys, 0, 10, 0.9) === [0, 5, 10]
    && GC::simplify($xs, $ys, 0, 10, 1.5) === [0, 10]);
check('simplify: one or two points', GC::simplify($xs, $ys, 3, 3, 1) === [3] && GC::simplify($xs, $ys, 3, 4, 1) === [3, 4]);
check('span: auto is the valid range, a "below" its end', GC::span($wfa, null) === [0.0, 1826.25]
    && GC::span($cat['references']['who-wfl'], null) === [45.0, 110.0] && GC::span($wfa, ['30', '365']) === [30.0, 365.0]);
check('key: reference, scale, span, lines and band', GC::key('who-wfa', null, [0.0, 1826.25], 'z', ['-2', '0', '2'], null)
    === 'who-wfa|1|0,1826.25|z:-2,0,2|band:' && GC::key('who-wfa', '0.0010', [0.0, 365.0], 'percentiles', ['3', '97'], ['-2', null])
    === 'who-wfa|0.001|0,365|percentiles:3,97|band:-2,');
check('numText: canonical, no trailing zeros', GC::numText('2.50') === '2.5' && GC::numText('-0.0') === '0'
    && GC::numText('007') === '7' && GC::numText('x') === null);

// ---- 4. the span against the reference, and the rows of a table of reference values ----
$show = ['chart' => ['span' => ['0', '2000']]];
check('a span past the reference is refused (got ' . json_encode(GC::showProblem($show, $wfa)) . ')',
    GC::showProblem($show, $wfa) === '"show" "chart" "span" [0, 2000] lies outside "who-wfa", which covers 0 to under 1826.25 days.');
check('a span inside it is fine', GC::showProblem(['chart' => ['span' => ['0', '1826.25']]], $wfa) === null
    && GC::showProblem(['table' => ['rows' => 'curve', 'step' => '30', 'span' => ['0', '730']]], $wfa) === null);
check('a span of a reference with "max" may reach it', GC::showProblem(['chart' => ['span' => ['45', '110']]], $cat['references']['who-wfl']) === null
    && GC::showProblem(['chart' => ['span' => ['44.9', '110']]], $cat['references']['who-wfl']) !== null);
$long = GC::showProblem(['table' => ['rows' => 'curve', 'step' => '1', 'span' => null]], $wfa);
check('a table of reference values over 100 rows is refused (got ' . json_encode($long) . ')', $long !== null
    && strpos($long, 'has more than 100 rows over "who-wfa" (0 to under 1826.25 days)') !== false);
check('curveXs: lo, lo + step, ... under a "below" end', GC::curveXs($wfa, null, '365.25') === [0.0, 365.25, 730.5, 1095.75, 1461.0]
    && GC::curveXs($wfa, ['0', '60'], '30') === [0.0, 30.0, 60.0]);
check('curveXs: at most 101 values', count(GC::curveXs($wfa, null, '1')) === GC::MAX_CURVE_ROWS + 1);
$tr = GC::tableRows($wfa, $wfaT, 'male', ['0', '60'], '30', ['age', 'valueAtZ:0', 'valueAtPercentile:97', 'lower', 'range'],
    ['limits' => ['lo' => '-2', 'hi' => '2'], 'decimals' => 2, 'comma' => true, 'unit' => null, 'scale' => null]);
check('tableRows: one row per age, the values with the rule\'s decimals (got ' . json_encode($tr) . ')', count($tr) === 3
    && $tr[0][0] === '0' && $tr[0][1] === '3.35' && $tr[1][0] === '30' && $tr[0][3] !== '' && strpos($tr[0][4], ' to ') !== false
    && strpos($tr[0][4], ',') !== false && strpos($tr[0][1], ',') === false);
check('tableRows: a column needing a limit the rule lacks is empty', GC::tableRows($wfa, $wfaT, 'male', ['0', '0'], '1', ['upper'],
    ['limits' => ['lo' => '-2', 'hi' => null], 'decimals' => 1]) === [['']]);

// ---- 5. the engine draws with the same geometry -------------------------------------
$src = file_get_contents(__DIR__ . '/../js/engine.js');
$ok = preg_match('/var QRID_CHART = \{([^}]*)\}/', $src, $mm);
$geo = [];
if ($ok) foreach (explode(',', $mm[1]) as $kv) { $p2 = explode(':', $kv); if (count($p2) === 2) $geo[trim($p2[0])] = (float) trim($p2[1]); }
check('QRID_CHART is GrowthCurve\'s geometry (got ' . json_encode($geo) . ')', $ok === 1
    && $geo['plotWidth'] == GC::PLOT_WIDTH && $geo['plotHeight'] == GC::PLOT_HEIGHT && $geo['maxZoom'] == GC::MAX_ZOOM
    && $geo['yFloor'] == GC::Y_FLOOR);

echo "growth_curve_php: $n checks, $fails failure(s)\n";
exit($fails ? 1 : 0);
