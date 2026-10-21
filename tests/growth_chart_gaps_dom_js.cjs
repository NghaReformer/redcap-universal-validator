/*
 * growth_chart_gaps_dom_js.cjs — @UVRANGE "show" on the paths section K of
 * docs/testbed/RANGE_LIVE_ACCEPTANCE.md does not cover, drawn by the real
 * QRIDRangeInit factory from the pages the module builds for the live
 * fixture docs/testbed/uvrange_chart_gaps_fields.csv
 * (tests/growth_chart_gaps_fixture.json, written by
 * tests/growth_chart_gaps_module_php.php). Every text steps K18 to K36 expect
 * is read here from the page the engine draws:
 *   - labels as language maps: the English page, a switch to French and
 *     back, in place, with no check run again and no curve drawn again,
 *   - a comma-decimal field: the tooltip, the description, the table and
 *     the point read "9,6" as 9.6,
 *   - a chart of z-scores with percentile lines, "span" "auto", closed on
 *     data entry,
 *   - two branches switching reference by age: only the active branch's
 *     chart shows, with its own reference,
 *   - a sex and a date of birth saved on another form: the chart draws that
 *     sex at once and its note names the saved fields; a sex saved blank
 *     leaves a note for staff,
 *   - a repeating instrument on event 380: the sex saved in that event, and
 *     one read from event_1_arm_1.
 *
 * Run:  node tests/growth_chart_gaps_dom_js.cjs
 */
'use strict';
const fs = require('fs');
const path = require('path');

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

const FX = JSON.parse(fs.readFileSync(path.join(__dirname, 'growth_chart_gaps_fixture.json'), 'utf8'));
const SVG_NS = 'http://www.w3.org/2000/svg';
/* the width every element reports: REDCap 17 gives a field's data column
   about 327 pixels (section K, step K14), so the charts are drawn that wide */
const STUB = { width: 327 };

function makeEl(tag, ns) {
  const el = {
    tagName: ns ? tag : (tag || 'div').toUpperCase(), namespaceURI: ns || null, id: '', name: '', value: '', type: '',
    checked: false, hidden: false, open: false, readOnly: false, disabled: false,
    _attrs: {}, children: [], parentNode: null, _handlers: {}, _text: '', _html: '', _htmlWrites: 0,
    style: { set cssText(v) { this._css = v; const m = /display:\s*([a-z]+)/.exec(v); this.display = m ? m[1] : ''; },
             get cssText() { return this._css || ''; } },
    get clientWidth() { return STUB.width; },
    get innerHTML() { return this._html; },
    set innerHTML(v) { this._html = String(v); this.children = []; this._text = ''; this._htmlWrites++; },
    get textContent() { return this.children.length ? this.children.map((c) => c.textContent).join('') : this._text; },
    set textContent(v) { for (const c of this.children) c.parentNode = null; this.children = []; this._text = String(v); },
    setAttribute(k, v) { this._attrs[k] = String(v); if (k === 'id') this.id = String(v); },
    getAttribute(k) { return (k in this._attrs) ? this._attrs[k] : (k === 'id' ? (this.id || null) : null); },
    hasAttribute(k) { return k in this._attrs; },
    removeAttribute(k) { delete this._attrs[k]; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev || {})); },
    dispatchEvent(ev) { this.fire(ev.type, ev); return true; },
    appendChild(c) { if (c.parentNode) c.parentNode.removeChild(c); c.parentNode = this; this.children.push(c); return c; },
    insertBefore(node, ref) {
      if (node.parentNode) node.parentNode.removeChild(node);
      node.parentNode = this;
      const i = ref ? this.children.indexOf(ref) : -1;
      if (i >= 0) this.children.splice(i, 0, node); else this.children.push(node);
      return node;
    },
    removeChild(c) { const i = this.children.indexOf(c); if (i >= 0) this.children.splice(i, 1); c.parentNode = null; return c; },
    closest() { return null; },
    focus() {},
    get nextSibling() {
      const i = this.parentNode ? this.parentNode.children.indexOf(this) : -1;
      return i >= 0 ? (this.parentNode.children[i + 1] || null) : null;
    },
    get firstChild() { return this.children[0] || null; },
  };
  return el;
}
function walk(el, fn) { fn(el); for (const c of el.children) walk(c, fn); }
function all(el, test) { const out = []; walk(el, (e) => { if (test(e)) out.push(e); }); return out; }
function tagged(el, tag) { return all(el, (e) => e.tagName.toLowerCase() === tag); }

