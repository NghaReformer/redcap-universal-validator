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
 * run - so revokePreviews() bumps the policy revision and marks the runs'
 * values expired in one statement, and the read paths refuse on that. Waiting
 * for a cron to catch up would leave a window in which the old policy is still
 * being served. The clearing that follows is paged, and that is safe precisely
 * because nothing is served while it runs.
 *
 * EVERY STATEMENT THAT WALKS FINDINGS IS A PAGE (P6). Measured, an unpaged
 * DELETE of 500,000 findings is one 160-second statement holding its row locks
 * throughout, and the value sweep was the same shape as an UPDATE. Each method
 * below touches at most PAGE rows per statement through a key, and the paging
 * ones answer `done` so a cron can run a fixed slice and come back. Every write
 * here reads its own count with exec() + affected(): paging needs it to know
 * whether more remains, and the cron reports it.
 */
final class ScanRetention
{
    /** The most rows one statement here may touch. One number, the store's. */
    const PAGE = ScanStore::PAGE;

    /** @var ScanDb */
    private $db;

    public function __construct(ScanDb $db)
    {
        $this->db = $db;
    }

    /**
     * Clear the value previews whose time is up, one window of ids.
     *
     * The column, not the row. See the class note: the finding stays true.
     *
     * A PRIMARY KEY RANGE, NOT A LIMIT. value_expires_at has no index, and
     * adding one is a schema version-3 decision deliberately not taken. A
     * `... WHERE value_expires_at <= ? LIMIT n` page would have the server walk
     * the table from the start on every page, past every row an earlier page
     * already cleared or that has no preview at all, to find the next n - O(N)
     * per page and O(N^2) for a sweep. A window `finding_id > ? AND finding_id
     * <= ?` of $window ids is read through the primary key and holds at most
     * $window rows whatever fraction of them qualifies, so every statement is
     * bounded and the sweep is one pass. The cursor is the window's last id.
     *
     * THE TWO NUMBERS (formerly the open question in this docblock). Without a
     * project this is installation-wide, which is the right shape for a cron:
     * expiry is a fact about each row. With $pid it clears exactly what
     * preview($pid) counts, so an administrator shown N for a project is told
     * N afterwards. The project narrows what is cleared, not what is read: a
     * scoped sweep walks the same windows.
     *
     * @param int      $afterId the cursor: the last id of the previous window
     * @param int|null $pid     one project, or null for the installation
     * @param string|null $now  'Y-m-d H:i:s' UTC; the expiry it is compared with
     * @param int      $window  ids per window, at most PAGE
     * @return array{done:bool, rows:int, cursor:int, top:int} `rows` previews
     *         cleared in this window, `cursor` the id to pass next, `top` the
     *         highest finding id when the window was read; done once the
     *         window reached it
     */
    public function expireValuesPage($afterId = 0, $pid = null, $now = null, $window = self::PAGE)
    {
        $now = $now === null ? self::now() : (string) $now;
        $from = max(0, (int) $afterId);
        $to = $from + max(1, min(self::PAGE, (int) $window));
        $sql = 'UPDATE ' . Schema::table('finding') . '
            SET value_bin = NULL, value_fingerprint = NULL, value_expires_at = NULL, z_score = NULL
            WHERE finding_id > ? AND finding_id <= ?
              AND value_expires_at IS NOT NULL AND value_expires_at <= ?';
        $params = [$from, $to, $now];
        if ($pid !== null) {
            $sql .= ' AND project_id = ?';
            $params[] = self::project($pid);
        }
        $this->db->exec($sql, $params);
        $rows = $this->db->affected();
        $top = $this->topFinding();
        return ['done' => $to >= $top, 'rows' => (int) $rows, 'cursor' => $to, 'top' => $top];
    }

