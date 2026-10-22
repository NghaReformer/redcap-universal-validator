/*
 * unique_dom_js.cjs — the @UVUNIQUE unique mode's DOM contract.
 *
 * Drives the real QRIDUniqueInit factory through the shared DOM stub with a
 * STUBBED framework transport (config.jsmoName resolves to an object whose
 * ajax() returns a synchronous thenable), and asserts:
 *   - a used value flags the field (message + record id when supplied) and a
 *     HARD rule traps the save; a free value shows the green note and allows,
 *   - the payload carries the field's value AND the composite "with" values,
 *   - an empty field is inert and sends NO request,
 *   - a transport error / error response FAILS OPEN (never traps a save),
 *   - a MISSING transport (no jsmoName) fails open with a console note,
 *   - stale responses are discarded (only the latest request renders),
 *   - surveys: rule inert without the surveys opt-in, active with it,
 *   - a "when" gate turns the whole check on/off live,
 *   - MODE COMPOSITION: @UVUNIQUE + a check rule on the same field keep
 *     independent block state.
 *
 * The server twin (scope/composite/anti-oracle/DAG) is locked by
 * tests/hook_php.php; this file tests the client wiring only.
 *
 * Run:  node tests/unique_dom_js.cjs
 */
'use strict';
const path = require('path');

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

/* The clock Save reads to decide whether a cached answer is fresh
   (QRID_RECHECK_MS): tests move it forward with clockShift. */
let clockShift = 0;
const realDateNow = Date.now;
Date.now = () => realDateNow() + clockShift;
/* A transport whose replies the test hands over when it chooses, as a real
   (asynchronous) one does: stub.answer(reply | 'ERROR'). */
function makeDeferredStub() {
  const stub = { calls: [], pending: [] };
  stub.obj = {
    ajax(action, payload) {
      stub.calls.push({ action, payload: JSON.parse(JSON.stringify(payload)) });
      return { then(res, rej) { stub.pending.push({ res, rej }); } };
    },
  };
  stub.answer = (r) => {
    const p = stub.pending.shift();
    if (r === 'ERROR') p.rej(new Error('network')); else p.res(JSON.parse(JSON.stringify(r)));
  };
  return stub;
}
/* console.error output while fn runs, kept off the test's own output. */
function errorsDuring(fn) {
  const errs = [], was = console.error;
  console.error = (m) => errs.push(String(m));
  try { fn(); } finally { console.error = was; }
  return errs;
}

/* style.cssText = "display:block;..." sets style.display, as a browser does,
   so a check of display sees what the engine last showed. */
