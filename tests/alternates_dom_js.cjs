/*
 * alternates_dom_js.cjs — multi-format rules in the browser.
 *
 * One rule may accept SEVERAL ID formats, each with its own pattern and its own
 * check algorithm (or "none"). A value/token is valid if any one alternate
 * accepts it. This suite locks the client behaviour a mixed pool depends on:
 *   - a single field accepts every declared format and rejects everything else,
 *   - a format's SHAPE matching but its CHECK failing is reported as a check
 *     error, not a format error (the reason precedence the audit mirrors),
 *   - suggestFix is offered for ONE candidate format and suppressed for two,
 *   - typing guidance narrows to one alternate and names the candidates while
 *     several are still live,
 *   - a pooled field splits a jammed mixed run at the right boundaries and
 *     still catches a broken check character,
 *   - a legacy scalar rule is unchanged (one alternate, today's wording).
 *
 * Run:  node tests/alternates_dom_js.cjs
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

// ---- the four families of the sample-transportation project ---------------
const MOD = 'iso7064_mod37_36';
const ALTS = [
  { label: 'GHIT',       pattern: 'FC[1-9]-[0-9]{4}',         algorithm: 'none', lengths: [8] },
  { label: 'START4KIDS', pattern: 'SK[1-5]-[0-9]{4}[0-9A-Z]', algorithm: MOD,    lengths: [9] },
  { label: 'DARETB',     pattern: 'DT[1-2]-[0-9]{5}[0-9A-Z]', algorithm: MOD,    lengths: [10] },
  { label: 'SCREENTB',   pattern: 'ST[1-5]-[0-9]{5}[0-9A-Z]', algorithm: MOD,    lengths: [10] },
];
const singleAlts = ALTS.map((a) => ({ label: a.label, pattern: a.pattern, algorithm: a.algorithm }));

// Minted with the dash stripped, which is how the companion generator mints
// them (verified against the FC1-0589C / FC3-0179J anchors).
function mint(env, base) {
  const Q = env.NS.engine;
  return base + Q.computeCheck(base, Q.makeScheme({
    algorithm: MOD, source: 'normalized_id', placement: 'append',
    normalize_rules: { strip_delimiters: '-', uppercase: true, unify_unicode_dashes: true, keep_only: null },
    enabled: true,
  }));
}

function singleEnv(value, extra) {
  const sid = makeEl('input'); sid.name = 'sid'; sid.value = value;
  const cfg = Object.assign({ type: 'single', fields: ['sid'], strip: '-', alternates: singleAlts }, extra || {});
  const env = boot([sid], { singleFields: [], pooledFields: [], rules: [cfg] });
  return { env, sid, msg: msgOf(env, 'sid') };
}

// ---- 1) a single field accepts every declared format ----------------------
{
  const probe = boot([], { singleFields: [], pooledFields: [], rules: [] });
  const SK = mint(probe, 'SK1-0123'), DT = mint(probe, 'DT1-12345'), ST = mint(probe, 'ST3-77012');

  for (const [label, v] of [['GHIT', 'FC1-0589'], ['START4KIDS', SK], ['DARETB', DT], ['SCREENTB', ST]]) {
    const { sid, msg } = singleEnv(v);
    check(`single: ${label} accepted`, sid.__qridInvalid === false && /&#10003;/.test(msg.innerHTML));
  }
  // GHIT has no check character, so its verdict must say so and must NOT claim
  // the whole project is check-free — three of the four formats do have one.
  {
    const { msg } = singleEnv('FC1-0589');
    check('single: regex-only alternate names itself, not "this project"',
      /GHIT/.test(msg.innerHTML) && !/This project's IDs carry/.test(msg.innerHTML));
  }
  // a shape that matches nothing
  {
    const { sid, msg } = singleEnv('ZZ9-9999');
    check('single: unmatched value is a FORMAT error naming the formats',
      sid.__qridInvalid === true && /FORMAT error/.test(msg.innerHTML)
      && /GHIT/.test(msg.innerHTML) && /SCREENTB/.test(msg.innerHTML));
  }
  // reason precedence: SK's shape matches, its check does not -> CHECK error
  {
    const bad = SK.slice(0, 5) + (SK[5] === '9' ? '8' : '9') + SK.slice(6);
    const { sid, msg } = singleEnv(bad);
    check('single: shape matched + check failed = CHECK error, not FORMAT',
      sid.__qridInvalid === true && /CHECK character/.test(msg.innerHTML)
      && !/FORMAT error/.test(msg.innerHTML));
  }
}

// ---- 2) suggestFix is offered for one candidate, suppressed for two -------
{
  const probe = boot([], { singleFields: [], pooledFields: [], rules: [] });
  const SK = mint(probe, 'SK1-0123');
  const bad = SK.slice(0, 5) + (SK[5] === '9' ? '8' : '9') + SK.slice(6);
  {
    const { msg } = singleEnv(bad, { suggestFix: true });
    check('single: suggestFix offered when exactly one shape matched',
      /should end in/.test(msg.innerHTML));
  }
  // Two check-bearing alternates sharing one shape: naming either check
  // character would be a coin flip presented as advice.
  {
    const sid = makeEl('input'); sid.name = 'sid'; sid.value = 'SK1-0123Q';
    const env = boot([sid], { singleFields: [], pooledFields: [], rules: [{
      type: 'single', fields: ['sid'], strip: '-', suggestFix: true,
      alternates: [
        { label: 'A', pattern: 'SK[1-5]-[0-9]{4}[0-9A-Z]', algorithm: MOD },
        { label: 'B', pattern: 'SK[1-5]-[0-9]{4}[0-9A-Z]', algorithm: 'damm' },
      ],
    }] });
    const msg = msgOf(env, 'sid');
    check('single: suggestFix suppressed when two shapes matched',
      /CHECK character/.test(msg.innerHTML) && !/should end in/.test(msg.innerHTML));
  }
}

// ---- 3) typing guidance narrows to one alternate --------------------------
{
  // "F" can only be GHIT -> precise remaining-characters guidance
  {
    const { sid, msg } = singleEnv('F');
    sid.value = 'F'; sid.fire('blur');
    check('guidance: one live alternate gives remaining characters',
      /GHIT/.test(msg.innerHTML) && /remaining/i.test(msg.innerHTML));
  }
  // "S" is still both START4KIDS and SCREENTB -> name them, promise nothing
  {
    const { sid, msg } = singleEnv('S');
    sid.value = 'S'; sid.fire('blur');
    check('guidance: several live alternates are named, not guessed',
      /still matching/i.test(msg.innerHTML)
      && /START4KIDS/.test(msg.innerHTML) && /SCREENTB/.test(msg.innerHTML));
    check('guidance: an incomplete value still holds the save',
      sid.__qridInvalid === true);
  }
  // "SK1-" has narrowed back to one
  {
    const { sid, msg } = singleEnv('SK1-');
    sid.value = 'SK1-'; sid.fire('blur');
    check('guidance: narrows back to one alternate once the prefix decides',
      /START4KIDS/.test(msg.innerHTML) && !/SCREENTB/.test(msg.innerHTML));
  }
}

// ---- 4) a legacy scalar rule is untouched --------------------------------
{
  const sid = makeEl('input'); sid.name = 'sid'; sid.value = 'FC12345';
  const env = boot([sid], { singleFields: [], pooledFields: [], rules: [
    { type: 'single', fields: ['sid'], algorithm: 'none', idPattern: 'FC[0-9]{4}' },
  ] });
  const msg = msgOf(env, 'sid');
  check('legacy single rule keeps today\'s per-character wording',
    /FORMAT error at character 7/.test(msg.innerHTML) && /expected end of ID/.test(msg.innerHTML));
  const ok = makeEl('input'); ok.name = 'sid'; ok.value = 'FC1234';
  const env2 = boot([ok], { singleFields: [], pooledFields: [], rules: [
    { type: 'single', fields: ['sid'], algorithm: 'none', idPattern: 'FC[0-9]{4}' },
  ] });
  check('legacy regex-only success keeps "This project\'s IDs carry no check character"',
    /This project's IDs carry no check/.test(msgOf(env2, 'sid').innerHTML));
}

// ---- 5) the pooled field splits a jammed mixed run ------------------------
{
  const probe = boot([], { singleFields: [], pooledFields: [], rules: [] });
  const SK = mint(probe, 'SK1-0123'), DT = mint(probe, 'DT1-12345'), ST = mint(probe, 'ST3-77012');
  const FC = 'FC1-0589';

  function pooled(value) {
    const box = makeEl('input'); box.name = 'pool'; box.value = value;
    const env = boot([box], { singleFields: [], pooledFields: [], rules: [
      { type: 'pooled', fields: ['pool'], strip: '-', alternates: ALTS },
    ] });
    return { env, box, msg: msgOf(env, 'pool'), api: env.NS.lastPooled };
  }

  {
    const { box, msg, api } = pooled(FC + SK + DT + ST);
    const segs = api.parse(FC + SK + DT + ST);
    check('pooled: jammed mixed run splits into four members',
      segs.length === 4 && segs.every((s) => s.type === 'id' && s.valid));
    check('pooled: each member is claimed by the right alternate',
      [FC, SK, DT, ST].every((id, i) => api.claimedBy(id) === i));
    check('pooled: a clean mixed pool does not block the save', box.__qridInvalid === false);
    check('pooled: the summary does not claim the project is check-free',
      !/no check character in this project/.test(msg.innerHTML));
  }
  {
    // two GHITs back to back: 16 characters, and 16 is NOT a declared length,
    // so this must read as two members rather than one swallowed blob
    const { api } = pooled('FC1-0589FC3-0179');
    const segs = api.parse('FC1-0589FC3-0179');
    check('pooled: two adjacent regex-only members stay two members',
      segs.length === 2 && segs.every((s) => s.type === 'id' && s.valid));
  }
  {
    const bad = SK.slice(0, 5) + (SK[5] === '9' ? '8' : '9') + SK.slice(6);
    const { box, api } = pooled(FC + ' ' + bad + ' ' + DT);
    const segs = api.parse(FC + ' ' + bad + ' ' + DT);
    const ids = segs.filter((s) => s.type === 'id');
    check('pooled: a broken check character in one family is caught',
      ids.length === 3 && ids.filter((s) => !s.valid).length === 1
      && ids.find((s) => !s.valid).id === bad);
    check('pooled: a broken member blocks the save', box.__qridInvalid === true);
  }
  {
    const { api } = pooled(FC + 'ZZ' + DT);
    const segs = api.parse(FC + 'ZZ' + DT);
    check('pooled: junk between members stays junk',
      segs.length === 3 && segs[1].type === 'junk' && segs[1].text === 'ZZ');
  }
  {
    const { api } = pooled(FC);
    check('pooled: mode reports a mixed rule', api.mode.mixed === true && api.mode.alternates === 4);
  }
}

console.log(`alternates_dom_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
