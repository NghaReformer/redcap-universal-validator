<?php
/**
 * choices_php.php — unit tests for the @UVCHOICES annotation parser and its
 * branching behavior (dynamic choice filtering, "choices" mode).
 *
 * AnnotationRules and Branching are pure PHP with no REDCap dependency, so the
 * whole parse -> validate -> group -> branch chain is tested here without a
 * REDCap runtime. The dictionary-dependent half (code existence, choicesAll
 * attachment, the post-save audit) lives in tests/hook_php.php; the client
 * twin is tests/choices_dom_js.cjs, sharing tests/choices_fixture.json.
 *
 * Run:  php tests/choices_php.php
 */

require_once __DIR__ . '/../php/AnnotationRules.php';
require_once __DIR__ . '/../php/Branching.php';

use INSPIRE\UniversalValidator\AnnotationRules;
use INSPIRE\UniversalValidator\Branching;

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

/** First parsed fragment of one annotation (the common single-tag case). */
function frag($annotation)
{
    $all = AnnotationRules::parseAllTags($annotation);
    return $all === null ? null : $all[0];
}

// ---- tag extraction boundaries ----
check('no tag -> null', AnnotationRules::parseAllTags('@READONLY @HIDECHOICE=1') === null);
check('@UVCHOICESY is a different tag', AnnotationRules::parseAllTags('@UVCHOICESY={"hide":["1"]}') === null);
check('lowercase tag accepted', frag('@uvchoices={"hide":["9"]}')['choicesHide'] === ['9']);

// ---- value forms ----
$r = frag('@UVCHOICES');
check('bare tag -> error teaching the JSON form', isset($r['error']) && strpos($r['error'], 'JSON form') !== false);
$r = frag('@UVCHOICES=show');
check('non-JSON value -> error', isset($r['error']));
$r = frag('@UVCHOICES={"when":"[country]=\'1\'","show":["101","102"]}');
check('valid show parses', !isset($r['error']) && $r['type'] === 'choices'
    && $r['choicesShow'] === ['101', '102'] && $r['when'] === "[country]='1'");
$r = frag('@UVCHOICES={"show":[1,2]}');
check('numeric codes normalize to strings', !isset($r['error']) && $r['choicesShow'] === ['1', '2']);
$r = frag('@UVCHOICES={"show":[" 1 ","2"]}');
check('codes are trimmed', !isset($r['error']) && $r['choicesShow'] === ['1', '2']);
$r = frag('@UVCHOICES={"hide":["9"]}');
check('valid hide parses (when optional)', !isset($r['error']) && $r['choicesHide'] === ['9'] && !isset($r['when']));
$r = frag('@UVCHOICES={"when":"[pilot(1)]=\'1\'","show":["s01"],"message":"Pilot sites only.","blockSave":"hard"}');
check('message + blockSave pass through', !isset($r['error'])
    && $r['message'] === 'Pilot sites only.' && $r['blockSave'] === 'hard');

// ---- value errors ----
$r = frag('@UVCHOICES={"show":["1"],"hide":["2"]}');
check('show + hide together -> error', isset($r['error']) && strpos($r['error'], 'cannot be combined') !== false);
$r = frag('@UVCHOICES={"when":"[x]=\'1\'"}');
check('neither show nor hide -> error', isset($r['error']) && strpos($r['error'], '"show" or "hide"') !== false);
$r = frag('@UVCHOICES={"show":[]}');
check('empty show list -> error', isset($r['error']) && strpos($r['error'], 'non-empty') !== false);
$r = frag('@UVCHOICES={"hide":["2","2"]}');
check('duplicate code -> error', isset($r['error']) && strpos($r['error'], 'twice') !== false);
$r = frag('@UVCHOICES={"show":' . json_encode(array_map('strval', range(1, 201))) . '}');
check('code list over the cap -> error', isset($r['error']) && strpos($r['error'], '200') !== false);
$r = frag('@UVCHOICES={"show":[["1"]]}');
check('non-scalar code entry -> error', isset($r['error']) && strpos($r['error'], 'not a choice code') !== false);
$r = frag('@UVCHOICES={"show":[""]}');
check('empty-string code -> error', isset($r['error']));
$r = frag('@UVCHOICES={"schow":["1"]}');
check('typo key -> error listing it', isset($r['error']) && strpos($r['error'], 'schow') !== false);
$r = frag("@UVCHOICES={'show':['1']}");
check('single-quoted json -> parse error with hint', isset($r['error']) && strpos($r['error'], 'double quotes') !== false);
$r = frag('@UVCHOICES={"show":["1"],"when":"datediff([a],[b],\'d\')>3"}');
check('unsupported when syntax -> error', isset($r['error']) && strpos($r['error'], '"when"') !== false);
$r = frag('@UVCHOICES={"show":["1"],"blockSave":"maybe"}');
check('bad blockSave -> error', isset($r['error']));
$r = frag('@UVCHOICES={"show":["1"],"message":3}');
check('non-string message -> error', isset($r['error']));