function makeStyle() {
  const s = {};
  Object.defineProperty(s, 'cssText', {
    get() { return this._css || ''; },
    set(v) {
      this._css = String(v);
      const m = /(?:^|;)\s*display\s*:\s*([^;]+)/.exec(this._css);
      this.display = m ? m[1].trim() : '';
    },
  });
  return s;
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

/* Transport stub: synchronous thenable so verdicts render deterministically.
   Set stub.next (a response object or 'ERROR'/'HANG') before firing events;
   stub.calls records every (action, payload). */
function makeTransportStub() {
  const stub = { calls: [], next: { used: false, record: null } };
  stub.obj = {
    ajax(action, payload) {
      stub.calls.push({ action, payload: JSON.parse(JSON.stringify(payload)) });
      const resp = stub.next;
      return {
        then(res, rej) {
          if (resp === 'HANG') return;            /* never answers */
          if (resp === 'ERROR') { rej(new Error('network')); return; }
          res(JSON.parse(JSON.stringify(resp)));
        },
      };
    },
  };
  return stub;
}

function boot(els, config, transportStub) {
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
  if (transportStub) win.EMStub = { UV: transportStub.obj };
  global.document = doc; global.window = win;
  const origError = console.error;
  const consoleErrors = [];
  console.error = (m) => consoleErrors.push(String(m));
  try { require(enginePath); } finally { console.error = origError; }
  return { doc, win, holders, allEls, NS: win.INSPIREUniversalValidator, consoleErrors };
}
function uMsg(env, field) {
  const kids = env.holders[field].children;
  for (let i = 1; i < kids.length; i++) if (kids[i].getAttribute && kids[i].id && /^uvalidate-msg-/.test(kids[i].id)) return kids[i];
  return kids[1];
}
function submitEv() {
  return { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
}
const JSMO = 'EMStub.UV';

// ---- 1) used value blocks; free value allows; record id shown to staff -----
{
  const stub = makeTransportStub();
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'AB100';
  stub.next = { used: true, record: '7' };
  const env = boot([pid], {
    singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }],
  }, stub);
  const msg = uMsg(env, 'pid');
  check('used: flagged with record id', /already recorded/.test(msg.innerHTML) && /record <b>7<\/b>/.test(msg.innerHTML));
  check('used: aria-invalid set', pid.getAttribute('aria-invalid') === 'true');
  check('payload names the field and value', stub.calls.length === 1
    && stub.calls[0].action === 'unique-check'
    && stub.calls[0].payload.field === 'pid' && stub.calls[0].payload.values.pid === 'AB100');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('used: hard block traps the save', ev._prevented === true);
  check('used: a fresh "used" decides the save without asking again', stub.calls.length === 1);
  // An older one is asked again: the other record may have changed since.
  clockShift += 31000;
  ev = submitEv(); env.doc.fire('submit', ev);
  check('used: the save asked again', stub.calls.length === 2 && stub.calls[1].payload.values.pid === 'AB100' && ev._prevented === true);
  stub.next = { used: false, record: null };
  clockShift += 31000;
  ev = submitEv(); env.doc.fire('submit', ev);
  check('used: a value freed since then lets the save through', ev._prevented === false && stub.calls.length === 3);

  stub.next = { used: false, record: null };
  pid.value = 'FRESH1';
  pid.fire('change');
  check('free: green note', /Not used before/.test(msg.innerHTML));
  ev = submitEv(); env.doc.fire('submit', ev);
  check('free: save allowed', ev._prevented === false);
  check('free: a fresh cached "free" is not asked again', stub.calls.length === 4);
  // An older "free" is asked again too (UNQ-4): the value may have been saved
  // in another record since this one was typed.
  stub.next = { used: true, record: '9' };
  clockShift += 31000;
  ev = submitEv(); env.doc.fire('submit', ev);
  check('free: an older "free" is asked again at Save, and the value saved since then blocks it',
    stub.calls.length === 5 && stub.calls[4].payload.values.pid === 'FRESH1' && ev._prevented === true
    && /already recorded/.test(msg.innerHTML));

  pid.value = '';
  pid.fire('change');
  check('empty: inert', msg.style.display === 'none');
  check('empty: no request sent', stub.calls.length === 5);

  // The server trims the same characters, no more and no less.
  pid.value = '\u00A0AB200\0';
  pid.fire('change');
  check('trim: no-break spaces and NUL are trimmed before asking',
    stub.calls.length === 6 && stub.calls[5].payload.values.pid === 'AB200');
  pid.value = '\u2003AB300';
  pid.fire('change');
  check('trim: an em space is kept, as the server keeps it',
    stub.calls.length === 7 && stub.calls[6].payload.values.pid === '\u2003AB300');
}

// ---- 1a) UNQ-6: a page drawn for a record not saved yet says so -------------
{
  const ask = (extra) => {
    const stub = makeTransportStub();
    stub.next = { used: false, record: null };
    const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'NR-1';
    boot([pid], Object.assign({ singleFields: [], pooledFields: [], jsmoName: JSMO,
      rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }] }, extra), stub);
    return stub.calls[0] && stub.calls[0].payload;
  };
  let p = ask({ newRecord: true });
  check('new record: the request says the record is not saved yet', p && p.newRecord === true);
  p = ask({});
  check('saved record: the request carries no newRecord', p && !('newRecord' in p));
  p = ask({ newRecord: 'yes' });
  check('newRecord other than true is not sent', p && !('newRecord' in p));
}

// ---- 1b) @UVUNIQUE "also": the server names the field the value was found in --
{
  const stub = makeTransportStub();
  const typed = makeEl('input'); typed.name = 'typed_id'; typed.value = 'P-001';
  stub.next = { used: true, record: '7', field: 'scan_id' };
  const env = boot([typed], {
    singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['typed_id'], blockSave: 'hard' }],
  }, stub);
  const msg = uMsg(env, 'typed_id');
  check('also: the message names the field and the record',
    /already recorded in field <b>scan_id<\/b> \(record <b>7<\/b>\)\./.test(msg.innerHTML));
  check('also: the request carries no "also" list (it stays on the server)',
    stub.calls.length === 1 && Object.keys(stub.calls[0].payload.values).join() === 'typed_id');
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('also: a value found in another field blocks like any duplicate', ev._prevented === true);
  // A field name is escaped like the record id.
  stub.next = { used: true, record: null, field: '<i>x</i>' };
  typed.value = 'P-002';
  typed.fire('change');
  check('also: the field name is escaped', /in field <b>&lt;i&gt;x&lt;\/i&gt;<\/b>\./.test(msg.innerHTML));
  // A survey answer carries neither.
  stub.next = { used: true, record: null };
  typed.value = 'P-003';
  typed.fire('change');
  check('also: no field and no record -> the plain message', /This value is already recorded\.$/.test(msg.innerHTML));
}

