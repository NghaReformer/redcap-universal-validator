/*
 * lookup_save_hold_dom_js.cjs - a save never stops for a lookup (@UVUNIQUE,
 * @UVEXISTS), and never asks the server.
 *
 * Reported from data entry on 2026-11-11: a form open longer than 30 seconds
 * refused its first Save with "Cannot save yet - please wait a moment and
 * save again", because Save asked again about an answer that old and the
 * guard refused the save while the question was out. Measured on the live
 * server the next day, one lookup takes a third of a second to a second
 * whatever the project's size, so a question asked at Save is time every
 * save pays.
 *
 * Asserts, on the save button's click, on REDCap's dataEntrySubmit (the Enter
 * key) and on a form submit:
 *   - Save goes by the answer the page has, however old: no request, no
 *     dialog, no wait; an old "already recorded" still blocks,
 *   - no pointer or focus event on a save button asks the server,
 *   - only a lookup already on its way (a value typed just before Save) keeps
 *     the save back, with no dialog, and the save goes on by itself as soon
 *     as the answer lands: REDCap's save runs once, with the arguments it was
 *     asked with,
 *   - an answer that blocks is what the person sees then, and nothing is
 *     saved,
 *   - with no answer in "lookupHoldMs" the save goes on anyway and the field
 *     says it could not be checked,
 *   - a second click while the save is kept back saves once, and a save made
 *     after the answer landed is not followed by the one kept back,
 *   - "lookupHoldMs": 0 never waits, and a value outside 0..10000 is ignored,
 *   - the line "Checking a value, then saving" shows only on a longer wait
 *     and is taken away when the save goes on.
 *
 * Run:  node tests/lookup_save_hold_dom_js.cjs
 */
'use strict';
const path = require('path');

let n = 0, fail = 0;
function check(label, cond, got) {
  n++;
  if (!cond) { fail++; console.error('FAIL: ' + label + (got !== undefined ? ' (got ' + JSON.stringify(got) + ')' : '')); }
}

/* The clock Save reads to decide whether a cached answer is fresh. */
let clockShift = 0;
const realDateNow = Date.now;
Date.now = () => realDateNow() + clockShift;
const STALE_MS = 31000;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

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
/* A transport that answers when the test says so: stub.answer(reply). */
function makeDeferredStub() {
  const stub = { calls: [], pending: [] };
  stub.obj = {
    ajax(action, payload) {
      stub.calls.push({ action, payload: JSON.parse(JSON.stringify(payload)) });
      return { then(res) { stub.pending.push(res); } };
    },
  };
  stub.answer = (r) => { stub.pending.shift()(JSON.parse(JSON.stringify(r))); };
  return stub;
}
function domEvent(target) {
  return { target, _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
}

const JSMO = 'EMStub.UV';
const HOLD_MS = 150;

/* One form with one looked-up field and REDCap's save button. env.saves lists
   every call that reached REDCap's own dataEntrySubmit. */
function boot(value, rule, extra) {
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  const stub = makeDeferredStub();
  const allEls = [];
  const body = makeEl('body');
  const field = makeEl('input'); field.name = rule.fields[0]; field.value = value;
  const holder = makeEl('div');
  holder.appendChild(field); body.appendChild(holder);
  allEls.push(field, holder);
  const doc = {
    body, readyState: 'complete', _handlers: {},
    createElement(t) { const e = makeEl(t); allEls.push(e); return e; },
    getElementById(id) { return allEls.find((e) => e.id === id) || null; },
    getElementsByName(name) { return allEls.filter((e) => e.name === name); },
    querySelector() { return null; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev)); },
  };
  const saves = [];
  const win = {
    _alerts: [], alert(m) { this._alerts.push(m); }, confirm() { return true; },
    EMStub: { UV: stub.obj },
    dataEntrySubmit(ob, more) { saves.push([ob, more]); return 'redcap-result'; },
    INSPIRE_VALIDATOR_CONFIG: Object.assign({ singleFields: [], pooledFields: [], jsmoName: JSMO, rules: [rule], lookupHoldMs: HOLD_MS }, extra || {}),
  };
  global.document = doc; global.window = win;
  require(enginePath);
  /* REDCap's save button: its click runs the capture listeners, then its
     inline onclick, dataEntrySubmit(this). */
  const btn = makeEl('button');
  btn.setAttribute('name', 'submit-btn-saverecord');
  btn.click = function () {
    const ev = domEvent(btn);
    doc.fire('click', ev);
    if (!ev._prevented) win.dataEntrySubmit(btn);
    return ev;
  };
  const msg = () => holder.children.find((k, i) => i > 0 && k.id && /^uvalidate-msg-/.test(k.id)) || holder.children[1];
  const note = () => doc.getElementById('uv-save-hold-note');
  return { doc, win, stub, field, btn, saves, msg, note };
}
const UNIQUE = (block) => ({ type: 'unique', fields: ['pid'], blockSave: block || 'hard' });
const EXISTS = (block) => ({ type: 'exists', fields: ['spec'], existsIn: 'specimen_id', blockSave: block || 'hard' });
const FREE = { used: false, record: null }, USED = { used: true, record: '7' };

