<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * The one place a run is allowed to become finished, and the one place that
 * decides what it may claim.
 *
 * THIS IS THE FILE THE WHOLE REBUILD IS FOR. The legacy scan assigned
 * `status = 'complete'` at the bottom of a loop that a `continue` could skip,
 * and every other defect in the review is downstream of that: a run that
 * examined nothing produced the same string as a run that examined everything,
 * so the green tick, the filename and the export header all agreed with each
 * other and all disagreed with the project.
 *
 * TWO STEPS, DELIBERATELY SEPARATE.
 *
 *   facts()   gathers what is true about the run - from record states, from
 *             aggregates, from both finalizers, from the fence, from the
 *             configuration as it is NOW.
 *   promote() turns those facts into an outcome with ScanOutcome::derive() and
 *             writes it, once.
 *
 * The split exists so the hard half is a pure function. Whether a tombstone
 * blocks coverage, whether a pending finalizer means "not yet" or "nothing to
 * do", whether an unreadable record outranks a truncated budget - those are the
 * decisions worth being able to state as a table and test without a database,
 * and they are exactly the decisions that would otherwise be buried inside a
 * transaction nobody can run twice.
 *
 * WHAT NEVER PROMOTES, and each of these is a defect the review named:
 *
 *   an unfinished manifest             a cursor at the end is not a manifest at
 *                                      its end; stragglers are states, not
 *                                      positions
 *   an unread or unstable record       reported, and enough on its own
 *   an undecidable duplicate group     the module could not answer, so it
 *                                      declines to answer
 *   a finalizer that has not finished  including one that never started
 *   a changed fingerprint or policy    the run describes two configurations
 *   a requested cancellation           honoured, whatever else is true
 *
 * A TOMBSTONE IS NOT A HOLE. A record deleted from the project during the run
 * cannot be read and will never be read, so requiring it to reach `done` holds
 * the run open forever - C3's mirror case. It reaches a terminal state of its
 * own and is counted, so a reader can see the project changed underneath the
 * scan without the run being unable to end.
 *
 * PHP 7.4.
 */
final class ScanPromotion
{
    /** Aggregate kinds that are collection gaps: reported, never violations, never blocking. */
    const GAP_KINDS = 'collection-gap';
    /** Aggregate kinds that are rule problems: they forbid clean but do not cap coverage. */
    const RULE_KINDS = 'rule-problem';

