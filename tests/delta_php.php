<?php
/**
 * delta_php.php — the @UVDELTA verdict and grammar (server side).
 *
 * Runs every case of tests/delta_fixture.json through Logic::deltaVerdict;
 * tests/delta_js.cjs runs the same cases through the browser twin, so the two
 * runtimes cannot disagree about a change. Then pins the tag's grammar: what
 * parseAllTags accepts, the keys it writes (the shorthand kept for the field
 * hook, exact limits, the branch group), and every refusal.
 *
 * Run:  php tests/delta_php.php
 */

require_once __DIR__ . '/../php/Logic.php';
require_once __DIR__ . '/../php/ExactDecimal.php';
require_once __DIR__ . '/../php/ModeRegistry.php';
require_once __DIR__ . '/../php/AnnotationRules.php';

use INSPIRE\UniversalValidator\AnnotationRules;
use INSPIRE\UniversalValidator\ExactDecimal;
use INSPIRE\UniversalValidator\Logic;

$n = 0;
$fails = 0;
function check($label, $ok)
{
    global $n, $fails;
    $n++;
    if (!$ok) { $fails++; echo "FAIL: $label\n"; }
}
function text($v)
{
    if ($v === null || is_string($v)) return $v;
    return (isset($v['prefix']) ? $v['prefix'] : '') . str_repeat($v['repeat'], $v['times']) . (isset($v['suffix']) ? $v['suffix'] : '');
}
function brief($s)
{
    return is_string($s) && strlen($s) > 40 ? strlen($s) . ' chars' : json_encode($s);
}

// ---- 1. the verdict ---------------------------------------------------------

$fx = json_decode(file_get_contents(__DIR__ . '/delta_fixture.json'), true);
check('fixture loads', is_array($fx) && count($fx['cases']) >= 40);
foreach ($fx['cases'] as $c) {
    $got = Logic::deltaVerdict($c['spec'], text($c['value']), text($c['earlier']));
    $want = ['verdict' => $c['verdict'], 'reason' => $c['reason'], 'change' => text($c['change'])];
    check($c['name'] . ' (want ' . $want['verdict'] . '/' . json_encode($want['reason']) . '/' . brief($want['change'])
        . ', got ' . $got['verdict'] . '/' . json_encode($got['reason']) . '/' . brief($got['change']) . ')', $got === $want);
}
check('a direction it does not know limits both ways',
    Logic::deltaVerdict(['max' => '1', 'direction' => 'sideways'], '0', '5')['verdict'] === 'down');
check('an empty limit is no limit', Logic::deltaVerdict(['max' => '', 'maxPct' => '10'], '105', '100')['verdict'] === 'ok');

// the evaluation budget (wargame P1): long numbers pay before the work, short ones are free
$paid = [];
$wallet = function ($units) use (&$paid) { $paid[] = $units; return true; };
Logic::deltaVerdict(['max' => '5', 'maxPct' => '10'], '74', '70', $wallet);
check('short values cost nothing, and the budget is not asked', $paid === []);
$long = '1' . str_repeat('0', 3000);
$paid = [];
$r = Logic::deltaVerdict(['max' => '1'], $long, $long, $wallet);
check('a long sum is paid for first (sumCost of both sides)', $r['verdict'] === 'ok'
    && $paid === [ExactDecimal::sumCost([$long, '-' . $long])]);
$paid = [];
$r = Logic::deltaVerdict(['maxPct' => '10'], $long . '0', $long, $wallet);
check('the percent products are paid for too', $r['verdict'] === 'up' && count($paid) === 2
    && $paid[1] === ExactDecimal::multiplyCost($r['change'], '100') + ExactDecimal::multiplyCost('10', $long));
$broke = function ($units) { return false; };
check('a refused sum is unknown, reason "limit", with no change',
    Logic::deltaVerdict(['max' => '1'], $long, $long, $broke) === ['verdict' => 'unknown', 'reason' => 'limit', 'change' => null]);
$once = 0;
$tight = function ($units) use (&$once) { return $once++ === 0; };
$r = Logic::deltaVerdict(['maxPct' => '10'], $long . '0', $long, $tight);
check('a refused product is unknown, reason "limit", and keeps the change',
    $r['verdict'] === 'unknown' && $r['reason'] === 'limit' && $r['change'] === '9' . str_repeat('0', 3000));
check('no budget given: nothing is refused', Logic::deltaVerdict(['max' => '1'], $long, $long)['verdict'] === 'ok');

// ---- 2. the grammar ---------------------------------------------------------

function frags($tag, $qualified = true)
{
    return AnnotationRules::parseAllTags($tag, $qualified ? ['qualified' => true] : []);
}
function frag($tag, $qualified = true)
{
    $f = frags($tag, $qualified);
    return is_array($f) && count($f) === 1 ? $f[0] : ['error' => 'parseAllTags returned ' . json_encode($f)];
}
function errOf($tag, $qualified = true)
{
    $f = frag($tag, $qualified);
    return isset($f['error']) ? $f['error'] : '';
}

