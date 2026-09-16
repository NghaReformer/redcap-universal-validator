/*
 * scan_client_js.cjs — the browser half of the durable scan.
 *
 * The client drives a loop and renders what the server said. It decides nothing
 * about the scan, which means the interesting assertions are all about what it
 * does when the answer is NOT "carry on":
 *
 *   - a refusal ends the loop and says so, rather than retrying into a run that
 *     no longer wants this worker;
 *   - an answer that moved nothing is a WAIT on a lengthening ladder, not a
 *     reason to ask again immediately, and the server's own explanation of why
 *     nothing moved stays on screen;
 *   - a transport failure retries, because the run on the server is untouched —
 *     but a transport that does not EXIST is not a lost connection and is not
 *     retried at all;
 *   - one tab drives one chain, however many times its buttons are pressed;
 *   - completion comes from the server saying terminal, NEVER from the counts
 *     looking finished. A count that looks done and a run that IS done are
 *     exactly the two things this module refuses to treat as the same.
 *
 * THE HARNESS USED TO MAKE A STALL IMPOSSIBLE TO WRITE. When a script ran out
 * the fake transport manufactured `{terminal:'complete'}`, so any loop that
 * over-ran was handed an ending and stopped — which is precisely why nothing
 * noticed that a run going nowhere was re-asked at zero milliseconds. The
 * fallback now REPEATS the last scripted answer, so "the server keeps saying
 * the same unhelpful thing" is expressible, and several tests below are exactly
 * that. Assertions about a schedule bound the TOTAL number of requests, not
 * only the first delay: a single sample can never detect a ladder that does not
 * climb, and that is how a flat 1 Hz poll shipped.
 *
 * Run:  node tests/scan_client_js.cjs
 */
'use strict';
const path = require('path');

let n = 0, fail = 0;
function check(label, cond) {
  n++;
  if (!cond) { fail++; process.stderr.write('FAIL: ' + label + '\n'); }
}

// -- a DOM with exactly the ids the page provides ---------------------------
// setAttribute/getAttribute/removeAttribute are modelled on tests/a11y_dom_js.cjs:
// the panel's ARIA state is set through them, and a stub without them would make
// the client throw rather than make the test fail.
function makeEl(id) {
  return {
    id, textContent: '', className: '', style: {}, disabled: false,
    _attrs: {}, _handlers: {},
    setAttribute(k, v) { this._attrs[k] = String(v); },
    getAttribute(k) { return (k in this._attrs) ? this._attrs[k] : null; },
    removeAttribute(k) { delete this._attrs[k]; },
    addEventListener(ev, fn) { (this._handlers[ev] = this._handlers[ev] || []).push(fn); },
    click() { (this._handlers.click || []).forEach((f) => f({ preventDefault() {} })); },
  };
}
const els = {};
['uv-scan-panel', 'uv-scan-start', 'uv-scan-cancel', 'uv-scan-resume', 'uv-scan-phase',
 'uv-scan-bar-track', 'uv-scan-bar', 'uv-scan-counts', 'uv-scan-found', 'uv-scan-done',
 'uv-scan-note', 'uv-scan-announce'].forEach((id) => {
  els[id] = makeEl(id);
});

global.window = {};
global.document = { getElementById: (id) => els[id] || null };

// setTimeout is driven by hand: the loop must be steppable, and a real timer
// would make every assertion a race.
const pending = [];
global.setTimeout = (fn, ms) => { pending.push({ fn, ms }); return pending.length; };
/** Let queued promise callbacks run. */
function tick() { return new Promise((r) => process.nextTick(r)); }
/** Several of them, which is what one request-and-render takes. */
async function ticks(k) { for (let i = 0; i < (k || 4); i++) await tick(); }
/** Run exactly one queued timer, so a backoff is observable rather than raced. */
function fire() { const t = pending.shift(); if (t) t.fn(); }
/** Fire up to `rounds` queued timers, returning the delay each one waited. */
async function drive(rounds) {
  const delays = [];
  for (let i = 0; i < rounds; i++) {
    if (!pending.length) break;
    delays.push(pending[0].ms);
    fire();
    await ticks(6);
  }
  return delays;
}

require(path.join(__dirname, '..', 'js', 'scan.js'));
const UV = global.window.UVScan;

