/*
 * writeonce_live_twin_js.cjs - replay the clicks of
 * docs/testbed/WRITEONCE_LIVE_ACCEPTANCE.md through js/engine.js, booted with
 * the page configs the real module built (tests/writeonce_live_pages.json,
 * which tests/writeonce_live_twin_php.php keeps in step with the module), on
 * a page built from the fixture's field types the way REDCap renders them: a
 * date box with its picker, radio buttons with their hidden mirror and
 * "reset" link, a dropdown with its autocomplete box, a notes box and a
 * slider. It checks which controls are locked, each lock note word for word,
 * that a change is undone, each save outcome, and the configuration errors.
 * B7, and a second run of B6, load the engine above the form while the page
 * is still loading, as REDCap prints it, with jQuery's ready callbacks run in a
 * timer after the DOMContentLoaded listeners. Section I reads what REDCap
 * logs, so it has no clicks to replay here.
 *
 * RUN:  php tests/writeonce_live_twin_php.php && node tests/writeonce_live_twin_js.cjs
 */
'use strict';
const path = require('path');
const PAGES = require('./writeonce_live_pages.json');

let n = 0, fail = 0;
function check(label, ok, got) { n++; if (!ok) { fail++; console.error('FAIL: ' + label + (got !== undefined ? ' (got ' + JSON.stringify(got) + ')' : '')); } }

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), id: '', name: '', value: '', innerHTML: '', type: '', checked: false, className: '',
    style: { set cssText(v) { this._css = v; const m = /display:\s*([a-z]+)/.exec(v); this.display = m ? m[1] : ''; },
             get cssText() { return this._css || ''; } },
    _attrs: {}, children: [], parentNode: null, readOnly: false, disabled: false, _handlers: {},
    setAttribute(k, v) { this._attrs[k] = String(v); if (k === 'id') this.id = String(v); },
    getAttribute(k) { return (k in this._attrs) ? this._attrs[k] : (k === 'id' ? (this.id || null) : null); },
    removeAttribute(k) { delete this._attrs[k]; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    dispatchEvent(ev) { this.fire(ev.type, ev); return true; },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn.call(this, ev || {})); },
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
    insertBefore(node, ref) {
      node.parentNode = this; const i = ref ? this.children.indexOf(ref) : -1;
      if (i >= 0) this.children.splice(i, 0, node); else this.children.push(node); return node;
    },
    getElementsByTagName(t) {
      const out = [];
      const walk = (e) => { for (const c of e.children) { if (c.tagName === t.toUpperCase()) out.push(c); walk(c); } };
      walk(this); return out;
    },
    closest() { return null; }, focus() {},
    get nextSibling() { const i = this.parentNode ? this.parentNode.children.indexOf(this) : -1; return i >= 0 ? (this.parentNode.children[i + 1] || null) : null; },
    get firstChild() { return this.children[0] || null; },
  };
}
global.Event = function (type) { this.type = type; };

/* REDCap's codes of a choices list: "1, Arm A | 2, Arm B"; a yes/no field is 1 and 0. */
function codes(t) {
  if (t.type === 'yesno') return ['1', '0'];
  return String(t.choices || '').split('|').map((c) => c.split(',')[0].trim()).filter((c) => c !== '');
}
/* One page: each fixture field as REDCap renders it, holding the saved values; the server's config as the page has it.
   jQuery runs ready callbacks as jQuery 3 does (in a timer, from its own DOMContentLoaded listener), and timers wait
   in a queue that boot runs once the page has started. opts.late loads the engine on a page still loading, before
   the form, then adds the form and fires DOMContentLoaded, as REDCap prints the engine above the form. */
