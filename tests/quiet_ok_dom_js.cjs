/*
 * quiet_ok_dom_js.cjs - quiet mode (project setting quiet-ok, page config
 * "quietOk"): a field that passes shows no line.
 *
 * Data entry staff reported the green "OK." lines under every field as noise
 * (2026-11-12). With "quietOk": true the lines that only confirm are written
 * but not displayed; every line that reports a problem shows as before, the
 * save guard decides the same, and what an @UVEXISTS lookup found still shows.
 *
 * Run:  node tests/quiet_ok_dom_js.cjs
 */
'use strict';
const path = require('path');

let n = 0, fail = 0;
function check(label, cond, got) {
  n++;
  if (!cond) { fail++; console.error('FAIL: ' + label + (got !== undefined ? ' (got ' + JSON.stringify(got) + ')' : '')); }
}
function makeStyle() {
  const s = {};
  Object.defineProperty(s, 'cssText', {
    get() { return this._css || ''; },
    set(v) {
      this._css = String(v);
      const m = /(?:^|;)\s*display\s*:\s*([^;]+)/.exec(this._css);
      this.display = m ? m[1].trim() : '';
    },
  });
  return s;
}
function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), id: '', name: '', value: '', innerHTML: '',
    type: '', checked: false,
    style: makeStyle(), _attrs: {}, children: [], parentNode: null, readOnly: false, disabled: false,
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

const JSMO = 'EMStub.UV';
const RULES = [
  { type: 'constraint', fields: ['end'], assert: '[end]>=[start]', message: 'End before start', blockSave: 'hard' },
  { type: 'unique', fields: ['pid'], blockSave: 'hard' },
  { type: 'exists', fields: ['spec'], existsIn: 'specimen_id', blockSave: 'hard' },
];
function boot(values, extra) {
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  const allEls = [], els = {}, holders = {};
  const body = makeEl('body');
  for (const name of ['start', 'end', 'pid', 'spec']) {
    const el = makeEl('input'); el.name = name; el.value = values[name] || '';
    const holder = makeEl('div');
    holder.appendChild(el); body.appendChild(holder);
    allEls.push(el, holder);
    els[name] = el; holders[name] = holder;
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
  const transport = {
    ajax(action, payload) {
      const one = (a, p) => {
        const v = p.values[p.field];
        if (a === 'unique-check') return { used: /^USED/.test(v), record: /^USED/.test(v) ? '7' : null };
        return /^SP-404/.test(v) ? { state: 'not-found' } : { state: 'found', record: '3' };
      };
      const r = action === 'lookup-batch' ? { answers: payload.items.map((it) => one(it.action, it.payload)) } : one(action, payload);
      return { then(res) { res(r); } };
    },
  };
  const win = {
    _alerts: [], alert(m) { this._alerts.push(m); }, confirm() { return true; },
    EMStub: { UV: transport },
    INSPIRE_VALIDATOR_CONFIG: Object.assign({ singleFields: [], pooledFields: [], jsmoName: JSMO, rules: RULES }, extra || {}),
  };
  global.document = doc; global.window = win;
  require(enginePath);
  const line = (name) => holders[name].children.slice(1).filter((k) => k.innerHTML !== '');
  /* the lines a person can see under a field */
  const seen = (name) => line(name).filter((k) => k.style.display !== 'none').map((k) => k.innerHTML).join(' | ');
  const save = () => {
    const ev = { target: {}, type: 'submit', _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
    doc.fire('submit', ev);
    return !ev._prevented;
  };
  const set = (name, v) => { els[name].value = v; els[name].fire('input'); els[name].fire('change'); };
  return { win, els, seen, save, set };
}
const GOOD = { start: '2024-01-01', end: '2024-01-05', pid: 'FREE-1', spec: 'SP-1' };

// ---- the setting off: the lines show as they always did ----------------------------
for (const [what, extra] of [['no "quietOk"', {}], ['"quietOk": false', { quietOk: false }], ['"quietOk": "yes" (not true)', { quietOk: 'yes' }]]) {
  const env = boot(GOOD, extra);
  check(what + ': a passing constraint says OK', /OK\./.test(env.seen('end')), env.seen('end'));
  check(what + ': a free value says "Not used before"', /Not used before/.test(env.seen('pid')), env.seen('pid'));
}
// ---- quiet mode ------------------------------------------------------------------------
{
  const env = boot(GOOD, { quietOk: true });
  check('quiet: a passing constraint shows no line', env.seen('end') === '', env.seen('end'));
  check('quiet: a free value shows no line', env.seen('pid') === '', env.seen('pid'));
  check('quiet: what @UVEXISTS found still shows', /Found/.test(env.seen('spec')), env.seen('spec'));
  check('quiet: the fields are not marked invalid, and the save goes through',
    env.els.end.getAttribute('aria-invalid') !== 'true' && env.els.pid.getAttribute('aria-invalid') !== 'true' && env.save());
  // A problem shows, and blocks, as without the setting.
  env.set('end', '2023-12-01');
  check('quiet: a failed constraint shows its message', /End before start/.test(env.seen('end')), env.seen('end'));
  env.set('pid', 'USED-9');
  check('quiet: a value already recorded shows its message', /already recorded/.test(env.seen('pid')), env.seen('pid'));
  env.set('spec', 'SP-404');
  check('quiet: a value not found shows its message', env.seen('spec') !== '' && !/Found/.test(env.seen('spec')), env.seen('spec'));
  check('quiet: the save is refused, and the dialog names the problem',
    !env.save() && env.win._alerts.length === 1 && /fix the flagged/.test(env.win._alerts[0]), env.win._alerts);
  // Fixed again: the lines go away.
  env.set('end', '2024-02-01'); env.set('pid', 'FREE-2'); env.set('spec', 'SP-2');
  check('quiet: once fixed, the lines are gone again', env.seen('end') === '' && env.seen('pid') === '' && /Found/.test(env.seen('spec')),
    [env.seen('end'), env.seen('pid')]);
  check('quiet: and the save goes through', env.save());
}

console.log(`quiet_ok_dom_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
