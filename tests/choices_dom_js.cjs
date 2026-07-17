/*
 * choices_dom_js.cjs — the @UVCHOICES choice-filter mode's DOM contract.
 *
 * Drives the real QRIDChoiceFilterInit factory through the same DOM stub the
 * other *_dom tests use (plus removeChild/option support — the dropdown
 * renderer physically removes <option>s, the one technique Safari honors),
 * and asserts:
 *   - dropdown: hidden options are REMOVED and re-inserted in original order
 *     when the "when" gate flips; the blank placeholder is never removed; a
 *     selected-but-hidden option is KEPT (disabled) and flagged, never cleared,
 *   - radio: hidden codes hide their wrapper; a checked hidden code stays
 *     visible and flags invalid; branches switch the visible set live,
 *   - checkbox: a checked hidden code is stale (message + block); unchecking
 *     restores the filter and clears,
 *   - stale selection + blockSave:"hard" traps the save; fixing releases it,
 *   - branch conflict: filter NOT applied, message shown, never blocks,
 *   - "show" without choicesAll -> visible config error, no filtering,
 *   - survey context mutes condition detail in messages,
 *   - a value outside choicesAll (missing-data code) is never flagged,
 *   - tests/choices_fixture.json: the hidden set matches the PHP audit
 *     (tests/hook_php.php consumes the same file) for every case.
 *
 * Run:  node tests/choices_dom_js.cjs
 */
'use strict';
const path = require('path');
const fs = require('fs');

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), id: '', name: '', value: '', innerHTML: '',
    type: '', checked: false, disabled: false,
    style: {}, _attrs: {}, children: [], parentNode: null, readOnly: false,
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
    removeChild(node) {
      const i = this.children.indexOf(node);
      if (i >= 0) this.children.splice(i, 1);
      node.parentNode = null;
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
  // Everything is in the DOM at boot in these tests — the 500ms late-render
  // retry loops only wait on elements that will never appear. Neuter the
  // intervals so the test finishes instantly (setTimeout/debounce untouched).
  global.setInterval = () => 0;
  global.clearInterval = () => {};
  const origError = console.error;
  const consoleErrors = [];
  console.error = (m) => consoleErrors.push(String(m));
  try { require(enginePath); } finally { console.error = origError; }
  return { doc, win, holders, allEls, NS: win.INSPIREUniversalValidator, consoleErrors };
}
function rMsg(env, field) {
  const kids = env.holders[field].children;
  for (let i = 1; i < kids.length; i++) if (kids[i].getAttribute && kids[i].id && /^uvalidate-msg-/.test(kids[i].id)) return kids[i];
  return kids[1];
}
function submitEv() {
  return { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
}
function option(value) { const o = makeEl('option'); o.value = value; return o; }
function selectValues(sel) { return sel.children.filter((c) => c.tagName === 'OPTION').map((o) => o.value); }

// ---- 1) dropdown: remove/restore in order, stale selection kept + blocked --
{
  const method = makeEl('select'); method.name = 'method';
  ['', '1', '2', '9'].forEach((v) => method.appendChild(option(v)));
  method.value = '';
  const legacy = makeEl('input'); legacy.name = 'legacy'; legacy.value = '0';
  const env = boot([method, legacy], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'choices', fields: ['method'], choicesHide: ['9'],
              choicesAll: ['1', '2', '9'], when: "[legacy]='0'", blockSave: 'hard' }],
  });
  check('hidden option removed (blank kept)', selectValues(method).join(',') === ',1,2');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('nothing selected: no block', ev._prevented === false);

  legacy.value = '1'; legacy.fire('change');
  check('gate off: option restored IN ORDER', selectValues(method).join(',') === ',1,2,9');
  method.value = '9'; method.fire('change');
  legacy.value = '0'; legacy.fire('change');
  check('selected option is KEPT while hidden', selectValues(method).join(',') === ',1,2,9');
  const kept = method.children.find((o) => o.value === '9');
  check('kept option is disabled (dead end, not removable choice)', kept.disabled === true);
  check('stale selection flags aria-invalid', method.getAttribute('aria-invalid') === 'true');
  const msg = rMsg(env, 'method');
  check('stale message shown and names the condition', /no longer available/.test(msg.innerHTML) && /legacy/.test(msg.innerHTML));
  ev = submitEv(); env.doc.fire('submit', ev);
  check('stale + hard: save trapped', ev._prevented === true);

  method.value = '2'; method.fire('change');
  check('repick: cleared', msg.style.display === 'none' && method.getAttribute('aria-invalid') === null);
  check('repick: the formerly kept option is now removed', selectValues(method).join(',') === ',1,2');
  check('repick: no orphaned disabled flag', method.children.every((o) => !o.disabled));
  ev = submitEv(); env.doc.fire('submit', ev);
  check('repick: save allowed', ev._prevented === false);
}

