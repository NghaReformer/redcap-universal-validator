/*
 * numeric_js.cjs — JS side of the exact-decimal comparison contract (H-03).
 *
 * Drives the QRID_whenCompare/QRID_whenDecCmp twins in js/engine.js (reached
 * through INSPIREUniversalValidator.whenLogic.evaluate) over every case in
 * tests/numeric_fixture.json. tests/numeric_php.php drives php/Logic.php over
 * the SAME fixture and hashes the SAME verdicts, so a comparator that drifts
 * between the runtimes cannot pass both.
 *
 * The defect this locks: both runtimes used to cast a NUM_RE-shaped operand to
 * a float before comparing, so 9007199254740992 and 9007199254740993 compared
 * EQUAL and the documented @UVASSERT="[id]=[id_confirm]" recipe accepted two
 * different identifiers. Cases marked "floatTrap" are the ones where the float
 * cast still gives a different answer; the float verdict is recomputed below
 * and the case fails if it AGREES, so the sentinels cannot quietly go stale.
 *
 * Run:  node tests/numeric_js.cjs
 */
'use strict';
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

global.window = {};
global.document = {
  addEventListener() {},
  getElementsByName() { return []; },
  createElement() { return { style: {}, setAttribute() {}, appendChild() {}, insertBefore() {} }; },
  readyState: 'complete',
  body: { addEventListener() {} },
};
require(path.join(__dirname, '..', 'js', 'engine.js'));

const NS = global.window && global.window.INSPIREUniversalValidator;
const W = NS && NS.whenLogic;
if (!W || typeof W.evaluate !== 'function') {
  console.error('engine.js did not expose INSPIREUniversalValidator.whenLogic');
  process.exit(1);
}

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'numeric_fixture.json'), 'utf8'));
let n = 0, fail = 0, shown = 0;
function check(label, cond) {
  n++;
  if (!cond) { fail++; console.error('FAIL: ' + label); }
}
/* At most a dozen detail lines, so a broken comparator stays readable. */
function detail(msg) {
  if (shown++ < 12) console.error('  ' + msg);
}

/* The six verdicts a single -1/0/1 comparison has to produce. */
function verdictFor(op, cmp) {
  switch (op) {
    case '=':  return cmp === 0;
    case '<>': return cmp !== 0;
    case '<':  return cmp < 0;
    case '>':  return cmp > 0;
    case '<=': return cmp <= 0;
    case '>=': return cmp >= 0;
  }
  return null;
}

/* ['ref','a',null] or ['lit','<value>'] — both operand shapes reach compare(). */
function operandNode(kind, slot, value) {
  return kind === 'lit' ? ['lit', value] : ['ref', slot, null];
}

const trim = (v) => String(v).replace(/^[ \t\r\n]+|[ \t\r\n]+$/g, '');

/*
 * Independent code-point comparison of the trimmed operands, written here
 * rather than read from the engine: it is what proves a "string" case really
 * fell through the numeric branch instead of being read as a number. This is
 * also the only path check available on this side — the engine keeps its
 * NUM_RE module-private, so unlike the PHP twin the regex cannot be asked
 * directly, and the fixture's declared cmp is what pins the branch.
 */
function codePointCmp(a, b) {
  a = trim(a); b = trim(b);
  let i = 0, j = 0;
  while (i < a.length && j < b.length) {
    const ca = a.codePointAt(i), cb = b.codePointAt(j);
    if (ca !== cb) return ca < cb ? -1 : 1;
    i += ca > 0xFFFF ? 2 : 1;
    j += cb > 0xFFFF ? 2 : 1;
  }
  const ra = a.length - i, rb = b.length - j;
  return ra < rb ? -1 : (ra > rb ? 1 : 0);
}

/* The comparison the module used to make — kept only to prove it was wrong. */
function floatCmp(a, b) {
  const fa = Number(trim(a)), fb = Number(trim(b));
  return fa < fb ? -1 : (fa > fb ? 1 : 0);
}

check('fixture loads', fx && Array.isArray(fx.cases) && fx.cases.length > 0
  && Array.isArray(fx.ops) && Array.isArray(fx.shapes)
  && Array.isArray(fx.mustInclude) && typeof fx.digest === 'string');
if (fail) {
  console.error('numeric_fixture.json is missing or malformed');
  process.exit(1);
}

// ---- the fixture itself: shape, uniqueness, and the cases nobody may drop ----
check('fixture ops are the six comparison operators',
  JSON.stringify(fx.ops) === JSON.stringify(['=', '<>', '<', '>', '<=', '>=']));
check('fixture covers both operand shapes on both sides',
  JSON.stringify(fx.shapes) === JSON.stringify(['ref_ref', 'ref_lit', 'lit_ref', 'lit_lit']));

const names = [];
for (const c of fx.cases) {
  const label = c && c.name ? c.name : '(unnamed)';
  // Operands MUST be JSON strings: a bare JSON number would be parsed as a
  // double on the way in and the fixture would lose the very digits it pins.
  check('case is well formed: ' + label,
    typeof c.a === 'string' && typeof c.b === 'string'
    && (c.path === 'numeric' || c.path === 'string')
    && (c.cmp === -1 || c.cmp === 0 || c.cmp === 1));
  names.push(label);
}
check('case names are unique', new Set(names).size === names.length);
for (const req of fx.mustInclude) {
  check('required case present: ' + req, names.indexOf(req) !== -1);
}

// ---- every case, every operator, every operand shape ----
const digestLines = [];
for (const c of fx.cases) {
  const values = { a: c.a, b: c.b };

  for (const shape of fx.shapes) {
    const kinds = shape.split('_');
    for (const op of fx.ops) {
      const ast = ['cmp', op,
        operandNode(kinds[0], 'a', c.a),
        operandNode(kinds[1], 'b', c.b)];
      const got = W.evaluate(ast, values);
      const want = verdictFor(op, c.cmp);
      if (got !== want) {
        detail(c.name + ' [' + shape + '] ' + JSON.stringify(c.a) + ' ' + op + ' '
          + JSON.stringify(c.b) + ' => ' + JSON.stringify(got)
          + ', expected ' + JSON.stringify(want) + ' (cmp ' + c.cmp + ')');
      }
      check('verdict ' + c.name + ' [' + shape + '] ' + op, got === want);
      digestLines.push(c.name + '|' + shape + '|' + op + '|' + (got ? '1' : '0'));
    }
  }

  // A string-path case is pinned to plain code-point order, computed independently.
  if (c.path === 'string') {
    check('string path is code-point order: ' + c.name, codePointCmp(c.a, c.b) === c.cmp);
  }

  // A floatTrap case only earns its keep while the old cast still disagrees.
  if (c.floatTrap) {
    const fc = floatCmp(c.a, c.b);
    if (fc === c.cmp) detail(c.name + ' no longer traps the float cast (both say ' + c.cmp + ')');
    check('float cast still disagrees: ' + c.name, fc !== c.cmp);
  }
}

// ---- the cross-runtime lock: PHP and JS hash the same verdicts ----
const digest = crypto.createHash('sha256').update(digestLines.join('\n'), 'utf8').digest('hex');
if (digest !== fx.digest) {
  console.error('  fixture digest: ' + fx.digest + '\n  js verdicts:    ' + digest
    + '\n  the JS comparator disagrees with the pinned verdicts; run php tests/numeric_php.php'
    + '\n  too — whichever runtime matches the fixture is the one that is right.');
}
check('verdict digest matches the fixture (JS == PHP)', digest === fx.digest);

console.log(`numeric_js: ${fx.cases.length} cases, ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
