/*
 * constraint_dom_js.cjs — the @UVASSERT constraint mode's DOM contract.
 *
 * Drives the real QRIDConstraintInit factory through the same DOM stub the
 * other *_dom tests use, and asserts:
 *   - a false assert flags the field (custom message, aria-invalid) and a HARD
 *     rule traps the save; a true assert clears it and allows the save,
 *   - an EMPTY validated field is inert (emptiness is @UVREQUIRED's job),
 *   - editing a REFERENCED field re-checks live,
 *   - constraints work on non-text fields (a <select> dropdown), read via
 *     QRID_WHEN.readRef and anchored via QRID_findAnchor,
 *   - a "when" gate makes the whole constraint inert while false,
 *   - MODE COMPOSITION: a check rule and a constraint rule on the SAME field
 *     both attach (no false duplicate) and keep INDEPENDENT block state — a
 *     passing constraint never clears a failing check's block, and vice versa,
 *   - branched constraints (two @UVASSERT with different "when"): active branch
 *     validates; both true -> conflict, never blocks,
 *   - a constraint with no condition is a visible config error,
 *   - M-04 BLANK PARITY: "no answer" is decided with the evaluators' own
 *     charlist (" \t\r\n"), so space/tab/CRLF go inert but a Unicode NBSP is
 *     judged — what matters is that the browser and the server AGREE,
 *   - M-02 SNAPSHOT DIAGNOSTIC: a failure names the off-page fields it was
 *     compared against and when they were read; survey respondents get the
 *     designer's wording only, never a field name.
 *
 * The assert evaluator itself is parity-locked by tests/when_js.cjs +
 * when_php.php; this file tests the constraint DOM wiring around it.
 *
 * Run:  node tests/constraint_dom_js.cjs
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
  const origError = console.error;
  const consoleErrors = [];
  console.error = (m) => consoleErrors.push(String(m));
  try { require(enginePath); } finally { console.error = origError; }
  return { doc, win, holders, allEls, NS: win.INSPIREUniversalValidator, consoleErrors };
}
/* the constraint's status region for a field: its holder's first inserted <div>
   (the region is inserted right after the input). */
function cMsg(env, field) {
  const kids = env.holders[field].children;
  for (let i = 1; i < kids.length; i++) if (kids[i].getAttribute && kids[i].id && /^uvalidate-msg-/.test(kids[i].id)) return kids[i];
  return kids[1];
}
function submitEv() {
  return { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
}

// ---- 1) basic text constraint: end >= start (hard block) -------------------
{
  const start = makeEl('input'); start.name = 'start'; start.value = '2024-01-10';
  const end = makeEl('input'); end.name = 'end'; end.value = '2024-01-05'; // end < start -> invalid
  const env = boot([start, end], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['end'], assert: '[end]>=[start]',
              message: 'End date must be on or after the start date', blockSave: 'hard' }],
  });
  const msg = cMsg(env, 'end');
  check('assert false: custom message shown', /on or after the start date/.test(msg.innerHTML));
  check('assert false: aria-invalid set', end.getAttribute('aria-invalid') === 'true');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('assert false: hard block traps the save', ev._prevented === true);

  // fix the end date -> valid
  end.value = '2024-01-20';
  end.fire('change');
  check('assert true: OK message', /OK/.test(msg.innerHTML) && !/must be on or after/.test(msg.innerHTML));
  check('assert true: aria-invalid cleared', end.getAttribute('aria-invalid') === 'false');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('assert true: save allowed', ev._prevented === false);

  // empty end -> inert (not @UVASSERT's job to require a value)
  end.value = '';
  end.fire('change');
  check('empty field: inert (no message)', msg.style.display === 'none');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('empty field: save never trapped', ev._prevented === false);
}

// ---- 2) live reaction to a referenced field --------------------------------
{
  const start = makeEl('input'); start.name = 'start'; start.value = '2024-06-01';
  const end = makeEl('input'); end.name = 'end'; end.value = '2024-05-01'; // end < start
  const env = boot([start, end], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['end'], assert: '[end]>=[start]', blockSave: 'hard' }],
  });
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('referenced-field: initially blocked', ev._prevented === true);
  // move START earlier so end >= start becomes true, without touching end
  start.value = '2024-01-01';
  start.fire('change');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('referenced-field change re-checks live -> unblocked', ev._prevented === false);
}

