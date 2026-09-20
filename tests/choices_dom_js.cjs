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

function boot(els, config, setup) {
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  const allEls = [];
  const body = makeEl('body');
  const holders = {};
  const groupHolders = {};
  for (const el of els) {
    // Elements sharing a groupKey land in ONE holder — models REDCap's
    // choicevert row, where a checkbox option's hidden mirror and visible
    // <input type=checkbox> live together (so hiding the row hides both).
    let holder;
    if (el.groupKey && groupHolders[el.groupKey]) {
      holder = groupHolders[el.groupKey];
    } else {
      holder = makeEl('div');
      body.appendChild(holder);
      allEls.push(holder);
      if (el.groupKey) groupHolders[el.groupKey] = holder;
    }
    holder.appendChild(el);
    allEls.push(el);
    if (el.name && !holders[el.name]) holders[el.name] = holder;
    if (el.id) holders['#' + el.id] = holder;
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
  if(setup) setup(win, doc);
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

// A REDCap 17.x checkbox OPTION: a hidden mirror named __chk__<field>_RC_<code>
// (value = code when checked, "" when not; type=hidden so .checked is useless)
// plus the visible clickable <input type=checkbox> id="id-__chk__…" name
// "__chkn__<field>", both in ONE choicevert row (shared groupKey). This is the
// structure that exposed the live bug: reading .checked off the hidden mirror
// always returned unchecked. set(on) mimics REDCap's own click handler.
function r17chk(field, code) {
  const key = 'row:' + field + ':' + code;
  const hidden = makeEl('input');
  hidden.name = '__chk__' + field + '_RC_' + code; hidden.type = 'hidden'; hidden.value = ''; hidden.groupKey = key;
  const visible = makeEl('input');
  visible.type = 'checkbox'; visible.name = '__chkn__' + field; visible.id = 'id-__chk__' + field + '_RC_' + code;
  visible.checked = false; visible.groupKey = key;
  return {
    hidden, visible, code,
    set(on) { visible.checked = on; hidden.value = on ? code : ''; visible.fire('click'); },
    rowShown() { return hidden.parentNode.style.display !== 'none'; },
  };
}

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
  // A disabled selected <option> is left OUT of the form submission, so the kept
  // answer must stay enabled: marked stale, still posted.
  check('kept option stays ENABLED so the browser still submits it', kept.disabled === false);
  check('kept option is marked stale', kept.getAttribute('data-uv-stale') === '1');
  check('stale selection flags aria-invalid', method.getAttribute('aria-invalid') === 'true');
  const msg = rMsg(env, 'method');
  check('stale message shown and names the condition', /no longer available/.test(msg.innerHTML) && /legacy/.test(msg.innerHTML));
  ev = submitEv(); env.doc.fire('submit', ev);
  check('stale + hard: save trapped', ev._prevented === true);

  method.value = '2'; method.fire('change');
  check('repick: cleared', msg.style.display === 'none' && method.getAttribute('aria-invalid') === null);
  check('repick: the formerly kept option is now removed', selectValues(method).join(',') === ',1,2');
  check('repick: no orphaned disabled flag', method.children.every((o) => !o.disabled));
  check('repick: no orphaned stale mark', kept.getAttribute('data-uv-stale') === null);
  ev = submitEv(); env.doc.fire('submit', ev);
  check('repick: save allowed', ev._prevented === false);
}

// ---- 1b) text in the "when" ignores letter case unless caseSensitive:true ---
{
  const run = (extra) => {
    const method = makeEl('select'); method.name = 'method';
    ['', '1', '2', '9'].forEach((v) => method.appendChild(option(v)));
    method.value = '';
    const legacy = makeEl('input'); legacy.name = 'legacy'; legacy.value = 'NO';
    boot([method, legacy], { singleFields: [], pooledFields: [],
      rules: [Object.assign({ type: 'choices', fields: ['method'], choicesHide: ['9'],
                              choicesAll: ['1', '2', '9'], when: "[legacy]='no'", blockSave: 'hard' }, extra)] });
    return selectValues(method).join(',');
  };
  check('choices, case default: "NO" matches when [legacy]=no (option hidden)', run({}) === ',1,2');
  check('choices, caseSensitive:true: "NO" does not match (all options kept)', run({ caseSensitive: true }) === ',1,2,9');
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
  check('multi-field: field 2 filtered (own stale 9 kept, marked, still submittable)',
    selectValues(s2).join(',') === ',1,2,9' && s2.children.find((o) => o.value === '9').disabled === false
    && s2.children.find((o) => o.value === '9').getAttribute('data-uv-stale') === '1');
  check('multi-field: only the stale field flags', s1.getAttribute('aria-invalid') === null
    && s2.getAttribute('aria-invalid') === 'true');
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('multi-field: the stale field blocks the shared save', ev._prevented === true);
}

// ---- 11) checkbox show-list on REDCap 17.x option markup --------------------
{
  const pilot = makeEl('input'); pilot.name = 'pilot'; pilot.value = '1';
  const reach = ['1', '2', '9'].map((v) => r17chk('reach', v));
  const flat = reach.reduce((a, o) => a.concat([o.hidden, o.visible]), []);
  boot([pilot, ...flat], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'choices', fields: ['reach'], choicesShow: ['1', '2'],
              choicesAll: ['1', '2', '9'], when: "[pilot]='1'", blockSave: 'hard' }],
  });
  check('R17 checkbox show-list: whitelisted 1,2 visible', reach[0].rowShown() && reach[1].rowShown());
  check('R17 checkbox show-list: complement 9 hidden', !reach[2].rowShown());
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

