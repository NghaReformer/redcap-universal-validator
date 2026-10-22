#!/usr/bin/env python3
"""Mutations of @UVWRITEONCE, applied only to a disposable copy.

Each mutation breaks one guarantee the suites claim to hold. The named suite must then fail;
a mutation it survives is a hole in the tests. The anchors are exact text and must occur once.

Usage: PHP_BIN=php python tests/writeonce_mutations.py
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
HISTORY = 'php/ValueHistory.php'
MODEREG = 'php/ModeRegistry.php'
ENGINE = 'js/engine.js'
VERDICT = 'tests/writeonce_php.php'
MODULE = 'tests/writeonce_module_php.php'
DOM = 'tests/writeonce_dom_js.cjs'
ANNOTATION = 'tests/annotation_php.php'

# (name, file, original, replacement, suite)
MUTATIONS = [
    # ---- the verdict ----
    ('a change by anyone moves the lock', LOGIC,
     '            if ($role) {\n                $lock = $empty($v) ? null : $v;',
     '            if (true) {\n                $lock = $empty($v) ? null : $v;', VERDICT),
    ('roles that cannot be read read as "no role"', LOGIC,
     "            if ($role === null) return ['verdict' => 'unknown', 'why' => 'roles'];\n", '', VERDICT),
    ('a blank saved by a named role keeps the lock', LOGIC,
     '                $lock = $empty($v) ? null : $v;', '                $lock = $v;', VERDICT),
    ('a blank first save locks', LOGIC,
     '                if (!$empty($v)) {\n                    $lock = $v;\n                    $lockedAt = $saves[$i][\'at\'];',
     '                if (true) {\n                    $lock = $v;\n                    $lockedAt = $saves[$i][\'at\'];', VERDICT),
    ('a value the log never shows saved passes', LOGIC,
     "        if (!$same($last, $current)) return ['verdict' => 'unknown', 'why' => 'gap'];\n", '', VERDICT),
    ('a save the log does not spell out read as a value', LOGIC,
     "            if ($v === null) return ['verdict' => 'unknown', 'why' => 'ambiguous'];",
     "            if (false) return ['verdict' => 'unknown', 'why' => 'ambiguous'];", VERDICT),
    ('"since" counts the saves after the date as before it', LOGIC,
     "strcmp((string) $saves[$i]['ts'], (string) $sinceTs) < 0", "strcmp((string) $saves[$i]['ts'], (string) $sinceTs) > 0", VERDICT),
    ('"since" says the lock started at the last save before it', LOGIC,
     '                    $lockedAt = $sinceLabel;', '                    $lockedAt = $lastAt;', VERDICT),
    ('a cleared value called changed', LOGIC,
     "return ['verdict' => $empty($current) ? 'cleared' : 'changed',", "return ['verdict' => 'changed',", VERDICT),
    ('a quote the log escaped read as a change', LOGIC,
     "return $l === $v || strtr($l, [\"\\\\'\" => \"'\", '\\\\\\\\' => '\\\\']) === $v;", 'return $l === $v;', VERDICT),
    ('the roles asked for a save of the locked value', LOGIC,
     '            if ($norm($v) === $norm($lock)) continue;   // both as the log writes them\n', '', VERDICT),
    # ---- the log reader ----
    ('a DELETE does not end the history', HISTORY,
     "if (isset($row[6]) && (string) $row[6] === 'DELETE') return 'read';", "if (false) return 'read';", VERDICT),
    ('another record of the same name in the collation read', HISTORY,
     'if (!isset($row[4]) || (string) $row[4] !== $record) continue;', 'if (!isset($row[4])) continue;', VERDICT),
    ('a log past the cap read in part', HISTORY,
     '            if ($rows >= self::MAX_ROWS_PER_RECORD) {', '            if (false) {', VERDICT),
    ('a record with no rows called read', HISTORY,
     "return $any ? 'read' : 'none';", "return 'read';", VERDICT),
    ('a failed read called "no rows"', HISTORY,
     "            } catch (\\Throwable $e) {\n                $this->saves[$record] = [];\n                return 'unknown';",
     "            } catch (\\Throwable $e) {\n                $this->saves[$record] = [];\n                return 'none';", VERDICT),
    ('a field not named when the reader was made read as no saves', HISTORY,
     "        if (!isset($this->fields[$field])) return ['state' => 'unknown', 'saves' => []];\n", '', VERDICT),
    ('saves newest first', HISTORY,
     'for ($i = count($rows) - 1; $i >= 0; $i--) {', 'for ($i = 0; $i < count($rows); $i++) {', VERDICT),
    ('every record held in memory', HISTORY,
     '        if (count($this->kept) <= self::RECORDS_KEPT) return;', '        return;', VERDICT),
    ('save times left in the log zone', HISTORY,
     "return $d->setTimezone($this->clockZone)->format('Y-m-d H:i:s');", "return $d->format('Y-m-d H:i:s');", VERDICT),
    # ---- the page hook ----
    ('roles that cannot be read lock a rule that names roles', UV,
     'if ($this->userHoldsRole($pid, $user, $roles) !== false) continue;', 'if ($this->userHoldsRole($pid, $user, $roles) === true) continue;', MODULE),
    ('a survey respondent given the roles of the login', UV,
     "            if ($roles && $context !== 'survey') {", '            if ($roles) {', MODULE),
    ('a Missing Data Code locked on the page', UV,
     "|| trim((string) $v, \" \\t\\r\\n\") === '' || self::isMissingCode($v, $codes)) continue;",
     "|| trim((string) $v, \" \\t\\r\\n\") === '') continue;", MODULE),
    ('a blank value locked on the page', UV,
     "|| trim((string) $v, \" \\t\\r\\n\") === '' || self::isMissingCode($v, $codes)) continue;",
     '|| self::isMissingCode($v, $codes)) continue;', MODULE),
    ('the roles sent to the page', MODEREG,
     "$drop = $survey ? array_merge(self::serverKeys(), self::staffOnlyKeys(), self::SURVEY_DROP) : self::serverKeys();",
     "$drop = $survey ? array_merge(self::staffOnlyKeys(), self::SURVEY_DROP) : [];", MODULE),
    # ---- the audit ----
    ('a log past the cap judged as no saves', UV,
     "            if ($h['state'] === 'capped' || $h['state'] === 'unknown') {", "            if ($h['state'] === 'unknown') {", MODULE),
    ('a "since" the reader cannot place ignored', UV,
     '            if ($since !== null && $sinceTs === null) {', '            if (false) {', MODULE),
    ('the lock times left out of the log', UV,
     "                if (isset($to['log'], $v[$fk]) && is_scalar($v[$fk])) $extra[$to['log']] = (string) $v[$fk];",
     '                if (false) $extra[$to[\'log\']] = (string) $v[$fk];', MODULE),
    ('the lock day left out of the scan', UV,
     "if (isset($to['scan'], $v[$fk]) && is_scalar($v[$fk]) && !isset($out[$to['scan']])) $out[$to['scan']] = (string) $v[$fk];",
     "if (false) $out[$to['scan']] = (string) $v[$fk];", MODULE),
    ('the lock time cut to the day', UV,
     "if (is_string($r['lockedAt'])) $finding['lockedAt'] = substr($r['lockedAt'], 0, 16);",
     "if (is_string($r['lockedAt'])) $finding['lockedAt'] = substr($r['lockedAt'], 0, 10);", MODULE),
    ('a log that cannot be read judged anyway', UV,
     "            if ($history === false) {\n                $out['unconfigurable'][] = ['fields' => [$field], 'why' => 'the project log could not be read — field not checked'];\n                continue;\n            }\n",
     '', MODULE),
    # ---- the hooks ----
    ('a lock on a returned field accepted', UV,
     "                if (!isset($owner[$f]) || $owner[$f] === $i) { $keep[] = $f; continue; }",
     '                { $keep[] = $f; continue; }', MODULE),
    ('a refused lock takes its whole group down', UV,
     '            if ($keep) {\n                $rules[$i][\'fields\'] = $keep;', '            if (false) {\n                $rules[$i][\'fields\'] = $keep;', MODULE),
    ('@SETVALUE locked', UV,
     "        if (preg_match('/@SETVALUE\\b/i', $ann)) {", '        if (false) {', MODULE),
    ('@CALCTEXT locked', UV,
     "        if (preg_match('/@(CALCTEXT|CALCDATE)\\b/i', $ann, $m)) {",
     "        if (false && preg_match('/@(CALCTEXT|CALCDATE)\\b/i', $ann, $m)) {", MODULE),
    ('a role named in other letters refused', UV,
     "foreach ($roles as $role) if (!isset($book['roles'][strtolower(trim((string) $role))])) $unknown[] = (string) $role;",
     "foreach ($roles as $role) if (!isset($book['roles'][trim((string) $role)])) $unknown[] = (string) $role;", MODULE),
    ('roles that cannot be read refuse every role', UV,
     '        if ($book === null) return $frag;\n        $unknown = [];', "        if ($book === null) $book = ['roles' => []];\n        $unknown = [];", MODULE),
    ('a role held in other letters not matched', UV,
     "if (in_array(strtolower(trim((string) $role)), $book['users'][$u], true)) return true;",
     "if (in_array(trim((string) $role), $book['users'][$u], true)) return true;", MODULE),
    # ---- the grammar ----
    ('"caseSensitive" taken by a tag with no condition', RULES,
     "foreach (['when' => '\"when\"', 'caseSensitive' => '\"caseSensitive\"', 'blockSave' => '\"blockSave\"'] as $k => $label) {",
     "foreach (['when' => '\"when\"', 'blockSave' => '\"blockSave\"'] as $k => $label) {", ANNOTATION),
    ('a "since" that is not a day of the calendar taken', RULES,
     "&& checkdate((int) $m[2], (int) $m[3], (int) $m[1]);", ';', MODULE),
    ('more than 20 roles taken', RULES,
     '            } elseif (count($roles) > self::MAX_WRITEONCE_ROLES) {', '            } elseif (false) {', MODULE),
    ('a mode with no condition read as taking one', MODEREG,
     "return !(is_array($m) && array_key_exists('when', $m) && $m['when'] === false);", 'return true;', ANNOTATION),
    # ---- the page ----
    ("another mode's item on a locked field holds the save", ENGINE,
     'var live = UV_guard.items.filter(function(el){ return el.__qridLock === true || UV_LOCKED[el.__qridFieldName] !== true; });',
     'var live = UV_guard.items;', DOM),
    ("the lock's own item counted as another mode's", ENGINE,
     'var GITEM = { __qridInvalid: false, __qridBlockMode: "off", __qridLock: true,',
     'var GITEM = { __qridInvalid: false, __qridBlockMode: "off",', DOM),
    ('a value that cannot be put back lets the save through', ENGINE,
     '      try { QRID_writeReturned(fieldName, saved, true, true); } catch(e){}\n      if(el.value !== saved) return false;\n',
     '      try { QRID_writeReturned(fieldName, saved, true, true); } catch(e){}\n', DOM),
    ('a text box left editable', ENGINE,
     '    } else {\n      el.readOnly = true;\n    }', '    } else {\n    }', DOM),
    ('the radio buttons left enabled', ENGINE,
     '      for(var i = 0; i < radios.length; i++) radios[i].disabled = true;\n', '', DOM),
    ('a dropdown disabled (REDCap would save it blank)', ENGINE,
     '      if(el.style) el.style.pointerEvents = "none";', '      el.disabled = true;', DOM),
    ("REDCap's radio reset link left on the page", ENGINE,
     'if(oc.indexOf("radioResetVal") >= 0) links[li].style.display = "none";', '', DOM),
    ('a change no event announces left in place', ENGINE,
     '    if(selfWatch) selfWatch.onChange(function(){ restore(); });', '', DOM),
    ('the slider left enabled', ENGINE,
     'if(sl.length && sl.slider) sl.slider("disable");', '', DOM),
    ('the date picker left enabled', ENGINE,
     'dp.datepicker("disable");', '', DOM),
    ('the rule message written into the page unescaped', ENGINE,
     '(QRID_ruleHtml(V, "message") || QRID_escapeHtml(NOTE))', '(V.message || QRID_escapeHtml(NOTE))', DOM),
    ('an undone change not said', ENGINE,
     '      undone = true;\n      show();', '      show();', DOM),
    ('test() answers true for a changed value', ENGINE,
     'return el ? el.value === SAVED[f] : null;', 'return el ? true : null;', DOM),
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
    with tempfile.TemporaryDirectory(prefix='uv-writeonce-mutations-') as directory:
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
        sys.exit(f'writeonce_mutations: {len(survived)} of {len(MUTATIONS)} mutations survived')
    print(f'writeonce_mutations: {len(MUTATIONS)} mutations, every one killed')


if __name__ == '__main__':
    main()
