<?php
/**
 * annotation_php.php — unit tests for the @UVALIDATE annotation parser.
 *
 * AnnotationRules is pure PHP with no REDCap dependency, so the whole
 * annotation-configuration channel (extract -> parse -> validate -> group) is
 * tested here without a REDCap runtime.
 *
 * Run:  php tests/annotation_php.php
 */

require_once __DIR__ . '/../php/AnnotationRules.php';

use INSPIRE\UniversalValidator\AnnotationRules;
use INSPIRE\UniversalValidator\CheckCharacter;

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

// ---- extractTag ----
check('absent tag -> null', AnnotationRules::extractTag('@READONLY @HIDDEN') === null);
check('bare tag', AnnotationRules::extractTag('@UVALIDATE') === '');
check('bare tag among others', AnnotationRules::extractTag('@READONLY @UVALIDATE @HIDDEN-SURVEY') === '');
check('lowercase tag accepted', AnnotationRules::extractTag('@uvalidate=damm') === 'damm');
check('bare token value', AnnotationRules::extractTag('@UVALIDATE=iso7064_mod11_10') === 'iso7064_mod11_10');
check('token ends at whitespace', AnnotationRules::extractTag('@UVALIDATE=damm @READONLY') === 'damm');
check('double-quoted value', AnnotationRules::extractTag('@UVALIDATE="damm" @X') === 'damm');
check('single-quoted value', AnnotationRules::extractTag("@UVALIDATE='damm'") === 'damm');
check('json value balanced', AnnotationRules::extractTag('@UVALIDATE={"algorithm":"damm"} @READONLY')
    === '{"algorithm":"damm"}');
check('json with nested braces in string', AnnotationRules::extractTag('@UVALIDATE={"pattern":"a{2}b}c"}')
    === '{"pattern":"a{2}b}c"}');
check('@UVALIDATED is a different tag', AnnotationRules::extractTag('@UVALIDATED=x') === null);
check('@UVALIDATED then real tag', AnnotationRules::extractTag('@UVALIDATED=x @UVALIDATE=luhn') === 'luhn');

// ---- parseField ----
check('untagged -> null', AnnotationRules::parseField('@READONLY') === null);
check('bare -> defaults', AnnotationRules::parseField('@UVALIDATE') === []);
$r = AnnotationRules::parseField('@UVALIDATE=verhoeff');
check('algorithm shorthand', $r === ['algorithm' => 'verhoeff']);
$r = AnnotationRules::parseField('@UVALIDATE=notanalgo');
check('unknown algorithm -> error', isset($r['error']) && strpos($r['error'], 'notanalgo') !== false);
$r = AnnotationRules::parseField('@UVALIDATE=none');
check('bare none -> error (needs pattern)', isset($r['error']));
$r = AnnotationRules::parseField('@UVALIDATE={"algorithm":"none","pattern":"FC[0-9]{4}","blockSave":"hard"}');
check('regex-only json ok', !isset($r['error']) && $r['idPattern'] === 'FC[0-9]{4}' && $r['blockSave'] === 'hard');
$r = AnnotationRules::parseField('@UVALIDATE={"type":"pooled","idLengths":"10, 12","expectedIds":3}');
check('pooled json with csv lengths', !isset($r['error']) && $r['idLengths'] === [10, 12] && $r['expectedIds'] === 3);
$r = AnnotationRules::parseField('@UVALIDATE={"algoritm":"damm"}');
check('typo key -> error listing it', isset($r['error']) && strpos($r['error'], 'algoritm') !== false);
$r = AnnotationRules::parseField("@UVALIDATE={'algorithm':'damm'}");
check('single-quoted json -> parse error with hint', isset($r['error']) && strpos($r['error'], 'double quotes') !== false);
$r = AnnotationRules::parseField('@UVALIDATE={"pattern":"(a+)+"}');
check('catastrophic pattern -> error', isset($r['error']) && strpos($r['error'], 'backtracking') !== false);
// F1: a long chain of overlapping BOUNDED quantifiers (freezes the browser engine
// even though PCRE stays fast) is now gated by riskyPattern stage two-b.
$r = AnnotationRules::parseField('@UVALIDATE={"algorithm":"none","pattern":"A{1,20}A{1,20}A{1,20}A{1,20}A{1,20}9"}');
check('F1: long bounded-quantifier chain -> backtracking error',
    isset($r['error']) && strpos($r['error'], 'backtracking') !== false);
// The deliberate 3-factor residue stays under the budget and still passes.
$r = AnnotationRules::parseField('@UVALIDATE={"algorithm":"none","pattern":"A{1,40}A{1,40}A{1,40}9"}');
check('F1: 3-factor bounded residue still passes', !isset($r['error']) && $r['idPattern'] === 'A{1,40}A{1,40}A{1,40}9');
// F2: \p{}/\u{}/\k<> need JS's "u" flag; the browser compiles ID patterns without
// it, so the value would validate differently in the browser and on the server.
$r = AnnotationRules::parseField('@UVALIDATE={"algorithm":"none","pattern":"\\\\p{Lu}{3}\\\\p{Nd}{5}"}');
check('F2: \\p{} unicode-property pattern -> u-flag dialect error',
    isset($r['error']) && strpos($r['error'], 'flag') !== false);
$r = AnnotationRules::parseField('@UVALIDATE={"algorithm":"none","pattern":"\\\\u{41}[0-9]{3}"}');
check('F2: \\u{} code-point escape -> u-flag dialect error',
    isset($r['error']) && strpos($r['error'], 'flag') !== false);
// F2-BYPASS-01: \x{...} is the same u-flag-only brace escape as \u{...} (\x{41}
// is code point 'A' under PCRE /u but literal x{41}=41 x's in the browser).
$r = AnnotationRules::parseField('@UVALIDATE={"algorithm":"none","pattern":"\\\\x{41}[0-9]{3}"}');
check('F2-BYPASS-01: \\x{} code-point escape -> u-flag dialect error',
    isset($r['error']) && strpos($r['error'], 'flag') !== false);
// F2-OVERREJECT-02: a LITERAL backslash pair before u{ is not a \u escape -> accepted.
$r = AnnotationRules::parseField('@UVALIDATE={"algorithm":"none","pattern":"\\\\\\\\u{2}"}');
check('F2-OVERREJECT-02: literal-backslash \\\\u{ is not a u-flag escape -> accepted',
    !isset($r['error']));
// F1/F2 control: a plain bounded pattern with disjoint classes is still accepted.
$r = AnnotationRules::parseField('@UVALIDATE={"algorithm":"none","pattern":"[A-Z]{3}[0-9]{5}"}');
check('F1/F2 control: disjoint bounded pattern still passes',
    !isset($r['error']) && $r['idPattern'] === '[A-Z]{3}[0-9]{5}');
// M-03: regex-only pooled + expectedIds over a VARIABLE length is ambiguous (no
// check character to disambiguate the split) -> config error.
$r = AnnotationRules::parseField('@UVALIDATE={"type":"pooled","algorithm":"none","pattern":"[A-Z]{2,3}","idLengths":[2,3],"expectedIds":2}');
check('M-03: regex-only pooled + expectedIds + variable length -> ambiguity error',
    isset($r['error']) && strpos($r['error'], 'expectedIds') !== false && strpos($r['error'], 'ambiguous') !== false);
// control: a single EXACT length is unambiguous -> fine.
$r = AnnotationRules::parseField('@UVALIDATE={"type":"pooled","algorithm":"none","pattern":"[A-Z]{3}","idLengths":[3],"expectedIds":2}');
check('M-03 control: regex-only pooled + expectedIds + single exact length is fine', !isset($r['error']));
// control: a CHECK algorithm disambiguates by verification -> variable + expectedIds fine.
$r = AnnotationRules::parseField('@UVALIDATE={"type":"pooled","idLengths":[10,12],"expectedIds":3}');
check('M-03 control: check-mode pooled + expectedIds + variable length is fine', !isset($r['error']));
$r = AnnotationRules::parseField('@UVALIDATE={"blockSave":"maybe"}');
check('bad blockSave -> error', isset($r['error']));
$r = AnnotationRules::parseField('@UVALIDATE={"expectedIds":"three"}');
check('bad expectedIds -> error', isset($r['error']));
$r = AnnotationRules::parseField('@UVALIDATE={"type":"multi"}');
check('bad type -> error', isset($r['error']));
$r = AnnotationRules::parseField('@UVALIDATE={"source":"digits_only","blockSave":"confirm"}');
check('source + blockSave pass through', !isset($r['error']) && $r['source'] === 'digits_only');

