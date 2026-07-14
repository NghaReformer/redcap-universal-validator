/*
 * pooled_dom_js.cjs — the pooled field's chip rendering contract.
 *
 * The chip SEVERITY model (v0.9.0): hard problems are red, warnings amber —
 *   - invalid ID (check-character mismatch)  -> RED   + cross mark
 *   - junk (text that is not an ID)          -> RED   + question mark
 *   - duplicate of a VALID ID (repeat scan)  -> AMBER + circled-x + "(again!)"
 *   - valid ID                               -> GREEN + check mark
 * Colors pair with non-color marks (A11Y-002) — this test locks both, so a
 * future restyle cannot silently invert severity again (the pre-0.9.0 palette
 * had duplicates red and junk amber, which read as "errors are yellow").
 *
 * The parse SEGMENTATION itself is locked by tests/pooled_fixture.json; this
 * file only tests the chip presentation around it.
 *
 * Run:  node tests/pooled_dom_js.cjs
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

const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
delete require.cache[require.resolve(enginePath)];
const allEls = [];
const body = makeEl('body');
const pool = makeEl('textarea');
pool.name = 'pool';
const holder = makeEl('div');
holder.appendChild(pool);
body.appendChild(holder);
allEls.push(pool, holder);
global.document = {
  body, readyState: 'complete', _handlers: {},
  createElement(t) { const e = makeEl(t); allEls.push(e); return e; },
  getElementById(id) { return allEls.find((e) => e.id === id) || null; },
  getElementsByName(name) { return allEls.filter((e) => e.name === name); },
  querySelector() { return null; },
  addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
  fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev)); },
};
global.window = {
  alert() {}, confirm() { return true; },
  INSPIRE_VALIDATOR_CONFIG: {
    singleFields: [], pooledFields: [],
    // same config as the pooled fixture's "check+pattern with a bad member"
    // case — the exact-length sandwich is what yields an INVALID member chip
    // (an unverifiable run without that geometry is classified as junk).
    rules: [{ type: 'pooled', fields: ['pool'], algorithm: 'iso7064_mod37_36',
              idPattern: '[0-9A-Z]{9}', idLengths: [9] }],
  },
};
require(enginePath);
const NS = global.window.INSPIREUniversalValidator;
const msg = holder.children[1];

const RED = '#c62828';
const AMBER = '#8a5500';
const GREEN = '#2e7d32';

function chipsOf(html) { return html.split('<span').slice(1); }
function chipWith(chips, marker) { return chips.find((c) => c.indexOf(marker) !== -1) || ''; }

// ---- A) invalid member: valid + INVALID + valid, no separators ----
pool.value = '0ABC0000H0ABC0001X0DEF00093'; // fixture-verified: ok, bad, ok
pool.fire('change');
{
  const chips = chipsOf(msg.innerHTML);
  check('three member chips render', chips.length === 3);
  const okChip = chips[0];
  check('valid chip is green', okChip.indexOf(GREEN) !== -1);
  check('valid chip carries the check mark', okChip.indexOf('&#10003;') !== -1);
  const badChip = chipWith(chips, '&#10007;');
  check('invalid chip exists', badChip !== '');
  check('invalid chip is RED', badChip.indexOf(RED) !== -1);
  check('invalid chip is not amber', badChip.indexOf(AMBER) === -1);
  check('summary flags the mismatch', /check-character mismatch/.test(msg.innerHTML));
  check('field flagged invalid overall', pool.__qridInvalid === true);
}

// ---- B) duplicate (valid ID scanned again) + junk ----
pool.value = '0ABC0000H 0ABC0000H zz!';
pool.fire('change');
{
  const chips = chipsOf(msg.innerHTML);
  const dupChip = chipWith(chips, '(again!)');
  check('duplicate chip exists', dupChip !== '');
  check('duplicate chip is AMBER (warning, not error)', dupChip.indexOf(AMBER) !== -1);
  check('duplicate chip is not red', dupChip.indexOf(RED) === -1);
  check('duplicate chip keeps the circled-x mark', dupChip.indexOf('&#8855;') !== -1);
  const junkChip = chipWith(chips, '?&nbsp;');
  check('junk chip exists', junkChip !== '');
  check('junk chip is RED (a hard problem, not a warning)', junkChip.indexOf(RED) !== -1);
  check('junk chip is not amber', junkChip.indexOf(AMBER) === -1);
  check('summary flags the duplicate', /duplicate/.test(msg.innerHTML));
  check('summary flags the junk', /not an ID/.test(msg.innerHTML));
}

// ---- C) all-valid pool: green only ----
pool.value = '0ABC0000H';
pool.fire('change');
check('all-valid pool has no red', msg.innerHTML.indexOf(RED) === -1);
check('all-valid pool has no amber', msg.innerHTML.indexOf(AMBER) === -1);
check('all-valid pool not flagged', pool.__qridInvalid === false);

console.log(`pooled_dom_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
