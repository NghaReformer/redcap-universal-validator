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

/* temporal_sec: QRIDUniqueInit's makeVariant() sets
     localAsts: cfg.uniqueRecordAsts || {}
   and attach() looks up v.localAsts[fieldName] on that plain object. For a
   REDCap field named "constructor" (a legal variable name) the lookup hits
   Object.prototype.constructor, a truthy function, so every ordinary
   project/DAG/event @UVUNIQUE rule on that field is treated as a record-local
   check: gateFor("1=1", <function>) parses "1=1", which is always true, the page says
   "No duplicate among the other saved entries in this record", never calls
   the unique-check endpoint, and a blockSave:"hard" rule never blocks. */
for (const name of ['pid', 'constructor']) {
  const stub = makeTransportStub();
  const el = makeEl('input'); el.name = name; el.value = 'AB100';
  stub.next = { used: true, record: '7' };            // the server WOULD say "used"
  const env = boot([el], {
    singleFields: [], pooledFields: [], jsmoName: JSMO,
    rules: [{ type: 'unique', fields: [name], blockSave: 'hard' }],
  }, stub);
  el.fire('input'); el.fire('change');
  const msg = uMsg(env, name);
  const ev = submitEv(); env.doc.fire('submit', ev);
  console.log(`field=${name.padEnd(12)} ajaxCalls=${stub.calls.length} saveBlocked=${ev._prevented} msg=${JSON.stringify(msg && msg.innerHTML)}`);
}
