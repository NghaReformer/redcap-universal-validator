/*
 * growth_dom_js.cjs — @UVRANGE growth references in the browser (2.6.0).
 *
 * Drives the real QRIDRangeInit factory with a growth rule and the page's
 * copy of its table (config.growth), through the DOM stub the other *_dom
 * tests use, and asserts:
 *   - the limits apply to the z-score, read from live inputs, and the note
 *     names the z-score, the reference and the expected range,
 *   - a change to an input (sex, a date) re-checks the value,
 *   - a value that is not a number, or at or below 0, is held whatever the
 *     inputs say,
 *   - a blank input, a sex code that is neither male nor female, and an age
 *     outside the reference check nothing; staff are told why, a survey
 *     respondent is told nothing; a date still being typed says nothing,
 *   - saved inputs (a snapshot) are used but never block; withheld inputs are
 *     not guessed at: staff are told the check runs on save,
 *   - a rule with event or instance references (blockSave "off") never blocks,
 *   - a Missing Data Code in an input is a blank input,
 *   - a decimal comma in the measurement and in "by",
 *   - a table the page was not given is a configuration error, unless the
 *     rule is deferred or an input is withheld (no z-score there, so no table),
 *   - a rule deferred by the page's table cap (deferredOnSave) gets a grey
 *     note that the save checks it, not the "not checked at all" notice,
 *   - UV_validators[field].test() answers by the z-score.
 *
 * The z-score itself is parity-locked by tests/growth_js.cjs + growth_php.php.
 *
 * Run:  node tests/growth_dom_js.cjs
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
    INSPIRE_VALIDATOR_CONFIG: Object.assign({ singleFields: [], pooledFields: [] }, config),
  };
  global.document = doc; global.window = win;
  require(enginePath);
  return { doc, win, holders, allEls, NS: win.INSPIREUniversalValidator };
}
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
function el(name, value) { const e = makeEl('input'); e.name = name; e.value = value; return e; }
function shown(msg) { return !!msg && msg.style.display !== 'none' && msg.innerHTML !== ''; }
function save(env) {
  const a = env.win._alerts.length, c = env.win._confirms.length;
  const ev = submitEv(); env.doc.fire('submit', ev);
  if (env.win._alerts.length > a) return 'held';
  if (env.win._confirms.length > c) return ev._prevented ? 'asked' : 'asked-saved';
  return ev._prevented ? 'prevented' : 'saved';
}

/* A table in days: for a boy, z is (y/10 - 1)/0.1 on every day but day 3;
   for a girl, (y/8 - 1)/0.1. WHO's restricted adjustment beyond 3 SD. */
const ROWS_M = [[1, 10, 0.1], [1, 10, 0.1], [1, 10, 0.1], [0.5, 12, 0.2], [1, 10, 0.1],
  [1, 10, 0.1], [1, 10, 0.1], [1, 10, 0.1], [1, 10, 0.1], [1, 10, 0.1]];
const ROWS_F = ROWS_M.map(() => [1, 8, 0.1]);
const T_DAYS = { title: 'Test, age in days', measure: 'weight', unit: 'kg', axis: 'age', axisUnit: 'days', lookup: 'round',
  valid: { min: '0', below: '10' }, adjust: 'who-restricted', scale: 1, first: 0, male: ROWS_M, female: ROWS_F };
const T_LEN = { title: 'Test, by length', measure: 'weight', unit: 'kg', axis: 'length', axisUnit: 'cm', lookup: 'linear',
  valid: { min: '45', max: '45.3' }, adjust: 'none', scale: 10, first: 450,
  male: [[1, 2, 0.1], [1, 2.2, 0.1], [1, 2.4, 0.1], [1, 2.6, 0.1]], female: [[1, 2, 0.1], [1, 2.2, 0.1], [1, 2.4, 0.1], [1, 2.6, 0.1]] };
