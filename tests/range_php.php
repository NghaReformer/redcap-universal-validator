<?php
/**
 * range_php.php — the @UVRANGE verdict and grammar (server side).
 *
 * Runs every case of tests/range_fixture.json through Logic::rangeVerdict;
 * tests/range_js.cjs runs the same cases through the browser twin, so the two
 * runtimes cannot disagree about a number. Then pins the tag's grammar:
 * what parseAllTags accepts, the exact decimal strings it keeps for each
 * limit, and every refusal.
 *
 * Run:  php tests/range_php.php
 */

require_once __DIR__ . '/../php/Logic.php';
require_once __DIR__ . '/../php/ModeRegistry.php';
require_once __DIR__ . '/../php/AnnotationRules.php';

use INSPIRE\UniversalValidator\AnnotationRules;
use INSPIRE\UniversalValidator\Logic;

$n = 0;
$fails = 0;
function check($label, $ok)
{
    global $n, $fails;
    $n++;
    if (!$ok) { $fails++; echo "FAIL: $label\n"; }
}

// ---- 1. the verdict ---------------------------------------------------------

$fx = json_decode(file_get_contents(__DIR__ . '/range_fixture.json'), true);
check('fixture loads', is_array($fx) && count($fx['cases']) > 30);
foreach ($fx['cases'] as $c) {
    $got = Logic::rangeVerdict($c['spec'], $c['value']);
    check($c['name'] . ' (' . json_encode($c['value']) . ' => ' . $c['tier'] . '/' . json_encode($c['reason'])
        . ', got ' . json_encode($got) . ')', $got === ['tier' => $c['tier'], 'reason' => $c['reason']]);
}
check('normalizeNumber: blank is ""', Logic::normalizeNumber("  \t") === '');
check('normalizeNumber: comma decimal', Logic::normalizeNumber('17,5', true) === '17.5');
check('normalizeNumber: comma on a point field is not a number', Logic::normalizeNumber('17,5') === null);
check('normalizeNumber: an exponent is not a number', Logic::normalizeNumber('1e3') === null);
check('normalizeNumber: kept as typed', Logic::normalizeNumber(' 0014.50 ') === '0014.50');
check('numCompare: 2.50 = 2.5', Logic::numCompare('2.50', '2.5') === 0);
check('numCompare: beyond 2^53', Logic::numCompare('9007199254740993', '9007199254740992') > 0);
check('numCompare: negative', Logic::numCompare('-5.01', '-5') < 0);

// ---- 2. the grammar ---------------------------------------------------------

function frag($tag)
{
    $f = AnnotationRules::parseAllTags($tag);
    return is_array($f) && count($f) === 1 ? $f[0] : ['error' => 'parseAllTags returned ' . json_encode($f)];
}
function errOf($tag)
{
    $f = frag($tag);
    return isset($f['error']) ? $f['error'] : '';
}

$f = frag('@UVRANGE={"soft":[12,17.5],"hard":[3,25],"unit":"g/dL"}');
check('both tiers: limits kept as exact decimal strings', !isset($f['error']) && $f['type'] === 'range'
    && $f['rangeSoftLo'] === '12' && $f['rangeSoftHi'] === '17.5' && $f['rangeHardLo'] === '3' && $f['rangeHardHi'] === '25');
check('unit carried', ($f['rangeUnit'] ?? null) === 'g/dL');
check('no block keys unless written', !isset($f['rangeSoftBlock']) && !isset($f['rangeHardBlock']));

$f = frag('@UVRANGE={"soft":[null,140],"softBlock":"off","hard":[40,250],"hardBlock":"confirm"}');
check('open soft low: absent, not null', !isset($f['error']) && !array_key_exists('rangeSoftLo', $f) && $f['rangeSoftHi'] === '140');
check('block keys carried', ($f['rangeSoftBlock'] ?? null) === 'off' && ($f['rangeHardBlock'] ?? null) === 'confirm');

