<?php
/**
 * GrowthCurve — the reference curves of an @UVRANGE growth chart, and the
 * rows of a page table of reference values ("show", page only).
 *
 * The server builds the curves when the page is built, so the browser draws
 * them and computes only the child's point (QRID_growthChart in js/engine.js).
 * Each curve is the measurement at one z-score (a percentile is turned into
 * its z-score first) at every table row of the chart's span, with
 * GrowthReference::valueAt, WHO's restricted method beyond +-3 SD included, in
 * the measured field's unit ("measureScale" undone). The polyline is then
 * simplified with Ramer-Douglas-Peucker, measuring the vertical distance.
 *
 * The tolerance keeps the drawn curve within MAX_ERROR of the exact one,
 * vertically, in the chart's own units, at the largest zoom the chart allows:
 * its narrowest window is 1/MAX_ZOOM of the span ("auto"), or the whole span
 * when the rule fixes it. A window's y axis spans at least the curves inside
 * it and the reference at -EXTENT_Z and EXTENT_Z (sent as "extent", not
 * drawn), with the same floor the browser uses (Y_FLOOR), so the smallest
 * such range over every window of that width sets how many units one unit of
 * the drawing area is.
 *
 * The curve is exact at the table's rows. With "lookup" "round" or "floor"
 * the reference reads the row at or below an age, a step from row to row, so
 * the browser draws this visit's point at the age of the row the check
 * reads (QRID_growthReadX), where the curve is exact; with "linear" it reads
 * between rows as the curve is drawn. tests/growth_curve_php.php measures the
 * bound at every row and, for "linear", at quarter rows between them, and
 * tests/growth_chart_dom_js.cjs against the paths the browser draws.
 *
 * The payload is whole numbers only: a row key (the axis value times the
 * table's "scale") and the measurement in units of 10^-d, so its size does
 * not depend on how PHP writes a double.
 *
 * Pure static class with no REDCap dependency.
 */

namespace INSPIRE\UniversalValidator;

require_once __DIR__ . '/Logic.php';
require_once __DIR__ . '/GrowthReference.php';
require_once __DIR__ . '/GrowthOutputs.php';

final class GrowthCurve
{
    /**
     * The chart's drawing area, in the SVG's own units. Twin: QRID_CHART in
     * js/engine.js. PLOT_WIDTH is the widest: the browser narrows the plot to
     * fit the field's cell, which the error bound (vertical) does not depend
     * on.
     */
    const PLOT_WIDTH = 512;
    const PLOT_HEIGHT = 312;
    /** The narrowest window the chart shows is 1/MAX_ZOOM of its span. */
    const MAX_ZOOM = 8;
    /** The most a drawn curve may lie from the exact one, vertically, in the chart's units. */
    const MAX_ERROR = 0.5;
    /**
     * The share of MAX_ERROR the simplification may use. The rest covers the
     * rounding of the numbers sent (at most a quarter of the tolerance, half
     * of it per number) and the browser reading a y axis from the simplified
     * curves rather than the exact ones.
     */
    const SHARE = 0.8;
    /**
     * A window's y axis spans at least this share of its highest value, so a
     * flat curve is not magnified without end. Twin: QRID_CHART.yFloor.
     */
    const Y_FLOOR = 0.1;
    /**
     * A chart of measurements spans at least the reference at -EXTENT_Z and
     * EXTENT_Z over the ages it shows, so a child inside that range is drawn
     * inside the plot when the chart draws only narrow lines (the median
     * alone, or [25, 75]). 2 is the "usual range" line of the WHO charts; a
     * chart that draws P3 to P97 (z +-1.88) keeps nearly the same axis, where
     * 3 would squeeze its curves into about the middle 60% of the plot. A point past
     * it may still fall outside and get the arrow, and it is unusual by then.
     * A chart of z-scores has its own floor in the browser (-3 to 3).
     */
    const EXTENT_Z = 2.0;
    /** The most rows a table of reference values ("rows":"curve") has. */
    const MAX_CURVE_ROWS = 100;
    /** The most decimals a sent measurement has, and the largest whole number it may become. */
    const MAX_DECIMALS = 9;
    const MAX_UNITS = 9.0e15;
    /**
     * The most points one chart's curves may take for one sex (about 60 KB).
     * WHO's tables need a few hundred; a table too ragged to simplify is left
     * off the page instead of sent whole.
     */
    const MAX_SET_POINTS = 5000;

