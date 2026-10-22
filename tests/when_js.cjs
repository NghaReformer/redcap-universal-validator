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
  /* JSON has no comments, and a fixture whose rows nobody can annotate is a
     fixture nobody maintains. A row carrying only "_comment" is prose. */
  if (c.expr === undefined) continue;
  const r = W.parse(c.expr);
  check('eval parses: ' + c.name, !!r.ok);
  if (r.ok) {
    /* EVERY case runs in BOTH roles — twin of tests/when_php.php. The
       blank-operand polarity (CRIT-01) is the one thing about this dialect that
       differs between an @UVASSERT test and a "when" gate, and pinning only the
       default is how the defect shipped. 'expectGate' defaults to 'expect'. */
    /* 'expect'/'expectGate' are the EXACT verdicts; the Ci twins are the module
       default. Defaults resolve exactly as in tests/when_php.php. */
    const has = (k) => Object.prototype.hasOwnProperty.call(c, k);
    const nums = c.numbers || undefined;
    check('eval verdict (assert): ' + c.name,
      W.evaluate(r.ast, c.values || {}, W.BLANK_PASSES, true, nums) === c.expect);
    const wantGate = has('expectGate') ? c.expectGate : c.expect;
    check('eval verdict (gate): ' + c.name,
      W.evaluate(r.ast, c.values || {}, W.BLANK_INERT, true, nums) === wantGate);
    const wantCi = has('expectCi') ? c.expectCi : c.expect;
    check('eval verdict (assert, case-insensitive): ' + c.name,
      W.evaluate(r.ast, c.values || {}, W.BLANK_PASSES, false, nums) === wantCi);
    const wantGateCi = has('expectGateCi') ? c.expectGateCi : (has('expectGate') ? c.expectGate : wantCi);
    check('eval verdict (gate, case-insensitive): ' + c.name,
      W.evaluate(r.ast, c.values || {}, W.BLANK_INERT, false, nums) === wantGateCi);
    /* 'expectExact': the test under "exact" (AST-2), defaulting to 'expect'. */
    const wantExact = has('expectExact') ? c.expectExact : c.expect;
    check('eval verdict (assert, exact): ' + c.name,
      W.evaluate(r.ast, c.values || {}, W.BLANK_PASSES, W.EXACT, nums) === wantExact);
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

// ---- astEval: prebuilt ASTs, incl. the server-folded ['const',bool] node ----
for (const c of fx.astEval) {
  check('astEval verdict (assert): ' + c.name,
    W.evaluate(c.ast, c.values || {}, W.BLANK_PASSES, true) === c.expect);
  const wantGate = Object.prototype.hasOwnProperty.call(c, 'expectGate') ? c.expectGate : c.expect;
  check('astEval verdict (gate): ' + c.name,
    W.evaluate(c.ast, c.values || {}, W.BLANK_INERT, true) === wantGate);
}
for (const c of fx.astRefs) {
  check('astRefs list: ' + c.name,
    JSON.stringify(W.referencedFields(c.ast)) === JSON.stringify(c.expect));
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

// ---- a long run of spaces is trimmed in linear time ----
// /^ +| +$/ retried " +$" from every space of a run inside the condition:
// 100,000 spaces there took 4.5 seconds.
{
  let t0 = Date.now();
  let r = W.parse(' '.repeat(100000) + "[a]='1'" + ' '.repeat(100000));
  check('trim: 200,000 spaces around a condition parse in well under a second',
    r.ok === true && Date.now() - t0 < 500);
  t0 = Date.now();
  r = W.parse("[a]='1'" + ' '.repeat(100000) + "and [b]='2'");
  check('trim: 100,000 spaces inside a condition are refused for length in well under a second',
    r.ok === false && /limited to 500 characters/.test(r.error) && Date.now() - t0 < 500);
  check('trim: spaces only is the empty-condition error', W.parse('     ').error === 'must be a non-empty condition string.');
}

console.log(`when_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
