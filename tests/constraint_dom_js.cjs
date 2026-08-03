/*
 * constraint_dom_js.cjs — the @UVASSERT constraint mode's DOM contract.
 *
 * Drives the real QRIDConstraintInit factory through the same DOM stub the
 * other *_dom tests use, and asserts:
 *   - a false assert flags the field (custom message, aria-invalid) and a HARD
 *     rule traps the save; a true assert clears it and allows the save,
 *   - an EMPTY validated field is inert (emptiness is @UVREQUIRED's job),
 *   - editing a REFERENCED field re-checks live,
 *   - constraints work on non-text fields (a <select> dropdown), read via
 *     QRID_WHEN.readRef and anchored via QRID_findAnchor,
 *   - a "when" gate makes the whole constraint inert while false,
 *   - MODE COMPOSITION: a check rule and a constraint rule on the SAME field
 *     both attach (no false duplicate) and keep INDEPENDENT block state — a
 *     passing constraint never clears a failing check's block, and vice versa,
 *   - branched constraints (two @UVASSERT with different "when"): active branch
 *     validates; both true -> conflict, never blocks,
 *   - a constraint with no condition is a visible config error.
 *
 * The assert evaluator itself is parity-locked by tests/when_js.cjs +
 * when_php.php; this file tests the constraint DOM wiring around it.
 *
 * Run:  node tests/constraint_dom_js.cjs
 */
'use strict';
const path = require('path');

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), id: '', name: '', value: '', innerHTML: '',
    type: '', checked: false,
    style: {}, _attrs: {}, children: [], parentNode: null, readOnly: false, disabled: false,
    _handlers: {},
    setAttribute(k, v) { this._attrs[k] = String(v); if (k === 'id') this.id = String(v); },
    getAttribute(k) { return (k in this._attrs) ? this._attrs[k] : (k === 'id' ? (this.id || null) : null); },
    removeAttribute(k) { delete this._attrs[k]; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev || {})); },
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
    insertBefore(node, ref) {
      node.parentNode = this;
      const i = ref ? this.children.indexOf(ref) : -1;
      if (i >= 0) this.children.splice(i, 0, node); else this.children.push(node);
      return node;
    },
    closest() { return null; },
    focus() { this._focused = true; },
    get nextSibling() {
      const i = this.parentNode ? this.parentNode.children.indexOf(this) : -1;
      return i >= 0 ? (this.parentNode.children[i + 1] || null) : null;
    },
    get firstChild() { return this.children[0] || null; },
  };
}

function boot(els, config) {
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  const allEls = [];
  const body = makeEl('body');
  const holders = {};
  for (const el of els) {
    const holder = makeEl('div');
    holder.appendChild(el);
    body.appendChild(holder);
    allEls.push(el, holder);
    holders[el.name] = holder;
  }
  const doc = {
    body, readyState: 'complete', _handlers: {},
    createElement(t) { const e = makeEl(t); allEls.push(e); return e; },
    getElementById(id) { return allEls.find((e) => e.id === id) || null; },
    getElementsByName(name) { return allEls.filter((e) => e.name === name); },
    querySelector() { return null; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev)); },
  };
  const win = {
    _alerts: [], alert(m) { this._alerts.push(m); }, confirm() { return true; },
    INSPIRE_VALIDATOR_CONFIG: config,
  };
  global.document = doc; global.window = win;
  const origError = console.error;
  const consoleErrors = [];
  console.error = (m) => consoleErrors.push(String(m));
  try { require(enginePath); } finally { console.error = origError; }
  return { doc, win, holders, allEls, NS: win.INSPIREUniversalValidator, consoleErrors };
}
/* the constraint's status region for a field: its holder's first inserted <div>
   (the region is inserted right after the input). */
function cMsg(env, field) {
  const kids = env.holders[field].children;
  for (let i = 1; i < kids.length; i++) if (kids[i].getAttribute && kids[i].id && /^uvalidate-msg-/.test(kids[i].id)) return kids[i];
  return kids[1];
}
function submitEv() {
  return { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
}

// ---- 1) basic text constraint: end >= start (hard block) -------------------
{
  const start = makeEl('input'); start.name = 'start'; start.value = '2024-01-10';
  const end = makeEl('input'); end.name = 'end'; end.value = '2024-01-05'; // end < start -> invalid
  const env = boot([start, end], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['end'], assert: '[end]>=[start]',
              message: 'End date must be on or after the start date', blockSave: 'hard' }],
  });
  const msg = cMsg(env, 'end');
  check('assert false: custom message shown', /on or after the start date/.test(msg.innerHTML));
  check('assert false: aria-invalid set', end.getAttribute('aria-invalid') === 'true');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('assert false: hard block traps the save', ev._prevented === true);

  // fix the end date -> valid
  end.value = '2024-01-20';
  end.fire('change');
  check('assert true: OK message', /OK/.test(msg.innerHTML) && !/must be on or after/.test(msg.innerHTML));
  check('assert true: aria-invalid cleared', end.getAttribute('aria-invalid') === 'false');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('assert true: save allowed', ev._prevented === false);

  // empty end -> inert (not @UVASSERT's job to require a value)
  end.value = '';
  end.fire('change');
  check('empty field: inert (no message)', msg.style.display === 'none');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('empty field: save never trapped', ev._prevented === false);
}

