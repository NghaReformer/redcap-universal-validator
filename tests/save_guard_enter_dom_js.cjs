/*
 * save_guard_enter_dom_js.cjs — the save guard in front of REDCap's
 * dataEntrySubmit (LIVE-1, adversarial review 2026-10-21).
 *
 * On a REDCap 13-17 data-entry form, Enter in a text field runs this keydown
 * handler (read live from REDCap 17.0.6):
 *
 *     $(this).trigger('blur');
 *     if ($('#field_validation_error_state').val() == '0') {
 *         dataEntrySubmit($('form#form :input[name="submit-btn-saverecord"]'));
 *     }
 *
 * dataEntrySubmit ends in document.form.submit(), which fires no submit event,
 * and nothing clicks a save button, so before this fix a hard block saved. The
 * handler looks dataEntrySubmit up as a global when it runs; in a browser that
 * is window.dataEntrySubmit, which this stub's `win` stands for, so the tests
 * call win.dataEntrySubmit(...) the way the handler does.
 *
 * Asserts:
 *   - the engine wraps window.dataEntrySubmit once, and only when it exists,
 *   - Enter on a hard-blocked field never reaches REDCap's dataEntrySubmit and
 *     shows the block alert; on a valid field it passes the arguments and the
 *     return value through,
 *   - the blur REDCap fires first judges a value typed a moment ago,
 *   - a "confirm" rule asks once; Cancel stops the save, OK lets it go and is
 *     not asked again within the 2-second pass,
 *   - a save-button click the guard let through is not judged twice when the
 *     button's onclick calls dataEntrySubmit, and that pass ends with the task,
 *   - a lookup still in flight holds an Enter save,
 *   - on a page still loading, a dataEntrySubmit defined after the engine is
 *     wrapped on DOMContentLoaded or load; a loaded page adds no listener,
 *   - a page without dataEntrySubmit or window.addEventListener boots as before,
 *   - a @UVWRITEONCE lock arms the wrapper on its own; Enter puts back a value
 *     changed with no event and saves; a rule on the locked field that reads
 *     another field holds Enter only once that field changed since the page
 *     opened (QRID_guardSpeaks), as it holds a click.
 *
 * Run:  node tests/save_guard_enter_dom_js.cjs
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
    setAttribute(k, v) { this._attrs[k] = String(v); if (k === 'id') this.id = String(v); if (k === 'name') this.name = String(v); },
    getAttribute(k) {
      if (k in this._attrs) return this._attrs[k];
      if (k === 'id') return this.id || null;
      if (k === 'name') return this.name || null;
      return null;
    },
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

/* REDCap's dataEntrySubmit, recording each call. */
function makeSubmit(calls) {
  return function dataEntrySubmit(ob) { calls.push({ ob, self: this, argc: arguments.length }); return 'redcap-result'; };
}

/* opts.submit: put a dataEntrySubmit on window before the engine loads.
   opts.winListeners: give window an addEventListener. opts.confirm: the
   answer window.confirm gives. opts.readyState: the document's (default
   "complete"). */
function boot(els, config, opts) {
  opts = opts || {};
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
    body, readyState: opts.readyState || 'complete', _handlers: {},
    createElement(t) { const e = makeEl(t); allEls.push(e); return e; },
    getElementById(id) { return allEls.find((e) => e.id === id) || null; },
    getElementsByName(name) { return allEls.filter((e) => e.name === name); },
    querySelector() { return null; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev)); },
  };
  const calls = [];
  const win = {
    _alerts: [], _confirms: [],
    alert(m) { this._alerts.push(m); },
    confirm(m) { this._confirms.push(m); return opts.confirm !== false; },
    INSPIRE_VALIDATOR_CONFIG: config,
  };
  if (opts.submit !== false) win.dataEntrySubmit = makeSubmit(calls);
  if (opts.winListeners) {
    win._handlers = {};
    win.addEventListener = function (type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); };
    win.fire = function (type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev || {})); };
  }
  global.document = doc; global.window = win;
  let bootError = null;
  try { require(enginePath); } catch (e) { bootError = e; }
  return { doc, win, holders, calls, bootError, NS: win.INSPIREUniversalValidator, makeSubmit };
}

