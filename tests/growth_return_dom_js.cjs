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
 *     measurement, a survey, and the configuration errors,
 *   - the writer itself (NS.returnWriter.write, shared with @UVEXISTS
 *     "return") on each kind of field: a dropdown and its autocomplete
 *     companion, a radio's hidden mirror and buttons, a textarea, and the
 *     "exact" flag that keeps "007" from passing for "7"; a dropdown or radio
 *     code the page does not offer empties the field and names it.
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

{
  // a branch that returns nothing owns no field, but the rule does: the other branch's fields are emptied
  const els = form('1', '11', ['wfa_z']);
  els.push(el('grp', 'a'));
  const A = rule({ when: "[grp]='a'", rangeReturn: { z: 'wfa_z' } });
  const B = rule({ when: "[grp]<>'a'" });
  delete B.rangeReturn;
  const env = boot(els, { growth: GROWTH, rules: [{ type: 'range', fields: ['weight'], branches: [A, B] }] });
  check('branch a: the z-score is filled', by(els, 'wfa_z').value === '1.00');
  by(els, 'grp').value = 'b'; by(els, 'grp').fire('change');
  check('branch b returns nothing: the z-score of branch a is emptied (got ' + by(els, 'wfa_z').value + ')', by(els, 'wfa_z').value === '');
  by(els, 'weight').value = '13'; by(els, 'weight').fire('change');
  check('... branch b still judges, and still writes nothing (got ' + by(els, 'wfa_z').value + ')',
    /higher than usual/.test(nMsg(env, 'weight').innerHTML) && by(els, 'wfa_z').value === '');
}

// ---- 6b) two references on one field: each fills its own fields at the same time ----------
/* a second table: for a boy, z is (y/11 - 1)/0.1 (11 kg is z 0) */
const T2 = Object.assign({}, T_DAYS, { title: 'Test 2', male: ROWS_M.map(() => [1, 11, 0.1]) });
{
  const els = form('1', '11', ['wfa_z', 'z2']);
  els.push(el('grp', 'a'));
  const A = rule({ rangeReturn: { z: 'wfa_z' } });
  const B = rule({ rangeReference: 't2', when: "[grp]='a'", rangeReturn: { z: 'z2' } });
  boot(els, { growth: { 't-days': T_DAYS, t2: T2 }, rules: [{ type: 'range', fields: ['weight'], branches: [A, B] }] });
  check('two references: both z-scores are filled (got ' + by(els, 'wfa_z').value + ', ' + by(els, 'z2').value + ')',
    by(els, 'wfa_z').value === '1.00' && by(els, 'z2').value === '0.00');
  by(els, 'grp').value = 'b'; by(els, 'grp').fire('change');
  check('the second reference applies nowhere: its field is emptied, the first keeps its own',
    by(els, 'z2').value === '' && by(els, 'wfa_z').value === '1.00');
  by(els, 'weight').value = '13'; by(els, 'weight').fire('change');
  check('weight 13: the first refills, the second stays empty', by(els, 'wfa_z').value === '3.00' && by(els, 'z2').value === '');
  by(els, 'grp').value = 'a'; by(els, 'grp').fire('change');
  check('the second applies again and fills (got ' + by(els, 'z2').value + ')', by(els, 'z2').value === '1.82');
}
{
  // a reference the page cannot judge (deferred) keeps its field; the other reference still fills its own
  const els = form('1', '11', ['wfa_z', 'z2']);
  by(els, 'z2').value = 'kept';
  const A = rule({ rangeReturn: { z: 'wfa_z' } });
  const B = rule({ rangeReference: 't2', deferred: true, rangeReturn: { z: 'z2' } });
  boot(els, { growth: { 't-days': T_DAYS, t2: T2 }, rules: [{ type: 'range', fields: ['weight'], branches: [A, B] }] });
  check('a deferred reference keeps its field, the other fills (got ' + by(els, 'wfa_z').value + ', ' + by(els, 'z2').value + ')',
    by(els, 'wfa_z').value === '1.00' && by(els, 'z2').value === 'kept');
}
{
  // two branches of the second reference apply at once: a branch conflict leaves the whole field unjudged,
  // so neither reference writes (the save skips the field and keeps them too)
  const els = form('1', '11', ['wfa_z', 'z2']);
  by(els, 'wfa_z').value = 'kept'; by(els, 'z2').value = 'kept';
  const A = rule({ rangeReturn: { z: 'wfa_z' } });
  const B1 = rule({ rangeReference: 't2', when: "[sex]='1'", rangeReturn: { z: 'z2' } });
  const B2 = rule({ rangeReference: 't2', when: "[weight]<>''", rangeReturn: { z: 'z2' } });
  boot(els, { growth: { 't-days': T_DAYS, t2: T2 }, rules: [{ type: 'range', fields: ['weight'], branches: [A, B1, B2] }] });
  check('a conflict in one reference: both references keep their fields (got ' + by(els, 'wfa_z').value + ', ' + by(els, 'z2').value + ')',
    by(els, 'wfa_z').value === 'kept' && by(els, 'z2').value === 'kept');
}