// ---- 2) live reaction to a referenced field --------------------------------
{
  const start = makeEl('input'); start.name = 'start'; start.value = '2024-06-01';
  const end = makeEl('input'); end.name = 'end'; end.value = '2024-05-01'; // end < start
  const env = boot([start, end], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['end'], assert: '[end]>=[start]', blockSave: 'hard' }],
  });
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('referenced-field: initially blocked', ev._prevented === true);
  // move START earlier so end >= start becomes true, without touching end
  start.value = '2024-01-01';
  start.fire('change');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('referenced-field change re-checks live -> unblocked', ev._prevented === false);
}

// ---- 3) constraint on a DROPDOWN (field-type extension) ---------------------
{
  const grade = makeEl('select'); grade.name = 'grade'; grade.value = '9'; // 9 = "unknown", disallowed
  const env = boot([grade], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['grade'], assert: "[grade]<>'9'",
              message: 'Please choose a real grade', blockSave: 'hard' }],
  });
  const msg = cMsg(env, 'grade');
  check('dropdown constraint attaches (anchored on <select>)', msg && msg.getAttribute('role') === 'status');
  check('dropdown assert false: flagged', /choose a real grade/.test(msg.innerHTML) && grade.getAttribute('aria-invalid') === 'true');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('dropdown assert false: blocked', ev._prevented === true);
  grade.value = '2';
  grade.fire('change');
  check('dropdown assert true: OK', /OK/.test(msg.innerHTML));
  ev = submitEv(); env.doc.fire('submit', ev);
  check('dropdown assert true: save allowed', ev._prevented === false);
}

// ---- 4) "when" gate: constraint inert while the gate is false ---------------
{
  const active = makeEl('select'); active.name = 'active'; active.value = '0';
  const end = makeEl('input'); end.name = 'end'; end.value = '2000-01-01';
  const start = makeEl('input'); start.name = 'start'; start.value = '2024-01-01';
  const env = boot([active, end, start], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['end'], assert: '[end]>=[start]',
              when: "[active]='1'", blockSave: 'hard' }],
  });
  const msg = cMsg(env, 'end');
  check('when false: constraint inert despite a violated assert', msg.style.display === 'none');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('when false: save never trapped', ev._prevented === false);
  active.value = '1';
  active.fire('change');
  check('when true: constraint now enforced', end.getAttribute('aria-invalid') === 'true');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('when true: hard block traps the save', ev._prevented === true);
}

// ---- 5) MODE COMPOSITION: check rule + constraint rule on the SAME field ----
{
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'AB1234';   // matches the check pattern
  const pid2 = makeEl('input'); pid2.name = 'pid2'; pid2.value = 'ZZ9999';
  const env = boot([pid, pid2], {
    singleFields: [], pooledFields: [],
    rules: [
      { type: 'single', fields: ['pid'], algorithm: 'none', idPattern: '[A-Z]{2}[0-9]{4}', blockSave: 'hard' },
      { type: 'constraint', fields: ['pid'], assert: '[pid]=[pid2]',
        message: 'The two IDs must match', blockSave: 'hard' },
    ],
  });
  // no "listed in more than one rule" config error should appear
  check('compose: no false duplicate notice', !env.allEls.some((e) => e.id === 'uvalidate-config-errors'));
  // check passes (pattern ok) but constraint fails (pid != pid2) -> blocked by constraint
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('compose: constraint independently blocks (check ok, assert bad)', ev._prevented === true);
  // make them match -> both pass -> allowed
  pid2.value = 'AB1234';
  pid2.fire('change');
  pid.fire('change');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('compose: both pass -> save allowed', ev._prevented === false);
  // break the CHECK (bad pattern) while keeping the assert satisfied (pid==pid2):
  // a passing constraint must NOT clear the check rule's block
  pid.value = 'XX'; pid2.value = 'XX';
  pid2.fire('change'); pid.fire('change');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('compose: passing constraint does NOT clear a failing check block', ev._prevented === true);
}