/*
 * THE PAGE HANDS THE CLIENT ITS VOCABULARY. js/scan.js holds no phase table and
 * no coverage table: both live in php/ScanPageView.php and pages/scan.php prints
 * ScanPageView::labels() into the panel, so a new coverage value is one PHP
 * entry rather than two edits a reviewer has to notice are a pair. The harness
 * therefore has to install them exactly as the page does. tests/scan_page_php.php
 * is what pins the wording and what fails if a phase or coverage constant ever
 * arrives without a sentence.
 */
UV.labels = {
  phase: {
    'planning': 'Listing the records to check',
    'scanning': 'Checking records',
    'catch-up': 'Checking what changed while it ran',
    'unique-finalize': 'Looking for duplicate values',
    'rollup-finalize': 'Building the summary',
    'cancelling': 'Stopping',
    'terminal': 'Finished',
  },
  coverage: {
    'complete-through-fence': 'Every record was checked, including changes made while it ran.',
    'manifest-complete': 'Every record on the opening list was checked. This server cannot prove '
                       + 'the project did not change during the scan.',
    'partial': 'Some records could not be checked. This is not a complete picture of the project.',
    'failed': 'The scan failed, so it describes nothing.',
    'empty-scope': 'No records were in scope for this scan, so nothing was checked and this '
                 + 'result says nothing about the project.',
  },
  coverageUnknown: 'This scan finished, but this page does not recognise the result it recorded '
                 + '({value}). Treat it as incomplete and run it again.',
  truncated: 'Some findings were not kept, because the scan reached the limit this project allows.',
};

// -- a fake transport the test scripts -------------------------------------
let script = [];
let sent = [];
let lastReply = null;
let throwOn = null;                 // an action whose call throws SYNCHRONOUSLY
UV.ajax = function (action, payload) {
  sent.push({ action, payload });
  if (throwOn === action || throwOn === '*') throw new TypeError('transport is not defined');
  const next = script.shift();
  if (!next) {
    // Repeat the last answer. Manufacturing an ending here is what made a
    // stalled run impossible to write a test for.
    return Promise.resolve(lastReply || { ok: true, status: { ok: true, terminal: 'complete' } });
  }
  if (next.reject) return Promise.reject(new Error('network'));
  lastReply = next;
  return Promise.resolve(next);
};
function reset(s) {
  script = s.slice();
  sent = [];
  lastReply = null;
  throwOn = null;
  pending.length = 0;
  UV.state.runId = null;
  UV.state.running = false;
  UV.state.starting = false;
  UV.state.inflight = false;
  UV.state.wait = 1000;
  UV.state.idle = 0;
  UV.state.stalledMs = 0;
  UV.state.seen = false;
  UV.state.lastDone = -1;
  UV.state.lastPhase = null;
  UV.state.lastPct = null;
  UV.state.lastFindings = null;
  Object.keys(els).forEach((k) => {
    els[k].textContent = ''; els[k].style = {}; els[k]._attrs = {}; els[k].disabled = false;
  });
}

const running = (done, total) => ({
  ok: true, run_id: 7, phase: 'scanning', terminal: null, coverage: 'partial',
  detail: 'complete', values: 'none', total, done, findings: 0, scope: 'project',
  active: true, mayCancel: true, why: null,
});
const finished = (coverage) => ({
  ok: true, run_id: 7, phase: 'terminal', terminal: 'complete', coverage,
  detail: 'complete', values: 'none', total: 10, done: 10, findings: 0,
  scope: 'project', active: false, mayCancel: false, why: null,
});
/** One scan-work answer that DID something. */
const worked = (st, k) => ({ ok: true, stop: null, worked: (k === undefined ? 5 : k),
                             requeued: 0, blocked: 0, findings: 0, status: st });
/** One scan-work answer that completed and moved nothing. */
const idle = (stop, why, st) => ({ ok: true, stop, why, worked: 0, requeued: 0, blocked: 0,
                                   findings: 0, status: st || running(3, 10) });

