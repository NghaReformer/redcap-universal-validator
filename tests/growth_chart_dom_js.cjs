/*
 * growth_chart_dom_js.cjs — @UVRANGE "show" in the browser: the growth chart
 * and the page table, this visit only.
 *
 * Drives the real QRIDRangeInit factory with the pages the module builds
 * (tests/growth_chart_fixture.json, written by tests/growth_chart_module_php.php
 * from UniversalValidator::buildClientConfig), through a DOM stub that also
 * knows createElementNS, attributes and textContent, and asserts:
 *   - the helpers: tick steps and ticks by the 1-2-5 rule (an exact boundary
 *     included), the x unit (months under two years, years above, a
 *     reference's own "chartUnit"), the row the check reads, the "auto"
 *     window, cutting a curve at the window, the y axis and its floor,
 *     reading "show",
 *   - the container uvalidate-chart-<field> after the note region, a
 *     <details> open on data entry and closed on surveys (or as "open"
 *     says), a heading with the title, the source, the sex and the legend,
 *     an SVG with role="img", a <title> and a <desc>, as wide as the cell
 *     allows (320 to 640 units, one to a pixel),
 *   - no innerHTML and no style attribute anywhere in what it builds, and a
 *     label written as text, never markup (setAttribute('style') is what a
 *     strict style-src refuses; a write to el.style is allowed, and a browser
 *     shows it as a style attribute, which this stub does not, so look for
 *     CSP errors in the console, not for style attributes, on a live page),
 *   - the curves the server sent, drawn within 0.5 of the exact ones at the
 *     narrowest window (measured against GrowthReference's twin, valueAt),
 *     in the field's unit when the rule has a "measureScale",
 *   - only the marker moves when the value changes; the sex or a window
 *     change draws the curves again; a point outside the chart gets an arrow
 *     at the edge it passes (top, bottom, left, right), and the description
 *     says so; a chart of z-scores places the marker at the z-score,
 *   - the line names at the right edge, 11 units apart inside the plot,
 *     named by their own value, and the colours by z-score,
 *   - the band between the soft limits or from one to the edge, and the
 *     legend shows it only when it is drawn,
 *   - a chart of the median alone: its y axis spans the reference at z -2
 *     to 2 (the "extent" the server sends), so a point at z 1.5 or -1.5 sits
 *     inside the plot, where rc.2 drew the arrow,
 *   - the table of reference values for the sex on the page, each cell under
 *     its own header, its caption over the rows, and the table of this visit, whose cells equal the
 *     server's for the same typed values (parity), in the field's decimal
 *     mark,
 *   - what each empty state says (no sex, a code that is neither, no age),
 *   - a chart left off for the "pageCharts" cap: staff see why, a survey
 *     sees nothing, and the check is unchanged; an input withheld from the
 *     page, or a rule deferred to the save, shows nothing,
 *   - the check is the same with or without "show", and a chart that fails
 *     hides itself and leaves the table and the check alone,
 *   - two branches of one field, each with its own chart,
 *   - Multi-Language Management: a language switch relabels the chart and
 *     the table in place (the x axis's unit word and the sex too), without
 *     drawing the curves again or re-checking,
 *   - how long the first drawing and a marker move take (printed; bounded
 *     loosely so a slow runner does not fail).
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
/* every element given innerHTML or a style attribute; none may be inside a chart's container.
   Only setAttribute('style') counts: this stub does not reflect writes to el.style (CSSOM) into
   a style attribute, as a browser does, and a strict CSP allows those writes. */
const audit = { innerHTML: [], styleAttr: [] };
function inside(el, root) { for (let e = el; e; e = e.parentNode) if (e === root) return true; return false; }
/* the width every element reports (clientWidth): 0 is "not known", as in a stub */
const STUB = { width: 0 };