// ---- algorithm synonyms (ease-of-use shorthands) ----
check('numeric shorthand 3736 -> mod37_36',
    AnnotationRules::canonicalAlgorithm('3736') === 'iso7064_mod37_36');
check('underscore shorthand mod11_10 -> canonical',
    AnnotationRules::canonicalAlgorithm('mod11_10') === 'iso7064_mod11_10');
check('comma shorthand 97,10 -> canonical',
    AnnotationRules::canonicalAlgorithm('97,10') === 'iso7064_mod97_10');
check('luhn shorthand mod10 -> luhn', AnnotationRules::canonicalAlgorithm('mod10') === 'luhn');
check('regex shorthand -> none', AnnotationRules::canonicalAlgorithm('regex') === 'none');
check('canonical name passes through unchanged',
    AnnotationRules::canonicalAlgorithm('damm') === 'damm');
check('canonical name is case-normalized',
    AnnotationRules::canonicalAlgorithm('ISO7064_MOD37_36') === 'iso7064_mod37_36');
check('shorthand is case-insensitive', AnnotationRules::canonicalAlgorithm('MOD37_36') === 'iso7064_mod37_36');
check('unknown value returned unchanged (whitelist still errors later)',
    AnnotationRules::canonicalAlgorithm('bogus') === 'bogus');
check('non-string is returned unchanged', AnnotationRules::canonicalAlgorithm(null) === null);
// end-to-end through the annotation parser
check('bare-tag shorthand parses to the canonical rule',
    AnnotationRules::parseField('@UVALIDATE=3736') === ['algorithm' => 'iso7064_mod37_36']);
$r = AnnotationRules::parseField('@UVALIDATE={"algorithm":"mod97_10"}');
check('json shorthand is canonicalized', !isset($r['error']) && $r['algorithm'] === 'iso7064_mod97_10');
$r = AnnotationRules::parseField('@UVALIDATE={"algorithm":"regex","pattern":"FC[0-9]{4}"}');
check('regex shorthand + pattern behaves like algorithm none',
    !isset($r['error']) && $r['algorithm'] === 'none' && $r['idPattern'] === 'FC[0-9]{4}');
$r = AnnotationRules::parseField('@UVALIDATE=regex');
check('bare regex shorthand without a pattern is still rejected', isset($r['error']));
// maintenance guard: no shorthand may collide with a canonical name or another shorthand
$seenSyn = [];
$synCollision = false;
foreach (AnnotationRules::ALGORITHM_SYNONYMS as $canon => $syns) {
    if (!in_array($canon, AnnotationRules::ALGORITHMS, true)) $synCollision = true; // maps to a real algorithm
    foreach ($syns as $s) {
        if ($s !== strtolower($s)) $synCollision = true;                             // must be stored lowercase
        if (in_array($s, AnnotationRules::ALGORITHMS, true)) $synCollision = true;   // clashes a canonical name
        if (isset($seenSyn[$s])) $synCollision = true;                               // duplicate shorthand
        $seenSyn[$s] = $canon;
    }
}
check('no shorthand collides with a canonical name or another shorthand', !$synCollision);

// ---- checkFragment: the one semantic validator shared by all channels ----
function fragErrors($frag) { return AnnotationRules::checkFragment($frag); }
check('sound single fragment -> no errors', fragErrors(['algorithm' => 'damm']) === []);
check('sound pooled fragment -> no errors',
    fragErrors(['type' => 'pooled', 'idLengths' => [10, 12], 'expectedIds' => 3]) === []);
check('unknown algorithm rejected', count(fragErrors(['algorithm' => 'bogus'])) === 1);
check('bad type rejected', count(fragErrors(['type' => 'multi'])) === 1);
check('none without pattern rejected', count(fragErrors(['algorithm' => 'none'])) === 1);
check('repeated-alternation pattern rejected with backtracking message',
    strpos(implode(' ', fragErrors(['idPattern' => '(a|aa)+'])), 'backtracking') !== false);
check('non-ASCII pattern rejected (parity subset)',
    strpos(implode(' ', fragErrors(['idPattern' => "FC[0-9]{4}\u{2013}"])), 'ASCII') !== false);
check('Python \\A anchor rejected',
    strpos(implode(' ', fragErrors(['idPattern' => '\\AFC[0-9]{4}'])), 'JavaScript regex') !== false);
check('Python (?P<name>) group rejected',
    count(fragErrors(['idPattern' => '(?P<x>FC)[0-9]{4}'])) === 1);
check('uncompilable pattern rejected',
    strpos(implode(' ', fragErrors(['idPattern' => 'FC[0-9'])), 'compile') !== false);
check('non-ASCII strip rejected', count(fragErrors(['strip' => "-\u{2013}"])) === 1);
check('overlong keepChars rejected', count(fragErrors(['keepChars' => str_repeat('-', 65)])) === 1);
check('ID length beyond the cap rejected',
    strpos(implode(' ', fragErrors(['idLengths' => [100]])), '64') !== false);
check('too many exact lengths rejected', count(fragErrors(['idLengths' => range(1, 40)])) >= 1);
check('idMaxLen beyond the cap rejected', count(fragErrors(['idMaxLen' => 200])) >= 1);
check('expectedIds beyond the cap rejected', count(fragErrors(['expectedIds' => 100000])) === 1);
check('pooled sum-swallow lengths rejected',
    strpos(implode(' ', fragErrors(['type' => 'pooled', 'idLengths' => [8, 16]])), 'swallow') !== false);
// A length reachable by adding THREE or more of the others is just as unsafe as
// a pairwise sum, and the pairwise-only test used to accept it: [4,12] passed,
// and a valid 4-char member plus eight characters of debris then read back as
// one verified 12-character ID with zero junk.
check('pooled 3-term sum-swallow rejected (12 = 4+4+4)',
    strpos(implode(' ', fragErrors(['type' => 'pooled', 'idLengths' => [4, 12]])), 'swallow') !== false);
check('pooled 3-term sum-swallow names the whole decomposition',
    strpos(implode(' ', fragErrors(['type' => 'pooled', 'idLengths' => [4, 12]])), '12 = 4 + 4 + 4') !== false);
check('pooled mixed-term sum-swallow rejected (8 = 3+5)',
    strpos(implode(' ', fragErrors(['type' => 'pooled', 'idLengths' => [3, 5, 8]])), 'swallow') !== false);
// The driving multi-format case must survive: no length in {8,9,10} is the sum
// of two or more of them (the smallest such sum is 16).
check('pooled 8/9/10 accepted (no reachable sum)',
    fragErrors(['type' => 'pooled', 'idLengths' => [8, 9, 10]]) === []);
check('pooled non-contiguous 10/12 accepted (10+10=20, 12+12=24, 10+12=22)',
    fragErrors(['type' => 'pooled', 'idLengths' => [10, 12]]) === []);
check('pooled max >= 2*min rejected',
    strpos(implode(' ', fragErrors(['type' => 'pooled', 'idMinLen' => 8, 'idMaxLen' => 16])), 'LESS than 2 x') !== false);
check('pooled max < min rejected',
    count(fragErrors(['type' => 'pooled', 'idMinLen' => 10, 'idMaxLen' => 9])) === 1);
check('single rule ignores pooled range relationship',
    fragErrors(['idMinLen' => 8, 'idMaxLen' => 16]) === []);
check('pooled defaults (8..14) pass the relationship check',
    fragErrors(['type' => 'pooled']) === []);
check('annotation JSON path uses checkFragment (pooled unsafe lengths -> error)',
    ($r = AnnotationRules::parseField('@UVALIDATE={"type":"pooled","idLengths":[8,16]}'))
    && isset($r['error']) && strpos($r['error'], 'swallow') !== false);

// ---- the "when" condition key (carriage + validation through this channel) ----
$r = AnnotationRules::parseField('@UVALIDATE={"algorithm":"damm","when":"[stype]=\'2\'"}');
check('when carried on the fragment', !isset($r['error']) && $r['when'] === "[stype]='2'"
    && $r['algorithm'] === 'damm');
