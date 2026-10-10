/*
 * missing_codes_dom_js.cjs — REDCap Missing Data Codes in the browser.
 *
 * A field marked with one of the project's missing data codes (the "M"
 * button, or an imported value) holds the code itself. REDCap does not
 * validate it, and every factory whose mode says "missingCodes":"skip" in
 * php/modes.json treats it as a blank field: no note, no block, no lookup.
 * @UVREQUIRED ("answer") counts it as filled in. The codes reach the page as
 * the top-level "missingCodes" list.
 *
 * For each factory the same field is first judged with an ordinary bad value
 * (so the factory is known to be checking), then holds a code (nothing), then
 * a bad value again (checked again). Without "missingCodes" in the config a
 * code is an ordinary value.
 *
 * The server twin is tests/missing_codes_php.php.
 *
 * Run:  node tests/missing_codes_dom_js.cjs
 */
'use strict';
const path = require('path');

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

function makeStyle() {
  const st = {};
  Object.defineProperty(st, 'cssText', {
    set(v) { const m = /(?:^|;)\s*display\s*:\s*([a-z-]+)/i.exec(String(v)); st.display = m ? m[1] : ''; },
    get() { return ''; },
  });
  return st;
}
function detach(node) {
  const p = node.parentNode;
  const i = p ? p.children.indexOf(node) : -1;
  if (i >= 0) p.children.splice(i, 1);
}
function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), id: '', name: '', value: '', innerHTML: '',
    type: '', checked: false,
    style: makeStyle(), _attrs: {}, children: [], parentNode: null, readOnly: false, disabled: false,
    _handlers: {},
    setAttribute(k, v) { this._attrs[k] = String(v); if (k === 'id') this.id = String(v); },
    getAttribute(k) { return (k in this._attrs) ? this._attrs[k] : (k === 'id' ? (this.id || null) : null); },
    removeAttribute(k) { delete this._attrs[k]; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev || {})); },
    appendChild(c) { detach(c); c.parentNode = this; this.children.push(c); return c; },
    insertBefore(node, ref) {
      detach(node);
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

const realConsoleError = console.error;
console.error = (m) => { if (/^FAIL: /.test(String(m))) realConsoleError(m); };

let timers = [], timerSeq = 0;
global.setTimeout = (fn, ms) => { const id = ++timerSeq; timers.push({ id, fn, ms: ms || 0 }); return id; };
global.clearTimeout = (id) => { timers = timers.filter((t) => t.id !== id); };
global.setInterval = () => 0;
global.clearInterval = () => {};
function flush() { const due = timers.filter((t) => t.ms < 5000); timers = timers.filter((t) => t.ms >= 5000); due.forEach((t) => t.fn()); }

function makeTransportStub(reply) {
  const stub = { calls: [] };
  stub.obj = {
    ajax(action, payload) {
      stub.calls.push({ action, payload: JSON.parse(JSON.stringify(payload)) });
      return { then(res) { res(JSON.parse(JSON.stringify(reply))); } };
    },
  };
  return stub;
}

function boot(els, config, stub) {
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  timers = [];
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
    body, readyState: 'complete', _handlers: {}, activeElement: null,
    createElement(t) { const e = makeEl(t); allEls.push(e); return e; },
    getElementById(id) { return allEls.find((e) => e.id === id) || null; },
    getElementsByName(name) { return allEls.filter((e) => e.name === name); },
    querySelector() { return null; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev)); },
  };
  const win = {
    _alerts: [], _confirms: [],
    alert(m) { this._alerts.push(m); }, confirm(m) { this._confirms.push(m); return false; },
    INSPIRE_VALIDATOR_CONFIG: Object.assign({ singleFields: [], pooledFields: [] }, config),
  };
  if (stub) win.EMStub = { UV: stub.obj };
  global.document = doc; global.window = win;
  require(enginePath);
  return { doc, win, holders, allEls };
}
function input(name, value) { const e = makeEl('input'); e.name = name; e.value = value; return e; }
/* Submit; true when the save went through without a block or a question. */
function saves(env) {
  const a = env.win._alerts.length, c = env.win._confirms.length;
  const ev = { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
  env.doc.fire('submit', ev);
  return !ev._prevented && env.win._alerts.length === a && env.win._confirms.length === c;
}
/* Any visible module message under the field. */
function noted(env, field) {
  return env.holders[field].children.slice(1).some((k) => k.style.display !== 'none' && k.innerHTML !== '');
}
function set(env, el, value) { el.value = value; el.fire('input'); el.fire('change'); el.fire('blur'); flush(); }

const CODES = { missingCodes: ['UNK', 'NASK', '-99'] };

// One block per factory: [label, element names and values, rule, bad value, field].
const CASES = [
  ['check (@UVALIDATE single)', { type: 'single', fields: ['study_id'], algorithm: 'iso7064_mod37_36', blockSave: 'hard' }, 'study_id', 'BAD-ID-1'],
  ['pooled (@UVALIDATE pooled)', { type: 'pooled', fields: ['pool'], algorithm: 'iso7064_mod37_36', blockSave: 'hard' }, 'pool', 'zz'],
  ['constraint (@UVASSERT)', { type: 'constraint', fields: ['dose'], assert: '[dose]<=10', blockSave: 'hard' }, 'dose', '50'],
  ['range (@UVRANGE)', { type: 'range', fields: ['wt'], rangeHardLo: '0', rangeHardHi: '250', rangeHardText: '0 to 250' }, 'wt', '-5'],
];
for (const [label, rule, field, bad] of CASES) {
  for (const code of ['UNK', '-99', ' NASK ']) {
    const el = input(field, code);
    const env = boot([el], Object.assign({ rules: [rule] }, CODES));
    check(label + ': ' + JSON.stringify(code) + ' on load is not judged', !noted(env, field) && el.getAttribute('aria-invalid') !== 'true');
    check(label + ': ' + JSON.stringify(code) + ' on load does not hold the save', saves(env));
    const reg = env.win.INSPIREUniversalValidator && env.win.INSPIREUniversalValidator.validators;
    if (rule.type === 'constraint' || rule.type === 'range') {
      check(label + ': the field test answers null for ' + JSON.stringify(code), reg && reg[field] && reg[field].test() === null);
    }
    set(env, el, bad);
    check(label + ': ' + JSON.stringify(bad) + ' is judged', noted(env, field) && !saves(env));
    set(env, el, code);
    check(label + ': back to ' + JSON.stringify(code) + ' clears the verdict', !noted(env, field) && saves(env));
  }
  // A code is a code only for a project that has it.
  const el = input(field, '-99');
  const env = boot([el], { rules: [rule], missingCodes: ['UNK'] });
  if (label.indexOf('range') === 0) {
    check(label + ': -99 is an ordinary value where it is not a code', noted(env, field) && !saves(env));
  }
  const el2 = input(field, 'unk');
  const env2 = boot([el2], Object.assign({ rules: [rule] }, CODES));
  check(label + ': codes are case-sensitive ("unk" is judged)', noted(env2, field));
}

// @UVWINDOW: a code is not a date, and is not reported as one.
{
  const el = input('visit', 'UNK');
  const env = boot([el], Object.assign({ clock: { today: '2026-10-10', now: '2026-10-10 12:00:00' },
    rules: [{ type: 'window', fields: ['visit'], windowNotFuture: true, dateType: 'date', dateFormat: 'ymd', blockSave: 'hard' }] }, CODES));
  check('window: a code says nothing', !noted(env, 'visit') && saves(env));
  set(env, el, '2027-01-01');
  check('window: a future date is judged', noted(env, 'visit') && !saves(env));
  set(env, el, 'UNK');
  check('window: back to the code clears it', !noted(env, 'visit') && saves(env));
  const t = env.win.INSPIREUniversalValidator && env.win.INSPIREUniversalValidator.validators
    ? env.win.INSPIREUniversalValidator.validators.visit : null;
  check('window: the field test answers null for a code', !t || t.test() === null);
}

// @UVWINDOW: a "from" date holding a code is like a blank one, on the page or baked in.
for (const from of ['live', 'snapshot']) {
  const visit = input('visit', '2026-03-01');
  const els = [visit];
  let rule = { type: 'window', fields: ['visit'], windowFrom: '[bl]', windowLo: 0, windowHi: 30, windowUnit: 'days',
    dateType: 'date', dateFormat: 'ymd', fromType: 'date', fromFormat: 'ymd', blockSave: 'hard' };
  let bl = null;
  if (from === 'live') { bl = input('bl', 'UNK'); els.push(bl); rule.windowFromOp = ['ref', 'bl', null]; }
  else { rule.windowFromOp = ['lit', 'UNK']; rule.snapshotFields = ['bl']; }
  const env = boot(els, Object.assign({ rules: [rule] }, CODES));
  check('window, ' + from + ' "from" holding a code: nothing to count from', !noted(env, 'visit') && saves(env));
  if (bl) {
    set(env, bl, '2026-01-01');
    check('window, live "from" entered: judged', noted(env, 'visit') && !saves(env));
  }
}

// @UVUNIQUE: a code is never sent to the server.
{
  const stub = makeTransportStub({ used: true, record: '7' });
  const el = input('pid', 'UNK');
  const env = boot([el], Object.assign({ jsmoName: 'EMStub.UV', rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }] }, CODES), stub);
  flush();
  check('unique: a code on load asks nothing', stub.calls.length === 0 && saves(env));
  set(env, el, 'P-001');
  check('unique: an ordinary value is asked about', stub.calls.length === 1 && noted(env, 'pid'));
  set(env, el, '-99');
  check('unique: a code clears the answer and asks nothing', stub.calls.length === 1 && !noted(env, 'pid') && saves(env));
}

