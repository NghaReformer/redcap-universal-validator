<?php
namespace INSPIRE\UniversalValidator;

use INSPIRE\UniversalValidator\Scan\ScanDb;
use INSPIRE\UniversalValidator\Scan\SourceFence;

/**
 * When a saved value was saved, read from REDCap's own log, for the
 * @UVWINDOW rules judged against "today" (a "from" of today or now,
 * "notPast", "notFuture"). Such a rule judges a saved value against the day
 * it was saved, so re-saving another field of the record never turns an old
 * date into a finding, and a later scan still sees a date that was in the
 * future when it was saved.
 *
 * THE LOG. The project's log table (redcap_log_event or redcap_log_eventN,
 * named by redcap_projects.log_event_table and allowlisted by
 * SourceFence::resolveTable) keeps one row per save: the record in pk, the
 * event in event_id, the time in ts (YYYYMMDDHHMMSS, the server's wall-clock
 * time) and what the save wrote in data_values, one "field = 'value'" line
 * per field joined by ",\n", after an "[instance = N]" line for a repeating
 * instance past the first.
 *
 * TRUST. A stamp is trusted only when the newest row that logged the field
 * logged the value saved now. Every other answer says why it is not a stamp
 * (state), and the caller decides: the post-save audit knows a value the log
 * does not show yet was written by the save it is auditing, a scan reports
 * the rule as not checked. A line that appears twice in one row (a notes
 * value holding a line of the same shape) is ambiguous and never a stamp.
 *
 * PHP 7.4.
 */
final class ValueStamps
{
    /** Rows read for one record at most. A value last logged further back than this is "capped". */
    const MAX_ROWS_PER_RECORD = 5000;
    /** Rows per query. */
    const PAGE = 500;
    /**
     * Records kept in memory. A scan asks record by record, so the oldest
     * record read is dropped once this many are held, and a scan of a large
     * project holds a few records' log at a time, not the whole log.
     */
    const RECORDS_KEPT = 16;

    /** @var ScanDb */
    private $db;
    private $pid;
    /** @var string an allowlisted log table name */
    private $table;
    /** @var \DateTimeZone the zone the log's ts is written in */
    private $logZone;
    /** @var \DateTimeZone the zone "today" is judged in */
    private $clockZone;
    /** record => event id => instance => field => [ts, value|null], newest row first */
    private $seen = [];
    /** record => true once every row was read, or the cap reached */
    private $done = [];
    /** record => the oldest log_event_id read so far (the keyset cursor) */
    private $cursor = [];
    /** record => rows read */
    private $rows = [];
    /** record => true when the log holds any row for it */
    private $any = [];
    /** record => true when reading stopped at MAX_ROWS_PER_RECORD */
    private $capped = [];
    /** record => true, in the order the records were first read */
    private $kept = [];

    private function __construct(ScanDb $db, $pid, $table, \DateTimeZone $logZone, \DateTimeZone $clockZone)
    {
        $this->db = $db;
        $this->pid = $pid;
        $this->table = $table;
        $this->logZone = $logZone;
        $this->clockZone = $clockZone;
    }

    /** A reader for this project's log, or null when the log table cannot be resolved. */
    public static function forProject(ScanDb $db, $pid, \DateTimeZone $logZone, \DateTimeZone $clockZone)
    {
        $table = SourceFence::resolveTable($db, $pid);
        return $table === null ? null : new self($db, $pid, $table, $logZone, $clockZone);
    }

    /**
     * When $field's value $value was saved on this record, event and instance.
     *
     * @return array{state:string, at:?string} at is 'Y-m-d H:i:s' in the clock
     *   zone when state is "logged". The other states:
     *   changed   the newest row for the field logged another value (or an
     *             ambiguous one)
     *   unlogged  the log holds rows for the record, none for this field
     *   capped    no row for this field among the newest MAX_ROWS_PER_RECORD
     *             rows of the record; older rows were not read
     *   none      the log holds no row for the record at all
     *   unknown   the log could not be read
     */
    public function savedAt($record, $eventId, $instance, $field, $value)
    {
        $record = (string) $record;
        $eventId = (string) (int) $eventId;
        $instance = max(1, (int) $instance);
        $value = trim((string) $value, " \t\r\n");
        $this->keep($record);
        while (true) {
            if (isset($this->seen[$record][$eventId][$instance]) && array_key_exists($field, $this->seen[$record][$eventId][$instance])) {
                list($ts, $logged) = $this->seen[$record][$eventId][$instance][$field];
                if ($logged === null || trim($logged, " \t\r\n") !== $value) return ['state' => 'changed', 'at' => null];
                $at = $this->toClock($ts);
                return $at === null ? ['state' => 'unknown', 'at' => null] : ['state' => 'logged', 'at' => $at];
            }
            if (!empty($this->done[$record])) {
                $state = empty($this->any[$record]) ? 'none' : (empty($this->capped[$record]) ? 'unlogged' : 'capped');
                return ['state' => $state, 'at' => null];
            }
            if (!$this->readPage($record)) return ['state' => 'unknown', 'at' => null];
        }
    }

