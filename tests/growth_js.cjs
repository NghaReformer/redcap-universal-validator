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
for (const c of fx.plausible) {
  const got = G.plausible(c.y, c.lo, c.hi);
  check('plausible(' + c.y + ', ' + c.lo + ', ' + c.hi + ') (got ' + got + ')', got === c.want);
}
for (const c of fx.measure) {
  const got = G.measure(c.text, c.comma, c.scale);
  check('measure(' + JSON.stringify(c.text) + ', comma ' + c.comma + ') (got ' + JSON.stringify(got) + ')', sameState(got, c.want));
}
for (const c of fx.zScore) {
  const got = G.zScore(R[c.ref], c.sex, c.x, c.y);
  check('zScore: ' + c.name + ' (got ' + JSON.stringify(got) + ')', sameState(got, c.want));
}
check('zText: never exponent notation, never "-0.00"', G.zText(-0) === '0.00' && G.zText(1e-300) === '0.00' && G.zText(-1e-300) === '0.00');

// The values an @UVRANGE rule returns: tests/growth_php.php runs the same cases.
check('fixture: return-value sections load', fx.valueAt.length > 10 && fx.bound.length > 5
  && fx.unitsText.length > 5 && fx.outputs.length > 10);
for (const c of fx.valueAt) {
  const got = G.valueAt(c.z, c.lms, c.restricted);
  const ok = c.want === null ? got === null : (got !== null && Math.abs(got - c.want) <= 1e-9 * Math.max(1, Math.abs(c.want)));
  check('valueAt: ' + c.name + ' (got ' + JSON.stringify(got) + ')', ok);
}
for (const c of fx.bound) {
  const got = G.bound(R[c.ref], c.sex, c.x, c.lim, c.decimals, c.low, c.scale);
  check('bound: ' + c.name + ' (got ' + JSON.stringify(got) + ')', got === c.want);
}
for (const [u, d, want] of fx.unitsText) {
  check('unitsText(' + u + ', ' + d + ') => ' + want + ' (got ' + G.unitsText(u, d) + ')', G.unitsText(u, d) === want);
}
for (const c of fx.outputs) {
  const got = G.outputs(R[c.ref], c.sex, c.x, c.y, c.limits, c.decimals, c.comma,
    c.scale === undefined ? null : c.scale, c.unit === undefined ? null : c.unit);
  check('outputs: ' + c.name + ' (got ' + JSON.stringify(got) + ')',
    ['z', 'lower', 'upper', 'range'].every((k) => got[k] === c.want[k]) && Object.keys(got).length === 4);
}
check('markText: a comma only when asked', G.markText('7.7', true) === '7,7' && G.markText('7.7', false) === '7.7'
  && G.markText('12.0', true) === '12,0');
{
  /* valueAt inverts zRaw over random rows, with and without the WHO restriction */
  let seed = 4711;
  const next = () => { seed = (seed * 48271) % 2147483647; return seed; };
  let worst = 0;
  for (let i = 0; i < 4000; i++) {
    const l = [0, 1, -1, 0.5, -0.5, 2, -2, 0.25, 0.1, -0.3][next() % 10];
    const m = 1 + next() % 400 / 10;
    const sd = 0.03 + next() % 300 / 1000;
    const z = -5 + next() % 1001 / 100;
    const r = next() % 2 === 1;
    const y = G.valueAt(z, [l, m, sd], r);
    if (y === null) continue;
    const back = G.zRaw(y, [l, m, sd], r);
    if (back === null) { worst = Infinity; break; }
    worst = Math.max(worst, Math.abs(back - z) / Math.max(1, Math.abs(z)));
  }
  check('valueAt: zRaw(valueAt(z)) is z, 4,000 random rows (worst relative error ' + worst + ')', worst < 1e-8);
}

// ---- 1b. the percentile and the values at a z-score or percentile (php/growth_outputs.json) ----
// Every expected value in these sections was worked out with mpmath; tests/growth_php.php runs the same.
check('fixture: output sections load', fx.percentileText.length > 10 && fx.percentile.length > 10
  && fx.valueAtText.length > 8 && fx.output.length > 5 && fx.outputOf.length > 30);