// @UVUNIQUE: an answer still on its way when the field turns to a code (or
// blank) is for a value the field no longer holds, and says nothing.
for (const after of ['UNK', '']) {
  const pending = [];
  const stub = { calls: [], obj: { ajax(action, payload) {
    stub.calls.push({ action, payload });
    return { then(res) { pending.push(() => res({ used: true, record: '7' })); } };
  } } };
  const el = input('pid', '');
  const env = boot([el], Object.assign({ jsmoName: 'EMStub.UV', rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }] }, CODES), stub);
  el.value = 'P-001'; el.fire('change'); el.fire('blur');
  el.value = after; el.fire('input'); el.fire('change'); el.fire('blur');
  pending.forEach((answer) => answer()); flush();
  check('unique: a late answer for P-001 says nothing once the field holds ' + JSON.stringify(after),
    stub.calls.length === 1 && !noted(env, 'pid') && saves(env));
}

// The ID check's field test answers null for a code, plain or branched.
{
  const el = input('study_id', 'UNK');
  const env = boot([el], Object.assign({ rules: [{ type: 'single', fields: ['study_id'], algorithm: 'iso7064_mod37_36', blockSave: 'hard' }] }, CODES));
  const t = env.win.INSPIREUniversalValidator.validators.study_id;
  check('check: the field test answers null for a code', t.test('UNK') === null && t.test(' NASK ') === null);
  check('check: the field test still judges a bad ID', t.test('BAD-ID-1') !== null && t.test('BAD-ID-1').ok === false);
}
{
  const sex = input('sex', '1');
  const el = input('study_id', 'UNK');
  const env = boot([sex, el], Object.assign({ rules: [{ type: 'single', fields: ['study_id'], branches: [
    { when: "[sex]='1'", algorithm: 'iso7064_mod37_36', blockSave: 'hard' },
    { when: "[sex]='2'", algorithm: 'iso7064_mod37_36', blockSave: 'hard' }] }] }, CODES));
  const t = env.win.INSPIREUniversalValidator.validators.study_id;
  check('check, branched: each branch test answers null for a code',
    t.branch === true && t.branches.every((b) => b.test('UNK') === null && b.test('BAD-ID-1') !== null));
}

