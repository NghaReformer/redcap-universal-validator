/*
 * exists_return_dom_js.cjs — @UVEXISTS "return" on the page.
 *
 * A lookup that finds the value fills fields of this form from the entry it
 * found. Drives the real QRIDExistsInit factory through a DOM stub with a
 * stubbed transport and fake timers, and asserts:
 *   - a found answer writes the values the server sent into each kind of
 *     field (Text, a date in the field's order, a number, a dropdown with its
 *     autocomplete companion, a radio), the fill made as the page opens
 *     without a single event (the form is not marked changed), later fills
 *     with input and change, as from typing;
 *   - a number field holding the same number spelled another way is left
 *     alone, a Text field is written exactly ("007" is not "7");
 *   - not-found, a blank value, a Missing Data Code, a "when" that is false
 *     and a branch without "return" empty the fields; unknown, an error, a
 *     branch conflict, a deferred rule and a found answer that copied nothing
 *     (several entries that disagree, one outside the user's Data Access
 *     Group: an amber line says so) leave them as they are;
 *   - a branched rule owns every branch's fields: the picked branch fills its
 *     own and empties the others';
 *   - a cached answer fills again without asking, a late answer to an older
 *     request fills nothing;
 *   - a lookup on its way holds the save even with "blockSave":"off";
 *   - a write that changes the field another lookup searches asks that
 *     lookup, within the depth bound;
 *   - a survey page never fills.
 *
 * The server twin is tests/exists_return_php.php (existsCheck "values",
 * returnsExists); the writer itself is in tests/growth_return_dom_js.cjs.
 *
 * Run:  node tests/exists_return_dom_js.cjs
 */
'use strict';
const path = require('path');
const PAGE_DEFAULTS = require('./page_defaults.json').defaults;

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
    _handlers: {}, _events: [],
    setAttribute(k, v) { this._attrs[k] = String(v); if (k === 'id') this.id = String(v); },
    getAttribute(k) { return (k in this._attrs) ? this._attrs[k] : (k === 'id' ? (this.id || null) : null); },
    removeAttribute(k) { delete this._attrs[k]; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev || {})); },
    /* what a real element does with an Event the page makes: the handlers hear it, and it is on record */
    dispatchEvent(ev) { this._events.push(ev.type); this.fire(ev.type, ev); return true; },
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

const realSetTimeout = global.setTimeout, realClearTimeout = global.clearTimeout;
let timers = [], timerSeq = 0;
global.setTimeout = (fn, ms) => { const id = ++timerSeq; timers.push({ id, fn, ms: ms || 0 }); return id; };
global.clearTimeout = (id) => { timers = timers.filter((t) => t.id !== id); };
function flush() { const due = timers.filter((t) => t.ms < 5000); timers = timers.filter((t) => t.ms >= 5000); due.forEach((t) => t.fn()); }
function flushAll() { const due = timers; timers = []; due.forEach((t) => t.fn()); }

/* Transport stub. stub.next: a reply, 'ERROR' or 'HANG'; stub.byValue: value of the searched field -> reply. */
function makeTransportStub() {
  const stub = { calls: [], next: { state: 'found', record: null }, byValue: null };
  stub.obj = {
    ajax(action, payload) {
      stub.calls.push({ action, payload: JSON.parse(JSON.stringify(payload)) });
      const v = payload.values[payload.field];
      const resp = (stub.byValue && Object.prototype.hasOwnProperty.call(stub.byValue, v)) ? stub.byValue[v] : stub.next;
      return {
        then(res, rej) {
          if (resp === 'HANG') return;
          if (resp === 'ERROR') { rej(new Error('network')); return; }
          res(JSON.parse(JSON.stringify(resp)));
        },
      };
    },
  };
  return stub;
}
function makeDeferredStub() {
  const stub = { calls: [], pending: [] };
  stub.obj = {
    ajax(action, payload) {
      stub.calls.push({ action, payload: JSON.parse(JSON.stringify(payload)) });
      return { then(res, rej) { stub.pending.push({ res, rej }); } };
    },
  };
  stub.answer = (i, r) => { const p = stub.pending.splice(i, 1)[0]; p.res(JSON.parse(JSON.stringify(r))); };
  return stub;
}