(async function () {
  // -- starting ------------------------------------------------------------
  reset([{ ok: true, run_id: 7 }, { ok: true, stop: null, status: finished('complete-through-fence') }]);
  UV.start();
  await ticks(6);
  check('client: starting asks the server to start', sent[0].action === 'scan-start');
  check('client: and then asks for work on the run it was given',
    sent[1] && sent[1].action === 'scan-work' && sent[1].payload.run_id === 7);
  check('client: the run id is the ONLY thing it sends',
    Object.keys(sent[1].payload).length === 1);
  check('client: a finished run stops the loop', UV.state.running === false);

  // A refused start says why and starts nothing. Busy is deliberately
  // uninformative on the server side, and the client repeats it verbatim
  // rather than inventing a friendlier, more specific sentence.
  reset([{ ok: false, why: 'a validation scan is already running for this project' }]);
  UV.start();
  await ticks(4);
  check('client: a refused start does not begin a loop', UV.state.running === false);
  check('client: and shows the server\'s own words',
    els['uv-scan-note'].textContent.indexOf('already running') !== -1);
  check('client: without asking for work', sent.length === 1);

  // -- progress ------------------------------------------------------------
  reset([{ ok: true, run_id: 7 },
         { ok: true, status: running(3, 10) },
         { ok: true, status: finished('complete-through-fence') }]);
  UV.start();
  await ticks(6);
  check('client: progress is rendered as a fraction of the total',
    els['uv-scan-counts'].textContent.indexOf('3 of 10') === 0);
  check('client: with a percentage', els['uv-scan-counts'].textContent.indexOf('30%') !== -1);
  check('client: and the phase in words rather than its stored name',
    els['uv-scan-phase'].textContent === 'Checking records');

  // A run with no total yet must NOT render 0%. A bar sitting at zero for the
  // length of a planning phase reads as a stalled scan, and people stop scans
  // that look stalled.
  reset([{ ok: true, run_id: 7 }, { ok: true, status: running(0, 0) }]);
  UV.start();
  await ticks(6);
  check('client: an unknown total shows an indeterminate bar, not 0%',
    els['uv-scan-bar'].className.indexOf('indeterminate') !== -1);
  check('client: and says it is preparing rather than showing 0 of 0',
    els['uv-scan-counts'].textContent === 'Preparing');
  // N-C1. The indeterminate state used to be a bar at width 100%, which reads
  // as a run that has FINISHED — the module's founding complaint, in a progress
  // bar. The width now belongs to the stylesheet the page ships.
  check('client: and does not fill the bar, which would read as finished',
    els['uv-scan-bar'].style.width !== '100%');

  // -- the loop ends on a refusal -----------------------------------------
  //
  // A refused batch means this worker was cancelled or overtaken. Retrying
  // would be work done on behalf of a run that has already discarded it.
  reset([{ ok: true, run_id: 7 },
         { ok: false, why: 'this scan was cancelled or taken over while these records were '
                         + 'being examined, so nothing from them was kept' }]);
  UV.start();
  await ticks(6);
  check('client: a refused batch stops the loop', UV.state.running === false);
  check('client: and says what happened',
    els['uv-scan-note'].textContent.indexOf('cancelled or taken over') !== -1);

  // -- busy is a wait, not a failure --------------------------------------
  reset([{ ok: true, run_id: 7 },
         { ok: true, stop: 'capacity', worked: 0, status: running(0, 10) },
         { ok: true, status: finished('complete-through-fence') }]);
  UV.start();
  await ticks(6);
  check('client: a busy server does not stop the scan', UV.state.running === true);
  check('client: it says the scan will continue, rather than reporting a failure',
    els['uv-scan-note'].textContent.indexOf('busy') !== -1
    && els['uv-scan-note'].textContent.indexOf('continue') !== -1);
  check('client: and waits before asking again', pending.length === 1 && pending[0].ms >= 1000);

  // -- transport failure retries ------------------------------------------
  reset([{ ok: true, run_id: 7 }, { reject: true },
         { ok: true, status: finished('complete-through-fence') }]);
  UV.start();
  await ticks(6);
  check('client: losing the server is a retry, not an ending', UV.state.running === true);
  check('client: and says so', els['uv-scan-note'].textContent.indexOf('Lost contact') !== -1);
  check('client: with a backoff', pending.length === 1 && pending[0].ms >= 1000);

  // -- H4: an answer that moved nothing is a WAIT, on a ladder -------------
  //
  // stop:'waiting' means another worker holds the claim; that state persists
  // until the claim goes stale, which is fifteen minutes. Re-issuing at zero
  // milliseconds turned one open tab into tens of thousands of requests against
  // a shared REDCap. The bound on TOTAL requests is the assertion that matters:
  // a first-delay assertion cannot see a ladder that never climbs.
  reset([{ ok: true, run_id: 7 },
         idle('waiting', 'some records are still being examined, or were left behind by a '
                       + 'worker that stopped; this scan will pick them up shortly',
              running(3, 10))]);
  UV.start();
  await ticks(6);
  check('client: a pass that moved nothing does not re-ask immediately',
    pending.length === 1 && pending[0].ms >= 1500);
  let delays = await drive(8);
  const workCalls = sent.filter((s) => s.action === 'scan-work').length;
  check('client: the wait lengthens instead of repeating',
    delays.length === 8 && delays.every((d, i) => i === 0 || d >= delays[i - 1]));
  check('client: and reaches its ceiling', delays[delays.length - 1] === 30000);
  check('client: no wait after the first is zero', delays.every((d) => d > 0));
  check('client: nine rounds of nothing cost nine requests, not thousands',
    workCalls === 9);
  check('client: which is over a minute of wall clock',
    delays.reduce((a, b) => a + b, 0) > 60000);
  // H5. The server explains why nothing moved and the client used to blank that
  // explanation on every successful answer, so the operator watched a frozen
  // bar, an unchanged count and an empty line.
  check('client: the server\'s own explanation stays on screen',
    els['uv-scan-note'].textContent.indexOf('still being examined') !== -1);
  check('client: and once the ladder is at its ceiling it says the run is stuck',
    els['uv-scan-note'].textContent.indexOf('Nothing has progressed') !== -1);
  check('client: telling the reader the scan survives leaving the page',
    els['uv-scan-note'].textContent.indexOf('leave this page') !== -1);
  // ...and says it ONCE. #uv-scan-note is itself a polite live region (the panel
  // markup is asserted in tests/scan_page_php.php), so echoing it into the
  // off-screen region would announce every stall twice.
  check('client: and a screen reader is not told it twice',
    els['uv-scan-announce'].textContent.indexOf('still being examined') === -1);

  // The same must hold for stop:null. Two of the four no-work answers carry no
  // stop string at all, so a fix keyed on the stop string fixes half of them.
  reset([{ ok: true, run_id: 7 }, idle(null, null, running(3, 10))]);
  UV.start();
  await ticks(6);
  delays = await drive(4);
  check('client: an answer with no stop string backs off too',
    pending.length <= 1 && delays.length === 4 && delays[0] === 1500 && delays[3] === 12000);
  check('client: and says something rather than nothing',
    els['uv-scan-note'].textContent.indexOf('not progressing') !== -1);

  // N-C2. The capacity path reset the ladder before reading it, so it polled a
  // server that was already at its limit once a second, per tab, forever.
  reset([{ ok: true, run_id: 7 }, idle('capacity', null, running(0, 10))]);
  UV.start();
  await ticks(6);
  delays = await drive(5);
  check('client: a busy server is asked less and less often, not once a second',
    delays[0] === 1500 && delays[4] === 24000);

  // -- a pass that DID work is re-issued at once ---------------------------
  //
  // The server's own three-second budget is the rate limiter on the happy path.
  // A client delay there would halve throughput and buy nothing — and planning
  // works zero RECORDS while doing real work, so the predicate is progress, not
  // records examined.
  reset([{ ok: true, run_id: 7 }, worked(running(3, 10), 5)]);
  UV.start();
  await ticks(6);
  check('client: a pass that examined records asks again immediately',
    pending.length === 1 && pending[0].ms === 0);
  reset([{ ok: true, run_id: 7 },
         { ok: true, stop: null, worked: 0, listed: 400, status: running(0, 0) }]);
  UV.start();
  await ticks(6);
  check('client: a pass that listed records but examined none is progress too',
    pending.length === 1 && pending[0].ms === 0);
  // And the note is cleared by a pass that moved, which is where blanking it
  // legitimately belongs.
  reset([{ ok: true, run_id: 7 }, idle('waiting', 'held by another worker', running(3, 10)),
         worked(running(8, 10), 5)]);
  UV.start();
  await ticks(6);
  check('client: an explanation is on screen while nothing moves',
    els['uv-scan-note'].textContent.indexOf('held by another worker') !== -1);
  fire(); await ticks(6);
  check('client: and is cleared by a pass that moved the run',
    els['uv-scan-note'].textContent === '');

  // -- H6: the transport may not throw ------------------------------------
  //
  // UV.ajax is assigned by the page from the framework's module object, and
  // calling a method on an object the framework never created is a synchronous
  // TypeError. It escaped call(), escaped the click handler, and left the Start
  // button dead and silent with no message at all.
  reset([]);
  throwOn = '*';
  let escaped = null;
  try { UV.start(); } catch (e) { escaped = e; }
  await ticks(6);
  check('client: a transport that throws does not throw out of start()', escaped === null);
  check('client: nothing is left running', UV.state.running === false);
  check('client: and the panel names the piece that is missing',
    els['uv-scan-note'].textContent.indexOf('transport') !== -1
    && els['uv-scan-note'].textContent.indexOf('nothing was') !== -1);

  // A throw AFTER the server started the run must not print a sentence that
  // contradicts the server: the run exists and holds the project's slot.
  reset([{ ok: true, run_id: 7 }]);
  throwOn = 'scan-work';                 // the start goes through; the first work call does not
  UV.start();
  await ticks(8);
  check('client: a throw after the run started does not claim it never started',
    els['uv-scan-note'].textContent.indexOf('could not be started') === -1);
  check('client: it says the scan is on the server and unaffected',
    els['uv-scan-note'].textContent.indexOf('on the server') !== -1);
  check('client: the loop is stopped rather than left running', UV.state.running === false);
  check('client: and the panel re-reads the run so Continue is offered',
    sent.some((s) => s.action === 'scan-status'));

  // A missing transport is not a lost connection, and must not be retried on a
  // ladder that can never succeed.
  reset([]);
  const ajax = UV.ajax;
  delete UV.ajax;
  UV.state.runId = 7;
  UV.resume();
  await ticks(8);
  check('client: a missing transport is not retried', pending.length === 0);
  check('client: and is not reported as a lost connection',
    els['uv-scan-note'].textContent.indexOf('Lost contact') === -1);
  check('client: it is reported as the missing transport it is',
    els['uv-scan-note'].textContent.indexOf('transport') !== -1);
  check('client: and the loop is not left running', UV.state.running === false);
  UV.ajax = ajax;

  // -- M6: one tab drives one chain ---------------------------------------
  //
  // Continue was not guarded at all: three impatient clicks on a panel that had
  // been silent for a minute produced three independent, permanent pump chains
  // from one tab, and three times the request rate.
  reset([{ ok: true, run_id: 7 }, worked(running(1, 10), 1)]);
  UV.state.runId = 7;
  UV.attach({});
  els['uv-scan-resume'].click();
  els['uv-scan-resume'].click();
  els['uv-scan-resume'].click();
  await ticks(6);
  check('client: three clicks on Continue make one request, not three',
    sent.filter((s) => s.action === 'scan-work').length === 1);
  check('client: and queue one timer, not three', pending.length === 1);
  delays = await drive(3);
  check('client: which stays one chain as it runs',
    sent.filter((s) => s.action === 'scan-work').length === 4);

  // A timer queued by a chain that has since been superseded still fires. The
  // chain token is what stops it rejoining the loop.
  reset([{ ok: true, run_id: 7 }, idle('waiting', 'held', running(3, 10))]);
  UV.start();
  await ticks(6);
  check('client: a stalled chain has one timer waiting', pending.length === 1);
  UV.cancel();                                     // supersedes the chain
  await ticks(4);
  const before = sent.filter((s) => s.action === 'scan-work').length;
  fire(); await ticks(6);
  check('client: the orphaned timer does not resume the loop',
    sent.filter((s) => s.action === 'scan-work').length === before);

  // The buttons are disabled while a request is out, which is the same guard a
  // browser applies to a double click; Stop is deliberately NOT, because a work
  // request takes seconds and a Stop that is dead throughout is not a Stop.
  reset([{ ok: true, run_id: 7 }, idle('waiting', 'held', running(3, 10))]);
  UV.start();
  check('client: Start is disabled while a request is in flight',
    els['uv-scan-start'].disabled === true);
  check('client: Stop is not', els['uv-scan-cancel'].disabled === false);
  await ticks(8);
  check('client: and is enabled again once the answer arrives',
    els['uv-scan-start'].disabled === false);

  // -- M7: the panel's ARIA contract --------------------------------------
  //
  // The module holds itself to this for a field's verdict (js/engine.js, and
  // tests/a11y_dom_js.cjs asserts it). The scan panel had not one ARIA attribute
  // on it, so every state change of a multi-minute operation was silent.
  reset([{ ok: true, run_id: 7 }, worked(running(3, 10), 3)]);
  UV.start();
  await ticks(6);
  check('a11y: the bar reports its position',
    els['uv-scan-bar-track'].getAttribute('aria-valuenow') === '30');
  check('a11y: in records, not only in percent',
    els['uv-scan-bar-track'].getAttribute('aria-valuetext') === '3 of 10 records');
  check('a11y: and the panel says it is busy',
    els['uv-scan-panel'].getAttribute('aria-busy') === 'true');
  check('a11y: the phase and the counts are announced once',
    els['uv-scan-announce'].textContent.indexOf('Checking records') === 0
    && els['uv-scan-announce'].textContent.indexOf('3 of 10') !== -1);

  // An unknown total is INDETERMINATE, and the ARIA half of that is an absent
  // aria-valuenow — not 0, which announces a stalled run, and not 100, which
  // announces a finished one.
  reset([{ ok: true, run_id: 7 }, { ok: true, status: running(0, 0) }]);
  UV.start();
  await ticks(6);
  check('a11y: an unknown total reports no value at all',
    els['uv-scan-bar-track'].getAttribute('aria-valuenow') === null);

  // A poll lands every few seconds. Announcing every one of them makes the page
  // unusable with a screen reader, so a step inside the same ten-percent band
  // and the same phase says nothing new.
  reset([{ ok: true, run_id: 7 }, worked(running(30, 100), 1), worked(running(31, 100), 1)]);
  UV.start();
  await ticks(6);
  const announced = els['uv-scan-announce'].textContent;
  fire(); await ticks(6);
  check('a11y: one more record inside the same band is not announced again',
    els['uv-scan-announce'].textContent === announced);

  // -- what a finished run is allowed to say -------------------------------
  //
  // Three different coverages, three different sentences. The whole rebuild
  // exists because one word covered a run that examined everything and a run
  // that examined nothing.
  reset([{ ok: true, run_id: 7 }, { ok: true, status: finished('complete-through-fence') }]);
  UV.start(); await ticks(6);
  const fenced = els['uv-scan-done'].textContent;
  check('client: a fenced run says every record was checked',
    fenced.indexOf('Every record was checked') === 0);

  reset([{ ok: true, run_id: 7 }, { ok: true, status: finished('manifest-complete') }]);
  UV.start(); await ticks(6);
  const manifest = els['uv-scan-done'].textContent;
  check('client: a manifest-only run says something DIFFERENT', manifest !== fenced);
  check('client: naming what it could not prove',
    manifest.indexOf('cannot prove') !== -1);

  reset([{ ok: true, run_id: 7 }, { ok: true, status: finished('partial') }]);
  UV.start(); await ticks(6);
  check('client: a partial run says it is not a complete picture',
    els['uv-scan-done'].textContent.indexOf('not a complete picture') !== -1);

  // The client holds no coverage vocabulary of its own - it renders whatever
  // ScanPageView::labels() handed it - so this pins that a NEW coverage value
  // arrives as its own sentence rather than as the unknown-value fallback.
  // That drift is exactly what the label tables did the last time a constant
  // landed in PHP without its client entry.
  reset([{ ok: true, run_id: 7 }, { ok: true, status: finished('empty-scope') }]);
  UV.start(); await ticks(6);
  check('client: an empty-scope run gets its own sentence, not the unknown fallback',
    els['uv-scan-done'].textContent.indexOf('No records were in scope') === 0
    && els['uv-scan-done'].textContent.indexOf('does not recognise') === -1);
  check('client: and it never claims a record was checked',
    els['uv-scan-done'].textContent.indexOf('Every record was checked') === -1
    && els['uv-scan-done'].textContent.indexOf('nothing was checked') !== -1);

  // Truncated detail is stated even on a run whose coverage was complete: the
  // report the reader holds is not the report the run produced.
  reset([{ ok: true, run_id: 7 },
         { ok: true, status: Object.assign(finished('complete-through-fence'),
             { detail: 'truncated' }) }]);
  UV.start(); await ticks(6);
  check('client: a truncated report says findings were not kept',
    els['uv-scan-done'].textContent.indexOf('not kept') !== -1);

  // -- L1: a coverage this build does not know ----------------------------
  //
  // The old code wrote COVERAGE[st.coverage] || '' and then revealed the
  // paragraph unconditionally, so a value from a newer server produced a
  // VISIBLE, EMPTY completion sentence — a blank certificate over a finished
  // run, which is the one outcome this module refuses.
  reset([{ ok: true, run_id: 7 }, { ok: true, status: finished('some-future-value') }]);
  UV.start(); await ticks(6);
  check('client: an unrecognised coverage never renders a blank certificate',
    els['uv-scan-done'].style.display === 'none'
    || els['uv-scan-done'].textContent.trim().length > 0);
  check('client: it names the value, so somebody can find out what it means',
    els['uv-scan-done'].textContent.indexOf('some-future-value') !== -1);
  check('client: and says the run should not be trusted as complete',
    els['uv-scan-done'].textContent.indexOf('incomplete') !== -1);

  reset([{ ok: true, run_id: 7 },
         { ok: true, status: Object.assign(finished('some-future-value'),
             { detail: 'truncated' }) }]);
  UV.start(); await ticks(6);
  check('client: and the truncation note does not become a headless sentence',
    els['uv-scan-done'].textContent[0] !== ' '
    && els['uv-scan-done'].textContent.indexOf('not kept') !== -1);

  // A build the page never handed labels to still renders. It reads worse, and
  // it reads: an empty phase and an empty certificate are how a panel stops
  // being able to say anything at all.
  const keep = UV.labels;
  delete UV.labels;
  reset([{ ok: true, run_id: 7 }, { ok: true, status: finished('complete-through-fence') }]);
  UV.start(); await ticks(6);
  check('client: with no labels the phase falls back to its stored name',
    els['uv-scan-phase'].textContent === 'terminal');
  check('client: and the certificate still says something',
    els['uv-scan-done'].textContent.length > 0);
  UV.labels = keep;

  // -- completion comes from the server -----------------------------------
  //
  // done === total is NOT the end. Catch-up, the duplicate finalizer and the
  // summary all run after the last record, and a client that stopped here would
  // report a scan as finished while it was still deciding whether two records
  // share a hospital number.
  reset([{ ok: true, run_id: 7 },
         worked(running(10, 10), 5),
         { ok: true, status: finished('complete-through-fence') }]);
  UV.start();
  await ticks(6);
  check('client: every record scanned does NOT mean the run is over',
    UV.state.running === true);
  fire(); await ticks(4);
  check('client: the server saying terminal is what ends it', UV.state.running === false);

  // -- cancelling ----------------------------------------------------------
  reset([{ ok: true, run_id: 7 }, { ok: true, status: running(1, 10) }]);
  UV.start();
  await ticks(6);
  sent.length = 0;
  script = [{ ok: true, why: null }, { ok: true, phase: 'cancelling', active: true }];
  UV.cancel();
  await ticks(4);
  check('client: cancelling stops the local loop at once', UV.state.running === false);
  check('client: and asks the server to cancel', sent[0].action === 'scan-cancel');
  check('client: sending only the run id', sent[0].payload.run_id === 7);

  // -- attaching to a run that is already going ---------------------------
  //
  // Watched, not resumed. This tab may have been opened beside one already
  // driving the run, and two drivers would both be refused by the lease anyway.
  reset([{ ok: true, status: running(5, 10) }]);
  UV.attach({ runId: 42, autoResume: false });
  await ticks(4);
  check('client: attaching asks for status', sent[0].action === 'scan-status');
  check('client: on the run the page named', sent[0].payload.run_id === 42);
  check('client: and does not start working on it uninvited', UV.state.running === false);

  process.stdout.write('scan_client_js: ' + n + ' checks, ' + fail + ' failure(s)\n');
  process.exit(fail ? 1 : 0);
})();
