/*
 * justify_dom_js.cjs — the @UVJUSTIFY mode's DOM contract.
 *
 * Drives the real QRIDJustifyInit factory, beside the factories of the rules
 * a reason stands for, through the DOM stub the other *_dom tests use, and
 * asserts:
 *   - while a rule a reason stands for holds the save and the reason field
 *     gives no reason, the save message adds "or give a reason in" with the
 *     reason field, and a note under the field asks for one, in the rule's
 *     own words when it has a message (escaped), else the default;
 *   - once the reason field gives a reason (QRID_reasonGiven: not blank, not
 *     a Missing Data Code) the field no longer holds the save and the note
 *     goes, while the rule's own note stays; clearing the reason brings both
 *     back; this holds for "hard" and "confirm", in either boot order;
 *   - a reason never adds or lifts anything else: a rule with blockSave
 *     "off" only warns and gets no note, a lookup still being checked still
 *     holds the save, a duplicate (@UVUNIQUE) still holds it, and a locked
 *     write-once field stays read-only;
 *   - a reason field whose row branching logic hides gives no reason, a
 *     reason field not on this page leaves the tag doing nothing, and a
 *     reason in a radio or dropdown field counts, including a change no
 *     event announces;
 *   - a configuration error is shown under the field and releases nothing;
 *   - the note asks only while the save guard would hold (QRID_itemHolds):
 *     none under a field made read-only after bind or hidden by branching,
 *     and a reason row that doBranching() hides brings the note back at once;
 *   - a reason field embedded in a field's label ({wt_why}) is judged by the
 *     row it sits in: typed there it releases the save, and that row hidden
 *     by branching gives no reason;
 *   - a checkbox field (@UVCHOICES), which has no input of its own name, is
 *     paired by its options: a hidden code ticked holds the save and asks
 *     for the reason in a note that stays shown beside the options, even
 *     with the first option hidden, and a reason releases it;
 *   - a reason label holding "$&" or "$$" shows as written, and the note
 *     under an autocomplete dropdown sits after its text box;
 *   - the reason label leaves out REDCap's icons cell (.rc-field-icons),
 *     where the Missing Data Code menu sits (LIVE-D3).
 *
 * Run:  node tests/justify_dom_js.cjs
 */
'use strict';
const path = require('path');
const PAGE_DEFAULTS = require('./page_defaults.json').defaults;

let n = 0, fail = 0;
function check(label, cond, got) {
  n++;
  if (!cond) { fail++; console.error('FAIL: ' + label + (got !== undefined ? ' (got ' + JSON.stringify(got) + ')' : '')); }
}

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), nodeType: 1, id: '', name: '', value: '', innerHTML: '',
    type: '', checked: false, className: '',
    style: { set cssText(v) { this._css = v; const m = /display:\s*([a-z]+)/.exec(v); this.display = m ? m[1] : ''; },
             get cssText() { return this._css || ''; } },
    _attrs: {}, children: [], parentNode: null, readOnly: false, disabled: false,
    _handlers: {},
    setAttribute(k, v) { this._attrs[k] = String(v); if (k === 'id') this.id = String(v); },
    getAttribute(k) { return (k in this._attrs) ? this._attrs[k] : (k === 'id' ? (this.id || null) : null); },
    removeAttribute(k) { delete this._attrs[k]; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    dispatchEvent(ev) { this.fire(ev.type, ev); return true; },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn.call(this, ev || {})); },
    appendChild(c) { if (c.parentNode) c.parentNode.removeChild(c); c.parentNode = this; this.children.push(c); return c; },
    insertBefore(node, ref) {
      if (node.parentNode) node.parentNode.removeChild(node);
      node.parentNode = this;
      const i = ref ? this.children.indexOf(ref) : -1;
      if (i >= 0) this.children.splice(i, 0, node); else this.children.push(node);
      return node;
    },
    removeChild(c) { const i = this.children.indexOf(c); if (i >= 0) this.children.splice(i, 1); c.parentNode = null; return c; },
    getElementsByTagName(t) {
      const out = [];
      const walk = (e) => { for (const c of e.children) { if (c.tagName === t.toUpperCase()) out.push(c); walk(c); } };
      walk(this);
      return out;
    },
    closest() { return null; },
    querySelectorAll(sel) {
      const classes = String(sel).split(',').map((x) => x.trim().replace(/^\./, ''));
      const out = [];
      const walk = (e) => { for (const c of e.children) { if (classes.some((k) => (' ' + c.className + ' ').indexOf(' ' + k + ' ') >= 0)) out.push(c); walk(c); } };
      walk(this);
      return out;
    },
    focus() { this._focused = true; },
    get nextSibling() {
      const i = this.parentNode ? this.parentNode.children.indexOf(this) : -1;
      return i >= 0 ? (this.parentNode.children[i + 1] || null) : null;
    },
    get firstChild() { return this.children[0] || null; },
  };
}
global.Event = function (type) { this.type = type; };

/* Fake timers: the note follows a tick after each change (setTimeout 0), and a
   lookup's wait for its answer is a long timer. flush() runs the short ones
   until none is left; the long ones run only when a test says so. */
