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
            return self::no('at least one field a rule depends on could not be located on an '
                . 'instrument, so access to it cannot be checked and the scan was not started');
        }
        $barred = self::barredForms($rights, $entitlement);
        if ($barred) {
            return self::no('this scan reads instrument(s) you do not have access to, so the whole '
                . 'report is refused rather than quietly narrowed');
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
     */
    public static function mayCancel($rights, array $entitlement, $runScopeDag,
                                     $creator = null, $username = null)
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
        if ($creator !== null && $username !== null && (string) $creator === (string) $username) {
            return self::scopeMatches($base, $runScopeDag);     // their own run
        }
        return self::scopeMatches($base, $runScopeDag);
    }

    /**
     * What `scan-start` may say when another scope already owns the project.
     *
     * GENERIC BY CONSTRUCTION. No run id, no owner, no scope, no progress, no
     * timing. A DAG user learning that a project-wide run is in progress learns
     * that someone with wider rights is looking at their project, and a run id
     * would let them ask about it. The message is identical whoever asks.
     */
    public static function busy()
    {
        return ['ok' => false, 'busy' => true, 'why' => 'a validation scan is already running for '
            . 'this project. Try again when it has finished.', 'scope' => null, 'unrestricted' => false];
    }

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
    private static function barredForms($rights, array $entitlement)
    {
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

    /** A DAG user may only touch a run whose immutable scope is exactly theirs. */
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
