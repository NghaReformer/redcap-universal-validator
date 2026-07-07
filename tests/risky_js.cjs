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

const Q = global.QRCheck;
if (!Q || typeof Q.riskyPattern !== 'function') {
  console.error('engine.js did not expose QRCheck.riskyPattern');
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
console.log(`risky_js: ${n} patterns checked, ${fail} mismatch(es)`);
process.exit(fail === 0 ? 0 : 1);