// ---- 2) radio cascade: branches switch the visible set live -----------------
{
  const country = makeEl('select'); country.name = 'country'; country.value = '';
  const mirror = makeEl('input'); mirror.name = 'site'; mirror.type = 'hidden'; mirror.value = '';
  const radios = ['101', '102', '201'].map((v) => {
    const r = makeEl('input'); r.name = 'site___radio'; r.type = 'radio'; r.value = v; return r;
  });
  const env = boot([country, mirror, ...radios], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'choices', fields: ['site'], branches: [
      { when: "[country]='1'", choicesShow: ['101', '102'], choicesAll: ['101', '102', '201'], blockSave: 'hard' },
      { when: "[country]='2'", choicesShow: ['201'], choicesAll: ['101', '102', '201'], blockSave: 'hard' },
    ] }],
  });
  const shown = () => radios.filter((r) => r.parentNode.style.display !== 'none').map((r) => r.value).join(',');
  check('no branch active: all radios visible', shown() === '101,102,201');

  country.value = '1'; country.fire('change');
  check('branch 1: only its show-set visible', shown() === '101,102');
  country.value = '2'; country.fire('change');
  check('branch 2: visible set switches live', shown() === '201');

  // stale: pick 201 under branch 2, then flip to branch 1 (201 becomes hidden)
  mirror.value = '201'; radios[2].checked = true; radios[2].fire('click');
  check('valid pick under branch 2: no flag', mirror.getAttribute('aria-invalid') === null);
  country.value = '1'; country.fire('change');
  check('checked hidden radio stays VISIBLE', shown() === '101,102,201');
  check('stale radio flags invalid', mirror.getAttribute('aria-invalid') === 'true');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('stale radio + hard: save trapped', ev._prevented === true);
  check('registry test() reports the stale state', env.NS.validators.site.test() === false);

  // repick a shown code — REDCap writes the mirror, then the radio fires
  mirror.value = '101'; radios[2].checked = false; radios[0].checked = true; radios[0].fire('click');
  check('repick: cleared and filter reasserted', mirror.getAttribute('aria-invalid') === null && shown() === '101,102');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('repick: save allowed', ev._prevented === false);
}

// ---- 3) checkbox: checked hidden code is stale; unchecking restores ---------
{
  const pilot = makeEl('input'); pilot.name = 'pilot'; pilot.value = '0';
  const chks = ['1', '2', '9'].map((v) => {
    const c = makeEl('input'); c.name = '__chk__reach_RC_' + v; c.type = 'checkbox'; c.value = v; return c;
  });
  const env = boot([pilot, ...chks], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'choices', fields: ['reach'], choicesHide: ['9'],
              choicesAll: ['1', '2', '9'], when: "[pilot]='1'", blockSave: 'hard',
              message: 'Legacy channels are unavailable during the pilot.' }],
  });
  const box9 = chks[2].parentNode;
  check('gate off: nothing hidden', box9.style.display !== 'none');
  // check the code while it is allowed, then the filter activates
  chks[2].checked = true; chks[2].fire('click');
  pilot.value = '1'; pilot.fire('change');
  check('checked hidden checkbox stays visible', box9.style.display !== 'none');
  const msg = rMsg(env, 'pilot') && env.holders['__chk__reach_RC_1'] ? rMsg(env, '__chk__reach_RC_1') : null;
  const anyMsg = env.allEls.find((e) => e.id && /^uvalidate-msg-/.test(e.id) && /Legacy channels/.test(e.innerHTML));
  check('custom stale message shown', !!anyMsg);
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('stale checkbox + hard: save trapped', ev._prevented === true);
  chks[2].checked = false; chks[2].fire('click');
  check('unchecked: filter hides the code again', box9.style.display === 'none');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('unchecked: save allowed', ev._prevented === false);
  check('unhidden codes were never touched', chks[0].parentNode.style.display !== 'none');
}

