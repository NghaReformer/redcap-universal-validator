#!/usr/bin/env python3
"""Mutations of @UVDELTA, applied only to a disposable copy.

Each mutation breaks one guarantee the suites claim to hold. The named suite must then fail;
a mutation it survives is a hole in the tests. The anchors are exact text and must occur once.

Usage: PHP_BIN=php python tests/delta_mutations.py
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
LOGIC = 'php/Logic.php'
RULES = 'php/AnnotationRules.php'
ENGINE = 'js/engine.js'
VERDICT_PHP = 'tests/delta_php.php'
VERDICT_JS = 'tests/delta_js.cjs'
DOM = 'tests/delta_dom_js.cjs'
MODULE = 'tests/delta_module_php.php'

# (name, file, original, replacement, suite)
MUTATIONS = [
    # ---- the verdict, PHP ----
    ('an earlier 0 judged by maxPct', LOGIC,
     "if ($pct !== null && self::decCmp($base, '0') === 0) {",
     'if (false) {', VERDICT_PHP),
    ('direction ignored', LOGIC,
     "if ($sign === 0 || ($sign > 0 && $dir === 'down') || ($sign < 0 && $dir === 'up')) {",
     'if ($sign === 0) {', VERDICT_PHP),
    ('"max" exclusive', LOGIC,
     "if ($max !== null && self::decCmp($size, $max) > 0)",
     "if ($max !== null && self::decCmp($size, $max) >= 0)", VERDICT_PHP),
    ('the percent compared as floats', LOGIC,
     "if (self::decCmp($lhs, $rhs) > 0) return ['verdict' => $way, 'reason' => 'maxPct'",
     "if ((float) $lhs > (float) $rhs) return ['verdict' => $way, 'reason' => 'maxPct'", VERDICT_PHP),
    ('the percent of the new value, not the earlier one', LOGIC,
     "$rhs = ExactDecimal::multiply($pct, $base);",
     "$rhs = ExactDecimal::multiply($pct, ltrim($v, '+-'));", VERDICT_PHP),
    ('a percent too long to work out passes', LOGIC,
     "if ($lhs === null || $rhs === null) return ['verdict' => 'unknown', 'reason' => 'too-long', 'change' => $change];",
     "if ($lhs === null || $rhs === null) return ['verdict' => 'ok', 'reason' => null, 'change' => $change];", VERDICT_PHP),
    ('the earlier value read with a point always', LOGIC,
     "$e = self::normalizeNumber($earlier, !empty($spec['earlierComma']));",
     '$e = self::normalizeNumber($earlier, false);', VERDICT_PHP),
    # ---- the verdict, browser ----
    ('JS: an earlier 0 judged by maxPct', ENGINE,
     'if(pct !== null && QRID_whenDecCmp(base, "0") === 0){',
     'if(false){', VERDICT_JS),
    ('JS: "max" exclusive', ENGINE,
     'if(max !== null && QRID_whenDecCmp(size, max) > 0)',
     'if(max !== null && QRID_whenDecCmp(size, max) >= 0)', VERDICT_JS),
    ('JS: the percent compared as floats', ENGINE,
     'if(QRID_whenDecCmp(lhs, rhs) > 0) return { verdict: way, reason: "maxPct"',
     'if(Number(lhs) > Number(rhs)) return { verdict: way, reason: "maxPct"', VERDICT_JS),
    ('JS: a change too long to add passes', ENGINE,
     'if(change === null) return { verdict: "unknown", reason: "too-long", change: null };',
     'if(change === null) return { verdict: "ok", reason: null, change: null };', VERDICT_JS),
    # ---- the grammar ----
    ('a shorthand in capitals not read', RULES,
     '$kw = strtolower($vs);',
     '$kw = $vs;', VERDICT_PHP),
    ('a limit loses the digits it was typed with', RULES,
     '$b = self::rangeBound($cfg[$k], $typed);',
     '$b = self::rangeBound($cfg[$k], null);', VERDICT_PHP),
    ('a shorthand accepted with references off', RULES,
     "            if (empty($opts['qualified'])) {\n                $errors[] = '\"vs\": \"' . $frag['deltaVs']",
     "            if (false) {\n                $errors[] = '\"vs\": \"' . $frag['deltaVs']", VERDICT_PHP),
    ('a negative limit accepted', RULES,
     "} elseif (Logic::numCompare($v, '0') < 0) {\n                $errors[] = '\"' . $label . '\" must be 0 or more",
     "} elseif (false) {\n                $errors[] = '\"' . $label . '\" must be 0 or more", VERDICT_PHP),
    ('the branch group forgets the direction', RULES,
     "return strtolower(preg_replace('/\\s+/', '', (string) $vs)) . ' ' . $direction;",
     "return strtolower(preg_replace('/\\s+/', '', (string) $vs));", VERDICT_PHP),
    ('no branch groups: two tags on one field conflict', 'php/modes.json',
     '"branchGroup": {"key": "deltaGroup", "name": "vs and direction"},',
     '', MODULE),
    # ---- the hooks ----
    ('a "vs" naming the field itself accepted', UV,
     "if ($op !== null && $op[0] === 'ref' && $op[1] === $name) {",
     'if (false) {', MODULE),
    ('a shorthand not written out for the field', UV,
     '$frag[\'deltaVs\'] = sprintf(AnnotationRules::DELTA_SHORTHANDS[$short], $name);',
     '$frag[\'deltaVs\'] = $frag[\'deltaVs\'];', MODULE),
    ('the "vs" field\'s comma not marked', UV,
     "if (substr($validation, -strlen('_comma_decimal')) === '_comma_decimal') $frag['deltaVsComma'] = true;",
     '', MODULE),
    ('an event shorthand on a form that repeats alone accepted', UV,
     'if ($repeatsAlone) {',
     'if (false) {', MODULE),
    ('a "vs" field that holds no numbers accepted', UV,
     "if (!in_array($ftype, ['calc', 'slider'], true) && !($ftype === 'text' && is_string($re) && preg_match('~' . $re . '~', $validation))) {",
     'if (false) {', MODULE),
    # ---- the audit ----
    ('an earlier Missing Data Code judged', UV,
     "if (self::isMissingCode($earlier, $this->missingDataCodes($project_id))) $earlier = '';",
     '', MODULE),
    ('a value that is not a number passes silently', UV,
     "            if ($r['verdict'] === 'unknown') {\n                $why = $r['reason'] === 'not-a-number'",
     "            if ($r['verdict'] === 'unknown') continue;\n            if (false) {\n                $why = $r['reason'] === 'not-a-number'", MODULE),
    ('a finding logs no "vs"', UV,
     "if (isset($v['vs'])) $extra['vs'] = (string) $v['vs'];",
     '', MODULE),
    ('an unresolved plain "vs" read as blank', UV,
     "            if ($state !== 'ok') {\n                $out['unconfigurable'][] = ['fields' => $rule['fields'], 'why' => 'the \"vs\" value ' . self::resolutionProblem($state, $op[1])];\n                return $out;\n            }\n",
     '', MODULE),
    ('an unresolved event/instance "vs" read as blank', UV,
     "            if (!is_string($earlier)) {\n                $why = 'the \"vs\" value ' . $vs . ' could not be resolved';",
     "            if (!is_string($earlier)) $earlier = '';\n            if (false) {\n                $why = 'the \"vs\" value ' . $vs . ' could not be resolved';", MODULE),
    # ---- the page ----
    ('a calc and an event/instance rule block', ENGINE,
     'var advisory = cfg.extendedAdvisory === true || cfg.deltaComputed === true || DEFERRED;',
     'var advisory = DEFERRED;', DOM),
    ('a survey told what the value is compared with', ENGINE,
     '(QRID_IS_SURVEY || !V.label ? "" : " (" + V.label + ")")',
     '(!V.label ? "" : " (" + V.label + ")")', DOM),
    ('a withheld earlier value read as blank', ENGINE,
     'if(op[0] === "withheld") return null;\n    var e = op[0] === "ref"',
     'if(op[0] === "withheld") return "";\n    var e = op[0] === "ref"', DOM),
    ('an earlier Missing Data Code judged on the page', ENGINE,
     'return QRID_isMissingCode(e) ? "" : e;',
     'return e;', DOM),
    ('changing the "vs" field re-checks nothing', ENGINE,
     'var WATCH = (vsOp && vsOp[0] === "ref") ? QRID_WHEN.gateFor(null, ["cmp", "=", vsOp, ["lit", ""]]) : null;',
     'var WATCH = null;', DOM),
    ('an earlier value that is not a number says nothing', ENGINE,
     'if(QRID_IS_SURVEY || r.reason === "not-a-number") return { kind: "none" };',
     'return { kind: "none" };', DOM),
    ('the stricter block of two comparisons ignored', ENGINE,
     '        if(one.kind === "bad") worst = "bad";\n        if(one.block && BLOCK_RANK[one.block] > BLOCK_RANK[block]) block = one.block;',
     '        if(one.kind === "bad") worst = "bad";\n        if(one.block && block === "off") block = one.block;', DOM),
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
    with tempfile.TemporaryDirectory(prefix='uv-delta-mutations-') as directory:
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
        sys.exit(f'delta_mutations: {len(survived)} of {len(MUTATIONS)} mutations survived')
    print(f'delta_mutations: {len(MUTATIONS)} mutations, every one killed')


if __name__ == '__main__':
    main()
