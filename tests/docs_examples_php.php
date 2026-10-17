<?php
/**
 * Every action tag printed in the user-facing example docs is parsed by the
 * real annotation parser, so an example cannot drift from the implementation.
 *
 * A fenced block is split into statements: one starts on a line beginning
 * with "@UV" and runs until a blank line, a "#" comment line or the next tag.
 * A block whose info string contains "invalid" (```text invalid) holds tags
 * that MUST be refused; every other tag must parse without a configuration
 * error. Lines may carry trailing commentary after the tag value.
 *
 * In an "invalid" block, a "# refused: <text>" comment line pins the reason:
 * the next tag's error must contain <text> (case-insensitive), so an example
 * cannot pass by being refused for some other mistake.
 *
 * A block whose info string contains "expect" (```text expect) tests the LAST
 * @UVALIDATE or @UVRANGE tag of the fenced block just before it. Each line reads
 *
 *     <input>  =>  <result>
 *
 * split at the last "=>". <input> is the raw value ("\n" stands for a new
 * line). <result> is one of
 *
 *     valid                            single-ID rule accepted the value
 *     valid: ID1, ID2, ...             pooled rule accepted; the members in order
 *     refused: reason1, reason2        the verdict's reason codes, in order
 *
 * and for @UVRANGE one of
 *
 *     ok                               inside every limit
 *     blank                            nothing to check
 *     soft-low, soft-high              outside "soft" (unusual)
 *     hard-low, hard-high              outside "hard" (implausible)
 *     not-a-number                     not a plain decimal number
 *
 * A @UVRANGE rule with a growth "reference" also reads its inputs, given
 * after the value as "with <field>=<value>, ..." (dates as Y-M-D; an input
 * left out is blank). Its result is one of
 *
 *     ok, z 0.12                       inside every limit, with the z-score
 *     soft-low, z -3.40                (and the other tiers) with the z-score
 *     plausible-low, plausible-high    no z-score, outside "plausible"
 *     not evaluated                    no z-score, and nothing else to judge
 *     blank, not-a-number, not-positive
 *
 * The inputs are read by GrowthReference::inputsFor, the reading the
 * post-save audit uses, against the references the module ships and the
 * made-up ones in data/references/examples, read as a folder of extra
 * references is ($examples below). Every tag is parsed with that folder as
 * its "referenceDir", so a "show" span outside its reference is refused
 * here as the dictionary hook refuses it on a server.
 *
 * The value runs through the same verdict the post-save audit uses. Every
 * expect line is also written to tests/docs_expect_fixture.json, which
 * tests/docs_examples_js.cjs replays through the browser engine, so the two
 * runtimes are held to the same documented outcomes. A stale fixture fails
 * this test; regenerate it with --write.
 *
 * Run: php tests/docs_examples_php.php [--write]
 */
namespace INSPIRE\UniversalValidator;

require_once __DIR__ . '/../php/AnnotationRules.php';
require_once __DIR__ . '/../php/CheckCharacter.php';
require_once __DIR__ . '/../php/Logic.php';
require_once __DIR__ . '/../php/GrowthReference.php';

$docs = [
    'docs/action_tag_validation_examples.md',
    'docs/EVENT-INSTANCE-REFERENCES.md',
    'docs/region-site-choices-example.txt',
];
$fixturePath = __DIR__ . '/docs_expect_fixture.json';
// The folder of extra references the guide's walkthrough of a reference of
// your own builds; a problem in it fails the run before any doc is read.
$examples = __DIR__ . '/../data/references/examples';
foreach (GrowthReference::catalog($examples)['problems'] as $p) {
    fwrite(STDERR, "FAIL data/references/examples: $p\n");
    exit(1);
}
$write = in_array('--write', array_slice($argv, 1), true);

$checks = 0;
$failures = [];
$fixture = [];