/* What REDCap's keydown handler does on Enter in a text field. */
function pressEnter(env, input, btn) {
  input.fire('blur');
  return env.win.dataEntrySubmit(btn);
}
function saveButton(name) { const b = makeEl('button'); b.setAttribute('name', name); return b; }
function clickEv(target) {
  return { target, _prevented: false, _stopped: false,
           preventDefault() { this._prevented = true; }, stopImmediatePropagation() { this._stopped = true; } };
}
const CHECK_RULE = (mode) => ({ singleFields: [], pooledFields: [],
  rules: [{ type: 'single', fields: ['study_id'], algorithm: 'iso7064_mod37_36', blockSave: mode }] });
function validId(NS) {
  const scheme = NS.engine.makeScheme({ algorithm: 'iso7064_mod37_36', source: 'normalized_id',
    placement: 'append', enabled: true,
    normalize_rules: { strip_delimiters: '', uppercase: true, unify_unicode_dashes: true, keep_only: null } });
  return NS.engine.appendCheck('0ABC0000', scheme);
}
function idInput() {
  const el = makeEl('input'); el.name = 'study_id'; el.type = 'text';
  el.setAttribute('aria-label', 'Participant Study ID');
  return el;
}

const pending = [];

// ---- 1) hard block: Enter is held, a valid value passes through ------------
{
  const input = idInput();
  const env = boot([input], CHECK_RULE('hard'));
  const btn = saveButton('submit-btn-saverecord');
  check('1 engine boots', env.bootError === null);
  check('1 dataEntrySubmit is wrapped', typeof env.win.dataEntrySubmit === 'function'
    && env.win.dataEntrySubmit.__qridGuarded === true);

  input.value = '0ABC00001X';                // wrong check character
  input.fire('change');
  const r1 = pressEnter(env, input, btn);
  check('1 Enter on a hard-blocked field never reaches dataEntrySubmit', env.calls.length === 0);
  check('1 the held call returns false', r1 === false);
  check('1 the block alert names the field by its label',
    env.win._alerts.length === 1 && /Cannot save yet/.test(env.win._alerts[0])
    && /Participant Study ID/.test(env.win._alerts[0]));
  check('1 the blocked field gets focus', input._focused === true);
  check('1 no confirm is asked for a hard block', env.win._confirms.length === 0);

  input.value = validId(env.NS);
  input.fire('change');
  const r2 = pressEnter(env, input, btn);
  check('1 Enter on a valid field reaches dataEntrySubmit once', env.calls.length === 1);
  check('1 the argument is passed through', env.calls[0] && env.calls[0].ob === btn && env.calls[0].argc === 1);
  check('1 the return value is passed through', r2 === 'redcap-result');
  check('1 no new alert for a valid field', env.win._alerts.length === 1);

  // a string action, as REDCap's own menu items pass it
  env.win.dataEntrySubmit('submit-btn-savenextform');
  check('1 a string action passes through too', env.calls.length === 2 && env.calls[1].ob === 'submit-btn-savenextform');
}

// ---- 2) the blur REDCap fires first judges a value typed a moment ago -------
{
  const input = idInput();
  const env = boot([input], CHECK_RULE('hard'));
  input.value = '0ABC00001X';
  input.fire('input');                       // keystroke: debounced, not judged yet
  const r = pressEnter(env, input, saveButton('submit-btn-saverecord'));
  check('2 Enter right after typing is held (blur judged the value)', env.calls.length === 0 && r === false);
  check('2 the block alert was shown', env.win._alerts.length === 1);
}

// ---- 3) confirm: Cancel stops, OK saves and is not asked twice -------------
{
  const input = idInput();
  const env = boot([input], CHECK_RULE('confirm'), { confirm: false });
  input.value = '0ABC00001X';
  input.fire('change');
  const r = pressEnter(env, input, saveButton('submit-btn-saverecord'));
  check('3 confirm rule asks once on Enter', env.win._confirms.length === 1
    && /Validation FAILED for:/.test(env.win._confirms[0]) && /Save anyway\?/.test(env.win._confirms[0]));
  check('3 Cancel keeps the save from REDCap', env.calls.length === 0 && r === false);
}
{
  const input = idInput();
  const env = boot([input], CHECK_RULE('confirm'), { confirm: true });
  input.value = '0ABC00001X';
  input.fire('change');
  const btn = saveButton('submit-btn-saverecord');
  const r = pressEnter(env, input, btn);
  check('3 OK lets the save go on', env.calls.length === 1 && r === 'redcap-result');
  pressEnter(env, input, btn);
  check('3 a second Enter within the pass is not asked again', env.win._confirms.length === 1 && env.calls.length === 2);
}

