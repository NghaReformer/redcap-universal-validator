<?php

namespace INSPIRE\UniversalValidator\Scan;

require_once __DIR__ . '/../Faults.php';

/**
 * Walking a project's record ids in bounded memory — the hard gate the whole
 * rebuild stands on.
 *
 * WHY THIS IS THE GATE. The legacy scan learned which records exist by exporting
 * them: `REDCap::getData` with every rule field, unchunked, before the chunk loop
 * it was feeding even started. That single call is why a 128 MB installation
 * died somewhere around 2,500 records. If an installation cannot enumerate
 * records without building a whole-project PHP array, there is no bounded scan
 * to write, and the correct answer is to refuse BEFORE a run exists rather than
 * to start one that will die.
 *
 * TWO SOURCES, PREFERRED IN ORDER:
 *
 *   `redcap_record_list` is REDCap's own record index. It carries the record and
 *   its group and no data at all, so a page of it is a few kilobytes whatever
 *   the project holds. Preferred whenever it exists AND answers for this
 *   project AND REDCap reports it complete - a table existing is not the same
 *   as a table being populated, an empty index would enumerate a full project
 *   as empty, and a half-built one would enumerate part of it (see open()).
 *
 *   The project's data table, restricted to the record-id field, is the
 *   fallback. Equally bounded, one row per record, and it needs the record-id
 *   field name to be resolvable.
 *
 * Neither is assumed from a version number. Both are probed, and the columns
 * they are read through are read out of `information_schema` rather than
 * guessed, because REDCap has moved this data more than once and a guess that
 * happens to parse is a guess that fails on someone else's installation.
 *
 * ORDERING, AND THE ONE UNCOMFORTABLE COMPROMISE. The walk must never skip a
 * record and must not grow more expensive the further in it gets, which means
 * keyset paging on the record id. The record column's collation is very often
 * case- and accent-insensitive, so `WHERE record > 'abc'` can skip a record
 * named 'ABC'. Two ways out:
 *
 *   Compare and order on the binary form. Correct, one line, and it throws away
 *   the index - every page becomes a filesort of the whole project, so the walk
 *   turns quadratic and a million-record project never finishes.
 *
 *   Page on the collated value with `>=`, and carry the ids already emitted at
 *   the boundary so they are not emitted twice. Index-friendly, linear, and the
 *   carried set is empty on every project whose record ids do not collide under
 *   its own collation - which is nearly all of them.
 *
 * The second is implemented, with a hard cap: a boundary group larger than the
 * cap is refused with a message naming the problem, rather than paged forever.
 * Duplicate emission is harmless anyway - the manifest's unique key on the
 * record hash collapses it - but a walk that cannot advance is not, so the cap
 * is a refusal and not a warning.
 *
 * PHP 7.4.
 */
final class RecordManifestSource
{
    /** How many equal-by-collation ids may sit on a page boundary before we refuse. */
    const TIE_CAP = 200;

    /** @var ScanDb */
    private $db;
    private $pid;
    private $table;       // allowlisted
    private $recordCol;   // allowlisted
    private $dagCol;      // allowlisted, or null
    private $via;
    private $pkField;     // only for the data-table fallback
    private $walkIndex = null;  // the index the ordered reads name (walkFrom), or none
    private $dataTable;   // for the __GROUPID__ lookup when $dagCol is null

    private function __construct()
    {
    }

