<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * What a finished run is allowed to claim — one function, used by the worker,
 * the UI and every exporter.
 *
 * THE POINT IS THAT THERE IS ONE. The legacy path derived "complete", "clean"
 * and the filename suffix in three different places, and they disagreed: the
 * screen withheld a tick that the download's filename did not, and a rule
 * problem changed one and not the other. Every such disagreement is a chance for
 * the most reassuring of the three to be the one someone files.
 *
 * FOUR INDEPENDENT DIMENSIONS, deliberately not collapsed:
 *
 *   terminal  did the run finish, and how          complete|partial|cancelled|failed|expired
 *   coverage  what finishing is worth here         complete-through-fence|manifest-complete|empty-scope|partial|failed
 *   detail    did every finding survive the budget complete|truncated
 *   clean     a claim about the PROJECT            bool
 *
 * A run can be `complete` and not clean; it can have zero violations and not be
 * clean; it can be fenced and truncated. Collapsing any pair of these produces a
 * sentence that is true of the run and false of the project, which is the entire
 * class of defect the rebuild plan exists to prevent.
 *
 * COLLECTION GAPS ARE NOT VIOLATIONS AND DO NOT BLOCK CLEAN. They also may never
 * be silent: `mustShowGaps` is true whenever any exist, so a caller that renders
 * "No issues found" without them is failing a contract it can see.
 */
final class ScanOutcome
{
    // Terminal states.
    const COMPLETE  = 'complete';
    const PARTIAL   = 'partial';
    const CANCELLED = 'cancelled';
    const FAILED    = 'failed';
    const EXPIRED   = 'expired';
    /** Terminals that ended before "was it clean" could be asked at all. */
    const NO_VERDICT = [self::FAILED, self::CANCELLED, self::EXPIRED];

    // Coverage.
    const FENCED   = 'complete-through-fence';
    const MANIFEST = 'manifest-complete';
    const COV_PARTIAL = 'partial';
    const COV_FAILED  = 'failed';
    /**
     * No record is left to report on, so nothing may be claimed about the
     * project: the scope held no records, or every record in it was deleted
     * while the run went on - before it was read, or after (review R2O-1). The
     * three are one value because they support the same claim - none - and
     * ScanPromotion::facts() cannot tell them apart without a manifest count
     * it has learned not to trust.
     *
     * ITS OWN VALUE RATHER THAN `failed` OR `manifest-complete`, and both of
     * those were tried on paper first. `failed` is what the two neighbouring
     * refusals in ScanPlanner use, but it is not true: an empty project is a
     * fact about the PROJECT and a failure is a fact about the RUN, and
     * `_FAILED` is the one piece of metadata that survives a year of being
     * forwarded. `manifest-complete` is worse - it claims every record on the
     * opening list was checked, which is a claim about a manifest that does not
     * exist.
     *
     * coverage is VARCHAR(32) (Schema.php:168), so this needs no migration.
     */
    const EMPTY_SCOPE = 'empty-scope';

    // Detail.
    const DETAIL_COMPLETE  = 'complete';
    const DETAIL_TRUNCATED = 'truncated';