$r = AnnotationRules::parseField('@UVALIDATE={"when":"[a]="}');
check('bad when syntax -> error naming when', isset($r['error']) && strpos($r['error'], '"when"') !== false);
$r = AnnotationRules::parseField('@UVALIDATE={"when":123}');
check('non-string when -> error', isset($r['error']) && strpos($r['error'], '"when"') !== false);
$r = AnnotationRules::parseField('@UVALIDATE={"when":""}');
check('empty when -> error (never a silent no-op)',
    isset($r['error']) && stripos($r['error'], 'non-empty') !== false);
$r = AnnotationRules::parseField('@UVALIDATE={"whenn":"[a]=\'1\'"}');
check('typo key lists when among valid keys',
    isset($r['error']) && strpos($r['error'], 'whenn') !== false && strpos($r['error'], 'when') !== false);
check('checkFragment: sound when -> no errors', fragErrors(['when' => "[a]='1'"]) === []);
check('checkFragment: function in when rejected',
    strpos(implode(' ', fragErrors(['when' => "datediff([a],[b],'d')>'3'"])), 'function') !== false);
check('checkFragment: event prefix in when rejected',
    strpos(implode(' ', fragErrors(['when' => "[event_1_arm_1][age]>'3'"])), '[event][field]') !== false);
check('checkFragment: non-string when rejected',
    count(fragErrors(['when' => ['x']])) === 1);

// ---- multi-tag annotations (branched validation, 0.9.0) ----
$tags = AnnotationRules::extractTags('@UVALIDATE=verhoeff @UVALIDATE={"algorithm":"damm","when":"[a]=\'1\'"}');
check('two tags extracted in order', count($tags) === 2 && $tags[0] === 'verhoeff'
    && $tags[1] === '{"algorithm":"damm","when":"[a]=\'1\'"}');
check('no tags -> empty list', AnnotationRules::extractTags('@READONLY @HIDDEN') === []);
check('bare tag contributes empty string', AnnotationRules::extractTags('@UVALIDATE @UVALIDATE=damm') === ['', 'damm']);
$tags = AnnotationRules::extractTags('@UVALIDATED=x @UVALIDATE=damm @UVALIDATE2 @UVALIDATE=luhn');
check('near-misses between real tags are skipped', $tags === ['damm', 'luhn']);
check('tag-like text inside a quoted value is not re-read',
    AnnotationRules::extractTags('@UVALIDATE="@UVALIDATE" @UVALIDATE=damm') === ['@UVALIDATE', 'damm']);
$frags = AnnotationRules::parseFieldAll('@UVALIDATE=verhoeff @UVALIDATE={"when":"[a]=\'1\'"}');
check('parseFieldAll: two fragments', count($frags) === 2
    && $frags[0] === ['algorithm' => 'verhoeff'] && $frags[1]['when'] === "[a]='1'");
check('parseFieldAll: untagged -> null', AnnotationRules::parseFieldAll('@READONLY') === null);
$frags = AnnotationRules::parseFieldAll('@UVALIDATE=notanalgo @UVALIDATE=damm');
check('bad + good tag -> error fragment then config fragment',
    isset($frags[0]['error']) && $frags[1] === ['algorithm' => 'damm']);
check('parseField still reads only the first tag',
    AnnotationRules::parseField('@UVALIDATE=verhoeff @UVALIDATE=damm') === ['algorithm' => 'verhoeff']);
// groupMulti: a field with two different tags joins two rules
$rules = AnnotationRules::groupMulti([
    'sid'   => [['algorithm' => 'verhoeff', 'when' => "[a]='1'"], ['algorithm' => 'damm', 'when' => "[a]='2'"]],
    'other' => [['algorithm' => 'damm', 'when' => "[a]='2'"]],
]);
check('groupMulti: field with two tags joins two rules', count($rules) === 2
    && $rules[0]['fields'] === ['sid'] && $rules[1]['fields'] === ['sid', 'other']);
// two byte-identical tags on one field collapse to a single claim
$rules = AnnotationRules::groupMulti(['sid' => [['algorithm' => 'damm'], ['algorithm' => 'damm']]]);
check('identical tags on one field collapse', count($rules) === 1 && $rules[0]['fields'] === ['sid']);
// error fragments still become per-field configError rules
$rules = AnnotationRules::groupMulti(['sid' => [['error' => 'boom'], ['algorithm' => 'damm']]]);
check('error fragment + live fragment coexist', count($rules) === 2
    && strpos($rules[0]['configError'], 'boom') !== false && $rules[1]['fields'] === ['sid']);

// ---- the "suggestFix" key (opt-in check-character hint) ----
$r = AnnotationRules::parseField('@UVALIDATE={"algorithm":"damm","suggestFix":true}');
check('suggestFix true carried', !isset($r['error']) && $r['suggestFix'] === true);
$r = AnnotationRules::parseField('@UVALIDATE={"suggestFix":false}');
check('suggestFix false carried', !isset($r['error']) && $r['suggestFix'] === false);
$r = AnnotationRules::parseField('@UVALIDATE={"suggestFix":"yes"}');
check('quoted suggestFix -> error', isset($r['error']) && strpos($r['error'], 'suggestFix') !== false
    && strpos($r['error'], 'unquoted') !== false);
$r = AnnotationRules::parseField('@UVALIDATE={"suggestFix":1}');
check('numeric suggestFix -> error', isset($r['error']) && strpos($r['error'], 'suggestFix') !== false);

// ---- group ----
$rules = AnnotationRules::group([
    'pid_1' => ['algorithm' => 'damm'],
    'pid_2' => ['algorithm' => 'damm'],
    'pid_3' => [],
    'bad_1' => ['error' => 'boom'],
]);
check('grouping: 3 rules out', count($rules) === 3);
check('identical fragments share a rule', $rules[0]['fields'] === ['pid_1', 'pid_2']);
check('defaults rule separate', $rules[1]['fields'] === ['pid_3'] && $rules[1]['type'] === 'single');
check('error rule carries configError on its field',
    $rules[2]['fields'] === ['bad_1'] && strpos($rules[2]['configError'], 'boom') !== false);

// grouping with when: identical conditions share a rule, different ones split
$rules = AnnotationRules::group([
    'w_1' => ['algorithm' => 'damm', 'when' => "[stype]='2'"],
    'w_2' => ['algorithm' => 'damm', 'when' => "[stype]='2'"],
    'w_3' => ['algorithm' => 'damm', 'when' => "[stype]='3'"],
    'w_4' => ['algorithm' => 'damm'],
]);
check('when grouping: 3 rules out', count($rules) === 3);
check('identical when shares a rule', $rules[0]['fields'] === ['w_1', 'w_2'] && $rules[0]['when'] === "[stype]='2'");
check('different when splits the rule', $rules[1]['fields'] === ['w_3'] && $rules[1]['when'] === "[stype]='3'");
check('when-less rule stays separate', $rules[2]['fields'] === ['w_4'] && !isset($rules[2]['when']));

// ---- @UVASSERT constraint mode (parseAllTags) ----
$f = AnnotationRules::parseAllTags('@UVASSERT="[end]>=[start]"');
check('assert bare -> constraint frag', is_array($f) && count($f) === 1
    && $f[0]['type'] === 'constraint' && $f[0]['assert'] === '[end]>=[start]');
$f = AnnotationRules::parseAllTags('@UVASSERT={"assert":"[a]<[b]","message":"A before B","blockSave":"hard"}');
check('assert json -> keys carried', $f[0]['type'] === 'constraint' && $f[0]['message'] === 'A before B'
    && $f[0]['blockSave'] === 'hard');
$f = AnnotationRules::parseAllTags('@UVASSERT');
check('assert with no condition -> error tagged @UVASSERT',
    isset($f[0]['error']) && $f[0]['_tag'] === '@UVASSERT');
$f = AnnotationRules::parseAllTags('@UVASSERT="[a]"'); // bare ref, no operator
check('assert bad condition -> error', isset($f[0]['error']));
$f = AnnotationRules::parseAllTags('@UVASSERT={"assert":"[a]=[b]","frob":1}');
check('assert unknown key -> error', isset($f[0]['error']) && strpos($f[0]['error'], 'frob') !== false);
$f = AnnotationRules::parseAllTags('@UVASSERT={"assert":"datediff([a],[b],\'d\')>3"}');
check('assert function -> rejected (dialect subset)', isset($f[0]['error']));
// "caseSensitive": the assert matches text case-insensitively unless it is true
$f = AnnotationRules::parseAllTags("@UVASSERT=\"[a]='yes'\"");
check('assert bare -> no caseSensitive key (case-insensitive default)',
    !isset($f[0]['error']) && !array_key_exists('caseSensitive', $f[0]));