// ---- 6) branched constraints (two @UVASSERT, different "when") --------------
{
  const t = makeEl('select'); t.name = 't'; t.value = '1';
  const x = makeEl('input'); x.name = 'x'; x.value = '-5';
  const env = boot([t, x], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['x'],
      branches: [
        { assert: "[x]>'0'", when: "[t]='1'", blockSave: 'hard', message: 'Must be positive when t=1' },
        { assert: "[x]<'0'", when: "[t]='2'", blockSave: 'hard', message: 'Must be negative when t=2' },
      ] }],
  });
  const msg = cMsg(env, 'x');
  check('branch t=1: the "positive" branch is active and violated', /positive/.test(msg.innerHTML));
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('branch t=1: blocked', ev._prevented === true);
  t.value = '2';
  t.fire('change');
  check('branch t=2: the "negative" branch now applies and is satisfied (-5<0)', /OK/.test(msg.innerHTML));
  ev = submitEv(); env.doc.fire('submit', ev);
  check('branch t=2: save allowed', ev._prevented === false);
}

// ---- 7) constraint with no condition -> visible config error ---------------
{
  const x = makeEl('input'); x.name = 'x'; x.value = 'v';
  const env = boot([x], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['x'] }],
  });
  const holder = env.holders['x'];
  const cfg = holder.children.find((c) => c.id && /-cfg$/.test(c.id));
  check('no-assert constraint: config error region under the field', !!cfg && /condition/.test(cfg.innerHTML));
}

// ---- 8) generic fallback message when none is supplied ---------------------
{
  const a = makeEl('input'); a.name = 'a'; a.value = '5';
  const b = makeEl('input'); b.name = 'b'; b.value = '9';
  const env = boot([a, b], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['a'], assert: '[a]>=[b]', blockSave: 'off' }],
  });
  const msg = cMsg(env, 'a');
  check('no message: generic wording shown, still flagged', /fails its validation rule/.test(msg.innerHTML));
}

// ---- 9) CROSS-FORM, entitled viewer: the server bakes the off-page value in
//         as a ['lit', …] operand and the comparison stays LIVE. Only end_date
//         is in the DOM — start_date lives on another instrument. -----------
{
  const end = makeEl('input'); end.name = 'end_date'; end.value = '';
  const env = boot([end], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['end_date'], assert: '[end_date]>=[start_date]',
              assertAst: ['cmp', '>=', ['ref', 'end_date', null], ['lit', '2026-05-10']],
              message: 'End date must be on or after the start date', blockSave: 'hard' }],
  });
  const msg = cMsg(env, 'end_date');
  check('cross-form: blank field is inert', msg.style.display === 'none');

  // the regression that started this: typing a CORRECT value must clear
  end.value = '2026-06-01';
  end.fire('change');
  check('cross-form: a valid value shows OK (was falsely flagged before 1.6.0)',
    /OK/.test(msg.innerHTML));
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('cross-form: a correct save is allowed (was hard-blocked before 1.6.0)',
    ev._prevented === false);

  // and a wrong value must still be caught, live
  end.value = '2020-01-01';
  end.fire('change');
  check('cross-form: an invalid value is flagged live',
    /on or after the start date/.test(msg.innerHTML));
  ev = submitEv(); env.doc.fire('submit', ev);
  check('cross-form: a bad save is blocked', ev._prevented === true);

  // back and forth keeps working (the verdict is not frozen)
  end.value = '2026-07-07';
  end.fire('change');
  check('cross-form: verdict tracks the field both ways', /OK/.test(msg.innerHTML));
}

