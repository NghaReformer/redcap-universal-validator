/*
 * lookup_js.cjs — JS side of the @UVEXISTS / @UVUNIQUE comparison contract.
 *
 * Drives QRID_lookupKey and QRID_sameFlags (reached through
 * INSPIREUniversalValidator.lookupLogic) over every case in
 * tests/lookup_fixture.json. tests/lookup_php.php drives the PHP twins
 * (Logic::lookupKey, TemporalLogic::sameFlags) over the SAME file.
 *
 * Run:  node tests/lookup_js.cjs
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

const L = global.window.INSPIREUniversalValidator && global.window.INSPIREUniversalValidator.lookupLogic;
if (!L || typeof L.key !== 'function') {
  console.error('engine.js did not expose INSPIREUniversalValidator.lookupLogic');
  process.exit(1);
}

let n = 0;
let fail = 0;
function check(label, cond) {
  n++;
  if (!cond) { fail++; console.error('FAIL: ' + label); }
}

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'lookup_fixture.json'), 'utf8'));
if (!fx.keys || !fx.keys.length || !fx.same || !fx.same.length) {
  console.error('lookup_fixture.json is missing or empty');
  process.exit(1);
}

for (const c of fx.keys) {
  const got = L.key(c.in, c.fold, c.mark);
  check('key ' + JSON.stringify([c.in, c.fold, c.mark]) + ' = ' + JSON.stringify(c.key) + ', got ' + JSON.stringify(got), got === c.key);
}
for (const c of fx.same) {
  check('sameFlags ' + c.op, JSON.stringify(L.sameFlags(c.op)) === JSON.stringify(c.flags));
}

check('a number key never equals a text key', L.key('7', true, 'point') !== L.key('7', true, null));
check('the trim keeps a no-break space inside a value', L.trim('a b') === 'a b');

const sp = ' \t'.repeat(10000), nb = '\u00A0'.repeat(10000);
check('the trim takes a long run of space and no-break spaces off each end', L.trim(sp + nb + 'SP-1' + nb + sp) === 'SP-1');
check('the trim keeps a long run of space inside a value', L.trim('A' + sp + 'B') === 'A' + sp + 'B');
check('the trim leaves nothing of a value that is all space', L.trim(sp + nb) === '');
check('the trim keeps an em space, as the server does', L.trim('\u2003x\u2003') === '\u2003x\u2003');
// A long run of zeros inside a typed number is passed over once, as the trim
// passes over space (an index scan, not /0+$/).
{
  const zeros = '0'.repeat(400000);
  let t0 = Date.now();
  check('a number key passes over a long run of zeros in linear time',
    L.number('1.' + zeros + '1', 'point') === '1.' + zeros + '1' && Date.now() - t0 < 200);
  t0 = Date.now();
  check('a number key drops a long run of trailing zeros in linear time',
    L.number('1.5' + zeros, 'point') === '1.5' && Date.now() - t0 < 200);
  const R = global.window.INSPIREUniversalValidator.rangeLogic;
  t0 = Date.now();
  check('a range check passes over a long run of zeros in linear time',
    R.verdict({ hardLo: '1', hardHi: '2' }, '1.' + zeros + '1').tier === 'ok' && Date.now() - t0 < 200);
  t0 = Date.now();
  check('a number with an exponent passes over a long run of zeros in linear time',
    R.plainDecimal('1' + zeros + '1e0') === null && Date.now() - t0 < 200);
}
// No regex: a long run of space inside a value is passed over once, not once
// per position (a saved value like this reaches every page that reads it).
{
  const inner = 'A' + ' '.repeat(400000) + 'B ';
  const t0 = Date.now();
  const out = L.trim(inner);
  check('the trim passes over a long inner run of space in linear time', out === inner.slice(0, -1) && Date.now() - t0 < 200);
  const chars = [' ', '\t', '\r', '\n', '\0', '\v', '\u00a0', 'a', '\u2003', '-'];
  const old = (v) => String(v).replace(/^[ \t\r\n\0\v\u00a0]+|[ \t\r\n\0\v\u00a0]+$/g, '');
  let same = true;
  for (let k = 0; k < 20000 && same; k++) {
    let s = '';
    const len = (k * 7) % 9;
    for (let q = 0; q < len; q++) s += chars[(k * 31 + q * 17 + (k >> 3)) % chars.length];
    same = L.trim(s) === old(s);
  }
  check('the trim agrees with the anchored regex it replaced', same);
}

console.log('lookup_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
