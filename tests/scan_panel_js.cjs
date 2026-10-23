/*
 * scan_panel_js.cjs — the findings panel of js/scan.js (wave 11, B5).
 *
 * A finished run's report, one keyset page at a time, from scan-findings. The
 * server decides every cell and whether the reader may see the run at all;
 * what is asserted here is what the client does with the answer:
 *
 *   - cells reach the page as text, never as markup, under real header cells
 *     and a caption;
 *   - Next and Previous walk the keyset cursor: `next` forward, a stack of
 *     `after` values back, and only pages that showed rows go on the stack;
 *   - the filters come from the run's facets, and changing one starts over;
 *   - a withheld run shows no table and its own sentence, and no download;
 *   - the download is offered only beside an answer that showed the report;
 *   - a refusal marked final is never asked again, any other one is asked
 *     again on a ladder that climbs, and nothing is ever re-asked at 0 ms;
 *   - an empty page with a `next` is read past a few pages at a time, and is
 *     never "no findings" (review rv11a2-6);
 *   - a run with no stored decision reads "not certified" (W7-N10).
 *
 * The DOM stub builds elements, unlike tests/scan_client_js.cjs's, because
 * the panel makes rows, cells and options.
 *
 * Run:  node tests/scan_panel_js.cjs
 */
'use strict';
const path = require('path');
const fs = require('fs');

let n = 0, fail = 0;
function check(label, cond) {
  n++;
  if (!cond) { fail++; process.stderr.write('FAIL: ' + label + '\n'); }
}

function makeEl(tag, id) {
  const e = {
    tagName: String(tag || 'div').toUpperCase(), id: id || '', className: '', style: {},
    disabled: false, value: '', children: [], parentNode: null, _attrs: {}, _handlers: {},
    _text: '', innerHTMLSet: false,
    get textContent() {
      return this.children.length ? this.children.map((c) => c.textContent).join('') : this._text;
    },
    set textContent(s) { this.children = []; this._text = String(s); },
    set innerHTML(s) { this.innerHTMLSet = true; this._text = String(s); },
    get innerHTML() { return this._text; },
    get firstChild() { return this.children[0] || null; },
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
    removeChild(c) { const i = this.children.indexOf(c); if (i >= 0) this.children.splice(i, 1); c.parentNode = null; return c; },
    setAttribute(k, v) { this._attrs[k] = String(v); },
    getAttribute(k) { return (k in this._attrs) ? this._attrs[k] : null; },
    removeAttribute(k) { delete this._attrs[k]; },
    addEventListener(ev, fn) { (this._handlers[ev] = this._handlers[ev] || []).push(fn); },
    fire(ev) { (this._handlers[ev] || []).forEach((f) => f({ preventDefault() {} })); },
    click() { this.fire('click'); },
    // A form the client posts (W11F-N5): what it sent, and where it was then.
    submit() {
      submitted.push({ attrs: Object.assign({}, this._attrs), inBody: this.parentNode === global.document.body,
        fields: this.children.map((c) => Object.assign({ tag: c.tagName }, c._attrs)) });
    },
  };
  return e;
}
/** Every form the client submitted, as submit() saw it. */
let submitted = [];

const IDS = ['uv-scan-panel', 'uv-scan-start', 'uv-scan-cancel', 'uv-scan-resume', 'uv-scan-phase',
  'uv-scan-bar-track', 'uv-scan-bar', 'uv-scan-counts', 'uv-scan-found', 'uv-scan-done',
  'uv-scan-note', 'uv-scan-announce', 'uv-scan-stalled', 'uv-scan-stored',
  'uv-scan-report', 'uv-scan-report-summary', 'uv-scan-report-count', 'uv-scan-report-degraded',
  'uv-scan-report-withheld', 'uv-scan-report-filters', 'uv-scan-report-table', 'uv-scan-report-caption',
  'uv-scan-report-head', 'uv-scan-report-body', 'uv-scan-report-nav', 'uv-scan-report-prev',
  'uv-scan-report-next', 'uv-scan-report-note', 'uv-scan-report-export-wrap', 'uv-scan-report-export',
  'uv-scan-filter-form-wrap', 'uv-scan-filter-form', 'uv-scan-filter-reason-wrap', 'uv-scan-filter-reason',
  'uv-scan-filter-type-wrap', 'uv-scan-filter-type', 'uv-scan-filter-group-wrap', 'uv-scan-filter-group',
  'uv-scan-report-banner', 'uv-scan-report-row', 'uv-scan-report-suffix', 'uv-scan-report-other',
  'uv-scan-report-switch'];
let els = {};
function freshDom() {
  els = {};
  IDS.forEach((id) => { els[id] = makeEl(/filter-(form|reason|type|group)$/.test(id) ? 'select' : 'div', id); });
  els['uv-scan-report'].style.display = 'none';
  // Hidden in the markup until the client shows them (pages/scan.php).
  els['uv-scan-report-other'].style.display = 'none';
  els['uv-scan-report-switch'].style.display = 'none';
}
freshDom();
global.window = {};
global.document = {
  getElementById: (id) => els[id] || null,
  createElement: (t) => makeEl(t),
  body: makeEl('body'),
};