// ---- 4) branch conflict: filter NOT applied, shown, never blocks ------------
{
  const a = makeEl('input'); a.name = 'a'; a.value = '1';
  const mirror = makeEl('input'); mirror.name = 'pick'; mirror.type = 'hidden'; mirror.value = '2';
  const r1 = makeEl('input'); r1.name = 'pick___radio'; r1.type = 'radio'; r1.value = '1';
  const r2 = makeEl('input'); r2.name = 'pick___radio'; r2.type = 'radio'; r2.value = '2'; r2.checked = true;
  const env = boot([a, mirror, r1, r2], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'choices', fields: ['pick'], branches: [
      { when: "[a]='1'", choicesHide: ['2'], choicesAll: ['1', '2'], blockSave: 'hard' },
      { when: "[a]<>'0'", choicesHide: ['1'], choicesAll: ['1', '2'], blockSave: 'hard' },
    ] }],
  });
  check('conflict: nothing hidden', r1.parentNode.style.display !== 'none' && r2.parentNode.style.display !== 'none');
  const msg = rMsg(env, 'pick');
  check('conflict message shown, filter NOT applied', /conflict/i.test(msg.innerHTML) && /NOT/.test(msg.innerHTML));
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('conflict never blocks', ev._prevented === false);
}

// ---- 5) "show" without choicesAll -> visible config error, no filtering -----
{
  const mirror = makeEl('input'); mirror.name = 'site'; mirror.type = 'hidden'; mirror.value = '';
  const r1 = makeEl('input'); r1.name = 'site___radio'; r1.type = 'radio'; r1.value = '1';
  const env = boot([mirror, r1], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'choices', fields: ['site'], choicesShow: ['1'] }],
  });
  const cfgMsg = env.allEls.find((e) => e.id && /-cfg$/.test(e.id));
  check('missing choicesAll: config error region attached', !!cfgMsg && /choicesAll/.test(cfgMsg.innerHTML));
  check('missing choicesAll: nothing hidden', r1.parentNode.style.display !== 'none');
}

// ---- 6) survey context mutes condition detail -------------------------------
{
  const legacy = makeEl('input'); legacy.name = 'legacy'; legacy.value = '0';
  const method = makeEl('select'); method.name = 'method';
  ['', '2', '9'].forEach((v) => method.appendChild(option(v)));
  method.value = '9';
  const env = boot([method, legacy], {
    singleFields: [], pooledFields: [], context: 'survey',
    rules: [{ type: 'choices', fields: ['method'], choicesHide: ['9'],
              choicesAll: ['2', '9'], when: "[legacy]='0'", blockSave: 'hard' }],
  });
  const msg = rMsg(env, 'method');
  check('survey: stale message shown without the condition text',
    /no longer available/.test(msg.innerHTML) && !/legacy/.test(msg.innerHTML));
}

// ---- 7) a value outside choicesAll (missing-data code) is never flagged -----
{
  const mirror = makeEl('input'); mirror.name = 'site'; mirror.type = 'hidden'; mirror.value = '-99';
  const r1 = makeEl('input'); r1.name = 'site___radio'; r1.type = 'radio'; r1.value = '1';
  const env = boot([mirror, r1], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'choices', fields: ['site'], choicesShow: ['1'],
              choicesAll: ['1', '2'], blockSave: 'hard' }],
  });
  check('MDC value: not flagged', mirror.getAttribute('aria-invalid') === null);
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('MDC value: save allowed', ev._prevented === false);
}

// ---- 8) confirm mode: allow when confirmed, block when declined -------------
{
  const legacy = makeEl('input'); legacy.name = 'legacy'; legacy.value = '0';
  const method = makeEl('select'); method.name = 'method';
  ['', '2', '9'].forEach((v) => method.appendChild(option(v)));
  method.value = '9';
  const env = boot([method, legacy], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'choices', fields: ['method'], choicesHide: ['9'],
              choicesAll: ['2', '9'], when: "[legacy]='0'", blockSave: 'confirm' }],
  });
  check('confirm: stale flagged', method.getAttribute('aria-invalid') === 'true');
  env.win.confirm = () => false;               // user clicks "Cancel"
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('confirm declined: save trapped', ev._prevented === true);
  env.win.confirm = () => true;                // user clicks "Save anyway"
  ev = submitEv(); env.doc.fire('submit', ev);
  check('confirm accepted: save allowed', ev._prevented === false);
}

// ---- 9) composition: @UVCHOICES + @UVREQUIRED on the SAME field -------------
// independent guard items — a stale choice blocks even while the field is
// non-blank (required satisfied), and vice-versa.
{
  const country = makeEl('input'); country.name = 'country'; country.value = '1';
  const mirror = makeEl('input'); mirror.name = 'site'; mirror.type = 'hidden'; mirror.value = '';
  const r1 = makeEl('input'); r1.name = 'site___radio'; r1.type = 'radio'; r1.value = '101';
  const r2 = makeEl('input'); r2.name = 'site___radio'; r2.type = 'radio'; r2.value = '201';
  const env = boot([country, mirror, r1, r2], {
    singleFields: [], pooledFields: [],
    rules: [
      { type: 'choices', fields: ['site'], choicesShow: ['101'], choicesAll: ['101', '201'],
        when: "[country]='1'", blockSave: 'hard' },
      { type: 'required', fields: ['site'], blockSave: 'hard' },
    ],
  });
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('compose: blank blocks (required)', ev._prevented === true);
  // pick the HIDDEN code 201 (out of the country-1 whitelist): required now
  // satisfied, but the choice filter must still block
  mirror.value = '201'; r2.checked = true; r2.fire('click');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('compose: stale-but-nonblank still blocks (choices)', ev._prevented === true);
  // pick the shown code 101: both modes satisfied
  mirror.value = '101'; r2.checked = false; r1.checked = true; r1.fire('click');
  ev = submitEv(); env.doc.fire('submit', ev);
  check('compose: valid pick clears both, save allowed', ev._prevented === false);
}