// ---- 4) a click the guard let through is judged once -----------------------
{
  const input = idInput();
  const env = boot([input], CHECK_RULE('hard'));
  let rechecks = 0;
  env.NS.guard.items.push({ __qridRecheck() { rechecks++; }, __qridInvalid: false, __qridBlockMode: 'hard' });
  input.value = validId(env.NS);
  input.fire('change');
  const btn = saveButton('submit-btn-savecontinue');
  const ev = clickEv(btn);
  env.doc.fire('click', ev);
  check('4 a valid click is not held', ev._prevented === false && ev._stopped === false);
  check('4 the click guard judged once', rechecks === 1);
  env.win.dataEntrySubmit(btn);            // the button's onclick, same task
  check('4 the onclick call reaches REDCap', env.calls.length === 1);
  check('4 the onclick call is not judged a second time', rechecks === 1);
  pending.push(new Promise((resolve) => setTimeout(() => {
    // the pass ends with the task: a later Enter on a now-wrong value is held
    input.value = '0ABC00001X';
    input.fire('change');
    const r = pressEnter(env, input, btn);
    check('4 the click pass does not outlive its task', r === false && env.calls.length === 1);
    check('4 that Enter was judged', rechecks === 2);
    resolve();
  }, 5)));
}
{
  const input = idInput();
  const env = boot([input], CHECK_RULE('hard'));
  input.value = '0ABC00001X';
  input.fire('change');
  const btn = saveButton('submit-btn-saverecord');
  const ev = clickEv(btn);
  env.doc.fire('click', ev);
  check('4 a hard-blocked click is still held by the click guard', ev._prevented === true && ev._stopped === true);
  check('4 and leaves no pass behind for dataEntrySubmit', env.win.dataEntrySubmit(btn) === false && env.calls.length === 0);
}

// ---- 5) a lookup still in flight holds an Enter save ------------------------
{
  const input = idInput();
  const env = boot([input], CHECK_RULE('hard'));
  input.value = validId(env.NS);
  input.fire('change');
  const lookup = { __qridPending: true, __qridInvalid: false, __qridBlockMode: 'hard', __qridFieldLabel: 'Specimen ID' };
  env.NS.guard.items.push(lookup);
  const r = pressEnter(env, input, saveButton('submit-btn-saverecord'));
  check('5 Enter waits for a lookup in flight', r === false && env.calls.length === 0);
  check('5 the alert asks to wait', env.win._alerts.length === 1 && /please wait a moment/.test(env.win._alerts[0])
    && /Specimen ID \(still being checked\)/.test(env.win._alerts[0]));
  lookup.__qridPending = false;
  check('5 once answered, Enter saves', pressEnter(env, input, saveButton('submit-btn-saverecord')) === 'redcap-result');
}

// ---- 6) a dataEntrySubmit defined after the engine -------------------------
{
  const input = idInput();
  const env = boot([input], CHECK_RULE('hard'), { submit: false, winListeners: true, readyState: 'loading' });
  check('6 boots without dataEntrySubmit', env.bootError === null && env.win.dataEntrySubmit === undefined);
  env.win.dataEntrySubmit = env.makeSubmit(env.calls);
  env.doc.fire('DOMContentLoaded', {});
  check('6 wrapped on DOMContentLoaded', env.win.dataEntrySubmit.__qridGuarded === true);
  input.value = '0ABC00001X';
  input.fire('change');
  check('6 the late function is guarded',
    pressEnter(env, input, saveButton('submit-btn-saverecord')) === false && env.calls.length === 0);
  const wrapped = env.win.dataEntrySubmit;
  env.win.fire('load');
  check('6 load does not wrap twice', env.win.dataEntrySubmit === wrapped);
}
{
  const input = idInput();
  const env = boot([input], CHECK_RULE('hard'), { submit: false, winListeners: true, readyState: 'interactive' });
  env.win.dataEntrySubmit = env.makeSubmit(env.calls);
  env.win.fire('load');
  check('6 wrapped on load', env.win.dataEntrySubmit.__qridGuarded === true);
}
{
  const input = idInput();
  const env = boot([input], CHECK_RULE('hard'), { submit: false, winListeners: true });
  check('6 a loaded page without dataEntrySubmit adds no load listener', !(env.win._handlers.load || []).length);
}