    /**
     * Derive the outcome from facts about the run.
     *
     * Every input defaults to the SAFE reading, so a caller that forgets one
     * gets the weaker claim rather than the stronger. That direction matters:
     * the cost of a missed field is an unnecessary "_INCOMPLETE" suffix, and the
     * cost of the opposite is certifying a project nobody scanned.
     *
     * @param array $f {
     *   fenced:        bool  the target fence was proved
     *   manifestDone:  bool  every frozen manifest row reached a terminal state
     *   blocked:       bool  any unread/unstable record, or validation-blocking degradation
     *   truncated:     bool  the detail budget was exceeded
     *   cancelled:     bool  cancellation was requested and honoured
     *   failed:        bool  unrecoverable store/schema/fingerprint failure
     *   expired:       bool  abandoned beyond the configured lifetime
     *   emptyScope:    bool  no record is left: none in scope, or all deleted, checked or not
     *   departed:      int   records that moved to another Data Access Group; wording only
     *   removed:       int   records deleted while it ran; wording only
     *   lookupsMoved:  bool  @UVEXISTS verdicts may have moved with a change in the window
     *   violations:    int   ABSENT IS UNKNOWN, and unknown is never clean
     *   ruleProblems:  int   ABSENT IS UNKNOWN, and unknown is never clean
     *   gaps:          int   collection gaps; never a violation, never blocking
     *   labelDegraded: bool  reporting-label degradation only; non-blocking
     * }
     * @return array{terminal:string, coverage:string, detail:string, clean:bool,
     *               mustShowGaps:bool, suffix:string, why:string}
     */
    public static function derive(array $f)
    {
        $b = function ($k) use ($f) { return !empty($f[$k]); };
        $i = function ($k) use ($f) { return isset($f[$k]) ? (int) $f[$k] : 0; };
        // A COUNT THAT WAS NOT SUPPLIED IS NOT ZERO (review OC-7). `violations`
        // and `ruleProblems` were read through $i(), so a caller that omitted
        // either got 0 - the CLEAN direction - against the rule this docblock
        // states. Absent, null, negative or not a number is UNKNOWN, and an
        // unknown count refuses clean. Gaps keep $i(): they never decide it.
        $count = function ($k) use ($f) {
            if (!array_key_exists($k, $f)) return null;
            $v = $f[$k];
            if (is_int($v)) return $v >= 0 ? $v : null;
            if (is_string($v) && ctype_digit($v)) return (int) $v;
            return null;
        };

        $detail = $b('truncated') ? self::DETAIL_TRUNCATED : self::DETAIL_COMPLETE;
        $gaps   = $i('gaps');

        // ORDER IS THE SPECIFICATION. The first three rows are terminal
        // conditions that override everything else, and they are ranked by how
        // little the run can be trusted: a failed store means the run's own
        // records may be wrong, so it outranks a cancellation, which outranks
        // an expiry.
        if ($b('failed')) {
            return self::out(self::FAILED, self::COV_FAILED, $detail, false, $gaps,
                'the run failed unrecoverably, so it describes nothing');
        }
        if ($b('cancelled')) {
            return self::out(self::CANCELLED, self::COV_PARTIAL, $detail, false, $gaps,
                'the run was cancelled, so the records after the cancellation point were never examined');
        }
        if ($b('expired')) {
            return self::out(self::EXPIRED, self::COV_PARTIAL, $detail, false, $gaps,
                'the run was abandoned beyond its configured lifetime');
        }

        // NOTHING WAS IN SCOPE, so there is nothing to be blocked on, no
        // manifest to have completed, and no fence worth proving. This check did
        // not exist, so a census of zero satisfied `pending === 0`, was read as
        // manifestDone, and fell all the way through to the clean row: a run
        // that examined nothing produced the same certificate as a run that
        // examined everything. That is the founding defect of this rebuild
        // reappearing at the other end of the pipeline.
        //
        // RANKED HERE ON PURPOSE. Below the three run-level terminals, because a
        // cancelled run over an empty scope is still a cancelled run and that is
        // the weaker claim; above `blocked`, because an empty scope cannot be
        // partially covered.
        //
        // TERMINAL IS `partial`, NOT a fifth terminal state. The run did finish;
        // what finishing is WORTH is a coverage question, and keeping those two
        // axes apart is what this file is for. `complete` is unavailable anyway,
        // since mayClaimClean() keys on it.
        //
        // The default is FALSE, which looks like the wrong direction for a file
        // whose rule is that a forgotten field yields the weaker claim. It is
        // not: a false emptyScope falls through to weaker rows, while a true one
        // ASSERTS that the scope was empty. Asserting is never the safe default.
        // THE SENTENCE COVERS EVERY WAY IN, and it has been wrong twice. It
        // said "no records were in scope", which is false of a run whose every
        // record was deleted while it ran. It then said "no record was
        // examined", which is false of the commonest of those runs (review
        // R2O-1): catch-up tombstones a record it had already examined, its
        // findings stay active, and the run was stored as having examined
        // nothing beside a count of what that examination found. What is true
        // of all of them is that no record is LEFT: none was listed, or every
        // one listed was deleted, checked or not - and whatever it found is
        // about records the project no longer has.
        if ($b('emptyScope')) {
            // EVERY RECORD LEFT THE GROUP (review rvf1-2). A group-scoped run
            // whose records all moved to another group was stored as having
            // had them deleted; none was.
            if ($i('departed') > 0) {
                return self::out(self::PARTIAL, self::EMPTY_SCOPE, $detail, false, $gaps,
                    'no record is left for it to report on - every record it listed moved to another '
                    . 'Data Access Group' . ($i('removed') > 0 ? ' or was deleted' : '') . ' while it ran'
                    . ' - so it says nothing about the group');
            }
            return self::out(self::PARTIAL, self::EMPTY_SCOPE, $detail, false, $gaps,
                'no record is left for it to report on - none was in scope, or every one was '
                . 'deleted while it ran, whether or not it had been examined, and any findings it '
                . 'kept are on deleted records - so it says nothing about the project');
        }

        // A record that could not be read, or a degradation that blocks
        // validation, caps coverage at 'partial' whatever else is true. This is
        // checked BEFORE the fence, because a proved fence over a manifest with
        // a hole in it still has a hole in it. A record examined only in part
        // (W7-N6: its extended-evaluation budget ran out) is one of those holes,
        // and the sentence names it.
        if ($b('blocked')) {
            return self::out(self::PARTIAL, self::COV_PARTIAL, $detail, false, $gaps,
                'at least one record could not be read, could not be held still or was examined '
                . 'only in part, so the project was not covered');
        }

        // THE MANIFEST BEFORE THE FENCE. These two were the other way round, and
        // the cell where BOTH are false answered from the fence row: a run that
        // had neither finished its list nor proved a window was stored as
        // `manifest-complete`, with the sentence "every record on the opening
        // list was examined". `manifest-complete` is a claim about the manifest,
        // so whether the manifest was finished is asked first; the fence can
        // only weaken a claim the manifest has already earned.
        if (!$b('manifestDone')) {
            return self::out(self::PARTIAL, self::COV_PARTIAL, $detail, false, $gaps,
                'the run did not reach every record on its manifest');
        }

        // Every manifest row terminal, but no proved fence: the run covered the
        // list it opened with and cannot know the project did not move.
        if (!$b('fenced')) {
            return self::out(self::PARTIAL, self::MANIFEST, $detail, false, $gaps,
                $b('lookupsMoved')
                    ? 'every record on the opening list was examined, but records changed during the run '
                      . 'and @UVEXISTS answers depend on other records, so they were not re-checked'
                    : 'every record on the opening list was examined, but this server cannot prove the '
                      . 'project did not change during the run');
        }

        // Fenced and whole. Detail truncation does not reduce COVERAGE - every
        // record really was examined - but it does forbid clean, because the
        // report the reader holds is not the report the run produced.
        if ($detail === self::DETAIL_TRUNCATED) {
            return self::out(self::PARTIAL, self::FENCED, $detail, false, $gaps,
                'every record was examined, but the detail budget was exceeded and some findings '
                . 'were not retained');
        }

        // The only row that can be clean. Label degradation is explicitly
        // NON-blocking: an event shown by id rather than by name is a worse
        // report, not a worse scan, and refusing to certify over it would make
        // the tick unreachable on any installation with a metadata gap.
        // BOTH TERMS MUST HAVE A PRODUCER, and for the whole of this file's life
        // one of them did not. `ruleProblems` reaches here as the sum of the
        // 'rule-problem' aggregates (ScanPromotion::tally()), and nothing in the
        // shipped tree ever wrote an aggregate of that kind: every addAggregate
        // call site wrote a reconciled-*, reconcile-unsettled, fence-unprovable
        // or rollup-* kind instead. So `clean` reduced to `violations === 0`,
        // and a project whose rules enforce nothing was certified clean - the
        // case the audit names M-02 and the legacy path already
        // refuses to imply. ScanPromotion::noteRuleProblems() is the producer
        // now; do not remove either term without checking it still has one.
        $violations = $count('violations');
        $ruleProblems = $count('ruleProblems');
        $counted = ($violations !== null && $ruleProblems !== null);
        $clean = ($counted && $violations === 0 && $ruleProblems === 0);
        if ($clean) {
            $why = $b('labelDegraded')
                ? 'every record was examined and nothing was found; some labels could not be read, '
                  . 'so parts of the report show raw identifiers'
                : 'every record was examined and nothing was found';
        } elseif (!$counted) {
            // Not "there is something to act on": nobody knows that either.
            $why = 'every record was examined, but what was found was not counted, so the project '
                 . 'cannot be called clean';
        } else {
            $why = 'every record was examined, and there is something to act on';
        }
        return self::out(self::COMPLETE, self::FENCED, $detail, $clean, $gaps, $why);
    }

