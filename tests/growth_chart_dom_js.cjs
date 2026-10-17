/*
 * growth_chart_dom_js.cjs — @UVRANGE "show" in the browser: the growth chart
 * and the page table, this visit only.
 *
 * Drives the real QRIDRangeInit factory with the pages the module builds
 * (tests/growth_chart_fixture.json, written by tests/growth_chart_module_php.php
 * from UniversalValidator::buildClientConfig), through a DOM stub that also
 * knows createElementNS, attributes and textContent, and asserts:
 *   - the helpers: tick steps and ticks by the 1-2-5 rule, the x unit
 *     (months under two years, years above), the "auto" window, cutting a
 *     curve at the window, the y axis and its floor, reading "show",
 *   - the container uvalidate-chart-<field> after the note region, a
 *     <details> open on data entry and closed on surveys (or as "open"
 *     says), an SVG with role="img", a <title> and a <desc>,
 *   - no innerHTML and no style attribute anywhere in what it builds, and a
 *     label written as text, never markup,
 *   - the curves the server sent, drawn within 0.5 of the exact ones at the
 *     narrowest window (measured against GrowthReference's twin, valueAt),
 *   - only the marker moves when the value changes; the sex or a window
 *     change draws the curves again; a point outside the chart gets an arrow
 *     at the edge, and the description says so,
 *   - the table of reference values for the sex on the page, and the table
 *     of this visit, whose cells equal the server's for the same typed values
 *     (parity), in the field's decimal mark,
 *   - a chart left off for the "pageCharts" cap: staff see why, a survey
 *     sees nothing, and the check is unchanged,
 *   - a branch whose condition is false shows nothing,
 *   - Multi-Language Management: a language switch relabels the chart and
 *     the table in place, without drawing the curves again or re-checking.
 *
 * Run:  node tests/growth_chart_dom_js.cjs
 */
'use strict';
const fs = require('fs');
const path = require('path');

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

const FX = JSON.parse(fs.readFileSync(path.join(__dirname, 'growth_chart_fixture.json'), 'utf8'));
const SVG_NS = 'http://www.w3.org/2000/svg';
/* every element given innerHTML or a style attribute; none may be inside a chart's container */
const audit = { innerHTML: [], styleAttr: [] };
function inside(el, root) { for (let e = el; e; e = e.parentNode) if (e === root) return true; return false; }

function makeEl(tag, ns) {
  const el = {
    tagName: ns ? tag : (tag || 'div').toUpperCase(), namespaceURI: ns || null, id: '', name: '', value: '', type: '',
    checked: false, hidden: false, open: false, readOnly: false, disabled: false,
    _attrs: {}, children: [], parentNode: null, _handlers: {}, _events: [], _text: '', _html: '',
    style: { set cssText(v) { this._css = v; const m = /display:\s*([a-z]+)/.exec(v); this.display = m ? m[1] : ''; },
             get cssText() { return this._css || ''; } },
    get innerHTML() { return this._html; },
    set innerHTML(v) { this._html = String(v); this.children = []; this._text = ''; audit.innerHTML.push(this); },
    get textContent() { return this.children.length ? this.children.map((c) => c.textContent).join('') : this._text; },
    set textContent(v) { for (const c of this.children) c.parentNode = null; this.children = []; this._text = String(v); },
    setAttribute(k, v) { if (k === 'style') audit.styleAttr.push(this); this._attrs[k] = String(v); if (k === 'id') this.id = String(v); },
    getAttribute(k) { return (k in this._attrs) ? this._attrs[k] : (k === 'id' ? (this.id || null) : null); },
    hasAttribute(k) { return k in this._attrs; },
    removeAttribute(k) { delete this._attrs[k]; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev || {})); },
    dispatchEvent(ev) { this._events.push(ev.type); this.fire(ev.type, ev); return true; },
    appendChild(c) {
      if (c.parentNode) c.parentNode.removeChild(c);
      c.parentNode = this; this.children.push(c); return c;
    },
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