    /**
     * Open a bounded walk, or refuse and say why.
     *
     * @param array $opts {pk: ?string record-id field name}
     * @return array{ok:bool, source:?RecordManifestSource, why:?string}
     */
    public static function open(ScanDb $db, $pid, array $opts = [])
    {
        $pk = isset($opts['pk']) && is_string($opts['pk']) && $opts['pk'] !== '' ? $opts['pk'] : null;

        // Preferred: REDCap's record index - when REDCap says it is complete.
        //
        // IT IS A CACHE (review CL-1). REDCap builds redcap_record_list in the
        // background and records how far it got in
        // redcap_record_counts.record_list_status. The probe below only proves
        // the table holds SOME row for this project, so a list that was half
        // built, or had gone stale and was being rebuilt, was walked as if it
        // were the project: a record it did not hold was never put on the
        // manifest, and the run certified a project it had not seen. Where
        // REDCap keeps the status, anything but COMPLETE (or FIX_SORT, which is
        // complete with its sort order still to be repaired) sends the walk to
        // the data table, which is slower and always whole.
        $cols = self::columnsOf($db, 'redcap_record_list');
        $usable = self::recordListComplete($db, $pid) !== false;
        if ($cols !== null && isset($cols['project_id']) && isset($cols['record']) && $usable) {
            $s = new self();
            $s->db = $db;
            $s->pid = $pid;
            $s->table = 'redcap_record_list';
            $s->recordCol = 'record';
            // REDCap has used both names. Whichever is present is used; neither
            // being present is not fatal - it costs the group column, not the
            // walk - and is reported by hasDag() so the caller can refuse a
            // group-scoped scan rather than silently scanning the project.
            $s->dagCol = isset($cols['dag_id']) ? 'dag_id' : (isset($cols['group_id']) ? 'group_id' : null);
            $s->via = 'redcap_record_list';
            $s->walkIndex = self::walkIndexOf($db, 'redcap_record_list');
            $probe = $s->probe();
            if ($probe['ok']) {
                $s->dataTable = self::dataTable($db, $pid);
                return ['ok' => true, 'source' => $s, 'why' => null];
            }
            // Fall through: an index that exists but holds nothing for this
            // project would enumerate a full project as empty.
        }
        // Said accurately when the index is there and was passed over: "no
        // record index" sends an administrator looking for a table that exists.
        $noIndex = ($cols !== null && !$usable)
            ? 'this project\'s record index is not complete'
            : 'this project has no record index';

        $dt = self::dataTable($db, $pid);
        if ($dt === null) {
            return ['ok' => false, 'source' => null,
                    'why' => $noIndex . ' and no recognisable data table, so '
                           . 'its records cannot be listed without exporting the whole project'];
        }
        if ($pk === null) {
            return ['ok' => false, 'source' => null,
                    'why' => $noIndex . ', and the record-id field could not '
                           . 'be determined, so its records cannot be listed in bounded memory'];
        }
        $s = new self();
        $s->db = $db;
        $s->pid = $pid;
        $s->table = $dt;
        $s->dataTable = $dt;
        $s->recordCol = 'record';
        $s->dagCol = null;      // groups come from __GROUPID__ rows, per page
        $s->via = $dt . ' (record-id field only)';
        $s->pkField = $pk;
        $s->walkIndex = self::walkIndexOf($db, $dt);
        $probe = $s->probe();
        if (!$probe['ok']) {
            return ['ok' => false, 'source' => null, 'why' => $probe['why']];
        }
        return ['ok' => true, 'source' => $s, 'why' => null];
    }

    /**
     * Which of these record ids the project still holds.
     *
     * Reconciliation needs this to tell a DELETION from an EDIT, and the two get
     * opposite treatment: a deleted record is tombstoned and a run can finish
     * over it, while an edited one is requeued. Guessing either way is a defect
     * - guess "deleted" and a record silently leaves the scan; guess "edited"
     * and the run waits forever for a record that will never be readable.
     *
     * FAILS TOWARD "STILL THERE", which is the requeue side: a read error costs
     * one wasted attempt, and the attempt limit turns a genuinely unreadable
     * record into a reported exclusion. The other direction would erase a record
     * from a report because a query timed out.
     *
     * ABSENT IS CONFIRMED AGAINST THE DATA (review CL-1). The first question
     * goes to the source the walk reads, which is usually redcap_record_list -
     * REDCap's record-list CACHE, which can be part built or stale while the
     * record itself sits in the data table. Answering "gone" from the cache
     * alone let catch-up tombstone an edited record and the worker's H18 path
     * tombstone a live one, and a tombstone does not block a clean verdict. So
     * every id the first answer leaves out is asked of the project's data
     * table as well, on any field, and only an id neither holds is reported
     * gone. That read cannot be made, or fails: "still there".
     *
     * @param string[] $ids
     * @return array<string,bool>
     */
    public function exist(array $ids)
    {
        $out = array_fill_keys(array_map('strval', $ids), true);
        if (!$ids) return $out;
        try {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $sql = 'SELECT ' . $this->recordCol . ' FROM ' . $this->table
                 . ' WHERE project_id = ?';
            $params = [$this->pid];
            if ($this->pkField !== null) {
                $sql .= ' AND field_name = ?';
                $params[] = $this->pkField;
            }
            $sql .= ' AND ' . $this->recordCol . ' IN (' . $marks . ')';
            foreach ($ids as $id) $params[] = (string) $id;

            $seen = [];
            foreach ($this->db->select($sql, $params) as $row) $seen[(string) $row[0]] = true;
            $absent = [];
            foreach ($out as $id => $_) {
                if (!isset($seen[(string) $id])) $absent[] = (string) $id;
            }
            if (!$absent) return $out;

            $held = $this->heldInData($absent);
            if ($held === null) return $out;          // could not confirm: still there
            foreach ($absent as $id) $out[$id] = isset($held[$id]);
            return $out;
        } catch (\Throwable $e) {
            return array_fill_keys(array_map('strval', $ids), true);
        }
    }

