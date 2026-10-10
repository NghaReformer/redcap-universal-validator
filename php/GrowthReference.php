<?php
namespace INSPIRE\UniversalValidator;
require_once __DIR__ . '/Logic.php';

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
    /** The largest z-score zText writes (see there). */
    const MAX_Z_TEXT = 999.99;

    const AXES = ['age' => ['days', 'months'], 'length' => ['cm'], 'height' => ['cm']];
    const LOOKUPS = ['round', 'floor', 'linear'];
    const ADJUSTS = ['none', 'who-restricted'];
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
     * @return array ['references' => [id => entry + ['dir' => folder]],
     *                'pageTables' => int, 'problems' => [string, ...],
     *                'refused' => [id => why], 'broken' => why|null]
     */
    public static function catalog($extraDir = null)
    {
        $extraDir = is_string($extraDir) ? trim($extraDir) : '';
        if (isset(self::$catalogMemo[$extraDir])) return self::$catalogMemo[$extraDir];
        $out = ['references' => [], 'pageTables' => self::PAGE_TABLES, 'problems' => [], 'refused' => [], 'broken' => null];
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
                continue;
            }
            if (isset($index['pageTables'])) $out['pageTables'] = $index['pageTables'];
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

    private static function readIndex($dir)
    {
        $path = $dir . DIRECTORY_SEPARATOR . 'index.json';
        // The folder's path stays out of the text: it reaches the page as part
        // of a configuration error, and the administrator knows the setting.
        if (!is_file($path) || !is_readable($path)) return ['error' => 'the folder has no readable index.json.'];
        $raw = @file_get_contents($path);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($j)) return ['error' => 'index.json is not valid JSON.'];
        if (!isset($j['format']) || $j['format'] !== self::INDEX_FORMAT) {
            return ['error' => 'index.json must say "format": "' . self::INDEX_FORMAT . '".'];
        }
        if (!isset($j['references']) || !is_array($j['references'])) return ['error' => 'index.json has no "references".'];
        $out = ['references' => $j['references']];
        if (isset($j['pageTables'])) {
            if (!is_int($j['pageTables']) || $j['pageTables'] < 0 || $j['pageTables'] > 50) {
                return ['error' => '"pageTables" must be a whole number from 0 to 50.'];
            }
            $out['pageTables'] = $j['pageTables'];
        }
        return $out;
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
        $size = is_file($path) ? @filesize($path) : false;
        if ($size === false) throw new \RuntimeException('the table ' . $entry['file'] . ' of "' . $entry['id'] . '" cannot be read.');
        if ($size > self::MAX_TABLE_BYTES) throw new \RuntimeException('the table ' . $entry['file'] . ' is larger than 4 MB.');
        $raw = @file_get_contents($path);
        if (!is_string($raw)) throw new \RuntimeException('the table ' . $entry['file'] . ' of "' . $entry['id'] . '" cannot be read.');
        if (!hash_equals($entry['sha256'], hash('sha256', $raw))) {
            throw new \RuntimeException('the table ' . $entry['file'] . ' does not match the "sha256" in index.json; it was changed or damaged.');
        }
        $t = json_decode($raw, true);
        $bad = function ($why) use ($entry) { return new \RuntimeException('the table ' . $entry['file'] . ' ' . $why); };
        if (!is_array($t) || !isset($t['format']) || $t['format'] !== self::TABLE_FORMAT) throw $bad('is not a "' . self::TABLE_FORMAT . '" table.');
        if (!isset($t['scale']) || !is_int($t['scale']) || $t['scale'] < 1 || $t['scale'] > 1000) throw $bad('needs "scale" as a whole number from 1 to 1000.');
        if (!isset($t['first']) || !is_int($t['first'])) throw $bad('needs "first" as a whole number.');
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
     * rule is refused instead. A limit must sit at least 0.01 inside the
     * bound, so that a two-decimal z-score can still fall beyond it.
     */
    public static function limitProblem(array $entry, array $table, array $limits)
    {
        $b = self::bounds($entry, $table);
        foreach (['hard', 'soft'] as $tier) {
            $lo = isset($limits[$tier][0]) ? (string) $limits[$tier][0] : null;
            $hi = isset($limits[$tier][1]) ? (string) $limits[$tier][1] : null;
            if ($lo !== null && $b['floor'] !== null) {
                // the lowest limit a score can still fall below, in hundredths
                // (rounded to 1e-6 first: -19.99 * 100 is -1998.9999999999998)
                $reach = (int) ceil(round(($b['floor']['z'] + 0.01) * 100, 6));
                if (Logic::numCompare($lo, self::hundredths($reach)) < 0) {
                    return 'its "' . $tier . '" low limit ' . $lo . ' is out of reach: with "' . $entry['id'] . '" no measurement'
                        . ' scores below ' . self::zText($b['floor']['z']) . ' ' . self::where($entry, $table, $b['floor'])
                        . '. Use a low limit of ' . self::hundredths($reach) . ' or above.';
                }
            }
            if ($hi !== null && $b['ceil'] !== null) {
                $reach = (int) floor(round(($b['ceil']['z'] - 0.01) * 100, 6));
                if (Logic::numCompare($hi, self::hundredths($reach)) > 0) {
                    return 'its "' . $tier . '" high limit ' . $hi . ' is out of reach: with "' . $entry['id'] . '" no measurement'
                        . ' scores above ' . self::zText($b['ceil']['z']) . ' ' . self::where($entry, $table, $b['ceil'])
                        . '. Use a high limit of ' . self::hundredths($reach) . ' or below.';
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
        $floor = $l > 0 ? -1 / ($l * $s) : null;
        $ceil = $l < 0 ? 1 / (-$l * $s) : null;
        if (!$restricted) return [$floor, $ceil];
        if ($ceil !== null && $ceil > 3) $ceil = null;
        if ($floor === null || $floor < -3) {
            $q = $l == 0 ? exp(-$s) : pow((1 - 3 * $l * $s) / (1 - 2 * $l * $s), 1 / $l);
            $floor = -3 - $q / (1 - $q);
        }
        return [$floor, $ceil];
    }

    /**
     * What a page needs of a reference to compute z-scores in the browser: the
     * index fields QRID_growth* reads, and the rows.
     */
    public static function pageCopy(array $entry, array $table)
    {
        $out = [];
        foreach (['title', 'measure', 'unit', 'axis', 'axisUnit', 'lookup', 'valid', 'adjust'] as $k) $out[$k] = $entry[$k];
        foreach (['scale', 'first', 'male', 'female'] as $k) $out[$k] = $table[$k];
        return $out;
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

    /** The unrounded z-score of $y, or null when it cannot be computed. */
    public static function zRaw($y, array $lms, $restricted)
    {
        list($l, $m, $s) = $lms;
        $y = (float) $y;
        if ($l == 0) {
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
    public static function measure($text, $decimalComma = false)
    {
        $n = Logic::normalizeNumber($text, $decimalComma);
        if ($n === '') return ['state' => 'blank'];
        if ($n === null) return ['state' => 'not-a-number'];
        $y = (float) $n;
        if (!($y > 0)) return ['state' => 'not-positive'];
        return ['state' => 'ok', 'y' => $y];
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
}
