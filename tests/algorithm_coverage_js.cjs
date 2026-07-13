/*
 * algorithm_coverage_js.cjs — completeness cross-check for the algorithm set (JS side).
 *
 * The PHP twin (algorithm_coverage_php.php) binds the fixture, the PHP engine,
 * the server allow-list, and the config dropdown. This test binds the remaining
 * surface — the JavaScript check-character registry that actually runs in the
 * browser — to the same fixture, so an algorithm present in the fixture but not
 * registered in engine.js (or vice versa) fails CI instead of silently going
 * unvalidated in the browser.
 *
 * Run:  node tests/algorithm_coverage_js.cjs
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
const cfg = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'config.json'), 'utf8'));

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

const sorted = (a) => a.slice().sort();
const fixtureAlgos = sorted(fx.algorithms.map((a) => a.name));
const registry = sorted(Object.keys(Q.ALGORITHMS));

let dropdown = [];
for (const s of cfg['project-settings']) {
  if (s.key !== 'rules') continue;
  for (const sub of s.sub_settings) {
    if (sub.key !== 'algorithm') continue;
    dropdown = sub.choices.map((c) => c.value);
  }
}
dropdown = sorted(dropdown);

const eq = (a, b) => a.length === b.length && a.every((x, i) => x === b[i]);

check('fixture set == JS engine registry (Object.keys(ALGORITHMS))', eq(fixtureAlgos, registry));
check('fixture set == config.json algorithm dropdown', eq(fixtureAlgos, dropdown));

// Every registered algorithm carries the metadata the fixture declares, and its
// compute()/explain() are wired (the pooled parser and preparePayload lean on
// checkAlphabet + nCheckChars, not just compute).
const metaByName = Object.fromEntries(fx.algorithms.map((a) => [a.name, a]));
for (const name of fixtureAlgos) {
  const algo = Q.ALGORITHMS[name];
  const meta = metaByName[name];
  check('registry has ' + name, !!algo);
  if (!algo) continue;
  check('nCheckChars matches fixture for ' + name, algo.nCheckChars === meta.n_check_chars);
  check('checkAlphabet matches fixture for ' + name, algo.checkAlphabet === meta.check_alphabet);
  check('compute is a function for ' + name, typeof algo.compute === 'function');
}

console.log('algorithm_coverage_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail === 0 ? 0 : 1);
