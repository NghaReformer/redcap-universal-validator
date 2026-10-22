<?php
/**
 * value_parity_php.php — the server half of tests/value_parity_fixture.json.
 *
 * Runs every row through CheckCharacter::validateSingleField /
 * validatePooledField with the config UniversalValidator::findingsCheck
 * builds, and skips a value findingsCheck skips as blank. The browser half is
 * tests/value_parity_dom_js.cjs. Also checks that each row's two columns say
 * the same thing, so the fixture cannot drift into recording a split, and
 * locks the helpers the parity rests on (browserTrim, jsLength,
 * dialectSplits).
 *
 * Run:  php tests/value_parity_php.php
 */

require_once __DIR__ . '/../php/CheckCharacter.php';

use INSPIRE\UniversalValidator\CheckCharacter;

$fx = json_decode(file_get_contents(__DIR__ . '/value_parity_fixture.json'), true);
$n = 0;
$fail = 0;
function check($label, $cond)
{
    global $n, $fail;
    $n++;
    if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
}

/** The verdict config of UniversalValidator::findingsCheck, key for key. */
function vcfg(array $rule)
{
    return [
        'algorithm'   => isset($rule['algorithm']) && $rule['algorithm'] !== '' ? $rule['algorithm'] : 'iso7064_mod37_36',
        'source'      => isset($rule['source']) && $rule['source'] !== '' ? $rule['source'] : 'normalized_id',
        'strip'       => isset($rule['strip']) ? $rule['strip'] : "-/ _|\\",
        'idPattern'   => isset($rule['idPattern']) ? $rule['idPattern'] : null,
        'alternates'  => isset($rule['alternates']) ? $rule['alternates'] : null,
        'keepChars'   => isset($rule['keepChars']) ? $rule['keepChars'] : '',
        'idLengths'   => isset($rule['idLengths']) ? $rule['idLengths'] : null,
        'idMinLen'    => isset($rule['idMinLen']) ? $rule['idMinLen'] : null,
        'idMaxLen'    => isset($rule['idMaxLen']) ? $rule['idMaxLen'] : null,
        'expectedIds' => isset($rule['expectedIds']) ? $rule['expectedIds'] : null,
    ];
}

/** "skip", "valid", "not-checked", "unconfigurable" or the finding's reason. */
function serverSays(array $rule, $value)
{
    if (CheckCharacter::browserTrim($value) === '') return 'skip';
    $type = isset($rule['type']) ? $rule['type'] : 'single';
    $res = $type === 'pooled'
        ? CheckCharacter::validatePooledField(vcfg($rule), $value)
        : CheckCharacter::validateSingleField(vcfg($rule), $value);
    $reason = isset($res['reason']) ? $res['reason'] : '';
    if ($reason === 'not-checked' || $reason === 'unconfigurable') return $reason;
    return empty($res['ok']) ? $reason : 'valid';
}

/** Whether a row's browser and server columns describe the same outcome. */
function columnsAgree($browser, $server)
{
    if ($server === 'not-checked') return true;      // reported for a manual check, whatever the browser said
    if ($browser === 'blank') return $server === 'skip';
    if ($browser === 'valid') return $server === 'valid';
    return $server !== 'skip' && $server !== 'valid' && $server !== 'unconfigurable';
}

function valueOf($v)
{
    if (is_string($v)) return $v;
    return (isset($v['prefix']) ? $v['prefix'] : '') . str_repeat($v['repeat'], $v['times']);
}

// ---- 1) the fixture rows ----
foreach ($fx['groups'] as $g) {
    foreach ($g['rows'] as $row) {
        list($value, $browser, $server) = $row;
        $label = json_encode($g['rule']) . ' ' . json_encode($value);
        check("$label: the fixture's columns agree ($browser / $server)", columnsAgree($browser, $server));
        $got = serverSays($g['rule'], $value);
        check("$label: server says \"$server\" (got \"$got\")", $got === $server);
    }
}
foreach ($fx['length']['cases'] as $c) {
    $value = valueOf($c['value']);
    $label = 'length ' . json_encode($c['value']);
    check("$label: the fixture's columns agree", columnsAgree($c['browser'], $c['server']));
    $got = serverSays($c['rule'], $value);
    check("$label: server says \"{$c['server']}\" (got \"$got\")", $got === $c['server']);
}

// ---- 2) browserTrim is String.prototype.trim ----
$ws = ["\t", "\n", "\x0B", "\f", "\r", ' ', "\u{00A0}", "\u{1680}", "\u{2000}", "\u{200A}",
       "\u{2028}", "\u{2029}", "\u{202F}", "\u{205F}", "\u{3000}", "\u{FEFF}"];
