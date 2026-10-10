/*
 * exists_dom_js.cjs — the @UVEXISTS exists mode's DOM contract (2.3.0).
 *
 * Drives the real QRIDExistsInit factory through the shared DOM stub with a
 * STUBBED framework transport (a synchronous thenable) and FAKE timers, and
 * asserts:
 *   - found shows a green note (with the record when the server sends one),
 *     not-found flags the field and traps the save per blockSave, unknown shows
 *     an amber "could not check" that never blocks,
 *   - every failure (transport error, error reply, malformed reply, missing
 *     transport) is "could not check" or inert, never a block,
 *   - requests go out on change and blur and once on load, NEVER per
 *     keystroke: typing clears the verdict and asks nothing, typing in a text
 *     field a "when" condition reads included,
 *   - Save re-judges the field first and waits for an answer still on its way
 *     (typed then Save at once; a change no event reported), up to a timeout
 *     after which the lookup counts as "could not check",
 *   - the payload carries the value, the "match" fields the page has (one it
 *     does not have is left out for the server to read) and the values of the
 *     fields the "when" conditions read ("cond"), so the server answers from
 *     the branch the page enforces,
 *   - a cache of found / not-found answers keyed by all of that; unknown is
 *     asked again; a cached answer is the latest word over a late reply,
 *   - a branch conflict never lifts another mode's block,
 *   - an autocomplete dropdown keeps its message beside the visible widget,
 *   - stale replies are dropped, surveys are opt-in, the "when" gate works,
 *   - a deferred rule shows its notice to staff only,
 *   - MODE COMPOSITION with @UVUNIQUE on the same field.
 *
 * The server twin is locked by tests/exists_php.php.
 *
 * Run:  node tests/exists_dom_js.cjs
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
const rule = (extra) => Object.assign({ type: 'exists', fields: ['spec'] }, extra || {});
function one(value, extra, cfgExtra) {
  const stub = makeTransportStub();
  const spec = makeEl('input'); spec.name = 'spec'; spec.value = value;
  return { stub, spec, cfg: Object.assign({ jsmoName: JSMO, rules: [rule(extra)] }, cfgExtra || {}) };
}

// ---- 1) found / not-found / unknown -------------------------------------------
{
  const t = one('SP-2', { blockSave: 'hard' });
  t.stub.next = { state: 'found', record: '7' };
  const env = boot([t.spec], t.cfg, t.stub);
  const msg = xMsg(env, 'spec');
  check('a11y: the message is a polite live region', msg.getAttribute('role') === 'status' && msg.getAttribute('aria-live') === 'polite');
  check('a11y: the input is described by it', (t.spec.getAttribute('aria-describedby') || '').split(' ').indexOf(msg.id) >= 0);
  check('asked once on load', t.stub.calls.length === 1 && t.stub.calls[0].action === 'exists-check');
  check('payload: field and value', t.stub.calls[0].payload.field === 'spec' && t.stub.calls[0].payload.values.spec === 'SP-2');
  check('found: green note naming the record', /Found/.test(msg.innerHTML) && /record <b>7<\/b>/.test(msg.innerHTML));
  check('found: aria-invalid false', t.spec.getAttribute('aria-invalid') === 'false');
  check('found: save allowed', saved(env));

  t.stub.next = { state: 'not-found', record: null };
  t.spec.value = 'SP-404'; t.spec.fire('change');
  check('not-found: flagged', /not saved where it should be/.test(msg.innerHTML));
  check('not-found: aria-invalid true', t.spec.getAttribute('aria-invalid') === 'true');
  check('not-found: red outline', t.spec.style.outline === '2px solid #c62828');
  check('not-found + hard: save trapped', !saved(env));

  t.stub.next = { state: 'unknown', record: null, why: 'the saved values could not be read just now' };
  t.spec.value = 'SP-5'; t.spec.fire('change');
  check('unknown: amber "could not check" with the reason', /Could not check/.test(msg.innerHTML) && /could not be read just now/.test(msg.innerHTML));
  check('unknown: amber outline', t.spec.style.outline === '2px solid #b7791f');
  check('unknown: no opinion on validity (aria-invalid removed)', t.spec.getAttribute('aria-invalid') === null);
  check('unknown: never blocks', saved(env));
  check('found without a record: no record named', (() => {
    t.stub.next = { state: 'found', record: null }; t.spec.value = 'SP-6'; t.spec.fire('change');
    return /Found\./.test(msg.innerHTML) && !/record <b>/.test(msg.innerHTML);
  })());
  check('the reply text is escaped', (() => {
    t.stub.next = { state: 'unknown', why: '<img src=x>' }; t.spec.value = 'SP-7'; t.spec.fire('change');
    return !/<img/.test(msg.innerHTML) && /&lt;img/.test(msg.innerHTML);
  })());
}
{
  const t = one('SP-404', { blockSave: 'hard', message: 'Register the <b>specimen</b> first.' });
  t.stub.next = { state: 'not-found' };
  const env = boot([t.spec], t.cfg, t.stub);
  check('custom message shown, escaped', /Register the &lt;b&gt;specimen&lt;\/b&gt; first\./.test(xMsg(env, 'spec').innerHTML));
}
{
  const t = one('SP-404', {});
  t.stub.next = { state: 'not-found' };
  const env = boot([t.spec], t.cfg, t.stub);
  check('not-found with blockSave off: flagged but the save goes through', /not saved/.test(xMsg(env, 'spec').innerHTML) && saved(env));
}

// ---- 2) failures: never a block -----------------------------------------------
{
  const t = one('SP-1', { blockSave: 'hard' });
  t.stub.next = 'ERROR';
  const env = boot([t.spec], t.cfg, t.stub);
  check('transport error: could not check', /Could not check/.test(xMsg(env, 'spec').innerHTML));
  check('transport error: console note', env.consoleErrors.some((m) => /lookup failed/.test(m)));
  check('transport error: save allowed', saved(env));
  t.stub.next = { error: 'not a checkable field' };
  t.spec.value = 'SP-2'; t.spec.fire('change');
  check('error reply: could not check, no reason shown', /Could not check this value just now\./.test(xMsg(env, 'spec').innerHTML));
  check('error reply: save allowed', saved(env));
  t.stub.next = { state: 'maybe' };
  t.spec.value = 'SP-3'; t.spec.fire('change');
  check('malformed reply: could not check', /Could not check/.test(xMsg(env, 'spec').innerHTML) && saved(env));
}
{
  const t = one('SP-1', { blockSave: 'hard' });
  const env = boot([t.spec], { rules: t.cfg.rules }, null);
  check('no transport: inert', xMsg(env, 'spec').style.display === 'none');
  check('no transport: console note', env.consoleErrors.some((m) => /no AJAX transport/.test(m)));
  check('no transport: save allowed', saved(env));
}
{
  const t = one('SP-1', { blockSave: 'hard' });
  t.stub.next = 'HANG';
  const env = boot([t.spec], t.cfg, t.stub);
  check('pending: checking note', /checking/.test(xMsg(env, 'spec').innerHTML));
  check('pending: a save click waits for the answer', !saved(env)
    && /still being checked/.test(env.win._alerts.slice(-1)[0] || '') && /wait a moment/.test(env.win._alerts.slice(-1)[0] || ''));
  flushAll();
  check('pending: no answer in time counts as could not check', /Could not check/.test(xMsg(env, 'spec').innerHTML)
    && /no answer from the server/.test(xMsg(env, 'spec').innerHTML));
  check('pending: ...which never blocks', saved(env));
}
{
  const t = one('SP-1', { blockSave: 'off' });
  t.stub.next = 'HANG';
  const env = boot([t.spec], t.cfg, t.stub);
  check('pending under an advisory rule: the save is not held', saved(env));
}
{
  const t = one('SP-1', { blockSave: 'confirm' });
  t.stub.next = 'HANG';
  const env = boot([t.spec], t.cfg, t.stub);
  check('pending under "confirm": held too (no dialog to answer yet)', !saved(env));
}

// ---- 3) typing asks nothing; change and blur ask --------------------------------
{
  const t = one('SP-1', { blockSave: 'hard' });
  t.stub.next = { state: 'not-found' };
  const env = boot([t.spec], t.cfg, t.stub);
  const msg = xMsg(env, 'spec');
  check('load: not-found shown', /not saved/.test(msg.innerHTML) && !saved(env));
  for (const v of ['S', 'SP', 'SP-', 'SP-2']) { t.spec.value = v; t.spec.fire('input'); }
  check('typing: the verdict is cleared at once', msg.style.display === 'none' && t.spec.getAttribute('aria-invalid') === null);
  flush();
  check('typing: no request per keystroke, even after the pause', t.stub.calls.length === 1);
  t.stub.next = { state: 'found', record: null };
  t.spec.fire('change');
  check('change: one request with the finished value', t.stub.calls.length === 2 && t.stub.calls[1].payload.values.spec === 'SP-2');
  flush();
  check('the registry echo of the same change asks nothing more', t.stub.calls.length === 2);
  t.spec.value = 'SP-3'; t.spec.fire('input');
  t.stub.next = { state: 'not-found' };
  t.spec.fire('blur');
  check('blur asks too', t.stub.calls.length === 3 && /not saved/.test(msg.innerHTML));
}
{
  // Typed, then Save at once: the click's blur is the first moment anyone asks.
  const t = one('SP-1', { blockSave: 'hard' });
  t.stub.next = { state: 'found' };
  const env = boot([t.spec], t.cfg, t.stub);
  t.spec.value = 'SP-404'; t.spec.fire('input');
  t.stub.next = { state: 'not-found' };
  check('typed then Save: the typed value is asked before the save is decided', !saved(env)
    && t.stub.calls.length === 2 && t.stub.calls[1].payload.values.spec === 'SP-404');
  t.spec.value = 'SP-500'; t.spec.fire('input');
  t.stub.next = 'HANG';
  check('typed then Save, answer still on its way: the save is held', !saved(env)
    && /still being checked/.test(env.win._alerts.slice(-1)[0] || ''));
  t.stub.next = { state: 'found' };
  t.spec.value = 'SP-1'; t.spec.fire('input');
  check('typed back to a value already answered: decided from the cache at once', saved(env) && t.stub.calls.length === 3);
}
{
  // REDCap's radio "reset" link blanks the value without an event.
  const t = one('SP-1', { blockSave: 'hard' });
  t.stub.next = { state: 'not-found' };
  const env = boot([t.spec], t.cfg, t.stub);
  check('reset: blocked while the value is not found', !saved(env));
  t.spec.value = '';
  check('reset without an event: Save re-judges the field and lets the blank through', saved(env)
    && xMsg(env, 'spec').style.display === 'none');
}
{
  // A value set without a native event (date picker, autocomplete) reaches the
  // when-registry only; it is asked after the pause.
  const t = one('SP-1', {});
  t.stub.next = { state: 'found' };
  const env = boot([t.spec], t.cfg, t.stub);
  t.spec.value = 'SP-9';
  t.spec._handlers.change.slice(-1)[0]({});   // the when-registry's own listener only
  check('a registry-only change waits for the pause', t.stub.calls.length === 1);
  flush();
  check('...and is then asked', t.stub.calls.length === 2 && t.stub.calls[1].payload.values.spec === 'SP-9');
}

// ---- 4) cache, blank, stale -------------------------------------------------------
{
  const t = one('SP-1', {});
  t.stub.next = { state: 'found' };
  const env = boot([t.spec], t.cfg, t.stub);
  t.spec.fire('change');
  check('cache: the same value is not asked twice', t.stub.calls.length === 1);
  t.stub.next = { state: 'unknown' };
  t.spec.value = 'SP-2'; t.spec.fire('change');
  t.spec.fire('change');
  check('cache: unknown is asked again', t.stub.calls.length === 3);
  t.spec.value = '   '; t.spec.fire('change');
  check('blank: inert, nothing asked', xMsg(env, 'spec').style.display === 'none' && t.stub.calls.length === 3);
}
{
  const t = one('FIRST', { blockSave: 'hard' });
  t.stub.next = 'HANG';
  const env = boot([t.spec], t.cfg, t.stub);
  t.stub.next = { state: 'found' };
  t.spec.value = 'SECOND'; t.spec.fire('change');
  check('stale: the latest request renders', /Found/.test(xMsg(env, 'spec').innerHTML) && t.stub.calls.length === 2);
}
{
  // A -> B -> A with no input event (radio, date picker): A answers from the
  // cache, and B's reply landing afterwards must not paint over it.
  const pending = [];
  const calls = [];
  const obj = { ajax(a, payload) { calls.push(payload); return { then(res) { pending.push(res); } }; } };
  const spec = makeEl('input'); spec.name = 'spec'; spec.value = 'A-1';
  const env = boot([spec], { jsmoName: JSMO, rules: [rule({ blockSave: 'hard' })] }, { obj });
  pending[0]({ state: 'found' });
  spec.value = 'B-1'; spec.fire('change');
  spec.value = 'A-1'; spec.fire('change');
  check('cache hit: A is answered without asking again', calls.length === 2 && /Found/.test(xMsg(env, 'spec').innerHTML));
  pending[1]({ state: 'not-found' });
  check('cache hit: a late reply for B is dropped', /Found/.test(xMsg(env, 'spec').innerHTML) && saved(env));
}
{
  // The first reply arrives AFTER the second: it must not overwrite it.
  const pending = [];
  const obj = { ajax() { return { then(res) { pending.push(res); } }; } };
  const spec = makeEl('input'); spec.name = 'spec'; spec.value = 'OLD';
  const env = boot([spec], { jsmoName: JSMO, rules: [rule({ blockSave: 'hard' })] }, { obj });
  spec.value = 'NEW'; spec.fire('change');
  pending[1]({ state: 'found' });
  pending[0]({ state: 'not-found' });
  check('stale: a late reply for an older value is dropped', /Found/.test(xMsg(env, 'spec').innerHTML) && saved(env));
}

// ---- 5) match fields ----------------------------------------------------------------
{
  const t = one('SP-2', { existsLocal: ['site', 'arm'], blockSave: 'hard' });
  const site = makeEl('select'); site.name = 'site'; site.value = '';
  t.stub.next = { state: 'found' };
  const env = boot([t.spec, site], t.cfg, t.stub);
  check('match: a blank match field on the page asks nothing', t.stub.calls.length === 0 && xMsg(env, 'spec').style.display === 'none');
  site.value = 'B'; site.fire('change');
  check('match: a match field change asks', t.stub.calls.length === 1);
  check('match: the on-page value travels; one not on this page is left out for the server to read',
    t.stub.calls[0].payload.values.site === 'B' && !('arm' in t.stub.calls[0].payload.values)
    && t.stub.calls[0].payload.values.spec === 'SP-2');
  site.value = 'C'; site.fire('input');
  flush();
  check('match: typing in a match field asks nothing', t.stub.calls.length === 1);
  site.fire('blur');
  check('match: ...until it is left', t.stub.calls.length === 2 && t.stub.calls[1].payload.values.site === 'C');
}

// ---- 6) surveys -----------------------------------------------------------------------
{
  const t = one('SP-2', { blockSave: 'hard' }, { context: 'survey' });
  t.stub.next = { state: 'not-found' };
  const env = boot([t.spec], t.cfg, t.stub);
  check('survey without the opt-in: inert, nothing asked', t.stub.calls.length === 0 && xMsg(env, 'spec').style.display === 'none');
}
{
  const t = one('SP-2', { blockSave: 'hard', existsSurveys: true }, { context: 'survey' });
  t.stub.next = { state: 'not-found' };
  const env = boot([t.spec], t.cfg, t.stub);
  check('survey with the opt-in: checked and blocking', /not saved/.test(xMsg(env, 'spec').innerHTML) && !saved(env));
  t.stub.next = { state: 'unknown', why: 'staff detail' };
  t.spec.value = 'SP-3'; t.spec.fire('change');
  check('survey: unknown shows nothing at all', xMsg(env, 'spec').style.display === 'none'
    && !/Could not check|staff detail/.test(xMsg(env, 'spec').innerHTML) && saved(env));
}

// ---- 7) "when" gate, deferral, config error ---------------------------------------------
{
  const t = one('SP-2', { when: "[k]='2'", blockSave: 'hard' });
  const k = makeEl('select'); k.name = 'k'; k.value = '1';
  t.stub.next = { state: 'not-found' };
  const env = boot([t.spec, k], t.cfg, t.stub);
  check('when false: inert, nothing asked', t.stub.calls.length === 0);
  k.value = '2'; k.fire('change'); flush();
  check('when true: asked and flagged', t.stub.calls.length === 1 && !saved(env));
  k.value = '1'; k.fire('change'); flush();
  check('when false again: cleared and released', xMsg(env, 'spec').style.display === 'none' && saved(env));
}
{
  const t = one('SP-2', { deferred: true, deferredWhy: ['[x] is on another form.'], blockSave: 'hard' });
  const env = boot([t.spec], t.cfg, t.stub);
  check('deferred: notice for staff, nothing asked', t.stub.calls.length === 0 && /another form/.test(xMsg(env, 'spec').innerHTML));
  check('deferred: never blocks', saved(env));
}
{
  const t = one('SP-2', { deferred: true, deferredWhy: ['[x] is on another form.'], existsSurveys: true }, { context: 'survey' });
  const env = boot([t.spec], t.cfg, t.stub);
  check('deferred on a survey: nothing shown, nothing asked', t.stub.calls.length === 0 && xMsg(env, 'spec').style.display === 'none');
}
{
  const t = one('SP-2', { blockSave: 'always' });
  const env = boot([t.spec], t.cfg, t.stub);
  check('bad blockSave: config error, nothing asked', t.stub.calls.length === 0
    && env.allEls.some((e) => /blockSave must be/.test(e.innerHTML)));
}

// ---- 7b) branches: the page's values travel, typing in a "when" field asks nothing -----------
{
  const spec = makeEl('input'); spec.name = 'spec'; spec.value = 'SP-2';
  const site = makeEl('select'); site.name = 'site'; site.value = 'A';
  const tick = makeEl('input'); tick.name = '__chk__tk_RC_3'; tick.type = 'checkbox'; tick.checked = true;
  const stub = makeTransportStub(); stub.next = { state: 'found' };
  const env = boot([spec, site, tick], { jsmoName: JSMO, rules: [{ type: 'exists', fields: ['spec'], branches: [
    { when: "[site]='A' and ([tk(3)]='1' or [gone]='x')", blockSave: 'hard' },
    { when: "[site]='B'", blockSave: 'off' }] }] }, stub);
  const c0 = stub.calls[0] && stub.calls[0].payload.cond;
  check('cond: the "when" fields on the page travel with their values', c0 && c0.site === 'A' && c0.tk && c0.tk['3'] === '1');
  check('cond: a "when" field not on the page is left out', c0 && !('gone' in c0));
  site.value = 'B'; site.fire('change'); flush();
  check('cond: a branch change asks with the new value', stub.calls.length === 2 && stub.calls[1].payload.cond.site === 'B');
  site.value = 'A'; site.fire('change'); flush();
  check('cond: flipping back is answered from the cache', stub.calls.length === 2);
  check('cond: ...and the branch\'s own blockSave applies again', /Found/.test(xMsg(env, 'spec').innerHTML) && saved(env));
}
{
  const spec = makeEl('input'); spec.name = 'spec'; spec.value = 'SP-2';
  const code = makeEl('input'); code.name = 'code'; code.type = 'text'; code.value = '';
  const stub = makeTransportStub(); stub.next = { state: 'found' };
  const env = boot([spec, code], { jsmoName: JSMO, rules: [{ type: 'exists', fields: ['spec'], branches: [
    { when: "[code]='YES'", blockSave: 'hard' }] }] }, stub);
  for (const v of ['Y', 'YE', 'YES', 'YESS', 'YES']) { code.value = v; code.fire('input'); flush(); }
  check('when field typing: nothing asked while typing', stub.calls.length === 0);
  code.fire('blur');
  check('when field typing: asked once it is left', stub.calls.length === 1 && stub.calls[0].payload.cond.code === 'YES');
}

// ---- 7c) a branch conflict leaves other modes' blocks alone -----------------------------------
{
  const spec = makeEl('input'); spec.name = 'spec'; spec.value = '0ABC00001X';
  const fa = makeEl('input'); fa.name = 'fa'; fa.value = '1';
  const fb = makeEl('input'); fb.name = 'fb'; fb.value = '1';
  const stub = makeTransportStub();
  const env = boot([spec, fa, fb], { jsmoName: JSMO, rules: [
    { type: 'single', fields: ['spec'], algorithm: 'iso7064_mod37_36', blockSave: 'hard' },
    { type: 'exists', fields: ['spec'], branches: [{ when: "[fa]='1'", blockSave: 'off' }, { when: "[fb]='1'", blockSave: 'off' }] },
  ] }, stub);
  check('conflict: the @UVEXISTS conflict is shown', env.allEls.some((e) => /Validation conflict/.test(e.innerHTML)));
  check('conflict: the @UVALIDATE block on the same field still holds', !saved(env));
}

// ---- 7d) autocomplete dropdown -------------------------------------------------------------
{
  // REDCap has already rendered its widget next to the select when the field binds.
  const sel = makeEl('select'); sel.name = 'spec'; sel.value = 'SP-2';
  const stub = makeTransportStub(); stub.next = { state: 'found' };
  const wrap = makeEl('span');
  const ac = makeEl('input'); ac.id = 'rc-ac-input_spec'; ac.type = 'text';
  wrap.appendChild(ac);
  const env = boot([sel], { jsmoName: JSMO, rules: [rule({ blockSave: 'hard' })] }, stub, ({ holders, allEls }) => {
    holders.spec.appendChild(wrap); allEls.push(wrap, ac);
  });
  const m = xMsg(env, 'spec');
  const kids = env.holders.spec.children;
  check('autocomplete: the select and its widget stay side by side', kids.indexOf(sel) + 1 === kids.indexOf(wrap));
  check('autocomplete: the message follows the widget', kids.indexOf(m) === kids.indexOf(wrap) + 1);
  check('autocomplete: the widget carries the found outline', /2e9e44/.test(ac.style.outline || ''));
  stub.next = { state: 'not-found' };
  sel.value = 'SP-404'; sel.fire('change');
  check('autocomplete: the visible widget is outlined and described', /c62828/.test(ac.style.outline || '')
    && (ac.getAttribute('aria-describedby') || '').split(' ').indexOf(m.id) >= 0 && ac.getAttribute('aria-invalid') === 'true');
}
{
  // ...or renders it after the field binds: the message still ends up after it.
  const sel = makeEl('select'); sel.name = 'spec'; sel.value = 'SP-2';
  const stub = makeTransportStub(); stub.next = { state: 'not-found' };
  const wrap = makeEl('span');
  const ac = makeEl('input'); ac.id = 'rc-ac-input_spec'; ac.type = 'text';
  wrap.appendChild(ac);
  const env = boot([sel], { jsmoName: JSMO, rules: [rule({ blockSave: 'hard' })] }, stub);
  env.holders.spec.insertBefore(wrap, sel.nextSibling);
  env.allEls.push(wrap, ac);
  sel.fire('change');
  const kids = env.holders.spec.children;
  check('autocomplete, late widget: select, widget, message in that order',
    kids.indexOf(sel) + 1 === kids.indexOf(wrap) && kids.indexOf(xMsg(env, 'spec')) === kids.indexOf(wrap) + 1);
}

// ---- 8) composition with @UVUNIQUE ---------------------------------------------------------
{
  const calls = [];
  const obj = { ajax(action, payload) {
    calls.push(action);
    const r = action === 'unique-check' ? { used: false, record: null } : { state: 'not-found' };
    return { then(res) { res(r); } };
  } };
  const spec = makeEl('input'); spec.name = 'spec'; spec.value = 'SP-2';
  const env = boot([spec], { jsmoName: JSMO, rules: [
    { type: 'unique', fields: ['spec'], blockSave: 'hard' },
    { type: 'exists', fields: ['spec'], blockSave: 'hard' },
  ] }, { obj });
  check('compose: both lookups asked', calls.indexOf('unique-check') >= 0 && calls.indexOf('exists-check') >= 0);
  check('compose: no duplicate-rule notice', !env.allEls.some((e) => e.id === 'uvalidate-config-errors'));
  check('compose: exists blocks although unique passes', !saved(env));
  check('compose: each mode has its own message region', env.allEls.filter((e) => e.id && /^uvalidate-msg-/.test(e.id)).length >= 2);
}

check('published in the namespace', (() => { const t = one('x'); const env = boot([t.spec], { rules: [] }, null); return typeof env.NS.existsInit === 'function'; })());

global.setTimeout = realSetTimeout; global.clearTimeout = realClearTimeout; console.error = realConsoleError;
console.log(`exists_dom_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
