/*
 * window_dom_js.cjs — the @UVWINDOW window mode's DOM contract.
 *
 * Drives the real QRIDWindowInit factory through the same DOM stub the other
 * *_dom tests use, and asserts:
 *   - a date outside its window flags the field with the allowed dates in the
 *     FIELD's display format, and a hard rule traps the save; a date inside
 *     clears it,
 *   - a blank date, a blank "from" date and a partly typed date are inert,
 *   - editing the "from" field re-checks live,
 *   - notFuture is judged against the SERVER clock (config.clock), advanced by
 *     how long the page has been open, and the save-time recheck moves past
 *     midnight with it; without a server clock there is no verdict,
 *   - a snapshot "from" date (another form's saved value) never blocks and
 *     says what it was counted from; surveys never see a field name,
 *   - a deferred rule never blocks, and says why only on staff forms,
 *   - branched windows pick the active branch,
 *   - a rule with nothing to check is a visible configuration error,
 *   - weeks and datetime hours.
 *
 * The verdict itself is parity-locked by tests/window_js.cjs + window_php.php;
 * this file tests the DOM wiring around it.
 *
 * Run:  node tests/window_dom_js.cjs
 */
'use strict';
const path = require('path');

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), id: '', name: '', value: '', innerHTML: '',
    type: '', checked: false,
    // A browser's style.cssText replaces every inline property, display included.
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
    INSPIRE_VALIDATOR_CONFIG: Object.assign({ singleFields: [], pooledFields: [] }, config),
  };
  global.document = doc; global.window = win;
  require(enginePath);
  return { doc, win, holders, allEls, NS: win.INSPIREUniversalValidator };
}
/* The window mode's status region: the region whose id ends in "-w". */
function wMsg(env, field) {
  const kids = env.holders[field].children;
  for (let i = 1; i < kids.length; i++) if (kids[i].id && /-w$/.test(kids[i].id)) return kids[i];
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
function dateEl(name, value) { const e = makeEl('input'); e.name = name; e.value = value; return e; }
function shown(msg) { return !!msg && msg.style.display !== 'none' && msg.innerHTML !== ''; }

const realNow = Date.now;

// ---- 1) live "from" date, D-M-Y fields, hard block -------------------------
{
  const bl = dateEl('visit_date_bl', '01-03-2026');
  const v2 = dateEl('visit_date_2', '15-03-2026');     // 14 days: before the 21-35 window
  const env = boot([bl, v2], { rules: [{ type: 'window', fields: ['visit_date_2'],
    windowFrom: '[visit_date_bl]', windowFromOp: ['ref', 'visit_date_bl', null],
    windowLo: 21, windowHi: 35, windowUnit: 'days',
    dateType: 'date', dateFormat: 'dmy', fromType: 'date', fromFormat: 'dmy', blockSave: 'hard' }] });
  const msg = wMsg(env, 'visit_date_2');
  check('early: flagged', shown(msg) && /between 22-03-2026 and 05-04-2026/.test(msg.innerHTML));
  check('early: staff wording names the window', /21 to 35 days from \[visit_date_bl\]/.test(msg.innerHTML));
  check('early: aria-invalid', v2.getAttribute('aria-invalid') === 'true');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('early: hard block traps the save', ev._prevented === true);

  v2.value = '22-03-2026'; v2.fire('change');
  check('first day of the window: OK', /OK/.test(msg.innerHTML) && v2.getAttribute('aria-invalid') === 'false');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('inside: save allowed', ev._prevented === false);

  v2.value = '06-04-2026'; v2.fire('change');
  check('day after the window: flagged', /between 22-03-2026 and 05-04-2026/.test(msg.innerHTML));

  // moving the anchor re-checks without touching the field
  bl.value = '15-03-2026'; bl.fire('change');
  check('anchor change re-checks live', /OK/.test(msg.innerHTML));

  bl.value = ''; bl.fire('change');
  check('blank "from" date: inert', !shown(msg) && v2.getAttribute('aria-invalid') === null);
  ev = submitEv(); env.doc.fire('submit', ev);
  check('blank "from" date: never blocks', ev._prevented === false);

  bl.value = '01-03-2026'; bl.fire('change');
  v2.value = '15-0'; v2.fire('change');
  check('partly typed date: no verdict', !shown(msg));
  v2.value = '31-02-2026'; v2.fire('change');
  check('impossible date: no verdict (REDCap says so)', !shown(msg));
  v2.value = '   '; v2.fire('change');
  check('blank value: inert', !shown(msg));
  ev = submitEv(); env.doc.fire('submit', ev);
  check('blank value: never blocks', ev._prevented === false);

  check('validators[].test() agrees', env.NS.validators.visit_date_2.test() === null);
  v2.value = '15-03-2026'; v2.fire('change');
  check('validators[].test() false outside', env.NS.validators.visit_date_2.test() === false);
}

// ---- 2) open ends and the field's own format in the message ----------------
{
  const dob = dateEl('dob', '12-31-2000');              // M-D-Y
  const t = dateEl('test_date', '12-30-2000');
  const env = boot([dob, t], { rules: [{ type: 'window', fields: ['test_date'],
    windowFrom: '[dob]', windowFromOp: ['ref', 'dob', null], windowLo: 0, windowUnit: 'days',
    dateType: 'date', dateFormat: 'mdy', fromType: 'date', fromFormat: 'mdy' }] });
  const msg = wMsg(env, 'test_date');
  check('open latest: "on or after" in M-D-Y', /on or after 12-31-2000/.test(msg.innerHTML));
  check('open latest: staff range text', /at least 0 days from \[dob\]/.test(msg.innerHTML));
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('blockSave off by default: never blocks', ev._prevented === false);
  t.value = '01-01-2099'; t.fire('change');
  check('open latest: any later date passes', /OK/.test(msg.innerHTML));
}

// ---- 3) notFuture against the server clock, across midnight ---------------
{
  let fake = Date.UTC(2031, 0, 1, 0, 0, 0);
  Date.now = () => fake;
  const d = dateEl('collected', '2026-10-10');
  const env = boot([d], { clock: { today: '2026-10-09', now: '2026-10-09 23:59:50' },
    rules: [{ type: 'window', fields: ['collected'], windowNotFuture: true,
      dateType: 'date', dateFormat: 'ymd', blockSave: 'hard' }] });
  const msg = wMsg(env, 'collected');
  check('tomorrow (server) is future', /after today \(2026-10-09\)/.test(msg.innerHTML));
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('future: hard block', ev._prevented === true);
  d.value = '2026-10-09'; d.fire('change');
  check('today is not future', /OK/.test(msg.innerHTML));
  d.value = '2026-10-10'; d.fire('change');
  check('future again', /after today/.test(msg.innerHTML));
  fake += 15 * 1000;   // the page stays open past the SERVER's midnight
  ev = submitEv(); env.doc.fire('submit', ev);
  check('save-time recheck: after midnight the date is today, the save goes through', ev._prevented === false);
  check('save-time recheck repainted the field', /OK/.test(msg.innerHTML));
  Date.now = realNow;
}
{
  // The computer's own clock never decides: a browser far in the future still
  // judges against the server's day.
  Date.now = () => Date.UTC(2099, 0, 1);
  const d = dateEl('collected', '2030-01-01');
  const env = boot([d], { clock: { today: '2026-10-09', now: '2026-10-09 12:00:00' },
    rules: [{ type: 'window', fields: ['collected'], windowNotFuture: true, dateType: 'date', dateFormat: 'ymd' }] });
  check('browser clock ahead: still future by the server', /after today \(2026-10-09\)/.test(wMsg(env, 'collected').innerHTML));
  Date.now = realNow;
}
{
  const d = dateEl('collected', '2099-01-01');
  const env = boot([d], { rules: [{ type: 'window', fields: ['collected'], windowNotFuture: true,
    dateType: 'date', dateFormat: 'ymd', blockSave: 'hard' }] });
  check('no server clock: no verdict', !shown(wMsg(env, 'collected')));
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('no server clock: never blocks', ev._prevented === false);
}
{
  const d = dateEl('seen_at', '2026-10-09 15:00');
  const env = boot([d], { clock: { today: '2026-10-09', now: '2026-10-09 14:30:00' },
    rules: [{ type: 'window', fields: ['seen_at'], windowNotFuture: true, dateType: 'datetime', dateFormat: 'ymd' }] });
  check('datetime notFuture compares the time', /in the future/.test(wMsg(env, 'seen_at').innerHTML));
  d.value = '2026-10-09 14:30'; d.fire('change');
  check('datetime now is not future', /OK/.test(wMsg(env, 'seen_at').innerHTML));
}

// ---- 4) snapshot "from" date: advisory, names its source ------------------
{
  const v = dateEl('fu_date', '2026-01-01');
  const env = boot([v], { rules: [{ type: 'window', fields: ['fu_date'],
    windowFrom: '[enrol_date]', windowFromOp: ['lit', '2026-02-01'], snapshotFields: ['enrol_date'],
    windowLo: 0, windowHi: 30, windowUnit: 'days',
    dateType: 'date', dateFormat: 'ymd', fromType: 'date', fromFormat: 'dmy', blockSave: 'hard' }] });
  const msg = wMsg(env, 'fu_date');
  check('snapshot anchor read as Y-M-D whatever its field shows', /between 2026-02-01 and 2026-03-03/.test(msg.innerHTML));
  check('snapshot: names what it counted from', /counted from enrol_date, read when this page was opened/.test(msg.innerHTML));
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('snapshot: never blocks', ev._prevented === false);
}

// ---- 5) surveys: bounds yes, field names no ----------------------------------
{
  const bl = dateEl('visit_date_bl', '2026-03-01');
  const v = dateEl('visit_date_2', '2026-03-02');
  const env = boot([bl, v], { context: 'survey', rules: [{ type: 'window', fields: ['visit_date_2'],
    windowFrom: '[visit_date_bl]', windowFromOp: ['ref', 'visit_date_bl', null], windowLo: 21, windowHi: 35,
    windowUnit: 'days', dateType: 'date', dateFormat: 'ymd', fromType: 'date', fromFormat: 'ymd' }] });
  const msg = wMsg(env, 'visit_date_2');
  check('survey: bounds shown', /between 2026-03-22 and 2026-04-05/.test(msg.innerHTML));
  check('survey: no field name', !/visit_date_bl/.test(msg.innerHTML));
}

// ---- 6) deferred ---------------------------------------------------------------
{
  const v = dateEl('fu_date', '2026-01-01');
  const env = boot([v], { rules: [{ type: 'window', fields: ['fu_date'], windowFrom: '[enrol_date]',
    windowLo: 0, windowHi: 30, dateType: 'date', dateFormat: 'ymd', fromType: 'date', fromFormat: 'ymd',
    blockSave: 'hard', deferred: true, deferredWhy: ['[enrol_date] is not collected in this event.'] }] });
  const msg = wMsg(env, 'fu_date');
  check('deferred with a reason: staff see it', /not being checked/.test(msg.innerHTML) && /enrol_date/.test(msg.innerHTML));
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('deferred: never blocks', ev._prevented === false && v.getAttribute('aria-invalid') !== 'true');
}
{
  const v = dateEl('fu_date', '2026-01-01');
  const env = boot([v], { context: 'survey', rules: [{ type: 'window', fields: ['fu_date'], windowFrom: '[enrol_date]',
    windowLo: 0, windowHi: 30, dateType: 'date', dateFormat: 'ymd', fromType: 'date', fromFormat: 'ymd',
    blockSave: 'hard', deferred: true, deferredWhy: ['[enrol_date] is on another form.'] }] });
  check('deferred on a survey: silent', !shown(wMsg(env, 'fu_date')));
}

// ---- 7) branches -------------------------------------------------------------
{
  const arm = dateEl('arm', '1');
  const bl = dateEl('bl', '2026-03-01');
  const v = dateEl('v2', '2026-03-10');                  // 9 days
  const base = { windowFrom: '[bl]', windowFromOp: ['ref', 'bl', null], windowLo: 0, windowUnit: 'days',
                 dateType: 'date', dateFormat: 'ymd', fromType: 'date', fromFormat: 'ymd', blockSave: 'hard' };
  const env = boot([arm, bl, v], { rules: [{ type: 'window', fields: ['v2'], branches: [
    Object.assign({}, base, { when: "[arm]='1'", windowHi: 7 }),
    Object.assign({}, base, { when: "[arm]='2'", windowHi: 14 }) ] }] });
  const msg = wMsg(env, 'v2');
  check('branch arm 1: 7-day window fails', /between 2026-03-01 and 2026-03-08/.test(msg.innerHTML));
  arm.value = '2'; arm.fire('change');
  check('branch arm 2: 14-day window passes', /OK/.test(msg.innerHTML));
  arm.value = '3'; arm.fire('change');
  check('no branch applies: inert', !shown(msg));
}

// ---- 8) configuration errors and the plain-text fallback --------------------
{
  const v = dateEl('d', '2026-01-01');
  const env = boot([v], { rules: [{ type: 'window', fields: ['d'], dateType: 'date', dateFormat: 'ymd' }] });
  check('nothing to check: configuration error', !!cfgErr(env, 'd') && /nothing to check/.test(cfgErr(env, 'd').innerHTML));
}
{
  const v = dateEl('d', '2026-01-01');
  const env = boot([v], { rules: [{ type: 'window', fields: ['d'], windowNotFuture: true }] });
  check('no date type: configuration error', !!cfgErr(env, 'd') && /date field/.test(cfgErr(env, 'd').innerHTML));
}
{
  const a = dateEl('a', '2026-01-01');
  const v = dateEl('d', '2026-01-20');
  const env = boot([a, v], { rules: [{ type: 'window', fields: ['d'], windowFrom: '[a]', windowLo: 0, windowHi: 7,
    dateType: 'date', dateFormat: 'ymd', fromType: 'date', fromFormat: 'ymd' }] });
  check('"from" text alone reads a field of this page', /between 2026-01-01 and 2026-01-08/.test(wMsg(env, 'd').innerHTML));
}

// ---- 9) weeks, datetime hours, a custom message --------------------------------
{
  const a = dateEl('a', '2026-01-01');
  const v = dateEl('d', '2026-02-20');
  const env = boot([a, v], { rules: [{ type: 'window', fields: ['d'], windowFrom: '[a]', windowFromOp: ['ref', 'a', null],
    windowLo: 4, windowHi: 6, windowUnit: 'weeks', dateType: 'date', dateFormat: 'ymd', fromType: 'date', fromFormat: 'ymd',
    message: 'Week 4-6 <visit>' }] });
  const msg = wMsg(env, 'd');
  check('weeks: late', /Week 4-6 &lt;visit&gt;/.test(msg.innerHTML));
  v.value = '2026-02-12'; v.fire('change');
  check('weeks: inside (6 weeks = 2026-02-12)', /OK/.test(msg.innerHTML));
}
{
  const a = dateEl('dose_at', '2026-01-01 08:00');
  const v = dateEl('sample_at', '2026-01-01 09:30');
  const env = boot([a, v], { rules: [{ type: 'window', fields: ['sample_at'], windowFrom: '[dose_at]',
    windowFromOp: ['ref', 'dose_at', null], windowLo: 2, windowHi: 4, windowUnit: 'hours',
    dateType: 'datetime', dateFormat: 'ymd', fromType: 'datetime', fromFormat: 'ymd' }] });
  const msg = wMsg(env, 'sample_at');
  check('datetime hours: early, bounds keep their time', /between 2026-01-01 10:00 and 2026-01-01 12:00/.test(msg.innerHTML));
  v.value = '2026-01-01 12:00'; v.fire('change');
  check('datetime hours: the latest minute is inside', /OK/.test(msg.innerHTML));
}

// ---- 10) composes with a constraint on the same field ---------------------------
{
  const a = dateEl('a', '2026-01-01');
  const v = dateEl('d', '2026-01-03');
  const env = boot([a, v], { rules: [
    { type: 'window', fields: ['d'], windowFrom: '[a]', windowFromOp: ['ref', 'a', null], windowLo: 0, windowHi: 7,
      dateType: 'date', dateFormat: 'ymd', fromType: 'date', fromFormat: 'ymd', blockSave: 'hard' },
    { type: 'constraint', fields: ['d'], assert: "[d]<>'2026-01-03'", blockSave: 'hard' } ] });
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('compose: a passing window does not clear a failing constraint', ev._prevented === true);
  v.value = '2026-01-20'; v.fire('change');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('compose: a failing window blocks on its own', ev._prevented === true && /between/.test(wMsg(env, 'd').innerHTML));
}

Date.now = realNow;
console.log(`window_dom_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail ? 1 : 0);
