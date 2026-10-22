<?php
namespace INSPIRE\UniversalValidator;
require_once __DIR__ . '/Logic.php';
require_once __DIR__ . '/TemporalValue.php';

/**
 * Growth references for @UVRANGE: LMS tables (WHO, CDC or any other) and the
 * z-score of a measurement against them.
 *
 * The tables live in data/references (bundled) and, optionally, in a folder
 * an administrator names in the module's system settings. Each folder has an
 * index.json ("uv-references-1") listing its references; a reference in the
 * administrator's folder replaces a bundled one of the same id. Each table is
 * a "uv-lms-1" file whose SHA-256 the index pins. data/references/README.md
 * says how to add one; data/references/convert.php writes the table.
 *
 * The z-score follows the WHO method (WHO Child Growth Standards, 2006,
 * chapter 7): z = ((y/M)^L - 1) / (S*L), and for the references marked
 * "adjust": "who-restricted" the distance beyond +-3 SD is measured in units
 * of the distance between 2 and 3 SD at that point. It is rounded to two
 * decimals, half away from zero. The browser twin is QRID_growth* in
 * js/engine.js; tests/growth_fixture.json and tests/who_golden.json pin both.
 */
final class GrowthReference
{
    const INDEX_FORMAT = 'uv-references-1';
    const TABLE_FORMAT = 'uv-lms-1';
    /** Days in a month, as WHO counts them. */
    const DAYS_PER_MONTH = 30.4375;
    /** A table file larger than this is refused before it is read. */
    const MAX_TABLE_BYTES = 4194304;
    /** References one page may carry tables for, unless index.json says otherwise. */
    const PAGE_TABLES = 4;
    /** Curve sets of growth charts (and curve tables) one page may carry, unless index.json says otherwise. */
    const PAGE_CHARTS = 4;
    /** The largest z-score zText writes (see there). */
    const MAX_Z_TEXT = 999.99;
    /** The largest axis value a "valid" bound may name (days, months or cm). */
    const MAX_AXIS = 1000000;
    /** The smallest S, and the smallest L other than 0, a table row may hold. */
    const MIN_S = 0.0001;
    const MIN_L = 0.000001;
    /** A z-score bound further out than this limits nothing: limits lie within +-20. */
    const BOUND_LIMIT = 21;
    /** The most decimals a returned bound may be written with. */
    const MAX_DECIMALS = 6;
    /** The most decimals, and the default, a returned percentile is written with ("percentileDecimals"). */
    const MAX_PERCENTILE_DECIMALS = 3;
    const DEFAULT_PERCENTILE_DECIMALS = 1;
    /**
     * The outputs this class computes: outputs() makes the first four,
     * output() the rest. GrowthOutputs refuses a catalog entry
     * (php/growth_outputs.json) that is not here, so a value is never declared
     * without the code that makes it. Twin: QRID_GROWTH_COMPUTED.
     */
    const COMPUTED = ['z', 'lower', 'upper', 'range', 'percentile', 'valueAtZ', 'valueAtPercentile', 'age', 'value'];
    /**
     * A bound, or a value at a z-score or percentile, counted in more units
     * than this is not written: both runtimes hold such a count exactly, far
     * below 2^53, and roundedUnits() keeps 15 significant digits.
     */
    const MAX_BOUND_UNITS = 1e15;
    /**
     * How far from its limit, in z, a bound is looked for. A two-decimal
     * z-score moves the edge of a limit by less than 0.01; the first offset
     * that a measurement scores brackets it, the smaller ones serve a limit
     * near a row's ceiling or floor.
     */
    const BOUND_BRACKETS = [0.02, 0.01, 0.006];

    const AXES = ['age' => ['days', 'months'], 'length' => ['cm'], 'height' => ['cm']];
    const LOOKUPS = ['round', 'floor', 'linear'];
    /**
     * How a growth chart counts an age axis ("chartUnit", optional): "auto"
     * (the default) counts months while the chart shows under two years and
     * years beyond; the others count that unit always, such as "weeks" for a
     * reference by gestational age. Twin: QRID_chartXUnit.
     */
    const CHART_UNITS = ['auto', 'days', 'weeks', 'months', 'years'];
    const ADJUSTS = ['none', 'who-restricted'];
    /**
     * The code of a \RuntimeException from table() whose file is there and
     * could not be READ, as opposed to one that was read and is wrong, or is
     * not there (W10BF-4, review rvc2-3). A scan
     * asks such a read again rather than judge the rule a configuration
     * error (UniversalValidator::annotateRangeDictionary).
     */
    const E_UNREAD = 1;
    /** A reference id: 1 to 64 lower-case letters, digits, ".", "_" or "-". */
    const ID_RE = '/^[a-z0-9][a-z0-9_.-]{0,63}$/D';

    private static $catalogMemo = [];
    private static $tableMemo = [];
    private static $boundsMemo = [];

