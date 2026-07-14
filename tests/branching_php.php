<?php
/**
 * branching_php.php — unit tests for the branch resolver.
 *
 * Branching is pure PHP with no REDCap dependency; the whole
 * shared-field-to-branch-rule rewrite (legality, branch synthesis, else
 * ordering, passthrough identity) is tested here without a REDCap runtime.
 * The client mirrors the same scenario table in tests/branch_dom_js.cjs.
 *
 * Run:  php tests/branching_php.php
 */

require_once __DIR__ . '/../php/Branching.php';

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

function rule($fields, $extra = [])
{
    return array_merge(['type' => 'single', 'fields' => $fields], $extra);
}

// ---- passthrough identity: no sharing => byte-identical output ----
$plain = [
    rule(['a', 'b'], ['algorithm' => 'damm', 'blockSave' => 'hard']),
    rule(['c'], ['type' => 'pooled', 'idLengths' => [10], 'when' => "[x]='1'"]),
    ['type' => 'single', 'fields' => ['zz'], 'configError' => 'boom'],
];
check('no sharing -> identity', Branching::resolve($plain) === $plain);
check('no sharing -> no conflicts', Branching::fieldConflicts($plain) === []);

// ---- legal: two conditional rules sharing one field ----
$rules = [
    rule(['sid', 'other1'], ['algorithm' => 'verhoeff', 'when' => "[stype]='1'", 'blockSave' => 'hard']),
    rule(['sid', 'other2'], ['algorithm' => 'iso7064_mod37_36', 'when' => "[stype]='2'", 'suggestFix' => true]),
];
$out = Branching::resolve($rules);
check('legal 2-cond: three rules out', count($out) === 3);
check('rule 1 keeps its unshared field only', $out[0]['fields'] === ['other1'] && $out[0]['when'] === "[stype]='1'");
check('rule 2 keeps its unshared field only', $out[1]['fields'] === ['other2']);
$br = $out[2];
check('branch rule covers exactly the shared field', $br['fields'] === ['sid'] && $br['type'] === 'single');
check('branch rule has no top-level when/algorithm', !isset($br['when']) && !isset($br['algorithm']));
check('two branches in source order', count($br['branches']) === 2
    && $br['branches'][0]['algorithm'] === 'verhoeff' && $br['branches'][1]['algorithm'] === 'iso7064_mod37_36');
check('branches carry their whens', $br['branches'][0]['when'] === "[stype]='1'"
    && $br['branches'][1]['when'] === "[stype]='2'");
check('sparse copy: blockSave rides branch 1 only',
    $br['branches'][0]['blockSave'] === 'hard' && !isset($br['branches'][1]['blockSave']));
check('sparse copy: suggestFix rides branch 2 only',
    $br['branches'][1]['suggestFix'] === true && !isset($br['branches'][0]['suggestFix']));

// ---- legal: conditional + else, else forced last regardless of order ----
$rules = [
    rule(['sid'], ['algorithm' => 'none', 'idPattern' => 'FC[0-9]{4}']),                 // when-less FIRST
    rule(['sid'], ['algorithm' => 'verhoeff', 'when' => "[stype]='1'"]),
];
$out = Branching::resolve($rules);
check('cond+else: one branch rule out', count($out) === 1 && isset($out[0]['branches']));
check('else branch is forced last', count($out[0]['branches']) === 2
    && $out[0]['branches'][0]['when'] === "[stype]='1'"
    && $out[0]['branches'][1]['when'] === null
    && $out[0]['branches'][1]['idPattern'] === 'FC[0-9]{4}');
check('emptied source rules are dropped', !isset($out[1]));

// ---- pooled branch rule keeps the pooled type + pooled keys ----
$rules = [
    rule(['pool'], ['type' => 'pooled', 'idLengths' => [9], 'when' => "[x]='1'"]),
    rule(['pool'], ['type' => 'pooled', 'idLengths' => [12], 'expectedIds' => 3, 'when' => "[x]='2'"]),
];
$out = Branching::resolve($rules);
check('pooled branch rule keeps type', count($out) === 1 && $out[0]['type'] === 'pooled');
check('pooled per-branch keys survive', $out[0]['branches'][0]['idLengths'] === [9]
    && $out[0]['branches'][1]['idLengths'] === [12] && $out[0]['branches'][1]['expectedIds'] === 3);

