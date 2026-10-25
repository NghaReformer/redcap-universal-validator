'use strict';
/*
 * window_glue_dom_js.cjs — @UVWINDOW: the page half of the glue parity suite (WIN-9).
 *
 * tests/window_glue_php.php draws random rules, each with a value saved at one
 * moment, and records the server's verdict (findingsWindow) in
 * tests/window_glue_fixture.json. This suite boots the page rule
 * (QRIDWindowInit) on each case, with the server clock at that moment, and
 * holds the page's verdict (validators.f.verdict()) against the server's:
 *
 *   - A new value: the same verdict. The one allowed difference is the clock
 *     margin on a date and time: the page allows 120 seconds around "now" plus
 *     how far the computer's clock runs ahead or behind (up to 10 minutes),
 *     the server the most the page could have, 12 minutes either side. Such a
 *     case passes when the page, booted again with the computer's clock 10
 *     minutes ahead or behind, gives the server's verdict.
 *   - An unchanged value (saved days before, and the page holds it as saved):
 *     the page leaves out the parts judged against today or now, which the
 *     server judged against the save day. A verdict the page still gives must
 *     be one the server gives, and a rule with no such part gets the same
 *     verdict on both sides.
 *
 * Run:  node tests/window_glue_dom_js.cjs
 */
const path = require('path');
const fs = require('fs');
const DEFAULTS = JSON.parse(fs.readFileSync(path.join(__dirname, 'page_defaults.json'), 'utf8')).defaults;
const FIXTURE = JSON.parse(fs.readFileSync(path.join(__dirname, 'window_glue_fixture.json'), 'utf8'));

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), nodeType: 1, id: '', name: '', value: '', innerHTML: '',
    type: '', checked: false,
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
    focus() {},
    get nextSibling() {
      const i = this.parentNode ? this.parentNode.children.indexOf(this) : -1;
      return i >= 0 ? (this.parentNode.children[i + 1] || null) : null;
    },
    get firstChild() { return this.children[0] || null; },
  };
}
const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
function boot(values, config) {
  delete require.cache[require.resolve(enginePath)];
  const allEls = [];
  const body = makeEl('body');
  for (const name of Object.keys(values)) {
    const el = makeEl('input'); el.name = name; el.value = values[name];
    const holder = makeEl('div');
    holder.appendChild(el);
    body.appendChild(holder);
    allEls.push(el, holder);
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
    INSPIRE_VALIDATOR_CONFIG: Object.assign({ singleFields: [], pooledFields: [] }, DEFAULTS, config),
  };
  global.document = doc; global.window = win;
  global.setInterval = () => 0;
  global.clearInterval = () => {};
  require(enginePath);
  return win.INSPIREUniversalValidator;
}

/* The page's verdict on a case, in the server's words: "none" for a pass or
   nothing to judge. skew: seconds the computer's clock runs ahead (or behind,
   negative) of the server's. */
function pageVerdict(c, skew) {
  const rule = Object.assign({}, c.rule);
  if (rule.windowFrom) rule.windowFromOp = ['ref', 'a', null];
  const values = { f: c.typed };
  if (c.anchorTyped !== null) values.a = c.anchorTyped;
  const clock = Object.assign({}, c.clock);
  if (skew) clock.utc = Date.now() - skew * 1000;
  const NS = boot(values, { clock, windowSaved: c.unchanged ? { f: c.stored.f } : {}, rules: [rule] });
  const r = NS.validators.f.verdict();
  if (r === null) return { said: null, skipped: false };
  const v = r.verdict;
  const said = (v === 'future' || v === 'past' || v === 'window-early' || v === 'window-late') ? v : 'none';
  return { said, skipped: r.skipped };
}
const CLOCK_PARTS = (rule) => !!(rule.windowNotFuture || rule.windowNotPast || rule.windowAnchor === 'today' || rule.windowAnchor === 'now');

check('the fixture holds cases', Array.isArray(FIXTURE.cases) && FIXTURE.cases.length >= 500);
const tally = {};
let shown = 0;
FIXTURE.cases.forEach((c, i) => {
  const server = c.server === 'unconfigurable' ? null : c.server;
  const p = pageVerdict(c, 0);
  let cls;
  if (!c.unchanged) {
    if (p.said === server) cls = 'agree';
    else if (c.rule.dateType !== 'date' && CLOCK_PARTS(c.rule)
             && (pageVerdict(c, 600).said === server || pageVerdict(c, -600).said === server)) cls = 'margin';
    else cls = 'DISAGREE';
  } else if (!CLOCK_PARTS(c.rule)) {
    cls = p.said === server ? 'agree-unchanged' : 'DISAGREE';
  } else if (p.said === null || p.said === 'none') {
    cls = 'page-left-out';
  } else {
    cls = (server !== null && server !== 'none') ? 'agree-unchanged' : 'DISAGREE';
  }
  tally[cls] = (tally[cls] || 0) + 1;
  if (cls === 'DISAGREE' && shown++ < 10) {
    console.error('  case ' + i + ': page ' + p.said + (p.skipped ? ' (parts left out)' : '') + ', server ' + c.server + ' | '
      + JSON.stringify({ rule: c.rule, clock: c.clock, typed: c.typed, anchor: c.anchorTyped, unchanged: c.unchanged }));
  }
});
check('every case: the page agrees with the server, or differs only by the clock margin or a part an unchanged value '
  + 'leaves out (got ' + JSON.stringify(tally) + ')', !tally.DISAGREE);
check('the clock margin is the exception, not the rule (got ' + (tally.margin || 0) + ' of ' + FIXTURE.cases.length + ')',
  (tally.margin || 0) < FIXTURE.cases.length / 10);
check('unchanged values were judged on both sides', (tally['agree-unchanged'] || 0) > 0);

console.log((fail ? 'FAILED' : 'OK') + ' — window_glue_dom_js: ' + n + ' checks, ' + fail + ' failure(s); ' + JSON.stringify(tally));
process.exitCode = fail ? 1 : 0;