let timers = [], timerSeq = 0;
global.setTimeout = (fn, ms) => { const id = ++timerSeq; timers.push({ id, fn, ms: ms || 0 }); return id; };
global.clearTimeout = (id) => { timers = timers.filter((t) => t.id !== id); };
function flush() {
  for (let round = 0; round < 20; round++) {
    const due = timers.filter((t) => t.ms < 5000);
    if (!due.length) return;
    timers = timers.filter((t) => t.ms >= 5000);
    due.forEach((t) => t.fn());
  }
}

/* A transport whose next reply the test sets: an object, or 'HANG'. */
function makeTransportStub() {
  const stub = { calls: [], next: null };
  stub.obj = {
    ajax(action, payload) {
      stub.calls.push({ action, payload: JSON.parse(JSON.stringify(payload)) });
      const resp = stub.next;
      return { then(res) { if (resp !== 'HANG' && resp !== null) res(JSON.parse(JSON.stringify(resp))); } };
    },
  };
  return stub;
}

/* Boot the engine over els (each in its own holder; els with a row id share
   a row) and return the page. Interval callbacks are kept, so a test can run
   the silent-change poll by hand (poll()). */
function boot(els, config, opts) {
  opts = opts || {};
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  timers = [];
  const allEls = [];
  const body = makeEl('body');
  const holders = {};
  const rows = {};
  /* a holder sits in the body, or in the row named by el._inRow (a checkbox
     option's own row inside its field's row) */
  const rowOf = (id) => {
    if (!rows[id]) { const r = makeEl(/-tr$/.test(id) ? 'tr' : 'div'); r.id = id; rows[id] = r; body.appendChild(r); allEls.push(r); }
    return rows[id];
  };
  for (const el of els) {
    let holder = el._row ? rows[el._row] : null;
    if (!holder) {
      /* a field's row ("<field>-tr") is a table row, as REDCap draws it */
      holder = makeEl(el._row && /-tr$/.test(el._row) ? 'tr' : 'div');
      if (el._row) { holder.id = el._row; rows[el._row] = holder; }
      (el._inRow ? rowOf(el._inRow) : body).appendChild(holder);
      allEls.push(holder);
    }
    holder.appendChild(el);
    allEls.push(el);
    if (el.name && !holders[el.name]) holders[el.name] = holder;
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
    _alerts: [], _confirms: [], confirmAnswer: false,
    alert(m) { this._alerts.push(m); }, confirm(m) { this._confirms.push(m); return this.confirmAnswer; },
    /* REDCap's branching: the test sets a row's display itself, then calls this, as REDCap does */
    doBranching() {},
    INSPIRE_VALIDATOR_CONFIG: Object.assign({ singleFields: [], pooledFields: [] }, PAGE_DEFAULTS, config),
  };
  if (opts.stub) win.EMStub = { UV: opts.stub.obj };
  global.document = doc; global.window = win;
  const intervals = [];
  global.setInterval = (fn) => { intervals.push(fn); return { unref() {} }; };
  global.clearInterval = () => {};
  require(enginePath);
  /* REDCap's own start-up (jQuery's ready handlers, @READONLY among them)
     runs after the engine bound the fields and before its first tick */
  if (opts.ready) opts.ready();
  flush();
  return { doc, win, holders, allEls, NS: win.INSPIREUniversalValidator, poll() { intervals.forEach((f) => f()); flush(); } };
}
function region(env, field, suffix) {
  const kids = env.holders[field].children;
  for (const k of kids) if (k.id && new RegExp('-' + suffix + '$').test(k.id)) return k;
  return null;
}
function shown(env, field, suffix) {
  const m = region(env, field, suffix);
  return m && m.style.display !== 'none' ? m.innerHTML : '';
}
function submitEv() {
  return { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
}
/* Submit and say what the guard did: "held" (alert), "asked" (confirm) or "saved".
   by: the name of the button that asked for the save (a survey's Previous Page
   or Save & Return Later), none for a plain submit. */
function save(env, by) {
  const a = env.win._alerts.length, c = env.win._confirms.length;
  const ev = submitEv();
  if (by) { const b = makeEl('button'); b.setAttribute('name', by); ev.submitter = b; }
  env.doc.fire('submit', ev);
  if (env.win._alerts.length > a) return 'held';
  if (env.win._confirms.length > c) return 'asked';
  return ev._prevented ? 'prevented' : 'saved';
}
function said(env) { return env.win._alerts[env.win._alerts.length - 1] || env.win._confirms[env.win._confirms.length - 1] || ''; }
function text(name, value) { const e = makeEl('input'); e.name = name; e.type = 'text'; e.value = value; return e; }
function type(env, el, value) { el.value = value; el.fire('input'); el.fire('change'); flush(); }
function option(v) { const o = makeEl('option'); o.value = v; o.text = v; return o; }
function select(name, codes, value) {
  const s = makeEl('select'); s.name = name;
  s.options = codes.map((c) => option(c));
  s.value = value;
  return s;
}
/* A REDCap radio field: the hidden mirror and one button per code, in one row. */
function radioField(name, codes, value) {
  const row = name + '-tr';
  const mirror = makeEl('input'); mirror.name = name; mirror.type = 'text'; mirror.value = value; mirror._row = row;
  const buttons = codes.map((c) => { const b = makeEl('input'); b.name = name + '___radio'; b.type = 'radio'; b.value = c; b.checked = c === value; b._row = row; return b; });
  return { mirror, buttons, els: [mirror].concat(buttons) };
}
const J = (fields, reason, extra) => Object.assign({ type: 'justify', fields, justifyReason: reason }, extra || {});
const LIGHT = (blockSave, extra) => Object.assign({ type: 'constraint', fields: ['wt'], assert: '[wt]>40', blockSave, message: 'Too light' }, extra || {});
const NOTE = (label) => 'Fix this value, or say why it is right in “' + label + '”.';

// ---- 1) a hard rule: held until a reason is given -----------------------------------
for (const order of ['reason first', 'reason last']) {
  const wt = text('wt', '30'), why = text('wt_why', '');
  const rules = order === 'reason first' ? [J(['wt'], 'wt_why'), LIGHT('hard')] : [LIGHT('hard'), J(['wt'], 'wt_why')];
  const env = boot([wt, why], { rules });
  check(order + ': the broken rule holds the save', save(env) === 'held');
  check(order + ': ... and the message says a reason in the reason field would do', /wt.*\(or give a reason in “wt_why”\)/.test(said(env)), said(env));
  check(order + ': a note under the field asks for the reason', shown(env, 'wt', 'j') === NOTE('wt_why'), shown(env, 'wt', 'j'));
  check(order + ': ... in its own region (suffix "-j"), apart from the rule\'s note', /Too light/.test(shown(env, 'wt', 'c')));

  type(env, why, 'scale recalibrated on the day');
  check(order + ': with a reason given the save goes through', save(env) === 'saved');
  check(order + ': ... the note asking for it goes', shown(env, 'wt', 'j') === '');
  check(order + ': ... and the rule\'s own note stays', /Too light/.test(shown(env, 'wt', 'c')));

  type(env, why, '   ');
  check(order + ': spaces are no reason: held again', save(env) === 'held');
  check(order + ': ... and the note is back', shown(env, 'wt', 'j') === NOTE('wt_why'));
  // blank as @UVREQUIRED reads it (QRID_isBlank, REQ-8)
  type(env, why, ' ​﻿　\f');
  check(order + ': no-break, zero-width and ideographic spaces, a byte-order mark and a form feed are no reason: held',
    save(env) === 'held' && shown(env, 'wt', 'j') === NOTE('wt_why'), shown(env, 'wt', 'j'));
  type(env, why, ' re-weighed');
  check(order + ': ... a word after a no-break space is one', save(env) === 'saved' && shown(env, 'wt', 'j') === '');
  type(env, why, '');

  type(env, wt, '50');
  check(order + ': once the value is fixed, nothing holds and nothing asks', save(env) === 'saved' && shown(env, 'wt', 'j') === '');
}

// ---- 2) a Missing Data Code is no reason ---------------------------------------------
{
  const wt = text('wt', '30'), why = text('wt_why', 'UNK');
  const env = boot([wt, why], { missingCodes: ['UNK', 'NA'], rules: [LIGHT('hard'), J(['wt'], 'wt_why')] });
  check('a Missing Data Code in the reason field is no reason', save(env) === 'held' && shown(env, 'wt', 'j') !== '');
  type(env, why, 'UNK because the scale broke');
  check('... text that only starts with one is', save(env) === 'saved');
}

// ---- 3) a reason saved before: no note at all, the save goes through -------------------
{
  const wt = text('wt', '30'), why = text('wt_why', 'patient on steroids');
  const env = boot([wt, why], { rules: [J(['wt'], 'wt_why'), LIGHT('hard')] });
  check('a reason already given: no note at boot', shown(env, 'wt', 'j') === '');
  check('... and the save goes through', save(env) === 'saved');
}

// ---- 4) "confirm": asked until a reason is given -----------------------------------------
{
  const wt = text('wt', '30'), why = text('wt_why', '');
  const env = boot([wt, why], { rules: [LIGHT('confirm'), J(['wt'], 'wt_why')] });
  check('a confirm rule asks', save(env) === 'asked');
  check('... naming the reason field', /or give a reason in “wt_why”/.test(said(env)), said(env));
  check('... with the note under the field', shown(env, 'wt', 'j') !== '');
  type(env, why, 'checked twice');
  check('with a reason it no longer asks', save(env) === 'saved' && shown(env, 'wt', 'j') === '');
}

// ---- 5) "off" only warns: no hold, no note, reason or not ------------------------------------
{
  const wt = text('wt', '30'), why = text('wt_why', '');
  const env = boot([wt, why], { rules: [LIGHT('off'), J(['wt'], 'wt_why')] });
  check('an "off" rule holds nothing', save(env) === 'saved');
  check('... and gets no note asking for a reason', shown(env, 'wt', 'j') === '');
  check('... though its own warning shows', /Too light/.test(shown(env, 'wt', 'c')));
}
{
  // a range whose broken tier only warns: its guard item holds nothing, so no note asks for a reason
  const wt = text('wt', '120'), why = text('wt_why', '');
  const env = boot([wt, why], { rules: [{ type: 'range', fields: ['wt'], rangeSoftLo: '40', rangeSoftHi: '100', rangeSoftBlock: 'off',
    rangeHardLo: '20', rangeHardHi: '250', rangeHardBlock: 'hard', rangeSoftText: '40 to 100', rangeHardText: '20 to 250' }, J(['wt'], 'wt_why')] });
  wt.fire('blur'); flush();
  check('a range outside its "off" tier holds nothing', save(env) === 'saved');
  flush();
  check('... and gets no note asking for a reason', shown(env, 'wt', 'j') === '', shown(env, 'wt', 'j'));
  type(env, wt, '300'); wt.fire('blur'); flush();
  check('... while its "hard" tier holds and asks', save(env) === 'held' && (flush(), shown(env, 'wt', 'j') !== ''));
}

// ---- 6) a required rule (a mode a reason stands for) ------------------------------------------
{
  const wt = text('wt', ''), why = text('wt_why', '');
  const env = boot([wt, why], { rules: [{ type: 'required', fields: ['wt'], blockSave: 'hard' }, J(['wt'], 'wt_why')] });
  check('a blank required field holds the save, naming the reason field', save(env) === 'held' && /or give a reason in/.test(said(env)), said(env));
  type(env, why, 'not measured: patient in theatre');
  check('... a reason releases it', save(env) === 'saved');
}

// ---- 7) a reason never lifts a hold it does not stand for ---------------------------------------
{
  // a lookup still being checked still waits
  const stub = makeTransportStub(); stub.next = 'HANG';
  const spec = text('spec', 'SP-1'), why = text('spec_why', 'lab sent it late');
  const env = boot([spec, why], { jsmoName: 'EMStub.UV', rules: [{ type: 'exists', fields: ['spec'], blockSave: 'hard' }, J(['spec'], 'spec_why')] }, { stub });
  /* the line names the wait and offers no reason, which could not release it */
  check('a lookup still being checked holds the save, reason or not, and offers no reason',
    save(env) === 'held' && said(env) === 'Cannot save yet — please wait a moment and save again:\n• spec (still being checked)', said(env));
  type(env, why, '');
  check('... nor with the reason field empty',
    save(env) === 'held' && said(env) === 'Cannot save yet — please wait a moment and save again:\n• spec (still being checked)', said(env));
}
{
  // a lookup held by the server's limit (UNQ-3) waits too, and says why
  const stub = makeTransportStub();
  stub.next = { state: 'unknown', why: 'too many checks in the last minute', throttled: true };
  const spec = text('spec', 'SP-1'), why = text('spec_why', 'lab sent it late');
  const env = boot([spec, why], { jsmoName: 'EMStub.UV', rules: [{ type: 'exists', fields: ['spec'], blockSave: 'hard' }, J(['spec'], 'spec_why')] }, { stub });
  check('a throttled lookup holds the save with its own words and offers no reason',
    save(env) === 'held'
    && said(env) === 'Cannot save yet — please wait a moment and save again:\n• spec (not checked yet: too many checks in the last minute)', said(env));
}
{
  // a not-found answer is released by the reason
  const stub = makeTransportStub(); stub.next = { state: 'not-found' };
  const spec = text('spec', 'SP-1'), why = text('spec_why', '');
  const env = boot([spec, why], { jsmoName: 'EMStub.UV', rules: [{ type: 'exists', fields: ['spec'], blockSave: 'hard' }, J(['spec'], 'spec_why')] }, { stub });
  check('a value not found holds the save without a reason', save(env) === 'held' && /or give a reason in/.test(said(env)), said(env));
  type(env, why, 'specimen logged on paper');
  check('... and not with one', save(env) === 'saved');
}
{
  /* Save asks again about a "not found" older than QRID_RECHECK_MS (30 s)
     and waits for the answer; with the field's reason given it has nothing
     to wait for, since the reason releases a "not found" and a "found"
     holds nothing. */
  const realNow = Date.now; let shift = 0; Date.now = () => realNow() + shift;
  try {
    const stub = makeTransportStub(); stub.next = { state: 'not-found' };
    const spec = text('spec', 'SP-1'), why = text('spec_why', 'specimen logged on paper');
    const env = boot([spec, why], { jsmoName: 'EMStub.UV', rules: [{ type: 'exists', fields: ['spec'], blockSave: 'hard' }, J(['spec'], 'spec_why')] }, { stub });
    check('a reason saved before: the save within 30 s goes through', save(env) === 'saved', said(env));
    shift += 31000; stub.next = 'HANG';
    const asked = stub.calls.length;
    check('31 s later, the reason still given: the save goes through without waiting', save(env) === 'saved', said(env));
    check('... and asks the server nothing', stub.calls.length === asked, stub.calls.length - asked);
    type(env, why, '');
    shift += 31000;
    check('the reason cleared, 31 s on: Save asks again and waits for the answer',
      save(env) === 'held' && /• spec \(still being checked\)$/.test(said(env)) && stub.calls.length === asked + 1, said(env));
  } finally { Date.now = realNow; }
}
{
  // a duplicate: a reason does not stand for @UVUNIQUE
  const stub = makeTransportStub(); stub.next = { used: true, record: '7' };
  const id = text('pid', 'P-001'), why = text('pid_why', 'same person, re-enrolled');
  const env = boot([id, why], { jsmoName: 'EMStub.UV', rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard' }, J(['pid'], 'pid_why')] }, { stub });
  check('a duplicate still holds the save with a reason given', save(env) === 'held');
  check('... and the message does not offer a reason', !/or give a reason in/.test(said(env)), said(env));
  check('... nor does a note ask for one', shown(env, 'pid', 'j') === '');
  type(env, why, '');
  check('... not even with the reason field empty', save(env) === 'held' && shown(env, 'pid', 'j') === '', shown(env, 'pid', 'j'));
}
{
  // a locked write-once field stays locked; a rule on it that reads another field is released
  const wt = text('wt', '30'), ht = text('ht', '20'), why = text('wt_why', '');
  const env = boot([wt, ht, why], { rules: [
    { type: 'writeonce', fields: ['wt'] },
    { type: 'constraint', fields: ['wt'], assert: '[wt]>[ht]', blockSave: 'hard', message: 'Too light for the height', lockReads: { wt: ['ht'] } },
    J(['wt'], 'wt_why'),
  ] });
  check('a locked field stays read-only beside a reason', wt.readOnly === true);
  type(env, ht, '40');
  check('the rule on it that reads another field holds the save once that field breaks it, offering a reason', save(env) === 'held' && /or give a reason in/.test(said(env)), said(env));
  type(env, why, 'height entered in the wrong unit');
  check('... a reason releases that rule', save(env) === 'saved');
  check('... and the field is still read-only', wt.readOnly === true);
}

// ---- 8) a hidden reason row, a reason field not on this page -------------------------------
{
  const wt = text('wt', '30'), why = text('wt_why', 'a reason'); why._row = 'wt_why-tr';
  const env = boot([wt, why], { rules: [LIGHT('hard'), J(['wt'], 'wt_why')] });
  check('a visible reason releases the save', save(env) === 'saved');
  env.doc.getElementById('wt_why-tr').style.display = 'none';
  check('a reason in a row branching logic hides gives no reason', save(env) === 'held' && /or give a reason in/.test(said(env)), said(env));
}
{
  const wt = text('wt', '30');
  const env = boot([wt], { rules: [LIGHT('hard'), J(['wt'], 'wt_why')] });
  check('a reason field not on this page: the rule holds as it would alone', save(env) === 'held');
  check('... with no offer of a reason', !/or give a reason in/.test(said(env)), said(env));
  check('... and no note', region(env, 'wt', 'j') === null);
}

// ---- 9) a reason picked in a radio or dropdown field -------------------------------------
{
  const wt = text('wt', '30'); const r = radioField('wt_why', ['1', '2'], '');
  const env = boot([wt].concat(r.els), { rules: [LIGHT('hard'), J(['wt'], 'wt_why')] });
  check('a radio with nothing picked gives no reason', save(env) === 'held');
  flush();
  check('... and the note asks for one', shown(env, 'wt', 'j') !== '');
  r.mirror.value = '2'; r.buttons[1].checked = true;
  env.poll();
  check('a radio code picked with no event: the silent-change watch hides the note before any save', shown(env, 'wt', 'j') === '');
  check('... and the code gives a reason at the save', save(env) === 'saved');
}
{
  const wt = text('wt', '30'), why = select('wt_why', ['', 'scale', 'other'], '');
  const env = boot([wt, why], { rules: [LIGHT('hard'), J(['wt'], 'wt_why')] });
  check('a dropdown on its blank choice gives no reason', save(env) === 'held');
  type(env, why, 'scale');
  check('... a choice does', save(env) === 'saved' && shown(env, 'wt', 'j') === '');
}

// ---- 10) one reason field for two fields; the rule's own message ---------------------------
{
  const a = text('a', '1'), b = text('b', '1'), why = text('why', '');
  const env = boot([a, b, why], { rules: [
    { type: 'constraint', fields: ['a'], assert: '[a]>5', blockSave: 'hard' },
    { type: 'constraint', fields: ['b'], assert: '[b]>5', blockSave: 'hard' },
    J(['a', 'b'], 'why', { message: 'Say <b>why</b> both are low.' }),
  ] });
  check('two fields held, each offering the one reason field', save(env) === 'held' && (said(env).match(/or give a reason in/g) || []).length === 2, said(env));
  check('the rule\'s own message replaces the default, escaped', shown(env, 'a', 'j').indexOf('Say &lt;b&gt;why&lt;/b&gt; both are low.') >= 0
    && shown(env, 'b', 'j').indexOf('Say &lt;b&gt;why&lt;/b&gt;') >= 0, shown(env, 'a', 'j'));
  type(env, why, 'both measured after dialysis');
  check('one reason releases both', save(env) === 'saved');
}

// ---- 11) configuration errors -----------------------------------------------------------------
{
  const wt = text('wt', '30'), why = text('wt_why', 'a reason');
  const env = boot([wt, why], { rules: [LIGHT('hard'), J(['wt'], 'wt_why', { configError: '@UVJUSTIFY on "wt": "reason" names "[wt_why]", which is on the form "other".' })] });
  check('a configuration error is shown under the field', /on the form/.test(shown(env, 'wt', 'cfg')), shown(env, 'wt', 'cfg'));
  check('... and releases nothing', save(env) === 'held' && !/or give a reason in/.test(said(env)), said(env));
}
{
  const wt = text('wt', '30'), why = text('wt_why', 'a reason');
  const env = boot([wt, why], { rules: [LIGHT('hard'), J(['wt'], '[wt_why]')] });
  check('a reason that is not a field name is an error under the field', /needs the field that holds the reason/.test(shown(env, 'wt', 'cfg')), shown(env, 'wt', 'cfg'));
  check('... and releases nothing', save(env) === 'held');
}

// ---- 12) the factory is published and finds nothing of its own --------------------------------
{
  const env = boot([text('x', '')], { rules: [] });
  check('justifyInit is published', typeof env.NS.justifyInit === 'function');
  check('justifyLogic.reasonGiven is published', typeof env.NS.justifyLogic.reasonGiven === 'function');
}
{
  const wt = text('wt', '30'), why = text('wt_why', '');
  const env = boot([wt, why], { rules: [J(['wt'], 'wt_why')] });
  check('a justify rule alone holds nothing and asks nothing', save(env) === 'saved' && shown(env, 'wt', 'j') === '');
}

// ---- 13) the note asks only while the item would hold the save -------------------------------
/* The note and the save guard ask one question (QRID_itemHolds): a field the
   person cannot change or cannot see never holds the save, so no note asks
   for a reason under it. REDCap applies @READONLY in its own start-up, after
   the engine bound the field and before the engine's first tick. */
{
  const wt = text('wt', '30'), why = text('wt_why', '');
  const env = boot([wt, why], { rules: [LIGHT('hard'), J(['wt'], 'wt_why')] }, { ready() { wt.readOnly = true; } });
  check('read-only after bind: the save goes through', save(env) === 'saved');
  check('... the rule\'s own note stays', /Too light/.test(shown(env, 'wt', 'c')), shown(env, 'wt', 'c'));
  check('... and no note asks for a reason', shown(env, 'wt', 'j') === '', shown(env, 'wt', 'j'));
  type(env, why, '');
  check('... nor after the reason field changes', shown(env, 'wt', 'j') === '', shown(env, 'wt', 'j'));
}
{
  // the field's own row hidden by branching logic
  const wt = text('wt', '30'); wt._row = 'wt-tr';
  const why = text('wt_why', '');
  const env = boot([wt, why], { rules: [LIGHT('hard'), J(['wt'], 'wt_why')] });
  check('a shown field asks for its reason', shown(env, 'wt', 'j') === NOTE('wt_why'));
  env.doc.getElementById('wt-tr').style.display = 'none';
  env.win.doBranching(); flush();
  check('its row hidden by branching: the note goes', shown(env, 'wt', 'j') === '', shown(env, 'wt', 'j'));
  check('... and the save goes through', save(env) === 'saved');
  env.doc.getElementById('wt-tr').style.display = '';
  env.win.doBranching(); flush();
  check('shown again: the note is back', shown(env, 'wt', 'j') === NOTE('wt_why'), shown(env, 'wt', 'j'));
}
{
  // the reason's row hidden by branching logic, its text kept (no event on the reason field)
  const wt = text('wt', '30'), why = text('wt_why', 'a reason'); why._row = 'wt_why-tr';
  const env = boot([wt, why], { rules: [LIGHT('hard'), J(['wt'], 'wt_why')] });
  check('a shown reason: no note', shown(env, 'wt', 'j') === '');
  env.doc.getElementById('wt_why-tr').style.display = 'none';
  env.win.doBranching(); flush();
  check('the reason row hidden: the note asks again at once', shown(env, 'wt', 'j') === NOTE('wt_why'), shown(env, 'wt', 'j'));
  check('... in step with the save', save(env) === 'held' && /or give a reason in “wt_why”/.test(said(env)), said(env));
  env.doc.getElementById('wt_why-tr').style.display = '';
  env.win.doBranching(); flush();
  check('the reason row shown again: the note goes', shown(env, 'wt', 'j') === '', shown(env, 'wt', 'j'));
  check('... and the save goes through', save(env) === 'saved');
}

// ---- 14) a reason field embedded in a field's label ({wt_why}) -----------------------------
/* REDCap moves an embedded field's input into the row of the field whose
   label names it and keeps the embedded field's own row hidden. A plain
   hidden reason row gives no reason (8, 13); an embedded one is judged by
   the row it sits in. */
{
  const wt = text('wt', '30'); wt._row = 'wt-tr';
  const why = text('wt_why', ''); why._row = 'wt-tr';
  const own = makeEl('span'); own._row = 'wt_why-tr';
  const env = boot([wt, why, own], { rules: [LIGHT('hard'), J(['wt'], 'wt_why')] });
  env.doc.getElementById('wt_why-tr').style.display = 'none';
  env.win.doBranching(); flush();
  check('embedded in the field\'s own label, no reason yet: held, naming the reason field', save(env) === 'held' && /\(or give a reason in “wt_why”\)/.test(said(env)), said(env));
  type(env, why, 'scale recalibrated');
  check('... a reason typed in the box inside the label releases the save', save(env) === 'saved', said(env));
  check('... and the note goes', shown(env, 'wt', 'j') === '', shown(env, 'wt', 'j'));
}
{
  const wt = text('wt', '30');
  const host = text('host', ''); host._row = 'host-tr';
  const why = text('wt_why', 'scale recalibrated'); why._row = 'host-tr';
  const own = makeEl('span'); own._row = 'wt_why-tr';
  const env = boot([wt, host, why, own], { rules: [LIGHT('hard'), J(['wt'], 'wt_why')] });
  env.doc.getElementById('wt_why-tr').style.display = 'none';
  env.win.doBranching(); flush();
  check('embedded in another field\'s label, that row shown: the reason releases the save', save(env) === 'saved' && shown(env, 'wt', 'j') === '');
  env.doc.getElementById('host-tr').style.display = 'none';
  env.win.doBranching(); flush();
  check('... that row hidden by branching: no reason, held', save(env) === 'held' && /\(or give a reason in “wt_why”\)/.test(said(env)), said(env));
  check('... and the note asks again at once', shown(env, 'wt', 'j') === NOTE('wt_why'), shown(env, 'wt', 'j'));
}

// ---- 15) a checkbox field, paired by its options ------------------------------------------
/* REDCap draws each option of a checkbox field as a hidden input named
   __chk__<field>_RC_<code>, holding the code while ticked, and the box the
   person clicks, named __chkn__<field>, in a row of its own inside the
   field's row. No input carries the field's own name. */
function checkboxOption(field, code) {
  const row = 'opt-' + field + '-' + code;
  const hidden = makeEl('input'); hidden.name = '__chk__' + field + '_RC_' + code; hidden.type = 'hidden'; hidden._row = row; hidden._inRow = field + '-tr';
  const box = makeEl('input'); box.name = '__chkn__' + field; box.id = 'id-__chk__' + field + '_RC_' + code; box.type = 'checkbox'; box._row = row;
  return { hidden, box, els: [hidden, box], tick(on) { box.checked = on; hidden.value = on ? code : ''; box.fire('click'); box.fire('change'); flush(); } };
}
{
  const opts = ['1', '2', '9'].map((c) => checkboxOption('reach', c));
  const why = text('reach_why', '');
  const els = [].concat(...opts.map((o) => o.els), [why]);
  const env = boot(els, { rules: [
    { type: 'choices', fields: ['reach'], choicesHide: ['1', '9'], choicesAll: ['1', '2', '9'], blockSave: 'hard', message: 'Legacy channel' },
    J(['reach'], 'reach_why')] });
  const note = () => env.doc.getElementById('uvalidate-msg-reach-j');
  const noteText = () => (note() && note().style.display !== 'none' ? note().innerHTML : '');
  check('checkbox: the first option, hidden and unticked, is hidden', opts[0].hidden.parentNode.style.display === 'none');
  check('... nothing ticked that is hidden: the save goes through, no note', save(env) === 'saved' && noteText() === '', noteText());
  opts[2].tick(true);
  check('a hidden code ticked holds the save, offering the reason', save(env) === 'held' && /• reach \(or give a reason in “reach_why”\)/.test(said(env)), said(env));
  check('... and a note asks for the reason', noteText() === NOTE('reach_why'), noteText());
  check('... beside the options, not inside the hidden first option', note() && note().parentNode === env.doc.getElementById('reach-tr'));
  type(env, why, 'legacy channel used before the change');
  check('a reason given releases the save', save(env) === 'saved', said(env));
  check('... and the note goes', noteText() === '', noteText());
  type(env, why, '');
  opts[2].tick(false);
  check('the hidden code unticked: nothing holds and nothing asks', save(env) === 'saved' && noteText() === '', noteText());
}

// ---- 16) a reason label holding "$&" or "$$"; an autocomplete dropdown ---------------------
{
  const wt = text('wt', '30'), why = text('wt_why', '');
  why.setAttribute('aria-label', 'Cost in $$ or $& (USD)');
  const env = boot([wt, why], { rules: [LIGHT('hard'), J(['wt'], 'wt_why')] });
  check('a reason label holding "$$" and "$&" shows as written in the note', shown(env, 'wt', 'j') === NOTE('Cost in $$ or $&amp; (USD)'), shown(env, 'wt', 'j'));
  check('... and in the save message', save(env) === 'held' && said(env).indexOf('(or give a reason in “Cost in $$ or $& (USD)”)') >= 0, said(env));
}
{
  // REDCap's autocomplete puts a text box, in a wrapper, right after the hidden select
  const dose = select('dose', ['', '1', '2'], '1');
  const wrap = makeEl('span'); wrap._row = 'dose-tr';
  dose._row = 'dose-tr';
  const why = text('dose_why', '');
  const env = boot([dose, wrap, why], { rules: [{ type: 'constraint', fields: ['dose'], assert: "[dose]<>'1'", blockSave: 'hard' }, J(['dose'], 'dose_why')] });
  const ac = makeEl('input'); ac.id = 'rc-ac-input_dose'; wrap.appendChild(ac); env.allEls.push(ac);
  type(env, why, '');
  const row = env.doc.getElementById('dose-tr');
  const j = env.doc.getElementById('uvalidate-msg-dose-j');
  check('an autocomplete dropdown: the note sits after the text box\'s wrapper, not between it and the select',
    j && j.parentNode === row && row.children.indexOf(j) > row.children.indexOf(wrap) && row.children.indexOf(dose) + 1 !== row.children.indexOf(j)
    && shown(env, 'dose', 'j') === NOTE('dose_why'), row.children.map((c) => c.id || c.tagName));
}

{
  // LIVE-D3 (live run 2026-10-30): REDCap's label holds an icons cell with the Missing Data Code
  // "M" button, and once that is opened its menu; their text is not the label
  const wt = text('wt', '30'), why = text('wt_why', '');
  const env = boot([wt, why], { rules: [LIGHT('hard'), J(['wt'], 'wt_why')] });
  const lab = makeEl('label'); lab.id = 'label-wt_why';
  const icons = makeEl('td'); icons.className = 'rc-field-icons'; icons.textContent = 'Mark field as:[Clear value]Unknown (UNK)';
  lab.appendChild(icons);
  lab.textContent = 'Reason for change\n Mark field as:[Clear value]Unknown (UNK)';
  env.allEls.push(lab, icons);
  type(env, why, '');
  check('the reason label leaves out the Missing Data Code menu, in the note', shown(env, 'wt', 'j') === NOTE('Reason for change'), shown(env, 'wt', 'j'));
  check('... and in the save message', save(env) === 'held' && said(env).indexOf('(or give a reason in “Reason for change”)') >= 0, said(env));
}

// ---- 17) a survey save that does not finish the response (REQ-7) --------------------------
/* Previous Page and Save & Return Later save the page without finishing the
   response. A blank @UVREQUIRED field (its mode waits for a complete
   response) does not hold them, reason or not. A broken value still does,
   since it is saved either way, until its own reason is given: a reason
   field on the page releases only the field whose reason it holds. */
{
  const PARTIAL = { buttons: ['submit-btn-saveprevpage', 'submit-btn-savereturnlater'], types: ['required'] };
  const wt = text('wt', '30'), why = text('wt_why', ''), req = text('req', ''), rwhy = text('req_why', '');
  const env = boot([wt, why, req, rwhy], { context: 'survey', partialSave: PARTIAL, rules: [
    LIGHT('hard'), J(['wt'], 'wt_why'), { type: 'required', fields: ['req'], blockSave: 'hard' }, J(['req'], 'req_why')] });
  for (const nm of PARTIAL.buttons) {
    check(nm + ': a broken value with no reason holds the save; the blank required field does not',
      save(env, nm) === 'held' && /• wt \(or give a reason in “wt_why”\)/.test(said(env)) && !/• req/.test(said(env)), said(env));
  }
  check('Next / Submit holds both', save(env, 'submit-btn-saverecord') === 'held'
    && /• wt \(or give a reason/.test(said(env)) && /• req \(or give a reason in “req_why”\)/.test(said(env)), said(env));
  type(env, why, 'scale recalibrated');
  for (const nm of PARTIAL.buttons) check(nm + ': with the reason given the save goes through', save(env, nm) === 'saved', said(env));
  check('Next / Submit still holds the blank required field, and only it',
    save(env, 'submit-btn-saverecord') === 'held' && /• req/.test(said(env)) && !/• wt/.test(said(env)), said(env));
  type(env, rwhy, 'not asked at this visit');
  check('... until its own reason is given', save(env, 'submit-btn-saverecord') === 'saved', said(env));
}

// ---- 18) a field drawn after the engine started (J3A-8) ------------------------------------
/* A slow form, or a row REDCap draws late, puts the reason field or the
   field itself on the page after boot. The tag waits for it through the
   page's one late-render timer and observer (QRID_lateAttach) and pairs the
   two once both are there. */
{
  const wt = text('wt', '30');
  const env = boot([wt], { rules: [LIGHT('hard'), J(['wt'], 'wt_why')] });
  check('a reason field not on the page yet: the save is held, offering no reason', save(env) === 'held' && !/or give a reason/.test(said(env)), said(env));
  const why = text('wt_why', ''); const holder = makeEl('div');
  holder.appendChild(why); env.doc.body.appendChild(holder); env.allEls.push(holder, why);
  env.poll();
  check('the reason field drawn late: the note asks for it', shown(env, 'wt', 'j') === NOTE('wt_why'), shown(env, 'wt', 'j'));
  type(env, why, 'scale recalibrated');
  check('... and a reason typed there releases the save', save(env) === 'saved', said(env));
}
{
  const why = text('wt_why', '');
  const env = boot([why], { rules: [LIGHT('hard'), J(['wt'], 'wt_why')] });
  const wt = text('wt', '30'); const holder = makeEl('div');
  holder.appendChild(wt); env.doc.body.appendChild(holder); env.allEls.push(holder, wt);
  env.holders.wt = holder;
  env.poll();
  wt.fire('change'); flush();
  check('the field drawn late: its broken rule holds the save, offering the reason field',
    save(env) === 'held' && /• wt \(or give a reason in “wt_why”\)/.test(said(env)), said(env));
  type(env, why, 'scale recalibrated');
  check('... and the reason releases it', save(env) === 'saved', said(env));
}

console.log('justify_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