$f = AnnotationRules::parseAllTags('@UVASSERT={"assert":"[a]=[b]","caseSensitive":true}');
check('assert caseSensitive:true carried', !isset($f[0]['error']) && $f[0]['caseSensitive'] === true);
$f = AnnotationRules::parseAllTags('@UVASSERT={"assert":"[a]=[b]","caseSensitive":false}');
check('assert caseSensitive:false dropped (it is the default)',
    !isset($f[0]['error']) && !array_key_exists('caseSensitive', $f[0]));
check('assert caseSensitive:false groups with the bare form',
    count(AnnotationRules::groupMulti(['x' => AnnotationRules::parseAllTags('@UVASSERT="[a]=[b]"'),
        'y' => $f])) === 1);
$f = AnnotationRules::parseAllTags('@UVASSERT={"assert":"[a]=[b]","caseSensitive":"true"}');
check('assert caseSensitive quoted -> error', isset($f[0]['error'])
    && strpos($f[0]['error'], 'caseSensitive') !== false && $f[0]['_tag'] === '@UVASSERT');
$f = AnnotationRules::parseAllTags('@UVASSERT={"assert":"[a]=[b]","caseSensitive":1}');
check('assert caseSensitive:1 -> error', isset($f[0]['error']));
// Every tag with a "when" takes the flag; each refuses a non-boolean.
$tagForms = [
    '@UVALIDATE' => '@UVALIDATE={"algorithm":"luhn","when":"[a]=\'x\'",%s}',
    '@UVREQUIRED' => '@UVREQUIRED={"when":"[a]=\'x\'",%s}',
    '@UVUNIQUE' => '@UVUNIQUE={"when":"[a]=\'x\'",%s}',
    '@UVCHOICES' => '@UVCHOICES={"when":"[a]=\'x\'","hide":["9"],%s}',
    '@UVASSERT' => '@UVASSERT={"assert":"[a]=[b]","when":"[a]=\'x\'",%s}',
];
foreach ($tagForms as $tag => $form) {
    $f = AnnotationRules::parseAllTags(sprintf($form, '"caseSensitive":true'));
    check($tag . ' caseSensitive:true carried', !isset($f[0]['error']) && $f[0]['caseSensitive'] === true);
    $f = AnnotationRules::parseAllTags(sprintf($form, '"caseSensitive":false'));
    check($tag . ' caseSensitive:false dropped', !isset($f[0]['error']) && !array_key_exists('caseSensitive', $f[0]));
    $f = AnnotationRules::parseAllTags(sprintf($form, '"caseSensitive":"yes"'));
    check($tag . ' caseSensitive non-boolean -> error', isset($f[0]['error'])
        && strpos($f[0]['error'], 'caseSensitive') !== false);
}
foreach (['single', 'pooled', 'constraint', 'required', 'unique', 'choices'] as $t) {
    $frag = ['type' => $t, 'caseSensitive' => 'yes', 'assert' => '[a]=[b]', 'choicesHide' => ['9']];
    $errs = AnnotationRules::checkFragment($frag);
    check('checkFragment (' . $t . ', settings channel) refuses a non-boolean caseSensitive',
        (bool) array_filter($errs, function ($e) { return strpos($e, 'caseSensitive') !== false; }));
}

// mode composition: @UVALIDATE + @UVASSERT on one field are two frags, distinct modes
$f = AnnotationRules::parseAllTags('@UVALIDATE=verhoeff @UVASSERT="[x]=[y]"');
check('compose: two tags -> two frags', count($f) === 2);
check('compose: check frag first', ($f[0]['algorithm'] ?? '') === 'verhoeff' && !isset($f[0]['type']));
check('compose: constraint frag second', ($f[1]['type'] ?? '') === 'constraint');

// checkFragment routes constraint fragments to the constraint validator
check('checkFragment: sound constraint -> []',
    AnnotationRules::checkFragment(['type' => 'constraint', 'assert' => '[a]>=[b]', 'message' => 'x']) === []);
check('checkFragment: constraint missing assert -> error',
    AnnotationRules::checkFragment(['type' => 'constraint']) !== []);
check('checkFragment: constraint bad blockSave -> error',
    AnnotationRules::checkFragment(['type' => 'constraint', 'assert' => '[a]=[b]', 'blockSave' => 'nope']) !== []);

// groupMulti: constraint frags carry type='constraint' into the rule
$rules = AnnotationRules::groupMulti(['fx' => AnnotationRules::parseAllTags('@UVASSERT="[fx]>0"')]);
check('groupMulti: constraint rule typed', count($rules) === 1 && $rules[0]['type'] === 'constraint'
    && $rules[0]['assert'] === '[fx]>0' && $rules[0]['fields'] === ['fx']);

// ---- @UVREQUIRED required mode (parseAllTags) ----
$f = AnnotationRules::parseAllTags('@UVREQUIRED');
check('required bare -> required frag', is_array($f) && count($f) === 1
    && $f[0]['type'] === 'required' && !isset($f[0]['when']));
$f = AnnotationRules::parseAllTags('@UVREQUIRED="[consent]=\'1\'"');
check('required condition shorthand -> when', $f[0]['type'] === 'required'
    && $f[0]['when'] === "[consent]='1'");
$f = AnnotationRules::parseAllTags('@UVREQUIRED={"when":"[site]<>\'9\'","message":"Needed at real sites","blockSave":"hard"}');
check('required json -> keys carried', $f[0]['type'] === 'required'
    && $f[0]['when'] === "[site]<>'9'" && $f[0]['message'] === 'Needed at real sites'
    && $f[0]['blockSave'] === 'hard');
$f = AnnotationRules::parseAllTags('@UVREQUIRED="datediff([a],[b],\'d\')>3"');
check('required bad shorthand condition -> error tagged @UVREQUIRED',
    isset($f[0]['error']) && $f[0]['_tag'] === '@UVREQUIRED');
$f = AnnotationRules::parseAllTags('@UVREQUIRED={"frob":"x"}');
check('required unknown key -> error', isset($f[0]['error']) && strpos($f[0]['error'], 'frob') !== false);
$f = AnnotationRules::parseAllTags('@UVREQUIRED={"blockSave":"nope"}');
check('required bad blockSave -> error', isset($f[0]['error']));

// @UVREQUIRED does not collide with @UVREQUIREDX (boundary rule)
check('@UVREQUIREDX is a different tag', AnnotationRules::parseAllTags('@UVREQUIREDX=1') === null);

// three modes on one field -> three frags, three distinct modes
$f = AnnotationRules::parseAllTags('@UVALIDATE=verhoeff @UVASSERT="[x]=[y]" @UVREQUIRED');
check('three modes -> three frags', count($f) === 3);
check('  mode set is check+constraint+required',
    ($f[0]['algorithm'] ?? '') === 'verhoeff'
    && ($f[1]['type'] ?? '') === 'constraint'
    && ($f[2]['type'] ?? '') === 'required');

// checkFragment routes required fragments
check('checkFragment: bare required -> []',
    AnnotationRules::checkFragment(['type' => 'required']) === []);
check('checkFragment: required with sound when -> []',
    AnnotationRules::checkFragment(['type' => 'required', 'when' => "[a]='1'"]) === []);
check('checkFragment: required bad when -> error',
    AnnotationRules::checkFragment(['type' => 'required', 'when' => '[a]']) !== []);

// ---- @UVUNIQUE unique mode (parseAllTags) ----
$f = AnnotationRules::parseAllTags('@UVUNIQUE');
check('unique bare -> unique frag, project scope default', is_array($f) && count($f) === 1
    && $f[0]['type'] === 'unique' && !isset($f[0]['uniqueScope']));