    /**
     * Which of these ids hold ANY row in the project's data table, as a set, or
     * null when that cannot be asked.
     *
     * Every field, not the record-id field alone: the point is to find the
     * record, and a record whose record-id row is missing is still a record.
     * DISTINCT and `project_id = ? AND record IN (...)` is the leading part of
     * the data table's own (project_id, record, ...) index.
     *
     * @param string[] $ids
     * @return array<string,true>|null
     */
    private function heldInData(array $ids)
    {
        if ($this->dataTable === null || !$ids) return null;
        $marks = implode(',', array_fill(0, count($ids), '?'));
        try {
            $rows = $this->db->select('SELECT DISTINCT record FROM ' . $this->dataTable
                . ' WHERE project_id = ? AND record IN (' . $marks . ')',
                array_merge([$this->pid], array_map('strval', $ids)));
        } catch (\Throwable $e) {
            return null;
        }
        $out = [];
        foreach ($rows as $row) $out[(string) $row[0]] = true;
        return $out;
    }

    /**
     * Does REDCap say this project's record-list cache is complete?
     *
     * true for COMPLETE and FIX_SORT; false for any other status, for no row,
     * and for a read that failed - each of those means the cache may be missing
     * records. null when this REDCap keeps no status at all (no
     * redcap_record_counts.record_list_status column), which leaves the index
     * judged by the probe alone, as it was before the status existed.
     *
     * @return bool|null
     */
    private static function recordListComplete(ScanDb $db, $pid)
    {
        $cols = self::columnsOf($db, 'redcap_record_counts');
        if ($cols === null || !isset($cols['project_id']) || !isset($cols['record_list_status'])) {
            return null;
        }
        try {
            $r = $db->select('SELECT record_list_status FROM redcap_record_counts WHERE project_id = ?',
                [$pid]);
        } catch (\Throwable $e) {
            return false;
        }
        if (!isset($r[0][0])) return false;
        return in_array(strtoupper((string) $r[0][0]), ['COMPLETE', 'FIX_SORT'], true);
    }

    /** Each record's group, or null. Null both for "no group" and for "cannot tell". */
    public function dagsOf(array $ids)
    {
        $out = array_fill_keys(array_map('strval', $ids), null);
        if (!$ids) return $out;
        // B3b: THE FALLBACK SOURCE CAN ANSWER THIS, AND USED TO REFUSE TO.
        //
        // On a project with no redcap_record_list rows the walk falls through to
        // the data table, dagCol is null, and page() resolves each record's
        // group perfectly well from the __GROUPID__ rows via fillGroups(). This
        // method short-circuited on the same null and answered "no group" for
        // every record - so planning and catch-up, on the same run, disagreed
        // about the same record. Measured on a fallback source holding
        // R001=7, R002=31, R003=7: page() returned dag 7 for R001 while
        // dagsOf() returned null for all three and inScope('7') returned false
        // for all three. The consequence is not a wrong count: catch-up admits
        // NOTHING created during a group-scoped run, and the run still claims a
        // proved fence over the window in which it admitted nothing.
        // Both halves now read groupsFromDataTable(), the one query page() uses.
        if ($this->dagCol === null) {
            $map = $this->groupsFromDataTable($ids);
            if ($map === null) return $out;
            foreach ($map as $id => $g) if (array_key_exists($id, $out)) $out[$id] = $g;
            return $out;
        }
        try {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $params = [$this->pid];
            foreach ($ids as $id) $params[] = (string) $id;
            foreach ($this->db->select('SELECT ' . $this->recordCol . ', ' . $this->dagCol
                    . ' FROM ' . $this->table . ' WHERE project_id = ? AND ' . $this->recordCol
                    . ' IN (' . $marks . ')', $params) as $row) {
                $out[(string) $row[0]] = ($row[1] === null || $row[1] === '') ? null : (string) $row[1];
            }
            return $out;
        } catch (\Throwable $e) {
            return $out;
        }
    }

