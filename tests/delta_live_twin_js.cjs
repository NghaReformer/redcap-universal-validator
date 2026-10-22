/*
 * delta_live_twin_js.cjs - replay the typing of
 * docs/testbed/DELTA_LIVE_ACCEPTANCE.md through js/engine.js, booted with the
 * page configs the real module built (tests/delta_live_pages.json, which
 * tests/delta_live_twin_php.php keeps in step with the module), and check
 * each note, each save outcome and the field label the save dialog names,
 * word for word.
 *
 * RUN:  php tests/delta_live_twin_php.php && node tests/delta_live_twin_js.cjs
 */
'use strict';
const path = require('path');
const PAGES = require('./delta_live_pages.json');

let n = 0, fail = 0;
function check(label, ok, got) { n++; if (!ok) { fail++; console.error('FAIL: ' + label + (got !== undefined ? ' (got ' + JSON.stringify(got) + ')' : '')); } }

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), id: '', name: '', value: '', innerHTML: '', type: '', checked: false,
    style: { set cssText(v) { this._css = v; const m = /display:\s*([a-z]+)/.exec(v); this.display = m ? m[1] : ''; },
             get cssText() { return this._css || ''; } },
    _attrs: {}, children: [], parentNode: null, readOnly: false, disabled: false, _handlers: {},
    setAttribute(k, v) { this._attrs[k] = String(v); if (k === 'id') this.id = String(v); },
    getAttribute(k) { return (k in this._attrs) ? this._attrs[k] : (k === 'id' ? (this.id || null) : null); },
    removeAttribute(k) { delete this._attrs[k]; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev || {})); },
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
    insertBefore(node, ref) {
      node.parentNode = this; const i = ref ? this.children.indexOf(ref) : -1;
      if (i >= 0) this.children.splice(i, 0, node); else this.children.push(node); return node;
    },
    closest() { return null; }, focus() {},
    get nextSibling() { const i = this.parentNode ? this.parentNode.children.indexOf(this) : -1; return i >= 0 ? (this.parentNode.children[i + 1] || null) : null; },
    get firstChild() { return this.children[0] || null; },
  };
}
/* One page: an input per field the rules name, all blank; the server's config as the page has it. */
function boot(cfg, values) {
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  const names = new Set();
  for (const r of cfg.rules || []) {
    for (const f of r.fields || []) names.add(f);
    for (const b of [r].concat(r.branches || [])) if (b.deltaVsOp && b.deltaVsOp[0] === 'ref') names.add(b.deltaVsOp[1]);
  }
  Object.keys(values || {}).forEach((f) => names.add(f));
  const all = [], holders = {}, els = {};
  const body = makeEl('body');
  for (const f of names) {
    const el = makeEl('input'); el.name = f; el.value = (values && values[f]) || '';
    const h = makeEl('div'); h.appendChild(el); body.appendChild(h);
    all.push(el, h); holders[f] = h; els[f] = el;
    /* the label REDCap renders as #label-<field>, which the save dialog names */
    if (PAGES.labels && PAGES.labels[f]) { const lab = makeEl('td'); lab.id = 'label-' + f; lab.textContent = PAGES.labels[f]; all.push(lab); }
  }
  const doc = {
    body, readyState: 'complete', _handlers: {}, activeElement: null,
    createElement(t) { const e = makeEl(t); all.push(e); return e; },
    getElementById(id) { return all.find((e) => e.id === id) || null; },
    getElementsByName(nm) { return all.filter((e) => e.name === nm); },
    querySelector() { return null; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev)); },
  };
  const win = { _alerts: [], _confirms: [], confirmAnswer: false,
    alert(m) { this._alerts.push(m); }, confirm(m) { this._confirms.push(m); return this.confirmAnswer; },
    INSPIRE_VALIDATOR_CONFIG: JSON.parse(JSON.stringify(cfg)) };
  global.document = doc; global.window = win;
  require(enginePath);
  return { doc, win, holders, els };
}
function set(env, f, v) { const el = env.els[f]; el.value = v; el.fire('input'); el.fire('change'); el.fire('blur'); }
function note(env, f) {
  const kids = env.holders[f].children;
  for (let i = 1; i < kids.length; i++) if (kids[i].id && /-d$/.test(kids[i].id)) {
    const k = kids[i];
    return (k.style.display === 'none' || k.innerHTML === '') ? '' : k.innerHTML.replace(/&#10007; /g, '✗ ').replace(/&#9888; /g, '⚠ ')
      .replace(/<span[^>]*>/g, '').replace(/<\/span>/g, '').replace(/&quot;/g, '"').replace(/&amp;/g, '&');
  }
  return '';
}
function save(env) {
  const a = env.win._alerts.length, c = env.win._confirms.length;
  const ev = { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
  env.doc.fire('submit', ev);
  if (env.win._alerts.length > a) return 'held: ' + env.win._alerts[env.win._alerts.length - 1].replace(/\s+/g, ' ');
  if (env.win._confirms.length > c) return 'asked';
  return ev._prevented ? 'prevented' : 'saved';
}
const SNAP = ' (compared with saved event/instance values, read when this page was opened — reload if it has changed since. This check does not block saving; it is re-checked after the save.)';

// ---- Section A: one page, references off -----------------------------------
{
  const env = boot(PAGES.visit1_off, { ud_grp: '' });
  set(env, 'ud_w1', '70.0'); set(env, 'ud_w2', '70.2');
  check('A1 a change of 0.2: no note', note(env, 'ud_w2') === '', note(env, 'ud_w2'));
  set(env, 'ud_w2', '70.3');
  check('A2 note', note(env, 'ud_w2') === '✗ This value rose by 0.3 kg (0.4%) from 70.0 kg ([ud_w1]). Allowed: a change of at most 0.2 kg.', note(env, 'ud_w2'));
  const s = save(env);
  check('A2 save held, the dialog naming "Second weighing (kg)"', /^held/.test(s) && /Second weighing \(kg\)/.test(s), s);
  set(env, 'ud_w1', '70.5');
  check('A3 changing ud_w1 re-checks: 70.3 is 0.2 below 70.5', note(env, 'ud_w2') === '', note(env, 'ud_w2'));
  set(env, 'ud_sbp1', '120'); set(env, 'ud_sbp2', '145');
  check('A4 custom message', note(env, 'ud_sbp2') === '✗ The two readings differ by more than 20 mmHg. Take a third.', note(env, 'ud_sbp2'));
  check('A4 save asks', save(env) === 'asked');
  set(env, 'ud_sbp2', '125');
  set(env, 'ud_ht0', '150.0'); set(env, 'ud_ht', '149.9');
  check('A5 a fall of 0.1', note(env, 'ud_ht') === '✗ This value fell by 0.1 cm (0.1%) from 150.0 cm ([ud_ht0]). Allowed: no fall.', note(env, 'ud_ht'));
  check('A5 held', /^held/.test(save(env)));
  set(env, 'ud_ht', '152.0'); set(env, 'ud_htcalc', '152');
  check('A6 a rise passes', note(env, 'ud_ht') === '', note(env, 'ud_ht'));
  check('A6 the calc notes its rise', note(env, 'ud_htcalc') === '✗ This value rose by 2 (1.3%) from 150.0 ([ud_ht0]). Allowed: a change of at most 0.5.', note(env, 'ud_htcalc'));
  check('A6 the calc never blocks: saves', save(env) === 'saved', save(env));
  set(env, 'ud_pct0', '3'); set(env, 'ud_pct', '3.5');
  check('A7 inside max, past maxPct', note(env, 'ud_pct') === '✗ This value rose by 0.5 (16.7%) from 3 ([ud_pct0]). Allowed: a change of at most 2 and 15%.', note(env, 'ud_pct'));
  set(env, 'ud_pct', '2.9');
  check('A7 2.9 from 3: no note', note(env, 'ud_pct') === '');
  set(env, 'ud_pct0', '0'); set(env, 'ud_pct', '1');
  check('A8 an earlier 0: maxPct does not apply, 1 is within max', note(env, 'ud_pct') === '');
  set(env, 'ud_pct', '3');
  check('A8 3 from 0: max judges, no percent', note(env, 'ud_pct') === '✗ This value rose by 3 from 0 ([ud_pct0]). Allowed: a change of at most 2 and 15%.', note(env, 'ud_pct'));
  set(env, 'ud_pct', '');
  set(env, 'ud_c0', '70,0'); set(env, 'ud_c', '72,6');
  check('A9 comma', note(env, 'ud_c') === '✗ This value rose by 2,6 kg (3,7%) from 70,0 kg ([ud_c0]). Allowed: a change of at most 2,5 kg.', note(env, 'ud_c'));
  set(env, 'ud_c', '72,5');
  check('A9 72,5: exactly 2,5, no note', note(env, 'ud_c') === '');
  set(env, 'ud_pain0', '2'); set(env, 'ud_pain', '7');
  check('A10 slider', note(env, 'ud_pain') === '✗ This value rose by 5 (250%) from 2 ([ud_pain0]). Allowed: a change of at most 3.', note(env, 'ud_pain'));
  check('A10 asks', save(env) === 'asked');
  set(env, 'ud_pain', '4');
  set(env, 'ud_two', '130');
  check('A11 130: past 5% of 70.5 only, one line', note(env, 'ud_two') === '✗ This value rose by 59.5 (84.4%) from 70.5 ([ud_w1]). Allowed: a change of at most 5%.', note(env, 'ud_two'));
  check('A11 asks', save(env) === 'asked');
  set(env, 'ud_two', '150');
  check('A11 150: both lines', note(env, 'ud_two') === '✗ This value rose by 79.5 (112.8%) from 70.5 ([ud_w1]). Allowed: a change of at most 5%.<br>✗ This value rose by 30 (25%) from 120 ([ud_sbp1]). Allowed: a change of at most 15%.', note(env, 'ud_two'));
  check('A11 held', /^held/.test(save(env)));
  set(env, 'ud_two', '');
  set(env, 'ud_grp', '1'); set(env, 'ud_when', '80');
  check('A12 checked while Check', note(env, 'ud_when') === '✗ This value rose by 9.5 (13.5%) from 70.5 ([ud_w1]). Allowed: a change of at most 1.', note(env, 'ud_when'));
  check('A12 held', /^held/.test(save(env)));
  set(env, 'ud_grp', '2');
  check('A12 Skip: the note goes away', note(env, 'ud_when') === '');
  check('A12 saves', save(env) === 'saved', save(env));
}

/* The save dialog's "save anyway?" text, as the sheet's B2 and C3 meet it. */
function asked(env) { const c = env.win._confirms; return c.length ? c[c.length - 1].replace(/\s+/g, ' ') : ''; }
const SBP2 = PAGES.labels.ud_sbp2;

// ---- Section B: another form of this event (a snapshot), opened as A left it ---------
{
  const env = boot(PAGES.sheet_b1, PAGES.sheet_b1_values);
  check('B: the page opens with what A saved (ud_sbp2 145)', env.els.ud_sbp2.value === '145', env.els.ud_sbp2.value);
  set(env, 'ud_snap', '74');
  check('B1 note with the snapshot sentence', note(env, 'ud_snap') === '✗ This value rose by 2 kg (2.8%) from 72 kg ([xv_weight]). Allowed: a change of at most 1 kg.'
    + SNAP.replace('saved event/instance values', 'xv_weight'), note(env, 'ud_snap'));
  check('B2 the save asks "save anyway?" for ud_sbp2 alone', save(env) === 'asked'
    && asked(env) === 'Validation FAILED for: • ' + SBP2 + ' Save anyway?', asked(env));
  env.win.confirmAnswer = true;
  check('B2 answered yes, the record saves', save(env) === 'asked' && env.win._confirms.length === 2);
}

// ---- Section C: other events, visit 1 opened as A, B and C1 left it --------------------
{
  const env = boot(PAGES.sheet_c2, PAGES.sheet_c2_values);
  set(env, 'ud_wt', '78');
  check('C2 previous event', note(env, 'ud_wt') === '✗ This value rose by 8 kg (11.4%) from 70 kg (the previous event). Allowed: a change of at most 10%.' + SNAP, note(env, 'ud_wt'));
  set(env, 'ud_height', '169.4');
  check('C2 first event', note(env, 'ud_height') === '✗ This value fell by 0.6 cm (0.4%) from 170.0 cm (the first event). Allowed: a fall of at most 0.5 cm.' + SNAP, note(env, 'ud_height'));
  set(env, 'ud_bl', '85');
  check('C2 named event', note(env, 'ud_bl') === '✗ This value rose by 15 (21.4%) from 70 ([baseline_arm_1][xv_weight]). Allowed: a change of at most 20%.' + SNAP, note(env, 'ud_bl'));
  set(env, 'ud_twoev', '74');
  check('C2 two comparisons, one failing', note(env, 'ud_twoev') === '✗ This value rose by 4 (5.7%) from 70 (the previous event). Allowed: a change of at most 5%.' + SNAP, note(env, 'ud_twoev'));
  check('C3 the save asks "save anyway?" for ud_sbp2 alone: the event rules never block, though ud_wt says hard',
    save(env) === 'asked' && asked(env) === 'Validation FAILED for: • ' + SBP2 + ' Save anyway?', asked(env));
}
{
  const env = boot(PAGES.visit2_on);
  set(env, 'ud_wt', '80'); set(env, 'ud_height', '170.0');
  check('C4 no note under ud_wt (80 is 2.6% above 78)', note(env, 'ud_wt') === '', note(env, 'ud_wt'));
  check('C4 no note under ud_height (no fall from 170.0)', note(env, 'ud_height') === '', note(env, 'ud_height'));
  set(env, 'ud_twoev', '82');
  check('C4 two lines', note(env, 'ud_twoev') === '✗ This value rose by 8 (10.8%) from 74 (the previous event). Allowed: a change of at most 5%.' + SNAP
    + '<br>✗ This value rose by 12 (17.1%) from 70 (the first event). Allowed: a change of at most 15%.' + SNAP, note(env, 'ud_twoev'));
  check('C4 the record saves', save(env) === 'saved', save(env));
}
{
  const env = boot(PAGES.baseline_on);
  set(env, 'ud_wt', '70'); set(env, 'ud_height', '170.0'); set(env, 'ud_bl', '70'); set(env, 'ud_twoev', '70');
  for (const f of ['ud_wt', 'ud_height', 'ud_twoev']) check('C1 baseline: nothing earlier, no note under ' + f, note(env, f) === '', note(env, f));
  check('C1 baseline: ud_bl 70 against xv_weight 70, no note', note(env, 'ud_bl') === '', note(env, 'ud_bl'));
  check('C1 the record saves', save(env) === 'saved', save(env));
}

// ---- Section D: repeating instances ---------------------------------------------------
{
  const env = boot(PAGES.sheet_d1, PAGES.sheet_d1_values);
  set(env, 'ud_rep', '10.0');
  check('D1 the first instance: nothing earlier, no note', note(env, 'ud_rep') === '', note(env, 'ud_rep'));
  check('D1 the record saves', save(env) === 'saved', save(env));
}
{
  const env = boot(PAGES.specimen2_on);
  set(env, 'ud_rep', '7.5');
  check('D2 previous instance', note(env, 'ud_rep') === '✗ This value fell by 2.5 (25%) from 10.0 (the previous instance). Allowed: a fall of at most 20%.' + SNAP, note(env, 'ud_rep'));
  check('D2 the record saves', save(env) === 'saved', save(env));
  set(env, 'ud_rep', '8.0');
  check('D3 8.0: exactly 20%, no note', note(env, 'ud_rep') === '');
}

// ---- Section F: configuration errors are shown under each field, never a block --------
function cfgErr(env, f) {
  const kids = env.holders[f].children;
  for (let i = 1; i < kids.length; i++) if (kids[i].id && /-cfg$/.test(kids[i].id) && kids[i].style.display !== 'none') {
    return kids[i].innerHTML.replace(/&quot;/g, '"').replace(/&amp;/g, '&');
  }
  return '';
}
{
  const env = boot(PAGES.visit1_on);
  for (const f of ['ud_bad_email', 'ud_bad_self', 'ud_bad_vs', 'ud_bad_nofield', 'ud_bad_inst', 'ud_bad_nolimit']) {
    const want = (PAGES.visit1_on.rules.find((r) => (r.fields || []).indexOf(f) >= 0) || {}).configError || '';
    check('F ' + f + ': its error is shown under the field (got ' + cfgErr(env, f) + ')',
      want !== '' && cfgErr(env, f).indexOf(want) >= 0 && cfgErr(env, f).indexOf('@UVDELTA on "' + f + '": ') >= 0);
    set(env, f, '5');
  }
  check('F the bad fields never block', save(env) === 'saved', save(env));
}

console.log('delta_live_twin_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