    /**
     * Everything the decision needs, in one shape.
     *
     * Every field defaults to the SAFE reading, matching ScanOutcome::derive():
     * a caller that forgets one gets the weaker claim. The direction matters -
     * a missed field costs an unnecessary suffix, and the opposite costs a
     * certification of a project nobody scanned.
     *
     * @param array $run     the stored run row
     * @param array $states  record state => count, from ScanStore::recordStates()
     * @param array $in {
     *   blockingAggregates: int
     *   gapCount:           int
     *   ruleProblems:       int
     *   uniqueDone:         bool
     *   uniqueBlocking:     int
     *   rollupDone:         bool
     *   fingerprintNow:     ?string
     *   policyRevisionNow:  ?int
     *   maxFindings:        ?int
     *   maxBytes:           ?int
     * }
     * @return array{ready:bool, facts:array, why:?string}
     */
    public static function facts(array $run, array $states, array $in = [])
    {
        $g = function ($k, $d = null) use ($in) { return isset($in[$k]) ? $in[$k] : $d; };
        $n = function ($state) use ($states) {
            return isset($states[$state]) ? (int) $states[$state] : 0;
        };

        $pending = $n(ScanStore::REC_PENDING) + $n(ScanStore::REC_CLAIMED);
        $unread  = $n(ScanStore::REC_UNREADABLE) + $n(ScanStore::REC_UNSTABLE);

        $cancelled = !empty($run['cancel_requested_at'])
                     || (isset($run['phase']) && $run['phase'] === ScanPhase::CANCELLING);

        // THE CONFIGURATION MOVED. Not "partial" - failed. A run half-checked
        // against rules that no longer exist does not describe the project under
        // either configuration, and the danger is that it looks like it does.
        $fpNow = $g('fingerprintNow');
        $fpBad = ($fpNow !== null && isset($run['fingerprint'])
                  && !ScanPlanner::fingerprintMatches($run['fingerprint'], $fpNow));
        $polNow = $g('policyRevisionNow');
        $polBad = ($polNow !== null && isset($run['policy_revision'])
                   && (int) $run['policy_revision'] !== (int) $polNow);

        $rows  = isset($run['detail_rows']) ? (int) $run['detail_rows'] : 0;
        $bytes = isset($run['detail_bytes']) ? (int) $run['detail_bytes'] : 0;
        $maxR  = $g('maxFindings');
        $maxB  = $g('maxBytes');
        $truncated = (($maxR !== null && $maxR > 0 && $rows >= (int) $maxR)
                   || ($maxB !== null && $maxB > 0 && $bytes >= (int) $maxB));

        $facts = [
            // A proved window, and a proved window only. `fence_target` is
            // written by catch-up when the change log covered the whole run;
            // when it could not, the field stays empty and the run keeps
            // `manifest-complete`, which says exactly what it did.
            'fenced' => !empty($run['fence_target']),
            'manifestDone' => ($pending === 0),
            // Unread and unstable records, undecidable duplicate groups, and
            // anything an aggregate marked blocking. A TOMBSTONE IS ABSENT FROM
            // THIS LIST on purpose - see the class note.
            'blocked' => ($unread > 0 || (int) $g('blockingAggregates', 0) > 0
                          || (int) $g('uniqueBlocking', 0) > 0),
            'truncated' => $truncated,
            'cancelled' => $cancelled,
            'failed' => ($fpBad || $polBad),
            'violations' => $rows,
            'ruleProblems' => (int) $g('ruleProblems', 0),
            'gaps' => (int) $g('gapCount', 0),
            'labelDegraded' => !empty($run['labelDegraded']),
        ];

        // READY is a different question from WHAT IT MAY CLAIM. A run with work
        // left is not promoted at all; a run with no work left is promoted to
        // whatever it earned, which may be very little.
        $why = null;
        $ready = true;
        if ($pending > 0) {
            $ready = false;
            $why = 'records are still waiting to be examined';
        } elseif (!$g('uniqueDone', false)) {
            $ready = false;
            $why = 'duplicate values have not finished being decided';
        } elseif (!$g('rollupDone', false)) {
            $ready = false;
            $why = 'the summary has not finished being built';
        }
        // A cancellation or an unrecoverable failure ends the run WHATEVER is
        // outstanding: continuing to wait for a finalizer on a run nobody wants
        // is how a cancelled scan keeps its project's slot.
        if (!$ready && ($facts['cancelled'] || $facts['failed'])) {
            $ready = true;
            $why = null;
        }

        return ['ready' => $ready, 'facts' => $facts, 'why' => $why];
    }

    /**
     * Finish the run, or explain why it is not finishable yet.
     *
     * Idempotent through the store: `finish()` only affects a run that still
     * holds its project slot, so a retried finaliser cannot reopen or rewrite a
     * run that a report has already been exported against.
     *
     * @return array{promoted:bool, outcome:?array, why:?string}
     */
    public static function promote(ScanStore $store, $pid, $runId, array $in = [])
    {
        $run = $store->run($pid, $runId);
        if ($run === null) {
            // Same wording as every other unknown run: a message distinguishing
            // "not here" from "not yours" is an oracle for anyone holding a
            // project link.
            return ['promoted' => false, 'outcome' => null,
                    'why' => 'no scan with that reference is running for this project'];
        }
        if (isset($run['phase']) && $run['phase'] === ScanPhase::TERMINAL) {
            return ['promoted' => false, 'outcome' => null,
                    'why' => 'this scan has already finished'];
        }

        $f = self::facts($run, $store->recordStates($runId), $in);
        if (!$f['ready']) {
            return ['promoted' => false, 'outcome' => null, 'why' => $f['why']];
        }

        $outcome = ScanOutcome::derive($f['facts']);
        $outcome['suffix'] = ScanOutcome::suffix($outcome);
        $ok = $store->finish($runId, $outcome);
        return ['promoted' => $ok, 'outcome' => $outcome,
                'why' => $ok ? null : 'another worker finished this scan first'];
    }
}
