/*
 * exists_where_dom_js.cjs — @UVEXISTS "where" on the page.
 *
 * The server answers a value that is saved, but only in entries that fail the
 * rule's "where", as not-found with filtered: true, to staff only. Drives the
 * real QRIDExistsInit factory through the DOM stub of tests/exists_dom_js.cjs
 * and asserts:
 *   - a filtered answer shows "whereMessage", or the module's own line when
 *     the rule has none, never the rule's "message"; a plain not-found still
 *     shows "message";
 *   - it flags the field and holds the save as not-found does (blockSave);
 *   - "whereMessage" is escaped and follows the language on screen under
 *     Multi-Language Management;
 *   - a survey page shows the plain not-found wording even if a reply carried
 *     the flag;
 *   - a cached filtered answer renders the same, and a rule that fills fields
 *     ("return") empties them.
 *
 * The server twin is tests/exists_where_php.php, which also pins the page
 * config: "whereMessage" reaches the page, the condition never does.
 *
 * Run:  node tests/exists_where_dom_js.cjs
 */
'use strict';
const path = require('path');

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

/* A style whose cssText assignment sets display, as a browser's does. */
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
    /* As in a browser, inserting a node that is already in the tree moves it. */
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

/* The engine reports lookup failures on console.error; collect them per test. */
const realConsoleError = console.error;
let consoleLog = [];
console.error = (m) => { if (/^FAIL: /.test(String(m))) realConsoleError(m); else consoleLog.push(String(m)); };

/* Fake timers: the debounced re-check runs only when the test says so.
   flush() runs the short ones (the pause after a change); flushAll() also the
   long ones (the wait for an answer that never comes). */
const realSetTimeout = global.setTimeout, realClearTimeout = global.clearTimeout;
let timers = [], timerSeq = 0;
global.setTimeout = (fn, ms) => { const id = ++timerSeq; timers.push({ id, fn, ms: ms || 0 }); return id; };
global.clearTimeout = (id) => { timers = timers.filter((t) => t.id !== id); };
function flush() { const due = timers.filter((t) => t.ms < 5000); timers = timers.filter((t) => t.ms >= 5000); due.forEach((t) => t.fn()); }
function flushAll() { const due = timers; timers = []; due.forEach((t) => t.fn()); }