/** Statements of one block: [[firstLine, text, refusedReason|null], ...]. */
$statements = function (array $lines, $start) {
    $out = [];
    $cur = null;
    $reason = null;
    foreach ($lines as $i => $line) {
        $t = ltrim($line);
        if (strncmp($t, '@UV', 3) === 0) {
            if ($cur) $out[] = $cur;
            $cur = [$start + $i, $t, $reason];
            $reason = null;
        } elseif ($cur && $t !== '' && $t[0] !== '#') {
            $cur[1] .= "\n" . $t;
        } else {
            if ($cur) $out[] = $cur;
            $cur = null;
            if (preg_match('/^#\s*refused:\s*(.+)$/i', $t, $m)) $reason = trim($m[1]);
        }
    }
    if ($cur) $out[] = $cur;
    return $out;
};

/** The verdict the post-save audit computes for one value (UniversalValidator::auditRule). */
$verdict = function (array $frag, $value) {
    $type = isset($frag['type']) ? $frag['type'] : 'single';
    $cfg = [
        'algorithm'   => isset($frag['algorithm']) && $frag['algorithm'] !== '' ? $frag['algorithm'] : 'iso7064_mod37_36',
        'source'      => isset($frag['source']) && $frag['source'] !== '' ? $frag['source'] : 'normalized_id',
        'strip'       => isset($frag['strip']) ? $frag['strip'] : "-/ _|\\",
        'idPattern'   => isset($frag['idPattern']) ? $frag['idPattern'] : null,
        'alternates'  => isset($frag['alternates']) ? $frag['alternates'] : null,
        'keepChars'   => isset($frag['keepChars']) ? $frag['keepChars'] : '',
        'idLengths'   => isset($frag['idLengths']) ? $frag['idLengths'] : null,
        'idMinLen'    => isset($frag['idMinLen']) ? $frag['idMinLen'] : null,
        'idMaxLen'    => isset($frag['idMaxLen']) ? $frag['idMaxLen'] : null,
        'expectedIds' => isset($frag['expectedIds']) ? $frag['expectedIds'] : null,
    ];
    if ($type === 'pooled') {
        $res = CheckCharacter::validatePooledField($cfg, $value);
        $segs = CheckCharacter::pooledParse($cfg, $value);
        $ids = [];
        $canon = [];
        foreach ((array) $segs as $s) {
            if ($s['type'] === 'id') {
                $ids[] = $s['id'];
                $canon[] = ['id', $s['id'], (bool) $s['valid'], isset($s['alt']) && is_int($s['alt']) ? $s['alt'] : -1];
            } else {
                $canon[] = ['junk', $s['text']];
            }
        }
        $text = !empty($res['ok']) ? 'valid: ' . implode(', ', $ids)
                                   : 'refused: ' . implode(', ', explode(';', (string) $res['reason']));
        return [$text, !empty($res['ok']), $segs === null ? null : $canon];
    }
    $res = CheckCharacter::validateSingleField($cfg, $value);
    return [!empty($res['ok']) ? 'valid' : 'refused: ' . $res['reason'], !empty($res['ok']), null];
};

/** The limits the @UVRANGE verdict reads (UniversalValidator::findingsRange). */
$rangeSpec = function (array $frag) {
    $spec = ['decimalComma' => false];
    foreach (['softLo' => 'rangeSoftLo', 'softHi' => 'rangeSoftHi', 'hardLo' => 'rangeHardLo', 'hardHi' => 'rangeHardHi'] as $k => $rk) {
        if (isset($frag[$rk])) $spec[$k] = $frag[$rk];
    }
    return $spec;
};

$norm = function ($s) { return preg_replace('/\s+/', ' ', trim($s)); };

/**
 * A growth rule's documented outcome (UniversalValidator::findingsRange):
 * [outcome text, fixture case]. $inputs maps field name => value.
 */
