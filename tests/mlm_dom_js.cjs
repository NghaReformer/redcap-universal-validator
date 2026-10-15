/*
 * mlm_dom_js.cjs — the engine under REDCap Multi-Language Management (MLM).
 *
 * Four defects found live on REDCap 17.0.6 (pid 149, 2026-10-15; see
 * reports/mlm-integration-plan-2026-10-15.md, section 9):
 *
 *   P0-1  A dropdown option hidden by @UVCHOICES kept the language it was
 *         hidden in: MLM rewrites only the [data-mlm-field] elements it can
 *         find, and the engine kept hidden options out of the page.
 *   P0-2  The same from page load: the engine binds before MLM's first pass,
 *         so an option hidden at load was never translated at all.
 *   P0-3  The save dialog named fields by the label read at bind time, so a
 *         French page listed English labels.
 *   P0-4  On a survey with question numbers the dialog named the variable
 *         ("block_hard"): the first .labelrc cell of the row is the empty
 *         number column.
 *
 * MLM is modelled the way MultiLanguageSurvey.js does it: walk the elements
 * attached to the page, and for each [data-mlm-field] set an option's text or
 * a label's content from the language's table. Detached nodes are not
 * reached, which is the whole of P0-1 and P0-2.
 *
 * Run:  node tests/mlm_dom_js.cjs
 */
'use strict';
const path = require('path');
const PAGE_DEFAULTS = require('./page_defaults.json').defaults;

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), id: '', name: '', value: '', innerHTML: '', className: '',
    type: '', checked: false, disabled: false, hidden: false, tabIndex: 0, readOnly: false,
    style: {}, _attrs: {}, children: [], parentNode: null, _handlers: {}, _text: '',
    get textContent() { return this.children.length ? this.children.map((c) => c.textContent).join(' ') : this._text; },
    set textContent(v) { this._text = String(v); this.children = []; },
    get text() { return this.textContent; },
    set text(v) { this.textContent = v; },
    setAttribute(k, v) { this._attrs[k] = String(v); if (k === 'id') this.id = String(v); },
    getAttribute(k) { return (k in this._attrs) ? this._attrs[k] : (k === 'id' ? (this.id || null) : null); },
    removeAttribute(k) { delete this._attrs[k]; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn.call(this, ev || {})); },
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
    closest(sel) {
      let p = this;
      while (p) { if (sel === 'tr' && p.tagName === 'TR') return p; p = p.parentNode; }
      return null;
    },
    querySelectorAll(sel) {
      const out = [];
      if (!/labelrc/.test(sel)) return out;
      walk(this, (e) => { if (e !== this && /\blabelrc\b/.test(e.className)) out.push(e); });
      return out;
    },
    focus() { this._focused = true; },
    get nextSibling() {
      const i = this.parentNode ? this.parentNode.children.indexOf(this) : -1;
      return i >= 0 ? (this.parentNode.children[i + 1] || null) : null;
    },
    get firstChild() { return this.children[0] || null; },
  };
}
function walk(root, fn) { fn(root); for (const c of root.children.slice()) walk(c, fn); }
function el(tag, props, kids) {
  const e = makeEl(tag);
  for (const k of Object.keys(props || {})) {
    if (k === 'attrs') for (const a of Object.keys(props.attrs)) e.setAttribute(a, props.attrs[a]);
    else e[k] = props[k];
  }
  for (const c of kids || []) e.appendChild(c);
  return e;
}
/* A REDCap field row: label cell (<label id="label-f"> around the MLM label
   div) and data cell holding the input. `survey` adds the empty question
   number cell first; `noLabelId` leaves the <label> without its id. */
function row(field, labelText, input, opts) {
  opts = opts || {};
  const mlm = el('div', { attrs: { 'data-mlm-field': field, 'data-mlm-type': 'label' }, textContent: labelText });
  const label = el('label', opts.noLabelId ? {} : { attrs: { id: opts.labelId || ('label-' + field) } }, [mlm]);
  const cells = [];
  if (opts.survey) cells.push(el('td', { className: 'labelrc questionnum col-1' }));
  cells.push(el('td', { className: 'labelrc col-6' }, [label]));
  cells.push(el('td', { className: 'data col-5' }, [input]));
  return el('tr', { attrs: { id: field + '-tr' } }, cells);
}
function option(field, code, text) {
  return el('option', { value: code, textContent: text,
    attrs: code === '' ? {} : { 'data-mlm-field': field, 'data-mlm-type': 'enum', 'data-mlm-value': code } });
}

