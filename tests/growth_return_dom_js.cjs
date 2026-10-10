/*
 * growth_return_dom_js.cjs — @UVRANGE "return" in the browser (2.7.0).
 *
 * Drives the real QRIDRangeInit factory with a growth rule that returns values
 * (the z-score, the measurement at the low and high limit, and the range as
 * text) into other fields of the form, through the DOM stub the other *_dom
 * tests use, and asserts:
 *   - the fields are filled as the page opens without announcing a change,
 *     and refilled, with an input and a change event, after each judgement,
 *   - a blank measurement leaves the bounds, a blank input empties every
 *     field, and a value is written whatever the verdict says,
 *   - returnLimits, decimals and the decimal mark of each field,
 *   - one-sided limits read "at least" / "at most",
 *   - a rule that returns only the z-score needs no limits,
 *   - a field already holding the same number is left alone,
 *   - a branched rule owns the fields of every branch: the active branch fills
 *     its own and empties the others,
 *   - a rule the page cannot judge (an input withheld, the table cap, an
 *     unresolved branch condition) leaves its fields as they are,
 *   - REDCap's calculations and branching logic are told about a new value,
 *   - a field that is not on the page, a Missing Data Code in the
 *     measurement, a survey, and the configuration errors.
 *
 * The values themselves are parity-locked by tests/growth_js.cjs and
 * tests/growth_php.php (the "outputs" fixture section).
 * UniversalValidator::returnsRange is the server twin; the save-time writes are
 * tests/growth_return_module_php.php.
 *
 * Run:  node tests/growth_return_dom_js.cjs
 */
'use strict';
const path = require('path');
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
    _handlers: {}, _events: [],
    setAttribute(k, v) { this._attrs[k] = String(v); if (k === 'id') this.id = String(v); },
    getAttribute(k) { return (k in this._attrs) ? this._attrs[k] : (k === 'id' ? (this.id || null) : null); },
    removeAttribute(k) { delete this._attrs[k]; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev || {})); },
    /* what a real element does with an Event the page makes: the handlers hear it, and it is on record */
    dispatchEvent(ev) { this._events.push(ev.type); this.fire(ev.type, ev); return true; },
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
    _alerts: [], _confirms: [], confirmAnswer: false, _calc: [], _branch: [],
    alert(m) { this._alerts.push(m); }, confirm(m) { this._confirms.push(m); return this.confirmAnswer; },
    calculate(f) { this._calc.push(f); }, doBranching(f) { this._branch.push(f); },
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
function el(name, value) { const e = makeEl('input'); e.name = name; e.value = value; return e; }
function shown(msg) { return !!msg && msg.style.display !== 'none' && msg.innerHTML !== ''; }

/* The table of tests/growth_dom_js.cjs: for a boy, z is (y/10 - 1)/0.1 on every
   day but day 3 (so 8.0 kg is z -2 and 12.0 kg is z 2); for a girl, (y/8 - 1)/0.1
   (6.4 kg is z -2, 9.6 kg is z 2). */
const ROWS_M = [[1, 10, 0.1], [1, 10, 0.1], [1, 10, 0.1], [0.5, 12, 0.2], [1, 10, 0.1],
  [1, 10, 0.1], [1, 10, 0.1], [1, 10, 0.1], [1, 10, 0.1], [1, 10, 0.1]];
const ROWS_F = ROWS_M.map(() => [1, 8, 0.1]);
const T_DAYS = { title: 'Test, age in days', measure: 'weight', unit: 'kg', axis: 'age', axisUnit: 'days', lookup: 'round',
  valid: { min: '0', below: '10' }, adjust: 'who-restricted', scale: 1, first: 0, male: ROWS_M, female: ROWS_F };
