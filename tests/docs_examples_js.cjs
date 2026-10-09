/*
 * docs_examples_js.cjs: the browser engine must reach every outcome the docs
 * promise.
 *
 * tests/docs_examples_php.php reads each ```text expect block in the docs,
 * checks the documented result against the server verdict and records the
 * case in tests/docs_expect_fixture.json. This replays those cases through
 * js/engine.js, booted the way the server injects it: a single-ID rule must
 * accept or refuse the value exactly as the server did, and a pooled rule must
 * split it into the same members and junk, with the same alternate credited.
 *
 * Run:  node tests/docs_examples_js.cjs
 */
'use strict';
const fs = require('fs');
const path = require('path');

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), id: '', name: '', value: '', innerHTML: '',
    type: '', checked: false, style: {}, _attrs: {}, children: [], parentNode: null,
    readOnly: false, disabled: false, _handlers: {},
    setAttribute(k, v) { this._attrs[k] = String(v); if (k === 'id') this.id = String(v); },
    getAttribute(k) { return (k in this._attrs) ? this._attrs[k] : (k === 'id' ? (this.id || null) : null); },
    removeAttribute(k) { delete this._attrs[k]; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
    insertBefore(node, ref) {
      node.parentNode = this;
      const i = ref ? this.children.indexOf(ref) : -1;
      if (i >= 0) this.children.splice(i, 0, node); else this.children.push(node);
      return node;
    },
    closest() { return null; },
    focus() {},
    get nextSibling() {
      const i = this.parentNode ? this.parentNode.children.indexOf(this) : -1;
      return i >= 0 ? (this.parentNode.children[i + 1] || null) : null;
    },
    get firstChild() { return this.children[0] || null; },
  };
}

// Boot one rule on one field, with the defaults buildClientConfig merges in.
function boot(rule) {
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  const el = makeEl('input');
  el.name = 'f';
  const body = makeEl('body');
  const holder = makeEl('div');
  holder.appendChild(el);
  body.appendChild(holder);
  const all = [el, holder];
  global.document = {
    body, readyState: 'complete',
    createElement(t) { const e = makeEl(t); all.push(e); return e; },
    getElementById(id) { return all.find((e) => e.id === id) || null; },
    getElementsByName(name) { return all.filter((e) => e.name === name); },
    querySelector() { return null; },
    addEventListener() {},
  };
  const r = Object.assign({}, rule, { fields: ['f'] });
  delete r.when;   // the expect block tests the validator, not its gate
  global.window = {
    alert() {}, confirm() { return true; },
    INSPIRE_VALIDATOR_CONFIG: {
      algorithm: 'iso7064_mod37_36', idPattern: null, alternates: null,
      source: 'normalized_id', strip: '-/ _|\\', suggestFix: false, keepChars: '',
      idLengths: null, idMinLen: 8, idMaxLen: 14, expectedIds: null, blockSave: 'off',
      singleFields: [], pooledFields: [], rules: [r],
    },
  };
  require(enginePath);
  return global.window.INSPIREUniversalValidator.validators.f;
}

function canon(segs) {
  return segs.map((s) => (s.type === 'id'
    ? ['id', s.id, !!s.valid, typeof s.alt === 'number' ? s.alt : -1]
    : ['junk', s.text]));
}

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'docs_expect_fixture.json'), 'utf8'));
let fail = 0;
for (const c of fx.cases) {
  const v = boot(c.rule);
  const label = `${c.where} ${JSON.stringify(c.input)}`;
  if (!v) { fail++; console.error(`NO VALIDATOR ${label}`); continue; }
  if (v.mode && v.mode.configError) { fail++; console.error(`CONFIG ERROR ${label}: ${v.mode.configError}`); continue; }
  if ((c.rule.type || 'single') === 'pooled') {
    const got = JSON.stringify(canon(v.parse(c.input)));
    const exp = JSON.stringify(c.segs);
    if (got !== exp) { fail++; console.error(`MISMATCH ${label}\n  server ${exp}\n  browser ${got}`); }
  } else {
    const res = v.test(c.input, true);
    const ok = !!(res && res.ok === true);
    if (ok !== c.ok) { fail++; console.error(`MISMATCH ${label}: server ok=${c.ok}, browser ok=${ok}`); }
  }
}
console.log(`docs_examples_js: ${fx.cases.length} documented outcomes replayed, ${fail} mismatch(es)`);
process.exit(fail === 0 ? 0 : 1);