// ---- 2) custom message wins; composite "with" values travel -----------------
{
  const stub = makeTransportStub();
  const spec = makeEl('input'); spec.name = 'spec'; spec.value = 'S-77';
  const site = makeEl('select'); site.name = 'site'; site.value = '2';
  stub.next = { used: true, record: null };
  const env = boot([spec, site], {
    singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['spec'], uniqueWith: ['site'],
              message: 'Specimen already registered at this site', blockSave: 'confirm' }],
  }, stub);
  check('custom message shown', /already registered at this site/.test(uMsg(env, 'spec').innerHTML));
  check('with value travels in the payload',
    stub.calls[0].payload.values.spec === 'S-77' && stub.calls[0].payload.values.site === '2');
  // changing the composite member re-checks
  stub.next = { used: false, record: null };
  site.value = '1';
  site.fire('change');
  check('with-field change re-checks', stub.calls.length === 2 && stub.calls[1].payload.values.site === '1');
}
{
  // A "with" field this page does not carry is left out: the server reads its
  // saved value (another form, or another page of a multi-page survey).
  const stub = makeTransportStub();
  const spec = makeEl('input'); spec.name = 'spec'; spec.value = 'S-77';
  stub.next = { used: false, record: null };
  boot([spec], { singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['spec'], uniqueWith: ['site'], blockSave: 'hard' }] }, stub);
  check('with field not on the page: left out of the payload', stub.calls.length === 1
    && !('site' in stub.calls[0].payload.values));
}
{
  // Branches: the page sends the values its conditions read, so the server
  // answers from the branch the page enforces.
  const stub = makeTransportStub();
  const spec = makeEl('input'); spec.name = 'spec'; spec.value = 'S-77';
  const arm = makeEl('select'); arm.name = 'arm'; arm.value = '2';
  stub.next = { used: false, record: null };
  boot([spec, arm], { singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['spec'], branches: [
      { when: "[arm]='1'", blockSave: 'hard' }, { when: "[arm]='2'", blockSave: 'confirm' }] }] }, stub);
  check('branches: the condition values travel as cond', stub.calls.length === 1 && stub.calls[0].payload.cond.arm === '2');
}