const GROWTH = { 't-days': T_DAYS };
const LIVE = { rangeSexOp: ['ref', 'sex', null], rangeAgeDobOp: ['ref', 'dob', null], rangeAgeAtOp: ['ref', 'visit_date', null],
  rangeDobType: 'date', rangeDobFormat: 'dmy', rangeAtType: 'date', rangeAtFormat: 'ymd' };
const ALL = { z: 'wfa_z', lower: 'wfa_lo', upper: 'wfa_hi', range: 'wfa_rng' };
function rule(extra) {
  return Object.assign({ type: 'range', fields: ['weight'], rangeReference: 't-days', rangeMale: '1', rangeFemale: '2',
    rangeSoftLo: '-2', rangeSoftHi: '2', rangeHardLo: '-5', rangeHardHi: '5', rangeDecimals: 1,
    rangeReturn: ALL }, LIVE, extra);
}
/* sex, a dob shown day-month-year, a visit date year-month-day, the weight (2 days old), and the four fields */
function form(sex, w, targets) {
  const out = [el('sex', sex), el('dob', '01-01-2026'), el('visit_date', '2026-01-03'), el('weight', w)];
  for (const t of (targets || Object.values(ALL))) out.push(el(t, ''));
  return out;
}
function by(els, name) { return els.find((e) => e.name === name); }
function vals(els) { const o = {}; for (const f of Object.values(ALL)) { const e = by(els, f); if (e) o[f] = e.value; } return o; }
function same(a, b) { return JSON.stringify(a) === JSON.stringify(b); }
function asText(o) { return JSON.stringify(o); }

// ---- 1) filled on opening, refilled after each judgement ---------------------------------
{
  const els = form('1', '11');
  const env = boot(els, { growth: GROWTH, rules: [rule()] });
  const v = () => vals(els);
  check('opening: z, bounds and range are filled (got ' + asText(v()) + ')',
    same(v(), { wfa_z: '1.00', wfa_lo: '8.0', wfa_hi: '12.0', wfa_rng: '8.0 to 12.0 kg' }));
  check('opening: no event announces the fill (the form is not marked as changed)',
    Object.values(ALL).every((f) => by(els, f)._events.length === 0));
  check('opening: REDCap\'s calculations and branching logic are told', env.win._calc.length === 4 && env.win._branch.length === 4
    && env.win._calc.includes('wfa_z') && env.win._branch.includes('wfa_rng'));
  const w = by(els, 'weight');
  w.value = '13'; w.fire('change');
  check('weight 13: z 3.00, the bounds are unchanged (got ' + asText(v()) + ')',
    same(v(), { wfa_z: '3.00', wfa_lo: '8.0', wfa_hi: '12.0', wfa_rng: '8.0 to 12.0 kg' }));
  const z = by(els, 'wfa_z');
  check('weight 13: z hears an input and a change event, the unchanged bounds hear nothing',
    same(z._events, ['input', 'change']) && by(els, 'wfa_lo')._events.length === 0);
  check('a value is written whatever the verdict (13 kg is unusual: the note is up) ',
    /higher than usual/.test(nMsg(env, 'weight').innerHTML));
  w.value = '16'; w.fire('change');
  check('weight 16 (z 6.00, implausible): still written (got ' + z.value + ')', z.value === '6.00');
  w.value = ''; w.fire('change');
  check('no measurement: no z-score, the bounds stay (got ' + asText(v()) + ')',
    same(v(), { wfa_z: '', wfa_lo: '8.0', wfa_hi: '12.0', wfa_rng: '8.0 to 12.0 kg' }));
  w.value = 'abc'; w.fire('change');
  check('not a number: no z-score, the bounds stay', z.value === '' && by(els, 'wfa_rng').value === '8.0 to 12.0 kg');
  w.value = '0'; w.fire('change');
  check('0 kg: no z-score, the bounds stay', z.value === '' && by(els, 'wfa_lo').value === '8.0');
  w.value = '11'; w.fire('change');
  check('11 kg again: z 1.00', z.value === '1.00');
  check('test() still answers by the z-score', env.NS.validators.weight.test() === true);
}

