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
     * @return array{done:bool, rows:int, why:?string}
     */
    public function step($runId, $epoch, $generationId, $limit = 1000)
    {
        $limit = max(1, min(10000, (int) $limit));
        $st = $this->store->progressState($runId);
        if ($st === null) {
            return ['done' => true, 'rows' => 0,
                    'why' => 'this run could not be read, so no summary was built'];
        }
        $after = (int) $st['rollupCursor'];

        // Only ACTIVE findings. A closed row belongs to an earlier version of a
        // record and is kept so an "as of run N" view stays reproducible; adding
        // it to today's summary would count the same problem twice.
        $rows = $this->db->select('SELECT finding_id, host_form, reason_code, dag_key, check_type
            FROM ' . Schema::table('finding') . '
            WHERE generation_id = ? AND active_slot = 1 AND finding_id > ?
            ORDER BY finding_id LIMIT ' . $limit, [$generationId, $after]);

        if (!$rows) {
            return ['done' => true, 'rows' => 0, 'why' => null];
        }

        $tally = [];
        $last = $after;
        foreach ($rows as $r) {
            $last = (int) $r[0];
            foreach (self::axes($r) as $kind => $axis) {
                $k = $kind . "\0" . (string) $axis;
                if (!isset($tally[$k])) $tally[$k] = ['kind' => $kind, 'axis' => $axis, 'n' => 0];
                $tally[$k]['n']++;
            }
        }

        // ONE TRANSACTION, counters and cursor together. See the class note: a
        // crash between them inflates the summary against the findings it
        // describes, and nothing downstream can detect that.
        $this->db->begin();
        try {
            foreach ($tally as $t) {
                $this->store->addAggregate($runId, $t['kind'], $t['axis'], null, $t['n']);
            }
            $ok = $this->store->setProgressState($runId, $epoch, ['rollupCursor' => $last]);
            if (!$ok) {
                // The lease moved: this worker no longer owns the run, and the
                // counts it just added would be added again by whoever does.
                $this->db->rollback();
                return ['done' => false, 'rows' => 0,
                        'why' => 'another worker took over this scan, so the summary page was '
                               . 'discarded rather than counted twice'];
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
