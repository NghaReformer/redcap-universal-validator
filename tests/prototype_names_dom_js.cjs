'use strict';
/*
 * prototype_names_dom_js.cjs — a field named "constructor" behaves like any other (S2).
 *
 * REDCap allows "constructor" as a variable name, and it is the one lowercase
 * name an object inherits: a plain map keyed by field name answers
 * map.constructor with Object, not undefined. The 2026-09-23 wargame found
 * @UVUNIQUE treating such a field as record-local (never asking the server,
 * never blocking). This suite boots each mode twice, once with ordinary names
 * and once with the tagged field or the field its condition reads named
 * "constructor", and requires the same messages, the same save verdict and the
 * same server calls, with the names swapped.
 *
 * Run:  node tests/prototype_names_dom_js.cjs
 */
const path = require('path');
const fs = require('fs');
const DEFAULTS = JSON.parse(fs.readFileSync(path.join(__dirname, 'page_defaults.json'), 'utf8')).defaults;

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), nodeType: 1, id: '', name: '', value: '', innerHTML: '',
    type: '', checked: false, disabled: false, readOnly: false,
    style: { set cssText(v) { this._css = v; const m = /display:\s*([a-z]+)/.exec(v); this.display = m ? m[1] : ''; },
             get cssText() { return this._css || ''; } },
    _attrs: {}, children: [], parentNode: null, _handlers: {},
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
    removeChild(node) {
      const i = this.children.indexOf(node);
      if (i >= 0) this.children.splice(i, 1);
      node.parentNode = null;
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
function input(name, value) { const e = makeEl('input'); e.name = name; e.value = value; return e; }
function select(name, codes, value) {
  const s = makeEl('select'); s.name = name;
  ['', ...codes].forEach((c) => { const o = makeEl('option'); o.value = c; s.appendChild(o); });
  s.value = value;
  return s;
}
/* A server stub for the modes that ask: every call answers `answer`. */
function transport(answer) {
  const stub = { calls: [] };
  stub.obj = {
    ajax(action, payload) {
      stub.calls.push({ action, payload: JSON.parse(JSON.stringify(payload)) });
      return { then(res) { res(JSON.parse(JSON.stringify(answer))); } };
    },
  };
  return stub;
}
const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
function boot(els, config, stub) {
  delete require.cache[require.resolve(enginePath)];
  const allEls = [];
  const body = makeEl('body');
  for (const el of els) {
    const holder = makeEl('div');
    holder.appendChild(el);
    body.appendChild(holder);
    allEls.push(holder, el, ...el.children);
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
    _alerts: [], alert(m) { this._alerts.push(String(m)); }, confirm() { return true; },
    INSPIRE_VALIDATOR_CONFIG: Object.assign({ singleFields: [], pooledFields: [], jsmoName: 'EMStub.UV' }, DEFAULTS, config),
  };
  if (stub) win.EMStub = { UV: stub.obj };
  global.document = doc; global.window = win;
  global.setInterval = () => 0;
  global.clearInterval = () => {};
  const errors = [], was = console.error;
  console.error = (m) => errors.push(String(m));
  try { require(enginePath); } catch (e) { errors.push('boot threw: ' + e.message); } finally { console.error = was; }
  return { doc, win, allEls, errors };
}
/* What the person sees and what the save does, with the names written as T and C. */
function observe(env, stub, T, C) {
  const swap = (s) => String(s).split(T).join('<T>').split(C).join('<C>');
  const msgs = env.allEls.filter((e) => /^uvalidate-/.test(e.id || '') && e.innerHTML && e.style.display !== 'none')
    .map((e) => swap(e.id + ': ' + e.innerHTML)).sort();
  const ev = { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
  try { env.doc.fire('submit', ev); } catch (e) { env.errors.push('save threw: ' + e.message); }
  const values = env.allEls.filter((e) => e.tagName === 'INPUT').map((e) => swap(e.name + '=' + e.value)).sort();
  return { msgs, values, blocked: ev._prevented, alerts: env.win._alerts.map(swap),
           calls: stub ? stub.calls.map((c) => swap(JSON.stringify(c))) : [], errors: env.errors.map(swap) };
}
/* Each scenario boots one rule on tagged field T, whose condition or "from" reads C. */
const SCENARIOS = {
  single(T, C) {
    return { els: [input(T, 'AB12X'), input(C, '1')],
             config: { rules: [{ type: 'single', fields: [T], algorithm: 'iso7064_mod37_36', when: '[' + C + "]='1'", blockSave: 'hard' }] } };
  },
  assert(T, C) {
    return { els: [input(T, '5'), input(C, '10')],
             config: { rules: [{ type: 'constraint', fields: [T], assert: '[' + T + ']>=[' + C + ']', blockSave: 'hard' }] } };
  },
  required(T, C) {
    return { els: [input(T, ''), input(C, '1')],
             config: { rules: [{ type: 'required', fields: [T], when: '[' + C + "]='1'", blockSave: 'hard' }] } };
  },
  unique(T, C) {
    return { els: [input(T, 'AB100'), input(C, '1')], answer: { used: true, record: '7' },
             config: { rules: [{ type: 'unique', fields: [T], when: '[' + C + "]='1'", blockSave: 'hard' }] } };
  },
  choices(T, C) {
    return { els: [select(T, ['1', '2', '9'], '9'), input(C, '1')],
             config: { rules: [{ type: 'choices', fields: [T], choicesAll: ['1', '2', '9'], choicesHide: ['9'],
                                 when: '[' + C + "]='1'", blockSave: 'hard' }] } };
  },
  window(T, C) {
    return { els: [input(C, '01-03-2026'), input(T, '15-03-2026')],
             config: { rules: [{ type: 'window', fields: [T], windowFrom: '[' + C + ']', windowFromOp: ['ref', C, null],
                                 windowLo: 21, windowHi: 35, windowUnit: 'days', dateType: 'date', dateFormat: 'dmy',
                                 fromType: 'date', fromFormat: 'dmy', blockSave: 'hard' }] } };
  },
  range(T, C) {
    return { els: [input(T, '30'), input(C, '1')],
             config: { rules: [{ type: 'range', fields: [T], rangeHardLo: '3', rangeHardHi: '25', rangeHardText: '3 to 25',
                                 when: '[' + C + "]='1'", hardBlock: 'hard' }] } };
  },
  // C is a field the lookup fills: the answer arrives keyed by its name
  existsReturn(T, C) {
    return { els: [input(T, 'SP-1'), input(C, '')], answer: { state: 'found', record: null, values: { [C]: 'X1' } }, blocks: false,
             config: { rules: [{ type: 'exists', fields: [T], existsReturnFields: [C], blockSave: 'off' }] } };
  },
  exists(T, C) {
    return { els: [input(T, 'SP-404'), input(C, '1')], answer: { state: 'not-found', record: null },
             config: { rules: [{ type: 'exists', fields: [T], when: '[' + C + "]='1'", blockSave: 'hard' }] } };
  },
};
function run(name, T, C) {
  const s = SCENARIOS[name](T, C);
  const stub = s.answer ? transport(s.answer) : null;
  const env = boot(s.els, s.config, stub);
  for (const el of s.els) { el.fire('input'); el.fire('change'); el.fire('blur'); }
  return Object.assign(observe(env, stub, T, C), { blocks: s.blocks !== false });
}
for (const name of Object.keys(SCENARIOS)) {
  const plain = run(name, 'tagged', 'ctl');
  check(name + ': the ordinary names do what the mode does (got ' + JSON.stringify(plain) + ')', plain.errors.length === 0
    && (plain.blocks ? plain.blocked === true && plain.msgs.length > 0 : plain.values.indexOf('<C>=X1') >= 0));
  for (const [T, C, which] of [['constructor', 'ctl', 'the tagged field'], ['tagged', 'constructor', 'the field it reads']]) {
    const got = run(name, T, C);
    check(name + ': ' + which + ' named "constructor" behaves the same (got ' + JSON.stringify(got) + ', want ' + JSON.stringify(plain) + ')',
      JSON.stringify(got) === JSON.stringify(plain));
  }
}
console.log((fail ? 'FAILED' : 'OK') + ' — prototype_names_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exitCode = fail ? 1 : 0;
