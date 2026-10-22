/*
 * mlm_messages_dom_js.cjs — rule messages in the language Multi-Language
 * Management has on screen.
 *
 * A tag may give "message" as a map of MLM language id to text. The server
 * keeps "message" the default string and sends the other languages under
 * "i18n" (AnnotationRules::takeTexts). This drives the real engine factories
 * through a DOM stub with a stub window.REDCap.MultiLanguage, and asserts:
 *   - a rule with a plain string message renders byte for byte as before and
 *     registers no language listener (existing projects are untouched),
 *   - the text of the language on screen is shown, matched ignoring case,
 *     then by its primary part ("fr" for "fr-CM"), else the default,
 *   - a language switch swaps the text in place without re-running the check:
 *     no new server request (@UVUNIQUE, @UVEXISTS), and a cleared message is
 *     not brought back,
 *   - MLM that appears only after the page loads is still picked up,
 *   - every mode with a message (@UVASSERT, @UVREQUIRED, @UVUNIQUE,
 *     @UVCHOICES, @UVWINDOW, @UVEXISTS, @UVRANGE, @UVDELTA) shows the
 *     translated text,
 *   - translated text is escaped, and a stale or malformed map falls back to
 *     the default.
 *
 * Run:  node tests/mlm_messages_dom_js.cjs
 */
'use strict';
const path = require('path');

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

function esc(t) { return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

/* A style whose cssText sets display the way a browser parses it: the
   factories show a message by writing cssText. */
function makeStyle() {
  const st = {};
  Object.defineProperty(st, 'cssText', {
    get() { return this._css || ''; },
    set(v) { this._css = v; const m = /display:\s*([a-z-]+)/.exec(v); if (m) this.display = m[1]; },
  });
  return st;
}

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), id: '', name: '', value: '', innerHTML: '',
    type: '', checked: false, disabled: false,
    style: makeStyle(), _attrs: {}, children: [], parentNode: null, readOnly: false,
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

/* Fake timers: debounced re-checks run only when a test flushes them. */
let timers = [], timerSeq = 0;
global.setTimeout = (fn, ms) => { const id = ++timerSeq; timers.push({ id, fn, ms: ms || 0 }); return id; };
global.clearTimeout = (id) => { timers = timers.filter((t) => t.id !== id); };
global.setInterval = () => 0;
global.clearInterval = () => {};

/* MLM stub: the three calls the engine makes. switchTo() runs the callbacks
   the way MLM does after it has rewritten the page. */
function makeMlm(lang) {
  const m = {
    lang, cbs: [],
    getCurrentLanguage() { return this.lang; },
    onLangChanged(cb) { this.cbs.push(cb); },
    isInitialized() { return true; },
  };
  m.switchTo = (l) => { m.lang = l; m.cbs.forEach((cb) => cb()); };
  return m;
}

function makeTransportStub(next) {
  const stub = { calls: [], next };
  stub.obj = {
    ajax(action, payload) {
      stub.calls.push({ action, payload: JSON.parse(JSON.stringify(payload)) });
      const resp = stub.next;
      return { then(res) { res(JSON.parse(JSON.stringify(resp))); } };
    },
  };
  return stub;
}
const JSMO = 'EMStub.UV';

