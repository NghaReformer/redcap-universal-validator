<?php
/**
 * growth_outputs_php.php — the catalog of values an @UVRANGE growth rule can
 * return (php/growth_outputs.json, php/GrowthOutputs.php).
 *
 * Pins:
 *   1. the bundled catalog: its outputs, the "valid:" list, what each is made
 *      from, and the keys the parser accepts and refuses with their reasons
 *      (tests/growth_fixture.json "outputOf" holds the cases both runtimes
 *      share; this file checks the wording),
 *   2. the engine's copy (the @generated growth-outputs block in
 *      js/engine.js) equals GrowthOutputs::clientCopy, and the engine's
 *      hand-written twins of GrowthReference's lists and limits
 *      (QRID_GROWTH_COMPUTED, the decimals a rule may ask for) equal the
 *      server's,
 *   3. every refusal of a malformed catalog file, and problem() reporting
 *      one without throwing.
 *
 * Run:  php tests/growth_outputs_php.php
 */

require_once __DIR__ . '/../php/Logic.php';
require_once __DIR__ . '/../php/GrowthOutputs.php';

use INSPIRE\UniversalValidator\GrowthOutputs as O;
use INSPIRE\UniversalValidator\GrowthReference as G;

$n = 0;
$fails = 0;
function check($label, $ok)
{
    global $n, $fails;
    $n++;
    if (!$ok) { $fails++; echo "FAIL: $label\n"; }
}

// ---- 1. the bundled catalog ---------------------------------------------------------
check('the outputs, in order', O::ids() === ['z', 'lower', 'upper', 'range', 'percentile', 'valueAtZ', 'valueAtPercentile', 'age', 'value']);
check('the page table also offers the age and the typed value (got ' . O::validText('column') . ')',
    O::validText('column') === 'z, lower, upper, range, percentile, valueAtZ:<z-score>, valueAtPercentile:<percentile>, age, value');
check('a chart curve is a value at a z-score or a percentile', O::validText('line') === 'valueAtZ:<z-score>, valueAtPercentile:<percentile>'
    && O::lineKinds() === ['z' => 'valueAtZ', 'percentiles' => 'valueAtPercentile']);
check('age and value are columns only: a field cannot take them', isset(O::parse('age')['error']) && isset(O::parse('value')['error'])
    && O::parse('age', '"show" "table" "columns"', 'column', 'column') === ['of' => 'age', 'param' => null]
    && O::parse('value', '"columns"', 'column') === ['of' => 'value', 'param' => null]);
check('the refusal names the place and the noun', strpos(O::parse('nope', '"columns"', 'column', 'column')['error'],
    'unknown "columns" column "nope" — valid: z, lower') === 0);
check('canonicalKey and anyWhere read a column key', O::canonicalKey('age', 'column') === 'age' && O::canonicalKey('age') === null
    && O::anyWhere(['age'], function ($e) { return $e['unit'] === 'axis'; }, 'column')
    && !O::anyWhere(['age'], function ($e) { return true; }));
check('paramText and paramOk', O::paramText('valueAtZ') === 'a number from -6 to 6 with at most 2 decimals'
    && O::paramOk('valueAtZ', '-2.5') && !O::paramOk('valueAtZ', '7') && !O::paramOk('valueAtZ', '1.234') && !O::paramOk('z', '1')
    && !O::paramOk('valueAtZ', 2));
check('the age is written on the axis, the value as typed', O::spec('age')['places'] === 'axis' && O::spec('age')['unit'] === 'axis'
    && O::spec('value')['places'] === 'typed' && O::spec('value')['needs'] === 'measurement' && O::spec('value')['label'] === '{measure}');
check('the "valid:" list names the parameter of an output that takes one (got ' . O::validText() . ')',
    O::validText() === 'z, lower, upper, range, percentile, valueAtZ:<z-score>, valueAtPercentile:<percentile>');
check('z is a number with two decimals, never in an integer field, made from the measurement',
    O::spec('z')['written'] === 'number' && O::spec('z')['places'] === 'z' && O::spec('z')['integer'] === false
    && O::spec('z')['needs'] === 'measurement');
check('range is text made from a limit on either side, its bounds written with "decimals"', O::spec('range')['written'] === 'text'
    && O::spec('range')['needs'] === 'limit-any' && O::spec('range')['places'] === 'decimals' && !isset(O::spec('range')['integer']));
check('the percentile takes "percentileDecimals"', O::spec('percentile')['places'] === 'percentileDecimals');
check('the values at a z-score or percentile take "decimals" and need neither measurement nor limit',
    O::spec('valueAtZ')['places'] === 'decimals' && O::spec('valueAtZ')['needs'] === 'none'
    && O::spec('valueAtPercentile')['places'] === 'decimals' && O::spec('valueAtPercentile')['needs'] === 'none');
