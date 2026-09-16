<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * Who may start, work, read, export or cancel a scan.
 *
 * ONE CLASS, BECAUSE A SECOND COPY AGES DIFFERENTLY. The legacy path decided
 * rights in two places and they had already drifted: the page checked design
 * rights and a DAG, the exporter checked design rights, a DAG and an export
 * level, and neither checked instrument access at all — so a designer with No
 * Access to an instrument received that instrument's findings and, on a project
 * that had opted into raw values, its values.
 *
 * WHOLE-REPORT DENIAL, NOT PARTIAL FILTERING. The plan is explicit and the
 * reason is not squeamishness: filtering rows leaks through the count, the
 * rollup, the filter options, the cursor, the timing, the filename and the value
 * preview. There is no version of "show them the rows they may see" that does
 * not also tell them how many they may not. So a reader who cannot read every
 * instrument in the run's entitlement set is refused the run.
 *
 * EVERY DENIAL IS NON-DISCLOSING. A refusal never distinguishes "no such run"
 * from "not yours", and a busy project never names the run that owns it, its
 * scope, its owner or its progress. The run id is a LOCATOR, never an
 * authorisation: it is bound to the project before any distinction is returned.
 *
 * FAILS CLOSED AT EVERY STEP. An unreadable rights shape is a denial, an absent
 * instrument entry is a denial, and an unrecognised export level is a denial.
 * The safe direction is the restrictive one, and it is the only direction that
 * stays safe when a framework changes shape underneath.
 */
final class ScanAuthorization
{
    /** REDCap's data_export_tool: only 1 is Full Data Set. */
    const EXPORT_FULL = '1';

    /**
     * May this user START a run over $entitlement?
     *
     * The plan's row: design rights, nonzero read access to EVERY form in the
     * snapshotted entitlement set, full identified-data export rights, and a
     * resolved DAG or unrestricted scope.
     *
     * $entitlement is every form the run will read: rule hosts plus the forms
     * owning condition, assertion, unique-composite and enrichment fields.
     * Unknown ownership is a denial rather than an omission, because a field
     * whose form cannot be determined is a field whose access cannot be checked.
     *
     * $unknownOwnership is a bool OR the list of fields that could not be
     * placed. A list is truthy exactly when the bool would be true, so every
     * existing caller keeps its meaning - and the ones that pass names get a
     * refusal that says which fields, which is the difference between a
     * diagnosis and a dead end.
     *
     * @param bool|string[] $unknownOwnership
     * @return array{ok:bool, why:?string, scope:?string, unrestricted:bool}
     */
    public static function mayStart($rights, array $entitlement, $unknownOwnership = false)
    {
        $base = self::readable($rights);
        if (!$base['ok']) return $base;

        // Full identified-data export rights, not merely "some export rights".
        // A de-identified reader may not start a run: the run STORES values, and
        // what is stored outlives the level the reader had when they asked.
        if (!self::hasFullExport($rights)) {
            // SAY WHAT WAS READ, not merely that it was not enough.
            //
            // The first live pilot was refused here by an account REDCap's own
            // User Rights page showed as Full Data Set, and the message gave
            // nobody a way to tell a wrong right from a wrong READING of it. A
            // user being told about their own export level discloses nothing
            // they cannot already see on that page, and it is the difference
            // between "ask your administrator" and a diagnosis.
            return self::no('this scan stores record values, so it needs Full Data Set export '
                . 'rights; ' . self::exportLevelPhrase($rights));
        }
        if ($unknownOwnership) {
            // NAMED, for the same reason the export-level refusal above says
            // which level it read. This arm was unreachable in production until
            // the entitlement moved onto the read set, and it names a fixable
            // dictionary problem: the field exists, its form_name does not.
            // Without the names it is a refusal nobody can act on - the exact
            // objection that put the instrument names in the branch below.
            $who = is_array($unknownOwnership) ? self::plainList($unknownOwnership) : '';
            return self::no('at least one field a rule depends on could not be located on an '
                . 'instrument, so access to it cannot be checked and the scan was not started'
                . ($who === '' ? '' : ': ' . $who));
        }
        $barred = self::barredForms($rights, $entitlement);
        if ($barred) {
            // TWO DIFFERENT FAILURES, TWO DIFFERENT SENTENCES.
            //
            // barredForms() returns the WHOLE entitlement when the rights row
            // itself could not be read, which is not a statement about any
            // instrument. Now that the entitlement is the read set, printing
            // that list would enumerate every instrument the run touches for a
            // failure that has nothing to do with instruments - misleading, and
            // longer the more the project does.
            //
            // Where the row WAS readable, every barred name is printed and none
            // is truncated. A cap would make the survivors a lexicographic
            // accident: sorting and then keeping twelve hides form3 behind
            // form19, and a refusal listing twelve arbitrary names is no more
            // actionable than a count. Naming them discloses nothing - the
            // caller has already been established as a project designer, who
            // sees every instrument in the Online Designer.
            if (!self::rightsListedForms($rights)) {
                return self::no('your instrument access for this project could not be read, so '
                    . 'the scan was refused rather than run against instruments it may not be '
                    . 'entitled to');
            }
            return self::no('this scan reads instrument(s) you do not have access to, so the whole '
                . 'report is refused rather than quietly narrowed: ' . self::plainList($barred));
        }
        return $base;
    }