// ---- 3) constraint on a DROPDOWN (field-type extension) ---------------------
{
  const grade = makeEl('select'); grade.name = 'grade'; grade.value = '9'; // 9 = "unknown", disallowed
  const env = boot([grade], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['grade'], assert: "[grade]<>'9'",
              message: 'Please choose a real grade', blockSave: 'hard' }],
  });
  const msg = cMsg(env, 'grade');
  check('dropdown constraint attaches (anchored on <select>)', msg && msg.getAttribute('role') === 'status');
  check('dropdown assert false: flagged', /choose a real grade/.test(msg.innerHTML) && grade.getAttribute('aria-invalid') === 'true');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('dropdown assert false: blocked', ev._prevented === true);
  grade.value = '2';
  grade.fire('change');
  check('dropdown assert true: OK', /OK/.test(msg.innerHTML));
  ev = submitEv(); env.doc.fire('submit', ev);
  check('dropdown assert true: save allowed', ev._prevented === false);
}

// ---- 4) "when" gate: constraint inert while the gate is false ---------------
{
  const active = makeEl('select'); active.name = 'active'; active.value = '0';
  const end = makeEl('input'); end.name = 'end'; end.value = '2000-01-01';
  const start = makeEl('input'); start.name = 'start'; start.value = '2024-01-01';
  const env = boot([active, end, start], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['end'], assert: '[end]>=[start]',
              when: "[active]='1'", blockSave: 'hard' }],
  });
  const msg = cMsg(env, 'end');
  check('when false: constraint inert despite a violated assert', msg.style.display === 'none');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('when false: save never trapped', ev._prevented === false);
  active.value = '1';
  active.fire('change');
  check('when true: constraint now enforced', end.getAttribute('aria-invalid') === 'true');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('when true: hard block traps the save', ev._prevented === true);
}

// ---- 5) MODE COMPOSITION: check rule + constraint rule on the SAME field ----
{
  const pid = makeEl('input'); pid.name = 'pid'; pid.value = 'AB1234';   // matches the check pattern
  const pid2 = makeEl('input'); pid2.name = 'pid2'; pid2.value = 'ZZ9999';
  const env = boot([pid, pid2], {
    singleFields: [], pooledFields: [],
    rules: [
      { type: 'single', fields: ['pid'], algorithm: 'none', idPattern: '[A-Z]{2}[0-9]{4}', blockSave: 'hard' },
      { type: 'constraint', fields: ['pid'], assert: '[pid]=[pid2]',
        message: 'The two IDs must match', blockSave: 'hard' },
    ],
  });
  // no "listed in more than one rule" config error should appear
  check('compose: no false duplicate notice', !env.allEls.some((e) => e.id === 'uvalidate-config-errors'));
  // check passes (pattern ok) but constraint fails (pid != pid2) -> blocked by constraint
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('compose: constraint independently blocks (check ok, assert bad)', ev._prevented === true);
  // make them match -> both pass -> allowed
  pid2.value = 'AB1234';
  pid2.fire('change');
  pid.fire('change');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('compose: both pass -> save allowed', ev._prevented === false);
  // break the CHECK (bad pattern) while keeping the assert satisfied (pid==pid2):
  // a passing constraint must NOT clear the check rule's block
  pid.value = 'XX'; pid2.value = 'XX';
  pid2.fire('change'); pid.fire('change');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('compose: passing constraint does NOT clear a failing check block', ev._prevented === true);
}

// ---- 6) branched constraints (two @UVASSERT, different "when") --------------
{
  const t = makeEl('select'); t.name = 't'; t.value = '1';
  const x = makeEl('input'); x.name = 'x'; x.value = '-5';
  const env = boot([t, x], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['x'],
      branches: [
        { assert: "[x]>'0'", when: "[t]='1'", blockSave: 'hard', message: 'Must be positive when t=1' },
        { assert: "[x]<'0'", when: "[t]='2'", blockSave: 'hard', message: 'Must be negative when t=2' },
      ] }],
  });
  const msg = cMsg(env, 'x');
  check('branch t=1: the "positive" branch is active and violated', /positive/.test(msg.innerHTML));
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('branch t=1: blocked', ev._prevented === true);
  t.value = '2';
  t.fire('change');
  check('branch t=2: the "negative" branch now applies and is satisfied (-5<0)', /OK/.test(msg.innerHTML));
  ev = submitEv(); env.doc.fire('submit', ev);
  check('branch t=2: save allowed', ev._prevented === false);
}

