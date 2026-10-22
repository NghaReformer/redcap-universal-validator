<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * What the module does on a timer, and when it is switched off.
 *
 * TWO CRONS, ONE CLASS (wave 10, B6). config.json declares `uv_scan_reap_abandoned`
 * and `uv_scan_expire_values`; UniversalValidator's two cron methods build one
 * of these and run reap() or retain(). Everything a pass does is here, so the
 * MySQL harness can drive the real pass with a counting connection
 * (tests/mysql/cases/cron.php) while the module keeps only the framework edge:
 * the settings, the log and where the cursors are kept.
 *
 * FIXED SLICES UNDER A WALL-CLOCK BUDGET. A pass is given seconds, well inside
 * the cron's cron_max_run_time, and every unit of work - a page of a purge, a
 * window of the value sweep, a project - is started only while the budget
 * lasts. Each method of ScanRetention and the store it calls is itself one
 * bounded page, so the last unit cannot run long either. What a pass did not
 * reach waits for the next one, which starts where this one stopped: the
 * caller persists the WHOLE state this hands back (review R8-3) - the project
 * cursor, the value sweep's window and the revocations in progress with their
 * full cursor arrays - and hands it to the next pass.
 *
 * EVERY PROJECT WITH SCAN DATA, A PAGE OF PROJECTS PER STATEMENT. census()
 * reads uv_scan_run grouped by project, CENSUS_PAGE projects at a time through
 * the project-leading run keys, and says for each whether anything is due: a
 * stale active run, a completed run whose supersede has not finished, the
 * oldest finished run, and which value modes its runs still keep. A project
 * with nothing due costs nothing beyond its row in that statement, so a pass
 * over two hundred idle projects is a handful of statements
 * (tests/mysql/cases/cron.php counts them).
 *
 * ONE PROJECT'S FAILURE IS ITS OWN. A project whose work throws - a deadlock
 * beside a live worker (W8-N5), a settings read that failed, anything - is
 * logged with the step and the redacted detail and the pass goes on to the
 * next project; the next pass tries it again. Nothing here throws to the
 * cron: a census that cannot be read ends the pass with the cursor where it
 * was.
 *
 * A PASS CAN BE TOLD TO STOP. `stopped` is asked before every unit, beside the
 * budget. The module answers it from the marker its disable hook writes, so a
 * pass running in another process when the module is switched off stops at
 * its next unit (M17). The marker is read every few seconds, so a pass also
 * stops at the first statement that finds a table missing: a disable that
 * dropped the tables in between would otherwise fail every project left in
 * the page, one log entry each (review rv10a2-4).
 *
 * SWITCHING THE MODULE OFF is here too: disable() stops every active run and
 * then frees every worker slot, deleting nothing; dropTables() is the opt-in
 * that deletes.
 *
 * PHP 7.4.
 */
final class ScanMaintenance
{
    /** Projects one census statement covers. */
    const CENSUS_PAGE = 500;

    /** A finished value sweep starts again after this many seconds. Value windows are days long. */
    const SWEEP_EVERY = 86400;

    /** The share of a retention pass the project lap may use; the value sweep has the rest. */
    const LAP_SHARE = 0.6;

    /** The share of cron_max_run_time a pass is given: well inside it, so a slow page cannot overrun. */
    const BUDGET_SHARE = 0.25;

    /** The terminal sentence of a run the module's disabling stopped. */
    const DISABLED_WHY = 'the module was disabled on this server, so this scan was stopped before it '
        . 'finished; nothing it had examined was lost';

    /** The terminal sentence of a run stopped because the module was disabled for its project. */
    const PROJECT_DISABLED_WHY = 'the module was disabled for this project, so this scan was stopped before it '
        . 'finished; nothing it had examined was lost';

    /** Active runs disable() reads, and stops, per statement. */
    const DISABLE_PAGE = 500;

    /**
     * Seconds disable() may spend finishing stopped runs one by one: inside
     * the 30 s a web request is commonly allowed, with room for the reads and
     * the drop after it. Every run is stopped whatever this says.
     */
    const DISABLE_SECONDS = 20;

    /**
     * The settings ScanPolicy::resolve() reads, by owner: the ones the policy
     * reader hands this class. tests/scan_cron_php.php holds them equal to
     * every setting key resolve() names, so a key added there cannot be read
     * by the scan and missed by the purge.
     */
    const SYSTEM_POLICY_KEYS = ['scan-system-max-value-retention-days', 'scan-system-max-run-retention-days',
        'scan-system-max-detail-findings', 'scan-system-max-detail-bytes', 'scan-system-max-concurrent-projects',
        'scan-system-stale-run-hours', 'scan-system-record-attempts'];
    const PROJECT_POLICY_KEYS = ['scan-value-storage', 'scan-value-retention-days', 'scan-run-retention-days',
        'scan-max-detail-findings', 'scan-max-detail-bytes'];

