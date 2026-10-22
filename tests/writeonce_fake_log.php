<?php

use INSPIRE\UniversalValidator\Scan\ScanDb;

/**
 * A mock of REDCap's log for php/ValueHistory.php, shared by
 * tests/writeonce_php.php and tests/writeonce_module_php.php. Rows are
 * [id, pid, pk, event_id, ts, data_values, object_type, event, user]. It
 * answers the projects lookup (the log table's name) and the paged read of
 * one record's rows, newest first; $fail and $failOn make a read fail.
 */
final class FakeHistoryLog implements ScanDb
{
    public $rows = [];
    public $table = 'redcap_log_event7';
    public $queries = 0;
    public $fail = false;
    public $failOn = 0;
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
        list($pid, $pk) = $params;
        $before = isset($params[2]) ? (int) $params[2] : PHP_INT_MAX;
        $out = [];
        foreach ($this->rows as $r) {
            // the collation matches letter case loosely, as MySQL's may
            if ($r[1] !== $pid || strtolower($r[2]) !== strtolower($pk) || $r[0] >= $before) continue;
            if ($r[6] !== 'redcap_data' || !in_array($r[7], ['INSERT', 'UPDATE', 'DELETE'], true)) continue;
            $out[] = [(string) $r[0], $r[4], $r[3], $r[5], $r[2], $r[8], $r[7]];
        }
        usort($out, function ($a, $b) { return (int) $b[0] - (int) $a[0]; });
        return array_slice($out, 0, $limit);
    }
    public function exec($sql, array $params = []) {}
    public function execNoCount($sql, array $params = []) {}
    public function affected() { return 0; }
    public function begin() {}
    public function commit() {}
    public function rollback() {}
}
