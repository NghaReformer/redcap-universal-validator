/*
 * explain_js.cjs — the vendored derivation tracers cannot drift from compute().
 *
 * Every algorithm object in js/engine.js carries an `explain(payload)` method
 * that re-derives the check character step by step for the on-screen "how was
 * this computed" panel. The tracers re-implement the math independently of
 * compute(), so a vendoring mistake (wrong weight order, wrong anchor
 * direction) would show users a derivation that disagrees with the verdict.
 * The QR playground guards this with an explain-vs-compute self-test group;
 * this file ports that guard here (2026-07-13 adversarial review): for every
 * fixture compute row, explain(payload).check must equal the Python-anchored
 * check — and therefore equal compute(payload), which parity_js.cjs already
 * pins to the same rows.
 *
 * Run:  node tests/explain_js.cjs
 */
'use strict';
const fs = require('fs');
const path = require('path');

// Minimal browser stubs (same as parity_js.cjs).
global.window = {};
global.document = {
  addEventListener() {},
  getElementsByName() { return []; },
  createElement() { return { style: {}, setAttribute() {}, appendChild() {}, insertBefore() {} }; },
  readyState: 'complete',
  body: { addEventListener() {} },
};

require(path.join(__dirname, '..', 'js', 'engine.js'));
const NS = global.window && global.window.INSPIREUniversalValidator;
const Q = NS && NS.engine;
if (!Q || !Q.ALGORITHMS) {
  console.error('engine.js did not expose INSPIREUniversalValidator.engine');
  process.exit(1);
}

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'check_fixture.json'), 'utf8'));
let total = 0, fail = 0;

for (const r of fx.compute) {
  total++;
  const algo = Q.ALGORITHMS[r.name];
  if (!algo) { fail++; console.error('FAIL: <<missing algorithm>> ' + r.name); continue; }
  if (typeof algo.explain !== 'function') {
    fail++; console.error('FAIL: ' + r.name + ' has no explain()'); continue;
  }
  let got;
  try { got = algo.explain(r.payload).check; }
  catch (e) { got = '<<THREW: ' + e.message + '>>'; }
  if (got !== r.check) {
    fail++;
    console.error('FAIL: ' + r.name + '.explain(' + JSON.stringify(r.payload) + ').check => '
      + JSON.stringify(got) + ' expected ' + JSON.stringify(r.check));
  }
}

console.log('explain_js: ' + total + ' rows checked, ' + fail + ' mismatch(es)');
process.exit(fail === 0 ? 0 : 1);
