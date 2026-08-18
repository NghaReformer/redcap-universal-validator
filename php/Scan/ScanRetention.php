<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * What the store forgets, and when.
 *
 * THREE DIFFERENT CLOCKS, deliberately not one. They expire different things for
 * different reasons, and collapsing them would make the shortest one govern
 * everything:
 *
 *   VALUE previews expire soonest. A stored value is the only thing here that is
 *   participant data; the finding it belongs to is a location and a rule name.
 *   Clearing the value leaves the report true and stops it being a copy of the
 *   project.
 *
 *   RUNS expire later. A finished run is evidence that a scan happened and what
 *   it concluded, which is worth keeping after its values are gone.
 *
 *   ABANDONED runs expire on their own, much sooner, because they are holding a
 *   project's scan slot. That is not really retention - it is a deadlock break -
 *   but it is the same "something stopped and nobody noticed" problem, so it
 *   lives here rather than in the worker that failed to finish.
 *
 * NOTHING HERE DELETES A FINDING TO SAVE SPACE. Clearing a value nulls a column;
 * purging a run removes the whole run. A report that silently loses rows as it
 * ages reads as the project having improved, which is the one misreading this
 * module exists to prevent.
 *
 * IMMEDIATE REVOCATION IS SEPARATE FROM EXPIRY. When a project tightens its
 * privacy policy, previews must stop being readable NOW - before any purge has
 * run - so revoke() bumps the policy revision and the read paths refuse on the
 * mismatch. Waiting for a cron to catch up would leave a window in which the old
 * policy is still being served.
 */
final class ScanRetention
{
    /** @var ScanDb */
    private $db;

    public function __construct(ScanDb $db)
    {
        $this->db = $db;
    }