// ---- 14) checkbox REF gate + checkbox target stale (LIVE-FOUND, REDCap 17) --
// The filter on `reach` is gated on a CHECKBOX ref [pilot(1)]='1'. Before the
// fix the engine read the hidden mirror's .checked (always false on 17.x) so the
// gate never fired live (pid 149). Now it reads the mirror VALUE / visible box.
// Also verifies a checked-but-hidden checkbox code is detected as stale.
{
  const pilot = r17chk('pilot', '1');
  const reach = ['1', '2', '9'].map((v) => r17chk('reach', v));
  const flat = [pilot.hidden, pilot.visible].concat(
    reach.reduce((a, o) => a.concat([o.hidden, o.visible]), []));
  const env = boot(flat, {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'choices', fields: ['reach'], choicesShow: ['1', '2'],
              choicesAll: ['1', '2', '9'], when: "[pilot(1)]='1'", blockSave: 'hard',
              message: 'Only Radio and TV during the pilot.' }],
  });
  check('checkbox-ref gate off: all reach rows shown',
    reach[0].rowShown() && reach[1].rowShown() && reach[2].rowShown());
  pilot.set(true);   // tick the VISIBLE pilot box -> gate must read it as checked
  check('checkbox-ref gate reads a checked box live -> filter activates', !reach[2].rowShown());
  check('checkbox-ref gate: whitelisted rows still shown', reach[0].rowShown() && reach[1].rowShown());
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('gate active, nothing stale: save allowed', ev._prevented === false);
  reach[2].set(true);   // check a HIDDEN code -> stale
  check('checked hidden checkbox code stays VISIBLE (never hidden)', reach[2].rowShown());
  const anyMsg = env.allEls.find((e) => e.id && /^uvalidate-msg-/.test(e.id) && /Only Radio and TV/.test(e.innerHTML));
  check('checked hidden checkbox: custom stale message shown', !!anyMsg);
  ev = submitEv(); env.doc.fire('submit', ev);
  check('checked hidden checkbox: hard block traps save', ev._prevented === true);
  reach[2].set(false);
  check('uncheck stale code: row hidden again', !reach[2].rowShown());
  ev = submitEv(); env.doc.fire('submit', ev);
  check('uncheck stale code: save allowed', ev._prevented === false);
}

// Extended unresolved selectors restore choices and never activate the fallback.
{
  const sel=makeEl('select');sel.name='pick';sel.value='';
  ['', '1', '9'].forEach(v=>sel.appendChild(option(v)));
  const key=makeEl('input');key.name='key';key.value='A';
  const tree=['temporal',['cmp','=', ['guard',[['key','A']],['lit','1']],['lit','1']]];
  const env=boot([sel,key],{rules:[{type:'choices',fields:['pick'],branches:[
    {when:"[key]='A'",whenAst:tree,choicesHide:['9'],choicesAll:['1','9'],blockSave:'hard'},
    {when:null,choicesHide:['1'],choicesAll:['1','9'],blockSave:'hard'}]}]});
  check('extended known gate really filters',selectValues(sel).join(',')===',1');
  key.value='B';key.fire('change');
  check('extended unknown gate restores all choices',selectValues(sel).join(',')===',1,9');
  const ev=submitEv();env.doc.fire('submit',ev);
  check('extended unknown choices never block',!ev._prevented);
}