// ---- 10) DEFERRED (survey, or no rights to the referenced form): the server
//          could not disclose the value, so the client states no verdict and
//          never blocks. The post-save audit is the enforcement point. ------
{
  const end = makeEl('input'); end.name = 'end_date'; end.value = '';
  const env = boot([end], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['end_date'], assert: '[end_date]>=[start_date]',
              assertAst: ['const', false], deferred: true,
              message: 'End date must be on or after the start date', blockSave: 'hard' }],
  });
  const msg = cMsg(env, 'end_date');
  check('deferred: inert at load', msg.style.display === 'none');

  end.value = '2026-06-01';
  end.fire('change');
  check('deferred: a correct value is NOT falsely flagged', !/on or after/.test(msg.innerHTML));
  check('deferred: no verdict is stated at all', msg.style.display === 'none');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('deferred: the save is never blocked, whatever blockSave said',
    ev._prevented === false);

  // even a value the frozen const would have called invalid must not block
  end.value = '2020-01-01';
  end.fire('change');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('deferred: still never blocks (server audit enforces)', ev._prevented === false);
  check('deferred: aria-invalid is not asserted', end.getAttribute('aria-invalid') !== 'true');

  /* A deferred rule forces blockSave to "off" in makeVariant, so firstBlock is
     "off" and the factory registers NO save-guard entry for the field at all.
     inert() would keep the guard disarmed anyway, but that belt-and-braces is
     exactly why this needs its own assertion: without it, dropping the
     blockSave override is a silent no-op that leaves a phantom blocker in the
     guard for every deferred field on the page. */
  const items = (env.NS.guard && env.NS.guard.items) || [];
  check('deferred: registers no save-guard entry at all',
    items.filter((i) => i.__qridFieldName === 'end_date').length === 0);
}

// ---- 11) BRANCHED cross-form: deferral is per BRANCH, not per rule --------
//          branch t=1 references a field this viewer may not read (deferred);
//          branch t=2 references one they may (baked literal, live).
{
  const t = makeEl('input'); t.name = 't'; t.value = '1';
  const v = makeEl('input'); v.name = 'v'; v.value = '';
  const env = boot([t, v], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['v'], branches: [
      { when: "[t]='1'", assert: '[v]=[secret]', assertAst: ['const', false], deferred: true,
        message: 'secret branch', blockSave: 'hard' },
      { when: "[t]='2'", assert: '[v]=[open]',
        assertAst: ['cmp', '=', ['ref', 'v', null], ['lit', 'OPENVAL']],
        message: 'open branch', blockSave: 'hard' },
    ] }],
  });
  const msg = cMsg(env, 'v');

  // branch 1 active (deferred): anything typed is inert and never blocks
  v.value = 'ANYTHING'; v.fire('change');
  check('branch deferral: the deferred branch states no verdict',
    msg.style.display === 'none');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('branch deferral: the deferred branch never blocks', ev._prevented === false);

  // switch to branch 2 (live): the same field now validates for real
  t.value = '2'; t.fire('change');
  v.value = 'WRONGVAL'; v.fire('change');
  check('branch deferral: the live branch flags a wrong value',
    /open branch/.test(msg.innerHTML));
  ev = submitEv(); env.doc.fire('submit', ev);
  check('branch deferral: the live branch DOES block', ev._prevented === true);

  v.value = 'OPENVAL'; v.fire('change');
  check('branch deferral: the live branch clears on the right value', /OK/.test(msg.innerHTML));
  ev = submitEv(); env.doc.fire('submit', ev);
  check('branch deferral: and allows the save', ev._prevented === false);

  // back to the deferred branch — the block must not linger from branch 2
  t.value = '1'; t.fire('change');
  v.value = 'WRONGVAL'; v.fire('change');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('branch deferral: switching back to deferred releases the block',
    ev._prevented === false);
}

// ---- 12) a DEFERRED constraint must not disarm a COMPOSED check rule ------
//          modes compose and keep independent block state: deferring the
//          constraint must not buy the check rule a free pass.
{
  const f = makeEl('input'); f.name = 'idf'; f.value = '';
  const env = boot([f], {
    singleFields: [], pooledFields: [],
    rules: [
      { type: 'constraint', fields: ['idf'], assert: '[idf]=[secret]',
        assertAst: ['const', false], deferred: true,
        message: 'deferred constraint', blockSave: 'hard' },
      // pattern-only (algorithm "none") so this half of the test turns purely
      // on the composed rule being satisfiable, not on check-character
      // arithmetic that a future scheme change could invalidate.
      { type: 'single', fields: ['idf'], algorithm: 'none',
        idPattern: '[1-8][A-Z]{3}-[0-9A-Z]{6}', blockSave: 'hard' },
    ],
  });
  f.value = 'NOT-A-VALID-ID'; f.fire('change');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('compose: a deferred constraint does NOT release the check rule\'s block',
    ev._prevented === true);

  // The discriminating half: with the pattern satisfied, the ONLY thing that
  // could still hold the save is the deferred constraint (frozen const false).
  // If deferral regressed this would block, so this half fails where the first
  // half cannot.
  f.value = '8QRS-55555E'; f.fire('change');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('compose: with the check rule satisfied, the deferred constraint alone never blocks',
    ev._prevented === false);
}

console.log(`constraint_dom_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
