<?php
/**
 * message_i18n_php.php — a rule "message" given as a map of Multi-Language
 * Management language id to text.
 *
 * Asserts, through the real parser (AnnotationRules::parseAllTags):
 *   - BACKWARD COMPATIBILITY: a string message parses to exactly the fragment
 *     it always did, with no "i18n" key, on every tag that takes a message,
 *   - a map becomes the default string under "message" ("default", else the
 *     first language) and the other languages under i18n.message, keyed by
 *     lowercased language id; a map of only "default" adds no "i18n",
 *   - malformed maps are refused with a message that names the problem,
 *   - the map survives grouping (AnnotationRules::groupMulti), branching
 *     (Branching::resolve) and the page shape (ModeRegistry::clientShape),
 *   - the ID check tag (@UVALIDATE) still refuses "message", as it always has.
 *
 * Run:  php tests/message_i18n_php.php
 */

require_once __DIR__ . '/../php/AnnotationRules.php';
require_once __DIR__ . '/../php/Branching.php';
require_once __DIR__ . '/../php/ModeRegistry.php';

use INSPIRE\UniversalValidator\AnnotationRules;
use INSPIRE\UniversalValidator\Branching;
use INSPIRE\UniversalValidator\ModeRegistry;

$n = 0;
$fail = 0;

function check($label, $cond)
{
    global $n, $fail;
    $n++;
    if (!$cond) {
        $fail++;
        fwrite(STDERR, "FAIL: $label\n");
    }
}

/** The single fragment one annotation parses to. */
function frag($annotation)
{
    $all = AnnotationRules::parseAllTags($annotation);
    return is_array($all) && count($all) === 1 ? $all[0] : null;
}

/** One tag per mode that shows a message; {M} is where the message goes. */
$TAGS = [
    'constraint' => '@UVASSERT={"assert":"[a]>1",{M}}',
    'required'   => '@UVREQUIRED={{M}}',
    'unique'     => '@UVUNIQUE={{M}}',
    'choices'    => '@UVCHOICES={"hide":["9"],{M}}',
    'window'     => '@UVWINDOW={"from":"[bl]","window":[0,30],{M}}',
    'exists'     => '@UVEXISTS={"in":"[spec_id]",{M}}',
    'range'      => '@UVRANGE={"hard":[1,2],{M}}',
];
$withMsg = function ($tag, $json) { return str_replace('{M}', '"message":' . $json, $tag); };

// ---- 1) a string message is untouched ---------------------------------------
foreach ($TAGS as $mode => $tag) {
    $f = frag($withMsg($tag, '"Too high"'));
    check($mode . ': string message parses', is_array($f) && !isset($f['error']));
    check($mode . ': message stays the string', ($f['message'] ?? null) === 'Too high');
    check($mode . ': no i18n key for a string message', is_array($f) && !array_key_exists('i18n', $f));
    $bare = frag(str_replace([',{M}', '{M}'], '', $tag));
    if (is_array($bare) && !isset($bare['error'])) {
        $bare['message'] = 'Too high';
        ksort($bare);
        $g = $f;
        ksort($g);
        check($mode . ': exactly the fragment it was, plus the message', $g === $bare);
    }
}
check('the documented required form is unchanged',
    frag('@UVREQUIRED={"when":"[consent]=\'1\'","message":"Phone needed","blockSave":"hard"}')
    === ['type' => 'required', 'when' => "[consent]='1'", 'message' => 'Phone needed', 'blockSave' => 'hard']);
check('shorthand tags carry no message and no i18n',
    frag('@UVREQUIRED') === ['type' => 'required'] && frag('@UVASSERT="[a]>1"') === ['type' => 'constraint', 'assert' => '[a]>1']);

// ---- 2) a map ----------------------------------------------------------------
foreach ($TAGS as $mode => $tag) {
    $f = frag($withMsg($tag, '{"default":"Too high","FR":"Trop élevé"}'));
    check($mode . ': map parses', is_array($f) && !isset($f['error']));
    check($mode . ': message is the default', ($f['message'] ?? null) === 'Too high');
    check($mode . ': other languages under i18n, id lowercased', ($f['i18n'] ?? null) === ['message' => ['fr' => 'Trop élevé']]);
}
$f = frag('@UVREQUIRED={"message":{"en":"Fill it","fr":"Remplir","fr-CA":"Remplir (CA)"}}');
check('no "default": the first language is the default', $f['message'] === 'Fill it');
check('no "default": every language kept for the page', $f['i18n']['message'] === ['en' => 'Fill it', 'fr' => 'Remplir', 'fr-ca' => 'Remplir (CA)']);
$f = frag('@UVREQUIRED={"message":{"fr":"Remplir","default":"Fill it"}}');
check('"default" wins wherever it is listed', $f['message'] === 'Fill it' && $f['i18n']['message'] === ['fr' => 'Remplir']);
$f = frag('@UVREQUIRED={"message":{"default":"Fill it"}}');
check('only "default": a plain string message, no i18n', $f === ['type' => 'required', 'message' => 'Fill it']);
$f = frag('@UVREQUIRED={"message":{"DEFAULT":"Fill it","es_MX":"Llénelo"}}');
check('"DEFAULT" and underscores accepted', $f['message'] === 'Fill it' && $f['i18n']['message'] === ['es_mx' => 'Llénelo']);
$f = frag('@UVREQUIRED={"message":{"default":"Value <b>&amp;</b>","fr":"Valeur é"}}');
check('text kept as written (the page escapes it)', $f['message'] === 'Value <b>&amp;</b>' && $f['i18n']['message']['fr'] === 'Valeur é');
$many = [];
for ($i = 0; $i < AnnotationRules::MAX_TEXT_LANGUAGES; $i++) $many['l' . $i] = 'x' . $i;
$f = frag('@UVREQUIRED={"message":' . json_encode($many) . '}');
check('the language cap itself is allowed', is_array($f) && !isset($f['error']) && count($f['i18n']['message']) === AnnotationRules::MAX_TEXT_LANGUAGES);

