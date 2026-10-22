/*
 * justify_live_twin_js.cjs - replay the clicks and typing of
 * docs/testbed/JUSTIFY_LIVE_ACCEPTANCE.md through js/engine.js, booted with
 * the page configs the real module built (tests/justify_live_pages.json,
 * which tests/justify_live_twin_php.php keeps in step with the module), on a
 * page built from the fixture's field types the way REDCap renders them: text
 * boxes and notes, radio and yes/no buttons with their hidden mirror and
 * "reset" link, dropdowns, a checkbox, rows that branching logic hides, and
 * one survey page per section. The lookup and the duplicate check get the
 * answers the module gave for the same values (the "ajax" table).
 *
 * It checks each hold, each line of the alert and of the "Save anyway?"
 * dialog word for word, the note under the field that asks for the reason
 * (region "-j"), each release once the reason is given, what is no reason
 * (blank, spaces, a Missing Data Code, a blank choice, a hidden row), the
 * Enter key, the duplicate and the lock a reason does not release, the
 * reason on another survey page, and the configuration errors.
 *
 * RUN:  php tests/justify_live_twin_php.php && node tests/justify_live_twin_js.cjs
 */
'use strict';
const path = require('path');
const PAGES = require('./justify_live_pages.json');

let n = 0, fail = 0;
function check(label, ok, got) { n++; if (!ok) { fail++; console.error('FAIL: ' + label + (got !== undefined ? ' (got ' + JSON.stringify(got) + ')' : '')); } }

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), nodeType: 1, id: '', name: '', value: '', innerHTML: '', textContent: '', type: '', checked: false, className: '',
    style: { set cssText(v) { this._css = v; const m = /display:\s*([a-z]+)/.exec(v); this.display = m ? m[1] : ''; },
             get cssText() { return this._css || ''; } },
    _attrs: {}, children: [], parentNode: null, readOnly: false, disabled: false, _handlers: {},
    setAttribute(k, v) { this._attrs[k] = String(v); if (k === 'id') this.id = String(v); },
    getAttribute(k) { return (k in this._attrs) ? this._attrs[k] : (k === 'id' ? (this.id || null) : null); },
    removeAttribute(k) { delete this._attrs[k]; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    dispatchEvent(ev) { this.fire(ev.type, ev); return true; },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn.call(this, ev || {})); },
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
    insertBefore(node, ref) {
      node.parentNode = this; const i = ref ? this.children.indexOf(ref) : -1;
      if (i >= 0) this.children.splice(i, 0, node); else this.children.push(node); return node;
    },
    getElementsByTagName(t) {
      const out = [];
      const walk = (e) => { for (const c of e.children) { if (c.tagName === t.toUpperCase()) out.push(c); walk(c); } };
      walk(this); return out;
    },
    closest() { return null; }, focus() { this._focused = true; },
    get nextSibling() { const i = this.parentNode ? this.parentNode.children.indexOf(this) : -1; return i >= 0 ? (this.parentNode.children[i + 1] || null) : null; },
    get firstChild() { return this.children[0] || null; },
  };
}
global.Event = function (type) { this.type = type; };

/* Fake timers: the note follows a tick after each change (setTimeout 0), a
   lookup's wait for its answer is a long timer. flush() runs the short ones
   until none is left. */
let timers = [], timerSeq = 0;
global.setTimeout = (fn, ms) => { const id = ++timerSeq; timers.push({ id, fn, ms: ms || 0 }); return id; };
global.clearTimeout = (id) => { timers = timers.filter((t) => t.id !== id); };
function flush() {
  for (let round = 0; round < 30; round++) {
    const due = timers.filter((t) => t.ms < 5000);
    if (!due.length) return;
    timers = timers.filter((t) => t.ms >= 5000);
    due.forEach((t) => t.fn());
  }
}

/* REDCap's codes of a choices list: "1, Arm A | 2, Arm B"; a yes/no field is 1 and 0. */
function codes(t) {
  if (t.type === 'yesno') return ['1', '0'];
  return String(t.choices || '').split('|').map((c) => c.split(',')[0].trim()).filter((c) => c !== '');
}
/*
 * One page: each field of PAGES.types (or of the sections listed in
 * opts.sections, for a survey page) as REDCap renders it, holding the saved
 * values; the server's config as the page has it. A row whose branching
 * logic is false on the saved values starts hidden, as REDCap draws it.
 */