// ---- 2) an input changes the bounds; a blank input empties every field -----------------
{
  const els = form('1', '11');
  const env = boot(els, { growth: GROWTH, rules: [rule()] });
  const sex = by(els, 'sex'), dob = by(els, 'dob'), at = by(els, 'visit_date');
  sex.value = '2'; sex.fire('change');
  check('a girl: 11 kg is z 3.75 and the bounds are 6.4 to 9.6 (got ' + asText(vals(els)) + ')',
    same(vals(els), { wfa_z: '3.75', wfa_lo: '6.4', wfa_hi: '9.6', wfa_rng: '6.4 to 9.6 kg' }));
  sex.value = ''; sex.fire('change');
  check('no sex: every field is emptied (got ' + asText(vals(els)) + ')', Object.values(vals(els)).every((x) => x === ''));
  sex.value = '3'; sex.fire('change');
  check('a sex code that is neither: emptied too', Object.values(vals(els)).every((x) => x === ''));
  sex.value = '1'; sex.fire('change');
  check('a boy again: refilled', vals(els).wfa_z === '1.00' && vals(els).wfa_rng === '8.0 to 12.0 kg');
  dob.value = ''; dob.fire('change');
  check('no date of birth: emptied', Object.values(vals(els)).every((x) => x === ''));
  dob.value = '01-01-2026'; dob.fire('change');
  at.value = '2026-02-01'; at.fire('change');
  check('31 days old, outside the reference: emptied', Object.values(vals(els)).every((x) => x === ''));
  at.value = '2026-01-0'; at.fire('change');
  check('a date still being typed: emptied until it is a date', Object.values(vals(els)).every((x) => x === ''));
  at.value = '2026-01-03'; at.fire('change');
  check('a whole date: refilled', vals(els).wfa_z === '1.00');
  check('after the fill a change announces itself once (input, change)',
    by(els, 'wfa_z')._events.slice(-2).join() === 'input,change');
}

// ---- 3) the limits and the decimals ------------------------------------------------------
{
  const els = form('1', '11');
  boot(els, { growth: GROWTH, rules: [rule({ rangeReturnLimits: 'hard' })] });
  check('returnLimits hard: 5.0 to 15.0 kg (got ' + asText(vals(els)) + ')',
    same(vals(els), { wfa_z: '1.00', wfa_lo: '5.0', wfa_hi: '15.0', wfa_rng: '5.0 to 15.0 kg' }));
}
{
  const els = form('1', '11');
  boot(els, { growth: GROWTH, rules: [rule({ rangeDecimals: 2 })] });
  check('decimals 2: 8.00 and 12.00 (got ' + asText(vals(els)) + ')',
    vals(els).wfa_lo === '8.00' && vals(els).wfa_hi === '12.00' && vals(els).wfa_rng === '8.00 to 12.00 kg' && vals(els).wfa_z === '1.00');
}
{
  const els = form('2', '8');
  boot(els, { growth: GROWTH, rules: [rule({ rangeDecimals: 0 })] });
  // 6.4 rounds up to 7 and 9.6 down to 9: inward, so the range never reaches past the limits
  check('decimals 0: rounded inward, 7 to 9 kg (got ' + asText(vals(els)) + ')',
    vals(els).wfa_lo === '7' && vals(els).wfa_hi === '9' && vals(els).wfa_rng === '7 to 9 kg');
}
{
  const els = form('1', '11');
  boot(els, { growth: GROWTH, rules: [rule({ rangeReturnComma: { z: true, lower: true, upper: false, range: true } })] });
  check('decimal marks: z and lower with a comma, upper with a point, the range with commas (got ' + asText(vals(els)) + ')',
    same(vals(els), { wfa_z: '1,00', wfa_lo: '8,0', wfa_hi: '12.0', wfa_rng: '8,0 to 12,0 kg' }));
}
{
  // a rule that gives one side only reads "at least" / "at most"
  const els = form('1', '11', ['wfa_rng']);
  boot(els, { growth: GROWTH, rules: [rule({ rangeSoftHi: undefined, rangeReturn: { range: 'wfa_rng' } })] });
  check('only a low limit: "at least 8.0 kg" (got ' + by(els, 'wfa_rng').value + ')', by(els, 'wfa_rng').value === 'at least 8.0 kg');
  const els2 = form('1', '11', ['wfa_rng']);
  boot(els2, { growth: GROWTH, rules: [rule({ rangeSoftLo: undefined, rangeReturn: { range: 'wfa_rng' } })] });
  check('only a high limit: "at most 12.0 kg" (got ' + by(els2, 'wfa_rng').value + ')', by(els2, 'wfa_rng').value === 'at most 12.0 kg');
}
{
  // limits narrower than a decimal leave no range
  const els = form('1', '11', ['wfa_lo', 'wfa_hi', 'wfa_rng']);
  boot(els, { growth: GROWTH, rules: [rule({ rangeSoftLo: '0.01', rangeSoftHi: '0.02', rangeReturn: { lower: 'wfa_lo', upper: 'wfa_hi', range: 'wfa_rng' } })] });
  // 10.01 to 10.02 kg: each bound rounds inward, 10.1 and 10.0, which cross
  check('a band narrower than one decimal: the bounds cross, so no range (got ' + asText(vals(els)) + ')',
    vals(els).wfa_rng === '' && vals(els).wfa_lo === '10.1' && vals(els).wfa_hi === '10.0');
}

