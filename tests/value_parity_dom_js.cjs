/*
 * value_parity_dom_js.cjs — the browser half of tests/value_parity_fixture.json.
 *
 * Each row is one value typed into an @UVALIDATE field. This suite boots the
 * real engine on a stub page, types the value, fires "change" and reads what
 * the user sees: no message ("blank"), a pass ("valid") or a block ("invalid").
 * tests/value_parity_php.php runs the same rows through the server twin, so
 * a row where the two sides part fails one suite or the other (VAL-1, VAL-3,
 * VAL-12 of the 2026-10-21 review).
 *
 * Run:  node tests/value_parity_dom_js.cjs
 */
'use strict';
const path = require('path');
const fs = require('fs');

const FX = JSON.parse(fs.readFileSync(path.join(__dirname, 'value_parity_fixture.json'), 'utf8'));
const PAGE_DEFAULTS = require('./page_defaults.json').defaults;

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), id: '', name: '', value: '', innerHTML: '',
    type: '', checked: false, style: {}, _attrs: {}, children: [], parentNode: null,
    readOnly: false, disabled: false, _handlers: {},
    setAttribute(k, v) { this._attrs[k] = String(v); if (k === 'id') this.id = String(v); },
    getAttribute(k) { return (k in this._attrs) ? this._attrs[k] : (k === 'id' ? (this.id || null) : null); },
    removeAttribute(k) { delete this._attrs[k]; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { const self = this; (this._handlers[type] || []).forEach((fn) => fn.call(self, ev || {})); },
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
    insertBefore(node, ref) {
      node.parentNode = this;
      const i = ref ? this.children.indexOf(ref) : -1;
      if (i >= 0) this.children.splice(i, 0, node); else this.children.push(node);
      return node;
    },
    closest() { return null; }, focus() {},
    get nextSibling() {
      const i = this.parentNode ? this.parentNode.children.indexOf(this) : -1;
      return i >= 0 ? (this.parentNode.children[i + 1] || null) : null;
    },
    get firstChild() { return this.children[0] || null; },
  };
}

/* One field "sid" under one rule; pooled fields are textareas on a real form. */
function boot(rule) {
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  const allEls = [];
  const body = makeEl('body');
  const holder = makeEl('div');
  const el = makeEl(rule.type === 'pooled' ? 'textarea' : 'input');
  el.name = 'sid';
  holder.appendChild(el);
  body.appendChild(holder);
  allEls.push(el, holder);
  const doc = {
    body, readyState: 'complete', _handlers: {},
    createElement(t) { const e = makeEl(t); allEls.push(e); return e; },
    getElementById(id) { return allEls.find((e) => e.id === id) || null; },
    getElementsByName(name) { return allEls.filter((e) => e.name === name); },
    querySelector() { return null; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
  };
  const win = {
    alert() {}, confirm() { return false; }, addEventListener() {},
    INSPIRE_VALIDATOR_CONFIG: Object.assign({}, PAGE_DEFAULTS, {
      singleFields: [], pooledFields: [], context: 'form',
      rules: [Object.assign({}, rule, { fields: ['sid'] })],
    }),
  };
  global.document = doc; global.window = win;
  const origSetInterval = global.setInterval;
  global.setInterval = function () { return 0; };     // no retry timers in a unit run
  try { require(enginePath); } finally { global.setInterval = origSetInterval; }
  return el;
}

/* What the user sees after typing $value and leaving the field. */
function judge(el, value) {
  const msg0 = el.nextSibling;
  if (msg0) { msg0.style = {}; msg0.innerHTML = ''; }  // the stub does not tie cssText to display
  el.value = value;
  el.fire('change');
  const msg = el.nextSibling;
  const shown = !!msg && msg.style.display !== 'none' && /display:block/.test(msg.style.cssText || '');
  if (!shown) return 'blank';
  return el.__qridInvalid ? 'invalid' : 'valid';
}

function valueOf(v) {
  if (typeof v === 'string') return v;
  return (v.prefix || '') + v.repeat.repeat(v.times);
}

for (const g of FX.groups) {
  const el = boot(g.rule);
  for (const [value, browser] of g.rows) {
    const got = judge(el, value);
    check(JSON.stringify(g.rule) + ' ' + JSON.stringify(value) + ': browser shows "' + browser + '" (got "' + got + '")',
      got === browser);
  }
}
for (const c of FX.length.cases) {
  const el = boot(c.rule);
  const value = valueOf(c.value);
  const got = judge(el, value);
  check('length: ' + JSON.stringify(c.value) + ': browser shows "' + c.browser + '" (got "' + got + '")', got === c.browser);
}

console.log(`value_parity_dom_js: ${n - fail}/${n} passed`);
process.exit(fail ? 1 : 0);