    /** A number as the text a key holds: canonical, no trailing zeros, "-0" as "0". */
    public static function numText($s)
    {
        $c = Logic::canonicalDecimal(trim((string) $s));
        if ($c === null) return null;
        if (strpos($c, '.') !== false) $c = rtrim(rtrim($c, '0'), '.');
        return $c === '-0' ? '0' : $c;
    }

    /**
     * The span of a chart or curve table as [lo, hi] (floats) in the
     * reference's axis unit: the rule's own, or for "auto" (null) the
     * reference's whole "valid" range (its "below" counts as the end).
     */
    public static function span(array $entry, $span)
    {
        if (is_array($span) && count($span) === 2) return [(float) $span[0], (float) $span[1]];
        $v = $entry['valid'];
        return [(float) $v['min'], (float) (array_key_exists('max', $v) ? $v['max'] : $v['below'])];
    }

    /**
     * Why a part of a rule's "show" does not fit reference $entry: a "span"
     * outside the reference's "valid" range, and a table of reference values
     * longer than MAX_CURVE_ROWS over its span. $show is the parsed
     * rangeShow. Returns ['chart' => why, 'table' => why], each part only
     * when it has a problem. These depend on the reference, which an
     * administrator may replace, so they leave that part off the page with
     * the reason for staff and never stop the check
     * (UniversalValidator::annotateRangeDictionary).
     */
    public static function showProblems(array $show, array $entry)
    {
        $v = $entry['valid'];
        $min = (string) $v['min'];
        $hasMax = array_key_exists('max', $v);
        $max = (string) ($hasMax ? $v['max'] : $v['below']);
        $range = $min . ' to ' . ($hasMax ? $max : 'under ' . $max) . ' ' . $entry['axisUnit'];
        $out = [];
        foreach (['chart', 'table'] as $part) {
            $span = isset($show[$part]['span']) ? $show[$part]['span'] : null;
            if (!is_array($span)) continue;
            if (Logic::numCompare($span[0], $min) < 0 || Logic::numCompare($span[1], $max) > 0
                    || (!$hasMax && Logic::numCompare($span[0], $max) >= 0)) {
                $out[$part] = 'its "span" [' . $span[0] . ', ' . $span[1] . '] lies outside "' . $entry['id'] . '", which covers '
                    . $range . '.';
            }
        }
        if (!isset($out['table']) && isset($show['table']['rows']) && $show['table']['rows'] === 'curve' && isset($show['table']['step'])) {
            $span = isset($show['table']['span']) ? $show['table']['span'] : null;
            $n = count(self::curveXs($entry, $span, $show['table']['step']));
            if ($n > self::MAX_CURVE_ROWS) {
                $out['table'] = '"step" ' . $show['table']['step'] . ' gives more than ' . self::MAX_CURVE_ROWS
                    . ' rows over ' . (is_array($span) ? '[' . $span[0] . ', ' . $span[1] . ']' : '"' . $entry['id'] . '" (' . $range . ')')
                    . ' — take a larger "step", or a shorter "span".';
            }
        }
        return $out;
    }

    /**
     * The axis values of a table of reference values: lo, lo + step, ...
     * up to hi, and under the end of a "valid" range that ends "below" a
     * value, at most MAX_CURVE_ROWS + 1 of them (the one past the cap says the
     * table is too long). Each is lo + i * step, so the rows do not drift.
     */
    public static function curveXs(array $entry, $span, $step)
    {
        list($lo, $hi) = self::span($entry, $span);
        $below = array_key_exists('max', $entry['valid']) ? null : (float) $entry['valid']['below'];
        return self::xsBetween($lo, $hi, (float) $step, $below);
    }

