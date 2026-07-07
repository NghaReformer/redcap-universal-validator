/*
 * parity_js.cjs — proves the vendored engine.js matches the Python fixture.
 *
 * Loads js/engine.js under a minimal window/document stub and recomputes every
 * row of check_fixture.json across all three sections, not just `compute`:
 *   - compute    : the raw check-character primitive (per algorithm)
 *   - normalize  : Unicode dash folding / case / strip / keep_only
 *   - scheme_ops : append + validate over the FULL runtime path
 *                  (normalize -> source -> compute -> compare)
 * This is what lets the module claim the browser engine still agrees with the
 * Python source of truth after vendoring — for the actual runtime path, not only
 * the primitive.
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
let total = 0, fail = 0;

// ---- 1) compute: raw check-character primitive ----
const byAlgo = {};
for (const r of fx.compute) {
  total++;
  byAlgo[r.name] = (byAlgo[r.name] || 0) + 1;
  const algo = Q.ALGORITHMS[r.name];
  let got;
  try { got = algo ? algo.compute(r.payload) : '<<missing algorithm>>'; }
  catch (e) { got = '<<error: ' + e.message + '>>'; }
  if (got !== r.check) {
    fail++;
    console.error(`compute   MISMATCH ${r.name.padEnd(18)} payload=${String(r.payload).padEnd(14)} expected=${JSON.stringify(r.check)} got=${JSON.stringify(got)}`);
  }
}
console.log('compute algorithms covered:', Object.keys(byAlgo).join(', '));

// ---- 2) normalize: dash folding / case / strip / keep_only ----
for (const r of (fx.normalize || [])) {
  total++;
  let got;
  try { got = Q.normalize(r.value, r.rules); }
  catch (e) { got = '<<error: ' + e.message + '>>'; }
  if (got !== r.result) {
    fail++;
    console.error(`normalize MISMATCH value=${JSON.stringify(r.value)} expected=${JSON.stringify(r.result)} got=${JSON.stringify(got)}`);
  }
}

// ---- 3) scheme_ops: append + validate over the full runtime path ----
function schemeFor(row) {
  // rows carry either a named scheme or a full inline config
  if (row.config) return Q.makeScheme(row.config);
  return row.scheme;                        // string name resolved by getScheme()
}
for (const r of (fx.scheme_ops || [])) {
  total++;
  const scheme = schemeFor(r);
  let got;
  try {
    if (r.op === 'append') got = Q.appendCheck(r.id, scheme);
    else if (r.op === 'validate') got = Q.validateIdCheck(r.id, scheme);
    else got = '<<unknown op: ' + r.op + '>>';
  } catch (e) { got = '<<error: ' + e.message + '>>'; }
  if (got !== r.result) {
    fail++;
    console.error(`scheme_op MISMATCH ${r.op.padEnd(8)} id=${JSON.stringify(r.id)} expected=${JSON.stringify(r.result)} got=${JSON.stringify(got)}`);
  }
}

console.log(`parity_js: ${total} rows checked (compute + normalize + scheme_ops), ${fail} mismatch(es)`);
process.exit(fail === 0 ? 0 : 1);