$growth = function (array $frag, $value, array $inputs) use ($rangeSpec, $examples) {
    $entry = GrowthReference::entry($frag['rangeReference'], $examples);
    if ($entry === null) return ['unknown reference ' . $frag['rangeReference'], null];
    $table = GrowthReference::table($entry);
    $scale = isset($frag['rangeMeasureScale']) ? $frag['rangeMeasureScale'] : null;
    $case = ['reference' => $frag['rangeReference'], 'input' => $value, 'scale' => $scale, 'spec' => $rangeSpec($frag),
             'plausibleLo' => isset($frag['rangePlausibleLo']) ? $frag['rangePlausibleLo'] : null,
             'plausibleHi' => isset($frag['rangePlausibleHi']) ? $frag['rangePlausibleHi'] : null,
             'sex' => null, 'x' => null];
    $m = GrowthReference::measure($value, false, $scale);
    if ($m['state'] !== 'ok') return [$m['state'], $case];
    $read = [];
    foreach (['rangeSex', 'rangeAgeDob', 'rangeAgeAt', 'rangeAgeDays', 'rangeAgeMonths', 'rangeBy'] as $k) {
        if (!isset($frag[$k])) continue;
        // the field a reference names: its last [name], so "[enrol_arm_1][dob]" reads dob
        $f = preg_match('/\[([a-z0-9_]+)\]$/', $frag[$k], $mm) ? $mm[1] : '';
        $read[$k] = isset($inputs[$f]) ? trim($inputs[$f], " \t\r\n") : '';
    }
    $in = GrowthReference::inputsFor($frag, $read, $entry);
    if ($in['state'] !== 'ok') {
        $p = GrowthReference::plausible($m['y'], $case['plausibleLo'], $case['plausibleHi']);
        return [$p !== null ? $p : 'not evaluated', $case];
    }
    // x as the shortest text that reads back as the same double, in both runtimes
    $case['sex'] = $in['sex'];
    $case['x'] = sprintf('%.17g', $in['x']);
    $z = GrowthReference::zScore($entry, $table, $in['sex'], $in['x'], $m['y']);
    if ($z['state'] !== 'ok') return ['no row', $case];
    $r = Logic::rangeVerdict($case['spec'], $z['z']);
    return [($r['tier'] === 'ok' ? 'ok' : $r['reason']) . ', z ' . $z['z'], $case];
};

