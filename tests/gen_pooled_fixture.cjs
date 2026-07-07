/*
 * gen_pooled_fixture.cjs — freeze the browser pooled-parser behavior.
 *
 * Loads the verified js/engine.js, drives its pooled factory (QRIDPooledInit)
 * over a set of representative configs/inputs, and records parse() output as
 * tests/pooled_fixture.json. Both runtimes then recompute these segments and
 * fail on any mismatch (tests/pooled_js.cjs and tests/pooled_php.php), which is
 * what lets the PHP port claim parity with the browser parser.
 *
 * Deterministic: valid members are minted with the engine's own appendCheck, so
 * the fixture never hard-codes a check character. Run:
 *   node tests/gen_pooled_fixture.cjs        # rewrites tests/pooled_fixture.json
 */
'use strict';
const fs = require('fs');
const path = require('path');

global.window = {};
global.document = {
  addEventListener() {},
  getElementsByName() { return []; },
  createElement() { return { style: {}, setAttribute() {}, appendChild() {}, insertBefore() {} }; },
  readyState: 'complete',
  body: { addEventListener() {} },
};
require(path.join(__dirname, '..', 'js', 'engine.js'));
global.window.QRCheck = global.QRCheck;                 // browser has window === globalThis

const Q = global.QRCheck;
const mod37 = 'iso7064_mod37_36';
// makeScheme() computes the .active flag appendCheck needs — a raw literal would
// be treated as inactive and mint no check character.
const MINT = Q.makeScheme({ algorithm: mod37, source: 'normalized_id', placement: 'append', enabled: true,
  normalize_rules: { strip_delimiters: '', uppercase: true, unify_unicode_dashes: true, keep_only: null } });
const app = (id) => Q.appendCheck(id, MINT);

function parseWith(config, input) {
  global.window.QRIDMulti = null;
  window.QRIDPooledInit(Object.assign({ fields: [] }, config));
  return window.QRIDMulti.parse(input);
}

// ---- representative valid members (minted, so they carry real check chars) ----
const A = app('0ABC0000'), B = app('0ABC0001'), C = app('0DEF0009');   // 9-char mod37,36 IDs
// (each is 9 chars: 8-char base + 1 check char)
const cases = [];
function add(label, config, input) { cases.push({ label, config, input, segs: parseWith(config, input) }); }

const checkCfg = { algorithm: mod37, source: 'normalized_id', strip: '-/ _|\\', idLengths: [9] };

add('clean pool of three', checkCfg, A + B + C);
add('pool with trailing junk', checkCfg, A + B + 'ZZ');
add('pool with a duplicate', checkCfg, A + B + A);
add('space/comma separated', checkCfg, A + ', ' + B + ' ' + C);
add('single member only', checkCfg, B);
add('all junk (no member)', checkCfg, 'ZZZZZZZZ');

// check + format pattern: a well-formed-but-wrong-check member surfaces as its own bad chip
const patCfg = { algorithm: mod37, source: 'normalized_id', strip: '-/ _|\\',
                 idPattern: '[0-9A-Z]{9}', idLengths: [9] };
const badB = B.slice(0, -1) + (B.slice(-1) === 'X' ? 'Y' : 'X');   // valid shape, broken check
add('check+pattern with a bad member', patCfg, A + badB + C);

// regex-only legacy project (no check character): shape is the whole test
const regexCfg = { algorithm: 'none', source: 'normalized_id', strip: '-/ _|\\',
                   idPattern: 'FC[0-9]{4}', idMinLen: 6, idMaxLen: 6 };
add('regex-only pool', regexCfg, 'FC0001FC0002XY');

const out = {
  generated_by: 'tests/gen_pooled_fixture.cjs',
  note: 'Frozen browser pooled-parser output; recomputed by pooled_js.cjs and pooled_php.php.',
  cases,
};
fs.writeFileSync(path.join(__dirname, 'pooled_fixture.json'), JSON.stringify(out, null, 2) + '\n');
console.log('wrote tests/pooled_fixture.json with ' + cases.length + ' cases');
for (const c of cases) {
  const ids = c.segs.filter((s) => s.type === 'id');
  console.log('  ' + c.label.padEnd(34) + ' -> ' +
    ids.length + ' id(s), ' + c.segs.filter((s) => s.type === 'junk').length + ' junk run(s)');
}