    /**
     * May this user READ a stored run — status, findings or export?
     *
     * The same entitlement as start, RE-EVALUATED NOW. Rights revoked during a
     * run stop further reads; the run id does not restore them. And the scope
     * must still match: a DAG user may read only a run whose immutable scope is
     * exactly their current DAG, because a run scoped to another group is not
     * made readable by having a locator for it.
     */
    public static function mayRead($rights, array $entitlement, $runScopeDag, $unknownOwnership = false)
    {
        $start = self::mayStart($rights, $entitlement, $unknownOwnership);
        if (!$start['ok']) return $start;
        return self::scopeMatches($start, $runScopeDag);
    }

    /**
     * May this user WORK (advance) the run?
     *
     * Identical entitlement to reading, plus the same exact-scope rule. A cron
     * worker is not a user and never comes through here: it carries the run's
     * own immutable authorised scope and cannot widen it.
     */
    public static function mayWork($rights, array $entitlement, $runScopeDag, $unknownOwnership = false)
    {
        return self::mayRead($rights, $entitlement, $runScopeDag, $unknownOwnership);
    }

    /**
     * May this user CANCEL the run?
     *
     * Wider than working on purpose: a run wedged by someone who has gone home
     * must be stoppable by anyone equally entitled in the same scope. A GLOBAL
     * run, though, may only be cancelled by an unrestricted user - a DAG user
     * cancelling a project-wide run affects every other group.
     *
     * CREATOR IDENTITY IS DELIBERATELY NOT CONSULTED, and this sentence is here
     * so it is not re-added. Ownership would only add a way for a wedged run to
     * become unstoppable: the person who started it is precisely the person who
     * may have gone home. This function used to TAKE a creator and a username
     * and compare them, and both arms of that comparison returned the same
     * expression - so the control read as live, was tested as live, and decided
     * nothing. Two inert parameters that every call site fills in earnest are a
     * better disguise than no parameters at all.
     */
    public static function mayCancel($rights, array $entitlement, $runScopeDag)
    {
        $base = self::mayStart($rights, $entitlement);
        if (!$base['ok']) return $base;
        $unrestricted = $base['unrestricted'];

        if ($runScopeDag === null) {
            if (!$unrestricted) {
                return self::no('this run covers the whole project, so only a user without a Data '
                    . 'Access Group restriction may cancel it');
            }
            return $base;
        }
        if ($unrestricted) return $base;                       // may cancel any DAG run
        return self::scopeMatches($base, $runScopeDag);        // same group, whoever started it
    }

    // busy() USED TO LIVE HERE, and it was never called.
    //
    // It existed so the "already running" sentence would be identical whoever
    // asked - and both stores wrote their own copy of it anyway, which had
    // already drifted from this one by a sentence. Its only two mentions in the
    // shipped tree were inside comments, so the wiring test could not see that
    // nothing invoked it until the call-site corpus stopped counting prose.
    //
    // The sentence now belongs to ScanStore::BUSY_WHY, which is the contract
    // that produces the refusal, and both stores answer with it. A wrapper that
    // only reshapes a constant is a third place for the wording to drift.