function boot(cfg, values, opts) {
  opts = opts || {};
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  timers = [];
  const all = [], holders = {}, ctl = {};
  const body = makeEl('body');
  const add = (holder, el) => { holder.appendChild(el); all.push(el); return el; };
  const types = Object.assign({}, PAGES.types);
  for (const f of Object.keys(types)) {
    if (opts.sections && opts.sections.indexOf(PAGES.sections[f]) < 0) continue;
    if (opts.without && opts.without.indexOf(f) >= 0) continue;
    /* the SQL reason field exists only once an administrator adds it (step J4) */
    if (f === 'uj_sql_why' && !opts.sql) continue;
    const t = types[f], v = (values && values[f]) || '';
    const row = makeEl('tr'); row.id = f + '-tr'; body.appendChild(row); all.push(row);
    holders[f] = row;
    const lab = makeEl('td'); lab.id = 'label-' + f; lab.textContent = PAGES.labels[f]; add(row, lab);
    const c = { type: t.type };
    if (t.type === 'radio' || t.type === 'yesno') {
      c.el = add(row, makeEl('input')); c.el.name = f; c.el.type = 'text'; c.el.value = v;
      c.buttons = codes(t).map((code) => { const b = add(row, makeEl('input')); b.name = f + '___radio'; b.type = 'radio'; b.value = code; b.checked = code === v; return b; });
      c.reset = add(row, makeEl('a')); c.reset.setAttribute('onclick', "radioResetVal('" + f + "','form');return false;"); c.reset.style.display = 'inline';
    } else if (t.type === 'dropdown' || t.type === 'sql') {
      c.el = add(row, makeEl('select')); c.el.name = f;
      c.el.options = [''].concat(codes(t)).map((code) => { const o = makeEl('option'); o.value = code; o.text = code; return o; });
      c.el.value = v;
    } else if (t.type === 'checkbox') {
      c.boxes = codes(t).map((code) => {
        const h = add(row, makeEl('input')); h.name = '__chk__' + f + '_RC_' + code; h.type = 'hidden'; h.value = '';
        const b = add(row, makeEl('input')); b.name = '__chkn__' + f; b.id = 'id-__chk__' + f + '_RC_' + code; b.type = 'checkbox'; b.value = code;
        return b;
      });
    } else {
      c.el = add(row, makeEl(t.type === 'notes' ? 'textarea' : 'input')); c.el.name = f; c.el.type = 'text'; c.el.value = v;
      if (/^date/.test(t.validation)) c.el.className = 'x hasDatepicker';
    }
    ctl[f] = c;
  }
  /* branching logic as REDCap's doBranching() applies it: an "[x]='1'" row is
     hidden while x is not 1 (read from the page, or from the saved values
     when x is not on this page). It fires no DOM event. */
  function drawBranching() {
    for (const f of Object.keys(holders)) {
      const m = /^\[([a-z0-9_]+)\]='([^']*)'$/.exec(types[f].branching || '');
      if (!m) continue;
      const now = ctl[m[1]] && ctl[m[1]].el ? ctl[m[1]].el.value : ((values && values[m[1]]) || '');
      holders[f].style.display = String(now) === m[2] ? '' : 'none';
    }
  }
  drawBranching();
  const doc = {
    body, readyState: 'complete', _handlers: {}, activeElement: null,
    createElement(t) { const e = makeEl(t); all.push(e); return e; },
    getElementById(id) { return all.find((e) => e.id === id) || null; },
    getElementsByName(nm) { return all.filter((e) => e.name === nm); },
    querySelector() { return null; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev)); },
  };
  const submits = [], asked = [];
  const win = { _alerts: [], _confirms: [], confirmAnswer: false,
    alert(m) { this._alerts.push(m); }, confirm(m) { this._confirms.push(m); return this.confirmAnswer; },
    /* REDCap's own, which the Enter key in a text field calls */
    dataEntrySubmit(ob) { submits.push(ob); return 'redcap-save'; },
    doBranching() { drawBranching(); },
    INSPIRE_VALIDATOR_CONFIG: JSON.parse(JSON.stringify(cfg)) };
  /* the module's AJAX object: the answer the module gave for these values (justify_live_twin_php.php) */
  const rec = opts.record || 'XE-1';
  win.EM = { T: { UV: { ajax(action, payload) {
    const key = rec + ' ' + action + ' ' + payload.field + ' ' + (payload.values || {})[payload.field];
    asked.push(key);
    const resp = PAGES.ajax[key];
    return { then(res) { if (resp !== undefined) res(JSON.parse(JSON.stringify(resp))); } };
  } } } };
  global.document = doc; global.window = win;
  const intervals = [];
  global.setInterval = (fn) => { intervals.push(fn); return { unref() {} }; };
  global.clearInterval = () => {};
  require(enginePath);
  /* REDCap's own start-up (jQuery's ready handlers, @READONLY among them)
     runs after the engine bound the fields and before its first tick. */
  if (opts.ready) opts.ready(ctl);
  flush();
  return { doc, win, holders, ctl, submits, asked, poll() { intervals.forEach((fn) => fn()); flush(); } };
}
function region(env, f, suffix) {
  for (const k of env.holders[f].children) if (k.id && new RegExp('-' + suffix + '$').test(k.id)) return k;
  return null;
}
function plain(html) {
  return String(html).replace(/&#10007; /g, '✗ ').replace(/&#9888; /g, '⚠ ').replace(/&#128274; /g, '🔒 ').replace(/<br>/g, ' | ')
    .replace(/<[^>]*>/g, '').replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>')
    .replace(/&hellip;/g, '…').replace(/&#8230;/g, '…').replace(/&mdash;/g, '—').replace(/&#10003;/g, '✓').replace(/&#10007;/g, '✗').replace(/&nbsp;/g, ' ');
}
/* What a region under the field shows ('' when hidden or empty). */
function shown(env, f, suffix) {
  const m = region(env, f, suffix);
  return m && m.style.display !== 'none' ? plain(m.innerHTML) : '';
}
/* The note asking for the reason (region "-j"). */
function ask(env, f) { return shown(env, f, 'j'); }
/* Every note shown under the field, region by region. */
function notes(env, f) {
  const out = {};
  for (const k of env.holders[f].children) {
    if (!k.id || !/^uvalidate-msg-/.test(k.id)) continue;
    const s = k.style.display !== 'none' ? plain(k.innerHTML) : '';
    if (s) out[k.id.replace('uvalidate-msg-' + f, '') || '(id)'] = s;
  }
  return out;
}
function cfgErr(env, f) { return shown(env, f, 'cfg'); }
function save(env) {
  const a = env.win._alerts.length, c = env.win._confirms.length;
  const ev = { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
  env.doc.fire('submit', ev);
  flush();
  if (env.win._alerts.length > a) return 'held: ' + env.win._alerts[env.win._alerts.length - 1];
  if (env.win._confirms.length > c) return 'asked: ' + env.win._confirms[env.win._confirms.length - 1];
  return ev._prevented ? 'prevented' : 'saved';
}
/* Enter in a text field, as REDCap's keydown handler does it: blur, then dataEntrySubmit. */
function pressEnter(env, f) {
  const a = env.win._alerts.length, s = env.submits.length;
  env.ctl[f].el.fire('blur');
  env.win.dataEntrySubmit('submit-btn-saverecord');
  flush();
  if (env.win._alerts.length > a) return 'held: ' + env.win._alerts[env.win._alerts.length - 1];
  return env.submits.length > s ? 'saved' : 'nothing';
}
/* What a person does: type and leave, pick a radio button or a dropdown option. */
function type(env, f, v) { const el = env.ctl[f].el; el.value = v; el.fire('input'); el.fire('change'); el.fire('blur'); flush(); }
function pick(env, f, code) {
  const c = env.ctl[f];
  if (c.buttons) {
    c.buttons.forEach((x) => { x.checked = x.value === code; });
    c.el.value = code; c.el.fire('change');
  } else { c.el.value = code; c.el.fire('change'); }
  flush();
}
/* REDCap's branching: show or hide a row (doBranching fires no event). */
/* A radio or yes/no answer that branching logic reads: REDCap sets it, then calls doBranching(). */
function answer(env, f, code) { pick(env, f, code); env.win.doBranching(); flush(); }
/* REDCap's @READONLY on a text box. */
const READONLY = (ctl) => { if (ctl.uj_ro) ctl.uj_ro.el.readOnly = true; };

const HELD = 'Cannot save yet — please fix the flagged field(s):';
const label = (f) => PAGES.labels[f];
const offer = (f, why) => '\n• ' + label(f) + ' (or give a reason in “' + label(why) + '”)';
const NOTE = (why) => 'Fix this value, or say why it is right in “' + label(why) + '”.';
const V = PAGES.values;
/* the notes under a field, as a sorted list (the regions' order on the page does not matter) */
const sorted = (o) => JSON.stringify(Object.keys(o).sort().map((k) => [k, o[k]]));

const W = (f) => '✗ This value is above the plausible range (allowed 30 to 250 kg).';

// ---- Section A: the configuration errors, under each field --------------------------------------
{
  const env = boot(PAGES.form, V.A1, { ready: READONLY });
  for (const f of Object.keys(PAGES.errors)) {
    check('A1 ' + f + ': its error is shown under the field, word for word', cfgErr(env, f) === '⚠ Configuration error: ' + PAGES.errors[f], cfgErr(env, f));
  }
  const clean = Object.keys(env.holders).filter((f) => !PAGES.errors[f] && cfgErr(env, f) !== '');
  check('A1 no other field shows an error', clean.length === 0, clean);
  check('A1 the blank required field shows its own note and the note asking for its reason',
    shown(env, 'uj_req', 'r') === '✗ This field must not be left blank.' && ask(env, 'uj_req') === NOTE('uj_req_why'), notes(env, 'uj_req'));
  const asking = Object.keys(env.holders).filter((f) => f !== 'uj_req' && region(env, f, 'j') && ask(env, f) !== '');
  check('A1 no other field asks for a reason', asking.length === 0, asking);

  // ---- A3: a refused @UVJUSTIFY releases nothing, and the field's own rule still runs ----
  type(env, 'uj_bad_bare', '11');
  check('A3 11 in "Bare tag": its range note under the error', shown(env, 'uj_bad_bare', 'n') === '✗ This value is above the plausible range (allowed 0 to 10).', notes(env, 'uj_bad_bare'));
  let a3 = save(env);
  check('A3 Save is held for it, the line offering no reason (the required field holds too)', a3 === 'held: ' + HELD + offer('uj_req', 'uj_req_why') + '\n• ' + label('uj_bad_bare'), a3);
  type(env, 'uj_bad_bare', '');
  type(env, 'uj_rr', '70');
  check('A3 70 in the respiratory rate: its range note, no note asking for a reason', shown(env, 'uj_rr', 'n') === '✗ This value is above the plausible range (allowed 5 to 60).'
    && ask(env, 'uj_rr') === '', notes(env, 'uj_rr'));
  a3 = save(env);
  check('A3 Save is held for it, the line offering no reason', a3 === 'held: ' + HELD + offer('uj_req', 'uj_req_why') + '\n• ' + label('uj_rr'), a3);
  type(env, 'uj_rr', '');

  // ---- B1: Save with nothing typed ----
  check('B1 Save is held for the required field alone, naming its reason field', save(env) === 'held: ' + HELD + offer('uj_req', 'uj_req_why'), env.win._alerts);
  pick(env, 'uj_req_why', '1');
  check('B1b "Not done": the note asking for the reason goes, the field\'s own note stays', ask(env, 'uj_req') === ''
    && shown(env, 'uj_req', 'r') === '✗ This field must not be left blank.', notes(env, 'uj_req'));
  check('B1b the record saves', save(env) === 'saved');
}
/*
 * One mode of section B: open the form as the save before left it, break
 * the rule, check the field's own note, the note asking for the reason, the
 * held save and its line; give the reason and check that it saves.
 */
function modeStep(stepNo, f, why, breakIt, ownNote, giveReason, opts) {
  opts = opts || {};
  const env = boot(PAGES.form, V[stepNo], { ready: READONLY });
  check(stepNo + ' the form opens with every reason saved before: nothing holds', save(env) === 'saved');
  breakIt(env);
  const own = notes(env, f);
  delete own['-j'];
  check(stepNo + ' ' + f + ': the rule\'s own note', JSON.stringify(own) === JSON.stringify(ownNote), own);
  check(stepNo + ' ' + f + ': the note asking for the reason', ask(env, f) === (opts.note || NOTE(why)), ask(env, f));
  const s = save(env);
  check(stepNo + ' Save is held, the line naming the reason field', s === 'held: ' + HELD + offer(f, why), s);
  giveReason(env);
  check(stepNo + 'b the reason given: the note asking for it goes', ask(env, f) === '', ask(env, f));
  const after = notes(env, f);
  check(stepNo + 'b ... the rule\'s own note stays', JSON.stringify(after) === JSON.stringify(ownNote), after);
  check(stepNo + 'b ... and the record saves', save(env) === 'saved');
  return env;
}
modeStep('B2', 'uj_wt', 'uj_wt_why', (e) => type(e, 'uj_wt', '300.0'), { '-n': W() }, (e) => type(e, 'uj_wt_why', 'Scale checked twice'));
modeStep('B3', 'uj_id', 'uj_id_why', (e) => type(e, 'uj_id', '79927398710'),
  { '(id)': '✗ This ID\'s check character does not match — probably a typo or mis-scan. Please re-scan or re-type it.' },
  (e) => type(e, 'uj_id_why', 'Number printed on the tube'));
modeStep('B4', 'uj_pool', 'uj_pool_why', (e) => type(e, 'uj_pool', '79927398713 79927398710'),
  { '(id)': '2 IDs read — 1 check-character mismatch | ✓ 79927398713✗ 79927398710' },
  (e) => type(e, 'uj_pool_why', 'Second tube relabelled\nat the lab'));
modeStep('B5', 'uj_dia', 'uj_dia_why', (e) => { type(e, 'uj_sys', '80'); type(e, 'uj_dia', '90'); },
  { '-c': '✗ The diastolic pressure must be below the systolic.' }, (e) => pick(e, 'uj_dia_why', '1'));
modeStep('B6', 'uj_dose', 'uj_dose_why', (e) => { pick(e, 'uj_arm', '1'); pick(e, 'uj_dose', '1'); pick(e, 'uj_arm', '2'); },
  { '-ch': '✗ The selected choice is no longer available while: [uj_arm]=\'2\' — pick one of the choices shown (the current answer is kept until you do).' },
  (e) => type(e, 'uj_dose_why', 'Dose by weight'));
modeStep('B7', 'uj_visit', 'uj_visit_why', (e) => { type(e, 'uj_anchor', '2026-10-01'); type(e, 'uj_visit', '2026-10-20'); },
  { '-w': '✗ This date must be between 2026-10-01 and 2026-10-15. (0 to 14 days from [uj_anchor])' },
  (e) => type(e, 'uj_visit_why', 'Clinic closed for a week'), { note: 'Say why this visit date is right.' });
modeStep('B8', 'uj_wt2', 'uj_wt2_why', (e) => type(e, 'uj_wt2', '302.5'),
  { '-d': '✗ This value rose by 2.5 kg (0.8%) from 300.0 kg ([uj_wt]). Allowed: a change of at most 1 kg.' }, (e) => pick(e, 'uj_wt2_why', '1'));
{
  const env = modeStep('B9', 'uj_mother', 'uj_mother_why', (e) => type(e, 'uj_mother', 'XE-9'),
    { '-x': '✗ This value is not saved where it should be. Check it, or record it there first.' }, (e) => type(e, 'uj_mother_why', 'Mother enrolled at another site'));
  check('B9 the page asked the module about XE-9', env.asked.indexOf('XE-1 exists-check uj_mother XE-9') >= 0, env.asked);
}
{
  // B9, the other way: a saved record is found, and nothing asks for a reason
  const env = boot(PAGES.form, V.B9, { ready: READONLY });
  type(env, 'uj_mother', 'XE-2');
  check('B9 XE-2 is found: no note under the field, none asking for a reason', JSON.stringify(notes(env, 'uj_mother')).indexOf('✗') < 0 && ask(env, 'uj_mother') === '', notes(env, 'uj_mother'));
}


// ---- Section C: what is no reason ---------------------------------------------------------------
{
  const env = boot(PAGES.form, V.C1, { ready: READONLY });
  check('C1 the form opens with every reason of section B saved: no note asks for one', ['uj_req', 'uj_wt', 'uj_id', 'uj_pool', 'uj_dia', 'uj_dose', 'uj_visit', 'uj_wt2', 'uj_mother']
    .every((f) => ask(env, f) === ''), ['uj_wt', 'uj_dia'].map((f) => ask(env, f)));
  const noReason = (stepNo, what, f, why, doIt, putBack) => {
    doIt(env);
    check(stepNo + ' ' + what + ': the note asking for the reason is back under "' + label(f) + '"', ask(env, f) === NOTE(why), ask(env, f));
    const s = save(env);
    check(stepNo + ' ... and Save is held, the line naming the reason field', s === 'held: ' + HELD + offer(f, why), s);
    putBack(env);
    check(stepNo + ' the reason put back: the note goes', ask(env, f) === '', ask(env, f));
  };
  const wtBack = (e) => type(e, 'uj_wt_why', 'Scale checked twice');
  noReason('C1', 'the reason cleared', 'uj_wt', 'uj_wt_why', (e) => type(e, 'uj_wt_why', ''), wtBack);
  noReason('C2', 'three spaces as the reason', 'uj_wt', 'uj_wt_why', (e) => type(e, 'uj_wt_why', '   '), wtBack);
  noReason('C3', 'the Missing Data Code UNK as the reason', 'uj_wt', 'uj_wt_why', (e) => type(e, 'uj_wt_why', 'UNK'), wtBack);
  check('C3 the page knows UNK as a Missing Data Code', JSON.stringify(PAGES.form.missingCodes) === '["UNK"]', PAGES.form.missingCodes);
  noReason('C4', 'the blank option of the dropdown reason', 'uj_dia', 'uj_dia_why', (e) => pick(e, 'uj_dia_why', ''), (e) => pick(e, 'uj_dia_why', '1'));
  check('C4b Save with every reason back: the record saves', save(env) === 'saved');
}
{
  // C5: the reason sits in a row branching logic hides until "Explain the heart rate?" is Yes
  const env = boot(PAGES.form, V.C5, { ready: READONLY });
  check('C5 the row "' + label('uj_hr_why') + '" starts hidden', env.holders.uj_hr_why.style.display === 'none');
  type(env, 'uj_hr', '250');
  check('C5 250: the range note under the heart rate', shown(env, 'uj_hr', 'n') === '✗ This value is above the plausible range (allowed 30 to 220).', notes(env, 'uj_hr'));
  check('C5 ... and the note asking for the reason, which names the hidden field', ask(env, 'uj_hr') === NOTE('uj_hr_why'), ask(env, 'uj_hr'));
  let s = save(env);
  check('C5 Save is held, the line naming the reason field', s === 'held: ' + HELD + offer('uj_hr', 'uj_hr_why'), s);
  answer(env, 'uj_hr_show', '1');
  check('C5 Yes: the reason row shows', env.holders.uj_hr_why.style.display === '');
  check('C5 ... the note still asks (the box is empty)', ask(env, 'uj_hr') === NOTE('uj_hr_why'), ask(env, 'uj_hr'));
  type(env, 'uj_hr_why', 'Athlete, resting rate checked');
  check('C5 the reason typed: the note goes', ask(env, 'uj_hr') === '', ask(env, 'uj_hr'));
  check('C5b the record saves', save(env) === 'saved');
}
{
  // C6: "No" hides the row again; the reason left in the hidden box gives no reason on this page
  const env = boot(PAGES.form, V.C6, { ready: READONLY });
  check('C6 reopened with Yes saved: the row shows, no note asks', env.holders.uj_hr_why.style.display === '' && ask(env, 'uj_hr') === '', ask(env, 'uj_hr'));
  answer(env, 'uj_hr_show', '0');
  check('C6 No: the reason row is hidden', env.holders.uj_hr_why.style.display === 'none');
  check('C6 ... the note asking for the reason is back, with no other change on the page', ask(env, 'uj_hr') === NOTE('uj_hr_why'), ask(env, 'uj_hr'));
  const s = save(env);
  check('C6 Save is held, the line naming the hidden reason field', s === 'held: ' + HELD + offer('uj_hr', 'uj_hr_why'), s);
  answer(env, 'uj_hr_show', '1');
  check('C6 Yes again: the reason shows with its text, the note goes', env.holders.uj_hr_why.style.display === '' && ask(env, 'uj_hr') === '', ask(env, 'uj_hr'));
  check('C6b the record saves', save(env) === 'saved');
}
{
  // C6 again, when REDCap asks to erase the hidden value and the tester clicks OK: the box is blanked too
  const env = boot(PAGES.form, V.C6, { ready: READONLY });
  answer(env, 'uj_hr_show', '0');
  env.ctl.uj_hr_why.el.value = '';
  env.poll();
  check('C6 (erase OK) the reason hidden and erased: the note asks', ask(env, 'uj_hr') === NOTE('uj_hr_why'), ask(env, 'uj_hr'));
  const s = save(env);
  check('C6 (erase OK) Save is held, the line naming the reason field', s === 'held: ' + HELD + offer('uj_hr', 'uj_hr_why'), s);
  answer(env, 'uj_hr_show', '1');
  check('C6 (erase OK) Yes again: the empty box shows, the note still asks', env.holders.uj_hr_why.style.display === '' && ask(env, 'uj_hr') === NOTE('uj_hr_why'), ask(env, 'uj_hr'));
  type(env, 'uj_hr_why', 'Athlete, resting rate checked');
  check('C6b (erase OK) the reason typed again: the note goes, the record saves', ask(env, 'uj_hr') === '' && save(env) === 'saved', ask(env, 'uj_hr'));
}
{
  // C7: the device weight, imported into a read-only field
  const env = boot(PAGES.form, V.C7, { ready: READONLY });
  check('C7 the device weight is read-only on the form', env.ctl.uj_ro.el.readOnly === true);
  check('C7 300.0: the range note under it', shown(env, 'uj_ro', 'n') === W(), notes(env, 'uj_ro'));
  check('C7 ... and no note asks for a reason, as the field cannot be changed here and never holds the save', ask(env, 'uj_ro') === '', ask(env, 'uj_ro'));
  check('C7 Save goes through', save(env) === 'saved');
  type(env, 'uj_ro_why', 'Device recalibrated');
  check('C7b the reason typed: still no note asking, the range note stays', ask(env, 'uj_ro') === '' && shown(env, 'uj_ro', 'n') === W(), notes(env, 'uj_ro'));
  check('C7b the record saves', save(env) === 'saved');
}

// ---- Section D: "Save anyway?", and a rule that never blocks --------------------------------------
{
  const env = boot(PAGES.form, V.D1, { ready: READONLY });
  type(env, 'uj_temp', '43.0');
  check('D1 43.0: the range note', shown(env, 'uj_temp', 'n') === '✗ This value is above the plausible range (allowed 34 to 42 C).', notes(env, 'uj_temp'));
  check('D1 ... and the note asking for the reason', ask(env, 'uj_temp') === NOTE('uj_temp_why'), ask(env, 'uj_temp'));
  env.win.confirmAnswer = false;
  const s = save(env);
  check('D1 Save asks "Save anyway?", word for word, naming the reason field', s === 'asked: Validation FAILED for:' + offer('uj_temp', 'uj_temp_why') + '\n\nSave anyway?', s);
  check('D1 Cancel keeps the form open: nothing saved', env.win._alerts.length === 0 && env.win._confirms.length === 1);
  type(env, 'uj_temp_why', 'Fever confirmed with a second thermometer');
  check('D2 the reason typed: the note asking for it goes', ask(env, 'uj_temp') === '', ask(env, 'uj_temp'));
  check('D2 Save goes through with no question', save(env) === 'saved' && env.win._confirms.length === 1);
}
{
  const env = boot(PAGES.form, V.D3, { ready: READONLY });
  type(env, 'uj_off', '85');
  check('D3 85: the rule\'s own warning', JSON.stringify(notes(env, 'uj_off')) === JSON.stringify({ '-c': '✗ Below 90%: check the probe.' }), notes(env, 'uj_off'));
  check('D3 ... and no note asks for a reason (the rule never blocks)', ask(env, 'uj_off') === '', ask(env, 'uj_off'));
  check('D3 Save goes through with no alert and no question', save(env) === 'saved' && env.win._alerts.length === 0 && env.win._confirms.length === 0);
  type(env, 'uj_off_why', 'Known lung disease');
  check('D4 the reason typed: the warning stays, still no note asking', shown(env, 'uj_off', 'c') === '✗ Below 90%: check the probe.' && ask(env, 'uj_off') === '', notes(env, 'uj_off'));
  check('D4 the record saves', save(env) === 'saved');
}

// ---- Section E: the Enter key --------------------------------------------------------------------
{
  const env = boot(PAGES.form, V.E1, { ready: READONLY });
  type(env, 'uj_wt_why', '');
  const e1 = pressEnter(env, 'uj_wt');
  check('E1 Enter in the weight box with the reason cleared: held, the line naming the reason field', e1 === 'held: ' + HELD + offer('uj_wt', 'uj_wt_why'), e1);
  type(env, 'uj_wt_why', 'Scale checked twice');
  const e2 = pressEnter(env, 'uj_wt_why');
  check('E2 Enter in the reason box once the reason is back: the record saves', e2 === 'saved', e2);
}

// ---- Section F: a reason does not release @UVUNIQUE or @UVWRITEONCE --------------------------------
const FORMAT_OK = '✓ ID format OK. (This project\'s IDs carry no check character, so typos that keep the format cannot be detected.)';
{
  const env = boot(PAGES.form, V.F1, { ready: READONLY });
  type(env, 'uj_code', 'UJ-1');
  check('F1 UJ-1: the format note and the duplicate check\'s answer', sorted(notes(env, 'uj_code')) === sorted({
    '(id)': '✗ FORMAT error — the ID is incomplete. Still remaining: 2 x digit.', '-q': '✓ Not used before.', '-j': NOTE('uj_code_why') }), notes(env, 'uj_code'));
  check('F1 the page asked the module about UJ-1', env.asked.indexOf('XE-1 unique-check uj_code UJ-1') >= 0, env.asked);
  let s = save(env);
  check('F1 Save is held, the line naming the reason field', s === 'held: ' + HELD + offer('uj_code', 'uj_code_why'), s);
  type(env, 'uj_code_why', 'Code from the paper form');
  check('F1b the reason typed: the note asking goes, the format note stays', ask(env, 'uj_code') === ''
    && notes(env, 'uj_code')['(id)'] === '✗ FORMAT error — the ID is incomplete. Still remaining: 2 x digit.', notes(env, 'uj_code'));
  check('F1b the record saves', save(env) === 'saved');
}
{
  const env = boot(PAGES.form, V.F2, { ready: READONLY });
  type(env, 'uj_code', 'UJ-001');
  check('F2 UJ-001: format OK and not used before, no note asking for a reason', sorted(notes(env, 'uj_code')) === sorted({
    '(id)': FORMAT_OK, '-q': '✓ Not used before.' }), notes(env, 'uj_code'));
  check('F2 the record saves', save(env) === 'saved');
}
{
  // F3: XE-2, where UJ-001 is a duplicate
  const env = boot(PAGES.xe2, V.F3, { ready: READONLY, record: 'XE-2' });
  pick(env, 'uj_req_why', '1');
  type(env, 'uj_code', 'UJ-001');
  check('F3 XE-2, UJ-001: the duplicate note names XE-1', shown(env, 'uj_code', 'q') === '✗ This value is already recorded (record XE-1).', notes(env, 'uj_code'));
  check('F3 ... and no note asks for a reason (a reason does not release a duplicate)', ask(env, 'uj_code') === '', notes(env, 'uj_code'));
  let s = save(env);
  check('F3 Save is held, the line naming no reason field', s === 'held: ' + HELD + '\n• ' + label('uj_code'), s);
  type(env, 'uj_code_why', 'Code from the paper form');
  s = save(env);
  check('F3b the reason typed: still held, the same line', s === 'held: ' + HELD + '\n• ' + label('uj_code'), s);
  check('F3b ... and still no note asking for a reason', ask(env, 'uj_code') === '', notes(env, 'uj_code'));
  type(env, 'uj_code', ''); type(env, 'uj_code_why', '');
  check('F3c the code and its reason cleared: XE-2 saves', save(env) === 'saved');
}
{
  const env = boot(PAGES.form, V.F4, { ready: READONLY });
  type(env, 'uj_lock', '300.0');
  check('F4 300.0: the range note, and the note asking for the reason', shown(env, 'uj_lock', 'n') === W() && ask(env, 'uj_lock') === NOTE('uj_lock_why'), notes(env, 'uj_lock'));
  const s = save(env);
  check('F4 Save is held, the line naming the reason field', s === 'held: ' + HELD + offer('uj_lock', 'uj_lock_why'), s);
  type(env, 'uj_lock_why', 'Weighed in a cast');
  check('F4b the reason typed: the note asking goes', ask(env, 'uj_lock') === '', ask(env, 'uj_lock'));
  check('F4b the record saves', save(env) === 'saved');
}
const LOCK = '🔒 This value was saved once and is locked. It cannot be changed here.';
{
  const env = boot(PAGES.locked, V.F5, { ready: READONLY });
  check('F5 reopened: the enrolment weight is read-only', env.ctl.uj_lock.el.readOnly === true);
  check('F5 ... with the lock note and the range note, and no note asking for a reason', shown(env, 'uj_lock', 'o') === LOCK
    && shown(env, 'uj_lock', 'n') === W() && ask(env, 'uj_lock') === '', notes(env, 'uj_lock'));
  type(env, 'uj_lock_why', '');
  check('F5b the reason cleared: still no note asking (a locked field never holds the save)', ask(env, 'uj_lock') === '', notes(env, 'uj_lock'));
  check('F5b the record saves', save(env) === 'saved');
}
{
  const env = boot(PAGES.locked310, V.F6, { ready: READONLY });
  check('F6 after the import: read-only, the lock note, the range note, no note asking', env.ctl.uj_lock.el.readOnly === true
    && shown(env, 'uj_lock', 'o') === LOCK && shown(env, 'uj_lock', 'n') === W() && ask(env, 'uj_lock') === '', notes(env, 'uj_lock'));
  check('F6 the record saves', save(env) === 'saved');
}

// ---- Section G: a survey of five pages, one section each --------------------------------------------
/* a survey shows respondents one general line for a refused tag, never the detail */
const SURVEY = '⚠ Automatic checking of this field is unavailable (a configuration issue has been logged for the study team).';
const SECTION = (letter) => Array.from(new Set(Object.values(PAGES.sections))).filter((s) => s.indexOf(letter + '.') === 0);
{
  const env = boot(PAGES.survey, {}, { ready: READONLY, record: 'XE-4', sections: SECTION('A') });
  check('G1 page 1: the respiratory rate shows the survey\'s general wording', cfgErr(env, 'uj_rr') === SURVEY, cfgErr(env, 'uj_rr'));
  check('G1 ... and no field of the other pages is on it (J6)', !env.holders.uj_code && !env.holders.uj_far && !env.holders.uj_bad_bare);
  let s = save(env);
  check('G1 Next with nothing typed: held for the required field, naming its reason field', s === 'held: ' + HELD + offer('uj_req', 'uj_req_why'), s);
  pick(env, 'uj_req_why', '2');
  type(env, 'uj_wt', '300.0');
  check('G1b 300.0: the note asking for the weight\'s reason', ask(env, 'uj_wt') === NOTE('uj_wt_why'), ask(env, 'uj_wt'));
  s = save(env);
  check('G1b Next: held, the line naming the reason field', s === 'held: ' + HELD + offer('uj_wt', 'uj_wt_why'), s);
  type(env, 'uj_wt_why', 'Scale checked twice');
  check('G1c the reason typed: Next goes on to page 2', save(env) === 'saved');
}
{
  const env = boot(PAGES.survey, {}, { ready: READONLY, record: 'XE-4', sections: SECTION('B') });
  check('G2 page 2 (the code and the enrolment weight): Next goes on', save(env) === 'saved');
}
{
  const env = boot(PAGES.survey, {}, { ready: READONLY, record: 'XE-4', sections: SECTION('C') });
  type(env, 'uj_far', '30');
  check('G3 page 3, 30 hours: the range note', shown(env, 'uj_far', 'n') === '✗ This value is above the plausible range (allowed 0 to 24).', notes(env, 'uj_far'));
  check('G3 ... no note asks for a reason, as the reason field is on page 4', !region(env, 'uj_far', 'j') || ask(env, 'uj_far') === '', notes(env, 'uj_far'));
  const s = save(env);
  check('G3 Next is held, the line naming no reason field', s === 'held: ' + HELD + '\n• ' + label('uj_far'), s);
  type(env, 'uj_far', '12');
  check('G4 12: Next goes on to page 4', save(env) === 'saved');
}
{
  const env = boot(PAGES.survey, {}, { ready: READONLY, record: 'XE-4', sections: SECTION('D') });
  check('G4 page 4, the fasting reason alone: Next goes on', save(env) === 'saved');
}
{
  const env = boot(PAGES.survey, {}, { ready: READONLY, record: 'XE-4', sections: SECTION('E') });
  for (const f of Object.keys(PAGES.surveyErrors)) {
    if (f === 'uj_rr') continue;
    check('G5 page 5, ' + f + ': the survey\'s general wording', cfgErr(env, f) === SURVEY, cfgErr(env, f));
  }
  check('G5 Submit goes through', save(env) === 'saved');
}

// ---- Section J: what REDCap does that the code depends on -------------------------------------------
{
  // J2: "Explain the heart rate?" imported as No: the reason row opens hidden, its text kept
  const env = boot(PAGES.locked310, V.J2, { ready: READONLY });
  check('J2 the reason row opens hidden, its text still in the box', env.holders.uj_hr_why.style.display === 'none' && env.ctl.uj_hr_why.el.value === 'Athlete, resting rate checked');
  check('J2 the page counts no reason: the note asks for one', ask(env, 'uj_hr') === NOTE('uj_hr_why'), ask(env, 'uj_hr'));
  const s = save(env);
  check('J2 Save is held, the line naming the hidden reason field', s === 'held: ' + HELD + offer('uj_hr', 'uj_hr_why'), s);
}
{
  // J3: the "reset" link of a radio reason clears it with no event
  const env = boot(PAGES.locked310, V.J4, { ready: READONLY });
  check('J3 the form opens with "Not done" saved: no note asks for the blank field\'s reason', ask(env, 'uj_req') === '', ask(env, 'uj_req'));
  const c = env.ctl.uj_req_why;
  c.buttons.forEach((b) => { b.checked = false; });
  c.el.value = '';
  env.win.doBranching();
  env.poll();
  check('J3 "reset" under "Why it is blank": the note asking for the reason comes back', ask(env, 'uj_req') === NOTE('uj_req_why'), ask(env, 'uj_req'));
  const s = save(env);
  check('J3 Save is held, the line naming the reason field', s === 'held: ' + HELD + offer('uj_req', 'uj_req_why'), s);
  pick(env, 'uj_req_why', '1');
  check('J3 "Not done" again: the note goes, the record saves', ask(env, 'uj_req') === '' && save(env) === 'saved');
}
{
  // J4: an administrator added the SQL field "uj_sql_why"
  const env = boot(PAGES.sql, V.J4, { ready: READONLY, sql: true });
  check('J4 the respiratory rate\'s configuration error is gone', cfgErr(env, 'uj_rr') === '', cfgErr(env, 'uj_rr'));
  check('J4 ... the six other errors stay', Object.keys(PAGES.errors).filter((f) => f !== 'uj_rr').every((f) => cfgErr(env, f) === '⚠ Configuration error: ' + PAGES.errors[f]));
  type(env, 'uj_rr', '70');
  check('J4 70: the range note, and the note asking for the reason in the SQL field', shown(env, 'uj_rr', 'n') === '✗ This value is above the plausible range (allowed 5 to 60).'
    && ask(env, 'uj_rr') === NOTE('uj_sql_why'), notes(env, 'uj_rr'));
  const s = save(env);
  check('J4 Save is held, the line naming the SQL field by its label', s === 'held: ' + HELD + '\n• Respiratory rate (per minute) (or give a reason in “Why the respiratory rate is right”)', s);
  pick(env, 'uj_sql_why', '2');
  check('J4b "Counted twice": the note asking goes, the record saves', ask(env, 'uj_rr') === '' && save(env) === 'saved', ask(env, 'uj_rr'));
}

console.log('justify_live_twin_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
