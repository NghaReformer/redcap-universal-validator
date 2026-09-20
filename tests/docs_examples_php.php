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
 * Run: php tests/docs_examples_php.php
 */
namespace INSPIRE\UniversalValidator;

require_once __DIR__ . '/../php/AnnotationRules.php';

$docs = [
    'docs/action_tag_validation_examples.md',
    'docs/EVENT-INSTANCE-REFERENCES.md',
    'docs/region-site-choices-example.txt',
];

$checks = 0;
$failures = [];

/** Statements of one block: [[firstLine, text], ...]. */
$statements = function (array $lines, $start) {
    $out = [];
    $cur = null;
    foreach ($lines as $i => $line) {
        $t = ltrim($line);
        if (strncmp($t, '@UV', 3) === 0) {
            if ($cur) $out[] = $cur;
            $cur = [$start + $i, $t];
        } elseif ($cur && $t !== '' && $t[0] !== '#') {
            $cur[1] .= "\n" . $t;
        } else {
            if ($cur) $out[] = $cur;
            $cur = null;
        }
    }
    if ($cur) $out[] = $cur;
    return $out;
};

foreach ($docs as $doc) {
    $path = __DIR__ . '/../' . $doc;
    $text = file_get_contents($path);
    if ($text === false) { $failures[] = "$doc: unreadable"; continue; }
    $lines = preg_split('/\r\n|\n|\r/', $text);
    $blocks = [];
    if (substr($doc, -4) === '.txt') {
        $blocks[] = [false, 1, $lines];
    } else {
        $open = null;
        foreach ($lines as $n => $line) {
            if (strncmp(ltrim($line), '```', 3) === 0) {
                if ($open === null) $open = [stripos($line, 'invalid') !== false, $n + 2, []];
                else { $blocks[] = $open; $open = null; }
            } elseif ($open !== null) {
                $open[2][] = $line;
            }
        }
    }
    foreach ($blocks as $block) {
        list($mustFail, $start, $body) = $block;
        foreach ($statements($body, $start) as $st) {
            list($lineNo, $tagText) = $st;
            $frags = AnnotationRules::parseAllTags($tagText, ['qualified' => true]);
            $checks++;
            if ($frags === null) { $failures[] = "$doc:$lineNo no tag recognised: " . substr($tagText, 0, 90); continue; }
            $errors = [];
            foreach ($frags as $f) if (isset($f['error'])) $errors[] = $f['error'];
            if ($mustFail && !$errors) $failures[] = "$doc:$lineNo should be refused but parsed: " . substr($tagText, 0, 90);
            if (!$mustFail && $errors) $failures[] = "$doc:$lineNo " . implode(' | ', $errors) . ' :: ' . substr($tagText, 0, 90);
        }
    }
}

foreach ($failures as $f) echo "FAIL $f\n";
echo 'docs_examples_php: ' . $checks . ' checks, ' . count($failures) . " failures\n";
exit($failures ? 1 : 0);