const GROWTH = { 't-days': T_DAYS, 't-len': T_LEN };
const LIVE = { rangeSexOp: ['ref', 'sex', null], rangeAgeDobOp: ['ref', 'dob', null], rangeAgeAtOp: ['ref', 'visit_date', null],
  rangeDobType: 'date', rangeDobFormat: 'dmy', rangeAtType: 'date', rangeAtFormat: 'ymd' };
function rule(extra) {
  return Object.assign({ type: 'range', fields: ['weight'], rangeReference: 't-days', rangeMale: '1', rangeFemale: '2',
    rangeSoftLo: '-2', rangeSoftHi: '2', rangeHardLo: '-5', rangeHardHi: '5',
    rangeSoftText: 'z-score -2 to 2', rangeHardText: 'z-score -5 to 5' }, extra);
}
/* sex, a dob shown day-month-year, a visit date year-month-day, the weight: 2 days old */
function inputs(sex, w) { return [el('sex', sex), el('dob', '01-01-2026'), el('visit_date', '2026-01-03'), el('weight', w)]; }

// ---- 1) the z-score against the limits, live inputs -------------------------------
{
  const els = inputs('1', '11');
  const [sex, dob, at, w] = els;
  const env = boot(els, { growth: GROWTH, rules: [rule(LIVE)] });
  const msg = nMsg(env, 'weight');
  check('z 1.00: usual, says nothing', !shown(msg) && save(env) === 'saved');
  w.value = '13'; w.fire('change');
  check('z 3.00: unusual, amber, names the z-score, the reference and the range (got ' + msg.innerHTML + ')',
    /^&#9888; This value is higher than usual \(z-score 3\.00 on Test, age in days; expected z-score -2 to 2\)\.$/.test(msg.innerHTML));
  check('z 3.00: the save asks first', save(env) === 'asked');
  w.value = '16'; w.fire('change');
  check('z 6.00 (restricted): implausible, held', /above the plausible range \(z-score 6\.00 on Test, age in days; allowed z-score -5 to 5\)/.test(msg.innerHTML)
    && save(env) === 'held');
  w.value = '6.9'; w.fire('change');
  check('z -3.10: lower than usual', /lower than usual \(z-score -3\.10/.test(msg.innerHTML));
  // a change to an input re-checks the value
  w.value = '11'; w.fire('change');
  check('back to usual', !shown(msg));
  sex.value = '2'; sex.fire('change');
  check('sex to female: 11 kg is z 3.75 on the female rows, unusual', /z-score 3\.75/.test(msg.innerHTML));
  at.value = '2026-01-04'; at.fire('change');
  check('visit a day later: the female rows are the same, still 3.75', /z-score 3\.75/.test(msg.innerHTML));
  sex.value = '1'; sex.fire('change');
  check('a boy on day 3: that row is L 0.5, M 12, S 0.2, so 11 kg is usual', !shown(msg));
  dob.value = '02-01-2026'; dob.fire('change');
  check('date of birth a day later: day 2 again, z 1.00, usual', !shown(msg));
  check('test(): usual is true', env.NS.validators.weight.test() === true);
}

// ---- 2) not a number, at or below 0: held whatever the inputs say -----------------
{
  const els = inputs('', 'abc');
  const w = els[3];
  const env = boot(els, { growth: GROWTH, rules: [rule(LIVE)] });
  const msg = nMsg(env, 'weight');
  check('not a number: red, held, even with no sex', /^&#10007; This is not a number\.$/.test(msg.innerHTML) && save(env) === 'held');
  w.value = '0'; w.fire('change');
  check('0: a measurement must be above 0, held', /^&#10007; A measurement must be above 0\.$/.test(msg.innerHTML) && save(env) === 'held');
  w.value = '-2'; w.fire('change');
  check('below 0: the same', /must be above 0/.test(msg.innerHTML));
  w.value = ''; w.fire('change');
  check('blank: nothing', !shown(msg) && save(env) === 'saved');
}

// ---- 3) inputs that check nothing: why, for staff only -------------------------------
{
  const els = inputs('', '16');
  const [sex, dob, at, w] = els;
  const env = boot(els, { growth: GROWTH, rules: [rule(LIVE)] });
  const msg = nMsg(env, 'weight');
  check('no sex: a grey note says why, never blocks', /^&#8505; Not checked against Test, age in days: the sex is blank\.$/.test(msg.innerHTML)
    && /#f5f5f5/.test(msg.style.cssText) && save(env) === 'saved');
  sex.value = '3'; sex.fire('change');
  check('sex code 3: neither male nor female', /the sex code is neither 1 \(male\) nor 2 \(female\)\./.test(msg.innerHTML) && save(env) === 'saved');
  sex.value = '1'; sex.fire('change');
  check('sex 1: implausible again', /plausible/.test(msg.innerHTML) && save(env) === 'held');
  at.value = '2026-02-01'; at.fire('change');
  check('31 days old: outside the reference, says so', /the age is outside the reference \(0 to under 10 days\)\./.test(msg.innerHTML)
    && save(env) === 'saved');
  at.value = '2025-12-31'; at.fire('change');
  check('measured before birth: says so', /the measurement is dated before the birth\./.test(msg.innerHTML));
  at.value = ''; at.fire('change');
  check('no visit date: says so', /the date of birth or the date of the measurement is blank\./.test(msg.innerHTML));
  at.value = '2026-01-0'; at.fire('change');
  check('a date still being typed: nothing at all', !shown(msg) && save(env) === 'saved');
}
{
  const els = inputs('', '16');
  const env = boot(els, { context: 'survey', growth: GROWTH, rules: [rule(LIVE)] });
  check('survey: no note about the inputs', !shown(nMsg(env, 'weight')));
}

// ---- 4) saved inputs, withheld inputs, event/instance rules --------------------------
{
  const w = el('weight', '13');
  const env = boot([w], { growth: GROWTH, rules: [rule({ rangeSexOp: ['lit', '1'], rangeAgeDobOp: ['lit', '2026-01-01'],
    rangeAgeAtOp: ['lit', '2026-01-03'], rangeDobFormat: 'dmy', rangeAtFormat: 'dmy', snapshotFields: ['sex', 'dob', 'visit_date'] })] });
  const msg = nMsg(env, 'weight');
  check('saved inputs: read as Y-M-D whatever the field shows, z 3.00', /z-score 3\.00/.test(msg.innerHTML));
  check('saved inputs: the note says they were read when the page opened', /read when this page was opened/.test(msg.innerHTML));
  check('saved inputs: named as inputs, not as a choice of limits',
    /\(worked out with sex, dob, visit_date, read when/.test(msg.innerHTML) && !/limits chosen/.test(msg.innerHTML));
  w.value = '16'; w.fire('change');
  check('saved inputs: never block', save(env) === 'saved');
}
{
  const w = el('weight', '16');
  const env = boot([w], { growth: GROWTH, rules: [rule({ rangeSexOp: ['withheld'], rangeAgeDobOp: ['withheld'],
    rangeAgeAtOp: ['lit', '2026-01-03'] })] });
  const msg = nMsg(env, 'weight');
  check('withheld inputs: not guessed at; staff told it runs on save',
    /Not checked against Test, age in days on this page: an input is on a form you cannot view here\. It is checked when the record is saved\./.test(msg.innerHTML)
    && save(env) === 'saved');
  w.value = 'abc'; w.fire('change');
  check('withheld inputs: a value that is not a number is still held', /not a number/.test(msg.innerHTML) && save(env) === 'held');
}
{
  // The server sends no table for a rule with a withheld input.
  const w = el('weight', '16');
  const env = boot([w], { rules: [rule({ rangeSexOp: ['withheld'], rangeAgeDobOp: ['lit', '2026-01-01'],
    rangeAgeAtOp: ['lit', '2026-01-03'] })] });
  const msg = nMsg(env, 'weight');
  check('withheld, no table: no configuration error', !cfgErr(env, 'weight'));
  check('withheld, no table: the note names no reference, runs on save',
    /Not checked against its growth reference on this page: an input is on a form you cannot view here\./.test(msg.innerHTML) && save(env) === 'saved');
  w.value = '0'; w.fire('change');
  check('withheld, no table: 0 is still held', /must be above 0/.test(msg.innerHTML) && save(env) === 'held');
}
{
  const w = el('weight', '16');
  const env = boot([w], { context: 'survey', growth: GROWTH, rules: [rule({ rangeSexOp: ['withheld'], rangeAgeDobOp: ['withheld'],
    rangeAgeAtOp: ['withheld'] })] });
  check('survey, withheld: says nothing', !shown(nMsg(env, 'weight')) && save(env) === 'saved');
}
{
  const els = inputs('1', '16');
  const env = boot(els, { growth: GROWTH, rules: [rule(Object.assign({ blockSave: 'off' }, LIVE))] });
  check('event/instance rule (blockSave off): noted, never blocks', /plausible/.test(nMsg(env, 'weight').innerHTML) && save(env) === 'saved');
}

// ---- 5) Missing Data Codes, decimal commas, inputs by age in days and by length ------
{
  const els = inputs('1', '16');
  els[1].value = 'UNK';
  const env = boot(els, { missingCodes: ['UNK'], growth: GROWTH, rules: [rule(LIVE)] });
  check('a code in the date of birth: blank', /the date of birth or the date of the measurement is blank/.test(nMsg(env, 'weight').innerHTML)
    && save(env) === 'saved');
}
{
  const age = el('age_days', '2,5'), w = el('weight', '11');
  const env = boot([age, w], { growth: GROWTH, rules: [rule({ rangeSexOp: ['lit', '1'], rangeAgeDaysOp: ['ref', 'age_days', null],
    rangeAxisComma: true, decimalComma: true })] });
  const msg = nMsg(env, 'weight');
  check('age 2,5 days rounds to day 3: 11 kg usual there', !shown(msg));
  age.value = '2,4'; age.fire('change');
  check('age 2,4 days: day 2, 11 kg is z 1.00, usual', !shown(msg));
  w.value = '13,5'; w.fire('change');
  check('13,5 kg read with its comma: z 3.50', /z-score 3\.50/.test(msg.innerHTML));
  age.value = 'x'; age.fire('change');
  check('an age that is not a number: says so', /the age is not a number/.test(msg.innerHTML));
}
{
  const len = el('len', '45.3'), w = el('weight', '2.6');
  const env = boot([len, w], { growth: GROWTH, rules: [rule({ fields: ['weight'], rangeReference: 't-len', rangeSexOp: ['lit', '2'],
    rangeByOp: ['ref', 'len', null] })] });
  const msg = nMsg(env, 'weight');
  check('by length: 2.6 kg at 45.3 cm, z 0.00', !shown(msg));
  len.value = '45.31'; len.fire('change');
  check('by length: past the reference, says so', /the length is outside the reference \(45 to 45\.3 cm\)/.test(msg.innerHTML));
  len.value = '45,1'; len.fire('change');
  check('by length: a comma without the comma flag is not a number', /the length is not a number/.test(msg.innerHTML));
}

// ---- 6) configuration: the table, deferral, escaping -----------------------------------
{
  const els = inputs('1', '16');
  const env = boot(els, { rules: [rule(LIVE)] });
  check('no table on the page: configuration error', cfgErr(env, 'weight')
    && /growth reference &quot;t-days&quot; was not sent to this page|growth reference "t-days" was not sent to this page/.test(cfgErr(env, 'weight').innerHTML));
  check('no table: never blocks', save(env) === 'saved');
}
{
  const CAP = ['this form already carries 4 growth reference tables, the most one page may carry (4), so this check runs when the record is saved.'];
  const els = inputs('1', '16');
  const env = boot(els, { rules: [rule(Object.assign({ deferred: true, deferredOnSave: true, deferredWhy: CAP }, LIVE))] });
  const msg = nMsg(env, 'weight');
  check('deferred for the table cap: no configuration error', !cfgErr(env, 'weight'));
  check('deferred: says why, never blocks', /already carries 4 growth reference tables/.test(msg.innerHTML) && save(env) === 'saved');
  check('deferred for the cap: a grey note that the save checks it, not "not checked after saving" (got ' + msg.innerHTML + ')',
    /^&#8505; Not checked on this page: this form already carries 4/.test(msg.innerHTML) && !/not checked after saving/i.test(msg.innerHTML));
  // A rule with branches: no branch applies, the rule-level note is the same.
  const els2 = inputs('', '16');
  const env2 = boot(els2, { rules: [{ type: 'range', fields: ['weight'], deferred: true, deferredOnSave: true, deferredWhy: CAP,
    branches: [rule(Object.assign({ when: "[sex]='1'", deferred: true, deferredOnSave: true }, LIVE))] }] });
  check('deferred for the cap, branches, none active: the same grey note (got ' + nMsg(env2, 'weight').innerHTML + ')',
    /^&#8505; Not checked on this page: this form already carries 4/.test(nMsg(env2, 'weight').innerHTML));
  // Any other deferral keeps its own notice.
  const env3 = boot(inputs('1', '16'), { rules: [rule(Object.assign({ deferred: true, deferredWhy: ['Extended reference unavailable: x.'] }, LIVE))] });
  check('another deferral: the "not checked after saving either" notice', /not checked after saving either/.test(nMsg(env3, 'weight').innerHTML));
  // A survey respondent is told nothing.
  const env4 = boot(inputs('1', '16'), { context: 'survey', rules: [rule(Object.assign({ deferred: true, deferredOnSave: true, deferredWhy: CAP }, LIVE))] });
  check('deferred for the cap, survey: says nothing', !shown(nMsg(env4, 'weight')));
}
{
  const els = inputs('1', '16');
  const env = boot(els, { growth: GROWTH, rules: [rule({ rangeSexOp: ['ref', 'sex', null] })] });
  check('no age input: configuration error', cfgErr(env, 'weight') && /cannot read the inputs of its growth reference/.test(cfgErr(env, 'weight').innerHTML));
}
{
  const els = inputs('', '16');
  const g = { 't-days': Object.assign({}, T_DAYS, { title: 'WHO <b>2006</b>' }) };
  const env = boot(els, { growth: g, rules: [rule(LIVE)] });
  check('the reference title is escaped', /Not checked against WHO &lt;b&gt;2006&lt;\/b&gt;: the sex is blank/.test(nMsg(env, 'weight').innerHTML));
  els[0].value = '1'; els[0].fire('change');
  check('... in the verdict too', /on WHO &lt;b&gt;2006&lt;\/b&gt;/.test(nMsg(env, 'weight').innerHTML));
}
{
  const els = inputs('1', '16');
  const env = boot(els, { growth: GROWTH, rules: [rule(Object.assign({ message: 'Weigh again.' }, LIVE))] });
  check('a custom message replaces the wording', /^&#10007; Weigh again\.$/.test(nMsg(env, 'weight').innerHTML));
}

// ---- 7) test() ---------------------------------------------------------------------------
{
  const els = inputs('1', '13');
  const [sex, , , w] = els;
  const env = boot(els, { growth: GROWTH, rules: [rule(LIVE)] });
  const t = () => env.NS.validators.weight.test();
  check('test(): unusual is false', t() === false);
  w.value = '11';
  check('test(): usual is true', t() === true);
  sex.value = '';
  check('test(): no sex is null (not checked)', t() === null);
  w.value = 'abc';
  check('test(): not a number is false', t() === false);
}

console.log('growth_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
