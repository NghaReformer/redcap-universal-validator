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

echo sprintf("annotation_php: %d checks, %d failure(s)\n", $n, $fail);
exit($fail === 0 ? 0 : 1);
