/*
 * risky_js.cjs — locks the browser risky-pattern heuristic.
 *
 * Asserts QRCheck.riskyPattern() rejects the `risky` patterns and passes the
 * `safe` patterns in tests/risky_patterns.json. Paired with tests/risky_php.php
 * (same list, PHP twin) this stops the client and server ReDoS gates from
 * diverging — the P2 finding in the fix-validation review.
 *
 * Run:  node tests/risky_js.cjs
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

const Q = global.window && global.window.INSPIREUniversalValidator;
if (!Q || typeof Q.riskyPattern !== 'function') {
  console.error('engine.js did not expose INSPIREUniversalValidator.riskyPattern');
  process.exit(1);
}

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'risky_patterns.json'), 'utf8'));
let n = 0, fail = 0;
for (const p of fx.risky) {
  n++;
  if (Q.riskyPattern(p) !== true) { fail++; console.error('EXPECTED RISKY but passed: ' + JSON.stringify(p)); }
}
for (const p of fx.safe) {
  n++;
  if (Q.riskyPattern(p) !== false) { fail++; console.error('EXPECTED SAFE but flagged: ' + JSON.stringify(p)); }
}

// ---- pattern-source length cap ----
n++;
if (Q.riskyPattern('A'.repeat(513)) !== true) { fail++; console.error('513-char pattern source was not gated'); }
n++;
if (Q.riskyPattern('A'.repeat(512)) !== false) { fail++; console.error('512-char plain pattern source was wrongly gated'); }

// ---- empirical budget: every pattern the gate PASSES must match adversarial
// input quickly. This is the property the gate exists for — assert it, don't
// assume it. Budget is generous (500ms for the whole safe list) to stay
// CI-noise-free; a catastrophic pattern would blow past it by orders of
// magnitude (the pre-fix (a|aa)+ took >3s on 43 chars).
n++;
{
  const inputs = [
    'a'.repeat(512) + '!',
    'A'.repeat(512) + '!',
    '1'.repeat(512) + '!',
    ('FC' + '0'.repeat(30)).repeat(16),
    '-'.repeat(512),   // a separator that a "." beside it also matches (VAL-4)
    '_'.repeat(512),
  ];
  const t0 = Date.now();
  for (const p of fx.safe) {
    const re = new RegExp('^(?:' + p.replace(/^\^/, '').replace(/\$$/, '') + ')$');
    for (const s of inputs) re.test(s);
  }
  const elapsed = Date.now() - t0;
  if (elapsed > 500) { fail++; console.error(`safe patterns exceeded the time budget: ${elapsed}ms`); }
}

// ---- gatePattern is THE admission point: every pattern the heuristic rejects
// must be refused by the gate too, and every safe one must compile through it.
// Locks the extraction that replaced three copies of this chain (single-field
// factory, pooled factory, AnnotationRules::checkFragment).
if (typeof Q.gatePattern !== 'function') {
  console.error('engine.js did not expose INSPIREUniversalValidator.gatePattern');
  process.exit(1);
}
for (const p of fx.risky) {
  n++;
  const g = Q.gatePattern(p, '');
  if (g.re !== null || !/catastrophically backtracking/.test(g.error)) {
    fail++;
    console.error(`gatePattern did not refuse a risky pattern: ${JSON.stringify(p)}`);
  }
}
for (const p of fx.safe) {
  n++;
  const g = Q.gatePattern(p, '');
  if (g.re === null || g.error !== '') {
    fail++;
    console.error(`gatePattern refused a safe pattern: ${JSON.stringify(p)} -> ${g.error}`);
  }
}
// The other three gates still fire, and `label` names the offending alternate.
n++;
{
  const cases = [
    ['\\Aabc', 'Python-only'],
    ['abéc', 'printable ASCII'],
    ['\\p{L}+x', 'Unicode-property'],
    ['[a-', 'not a valid JavaScript regex'],
  ];
  for (const [pat, needle] of cases) {
    const g = Q.gatePattern(pat, 'GHIT');
    // "<name>: <message>" — the same way the php twin prefixes a labelled
    // failure from checkFragment, so the two runtimes blame the same alternate
    // in the same shape. The base sentences differ by audience and always have.
    if (g.re !== null || g.error.indexOf(needle) === -1 || g.error.indexOf('GHIT: ') !== 0) {
      fail++;
      console.error(`gatePattern(${JSON.stringify(pat)}, "GHIT") -> ${JSON.stringify(g.error)}`);
    }
  }
  // no label => legacy wording: no "(alternate)" tag right after "idPattern"
  const g0 = Q.gatePattern('\\Aabc', '');
  if (g0.error.indexOf('idPattern uses Python-only') !== 0) {
    fail++;
    console.error(`unlabelled gatePattern changed the legacy wording: ${JSON.stringify(g0.error)}`);
  }
}

// ---- dialect parity (M-1, M-2) ----
// A pattern one runtime admits and the other refuses splits enforcement: either
// the browser stops checking the field while the post-save audit keeps filing
// findings, or both engines compile it and then disagree about every value.
// Same list as risky_php.php; the two suites assert the same verdicts.
for (const p of fx.dialect_reject) {
  n++;
  const g = Q.gatePattern(p, '');
  if (g.re !== null || g.error === '') {
    fail++;
    console.error('gatePattern admitted a dialect-split pattern: ' + JSON.stringify(p));
  }
}
for (const p of fx.dialect_accept) {
  n++;
  const g = Q.gatePattern(p, '');
  if (g.re === null || g.error !== '') {
    fail++;
    console.error(`gatePattern refused a portable pattern: ${JSON.stringify(p)} -> ${g.error}`);
  }
}
// The refusals that used to fall through to the compile step now say WHY, in
// the same terms the server does.
n++;
{
  const cases = [
    ['\\pL{8}[0-9A-Z]', 'Unicode-property'],
    ['[[:digit:]]{4}', 'PCRE-only syntax'],
    ['(?i)FC[0-9]{4}', 'group form JavaScript cannot compile'],
    ['FC[][0-9]{4}', 'empty character class'],
    // review 2026-11-13, VAL-4: PCRE ran these and new RegExp threw
    ['[0-9]?+[A-Z]', 'quantifier where JavaScript allows none'],
    ['[A-Z]{2}+[0-9]{4}', 'quantifier where JavaScript allows none'],
    ['(?<=A)+B', 'quantifier where JavaScript allows none'],
    ['(?<!A){2}B', 'quantifier where JavaScript allows none'],
    ['A\\b+B', 'quantifier where JavaScript allows none'],
    // review 2026-11-13, VAL-5: both engines ran these and read them differently
    ['(A)?\\1B', 'a back-reference \\1 to \\9'],
    ['\\x4?A', '\\x without two hex digits'],
    ['FC\\e[0-9]{4}', '\\a, \\e'],
  ];
  for (const [pat, needle] of cases) {
    const g = Q.gatePattern(pat, '');
    if (g.re !== null || g.error.indexOf(needle) === -1) {
      fail++;
      console.error(`gatePattern(${JSON.stringify(pat)}) -> ${JSON.stringify(g.error)}`);
    }
  }
}

// ---- what the gate admits, the two engines read the same way (VAL-4, VAL-5) ----
// Same rows as risky_php.php: [pattern, value, matches].
for (const [pat, value, want] of [
  ['\\u0041B[0-9]{2}', 'AB12', true],
  ['\\u0041B[0-9]{2}', 'UB12', false],
  ['\\x41B[0-9]{2}', 'AB12', true],
  ['[0-9]{2}?[A-Z]', '12A', true],
  ['(?<=A)B|AB', 'AB', true],
  ['(?=A)+[A-Z]{2}', 'AB', true],
  ['(?=A)+[A-Z]{2}', 'BA', false],
  ['FC{[0-9]', 'FC{1', true],
]) {
  n++;
  const g = Q.gatePattern(pat, '');
  if (g.re === null || g.re.test(value) !== want) {
    fail++;
    console.error(`browser verdict for ${JSON.stringify(pat)} on ${JSON.stringify(value)}: ${g.error || !want}`);
  }
}

// ---- the gate never hands back a pattern new RegExp refuses (VAL-4) ----
// A refusal that reaches the compile step is a rule the server may have saved.
n++;
{
  const atoms = ['A', '[0-9]', '.', '\\d', '\\b', '^', '$', '(?<=A)', '(?<!A)', '(?=A)', '(A)', '(?:A|B)', '{', '}', ']', '|', '\\.'];
  const quants = ['', '', '*', '+', '?', '{2}', '{1,2}', '{2,}', '*?', '+?', '??', '{2}?', '?+', '*+', '++', '{2}+', '{2}{2}', '{2}*'];
  let seed = 7, bad = [];
  const rnd = () => { seed = (Math.imul(seed, 1103515245) + 12345) & 0x7fffffff; return seed / 0x7fffffff; };
  const pick = (a) => a[Math.floor(rnd() * a.length)];
  for (let k = 0; k < 4000; k++) {
    let p = '';
    for (let j = 1 + Math.floor(rnd() * 4); j > 0; j--) p += pick(atoms) + pick(quants);
    const g = Q.gatePattern(p, '');
    if (g.re === null && /not a valid JavaScript regex/.test(g.error)) bad.push(p);
  }
  if (bad.length) { fail++; console.error('patterns that reached the compile step and failed there: ' + JSON.stringify(bad.slice(0, 8))); }
}

console.log(`risky_js: ${n} patterns checked, ${fail} mismatch(es)`);
process.exit(fail === 0 ? 0 : 1);
