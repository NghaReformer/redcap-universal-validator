/*
 * gen_when_fuzz.cjs — regenerate tests/when_fuzz.json.
 *
 * The hand-curated tests/when_fixture.json locks the cases a human thought of.
 * This adds the ones nobody thought of: a seeded generator builds conditions
 * from the grammar AND mutates them into malformed ones, records what the JS
 * twin does with each, and freezes the lot. tests/when_fuzz_php.php then makes
 * php/Logic.php recompute every case and fails on ANY disagreement — accept vs
 * reject, verdict, or referenced fields.
 *
 * Deterministic (fixed seeds, own PRNG) so the fixture is stable and a failure
 * is reproducible; CI regenerates it and fails if the committed copy drifts.
 *
 * Run:  node tests/gen_when_fuzz.cjs
 */
'use strict';
const fs = require('fs');
const path = require('path');

let seed = 0;
function srand(s) { seed = s; }
function rnd() { seed = (seed * 1103515245 + 12345) & 0x7fffffff; return seed / 0x7fffffff; }
function pick(a) { return a[Math.floor(rnd() * a.length) % a.length]; }
function int(n) { return Math.floor(rnd() * n) % n; }

const FIELDS = ['a', 'b', 'c', 'stype', 'age'];
const CODES = ['1', '2', '3'];
const OPS = ['=', '<>', '!=', '>', '<', '>=', '<='];
/* values that stress the numeric-vs-string rule: leading zeros, decimals,
   signs, exponent/hex shapes PHP and JS parse differently, padding, case */
const VALS = ['', '0', '1', '2', '02', '2.0', '2.50', '10', '9', '-1', '-2', 'a', 'A',
              'yes', 'Yes', ' 2 ', '1e3', '0x10', '+3', '.5', '00', 'abc', '2x'];
const LITS = ['1', '2', '02', '9', '10', '2.5', '-1', '', 'a', 'A', 'yes', '0', '.5', '+3'];

function operand() {
  const r = rnd();
  if (r < 0.55) return '[' + pick(FIELDS) + ']';
  if (r < 0.70) return '[cb(' + pick(CODES) + ')]';
  if (r < 0.85) { const q = rnd() < 0.5 ? "'" : '"'; return q + pick(LITS) + q; }
  return pick(['1', '2', '10', '-1', '2.5']);
}
function cmp() { return operand() + ' ' + pick(OPS) + ' ' + operand(); }
function expr(depth) {
  if (depth <= 0) return cmp();
  const r = rnd();
  if (r < 0.45) return cmp();
  if (r < 0.60) return 'not ' + expr(depth - 1);
  if (r < 0.75) return '(' + expr(depth - 1) + ')';
  const op = rnd() < 0.5 ? ' and ' : ' or ';
  const parts = [];
  const n = 2 + int(2);
  for (let i = 0; i < n; i++) parts.push(expr(depth - 1));
  return parts.join(op);
}
function values() {
  const v = {};
  for (const f of FIELDS) if (rnd() < 0.75) v[f] = pick(VALS);
  if (rnd() < 0.7) {
    const m = {};
    for (const c of CODES) if (rnd() < 0.6) m[c] = rnd() < 0.5 ? '1' : '0';
    v.cb = m;
  }
  return v;
}

const MUT_BASE = [
  "[a]='1'", "[a]='1' and [b]='2'", "[a]='1' or [b]='2' and [c]='3'",
  "not [a]='1'", "not([a]='1' or [b]='2')", "([a]='1' or [b]='2') and [c]='3'",
  "[cb(2)]='1'", "[age]>'17' and [age]<='65'", "[a]<>'' and [b]!='x'",
  "'1'='1'", "[a]=[b]", "[a]>-1", "[a]='2.50'",
];
const MUT_CHARS = ['[', ']', '(', ')', "'", '"', '=', '<', '>', '!', ' ', 'a', '1', '.', ',',
                   '\\', '|', '&', '+', '-', '*', '/', '%', '$', '{', '}', ';', ':', '?', '@',
                   '\t', '\n', 'é', 'and', 'or', 'not', 'datediff', '[event]'];