// ---- 3) failures FAIL OPEN, and say so on staff forms (UNQ-3) ----------------
{
  const stub = makeTransportStub();
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'X1';
  stub.next = 'ERROR';
  const env = boot([pid], {
    singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }],
  }, stub);
  const msg = uMsg(env, 'pid');
  check('transport error: says it could not check (no reason given)',
    msg.style.display === 'block' && /Could not check whether this value is already recorded just now\.$/.test(msg.innerHTML));
  check('transport error: not marked invalid', pid.getAttribute('aria-invalid') !== 'true');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('transport error: save never trapped', ev._prevented === false);
  check('transport error: console note', env.consoleErrors.some((m) => /uniqueness check failed/.test(m)));

  stub.next = { error: 'not a checkable field' };
  pid.value = 'X2';
  pid.fire('change');
  check('error response: says it could not check, without the server error text',
    /Could not check/.test(msg.innerHTML) && !/checkable/.test(msg.innerHTML));
  ev = submitEv(); env.doc.fire('submit', ev);
  check('error response: save never trapped', ev._prevented === false);
}
{
  // A throttled answer holds a rule that blocks: the server answers again
  // within the minute, and Save asks again (UNQ-3).
  const stub = makeTransportStub();
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'TH1';
  stub.next = { unknown: true, record: null, throttled: true, why: 'too many checks in the last minute — wait a moment' };
  const env = boot([pid], {
    singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }],
  }, stub);
  const msg = uMsg(env, 'pid');
  check('throttled: says it could not check, why, and that the save waits',
    msg.style.display === 'block'
    && /Could not check whether this value is already recorded just now \(too many checks in the last minute — wait a moment\)\. The save waits until it can be checked\./.test(msg.innerHTML));
  check('throttled: not marked invalid', pid.getAttribute('aria-invalid') !== 'true');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('throttled: the save is held, and Save asked again',
    ev._prevented === true && stub.calls.length === 2
    && /wait a moment and save again/.test(env.win._alerts.slice(-1)[0] || ''));
  stub.next = { used: true, record: '4' };
  ev = submitEv(); env.doc.fire('submit', ev);
  check('throttled: a duplicate found once the limit clears blocks', ev._prevented === true && /already recorded/.test(msg.innerHTML));
  stub.next = { unknown: true, record: null, throttled: true, why: 'too many checks in the last minute — wait a moment' };
  pid.value = 'TH2'; pid.fire('change');
  stub.next = { used: false, record: null };
  ev = submitEv(); env.doc.fire('submit', ev);
  check('throttled: a free answer once the limit clears lets the save through', ev._prevented === false && /Not used before/.test(msg.innerHTML));
}
{
  // Any other answer the server cannot give fails open: it names its reason,
  // never blocks, and Save does not ask the same value again.
  const stub = makeTransportStub();
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'UK1';
  stub.next = { unknown: true, record: null, why: 'the saved values could not be compared just now' };
  const env = boot([pid], {
    singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }],
  }, stub);
  const msg = uMsg(env, 'pid');
  check('unknown: says it could not check, and why',
    /Could not check whether this value is already recorded just now \(the saved values could not be compared just now\)\.$/.test(msg.innerHTML));
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('unknown: never blocks the save', ev._prevented === false);
  check('unknown: Save asks nothing new and keeps the notice',
    stub.calls.length === 1 && /could not be compared/.test(msg.innerHTML));
  stub.next = { unknown: true, record: null, why: '<img src=x>' };
  pid.value = 'UK2'; pid.fire('change');
  check('unknown: the reason is escaped', /\(&lt;img src=x&gt;\)/.test(msg.innerHTML) && !/<img/.test(msg.innerHTML));
  stub.next = { used: false, record: null };
  pid.value = 'UK3'; pid.fire('change');
  check('unknown: a later answer replaces the notice', /Not used before/.test(msg.innerHTML));
}
{
  // A throttled rule that cannot block is never held.
  const stub = makeTransportStub();
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'TH9';
  stub.next = { unknown: true, record: null, throttled: true, why: 'too many checks in the last minute — wait a moment' };
  const env = boot([pid], {
    singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['pid'], blockSave: 'off' }],
  }, stub);
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('throttled + blockSave off: never held', ev._prevented === false && !/save waits/.test(uMsg(env, 'pid').innerHTML));
}

{
  // On a survey the notice stays hidden: a respondent cannot act on it.
  const stub = makeTransportStub();
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'SV1';
  stub.next = { unknown: true, record: null };
  const env = boot([pid], {
    singleFields: [], pooledFields: [], context: 'survey', jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['pid'], uniqueSurveys: true, blockSave: 'hard' }],
  }, stub);
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('survey: an unknown answer shows nothing and never blocks',
    uMsg(env, 'pid').style.display === 'none' && ev._prevented === false && stub.calls.length === 1);
}
{
  // Typing asks nothing; change and blur ask (UNQ-3).
  const stub = makeTransportStub();
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = '';
  const env = boot([pid], {
    singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }],
  }, stub);
  for (const v of ['A', 'AB', 'AB1']) { pid.value = v; pid.fire('input'); }
  check('typing: no request per keystroke', stub.calls.length === 0);
  pid.fire('change');
  check('typing: change asks once', stub.calls.length === 1 && stub.calls[0].payload.values.pid === 'AB1');
  pid.fire('blur');
  check('typing: blur on the same value asks nothing new', stub.calls.length === 1);
  pid.value = 'AB2'; pid.fire('input');
  check('typing: a keystroke clears the old verdict', uMsg(env, 'pid').style.display === 'none');
  pid.fire('blur');
  check('typing: blur asks too', stub.calls.length === 2 && stub.calls[1].payload.values.pid === 'AB2');
  void env;
}
{
  // no transport at all (jsmoName absent)
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'X1';
  const env = boot([pid], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }],
  }, null);
  check('no transport: inert', uMsg(env, 'pid').style.display === 'none');
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('no transport: save never trapped', ev._prevented === false);
  check('no transport: console note', env.consoleErrors.some((m) => /no AJAX transport/.test(m)));
}