    /**
     * Clear value previews whose time is up.
     *
     * The column, not the row. See the class note: the finding stays true.
     *
     * @return int findings whose value was cleared
     */
    public function expireValues($now = null)
    {
        $now = $now === null ? self::now() : $now;
        $this->db->exec('UPDATE ' . Schema::table('finding') . '
            SET value_bin = NULL, value_fingerprint = NULL, value_expires_at = NULL
            WHERE value_expires_at IS NOT NULL AND value_expires_at <= ?', [$now]);
        return $this->db->affected();
    }

    /**
     * Stop serving previews immediately, ahead of any purge.
     *
     * Bumping the revision is what makes this immediate: every read path
     * compares the run's stored revision against the project's current one and
     * refuses on a mismatch, so the previews become unreadable in the same
     * request that tightened the policy. The actual clearing follows; the point
     * is that nothing is served in the gap.
     *
     * @return int runs whose previews were revoked
     */
    public function revokePreviews($pid, $newRevision)
    {
        $this->db->exec('UPDATE ' . Schema::table('scan_run') . '
            SET policy_revision = ?, values_state = ?, updated_at = ?
            WHERE project_id = ? AND policy_revision < ?',
            [(int) $newRevision, 'expired', self::now(), $pid, (int) $newRevision]);
        $n = $this->db->affected();
        // Then clear, in the same call rather than on a later schedule: the
        // window between "unreadable" and "gone" is small and bounded, instead
        // of being however long until the next cron.
        $this->db->exec('UPDATE ' . Schema::table('finding') . ' f
            JOIN ' . Schema::table('scan_run') . ' r ON r.generation_id = f.generation_id
            SET f.value_bin = NULL, f.value_fingerprint = NULL, f.value_expires_at = NULL
            WHERE r.project_id = ?', [$pid]);
        return $n;
    }

    /**
     * Remove finished runs past their retention.
     *
     * ACTIVE RUNS ARE NEVER TOUCHED, whatever their age: a long scan is not an
     * abandoned one, and deleting a run out from under a working worker would
     * turn a slow scan into a corrupt one. Abandonment is expire(), below, and
     * it goes through the run's own terminal state first.
     *
     * @return int runs removed
     */
    public function purgeRuns($pid, $days)
    {
        $cut = self::daysAgo($days);
        $ids = $this->db->select('SELECT run_id, generation_id FROM ' . Schema::table('scan_run')
            . ' WHERE project_id = ? AND active_slot IS NULL AND updated_at < ?', [$pid, $cut]);
        $n = 0;
        foreach ($ids as $row) {
            $runId = (int) $row[0];
            $gen   = (int) $row[1];
            // Children before parents. There are no foreign keys - the plan
            // calls for application-enforced cascade - so this ORDER is the
            // cascade, and reversing it orphans rows whose parent is gone.
            $this->db->exec('DELETE FROM ' . Schema::table('scan_record') . ' WHERE run_id = ?', [$runId]);
            $this->db->exec('DELETE FROM ' . Schema::table('scan_aggregate') . ' WHERE run_id = ?', [$runId]);
            $this->db->exec('DELETE FROM ' . Schema::table('finding') . ' WHERE generation_id = ?', [$gen]);
            $this->db->exec('DELETE FROM ' . Schema::table('unique_candidate') . ' WHERE generation_id = ?', [$gen]);
            $this->db->exec('DELETE FROM ' . Schema::table('unique_group') . ' WHERE generation_id = ?', [$gen]);
            $this->db->exec('DELETE FROM ' . Schema::table('scan_dim') . ' WHERE generation_id = ?', [$gen]);
            $this->db->exec('DELETE FROM ' . Schema::table('scan_run') . ' WHERE run_id = ?', [$runId]);
            $n++;
        }
        return $n;
    }

    /**
     * Give up on runs that stopped making progress, and release their slots.
     *
     * A run whose lease has expired and which has not been updated within the
     * stale window is abandoned: its browser closed, its worker died, or its
     * request was killed. It becomes terminally `expired` - a real terminal
     * state with `partial` coverage, never `complete` - and its project slot is
     * freed so a new scan can start.
     *
     * The predicate requires active_slot = 1, so this is idempotent: a run it
     * already expired is no longer a candidate.
     *
     * @return int runs expired
     */
    public function expireAbandoned($staleHours)
    {
        $cut = gmdate('Y-m-d H:i:s', time() - (max(1, (int) $staleHours) * 3600));
        $this->db->exec('UPDATE ' . Schema::table('scan_run') . '
            SET phase = ?, terminal = ?, coverage = ?, active_slot = NULL,
                terminal_reason = ?, updated_at = ?
            WHERE active_slot = 1 AND updated_at < ?
              AND (lease_expires_at IS NULL OR lease_expires_at < ?)',
            ['terminal', ScanOutcome::EXPIRED, ScanOutcome::COV_PARTIAL,
             'no progress within the configured stale-run window; the scan slot was released',
             self::now(), $cut, self::now()]);
        return $this->db->affected();
    }

    /**
     * What retention WOULD do, without doing it.
     *
     * An administrator deciding whether to shorten a window should be able to
     * see the consequence first. Counting is cheap; explaining a deletion after
     * the fact is not.
     */
    public function preview($pid, array $policy)
    {
        $vals = $this->db->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
            . ' WHERE value_expires_at IS NOT NULL AND value_expires_at <= ?', [self::now()]);
        $runs = $this->db->select('SELECT COUNT(*) FROM ' . Schema::table('scan_run')
            . ' WHERE project_id = ? AND active_slot IS NULL AND updated_at < ?',
            [$pid, self::daysAgo(isset($policy['runDays']) ? $policy['runDays'] : 90)]);
        return ['values_to_clear' => isset($vals[0][0]) ? (int) $vals[0][0] : 0,
                'runs_to_purge'   => isset($runs[0][0]) ? (int) $runs[0][0] : 0];
    }

    private static function now() { return gmdate('Y-m-d H:i:s'); }
    private static function daysAgo($days)
    {
        return gmdate('Y-m-d H:i:s', time() - (max(0, (int) $days) * 86400));
    }
}