    /**
     * lo, lo + step, ... up to hi, and under $below when it is not null, at
     * most MAX_CURVE_ROWS + 1 values: what curveXs() gives once the span is
     * known, and what the parser counts for a span written in the tag.
     */
    public static function xsBetween($lo, $hi, $step, $below)
    {
        $out = [];
        if (!($step > 0)) return $out;
        for ($i = 0; $i <= self::MAX_CURVE_ROWS; $i++) {
            $x = $lo + $i * $step;
            if (($below !== null && $x >= $below) || $x > $hi + 1e-9 * max(1.0, abs($hi))) break;
            $out[] = $x;
        }
        return $out;
    }

    /**
     * The curves of one chart for one sex. $zs lists each curve's z-score
     * (GrowthReference::lineZ), $span is [lo, hi] (span()), $scale the
     * rule's "measureScale" or null, and $zoom how far the chart may narrow
     * its window: MAX_ZOOM for an "auto" span, 1 for a span the rule fixes
     * (the browser then always shows all of it).
     *
     * @return array|null ['d' => decimals, 'tolerance' => float (field
     *   units), 'lines' => [[segment, ...] per curve], 'extent' => [low,
     *   high]] where a segment is a flat list of whole numbers [key, units,
     *   key, units, ...] (x = key / the table's "scale", y = units / 10^d) and
     *   a gap between segments is where no measurement above 0 has that
     *   z-score. "extent" holds the reference at -EXTENT_Z and EXTENT_Z as
     *   segments, each null when the curves already reach past it at every
     *   row (it changes no axis then); the browser reads it for the y axis
     *   and draws nothing of it. ['why' => reason] when the curves cannot be
     *   sent (values too large to send as whole numbers, or more than
     *   MAX_SET_POINTS points); null when no curve has a point in the span.
     */
    public static function build(array $entry, array $table, $sex, array $zs, array $span, $scale = null, $zoom = self::MAX_ZOOM)
    {
        list($lo, $hi) = $span;
        $f = $scale === null ? 1.0 : (float) $scale;
        $ts = $table['scale'];
        $rows = $table[$sex];
        $first = $table['first'];
        $last = $first + count($rows) - 1;
        // one row either side of the span, so the curve reaches its edges
        $kLo = max($first, (int) floor($lo * $ts) - 1);
        $kHi = min($last, (int) ceil($hi * $ts) + 1);
        if ($kHi < $kLo) return null;
        $restricted = $entry['adjust'] === 'who-restricted';
        $keys = range($kLo, $kHi);
        $column = function ($z) use ($keys, $rows, $first, $restricted, $f) {
            return self::column($z, $keys, $rows, $first, $restricted, $f);
        };
        $ys = [];
        foreach ($zs as $z) $ys[] = $column($z);
        // nothing to draw when no curve has a point in the span
        $drawn = false;
        foreach ($keys as $i => $k) {
            $x = $k / $ts;
            if ($x < $lo || $x > $hi) continue;
            foreach ($ys as $col) if ($col[$i] !== null) { $drawn = true; break 2; }
        }
        if (!$drawn) return null;
        $ext = [$column(-self::EXTENT_Z), $column(self::EXTENT_Z)];
        $all = array_merge($ys, $ext);
        $range = self::minRange($keys, $all, $ts, $lo, $hi, max(1, (int) $zoom));
        if ($range === null) return null;
        $tol = self::MAX_ERROR * self::SHARE * $range / self::PLOT_HEIGHT;
        $top = 0.0;
        foreach ($all as $col) foreach ($col as $y) if ($y !== null && abs($y) > $top) $top = abs($y);
        $d = (int) ceil(-log10($tol / 4));
        $d = max(0, min(self::MAX_DECIMALS, $d));
        while ($d > 0 && $top * pow(10, $d) > self::MAX_UNITS) $d--;
        if ($top > self::MAX_UNITS) {
            return ['why' => 'the curves of "' . $entry['id'] . '" reach values too large to send (above ' . number_format(self::MAX_UNITS, 0, '.', ',')
                . ' in the field\'s unit). The check itself is not affected.'];
        }
        $points = 0;
        $lines = [];
        foreach ($ys as $col) $lines[] = self::segments($keys, $col, $tol, $d, $points);
        $extent = [];
        foreach ($ext as $side => $col) {
            $extent[] = self::covered($col, $ys, $side === 0) ? null : self::segments($keys, $col, $tol, $d, $points);
        }
        if ($points > self::MAX_SET_POINTS) {
            return ['why' => 'the curves of "' . $entry['id'] . '" need ' . $points . ' points to be drawn to within half a unit, more than the '
                . self::MAX_SET_POINTS . ' one chart may send (the table\'s rows are not smooth enough). '
                . 'The check itself is not affected.'];
        }
        return ['d' => $d, 'tolerance' => $tol, 'lines' => $lines, 'extent' => $extent];
    }