    /** @var ScanDb */
    private $db;
    /** @var ScanStore */
    private $store;
    /** @var ScanRetention */
    private $retention;
    /** @var WorkerSlots */
    private $slots;
    /** @var callable */
    private $policy;
    /** @var callable */
    private $clock;
    /** @var callable */
    private $log;
    /** @var callable */
    private $stopped;
    /** @var callable */
    private $enter;
    /** @var callable */
    private $save;
    /** @var int unix seconds the windows of this pass are measured from */
    private $now;
    /** @var bool whether a stated `now` was given, so the store is asked with it */
    private $nowGiven;
    /** @var array the state of the pass in hand, as the next pass will be handed it */
    private $state = [];
    /** @var string which pass, for the log */
    private $what = '';

    /**
     * @param ScanDb $db   the module's handle
     * @param array  $deps {
     *   policy:  callable(?int $pid): array  ScanPolicy::resolve()'s answer for
     *            the project, or the installation's with null. Required.
     *   clock:   callable(): float  seconds, for the budget; microtime(true)
     *   log:     callable(string $event, array $context)  the module log
     *   stopped: callable(): bool  true once the module was disabled after the
     *            pass began
     *   enter:   callable(int $pid)  before a project's work: the module drops
     *            what it remembered about the last one (W8-N10)
     *   save:    callable(array $state)  persists the state at a checkpoint
     *   store:   ScanStore  SqlScanStore over $db when absent
     *   now:     int  unix seconds the windows are measured from; time()
     * }
     */
    public function __construct(ScanDb $db, array $deps)
    {
        if (!isset($deps['policy']) || !is_callable($deps['policy'])) {
            throw new \InvalidArgumentException('maintenance needs the policy reader');
        }
        $none = function () { };
        $this->db = $db;
        $this->store = (isset($deps['store']) && $deps['store'] instanceof ScanStore)
            ? $deps['store'] : new SqlScanStore($db);
        $this->retention = new ScanRetention($db);
        $this->slots = new WorkerSlots($db);
        $this->policy = $deps['policy'];
        $this->clock = isset($deps['clock']) && is_callable($deps['clock']) ? $deps['clock']
            : function () { return microtime(true); };
        $this->log = isset($deps['log']) && is_callable($deps['log']) ? $deps['log'] : $none;
        $this->stopped = isset($deps['stopped']) && is_callable($deps['stopped']) ? $deps['stopped']
            : function () { return false; };
        $this->enter = isset($deps['enter']) && is_callable($deps['enter']) ? $deps['enter'] : $none;
        $this->save = isset($deps['save']) && is_callable($deps['save']) ? $deps['save'] : $none;
        $this->nowGiven = isset($deps['now']);
        $this->now = $this->nowGiven ? (int) $deps['now'] : time();
    }

    /** Seconds a pass of a cron declared with $maxRunTime is given. */
    public static function budgetFor($maxRunTime)
    {
        return max(1, (int) floor(max(1, (int) $maxRunTime) * self::BUDGET_SHARE));
    }

    // -- the reap cron -------------------------------------------------------

    /**
     * Free what is stuck: abandoned runs, slots above the limit, and the
     * previous reports of every project whose latest scan completed.
     *
     * THE SLOTS FIRST (W1-N1). Lowering scan-system-max-concurrent-projects
     * takes effect in acquire() at once; the free rows above it are removed
     * here, so the table says what the limit is within one interval.
     *
     * THEN THE PROJECTS. A project with a stale active run has it expired
     * (ScanStore::expireAbandoned()), or finished as cancelled when somebody
     * had stopped it (finishStopped()); a project with a whole-project run that
     * completed and has not closed its older generations has them closed, a
     * page per call (W4-D1). Scoped runs are not listed: they supersede
     * nothing (review R8-1), and one that answers SUPERSEDE_SCOPED anyway is
     * done, never retried.
     *
     * @param array $state what the previous reap pass handed back
     * @param float $seconds the budget
     * @return array{state:array, out:array}
     */
    public function reap(array $state, $seconds)
    {
        $this->begin('reap', $state);
        $until = $this->started + max(0.0, (float) $seconds);
        $out = ['runs_expired' => 0, 'runs_stopped' => 0, 'slots_removed' => 0, 'superseded' => 0,
                'projects' => 0, 'failed' => 0, 'more' => 0, 'stopped' => 0];
        $sys = null;
        try {
            $sys = $this->sys = call_user_func($this->policy, null);
            $out['slots_removed'] = $this->slots->shrinkTo($sys['maxProjects']);
        } catch (\Throwable $e) {
            $this->failed('slots', null, $e);
            $out['failed']++;
        }
        if ($sys === null) return $this->end($out);
        $this->state['after'] = $this->lap($this->cursor(), $until, $out, function (array $row) use ($sys, $until, &$out) {
            if (!$row['stale'] && !$row['stopped'] && !$row['supersede']) return true;
            $out['projects']++;
            call_user_func($this->enter, $row['pid']);
            if ($row['stopped']) $out['runs_stopped'] += $this->finishStopped($row['pid']);
            if ($row['stale']) {
                $out['runs_expired'] += $this->store->expireAbandoned($row['pid'], $sys['staleHours'], 'cron',
                    $this->nowGiven ? $this->now : null);
            }
            return !$row['supersede'] || $this->supersede($row['pid'], $until, $out);
        });
        return $this->end($out);
    }

