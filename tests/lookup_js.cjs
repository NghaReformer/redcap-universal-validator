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

console.log('lookup_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