    /** The bundled reference folder. */
    public static function bundledDir()
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'references';
    }

    /**
     * Every reference the module knows: the bundled ones, then the ones in
     * $extraDir, which add to them or replace them by id.
     *
     * An entry of $extraDir that cannot be used removes the bundled one with
     * the same id instead of leaving it in place, and a folder whose index
     * cannot be read removes every reference: the administrator meant to
     * change something, and judging against the table they meant to replace,
     * with no sign of it, is worse than a configuration error.
     *
     * An entry whose "source" names an entry of the index's "sources" with a
     * "title" gets it as 'sourceTitle', which the growth chart shows.
     *
     * 'unread' names a folder whose index could not be READ - an index.json
     * that is there and would not open - rather than one that was read and
     * is wrong, or is not there at all (W10BF-4, review rvc2-3). Either way
     * the folder's references are gone; only a read that failed may be asked
     * again.
     *
     * @return array ['references' => [id => entry + ['dir' => folder]],
     *                'pageTables' => int, 'pageCharts' => int,
     *                'problems' => [string, ...], 'refused' => [id => why],
     *                'broken' => why|null, 'unread' => why|null]
     */
    public static function catalog($extraDir = null)
    {
        $extraDir = is_string($extraDir) ? trim($extraDir) : '';
        if (isset(self::$catalogMemo[$extraDir])) return self::$catalogMemo[$extraDir];
        $out = ['references' => [], 'pageTables' => self::PAGE_TABLES, 'pageCharts' => self::PAGE_CHARTS,
                'problems' => [], 'refused' => [], 'broken' => null, 'unread' => null];
        $dirs = [['dir' => self::bundledDir(), 'what' => 'the bundled references', 'extra' => false]];
        if ($extraDir !== '') $dirs[] = ['dir' => rtrim($extraDir, '/\\'), 'what' => 'the reference folder in the module settings', 'extra' => true];
        foreach ($dirs as $d) {
            $index = self::readIndex($d['dir']);
            if (isset($index['error'])) {
                $why = $d['what'] . ': ' . $index['error'];
                $out['problems'][] = $why;
                if ($d['extra']) {
                    $out['broken'] = $why;
                    $out['references'] = [];
                }
                if (!empty($index['unread']) && $out['unread'] === null) $out['unread'] = $why;
                continue;
            }
            foreach (['pageTables', 'pageCharts'] as $cap) {
                if (isset($index[$cap])) $out[$cap] = $index[$cap];
            }
            // a cap out of range keeps the default: it limits what a page
            // carries, and is no reason to stop every reference
            foreach ($index['capProblems'] as $why) $out['problems'][] = $d['what'] . ': ' . $why;
            foreach ($index['references'] as $id => $e) {
                $why = self::entryProblem($id, $e);
                if ($why !== null) {
                    $why = $d['what'] . ': reference "' . $id . '" ' . $why;
                    $out['problems'][] = $why;
                    unset($out['references'][(string) $id]);
                    $out['refused'][(string) $id] = $why;
                    continue;
                }
                $e['dir'] = $d['dir'];
                $e['id'] = $id;
                unset($e['sourceTitle']);
                $src = isset($e['source']) && is_string($e['source']) && isset($index['sources'][$e['source']]['title'])
                    ? $index['sources'][$e['source']]['title'] : null;
                if (is_string($src) && trim($src) !== '' && self::oneLine($src)) $e['sourceTitle'] = trim($src);
                $out['references'][$id] = $e;
            }
        }
        return self::$catalogMemo[$extraDir] = $out;
    }

    /** One reference by id, or null. */
    public static function entry($id, $extraDir = null)
    {
        $c = self::catalog($extraDir);
        return (is_string($id) && isset($c['references'][$id])) ? $c['references'][$id] : null;
    }

    /**
     * Why reference $id is not available although it may be meant to be: the
     * extra folder cannot be read, or its entry for $id is broken. Null when
     * neither applies (the id is available, or simply unknown).
     */
    public static function unavailableWhy($id, $extraDir = null)
    {
        $c = self::catalog($extraDir);
        if ($c['broken'] !== null) return $c['broken'];
        return (is_string($id) && isset($c['refused'][$id])) ? $c['refused'][$id] : null;
    }

    /** Forget what was read (tests). */
    public static function reset()
    {
        self::$catalogMemo = [];
        self::$tableMemo = [];
        self::$boundsMemo = [];
    }

    private static function stripBom($raw)
    {
        return strncmp($raw, "\xEF\xBB\xBF", 3) === 0 ? (string) substr($raw, 3) : $raw;
    }

    private static function readIndex($dir)
    {
        $path = $dir . DIRECTORY_SEPARATOR . 'index.json';
        // The folder's path stays out of the text: it reaches the page as part
        // of a configuration error, and the administrator knows the setting.
        //
        // NO FOLDER, OR NO index.json IN IT, IS THE CONFIGURATION (review
        // rvc2-3). W10BF-4 made both "could not be read just now", so while an
        // administrator's setting named a folder that is not there, every
        // project with a reference @UVRANGE rule was refused Start and every
        // work request did nothing, and on a project whose rules are all of
        // that kind the run could not even be stopped. The setting says
        // where the references are; a place with none is what it says, and
        // the rule is a rule problem, as before W10BF-4. Only a file that is
        // there and will not open is a read that failed, and asked again.
        if (!is_file($path)) return ['error' => 'the folder has no index.json.'];
        if (!is_readable($path)) return ['error' => 'the folder\'s index.json could not be opened.', 'unread' => true];
        $raw = @file_get_contents($path);
        // A file that would not open is not one that holds bad JSON.
        if (!is_string($raw)) return ['error' => 'the folder\'s index.json could not be opened.', 'unread' => true];
        // a byte-order mark, as Windows Notepad saves UTF-8, is not JSON
        $j = is_string($raw) ? json_decode(self::stripBom($raw), true) : null;
        if (!is_array($j)) return ['error' => 'index.json is not valid JSON.'];
        if (!isset($j['format']) || $j['format'] !== self::INDEX_FORMAT) {
            return ['error' => 'index.json must say "format": "' . self::INDEX_FORMAT . '".'];
        }
        if (!isset($j['references']) || !is_array($j['references'])) return ['error' => 'index.json has no "references".'];
        $out = ['references' => $j['references'], 'sources' => (isset($j['sources']) && is_array($j['sources'])) ? $j['sources'] : [],
                'capProblems' => []];
        foreach (['pageTables' => self::PAGE_TABLES, 'pageCharts' => self::PAGE_CHARTS] as $cap => $default) {
            if (!array_key_exists($cap, $j)) continue;
            if (!is_int($j[$cap]) || $j[$cap] < 0 || $j[$cap] > 50) {
                $out['capProblems'][] = '"' . $cap . '" must be a whole number from 0 to 50, so the default (' . $default . ') applies.';
                continue;
            }
            $out[$cap] = $j[$cap];
        }
        return $out;
    }

    /**
     * Whether $s is text for one line: no control character, line or
     * paragraph separator, or bidirectional override (which would reorder
     * the text around it). $s must be valid UTF-8 (json_decode makes sure).
     */
    public static function oneLine($s)
    {
        return is_string($s)
            && preg_match('/[\x00-\x1f\x7f\x{80}-\x{9f}\x{2028}\x{2029}\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', $s) === 0;
    }

    /** Why an index entry cannot be used, or null. */
    private static function entryProblem($id, $e)
    {
        if (!is_string($id) || !preg_match(self::ID_RE, $id)) {
            return 'has an id that is not 1 to 64 lower-case letters, digits, ".", "_" or "-".';
        }
        if (!is_array($e)) return 'is not an object.';
        foreach (['title', 'file', 'sha256', 'measure', 'unit', 'axis', 'axisUnit', 'lookup', 'adjust'] as $k) {
            if (!isset($e[$k]) || !is_string($e[$k]) || $e[$k] === '') return 'needs "' . $k . '".';
        }
        if (!preg_match('~^(?!/)(?!.*(?:^|/)\.\.?(?:/|$))[A-Za-z0-9_./-]{1,200}\.json$~D', $e['file'])) {
            return 'has a "file" that is not a .json path inside its folder.';
        }
        if (!preg_match('/^[0-9a-f]{64}$/D', $e['sha256'])) return 'needs "sha256" as 64 lower-case hex digits.';
        if (!isset(self::AXES[$e['axis']])) return 'has "axis" "' . $e['axis'] . '"; it must be age, length or height.';
        if (!in_array($e['axisUnit'], self::AXES[$e['axis']], true)) {
            return 'has "axisUnit" "' . $e['axisUnit'] . '"; with axis ' . $e['axis'] . ' it must be ' . implode(' or ', self::AXES[$e['axis']]) . '.';
        }
        if (array_key_exists('chartUnit', $e)) {
            if ($e['axis'] !== 'age') return 'has "chartUnit", which only an age reference takes.';
            if (!in_array($e['chartUnit'], self::CHART_UNITS, true)) {
                return 'has "chartUnit" ' . json_encode($e['chartUnit']) . '; it must be ' . implode(', ', self::CHART_UNITS) . '.';
            }
        }
        if (!in_array($e['lookup'], self::LOOKUPS, true)) return 'has "lookup" "' . $e['lookup'] . '"; it must be round, floor or linear.';
        if (!in_array($e['adjust'], self::ADJUSTS, true)) return 'has "adjust" "' . $e['adjust'] . '"; it must be none or who-restricted.';
        if (!isset($e['valid']) || !is_array($e['valid'])) return 'needs "valid": {"min": ..., "max" or "below": ...}.';
        $v = $e['valid'];
        // array_key_exists, not isset: "max": null is a key the browser sees
        // (QRID_growthAxis tests "!== undefined"), so it must be refused here.
        if (!array_key_exists('min', $v) || !self::isNum($v['min'])) return 'needs "valid" "min" as a number in a string.';
        $hasMax = array_key_exists('max', $v);
        if ($hasMax === array_key_exists('below', $v)) return 'needs exactly one of "valid" "max" and "below".';
        $hi = $hasMax ? $v['max'] : $v['below'];
        if (!self::isNum($hi)) return 'needs "valid" "' . ($hasMax ? 'max' : 'below') . '" as a number in a string.';
        if ((float) $hi < (float) $v['min']) return 'has a "valid" range that ends before it starts.';
        // row keys are axis value times "scale" (at most 1000) as whole numbers
        if (abs((float) $v['min']) > self::MAX_AXIS || abs((float) $hi) > self::MAX_AXIS) {
            return 'has a "valid" bound beyond ' . self::MAX_AXIS . ' (or -' . self::MAX_AXIS . ').';
        }
        return null;
    }

    private static function isNum($s)
    {
        return is_string($s) && preg_match(Logic::NUM_RE, $s);
    }

    /**
     * The rows of a reference, read once per request and checked against the
     * index's SHA-256, and against the entry's "valid" range on every call:
     * two entries may share one file with different ranges or lookups.
     * Throws \RuntimeException with a sentence a designer can act on.
     *
     * @return array ['scale' => int, 'first' => int, 'male' => [[L,M,S], ...], 'female' => [...]]
     */
    public static function table(array $entry)
    {
        $key = self::fileKey($entry);
        if (!isset(self::$tableMemo[$key])) self::$tableMemo[$key] = self::readTable($entry);
        $out = self::$tableMemo[$key];
        $gap = self::coverProblem($entry, $out);
        if ($gap !== null) throw new \RuntimeException('the table ' . $entry['file'] . ' ' . $gap);
        return $out;
    }

    /** The memo key of an entry's table file: its path and pinned SHA-256. */
    private static function fileKey(array $entry)
    {
        return $entry['dir'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['file']) . '|' . $entry['sha256'];
    }

    private static function readTable(array $entry)
    {
        $path = $entry['dir'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['file']);
        // A table its index names that is not there is the folder as the
        // administrator left it, as a missing index.json is (review rvc2-3);
        // one that is there and will not open is a read that failed.
        if (!is_file($path)) throw new \RuntimeException('the table ' . $entry['file'] . ' of "' . $entry['id'] . '" is not in its folder.');
        $size = @filesize($path);
        if ($size === false) throw new \RuntimeException('the table ' . $entry['file'] . ' of "' . $entry['id'] . '" cannot be read.', self::E_UNREAD);
        if ($size > self::MAX_TABLE_BYTES) throw new \RuntimeException('the table ' . $entry['file'] . ' is larger than 4 MB.');
        $raw = @file_get_contents($path);
        if (!is_string($raw)) throw new \RuntimeException('the table ' . $entry['file'] . ' of "' . $entry['id'] . '" cannot be read.', self::E_UNREAD);
        if (!hash_equals($entry['sha256'], hash('sha256', $raw))) {
            throw new \RuntimeException('the table ' . $entry['file'] . ' does not match the "sha256" in index.json; it was changed or damaged.');
        }
        // the checksum covers the file as it is; the mark goes only for decoding
        $t = json_decode(self::stripBom($raw), true);
        $bad = function ($why) use ($entry) { return new \RuntimeException('the table ' . $entry['file'] . ' ' . $why); };
        if (!is_array($t) || !isset($t['format']) || $t['format'] !== self::TABLE_FORMAT) throw $bad('is not a "' . self::TABLE_FORMAT . '" table.');
        if (!isset($t['scale']) || !is_int($t['scale']) || $t['scale'] < 1 || $t['scale'] > 1000) throw $bad('needs "scale" as a whole number from 1 to 1000.');
        if (!isset($t['first']) || !is_int($t['first']) || abs($t['first']) > self::MAX_AXIS * 1000) {
            throw $bad('needs "first" as a whole number from -' . (self::MAX_AXIS * 1000) . ' to ' . (self::MAX_AXIS * 1000) . '.');
        }
        foreach (['male', 'female'] as $sex) {
            if (!isset($t[$sex]) || !is_array($t[$sex]) || !$t[$sex] || array_keys($t[$sex]) !== range(0, count($t[$sex]) - 1)) {
                throw $bad('needs a list of "' . $sex . '" rows.');
            }
            foreach ($t[$sex] as $i => $row) {
                if (!is_array($row) || count($row) !== 3 || array_keys($row) !== [0, 1, 2]) throw $bad('row ' . $i . ' of "' . $sex . '" is not [L, M, S].');
                foreach ($row as $j => $p) {
                    // 1e400 decodes as INF, which json_encode cannot write into the page
                    if ((!is_int($p) && !is_float($p)) || !is_finite((float) $p)) {
                        throw $bad('row ' . $i . ' of "' . $sex . '" holds something that is not a finite number.');
                    }
                    $t[$sex][$i][$j] = (float) $p;
                }
                if ($t[$sex][$i][1] <= 0 || $t[$sex][$i][2] <= 0) throw $bad('row ' . $i . ' of "' . $sex . '" has M or S at or below 0.');
                // A tiny S or L (rounding noise where L is 0) makes L*S vanish:
                // the z-score divides by it, and the bounds of limitProblem() blow up.
                if ($t[$sex][$i][2] < self::MIN_S) throw $bad('row ' . $i . ' of "' . $sex . '" has an S below ' . self::MIN_S . '.');
                $l = abs($t[$sex][$i][0]);
                if ($l > 0 && $l < self::MIN_L) {
                    throw $bad('row ' . $i . ' of "' . $sex . '" has an L of ' . $t[$sex][$i][0] . '; write an L of 0 as 0 (any other L must be at least '
                        . number_format(self::MIN_L, 6) . ' in size).');
                }
            }
        }
        return ['scale' => $t['scale'], 'first' => $t['first'], 'male' => $t['male'], 'female' => $t['female']];
    }

    /**
     * A sentence when the rows do not reach every axis position "valid"
     * admits, under the entry's lookup, for both sexes; null when they do.
     * Without this check a position inside "valid" with no row gets no
     * z-score: a rule problem on the server and silence on the page.
     */
    private static function coverProblem(array $entry, array $t)
    {
        list($first, $last) = self::neededRows($entry, $t['scale']);
        foreach (['male', 'female'] as $sex) {
            $end = $t['first'] + count($t[$sex]) - 1;
            if ($first < $t['first'] || $last > $end) {
                return 'has "' . $sex . '" rows ' . $t['first'] . ' to ' . $end . ', but its "valid" range needs rows '
                    . $first . ' to ' . $last . ' (a row is 1/' . $t['scale'] . ' ' . $entry['axisUnit'] . ').';
            }
        }
        return null;
    }

    /**
     * The first and last row lms() can read for an axis value inRange()
     * admits: the rows at the smallest and the largest such value, found with
     * lms()'s own arithmetic (position()). The largest value under an
     * exclusive "below" is the double just under it, so the floating-point
     * product with "scale" lands exactly where a typed value would.
     *
     * @return int[] [first, last]
     */
    private static function neededRows(array $entry, $scale)
    {
        $v = $entry['valid'];
        $xHi = array_key_exists('max', $v) ? (float) $v['max'] : self::justBelow((float) $v['below']);
        $first = self::position($entry['lookup'], $scale, (float) $v['min'])[0];
        list($key, $diff) = self::position($entry['lookup'], $scale, $xHi);
        return [$first, $diff > 0 ? $key + 1 : $key];
    }

    /** The largest double below $f. */
    private static function justBelow($f)
    {
        if ($f == 0) return -4.9406564584124654E-324;
        // the bits of a double, as a signed 64-bit integer, count in the
        // direction of its magnitude: one step down is -1 above 0, +1 below
        $bits = unpack('q', pack('d', $f))[1];
        $bits += $f > 0 ? -1 : 1;
        return unpack('d', pack('q', $bits))[1];
    }

    /**
     * Where lms() reads axis value $x: the row key, and for "linear" the
     * share of the way to the next row (0 for "round" and "floor"). The one
     * place this arithmetic lives, so that neededRows() agrees with lms().
     *
     * @return array [int key, float share]
     */
    private static function position($lookup, $scale, $x)
    {
        if ($lookup === 'round') {
            $k = $x * $scale;
            $f = floor($k);
            return [(int) ($k - $f >= 0.5 ? $f + 1 : $f), 0.0];
        }
        $key = (int) floor($x * $scale);
        if ($lookup === 'floor') return [$key, 0.0];
        // linear, as WHO's own code does it: low = trunc(x*scale)/scale and
        // the share of the way to the next row is (x - low) / (1/scale).
        return [$key, ($x - $key / $scale) / (1 / $scale)];
    }

    /**
     * A sentence when a z-score limit of a rule can never be passed with this
     * reference somewhere in its "valid" range; null when every limit can.
     * $limits: ['soft' => [lo, hi], 'hard' => [lo, hi]], each a number as
     * text or null (open).
     *
     * The z-score of a measurement above 0 has a floor or a ceiling for some
     * rows. Without "adjust", a row with L above 0 never scores below
     * -1/(L*S), and one with L below 0 never above 1/(|L|*S); WHO's
     * restricted method (beyond -3 SD) never scores below -3 - q/(1 - q),
     * with q = SD-3 / SD-2. A limit past that point is never crossed, so an
     * absurd value, 0.001 kg or a BMI of 1,000, would pass that tier: the
     * rule is refused instead. With b the bound plus 0.01, rounded away from
     * it to hundredths, a low limit must lie above b - 0.01 and a high limit
     * below b + 0.01: a two-decimal z-score of b can then still fall beyond
     * it. A limit of b itself, or of b to three decimals, is accepted.
     */
    public static function limitProblem(array $entry, array $table, array $limits)
    {
        $b = self::bounds($entry, $table);
        foreach (['hard', 'soft'] as $tier) {
            $lo = isset($limits[$tier][0]) ? (string) $limits[$tier][0] : null;
            $hi = isset($limits[$tier][1]) ? (string) $limits[$tier][1] : null;
            if ($lo !== null && $b['floor'] !== null) {
                // In hundredths: a score lands at or below "reach" only when the
                // bound is 0.01 or more away (rounded to 1e-6 first: -19.99 * 100
                // is -1998.9999999999998), so a limit above reach - 0.01 can be passed.
                $reach = (int) ceil(round(($b['floor']['z'] + 0.01) * 100, 6));
                $edge = self::hundredths($reach - 1);
                if (Logic::numCompare($lo, $edge) <= 0) {
                    return 'its "' . $tier . '" low limit ' . $lo . ' is out of reach: with "' . $entry['id'] . '" no measurement'
                        . ' scores below ' . self::zText($b['floor']['z'] + 1e-9) . ' ' . self::where($entry, $table, $b['floor'])
                        . ', so a low limit must be above ' . $edge . '.';
                }
            }
            if ($hi !== null && $b['ceil'] !== null) {
                $reach = (int) floor(round(($b['ceil']['z'] - 0.01) * 100, 6));
                $edge = self::hundredths($reach + 1);
                if (Logic::numCompare($hi, $edge) >= 0) {
                    return 'its "' . $tier . '" high limit ' . $hi . ' is out of reach: with "' . $entry['id'] . '" no measurement'
                        . ' scores above ' . self::zText($b['ceil']['z'] - 1e-9) . ' ' . self::where($entry, $table, $b['ceil'])
                        . ', so a high limit must be below ' . $edge . '.';
                }
            }
        }
        return null;
    }

    /** A whole number of hundredths as text: -696 => "-6.96". */
    private static function hundredths($n)
    {
        $a = abs($n);
        return ($n < 0 ? '-' : '') . intdiv($a, 100) . '.' . str_pad((string) ($a % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "at 1500 days (female)": where a bound from bounds() lies. */
    private static function where(array $entry, array $table, array $at)
    {
        return 'at ' . ($at['key'] / $table['scale']) . ' ' . $entry['axisUnit'] . ' (' . $at['sex'] . ')';
    }

    /**
     * The highest z-score floor and the lowest ceiling over the rows the
     * entry's "valid" range reads, for both sexes: ['floor' => ['z', 'sex',
     * 'key'] or null, 'ceil' => same]. A "linear" lookup also reads between
     * rows; there the bound moves smoothly from one row's to the next one's,
     * which the 0.01 margin of limitProblem() covers (tests/growth_php.php
     * samples the bundled linear tables between rows).
     */
    private static function bounds(array $entry, array $table)
    {
        list($first, $last) = self::neededRows($entry, $table['scale']);
        $memo = self::fileKey($entry) . '|' . $entry['adjust'] . '|' . $first . '|' . $last;
        if (isset(self::$boundsMemo[$memo])) return self::$boundsMemo[$memo];
        $out = ['floor' => null, 'ceil' => null];
        foreach (['male', 'female'] as $sex) {
            for ($key = $first; $key <= $last; $key++) {
                $row = $table[$sex][$key - $table['first']];
                list($floor, $ceil) = self::zRange($row[0], $row[2], $entry['adjust'] === 'who-restricted');
                if ($floor !== null && ($out['floor'] === null || $floor > $out['floor']['z'])) {
                    $out['floor'] = ['z' => $floor, 'sex' => $sex, 'key' => $key];
                }
                if ($ceil !== null && ($out['ceil'] === null || $ceil < $out['ceil']['z'])) {
                    $out['ceil'] = ['z' => $ceil, 'sex' => $sex, 'key' => $key];
                }
            }
        }
        return self::$boundsMemo[$memo] = $out;
    }

    /**
     * The z-scores a measurement above 0 can get from a row with these L and
     * S (M plays no part): [floor or null, ceiling or null], both exclusive.
     * Twin of zRaw(): without "adjust" the formula alone; with WHO's
     * restriction, a row whose plain z-score can pass -3 (or +3) is read
     * linearly beyond it, which has no ceiling and, towards 0, the floor
     * -3 - SD-3 / (SD-2 - SD-3).
     */
    private static function zRange($l, $s, $restricted)
    {
        $ls = $l * $s;
        $floor = ($l > 0 && $ls > 0) ? -1 / $ls : null;
        $ceil = ($l < 0 && $ls < 0) ? -1 / $ls : null;
        if ($restricted) {
            if ($ceil !== null && $ceil > 3) $ceil = null;
            if ($floor === null || $floor < -3) {
                $q = $l == 0 ? exp(-$s) : pow((1 - 3 * $ls) / (1 - 2 * $ls), 1 / $l);
                $floor = (is_finite($q) && $q > 0 && $q < 1) ? -3 - $q / (1 - $q) : null;
            }
        }
        // a bound past every allowed limit (AnnotationRules::MAX_GROWTH_Z) limits nothing
        $keep = function ($b) { return ($b !== null && is_finite($b) && abs($b) <= self::BOUND_LIMIT) ? $b : null; };
        return [$keep($floor), $keep($ceil)];
    }

    /**
     * What a page needs of a reference to compute z-scores in the browser: the
     * index fields QRID_growth* reads, and the rows.
     *
     * $need limits the rows to what the page's rules can read (pageNeed):
     * 'sexes' => ['male' => bool, 'female' => bool] sends the other sex's rows
     * empty, and 'xs' => [axis value, ...] sends only the rows lms() reads at
     * those values, one more either side, with "first" moved to match; 'xs'
     * null sends every row. Without $need the whole table travels.
     */
    public static function pageCopy(array $entry, array $table, ?array $need = null)
    {
        $out = [];
        foreach (['title', 'measure', 'unit', 'axis', 'axisUnit', 'lookup', 'valid', 'adjust'] as $k) $out[$k] = $entry[$k];
        if (isset($entry['chartUnit']) && $entry['chartUnit'] !== 'auto') $out['chartUnit'] = $entry['chartUnit'];
        foreach (['scale', 'first', 'male', 'female'] as $k) $out[$k] = $table[$k];
        if ($need === null) return $out;
        if (isset($need['xs']) && is_array($need['xs'])) {
            $lo = null;
            $hi = null;
            foreach ($need['xs'] as $x) {
                list($key, $share) = self::position($entry['lookup'], $table['scale'], (float) $x);
                $top = $share > 0 ? $key + 1 : $key;
                $lo = $lo === null ? $key : min($lo, $key);
                $hi = $hi === null ? $top : max($hi, $top);
            }
            foreach (['male', 'female'] as $s) {
                $rows = $table[$s];
                if ($lo === null) { $out[$s] = []; continue; }
                $a = max(0, $lo - 1 - $table['first']);
                $b = min(count($rows) - 1, $hi + 1 - $table['first']);
                $out[$s] = $b >= $a ? array_slice($rows, $a, $b - $a + 1) : [];
            }
            if ($lo !== null) $out['first'] = $table['first'] + max(0, $lo - 1 - $table['first']);
        }
        foreach (['male', 'female'] as $s) {
            if (isset($need['sexes']) && empty($need['sexes'][$s])) $out[$s] = [];
        }
        return $out;
    }

    /**
     * How much of a page's table cap one copy uses: every row of both sexes
     * is 1, every row of one sex 0.5, and rows limited to a few axis values
     * (pageCopy 'xs') nothing.
     */
    public static function pageWeight(array $need)
    {
        if (isset($need['xs']) && is_array($need['xs'])) return 0.0;
        $sexes = isset($need['sexes']) ? $need['sexes'] : ['male' => true, 'female' => true];
        return 0.5 * ((empty($sexes['male']) ? 0 : 1) + (empty($sexes['female']) ? 0 : 1));
    }

    /**
     * Where on the reference's axis a measurement sits, from the rule's
     * inputs. $in holds what the rule supplied: 'days' or 'months' (a number
     * as text), 'dobDays' and 'atDays' (whole days since 1970-01-01, from the
     * dates; null for a blank date), or 'by' (a length or height in cm, as
     * text). 'comma' => true reads a decimal comma in days, months or by.
     *
     * @return array ['state' => 'ok', 'x' => float] or ['state' => 'blank'|'outside'|'invalid', 'why' => string]
     */
    public static function axisValue(array $entry, array $in)
    {
        $comma = !empty($in['comma']);
        if ($entry['axis'] !== 'age') {
            $by = isset($in['by']) ? trim((string) $in['by'], " \t\r\n") : '';
            if ($by === '') return ['state' => 'blank', 'why' => 'the ' . $entry['axis'] . ' is blank'];
            $n = Logic::normalizeNumber($by, $comma);
            if ($n === null || $n === '') return ['state' => 'invalid', 'why' => 'the ' . $entry['axis'] . ' is not a number'];
            return self::inRange($entry, (float) $n, $entry['axis']);
        }
        if (array_key_exists('dobDays', $in) || array_key_exists('atDays', $in)) {
            $dob = isset($in['dobDays']) ? $in['dobDays'] : null;
            $at = isset($in['atDays']) ? $in['atDays'] : null;
            if ($dob === null || $at === null) return ['state' => 'blank', 'why' => 'the date of birth or the date of the measurement is blank'];
            $days = (float) ($at - $dob);
            if ($days < 0) return ['state' => 'outside', 'why' => 'the measurement is dated before the birth'];
            $x = $entry['axisUnit'] === 'days' ? $days : $days / self::DAYS_PER_MONTH;
        } else {
            $unit = isset($in['days']) ? 'days' : (isset($in['months']) ? 'months' : null);
            $raw = $unit === null ? '' : trim((string) $in[$unit], " \t\r\n");
            if ($raw === '') return ['state' => 'blank', 'why' => 'the age is blank'];
            $n = Logic::normalizeNumber($raw, $comma);
            if ($n === null || $n === '') return ['state' => 'invalid', 'why' => 'the age is not a number'];
            $v = (float) $n;
            if ($v < 0) return ['state' => 'outside', 'why' => 'the age is below 0'];
            if ($unit === $entry['axisUnit']) $x = $v;
            elseif ($unit === 'months') $x = $v * self::DAYS_PER_MONTH;
            else $x = $v / self::DAYS_PER_MONTH;
        }
        return self::inRange($entry, $x, 'age');
    }

    private static function inRange(array $entry, $x, $what)
    {
        $v = $entry['valid'];
        $lo = (float) $v['min'];
        $ok = $x >= $lo && (isset($v['max']) ? $x <= (float) $v['max'] : $x < (float) $v['below']);
        if ($ok) return ['state' => 'ok', 'x' => $x];
        $u = $entry['axisUnit'];
        $range = $v['min'] . ' to ' . (isset($v['max']) ? $v['max'] : 'under ' . $v['below']) . ' ' . $u;
        return ['state' => 'outside', 'why' => 'the ' . $what . ' is outside the reference (' . $range . ')'];
    }

    /**
     * L, M and S at axis position $x for 'male' or 'female', or null when the
     * table has no row there.
     */
    public static function lms(array $entry, array $table, $sex, $x)
    {
        $rows = $table[$sex];
        $row = function ($key) use ($rows, $table) {
            $i = $key - $table['first'];
            return ($i >= 0 && $i < count($rows)) ? $rows[$i] : null;
        };
        list($lowKey, $diff) = self::position($entry['lookup'], $table['scale'], $x);
        $lo = $row($lowKey);
        if ($lo === null) return null;
        if (!($diff > 0)) return $lo;
        $hi = $row($lowKey + 1);
        if ($hi === null) return null;
        return [$lo[0] + $diff * ($hi[0] - $lo[0]), $lo[1] + $diff * ($hi[1] - $lo[1]), $lo[2] + $diff * ($hi[2] - $lo[2])];
    }

    /**
     * The unrounded z-score of $y, or null when it cannot be computed. An L
     * nearer 0 than MIN_L takes the L = 0 formula: a table row cannot hold
     * one, but a row read between two of opposite sign can, and there
     * pow($y / $m, $l) is 1 for every measurement. The formulas then differ
     * by about L(Sz)^2/2. Twin: QRID_growthZRaw.
     */
    public static function zRaw($y, array $lms, $restricted)
    {
        list($l, $m, $s) = $lms;
        $y = (float) $y;
        if (abs($l) < self::MIN_L) {
            $z = log($y / $m) / $s;
            $sd = function ($k) use ($m, $s) { return $m * exp($s * $k); };
        } else {
            $z = (pow($y / $m, $l) - 1) / ($s * $l);
            $sd = function ($k) use ($l, $m, $s) { return $m * pow(1 + $l * $s * $k, 1 / $l); };
        }
        if ($restricted && is_finite($z)) {
            if ($z > 3) {
                $sd3 = $sd(3);
                $z = 3 + ($y - $sd3) / ($sd3 - $sd(2));
            } elseif ($z < -3) {
                $sd3 = $sd(-3);
                $z = -3 + ($y - $sd3) / ($sd(-2) - $sd3);
            }
        }
        return is_finite($z) ? $z : null;
    }

    /**
     * $z rounded to two decimals, half away from zero, as text ("-2.31",
     * "0.00"). A z-score beyond +-MAX_Z_TEXT is written as that bound: every
     * limit lies far inside it, and the digits stay plain.
     */
    public static function zText($z)
    {
        $z = max(-self::MAX_Z_TEXT, min(self::MAX_Z_TEXT, (float) $z));
        $n = (int) floor(abs($z) * 100 + 0.5);
        $text = intdiv($n, 100) . '.' . str_pad((string) ($n % 100), 2, '0', STR_PAD_LEFT);
        return ($z < 0 && $n !== 0 ? '-' : '') . $text;
    }

    /**
     * A measurement typed as text: ['state' => 'ok', 'y' => float], or
     * ['state' => 'blank'|'not-a-number'|'not-positive']. Read before the
     * inputs, so a value that is no measurement at all is reported whatever
     * the sex and age say.
     */
    public static function measure($text, $decimalComma = false, $scale = null)
    {
        $n = Logic::normalizeNumber($text, $decimalComma);
        if ($n === '') return ['state' => 'blank'];
        if ($n === null) return ['state' => 'not-a-number'];
        $y = (float) $n;
        if (!($y > 0)) return ['state' => 'not-positive'];
        // A rule's "measureScale" (0.001 for grams typed into a reference in kg),
        // one double multiplication in both runtimes. Twin: QRID_growthMeasure.
        if ($scale !== null) $y *= (float) $scale;
        return ['state' => 'ok', 'y' => $y];
    }

    /**
     * 'male', 'female' or null for a sex code under a rule (or branch): letter
     * case ignored unless its "caseSensitive" is true, the fold Logic::compare
     * uses. Twin: growthVerdict in js/engine.js.
     */
    public static function sexOf($code, array $rule)
    {
        $fold = empty($rule['caseSensitive']);
        $key = function ($v) use ($fold) { $v = (string) $v; return $fold ? Logic::foldAscii($v) : $v; };
        $c = $key($code);
        if ($c === $key(isset($rule['rangeMale']) ? $rule['rangeMale'] : '')) return 'male';
        if ($c === $key(isset($rule['rangeFemale']) ? $rule['rangeFemale'] : '')) return 'female';
        return null;
    }

    /**
     * The sex and the axis position of a growth rule's inputs, or why there
     * are none: ['state' => 'ok', 'sex' => 'male'|'female', 'x' => float] or
     * ['state' => 'inert', 'why']. $read holds each input the rule names
     * (rangeSex, rangeAgeDob, rangeAgeAt, rangeAgeDays, rangeAgeMonths,
     * rangeBy) as trimmed text, '' for a blank one; dates are saved values,
     * Y-M-D. The reasons are the browser's (growthVerdict in js/engine.js).
     * The one reading of the inputs: UniversalValidator::growthInputs and
     * tests/docs_examples_php.php both call it.
     */
    public static function inputsFor(array $rule, array $read, array $entry)
    {
        $sexCode = isset($read['rangeSex']) ? (string) $read['rangeSex'] : '';
        $sex = self::sexOf($sexCode, $rule);
        if ($sex === null) {
            return ['state' => 'inert', 'why' => $sexCode === '' ? 'the sex is blank'
                : 'the sex code is neither ' . $rule['rangeMale'] . ' (male) nor ' . $rule['rangeFemale'] . ' (female)'];
        }
        $in = ['comma' => !empty($rule['rangeAxisComma'])];
        if (isset($rule['rangeAgeDob'])) {
            foreach (['dob' => 'rangeAgeDob', 'at' => 'rangeAgeAt'] as $w => $k) {
                $type = isset($rule['range' . ucfirst($w) . 'Type']) ? $rule['range' . ucfirst($w) . 'Type'] : 'date';
                $p = TemporalValue::parse(isset($read[$k]) ? (string) $read[$k] : '', $type, 'ymd');
                if ($p['state'] === 'absent') { $in[$w . 'Days'] = null; continue; }
                // a saved value, not the rule: not evaluated, as in the browser
                if ($p['state'] !== 'ok') {
                    return ['state' => 'inert', 'why' => 'the ' . ($w === 'dob' ? 'date of birth' : 'date of the measurement')
                        . ' is not a date this rule can read'];
                }
                $in[$w . 'Days'] = (int) floor($p['seconds'] / 86400);
            }
        } else {
            foreach (['days' => 'rangeAgeDays', 'months' => 'rangeAgeMonths', 'by' => 'rangeBy'] as $w => $k) {
                if (isset($rule[$k])) $in[$w] = isset($read[$k]) ? (string) $read[$k] : '';
            }
        }
        $a = self::axisValue($entry, $in);
        if ($a['state'] !== 'ok') return ['state' => 'inert', 'why' => $a['why']];
        return ['state' => 'ok', 'sex' => $sex, 'x' => $a['x']];
    }

    /**
     * 'plausible-low', 'plausible-high' or null: measurement $y, in the
     * reference's unit, against a rule's "plausible" limits (texts, or null
     * for no limit on that side). Doubles compared as doubles: "30" is the
     * same double in both runtimes, and $y is too. Twin: QRID_growthPlausible.
     */
    public static function plausible($y, $lo, $hi)
    {
        if ($lo !== null && $y < (float) $lo) return 'plausible-low';
        if ($hi !== null && $y > (float) $hi) return 'plausible-high';
        return null;
    }

    /**
     * The z-score of measurement $y for 'male' or 'female' at axis position
     * $x, as text, or a state saying why there is none:
     * ['state' => 'ok', 'z' => '-2.31'] | ['state' => 'outside'] (no row at
     * $x; table() refuses a table whose rows do not cover its "valid" range).
     */
    public static function zScore(array $entry, array $table, $sex, $x, $y)
    {
        $lms = self::lms($entry, $table, $sex, $x);
        if ($lms === null) return ['state' => 'outside'];
        $z = self::zRaw($y, $lms, $entry['adjust'] === 'who-restricted');
        // No finite z: the measurement is too large (or too small) for the
        // formula, as when hundreds of digits overflow to INF. It lies beyond
        // every limit on its side of the median, which zText writes as the
        // bound. Twin: QRID_growthZScore.
        if ($z === null) $z = $y > $lms[1] ? self::MAX_Z_TEXT : -self::MAX_Z_TEXT;
        return ['state' => 'ok', 'z' => self::zText($z)];
    }

    /**
     * The measurement that scores $z against L, M and S: the inverse of
     * zRaw(), with the same WHO restriction beyond +-3 SD. Null when no
     * measurement above 0 scores it (1 + L*S*z at or below 0, or a result
     * that is not a finite number). Twin: QRID_growthValueAt.
     */
    public static function valueAt($z, array $lms, $restricted)
    {
        list($l, $m, $s) = $lms;
        $z = (float) $z;
        // sdAt, not a closure: a growth chart evaluates this at every row of
        // every curve when the page is built
        if ($restricted && $z > 3) {
            $sd3 = self::sdAt(3, $l, $m, $s);
            $y = $sd3 + ($z - 3) * ($sd3 - self::sdAt(2, $l, $m, $s));
        } elseif ($restricted && $z < -3) {
            $sd3 = self::sdAt(-3, $l, $m, $s);
            $y = $sd3 + ($z + 3) * (self::sdAt(-2, $l, $m, $s) - $sd3);
        } else {
            $y = self::sdAt($z, $l, $m, $s);
        }
        return (is_finite($y) && $y > 0) ? $y : null;
    }

    /** The measurement at z-score $k by the LMS formula alone (NAN where 1 + L*S*k is at or below 0). */
    private static function sdAt($k, $l, $m, $s)
    {
        if (abs($l) < self::MIN_L) return $m * exp($s * $k);
        $b = 1 + $l * $s * $k;
        return $b > 0 ? $m * pow($b, 1 / $l) : NAN;
    }

    /**
     * The bound of one limit, as a count of units of 10^-$decimals of the
     * measured field's own unit: the smallest measurement that the verdict
     * passes for a low limit ($low), the largest for a high one; null when
     * there is none to find. A measurement passes when its text, read the way
     * a typed value is (measure(), times $scale), scores a two-decimal z-score
     * (zText) inside the limit. So a value at the bound passes and one unit
     * beyond it does not, whatever decimals the limit has: the edge of a
     * limit is where the rounded z-score crosses it, not where the exact one
     * does. Found by halving between two measurements that bracket the edge
     * (BOUND_BRACKETS). Twin: QRID_growthBound.
     */
    public static function bound(array $entry, array $table, $sex, $x, $lim, $decimals, $low, $scale = null)
    {
        $lms = self::lms($entry, $table, $sex, $x);
        if ($lms === null) return null;
        $restricted = $entry['adjust'] === 'who-restricted';
        $d = (int) $decimals;
        $f = $scale === null ? 1.0 : (float) $scale;
        $lim = (string) $lim;
        $passes = function ($k) use ($lms, $restricted, $d, $scale, $lim, $low) {
            if ($k < 1) return false;     // a measurement at or below 0 is none
            $m = self::measure(self::unitsText($k, $d), false, $scale);
            if ($m['state'] !== 'ok') return false;
            $z = self::zRaw($m['y'], $lms, $restricted);
            if ($z === null) $z = $m['y'] > $lms[1] ? self::MAX_Z_TEXT : -self::MAX_Z_TEXT;
            $c = Logic::numCompare(self::zText($z), $lim);
            return $low ? $c >= 0 : $c <= 0;
        };
        // the measurement scoring $z, in units, or null when none does or it is too many units
        $units = function ($z) use ($lms, $restricted, $d, $f) {
            $v = self::valueAt($z, $lms, $restricted);
            if ($v === null) return null;
            $n = $v / $f * pow(10, $d);
            return (is_finite($n) && $n <= self::MAX_BOUND_UNITS) ? $n : null;
        };
        // inside: on the side of the limit that passes; outside: the side that does not
        $inside = null;
        $outside = null;
        foreach (self::BOUND_BRACKETS as $dz) {
            if ($inside === null) $inside = $units((float) $lim + ($low ? $dz : -$dz));
            if ($outside === null) $outside = $units((float) $lim + ($low ? -$dz : $dz));
        }
        if ($low) {
            if ($inside === null) return null;
            $in = (int) ceil($inside) + 1;
            $out = $outside === null ? 0 : max(0, (int) floor($outside) - 1);
        } else {
            if ($outside === null) return null;
            $out = (int) ceil($outside) + 1;
            $in = $inside === null ? 1 : max(1, (int) floor($inside) - 1);
        }
        if (max($in, $out) > self::MAX_BOUND_UNITS || !$passes($in) || $passes($out)) return null;
        while (abs($in - $out) > 1) {
            $mid = intdiv($in + $out, 2);
            if ($passes($mid)) $in = $mid;
            else $out = $mid;
        }
        return $in;
    }

    /** $units of 10^-$decimals as text, with all its decimals: (77, 1) is "7.7", (120, 1) "12.0", (8, 0) "8". */
    public static function unitsText($units, $decimals)
    {
        $d = (int) $decimals;
        $a = abs((int) $units);
        $sign = $units < 0 ? '-' : '';
        if ($d === 0) return $sign . $a;
        $p = (int) pow(10, $d);
        return $sign . intdiv($a, $p) . '.' . str_pad((string) ($a % $p), $d, '0', STR_PAD_LEFT);
    }

    /**
     * What an @UVRANGE rule that returns values writes for one child, from the
     * same inputs the verdict reads:
     *   z      the z-score of the measurement $y (null while there is none),
     *          written the way the verdict reads it, to two decimals;
     *   lower  the smallest measurement, to $decimals, that the low z limit
     *          passes (bound());
     *   upper  the largest one that the high z limit passes;
     *   range  "7.7 to 12.0 kg", "at least 7.7 kg" or "at most 12.0 kg"
     *          ($comma writes a decimal comma).
     * $y is the measurement in the reference's unit (measure() applied the
     * rule's $scale). The bounds are in the measured field's own unit, so with
     * a $scale they are the reference's values divided by it. The range names
     * $unit when given (the rule's "unit", which only goes with a
     * "measureScale"), else the reference's unit when there is no $scale, else
     * no unit. $limits is ['lo' => z limit as text or null, 'hi' => same].
     * Every key is null when there is nothing to write: no measurement, no
     * such limit, or no measurement that scores it. "range" is also null when
     * the two bounds cross: a band narrower than one unit of the last decimal
     * holds no value at that precision (lower and upper are still written, and
     * say so). The numbers of z, lower and upper use a decimal point;
     * markText() gives a field its own mark. Twin: QRID_growthOutputs.
     *
     * @return array ['z' => ?string, 'lower' => ?string, 'upper' => ?string, 'range' => ?string]
     */
    public static function outputs(array $entry, array $table, $sex, $x, $y, array $limits, $decimals, $comma = false, $scale = null, $unit = null)
    {
        $out = ['z' => null, 'lower' => null, 'upper' => null, 'range' => null];
        if ($y !== null) {
            $z = self::zScore($entry, $table, $sex, $x, $y);
            if ($z['state'] === 'ok') $out['z'] = $z['z'];
        }
        $want = ['lo' => false, 'hi' => false];
        foreach (['lo' => ['lower', true], 'hi' => ['upper', false]] as $side => $spec) {
            $lim = isset($limits[$side]) ? $limits[$side] : null;
            if ($lim === null || $lim === '') continue;
            $want[$side] = true;
            $k = self::bound($entry, $table, $sex, $x, $lim, $decimals, $spec[1], $scale);
            if ($k !== null) $out[$spec[0]] = self::unitsText($k, $decimals);
        }
        if (($want['lo'] || $want['hi']) && (!$want['lo'] || $out['lower'] !== null) && (!$want['hi'] || $out['upper'] !== null)) {
            $unit = ($unit !== null && $unit !== '') ? ' ' . $unit : ($scale === null ? ' ' . $entry['unit'] : '');
            if ($want['lo'] && $want['hi']) {
                if (Logic::numCompare($out['lower'], $out['upper']) <= 0) {
                    $out['range'] = self::markText($out['lower'], $comma) . ' to ' . self::markText($out['upper'], $comma) . $unit;
                }
            } elseif ($want['lo']) {
                $out['range'] = 'at least ' . self::markText($out['lower'], $comma) . $unit;
            } else {
                $out['range'] = 'at most ' . self::markText($out['upper'], $comma) . $unit;
            }
        }
        return $out;
    }

    /**
     * An axis value (days, months or cm) as text: rounded half away from zero
     * to two decimals the way roundedUnits() rounds, with no trailing zeros,
     * so 365 is "365", 12.5 months "12.5" and 400/30.4375 months "13.14".
     * Null for a value that is not a finite number. Twin: QRID_growthAxisText.
     */
    public static function axisText($x)
    {
        $x = (float) $x;
        if (!is_finite($x)) return null;
        $n = self::roundedUnits(abs($x), 2);
        if ($n === null) return null;
        $t = self::unitsText($n, 2);
        $t = rtrim(rtrim($t, '0'), '.');
        return ($x < 0 && $n !== 0 ? '-' : '') . $t;
    }

    /** A number as text with the decimal mark of the field it is written to: "7.7", or "7,7" for a comma. */
    public static function markText($text, $comma)
    {
        return $comma ? str_replace('.', ',', (string) $text) : (string) $text;
    }

    /**
     * One value other than the four outputs() makes, from the catalog
     * (php/growth_outputs.json, GrowthOutputs), as text with a decimal point,
     * or null when there is nothing to write:
     *   percentile         the percentile of measurement $y (null without one);
     *   valueAtZ           the measurement at z-score $param;
     *   valueAtPercentile  the measurement at percentile $param;
     *   age                the axis value $x (axisText), a column only;
     *   value              the measurement as it was typed ($typed, trimmed;
     *                      null without one), a column only.
     * $places is the decimals the value is written with. $y is in the
     * reference's unit; a measurement written out is in the measured field's
     * (divided by $scale). Twin: QRID_growthOutput.
     */
    public static function output(array $entry, array $table, $sex, $x, $y, $of, $param, $places, $scale = null, $typed = null)
    {
        if ($of === 'age') return self::axisText($x);
        if ($of === 'value') {
            $t = $typed === null ? '' : trim((string) $typed, " \t\r\n");
            return $t === '' ? null : $t;
        }
        if ($of === 'percentile') return $y === null ? null : self::percentile($entry, $table, $sex, $x, $y, $places);
        $z = self::lineZ($of, $param);
        return $z === null ? null : self::valueAtText($entry, $table, $sex, $x, $z, $places, $scale);
    }

    /**
     * The z-score output $of stands for at parameter $param: the z-score
     * itself for valueAtZ, the quantile of the percentile for
     * valueAtPercentile; null for another output, or a percentile with no
     * finite z-score. A chart curve is the measurement at this z-score.
     * Twin: QRID_growthLineZ.
     */
    public static function lineZ($of, $param)
    {
        if ($of === 'valueAtZ') return (float) $param;
        if ($of !== 'valueAtPercentile') return null;
        $z = self::normalQuantile((float) $param / 100);
        return is_finite($z) ? $z : null;
    }

    /**
     * The percentile of measurement $y, 100 times the normal distribution
     * function of its unrounded z-score (the WHO restriction beyond +-3 SD
     * included), as text with $places decimals; null when the table has no
     * row at $x. A measurement too large or too small for the formula sits at
     * the end of its side. Twin: QRID_growthPercentile.
     */
    public static function percentile(array $entry, array $table, $sex, $x, $y, $places)
    {
        $lms = self::lms($entry, $table, $sex, $x);
        if ($lms === null) return null;
        $z = self::zRaw($y, $lms, $entry['adjust'] === 'who-restricted');
        $cum = $z === null ? ((float) $y > $lms[1] ? 1.0 : 0.0) : self::normalCdf($z);
        return self::percentileText($cum, $places);
    }

    /**
     * Probability $cum as a percentile with $places decimals, rounded half
     * away from zero and kept inside the scale: at one decimal 0.1 to 99.9,
     * because a number field cannot say "below 0.1". Twin: QRID_growthPercentileText.
     */
    public static function percentileText($cum, $places)
    {
        if (!is_finite((float) $cum)) return null;
        $d = (int) $places;
        $top = (int) pow(10, $d + 2);
        $n = self::roundedUnits(max(0.0, min(1.0, (float) $cum)) * 100, $d);
        return self::unitsText(max(1, min($top - 1, (int) $n)), $d);
    }

    /**
     * $v (a finite number, 0 or more) as a count of units of 10^-$d, or null
     * when it is not such a number. The value is first rounded to 15
     * significant digits, the most a double holds for every decimal, so a
     * decimal tie stays a tie (a median of 9.165, held as 9.16499999999999915,
     * reads 9.165), and that is rounded half away from zero to $d decimals
     * (9.17). Both roundings are done here on the value's first 40 decimal
     * digits (sprintf("%.39e"); toExponential(39) gives the same digits), so
     * neither depends on how a library breaks a tie, and the two runtimes
     * write the same text for the same double. Twin: QRID_growthRoundedUnits.
     */
    public static function roundedUnits($v, $d)
    {
        $v = (float) $v;
        $d = (int) $d;
        if (!is_finite($v) || $v < 0) return null;
        if ($v == 0) return 0;
        $s = sprintf('%.39e', $v);                      // "9.164999999999999147...e+0"
        $exp = (int) substr($s, strpos($s, 'e') + 1);
        // 15 significant digits, half up on the sixteenth (s[1] is the point)
        $n15 = (int) ($s[0] . substr($s, 2, 14)) + ((int) $s[16] >= 5 ? 1 : 0);
        if ($n15 >= 1000000000000000) {
            $n15 = 100000000000000;
            $exp++;
        }
        $digits = (string) $n15;
        $shift = $exp - 14 + $d;                        // units = n15 * 10^shift
        if ($shift >= 0) return $shift > 3 ? null : $n15 * (int) pow(10, $shift);
        $keep = 15 + $shift;                            // digits before the one that rounds
        if ($keep < 0) return 0;
        $n = $keep === 0 ? 0 : (int) substr($digits, 0, $keep);
        return (int) $digits[$keep] >= 5 ? $n + 1 : $n;
    }

    /**
     * The measurement that scores z-score $z at $x, in the measured field's
     * unit (the reference's divided by $scale), rounded half away from zero to
     * $places decimals (roundedUnits); null when the table has no row at $x,
     * no measurement above 0 scores $z, it rounds to 0 (a measurement must be
     * above 0, as bound() keeps it), or it would take more than
     * MAX_BOUND_UNITS units, as a bound would. Twin: QRID_growthValueAtText.
     */
    public static function valueAtText(array $entry, array $table, $sex, $x, $z, $places, $scale = null)
    {
        $lms = self::lms($entry, $table, $sex, $x);
        if ($lms === null) return null;
        $v = self::valueAt($z, $lms, $entry['adjust'] === 'who-restricted');
        if ($v === null) return null;
        $d = (int) $places;
        $f = $scale === null ? 1.0 : (float) $scale;
        $n = self::roundedUnits($v / $f, $d);
        if ($n === null || $n < 1 || $n > self::MAX_BOUND_UNITS) return null;
        return self::unitsText($n, $d);
    }

    /**
     * The standard normal distribution function, lower tail. W. J. Cody's
     * rational Chebyshev approximation (ACM TOMS Algorithm 715) as R computes
     * pnorm(), step for step, with the same constants and cut-offs. Results
     * agree with R 4.6.1 to 1e-15 relative, a few units in the last place,
     * because exp() and the order of rounding differ between builds; below
     * x = -37.5193, where R 4.6 returns subnormal numbers, this returns 0.
     * Twin: QRID_growthNormalCdf; tests/growth_normal_golden.json pins both.
     */
    public static function normalCdf($x)
    {
        $x = (float) $x;
        if (is_nan($x)) return NAN;
        static $a = [2.2352520354606839287, 161.02823106855587881, 1067.6894854603709582, 18154.981253343561249,
                     0.065682337918207449113];
        static $b = [47.20258190468824187, 976.09855173777669322, 10260.932208618978205, 45507.789335026729956];
        static $c = [0.39894151208813466764, 8.8831497943883759412, 93.506656132177855979, 597.27027639480026226,
                     2494.5375852903726711, 6848.1904505362823326, 11602.651437647350124, 9842.7148383839780218,
                     1.0765576773720192317e-8];
        static $d = [22.266688044328115691, 235.38790178262499861, 1519.377599407554805, 6485.558298266760755,
                     18615.571640885098091, 34900.952721145977266, 38912.003286093271411, 19685.429676859990727];
        static $p = [0.21589853405795699, 0.1274011611602473639, 0.022235277870649807, 0.001421619193227893466,
                     2.9112874951168792e-5, 0.02307344176494017303];
        static $q = [1.28426009614491121, 0.468238212480865118, 0.0659881378689285515, 0.00378239633202758244,
                     7.29751555083966205e-5];
        $y = abs($x);
        if ($y <= 0.67448975) {
            // |x| up to qnorm(3/4)
            $xnum = 0.0;
            $xden = 0.0;
            if ($y > 1.1102230246251565e-16) {
                $xsq = $x * $x;
                $xnum = $a[4] * $xsq;
                $xden = $xsq;
                for ($i = 0; $i < 3; $i++) {
                    $xnum = ($xnum + $a[$i]) * $xsq;
                    $xden = ($xden + $b[$i]) * $xsq;
                }
            }
            return 0.5 + $x * ($xnum + $a[3]) / ($xden + $b[3]);
        }
        if ($y <= 5.656854249492380195206754896838) {
            // up to sqrt(32)
            $xnum = $c[8] * $y;
            $xden = $y;
            for ($i = 0; $i < 7; $i++) {
                $xnum = ($xnum + $c[$i]) * $y;
                $xden = ($xden + $d[$i]) * $y;
            }
            $temp = ($xnum + $c[7]) / ($xden + $d[7]);
            $xsq = floor($y * 16) / 16;
            $del = ($y - $xsq) * ($y + $xsq);
            $cum = exp(-$xsq * $xsq * 0.5) * exp(-$del * 0.5) * $temp;
            return $x > 0 ? 1.0 - $cum : $cum;
        }
        if (-37.5193 < $x && $x < 8.2924) {
            $xsq = 1.0 / ($x * $x);
            $xnum = $p[5] * $xsq;
            $xden = $xsq;
            for ($i = 0; $i < 4; $i++) {
                $xnum = ($xnum + $p[$i]) * $xsq;
                $xden = ($xden + $q[$i]) * $xsq;
            }
            $temp = $xsq * ($xnum + $p[4]) / ($xden + $q[4]);
            $temp = (0.398942280401432677939946059934 - $temp) / $y;
            // trunc(x * 16) / 16, rounded toward zero as R's trunc() does
            $xsq = ($x < 0 ? -floor(-$x * 16) : floor($x * 16)) / 16;
            $del = ($x - $xsq) * ($x + $xsq);
            $cum = exp(-$xsq * $xsq * 0.5) * exp(-$del * 0.5) * $temp;
            return $x > 0 ? 1.0 - $cum : $cum;
        }
        return $x > 0 ? 1.0 : 0.0;
    }

    /**
     * The quantile of the standard normal distribution: the z-score below
     * which a share $p of children lie. Wichura's AS241 (PPND16) as R computes
     * qnorm(), with the same constants and branches, agreeing with R 4.6.1 to
     * 1e-15 relative (R 4.3 and later add an asymptotic branch for tails far
     * beyond the 0.1 to 99.9 percentiles a rule names); -INF at 0, INF at 1,
     * NAN outside 0 to 1. Twin: QRID_growthNormalQuantile;
     * tests/growth_normal_golden.json pins both.
     */
    public static function normalQuantile($p)
    {
        $p = (float) $p;
        if (is_nan($p) || $p < 0 || $p > 1) return NAN;
        if ($p == 0) return -INF;
        if ($p == 1) return INF;
        $q = $p - 0.5;
        if (abs($q) <= 0.425) {
            $r = 0.180625 - $q * $q;
            return $q * ((((((($r * 2509.0809287301226727 + 33430.575583588128105) * $r + 67265.770927008700853) * $r
                        + 45921.953931549871457) * $r + 13731.693765509461125) * $r + 1971.5909503065514427) * $r
                        + 133.14166789178437745) * $r + 3.387132872796366608)
                / ((((((($r * 5226.495278852545925 + 28729.085735721942674) * $r + 39307.89580009271061) * $r
                        + 21213.794301586595867) * $r + 5394.1960214247511077) * $r + 687.1870074920579083) * $r
                        + 42.313330701600911252) * $r + 1.0);
        }
        $r = sqrt(-log($q < 0 ? $p : 1 - $p));
        if ($r <= 5) {
            $r -= 1.6;
            $val = ((((((($r * 7.7454501427834140764e-4 + 0.0227238449892691845833) * $r + 0.24178072517745061177) * $r
                        + 1.27045825245236838258) * $r + 3.64784832476320460504) * $r + 5.7694972214606914055) * $r
                        + 4.6303378461565452959) * $r + 1.42343711074968357734)
                / ((((((($r * 1.05075007164441684324e-9 + 5.475938084995344946e-4) * $r + 0.0151986665636164571966) * $r
                        + 0.14810397642748007459) * $r + 0.68976733498510000455) * $r + 1.6763848301838038494) * $r
                        + 2.05319162663775882187) * $r + 1.0);
        } else {
            $r -= 5.0;
            $val = ((((((($r * 2.01033439929228813265e-7 + 2.71155556874348757815e-5) * $r + 0.0012426609473880784386) * $r
                        + 0.026532189526576123093) * $r + 0.29656057182850489123) * $r + 1.7848265399172913358) * $r
                        + 5.4637849111641143699) * $r + 6.6579046435011037772)
                / ((((((($r * 2.04426310338993978564e-15 + 1.4215117583164458887e-7) * $r + 1.8463183175100546818e-5) * $r
                        + 7.868691311456132591e-4) * $r + 0.0148753612908506148525) * $r + 0.13692988092273580531) * $r
                        + 0.59983220655588793769) * $r + 1.0);
        }
        return $q < 0 ? -$val : $val;
    }
}