foreach ($ws as $w) {
    check('browserTrim strips ' . json_encode($w), CheckCharacter::browserTrim($w . 'A' . $w) === 'A');
}
foreach (["\u{200B}", "\u{180E}", "\u{0085}", "\x00", "\x7F"] as $w) {
    // not in the ECMAScript WhiteSpace / LineTerminator sets
    check('browserTrim keeps ' . json_encode($w), CheckCharacter::browserTrim($w . 'A') === $w . 'A');
}
check('browserTrim keeps inner whitespace', CheckCharacter::browserTrim(" A \u{00A0}B ") === "A \u{00A0}B");
check('browserTrim of invalid UTF-8 still trims ASCII space', CheckCharacter::browserTrim(" \xC3( ") === "\xC3(");

// ---- 3) jsLength counts UTF-16 units ----
check('jsLength: ASCII', CheckCharacter::jsLength('FC1-0589C') === 9);
check('jsLength: BMP letter is one unit', CheckCharacter::jsLength("\u{00E9}") === 1);
check('jsLength: astral character is two units', CheckCharacter::jsLength("\u{1F600}") === 2);

// ---- 4) dialectSplits: only where JS RegExp and PCRE /u can part ----
$splits = [
    ['FC.[0-9]{4}',       "FC\r1234",       true,  '"." against CR'],
    ['FC.[0-9]{4}',       "FC\u{2028}1234", true,  '"." against U+2028'],
    ['FC.[0-9]{4}',       "FC\u{00E9}1234", false, '"." against a BMP letter'],
    ['FC\\s[0-9]{4}',     "FC\u{00A0}1234", true,  '"\\s" against a no-break space'],
    ['FC\\S[0-9]{4}',     "FC\u{3000}1234", true,  '"\\S" against U+3000'],
    ['FC\\s[0-9]{4}',     "FC\r1234",       false, '"\\s" against CR (both engines match)'],
    ['FC[^0-9][0-9]{4}',  "FC\u{00E9}1234", false, 'a negated class against a BMP letter'],
    ['FC[0-9]{4}',        "FC\u{00E9}1234", false, 'a positive ASCII class'],
    ['FC[0-9]{4}',        'FC1234',         false, 'printable ASCII never splits'],
    ['FC.[0-9]{4}',       "FC\xC3(1234",    true,  'a value that is not UTF-8'],
];
foreach ($splits as $s) {
    check('dialectSplits: ' . $s[3], CheckCharacter::dialectSplits($s[0], $s[1]) === $s[2]);
}

// ---- 5) no value outside printable ASCII is passed unchecked (VAL-1) ----
// The old server returned "valid" before any check math for every such value.
$bare = vcfg(['type' => 'single']);
foreach (["FC1-0589\u{0421}", "FC1-0589C\u{200B}", "\u{00E9}\u{00E9}\u{00E9}\u{00E9}\u{00E9}\u{00E9}\u{00E9}\u{00E9}", "FC1-0589C\u{1F600}"] as $v) {
    $res = CheckCharacter::validateSingleField($bare, $v);
    check('VAL-1: ' . json_encode($v) . ' fails the check', $res === ['ok' => false, 'reason' => 'check-character']);
}

// ---- 6) a PCRE engine failure is "not-checked", never "valid" (VAL-10) ----
$old = ini_get('pcre.backtrack_limit');
ini_set('pcre.backtrack_limit', '100');
$res = CheckCharacter::validateSingleField(
    ['algorithm' => 'none', 'source' => 'normalized_id', 'strip' => '', 'idPattern' => 'A{1,40}A{1,40}A{1,40}9'],
    str_repeat('A', 28) . '9A');
ini_set('pcre.backtrack_limit', $old === false ? '1000000' : $old);
check('VAL-10: a backtrack-limit hit on a single field is not-checked', $res === ['ok' => true, 'reason' => 'not-checked']);

// ---- 7) a broken pattern is refused before the value is read ----
foreach (['(a+)+$', '(?<a>[A-Z]', 'FC[0-9'] as $p) {
    $res = CheckCharacter::validateSingleField(['algorithm' => 'none', 'source' => 'normalized_id', 'strip' => '', 'idPattern' => $p],
        str_repeat('A', 600));
    check('a broken pattern ' . json_encode($p) . ' is unconfigurable, even for a long value', $res === ['ok' => true, 'reason' => 'unconfigurable']);
}

echo "value_parity_php: " . ($n - $fail) . "/$n passed\n";
exit($fail ? 1 : 0);