function boot(els, config, opts) {
  opts = opts || {};
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  timers = [];
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
    /* Only the selector QRID_relabelTexts asks. Each marked span becomes an
       element whose textContent setter rewrites that span, escaped, as the
       browser does. */
    querySelectorAll(sel) {
      if (sel !== '[data-uv-text]') return [];
      const out = [];
      for (const el of allEls) {
        const re = /<span data-uv-text="(\d+)">/g;
        let m;
        while ((m = re.exec(el.innerHTML || ''))) {
          const id = m[1];
          out.push({
            getAttribute: (k) => (k === 'data-uv-text' ? id : null),
            set textContent(v) {
              el.innerHTML = el.innerHTML.replace(new RegExp('(<span data-uv-text="' + id + '">)[^<]*(</span>)'),
                (s, a, b) => a + esc(v) + b);
            },
          });
        }
      }
      return out;
    },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev)); },
  };
  const win = {
    _alerts: [], alert(m) { this._alerts.push(m); }, confirm() { return true; },
    _handlers: {},
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev || {})); },
    INSPIRE_VALIDATOR_CONFIG: config,
  };
  if (opts.mlm) win.REDCap = { MultiLanguage: opts.mlm };
  if (opts.transport) win.EMStub = { UV: opts.transport.obj };
  global.document = doc; global.window = win;
  const origError = console.error;
  const consoleErrors = [];
  console.error = (m) => { if (/^FAIL: /.test(String(m))) origError(m); else consoleErrors.push(String(m)); };
  try { require(enginePath); } finally { console.error = origError; }
  return { doc, win, holders, allEls, consoleErrors };
}
/* The message region of one field that is showing something. */
function msgOf(env, field) {
  const all = env.allEls.filter((e) => e.id && e.id.indexOf('uvalidate-msg-') === 0 && e.id.indexOf(field) >= 0
    && !/-cfg$/.test(e.id));
  return all.find((e) => e.innerHTML !== '' && e.style.display !== 'none') || all[0] || null;
}
function shown(msg) { return !!msg && msg.style.display !== 'none' && msg.innerHTML !== ''; }
function textOf(msg) {
  return msg ? msg.innerHTML.replace(/<[^>]*>/g, '').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&#10007;/g, '✗').replace(/&amp;/g, '&') : '';
}
function submitEv() {
  return { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
}
function saved(env) { const ev = submitEv(); env.doc.fire('submit', ev); return !ev._prevented; }
function input(name, value) { const e = makeEl('input'); e.name = name; e.value = value; return e; }

const EN = 'Phone number is needed';
const FR = 'Le numéro de téléphone est requis';
const FRCA = 'Numéro requis (Canada)';
const required = (extra) => Object.assign({ type: 'required', fields: ['phone'], blockSave: 'hard', message: EN,
  i18n: { message: { fr: FR, 'fr-ca': FRCA } } }, extra || {});

// ---- 1) a plain string message is untouched --------------------------------
{
  const mlm = makeMlm('fr');
  const phone = input('phone', '');
  const env = boot([phone], { rules: [{ type: 'required', fields: ['phone'], message: EN, blockSave: 'hard' }] }, { mlm });
  const msg = msgOf(env, 'phone');
  check('string message: exact markup as before maps existed', msg.innerHTML === '&#10007; ' + EN);
  check('string message: no marked span', msg.innerHTML.indexOf('data-uv-text') < 0);
  check('string message: no language listener registered', mlm.cbs.length === 0);
  mlm.switchTo('en');
  check('string message: a switch changes nothing', msg.innerHTML === '&#10007; ' + EN);
  check('string message: still blocks', !saved(env));
}

// ---- 2) the language on screen picks the text; a switch swaps it ---------
{
  const mlm = makeMlm('en');
  const phone = input('phone', '');
  const env = boot([phone], { rules: [required()] }, { mlm });
  const msg = msgOf(env, 'phone');
  check('en (not in the map): the default', textOf(msg) === '✗ ' + EN);
  check('listener registered once', mlm.cbs.length === 1);
  mlm.switchTo('fr');
  check('fr: French', textOf(msg) === '✗ ' + FR);
  mlm.switchTo('FR');
  check('FR: language ids ignore case', textOf(msg) === '✗ ' + FR);
  mlm.switchTo('fr-CM');
  check('fr-CM: falls back to its primary part', textOf(msg) === '✗ ' + FR);
  mlm.switchTo('fr-CA');
  check('fr-CA: its own text', textOf(msg) === '✗ ' + FRCA);
  mlm.switchTo('es');
  check('es (not in the map): the default', textOf(msg) === '✗ ' + EN);
  check('the switch kept the block', !saved(env) && phone.getAttribute('aria-invalid') === 'true');

  phone.value = '677001122'; phone.fire('change');
  check('filled: message cleared', !shown(msg));
  mlm.switchTo('fr');
  check('a switch does not bring a cleared message back', !shown(msg));
  phone.value = ''; phone.fire('change');
  check('blank again: rendered straight in French', textOf(msg) === '✗ ' + FR);
  check('still one listener after re-renders', mlm.cbs.length === 1);
}

// ---- 3) French on screen from the start ------------------------------------
{
  const mlm = makeMlm('fr');
  const env = boot([input('phone', '')], { rules: [required()] }, { mlm });
  check('first render already French', textOf(msgOf(env, 'phone')) === '✗ ' + FR);
}

// ---- 4) no MLM on the page, or MLM that appears after the page loads -------
{
  const env = boot([input('phone', '')], { rules: [required()] });
  const msg = msgOf(env, 'phone');
  check('no MLM: the default', textOf(msg) === '✗ ' + EN);
  check('no MLM: no console error', env.consoleErrors.length === 0);
  check('no MLM: one retry waits for the page load', (env.win._handlers.load || []).length === 1);
  const mlm = makeMlm('fr');
  env.win.REDCap = { MultiLanguage: mlm };
  env.win.fire('load');
  check('MLM after load: hooked', mlm.cbs.length === 1);
  check('MLM after load: relabelled to French', textOf(msg) === '✗ ' + FR);
  mlm.switchTo('en');
  check('MLM after load: switches back', textOf(msg) === '✗ ' + EN);
}
{
  const env = boot([input('phone', '')], { rules: [required()] });
  env.win.fire('load');
  check('no MLM even after load: the default stays, nothing thrown', textOf(msgOf(env, 'phone')) === '✗ ' + EN);
}

// ---- 5) a switch asks the server nothing -----------------------------------
{
  const mlm = makeMlm('en');
  const stub = makeTransportStub({ used: true, record: '7' });
  const env = boot([input('pid', 'AB100')], { jsmoName: JSMO, rules: [{ type: 'unique', fields: ['pid'], blockSave: 'hard',
    message: 'This ID is taken', i18n: { message: { fr: 'Cet identifiant est déjà pris' } } }] }, { mlm, transport: stub });
  const msg = msgOf(env, 'pid');
  check('unique: default shown', textOf(msg) === '✗ This ID is taken');
  const asked = stub.calls.length;
  mlm.switchTo('fr');
  timers.forEach((t) => t.fn()); timers = [];
  check('unique: French after the switch', textOf(msg) === '✗ Cet identifiant est déjà pris');
  check('unique: the switch sent no request', stub.calls.length === asked && asked === 1);
}
{
  const mlm = makeMlm('en');
  const stub = makeTransportStub({ state: 'not-found', record: null });
  const env = boot([input('spec', 'SP-404')], { jsmoName: JSMO, rules: [{ type: 'exists', fields: ['spec'], blockSave: 'hard',
    message: 'Not in the specimen register', i18n: { message: { fr: 'Absent du registre des échantillons' } } }] },
    { mlm, transport: stub });
  const msg = msgOf(env, 'spec');
  check('exists: default shown', textOf(msg) === '✗ Not in the specimen register');
  const asked = stub.calls.length;
  mlm.switchTo('fr');
  timers.forEach((t) => t.fn()); timers = [];
  check('exists: French after the switch', textOf(msg) === '✗ Absent du registre des échantillons');
  check('exists: the switch sent no request', stub.calls.length === asked && asked === 1);
  check('exists: still blocks', !saved(env));
}

// ---- 6) every other mode with a message ------------------------------------
function frAndBack(label, env, field, en, fr, mlm) {
  const msg = msgOf(env, field);
  check(label + ': French shown', shown(msg) && textOf(msg).indexOf(fr) >= 0 && textOf(msg).indexOf(en) < 0);
  mlm.switchTo('en');
  check(label + ': default after switching to en', textOf(msg).indexOf(en) >= 0 && textOf(msg).indexOf(fr) < 0);
}
{
  const mlm = makeMlm('fr');
  const env = boot([input('start', '2026-03-10'), input('end', '2026-03-01')], { rules: [{ type: 'constraint', fields: ['end'],
    assert: '[end]>=[start]', blockSave: 'hard', message: 'End is before start',
    i18n: { message: { fr: 'La fin précède le début' } } }] }, { mlm });
  frAndBack('constraint', env, 'end', 'End is before start', 'La fin précède le début', mlm);
}
{
  const mlm = makeMlm('fr');
  const env = boot([input('sbp', '300')], { rules: [{ type: 'range', fields: ['sbp'], rangeHardLo: '40', rangeHardHi: '250',
    rangeHardBlock: 'confirm', message: 'Check the blood pressure', i18n: { message: { fr: 'Vérifiez la tension' } } }] }, { mlm });
  frAndBack('range', env, 'sbp', 'Check the blood pressure', 'Vérifiez la tension', mlm);
}
{
  const mlm = makeMlm('fr');
  const env = boot([input('w0', '70'), input('weight', '90')], { rules: [{ type: 'delta', fields: ['weight'],
    deltaVs: '[w0]', deltaVsOp: ['ref', 'w0', null], deltaMax: '5', blockSave: 'hard',
    message: 'Re-weigh the patient', i18n: { message: { fr: 'Repesez le patient' } } }] }, { mlm });
  frAndBack('delta', env, 'weight', 'Re-weigh the patient', 'Repesez le patient', mlm);
}
{
  const mlm = makeMlm('fr');
  const env = boot([input('consent_date', '2026-03-01')], { rules: [{ type: 'writeonce', fields: ['consent_date'],
    message: 'Ask the data manager to correct it', i18n: { message: { fr: 'Demandez au gestionnaire de le corriger' } } }] }, { mlm });
  frAndBack('writeonce', env, 'consent_date', 'Ask the data manager to correct it', 'Demandez au gestionnaire de le corriger', mlm);
}
{
  const mlm = makeMlm('fr');
  const env = boot([input('visit_date_bl', '01-03-2026'), input('visit_date_2', '15-03-2026')], { rules: [{ type: 'window',
    fields: ['visit_date_2'], windowFrom: '[visit_date_bl]', windowFromOp: ['ref', 'visit_date_bl', null],
    windowLo: 21, windowHi: 35, windowUnit: 'days', dateType: 'date', dateFormat: 'dmy', fromType: 'date', fromFormat: 'dmy',
    blockSave: 'hard', message: 'Visit 2 is outside its window', i18n: { message: { fr: 'La visite 2 est hors fenêtre' } } }] },
    { mlm });
  frAndBack('window', env, 'visit_date_2', 'Visit 2 is outside its window', 'La visite 2 est hors fenêtre', mlm);
}
{
  const mlm = makeMlm('fr');
  const method = makeEl('select'); method.name = 'method';
  ['', '1', '2', '9'].forEach((v) => { const o = makeEl('option'); o.value = v; method.appendChild(o); });
  method.value = '9';
  const env = boot([method, input('legacy', '0')], { rules: [{ type: 'choices', fields: ['method'], choicesHide: ['9'],
    choicesAll: ['1', '2', '9'], when: "[legacy]='0'", blockSave: 'hard', message: 'Pick another method',
    i18n: { message: { fr: 'Choisissez une autre méthode' } } }] }, { mlm });
  frAndBack('choices', env, 'method', 'Pick another method', 'Choisissez une autre méthode', mlm);
}

// ---- 7) escaping and malformed maps ----------------------------------------
{
  const mlm = makeMlm('fr');
  const env = boot([input('phone', '')], { rules: [required({ i18n: { message: { fr: 'Valeur <b>invalide</b> & "fausse"' } } })] },
    { mlm });
  const msg = msgOf(env, 'phone');
  check('translated text is escaped on render', msg.innerHTML.indexOf('<b>') < 0 && msg.innerHTML.indexOf('&lt;b&gt;') >= 0);
  mlm.switchTo('en'); mlm.switchTo('fr');
  check('translated text is escaped after a switch', msg.innerHTML.indexOf('<b>') < 0 && textOf(msg).indexOf('<b>invalide</b>') >= 0);
}
for (const lang of ['constructor', 'toString', '__proto__', '']) {
  const mlm = makeMlm(lang);
  const env = boot([input('phone', '')], { rules: [required()] }, { mlm });
  check('language "' + lang + '": the default, nothing inherited', textOf(msgOf(env, 'phone')) === '✗ ' + EN);
}
{
  const mlm = makeMlm('fr');
  mlm.getCurrentLanguage = () => { throw new Error('not ready'); };
  const env = boot([input('phone', '')], { rules: [required()] }, { mlm });
  check('getCurrentLanguage throwing: the default', textOf(msgOf(env, 'phone')) === '✗ ' + EN);
}
for (const [label, i18n] of [['i18n a string', 'fr'], ['message map a string', { message: 'Bonjour' }],
  ['i18n null', null], ['message map a list', { message: ['x'] }]]) {
  const mlm = makeMlm('fr');
  const env = boot([input('phone', '')], { rules: [required({ i18n })] }, { mlm });
  const msg = msgOf(env, 'phone');
  check(label + ': the default, no crash', textOf(msg) === '✗ ' + EN);
}

console.log('mlm_messages_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
