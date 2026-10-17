<?php
/**
 * GrowthOutputs — what an @UVRANGE growth rule can work out and write, read
 * from php/growth_outputs.json.
 *
 * Each output is declared once in that file: whether it is written as a number
 * or as text, how many decimals a number gets, what it is made from (the
 * measurement, a limit, or neither) and, for an output with a parameter, the
 * parameter's range. The parser checks a rule's "return" keys against it
 * (AnnotationRules::parseRangeValue, checkReturn), the dictionary hook checks
 * the fields the values go to (UniversalValidator::returnTargetsProblem), and
 * tests/gen_mode_registry.cjs writes the engine's copy into js/engine.js. The
 * values themselves are computed by GrowthReference and its browser twins.
 *
 * A return key is an output id ("percentile"), or an id, a colon and a
 * parameter for an output that takes one ("valueAtZ:-2",
 * "valueAtPercentile:97").
 *
 * A missing or malformed file throws, as ModeRegistry does: the parser never
 * runs with half a catalog. problem() gives the reason without throwing, so a
 * caller refuses only the rules that return values. An entry that
 * GrowthReference does not compute (GrowthReference::COMPUTED) is malformed.
 *
 * Pure static class with no REDCap dependency; tests/growth_outputs_php.php
 * pins it.
 */

namespace INSPIRE\UniversalValidator;

require_once __DIR__ . '/Logic.php';
require_once __DIR__ . '/GrowthReference.php';

final class GrowthOutputs
{
    const FORMAT = 'uv-growth-outputs-1';
    const WRITTEN = ['number', 'text'];
    const PLACES = ['z', 'decimals', 'percentileDecimals'];
    const NEEDS = ['measurement', 'limit-low', 'limit-high', 'limit-any', 'none'];
    const SHAPES = ['value'];
    /** The most decimals a parameter may be declared with. */
    const MAX_PARAM_DECIMALS = 6;
    /** An output id: a letter, then letters and digits. */
    const ID_RE = '/^[A-Za-z][A-Za-z0-9]{0,39}$/D';

    /** @var array|null the decoded, checked file */
    private static $data = null;
    /** @var string|null test override for the file path */
    private static $path = null;

    /** Point the catalog at another file (tests only). null restores the default. */
    public static function useFile($path)
    {
        self::$path = $path;
        self::$data = null;
    }