$f = frag('@UVDELTA={"vs":"previous-event","maxPct":10}');
check('a shorthand: kept as written for the field hook, and named', !isset($f['error']) && $f['type'] === 'delta'
    && $f['deltaVs'] === 'previous-event' && $f['deltaVsName'] === 'previous-event' && $f['deltaMaxPct'] === '10'
    && !isset($f['deltaMax']) && !isset($f['deltaDirection']));
check('the branch group: what it compares with, and both ways', $f['deltaGroup'] === 'previous-event both');
foreach (['previous-instance', 'first-event', 'first-instance'] as $s) {
    $f = frag('@UVDELTA={"vs":"' . $s . '","max":5}');
    check('shorthand ' . $s, !isset($f['error']) && $f['deltaVs'] === $s && $f['deltaVsName'] === $s);
}
$f = frag('@UVDELTA={"vs":" Previous-Event ","max":5}');
check('a shorthand in any letter case, trimmed', !isset($f['error']) && $f['deltaVs'] === 'previous-event' && $f['deltaVsName'] === 'previous-event');
check('a shorthand needs event and instance references',
    strpos(errOf('@UVDELTA={"vs":"previous-event","max":5}', false), 'needs event and instance references') !== false);
check('...each of them', strpos(errOf('@UVDELTA={"vs":"first-instance","max":5}', false), 'enable them in project settings') !== false);

$f = frag('@UVDELTA={"vs":"[weight_screening]","max":2.5,"unit":"kg"}', false);
check('a field on this entry needs no references', !isset($f['error']) && $f['deltaVs'] === '[weight_screening]'
    && !isset($f['deltaVsName']) && $f['deltaMax'] === '2.5' && $f['deltaUnit'] === 'kg');
$f = frag('@UVDELTA={"vs":"[baseline_arm_1][weight]","maxPct":15,"direction":"down"}');
check('another event, with references on', !isset($f['error']) && $f['deltaVs'] === '[baseline_arm_1][weight]'
    && $f['deltaDirection'] === 'down' && $f['deltaGroup'] === '[baseline_arm_1][weight] down');
check('another event, with references off: refused, and says why',
    strpos(errOf('@UVDELTA={"vs":"[baseline_arm_1][weight]","maxPct":15}', false), 'another event or instance needs event and instance references') !== false);
$f = frag('@UVDELTA={"vs":"[weight][previous-instance]","max":1}');
check('an instance reference', !isset($f['error']) && $f['deltaVs'] === '[weight][previous-instance]');
$f = frag('@UVDELTA={"vs":"[previous-event-name][weight][last-instance]","max":1}');
check('a relative event with an instance', !isset($f['error']));
check('branch group ignores spaces and letter case',
    AnnotationRules::deltaGroup(' [Baseline_Arm_1] [weight] ', 'up') === '[baseline_arm_1][weight] up');

$f = frag('@UVDELTA={"vs":"previous-event","max":5,"direction":"both"}');
check('"both" is the default, dropped', !isset($f['error']) && !isset($f['deltaDirection']) && $f['deltaGroup'] === 'previous-event both');
$f = frag('@UVDELTA={"vs":"previous-event","max":5,"direction":" UP "}');
check('a direction in any letter case', !isset($f['error']) && $f['deltaDirection'] === 'up' && $f['deltaGroup'] === 'previous-event up');

$f = frag('@UVDELTA={"vs":"previous-event","max":0.12345678901234567890,"maxPct":"7.50"}');
check('limits kept exactly as typed, quoted or not', !isset($f['error']) && $f['deltaMax'] === '0.12345678901234567890' && $f['deltaMaxPct'] === '7.50');
$f = frag('@UVDELTA={"vs":"previous-event","max":1e2}');
check('an exponent limit is written out', !isset($f['error']) && $f['deltaMax'] === '100');
$f = frag('@UVDELTA={"vs":"previous-event","max":0}');
check('a limit of 0', !isset($f['error']) && $f['deltaMax'] === '0');
$f = frag('@UVDELTA={"vs":"previous-event","maxPct":10,"message":{"default":"Check it.","fr":"Vérifiez."},"when":"[a]=\'1\'","blockSave":"hard","caseSensitive":true}');
check('message map, when, blockSave and caseSensitive', !isset($f['error']) && $f['message'] === 'Check it.'
    && $f['i18n']['message']['fr'] === 'Vérifiez.' && $f['when'] === "[a]='1'" && $f['blockSave'] === 'hard' && $f['caseSensitive'] === true);