// ---- 3) refusals --------------------------------------------------------------
$refused = [
    'a list'                  => ['["a","b"]', 'must be a string, or an object of language id to text'],
    'an empty object'         => ['{}', 'must be a string, or an object of language id to text'],
    'a number'                => ['12', 'must be a string'],
    'a text that is a number' => ['{"fr":12}', 'text for "fr" must be a non-empty string'],
    'an empty text'           => ['{"default":"x","fr":"  "}', 'text for "fr" must be a non-empty string'],
    'a nested object'         => ['{"fr":{"a":"b"}}', 'text for "fr" must be a non-empty string'],
    'an id with a space'      => ['{"fr ca":"x"}', 'language id "fr ca" is not valid'],
    'an id starting with a digit' => ['{"1fr":"x"}', 'language id "1fr" is not valid'],
    'a numeric id'            => ['{"12":"x"}', 'language id "12" is not valid'],
    'an id starting with _'   => ['{"__proto__":"x"}', 'language id "__proto__" is not valid'],
    'the same id twice by case' => ['{"fr":"a","FR":"b"}', 'names language "fr" twice'],
    '"default" twice by case' => ['{"default":"a","Default":"b"}', 'names language "default" twice'],
];
foreach ($refused as $label => $case) {
    $f = frag('@UVREQUIRED={"message":' . $case[0] . '}');
    check('refused: ' . $label, isset($f['error']) && strpos($f['error'], $case[1]) !== false);
}
$many['l_last'] = 'x';
$f = frag('@UVREQUIRED={"message":' . json_encode($many) . '}');
check('refused: more languages than the cap', isset($f['error']) && strpos($f['error'], 'more than ' . AnnotationRules::MAX_TEXT_LANGUAGES) !== false);
$f = frag('@UVALIDATE={"message":{"default":"x"}}');
check('@UVALIDATE has no message option, map or not', isset($f['error']));
$f = frag('@UVRANGE={"hard":[1,2],"unit":{"default":"kg","fr":"kg"}}');
check('"unit" is not a text key: still a plain string only', isset($f['error']) && strpos($f['error'], '"unit" must be a string') !== false);

// ---- 4) grouping, branching, page shape ----------------------------------------
$a = frag('@UVREQUIRED={"message":{"default":"Fill it","fr":"Remplir"}}');
$b = frag('@UVREQUIRED={"message":{"default":"Fill it","fr":"Remplissez"}}');
$rules = AnnotationRules::groupMulti(['f1' => [$a], 'f2' => [$a]]);
check('same map on two fields: one rule', count($rules) === 1 && $rules[0]['fields'] === ['f1', 'f2']);
$rules = AnnotationRules::groupMulti(['f1' => [$a], 'f2' => [$b]]);
check('a different translation is a different rule', count($rules) === 2);

$two = AnnotationRules::parseAllTags(
    '@UVREQUIRED={"when":"[c]=\'1\'","message":{"default":"One","fr":"Un"}} '
    . '@UVREQUIRED={"when":"[c]=\'2\'","message":"Two"}');
$grouped = AnnotationRules::groupMulti(['site' => $two]);
$resolved = Branching::resolve($grouped);
check('two required tags on one field branch', count($resolved) === 1 && isset($resolved[0]['branches'])
    && count($resolved[0]['branches']) === 2);
$br = $resolved[0]['branches'];
check('the branch with a map carries it', ($br[0]['i18n'] ?? null) === ['message' => ['fr' => 'Un']] && $br[0]['message'] === 'One');
check('the branch with a string carries no map', !isset($br[1]['i18n']) && $br[1]['message'] === 'Two');

$page = ModeRegistry::clientShape($resolved[0]);
check('the page keeps the map', ($page['branches'][0]['i18n'] ?? null) === ['message' => ['fr' => 'Un']]);
$page = ModeRegistry::clientShape(['type' => 'required', 'fields' => ['x']] + $a, true);
check('a survey page keeps the map', ($page['i18n'] ?? null) === ['message' => ['fr' => 'Remplir']]);
check('i18n is a branch key and a client key, never a server key',
    in_array('i18n', ModeRegistry::branchKeys(), true) && in_array('i18n', ModeRegistry::clientKeys(), true)
    && !in_array('i18n', ModeRegistry::serverKeys(), true));

// ---- summary ----
echo ($fail === 0 ? "OK" : "FAILED") . " — $n checks, $fail failures\n";
exit($fail === 0 ? 0 : 1);
