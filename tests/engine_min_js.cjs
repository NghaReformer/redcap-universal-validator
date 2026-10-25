/*
 * tests/engine_min_js.cjs - js/engine.min.js is the build of js/engine.js and
 * behaves as it does (PRF-7).
 *
 * A form loads the minified copy (UniversalValidator::engineUrl) while its
 * first line names the CRC-32 of the current js/engine.js. This checks that
 * line against the source (its SHA-256 and CRC-32), then runs every browser
 * suite again with the minified copy loaded in place of the source
 * (tests/use_engine_min.cjs), and fails when any of them fails. A suite that
 * reads the source as text, rather than loading it, is not counted.
 *
 * tools/build_engine_min.cjs writes the file; CI also checks it is exactly
 * what the pinned terser makes of the source.
 *
 * Run:  node tests/engine_min_js.cjs
 */
'use strict';
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { spawnSync } = require('child_process');

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

const root = path.join(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'js', 'engine.js'));
const minFile = path.join(root, 'js', 'engine.min.js');
const min = fs.existsSync(minFile) ? fs.readFileSync(minFile, 'utf8') : '';
const head = min.split('\n', 1)[0];
let crc = 0xffffffff;
for (const byte of source) {
  crc ^= byte;
  for (let k = 0; k < 8; k++) crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1));
}
crc = ((crc ^ 0xffffffff) >>> 0).toString(16).padStart(8, '0');
const sha = crypto.createHash('sha256').update(source).digest('hex');
check('js/engine.min.js names the current js/engine.js (sha256 ' + sha + ', crc32b ' + crc + '); rebuild it with node tools/build_engine_min.cjs',
  head.indexOf(' source sha256 ' + sha + ' crc32b ' + crc + '. ') > 0);
check('js/engine.min.js is smaller than the source', min.length > 0 && min.length < source.length / 2);

// every browser suite, except the generators and this one
const skip = new Set(['engine_min_js.cjs', 'use_engine_min.cjs']);
const suites = fs.readdirSync(__dirname).filter((f) => /\.cjs$/.test(f) && !/^gen_/.test(f) && !skip.has(f)).sort();
const loaded = [];
for (const f of suites) {
  const r = spawnSync(process.execPath, ['-r', path.join(__dirname, 'use_engine_min.cjs'), path.join(__dirname, f)],
    { cwd: root, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  if (!/UV_ENGINE_MIN_LOADED/.test(r.stderr || '')) continue;
  loaded.push(f);
  const last = (r.stdout || '').trim().split('\n').pop();
  check(f + ' passes with js/engine.min.js (exit ' + r.status + ': ' + last + ')', r.status === 0);
}
check('the browser suites loaded the minified engine (' + loaded.length + ' of ' + suites.length + ')', loaded.length >= 30);

console.log('engine_min_js: ' + n + ' checks, ' + fail + ' failure(s); ' + loaded.length + ' suites ran on js/engine.min.js');
process.exit(fail ? 1 : 0);
