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

console.log('delta_js: ' + n + ' checks, ' + fails + ' failure(s)');
process.exit(fails ? 1 : 0);