    /**
     * The filename suffix for an export of this outcome.
     *
     * A filename is the one piece of metadata that survives being renamed,
     * forwarded and opened a year later, so it carries the weakest claim rather
     * than the strongest. Suffixes COMPOSE: a manifest-only run whose detail was
     * also truncated says both, because a reader who only knows one of them will
     * draw the wrong conclusion from the other.
     */
    public static function suffix(array $outcome)
    {
        $s = '';
        switch ($outcome['terminal']) {
            case self::FAILED:    $s = '_FAILED'; break;
            case self::CANCELLED: $s = '_CANCELLED'; break;
            case self::EXPIRED:   $s = '_EXPIRED'; break;
            case self::COMPLETE:  $s = ''; break;
            default:
                // partial: say WHICH kind of partial.
                if ($outcome['coverage'] === self::MANIFEST) $s = '_MANIFEST_ONLY';
                // Correctness, not tidiness: with no arm here the default leaves
                // $s empty, so an empty-scope export would carry the same
                // unmarked filename a fully covered run gets.
                elseif ($outcome['coverage'] === self::EMPTY_SCOPE) $s = '_NO_RECORDS';
                elseif ($outcome['coverage'] === self::COV_PARTIAL) $s = '_INCOMPLETE';
                break;
        }
        if ($outcome['detail'] === self::DETAIL_TRUNCATED) $s .= '_TRUNCATED';
        return $s;
    }