    /**
     * Finish a run somebody stopped and nobody finished (review rv10a1-2).
     *
     * Stop leaves a run `cancelling` while a worker holds it, for that worker
     * to finish at its next fence; a worker that never came back, a finish
     * that failed, or a disable that ran out of time leaves it there, holding
     * its project's slot and reading "stopping". Start finishes such a run
     * (ScanService::reapCancelled()); the cron expired it as abandoned once it
     * was stale, which threw away the user's stop and the reason. It is
     * finished here the way Stop finishes one, by
     * ScanPromotion::finishCancelled() with what the run found counted, once
     * the census finds it past the stale window with its lease lapsed: the
     * same wait a worker that still holds a run is given to come back.
     *
     * @return int runs finished: 0 or 1
     */
    private function finishStopped($pid)
    {
        $rows = $this->db->select('SELECT run_id FROM ' . Schema::table('scan_run')
            . ' WHERE project_id = ? AND active_slot = 1 AND phase = ?', [(int) $pid, ScanPhase::CANCELLING]);
        if (!isset($rows[0][0])) return 0;
        $policy = call_user_func($this->policy, $pid);
        return ScanPromotion::finishCancelled($this->store, $pid, (int) $rows[0][0], [
            'maxFindings' => $policy['maxFindings'], 'maxBytes' => $policy['maxBytes']]) ? 1 : 0;
    }

    /**
     * Close the older generations of every completed whole-project run of
     * one project, oldest run first, so each closes what it superseded with
     * its own sequence. A page that moved nothing and is not done (the cursor
     * moved under it, which another request superseding does) is asked again
     * next pass rather than spun on.
     *
     * @return bool false when the budget ran out inside the project
     */
    private function supersede($pid, $until, array &$out)
    {
        $runs = $this->db->select('SELECT run_id FROM ' . Schema::table('scan_run')
            . ' WHERE project_id = ? AND terminal = ? AND scope_dag IS NULL'
            . ' AND supersede_cursor < generation_id ORDER BY generation_id, run_id',
            [(int) $pid, ScanOutcome::COMPLETE]);
        foreach ($runs as $r) {
            $before = -1;
            while (true) {
                if ($this->halted($until, $out)) return false;
                $p = $this->store->supersedePrevious($pid, (int) $r[0], ScanStore::PAGE);
                $out['superseded'] += (int) $p['rows'];
                if (!empty($p['done'])) break;
                if ((int) $p['rows'] === 0 && (int) $p['cursor'] === $before) break;
                $before = (int) $p['cursor'];
            }
        }
        return true;
    }

    // -- the retention cron --------------------------------------------------