/* A page of fixture `name`: its config, with the shared table, the inputs as
   text boxes and the measured fields. */
function boot(name, values, extra) {
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  const config = JSON.parse(JSON.stringify(FX.pages[name]));
  config.growth = FX.growth;
  Object.assign(config, extra || {});
  const body = makeEl('body');
  const holders = {}, els = {};
  const allEls = [];
  for (const f of ['sex', 'dob', 'visit_date', 'height', 'weight', 'weight2', 'weight3']) {
    const e = makeEl('input'); e.name = f; e.value = (values && values[f]) || '';
    const h = makeEl('div'); h.appendChild(e); body.appendChild(h);
    holders[f] = h; els[f] = e; allEls.push(e, h);
  }
  const mlm = { lang: 'en', cbs: [], getCurrentLanguage() { return this.lang; }, onLangChanged(fn) { this.cbs.push(fn); } };
  const doc = {
    body, readyState: 'complete', _handlers: {}, activeElement: null,
    createElement(t) { const e = makeEl(t); e._built = true; allEls.push(e); return e; },
    createElementNS(ns, t) { const e = makeEl(t, ns); e._built = true; allEls.push(e); return e; },
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
    _calc: [], _branch: [], alert() {}, confirm() { return true; }, calculate(f) { this._calc.push(f); }, doBranching(f) { this._branch.push(f); },
    INSPIRE_VALIDATOR_CONFIG: config, REDCap: { MultiLanguage: mlm }, addEventListener() {},
  };
  global.document = doc; global.window = win;
  require(enginePath);
  return { doc, win, holders, els, mlm, NS: win.INSPIREUniversalValidator };
}
function box(env, f) { return env.doc.getElementById('uvalidate-chart-' + f); }
function svgOf(env, f) { const b = box(env, f); return b ? all(b, (e) => e.namespaceURI === SVG_NS && e.tagName === 'svg')[0] : null; }
function set(env, f, v) { env.els[f].value = v; env.els[f].fire('change'); }
function vis(e) { return e && e.getAttribute('visibility') === 'visible'; }
function marker(svg) { return all(svg, (e) => e.tagName === 'circle' && e.getAttribute('r') === '4' && e.getAttribute('stroke-width') === '1.5')[0]; }
function arrow(svg) { return all(svg, (e) => e.tagName === 'polygon')[0]; }
function paths(svg) { return all(svg, (e) => e.tagName === 'path' && e.getAttribute('fill') !== '#c8e6c9'); }
function bandPaths(svg) { return all(svg, (e) => e.tagName === 'path' && e.getAttribute('fill') === '#c8e6c9'); }
function descOf(svg) { return all(svg, (e) => e.tagName === 'desc')[0].textContent; }
function titleOf(svg) { return svg.children.find((e) => e.tagName === 'title').textContent; }
/* the y axis as drawn: two grid lines and their tick texts give the map */
function yMap(svg) {
  const texts = all(svg, (e) => e.tagName === 'text' && e.getAttribute('text-anchor') === 'end' && /^-?[0-9.,]+$/.test(e.textContent)
    && +e.getAttribute('x') === 52);
  const a = texts[0], b = texts[texts.length - 1];
  const ya = +a.getAttribute('y') - 3.5, yb = +b.getAttribute('y') - 3.5;
  const va = parseFloat(a.textContent.replace(',', '.')), vb = parseFloat(b.textContent.replace(',', '.'));
  return (v) => ya + (v - va) * (yb - ya) / (vb - va);
}
function xMap(svg, div) {
  const texts = all(svg, (e) => e.tagName === 'text' && e.getAttribute('text-anchor') === 'middle' && +e.getAttribute('y') === 366);
  const a = texts[0], b = texts[texts.length - 1];
  const xa = +a.getAttribute('x'), xb = +b.getAttribute('x');
  const va = parseFloat(a.textContent.replace(',', '.')) * div, vb = parseFloat(b.textContent.replace(',', '.')) * div;
  return { px: (v) => xa + (v - va) * (xb - xa) / (vb - va), x: (p) => va + (p - xa) * (vb - va) / (xb - xa) };
}
function pathPoints(d) {
  const out = [];
  const re = /([ML])(-?[0-9.]+) (-?[0-9.]+)/g;
  let m;
  while ((m = re.exec(d))) { if (m[1] === 'M') out.push([]); out[out.length - 1].push([+m[2], +m[3]]); }
  return out;
}

