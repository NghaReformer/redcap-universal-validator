/*
 * silent_change_dom_js.cjs — two live-found defects (pid 151, REDCap 17.0.6).
 *
 * 1. A value REDCap writes from script (a calc result, a slider's number)
 *    raises no input/change/blur event. A rule on that field, or a "when"
 *    gate reading it, kept judging the old value. The when-registry now polls
 *    its bound value elements and announces a difference nobody announced.
 * 2. The save dialog named a blocked field by its code ("uw_live") instead of
 *    its label: QRID_registerBlocker asked the plain guard object for a label
 *    and overwrote the one its factory had read from the field's row.
 * 3. The save dialog joined the labels with ", ", so a label holding a comma
 *    read as two fields (pid 149, 2.3.0-rc.2). It lists one label a line.
 *
 * Run:  node tests/silent_change_dom_js.cjs
 */
'use strict';
const path = require('path');
const PAGE_DEFAULTS = require('./page_defaults.json').defaults;

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), id: '', name: '', value: '', innerHTML: '', className: '',
    type: '', checked: false,
    style: { set cssText(v) { this._css = v; const m = /display:\s*([a-z]+)/.exec(v); this.display = m ? m[1] : ''; },
             get cssText() { return this._css || ''; } },
    _attrs: {}, children: [], parentNode: null, readOnly: false, disabled: false,
    _handlers: {}, _row: null,
    setAttribute(k, v) { this._attrs[k] = String(v); if (k === 'id') this.id = String(v); },
    getAttribute(k) { return (k in this._attrs) ? this._attrs[k] : (k === 'id' ? (this.id || null) : null); },
    removeAttribute(k) { delete this._attrs[k]; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn.call(this, ev || {})); },
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
    insertBefore(node, ref) {
      node.parentNode = this;
      const i = ref ? this.children.indexOf(ref) : -1;
      if (i >= 0) this.children.splice(i, 0, node); else this.children.push(node);
      return node;
    },
    closest(sel) { return sel === 'tr' ? this._row : null; },
    focus() { this._focused = true; },
    get nextSibling() {
      const i = this.parentNode ? this.parentNode.children.indexOf(this) : -1;
      return i >= 0 ? (this.parentNode.children[i + 1] || null) : null;
    },
    get firstChild() { return this.children[0] || null; },
  };
}