$f = frag('@UVRANGE={"hard":[0,10]}');
check('hard only', !isset($f['error']) && $f['rangeHardLo'] === '0' && $f['rangeHardHi'] === '10' && !isset($f['rangeSoftLo']));
$f = frag('@UVRANGE={"soft":[1,2]}');
check('soft only', !isset($f['error']) && $f['rangeSoftLo'] === '1' && !isset($f['rangeHardLo']));
$f = frag('@UVRANGE={"hard":[3.0,0.1]}');
check('3.0 is kept as 3 (a float written back the shortest way)', isset($f['error']) && strpos($f['error'], '(3) is above its high limit (0.1)') !== false);
$f = frag('@UVRANGE={"hard":[0.1,0.30000000000000004]}');
check('a float keeps its shortest round-trip digits', !isset($f['error']) && $f['rangeHardLo'] === '0.1'
    && $f['rangeHardHi'] === '0.30000000000000004');
$f = frag('@UVRANGE={"hard":["0.1000000000000000000001","9007199254740993"]}');
check('quoted limits are kept exactly', !isset($f['error']) && $f['rangeHardLo'] === '0.1000000000000000000001'
    && $f['rangeHardHi'] === '9007199254740993');
$f = frag('@UVRANGE={"hard":[0,9007199254740993]}');
check('a whole number past 2^53 is kept exactly', !isset($f['error']) && $f['rangeHardHi'] === '9007199254740993');
$f = frag('@UVRANGE={"hard":[0,99999999999999999999]}');
check('a whole number past PHP_INT_MAX is kept exactly', !isset($f['error']) && $f['rangeHardHi'] === '99999999999999999999');
$f = frag('@UVRANGE={"hard":[-5,-1],"soft":[-4.5,-2]}');
check('negative limits', !isset($f['error']) && $f['rangeHardLo'] === '-5' && $f['rangeSoftLo'] === '-4.5');
$f = frag('@UVRANGE={"hard":[0,10],"when":"[sex]=\'1\'","message":"Check the units.","caseSensitive":true}');
check('when, message and caseSensitive carried', !isset($f['error']) && $f['when'] === "[sex]='1'"
    && $f['message'] === 'Check the units.' && $f['caseSensitive'] === true);
$f = frag('@UVRANGE={"hard":[0,10],"unit":"  mmHg "}');
check('unit trimmed', ($f['rangeUnit'] ?? null) === 'mmHg');
// PHP writes a small double with an exponent; the limit keeps the digits as typed.
$f = frag('@UVRANGE={"hard":[0.0000001,0.00025]}');
check('a small limit typed plainly is kept (got ' . json_encode($f) . ')', !isset($f['error'])
    && $f['rangeHardLo'] === '0.0000001' && $f['rangeHardHi'] === '0.00025');
$f = frag('@UVRANGE={"hard":[-2.5e-5,1e3],"soft":[-1.25E-5,0.000125]}');
check('a JSON number with an exponent is that number', !isset($f['error']) && $f['rangeHardLo'] === '-0.000025'
    && $f['rangeHardHi'] === '1000' && $f['rangeSoftLo'] === '-0.0000125' && $f['rangeSoftHi'] === '0.000125');
$f = frag('@UVRANGE={"hard":[0,1e15]}');
check('a large whole double below 2^53 is spelled out', !isset($f['error']) && $f['rangeHardHi'] === '1000000000000000');
$f = frag('@UVRANGE={"hard":[0,1.0e16]}');
check('a double at or past 2^53 is refused', isset($f['error']) && strpos($f['error'], 'the "hard" high limit must be a number') !== false);

// serialize_precision 17 (old php.ini) must not change the digits kept.
$old = ini_get('serialize_precision');
ini_set('serialize_precision', '17');
$f = frag('@UVRANGE={"hard":[0.1,17.5]}');
check('serialize_precision 17: 0.1 stays 0.1', !isset($f['error']) && $f['rangeHardLo'] === '0.1' && $f['rangeHardHi'] === '17.5');
check('serialize_precision is restored', ini_get('serialize_precision') === '17');
ini_set('serialize_precision', $old);

