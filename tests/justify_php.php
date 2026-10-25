<?php
/**
 * justify_php.php — @UVJUSTIFY without a REDCap.
 *
 * Runs every case of tests/justify_fixture.json through Logic::reasonGiven;
 * tests/justify_js.cjs runs the same cases through the browser twin, so the
 * two runtimes cannot disagree about whether a reason was given. Then pins
 * the tag's grammar (what parseAllTags accepts and every refusal), the
 * registry's "justify" flags and the reason fields a rule reads, and the
 * sentence a report adds to a finding saved with a reason.
 *
 * Run:  php tests/justify_php.php
 */

require_once __DIR__ . '/../php/Logic.php';
require_once __DIR__ . '/../php/ExactDecimal.php';
require_once __DIR__ . '/../php/ModeRegistry.php';
require_once __DIR__ . '/../php/AnnotationRules.php';
require_once __DIR__ . '/../php/MessageCatalog.php';

use INSPIRE\UniversalValidator\AnnotationRules;
use INSPIRE\UniversalValidator\Logic;
use INSPIRE\UniversalValidator\MessageCatalog;
use INSPIRE\UniversalValidator\ModeRegistry;

$n = 0;
$fails = 0;
function check($label, $ok, $got = null)
{
    global $n, $fails;
    $n++;
    if (!$ok) {
        $fails++;
        echo "FAIL: $label" . ($got === null ? '' : ' — got ' . json_encode($got, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
    }
}
function text($v)
{
    if (!is_array($v) || !isset($v['repeat'])) return $v;
    return (isset($v['prefix']) ? $v['prefix'] : '') . str_repeat($v['repeat'], $v['times']) . (isset($v['suffix']) ? $v['suffix'] : '');
}

// ---- 1. whether a reason was given -------------------------------------------

$fx = json_decode(file_get_contents(__DIR__ . '/justify_fixture.json'), true);
check('the fixture parses', is_array($fx) && count($fx['cases']) >= 30);
foreach ($fx['cases'] as $c) {
    $codes = array_fill_keys($c['codes'], true);
    $got = Logic::reasonGiven(text($c['value']), $codes);
    check('reasonGiven: ' . $c['name'] . ' (want ' . json_encode($c['given']) . ')', $got === $c['given'], $got);
}
check('the codes are read as keys, whatever their value', Logic::reasonGiven('NA', ['NA' => 0]) === false);
check('no codes given: none', Logic::reasonGiven('NA') === true);
$t0 = microtime(true);
$r = Logic::reasonGiven(str_repeat(" \t", 200000) . 'x' . str_repeat("\n ", 200000));
check('400,000 characters of spaces around a word are read in under 250 ms', $r === true && (microtime(true) - $t0) < 0.25);

// ---- 2. the grammar ------------------------------------------------------------

function frags($tag, $qualified = false)
{
    return AnnotationRules::parseAllTags($tag, $qualified ? ['qualified' => true] : []);
}
function frag($tag, $qualified = false)
{
    $f = frags($tag, $qualified);
    return is_array($f) && count($f) === 1 ? $f[0] : ['error' => 'parseAllTags returned ' . json_encode($f)];
}
function errOf($tag, $qualified = false)
{
    $f = frag($tag, $qualified);
    return isset($f['error']) ? $f['error'] : '';
}

$f = frag('@UVJUSTIFY={"reason":"[weight_reason]"}');
check('the reason field, kept as a bare name', !isset($f['error']) && $f['type'] === 'justify' && $f['justifyReason'] === 'weight_reason'
    && !isset($f['message']) && !isset($f['when']) && !isset($f['blockSave']), $f);
$f = frag('@UVJUSTIFY={"reason":"  [why]  "}');
check('spaces around the reference are dropped', !isset($f['error']) && $f['justifyReason'] === 'why', $f);
$f = frag('@UVJUSTIFY={"reason":"[why]","message":"Say why the weight is right."}');
check('a message', !isset($f['error']) && $f['message'] === 'Say why the weight is right.', $f);
$f = frag('@UVJUSTIFY={"reason":"[why]","message":{"default":"Say why.","fr":"Dites pourquoi."}}');
check('a message as a language map', !isset($f['error']) && $f['message'] === 'Say why.' && $f['i18n']['message']['fr'] === 'Dites pourquoi.', $f);
$f = frag('@UVJUSTIFY={"reason":"[why]"}', true);
check('event references on change nothing', !isset($f['error']) && $f['justifyReason'] === 'why', $f);
$two = frags('@UVDELTA={"vs":"[w0]","max":5} @UVJUSTIFY={"reason":"[why]"}');
check('beside another tag: two fragments', is_array($two) && count($two) === 2 && $two[0]['type'] === 'delta' && $two[1]['type'] === 'justify', $two);
check('@UVJUSTIFY is not read out of a longer word', AnnotationRules::parseAllTags('@UVJUSTIFYX={"reason":"[why]"}') === null);

$refused = [
    '@UVJUSTIFY'                                              => 'needs the field that holds the reason',
    '@UVJUSTIFY=why'                                          => 'takes its settings as JSON',
    '@UVJUSTIFY=[why]'                                        => 'takes its settings as JSON',
    '@UVJUSTIFY={"reason":"[why]"'                            => 'JSON does not parse',
    '@UVJUSTIFY={}'                                           => 'needs "reason"',
    '@UVJUSTIFY={"message":"Say why."}'                       => 'needs "reason"',
    '@UVJUSTIFY={"reason":"why"}'                             => '"reason" must be one field reference',
    '@UVJUSTIFY={"reason":""}'                                => '"reason" must be one field reference',
    '@UVJUSTIFY={"reason":5}'                                 => '"reason" must be one field reference',
    '@UVJUSTIFY={"reason":["[why]"]}'                         => '"reason" must be one field reference',
    '@UVJUSTIFY={"reason":"[Why]"}'                           => '"reason" must be one field reference',
    '@UVJUSTIFY={"reason":"[1why]"}'                          => '"reason" must be one field reference',
    '@UVJUSTIFY={"reason":"[why] [other]"}'                   => '"reason" must be one field reference',
    '@UVJUSTIFY={"reason":"[why(1)]"}'                        => '"reason" must be one field reference',
    '@UVJUSTIFY={"reason":"[why]x"}'                          => '"reason" must be one field reference',
    '@UVJUSTIFY={"reason":"[visit_1_arm_1][why]"}'            => 'with no event or instance',
    '@UVJUSTIFY={"reason":"[why][previous-instance]"}'        => 'with no event or instance',
    '@UVJUSTIFY={"reason":"[why]","when":"[a]=\'1\'"}'        => '@UVJUSTIFY takes no "when"',
    '@UVJUSTIFY={"reason":"[why]","caseSensitive":true}'      => '@UVJUSTIFY takes no "caseSensitive"',
    '@UVJUSTIFY={"reason":"[why]","blockSave":"off"}'         => '@UVJUSTIFY takes no "blockSave"',
    '@UVJUSTIFY={"reason":"[why]","references":true}'         => '@UVJUSTIFY takes no "references"',
    '@UVJUSTIFY={"reason":"[why]","label":"x"}'               => 'unknown @UVJUSTIFY option(s): label',
    '@UVJUSTIFY={"reason":"[why]","roles":["PI"]}'            => 'unknown @UVJUSTIFY option(s): roles',
    '@UVJUSTIFY={"reason":"[why]","message":5}'               => '"message" must be',
];
foreach ($refused as $tag => $want) {
    $e = errOf($tag);
    check('refused: ' . $tag . ' (' . $want . ') got: ' . $e, $e !== '' && strpos($e, $want) !== false);
}
check('a key it does not take says the reason stands whenever the rules apply',
    strpos(errOf('@UVJUSTIFY={"reason":"[why]","when":"[a]=1"}'), 'a reason stands for the other rules on the field whenever they apply') !== false);
$err = frag('@UVJUSTIFY={}');
check('a refusal names its tag', isset($err['_tag']) && $err['_tag'] === '@UVJUSTIFY', $err);

// checkJustify on its own: the keys the parser writes, checked again for every channel
check('checkJustify: a sound fragment', AnnotationRules::checkJustify(['type' => 'justify', 'justifyReason' => 'why']) === []);
check('checkJustify: no reason', AnnotationRules::checkJustify(['type' => 'justify']) !== []);
check('checkJustify: a reason that is not a name', AnnotationRules::checkJustify(['type' => 'justify', 'justifyReason' => '[why]']) !== []
    && AnnotationRules::checkJustify(['type' => 'justify', 'justifyReason' => 5]) !== []
    && AnnotationRules::checkJustify(['type' => 'justify', 'justifyReason' => "why\n"]) !== []);
foreach (['when' => "[a]='1'", 'caseSensitive' => true, 'blockSave' => 'hard'] as $k => $v) {
    check('checkJustify: refuses "' . $k . '"', AnnotationRules::checkJustify(['type' => 'justify', 'justifyReason' => 'why', $k => $v]) !== []);
}

// ---- 3. the registry -------------------------------------------------------------

$stands = [];
$not = [];
foreach (ModeRegistry::all() as $m) {
    if ($m['mode'] === 'justify') continue;
    if (ModeRegistry::justifies($m['mode'])) $stands[] = $m['mode']; else $not[] = $m['mode'];
}
sort($stands);
sort($not);
check('a reason stands for these modes', $stands === ['check', 'choices', 'constraint', 'delta', 'exists', 'range', 'required', 'window'], $stands);
check('and never for a duplicate or a lock', $not === ['unique', 'writeonce'], $not);
check('nor for itself', ModeRegistry::justifies('justify') === false);
check('justifyFields: the reason fields, once each', ModeRegistry::justifyFields(['justifyBy' => ['a' => 'why', 'b' => 'why', 'c' => 'other']]) === ['why', 'other']);
check('justifyFields: nothing to read', ModeRegistry::justifyFields([]) === [] && ModeRegistry::justifyFields(['justifyBy' => 'why']) === []
    && ModeRegistry::justifyFields(['justifyBy' => ['a' => '', 'b' => 5]]) === []);
$rule = ['type' => 'constraint', 'fields' => ['a'], 'expr' => '[a] > 0', 'justifyBy' => ['a' => 'why']];
check('refFields reads the reason field by default', in_array('why', ModeRegistry::refFields($rule), true));
check('refFields without "reason" does not', !in_array('why', ModeRegistry::refFields($rule, ['cond', 'operand', 'fieldList']), true));
check('the justify mode itself finds nothing a reason could stand for', ModeRegistry::mode('justify')['evaluator'] === 'findingsJustify');

// ---- 4. the report sentence --------------------------------------------------------

$finding = ['field' => 'wt', 'type' => 'delta', 'reason' => 'up', 'justified' => true, 'justifiedBy' => 'wt_why'];
$d = MessageCatalog::detail($finding, []);
check('a finding saved with a reason names the reason field', strpos($d, 'It was saved with a reason, given in [wt_why].') !== false, $d);
$d = MessageCatalog::detail(['field' => 'wt', 'type' => 'delta', 'reason' => 'up', 'justified' => true], ['justifyBy' => ['wt' => 'wt_reason']]);
check('a stored finding keeps only the flag: the rule names the reason field', strpos($d, '[wt_reason]') !== false, $d);
$d = MessageCatalog::detail(['field' => 'wt', 'type' => 'delta', 'reason' => 'up', 'justified' => true], ['justifyBy' => ['ht' => 'ht_reason']]);
check('a rule that pairs another field says nothing', strpos($d, 'saved with a reason') === false, $d);
$d = MessageCatalog::detail(['field' => 'wt', 'type' => 'delta', 'reason' => 'up', 'justifiedBy' => 'wt_why'], []);
check('an open finding says nothing about a reason', strpos($d, 'saved with a reason') === false, $d);
$d = MessageCatalog::detail(['field' => 'v', 'type' => 'window', 'reason' => 'late', 'justified' => true, 'justifiedBy' => 'v_why'], []);
check('every type gets the sentence, from the */* entry', strpos($d, 'given in [v_why]') !== false, $d);

echo "justify_php: $n checks, $fails failure(s)\n";
exit($fails ? 1 : 0);
