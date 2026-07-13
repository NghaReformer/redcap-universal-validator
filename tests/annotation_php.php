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

echo sprintf("annotation_php: %d checks, %d failure(s)\n", $n, $fail);
exit($fail === 0 ? 0 : 1);
