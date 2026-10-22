<?php

use INSPIRE\UniversalValidator\Scan\ScanDb;

/**
 * A mock of REDCap's log for php/ValueHistory.php, shared by
 * tests/writeonce_php.php and tests/writeonce_module_php.php. Rows are
 * [id, pid, pk, event_id, ts, data_values, object_type, event, user,
 * description?] (description defaults to "Delete record" for a DELETE and
 * "Update record" otherwise; an explicit null is a NULL description). It
 * answers the projects lookup (the log table's name), the newest
 * project-wide erase row, and the paged read of one record's rows for one
 * event (deletes, rows with no event and rows matching the rename pattern
 * included), after the erase floor, newest first; $fail and $failOn make a
 * read fail. With $looseEvent it returns the rows of every event, as a
 * server that ignored the event filter would: the reader checks each row's
 * event again.
 */
final class FakeHistoryLog implements ScanDb
{
    public $rows = [];
    public $table = 'redcap_log_event7';
    public $queries = 0;
    public $fail = false;
    public $failOn = 0;
    public $looseEvent = false;
    public $sql = [];
    public function select($sql, array $params = [])
    {
        $this->queries++;
        $this->sql[] = $sql;
        if ($this->fail || ($this->failOn && $this->queries === $this->failOn)) throw new \RuntimeException('down');
        if (strpos($sql, 'FROM redcap_projects') !== false) return [[$this->table]];
        if (strpos($sql, 'FROM ' . $this->table . ' ') === false) throw new \RuntimeException('wrong table');
        preg_match('/LIMIT (\d+)$/', $sql, $m);
        $limit = (int) $m[1];
        if (strpos($sql, "AND event = 'DELETE' AND event_id IS NULL AND (description IS NULL OR description NOT LIKE 'Delete record%')") !== false) {
            list($pid, $pk) = $params;
            $out = [];
            foreach ($this->rows as $r) {
                if ($r[1] !== $pid || strtolower($r[2]) !== strtolower($pk) || $r[6] !== 'redcap_data' || strtoupper($r[7]) !== 'DELETE' || $r[3] !== null) continue;
                $descr = $this->descr($r);
                if ($descr !== null && stripos($descr, 'delete record') === 0) continue;
                $out[] = [(string) $r[0]];
            }
            usort($out, function ($a, $b) { return (int) $b[0] - (int) $a[0]; });
            return array_slice($out, 0, $limit);
        }
        if (strpos($sql, "(event = 'DELETE' OR event_id = ? OR event_id IS NULL") === false) throw new \RuntimeException('no event filter');
        $i = 0;
        $pid = $params[$i++];
        $pk = $params[$i++];
        $event = $params[$i++];
        $like = strpos($sql, 'OR data_values LIKE ?') !== false ? $params[$i++] : null;
        $after = strpos($sql, 'AND log_event_id > ?') !== false ? (int) $params[$i++] : 0;
        $before = strpos($sql, 'AND log_event_id < ?') !== false ? (int) $params[$i++] : PHP_INT_MAX;
        // LIKE '%x%': x as plain text (an underscore matching itself is enough here)
        $needle = $like === null ? null : trim($like, '%');
        $out = [];
        foreach ($this->rows as $r) {
            // the collation matches letter case loosely, as MySQL's may, and so does IN
            if ($r[1] !== $pid || strtolower($r[2]) !== strtolower($pk) || $r[0] >= $before || $r[0] <= $after) continue;
            if ($r[6] !== 'redcap_data' || !in_array(strtoupper($r[7]), ['INSERT', 'UPDATE', 'DELETE'], true)) continue;
            $match = strtoupper($r[7]) === 'DELETE' || $r[3] === null || (int) $r[3] === (int) $event
                || ($needle !== null && strpos((string) $r[5], $needle) !== false);
            if (!$this->looseEvent && !$match) continue;
            $out[] = [(string) $r[0], $r[4], $r[3], $r[5], $r[2], $r[8], $r[7], $this->descr($r)];
        }
        usort($out, function ($a, $b) { return (int) $b[0] - (int) $a[0]; });
        return array_slice($out, 0, $limit);
    }
    private function descr(array $r)
    {
        if (array_key_exists(9, $r)) return $r[9];
        return strtoupper($r[7]) === 'DELETE' ? 'Delete record' : 'Update record';
    }
    public function exec($sql, array $params = []) {}
    public function execNoCount($sql, array $params = []) {}
    public function affected() { return 0; }
    public function begin() {}
    public function commit() {}
    public function rollback() {}
}
