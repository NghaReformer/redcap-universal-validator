<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * Proof that a record did not move while we were reading it, and a list of the
 * ones that did.
 *
 * WHAT A FENCE IS FOR. A scan that reads 100,000 records over twenty minutes is
 * reading a project people are still using. Without a fence the run can say "I
 * examined every record on the list I started with" and nothing more; with one
 * it can say "and the project did not change underneath me". Those are different
 * claims, they are reported as different coverage values, and the difference is
 * the whole reason this class exists.
 *
 * THE FENCE IS `log_event_id`, NOT A TIMESTAMP. REDCap's log carries `ts` at
 * one-second resolution, and a second is long enough for several saves; two
 * edits within it are indistinguishable, and a clock that steps backwards makes
 * the ordering a lie. `log_event_id` is a monotonic auto-increment, which is the
 * one property a fence needs.
 *
 * THE EVENT TAXONOMY IS DELIBERATELY IGNORED. Any log row carrying a record
 * identifier counts as a change to that record - no filter on `event`, none on
 * `object_type`. The two failure directions are not symmetric: being over-
 * inclusive costs a needless re-read of a record, and being under-inclusive
 * means a record that changed is presented as covered. This module exists to
 * prevent the second one. If a future measurement shows a taxonomy that can be
 * trusted, narrowing is an optimisation; until then the permissive form is the
 * correct one and the cost is bounded by how many records were touched.
 *
 * DAG MOVEMENT IS NOT PROVED FROM THE LOG, and that is a deliberate divergence
 * in mechanism from the rebuild plan's wording while meeting its requirement by
 * a stronger route. Whether REDCap writes a log row when a record is reassigned
 * to another group is an assumption about a taxonomy this file has just refused
 * to trust. So catch-up re-walks the record source at the target fence and reads
 * each record's group from the SOURCE. That costs one more bounded keyset walk
 * of ids - no record data - and it proves membership rather than inferring it.
 * See RecordManifestSource.
 *
 * PHP 7.4.
 */
final class SourceFence
{
    /** @var ScanDb */
    private $db;
    private $pid;
    /** @var string an allowlisted log table name */
    private $table;

    private function __construct(ScanDb $db, $pid, $table)
    {
        $this->db = $db;
        $this->pid = $pid;
        $this->table = $table;
    }

    /**
     * A fence for this project, or a refusal that says why.
     *
     * @return array{ok:bool, fence:?SourceFence, why:?string}
     */
    public static function forProject(ScanDb $db, $pid)
    {
        $tbl = self::resolveTable($db, $pid);
        if ($tbl === null) {
            return ['ok' => false, 'fence' => null,
                    'why' => 'this project\'s change log could not be resolved to a table this '
                           . 'module recognises, so no read can be proved stable'];
        }
        $f = new self($db, $pid, $tbl);
        $open = $f->now();
        if ($open === null) {
            return ['ok' => false, 'fence' => null,
                    'why' => 'the change log ' . $tbl . ' holds no ordered entries for this '
                           . 'project, so there is nothing to fence a scan against'];
        }
        return ['ok' => true, 'fence' => $f, 'why' => null];
    }

