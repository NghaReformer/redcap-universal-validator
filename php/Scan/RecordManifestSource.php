<?php

namespace INSPIRE\UniversalValidator\Scan;

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
 *   project - a table existing is not the same as a table being populated, and
 *   an empty index would enumerate a full project as empty.
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

        // Preferred: REDCap's record index.
        $cols = self::columnsOf($db, 'redcap_record_list');
        if ($cols !== null && isset($cols['project_id']) && isset($cols['record'])) {
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
            $probe = $s->probe();
            if ($probe['ok']) {
                $s->dataTable = self::dataTable($db, $pid);
                return ['ok' => true, 'source' => $s, 'why' => null];
            }
            // Fall through: an index that exists but holds nothing for this
            // project would enumerate a full project as empty.
        }

        $dt = self::dataTable($db, $pid);
        if ($dt === null) {
            return ['ok' => false, 'source' => null,
                    'why' => 'this project has no record index and no recognisable data table, so '
                           . 'its records cannot be listed without exporting the whole project'];
        }
        if ($pk === null) {
            return ['ok' => false, 'source' => null,
                    'why' => 'this project has no record index, and the record-id field could not '
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
        $probe = $s->probe();
        if (!$probe['ok']) {
            return ['ok' => false, 'source' => null, 'why' => $probe['why']];
        }
        return ['ok' => true, 'source' => $s, 'why' => null];
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
             . ' FROM ' . $this->table . ' WHERE project_id = ?';
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
        foreach ($rows as $row) {
            $id = (string) $row[0];
            if (isset($skip[$id])) continue;         // already emitted at this boundary
            $out[] = ['id' => $id,
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
        $marks = implode(',', array_fill(0, count($ids), '?'));
        try {
            $q = $this->db->select('SELECT record, value FROM ' . $this->dataTable . '
                WHERE project_id = ? AND field_name = ? AND record IN (' . $marks . ')',
                array_merge([$this->pid, '__GROUPID__'], $ids));
        } catch (\Throwable $e) {
            return;    // the caller sees null groups and reports the degradation
        }
        $map = [];
        foreach ($q as $row) $map[(string) $row[0]] = ($row[1] === null ? null : (string) $row[1]);
        foreach ($rows as $i => $r) {
            if (isset($map[$r['id']])) $rows[$i]['dag'] = $map[$r['id']];
        }
    }

    /**
     * Every record id this project holds that the database considers equal to
     * $id, as a set. Null when the question could not be asked.
     *
     * One query per page, in the record column's own collation, bounded by one
     * more than the cap so an unusable project is detected rather than paged.
     */
    private function boundaryGroup($id)
    {
        $sql = 'SELECT ' . ($this->pkField === null ? '' : 'DISTINCT ') . $this->recordCol
             . ' FROM ' . $this->table . ' WHERE project_id = ?';
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
            $sql = 'SELECT ' . $this->recordCol . ' FROM ' . $this->table . ' WHERE project_id = ?';
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
