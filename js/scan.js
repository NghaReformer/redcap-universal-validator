/**
 * scan.js — the browser half of the durable validation scan.
 *
 * WHAT THIS IS FOR. The old scan was one GET request that ran the whole project
 * and rendered whatever it had when PHP gave up. This is the opposite: the
 * browser asks the server to do a few seconds of work, the server says how far
 * it got, and the browser asks again. Nothing here decides anything about the
 * scan — not what to read, not who may see it, not when it is finished. It
 * drives a loop and renders what the server said, which is why closing the tab
 * is safe: the run is on the server, its lease expires on its own, and the next
 * visit resumes it.
 *
 * THE CLIENT IS NOT A PARTICIPANT IN THE SECURITY MODEL. It sends a run id and
 * nothing else. The server re-derives the project, the user, the Data Access
 * Group and the entitlement on every single request, so a modified run id gets
 * the same sentence as one that does not exist.
 *
 * FAILURE IS EXPECTED, NOT EXCEPTIONAL. A batch can be refused because the run
 * was cancelled from another tab, because the server is at its concurrency
 * limit, or because the network dropped. None of those end the scan; they end
 * this LOOP, and the run keeps its state. So the client stops politely, says
 * what happened, and offers to resume — never silently, and never by pretending
 * the scan finished.
 */