// @UVEXISTS: neither a code in the field nor a code in a "match" field is looked up.
{
  const stub = makeTransportStub({ state: 'not-found', record: null });
  const spec = input('spec', 'NASK');
  const site = input('site', '2');
  const rule = { type: 'exists', fields: ['spec'], existsLocal: ['site'], blockSave: 'hard' };
  const env = boot([spec, site], Object.assign({ jsmoName: 'EMStub.UV', rules: [rule] }, CODES), stub);
  flush();
  check('exists: a code on load asks nothing', stub.calls.length === 0 && saves(env));
  set(env, spec, 'S-100');
  check('exists: an ordinary value is looked up', stub.calls.length === 1 && noted(env, 'spec'));
  // A fresh "not found" decides the save (an older one would be asked again).
  check('exists: ...and blocks the save', !saves(env) && stub.calls.length === 1);
  set(env, site, 'UNK'); set(env, spec, 'S-101');
  check('exists: a code in the match field is like a blank one', stub.calls.length === 1 && saves(env));
}

// @UVREQUIRED counts a code as an answer (php/modes.json "missingCodes":"answer").
{
  const el = input('consent_date', 'UNK');
  const env = boot([el], Object.assign({ rules: [{ type: 'required', fields: ['consent_date'], blockSave: 'hard' }] }, CODES));
  check('required: a code is an answer', saves(env));
  set(env, el, '');
  check('required: blank is still missing', !saves(env));
}

console.log('missing_codes_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
