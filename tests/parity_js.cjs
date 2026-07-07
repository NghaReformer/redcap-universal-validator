/*
 * parity_js.cjs — proves the vendored engine.js matches the Python fixture.
 *
 * Loads js/engine.js under a minimal window/document stub, reaches its exposed
 * algorithm registry (window.QRCheck.ALGORITHMS), and recomputes every row of
 * check_fixture.json. Fails on any mismatch. This is what lets the module claim
 * the browser engine still agrees with the Python source of truth after
 * vendoring.
 *
 * Run:  node tests/parity_js.cjs
 */
'use strict';
const fs = require('fs');
const path = require('path');

// Minimal browser stubs — engine.js sets window.* at load and, with an empty
// config, its dispatcher attaches no fields (so document is never really used).
global.window = {};
global.document = {
  addEventListener() {},
  getElementsByName() { return []; },
  createElement() { return { style: {}, setAttribute() {}, appendChild() {}, insertBefore() {} }; },
  readyState: 'complete',
  body: { addEventListener() {} },
};

require(path.join(__dirname, '..', 'js', 'engine.js'));

// The engine assigns to G = globalThis (which is `window` in a browser). In Node
// that is `global`, so read it there.
const Q = global.QRCheck || (global.window && global.window.QRCheck);
if (!Q || !Q.ALGORITHMS) {
  console.error('engine.js did not expose QRCheck.ALGORITHMS');
  process.exit(1);
}

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'check_fixture.json'), 'utf8'));
let n = 0, fail = 0;
const byAlgo = {};

for (const r of fx.compute) {
  n++;
  byAlgo[r.name] = (byAlgo[r.name] || 0) + 1;
  const algo = Q.ALGORITHMS[r.name];
  let got;
  try {
    got = algo ? algo.compute(r.payload) : '<<missing algorithm>>';
  } catch (e) {
    got = '<<error: ' + e.message + '>>';
  }
  if (got !== r.check) {
    fail++;
    console.error(`MISMATCH  ${r.name.padEnd(18)} payload=${String(r.payload).padEnd(14)} expected=${JSON.stringify(r.check)} got=${JSON.stringify(got)}`);
  }
}

console.log('algorithms covered:', Object.keys(byAlgo).join(', '));
console.log(`parity_js: ${n} rows checked, ${fail} mismatch(es)`);
process.exit(fail === 0 ? 0 : 1);