    /**
     * May this outcome's export use the word "clean" anywhere?
     *
     * Its own function because the plan states the rule as a prohibition on the
     * ARTEFACT, not on the verdict: an export whose status is not complete may
     * not contain the word. Callers ask rather than re-deriving.
     */
    public static function mayClaimClean(array $outcome)
    {
        return $outcome['terminal'] === self::COMPLETE && $outcome['clean'] === true;
    }

    /**
     * What scan_run.clean holds for this outcome: 1, 0, or NULL.
     *
     * THREE values, because "was it clean" has three answers:
     *
     *   1     CERTIFIED. Complete, fenced, every count zero - mayClaimClean().
     *   0     DECIDED, AND NOT CERTIFIED. The run reached the question and the
     *         answer was no, for ANY reason: something was found, a rule could
     *         not be evaluated, a record could not be read, the detail budget
     *         ran out, the window was not proved, or nothing was examined. It
     *         does NOT mean "checked, and found something", and this note used
     *         to say it did (review EE-4) while partial and empty-scope runs
     *         were written 0 beside it. A reader wanting "found something"
     *         asks the counts; the counts mean something only where this
     *         column is not NULL (W7-N10).
     *   NULL  NO DECISION. A run that failed, was cancelled or expired never
     *         reached the question. NULL is also what Schema::upgradeDataV2 and
     *         the reaper write for those runs, and what every run finished
     *         before this column was written carries.
     *
     * Partial runs stay 0 rather than NULL on purpose: they did reach the
     * question, and their counts are the explanation a reader is owed.
     *
     * @return int|null
     */
    public static function cleanColumn(array $outcome)
    {
        if (in_array($outcome['terminal'], self::NO_VERDICT, true)) return null;
        return self::mayClaimClean($outcome) ? 1 : 0;
    }

    private static function out($terminal, $coverage, $detail, $clean, $gaps, $why)
    {
        return [
            'terminal' => $terminal,
            'coverage' => $coverage,
            'detail'   => $detail,
            'clean'    => (bool) $clean,
            // Gaps never block clean and never become violations - but a caller
            // rendering "No issues found" beside unmentioned gaps is stating
            // something false, so the obligation travels with the outcome.
            'mustShowGaps' => $gaps > 0,
            'gaps'     => (int) $gaps,
            'why'      => $why,
            'suffix'   => '',   // filled by suffix(); kept present so the shape is stable
        ];
    }
}
