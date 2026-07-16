/*
 * required_dom_js.cjs — the @UVREQUIRED required mode's DOM contract.
 *
 * Drives the real QRIDRequiredInit factory through the same DOM stub the other
 * *_dom tests use, and asserts:
 *   - a blank field shows the required notice (custom or generic message,
 *     aria-invalid) and a HARD rule traps the save,
 *   - filling the field CLEARS the notice (no green OK — required mode never
 *     judges the value) and allows the save,
 *   - whitespace-only input still counts as blank,
 *   - a "when" gate turns the requirement on/off LIVE from a referenced field,
 *   - required works on a dropdown (<select> anchor) and a radio-family field
 *     (hidden mirror + ___radio group wiring via the shared when-registry),
 *   - MODE COMPOSITION: @UVREQUIRED + a check rule on the SAME field keep
 *     independent block state — filling the field satisfies required but a bad
 *     check still blocks,
 *   - readonly fields show the notice but never trap the save,
 *   - two required rules with different "when" branch; both true -> conflict,
 *     never blocks.
 *
 * Run:  node tests/required_dom_js.cjs
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

function boot(els, config) {
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
  global.document = doc; global.window = win;
  const origError = console.error;
  const consoleErrors = [];
  console.error = (m) => consoleErrors.push(String(m));
  try { require(enginePath); } finally { console.error = origError; }
  return { doc, win, holders, allEls, NS: win.INSPIREUniversalValidator, consoleErrors };
}
function rMsg(env, field) {
  const kids = env.holders[field].children;
  for (let i = 1; i < kids.length; i++) if (kids[i].getAttribute && kids[i].id && /^uvalidate-msg-/.test(kids[i].id)) return kids[i];
  return kids[1];
}
function submitEv() {
  return { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
}

// ---- 1) unconditional required on a text field (hard block) ----------------
{
  const phone = makeEl('input'); phone.name = 'phone'; phone.value = '';
  const env = boot([phone], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'required', fields: ['phone'], message: 'Phone number is needed', blockSave: 'hard' }],
  });
  const msg = rMsg(env, 'phone');
  check('blank: custom message shown', /Phone number is needed/.test(msg.innerHTML));
  check('blank: aria-invalid set', phone.getAttribute('aria-invalid') === 'true');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('blank: hard block traps the save', ev._prevented === true);

  phone.value = '677001122';
  phone.fire('change');
  check('filled: notice CLEARED (no green OK)', msg.style.display === 'none');
  check('filled: aria-invalid removed', phone.getAttribute('aria-invalid') === null);
  ev = submitEv(); env.doc.fire('submit', ev);
  check('filled: save allowed', ev._prevented === false);

  phone.value = '   ';
  phone.fire('change');
  check('whitespace-only counts as blank', phone.getAttribute('aria-invalid') === 'true');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('whitespace-only: save trapped again', ev._prevented === true);
}

// ---- 2) generic message names the condition (staff form) -------------------
{
  const phone = makeEl('input'); phone.name = 'phone'; phone.value = '';
  const consent = makeEl('select'); consent.name = 'consent'; consent.value = '1';
  const env = boot([phone, consent], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'required', fields: ['phone'], when: "[consent]='1'" }],
  });
  const msg = rMsg(env, 'phone');
  check('generic message says blank + names the condition',
    /must not be left blank/.test(msg.innerHTML) && /consent/.test(msg.innerHTML));
}

// ---- 3) conditional requirement flips LIVE from a referenced dropdown ------
{
  const phone = makeEl('input'); phone.name = 'phone'; phone.value = '';
  const consent = makeEl('select'); consent.name = 'consent'; consent.value = '0';
  const env = boot([phone, consent], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'required', fields: ['phone'], when: "[consent]='1'", blockSave: 'hard' }],
  });
  const msg = rMsg(env, 'phone');
  check('when false: no requirement, blank tolerated', msg.style.display === 'none');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('when false: save never trapped', ev._prevented === false);

  consent.value = '1';
  consent.fire('change');
  check('when flips true: requirement appears live', phone.getAttribute('aria-invalid') === 'true');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('when true + blank: save trapped', ev._prevented === true);

  consent.value = '0';
  consent.fire('change');
  check('when flips back false: notice cleared', msg.style.display === 'none');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('when false again: save allowed', ev._prevented === false);
}

// ---- 4) required on a dropdown (<select> anchor) ----------------------------
{
  const site = makeEl('select'); site.name = 'site'; site.value = '';
  const env = boot([site], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'required', fields: ['site'], blockSave: 'hard' }],
  });
  const msg = rMsg(env, 'site');
  check('dropdown blank: flagged', site.getAttribute('aria-invalid') === 'true' && /blank/.test(msg.innerHTML));
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('dropdown blank: blocked', ev._prevented === true);
  site.value = '3';
  site.fire('change');
  check('dropdown picked: cleared', msg.style.display === 'none');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('dropdown picked: save allowed', ev._prevented === false);
}

// ---- 5) required on a radio-family field (hidden mirror + ___radio group) --
{
  const hidden = makeEl('input'); hidden.name = 'sex'; hidden.type = 'hidden'; hidden.value = '';
  const r1 = makeEl('input'); r1.name = 'sex___radio'; r1.type = 'radio'; r1.value = '1';
  const r2 = makeEl('input'); r2.name = 'sex___radio'; r2.type = 'radio'; r2.value = '2';
  const env = boot([hidden, r1, r2], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'required', fields: ['sex'], blockSave: 'hard' }],
  });
  const msg = rMsg(env, 'sex');
  check('radio unset: flagged on the hidden mirror anchor', /blank/.test(msg.innerHTML));
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('radio unset: blocked', ev._prevented === true);
  // REDCap's own JS writes the hidden input, then the clicked radio fires
  hidden.value = '1';
  r1.checked = true;
  r1.fire('click');
  check('radio picked: cleared via the ___radio wiring', msg.style.display === 'none');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('radio picked: save allowed', ev._prevented === false);
}

// ---- 6) MODE COMPOSITION: @UVREQUIRED + check rule on the SAME field --------
{
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = '';
  const env = boot([pid], {
    singleFields: [], pooledFields: [],
    rules: [
      { type: 'single', fields: ['pid'], algorithm: 'none', idPattern: '[A-Z]{2}[0-9]{4}', blockSave: 'hard' },
      { type: 'required', fields: ['pid'], blockSave: 'hard' },
    ],
  });
  check('compose: no false duplicate notice', !env.allEls.some((e) => e.id === 'uvalidate-config-errors'));
  // blank: required blocks (check is inert on blank)
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('compose blank: required blocks', ev._prevented === true);
  // filled but bad pattern: required satisfied, but the CHECK must still block
  pid.value = 'nope';
  pid.fire('change');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('compose filled-bad: satisfied required does NOT clear the check block', ev._prevented === true);
  // filled and valid: both pass
  pid.value = 'AB1234';
  pid.fire('change');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('compose filled-good: both pass, save allowed', ev._prevented === false);
}

// ---- 7) readonly field: notice shows, save never trapped --------------------
{
  const ro = makeEl('input'); ro.name = 'ro'; ro.value = ''; ro.readOnly = true;
  const env = boot([ro], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'required', fields: ['ro'], blockSave: 'hard' }],
  });
  check('readonly blank: notice still shown', /blank/.test(rMsg(env, 'ro').innerHTML));
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('readonly blank: save never trapped (UX-003)', ev._prevented === false);
}

// ---- 8) branched requirements: different "when" branch; both true conflict --
{
  const t = makeEl('select'); t.name = 't'; t.value = '1';
  const x = makeEl('input'); x.name = 'x'; x.value = '';
  const env = boot([t, x], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'required', fields: ['x'],
      branches: [
        { when: "[t]='1'", blockSave: 'hard', message: 'Needed for type 1' },
        { when: "[t]<>'2'", blockSave: 'off', message: 'Suggested otherwise' },
      ] }],
  });
  const msg = rMsg(env, 'x');
  // t='1' satisfies BOTH conditions -> conflict: shown, never blocks
  check('both branch conditions true -> conflict notice', /Validation conflict/.test(msg.innerHTML));
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('conflict never blocks', ev._prevented === false);
  t.value = '3'; // only the second branch ([t]<>'2') is true now
  t.fire('change');
  check('single active branch: its message + mode apply', /Suggested otherwise/.test(msg.innerHTML));
  ev = submitEv(); env.doc.fire('submit', ev);
  check('active branch blockSave=off: no block', ev._prevented === false);
}

console.log(`required_dom_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