const pending = [];
global.setTimeout = (fn, ms) => { pending.push({ fn, ms }); return pending.length; };
function tick() { return new Promise((r) => process.nextTick(r)); }
async function ticks(k) { for (let i = 0; i < (k || 6); i++) await tick(); }
/** Fire every queued timer in turn, up to `rounds`, returning each one's delay. */
async function drive(rounds) {
  const delays = [];
  for (let i = 0; i < rounds && pending.length; i++) {
    const t = pending.shift();
    delays.push(t.ms);
    t.fn();
    await ticks(6);
  }
  return delays;
}

const SRC = fs.readFileSync(path.join(__dirname, '..', 'js', 'scan.js'), 'utf8');
require(path.join(__dirname, '..', 'js', 'scan.js'));
const UV = global.window.UVScan;

// The page's vocabulary, as pages/scan.php hands it over (ScanPageView::labels()).
UV.labels = {
  notCertified: 'This result does not certify the project.',
  ruleProblem: '1 rule could not be evaluated, so this result does not certify the project.',
  ruleProblems: '{n} rules could not be evaluated, so this result does not certify the project.',
  foundUnknown: 'Findings not counted, because the scan ended before it could total them',
  withheldEnded: 'This scan covers one Data Access Group and ended before it confirmed which records belong to that group, so its counts cannot be shown.',
  reportCaption: 'Findings of this scan, page {page}',
  reportLoading: 'Loading the findings.',
  reportCount: 'This report holds {findings}.',
  reportNone: 'This scan stored no findings.',
  reportEmpty: 'No findings match these filters.',
  reportNoMore: 'No further findings match these filters.',
  reportLooking: 'Looking further for findings that match these filters.',
  reportSearching: 'Still looking: the pages read so far held no finding that matches these filters, and there are more to read. Press Next page to keep looking.',
  reportRetry: 'Trying again shortly.',
  reportFailed: 'The findings of this scan could not be read just now.',
  filterAll: 'All',
  reportSuffix: 'Its file name ends in {suffix}, so the file says it is incomplete wherever it is saved.',
  reportReplaced: 'A scan of the whole project has replaced the last report of this Data Access Group. Start a scan of the group to see its current findings.',
  reportNewer: 'Scan {run} started after this one and did not complete, so this report is still the current one.',
  reportShowNewer: 'Show what scan {run} found',
  reportViewingNewer: 'This is scan {run}, which did not complete. The current report is scan {current}.',
  reportShowCurrent: 'Show the current report',
  reportMissing: 'This report counts findings that could not be read now. It may have been replaced since this page was opened; reload the page to open the current report.',
  reportGroupOnly: 'This scan covered {group} only, not the whole project.',
  coverage: {}, phase: {},
};

// -- a fake transport the test scripts -------------------------------------
let script = [];
let sent = [];
let held = null;                  // a reply kept back, to answer later by hand
let holdNext = false;
UV.ajax = function (action, payload) {
  sent.push({ action, payload: JSON.parse(JSON.stringify(payload)) });
  if (holdNext) {
    holdNext = false;
    return new Promise((resolve, reject) => { held = { resolve, reject }; });
  }
  const next = script.length ? script.shift() : { ok: false, why: 'unscripted', final: true };
  if (next.reject) return Promise.reject(next.fatal ? Object.assign(new Error('x'), { uvFatal: true }) : new Error('network'));
  return Promise.resolve(next);
};

const COLS = [{ key: 'record', label: 'Record' }, { key: 'field', label: 'Field' }, { key: 'problem', label: 'What is wrong' }];
const FACETS = {
  form: [{ value: 'fa', label: 'Form A', count: 1200 }, { value: 'fb', label: 'Form B', count: 3 }],
  reason: [{ value: 'blank', label: 'blank', count: 4 }],
  type: [{ value: 'required', label: 'required', count: 4 }],
  group: [],
};
function rowsOf(from, k) {
  const out = [];
  for (let i = 0; i < k; i++) {
    out.push({ id: from + i, cells: { record: 'R' + (from + i), field: 'f' + i, problem: 'This field must not be left blank.' } });
  }
  return out;
}
/** One scan-findings answer that showed the report. */
function page(over) {
  return Object.assign({
    ok: true, run_id: 61, withheld: false, withheldWhy: null, columns: COLS, after: 0, limit: 50,
    filter: { form: '', reason: '', type: '', group: '' },
    report: { coverageSentence: 'Every record was checked, including changes made while it ran.',
              expectedRows: 1203, labelsDegraded: false, degradedSummary: '' },
    marks: { incomplete: false, certified: false, banner: null, row: null, suffix: '_NOT-CERTIFIED' },
    clean: 0, ruleProblems: 0, notExamined: 0, rows: rowsOf(1, 3), next: 3, facets: FACETS,
  }, over || {});
}
function reset(s) {
  script = s.slice();
  sent = [];
  held = null;
  holdNext = false;
  pending.length = 0;
  freshDom();
  global.document.body = makeEl('body');
  UV.csrfToken = 'tok-fixture';   // the page prints one; a check that wants none clears it
  UV.report.runId = null;
  UV.report.inflight = false;
  UV.report.waiting = false;
  UV.report.gen++;
  UV.state.runId = null;
  UV.state.running = false;
  UV.state.watchChain++;
}
function cells() {
  return els['uv-scan-report-body'].children.map((tr) => tr.children.map((td) => td.textContent));
}
function findingsSent() { return sent.filter((s) => s.action === 'scan-findings'); }
/** The payload of the i-th scan-findings sent, or {} when there was none: a check fails, never the file. */
function sentAt(i) { const f = findingsSent()[i]; return f ? f.payload : {}; }
function shown(id) { return els[id].style.display !== 'none'; }
function noZero(label) {
  check(label + ': nothing is queued at 0 ms', pending.every((t) => t.ms > 0));
}

