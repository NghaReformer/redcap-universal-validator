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
 * scan without the run being unable to end. It is not an examination either: a
 * run whose records are ALL tombstones has nothing left to vouch for - whether
 * or not some were read before they went - and is an empty scope, never a
 * pass. See `emptyScope` in facts().
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
     * Records the change log listed inside the run's window. CatchUp::K_WINDOW
     * writes it; tests/scan_service_php.php holds the two names equal, because
     * this file must load without CatchUp (the database harness does).
     */
    const WINDOW_KINDS = 'window-changes';

    /**
     * Record that this run knows of rules it cannot evaluate.
     *
     * THE CONSTANT ABOVE HAD NO WRITER. It was declared, it was summed into
     * ScanOutcome::derive()'s `ruleProblems` term (by tally(), today), and
     * nothing in the shipped tree ever produced an aggregate of that
     * kind - every addAggregate() call site wrote a reconciled-*,
     * reconcile-unsettled, fence-unprovable or rollup-* kind instead. So `clean`
     * was really `violations === 0`, and a project whose rules enforce nothing
     * was certified clean. This is the writer.
     *
     * IT IS PUBLIC FOR A REASON. tests/scan_wiring_php.php fails on any public
     * method under php/Scan/ with no production caller, so the day somebody
     * unwires this the suite says so, instead of the term quietly returning to
     * zero the way it has been for the whole of this file's life.
     *
     * ONE ROW PER PROBLEM, AND EACH ROW IS A PRESENCE. This used to be a single
     * addAggregate() row whose count was the number of problems, which made the
     * planner the only caller that could use it: addAggregate ADDS, so a plan
     * that ran twice (a retried request today, resumable planning tomorrow)
     * reported twice the problems, and the per-record problems
     * durableEvaluateRecord() returns could not come here at all - the same
     * broken rule is met by every record, and its count would have measured the
     * size of the project. markAggregate() is the verb that was missing; the
     * worker now sends its problems through marksFor() and commitBatch().
     *
     * Plan-time problems are still noted here, before the first record is read,
     * so they are known even on a run whose scope turns out to be empty.
     *
     * BLOCKS IS 0, and that is correctness rather than a default. A rule problem
     * FORBIDS CLEAN BUT DOES NOT CAP COVERAGE. Passing 1 would put it into
     * blockingAggregates(), which caps coverage at partial - so a project with
     * one misconfigured rule could no longer report that every record was
     * checked, which it was. The one exception is a problem that says a record
     * was only partly examined (PARTLY_EXAMINED, W7-N6); see marksFor().
     *
     * @param array $problems each {rule:int, fields:array, why:string}
     * @return int how many were recorded
     */
    public static function noteRuleProblems(ScanStore $store, $runId, array $problems)
    {
        $marks = self::marksFor($problems);
        foreach ($marks as $m) {
            $store->markAggregate($runId, $m['kind'], $m['axis1'], $m['axis2'],
                                  $m['blocks'], $m['samples']);
        }
        return count($marks);
    }

    /** Longest explanation kept beside a mark. `samples` is TEXT and a sentence is unbounded. */
    const SAMPLE_BYTES = 500;

    /**
     * A sentence cut to SAMPLE_BYTES that is still valid UTF-8.
     *
     * `samples` is a utf8mb4 column, and a strict server refuses a string that
     * ends inside a character (error 1366). A rule-problem sentence quotes the
     * designer's own expressions, so a multi-byte character can straddle the
     * cut. Since B4b the mark is written inside commitBatch(), so one refused
     * mark rolled back the whole batch, every retry carried the same mark, and
     * the batch's records ended UNSTORED with the problem never recorded.
     *
     * Scrubbed first, because the sentence can carry project bytes that were
     * never valid; then cut on a character boundary.
     *
     * @return string|null
     */
    public static function sample($why)
    {
        if (!is_string($why)) return null;
        $why = mb_scrub($why, 'UTF-8');
        return strlen($why) <= self::SAMPLE_BYTES
            ? $why : mb_strcut($why, 0, self::SAMPLE_BYTES, 'UTF-8');
    }

    /**
     * What makes two reports of a rule problem THE SAME problem.
     *
     * The rule, and what is wrong with it. UniversalValidator keys its own list
     * "<rule index>|<code>" and then hands over array_values() of it, so the
     * code does not survive the trip; until it does (`code` on each problem),
     * the sentence stands in for it, hashed because an axis is 255 characters
     * and a sentence is not. Two sentences for one fault would then be two rows,
     * which overstates - the direction that cannot certify.
     *
     * @return array{0:string, 1:string} axis1 (the rule's ordinal), axis2 (the code)
     */
    public static function problemKey(array $problem)
    {
        $rule = isset($problem['rule']) ? (string) (int) $problem['rule'] : '0';
        if (isset($problem['code']) && is_string($problem['code']) && $problem['code'] !== '') {
            return [$rule, substr($problem['code'], 0, 255)];
        }
        $why = (isset($problem['why']) && is_string($problem['why'])) ? $problem['why'] : '';
        return [$rule, 'w:' . substr(sha1($why), 0, 16)];
    }

    /**
     * Rule problems as presence marks, one per DISTINCT problem.
     *
     * The shape commitBatch() takes in `marks` and noteRuleProblems() writes
     * directly - one function, so the planner and the worker cannot come to key
     * the same problem differently and record it twice.
     *
     * BLOCKS IS 0, for the reason on noteRuleProblems(): a rule problem forbids
     * clean and does not cap coverage.
     *
     * EXCEPT WHERE THE PROBLEM IS A RECORD NOT FULLY EXAMINED (W7-N6). When the
     * extended-evaluation budget runs out, UniversalValidator stops judging
     * the record's remaining extended rules and reports `temporal-budget`:
     * "this record was not fully checked". As a rule problem alone it forbade
     * clean and left coverage whole, so a run could say every record was
     * examined over a record it had read only in part. Those codes block: the
     * run's coverage is partial, as it is for a record that could not be read.
     * The budget is per record and the record's data decides it, so trying the
     * record again would stop at the same place; its findings so far are kept.
     *
     * @param array $problems each {rule:int, fields:array, why:string, code?:string}
     * @return array<int, array{kind:string, axis1:string, axis2:string, blocks:int, samples:?string}>
     */
    public static function marksFor(array $problems)
    {
        $out = [];
        foreach ($problems as $p) {
            if (!is_array($p)) continue;
            list($a1, $a2) = self::problemKey($p);
            $k = $a1 . "\0" . $a2;
            if (isset($out[$k])) continue;
            $partly = isset($p['code']) && is_string($p['code'])
                      && in_array($p['code'], self::PARTLY_EXAMINED, true);
            $out[$k] = ['kind' => self::RULE_KINDS, 'axis1' => $a1, 'axis2' => $a2,
                        'blocks' => $partly ? 1 : 0,
                        'samples' => self::sample(isset($p['why']) ? $p['why'] : null)];
        }
        return array_values($out);
    }

    /**
     * Problem codes that mean a record was only partly examined (W7-N6). Their
     * marks block coverage. Each must be a code UniversalValidator writes as
     * the second segment of a problem key, which problemList() hands over.
     *
     * `temporal` AND `temporal-group` ARE NOT HERE, and that was decided, not
     * missed (review rv10b1, design question). They mean an event/instance
     * rule could not be judged at one event or instance of the record - a
     * reference that does not resolve there, a branch selector that cannot be
     * read - and the evaluator says so and goes on to every other rule and
     * context. Coverage says every record in scope was examined: every rule
     * was asked of every context. These codes are an answer to that question
     * ("this rule cannot be judged here"), and the run reports them as rule
     * problems, so it is never clean and the report names the rule, the event
     * and the instance. `temporal-budget` is different in kind: the evaluator
     * stopped asking, and the rules after it were never put to the record.
     * The record's data decides a temporal code, as it decides the budget,
     * but a rerun meets the same code at the same context for as long as the
     * project is designed that way. Blocking it would make such a project
     * partial forever, and partial would no longer mean "the scan did not get
     * to part of it".
     */
    const PARTLY_EXAMINED = ['temporal-budget'];

    /** The stored reason of a run an upgrade of this module ended (review rv10b1-6). */
    const ENGINE_UPGRADED = 'this module was upgraded while this scan was running, to a version that judges '
        . 'some values differently, so the scan was ended rather than finished half under each version; '
        . 'run it again';

    /**
     * What a run's aggregates say was found: active findings, rule problems, gaps.
     *
     * VIOLATIONS ARE ACTIVE FINDINGS, and the rollup is the only count of those.
     * The term used to be the run row's `detail_rows`, which counts what
     * commitBatch() stored and nothing else. UniqueFinalizer writes its
     * duplicate findings straight into the findings table, so a project whose
     * ONLY problem was duplicated values had `detail_rows = 0`, and a run that
     * had found thousands of duplicates was certified clean. It errs the other
     * way too: a finding superseded during catch-up stays in `detail_rows`
     * after the record was corrected.
     *
     * THE LARGEST AXIS, not the first. Every active finding is counted once on
     * each of RollupBuilder::KINDS, so the four sums are equal on a healthy run.
     * If they ever disagree - an axis added later, a page lost on one - the
     * larger number is the one that cannot certify a project it should not.
     *
     * AND WHAT THE DETAIL BUDGET LEFT OUT (H9, W7-N8). Past the budget the
     * worker keeps evaluating and stops storing, and counts each finding it
     * left out in ScanStore::DETAIL_UNSTORED. The summary cannot count a row
     * that was never written, so `violations` is the summary's `stored` plus
     * that `unstored`, and a run with any unstored is the truncated one.
     *
     * @param array $aggregates rows of {kind, cnt, ...} as ScanStore::aggregates() returns them
     * @return array{violations:int, stored:int, unstored:int, ruleProblems:int, gaps:int}
     */
    public static function tally(array $aggregates)
    {
        $byKind = [];
        foreach ($aggregates as $a) {
            if (!isset($a['kind'])) continue;
            $k = (string) $a['kind'];
            $byKind[$k] = (isset($byKind[$k]) ? $byKind[$k] : 0)
                        + (isset($a['cnt']) ? max(0, (int) $a['cnt']) : 0);
        }
        $of = function ($kind) use ($byKind) { return isset($byKind[$kind]) ? $byKind[$kind] : 0; };
        $stored = 0;
        foreach (RollupBuilder::KINDS as $kind) $stored = max($stored, $of($kind));
        $unstored = $of(ScanStore::DETAIL_UNSTORED);
        return ['violations' => $stored + $unstored, 'stored' => $stored, 'unstored' => $unstored,
                'ruleProblems' => $of(self::RULE_KINDS), 'gaps' => $of(self::GAP_KINDS)];
    }

    /** The summed count of one aggregate kind, over rows as ScanStore::aggregates() returns them. */
    private static function kindTotal(array $aggregates, $kind)
    {
        $n = 0;
        foreach ($aggregates as $a) {
            if (isset($a['kind']) && (string) $a['kind'] === $kind) {
                $n += isset($a['cnt']) ? max(0, (int) $a['cnt']) : 0;
            }
        }
        return $n;
    }

    /**
     * How many findings the detail budget left out of readings the run still
     * holds (review rvf1-3).
     *
     * The run row's open_unstored, which the store keeps per record: a record
     * that is deleted or leaves the run's group takes its count back, and one
     * read again has it replaced by the new reading's (review rvc1-2). The
     * DETAIL_UNSTORED aggregate only ever grows, so a departed
     * record's left-out findings stayed in the count and alone made the run
     * truncated. A run that began before schema version 6 has NULL here and
     * is counted from the aggregate, as it always was.
     *
     * @param array $t tally() of the run's aggregates
     */
    private static function unstoredOf(array $run, array $t)
    {
        $kept = self::keptUnstored($run);
        return $kept !== null ? $kept : (int) $t['unstored'];
    }

    /**
     * The run row's own count of what the detail budget left out, or null
     * for a run that began before schema version 6 and keeps none.
     */
    private static function keptUnstored(array $run)
    {
        if (isset($run['open_unstored']) && $run['open_unstored'] !== null) {
            return max(0, (int) $run['open_unstored']);
        }
        return null;
    }

    /**
     * How many findings this run has to show.
     *
     * ONE NUMBER FOR A RUN THAT KEEPS ITS COUNT (reviews rvc2-2, rvc1-2,
     * CONS-N1). A run started at schema version 6 or later answers from its
     * row, in every state: open_findings, the findings stored by the readings
     * it holds and the duplicate-value findings it has published, plus
     * open_unstored, the findings those readings left out. The store keeps
     * open_findings equal to the run's active rows in every transaction that
     * changes them (ScanStore::commitBatch(), requeue(), noteAttempts(),
     * notePublished()), so the findings page and the file, which list the
     * active rows, and the summary, which counts them once every record is
     * read and every group published, give the same number. It used to read
     * the summary once the run had a verdict, and floor a run without one on
     * whatever summary existed: a run stopped after catch-up sent records back
     * to be read, or after some duplicate groups were published, counted
     * fewer findings than its page and file listed, and a finished run's
     * count could change at the finish. The one difference left is a working
     * run in the duplicate check: a group not yet published is in no count,
     * page or file until it is.
     *
     * Zero is an answer for such a run, stopped or not: its rows are what it
     * counts, and it has none.
     *
     * A RUN FROM BEFORE SCHEMA VERSION 6 IS COUNTED THE OLD WAY, its row
     * keeping no count (NULL). Working, from detail_rows and the run-wide
     * detail-unstored aggregate, one read a poll. Finished with a verdict,
     * from its summary, which is what the verdict was decided on. Ended
     * without one (ScanOutcome::NO_VERDICT), from the floor promote() uses -
     * the larger of detail_rows and whatever summary exists - and NULL when
     * that floor is zero: such a run may have written duplicates it never
     * summarised, and a zero that might be false is the one number this must
     * not print.
     *
     * THE FINDINGS PAST THE DETAIL BUDGET ARE IN EVERY ANSWER (H9): they were
     * found and not stored, so no row and no summary holds them, and they are
     * added to what was stored (review rv10b1-3: a working run's count froze
     * at the budget while the worker kept finding). From the run row, with no
     * gate (review rvf2-4), so a status poll of a run that keeps its count
     * reads no aggregates, as the perf case pins.
     *
     * Here rather than in ScanService so it can be asked of a store without a
     * module, a user and an entitlement around it.
     *
     * @return int|null null when the run ended before its findings could be counted
     */
    public static function findingsOf(ScanStore $store, array $run)
    {
        $held = self::storedOf($run);
        if ($held !== null) return $held + self::keptUnstored($run);
        $terminal = isset($run['terminal']) ? $run['terminal'] : null;
        $rows = isset($run['detail_rows']) ? max(0, (int) $run['detail_rows']) : 0;
        if ($terminal === null) {
            return $rows + self::kindTotal($store->aggregates((int) $run['run_id']), ScanStore::DETAIL_UNSTORED);
        }
        $t = self::tally($store->aggregates((int) $run['run_id']));
        $unstored = (int) $t['unstored'];
        if (in_array($terminal, ScanOutcome::NO_VERDICT, true)) {
            $floor = max($rows, $t['stored']) + $unstored;
            return $floor > 0 ? $floor : null;
        }
        return $t['stored'] + $unstored;
    }

    /**
     * The findings a run that keeps its count shows as rows: its row's
     * open_findings, which the store keeps equal to its active findings (see
     * findingsOf()). Null for a run from before schema version 6, whose row
     * keeps neither count; a row with one and not the other is read the same
     * way, as the weaker claim.
     *
     * @return int|null
     */
    public static function storedOf(array $run)
    {
        if (!isset($run['open_findings']) || $run['open_findings'] === null) return null;
        if (self::keptUnstored($run) === null) return null;
        return max(0, (int) $run['open_findings']);
    }

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
 *   (emptyScope is NOT an input. It is derived below from the run row and the
 *    census, because a caller that can be asked for it is a caller that can
 *    forget it, and forgetting it is what this cost the first time.)
     *   labelDegraded:      bool  some report labels could not be read; wording only
     *   uniqueDone:         bool
     *   uniqueBlocking:     int
     *   rollupDone:         bool
     *   fingerprintNow:     ?string
     *   engineNow:          ?string  the evaluator's revision now; with it, a
     *                                fingerprint moved by an upgrade says so
     *   policyRevisionNow:  ?int
     *   maxFindings:        ?int
     *   maxBytes:           ?int
     *   unstored:           ?int  findings the detail budget left out (tally()); with
     *                             it, `truncated` is whether there were any (H9)
     *   crossRecordLookups: bool  a rule's verdict depends on OTHER records (@UVEXISTS)
     *   windowChanges:      int   records the change log listed inside the window
     * }
     * @return array{ready:bool, facts:array, why:?string, notExamined:int}
     */
    public static function facts(array $run, array $states, array $in = [])
    {
        $g = function ($k, $d = null) use ($in) { return isset($in[$k]) ? $in[$k] : $d; };
        $n = function ($state) use ($states) {
            return isset($states[$state]) ? (int) $states[$state] : 0;
        };

        $pending = $n(ScanStore::REC_PENDING) + $n(ScanStore::REC_CLAIMED);
        // EVERY NON-EXAMINING TERMINAL STATE EXCEPT THE TOMBSTONE. A record
        // that could not be read, would not hold still, or whose result could
        // not be stored is a record this run did not check - and a run with one
        // of those in it must never be able to say `clean`. REC_UNSTORED is the
        // newest of the three and the easiest to forget: it is written by the
        // give-back path rather than by a commit, so nothing else in this file
        // would ever have mentioned it.
        //
        // The list is ScanStore::NOT_EXAMINED so that the number promote()
        // persists as `not_examined` and the number that blocks `clean` here are
        // one sum over one list.
        $unread = 0;
        foreach (ScanStore::NOT_EXAMINED as $state) $unread += $n($state);

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
        // WHICH MOVED IT (review rv10b1-6). The evaluator's revision is one of
        // the fingerprint's inputs, so an upgrade to a build that judges
        // differently ends every run in flight - and the stored sentence then
        // said the PROJECT's rules or design had changed, on a project nobody
        // touched. The run keeps the revision it began under, so an upgrade is
        // told apart and named.
        $engNow = $g('engineNow');
        $engineMoved = $fpBad && is_string($engNow) && $engNow !== '' && self::engineOf($run) !== $engNow;

        // NO RECORD IS LEFT DONE: none is `done`, none is still waiting, and
        // nothing is blocked. That is the question `emptyScope` answers, and it
        // is asked of the census alone.
        //
        // NOT "NOTHING WAS EXAMINED", which is what this note and the stored
        // sentence used to say (review R2O-1). CatchUp::step() tombstones a
        // record that was deleted after it was examined, so `done` drops to
        // zero. Its findings are closed with it now (W5F-N4); before that they
        // stayed active, and a run stored then counts them. The
        // classification is right - no record remains for the run to vouch
        // for, so it must never be clean - but the census cannot say whether a
        // tombstone was read first, and ScanOutcome's sentence now claims only
        // what is true either way.
        //
        // It used to be `manifest_total === 0 && every state summed === 0`, and
        // both halves were the wrong question. The sum counted TOMBSTONES, so a
        // run whose every record was deleted before it could be read had a
        // census of N, was not "empty", fell through to the clean row and was
        // stored `complete, complete-through-fence, clean=1` - "every record was
        // examined and nothing was found" over a run that examined none. And
        // `manifest_total` is written once, at freeze, so a manifest emptied
        // after it froze (total 5, no rows) was not empty either: the guard
        // could not fire on the one case it was written for.
        //
        // NOT BLOCKED, ON PURPOSE. A run where every record was unreadable also
        // examined nothing, but `blocked` already says the truer thing about it
        // - the records are there and could not be read - and that is what the
        // reader has to act on. Empty scope is for a run with nothing left to
        // say about any record.
        //
        // ScanPlanner::plan() already refuses to start a run over an empty
        // scope, so in normal operation this fires only when every record was
        // deleted mid-run. It is here because the real cost was never the empty
        // project - it was that ANY bug which empties a manifest became a clean
        // certificate rather than an error.
        $examined = $n(ScanStore::REC_DONE);
        $blocked = ($unread > 0 || (int) $g('blockingAggregates', 0) > 0
                    || (int) $g('uniqueBlocking', 0) > 0);

        $rows  = isset($run['detail_rows']) ? (int) $run['detail_rows'] : 0;
        $bytes = isset($run['detail_bytes']) ? (int) $run['detail_bytes'] : 0;
        $maxR  = $g('maxFindings');
        $maxB  = $g('maxBytes');
        // TRUNCATED MEANS A FINDING WAS LEFT OUT (H9, W7-N8). The worker stops
        // storing once the budget is spent and counts what it leaves out, so a
        // run that counted any is truncated and one that counted none is not -
        // whatever the counters say. Read off the counters, a run that stored
        // exactly its budget and dropped nothing was called truncated, and one
        // that two workers took past it was too, beside a `violations` that
        // matched what was stored. A caller that does not pass the count keeps
        // the counter reading, which can only say truncated more often.
        $unstored = $g('unstored');
        $unstored = ($unstored === null) ? null : max(0, (int) $unstored);
        $truncated = ($unstored !== null) ? ($unstored > 0)
                   : (($maxR !== null && $maxR > 0 && $rows >= (int) $maxR)
                      || ($maxB !== null && $maxB > 0 && $bytes >= (int) $maxB));

        $lookupsMoved = (bool) $g('crossRecordLookups', false) && (int) $g('windowChanges', 0) > 0;

        $facts = [
            // A proved window, and a proved window only. `fence_target` is
            // written by catch-up when the change log covered the whole run;
            // when it could not, the field stays empty and the run keeps
            // `manifest-complete`, which says exactly what it did.
            // A proved fence covers records that changed; it does not cover a
            // @UVEXISTS verdict of an unchanged record whose lookup a change
            // ELSEWHERE moved. With such rules and any change in the window,
            // the run claims the list it read and no more.
            'fenced' => !empty($run['fence_target']) && !$lookupsMoved,
            'lookupsMoved' => $lookupsMoved,
            'manifestDone' => ($pending === 0),
            // Beside manifestDone deliberately: `pending === 0` over a census
            // with nothing examined in it is exactly what made manifestDone
            // true, and the reader needs to see the correction next to the
            // thing it corrects.
            'emptyScope' => ($examined === 0 && $pending === 0 && !$blocked),
            // HOW IT EMPTIED, for the sentence and nothing else (review
            // rvf1-2). A group-scoped run whose every record moved to another
            // group is an empty scope too, and the stored reason said its
            // records had been deleted.
            'departed' => $n(ScanStore::REC_DEPARTED),
            'removed' => $n(ScanStore::REC_TOMBSTONE),
            // Unread and unstable records, undecidable duplicate groups, and
            // anything an aggregate marked blocking. A TOMBSTONE IS ABSENT FROM
            // THIS LIST on purpose - see the class note.
            'blocked' => $blocked,
            'truncated' => $truncated,
            'cancelled' => $cancelled,
            'failed' => ($fpBad || $polBad),
            // Active findings when the caller counted them - see tally(). With no
            // count, or with a summary that never finished (a cancelled or failed
            // run ends whatever is outstanding), the stored-row counter is the
            // floor: a partial summary undercounts, and an undercount is the
            // direction that certifies. The findings the detail budget left out
            // are added to the floor, never compared with it: the counter does
            // not hold them either (H9).
            'violations' => ($g('violations') !== null && $g('rollupDone', false))
                ? max(0, (int) $g('violations'))
                : max($rows, (int) $g('violations', 0) - (int) $unstored) + (int) $unstored,
            'ruleProblems' => (int) $g('ruleProblems', 0),
            'gaps' => (int) $g('gapCount', 0),
            // FROM THE CALLER, because no run row carries it: this read
            // $run['labelDegraded'], a key ScanStore::RUN_COLUMNS does not have
            // and never had, so it was false on every run and the sentence it
            // selects could not be reached. It changes wording only - label
            // degradation never blocks clean.
            'labelDegraded' => !empty($g('labelDegraded', false)),
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

        return ['ready' => $ready, 'facts' => $facts, 'why' => $why,
                'notExamined' => $unread,
                // Which configuration moved, for the stored reason (conclude()).
                'configChanged' => $fpBad ? ($engineMoved ? 'engine' : 'rules') : ($polBad ? 'privacy' : null)];
    }

    /**
     * The evaluator revision a run began under, as its stored policy records
     * it (review rv10b1-6), or null when the policy does not say.
     *
     * NULL MEANS AN OLDER EVALUATOR. ScanPlanner::storedPolicy() has written
     * it since engine-5, so a run without one was begun by a build whose
     * evaluator was engine-4 or older, and differs from any build that reads
     * this.
     */
    public static function engineOf(array $run)
    {
        $raw = isset($run['policy_json']) && is_string($run['policy_json']) ? $run['policy_json'] : null;
        $p = $raw === null ? null : json_decode($raw, true);
        return (is_array($p) && isset($p['engine']) && is_string($p['engine']) && $p['engine'] !== '')
            ? $p['engine'] : null;
    }

    /**
     * Finish the run, or explain why it is not finishable yet.
     *
     * Idempotent through the store: `finish()` only affects a run that still
     * holds its project slot, so a retried finaliser cannot reopen or rewrite a
     * run that a report has already been exported against.
     *
     * WHAT IT READ GOES BACK WITH THE ANSWER (P11). The caller's next step is
     * nearly always status(), which needs the same run row and the same census;
     * reading them twice was a second O(manifest) count on every work request.
     * `run` and `census` are the reads this call made - null when it made none -
     * and `wrote` says whether it sent finish() after reading them. A caller may
     * stand them in for its own reads only when `wrote` is false: a census taken
     * before a write must never describe the run after it.
     *
     * @return array{promoted:bool, outcome:?array, why:?string, run:?array,
     *               census:?array, wrote:bool}
     */
    public static function promote(ScanStore $store, $pid, $runId, array $in = [])
    {
        $run = $store->run($pid, $runId);
        if ($run === null) {
            // Same wording as every other unknown run: a message distinguishing
            // "not here" from "not yours" is an oracle for anyone holding a
            // project link.
            return ['promoted' => false, 'outcome' => null,
                    'why' => 'no scan with that reference is running for this project',
                    'run' => null, 'census' => null, 'wrote' => false];
        }
        if (isset($run['phase']) && $run['phase'] === ScanPhase::TERMINAL) {
            return ['promoted' => false, 'outcome' => null,
                    'why' => 'this scan has already finished',
                    'run' => $run, 'census' => null, 'wrote' => false];
        }

        $states = $store->recordStates($runId);
        $f = self::facts($run, $states, $in);
        if (!$f['ready']) {
            return ['promoted' => false, 'outcome' => null, 'why' => $f['why'],
                    'run' => $run, 'census' => $states, 'wrote' => false];
        }

        $outcome = self::conclude($store, $runId, $run, $states, $in, false);
        $ok = $store->finish($runId, $outcome);
        return ['promoted' => $ok, 'outcome' => $outcome,
                'why' => $ok ? null : 'another worker finished this scan first',
                // A refused finish is still a write somebody made: the run
                // ended under another worker, after these reads.
                'run' => $run, 'census' => $states, 'wrote' => true];
    }

    /**
     * End a run that was asked to stop, with what it found counted (review
     * R3U-3).
     *
     * ONE FINISHER FOR EVERY WRITER OF A STOPPED RUN. Stop on a run nobody
     * drives, the reap a Start does first, and the worker that arrives to find
     * a run `cancelling` each wrote derive(['cancelled' => true, ...]): an
     * outcome with no counts. So the row said `detail = complete` and
     * rule_problem_count = not_examined = gap_count = 0 whatever the run had
     * met, while promote() wrote the SAME stopped run with its tally and its
     * truncation - one run, two rows, depending on which request ended it. The
     * panel lost "1 rule could not be evaluated", and the export was named
     * _CANCELLED where promote() would have named it _CANCELLED_TRUNCATED.
     *
     * IT IS promote()'s conclusion, with the cancellation asserted. The tally,
     * the census and the rows kept against $in's budget are read and judged
     * exactly as promote() judges them; the caller is the one deciding the run
     * ends, as derive(['cancelled' => true]) always let it. A run already
     * terminal is not touched, and finish() itself changes nothing on a run
     * that has left its slot.
     *
     * @param array $in maxFindings and maxBytes, the run's policy - the budget
     *                  `truncated` is decided against, as promote() is asked -
     *                  and `why`, a sentence that replaces the outcome's own
     * @return bool whether this call ended it
     */
    public static function finishCancelled(ScanStore $store, $pid, $runId, array $in = [])
    {
        $run = $store->run($pid, $runId);
        if ($run === null || (isset($run['phase']) && $run['phase'] === ScanPhase::TERMINAL)) {
            return false;
        }
        $budget = [];
        foreach (['maxFindings', 'maxBytes'] as $k) {
            if (isset($in[$k])) $budget[$k] = $in[$k];
        }
        $outcome = self::conclude($store, $runId, $run, $store->recordStates($runId), $budget, true);
        if (isset($in['why']) && is_string($in['why']) && $in['why'] !== '') {
            $outcome['why'] = $in['why'];
        } elseif (isset($run['phase'], $run['terminal_reason']) && $run['phase'] === ScanPhase::CANCELLING
                  && is_string($run['terminal_reason']) && $run['terminal_reason'] !== '') {
            // A STOP THAT SAID WHY KEEPS ITS SENTENCE, whichever request
            // finishes the run (review rv10a2-3). Only a disable writes one on
            // a stopped run (ScanMaintenance::stopPage()); a run it stopped and
            // could not finish in time read as an ordinary cancel when Start
            // or the reap cron finished it later.
            $outcome['why'] = $run['terminal_reason'];
        }
        return $store->finish($runId, $outcome);
    }

    /**
     * What a finishable run may claim, as finish() takes it.
     *
     * THE STORE IS ASKED HERE, NOT BY THE CALLER. What was found used to
     * arrive in $in, so the verdict was as good as whichever caller built the
     * array, and the one production caller fed `violations` from a counter the
     * finalizer never touches. A number a caller supplies for any of these five
     * is overridden: what was found, the rule problems, the gaps, what blocks
     * coverage, and the window's changes.
     *
     * ONLY ONCE THE RUN CAN END, because promotion is attempted on every pass
     * and readiness needs none of it: a run with work left costs no read here.
     * What blocks coverage and the window's changes were the CALLER's reads,
     * sent on every pass although neither decides readiness; asked here they
     * are sent once, by the pass that can end the run. The window is counted
     * from the rows tally() already has.
     *
     * $cancel asserts the cancellation (finishCancelled()); otherwise it is
     * whatever the row says.
     */
    private static function conclude(ScanStore $store, $runId, array $run, array $states, array $in, $cancel)
    {
        $aggregates = $store->aggregates($runId);
        $t = self::tally($aggregates);
        // What the budget left out of readings the run still holds (rvf1-3).
        $unstored = self::unstoredOf($run, $t);
        $f = self::facts($run, $states, array_merge($in, [
            'violations' => $t['stored'] + $unstored, 'unstored' => $unstored,
            'ruleProblems' => $t['ruleProblems'],
            'gapCount' => $t['gaps'], 'blockingAggregates' => $store->blockingAggregates($runId),
            'windowChanges' => self::kindTotal($aggregates, self::WINDOW_KINDS)]));
        if ($cancel) $f['facts']['cancelled'] = true;

        $outcome = ScanOutcome::derive($f['facts']);
        // A RUN THE CONFIGURATION MOVED UNDER SAYS SO (W7-N11). derive()'s
        // sentence for `failed` is the store's - "failed unrecoverably, so it
        // describes nothing" - and a project redesigned under a running scan
        // read as a broken installation.
        if ($outcome['terminal'] === ScanOutcome::FAILED && $f['configChanged'] !== null) {
            $outcome['why'] = $f['configChanged'] === 'rules'
                ? 'the project\'s validation rules or design changed while this scan was running, so its '
                  . 'results would describe two different configurations; run it again'
                : ($f['configChanged'] === 'engine' ? self::ENGINE_UPGRADED
                : 'the project\'s privacy settings were tightened while this scan was running, so it was '
                  . 'ended rather than finished under the settings it started with; run it again');
        }
        $outcome['suffix'] = ScanOutcome::suffix($outcome);
        // What finish() persists beside the terminal. `gaps` is already there.
        $outcome['ruleProblems'] = (int) $f['facts']['ruleProblems'];
        $outcome['notExamined'] = (int) $f['notExamined'];
        return $outcome;
    }
}