// Dropdown mirrors, optgroups and autocomplete must use the same coded answer.
{
  const mirror = makeEl('input'); mirror.type = 'hidden'; mirror.name = 'site'; mirror.value = '';
  const site = makeEl('select'); site.name = 'site'; site.value = '1BAM';
  site.appendChild(option(''));
  const group = makeEl('optgroup'); site.appendChild(group);
  ['1BAM', '2ADI', '7KET'].forEach(code => group.appendChild(option(code)));
  group.children[2].disabled = true; // another REDCap feature owns this restriction
  const region = makeEl('select'); region.name = 'region'; region.value = '1';
  const env = boot([mirror, site, region], { rules: [{type:'choices', fields:['site'], branches:[
    {when:"[region]='1'", choicesShow:['1BAM'], choicesAll:['1BAM','2ADI','7KET'], blockSave:'hard'},
    {when:"[region]='2'", choicesShow:['2ADI'], choicesAll:['1BAM','2ADI','7KET'], blockSave:'hard'}
  ]}] });
  check('dropdown: prefer select over preceding hidden mirror', site.getAttribute('data-qrid-bound-cf') === '1' && !mirror.getAttribute('data-qrid-bound-cf'));
  check('dropdown: grouped options are filtered', selectValues(group).join(',') === '1BAM');
  region.value='2'; region.fire('change');
  check('dropdown: stale grouped selection is retained', site.value === '1BAM' && selectValues(group).join(',') === '1BAM,2ADI');
  check('dropdown: stale answer read from select, not blank mirror', site.getAttribute('aria-invalid') === 'true');
  let ev=submitEv(); env.doc.fire('submit',ev);
  check('dropdown: stale grouped choice blocks save',ev._prevented);
  site.value='2ADI'; site.fire('change');
  check('dropdown: repick removes stale grouped option',selectValues(group).join(',')==='2ADI');
  region.value=''; region.fire('change');
  check('dropdown: original optgroup order restored',selectValues(group).join(',')==='1BAM,2ADI,7KET');
  check('dropdown: preexisting disabled option stays disabled',group.children[2].disabled);
}
{
  const site=makeEl('select'); site.name='site'; site.value='1BAM';
  ['', '1BAM','2ADI','7KET'].forEach(code=>{ const o=option(code);o.text=code+' Site';site.appendChild(o); });
  const ac=makeEl('input');ac.id='rc-ac-input_site';ac.value='1BAM Site';
  const region=makeEl('select');region.name='region';region.value='1';
  let closeCount=0;
  function jq(el){ return {
    on(events, fn){ events.split(' ').forEach(event=>{const name=event.split('.')[0];(el._jqHandlers||(el._jqHandlers={}))[name]=(el._jqHandlers[name]||[]).concat(fn);});return this; },
    autocomplete(method){if(method==='close')closeCount++;return this;}
  }; }
  jq.fn={on:true,autocomplete:true};
  function trigger(el,name,ui){(el._jqHandlers && el._jqHandlers[name] || []).forEach(fn=>fn({},ui));}
  const env=boot([site,ac,region],{rules:[{type:'choices',fields:['site'],branches:[
    {when:"[region]='1'",choicesShow:['1BAM'],choicesAll:['1BAM','2ADI','7KET'],blockSave:'hard'},
    {when:"[region]='2'",choicesShow:['2ADI'],choicesAll:['1BAM','2ADI','7KET'],blockSave:'hard'}
  ]}]},win=>{win.jQuery=jq;});
  const response={content:[{value:'1BAM',label:'First'},{value:'2ADI',label:'Second'},{value:'7KET Site',label:'7KET Site'}]};
  trigger(ac,'autocompleteresponse',response);
  check('autocomplete: cached suggestions filtered by codes and labels',response.content.length===1 && response.content[0].value==='1BAM');
  region.value='2';trigger(region,'change'); // deliberately no native event
  check('autocomplete: jQuery-only region changes switch branch',selectValues(site).join(',')===',1BAM,2ADI');
  check('autocomplete: old open menu closes after filter change',closeCount>=2);
  const stale={content:[{option:{value:'1BAM'},label:'First'},{option:{value:'2ADI'},label:'Second'}]};
  trigger(ac,'autocompleteresponse',stale);
  check('autocomplete: stale current option is not offered as a fresh suggestion',stale.content.length===1 && stale.content[0].option.value==='2ADI');
  check('autocomplete: invalid state is exposed on the visible textbox',ac.getAttribute('aria-invalid')==='true');
  check('autocomplete: textbox has status relationship',/uvalidate-msg-site-ch/.test(ac.getAttribute('aria-describedby')));
  check('autocomplete: filtering does not overwrite typed/saved text',ac.value==='1BAM Site' && site.value==='1BAM');
  site.value='2ADI';trigger(site,'change');
  check('autocomplete: valid selection clears visible invalid state',ac.getAttribute('aria-invalid')===null);
  let ev=submitEv();env.doc.fire('submit',ev);
  check('autocomplete: valid selection releases the save',!ev._prevented);
}