foreach ($docs as $doc) {
    $path = __DIR__ . '/../' . $doc;
    $text = file_get_contents($path);
    if ($text === false) { $failures[] = "$doc: unreadable"; continue; }
    $lines = preg_split('/\r\n|\n|\r/', $text);
    $blocks = [];
    if (substr($doc, -4) === '.txt') {
        $blocks[] = ['plain', 1, $lines];
    } else {
        $open = null;
        foreach ($lines as $n => $line) {
            if (strncmp(ltrim($line), '```', 3) === 0) {
                if ($open === null) {
                    $kind = stripos($line, 'invalid') !== false ? 'invalid'
                          : (stripos($line, 'expect') !== false ? 'expect' : 'plain');
                    $open = [$kind, $n + 2, []];
                } else { $blocks[] = $open; $open = null; }
            } elseif ($open !== null) {
                $open[2][] = $line;
            }
        }
    }
    $previous = null;   // [kind, last parsed @UVALIDATE fragment or null, its line]
    foreach ($blocks as $block) {
        list($kind, $start, $body) = $block;
        if ($kind === 'expect') {
            if (!$previous || $previous[0] !== 'plain' || !$previous[1]) {
                $failures[] = "$doc:$start expect block does not follow a block ending in a valid @UVALIDATE or @UVRANGE tag";
                $previous = null;
                continue;
            }
            list(, $frag, $tagLine) = $previous;
            foreach ($body as $i => $line) {
                $t = trim($line);
                if ($t === '' || $t[0] === '#') continue;
                $lineNo = $start + $i;
                $cut = strrpos($t, '=>');
                if ($cut === false) { $failures[] = "$doc:$lineNo expect line has no \"=>\": $t"; continue; }
                $input = str_replace('\n', "\n", trim(substr($t, 0, $cut)));
                $want = $norm(substr($t, $cut + 2));
                if (($frag['type'] ?? null) === 'range' && isset($frag['rangeReference'])) {
                    $with = [];
                    $w = stripos($input, ' with ');
                    if ($w !== false) {
                        foreach (explode(',', substr($input, $w + 6)) as $pair) {
                            $eq = strpos($pair, '=');
                            if ($eq === false) { $failures[] = "$doc:$lineNo expect input \"$pair\" is not <field>=<value>"; continue 2; }
                            $with[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
                        }
                        $input = trim(substr($input, 0, $w));
                    }
                    list($got, $case) = $growth($frag, $input, $with);
                    $checks++;
                    if ($norm($got) !== $want) {
                        $failures[] = "$doc:$lineNo " . json_encode($input) . ' with ' . json_encode($with) . " expected \"$want\" but got \"$got\"";
                    }
                    if ($case !== null) {
                        $fixture[] = ['where' => "$doc:$lineNo", 'tagLine' => $tagLine, 'kind' => 'growth', 'outcome' => $got] + $case;
                    }
                    continue;
                }
                if (($frag['type'] ?? null) === 'range') {
                    $spec = $rangeSpec($frag);
                    $r = Logic::rangeVerdict($spec, $input);
                    $got = $r['tier'] === 'ok' ? 'ok' : ($r['tier'] === 'inert' ? 'blank' : $r['reason']);
                    $checks++;
                    if ($norm($got) !== $want) {
                        $failures[] = "$doc:$lineNo " . json_encode($input) . " expected \"$want\" but got \"$got\"";
                    }
                    $fixture[] = ['where' => "$doc:$lineNo", 'tagLine' => $tagLine, 'kind' => 'range', 'spec' => $spec,
                                  'input' => $input, 'tier' => $r['tier'], 'reason' => $r['reason']];
                    continue;
                }
                list($got, $ok, $segs) = $verdict($frag, $input);
                $checks++;
                if ($norm($got) !== $want) {
                    $failures[] = "$doc:$lineNo " . json_encode($input) . " expected \"$want\" but got \"$got\"";
                }
                $fixture[] = ['where' => "$doc:$lineNo", 'tagLine' => $tagLine, 'rule' => $frag,
                              'input' => $input, 'ok' => $ok, 'segs' => $segs];
            }
            $previous = null;
            continue;
        }
        $last = null;
        $lastLine = null;
        foreach ($statements($body, $start) as $st) {
            list($lineNo, $tagText, $reason) = $st;
            $frags = AnnotationRules::parseAllTags($tagText, ['qualified' => true, 'referenceDir' => $examples]);
            $checks++;
            $last = null;
            if ($frags === null) { $failures[] = "$doc:$lineNo no tag recognised: " . substr($tagText, 0, 90); continue; }
            $errors = [];
            foreach ($frags as $f) if (isset($f['error'])) $errors[] = $f['error'];
            if ($kind === 'invalid') {
                if (!$errors) $failures[] = "$doc:$lineNo should be refused but parsed: " . substr($tagText, 0, 90);
                elseif ($reason !== null && stripos(implode(' ', $errors), $reason) === false) {
                    $failures[] = "$doc:$lineNo refused, but not for \"$reason\": " . implode(' | ', $errors);
                }
                continue;
            }
            if ($errors) { $failures[] = "$doc:$lineNo " . implode(' | ', $errors) . ' :: ' . substr($tagText, 0, 90); continue; }
            if (count($frags) === 1 && (strncmp($tagText, '@UVALIDATE', 10) === 0 || strncmp($tagText, '@UVRANGE', 8) === 0)) {
                $last = $frags[0];
                $lastLine = $lineNo;
            }
        }
        $previous = [$kind, $last, $lastLine];
    }
}

$json = json_encode(['note' => 'Generated by tests/docs_examples_php.php --write from the expect blocks in the docs; do not edit.',
                     'cases' => $fixture], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
if ($write) {
    file_put_contents($fixturePath, $json);
    echo "wrote " . count($fixture) . " expect cases to tests/docs_expect_fixture.json\n";
} elseif (!is_file($fixturePath) || str_replace("\r\n", "\n", file_get_contents($fixturePath)) !== $json) {
    $failures[] = 'tests/docs_expect_fixture.json is stale: run php tests/docs_examples_php.php --write and commit it';
}

foreach ($failures as $f) echo "FAIL $f\n";
echo 'docs_examples_php: ' . $checks . ' checks (' . count($fixture) . ' expect lines), ' . count($failures) . " failures\n";
exit($failures ? 1 : 0);