// ---- 7) constraint with no condition -> visible config error ---------------
{
  const x = makeEl('input'); x.name = 'x'; x.value = 'v';
  const env = boot([x], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['x'] }],
  });
  const holder = env.holders['x'];
  const cfg = holder.children.find((c) => c.id && /-cfg$/.test(c.id));
  check('no-assert constraint: config error region under the field', !!cfg && /condition/.test(cfg.innerHTML));
}

// ---- 8) generic fallback message when none is supplied ---------------------
{
  const a = makeEl('input'); a.name = 'a'; a.value = '5';
  const b = makeEl('input'); b.name = 'b'; b.value = '9';
  const env = boot([a, b], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['a'], assert: '[a]>=[b]', blockSave: 'off' }],
  });
  const msg = cMsg(env, 'a');
  check('no message: generic wording shown, still flagged', /fails its validation rule/.test(msg.innerHTML));
}

// ---- 9) CROSS-FORM, entitled viewer: the server bakes the off-page value in
//         as a ['lit', …] operand and the comparison stays LIVE. Only end_date
//         is in the DOM — start_date lives on another instrument. -----------
{
  const end = makeEl('input'); end.name = 'end_date'; end.value = '';
  const env = boot([end], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['end_date'], assert: '[end_date]>=[start_date]',
              assertAst: ['cmp', '>=', ['ref', 'end_date', null], ['lit', '2026-05-10']],
              /* the server always emits this alongside a baked literal */
              snapshotFields: ['start_date'],
              message: 'End date must be on or after the start date', blockSave: 'hard' }],
  });
  const msg = cMsg(env, 'end_date');
  check('cross-form: blank field is inert', msg.style.display === 'none');

  // the regression that started this: typing a CORRECT value must clear
  end.value = '2026-06-01';
  end.fire('change');
  check('cross-form: a valid value shows OK (was falsely flagged before 1.6.0)',
    /OK/.test(msg.innerHTML));
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('cross-form: a correct save is allowed (was hard-blocked before 1.6.0)',
    ev._prevented === false);

  // and a wrong value must still be FLAGGED live, though advisory-only
  end.value = '2020-01-01';
  end.fire('change');
  check('cross-form: an invalid value is flagged live',
    /on or after the start date/.test(msg.innerHTML));
  ev = submitEv(); env.doc.fire('submit', ev);
  /* ADVISORY: the referenced value is a page-load snapshot, so it never drives
     a block even at blockSave:"hard". The audit is the enforcement record. */
  check('cross-form: flagging it does NOT block the save (advisory)',
    ev._prevented === false);
  check('cross-form: the message names the snapshot field and says it does not block',
    /start_date/.test(msg.innerHTML) && /does not block/.test(msg.innerHTML));

  // back and forth keeps working (the verdict is not frozen)
  end.value = '2026-07-07';
  end.fire('change');
  check('cross-form: verdict tracks the field both ways', /OK/.test(msg.innerHTML));
}