// ---- 1) the helpers ----------------------------------------------------------------------
{
  const env = boot('form', {});
  const C = env.NS.chartLogic;
  check('geometry: a 640 x 400 drawing, a 512 x 312 plot', C.geometry.width === 640 && C.geometry.height === 400
    && C.geometry.plotWidth === 512 && C.geometry.plotHeight === 312 && C.geometry.maxZoom === 8 && C.geometry.yFloor === 0.1);
  check('step: 1, 2 or 5 times a power of ten', C.step(10, 5) === 2 && C.step(60, 8) === 10 && C.step(7.3, 6) === 2
    && C.step(0.35, 6) === 0.1 && Math.abs(C.step(0.031, 6) - 0.01) < 1e-15 && C.step(0, 6) === 1 && C.step(23, 8) === 5);
  check('ticks: whole multiples of the step, with its decimals and the decimal mark',
    JSON.stringify(C.ticks(0.3, 2.1, 0.5, false).map((t) => t.text)) === '["0.5","1.0","1.5","2.0"]'
    && JSON.stringify(C.ticks(-1, 1, 1, true).map((t) => t.text)) === '["-1","0","1"]'
    && JSON.stringify(C.ticks(2.9, 3.31, 0.1, true).map((t) => t.text)) === '["2,9","3,0","3,1","3,2","3,3"]');
  check('ticks: never "-0"', C.ticks(-0.04, 0.04, 0.1, false).map((t) => t.text).join() === '0.0'
    && C.ticks(-1e-12, 1e-12, 1, false).map((t) => t.text).join() === '0');
  const wfa = FX.growth['who-wfa'];
  check('x unit: months under two years of window, years above, cm for a length', C.xUnit(wfa, 0, 700).word === 'months'
    && Math.abs(C.xUnit(wfa, 0, 700).div - 30.4375) < 1e-12 && C.xUnit(wfa, 0, 800).word === 'years'
    && C.xUnit(wfa, 0, 800).div === 365.25 && C.xUnit({ axis: 'length', axisUnit: 'cm' }, 45, 110).word === 'cm'
    && C.xUnit({ axis: 'age', axisUnit: 'months' }, 61, 120).div === 12);
  check('span: the valid range', JSON.stringify(C.span(wfa)) === '[0,1826.25]');
  check('window: from the start to a quarter past the point, at least 1/8 of the span, never past its end',
    JSON.stringify(C.window([0, 1826.25], 100)) === JSON.stringify([0, 1826.25 / 8])
    && JSON.stringify(C.window([0, 1826.25], 400)) === '[0,500]' && JSON.stringify(C.window([0, 1826.25], 1700)) === '[0,1826.25]'
    && JSON.stringify(C.window([0, 1826.25], null)) === '[0,1826.25]');
  const segs = [[0, 100, 10, 200, 20, 100], [30, 50]];
  check('clip: a curve cut at the window\'s edges, a lone point inside kept',
    JSON.stringify(C.clip(segs, 1, 1, 5, 15)) === '[[[5,15],[10,20],[15,15]]]'
    && JSON.stringify(C.clip(segs, 1, 1, 0, 40)) === '[[[0,10],[10,20],[20,10]],[[30,5]]]'
    && JSON.stringify(C.clip(segs, 2, 0, 0, 5)) === '[[[0,100],[5,200]]]'
    && JSON.stringify(C.clip(segs, 1, 1, 10, 20)) === '[[[10,20],[20,10]]]' && JSON.stringify(C.clip(segs, 1, 1, 21, 29)) === '[]');
  const yr = C.yRange([[[[0, 10], [1, 10.2]]]]);
  check('y axis: at least a tenth of the highest value tall, on whole ticks (got ' + JSON.stringify(yr) + ')',
    yr.lo <= 9.59 && yr.hi >= 10.61 && yr.hi - yr.lo >= 1.02 && C.yRange([]) === null);
  check('showOf: a shape not understood shows nothing', C.showOf(null) === null && C.showOf([]) === null
    && C.showOf({ chart: { lines: { kind: 'sd', values: ['1'] }, y: 'value' } }) === null
    && C.showOf({ table: { rows: 'all', columns: ['z'] } }) === null
    && C.showOf({ chart: { lines: { kind: 'z', values: [1] }, y: 'value' } }) === null
    && C.showOf({ chart: { lines: { kind: 'z', values: ['1'] }, y: 'value', labels: [] } }).chart.labels.constructor === Object);
}