// Refusals: every one names the problem.
$refusals = [
    'bare value'              => ['@UVRANGE=12', 'needs its settings as JSON'],
    'bad JSON'                => ['@UVRANGE={"soft":[1,2}', 'JSON does not parse'],
    'blockSave'               => ['@UVRANGE={"hard":[0,10],"blockSave":"hard"}', '"blockSave" does not apply to @UVRANGE'],
    'unknown key'             => ['@UVRANGE={"hard":[0,10],"min":0}', 'unknown @UVRANGE option(s): min'],
    'no limits at all'        => ['@UVRANGE={"unit":"g/dL"}', 'needs "soft" or "hard" limits'],
    'soft not a pair'         => ['@UVRANGE={"soft":[1]}', '"soft" must be a list of two limits'],
    'hard three items'        => ['@UVRANGE={"hard":[1,2,3]}', '"hard" must be a list of two limits'],
    'hard as an object'       => ['@UVRANGE={"hard":{"lo":1,"hi":2}}', '"hard" must be a list of two limits'],
    'hard a number'           => ['@UVRANGE={"hard":5}', '"hard" must be a list of two limits'],
    'both open'               => ['@UVRANGE={"soft":[null,null]}', '"soft" needs at least one limit'],
    'limit a word'            => ['@UVRANGE={"hard":["low",10]}', 'the "hard" low limit must be a number'],
    'limit an exponent string' => ['@UVRANGE={"hard":["1e3",10]}', 'the "hard" low limit must be a number'],
    'limit an exponent number' => ['@UVRANGE={"hard":[0,1e300]}', 'the "hard" high limit must be a number'],
    'limit a float past 2^53' => ['@UVRANGE={"hard":[0,9007199254740993.5]}', 'Write a very large or very precise number in quotes'],
    'limit true'              => ['@UVRANGE={"hard":[true,10]}', 'the "hard" low limit must be a number'],
    'limit a comma string'    => ['@UVRANGE={"hard":["17,5",20]}', 'the "hard" low limit must be a number'],
    'hard low above high'     => ['@UVRANGE={"hard":[25,3]}', 'the "hard" low limit (25) is above its high limit (3)'],
    'soft low above high'     => ['@UVRANGE={"soft":[17.5,12]}', 'the "soft" low limit (17.5) is above its high limit (12)'],
    'soft below hard'         => ['@UVRANGE={"soft":[2,17.5],"hard":[3,25]}', 'the "soft" low limit (2) is outside the "hard" range'],
    'soft above hard'         => ['@UVRANGE={"soft":[12,26],"hard":[3,25]}', 'the "soft" high limit (26) is outside the "hard" range'],
    'soft past an open side'  => ['@UVRANGE={"soft":[30,40],"hard":[null,20]}', 'the "soft" low limit (30) is outside the "hard" range'],
    'soft high under hard low' => ['@UVRANGE={"soft":[null,2],"hard":[3,null]}', 'the "soft" high limit (2) is outside the "hard" range'],
    'softBlock hard'          => ['@UVRANGE={"soft":[1,2],"softBlock":"hard"}', '"softBlock" must be off or confirm'],
    'hardBlock off'           => ['@UVRANGE={"hard":[1,2],"hardBlock":"off"}', '"hardBlock" must be confirm or hard'],
    'softBlock not a string'  => ['@UVRANGE={"soft":[1,2],"softBlock":true}', '"softBlock" must be a string'],
    'unit not a string'       => ['@UVRANGE={"hard":[1,2],"unit":5}', '"unit" must be a string'],
    'unit blank'              => ['@UVRANGE={"hard":[1,2],"unit":"   "}', '"unit" must be a short label'],
    'unit too long'           => ['@UVRANGE={"hard":[1,2],"unit":"milligrams per decilitre"}', '"unit" must be a short label'],
    'unit with a newline'     => ['@UVRANGE={"hard":[1,2],"unit":"g\ndL"}', '"unit" must be a short label'],
    'message not a string'    => ['@UVRANGE={"hard":[1,2],"message":["x"]}', '"message" must be a string'],
    'when does not parse'     => ['@UVRANGE={"hard":[1,2],"when":"[sex]=="}', 'the "when" condition'],
    'when blank'              => ['@UVRANGE={"hard":[1,2],"when":"  "}', 'the "when" condition must be a non-empty'],
    'caseSensitive quoted'    => ['@UVRANGE={"hard":[1,2],"caseSensitive":"yes"}', '"caseSensitive" must be true or false'],
    'references'              => ['@UVRANGE={"hard":[1,2],"references":{"b":{"field":"x"}}}', 'unknown @UVRANGE option(s): references'],
];
foreach ($refusals as $label => $pair) {
    $e = errOf($pair[0]);
    check('refused: ' . $label . ' (got ' . json_encode($e) . ')', $e !== '' && strpos($e, $pair[1]) !== false);
}
check('a refusal is tagged @UVRANGE', (frag('@UVRANGE={"hard":[25,3]}')['_tag'] ?? null) === '@UVRANGE');
check('a unit of exactly 20 characters is fine', errOf('@UVRANGE={"hard":[1,2],"unit":"' . str_repeat('x', 20) . '"}') === '');
check('a unit of 20 multibyte characters is fine', errOf('@UVRANGE={"hard":[1,2],"unit":"' . str_repeat('µ', 20) . '"}') === '');
check('equal limits are a fixed value, accepted', errOf('@UVRANGE={"hard":[5,5],"soft":[5,5]}') === '');
check('soft equal to hard, accepted', errOf('@UVRANGE={"soft":[3,25],"hard":[3,25]}') === '');
check('soft open where hard is closed, accepted', errOf('@UVRANGE={"soft":[null,140],"hard":[40,250]}') === '');