    /**
     * Clear every preview whose time is up, a window at a time.
     *
     * NOT THE CRON'S ANY MORE (wave 10). The retention cron runs
     * expireValuesPage() under a budget from a stored window
     * (ScanMaintenance::retain()), because this runs the whole sweep to the end
     * in one call. Every statement it sends is a window, so none holds more
     * than PAGE rows, and it stops at the highest id it saw first, so rows
     * written while it runs - which expire in the future anyway - cannot keep
     * it going. Kept for the callers that want the whole sweep at once.
     *
     * @return int previews cleared
     */
    public function expireValues($now = null, $pid = null)
    {
        $now = $now === null ? self::now() : (string) $now;
        $cleared = 0;
        $cursor = 0;
        $horizon = null;
        do {
            $page = $this->expireValuesPage($cursor, $pid, $now);
            $cleared += $page['rows'];
            $cursor = $page['cursor'];
            if ($horizon === null) $horizon = $page['top'];
        } while (!$page['done'] && $cursor < $horizon);
        return $cleared;
    }

    /**
     * Stop serving previews immediately, then clear them a page at a time.
     *
     * THE BUMP IS IMMEDIATE AND THE CLEAR IS PAGED, and the first is what makes
     * the second safe. One statement moves every run of the project below the
     * new revision to it and marks its values `expired` - bounded by the
     * project's runs, a handful - and every read path refuses a run whose
     * values are expired, so the previews are unreadable in the request that
     * tightened the policy. The clear that follows used to be one multi-table
     * UPDATE JOIN over every finding of the project, run synchronously in that
     * request; it is now a page per call, and a call that finds the bump
     * already done simply carries on clearing.
     *
     * WHICH ROWS: every finding in a generation of this project's runs whose
     * values are expired - both sides of the old join, project and generation,
     * and never another project's. Walked generation by generation, the active
     * rows and then the closed ones, each as a keyset on ix_page_v2 (project,
     * generation, active_slot, finding_id): a bounded read finds the page's
     * last id, and the UPDATE takes that id range under the same three
     * equalities, so the server reads the rows it clears and no others. A
     * `value_bin IS NOT NULL ... LIMIT` page would re-read every row it had
     * already cleared on every later page.
     *
     * (The comment that explained the old join used to live inside the SQL
     * string, where `//` is query text and MySQL answered ERROR 1064: the
     * cross-project fix it described had never once run. Comments stay in PHP.)
     *
     * A NULL REVISION BUMPS NOTHING and only carries the clearing on. The
     * cron's later calls for one revocation pass null (wave 10): a run
     * started after the bump was planned under the policy that caused it, and
     * a repeated bump would mark its values expired too. Every run starts at
     * revision 1, so "below the revision" does not tell such a run apart.
     *
     * @param int|null   $newRevision the revision to move the project's runs
     *                           to, or null to bump nothing
     * @param array|null $cursor what the previous call FOR THIS PROJECT returned,
     *                           or null to start; any other cursor starts again
     *                           (review R8-3, see revokeAt())
     * @param int        $limit  rows per page, at most PAGE
     * @return array{runs:int, rows:int, done:bool, cursor:?array} `runs` whose
     *         previews this call revoked (0 once the bump has been made),
     *         `rows` previews cleared by this call
     */
    public function revokePreviews($pid, $newRevision, $cursor = null, $limit = self::PAGE)
    {
        $pid = self::project($pid);
        $limit = max(1, min(self::PAGE, (int) $limit));
        $runs = 0;
        if ($newRevision !== null) {
            // NOT updated_at. It is a finished run's age for purgeRuns(), and
            // the bump is no work on the run: moving it restarted every run's
            // retention, so tightening the privacy policy kept the project's
            // old findings up to runDays longer (wave 10, the first caller).
            $this->db->exec('UPDATE ' . Schema::table('scan_run') . '
                SET policy_revision = ?, values_state = ?
                WHERE project_id = ? AND policy_revision < ?',
                [(int) $newRevision, 'expired', $pid, (int) $newRevision]);
            $runs = (int) $this->db->affected();
        }

        $gens = [];
        foreach ($this->db->select('SELECT DISTINCT generation_id FROM ' . Schema::table('scan_run')
                . ' WHERE project_id = ? AND values_state = ? ORDER BY generation_id',
                [$pid, 'expired']) as $g) {
            $gens[] = (int) $g[0];
        }
        $at = self::revokeAt($cursor, $pid, $gens);
        $rows = 0;
        while ($at !== null) {
            list($n, $last) = $this->slicePage($pid, $at['gen'], $at['slot'], $at['after'], $limit);
            if ($n > 0) {
                $this->db->exec('UPDATE ' . Schema::table('finding') . '
                    SET value_bin = NULL, value_fingerprint = NULL, value_expires_at = NULL, z_score = NULL
                    WHERE project_id = ? AND generation_id = ? AND ' . self::slot($at['slot']) . '
                      AND finding_id > ? AND finding_id <= ?',
                    [$pid, $at['gen'], $at['after'], $last]);
                $rows += (int) $this->db->affected();
            }
            if ($n >= $limit) {
                $at['after'] = $last;          // more of this slice, next call
                break;
            }
            $at = self::nextSlice($at, $gens);
            if ($n > 0) break;                 // one UPDATE per call
        }
        return ['runs' => $runs, 'rows' => $rows, 'done' => $at === null,
                'cursor' => $at === null ? null : self::issued($at, $pid, $gens)];
    }