check('an unknown id has no spec', O::spec('nope') === null && O::spec('') === null);
check('needsOf a parameterized key', O::needsOf('valueAtZ:-2') === 'none' && O::needsOf('lower') === 'limit-low'
    && O::needsOf('valueAtZ:9') === null);
check('limitFree: z, percentile and reference values need no limit',
    O::limitFree(['z']) && O::limitFree(['percentile', 'valueAtZ:-2', 'valueAtPercentile:97']));
check('limitFree: a bound, the range or an unknown key needs one; no keys is not limit-free',
    !O::limitFree(['z', 'lower']) && !O::limitFree(['range']) && !O::limitFree(['zz']) && !O::limitFree([]));
check('exampleOf: the parameter is part of the name ("-" as m, "." as _)', O::exampleOf('valueAtPercentile:50') === 'wfa_p50'
    && O::exampleOf('valueAtPercentile:97') === 'wfa_p97' && O::exampleOf('valueAtZ:-2.5') === 'wfa_sdm2_5'
    && O::exampleOf('z') === 'wfa_z' && O::exampleOf('?') === 'wfa_value');
check('canonicalKey: one spelling per value', O::canonicalKey('valueAtZ:2.0') === 'valueAtZ:2' && O::canonicalKey('valueAtZ:2.50') === 'valueAtZ:2.5'
    && O::canonicalKey('valueAtZ:-0') === 'valueAtZ:0' && O::canonicalKey('valueAtZ:-0.00') === 'valueAtZ:0'
    && O::canonicalKey('valueAtZ:-2') === 'valueAtZ:-2' && O::canonicalKey('valueAtPercentile:50.00') === 'valueAtPercentile:50'
    && O::canonicalKey('valueAtPercentile:0.1') === 'valueAtPercentile:0.1' && O::canonicalKey('z') === 'z' && O::canonicalKey('zz') === null);
$byDecimals = function ($e) { return isset($e['places']) && $e['places'] === 'decimals'; };
check('idsWhere: the outputs "decimals" applies to', O::idsWhere($byDecimals) === ['lower', 'upper', 'range', 'valueAtZ', 'valueAtPercentile']);
check('anyWhere', O::anyWhere(['z', 'valueAtZ:-2'], $byDecimals) && !O::anyWhere(['z', 'percentile'], $byDecimals) && !O::anyWhere(['nope'], $byDecimals));
check('problem: none with the bundled file', O::problem() === null);

$refusals = [
    ['zz', 'unknown "return" value "zz" — valid: z, lower, upper, range, percentile, valueAtZ:<z-score>, valueAtPercentile:<percentile>.'],
    ['z:1', 'unknown "return" value "z:1"'],
    ['valueAtZ', '"return" "valueAtZ" takes the z-score after a colon, a number from -6 to 6 with at most 2 decimals, such as "valueAtZ:6".'],
    ['valueAtZ:', 'takes the z-score after a colon'],
    ['valueAtZ:9', 'a number from -6 to 6 with at most 2 decimals, such as "valueAtZ:6" — got "valueAtZ:9".'],
    ['valueAtZ:2.555', 'with at most 2 decimals'],
    ['valueAtPercentile:100', '"return" "valueAtPercentile" takes the percentile after a colon, a number from 0.1 to 99.9'],
    ['valueAtPercentile:0', '— got "valueAtPercentile:0".'],
];
foreach ($refusals as $c) {
    $p = O::parse($c[0]);
    check('refused ' . json_encode($c[0]) . ' (got ' . json_encode($p) . ')', isset($p['error']) && strpos($p['error'], $c[1]) !== false);
}
$p = O::parse('valueAtZ:-2', '"show"');
check('the label names where the key was written', $p === ['of' => 'valueAtZ', 'param' => '-2']
    && strpos(O::parse('nope', '"show"')['error'], 'unknown "show" value') === 0);