for (const [cum, d, want] of fx.percentileText) {
  const got = G.percentileText(cum, d);
  check('percentileText(' + cum + ', ' + d + ') => ' + want + ' (got ' + got + ')', got === want);
}
for (const c of fx.percentile) {
  const got = G.percentile(R[c.ref], c.sex, c.x, c.y, c.places);
  check('percentile: ' + c.name + ' (got ' + JSON.stringify(got) + ')', got === c.want);
}
for (const c of fx.valueAtText) {
  const got = G.valueAtText(R[c.ref], c.sex, c.x, c.z, c.places, c.scale);
  check('valueAtText: ' + c.name + ' (got ' + JSON.stringify(got) + ')', got === c.want);
}
for (const c of fx.output) {
  const got = G.output(R[c.ref], c.sex, c.x, c.y, c.of, c.param, c.places, c.scale);
  check('output: ' + c.name + ' (got ' + JSON.stringify(got) + ')', got === c.want);
}
for (const [key, want] of fx.outputOf) {
  const p = G.outputOf(key);
  const got = p === null ? null : { of: p.of, param: p.param };
  check('outputOf ' + JSON.stringify(key) + ' (got ' + JSON.stringify(got) + ')', JSON.stringify(got) === JSON.stringify(want));
}
{
  /* R's pnorm() and qnorm(), which normalCdf and normalQuantile port step for step */
  const ng = JSON.parse(fs.readFileSync(path.join(__dirname, 'growth_normal_golden.json'), 'utf8'));
  check('normal golden: thousands of points from R', ng.pnorm.length > 4000 && ng.qnorm.length > 4000);
  let wp = 0, wq = 0;
  for (const [x, v] of ng.pnorm) {
    const got = G.normalCdf(x);
    /* R 4.6 keeps subnormal values below x = -37.52 where the port gives 0 */
    if (Math.abs(v) < 1e-300) { wp = Math.max(wp, Math.abs(got - v) < 1e-300 ? 0 : 1); continue; }
    wp = Math.max(wp, Math.abs(got - v) / Math.abs(v));
  }
  for (const [p, v] of ng.qnorm) {
    const got = G.normalQuantile(p);
    if (typeof v === 'string') { wq = Math.max(wq, got === (v === 'Infinity' ? Infinity : -Infinity) ? 0 : 1); continue; }
    wq = Math.max(wq, v === 0 ? Math.abs(got) : Math.abs(got - v) / Math.abs(v));
  }
  check('normalCdf: R pnorm() to 1e-15 relative (worst ' + wp + ')', wp < 1e-15);
  check('normalQuantile: R qnorm() to 1e-15 relative (worst ' + wq + ')', wq < 1e-15);
  check('normalQuantile: the ends and outside', G.normalQuantile(0) === -Infinity && G.normalQuantile(1) === Infinity
    && isNaN(G.normalQuantile(-0.1)) && isNaN(G.normalQuantile(1.1)) && G.normalQuantile(0.5) === 0);
  check('normalCdf: symmetric about 0 and exact at 0', G.normalCdf(0) === 0.5 && Math.abs(G.normalCdf(1.3) + G.normalCdf(-1.3) - 1) < 1e-15);
}
{
  /* the engine's copy of the catalog is what php/growth_outputs.json declares */
  const cat = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'php', 'growth_outputs.json'), 'utf8')).outputs;
  check('catalog: the engine knows every output, in the file\'s order',
    JSON.stringify(Object.keys(G.catalog)) === JSON.stringify(Object.keys(cat)));
  check('catalog: needs, written and places as the file says',
    Object.keys(cat).every((id) => G.catalog[id].needs === cat[id].needs && G.catalog[id].written === cat[id].written
      && G.catalog[id].places === cat[id].places));
  /* every output the engine lists as computed gives a value on a plain row */
  const plain = G.outputs(R['t-days'], 'male', 2, 11, { lo: '-2', hi: '2' }, 1, false);
  for (const id of G.computed) {
    const got = Object.prototype.hasOwnProperty.call(plain, id) ? plain[id]
      : G.output(R['t-days'], 'male', 2, 11, id, cat[id] && cat[id].param ? cat[id].param.max : null, 1, null, ' 11.0 ');
    check('computed: ' + id + ' gives a value (got ' + JSON.stringify(got) + ')', typeof got === 'string' && got !== '');
  }
  /* the page table's own columns, as tests/growth_php.php pins them */
  check('output age: the axis value, two decimals at most, no trailing zeros',
    G.output(R['t-days'], 'male', 365, null, 'age', null, 1) === '365' && G.output(R['t-days'], 'male', 12.5, null, 'age', null, 1) === '12.5'
    && G.output(R['t-days'], 'male', 13.145, null, 'age', null, 1) === '13.15' && G.output(R['t-days'], 'male', 0, null, 'age', null, 1) === '0');
  check('axisText: nothing for what is not finite', G.axisText(NaN) === null && G.axisText(Infinity) === null && G.axisText(-1.5) === '-1.5');
  check('output value: the typed text, trimmed; nothing when blank', G.output(R['t-days'], 'male', 2, 11, 'value', null, 1, null, ' 7,70\t') === '7,70'
    && G.output(R['t-days'], 'male', 2, null, 'value', null, 1, null, '  ') === null && G.output(R['t-days'], 'male', 2, null, 'value', null, 1) === null);
  check('lineZ: a z-score as itself, a percentile as its quantile, nothing else',
    G.lineZ('valueAtZ', '-2') === -2 && Math.abs(G.lineZ('valueAtPercentile', '97.5') - 1.959963984540054) < 1e-12
    && G.lineZ('z', null) === null && G.lineZ('valueAtPercentile', '0') === null);
  check('outputOf: age and value are columns, not field values', G.outputOf('age') === null && G.outputOf('value') === null
    && G.outputOf('age', 'column').of === 'age' && G.outputOf('valueAtZ:2', 'line').of === 'valueAtZ' && G.outputOf('z', 'line') === null);
  check('percentileText: no probability, no text', G.percentileText(NaN, 1) === null && G.percentileText(Infinity, 1) === null
    && G.percentileText(-Infinity, 1) === null);
  check('roundedUnits: what is not a number at 0 or above gives nothing', G.roundedUnits(NaN, 1) === null
    && G.roundedUnits(Infinity, 1) === null && G.roundedUnits(-0.5, 1) === null && G.roundedUnits(-0, 2) === 0);
  check('roundedUnits: decimal ties round half away from zero', G.roundedUnits(9.165, 2) === 917 && G.roundedUnits(1.005, 2) === 101
    && G.roundedUnits(2.675, 2) === 268 && G.roundedUnits(0.5, 0) === 1 && G.roundedUnits(9.995, 2) === 1000);
  check('roundedUnits: an exact binary tie rounds up, not to even', G.roundedUnits(617283945060.5, 0) === 617283945061
    && G.roundedUnits(1234567.890625, 5) === 123456789063 && G.roundedUnits(2.5, 0) === 3);
  check('roundedUnits: 15 significant digits, then the decimals', G.roundedUnits(1.23456789012345678, 14) === 123456789012346
    && G.roundedUnits(999999999999999.5, 0) === 1000000000000000 && G.roundedUnits(1e-20, 6) === 0);
}

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
{
  /* A bound is the edge the verdict draws: the measurement at it scores a
     two-decimal z-score inside the limit, one unit beyond it does not
     (tests/growth_php.php runs the same check). Limits have at most three
     decimals, so comparing them as doubles is exact. */
  let seed = 90210;
  const next = () => { seed = (seed * 48271) % 2147483647; return seed; };
  const pass = (ref, sex, x, k, d, lim, low, scale) => {
    if (k < 1) return false;
    const m = G.measure(G.unitsText(k, d), false, scale);
    if (m.state !== 'ok') return false;
    const z = G.zScore(ref, sex, x, m.y);
    if (z.state !== 'ok') return false;
    return low ? parseFloat(z.z) >= parseFloat(lim) : parseFloat(z.z) <= parseFloat(lim);
  };
  let n = 0, wrong = 0, ex = '';
  for (const id of ids) {
    const ref = refs[id];
    const lo = parseFloat(ref.valid.min);
    const hi = ref.valid.max !== undefined ? parseFloat(ref.valid.max) : parseFloat(ref.valid.below) - 1;
    for (let i = 0; i < 60; i++) {
      let x = lo + (next() % 10000) / 10000 * (hi - lo);
      if (ref.axis === 'age') x = Math.floor(x);
      const sex = next() % 2 ? 'male' : 'female';
      const lim = next() % 2 ? ((next() % 7801 - 3900) / 1000).toFixed(3) : ((next() % 781 - 390) / 100).toFixed(2);
      const scale = next() % 4 === 0 ? '0.001' : null;
      const d = scale === null ? next() % 4 : next() % 2;
      const low = next() % 2 === 1;
      const k = G.bound(ref, sex, x, lim, d, low, scale);
      if (k === null) continue;
      n++;
      if (!pass(ref, sex, x, k, d, lim, low, scale) || pass(ref, sex, x, low ? k - 1 : k + 1, d, lim, low, scale)) {
        wrong++;
        if (!ex) ex = ': ' + [id, sex, x, lim, d, low ? 'low' : 'high', k].join(' ');
      }
    }
  }
  check('bound: the verdict passes the bound and not one unit beyond, ' + n + ' random rows (' + wrong + ' wrong' + ex + ')',
    n > 500 && wrong === 0);
}

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