    /**
     * Remove finished runs past their retention, one bounded step at a time.
     *
     * ACTIVE RUNS ARE NEVER TOUCHED, whatever their age: a long scan is not an
     * abandoned one, and deleting a run out from under a working worker would
     * turn a slow scan into a corrupt one. Abandonment is expireAbandoned(),
     * below, and it goes through the run's own terminal state first.
     *
     * CHILDREN BEFORE PARENTS, AND THE RUN ROW ONLY WHEN THEY ARE ALL GONE.
     * There are no foreign keys - the cascade is application-enforced - so this
     * order IS the cascade. Each DELETE takes at most $limit rows through a key
     * the table already has: the run's manifest, aggregates and plan by run_id,
     * and its generation's findings, candidates, groups and dimensions by
     * (project_id, generation_id). A table that gives up fewer than $limit rows
     * is empty, and the call moves to the next; the first that gives up a full
     * page ends the call, because it may hold more. The run row goes last, in
     * the call where every child table answered short - so a call interrupted
     * anywhere leaves the run row in place, and the next call finds it and
     * carries on. Runs are taken oldest first.
     *
     * BY PROJECT AND GENERATION. These deletes named the generation only, and
     * the generation was 1 for every run of every project - so purging ONE
     * finished run of ONE project deleted every project's findings on the whole
     * installation. Reproduced against a real server before it was fixed: two
     * projects, one purge, zero findings left anywhere.
     *
     * A GENERATION ANOTHER RUN STILL NAMES IS LEFT ALONE. An incremental run
     * reports into its baseline's generation, so a generation can belong to
     * more than one run; its rows go with the last of them.
     *
     * uv_scan_audit is not a child. It is the record that the run existed.
     *
     * @param int $olderThanDays a run last touched longer ago than this goes
     * @param int $limit         rows per statement, at most PAGE
     * @return array{done:bool, rows:int, runs:int, cursor:?int} `rows` child
     *         rows deleted by this call, `runs` run rows removed by it, `cursor`
     *         the run it was working on; done when no purgeable run is left
     */
    public function purgeRuns($pid, $olderThanDays, $limit = self::PAGE)
    {
        $pid = self::project($pid);
        $limit = max(1, min(self::PAGE, (int) $limit));
        $cut = self::daysAgo($olderThanDays);
        $run = $this->db->select('SELECT run_id, generation_id FROM ' . Schema::table('scan_run')
            . ' WHERE project_id = ? AND active_slot IS NULL AND updated_at < ?'
            . ' ORDER BY run_id LIMIT 1', [$pid, $cut]);
        if (!isset($run[0])) {
            return ['done' => true, 'rows' => 0, 'runs' => 0, 'cursor' => null];
        }
        $runId = (int) $run[0][0];
        $gen   = (int) $run[0][1];

        $steps = [
            ['scan_record', 'run_id = ?', [$runId]],
            ['scan_aggregate', 'run_id = ?', [$runId]],
            ['scan_plan', 'run_id = ?', [$runId]],
        ];
        $shared = $this->db->select('SELECT EXISTS(SELECT 1 FROM ' . Schema::table('scan_run')
            . ' WHERE project_id = ? AND generation_id = ? AND run_id <> ?)', [$pid, $gen, $runId]);
        if (!isset($shared[0][0])) {
            // EXISTS always answers. No answer is a read that did not work, and
            // reading it as "not shared" would delete another run's findings.
            throw new \RuntimeException('could not tell whether another run shares this generation');
        }
        if ((int) $shared[0][0] === 0) {
            foreach (['finding', 'unique_candidate', 'unique_group', 'scan_dim'] as $t) {
                $steps[] = [$t, 'project_id = ? AND generation_id = ?', [$pid, $gen]];
            }
        }
        $rows = 0;
        foreach ($steps as $s) {
            $n = $this->deletePage($s[0], $s[1], $s[2], $limit);
            $rows += $n;
            if ($n >= $limit) {
                return ['done' => false, 'rows' => $rows, 'runs' => 0, 'cursor' => $runId];
            }
        }
        $this->db->exec('DELETE FROM ' . Schema::table('scan_run')
            . ' WHERE run_id = ? AND project_id = ? AND active_slot IS NULL', [$runId, $pid]);
        $gone = (int) $this->db->affected();
        $more = $this->db->select('SELECT EXISTS(SELECT 1 FROM ' . Schema::table('scan_run')
            . ' WHERE project_id = ? AND active_slot IS NULL AND updated_at < ?)', [$pid, $cut]);
        if (!isset($more[0][0])) {
            throw new \RuntimeException('could not tell whether more runs are due for removal');
        }
        return ['done' => (int) $more[0][0] === 0, 'rows' => $rows, 'runs' => $gone,
                'cursor' => $runId];
    }