// ---- 2. the engine's copy -----------------------------------------------------------
$src = file_get_contents(__DIR__ . '/../js/engine.js');
$ok = preg_match('#/\* @generated growth-outputs BEGIN.*?\*/\s*var QRID_GROWTH_OUTPUTS = (\{.*?\n\});\s*/\* @generated growth-outputs END \*/#s', $src, $mm);
check('the engine carries the generated block', $ok === 1);
$copy = $ok ? json_decode($mm[1], true) : null;
check('the engine\'s copy is GrowthOutputs::clientCopy (run node tests/gen_mode_registry.cjs)', $copy === O::clientCopy());
$ok = preg_match('/var QRID_GROWTH_COMPUTED = (\[[^\]]*\]);/', $src, $mm);
check('the engine computes what GrowthReference computes (QRID_GROWTH_COMPUTED)', $ok === 1 && json_decode($mm[1], true) === G::COMPUTED);
check('every catalog output is computed', !array_diff(O::ids(), G::COMPUTED));
$num = function ($name) use ($src) {
    return preg_match('/\b' . $name . ' = ([0-9.e]+)[,;]/', $src, $m) ? (float) $m[1] : null;
};
foreach (['QRID_GROWTH_MAX_DECIMALS' => G::MAX_DECIMALS, 'QRID_GROWTH_MAX_PERCENTILE_DECIMALS' => G::MAX_PERCENTILE_DECIMALS,
          'QRID_GROWTH_DEFAULT_PERCENTILE_DECIMALS' => G::DEFAULT_PERCENTILE_DECIMALS, 'QRID_GROWTH_MIN_L' => G::MIN_L,
          'QRID_GROWTH_MAX_BOUND_UNITS' => G::MAX_BOUND_UNITS] as $js => $php) {
    check("the engine's $js is the server's (" . json_encode($php) . ')', $num($js) === (float) $php);
}