function boot(rows, config, opts) {
  opts = opts || {};
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  const table = el('table', {}, rows);
  const center = el('div', { attrs: { id: 'center' } }, [el('form', {}, [table])]);
  const body = el('body', {}, opts.noCenter ? [table] : [center]);
  const attached = (f) => { const out = []; walk(body, (e) => { if (f(e)) out.push(e); }); return out; };
  const doc = {
    body: opts.noBody ? null : body, readyState: 'complete', _handlers: {},
    createElement(t) { return makeEl(t); },
    getElementById(id) { return attached((e) => e.id === id)[0] || null; },
    getElementsByName(name) { return attached((e) => e.name === name); },
    querySelector() { return null; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev)); },
  };
  const win = {
    _alerts: [], alert(m) { this._alerts.push(m); }, confirm() { return false; },
    INSPIRE_VALIDATOR_CONFIG: Object.assign({ singleFields: [], pooledFields: [] }, PAGE_DEFAULTS, config),
  };
  global.document = doc; global.window = win;
  global.setInterval = () => 0;
  global.clearInterval = () => {};
  require(enginePath);
  return { doc, win, body, center };
}
/* MLM's render pass: only elements attached to the page are reached. */
function mlmSwitch(env, table) {
  walk(env.body, (e) => {
    const f = e.getAttribute('data-mlm-field');
    if (!f || !table[f]) return;
    const type = e.getAttribute('data-mlm-type');
    if (type === 'label' && table[f].label != null) e.textContent = table[f].label;
    if (type === 'enum') {
      const t = table[f].enum && table[f].enum[e.getAttribute('data-mlm-value')];
      if (t != null) e.textContent = t;
    }
  });
}
function opts(sel) { return sel ? sel.children.filter((c) => c.tagName === 'OPTION') : []; }
function shown(sel) { return opts(sel).map((o) => o.value + ':' + o.text).join('|'); }
function submitEv() {
  return { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
}
function lastAlert(env) { return env.win._alerts[env.win._alerts.length - 1] || ''; }

const SITE_EN = { '1101': 'Douala Central', '1102': 'Douala Coastal', '1201': 'Yaounde North' };
const FR = {
  site: { label: 'FR Site', enum: { '1101': 'FR Douala Central', '1102': 'FR Douala Coastal', '1201': 'FR Yaounde North' } },
  phone: { label: 'FR Phone number' },
  study_id: { label: 'FR Study ID' },
};
const EN = {
  site: { label: 'Site', enum: SITE_EN },
  phone: { label: 'Phone number' },
  study_id: { label: 'Study ID' },
};
function siteRule(extra) {
  return Object.assign({ type: 'choices', fields: ['site'], when: "[region]='101'",
    choicesShow: ['1101', '1102'], choicesAll: ['1101', '1102', '1201'], blockSave: 'hard' }, extra || {});
}
function sitePage(extraConfig, bootOpts) {
  const site = el('select', { name: 'site', value: '' },
    [option('site', '', '')].concat(Object.keys(SITE_EN).map((c) => option('site', c, SITE_EN[c]))));
  const region = el('input', { name: 'region', value: '101' });
  const env = boot([row('site', 'Site', site), row('region', 'Region', region)],
    Object.assign({ rules: [siteRule()] }, extraConfig || {}), bootOpts);
  return { env, site, region };
}

// ---- 1) P0-2 then P0-1: hidden options are translated with the page ----------
{
  const { env, site, region } = sitePage();
  const box = env.doc.getElementById('uvalidate-choice-parking');
  const parking = box && box.children[0];
  check('filter at load: 1201 is out of the select', shown(site) === ':|1101:Douala Central|1102:Douala Coastal');
  check('parking: one hidden box inside #center', !!box && box.parentNode === env.center);
  check('parking: the box is hidden from view and from assistive tech',
    box && box.hidden === true && box.style.display === 'none' && box.getAttribute('aria-hidden') === 'true');
  check('parking: the box is outside every field row', box && box.closest('tr') === null);
  check('parking: the hidden option waits in an unnamed, disabled, unfocusable select',
    !!parking && parking.tagName === 'SELECT' && parking.name === '' && parking.disabled === true
    && parking.tabIndex === -1 && parking.getAttribute('data-uv-parking') === 'site');
  check('parking: it holds exactly the hidden option', parking && opts(parking).map((o) => o.value).join(',') === '1201');

  mlmSwitch(env, FR);   // MLM's first pass, after the engine bound (P0-2)
  check('P0-2: the parked option was translated by the first pass', parking && opts(parking)[0].text === 'FR Yaounde North');
  region.value = '102'; region.fire('change');
  check('P0-2: revealed, it shows in the page language, in its place',
    shown(site) === ':|1101:FR Douala Central|1102:FR Douala Coastal|1201:FR Yaounde North');
  check('reveal: the parking select is empty again', parking && opts(parking).length === 0);

  mlmSwitch(env, FR);   // every option visible and French before it hides
  check('P0-1: all options French while shown',
    shown(site) === ':|1101:FR Douala Central|1102:FR Douala Coastal|1201:FR Yaounde North');
  region.value = '101'; region.fire('change');
  check('P0-1: hidden again while French', shown(site) === ':|1101:FR Douala Central|1102:FR Douala Coastal');
  mlmSwitch(env, EN);   // switch while it is hidden
  region.value = '102'; region.fire('change');
  check('P0-1: switched back while hidden, it returns in English',
    shown(site) === ':|1101:Douala Central|1102:Douala Coastal|1201:Yaounde North');
  check('P0-1: no option was duplicated anywhere',
    opts(site).length === 4 && opts(parking).length === 0 && !!box && box.children.length === 1);
}

// ---- 2) a stale answer stays in the select; the dialog names today's label ----
{
  const { env, site, region } = sitePage();
  region.value = '102'; region.fire('change');
  site.value = '1201'; site.fire('change');
  region.value = '101'; region.fire('change');
  const box = env.doc.getElementById('uvalidate-choice-parking');
  const parking = box ? box.children[0] : null;
  check('stale: the selected option is kept in the select, not parked',
    opts(site).some((o) => o.value === '1201') && opts(parking).length === 0 && site.value === '1201');
  mlmSwitch(env, FR);
  const ev = submitEv(); env.doc.fire('submit', ev);
  check('stale + hard: the save is held', ev._prevented === true);
  check('P0-3 (choices): the dialog names the French label', /FR Site/.test(lastAlert(env)));
}

// ---- 3) P0-3: labels are read when the dialog opens --------------------------
{
  const phone = el('input', { name: 'phone', value: '' });
  const studyId = el('input', { name: 'study_id', value: '0ABC00001X' });   // wrong check character
  studyId.setAttribute('aria-labelledby', 'label-study_id note-study_id');
  const env = boot([row('phone', 'Phone number', phone), row('study_id', 'Study ID', studyId)], {
    rules: [{ type: 'required', fields: ['phone'], blockSave: 'hard' },
            { type: 'single', fields: ['study_id'], algorithm: 'iso7064_mod37_36', blockSave: 'hard' }],
  });
  studyId.fire('change');
  let ev = submitEv(); env.doc.fire('submit', ev);
  check('English page: both fields named by label',
    ev._prevented && /Phone number/.test(lastAlert(env)) && /Study ID/.test(lastAlert(env)));
  mlmSwitch(env, FR);
  ev = submitEv(); env.doc.fire('submit', ev);
  check('P0-3: after the switch, a guard object (required) is named in French', /FR Phone number/.test(lastAlert(env)));
  check('P0-3: after the switch, an input guard (single) is named in French', /FR Study ID/.test(lastAlert(env)));

  // the label element is gone: the label read at bind time still beats the code
  const lab = env.doc.getElementById('label-phone');
  lab.parentNode.removeChild(lab);
  ev = submitEv(); env.doc.fire('submit', ev);
  check('no label element: the bind-time label is used, never the code',
    /Phone number/.test(lastAlert(env)) && !/\bphone\b/.test(lastAlert(env)));
}

// ---- 4) P0-4: survey rows with a question-number cell ------------------------
{
  const a = el('input', { name: 'block_hard', value: '' });
  const b = el('input', { name: 'no_id_label', value: '' });
  const c = el('input', { name: 'by_ref', value: '' });
  c.setAttribute('aria-labelledby', 'custom-label-c');
  const env = boot([
    row('block_hard', 'Damm + HARD block', a, { survey: true }),
    row('no_id_label', 'Second cell label', b, { survey: true, noLabelId: true }),
    row('by_ref', 'Referenced label', c, { survey: true, labelId: 'custom-label-c' }),
  ], { context: 'survey',
       rules: [{ type: 'required', fields: ['block_hard', 'no_id_label', 'by_ref'], blockSave: 'hard' }] });
  const ev = submitEv(); env.doc.fire('submit', ev);
  const msg = lastAlert(env);
  check('P0-4: the save is held', ev._prevented === true);
  check('P0-4: label-<field> names the field, not the empty number cell', /Damm \+ HARD block/.test(msg));
  check('P0-4: with no label id, the first NON-EMPTY label cell is used', /Second cell label/.test(msg));
  check('P0-4: aria-labelledby is followed', /Referenced label/.test(msg));
  check('P0-4: no variable name reaches the respondent', !/block_hard|no_id_label|by_ref/.test(msg));
}

// ---- 5) no place to park: options leave the page as before -------------------
{
  const { env, site, region } = sitePage({}, { noBody: true, noCenter: true });
  check('no host: no parking box', env.doc.getElementById('uvalidate-choice-parking') === null);
  check('no host: the filter still works', shown(site) === ':|1101:Douala Central|1102:Douala Coastal');
  region.value = '102'; region.fire('change');
  check('no host: the option comes back in its place', shown(site) === ':|1101:Douala Central|1102:Douala Coastal|1201:Yaounde North');
}

console.log('mlm_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
