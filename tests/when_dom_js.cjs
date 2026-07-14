/*
 * when_dom_js.cjs — the "when" condition gate's field-facing DOM contract.
 *
 * Drives the real factories through the a11y_dom_js.cjs stub (extended with
 * select/radio/checkbox support) and asserts the runtime gate behavior:
 *   - condition false  -> no message, no aria-invalid, and a HARD block never
 *     traps the save (the rule is inert),
 *   - flipping a referenced dropdown/radio/checkbox re-checks live and
 *     restores full validation (including the hard block),
 *   - true -> false clears a previously shown verdict,
 *   - off-page refs resolve from the server-baked whenValues snapshot,
 *   - an unparseable condition fails OPEN (skips validation, never blocks),
 *   - the pooled factory honors the same gate,
 *   - a rule without "when" is untouched (regression).
 *
 * The evaluator itself is parity-locked by tests/when_js.cjs + when_php.php;
 * this file only tests the DOM wiring around it.
 *
 * Run:  node tests/when_dom_js.cjs
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

/* Boot a fresh engine instance over a fresh DOM. els: pre-existing page
   elements (inputs, selects, ...); config: the injected module config. */
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
  return { doc, win, holders, NS: win.INSPIREUniversalValidator, consoleErrors };
}
function msgOf(env, field) { return env.holders[field].children[1]; }
function submitEv() {
  return { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
}

const BAD_ID = '0ABC00001X'; // wrong iso7064_mod37_36 check character

// ---- 1) dropdown ref gates a single-field HARD rule ------------------------
{
  const sid = makeEl('input'); sid.name = 'sid'; sid.value = BAD_ID;
  const stype = makeEl('select'); stype.name = 'stype'; stype.value = '1';
  const env = boot([sid, stype], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'single', fields: ['sid'], algorithm: 'iso7064_mod37_36',
              blockSave: 'hard', when: "[stype]='2'" }],
  });
  const msg = msgOf(env, 'sid');
  check('gate false: no message shown for an invalid value', msg.style.display === 'none');
  check('gate false: no aria-invalid', sid.getAttribute('aria-invalid') === null);
  check('gate false: field not flagged invalid', sid.__qridInvalid === false);
  let ev = submitEv();
  env.doc.fire('submit', ev);
  check('gate false: hard block does NOT trap the save', ev._prevented === false);
  check('gate false: no block dialog', env.win._alerts.length === 0);

  // flip the dropdown -> condition true -> full validation returns live
  // (verdicts are asserted via innerHTML/__qridInvalid — the engine writes
  // style.cssText, which the stub does not parse back into style.display)
  stype.value = '2';
  stype.fire('change');
  check('gate flip to true: verdict appears', /check character/i.test(msg.innerHTML) && sid.__qridInvalid === true);
  check('gate flip to true: aria-invalid set', sid.getAttribute('aria-invalid') === 'true');
  ev = submitEv();
  env.doc.fire('submit', ev);
  check('gate flip to true: hard block now traps the save', ev._prevented === true);

  // flip back -> previously shown verdict is cleared, save allowed again
  stype.value = '1';
  stype.fire('change');
  check('gate flip to false: verdict cleared', msg.style.display === 'none');
  check('gate flip to false: aria-invalid removed', sid.getAttribute('aria-invalid') === null);
  ev = submitEv();
  env.doc.fire('submit', ev);
  check('gate flip to false: save allowed again', ev._prevented === false);
}

// ---- 2) radio-family ref (hidden input + ___radio group) -------------------
{
  const sid = makeEl('input'); sid.name = 'sid'; sid.value = BAD_ID;
  const hidden = makeEl('input'); hidden.name = 'sex'; hidden.type = 'hidden'; hidden.value = '';
  const r1 = makeEl('input'); r1.name = 'sex___radio'; r1.type = 'radio'; r1.value = '1';
  const r2 = makeEl('input'); r2.name = 'sex___radio'; r2.type = 'radio'; r2.value = '2';
  const env = boot([sid, hidden, r1, r2], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'single', fields: ['sid'], algorithm: 'iso7064_mod37_36',
              blockSave: 'hard', when: "[sex]='1'" }],
  });
  const msg = msgOf(env, 'sid');
  check('radio unset: rule inert', msg.style.display === 'none');
  // REDCap's own JS writes the hidden input, then the clicked radio fires
  hidden.value = '1';
  r1.checked = true;
  r1.fire('click');
  check('radio pick re-checks via the hidden input', /check character/i.test(msg.innerHTML));
  check('radio pick: field flagged', sid.__qridInvalid === true);
}

// ---- 3) checkbox ref (__chk__<field>_RC_<code>) ----------------------------
{
  const sid = makeEl('input'); sid.name = 'sid'; sid.value = BAD_ID;
  const cb = makeEl('input'); cb.name = '__chk__consent_RC_1'; cb.type = 'checkbox'; cb.checked = false;
  const env = boot([sid, cb], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'single', fields: ['sid'], algorithm: 'iso7064_mod37_36',
              blockSave: 'hard', when: "[consent(1)]='1'" }],
  });
  const msg = msgOf(env, 'sid');
  check('checkbox unchecked: rule inert', msg.style.display === 'none');
  cb.checked = true;
  cb.fire('click');
  check('checkbox tick re-checks live', /check character/i.test(msg.innerHTML));
  cb.checked = false;
  cb.fire('change');
  check('checkbox untick clears the verdict', msg.style.display === 'none');
}

