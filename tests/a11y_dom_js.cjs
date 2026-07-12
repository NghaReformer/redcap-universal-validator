/*
 * a11y_dom_js.cjs — the field-facing DOM contract of the client engine.
 *
 * Drives the real factories through a minimal DOM stub and asserts the parts a
 * screen-reader user depends on (WCAG 2.2 SC 3.3.1 / 4.1.3 — A11Y-001):
 *   - the status message is a polite live region with a stable id,
 *   - the input is wired to it with aria-describedby and carries aria-invalid,
 *   - save-block dialogs name fields by their visible label, not the variable,
 *   - readonly fields never arm the save blocker (UX-003),
 *   - keystroke validation is debounced; change/blur validate immediately,
 *   - survey pages mute technical configuration detail (UX-001).
 *
 * A DOM stub cannot prove how NVDA/JAWS/VoiceOver announce these — that stays
 * a manual checklist item in docs/TESTING.md.
 *
 * Run:  node tests/a11y_dom_js.cjs
 */
'use strict';
const path = require('path');

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), id: '', name: '', value: '', innerHTML: '',
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

// ---- 1) a11y wiring on a live single-field rule ----------------------------
// (the input must exist before the engine loads, so boot() attaches on sweep 1)
{
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  const allEls = [];
  const body = makeEl('body');
  const input = makeEl('input');
  input.name = 'study_id';
  input.setAttribute('aria-label', 'Participant Study ID');
  const holder = makeEl('div');
  holder.appendChild(input);
  body.appendChild(holder);
  allEls.push(input, holder);
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
    INSPIRE_VALIDATOR_CONFIG: {
      singleFields: [], pooledFields: [],
      rules: [{ type: 'single', fields: ['study_id'], algorithm: 'iso7064_mod37_36', blockSave: 'hard' }],
    },
  };
  global.document = doc; global.window = win;
  require(enginePath);
  const NS = win.INSPIREUniversalValidator;

  const msg = holder.children[1];
  check('status region created next to the input', !!msg && msg.id === 'uvalidate-msg-study_id');
  check('status region is a polite live region',
    msg && msg.getAttribute('role') === 'status' && msg.getAttribute('aria-live') === 'polite'
    && msg.getAttribute('aria-atomic') === 'true');
  check('input is wired via aria-describedby', input.getAttribute('aria-describedby') === 'uvalidate-msg-study_id');
  check('no aria-invalid while the field is empty', input.getAttribute('aria-invalid') === null);

  // invalid value, final check (change event) -> announced as an error
  input.value = '0ABC00001X';               // wrong check character
  input.fire('change');
  check('invalid final value sets aria-invalid=true', input.getAttribute('aria-invalid') === 'true');
  check('message shows a check-character error', /check character/i.test(msg.innerHTML));

  // mint a valid ID with the engine itself -> announced as valid
  const MINT = NS.engine.makeScheme({ algorithm: 'iso7064_mod37_36', source: 'normalized_id',
    placement: 'append', enabled: true,
    normalize_rules: { strip_delimiters: '', uppercase: true, unify_unicode_dashes: true, keep_only: null } });
  input.value = NS.engine.appendCheck('0ABC0000', MINT);
  input.fire('change');
  check('valid value sets aria-invalid=false', input.getAttribute('aria-invalid') === 'false');

  // empty again -> attribute removed (no stale error state)
  input.value = '';
  input.fire('change');
  check('clearing the field removes aria-invalid', input.getAttribute('aria-invalid') === null);

  // hard block: the dialog names the field by its LABEL, not the variable name
  input.value = '0ABC00001X';
  input.fire('change');
  const ev = { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
  doc.fire('submit', ev);
  check('hard block prevents the submit', ev._prevented === true);
  check('block dialog uses the visible label', win._alerts.length === 1 && /Participant Study ID/.test(win._alerts[0]));
  check('block dialog does not leak the variable name', !/study_id/.test(win._alerts[0]));
  check('blocked field receives focus', input._focused === true);

  // ---- debounce: keystrokes are deferred, change/blur are immediate ----
  input.value = '0ABC0000';                  // incomplete/invalid
  msg.innerHTML = 'SENTINEL';
  input.fire('input');
  check('input event does not validate synchronously (debounced)', msg.innerHTML === 'SENTINEL');
  setTimeout(() => {
    check('debounced validation ran after the quiet period', msg.innerHTML !== 'SENTINEL');

    // ---- 2) readonly fields never arm the blocker ----
    {
      const enginePath2 = path.join(__dirname, '..', 'js', 'engine.js');
      delete require.cache[require.resolve(enginePath2)];
      const allEls2 = [];
      const body2 = makeEl('body');
      const ro = makeEl('input'); ro.name = 'locked_id'; ro.readOnly = true;
      const holder2 = makeEl('div'); holder2.appendChild(ro); body2.appendChild(holder2);
      allEls2.push(ro, holder2);
      const doc2 = {
        body: body2, readyState: 'complete', _handlers: {},
        createElement(t) { const e = makeEl(t); allEls2.push(e); return e; },
        getElementById(id) { return allEls2.find((e) => e.id === id) || null; },
        getElementsByName(name) { return allEls2.filter((e) => e.name === name); },
        querySelector() { return null; },
        addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
        fire() {},
      };
      const win2 = {
        alert() {}, confirm() { return true; },
        INSPIRE_VALIDATOR_CONFIG: {
          singleFields: [], pooledFields: [],
          rules: [{ type: 'single', fields: ['locked_id'], algorithm: 'iso7064_mod37_36', blockSave: 'hard' }],
        },
      };
      global.document = doc2; global.window = win2;
      require(enginePath2);
      const NS2 = win2.INSPIREUniversalValidator;
      check('readonly field still gets its message region', holder2.children.length === 2);
      check('readonly field never arms the save blocker (UX-003)', NS2.guard.items.length === 0);
    }

    // ---- 3) survey context mutes technical configuration detail ----
    {
      const enginePath3 = path.join(__dirname, '..', 'js', 'engine.js');
      delete require.cache[require.resolve(enginePath3)];
      const allEls3 = [];
      const body3 = makeEl('body');
      const inp3 = makeEl('input'); inp3.name = 'survey_id';
      const holder3 = makeEl('div'); holder3.appendChild(inp3); body3.appendChild(holder3);
      allEls3.push(inp3, holder3);
      const doc3 = {
        body: body3, readyState: 'complete', _handlers: {},
        createElement(t) { const e = makeEl(t); allEls3.push(e); return e; },
        getElementById(id) { return allEls3.find((e) => e.id === id) || null; },
        getElementsByName(name) { return allEls3.filter((e) => e.name === name); },
        querySelector() { return null; },
        addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
        fire() {},
      };
      const win3 = {
        alert() {}, confirm() { return true; },
        INSPIRE_VALIDATOR_CONFIG: {
          context: 'survey', singleFields: [], pooledFields: [],
          rules: [
            // a config-error rule whose field IS on the page -> generic text only
            { type: 'single', fields: ['survey_id'], configError: 'secret internal detail: pattern (a|aa)+ rejected' },
            // a config-error rule with no field anywhere -> NO page-level notice on surveys
            { type: 'single', fields: ['(unknown field)'], configError: 'field(s) not in this project: xyz' },
          ],
        },
      };
      global.document = doc3; global.window = win3;
      require(enginePath3);
      const msg3 = holder3.children[1];
      check('survey per-field config error is generic', /unavailable/.test(msg3.innerHTML));
      check('survey per-field config error hides technical detail', !/secret internal detail/.test(msg3.innerHTML));
      check('survey page shows NO page-level config notice', doc3.getElementById('uvalidate-config-errors') === null);
    }

    console.log(`a11y_dom_js: ${n} checks, ${fail} failure(s)`);
    process.exit(fail === 0 ? 0 : 1);
  }, 400);
}