// ---- 10) DEFERRED (survey, or no rights to the referenced form): the server
//          could not disclose the value, so the client states no verdict and
//          never blocks. The post-save audit is the enforcement point. ------
{
  const end = makeEl('input'); end.name = 'end_date'; end.value = '';
  const env = boot([end], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['end_date'], assert: '[end_date]>=[start_date]',
              assertAst: ['const', false], deferred: true,
              message: 'End date must be on or after the start date', blockSave: 'hard' }],
  });
  const msg = cMsg(env, 'end_date');
  check('deferred: inert at load', msg.style.display === 'none');

  end.value = '2026-06-01';
  end.fire('change');
  check('deferred: a correct value is NOT falsely flagged', !/on or after/.test(msg.innerHTML));
  check('deferred: no verdict is stated at all', msg.style.display === 'none');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('deferred: the save is never blocked, whatever blockSave said',
    ev._prevented === false);

  // even a value the frozen const would have called invalid must not block
  end.value = '2020-01-01';
  end.fire('change');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('deferred: still never blocks (server audit enforces)', ev._prevented === false);
  check('deferred: aria-invalid is not asserted', end.getAttribute('aria-invalid') !== 'true');

  /* A deferred rule forces blockSave to "off" in makeVariant, so firstBlock is
     "off" and the factory registers NO save-guard entry for the field at all.
     inert() would keep the guard disarmed anyway, but that belt-and-braces is
     exactly why this needs its own assertion: without it, dropping the
     blockSave override is a silent no-op that leaves a phantom blocker in the
     guard for every deferred field on the page. */
  const items = (env.NS.guard && env.NS.guard.items) || [];
  check('deferred: registers no save-guard entry at all',
    items.filter((i) => i.__qridFieldName === 'end_date').length === 0);
}

// ---- 11) BRANCHED cross-form: deferral is per BRANCH, not per rule --------
//          branch t=1 references a field this viewer may not read (deferred);
//          branch t=2 references one they may (baked literal, live).
{
  const t = makeEl('input'); t.name = 't'; t.value = '1';
  const v = makeEl('input'); v.name = 'v'; v.value = '';
  const env = boot([t, v], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['v'], branches: [
      { when: "[t]='1'", assert: '[v]=[secret]', assertAst: ['const', false], deferred: true,
        message: 'secret branch', blockSave: 'hard' },
      { when: "[t]='2'", assert: '[v]=[open]',
        assertAst: ['cmp', '=', ['ref', 'v', null], ['lit', 'OPENVAL']],
        message: 'open branch', blockSave: 'hard' },
    ] }],
  });
  const msg = cMsg(env, 'v');

  // branch 1 active (deferred): anything typed is inert and never blocks
  v.value = 'ANYTHING'; v.fire('change');
  check('branch deferral: the deferred branch states no verdict',
    msg.style.display === 'none');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('branch deferral: the deferred branch never blocks', ev._prevented === false);

  // switch to branch 2 (live): the same field now validates for real
  t.value = '2'; t.fire('change');
  v.value = 'WRONGVAL'; v.fire('change');
  check('branch deferral: the live branch flags a wrong value',
    /open branch/.test(msg.innerHTML));
  ev = submitEv(); env.doc.fire('submit', ev);
  check('branch deferral: the live branch DOES block', ev._prevented === true);

  v.value = 'OPENVAL'; v.fire('change');
  check('branch deferral: the live branch clears on the right value', /OK/.test(msg.innerHTML));
  ev = submitEv(); env.doc.fire('submit', ev);
  check('branch deferral: and allows the save', ev._prevented === false);

  // back to the deferred branch — the block must not linger from branch 2
  t.value = '1'; t.fire('change');
  v.value = 'WRONGVAL'; v.fire('change');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('branch deferral: switching back to deferred releases the block',
    ev._prevented === false);
}

// ---- 12) a DEFERRED constraint must not disarm a COMPOSED check rule ------
//          modes compose and keep independent block state: deferring the
//          constraint must not buy the check rule a free pass.
{
  const f = makeEl('input'); f.name = 'idf'; f.value = '';
  const env = boot([f], {
    singleFields: [], pooledFields: [],
    rules: [
      { type: 'constraint', fields: ['idf'], assert: '[idf]=[secret]',
        assertAst: ['const', false], deferred: true,
        message: 'deferred constraint', blockSave: 'hard' },
      // pattern-only (algorithm "none") so this half of the test turns purely
      // on the composed rule being satisfiable, not on check-character
      // arithmetic that a future scheme change could invalidate.
      { type: 'single', fields: ['idf'], algorithm: 'none',
        idPattern: '[1-8][A-Z]{3}-[0-9A-Z]{6}', blockSave: 'hard' },
    ],
  });
  f.value = 'NOT-A-VALID-ID'; f.fire('change');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('compose: a deferred constraint does NOT release the check rule\'s block',
    ev._prevented === true);

  // The discriminating half: with the pattern satisfied, the ONLY thing that
  // could still hold the save is the deferred constraint (frozen const false).
  // If deferral regressed this would block, so this half fails where the first
  // half cannot.
  f.value = '8QRS-55555E'; f.fire('change');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('compose: with the check rule satisfied, the deferred constraint alone never blocks',
    ev._prevented === false);
}