/* Type a value and leave the field as a click on Save does: nothing has
   asked the server yet, and the save's own re-judging is what asks. */
function typeIn(env, value) { env.field.value = value; env.field.fire('input'); }

(async function main() {
  // ---- 1) the reported case: a form open longer than 30 s, then Save ----------
  {
    const env = boot('AB100', UNIQUE());
    env.stub.answer(FREE);
    clockShift += STALE_MS;
    const ev = env.btn.click();
    check('1 a form open 31 s saves at the first click', !ev._prevented && env.saves.length === 1 && env.saves[0][0] === env.btn);
    check('1 Save asks the server nothing', env.stub.calls.length === 1, env.stub.calls.length);
    check('1 no dialog', env.win._alerts.length === 0, env.win._alerts);
    clockShift += 40 * STALE_MS;
    env.btn.click();
    check('1 twenty minutes on, the same', env.saves.length === 2 && env.stub.calls.length === 1 && env.win._alerts.length === 0);
    await sleep(HOLD_MS + 60);
    check('1 and nothing is saved again later', env.saves.length === 2, env.saves.length);
  }
  {
    const env = boot('AB100', UNIQUE());
    env.stub.answer(USED);
    clockShift += STALE_MS;
    const ev = env.btn.click();
    check('1 an old "already recorded" still blocks, with no request',
      ev._prevented && env.saves.length === 0 && env.stub.calls.length === 1
      && env.win._alerts.length === 1 && /fix the flagged/.test(env.win._alerts[0]), env.win._alerts);
  }
  // ---- 2) nothing asks ahead of the click ------------------------------------------
  for (const type of ['pointerdown', 'mousedown', 'touchstart', 'focusin', 'mouseover']) {
    const env = boot('AB100', UNIQUE());
    env.stub.answer(FREE);
    clockShift += STALE_MS;
    env.doc.fire(type, domEvent(env.btn));
    check('2 ' + type + ' on a save button asks nothing', env.stub.calls.length === 1);
  }
  // ---- 3) a value typed just before Save: the one wait ------------------------------
  {
    const env = boot('', UNIQUE());
    typeIn(env, 'NEW1');
    const ev = env.btn.click();
    check('3 typed then Save: asked, and kept back with no dialog',
      ev._prevented && env.stub.calls.length === 1 && env.saves.length === 0 && env.win._alerts.length === 0);
    env.stub.answer(FREE);
    await sleep(60);
    check('3 the save goes on by itself once the answer lands', env.saves.length === 1 && env.saves[0][0] === env.btn, env.saves.length);
    check('3 still no dialog', env.win._alerts.length === 0, env.win._alerts);
    check('3 a wait this short shows no line', !env.note() || env.note().style.display !== 'block');
    await sleep(HOLD_MS + 60);
    check('3 and it saves once', env.saves.length === 1, env.saves.length);
  }
  {
    const env = boot('', UNIQUE());
    typeIn(env, 'NEW2');
    env.btn.click();
    env.stub.answer(USED);
    await sleep(60);
    check('3 an answer that blocks is shown, and nothing is saved',
      env.saves.length === 0 && env.win._alerts.length === 1 && /fix the flagged/.test(env.win._alerts[0]), env.win._alerts);
    check('3 the field says so', /already recorded/.test(env.msg().innerHTML));
    await sleep(HOLD_MS + 60);
    check('3 nothing is saved later either', env.saves.length === 0 && env.win._alerts.length === 1);
  }
  {
    const env = boot('', UNIQUE());
    typeIn(env, 'NEW3');
    env.btn.click();
    await sleep(60);
    check('3 still kept back before the wait is over', env.saves.length === 0);
    await sleep(HOLD_MS + 80);
    check('3 no answer in time: the save goes on', env.saves.length === 1 && env.win._alerts.length === 0, env.saves.length);
    check('3 the field says it could not be checked', /Could not check[^<]*\(no answer in time\)/.test(env.msg().innerHTML), env.msg().innerHTML);
    check('3 no line is left on the page', !env.note() || env.note().style.display !== 'block');
    env.btn.click();
    check('3 the next save goes through at once', env.saves.length === 2, env.saves.length);
  }
  // ---- 4) a second click while kept back; a save made after the answer ------------
  {
    const env = boot('', UNIQUE());
    typeIn(env, 'NEW4');
    env.btn.click();
    const again = env.btn.click();
    check('4 a second click meanwhile is kept back too, with no dialog', again._prevented && env.win._alerts.length === 0 && env.stub.calls.length === 1);
    env.stub.answer(FREE);
    await sleep(HOLD_MS + 60);
    check('4 two clicks save once', env.saves.length === 1, env.saves.length);
  }
  {
    const env = boot('', UNIQUE());
    typeIn(env, 'NEW5');
    env.btn.click();
    env.stub.answer(FREE);
    const ev = env.btn.click();
    check('4 a click after the answer landed saves at once', !ev._prevented && env.saves.length === 1);
    await sleep(HOLD_MS + 60);
    check('4 and the save kept back earlier does not follow it', env.saves.length === 1, env.saves.length);
  }
  // ---- 5) the Enter key: REDCap calls dataEntrySubmit itself ----------------------
  {
    const env = boot('', UNIQUE());
    typeIn(env, 'NEW6');
    const r = env.win.dataEntrySubmit(env.btn, 'second-argument');
    check('5 Enter is kept back', r === false && env.saves.length === 0 && env.win._alerts.length === 0);
    env.stub.answer(FREE);
    await sleep(60);
    check('5 and saved once the answer lands, with the same arguments',
      env.saves.length === 1 && env.saves[0][0] === env.btn && env.saves[0][1] === 'second-argument', env.saves);
  }
  {
    const env = boot('AB100', UNIQUE());
    env.stub.answer(FREE);
    clockShift += STALE_MS;
    const r = env.win.dataEntrySubmit(env.btn);
    check('5 Enter on a form open 31 s saves at once', r === 'redcap-result' && env.saves.length === 1 && env.stub.calls.length === 1);
  }
  // ---- 6) a form submit -----------------------------------------------------------
  {
    const env = boot('', EXISTS());
    typeIn(env, 'SP-1');
    const asked = [];
    const form = { requestSubmit(by) { asked.push(by); } };
    const ev = domEvent(form); ev.type = 'submit'; ev.submitter = env.btn;
    env.doc.fire('submit', ev);
    check('6 @UVEXISTS: a submit with a lookup on its way is kept back', ev._prevented && env.stub.calls.length === 1 && env.win._alerts.length === 0);
    env.stub.answer({ state: 'found', record: '3' });
    await sleep(60);
    check('6 the form is asked to submit again, with its button', asked.length === 1 && asked[0] === env.btn, asked.length);
  }
  {
    const env = boot('SP-1', EXISTS());
    env.stub.answer({ state: 'not-found' });
    clockShift += STALE_MS;
    const ev = domEvent({}); ev.type = 'submit';
    env.doc.fire('submit', ev);
    check('6 @UVEXISTS: an old "not found" blocks at once, and Save asks nothing',
      ev._prevented && env.stub.calls.length === 1 && env.win._alerts.length === 1 && /fix the flagged/.test(env.win._alerts[0]));
  }
  // ---- 7) lookupHoldMs ------------------------------------------------------------
  {
    const env = boot('', UNIQUE(), { lookupHoldMs: 0 });
    typeIn(env, 'NEW7');
    const ev = env.btn.click();
    check('7 "lookupHoldMs": 0 never waits', !ev._prevented && env.saves.length === 1 && env.win._alerts.length === 0);
    check('7 the field says it could not be checked', /Could not check/.test(env.msg().innerHTML), env.msg().innerHTML);
  }
  for (const bad of [-1, 10001, '150', null]) {
    const env = boot('', UNIQUE(), { lookupHoldMs: bad });
    typeIn(env, 'NEW8');
    const ev = env.btn.click();
    await sleep(HOLD_MS + 100);
    check('7 "lookupHoldMs": ' + JSON.stringify(bad) + ' is ignored, the save waits the usual time', ev._prevented && env.saves.length === 0);
    env.stub.answer(FREE);
    await sleep(60);
    check('7 ... and goes on with the answer', env.saves.length === 1);
  }
  // ---- 8) the line shown on a longer wait -----------------------------------------
  {
    const env = boot('', UNIQUE(), { lookupHoldMs: 700 });
    typeIn(env, 'NEW9');
    env.btn.click();
    await sleep(450);
    const note = env.note();
    check('8 a wait past 300 ms says the value is being checked',
      !!note && note.style.display === 'block' && /Checking a value, then saving/.test(note.innerHTML)
      && note.getAttribute('role') === 'status' && note.getAttribute('aria-live') === 'polite');
    env.stub.answer(FREE);
    await sleep(60);
    check('8 the line is taken away when the save goes on', env.saves.length === 1 && env.note().style.display === 'none');
  }

  console.log(`lookup_save_hold_dom_js: ${n} checks, ${fail} failure(s)`);
  process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error(e); process.exit(1); });