// ---- 6c) "measureScale" and "unit": the bounds in the unit the field holds -----------------
{
  const els = form('1', '11000');
  const env = boot(els, { growth: GROWTH, rules: [rule({ rangeMeasureScale: '0.001', rangeUnit: 'g', rangeDecimals: 0 })] });
  const o = env.NS.growthLogic.outputs(T_DAYS, 'male', 2, 11, { lo: '-2', hi: '2' }, 0, false, '0.001', 'g');
  const v = vals(els);
  check('grams into a table in kg: z, whole-gram bounds and a range in g (got ' + asText(v) + ')',
    v.wfa_z === '1.00' && v.wfa_lo === o.lower && v.wfa_hi === o.upper && v.wfa_rng === o.range
    && /^\d+ to \d+ g$/.test(v.wfa_rng) && +v.wfa_lo > 7990 && +v.wfa_lo <= 8000 && +v.wfa_hi >= 12000 && +v.wfa_hi < 12010);
}
{
  const els = form('1', '11000');
  boot(els, { growth: GROWTH, rules: [rule({ rangeMeasureScale: '0.001', rangeDecimals: 0 })] });
  check('... without "unit" the range names no unit, not the table\'s kg (got ' + by(els, 'wfa_rng').value + ')',
    /^\d+ to \d+$/.test(by(els, 'wfa_rng').value));
}

// ---- 7) what the page cannot judge leaves the fields as they are -------------------------
/* a page whose fields already hold a value: every box says "kept". Each page of a test is
   built from fresh elements: a field a page has bound is not bound a second time. */