// ---- checkChoices directly (the validator the settings gate would share) ----
check('checkChoices: sound fragment -> no errors',
    AnnotationRules::checkChoices(['type' => 'choices', 'choicesHide' => ['9']]) === []);
$errs = AnnotationRules::checkChoices(['type' => 'choices']);
check('checkChoices: neither list -> error', count($errs) === 1);
$errs = AnnotationRules::checkChoices(['type' => 'choices', 'choicesShow' => ['1'], 'choicesHide' => ['2']]);
check('checkChoices: both lists -> error', count($errs) === 1);

// ---- multiple tags on one field / composition with other modes ----
$all = AnnotationRules::parseAllTags(
    '@UVCHOICES={"when":"[c]=\'1\'","show":["1"]} @UVCHOICES={"when":"[c]=\'2\'","show":["2"]}');
check('two tags -> two fragments', count($all) === 2
    && $all[0]['choicesShow'] === ['1'] && $all[1]['choicesShow'] === ['2']);
$all = AnnotationRules::parseAllTags('@UVREQUIRED @UVCHOICES={"hide":["9"]}');
$types = array_map(function ($f) { return $f['type']; }, $all);
sort($types);
check('choices composes with other modes in parseAllTags', $types === ['choices', 'required']);

// ---- groupMulti ----
$fragA = ['type' => 'choices', 'choicesHide' => ['9'], 'choicesAll' => ['1', '2', '9']];
$rules = AnnotationRules::groupMulti(['f1' => [$fragA], 'f2' => [$fragA]]);
check('identical fragments (same choicesAll) share one rule',
    count($rules) === 1 && $rules[0]['fields'] === ['f1', 'f2'] && $rules[0]['type'] === 'choices');
$fragB = ['type' => 'choices', 'choicesHide' => ['9'], 'choicesAll' => ['1', '9']];
$rules = AnnotationRules::groupMulti(['f1' => [$fragA], 'f2' => [$fragB]]);
check('different choicesAll never merge', count($rules) === 2);
$rules = AnnotationRules::groupMulti(['f1' => [['error' => 'boom', '_tag' => AnnotationRules::TAG_CHOICES]]]);
check('error fragment -> per-field configError naming @UVCHOICES',
    count($rules) === 1 && strpos($rules[0]['configError'], '@UVCHOICES on "f1"') === 0);

// ---- Branching ----
check('modeOfType routes choices to its own mode', Branching::modeOfType('choices') === 'choices');

$mk = function ($when, $show, $all = ['1', '2', '3']) {
    $r = ['type' => 'choices', 'fields' => ['site'], 'choicesShow' => $show, 'choicesAll' => $all];
    if ($when !== null) $r['when'] = $when;
    return $r;
};
$resolved = Branching::resolve([$mk("[c]='1'", ['1']), $mk("[c]='2'", ['2']), $mk(null, ['3'])]);
check('conditional tags + else -> one branch rule', count($resolved) === 1
    && $resolved[0]['type'] === 'choices' && isset($resolved[0]['branches'])
    && count($resolved[0]['branches']) === 3);
$b = $resolved[0]['branches'];
check('else branch is forced last', $b[2]['when'] === null && $b[2]['choicesShow'] === ['3']);
check('branches carry choicesShow and choicesAll',
    $b[0]['choicesShow'] === ['1'] && $b[0]['choicesAll'] === ['1', '2', '3']);

$resolved = Branching::resolve([$mk(null, ['1']), $mk(null, ['2'])]);
check('two unconditional choices tags -> configError', count($resolved) === 1
    && isset($resolved[0]['configError'])
    && strpos($resolved[0]['configError'], 'no "when" condition') !== false);

$resolved = Branching::resolve([$mk("[c]='1'", ['1']), $mk("[c]='1'", ['2'])]);
check('identical when -> configError', count($resolved) === 1 && isset($resolved[0]['configError']));

$req = ['type' => 'required', 'fields' => ['site']];
$resolved = Branching::resolve([$mk(null, ['1']), $req]);
check('choices + required on one field compose (no branch, no conflict)',
    count($resolved) === 2 && !isset($resolved[0]['configError']) && !isset($resolved[1]['configError'])
    && !isset($resolved[0]['branches']) && !isset($resolved[1]['branches']));

// ---- summary ----
echo ($fail === 0 ? "OK" : "FAILED") . " — $n checks, $fail failures\n";
exit($fail === 0 ? 0 : 1);
