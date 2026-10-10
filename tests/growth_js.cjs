'use strict';
/*
 * growth_js.cjs — growth references for @UVRANGE (browser side, 2.6.0).
 *
 * Runs every case of tests/growth_fixture.json, the 2,880 WHO points of
 * tests/who_golden.json and the 5,000 random points of the parity hash
 * through QRID_growth* in js/engine.js. tests/growth_php.php runs the same
 * through php/GrowthReference.php, so the two runtimes give the same z-score
 * for every point.
 *
 * Run:  node tests/growth_js.cjs
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

global.window = {};
global.document = { addEventListener() {}, getElementsByName() { return []; }, readyState: 'complete', body: { addEventListener() {} } };
require('../js/engine.js');
const G = window.INSPIREUniversalValidator.growthLogic;

let n = 0, fails = 0;
function check(label, ok) { n++; if (!ok) { fails++; console.log('FAIL: ' + label); } }
function sameState(got, want) {
  if (!got || got.state !== want.state) return false;
  const gk = Object.keys(got), wk = Object.keys(want);
  return gk.length === wk.length && wk.every((k) => got[k] === want[k]);
}
function sameLms(a, b) {
  if (a === null || b === null) return a === b;
  return a.length === 3 && b.length === 3 && a[0] === b[0] && a[1] === b[1] && a[2] === b[2];
}

// ---- 1. the fixture -------------------------------------------------------------
const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'growth_fixture.json'), 'utf8'));
check('fixture loads', fx.axis.length > 20 && fx.zText.length > 10);
const R = fx.references;
for (const c of fx.axis) {
  const got = G.axis(R[c.ref], c.input);
  check('axis: ' + c.name + ' (got ' + JSON.stringify(got) + ')', sameState(got, c.want));
}
for (const c of fx.lms) {
  const got = G.lms(R[c.ref], c.sex, c.x);
  check('lms: ' + c.name + ' (got ' + JSON.stringify(got) + ')', sameLms(got, c.want));
}
for (const c of fx.zRaw) {
  const z = G.zRaw(c.y, c.lms, c.restricted);
  const got = z === null ? null : G.zText(z);
  check('zRaw: ' + c.name + ' (got ' + JSON.stringify(got) + ')', got === c.zText);
}
for (const [z, want] of fx.zText) check('zText(' + z + ') => ' + want + ' (got ' + G.zText(z) + ')', G.zText(z) === want);
for (const c of fx.measure) {
  const got = G.measure(c.text, c.comma);
  check('measure(' + JSON.stringify(c.text) + ', comma ' + c.comma + ') (got ' + JSON.stringify(got) + ')', sameState(got, c.want));
}
for (const c of fx.zScore) {
  const got = G.zScore(R[c.ref], c.sex, c.x, c.y);
  check('zScore: ' + c.name + ' (got ' + JSON.stringify(got) + ')', sameState(got, c.want));
}
check('zText: never exponent notation, never "-0.00"', G.zText(-0) === '0.00' && G.zText(1e-300) === '0.00' && G.zText(-1e-300) === '0.00');

// ---- 2. the bundled WHO references: WHO's own z-scores ---------------------------
const dir = path.join(__dirname, '..', 'data', 'references');
const idx = JSON.parse(fs.readFileSync(path.join(dir, 'index.json'), 'utf8'));
const ids = Object.keys(idx.references);
const refs = {};
for (const id of ids) {
  const e = idx.references[id];
  const raw = fs.readFileSync(path.join(dir, e.file));
  check('bundled: ' + id + ' matches its sha256', crypto.createHash('sha256').update(raw).digest('hex') === e.sha256);
  refs[id] = Object.assign({}, e, JSON.parse(raw.toString('utf8')));
}
const golden = JSON.parse(fs.readFileSync(path.join(__dirname, 'who_golden.json'), 'utf8')).points;
check('golden: 2,880 points', golden.length === 2880);
let bad = 0;
for (const p of golden) {
  const ref = refs[p.ref];
  const a = G.axis(ref, ref.axis === 'age' ? { days: p.x } : { by: p.x });
  let z = null;
  if (a.state === 'ok') {
    const m = G.measure(p.y, false);
    if (m.state === 'ok') { const s = G.zScore(ref, p.sex === 1 ? 'male' : 'female', a.x, m.y); if (s.state === 'ok') z = s.z; }
  }
  if (z !== p.z) { bad++; if (bad <= 10) console.log('golden mismatch: ' + JSON.stringify(p) + ' got ' + JSON.stringify(z)); }
}
check('golden: every point equals WHO\'s own code (' + bad + ' mismatches)', bad === 0);

// ---- 3. 5,000 random points: the hash growth_php.php also checks ------------------
let s = fx.parity.seed;
function minstd() { s = (s * 48271) % 2147483647; return s; }
function hund(t) { const p = t.split('.'); return parseInt(p[0], 10) * 100 + (p[1] !== undefined ? parseInt((p[1].slice(0, 2) + '00').slice(0, 2), 10) : 0); }
function hundText(h) { const f = String(h % 100); return Math.floor(h / 100) + '.' + (f.length < 2 ? '0' + f : f); }
const lines = [];
for (let i = 0; i < fx.parity.points; i++) {
  const id = ids[minstd() % ids.length], ref = refs[id];
  const sex = minstd() % 2 ? 'female' : 'male';
  const lo = hund(ref.valid.min), hi = ref.valid.max !== undefined ? hund(ref.valid.max) + 1 : hund(ref.valid.below);
  const xt = hundText(lo + minstd() % (hi - lo));
  const input = {};
  if (ref.axis === 'age') input[ref.axisUnit] = xt; else input.by = xt;
  const a = G.axis(ref, input);
  let k = a.state === 'ok' ? Math.floor(a.x * ref.scale) - ref.first : 0;
  k = Math.max(0, Math.min(ref[sex].length - 1, k));
  const yt = hundText(Math.floor(ref[sex][k][1] * (50 + minstd() % 120)));
  let out = a.state;
  if (a.state === 'ok') {
    const m = G.measure(yt, false);
    out = m.state;
    if (m.state === 'ok') { const z = G.zScore(ref, sex, a.x, m.y); out = z.state === 'ok' ? z.z : z.state; }
  }
  lines.push(id + '|' + sex + '|' + xt + '|' + yt + '|' + out);
}
const hash = crypto.createHash('sha256').update(lines.join('\n')).digest('hex');
check('parity: 5,000 points hash to the pinned SHA-256 (got ' + hash + ')', hash === fx.parity.sha256);

console.log('growth_js: ' + n + ' checks, ' + fails + ' failure(s)');
process.exit(fails ? 1 : 0);
