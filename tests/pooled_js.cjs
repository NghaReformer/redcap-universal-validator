/*
 * pooled_js.cjs — regression guard for the browser pooled parser.
 *
 * Recomputes parse() for every case in tests/pooled_fixture.json and fails on any
 * mismatch. Paired with tests/pooled_php.php (which recomputes the same cases with
 * the PHP port), this is the contract that keeps the server pooled parser in step
 * with the browser one. Regenerate the fixture with tests/gen_pooled_fixture.cjs
 * whenever the parser legitimately changes.
 *
 * Run:  node tests/pooled_js.cjs
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
global.window.QRCheck = global.QRCheck;

function canon(segs) {
  return segs.map((s) => (s.type === 'id' ? ['id', s.id, !!s.valid] : ['junk', s.text]));
}

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'pooled_fixture.json'), 'utf8'));
let fail = 0;
for (const c of fx.cases) {
  global.window.QRIDMulti = null;
  window.QRIDPooledInit(Object.assign({ fields: [] }, c.config));
  const got = JSON.stringify(canon(window.QRIDMulti.parse(c.input)));
  const exp = JSON.stringify(canon(c.segs));
  if (got !== exp) {
    fail++;
    console.error(`MISMATCH [${c.label}]\n  expected ${exp}\n  got      ${got}`);
  }
}
console.log(`pooled_js: ${fx.cases.length} cases checked, ${fail} mismatch(es)`);
process.exit(fail === 0 ? 0 : 1);