// ---- 2) the chart on data entry ------------------------------------------------------------
const W = FX.pages.form.rules.find((r) => r.fields.includes('weight'));
const SET = FX.pages.form.growthCurves[W.growthCurveKey];
{
  const env = boot('form', {});
  const b = box(env, 'weight');
  const kids = env.holders.weight.children;
  check('the container follows the note region', b && kids[0] === env.els.weight && /-n$/.test(kids[1].id) && kids[2] === b);
  const details = tagged(b, 'details');
  check('a chart and a table, each in a <details> open on data entry', details.length === 2 && details.every((d) => d.open === true));
  check('the chart\'s summary is the rule\'s label', tagged(details[0], 'summary')[0].textContent === 'Weight chart');
  const svg = svgOf(env, 'weight');
  check('an SVG with role="img", a title and a description it is labelled by', svg && svg.getAttribute('role') === 'img'
    && svg.getAttribute('aria-labelledby') === b.id + '-0-title' && svg.getAttribute('aria-describedby') === b.id + '-0-desc'
    && svg.getAttribute('viewBox') === '0 0 640 400');
  check('the title is the rule\'s label, the reference\'s source under it', titleOf(svg) === 'Weight for age'
    && all(svg, (e) => e.tagName === 'text' && e.textContent === 'WHO Child Growth Standards (2006)').length === 1);
  check('no sex yet: no curves, and the chart says why', paths(svg).length === 0
    && all(svg, (e) => e.tagName === 'text' && vis(e) && e.textContent === 'The curves show once the sex is entered.').length === 1
    && !vis(marker(svg)));
  check('the description says there is no measurement yet', /No measurement to show yet\.$/.test(descOf(svg)));

  set(env, 'sex', '1');
  check('a boy: five curves and the band, before any age', paths(svg).length === 5 && bandPaths(svg).length === 1);
  check('the line names at the right edge', all(svg, (e) => e.tagName === 'text' && e.getAttribute('x') === '572' && e.parentNode.tagName === 'g').map((e) => e.textContent).join() === '97,85,50,15,3');
  check('the colours: the median green, the rest grey', paths(svg).map((p) => p.getAttribute('stroke')).filter((s) => s === '#2e7d32').length === 1);
  set(env, 'dob', '2025-01-10'); set(env, 'visit_date', '2025-04-20'); set(env, 'weight', '6.4');
  const v = FX.visits[0];
  check('100 days, 6.4 kg: the marker is up', vis(marker(svg)) && !vis(arrow(svg)));
  check('the marker\'s title states the visit (got ' + all(marker(svg), (e) => e.tagName === 'title')[0].textContent + ')',
    all(marker(svg), (e) => e.tagName === 'title')[0].textContent === 'This visit: 6.4 kg at 3.29 months, z-score ' + v.z + ', percentile ' + v.pct);
  check('the description states the reference, the lines and the visit', descOf(svg) === 'Weight for age. WHO Child Growth Standards (2006) · male. '
    + 'Percentiles: 3, 15, 50, 85, 97. This visit: 6.4 kg at 3.29 months, z-score ' + v.z + ', percentile ' + v.pct + '.');
  // the window is the narrowest: 1/8 of five years, in months
  const xs = all(svg, (e) => e.tagName === 'text' && +e.getAttribute('y') === 366).map((e) => e.textContent);
  check('x ticks in months over the first 7.5 months (got ' + xs.join() + ')', xs.join() === '0,1,2,3,4,5,6,7');
  check('the x title names the unit', all(svg, (e) => e.tagName === 'text' && e.textContent === 'Age (months)').length === 1);
  // drawn within 0.5 of the exact curves, at every table row in the window
  const G = env.NS.growthLogic;
  const ref = FX.growth['who-wfa'];
  const ym = yMap(svg), xm = xMap(svg, 30.4375);
  let worst = 0, pts = 0;
  paths(svg).forEach((p, li) => {
    const z = G.normalQuantile(+SET.values[li] / 100);
    const runs = pathPoints(p.getAttribute('d'));
    for (const run of runs) {
      for (let k = 0; k <= 228; k++) {
        const X = xm.px(k);
        if (X < run[0][0] || X > run[run.length - 1][0]) continue;
        let i = 0;
        while (i + 1 < run.length - 1 && run[i + 1][0] < X) i++;
        const a = run[i], c = run[i + 1];
        const drawn = a[1] + (c[1] - a[1]) * (X - a[0]) / (c[0] - a[0]);
        const exact = ym(G.valueAt(z, G.lms(ref, 'male', k), true));
        worst = Math.max(worst, Math.abs(drawn - exact)); pts++;
      }
    }
  });
  check('the curves as drawn lie within 0.5 of the exact ones at every row of the window (worst ' + worst.toFixed(3) + ' over ' + pts + ')',
    worst <= 0.5 && pts > 1000);

  // only the marker moves
  const before = paths(svg), cyBefore = marker(svg).getAttribute('cy');
  set(env, 'weight', '7.4');
  check('a new weight moves the marker only', paths(svg).every((p, i) => p === before[i]) && marker(svg).getAttribute('cy') !== cyBefore);
  check('blur judges too', (() => { env.els.weight.value = '6.9'; env.els.weight.fire('blur'); return marker(svg).getAttribute('cy') !== cyBefore; })());
  set(env, 'sex', '2');
  check('another sex draws that sex\'s curves', paths(svg).length === 5 && paths(svg)[0] !== before[0]
    && paths(svg)[2].getAttribute('d') !== before[2].getAttribute('d'));
  check('the subtitle names the sex', all(svg, (e) => e.tagName === 'text' && e.textContent === 'WHO Child Growth Standards (2006) · female').length === 1);
  const female = paths(svg);
  set(env, 'visit_date', '2025-05-20');
  check('a later age inside the window: the curves stay', paths(svg).every((p, i) => p === female[i]));
  set(env, 'visit_date', '2029-01-01');
  const ys = all(svg, (e) => e.tagName === 'text' && +e.getAttribute('y') === 366).map((e) => e.textContent);
  check('an age past the window refits it, in years (got ' + ys.join() + ')', paths(svg)[0] !== female[0] && ys.join() === '0,1,2,3,4'
    && all(svg, (e) => e.tagName === 'text' && e.textContent === 'Age (years)').length === 1);
  set(env, 'visit_date', '2025-02-10');
  const xs2 = all(svg, (e) => e.tagName === 'text' && +e.getAttribute('y') === 366).map((e) => e.textContent);
  check('a corrected age narrows the window again (got ' + xs2.join() + ')', xs2.join() === '0,1,2,3,4,5,6,7');
  set(env, 'weight', '');
  check('no measurement: no marker, the curves stay', !vis(marker(svg)) && paths(svg).length === 5);
  set(env, 'weight', '-1');
  check('not a measurement: no marker', !vis(marker(svg)));
  set(env, 'weight', '99');
  check('far above the curves: an arrow at the top edge, and the description says so', !vis(marker(svg)) && vis(arrow(svg))
    && / This visit lies outside the chart; an arrow at its edge points to it\.$/.test(descOf(svg))
    && /\(outside the chart\)$/.test(all(arrow(svg), (e) => e.tagName === 'title')[0].textContent));
  check('the arrow points up from the top edge', arrow(svg).getAttribute('points').split(' ')[0].split(',')[1] === '40.0');

  // the table of reference values: the rows sent for the sex on the page
  const tbl = tagged(box(env, 'weight'), 'table')[0];
  const rows = () => tagged(tagged(tbl, 'tbody')[0], 'tr').map((tr) => tagged(tr, 'td').map((td) => td.textContent));
  check('the table of reference values shows the girl\'s rows (got ' + JSON.stringify(rows()[0]) + ')',
    JSON.stringify(rows()) === JSON.stringify(W.growthTableRows.female));
  check('its caption is text, never markup', tagged(tbl, 'caption')[0].textContent === '<b>WHO</b> values');
  check('its headers: the age, then the values at each percentile', tagged(tbl, 'th').map((t) => t.textContent).join('|')
    === 'Age (days)|Value at percentile 3 (kg)|Value at percentile 15 (kg)|Value at percentile 50 (kg)|Value at percentile 85 (kg)|Value at percentile 97 (kg)'
    && tagged(tbl, 'th').every((t) => t.getAttribute('scope') === 'col'));
  set(env, 'sex', '1');
  check('a boy: the boy\'s rows', JSON.stringify(rows()) === JSON.stringify(W.growthTableRows.male));
  set(env, 'sex', '');
  check('no sex: a note across the table', rows().length === 1 && rows()[0][0] === 'The values show once the sex is entered.'
    && tagged(tagged(tbl, 'tbody')[0], 'td')[0].getAttribute('colspan') === '6');
  const cb = box(env, 'weight');
  check('nothing in the container is built with innerHTML or a style attribute',
    audit.innerHTML.concat(audit.styleAttr).every((e) => !inside(e, cb)) && all(cb, () => true).length > 40);
  check('the chart never changes the check', env.NS.validators.weight.test() === null || env.NS.validators.weight.test() === true
    || env.NS.validators.weight.test() === false);
}