/* Transport stub. stub.next: a reply object, 'ERROR', or 'HANG'. */
function makeTransportStub() {
  const stub = { calls: [], next: { state: 'found', record: null } };
  stub.obj = {
    ajax(action, payload) {
      stub.calls.push({ action, payload: JSON.parse(JSON.stringify(payload)) });
      const resp = stub.next;
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

function boot(els, config, transportStub, before) {
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
  consoleLog = [];
  if (before) before({ holders, allEls });
  require(enginePath);
  return { doc, win, holders, allEls, NS: win.INSPIREUniversalValidator, get consoleErrors() { return consoleLog; } };
}
/* The exists message region (suffix "x"). */
function xMsg(env, field) {
  return env.allEls.find((e) => e.id && /^uvalidate-msg-/.test(e.id) && /-x$/.test(e.id) && e.id.indexOf(field) >= 0)
    || env.holders[field].children[1];
}
function submitEv() {
  return { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
}
function saved(env) { const ev = submitEv(); env.doc.fire('submit', ev); return !ev._prevented; }
const JSMO = 'EMStub.UV';

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

const rule = (extra) => Object.assign({ type: 'exists', fields: ['spec'] }, extra || {});
function one(value, extra, cfgExtra) {
  const stub = makeTransportStub();
  const spec = makeEl('input'); spec.name = 'spec'; spec.value = value;
  return { stub, spec, cfg: Object.assign({ jsmoName: JSMO, rules: [rule(extra)] }, cfgExtra || {}) };
}

const FILTERED = { state: 'not-found', record: null, filtered: true };

// ---- 1) the filtered line -------------------------------------------------------------
{
  const t = one('SP-WD', { blockSave: 'hard', message: 'Register it first.', whereMessage: 'Registered, but <b>not</b> eligible.' });
  t.stub.next = FILTERED;
  const env = boot([t.spec], t.cfg, t.stub);
  const msg = xMsg(env, 'spec');
  check('filtered: "whereMessage" shown, escaped', /Registered, but &lt;b&gt;not&lt;\/b&gt; eligible\./.test(msg.innerHTML));
  check('filtered: never the rule\'s "message"', !/Register it first/.test(msg.innerHTML));
  check('filtered: the field is flagged', t.spec.getAttribute('aria-invalid') === 'true' && t.spec.style.outline === '2px solid #c62828');
  check('filtered + hard: the save is held', !saved(env));

  t.stub.next = { state: 'not-found', record: null };
  t.spec.value = 'SP-404'; t.spec.fire('change');
  check('plain not-found: the rule\'s "message"', /Register it first\./.test(msg.innerHTML) && !/eligible/.test(msg.innerHTML));

  t.stub.next = { state: 'found', record: null };
  t.spec.value = 'SP-OK'; t.spec.fire('change');
  check('found: the field is clear', /Found/.test(msg.innerHTML) && saved(env));

  // the cached filtered answer renders the same, without asking again
  const asked = t.stub.calls.length;
  t.spec.value = 'SP-WD'; t.spec.fire('change');
  check('cache: a filtered answer is kept and rendered the same', t.stub.calls.length === asked
    && /not<\/b>|not&lt;\/b&gt;/.test(msg.innerHTML) && !saved(env));
}
{
  const t = one('SP-WD', { blockSave: 'confirm' });
  t.stub.next = FILTERED;
  const env = boot([t.spec], t.cfg, t.stub);
  check('filtered without "whereMessage": the module\'s own line',
    /saved, but not in an entry that meets this rule's conditions/.test(xMsg(env, 'spec').innerHTML));
}
{
  const t = one('SP-WD', { blockSave: 'off', whereMessage: 'Not eligible.' });
  t.stub.next = FILTERED;
  const env = boot([t.spec], t.cfg, t.stub);
  check('filtered with blockSave off: flagged, the save goes through', /Not eligible\./.test(xMsg(env, 'spec').innerHTML) && saved(env));
}

// ---- 2) languages ----------------------------------------------------------------------
{
  const t = one('SP-WD', { blockSave: 'hard', whereMessage: 'Not eligible.', i18n: { whereMessage: { fr: 'Non éligible.' } } });
  t.stub.next = FILTERED;
  let langChanged = null;
  const mlm = { lang: 'fr', getCurrentLanguage() { return this.lang; }, onLangChanged(fn) { langChanged = fn; } };
  const env = boot([t.spec], t.cfg, t.stub, () => { global.window.REDCap = { MultiLanguage: mlm }; });
  const msg = xMsg(env, 'spec');
  check('MLM: "whereMessage" in the language on screen', /Non éligible\./.test(msg.innerHTML));
  check('MLM: marked for a language switch, which is hooked', /data-uv-text="\d+"/.test(msg.innerHTML) && typeof langChanged === 'function');
  const asked = t.stub.calls.length;
  langChanged();
  check('MLM: a switch asks nothing again', t.stub.calls.length === asked);
}
{
  const t = one('SP-WD', { blockSave: 'hard', whereMessage: 'Not eligible.', i18n: { whereMessage: { fr: 'Non éligible.' } } });
  t.stub.next = FILTERED;
  const mlm = { getCurrentLanguage() { return 'de'; }, onLangChanged() {} };
  const env = boot([t.spec], t.cfg, t.stub, () => { global.window.REDCap = { MultiLanguage: mlm }; });
  check('MLM: a language the map lacks gets the default text', /Not eligible\./.test(xMsg(env, 'spec').innerHTML));
}

// ---- 3) surveys ------------------------------------------------------------------------
{
  const t = one('SP-WD', { blockSave: 'hard', existsSurveys: true, message: 'Not found.', whereMessage: 'Registered, not eligible.' },
    { context: 'survey' });
  t.stub.next = FILTERED;   // the server never sends the flag to a survey; the page ignores it if it did
  const env = boot([t.spec], t.cfg, t.stub);
  const msg = xMsg(env, 'spec');
  check('survey: the plain not-found wording', /Not found\./.test(msg.innerHTML) && !/eligible/.test(msg.innerHTML));
  check('survey: blocked as not-found', !saved(env));
}

// ---- 4) "return" -----------------------------------------------------------------------
{
  const stub = makeTransportStub();
  const spec = makeEl('input'); spec.name = 'spec'; spec.value = 'SP-WD';
  const site = makeEl('input'); site.name = 'site'; site.value = 'North';
  stub.next = FILTERED;
  const cfg = { jsmoName: JSMO, rules: [rule({ existsReturnFields: ['site'], whereMessage: 'Not eligible.' })] };
  const env = boot([spec, site], cfg, stub);
  check('return: a filtered answer empties the field it fills', site.value === '' && /Not eligible\./.test(xMsg(env, 'spec').innerHTML));
}

console.log('exists_where_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
