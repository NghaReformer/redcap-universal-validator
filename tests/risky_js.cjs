/*
 * risky_js.cjs — locks the browser risky-pattern heuristic.
 *
 * Asserts QRCheck.riskyPattern() rejects the `risky` patterns and passes the
 * `safe` patterns in tests/risky_patterns.json. Paired with tests/risky_php.php
 * (same list, PHP twin) this stops the client and server ReDoS gates from
 * diverging — the P2 finding in the fix-validation review.
 *
 * Run:  node tests/risky_js.cjs
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

const Q = global.window && global.window.INSPIREUniversalValidator;
if (!Q || typeof Q.riskyPattern !== 'function') {
  console.error('engine.js did not expose INSPIREUniversalValidator.riskyPattern');
  process.exit(1);
}

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'risky_patterns.json'), 'utf8'));
let n = 0, fail = 0;
for (const p of fx.risky) {
  n++;
  if (Q.riskyPattern(p) !== true) { fail++; console.error('EXPECTED RISKY but passed: ' + JSON.stringify(p)); }
}
for (const p of fx.safe) {
  n++;
  if (Q.riskyPattern(p) !== false) { fail++; console.error('EXPECTED SAFE but flagged: ' + JSON.stringify(p)); }
}

// ---- pattern-source length cap ----
n++;
if (Q.riskyPattern('A'.repeat(513)) !== true) { fail++; console.error('513-char pattern source was not gated'); }
n++;
if (Q.riskyPattern('A'.repeat(512)) !== false) { fail++; console.error('512-char plain pattern source was wrongly gated'); }

// ---- empirical budget: every pattern the gate PASSES must match adversarial
// input quickly. This is the property the gate exists for — assert it, don't
// assume it. Budget is generous (500ms for the whole safe list) to stay
// CI-noise-free; a catastrophic pattern would blow past it by orders of
// magnitude (the pre-fix (a|aa)+ took >3s on 43 chars).
n++;
{
  const inputs = [
    'a'.repeat(512) + '!',
    'A'.repeat(512) + '!',
    '1'.repeat(512) + '!',
    ('FC' + '0'.repeat(30)).repeat(16),
  ];
  const t0 = Date.now();
  for (const p of fx.safe) {
    const re = new RegExp('^(?:' + p.replace(/^\^/, '').replace(/\$$/, '') + ')$');
    for (const s of inputs) re.test(s);
  }
  const elapsed = Date.now() - t0;
  if (elapsed > 500) { fail++; console.error(`safe patterns exceeded the time budget: ${elapsed}ms`); }
}

console.log(`risky_js: ${n} patterns checked, ${fail} mismatch(es)`);
process.exit(fail === 0 ? 0 : 1);