// ---- 4) a rule that returns only the z-score needs no limits ----------------------------
{
  const els = form('1', '11', ['wfa_z']);
  const env = boot(els, { growth: GROWTH, rules: [{ type: 'range', fields: ['weight'], rangeReference: 't-days', rangeMale: '1',
    rangeFemale: '2', rangeReturn: { z: 'wfa_z' } , rangeDecimals: 1 }].map((r) => Object.assign(r, LIVE)) });
  check('z only, no limits: no configuration error', !cfgErr(env, 'weight'));
  check('z only: z 1.00', by(els, 'wfa_z').value === '1.00');
  check('z only: nothing is flagged', !shown(nMsg(env, 'weight')));
  by(els, 'weight').value = '-3'; by(els, 'weight').fire('change');
  check('z only: a measurement at or below 0 is still held', /must be above 0/.test(nMsg(env, 'weight').innerHTML));
}

// ---- 5) a number the field already holds is left alone -----------------------------------
{
  const els = form('1', '11');
  by(els, 'wfa_lo').value = '8.00';      // the same number as 8.0
  by(els, 'wfa_z').value = '1';          // the same number as 1.00
  by(els, 'wfa_hi').value = '11.9';      // not the same
  boot(els, { growth: GROWTH, rules: [rule()] });
  check('the same number in another spelling stays (got ' + asText(vals(els)) + ')',
    by(els, 'wfa_lo').value === '8.00' && by(els, 'wfa_z').value === '1' && by(els, 'wfa_hi').value === '12.0');
}