    /**
     * What `scan-status` may disclose about a DAG-scoped run that has NOT yet
     * reached its target fence.
     *
     * Before the fence, a DAG projection is not yet provable, so counts,
     * percentages, rollups, filters and samples would be claims about a scope
     * that has not been established. Phase, heartbeat and the control flags are
     * all that survives - enough to render a progress spinner and a cancel
     * button, and nothing that describes the data.
     */
    public static function preFenceStatus(array $status)
    {
        $keep = ['run_owned', 'phase', 'heartbeat_at', 'last_progress_at',
                 'may_resume', 'may_cancel', 'error_category'];
        $out = [];
        foreach ($keep as $k) if (array_key_exists($k, $status)) $out[$k] = $status[$k];
        // Stated positively so a caller cannot mistake the absence of counts for
        // "the scan found nothing".
        $out['detail_withheld'] = true;
        $out['detail_withheld_why'] = 'this run is scoped to a Data Access Group and has not yet '
            . 'reached a point where that scope can be proved, so no counts are shown yet';
        return $out;
    }

    /**
     * A DAG-scoped run may only START where group membership CHANGES can be
     * proved. Without that, a projection built at the fence cannot be shown to
     * still describe the group, and the honest answer is to refuse up front
     * rather than produce a report that silently ages.
     */
    public static function mayStartDagScoped($fenceProvesDagChanges)
    {
        if ($fenceProvesDagChanges) return ['ok' => true, 'why' => null];
        return ['ok' => false, 'why' => 'this server cannot prove when records move between Data '
            . 'Access Groups, so a group-scoped scan cannot be shown to still describe your group. '
            . 'Ask an administrator to run a project-wide scan.'];
    }

    /**
     * A stored DAG projection is only readable while no membership drift exists
     * after the target fence. Drift invalidates the WHOLE response - it never
     * silently changes a count, and it never pages until enough authorised rows
     * happen to appear.
     */
    public static function projectionStillValid($movedIn, $movedOut)
    {
        if ((int) $movedIn === 0 && (int) $movedOut === 0) return ['ok' => true, 'why' => null];
        return ['ok' => false, 'why' => 'records have moved into or out of this Data Access Group '
            . 'since the scan, so its results no longer describe your group. Run a new scan.'];
    }

    // -- internals ----------------------------------------------------------

    /** Design rights, a readable rights shape, and a resolvable scope. */
    private static function readable($rights)
    {
        if (!is_array($rights)) {
            return self::no('your rights could not be read, so the scan was not run');
        }
        if (empty($rights['design'])) {
            return self::no('you need project design rights to use the validation scan');
        }
        // THE SCOPE IS THE GROUP ID AND MUST STAY THE GROUP ID.
        //
        // $rights['group_id'] is REDCap's numeric group id, and scopeMatches()
        // compares it against uv_scan_run.scope_dag. Anything that resolves it
        // to the friendly name here - or stores the friendly name there -
        // breaks both sides of that comparison at once, and the failure is not
        // a leak: it fails CLOSED, refusing the designer who started the run
        // access to scan-work, scan-status and scan-cancel on their own run,
        // which then holds the project's only slot with nothing to reap it.
        // That is the shape the page shipped with, and no amount of correctness
        // in this file could have compensated for it.
        $dag = (isset($rights['group_id']) && $rights['group_id'] !== '' && $rights['group_id'] !== null)
             ? (string) $rights['group_id'] : null;
        return ['ok' => true, 'why' => null, 'scope' => $dag, 'unrestricted' => ($dag === null)];
    }

    private static function hasFullExport($rights)
    {
        if (!is_array($rights)) return false;
        $lvl = self::exportLevel($rights);
        return $lvl !== null && (string) $lvl === self::EXPORT_FULL;
    }

    /**
     * The reader's export level.
     *
     * Delegated to ScanPageView, which owns the one reader the value ceiling and
     * the download gate also use. Three private copies of the same array lookup
     * is three chances for one of them to be reading the wrong key, which is the
     * defect this method exists because of.
     */
    private static function exportLevel($rights)
    {
        return \INSPIRE\UniversalValidator\ScanPageView::exportLevel($rights);
    }

    /** How to describe the level that was actually read, in the refusal. */
    private static function exportLevelPhrase($rights)
    {
        if (!is_array($rights)) return 'your rights could not be read';
        $lvl = self::exportLevel($rights);
        if ($lvl === null) {
            return 'no export level was present in your rights for this project (this build '
                 . 'supplied neither data_export_tool nor data_export), so the scan was refused '
                 . 'rather than assumed';
        }
        $names = ['0' => 'No Access', '1' => 'Full Data Set', '2' => 'De-Identified',
                  '3' => 'Remove Identifier Fields'];
        $s = (string) $lvl;
        return 'your export level for this project reads as '
             . (isset($names[$s]) ? $names[$s] : ('an unrecognised value (' . $s . ')'));
    }