$f = AnnotationRules::parseAllTags('@UVUNIQUE=event');
check('unique scope shorthand', $f[0]['type'] === 'unique' && $f[0]['uniqueScope'] === 'event');
$f = AnnotationRules::parseAllTags('@UVUNIQUE=DAG');
check('unique scope shorthand case-insensitive', $f[0]['uniqueScope'] === 'dag');
$f = AnnotationRules::parseAllTags('@UVUNIQUE=weekly');
check('unique bad scope -> error', isset($f[0]['error']) && $f[0]['_tag'] === '@UVUNIQUE');
$f = AnnotationRules::parseAllTags('@UVUNIQUE={"with":["Site","batch"],"scope":"event","message":"Dup","blockSave":"hard","surveys":true}');
check('unique json -> keys carried + with lowercased', $f[0]['type'] === 'unique'
    && $f[0]['uniqueWith'] === ['site', 'batch'] && $f[0]['uniqueScope'] === 'event'
    && $f[0]['message'] === 'Dup' && $f[0]['blockSave'] === 'hard' && $f[0]['uniqueSurveys'] === true);
$f = AnnotationRules::parseAllTags('@UVUNIQUE={"with":"site"}');
check('unique with must be a list', isset($f[0]['error']));
$f = AnnotationRules::parseAllTags('@UVUNIQUE={"with":["a","b","c","d","e","f"]}');
check('unique with capped at 5', isset($f[0]['error']));
$f = AnnotationRules::parseAllTags('@UVUNIQUE={"with":["site","site"]}');
check('unique with duplicate entry -> error', isset($f[0]['error']));
$f = AnnotationRules::parseAllTags('@UVUNIQUE={"surveys":"yes"}');
check('unique surveys must be a real boolean', isset($f[0]['error']));
$f = AnnotationRules::parseAllTags('@UVUNIQUE={"frob":1}');
check('unique unknown key -> error', isset($f[0]['error']) && strpos($f[0]['error'], 'frob') !== false);

// @UVUNIQUE does not collide with the boundary rule
check('@UVUNIQUEX is a different tag', AnnotationRules::parseAllTags('@UVUNIQUEX=1') === null);

// all four modes on one field -> four frags
$f = AnnotationRules::parseAllTags('@UVALIDATE=verhoeff @UVASSERT="[x]=[y]" @UVREQUIRED @UVUNIQUE');
check('four modes -> four frags', count($f) === 4
    && ($f[0]['algorithm'] ?? '') === 'verhoeff'
    && ($f[1]['type'] ?? '') === 'constraint'
    && ($f[2]['type'] ?? '') === 'required'
    && ($f[3]['type'] ?? '') === 'unique');

// checkFragment routes unique fragments
check('checkFragment: bare unique -> []', AnnotationRules::checkFragment(['type' => 'unique']) === []);
check('checkFragment: unique bad scope -> error',
    AnnotationRules::checkFragment(['type' => 'unique', 'uniqueScope' => 'weekly']) !== []);
check('checkFragment: unique empty with -> error',
    AnnotationRules::checkFragment(['type' => 'unique', 'uniqueWith' => []]) !== []);

// ---- multi-format rules ("alternates") ------------------------------------
// The sample-transportation case: four ID families in one field, three of them
// carrying ISO 7064 Mod 37,36 and one (GHIT) carrying no check character.
$ALT4 = [
    ['label' => 'GHIT',       'pattern' => 'FC[1-9]-[0-9]{4}',         'algorithm' => 'none', 'lengths' => [8]],
    ['label' => 'START4KIDS', 'pattern' => 'SK[1-5]-[0-9]{4}[0-9A-Z]', 'algorithm' => 'iso7064_mod37_36', 'lengths' => [9]],
    ['label' => 'DARETB',     'pattern' => 'DT[1-2]-[0-9]{5}[0-9A-Z]', 'algorithm' => 'iso7064_mod37_36', 'lengths' => [10]],
    ['label' => 'SCREENTB',   'pattern' => 'ST[1-5]-[0-9]{5}[0-9A-Z]', 'algorithm' => 'iso7064_mod37_36', 'lengths' => [10]],
];
$altErr = function (array $alts, array $extra = []) {
    return implode(' ', fragErrors(array_merge(['type' => 'pooled', 'alternates' => $alts], $extra)));
};

check('four-family pooled rule accepted',
    fragErrors(['type' => 'pooled', 'alternates' => $ALT4, 'strip' => '-']) === []);
check('four-family SINGLE rule accepted (no lengths needed)',
    fragErrors(['type' => 'single', 'strip' => '-', 'alternates' => array_map(function ($a) {
        unset($a['lengths']); return $a;
    }, $ALT4)]) === []);

// per-alternate algorithm shorthands resolve like the rule-level one
$r = AnnotationRules::parseField('@UVALIDATE={"type":"pooled","alternates":[{"pattern":"A[0-9]{4}","algorithm":"3736","lengths":[5]}]}');
check('alternate algorithm shorthand resolves to canonical',
    !isset($r['error']) && $r['alternates'][0]['algorithm'] === 'iso7064_mod37_36');

// R6 - a pattern-less alternate would accept everything and hide the rest
check('alternate without a pattern rejected',
    strpos(implode(' ', fragErrors(['type' => 'pooled',
        'alternates' => [['algorithm' => 'none', 'lengths' => [8]]]])), 'needs a non-empty "pattern"') !== false);
$r = AnnotationRules::parseField('@UVALIDATE={"alternates":[{"algorithm":"none"}]}');
check('alternate without a pattern rejected in the JSON channel too',
    isset($r['error']) && strpos($r['error'], 'pattern') !== false);
check('pooled alternate without lengths rejected',
    strpos($altErr([['pattern' => 'A[0-9]{4}', 'algorithm' => 'none']]), 'needs "lengths"') !== false);

// R7 - a JSON object would be ordered differently by the two runtimes
$r = AnnotationRules::parseField('@UVALIDATE={"alternates":{"a":{"pattern":"A[0-9]{4}"}}}');
check('alternates as a JSON object rejected',
    isset($r['error']) && strpos($r['error'], 'not an object') !== false);

// unknown option inside an alternate must name it, not be silently dropped
check('unknown key inside an alternate rejected',
    ($r = AnnotationRules::parseField('@UVALIDATE={"alternates":[{"pattern":"A[0-9]{4}","algoritm":"none"}]}'))
    && isset($r['error']) && strpos($r['error'], 'algoritm') !== false);

// R5 - two sources of truth for one fact
// validateConfig renames the JSON key "pattern" to "idPattern", so that is what
// the fragment carries by the time checkFragment sees it.
check('alternates + rule-level pattern rejected',
    strpos($altErr($ALT4, ['idPattern' => 'A[0-9]{4}']), 'must not also set a rule-level "pattern"') !== false);
check('alternates + rule-level idLengths rejected',
    strpos($altErr($ALT4, ['idLengths' => [8]]), 'must not also set rule-level') !== false);
check('alternates + rule-level idMinLen rejected',
    strpos($altErr($ALT4, ['idMinLen' => 8]), 'must not also set rule-level') !== false);

// R9 - caps
check('more than 8 alternates rejected', strpos($altErr(array_map(function ($i) {
    return ['pattern' => 'A' . $i . '[0-9]{4}', 'algorithm' => 'none', 'lengths' => [6]];
}, range(1, 9))), 'at most') !== false);
check('over-long alternate label rejected',
    strpos($altErr([['label' => str_repeat('x', 41), 'pattern' => 'A[0-9]{4}',
        'algorithm' => 'none', 'lengths' => [5]]]), 'label') !== false);

// R8 - a bad pattern on ANY alternate fails the WHOLE rule, and names it
check('a risky pattern in alternate 2 fails the rule and names the alternate',
    strpos($altErr([
        ['label' => 'ok',  'pattern' => 'A[0-9]{4}', 'algorithm' => 'none', 'lengths' => [5]],
        ['label' => 'bad', 'pattern' => '(a+)+$',    'algorithm' => 'none', 'lengths' => [7]],
    ]), 'bad: ') !== false);
check('an unknown algorithm on an alternate names the alternate',
    strpos($altErr([['label' => 'z', 'pattern' => 'A[0-9]{4}',
        'algorithm' => 'nosuchalgo', 'lengths' => [5]]]), 'z: unknown algorithm') !== false);