// ---- 4) pending answer never blocks (HANG = fail open while waiting) --------
{
  const stub = makeTransportStub();
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'SLOW1';
  stub.next = 'HANG';
  const env = boot([pid], {
    singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }],
  }, stub);
  check('pending: checking note shown', /checking/.test(uMsg(env, 'pid').innerHTML));
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('pending: a save click waits for the answer', ev._prevented === true
    && /still being checked/.test(env.win._alerts.slice(-1)[0] || ''));
}
{
  // ...for a bounded time: no answer in time fails open, and Save does not ask
  // the same value again.
  const realST = global.setTimeout;
  const long = [];
  global.setTimeout = (fn, ms) => { if ((ms || 0) >= 5000) { long.push(fn); return 0; } return realST(fn, ms); };
  try {
    const stub = makeTransportStub();
    const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'SLOW2';
    stub.next = 'HANG';
    const env = boot([pid], { singleFields: [], pooledFields: [], jsmoName: JSMO,
      rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }] }, stub);
    long.forEach((fn) => fn());
    check('pending: no answer in time says so', /Could not check[^<]*\(no answer in time\)/.test(uMsg(env, 'pid').innerHTML));
    const ev = submitEv(); env.doc.fire('submit', ev);
    check('pending: no answer in time lets the save through', ev._prevented === false);
    check('pending: ...without asking the same value again', stub.calls.length === 1);
  } finally { global.setTimeout = realST; }
}

{
  // A value changed with no event at all (a script, a widget): Save asks.
  const stub = makeTransportStub();
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'FREE1';
  stub.next = { used: false, record: null };
  const env = boot([pid], { singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }] }, stub);
  pid.value = 'TAKEN1';
  stub.next = { used: true, record: null };
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('changed without an event, then Save: the value is asked before the save is decided',
    ev._prevented === true && stub.calls.length === 2 && stub.calls[1].payload.values.pid === 'TAKEN1');
}
{
  // A -> B -> A without an input event: A comes from the cache, and B's late
  // reply must not paint over it.
  const pending = [];
  const calls = [];
  const obj = { ajax(a, payload) { calls.push(payload); return { then(res) { pending.push(res); } }; } };
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'A-1';
  const env = boot([pid], { singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }] }, { obj });
  pending[0]({ used: false, record: null });
  pid.value = 'B-1'; pid.fire('change');
  pid.value = 'A-1'; pid.fire('change');
  check('cache hit: A is answered without asking again', calls.length === 2 && /Not used before/.test(uMsg(env, 'pid').innerHTML));
  pending[1]({ used: true, record: null });
  check('cache hit: a late reply for B is dropped', /Not used before/.test(uMsg(env, 'pid').innerHTML));
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('cache hit: ...and the save goes through', ev._prevented === false);
}

// ---- 5) stale responses are discarded ---------------------------------------
{
  const stub = makeTransportStub();
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'FIRST';
  // first answer HANGS (arrives never); second answers used=false
  stub.next = 'HANG';
  const env = boot([pid], {
    singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }],
  }, stub);
  // now the user types again; this request answers immediately
  stub.next = { used: false, record: null };
  pid.value = 'SECOND';
  pid.fire('change');
  check('latest request renders', /Not used before/.test(uMsg(env, 'pid').innerHTML));
  check('two requests were made', stub.calls.length === 2);
}

// ---- 6) surveys: opt-in only -------------------------------------------------
{
  const stub = makeTransportStub();
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'AB100';
  stub.next = { used: true, record: null };
  const env = boot([pid], {
    singleFields: [], pooledFields: [], context: 'survey', jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }],
  }, stub);
  check('survey without opt-in: inert, no request', uMsg(env, 'pid').style.display === 'none' && stub.calls.length === 0);
}
{
  const stub = makeTransportStub();
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'AB100';
  stub.next = { used: true, record: null };
  const env = boot([pid], {
    singleFields: [], pooledFields: [], context: 'survey', jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['pid'], uniqueSurveys: true, blockSave: 'hard' }],
  }, stub);
  check('survey with opt-in: checked and flagged (no record id shown)',
    /already recorded/.test(uMsg(env, 'pid').innerHTML) && !/record <b>/.test(uMsg(env, 'pid').innerHTML));
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('survey with opt-in: hard block works', ev._prevented === true);
}

