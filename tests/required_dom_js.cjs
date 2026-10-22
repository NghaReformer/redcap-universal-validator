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
 *     never blocks,
 *   - a field whose row REDCap hides (branching logic, @HIDDEN) is not
 *     required, and doBranching() showing the row brings the notice back at
 *     once (REQ-1); an embedded field is judged by the row it sits in,
 *   - a @READONLY radio (options disabled, mirror enabled) or autocomplete
 *     dropdown never holds the save, asked again at save (REQ-2).
 *
 * Run:  node tests/required_dom_js.cjs
 */
'use strict';
const path = require('path');

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), nodeType: 1, id: '', name: '', value: '', innerHTML: '',
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

/* opts.rows: each field sits in a REDCap row, <tr id="<field>-tr"> (opts.rowOf
   puts a field in another field's row), and opts.hidden lists the rows hidden
   as REDCap's branching logic hides them. opts.before(win) runs before the
   engine loads, where REDCap defines its own functions. */
function boot(els, config, opts) {
  opts = opts || {};
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  const allEls = [];
  const body = makeEl('body');
  const holders = {};
  for (const el of els) {
    const rowName = opts.rows ? (opts.rowOf && opts.rowOf[el.name]) || el.name : null;
    let holder = rowName ? allEls.find((e) => e.id === rowName + '-tr') : null;
    if (!holder) {
      holder = makeEl(rowName ? 'tr' : 'div');
      if (rowName) holder.id = rowName + '-tr';
      if (rowName && (opts.hidden || []).indexOf(rowName) >= 0) holder.style.display = 'none';
      body.appendChild(holder);
      allEls.push(holder);
    }
    holder.appendChild(el);
    allEls.push(el);
    if (!holders[el.name]) holders[el.name] = holder;
  }
  for (const extra of (opts.extraRows || [])) {
    const tr = makeEl('tr'); tr.id = extra.id; if (extra.hidden) tr.style.display = 'none';
    body.appendChild(tr); allEls.push(tr);
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
  if (opts.before) opts.before(win);
  const origError = console.error;
  const consoleErrors = [];
  console.error = (m) => consoleErrors.push(String(m));
  try { require(enginePath); } finally { console.error = origError; }
  return { doc, win, holders, allEls, NS: win.INSPIREUniversalValidator, consoleErrors };
}
function rMsg(env, field) {
  const kids = env.holders[field].children;
  for (let i = 0; i < kids.length; i++) if (kids[i].id === 'uvalidate-msg-' + field + '-r') return kids[i];
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

// ---- 3b) text in the "when" ignores letter case unless caseSensitive:true ---
{
  const run = (extra) => {
    const phone = makeEl('input'); phone.name = 'phone'; phone.value = '';
    const consent = makeEl('input'); consent.name = 'consent'; consent.value = 'YES';
    const env = boot([phone, consent], { singleFields: [], pooledFields: [],
      rules: [Object.assign({ type: 'required', fields: ['phone'], when: "[consent]='yes'", blockSave: 'hard' }, extra)] });
    const ev = submitEv(); env.doc.fire('submit', ev);
    return { phone, ev };
  };
  let r = run({});
  check('required, case default: "YES" matches when [consent]=yes (blank trapped)',
    r.phone.getAttribute('aria-invalid') === 'true' && r.ev._prevented === true);
  r = run({ caseSensitive: true });
  check('required, caseSensitive:true: "YES" does not match (not required)',
    r.phone.getAttribute('aria-invalid') !== 'true' && r.ev._prevented === false);
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

// A saved value with a long run of inner spaces is read in linear time: the
// trim used to retry its end-anchored pattern from every space (1.6 seconds
// for this value, on every check and save).
{
  const notes = makeEl('textarea'); notes.name = 'notes'; notes.value = 'a' + ' '.repeat(50000) + 'a';
  const t0 = Date.now();
  const env = boot([notes], { singleFields: [], pooledFields: [], rules: [{ type: 'required', fields: ['notes'], blockSave: 'hard' }] });
  const filled = env.NS.validators.notes.test();
  const ms = Date.now() - t0;
  check('50,000 inner spaces: the page checks and test() answers in under 250 ms (took ' + ms + ' ms)', ms < 250 && filled === true);
}

// ---- 9) a field REDCap hides is not required (REQ-1) ------------------------
for (const mode of ['hard', 'confirm']) {
  const sex = makeEl('select'); sex.name = 'sex'; sex.value = '1';
  const preg = makeEl('input'); preg.name = 'preg'; preg.value = '';
  let branchingCalls = 0;
  const env = boot([sex, preg], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'required', fields: ['preg'], blockSave: mode }],
  }, { rows: true, hidden: ['preg'], before(w) { w.doBranching = function () { branchingCalls++; }; } });
  let asked = 0;
  env.win.confirm = () => { asked++; return false; };
  const msg = rMsg(env, 'preg');
  check(mode + ' hidden row: no notice', msg.style.display === 'none' && preg.getAttribute('aria-invalid') === null);
  let ev = submitEv(); env.doc.fire('submit', ev);
  check(mode + ' hidden row: the save goes through, nothing asked', ev._prevented === false && env.win._alerts.length === 0 && asked === 0);
  // REDCap shows the row in doBranching(), with no event on the field
  env.doc.getElementById('preg-tr').style.display = '';
  env.win.doBranching('sex');
  check(mode + ' shown by doBranching: wrapped, and REDCap\'s own still runs', branchingCalls === 1 && env.win.doBranching.__qridBranching === true);
  check(mode + ' shown by doBranching: notice at once', /blank/.test(msg.innerHTML) && preg.getAttribute('aria-invalid') === 'true');
  ev = submitEv(); env.doc.fire('submit', ev);
  check(mode + ' shown: the save is held', ev._prevented === true);
  env.doc.getElementById('preg-tr').style.display = 'none';
  env.win.doBranching('sex');
  check(mode + ' hidden again: notice cleared', msg.style.display === 'none' && preg.getAttribute('aria-invalid') === null);
  ev = submitEv(); env.doc.fire('submit', ev);
  check(mode + ' hidden again: the save goes through', ev._prevented === false);
  // shown with no doBranching call at all: the save-time recheck still sees it
  env.doc.getElementById('preg-tr').style.display = '';
  ev = submitEv(); env.doc.fire('submit', ev);
  check(mode + ' shown without doBranching: held at save', ev._prevented === true);
}
{
  // @HIDDEN hides the row the same way
  const ts = makeEl('input'); ts.name = 'ts'; ts.value = '';
  const env = boot([ts], { singleFields: [], pooledFields: [], rules: [{ type: 'required', fields: ['ts'], blockSave: 'hard' }] },
    { rows: true, hidden: ['ts'] });
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('@HIDDEN row: the save goes through', ev._prevented === false && env.win._alerts.length === 0);
}
{
  // an embedded field ({preg} in host's label) sits in the host's row; its own
  // row stays hidden, and it is still required while the host row is shown
  const preg = makeEl('input'); preg.name = 'preg'; preg.value = '';
  const env = boot([preg], { singleFields: [], pooledFields: [], rules: [{ type: 'required', fields: ['preg'], blockSave: 'hard' }] },
    { rows: true, rowOf: { preg: 'host' }, extraRows: [{ id: 'preg-tr', hidden: true }] });
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('embedded field in a shown row: still required', ev._prevented === true);
  check('embedded field: notice shown', /blank/.test(rMsg(env, 'preg').innerHTML));
}
{
  // a value rule on a hidden row never holds the save either
  const dose = makeEl('input'); dose.name = 'dose'; dose.value = '999';
  const env = boot([dose], { singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['dose'], assert: "[dose]<>'999'", blockSave: 'hard' }] },
    { rows: true, hidden: ['dose'] });
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('constraint on a hidden row: the save goes through', ev._prevented === false);
}