// R1 - the union is what the sum-swallow proof runs over. Individually safe,
// jointly unsafe: 9 = 5 + 4.
check('cross-alternate sum-swallow rejected (9 = 5 + 4)',
    strpos($altErr([
        ['pattern' => 'A[0-9A-Z]{8}', 'algorithm' => 'none', 'lengths' => [9]],
        ['pattern' => 'B[0-9A-Z]{4}', 'algorithm' => 'none', 'lengths' => [5]],
        ['pattern' => 'C[0-9A-Z]{3}', 'algorithm' => 'none', 'lengths' => [4]],
    ]), 'swallow') !== false);
check('the four-family union 8/9/10 is safe', $altErr($ALT4) === '');

// Helpers for the shape-ownership tests below: a minted ID, the same ID with
// its check character corrupted (same character class, so the shape still
// matches), and compact views of the two runtime verdicts.
$withCheck = function ($body) {
    return $body . CheckCharacter::compute('iso7064_mod37_36', str_replace('-', '', $body));
};
$breakCheck = function ($id) {
    $c = substr($id, -1);
    return substr($id, 0, -1) . ($c === 'A' ? 'B' : 'A');
};
$pooledSegs = function (array $alts, $v) {
    $segs = CheckCharacter::pooledParse(['type' => 'pooled', 'alternates' => $alts], $v);
    if ($segs === null) return 'null';
    return implode(' ', array_map(function ($s) {
        return $s['type'] === 'id' ? $s['id'] . ':' . ($s['valid'] ? 'ok' : 'bad') . ':' . $s['alt']
                                   : 'junk:' . $s['text'];
    }, $segs));
};
$singleReason = function (array $alts, $v) {
    $r = CheckCharacter::validateSingleField(['strip' => '-', 'alternates' => $alts], $v);
    return ($r['ok'] ? 'ok' : 'bad') . ':' . $r['reason'];
};

// R3 (superseded by shape ownership) - a format-only alternate may share a
// length with a check-bearing one. A token the check-bearing pattern shapes
// must pass its check; the format-only alternate is consulted only for tokens
// no check-bearing pattern shapes, so the check character stays in force.
$R3 = [
    ['pattern' => 'AA[0-9]{7}',         'algorithm' => 'none',             'lengths' => [9]],
    ['pattern' => 'BB[0-9]{6}[0-9A-Z]', 'algorithm' => 'iso7064_mod37_36', 'lengths' => [9]],
];
$bb = $withCheck('BB123456');
check('R3 format-only and check-bearing alternates may share a length', $altErr($R3) === '');
check('R3 pooled: a good check-bearing ID verifies', $pooledSegs($R3, $bb) === $bb . ':ok:1');
check('R3 pooled: a corrupted check-bearing ID is flagged',
    $pooledSegs($R3, $breakCheck($bb)) === $breakCheck($bb) . ':bad:1');
check('R3 pooled: a format-only ID of the same length is accepted',
    $pooledSegs($R3, 'AA1234567') === 'AA1234567:ok:0');
check('R3 pooled: a mixed run splits at the right boundaries',
    $pooledSegs($R3, 'AA1234567' . $bb . $breakCheck($bb))
    === 'AA1234567:ok:0 ' . $bb . ':ok:1 ' . $breakCheck($bb) . ':bad:1');

// The driving real-world case: HIV viral-load sample codes. C-Z codes carry a
// check character, legacy A/B codes do not, and both are 6 characters long.
$HIV = [
    ['label' => 'Check character verified code',     'pattern' => '[C-Z][0-9]{4}[0-9A-Z]',
     'algorithm' => 'iso7064_mod37_36', 'lengths' => [6]],
    ['label' => 'Non Check character verified code', 'pattern' => '[AB][0-9]{5}',
     'algorithm' => 'none', 'lengths' => [6]],
];
$hivTag = '@UVALIDATE={"type":"pooled","strip":"-","blockSave":"hard","alternates":['
    . '{"label":"Check character verified code","pattern":"[C-Z][0-9]{4}[0-9A-Z]","algorithm":"3736","lengths":[6]},'
    . '{"label":"Non Check character verified code","pattern":"[AB][0-9]{5}","algorithm":"none","lengths":[6]}]}';
$r = AnnotationRules::parseField($hivTag);
check('HIV the pooled action tag parses clean', !isset($r['error']) && count($r['alternates']) === 2);
$hivSingle = array_map(function ($a) { unset($a['lengths']); return $a; }, $HIV);
check('HIV the single-value rule parses clean',
    fragErrors(['type' => 'single', 'alternates' => $hivSingle]) === []);
$c1 = $withCheck('C1234');
$z9 = $withCheck('Z9876');
check('HIV pooled: a check-character code verifies', $pooledSegs($HIV, $c1) === $c1 . ':ok:0');
check('HIV pooled: a corrupted check-character code is flagged',
    $pooledSegs($HIV, $breakCheck($c1)) === $breakCheck($c1) . ':bad:0');
check('HIV pooled: a legacy A/B code is accepted on format',
    $pooledSegs($HIV, 'A12345') === 'A12345:ok:1' && $pooledSegs($HIV, 'B00001') === 'B00001:ok:1');
check('HIV pooled: a jammed mixed run splits and verifies',
    $pooledSegs($HIV, $c1 . 'A12345' . $z9) === $c1 . ':ok:0 A12345:ok:1 ' . $z9 . ':ok:0');
check('HIV pooled: a corrupted member inside a run is still caught',
    $pooledSegs($HIV, $breakCheck($c1) . 'A12345') === $breakCheck($c1) . ':bad:0 A12345:ok:1');
check('HIV single: a check-character code verifies', $singleReason($hivSingle, $c1) === 'ok:valid');
check('HIV single: a corrupted check-character code is a check error',
    $singleReason($hivSingle, $breakCheck($c1)) === 'bad:check-character');
check('HIV single: a legacy A/B code is accepted', $singleReason($hivSingle, 'A12345') === 'ok:valid');
check('HIV single: anything else is a format error', $singleReason($hivSingle, 'A1234X') === 'bad:format');

// R2 - KEEP is computed once for the whole field, so an algorithm that can emit
// "*" changes what survives cleaning for its siblings too
check('alternates disagreeing about the kept character set rejected',
    strpos($altErr([
        ['pattern' => 'AA[0-9]{6}[0-9A-Z]', 'algorithm' => 'iso7064_mod37_2',  'lengths' => [9]],
        ['pattern' => 'BB[0-9]{7}[0-9A-Z]', 'algorithm' => 'iso7064_mod37_36', 'lengths' => [10]],
    ]), 'disagree about which characters survive') !== false);
check('declaring the union in keepChars is the escape hatch',
    $altErr([
        ['pattern' => 'AA[0-9]{6}[0-9A-Z]', 'algorithm' => 'iso7064_mod37_2',  'lengths' => [9]],
        ['pattern' => 'BB[0-9]{7}[0-9A-Z]', 'algorithm' => 'iso7064_mod37_36', 'lengths' => [10]],
    ], ['keepChars' => '*']) === '');

// R4 (M-03 generalised) - only the FORMAT-ONLY alternates can create count
// ambiguity, so the driving case keeps expectedIds while two unpinned
// format-only families lose it.
check('expectedIds kept when only ONE format-only length exists (the GHIT case)',
    $altErr($ALT4, ['expectedIds' => 4]) === '');
check('expectedIds refused when two format-only alternates differ in length',
    strpos($altErr([
        ['pattern' => 'A[0-9A-Z]{3}', 'algorithm' => 'none', 'lengths' => [4]],
        ['pattern' => 'B[0-9A-Z]{4}', 'algorithm' => 'none', 'lengths' => [5]],
    ], ['expectedIds' => 2]), 'ambiguous') !== false);
check('expectedIds kept when every alternate carries a check character',
    $altErr([
        ['pattern' => 'A[0-9]{3}[0-9A-Z]', 'algorithm' => 'iso7064_mod37_36', 'lengths' => [5]],
        ['pattern' => 'B[0-9]{5}[0-9A-Z]', 'algorithm' => 'iso7064_mod37_36', 'lengths' => [7]],
    ], ['expectedIds' => 2]) === '');

