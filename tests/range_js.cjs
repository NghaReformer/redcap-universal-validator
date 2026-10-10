'use strict';
/*
 * range_js.cjs — the @UVRANGE verdict (browser side).
 *
 * Runs every case of tests/range_fixture.json through QRID_rangeVerdict;
 * tests/range_php.php runs the same cases through Logic::rangeVerdict, so the
 * two runtimes cannot disagree about a number.
 *
 * Run:  node tests/range_js.cjs
 */
const fs = require('fs');
const path = require('path');

global.window = {};
global.document = { addEventListener() {}, getElementsByName() { return []; }, readyState: 'complete', body: { addEventListener() {} } };
require('../js/engine.js');
const R = window.INSPIREUniversalValidator.rangeLogic;

let n = 0, fails = 0;
function check(label, ok) { n++; if (!ok) { fails++; console.log('FAIL: ' + label); } }

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'range_fixture.json'), 'utf8'));
check('fixture loads', Array.isArray(fx.cases) && fx.cases.length > 30);
for (const c of fx.cases) {
  const r = R.verdict(c.spec, c.value);
  check(c.name + ' (' + JSON.stringify(c.value) + ' => ' + c.tier + '/' + c.reason + ', got ' + r.tier + '/' + r.reason + ')',
    r.tier === c.tier && r.reason === c.reason);
}
check('number: blank is ""', R.number('  ', false) === '');
check('number: comma decimal', R.number('17,5', true) === '17.5');
check('number: not a number is null', R.number('1e3x', false) === null);
check('number: an exponent is written out', R.number(' 2.5E1 ', false) === '25');
check('plainDecimal fixture loads', Array.isArray(fx.plainDecimal) && fx.plainDecimal.length > 15);
for (const [input, want] of fx.plainDecimal) {
  const got = R.plainDecimal(input);
  check('plainDecimal(' + JSON.stringify(input) + ') => ' + JSON.stringify(want) + ', got ' + JSON.stringify(got), got === want);
}
check('plainDecimal: a three-digit exponent', R.plainDecimal('1e999') === '1' + '0'.repeat(999));

console.log('range_js: ' + n + ' checks, ' + fails + ' failure(s)');
process.exit(fails ? 1 : 0);