    /**
     * Forget what is due: revoked previews, finished runs past their
     * retention, and value previews past their window.
     *
     * REVOCATIONS IN PROGRESS FIRST. A tightened privacy policy stops the
     * previews being served in the call that bumps the revision; clearing them
     * is paged, and the cursor of each one in progress is part of the state.
     *
     * THEN THE PROJECTS, a lap with LAP_SHARE of the budget: a revocation
     * starts where the policy now allows less than a run kept, and finished
     * runs past the project's runDays are purged. A project's purge waits
     * while its revocation is unfinished. That is the R8-3 design note: a
     * revoke cursor carries a digest of the generations it walks, a purge
     * changes them, and the next call would start the walk again over pages
     * it had already cleared. Purging only once the revocation is done means
     * there is no such rework.
     *
     * THEN THE VALUE SWEEP, with the rest of the budget: expireValuesPage()
     * windows of the primary key, installation-wide, from the stored window.
     * A finished sweep starts again after SWEEP_EVERY seconds; an unfinished
     * one carries on at the next pass.
     *
     * @param array $state what the previous retention pass handed back
     * @param float $seconds the budget
     * @return array{state:array, out:array}
     */
    public function retain(array $state, $seconds)
    {
        $this->begin('retention', $state);
        $until = $this->started + max(0.0, (float) $seconds);
        $lapUntil = $this->started + max(0.0, (float) $seconds) * self::LAP_SHARE;
        $out = ['runs_revoked' => 0, 'previews_revoked' => 0, 'runs_purged' => 0, 'rows_purged' => 0,
                'values_cleared' => 0, 'projects' => 0, 'failed' => 0, 'more' => 0, 'stopped' => 0];
        if (!isset($this->state['revoke']) || !is_array($this->state['revoke'])) $this->state['revoke'] = [];

        foreach (array_keys($this->state['revoke']) as $pid) {
            if ($this->gone) {
                $out['stopped'] = 1;
                break;
            }
            // revokeMore() asks the budget itself, after a bump still owed.
            try {
                call_user_func($this->enter, (int) $pid);
                $this->revokeMore((int) $pid, $lapUntil, $out);
            } catch (\Throwable $e) {
                $this->failed('revoke', (int) $pid, $e);
                $out['failed']++;
            }
        }

        $this->state['after'] = $this->lap($this->cursor(), $lapUntil, $out, function (array $row) use ($lapUntil, &$out) {
            $pid = $row['pid'];
            if (isset($this->state['revoke'][$pid])) return true;   // its purge waits (R8-3)
            $kept = self::keptModes($row['modes']);
            // runDays is at least 1, so a run finished within the last day is
            // never due, whatever the project chose: no policy read for it.
            $mayPurge = $row['oldest'] !== null && strcmp($row['oldest'], self::at($this->now - 86400)) < 0;
            if (!$kept && !$mayPurge) return true;
            $out['projects']++;
            call_user_func($this->enter, $pid);
            $policy = call_user_func($this->policy, $pid);
            if ($kept && !$this->revokeStart($pid, $policy, $kept, $lapUntil, $out)) return false;
            if (isset($this->state['revoke'][$pid])) return true;
            if (!$mayPurge || strcmp($row['oldest'], self::at($this->now - max(1, (int) $policy['runDays']) * 86400)) >= 0) {
                return true;
            }
            return $this->purge($pid, (int) $policy['runDays'], $lapUntil, $out);
        });

        try {
            $this->valueSweep($until, $out);
        } catch (\Throwable $e) {
            $this->failed('expire-values', null, $e);
            $out['failed']++;
        }
        return $this->end($out);
    }

    /**
     * Start a revocation where the policy now allows less than a run kept.
     *
     * WHERE IT APPLIES. A run whose values_state still keeps values (a mode
     * ScanPolicy ranks above `locations`) is judged against the project's
     * policy now on the two settings that govern a preview, with
     * ScanPolicy::tightened(): the value mode and the value window. A frozen
     * policy that cannot be read is tightened, as ScanService treats it.
     *
     * The revision is one past the project's highest, so the bump reaches
     * every run of the project. A run starts at the project's highest
     * revision (W10A-N1), so one started under the new policy before a pass
     * noticed the change sits at the same revision as the runs it tightened,
     * and its previews are cleared too: more than it must, never less. A run
     * started after the bump starts at the new revision, and a repeat of the
     * bump leaves it alone. The intent is checkpointed before the bump and
     * again after it, so a pass that dies in between bumps again rather than
     * leaving previews it never clears.
     *
     * @return bool false when the budget ran out inside the clearing
     */
    private function revokeStart($pid, array $policy, array $kept, $until, array &$out)
    {
        $rows = $this->db->select('SELECT values_state, policy_json FROM ' . Schema::table('scan_run')
            . ' WHERE project_id = ? AND values_state IN (' . implode(',', array_fill(0, count($kept), '?')) . ')',
            array_merge([(int) $pid], $kept));
        $applies = false;
        $now = ['valueMode' => $policy['valueMode'], 'valueDays' => (int) $policy['valueDays']];
        foreach ($rows as $r) {
            $frozen = is_string($r[1]) ? json_decode($r[1], true) : null;
            if (!is_array($frozen)) { $applies = true; break; }
            $then = ['valueMode' => (string) $r[0]];
            if (isset($frozen['valueDays'])) $then['valueDays'] = (int) $frozen['valueDays'];
            if (ScanPolicy::tightened($then, $now)) { $applies = true; break; }
        }
        if (!$applies) return true;
        $max = $this->db->select('SELECT MAX(policy_revision) FROM ' . Schema::table('scan_run')
            . ' WHERE project_id = ?', [(int) $pid]);
        if (!isset($max[0]) || !array_key_exists(0, (array) $max[0])) {
            // MAX() always answers one row; none is a read that did not work,
            // and a guessed revision could leave some runs below it unbumped.
            throw new \RuntimeException('the project\'s policy revision could not be read');
        }
        $rev = (int) $max[0][0] + 1;
        $this->state['revoke'][$pid] = ['revision' => $rev, 'bumped' => false, 'cursor' => null];
        $this->checkpoint();
        call_user_func($this->log, 'scan-cron-revoke', ['project_id' => (int) $pid, 'revision' => $rev]);
        return $this->revokeMore($pid, $until, $out);
    }

