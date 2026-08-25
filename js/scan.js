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
 * IT HOLDS NO VOCABULARY OF ITS OWN. The phase labels and the coverage
 * sentences live in php/ScanPageView.php and arrive on window.UVScan.labels,
 * because they were literals in this file as well as in the page and the two
 * copies had no way of learning about each other. A build that supplies no
 * labels still renders — the stored phase name and a sentence that names the
 * unrecognised coverage — rather than rendering nothing.
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
     * TWO BACKOFFS, because there are two different questions.
     *
     * TRANSPORT (NET_MIN..NET_MAX) answers "this request did not complete".
     * NO PROGRESS (IDLE_MIN..IDLE_MAX) answers "this request completed and the
     * run did not move".
     *
     * They used to be one ladder, and the reset for a completed request sat
     * ABOVE the branch that used it, so the ladder never grew on the path it was
     * written for: a server at its concurrency limit was polled once a second,
     * per tab, for as long as it stayed busy. The answer that did no work at all
     * was worse — it was re-issued at zero milliseconds, so one open tab could
     * put tens of thousands of requests through a shared REDCap while a dead
     * worker's claim aged out. Neither state is exotic; both are the ordinary
     * consequence of a worker that stopped mid-batch.
     *
     * WHY 1500 ms AT THE BOTTOM OF THE IDLE LADDER. A browser batch is aimed at
     * WorkBudget::BROWSER_TARGET = 3.0 seconds (php/Scan/WorkBudget.php), so an
     * answer that says another worker holds the claim cannot have become false
     * in less than that. Asking again after one second is certainly wasted;
     * 1500 ms is the smallest interval that is not.
     *
     * WHY 30000 ms AT THE TOP. The two states that legitimately persist are
     * bounded by the claim staleness the store uses (900 s) and the
     * installation slot lease (ScanService::SLOT_TTL, 300 s). Doubling from
     * 1500 reaches the ceiling in five passes, so a fifteen-minute stall costs
     * about thirty requests rather than tens of thousands, and a run that
     * recovers loses at most thirty seconds. No higher than that: somebody is
     * watching a progress bar, and a longer silence reads as a dead page.
     *
     * A PASS THAT MADE PROGRESS IS RE-ISSUED AT ONCE, and that must not change.
     * The server's own budget is the rate limiter on the happy path; delaying
     * there would halve throughput and buy nothing.
     */
    var NET_MIN = 1000, NET_MAX = 30000;
    var IDLE_MIN = 1500, IDLE_MAX = 30000;

    function el(id) { return document.getElementById(id); }

    function text(node, s) {
        if (node) node.textContent = (s === null || s === undefined) ? '' : String(s);
    }

    function attr(node, k, v) {
        if (!node || !node.setAttribute) return;
        if (v === null) { if (node.removeAttribute) node.removeAttribute(k); return; }
        node.setAttribute(k, String(v));
    }

    /** Percentage, or null when the total is not knowable yet. */
    function pct(done, total) {
        if (!total || total < 0) return null;
        var p = Math.floor((done / total) * 100);
        return p < 0 ? 0 : (p > 100 ? 100 : p);
    }

    function labels() { return UV.labels || {}; }

    /**
     * How a phase reads to somebody who did not design this.
     *
     * The table is the page's (ScanPageView::phaseLabels). An unrecognised phase
     * falls back to its stored name: "unique-finalize" on screen is ugly, and an
     * empty label where the phase should be is a page that looks broken.
     */
    function phaseLabel(phase) {
        var t = labels().phase;
        return (t && t[phase]) ? t[phase] : String(phase === undefined ? '' : phase);
    }

    /**
     * What a finished run is allowed to say it achieved, as ONE sentence.
     *
     * Built rather than appended to what is already on screen. The truncation
     * note used to be concatenated onto the node's text, so a coverage value
     * this build did not recognise produced a visible paragraph that began with
     * a space and had no subject — a blank certificate over a finished run,
     * which is the one outcome this module refuses. An unknown value is NAMED
     * instead, because whoever has to fix it needs the thread.
     */
    function coverageSentence(coverage, detail) {
        var L = labels();
        var t = L.coverage || {};
        var s = t[coverage];
        if (s === undefined) {
            var tpl = L.coverageUnknown || 'This scan finished, but this page does not recognise '
                    + 'the result it recorded ({value}). Treat it as incomplete and run it again.';
            s = String(tpl).replace('{value}', String(coverage));
        }
        if (detail === 'truncated') {
            s += ' ' + (L.truncated || 'Some findings were not kept, because the scan reached the '
                                     + 'limit this project allows.');
        }
        return s;
    }

    UV.state = {
        runId: null, running: false, stopping: false, starting: false,
        wait: NET_MIN,          // the transport ladder
        idle: 0,                // the no-progress ladder, 0 until a pass makes none
        stalledMs: 0,           // how long we have been getting nowhere, for the disclosure
        inflight: false,        // one scan-work in flight per tab, always
        chain: 0,               // which driver owns the loop; a stale one dies quietly
        seen: false,            // has any status arrived on this chain yet
        lastDone: -1, lastPhase: null, lastPct: null, lastFindings: null
    };

    /**
     * One call to the module's AJAX endpoint.
     *
     * IT MAY NOT THROW. UV.ajax is assigned by the page from the framework's
     * JavaScript module object, and calling a method on an object the framework
     * did not create is a synchronous TypeError — which escaped this function,
     * escaped the click handler, and left the Start button dead and silent with
     * no message at all. Everything here returns a promise, and a transport that
     * is missing or broken is marked uvFatal: a transport that does not exist is
     * not a lost connection and retrying it forever explains nothing.
     */
    function call(action, payload) {
        var e;
        if (typeof UV.ajax !== 'function') {
            e = new Error('no transport');
            e.uvFatal = true;
            return Promise.reject(e);
        }
        try {
            return Promise.resolve(UV.ajax(action, payload || {}));
        } catch (err) {
            e = (err && typeof err === 'object') ? err : new Error(String(err));
            e.uvFatal = true;
            return Promise.reject(e);
        }
    }

    /**
     * Say one sentence to a screen reader, and only when it is worth saying.
     *
     * ONLY THE PROGRESS SENTENCE GOES THROUGH HERE. The phase, the counts and
     * the findings are three visible spans that are deliberately NOT live
     * regions - a poll lands every few seconds and announcing each of them
     * separately makes the page unusable with assistive technology - so they are
     * gathered into one atomic region and written on a change. The explanation
     * line and the completion sentence are live regions in their own right
     * (pages/scan.php), so repeating them here would announce them twice.
     */
    function announce(s) { text(el('uv-scan-announce'), s); }

    function render(st) {
        if (!st || st.ok === false) {
            text(el('uv-scan-note'), st && st.why ? st.why : 'The scan could not be reached.');
            return;
        }
        UV.state.runId = st.run_id;
        var phase = phaseLabel(st.phase);
        text(el('uv-scan-phase'), phase);

        var p = pct(st.done, st.total);
        var track = el('uv-scan-bar-track'), bar = el('uv-scan-bar');
        // A bar with no total is INDETERMINATE, never zero and never full: a bar
        // sitting at 0% for the length of a planning phase reads as a scan that
        // has stalled, and people stop scans that look stalled — but the first
        // attempt at that set the width to 100%, which reads as a scan that has
        // FINISHED. That is this module's founding complaint reproduced in a
        // progress bar. The width now comes from the stylesheet on the page, and
        // the programmatic half is an ABSENT aria-valuenow, which is what ARIA
        // means by indeterminate. The two are set in the same branch so they
        // cannot diverge.
        if (track) {
            attr(track, 'aria-valuenow', p === null ? null : p);
            attr(track, 'aria-valuetext', p === null
                ? 'not known yet' : (st.done + ' of ' + st.total + ' records'));
        }
        if (bar) {
            bar.style.width = (p === null ? '' : p + '%');
            bar.className = (p === null ? 'uv-bar uv-bar-indeterminate' : 'uv-bar');
        }

        var counts = st.total
            ? (st.done + ' of ' + st.total + ' records' + (p === null ? '' : '  (' + p + '%)'))
            : 'Preparing';
        var found = st.findings === 0 ? 'Nothing found yet'
            : (st.findings + ' finding' + (st.findings === 1 ? '' : 's') + ' so far');
        text(el('uv-scan-counts'), counts);
        text(el('uv-scan-found'), found);

        var done = el('uv-scan-done');
        if (st.terminal) {
            // The terminal sentence and the coverage sentence are separate,
            // because a run can finish and still not have covered the project -
            // and a reader given only one of the two draws the wrong conclusion
            // from it.
            var sentence = coverageSentence(st.coverage, st.detail);
            // Revealed BEFORE it is written. A live region that is display:none
            // when its text changes announces nothing in most assistive
            // technology, so the old order made the completion sentence the one
            // thing a screen-reader user never heard.
            if (done) done.style.display = '';
            text(done, sentence);
        } else if (done) {
            done.style.display = 'none';
        }

        // Announce a phase change, a ten-percent step, or a new finding — and
        // nothing else. Everything below that threshold is noise at poll rate.
        if (!st.terminal) {
            var band = (p === null) ? null : Math.floor(p / 10);
            var lastBand = (UV.state.lastPct === null) ? null : Math.floor(UV.state.lastPct / 10);
            if (st.phase !== UV.state.lastPhase || band !== lastBand
                || st.findings !== UV.state.lastFindings) {
                announce(phase + '. ' + counts + '. ' + found);
            }
        }
        UV.state.lastPct = p;
        UV.state.lastFindings = st.findings;

        var panel = el('uv-scan-panel');
        attr(panel, 'aria-busy', st.active ? 'true' : 'false');

        toggle(el('uv-scan-start'), !st.active);
        toggle(el('uv-scan-cancel'), st.active && st.mayCancel);
        toggle(el('uv-scan-resume'), st.active && !UV.state.running);
    }

    function toggle(node, on) {
        if (node) node.style.display = on ? '' : 'none';
    }

    /**
     * Start and Continue are disabled while a request is in flight; Stop is NOT.
     *
     * A second click on Continue used to start a second pump chain from the same
     * tab, and three impatient clicks on a panel that had been silent for a
     * minute produced three permanent chains and three times the requests. The
     * DOM `disabled` property is what stops the click — aria-disabled announces
     * the state and still lets the click through, and this is a double-submit
     * guard before it is an announcement.
     *
     * Stop stays live on purpose: a work request takes seconds, and a Stop
     * button that is dead for the whole of every request is a Stop button.
     */
    function busy(on) {
        var ids = ['uv-scan-start', 'uv-scan-resume'], i, node;
        for (i = 0; i < ids.length; i++) {
            node = el(ids[i]);
            if (node) { node.disabled = !!on; attr(node, 'aria-disabled', on ? 'true' : 'false'); }
        }
    }

    /**
     * Did this pass move the run?
     *
     * THE PREDICATE IS "PROGRESS", NOT "A RECORD WAS EXAMINED". Planning lists
     * records and examines none of them, and a scheduler that treated that as a
     * stall would back off through the one phase that is genuinely working. So
     * every counter the server reports counts, including the ones a future phase
     * adds, plus a rising done count or a phase change for a server that reports
     * neither.
     *
     * The FIRST status of a chain is never progress by itself. Everything is new
     * on the first pass, and a run whose first answer is "the server is busy"
     * would otherwise be read as having moved and re-asked immediately.
     */
    function progressed(r, st) {
        var moved = (((r.worked | 0) + (r.listed | 0) + (r.requeued | 0)
                    + (r.blocked | 0) + (r.findings | 0)) > 0);
        if (st) {
            if (!moved && UV.state.seen) {
                if (typeof st.done === 'number' && st.done > UV.state.lastDone) moved = true;
                if (st.phase && st.phase !== UV.state.lastPhase) moved = true;
            }
            UV.state.seen = true;
            if (typeof st.done === 'number') UV.state.lastDone = st.done;
            if (st.phase) UV.state.lastPhase = st.phase;
        }
        return moved;
    }

    /**
     * Ask for one unit of work, then decide whether to ask again.
     *
     * The server tells us it is done; we never infer it from a count, because a
     * count that looks finished and a run that IS finished are exactly the two
     * things this module refuses to treat as the same.
     *
     * ONE CHAIN PER TAB. The in-flight flag stops a second driver starting while
     * a request is out, and the chain token kills one that was already queued:
     * a timer belonging to a superseded chain still fires, and without the token
     * it would rejoin the loop and double the request rate.
     */
    function pump() {
        if (!UV.state.running || !UV.state.runId) return;
        if (UV.state.inflight) return;
        var mine = UV.state.chain;
        UV.state.inflight = true;
        busy(true);
        call('scan-work', { run_id: UV.state.runId }).then(function (r) {
            UV.state.inflight = false;
            busy(false);
            if (mine !== UV.state.chain) return;      // superseded; die quietly
            try {
                advance(r);
            } catch (e) {
                // A bug in this file is not a failure of the scan, and must not
                // be reported as one. The run is on the server and untouched.
                stop('The scan is running on the server, but this page could not show what it '
                   + 'said. Reload the page; nothing has been lost.');
            }
        }, function (e) {
            UV.state.inflight = false;
            busy(false);
            if (mine !== UV.state.chain) return;
            if (e && e.uvFatal) {
                // Not a lost connection: there is no transport to lose. Retrying
                // on a ladder that can never succeed prints "retrying" forever.
                return stop('This page could not reach the module\'s JavaScript transport. The '
                          + 'scan itself is on the server and is unaffected. Reload the page; if '
                          + 'it keeps happening, ask an administrator to check the module is '
                          + 'enabled here.');
            }
            // Transport failure. The run is untouched on the server, so this
            // retries rather than ending anything.
            text(el('uv-scan-note'), 'Lost contact with the server; retrying.');
            setTimeout(pump, netBackoff());
        });
    }

    /** What one completed scan-work answer means, and when to ask again. */
    function advance(r) {
        if (!r || r.ok === false) {
            stop(r && r.why ? r.why : 'The scan stopped. It can be resumed.');
            if (r && r.status) render(r.status);
            return;
        }
        var st = r.status || null;
        UV.state.wait = NET_MIN;             // the REQUEST completed: the transport ladder resets
        var moved = progressed(r, st);
        if (st) render(st);

        if (st && st.terminal) {
            UV.state.running = false;
            UV.state.idle = 0;
            UV.state.stalledMs = 0;
            text(el('uv-scan-note'), '');
            return;
        }
        if (moved) {
            UV.state.idle = 0;
            UV.state.stalledMs = 0;
            text(el('uv-scan-note'), '');
            setTimeout(pump, 0);
            return;
        }

        // NOTHING MOVED. The server always says why — waiting on another
        // worker's claim, refused by the fence, at the installation's
        // concurrency limit — and every one of those sentences is written for a
        // person. Repeat it verbatim. The old code blanked the note here
        // instead, so an operator watched a frozen bar, an unchanged count and
        // an empty line for as long as it took a dead worker's claim to age out.
        var why = r.why || (r.stop === 'capacity'
            ? 'This server is busy with other scans; this one will continue shortly.'
            : 'The scan is not progressing just now. It has not stopped, and nothing has been lost.');
        var w = UV.state.idle = UV.state.idle ? Math.min(IDLE_MAX, UV.state.idle * 2) : IDLE_MIN;
        UV.state.stalledMs += w;
        if (UV.state.idle >= IDLE_MAX) {
            // Once the ladder is at its ceiling this is a stall, not a pause, and
            // saying so is the difference between a person waiting and a person
            // reloading the page in the belief it has died.
            why += '  Nothing has progressed for about '
                 + Math.round(UV.state.stalledMs / 1000) + ' seconds. The scan is on the server '
                 + 'and resumes when it can, so you can leave this page and come back.';
        }
        text(el('uv-scan-note'), why);
        setTimeout(pump, w);
    }

    /** The transport ladder. Used ONLY by a request that did not complete. */
    function netBackoff() {
        var w = UV.state.wait;
        UV.state.wait = Math.min(NET_MAX, UV.state.wait * 2);
        return w;
    }

    function stop(why) {
        UV.state.running = false;
        UV.state.inflight = false;
        UV.state.idle = 0;
        UV.state.stalledMs = 0;
        UV.state.chain++;                    // any timer already queued is now an orphan
        busy(false);
        text(el('uv-scan-note'), why || '');
        refresh();
    }

    function refresh() {
        if (!UV.state.runId) return;
        call('scan-status', { run_id: UV.state.runId }).then(render)['catch'](function () {});
    }

    UV.start = function () {
        if (UV.state.running || UV.state.starting) return;
        UV.state.starting = true;
        UV.state.chain++;
        text(el('uv-scan-note'), '');
        busy(true);
        // .then(onOk, onErr), NOT .then(onOk)['catch'](onErr). With the catch
        // form, a throw inside onOk printed "The scan could not be started."
        // over a run the server HAD started and was holding the project's slot
        // for — a message that contradicted the server, on a panel that then sat
        // inert. A transport failure and a bug in this file are different
        // events and get different sentences.
        call('scan-start', {}).then(function (r) {
            UV.state.starting = false;
            busy(false);
            if (!r || r.ok === false) {
                // Busy is a refusal with no detail by design: which run holds
                // the project's slot, and who started it, are never disclosed.
                text(el('uv-scan-note'), (r && r.why) ? r.why : 'The scan could not be started.');
                return;
            }
            UV.state.runId = r.run_id;
            UV.state.running = true;
            UV.state.wait = NET_MIN;
            UV.state.idle = 0;
            UV.state.stalledMs = 0;
            UV.state.seen = false;
            UV.state.lastDone = -1;
            UV.state.lastPhase = null;
            try {
                pump();
            } catch (e) {
                stop('The scan started, but this page could not drive it. Press Continue to try '
                   + 'again, or reload the page.');
            }
        }, function (e) {
            UV.state.starting = false;
            busy(false);
            // A transport that is not there is not a server that said no, and
            // "The scan could not be started." sends the reader to look at the
            // scan. Name the piece that is actually missing.
            text(el('uv-scan-note'), (e && e.uvFatal)
                ? 'This page could not reach the module\'s JavaScript transport, so nothing was '
                  + 'started. Reload the page; if it keeps happening, ask an administrator to '
                  + 'check the module is enabled here.'
                : 'The scan could not be started.');
        });
    };

    UV.resume = function (runId) {
        if (runId) UV.state.runId = runId;
        if (!UV.state.runId) return;
        if (UV.state.running) return;        // a second Continue is not a second driver
        UV.state.chain++;
        UV.state.running = true;
        UV.state.wait = NET_MIN;
        UV.state.idle = 0;
        UV.state.stalledMs = 0;
        UV.state.seen = false;
        UV.state.lastDone = -1;
        UV.state.lastPhase = null;
        pump();
    };

    UV.cancel = function () {
        if (!UV.state.runId) return;
        UV.state.running = false;
        UV.state.chain++;
        call('scan-cancel', { run_id: UV.state.runId }).then(function (r) {
            text(el('uv-scan-note'), (r && r.why) ? r.why : 'Stopping the scan.');
            refresh();
        }, function () {
            text(el('uv-scan-note'), 'The scan could not be stopped; try again.');
        });
    };

    /**
     * A click that cannot leave the page in a state nobody can see.
     *
     * preventDefault FIRST, because these controls sit inside REDCap's project
     * form and a button that reaches its default action submits the page out
     * from under a running scan. Then the handler, inside a try: an exception
     * escaping a click handler is invisible to everyone except whoever has the
     * console open.
     */
    function safely(fn) {
        return function (e) {
            if (e && e.preventDefault) e.preventDefault();
            try {
                fn();
            } catch (err) {
                UV.state.running = false;
                UV.state.starting = false;
                busy(false);
                text(el('uv-scan-note'), 'This page could not reach the module\'s JavaScript '
                    + 'transport. Nothing was started. Reload the page.');
            }
        };
    }

    /**
     * Attach to a page that already knows whether a run is in progress.
     *
     * The page renders the state before any JavaScript runs — the phase, the
     * counts, the bar and which of Start and Continue applies — so somebody with
     * scripting disabled sees the state of their scan rather than an empty
     * panel. This only adds the ability to advance it.
     */
    UV.attach = function (opts) {
        opts = opts || {};
        var b;
        if ((b = el('uv-scan-start')))  b.addEventListener('click', safely(UV.start));
        if ((b = el('uv-scan-cancel'))) b.addEventListener('click', safely(UV.cancel));
        if ((b = el('uv-scan-resume'))) b.addEventListener('click', safely(function () { UV.resume(); }));
        if (opts.runId) {
            UV.state.runId = opts.runId;
            refresh();
            if (opts.autoResume) UV.resume();
        }
    };
})();