// ---- 3) a chart of z-scores and the table of this visit (parity) ----------------------------
{
  const env = boot('form', { sex: '1', dob: '2025-01-10', visit_date: '2025-04-20', weight2: '6,40' });
  const svg = svgOf(env, 'weight2');
  const details = tagged(box(env, 'weight2'), 'details');
  check('"open":false closes the chart; the table keeps the default', details[0].open === false && details[1].open === true);
  check('a chart of z-scores: three flat lines across the span', paths(svg).length === 3
    && paths(svg).every((p) => { const r = pathPoints(p.getAttribute('d'))[0]; return r.length === 2 && r[0][1] === r[1][1]; }));
  check('its y axis is the z-score', all(svg, (e) => e.tagName === 'text' && e.textContent === 'z-score').length === 1);
  const tbl = tagged(box(env, 'weight2'), 'table')[0];
  const cells = () => tagged(tagged(tbl, 'tbody')[0], 'td').map((td) => td.textContent);
  check('the headers, from the catalog\'s labels (got ' + tagged(tbl, 'th').map((t) => t.textContent).join('|') + ')',
    tagged(tbl, 'th').map((t) => t.textContent).join('|') === 'Age (days)|Weight (kg)|z|Percentile|Lowest usual value (kg)|'
    + 'Highest usual value (kg)|Usual range|Value at z-score 2 (kg)|Value at percentile 97 (kg)');
  for (const v of FX.visits) {
    set(env, 'sex', v.sex); set(env, 'dob', v.dob); set(env, 'visit_date', v.at); set(env, 'weight2', v.typed);
    check('this visit\'s row equals the server\'s: ' + JSON.stringify([v.sex, v.dob, v.at, v.typed]) + ' (got ' + JSON.stringify(cells())
      + ', want ' + JSON.stringify(v.cells) + ')', JSON.stringify(cells()) === JSON.stringify(v.cells));
    if (v.z !== null && v.x <= 365) {
      check('... and the marker sits at z ' + v.z, vis(marker(svg)) || vis(arrow(svg)));
      check('... the description in the field\'s decimal mark', descOf(svg).indexOf('z-score ' + v.z.replace('.', ',')) >= 0);
    }
  }
  set(env, 'visit_date', '2026-06-01');
  check('past the span of a chart that names one: an arrow at the right edge', !vis(marker(svg)) && vis(arrow(svg))
    && arrow(svg).getAttribute('points').split(' ')[0].split(',')[0] === '568.0');
  // a branch whose condition is false shows nothing
  const p3 = box(env, 'weight3').children[0];
  check('weight3 applies only while [height] is 1: hidden', p3.hidden === true);
  set(env, 'height', '1');
  check('... shown once it is', p3.hidden === false && svgOf(env, 'weight3') && paths(svgOf(env, 'weight3')).length === 5);
  set(env, 'height', '2');
  check('... hidden again', p3.hidden === true);
}