// Full region/site configuration supplied by the user, on a dropdown.
{
  const fixture=JSON.parse(fs.readFileSync(path.join(__dirname,'choices_region_fixture.json'),'utf8'));
  const all=fixture.regions.flat();
  const site=makeEl('select');site.name='site';site.value='';
  ['',...all].forEach(c=>site.appendChild(option(c)));
  const region=makeEl('select');region.name='region';region.value='';
  const branches=fixture.branches.map(b=>({when:b.when,choicesShow:b.show,choicesAll:all,blockSave:b.blockSave}));
  boot([site,region],{rules:[{type:'choices',fields:['site'],branches}]});
  fixture.regions.forEach((codes,i)=>{
    region.value=String(i+1);region.fire('change');
    check('site dropdown region '+(i+1)+' exactly matches authored codes',selectValues(site).join(',')===['',...codes].join(','));
  });
  region.value='';region.fire('change');
  check('site dropdown no active region restores original code order',selectValues(site).join(',')===['',...all].join(','));
}

// ---- 15) adversarial review 2026-09-20 --------------------------------------
// (a) REDCap 17 checkbox: the message must not live inside an option row the
//     filter can hide, and the verdict must reach the VISIBLE boxes.
{
  const pilot = makeEl('input'); pilot.name = 'pilot'; pilot.value = '0';
  const opts = ['1', '2', '9'].map((code) => r17chk('reach', code));
  const env = boot([pilot, ...opts.flatMap((o) => [o.hidden, o.visible])], {
    singleFields: [], pooledFields: [],
    rules: [{ type: 'choices', fields: ['reach'], choicesHide: ['1'], choicesAll: ['1', '2', '9'],
              when: "[pilot]='1'", blockSave: 'hard', message: 'Channel one is closed.' }],
  });
  opts[2].set(true);                       // an allowed code, so the field has an answer
  pilot.value = '1'; pilot.fire('change'); // code 1 (the FIRST row, the anchor's row) is now hidden
  check('first option row is hidden by the filter', opts[0].rowShown() === false);
  const msg = env.allEls.find((e) => e.id && /^uvalidate-msg-/.test(e.id) && /-ch$/.test(e.id));
  check('checkbox message region is not inside the hidden option row', !!msg && msg.parentNode !== opts[0].hidden.parentNode);
  pilot.value = '0'; pilot.fire('change');
  opts[0].set(true);
  pilot.value = '1'; pilot.fire('change'); // code 1 is checked AND hidden: stale
  check('stale checkbox message is on screen', /Channel one is closed/.test(msg.innerHTML) && msg.style.cssText.indexOf('display:block') >= 0
    && msg.parentNode.style.display !== 'none');
  check('stale verdict reaches the visible boxes', opts[0].visible.getAttribute('aria-invalid') === 'true');
  check('visible boxes are tied to the message region', (opts[0].visible.getAttribute('aria-describedby') || '').indexOf(msg.id) >= 0);
  opts[0].set(false);
  check('released verdict leaves the visible boxes', opts[0].visible.getAttribute('aria-invalid') === null);
}
// (b) the save guard asks for a fresh verdict: a controlling field changed with
//     NO DOM event must neither leave a stale block nor let a stale answer pass.
{
  const legacy = makeEl('input'); legacy.name = 'legacy'; legacy.value = '1';
  const method = makeEl('select'); method.name = 'method'; method.value = '9';
  ['', '1', '2', '9'].forEach((v) => method.appendChild(option(v)));
  const env = boot([legacy, method], { singleFields: [], pooledFields: [],
    rules: [{ type: 'choices', fields: ['method'], choicesHide: ['9'], choicesAll: ['1', '2', '9'],
              when: "[legacy]<>'1'", blockSave: 'hard' }] });
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('guard: nothing stale, save allowed', ev._prevented === false);
  legacy.value = '0';                      // no event fired
  ev = submitEv(); env.doc.fire('submit', ev);
  check('guard: event-less controller change is judged at submit', ev._prevented === true);
  legacy.value = '1';                      // and back, again silently
  ev = submitEv(); env.doc.fire('submit', ev);
  check('guard: event-less release is honoured at submit', ev._prevented === false);
}
// (c) @READONLY radios: the mirror input stays enabled, the options do not.
{
  const country = makeEl('input'); country.name = 'country'; country.value = '1';
  const mirror = makeEl('input'); mirror.name = 'site'; mirror.type = 'hidden'; mirror.value = '201';
  const radios = ['101', '201'].map((v) => { const r = makeEl('input'); r.name = 'site___radio'; r.type = 'radio'; r.value = v; r.disabled = true; return r; });
  const env = boot([country, mirror, ...radios], { singleFields: [], pooledFields: [],
    rules: [{ type: 'choices', fields: ['site'], choicesShow: ['101'], choicesAll: ['101', '201'],
              when: "[country]='1'", blockSave: 'hard' }] });
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('read-only radio options never trap the save', ev._prevented === false);
  check('read-only radio still shows its stale message', env.allEls.some((e) => /no longer available/.test(e.innerHTML || '')));
}
// (d) one change, one check: N managed codes must not mean N re-evaluations.
{
  const region = makeEl('select'); region.name = 'region'; region.value = '1';
  const site = makeEl('select'); site.name = 'site'; site.value = '';
  const all = []; for (let i = 0; i < 400; i++) all.push('c' + i);
  [''].concat(all).forEach((v) => site.appendChild(option(v)));
  let inserts = 0, removes = 0;
  const ins = site.insertBefore, rem = site.removeChild;
  site.insertBefore = function (a, b) { inserts++; return ins.call(this, a, b); };
  site.removeChild = function (a) { removes++; return rem.call(this, a); };
  const branches = [1, 2, 3, 4].map((k) => ({ when: "[region]='" + k + "'", choicesShow: all.filter((c, i) => i % 4 === k - 1), blockSave: 'hard' }));
  boot([region, site], { singleFields: [], pooledFields: [],
    rules: [{ type: 'choices', fields: ['site'], choicesAll: all, branches }] });
  check('rule-level choicesAll reaches every branch', selectValues(site).length === 101);
  inserts = 0; removes = 0;
  region.value = '2'; region.fire('change');
  check('one controller change moves each option at most once', inserts === 100 && removes === 100);
  check('cascade result is exact and ordered', selectValues(site).join(',') === [''].concat(all.filter((c, i) => i % 4 === 1)).join(','));
  region.value = ''; region.fire('change');
  check('whole list restored in original order', selectValues(site).join(',') === [''].concat(all).join(','));
}
// (e) autocomplete: the widget's own code beats an ambiguous label.
{
  const site = makeEl('select'); site.name = 'site'; site.value = '';
  [['', ''], ['1GH', 'General Hospital'], ['2GH', 'General Hospital']].forEach(([code, text]) => { const o = option(code); o.text = text; site.appendChild(o); });
  const ac = makeEl('input'); ac.id = 'rc-ac-input_site'; ac.value = '';
  const region = makeEl('select'); region.name = 'region'; region.value = '1';
  function jq(el) { return {
    on(events, fn) { events.split(' ').forEach((event) => { const name = event.split('.')[0]; (el._jqHandlers || (el._jqHandlers = {}))[name] = (el._jqHandlers[name] || []).concat(fn); }); return this; },
    autocomplete() { return this; } }; }
  jq.fn = { on: true, autocomplete: true };
  boot([site, ac, region], { rules: [{ type: 'choices', fields: ['site'], choicesAll: ['1GH', '2GH'], branches: [
    { when: "[region]='1'", choicesShow: ['1GH'], blockSave: 'hard' },
    { when: "[region]='2'", choicesShow: ['2GH'], blockSave: 'hard' }] }] }, (win) => { win.jQuery = jq; });
  const ui = { content: [{ label: 'General Hospital', value: 'General Hospital', code: '1GH' }, { label: 'General Hospital', value: 'General Hospital', code: '2GH' }] };
  (ac._jqHandlers.autocompleteresponse || []).forEach((fn) => fn({}, ui));
  check('autocomplete keeps the permitted same-label option by its code', ui.content.length === 1 && ui.content[0].code === '1GH');
}

console.log((fail === 0 ? 'OK' : 'FAILED') + ' — choices_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail === 0 ? 0 : 1);