    /**
     * The curve at z-score $z, at each row key of $keys, in the field's unit
     * (divided by $f); null where valueAt() has none.
     *
     * PRF-7. A chart evaluates its curves at every row of its span, so the
     * LMS formula is written out here for the common case, with the same
     * operations in the same order as GrowthReference::valueAt() and sdAt(),
     * so each value is the same float (tests/growth_curve_php.php compares
     * every bundled table). WHO's restricted adjustment beyond 3 SD still goes
     * through valueAt(). This halved the time to build a chart's curves.
     */
    private static function column($z, array $keys, array $rows, $first, $restricted, $f)
    {
        $col = [];
        $z = (float) $z;
        if ($restricted && ($z > 3 || $z < -3)) {
            foreach ($keys as $k) {
                $v = GrowthReference::valueAt($z, $rows[$k - $first], $restricted);
                $col[] = $v === null ? null : $v / $f;
            }
            return $col;
        }
        $minL = GrowthReference::MIN_L;
        foreach ($keys as $k) {
            list($l, $m, $s) = $rows[$k - $first];
            if (abs($l) < $minL) {
                $y = $m * exp($s * $z);
            } else {
                $b = 1 + $l * $s * $z;
                $y = $b > 0 ? $m * pow($b, 1 / $l) : NAN;
            }
            $col[] = (is_finite($y) && $y > 0) ? $y / $f : null;
        }
        return $col;
    }

    /**
     * One curve as segments: each run of rows with a value, simplified to
     * within $tol and written as [key, units, key, units, ...]. Adds the
     * points kept to $points.
     */
    private static function segments(array $keys, array $col, $tol, $d, &$points)
    {
        $n = count($keys);
        $out = [];
        for ($a = 0; $a < $n; $a = $b + 1) {
            while ($a < $n && $col[$a] === null) $a++;
            if ($a >= $n) break;
            for ($b = $a; $b + 1 < $n && $col[$b + 1] !== null; $b++);
            $flat = [];
            foreach (self::simplify($keys, $col, $a, $b, $tol) as $i) {
                $flat[] = $keys[$i];
                $flat[] = self::units($col[$i], $d);
            }
            $points += count($flat) / 2;
            $out[] = $flat;
        }
        return $out;
    }

    /**
     * Whether the curves $ys reach $ext at every row where $ext has a value:
     * some curve at or below it ($low), or at or above it. The y axis then
     * spans $ext without it.
     */
    private static function covered(array $ext, array $ys, $low)
    {
        foreach ($ext as $i => $e) {
            if ($e === null) continue;
            $hit = false;
            foreach ($ys as $col) {
                $y = $col[$i];
                if ($y !== null && ($low ? $y <= $e : $y >= $e)) { $hit = true; break; }
            }
            if (!$hit) return false;
        }
        return true;
    }