// ---- 3b. the outputs on the bundled tables: mpmath, then the hash growth_php.php also checks ----
{
  const og = JSON.parse(fs.readFileSync(path.join(__dirname, 'growth_outputs_golden.json'), 'utf8')).points;
  check('outputs golden: 720 points', og.length === 720);
  let bad = 0, ex = '';
  for (const p of og) {
    const ref = refs[p.ref];
    const got = p.kind === 'percentile' ? G.percentile(ref, p.sex, p.x, parseFloat(p.arg), p.places)
      : G.output(ref, p.sex, p.x, null, p.kind, p.arg, p.places);
    if (got !== p.want) { bad++; if (!ex) ex = ': ' + JSON.stringify(p) + ' got ' + JSON.stringify(got); }
  }
  check('outputs golden: every point equals mpmath at 50 digits (' + bad + ' mismatches' + ex + ')', bad === 0);
  /* the values the documentation prints for a child of 365 days on who-wfa
     (docs/action_tag_validation_examples.md Level 6, README.md, USER_GUIDE.md,
     CHANGELOG.md); tests/growth_return_module_php.php pins the same in PHP */
  const wfa = refs['who-wfa'], at = G.axis(wfa, { days: '365' }).x;
  const doc = [['male', 'percentile', null, 9.6, '48.3'], ['male', 'valueAtZ', '-2', null, '7.7'],
    ['male', 'valueAtPercentile', '50', null, '9.6'], ['male', 'valueAtPercentile', '97', null, '11.8'],
    ['female', 'percentile', null, 9.6, '71.6'], ['female', 'valueAtZ', '-2', null, '7.0'],
    ['female', 'valueAtPercentile', '50', null, '8.9'], ['female', 'valueAtPercentile', '97', null, '11.3'],
    ['male', 'percentile', null, 6.6, '0.1']];
  for (const [sex, of, param, y, want] of doc) {
    const got = G.output(wfa, sex, at, y, of, param, 1);
    check('documented: ' + sex + ' ' + of + (param === null ? '' : ':' + param) + ' is ' + want + ' (got ' + got + ')', got === want);
  }
  s = fx.parityOutputs.seed;
  const oLines = [];
  for (let i = 0; i < fx.parityOutputs.points; i++) {
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
    const pz = ['-3.5', '-2', '-1', '0', '1', '2', '3', '4.25'][minstd() % 8];
    const pp = ['0.1', '3', '15', '50', '85', '97', '99.9', '2.28'][minstd() % 8];
    const d = minstd() % 4;
    let out = [a.state];
    if (a.state === 'ok') {
      const m = G.measure(yt, false);
      const y = m.state === 'ok' ? m.y : null;
      out = [G.output(ref, sex, a.x, y, 'percentile', null, 1), G.output(ref, sex, a.x, y, 'percentile', null, 3),
        G.output(ref, sex, a.x, null, 'valueAtZ', pz, d), G.output(ref, sex, a.x, null, 'valueAtPercentile', pp, d)];
    }
    oLines.push([id, sex, xt, yt, pz, pp, d].concat(out.map((v) => v === null ? '-' : v)).join('|'));
  }
  const oHash = crypto.createHash('sha256').update(oLines.join('\n')).digest('hex');
  check('parity: ' + fx.parityOutputs.points + ' output points hash to the pinned SHA-256 (got ' + oHash + ')',
    oHash === fx.parityOutputs.sha256);
}

console.log('growth_js: ' + n + ' checks, ' + fails + ' failure(s)');
process.exit(fails ? 1 : 0);
