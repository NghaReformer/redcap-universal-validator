/*
 * dispatch_notice_js.cjs — the dispatcher routes config-error rules to the notice.
 *
 * Regression test for two adversarial-review findings:
 *   1. Two config-error rules that share the same placeholder field name must NOT
 *      collapse into one bogus "duplicate field" error — config-error rules are
 *      excluded from duplicate detection, and each distinct message is shown.
 *   2. A config-error rule renders ONCE in the page notice (handled in boot(),
 *      not per-field), so it never disarms the retry/observer or double-renders.
 *
 * Drives the real dispatcher by setting window.INSPIRE_VALIDATOR_CONFIG before
 * loading js/engine.js, under a minimal DOM stub.
 *
 * Run:  node tests/dispatch_notice_js.cjs
 */
'use strict';
const path = require('path');

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), id: '', name: '', value: '', innerHTML: '',
    style: {}, _attrs: {}, children: [], parentNode: null,
    setAttribute(k, v) { this._attrs[k] = v; if (k === 'id') this.id = v; },
    getAttribute(k) { return (k in this._attrs) ? this._attrs[k] : (k === 'id' ? (this.id || null) : null); },
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
    insertBefore(n) { n.parentNode = this; this.children.unshift(n); return n; },
    get firstChild() { return this.children[0] || null; },
    get nextSibling() { return null; },
  };
}

const allEls = [];
global.document = {
  body: makeEl('body'),
  createElement(t) { const e = makeEl(t); allEls.push(e); return e; },
  getElementById(id) { return allEls.find((e) => e.id === id) || null; },
  getElementsByName() { return []; },       // no field ever resolves -> pure config-error path
  querySelector() { return null; },
  addEventListener() {},
  readyState: 'complete',
};
const form = document.createElement('form');
form.id = 'form';
document.body.appendChild(form);

// In a browser, window === globalThis; mirror that so the engine's window.QRCheck
// (set as globalThis.QRCheck during load) is visible to the factories.
global.window = global;
global.INSPIRE_VALIDATOR_CONFIG = {
  singleFields: [], pooledFields: [],
  rules: [
    // Two all-invalid fast-entry rules that BOTH ended up with the placeholder
    // field name — must not collapse into a "duplicate" error.
    { type: 'single', fields: ['(unknown field)'], configError: 'ruleA: field(s) not in this project: aaa' },
    { type: 'single', fields: ['(unknown field)'], configError: 'ruleB: field(s) not in this project: bbb' },
    // @UVALIDATE on a non-text field.
    { type: 'single', fields: ['gender'], configError: '@UVALIDATE on "gender": only works on Text or Notes fields.' },
    // A valid rule — must NOT create any notice.
    { type: 'single', fields: ['ok_id'], algorithm: 'iso7064_mod37_36' },
  ],
};

require(path.join(__dirname, '..', 'js', 'engine.js'));

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

const box = document.getElementById('uvalidate-config-errors');
check('config-error notice was created', !!box);
const text = box ? box.children.map((c) => c.innerHTML).join('\n') : '';
check('rule A message shown', /ruleA/.test(text));
check('rule B message shown (no collision suppression)', /ruleB/.test(text));
check('non-text-field message shown', /only works on Text or Notes/.test(text));
check('exactly three config errors (no duplicate-field message added)', box && box.children.length === 3);
check('no spurious "listed in more than one" duplicate error', !/listed in more than one/.test(text));

console.log(`dispatch_notice_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