    /**
     * The smallest y range the chart can give a window of 1/$zoom of the
     * span: over every such window inside [lo, hi], the curves' highest value
     * less their lowest, or Y_FLOOR of the highest when that is more. Windows
     * start at rows and are one row narrower than the chart's, so each window
     * the chart shows holds one of them and spans at least its range. Null
     * when no curve has a point in the span.
     */
    private static function minRange(array $keys, array $ys, $ts, $lo, $hi, $zoom)
    {
        $n = count($keys);
        $xs = [];
        $tops = [];
        $bots = [];
        for ($i = 0; $i < $n; $i++) {
            $x = $keys[$i] / $ts;
            if ($x < $lo || $x > $hi) continue;
            $t = null;
            $b = null;
            foreach ($ys as $col) {
                $y = $col[$i];
                if ($y === null) continue;
                if ($t === null || $y > $t) $t = $y;
                if ($b === null || $y < $b) $b = $y;
            }
            if ($t === null) continue;
            $xs[] = $x;
            $tops[] = $t;
            $bots[] = $b;
        }
        $m = count($xs);
        if (!$m) return null;
        $w = max(0.0, ($hi - $lo) / $zoom - 1 / $ts);
        // two pointers, with the window's highest and lowest kept in deques
        // (a list and the index of its head)
        $best = null;
        $qt = [];
        $qb = [];
        $ht = 0;
        $hb = 0;
        $j = 0;
        for ($i = 0; $i < $m; $i++) {
            while ($j < $m && $xs[$j] <= $xs[$i] + $w + 1e-12) {
                while (count($qt) > $ht && $tops[$qt[count($qt) - 1]] <= $tops[$j]) array_pop($qt);
                $qt[] = $j;
                while (count($qb) > $hb && $bots[$qb[count($qb) - 1]] >= $bots[$j]) array_pop($qb);
                $qb[] = $j;
                $j++;
            }
            while ($qt[$ht] < $i) $ht++;
            while ($qb[$hb] < $i) $hb++;
            $t = $tops[$qt[$ht]];
            $r = max($t - $bots[$qb[$hb]], self::Y_FLOOR * $t);
            if ($best === null || $r < $best) $best = $r;
            // this window reaches the end of the span; one starting later
            // would end past it, which the chart never shows
            if ($j >= $m) break;
        }
        return ($best !== null && $best > 0) ? $best : null;
    }

    /**
     * $y as a count of units of 10^-$d, half away from zero, through
     * GrowthReference::roundedUnits so the payload is the same on every PHP.
     * round() is not: PHP 8.4 dropped its pre-rounding, and a curve point
     * held as 17145.4999... read 17145 there and 17146 on 8.3 and below.
     * A z-score chart's lines go below 0, so the sign is put back after.
     */
    private static function units($y, $d)
    {
        $y = (float) $y;
        return $y < 0 ? -GrowthReference::roundedUnits(-$y, $d) : GrowthReference::roundedUnits($y, $d);
    }

    /**
     * Ramer-Douglas-Peucker on the points ($xs[i], $ys[i]) for i from $first
     * to $last, measuring the vertical distance to the chord, so the error
     * does not depend on how far the chart stretches its x axis. Returns the
     * indices kept, in order; every point left out lies within $tol of the
     * line through the points kept either side of it.
     */
    public static function simplify(array $xs, array $ys, $first, $last, $tol)
    {
        if ($last - $first < 2) return $last > $first ? [$first, $last] : [$first];
        $keep = [$first => true, $last => true];
        $stack = [$first, $last];
        while ($stack) {
            $b = array_pop($stack);
            $a = array_pop($stack);
            if ($b - $a < 2) continue;
            $xa = $xs[$a];
            $ya = $ys[$a];
            $slope = ($ys[$b] - $ya) / ($xs[$b] - $xa);
            $far = -1.0;
            $at = -1;
            for ($k = $a + 1; $k < $b; $k++) {
                $dev = $ys[$k] - $ya - $slope * ($xs[$k] - $xa);
                if ($dev < 0) $dev = -$dev;
                if ($dev > $far) {
                    $far = $dev;
                    $at = $k;
                }
            }
            if ($far > $tol) {
                $keep[$at] = true;
                array_push($stack, $a, $at, $at, $b);
            }
        }
        $out = array_keys($keep);
        sort($out);
        return $out;
    }