    /**
     * Clear a revocation's previews a page at a time, from its stored cursor.
     *
     * The first call after the intent was stored bumps; every later one passes
     * no revision and bumps nothing (ScanRetention::revokePreviews()). The
     * cursor kept is the whole array the call handed back: it carries the
     * project and the generation digest, and a bare id would restart it.
     *
     * THE BUMP DOES NOT WAIT FOR THE BUDGET. It is what stops the previews
     * being served, one statement over the project's runs, so the call that
     * makes it is sent even by a pass whose budget has just run out; only
     * the clearing after it is rationed.
     *
     * @return bool false when the budget ran out first
     */
    private function revokeMore($pid, $until, array &$out)
    {
        $st = $this->state['revoke'][$pid];
        while (true) {
            if (!empty($st['bumped']) && $this->halted($until, $out)) return false;
            $r = $this->retention->revokePreviews($pid, empty($st['bumped']) ? (int) $st['revision'] : null,
                isset($st['cursor']) && is_array($st['cursor']) ? $st['cursor'] : null, ScanRetention::PAGE);
            $out['runs_revoked'] += (int) $r['runs'];
            $out['previews_revoked'] += (int) $r['rows'];
            $first = empty($st['bumped']);
            $st['bumped'] = true;
            $st['cursor'] = $r['cursor'];
            if (!empty($r['done'])) {
                unset($this->state['revoke'][$pid]);
                $this->checkpoint();
                return true;
            }
            $this->state['revoke'][$pid] = $st;
            if ($first) $this->checkpoint();
        }
    }

    /** Purge one project's finished runs past their retention, a page per call. */
    private function purge($pid, $runDays, $until, array &$out)
    {
        while (true) {
            if ($this->halted($until, $out)) return false;
            $p = $this->retention->purgeRuns($pid, $runDays, ScanRetention::PAGE);
            $out['rows_purged'] += (int) $p['rows'];
            $out['runs_purged'] += (int) $p['runs'];
            if (!empty($p['done'])) return true;
        }
    }

    /** The installation-wide value sweep, from the stored window. */
    private function valueSweep($until, array &$out)
    {
        $sw = isset($this->state['sweep']) && is_array($this->state['sweep']) ? $this->state['sweep'] : [];
        $sw += ['cursor' => 0, 'top' => null, 'finished' => null];
        $running = $sw['top'] !== null;
        if (!$running && $sw['finished'] !== null && $this->now - (int) $sw['finished'] < self::SWEEP_EVERY) {
            return;
        }
        if (!$running) $sw['cursor'] = 0;
        $at = self::at($this->now);
        while (!$this->halted($until, $out)) {
            $p = $this->retention->expireValuesPage((int) $sw['cursor'], null, $at);
            if ($sw['top'] === null) $sw['top'] = (int) $p['top'];
            $sw['cursor'] = (int) $p['cursor'];
            $out['values_cleared'] += (int) $p['rows'];
            $this->state['sweep'] = $sw;
            if (!empty($p['done']) || $sw['cursor'] >= (int) $sw['top']) {
                // Stopped at the highest id seen when it began: rows written
                // since expire in the future, and must not keep it going.
                $this->state['sweep'] = ['cursor' => 0, 'top' => null, 'finished' => $this->now];
                return;
            }
        }
    }

    // -- switching the module off (M17) ----------------------------------------

    /**
     * Stop every active run and release every worker slot, deleting nothing.
     *
     * A disabled module answers no request, so a run left active would hold
     * its project's slot through the next enable, and a leased worker slot
     * would be counted against the limit for a worker that is gone.
     *
     * THE RUNS FIRST, THE SLOTS LAST (review rv10a2-3). Releasing the slots
     * first told a worker still mid-batch, at its next renew(), that it had
     * "lost its place in the server queue" and would continue shortly - which
     * was false - and it took a slot again. Its run is stopped first now, so
     * its next fence tells it the truth, and the slots are freed when no run
     * is left to want one.
     *
     * EVERY RUN IS STOPPED FIRST, THEN AS MANY AS THE BUDGET ALLOWS ARE
     * FINISHED. A keyset over the active runs, DISABLE_PAGE per read; each
     * page is stopped in two statements (stopPage()): the epoch moves, so
     * every worker is refused at its next fence and nothing it committed is
     * lost, and the project's Start finishes the run at once. Only when every
     * page is stopped is each run finished the way Stop finishes one nobody
     * drives, ScanPromotion::finishCancelled() with what it found counted and
     * DISABLED_WHY, a handful of statements a run, while $seconds last. The
     * administrator's request is held for this, and 1,200 active runs took
     * 38 s finished one by one (the reviewer's measure), past a 30 s request.
     * A run the budget did not reach stays `cancelling` with DISABLED_WHY on
     * its row, and the first Start on its project, or the reap pass that
     * finds it stale after the next enable, finishes it with that sentence.
     *
     * THEN THE ACTIVE RUNS ARE READ ONCE MORE, before any is finished. Start
     * and work refuse once the disable marker is written
     * (ScanService::disabling()), but a Start already past that check could
     * make its run while the walk went on, in a part of the keyset the walk
     * had passed. The second read takes every active run not yet stopped.
     *
     * @param float $seconds how long the finishing may take
     * @return array{runs_stopped:int, runs_left:int, late:int, slots_released:int, failed:int}
     *         `runs_left` stopped and left for a later finish, `late` found by
     *         the second read
     */
    public function disable($seconds = self::DISABLE_SECONDS)
    {
        $this->what = 'disable';
        $until = (float) call_user_func($this->clock) + max(0.0, (float) $seconds);
        $out = ['runs_stopped' => 0, 'runs_left' => 0, 'late' => 0, 'slots_released' => 0, 'failed' => 0];
        // run id => project id of every run stopped: one per project at most,
        // the uq_project_active key.
        $stopped = [];
        if ($this->stopEvery(false, $stopped, $out)) $this->stopEvery(true, $stopped, $out);
        $this->finishEach($stopped, $until, $out);
        try {
            $out['slots_released'] = $this->slots->releaseAll();
        } catch (\Throwable $e) {
            $this->failed('slots', null, $e);
            $out['failed']++;
        }
        return $out;
    }

