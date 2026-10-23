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

    /** A page template with its {holes} filled. The templates are the page's. */
    function fill(tpl, withValues) {
        var out = String(tpl), k;
        for (k in withValues) {
            if (Object.prototype.hasOwnProperty.call(withValues, k)) {
                out = out.split('{' + k + '}').join(String(withValues[k]));
            }
        }
        return out;
    }

    /**
     * How much of the manifest is SETTLED - examined, excluded or removed. It is
     * what the bar measures, so the bar reaches the end when the run does. A
     * server that does not report it yet reports `done` with the old meaning,
     * which was this.
     */
    function settled(st) {
        return (typeof st.settled === 'number') ? st.settled : st.done;
    }

    /**
     * The progress line. ScanPageView::countsText() builds the same string from
     * the same templates, so the first paint and the first poll agree. The
     * number before "of" is records EXAMINED; what was not is said beside it.
     */
    function countsText(st, p) {
        var L = labels();
        // A group-scoped run that has not proved its scope carries no counts
        // (H10 part 1); the server says why, and that is the line.
        if (st.withheld) {
            if (typeof st.withheldWhy === 'string' && st.withheldWhy) return st.withheldWhy;
            // A run that has ended is not promised its counts later (review rv5b-2).
            if (st.terminal) {
                return L.withheldEnded || 'This scan covers one Data Access Group and ended before it confirmed which records belong to that group, so its counts cannot be shown.';
            }
            return L.withheld || 'This scan covers one Data Access Group, so its counts are shown once it has confirmed which records belong to that group.';
        }
        // A finished run with no record left in its total is not preparing
        // anything (review rvf1-2).
        if (!st.total) return st.terminal ? (L.none || '0 records') : 'Preparing';
        var excluded = st.excluded | 0, removed = st.removed | 0, line;
        if (excluded <= 0 && removed <= 0) {
            line = fill(L.plain || '{done} of {total} records', { done: st.done, total: st.total });
        } else {
            var parts = [fill(L.checked || '{done} of {total} records checked',
                              { done: st.done, total: st.total })];
            if (excluded > 0) parts.push(fill(L.excluded || '{n} could not be checked', { n: excluded }));
            if (removed > 0) parts.push(fill(L.removed || '{n} deleted during the scan', { n: removed }));
            line = parts.join('; ');
        }
        return line + (p === null ? '' : '  (' + p + '%)');
    }

    /**
     * What a finished run that is NOT certified clean must add to its coverage
     * sentence, or null when it owes nothing.
     *
     * The coverage sentence says what was checked, not what was decided, so a
     * run refused `clean` because a rule could not be evaluated read exactly as
     * a certified one did. Only a stored `clean` of exactly 1 owes nothing; a
     * status without the field (an older server) is not certified, because the
     * missing answer is never the reassuring one. ScanPageView::verdictText()
     * decides the same way, from the same sentences.
     *
     * A run with NO decision (`clean` null: expired by the reaper, or finished
     * before the decision was stored) is not certified, and its counters are
     * not measurements (W7-N10): the rule count is read only beside a stored 0.
     */
    function verdictText(st) {
        if (!st.terminal || st.clean === 1) return null;
        var L = labels(), n = st.ruleProblems | 0, tpl;
        if (st.clean !== 0) return L.notCertified || 'This result does not certify the project.';
        if (n === 1) {
            return L.ruleProblem
                || '1 rule could not be evaluated, so this result does not certify the project.';
        }
        if (n > 1) {
            tpl = L.ruleProblems
                || '{n} rules could not be evaluated, so this result does not certify the project.';
            return fill(tpl, { n: n });
        }
        return L.notCertified || 'This result does not certify the project.';
    }

    /**
     * "Dates were judged as of 2026-10-22.", or null (W10BF-3): the day a
     * durable run froze for its date rules. ScanPageView::asOfText() decides
     * the same way.
     */
    function asOfText(st) {
        if (typeof st.asOf !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(st.asOf)) return null;
        var L = labels();
        return fill(L.asOf || 'Dates were judged as of {date}.', { date: st.asOf });
    }

    /**
     * "Nothing found yet", "3 findings so far", or that they were not counted.
     *
     * A findings value that is not a number is NOT zero. A run that ended
     * before its summary was built answers null (ScanPromotion::findingsOf),
     * and this printed "null findings so far"; read as zero it would have said
     * "Nothing found yet" over duplicates the finalizer had already written.
     * ScanPageView::foundText() builds the same string.
     */
    function foundText(st) {
        // Withheld is not uncounted: the counts line has said why.
        if (st.withheld) return '';
        var f = st.findings, L = labels();
        if (typeof f !== 'number' || f < 0 || f !== Math.floor(f)) {
            return L.foundUnknown
                || 'Findings not counted, because the scan ended before it could total them';
        }
        return f === 0 ? 'Nothing found yet' : (f + ' finding' + (f === 1 ? '' : 's') + ' so far');
    }

    /** "12345" as "12,345", as PHP's number_format() writes it. */
    function grouped(n) {
        return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    /** A whole, non-negative count from the status, or 0. */
    function whole(v) {
        return (typeof v === 'number' && v > 0 && v === Math.floor(v)) ? v : 0;
    }

    /**
     * "400 bytes", "3.4 MB". ScanPageView::sizeText() decides the same way, in
     * whole numbers on both sides so the two land on the same digit: binary
     * units, one decimal, rounded half up, and a value that rounds up to 1024
     * of a unit is said in the next one.
     */
    function sizeText(bytes) {
        if (bytes < 1024) return bytes + ' byte' + (bytes === 1 ? '' : 's');
        var units = ['KB', 'MB', 'GB', 'TB'], i = 0, div = 1024;
        var tenths = Math.floor((bytes * 10 + div / 2) / div);
        while (tenths >= 10240 && i < units.length - 1) {
            i++;
            div *= 1024;
            tenths = Math.floor((bytes * 10 + div / 2) / div);
        }
        return grouped(Math.floor(tenths / 10)) + '.' + (tenths % 10) + ' ' + units[i];
    }

    /**
     * "Stored: 12,345 finding rows, with 3.4 MB of values", or null when the
     * run has stored no row (P10). These are the run row's storage counters,
     * not the findings count. ScanPageView::storedText() builds the same string.
     */
    function storedText(st) {
        var rows = whole(st.detailRows), bytes = whole(st.detailBytes), L = labels();
        if (rows <= 0) return null;
        var phrase = grouped(rows) + ' finding row' + (rows === 1 ? '' : 's');
        if (bytes > 0) {
            return fill(L.storedValues || 'Stored: {rows}, with {size} of values',
                        { rows: phrase, size: sizeText(bytes) });
        }
        return fill(L.stored || 'Stored: {rows}', { rows: phrase });
    }

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

    /**
     * The coverage half of a finished run's sentence, as
     * ScanPageView::endedSentence() decides it. A group-scoped run stopped
     * (or expired) before it proved its scope has coverage `partial`, whose
     * sentence says records could not be checked; its reader is shown no
     * counts, so it is told what is known instead (review rv5b-2). One
     * stopped after it proved its scope shows its counts, and is told they
     * are the group's (review rvfix-N2). An empty scope that records LEFT
     * says they moved to another group, not that they were deleted (review
     * rvf1-2).
     */
    function endedSentence(st) {
        var L = labels();
        // A run an upgrade of the module ended says so (review W12-N4).
        if (st.upgraded === true && st.terminal === 'failed') {
            var u = L.upgraded || 'This module was upgraded while this scan was running, and the new version judges some values differently, so the scan was ended rather than finished half under each version. Start a new scan.';
            if (st.detail === 'truncated') {
                u += ' ' + (L.truncated || 'Some findings were not kept, because the scan reached the '
                                         + 'limit this project allows.');
            }
            return u;
        }
        var stopped = (st.terminal === 'cancelled' || st.terminal === 'expired');
        if (st.withheld && stopped) {
            return L.stoppedWithheld || 'This scan was stopped before it confirmed which records belong to this Data Access Group, so it is not a complete picture of the group.';
        }
        if (stopped && st.scope === 'group' && st.coverage === 'partial') {
            var p = L.stoppedProved || 'This scan was stopped after it confirmed which records belong to this Data Access Group, so its counts are for the group. It ended before every check had run, so it is not a complete picture of the group.';
            if (st.detail === 'truncated') {
                p += ' ' + (L.truncated || 'Some findings were not kept, because the scan reached the '
                                         + 'limit this project allows.');
            }
            return p;
        }
        if (st.coverage === 'empty-scope' && st.leftGroup) {
            var s = (st.removed | 0) > 0
                ? (L.emptyLeftDeleted || 'This scan has no record left to report on: every record it listed moved to another Data Access Group or was deleted while it ran. Any findings it counted are on records no longer in the group. This result says nothing about the group.')
                : (L.emptyLeft || 'This scan has no record left to report on: every record it listed moved to another Data Access Group while it ran. Any findings it counted are on records no longer in the group. This result says nothing about the group.');
            if (st.detail === 'truncated') {
                s += ' ' + (L.truncated || 'Some findings were not kept, because the scan reached the '
                                         + 'limit this project allows.');
            }
            return s;
        }
        return coverageSentence(st.coverage, st.detail);
    }

    UV.state = {
        runId: null, running: false, stopping: false, starting: false,
        wait: NET_MIN,          // the transport ladder
        idle: 0,                // the no-progress ladder, 0 until a pass makes none
        stalledMs: 0,           // how long we have been getting nowhere, for the disclosure
        inflight: false,        // one scan-work in flight per tab, always
        chain: 0,               // which driver owns the loop; a stale one dies quietly
        watchChain: 0,          // the same, for a tab that is only watching
        watchWait: 0,           // the watching ladder, 0 until the first poll is queued
        watchNote: null,        // the refusal a watch last wrote into the note, if any
        stopNote: null,         // the "stopping" sentence Stop wrote, until the run is over
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
            // A REFUSAL SAYS NOTHING ABOUT WHETHER THE RUN IS QUIET (review
            // R2O-4). This returned before the paused/stalled sentence below
            // was reconsidered, so a sentence about the last status that DID
            // arrive stayed under a refusal that contradicted it.
            var quietNode = el('uv-scan-stalled');
            if (quietNode) {
                quietNode.style.display = 'none';
                text(quietNode, '');
            }
            // A REFUSED STATUS CAN STILL OFFER STOP (review rvc2-3). The server
            // says so with mayCancel when this reader may stop the run it
            // will not describe - a configuration it could not read in full,
            // an instrument or another project that bars the report - and
            // Stop was otherwise only ever shown from a status that arrived.
            // A refusal that does not say leaves the button as it was.
            if (st && st.mayCancel === true) toggle(el('uv-scan-cancel'), st.active === true);
            return;
        }
        UV.state.runId = st.run_id;
        var phase = phaseLabel(st.phase);
        text(el('uv-scan-phase'), phase);

        var p = pct(settled(st), st.total);
        var counts = countsText(st, p);
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
            attr(track, 'aria-valuetext', p === null ? 'not known yet' : countsText(st, null));
        }
        if (bar) {
            bar.style.width = (p === null ? '' : p + '%');
            bar.className = (p === null ? 'uv-bar uv-bar-indeterminate' : 'uv-bar');
        }

        var found = foundText(st);
        text(el('uv-scan-counts'), counts);
        text(el('uv-scan-found'), found);

        // What the run has stored, shown once it has stored something. Server
        // numbers reach the node through textContent only, as everything here.
        var storedNode = el('uv-scan-stored'), stored = storedText(st);
        if (storedNode) {
            storedNode.style.display = stored === null ? 'none' : '';
            text(storedNode, stored === null ? '' : stored);
        }

        var done = el('uv-scan-done');
        if (st.terminal) {
            // The terminal sentence and the coverage sentence are separate,
            // because a run can finish and still not have covered the project -
            // and a reader given only one of the two draws the wrong conclusion
            // from it.
            var sentence = endedSentence(st);
            var verdict = verdictText(st);
            if (verdict) sentence += ' ' + verdict;
            var asOf = asOfText(st);
            if (asOf) sentence += ' ' + asOf;
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

        // STALLED IS FOR A TAB THAT IS WATCHING. The one driving the scan learns
        // that a pass made no progress from the pass itself and says so in the
        // note; showing both would be two sentences about one fact. Revealed
        // before it is written, for the reason the completion sentence is.
        //
        // THREE SENTENCES, AND ONLY ONE IS THE ALARM (review CL-2). The server
        // decides which: `stopping` for a run Stop was pressed on, `stalled`
        // for a run a worker holds while nothing finishes, `paused` for a run
        // nobody is working. Paused used to arrive as stalled, so a run left by
        // a closed tab - the ordinary way to leave one - told the next reader
        // to stop it and start again, beside the Continue button that was the
        // right answer. ScanPageView::stalledText() picks in the same order.
        //
        // Planning is a slice per request since B10, driven by this pump like
        // any phase, so a quiet planning run is paused or stalled by the same
        // rule as a scanning one, and Continue resumes the list from where it
        // stopped. The fourth sentence R2O-3 added, which sent the reader to
        // Stop and hid Continue, threw that list away and is gone.
        var stalledNode = el('uv-scan-stalled');
        if (stalledNode) {
            var L2 = labels(), quiet = null;
            if (!st.terminal && st.active && !UV.state.running) {
                if (st.stopping) quiet = L2.stopping || '';
                else if (st.stalled) quiet = L2.stalled || '';
                else if (st.paused) quiet = L2.paused || '';
            }
            if (quiet) {
                var mins = Math.max(1, Math.floor((st.idleSeconds | 0) / 60));
                stalledNode.style.display = '';
                text(stalledNode, fill(quiet, { minutes: mins }));
            } else {
                stalledNode.style.display = 'none';
                text(stalledNode, '');
            }
        }

        // A finished run's report opens below the panel (B5), once per run.
        if (st.terminal) reportLoad(st.run_id);

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
        // `moved` is the server's own yes or no. A group-scoped run gives no
        // counters until it has proved its scope (H10 part 1), and a planning
        // page holding only other groups' records lists nothing a reader may
        // count (review R5-1); both still moved the run.
        var moved = (r.moved === true) || (((r.worked | 0) + (r.listed | 0) + (r.requeued | 0)
                    + (r.blocked | 0) + (r.findings | 0)) > 0);
        if (st) {
            if (!moved && UV.state.seen) {
                // SETTLED, not examined: a pass that excluded a record moved the
                // run on exactly as much as one that examined it.
                if (typeof settled(st) === 'number' && settled(st) > UV.state.lastDone) moved = true;
                if (st.phase && st.phase !== UV.state.lastPhase) moved = true;
            }
            UV.state.seen = true;
            if (typeof settled(st) === 'number') UV.state.lastDone = settled(st);
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

    /**
     * Read the run once, and keep reading it while this tab only WATCHES it.
     *
     * A WATCHING TAB ASKED ONCE (review CL-4). The page attaches without
     * resuming, so a tab opened beside a running scan - or after it was left -
     * asked scan-status a single time and never again: a run that stalled
     * later was never shown as stalled, and a stall sentence that had stopped
     * being true was never taken down. While the run is active and this tab is
     * not driving it, the status is asked again on the no-progress ladder
     * (IDLE_MIN doubling to IDLE_MAX), never at 0 ms, and the asking stops when
     * the run is terminal, when the server refuses FOR GOOD, or when this tab
     * starts driving - pump() renders every answer itself from then on.
     *
     * NOT EVERY REFUSAL IS FOR GOOD (review R2O-4). The endpoint answers a
     * database blip with ok:false too - its catch-all "could not be reached" -
     * and the watch used to end on it, so the tab never learned that the run
     * finished. The server marks the refusals that will not change (no such
     * run, not yours to read) with `final`; any other refusal is asked again
     * on the same ladder, and its sentence is taken down when an answer
     * arrives.
     *
     * ONE WATCH PER TAB, by the same device as the driver: every refresh() is a
     * new chain, and a timer from an older one dies when it fires.
     */
    function refresh() {
        if (!UV.state.runId) return;
        UV.state.watchWait = 0;
        poll(++UV.state.watchChain);
    }

    function poll(mine) {
        call('scan-status', { run_id: UV.state.runId }).then(function (st) {
            if (mine !== UV.state.watchChain) return;
            var note = el('uv-scan-note');
            try {
                render(st);
            } catch (e) {
                // As before: a bug in rendering is not a reason to stop
                // watching a scan that is still on the server.
            }
            // The refusal sentence this watch wrote, and only that one: a note
            // something else has written since is not this watch's to clear.
            if (!st || st.ok === false) {
                UV.state.watchNote = note ? note.textContent : null;
            } else {
                if (note && UV.state.watchNote !== null && note.textContent === UV.state.watchNote) {
                    text(note, '');
                }
                UV.state.watchNote = null;
                if (st.terminal) settleStopNote(st);
            }
            keepWatching(st, mine);
        }, function (e) {
            if (mine !== UV.state.watchChain) return;
            // A transport that does not exist is not a lost connection, and
            // asking it again can never succeed - see pump().
            if (e && e.uvFatal) return;
            // The status did not arrive. The run did not therefore finish:
            // ask again, on the same ladder, and never at once.
            keepWatching(null, mine);
        });
    }

    /**
     * Take "Stopping the scan." down once the run is over (review R3U-5).
     *
     * Stop answers stopped:false when it could only ASK - commonly because
     * this tab's own work request was in flight and held the run - and that
     * request then ends it. The note was written once and never revisited, so
     * the finished panel sat under "Stopping the scan." for as long as the
     * page was open. The first terminal status replaces it with what happened.
     * Only Stop's own sentence: a note something else has written since is not
     * this one's to change.
     */
    function settleStopNote(st) {
        var note = el('uv-scan-note');
        if (UV.state.stopNote !== null && note && note.textContent === UV.state.stopNote) {
            text(note, st.terminal === 'cancelled' ? 'The scan was stopped.'
                : 'The scan ended before it could be stopped.');
        }
        UV.state.stopNote = null;
    }

    function keepWatching(st, mine) {
        if (UV.state.running || UV.state.starting) return;
        var over = st ? (st.ok === false ? st.final === true : (st.terminal || !st.active)) : false;
        if (over) {
            UV.state.watchWait = 0;
            return;
        }
        var w = UV.state.watchWait = UV.state.watchWait
            ? Math.min(IDLE_MAX, UV.state.watchWait * 2) : IDLE_MIN;
        setTimeout(function () {
            if (mine !== UV.state.watchChain || UV.state.running || UV.state.starting) return;
            poll(mine);
        }, w);
    }

    UV.start = function () {
        if (UV.state.running || UV.state.starting) return;
        UV.state.starting = true;
        UV.state.chain++;
        UV.state.watchChain++;               // a tab that drives is no longer watching
        UV.state.stopNote = null;
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
            // The last run's report is put away while the new run goes; the
            // new one's opens when its status says it finished.
            reportReset();
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
        UV.state.watchChain++;               // a tab that drives is no longer watching
        UV.state.stopNote = null;            // a driver writes its own notes
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
            // `stopped` is the server saying the run has ENDED, which it now
            // does at once for a run nobody was working (review R2O-5).
            // "Stopping" over a finished run would be the one stale word, so
            // when that is what has to be said, the watch below replaces it
            // once a status comes back terminal (settleStopNote, R3U-5).
            var asked = !(r && r.why) && !(r && r.stopped);
            text(el('uv-scan-note'), (r && r.why) ? r.why
                : ((r && r.stopped) ? 'The scan was stopped.' : 'Stopping the scan.'));
            UV.state.stopNote = asked ? 'Stopping the scan.' : null;
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

    /* ---------------------------------------------------------------------
     * THE FINDINGS PANEL (B5)
     *
     * A finished run's report, one keyset page at a time, from scan-findings.
     * The server decides every cell, every withheld value and whether the
     * reader may see the run at all; this renders what it said, through
     * textContent only, and keeps the cursor.
     *
     * KEYSET PAGES HAVE NO NUMBER. `next` is the last finding id of the page
     * just read, so Next page asks after it and Previous page needs the
     * `after` of every page shown before: a stack. Only pages that SHOWED
     * rows go on it, so Previous never lands on an empty page.
     *
     * ONE REQUEST AT A TIME, NEVER AT 0 ms. A press while one is out is
     * dropped (Next and Previous) or kept for when it lands (a filter, which
     * starts over anyway). A refusal marked `final` is the last word and is
     * never asked again; any other refusal, and a request that did not
     * complete, is asked again on the no-progress ladder (IDLE_MIN doubling
     * to IDLE_MAX).
     * ------------------------------------------------------------------- */

    /** Rows one page asks for. ScanService caps it at FINDINGS_PAGE_MAX. */
    var REPORT_LIMIT = 50;
    /**
     * AN EMPTY PAGE WITH A `next` IS NOT "NO FINDINGS" (review rv11a2-6).
     * With two filters set the server reads the first filter's key a window
     * at a time, and a window can hold no row matching the second while later
     * windows do. The panel reads on, up to REPORT_SKIP_MAX more pages for one
     * press and REPORT_SKIP_MS apart, then says it is still looking and leaves
     * Next page to the reader. "No findings match" is said only when `next`
     * is null and nothing was shown.
     */
    var REPORT_SKIP_MAX = 4, REPORT_SKIP_MS = 250;
    /** The filters scan-findings takes, in the order the page shows them. */
    var FILTERS = ['form', 'reason', 'type', 'group'];

    UV.report = {
        runId: null,            // the run whose report is open, or null
        gen: 0,                 // which question is current; an older answer is dropped
        inflight: false,        // one scan-findings in flight, always
        waiting: false,         // a timer is queued to ask again
        wait: 0,                // the retry ladder, 0 until a refusal
        at: 0,                  // the `after` of the page asked for
        stack: [],              // the `after` of every page shown before it
        next: null,             // the cursor of the page shown, null on the last
        shown: false,           // whether the page on screen holds rows
        skips: 0,               // empty pages read past for this press
        stopped: false,         // a final refusal: never asked again
        facetsFor: null,        // the run the filter options were built for
        offer: null,            // {current, newer}: the page's two runs (rv11b2-5), or null
        exportFor: null,        // the run the download is offered for, or null
        filter: { form: '', reason: '', type: '', group: '' }
    };

    function isRunId(v) {
        return typeof v === 'number' && v > 0 && v === Math.floor(v);
    }

    function show(node, on) {
        if (node) node.style.display = on ? '' : 'none';
    }

    function clearNode(node) {
        if (!node) return;
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    /** A child element with its text set as text, never as markup. */
    function child(parent, tag, s) {
        var c = document.createElement(tag);
        if (s !== undefined) c.textContent = (s === null) ? '' : String(s);
        parent.appendChild(c);
        return c;
    }

    function reportNote(s) { text(el('uv-scan-report-note'), s); }

    /**
     * Put the report away: a new run is going, or the page has none. The
     * page's offer of a newer run goes with it: once a new run is going,
     * which report is current is that run's to say.
     */
    function reportReset() {
        var r = UV.report;
        r.runId = null;
        r.gen++;
        r.waiting = false;
        r.offer = null;
        reportOther();
        show(el('uv-scan-report'), false);
    }

    /**
     * Open a run's report on its first page, once per run. THE RUNS IT IS
     * HANDED are the page's two (finishedRun(): the current report and a
     * newer run that did not complete) and the run this tab watched to its
     * end (review rv11b1-2); the answer says which scope each covered.
     */
    function reportLoad(runId) {
        var r = UV.report;
        if (!el('uv-scan-report') || !isRunId(runId) || r.runId === runId) return;
        r.runId = runId;
        r.filter = { form: '', reason: '', type: '', group: '' };
        r.facetsFor = null;
        r.stopped = false;
        reportOther();
        reportAsk(0, true);
    }

    /**
     * THE NEWER RUN BESIDE THE CURRENT REPORT (review rv11b2-5). A scan
     * stopped seconds after it started hid the report the store still treats
     * as current, which stayed reachable only by its id. The page opens the
     * current report and names the newer run here, with a control that opens
     * it, and from it the way back. Shown only while one of the two is open.
     */
    function reportOther() {
        var r = UV.report, o = r.offer, L = labels();
        var p = el('uv-scan-report-other'), b = el('uv-scan-report-switch');
        var on = o !== null && (r.runId === o.current || r.runId === o.newer);
        if (on && r.runId === o.current) {
            text(p, fill(L.reportNewer || 'Scan {run} started after this one and did not complete, so this report is still the current one.', { run: o.newer }));
            text(b, fill(L.reportShowNewer || 'Show what scan {run} found', { run: o.newer }));
        } else if (on) {
            text(p, fill(L.reportViewingNewer || 'This is scan {run}, which did not complete. The current report is scan {current}.', { run: o.newer, current: o.current }));
            text(b, L.reportShowCurrent || 'Show the current report');
        }
        show(p, on);
        show(b, on);
    }

    function reportSwitch() {
        var r = UV.report, o = r.offer;
        if (o === null || (r.runId !== o.current && r.runId !== o.newer)) return;
        reportLoad(r.runId === o.current ? o.newer : o.current);
    }

    /**
     * A GROUP WHOSE LAST REPORT A WHOLE-PROJECT SCAN REPLACED (review
     * rv11b1-1). That scan closed the group's findings, and the group may not
     * read it, so there is no report to open: the page says so in one
     * sentence, instead of opening an empty report under a count of findings.
     */
    function reportReplaced() {
        var L = labels();
        if (!el('uv-scan-report')) return;
        show(el('uv-scan-report'), true);
        reportBody(false);
        reportNote(L.reportReplaced || 'A scan of the whole project has replaced the last report of this Data Access Group. Start a scan of the group to see its current findings.');
    }

    /**
     * Start one reader action: the page after $after, with the stack kept or
     * emptied. Everything a press changes is set here, and only here.
     */
    function reportAsk(after, fresh) {
        var r = UV.report;
        if (fresh) r.stack = [];
        r.at = after;
        r.skips = 0;
        r.wait = 0;
        r.gen++;
        r.waiting = false;
        reportSend();
    }

    function reportSend() {
        var r = UV.report;
        if (r.inflight || r.runId === null || r.stopped) return;
        var gen = r.gen, filter = {}, i, L = labels();
        for (i = 0; i < FILTERS.length; i++) filter[FILTERS[i]] = r.filter[FILTERS[i]];
        r.inflight = true;
        attr(el('uv-scan-report'), 'aria-busy', 'true');
        reportNote(L.reportLoading || 'Loading the findings.');
        call('scan-findings', { run_id: r.runId, after: r.at, limit: REPORT_LIMIT, filter: filter })
            .then(function (a) {
                r.inflight = false;
                attr(el('uv-scan-report'), 'aria-busy', 'false');
                // The reader asked something else while this was out: ask
                // that instead, and show nothing of the answer to this.
                if (gen !== r.gen) { reportSend(); return; }
                try {
                    reportAnswer(a);
                } catch (e) {
                    reportNote('This page could not show the findings the server sent. Reload the page; '
                             + 'nothing has been lost.');
                }
            }, function (e) {
                r.inflight = false;
                attr(el('uv-scan-report'), 'aria-busy', 'false');
                if (gen !== r.gen) { reportSend(); return; }
                if (e && e.uvFatal) {
                    reportNote('This page could not reach the module\'s JavaScript transport, so the '
                             + 'findings cannot be shown. Reload the page.');
                    return;
                }
                reportLater('Lost contact with the server.');
            });
    }

    /** Ask the same question again, on the no-progress ladder, never at once. */
    function reportLater(why) {
        var r = UV.report, gen = r.gen, L = labels();
        var w = r.wait = r.wait ? Math.min(IDLE_MAX, r.wait * 2) : IDLE_MIN;
        // The download is offered beside an answer, and this is none.
        reportExport(null);
        reportNote(why + ' ' + (L.reportRetry || 'Trying again shortly.'));
        r.waiting = true;
        setTimeout(function () {
            if (gen !== r.gen) return;
            r.waiting = false;
            reportSend();
        }, w);
    }

    function filtered() {
        var r = UV.report, i;
        for (i = 0; i < FILTERS.length; i++) if (r.filter[FILTERS[i]] !== '') return true;
        return false;
    }

    /** What one completed scan-findings answer means. */
    function reportAnswer(a) {
        var r = UV.report, L = labels();
        if (!a || a.ok === false) {
            if (a && a.final === true) {
                // The last word: rights withdrawn, or no such run. Nothing
                // of the report stays on screen, and nothing is asked again.
                r.stopped = true;
                show(el('uv-scan-report'), true);
                reportBody(false);
                text(el('uv-scan-report-summary'), '');
                text(el('uv-scan-report-count'), '');
                reportMarks(null);
                show(el('uv-scan-report-degraded'), false);
                show(el('uv-scan-report-withheld'), false);
                reportNote((a && a.why) ? a.why : (L.reportFailed || 'The findings of this scan could not be read just now.'));
                return;
            }
            reportLater((a && a.why) ? a.why : (L.reportFailed || 'The findings of this scan could not be read just now.'));
            return;
        }
        r.wait = 0;
        show(el('uv-scan-report'), true);
        reportSummary(a);
        reportMarks(a);
        if (a.withheld) {
            // A RUN THAT HAS NOT PROVED ITS GROUP SHOWS NO TABLE (H10): a row
            // is a count of one. Its sentence, and nothing to download.
            reportBody(false);
            reportExport(a);
            var held = el('uv-scan-report-withheld');
            text(held, (typeof a.withheldWhy === 'string' && a.withheldWhy) ? a.withheldWhy
                : (L.withheldEnded || 'This scan covers one Data Access Group and ended before it confirmed which records belong to that group, so its counts cannot be shown.'));
            show(held, true);
            reportNote('');
            return;
        }
        show(el('uv-scan-report-withheld'), false);
        reportExport(a);
        reportFacets(a);
        var rows = (a.rows && a.rows.length) ? a.rows : [];
        var next = isRunId(a.next) ? a.next : null;
        if (!rows.length && next !== null) {
            if (r.skips < REPORT_SKIP_MAX) {
                // Read on, a moment later, keeping what is on screen.
                r.skips++;
                r.at = next;
                reportNote(L.reportLooking || 'Looking further for findings that match these filters.');
                var gen = r.gen;
                r.waiting = true;
                setTimeout(function () {
                    if (gen !== r.gen) return;
                    r.waiting = false;
                    reportSend();
                }, REPORT_SKIP_MS);
                return;
            }
            r.next = next;
            r.shown = false;
            reportTable(a, rows);
            reportNote(L.reportSearching || 'Still looking: the pages read so far held no finding that matches these filters, and there are more to read. Press Next page to keep looking.');
            return;
        }
        r.next = next;
        r.shown = rows.length > 0;
        reportTable(a, rows);
        if (rows.length) {
            reportNote('');
        } else if (r.stack.length) {
            reportNote(L.reportNoMore || 'No further findings match these filters.');
        } else if (filtered()) {
            reportNote(L.reportEmpty || 'No findings match these filters.');
        } else {
            // NEVER "STORED NO FINDINGS" UNDER A COUNT OF SOME (review
            // rv11b1-1): a report whose rows were closed since its count was
            // taken reads as missing them, not as clean.
            reportNote(whole((a.report || {}).expectedRows) > 0
                ? (L.reportMissing || 'This report counts findings that could not be read now. It may have been replaced since this page was opened; reload the page to open the current report.')
                : (L.reportNone || 'This scan stored no findings.'));
        }
    }

    /** The table, the filters, the controls and the download, on or off together. */
    function reportBody(on) {
        show(el('uv-scan-report-table'), on);
        show(el('uv-scan-report-nav'), on);
        show(el('uv-scan-report-filters'), on && UV.report.facetsFor !== null);
        if (!on) show(el('uv-scan-report-export-wrap'), false);
    }

    /**
     * What the whole report may be said to be: its coverage sentence and its
     * verdict, as the panel says them (W7-N10: no decision is not certified),
     * how many findings it holds, and whether its labels are today's.
     */
    function reportSummary(a) {
        var rep = a.report || {}, L = labels();
        // A withheld run says why in its own sentence; its coverage sentence
        // is about records its reader is not shown.
        var parts = [];
        if (!a.withheld && typeof rep.coverageSentence === 'string' && rep.coverageSentence !== '') {
            parts.push(rep.coverageSentence);
        }
        // WHAT THE RUN COVERED (review rv11b1-2): a run over one group says
        // so, whoever reads it, as its file does.
        var sc = a.runScope || {};
        if (sc.kind === 'group' && typeof sc.label === 'string' && sc.label !== '') {
            parts.push(fill(L.reportGroupOnly || 'This scan covered {group} only, not the whole project.', { group: sc.label }));
        }
        // A WITHHELD RUN NAMES NO RULE COUNT (review rv11b1-3): how many rules
        // it could not evaluate is a statement about what it read, and its
        // status says only that it does not certify (preFenceStatus()). The
        // server leaves the count out of such an answer (rv11a1-3); this
        // does not read one that arrives anyway.
        var v = verdictText({ terminal: true, clean: a.clean, ruleProblems: a.withheld ? 0 : a.ruleProblems });
        if (v) parts.push(v);
        // AND THE DAY IT WAS JUDGED AS OF (review rv11b1-4), as the panel says
        // it for a run this tab watched to its end: a report opened from the
        // page never said it.
        var d = asOfText({ asOf: rep.asOf });
        if (d) parts.push(d);
        text(el('uv-scan-report-summary'), parts.join(' '));
        var n = rep.expectedRows, count;
        if (typeof n === 'number' && n >= 0 && n === Math.floor(n)) {
            count = fill(L.reportCount || 'This report holds {findings}.',
                         { findings: grouped(n) + ' finding' + (n === 1 ? '' : 's') });
        } else {
            count = L.foundUnknown || 'Findings not counted, because the scan ended before it could total them';
        }
        text(el('uv-scan-report-count'), a.withheld ? '' : count);
        var deg = el('uv-scan-report-degraded');
        var said = (rep.labelsDegraded && typeof rep.degradedSummary === 'string') ? rep.degradedSummary : '';
        text(deg, said);
        show(deg, said !== '');
    }

    /**
     * README's three marks of a report that cannot certify (item 10), as the
     * file carries them: marks.banner above the table, marks.row below it -
     * the page's terminal row - and the file name's suffix beside the
     * download (reportExport()). The server decides all three
     * (ScanService::incompleteMarks()); a run that is not incomplete shows
     * none of them.
     */
    function reportMarks(a) {
        var m = (a && a.marks) || {}, on = m.incomplete === true;
        var banner = el('uv-scan-report-banner'), row = el('uv-scan-report-row');
        var bannerOn = on && typeof m.banner === 'string' && m.banner !== '';
        text(banner, bannerOn ? m.banner : '');
        show(banner, bannerOn);
        // A withheld run has no rows for a terminal row to come after.
        var rowOn = on && !(a && a.withheld) && typeof m.row === 'string' && m.row !== '';
        text(row, rowOn ? m.row : '');
        show(row, rowOn);
    }

    /** The page: its column heads, its rows, its caption and the two controls. */
    function reportTable(a, rows) {
        var r = UV.report, L = labels(), i, j;
        var cols = (a.columns && a.columns.length) ? a.columns : [];
        var head = el('uv-scan-report-head'), body = el('uv-scan-report-body');
        clearNode(head);
        for (i = 0; i < cols.length; i++) {
            attr(child(head, 'th', cols[i].label), 'scope', 'col');
        }
        clearNode(body);
        for (i = 0; i < rows.length; i++) {
            var tr = child(body, 'tr'), cells = rows[i].cells || {};
            for (j = 0; j < cols.length; j++) {
                var v = Object.prototype.hasOwnProperty.call(cells, cols[j].key) ? cells[cols[j].key] : '';
                child(tr, 'td', v);
            }
        }
        text(el('uv-scan-report-caption'),
             fill(L.reportCaption || 'Findings of this scan, page {page}', { page: r.stack.length + 1 }));
        reportBody(true);
        // aria-disabled, NOT disabled: a press on the last page keeps the
        // focus on the button it pressed, where `disabled` drops it on the
        // page body. The handlers below refuse the press themselves.
        var prev = el('uv-scan-report-prev'), nextB = el('uv-scan-report-next');
        attr(prev, 'aria-disabled', r.stack.length ? 'false' : 'true');
        attr(nextB, 'aria-disabled', r.next !== null ? 'false' : 'true');
    }

    /**
     * The four filters, from the run's summary: one option per value with its
     * count, and All. Built once per run: they are the run's, not the page's,
     * and rebuilding a select under a keyboard user moves them.
     */
    function reportFacets(a) {
        var r = UV.report, L = labels(), i, k, any = false;
        if (r.facetsFor === r.runId) return;
        for (i = 0; i < FILTERS.length; i++) {
            var name = FILTERS[i], sel = el('uv-scan-filter-' + name);
            var list = (a.facets && a.facets[name] && a.facets[name].length) ? a.facets[name] : [];
            show(el('uv-scan-filter-' + name + '-wrap'), list.length > 0);
            if (!sel) continue;
            clearNode(sel);
            var all = child(sel, 'option', L.filterAll || 'All');
            all.value = '';
            for (k = 0; k < list.length; k++) {
                var o = child(sel, 'option', String(list[k].label) + ' (' + grouped(list[k].count | 0) + ')');
                o.value = String(list[k].value);
            }
            sel.value = r.filter[name];
            if (list.length) any = true;
        }
        r.facetsFor = any ? r.runId : null;
        show(el('uv-scan-report-filters'), any);
    }

    /**
     * The download, offered only beside an answer that showed the report.
     *
     * A BUTTON THAT POSTS, NOT A LINK (W11F-N5). The link was a GET, which
     * the framework does not check for its CSRF token, so another site could
     * send a signed-in reader to the file by a top-level navigation.
     * pages/export.php now answers only a POST with run_id and the page's
     * redcap_csrf_token (reportDownload()); without a token nothing is
     * offered. The button names the whole report and its count (review
     * rv11b1-5): the filters narrow the table on the page, never the file.
     */
    function reportExport(a) {
        var r = UV.report, btn = el('uv-scan-report-export'), L = labels();
        var url = (typeof UV.exportUrl === 'string') ? UV.exportUrl : '';
        var token = (typeof UV.csrfToken === 'string') ? UV.csrfToken : '';
        var ok = a && a.ok === true && !a.withheld && url !== '' && token !== '' && isRunId(r.runId)
            && a.run_id === r.runId;
        var suffix = el('uv-scan-report-suffix');
        if (!ok) {
            r.exportFor = null;
            show(el('uv-scan-report-export-wrap'), false);
            text(suffix, '');
            show(suffix, false);
            return;
        }
        r.exportFor = r.runId;
        var n = (a.report || {}).expectedRows;
        text(btn, (typeof n === 'number' && n >= 0 && n === Math.floor(n))
            ? fill(L.reportDownload || 'Download the whole report as a CSV file ({findings})',
                   { findings: grouped(n) + ' finding' + (n === 1 ? '' : 's') })
            : (L.reportDownloadPlain || 'Download the whole report as a CSV file'));
        show(el('uv-scan-report-export-wrap'), true);
        // The third mark: the file's name says it, wherever the file goes.
        var m = a.marks || {};
        var said = (m.incomplete === true && typeof m.suffix === 'string' && m.suffix !== '')
            ? fill(L.reportSuffix || 'Its file name ends in {suffix}, so the file says it is incomplete wherever it is saved.', { suffix: m.suffix })
            : '';
        text(suffix, said);
        show(suffix, said !== '');
    }

    /**
     * Post the download (W11F-N5): a form built for the press, outside any
     * form on the page - the panel sits inside REDCap's project form, and a
     * nested form is no form - carrying the run the button was offered for
     * and the token, and taken away again once sent.
     */
    function reportDownload() {
        var r = UV.report, body = document.body;
        var url = (typeof UV.exportUrl === 'string') ? UV.exportUrl : '';
        var token = (typeof UV.csrfToken === 'string') ? UV.csrfToken : '';
        if (!isRunId(r.exportFor) || r.exportFor !== r.runId || url === '' || token === '' || !body) return;
        var f = document.createElement('form'), fields = { run_id: String(r.exportFor), redcap_csrf_token: token }, k;
        attr(f, 'method', 'post');
        attr(f, 'action', url);
        f.style.display = 'none';
        for (k in fields) {
            if (Object.prototype.hasOwnProperty.call(fields, k)) {
                var input = document.createElement('input');
                attr(input, 'type', 'hidden');
                attr(input, 'name', k);
                attr(input, 'value', fields[k]);
                f.appendChild(input);
            }
        }
        body.appendChild(f);
        try {
            f.submit();
        } finally {
            body.removeChild(f);
        }
    }

    function reportNext() {
        var r = UV.report;
        if (r.inflight || r.waiting || r.stopped || r.next === null) return;
        if (r.shown) r.stack.push(r.at);
        reportAsk(r.next, false);
    }

    function reportPrev() {
        var r = UV.report;
        if (r.inflight || r.waiting || r.stopped || !r.stack.length) return;
        reportAsk(r.stack.pop(), false);
    }

    function reportFilter(name, value) {
        var r = UV.report;
        if (r.runId === null || r.stopped) return;
        r.filter[name] = String(value === null || value === undefined ? '' : value);
        r.next = null;
        r.shown = false;
        reportAsk(0, true);
    }

    /** A report control's handler: never the page's default action, never a throw. */
    function quietly(fn) {
        return function (e) {
            if (e && e.preventDefault) e.preventDefault();
            try { fn(e); } catch (err) {
                reportNote('This page could not change the findings shown. Reload the page; nothing has '
                         + 'been lost.');
            }
        };
    }

    function attachReport(opts) {
        var b, i;
        if (typeof opts.exportUrl === 'string') UV.exportUrl = opts.exportUrl;
        if (typeof opts.csrfToken === 'string') UV.csrfToken = opts.csrfToken;
        if ((b = el('uv-scan-report-export'))) b.addEventListener('click', quietly(reportDownload));
        if ((b = el('uv-scan-report-next'))) b.addEventListener('click', quietly(reportNext));
        if ((b = el('uv-scan-report-prev'))) b.addEventListener('click', quietly(reportPrev));
        if ((b = el('uv-scan-report-switch'))) b.addEventListener('click', quietly(reportSwitch));
        for (i = 0; i < FILTERS.length; i++) {
            (function (name) {
                var sel = el('uv-scan-filter-' + name);
                if (sel) sel.addEventListener('change', quietly(function () { reportFilter(name, sel.value); }));
            })(FILTERS[i]);
        }
        var current = isRunId(opts.reportRunId) ? opts.reportRunId : null;
        var newer = (isRunId(opts.reportNewerId) && opts.reportNewerId !== current) ? opts.reportNewerId : null;
        UV.report.offer = (current !== null && newer !== null) ? { current: current, newer: newer } : null;
        if (current !== null) reportLoad(current);
        else if (opts.reportReplaced === true) reportReplaced();
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
        attachReport(opts);
        if (opts.runId) {
            UV.state.runId = opts.runId;
            refresh();
            if (opts.autoResume) UV.resume();
        }
    };
})();
