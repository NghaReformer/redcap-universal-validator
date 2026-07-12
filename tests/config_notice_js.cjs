/*
 * config_notice_js.cjs — the page-level config-error notice.
 *
 * A configuration error can belong to a rule with NO text input to sit under:
 * an @UVALIDATE tag on a non-text field (dropdown/radio/calc), or a fast-entry
 * rule whose field names were all mis-typed. Before, such an error was silently
 * lost; now it surfaces in a single page-level notice. This test drives that
 * helper directly (exposed as QRCheck.configErrorNotice) under a minimal DOM
 * stub and asserts it renders once per distinct message, escaped.
 *
 * Run:  node tests/config_notice_js.cjs
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
  };
}

const allEls = [];
global.window = {};
global.document = {
  body: makeEl('body'),
  createElement(t) { const e = makeEl(t); allEls.push(e); return e; },
  getElementById(id) { return allEls.find((e) => e.id === id) || null; },
  getElementsByName() { return []; },
  querySelector() { return null; },
  addEventListener() {},
  readyState: 'complete',
};
// A REDCap-style form element the notice anchors before.
const form = document.createElement('form');
form.id = 'form';
document.body.appendChild(form);

require(path.join(__dirname, '..', 'js', 'engine.js'));
const notice = global.window.INSPIREUniversalValidator.configErrorNotice;

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

check('helper is exposed', typeof notice === 'function');

notice('@UVALIDATE on "gender": only works on Text or Notes fields (this field is "radio").');
let box = document.getElementById('uvalidate-config-errors');
check('notice box is created', !!box);
check('box anchored into the form parent (visible)', box && box.parentNode === document.body);
check('one message rendered', box && box.children.length === 1);

notice('field(s) not in this project: studyid, specimenid — check spelling.');
check('second distinct message rendered', box.children.length === 2);

notice('@UVALIDATE on "gender": only works on Text or Notes fields (this field is "radio").');
check('duplicate message is deduplicated', box.children.length === 2);

notice('<img src=x onerror=alert(1)>');
const last = box.children[box.children.length - 1];
check('config-error text is HTML-escaped', /&lt;img/.test(last.innerHTML) && !/<img/.test(last.innerHTML));

console.log(`config_notice_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
