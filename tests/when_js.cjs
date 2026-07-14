/*
 * when_js.cjs — JS side of the "when" condition parity contract.
 *
 * Drives the QRID_when* twins in js/engine.js (exposed as
 * INSPIREUniversalValidator.whenLogic) over every case in
 * tests/when_fixture.json: parse ok/error, evaluate() verdicts, and
 * referencedFields() output. tests/when_php.php drives php/Logic.php (the
 * normative dialect spec) over the SAME fixture, so the two runtimes cannot
 * drift.
 *
 * Run:  node tests/when_js.cjs
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

const NS = global.window && global.window.INSPIREUniversalValidator;
const W = NS && NS.whenLogic;
if (!W || typeof W.parse !== 'function' || typeof W.evaluate !== 'function'
    || typeof W.referencedFields !== 'function') {
  console.error('engine.js did not expose INSPIREUniversalValidator.whenLogic');
  process.exit(1);
}

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'when_fixture.json'), 'utf8'));
let n = 0, fail = 0;
function check(label, cond) {
  n++;
  if (!cond) { fail++; console.error('FAIL: ' + label); }
}

// ---- caps: the engine's numbers ARE the fixture's numbers ----
check('caps maxLen', W.caps.maxLen === fx.caps.maxLen);
check('caps maxRefs', W.caps.maxRefs === fx.caps.maxRefs);
check('caps maxDepth', W.caps.maxDepth === fx.caps.maxDepth);

// ---- eval: parse must succeed, evaluate must match ----
for (const c of fx.eval) {
  const r = W.parse(c.expr);
  check('eval parses: ' + c.name, !!r.ok);
  if (r.ok) {
    check('eval verdict: ' + c.name, W.evaluate(r.ast, c.values || {}) === c.expect);
  }
}

// ---- errors: parse must fail with the expected substring ----
for (const c of fx.errors) {
  const r = W.parse(c.expr);
  const ok = !r.ok && typeof r.error === 'string'
    && r.error.toLowerCase().indexOf(c.errorContains.toLowerCase()) !== -1;
  if (!ok && r && r.error) console.error('  got error: ' + r.error);
  check('error: ' + c.name, ok);
}

// ---- refs: referencedFields output locked (order + dedupe + lowercase) ----
for (const c of fx.refs) {
  const r = W.parse(c.expr);
  check('refs parses: ' + c.name, !!r.ok);
  if (r.ok) {
    check('refs list: ' + c.name,
      JSON.stringify(W.referencedFields(r.ast)) === JSON.stringify(c.expect));
  }
}

// ---- non-string / hostile inputs never throw ----
for (const weird of [null, undefined, 7, 1.5, true, ['x'], {}, "[a]='1'"]) {
  let r = null, threw = false;
  try { r = W.parse(weird); } catch (e) { threw = true; }
  check('parse total on ' + Object.prototype.toString.call(weird),
    !threw && r && typeof r.ok === 'boolean');
}

console.log(`when_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
