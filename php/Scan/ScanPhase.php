<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * Where a run is, and where it is allowed to go next.
 *
 * WHY A CLASS FOR SEVEN STRINGS. Because the phase is written by five different
 * callers — the planner, the browser worker, the cron worker, both finalizers
 * and the canceller — and every one of them is a candidate for writing a phase
 * that does not follow from the one already stored. The legacy scan had exactly
 * one such bug and it was invisible: `status` was assigned `'complete'` at the
 * end of a loop that a `continue` could skip, so a run that examined nothing
 * reported the same string as a run that examined everything. A transition
 * table cannot prevent a wrong decision, but it can prevent a wrong decision
 * from being STORED, which is the difference between a bug and a bad report.
 *
 * THE CHAIN IS STRICTLY FORWARD, ONE STEP AT A TIME:
 *
 *     planning -> scanning -> catch-up -> unique-finalize -> rollup-finalize
 *
 * No skipping, and that is the whole design. A run with no unique rules still
 * passes THROUGH `unique-finalize` and records that it had nothing to do,
 * because the promotion predicate requires both finalizers to have completed
 * and "completed" must not be satisfiable by never having started. Allowing a
 * skip would make "we had nothing to do" and "we never ran" the same stored
 * fact, and only one of those may certify a project.
 *
 * No backward step either. Catch-up requeues manifest rows and processes them
 * itself under the same stable-read protocol rather than returning the run to
 * `scanning`, so the phase can never oscillate and a stuck run is always stuck
 * in one identifiable place.
 *
 * TWO ESCAPES FROM THE CHAIN. Any active phase may go to `cancelling`, and any
 * phase at all may go to `terminal` — a store failure, an expiry or a fatal has
 * to be recordable from wherever it happened. `cancelling` is not the end: it
 * exists so that the epoch bump and the terminal write are two separate events,
 * which is what lets a worker mid-evaluation discover it has lost the run
 * before anything it buffered can reach the tables.
 *
 * THE NULLABLE TERMINAL. `uv_scan_run.terminal` is NULL for every phase except
 * `terminal`, and non-null exactly there. That is not tidiness: `terminal IS
 * NULL` is how every read path asks "is this run still going", and a run that
 * carried a terminal state while still working would be readable as finished by
 * a query that never looked at the phase.
 *
 * PHP 7.4: class constants and static methods only.
 */
final class ScanPhase
{
    const PLANNING   = 'planning';
    const SCANNING   = 'scanning';
    const CATCH_UP   = 'catch-up';
    const UNIQUE     = 'unique-finalize';
    const ROLLUP     = 'rollup-finalize';
    const CANCELLING = 'cancelling';
    const TERMINAL   = 'terminal';

    /**
     * The forward chain, in order. Position IS the transition rule: legal
     * forward movement is exactly one step down this list.
     */
    private static $chain = [self::PLANNING, self::SCANNING, self::CATCH_UP,
                             self::UNIQUE, self::ROLLUP];

    /**
     * The phases in which a worker may claim and commit.
     *
     * `planning` is absent because the manifest is not frozen yet and a claim
     * against an unfrozen manifest is a claim on a list that can still grow.
     * `cancelling` is absent because the point of the phase is that work has
     * stopped being wanted. `terminal` is absent for the obvious reason, and
     * the reason it must be stated anyway: a resumed browser tab will happily
     * ask a finished run for more work.
     */
    private static $working = [self::SCANNING, self::CATCH_UP, self::UNIQUE, self::ROLLUP];

    /** Every phase, for callers that must handle all of them. */
    public static function all()
    {
        return [self::PLANNING, self::SCANNING, self::CATCH_UP, self::UNIQUE,
                self::ROLLUP, self::CANCELLING, self::TERMINAL];
    }

    /** Is this string a phase at all? Unknown is not a phase; it is a bug. */
    public static function isPhase($p)
    {
        return in_array($p, self::all(), true);
    }

    /** Still going: anything that is not the end. */
    public static function isActive($p)
    {
        return self::isPhase($p) && $p !== self::TERMINAL;
    }

    /**
     * May a worker claim and commit in this phase?
     *
     * An unknown phase answers NO. A run whose stored phase this build does not
     * recognise is a run written by a different build, and the safe reading of
     * "I do not know what state this is in" is not "carry on".
     */
    public static function mayWork($p)
    {
        return in_array($p, self::$working, true);
    }

    /** The next phase in the chain, or null at the end of it. */
    public static function next($p)
    {
        $i = array_search($p, self::$chain, true);
        if ($i === false) return null;
        return isset(self::$chain[$i + 1]) ? self::$chain[$i + 1] : null;
    }