// ---- 13) M-04 BLANK PARITY: what counts as "no answer" must be the SAME on
//          both sides. The client decides inertness with QRID_whenTrim, whose
//          charlist is " \t\r\n" — exactly what BOTH evaluators trim with
//          before comparing (QRID_whenCompare in js/engine.js,
//          Logic::compare in php/Logic.php) and exactly what the server's
//          constraint branch skips a blank value with
//          (UniversalValidator.php: trim((string) $value, " \t\r\n") === ''
//          -> continue).
//
//          Before the fix the client used String.prototype.trim, which ALSO
//          strips Unicode spaces (U+00A0 and friends). A field holding a
//          single NBSP therefore went silently inert in the browser while the
//          server — trimming ASCII whitespace only — still compared "\u00A0"
//          against the assert, found it false, and logged a violation the user
//          was never shown.
//
//          Per host value, what each runtime must now do:
//            " "        client inert    server skips (trim -> "")     AGREE
//            "\t"       client inert    server skips                  AGREE
//            "\r\n"     client inert    server skips                  AGREE
//            "\u00A0"   client JUDGES   server judges: NBSP is not in
//                                        the charlist, so it survives
//                                        the trim, and [x]='OK' is false  AGREE
//          The claim is not that NBSP is better treated as a real value — it
//          is that the two runtimes must not disagree about it.
//
//          The NBSP host value is written as an escape on purpose: a
//          literal U+00A0 in this source would be invisible, and one stray
//          editor normalization away from silently collapsing this case
//          into the plain-space case above it.
{
  const HOSTS = [
    { label: 'a single space', value: ' ', inert: true },
    { label: 'a tab', value: '\t', inert: true },
    { label: 'a CRLF', value: '\r\n', inert: true },
    { label: 'a Unicode NBSP (U+00A0)', value: '\u00A0', inert: false },
  ];
  for (const host of HOSTS) {
    const x = makeEl('input'); x.name = 'x'; x.value = host.value;
    const env = boot([x], {
      singleFields: [], pooledFields: [],
      rules: [{ type: 'constraint', fields: ['x'], assert: "[x]='OK'",
                message: 'x must be OK', blockSave: 'hard' }],
    });
    const msg = cMsg(env, 'x');
    const ev = submitEv(); env.doc.fire('submit', ev);
    if (host.inert) {
      // a fresh boot, so an untouched region proves no verdict was ever stated
      check(`M-04: ${host.label} is blank on BOTH sides -> inert, no verdict`,
        msg.style.display === 'none' && msg.innerHTML === '' &&
        x.getAttribute('aria-invalid') === null);
      check(`M-04: ${host.label} never traps the save`, ev._prevented === false);
    } else {
      check(`M-04: ${host.label} is blank on NEITHER side -> judged and flagged`,
        /x must be OK/.test(msg.innerHTML) && x.getAttribute('aria-invalid') === 'true');
      check(`M-04: ${host.label} blocks in the browser exactly as the server would`,
        ev._prevented === true);
    }
  }
}

// ---- 14) M-02 SNAPSHOT DIAGNOSTIC (data-entry form): off-page operands are
//          baked once, when the page is built. Naming them on a failure lets
//          the user tell a real violation from a stale read (someone edited
//          the other form in another tab) and reload — without it a wrong hard
//          block is a dead end with no explanation.
{
  const end = makeEl('input'); end.name = 'end_date'; end.value = '2020-01-01';
  const env = boot([end], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['end_date'], assert: '[end_date]>=[start_date]',
              assertAst: ['cmp', '>=', ['ref', 'end_date', null], ['lit', '2026-05-10']],
              snapshotFields: ['start_date'],
              message: 'End date must be on or after the start date', blockSave: 'hard' }],
  });
  const msg = cMsg(env, 'end_date');
  check('M-02: the failure still leads with the designer\'s own message',
    /on or after the start date/.test(msg.innerHTML));
  check('M-02: the failure names the off-page field it was compared against',
    /start_date/.test(msg.innerHTML));
  check('M-02: and says the value was read when this page was opened',
    /read when this page was opened/.test(msg.innerHTML));

  // a PASS needs no caveat: the note exists to explain a block, not to nag
  end.value = '2026-06-01';
  end.fire('change');
  check('M-02: no snapshot note on a passing value',
    /OK/.test(msg.innerHTML) && !/start_date/.test(msg.innerHTML));
}