function boot(cfg, values, opts) {
  opts = opts || {};
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  const all = [], holders = {}, ctl = {}, later = [];
  const body = makeEl('body');
  const add = (holder, el) => { holder.appendChild(el); (opts.late ? later : all).push(el); return el; };
  for (const f of Object.keys(PAGES.types)) {
    const t = PAGES.types[f], v = (values && values[f]) || '';
    const row = makeEl('tr'); row.id = f + '-tr'; (opts.late ? later : all).push(row);
    if (!opts.late) body.appendChild(row);
    holders[f] = row;
    const lab = makeEl('td'); lab.id = 'label-' + f; lab.textContent = PAGES.labels[f]; all.push(lab);
    const c = { type: t.type };
    if (t.type === 'radio' || t.type === 'yesno') {
      c.el = add(row, makeEl('input')); c.el.name = f; c.el.type = 'text'; c.el.value = v;
      c.buttons = codes(t).map((code) => { const b = add(row, makeEl('input')); b.name = f + '___radio'; b.type = 'radio'; b.value = code; b.checked = code === v; return b; });
      c.reset = add(row, makeEl('a')); c.reset.setAttribute('onclick', "radioResetVal('" + f + "','form');return false;"); c.reset.style.display = 'inline';
    } else if (t.type === 'dropdown') {
      c.el = add(row, makeEl('select')); c.el.name = f;
      c.el.options = [''].concat(codes(t)).map((code) => { const o = makeEl('option'); o.value = code; o.text = code; return o; });
      c.el.value = v;
      c.ac = add(row, makeEl('input')); c.ac.id = 'rc-ac-input_' + f; c.ac.value = v;
    } else {
      c.el = add(row, makeEl(t.type === 'notes' ? 'textarea' : 'input')); c.el.name = f; c.el.type = 'text'; c.el.value = v;
      if (/^date/.test(t.validation)) c.el.className = 'x hasDatepicker';
      if (t.type === 'slider') { c.slider = add(row, makeEl('div')); c.slider.id = 'slider-' + f; }
    }
    ctl[f] = c;
  }
  const doc = {
    body, readyState: opts.late ? 'loading' : 'complete', _handlers: {}, activeElement: null,
    createElement(t) { const e = makeEl(t); all.push(e); return e; },
    getElementById(id) { return all.find((e) => e.id === id) || null; },
    getElementsByName(nm) { return all.filter((e) => e.name === nm); },
    querySelector() { return null; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev)); },
  };
  const calls = [];
  const submits = [];
  const win = { _alerts: [], _confirms: [], confirmAnswer: false,
    alert(m) { this._alerts.push(m); }, confirm(m) { this._confirms.push(m); return this.confirmAnswer; },
    /* REDCap's own, which the Enter key in a text field calls */
    dataEntrySubmit(ob) { submits.push(ob); return 'redcap-save'; },
    INSPIRE_VALIDATOR_CONFIG: JSON.parse(JSON.stringify(cfg)) };
  const timers = [];
  global.setTimeout = (fn) => { const t = { fn, cleared: false }; timers.push(t); return t; };
  global.clearTimeout = (t) => { if (t && typeof t === 'object') t.cleared = true; };
  const flush = () => { for (let i = 0; timers.length && i < 10000; i++) { const t = timers.shift(); if (!t.cleared) t.fn(); } };
  const ready = { done: doc.readyState !== 'loading', list: [] };
  if (!ready.done) doc.addEventListener('DOMContentLoaded', () => { ready.done = true; ready.list.splice(0).forEach((fn) => setTimeout(fn, 0)); });
  const jq = function (sel) {
    if (typeof sel === 'function') { if (ready.done) setTimeout(sel, 0); else ready.list.push(sel); return undefined; }
    const el = typeof sel === 'string' ? (sel[0] === '#' ? doc.getElementById(sel.slice(1)) : null) : sel;
    const w = { length: el ? 1 : 0 };
    w.on = function (events, handler) { for (const ev of events.split(' ')) if (el) el.addEventListener(ev.split('.')[0], handler); return w; };
    w.hasClass = (c) => !!el && (' ' + el.className + ' ').indexOf(' ' + c + ' ') >= 0;
    w.slider = (a) => { calls.push(['slider', el && el.id, a]); return w; };
    // as jQuery UI 1.13: "disable" disables the input itself, which the form then leaves out
    w.datepicker = (a, k, v) => {
      calls.push(['datepicker', el && el.name, a]);
      if (el && a === 'disable') el.disabled = true;
      if (el && a === 'option' && k === 'beforeShow') el.__beforeShow = v;
      return w;
    };
    w.nextAll = () => ({ hide() { calls.push(['hide-trigger', el && el.name]); } });
    return w;
  };
  jq.fn = { on: true };
  win.jQuery = jq;
  global.document = doc; global.window = win;
  const intervals = [];
  global.setInterval = (fn) => { intervals.push(fn); return { unref() {} }; };
  global.clearInterval = () => {};
  require(enginePath);
  if (opts.late) {
    flush();   // a timer started while the page is still loading may run before the form exists
    for (const el of later) { all.push(el); if (el.tagName === 'TR') body.appendChild(el); }
    doc.readyState = 'interactive';
    doc.fire('DOMContentLoaded', {});
  }
  flush();
  return { doc, win, holders, ctl, calls, submits, flush, poll() { intervals.forEach((fn) => fn()); } };
}
function region(env, f, suffix) {
  for (const k of env.holders[f].children) if (k.id && new RegExp('-' + suffix + '$').test(k.id)) return k;
  return null;
}
/* The lock note under a field, as the page shows it ('' when none). */
function note(env, f) {
  const m = region(env, f, 'o');
  return m && m.style.display !== 'none' ? m.innerHTML.replace(/&#128274; /, '🔒 ').replace(/<[^>]*>/g, '').replace(/&amp;/g, '&').replace(/&#39;/g, "'") : '';
}
function cfgErr(env, f) {
  const m = region(env, f, 'cfg');
  return m && m.style.display !== 'none' ? m.innerHTML.replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&#39;/g, "'") : '';
}
function save(env) {
  const a = env.win._alerts.length, c = env.win._confirms.length;
  const ev = { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
  env.doc.fire('submit', ev);
  if (env.win._alerts.length > a) return 'held: ' + env.win._alerts[env.win._alerts.length - 1].replace(/\s+/g, ' ');
  if (env.win._confirms.length > c) return 'asked';
  return ev._prevented ? 'prevented' : 'saved';
}
/* Enter in a text field, as REDCap's keydown handler does it: blur, then dataEntrySubmit. */
function pressEnter(env, f) {
  env.ctl[f].el.fire('blur');
  return env.win.dataEntrySubmit('submit-btn-saverecord');
}
/* What a person does to a control: type and leave, pick a radio button, pick a dropdown option. */
function type(env, f, v) { const el = env.ctl[f].el; el.value = v; el.fire('input'); el.fire('change'); el.fire('blur'); }
function pick(env, f, code) {
  const c = env.ctl[f];
  if (c.buttons) {
    const b = c.buttons.find((x) => x.value === code);
    if (b.disabled) return;            /* a disabled button takes no click */
    c.buttons.forEach((x) => { x.checked = x === b; });
    c.el.value = code; c.el.fire('change');
  } else { c.el.value = code; c.el.fire('change'); }
}
function isLocked(env, f) {
  const c = env.ctl[f];
  if (c.buttons) return c.buttons.every((b) => b.disabled) && c.reset.style.display === 'none';
  if (c.type === 'dropdown') return c.el.getAttribute('aria-readonly') === 'true' && c.el.style.pointerEvents === 'none' && c.ac.readOnly === true && c.el.disabled === false;
  return c.el.readOnly === true;
}
const LOCK = '🔒 This value was saved once and is locked. It cannot be changed here.';
const UNDONE = ' A change was undone.';
const TAGGED = ['uw_consent', 'uw_arm', 'uw_site', 'uw_yn', 'uw_notes', 'uw_pain', 'uw_weight'];

// ---- Section A and F: configuration errors are shown under each field, never a block ----------
{
  const env = boot(PAGES.a1, PAGES.a1_values);
  for (const f of ['uw_arm', 'uw_site', 'uw_yn']) {
    check('A1 ' + f + ': refused until the role exists, under the field (a dropdown too)', cfgErr(env, f).indexOf('&#9888; Validator configuration error: @UVWRITEONCE on "' + f + '": ') === 0
      && /is not a role of this project/.test(cfgErr(env, f)) && !isLocked(env, f), cfgErr(env, f));
  }
  check('A1 no error is left for the page-level notice', env.doc.getElementById('uvalidate-config-errors') === null);
}
{
  const env = boot(PAGES.b1, PAGES.b1_values);
  for (const f of Object.keys(PAGES.errors)) {
    check('F ' + f + ': its error is shown under the field', PAGES.errors[f] !== '' && cfgErr(env, f).indexOf(PAGES.errors[f]) >= 0, cfgErr(env, f));
  }
  for (const f of TAGGED) check('A2 ' + f + ': configures, and nothing is locked before the first save', cfgErr(env, f) === '' && !isLocked(env, f) && note(env, f) === '', [cfgErr(env, f), note(env, f)]);
  // ---- Section B1: fill the form and save ----
  type(env, 'uw_consent', '2026-10-01'); pick(env, 'uw_arm', '1'); pick(env, 'uw_yn', '1');
  type(env, 'uw_notes', 'First note'); type(env, 'uw_pain', '50'); type(env, 'uw_weight', '70.0'); type(env, 'uw_free', 'a');
  type(env, 'uw_bad_role', 'x'); type(env, 'uw_bad_setvalue', 'x'); type(env, 'uw_bad_when', 'x'); type(env, 'uw_bad_value', 'x');
  check('B1 the values stay as typed', env.ctl.uw_consent.el.value === '2026-10-01' && env.ctl.uw_arm.el.value === '1' && env.ctl.uw_notes.el.value === 'First note');
  check('B1 the record saves; the bad fields never block', save(env) === 'saved', save(env));
}

// ---- Section B2: reopened, the saved fields are locked ------------------------------------------
{
  const env = boot(PAGES.b2, PAGES.b2_values);
  for (const f of ['uw_consent', 'uw_arm', 'uw_yn', 'uw_notes', 'uw_pain']) {
    check('B2 ' + f + ' is locked, with the default note', isLocked(env, f) && note(env, f) === LOCK, [isLocked(env, f), note(env, f)]);
  }
  for (const f of ['uw_site', 'uw_weight', 'uw_free']) check('B2 ' + f + ' is not locked and has no note', !isLocked(env, f) && note(env, f) === '');
  check('B2 the date picker is kept from opening and the slider is disabled', typeof env.ctl.uw_consent.el.__beforeShow === 'function'
    && env.ctl.uw_consent.el.__beforeShow() === false
    && env.calls.some((c) => c[0] === 'slider' && c[1] === 'slider-uw_pain' && c[2] === 'disable'), env.calls);
  check('B2 the date box is read-only, not disabled, so the form posts it', env.ctl.uw_consent.el.readOnly === true && env.ctl.uw_consent.el.disabled !== true);
  check('B2 the arm\'s buttons take no click', (pick(env, 'uw_arm', '2'), env.ctl.uw_arm.el.value === '1'));
  // a script (or the date picker's own "Today") changes the date: undone, and the note says so
  type(env, 'uw_consent', '2026-10-05');
  check('B2 a change to the date is undone', env.ctl.uw_consent.el.value === '2026-10-01', env.ctl.uw_consent.el.value);
  check('B2 ... and the note says so', note(env, 'uw_consent') === LOCK + UNDONE, note(env, 'uw_consent'));
  // the radio "reset" link, if a script clicks it: the mirror emptied
  env.ctl.uw_yn.el.value = ''; env.ctl.uw_yn.buttons.forEach((b) => { b.checked = false; }); env.ctl.uw_yn.el.fire('change');
  check('B2 a reset of Eligible is undone', env.ctl.uw_yn.el.value === '1' && env.ctl.uw_yn.buttons[0].checked === true, env.ctl.uw_yn.el.value);
  // a change no event announces, put back before the save
  env.ctl.uw_notes.el.value = 'Edited';
  check('B2 a silent change to the note is put back at the save, which goes through', save(env) === 'saved' && env.ctl.uw_notes.el.value === 'First note\nsecond line', env.ctl.uw_notes.el.value);
  // B2d: a change no event announces, then Enter in another field
  env.ctl.uw_consent.el.value = '2026-12-31';
  check('B2d Enter in uw_free saves, with the date put back first', pressEnter(env, 'uw_free') === 'redcap-save'
    && env.submits.length === 1 && env.ctl.uw_consent.el.value === '2026-10-01', [env.submits, env.ctl.uw_consent.el.value]);
  pick(env, 'uw_site', '1');
  check('B3 the site takes its first value and the record saves', env.ctl.uw_site.el.value === '1' && save(env) === 'saved');
}

// ---- Section B6 and B7: a rule on the locked note that reads the free text (lockReads) -------------
{
  const env = boot(PAGES.b6, PAGES.b6_values);
  type(env, 'uw_free', 'stop');
  const held = save(env);
  check('B6 the rule on the locked note holds the save once the free text it reads breaks it, naming the note',
    /Cannot save yet/.test(held) && /Screening note/.test(held), held);
  type(env, 'uw_free', 'ok');
  check('B6 ... and the save goes through once it is put right', save(env) === 'saved');
}
{
  // the same with the engine above the form, as REDCap prints it: the opening value is still read from the form
  const env = boot(PAGES.b6, PAGES.b6_values, { late: true });
  type(env, 'uw_free', 'stop');
  const held = save(env);
  check('B6 (engine above the form) the free text that breaks the rule holds the save', /Cannot save yet/.test(held) && /Screening note/.test(held), held);
  type(env, 'uw_free', 'ok');
  check('B6 (engine above the form) ... and it goes through once put right', save(env) === 'saved');
}
{
  // REDCap prints the engine above the form: the page is still loading when it runs (review W4-2)
  const env = boot(PAGES.b7, PAGES.b7_values, { late: true });
  check('B7 the engine ran above the form, and the note is locked once the form exists', isLocked(env, 'uw_notes') && note(env, 'uw_notes') === LOCK, note(env, 'uw_notes'));
  check('B7 the free text broke the rule before the page opened: Enter saves', pressEnter(env, 'uw_weight') === 'redcap-save' && env.submits.length === 1,
    env.win._alerts);
  check('B7 ... and so does Save', save(env) === 'saved');
  type(env, 'uw_free', 'ok');
  check('B7b the free text put right saves', save(env) === 'saved');
}

// ---- Section B4: the site is locked, with its own message; a keyboard change is undone ------------
{
  const env = boot(PAGES.b4, PAGES.b4_values);
  check('B4 the site is locked, not disabled (REDCap would save it blank)', isLocked(env, 'uw_site'), env.ctl.uw_site.el.disabled);
  check('B4 ... with the rule\'s message', note(env, 'uw_site') === '🔒 Ask the data manager to change the site.', note(env, 'uw_site'));
  pick(env, 'uw_site', '3');
  check('B4 a new choice is undone', env.ctl.uw_site.el.value === '1' && env.ctl.uw_site.ac.value === '1', [env.ctl.uw_site.el.value, env.ctl.uw_site.ac.value]);
  check('B4 ... the note says so', note(env, 'uw_site') === '🔒 Ask the data manager to change the site.' + UNDONE, note(env, 'uw_site'));
  check('B4 the record saves with the saved site', save(env) === 'saved' && env.ctl.uw_site.el.value === '1');
  type(env, 'uw_weight', '71.0');
  check('B5 the weight is not locked yet: it changes and saves', env.ctl.uw_weight.el.value === '71.0' && save(env) === 'saved');
}

// ---- Section C2: after the import, the imported values are locked; the cleared note is not ---------
{
  const env = boot(PAGES.c2, PAGES.c2_values);
  check('C2 the date shows the imported 2026-10-02, locked', env.ctl.uw_consent.el.value === '2026-10-02' && isLocked(env, 'uw_consent'));
  check('C2 the site shows Yaounde (2), locked', env.ctl.uw_site.el.value === '2' && isLocked(env, 'uw_site'));
  check('C2 the cleared note is blank and open', env.ctl.uw_notes.el.value === '' && !isLocked(env, 'uw_notes') && note(env, 'uw_notes') === '');
  check('C3 the form saves as it is', save(env) === 'saved');
}

// ---- Section D: in the role, then out of it ------------------------------------------------------
{
  const env = boot(PAGES.d1, PAGES.d1_values);
  for (const f of ['uw_arm', 'uw_site', 'uw_yn']) check('D1 in the role: ' + f + ' is open', !isLocked(env, f) && note(env, f) === '');
  for (const f of ['uw_consent', 'uw_pain']) check('D1 in the role: ' + f + ' stays locked', isLocked(env, f) && note(env, f) === LOCK);
  pick(env, 'uw_arm', '2');
  check('D2 the arm changes to Arm B and the record saves', env.ctl.uw_arm.el.value === '2' && save(env) === 'saved');
}
{
  const env = boot(PAGES.d4, PAGES.d4_values);
  check('D4 out of the role: the arm is locked at Arm B', env.ctl.uw_arm.el.value === '2' && isLocked(env, 'uw_arm') && note(env, 'uw_arm') === LOCK);
  check('D4 ... and so are the site and Eligible', isLocked(env, 'uw_site') && isLocked(env, 'uw_yn'));
}

console.log('writeonce_live_twin_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