    /**
     * Is each record inside a run scoped to $dag?
     *
     * FAILS CLOSED, unlike exist(). A record whose group cannot be established
     * is NOT admitted to a group-scoped run: admitting it would put another
     * group's record into a report its reader is not entitled to, which is the
     * same fail-open the page-level DAG filter was hardened against. Leaving it
     * out costs coverage, and coverage is reported.
     *
     * @return array<string,bool>
     */
    public function inScope(array $ids, $dag = null)
    {
        if ($dag === null) {
            // An unscoped run admits everything the project holds.
            return array_fill_keys(array_map('strval', $ids), true);
        }
        // ONE RESOLUTION FOR BOTH HALVES OF THE RUN. This used to refuse every
        // record whenever dagCol was null, which on the data-table fallback
        // meant a group-scoped run admitted nothing at catch-up while its
        // manifest had been built from exactly the group information this
        // branch claimed was unavailable. dagsOf() now answers on both sources,
        // so both halves ask the same question of the same rows. A record whose
        // group still cannot be established comes back null and is refused
        // below - the fail-closed direction this method's docblock commits to,
        // reached by an answer rather than by an assumption.
        if ($this->dagCol === null && $this->dataTable === null) {
            // No way at all to establish a group: fail closed, as documented.
            return array_fill_keys(array_map('strval', $ids), false);
        }
        $out = [];
        foreach ($this->dagsOf($ids) as $id => $g) {
            $out[$id] = ($g !== null && (string) $g === (string) $dag);
        }
        return $out;
    }

    /** Which source this walk reads, for the run's record of itself. */
    public function via()
    {
        return $this->via;
    }

    /**
     * Can this source say which group a record is in?
     *
     * FALSE MUST REFUSE A GROUP-SCOPED SCAN rather than downgrade it. A scan
     * that cannot read group membership and runs anyway is a scan that shows one
     * group's user every group's records, which is the leak the persisted store
     * creates and the reason this question is asked before a run exists.
     */
    public function hasDag()
    {
        return $this->dagCol !== null || $this->dataTable !== null;
    }

