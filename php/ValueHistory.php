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
 * another event's data is no part of this event's history when its
 * description says it removed one form or one event ("Delete all record data
 * for single form", "... single event", "Delete all data on event"); a DELETE
 * of another event described
 * any other way may have removed the whole record, so it makes the history
 * "unknown" too.
 *
 * ERASED DATA. "Erase all data" (and deleting every record when a project
 * moves to production) writes one DELETE row for the whole project, with the
 * project id as its pk and no event, rather than one per record. Every
 * history starts after the newest such row (eraseFloor): a record made again
 * under a name the erased data used does not inherit its saves. A row of a
 * record named like the project id that deletes the whole record, or one
 * form or event of it, says so in its description and is not taken for an
 * erase; the project id is compared as bytes, so "１４９" is not "149".
 *
 * THE START OF A RECORD. When it is told the record id field, the reader
 * reads a history only when it holds the record's own beginning: an INSERT
 * row with a line for the record id field (REDCap's "Create record" in any of
 * its forms). Such a row is read whatever event it names; the rows read for
 * it match the record id field's line at the start of a line, so a field
 * named "prev_record_id" does not. A history without that row is "unknown"
 * ("start"): the record was renamed and its first saves are logged under its
 * old name, or it came with a copied project and has no log before the copy,
 * or an erase was placed after its creation. An UPDATE that writes the
 * record id field, as the first save of a record at another event may, is no
 * rename. The start is read from the row as written (startOf), not through
 * parseRow, so a field moved in the data dictionary since the record was
 * made, or a Notes value later in the row, does not hide it. A typed value
 * only adds lines after its own field's line, so the record id line counts
 * when it comes before every line of a Text or Notes field other than the
 * record id field itself; a row whose only record id line comes after one
 * may hold a typed copy of it, and its history is "unknown" ("startrow").
 *
 * A ROW WITH NO EVENT that names a kept field makes the history "unknown"
 * too: it may hold the first save, and it cannot be placed.
 *
 * A HISTORY READ IN PART IS NO HISTORY. The first save of a value is the
 * oldest row, so a record and event whose log runs past MAX_ROWS_PER_RECORD
 * rows before its start (or its last whole-record DELETE) answers "capped",
 * and the caller does not judge it. A history of exactly that many rows is
 * read in full. Only this event's rows, DELETE rows and rows with no event
 * count toward that cap; a record whose log holds more than MAX_ROWS_READ
 * rows of any kind before its start is "capped" too. Only the fields named
 * when the reader is made are kept.
 *
 * PHP 7.4.
 */
final class ValueHistory
{
    /** Rows of this event (with DELETE rows and rows with no event) read for one record at most. */
    const MAX_ROWS_PER_RECORD = 5000;
    /** Rows of any kind read for one record and event at most. */
    const MAX_ROWS_READ = 20000;
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
    /** @var string|null the record id field, whose line in an INSERT row is the record's start */
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
    /** history key => why it is "unknown": read (the log could not be read), delete, noevent, start, startrow */
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
     * has more than one arm; recordIdField = the record id field (THE START OF
     * A RECORD).
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
     *   field was not named when the reader was made, or the log does not
     *   hold the record's start, or holds it only after a typed value. "why"
     *   says which: read, delete, noevent, unnamed, start or startrow.
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
     * newest project-wide erase: deletes, rows with no event, and rows with a
     * line for the record id field (the record's start) included. That line
     * is matched at the start of data_values or after a line break, with the
     * field name's "_" escaped, so "prev_record_id" and "recordXid" do not
     * match.
     */
    private function page($record, $eventId, $cursor, $limit)
    {
        $sql = 'SELECT log_event_id, ts, event_id, data_values, CAST(pk AS BINARY) AS p, `user`, event, description FROM ' . $this->table
             . ' WHERE project_id = ? AND pk = ? AND object_type = \'redcap_data\' AND event IN (\'INSERT\', \'UPDATE\', \'DELETE\')'
             . ' AND (event = \'DELETE\' OR event_id = ? OR event_id IS NULL'
             . ($this->idField !== null ? ' OR data_values LIKE ? ESCAPE \'!\' OR data_values LIKE ? ESCAPE \'!\'' : '') . ')';
        $params = [$this->pid, $record, (int) $eventId];
        if ($this->idField !== null) {
            $line = str_replace('_', '!_', $this->idField) . " = '%";
            $params[] = $line;
            $params[] = "%\n" . $line;
        }
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

    /** Whether a DELETE row's description says it removed one form or one event of the record. */
    private static function partDelete(array $row)
    {
        $d = isset($row[7]) ? trim((string) $row[7]) : '';
        return (bool) preg_match('/^delete all (?:record data for single (?:form|event)|data on event)(?: \(api\))?$/iD', $d);
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
                . ' WHERE project_id = ? AND pk = ? AND CAST(pk AS BINARY) = ? AND object_type = \'redcap_data\' AND event = \'DELETE\' AND event_id IS NULL'
                . ' AND (description IS NULL OR (description NOT LIKE \'Delete record%\' AND description NOT LIKE \'Delete all record data for single%\' AND description NOT LIKE \'Delete all data on event%\'))'
                . ' ORDER BY log_event_id DESC LIMIT 1', [$this->pid, (string) $this->pid, (string) $this->pid]);
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
     * event, of no event or of an arm, at one of another event described as
     * neither one form nor one event, and at any whole-record DELETE in a
     * project of several arms; "noevent" at a row with no event that names a
     * kept field, which the reader cannot place. $started turns true at the
     * record's start, and false at an INSERT whose record id line may have
     * been typed, unless a start was met (THE START OF A RECORD).
     */
    private function take($key, $record, $eventId, array $row, &$started)
    {
        // pk compared as bytes: the column's collation may match "abc" for "ABC".
        if (!isset($row[4]) || (string) $row[4] !== $record) return null;
        $event = isset($row[2]) && $row[2] !== null && $row[2] !== '' ? (string) (int) $row[2] : null;
        $verb = isset($row[6]) ? strtoupper((string) $row[6]) : '';
        if ($verb === 'DELETE') {
            $whole = self::wholeRecordDelete($row);
            // another event's form or event data: not this event's history
            if ($event !== null && $event !== $eventId && !$whole && self::partDelete($row)) return null;
            return !$this->multiArm && $whole ? 'read' : 'delete';
        }
        if ($verb === 'INSERT' && $this->idField !== null && $started !== true) {
            $start = $this->startOf(isset($row[3]) ? $row[3] : '');
            if ($start !== null) $started = $start;
        }
        $parsed = self::parseRow(isset($row[3]) ? $row[3] : '', $this->order, $this->freeText);
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

    /** Whether a row counts toward MAX_ROWS_PER_RECORD: a DELETE, a row of $eventId, or one with no event. */
    private static function counted(array $row, $eventId)
    {
        if (isset($row[6]) && strtoupper((string) $row[6]) === 'DELETE') return true;
        if (!isset($row[2]) || $row[2] === null || $row[2] === '') return true;
        return (string) (int) $row[2] === $eventId;
    }

    /**
     * "read", or "unknown" when the reader knows the record id field and met
     * no start: "startrow" when it met an INSERT whose record id line may
     * have been typed ($started false), "start" when it met none (null).
     */
    private function started($key, $started)
    {
        if ($this->idField === null || $started === true) return 'read';
        $this->saves[$key] = [];
        $this->why[$key] = $started === false ? 'startrow' : 'start';
        return 'unknown';
    }

    /**
     * Whether an INSERT row's data_values holds the record's start, read from
     * the lines as written: true when a line "<record id field> = '<value>'"
     * that ends the value (with "'," or at the row's end) comes before any
     * line of a Text or Notes field other than the record id field
     * ($freeText; every field when none was given). An "[instance = N]"
     * first line, checkbox lines and lines of other fields that end their
     * value may come before it. False when the row's only record id line
     * comes after such a line, a line of no known shape or a value that runs
     * on, where a typed value may have written it; null when it has none.
     */
    private function startOf($text)
    {
        if (!is_string($text) || $text === '') return null;
        $lines = preg_split('/\r?\n/', $text);
        $last = count($lines) - 1;
        $head = $this->idField . " = '";
        $plain = true;    // no line a person may have typed so far
        $seen = false;
        foreach ($lines as $i => $line) {
            if ($i === 0 && preg_match('/^\[instance = \d+\],?$/D', $line)) continue;
            $ends = substr($line, $i === $last ? -1 : -2) === ($i === $last ? "'" : "',");
            if (strncmp($line, $head, strlen($head)) === 0) {
                if ($plain && $ends && strlen($line) >= strlen($head) + ($i === $last ? 1 : 2)) return true;
                $seen = true;
                $plain = false;
                continue;
            }
            if (!$plain) continue;
            if (preg_match('/^[a-z][a-z0-9_]*\([^)]*\) = (?:checked|unchecked),?$/D', $line)) continue;
            if ($ends && preg_match("/^([a-z][a-z0-9_]*) = '/", $line, $m) && $this->freeText && !isset($this->freeText[$m[1]])) continue;
            $plain = false;
        }
        return $seen ? false : null;
    }

    /** Read the log of one record and event, newest first, into $saves. Returns its state. */
    private function read($key, $record, $eventId)
    {
        $this->saves[$key] = [];
        if ($this->eraseFloor() === false) return 'unknown';
        $cursor = null;
        $rows = 0;
        $fetched = 0;
        $any = false;
        $started = null;
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
                if (++$fetched > self::MAX_ROWS_READ) {
                    $this->saves[$key] = [];
                    return 'capped';
                }
                $mine = isset($row[4]) && (string) $row[4] === $record;
                if ($mine) $any = true;
                if ($mine && self::counted($row, $eventId)) {
                    if ($rows >= self::MAX_ROWS_PER_RECORD) {
                        // A history of exactly the cap is whole: what lies
                        // just past it decides, a whole-record DELETE (or
                        // nothing at all, when the log ends here).
                        if (isset($row[6]) && strtoupper((string) $row[6]) === 'DELETE' && !$this->multiArm && self::wholeRecordDelete($row)) {
                            return $this->started($key, $started);
                        }
                        $this->saves[$key] = [];
                        return 'capped';
                    }
                    $rows++;
                }
                $end = $this->take($key, $record, $eventId, $row, $started);
                if ($end === 'read') return $this->started($key, $started);
                if ($end !== null) {
                    $this->saves[$key] = [];
                    $this->why[$key] = $end;
                    return 'unknown';
                }
            }
            if ($n < self::PAGE) return $any ? $this->started($key, $started) : 'none';
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