// ---- 6) a branched rule owns the fields of every branch ----------------------------------
{
  const els = form('1', '11', ['wfa_z', 'wfa_rng']);
  els.push(el('grp', 'a'));
  const A = rule({ when: "[grp]='a'", rangeReturn: { z: 'wfa_z' } });
  const B = rule({ when: "[grp]<>'a'", rangeReturn: { range: 'wfa_rng' } });
  const env = boot(els, { growth: GROWTH, rules: [{ type: 'range', fields: ['weight'], branches: [A, B] }] });
  check('branch a fills the z-score and leaves the range empty (got ' + asText(vals(els)) + ')',
    by(els, 'wfa_z').value === '1.00' && by(els, 'wfa_rng').value === '');
  by(els, 'grp').value = 'b'; by(els, 'grp').fire('change');
  check('branch b fills the range and empties the z-score (got ' + asText(vals(els)) + ')',
    by(els, 'wfa_z').value === '' && by(els, 'wfa_rng').value === '8.0 to 12.0 kg');
  check('a branched rule judges as before', !shown(nMsg(env, 'weight')) && env.NS.validators.weight.test() === true);
}
{
  // no branch applies: the rule is inert here and the fields it owns are emptied
  const els = form('1', '11', ['wfa_z']);
  els.push(el('grp', 'a'));
  const A = rule({ when: "[grp]='a'", rangeReturn: { z: 'wfa_z' } });
  boot(els, { growth: GROWTH, rules: [{ type: 'range', fields: ['weight'], branches: [A] }] });
  check('branch a: filled', by(els, 'wfa_z').value === '1.00');
  by(els, 'grp').value = 'c'; by(els, 'grp').fire('change');
  check('no branch applies: emptied (got ' + by(els, 'wfa_z').value + ')', by(els, 'wfa_z').value === '');
}
{
  // a plain rule with a "when" that is false: inert, the fields are emptied
  const els = form('1', '11', ['wfa_z']);
  els.push(el('grp', 'a'));
  boot(els, { growth: GROWTH, rules: [rule({ when: "[grp]='a'", rangeReturn: { z: 'wfa_z' } })] });
  check('when true: filled', by(els, 'wfa_z').value === '1.00');
  by(els, 'grp').value = 'b'; by(els, 'grp').fire('change');
  check('when false: emptied', by(els, 'wfa_z').value === '');
}

// ---- 7) what the page cannot judge leaves the fields as they are -------------------------
{
  const els = form('1', '11');
  for (const f of Object.values(ALL)) by(els, f).value = 'kept';
  const env = boot(els, { rules: [rule({ rangeSexOp: ['withheld'], rangeAgeDobOp: ['withheld'], rangeAgeAtOp: ['lit', '2026-01-03'] })] });
  check('an input withheld from this page: fields keep their value (got ' + asText(vals(els)) + ')',
    Object.values(vals(els)).every((x) => x === 'kept'));
  check('... and no configuration error', !cfgErr(env, 'weight'));
  const env2 = boot(els, { growth: GROWTH, rules: [rule({ rangeSexOp: ['withheld'], rangeAgeDobOp: ['withheld'], rangeAgeAtOp: ['lit', '2026-01-03'] })] });
  check('... with a table too', Object.values(vals(els)).every((x) => x === 'kept') && !cfgErr(env2, 'weight'));
}
{
  const CAP = ['this form already carries 4 growth reference tables, the most one page may carry (4), so this check runs when the record is saved.'];
  const els = form('1', '11');
  for (const f of Object.values(ALL)) by(els, f).value = 'kept';
  const env = boot(els, { rules: [rule({ deferred: true, deferredOnSave: true, deferredWhy: CAP })] });
  check('deferred for the table cap: fields keep their value (the save fills them)', Object.values(vals(els)).every((x) => x === 'kept')
    && !cfgErr(env, 'weight'));
}
{
  // a branch conflict (two conditions true at once) judges and returns nothing
  const els = form('1', '11', ['wfa_z']);
  els.push(el('grp', 'a'));
  by(els, 'wfa_z').value = 'kept';
  boot(els, { growth: GROWTH, rules: [{ type: 'range', fields: ['weight'], branches: [
    rule({ when: "[grp]='a'", rangeReturn: { z: 'wfa_z' } }), rule({ when: "[grp]<>''", rangeReturn: { z: 'wfa_z' } })] }] });
  check('a branch conflict: the field keeps its value', by(els, 'wfa_z').value === 'kept');
}