// ---- 10) one rule, TWO fields (groupMulti) — each filtered independently ----
{
  const s1 = makeEl('select'); s1.name = 's1'; ['', '1', '2', '9'].forEach((v) => s1.appendChild(option(v))); s1.value = '';
  const s2 = makeEl('select'); s2.name = 's2'; ['', '1', '2', '9'].forEach((v) => s2.appendChild(option(v))); s2.value = '9';
  const env = boot([s1, s2], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'choices', fields: ['s1', 's2'], choicesHide: ['9'],
              choicesAll: ['1', '2', '9'], blockSave: 'hard' }],
  });
  check('multi-field: field 1 filtered', selectValues(s1).join(',') === ',1,2');
  check('multi-field: field 2 filtered (own stale 9 kept, disabled)',
    selectValues(s2).join(',') === ',1,2,9' && s2.children.find((o) => o.value === '9').disabled === true);
  check('multi-field: only the stale field flags', s1.getAttribute('aria-invalid') === null
    && s2.getAttribute('aria-invalid') === 'true');
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('multi-field: the stale field blocks the shared save', ev._prevented === true);
}

// ---- 11) checkbox show-list (complement over choicesAll) --------------------
{
  const pilot = makeEl('input'); pilot.name = 'pilot'; pilot.value = '1';
  const chks = ['1', '2', '9'].map((v) => {
    const c = makeEl('input'); c.name = '__chk__reach_RC_' + v; c.type = 'checkbox'; c.value = v; return c;
  });
  const env = boot([pilot, ...chks], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'choices', fields: ['reach'], choicesShow: ['1', '2'],
              choicesAll: ['1', '2', '9'], when: "[pilot]='1'", blockSave: 'hard' }],
  });
  const shownRow = (i) => chks[i].parentNode.style.display !== 'none';
  check('checkbox show-list: whitelisted codes visible', shownRow(0) && shownRow(1));
  check('checkbox show-list: complement (9) hidden', !shownRow(2));
}

// ---- 12) readonly anchor: message shown, save NEVER trapped -----------------
{
  const legacy = makeEl('input'); legacy.name = 'legacy'; legacy.value = '0';
  const method = makeEl('select'); method.name = 'method'; method.readOnly = true;
  ['', '2', '9'].forEach((v) => method.appendChild(option(v)));
  method.value = '9';
  const env = boot([method, legacy], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'choices', fields: ['method'], choicesHide: ['9'],
              choicesAll: ['2', '9'], when: "[legacy]='0'", blockSave: 'hard' }],
  });
  const msg = rMsg(env, 'method');
  check('readonly: stale message still shown', /no longer available/.test(msg.innerHTML));
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('readonly: save never trapped (UX-003 exemption)', ev._prevented === false);
}

// ---- 13) fixture: the hidden-set contract shared with tests/hook_php.php -----
{
  const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'choices_fixture.json'), 'utf8'));
  check('fixture loads', Array.isArray(fx.cases) && fx.cases.length > 0);
  for (const c of fx.cases) {
    const sel = makeEl('select'); sel.name = 'pick';
    sel.appendChild(option(''));
    c.all.forEach((v) => sel.appendChild(option(v)));
    sel.value = '';
    const rule = { type: 'choices', fields: ['pick'], choicesAll: c.all.slice() };
    if (c.show) rule.choicesShow = c.show.slice(); else rule.choicesHide = c.hide.slice();
    boot([sel], { singleFields: [], pooledFields: [], rules: [rule] });
    const visible = selectValues(sel).filter((v) => v !== '');
    const expected = c.all.filter((v) => c.hidden.indexOf(v) === -1);
    check('fixture "' + c.name + '": visible = all minus hidden, in order',
      visible.join(',') === expected.join(','));
  }
}

console.log((fail === 0 ? 'OK' : 'FAILED') + ' — choices_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail === 0 ? 0 : 1);