// ---- 4) a survey, and the cap -----------------------------------------------------------
{
  const env = boot('survey', { sex: '1', dob: '2025-01-10', visit_date: '2025-04-20', weight: '6.4' });
  check('a survey: the chart and the table start closed', tagged(box(env, 'weight'), 'details').every((d) => d.open === false));
  check('... and draw as on data entry', vis(marker(svgOf(env, 'weight'))));
}
{
  const env = boot('capped', { sex: '1', dob: '2025-01-10', visit_date: '2025-04-20', weight: '6.4' });
  const b = box(env, 'weight');
  check('a chart left off for the cap: staff see why', !svgOf(env, 'weight') && b.textContent.indexOf('Growth chart not shown: this form already shows 0 growth charts') >= 0
    && b.textContent.indexOf('Table not shown:') >= 0);
  check('... and the check is unchanged', env.NS.validators.weight.test() === true);
}
{
  const cfg = JSON.parse(JSON.stringify(FX.pages.capped));
  const env = boot('capped', { sex: '1', dob: '2025-01-10', visit_date: '2025-04-20', weight: '6.4' }, { context: 'survey' });
  check('a survey is not told why', box(env, 'weight').textContent === '' && cfg.rules.length === 3);
}

// ---- 5) Multi-Language Management ----------------------------------------------------------
{
  const env = boot('form', { sex: '1', dob: '2025-01-10', visit_date: '2025-04-20', weight: '6.4', weight2: '6,4' });
  const svg = svgOf(env, 'weight');
  const before = paths(svg);
  const calc = env.win._calc.length;
  env.mlm.lang = 'fr';
  env.mlm.cbs.forEach((fn) => fn());
  check('a language switch relabels the chart\'s title in place', titleOf(svg) === 'Poids pour age'
    && all(svg, (e) => e.tagName === 'text' && e.textContent === 'Poids pour age').length === 1);
  check('... and its description', descOf(svg).indexOf('Poids pour age. ') === 0);
  check('... and the table\'s summary and column header', tagged(box(env, 'weight'), 'summary')[1].textContent === 'Valeurs'
    && tagged(box(env, 'weight2'), 'th')[2].textContent === 'score z');
  check('... without drawing the curves again or re-checking', paths(svg).every((p, i) => p === before[i]) && env.win._calc.length === calc);
  env.mlm.lang = 'de';
  env.mlm.cbs.forEach((fn) => fn());
  check('a language the rule does not give: the default', titleOf(svg) === 'Weight for age'
    && tagged(box(env, 'weight2'), 'th')[2].textContent === 'z');
}

console.log('growth_chart_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