    /**
     * Is moving from $from to $to allowed, and if not, why not.
     *
     * `noop` is separated from `ok` deliberately. A worker that re-enters the
     * phase it is already in has not done anything wrong — a second browser tab
     * resuming the same run is ordinary — but it must not issue the write
     * either, because a phase write bumps `updated_at` and an abandoned run is
     * detected by `updated_at` standing still. A no-op that touched the clock
     * would keep a dead run looking alive.
     *
     * @return array{ok:bool, noop:bool, why:?string}
     */
    public static function check($from, $to)
    {
        if (!self::isPhase($from)) {
            return self::deny('the run\'s stored phase is not one this build recognises');
        }
        if (!self::isPhase($to)) {
            return self::deny('that is not a phase');
        }
        if ($from === $to) {
            return ['ok' => false, 'noop' => true,
                    'why' => 'the run is already in that phase, so nothing is written'];
        }
        if ($from === self::TERMINAL) {
            // The one rule with no exception. A finished run stays finished:
            // reopening one would let a retried finaliser overwrite a terminal
            // state that a report has already been exported against.
            return self::deny('the run has already finished, and a finished run is never reopened');
        }
        if ($to === self::TERMINAL) {
            return self::allow();
        }
        if ($from === self::CANCELLING) {
            return self::deny('a cancelling run may only finish; it never returns to work');
        }
        if ($to === self::CANCELLING) {
            return self::allow();
        }
        if ($to === self::PLANNING) {
            return self::deny('planning happens once, before the manifest is frozen');
        }
        if (self::next($from) === $to) {
            return self::allow();
        }
        // Everything left is either a skip or a step backwards, and the message
        // says which so a caller reading a log can tell a missing finalizer
        // from a retry loop.
        $fi = array_search($from, self::$chain, true);
        $ti = array_search($to, self::$chain, true);
        if ($fi !== false && $ti !== false && $ti > $fi) {
            return self::deny('that skips ' . implode(' and ', array_slice(self::$chain, $fi + 1, $ti - $fi - 1))
                . ', and a phase that never ran has not completed');
        }
        return self::deny('phases do not run backwards');
    }

    /** The bare boolean, for callers that only branch. */
    public static function may($from, $to)
    {
        $c = self::check($from, $to);
        return $c['ok'];
    }

    /**
     * Does the stored pair (phase, terminal) make sense together?
     *
     * Checked on write AND on read. On write because a caller that sets one
     * without the other produces a row no predicate can classify; on read
     * because a row written by an older build, or by a half-applied migration,
     * would otherwise be interpreted by whichever of the two columns the
     * reading code happened to consult.
     *
     * @return array{ok:bool, why:?string}
     */
    public static function consistent($phase, $terminal)
    {
        if (!self::isPhase($phase)) {
            return ['ok' => false, 'why' => 'the stored phase is not one this build recognises'];
        }
        $has = ($terminal !== null && $terminal !== '');
        if ($phase === self::TERMINAL && !$has) {
            return ['ok' => false,
                    'why' => 'the run is in its terminal phase but records no terminal state, so '
                           . 'nothing can say how it ended'];
        }
        if ($phase !== self::TERMINAL && $has) {
            return ['ok' => false,
                    'why' => 'the run records a terminal state while still in ' . $phase
                           . ', so a reader checking only the terminal column would call it finished'];
        }
        if ($has && !in_array($terminal, [ScanOutcome::COMPLETE, ScanOutcome::PARTIAL,
                ScanOutcome::CANCELLED, ScanOutcome::FAILED, ScanOutcome::EXPIRED], true)) {
            return ['ok' => false, 'why' => 'that is not a terminal state'];
        }
        return ['ok' => true, 'why' => null];
    }

    /**
     * The phase a cancellation should write, given where the run is now.
     *
     * A run that has not started working has nothing in flight, so it goes
     * straight to `terminal`; anything else goes to `cancelling` first so the
     * epoch bump lands before the terminal write and an evaluating worker
     * fails its compare-and-set rather than committing into a finished run.
     * Returning null means the request is refused, and the caller must say so
     * rather than reporting a cancellation that did not happen.
     */
    public static function cancelTarget($from)
    {
        if (!self::isPhase($from)) return null;
        if ($from === self::TERMINAL || $from === self::CANCELLING) return null;
        if ($from === self::PLANNING) return self::TERMINAL;
        return self::CANCELLING;
    }

    private static function allow()
    {
        return ['ok' => true, 'noop' => false, 'why' => null];
    }

    private static function deny($why)
    {
        return ['ok' => false, 'noop' => false, 'why' => $why];
    }
}