    /**
     * Stop one project's active run because the module was disabled for that
     * project (review rv10a2-7), deleting nothing.
     *
     * A project the module is disabled for gets no answer from it, so its
     * run's worker stops at its next request and the run kept the project's
     * slot until the reaper expired it, staleHours later, as abandoned, which
     * it was not. disable()'s steps for that one run: stopped with
     * PROJECT_DISABLED_WHY on the row and the cancel audited as the system's,
     * the slots held for it freed, then finished as cancelled with that
     * sentence. uq_project_active allows one active run a project, so this
     * is a handful of statements and needs no budget.
     *
     * @return array{runs_stopped:int, slots_released:int, failed:int}
     */
    public function disableProject($pid)
    {
        $this->what = 'disable-project';
        $out = ['runs_stopped' => 0, 'slots_released' => 0, 'failed' => 0];
        $pid = (int) $pid;
        try {
            $rows = $this->db->select('SELECT run_id FROM ' . Schema::table('scan_run')
                . ' WHERE project_id = ? AND active_slot = 1', [$pid]);
        } catch (\Throwable $e) {
            $this->failed('runs', $pid, $e);
            $out['failed']++;
            return $out;
        }
        $stopped = [];
        foreach ($rows as $r) {
            try {
                $this->stopPage([(int) $r[0]], self::PROJECT_DISABLED_WHY);
                $stopped[(int) $r[0]] = $pid;
                $out['slots_released'] += $this->slots->releaseRun((int) $r[0]);
            } catch (\Throwable $e) {
                $this->failed('stop', $pid, $e);
                $out['failed']++;
            }
        }
        $left = ['runs_left' => 0, 'runs_stopped' => 0, 'failed' => 0];
        $this->finishEach($stopped, INF, $left, self::PROJECT_DISABLED_WHY);
        $out['runs_stopped'] = $left['runs_stopped'];
        $out['failed'] += $left['failed'];
        return $out;
    }

    /**
     * One walk over the active runs for disable(): every one, or with $late
     * only those not stopped yet. Each page it stopped is added to $stopped.
     *
     * @return bool false when a page could not be read
     */
    private function stopEvery($late, array &$stopped, array &$out)
    {
        $after = 0;
        while (true) {
            try {
                $runs = $this->db->select('SELECT run_id, project_id FROM ' . Schema::table('scan_run')
                    . ' WHERE active_slot = 1' . ($late ? ' AND phase <> ?' : '') . ' AND run_id > ?'
                    . ' ORDER BY run_id LIMIT ' . self::DISABLE_PAGE,
                    $late ? [ScanPhase::CANCELLING, $after] : [$after]);
            } catch (\Throwable $e) {
                $this->failed('runs', null, $e);
                $out['failed']++;
                return false;
            }
            $page = [];
            foreach ($runs as $r) $page[(int) $r[0]] = (int) $r[1];
            if ($page) {
                $after = max(array_keys($page));
                try {
                    $this->stopPage(array_keys($page), self::DISABLED_WHY);
                    if ($late) $out['late'] += count($page);
                    $stopped += $page;
                } catch (\Throwable $e) {
                    // Nothing of this page was stopped: the two statements are
                    // one transaction, and none of it is finished. The next
                    // disable, or each run's own staleness, comes back to them.
                    $this->failed('stop', null, $e);
                    $out['failed']++;
                }
            }
            if (count($runs) < self::DISABLE_PAGE) return true;
        }
    }