// ---- 7) "when" gate ----------------------------------------------------------
{
  const stub = makeTransportStub();
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'AB100';
  const t = makeEl('select'); t.name = 't'; t.value = '1';
  stub.next = { used: true, record: null };
  const env = boot([pid, t], {
    singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['pid'], when: "[t]='2'", blockSave: 'hard' }],
  }, stub);
  check('when false: inert, no request', uMsg(env, 'pid').style.display === 'none' && stub.calls.length === 0);
  t.value = '2';
  t.fire('change');
  check('when flips true: checked and flagged', /already recorded/.test(uMsg(env, 'pid').innerHTML) && stub.calls.length === 1);
}

// ---- 7b) text in the "when" ignores letter case unless caseSensitive:true ---
{
  const run = (extra) => {
    const stub = makeTransportStub();
    const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'AB100';
    const t = makeEl('input'); t.name = 't'; t.value = 'ACTIVE';
    stub.next = { used: true, record: null };
    const env = boot([pid, t], { singleFields: [], pooledFields: [], jsmoName: JSMO,
      rules: [Object.assign({ type: 'unique', fields: ['pid'], when: "[t]='active'", blockSave: 'hard' }, extra)] }, stub);
    return { env, stub };
  };
  let r = run({});
  check('unique, case default: "ACTIVE" matches when [t]=active (checked)',
    r.stub.calls.length === 1 && /already recorded/.test(uMsg(r.env, 'pid').innerHTML));
  r = run({ caseSensitive: true });
  check('unique, caseSensitive:true: "ACTIVE" does not match (no request)', r.stub.calls.length === 0);
}

// ---- 8) MODE COMPOSITION: unique + check rule on the same field --------------
{
  const stub = makeTransportStub();
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'AB1234';
  stub.next = { used: true, record: null };   // unique says taken; pattern is fine
  const env = boot([pid], {
    singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [
      { type: 'single', fields: ['pid'], algorithm: 'none', idPattern: '[A-Z]{2}[0-9]{4}', blockSave: 'hard' },
      { type: 'unique', fields: ['pid'], blockSave: 'hard' },
    ],
  }, stub);
  check('compose: no false duplicate notice', !env.allEls.some((e) => e.id === 'uvalidate-config-errors'));
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('compose: unique blocks although the pattern passes', ev._prevented === true);
  stub.next = { used: false, record: null };
  pid.value = 'CD5678';   // still matches the pattern; a NEW value re-asks the server
  pid.fire('change');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('compose: both pass -> save allowed', ev._prevented === false);
  // same-value recheck is served from the one-deep cache, not a duplicate request
  const before = stub.calls.length;
  pid.fire('change');
  check('compose: same-value recheck hits the cache (no duplicate request)', stub.calls.length === before);
}

// A response from a branch that is no longer active must never restore its guard.
{
  let resolve;
  const stub={calls:[]};
  stub.obj={ajax(action,payload){stub.calls.push({action,payload});return {then(fn){resolve=fn;}};}};
  const pid=makeEl('input');pid.name='pid';pid.value='ABC';
  const key=makeEl('input');key.name='key';key.value='A';
  const tree=['temporal',['cmp','=', ['guard',[['key','A']],['lit','1']],['lit','1']]];
  const env=boot([pid,key],{jsmoName:JSMO,rules:[{type:'unique',fields:['pid'],when:"[key]='A'",whenAst:tree,blockSave:'hard'}]},stub);
  check('known extended uniqueness sends request',stub.calls.length===1);
  key.value='B';key.fire('change');
  resolve({used:true,record:'7'});
  const ev=submitEv();env.doc.fire('submit',ev);
  check('unknown uniqueness discards late response',!ev._prevented&&pid.getAttribute('aria-invalid')!=='true');
}

