<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * The summary, built once at the end rather than computed per request.
 *
 * WHY NOT `GROUP BY` AT READ TIME. Because the read is a page. A report over
 * 4.9 million findings that recomputed "how many per instrument" every time
 * someone opened it would run a full aggregate on every page fetch, and the
 * summary is the FIRST thing rendered - so the slowest query on the page would
 * also be the one blocking it. Computed once, it is a handful of rows read by
 * primary key.
 *
 * THE COUNTERS ADD, WHICH MAKES THE CURSOR PART OF THE WRITE. Each page reads
 * some findings and adds their counts, so a page applied twice counts twice. The
 * page's aggregate writes and the cursor that says the page is finished go into
 * ONE transaction: a crash between them would otherwise inflate the summary
 * relative to the findings it summarises, and an inflated summary is a report
 * that overstates a project's problems with nothing to point at.
 *
 * AXES COME FROM THE FINDING, NOT FROM A LIST HERE. Instrument, reason, group
 * and check type are the four the finding row carries as filter keys, which is
 * the same criterion that decided they were columns rather than JSON. A future
 * axis is a column plus one line in axes(), never a new mechanism.
 *
 * BOUNDED BY KEYSET, never OFFSET: the summary of a multi-million-row run is
 * built in pages whose cost does not grow with how far in they are, and a page
 * cannot shift under a concurrent insert.
 *
 * PHP 7.4.
 */
final class RollupBuilder
{
    /** Aggregate kinds this class writes. One per axis. */
    const K_FORM   = 'rollup-instrument';
    const K_REASON = 'rollup-reason';
    const K_DAG    = 'rollup-group';
    const K_TYPE   = 'rollup-check';
    /**
     * Every axis, as a list. Each active finding is counted ONCE ON EACH, so
     * the sum over any one kind is the number of active findings - which is
     * what ScanPromotion::tally() reads. A new axis goes here and in axes().
     */
    const KINDS = [self::K_FORM, self::K_REASON, self::K_DAG, self::K_TYPE];

    /** @var ScanDb */
    private $db;
    /** @var ScanStore */
    private $store;

    public function __construct(ScanDb $db, ScanStore $store)
    {
        $this->db = $db;
        $this->store = $store;
    }

    /**
     * One bounded page of summarising.
     *
     * $byGroup: A GROUP RUN'S COUNTS ARE KEPT PER GROUP (review rv11a1-4).
     * The instrument, reason and check axes of a group-scoped run carry each
     * finding's group in axis2, so its reader's filters can count only rows
     * filed under their group - which their pages hold to. A duplicate the
     * finalizer emits after a record moved carries the group it moved to,
     * and those axes counted it for a reader whose pages never show it. The
     * group axis needs no split (its axis1 is the group). Only a group run is
     * split: no group reader may read a whole-project run, and splitting one
     * would multiply its summary by the project's group count for nobody.
     *
     * @return array{done:bool, rows:int, why:?string}
     */
    public function step($projectId, $runId, $epoch, $generationId, $limit = 1000, $byGroup = false)
    {
        $limit = max(1, min(10000, (int) $limit));
        $st = $this->store->progressState($runId);
        if ($st === null) {
            return ['done' => true, 'rows' => 0, 'why' => ScanStore::ROLLUP_NO_RUN];
        }
        $after = (int) $st['rollupCursor'];

        // Only ACTIVE findings. A closed row belongs to an earlier version of a
        // record and is kept so an "as of run N" view stays reproducible; adding
        // it to today's summary would count the same problem twice.
        $rows = $this->db->select('SELECT finding_id, host_form, reason_code, dag_key, check_type
            FROM ' . Schema::table('finding') . '
            WHERE project_id = ? AND generation_id = ? AND active_slot = 1 AND finding_id > ?
            ORDER BY finding_id LIMIT ' . $limit, [$projectId, $generationId, $after]);

        if (!$rows) {
            return ['done' => true, 'rows' => 0, 'why' => null];
        }

        $tally = [];
        $last = $after;
        foreach ($rows as $r) {
            $last = (int) $r[0];
            $group = $byGroup ? ($r[3] === null ? '' : (string) $r[3]) : null;
            foreach (self::axes($r) as $kind => $axis) {
                $by = $kind === self::K_DAG ? null : $group;
                $k = $kind . "\0" . (string) $axis . "\0" . (string) $by;
                if (!isset($tally[$k])) $tally[$k] = ['kind' => $kind, 'axis' => $axis, 'by' => $by, 'n' => 0];
                $tally[$k]['n']++;
            }
        }

        // ONE TRANSACTION, counters and cursor together. See the class note: a
        // crash between them inflates the summary against the findings it
        // describes, and nothing downstream can detect that.
        $this->db->begin();
        try {
            // HELD FIRST, AND THE CURSOR READ AGAIN UNDER THE HOLD. The epoch
            // alone fenced nothing between two workers of one run: they share
            // it, both read the cursor above, both read the same page, and
            // both added it. Now the second waits on the run row, then finds
            // the cursor the first moved and drops its copy of the page. The
            // run row is also taken before the aggregate rows, the order
            // commitBatch() takes them in.
            $held = $this->store->holdRollupCursor($runId, $epoch);
            if (!is_int($held)) {
                // The lease moved, or the run ended while this page was read.
                $this->db->rollback();
                return ['done' => false, 'rows' => 0, 'why' => (string) $held];
            }
            if ($held !== $after) {
                // Another worker of this run counted the page first. Not a
                // reason to stop: the next step reads from where it got to.
                $this->db->rollback();
                return ['done' => false, 'rows' => 0, 'why' => null];
            }
            foreach ($tally as $t) {
                $this->store->addAggregate($runId, $t['kind'], $t['axis'], $t['by'], $t['n']);
            }
            // AND THE PROGRESS CLOCK, in the same fenced write (W7-N9). A page
            // summarised is the run moving forward; without it a long rollup
            // read as stalled to the page and as abandoned to the reaper.
            $ok = $this->store->setProgressState($runId, $epoch,
                                                 ['rollupCursor' => $last, 'progress' => true]);
            if (!$ok) {
                // The lease moved: this worker no longer owns the run, and the
                // counts it just added would be added again by whoever does.
                $this->db->rollback();
                return ['done' => false, 'rows' => 0, 'why' => ScanStore::ROLLUP_TAKEN_OVER];
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        return ['done' => false, 'rows' => count($rows), 'why' => null];
    }

    /**
     * The axes one finding contributes to.
     *
     * A null axis is stored as the empty string rather than skipped: "findings
     * with no Data Access Group" is an answer, and dropping it would make the
     * axis totals quietly disagree with the finding count.
     */
    private static function axes(array $r)
    {
        return [
            self::K_FORM   => (string) $r[1],
            self::K_REASON => (string) $r[2],
            self::K_DAG    => ($r[3] === null ? '' : (string) $r[3]),
            self::K_TYPE   => (string) $r[4],
        ];
    }
}
