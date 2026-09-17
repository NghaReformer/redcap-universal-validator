#!/usr/bin/env python3
"""Four safety mutations, applied only to disposable copies. Usage: PHP_BIN=php python3 tests/temporal_mutations.py."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parent.parent
PHP = os.environ.get('PHP_BIN', 'php')
MUTATIONS = [
    ('address collision', 'php/Logic.php',
     "        return ($ref[3] === null ? '' : '[' . $ref[3] . ']') . '[' . $ref[1]",
     "        return $ref[1];\n        return ($ref[3] === null ? '' : '[' . $ref[3] . ']') . '[' . $ref[1]",
     'tests/qualified_php.php'),
    ('unauthorized literal', 'php/TemporalRules.php', "if($browser&&$mayRead&&!$mayRead($loc['instrument']))$denied=true;", "if($browser&&$mayRead&&!$mayRead($loc['instrument']))$denied=false;",
     'tests/temporal_integration_php.php'),
    ('absent as blank', 'php/AddressResolver.php', "return ['state'=>$state];",
     "return ['state'=>$state==='absent'?'ok':$state,'value'=>''];", 'tests/qualified_php.php'),
    ('self counted twice', 'php/AddressResolver.php', '$ids = array_keys($ids); sort($ids, SORT_NUMERIC);',
     '$ids = array_keys($ids); if ($context !== null) $ids[]=(int)$context[\'instance\']; sort($ids, SORT_NUMERIC);',
     'tests/qualified_php.php'),
]
for suite in sorted({m[-1] for m in MUTATIONS}):
    subprocess.run([PHP, suite], cwd=ROOT, check=True)
with tempfile.TemporaryDirectory(prefix='uv-temporal-mutations-') as directory:
    copy = Path(directory)
    for folder in ['php', 'js', 'tests']:
        shutil.copytree(ROOT / folder, copy / folder)
    for filename in ['UniversalValidator.php', 'config.json']:
        shutil.copy2(ROOT / filename, copy / filename)
    for name, filename, original, replacement, suite in MUTATIONS:
        path = copy / filename
        source = path.read_text()
        if source.count(original) != 1:
            raise RuntimeError(f'{name}: mutation anchor must occur once')
        path.write_text(source.replace(original, replacement))
        subprocess.run([PHP, '-l', str(path)], check=True, stdout=subprocess.DEVNULL)
        result = subprocess.run([PHP, suite], cwd=copy, capture_output=True, text=True)
        path.write_text(source)
        if result.returncode == 0:
            raise RuntimeError(f'SURVIVED: {name}')
        print(f'Killed: {name}')
print('temporal_mutations: 4 safety mutations detected')
