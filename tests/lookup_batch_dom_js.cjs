/*
 * lookup_batch_dom_js.cjs - the lookups a page asks in one pass go out as one
 * request (js/engine.js QRID_lookup; server twin LiveLookups::lookupBatch,
 * locked by tests/exists_php.php).
 *
 * Measured on a live server on 2026-11-12: the framework sends module
 * requests one after another, and each lookup took a third of a second to a
 * second, so a form with six lookup fields showed its last answer six round
 * trips after it opened.
 *
 * Asserts:
 *   - a page that opens with several filled lookup fields (@UVUNIQUE and
 *     @UVEXISTS together) sends ONE "lookup-batch" request with one item per
 *     field, and each field shows its own answer,
 *   - a page with one lookup field sends the plain request, as before,
 *   - after the page has started, a field that is left asks alone,
 *   - an item the server answers null fails open for that field only,
 *   - a server that does not know the action (an answer with no "answers",
 *     or a rejected request) is asked again field by field,
 *   - more than QRID_LOOKUP_BATCH_MAX lookups go out in batches of that size,
 *   - a page still loading batches the lookups its DOMContentLoaded start asks.
 *
 * Run:  node tests/lookup_batch_dom_js.cjs
 */
'use strict';
const path = require('path');

let n = 0, fail = 0;
function check(label, cond, got) {
  n++;
  if (!cond) { fail++; console.error('FAIL: ' + label + (got !== undefined ? ' (got ' + JSON.stringify(got) + ')' : '')); }
}

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

const JSMO = 'EMStub.UV';
const BATCH = 'lookup-batch';

/* What the server says about one lookup: by the value asked. */
function answerOf(action, payload) {
  const v = payload.values[payload.field];
  if (action === 'unique-check') return { used: /^USED/.test(v), record: /^USED/.test(v) ? '7' : null };
  return /^SP-404/.test(v) ? { state: 'not-found' } : { state: 'found', record: '3' };
}
/* A transport that knows "lookup-batch". opts.batch replaces its answer to a
   batch: a function (items) => answer, or 'reject'. */
function makeStub(opts) {
  opts = opts || {};
  const stub = { calls: [] };
  stub.obj = {
    ajax(action, payload) {
      stub.calls.push({ action, payload: JSON.parse(JSON.stringify(payload)) });
      if (action === BATCH) {
        if (opts.batch === 'reject') return { then(res, rej) { rej('refused'); } };
        const r = opts.batch ? opts.batch(payload.items)
          : { answers: payload.items.map((it) => answerOf(it.action, it.payload)) };
        return { then(res) { res(JSON.parse(JSON.stringify(r))); } };
      }
      return { then(res) { res(answerOf(action, payload)); } };
    },
  };
  return stub;
}

/* fields: [[name, value, rule type]]. One rule per field. */
function boot(fields, stub, readyState) {
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  const allEls = [];
  const body = makeEl('body');
  const els = {}, holders = {};
  for (const [name, value] of fields) {
    const el = makeEl('input'); el.name = name; el.value = value;
    const holder = makeEl('div');
    holder.appendChild(el); body.appendChild(holder);
    allEls.push(el, holder);
    els[name] = el; holders[name] = holder;
  }
  const doc = {
    body, readyState: readyState || 'complete', _handlers: {},
    createElement(t) { const e = makeEl(t); allEls.push(e); return e; },
    getElementById(id) { return allEls.find((e) => e.id === id) || null; },
    getElementsByName(name) { return allEls.filter((e) => e.name === name); },
    querySelector() { return null; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev)); },
  };
  const rules = fields.map(([name, , type]) => (type === 'exists'
    ? { type: 'exists', fields: [name], existsIn: 'specimen_id', blockSave: 'hard' }
    : { type: 'unique', fields: [name], blockSave: 'hard' }));
  const win = {
    _alerts: [], alert(m) { this._alerts.push(m); }, confirm() { return true; },
    EMStub: { UV: stub.obj },
    INSPIRE_VALIDATOR_CONFIG: { singleFields: [], pooledFields: [], jsmoName: JSMO, rules },
  };
  global.document = doc; global.window = win;
  require(enginePath);
  const msg = (name) => {
    const h = holders[name];
    const m = h.children.find((k, i) => i > 0 && k.id && /^uvalidate-msg-/.test(k.id)) || h.children[1];
    return m ? m.innerHTML : '';
  };
  return { doc, win, els, msg };
}