(function () {
    'use strict';

    var UV = (window.UVScan = window.UVScan || {});

    /**
     * Backoff after a request that did not go through.
     *
     * Doubling from one second to thirty. A browser that retried immediately
     * would turn one failing server into a page hammering it, and the whole
     * point of the concurrency slot is that a busy server is asked to wait.
     */
    var MIN_WAIT = 1000, MAX_WAIT = 30000;

    function el(id) { return document.getElementById(id); }

    function text(node, s) {
        if (node) node.textContent = (s === null || s === undefined) ? '' : String(s);
    }

    /** Percentage, or null when the total is not knowable yet. */
    function pct(done, total) {
        if (!total || total < 0) return null;
        var p = Math.floor((done / total) * 100);
        return p < 0 ? 0 : (p > 100 ? 100 : p);
    }

    /**
     * How a phase reads to somebody who did not design this.
     *
     * Deliberately not the stored value. "unique-finalize" is a correct name for
     * a state machine and an alarming one for a data manager watching a progress
     * bar.
     */
    var PHASE = {
        'planning':        'Listing the records to check',
        'scanning':        'Checking records',
        'catch-up':        'Checking what changed while it ran',
        'unique-finalize': 'Looking for duplicate values',
        'rollup-finalize': 'Building the summary',
        'cancelling':      'Stopping',
        'terminal':        'Finished'
    };

    /**
     * What a finished run may be said to have achieved.
     *
     * Every one of these is a different sentence on purpose. The whole rebuild
     * exists because one word — "complete" — was used for a run that examined
     * everything and a run that examined nothing.
     */
    var COVERAGE = {
        'complete-through-fence': 'Every record was checked, including changes made while it ran.',
        'manifest-complete':      'Every record on the opening list was checked. This server '
                                + 'cannot prove the project did not change during the scan.',
        'partial':                'Some records could not be checked. This is not a complete '
                                + 'picture of the project.',
        'failed':                 'The scan failed, so it describes nothing.'
    };

    UV.state = { runId: null, running: false, wait: MIN_WAIT, stopping: false };

    /** One call to the module's AJAX endpoint. Rejects only on transport failure. */
    function call(action, payload) {
        if (typeof UV.ajax !== 'function') {
            return Promise.reject(new Error('no transport'));
        }
        return UV.ajax(action, payload || {});
    }

    function render(st) {
        if (!st || st.ok === false) {
            text(el('uv-scan-note'), st && st.why ? st.why : 'The scan could not be reached.');
            return;
        }
        UV.state.runId = st.run_id;
        text(el('uv-scan-phase'), PHASE[st.phase] || st.phase);

        var p = pct(st.done, st.total);
        var bar = el('uv-scan-bar');
        if (bar) {
            // A bar with no total is INDETERMINATE, never zero: a bar sitting at
            // 0% for the length of a planning phase reads as a scan that has
            // stalled, and people stop scans that look stalled.
            bar.style.width = (p === null ? 100 : p) + '%';
            bar.className = (p === null ? 'uv-bar uv-bar-indeterminate' : 'uv-bar');
        }
        text(el('uv-scan-counts'), st.total
            ? (st.done + ' of ' + st.total + ' records' + (p === null ? '' : '  (' + p + '%)'))
            : 'Preparing');
        text(el('uv-scan-found'), st.findings === 0 ? 'Nothing found yet'
            : (st.findings + ' finding' + (st.findings === 1 ? '' : 's') + ' so far'));

        var done = el('uv-scan-done');
        if (st.terminal) {
            // The terminal sentence and the coverage sentence are separate,
            // because a run can finish and still not have covered the project -
            // and a reader given only one of the two draws the wrong conclusion
            // from it.
            text(done, COVERAGE[st.coverage] || '');
            if (done) done.style.display = '';
            if (st.detail === 'truncated' && done) {
                done.textContent += ' Some findings were not kept, because the scan reached the '
                                  + 'limit this project allows.';
            }
        } else if (done) {
            done.style.display = 'none';
        }

        toggle(el('uv-scan-start'), !st.active);
        toggle(el('uv-scan-cancel'), st.active && st.mayCancel);
        toggle(el('uv-scan-resume'), st.active && !UV.state.running);
    }

    function toggle(node, on) {
        if (node) node.style.display = on ? '' : 'none';
    }

    /**
     * Ask for one unit of work, then decide whether to ask again.
     *
     * The server tells us it is done; we never infer it from a count, because a
     * count that looks finished and a run that IS finished are exactly the two
     * things this module refuses to treat as the same.
     */
    function pump() {
        if (!UV.state.running || !UV.state.runId) return;
        call('scan-work', { run_id: UV.state.runId }).then(function (r) {
            if (!r || r.ok === false) {
                stop(r && r.why ? r.why : 'The scan stopped. It can be resumed.');
                if (r && r.status) render(r.status);
                return;
            }
            UV.state.wait = MIN_WAIT;                 // a good pass resets the backoff
            if (r.status) render(r.status);

            if (r.stop === 'capacity') {
                // Not an error and not the end: the server is running as many
                // scans as it allows. Waiting is the correct behaviour and the
                // message says so, rather than reading as a failure.
                text(el('uv-scan-note'), 'This server is busy with other scans; '
                    + 'this one will continue shortly.');
                return setTimeout(pump, backoff());
            }
            text(el('uv-scan-note'), '');
            if (r.status && r.status.terminal) { UV.state.running = false; return; }
            setTimeout(pump, 0);
        })['catch'](function () {
            // Transport failure. The run is untouched on the server, so this
            // retries rather than ending anything.
            text(el('uv-scan-note'), 'Lost contact with the server; retrying.');
            setTimeout(pump, backoff());
        });
    }

    function backoff() {
        var w = UV.state.wait;
        UV.state.wait = Math.min(MAX_WAIT, UV.state.wait * 2);
        return w;
    }

    function stop(why) {
        UV.state.running = false;
        text(el('uv-scan-note'), why || '');
        refresh();
    }

    function refresh() {
        if (!UV.state.runId) return;
        call('scan-status', { run_id: UV.state.runId }).then(render)['catch'](function () {});
    }

    UV.start = function () {
        text(el('uv-scan-note'), '');
        call('scan-start', {}).then(function (r) {
            if (!r || r.ok === false) {
                // Busy is a refusal with no detail by design: which run holds
                // the project's slot, and who started it, are never disclosed.
                text(el('uv-scan-note'), (r && r.why) ? r.why : 'The scan could not be started.');
                return;
            }
            UV.state.runId = r.run_id;
            UV.state.running = true;
            UV.state.wait = MIN_WAIT;
            pump();
        })['catch'](function () {
            text(el('uv-scan-note'), 'The scan could not be started.');
        });
    };

    UV.resume = function (runId) {
        if (runId) UV.state.runId = runId;
        if (!UV.state.runId) return;
        UV.state.running = true;
        UV.state.wait = MIN_WAIT;
        pump();
    };

    UV.cancel = function () {
        if (!UV.state.runId) return;
        UV.state.running = false;
        call('scan-cancel', { run_id: UV.state.runId }).then(function (r) {
            text(el('uv-scan-note'), (r && r.why) ? r.why : 'Stopping the scan.');
            refresh();
        })['catch'](function () {
            text(el('uv-scan-note'), 'The scan could not be stopped; try again.');
        });
    };

    /**
     * Attach to a page that already knows whether a run is in progress.
     *
     * The page decides what to show before any JavaScript runs, so somebody with
     * scripting disabled still sees the state of their scan rather than an empty
     * panel. This only adds the ability to advance it.
     */
    UV.attach = function (opts) {
        opts = opts || {};
        var b;
        if ((b = el('uv-scan-start')))  b.addEventListener('click', function (e) { e.preventDefault(); UV.start(); });
        if ((b = el('uv-scan-cancel'))) b.addEventListener('click', function (e) { e.preventDefault(); UV.cancel(); });
        if ((b = el('uv-scan-resume'))) b.addEventListener('click', function (e) { e.preventDefault(); UV.resume(); });
        if (opts.runId) {
            UV.state.runId = opts.runId;
            refresh();
            if (opts.autoResume) UV.resume();
        }
    };
})();
