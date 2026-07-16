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

  stub.next = { used: false, record: null };
  pid.value = 'FRESH1';
  pid.fire('change');
  check('free: green note', /Not used before/.test(msg.innerHTML));
  ev = submitEv(); env.doc.fire('submit', ev);
  check('free: save allowed', ev._prevented === false);

  pid.value = '';
  pid.fire('change');
  check('empty: inert', msg.style.display === 'none');
  check('empty: no request sent', stub.calls.length === 2);
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

// ---- 3) failures FAIL OPEN ---------------------------------------------------
{
  const stub = makeTransportStub();
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'X1';
  stub.next = 'ERROR';
  const env = boot([pid], {
    singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }],
  }, stub);
  check('transport error: inert (fail open)', uMsg(env, 'pid').style.display === 'none');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('transport error: save never trapped', ev._prevented === false);
  check('transport error: console note', env.consoleErrors.some((m) => /uniqueness check failed/.test(m)));

  stub.next = { error: 'not a checkable field' };
  pid.value = 'X2';
  pid.fire('change');
  check('error response: inert (fail open)', uMsg(env, 'pid').style.display === 'none');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('error response: save never trapped', ev._prevented === false);
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
  check('pending: save never trapped', ev._prevented === false);
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

console.log(`unique_dom_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