// ---- 15) M-02 on a SURVEY: same rule, same block, generic wording. A
//          respondent cannot act on "start_date" and must not learn the name
//          of a field on an instrument they were never shown (the same muting
//          the branch-conflict and config-error paths already do).
{
  const end = makeEl('input'); end.name = 'end_date'; end.value = '2020-01-01';
  const env = boot([end], {
    singleFields: [], pooledFields: [], context: 'survey',
    rules: [{ type: 'constraint', fields: ['end_date'], assert: '[end_date]>=[start_date]',
              assertAst: ['cmp', '>=', ['ref', 'end_date', null], ['lit', '2026-05-10']],
              snapshotFields: ['start_date'],
              message: 'End date must be on or after the start date', blockSave: 'hard' }],
  });
  const msg = cMsg(env, 'end_date');
  check('M-02 survey: the respondent still gets the designer\'s message',
    /on or after the start date/.test(msg.innerHTML));
  check('M-02 survey: no off-page field name leaks to the respondent',
    !/start_date/.test(msg.innerHTML));
  check('M-02 survey: no "read when this page was opened" detail either',
    !/page was opened/.test(msg.innerHTML));
  const ev = submitEv(); env.doc.fire('submit', ev);
  /* Advisory-only: a snapshot never blocks, on a survey or a staff form. The
     verdict is stale the moment the other form changes, and nothing on this
     page can refresh it, so the save proceeds and the post-save audit is the
     record. The respondent still SEES the designer's message. */
  check('M-02 survey: a snapshot rule does not block the save',
    ev._prevented === false);
  check('M-02 survey: but the message is still shown',
    /on or after the start date/.test(msg.innerHTML));
}

// ---- 16) deferredWhy: shown to STAFF, withheld from survey respondents.
//
//          A deferred rule states no verdict, but it must still say WHY it
//          stopped checking — the server builds a human-readable reason and
//          discarding it is the same silent failure the reason exists to
//          prevent (M-05): the rule goes quiet and the only trace is a
//          module-log entry the person typing the value never sees.
//          Respondents are still told nothing: the reasons name other
//          instruments and fields, and a respondent cannot act on a design
//          problem. Neither path ever blocks.
{
  /* the shape UniversalValidator.php ships: an array of human-readable reasons
     attached to the first deferred rule (see the $notices loop). */
  const WHY = 'the "assert" condition reads [start_date], which is on a form not designated for this event';
  function bootDeferred(context) {
    const v = makeEl('input'); v.name = 'v'; v.value = '';
    const cfg = {
      singleFields: [], pooledFields: [],
      rules: [{ type: 'constraint', fields: ['v'], assert: '[v]=[start_date]',
                assertAst: ['const', false], deferred: true, deferredWhy: [WHY],
                message: 'v must match the start date', blockSave: 'hard' }],
    };
    if (context) cfg.context = context;
    const env = boot([v], cfg);
    return { env: env, v: v, msg: cMsg(env, 'v') };
  }

  const s = bootDeferred('survey');
  s.v.value = 'ANYTHING';
  s.v.fire('change');
  check('deferredWhy: the reason is never rendered to a survey respondent',
    !/designated for this event/.test(s.msg.innerHTML) && !/start_date/.test(s.msg.innerHTML));
  check('deferredWhy: the survey region states no verdict at all',
    s.msg.style.display === 'none' && s.msg.innerHTML === '');

  const d = bootDeferred(null);
  d.v.value = 'ANYTHING';
  d.v.fire('change');
  check('deferredWhy: staff DO see the reason on a data-entry form',
    /designated for this event/.test(d.msg.innerHTML));
  /* The wording must NOT promise a later check. A rule deferred for an
     unresolved reference is skipped by the audit too (it emits an
     "unconfigurable" note and returns), so "still checked after the save" was
     false — the same overstatement class as calling deferral "enforcement". */
  check('deferredWhy: staff are told the rule is NOT checked',
    /not being checked/i.test(d.msg.innerHTML));
  check('deferredWhy: and that saving does not check it either',
    /not checked after saving/i.test(d.msg.innerHTML));
  check('deferredWhy: it does NOT promise a post-save check',
    !/still checked after the save/i.test(d.msg.innerHTML));
  check('deferredWhy: it points at the study team, who must fix the rule',
    /study team/i.test(d.msg.innerHTML));
  check('deferredWhy: it is shown as a notice, not as a pass/fail verdict',
    !/OK\./.test(d.msg.innerHTML) && d.v.getAttribute('aria-invalid') !== 'true');
  const ev = submitEv(); d.env.doc.fire('submit', ev);
  check('deferredWhy: the deferred rule still never blocks the save',
    ev._prevented === false);
}

