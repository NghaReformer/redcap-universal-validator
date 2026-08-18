<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * What the project did while the scan was reading it.
 *
 * THE PROBLEM THIS EXISTS FOR. Planning freezes a list of record ids so that
 * ordinals mean something and progress is a cursor rather than a re-derivation.
 * The moment that list is frozen it starts going out of date: someone creates a
 * record, deletes one, moves one between groups, or edits one the scan passed an
 * hour ago. A run that finished its frozen list and called that "complete" would
 * be certifying a project it had provably not seen — which is C3 in the review,
 * and the review's point was that FULL runs acquire that defect the instant the
 * list is frozen, not just incremental ones.
 *
 * SO A FINISHED MANIFEST IS NOT A FINISHED SCAN. Between the two sits this: a
 * fixed window, walked to the end, with everything it turns up brought back into
 * the run.
 *
 *   opening fence          the log position when planning began
 *   target fence           the log position when catch-up began
 *   the window             (opening, target]
 *
 * The window is FIXED once, and that is what makes this terminate. Records
 * changed after the target fence are not covered, and the run says so by
 * claiming coverage "through" that fence rather than coverage of a moment that
 * has already passed. A window that moved every round would be a phase chasing a
 * project people are still using, and on a busy project it would never end.
 *
 * FOUR THINGS A CHANGED RECORD CAN BE, and they are not the same:
 *
 *   NEW      not on the manifest -> added, if it is in scope. A DAG-scoped run
 *            must not quietly widen itself, so scope is asked, never assumed.
 *   EDITED   on the manifest, changed after we scanned it -> requeued.
 *   GONE     on the manifest, no longer in the project -> tombstoned. Not
 *            requeued: a deleted record can never be read, so requeueing it
 *            would hold the run open forever (C3's mirror case).
 *   SETTLED  changed within the window but before we scanned it, so the reading
 *            already includes it -> nothing to do. This is the case that makes
 *            the second round cheap and is why the pass converges.
 *
 * WHY ROUNDS AT ALL. The reconciler and the worker take turns: a round requeues
 * records, the worker scans them, and a second round over the SAME window
 * confirms nothing is left. Two rounds is the normal number, because a record
 * re-scanned after its windowed change now has a scanned version at or beyond
 * it. The round cap is a backstop against a case nobody has thought of, and it
 * fails toward a reported exclusion rather than toward a loop.
 *
 * WHEN THE LOG CANNOT ANSWER. If the change log no longer covers the opening
 * fence, there is no window to walk and no honest way to enumerate what moved.
 * That is not a failure — the run's records were still examined — but it is not
 * a fence either, so the run keeps `manifest-complete` and states that it cannot
 * prove the project held still. Guessing in the other direction is the whole
 * failure mode this module exists to prevent.
 *
 * PHP 7.4.
 */
final class CatchUp
{
    /**
     * How many times the window may be walked before the run gives up on it.
     *
     * Two is the expected number. Anything past that means records are changing
     * inside a fixed window faster than they can be re-scanned, which should be
     * impossible - so the cap is a backstop, and exceeding it is REPORTED as a
     * blocking exclusion rather than retried.
     */
    const MAX_ROUNDS = 4;

    /** Aggregate kinds this phase can write. */
    const K_ADDED   = 'reconciled-added';
    const K_EDITED  = 'reconciled-edited';
    const K_GONE    = 'reconciled-deleted';
    const K_NOFENCE = 'fence-unprovable';
    const K_CHURN   = 'reconcile-unsettled';

    /** @var ScanStore */
    private $store;
    /** @var array */
    private $deps;

    /**
     * @param array $deps {
     *   fence:  ?ChangeLog     the change log. Null means no window can be walked.
     *   exists: ?callable(string[] $ids): array<string,bool>  does the project still hold it
     *   scope:  ?callable(string[] $ids): array<string,bool>  is it inside this run's scope
     *   hash:   callable(string $id): string                  the manifest's record hash
     *   dag:    ?callable(string[] $ids): array<string,?string>
     * }
     */
    public function __construct(ScanStore $store, array $deps = [])
    {
        $this->store = $store;
        $this->deps = $deps;
    }

    /**
     * One bounded page of reconciliation.
     *
     * @return array{done:bool, fenced:bool, added:int, requeued:int, gone:int,
     *               scanned:int, why:?string}
     */
    public function step($pid, $runId, $epoch, $limit = 500)
    {
        $limit = max(1, min(5000, (int) $limit));
        $out = ['done' => false, 'fenced' => false, 'added' => 0, 'requeued' => 0,
                'gone' => 0, 'scanned' => 0, 'why' => null];

        $st = $this->store->progressState($runId);
        if ($st === null) {
            $out['done'] = true;
            $out['why'] = 'this run could not be read, so nothing was reconciled';
            return $out;
        }

        $fence = isset($this->deps['fence']) ? $this->deps['fence'] : null;
        if (!($fence instanceof ChangeLog)) {
            // No change log means no window. The run keeps what it earned:
            // every record on its list was examined, and it does not claim the
            // project stood still.
            return $this->unfenced($runId, $out,
                'this server cannot enumerate changes made during a scan, so the run covers the '
                . 'records it opened with rather than the project as it is now');
        }

        // -- capture the target fence, once -----------------------------------
        if ($st['fenceTarget'] === null || $st['fenceTarget'] === '') {
            $keeps = $fence->retained($st['fenceOpen']);
            if (empty($keeps['ok'])) {
                return $this->unfenced($runId, $out, $keeps['why']);
            }
            $target = $fence->now();
            if ($target === null) {
                return $this->unfenced($runId, $out,
                    'the change log could not be read at the end of the scan, so changes during '
                    . 'the run cannot be enumerated');
            }
            $this->store->setProgressState($runId, $epoch,
                ['fenceTarget' => $target, 'catchupCursor' => null,
                 'catchupRound' => 1, 'catchupDirty' => 0]);
            $st['fenceTarget'] = $target;
            $st['catchupCursor'] = null;
            $st['catchupRound'] = 1;
            $st['catchupDirty'] = 0;
        }
        $out['fenced'] = true;

        // -- walk one page of the window --------------------------------------
        $page = $fence->changedSince($st['fenceOpen'], $st['fenceTarget'],
                                     $st['catchupCursor'], $limit);
        if (!$page) {
            return $this->endOfRound($runId, $epoch, $st, $out);
        }

        $ids = [];
        $version = [];
        foreach ($page as $row) {
            $ids[] = $row['id'];
            $version[$row['id']] = $row['version'];
        }

        $known  = $this->store->scannedVersions($runId, $ids);
        $exists = $this->ask('exists', $ids, true);
        $scope  = $this->ask('scope', $ids, true);
        $dags   = $this->ask('dag', $ids, null);

        $add = [];
        $requeue = [];
        $gone = [];
        foreach ($ids as $id) {
            $onManifest = isset($known[$id]);
            $stillThere = !empty($exists[$id]);

            if (!$onManifest) {
                // Created (or moved into scope) after the manifest was frozen.
                // Scope is asked rather than assumed: a DAG-scoped run that
                // widened itself here would put another group's records into a
                // report its reader is not entitled to.
                if ($stillThere && !empty($scope[$id])) {
                    $add[] = ['id_bin' => $id,
                              'hash' => call_user_func($this->deps['hash'], $id),
                              'dag' => isset($dags[$id]) ? $dags[$id] : null];
                }
                continue;
            }
            if (!$stillThere) {
                if ($known[$id]['state'] !== ScanStore::REC_TOMBSTONE) $gone[] = $id;
                continue;
            }
            // Edited AFTER we read it. A change that landed before the record's
            // scanned version is already inside the reading we have, and
            // requeueing it would be work with no question behind it - this is
            // the branch that makes the confirming round cheap.
            $scanned = $known[$id]['version'];
            if ($scanned === null
                    || SourceFence::decCmp((string) $version[$id], (string) $scanned) > 0) {
                $requeue[] = $id;
            }
        }

        if ($add)     $out['added']    = $this->store->reconcileAdd($runId, $epoch, $add);
        if ($requeue) $out['requeued'] = $this->store->requeue($runId, $epoch, $requeue);
        if ($gone)    $out['gone']     = $this->store->tombstone($runId, $epoch, $gone);

        // Counted, and visible in the report. A run that silently absorbed
        // twenty new records would look identical to one that scanned a project
        // nobody touched, and only one of those deserves the same confidence.
        if ($out['added'])    $this->store->addAggregate($runId, self::K_ADDED, null, null, $out['added']);
        if ($out['requeued']) $this->store->addAggregate($runId, self::K_EDITED, null, null, $out['requeued']);
        if ($out['gone'])     $this->store->addAggregate($runId, self::K_GONE, null, null, $out['gone']);

        $dirty = ($out['added'] || $out['requeued'] || $out['gone']) ? 1 : (int) $st['catchupDirty'];
        $last = $page[count($page) - 1]['id'];
        $this->store->setProgressState($runId, $epoch,
            ['catchupCursor' => $last, 'catchupDirty' => $dirty]);
        $out['scanned'] = count($page);
        return $out;
    }

    /**
     * The window has been walked to its end. Round again, or settle.
     *
     * A round that changed nothing is the proof that everything in the window is
     * at or beyond it. A round that changed something has to be followed by
     * another, because the records it requeued are scanned by the worker AFTER
     * this reconciler saw them.
     */
    private function endOfRound($runId, $epoch, array $st, array $out)
    {
        if (empty($st['catchupDirty'])) {
            $out['done'] = true;
            return $out;
        }
        $round = (int) $st['catchupRound'] + 1;
        if ($round > self::MAX_ROUNDS) {
            // Records are changing inside a FIXED window faster than they can be
            // re-scanned, which should not be possible. Reported as blocking
            // rather than retried: an unexplained loop that ends in a truthful
            // partial beats one that never ends.
            $this->store->addAggregate($runId, self::K_CHURN, null, null, 1, 1);
            $out['done'] = true;
            $out['why'] = 'records kept changing faster than they could be re-examined, so this '
                        . 'scan reports partial coverage';
            return $out;
        }
        $this->store->setProgressState($runId, $epoch,
            ['catchupCursor' => null, 'catchupRound' => $round, 'catchupDirty' => 0]);
        return $out;
    }

    /**
     * There is no window, and the run must say so rather than imply one.
     *
     * Not a failure: every record on the manifest really was examined. It is the
     * difference between "this is the project" and "this is the list we opened
     * with", and the report is required to be able to tell a reader which.
     */
    private function unfenced($runId, array $out, $why)
    {
        $this->store->addAggregate($runId, self::K_NOFENCE, null, null, 1, 0,
            substr((string) $why, 0, 500));
        $out['done'] = true;
        $out['fenced'] = false;
        $out['why'] = $why;
        return $out;
    }

    /** Ask a configured resolver about a page of ids, or assume the default. */
    private function ask($which, array $ids, $default)
    {
        $fn = isset($this->deps[$which]) ? $this->deps[$which] : null;
        if (!is_callable($fn)) {
            return array_fill_keys($ids, $default);
        }
        $got = $fn($ids);
        if (!is_array($got)) return array_fill_keys($ids, $default);
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = array_key_exists($id, $got) ? $got[$id] : $default;
        }
        return $out;
    }
}