// the whole tag, end to end through the annotation channel
$tag = '@UVALIDATE={"type":"pooled","strip":"-","blockSave":"hard","alternates":['
    . '{"label":"GHIT","pattern":"FC[1-9]-[0-9]{4}","algorithm":"none","lengths":[8]},'
    . '{"label":"START4KIDS","pattern":"SK[1-5]-[0-9]{4}[0-9A-Z]","algorithm":"3736","lengths":[9]},'
    . '{"label":"DARETB","pattern":"DT[1-2]-[0-9]{5}[0-9A-Z]","algorithm":"3736","lengths":[10]},'
    . '{"label":"SCREENTB","pattern":"ST[1-5]-[0-9]{5}[0-9A-Z]","algorithm":"3736","lengths":[10]}]}';
$r = AnnotationRules::parseField($tag);
check('the four-family action tag parses clean end to end',
    !isset($r['error']) && count($r['alternates']) === 4
    && $r['alternates'][1]['algorithm'] === 'iso7064_mod37_36'
    && $r['blockSave'] === 'hard' && $r['type'] === 'pooled');


// ---- adversarial review 2026-08-27: the config-time gates it got past ------
$MOD = 'iso7064_mod37_36';

// H-01 (superseded by shape ownership): a permissive format-only alternate
// used to be refused, because with "first accept wins" it silently disabled a
// check-bearing sibling. Ownership removes the hazard instead of refusing the
// rule: SK-shaped values must pass the SK check in either declaration order,
// and everything else falls to the catch-all.
$CATCH = ['label' => 'catchall', 'pattern' => '[A-Z0-9-]+',              'algorithm' => 'none'];
$SK    = ['label' => 'SK',       'pattern' => 'SK[1-5]-[0-9]{4}[0-9A-Z]', 'algorithm' => $MOD];
$sk    = $withCheck('SK1-0000');
foreach ([[$CATCH, $SK], [$SK, $CATCH]] as $oi => $pair) {
    check('H-01 a catch-all beside a check-bearing alternate is accepted, order ' . $oi,
        $altErr($pair, ['type' => 'single']) === '');
    check('H-01 a good SK ID verifies, order ' . $oi, $singleReason($pair, $sk) === 'ok:valid');
    check('H-01 a mis-scanned SK ID is a check error, not a catch-all match, order ' . $oi,
        $singleReason($pair, $breakCheck($sk)) === 'bad:check-character');
    check('H-01 a non-SK value still falls to the catch-all, order ' . $oi,
        $singleReason($pair, 'XYZ-123') === 'ok:valid');
}
// ...and disjoint prefixes are still fine, single AND pooled: the driving case
// declares its format-only family FIRST and must keep working.
check('H-01 the driving case (disjoint shapes) still accepted, single',
    fragErrors(['type' => 'single', 'strip' => '-', 'alternates' => array_map(function ($a) {
        unset($a['lengths']); return $a;
    }, $ALT4)]) === []);
check('H-01 the driving case still accepted, pooled',
    fragErrors(['type' => 'pooled', 'strip' => '-', 'alternates' => $ALT4]) === []);

// H-02: the KEEP map was keyed by display name, so two alternates sharing a
// label overwrote each other - and when every entry shared one label the map
// collapsed to a single row and the comparison was skipped outright.
$keepPair = function ($l1, $l2) {
    return implode(' ', fragErrors(['type' => 'pooled', 'alternates' => [
        ['label' => $l1, 'pattern' => 'AA[0-9]{6}[0-9A-Z]', 'algorithm' => 'iso7064_mod37_2',  'lengths' => [9]],
        ['label' => $l2, 'pattern' => 'BB[0-9]{7}[0-9A-Z]', 'algorithm' => 'iso7064_mod37_36', 'lengths' => [10]],
    ]]));
};
check('H-02 distinct labels: KEEP disagreement refused',
    strpos($keepPair('QR', 'QR2'), 'disagree about which characters survive') !== false);
check('H-02 DUPLICATE labels no longer collapse the gate',
    strpos($keepPair('QR', 'QR'), 'disagree about which characters survive') !== false);
check('H-02 the duplicate-label message still distinguishes the two entries',
    strpos($keepPair('QR', 'QR'), 'alternate 2') !== false);

// M-01: alternatesOf refuses a non-ASCII label at RUNTIME, so one that only
// passed a length check here would save clean and kill the rule on the form.
check('M-01 non-ASCII alternate label refused at config time',
    strpos($altErr([['label' => "A\xE2\x80\x94B", 'pattern' => 'A[0-9]{4}', 'algorithm' => 'none']],
        ['type' => 'single']), 'printable ASCII') !== false);
// M-04: an alternate's own strip reaches normalize() exactly as the rule-level
// one does, and the runtimes split it differently outside printable ASCII.
check('M-04 non-ASCII alternate strip refused at config time',
    strpos($altErr([['pattern' => 'A[0-9]{4}', 'algorithm' => 'none', 'strip' => "\xE2\x80\x94"]],
        ['type' => 'single']), 'printable ASCII') !== false);
check('M-04 an ASCII alternate strip is still accepted',
    $altErr([['pattern' => 'A[0-9]{4}', 'algorithm' => 'none', 'strip' => '-']], ['type' => 'single']) === '');

// L-01: the union cap only runs on the POOLED path, so without a per-entry
// bound a single-type rule could carry an arbitrarily long list into config.
check('L-01 an over-long per-alternate lengths list is refused',
    ($r = AnnotationRules::parseField('@UVALIDATE={"type":"single","alternates":[{"pattern":"A[0-9]{4}",'
        . '"algorithm":"none","lengths":[' . implode(',', range(1, 40)) . ']}]}'))
    && isset($r['error']) && strpos($r['error'], 'at most') !== false);
check('L-01 a list at the cap is still accepted',
    !isset(AnnotationRules::parseField('@UVALIDATE={"type":"single","alternates":[{"pattern":"A[0-9]{4}",'
        . '"algorithm":"none","lengths":[' . implode(',', range(1, 32)) . ']}]}')['error']));

// Round 2 lows: checkFragment is public and documented as THE shared
// validator returning a list of error strings, so a malformed alternates must
// be REPORTED there, not throw (validateSettings swallows a throw into an
// allowed save) and not be skipped while both runtimes refuse it.
check('L-1 a non-list alternates is reported, not thrown',
    ($e = AnnotationRules::checkFragment(['type' => 'single', 'alternates' => ['a' => ['pattern' => 'A[0-9]{4}']]]))
    && is_array($e) && count($e) === 1 && strpos($e[0], 'JSON list') !== false);
check('L-2 an empty alternates list is reported',
    count(AnnotationRules::checkFragment(['type' => 'single', 'alternates' => []])) === 1);
check('L-4 alternates on a rule kind that never reads it is refused',
    strpos(implode(' ', AnnotationRules::checkFragment(
        ['type' => 'unique', 'alternates' => [['pattern' => 'A[0-9]{4}']]])), 'applies only to ID checks') !== false);
foreach (['constraint', 'required', 'choices'] as $mode) {
    check('L-4 ... and on ' . $mode,
        strpos(implode(' ', AnnotationRules::checkFragment(
            ['type' => $mode, 'alternates' => [['pattern' => 'A[0-9]{4}']]])), 'applies only to ID checks') !== false);
}
check('L-5 the alternate count is refused before the list is normalized',
    isset(AnnotationRules::normalizeAlternates(
        array_fill(0, 20000, ['pattern' => 'A[0-9]{4}', 'algorithm' => 'none']))['error']));

// Ownership holds for the pattern shapes that once slipped past the witness
// probe: a group, an alternation of prefixes, a negated class.
foreach (['(SK|DT)[1-5]-[0-9]{4}[0-9A-Z]',
          'SK1-[0-9]{4}[0-9A-Z]|SK2-[0-9]{4}[0-9A-Z]',
          '[^a-z]K[1-5]-[0-9]{4}[0-9A-Z]'] as $ck) {
    $pair = [['label' => 'legacy', 'pattern' => '[A-Z0-9-]+', 'algorithm' => 'none'],
             ['label' => 'SK',     'pattern' => $ck,          'algorithm' => $MOD]];
    check('H-1 accepted at config time: ' . $ck, $altErr($pair, ['type' => 'single']) === '');
    check('H-1 a good ID verifies under ' . $ck, $singleReason($pair, $sk) === 'ok:valid');
    check('H-1 a mis-scanned ID is still a check error under ' . $ck,
        $singleReason($pair, $breakCheck($sk)) === 'bad:check-character');
}
// a genuinely disjoint alternation family is still accepted
check('H-1 a disjoint alternation family is not refused',
    $altErr([
        ['label' => 'GHIT', 'pattern' => 'FC[1-9]-[0-9]{4}',              'algorithm' => 'none'],
        ['label' => 'SKDT', 'pattern' => '(SK|DT)[1-5]-[0-9]{4}[0-9A-Z]', 'algorithm' => $MOD],
    ], ['type' => 'single']) === '');

