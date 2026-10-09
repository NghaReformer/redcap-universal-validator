/*
 * range_dom_js.cjs — the @UVRANGE range mode's DOM contract.
 *
 * Drives the real QRIDRangeInit factory through the same DOM stub the other
 * *_dom tests use, and asserts:
 *   - a value inside the soft range says nothing and never blocks,
 *   - outside "soft": an amber note naming the expected range, and the save
 *     asks first (softBlock confirm, the default) or goes through (off),
 *   - outside "hard", or not a number: a red note naming the allowed range,
 *     and the save is held (hardBlock hard, the default) or asks (confirm),
 *   - a blank value is inert,
 *   - typing clears the note and leaving the field judges the value, while
 *     the save-time recheck still catches a value that was never left,
 *   - a comma-decimal field reads 17,5 as 17.5,
 *   - a calc (rangeComputed) and a read-only input never block,
 *   - a snapshot branch selector and a deferred rule never block,
 *   - branched limits follow the live selector,
 *   - the custom message replaces the default wording and is escaped,
 *   - a jQuery-only change (a slider, a script) re-checks the field,
 *   - a rule with no limits, or a bad block setting, is a configuration error.
 *
 * The verdict itself is parity-locked by tests/range_js.cjs + range_php.php;
 * this file tests the DOM wiring around it.
 *
 * Run:  node tests/range_dom_js.cjs
 */
'use strict';
const path = require('path');

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), id: '', name: '', value: '', innerHTML: '',
    type: '', checked: false,
    style: { set cssText(v) { this._css = v; const m = /display:\s*([a-z]+)/.exec(v); this.display = m ? m[1] : ''; },
             get cssText() { return this._css || ''; } },
    _attrs: {}, children: [], parentNode: null, readOnly: false, disabled: false,
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

