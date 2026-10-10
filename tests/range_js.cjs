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
check('plainDecimal: 4,096 characters written out', R.plainDecimal('1e4095') === '1' + '0'.repeat(4095)
  && R.plainDecimal('-1e4094') === '-1' + '0'.repeat(4094) && R.plainDecimal('1e4096') === null
  && R.plainDecimal('1e-4094') === '0.' + '0'.repeat(4093) + '1' && R.plainDecimal('1e-4095') === null);
check('number: past 70 places, judged as 10^70 or 10^-71 with its sign',
  R.number('1e1000', false) === '1' + '0'.repeat(70) && R.number('-1e-1000', false) === '-0.' + '0'.repeat(70) + '1'
  && R.number('2e70', false) === '2' + '0'.repeat(70) && R.number('0e1000', false) === '0');

console.log('range_js: ' + n + ' checks, ' + fails + ' failure(s)');
process.exit(fails ? 1 : 0);