// ---- round 3 ------------------------------------------------------------
// H-1: a check-bearing pattern the witness builder could not analyse used to
// silence the overlap guard - every mis-scanned ID of that family recorded as
// clean - and the fix refused such rules. Ownership needs no analysis: the
// pattern itself is the test at runtime, so the rules are accepted and the
// check character holds. (keepChars: the KEEP gate reads lookaround syntax as
// literal separators, which is a separate matter from ownership.)
foreach (['(?=[A-Z])[0-9A-Z]{9}', '(?![0])[0-9A-Z]{9}', '(?<x>[A-Z])[0-9]{8}', '([A-Z])\\1[0-9]{7}'] as $opaque) {
    check('H-1 an opaque check-bearing pattern is accepted: ' . $opaque,
        $altErr([
            ['label' => 'LEGACY', 'pattern' => '[0-9A-Z]{9}', 'algorithm' => 'none'],
            ['label' => 'MINTED', 'pattern' => $opaque,       'algorithm' => $MOD],
        ], ['type' => 'single', 'keepChars' => '=!<>']) === '');
}
// The round-3 reproduction, exhaustively: LEGACY [0-9A-Z]{9} beside MINTED
// \D[0-9A-Z]{8}. Of the 36 possible final characters after ABCDEFGH exactly one
// is the real check character; the other 35 used to be recorded as clean.
$R3X = [['label' => 'LEGACY', 'pattern' => '[0-9A-Z]{9}',   'algorithm' => 'none'],
        ['label' => 'MINTED', 'pattern' => '\D[0-9A-Z]{8}', 'algorithm' => $MOD]];
$R3P = [['label' => 'LEGACY', 'pattern' => '[0-9A-Z]{9}',   'algorithm' => 'none', 'lengths' => [9]],
        ['label' => 'MINTED', 'pattern' => '\D[0-9A-Z]{8}', 'algorithm' => $MOD,   'lengths' => [9]]];
check('H-1 the round-3 rule is accepted, single and pooled',
    $altErr($R3X, ['type' => 'single']) === '' && $altErr($R3P) === '');
$good = $withCheck('ABCDEFGH');
$singleOk = 0; $pooledOk = 0; $singleChk = 0; $pooledBad = 0;
foreach (str_split('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ') as $ch) {
    $v = 'ABCDEFGH' . $ch;
    if ($singleReason($R3X, $v) === 'ok:valid') $singleOk++;
    if ($singleReason($R3X, $v) === 'bad:check-character') $singleChk++;
    if ($pooledSegs($R3P, $v) === $v . ':ok:1') $pooledOk++;
    if ($pooledSegs($R3P, $v) === $v . ':bad:1') $pooledBad++;
}
check('H-1 single: only the real check character is accepted (1 of 36)',
    $singleOk === 1 && $singleChk === 35 && $singleReason($R3X, $good) === 'ok:valid');
check('H-1 pooled: only the real check character is accepted (1 of 36)',
    $pooledOk === 1 && $pooledBad === 35 && $pooledSegs($R3P, $good) === $good . ':ok:1');
check('H-1 a LEGACY ID that MINTED does not shape (leading digit) is still accepted',
    $singleReason($R3X, '123456789') === 'ok:valid' && $pooledSegs($R3P, '123456789') === '123456789:ok:0');
check('H-1 pooled is not refused for an unanalysable pattern',
    $altErr([
        ['label' => 'GHIT',   'pattern' => 'FC[1-9]-[0-9]{4}', 'algorithm' => 'none', 'lengths' => [8]],
        ['label' => 'MINTED', 'pattern' => '([A-Z])\1[1-9]-[0-9]{5}', 'algorithm' => $MOD, 'lengths' => [9]],
    ]) === '');
// A format-only alternate wholly inside a check-bearing one is accepted, and
// is simply never reached: every value it shapes is owned by the check.
$SUB = [['label' => 'SUB', 'pattern' => 'SK5-[0-9]{4}[0-9A-Z]',     'algorithm' => 'none'],
        ['label' => 'S4K', 'pattern' => 'SK[1-5]-[0-9]{4}[0-9A-Z]', 'algorithm' => $MOD]];
$sk5 = $withCheck('SK5-9999');
check('H-1 a subset format-only alternate is accepted', $altErr($SUB, ['type' => 'single']) === '');
check('H-1 ... and cannot excuse a bad check inside the owner\'s shape',
    $singleReason($SUB, $sk5) === 'ok:valid' && $singleReason($SUB, $breakCheck($sk5)) === 'bad:check-character');
// L-1: checkFragment is documented as THE gate every channel goes through, so
// it has to report on a hostile shape rather than throw - validateSettings
// swallows a throw into an ALLOWED save of a rule nothing validated.
foreach ([['label' => ['x'], 'pattern' => 'A[0-9]{4}'],
          ['label' => 'L', 'pattern' => 'A[0-9]{4}', 'algorithm' => ['damm']],
          ['label' => 'L', 'pattern' => 'A[0-9]{4}', 'strip' => ['-']],
          ['label' => 'L', 'pattern' => 'A[0-9]{4}', 'source' => ['x']],
          ['label' => 'L', 'pattern' => ['A']],
          'not-an-object'] as $ai => $hostile) {
    $threw = false;
    $errs  = [];
    try {
        $errs = AnnotationRules::checkFragment(['type' => 'single', 'alternates' => [$hostile]]);
    } catch (\Throwable $e) {
        $threw = true;
    }
    check('L-1 hostile alternate ' . $ai . ' is reported, not thrown', !$threw && count($errs) > 0);
}
foreach ([['algorithm' => ['damm']], ['strip' => ['-']], ['source' => [1]], ['idPattern' => ['A']]] as $ci => $cfg) {
    $threw = false;
    $res   = null;
    try {
        $res = CheckCharacter::validateSingleField($cfg, 'FC1-0589');
    } catch (\Throwable $e) {
        $threw = true;
    }
    check('L-1 validateSingleField survives hostile cfg ' . $ci,
        !$threw && $res['reason'] === 'unconfigurable');
}
// L-2: the standalone claimedBy twins were dead in both runtimes - the parser
// records the winning alternate on the segment, which is what reporting reads.
check('L-2 the dead pooledClaimedBy twin is gone',
    !method_exists('INSPIRE\\UniversalValidator\\CheckCharacter', 'pooledClaimedBy'));

// L-3: swallowSum and alternatesOf are hand-mirrored across the two runtimes
// and neither had a direct cross-runtime test - swallowSum was reachable only
// through configError text. tests/alternates_dom_js.cjs reads the same corpus
// and asserts the same expectations, written from the definitions.
$fx = json_decode(file_get_contents(__DIR__ . '/alternates_fixture.json'), true);
check('L-3 the shared alternates corpus loads', is_array($fx) && count($fx['swallow']) && count($fx['normalize']));
foreach ($fx['swallow'] as $c) {
    $got  = CheckCharacter::swallowSum($c['lens']);
    $want = $c['target'] === null ? 'safe' : json_encode(['target' => $c['target'], 'parts' => $c['parts']]);
    $has  = $got === null ? 'safe' : json_encode(['target' => $got['target'], 'parts' => $got['parts']]);
    check('swallowSum [' . implode(',', $c['lens']) . ']: ' . $c['why'], $has === $want);
}
foreach ($fx['normalize'] as $c) {
    $got = CheckCharacter::alternatesOf($c['cfg']);
    if (empty($c['ok'])) {
        check('alternatesOf refuses: ' . $c['why'], $got === null);
        continue;
    }
    // The js twin reports an error string where this one returns null, so the
    // corpus compares the NORMALIZED entries, which both runtimes must agree on.
    check('alternatesOf normalizes: ' . $c['why'],
        $got !== null && json_encode($got) === json_encode($c['entries']));
}

echo sprintf("annotation_php: %d checks, %d failure(s)\n", $n, $fail);
exit($fail === 0 ? 0 : 1);
