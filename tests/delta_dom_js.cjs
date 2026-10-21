/*
 * delta_dom_js.cjs — the @UVDELTA mode's DOM contract.
 *
 * Drives the real QRIDDeltaInit factory through the same DOM stub the other
 * *_dom tests use, and asserts:
 *   - a "vs" field on this page is read as it is typed: a change within the
 *     limits says nothing, a larger one is a red note naming the change, its
 *     percent, the earlier value and the limit, and the save follows
 *     blockSave (off by default, confirm, hard),
 *   - changing the "vs" field re-checks the change,
 *   - a blank value, a blank earlier value and a Missing Data Code on either
 *     side check nothing, and a "vs" that is the field itself is no change,
 *   - an earlier value read when the page opened (a snapshot), an
 *     event/instance rule (extendedAdvisory), a calc and a deferred rule
 *     never block, and each says why,
 *   - a value withheld from this viewer, or an earlier value that is not a
 *     number, is an amber note for staff and nothing on a survey,
 *   - direction, a comma-decimal field, an earlier value of 0 (no percent),
 *     the survey wording (no label), a custom message (escaped),
 *   - typing clears the note, leaving the field judges, the save rechecks,
 *   - two tags on one field with different "vs" each say their own line,
 *   - a rule with no limit, no readable "vs", or a bad setting is a
 *     configuration error.
 *
 * The verdict itself is parity-locked by tests/delta_js.cjs + delta_php.php;
 * this file tests the DOM wiring around it.
 *
 * Run:  node tests/delta_dom_js.cjs
 */
'use strict';
const path = require('path');
/* The page-wide defaults a real page carries (UniversalValidator::defaults(), among
   them blockSave "off"): the engine fills every key a rule lacks from them. */