    /**
     * One page of record ids, in order, with each record's group.
     *
     * @param string|null $after   the last id of the previous page, or null to start
     * @param string[]    $emitted ids already emitted that collate equal to $after
     * @param int         $limit
     * @return array{ok:bool, rows:array, cursor:?string, emitted:string[], done:bool, why:?string}
     */
    public function page($after, array $emitted, $limit)
    {
        $limit = max(1, min(5000, (int) $limit));
        // DISTINCT only on the data-table fallback, where one record can hold
        // the record-id field in several events and would otherwise be listed
        // once per event. The record index has one row per record already, and
        // DISTINCT there would forbid selecting the group column beside it.
        $sql = 'SELECT ' . ($this->pkField === null ? '' : 'DISTINCT ') . $this->recordCol
             . ($this->dagCol === null ? '' : ', ' . $this->dagCol)
             . ' FROM ' . $this->walkFrom() . ' WHERE project_id = ?';
        $params = [$this->pid];
        if ($this->pkField !== null) {
            $sql .= ' AND field_name = ?';
            $params[] = $this->pkField;
        }
        if ($after !== null) {
            // `>=`, not `>`. Under a case-insensitive collation `>` can step
            // over a record whose id differs from the cursor only in case, and a
            // skipped record is a record certified without being examined.
            $sql .= ' AND ' . $this->recordCol . ' >= ?';
            $params[] = $after;
        }
        // FETCH PAST THE SKIPS. The boundary group is re-read by the `>=`
        // above and then dropped, so asking for one row more than the page
        // would spend the whole page on rows we are about to discard - and the
        // walk would report itself finished with records still ahead of it.
        // The carried set is capped, so this stays bounded.
        $fetch = $limit + count($emitted) + 1;
        $sql .= ' ORDER BY ' . $this->recordCol . ' LIMIT ' . $fetch;

        try {
            $rows = $this->db->select($sql, $params);
        } catch (\Throwable $e) {
            return ['ok' => false, 'rows' => [], 'cursor' => $after, 'emitted' => $emitted,
                    'done' => false,
                    'why' => 'the record list could not be read (' . get_class($e) . ')'];
        }

        $skip = array_flip($emitted);
        $out = [];
        // ONE ROW PER ARM (PRF-8). REDCap's record index keys a record by
        // project, arm and record, so a record in two arms of a project is two
        // rows with the same id, next to each other in this order. It was
        // listed twice; the walk lists each id once, by its exact bytes, so
        // ids the collation ties are still kept apart.
        $inPage = [];
        foreach ($rows as $row) {
            $id = (string) $row[0];
            if (isset($skip[$id])) continue;         // already emitted at this boundary
            if (isset($inPage[$id])) continue;       // the same record in another arm
            $inPage[$id] = true;
            $out[] = ['id' => $id,
                      // THE GROUP ID, VERBATIM. dag_id on the record index and
                      // the __GROUPID__ value on the data table are both
                      // REDCap's numeric group id; neither is the friendly
                      // name. This is the value ScanPlanner::step() compares
                      // scope_dag against and the value written to
                      // uv_scan_record.dag, so anything that normalised it into
                      // a label here would move the whole manifest onto a second
                      // axis. (uv_finding.dag_key is a DIFFERENT column on a
                      // DIFFERENT axis - see UniversalValidator::dagOfRecordNode.)
                      'dag' => ($this->dagCol === null ? null
                                : (($row[1] === null || $row[1] === '') ? null : (string) $row[1]))];
            if (count($out) >= $limit) break;
        }

        // An empty page after skipping means every row we fetched had already
        // been emitted, which can only happen when the whole remaining tail is
        // the boundary group - so there is nothing left. It is stated rather
        // than assumed, because "no rows" and "no rows we can use" reaching the
        // same conclusion by accident is how a walk stops early.
        if (!$out) {
            return ['ok' => true, 'rows' => [], 'cursor' => $after, 'emitted' => $emitted,
                    'done' => true, 'why' => null];
        }

        // THE BOUNDARY GROUP: every id the database considers equal to this
        // page's last id, and therefore every id a `>=` next page can hand back.
        //
        // Asked of the SOURCE TABLE rather than computed here, because the
        // answer belongs to a collation this file cannot see and must not guess.
        // Comparing two bound parameters would answer in the CONNECTION's
        // collation, which is a different question with the same shape - the
        // kind of near-miss that passes every test until it meets an
        // installation whose record column is latin1.
        $last = $out[count($out) - 1]['id'];
        $group = $this->boundaryGroup($last);
        if ($group === null) {
            return ['ok' => false, 'rows' => [], 'cursor' => $after, 'emitted' => $emitted,
                    'done' => false,
                    'why' => 'the record list could not be read back to establish a stable page '
                           . 'boundary'];
        }
        if (count($group) > self::TIE_CAP) {
            return ['ok' => false, 'rows' => [], 'cursor' => $after, 'emitted' => $emitted,
                    'done' => false,
                    'why' => 'more than ' . self::TIE_CAP . ' record ids in this project are '
                           . 'indistinguishable to its database collation, so the record list '
                           . 'cannot be walked in a stable order'];
        }
        // Carry only what has actually been emitted. Members of the group that
        // sort past this page have not been seen yet and must not be suppressed.
        $carry = [];
        foreach ($out as $r) {
            if (isset($group[$r['id']])) $carry[] = $r['id'];
        }
        foreach ($emitted as $e) {
            if (isset($group[$e])) $carry[] = $e;
        }
        $carry = array_values(array_unique($carry));
        $cursor = $last;

        // Asked for one more than could possibly be used: fewer coming back
        // means there is nothing past this page.
        $done = count($rows) < $fetch;

        if ($this->dagCol === null && $out) $this->fillGroups($out);

        return ['ok' => true, 'rows' => $out, 'cursor' => $cursor, 'emitted' => $carry,
                'done' => $done, 'why' => null];
    }

