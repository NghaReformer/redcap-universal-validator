/*
 * config_notice_js.cjs — the page-level config-error notice.
 *
 * A configuration error can belong to a rule with NO text input to sit under:
 * an @UVALIDATE tag on a non-text field (dropdown/radio/calc), or a fast-entry
 * rule whose field names were all mis-typed. Before, such an error was silently
 * lost; now it surfaces in a single page-level notice. This test drives that
 * helper directly (exposed as QRCheck.configErrorNotice) under a minimal DOM
 * stub and asserts it renders once per distinct message, escaped. It then
 * loads the engine with a config of another configShape (QUA-7): nothing is
 * read, and the notice says to reload.
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

// QUA-7: a page whose config states another configShape was built for another
// engine.js. The script checks nothing there and the notice says to reload; on
// a survey only the console says so.
function loadWith(cfg) {
  allEls.length = 0;
  document.body.children.length = 0;
  const f = document.createElement('form');
  f.id = 'form';
  document.body.appendChild(f);
  const node = document.createElement('script');
  node.id = 'inspire-validator-config';
  node.textContent = JSON.stringify(cfg);
  delete require.cache[require.resolve(path.join(__dirname, '..', 'js', 'engine.js'))];
  const warned = [];
  const warn = console.warn;
  console.warn = (m) => { warned.push(String(m)); };
  try { require(path.join(__dirname, '..', 'js', 'engine.js')); } finally { console.warn = warn; }
  return { api: global.window.INSPIREUniversalValidator, warned, box: document.getElementById('uvalidate-config-errors') };
}
const rule = { type: 'single', fields: ['study_id'], algorithm: 'iso7064_mod37_36' };
const shape = global.window.INSPIREUniversalValidator.configShape;
check('QUA-7: the engine states its config shape', Number.isInteger(shape) && shape >= 1);

let r = loadWith({ configShape: shape + 1, context: 'form', rules: [rule] });
check('QUA-7: a config of another shape is not read', r.api.config.rules.length === 0);
check('QUA-7: ...the page says to reload', !!r.box && r.box.children.length === 1
  && /does not match the server/.test(r.box.children[0].innerHTML) && /Ctrl\+F5/.test(r.box.children[0].innerHTML));
check('QUA-7: ...and the console says so', r.warned.length === 1 && /config shape/.test(r.warned[0]));

r = loadWith({ configShape: shape + 1, context: 'survey', rules: [rule] });
check('QUA-7: on a survey nothing is read and only the console says so',
  r.api.config.rules.length === 0 && r.api.config.context === 'survey' && !r.box && r.warned.length === 1);

r = loadWith({ configShape: shape, context: 'form', rules: [rule] });
check('QUA-7: a config of the same shape is read', r.api.config.rules.length === 1 && !r.box && r.warned.length === 0);

r = loadWith({ context: 'form', rules: [rule] });
check('QUA-7: a config that states no shape is read as it is', r.api.config.rules.length === 1 && r.warned.length === 0);

console.log(`config_notice_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
