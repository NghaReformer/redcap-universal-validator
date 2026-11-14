#!/usr/bin/env python3
"""Mutations of the value index (php/Scan/LookupIndex.php), applied only to a disposable copy.

Each mutation breaks one guarantee the suites claim to hold. The named suite must then fail;
a mutation it survives is a hole in the tests. The anchors are exact text and must occur once.

Usage: PHP_BIN=php python tests/lookup_index_mutations.py [--shard I/N]

The mutations of the SQL (suite tests/mysql/run.php, case "lookup_index") run only when
UV_DB_HOST and the other UV_DB_* variables name a MySQL or MariaDB server; without one they
are reported as skipped, never as killed. --shard I/N runs every Nth mutation from the Ith
(1-based), so two copies can share the work against two servers.
"""
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parent.parent
PHP = os.environ.get('PHP_BIN', 'php')
UV = 'UniversalValidator.php'
LI = 'php/Scan/LookupIndex.php'
MYSQL = 'tests/mysql/run.php'
CRON = 'tests/scan_cron_php.php'
HOOK = 'tests/hook_php.php'
KEYS = 'tests/lookup_key_php.php'

# (name, file, original, replacement, suite)
MUTATIONS = [
    # ---- the keys ----
    ('the key leaves out the project', LI,
     "(string) $secret . '|lookup|' . (int) $pid), 0, 32);", "(string) $secret . '|lookup|0'), 0, 32);", MYSQL),
    ('a composite key leaves out the project', LI,
     "(string) $this->secret . '|lookup|' . (int) $pid), 0, 32);", "(string) $this->secret . '|lookup|0'), 0, 32);", MYSQL),
    ('a composite key is the MD5 of its material', LI,
     r'''return substr(hash_hmac('sha256', (string) $name . "\0" . (string) $material,''',
     "return md5((string) $material); return substr(hash_hmac('sha256', (string) $name,", MYSQL),
    ('a spec of another secret is read', LI,
     'if (count($p) < 2 || $p[0] !== $this->specHead()) return false;', 'if (count($p) < 2) return false;', CRON),
    ("forgetProject leaves the composites' definitions", LI,
     "foreach (['lookup_field', 'lookup_sync', 'lookup_def'] as $t) {", "foreach (['lookup_field', 'lookup_sync'] as $t) {", KEYS),
    # ---- the entries a composite keys ----
    ('the repeat buckets merged', LI,
     "$cells[$evt]['b:' . $def['rep'][$r[0]][$evt]][$i][$r[0]] = $r[1];", "$cells[$evt]['b:'][$i][$r[0]] = $r[1];", MYSQL),
    ("the event's row is no entry", LI, '$entries = [$base];', '$entries = [];', MYSQL),
    ('a field that does not repeat counts on any instance', LI,
     "elseif ($i === 1) $cells[$evt]['-'][1][$r[0]] = $r[1];", "else $cells[$evt]['-'][1][$r[0]] = $r[1];", MYSQL),
    ('a slot of only spaces is keyed', LI,
     "if (!isset($vals[$slot]) || Logic::lookupTrim($vals[$slot]) === '') continue;", 'if (!isset($vals[$slot])) continue;', MYSQL),
    ('event id 0 accepted in a repeat bucket', LI, '(int) $e < 1 || ', '', MYSQL),
    # ---- keeping it current ----
    ("a letter-case twin's rows taken into a batch", LI,
     'if (!isset($plain[$f]) || !isset($want[$rh])) continue;', 'if (!isset($plain[$f])) continue;', MYSQL),
    ('a build paused under another secret goes on past the sync', LI,
     '} elseif ($live || $paused) {', '} elseif ($live) {', MYSQL),
    ('an entry the sync cannot keep goes on answering', LI,
     "$stale[] = (string) $f;", '', MYSQL),
    ("another project's log row read", LI,
     "if ($win['counts'][$last + 1] !== 0) $read($synced, $top);", '$read($synced, $top);', MYSQL),
    ('a build past BUILD_STALE not kept current', LI,
     "$live = $st['ready'] || $st['building'] > 0;", "$live = $st['ready'] || self::building($st, $now);", MYSQL),
    # ---- building ----
    ('a new spec leaves the old build able to finish', LI,
     "                gen = IF(spec = VALUES(spec), gen, gen + 1), building_at = IF(spec = VALUES(spec), building_at, 0),\n", '', MYSQL),
    ('a field to wipe is written before its old keys go', LI,
     "if ($old && $st['wipe'] && !$this->deleteKeys($pid, $field, $gen, $until)) {",
     "if (false && !$this->deleteKeys($pid, $field, $gen, $until)) {", MYSQL),
    ('a field to wipe the cron ran out of time on is written anyway', LI,
     "if ($old && $st['wipe'] && !$this->deleteKeys($pid, $field, $gen, $until)) {",
     "if ($old && $st['wipe'] && !$this->deleteKeys($pid, $field, $gen, $until) && false) {", MYSQL),
    ("a plain build leaves an older generation's keys", LI,
     '        if ($ok && $old) $this->deleteKeys($pid, $field, $gen, $until);\n', '', MYSQL),
    ("a composite build leaves an older generation's keys", LI,
     "        if ($ok && $cursor === '' && $old) $this->deleteKeys($pid, $name, $gen, $until);\n", '', MYSQL),
    ('keys deleted before the field stops answering', LI,
     "        $gen = ($st !== null ? $st['gen'] : 0) + 1;\n        $this->db->execNoCount('INSERT INTO ' . $ft . '",
     "        $gen = ($st !== null ? $st['gen'] : 0) + 1;\n        if (!$keep) $this->deleteKeys($pid, $field, null);\n"
     "        $this->db->execNoCount('INSERT INTO ' . $ft . '", MYSQL),
    ("a composite counted by its first field's rows", LI,
     "$fields = $entry['kind'] === 'plain' ? [$field] : self::compositeFields($entry['def']);",
     "$fields = $entry['kind'] === 'plain' ? [$field] : [$entry['def']['slot']];", MYSQL),
    ('a large field is listed, never walked', LI,
     "$walk = $this->walkOf($pid, $dataTable, $def['slot']);", '$walk = null;', MYSQL),
    ('the walk taken however few records hold the field', LI,
     "return $o['source']->rowsUpTo($most) <= $most ? $o['source'] : null;", "return $o['source'];", MYSQL),
    ('a cursor of no known way resumed as a listing', LI,
     "if ($way !== 'l' && $way !== 'w') $cursor = '';", '', MYSQL),
    ('a resumed walk starts from the first record', LI,
     "$after = $cursor === '' ? null : (string) substr($cursor, 1);", '$after = null;', MYSQL),
    ('a walk cursor resumed as a listing cursor', LI,
     "                else $cursor = '';\n", '', MYSQL),
    ('a walk page that failed ends the build as done', LI,
     "                    if (empty($page['ok'])) {\n                        $cursor = '';\n                        break;\n                    }",
     "                    if (empty($page['ok'])) return [true, ''];", MYSQL),
    ('a walk page that failed fails the build on every run', LI,
     "                    if (empty($page['ok'])) {\n                        $cursor = '';\n                        break;\n                    }",
     "                    if (empty($page['ok'])) return [false, ''];", MYSQL),
    ('a listing that failed ends the build as done', LI,
     "            Faults::swallow($e, 'LookupIndex::writeCompositeKeys');\n            return [false, ''];",
     "            Faults::swallow($e, 'LookupIndex::writeCompositeKeys');\n            return [true, ''];", MYSQL),
    ('one listing only', LI, "if (!$more) return [true, ''];", "return [true, ''];", MYSQL),
    ('the listing asks for no extra id', LI,
     "' ORDER BY r LIMIT ' . ((int) $limit + 1)", "' ORDER BY r LIMIT ' . ((int) $limit)", MYSQL),
    # ---- the cron ----
    ('maintain leaves the entries of another version', LI,
     'OR (building_at > 0 AND building_at < ?) OR LEFT(spec, ?) <> ?', "OR (building_at > 0 AND building_at < ?) OR (? < 0 AND ? IS NULL)", MYSQL),
    ('maintain drops entries without a secret', LI,
     '        if ($this->secret === null) return $out;\n', '', MYSQL),
    ('deletes run past the deadline', LI, 'if ($until > 0 && time() >= $until) return false;', '', MYSQL),
    ('maintain drops a field past its deadline', LI,
     "if ($this->dropField($pid, $field, $until)) $out['dropped']++;", "if ($this->dropField($pid, $field)) $out['dropped']++;", MYSQL),
    ('a field left part way is dropped anyway', LI,
     'if (!$this->deleteKeys($pid, $field, null, $until)) return false;', '$this->deleteKeys($pid, $field, null, $until);', MYSQL),
    ('a dropped entry answers while its keys go', LI,
     "    private function dropField($pid, $field, $until = 0)\n    {\n        $this->db->execNoCount('UPDATE ' . Schema::table('lookup_field') . ' SET ready = 0, building_at = 0, gen = gen + 1\n            WHERE project_id = ? AND field_name = ?', [$pid, $field]);\n",
     "    private function dropField($pid, $field, $until = 0)\n    {\n", MYSQL),
    # ---- the secret ----
    ('the key is not read back after writing', UV,
     'if (is_string($stored) && strlen($stored) >= 64) $key = $stored;', '', HOOK),
    ('the key is not made when the module is enabled', UV,
     "        // The keyed-hash secret, made here outside any request's work (hmacKey()).\n        $this->hmacKey();\n", '', HOOK),
    ('the key is not made when the module is upgraded', UV,
     "        $this->hmacKey();\n        $missing = $this->missingPhpExtensions();\n        if ($missing) $this->logMissingExtensions($missing, 'upgrade');",
     "        $missing = $this->missingPhpExtensions();\n        if ($missing) $this->logMissingExtensions($missing, 'upgrade');", HOOK),
    ('the key is not read again under the lock', UV,
     "                try {\n                    $key = $this->getSystemSetting('log-hmac-key');",
     "                try {\n                    $key = null;", HOOK),
]


