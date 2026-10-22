/* justify_js.cjs — whether a reason field gives a reason (@UVJUSTIFY), in the
   browser, against tests/justify_fixture.json. tests/justify_php.php runs the
   same fixture through Logic::reasonGiven, so the two runtimes cannot disagree.
   Run:  node tests/justify_js.cjs */
'use strict';
global.window = {};
global.document = { addEventListener() {}, getElementsByName() { return []; }, readyState: 'complete',
                    body: { appendChild() {} } };
require('../js/engine.js');
const L = window.INSPIREUniversalValidator.justifyLogic;
const FIX = require('./justify_fixture.json');

let n = 0, fails = 0;
function check(label, ok) { n++; if (!ok) { fails++; console.log('FAIL: ' + label); } }
function text(v) {
  if (v === null || typeof v !== 'object' || !('repeat' in v)) return v;
  return (v.prefix || '') + String(v.repeat).repeat(v.times) + (v.suffix || '');
}
function codes(list) { const o = Object.create(null); for (const c of list) o[c] = true; return o; }

check('the fixture has cases', FIX.cases.length >= 30);
for (const c of FIX.cases) {
  const got = L.reasonGiven(text(c.value), codes(c.codes));
  check('reasonGiven: ' + c.name + ' (want ' + c.given + ', got ' + got + ')', got === c.given);
}
// a code whose map value is not true is no code: the page builds the map with true only
check('a code mapped to anything but true is no code', L.reasonGiven('NA', { NA: 1 }) === true);
// a code named after an Object.prototype member is no code unless the map holds it
check('"constructor" is a reason when the codes do not hold it', L.reasonGiven('constructor', {}) === true);
check('"__proto__" is a reason when the codes do not hold it', L.reasonGiven('__proto__', codes(['UNK'])) === true);
// no codes given: the page's own (none in this harness)
check('no codes given: the page has none here', L.reasonGiven('UNK') === true);

// the trim walks each end once: a long run of spaces costs one pass
const t0 = Date.now();
const r = L.reasonGiven(' \t'.repeat(200000) + 'x' + '\n '.repeat(200000), {});
check('400,000 characters of spaces around a word are read in under 250 ms (took ' + (Date.now() - t0) + ' ms)',
      r === true && Date.now() - t0 < 250);

console.log('justify_js: ' + n + ' checks, ' + fails + ' failure(s)');
process.exit(fails ? 1 : 0);