(async function () {
  // -- opening a finished run's report ---------------------------------------
  reset([page({ rows: [{ id: 1, cells: { record: '<img src=x onerror=alert(1)>', field: 'dob', problem: 'a & b' } }], next: null })]);
  UV.attach({ reportRunId: 61, exportUrl: '/redcap/ExternalModules/?prefix=uv&page=pages%2Fexport&pid=13' });
  await ticks();
  const first = findingsSent();
  check('panel: the page opens the finished run it was handed, on its first page',
    first.length === 1 && sentAt(0).run_id === 61 && sentAt(0).after === 0
    && sentAt(0).limit === 50
    && JSON.stringify(sentAt(0).filter) === JSON.stringify({ form: '', reason: '', type: '', group: '' }));
  check('panel: and shows the panel once the answer arrives', shown('uv-scan-report'));
  check('panel: cells are text, never markup',
    cells()[0][0] === '<img src=x onerror=alert(1)>' && cells()[0][2] === 'a & b'
    && !els['uv-scan-report-body'].children[0].children[0].innerHTMLSet);
  check('panel: and nothing in the client writes innerHTML', SRC.indexOf('innerHTML') === -1);
  const ths = els['uv-scan-report-head'].children;
  check('panel: the columns are header cells, scoped to their column, in the server\'s words',
    ths.length === 3 && ths.every((t) => t.tagName === 'TH' && t.getAttribute('scope') === 'col')
    && ths.map((t) => t.textContent).join('|') === 'Record|Field|What is wrong');
  check('panel: the caption names the page', els['uv-scan-report-caption'].textContent === 'Findings of this scan, page 1');
  check('panel: on the only page both controls say they do nothing',
    els['uv-scan-report-prev'].getAttribute('aria-disabled') === 'true'
    && els['uv-scan-report-next'].getAttribute('aria-disabled') === 'true');
  check('panel: the summary is the coverage sentence and the verdict',
    els['uv-scan-report-summary'].textContent
      === 'Every record was checked, including changes made while it ran. This result does not certify the project.');
  check('panel: and the count is the report\'s', els['uv-scan-report-count'].textContent === 'This report holds 1,203 findings.');
  check('panel: the download is offered beside the answer, and names the whole report and its count (rv11b1-5)',
    shown('uv-scan-report-export-wrap')
    && els['uv-scan-report-export'].textContent === 'Download the whole report as a CSV file (1,203 findings)'
    && els['uv-scan-report-export'].getAttribute('href') === null);
  submitted = [];
  els['uv-scan-report-export'].click();
  const posted = submitted[0] || { attrs: {}, fields: [] };
  check('W11F-N5: the download posts run_id and the page\'s token to the export route, and nothing else',
    submitted.length === 1 && posted.inBody && posted.attrs.method === 'post'
    && posted.attrs.action === '/redcap/ExternalModules/?prefix=uv&page=pages%2Fexport&pid=13'
    && JSON.stringify(posted.fields) === JSON.stringify([
      { tag: 'INPUT', type: 'hidden', name: 'run_id', value: '61' },
      { tag: 'INPUT', type: 'hidden', name: 'redcap_csrf_token', value: 'tok-fixture' }]));
  check('W11F-N5: and the form is taken away again once sent', global.document.body.children.length === 0);
  check('W11F-N5: the client never builds an export address of its own', SRC.indexOf("'run_id='") === -1);

  // -- Next and Previous on the keyset cursor --------------------------------
  reset([page({ next: 3 }), page({ rows: rowsOf(4, 3), next: 6 }), page({ rows: rowsOf(7, 2), next: null }),
         page({ rows: rowsOf(4, 3), next: 6 })]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  check('keyset: a page with a next offers Next and not Previous',
    els['uv-scan-report-next'].getAttribute('aria-disabled') === 'false'
    && els['uv-scan-report-prev'].getAttribute('aria-disabled') === 'true');
  els['uv-scan-report-next'].click();
  await ticks();
  check('keyset: Next asks after the cursor the page gave', sentAt(1).after === 3);
  check('keyset: and says which page it is', els['uv-scan-report-caption'].textContent === 'Findings of this scan, page 2'
    && cells()[0][0] === 'R4' && els['uv-scan-report-prev'].getAttribute('aria-disabled') === 'false');
  els['uv-scan-report-next'].click();
  await ticks();
  check('keyset: the last page has no Next', sentAt(2).after === 6
    && els['uv-scan-report-next'].getAttribute('aria-disabled') === 'true');
  els['uv-scan-report-next'].click();
  await ticks();
  check('keyset: and pressing it asks nothing', findingsSent().length === 3);
  els['uv-scan-report-prev'].click();
  await ticks();
  check('keyset: Previous asks after the cursor the page before was asked with',
    sentAt(3).after === 3 && els['uv-scan-report-caption'].textContent === 'Findings of this scan, page 2');
  noZero('keyset');

  // One request at a time: a second press while the first is out asks nothing.
  reset([page({ next: 3 })]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  holdNext = true;
  els['uv-scan-report-next'].click();
  els['uv-scan-report-next'].click();
  els['uv-scan-report-prev'].click();
  await ticks();
  check('keyset: presses while a page is on its way ask nothing more', findingsSent().length === 2);
  held.resolve(page({ rows: rowsOf(4, 3), next: null }));
  await ticks();
  check('keyset: and the page that arrives is the one asked for, page 2 of 2',
    els['uv-scan-report-caption'].textContent === 'Findings of this scan, page 2' && cells()[0][0] === 'R4');

  // -- filters from the run's facets ----------------------------------------
  reset([page(), page({ rows: rowsOf(1, 1), next: null })]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  const formSel = els['uv-scan-filter-form'];
  check('filters: each is built from the run\'s facets: All, then value, label and count',
    formSel.children.length === 3 && formSel.children[0].value === '' && formSel.children[0].textContent === 'All'
    && formSel.children[1].value === 'fa' && formSel.children[1].textContent === 'Form A (1,200)');
  check('filters: one with nothing to choose from is not offered',
    !shown('uv-scan-filter-group-wrap') && shown('uv-scan-filter-form-wrap') && shown('uv-scan-report-filters'));
  const optionsBefore = formSel.children.slice();
  els['uv-scan-report-next'].click();
  await ticks();
  // rv11b1-7 (b): counting the options cannot tell a rebuild from none, since
  // a rebuild makes the same three. The nodes themselves must be the same.
  check('rv11b1-7: a page turn keeps the very option nodes the reader may be choosing from',
    sentAt(findingsSent().length - 1).after === 3 && formSel.children.length === optionsBefore.length
    && formSel.children.every((o, i) => o === optionsBefore[i]));
  formSel.value = 'fb';
  script = [page({ rows: rowsOf(9, 1), next: null })];
  formSel.fire('change');
  await ticks();
  const fsent = findingsSent();
  check('filters: changing one starts over, from the first page, with the filter sent',
    sentAt(fsent.length - 1).after === 0 && sentAt(fsent.length - 1).filter.form === 'fb'
    && els['uv-scan-report-caption'].textContent === 'Findings of this scan, page 1'
    && els['uv-scan-report-prev'].getAttribute('aria-disabled') === 'true');
  check('filters: and the options are not rebuilt under the reader', formSel.children.length === 3);
  // rv11b1-5: a filter narrows the table, never the file, and the button says so.
  check('rv11b1-5: with a filter chosen, the button still names the whole report and its count',
    shown('uv-scan-report-export-wrap')
    && els['uv-scan-report-export'].textContent === 'Download the whole report as a CSV file (1,203 findings)');
  submitted = [];
  els['uv-scan-report-export'].click();
  check('rv11b1-5: and the file it asks for carries no filter: run_id and the token only',
    submitted.length === 1
    && JSON.stringify(submitted[0].fields.map((f) => f.name)) === JSON.stringify(['run_id', 'redcap_csrf_token']));

  // A filter changed while a page is out: that answer is not shown, and the
  // filtered page is asked for once it lands.
  reset([page()]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  holdNext = true;
  els['uv-scan-report-next'].click();
  await ticks();
  els['uv-scan-filter-reason'].value = 'blank';
  els['uv-scan-filter-reason'].fire('change');
  await ticks();
  check('filters: a change while a page is out does not send a second request at once', findingsSent().length === 2);
  script = [page({ rows: rowsOf(20, 1), next: null })];
  held.resolve(page({ rows: rowsOf(4, 3), next: 6 }));
  await ticks();
  const last = sentAt(findingsSent().length - 1);
  check('filters: the answer to the old question is not shown; the filtered page is asked for and shown',
    findingsSent().length === 3 && last.filter.reason === 'blank' && last.after === 0
    && cells().length === 1 && cells()[0][0] === 'R20');

  // -- a withheld run --------------------------------------------------------
  reset([page({ withheld: true, withheldWhy: UV.labels.withheldEnded, rows: [], next: null, facets: null })]);
  UV.attach({ reportRunId: 61, exportUrl: '/x/export.php' });
  await ticks();
  check('withheld: no table, no filters, no controls',
    !shown('uv-scan-report-table') && !shown('uv-scan-report-filters') && !shown('uv-scan-report-nav'));
  check('withheld: its own sentence instead', shown('uv-scan-report-withheld')
    && els['uv-scan-report-withheld'].textContent === UV.labels.withheldEnded);
  submitted = [];
  els['uv-scan-report-export'].click();
  check('withheld: and nothing to download', !shown('uv-scan-report-export-wrap')
    && UV.report.exportFor === null && submitted.length === 0);
  check('withheld: no count is shown for it either', els['uv-scan-report-count'].textContent === '');

  // -- W11F-N5: a page that printed no token offers no download --------------
  reset([page({ next: null })]);
  UV.csrfToken = '';
  UV.attach({ reportRunId: 61, exportUrl: '/x/export.php' });
  await ticks();
  submitted = [];
  els['uv-scan-report-export'].click();
  check('W11F-N5: without the page\'s token the download is not offered, and a press sends nothing',
    shown('uv-scan-report-table') && !shown('uv-scan-report-export-wrap') && UV.report.exportFor === null
    && submitted.length === 0);
  reset([page({ next: null })]);
  UV.attach({ reportRunId: 61, exportUrl: '/x/export.php' });
  await ticks();
  check('W11F-N5 (control): with the token it is', shown('uv-scan-report-export-wrap') && UV.report.exportFor === 61);

  // -- refusals --------------------------------------------------------------
  reset([{ ok: false, why: 'no scan with that reference is running for this project', final: true }]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  const finalDelays = await drive(10);
  check('refusal: a final one is said', els['uv-scan-report-note'].textContent
    === 'no scan with that reference is running for this project');
  check('refusal: and never asked again', findingsSent().length === 1 && finalDelays.length === 0);
  check('refusal: with nothing of a report on screen', !shown('uv-scan-report-table'));

  reset([page({ next: 3 }), { ok: false, why: 'Could not verify your rights - scan not run.', final: false }]);
  UV.attach({ reportRunId: 61, exportUrl: '/x/export.php' });
  await ticks();
  els['uv-scan-report-next'].click();
  await ticks();
  check('refusal: one that is not final takes the download away, since it answered nothing',
    !shown('uv-scan-report-export-wrap'));
  check('refusal: and says it will ask again', els['uv-scan-report-note'].textContent
    === 'Could not verify your rights - scan not run. Trying again shortly.');
  // The same refusal again and again: the ladder climbs and stops at the top.
  script = [];
  for (let i = 0; i < 12; i++) script.push({ ok: false, why: 'busy', final: false });
  const ladder = await drive(8);
  check('refusal: asked again on a ladder that climbs, never at 0 ms',
    ladder.join(',') === '1500,3000,6000,12000,24000,30000,30000,30000');
  check('refusal: each of them the same question', findingsSent().slice(1).every((s) => s.payload.after === 3));
  script = [page({ rows: rowsOf(4, 2), next: null })];
  await drive(1);
  check('refusal: and when an answer comes it is shown, and the download is back',
    cells()[0][0] === 'R4' && els['uv-scan-report-caption'].textContent === 'Findings of this scan, page 2'
    && shown('uv-scan-report-export-wrap'));

  reset([{ reject: true }, page({ next: null })]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  check('transport: a request that did not complete is asked again, a moment later',
    pending.length === 1 && pending[0].ms === 1500
    && els['uv-scan-report-note'].textContent === 'Lost contact with the server. Trying again shortly.');
  await drive(1);
  check('transport: and the answer is shown', cells().length === 3);
  reset([{ reject: true, fatal: true }]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  check('transport: a transport that does not exist is not retried', pending.length === 0 && findingsSent().length === 1);

  // -- rv11a2-6: an empty page with a next is not "no findings" -------------
  reset([page({ rows: [], next: 20000, filter: { form: 'fa', reason: 'high', type: '', group: '' } }),
         page({ rows: [], next: 40000 }), page({ rows: rowsOf(50, 3), next: null })]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  check('rv11a2-6: an empty page with a next says it is looking further, not that nothing matches',
    els['uv-scan-report-note'].textContent === UV.labels.reportLooking
    && els['uv-scan-report-note'].textContent.indexOf('No findings') === -1);
  check('rv11a2-6: and reads on a moment later, never at 0 ms', pending.length === 1 && pending[0].ms === 250);
  await drive(3);
  check('rv11a2-6: from the cursor the empty pages gave', sentAt(1).after === 20000
    && sentAt(2).after === 40000);
  check('rv11a2-6: until the page that holds the matches is shown, as the first page',
    cells().length === 3 && cells()[0][0] === 'R50'
    && els['uv-scan-report-caption'].textContent === 'Findings of this scan, page 1'
    && els['uv-scan-report-prev'].getAttribute('aria-disabled') === 'true'
    && els['uv-scan-report-note'].textContent === '');

  // Bounded for one press: four more pages, then it says so and waits.
  const empties = [];
  for (let i = 0; i < 8; i++) empties.push(page({ rows: [], next: 1000 * (i + 1) }));
  reset(empties);
  UV.attach({ reportRunId: 61 });
  await ticks();
  const skipDelays = await drive(10);
  check('rv11a2-6: it reads past at most four empty pages for one press',
    findingsSent().length === 5 && skipDelays.join(',') === '250,250,250,250');
  check('rv11a2-6: then says it is still looking, and leaves Next page to the reader',
    els['uv-scan-report-note'].textContent === UV.labels.reportSearching
    && els['uv-scan-report-next'].getAttribute('aria-disabled') === 'false' && pending.length === 0);
  script = [page({ rows: rowsOf(77, 1), next: null })];
  els['uv-scan-report-next'].click();
  await ticks();
  check('rv11a2-6: Next page carries on from where it stopped', sentAt(5).after === 5000
    && cells()[0][0] === 'R77');
  check('rv11a2-6: and the empty pages are not pages to go back to',
    els['uv-scan-report-prev'].getAttribute('aria-disabled') === 'true'
    && els['uv-scan-report-caption'].textContent === 'Findings of this scan, page 1');

  // "No findings" only when next is null, nothing was shown, and the report
  // counts none (rv11b1-1).
  reset([page({ rows: [], next: null, report: { coverageSentence: 'Every record was checked, including changes made while it ran.', expectedRows: 0 } })]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  check('empty: a run with no findings says so, when the server says there is no more',
    els['uv-scan-report-note'].textContent === UV.labels.reportNone);
  reset([page()]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  els['uv-scan-filter-form'].value = 'fb';
  script = [page({ rows: [], next: null })];
  els['uv-scan-filter-form'].fire('change');
  await ticks();
  check('empty: and a filter nothing matches says that', els['uv-scan-report-note'].textContent === UV.labels.reportEmpty);
  reset([page({ next: 3 }), page({ rows: [], next: null })]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  els['uv-scan-report-next'].click();
  await ticks();
  check('empty: a last page with nothing on it says there is no more, and Previous still works',
    els['uv-scan-report-note'].textContent === UV.labels.reportNoMore
    && els['uv-scan-report-prev'].getAttribute('aria-disabled') === 'false');

  // -- W7-N10 in the panel -------------------------------------------------
  reset([page({ clean: null, ruleProblems: 5 })]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  check('W7-N10: a run with no decision is not certified in the panel, whatever its counters say',
    /This result does not certify the project\.$/.test(els['uv-scan-report-summary'].textContent)
    && els['uv-scan-report-summary'].textContent.indexOf('could not be evaluated') === -1);
  reset([page({ clean: 0, ruleProblems: 2 })]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  check('W7-N10 control: beside a stored 0 the rule count is said',
    els['uv-scan-report-summary'].textContent.indexOf('2 rules could not be evaluated') !== -1);
  reset([page({ clean: 1, report: { coverageSentence: 'Every record was checked, including changes made while it ran.', expectedRows: null } })]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  check('summary: a certified run says what it checked and nothing more, and an uncounted report says so',
    els['uv-scan-report-summary'].textContent === 'Every record was checked, including changes made while it ran.'
    && els['uv-scan-report-count'].textContent === UV.labels.foundUnknown);

  // -- rv11b1-2: the run's scope in the summary -----------------------------
  // An unrestricted reader who watched a group run to its end read "Every
  // record was checked" over the group's count, with nothing saying the
  // other groups were not read. scan-findings carries `runScope` (rv11a1-1).
  const GROUP_RUN = { kind: 'group', group: 'site_b', label: 'Data Access Group site_b' };
  reset([{ ok: true, run_id: 9, phase: 'terminal', terminal: 'complete', coverage: 'complete-through-fence',
           detail: 'complete', total: 5, done: 5, findings: 0, active: false, clean: 1, scope: 'group' },
         page({ run_id: 9, runScope: GROUP_RUN, clean: 1, rows: [], next: null,
                report: { coverageSentence: 'Every record was checked, including changes made while it ran.', expectedRows: 0 } })]);
  UV.attach({ runId: 9 });
  await ticks(10);
  check('rv11b1-2: a group run watched to its end says in its summary that it covered that group only',
    sentAt(0).run_id === 9 && els['uv-scan-report-summary'].textContent
      === 'Every record was checked, including changes made while it ran. This scan covered Data Access Group site_b only, not the whole project.');
  reset([page({ runScope: { kind: 'project', group: null, label: 'whole project' }, next: null })]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  check('rv11b1-2 (control): a whole-project run says nothing about a group',
    els['uv-scan-report-summary'].textContent.indexOf('only, not the whole project') === -1);
  reset([page({ withheld: true, withheldWhy: UV.labels.withheldEnded, runScope: GROUP_RUN, rows: [], next: null, facets: null })]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  check('rv11b1-2: and a withheld group run says which group, with no coverage sentence',
    els['uv-scan-report-summary'].textContent
      === 'This scan covered Data Access Group site_b only, not the whole project. This result does not certify the project.');

  // -- rv11b1-3: a withheld run names no rule count -------------------------
  // The main panel said "...not a complete picture of the group. This result
  // does not certify the project." while the report said "2 rules could not
  // be evaluated". The server drops the count from such an answer (rv11a1-3);
  // the panel does not read one that arrives.
  reset([page({ withheld: true, withheldWhy: UV.labels.withheldEnded, clean: 0, ruleProblems: 2, rows: [], next: null, facets: null })]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  check('rv11b1-3: a withheld run shows no rule count, whatever the answer carries',
    els['uv-scan-report-summary'].textContent === 'This result does not certify the project.'
    && els['uv-scan-report-summary'].textContent.indexOf('could not be evaluated') === -1);
  reset([page({ clean: 0, ruleProblems: 2, next: null })]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  check('rv11b1-3 (control): a run that proved its scope says its rule count',
    els['uv-scan-report-summary'].textContent.indexOf('2 rules could not be evaluated') !== -1);

  // -- rv11b1-4: the judged-as-of day in the summary -------------------------
  reset([page({ next: null, report: { coverageSentence: 'Every record was checked, including changes made while it ran.',
                                      expectedRows: 1, asOf: '2026-10-21' } })]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  check('rv11b1-4: a report opened from the page says the day its dates were judged as of',
    els['uv-scan-report-summary'].textContent === 'Every record was checked, including changes made while it ran. '
      + 'This result does not certify the project. Dates were judged as of 2026-10-21.');
  reset([page({ next: null, report: { coverageSentence: 'x', expectedRows: 1, asOf: '21/10/2026' } })]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  check('rv11b1-4 (control): a day not in the stored form is not said',
    els['uv-scan-report-summary'].textContent.indexOf('judged as of') === -1);

  // -- README's three marks of an incomplete report (item 10) ---------------
  // The words are ScanService::incompleteMarks()'s, which tests/scan_service_php.php
  // and tests/scan_export_php.php pin; the page shows them where the file has them.
  const MARKS = {
    incomplete: true, certified: false,
    banner: 'INCOMPLETE SCAN - this report does NOT certify the project as clean',
    row: 'This scan did not cover the whole project. Rows above are real findings over the records that WERE '
       + 'examined; absence of a row is not evidence a record is clean. Some records could not be checked. '
       + 'This is not a complete picture of the project.',
    suffix: '_INCOMPLETE_CANCELLED',
  };
  reset([page({ marks: MARKS, next: null })]);
  UV.attach({ reportRunId: 61, exportUrl: '/x/export.php' });
  await ticks();
  check('marks: an incomplete report carries its banner above the table',
    shown('uv-scan-report-banner') && els['uv-scan-report-banner'].textContent === MARKS.banner);
  check('marks: its terminal row below it',
    shown('uv-scan-report-row') && els['uv-scan-report-row'].textContent === MARKS.row);
  check('marks: and the file name\'s suffix beside the download',
    shown('uv-scan-report-suffix') && els['uv-scan-report-suffix'].textContent
      === 'Its file name ends in _INCOMPLETE_CANCELLED, so the file says it is incomplete wherever it is saved.');
  reset([page({ next: null })]);
  UV.attach({ reportRunId: 61, exportUrl: '/x/export.php' });
  await ticks();
  check('marks (control): a report that is not incomplete carries none of the three',
    !shown('uv-scan-report-banner') && !shown('uv-scan-report-row') && !shown('uv-scan-report-suffix')
    && els['uv-scan-report-banner'].textContent === '' && shown('uv-scan-report-export-wrap'));
  reset([page({ withheld: true, withheldWhy: UV.labels.withheldEnded, rows: [], next: null, facets: null, marks: MARKS })]);
  UV.attach({ reportRunId: 61, exportUrl: '/x/export.php' });
  await ticks();
  check('marks: a withheld run keeps its banner, and has no rows for a terminal row to follow, nor a file',
    shown('uv-scan-report-banner') && !shown('uv-scan-report-row') && !shown('uv-scan-report-suffix'));
  reset([page({ marks: MARKS, next: 3 }), { ok: false, why: 'no scan with that reference is running for this project', final: true }]);
  UV.attach({ reportRunId: 61, exportUrl: '/x/export.php' });
  await ticks();
  els['uv-scan-report-next'].click();
  await ticks();
  check('marks: a final refusal takes all three down with the report',
    !shown('uv-scan-report-banner') && !shown('uv-scan-report-row') && !shown('uv-scan-report-export-wrap'));

  // -- the panel and the run ------------------------------------------------
  // A run this tab watches to its end opens its report; a new Start puts it away.
  reset([{ ok: true, run_id: 7, phase: 'terminal', terminal: 'complete', coverage: 'complete-through-fence',
           detail: 'complete', total: 2, done: 2, findings: 1, active: false, clean: 0, ruleProblems: 0 },
         page({ run_id: 7, next: null })]);
  UV.attach({ runId: 7 });
  await ticks(10);
  check('run: a run that ends while this tab watches it opens its report',
    sent[0].action === 'scan-status' && findingsSent().length === 1 && sentAt(0).run_id === 7
    && shown('uv-scan-report'));
  script = [{ ok: true, run_id: 8 }, { ok: false, why: 'stop here' }];
  UV.start();
  await ticks(10);
  check('run: a new Start puts the last report away', !shown('uv-scan-report'));
  noZero('run');

  // rv11b1-7 (a): the reviewer's F2. A page of the last report, asked for
  // before Start, lands while the new run is still going. Start moved the
  // report on to a new generation, so that answer is dropped, not shown.
  reset([{ ok: true, run_id: 8 },
         { ok: true, worked: 1, status: { ok: true, run_id: 8, phase: 'checking', total: 10, done: 1,
                                          findings: 0, active: true, mayCancel: true } }]);
  holdNext = true;
  UV.attach({ reportRunId: 5 });
  await ticks();
  const oldAsk = findingsSent().length;
  UV.start();
  await ticks(16);
  const startedNew = sent.some((s) => s.action === 'scan-start') && UV.state.runId === 8;
  held.resolve(page({ run_id: 5, rows: rowsOf(40, 4), next: null }));
  await ticks(16);
  check('rv11b1-7: the old run\'s page landing while the new run goes is not shown',
    oldAsk === 1 && startedNew && !shown('uv-scan-report') && cells().length === 0
    && els['uv-scan-report-summary'].textContent === '');

  // -- rv11b1-1: which report opens -----------------------------------------
  // A group whose last report a whole-project scan replaced has none to open
  // (ScanService::finishedRun() says `replaced`): one sentence, nothing asked.
  reset([]);
  UV.attach({ reportRunId: null, reportNewerId: null, reportReplaced: true, exportUrl: '/x/export.php' });
  await ticks();
  check('rv11b1-1: a group report a whole-project scan replaced is said in one sentence, and nothing is asked for',
    findingsSent().length === 0 && shown('uv-scan-report')
    && els['uv-scan-report-note'].textContent === UV.labels.reportReplaced
    && !shown('uv-scan-report-table') && !shown('uv-scan-report-export-wrap') && !shown('uv-scan-report-other'));
  reset([page({ rows: [], next: null, report: { coverageSentence: 'Every record was checked, including changes made while it ran.', expectedRows: 2 } })]);
  UV.attach({ reportRunId: 61 });
  await ticks();
  check('rv11b1-1: an empty report that counts findings never says it stored none',
    els['uv-scan-report-note'].textContent === UV.labels.reportMissing
    && els['uv-scan-report-note'].textContent !== UV.labels.reportNone);

  // -- rv11b2-5: a newer run that did not complete --------------------------
  reset([page({ next: null }), page({ run_id: 64, rows: rowsOf(9, 1), next: null }), page({ next: null })]);
  UV.attach({ reportRunId: 61, reportNewerId: 64 });
  await ticks();
  check('rv11b2-5: the current report opens, and the newer run is named beside it with a control',
    sentAt(0).run_id === 61 && shown('uv-scan-report-other') && shown('uv-scan-report-switch')
    && els['uv-scan-report-other'].textContent
       === 'Scan 64 started after this one and did not complete, so this report is still the current one.'
    && els['uv-scan-report-switch'].textContent === 'Show what scan 64 found');
  els['uv-scan-report-switch'].click();
  await ticks();
  check('rv11b2-5: the control opens the newer run, which names the current report',
    sentAt(1).run_id === 64 && cells()[0][0] === 'R9'
    && els['uv-scan-report-other'].textContent === 'This is scan 64, which did not complete. The current report is scan 61.'
    && els['uv-scan-report-switch'].textContent === UV.labels.reportShowCurrent);
  els['uv-scan-report-switch'].click();
  await ticks();
  check('rv11b2-5: and the way back', sentAt(2).run_id === 61 && findingsSent().length === 3);
  script = [{ ok: true, run_id: 70 }, { ok: false, why: 'stop here' }];
  UV.start();
  await ticks(10);
  check('rv11b2-5: a new Start puts the offer away with the report',
    !shown('uv-scan-report-other') && !shown('uv-scan-report-switch') && UV.report.offer === null);
  reset([page({ next: null })]);
  UV.attach({ reportRunId: 61, reportNewerId: null });
  await ticks();
  check('rv11b2-5 (control): with nothing newer, nothing is offered beside the report',
    !shown('uv-scan-report-other') && !shown('uv-scan-report-switch'));

  // Without the panel on the page the client asks for no findings at all,
  // which is what tests/scan_client_js.cjs relies on.
  reset([]);
  delete els['uv-scan-report'];
  script = [{ ok: true, run_id: 7, phase: 'terminal', terminal: 'complete', coverage: 'partial', detail: 'complete',
              total: 1, done: 1, findings: 0, active: false, clean: 0 }];
  UV.attach({ runId: 7, reportRunId: 9 });
  await ticks(10);
  check('run: a page without the panel asks for no findings', findingsSent().length === 0);

  console.log('scan_panel_js: ' + n + ' checks, ' + fail + ' failure(s)');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