def run(suite, cwd):
    """The suite's result, or None for the database suite when no server is named."""
    env = None
    if suite == MYSQL:
        if not os.environ.get('UV_DB_HOST'):
            return None
        cmd = [PHP, '-d', 'extension=mysqli', suite]
        env = dict(os.environ, UV_CASES='lookup_index')
    else:
        cmd = [PHP, suite]
    return subprocess.run(cmd, cwd=cwd, capture_output=True, text=True, encoding='utf-8', errors='replace', env=env)


def main():
    shard, of = 1, 1
    if len(sys.argv) == 3 and sys.argv[1] == '--shard':
        shard, of = (int(x) for x in sys.argv[2].split('/'))
    mine = [m for i, m in enumerate(MUTATIONS) if i % of == shard - 1]
    survived = []
    skipped = []
    with tempfile.TemporaryDirectory(prefix='uv-lookup-mutations-') as directory:
        copy = Path(directory)
        for folder in ['php', 'js', 'tests', 'data', 'docs', 'site']:
            shutil.copytree(ROOT / folder, copy / folder)
        for filename in [UV, 'config.json', 'README.md', 'CHANGELOG.md']:
            shutil.copy2(ROOT / filename, copy / filename)
        # in the copy, so a suite the copy alone breaks stops the run
        for suite in sorted({m[-1] for m in mine}):
            res = run(suite, copy)
            if res is not None and res.returncode != 0:
                sys.exit(f'{suite} fails in the copy before any mutation:\n{res.stdout[-2000:]}{res.stderr[-2000:]}')
        for name, filename, original, replacement, suite in mine:
            path = copy / filename
            source = path.read_text(encoding='utf-8')
            if source.count(original) != 1:
                sys.exit(f'{name}: the anchor must occur once in {filename}, found {source.count(original)}')
            path.write_text(source.replace(original, replacement), encoding='utf-8', newline='')
            subprocess.run([PHP, '-l', str(path)], check=True, stdout=subprocess.DEVNULL)
            result = run(suite, copy)
            path.write_text(source, encoding='utf-8', newline='')
            if result is None:
                skipped.append(name)
                print(f'skipped (no UV_DB_HOST): {name}', flush=True)
            elif result.returncode == 0:
                survived.append(name)
                print(f'SURVIVED: {name} ({suite})', flush=True)
            else:
                print(f'killed: {name}', flush=True)
    if survived:
        sys.exit(f'lookup_index_mutations: {len(survived)} of {len(mine)} mutations survived')
    ran = len(mine) - len(skipped)
    print(f'lookup_index_mutations: {ran} mutations, every one killed' + (f'; {len(skipped)} skipped (no database)' if skipped else ''))


if __name__ == '__main__':
    main()
