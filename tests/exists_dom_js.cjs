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
 *     keystroke: typing clears the verdict and asks nothing,
 *   - the payload carries the value and the "match" fields; a blank match field
 *     on the page asks nothing, one not on the page travels blank,
 *   - one-deep cache of found / not-found answers; unknown is asked again,
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

/* The engine reports lookup failures on console.error; collect them per test. */
const realConsoleError = console.error;
let consoleLog = [];
console.error = (m) => { if (/^FAIL: /.test(String(m))) realConsoleError(m); else consoleLog.push(String(m)); };

/* Fake timers: the debounced re-check runs only when the test says so. */
const realSetTimeout = global.setTimeout, realClearTimeout = global.clearTimeout;
let timers = [], timerSeq = 0;
global.setTimeout = (fn) => { const id = ++timerSeq; timers.push({ id, fn }); return id; };
global.clearTimeout = (id) => { timers = timers.filter((t) => t.id !== id); };
function flush() { const due = timers; timers = []; due.forEach((t) => t.fn()); }

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
  check('pending: never blocks', saved(env));
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
  check('typing: the save is no longer trapped by the stale verdict', saved(env));
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
  check('match: on-page value and off-page blank travel', t.stub.calls[0].payload.values.site === 'B'
    && t.stub.calls[0].payload.values.arm === '' && t.stub.calls[0].payload.values.spec === 'SP-2');
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