// ---- 1) several lookup fields at page open: one request --------------------------------
{
  const stub = makeStub();
  const env = boot([['a', 'FREE-1', 'unique'], ['b', 'USED-2', 'unique'], ['s', 'SP-1', 'exists'], ['t', 'SP-404', 'exists']], stub);
  check('1 four filled lookup fields: one request', stub.calls.length === 1, stub.calls.map((c) => c.action));
  const c = stub.calls[0];
  check('1 it is a "lookup-batch"', c.action === BATCH);
  check('1 one item per field, each the request the field would have sent alone',
    c.payload.items.length === 4
    && c.payload.items.map((it) => it.action).join(',') === 'unique-check,unique-check,exists-check,exists-check'
    && c.payload.items.map((it) => it.payload.field).join(',') === 'a,b,s,t'
    && c.payload.items[1].payload.values.b === 'USED-2', c.payload.items);
  check('1 each field shows its own answer',
    /Not used before/.test(env.msg('a')) && /already recorded/.test(env.msg('b'))
    && /Found/.test(env.msg('s')) && /not/i.test(env.msg('t')) && !/Found/.test(env.msg('t')),
    [env.msg('a'), env.msg('b'), env.msg('s'), env.msg('t')]);
  // After the start a field asks alone.
  env.els.a.value = 'FREE-9'; env.els.a.fire('input'); env.els.a.fire('change');
  check('1 a field left later asks alone, with the plain request',
    stub.calls.length === 2 && stub.calls[1].action === 'unique-check' && stub.calls[1].payload.values.a === 'FREE-9', stub.calls.slice(1));
}
// ---- 2) blank fields ask nothing; one filled field sends the plain request ----------------
{
  const stub = makeStub();
  boot([['a', '', 'unique'], ['b', 'FREE-2', 'unique'], ['s', '', 'exists']], stub);
  check('2 one filled lookup field: the plain request, no batch',
    stub.calls.length === 1 && stub.calls[0].action === 'unique-check' && stub.calls[0].payload.field === 'b', stub.calls);
}
{
  const stub = makeStub();
  boot([['a', '', 'unique'], ['s', '', 'exists']], stub);
  check('2 no filled lookup field: no request', stub.calls.length === 0);
}
// ---- 3) an item answered null fails open for that field only ---------------------------
{
  const stub = makeStub({ batch: (items) => ({ answers: items.map((it, i) => (i === 1 ? null : answerOf(it.action, it.payload))) }) });
  const env = boot([['a', 'FREE-1', 'unique'], ['b', 'USED-2', 'unique'], ['s', 'SP-1', 'exists']], stub);
  check('3 the item with no answer says it could not be checked, and is not asked again',
    /Could not check/.test(env.msg('b')) && stub.calls.length === 1, env.msg('b'));
  check('3 the other fields show their answers', /Not used before/.test(env.msg('a')) && /Found/.test(env.msg('s')));
}
// ---- 4) a server that does not know the action ---------------------------------------
for (const [what, batch] of [
  ['an answer with no "answers"', () => ({ error: 'unknown action' })],
  ['an answer of another length', (items) => ({ answers: items.slice(1).map((it) => answerOf(it.action, it.payload)) })],
  ['a rejected request', 'reject'],
]) {
  const stub = makeStub({ batch });
  const env = boot([['a', 'FREE-1', 'unique'], ['b', 'USED-2', 'unique'], ['s', 'SP-1', 'exists']], stub);
  check('4 ' + what + ': each field is asked again alone',
    stub.calls.map((c) => c.action).join(',') === BATCH + ',unique-check,unique-check,exists-check', stub.calls.map((c) => c.action));
  check('4 ' + what + ': and shows its answer',
    /Not used before/.test(env.msg('a')) && /already recorded/.test(env.msg('b')) && /Found/.test(env.msg('s')));
}
// ---- 5) more lookups than one batch carries ------------------------------------------
{
  const stub = makeStub();
  const many = [];
  for (let i = 0; i < 45; i++) many.push(['u' + i, 'FREE-' + i, 'unique']);
  const env = boot(many, stub);
  check('5 45 lookups: a batch of 40 and a batch of 5',
    stub.calls.length === 2 && stub.calls[0].payload.items.length === 40 && stub.calls[1].payload.items.length === 5,
    stub.calls.map((c) => c.payload.items && c.payload.items.length));
  check('5 the last field has its answer', /Not used before/.test(env.msg('u44')));
}
// ---- 6) a page still loading ---------------------------------------------------------
{
  const stub = makeStub();
  const env = boot([['a', 'FREE-1', 'unique'], ['s', 'SP-1', 'exists']], stub, 'loading');
  check('6 a page still loading asks nothing yet', stub.calls.length === 0);
  env.doc.fire('DOMContentLoaded', {});
  check('6 its start asks in one request',
    stub.calls.length === 1 && stub.calls[0].action === BATCH && stub.calls[0].payload.items.length === 2, stub.calls.map((c) => c.action));
  check('6 and the fields show their answers', /Not used before/.test(env.msg('a')) && /Found/.test(env.msg('s')));
  env.els.a.value = 'FREE-9'; env.els.a.fire('input'); env.els.a.fire('change');
  check('6 a field left later asks alone', stub.calls.length === 2 && stub.calls[1].action === 'unique-check');
}

console.log(`lookup_batch_dom_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
