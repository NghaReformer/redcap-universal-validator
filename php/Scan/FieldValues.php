<?php
namespace INSPIRE\UniversalValidator\Scan;

/**
 * The records that hold a value, read from the project's data table.
 *
 * A @UVUNIQUE lookup settled a miss by exporting the tagged fields of every
 * record through REDCap::getData, and the save audit did the same on every
 * save: at 100,000 records a 115 MB array and 0.4 s of comparison each time
 * (UNQ-7, PRF-2). This reads the (record, field, value) rows of the searched
 * fields straight from the data table, one row at a time, and keeps only the
 * records whose value the caller's test accepts. The caller then reads those
 * few records through getData and compares them as before, so the answer does
 * not change. Only the records that cannot match are never built.
 *
 * The rows are read through the index on (project_id, field_name) that REDCap
 * keeps on every data table, one field at a time. Nothing is sorted and
 * nothing is held but the record ids that matched.
 *
 * NULL, NOT [], WHEN THE TABLE CANNOT BE READ. An empty list is an answer (no
 * record holds the value); null tells the caller to fall back to the export.
 *
 * @UVEXISTS reads it the same way (EXS-4): the records that hold the value
 * searched, then getData of those records only. The table is read by the
 * module, so REDCap does not confine it to the Data Access Group of the user
 * (EXS-1): a value saved nowhere is "not found" for a user in a group too.
 */
final class FieldValues
{
    private $module;
    /** @var array pid => the data table, or false when none could be resolved */
    private $tables = [];

    /** @param object $module anything with the framework's query($sql, $params) */
    public function __construct($module)
    {
        $this->module = $module;
    }

    /**
     * The ids of the records with a row of one of $fields whose value $accepts,
     * each once, in the order the table gave them, field by field; or null when
     * the data table could not be read.
     *
     * @param int      $pid
     * @param string[] $fields
     * @param int|null $eventId rows of this event only, or null for every event
     * @param callable $accepts function (string $field, string $value): bool
     * @return string[]|null
     */
    public function recordsWhere($pid, array $fields, $eventId, callable $accepts)
    {
        $fields = array_values(array_unique(array_map('strval', $fields)));
        if (!$fields || !is_object($this->module) || !is_callable([$this->module, 'query'])) return null;
        $table = $this->tableOf($pid);
        if ($table === null) return null;
        // The table name is allowlisted (tableOf); everything else is bound.
        // ONE STATEMENT PER FIELD. With "field_name IN (?, ?)" MySQL 5.7 and
        // 8.0 both read every row of the project through (project_id, record,
        // field_name) and filter; with "=" they read the field's rows through
        // (project_id, field_name) (tests/mysql/cases/field_values.php).
        $sql = 'SELECT record, field_name, `value` FROM ' . $table . ' WHERE project_id = ? AND field_name = ?';
        $byEvent = $eventId !== null && $eventId !== '';
        if ($byEvent) $sql .= ' AND event_id = ?';
        $hit = [];
        try {
            foreach ($fields as $f) {
                $params = [(int) $pid, $f];
                if ($byEvent) $params[] = (int) $eventId;
                $q = $this->module->query($sql, $params);
                if (is_array($q)) {
                    foreach ($q as $row) {
                        if (!self::take(is_array($row) ? array_values($row) : null, $accepts, $hit)) return null;
                    }
                } elseif (is_object($q) && is_callable([$q, 'fetch_row'])) {
                    while ($row = $q->fetch_row()) {
                        if (!self::take($row, $accepts, $hit)) return null;
                    }
                } elseif (is_object($q) && is_callable([$q, 'fetch_assoc'])) {
                    while ($row = $q->fetch_assoc()) {
                        if (!self::take(array_values($row), $accepts, $hit)) return null;
                    }
                } else {
                    return null;
                }
            }
        } catch (\Throwable $e) {
            return null;
        }
        $out = [];
        foreach ($hit as $rec => $_) $out[] = (string) $rec;
        return $out;
    }