    /**
     * Give up on runs that stopped making progress, and release their slots.
     *
     * A run whose lease has expired and which has made no PROGRESS within the
     * stale window is abandoned: its browser closed, its worker died, or its
     * request was killed. It becomes terminally `expired` - a real terminal
     * state with `partial` coverage, never `complete` - and its project slot is
     * freed so a new scan can start.
     *
     * PROGRESS, NOT ACTIVITY, and the distinction is the whole finding. This
     * read updated_at, which is written by claim(), advancePhase(),
     * setProgressState() and every batch fence - so a run stuck in a retry loop
     * refreshed it several times a second and could not be reaped at ANY
     * staleHours setting, while this method's own terminal_reason said "no
     * progress within the configured stale-run window". progress_at is written
     * in three places, each of them real progress: a batch that finished at
     * least one record, the manifest freezing, and a page of the manifest
     * appended (B10). The third matters as planning runs across requests: a
     * planning phase that lists a large project over hours is moving the whole
     * time, and without it the run was judged on created_at alone and expired
     * out from under the planner.
     *
     * COALESCE, because progress_at is NULL until one of those happens. A run
     * that wedged before its first page has never made progress and created_at
     * is then the honest measure of how long it has been failing to.
     *
     * The predicate requires active_slot = 1, so this is idempotent: a run it
     * already expired is no longer a candidate.
     *
     * THE STORE WRITES IT (wave 10, B6a). This was one UPDATE here over every
     * project's live runs, and it wrote a row of its own: no detail, no clean,
     * no counts, a sentence no other writer of an expired run used, the lease
     * epoch left where it was - so a browser worker returning after the reap
     * passed the commit fence and wrote findings into a run already reported
     * expired (Risk 5) - and no audit entry. ScanStore::expireAbandoned() is
     * the one reaper now, for one project, and Start and the cron both reach
     * it. This keeps the installation-wide form: one read names the projects
     * with a stale run, and each is reaped by the store under its own
     * predicate.
     *
     * @param int|null $pid one project, or null for every project
     * @param string   $actor who the audit names
     * @param int|null $now unix seconds; null is now
     * @return int runs expired
     */
    public function expireAbandoned($staleHours, $pid = null, $actor = 'cron', $now = null)
    {
        $store = new SqlScanStore($this->db);
        if ($pid !== null) {
            return $store->expireAbandoned(self::project($pid), $staleHours, $actor, $now);
        }
        $t = $now === null ? time() : (int) $now;
        $rows = $this->db->select('SELECT DISTINCT project_id FROM ' . Schema::table('scan_run')
            . ' WHERE active_slot = 1 AND COALESCE(progress_at, created_at) < ?'
            . ' AND (lease_expires_at IS NULL OR lease_expires_at < ?) ORDER BY project_id',
            [gmdate('Y-m-d H:i:s', $t - max(1, (int) $staleHours) * 3600), gmdate('Y-m-d H:i:s', $t)]);
        $n = 0;
        foreach ($rows as $r) {
            if ((int) $r[0] < 1) continue;
            $n += $store->expireAbandoned((int) $r[0], $staleHours, $actor, $now);
        }
        return $n;
    }