// ---- illegal: two unconditional rules (today's true duplicate) ----
$rules = [rule(['sid'], ['algorithm' => 'damm']), rule(['sid'], ['algorithm' => 'verhoeff'])];
$c = Branching::fieldConflicts($rules);
check('two-unconditional detected', isset($c['sid']) && $c['sid']['kind'] === 'two-unconditional'
    && $c['sid']['rules'] === [0, 1]);
$out = Branching::resolve($rules);
check('two-unconditional -> one configError rule', count($out) === 1 && isset($out[0]['configError']));
check('two-unconditional wording', strpos($out[0]['configError'], 'no "when" condition') !== false
    && strpos($out[0]['configError'], 'at most ONE unconditional rule') !== false);

// ---- illegal: identical when strings ----
$rules = [
    rule(['sid'], ['when' => "[stype]='2'"]),
    rule(['sid'], ['algorithm' => 'damm', 'when' => "[stype]='2'"]),
];
$c = Branching::fieldConflicts($rules);
check('identical-when detected', isset($c['sid']) && $c['sid']['kind'] === 'identical-when'
    && $c['sid']['detail'] === "[stype]='2'");
$out = Branching::resolve($rules);
check('identical-when wording names the condition',
    strpos($out[0]['configError'], "identical condition \"[stype]='2'\"") !== false);

// ---- illegal: mixed single/pooled types ----
$rules = [
    rule(['sid'], ['when' => "[a]='1'"]),
    rule(['sid'], ['type' => 'pooled', 'when' => "[a]='2'"]),
];
$c = Branching::fieldConflicts($rules);
check('mixed-type detected', isset($c['sid']) && $c['sid']['kind'] === 'mixed-type');
check('mixed-type wording', strpos(Branching::message('sid', $c['sid']), 'same field type') !== false);

// ---- priority: two-unconditional wins over identical-when and mixed-type ----
$rules = [
    rule(['sid'], ['when' => "[a]='1'"]),
    rule(['sid'], ['when' => "[a]='1'"]),
    rule(['sid'], []),
    rule(['sid'], ['type' => 'pooled']),
];
$c = Branching::fieldConflicts($rules);
check('conflict priority: two-unconditional first',
    isset($c['sid']) && $c['sid']['kind'] === 'two-unconditional' && $c['sid']['rules'] === [2, 3]);

// ---- configError rules never participate in sharing ----
$rules = [
    rule(['sid'], ['algorithm' => 'damm']),
    ['type' => 'single', 'fields' => ['sid'], 'configError' => 'already broken'],
];
check('configError rules excluded from claims', Branching::resolve($rules) === $rules);

// ---- one field legal, another illegal, in the same list ----
$rules = [
    rule(['ok_f', 'bad_f'], ['when' => "[a]='1'"]),
    rule(['ok_f', 'bad_f', 'solo'], ['when' => "[a]='1'"]),   // identical when -> bad on BOTH shared fields
];
$out = Branching::resolve($rules);
$errs = 0;
foreach ($out as $r) { if (!empty($r['configError'])) $errs++; }
check('identical-when flags every shared field', $errs === 2);
$solo = null;
foreach ($out as $r) { if (empty($r['configError']) && !isset($r['branches'])) $solo = $r; }
check('unshared field of a flagged rule survives', $solo !== null && $solo['fields'] === ['solo']);

// ---- whitespace-only when counts as unconditional ----
$rules = [rule(['sid'], ['when' => '   ']), rule(['sid'], [])];
$c = Branching::fieldConflicts($rules);
check('blank when is treated as no when', isset($c['sid']) && $c['sid']['kind'] === 'two-unconditional');

echo sprintf("branching_php: %d checks, %d failure(s)\n", $n, $fail);
exit($fail === 0 ? 0 : 1);