    /**
     * Stop a page of runs in two statements: the cancel and its audit.
     *
     * SqlScanStore::cancel()'s write, for many runs at once: the request time,
     * the phase, the epoch moved - which refuses every worker at its next
     * fence - and `system` as the actor in the audit. Two more things: a run
     * somebody had already stopped is left as they stopped it, and the row
     * carries $why (DISABLED_WHY, or PROJECT_DISABLED_WHY), which
     * ScanPromotion::finishCancelled() keeps whichever request finishes the
     * run. The audit names exactly the rows this UPDATE moved: they are
     * locked by it until the commit, and no other writer sets this reason.
     */
    private function stopPage(array $runIds, $why)
    {
        $in = implode(', ', array_fill(0, count($runIds), '?'));
        $at = gmdate('Y-m-d H:i:s');
        $run = Schema::table('scan_run');
        $this->db->begin();
        try {
            $this->db->exec('UPDATE ' . $run . ' SET cancel_requested_at = ?, phase = ?, terminal_reason = ?,'
                . ' lease_epoch = lease_epoch + 1, updated_at = ?'
                . ' WHERE active_slot = 1 AND phase <> ? AND run_id IN (' . $in . ')',
                array_merge([$at, ScanPhase::CANCELLING, (string) $why, $at, ScanPhase::CANCELLING], $runIds));
            if ((int) $this->db->affected() > 0) {
                $this->db->exec('INSERT INTO ' . Schema::table('scan_audit')
                    . ' (project_id, run_id, event, actor, detail, created_at)'
                    . ' SELECT project_id, run_id, ?, ?, NULL, ? FROM ' . $run
                    . ' WHERE active_slot = 1 AND phase = ? AND terminal_reason = ? AND cancel_requested_at = ?'
                    . ' AND run_id IN (' . $in . ')',
                    array_merge(['cancel', 'system', $at, ScanPhase::CANCELLING, (string) $why, $at], $runIds));
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            try { $this->db->rollback(); } catch (\Throwable $ignored) { }
            throw $e;
        }
    }

    /** Finish the stopped runs, with $why, while the budget lasts; count the rest as left. */
    private function finishEach(array $stopped, $until, array &$out, $why = self::DISABLED_WHY)
    {
        foreach ($stopped as $runId => $pid) {
            if ((float) call_user_func($this->clock) >= $until) {
                $out['runs_left']++;
                continue;
            }
            try {
                $policy = call_user_func($this->policy, $pid);
                if (ScanPromotion::finishCancelled($this->store, $pid, $runId, [
                        'maxFindings' => $policy['maxFindings'], 'maxBytes' => $policy['maxBytes'],
                        'why' => (string) $why])) {
                    $out['runs_stopped']++;
                }
            } catch (\Throwable $e) {
                $this->failed('stop', $pid, $e);
                $out['failed']++;
            }
        }
    }

    /**
     * Drop every table the module created. Only when the installation opted
     * in (scan-system-drop-tables-on-disable, off by default); the caller
     * decides that. The version table goes last, so a drop that fails part
     * way leaves a version row, a health check that names the missing tables,
     * and an enable that recreates them.
     *
     * @return int tables dropped
     */
    public function dropTables()
    {
        $last = Schema::table('schema_version');
        $names = array_values(array_diff(Schema::tables(), [$last]));
        $names[] = $last;
        $n = 0;
        foreach ($names as $t) {
            $this->db->execNoCount('DROP TABLE IF EXISTS ' . $t);
            $n++;
        }
        return $n;
    }

    // -- the pieces ----------------------------------------------------------

    /** @var float when the pass in hand began, by the budget's clock */
    private $started = 0.0;

    /** @var ?array the installation's policy, read once per pass */
    private $sys = null;

    /**
     * @var bool a statement of this pass found a table missing
     *      (DbError::isMissingTable()): the tables went while it ran, and it
     *      stops at its next unit (review rv10a2-4)
     */
    private $gone = false;

    private function begin($what, array $state)
    {
        $this->sys = null;
        $this->gone = false;
        $this->what = $what;
        $this->state = $state;
        $this->started = (float) call_user_func($this->clock);
    }

    private function end(array $out)
    {
        return ['state' => $this->state, 'out' => $out];
    }

    private function cursor()
    {
        return isset($this->state['after']) ? max(0, (int) $this->state['after']) : 0;
    }

    private function checkpoint()
    {
        call_user_func($this->save, $this->state);
    }

    /** Out of budget, switched off, or its tables gone: any stops the pass here. */
    private function halted($until, array &$out)
    {
        if ($this->gone || call_user_func($this->stopped)) {
            $out['stopped'] = 1;
            return true;
        }
        if ((float) call_user_func($this->clock) >= $until) {
            $out['more'] = 1;
            return true;
        }
        return false;
    }

    /**
     * Walk the projects with scan data from $after, a census page at a time,
     * and hand each to $each until the budget is spent.
     *
     * @return int the cursor for the next pass: 0 when the lap reached the
     *             last project, else the last project finished (one below a
     *             project the budget stopped inside, so it is resumed)
     */
    private function lap($after, $until, array &$out, callable $each)
    {
        while (true) {
            if ($this->halted($until, $out)) return $after;
            try {
                $rows = $this->census($after);
            } catch (\Throwable $e) {
                $this->failed('census', null, $e);
                $out['failed']++;
                return $after;
            }
            foreach ($rows as $row) {
                if ($this->halted($until, $out)) return $after;
                try {
                    $finished = $each($row);
                } catch (\Throwable $e) {
                    // Logged; the next lap comes back to it. With the tables
                    // gone, the project is not passed over: the pass stops
                    // before it, and nothing after it is tried.
                    $this->failed('project', $row['pid'], $e);
                    $out['failed']++;
                    if ($this->gone) {
                        $out['stopped'] = 1;
                        return $after;
                    }
                    $finished = true;
                }
                if (!$finished) return $row['pid'] - 1;
                $after = $row['pid'];
            }
            if (count($rows) < self::CENSUS_PAGE) return 0;
        }
    }

    /**
     * One page of projects with scan data, and what is due in each.
     *
     * GROUPED BY THE PROJECT THROUGH ITS OWN KEYS. uq_project_active and
     * ix_project_created both lead with project_id, so the groups come out in
     * project order and the LIMIT stops after CENSUS_PAGE of them; every row
     * read is one run of one of those projects. uv_scan_run holds a row per
     * run, and retention keeps that number bounded per project.
     *
     * @return array<int, array{pid:int, stale:bool, stopped:bool, supersede:bool, oldest:?string,
     *                          modes:string[]}>
     */
    private function census($after)
    {
        // The store's predicate, clock and all (review rv10a1-1), so the
        // census names exactly the projects expireAbandoned() will reap.
        // A stale run somebody stopped is 2, finished as cancelled; any
        // other stale run is 1, expired (review rv10a1-2). uq_project_active
        // allows one active run a project, so MAX() sees at most one of them.
        $stale = SqlScanStore::staleWhere($this->staleCut(), self::at($this->now));
        $rows = $this->db->select('SELECT project_id,'
            . ' MAX(CASE WHEN ' . $stale[0] . ' THEN (CASE WHEN phase = ? THEN 2 ELSE 1 END) ELSE 0 END),'
            . ' MAX(CASE WHEN terminal = ? AND scope_dag IS NULL AND supersede_cursor < generation_id'
            . '   THEN 1 ELSE 0 END),'
            . ' MIN(CASE WHEN active_slot IS NULL THEN updated_at END),'
            . ' GROUP_CONCAT(DISTINCT values_state)'
            . ' FROM ' . Schema::table('scan_run') . ' WHERE project_id > ?'
            . ' GROUP BY project_id ORDER BY project_id LIMIT ' . self::CENSUS_PAGE,
            array_merge($stale[1], [ScanPhase::CANCELLING, ScanOutcome::COMPLETE, (int) $after]));
        $out = [];
        foreach ($rows as $r) {
            if ((int) $r[0] < 1) continue;   // the version-1 leftover: no project to act for
            $out[] = ['pid' => (int) $r[0], 'stale' => (int) $r[1] === 1, 'stopped' => (int) $r[1] === 2,
                      'supersede' => (int) $r[2] === 1,
                      'oldest' => $r[3] === null ? null : (string) $r[3],
                      'modes' => $r[4] === null || $r[4] === '' ? [] : explode(',', (string) $r[4])];
        }
        return $out;
    }

    /** The census's stale cut: the installation's window, measured back from now. */
    private function staleCut()
    {
        if ($this->sys === null) $this->sys = call_user_func($this->policy, null);
        return self::at($this->now - max(1, (int) $this->sys['staleHours']) * 3600);
    }

    /** The modes in $modes that keep a value preview, by ScanPolicy's ranking. */
    private static function keptModes(array $modes)
    {
        $out = [];
        foreach ($modes as $m) {
            if (ScanPolicy::rank($m) > ScanPolicy::rank(ScanPolicy::D_VALUE_MODE)) $out[] = (string) $m;
        }
        return $out;
    }

    private function failed($step, $pid, \Throwable $e)
    {
        if (DbError::isMissingTable($e)) $this->gone = true;
        call_user_func($this->log, 'scan-cron-step-failed', [
            'pass' => $this->what, 'step' => (string) $step, 'project_id' => $pid === null ? null : (int) $pid,
            // The class and what DbError keeps of the server's text, which is
            // the module's one redaction point; never the raw message.
            'error' => get_class($e),
            'detail' => $e instanceof ScanStoreUnavailable ? $e->safeDetail() : DbError::safe($e),
            'retry' => 'next pass']);
    }

    private static function at($unix)
    {
        return gmdate('Y-m-d H:i:s', (int) $unix);
    }
}