function mutate(s) {
  const n = 1 + int(3);
  for (let k = 0; k < n; k++) {
    const op = int(4);
    const i = int(Math.max(1, s.length));
    if (op === 0) s = s.slice(0, i) + pick(MUT_CHARS) + s.slice(i);
    else if (op === 1) s = s.slice(0, i) + s.slice(i + 1);
    else if (op === 2) s = s.slice(0, i) + pick(MUT_CHARS) + s.slice(i + 1);
    else s = s.slice(0, i) + s.slice(i, i + 1 + int(4)) + s.slice(i);
  }
  return s;
}
/* hand-picked hostile shapes the mutator is unlikely to hit */
const HOSTILE = [
  '', ' ', '\t\n', '[', ']', '[]', '[]=1', '[a]', '[a]=', '=[a]', "[a]==='1'",
  "[a]='1'; DROP TABLE x", "[a]='<script>'", "[a]='" + 'x'.repeat(600) + "'",
  '['.repeat(60) + 'a' + ']'.repeat(60), '('.repeat(50) + "[a]='1'" + ')'.repeat(50),
  "datediff([a],[b],'d')>3", "[event_1_arm_1][a]='1'", "[record-name]='x'",
  "[a]='1' and", "and [a]='1'", 'not', "not not [a]='1'", "[a]='1' [b]='2'",
  "[a]=[b]=[c]", "[a(1)(2)]='1'", "[a()]='1'", "[a(]='1'", "[a)]='1'",
  "[A]='1'", "[_a]='1'", "[1a]='1'", "[a b]='1'", "[a]  =  '1'",
  "[a]='it\\'s'", "[a]='é'", '[a]=1e3', '[a]=0x10', '[a]=.5', '[a]=+3', '[a]=-',
  "[a]='1'or[b]='2'", "[a]='1'and[b]='2'", "[cb(999999)]='1'", "[cb(-1)]='1'",
  "[cb(a b)]='1'", '*'.repeat(100), "[a]='1'".repeat(30),
];

global.window = {};
global.document = {
  addEventListener() {}, getElementsByName() { return []; },
  createElement() { return { style: {}, setAttribute() {}, appendChild() {}, insertBefore() {} }; },
  readyState: 'complete', body: { addEventListener() {} },
};
require(path.join(__dirname, '..', 'js', 'engine.js'));
const W = global.window.INSPIREUniversalValidator.whenLogic;

const cases = [];
function add(expr) {
  const v = values();
  const c = { expr: expr, values: v };
  const r = W.parse(expr);
  c.ok = !!r.ok;
  if (r.ok) {
    c.js = W.evaluate(r.ast, v);
    c.jsRefs = W.referencedFields(r.ast);
  }
  cases.push(c);
}
const SEEDS = [1, 7, 99, 20260715, 424242];
srand(11);
for (const h of HOSTILE) add(h);
for (const s of SEEDS) {
  srand(s);
  for (let i = 0; i < 400; i++) add(expr(1 + int(3)));      // valid by construction
  for (let i = 0; i < 400; i++) add(mutate(pick(MUT_BASE))); // mostly malformed
}

const out = path.join(__dirname, 'when_fuzz.json');
fs.writeFileSync(out, JSON.stringify({
  note: 'GENERATED by tests/gen_when_fuzz.cjs — do not hand-edit. Seeded conditions (valid and mutated) with the JS twin\'s verdicts frozen; tests/when_fuzz_php.php makes php/Logic.php recompute them and fails on any disagreement.',
  seeds: SEEDS,
  cases: cases,
}, null, 1) + '\n');
const ok = cases.filter((c) => c.ok).length;
console.log(`gen_when_fuzz: ${cases.length} cases (accepted ${ok}, rejected ${cases.length - ok}) -> tests/when_fuzz.json`);