let intervals = [];
function boot(els, config) {
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  intervals = [];
  global.setInterval = (fn) => { intervals.push(fn); return { unref() {} }; };
  const allEls = [];
  const body = makeEl('body');
  body.contains = (el) => allEls.indexOf(el) >= 0;
  const holders = {};
  for (const el of els) {
    const holder = makeEl('div');
    holder.appendChild(el);
    body.appendChild(holder);
    allEls.push(el, holder);
    holders[el.name] = holder;
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
    INSPIRE_VALIDATOR_CONFIG: Object.assign({ singleFields: [], pooledFields: [] }, PAGE_DEFAULTS, config),
  };
  global.document = doc; global.window = win;
  require(enginePath);
  return { doc, win, holders, allEls, NS: win.INSPIREUniversalValidator };
}
function tick() { intervals.slice().forEach((fn) => fn()); }
function nMsg(env, field) {
  const kids = env.holders[field].children;
  for (let i = 1; i < kids.length; i++) if (kids[i].id && /-n$/.test(kids[i].id)) return kids[i];
  return null;
}
function shown(msg) { return !!msg && msg.style.display !== 'none' && msg.innerHTML !== ''; }
function numEl(name, value) { const e = makeEl('input'); e.name = name; e.value = value; return e; }
function submitEv() {
  return { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
}

const BMI = { type: 'range', fields: ['bmi'], rangeSoftLo: '16', rangeSoftHi: '35', rangeHardLo: '10', rangeHardHi: '60',
  rangeSoftText: '16 to 35', rangeHardText: '10 to 60', rangeComputed: true };

// ---- 1) a calc result written without an event is judged -----------------------
{
  const bmi = numEl('bmi', ''); bmi.className = 'rci-calc'; bmi.readOnly = true;
  const env = boot([bmi], { rules: [Object.assign({}, BMI)] });
  const msg = nMsg(env, 'bmi');
  check('calc: blank says nothing', !shown(msg));
  check('calc: the page polls for silent changes', intervals.length > 0);
  bmi.value = '71.1';                       // REDCap's calculate(): no event
  check('calc: nothing is judged before the poll runs', !shown(msg));
  tick();
  check('calc: 71.1 is judged after the poll', shown(msg) && /above the plausible range/.test(msg.innerHTML));
  bmi.value = '22';
  tick();
  check('calc: back inside the limits, the note goes away', !shown(msg));
  bmi.value = '5';
  tick();
  check('calc: 5 is below the hard limit', shown(msg) && /below the plausible range/.test(msg.innerHTML));
}

// ---- 2) a value an event already announced is not announced twice ---------------
{
  const hb = numEl('hb', '14');
  const rule = { type: 'range', fields: ['hb'], rangeSoftLo: '12', rangeSoftHi: '17.5', rangeSoftText: '12 to 17.5' };
  const env = boot([hb], { rules: [rule] });
  const msg = nMsg(env, 'hb');
  hb.value = '20'; hb.fire('change');
  const first = msg.innerHTML;
  check('event: judged at once', /higher than usual/.test(first));
  // the poll must see the event's value as already known
  msg.innerHTML = 'MARK';
  tick();
  check('event: the poll does not re-judge a value an event announced', msg.innerHTML === 'MARK');
  hb.value = '21';                           // silent again
  tick();
  check('event then silent: the new value is judged', msg.innerHTML !== 'MARK' && /higher than usual/.test(msg.innerHTML));
}

// ---- 3) REDCap's Today button: the field keeps the focus, no change fires --------
{
  const d = makeEl('input'); d.name = 'collected'; d.value = '2026-10-11';
  const env = boot([d], { clock: { today: '2026-10-10', now: '2026-10-10 12:00:00' },
    rules: [{ type: 'window', fields: ['collected'], windowNotFuture: true, dateType: 'date', dateFormat: 'ymd' }] });
  const msg = env.holders.collected.children.find((c) => c.id && /-w$/.test(c.id));
  check('Today: tomorrow is refused', /after today/.test(msg.innerHTML));
  env.doc.activeElement = d;                // the button focuses the field, then writes it
  d.value = '2026-10-10';
  tick();
  check('Today: the written date is judged without leaving the field', /OK/.test(msg.innerHTML));
}

// ---- 3b) typing is judged by its own events, never half a number -------------------
{
  const hb = numEl('hb', '14');
  const rule = { type: 'range', fields: ['hb'], rangeSoftLo: '12', rangeSoftHi: '17.5', rangeSoftText: '12 to 17.5' };
  const env = boot([hb], { rules: [rule] });
  const msg = nMsg(env, 'hb');
  env.doc.activeElement = hb;
  hb.value = '2'; hb.fire('input');
  tick();
  check('typing: the poll adds nothing to what the input event heard', !shown(msg));
  hb.value = '20'; hb.fire('input'); hb.fire('change');
  check('typing: the change event judges', shown(msg));
}

// ---- 4) the save dialog names a blocked field by its label ------------------------
{
  const live = numEl('live', '30');
  const row = { querySelector(sel) { return /labelrc/.test(sel) ? { textContent: '  Visit 21 to 35 days\n after the anchor  ' } : null; } };
  live._row = row;
  const rule = { type: 'range', fields: ['live'], rangeHardLo: '40', rangeHardHi: '250', rangeHardText: '40 to 250', rangeHardBlock: 'hard' };
  const env = boot([live], { rules: [rule] });
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('label: the save is held', env.win._alerts.length === 1 && ev._prevented);
  check('label: the dialog names the label', /Visit 21 to 35 days after the anchor/.test(env.win._alerts[0] || ''));
  check('label: the dialog does not name the field code', !/\blive\b/.test(env.win._alerts[0] || ''));
}

// ---- 5) the save dialog lists one label a line ------------------------------------
// Live on rc.2 (pid 149) "Arm circumference (cm), Weight (kg), a span past the
// reference" named two fields and read as three: a label may hold a comma.
{
  const rowOf = (t) => ({ querySelector(sel) { return /labelrc/.test(sel) ? { textContent: t } : null; } });
  const fields = () => {
    const arm = numEl('arm', '30'), span = numEl('span', '30');
    arm._row = rowOf('Arm circumference (cm)');
    span._row = rowOf('Weight (kg), a span\n past the reference');
    return [arm, span];
  };
  const rule = (f, block) => ({ type: 'range', fields: [f], rangeHardLo: '40', rangeHardHi: '250', rangeHardText: '40 to 250', rangeHardBlock: block });
  let env = boot(fields(), { rules: [rule('arm', 'confirm'), rule('span', 'confirm')] });
  let ev = submitEv(); env.doc.fire('submit', ev);
  const asked = env.win._confirms[0] || '';
  check('confirm: one label a line, a comma inside a label kept (got ' + JSON.stringify(asked) + ')', env.win._confirms.length === 1
    && asked === 'Validation FAILED for:\n• Arm circumference (cm)\n• Weight (kg), a span past the reference\n\nSave anyway?'
    && ev._prevented);
  env = boot(fields(), { rules: [rule('arm', 'hard'), rule('span', 'confirm')] });
  ev = submitEv(); env.doc.fire('submit', ev);
  check('hard: the alert lists them the same way (got ' + JSON.stringify(env.win._alerts[0] || '') + ')', ev._prevented
    && env.win._alerts[0] === 'Cannot save yet — please fix the flagged field(s):\n• Arm circumference (cm)\n• Weight (kg), a span past the reference');
}

// ---- 6) Save straight after a silent change, before the poll runs ----------------
// REQ-3, AST-7, VAL-6 (review 2026-10-21): the person types, clicks Save, the
// change makes REDCap's calculate() rewrite a calc with no event, and the
// click reaches the guard before the 400 ms poll. Every guard item re-judges
// itself at save, so the verdict is the one the page now shows.
{
  const calc = (name, value) => { const e = numEl(name, value); e.className = 'rci-calc'; e.readOnly = true; return e; };
  const held = (env) => { const ev = submitEv(); env.doc.fire('submit', ev); return ev._prevented; };

  // @UVREQUIRED with a "when" over a calc
  let bmi = calc('bmi', '25'), note = numEl('note', '');
  let env = boot([bmi, note], { rules: [{ type: 'required', fields: ['note'], when: '[bmi]>30', blockSave: 'hard' }] });
  check('required: BMI 25, the note is not required, Save goes through', !held(env));
  bmi.value = '35.9';                                    // calculate(): no event, no tick
  check('required: BMI now 35.9, Save at once is held (REQ-3)', held(env) && /note/.test(env.win._alerts.join('\n')));
  note.value = 'smoker';                                 // filled from script, no event
  check('required: the note filled silently, Save at once goes through', !held(env));

  // @UVASSERT over a calc
  const dose = numEl('dose', '5'), maxd = calc('maxd', '10');
  env = boot([dose, maxd], { rules: [{ type: 'constraint', fields: ['dose'], assert: '[dose]<=[maxd]', blockSave: 'hard' }] });
  check('constraint: dose 5 <= 10, Save goes through', !held(env));
  maxd.value = '3';
  check('constraint: the maximum is now 3, Save at once is held (AST-7)', held(env));

  // @UVALIDATE single and pooled behind a "when" whose controller changed by script
  const sid = numEl('sid', 'FC1-0589D'), flag = numEl('flag', '0');
  env = boot([sid, flag], { rules: [{ type: 'single', fields: ['sid'], when: "[flag]='1'", blockSave: 'hard' }] });
  check('single: the rule is off, Save goes through', !held(env));
  flag.value = '1';
  check('single: the rule now applies to a bad ID, Save at once is held (VAL-6)', held(env));
  const pool = numEl('pool', 'FC1-0589D'), flag2 = numEl('flag2', '0');
  env = boot([pool, flag2], { rules: [{ type: 'pooled', fields: ['pool'], when: "[flag2]='1'", blockSave: 'hard' }] });
  check('pooled: the rule is off, Save goes through', !held(env));
  flag2.value = '1';
  check('pooled: the rule now applies to a bad ID, Save at once is held (VAL-6)', held(env));
}

console.log('silent_change_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