// refusals
$refused = [
    '@UVDELTA'                                                     => 'needs its settings as JSON',
    '@UVDELTA=previous-event'                                      => 'needs its settings as JSON',
    '@UVDELTA={"vs":"previous-event","max":5'                      => 'JSON does not parse',
    '@UVDELTA={"vs":"previous-event","max":5,"min":1}'             => 'unknown @UVDELTA option(s): min',
    '@UVDELTA={"max":5}'                                           => 'needs "vs"',
    '@UVDELTA={"vs":"previous-event"}'                             => 'needs a limit',
    '@UVDELTA={"vs":"","max":5}'                                   => '"vs" must be previous-event, previous-instance, first-event or first-instance',
    '@UVDELTA={"vs":5,"max":5}'                                    => '"vs" must be',
    '@UVDELTA={"vs":"previous-visit","max":5}'                     => '"vs" must be previous-event',
    '@UVDELTA={"vs":"[w(1)]","max":5}'                             => 'not a checkbox code',
    '@UVDELTA={"vs":"[a] or [b]","max":5}'                         => 'exactly one field reference',
    '@UVDELTA={"vs":"{b}","max":5}'                                => 'exactly one field reference',
    '@UVDELTA={"vs":"[w][any-instance]","max":5}'                  => 'exactly one field reference',
    '@UVDELTA={"vs":"previous-event","max":-1}'                    => '"max" must be 0 or more',
    '@UVDELTA={"vs":"previous-event","maxPct":"-0.5"}'             => '"maxPct" must be 0 or more',
    '@UVDELTA={"vs":"previous-event","maxPct":0}'                  => '"maxPct": 0 does not stop a change from an earlier 0',
    '@UVDELTA={"vs":"previous-event","maxPct":"0.00"}'             => '"maxPct": 0 does not stop',
    '@UVDELTA={"vs":"previous-event","max":"abc"}'                 => '"max" must be a number such as 5',
    '@UVDELTA={"vs":"previous-event","maxPct":true}'               => '"maxPct" must be a number such as 10',
    '@UVDELTA={"vs":"previous-event","max":1e100}'                 => '"max" is longer than 64 characters',
    '@UVDELTA={"vs":"previous-event","max":5,"direction":"left"}'  => '"direction" must be both, up or down',
    '@UVDELTA={"vs":"previous-event","max":5,"direction":1}'       => '"direction" must be both, up or down',
    '@UVDELTA={"vs":"previous-event","max":5,"unit":"a very long unit label here"}' => '"unit" must be a short label',
    '@UVDELTA={"vs":"previous-event","max":5,"unit":7}'            => '"unit" must be a string',
    '@UVDELTA={"vs":"previous-event","max":5,"blockSave":"maybe"}' => '"blockSave" must be off, confirm or hard',
    '@UVDELTA={"vs":"previous-event","max":5,"when":"[a] =="}'     => 'the "when" condition',
    '@UVDELTA={"vs":"previous-event","max":5,"caseSensitive":"yes"}' => '"caseSensitive" must be true or false',
    '@UVDELTA={"vs":"previous-event","max":5,"message":5}'         => '"message" must be a string',
];
foreach ($refused as $tag => $want) {
    $e = errOf($tag);
    check('refused: ' . $tag . ' (' . $want . ') got: ' . $e, $e !== '' && strpos($e, $want) !== false);
}
$err = frag('@UVDELTA={"max":5}');
check('a refusal names its tag', isset($err['_tag']) && $err['_tag'] === '@UVDELTA');

// checkDelta on its own: the keys the parser writes, checked again for every channel
check('checkDelta: a sound fragment', AnnotationRules::checkDelta(['type' => 'delta', 'deltaVs' => '[w0]', 'deltaMax' => '5']) === []);
check('checkDelta: a written-out shorthand needs references',
    AnnotationRules::checkDelta(['type' => 'delta', 'deltaVs' => '[previous-event-name][w]', 'deltaMax' => '5']) !== []
    && AnnotationRules::checkDelta(['type' => 'delta', 'deltaVs' => '[previous-event-name][w]', 'deltaMax' => '5'], ['qualified' => true]) === []);
check('checkDelta: a limit that is not a decimal string', AnnotationRules::checkDelta(['type' => 'delta', 'deltaVs' => '[w0]', 'deltaMax' => 5]) !== []);
check('checkDelta: an unknown direction', AnnotationRules::checkDelta(['type' => 'delta', 'deltaVs' => '[w0]', 'deltaMax' => '5', 'deltaDirection' => 'both']) !== []);

// two tags on one field: parsed one by one, in order
$two = frags('@UVDELTA={"vs":"previous-event","maxPct":5} @UVDELTA={"vs":"first-event","maxPct":15}');
check('two tags parse to two fragments with their own groups', is_array($two) && count($two) === 2
    && $two[0]['deltaGroup'] === 'previous-event both' && $two[1]['deltaGroup'] === 'first-event both');
check('@UVDELTA is not read out of a longer word', AnnotationRules::parseAllTags('@UVDELTAX={"vs":"[a]","max":1}') === null);

echo "delta_php: $n checks, $fails failure(s)\n";
exit($fails ? 1 : 0);