// ---- a real transport answers later: Save waits once, then decides ----------------
for (const mode of ['confirm', 'hard']) {
  const stub = makeDeferredStub();
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'AB100';
  const env = boot([pid], { singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['pid'], blockSave: mode }] }, stub);
  stub.answer({ used: true, record: '7' });
  const confirms = [];
  env.win.confirm = (m) => { confirms.push(m); return false; };
  const click = () => { const ev = submitEv(); env.doc.fire('submit', ev); return ev; };
  const told = () => mode === 'confirm' ? confirms.length : env.win._alerts.filter((a) => /fix the flagged/.test(a)).length;
  let ev = click();
  check(mode + ' async: a fresh "used" decides the first click, with no request', ev._prevented && told() === 1 && stub.calls.length === 1);
  clockShift += 31000;
  ev = click();
  check(mode + ' async: an older "used" is asked again, and the click waits for it',
    ev._prevented && stub.calls.length === 2 && /still being checked/.test(env.win._alerts[env.win._alerts.length - 1] || ''));
  stub.answer({ used: true, record: '7' });
  ev = click();
  check(mode + ' async: the next click is decided by the answer, not held again', ev._prevented && told() === 2 && stub.calls.length === 2);
  click(); click();
  check(mode + ' async: further clicks ask nothing', stub.calls.length === 2 && told() === 4);
  clockShift += 31000;
  click();
  const errs = errorsDuring(() => stub.answer('ERROR'));
  ev = click();
  check(mode + ' async: a re-ask that fails keeps the known duplicate',
    ev._prevented && told() === 5 && stub.calls.length === 3 && /already recorded/.test(uMsg(env, 'pid').innerHTML) && errs.length === 1);
  clockShift += 31000;
  click();
  const quiet = errorsDuring(() => stub.answer({ unknown: true, record: null }));
  ev = click();
  check(mode + ' async: an "unknown" re-ask keeps it too, and is no error',
    ev._prevented && told() === 6 && stub.calls.length === 4 && quiet.length === 0);
  clockShift += 31000;
  click();
  stub.answer({ used: false, record: null });
  ev = click();
  check(mode + ' async: a value freed since then lets the next click save', !ev._prevented && stub.calls.length === 5);
}
// A re-ask with no answer in time keeps the known duplicate.
{
  const realST = global.setTimeout;
  const long = [];
  global.setTimeout = (fn, ms) => { if ((ms || 0) >= 5000) { long.push(fn); return 0; } return realST(fn, ms); };
  try {
    const stub = makeDeferredStub();
    const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'AB100';
    const env = boot([pid], { singleFields: [], pooledFields: [], jsmoName: JSMO,
      rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }] }, stub);
    stub.answer({ used: true, record: '7' });
    clockShift += 31000;
    let ev = submitEv(); env.doc.fire('submit', ev);
    long.forEach((fn) => fn()); long.length = 0;
    ev = submitEv(); env.doc.fire('submit', ev);
    check('async: a re-ask with no answer in time keeps the known duplicate',
      ev._prevented && stub.calls.length === 2 && /already recorded/.test(uMsg(env, 'pid').innerHTML)
      && /fix the flagged/.test(env.win._alerts[env.win._alerts.length - 1] || ''));
  } finally { global.setTimeout = realST; }
}
// An older "free" asked again with no answer in time keeps "free": the save goes through (UNQ-4).
{
  const realST = global.setTimeout;
  const long = [];
  global.setTimeout = (fn, ms) => { if ((ms || 0) >= 5000) { long.push(fn); return 0; } return realST(fn, ms); };
  try {
    const stub = makeDeferredStub();
    const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'FREE77';
    const env = boot([pid], { singleFields: [], pooledFields: [], jsmoName: JSMO,
      rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }] }, stub);
    stub.answer({ used: false, record: null });
    clockShift += 31000;
    let ev = submitEv(); env.doc.fire('submit', ev);
    check('async: an older "free" is asked again, and the click waits for it',
      ev._prevented && stub.calls.length === 2 && /still being checked/.test(env.win._alerts[env.win._alerts.length - 1] || ''));
    long.forEach((fn) => fn()); long.length = 0;
    ev = submitEv(); env.doc.fire('submit', ev);
    check('async: ...with no answer in time it keeps "free" and the next click saves',
      !ev._prevented && stub.calls.length === 2 && /Not used before/.test(uMsg(env, 'pid').innerHTML));
  } finally { global.setTimeout = realST; }
}
// A first answer of "unknown" (the server's read budget) is no error either.
{
  const stub = makeDeferredStub();
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'AB100';
  const env = boot([pid], { singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }] }, stub);
  const errs = errorsDuring(() => stub.answer({ unknown: true, record: null }));
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('unknown: says it could not check, never blocks, logs nothing',
    !ev._prevented && /Could not check/.test(uMsg(env, 'pid').innerHTML) && errs.length === 0);

}

console.log(`unique_dom_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