// ---- 3. a malformed catalog is refused ---------------------------------------------
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uv_outputs_' . bin2hex(random_bytes(6));
mkdir($dir);
$good = json_decode(file_get_contents(__DIR__ . '/../php/growth_outputs.json'), true);
$cases = [
    'no file'                  => [null, 'could not be read'],
    'not JSON'                 => ['{', 'is not a "uv-growth-outputs-1" file'],
    'an entry that is a list'  => [['z' => 'number'], 'the entry is not an object'],
    'param without what'       => [['valueAtZ' => ['param' => ['what' => '', 'min' => '-6', 'max' => '6', 'decimals' => 2]] + $good['outputs']['valueAtZ']], 'the parameter\'s "what" must be a text'],
    'another format'           => [array_merge($good, ['format' => 'uv-growth-outputs-2']), 'is not a "uv-growth-outputs-1" file'],
    'outputs as a list'        => [array_merge($good, ['outputs' => [['written' => 'text']]]), 'is not a "uv-growth-outputs-1" file'],
    'a bad id'                 => [['format' => 'uv-growth-outputs-1', 'outputs' => ['1z' => $good['outputs']['z']]], 'the id must be a letter'],
    'written'                  => [['z' => ['written' => 'date'] + $good['outputs']['z']], '"written" must be number or text'],
    'places'                   => [['z' => ['places' => 'many'] + $good['outputs']['z']], '"places" must be one of'],
    'integer'                  => [['z' => ['integer' => 'no'] + $good['outputs']['z']], '"integer" must be true or false'],
    'a text with integer'      => [['range' => ['integer' => true] + $good['outputs']['range']], 'a text output has no "integer"'],
    'a text with bad places'   => [['range' => ['places' => 'many'] + $good['outputs']['range']], '"places" must be one of'],
    'not computed'             => [['median' => $good['outputs']['valueAtZ']], 'no code computes it'],
    'example {param} twice'    => [['valueAtZ' => ['example' => 'wfa_{param}_{param}'] + $good['outputs']['valueAtZ']], '"{param}" at most once'],
    'example {param} unused'   => [['z' => ['example' => 'wfa_z{param}'] + $good['outputs']['z']], 'only for an output with a parameter'],
    'needs'                    => [['z' => ['needs' => 'luck'] + $good['outputs']['z']], '"needs" must be one of'],
    'shapes'                   => [['z' => ['shapes' => ['chart']] + $good['outputs']['z']], '"shapes" must list some of'],
    'shapes nested'            => [['z' => ['shapes' => [['value']]] + $good['outputs']['z']], '"shapes" must list some of'],
    'example'                  => [['z' => ['example' => 'Wfa Z'] + $good['outputs']['z']], '"example" must be a field name'],
    'label'                    => [['z' => ['label' => ''] + $good['outputs']['z']], '"label" must be a text'],
    'param without max'        => [['valueAtZ' => ['param' => ['what' => 'z', 'min' => '-6', 'decimals' => 2]] + $good['outputs']['valueAtZ']], '"param" needs'],
    'param decimals'           => [['valueAtZ' => ['param' => ['what' => 'z', 'min' => '-6', 'max' => '6', 'decimals' => 9]] + $good['outputs']['valueAtZ']], '"decimals" must be a whole number'],
    'param min as a number'    => [['valueAtZ' => ['param' => ['what' => 'z', 'min' => -6, 'max' => '6', 'decimals' => 2]] + $good['outputs']['valueAtZ']], '"min" must be a number written as text'],
    'param min above max'      => [['valueAtZ' => ['param' => ['what' => 'z', 'min' => '6', 'max' => '-6', 'decimals' => 2]] + $good['outputs']['valueAtZ']], '"min" is above its "max"'],
    'label without {param}'    => [['valueAtZ' => ['label' => 'value at z'] + $good['outputs']['valueAtZ']], 'must name {param}'],
    'shapes twice'             => [['z' => ['shapes' => ['value', 'value']] + $good['outputs']['z']], 'each once'],
    'shapes as a map'          => [['z' => ['shapes' => ['a' => 'value']] + $good['outputs']['z']], 'each once'],
    'a cell place as a value'  => [['age' => ['shapes' => ['value', 'column']] + $good['outputs']['age']], 'describes a table cell'],
    'a line without param'     => [['z' => ['shapes' => ['value', 'line'], 'line' => 'zz'] + $good['outputs']['z']], 'the line shape is for an output with a parameter'],
    'a line that needs a limit' => [['valueAtZ' => ['needs' => 'limit-low'] + $good['outputs']['valueAtZ']], 'the line shape is for an output with a parameter'],
    'a line name, no line'     => [['z' => ['line' => 'zz'] + $good['outputs']['z']], '"line" names an output with the line shape'],
    'a line shape, no name'    => [['valueAtZ' => array_diff_key($good['outputs']['valueAtZ'], ['line' => 1])], '"line" names an output with the line shape'],
    'a bad line name'          => [['valueAtZ' => ['line' => 'Z-scores'] + $good['outputs']['valueAtZ']], '"line" must be 1 to 20 lower-case letters'],
    'a line name twice'        => [['valueAtZ' => $good['outputs']['valueAtZ'], 'valueAtPercentile' => ['line' => 'z'] + $good['outputs']['valueAtPercentile']], 'its "line" name "z" is also "valueAtZ"\'s'],
    'unit'                     => [['lower' => ['unit' => 'kg'] + $good['outputs']['lower']], '"unit" must be one of measure, axis'],
    'a brace in the label'     => [['z' => ['label' => 'z {score}'] + $good['outputs']['z']], 'the label may hold only {param}, {measure}, {axis} in braces'],
    'a slot twice'             => [['value' => ['label' => '{measure} {measure}'] + $good['outputs']['value']], 'the label names {measure} more than once'],
    '{param} without param'    => [['z' => ['label' => 'z {param}'] + $good['outputs']['z']], 'the label names {param}, but the output has no parameter'],
];
foreach ($cases as $name => $c) {
    $file = $dir . DIRECTORY_SEPARATOR . md5($name) . '.json';
    if ($c[0] !== null) {
        $doc = is_string($c[0]) ? $c[0] : json_encode(isset($c[0]['format']) ? $c[0] : ['format' => 'uv-growth-outputs-1', 'outputs' => $c[0]]);
        file_put_contents($file, $doc);
    }
    O::useFile($file);
    $why = null;
    try { O::ids(); } catch (\RuntimeException $e) { $why = $e->getMessage(); }
    check('refused, ' . $name . ' (got ' . json_encode($why) . ')', $why !== null && strpos($why, $c[1]) !== false);
    check('... and problem() says so without throwing', O::problem() === $why);
    if (is_file($file)) unlink($file);
}
// a catalog may offer fewer outputs, and its entries change the grammar and
// the limits with no code change
$fewer = $good;
unset($fewer['outputs']['range']);
$fewer['outputs']['valueAtZ']['param']['max'] = '3';
$fewer['outputs']['percentile']['needs'] = 'limit-any';
$file = $dir . DIRECTORY_SEPARATOR . 'fewer.json';
file_put_contents($file, json_encode($fewer));
O::useFile($file);
check('an output left out of the file is an unknown key', isset(O::parse('range')['error'])
    && strpos(O::validText(), 'range') === false);
check('a parameter range from the file', isset(O::parse('valueAtZ:4')['error']) && O::parse('valueAtZ:3') === ['of' => 'valueAtZ', 'param' => '3']);
check('what an output needs, from the file', !O::limitFree(['percentile']));
unlink($file);
O::useFile(null);
check('the default file again', O::ids()[0] === 'z' && O::spec('range') !== null && O::limitFree(['percentile']));
rmdir($dir);

echo "growth_outputs_php: $n checks, $fails failure(s)\n";
exit($fails ? 1 : 0);