function boot(els, config, prep) {
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
    body, readyState: 'complete', _handlers: {}, activeElement: null,
    createElement(t) { const e = makeEl(t); allEls.push(e); return e; },
    getElementById(id) { return allEls.find((e) => e.id === id) || null; },
    getElementsByName(name) { return allEls.filter((e) => e.name === name); },
    querySelector() { return null; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev)); },
  };
  const win = {
    _alerts: [], _confirms: [], confirmAnswer: false,
    alert(m) { this._alerts.push(m); }, confirm(m) { this._confirms.push(m); return this.confirmAnswer; },
    INSPIRE_VALIDATOR_CONFIG: Object.assign({ singleFields: [], pooledFields: [] }, config),
  };
  if (prep) prep(win);
  global.document = doc; global.window = win;
  require(enginePath);
  return { doc, win, holders, allEls, NS: win.INSPIREUniversalValidator };
}
/* The range mode's status region: the region whose id ends in "-n". */
function nMsg(env, field) {
  const kids = env.holders[field].children;
  for (let i = 1; i < kids.length; i++) if (kids[i].id && /-n$/.test(kids[i].id)) return kids[i];
  return null;
}
function cfgErr(env, field) {
  const kids = env.holders[field].children;
  for (let i = 1; i < kids.length; i++) if (kids[i].id && /-cfg$/.test(kids[i].id)) return kids[i];
  return null;
}
function submitEv() {
  return { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
}
function numEl(name, value) { const e = makeEl('input'); e.name = name; e.value = value; return e; }
function shown(msg) { return !!msg && msg.style.display !== 'none' && msg.innerHTML !== ''; }
/* Submit and say what the guard did: "held" (alert), "asked" (confirm, answered no) or "saved". */
function save(env) {
  const a = env.win._alerts.length, c = env.win._confirms.length;
  const ev = submitEv(); env.doc.fire('submit', ev);
  if (env.win._alerts.length > a) return 'held';
  if (env.win._confirms.length > c) return ev._prevented ? 'asked' : 'asked-saved';
  return ev._prevented ? 'prevented' : 'saved';
}
const HB = { type: 'range', fields: ['hb'], rangeSoftLo: '12', rangeSoftHi: '17.5', rangeHardLo: '3', rangeHardHi: '25',
  rangeUnit: 'g/dL', rangeSoftText: '12 to 17.5 g/dL', rangeHardText: '3 to 25 g/dL' };

// ---- 1) the tiers, default enforcement --------------------------------------
{
  const hb = numEl('hb', '14');
  const env = boot([hb], { rules: [Object.assign({}, HB)] });
  const msg = nMsg(env, 'hb');
  check('inside soft: no note', !shown(msg) && hb.getAttribute('aria-invalid') === null);
  check('inside soft: no outline', hb.style.outline === '');
  check('inside soft: saves', save(env) === 'saved');

  hb.value = '18'; hb.fire('change');
  check('above soft: amber note', shown(msg) && /^&#9888; /.test(msg.innerHTML) && /#fdf8e6/.test(msg.style.cssText));
  check('above soft: names the expected range', /higher than usual \(expected 12 to 17\.5 g\/dL\)/.test(msg.innerHTML));
  check('above soft: amber outline, not invalid', /#b7791f/.test(hb.style.outline) && hb.getAttribute('aria-invalid') === null);
  check('above soft: the save asks first (confirm by default)', save(env) === 'asked');

  hb.value = '11.9'; hb.fire('change');
  check('below soft: lower than usual', /lower than usual/.test(msg.innerHTML));

  hb.value = '25.1'; hb.fire('change');
  check('above hard: red note', /^&#10007; /.test(msg.innerHTML) && /#fbeceb/.test(msg.style.cssText));
  check('above hard: names the allowed range', /above the plausible range \(allowed 3 to 25 g\/dL\)/.test(msg.innerHTML));
  check('above hard: aria-invalid', hb.getAttribute('aria-invalid') === 'true');
  check('above hard: the save is held (hard by default)', save(env) === 'held');

  hb.value = '2'; hb.fire('change');
  check('below hard: below the plausible range', /below the plausible range/.test(msg.innerHTML));

  hb.value = 'abc'; hb.fire('change');
  check('not a number: red, said plainly', /^&#10007; This is not a number\./.test(msg.innerHTML));
  check('not a number: held', save(env) === 'held');

  hb.value = '25'; hb.fire('change');
  check('hard edge is inside hard (soft-high only)', /higher than usual/.test(msg.innerHTML));
  hb.value = '17.50'; hb.fire('change');
  check('17.50 equals the soft edge: no note', !shown(msg));

  hb.value = '  '; hb.fire('change');
  check('blank: inert', !shown(msg) && hb.style.outline === '' && save(env) === 'saved');

  check('validators registry: range test', env.NS.validators.hb.type === 'range');
  hb.value = '30';
  check('validators registry: false outside', env.NS.validators.hb.test() === false);
  hb.value = '15';
  check('validators registry: true inside', env.NS.validators.hb.test() === true);
  hb.value = '';
  check('validators registry: null when blank', env.NS.validators.hb.test() === null);
}

{
  // "Save anyway" opens a short pass for the save it starts, so it gets its own page.
  const hb = numEl('hb', '18');
  const env = boot([hb], { rules: [Object.assign({}, HB)] });
  env.win.confirmAnswer = true;
  check('above soft: "save anyway" lets it through', save(env) === 'asked-saved');
}

// ---- 2) softBlock / hardBlock --------------------------------------------------
{
  const v = numEl('sbp', '150');
  const env = boot([v], { rules: [{ type: 'range', fields: ['sbp'], rangeSoftHi: '140', rangeHardLo: '40', rangeHardHi: '250',
    rangeSoftBlock: 'off', rangeHardBlock: 'confirm', rangeSoftText: 'at most 140 mmHg', rangeHardText: '40 to 250 mmHg' }] });
  const msg = nMsg(env, 'sbp');
  check('softBlock off: still noted', /higher than usual \(expected at most 140 mmHg\)/.test(msg.innerHTML));
  check('softBlock off: saves without asking', save(env) === 'saved');
  v.value = '300'; v.fire('change');
  check('hardBlock confirm: asks instead of holding', save(env) === 'asked');
  v.value = '-1'; v.fire('change');
  check('open soft low: under hard is still hard', /below the plausible range/.test(msg.innerHTML));
}

// ---- 3) typing clears; leaving judges; the save rechecks ---------------------
{
  const hb = numEl('hb', '');
  const env = boot([hb], { rules: [Object.assign({}, HB)] });
  const msg = nMsg(env, 'hb');
  env.doc.activeElement = hb;
  hb.value = '1'; hb.fire('input');
  check('typing: "1" on the way to "12" says nothing', !shown(msg));
  hb.value = '30'; hb.fire('change'); hb.fire('blur');
  env.doc.activeElement = null;
  check('leaving the field: judged', /above the plausible range/.test(msg.innerHTML));
  env.doc.activeElement = hb;
  hb.value = '3'; hb.fire('input');
  check('typing again clears the note', !shown(msg));
  hb.value = '300';
  check('save without leaving: the recheck holds it', save(env) === 'held');
  check('...and the note is back', /above the plausible range/.test(msg.innerHTML));
  // Typed away and back to the value the field had when entered: the browser
  // fires no change event on leaving, only blur. The note must come back.
  env.doc.activeElement = hb;
  hb.value = '3001'; hb.fire('input');
  hb.value = '300'; hb.fire('input');
  check('typing back to the same value clears the note', !shown(msg));
  env.doc.activeElement = null;
  hb.fire('blur');
  check('leaving without a change event: judged again', /above the plausible range/.test(msg.innerHTML));
}

// ---- 4) comma decimals ---------------------------------------------------------
{
  const t = numEl('temp_c', '37,5');
  const env = boot([t], { rules: [{ type: 'range', fields: ['temp_c'], rangeSoftLo: '36', rangeSoftHi: '37.5',
    rangeHardLo: '30', rangeHardHi: '43', decimalComma: true, rangeSoftText: '36 to 37,5 °C', rangeHardText: '30 to 43 °C' }] });
  const msg = nMsg(env, 'temp_c');
  check('comma: 37,5 is the soft edge', !shown(msg));
  t.value = '37,6'; t.fire('change');
  check('comma: 37,6 is unusual, said with a comma', /expected 36 to 37,5 °C/.test(msg.innerHTML));
  t.value = '1.200,5'; t.fire('change');
  check('comma: a thousands point is not a number', /not a number/.test(msg.innerHTML));
}
{
  const t = numEl('temp_c', '37,6');
  const env = boot([t], { rules: [{ type: 'range', fields: ['temp_c'], rangeHardLo: '30', rangeHardHi: '43' }] });
  check('point field: a comma is not a number', /not a number/.test(nMsg(env, 'temp_c').innerHTML));
}

// ---- 5) calc, read-only ------------------------------------------------------
{
  const c = numEl('bmi', '70');
  const env = boot([c], { rules: [{ type: 'range', fields: ['bmi'], rangeHardLo: '10', rangeHardHi: '60', rangeComputed: true }] });
  check('calc: noted', /above the plausible range/.test(nMsg(env, 'bmi').innerHTML));
  check('calc: never blocks', save(env) === 'saved');
}
{
  const c = numEl('ro', '70'); c.readOnly = true;
  const env = boot([c], { rules: [{ type: 'range', fields: ['ro'], rangeHardLo: '10', rangeHardHi: '60' }] });
  check('read-only: noted', /above the plausible range/.test(nMsg(env, 'ro').innerHTML));
  check('read-only: never blocks', save(env) === 'saved');
}

// ---- 6) snapshot, deferred ------------------------------------------------------
{
  const hb = numEl('hb', '30');
  const env = boot([hb], { rules: [Object.assign({}, HB, { when: "[sex]='1'", whenAst: ['const', true], snapshotFields: ['sex'] })] });
  const msg = nMsg(env, 'hb');
  check('snapshot: noted', /above the plausible range/.test(msg.innerHTML));
  check('snapshot: says where the limits came from', /limits chosen from sex, read when this page was opened/.test(msg.innerHTML));
  check('snapshot: never blocks', save(env) === 'saved');
}
{
  const hb = numEl('hb', '30');
  const env = boot([hb], { context: 'survey', rules: [Object.assign({}, HB, { when: "[sex]='1'", whenAst: ['const', true], snapshotFields: ['sex'] })] });
  const msg = nMsg(env, 'hb');
  check('survey snapshot: no field name', shown(msg) && !/sex/.test(msg.innerHTML));
}
{
  const hb = numEl('hb', '30');
  const env = boot([hb], { rules: [{ type: 'range', fields: ['hb'], deferred: true, deferredWhy: ['[sex] is on a form you cannot view'] }] });
  check('deferred stub: no configuration error', !cfgErr(env, 'hb'));
  check('deferred: never blocks', save(env) === 'saved');
  check('deferred: says why on staff forms', /cannot view/.test(nMsg(env, 'hb').innerHTML));
}

// ---- 7) branches by a live selector ---------------------------------------------
{
  const sex = numEl('sex', '1');
  const hb = numEl('hb', '13');
  const base = { rangeHardLo: '3', rangeHardHi: '25', rangeHardText: '3 to 25 g/dL' };
  const env = boot([sex, hb], { rules: [{ type: 'range', fields: ['hb'], branches: [
    Object.assign({}, base, { when: "[sex]='2'", rangeSoftLo: '12', rangeSoftHi: '15.5', rangeSoftText: '12 to 15.5 g/dL' }),
    Object.assign({}, base, { when: "[sex]='1'", rangeSoftLo: '13.5', rangeSoftHi: '17.5', rangeSoftText: '13.5 to 17.5 g/dL' }) ] }] });
  const msg = nMsg(env, 'hb');
  check('male branch: 13 is lower than usual', /lower than usual \(expected 13\.5 to 17\.5 g\/dL\)/.test(msg.innerHTML));
  sex.value = '2'; sex.fire('change');
  check('switch to female: 13 is usual', !shown(msg));
  hb.value = '16'; hb.fire('change');
  check('female: 16 is higher than usual', /expected 12 to 15\.5 g\/dL/.test(msg.innerHTML));
  sex.value = ''; sex.fire('change');
  check('no selector value: no branch, inert', !shown(msg) && save(env) === 'saved');
}

// ---- 8) message, escaping --------------------------------------------------------
{
  const w = numEl('w', '0.4');
  const env = boot([w], { rules: [{ type: 'range', fields: ['w'], rangeHardLo: '0.5', rangeHardHi: '250',
    message: 'Check the <b>scale</b>.', rangeHardText: '0.5 to 250 <kg>' }] });
  const msg = nMsg(env, 'w');
  check('custom message replaces the wording, escaped', /Check the &lt;b&gt;scale&lt;\/b&gt;\./.test(msg.innerHTML)
    && !/plausible/.test(msg.innerHTML));
}
{
  const w = numEl('w', '0.4');
  const env = boot([w], { rules: [{ type: 'range', fields: ['w'], rangeHardLo: '0.5', rangeHardText: 'at least 0.5 <kg>' }] });
  check('range text escaped', /at least 0\.5 &lt;kg&gt;/.test(nMsg(env, 'w').innerHTML));
}

// ---- 9) a jQuery-only change re-checks the field ----------------------------------
{
  function jq(el) { return {
    on(events, fn) { events.split(' ').forEach((e) => { const name = e.split('.')[0];
      (el._jqHandlers || (el._jqHandlers = {}))[name] = (el._jqHandlers[name] || []).concat(fn); }); return this; } }; }
  jq.fn = { on: true };
  const jqFire = (el, name) => ((el._jqHandlers && el._jqHandlers[name]) || []).forEach((fn) => fn({}));
  const p = numEl('pain', '3');
  const env = boot([p], { rules: [{ type: 'range', fields: ['pain'], rangeSoftLo: '0', rangeSoftHi: '7', rangeSoftText: '0 to 7' }] },
    (win) => { win.jQuery = jq; });
  const msg = nMsg(env, 'pain');
  check('slider: inside', !shown(msg));
  p.value = '9'; jqFire(p, 'change');
  check('slider: a jQuery-only change re-checks', /expected 0 to 7/.test(msg.innerHTML));
  env.doc.activeElement = p;
  p.value = '3'; jqFire(p, 'input');
  check('jQuery input while in the field: no verdict yet', /expected 0 to 7/.test(msg.innerHTML));
}

// ---- 10) configuration errors -----------------------------------------------------
{
  const v = numEl('x', '5');
  const env = boot([v], { rules: [{ type: 'range', fields: ['x'] }] });
  check('no limits: configuration error', cfgErr(env, 'x') && /no limits to check/.test(cfgErr(env, 'x').innerHTML));
  check('no limits: never blocks', save(env) === 'saved');
}
{
  const v = numEl('x', '5');
  const env = boot([v], { rules: [{ type: 'range', fields: ['x'], rangeHardLo: '0', rangeSoftBlock: 'hard' }] });
  check('softBlock hard: configuration error', cfgErr(env, 'x') && /softBlock must be/.test(cfgErr(env, 'x').innerHTML));
}
{
  const v = numEl('x', '5');
  const env = boot([v], { rules: [{ type: 'range', fields: ['x'], rangeHardLo: '1e3' }] });
  check('a limit that is not a number: configuration error', cfgErr(env, 'x') && /not a number/.test(cfgErr(env, 'x').innerHTML));
}

// ---- 11) composes with another mode on the same field ---------------------------
{
  const v = numEl('hb', '14');
  const env = boot([v], { rules: [Object.assign({}, HB),
    { type: 'constraint', fields: ['hb'], assert: "[hb]<>'14'", blockSave: 'hard' }] });
  check('compose: a usual range does not clear a failing constraint', save(env) === 'held');
  v.value = '30'; v.fire('change');
  check('compose: range holds on its own', save(env) === 'held' && /plausible/.test(nMsg(env, 'hb').innerHTML));
}

console.log('range_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