function makeEl(tag, ns) {
  const el = {
    tagName: ns ? tag : (tag || 'div').toUpperCase(), namespaceURI: ns || null, id: '', name: '', value: '', type: '',
    checked: false, hidden: false, open: false, readOnly: false, disabled: false,
    _attrs: {}, children: [], parentNode: null, _handlers: {}, _events: [], _text: '', _html: '',
    style: { set cssText(v) { this._css = v; const m = /display:\s*([a-z]+)/.exec(v); this.display = m ? m[1] : ''; },
             get cssText() { return this._css || ''; } },
    get clientWidth() { return STUB.width; },
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
   text boxes and the measured fields. mutate(config) changes the page before
   the engine reads it. */
function boot(name, values, extra, mutate) {
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  const config = JSON.parse(JSON.stringify(FX.pages[name]));
  config.growth = JSON.parse(JSON.stringify(FX.growth));
  Object.assign(config, extra || {});
  if (mutate) mutate(config);
  const body = makeEl('body');
  const holders = {}, els = {};
  const allEls = [];
  for (const f of ['sex', 'dob', 'visit_date', 'height', 'weight', 'weight_b', 'weight2', 'weight3', 'weight4']) {
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
  return { doc, win, holders, els, mlm, config, NS: win.INSPIREUniversalValidator };
}
function ruleOf(config, f) { return config.rules.find((r) => r.fields.includes(f)); }
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
function svgText(svg, t) { return all(svg, (e) => e.namespaceURI === SVG_NS && e.tagName === 'text' && e.textContent === t); }
function htmlText(b, t) { return all(b, (e) => e.namespaceURI === null && e.tagName !== 'SUMMARY' && e.children.length === 0 && e.textContent === t); }
function lineNames(svg) {
  return all(svg, (e) => e.tagName === 'text' && e.parentNode && e.parentNode.tagName === 'g' && e.parentNode.getAttribute('font-size') === '10');
}
/* the legend's two entries in the chart's heading: [this visit, the band] */
function legend(b) {
  const head = all(b, (e) => e.tagName === 'DIV' && e.getAttribute('aria-hidden') === 'true')[0];
  const spans = head ? head.children[2].children : [];
  return spans;
}
const GEO = { left: 56, top: 20, plotHeight: 312 };
const XT_Y = GEO.top + GEO.plotHeight + 14;
/* the y axis as drawn: two grid lines and their tick texts give the map */
function yMap(svg) {
  const texts = all(svg, (e) => e.tagName === 'text' && e.getAttribute('text-anchor') === 'end' && /^-?[0-9.,]+$/.test(e.textContent)
    && +e.getAttribute('x') === GEO.left - 4);
  const a = texts[0], b = texts[texts.length - 1];
  const ya = +a.getAttribute('y') - 3.5, yb = +b.getAttribute('y') - 3.5;
  const va = parseFloat(a.textContent.replace(',', '.')), vb = parseFloat(b.textContent.replace(',', '.'));
  return (v) => ya + (v - va) * (yb - ya) / (vb - va);
}
function xTicks(svg) { return all(svg, (e) => e.tagName === 'text' && e.getAttribute('text-anchor') === 'middle' && +e.getAttribute('y') === XT_Y); }
function xMap(svg, div) {
  const texts = xTicks(svg);
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
/* the worst distance, in drawing units, between the curves drawn and the
   exact ones at every whole row from 0 to rows, for sex, the measurement
   divided by scale */
function worstDrawn(env, svg, set, sex, rows, div, scale) {
  const G = env.NS.growthLogic;
  const ref = env.config.growth['who-wfa'];
  const ym = yMap(svg), xm = xMap(svg, div);
  let worst = 0, pts = 0;
  paths(svg).forEach((p, li) => {
    const z = set.kind === 'percentiles' ? G.normalQuantile(+set.values[li] / 100) : +set.values[li];
    for (const run of pathPoints(p.getAttribute('d'))) {
      for (let k = 0; k <= rows; k++) {
        const X = xm.px(k);
        if (X < run[0][0] || X > run[run.length - 1][0]) continue;
        let i = 0;
        while (i + 1 < run.length - 1 && run[i + 1][0] < X) i++;
        const a = run[i], c = run[i + 1];
        const drawn = a[1] + (c[1] - a[1]) * (X - a[0]) / (c[0] - a[0]);
        const exact = ym(G.valueAt(z, G.lms(ref, sex, k), true) / scale);
        worst = Math.max(worst, Math.abs(drawn - exact)); pts++;
      }
    }
  });
  return { worst, pts };
}
/* a weight's verdict and note, to compare a page with and without "show" */
function verdict(env, f) {
  const t = env.NS.validators[f].test();
  const msg = env.holders[f].children[1];
  return JSON.stringify([t, msg.innerHTML, msg.style.cssText]);
}
const W = FX.pages.form.rules.find((r) => r.fields.includes('weight'));
const SET = FX.pages.form.growthCurves[W.growthCurveKey];
const ROWS = FX.pages.form.growthCurveTables[W.growthTableKey];

// ---- 1) the helpers ----------------------------------------------------------------------
{
  const env = boot('form', {});
  const C = env.NS.chartLogic;
  check('geometry: at most 640 x 380 drawn, at least 320 wide, a 512 x 312 plot at the widest', C.geometry.width === 640
    && C.geometry.minWidth === 320 && C.geometry.height === 380 && C.geometry.left === GEO.left && C.geometry.top === GEO.top
    && C.geometry.right === 72 && C.geometry.plotWidth === 512 && C.geometry.plotHeight === GEO.plotHeight
    && C.geometry.maxZoom === 8 && C.geometry.yFloor === 0.1);
  check('step: 1, 2 or 5 times a power of ten', C.step(10, 5) === 2 && C.step(60, 8) === 10 && C.step(7.3, 6) === 2
    && C.step(0.35, 6) === 0.1 && Math.abs(C.step(0.031, 6) - 0.01) < 1e-15 && C.step(0, 6) === 1 && C.step(23, 8) === 5);
  check('step: a range of whole steps keeps that step (0.3 over 6 is 0.05, not 0.1)', Math.abs(C.step(0.3, 6) - 0.05) < 1e-15
    && Math.abs(C.step(0.6, 6) - 0.1) < 1e-15 && Math.abs(C.step(1.2, 6) - 0.2) < 1e-15 && C.step(6, 6) === 1 && C.step(30, 6) === 5);
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
    && C.xUnit({ axis: 'length', axisUnit: 'cm' }, 45, 110).name === null
    && C.xUnit({ axis: 'age', axisUnit: 'months' }, 61, 120).div === 12 && C.xUnit({ axis: 'age', axisUnit: 'months' }, 0, 12).div === 1);
  check('x unit: a reference\'s "chartUnit" counts in that unit always', C.xUnit({ axis: 'age', axisUnit: 'days', chartUnit: 'weeks' }, 259, 301).div === 7
    && C.xUnit({ axis: 'age', axisUnit: 'days', chartUnit: 'weeks' }, 0, 3000).word === 'weeks'
    && Math.abs(C.xUnit({ axis: 'age', axisUnit: 'months', chartUnit: 'weeks' }, 0, 12).div - 7 / 30.4375) < 1e-15
    && C.xUnit({ axis: 'age', axisUnit: 'days', chartUnit: 'days' }, 0, 30).div === 1
    && C.xUnit({ axis: 'age', axisUnit: 'days', chartUnit: 'bogus' }, 0, 30).word === 'months');
  check('the row the check reads: nearest for "round", at or below for "floor", x itself for "linear"',
    C.readX({ lookup: 'round', scale: 1 }, 100.4) === 100 && C.readX({ lookup: 'round', scale: 1 }, 100.5) === 101
    && C.readX({ lookup: 'floor', scale: 1 }, 100.9) === 100 && C.readX({ lookup: 'floor', scale: 2 }, 3.7) === 3.5
    && C.readX({ lookup: 'linear', scale: 1 }, 100.9) === 100.9);
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
{
  const t0 = Date.now();
  const env = boot('form', {});
  const bootMs = Date.now() - t0;
  const b = box(env, 'weight');
  const kids = env.holders.weight.children;
  check('the container follows the note region', b && kids[0] === env.els.weight && /-n$/.test(kids[1].id) && kids[2] === b);
  const details = tagged(b, 'details');
  check('a chart and a table, each in a <details> open on data entry', details.length === 2 && details.every((d) => d.open === true));
  check('the chart\'s summary is the rule\'s label', tagged(details[0], 'summary')[0].textContent === 'Weight chart');
  const svg = svgOf(env, 'weight');
  check('an SVG with role="img", a title and a description it is labelled by, 640 wide when the cell\'s width is not known',
    svg && svg.getAttribute('role') === 'img'
    && svg.getAttribute('aria-labelledby') === b.id + '-0-title' && svg.getAttribute('aria-describedby') === b.id + '-0-desc'
    && svg.getAttribute('viewBox') === '0 0 640 380' && svg.style.maxWidth === '640px');
  check('the heading: the rule\'s title, the reference\'s source under it, read to a screen reader through the SVG instead',
    titleOf(svg) === 'Weight for age' && htmlText(b, 'Weight for age').length === 1
    && htmlText(b, 'WHO Child Growth Standards (2006)').length === 1
    && all(b, (e) => e.getAttribute && e.getAttribute('aria-hidden') === 'true').length === 1);
  check('the legend: this visit; the band only once it is drawn', legend(b).length === 2 && legend(b)[0].textContent === '●This visit'
    && legend(b)[1].textContent === '■Usual range' && legend(b)[1].hidden === true);
  check('no sex yet: no curves, and the chart says why', paths(svg).length === 0
    && svgText(svg, 'The curves show once the sex is entered.').filter(vis).length === 1 && !vis(marker(svg)));
  check('the description says why this visit has no point (got ' + descOf(svg) + ')', /This visit is not placed: the sex is blank\.$/.test(descOf(svg)));
  set(env, 'sex', '3');
  check('a sex code that is neither: the chart says so (got ' + svgText(svg, '').length + ')',
    svgText(svg, 'No curves to show: the sex code is neither 1 (male) nor 2 (female).').filter(vis).length === 1);

  set(env, 'sex', '1');
  check('a boy: five curves and the band, before any age, and the band in the legend', paths(svg).length === 5 && bandPaths(svg).length === 1
    && legend(b)[1].hidden === false);
  check('the description: no age yet (got ' + descOf(svg) + ')',
    / This visit is not placed: the date of birth or the date of the measurement is blank\.$/.test(descOf(svg)));
  check('the line names at the right edge, by their own value', lineNames(svg).map((e) => e.textContent).join() === '97,85,50,15,3'
    && lineNames(svg).every((e) => e.getAttribute('x') === '572.0'));
  const ny = lineNames(svg).map((e) => +e.getAttribute('y'));
  check('... 11 units apart at least, inside the plot', ny.every((y, i) => !i || y - ny[i - 1] >= 10.99)
    && ny.every((y) => y >= GEO.top + 4 + 3.5 - 0.05 && y <= GEO.top + GEO.plotHeight - 4 + 3.5 + 0.05));
  check('the colours: percentiles 3 to 97 lie inside +-2 SD, so grey, the median green',
    paths(svg).map((p) => p.getAttribute('stroke')).join() === '#607d8b,#607d8b,#2e7d32,#607d8b,#607d8b');
  const t1 = Date.now();
  set(env, 'dob', '2025-01-10'); set(env, 'visit_date', '2025-04-20'); set(env, 'weight', '6.4');
  const v = FX.visits[0];
  check('100 days, 6.4 kg: the marker is up', vis(marker(svg)) && !vis(arrow(svg)));
  check('the marker\'s title states the visit (got ' + all(marker(svg), (e) => e.tagName === 'title')[0].textContent + ')',
    all(marker(svg), (e) => e.tagName === 'title')[0].textContent === 'This visit: 6.4 kg at 3.29 months, z-score ' + v.z + ', percentile ' + v.pct);
  check('the description states the reference, the lines and the visit', descOf(svg) === 'Weight for age. WHO Child Growth Standards (2006) · male. '
    + 'Percentiles: 3, 15, 50, 85, 97. This visit: 6.4 kg at 3.29 months, z-score ' + v.z + ', percentile ' + v.pct + '.');
  check('the heading names the sex', htmlText(b, 'WHO Child Growth Standards (2006) · male').length === 1);
  // the window is the narrowest: 1/8 of five years, in months
  const xs = xTicks(svg).map((e) => e.textContent);
  check('x ticks in months over the first 7.5 months (got ' + xs.join() + ')', xs.join() === '0,1,2,3,4,5,6,7');
  check('the x title names the unit', svgText(svg, 'Age (months)').length === 1);
  const ym0 = yMap(svg), xm0 = xMap(svg, 30.4375);
  check('the marker sits at the age and the measurement', Math.abs(+marker(svg).getAttribute('cx') - xm0.px(100)) < 0.06
    && Math.abs(+marker(svg).getAttribute('cy') - ym0(6.4)) < 0.06);
  // drawn within 0.5 of the exact curves, at every table row in the window
  const acc = worstDrawn(env, svg, SET, 'male', 228, 30.4375, 1);
  check('the curves as drawn lie within 0.5 of the exact ones at every row of the window (worst ' + acc.worst.toFixed(3) + ' over ' + acc.pts + ')',
    acc.worst <= 0.5 && acc.pts > 1000);

  // only the marker moves
  const before = paths(svg), cyBefore = marker(svg).getAttribute('cy');
  const t2 = Date.now();
  for (let i = 0; i < 200; i++) set(env, 'weight', (6 + (i % 20) / 10).toFixed(1));
  const moveMs = (Date.now() - t2) / 200;
  set(env, 'weight', '7.4');
  check('a new weight moves the marker only', paths(svg).every((p, i) => p === before[i]) && marker(svg).getAttribute('cy') !== cyBefore);
  check('blur judges too', (() => { env.els.weight.value = '6.9'; env.els.weight.fire('blur'); return marker(svg).getAttribute('cy') !== cyBefore; })());
  set(env, 'sex', '2');
  check('another sex draws that sex\'s curves', paths(svg).length === 5 && paths(svg)[0] !== before[0]
    && paths(svg)[2].getAttribute('d') !== before[2].getAttribute('d'));
  check('the heading names the sex', htmlText(b, 'WHO Child Growth Standards (2006) · female').length === 1);
  const female = paths(svg);
  set(env, 'visit_date', '2025-05-20');
  check('a later age inside the window: the curves stay', paths(svg).every((p, i) => p === female[i]));
  set(env, 'visit_date', '2029-01-01');
  const ys = xTicks(svg).map((e) => e.textContent);
  check('an age past the window refits it, in years (got ' + ys.join() + ')', paths(svg)[0] !== female[0] && ys.join() === '0,1,2,3,4'
    && svgText(svg, 'Age (years)').length === 1);
  check('at the end of the reference (its last row a day before "below"), the curves are still named (got '
    + lineNames(svg).map((e) => e.textContent).join() + ')', lineNames(svg).length === 5);
  set(env, 'visit_date', '2025-02-10');
  const xs2 = xTicks(svg).map((e) => e.textContent);
  check('a corrected age narrows the window again (got ' + xs2.join() + ')', xs2.join() === '0,1,2,3,4,5,6,7');
  set(env, 'weight', '');
  check('no measurement: no marker, the curves stay, the description says so', !vis(marker(svg)) && paths(svg).length === 5
    && / No measurement to show yet\.$/.test(descOf(svg)));
  set(env, 'weight', '-1');
  check('not a measurement: no marker, and the description says why', !vis(marker(svg)) && / The measurement is not above 0\.$/.test(descOf(svg)));
  set(env, 'weight', 'abc');
  check('... nor a number', / The measurement is not a number\.$/.test(descOf(svg)));
  set(env, 'weight', '99');
  check('far above the curves: an arrow at the top edge, and the description says so', !vis(marker(svg)) && vis(arrow(svg))
    && / This visit lies outside the chart; an arrow at its edge points to it\.$/.test(descOf(svg))
    && /\(outside the chart\)$/.test(all(arrow(svg), (e) => e.tagName === 'title')[0].textContent));
  check('the arrow points up from the top edge', arrow(svg).getAttribute('points').split(' ')[0].split(',')[1] === GEO.top.toFixed(1));
  set(env, 'weight', '0.5');
  check('far below: an arrow at the bottom edge', vis(arrow(svg))
    && arrow(svg).getAttribute('points').split(' ')[0].split(',')[1] === (GEO.top + GEO.plotHeight).toFixed(1));

  // the table of reference values: the rows sent for the sex on the page
  const tbl = tagged(box(env, 'weight'), 'table')[0];
  const rows = () => tagged(tagged(tbl, 'tbody')[0], 'tr').map((tr) => tagged(tr, 'td').map((td) => td.textContent));
  check('the table of reference values shows the girl\'s rows (got ' + JSON.stringify(rows()[0]) + ')',
    JSON.stringify(rows()) === JSON.stringify(ROWS.female));
  check('its caption is text, never markup', tagged(tbl, 'caption')[0].textContent === '<b>WHO</b> values');
  check('... and sits over the rows (REDCap 17\'s Bootstrap puts a caption under them)', tagged(tbl, 'caption')[0].style.captionSide === 'top'
    && tbl.children[0] === tagged(tbl, 'caption')[0]);
  check('its headers: the age, then the values at each percentile', tagged(tbl, 'th').map((t) => t.textContent).join('|')
    === 'Age (days)|Value at percentile 3 (kg)|Value at percentile 15 (kg)|Value at percentile 50 (kg)|Value at percentile 85 (kg)|Value at percentile 97 (kg)'
    && tagged(tbl, 'th').every((t) => t.getAttribute('scope') === 'col'));
  set(env, 'sex', '1');
  check('a boy: the boy\'s rows', JSON.stringify(rows()) === JSON.stringify(ROWS.male));
  set(env, 'sex', '');
  check('no sex: a note across the table', rows().length === 1 && rows()[0][0] === 'The values show once the sex is entered.'
    && tagged(tagged(tbl, 'tbody')[0], 'td')[0].getAttribute('colspan') === '6');
  set(env, 'sex', '7');
  check('a code that is neither: the note says so', rows()[0][0] === 'No values to show: the sex code is neither 1 (male) nor 2 (female).');
  const cb = box(env, 'weight');
  check('nothing in the container is built with innerHTML or a style attribute',
    audit.innerHTML.concat(audit.styleAttr).every((e) => !inside(e, cb)) && all(cb, () => true).length > 40);
  console.log('growth_chart_dom_js: page with 4 charts built in ' + bootMs + ' ms; first age and weight drawn in '
    + (Date.now() - t1) + ' ms (with the checks); a marker move ' + moveMs.toFixed(3) + ' ms');
  check('the page with its charts is built quickly (' + bootMs + ' ms; loosely bounded)', bootMs < 2000);
  check('a marker move takes well under a few milliseconds (' + moveMs.toFixed(3) + ' ms with the check; loosely bounded)', moveMs < 20);
}

// ---- 3) the check does not depend on the chart ----------------------------------------------
{
  const strip = (cfg) => { for (const r of cfg.rules) { delete r.rangeShow; for (const b of r.branches || []) delete b.rangeShow; } delete cfg.growthCurves; };
  const cases = [
    { sex: '1', dob: '2025-01-10', visit_date: '2025-04-20', weight: '6.4' },
    { sex: '1', dob: '2025-01-10', visit_date: '2025-04-20', weight: '3.1' },
    { sex: '2', dob: '2025-01-10', visit_date: '2025-04-20', weight: '40' },
    { sex: '', dob: '2025-01-10', visit_date: '2025-04-20', weight: '6.4' },
    { sex: '1', dob: '2025-01-10', visit_date: '2025-04-20', weight: '-2' },
  ];
  for (const c of cases) {
    const a = boot('form', c), va = verdict(a, 'weight');
    a.els.weight.fire('blur');
    const va2 = verdict(a, 'weight');
    const b = boot('form', c, null, strip), vb = verdict(b, 'weight');
    b.els.weight.fire('blur');
    const vb2 = verdict(b, 'weight');
    check('the verdict and the note are the same with and without "show": ' + JSON.stringify(c), va === vb && va2 === vb2 && !box(b, 'weight'));
  }
  // a chart that fails hides itself; the table and the check go on
  const vals = { sex: '1', dob: '2025-01-10', visit_date: '2025-04-20', weight: '6.4' };
  const ok = boot('form', vals);
  const broken = boot('form', vals, null, (cfg) => { cfg.growthCurves[W.growthCurveKey].male = { d: 1, lines: null }; });
  const parts = tagged(box(broken, 'weight'), 'details');
  check('a chart that throws while drawing is hidden on its own, the table still shows', parts.length === 2 && parts[0].hidden === true
    && parts[1].hidden === false && tagged(parts[1], 'td').length > 5 && box(broken, 'weight').children[0].hidden === false);
  check('... and the check is the same', verdict(ok, 'weight') === verdict(broken, 'weight'));
  // a rule that only shows needs no limits (AnnotationRules takes it): the
  // chart draws, and the only check is the one every growth rule makes
  const showOnly = (v) => boot('form', Object.assign({}, vals, { weight: v }), null, (cfg) => {
    const r = ruleOf(cfg, 'weight');
    for (const k of ['rangeSoftLo', 'rangeSoftHi', 'rangeHardLo', 'rangeHardHi']) delete r[k];
    r.rangeShow.chart.bands = false;
    delete r.rangeShow.table;
  });
  const so = showOnly('3.1');
  so.els.weight.fire('blur');
  const soNote = so.holders.weight.children[1].innerHTML;
  check('a rule that only shows takes no limits: no configuration error, the chart draws, an extreme weight gets no note (got '
    + JSON.stringify(soNote) + ')', soNote === '' && !!svgOf(so, 'weight') && / z-score -\d+\.\d\d, /.test(descOf(svgOf(so, 'weight'))));
  const so0 = showOnly('0');
  so0.els.weight.fire('blur');
  check('... and a value that is not above 0 is still refused (got ' + JSON.stringify(so0.holders.weight.children[1].innerHTML) + ')',
    /above 0/.test(so0.holders.weight.children[1].innerHTML));
}

// ---- 4) a chart of z-scores and the table of this visit (parity) ----------------------------
{
  const env = boot('form', { sex: '1', dob: '2025-01-10', visit_date: '2025-04-20', weight2: '6,40' });
  const svg = svgOf(env, 'weight2');
  const details = tagged(box(env, 'weight2'), 'details');
  check('"open":false closes the chart; the table keeps the default', details[0].open === false && details[1].open === true);
  check('a chart of z-scores: three flat lines across the span', paths(svg).length === 3
    && paths(svg).every((p) => { const r = pathPoints(p.getAttribute('d'))[0]; return r.length === 2 && r[0][1] === r[1][1]; }));
  check('... coloured by z-score: +-2 SD red, the median green',
    paths(svg).map((p) => p.getAttribute('stroke')).join() === '#c62828,#2e7d32,#c62828');
  check('its y axis is the z-score, at least -3 to 3', svgText(svg, 'z-score').length === 1
    && (() => { const ym = yMap(svg); return ym(-3) <= GEO.top + GEO.plotHeight && ym(3) >= GEO.top; })());
  check('no band, so no band in the legend', legend(box(env, 'weight2'))[1].hidden === true);
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
      const ym = yMap(svg), Y = ym(+v.z), inPlot = Y >= GEO.top && Y <= GEO.top + GEO.plotHeight;
      check('... and the marker sits at z ' + v.z + (inPlot ? '' : ' (an arrow: outside the plot)'), inPlot
        ? vis(marker(svg)) && Math.abs(+marker(svg).getAttribute('cy') - Y) < 0.06
        : vis(arrow(svg)) && !vis(marker(svg)));
      check('... the description in the field\'s decimal mark', descOf(svg).indexOf('z-score ' + v.z.replace('.', ',')) >= 0);
    }
  }
  set(env, 'visit_date', '2026-06-01');
  check('past the span of a chart that names one: an arrow at the right edge', !vis(marker(svg)) && vis(arrow(svg))
    && arrow(svg).getAttribute('points').split(' ')[0].split(',')[0] === '568.0');
  // a rule whose condition is false shows nothing
  const p3 = box(env, 'weight3').children[0];
  check('weight3 applies only while [height] is 1: hidden', p3.hidden === true);
  set(env, 'height', '1');
  check('... shown once it is', p3.hidden === false && svgOf(env, 'weight3') && paths(svgOf(env, 'weight3')).length === 5);
  set(env, 'height', '2');
  check('... hidden again', p3.hidden === true);
}
{
  // a chart of z-scores that starts after birth, a band, a line that cannot be drawn
  const env = boot('form', { sex: '1', dob: '2025-01-10', visit_date: '2025-03-01', weight2: '1' }, null, (cfg) => {
    const r = ruleOf(cfg, 'weight2');
    r.rangeShow.chart.span = ['100', '365'];
    r.rangeShow.chart.bands = true;
    r.rangeShow.chart.lines = { kind: 'percentiles', values: ['0', '50', '97'] };
  });
  const svg = svgOf(env, 'weight2');
  check('an age before the chart\'s span: an arrow at the left edge', vis(arrow(svg))
    && arrow(svg).getAttribute('points').split(' ')[0].split(',')[0] === GEO.left.toFixed(1));
  check('a band on a chart of z-scores, in the legend', bandPaths(svg).length === 1 && legend(box(env, 'weight2'))[1].hidden === false);
  check('a line with no z-score is left out, and the others keep their own names (got ' + lineNames(svg).map((e) => e.textContent).join() + ')',
    paths(svg).length === 2 && lineNames(svg).map((e) => e.textContent).join() === '97,50');
  set(env, 'dob', '2024-12-01'); set(env, 'visit_date', '2025-06-01');
  check('a z-score far below: an arrow at the bottom edge', vis(arrow(svg))
    && arrow(svg).getAttribute('points').split(' ')[0].split(',')[1] === (GEO.top + GEO.plotHeight).toFixed(1));
  const one = boot('form', {}, null, (cfg) => {
    const r = ruleOf(cfg, 'weight2');
    r.rangeShow.chart.bands = true;
    delete r.rangeSoftHi;
  });
  const s1 = svgOf(one, 'weight2');
  check('a band with one soft limit: from it to the edge', bandPaths(s1).length === 1);
}

// ---- 5) branches, a survey, the cap, a rule the page does not judge -------------------------
{
  const env = boot('form', { sex: '1', dob: '2025-01-10', visit_date: '2025-04-20', weight4: '6.4' });
  const b = box(env, 'weight4');
  check('two branches of one field: two panels, both hidden while neither applies', b.children.length === 2
    && b.children.every((h) => h.hidden === true));
  set(env, 'height', '1');
  check('the first branch\'s chart while [height] is 1', b.children[0].hidden === false && b.children[1].hidden === true
    && paths(all(b.children[0], (e) => e.tagName === 'svg')[0]).length === 5);
  set(env, 'height', '2');
  check('the second\'s while it is 2', b.children[0].hidden === true && b.children[1].hidden === false
    && paths(all(b.children[1], (e) => e.tagName === 'svg')[0]).length === 3);
}
{
  const env = boot('survey', { sex: '1', dob: '2025-01-10', visit_date: '2025-04-20', weight: '6.4' });
  check('a survey: the chart and the table start closed', tagged(box(env, 'weight'), 'details').every((d) => d.open === false));
  check('... and draw as on data entry', vis(marker(svgOf(env, 'weight'))));
  const open = boot('survey', {}, null, (cfg) => { ruleOf(cfg, 'weight').rangeShow.chart.open = true; ruleOf(cfg, 'weight').rangeShow.table.open = false; });
  const d = tagged(box(open, 'weight'), 'details');
  check('"open" sets either box, on a survey too', d[0].open === true && d[1].open === false);
}
{
  const env = boot('capped', { sex: '1', dob: '2025-01-10', visit_date: '2025-04-20', weight: '6.4' });
  const b = box(env, 'weight');
  check('a chart left off for the cap: staff see why', !svgOf(env, 'weight')
    && b.textContent.indexOf('Growth chart not shown: this server shows no growth charts or tables of reference values on a page') >= 0
    && b.textContent.indexOf('Table not shown:') >= 0);
  check('... and the check is unchanged', env.NS.validators.weight.test() === true);
}
{
  const cfg = JSON.parse(JSON.stringify(FX.pages.capped));
  const env = boot('capped', { sex: '1', dob: '2025-01-10', visit_date: '2025-04-20', weight: '6.4' }, { context: 'survey' });
  check('a survey is not told why', box(env, 'weight').textContent === '' && cfg.rules.length === 4);
}
{
  const vals = { sex: '1', dob: '2025-01-10', visit_date: '2025-04-20', weight: '6.4' };
  const env = boot('form', vals, null, (cfg) => { ruleOf(cfg, 'weight').rangeAgeDobOp = ['withheld']; });
  check('an input on a form this page was not sent: nothing is shown', box(env, 'weight').children[0].hidden === true && !svgOf(env, 'weight'));
  const def = boot('form', vals, null, (cfg) => { const r = ruleOf(cfg, 'weight'); r.deferred = true; r.deferredOnSave = true; r.deferredWhy = ['x']; });
  check('a rule checked when the record is saved (past the page\'s table cap): nothing is shown',
    !box(def, 'weight') || box(def, 'weight').children.every((h) => h.hidden === true));
}

// ---- 6) its own words, other units, other widths, grams -------------------------------------
{
  const env = boot('form', { sex: '1', dob: '2025-01-10', visit_date: '2025-04-20', weight: '6.4' }, null, (cfg) => {
    const l = ruleOf(cfg, 'weight').rangeShow.chart.labels;
    l.x = 'Âge ({unit})'; l.months = 'mois'; l.years = 'ans'; l.male = 'garçon';
  });
  const svg = svgOf(env, 'weight');
  check('an own x label names the unit through {unit} (got ' + svgText(svg, 'Âge (mois)').length + ')', svgText(svg, 'Âge (mois)').length === 1
    && htmlText(box(env, 'weight'), 'WHO Child Growth Standards (2006) · garçon').length === 1
    && descOf(svg).indexOf('at 3.29 mois') > 0);
  set(env, 'visit_date', '2029-01-01');
  check('... which follows the axis to years', svgText(svg, 'Âge (ans)').length === 1 && xTicks(svg).map((e) => e.textContent).join() === '0,1,2,3,4');
}
{
  const env = boot('form', { sex: '1', dob: '2025-01-10', visit_date: '2025-04-20', weight: '6.4' }, null, (cfg) => { cfg.growth['who-wfa'].chartUnit = 'weeks'; });
  const svg = svgOf(env, 'weight');
  check('a reference counted in weeks ("chartUnit"): the axis and the description in weeks (got ' + xTicks(svg).map((e) => e.textContent).join() + ')',
    svgText(svg, 'Age (weeks)').length === 1 && descOf(svg).indexOf('at 14.29 weeks') > 0 && xTicks(svg).map((e) => e.textContent).join() === '0,5,10,15,20,25,30');
}
{
  STUB.width = 340;
  const env = boot('form', { sex: '1', dob: '2025-01-10', visit_date: '2025-04-20', weight: '6.4' });
  const svg = svgOf(env, 'weight');
  check('a cell 340 wide: the chart is 340 units wide, drawn one to a pixel', svg.getAttribute('viewBox') === '0 0 340 380'
    && svg.style.maxWidth === '340px' && lineNames(svg)[0].getAttribute('x') === '272.0');
  check('... with fewer ticks', xTicks(svg).length <= 5 && xTicks(svg).length >= 3);
  check('... and the marker where the age is', (() => { const xm = xMap(svg, 30.4375); return Math.abs(+marker(svg).getAttribute('cx') - xm.px(100)) < 0.06; })());
  STUB.width = 500;
  set(env, 'weight', '6.5');
  check('the cell widened (a resized window): the next value draws the chart to the new width', svg.getAttribute('viewBox') === '0 0 500 380'
    && svg.style.maxWidth === '500px' && (() => { const xm = xMap(svg, 30.4375); return Math.abs(+marker(svg).getAttribute('cx') - xm.px(100)) < 0.06; })());
  const before = paths(svg);
  set(env, 'weight', '6.6');
  check('... and the same width moves only the marker', paths(svg).every((p, i) => p === before[i]));
  STUB.width = 200;
  const narrow = boot('form', { sex: '1' });
  check('a cell narrower than 320: drawn 320 wide and scaled', svgOf(narrow, 'weight').getAttribute('viewBox') === '0 0 320 380');
  STUB.width = 0;
}
{
  const S = FX.pages.scaled;
  const r = S.rules.find((x) => x.fields.includes('weight'));
  const env = boot('scaled', { sex: '2', dob: '2025-01-10', visit_date: '2025-04-20', weight: '6400' });
  const svg = svgOf(env, 'weight');
  const acc = worstDrawn(env, svg, S.growthCurves[r.growthCurveKey], 'female', 228, 30.4375, 0.001);
  check('a weight in grams ("measureScale" 0.001): the curves in grams, within 0.5 of the exact ones (worst ' + acc.worst.toFixed(3) + ')',
    acc.worst <= 0.5 && acc.pts > 500 && svgText(svg, 'Weight').length === 1);
  check('... and the marker at 6400', Math.abs(+marker(svg).getAttribute('cy') - yMap(svg)(6400)) < 0.06);
}

// ---- 7) Multi-Language Management ----------------------------------------------------------
{
  const env = boot('form', { sex: '1', dob: '2025-01-10', visit_date: '2025-04-20', weight: '6.4', weight2: '6,4' }, null, (cfg) => {
    const r = ruleOf(cfg, 'weight');
    r.rangeShow.chart.labels.months = 'months';
    r.rangeShow.chart.labels.male = 'male';
    r.i18n['show.chart.labels.months'] = { fr: 'mois' };
    r.i18n['show.chart.labels.male'] = { fr: 'garçon' };
  });
  const svg = svgOf(env, 'weight');
  const before = paths(svg);
  const msgEl = env.holders.weight.children[1];
  const writes = () => audit.innerHTML.filter((e) => e === msgEl).length;
  const w0 = writes();
  env.mlm.lang = 'fr';
  env.mlm.cbs.forEach((fn) => fn());
  check('a language switch relabels the chart\'s title in place', titleOf(svg) === 'Poids pour age'
    && htmlText(box(env, 'weight'), 'Poids pour age').length === 1);
  check('... and its description, its x axis and the sex', descOf(svg).indexOf('Poids pour age. ') === 0
    && descOf(svg).indexOf(' · garçon.') > 0 && descOf(svg).indexOf('at 3.29 mois') > 0 && svgText(svg, 'Age (mois)').length === 1
    && htmlText(box(env, 'weight'), 'WHO Child Growth Standards (2006) · garçon').length === 1);
  check('... and the table\'s summary and column header', tagged(box(env, 'weight'), 'summary')[1].textContent === 'Valeurs'
    && tagged(box(env, 'weight2'), 'th')[2].textContent === 'score z');
  check('... without drawing the curves again or re-checking', paths(svg).every((p, i) => p === before[i]) && writes() === w0);
  env.mlm.lang = 'de';
  env.mlm.cbs.forEach((fn) => fn());
  check('a language the rule does not give: the default', titleOf(svg) === 'Weight for age'
    && tagged(box(env, 'weight2'), 'th')[2].textContent === 'z' && svgText(svg, 'Age (months)').length === 1);
}

// ---- 8) a chart of the median alone: the y axis spans the reference at z -2 to 2 -----------
// Live on rc.2 (pid 149, ugc_head) the axis hugged the median: a girl of 12
// months at z 0.81 sat on the top frame, and one at z 1.55 was the arrow.
{
  const ref = FX.growth['who-wfa'];
  const set = FX.pages.median.growthCurves[ruleOf(FX.pages.median, 'weight').growthCurveKey];
  check('the median page draws one line and carries the extent for both sexes', set.values.join() === '0'
    && ['male', 'female'].every((s) => set[s].lines.length === 1 && set[s].extent.length === 2));
  const G = boot('median', {}).NS.growthLogic;
  const C = boot('median', {}).NS.chartLogic;
  const noExtent = (cfg) => { for (const k in cfg.growthCurves) for (const s of ['male', 'female']) delete cfg.growthCurves[k][s].extent; };
  // a girl of 365 days and one of 10 days, at z 1.5 and -1.5
  for (const [at, x, z] of [['2026-01-10', 365, 1.5], ['2026-01-10', 365, -1.5], ['2025-01-20', 10, 1.5], ['2025-01-20', 10, -1.5]]) {
    const w = G.valueAt(z, G.lms(ref, 'female', x), true).toFixed(2);
    const vals = { sex: '2', dob: '2025-01-10', visit_date: at, weight: w };
    const env = boot('median', vals);
    const svg = svgOf(env, 'weight');
    const ym = yMap(svg), cy = +marker(svg).getAttribute('cy');
    check('the median alone, ' + w + ' kg at ' + x + ' days (z ' + z + '): the point sits inside the plot, not the arrow (got cy '
      + cy + ', ' + descOf(svg) + ')', vis(marker(svg)) && !vis(arrow(svg)) && cy > GEO.top && cy < GEO.top + GEO.plotHeight
      && Math.abs(cy - ym(+w)) < 0.06 && paths(svg).length === 1 && lineNames(svg).map((e) => e.textContent).join() === '0');
    // the window shown spans the reference at z -2 and 2, on every row in it
    const win = C.window(C.span(ref), x);
    let lo = Infinity, hi = -Infinity;
    for (let k = Math.ceil(win[0]); k <= Math.floor(win[1]); k++) {
      const lms = G.lms(ref, 'female', k);
      lo = Math.min(lo, G.valueAt(-2, lms, true)); hi = Math.max(hi, G.valueAt(2, lms, true));
    }
    check('... and its y axis spans the reference at z -2 to 2 over the window [' + win.join(', ') + '] (' + lo.toFixed(2) + ' to '
      + hi.toFixed(2) + ' kg)', ym(lo) <= GEO.top + GEO.plotHeight + 0.5 && ym(hi) >= GEO.top - 0.5);
    if (z === 1.5 && x === 365) {
      const old = boot('median', vals, null, noExtent), os = svgOf(old, 'weight');
      check('... where without the extent (rc.2) the same point was the arrow at the top edge', vis(arrow(os)) && !vis(marker(os))
        && arrow(os).getAttribute('points').split(' ')[0].split(',')[1] === GEO.top.toFixed(1));
    }
  }
  // percentiles 3 to 97 lie inside z -2 to 2, so the extent travels with
  // them, unless the band's own curves at -2 and 2 already reach it
  const S = FX.pages.scaled, banded = SET;
  const p397 = S.growthCurves[S.rules.find((r) => r.fields.includes('weight')).growthCurveKey];
  check('percentiles 3, 50 and 97: the extent too; with the band at z -2 and 2: none', p397.female.extent.length === 2
    && ['male', 'female'].every((s) => banded[s].extent === undefined));
}

console.log('growth_chart_dom_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
