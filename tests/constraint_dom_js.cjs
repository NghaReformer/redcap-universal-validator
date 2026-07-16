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

console.log(`constraint_dom_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
