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
 'uv-scan-note', 'uv-scan-announce', 'uv-scan-stalled', 'uv-scan-stored'].forEach((id) => {
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
    'empty-scope': 'This scan has no record left to report on: none was in scope, or every one was '
                 + 'deleted while it ran, whether or not it had been checked. Any findings it '
                 + 'counted are on deleted records. This result says nothing about the project.',
  },
  coverageUnknown: 'This scan finished, but this page does not recognise the result it recorded '
                 + '({value}). Treat it as incomplete and run it again.',
  truncated: 'Some findings were not kept, because the scan reached the limit this project allows.',
  stalled: 'No progress has been recorded on this scan for {minutes} minutes. If it does not move '
         + 'soon, stop it and start a new one, or ask your REDCap administrator to check the '
         + 'External Modules log.',
  paused: 'This scan is paused: nothing has worked on it for {minutes} minutes, which is what '
        + 'happens when the page that was running it is closed. Nothing has been lost. Press '
        + 'Continue to carry on from where it stopped.',
  stopping: 'This scan was asked to stop and has not finished stopping. Press Continue to finish '
          + 'stopping it.',
  plain: '{done} of {total} records',
  checked: '{done} of {total} records checked',
  excluded: '{n} could not be checked',
  removed: '{n} deleted during the scan',
  foundUnknown: 'Findings not counted, because the scan ended before it could total them',
  stored: 'Stored: {rows}',
  storedValues: 'Stored: {rows}, with {size} of values',
  notCertified: 'This result does not certify the project.',
  ruleProblem: '1 rule could not be evaluated, so this result does not certify the project.',
  ruleProblems: '{n} rules could not be evaluated, so this result does not certify the project.',
  withheld: 'This scan covers one Data Access Group, so its counts are shown once it has confirmed '
          + 'which records belong to that group.',
  withheldEnded: 'This scan covers one Data Access Group and ended before it confirmed which '
               + 'records belong to that group, so its counts cannot be shown.',
  stoppedWithheld: 'This scan was stopped before it confirmed which records belong to this Data '
                 + 'Access Group, so it is not a complete picture of the group.',
  stoppedProved: 'This scan was stopped after it confirmed which records belong to this Data Access '
               + 'Group, so its counts are for the group. It ended before every check had run, so it '
               + 'is not a complete picture of the group.',
  upgraded: 'This module was upgraded while this scan was running, and the new version judges some '
          + 'values differently, so the scan was ended rather than finished half under each version. '
          + 'Start a new scan.',
  emptyLeft: 'This scan has no record left to report on: every record it listed moved to another '
           + 'Data Access Group while it ran. Any findings it counted are on records no longer in '
           + 'the group. This result says nothing about the group.',
  emptyLeftDeleted: 'This scan has no record left to report on: every record it listed moved to '
                  + 'another Data Access Group or was deleted while it ran. Any findings it counted '
                  + 'are on records no longer in the group. This result says nothing about the group.',
  none: '0 records',
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
  UV.state.watchChain++;               // a watch from an earlier test dies quietly
  UV.state.watchWait = 0;
  UV.state.stopNote = null;
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
    els['uv-scan-done'].textContent.indexOf('This scan has no record left to report on') === 0
    && els['uv-scan-done'].textContent.indexOf('does not recognise') === -1);
  check('client: and it never claims a record was checked',
    els['uv-scan-done'].textContent.indexOf('Every record was checked') === -1);
  // R2O-1. Nor that NONE was: records checked and then deleted keep their
  // findings, and the panel counts them. Both lines, as the client renders an
  // empty-scope run whose records were examined before they were deleted.
  reset([{ ok: true, run_id: 7 }, { ok: true, status: Object.assign(finished('empty-scope'),
    { terminal: 'partial', done: 0, settled: 2, removed: 2, total: 2, findings: 2, clean: 0 }) }]);
  UV.start(); await ticks(6);
  check('client: R2O-1 an empty scope with findings does not say nothing was checked beside them',
    els['uv-scan-found'].textContent === '2 findings so far'
    && els['uv-scan-done'].textContent.toLowerCase().indexOf('nothing was checked') === -1
    && els['uv-scan-done'].textContent.indexOf('whether or not it had been checked') !== -1
    && els['uv-scan-done'].textContent.indexOf('Any findings it counted are on deleted records') !== -1);
  // rvf1-2. A group whose every record moved away is an empty scope too, and
  // was told its records had been deleted, under "Preparing".
  const leftEmpty = (over) => Object.assign(finished('empty-scope'),
    { terminal: 'partial', total: 0, done: 0, settled: 0, removed: 0, findings: 0, clean: 0, leftGroup: true },
    over || {});
  reset([{ ok: true, run_id: 7 }, { ok: true, status: leftEmpty() }]);
  UV.start(); await ticks(6);
  check('client rvf1-2: an empty scope its records left says they moved, not that they were deleted',
    els['uv-scan-done'].textContent.indexOf(UV.labels.emptyLeft) === 0
    && els['uv-scan-done'].textContent.indexOf('deleted') === -1);
  check('client rvf1-2: and a finished run with no record in its total shows 0 records, not Preparing',
    els['uv-scan-counts'].textContent === '0 records');
  reset([{ ok: true, run_id: 7 }, { ok: true, status: leftEmpty({ total: 2, removed: 2, settled: 2 }) }]);
  UV.start(); await ticks(6);
  check('client rvf1-2: some moved and the rest were deleted, and it says so',
    els['uv-scan-done'].textContent.indexOf(UV.labels.emptyLeftDeleted) === 0);
  reset([{ ok: true, run_id: 7 }, { ok: true, status: leftEmpty({ leftGroup: false }) }]);
  UV.start(); await ticks(6);
  check('client rvf1-2 control: an empty scope no record left keeps the empty-scope sentence',
    els['uv-scan-done'].textContent.indexOf(UV.labels.coverage['empty-scope']) === 0);
  const keptLeftLabels = UV.labels;
  UV.labels = Object.assign({}, keptLeftLabels, { emptyLeft: undefined, none: undefined });
  reset([{ ok: true, run_id: 7 }, { ok: true, status: leftEmpty() }]);
  UV.start(); await ticks(6);
  check('client rvf1-2: and without the page\'s labels its own words are the server\'s',
    els['uv-scan-done'].textContent.indexOf(keptLeftLabels.emptyLeft) === 0
    && els['uv-scan-counts'].textContent === keptLeftLabels.none);
  UV.labels = keptLeftLabels;

  // W10BF-3. A finished run says the day its date rules were judged as of,
  // last; a status without one, or with something that is not a day, says
  // nothing of it.
  reset([{ ok: true, run_id: 7 }, { ok: true, status: Object.assign(finished('complete-through-fence'),
    { asOf: '2026-10-21' }) }]);
  UV.start(); await ticks(6);
  const judged = els['uv-scan-done'].textContent;
  check('client: W10BF-3 a finished run ends with the day it was judged as of',
    judged.indexOf('Every record was checked') === 0
    && judged.slice(-'Dates were judged as of 2026-10-21.'.length) === 'Dates were judged as of 2026-10-21.');
  reset([{ ok: true, run_id: 7 }, { ok: true, status: Object.assign(finished('complete-through-fence'),
    { asOf: '<b>2026</b>' }) }]);
  UV.start(); await ticks(6);
  check('client: W10BF-3 and a value that is not a day is not shown',
    els['uv-scan-done'].textContent === fenced);

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
  check('client: a stop the server has only asked for says it is stopping',
    els['uv-scan-note'].textContent === 'Stopping the scan.');

  // R2O-5. A run nobody was working is ENDED by Stop, and the server says so;
  // "Stopping" over the finished panel would be the one stale word on it.
  reset([{ ok: true, run_id: 7 }, { ok: true, status: running(1, 10) }]);
  UV.start();
  await ticks(6);
  sent.length = 0;
  script = [{ ok: true, why: null, stopped: true },
            Object.assign(finished('partial'), { terminal: 'cancelled' })];
  UV.cancel();
  await ticks(6);
  check('client: R2O-5 a stop the server finished says the scan was stopped',
    els['uv-scan-note'].textContent === 'The scan was stopped.');
  // The driver's own queued pass is an orphan of the chain Stop superseded.
  const askedR5 = sent.length;
  await drive(3);
  check('client: R2O-5 and the panel shows it finished, with no stopping sentence and nothing more asked',
    els['uv-scan-done'].style.display === '' && els['uv-scan-stalled'].style.display === 'none'
    && els['uv-scan-start'].style.display === '' && sent.length === askedR5);

  // R3U-5. The commonest Stop is pressed while this tab's own work request is
  // in flight: the server sees the run held, can only ASK, and answers
  // stopped:false - and that request then ends it. "Stopping the scan." was
  // written once and stayed under the finished panel for as long as the page
  // was open. The first terminal status the watch gets replaces it.
  const stoppingSt = Object.assign(running(1, 10), { phase: 'cancelling', stopping: true, idleSeconds: 2 });
  const cancelledSt = Object.assign(finished('partial'), { terminal: 'cancelled' });
  const stopThen = async (ending, meddle) => {
    reset([{ ok: true, run_id: 7 }, { ok: true, status: running(1, 10) }]);
    UV.start();
    await ticks(6);
    sent.length = 0;
    script = [{ ok: true, why: null, stopped: false }, stoppingSt, ending];
    UV.cancel();
    await ticks(6);
    const asked = els['uv-scan-note'].textContent;
    if (meddle) els['uv-scan-note'].textContent = meddle;
    const delays = await drive(4);
    return { asked, delays, note: els['uv-scan-note'].textContent };
  };
  let r5 = await stopThen(cancelledSt);
  check('client: R3U-5 a stop the server could only ask for says it is stopping',
    r5.asked === 'Stopping the scan.');
  check('client: R3U-5 and once the run is over the note says it was stopped',
    r5.note === 'The scan was stopped.' && els['uv-scan-done'].style.display === '');
  check('client: R3U-5 and the watch asked again on its ladder, never at 0 ms',
    r5.delays.length > 0 && r5.delays.every((ms) => ms > 0)
    && !sent.some((s) => s.action === 'scan-work'));
  r5 = await stopThen(finished('complete-through-fence'));
  check('client: R3U-5 a run that ended some other way says so, not that it was stopped',
    r5.note === 'The scan ended before it could be stopped.');
  r5 = await stopThen(cancelledSt, 'A sentence somebody else wrote.');
  check('client: R3U-5 a note written since the Stop is not Stop\'s to replace',
    r5.note === 'A sentence somebody else wrote.');

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

  // -- CL-4: a watching tab keeps watching ---------------------------------
  //
  // It asked scan-status once and never again, so a stall that began later was
  // never shown and a stall sentence that stopped being true never came down.
  // It now asks again on the no-progress ladder while the run is active, and
  // stops when it is not.
  reset([running(5, 10)]);                 // the transport repeats this for ever
  UV.attach({ runId: 42, autoResume: false });
  await ticks(4);
  check('watch: an active run that this tab is only watching is asked about again',
    pending.length === 1 && pending[0].ms === 1500);
  let wd = await drive(12);
  const statusAsks = sent.filter((s) => s.action === 'scan-status').length;
  check('watch: on the no-progress ladder - 1.5 s doubling to 30 s, never 0 ms',
    wd.slice(0, 7).join(',') === '1500,3000,6000,12000,24000,30000,30000'
    && wd.every((ms) => ms > 0));
  check('watch: one status request per step, and never a scan-work',
    statusAsks === 13 && !sent.some((s) => s.action === 'scan-work'));
  check('watch: still only watching', UV.state.running === false && pending.length === 1);

  // A stall the server reports later is shown; one it stops reporting is taken down.
  reset([running(5, 10), Object.assign(running(5, 10), { stalled: true, idleSeconds: 300 }),
         running(6, 10)]);
  UV.attach({ runId: 42, autoResume: false });
  await ticks(4);
  check('watch: a run moving when the tab opened shows no stall', els['uv-scan-stalled'].style.display === 'none');
  await drive(1);
  check('watch: a stall that began afterwards is shown to the watcher',
    els['uv-scan-stalled'].style.display === ''
    && els['uv-scan-stalled'].textContent.indexOf('No progress has been recorded') === 0);
  await drive(1);
  check('watch: and taken down once the run moves again',
    els['uv-scan-stalled'].style.display === 'none' && els['uv-scan-stalled'].textContent === '');

  // A terminal run ends the watching.
  reset([running(5, 10), finished('complete-through-fence')]);
  UV.attach({ runId: 42, autoResume: false });
  await ticks(4);
  await drive(1);
  check('watch: a run that finishes stops the watching - no timer is left',
    els['uv-scan-done'].style.display === '' && pending.length === 0);
  await drive(5);
  check('watch: and nothing more is asked', sent.length === 2);

  // A refusal the server marks FINAL ends it too: there is no such run, or
  // this tab may not look, and asking again gets the same answer.
  reset([{ ok: false, why: 'no scan with that reference is running for this project', final: true }]);
  UV.attach({ runId: 42, autoResume: false });
  await ticks(4);
  check('watch: a refused status the server calls final is not asked again', pending.length === 0);

  // R2O-4. ANY OTHER REFUSAL IS A MOMENT. The endpoint's catch-all answers a
  // database blip with ok:false; the watch ended on it, kept the paused
  // sentence from the answer before, and never learned the run finished.
  const blip = { ok: false, why: 'the validation scan could not be reached; ask an administrator to '
                                 + 'check the module log' };
  reset([Object.assign(running(5, 10), { paused: true, idleSeconds: 600 }), blip,
         finished('complete-through-fence')]);
  UV.attach({ runId: 42, autoResume: false });
  await ticks(4);
  check('watch: R2O-4 the watcher is shown the paused sentence first',
    els['uv-scan-stalled'].textContent.indexOf('This scan is paused') === 0);
  await drive(1);
  check('watch: R2O-4 a refusal that is not final is asked again, on the ladder, never at once',
    pending.length === 1 && pending[0].ms === 3000);
  check('watch: R2O-4 and the paused sentence does not outlive the answer that contradicted it',
    els['uv-scan-stalled'].style.display === 'none' && els['uv-scan-stalled'].textContent === ''
    && els['uv-scan-note'].textContent === blip.why);
  await drive(1);
  check('watch: R2O-4 so the tab learns the run finished',
    els['uv-scan-done'].style.display === '' && els['uv-scan-done'].textContent.indexOf('Every record') === 0
    && pending.length === 0);
  check('watch: R2O-4 and the refusal sentence comes down with the answer that ended it',
    els['uv-scan-note'].textContent === '');
  // A server that sends no `final` at all - an older build, or the shutdown
  // handler - is treated the same way: asked again, never at 0 ms.
  reset([blip]);
  UV.attach({ runId: 42, autoResume: false });
  await ticks(4);
  const bd = await drive(6);
  check('watch: R2O-4 a refusal repeated for ever is asked on the climbing ladder, never at 0 ms',
    bd.slice(0, 5).join(',') === '1500,3000,6000,12000,24000' && bd.every((ms) => ms > 0)
    && !sent.some((s) => s.action === 'scan-work'));
  // A note somebody else wrote after the refusal is not the watch's to clear.
  reset([blip, running(5, 10)]);
  UV.attach({ runId: 42, autoResume: false });
  await ticks(4);
  els['uv-scan-note'].textContent = 'Stopping the scan.';
  await drive(1);
  check('watch: R2O-4 it clears only its own sentence',
    els['uv-scan-note'].textContent === 'Stopping the scan.');

  // A status that did not arrive is retried on the same ladder, never at once.
  reset([{ reject: true }, running(5, 10)]);
  UV.attach({ runId: 42, autoResume: false });
  await ticks(4);
  check('watch: a lost status is asked again after the ladder, not immediately',
    pending.length === 1 && pending[0].ms === 1500);

  // Pressing Continue ends the watching: the driver renders every answer itself.
  reset([running(5, 10), worked(running(6, 10)), worked(running(7, 10))]);
  UV.attach({ runId: 42, autoResume: false });
  await ticks(4);
  const watchTimer = pending.shift();      // the watcher's queued poll, held back
  UV.resume();
  await ticks(6);
  const before4 = sent.filter((s) => s.action === 'scan-status').length;
  if (watchTimer) watchTimer.fn();
  await ticks(6);
  check('watch: once this tab drives, an old watching timer asks nothing',
    !!watchTimer && sent.filter((s) => s.action === 'scan-status').length === before4);
  UV.state.running = false;
  UV.state.chain++;

  // -- what was examined, and what only LOOKS like progress (L4) -------------
  //
  // `done` was every record in a terminal state, so a run in which no result
  // could be stored read "10 of 10 records (100%)" above an empty report. The
  // server now reports what was examined and what was not, separately, and the
  // bar follows `settled` so it still reaches the end.
  const counted = (over) => Object.assign(running(8, 10), over);
  // scan-status answers with the status ITSELF, not wrapped in a work reply.
  const show = async (st) => {
    reset([st]);
    UV.attach({ runId: 42, autoResume: false });
    await ticks(4);
  };
  await show(counted({ done: 3, settled: 3, excluded: 0, removed: 0 }));
  check('counts: with nothing excluded the line is exactly what it always was',
    els['uv-scan-counts'].textContent === '3 of 10 records  (30%)');
  await show(counted({ done: 8, settled: 10, excluded: 2, removed: 0 }));
  check('counts: records that could not be checked are SAID, not folded into the total',
    els['uv-scan-counts'].textContent === '8 of 10 records checked; 2 could not be checked  (100%)');
  check('counts: and the bar still reaches the end, because the run did',
    els['uv-scan-bar'].style.width === '100%'
    && els['uv-scan-bar-track']._attrs['aria-valuenow'] === '100');
  check('counts: a screen reader is told the same thing the eye is',
    els['uv-scan-bar-track']._attrs['aria-valuetext']
      === '8 of 10 records checked; 2 could not be checked');
  await show(counted({ done: 7, settled: 10, excluded: 2, removed: 1 }));
  check('counts: a record deleted during the scan is its own clause',
    els['uv-scan-counts'].textContent
      === '7 of 10 records checked; 2 could not be checked; 1 deleted during the scan  (100%)');
  await show(counted({ done: 0, settled: 10, excluded: 10, removed: 0 }));
  check('counts: a run that examined NOTHING does not read as one that examined everything',
    els['uv-scan-counts'].textContent.indexOf('0 of 10 records checked') === 0
    && els['uv-scan-counts'].textContent.indexOf('10 of 10') === -1);
  // A server that has not been upgraded sends no `settled`. The old `done` had
  // that meaning, so the bar reads it and nothing is excluded.
  await show(running(5, 10));
  check('counts: a status with no breakdown renders as it used to',
    els['uv-scan-counts'].textContent === '5 of 10 records  (50%)'
    && els['uv-scan-bar'].style.width === '50%');

  // -- a stall the WATCHING tab can see (L5) --------------------------------
  //
  // The tab driving a scan already knows when a pass made no progress and says
  // so. The tab that cannot know is the one opened beside it, or an hour later.
  await show(counted({ stalled: true, idleSeconds: 330 }));
  check('stalled: a watching tab is told, in the page\'s own sentence',
    els['uv-scan-stalled'].style.display === ''
    && els['uv-scan-stalled'].textContent.indexOf('for 5 minutes') !== -1
    && els['uv-scan-stalled'].textContent.indexOf('{') === -1);
  await show(counted({ stalled: false, idleSeconds: 4 }));
  check('stalled: a run that is moving shows nothing, and leaves no sentence behind',
    els['uv-scan-stalled'].style.display === 'none' && els['uv-scan-stalled'].textContent === '');
  await show(Object.assign(finished('partial'), { stalled: true, idleSeconds: 9000 }));
  check('stalled: a finished run is never stalled, whatever it is sent',
    els['uv-scan-stalled'].style.display === 'none');
  // CL-2. A run nobody is working on is PAUSED: the server says so, and the
  // reader is told to press Continue - not to stop it and start a new one.
  await show(counted({ paused: true, idleSeconds: 600 }));
  check('paused: a watching tab is told the run is paused and to press Continue',
    els['uv-scan-stalled'].style.display === ''
    && els['uv-scan-stalled'].textContent.indexOf('This scan is paused') === 0
    && els['uv-scan-stalled'].textContent.indexOf('for 10 minutes') !== -1
    && els['uv-scan-stalled'].textContent.indexOf('start a new one') === -1);
  await show(Object.assign(counted({ stopping: true, stalled: true, idleSeconds: 9000 }),
                           { phase: 'cancelling' }));
  check('stopping: a run that was asked to stop says so, never that it stalled',
    els['uv-scan-stalled'].textContent === UV.labels.stopping);
  await show(Object.assign(finished('partial'), { paused: true, stopping: true }));
  check('paused: a finished run is never paused or stopping, whatever it is sent',
    els['uv-scan-stalled'].style.display === 'none');

  // rvc2-3. A REFUSED STATUS THAT SAYS THIS READER MAY STOP THE RUN SHOWS
  // STOP. Stop was only ever shown from a status that arrived, so a run whose
  // configuration could not be read in full kept going with no way to end it
  // from the page. The refusal still names no count and no finding.
  const stoppable = (active) => ({ ok: false, final: false, run_id: 42, active: active, mayCancel: true,
    why: 'the scan context could not be built: the growth reference folder could not be read just now; try again shortly' });
  const refusedFrom = async (shown, st) => {
    reset([st]);
    els['uv-scan-cancel'].style.display = shown;
    UV.attach({ runId: 42, autoResume: false });
    await ticks(4);
    return els['uv-scan-cancel'].style.display;
  };
  check('rvc2-3: a refused status that offers Stop on an active run shows Stop',
    (await refusedFrom('none', stoppable(true))) === ''
    && els['uv-scan-note'].textContent.indexOf('could not be read just now') !== -1
    && els['uv-scan-counts'].textContent === '');
  check('rvc2-3: and hides it when the run has ended',
    (await refusedFrom('', stoppable(false))) === 'none');
  const plain = { ok: false, final: false, why: 'the scan context could not be built' };
  check('rvc2-3: a refusal that offers nothing leaves a shown Stop shown',
    (await refusedFrom('', plain)) === '');
  check('rvc2-3: and a hidden one hidden',
    (await refusedFrom('none', plain)) === 'none');
  // rv11b1-8. A reader barred only from an instrument a rule's operand sits
  // on (W11A-N3) is refused status, FINAL, and may still stop the run. This
  // is the service's own answer for them (tools/temporal_pfx_g_status.php,
  // pinned in tests/scan_service_php.php "refused a run and no run alike"):
  // the page shows Stop, and Stop sends the cancel for that run.
  const barred = { ok: false, final: true, run_id: 42, active: true, mayCancel: true,
    why: 'this scan reads instrument(s) you do not have access to, so the whole report is refused rather '
       + 'than quietly narrowed: fb' };
  check('rv11b1-8: a final refusal for a reader who may still stop the run shows Stop',
    (await refusedFrom('none', barred)) === ''
    && els['uv-scan-note'].textContent === barred.why && els['uv-scan-counts'].textContent === '');
  const askedAfter = sent.length;
  await ticks(4);
  for (let i = 0; i < 6 && pending.length; i++) { const t = pending.shift(); t.fn(); await ticks(4); }
  check('rv11b1-8: and the final refusal is not asked again', sent.length === askedAfter);
  sent.length = 0;
  script = [{ ok: true, stopped: true, why: null }];
  els['uv-scan-cancel'].click();
  await ticks(6);
  check('rv11b1-8: Stop sends the cancel for that run, and only the run id',
    sent.length >= 1 && sent[0].action === 'scan-cancel' && sent[0].payload.run_id === 42
    && Object.keys(sent[0].payload).join() === 'run_id');

  // R2O-3, RETIRED BY B10. A quiet planning run used to get its own
  // sentence sending the reader to Stop, with Continue hidden; the list now
  // resumes from its cursor, so a flag an older server still sends is not
  // a sentence and does not hide Continue.
  const planning = (over) => Object.assign(running(0, 0), { phase: 'planning' }, over);
  await show(planning({ planningStopped: true, idleSeconds: 3 * 3600 }));
  check('planning: a stale planningStopped flag neither speaks nor hides Continue',
    els['uv-scan-stalled'].style.display === 'none' && els['uv-scan-resume'].style.display === ''
    && UV.labels.planningStopped === undefined);
  await show(planning({ idleSeconds: 90 }));
  check('planning: a run still listing is told nothing, as before',
    els['uv-scan-stalled'].style.display === 'none' && els['uv-scan-resume'].style.display === '');
  // B10. PLANNING IS A SLICE PER WORK REQUEST NOW, so a planning run nobody is
  // driving is PAUSED like any other, and Continue resumes the list.
  await show(planning({ paused: true, idleSeconds: 300 }));
  check('planning: a planning run nobody is driving reads as paused, with Continue offered',
    els['uv-scan-stalled'].textContent === UV.labels.paused.replace('{minutes}', '5')
    && els['uv-scan-resume'].style.display === '');
  reset([{ ok: true, stop: 'time', worked: 0, requeued: 0, blocked: 0, findings: 0, listed: 1000,
           phase: 'planning', status: planning({}) },
         idle('held', 'another request is listing this project\'s records; this one will continue shortly',
              planning({}))]);
  UV.resume(7);
  await ticks(6);
  check('planning: Continue drives the list, and a slice that listed records is asked again at once',
    sent.filter((s) => s.action === 'scan-work').length === 1
    && pending.length === 1 && pending[0].ms === 0);
  fire(); await ticks(6);
  check('planning: a slice held off by another planner waits on the ladder, and says why',
    sent.filter((s) => s.action === 'scan-work').length === 2
    && pending.length === 1 && pending[0].ms >= 1500
    && els['uv-scan-note'].textContent.indexOf('another request is listing') === 0);
  // A tab DRIVING a planning run keeps driving it: there is no planning
  // state it gives up on, because every slice resumes from the cursor.
  reset([{ ok: true, stop: 'time', worked: 0, requeued: 0, blocked: 0, findings: 0, listed: 500,
           phase: 'planning', status: planning({ planningStopped: true, idleSeconds: 3 * 3600 }) },
         planning({})]);
  UV.resume(7);
  await ticks(6);
  check('planning: a driving tab does not stop on a planningStopped flag, and asks again at once',
    UV.state.running === true && pending.length === 1 && pending[0].ms === 0);

  // DRIVING. Same status, but this tab is the one doing the work.
  reset([{ ok: true, run_id: 7 }, worked(counted({ stalled: true, idleSeconds: 330 }))]);
  UV.start();
  await ticks(6);
  check('stalled: the tab DRIVING the scan does not show it - it has its own note for that',
    UV.state.running === true && els['uv-scan-stalled'].style.display === 'none');

  // -- OC-2: what promotion decided --------------------------------------
  //
  // A run refused `clean` because a rule could not be evaluated, with nothing
  // found, rendered exactly what a certified run rendered. The server now says
  // what it decided, and every finished run not certified says so.
  const fencedText = 'Every record was checked, including changes made while it ran.';
  const decided = (over) => Object.assign(finished('complete-through-fence'), over);
  await show(decided({ clean: 1, ruleProblems: 0 }));
  const certText = els['uv-scan-done'].textContent;
  check('verdict: a certified run says what it checked and nothing more', certText === fencedText);
  await show(decided({ clean: 0, ruleProblems: 1 }));
  check('verdict: a run with a rule it could not evaluate does NOT read as the certified one',
    els['uv-scan-done'].textContent !== certText
    && els['uv-scan-done'].textContent === fencedText + ' ' + UV.labels.ruleProblem);
  await show(decided({ clean: 0, ruleProblems: 4 }));
  check('verdict: and counts the rules when there is more than one',
    els['uv-scan-done'].textContent.indexOf('4 rules could not be evaluated') !== -1
    && els['uv-scan-done'].textContent.indexOf('{n}') === -1);
  await show(decided({ clean: 0, ruleProblems: 0, findings: 3 }));
  check('verdict: a run not certified for another reason says it is not certified',
    els['uv-scan-done'].textContent === fencedText + ' ' + UV.labels.notCertified);
  await show(Object.assign(finished('partial'), { terminal: 'cancelled', clean: null }));
  check('verdict: so does a run with no decision',
    els['uv-scan-done'].textContent.indexOf(UV.labels.notCertified) !== -1);
  await show(finished('complete-through-fence'));
  check('verdict: and a status that never said is not read as certified',
    els['uv-scan-done'].textContent.indexOf(UV.labels.notCertified) !== -1);
  // W7-N10. No decision is not certified, and its counters are not counts.
  await show(Object.assign(finished('partial'), { terminal: 'expired', clean: null, ruleProblems: 2 }));
  check('verdict W7-N10: a run with no decision says it is not certified, whatever its rule count says',
    els['uv-scan-done'].textContent.slice(-UV.labels.notCertified.length) === UV.labels.notCertified
    && els['uv-scan-done'].textContent.indexOf('could not be evaluated') === -1);
  await show(decided({ clean: 0, ruleProblems: 2 }));
  check('verdict W7-N10 control: beside a stored 0 the count is said',
    els['uv-scan-done'].textContent === fencedText + ' 2 rules could not be evaluated, so this result does not certify the project.');
  const keptLabelsForVerdict = UV.labels;
  UV.labels = {};
  await show(decided({ clean: 0, ruleProblems: 1 }));
  check('verdict: with no vocabulary handed over the sentence is still there',
    els['uv-scan-done'].textContent.indexOf('could not be evaluated') !== -1);
  UV.labels = keptLabelsForVerdict;

  // -- OC-4: findings that were never counted -----------------------------
  //
  // A run that ended before its summary was built answers findings: null. The
  // line printed "null findings so far"; read as zero it would say "Nothing
  // found yet" over duplicates the finalizer had already written.
  const ended = Object.assign(finished('partial'), { terminal: 'cancelled', findings: null });
  await show(ended);
  check('found: a count that does not exist is said not to exist',
    els['uv-scan-found'].textContent === UV.labels.foundUnknown
    && els['uv-scan-found'].textContent.indexOf('null') === -1);
  const noCount = Object.assign({}, ended);
  delete noCount.findings;
  await show(noCount);
  check('found: and so is a status with no count at all, never "Nothing found yet"',
    els['uv-scan-found'].textContent === UV.labels.foundUnknown);
  await show(Object.assign({}, ended, { findings: 0 }));
  check('found: a counted zero is still nothing found',
    els['uv-scan-found'].textContent === 'Nothing found yet');
  const keptLabels = UV.labels;
  UV.labels = {};
  await show(ended);
  check('found: with no vocabulary handed over it still does not say nothing was found',
    els['uv-scan-found'].textContent.indexOf('not counted') !== -1);
  UV.labels = keptLabels;

  // -- P10: what the run has stored ------------------------------------------
  //
  // The run row's storage counters, which nothing rendered. The size table is
  // tests/scan_page_php.php's, string for string: the panel's first paint and
  // the client's first poll must not disagree by a digit.
  await show(Object.assign(counted({}), { detailRows: 12345, detailBytes: 3565158 }));
  check('stored: a run that has stored rows says how many, and how much the values take',
    els['uv-scan-stored'].textContent === 'Stored: 12,345 finding rows, with 3.4 MB of values'
    && els['uv-scan-stored'].style.display === '');
  await show(Object.assign(counted({}), { detailRows: 1, detailBytes: 0 }));
  check('stored: one row and no values says only the row',
    els['uv-scan-stored'].textContent === 'Stored: 1 finding row');
  await show(Object.assign(counted({}), { detailRows: 0, detailBytes: 0 }));
  check('stored: a run that stored nothing hides the line and empties it',
    els['uv-scan-stored'].textContent === '' && els['uv-scan-stored'].style.display === 'none');
  await show(Object.assign(counted({}), { detailRows: '<b>9</b>', detailBytes: 10 }));
  check('stored: a count that is not a number is not a count, and is never written as markup',
    els['uv-scan-stored'].textContent === '' && els['uv-scan-stored'].style.display === 'none');
  const sizes = { 0: '0 bytes', 1: '1 byte', 400: '400 bytes', 1023: '1023 bytes', 1024: '1.0 KB',
    1075: '1.0 KB', 1076: '1.1 KB', 1048575: '1.0 MB', 3565158: '3.4 MB', 3617587: '3.4 MB',
    3617588: '3.5 MB', 1073741824: '1.0 GB', 2199023255552: '2.0 TB', 1125899906842624: '1,024.0 TB' };
  const badSizes = [];
  for (const b of Object.keys(sizes)) {
    // Through the line, with one row, so the size is what follows "with ".
    await show(Object.assign(counted({}), { detailRows: 1, detailBytes: Number(b) }));
    const got = Number(b) === 0 ? 'Stored: 1 finding row'
      : 'Stored: 1 finding row, with ' + sizes[b] + ' of values';
    if (els['uv-scan-stored'].textContent !== got) badSizes.push(b + ' => ' + els['uv-scan-stored'].textContent);
  }
  check('stored: every size reads as the page writes it', badSizes.length === 0);
  const keptStoredLabels = UV.labels;
  UV.labels = {};
  await show(Object.assign(counted({}), { detailRows: 2, detailBytes: 400 }));
  check('stored: with no vocabulary handed over the line is still the wording of the page',
    els['uv-scan-stored'].textContent === 'Stored: 2 finding rows, with 400 bytes of values');
  UV.labels = keptStoredLabels;

  // SETTLED IS PROGRESS. A pass that excluded a record moved the run on as much
  // as one that examined it; read from `done` alone, a run working through a
  // stretch of unreadable records would look idle and be backed off.
  reset([{ ok: true, run_id: 7 },
         idle(null, null, counted({ done: 3, settled: 3 })),
         idle(null, null, counted({ done: 3, settled: 6, excluded: 3 }))]);
  UV.start();
  await ticks(6);
  const idleBefore = UV.state.idle;
  await drive(1);
  check('progress: a pass that only excluded records still counts as the run moving',
    idleBefore > 0 && UV.state.idle === 0);

  // H10 part 1 and review R5-1. A group-scoped run that has not proved its
  // scope gives no counters and no counts, and a planning page holding only
  // other groups' records lists nothing a reader may count. The server says
  // `moved`, and that alone is progress.
  const withheld = (over) => Object.assign({ ok: true, run_id: 7, phase: 'planning', terminal: null,
    coverage: null, detail: null, values: 'none', scope: 'group', active: true, mayCancel: true,
    why: null, withheld: true,
    withheldWhy: 'This scan covers one Data Access Group, so its counts are shown once it has '
               + 'confirmed which records belong to that group.' }, over || {});
  reset([{ ok: true, run_id: 7 }, { ok: true, stop: 'time', moved: true, withheld: true,
                                    phase: 'planning', status: withheld() }]);
  UV.start();
  await ticks(6);
  check('withheld: a pass the server says moved the run is asked again at once, with no counter in it',
    pending.length === 1 && pending[0].ms === 0);
  reset([{ ok: true, run_id: 7 }, { ok: true, stop: 'time', moved: false, withheld: true,
                                    phase: 'planning', status: withheld() }]);
  UV.start();
  await ticks(6);
  check('withheld: and one it says did not move waits on the ladder',
    pending.length === 1 && pending[0].ms >= 1500);
  check('withheld: the counts line says why there are no counts, and there is no findings line',
    els['uv-scan-counts'].textContent === withheld().withheldWhy
    && els['uv-scan-found'].textContent === ''
    && els['uv-scan-bar'].className === 'uv-bar uv-bar-indeterminate');

  // review rv5b-2. A withheld run that has ENDED is not promised its counts,
  // and a stopped (or expired) one is not said to have left records
  // unchecked: its reader is shown no counts and cannot tell. The server
  // sends the counts sentence; the page chooses the coverage one, as
  // ScanPageView::endedSentence() does.
  const endedW = (terminal, over) => withheld(Object.assign({ phase: 'terminal', terminal: terminal,
    coverage: 'partial', active: false, mayCancel: false, clean: false,
    withheldWhy: UV.labels.withheldEnded }, over || {}));
  for (const t of ['cancelled', 'expired']) {
    reset([{ ok: true, run_id: 7 }, { ok: true, status: endedW(t) }]);
    UV.start(); await ticks(6);
    check('rv5b-2 client: a withheld run that was ' + t + ' says it was stopped, not that records went unchecked',
      els['uv-scan-done'].textContent.indexOf(UV.labels.stoppedWithheld) === 0
      && els['uv-scan-done'].textContent.indexOf('could not be checked') === -1
      && els['uv-scan-counts'].textContent === UV.labels.withheldEnded);
  }
  reset([{ ok: true, run_id: 7 }, { ok: true, status: endedW('partial') }]);
  UV.start(); await ticks(6);
  check('rv5b-2 client control: a withheld run that finished partial keeps the sentence its coverage earns',
    els['uv-scan-done'].textContent.indexOf(UV.labels.coverage.partial) === 0);
  reset([{ ok: true, run_id: 7 }, { ok: true, status: endedW('cancelled', { withheldWhy: undefined }) }]);
  UV.start(); await ticks(6);
  check('rv5b-2 client: with no sentence from the server, an ended run still is not promised its counts',
    els['uv-scan-counts'].textContent === UV.labels.withheldEnded);
  const keptEndedLabels = UV.labels;
  UV.labels = Object.assign({}, keptEndedLabels, { withheldEnded: undefined, stoppedWithheld: undefined });
  reset([{ ok: true, run_id: 7 }, { ok: true, status: endedW('cancelled', { withheldWhy: undefined }) }]);
  UV.start(); await ticks(6);
  check('rv5b-2 client: and without the page\'s labels its own words are the server\'s',
    els['uv-scan-counts'].textContent === keptEndedLabels.withheldEnded
    && els['uv-scan-done'].textContent.indexOf(keptEndedLabels.stoppedWithheld) === 0);
  UV.labels = keptEndedLabels;

  // rvfix-N2. A group run stopped AFTER it proved its scope shows its counts,
  // and its ending says they are the group's, as ScanPageView::endedSentence()
  // does; it is not told that records could not be checked.
  const endedP = (terminal, over) => withheld(Object.assign({ phase: 'terminal', terminal: terminal,
    coverage: 'partial', active: false, mayCancel: false, clean: false, withheld: false, withheldWhy: null,
    total: 5, done: 5, settled: 5, findings: 1 }, over || {}));
  for (const t of ['cancelled', 'expired']) {
    reset([{ ok: true, run_id: 7 }, { ok: true, status: endedP(t) }]);
    UV.start(); await ticks(6);
    check('rvfix-N2 client: a group run ' + t + ' after it proved its scope says its counts are for the group',
      els['uv-scan-done'].textContent.indexOf(UV.labels.stoppedProved) === 0
      && els['uv-scan-done'].textContent.indexOf('could not be checked') === -1
      && els['uv-scan-counts'].textContent.indexOf('5 of 5 records') === 0);
  }
  reset([{ ok: true, run_id: 7 }, { ok: true, status: endedP('cancelled', { detail: 'truncated' }) }]);
  UV.start(); await ticks(6);
  check('rvfix-N2 client: a truncated one still says findings were not kept',
    els['uv-scan-done'].textContent.indexOf(UV.labels.stoppedProved + ' ' + UV.labels.truncated) === 0);
  reset([{ ok: true, run_id: 7 }, { ok: true, status: endedP('cancelled', { scope: 'project' }) }]);
  UV.start(); await ticks(6);
  check('rvfix-N2 client control: a stopped whole-project run keeps the partial sentence',
    els['uv-scan-done'].textContent.indexOf(UV.labels.coverage.partial) === 0);
  UV.labels = Object.assign({}, keptEndedLabels, { stoppedProved: undefined });
  reset([{ ok: true, run_id: 7 }, { ok: true, status: endedP('expired') }]);
  UV.start(); await ticks(6);
  check('rvfix-N2 client: and without the labels of the page it still says so',
    els['uv-scan-done'].textContent.indexOf(keptEndedLabels.stoppedProved) === 0);
  UV.labels = keptEndedLabels;

  // W12-N4. A run an upgrade of the module ended says so wherever its ending
  // is shown, as ScanPageView::endedSentence() does.
  const upgradedSt = (over) => Object.assign(finished('failed'), { terminal: 'failed', upgraded: true }, over || {});
  reset([{ ok: true, run_id: 7 }, { ok: true, status: upgradedSt() }]);
  UV.start(); await ticks(6);
  check('W12-N4 client: a run an upgrade ended says so, not only that it failed',
    els['uv-scan-done'].textContent.indexOf(UV.labels.upgraded) === 0
    && els['uv-scan-done'].textContent.indexOf(UV.labels.coverage.failed) === -1);
  reset([{ ok: true, run_id: 7 }, { ok: true, status: upgradedSt({ detail: 'truncated' }) }]);
  UV.start(); await ticks(6);
  check('W12-N4 client: a truncated one still says findings were not kept',
    els['uv-scan-done'].textContent.indexOf(UV.labels.upgraded + ' ' + UV.labels.truncated) === 0);
  reset([{ ok: true, run_id: 7 }, { ok: true, status: upgradedSt({ upgraded: false }) }]);
  UV.start(); await ticks(6);
  check('W12-N4 client control: a failed run nothing upgraded keeps the failed sentence',
    els['uv-scan-done'].textContent.indexOf(UV.labels.coverage.failed) === 0);
  const keptUpLabels = UV.labels;
  UV.labels = Object.assign({}, keptUpLabels, { upgraded: undefined });
  reset([{ ok: true, run_id: 7 }, { ok: true, status: upgradedSt() }]);
  UV.start(); await ticks(6);
  check('W12-N4 client: and without the labels of the page it still says so',
    els['uv-scan-done'].textContent.indexOf(keptUpLabels.upgraded) === 0);
  UV.labels = keptUpLabels;

  process.stdout.write('scan_client_js: ' + n + ' checks, ' + fail + ' failure(s)\n');
  process.exit(fail ? 1 : 0);
})();
