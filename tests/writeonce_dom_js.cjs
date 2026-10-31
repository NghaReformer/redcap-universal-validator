/*
 * writeonce_dom_js.cjs — the @UVWRITEONCE mode's DOM contract.
 *
 * Drives the real QRIDWriteOnceInit factory through the DOM stub the other
 * *_dom tests use. The server sends a write-once rule only with the fields it
 * locks for this viewer (UniversalValidator::pageWriteonce), so every field
 * here is locked, and this file asserts:
 *   - a text box is read-only and a lock note is shown under it, in the
 *     rule's own words when it has a message (escaped, in the page's
 *     language), else the default; the save goes through,
 *   - the radio buttons are disabled and REDCap's "reset" link is hidden; a
 *     dropdown is not disabled (REDCap would save it blank) but marked
 *     read-only, and its autocomplete box is read-only,
 *   - a change made anyway (an event, a jQuery change, a script with no
 *     event at all) is undone, the note says so, and the save sends the
 *     saved value; a value that cannot be put back holds the save,
 *   - another mode's item on a locked field never holds the save, whatever
 *     order the rules boot in, while the same rule on an unlocked field does,
 *   - the slider and the date picker are disabled through jQuery UI,
 *   - validators[field].test() says whether the value is the saved one,
 *   - a configuration error locks nothing and is shown under the field.
 *
 * Run:  node tests/writeonce_dom_js.cjs
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
    tagName: (tag || 'div').toUpperCase(), id: '', name: '', value: '', innerHTML: '',
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
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
    insertBefore(node, ref) {
      node.parentNode = this;
      const i = ref ? this.children.indexOf(ref) : -1;
      if (i >= 0) this.children.splice(i, 0, node); else this.children.push(node);
      return node;
    },
    getElementsByTagName(t) {
      const out = [];
      const walk = (e) => { for (const c of e.children) { if (c.tagName === t.toUpperCase()) out.push(c); walk(c); } };
      walk(this);
      return out;
    },
    closest() { return null; },
    /* class selectors only, joined by commas, as the lock asks */
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

/* Boot the engine over els (each in its own holder; els with a row id share
   a row) and return the page. opts.jQuery installs a jQuery stub that
   records the widget calls and runs ready callbacks as jQuery 3 does: its
   own DOMContentLoaded listener, added when it loads (before the engine),
   runs each one in a timer, and one added after that goes straight to a
   timer. opts.late loads the engine on a page still loading, before els
   exist, then adds them and fires DOMContentLoaded, as REDCap does: it
   prints the engine above the form; timers started before the form runs
   before it is added. opts.beforeEngine(doc, win) runs before
   the engine loads (REDCap's scripts in the page head), opts.beforeForm(win)
   after it, before the form. Timers wait in a queue that flush() runs in
   order; boot runs it once the page has started, as a browser would, unless
   opts.hold. Interval callbacks are kept, so a test can run the
   silent-change poll by hand (poll()). */