// ---- 4) off-page ref resolves from the whenValues snapshot -----------------
{
  const sid = makeEl('input'); sid.name = 'sid'; sid.value = BAD_ID;
  const env = boot([sid], {
    singleFields: [], pooledFields: [],
    whenValues: { elig: 'yes', consent: { '0': '1', '1': '0' } },
    rules: [{ type: 'single', fields: ['sid'], algorithm: 'iso7064_mod37_36',
              blockSave: 'hard', when: "[elig]='yes' and [consent(0)]='1'" }],
  });
  const msg = msgOf(env, 'sid');
  check('snapshot refs (scalar + checkbox map) evaluate true', /check character/i.test(msg.innerHTML));
}
{
  const sid = makeEl('input'); sid.name = 'sid'; sid.value = BAD_ID;
  const env = boot([sid], {
    singleFields: [], pooledFields: [],
    whenValues: { elig: 'no' },
    rules: [{ type: 'single', fields: ['sid'], algorithm: 'iso7064_mod37_36',
              blockSave: 'hard', when: "[elig]='yes'" }],
  });
  check('snapshot ref evaluates false -> inert', msgOf(env, 'sid').style.display === 'none');
}
{
  const sid = makeEl('input'); sid.name = 'sid'; sid.value = BAD_ID;
  const env = boot([sid], {
    singleFields: [], pooledFields: [],
    // no whenValues at all (new record): off-page ref reads ''
    rules: [{ type: 'single', fields: ['sid'], algorithm: 'iso7064_mod37_36',
              blockSave: 'hard', when: "[elig]=''" }],
  });
  check('missing snapshot: off-page ref reads empty (condition true here)',
    /check character/i.test(msgOf(env, 'sid').innerHTML));
}

// ---- 5) unparseable condition fails OPEN (never traps a save) --------------
{
  const sid = makeEl('input'); sid.name = 'sid'; sid.value = BAD_ID;
  const env = boot([sid], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'single', fields: ['sid'], algorithm: 'iso7064_mod37_36',
              blockSave: 'hard', when: "datediff([a],[b],'d')>3" }],
  });
  check('unparseable when: no validation (fail open)', msgOf(env, 'sid').style.display === 'none');
  check('unparseable when: field not flagged', sid.__qridInvalid === false);
  const ev = submitEv();
  env.doc.fire('submit', ev);
  check('unparseable when: save never trapped', ev._prevented === false);
  check('unparseable when: reason on the console', env.consoleErrors.some((m) => /when/.test(m)));
}

// ---- 6) pooled factory honors the same gate --------------------------------
{
  const pool = makeEl('textarea'); pool.name = 'pool'; pool.value = 'not-an-id!!';
  const stype = makeEl('select'); stype.name = 'stype'; stype.value = '1';
  const env = boot([pool, stype], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'pooled', fields: ['pool'], algorithm: 'iso7064_mod37_36',
              blockSave: 'hard', when: "[stype]='2'" }],
  });
  const msg = msgOf(env, 'pool');
  check('pooled gate false: junk content not flagged', msg.style.display === 'none' && pool.__qridInvalid === false);
  stype.value = '2';
  stype.fire('change');
  check('pooled gate flip: verdict rendered', pool.__qridInvalid === true && msg.innerHTML !== '');
  stype.value = '1';
  stype.fire('change');
  check('pooled gate flip back: verdict cleared', msg.style.display === 'none' && pool.__qridInvalid === false);
}

// ---- 7) rules WITHOUT when are untouched (regression) ----------------------
{
  const sid = makeEl('input'); sid.name = 'sid'; sid.value = BAD_ID;
  const env = boot([sid], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'single', fields: ['sid'], algorithm: 'iso7064_mod37_36', blockSave: 'hard' }],
  });
  check('when-less rule validates immediately', /check character/i.test(msgOf(env, 'sid').innerHTML));
  const ev = submitEv();
  env.doc.fire('submit', ev);
  check('when-less rule still hard-blocks', ev._prevented === true);
}

// ---- 8) two rules sharing a ref field both re-check on one flip -------------
{
  const a = makeEl('input'); a.name = 'id_a'; a.value = BAD_ID;
  const b = makeEl('input'); b.name = 'id_b'; b.value = 'junk';
  const stype = makeEl('select'); stype.name = 'stype'; stype.value = '1';
  const env = boot([a, b, stype], {
    singleFields: [], pooledFields: [],
    rules: [
      { type: 'single', fields: ['id_a'], algorithm: 'iso7064_mod37_36', when: "[stype]='2'" },
      { type: 'single', fields: ['id_b'], algorithm: 'none', idPattern: 'FC[0-9]{4}', when: "[stype]<>'1'" },
    ],
  });
  check('both gated rules inert at load',
    msgOf(env, 'id_a').style.display === 'none' && msgOf(env, 'id_b').style.display === 'none');
  stype.value = '2';
  stype.fire('change');
  check('one flip re-checks BOTH rules sharing the ref',
    a.__qridInvalid === true && b.__qridInvalid === true);
}

console.log(`when_dom_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
