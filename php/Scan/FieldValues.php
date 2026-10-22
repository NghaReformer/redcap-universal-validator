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
