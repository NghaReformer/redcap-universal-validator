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
 *   - a rule with event or instance references (extendedAdvisory) never blocks,
 *     while the page-wide default blockSave "off" (page_defaults.json) does not
 *     make an ordinary rule advisory,
 *   - a Missing Data Code in an input is a blank input,
 *   - a decimal comma in the measurement and in "by",
 *   - a table the page was not given is a configuration error, unless the
 *     rule is deferred, an input is withheld (no z-score there, so no table),
 *     or the server settled its condition false (growthIdle),
 *   - a rule deferred by the page's table cap (deferredOnSave) gets a grey
 *     note that the save checks it, not the "not checked at all" notice, and
 *     nothing at all where its condition is false,
 *   - UV_validators[field].test() answers by the z-score,
 *   - several references on one field (branch groups): each judges, the note
 *     has a line for each that has something to say, the worst decides the
 *     colour and the guard, and two active branches of one reference are a
 *     conflict; a reference that is not checked says so in the notice's own
 *     words, on a Missing Data Code too, and a selector that cannot be
 *     resolved (on the server or here) sets aside its own reference only,
 *   - a survey respondent is not told why there is no z-score,
 *   - "not a number" and "0 or below" keep the rule's hard setting when its
 *     inputs are saved values from another form, or its table was left off
 *     the page (deferredOnSave), and say nothing about the saved values; not
 *     when the rule's condition was read when the page opened, for a calc, or
 *     for an event/instance rule.
 *
 * The z-score itself is parity-locked by tests/growth_js.cjs + growth_php.php.
 *
 * Run:  node tests/growth_dom_js.cjs
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
  // Dates with a time count their dates only: born a second before midnight on
  // 1 January and measured a minute after midnight on 4 January is day 3 (13 kg
  // usual there), though only 2 days and 2 minutes have passed (day 2: z 3.00).
  const els = [el('sex', '1'), el('dob', '2026-01-01 23:59:59'), el('visit_date', '04-01-2026 00:01'), el('weight', '13')];
  const env = boot(els, { growth: GROWTH, rules: [rule(Object.assign({}, LIVE,
    { rangeDobType: 'datetime_seconds', rangeDobFormat: 'ymd', rangeAtType: 'datetime', rangeAtFormat: 'dmy' }))] });
  check('datetime inputs: read by date, day 3, usual (got ' + nMsg(env, 'weight').innerHTML + ')', !shown(nMsg(env, 'weight')));
  els[2].value = '03-01-2026 23:59'; els[2].fire('change');
  check('datetime inputs: a minute before midnight is day 2, z 3.00 (got ' + nMsg(env, 'weight').innerHTML + ')',
    /z-score 3\.00 on Test, age in days/.test(nMsg(env, 'weight').innerHTML));
}
{
  const els = inputs('1', '11');
  const [sex, dob, at, w] = els;
  const env = boot(els, { growth: GROWTH, rules: [rule(LIVE)] });
  const msg = nMsg(env, 'weight');
  check('z 1.00: usual, says nothing', !shown(msg) && save(env) === 'saved');
  w.value = '13'; w.fire('change');
  check('z 3.00: unusual, amber, names the z-score, the reference and the range (got ' + msg.innerHTML + ')',
    /^&#9888; This value is higher than usual \(z-score 3\.00 on Test, age in days, in kg; expected z-score -2 to 2\)\.$/.test(msg.innerHTML));
  check('z 3.00: the save asks first', save(env) === 'asked');
  w.value = '16'; w.fire('change');
  check('z 6.00 (restricted): implausible, held', /above the plausible range \(z-score 6\.00 on Test, age in days, in kg; allowed z-score -5 to 5\)/.test(msg.innerHTML)
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
  // A saved value that chose the branch (snapshotGate) is not an input: "based on".
  const env = boot(inputs('1', '13'), { growth: GROWTH, rules: [rule(Object.assign({ snapshotGate: true, snapshotFields: ['grp'] }, LIVE))] });
  check('a saved value that chose the branch: "based on" it (got ' + nMsg(env, 'weight').innerHTML + ')',
    /\(based on grp, read when this page was opened/.test(nMsg(env, 'weight').innerHTML)
    && !/worked out with/.test(nMsg(env, 'weight').innerHTML) && save(env) === 'saved');
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
  // as TemporalRules::compile sends an event/instance rule to the browser
  const env = boot(els, { growth: GROWTH, rules: [rule(Object.assign({ blockSave: 'off', extendedAdvisory: true }, LIVE))] });
  check('event/instance rule (extendedAdvisory): noted, never blocks', /plausible/.test(nMsg(env, 'weight').innerHTML) && save(env) === 'saved');
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
  check('no table: test() is null and does not throw', env.NS.validators.weight.test() === null);
  // a configuration error that does not stop the z-score: test() still answers null
  const env2 = boot(inputs('1', '16'), { growth: GROWTH, rules: [rule(Object.assign({ rangeSoftBlock: 'bogus' }, LIVE))] });
  check('a configuration error with the table there: test() is null, not a verdict', cfgErr(env2, 'weight')
    && env2.NS.validators.weight.test() === null);
}
{
  // A damaged table: "valid" runs past the rows (the server refuses such a table).
  const els = inputs('1', '16');
  els[2].value = '2026-01-06';   // 5 days old, and the rows end at day 3
  const short = Object.assign({}, T_DAYS, { male: ROWS_M.slice(0, 4), female: ROWS_F.slice(0, 4) });
  const env = boot(els, { growth: { 't-days': short }, rules: [rule(LIVE)] });
  check('no row at that age: a grey note says so, never blocks (got ' + nMsg(env, 'weight').innerHTML + ')',
    /^&#8505; Not checked against Test, age in days: the reference has no row at this age\.$/.test(nMsg(env, 'weight').innerHTML)
    && save(env) === 'saved');
}
{
  // Hundreds of digits overflow to Infinity: beyond every limit, as in the audit.
  const els = inputs('1', '1' + '0'.repeat(320));
  const env = boot(els, { growth: GROWTH, rules: [rule(LIVE)] });
  check('a measurement past what a number holds: implausible, held (got ' + nMsg(env, 'weight').innerHTML + ')',
    /above the plausible range \(z-score 999\.99 on Test, age in days/.test(nMsg(env, 'weight').innerHTML) && save(env) === 'held');
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
  // A rule with branches, none of which applies: the rule does not apply, so
  // the save does not check it either, and the page says nothing.
  const els2 = inputs('', '16');
  const env2 = boot(els2, { rules: [{ type: 'range', fields: ['weight'], deferred: true, deferredOnSave: true, deferredWhy: CAP,
    branches: [rule(Object.assign({ when: "[sex]='1'", deferred: true }, LIVE))] }] });
  check('deferred for the cap, branches, none active: nothing (got ' + nMsg(env2, 'weight').innerHTML + ')',
    !shown(nMsg(env2, 'weight')) && save(env2) === 'saved');
  els2[0].value = '1'; els2[0].fire('change');
  check('... and once the branch applies, the grey note (got ' + nMsg(env2, 'weight').innerHTML + ')',
    /^&#8505; Not checked on this page: this form already carries 4/.test(nMsg(env2, 'weight').innerHTML));
  // One rule, its own "when" false: nothing.
  const env2c = boot(inputs('2', '16'), { rules: [rule(Object.assign({ when: "[sex]='1'", deferred: true, deferredOnSave: true, deferredWhy: CAP }, LIVE))] });
  check('deferred for the cap, its "when" false: nothing (got ' + nMsg(env2c, 'weight').innerHTML + ')', !shown(nMsg(env2c, 'weight')));
  // The branch applies: the rule-level mark still decides (a deferred rule has no active variant).
  const env2b = boot(inputs('1', '16'), { rules: [{ type: 'range', fields: ['weight'], deferred: true, deferredOnSave: true, deferredWhy: CAP,
    branches: [rule(Object.assign({ when: "[sex]='1'", deferred: true }, LIVE))] }] });
  check('deferred for the cap, the branch applies: the same grey note (got ' + nMsg(env2b, 'weight').innerHTML + ')',
    /^&#8505; Not checked on this page: this form already carries 4/.test(nMsg(env2b, 'weight').innerHTML) && save(env2b) === 'saved');
  // Any other deferral keeps its own notice.
  const env3 = boot(inputs('1', '16'), { rules: [rule(Object.assign({ deferred: true, deferredWhy: ['Extended reference unavailable: x.'] }, LIVE))] });
  // One reference, a branch of it not checked: the notice is about the rule, with no reference named.
  const env3b = boot(inputs('1', '16'), { growth: GROWTH, rules: [{ type: 'range', fields: ['weight'], branches: [
    br({ when: "[sex]='1'", deferred: true, deferredWhy: ['Extended reference unavailable: x.'] }), br({ when: "[sex]='2'" })] }] });
  check('one reference, a branch not checked: the rule\'s notice, no reference named (got ' + nMsg(env3b, 'weight').innerHTML + ')',
    /^&#9888; This rule is not being checked — Extended reference unavailable: x\./.test(nMsg(env3b, 'weight').innerHTML));
  check('another deferral: the "not checked after saving either" notice, about the rule (got ' + nMsg(env3, 'weight').innerHTML + ')',
    /^&#9888; This rule is not being checked — Extended reference unavailable: x\./.test(nMsg(env3, 'weight').innerHTML)
    && /not checked after saving either/.test(nMsg(env3, 'weight').innerHTML));
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

// ---- 8) several references on one field ----------------------------------------------------
/* A second reference by age: M 11 on every day, for both sexes. */
const T_DAYS2 = Object.assign({}, T_DAYS, { title: 'Second test', male: ROWS_M.map(() => [1, 11, 0.1]), female: ROWS_M.map(() => [1, 11, 0.1]) });
const GROWTH2 = { 't-days': T_DAYS, 't-days2': T_DAYS2 };
function br(extra) {
  const r = rule(Object.assign({}, LIVE, extra));
  delete r.type; delete r.fields;
  if (r.when === undefined) r.when = null;
  return r;
}
function twoRefs(extraA, extraB) {
  return { type: 'range', fields: ['weight'], branches: [br(extraA), br(Object.assign({ rangeReference: 't-days2' }, extraB))] };
}
{
  const els = inputs('1', '11');
  const [sex, , , w] = els;
  const env = boot(els, { growth: GROWTH2, rules: [twoRefs()] });
  const msg = nMsg(env, 'weight');
  const t = () => env.NS.validators.weight.test();
  check('two references: both pass (z 1.00 and 0.00), nothing shown', !shown(msg) && save(env) === 'saved' && t() === true);
  w.value = '13'; w.fire('change');
  check('two references: one unusual, one line, amber (got ' + msg.innerHTML + ')',
    msg.innerHTML === '&#9888; This value is higher than usual (z-score 3.00 on Test, age in days, in kg; expected z-score -2 to 2).'
    && save(env) === 'asked' && t() === false);
  w.value = '16'; w.fire('change');
  check('two references: implausible on one, unusual on the other: two lines, red, held (got ' + msg.innerHTML + ')',
    msg.innerHTML === '&#10007; This value is above the plausible range (z-score 6.00 on Test, age in days, in kg; allowed z-score -5 to 5).'
      + '<br>&#9888; This value is higher than usual (z-score 4.55 on Second test, in kg; expected z-score -2 to 2).'
    && /#c62828/.test(msg.style.cssText) && save(env) === 'held' && t() === false);
  w.value = 'abc'; w.fire('change');
  check('two references: the same line from both is shown once (got ' + msg.innerHTML + ')',
    msg.innerHTML === '&#10007; This is not a number.' && save(env) === 'held');
  w.value = '11'; w.fire('change');
  sex.value = ''; sex.fire('change');
  check('two references: neither can judge, a grey line each for staff (got ' + msg.innerHTML + ')',
    msg.innerHTML === '&#8505; Not checked against Test, age in days: the sex is blank.'
      + '<br>&#8505; Not checked against Second test: the sex is blank.'
    && save(env) === 'saved' && t() === null);
}
{
  // The second reference is read by length, which is blank: it says why, the first still judges.
  const els = inputs('1', '13').concat([el('len', '')]);
  const env = boot(els, { growth: { 't-days': T_DAYS, 't-len': T_LEN }, rules: [{ type: 'range', fields: ['weight'], branches: [
    br(), Object.assign(br({ rangeReference: 't-len' }), { rangeAgeDobOp: undefined, rangeAgeAtOp: undefined, rangeByOp: ['ref', 'len', null] })] }] });
  const msg = nMsg(env, 'weight');
  check('two references: a verdict and a reference that cannot judge share the note (got ' + msg.innerHTML + ')',
    /^&#9888; This value is higher than usual \(z-score 3\.00 on Test, age in days, in kg; expected z-score -2 to 2\)\.<br>&#8505; Not checked against Test, by length: /.test(msg.innerHTML)
    && save(env) === 'asked');
}
{
  // The strongest block of the references with a verdict decides: unusual (asks) and
  // implausible with hardBlock "confirm" (asks) asks; with the default hard, held.
  const els = inputs('1', '16');
  let env = boot(els, { growth: GROWTH2, rules: [twoRefs({ rangeHardBlock: 'confirm' })] });
  check('two references: implausible with hardBlock confirm and unusual: the save asks', save(env) === 'asked');
  env = boot(inputs('1', '16'), { growth: GROWTH2, rules: [twoRefs({ rangeHardBlock: 'confirm' }, { rangeSoftBlock: 'off' })] });
  check('two references: ... and with the other one\'s softBlock off, still asks', save(env) === 'asked');
}
{
  // Two references, the first branched by "when": the conditional one applies when true, the else otherwise.
  const els = inputs('1', '13').concat([el('band', '1')]);
  const [, , , w, band] = els;
  const env = boot(els, { growth: GROWTH2, rules: [{ type: 'range', fields: ['weight'], branches: [
    br({ when: "[band]='1'", rangeSoftHi: '5', rangeSoftText: 'z-score -2 to 5' }), br({ rangeReference: 't-days2' }), br()] }] });
  const msg = nMsg(env, 'weight');
  check('branched reference: the "when" branch applies (soft up to 5), the other reference passes', !shown(msg));
  band.value = '2'; band.fire('change');
  check('branched reference: false, the else of that reference applies (got ' + msg.innerHTML + ')',
    /^&#9888; This value is higher than usual \(z-score 3\.00 on Test, age in days/.test(msg.innerHTML));
  w.value = '11'; w.fire('change');
  check('branched reference: back to usual', !shown(msg));
}
{
  // Two active branches of ONE reference: a conflict, nothing judged, never held.
  const els = inputs('1', '16');
  const env = boot(els, { growth: GROWTH2, rules: [{ type: 'range', fields: ['weight'], branches: [
    br({ when: "[sex]='1'" }), br({ when: "[visit_date]<>''" }), br({ rangeReference: 't-days2' })] }] });
  const msg = nMsg(env, 'weight');
  check('one reference, two active branches: a conflict (got ' + msg.innerHTML + ')',
    /Validation conflict/.test(msg.innerHTML) && save(env) === 'saved' && env.NS.validators.weight.test() === null);
}

{
  // A branch whose condition the server settled false gets no table (growthIdle):
  // no configuration error, and the other reference still judges.
  const els = inputs('1', '16');
  const env = boot(els, { growth: { 't-days2': T_DAYS2 }, rules: [{ type: 'range', fields: ['weight'], branches: [
    br({ when: "[grp]='2'", whenAst: ['const', false], growthIdle: true }), br({ rangeReference: 't-days2' })] }] });
  check('settled false (growthIdle): no configuration error, the other reference judges (got ' + nMsg(env, 'weight').innerHTML + ')',
    !cfgErr(env, 'weight') && /^&#9888; This value is higher than usual \(z-score 4\.55 on Second test/.test(nMsg(env, 'weight').innerHTML)
    && save(env) === 'asked');
  // One rule settled false: nothing at all.
  const env2 = boot(inputs('1', '16'), { rules: [rule(Object.assign({ when: "[grp]='2'", whenAst: ['const', false], growthIdle: true }, LIVE))] });
  check('settled false, one rule: no configuration error, nothing shown', !cfgErr(env2, 'weight') && !shown(nMsg(env2, 'weight'))
    && save(env2) === 'saved' && env2.NS.validators.weight.test() === null);
  // Without the mark a missing table is still an error.
  const env3 = boot(inputs('1', '16'), { rules: [rule(Object.assign({ when: "[grp]='2'", whenAst: ['const', false] }, LIVE))] });
  check('... and without growthIdle, a missing table is a configuration error', !!cfgErr(env3, 'weight'));
}
{
  // A reference not checked (an unresolved input), beside one with a verdict: the
  // notice's own words, one icon.
  const WHY = ['references "[ht]", which is not collected in this event.'];
  const els = inputs('1', '16');
  const w = els[3];
  const env = boot(els, { missingCodes: ['UNK'], growth: { 't-days2': T_DAYS2 }, rules: [{ type: 'range', fields: ['weight'], branches: [
    br({ deferred: true, deferredWhy: WHY }), br({ rangeReference: 't-days2' })] }] });
  const msg = nMsg(env, 'weight');
  const LINE = '&#9888; Reference &quot;t-days&quot; is not being checked — references &quot;[ht]&quot;, which is not collected in this event.'
    + ' It is not checked after saving either; the study team needs to correct the rule.';
  check('two references, one not checked: its line names it, in the notice\'s words (got ' + msg.innerHTML + ')',
    msg.innerHTML === LINE + '<br>&#9888; This value is higher than usual (z-score 4.55 on Second test, in kg; expected z-score -2 to 2).');
  w.value = '11'; w.fire('change');
  check('... the other passes: the line alone, amber, one icon, saved (got ' + msg.innerHTML + ')',
    msg.innerHTML === LINE && /#7a5c00/.test(msg.style.cssText) && save(env) === 'saved');
  w.value = 'UNK'; w.fire('change');
  check('... a Missing Data Code: the line stays (got ' + msg.innerHTML + ')', msg.innerHTML === LINE && save(env) === 'saved');
  // the fixed limits not checked, beside a reference: the line names them
  const envL = boot(inputs('1', '16'), { growth: { 't-days2': T_DAYS2 }, rules: [{ type: 'range', fields: ['weight'], branches: [
    { when: null, deferred: true, deferredWhy: WHY, rangeHardLo: '1', rangeHardHi: '40' }, br({ rangeReference: 't-days2' })] }] });
  check('... the fixed limits not checked: their line names them (got ' + nMsg(envL, 'weight').innerHTML + ')',
    /^&#9888; The limits without a reference are not being checked — references &quot;\[ht\]&quot;/.test(nMsg(envL, 'weight').innerHTML));
}
{
  // The server could not resolve one reference's selector (selectorBlocked): that
  // reference says so, though none of its conditions holds and it has no else;
  // the other still judges.
  const WHY = ['references "[grp]", which is not collected in this event.'];
  const els = inputs('1', '16');
  const env = boot(els, { growth: { 't-days2': T_DAYS2 }, rules: [{ type: 'range', fields: ['weight'], branches: [
    br({ when: "[grp]='2'", whenAst: ['const', false], deferred: true, selectorBlocked: true, deferredWhy: WHY }),
    br({ when: "[grp]='3'", whenAst: ['const', false], deferred: true, selectorBlocked: true, deferredWhy: WHY, rangeSoftHi: '9' }),
    br({ rangeReference: 't-days2' })] }] });
  const msg = nMsg(env, 'weight');
  check('selector blocked on one reference: its deferral, and the other\'s verdict (got ' + msg.innerHTML + ')',
    /^&#9888; Reference &quot;t-days&quot; is not being checked — references &quot;\[grp\]&quot;/.test(msg.innerHTML)
    && /<br>&#9888; This value is higher than usual \(z-score 4\.55 on Second test/.test(msg.innerHTML) && save(env) === 'asked');
  // ... and when its conditions read true here (the server folds each unresolved
  // comparison to false, so "not [grp]='1'" reads true): still its line, not a conflict
  const envN = boot(inputs('1', '16'), { growth: { 't-days2': T_DAYS2 }, rules: [{ type: 'range', fields: ['weight'], branches: [
    br({ when: "not [grp]='1'", whenAst: ['not', ['const', false]], deferred: true, selectorBlocked: true, deferredWhy: WHY }),
    br({ when: "not [grp]='2'", whenAst: ['not', ['const', false]], deferred: true, selectorBlocked: true, deferredWhy: WHY, rangeSoftHi: '9' }),
    br({ rangeReference: 't-days2' })] }] });
  check('... two of its conditions true: no conflict, its line and the other\'s verdict (got ' + nMsg(envN, 'weight').innerHTML + ')',
    /^&#9888; Reference &quot;t-days&quot; is not being checked — references &quot;\[grp\]&quot;/.test(nMsg(envN, 'weight').innerHTML)
    && /<br>&#9888; This value is higher than usual \(z-score 4\.55 on Second test/.test(nMsg(envN, 'weight').innerHTML)
    && save(envN) === 'asked' && envN.NS.validators.weight.test() === false);
  // A selector this page cannot resolve (an event/instance reference): the same, with "checked after saving".
  const env2 = boot(inputs('1', '16'), { growth: { 't-days': T_DAYS, 't-days2': T_DAYS2 }, rules: [{ type: 'range', fields: ['weight'], branches: [
    br({ when: '[visit_1][grp]=1', whenAst: ['cmp', '=', ['qref', 'visit_1', 'grp', null], ['lit', '1']] }),
    br({ rangeReference: 't-days2' })] }] });
  check('a selector this page cannot resolve: that reference\'s line, the other judges (got ' + nMsg(env2, 'weight').innerHTML + ')',
    /^&#9888; Reference &quot;t-days&quot; is not being checked — Current values cannot resolve this comparison\. Save and reload to refresh matched data\. It is checked after the record is saved\.<br>&#9888; This value is higher than usual \(z-score 4\.55/.test(nMsg(env2, 'weight').innerHTML));
  // ... and with two of that reference's other conditions true: a conflict, as the server,
  // which reads the unknown one, sees two true at least
  const env2u = boot(inputs('1', '16'), { growth: { 't-days': T_DAYS, 't-days2': T_DAYS2 }, rules: [{ type: 'range', fields: ['weight'], branches: [
    br({ when: '[visit_1][grp]=1', whenAst: ['cmp', '=', ['qref', 'visit_1', 'grp', null], ['lit', '1']] }),
    br({ when: "[sex]='1'" }), br({ when: "[sex]<>'2'", rangeSoftHi: '9' }),
    br({ rangeReference: 't-days2' })] }] });
  check('... an unknown condition beside two true ones: still a conflict (got ' + nMsg(env2u, 'weight').innerHTML + ')',
    /Validation conflict/.test(nMsg(env2u, 'weight').innerHTML) && save(env2u) === 'saved');
  // ... and alone, when the other reference does not apply: the notice, which says the save checks it
  const env3 = boot(inputs('1', '16'), { growth: { 't-days': T_DAYS, 't-days2': T_DAYS2 }, rules: [{ type: 'range', fields: ['weight'], branches: [
    br({ when: '[visit_1][grp]=1', whenAst: ['cmp', '=', ['qref', 'visit_1', 'grp', null], ['lit', '1']] }),
    br({ rangeReference: 't-days2', when: "[sex]='2'" })] }] });
  check('... alone: the notice names its reference and says the save checks it (got ' + nMsg(env3, 'weight').innerHTML + ')',
    nMsg(env3, 'weight').innerHTML === '&#9888; Reference &quot;t-days&quot; is not being checked — Current values cannot resolve this comparison. '
      + 'Save and reload to refresh matched data. It is checked after the record is saved.' && save(env3) === 'saved');
}
{
  // A survey respondent sees the plausible range, not why there is no z-score.
  const env = boot(inputs('3', '95'), { context: 'survey', growth: GROWTH,
    rules: [rule(Object.assign({ rangePlausibleLo: '2', rangePlausibleHi: '30', rangePlausibleText: '2 to 30 kg' }, LIVE))] });
  check('plausible on a survey: the range, no reason (got ' + nMsg(env, 'weight').innerHTML + ')',
    nMsg(env, 'weight').innerHTML === '&#10007; This value is above the plausible range (allowed 2 to 30 kg).' && save(env) === 'held');
  const env2 = boot(inputs('3', '95'), { context: 'survey', growth: GROWTH,
    rules: [rule(Object.assign({ rangePlausibleLo: '2', rangePlausibleHi: '30' }, LIVE))] });
  check('... with no plausible text, no brackets at all (got ' + nMsg(env2, 'weight').innerHTML + ')',
    nMsg(env2, 'weight').innerHTML === '&#10007; This value is above the plausible range.');
}

// ---- 9) the measurement alone keeps the rule's hard setting -------------------------------------
/* sex and the date of birth saved on another form: folded to literals, named in snapshotFields */
const SAVED = { rangeSexOp: ['lit', '1'], rangeAgeDobOp: ['lit', '2026-01-01'], rangeAgeAtOp: ['ref', 'visit_date', null],
  rangeDobType: 'date', rangeDobFormat: 'ymd', rangeAtType: 'date', rangeAtFormat: 'ymd', snapshotFields: ['sex', 'dob'] };
function savedInputs(w) { return [el('visit_date', '2026-01-03'), el('weight', w)]; }
{
  const els = savedInputs('abc');
  const w = els[1];
  const env = boot(els, { growth: GROWTH, rules: [rule(SAVED)] });
  const msg = nMsg(env, 'weight');
  check('saved inputs: not a number is held, and the note names no saved value (got ' + msg.innerHTML + ')',
    msg.innerHTML === '&#10007; This is not a number.' && save(env) === 'held');
  w.value = '0'; w.fire('change');
  check('saved inputs: 0 is held', msg.innerHTML === '&#10007; A measurement must be above 0.' && save(env) === 'held');
  w.value = '16'; w.fire('change');
  check('saved inputs: a z-score verdict still never blocks, and says what it was worked out with (got ' + msg.innerHTML + ')',
    /above the plausible range \(z-score 6\.00/.test(msg.innerHTML) && /worked out with sex, dob/.test(msg.innerHTML)
    && save(env) === 'saved');
  w.value = 'abc';
  check('saved inputs: test() is false for not a number', env.NS.validators.weight.test() === false);
}
{
  // hardBlock "confirm" is the rule's hard setting: it asks
  const env = boot(savedInputs('-1'), { growth: GROWTH, rules: [rule(Object.assign({ rangeHardBlock: 'confirm' }, SAVED))] });
  check('saved inputs: hardBlock confirm asks for 0 or below', save(env) === 'asked');
}
{
  // a condition read when the page opened decides whether the rule applies: nothing blocks
  const env = boot(savedInputs('abc'), { growth: GROWTH, rules: [rule(Object.assign({ snapshotGate: true }, SAVED))] });
  check('saved condition (snapshotGate): not a number is shown, not held', /not a number/.test(nMsg(env, 'weight').innerHTML) && save(env) === 'saved');
  const env2 = boot(savedInputs('abc'), { growth: GROWTH, rules: [rule(Object.assign({ rangeComputed: true }, SAVED))] });
  check('a calc: not a number never blocks', save(env2) === 'saved');
  const env3 = boot(savedInputs('abc'), { growth: GROWTH, rules: [rule(Object.assign({ extendedAdvisory: true }, SAVED))] });
  check('an event/instance rule: not a number never blocks', save(env3) === 'saved');
}
{
  // left off the page by the table cap: the measurement is still checked
  const CAP = ['this form already carries the rows of 4 growth reference tables, the most one page may carry, so this check runs when the record is saved.'];
  const els = inputs('1', 'abc');
  const w = els[3];
  const env = boot(els, { rules: [rule(Object.assign({ deferred: true, deferredOnSave: true, deferredWhy: CAP }, LIVE))] });
  const msg = nMsg(env, 'weight');
  check('cap: not a number is held (got ' + msg.innerHTML + ')', msg.innerHTML === '&#10007; This is not a number.' && save(env) === 'held');
  check('cap: test() false', env.NS.validators.weight.test() === false);
  w.value = '0'; w.fire('change');
  check('cap: 0 is held', /must be above 0/.test(msg.innerHTML) && save(env) === 'held');
  w.value = '16'; w.fire('change');
  check('cap: a number gets the grey note, not a verdict, and is saved (got ' + msg.innerHTML + ')',
    /^&#8505; Not checked on this page: this form already carries the rows of 4/.test(msg.innerHTML) && save(env) === 'saved'
    && env.NS.validators.weight.test() === null);
  // branch-level: one reference left off, the other judged
  const env2 = boot(inputs('1', '16'), { growth: { 't-days2': T_DAYS2 }, rules: [{ type: 'range', fields: ['weight'], branches: [
    br({ deferred: true, deferredOnSave: true, deferredWhy: CAP }), br({ rangeReference: 't-days2' })] }] });
  check('cap, one reference of two: its grey line beside the other\'s verdict (got ' + nMsg(env2, 'weight').innerHTML + ')',
    nMsg(env2, 'weight').innerHTML === '&#8505; Reference &quot;t-days&quot; is not checked on this page: ' + CAP[0]
      + '<br>&#9888; This value is higher than usual (z-score 4.55 on Second test, in kg; expected z-score -2 to 2).'
    || nMsg(env2, 'weight').innerHTML === '&#9888; This value is higher than usual (z-score 4.55 on Second test, in kg; expected z-score -2 to 2).'
      + '<br>&#8505; Reference &quot;t-days&quot; is not checked on this page: ' + CAP[0]);
  // A Missing Data Code is not checked on save either: no grey line, alone or beside another reference
  const envM = boot([el('sex', '1'), el('dob', '01-01-2026'), el('visit_date', '2026-01-03'), el('weight', 'UNK')],
    { missingCodes: ['UNK'], rules: [rule(Object.assign({ deferred: true, deferredOnSave: true, deferredWhy: CAP }, LIVE))] });
  check('cap, a Missing Data Code: no note (got ' + nMsg(envM, 'weight').innerHTML + ')', !shown(nMsg(envM, 'weight')) && save(envM) === 'saved');
  const envM2 = boot([el('sex', '1'), el('dob', '01-01-2026'), el('visit_date', '2026-01-03'), el('weight', 'UNK')],
    { missingCodes: ['UNK'], growth: { 't-days2': T_DAYS2 }, rules: [{ type: 'range', fields: ['weight'], branches: [
      br({ deferred: true, deferredOnSave: true, deferredWhy: CAP }), br({ rangeReference: 't-days2' })] }] });
  check('cap, one reference of two, a Missing Data Code: no note (got ' + nMsg(envM2, 'weight').innerHTML + ')',
    !shown(nMsg(envM2, 'weight')) && save(envM2) === 'saved');
  const env3 = boot(inputs('1', 'abc'), { context: 'survey', rules: [rule(Object.assign({ deferred: true, deferredOnSave: true, deferredWhy: CAP }, LIVE))] });
  check('cap, survey: not a number is still held', save(env3) === 'held');
}

// ---- 10) a table cut to what the page reads (UniversalValidator::growthPageNeed) -------------------
{
  // The sex and both dates saved: the server sends the boys' rows at day 2, one either side.
  const CUT = Object.assign({}, T_DAYS, { first: 1, male: ROWS_M.slice(1, 4), female: [] });
  const ALL_SAVED = { rangeSexOp: ['lit', '1'], rangeAgeDobOp: ['lit', '2026-01-01'], rangeAgeAtOp: ['lit', '2026-01-03'],
    rangeDobType: 'date', rangeDobFormat: 'ymd', rangeAtType: 'date', rangeAtFormat: 'ymd', snapshotFields: ['sex', 'dob', 'visit_date'] };
  const env = boot([el('weight', '13')], { growth: { 't-days': CUT }, rules: [rule(ALL_SAVED)] });
  check('cut table: the rows at the saved age give the same z-score (got ' + nMsg(env, 'weight').innerHTML + ')',
    /higher than usual \(z-score 3\.00 on Test, age in days/.test(nMsg(env, 'weight').innerHTML));
}

// ---- 11) sex codes ignore letter case unless caseSensitive (GrowthReference::sexOf) ----------
{
  const MF = { rangeMale: 'M', rangeFemale: 'F' };
  const env = boot(inputs('m', '16'), { growth: GROWTH, rules: [rule(Object.assign({}, MF, LIVE))] });
  check('sex "m" is the male code "M" (got ' + nMsg(env, 'weight').innerHTML + ')', /plausible/.test(nMsg(env, 'weight').innerHTML) && save(env) === 'held');
  const env2 = boot(inputs('f', '11'), { growth: GROWTH, rules: [rule(Object.assign({}, MF, LIVE))] });
  check('sex "f" is the female code "F": the female rows (got ' + nMsg(env2, 'weight').innerHTML + ')', /z-score 3\.75/.test(nMsg(env2, 'weight').innerHTML));
  const env3 = boot(inputs('m', '16'), { growth: GROWTH, rules: [rule(Object.assign({ caseSensitive: true }, MF, LIVE))] });
  check('caseSensitive: "m" is neither code (got ' + nMsg(env3, 'weight').innerHTML + ')',
    /the sex code is neither M \(male\) nor F \(female\)\./.test(nMsg(env3, 'weight').innerHTML) && save(env3) === 'saved');
  const env4 = boot(inputs('M', '16'), { growth: GROWTH, rules: [rule(Object.assign({ caseSensitive: true }, MF, LIVE))] });
  check('caseSensitive: "M" is', save(env4) === 'held');
  // a saved sex folded to a literal reads the same
  const env5 = boot([el('dob', '01-01-2026'), el('visit_date', '2026-01-03'), el('weight', '16')],
    { growth: GROWTH, rules: [rule(Object.assign({}, MF, LIVE, { rangeSexOp: ['lit', 'm'], snapshotFields: ['sex'] }))] });
  // a verdict on saved inputs never blocks (section 9), so read the note
  check('a saved sex "m" is the male code too (got ' + nMsg(env5, 'weight').innerHTML + ')',
    /above the plausible range \(z-score 6\.00/.test(nMsg(env5, 'weight').innerHTML));
}

// ---- 12) no z-score: "plausible" judges; "measureScale"; the unit in the note ------------------
const PL = { rangePlausibleLo: '2', rangePlausibleHi: '30', rangePlausibleText: '2 to 30 kg' };
{
  const els = inputs('', '95');
  const [sex, , , w] = els;
  const env = boot(els, { growth: GROWTH, rules: [rule(Object.assign({}, PL, LIVE))] });
  const msg = nMsg(env, 'weight');
  check('plausible: no sex, 95 kg is above the plausible range, held (got ' + msg.innerHTML + ')',
    msg.innerHTML === '&#10007; This value is above the plausible range (allowed 2 to 30 kg; no z-score on Test, age in days: the sex is blank).'
    && save(env) === 'held' && env.NS.validators.weight.test() === false);
  w.value = '1'; w.fire('change');
  check('plausible: 1 kg is below it', /^&#10007; This value is below the plausible range \(allowed 2 to 30 kg; no z-score/.test(msg.innerHTML) && save(env) === 'held');
  w.value = '9'; w.fire('change');
  check('plausible: 9 kg inside it: the grey note, saved, test() open (got ' + msg.innerHTML + ')',
    /^&#8505; Not checked against Test, age in days: the sex is blank\.$/.test(msg.innerHTML) && save(env) === 'saved'
    && env.NS.validators.weight.test() === null);
  sex.value = '1'; sex.fire('change'); w.value = '13'; w.fire('change');
  check('plausible: with a z-score the z-score decides (got ' + msg.innerHTML + ')',
    /^&#9888; This value is higher than usual \(z-score 3\.00 on Test, age in days, in kg; expected z-score -2 to 2\)\.$/.test(msg.innerHTML));
  w.value = '31'; w.fire('change');
  check('plausible: ... and 31 kg is judged by its z-score, not by "plausible"', /z-score/.test(msg.innerHTML) && !/no z-score/.test(msg.innerHTML));
}
{
  const env = boot(inputs('3', '95'), { growth: GROWTH, rules: [rule(Object.assign({ rangeHardBlock: 'confirm' }, PL, LIVE))] });
  check('plausible: a sex code that is neither, hardBlock confirm asks (got ' + nMsg(env, 'weight').innerHTML + ')',
    /no z-score on Test, age in days: the sex code is neither 1 \(male\) nor 2 \(female\)/.test(nMsg(env, 'weight').innerHTML) && save(env) === 'asked');
}
{
  // a date on the page still being typed says nothing, plausible or not
  const els = inputs('1', '95');
  const env = boot(els, { growth: GROWTH, rules: [rule(Object.assign({}, PL, LIVE))] });
  els[2].value = '2026-01-0'; els[2].fire('change');
  check('plausible: a date still being typed: nothing at all', !shown(nMsg(env, 'weight')) && save(env) === 'saved');
}
{
  // a saved date of birth the rule cannot read: the server's words
  const BAD = Object.assign({}, SAVED, { rangeAgeDobOp: ['lit', '10-01-2025'] });
  const env = boot(savedInputs('9'), { growth: GROWTH, rules: [rule(BAD)] });
  check('a saved date of birth it cannot read: the grey note (got ' + nMsg(env, 'weight').innerHTML + ')',
    nMsg(env, 'weight').innerHTML === '&#8505; Not checked against Test, age in days: the date of birth is not a date this rule can read.');
  // with "plausible", it judges; a verdict from saved values never blocks
  const env2 = boot(savedInputs('95'), { growth: GROWTH, rules: [rule(Object.assign({}, PL, BAD))] });
  check('... with "plausible": judged, worked out with the saved values, never blocks (got ' + nMsg(env2, 'weight').innerHTML + ')',
    /above the plausible range \(allowed 2 to 30 kg; no z-score on Test, age in days: the date of birth is not a date this rule can read\)/.test(nMsg(env2, 'weight').innerHTML)
    && /worked out with sex, dob/.test(nMsg(env2, 'weight').innerHTML) && save(env2) === 'saved');
}
{
  // measureScale: grams into a table in kg
  const els = inputs('1', '13000');
  const env = boot(els, { growth: GROWTH, rules: [rule(Object.assign({ rangeMeasureScale: '0.001' }, LIVE))] });
  const msg = nMsg(env, 'weight');
  check('measureScale: 13000 g is 13 kg, z 3.00, and the note says so (got ' + msg.innerHTML + ')',
    msg.innerHTML === '&#9888; This value is higher than usual (z-score 3.00 on Test, age in days, in kg after × 0.001; expected z-score -2 to 2).');
  els[3].value = '0'; els[3].fire('change');
  check('measureScale: 0 is still not a measurement', /must be above 0/.test(msg.innerHTML) && save(env) === 'held');
  const env2 = boot(inputs('', '95000'), { growth: GROWTH, rules: [rule(Object.assign({ rangeMeasureScale: '0.001' }, PL, LIVE))] });
  check('measureScale: "plausible" is in the reference\'s unit, after the scale', /above the plausible range \(allowed 2 to 30 kg/.test(nMsg(env2, 'weight').innerHTML));
  const env3 = boot(inputs('1', '13'), { growth: GROWTH, rules: [rule(Object.assign({ rangeMeasureScale: 'grams' }, LIVE))] });
  check('measureScale that is not a number: a configuration error', !!cfgErr(env3, 'weight') && /rangeMeasureScale is not a number/.test(cfgErr(env3, 'weight').innerHTML));
  const env4 = boot(inputs('1', '13'), { growth: GROWTH, rules: [rule(Object.assign({ rangePlausibleLo: 'x' }, LIVE))] });
  check('a plausible limit that is not a number: a configuration error', !!cfgErr(env4, 'weight') && /rangePlausibleLo is not a number/.test(cfgErr(env4, 'weight').innerHTML));
}

console.log('growth_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