    /**
     * Groups for one page, from the data table's `__GROUPID__` rows.
     *
     * One query per page, never per record. A record with no row is ungrouped,
     * which is a real answer and not a missing one.
     */
    private function fillGroups(array &$rows)
    {
        if ($this->dataTable === null) return;
        $ids = [];
        foreach ($rows as $r) $ids[] = $r['id'];
        $map = $this->groupsFromDataTable($ids);
        if ($map === null) return;    // caller sees null groups and reports the degradation
        foreach ($rows as $i => $r) {
            if (isset($map[$r['id']])) $rows[$i]['dag'] = $map[$r['id']];
        }
    }

    /**
     * record id => group id, from the data table's `__GROUPID__` rows. Null when
     * the question could not be asked at all.
     *
     * EXTRACTED so page() and dagsOf() cannot drift. They had drifted: page()
     * fell back to these rows when the record index carried no group column,
     * dagsOf() did not, and inScope() refused every record on that source
     * because it only looked at the index. hasDag() answers true for it, so a
     * group-scoped scan was allowed to start and then admitted nothing.
     * One query per page, never per record.
     */
    private function groupsFromDataTable(array $ids)
    {
        if ($this->dataTable === null || !$ids) return null;
        $marks = implode(',', array_fill(0, count($ids), '?'));
        try {
            $q = $this->db->select('SELECT record, value FROM ' . $this->dataTable . '
                WHERE project_id = ? AND field_name = ? AND record IN (' . $marks . ')',
                array_merge([$this->pid, '__GROUPID__'], array_map('strval', $ids)));
        } catch (\Throwable $e) {
            return null;
        }
        $map = [];
        // '' IS NOT A GROUP. The __GROUPID__ row of a record that has been
        // removed from its group holds the empty string, not NULL, and the
        // index branch at page() already folds both onto null. Doing it here
        // rather than in each caller is what keeps the two sources answering
        // the same shape: dagsOf() reads this method on the fallback, and
        // inScope() then refuses null - so an unfolded '' would be compared
        // against a group id as a value rather than refused as an absence.
        foreach ($q as $row) {
            $map[(string) $row[0]] = ($row[1] === null || $row[1] === '') ? null : (string) $row[1];
        }
        return $map;
    }

    /**
     * Every record id this project holds that the database considers equal to
     * $id, as a set. Null when the question could not be asked.
     *
     * One query per page, in the record column's own collation, bounded by one
     * more than the cap so an unusable project is detected rather than paged.
     *
     * LOAD-BEARING, AND THE ONE LEVER IF PLANNING EVER NEEDS TO BE FASTER (P4).
     * It is half the queries a plan makes, and it is what makes the `>=`
     * keyset walk skip nothing: without it, ids the collation calls equal to
     * a page's last id are either listed twice or not at all. Do not remove it
     * without putting the same guarantee somewhere else. Do not shrink the
     * page to speed planning up either - see ScanPlanner::step().
     */
    private function boundaryGroup($id)
    {
        $sql = 'SELECT ' . ($this->pkField === null ? '' : 'DISTINCT ') . $this->recordCol
             . ' FROM ' . $this->walkFrom() . ' WHERE project_id = ?';
        $params = [$this->pid];
        if ($this->pkField !== null) {
            $sql .= ' AND field_name = ?';
            $params[] = $this->pkField;
        }
        $sql .= ' AND ' . $this->recordCol . ' = ? LIMIT ' . (self::TIE_CAP + 1);
        $params[] = $id;
        try {
            $rows = $this->db->select($sql, $params);
        } catch (\Throwable $e) {
            return null;
        }
        $out = [];
        foreach ($rows as $r) $out[(string) $r[0]] = true;
        // The id itself must be in its own group; a source that does not return
        // it has answered a different question than the one asked.
        $out[(string) $id] = true;
        return $out;
    }

    /** Prove the walk answers for THIS project before anything depends on it. */
    private function probe()
    {
        try {
            $sql = 'SELECT ' . $this->recordCol . ' FROM ' . $this->walkFrom() . ' WHERE project_id = ?';
            $params = [$this->pid];
            if ($this->pkField !== null) {
                $sql .= ' AND field_name = ?';
                $params[] = $this->pkField;
            }
            $r = $this->db->select($sql . ' ORDER BY ' . $this->recordCol . ' LIMIT 1', $params);
            if (!isset($r[0])) {
                return ['ok' => false,
                        'why' => $this->via . ' holds no records for this project, so it cannot be '
                               . 'used to list them'];
            }
            return ['ok' => true, 'why' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'why' => $this->via . ' could not be read (' . get_class($e) . ')'];
        }
    }