// ---- 7) pages without dataEntrySubmit, or with no blocking rule ------------
{
  const input = idInput();
  const env = boot([input], CHECK_RULE('hard'), { submit: false });
  check('7 no dataEntrySubmit and no window.addEventListener: boots', env.bootError === null);
  check('7 nothing is defined on window', env.win.dataEntrySubmit === undefined);
  input.value = '0ABC00001X';
  input.fire('change');
  const ev = { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
  env.doc.fire('submit', ev);
  check('7 the submit guard still holds', ev._prevented === true);
}
{
  const input = idInput();
  const env = boot([input], CHECK_RULE('off'));
  check('7 a warn-only rule leaves dataEntrySubmit alone', env.win.dataEntrySubmit.__qridGuarded !== true);
}

// ---- 8) @UVWRITEONCE: the lock and a rule that reads another field ---------
function textField(name, value) { const el = makeEl('input'); el.name = name; el.type = 'text'; el.value = value; return el; }
const WO = (fields) => ({ type: 'writeonce', fields });
const HEAVIER = { type: 'constraint', fields: ['weight'], assert: '[weight]<[height]', blockSave: 'hard',
  message: 'Heavier than tall', lockReads: { weight: ['height'] } };
{
  const locked = textField('consent_date', '2026-03-01');
  const env = boot([locked], { rules: [WO(['consent_date'])] });
  check('8 a write-once lock alone wraps dataEntrySubmit', env.bootError === null
    && env.win.dataEntrySubmit.__qridGuarded === true);
  check('8 the locked box is read-only', locked.readOnly === true);
  locked.value = '2026-04-01';                 // a script, no event
  const r = pressEnter(env, locked, saveButton('submit-btn-saverecord'));
  check('8 Enter puts the saved value back and saves', r === 'redcap-result' && env.calls.length === 1
    && locked.value === '2026-03-01');
  check('8 no alert for a value put back', env.win._alerts.length === 0);
}
// The values the other fields opened with are taken in a timer, after REDCap's start-up (review W4-2),
// so each page is booted, left to start, and checked before the next one is booted, once the timers
// of the checks above have run (an alert goes to the window of the page booted last).
const earlier = pending.slice();
pending.push((async () => {
await Promise.all(earlier);
for (const order of ['lock first', 'lock last']) {
  const weight = textField('weight', '70'), height = textField('height', '50');
  const env = boot([weight, height], { rules: order === 'lock first' ? [WO(['weight']), HEAVIER] : [HEAVIER, WO(['weight'])] });
  const btn = saveButton('submit-btn-saverecord');
  await new Promise((resolve) => setTimeout(resolve, 5));
  global.document = env.doc; global.window = env.win;
  {
    check('8 ' + order + ': a violation saved before the page opened does not hold Enter',
      pressEnter(env, height, btn) === 'redcap-result' && env.calls.length === 1);
    height.value = '40';
    height.fire('change');
    const r = pressEnter(env, height, btn);
    check('8 ' + order + ': Enter is held once the other field changed and still breaks the rule',
      r === false && env.calls.length === 1);
    check('8 ' + order + ': the alert names the locked field',
      env.win._alerts.length === 1 && /weight/.test(env.win._alerts[0]));
    height.value = '50';
    height.fire('change');
    check('8 ' + order + ': the other field put back as it was lets Enter through again',
      pressEnter(env, height, btn) === 'redcap-result' && env.calls.length === 2);
  }
}
})());

Promise.all(pending).then(() => {
  console.log((fail ? 'FAIL' : 'OK') + ': save guard in front of dataEntrySubmit (' + (n - fail) + '/' + n + ' checks)');
  process.exit(fail ? 1 : 0);
});