// ---- 17) H-01: an unresolved BRANCH SELECTOR must not hand control to the
//          fallback. A false gate is merely inert for a plain rule, but for a
//          branched one it ACTIVATES the else branch, which then enforced --
//          flagging the field and blocking the save for a rule the designer
//          never meant to apply here. The server now marks every branch (and the
//          rule) deferred; the client must honour that on all of them.
{
  const WHY = ['references "[b_open]", which is on a different repeating instrument.'];
  const v = makeEl('input'); v.name = 'a_val'; v.value = 'X';
  const env = boot([v], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'constraint', fields: ['a_val'], deferred: true, deferredWhy: WHY,
      branches: [
        { when: "[b_open]='SPECIAL'", assert: "[a_val]='X'", assertAst: ['cmp','=',['ref','a_val',null],['lit','X']],
          whenAst: ['const', false], deferred: true, deferredWhy: WHY,
          message: 'SPECIAL-branch', blockSave: 'hard' },
        { when: null, assert: "[a_val]='Y'", assertAst: ['cmp','=',['ref','a_val',null],['lit','Y']],
          deferred: true, deferredWhy: WHY,
          message: 'FALLBACK-else', blockSave: 'hard' },
      ] }],
  });
  const msg = cMsg(env, 'a_val');
  check('H-01: the fallback branch does NOT flag the field',
    !/FALLBACK-else/.test(msg.innerHTML));
  check('H-01: aria-invalid is not asserted', v.getAttribute('aria-invalid') !== 'true');
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('H-01: the fallback branch does NOT block the save', ev._prevented === false);
  check('H-01: staff are told the rule is not being checked',
    /not being checked/i.test(msg.innerHTML));
  check('H-01: and the reason names the blocking field',
    /b_open/.test(msg.innerHTML));
}

// ---- 18) the same, on a SURVEY: still no block, and no field names leak -----
{
  const WHY = ['references "[b_open]", which is on a different repeating instrument.'];
  const v = makeEl('input'); v.name = 'a_val'; v.value = 'X';
  const env = boot([v], {
    singleFields: [], pooledFields: [], context: 'survey',
    rules: [{ type: 'constraint', fields: ['a_val'], deferred: true, deferredWhy: WHY,
      branches: [
        { when: "[b_open]='SPECIAL'", assert: "[a_val]='X'", assertAst: ['cmp','=',['ref','a_val',null],['lit','X']],
          whenAst: ['const', false], deferred: true, deferredWhy: WHY,
          message: 'SPECIAL-branch', blockSave: 'hard' },
        { when: null, assert: "[a_val]='Y'", assertAst: ['cmp','=',['ref','a_val',null],['lit','Y']],
          deferred: true, deferredWhy: WHY, message: 'FALLBACK-else', blockSave: 'hard' },
      ] }],
  });
  const msg = cMsg(env, 'a_val');
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('H-01 survey: no block', ev._prevented === false);
  check('H-01 survey: no off-page field name leaks', !/b_open/.test(msg.innerHTML));
}

console.log(`constraint_dom_js: ${n} checks, ${fail} failure(s)`);
process.exit(fail === 0 ? 0 : 1);
