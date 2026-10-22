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
   records the widget calls. Interval callbacks are kept, so a test can run
   the silent-change poll by hand (poll()). */
function boot(els, config, opts) {
  opts = opts || {};
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  const allEls = [];
  const body = makeEl('body');
  const holders = {};
  const rows = {};
  for (const el of els) {
    let holder = el._row ? rows[el._row] : null;
    if (!holder) {
      holder = makeEl('div');
      if (el._row) { holder.id = el._row; rows[el._row] = holder; }
      body.appendChild(holder);
      allEls.push(holder);
    }
    holder.appendChild(el);
    allEls.push(el);
    for (const k of el.children) allEls.push(k);
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
  const calls = [];
  const win = {
    _alerts: [], _confirms: [], confirmAnswer: false,
    alert(m) { this._alerts.push(m); }, confirm(m) { this._confirms.push(m); return this.confirmAnswer; },
    INSPIRE_VALIDATOR_CONFIG: Object.assign({ singleFields: [], pooledFields: [] }, PAGE_DEFAULTS, config),
  };
  if (opts.jQuery) {
    const jq = function (sel) {
      const el = typeof sel === 'string' ? (sel[0] === '#' ? doc.getElementById(sel.slice(1)) : null) : sel;
      const w = { length: el ? 1 : 0, fn: null };
      w.on = function (events, handler) {
        for (const ev of events.split(' ')) if (el) el.addEventListener(ev.split('.')[0], handler);
        return w;
      };
      w.hasClass = (c) => !!el && (' ' + el.className + ' ').indexOf(' ' + c + ' ') >= 0;
      w.slider = (a) => { calls.push(['slider', el && el.id, a]); return w; };
      w.datepicker = (a) => { calls.push(['datepicker', el && el.name, a]); return w; };
      return w;
    };
    jq.fn = { on: true };
    win.jQuery = jq;
  }
  global.document = doc; global.window = win;
  const intervals = [];
  global.setInterval = (fn) => { intervals.push(fn); return { unref() {} }; };
  global.clearInterval = () => {};
  require(enginePath);
  return { doc, win, holders, allEls, calls, NS: win.INSPIREUniversalValidator, poll() { intervals.forEach((f) => f()); } };
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
  check('... with the default note under it', note(env, 'consent_date') === '\u{1F512} ' + DEFAULT || note(env, 'consent_date') === '&#128274; ' + DEFAULT,
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
  check('the date picker is disabled', env.calls.some((c) => c[0] === 'datepicker' && c[1] === 'visit_date' && c[2] === 'disable'), env.calls);
  check('... and both boxes are read-only', sl.readOnly === true && dt.readOnly === true);
  // a jQuery change (the date picker's own) reaches the lock through the jQuery listener
  dt.value = '02-03-2026'; dt.fire('change');
  check('a date picker change is undone', dt.value === '01-03-2026', dt.value);
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

console.log('writeonce_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