    // -- server-derived identifiers -----------------------------------------

    /**
     * The project's data table, allowlisted.
     *
     * REDCap gained per-project data tables, so `redcap_data` is a default and
     * not a fact. The column holding the name may itself not exist on older
     * builds, which is why the read is wrapped rather than branched on a version
     * number - a version number is a claim, and a failed query is an answer.
     */
    public static function dataTable(ScanDb $db, $pid)
    {
        try {
            $r = $db->select('SELECT data_table FROM redcap_projects WHERE project_id = ?', [$pid]);
            $t = isset($r[0][0]) ? (string) $r[0][0] : '';
            if ($t !== '' && preg_match('/^redcap_data[0-9]*\z/', $t)) return $t;
        } catch (\Throwable $e) {
            \INSPIRE\UniversalValidator\Faults::swallow($e, 'RecordManifestSource::dataTable');
            // No such column on this build: fall through to the default, which
            // is then itself proved to exist.
        }
        $cols = self::columnsOf($db, 'redcap_data');
        if ($cols !== null && isset($cols['project_id']) && isset($cols['record'])
                && isset($cols['field_name'])) {
            return 'redcap_data';
        }
        return null;
    }

    /**
     * A table's columns, or null when it does not exist.
     *
     * information_schema, not `SHOW COLUMNS LIKE ?`: SHOW is not preparable in
     * the client protocol, so a bound parameter makes the statement fail rather
     * than match - the defect the database matrix caught in Schema::health() on
     * its first run. The table name is still validated by shape, because a name
     * can never be a bound parameter anywhere it is interpolated.
     */
    /**
     * The table, with the index the ordered reads must use when there is one.
     *
     * PRF-8. REDCap's data table is keyed (project_id, field_name) and
     * (project_id, record, field_name), among others. For the
     * fallback walk, WHERE project_id = ? AND field_name = ? ORDER BY record,
     * the server chose the first and sorted every record of the project for
     * each page of 1,000, so a walk cost about n squared over 1,000 row sorts.
     * Told to use an index that leads with (project_id, record), it reads the
     * records in order and stops at the page's end, with no sort.
     */
    private function walkFrom()
    {
        return $this->walkIndex === null ? $this->table
            : $this->table . ' FORCE INDEX (`' . $this->walkIndex . '`)';
    }

    /**
     * An index on $table whose first two columns are project_id and record,
     * read from the server and never assumed: REDCap's index names are not
     * part of any contract. One that also holds field_name is preferred, as
     * the fallback walk then reads the index alone. Null when there is none,
     * when its name is not a plain identifier, or when the read fails; the
     * reads then run without a hint, as before.
     */
    private static function walkIndexOf(ScanDb $db, $table)
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*\z/', $table)) return null;
        try {
            $rows = $db->select('SELECT index_name, seq_in_index, column_name FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = ? ORDER BY index_name, seq_in_index', [$table]);
        } catch (\Throwable $e) {
            \INSPIRE\UniversalValidator\Faults::swallow($e, 'RecordManifestSource::walkIndexOf');
            return null;
        }
        $cols = [];
        foreach ((array) $rows as $r) {
            if (!is_array($r) || !isset($r[0], $r[1], $r[2])) continue;
            $cols[(string) $r[0]][(int) $r[1]] = strtolower((string) $r[2]);
        }
        $pick = null;
        foreach ($cols as $name => $c) {
            if (!preg_match('/^[A-Za-z0-9_]+\z/', $name)) continue;
            if (($c[1] ?? null) !== 'project_id' || ($c[2] ?? null) !== 'record') continue;
            if (in_array('field_name', $c, true)) return $name;
            if ($pick === null) $pick = $name;
        }
        return $pick;
    }

    private static function columnsOf(ScanDb $db, $table)
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*\z/', $table)) return null;
        try {
            $rows = $db->select('SELECT column_name FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$rows) return null;
        $out = [];
        foreach ($rows as $r) $out[strtolower((string) $r[0])] = true;
        return $out;
    }
}