const JSMO = 'EMStub.UV';
function boot(els, config, transportStub) {
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
    if (!holders[el.name]) holders[el.name] = holder;
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
    _alerts: [], _calc: [], _branch: [],
    alert(m) { this._alerts.push(m); }, confirm() { return true; },
    calculate(f) { this._calc.push(f); }, doBranching(f) { this._branch.push(f); },
    INSPIRE_VALIDATOR_CONFIG: Object.assign({ singleFields: [], pooledFields: [] }, PAGE_DEFAULTS, { jsmoName: JSMO }, config),
  };
  if (transportStub) win.EMStub = { UV: transportStub.obj };
  global.document = doc; global.window = win;
  require(enginePath);
  return { doc, win, holders, allEls };
}
function xMsg(env, field) {
  return env.allEls.find((e) => e.id && /^uvalidate-msg-/.test(e.id) && /-x$/.test(e.id) && e.id.indexOf(field) >= 0) || null;
}
function saved(env) {
  const ev = { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
  env.doc.fire('submit', ev);
  return !ev._prevented;
}
function el(name, value) { const e = makeEl('input'); e.name = name; e.value = value; return e; }
function select(name, codes, value) {
  const s = makeEl('select'); s.name = name; s.value = value || '';
  s.options = codes.map(([v, t]) => ({ value: v, text: t }));
  return s;
}
/* The fields one rule fills, one of each kind, and the field it searches. */
function form(spec, held) {
  const h = held || {};
  const f = {
    spec: el('spec', spec),
    site: select('r_site', [['', ''], ['1', 'North'], ['2', 'South']], h.r_site),
    comp: el('r_site_ac', h.comp || ''),
    date: el('r_date', h.r_date || ''),
    dose: el('r_dose', h.r_dose || ''),
    from: el('r_from', h.r_from || ''),
    armMirror: el('r_arm', h.r_arm || ''),
    arms: ['1', '2', '3'].map((c) => { const r = el('r_arm___radio', c); r.type = 'radio'; r.checked = h.r_arm === c; return r; }),
  };
  f.comp.setAttribute('id', 'rc-ac-input_r_site');
  f.armMirror.type = 'hidden';
  f.list = [f.spec, f.site, f.comp, f.date, f.dose, f.from, f.armMirror].concat(f.arms);
  f.targets = [f.site, f.date, f.dose, f.from, f.armMirror];
  f.events = () => f.targets.concat(f.arms).reduce((a, e) => a + e._events.length, 0);
  f.clearEvents = () => f.targets.concat(f.arms).forEach((e) => { e._events = []; });
  f.snapshot = () => [f.site.value, f.comp.value, f.date.value, f.dose.value, f.from.value, f.armMirror.value,
    f.arms.map((r) => r.checked ? 'x' : '-').join('')].join('|');
  return f;
}
const FILLS = ['r_site', 'r_date', 'r_dose', 'r_from', 'r_arm'];
const rule = (extra) => Object.assign({ type: 'exists', fields: ['spec'], existsReturnFields: FILLS,
  existsReturnNumbers: ['r_dose'], blockSave: 'off' }, extra || {});
const FOUND_1 = { state: 'found', record: null, values: { r_site: '2', r_date: '01-03-2026', r_dose: '7.5', r_from: '1', r_arm: '3' } };
const FOUND_2 = { state: 'found', record: null, values: { r_site: '1', r_date: '02-04-2026', r_dose: '8', r_from: '2', r_arm: '1' } };

// ---- 1) found: every kind of field, quiet as the page opens, heard afterwards --------------------
{
  const f = form('SP-1');
  const stub = makeTransportStub();
  stub.byValue = { 'SP-1': FOUND_1, 'SP-2': FOUND_2, 'SP-404': { state: 'not-found' } };
  const env = boot(f.list, { rules: [rule()] }, stub);
  check('page open: asked once', stub.calls.length === 1 && stub.calls[0].payload.values.spec === 'SP-1');
  check('page open: every field filled (got ' + f.snapshot() + ')', f.snapshot() === '2|South|01-03-2026|7.5|1|3|--x');
  check('page open: not one event, so the form is not marked changed', f.events() === 0);
  check('page open: calculations and branching still hear the fill', env.win._calc.includes('r_dose') && env.win._branch.includes('r_arm'));
  check('found: the green note', /Found/.test(xMsg(env, 'spec').innerHTML));
  f.spec.value = 'SP-2'; f.spec.fire('change');
  check('a new value: the fields take its entry (got ' + f.snapshot() + ')', f.snapshot() === '1|North|02-04-2026|8|2|1|x--');
  check('...and REDCap hears input and change, as from typing', f.from._events.join(',') === 'input,change'
    && f.site._events.join(',') === 'input,change' && f.arms[0]._events.includes('change'));
  f.clearEvents();
  f.spec.value = 'SP-404'; f.spec.fire('change');
  check('not-found: every field emptied (got ' + f.snapshot() + ')', f.snapshot() === '||||||---');
  check('...heard too', f.from._events.join(',') === 'input,change');
  check('not-found: the red note', /not saved/.test(xMsg(env, 'spec').innerHTML));
  f.spec.value = 'SP-1'; f.spec.fire('change');
  f.spec.value = ''; f.spec.fire('change');
  check('a blank value: emptied, nothing asked (got ' + f.snapshot() + ' after ' + stub.calls.length + ' requests)', f.snapshot() === '||||||---' && stub.calls.length === 3);
  f.spec.value = 'SP-2'; f.spec.fire('change');
  check('a cached answer fills again without asking (got ' + f.snapshot() + ' after ' + stub.calls.length + ' requests)', f.snapshot() === '1|North|02-04-2026|8|2|1|x--' && stub.calls.length === 3);
}

// ---- 2) numbers and exact text ---------------------------------------------------------------------
{
  const f = form('SP-1', { r_dose: '7.50', r_from: '01' });
  const stub = makeTransportStub();
  stub.next = { state: 'found', values: { r_site: '2', r_date: '01-03-2026', r_dose: '7.5', r_from: '1', r_arm: '3' } };
  boot(f.list, { rules: [rule()] }, stub);
  check('a number field holding the same number spelled another way is left alone', f.dose.value === '7.50');
  check('a Text field is written exactly: "01" becomes "1"', f.from.value === '1');
}

// ---- 3) answers that leave the fields as they are ----------------------------------------------------
const HELD = { r_site: '1', comp: 'North', r_date: '09-09-2020', r_dose: '1.0', r_from: 'old', r_arm: '2' };
const HELD_SNAP = '1|North|09-09-2020|1.0|old|2|-x-';
for (const [label, reply, note] of [
  ['unknown', { state: 'unknown', why: 'the saved values could not be read just now' }, /Could not check/],
  ['a transport error', 'ERROR', /Could not check/],
  ['several entries that disagree', { state: 'found', record: null, fill: 'conflict', count: 3 }, /3 saved entries hold this value with different values to copy/],
  ['an entry outside the user\'s group', { state: 'found', record: null, fill: 'withheld' }, /outside your Data Access Group/],
  ['a found answer without values', { state: 'found', record: null }, /Found/],
]) {
  const f = form('SP-1', HELD);
  const stub = makeTransportStub();
  stub.next = reply;
  const env = boot(f.list, { rules: [rule()] }, stub);
  check(label + ': the fields keep what they hold (got ' + f.snapshot() + ')', f.snapshot() === HELD_SNAP && f.events() === 0);
  check(label + ': the note says so', note.test(xMsg(env, 'spec').innerHTML));
}
{
  const f = form('SP-1', HELD);
  const stub = makeTransportStub();
  stub.next = { state: 'found', record: null, fill: 'conflict', count: 2 };
  const env = boot(f.list, { rules: [rule()] }, stub);
  const html = xMsg(env, 'spec').innerHTML;
  check('a disagreement is a green "Found" with an amber line under it', /^&#10003; Found\./.test(html) && /#7a5c00/.test(html) && /2 saved entries/.test(html));
  check('...and never blocks', saved(env));
}
{
  const f = form('SP-1', HELD);
  const stub = makeTransportStub();
  stub.next = { state: 'found', record: null, values: { r_from: '<b>x</b>' }, fill: 'conflict', count: 2 };
  boot(f.list, { rules: [rule()] }, stub);
  check('values that come with a "fill" reason are not written', f.from.value === 'old');
}
{
  const f = form('SP-1', HELD);
  const stub = makeTransportStub();
  stub.next = { state: 'found', record: null, values: { r_from: 7, r_date: null, r_site: '2' } };
  boot(f.list, { rules: [rule()] }, stub);
  check('a value that is not text is not written; the others are', f.from.value === 'old' && f.date.value === '09-09-2020' && f.site.value === '2');
}
{
  const f = form('SP-1', HELD);
  const stub = makeTransportStub();
  stub.next = FOUND_1;
  boot(f.list, { rules: [rule({ deferred: true, deferredWhy: ['[x] is on another form.'] })] }, stub);
  check('a deferred rule leaves the fields and asks nothing', f.snapshot() === HELD_SNAP && stub.calls.length === 0);
}
{
  const f = form('SP-1', HELD);
  const stub = makeTransportStub();
  stub.next = FOUND_1;
  boot(f.list, { rules: [rule({ deferred: true, deferredWhy: ['[x] is on another form.'] })], dynamicDeferred: true }, stub);
  check('a rule the page cannot judge leaves the fields', f.snapshot() === HELD_SNAP);
}

// ---- 4) "when", Missing Data Codes, branches ---------------------------------------------------------------
{
  const f = form('SP-1', HELD);
  const k = select('k', [['1', 'one'], ['2', 'two']], '1');
  const stub = makeTransportStub();
  stub.next = FOUND_1;
  boot(f.list.concat([k]), { rules: [rule({ when: "[k]='2'" })] }, stub);
  check('"when" false at page open: emptied quietly, nothing asked', f.snapshot() === '||||||---' && f.events() === 0 && stub.calls.length === 0);
  k.value = '2'; k.fire('change'); flush();
  check('"when" true: asked and filled', stub.calls.length === 1 && f.snapshot() === '2|South|01-03-2026|7.5|1|3|--x');
  k.value = '1'; k.fire('change'); flush();
  check('"when" false again: emptied', f.snapshot() === '||||||---');
}
{
  const f = form('NA', HELD);
  const stub = makeTransportStub();
  boot(f.list, { rules: [rule()], missingCodes: ['NA'] }, stub);
  check('a Missing Data Code: emptied, nothing asked', f.snapshot() === '||||||---' && stub.calls.length === 0);
}
{
  const f = form('SP-1', HELD);
  const k = select('k', [['A', 'a'], ['B', 'b'], ['C', 'c']], 'A');
  const stub = makeTransportStub();
  stub.next = FOUND_1;
  boot(f.list.concat([k]), { rules: [{ type: 'exists', fields: ['spec'], branches: [
    { when: "[k]='A'", blockSave: 'off', existsReturnFields: ['r_from'] },
    { when: "[k]='B'", blockSave: 'off', existsReturnFields: ['r_date'] },
    { when: "[k]='C'", blockSave: 'off' }] }] }, stub);
  check('branch A: its field filled, the other branches\' fields emptied', f.from.value === '1' && f.date.value === '');
  check('...a field no branch fills is left', f.site.value === '1' && f.dose.value === '1.0');
  k.value = 'B'; k.fire('change'); flush();
  check('branch B: its field filled, branch A\'s emptied', f.from.value === '' && f.date.value === '01-03-2026');
  stub.next = 'HANG';
  k.value = 'C'; k.fire('change'); flush();
  check('a branch without "return": every owned field emptied before any answer', f.from.value === '' && f.date.value === '');
}
{
  const f = form('SP-1', HELD);
  const a = select('a', [['1', 'y']], '1'), b = select('b', [['1', 'y']], '1');
  const stub = makeTransportStub();
  stub.next = FOUND_1;
  boot(f.list.concat([a, b]), { rules: [{ type: 'exists', fields: ['spec'], branches: [
    { when: "[a]='1'", blockSave: 'off', existsReturnFields: ['r_from'] },
    { when: "[b]='1'", blockSave: 'off', existsReturnFields: ['r_date'] }] }] }, stub);
  check('a branch conflict leaves the fields', f.from.value === 'old' && f.date.value === '09-09-2020' && stub.calls.length === 0);
}

// ---- 5) the save waits for the values; stale answers -----------------------------------------------------------
{
  const f = form('SP-1');
  const stub = makeTransportStub();
  stub.next = 'HANG';
  const env = boot(f.list, { rules: [rule({ blockSave: 'off' })] }, stub);
  check('a lookup on its way holds the save, even with "blockSave":"off"', !saved(env)
    && /still being checked/.test(env.win._alerts.slice(-1)[0] || ''));
  flushAll();
  check('...until it counts as "could not check"', saved(env));
}
{
  const f = form('SP-1', HELD);
  const stub = makeDeferredStub();
  boot(f.list, { rules: [rule()] }, stub);
  f.spec.value = 'SP-2'; f.spec.fire('change');
  stub.answer(0, FOUND_1);
  check('a late answer to an older request fills nothing', f.snapshot() === HELD_SNAP);
  stub.answer(0, FOUND_2);
  check('...the latest one fills, heard', f.from.value === '2' && f.from._events.join(',') === 'input,change');
}
{
  const f = form('SP-1', HELD);
  const stub = makeDeferredStub();
  boot(f.list, { rules: [rule()] }, stub);
  stub.answer(0, FOUND_1);
  check('the answer to the page-open request fills quietly, whenever it lands', f.from.value === '1' && f.events() === 0);
}

// ---- 6) a write sets off another lookup; surveys ------------------------------------------------------------------
{
  const f = form('SP-1');
  const stub = makeTransportStub();
  stub.byValue = { 'SP-1': FOUND_1, 'SP-2': FOUND_2 };
  boot(f.list, { rules: [rule(), { type: 'exists', fields: ['r_from'], blockSave: 'off' }] }, stub);
  const before = stub.calls.filter((c) => c.payload.field === 'r_from').length;
  f.spec.value = 'SP-2'; f.spec.fire('change');
  const after = stub.calls.filter((c) => c.payload.field === 'r_from');
  check('a field another lookup searches, once written, is asked about', after.length === before + 1 && after[after.length - 1].payload.values.r_from === '2');
}
{
  const f = form('SP-1', HELD);
  const stub = makeTransportStub();
  stub.next = FOUND_1;
  boot(f.list, { rules: [rule()], context: 'survey' }, stub);
  check('a survey page: inert, nothing asked, nothing filled', stub.calls.length === 0 && f.snapshot() === HELD_SNAP);
}
{
  const f = form('SP-1', HELD);
  const stub = makeTransportStub();
  stub.next = FOUND_1;
  boot(f.list, { rules: [rule({ existsSurveys: true })], context: 'survey' }, stub);
  check('a survey page fills nothing even when the rule is open to surveys', f.snapshot() === HELD_SNAP);
  f.spec.value = ''; f.spec.fire('change');
  check('...nor empties', f.snapshot() === HELD_SNAP);
}
{
  const f = form('SP-1', HELD);
  const stub = makeTransportStub();
  stub.next = FOUND_1;
  const env = boot([f.spec, f.from], { rules: [rule()] }, stub);
  check('fields this page does not carry are skipped', f.from.value === '1' && /Found/.test(xMsg(env, 'spec').innerHTML));
}

global.setTimeout = realSetTimeout; global.clearTimeout = realClearTimeout; console.error = realConsoleError;
console.log(`exists_return_dom_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