// With event and instance references on, a qualified "when" is still refused:
// the check reads only the tagged field.
$q = AnnotationRules::parseAllTags('@UVRANGE={"hard":[1,2],"when":"[baseline_arm_1][sex]=\'1\'"}', ['qualified' => true]);
check('qualified "when": refused', isset($q[0]['error']) && strpos($q[0]['error'], 'does not support event or instance references') !== false);
$q = AnnotationRules::parseAllTags('@UVRANGE={"hard":[1,2],"when":"[sex]=\'1\'"}', ['qualified' => true]);
check('plain "when" with references on: fine', !isset($q[0]['error']));
// "references" names bindings for "when", which reads only this entry here: refused, never carried unused.
$q = AnnotationRules::parseAllTags('@UVRANGE={"hard":[1,2],"references":{"b":{"field":"x"}}}', ['qualified' => true]);
check('"references" with references on: refused', isset($q[0]['error']) && strpos($q[0]['error'], 'unknown @UVRANGE option(s): references') !== false);

// checkFragment (the shared gate) refuses what the parser would never produce.
$cf = function (array $frag) { return AnnotationRules::checkFragment(array_merge(['type' => 'range'], $frag)); };
check('checkFragment: sound range', $cf(['rangeHardLo' => '0', 'rangeHardHi' => '10']) === []);
check('checkFragment: no limits', (bool) $cf([]));
check('checkFragment: a float limit', (bool) $cf(['rangeHardLo' => 0.5]));
check('checkFragment: an exponent limit', (bool) $cf(['rangeHardLo' => '1e3']));
check('checkFragment: blockSave', (bool) $cf(['rangeHardLo' => '0', 'blockSave' => 'hard']));
check('checkFragment: unit not a string', (bool) $cf(['rangeHardLo' => '0', 'rangeUnit' => 5]));
check('checkFragment: soft outside hard', (bool) $cf(['rangeHardLo' => '0', 'rangeHardHi' => '10', 'rangeSoftHi' => '11']));

// Two @UVRANGE tags on one field are two branches; each needs its own "when".
$two = AnnotationRules::parseAllTags('@UVRANGE={"soft":[12,15.5],"when":"[sex]=\'2\'"} @UVRANGE={"soft":[13.5,17.5],"when":"[sex]=\'1\'"}');
check('two tags: two fragments', is_array($two) && count($two) === 2 && !isset($two[0]['error']) && !isset($two[1]['error'])
    && $two[0]['rangeSoftLo'] === '12' && $two[1]['rangeSoftLo'] === '13.5');

echo "range_php: $n checks, $fails failure(s)\n";
exit($fails ? 1 : 0);
