/* delta_js.cjs — @UVDELTA's browser verdict against tests/delta_fixture.json.
   tests/delta_php.php runs the same fixture through Logic::deltaVerdict, so
   the two runtimes cannot disagree on a change.
   Run:  node tests/delta_js.cjs */
'use strict';
global.window = {};
global.document = { addEventListener() {}, getElementsByName() { return []; }, readyState: 'complete',
                    body: { appendChild() {} } };
require('../js/engine.js');
const L = window.INSPIREUniversalValidator.deltaLogic;
const FIX = require('./delta_fixture.json');

let n = 0, fails = 0;
function check(label, ok) { n++; if (!ok) { fails++; console.log('FAIL: ' + label); } }
function text(v) {
  if (v === null || typeof v === 'string') return v;
  return (v.prefix || '') + String(v.repeat).repeat(v.times) + (v.suffix || '');
}

check('the fixture has cases', FIX.cases.length >= 40);
for (const c of FIX.cases) {
  const r = L.verdict(c.spec, text(c.value), text(c.earlier));
  check(c.name + ': verdict ' + c.verdict + ' (got ' + r.verdict + ')', r.verdict === c.verdict);
  check(c.name + ': reason ' + c.reason + ' (got ' + r.reason + ')', r.reason === c.reason);
  const want = text(c.change);
  check(c.name + ': change ' + (want && want.length > 40 ? want.length + ' chars' : want) + ' (got '
        + (r.change && r.change.length > 40 ? r.change.length + ' chars' : r.change) + ')', r.change === want);
}
// the verdict reads no other key of spec, and an unknown direction is "both"
check('a direction it does not know limits both ways', L.verdict({ max: '1', direction: 'sideways' }, '0', '5').verdict === 'down');

// a run of spaces inside a value is read in linear time: the trim used to retry
// its end-anchored pattern from every space, seconds for a saved value like this
for (const [label, v, e] of [['the value', '5' + ' '.repeat(100000) + '5', '1'],
                             ['the earlier value', '1', '5' + '	'.repeat(100000) + '5']]) {
  const t0 = Date.now();
  const r = L.verdict({ max: '1' }, v, e);
  const ms = Date.now() - t0;
  check('100,000 inner spaces in ' + label + ' are judged in under 250 ms (took ' + ms + ' ms)', ms < 250 && r.verdict === 'unknown');
}

// a plain number past QRID_MAX_PLAIN (4,096) is too long, as Logic::deltaNumber says
check('a plain number of 4,096 digits is read', L.number('9'.repeat(4096)) === '9'.repeat(4096));
check('one of 4,097 is too long (false)', L.number('9'.repeat(4097)) === false);
// DLT-4: zeros that pad a number are not its digits, as Logic::deltaNumber says
check('leading zeros past 4,096 characters are taken off', L.number('0'.repeat(4097) + '700') === '700');
check('... with a sign, a plus dropped', L.number('-' + '0'.repeat(4096) + '7.5') === '-7.5' && L.number('+' + '0'.repeat(4096) + '7.5') === '7.5');
check('trailing zeros of the decimals are taken off', L.number('7.5' + '0'.repeat(4096)) === '7.5');
check('a number inside 4,096 characters keeps its zeros', L.number('007.50') === '007.50');
check('padding does not hide a number that is too long', L.number('000' + '9'.repeat(4097)) === false);

console.log('delta_js: ' + n + ' checks, ' + fails + ' failure(s)');
process.exit(fails ? 1 : 0);
