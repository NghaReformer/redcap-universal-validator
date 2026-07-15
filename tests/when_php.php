<?php
/**
 * when_php.php — PHP side of the "when" condition parity contract.
 *
 * Drives php/Logic.php (the normative dialect spec) over every case in
 * tests/when_fixture.json: parse ok/error, evaluate() verdicts, and
 * referencedFields() output. tests/when_js.cjs drives the JS twin in
 * js/engine.js over the SAME fixture, so the two runtimes cannot drift.
 * The dictionary-dependent helpers (checkRefs, parseChoiceCodes) have no JS
 * twin (the client never sees the data dictionary) and are unit-tested
 * directly below the fixture loop.
 *
 * Run:  php tests/when_php.php
 */

require_once __DIR__ . '/../php/Logic.php';

use INSPIRE\UniversalValidator\Logic;

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

$fx = json_decode(file_get_contents(__DIR__ . '/when_fixture.json'), true);
check('fixture loads', is_array($fx) && isset($fx['eval'], $fx['errors'], $fx['refs'], $fx['caps']));

// ---- caps: the constants ARE the fixture's numbers ----
check('caps maxLen', Logic::MAX_EXPR_LEN === $fx['caps']['maxLen']);
check('caps maxRefs', Logic::MAX_REFS === $fx['caps']['maxRefs']);
check('caps maxDepth', Logic::MAX_DEPTH === $fx['caps']['maxDepth']);

// ---- eval: parse must succeed, evaluate must match ----
foreach ($fx['eval'] as $c) {
    $r = Logic::parse($c['expr']);
    check('eval parses: ' . $c['name'], !empty($r['ok']));
    if (!empty($r['ok'])) {
        $values = isset($c['values']) && is_array($c['values']) ? $c['values'] : [];
        check('eval verdict: ' . $c['name'], Logic::evaluate($r['ast'], $values) === $c['expect']);
    }
}

// ---- errors: parse must fail with the expected substring ----
foreach ($fx['errors'] as $c) {
    $r = Logic::parse($c['expr']);
    $ok = empty($r['ok']) && isset($r['error'])
        && stripos($r['error'], $c['errorContains']) !== false;
    if (!$ok && isset($r['error'])) {
        fwrite(STDERR, "  got error: {$r['error']}\n");
    }
    check('error: ' . $c['name'], $ok);
}

// ---- astEval: prebuilt ASTs, incl. the server-folded ['const',bool] node ----
foreach ($fx['astEval'] as $c) {
    $values = isset($c['values']) && is_array($c['values']) ? $c['values'] : [];
    check('astEval verdict: ' . $c['name'], Logic::evaluate($c['ast'], $values) === $c['expect']);
}
foreach ($fx['astRefs'] as $c) {
    check('astRefs list: ' . $c['name'],
        json_encode(Logic::referencedFields($c['ast'])) === json_encode($c['expect']));
}

// ---- refs: referencedFields output locked (order + dedupe + lowercase) ----
foreach ($fx['refs'] as $c) {
    $r = Logic::parse($c['expr']);
    check('refs parses: ' . $c['name'], !empty($r['ok']));
    if (!empty($r['ok'])) {
        check('refs list: ' . $c['name'],
            json_encode(Logic::referencedFields($r['ast'])) === json_encode($c['expect']));
    }
}

// ---- non-string / hostile inputs never throw ----
foreach ([null, 7, 1.5, true, ['x'], "[a]='1'"] as $weird) {
    $r = Logic::parse($weird);
    check('parse total on ' . gettype($weird), is_array($r) && array_key_exists('ok', $r));
}

// ---- fold (PHP-only: the server decides what the browser may see, SEC-005) ----
function foldOf($expr, $values, $live)
{
    $r = Logic::parse($expr);
    if (empty($r['ok'])) return '(parse failed)';
    return json_encode(Logic::fold($r['ast'], $values, array_flip($live)));
}
// a comparison the browser CAN read is left live
check('fold: on-page ref stays a live ref',
    foldOf("[a]='1'", ['a' => '1'], ['a']) === '[["cmp","=",["ref","a",null],["lit","1"]]]'
    || foldOf("[a]='1'", ['a' => '1'], ['a']) === '["cmp","=",["ref","a",null],["lit","1"]]');
// a comparison the browser CANNOT read is settled here, value never shipped
check('fold: off-page ref folds to true', foldOf("[b]='1'", ['b' => '1'], ['a']) === '["const",true]');
check('fold: off-page ref folds to false', foldOf("[b]='1'", ['b' => '2'], ['a']) === '["const",false]');
check('fold: off-page value is absent from the folded AST',
    strpos(foldOf("[b]='x'", ['b' => 'SEEKRET'], ['a']), 'SEEKRET') === false);
check('fold: missing off-page value folds against empty',
    foldOf("[b]=''", [], ['a']) === '["const",true]');
