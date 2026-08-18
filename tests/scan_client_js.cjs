/*
 * scan_client_js.cjs — the browser half of the durable scan.
 *
 * The client drives a loop and renders what the server said. It decides nothing
 * about the scan, which means the interesting assertions are all about what it
 * does when the answer is NOT "carry on":
 *
 *   - a refusal ends the loop and says so, rather than retrying into a run that
 *     no longer wants this worker;
 *   - "the server is busy" is a wait, not a failure, and it backs off;
 *   - a transport failure retries, because the run on the server is untouched;
 *   - completion comes from the server saying terminal, NEVER from the counts
 *     looking finished. A count that looks done and a run that IS done are
 *     exactly the two things this module refuses to treat as the same.
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
function makeEl(id) {
  return {
    id, textContent: '', className: '', style: {},
    _handlers: {},
    addEventListener(ev, fn) { (this._handlers[ev] = this._handlers[ev] || []).push(fn); },
    click() { (this._handlers.click || []).forEach((f) => f({ preventDefault() {} })); },
  };
}
const els = {};
['uv-scan-start', 'uv-scan-cancel', 'uv-scan-resume', 'uv-scan-phase', 'uv-scan-bar',
 'uv-scan-counts', 'uv-scan-found', 'uv-scan-done', 'uv-scan-note'].forEach((id) => {
  els[id] = makeEl(id);
});

global.window = {};
global.document = { getElementById: (id) => els[id] || null };

// setTimeout is driven by hand: the loop must be steppable, and a real timer
// would make every assertion a race.
const pending = [];
global.setTimeout = (fn, ms) => { pending.push({ fn, ms }); return pending.length; };
function drain(max) {
  let i = 0;
  while (pending.length && i++ < (max || 50)) {
    const t = pending.shift();
    t.fn();
  }
}
/** Let queued promise callbacks run. */
function tick() { return new Promise((r) => process.nextTick(r)); }
/** Several of them, which is what one request-and-render takes. */
async function ticks(k) { for (let i = 0; i < (k || 4); i++) await tick(); }
/** Run exactly one queued timer, so a backoff is observable rather than raced. */
function fire() { const t = pending.shift(); if (t) t.fn(); }

require(path.join(__dirname, '..', 'js', 'scan.js'));
const UV = global.window.UVScan;

// -- a fake transport the test scripts -------------------------------------
let script = [];
let sent = [];
UV.ajax = function (action, payload) {
  sent.push({ action, payload });
  const next = script.shift();
  if (!next) return Promise.resolve({ ok: true, status: { ok: true, terminal: 'complete' } });
  if (next.reject) return Promise.reject(new Error('network'));
  return Promise.resolve(next);
};
function reset(s) {
  script = s.slice();
  sent = [];
  pending.length = 0;
  UV.state.runId = null;
  UV.state.running = false;
  UV.state.wait = 1000;
  Object.keys(els).forEach((k) => { els[k].textContent = ''; els[k].style = {}; });
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
         { ok: true, stop: 'capacity', status: running(0, 10) },
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

  // -- completion comes from the server -----------------------------------
  //
  // done === total is NOT the end. Catch-up, the duplicate finalizer and the
  // summary all run after the last record, and a client that stopped here would
  // report a scan as finished while it was still deciding whether two records
  // share a hospital number.
  reset([{ ok: true, run_id: 7 },
         { ok: true, status: running(10, 10) },
         { ok: true, status: finished('complete-through-fence') }]);
  UV.start();
  await ticks(6);
  check('client: every record scanned does NOT mean the run is over',
    UV.state.running === true);
  fire(); await ticks(4);
  check('client: the server saying terminal is what ends it', UV.state.running === false);

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

  // Truncated detail is stated even on a run whose coverage was complete: the
  // report the reader holds is not the report the run produced.
  reset([{ ok: true, run_id: 7 },
         { ok: true, status: Object.assign(finished('complete-through-fence'),
             { detail: 'truncated' }) }]);
  UV.start(); await ticks(6);
  check('client: a truncated report says findings were not kept',
    els['uv-scan-done'].textContent.indexOf('not kept') !== -1);

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