    /**
     * recordsWhere() over the rows of $records only: the ones whose row of
     * one of $fields $accepts, or null when the table could not be read. The
     * rows come through the (project_id, record, field_name) index, so the
     * cost is that of the records named, not of the project. LookupIndex
     * names the records; this confirms them against what is saved now.
     *
     * The record list is matched by the table's collation, so a record whose
     * id differs only in letter case can come back too. Its rows are tested
     * like the others: a record that holds the value is never left out.
     *
     * @param string[] $records
     * @return string[]|null
     */
    public function recordsAmong($pid, array $fields, $eventId, array $records, callable $accepts)
    {
        $fields = array_values(array_unique(array_map('strval', $fields)));
        $records = array_values(array_unique(array_map('strval', $records)));
        if (!$records) return [];
        if (!$fields || !is_object($this->module) || !is_callable([$this->module, 'query'])) return null;
        $table = $this->tableOf($pid);
        if ($table === null) return null;
        $byEvent = $eventId !== null && $eventId !== '';
        $hit = [];
        try {
            foreach (array_chunk($records, 500) as $chunk) {
                $sql = 'SELECT record, field_name, `value` FROM ' . $table . ' WHERE project_id = ? AND record IN ('
                    . implode(', ', array_fill(0, count($chunk), '?')) . ') AND field_name IN ('
                    . implode(', ', array_fill(0, count($fields), '?')) . ')';
                $params = array_merge([(int) $pid], $chunk, $fields);
                if ($byEvent) {
                    $sql .= ' AND event_id = ?';
                    $params[] = (int) $eventId;
                }
                $q = $this->module->query($sql, $params);
                if (is_array($q)) {
                    foreach ($q as $row) {
                        if (!self::take(is_array($row) ? array_values($row) : null, $accepts, $hit)) return null;
                    }
                } elseif (is_object($q) && is_callable([$q, 'fetch_row'])) {
                    while ($row = $q->fetch_row()) {
                        if (!self::take($row, $accepts, $hit)) return null;
                    }
                } elseif (is_object($q) && is_callable([$q, 'fetch_assoc'])) {
                    while ($row = $q->fetch_assoc()) {
                        if (!self::take(array_values($row), $accepts, $hit)) return null;
                    }
                } else {
                    return null;
                }
            }
        } catch (\Throwable $e) {
            return null;
        }
        $out = [];
        foreach ($hit as $rec => $_) $out[] = (string) $rec;
        return $out;
    }

    /**
     * Every row of $field (of event $eventId, or of every event), handed to
     * $each(record, event_id, value) one at a time. True when the whole field
     * was read, false when the table could not be read or a row could not be
     * read (the caller then reads through getData). The @UVEXISTS scan index
     * is built this way (EXS-5): one statement instead of an export of every
     * record. A NULL value is skipped, as REDCap stores no blank.
     *
     * @param int      $pid
     * @param string   $field
     * @param int|null $eventId
     * @param callable $each function (string $record, string $eventId, string $value): void
     * @return bool
     */
    public function eachRow($pid, $field, $eventId, callable $each)
    {
        if (!is_object($this->module) || !is_callable([$this->module, 'query'])) return false;
        $table = $this->tableOf($pid);
        if ($table === null) return false;
        $sql = 'SELECT record, event_id, `value` FROM ' . $table . ' WHERE project_id = ? AND field_name = ?';
        $params = [(int) $pid, (string) $field];
        if ($eventId !== null && $eventId !== '') {
            $sql .= ' AND event_id = ?';
            $params[] = (int) $eventId;
        }
        $one = function ($row) use ($each) {
            if (!is_array($row) || count($row) < 3) return false;
            $row = array_values($row);
            if ($row[2] !== null) $each((string) $row[0], (string) $row[1], (string) $row[2]);
            return true;
        };
        try {
            $q = $this->module->query($sql, $params);
            if (is_array($q)) {
                foreach ($q as $row) if (!$one($row)) return false;
            } elseif (is_object($q) && is_callable([$q, 'fetch_row'])) {
                while ($row = $q->fetch_row()) if (!$one($row)) return false;
            } elseif (is_object($q) && is_callable([$q, 'fetch_assoc'])) {
                while ($row = $q->fetch_assoc()) if (!$one($row)) return false;
            } else {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }
        return true;
    }

    /** One row into $hit; false for a row this class cannot read. */
    private static function take($row, callable $accepts, array &$hit)
    {
        if (!is_array($row) || count($row) < 3) return false;
        $rec = (string) $row[0];
        if (isset($hit[$rec]) || $row[2] === null) return true;
        if ($accepts((string) $row[1], (string) $row[2])) $hit[$rec] = true;
        return true;
    }

    /**
     * The project's data table, resolved the way the scan's walk resolves it
     * (RecordManifestSource::dataTable), once per project, or null.
     */
    private function tableOf($pid)
    {
        $k = (int) $pid;
        if (!array_key_exists($k, $this->tables)) {
            $t = null;
            try {
                $t = RecordManifestSource::dataTable(new ModuleDb($this->module), $k);
            } catch (\Throwable $e) {
                $t = null;
            }
            $this->tables[$k] = (is_string($t) && preg_match('/^redcap_data[0-9]*\z/', $t)) ? $t : false;
        }
        return $this->tables[$k] === false ? null : $this->tables[$k];
    }
}