// ---- 10) @READONLY radio: the options are locked, the mirror is not (REQ-2) --
function radioField(name, value, disabled) {
  const mirror = makeEl('input'); mirror.name = name; mirror.type = 'text'; mirror.value = value;
  const o1 = makeEl('input'); o1.name = name + '___radio'; o1.type = 'radio'; o1.value = '1'; o1.disabled = disabled;
  const o0 = makeEl('input'); o0.name = name + '___radio'; o0.type = 'radio'; o0.value = '0'; o0.disabled = disabled;
  return [mirror, o1, o0];
}
{
  const els = radioField('elig', '', true);
  const env = boot(els, { singleFields: [], pooledFields: [], rules: [{ type: 'required', fields: ['elig'], blockSave: 'hard' }] });
  check('@READONLY radio: notice still shown', /blank/.test(rMsg(env, 'elig').innerHTML));
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('@READONLY radio: blank never holds the save', ev._prevented === false && env.win._alerts.length === 0);
}
{
  // REDCap applies @READONLY after the engine bound the field
  const els = radioField('elig', '', false);
  const env = boot(els, { singleFields: [], pooledFields: [], rules: [{ type: 'required', fields: ['elig'], blockSave: 'hard' }] });
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('radio, options enabled: held (control)', ev._prevented === true);
  els[1].disabled = true; els[2].disabled = true;
  ev = submitEv(); env.doc.fire('submit', ev);
  check('radio locked after bind: asked again at save, not held', ev._prevented === false);
}
{
  // the same test covers the other factories: a constraint on a locked radio
  const els = radioField('sev', '1', true);
  const env = boot(els, { singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['sev'], assert: "[sev]<>'1'", blockSave: 'hard' }] });
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('constraint on a @READONLY radio: never holds the save', ev._prevented === false);
}
{
  // an autocomplete dropdown: REDCap locks the text box that stands in for it
  const sel = makeEl('select'); sel.name = 'site'; sel.value = '';
  const ac = makeEl('input'); ac.id = 'rc-ac-input_site'; ac.disabled = true;
  const env = boot([sel, ac], { singleFields: [], pooledFields: [], rules: [{ type: 'required', fields: ['site'], blockSave: 'hard' }] });
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('@READONLY autocomplete dropdown: never holds the save', ev._prevented === false);
}

console.log(`required_dom_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