    /**
     * The key config.growthCurves holds a chart's curves under: the
     * reference, the measure scale, the span and the curves (the chart's
     * lines, then the soft limits a band is drawn between), so charts that
     * draw the same curves share one copy.
     */
    public static function key($id, $scale, array $span, $kind, array $values, ?array $band)
    {
        return $id . '|' . ($scale === null ? '1' : self::numText($scale)) . '|' . self::numText(self::fmt($span[0])) . ','
            . self::numText(self::fmt($span[1])) . '|' . $kind . ':' . implode(',', $values)
            . '|band:' . ($band === null ? '' : implode(',', array_map(function ($b) { return $b === null ? '' : $b; }, $band)));
    }

    /** A float as plain decimal text (no exponent). */
    private static function fmt($x)
    {
        $t = rtrim(rtrim(sprintf('%.6F', (float) $x), '0'), '.');
        return $t === '' || $t === '-' ? '0' : $t;
    }

    /**
     * The rows of a table of reference values for one sex: one per axis value
     * of curveXs(), a text per column, with a decimal point (the browser
     * writes the field's decimal mark). $columns are catalog keys of the
     * column shape that need no measurement; $ctx holds the rule's
     * 'limits' (['lo', 'hi'], the "returnLimits" tier), 'decimals',
     * 'percentileDecimals', 'comma' (the measured field's decimal mark, for
     * "range"), 'scale' and 'unit'. A cell with nothing to show is "".
     */
    public static function tableRows(array $entry, array $table, $sex, $span, $step, array $columns, array $ctx)
    {
        $rows = [];
        $limits = isset($ctx['limits']) ? $ctx['limits'] : ['lo' => null, 'hi' => null];
        $dec = isset($ctx['decimals']) ? (int) $ctx['decimals'] : 1;
        $pd = isset($ctx['percentileDecimals']) ? (int) $ctx['percentileDecimals'] : GrowthReference::DEFAULT_PERCENTILE_DECIMALS;
        $scale = isset($ctx['scale']) ? $ctx['scale'] : null;
        $unit = isset($ctx['unit']) ? $ctx['unit'] : null;
        $comma = !empty($ctx['comma']);
        $parsed = [];
        $bounds = false;
        foreach ($columns as $c) {
            $p = GrowthOutputs::parse($c, '"show" "table" "columns"', 'column', 'entry');
            $parsed[] = $p;
            if (isset($p['of']) && in_array($p['of'], ['lower', 'upper', 'range'], true)) $bounds = true;
        }
        foreach (self::curveXs($entry, $span, $step) as $i => $x) {
            if ($i >= self::MAX_CURVE_ROWS) break;
            $o = $bounds ? GrowthReference::outputs($entry, $table, $sex, $x, null, $limits, $dec, $comma, $scale, $unit) : [];
            $row = [];
            foreach ($parsed as $p) {
                if (!isset($p['of'])) { $row[] = ''; continue; }
                if (array_key_exists($p['of'], $o)) { $row[] = $o[$p['of']] === null ? '' : $o[$p['of']]; continue; }
                $spec = GrowthOutputs::spec($p['of']);
                $places = $spec['places'] === 'percentileDecimals' ? $pd : ($spec['places'] === 'z' ? 2 : $dec);
                $t = GrowthReference::output($entry, $table, $sex, $x, null, $p['of'], $p['param'], $places, $scale);
                $row[] = $t === null ? '' : $t;
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