const PAGE_DEFAULTS = require('./page_defaults.json').defaults;

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
    INSPIRE_VALIDATOR_CONFIG: Object.assign({ singleFields: [], pooledFields: [] }, PAGE_DEFAULTS, config),
  };
  global.document = doc; global.window = win;
  require(enginePath);
  return { doc, win, holders, allEls, NS: win.INSPIREUniversalValidator };
}
/* The delta mode's status region: the region whose id ends in "-d". */
function dMsg(env, field) {
  const kids = env.holders[field].children;
  for (let i = 1; i < kids.length; i++) if (kids[i].id && /-d$/.test(kids[i].id)) return kids[i];
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
/* weight compared with w0 on this page: at most 5 kg and 10% either way */
function W(extra) {
  return Object.assign({ type: 'delta', fields: ['weight'], deltaVs: '[w0]', deltaVsOp: ['ref', 'w0', null],
    deltaVsLabel: '[w0]', deltaMax: '5', deltaMaxPct: '10', deltaUnit: 'kg', deltaText: 'a change of at most 5 kg and 10%',
    deltaGroup: '[w0] both', blockSave: 'hard' }, extra || {});
}

// ---- 1) a "vs" field on this page, blockSave hard ------------------------------
{
  const w0 = numEl('w0', '70'), w = numEl('weight', '74');
  const env = boot([w0, w], { rules: [W()] });
  const msg = dMsg(env, 'weight');
  check('a change within both limits: no note', !shown(msg) && w.getAttribute('aria-invalid') === null);
  check('... saves', save(env) === 'saved');

  w.value = '76'; w.fire('change');
  check('a rise past max: red note', shown(msg) && /^&#10007; /.test(msg.innerHTML) && /#fbeceb/.test(msg.style.cssText));
  check('... names the change, its percent, the earlier value and the vs (got ' + msg.innerHTML + ')',
    /This value rose by 6 kg \(8\.6%\) from 70 kg \(\[w0\]\)\./.test(msg.innerHTML));
  check('... and the limit', /Allowed: a change of at most 5 kg and 10%\./.test(msg.innerHTML));
  check('... aria-invalid', w.getAttribute('aria-invalid') === 'true');
  check('... the save is held (blockSave hard)', save(env) === 'held');

  w.value = '64'; w.fire('change');
  check('a fall past max: "fell by"', /This value fell by 6 kg \(8\.6%\) from 70 kg/.test(msg.innerHTML) && save(env) === 'held');
  w.value = '65'; w.fire('change');
  check('a fall of exactly max: no note (the limits are inclusive)', !shown(msg) && save(env) === 'saved');

  w0.value = '80'; w0.fire('change');
  check('changing the vs field re-checks: 65 is now 15 below 80', /fell by 15 kg \(18\.8%\) from 80 kg/.test(msg.innerHTML));
  w0.value = ''; w0.fire('change');
  check('a blank earlier value: nothing to compare with', !shown(msg) && save(env) === 'saved');
  w0.value = '70'; w0.fire('change');
  w.value = ''; w.fire('change');
  check('a blank value: inert', !shown(msg) && save(env) === 'saved');

  check('validators registry: delta test', env.NS.validators.weight.type === 'delta');
  w.value = '90';
  check('validators registry: false past the limit', env.NS.validators.weight.test() === false);
  w.value = '71';
  check('validators registry: true within', env.NS.validators.weight.test() === true);
  w.value = '';
  check('validators registry: null for a blank value (nothing to judge)', env.NS.validators.weight.test() === null);
  w.value = '90'; w0.value = 'n/a';
  check('validators registry: null when the earlier value is not a number', env.NS.validators.weight.test() === null);
  w0.value = '70';
}

// ---- 2) blockSave: confirm, and the page default ("off") ------------------------
{
  const w0 = numEl('w0', '70'), w = numEl('weight', '80');
  const env = boot([w0, w], { rules: [W({ blockSave: 'confirm' })] });
  check('blockSave confirm: asks', save(env) === 'asked');
  env.win.confirmAnswer = true;
  check('... "save anyway" goes through', save(env) === 'asked-saved');
}
{
  const w0 = numEl('w0', '70'), w = numEl('weight', '80');
  const rule = W(); delete rule.blockSave;
  const env = boot([w0, w], { rules: [rule] });
  check('no blockSave: the page default, off: noted, saves', shown(dMsg(env, 'weight')) && save(env) === 'saved');
}

// ---- 3) the percent, direction, comma -------------------------------------------
{
  const w0 = numEl('w0', '40'), w = numEl('weight', '44.5');
  const env = boot([w0, w], { rules: [W()] });
  check('inside max, past maxPct: noted with the percent (got ' + dMsg(env, 'weight').innerHTML + ')',
    /rose by 4\.5 kg \(11\.3%\) from 40 kg/.test(dMsg(env, 'weight').innerHTML));
}
{
  const w0 = numEl('w0', '0'), w = numEl('weight', '3');
  const env = boot([w0, w], { rules: [W({ deltaMaxPct: undefined, deltaMax: '1', deltaText: 'a change of at most 1 kg' })] });
  const msg = dMsg(env, 'weight');
  check('an earlier 0: no percent in the note', /rose by 3 kg from 0 kg \(\[w0\]\)\./.test(msg.innerHTML) && !/%/.test(msg.innerHTML.replace(/at most 1 kg/, '')));
}
{
  const w0 = numEl('w0', '0'), w = numEl('weight', '3');
  const env = boot([w0, w], { rules: [W({ deltaMax: undefined, deltaText: 'a change of at most 10%' })] });
  check('an earlier 0 with maxPct alone: nothing to judge', !shown(dMsg(env, 'weight')) && save(env) === 'saved');
}
{
  const h0 = numEl('h0', '150'), h = numEl('height', '149.9');
  const env = boot([h0, h], { rules: [{ type: 'delta', fields: ['height'], deltaVs: '[h0]', deltaVsOp: ['ref', 'h0', null],
    deltaVsLabel: 'the previous event', deltaMax: '0', deltaDirection: 'down', deltaUnit: 'cm', deltaText: 'no fall', blockSave: 'hard' }] });
  const msg = dMsg(env, 'height');
  check('direction down, max 0: any fall is noted (got ' + msg.innerHTML + ')',
    /fell by 0\.1 cm \(0\.1%\) from 150 cm \(the previous event\)\. Allowed: no fall\./.test(msg.innerHTML) && save(env) === 'held');
  h.value = '190'; h.fire('change');
  check('... a rise passes', !shown(msg) && save(env) === 'saved');
}
{
  const w0 = numEl('w0', '70,0'), w = numEl('weight', '72,5');
  const env = boot([w0, w], { rules: [W({ deltaMaxPct: undefined, deltaMax: '2', decimalComma: true, deltaVsComma: true,
    deltaText: 'a change of at most 2 kg' })] });
  check('comma fields: read, and said with a comma (got ' + dMsg(env, 'weight').innerHTML + ')',
    /rose by 2,5 kg \(3,6%\) from 70,0 kg/.test(dMsg(env, 'weight').innerHTML));
}
{
  const w0 = numEl('w0', '70'), w = numEl('weight', '72,5');
  const env = boot([w0, w], { rules: [W({ deltaMaxPct: undefined, deltaMax: '2' })] });
  check('a comma on a point field is not a number: REDCap\'s check names it, this one says nothing',
    !shown(dMsg(env, 'weight')) && save(env) === 'saved');
}

// ---- 4) Missing Data Codes, self, an earlier value that is not a number ----------
{
  const w0 = numEl('w0', 'UNK'), w = numEl('weight', '90');
  const env = boot([w0, w], { missingCodes: ['UNK', 'NA'], rules: [W()] });
  const msg = dMsg(env, 'weight');
  check('a Missing Data Code as the earlier value: nothing checked', !shown(msg) && save(env) === 'saved');
  w0.value = '70'; w0.fire('change');
  check('... a number again: checked', shown(msg) && save(env) === 'held');
  w.value = 'NA'; w.fire('change');
  check('a Missing Data Code as the value: nothing checked', !shown(msg) && save(env) === 'saved');
}
{
  const w = numEl('weight', '90');
  const env = boot([w], { rules: [W({ deltaVs: '[weight]', deltaVsOp: ['ref', 'weight', null] })] });
  check('a vs that is the field itself (an extended ref folded to this entry): no change, nothing said',
    !shown(dMsg(env, 'weight')) && save(env) === 'saved');
}
{
  const w0 = numEl('w0', 'n/a'), w = numEl('weight', '90');
  const env = boot([w0, w], { rules: [W()] });
  const msg = dMsg(env, 'weight');
  check('an earlier value that is not a number: an amber note for staff (got ' + msg.innerHTML + ')',
    /^&#9888; The change from \[w0\] is not checked: the value it is compared with is not a number this rule can read\./.test(msg.innerHTML)
    && /#fdf8e6/.test(msg.style.cssText));
  check('... never blocks', save(env) === 'saved');
}
{
  const w0 = numEl('w0', 'n/a'), w = numEl('weight', '90');
  const env = boot([w0, w], { context: 'survey', rules: [W()] });
  check('... and on a survey says nothing', !shown(dMsg(env, 'weight')) && save(env) === 'saved');
}

// ---- 5) snapshot, extended, withheld, deferred, calc --------------------------
{
  const w = numEl('weight', '80');
  const env = boot([w], { rules: [W({ deltaVs: '[previous-event-name][weight]', deltaVsOp: ['lit', '70'],
    deltaVsLabel: 'the previous event', extendedAdvisory: true, blockSave: 'off', snapshotFields: ['saved event/instance values'] })] });
  const msg = dMsg(env, 'weight');
  check('a saved earlier value: noted (got ' + msg.innerHTML + ')',
    /rose by 10 kg \(14\.3%\) from 70 kg \(the previous event\)/.test(msg.innerHTML));
  check('... says it was read when the page opened, and does not block',
    /compared with saved event\/instance values, read when this page was opened/.test(msg.innerHTML)
    && /does not block saving; it is re-checked after the save/.test(msg.innerHTML));
  check('... never blocks', save(env) === 'saved');
}
{
  // A snapshot never blocks, even when blockSave says hard (a plain-path fold of a value on another form).
  const w = numEl('weight', '80');
  const env = boot([w], { rules: [W({ deltaVsOp: ['lit', '70'], snapshotFields: ['w0'] })] });
  check('a snapshot with blockSave hard: noted, never blocks', shown(dMsg(env, 'weight')) && save(env) === 'saved');
}
{
  // An event/instance rule whose earlier value folded to a live field still never blocks.
  const w0 = numEl('w0', '70'), w = numEl('weight', '80');
  const env = boot([w0, w], { rules: [W({ extendedAdvisory: true })] });
  const msg = dMsg(env, 'weight');
  check('extendedAdvisory, live vs: noted, says it does not block',
    /rose by 10 kg/.test(msg.innerHTML) && /\(This check does not block saving; it is re-checked after the save\.\)/.test(msg.innerHTML));
  check('... never blocks', save(env) === 'saved');
}
{
  const w = numEl('weight', '80');
  const env = boot([w], { rules: [W({ deltaVsOp: ['withheld'], extendedAdvisory: true })] });
  const msg = dMsg(env, 'weight');
  check('a withheld earlier value: an amber note (got ' + msg.innerHTML + ')',
    /^&#9888; The change from \[w0\] is not checked on this page: that value is on a form you cannot view here\. It is checked when the record is saved\./.test(msg.innerHTML));
  check('... never blocks', save(env) === 'saved');
}
{
  const w = numEl('weight', '80');
  const env = boot([w], { context: 'survey', rules: [W({ deltaVsOp: ['withheld'], extendedAdvisory: true })] });
  check('a withheld earlier value on a survey: nothing', !shown(dMsg(env, 'weight')));
}
{
  const w = numEl('weight', '80');
  const env = boot([w], { rules: [{ type: 'delta', fields: ['weight'], deferred: true, blockSave: 'hard',
    deferredWhy: ['Extended reference unavailable: unauthorized.'] }] });
  check('deferred stub: no configuration error', !cfgErr(env, 'weight'));
  check('deferred: says why on staff forms', /unauthorized/.test(dMsg(env, 'weight').innerHTML));
  check('... and, unmarked, that the save does not check it either',
    /It is not checked after saving either/.test(dMsg(env, 'weight').innerHTML));
  check('deferred: never blocks', save(env) === 'saved');
  check('deferred: validators registry answers null', env.NS.validators.weight.test() === null);
}
{
  const w = numEl('weight', '80');
  const env = boot([w], { rules: [{ type: 'delta', fields: ['weight'], deferred: true, deferredChecked: true, blockSave: 'hard',
    deferredWhy: ['Extended reference unavailable: unauthorized.'] }] });
  check('deferred only for this viewer (deferredChecked): says the save checks it (got ' + dMsg(env, 'weight').innerHTML + ')',
    /It is checked after the record is saved\./.test(dMsg(env, 'weight').innerHTML)
    && !/not checked after saving either/.test(dMsg(env, 'weight').innerHTML));
}
{
  const w0 = numEl('w0', '70'), w = numEl('weight', '90');
  const env = boot([w0, w], { rules: [W({ deltaVsOp: ['withheld'] })] });
  check('withheld: validators registry answers null', env.NS.validators.weight.test() === null);
}
{
  const w0 = numEl('w0', '20'), c = numEl('bmi', '30');
  const env = boot([w0, c], { rules: [{ type: 'delta', fields: ['bmi'], deltaVs: '[w0]', deltaVsOp: ['ref', 'w0', null],
    deltaMax: '2', deltaComputed: true, blockSave: 'hard', deltaText: 'a change of at most 2' }] });
  check('calc: noted', /rose by 10 \(50%\) from 20/.test(dMsg(env, 'bmi').innerHTML));
  check('calc: never blocks', save(env) === 'saved');
}
{
  const w0 = numEl('w0', '70'), w = numEl('weight', '80'); w.readOnly = true;
  const env = boot([w0, w], { rules: [W()] });
  check('read-only: noted, never blocks', shown(dMsg(env, 'weight')) && save(env) === 'saved');
}

// ---- 6) the survey wording, the custom message ----------------------------------
{
  const w0 = numEl('w0', '70'), w = numEl('weight', '80');
  const env = boot([w0, w], { context: 'survey', rules: [W()] });
  const msg = dMsg(env, 'weight');
  check('survey: the note does not name what it compares with (got ' + msg.innerHTML + ')',
    /rose by 10 kg \(14\.3%\) from 70 kg\./.test(msg.innerHTML) && !/w0/.test(msg.innerHTML));
  check('survey: still blocks as blockSave says', save(env) === 'held');
}
{
  const w0 = numEl('w0', '70'), w = numEl('weight', '80');
  const env = boot([w0, w], { rules: [W({ message: 'Re-weigh <b>now</b>.' })] });
  const msg = dMsg(env, 'weight');
  check('custom message replaces the wording, escaped', /Re-weigh &lt;b&gt;now&lt;\/b&gt;\./.test(msg.innerHTML) && !/rose by/.test(msg.innerHTML));
}
{
  const w0 = numEl('w0', '70'), w = numEl('weight', '80');
  const env = boot([w0, w], { rules: [W({ deltaUnit: '<kg>', deltaVsLabel: '[<w0>]', deltaText: 'a change of at most 5 <kg>' })] });
  const msg = dMsg(env, 'weight');
  check('unit, label and limit text are escaped', /10 &lt;kg&gt;/.test(msg.innerHTML) && /\(\[&lt;w0&gt;\]\)/.test(msg.innerHTML)
    && /at most 5 &lt;kg&gt;/.test(msg.innerHTML) && !/<kg>/.test(msg.innerHTML));
}

// ---- 7) typing clears; leaving judges; the save rechecks ---------------------
{
  const w0 = numEl('w0', '70'), w = numEl('weight', '');
  const env = boot([w0, w], { rules: [W()] });
  const msg = dMsg(env, 'weight');
  env.doc.activeElement = w;
  w.value = '7'; w.fire('input');
  check('typing: "7" on the way to "71" says nothing', !shown(msg));
  w.value = '90'; w.fire('change'); w.fire('blur');
  env.doc.activeElement = null;
  check('leaving the field: judged', /rose by 20 kg/.test(msg.innerHTML));
  env.doc.activeElement = w;
  w.value = '9'; w.fire('input');
  check('typing again clears the note', !shown(msg));
  w.value = '95';
  check('save without leaving: the recheck holds it', save(env) === 'held');
  check('... and the note is back', /rose by 25 kg/.test(msg.innerHTML));
}

// ---- 8) two tags on one field: one line each ------------------------------------
{
  const prev = numEl('wp', '70'), base = numEl('wb', '50'), w = numEl('weight', '80');
  const env = boot([prev, base, w], { rules: [{ type: 'delta', fields: ['weight'], branches: [
    { deltaVs: '[wp]', deltaVsOp: ['ref', 'wp', null], deltaVsLabel: '[wp]', deltaMaxPct: '5', deltaText: 'a change of at most 5%',
      deltaGroup: '[wp] both', blockSave: 'confirm' },
    { deltaVs: '[wb]', deltaVsOp: ['ref', 'wb', null], deltaVsLabel: '[wb]', deltaMaxPct: '15', deltaText: 'a change of at most 15%',
      deltaGroup: '[wb] both', blockSave: 'hard' } ] }] });
  const msg = dMsg(env, 'weight');
  check('two comparisons failing: two lines (got ' + msg.innerHTML + ')', msg.innerHTML.split('<br>').length === 2
    && /from 70 \(\[wp\]\)/.test(msg.innerHTML) && /from 50 \(\[wb\]\)/.test(msg.innerHTML));
  check('... the stricter block wins', save(env) === 'held');
  w.value = '73.4'; w.fire('change');
  check('within 5% of 70 but 46.8% above 50: only the baseline line, held',
    msg.innerHTML.split('<br>').length === 1 && /from 50 \(\[wb\]\)/.test(msg.innerHTML) && save(env) === 'held');
  base.value = '70'; base.fire('change');
  check('both within: no note, saves', !shown(msg) && save(env) === 'saved');
  w.value = '74'; w.fire('change');
  check('past 5% of the previous value only: one line, the save asks',
    msg.innerHTML.split('<br>').length === 1 && /from 70 \(\[wp\]\)/.test(msg.innerHTML) && save(env) === 'asked');
}
{
  /* One group deferred only for this viewer (deferredChecked on the branch):
     its line says the save checks it, while the other group judges. */
  const prev = numEl('wp', '70'), w = numEl('weight', '80');
  const env = boot([prev, w], { rules: [{ type: 'delta', fields: ['weight'], branches: [
    { deltaVs: '[wp]', deltaVsOp: ['ref', 'wp', null], deltaVsLabel: '[wp]', deltaMaxPct: '5', deltaText: 'a change of at most 5%',
      deltaGroup: '[wp] both', blockSave: 'confirm' },
    { deltaVs: '[enrol_arm_1][wb]', deltaVsLabel: '[enrol_arm_1][wb]', deltaMaxPct: '15', deltaGroup: '[enrol_arm_1][wb] both',
      deferred: true, deferredChecked: true, deferredWhy: ['Extended reference unavailable: unauthorized.'] } ] }] });
  const t = dMsg(env, 'weight').innerHTML;
  check('a group deferred for this viewer alone: its line says the save checks it (got ' + t + ')',
    /\[enrol_arm_1\]\[wb\] is not being checked — Extended reference unavailable: unauthorized\. It is checked after the record is saved\./.test(t)
    && /from 70 \(\[wp\]\)/.test(t));
}
{
  // Two tags with the same comparison need their own "when": the active one judges.
  const grp = numEl('grp', 'a'), w0 = numEl('w0', '100'), w = numEl('weight', '108');
  const env = boot([grp, w0, w], { rules: [{ type: 'delta', fields: ['weight'], branches: [
    { when: "[grp]='a'", deltaVs: '[w0]', deltaVsOp: ['ref', 'w0', null], deltaMaxPct: '5', deltaGroup: '[w0] both', deltaText: 'a change of at most 5%', blockSave: 'hard' },
    { when: "[grp]='b'", deltaVs: '[w0]', deltaVsOp: ['ref', 'w0', null], deltaMaxPct: '10', deltaGroup: '[w0] both', deltaText: 'a change of at most 10%', blockSave: 'hard' } ] }] });
  const msg = dMsg(env, 'weight');
  check('when picks the branch: group a, 8% is past 5%', /at most 5%/.test(msg.innerHTML) && save(env) === 'held');
  grp.value = 'b'; grp.fire('change');
  check('... group b, 8% is within 10%', !shown(msg) && save(env) === 'saved');
  grp.value = ''; grp.fire('change');
  check('... no group: no branch applies', !shown(msg) && save(env) === 'saved');
}

// ---- 9) configuration errors -----------------------------------------------------
{
  const w = numEl('weight', '5');
  const env = boot([w], { rules: [{ type: 'delta', fields: ['weight'], deltaVs: '[w0]', deltaVsOp: ['ref', 'w0', null] }] });
  check('no limit: configuration error', cfgErr(env, 'weight') && /no limit to check/.test(cfgErr(env, 'weight').innerHTML));
  check('... never blocks', save(env) === 'saved');
}
{
  const w = numEl('weight', '5');
  const env = boot([w], { rules: [{ type: 'delta', fields: ['weight'], deltaMax: '1' }] });
  check('no vs: configuration error', cfgErr(env, 'weight') && /cannot read its &quot;vs&quot; value|cannot read its "vs" value/.test(cfgErr(env, 'weight').innerHTML));
}
{
  const w = numEl('weight', '5');
  const env = boot([w], { rules: [{ type: 'delta', fields: ['weight'], deltaVs: '[w0]', deltaMax: '1' }] });
  check('a hand-written plain [field] vs without deltaVsOp still works', !cfgErr(env, 'weight'));
}
{
  const w = numEl('weight', '5');
  const env = boot([w], { rules: [W({ blockSave: 'maybe' })] });
  check('bad blockSave: configuration error', cfgErr(env, 'weight') && /blockSave must be/.test(cfgErr(env, 'weight').innerHTML));
}
{
  const w = numEl('weight', '5');
  const env = boot([w], { rules: [W({ deltaDirection: 'left' })] });
  check('bad direction: configuration error', cfgErr(env, 'weight') && /direction/.test(cfgErr(env, 'weight').innerHTML));
}
{
  const w = numEl('weight', '5');
  const env = boot([w], { rules: [W({ deltaMax: '-1' })] });
  check('a negative limit: configuration error', cfgErr(env, 'weight') && /not a number of 0 or more/.test(cfgErr(env, 'weight').innerHTML));
}
{
  const w = numEl('weight', '5');
  const env = boot([w], { rules: [W({ deltaMaxPct: '1e3' })] });
  check('a limit with an exponent: configuration error', cfgErr(env, 'weight') && /not a number of 0 or more/.test(cfgErr(env, 'weight').innerHTML));
}

// ---- 9b) the message's numbers (review B2, V5) -----------------------------------
{
  const w0 = numEl('w0', '70'), w = numEl('weight', '77.001');
  const env = boot([w0, w], { rules: [W({ deltaMax: undefined, deltaText: 'a change of at most 10%' })] });
  check('a percent that rounds to the limit it broke reads "more than" (got ' + dMsg(env, 'weight').innerHTML + ')',
    /rose by 7\.001 kg \(more than 10%\) from 70 kg/.test(dMsg(env, 'weight').innerHTML));
}
{
  const w0 = numEl('w0', '150'), w = numEl('weight', '150.01');
  const env = boot([w0, w], { rules: [W({ deltaMax: '0', deltaMaxPct: undefined, deltaText: 'no change' })] });
  check('a percent that rounds to 0 reads "under 0.1%" (got ' + dMsg(env, 'weight').innerHTML + ')',
    /rose by 0\.01 kg \(under 0\.1%\) from 150 kg/.test(dMsg(env, 'weight').innerHTML));
}
{
  const w0 = numEl('w0', '70'), w = numEl('weight', '1e70');
  const env = boot([w0, w], { rules: [W()] });
  const t = dMsg(env, 'weight').innerHTML;
  check('a percent of a million or more is left out (got ' + t.slice(0, 120) + ')', /rose by 9{68}30 kg from 70 kg/.test(t) && !/%\)/.test(t));
}
for (const [typed, want] of [['+70', '70'], ['070', '70'], ['70.', '70'], ['70.0', '70.0'], ['-0', '0'], ['1.5E1', '15']]) {
  const w0 = numEl('w0', typed), w = numEl('weight', '90');
  const env = boot([w0, w], { rules: [W()] });
  check('the earlier value ' + JSON.stringify(typed) + ' is said as ' + want + ' (got ' + dMsg(env, 'weight').innerHTML + ')',
    new RegExp('from ' + want.replace('.', '\\.') + ' kg').test(dMsg(env, 'weight').innerHTML));
}
{
  const w0 = numEl('w0', '.5'), w = numEl('weight', '9');
  const env = boot([w0, w], { rules: [W()] });
  check('an earlier ".5" is said as 0.5', /from 0\.5 kg/.test(dMsg(env, 'weight').innerHTML));
}
{
  const w = numEl('weight', '5');
  const env = boot([w], { rules: [W({ deltaMax: undefined, deltaMaxPct: '0' })] });
  check('"maxPct": 0 on a hand-written config: a configuration error',
    cfgErr(env, 'weight') && /maxPct(&quot;|")?: 0 does not stop a change from an earlier 0/.test(cfgErr(env, 'weight').innerHTML));
}

// ---- 10) composes with another mode on the same field ---------------------------
{
  const w0 = numEl('w0', '70'), w = numEl('weight', '71');
  const env = boot([w0, w], { rules: [W(), { type: 'constraint', fields: ['weight'], assert: "[weight]<>'71'", blockSave: 'hard' }] });
  check('compose: a change within the limits does not clear a failing constraint', save(env) === 'held');
  w.value = '72'; w.fire('change');
  check('compose: the constraint passes, the change is within: saves', save(env) === 'saved');
  w.value = '90'; w.fire('change');
  check('compose: delta holds on its own', save(env) === 'held' && /rose by/.test(dMsg(env, 'weight').innerHTML));
}

// One registry entry per field, every mode composing (review B4).
{
  const w0 = numEl('w0', '70'), w = numEl('weight', '300');
  const env = boot([w0, w], { rules: [{ type: 'range', fields: ['weight'], rangeHardLo: '20', rangeHardHi: '250', rangeHardBlock: 'hard',
    rangeHardText: '20 to 250 kg' }, W({ deltaMax: undefined, deltaMaxPct: '500', deltaText: 'a change of at most 500%' })] });
  const reg = env.NS.validators.weight;
  check('two modes on one field: the entry lists both (got ' + JSON.stringify(reg.types) + ')',
    Array.isArray(reg.types) && reg.types.indexOf('range') >= 0 && reg.types.indexOf('delta') >= 0);
  check('... test() is false while the range check fails, though the change passes', reg.test() === false
    && reg.byMode.delta.test() === true && reg.byMode.range.test() === false);
  w.value = '80';
  check('... true once both pass', reg.test() === true);
}

// ---- 11) what the page hears from scripts and while typing (async) --------------
const tick = (ms) => new Promise((r) => setTimeout(r, ms));
(async () => {
  {
    /* A value written by a script raises no event (a calc, a slider, @SETVALUE):
       the silent-change poll fires the field and the change is judged (review D5). */
    const w0 = numEl('w0', '70'), w = numEl('weight', '71');
    const env = boot([w0, w], { rules: [W()] });
    w.value = '90';
    await tick(900);
    check('a value written by a script is judged without any event (got ' + dMsg(env, 'weight').innerHTML + ')',
      /rose by 20 kg/.test(dMsg(env, 'weight').innerHTML));
  }
  {
    /* Typing in the "vs" field: the change is not judged per keystroke, and is
       once the person leaves the field (review B1). */
    const w0 = numEl('w0', '70'), w = numEl('weight', '74');
    const env = boot([w0, w], { rules: [W()] });
    env.doc.activeElement = w0;
    w0.value = '7'; w0.fire('input'); w0.fire('keyup');
    check('typing "7" on the way to "70" in the vs field: no note on the delta field', !shown(dMsg(env, 'weight')));
    w0.value = '50';
    env.doc.activeElement = null; w0.fire('blur');
    await tick(10);
    check('... leaving the vs field judges the change (got ' + dMsg(env, 'weight').innerHTML + ')',
      /rose by 24 kg/.test(dMsg(env, 'weight').innerHTML));
  }
  {
    const w0 = numEl('w0', '70'), w = numEl('weight', '');
    const env = boot([w0, w], { rules: [W({ when: '[weight] > 0', whenAst: ['cmp', '>', ['ref', 'weight', null], ['lit', '0']] })] });
    env.doc.activeElement = w;
    w.value = '7'; w.fire('input');
    check('typing "7" in a field its "when" reads: no note while typing', !shown(dMsg(env, 'weight')));
    w.value = '72';
    env.doc.activeElement = null; w.fire('blur');
    await tick(10);
    check('... and nothing to say once "72" is left', !shown(dMsg(env, 'weight')));
  }
  console.log('delta_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
  process.exit(fail ? 1 : 0);
})();