    /** Hold $record, dropping the record read longest ago past RECORDS_KEPT. */
    private function keep($record)
    {
        if (isset($this->kept[$record])) return;
        $this->kept[$record] = true;
        if (count($this->kept) <= self::RECORDS_KEPT) return;
        reset($this->kept);
        $old = (string) key($this->kept);
        unset($this->kept[$old], $this->seen[$old], $this->done[$old], $this->cursor[$old],
              $this->rows[$old], $this->any[$old], $this->capped[$old]);
    }

    /** How many records are held in memory (for tests). */
    public function recordsHeld()
    {
        return count($this->kept);
    }

    /**
     * One page of the record's log, newest first, into $seen. false when the
     * log could not be read.
     */
    private function readPage($record)
    {
        $sql = 'SELECT log_event_id, ts, event_id, data_values, CAST(pk AS BINARY) AS p FROM ' . $this->table
             . ' WHERE project_id = ? AND pk = ? AND object_type = \'redcap_data\' AND event IN (\'INSERT\', \'UPDATE\')';
        $params = [$this->pid, $record];
        if (isset($this->cursor[$record])) {
            $sql .= ' AND log_event_id < ?';
            $params[] = $this->cursor[$record];
        }
        $sql .= ' ORDER BY log_event_id DESC LIMIT ' . self::PAGE;
        try {
            $rows = $this->db->select($sql, $params);
        } catch (\Throwable $e) {
            return false;
        }
        if (!is_array($rows)) return false;
        $n = 0;
        foreach ($rows as $row) {
            $n++;
            $id = isset($row[0]) ? (string) $row[0] : '';
            if ($id === '' || !ctype_digit($id)) return false;
            $this->cursor[$record] = $id;
            // pk compared as bytes: the column's collation may match "abc" for "ABC".
            if (!isset($row[4]) || (string) $row[4] !== $record) continue;
            $this->any[$record] = true;
            $event = isset($row[2]) && $row[2] !== null && $row[2] !== '' ? (string) (int) $row[2] : null;
            if ($event === null) continue;
            foreach (self::parseDataValues(isset($row[3]) ? $row[3] : '') as $inst => $fields) {
                foreach ($fields as $f => $v) {
                    if (isset($this->seen[$record][$event][$inst]) && array_key_exists($f, $this->seen[$record][$event][$inst])) continue;
                    $this->seen[$record][$event][$inst][$f] = [isset($row[1]) ? (string) $row[1] : '', $v];
                }
            }
        }
        $this->rows[$record] = (isset($this->rows[$record]) ? $this->rows[$record] : 0) + $n;
        if ($n < self::PAGE) {
            $this->done[$record] = true;
        } elseif ($this->rows[$record] >= self::MAX_ROWS_PER_RECORD) {
            $this->done[$record] = true;
            $this->capped[$record] = true;
        }
        return true;
    }

    /**
     * data_values as instance => field => value. A field logged twice in one
     * row maps to null (ambiguous). Lines of another shape (a checkbox code,
     * a file, a DAG change) are skipped.
     */
    public static function parseDataValues($text)
    {
        $out = [];
        if (!is_string($text) || $text === '') return $out;
        $instance = 1;
        foreach (preg_split('/\r?\n/', $text) as $i => $line) {
            if ($i === 0 && preg_match('/^\[instance = (\d+)\],?$/D', $line, $m)) {
                $instance = max(1, (int) $m[1]);
                continue;
            }
            if (!preg_match("/^([a-z][a-z0-9_]*) = '(.*)',?$/D", $line, $m)) continue;
            $out[$instance][$m[1]] = (isset($out[$instance]) && array_key_exists($m[1], $out[$instance])) ? null : $m[2];
        }
        return $out;
    }

    /** A log ts (YYYYMMDDHHMMSS, log zone) as 'Y-m-d H:i:s' in the clock zone, or null. */
    private function toClock($ts)
    {
        if (!is_string($ts) || !preg_match('/^\d{14}$/D', $ts)) return null;
        $d = \DateTimeImmutable::createFromFormat('!YmdHis', $ts, $this->logZone);
        if ($d === false || $d->format('YmdHis') !== $ts) return null;
        return $d->setTimezone($this->clockZone)->format('Y-m-d H:i:s');
    }
}
