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
