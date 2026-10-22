<?php
namespace INSPIRE\UniversalValidator;

use INSPIRE\UniversalValidator\Scan\ScanDb;
use INSPIRE\UniversalValidator\Scan\SourceFence;

/**
 * Every value a field held on one record, event and instance, read from
 * REDCap's own log, for @UVWRITEONCE: which value was saved first, who saved
 * each later one, and when.
 *
 * THE LOG. The same rows ValueStamps reads (the project's redcap_log_event
 * table, allowlisted by SourceFence::resolveTable), newest first in pages of
 * PAGE rows, with the record compared as bytes. Each INSERT or UPDATE row of
 * object type redcap_data is one save: the user in `user`, the time in ts
 * (YYYYMMDDHHMMSS, the server's wall-clock time), and what the save wrote in
 * data_values (ValueStamps::parseDataValues). A DELETE row for the record
 * deletes everything it held, so reading stops there: a record deleted and
 * made again under the same name starts a new history.
 *
 * A HISTORY READ IN PART IS NO HISTORY. The first save of a value is the
 * oldest row, so a record whose log runs past MAX_ROWS_PER_RECORD rows before
 * its start (or its last DELETE) answers "capped", and the caller does not
 * judge it. Only the fields named when the reader is made are kept.
 *
 * PHP 7.4.
 */
final class ValueHistory
{
    /** Rows read for one record at most. */
    const MAX_ROWS_PER_RECORD = 5000;
    /** Rows per query. */
    const PAGE = 500;
    /** Records kept in memory; a scan asks record by record. */
    const RECORDS_KEPT = 16;

    /** @var ScanDb */
    private $db;
    private $pid;
    /** @var string an allowlisted log table name */
    private $table;
    /** @var array<string,true> the fields kept */
    private $fields;
    /** @var \DateTimeZone the zone the log's ts is written in */
    private $logZone;
    /** @var \DateTimeZone the zone the answer's times are written in */
    private $clockZone;
    /** record => state (read|capped|none|unknown) once the record was read */
    private $state = [];
    /** record => event id => instance => field => list of [ts, user, value|null], newest first */
    private $saves = [];
    /** record => true, in the order the records were first read */
    private $kept = [];

    private function __construct(ScanDb $db, $pid, $table, array $fields, \DateTimeZone $logZone, \DateTimeZone $clockZone)
    {
        $this->db = $db;
        $this->pid = $pid;
        $this->table = $table;
        $this->fields = array_fill_keys(array_map('strval', $fields), true);
        $this->logZone = $logZone;
        $this->clockZone = $clockZone;
    }

    /**
     * A reader for this project's log that keeps $fields, or null when the
     * log table cannot be resolved (or its name could not be read).
     */
    public static function forProject(ScanDb $db, $pid, array $fields, \DateTimeZone $logZone, \DateTimeZone $clockZone)
    {
        try {
            $table = SourceFence::resolveTable($db, $pid);
        } catch (\Throwable $e) {
            return null;
        }
        return $table === null ? null : new self($db, $pid, $table, $fields, $logZone, $clockZone);
    }