    /**
     * What retention WOULD do to ONE project, without doing it.
     *
     * An administrator deciding whether to shorten a window should be able to
     * see the consequence first. Counting is cheap; explaining a deletion after
     * the fact is not.
     *
     * WHAT IT COUNTS, exactly. `values_to_clear` is this project's previews
     * whose time is up now - the rows expireValues($now, $pid) clears, and this
     * project's share of what the installation-wide sweep clears. It used to
     * count every expired preview on the installation, so an administrator of
     * a small project was shown the consequence of somebody else's data.
     * `runs_to_purge` is the finished runs purgeRuns() would remove at
     * $policy['runDays'].
     *
     * ONE READ OF THIS PROJECT'S FINDINGS, NOT A PAGE. value_expires_at has no
     * index, so the count reads the project's rows once, through a
     * project-leading key, as a consistent read that takes no locks. It is the
     * one statement in this class not bounded by PAGE, on purpose: it is asked
     * by a person, once, and a count delivered in pages would be a job rather
     * than an answer. Nothing scheduled may call it.
     */
    public function preview($pid, array $policy)
    {
        $pid = self::project($pid);
        $vals = $this->db->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
            . ' WHERE project_id = ? AND value_expires_at IS NOT NULL'
            . ' AND value_expires_at <= ?', [$pid, self::now()]);
        $runs = $this->db->select('SELECT COUNT(*) FROM ' . Schema::table('scan_run')
            . ' WHERE project_id = ? AND active_slot IS NULL AND updated_at < ?',
            [$pid, self::daysAgo(isset($policy['runDays']) ? $policy['runDays'] : 90)]);
        // COUNT(*) always answers one row. No row is a read that did not work,
        // and showing it as 0 would tell the administrator nothing is affected.
        if (!isset($vals[0][0], $runs[0][0])) {
            throw new \RuntimeException('the retention preview could not be counted');
        }
        return ['values_to_clear' => (int) $vals[0][0], 'runs_to_purge' => (int) $runs[0][0]];
    }

    // -- the pieces ----------------------------------------------------------

    /**
     * One bounded DELETE, and the count that says whether the table is empty.
     *
     * The write and the read of its count in one function, as the order lint
     * in tests/scan_moduledb_php.php requires. $table and $where are this
     * class's own literals, never a caller's.
     */
    private function deletePage($table, $where, array $params, $limit)
    {
        $this->db->exec('DELETE FROM ' . Schema::table($table) . ' WHERE ' . $where
            . ' LIMIT ' . (int) $limit, $params);
        return (int) $this->db->affected();
    }

    /**
     * How many rows the next page of one slice holds, and its last id.
     *
     * One slice is a (project, generation, active_slot) triple, which is an
     * equality on the first three columns of ix_page_v2, so the derived table
     * reads at most $limit index entries in finding_id order and never a row.
     * COUNT and MAX of a derived table always answer one row; no row is a read
     * that did not work, and is not taken for an empty slice.
     *
     * @return array{0:int, 1:int} [rows in the page, last finding_id or 0]
     */
    private function slicePage($pid, $gen, $slot, $after, $limit)
    {
        // FORCE INDEX, for the reason UniqueFinalizer::nextUnfinished() gives.
        // ix_page_v2 is (project_id, generation_id, active_slot, finding_id):
        // the slice is its prefix and the page is its order, so the read stops
        // after $limit index entries. Measured on MySQL 8.0 and MariaDB 10.5
        // and 10.11 over a small table, the optimiser chose
        // uq_active_identity_v2 instead and sorted the slice; 5.7 chose the
        // page key. A plan that depends on table statistics is not a bound.
        $r = $this->db->select('SELECT COUNT(*), MAX(finding_id) FROM (SELECT finding_id FROM '
            . Schema::table('finding') . ' FORCE INDEX (ix_page_v2) WHERE project_id = ? AND generation_id = ? AND '
            . self::slot($slot) . ' AND finding_id > ? ORDER BY finding_id LIMIT ' . (int) $limit
            . ') page', [$pid, (int) $gen, (int) $after]);
        if (!isset($r[0][0])) {
            throw new \RuntimeException('a page of this project\'s findings could not be read');
        }
        return [(int) $r[0][0], isset($r[0][1]) ? (int) $r[0][1] : 0];
    }

    /** The highest finding id there is, or 0 for an empty table. */
    private function topFinding()
    {
        $r = $this->db->select('SELECT MAX(finding_id) FROM ' . Schema::table('finding'));
        if (!isset($r[0]) || !array_key_exists(0, (array) $r[0])) {
            // MAX() always answers one row. No row is a read that did not work,
            // and taking it for an empty table would call the sweep finished.
            throw new \RuntimeException('the highest finding id could not be read');
        }
        $v = array_values((array) $r[0]);
        return $v[0] === null ? 0 : (int) $v[0];
    }

    /** The active rows of a generation, or the closed and staged ones. */
    private static function slot($active)
    {
        return $active ? 'active_slot = 1' : 'active_slot IS NULL';
    }

    /**
     * Where a revocation picks up: the caller's cursor, or the first slice.
     *
     * A CURSOR IS TRUSTED ONLY FOR THE PROJECT AND THE GENERATIONS IT WAS
     * ISSUED FOR (review R8-3). Every row of a slice at or below `after` is
     * skipped, and the cursor was a bare {gen, slot, after}: another project's
     * cursor named a generation number this project also has, its `after` was
     * a finding id above all of this project's rows in it, and the walk
     * answered done after one call with every preview still stored - unread,
     * the bump saw to that, but at rest. Likewise a cursor whose generation
     * had gone moved on past every generation below it. So the cursor carries
     * the project and a digest of the generation list it was walking, and one
     * that does not match both starts again from the first slice. Starting
     * again clears nothing twice and skips nothing: rows already cleared are
     * walked past, a page at a time.
     *
     * The digest is a check against a cursor handed to the wrong project or
     * kept past a change in what is being revoked, not against forgery: the
     * cursor is the caller's own server-side state.
     */
    private static function revokeAt($cursor, $pid, array $gens)
    {
        if (!$gens) return null;
        $first = ['gen' => $gens[0], 'slot' => 1, 'after' => 0];
        if (!is_array($cursor) || !isset($cursor['pid'], $cursor['set'], $cursor['gen'],
                                         $cursor['slot'], $cursor['after'])) {
            return $first;
        }
        if ((int) $cursor['pid'] !== (int) $pid || !is_string($cursor['set'])
                || !hash_equals(self::genSet($pid, $gens), $cursor['set'])
                || !in_array((int) $cursor['gen'], $gens, true)) {
            return $first;
        }
        return ['gen' => (int) $cursor['gen'], 'slot' => (int) $cursor['slot'] ? 1 : 0,
                'after' => max(0, (int) $cursor['after'])];
    }

    /** A cursor as this class hands it out: where to resume, and what for. */
    private static function issued(array $at, $pid, array $gens)
    {
        return ['pid' => (int) $pid, 'set' => self::genSet($pid, $gens),
                'gen' => $at['gen'], 'slot' => $at['slot'], 'after' => $at['after']];
    }

    /** The digest of the generations a revocation of $pid is walking. */
    private static function genSet($pid, array $gens)
    {
        return substr(hash('sha256', (int) $pid . ':' . implode(',', $gens)), 0, 16);
    }

    /** Active rows, then closed rows, then the next generation; null at the end. */
    private static function nextSlice(array $at, array $gens)
    {
        if ($at['slot'] === 1) return ['gen' => $at['gen'], 'slot' => 0, 'after' => 0];
        foreach ($gens as $g) {
            if ($g > $at['gen']) return ['gen' => $g, 'slot' => 1, 'after' => 0];
        }
        return null;
    }

    /** A project id this class may act on. Zero is the version-1 leftover. */
    private static function project($pid)
    {
        if ((int) $pid < 1) {
            throw new \InvalidArgumentException('retention needs the project it is acting for');
        }
        return (int) $pid;
    }

    private static function now() { return gmdate('Y-m-d H:i:s'); }
    private static function daysAgo($days)
    {
        return gmdate('Y-m-d H:i:s', time() - (max(0, (int) $days) * 86400));
    }
}