// mixed and/or trees keep the live parts and fold the rest
check('fold: mixed tree keeps the live half',
    foldOf("[a]='1' and [b]='2'", ['a' => '1', 'b' => '2'], ['a'])
    === '["and",[["cmp","=",["ref","a",null],["lit","1"]],["const",true]]]');
check('fold: not() folds through',
    foldOf("not [b]='2'", ['b' => '2'], ['a']) === '["not",["const",true]]');
// a comparison mixing a live and an off-page ref cannot be resolved in the
// browser at all -> folded whole (correct at page load, no value shipped)
check('fold: live-vs-offpage comparison folds whole',
    foldOf("[a]=[b]", ['a' => 'x', 'b' => 'x'], ['a']) === '["const",true]');
// checkbox refs follow the same rule
check('fold: off-page checkbox ref folds',
    foldOf("[cb(2)]='1'", ['cb' => ['2' => '1']], ['a']) === '["const",true]');
check('fold: on-page checkbox ref stays live',
    strpos(foldOf("[cb(2)]='1'", ['cb' => ['2' => '1']], ['cb']), '"ref","cb","2"') !== false);
// literal-only comparisons need no field at all -> settled here
check('fold: literal-only comparison folds', foldOf("'1'='1'", [], ['a']) === '["const",true]');
// a folded AST evaluates to the same verdict as the unfolded one did
$vals = ['a' => '1', 'b' => '2'];
$un = Logic::parse("[a]='1' and [b]='2'");
$fo = Logic::fold($un['ast'], $vals, ['a' => true]);
check('fold: folded AST agrees with the unfolded verdict',
    Logic::evaluate($fo, $vals) === Logic::evaluate($un['ast'], $vals));
check('fold: folded AST only needs the live field to evaluate',
    Logic::evaluate($fo, ['a' => '1']) === true);

// ---- checkRefs (dictionary-dependent; PHP-only, used by config channels) ----
$types = [
    'age'  => 'text',
    'cb'   => 'checkbox',
    'cb2'  => 'checkbox',   // choices unknown -> membership not checked
    'sex'  => 'radio',
    'doc'  => 'file',
    'info' => 'descriptive',
];
$choices = ['cb' => ['1', '2', '3']];

function refErrors($expr, $types, $choices)
{
    $r = Logic::parse($expr);
    return empty($r['ok']) ? ['(parse failed)'] : Logic::checkRefs($r['ast'], $types, $choices);
}

check('checkRefs: plain text ref ok', refErrors("[age]>'17'", $types, $choices) === []);
$e = refErrors("[missing]='1'", $types, $choices);
check('checkRefs: unknown field', count($e) === 1 && stripos($e[0], 'not a field') !== false);
$e = refErrors("[cb]='1'", $types, $choices);
check('checkRefs: checkbox needs code', count($e) === 1 && stripos($e[0], 'is a checkbox') !== false
    && stripos($e[0], '1, 2, 3') !== false);
$e = refErrors("[cb(9)]='1'", $types, $choices);
check('checkRefs: bad checkbox code', count($e) === 1 && stripos($e[0], 'no choice code "9"') !== false);
check('checkRefs: good checkbox code ok', refErrors("[cb(2)]='1'", $types, $choices) === []);
$e = refErrors("[sex(1)]='1'", $types, $choices);
check('checkRefs: code on non-checkbox', count($e) === 1 && stripos($e[0], 'only checkbox fields') !== false);
$e = refErrors("[doc]='1'", $types, $choices);
check('checkRefs: file ref rejected', count($e) === 1 && stripos($e[0], 'file and descriptive') !== false);
$e = refErrors("[info]='1'", $types, $choices);
check('checkRefs: descriptive ref rejected', count($e) === 1 && stripos($e[0], 'file and descriptive') !== false);
check('checkRefs: unknown choices skip membership', refErrors("[cb2(5)]='1'", $types, $choices) === []);
$e = refErrors("[cb2]='1'", $types, $choices);
check('checkRefs: code still required without choices', count($e) === 1 && stripos($e[0], 'is a checkbox') !== false);
$e = refErrors("[missing]='1' and [cb]='1'", $types, $choices);
check('checkRefs: one error per bad ref', count($e) === 2);

// ---- parseChoiceCodes ----
check('choices: basic', Logic::parseChoiceCodes('1, Yes | 2, No') === ['1', '2']);
check('choices: labels with commas + blanks', Logic::parseChoiceCodes(' A-1, Label, with comma |2,X| | 3 ') === ['A-1', '2', '3']);
check('choices: no-comma part taken whole', Logic::parseChoiceCodes('1 | 2') === ['1', '2']);
check('choices: empty string', Logic::parseChoiceCodes('') === []);
check('choices: non-string', Logic::parseChoiceCodes(null) === []);

echo sprintf("when_php: %d checks, %d failure(s)\n", $n, $fail);
exit($fail === 0 ? 0 : 1);