    /**
     * The saves of $field on this record, event and instance, oldest first.
     *
     * @return array{state:string, saves:list<array{at:?string, ts:string, user:string, value:?string}>}
     *   state "read" when the record's log was read to its start or its last
     *   DELETE; "capped" when it runs past MAX_ROWS_PER_RECORD rows; "none"
     *   when the log holds no row for the record; "unknown" when the log could
     *   not be read, or the field was not named when the reader was made.
     *   saves is empty unless the state is "read". value is null for a value
     *   the log does not say plainly (ValueStamps::parseDataValues); at is ts
     *   as 'Y-m-d H:i:s' in the clock zone, or null when ts is not a time.
     */
    public function saves($record, $eventId, $instance, $field)
    {
        $record = (string) $record;
        $field = (string) $field;
        if (!isset($this->fields[$field])) return ['state' => 'unknown', 'saves' => []];
        $this->keep($record);
        if (!isset($this->state[$record])) $this->state[$record] = $this->read($record);
        $state = $this->state[$record];
        if ($state !== 'read') return ['state' => $state, 'saves' => []];
        $eventId = (string) (int) $eventId;
        $instance = max(1, (int) $instance);
        $rows = isset($this->saves[$record][$eventId][$instance][$field]) ? $this->saves[$record][$eventId][$instance][$field] : [];
        $out = [];
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            list($ts, $user, $value) = $rows[$i];
            $out[] = ['at' => $this->toClock($ts), 'ts' => $ts, 'user' => $user, 'value' => $value];
        }
        return ['state' => 'read', 'saves' => $out];
    }

    /** The log time (YYYYMMDDHHMMSS, log zone) that a clock-zone date starts at, or null. */
    public function logTimeOf($date)
    {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) return null;
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->clockZone);
        if ($d === false || $d->format('Y-m-d') !== $date) return null;
        return $d->setTimezone($this->logZone)->format('YmdHis');
    }

    /** How many records are held in memory (for tests). */
    public function recordsHeld()
    {
        return count($this->kept);
    }

    /** Hold $record, dropping the record read longest ago past RECORDS_KEPT. */
    private function keep($record)
    {
        if (isset($this->kept[$record])) return;
        $this->kept[$record] = true;
        if (count($this->kept) <= self::RECORDS_KEPT) return;
        reset($this->kept);
        $old = (string) key($this->kept);
        unset($this->kept[$old], $this->state[$old], $this->saves[$old]);
    }

    /** Read the record's log, newest first, into $saves. Returns the record's state. */
    private function read($record)
    {
        $this->saves[$record] = [];
        $cursor = null;
        $rows = 0;
        $any = false;
        while (true) {
            $sql = 'SELECT log_event_id, ts, event_id, data_values, CAST(pk AS BINARY) AS p, `user`, event FROM ' . $this->table
                 . ' WHERE project_id = ? AND pk = ? AND object_type = \'redcap_data\' AND event IN (\'INSERT\', \'UPDATE\', \'DELETE\')';
            $params = [$this->pid, $record];
            if ($cursor !== null) {
                $sql .= ' AND log_event_id < ?';
                $params[] = $cursor;
            }
            $sql .= ' ORDER BY log_event_id DESC LIMIT ' . self::PAGE;
            try {
                $page = $this->db->select($sql, $params);
            } catch (\Throwable $e) {
                $this->saves[$record] = [];
                return 'unknown';
            }
            if (!is_array($page)) {
                $this->saves[$record] = [];
                return 'unknown';
            }
            $n = 0;
            foreach ($page as $row) {
                $n++;
                $id = isset($row[0]) ? (string) $row[0] : '';
                if ($id === '' || !ctype_digit($id)) {
                    $this->saves[$record] = [];
                    return 'unknown';
                }
                $cursor = $id;
                // pk compared as bytes: the column's collation may match "abc" for "ABC".
                if (!isset($row[4]) || (string) $row[4] !== $record) continue;
                $any = true;
                // A DELETE ends the history: what came before belongs to a record that is gone.
                if (isset($row[6]) && (string) $row[6] === 'DELETE') return 'read';
                $event = isset($row[2]) && $row[2] !== null && $row[2] !== '' ? (string) (int) $row[2] : null;
                if ($event === null) continue;
                $ts = isset($row[1]) ? (string) $row[1] : '';
                $user = isset($row[5]) && $row[5] !== null ? (string) $row[5] : '';
                foreach (ValueStamps::parseDataValues(isset($row[3]) ? $row[3] : '') as $inst => $values) {
                    foreach ($values as $f => $v) {
                        if (!isset($this->fields[$f])) continue;
                        $this->saves[$record][$event][$inst][$f][] = [$ts, $user, $v];
                    }
                }
            }
            $rows += $n;
            if ($n < self::PAGE) return $any ? 'read' : 'none';
            if ($rows >= self::MAX_ROWS_PER_RECORD) {
                $this->saves[$record] = [];
                return 'capped';
            }
        }
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