    /** The checked outputs, id => entry. Throws \RuntimeException on a missing or malformed file. */
    private static function data()
    {
        if (self::$data !== null) return self::$data;
        $file = self::$path !== null ? self::$path : __DIR__ . '/growth_outputs.json';
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('growth output catalog ' . basename($file) . ' could not be read');
        }
        $j = json_decode($raw, true);
        if (!is_array($j) || !isset($j['format']) || $j['format'] !== self::FORMAT
                || !isset($j['outputs']) || !is_array($j['outputs']) || !$j['outputs'] || array_values($j['outputs']) === $j['outputs']) {
            throw new \RuntimeException('growth output catalog ' . basename($file) . ' is not a "' . self::FORMAT . '" file with an "outputs" map');
        }
        foreach ($j['outputs'] as $id => $e) {
            $why = self::entryProblem((string) $id, $e);
            if ($why !== null) throw new \RuntimeException('growth output "' . $id . '": ' . $why);
        }
        self::$data = $j['outputs'];
        return self::$data;
    }

    /** Why catalog entry $id cannot be used, or null. */
    private static function entryProblem($id, $e)
    {
        if (!preg_match(self::ID_RE, $id)) return 'the id must be a letter followed by letters and digits';
        if (!is_array($e)) return 'the entry is not an object';
        if (!in_array($id, GrowthReference::COMPUTED, true)) {
            return 'no code computes it (GrowthReference::COMPUTED and QRID_GROWTH_COMPUTED list the outputs that are computed)';
        }
        if (!isset($e['written']) || !in_array($e['written'], self::WRITTEN, true)) return '"written" must be number or text';
        if ($e['written'] === 'number') {
            if (!isset($e['places']) || !in_array($e['places'], self::PLACES, true)) {
                return '"places" must be one of ' . implode(', ', self::PLACES);
            }
            if (!isset($e['integer']) || !is_bool($e['integer'])) return '"integer" must be true or false';
        } else {
            // a text holds numbers too ("range" its two bounds): "places" may say
            // what sets their decimals, but a text never goes into an integer field
            if (isset($e['integer'])) return 'a text output has no "integer"';
            if (isset($e['places']) && !in_array($e['places'], self::PLACES, true)) {
                return '"places" must be one of ' . implode(', ', self::PLACES);
            }
        }
        if (!isset($e['needs']) || !in_array($e['needs'], self::NEEDS, true)) return '"needs" must be one of ' . implode(', ', self::NEEDS);
        if (!isset($e['shapes']) || !is_array($e['shapes']) || !$e['shapes']
                || count(array_filter($e['shapes'], 'is_string')) !== count($e['shapes']) || array_diff($e['shapes'], self::SHAPES)) {
            return '"shapes" must list some of ' . implode(', ', self::SHAPES);
        }
        // a field name; "{param}" stands for the parameter (see exampleOf)
        if (!isset($e['example']) || !is_string($e['example'])
                || !preg_match('/^[a-z][a-z0-9_]*$/', str_replace('{param}', 'x', $e['example']))
                || substr_count($e['example'], '{param}') > (isset($e['param']) ? 1 : 0)) {
            return '"example" must be a field name, with "{param}" at most once and only for an output with a parameter';
        }
        if (!isset($e['label']) || !is_string($e['label']) || $e['label'] === '') return '"label" must be a text';
        if (isset($e['param'])) {
            $p = $e['param'];
            if (!is_array($p) || !isset($p['what'], $p['min'], $p['max'], $p['decimals'])) {
                return '"param" needs "what", "min", "max" and "decimals"';
            }
            if (!is_string($p['what']) || $p['what'] === '') return 'the parameter\'s "what" must be a text';
            if (!is_int($p['decimals']) || $p['decimals'] < 0 || $p['decimals'] > self::MAX_PARAM_DECIMALS) {
                return 'the parameter\'s "decimals" must be a whole number from 0 to ' . self::MAX_PARAM_DECIMALS;
            }
            foreach (['min', 'max'] as $k) {
                if (!is_string($p[$k]) || !preg_match(self::paramRe($p['decimals']), $p[$k])) {
                    return 'the parameter\'s "' . $k . '" must be a number written as text, with at most its "decimals"';
                }
            }
            if (Logic::numCompare($p['min'], $p['max']) > 0) return 'the parameter\'s "min" is above its "max"';
            if (strpos($e['label'], '{param}') === false) return 'the label of an output with a parameter must name {param}';
        }
        return null;
    }

    /** How a parameter is written: an optional minus, a whole number without leading zeros, at most $d decimals. */
    private static function paramRe($d)
    {
        return '/^-?(?:0|[1-9][0-9]*)' . ($d > 0 ? '(?:\.[0-9]{1,' . (int) $d . '})?' : '') . '$/D';
    }

    /** Null when the catalog can be used, otherwise why not (what the other methods would throw). */
    public static function problem()
    {
        try {
            self::data();
            return null;
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
    }

    /** Every output id, in the catalog's order. */
    public static function ids()
    {
        return array_keys(self::data());
    }

    /** The catalog entry of output $id, or null. */
    public static function spec($id)
    {
        $d = self::data();
        return isset($d[$id]) ? $d[$id] : null;
    }

    /**
     * The outputs as a "valid:" list: "z, lower, ..., valueAtZ:<z-score>,
     * valueAtPercentile:<percentile>".
     */
    public static function validText()
    {
        $out = [];
        foreach (self::data() as $id => $e) {
            $out[] = isset($e['param']) ? $id . ':<' . $e['param']['what'] . '>' : $id;
        }
        return implode(', ', $out);
    }

    /**
     * Return key $key read against the catalog: ['of' => id, 'param' => text
     * or null], or ['error' => why]. $label names the key in the message
     * (the "return" entry by default).
     */
    public static function parse($key, $label = '"return"')
    {
        $key = (string) $key;
        $colon = strpos($key, ':');
        $id = $colon === false ? $key : substr($key, 0, $colon);
        $param = $colon === false ? null : substr($key, $colon + 1);
        $e = self::spec($id);
        if ($e === null || ($param !== null && !isset($e['param']))) {
            return ['error' => 'unknown ' . $label . ' value "' . $key . '" — valid: ' . self::validText() . '.'];
        }
        if (!isset($e['param'])) return ['of' => $id, 'param' => null];
        $p = $e['param'];
        $shape = $label . ' "' . $id . '" takes the ' . $p['what'] . ' after a colon, a number from ' . $p['min'] . ' to ' . $p['max']
            . ($p['decimals'] > 0 ? ' with at most ' . $p['decimals'] . ' decimal' . ($p['decimals'] === 1 ? '' : 's') : ' without decimals')
            . ', such as "' . $id . ':' . $p['max'] . '"';
        if ($param === null || $param === '') return ['error' => $shape . '.'];
        if (!preg_match(self::paramRe($p['decimals']), $param)
                || Logic::numCompare($param, $p['min']) < 0 || Logic::numCompare($param, $p['max']) > 0) {
            return ['error' => $shape . ' — got "' . $key . '".'];
        }
        return ['of' => $id, 'param' => $param];
    }

    /** What the value of return key $key is made from (see "needs"), or null when the key is not an output. */
    public static function needsOf($key)
    {
        $p = self::parse($key);
        return isset($p['of']) ? self::spec($p['of'])['needs'] : null;
    }

    /**
     * True when every key in $keys is an output made without a limit: a rule
     * that returns only such values judges nothing and needs no "soft" or
     * "hard" (it returns its z-score, percentile or reference values).
     */
    public static function limitFree(array $keys)
    {
        if (!$keys) return false;
        foreach ($keys as $k) {
            $n = self::needsOf($k);
            if ($n === null || strpos($n, 'limit-') === 0) return false;
        }
        return true;
    }

    /**
     * The field name the parser suggests for return key $key, such as
     * "wfa_p97" for "valueAtPercentile:97": the entry's "example", with
     * "{param}" replaced by the parameter ("-" written "m", "." written "_",
     * so "valueAtZ:-2.5" suggests "wfa_sdm2_5").
     */
    public static function exampleOf($key)
    {
        $p = self::parse($key);
        if (!isset($p['of'])) return 'wfa_value';
        $param = $p['param'] === null ? '' : strtr($p['param'], ['-' => 'm', '.' => '_']);
        return str_replace('{param}', $param, self::spec($p['of'])['example']);
    }

    /**
     * Return key $key spelled one way per value, or null when it is not an
     * output: a parameter loses trailing zeros, and minus zero is zero, so
     * "valueAtZ:2.0" and "valueAtZ:2" are both "valueAtZ:2", and
     * "valueAtZ:-0" is "valueAtZ:0". Two keys of one rule with the same
     * spelling write the same value.
     */
    public static function canonicalKey($key)
    {
        $p = self::parse($key);
        if (!isset($p['of'])) return null;
        if ($p['param'] === null) return $p['of'];
        $n = $p['param'];
        if (strpos($n, '.') !== false) $n = rtrim(rtrim($n, '0'), '.');
        if ($n === '-0') $n = '0';
        return $p['of'] . ':' . $n;
    }

    /**
     * The output ids whose entry passes $test, in the catalog's order: for
     * the messages that name the outputs a key applies to.
     */
    public static function idsWhere(callable $test)
    {
        $out = [];
        foreach (self::data() as $id => $e) {
            if ($test($e)) $out[] = $id;
        }
        return $out;
    }

    /** True when some return key in $keys is an output whose entry passes $test. */
    public static function anyWhere(array $keys, callable $test)
    {
        foreach ($keys as $k) {
            $p = self::parse((string) $k);
            if (isset($p['of']) && $test(self::spec($p['of']))) return true;
        }
        return false;
    }

    /**
     * The catalog as the engine needs it (tests/gen_mode_registry.cjs writes
     * the same from the file): id => written, places, integer, needs and
     * param.
     */
    public static function clientCopy()
    {
        $out = [];
        foreach (self::data() as $id => $e) {
            $c = ['written' => $e['written'], 'needs' => $e['needs']];
            if (isset($e['places'])) $c['places'] = $e['places'];
            if ($e['written'] === 'number') $c['integer'] = $e['integer'];
            if (isset($e['param'])) {
                $c['param'] = ['min' => $e['param']['min'], 'max' => $e['param']['max'], 'decimals' => $e['param']['decimals']];
            }
            $out[$id] = $c;
        }
        return $out;
    }
}