function keptForm() {
  const els = form('1', '11');
  for (const f of Object.values(ALL)) by(els, f).value = 'kept';
  return els;
}
{
  const WITHHELD = { rangeSexOp: ['withheld'], rangeAgeDobOp: ['withheld'], rangeAgeAtOp: ['lit', '2026-01-03'] };
  const els = keptForm();
  const env = boot(els, { rules: [rule(WITHHELD)] });
  check('an input withheld from this page, no table sent: fields keep their value (got ' + asText(vals(els)) + ')',
    Object.values(vals(els)).every((x) => x === 'kept'));
  check('... and no configuration error', !cfgErr(env, 'weight'));
  const els2 = keptForm();
  const env2 = boot(els2, { growth: GROWTH, rules: [rule(WITHHELD)] });
  check('... with a table too (got ' + asText(vals(els2)) + ')', Object.values(vals(els2)).every((x) => x === 'kept') && !cfgErr(env2, 'weight'));
  // the same rule with every input readable does write: the keep above is the withheld input's doing
  const els3 = keptForm();
  boot(els3, { growth: GROWTH, rules: [rule({})] });
  check('... and with the inputs readable the fields are written', vals(els3).wfa_z === '1.00');
}
{
  const CAP = ['this form already carries 4 growth reference tables, the most one page may carry (4), so this check runs when the record is saved.'];
  const els = keptForm();
  const env = boot(els, { rules: [rule({ deferred: true, deferredOnSave: true, deferredWhy: CAP })] });
  check('deferred for the table cap: fields keep their value (the save fills them)', Object.values(vals(els)).every((x) => x === 'kept')
    && !cfgErr(env, 'weight'));
  // with the table there too (the page was given it), a deferred rule still writes nothing
  const els2 = keptForm();
  boot(els2, { growth: GROWTH, rules: [rule({ deferred: true, deferredOnSave: true, deferredWhy: CAP })] });
  check('... with the table on the page too', Object.values(vals(els2)).every((x) => x === 'kept'));
}
{
  // one branch deferred by the server (the table is on the page for the other branch): the branch is
  // not judged here, so the fields are left to the save
  const WHY = ['this form already carries 4 growth reference tables, the most one page may carry (4), so this check runs when the record is saved.'];
  const A = rule({ when: "[grp]='a'", rangeReturn: { z: 'wfa_z' } });
  const els = keptForm(); els.push(el('grp', 'b'));
  const env = boot(els, { growth: GROWTH, rules: [{ type: 'range', fields: ['weight'], branches: [A,
    rule({ when: "[grp]<>'a'", deferred: true, deferredWhy: WHY })] }] });
  check('a deferred branch: the fields keep their value (got ' + asText(vals(els)) + ')',
    Object.values(vals(els)).every((x) => x === 'kept') && !cfgErr(env, 'weight'));
  // the same branch not deferred does write: the keep above is the deferral's doing
  const els2 = keptForm(); els2.push(el('grp', 'b'));
  boot(els2, { growth: GROWTH, rules: [{ type: 'range', fields: ['weight'], branches: [A, rule({ when: "[grp]<>'a'" })] }] });
  check('... the same branch, not deferred, writes (got ' + asText(vals(els2)) + ')', vals(els2).wfa_z === '1.00');
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

// ---- 7b) a write sets off the rules that read the field, to a bounded depth ----------------
/* a table where z = y - 1: the z-score of a z-score is a positive number again, so a chain of
   rules, each reading the field the one before returned into, keeps going (a z-score is
   written up to 999.99, so the numbers stay small) */
const ROWS_U = ROWS_M.map(() => [1, 1, 1]);
const T_UNIT = Object.assign({}, T_DAYS, { title: 'Test, unit table', male: ROWS_U, female: ROWS_U });
function link(from, to) {
  return Object.assign({ type: 'range', fields: [from], rangeReference: 't-unit', rangeMale: '1', rangeFemale: '2',
    rangeDecimals: 1, rangeReturn: { z: to } }, LIVE);
}
{
  const names = ['f1', 'f2', 'f3', 'f4', 'f5', 'f6'];
  const els = form('1', '20', names);
  boot(els, { growth: { 't-unit': T_UNIT }, rules: names.map((to, i) => link(i === 0 ? 'weight' : names[i - 1], to)) });
  const got = () => names.map((f) => by(els, f).value);
  check('opening: every link of the chain is filled (got ' + got() + ')',
    same(got(), ['19.00', '18.00', '17.00', '16.00', '15.00', '14.00']));
  by(els, 'weight').value = '30'; by(els, 'weight').fire('change');
  // a write that sets off a write that sets off a write... goes 4 deep; the 5th and 6th link wait for the save
  check('a change runs down the chain as far as the depth bound (got ' + got() + ')',
    same(got(), ['29.00', '28.00', '27.00', '26.00', '15.00', '14.00']));
}
{
  // two rules that return into each other's measured field settle only when the value reaches 0
  // (each hop takes 1 off, from 500): the depth bound ends the loop long before. The stub gives up
  // after 100 events on a field, so that a missing bound shows as a tripped breaker, not as a hang.
  const els = form('1', '30', ['f1']);
  let tripped = false;
  const breaker = (e) => { const orig = e.dispatchEvent; let left = 100;
    e.dispatchEvent = function (ev) { if (left-- <= 0) { tripped = true; throw new Error('runaway'); } return orig.call(this, ev); }; };
  breaker(by(els, 'weight')); breaker(by(els, 'f1'));
  boot(els, { growth: { 't-unit': T_UNIT }, rules: [link('weight', 'f1'), link('f1', 'weight')] });
  by(els, 'weight')._events.length = 0; by(els, 'f1')._events.length = 0;
  by(els, 'weight').value = '500'; by(els, 'weight').fire('change');
  const heard = by(els, 'weight')._events.length + by(els, 'f1')._events.length;
  check('two rules feeding each other: the loop is cut short (' + heard + ' events)', heard > 0 && !tripped);
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
  // a Missing Data Code that is a number is no measurement either
  const els = form('1', '999');
  boot(els, { missingCodes: ['999'], growth: GROWTH, rules: [rule()] });
  check('a numeric Missing Data Code is blank: no z-score, the bounds stay (got ' + asText(vals(els)) + ')',
    vals(els).wfa_z === '' && vals(els).wfa_rng === '8.0 to 12.0 kg');
  const els2 = form('1', '999');
  boot(els2, { growth: GROWTH, rules: [rule()] });
  check('... with no such code configured, 999 is a weight (got ' + vals(els2).wfa_z + ')', vals(els2).wfa_z === '989.00');
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

/* ---- the writer by field type (QRID_writeReturned, shared with @UVEXISTS "return") ---- */
function opt(value, text) { return { value: value, text: text }; }
function select(name, codes) {
  const s = makeEl('select'); s.name = name;
  s.options = codes.map(([v, t]) => opt(v, t));
  return s;
}
function radioGroup(name, codes) {
  const mirror = el(name, ''); mirror.type = 'hidden';
  const buttons = codes.map((c) => { const r = el(name + '___radio', c); r.type = 'radio'; return r; });
  return [mirror].concat(buttons);
}
{
  const s = select('site', [['', ''], ['1', 'North'], ['2', 'South']]);
  const comp = el('site_ac', ''); comp.setAttribute('id', 'rc-ac-input_site');
  const [mirror, ...buttons] = radioGroup('arm', ['1', '2', '3']);
  const area = makeEl('textarea'); area.name = 'note'; area.value = '';
  const txt = el('code_id', '007');
  const num = el('dose', '7.80');
  const env = boot([s, comp, mirror].concat(buttons, [area, txt, num]), { rules: [] });
  const W = env.NS.returnWriter.write;
  check('writer: a dropdown takes a code it offers', W('site', '2', true) === true && s.value === '2');
  check('... its autocomplete companion shows the label', comp.value === 'South');
  check('... and REDCap hears input and change on the select', s._events.join(',') === 'input,change');
  const lostSite = [];
  s._events = [];
  check('writer: a code the dropdown does not offer empties it and its companion, and names the field',
    W('site', '9', true, true, lostSite) === true && s.value === '' && comp.value === '' && lostSite.join() === 'site'
    && s._events.join(',') === 'input,change');
  const lostAgain = [];
  check('... already empty: nothing to write, the field is still named', W('site', '9', true, true, lostAgain) === false && lostAgain.join() === 'site');
  check('... and without a list to name it in, still emptied', W('site', '1', true) === true && W('site', '9', true) === true && s.value === '' && comp.value === '');
  W('site', '2', true);
  check('writer: "" empties the dropdown and its companion', W('site', '', true) === true && s.value === '' && comp.value === '');
  check('writer: a radio gets the code in its mirror and the matching button checked',
    W('arm', '2', true) === true && mirror.value === '2' && buttons.map((b) => b.checked).join() === 'false,true,false');
  check('... the newly checked button raises change', buttons[1]._events.includes('change') && !buttons[0]._events.includes('change'));
  check('writer: another code moves the check', W('arm', '3', false) === true && buttons.map((b) => b.checked).join() === 'false,false,true');
  check('... silently when not announced', !buttons[2]._events.length && mirror._events.join(',') === 'input,change');
  check('writer: "" unchecks every button', W('arm', '', true) === true && mirror.value === '' && buttons.every((b) => !b.checked));
  W('arm', '1', true);
  const lostArm = [];
  check('writer: a code no radio button carries empties the mirror and unchecks every button, and names the field',
    W('arm', '7', true, true, lostArm) === true && mirror.value === '' && buttons.every((b) => !b.checked) && lostArm.join() === 'arm');
  check('writer: a textarea takes text', W('note', 'line one', true) === true && area.value === 'line one');
  check('writer: exact — a Text field holding "007" takes "7"', W('code_id', '7', true, true) === true && txt.value === '7');
  check('writer: not exact — "7.8" is the same number as "7.80", left alone', W('dose', '7.8', true) === false && num.value === '7.80');
  check('writer: exact — "7.8" replaces "7.80"', W('dose', '7.8', true, true) === true && num.value === '7.8');
  check('writer: the same value writes nothing', W('note', 'line one', true) === false);
  check('writer: a field the page does not carry writes nothing', W('absent', 'x', true) === false);
  const lostText = [];
  check('writer: a Text field takes any text, and names nothing', W('code_id', '9', true, true, lostText) === true && txt.value === '9' && !lostText.length);
  check('writer: calculate and doBranching hear every write', env.win._calc.includes('site') && env.win._branch.includes('arm'));
}

// ---- the percentile and the values at a z-score or percentile (php/growth_outputs.json) ------------
{
  /* For the boy of the table, z is (y/10 - 1)/0.1: 11 kg is z 1 (percentile 84.1), the measurement at
     z -2 is 8.0, at percentile 97 (z 1.8808) 11.88, at percentile 50 10.0. For the girl, M is 8. */
  const NEW = { percentile: 'wfa_pct', 'valueAtZ:-2': 'wfa_m2', 'valueAtPercentile:97': 'wfa_p97', 'valueAtPercentile:50': 'wfa_p50' };
  const NO_LIMITS = { rangeSoftLo: undefined, rangeSoftHi: undefined, rangeHardLo: undefined, rangeHardHi: undefined };
  const read = (els) => { const o = {}; for (const f of Object.values(NEW)) o[f] = by(els, f).value; return o; };
  const els = form('1', '11', Object.values(NEW));
  const env = boot(els, { growth: GROWTH, rules: [rule(Object.assign({ rangeReturn: NEW, rangePercentileDecimals: 1 }, NO_LIMITS))] });
  check('outputs: a rule that returns only the percentile and reference values needs no limits', !shown(cfgErr(env, 'weight')));
  check('outputs: filled as the page opens (got ' + asText(read(els)) + ')',
    same(read(els), { wfa_pct: '84.1', wfa_m2: '8.0', wfa_p97: '11.9', wfa_p50: '10.0' }));
  check('outputs: the opening fill announces nothing', Object.values(NEW).every((f) => by(els, f)._events.length === 0));
  const w = by(els, 'weight');
  w.value = '8'; w.fire('change');
  check('outputs: 8 kg moves the percentile to 2.3, the reference values stay (got ' + asText(read(els)) + ')',
    same(read(els), { wfa_pct: '2.3', wfa_m2: '8.0', wfa_p97: '11.9', wfa_p50: '10.0' }));
  check('outputs: the percentile hears an input and a change, the unchanged values nothing',
    same(by(els, 'wfa_pct')._events, ['input', 'change']) && by(els, 'wfa_m2')._events.length === 0);
  w.value = ''; w.fire('change');
  check('outputs: no measurement empties the percentile and keeps the reference values (got ' + asText(read(els)) + ')',
    same(read(els), { wfa_pct: '', wfa_m2: '8.0', wfa_p97: '11.9', wfa_p50: '10.0' }));
  const sex = by(els, 'sex');
  w.value = '11'; w.fire('change');
  sex.value = '2'; sex.fire('change');
  check('outputs: a girl of 11 kg (z 3.75) is kept at 99.9, and her reference values follow (got ' + asText(read(els)) + ')',
    same(read(els), { wfa_pct: '99.9', wfa_m2: '6.4', wfa_p97: '9.5', wfa_p50: '8.0' }));
  sex.value = ''; sex.fire('change');
  check('outputs: no sex empties every field', Object.values(read(els)).every((x) => x === ''));
  /* a form opened before anything is measured shows the reference values */
  const blank = form('1', '', Object.values(NEW));
  boot(blank, { growth: GROWTH, rules: [rule(Object.assign({ rangeReturn: NEW, rangePercentileDecimals: 1 }, NO_LIMITS))] });
  check('outputs: opened with the weight blank, the reference values are filled and the percentile is empty (got '
    + asText(read(blank)) + ')', same(read(blank), { wfa_pct: '', wfa_m2: '8.0', wfa_p97: '11.9', wfa_p50: '10.0' }));
}
{
  const NEW = { percentile: 'wfa_pct', 'valueAtPercentile:97': 'wfa_p97' };
  const read = (els) => [by(els, 'wfa_pct').value, by(els, 'wfa_p97').value].join(' ');
  let els = form('1', '11', Object.values(NEW));
  boot(els, { growth: GROWTH, rules: [rule({ rangeReturn: NEW, rangePercentileDecimals: 3, rangeDecimals: 2 })] });
  check('outputs: percentileDecimals 3 and decimals 2 (got ' + read(els) + ')', read(els) === '84.134 11.88');
  els = form('1', '11', Object.values(NEW));
  boot(els, { growth: GROWTH, rules: [rule({ rangeReturn: NEW, rangePercentileDecimals: 0, rangeDecimals: 0 })] });
  check('outputs: no decimals (got ' + read(els) + ')', read(els) === '84 12');
  els = form('1', '11', Object.values(NEW));
  boot(els, { growth: GROWTH, rules: [rule({ rangeReturn: NEW, rangePercentileDecimals: 1,
    rangeReturnComma: { percentile: true, 'valueAtPercentile:97': true } })] });
  check('outputs: each field gets its own decimal mark (got ' + read(els) + ')', read(els) === '84,1 11,9');
  els = form('1', '11', Object.values(NEW));
  boot(els, { growth: GROWTH, rules: [rule({ rangeReturn: NEW })] });
  check('outputs: a percentile without percentileDecimals is written with one decimal', read(els) === '84.1 11.9');
}
{
  const bad = [
    [{ 'valueAtZ:9': 'x' }, {}, 'a z-score out of range'],
    [{ 'percentile:5': 'x' }, {}, 'a parameter on an output that takes none'],
    [{ 'valueAtZ': 'x' }, {}, 'an output without its parameter'],
    [{ percentile: 'x' }, { rangePercentileDecimals: 4 }, 'percentileDecimals out of range'],
    [{ percentile: 'x' }, { rangePercentileDecimals: '1' }, 'percentileDecimals as text'],
  ];
  for (const [ret, extra, label] of bad) {
    const els = form('1', '11', ['x']);
    const env = boot(els, { growth: GROWTH, rules: [rule(Object.assign({ rangeReturn: ret }, extra))] });
    check('outputs: a configuration error, ' + label, shown(cfgErr(env, 'weight')) && by(els, 'x').value === '');
  }
  const els = form('1', '11', ['x']);
  const env = boot(els, { growth: GROWTH, rules: [rule({ rangeReturn: { lower: 'x', percentile: 'y' }, rangeSoftLo: undefined,
    rangeSoftHi: undefined, rangeHardLo: undefined, rangeHardHi: undefined })] });
  check('outputs: a bound with a percentile still needs a limit', shown(cfgErr(env, 'weight'))
    && /no limits to check/.test(cfgErr(env, 'weight').innerHTML));
}

console.log('growth_return_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