/* The fixture's page `name`, its reference rows put back from the shared ones. */
function pageOf(name) {
  const cfg = JSON.parse(JSON.stringify(FX.pages[name]));
  for (const ref of Object.keys(cfg.growth || {})) {
    for (const sex of ['male', 'female']) {
      const s = cfg.growth[ref][sex];
      if (s && s.slice) cfg.growth[ref][sex] = FX.growth[ref][sex].slice(s.slice[0], s.slice[0] + s.slice[1]);
    }
  }
  return cfg;
}
/* Page `name` with text boxes for `inputs` and every field the rules check,
   holding `values`; lang is the Multi-Language Management language. */
function boot(name, inputs, values, lang) {
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  const config = pageOf(name);
  const body = makeEl('body');
  const holders = {}, els = {}, allEls = [];
  const checked = [].concat.apply([], config.rules.map((r) => r.fields || []));
  for (const f of inputs.concat(checked)) {
    if (els[f]) continue;
    const e = makeEl('input'); e.name = f; e.value = (values && values[f]) || '';
    const h = makeEl('div'); h.appendChild(e); body.appendChild(h);
    holders[f] = h; els[f] = e; allEls.push(e, h);
  }
  const mlm = { lang: lang || 'en', cbs: [], getCurrentLanguage() { return this.lang; }, onLangChanged(fn) { this.cbs.push(fn); } };
  const asked = [];
  const doc = {
    body, readyState: 'complete', _handlers: {}, activeElement: null,
    createElement(t) { const e = makeEl(t); allEls.push(e); return e; },
    createElementNS(ns, t) { const e = makeEl(t, ns); allEls.push(e); return e; },
    getElementById(id) { return allEls.find((e) => e.id === id) || null; },
    getElementsByName(nm) { return allEls.filter((e) => e.name === nm); },
    querySelector() { return null; },
    querySelectorAll(sel) {
      const m = /^\[([a-z-]+)\]$/.exec(sel);
      return m ? all(body, (e) => e.hasAttribute && e.hasAttribute(m[1])) : [];
    },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
  };
  const win = {
    alert() {}, confirm() { return true; }, calculate() {}, doBranching() {},
    INSPIRE_VALIDATOR_CONFIG: config, REDCap: { MultiLanguage: mlm }, addEventListener() {},
    fetch() { asked.push([].slice.call(arguments)); return new Promise(() => {}); },
    XMLHttpRequest: function () { asked.push('xhr'); this.open = () => {}; this.send = () => {}; this.setRequestHeader = () => {}; },
  };
  global.document = doc; global.window = win;
  require(enginePath);
  return { doc, win, holders, els, mlm, config, asked, NS: win.INSPIREUniversalValidator };
}
function ruleOf(config, f) { return config.rules.find((r) => r.fields.includes(f)); }
function box(env, f) { return env.doc.getElementById('uvalidate-chart-' + f); }
/* the svg of panel i (one per branch) of field f */
function svgOf(env, f, i) {
  const b = box(env, f);
  if (!b) return null;
  return all(b.children[i || 0], (e) => e.namespaceURI === SVG_NS && e.tagName === 'svg')[0] || null;
}
function set(env, f, v) { env.els[f].value = v; env.els[f].fire('change'); }
function vis(e) { return !!e && e.getAttribute('visibility') === 'visible'; }
function marker(svg) { return all(svg, (e) => e.tagName === 'circle' && e.getAttribute('r') === '4')[0]; }
function tip(svg) { const t = all(marker(svg), (e) => e.tagName === 'title')[0]; return t ? t.textContent : null; }
function paths(svg) { return all(svg, (e) => e.tagName === 'path' && e.getAttribute('fill') !== '#c8e6c9'); }
function descOf(svg) { return all(svg, (e) => e.tagName === 'desc')[0].textContent; }
function titleOf(svg) { return svg.children.find((e) => e.tagName === 'title').textContent; }
function svgTexts(svg) { return all(svg, (e) => e.namespaceURI === SVG_NS && e.tagName === 'text').map((e) => e.textContent); }
function lineNames(svg) {
  return all(svg, (e) => e.tagName === 'text' && e.parentNode && e.parentNode.tagName === 'g' && e.parentNode.getAttribute('font-size') === '10')
    .map((e) => e.textContent);
}
const GEO = { left: 56, top: 20, plotHeight: 312 };
const XT_Y = GEO.top + GEO.plotHeight + 14;
function xTicks(svg) {
  return all(svg, (e) => e.tagName === 'text' && e.getAttribute('text-anchor') === 'middle' && +e.getAttribute('y') === XT_Y).map((e) => e.textContent);
}
function yTicks(svg) {
  return all(svg, (e) => e.tagName === 'text' && e.getAttribute('text-anchor') === 'end' && +e.getAttribute('x') === GEO.left - 4);
}
/* drawing y of value v on the y axis as drawn, from its first and last tick */
function yAt(svg, v) {
  const t = yTicks(svg), a = t[0], b = t[t.length - 1];
  const ya = +a.getAttribute('y') - 3.5, yb = +b.getAttribute('y') - 3.5;
  const va = parseFloat(a.textContent.replace(',', '.')), vb = parseFloat(b.textContent.replace(',', '.'));
  return ya + (v - va) * (yb - ya) / (vb - va);
}
function xTitle(svg) { return all(svg, (e) => e.tagName === 'text' && +e.getAttribute('y') === 372)[0].textContent; }
function yTitle(svg) { return all(svg, (e) => e.tagName === 'text' && /^rotate/.test(e.getAttribute('transform') || ''))[0].textContent; }
/* the chart's heading lines: title, source and sex, legend */
function heading(env, f, i) {
  const head = all(box(env, f).children[i || 0], (e) => e.tagName === 'DIV' && e.getAttribute('aria-hidden') === 'true')[0];
  return head.children.map((c) => c.textContent);
}
function summaries(env, f) { return tagged(box(env, f), 'summary').map((s) => s.textContent); }
function details(env, f) { return tagged(box(env, f), 'details'); }
function table(env, f) {
  const t = tagged(box(env, f), 'table')[0];
  return { caption: tagged(t, 'caption')[0].textContent, head: tagged(t, 'th').map((x) => x.textContent),
           rows: tagged(tagged(t, 'tbody')[0], 'tr').map((tr) => tagged(tr, 'td').map((td) => td.textContent)) };
}
function note(env, f) { return env.holders[f].children[1].innerHTML; }
function leave(env, f) { env.els[f].fire('blur'); }
function plain(html) { return html.replace(/<[^>]+>/g, '').replace(/&#8505; /g, 'ℹ ').replace(/&#9888; /g, '⚠ ').replace(/&#10007; /g, '✗ '); }

const GAPS = ['ucg_sex', 'ucg_dob', 'ucg_visit', 'ucg_age_days'];
const BOY = { ucg_sex: '1', ucg_dob: '10-01-2025', ucg_visit: '2026-01-10' };
/* the server's z-score and percentile for a visit (GrowthReference, in the fixture), in the decimal mark */
function zp(name, comma) {
  const v = FX.visits[name], m = (t) => comma ? t.replace('.', ',') : t;
  return 'z-score ' + m(v.z) + ', percentile ' + m(v.pct);
}
const BOY_TIP = 'This visit: 9.6 kg at 11.99 months, ' + zp('boy365_9.6');

// ---- K18 to K21: labels in two languages -----------------------------------------------------
{
  const env = boot('gaps', GAPS, {});
  check('K18: blank, the chart and the table of ucg_mlm are open with the rule\'s English labels (got ' + JSON.stringify(summaries(env, 'ucg_mlm')) + ')',
    JSON.stringify(summaries(env, 'ucg_mlm')) === '["Growth chart","This visit"]' && details(env, 'ucg_mlm').every((d) => d.open === true));
  const svg = svgOf(env, 'ucg_mlm');
  check('K18: no sex yet (got ' + JSON.stringify(svgTexts(svg).filter((t) => /show once/.test(t))) + ')',
    svgTexts(svg).indexOf('The curves show once the sex is entered.') >= 0);
  for (const f of Object.keys(BOY)) set(env, f, BOY[f]);
  set(env, 'ucg_mlm', '9.6'); leave(env, 'ucg_mlm');
  check('K18: the heading (got ' + JSON.stringify(heading(env, 'ucg_mlm')) + ')',
    JSON.stringify(heading(env, 'ucg_mlm')) === JSON.stringify(['Weight-for-age', 'WHO Child Growth Standards (2006) · boy', '●This visit■Usual range']));
  check('K18: the axes (got ' + JSON.stringify([xTitle(svg), yTitle(svg), xTicks(svg)]) + ')',
    xTitle(svg) === 'Age (months)' && yTitle(svg) === 'Weight (kg)' && xTicks(svg).join() === '0,5,10'
    && svgTexts(svg).indexOf('Percentiles') >= 0 && lineNames(svg).join() === '97,85,50,15,3');
  check('K18: the tooltip (got ' + tip(svg) + ')', tip(svg) === BOY_TIP);
  const t = table(env, 'ucg_mlm');
  check('K18: the table (got ' + JSON.stringify(t) + ')', JSON.stringify(t) === JSON.stringify({ caption: 'Weight-for-age',
    head: ['Age (days)', 'Weight (kg)', 'Z-score', 'Percentile'], rows: [['365', '9.6', '-0.04', '48.3']] }));
  const before = paths(svg), notes = env.holders.ucg_mlm.children[1]._htmlWrites;
  env.mlm.lang = 'fr';
  env.mlm.cbs.forEach((fn) => fn());
  check('K19: French: the summaries (got ' + JSON.stringify(summaries(env, 'ucg_mlm')) + ')',
    JSON.stringify(summaries(env, 'ucg_mlm')) === '["Courbe de croissance","Cette visite"]');
  check('K19: French: the heading (got ' + JSON.stringify(heading(env, 'ucg_mlm')) + ')',
    JSON.stringify(heading(env, 'ucg_mlm')) === JSON.stringify(['Poids pour l\'âge', 'WHO Child Growth Standards (2006) · garçon', '●Cette visite■Zone habituelle']));
  check('K19: French: the axes (got ' + JSON.stringify([xTitle(svg), yTitle(svg)]) + ')', xTitle(svg) === 'Âge (mois)' && yTitle(svg) === 'Poids (kg)'
    && svgTexts(svg).indexOf('Centiles') >= 0);
  check('K19: French: the tooltip, the module\'s own words still English (got ' + tip(svg) + ')',
    tip(svg) === 'Cette visite: 9.6 kg at 11.99 mois, ' + zp('boy365_9.6'));
  check('K19: French: the description (got ' + descOf(svg) + ')', descOf(svg) === 'Poids pour l\'âge. WHO Child Growth Standards (2006) · garçon. '
    + 'Centiles: 3, 15, 50, 85, 97. Cette visite: 9.6 kg at 11.99 mois, ' + zp('boy365_9.6') + '.' && titleOf(svg) === 'Poids pour l\'âge');
  const tf = table(env, 'ucg_mlm');
  check('K19: French: the table (got ' + JSON.stringify(tf) + ')', JSON.stringify(tf) === JSON.stringify({ caption: 'Poids pour l\'âge',
    head: ['Âge (jours)', 'Poids (kg)', 'Score z', 'Centile'], rows: [['365', '9.6', '-0.04', '48.3']] }));
  check('K19: the switch drew no curve again, wrote no note and asked nothing', paths(svg).every((p, i) => p === before[i])
    && env.holders.ucg_mlm.children[1]._htmlWrites === notes && env.asked.length === 0);
  set(env, 'ucg_visit', '2027-07-10');
  check('K20: in French a later visit counts in "ans" (got ' + JSON.stringify([xTitle(svg), tip(svg)]) + ')', xTitle(svg) === 'Âge (ans)'
    && tip(svg) === 'Cette visite: 9.6 kg at 2.49 ans, ' + zp('boy912_9.6'));
  set(env, 'ucg_visit', '2026-01-10');
  set(env, 'ucg_sex', '2');
  check('K20: in French a girl is "fille" (got ' + JSON.stringify(heading(env, 'ucg_mlm')[1]) + ')',
    heading(env, 'ucg_mlm')[1] === 'WHO Child Growth Standards (2006) · fille');
  set(env, 'ucg_sex', '1');
  env.mlm.lang = 'en';
  env.mlm.cbs.forEach((fn) => fn());
  check('K20: back to English', JSON.stringify(heading(env, 'ucg_mlm')) === JSON.stringify(['Weight-for-age', 'WHO Child Growth Standards (2006) · boy', '●This visit■Usual range'])
    && tip(svg) === BOY_TIP && table(env, 'ucg_mlm').head.join('|') === 'Age (days)|Weight (kg)|Z-score|Percentile');
  // the other charts on the form keep the module's own words in French
  env.mlm.lang = 'fr';
  env.mlm.cbs.forEach((fn) => fn());
  set(env, 'ucg_comma', '9,6'); leave(env, 'ucg_comma');
  check('K19: a chart without language maps keeps its English labels in French (got ' + JSON.stringify(summaries(env, 'ucg_comma')) + ')',
    JSON.stringify(summaries(env, 'ucg_comma')) === '["Growth chart","This visit"]'
    && heading(env, 'ucg_comma')[0] === 'Weight-for-age, WHO 2006 (birth to 5 years)');
}

{
  // a page opened with Multi-Language Management already on French
  const env = boot('gaps', GAPS, Object.assign({ ucg_mlm: '9.6' }, BOY), 'fr');
  const svg = svgOf(env, 'ucg_mlm');
  check('K21: opened in French, the chart is French from the start (got ' + JSON.stringify([summaries(env, 'ucg_mlm'), heading(env, 'ucg_mlm'), xTitle(svg), tip(svg)]) + ')',
    JSON.stringify(summaries(env, 'ucg_mlm')) === '["Courbe de croissance","Cette visite"]'
    && heading(env, 'ucg_mlm')[1] === 'WHO Child Growth Standards (2006) · garçon' && xTitle(svg) === 'Âge (mois)'
    && tip(svg) === 'Cette visite: 9.6 kg at 11.99 mois, ' + zp('boy365_9.6'));
}

{
  // K28: the form on event 380, where the girl's inputs are saved
  const env = boot('gaps', GAPS, { ucg_sex: '2', ucg_dob: '10-01-2025', ucg_visit: '2026-01-10' });
  set(env, 'ucg_mlm', '9.6'); leave(env, 'ucg_mlm');
  const svg = svgOf(env, 'ucg_mlm');
  check('K28: a girl: "girl" and her tooltip (got ' + JSON.stringify([heading(env, 'ucg_mlm')[1], tip(svg)]) + ')',
    heading(env, 'ucg_mlm')[1] === 'WHO Child Growth Standards (2006) · girl' && tip(svg) === 'This visit: 9.6 kg at 11.99 months, ' + zp('girl365_9.6'));
}

// ---- K22, K23: two branches switching reference by age -----------------------------------
{
  const env = boot('gaps', GAPS, { ucg_sex: '1' });
  const b = box(env, 'ucg_branch');
  check('K22: two panels, both hidden while the age is blank', b && b.children.length === 2 && b.children.every((h) => h.hidden === true));
  set(env, 'ucg_age_days', '365');
  set(env, 'ucg_branch', '9.6'); leave(env, 'ucg_branch');
  const s0 = svgOf(env, 'ucg_branch', 0);
  check('K22: 365 days: the WHO 2006 chart only (got ' + JSON.stringify([b.children.map((h) => h.hidden), s0 && heading(env, 'ucg_branch', 0)]) + ')',
    b.children[0].hidden === false && b.children[1].hidden === true
    && JSON.stringify(heading(env, 'ucg_branch', 0)) === JSON.stringify(['Weight-for-age, WHO 2006 (birth to 5 years)', 'WHO Child Growth Standards (2006) · male', '●This visit■Usual range']));
  check('K22: its lines and tooltip (got ' + JSON.stringify([lineNames(s0), xTicks(s0), tip(s0)]) + ')', lineNames(s0).join() === '5,3,0,-3,-6'
    && xTicks(s0).join() === '0,5,10' && tip(s0) === BOY_TIP && note(env, 'ucg_branch') === '');
  set(env, 'ucg_age_days', '2000');
  set(env, 'ucg_branch', '18.0'); leave(env, 'ucg_branch');
  const s1 = svgOf(env, 'ucg_branch', 1);
  check('K23: 2000 days: the WHO 2007 chart only (got ' + JSON.stringify([b.children.map((h) => h.hidden), s1 && heading(env, 'ucg_branch', 1)]) + ')',
    b.children[0].hidden === true && b.children[1].hidden === false
    && JSON.stringify(heading(env, 'ucg_branch', 1)) === JSON.stringify(['Weight-for-age, WHO 2007 (5 to 10 years)', 'WHO Growth Reference for 5 to 19 years (2007) · male', '●This visit■Usual range']));
  check('K23: its axis and tooltip (got ' + JSON.stringify([xTitle(s1), xTicks(s1), tip(s1), plain(note(env, 'ucg_branch'))]) + ')',
    xTitle(s1) === 'Age (months)' && xTicks(s1).join() === '60,62,64,66'
    && tip(s1) === 'This visit: 18.0 kg at 65.71 months, ' + zp('boy2000d_18.0') && note(env, 'ucg_branch') === '');
  set(env, 'ucg_age_days', '1826');
  check('K23: 1826 days: WHO 2006 again (got ' + JSON.stringify([b.children.map((h) => h.hidden), tip(s0)]) + ')',
    b.children[0].hidden === false && b.children[1].hidden === true && tip(s0) === 'This visit: 18.0 kg at 5 years, ' + zp('boy1826d_18.0')
    && xTitle(s0) === 'Age (years)' && xTicks(s0).join() === '0,2,4');
  set(env, 'ucg_age_days', '1827');
  check('K23: 1827 days: WHO 2007 (got ' + tip(s1) + ')', b.children[0].hidden === true && b.children[1].hidden === false
    && tip(s1) === 'This visit: 18.0 kg at 60.02 months, ' + zp('boy1827d_18.0'));
}

// ---- K24, K25: comma decimals --------------------------------------------------------------------
{
  const env = boot('gaps', GAPS, BOY);
  set(env, 'ucg_comma', '9,6'); leave(env, 'ucg_comma');
  const svg = svgOf(env, 'ucg_comma');
  check('K24: the tooltip in the field\'s decimal mark (got ' + tip(svg) + ')', tip(svg) === 'This visit: 9,6 kg at 11,99 months, ' + zp('boy365_9.6', true));
  check('K24: the table (got ' + JSON.stringify(table(env, 'ucg_comma')) + ')', JSON.stringify(table(env, 'ucg_comma').rows) === '[["365","9,6","-0,04","48,3"]]'
    && table(env, 'ucg_comma').head.join('|') === 'Age (days)|Weight (kg)|Z-score|Percentile'
    && table(env, 'ucg_comma').caption === 'Weight-for-age, WHO 2006 (birth to 5 years)');
  check('K24: the point sits at 9.6 kg', vis(marker(svg)) && Math.abs(+marker(svg).getAttribute('cy') - yAt(svg, 9.6)) < 0.06);
  check('K24: no note (got ' + JSON.stringify(note(env, 'ucg_comma')) + ')', note(env, 'ucg_comma') === '');
  set(env, 'ucg_comma', '7,0'); leave(env, 'ucg_comma');
  check('K24: 7,0: amber, the tooltip and the table follow (got ' + JSON.stringify([plain(note(env, 'ucg_comma')), tip(svg), table(env, 'ucg_comma').rows]) + ')',
    plain(note(env, 'ucg_comma')) === '⚠ This value is lower than usual (z-score ' + FX.visits['boy365_7,0'].z + ' on Weight-for-age, WHO 2006 (birth to 5 years), in kg; expected z-score -2 to 2).'
    && tip(svg) === 'This visit: 7,0 kg at 11,99 months, ' + zp('boy365_7,0', true)
    && JSON.stringify(table(env, 'ucg_comma').rows) === JSON.stringify([['365', '7,0', FX.visits['boy365_7,0'].z.replace('.', ','), FX.visits['boy365_7,0'].pct.replace('.', ',')]])
    && Math.abs(+marker(svg).getAttribute('cy') - yAt(svg, 7)) < 0.06);
}

{
  // a point typed on the comma field, if REDCap lets it through: read as 9.6, echoed as typed
  const env = boot('gaps', GAPS, BOY);
  set(env, 'ucg_comma', '9.6'); leave(env, 'ucg_comma');
  const svg = svgOf(env, 'ucg_comma');
  check('K25: "9.6" with a point on the comma field (got ' + JSON.stringify([tip(svg), table(env, 'ucg_comma').rows, note(env, 'ucg_comma')]) + ')',
    tip(svg) === 'This visit: 9.6 kg at 11,99 months, ' + zp('boy365_9.6', true)
    && JSON.stringify(table(env, 'ucg_comma').rows) === '[["365","9.6","-0,04","48,3"]]' && note(env, 'ucg_comma') === ''
    && Math.abs(+marker(svg).getAttribute('cy') - yAt(svg, 9.6)) < 0.06);
}

// ---- K26: a chart of z-scores with percentile lines, "span" "auto", closed ----------------------
{
  const env = boot('gaps', GAPS, BOY);
  check('K26: the box starts closed on data entry', details(env, 'ucg_zpct').length === 1 && details(env, 'ucg_zpct')[0].open === false);
  set(env, 'ucg_zpct', '9.6'); leave(env, 'ucg_zpct');
  const svg = svgOf(env, 'ucg_zpct');
  const ys = yTicks(svg).map((e) => e.textContent);
  check('K26: five flat lines named by their percentile, the median green (got ' + JSON.stringify([lineNames(svg), paths(svg).map((p) => p.getAttribute('stroke'))]) + ')',
    lineNames(svg).join() === '97,85,50,15,3' && paths(svg).length === 5
    && paths(svg).map((p) => p.getAttribute('stroke')).join() === '#607d8b,#607d8b,#2e7d32,#607d8b,#607d8b');
  check('K26: a z-score axis from -4 to 4, "Percentiles" over the names (got ' + JSON.stringify([yTitle(svg), ys]) + ')',
    yTitle(svg) === 'z-score' && ys[0] === '-4' && ys[ys.length - 1] === '4' && svgTexts(svg).indexOf('Percentiles') >= 0);
  check('K26: the 3rd and 97th percentile lines lie at z -1.88 and 1.88', (() => {
    const p = paths(svg), y3 = +/ (-?[0-9.]+)L/.exec(p[0].getAttribute('d'))[1], y97 = +/ (-?[0-9.]+)L/.exec(p[4].getAttribute('d'))[1];
    return Math.abs(y3 - yAt(svg, -1.8808)) < 0.11 && Math.abs(y97 - yAt(svg, 1.8808)) < 0.11;
  })());
  check('K26: "span" "auto": the window of the default (got ' + JSON.stringify(xTicks(svg)) + ')', xTicks(svg).join() === '0,5,10'
    && xTitle(svg) === 'Age (months)');
  check('K26: the point at its z-score, and the tooltip (got ' + tip(svg) + ')', vis(marker(svg))
    && Math.abs(+marker(svg).getAttribute('cy') - yAt(svg, -0.04)) < 0.06 && tip(svg) === BOY_TIP);
}

// ---- K29 to K31: the sex and the date of birth saved on uv_chart_gaps --------------------------
{
  const env = boot('saved', ['ucs_visit'], { ucs_visit: '2026-01-10' });
  const svg = svgOf(env, 'ucs_weight');
  check('K29: the boy\'s curves at once, the heading says male (got ' + JSON.stringify(heading(env, 'ucs_weight')) + ')',
    JSON.stringify(heading(env, 'ucs_weight')) === JSON.stringify(['Weight-for-age, WHO 2006 (birth to 5 years)', 'WHO Child Growth Standards (2006) · male', '●This visit■Usual range'])
    && paths(svg).length === 5 && descOf(svg).endsWith(' No measurement to show yet.'));
  const t = table(env, 'ucs_weight');
  check('K29: the reference values for the boy (got ' + JSON.stringify(t) + ')', t.rows.length === 5
    && JSON.stringify(summaries(env, 'ucs_weight')) === '["Growth chart","Reference values"]'
    && JSON.stringify(t.rows[1]) === '["365.25","7.8","9.6","11.8"]'
    && t.head.join('|') === 'Age (days)|Value at percentile 3 (kg)|Value at percentile 50 (kg)|Value at percentile 97 (kg)');
  set(env, 'ucs_weight', '9.6'); leave(env, 'ucs_weight');
  check('K30: 9.6: the tooltip, no note (got ' + JSON.stringify([tip(svg), note(env, 'ucs_weight')]) + ')', tip(svg) === BOY_TIP && note(env, 'ucs_weight') === '');
  set(env, 'ucs_weight', '7.0'); leave(env, 'ucs_weight');
  check('K30: 7.0: amber, naming the saved fields (got ' + plain(note(env, 'ucs_weight')) + ')', plain(note(env, 'ucs_weight'))
    === '⚠ This value is lower than usual (z-score ' + FX.visits['boy365_7.0'].z + ' on Weight-for-age, WHO 2006 (birth to 5 years), in kg; expected z-score -2 to 2). '
    + '(worked out with ucg_sex, ucg_dob, read when this page was opened — reload if it has changed since. This check does not block saving; it is re-checked after the save.)');
  set(env, 'ucs_visit', '2027-07-10');
  check('K30: another visit date moves the window, the sex stays (got ' + JSON.stringify([xTitle(svg), heading(env, 'ucs_weight')[1]]) + ')',
    xTitle(svg) === 'Age (years)' && heading(env, 'ucs_weight')[1] === 'WHO Child Growth Standards (2006) · male');
  const sa = svgOf(env, 'ucs_all');
  set(env, 'ucs_all', '9.6'); leave(env, 'ucs_all');
  check('K30: ucs_all: every input saved, the point placed at once (got ' + JSON.stringify([tip(sa), lineNames(sa)]) + ')', tip(sa) === BOY_TIP
    && lineNames(sa).join() === '5,2,0,-2,-5');
}
{
  const env = boot('savedBlank', ['ucs_visit'], {});
  check('K31: a sex saved blank: staff see why, for the chart and the table (got ' + JSON.stringify(box(env, 'ucs_weight').textContent) + ')',
    box(env, 'ucs_weight').textContent === 'ℹ Growth chart not shown: the sex saved for this record is blank or neither the rule\'s male nor its female code, so there is nothing to draw.'
    + 'ℹ Table not shown: the sex saved for this record is blank or neither the rule\'s male nor its female code, so there is nothing to draw.'
    && !svgOf(env, 'ucs_weight') && box(env, 'ucs_all').textContent.indexOf('ℹ Growth chart not shown: the sex saved') === 0);
  set(env, 'ucs_visit', '2026-01-10');
  set(env, 'ucs_weight', '9.6'); leave(env, 'ucs_weight');
  check('K31: the check says the same (got ' + plain(note(env, 'ucs_weight')) + ')', plain(note(env, 'ucs_weight'))
    === 'ℹ Not checked against Weight-for-age, WHO 2006 (birth to 5 years): the sex is blank.');
}

// ---- K33 to K35: a repeating instrument on event 380 ------------------------------------------
{
  const env = boot('repeat', ['ucr_visit'], { ucr_visit: '2026-01-10', ucr_weight: '9.6' });
  const svg = svgOf(env, 'ucr_weight');
  check('K33: the girl\'s curves, read in this event (got ' + JSON.stringify([heading(env, 'ucr_weight'), tip(svg)]) + ')',
    heading(env, 'ucr_weight')[1] === 'WHO Child Growth Standards (2006) · female'
    && tip(svg) === 'This visit: 9.6 kg at 11.99 months, ' + zp('girl365_9.6') && lineNames(svg).join() === '97,50,3');
  check('K33: the table of this visit (got ' + JSON.stringify(table(env, 'ucr_weight').rows) + ')',
    JSON.stringify(table(env, 'ucr_weight').rows) === JSON.stringify([['365', '9.6', FX.visits['girl365_9.6'].z, FX.visits['girl365_9.6'].pct]]));
  set(env, 'ucr_visit', '2026-07-10'); set(env, 'ucr_weight', '8.0'); leave(env, 'ucr_weight');
  check('K34: instance 2\'s values (got ' + JSON.stringify([tip(svg), plain(note(env, 'ucr_weight'))]) + ')',
    tip(svg) === 'This visit: 8.0 kg at 17.94 months, ' + zp('girl546_8.0')
    && plain(note(env, 'ucr_weight')).indexOf('⚠ This value is lower than usual (z-score ' + FX.visits['girl546_8.0'].z + ' on Weight-for-age') === 0);
  const sx = svgOf(env, 'ucr_xev');
  set(env, 'ucr_visit', '2026-01-10'); set(env, 'ucr_xev', '7.0'); leave(env, 'ucr_xev');
  check('K35: ucr_xev: the boy of event_1_arm_1; the note says it does not block (got ' + JSON.stringify([heading(env, 'ucr_xev')[1], tip(sx), plain(note(env, 'ucr_xev'))]) + ')',
    heading(env, 'ucr_xev')[1] === 'WHO Child Growth Standards (2006) · male'
    && tip(sx) === 'This visit: 7.0 kg at 11.99 months, ' + zp('boy365_7.0')
    && plain(note(env, 'ucr_xev')) === '⚠ This value is lower than usual (z-score ' + FX.visits['boy365_7.0'].z + ' on Weight-for-age, WHO 2006 (birth to 5 years), in kg; expected z-score -2 to 2). '
      + '(worked out with saved event/instance values, read when this page was opened — reload if it has changed since. This check does not block saving; it is re-checked after the save.)');
}

console.log('growth_chart_gaps_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