function boot(els, config, opts) {
  opts = opts || {};
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  const allEls = [];
  const body = makeEl('body');
  const holders = {};
  const rows = {};
  const later = [];
  const place = (el) => { if (opts.late) later.push(el); else allEls.push(el); };
  for (const el of els) {
    let holder = el._row ? rows[el._row] : null;
    if (!holder) {
      holder = makeEl('div');
      if (el._row) { holder.id = el._row; rows[el._row] = holder; }
      if (!opts.late) body.appendChild(holder);
      place(holder);
    }
    holder.appendChild(el);
    place(el);
    for (const k of el.children) place(k);
    if (el.name && !holders[el.name]) holders[el.name] = holder;
  }
  const doc = {
    body, readyState: opts.late ? 'loading' : 'complete', _handlers: {}, activeElement: null,
    createElement(t) { const e = makeEl(t); allEls.push(e); return e; },
    getElementById(id) { return allEls.find((e) => e.id === id) || null; },
    getElementsByName(name) { return allEls.filter((e) => e.name === name); },
    querySelector() { return null; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev)); },
  };
  const calls = [];
  const win = {
    _alerts: [], _confirms: [], confirmAnswer: false,
    alert(m) { this._alerts.push(m); }, confirm(m) { this._confirms.push(m); return this.confirmAnswer; },
    INSPIRE_VALIDATOR_CONFIG: Object.assign({ singleFields: [], pooledFields: [] }, PAGE_DEFAULTS, config),
  };
  if (opts.stub) win.EMStub = { UV: opts.stub.obj };
  const timers = [];
  global.setTimeout = (fn) => { const t = { fn, cleared: false }; timers.push(t); return t; };
  global.clearTimeout = (t) => { if (t && typeof t === 'object') t.cleared = true; };
  const flush = () => {
    for (let i = 0; timers.length && i < 10000; i++) { const t = timers.shift(); if (!t.cleared) t.fn(); }
  };
  if (opts.jQuery) {
    const ready = { done: doc.readyState !== 'loading', list: [] };
    if (!ready.done) doc.addEventListener('DOMContentLoaded', () => { ready.done = true; ready.list.splice(0).forEach((fn) => setTimeout(fn, 0)); });
    const jq = function (sel) {
      if (typeof sel === 'function') { if (ready.done) setTimeout(sel, 0); else ready.list.push(sel); return undefined; }
      const el = typeof sel === 'string' ? (sel[0] === '#' ? doc.getElementById(sel.slice(1)) : null) : sel;
      const w = { length: el ? 1 : 0, fn: null };
      w.on = function (events, handler) {
        for (const ev of events.split(' ')) if (el) el.addEventListener(ev.split('.')[0], handler);
        return w;
      };
      w.hasClass = (c) => !!el && (' ' + el.className + ' ').indexOf(' ' + c + ' ') >= 0;
      w.slider = (a) => {
        calls.push(['slider', el && el.id, a]);
        if (el && a === 'disable') el.className = (el.className ? el.className + ' ' : '') + 'ui-slider-disabled';
        return w;
      };
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
  }
  global.document = doc; global.window = win;
  const intervals = [];
  global.setInterval = (fn) => { intervals.push(fn); return { unref() {} }; };
  global.clearInterval = () => {};
  if (opts.beforeEngine) opts.beforeEngine(doc, win);
  require(enginePath);
  if (opts.late) {
    // the form below the engine, then DOMContentLoaded; the parser may stop between the two, so a
    // timer started while the page is still loading may run before the form exists
    if (opts.beforeForm) opts.beforeForm(win);
    flush();
    for (const el of later) { allEls.push(el); if (el.tagName === 'DIV' && !el.parentNode) body.appendChild(el); }
    doc.readyState = 'interactive';
    doc.fire('DOMContentLoaded', {});
  }
  if (!opts.hold) flush();
  return { doc, win, holders, allEls, calls, flush, NS: win.INSPIREUniversalValidator, poll() { intervals.forEach((f) => f()); } };
}
function region(env, field, suffix) {
  const kids = env.holders[field].children;
  for (const k of kids) if (k.id && new RegExp('-' + suffix + '$').test(k.id)) return k;
  return null;
}
function note(env, field) {
  const m = region(env, field, 'o');
  return m && m.style.display !== 'none' ? m.innerHTML.replace(/<[^>]*>/g, '').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>') : '';
}
function submitEv() {
  return { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
}
/* Submit and say what the guard did: "held" (alert), "asked" (confirm) or "saved". */
function save(env) {
  const a = env.win._alerts.length, c = env.win._confirms.length;
  const ev = submitEv(); env.doc.fire('submit', ev);
  if (env.win._alerts.length > a) return 'held';
  if (env.win._confirms.length > c) return 'asked';
  return ev._prevented ? 'prevented' : 'saved';
}
function text(name, value) { const e = makeEl('input'); e.name = name; e.type = 'text'; e.value = value; return e; }
function option(v, label) { const o = makeEl('option'); o.value = v; o.text = label || v; return o; }
function select(name, codes, value) {
  const s = makeEl('select'); s.name = name;
  s.options = codes.map((c) => option(c));
  s.value = value;
  const append = s.appendChild;
  s.appendChild = function (c) { append.call(s, c); if (c.tagName === 'OPTION') s.options.push(c); return c; };
  return s;
}
/* A REDCap radio field: the hidden mirror, one button per code and the reset link, in one row. */
function radioField(name, codes, value) {
  const row = name + '-tr';
  const mirror = makeEl('input'); mirror.name = name; mirror.type = 'text'; mirror.value = value; mirror._row = row;
  const buttons = codes.map((c) => { const b = makeEl('input'); b.name = name + '___radio'; b.type = 'radio'; b.value = c; b.checked = c === value; b._row = row; return b; });
  const reset = makeEl('a'); reset.setAttribute('onclick', "radioResetVal('" + name + "','form');return false;"); reset._row = row;
  reset.style.display = 'inline';
  return { mirror, buttons, reset, els: [mirror].concat(buttons, [reset]) };
}
const WO = (fields, extra) => Object.assign({ type: 'writeonce', fields }, extra || {});
const DEFAULT = 'This value was saved once and is locked. It cannot be changed here.';

// ---- 1) a text box: read-only, a note, the save goes through -------------------------
{
  const f = text('consent_date', '2026-03-01');
  const env = boot([f], { rules: [WO(['consent_date'])] });
  check('a locked text box is read-only', f.readOnly === true);
  check('... with the default note under it', note(env, 'consent_date') === '&#128274; ' + DEFAULT,
    note(env, 'consent_date'));
  check('... in the lock region (id ends "-o"), muted grey, not red', region(env, 'consent_date', 'o') && /#f4f4f4/.test(region(env, 'consent_date', 'o').style.cssText));
  check('... no outline or aria-invalid of its own', f.getAttribute('aria-invalid') === null && !f.style.outline);
  check('... the save goes through', save(env) === 'saved');
  check('validators.consent_date.test(): the saved value', env.NS.validators.consent_date.test() === true);

  // a script changes it with an event: undone, and the note says so
  f.value = '2026-04-01'; f.fire('change');
  check('a change with an event is undone', f.value === '2026-03-01', f.value);
  check('... and the note says so', /A change was undone\.$/.test(note(env, 'consent_date')), note(env, 'consent_date'));
  check('... the save goes through with the saved value', save(env) === 'saved' && f.value === '2026-03-01');
}

// ---- 2) a change no event announces -------------------------------------------------
{
  const f = text('arm', 'A');
  const env = boot([f], { rules: [WO(['arm'])] });
  f.value = 'B';
  check('a silent change: test() says it is not the saved value', env.NS.validators.arm.test() === false);
  env.poll();
  check('... the silent-change poll undoes it', f.value === 'A', f.value);
  f.value = 'C';
  check('a silent change at save: put back before the save, which goes through', save(env) === 'saved' && f.value === 'A', f.value);
  check('... the note says a change was undone', /A change was undone/.test(note(env, 'arm')));
}

// ---- 3) a dropdown: not disabled, marked read-only, a change undone -------------------------
{
  const s = select('site', ['', '1', '2', '3'], '2');
  const ac = makeEl('input'); ac.id = 'rc-ac-input_site'; ac.value = '2';
  const env = boot([s, ac], { rules: [WO(['site'])] });
  check('a locked dropdown is not disabled (REDCap would save it blank)', s.disabled === false);
  check('... it is marked read-only and takes no clicks', s.getAttribute('aria-readonly') === 'true' && s.style.pointerEvents === 'none');
  check('... its autocomplete box is read-only', ac.readOnly === true);
  s.value = '3'; s.fire('change');
  check('a new choice is undone', s.value === '2', s.value);
  check('... the autocomplete box shows the saved choice again', ac.value === '2', ac.value);
  check('... the save goes through', save(env) === 'saved');
  // the saved choice taken off the page: it cannot be put back, and the save is held
  s.options = s.options.filter((o) => o.value !== '2');
  s.value = '1';
  check('a value that cannot be put back holds the save', save(env) === 'held', env.win._alerts);
  check('... the alert names the field', /site/.test(env.win._alerts[env.win._alerts.length - 1] || ''));
}

// ---- 4) a radio: buttons disabled, reset hidden, a reset undone ---------------------------
{
  const r = radioField('sex', ['1', '2'], '2');
  const env = boot(r.els, { rules: [WO(['sex'])] });
  check('the radio buttons are disabled', r.buttons.every((b) => b.disabled === true));
  check('the hidden mirror stays posted (not disabled)', r.mirror.disabled === false);
  check('REDCap\'s reset link is hidden', r.reset.style.display === 'none');
  check('... the note sits under the field', note(env, 'sex') !== '');
  // what the reset link does: the mirror emptied, every button unchecked
  r.mirror.value = ''; r.buttons.forEach((b) => { b.checked = false; }); r.mirror.fire('change');
  check('a reset is undone: the code is back in the mirror', r.mirror.value === '2', r.mirror.value);
  check('... and its button is checked again', r.buttons[1].checked === true && r.buttons[0].checked === false);
  check('... the save goes through', save(env) === 'saved');
}

// ---- 5) another mode on a locked field never holds the save --------------------------
for (const order of ['lock first', 'lock last']) {
  const locked = text('weight', '70'), open = text('height', '150');
  const lock = WO(['weight']);
  const asserts = [
    { type: 'constraint', fields: ['weight'], assert: '[weight]>100', blockSave: 'hard', message: 'Too light' },
    { type: 'constraint', fields: ['height'], assert: '[height]>200', blockSave: 'hard', message: 'Too short' },
  ];
  const rules = order === 'lock first' ? [lock].concat(asserts) : asserts.concat([lock]);
  const env = boot([locked, open], { rules });
  const a = env.win._alerts.length;
  check(order + ': the save is held for the unlocked field', save(env) === 'held');
  const said = env.win._alerts[a] || '';
  check(order + ': ... naming only the unlocked field', /height/.test(said) && !/weight/.test(said), said);
  open.value = '201'; open.fire('change');
  check(order + ': with the unlocked field fixed, the locked field\'s other rule does not hold the save', save(env) === 'saved');
  check(order + ': ... though its note still shows', /Too light/.test(region(env, 'weight', 'c') ? region(env, 'weight', 'c').innerHTML : ''));
}

// ---- 6) the rule's own message, escaped, and a survey -------------------------------
{
  const f = text('dob', '2020-01-01');
  const env = boot([f], { context: 'survey', rules: [WO(['dob'], { message: 'Call <b>us</b> to correct it' })] });
  check('a survey locks too', f.readOnly === true);
  check('the rule\'s message replaces the default, escaped', region(env, 'dob', 'o').innerHTML.indexOf('Call &lt;b&gt;us&lt;/b&gt; to correct it') >= 0
    && region(env, 'dob', 'o').innerHTML.indexOf(DEFAULT) < 0, region(env, 'dob', 'o').innerHTML);
}

// ---- 7) a slider and a date picker, through jQuery UI ---------------------------------
{
  const sl = text('pain', '40');
  const sliderDiv = makeEl('div'); sliderDiv.id = 'slider-pain';
  const dt = text('visit_date', '01-03-2026'); dt.className = 'x hasDatepicker';
  const env = boot([sl, sliderDiv, dt], { rules: [WO(['pain', 'visit_date'])] }, { jQuery: true });
  check('the slider widget is disabled', env.calls.some((c) => c[0] === 'slider' && c[1] === 'slider-pain' && c[2] === 'disable'), env.calls);
  check('the date picker is kept from opening, its trigger hidden (got ' + JSON.stringify(env.calls) + ')',
    typeof dt.__beforeShow === 'function' && dt.__beforeShow() === false && env.calls.some((c) => c[0] === 'hide-trigger' && c[1] === 'visit_date'));
  check('... and the date box is not disabled, so the form still posts it (review H1)', dt.disabled !== true);
  check('... and both boxes are read-only', sl.readOnly === true && dt.readOnly === true);
  // a jQuery change (the date picker's own) reaches the lock through the jQuery listener
  dt.value = '02-03-2026'; dt.fire('change');
  check('a date picker change is undone', dt.value === '01-03-2026', dt.value);
}

{
  // REDCap draws a slider's number box read-only (sldrnum); the lock disables the widget and marks it,
  // so a rule on the slider that reads another field keeps its guard item (LIVE-D1)
  const sl = text('pain', '40'); sl.readOnly = true; sl.className = 'sldrnum';
  const sliderDiv = makeEl('div'); sliderDiv.id = 'slider-pain';
  const lvl = text('pain_max', '50');
  const env = boot([sl, sliderDiv, lvl], { rules: [WO(['pain']),
    { type: 'constraint', fields: ['pain'], assert: '[pain]<[pain_max]', blockSave: 'hard', lockReads: { pain: ['pain_max'] } }] }, { jQuery: true });
  check('a slider the lock disabled is marked as the lock\'s', / ui-slider-disabled /.test(' ' + sliderDiv.className + ' ') && sliderDiv.__qridLockSetDisabled === true);
  check('... the save goes through while the rule holds', save(env) === 'saved', env.win._alerts);
  lvl.value = '30'; lvl.fire('change');
  check('... and is held once the other field breaks it (not taken for @READONLY)', save(env) === 'held', env.win._alerts);
}

// ---- 8) configuration errors, duplicates ----------------------------------------------
{
  const f = text('x1', 'v');
  const env = boot([f], { rules: [WO(['x1'], { configError: '@UVWRITEONCE on "x1": "Nobody" is not a role of this project.' })] });
  check('a configuration error locks nothing', f.readOnly === false && region(env, 'x1', 'o') === null);
  check('... and is shown under the field', !!region(env, 'x1', 'cfg') && /Nobody/.test(region(env, 'x1', 'cfg').innerHTML));
  check('... the field is not counted as locked: another rule on it may hold the save', (function () {
    const g = text('x2', '5');
    const e2 = boot([g], { rules: [WO(['x2'], { configError: 'bad' }), { type: 'constraint', fields: ['x2'], assert: '[x2]>9', blockSave: 'hard' }] });
    return save(e2) === 'held';
  })());
}
{
  const f = text('y1', 'v');
  const env = boot([f], { rules: [WO(['y1']), WO(['y1'])] });
  check('two write-once rules on one field: a duplicate error, no lock', f.readOnly === false && !!region(env, 'y1', 'cfg'));
}

// ---- 9) a rule on a locked field that reads another field (review M3) ----------------
for (const order of ['lock first', 'lock last']) {
  const locked = text('weight', '70'), open = text('height', '150');
  const lock = WO(['weight']);
  const rule = { type: 'constraint', fields: ['weight'], assert: '[weight]<[height]', blockSave: 'hard', message: 'Heavier than tall', lockReads: { weight: ['height'] } };
  const env = boot([locked, open], { rules: order === 'lock first' ? [lock, rule] : [rule, lock] });
  check(order + ': the locked field is read-only', locked.readOnly === true);
  check(order + ': a rule on a locked field that reads another field: the save goes through while it holds', save(env) === 'saved');
  open.value = '50'; open.fire('change');
  const a = env.win._alerts.length;
  check(order + ': ... and is held once that other field breaks it, naming the locked field', save(env) === 'held' && /weight/.test(env.win._alerts[a] || ''), env.win._alerts);
}
{
  // @READONLY was there before the lock: the field still never holds the save (UX-003)
  const locked = text('weight', '70'), open = text('height', '50');
  locked.readOnly = true;
  const env = boot([locked, open], { rules: [WO(['weight']),
    { type: 'constraint', fields: ['weight'], assert: '[weight]<[height]', blockSave: 'hard', message: 'Heavier than tall', lockReads: { weight: ['height'] } }] });
  open.value = '40'; open.fire('change');
  check('a field REDCap made read-only (@READONLY) never holds the save, even with the lock reading it', save(env) === 'saved', env.win._alerts);
}
{
  // a violation saved before the page opened does not trap the form (review W2-2)
  const locked = text('weight', '70'), open = text('height', '50');
  const env = boot([locked, open], { rules: [WO(['weight']),
    { type: 'constraint', fields: ['weight'], assert: '[weight]<[height]', blockSave: 'hard', message: 'Heavier than tall', lockReads: { weight: ['height'] } }] });
  check('a violation already saved when the page opened does not hold the save', save(env) === 'saved', env.win._alerts);
  open.value = '40'; open.fire('change');
  check('... a change of the other field that still breaks it does', save(env) === 'held');
  open.value = '50'; open.fire('change');
  check('... and the other field put back as it was lets the save through again', save(env) === 'saved');
  open.value = '40';
  check('... a change no event announced counts too', save(env) === 'held');
}
{
  // two locked fields that read each other: the server marks neither (no field left to change), so nothing traps the form
  const a = text('wa', '70'), b = text('wb', '60');
  const env = boot([a, b], { rules: [WO(['wa', 'wb']),
    { type: 'constraint', fields: ['wa'], assert: '[wa]<[wb]', blockSave: 'hard' },
    { type: 'constraint', fields: ['wb'], assert: '[wb]>[wa]', blockSave: 'hard' }] });
  check('two locked fields that read each other never hold the save', save(env) === 'saved', env.win._alerts);
}
{
  // a box the lock made read-only that REDCap then disables (form locking) counts as one the person cannot edit (review W2-13)
  const k = text('kk', '70'); k.disabled = true;
  const h = text('kh', '150');
  const env = boot([k, h], { rules: [WO(['kk']),
    { type: 'constraint', fields: ['kk'], assert: '[kk]<[kh]', blockSave: 'hard', lockReads: { kk: ['kh'] } }] });
  check('a locked box REDCap disabled: read-only by the lock, and marked so', k.readOnly === true && k.__qridLockSetRO === true);
  check('... and no other rule on it gets a guard item, the disabled state being REDCap\'s', !env.NS.guard.items.some((it) => it.__qridFieldName === 'kk' && it.__qridMode === 'constraint'),
    env.NS.guard.items.map((it) => it.__qridFieldName + ':' + it.__qridMode));
}
{
  // a radio: the lock disables the buttons, which must not count as @READONLY for the choices filter
  const r = radioField('grp', ['1', '2', '9'], '9');
  const legacy = text('legacy', '1');
  const env = boot(r.els.concat([legacy]), { rules: [WO(['grp']),
    { type: 'choices', fields: ['grp'], choicesHide: ['9'], choicesAll: ['1', '2', '9'], when: "[legacy]='0'", blockSave: 'hard', lockReads: { grp: ['legacy'] } }] });
  check('a radio the lock disabled still gets the choices filter\'s guard item', env.NS.guard.items.some((it) => it.__qridFieldName === 'grp' && it.__qridMode === 'choices'),
    env.NS.guard.items.map((it) => it.__qridMode));
  check('... the save goes through while the saved code is shown', save(env) === 'saved', env.win._alerts);
  legacy.value = '0'; legacy.fire('change');
  check('... it holds the save once the other field hides the saved code', save(env) === 'held');
  legacy.value = '1'; legacy.fire('change');
  check('... and lets it through once the other field changes back', save(env) === 'saved', env.win._alerts);
}
{
  const locked = text('weight', '70');
  const open = text('height', '200');
  const env = boot([locked, open], { rules: [WO(['weight']),
    { type: 'constraint', fields: ['weight'], assert: '[weight]<[height]', blockSave: 'hard', message: 'Heavier than tall', lockReads: { weight: ['height'] } }] });
  // a lookup still running for a rule that reads the locked field alone does not hold
  env.NS.guard.items.push({ __qridFieldName: 'weight', __qridMode: 'unique', __qridPending: true, __qridInvalid: false,
    __qridBlockMode: 'hard', __qridFieldLabel: 'weight', focus() {} });
  check('a lookup still running on a locked field, for a rule that reads it alone, does not hold the save', save(env) === 'saved', env.win._alerts);
  env.NS.guard.items.push({ __qridFieldName: 'weight', __qridMode: 'constraint', __qridPending: true, __qridInvalid: false,
    __qridBlockMode: 'hard', __qridFieldLabel: 'weight', focus() {} });
  check('... nor one for a rule that reads another field, while that field is as the page opened', save(env) === 'saved');
  open.value = '201'; open.fire('change');
  check('... which holds the save once that field has changed', save(env) === 'held');
}

for (const order of ['lock first', 'lock last']) {
  // a dropdown with an autocomplete box: the box the lock made read-only is not @READONLY's,
  // so a rule on the dropdown that reads another field keeps its guard item (QRID_fieldLocked)
  const s = select('grp', ['', '1', '2', '9'], '9');
  const ac = makeEl('input'); ac.id = 'rc-ac-input_grp'; ac.value = '9';
  const legacy = text('legacy', '1');
  const lock = WO(['grp']);
  const rule = { type: 'choices', fields: ['grp'], choicesHide: ['9'], choicesAll: ['1', '2', '9'], when: "[legacy]='0'",
    blockSave: 'hard', lockReads: { grp: ['legacy'] } };
  const env = boot([s, ac, legacy], { rules: order === 'lock first' ? [lock, rule] : [rule, lock] });
  check(order + ': the autocomplete box the lock made read-only is marked so', ac.readOnly === true && ac.__qridLockSetRO === true);
  check(order + ': ... the save goes through while the saved code is allowed', save(env) === 'saved', env.win._alerts);
  legacy.value = '0'; legacy.fire('change');
  check(order + ': ... and is held once the other field hides the saved code', save(env) === 'held', env.win._alerts);
}
{
  // an autocomplete box REDCap made read-only (@READONLY) before the lock still counts
  const s = select('grp', ['', '1', '2', '9'], '9');
  const ac = makeEl('input'); ac.id = 'rc-ac-input_grp'; ac.value = '9'; ac.readOnly = true;
  const legacy = text('legacy', '1');
  const env = boot([s, ac, legacy], { rules: [WO(['grp']), { type: 'choices', fields: ['grp'], choicesHide: ['9'],
    choicesAll: ['1', '2', '9'], when: "[legacy]='0'", blockSave: 'hard', lockReads: { grp: ['legacy'] } }] });
  check('an autocomplete box already read-only is not marked as the lock\'s', ac.__qridLockSetRO !== true);
  legacy.value = '0'; legacy.fire('change');
  check('... and the dropdown never holds the save (UX-003)', save(env) === 'saved', env.win._alerts);
}

{
  // REDCap prints the engine above the form: the base of each field the rule reads is taken
  // once the form exists, not before (review W3-1)
  const locked = text('weight', '70'), open = text('height', '50');
  const env = boot([locked, open], { rules: [WO(['weight']),
    { type: 'constraint', fields: ['weight'], assert: '[weight]<[height]', blockSave: 'hard', message: 'Heavier than tall', lockReads: { weight: ['height'] } }] },
    { late: true });
  check('engine above the form: the locked field is read-only once the form exists', locked.readOnly === true);
  check('... a violation already saved when the page opened does not hold the save', save(env) === 'saved', env.win._alerts);
  open.value = '40'; open.fire('change');
  check('... a change of the other field that still breaks it does', save(env) === 'held');
}
{
  // REDCap's own start-up writes fields after DOMContentLoaded: its calcs, branching and action tags run in
  // jQuery ready callbacks, which jQuery 3 runs in a timer. A value it writes is no change by the person,
  // so the base is taken after it (review W4-2). The reviewer's probe: dob locked, age a calc printed 18 and
  // worked out again as 17 at start-up, the rule on dob reads [age]; nobody types.
  const probe = (label, start, opts) => {
    const dob = text('dob', '2008-03-01'), age = text('age', '18'), visit = text('visit_date', '2026-02-01');
    age.readOnly = true;
    const env = boot([dob, age, visit], { rules: [WO(['dob']),
      { type: 'constraint', fields: ['dob'], assert: '[age] >= 18', blockSave: 'hard', message: 'Under 18', lockReads: { dob: ['age'] } }] },
      Object.assign({ late: true }, start(age), opts || {}));
    check('start-up write ' + label + ': nobody typed, the save goes through', save(env) === 'saved', env.win._alerts);
    age.value = '16';
    check('start-up write ' + label + ': ... a later change of the field read still holds it', save(env) === 'held', env.win._alerts);
    return env;
  };
  // as the probe wrote it: a DOMContentLoaded listener added after the engine, writing in a timer
  probe('in a timer of a DOMContentLoaded listener after the engine (the probe)', (age) => ({ jQuery: true,
    beforeForm() { document.addEventListener('DOMContentLoaded', () => setTimeout(() => { age.value = '17'; }, 0)); } }));
  // REDCap's $(function(){ calculate(); doBranching(); }), in the page head and below the form
  probe('in a jQuery ready callback added before the engine', (age) => ({ jQuery: true,
    beforeEngine(doc, win) { win.jQuery(() => { age.value = '17'; }); } }));
  probe('in a jQuery ready callback added below the form', (age) => ({ jQuery: true,
    beforeForm(win) { win.jQuery(() => { age.value = '17'; }); } }));
  probe('in a timer a jQuery ready callback starts', (age) => ({ jQuery: true,
    beforeEngine(doc, win) { win.jQuery(() => { setTimeout(() => { age.value = '17'; }, 0); }); } }));
  // a page without jQuery: a DOMContentLoaded listener added before the engine, writing in a timer
  probe('in a timer of a DOMContentLoaded listener before the engine, no jQuery', (age) => ({
    beforeEngine(doc) { doc.addEventListener('DOMContentLoaded', () => setTimeout(() => { age.value = '17'; }, 0)); } }));
  // a page that has loaded already: a ready callback queued before the engine still runs first
  probe('on a page that has loaded, in a jQuery ready callback', (age) => ({ late: false, jQuery: true,
    beforeEngine(doc, win) { win.jQuery(() => { age.value = '17'; }); } }));
}
{
  // The fifth review's scenario: dob is locked, visit carries @TODAY, and the rule on dob reads it. The server
  // now names visit in lockReads (review W5-1): REDCap fills it as the page starts, before the base is taken,
  // so naming it holds nothing, while the person's own change of it is caught.
  const dob = text('dob', '2026-01-10'), visit = text('visit', '');
  const env = boot([dob, visit], { rules: [WO(['dob']),
    { type: 'constraint', fields: ['dob'], assert: '[dob] <= [visit]', blockSave: 'hard', message: 'Visit before consent', lockReads: { dob: ['visit'] } }] },
    { late: true, jQuery: true, beforeEngine(doc, win) { win.jQuery(() => { visit.value = '2026-01-20'; }); } });
  check('@TODAY read by a rule on a locked field: the date REDCap writes as the page starts holds nothing', visit.value === '2026-01-20' && save(env) === 'saved', env.win._alerts);
  visit.value = '2026-01-05'; visit.fire('change');
  check('... the person moving the visit before dob holds the save', save(env) === 'held', env.win._alerts);
  visit.value = '2026-01-25'; visit.fire('change');
  check('... and moving it after dob again lets it through', save(env) === 'saved', env.win._alerts);
}
{
  // until the base is taken nothing is compared
  const locked = text('weight', '70'), open = text('height', '50');
  const env = boot([locked, open], { rules: [WO(['weight']),
    { type: 'constraint', fields: ['weight'], assert: '[weight]<[height]', blockSave: 'hard', lockReads: { weight: ['height'] } }] },
    { jQuery: true, hold: true });
  open.value = '40';
  check('before the base is taken a field read is not compared', save(env) === 'saved', env.win._alerts);
  env.flush();
  check('... the base taken then is the value it holds', save(env) === 'saved', env.win._alerts);
  open.value = '30';
  check('... and a change after it holds the save', save(env) === 'held');
}
{
  // a rule that reads a checkbox: each option is read by its code (review W3-6)
  const locked = text('weight', '120');
  const mirror = makeEl('input'); mirror.name = '__chk__pregnant_RC_1'; mirror.type = 'hidden'; mirror.value = '';
  const box = makeEl('input'); box.id = 'id-__chk__pregnant_RC_1'; box.name = '__chkn__pregnant'; box.type = 'checkbox';
  const env = boot([locked, mirror, box], { rules: [WO(['weight']),
    { type: 'constraint', fields: ['weight'], assert: "[weight]<100 or [pregnant(1)]='0'", blockSave: 'hard', lockReads: { weight: ['pregnant(1)', 'pregnant(2)'] } }] });
  check('checkbox read: nothing ticked since the page opened, the save goes through', save(env) === 'saved', env.win._alerts);
  mirror.value = '1'; box.checked = true; box.fire('click'); mirror.fire('change');
  check('checkbox read: ... ticking the option that breaks the rule holds it', save(env) === 'held', env.win._alerts);
  mirror.value = ''; box.checked = false; box.fire('click'); mirror.fire('change');
  check('checkbox read: ... and unticking it lets the save through', save(env) === 'saved', env.win._alerts);
}
{
  // a field the rule reads that this page does not carry (another page of a survey) is never compared
  const locked = text('weight', '70'), open = text('height', '50');
  const env = boot([locked, open], { rules: [WO(['weight']),
    { type: 'constraint', fields: ['weight'], assert: '[weight]<[height]', blockSave: 'hard', lockReads: { weight: ['height', 'elsewhere'] } }] });
  check('a read field this page does not carry does not hold the save', save(env) === 'saved', env.win._alerts);
  open.value = '40'; open.fire('change');
  check('... while one it carries still does once changed', save(env) === 'held');
}
{
  // an input added after the form opened has no value to compare with; one removed has none to compare
  const locked = text('weight', '70'), open = text('height', '50');
  const env = boot([locked, open], { rules: [WO(['weight']),
    { type: 'constraint', fields: ['weight'], assert: '[weight]<50', blockSave: 'hard', lockReads: { weight: ['height', 'later'] } }] });
  const later = text('later', 'x');
  env.allEls.push(later);
  check('a read field whose input appears after the form opened is not compared', save(env) === 'saved', env.win._alerts);
  open.value = '40'; open.fire('change');
  env.allEls.splice(env.allEls.indexOf(open), 1);
  check('a read field whose input is gone at the save is not compared', save(env) === 'saved', env.win._alerts);
}

// ---- 10) roles that could not be read (review M8) --------------------------------------
{
  const f = text('rv', 'x');
  const env = boot([f], { rules: [WO(['rv'], { writeonceRolesUnread: true })] });
  check('roles that could not be read: locked, and the note says why', f.readOnly === true
    && note(env, 'rv') === '&#128274; ' + DEFAULT + ' Your user role could not be checked, so the field is locked for now.', note(env, 'rv'));
}

// ---- 11) a dropdown whose saved code is no longer a choice (review L9) ------------------
{
  const s = select('arm2', ['', '1', '2'], '');
  const env = boot([s], { rules: [WO(['arm2'], { writeonceSaved: { arm2: '9' } })] });
  check('a dropdown whose saved code is no longer a choice gets it back as an option', s.options.some((o) => o.value === '9') && s.value === '9', s.value);
  check('... the save goes through, keeping it', save(env) === 'saved' && s.value === '9', s.value);
  check('... and test() calls it the saved value', env.NS.validators.arm2.test() === true);
}

// ---- 12) the widgets REDCap builds around a field (review L10, live sheet) -------------
{
  const r = radioField('grp', ['1', '2'], '1');
  r.buttons.forEach((b, i) => { b.id = 'opt-grp_' + (i + 1); });
  const enh = makeEl('div'); enh.className = 'enhancedchoice'; enh._row = 'grp-tr';
  const lab = makeEl('label'); lab.setAttribute('for', 'opt-grp_1'); enh.appendChild(lab);
  const enh2 = makeEl('div'); enh2.className = 'enhancedchoice'; enh2._row = 'grp-tr';
  const lab2 = makeEl('label'); lab2.setAttribute('comps', 'grp,code,2'); enh2.appendChild(lab2);
  // an embedded field in the same row keeps its own enhanced choices and buttons (review W2-7)
  const other = makeEl('div'); other.className = 'enhancedchoice'; other._row = 'grp-tr';
  const lab3 = makeEl('label'); lab3.setAttribute('for', 'opt-emb_1'); other.appendChild(lab3);
  const embReset = makeEl('a'); embReset.setAttribute('onclick', "radioResetVal('emb','form');return false;"); embReset._row = 'grp-tr'; embReset.style.display = 'inline';
  const embToday = makeEl('button'); embToday.setAttribute('onclick', "setToday('emb_date','ymd');"); embToday._row = 'grp-tr'; embToday.style.display = 'inline';
  boot(r.els.concat([enh, enh2, other, embReset, embToday]), { rules: [WO(['grp'])] });
  check('the enhanced radio buttons take no clicks', enh.style.pointerEvents === 'none' && enh2.style.pointerEvents === 'none');
  check('... an embedded field\'s enhanced choice in the same row still does', other.style.pointerEvents !== 'none');
  check('... and its reset link and Today button stay', embReset.style.display === 'inline' && embToday.style.display === 'inline');
}
{
  const s = select('site2', ['', '1', '2'], '2');
  const env = boot([s], { rules: [WO(['site2'])] });
  const ac = makeEl('input'); ac.id = 'rc-ac-input_site2'; ac.value = '2'; env.allEls.push(ac);   // built after the lock
  save(env);
  check('an autocomplete box built after the lock is read-only by the time the save is checked', ac.readOnly === true);
}
{
  const d = text('vd', '2026-01-01'); d._row = 'vd-tr';
  const today = makeEl('button'); today.setAttribute('onclick', "setToday('vd','ymd');"); today._row = 'vd-tr'; today.style.display = 'inline';
  const now = makeEl('button'); now.setAttribute('onclick', "setNowTime('vd');"); now._row = 'vd-tr'; now.style.display = 'inline';
  boot([d, today, now], { rules: [WO(['vd'])] });
  check("REDCap's Today and Now buttons are hidden", today.style.display === 'none' && now.style.display === 'none');
}

// ---- 12b) the date picker is set once, not at every undo (review W2-16) ---------------
{
  const dt = text('vd2', '01-03-2026'); dt.className = 'x hasDatepicker';
  const env = boot([dt], { rules: [WO(['vd2'])] }, { jQuery: true });
  for (const v of ['02-03-2026', '03-03-2026', '04-03-2026']) { dt.value = v; dt.fire('change'); }
  save(env);
  const opts = env.calls.filter((c) => c[0] === 'datepicker' && c[2] === 'option').length;
  check('the date picker\'s option is set once, whatever the undos (got ' + opts + ')', opts === 1 && dt.value === '01-03-2026');
}

// ---- 12c) saved values that could not be read (review W2-11) ---------------------------
{
  const f = text('vu', 'x');
  const env = boot([f], { rules: [WO(['vu'], { writeonceValuesUnread: true })] });
  check('saved values that could not be read lock nothing', f.readOnly === false);
  check('... and the note says so', /could not be read, so this field is not locked here/.test(note(env, 'vu')), note(env, 'vu'));
  f.value = 'y'; f.fire('change');
  check('... a change stays, and the save goes through', f.value === 'y' && save(env) === 'saved');
  const g = text('vs', 'x');
  const e2 = boot([g], { context: 'survey', rules: [WO(['vs'], { writeonceValuesUnread: true })] });
  check('... a survey respondent sees no note', note(e2, 'vs') === '' && g.readOnly === false);
}

// ---- 12d) every mode's item on a locked field exists whichever binds first (review W2-15) ---
{
  const stub = { obj: { ajax() { return { then() {} }; } } };
  const cases = [
    ['check', { type: 'single', fields: ['f'], algorithm: 'luhn', blockSave: 'hard' }],
    ['window', { type: 'window', fields: ['f'], windowFrom: '[g]', windowFromOp: ['ref', 'g', null], windowLo: 0, windowHi: 1, windowUnit: 'days',
                 dateType: 'date', dateFormat: 'ymd', fromType: 'date', fromFormat: 'ymd', blockSave: 'hard' }],
    ['range', { type: 'range', fields: ['f'], rangeHardLo: '0', rangeHardHi: '1' }],
    ['delta', { type: 'delta', fields: ['f'], deltaVs: '[g]', deltaVsOp: ['ref', 'g', null], deltaVsLabel: '[g]', deltaMax: '1',
                deltaText: 'a change of at most 1', deltaGroup: '[g] both', blockSave: 'hard' }],
    ['required', { type: 'required', fields: ['f'], when: "[g]='x'", blockSave: 'hard' }],
    ['unique', { type: 'unique', fields: ['f'], blockSave: 'hard' }],
    ['exists', { type: 'exists', fields: ['f'], blockSave: 'hard' }],
  ];
  for (const [mode, rule] of cases) {
    const f = text('f', '2026-01-05'), g = text('g', '2026-01-01');
    const env = boot([f, g], { jsmoName: 'EMStub.UV', rules: [WO(['f']), Object.assign({ lockReads: { f: ['g'] } }, rule)] }, { stub });
    check(mode + ': a rule on a box the lock made read-only still gets its guard item', f.readOnly === true
      && env.NS.guard.items.some((it) => it.__qridFieldName === 'f' && it.__qridMode === mode), env.NS.guard.items.map((it) => it.__qridMode));
  }
}
{
  // a check rule with a "when" on another field: lockReads keyed by the mode ("check"), not the type ("single") (J1)
  const code = text('code', '1234'), flag = text('flag', '0');
  const env = boot([code, flag], { rules: [WO(['code']),
    { type: 'single', fields: ['code'], algorithm: 'luhn', when: "[flag]='1'", blockSave: 'hard', lockReads: { code: ['flag'] } }] });
  check('check mode: the save goes through while the rule is off', save(env) === 'saved', env.win._alerts);
  flag.value = '1'; flag.fire('change');
  check('check mode: ... and is held once the other field turns it on', save(env) === 'held', env.win._alerts);
}

// ---- 13) every guard item names its mode --------------------------------------------
{
  const src = require('fs').readFileSync(path.join(__dirname, '..', 'js', 'engine.js'), 'utf8');
  const used = new Set([...src.matchAll(/QRID_registerBlocker\([^;]*,\s*"([a-z]+)"\);/g)].map((m) => m[1]));
  // @UVJUSTIFY holds no save of its own: it only lifts the hold of the rules it stands for.
  const modes = require('../php/modes.json').modes.map((m) => m.mode);
  const holders = modes.filter((m) => m !== 'justify');
  check('every guard item names a mode the registry knows, and every mode that can hold a save has one', [...used].every((m) => holders.includes(m)) && holders.every((m) => used.has(m)), [...used]);
}

console.log('writeonce_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
