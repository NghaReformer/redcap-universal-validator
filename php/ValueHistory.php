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
 * table, allowlisted by SourceFence::resolveTable), one record and event at a
 * time, newest first in pages of PAGE rows, with the record compared as bytes.
 * Each INSERT or UPDATE row of object type redcap_data is one save: the user
 * in `user`, the time in ts (YYYYMMDDHHMMSS, the server's wall-clock time),
 * and what the save wrote in data_values (parseRow).
 *
 * DELETES. A row whose description says the whole record was deleted
 * ("Delete record", or "Delete record (API)") ends the history in a project
 * with one arm: a record deleted and made again under the same name starts a
 * new history. In a project with more than one arm (where a record can be
 * deleted from one arm only), that row and any DELETE that names an arm make
 * the history "unknown". So does a DELETE of this event's own data, or one
 * that names no event: the reader cannot tell what it removed. A DELETE of
 * another event's data is no part of this event's history.
 *
 * ERASED DATA. "Erase all data" (and deleting every record when a project
 * moves to production) writes one DELETE row for the whole project, with the
 * project id as its pk and no event, rather than one per record. Every
 * history starts after the newest such row (eraseFloor): a record made again
 * under a name the erased data used does not inherit its saves. A row of a
 * record named like the project id that deletes the whole record says so in
 * its description and is not taken for an erase.
 *
 * A RENAMED RECORD. An UPDATE row that writes the record id field names the
 * record's new name. The saves made under its old name are not read, so the
 * history is "unknown". Such a row is read whatever event it names.
 *
 * A ROW WITH NO EVENT that names a kept field makes the history "unknown"
 * too: it may hold the first save, and it cannot be placed.
 *
 * A HISTORY READ IN PART IS NO HISTORY. The first save of a value is the
 * oldest row, so a record and event whose log runs past MAX_ROWS_PER_RECORD
 * rows before its start (or its last whole-record DELETE) answers "capped",
 * and the caller does not judge it. A history of exactly that many rows is
 * read in full. Only the fields named when the reader is made are kept.
 *
 * PHP 7.4.
 */
final class ValueHistory
{
    /** Rows read for one record at most. */
    const MAX_ROWS_PER_RECORD = 5000;
    /** Rows per query. */
    const PAGE = 500;
    /** Histories (one record and event each) kept in memory; a scan asks record by record. */
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
    /** @var array<string,int> every field of the project => its place in the data dictionary */
    private $order;
    /** @var bool the project has more than one arm */
    private $multiArm;
    /** @var array<string,true> the Text and Notes fields of the project, whose value may hold a line break */
    private $freeText;
    /** @var string|null the record id field, whose line in an UPDATE row is a rename */
    private $idField;
    /** @var string|null|false the id of the newest project-wide erase row: null for none, false when it could not be read; unset until read */
    private $eraseFloor;
    /** @var bool whether eraseFloor was read */
    private $eraseRead = false;
    /** history key => state (read|capped|none|unknown) once the history was read */
    private $state = [];
    /** history key => instance => field => list of [ts, user, value|null], newest first */
    private $saves = [];
    /** history key => true, in the order the histories were first read */
    private $kept = [];
    /** history key => why it is "unknown": read (the log could not be read), delete, noevent */
    private $why = [];

    private function __construct(ScanDb $db, $pid, $table, array $fields, \DateTimeZone $logZone, \DateTimeZone $clockZone, array $opts)
    {
        $this->db = $db;
        $this->pid = $pid;
        $this->table = $table;
        $this->fields = array_fill_keys(array_map('strval', $fields), true);
        $this->logZone = $logZone;
        $this->clockZone = $clockZone;
        $this->order = isset($opts['order']) && is_array($opts['order']) ? $opts['order'] : [];
        $this->multiArm = !empty($opts['multiArm']);
        $this->freeText = isset($opts['freeText']) && is_array($opts['freeText']) ? $opts['freeText'] : [];
        $this->idField = isset($opts['recordIdField']) && is_string($opts['recordIdField']) && preg_match('/^[a-z][a-z0-9_]*$/D', $opts['recordIdField'])
            ? $opts['recordIdField'] : null;
    }

    /**
     * A reader for this project's log that keeps $fields, or null when the
     * log table cannot be resolved (or its name could not be read). $opts:
     * order = every field of the project => its place in the data dictionary,
     * and freeText = its Text and Notes fields => true (parseRow refuses a
     * line out of that order after a free-text value); multiArm = the project
     * has more than one arm; recordIdField = the record id field (a rename).
     */
    public static function forProject(ScanDb $db, $pid, array $fields, \DateTimeZone $logZone, \DateTimeZone $clockZone, array $opts = [])
    {
        try {
            $table = SourceFence::resolveTable($db, $pid);
        } catch (\Throwable $e) {
            return null;
        }
        return $table === null ? null : new self($db, $pid, $table, $fields, $logZone, $clockZone, $opts);
    }

    /**
     * The saves of $field on this record, event and instance, oldest first.
     *
     * @return array{state:string, saves:list<array{at:?string, ts:string, user:string, value:?string}>}
     *   state "read" when the log of the record and event was read to its
     *   start or its last whole-record DELETE; "capped" when it runs past
     *   MAX_ROWS_PER_RECORD rows; "none" when the log holds no row for them;
     *   "unknown" when the log could not be read, holds a DELETE the reader
     *   cannot place or a row with no event that names a kept field, or the
     *   field was not named when the reader was made, or the record was
     *   renamed. "why" says which: read, delete, noevent, unnamed or rename.
     *   saves is empty unless the state is "read". value is null for a value
     *   the log does not say plainly (parseRow); at is ts as 'Y-m-d H:i:s' in
     *   the clock zone, or null when ts is not a time.
     */
    public function saves($record, $eventId, $instance, $field)
    {
        $record = (string) $record;
        $field = (string) $field;
        if (!isset($this->fields[$field])) return ['state' => 'unknown', 'saves' => [], 'why' => 'unnamed'];
        $eventId = (string) (int) $eventId;
        $key = $record . "\x1F" . $eventId;
        $this->keep($key);
        if (!isset($this->state[$key])) $this->state[$key] = $this->read($key, $record, $eventId);
        $state = $this->state[$key];
        if ($state === 'unknown') return ['state' => $state, 'saves' => [], 'why' => isset($this->why[$key]) ? $this->why[$key] : 'read'];
        if ($state !== 'read') return ['state' => $state, 'saves' => []];
        $instance = max(1, (int) $instance);
        $rows = isset($this->saves[$key][$instance][$field]) ? $this->saves[$key][$instance][$field] : [];
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

    /** How many histories are held in memory (for tests). */
    public function recordsHeld()
    {
        return count($this->kept);
    }

    /** Hold history $key, dropping the one read longest ago past RECORDS_KEPT. */
    private function keep($key)
    {
        if (isset($this->kept[$key])) return;
        $this->kept[$key] = true;
        if (count($this->kept) <= self::RECORDS_KEPT) return;
        reset($this->kept);
        $old = (string) key($this->kept);
        unset($this->kept[$old], $this->state[$old], $this->saves[$old], $this->why[$old]);
    }

    /**
     * One page of the record's rows for $eventId, newest first, after the
     * newest project-wide erase: deletes, rows with no event, and rows that
     * may write the record id field (a rename) included.
     */
    private function page($record, $eventId, $cursor, $limit)
    {
        $sql = 'SELECT log_event_id, ts, event_id, data_values, CAST(pk AS BINARY) AS p, `user`, event, description FROM ' . $this->table
             . ' WHERE project_id = ? AND pk = ? AND object_type = \'redcap_data\' AND event IN (\'INSERT\', \'UPDATE\', \'DELETE\')'
             . ' AND (event = \'DELETE\' OR event_id = ? OR event_id IS NULL' . ($this->idField !== null ? ' OR data_values LIKE ?' : '') . ')';
        $params = [$this->pid, $record, (int) $eventId];
        if ($this->idField !== null) $params[] = '%' . $this->idField . " = '%";
        if ($this->eraseFloor !== null && $this->eraseFloor !== false) {
            $sql .= ' AND log_event_id > ?';
            $params[] = $this->eraseFloor;
        }
        if ($cursor !== null) {
            $sql .= ' AND log_event_id < ?';
            $params[] = $cursor;
        }
        return $this->db->select($sql . ' ORDER BY log_event_id DESC LIMIT ' . (int) $limit, $params);
    }

    /** Whether a DELETE row says the whole record was deleted. */
    private static function wholeRecordDelete(array $row)
    {
        $d = isset($row[7]) ? trim((string) $row[7]) : '';
        return (bool) preg_match('/^delete record(?: \(api\))?$/iD', $d);
    }

    /** Whether a DELETE row's description names an arm. */
    private static function armDelete(array $row)
    {
        return (bool) preg_match('/\barm\b/i', isset($row[7]) ? (string) $row[7] : '');
    }

    /**
     * The id of the newest project-wide erase row (see ERASED DATA), read once
     * per reader: null when there is none, false when it could not be read.
     */
    private function eraseFloor()
    {
        if ($this->eraseRead) return $this->eraseFloor;
        $this->eraseRead = true;
        try {
            $rows = $this->db->select('SELECT log_event_id FROM ' . $this->table
                . ' WHERE project_id = ? AND pk = ? AND object_type = \'redcap_data\' AND event = \'DELETE\' AND event_id IS NULL'
                . ' AND (description IS NULL OR description NOT LIKE \'Delete record%\')'
                . ' ORDER BY log_event_id DESC LIMIT 1', [$this->pid, (string) $this->pid]);
        } catch (\Throwable $e) {
            return $this->eraseFloor = false;
        }
        if (!is_array($rows)) return $this->eraseFloor = false;
        if (!$rows) return $this->eraseFloor = null;
        $id = isset($rows[0][0]) ? (string) $rows[0][0] : '';
        return $this->eraseFloor = ($id !== '' && ctype_digit($id)) ? $id : false;
    }

    /**
     * Read one row into $saves[$key]. Returns null to read on, or what ends
     * the read: "read" at a whole-record DELETE; "delete" at a DELETE of this
     * event, of no event or of an arm (and at any whole-record DELETE in a
     * project of several arms); "rename" at an UPDATE that writes the record
     * id field; "noevent" at a row with no event that names a kept field,
     * which the reader cannot place.
     */
    private function take($key, $record, $eventId, array $row)
    {
        // pk compared as bytes: the column's collation may match "abc" for "ABC".
        if (!isset($row[4]) || (string) $row[4] !== $record) return null;
        $event = isset($row[2]) && $row[2] !== null && $row[2] !== '' ? (string) (int) $row[2] : null;
        $verb = isset($row[6]) ? strtoupper((string) $row[6]) : '';
        if ($verb === 'DELETE') {
            $whole = self::wholeRecordDelete($row);
            // another event's form data: not this event's history
            if ($event !== null && $event !== $eventId && !$whole && !self::armDelete($row)) return null;
            return !$this->multiArm && $whole ? 'read' : 'delete';
        }
        $parsed = self::parseRow(isset($row[3]) ? $row[3] : '', $this->order, $this->freeText);
        if ($verb === 'UPDATE' && $this->idField !== null) {
            foreach ($parsed as $values) if (array_key_exists($this->idField, $values)) return 'rename';
        }
        if ($event === null) {
            foreach ($parsed as $values) foreach ($values as $f => $v) if (isset($this->fields[$f])) return 'noevent';
            return null;
        }
        if ($event !== $eventId) return null;
        $ts = isset($row[1]) ? (string) $row[1] : '';
        $user = isset($row[5]) && $row[5] !== null ? (string) $row[5] : '';
        foreach ($parsed as $inst => $values) {
            foreach ($values as $f => $v) {
                if (!isset($this->fields[$f])) continue;
                $this->saves[$key][$inst][$f][] = [$ts, $user, $v];
            }
        }
        return null;
    }

    /** Read the log of one record and event, newest first, into $saves. Returns its state. */
    private function read($key, $record, $eventId)
    {
        $this->saves[$key] = [];
        if ($this->eraseFloor() === false) return 'unknown';
        $cursor = null;
        $rows = 0;
        $any = false;
        while (true) {
            try {
                $page = $this->page($record, $eventId, $cursor, self::PAGE);
            } catch (\Throwable $e) {
                $this->saves[$key] = [];
                return 'unknown';
            }
            if (!is_array($page)) {
                $this->saves[$key] = [];
                return 'unknown';
            }
            $n = 0;
            foreach ($page as $row) {
                $n++;
                $id = isset($row[0]) ? (string) $row[0] : '';
                if ($id === '' || !ctype_digit($id)) {
                    $this->saves[$key] = [];
                    return 'unknown';
                }
                $cursor = $id;
                if (isset($row[4]) && (string) $row[4] === $record) $any = true;
                $end = $this->take($key, $record, $eventId, $row);
                if ($end === 'read') return 'read';
                if ($end !== null) {
                    $this->saves[$key] = [];
                    $this->why[$key] = $end;
                    return 'unknown';
                }
            }
            $rows += $n;
            if ($n < self::PAGE) return $any ? 'read' : 'none';
            if ($rows >= self::MAX_ROWS_PER_RECORD) {
                // A history of exactly the cap is whole: what lies just past
                // it decides, a whole-record DELETE or nothing at all.
                try {
                    $next = $this->page($record, $eventId, $cursor, 1);
                } catch (\Throwable $e) {
                    $next = null;
                }
                if (is_array($next) && !$next) return 'read';
                if (is_array($next) && isset($next[0]) && is_array($next[0]) && isset($next[0][4]) && (string) $next[0][4] === $record
                        && isset($next[0][6]) && strtoupper((string) $next[0][6]) === 'DELETE' && !$this->multiArm && self::wholeRecordDelete($next[0])) {
                    return 'read';
                }
                $this->saves[$key] = [];
                return 'capped';
            }
        }
    }

    /**
     * One row's data_values as instance => field => value. REDCap writes one
     * "field = 'value'" line per field joined by ",\n" (a checkbox as
     * "field(code) = checked"), after an "[instance = N]" line for a
     * repeating instance past the first. A value runs until a line that ends
     * with "'," (or the row's end), so a notes value that spans lines is read
     * whole. These map to null (the log does not say plainly what was saved):
     *  - a value that does not end with a quote, or a field written twice;
     *  - every field of a row with a line of no known shape;
     *  - the fields of a row from its first free-text value ($freeText,
     *    field => true: a field whose value a person can type) to its end,
     *    when a line after that value is out of the data dictionary's order
     *    ($order, field => place): a value typed to look like lines of other
     *    fields ("x',\nsite = 'C") reads as more entries. It is caught here
     *    unless every such line comes later in the dictionary and the row
     *    holds no line of it already. The lines before the first free-text
     *    value cannot be typed, so they are kept, and so is a row whose line
     *    out of order follows no free-text value (the dictionary may have
     *    changed since the row was written, or the save wrote its fields in
     *    another order). With no $freeText given, every field counts.
     * Each line is looked at once, so a long value costs one pass.
     */
    public static function parseRow($text, array $order = [], array $freeText = [])
    {
        $out = [];
        if (!is_string($text) || $text === '') return $out;
        $instance = 1;
        $blocks = [];   // [field, lines, is a checkbox line, whether the last line ends the value]
        $stray = false;
        foreach (preg_split('/\r?\n/', $text) as $i => $line) {
            if ($i === 0 && preg_match('/^\[instance = (\d+)\],?$/D', $line, $m)) {
                $instance = max(1, (int) $m[1]);
                continue;
            }
            $last = count($blocks) - 1;
            if ($last >= 0 && !$blocks[$last][2] && !$blocks[$last][3]) {
                $blocks[$last][1][] = $line;   // the value goes on
                $blocks[$last][3] = substr($line, -2) === "',";
                continue;
            }
            if (preg_match('/^([a-z][a-z0-9_]*)\([^)]*\) = (?:checked|unchecked),?$/D', $line, $m)) {
                $blocks[] = [$m[1], [$line], true, true];
            } elseif (preg_match("/^([a-z][a-z0-9_]*) = '/", $line, $m)) {
                $blocks[] = [$m[1], [$line], false, substr($line, -2) === "',"];
            } elseif ($line !== '') {
                $stray = true;
            }
        }
        $place = -1;
        $prev = null;
        $firstFree = null;   // the first block a person may have typed
        $cut = null;         // the first block the order check does not trust
        foreach ($blocks as $k => $b) {
            if ($cut === null && isset($order[$b[0]])) {
                $p = (int) $order[$b[0]];
                // a checkbox writes one line per option, so its own lines may follow it
                if (($p < $place || ($p === $place && !($b[2] && $prev === $b[0]))) && $firstFree !== null) $cut = $firstFree;
                $place = $p;
            }
            if ($firstFree === null && !$b[2] && (!$freeText || isset($freeText[$b[0]]))) $firstFree = $k;
            $prev = $b[0];
        }
        foreach ($blocks as $k => $b) {
            if ($b[2]) continue;
            $v = ($stray || ($cut !== null && $k >= $cut)) ? null
               : (preg_match("/^[a-z][a-z0-9_]* = '(.*)',?$/sD", implode("\n", $b[1]), $m) ? $m[1] : null);
            $out[$instance][$b[0]] = (isset($out[$instance]) && array_key_exists($b[0], $out[$instance])) ? null : $v;
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