    /**
     * The project's log table, server-derived and allowlisted.
     *
     * A TABLE NAME CAN NEVER BE A BOUND PARAMETER, so this pattern is the only
     * thing standing between `redcap_projects` and an interpolated identifier.
     * Two details that look like fussiness and are not:
     *
     *   The value is NOT trimmed. A name arriving with surrounding whitespace is
     *   anomalous and should be refused, not tidied - and trimming would make
     *   the anchor below untestable, because with trim() in front of it '$' and
     *   '\z' accept exactly the same set.
     *
     *   The anchor is `\z`, not `$`. PHP's `$` also matches immediately before a
     *   trailing newline, so with `$` a value ending in one would be accepted.
     */
    public static function resolveTable(ScanDb $db, $pid)
    {
        try {
            $r = $db->select('SELECT log_event_table FROM redcap_projects WHERE project_id = ?', [$pid]);
            $tbl = isset($r[0][0]) ? (string) $r[0][0] : '';
            if ($tbl === '' || !preg_match('/^redcap_log_event[0-9]*\z/', $tbl)) return null;
            return $tbl;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Which table this fence reads. For diagnostics and for the run's record of itself. */
    public function table()
    {
        return $this->table;
    }

    /**
     * The current top of the log: the opening or target fence.
     *
     * Returned as a decimal STRING, not an int. A 32-bit PHP build silently
     * truncates a bigint past 2^31, and the value is compared, stored and sent
     * to the client - three chances for a truncated fence to be treated as a
     * real one.
     */
    public function now()
    {
        try {
            $r = $this->db->select('SELECT MAX(log_event_id) FROM ' . $this->table
                . ' WHERE project_id = ?', [$this->pid]);
            $v = isset($r[0][0]) ? $r[0][0] : null;
            if ($v === null || $v === '' || !ctype_digit((string) $v)) return null;
            return (string) $v;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Each record's current source version, keyed by record id.
     *
     * A record with no log history answers null, which is a version like any
     * other: null before and null after means nothing happened, which is exactly
     * what it means.
     *
     * CAST(pk AS BINARY) rather than a bare GROUP BY, because the log table's
     * collation may be case- and accent-insensitive. Grouped by the collated
     * value, records "abc" and "ABC" would share one version, and an edit to
     * either would report BOTH as unstable. That direction is safe but wasteful,
     * and the cast removes it entirely. Selecting the cast expression under its
     * own alias also keeps the statement legal under ONLY_FULL_GROUP_BY.
     *
     * @param string[] $recordIds
     * @return array<string,?string>
     */
    public function versions(array $recordIds)
    {
        $out = [];
        foreach ($recordIds as $r) $out[(string) $r] = null;
        if (!$recordIds) return $out;

        $marks = implode(',', array_fill(0, count($recordIds), '?'));
        $params = array_merge([$this->pid], array_map('strval', $recordIds));
        $rows = $this->db->select('SELECT CAST(pk AS BINARY) AS p, MAX(log_event_id) FROM '
            . $this->table . ' WHERE project_id = ? AND pk IN (' . $marks . ') GROUP BY p', $params);
        foreach ($rows as $row) {
            $id = (string) $row[0];
            // The IN list matched by collation, so a row can come back for a
            // record we did not ask about. Only keys we asked for are kept.
            if (!array_key_exists($id, $out)) continue;
            $out[$id] = ($row[1] === null || $row[1] === '') ? null : (string) $row[1];
        }
        return $out;
    }

    /**
     * Can the interval from $open onwards still be enumerated?
     *
     * Some installations prune their log. If entries at or below the opening
     * fence are gone, entries just above it may be gone too, and a catch-up pass
     * would report "nothing changed" about a window it simply cannot see. That
     * is the failure this module exists to prevent, so it is answered before the
     * claim is made rather than after.
     *
     * The test is stricter than strictly necessary - it requires the opening row
     * itself to have survived - and that is the safe direction: it fails toward
     * `manifest-complete`, never toward a fence that cannot be backed up.
     *
     * @return array{ok:bool, why:?string}
     */
    public function retained($open)
    {
        if (!is_string($open) || !ctype_digit($open)) {
            return ['ok' => false, 'why' => 'the opening fence was not recorded, so nothing can be '
                                          . 'proved about the interval since'];
        }
        try {
            $r = $this->db->select('SELECT MIN(log_event_id) FROM ' . $this->table
                . ' WHERE project_id = ?', [$this->pid]);
            $min = isset($r[0][0]) ? $r[0][0] : null;
            if ($min === null || !ctype_digit((string) $min)) {
                return ['ok' => false, 'why' => 'the change log holds no entries for this project '
                                              . 'any more, so the scan interval cannot be read back'];
            }
            // String compare would order "9" after "10". bccomp is not
            // guaranteed present, so compare by length first and then
            // lexically - correct for arbitrary-length decimal integers with no
            // extension and no float rounding.
            if (self::decCmp((string) $min, $open) > 0) {
                return ['ok' => false,
                        'why' => 'part of the change log covering this scan has been removed since '
                               . 'the run opened, so changes during the run cannot be enumerated'];
            }
            return ['ok' => true, 'why' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'why' => 'the change log could not be read back (' . get_class($e)
                                          . '), so the scan interval cannot be proved covered'];
        }
    }

    /**
     * One keyset page of records changed in ($after, $upTo].
     *
     * Keyset, never OFFSET: the window is walked by record id, so a page cannot
     * shift under an insert and the cost does not grow with how far in we are.
     *
     * @param string      $after   exclusive lower fence
     * @param string      $upTo    inclusive upper fence
     * @param string|null $afterId page cursor: the last record id already seen
     * @return array<int,array{id:string, version:string}>
     */
    public function changedSince($after, $upTo, $afterId, $limit)
    {
        $limit = max(1, min(5000, (int) $limit));
        if (!ctype_digit((string) $after) || !ctype_digit((string) $upTo)) return [];

        $sql = 'SELECT CAST(pk AS BINARY) AS p, MAX(log_event_id) FROM ' . $this->table . '
                WHERE project_id = ? AND log_event_id > ? AND log_event_id <= ?
                  AND pk IS NOT NULL AND pk <> \'\'';
        $params = [$this->pid, (string) $after, (string) $upTo];
        if ($afterId !== null) {
            // Compared as bytes, matching the ordering below. A collated
            // comparison here would skip a record whose id differs from the
            // cursor only by case.
            $sql .= ' AND CAST(pk AS BINARY) > ?';
            $params[] = (string) $afterId;
        }
        $sql .= ' GROUP BY p ORDER BY p LIMIT ' . $limit;

        $out = [];
        foreach ($this->db->select($sql, $params) as $row) {
            $out[] = ['id' => (string) $row[0],
                      'version' => ($row[1] === null ? null : (string) $row[1])];
        }
        return $out;
    }

    /**
     * Compare two arbitrary-length decimal integers.
     *
     * Not (int) - a log id can exceed 2^31 on a 32-bit build and would wrap.
     * Not (float) - past 2^53 two different ids compare equal. Not strcmp -
     * "9" sorts after "10". Length, then bytes, which is exact for every length.
     */
    public static function decCmp($a, $b)
    {
        $a = ltrim((string) $a, '0');
        $b = ltrim((string) $b, '0');
        if ($a === '') $a = '0';
        if ($b === '') $b = '0';
        if (strlen($a) !== strlen($b)) return strlen($a) < strlen($b) ? -1 : 1;
        return strcmp($a, $b);
    }
}
