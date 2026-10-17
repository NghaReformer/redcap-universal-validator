#!/usr/bin/env python3
"""Mutations of @UVEXISTS "where" and of reading a condition whole, applied only to a disposable copy.

Each mutation breaks one guarantee the suites claim to hold. The named suite must then fail;
a mutation it survives is a hole in the tests. The anchors are exact text and must occur once.

Usage: PHP_BIN=php python tests/exists_where_mutations.py
"""
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parent.parent
PHP = os.environ.get('PHP_BIN', 'php')
NODE = os.environ.get('NODE_BIN', 'node')
UV = 'UniversalValidator.php'
WHERE = 'tests/exists_where_php.php'
DOM = 'tests/exists_where_dom_js.cjs'

# (name, file, original, replacement, suite)
MUTATIONS = [
    ('no confirm read after a narrowed read', UV,
     "if (is_array($n) && $spec['where'] !== null && $n) {",
     'if (false) {', WHERE),
    ('"where" left out of the lookup memo', UV,
     "$k['existsWhere'] = isset($rule['existsWhere'])",
     "$k['existsWhere'] = null && isset($rule['existsWhere'])", WHERE),
    ('"where" left out of the scan index key', UV,
     "$where !== null ? [Logic::canonicalKey((string) $rule['existsWhere'], $spec['fold']), $where['marks']] : null]);",
     'null]);', WHERE),
    ('"where" fields not among the fields read', 'php/AnnotationRules.php',
     "foreach (isset($out['existsWhereFields']) ? $out['existsWhereFields'] : [] as $t) $targets[] = (string) $t;",
     'foreach ([] as $t) $targets[] = (string) $t;', WHERE),
    ('a blank side of < and > passes', 'php/AnnotationRules.php',
     'Logic::BLANK_INERT, (bool) $caseSensitive',
     'Logic::BLANK_PASSES, (bool) $caseSensitive', WHERE),
    ('no checkbox fallback to the event row', UV,
     '$vals[$f] = $base[$f];',
     '$vals[$f] = $vals[$f];', WHERE),
    ('a decimal-comma field read as text', UV,
     "if (isset($spec['where']['marks'][$f]) && $spec['where']['marks'][$f] === 'comma') $comma[] = $f;",
     '', WHERE),
    ('the condition sent to the page', 'php/modes.json',
     '"existsReturn", "existsWhere", "existsWhereFields"]',
     '"existsReturn", "existsWhereFields"]', WHERE),
    ('a filtered miss loses its mark', UV,
     "if ($filtered && $miss['state'] === 'not-found') $miss['filtered'] = true;",
     'if (false) $miss[\'filtered\'] = true;', WHERE),
    ('a survey told the value is filtered', UV,
     "&& $isAuthenticated && !$isSurvey) $resp['filtered'] = true;",
     ") $resp['filtered'] = true;", WHERE),
    ('record IDs in another letter case not judged', UV,
     '$hit = $this->recordWhereHit($pid, $spec, $more, $filtered);',
     '$hit = null;', WHERE),
    ('no kind check of "where" comparisons', UV,
     "$why = self::existsWhereKindProblem($p['ast'], $ddThere);",
     '$why = null;', WHERE),
    ('no placement check of "where" fields', UV,
     "'Test', 'test',\n                $f !== $pkThere);\n            if ($why !== null) return $hide($why);",
     "'Test', 'test',\n                $f !== $pkThere);", WHERE),
    ('another project\'s refusal names its fields', UV,
     "return $there === '' ? $why : sprintf(self::CROSS_FILTER_UNAVAILABLE, (int) $pidThere);",
     'return $why;', 'tests/exists_cross_php.php'),
    ('the report explains a filtered finding with "message"', 'php/MessageCatalog.php',
     "&& array_key_exists($code, $rule['messages']))",
     '&& false)', WHERE),
    ('the rule snapshot carries no reason texts', 'php/ScanDimensions.php',
     "'messages' => $t['messages'],",
     "'messages' => [],", WHERE),
    ('the page shows "whereMessage" on a survey', 'js/engine.js',
     'if(resp.filtered === true && !QRID_IS_SURVEY){',
     'if(resp.filtered === true){', DOM),
    ('the page shows "message" for a filtered value', 'js/engine.js',
     '(V.whereMessage ? QRID_ruleHtml(V, "whereMessage")',
     '(V.message ? QRID_ruleHtml(V, "message")', DOM),
    ('the greedy reader ignores its flag', 'php/AnnotationRules.php',
     "if ($bare === 'condition') {",
     'if (true) {', 'tests/annotation_php.php'),
    ('branch conditions compared as plain text', 'php/Branching.php',
     "$keys[] = 'x' . Logic::canonicalKey($w);\n"
     "                if (empty($rules[$i]['caseSensitive'])) $keys[] = 'f' . Logic::canonicalKey($w, true);",
     "$keys[] = 'x' . trim($w);\n"
     "                if (empty($rules[$i]['caseSensitive'])) $keys[] = 'f' . strtolower(trim($w));",
     'tests/branching_php.php'),
    ('the docs harness tests nothing', 'php/AnnotationRules.php',
     "return Logic::evaluate($ast, $values, Logic::BLANK_INERT",
     "return true || Logic::evaluate($ast, $values, Logic::BLANK_INERT",
     'tests/docs_examples_php.php'),
]


def run(suite, cwd):
    cmd = [NODE, suite] if suite.endswith('.cjs') else [PHP, suite]
    return subprocess.run(cmd, cwd=cwd, capture_output=True, text=True, encoding='utf-8', errors='replace')


def main():
    for suite in sorted({m[-1] for m in MUTATIONS}):
        res = run(suite, ROOT)
        if res.returncode != 0:
            sys.exit(f'{suite} fails before any mutation:\n{res.stdout[-2000:]}{res.stderr[-2000:]}')
    survived = []
    with tempfile.TemporaryDirectory(prefix='uv-where-mutations-') as directory:
        copy = Path(directory)
        for folder in ['php', 'js', 'tests', 'data', 'docs']:
            shutil.copytree(ROOT / folder, copy / folder)
        for filename in [UV, 'config.json']:
            shutil.copy2(ROOT / filename, copy / filename)
        for name, filename, original, replacement, suite in MUTATIONS:
            path = copy / filename
            source = path.read_text(encoding='utf-8')
            if source.count(original) != 1:
                sys.exit(f'{name}: the anchor must occur once in {filename}, found {source.count(original)}')
            path.write_text(source.replace(original, replacement), encoding='utf-8', newline='')
            if filename.endswith('.php'):
                subprocess.run([PHP, '-l', str(path)], check=True, stdout=subprocess.DEVNULL)
            result = run(suite, copy)
            path.write_text(source, encoding='utf-8', newline='')
            if result.returncode == 0:
                survived.append(name)
                print(f'SURVIVED: {name} ({suite})')
            else:
                print(f'killed: {name}')
    if survived:
        sys.exit(f'exists_where_mutations: {len(survived)} of {len(MUTATIONS)} mutations survived')
    print(f'exists_where_mutations: {len(MUTATIONS)} mutations, every one killed')


if __name__ == '__main__':
    main()