// ---- 8) the rest -------------------------------------------------------------------------
{
  // the fields are not on this page (another page of a survey): nothing to write, nothing thrown
  const els = form('1', '11', []);
  let threw = false;
  try { boot(els, { growth: GROWTH, rules: [rule()] }); } catch (e) { threw = true; }
  check('target fields not on the page: no error', !threw);
}
{
  const els = form('1', 'UNK');
  boot(els, { missingCodes: ['UNK'], growth: GROWTH, rules: [rule()] });
  check('a Missing Data Code in the measurement is blank: no z-score, the bounds stay (got ' + asText(vals(els)) + ')',
    vals(els).wfa_z === '' && vals(els).wfa_rng === '8.0 to 12.0 kg');
}
{
  const els = form('1', '11');
  boot(els, { context: 'survey', growth: GROWTH, rules: [rule()] });
  check('a survey fills the fields too', vals(els).wfa_z === '1.00' && vals(els).wfa_rng === '8.0 to 12.0 kg');
}
{
  // a calc or another rule hears the written value: a second rule reads the z-score field
  const els = form('1', '11', ['wfa_z']);
  els.push(el('flag', ''));
  const env = boot(els, { growth: GROWTH, rules: [rule({ rangeReturn: { z: 'wfa_z' } }),
    { type: 'range', fields: ['wfa_z'], rangeSoftLo: '-2', rangeSoftHi: '2', rangeHardLo: '-5', rangeHardHi: '5' }] });
  by(els, 'weight').value = '13'; by(els, 'weight').fire('change');
  check('a rule on the returned field hears the change at once (z 3.00 is unusual; got ' + (nMsg(env, 'wfa_z') ? nMsg(env, 'wfa_z').innerHTML : 'no note') + ')',
    nMsg(env, 'wfa_z') && /higher than usual/.test(nMsg(env, 'wfa_z').innerHTML));
}

// ---- 9) configuration errors ---------------------------------------------------------------
{
  const els = form('1', '11');
  const env = boot(els, { rules: [{ type: 'range', fields: ['weight'], rangeSoftLo: '1', rangeSoftHi: '2', rangeReturn: { z: 'wfa_z' } }] });
  check('"return" without a reference: configuration error', cfgErr(env, 'weight') && /needs a growth/.test(cfgErr(env, 'weight').innerHTML));
}
{
  const els = form('1', '11');
  const env = boot(els, { growth: GROWTH, rules: [rule({ rangeReturn: { nope: 'wfa_z' } })] });
  check('an unknown value name: configuration error', cfgErr(env, 'weight') && /not understood/.test(cfgErr(env, 'weight').innerHTML));
  check('... nothing is written', vals(els).wfa_z === '');
}
{
  const els = form('1', '11');
  const env = boot(els, { growth: GROWTH, rules: [rule({ rangeReturnLimits: 'medium' })] });
  check('returnLimits that is neither soft nor hard: configuration error', cfgErr(env, 'weight')
    && /returnLimits(?:"|&quot;) must be (?:"|&quot;)soft/.test(cfgErr(env, 'weight').innerHTML));
}
{
  const els = form('1', '11');
  const env = boot(els, { growth: GROWTH, rules: [rule({ rangeDecimals: 9 })] });
  check('decimals past 6: configuration error', cfgErr(env, 'weight') && /decimals(?:"|&quot;) must be a whole number from 0 to 6/.test(cfgErr(env, 'weight').innerHTML));
}
{
  // a limit-less rule that returns more than the z-score still needs its limits
  const els = form('1', '11');
  const env = boot(els, { growth: GROWTH, rules: [{ type: 'range', fields: ['weight'], rangeReference: 't-days', rangeMale: '1',
    rangeFemale: '2', rangeReturn: { z: 'wfa_z', range: 'wfa_rng' } }].map((r) => Object.assign(r, LIVE)) });
  check('no limits and more than z: the usual "no limits" error', cfgErr(env, 'weight') && /has no limits to check/.test(cfgErr(env, 'weight').innerHTML));
}

console.log('growth_return_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
