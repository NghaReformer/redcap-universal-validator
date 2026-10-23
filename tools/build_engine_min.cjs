#!/usr/bin/env node
/*
 * tools/build_engine_min.cjs - write js/engine.min.js, the minified copy of
 * js/engine.js a form loads (PRF-7).
 *
 * js/engine.js stays the source and the copy the test suites read; this file
 * is derived from it and is served in its place only while its first line
 * names the source it was built from (UniversalValidator::engineUrl): the
 * source's SHA-256, which CI checks, and its CRC-32, which the server reads on
 * each page because it costs a tenth as much. An engine.js edited on a server
 * without a rebuild is then served as it is, never a stale minified copy.
 *
 * terser is pinned, so the same source always gives the same bytes. It runs
 * through npx, which fetches it the first time.
 *
 * Usage: node tools/build_engine_min.cjs [--check]
 *   --check  write nothing; exit 1 when js/engine.min.js is not what this would write
 */
'use strict';
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const zlib = require('zlib');
const { execSync } = require('child_process');

const TERSER = 'terser@5.36.0';
const root = path.join(__dirname, '..');
const src = path.join(root, 'js', 'engine.js');
const out = path.join(root, 'js', 'engine.min.js');
const check = process.argv.includes('--check');

const source = fs.readFileSync(src);
const sha = crypto.createHash('sha256').update(source).digest('hex');
// CRC-32 as PHP's hash('crc32b') and zlib compute it; zlib.crc32() needs Node 20.15
let crc = 0xffffffff;
for (const byte of source) {
  crc ^= byte;
  for (let k = 0; k < 8; k++) crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1));
}
crc = ((crc ^ 0xffffffff) >>> 0).toString(16).padStart(8, '0');

// Mangled local names, no comments; the syntax stays what the source uses.
// One command line of constants and this checkout's own path.
const min = execSync('npx --yes ' + TERSER + ' "' + src + '" --compress --mangle --comments false',
  { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
const header = '/*! engine.min.js: js/engine.js minified by ' + TERSER + ' (tools/build_engine_min.cjs); source sha256 '
  + sha + ' crc32b ' + crc + '. Read js/engine.js and js/README.md for the source and its provenance. */\n';
const text = header + min.replace(/\s+$/, '') + '\n';

if (check) {
  const have = fs.existsSync(out) ? fs.readFileSync(out, 'utf8') : null;
  if (have !== text) {
    console.error(have === null ? 'js/engine.min.js is missing.' : 'js/engine.min.js is not the build of the current js/engine.js.');
    console.error('Run node tools/build_engine_min.cjs and commit js/engine.min.js.');
    process.exit(1);
  }
  console.log('js/engine.min.js is the build of js/engine.js (' + text.length + ' bytes)');
  process.exit(0);
}
fs.writeFileSync(out, text);
console.log('wrote js/engine.min.js: ' + source.length + ' bytes to ' + text.length
  + ' (gzip ' + zlib.gzipSync(source).length + ' to ' + zlib.gzipSync(text).length + ')');
