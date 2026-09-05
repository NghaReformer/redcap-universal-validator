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

    // Coverage.
    const FENCED   = 'complete-through-fence';
    const MANIFEST = 'manifest-complete';
    const COV_PARTIAL = 'partial';
    const COV_FAILED  = 'failed';
    /**
     * The scope held no records, so nothing was examined and nothing may be
     * claimed about the project.
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
 *   emptyScope:    bool  the scope held no records at all; nothing was examined
     *   violations:    int
     *   ruleProblems:  int
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
        if ($b('emptyScope')) {
            return self::out(self::PARTIAL, self::EMPTY_SCOPE, $detail, false, $gaps,
                'no records were in scope for this run, so nothing was examined and it '
                . 'says nothing about the project');
        }

        // A record that could not be read, or a degradation that blocks
        // validation, caps coverage at 'partial' whatever else is true. This is
        // checked BEFORE the fence, because a proved fence over a manifest with
        // a hole in it still has a hole in it.
        if ($b('blocked')) {
            return self::out(self::PARTIAL, self::COV_PARTIAL, $detail, false, $gaps,
                'at least one record could not be read or could not be held still, so the project '
                . 'was not covered');
        }

        // Every manifest row terminal, but no proved fence: the run covered the
        // list it opened with and cannot know the project did not move.
        if (!$b('fenced')) {
            return self::out(self::PARTIAL, self::MANIFEST, $detail, false, $gaps,
                'every record on the opening list was examined, but this server cannot prove the '
                . 'project did not change during the run');
        }

        if (!$b('manifestDone')) {
            return self::out(self::PARTIAL, self::COV_PARTIAL, $detail, false, $gaps,
                'the run did not reach every record on its manifest');
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
        // one of them did not. `ruleProblems` reaches here from
        // ScanService::aggregateTotal(..., 'rule-problem'), and nothing in the
        // shipped tree ever wrote an aggregate of that kind: every addAggregate
        // call site wrote a reconciled-*, reconcile-unsettled, fence-unprovable
        // or rollup-* kind instead. So `clean` reduced to `violations === 0`,
        // and a project whose rules enforce nothing was certified clean - the
        // case ScanPageView::verdict names as M-02 and the legacy path already
        // refuses to imply. ScanPromotion::noteRuleProblems() is the producer
        // now; do not remove either term without checking it still has one.
        $clean = ($i('violations') === 0 && $i('ruleProblems') === 0);
        $why = $clean
            ? ($b('labelDegraded')
                ? 'every record was examined and nothing was found; some labels could not be read, '
                  . 'so parts of the report show raw identifiers'
                : 'every record was examined and nothing was found')
            : 'every record was examined, and there is something to act on';
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
