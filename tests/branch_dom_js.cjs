/*
 * branch_dom_js.cjs — branched validation in the browser.
 *
 * Several conditional rules may share one field since 0.9.0; the server
 * (php/Branching.php) rewrites the sharing into a per-field branch rule and
 * the client picks the branch whose "when" is true. This suite locks the
 * client side of the SAME scenario table tests/branching_php.php and
 * tests/hook_php.php lock server-side:
 *   - the ACTIVE branch's algorithm/pattern validates the field,
 *   - the else branch fires only when no condition is true,
 *   - no condition + no else = inert,
 *   - a conflict (two conditions true) shows both conditions, validates
 *     nothing, and NEVER traps the save — even when a branch is "hard",
 *   - blockSave and suggestFix are per-branch,
 *   - readonly fields never arm the blocker,
 *   - the pooled factory branches identically.
 *
 * Run:  node tests/branch_dom_js.cjs
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
  require(enginePath);
  return { doc, win, holders, NS: win.INSPIREUniversalValidator };
}
function msgOf(env, field) { return env.holders[field].children[1]; }
function submitEv() {
  return { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
}

const BAD_ID = '0ABC00001X'; // fails the mod37,36 check AND the FC[0-9]{4} format

// ---- 1) the ACTIVE branch decides which validation runs --------------------
{
  const sid = makeEl('input'); sid.name = 'sid'; sid.value = BAD_ID;
  const stype = makeEl('select'); stype.name = 'stype'; stype.value = '1';
  const env = boot([sid, stype], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'single', fields: ['sid'], branches: [
      { algorithm: 'iso7064_mod37_36', when: "[stype]='1'" },
      { algorithm: 'none', idPattern: 'FC[0-9]{4}', when: "[stype]='2'" },
    ] }],
  });
  const msg = msgOf(env, 'sid');
  check('branch 1 active: check-character verdict', /check character/i.test(msg.innerHTML)
    && sid.__qridInvalid === true);
  stype.value = '2';
  stype.fire('change');
  check('branch 2 active after flip: FORMAT verdict', /FORMAT error/i.test(msg.innerHTML)
    && !/check character/i.test(msg.innerHTML));
  stype.value = '9';
  stype.fire('change');
  check('no branch active, no else: inert', msg.style.display === 'none' && sid.__qridInvalid === false);
  // registry exposes the branch shape
  const reg = env.NS.validators.sid;
  check('validators registry: branch entry', reg && reg.branch === true && reg.branches.length === 2);
  check('validators registry: active() reflects the DOM', reg.active().length === 0);
}

// ---- 2) the else branch fires only when nothing else is true ---------------
{
  const sid = makeEl('input'); sid.name = 'sid'; sid.value = BAD_ID;
  const stype = makeEl('select'); stype.name = 'stype'; stype.value = '9';
  const env = boot([sid, stype], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'single', fields: ['sid'], branches: [
      { algorithm: 'iso7064_mod37_36', when: "[stype]='1'" },
      { algorithm: 'none', idPattern: 'FC[0-9]{4}', when: null },
    ] }],
  });
  const msg = msgOf(env, 'sid');
  check('else branch active by default: FORMAT verdict', /FORMAT error/i.test(msg.innerHTML));
  stype.value = '1';
  stype.fire('change');
  check('a true condition beats the else', /check character/i.test(msg.innerHTML));
  stype.value = '9';
  stype.fire('change');
  check('flip back returns to the else branch', /FORMAT error/i.test(msg.innerHTML));
}

// ---- 3) conflict: two conditions true -> shown, inert, never trapping ------
{
  const sid = makeEl('input'); sid.name = 'sid'; sid.value = BAD_ID;
  const a = makeEl('input'); a.name = 'fa'; a.value = '1';
  const b = makeEl('input'); b.name = 'fb'; b.value = '1';
  const env = boot([sid, a, b], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'single', fields: ['sid'], branches: [
      { algorithm: 'iso7064_mod37_36', blockSave: 'hard', when: "[fa]='1'" },
      { algorithm: 'verhoeff', when: "[fb]='1'" },
    ] }],
  });
  const msg = msgOf(env, 'sid');
  // condition strings render HTML-escaped ("'" -> &#39;) — locked here
  check('conflict message names both conditions', /Validation conflict/.test(msg.innerHTML)
    && msg.innerHTML.indexOf('[fa]=&#39;1&#39;') !== -1 && msg.innerHTML.indexOf('[fb]=&#39;1&#39;') !== -1);
  check('conflict: field not flagged invalid', sid.__qridInvalid === false);
  const ev = submitEv();
  env.doc.fire('submit', ev);
  check('conflict: save never trapped despite a hard branch', ev._prevented === false);
  // resolving the overlap restores normal validation
  b.value = '0';
  b.fire('change');
  check('resolving the overlap restores the active branch', /check character/i.test(msg.innerHTML)
    && sid.__qridInvalid === true);
  const ev2 = submitEv();
  env.doc.fire('submit', ev2);
  check('the hard branch now traps the save', ev2._prevented === true);
}

// ---- 4) blockSave is per-branch (guard "off" filter) ------------------------
{
  const sid = makeEl('input'); sid.name = 'sid'; sid.value = BAD_ID;
  const stype = makeEl('select'); stype.name = 'stype'; stype.value = '2';
  const env = boot([sid, stype], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'single', fields: ['sid'], branches: [
      { algorithm: 'iso7064_mod37_36', blockSave: 'hard', when: "[stype]='1'" },
      { algorithm: 'verhoeff', blockSave: 'off', when: "[stype]='2'" },
    ] }],
  });
  check('off branch active: field flagged but mode off', sid.__qridInvalid === true
    && sid.__qridBlockMode === 'off');
  let ev = submitEv();
  env.doc.fire('submit', ev);
  check('off branch active: invalid value does NOT trap the save', ev._prevented === false);
  stype.value = '1';
  stype.fire('change');
  ev = submitEv();
  env.doc.fire('submit', ev);
  check('hard branch active: same value now traps the save', ev._prevented === true);
}

// ---- 5) suggestFix is per-branch --------------------------------------------
{
  const sid = makeEl('input'); sid.name = 'sid'; sid.value = BAD_ID;
  const stype = makeEl('select'); stype.name = 'stype'; stype.value = '1';
  const env = boot([sid, stype], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'single', fields: ['sid'], branches: [
      { algorithm: 'iso7064_mod37_36', source: 'normalized_id', suggestFix: true, when: "[stype]='1'" },
      { algorithm: 'iso7064_mod37_36', source: 'normalized_id', when: "[stype]='2'" },
    ] }],
  });
  const msg = msgOf(env, 'sid');
  check('hint branch shows "should end in"', /should end in/i.test(msg.innerHTML));
  stype.value = '2';
  stype.fire('change');
  check('hint-less branch stays hint-free', /check character/i.test(msg.innerHTML)
    && !/should end in/i.test(msg.innerHTML));
}

// ---- 6) readonly fields never arm the blocker, branch or not ----------------
{
  const sid = makeEl('input'); sid.name = 'sid'; sid.value = BAD_ID; sid.readOnly = true;
  const stype = makeEl('select'); stype.name = 'stype'; stype.value = '1';
  const env = boot([sid, stype], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'single', fields: ['sid'], branches: [
      { algorithm: 'iso7064_mod37_36', blockSave: 'hard', when: "[stype]='1'" },
      { algorithm: 'verhoeff', when: "[stype]='2'" },
    ] }],
  });
  check('readonly: verdict still renders', /check character/i.test(msgOf(env, 'sid').innerHTML));
  check('readonly: blocker never armed (UX-003)', env.NS.guard.items.length === 0);
}

// ---- 7) the pooled factory branches identically ------------------------------
{
  const pool = makeEl('textarea'); pool.name = 'pool'; pool.value = '0ABC0000H'; // valid 9-char member
  const stype = makeEl('select'); stype.name = 'stype'; stype.value = '1';
  const env = boot([pool, stype], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'pooled', fields: ['pool'], branches: [
      { algorithm: 'iso7064_mod37_36', idLengths: [9], when: "[stype]='1'" },
      { algorithm: 'iso7064_mod37_36', idLengths: [10], when: "[stype]='2'" },
    ] }],
  });
  const msg = msgOf(env, 'pool');
  check('pooled branch 1 (9-char lengths): member verifies', pool.__qridInvalid === false
    && /1 ID read/.test(msg.innerHTML));
  stype.value = '2';
  stype.fire('change');
  check('pooled branch 2 (10-char lengths): same value now fails', pool.__qridInvalid === true);
  stype.value = '9';
  stype.fire('change');
  check('pooled no-branch: inert', msg.style.display === 'none' && pool.__qridInvalid === false);
  const reg = env.NS.validators.pool;
  check('pooled registry: branch entry with parse per branch', reg && reg.branch === true
    && typeof reg.branches[0].parse === 'function');
}

console.log(`branch_dom_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