    /**
     * Forms in $entitlement this user may not read.
     *
     * REDCap's levels: 0 no access; 1 view/edit, 2 read-only and 3 edit survey
     * responses all imply the form can be read. A form with NO entry is barred:
     * a rights row that says nothing about an instrument is not a rights row
     * that grants it.
     */
    /**
     * Did the rights row actually LIST instruments?
     *
     * The half of barredForms()'s answer its return value cannot carry: a full
     * entitlement comes back both when every instrument is genuinely barred and
     * when the row could not be read at all, and those need different sentences.
     * A super-user has no row and is barred from nothing, so it answers true.
     */
    private static function rightsListedForms($rights)
    {
        if (is_array($rights) && !empty($rights['superUser'])) return true;
        return is_array($rights) && isset($rights['forms']) && is_array($rights['forms']);
    }

    /** Names in a sentence: "a", "a and b", "a, b and c". Never truncated. */
    private static function plainList(array $names)
    {
        $n = [];
        foreach ($names as $x) {
            $x = (string) $x;
            if ($x !== '') $n[$x] = true;
        }
        $n = array_keys($n);
        sort($n, SORT_STRING);
        if (!$n) return '';
        if (count($n) === 1) return $n[0];
        $last = array_pop($n);
        return implode(', ', $n) . ' and ' . $last;
    }

    private static function barredForms($rights, array $entitlement)
    {
        // An administrator reads every instrument, and has no per-instrument
        // rights row to say so. Without this the same absence that hid their
        // export level bars every form they were about to scan - one defect
        // presenting as two, which is why both are fixed together.
        if (is_array($rights) && !empty($rights['superUser'])) return [];
        $forms = (is_array($rights) && isset($rights['forms']) && is_array($rights['forms']))
               ? $rights['forms'] : null;
        if ($forms === null) return $entitlement;      // unreadable clears nothing
        $bad = [];
        foreach ($entitlement as $f) {
            $f = (string) $f;
            if (!array_key_exists($f, $forms) || (string) $forms[$f] === '0') $bad[] = $f;
        }
        return $bad;
    }

    /**
     * The SCOPE half of the entitlement, on its own, for a page deciding what
     * to OFFER.
     *
     * NECESSARY, NEVER SUFFICIENT, and this sentence is the contract. It skips
     * export level and instrument access, so it may admit a caller that
     * mayWork() will refuse - which is the safe direction, because every verb
     * re-asks mayWork() before doing anything. It exists because the page has
     * to decide whether to render a Continue button, and rebuilding the whole
     * plan (durableScanContext) to answer that costs a second full plan build
     * on every page load. Nothing that WRITES may call this.
     *
     * @return array{ok:bool, why:?string, scope:?string, unrestricted:bool}
     */
    public static function mayTouchScope($rights, $runScopeDag)
    {
        $base = self::readable($rights);
        if (!$base['ok']) return $base;
        return self::scopeMatches($base, $runScopeDag);
    }

    /**
     * A DAG user may only touch a run whose immutable scope is exactly theirs.
     *
     * BOTH SIDES ARE GROUP IDS. $base['scope'] comes from readable() above and
     * $runScopeDag from uv_scan_run.scope_dag, written by
     * ScanPageView::scanScope()['dag']. The string cast below makes an int and
     * a numeric string equal; it does not make a name and an id equal, and
     * nothing here can.
     */
    private static function scopeMatches(array $base, $runScopeDag)
    {
        if ($base['unrestricted']) return $base;
        if ($runScopeDag === null) {
            return self::no('this scan covers the whole project, which is wider than your Data '
                . 'Access Group');
        }
        if ((string) $runScopeDag !== (string) $base['scope']) {
            // Deliberately the SAME wording as "no such run": a distinct message
            // here would confirm that a run exists for another group.
            return self::no('that scan is not available to you');
        }
        return $base;
    }

    private static function no($why)
    {
        return ['ok' => false, 'why' => $why, 'scope' => null, 'unrestricted' => false];
    }
}
