<?php
namespace INSPIRE\UniversalValidator\Scan;

require_once __DIR__ . '/../Faults.php';

use INSPIRE\UniversalValidator\Faults;
use INSPIRE\UniversalValidator\Logic;

/**
 * The records that may hold a value, from an index the module keeps, for the
 * live @UVUNIQUE and @UVEXISTS lookups and the check after saving.
 *
 * WHY. FieldValues answers by reading every row of the searched field. REDCap
 * keeps no index on a value, so each of those rows is a separate read of the
 * data table: 3 to 9 s at 100,000 records on a test server with a cold
 * buffer pool, one disk read per record when the rows are not in memory. A
 * lookup through this index reads one key.
 *
 * WHAT IT HOLDS. uv_lookup_key has a row per (project, field, key, record),
 * where the key is a KEYED hash of Logic::lookupKey(value, letter case folded,
 * the field's number mark): the first 16 bytes of an HMAC-SHA-256 made with
 * the module's secret and the project id (FRM-7). Up to key version 1 it was
 * a plain MD5 of the value, which anyone who can read the table can test
 * guesses against (a phone number, a national id), beside the record that
 * holds it; the module's log has never stored a value that way. A caller
 * passes the secret as the option "keySecret". Without one the index answers
 * nothing and builds nothing, and the lookups read the data table.
 * Folding always gives a superset of a lookup that keeps
 * letter case, and a hash that collides only adds a record. So the records
 * this class returns are a SUPERSET of the records that hold the value, and
 * the caller confirms them against the data table rows of those records
 * (FieldValues::recordsAmong) before it uses one.
 *
 * COMPOSITE ENTRIES. Besides one entry per field, an entry can key a value
 * together with other fields of the same entry of the record and, as asked,
 * its event or its Data Access Group (compositeCandidates()). Its name starts
 * with "#", which no REDCap field name does, and its definition (the fields,
 * their number marks, the scope, which fields repeat in which event) is kept
 * in uv_lookup_def, so the cron and the sync can derive its keys without the
 * rule that asked for it. A value whose other parts all hold a few values (an
 * arm code with a site) then narrows to one stratum: the records that share
 * every part, and no others.
 *
 * A COMPOSITE BUILD CAN PAUSE. It goes through the records BATCH at a time,
 * listing the first field's records or walking REDCap's record index
 * (writeCompositeKeys()). A cron that reaches its deadline keeps its
 * place in uv_lookup_def.build_cursor and the next run goes on from there.
 * Meanwhile the sync keeps the records already indexed current, as it does for
 * a build that runs (keptOf()), and a reset of the project starts it again.
 *
 * BUILT ONCE, BY THE CRON WHEN LARGE. A field's first build reads all its
 * rows. A lookup builds a field of up to LIVE_BUILD_ROWS rows itself; a
 * larger one it marks as wanted (uv_lookup_field.wanted_at) and the module's
 * cron builds it (maintain()), letting the project's lock go while it reads
 * the data table, so the project's other fields keep answering. Until then
 * the index does not answer for it. A lookup never builds a larger field,
 * however long it has waited (UNQ-3): that read is the cron's, and a request
 * that made it kept a person waiting for a minute at 400,000 values.
 *
 * A REQUEST READS A BOUNDED STRETCH OF THE LOG. A project no lookup asked
 * about for days has days of the shared log table to read. Above MAX_SYNC_IDS
 * log positions a lookup does not read them and gets no answer; the cron
 * brings such a project up to date (maintain()), and keeps every project
 * with indexed fields within that reach.
 * The cron also rebuilds each field REFRESH_BEFORE seconds before it reaches
 * REBUILD_AFTER, and drops the keys of a field no lookup asked for in
 * UNUSED_AFTER seconds.
 *
 * GENERATIONS. Each build writes its keys with a new generation number
 * (`gen`). A rebuild of a field that answers keeps the old keys while it
 * writes the new ones, so the field answers from both (a superset) until the
 * build ends and the keys of older generations go. A sync that indexes a
 * record again while a build runs writes the build's generation. A reset
 * (below) raises the generation, so a build that was running when it happened
 * does not mark the field built.
 *
 * KEPT CURRENT FROM THE LOG. Every value REDCap saves writes a row to the
 * project's log table, with the record in `pk`. Before it answers, a lookup
 * reads the records the log names since the last lookup and indexes them
 * again from the data table, for every field that answers or is being built.
 * A record is indexed again only when the log shows a row for it above the
 * one it was last indexed at (uv_lookup_record).
 *
 * THE WINDOW REACHES BACK OVERLAP SECONDS. A log row can become visible after
 * a row with a higher id (an import that saves many records in one
 * transaction), so a position the log has passed is not proof that nothing
 * below it will appear. The sync row keeps (time, position, count) samples
 * at most SAMPLE_EVERY apart for the last OVERLAP seconds, where count is the
 * number of the project's log rows between a sample and the one before it.
 * Each lookup counts those ranges again and reads a range only when its count
 * changed, then reads what is new above the last position. A transaction that
 * stays open longer than OVERLAP is not seen; REBUILD_AFTER bounds how long
 * such a change, or a write that bypassed REDCap's log, can be missed.
 *
 * RESET, THEN REBUILT, when the index cannot be shown current: the project's
 * data or log table changed, the log was pruned past the window, too many
 * records changed at once, or the field's number mark or this class's key
 * version changed.
 *
 * A CHANGE TO THE DATA DICTIONARY (a metadata row in the log) resets the
 * fields it can have touched (metaFields()): the field the row names, and
 * every indexed field the dictionary no longer holds. Every field is reset
 * when the row names no field the dictionary holds now (a field deleted or
 * renamed away, an instrument, an upload of the whole dictionary), or more
 * than META_FIELDS fields were changed. Editing the label of one field no
 * longer stops the lookups of all the others (UNQ-3).
 *
 * A RENAMED RECORD is logged under one of its two ids; the other is read
 * from the row's values (renamedSince()), so both are indexed again (UNQ-6).
 *
 * NULL WHEN IT CANNOT ANSWER. The tables are missing, the log or data table
 * cannot be resolved, another request holds the project's lock for longer
 * than LOCK_WAIT, the field is not built yet and this request may not build
 * it (too large, being built elsewhere, or this request has used its build),
 * the project's log is further ahead than a request reads, or any read
 * fails. lastProblem() says which (UNQ-9). The caller then reads the data
 * table when the field is small enough for that, so the index only ever
 * saves work.
 *
 * PHP 7.4.
 */
final class LookupIndex
{
    /**
     * Bump when Logic::lookupKey changes what a value's key is, or how the
     * key is hashed. 2: a keyed hash in place of MD5 (FRM-7). A field's spec
     * starts with this number and the secret's id (specHead), so the keys of
     * another version or another secret stop answering at once, are cleared
     * by the next cron pass (maintain) and are built again when a lookup asks.
     */
    const KEY_VERSION = 2;
    /** Fields a composite entry keys besides its first. */
    const MAX_PARTS = 8;
    /** Seconds the log is read back behind the newest position. */
    const OVERLAP = 3600;
    /** A (time, position, count) sample is kept at most this often. */
    const SAMPLE_EVERY = 450;
    /** A field is built again from the data table after this many seconds. */
    const REBUILD_AFTER = 604800;
    /** The cron rebuilds a field this many seconds before REBUILD_AFTER, so no lookup waits for it. */
    const REFRESH_BEFORE = 86400;
    /** A lookup builds a field of up to this many data rows itself; the cron builds larger ones. */
    const LIVE_BUILD_ROWS = 20000;
    /** With no cron run for this long, a lookup that waits for the cron says the cron is not running. */
    const CRON_GRACE = 900;
    /** Log positions a lookup reads to bring a project up to date; the cron reads further. */
    const MAX_SYNC_IDS = 500000;
    /** The cron brings up to date at most this many projects a run. */
    const CRON_SYNC_PROJECTS = 200;
    /** More fields than this named by data dictionary changes in one window reset every field. */
    const META_FIELDS = 50;
    /** Renamed records read from one stretch of the log; more reset the fields, as MAX_CHANGED does. */
    const MAX_RENAMED = 2000;
    /**
     * Every read of a stretch of the log goes through the primary key
     * (log_event_id), so its cost is that of the positions in the stretch,
     * whatever the statistics say (UNQ-7). REDCap's log table has no index
     * on (project_id, log_event_id); left to choose, a server may read every
     * log row the project ever wrote through (project_id, user) instead.
     */
    const BY_POSITION = ' FORCE INDEX (PRIMARY)';
    /** A build that started this long ago and never ended is taken as dead. */
    const BUILD_STALE = 3600;
    /** The cron drops the keys of a field no lookup asked for in this long. */
    const UNUSED_AFTER = 2592000;
    /** A field's last-asked time is written at most this often. */
    const ASKED_EVERY = 86400;
    /** More changed records than this in one window rebuild the project's fields. */
    const MAX_CHANGED = 20000;
    /** More records to index again than this rebuild them too. */
    const MAX_REINDEX = 5000;
    /** Seconds a lookup waits for another request's sync of the same project. */
    const LOCK_WAIT = 2;
    /** Seconds the cron waits for a project's lock. */
    const CRON_LOCK_WAIT = 10;
    /** Fields a lookup builds in one request; the others read the data table meanwhile. */
    const BUILDS_PER_REQUEST = 1;
    /** Records per statement when indexing again. */
    const BATCH = 500;
    /** Keys per INSERT. */
    const INSERT_ROWS = 2000;
    /** Rows per DELETE when keys are cleared. */
    const DELETE_ROWS = 20000;
    /** Records a composite build lists from one read of the data table (recordsAfter()). */
    const LIST_RECORDS = 50000;

    private $module;
    /** @var ModuleDb */
    private $db;
    private $builds = 0;
    /** @var int fields this object finished building */
    private $built = 0;
    /** @var int data rows a lookup builds a field of itself */
    private $liveBuildRows;
    /** @var int records a composite build lists from one read */
    private $listRecords;
    /** @var int when the cron last ran (Unix time), 0 when it never has */
    private $cronAt;
    /** @var array pid => the sync row, once this request synced that project */
    private $rows = [];
    /** @var array pid => ['data' => table, 'log' => table], or false */
    private $sources = [];
    /** @var array log table => bool: an index leads with `ts` */
    private $tsIndexed = [];
    /** @var string|null why the last candidates() gave no answer (lastProblem()) */
    private $why = null;
    /** @var array pid => the project's record ID field, or '' when it could not be read */
    private $pkFields = [];
    /** @var string|null the secret the keys are hashed with, null when the caller has none */
    private $secret;

    /**
     * @param object $module anything with the framework's query($sql, $params)
     * @param array $options 'liveBuildRows' => int, in place of LIVE_BUILD_ROWS;
     *                       'cronAt' => int, when the cron last ran;
     *                       'keySecret' => string, what the keys are hashed with (FRM-7)
     *                       'listRecords' => int, in place of LIST_RECORDS
     */
    public function __construct($module, array $options = [])
    {
        $this->module = $module;
        $this->db = new ModuleDb($module);
        $this->liveBuildRows = isset($options['liveBuildRows']) ? (int) $options['liveBuildRows'] : self::LIVE_BUILD_ROWS;
        $this->cronAt = isset($options['cronAt']) ? (int) $options['cronAt'] : 0;
        $this->listRecords = isset($options['listRecords']) ? max(1, (int) $options['listRecords']) : self::LIST_RECORDS;
        $this->secret = (isset($options['keySecret']) && is_string($options['keySecret']) && strlen($options['keySecret']) >= self::SECRET_MIN)
            ? $options['keySecret'] : null;
    }

    /** Whether the index can work at all: a module to query and a secret to key with (FRM-7). */
    private function usable()
    {
        return $this->secret !== null && is_object($this->module) && is_callable([$this->module, 'query']);
    }

    /**
     * The records of $pid with a value in one of $fieldMarks (field => number
     * mark, null for text) whose key may equal $value's; a superset the
     * caller confirms. Null when the index cannot answer (class docblock).
     *
     * @return string[]|null
     */
    public function candidates($pid, array $fieldMarks, $value)
    {
        $pid = (int) $pid;
        $this->why = null;
        if ($pid <= 0 || !$fieldMarks || !is_object($this->module) || !is_callable([$this->module, 'query'])) {
            $this->why = 'error';
            return null;
        }
        // No secret to hash with: no key can be made or matched (FRM-7).
        if ($this->secret === null) {
            $this->why = 'error';
            return null;
        }
        $entries = [];
        foreach ($fieldMarks as $f => $mark) $entries[(string) $f] = $this->plainEntry($mark);
        try {
            $src = $this->sources($pid);
            if ($src === null) {
                $this->why = 'tables';
                return null;
            }
            if (!$this->lock($pid, self::LOCK_WAIT)) {
                $this->why = 'lock';
                return null;
            }
            try {
                if (!$this->prepare($pid, $src, $entries, false)) {
                    if ($this->why === null) $this->why = 'building';
                    return null;
                }
                return $this->keysFor($pid, $fieldMarks, $value);
            } finally {
                $this->unlock($pid);
            }
        } catch (\Throwable $e) {
            Faults::swallow($e, 'LookupIndex::candidates');
            $this->why = 'error';
            return null;
        }
    }

    /**
     * Why the last candidates() gave no answer, or null when it answered:
     * 'tables' (the project's data or log table could not be resolved),
     * 'lock' (another request held the project, or the lock was refused),
     * 'cron' (the field is the cron's to build and it has not yet),
     * 'no-cron' (the same, and no cron has run for CRON_GRACE),
     * 'cooldown' (many records changed at once; builds wait),
     * 'building' (the field is being built, or this request has used its
     * build), 'behind' (the log is further ahead than a request reads; the
     * cron brings the project up to date; 'no-cron' when none has run for
     * CRON_GRACE), 'error' (a read failed, the module's own tables
     * included).
     *
     * @return string|null
     */
    public function lastProblem()
    {
        return $this->why;
    }

    /**
     * The records of $pid whose entry of $def holds the key of $parts, in byte
     * order, those after $after only, at most $limit of them (0: all). A
     * superset the caller confirms, as candidates() returns. Null when the
     * index cannot answer (lastProblem() says why), or $def is not one
     * compositeDef() accepts. The
     * caller vouches for 'rep': a field and event left out of it are read as
     * the event's row, so instances above 1 there get no key.
     *
     * $def:   'slot' => the field the value is in; 'with' => the other fields
     *         read from the same entry, in order; 'marks' => field => number
     *         mark ('point', 'comma' or null); 'scope' => 'project', 'event'
     *         or 'dag'; 'rep' => field => [event id => bucket] for each
     *         event where the field repeats: its form's name when the form
     *         repeats there, '' when the event repeats. Fields of one value
     *         per entry only: a checkbox has a row per ticked option.
     * $parts: 'value' => the value; 'with' => field => value (blank when
     *         missing); 'event' => the event id (scope event); 'dag' => the
     *         group id, '' for none (scope dag).
     *
     * The entries keyed are the ones the scan reads (ScanRuns::recordContexts()):
     * the event's row, and each repeating instance merged over it. A "with"
     * field is read from the entry, so from the event's row when it does not
     * repeat, and blank in an entry of another bucket (compositeMaterials()).
     *
     * @return string[]|null
     */
    public function compositeCandidates($pid, array $def, array $parts, $after = '', $limit = 0)
    {
        $pid = (int) $pid;
        $def = self::compositeDef($def);
        $this->why = null;
        if ($pid <= 0 || $def === null || !$this->usable()) {
            $this->why = 'error';
            return null;
        }
        $value = isset($parts['value']) && is_scalar($parts['value']) ? (string) $parts['value'] : '';
        if (Logic::lookupTrim($value) === '') return [];
        $with = isset($parts['with']) && is_array($parts['with']) ? $parts['with'] : [];
        $event = isset($parts['event']) && is_scalar($parts['event']) ? (string) $parts['event'] : '';
        $dag = isset($parts['dag']) && is_scalar($parts['dag']) ? (string) $parts['dag'] : '';
        $name = self::compositeName($def);
        try {
            $src = $this->sources($pid);
            if ($src === null) {
                $this->why = 'tables';
                return null;
            }
            if (!$this->lock($pid, self::LOCK_WAIT)) {
                $this->why = 'lock';
                return null;
            }
            try {
                if (!$this->prepare($pid, $src, [$name => $this->compositeEntry($def)], false)) {
                    if ($this->why === null) $this->why = 'building';
                    return null;
                }
                $key = $this->compositeKeyHex($pid, $name, self::compositeMaterial($def, $value, $with, $event, $dag));
                return $this->recordsOf($pid, $name, $key, (string) $after, (int) $limit);
            } finally {
                $this->unlock($pid);
            }
        } catch (\Throwable $e) {
            Faults::swallow($e, 'LookupIndex::compositeCandidates');
            $this->why = 'error';
            return null;
        }
    }

    /**
     * The cron's work, until the Unix time $until: build the fields lookups
     * wanted and could not build, rebuild a field REFRESH_BEFORE short of
     * REBUILD_AFTER, start again a build that died, and drop the keys of a
     * field no lookup asked for in UNUSED_AFTER (or whose project is gone).
     * One field at a time, each under its project's lock, which a build lets
     * go while it reads the data table. Then, with time left, the projects
     * whose log is further ahead than a lookup reads are brought up to date
     * (syncBehind()).
     *
     * 'why' counts the reasons work was left waiting, by lastProblem()'s
     * words, so a run that can never do its work says so (UNQ-9): a lock
     * the server refuses is counted even when no field is waiting.
     *
     * @return array ['built' => int, 'dropped' => int, 'waiting' => int, 'left' => int, 'synced' => int, 'why' => array]
     */
    public function maintain($until)
    {
        $out = ['built' => 0, 'dropped' => 0, 'waiting' => 0, 'left' => 0, 'synced' => 0, 'why' => []];
        if (!is_object($this->module) || !is_callable([$this->module, 'query'])) return $out;
        // Without the secret nothing can be built, and nothing is dropped
        // either: a secret that cannot be read for one pass must not cost
        // every project its index.
        if ($this->secret === null) return $out;
        $now = time();
        $note = function ($why) use (&$out) {
            $out['why'][$why] = isset($out['why'][$why]) ? $out['why'][$why] + 1 : 1;
        };
        // A lock nobody holds: refused here, it is refused to every lookup.
        if (!$this->lock(0, 0)) $note('lock');
        else $this->unlock(0);
        // An entry keyed another way (an older key version, the MD5 keys of
        // version 1 among them, or another secret) is listed too, and dropped
        // below with its keys (storedEntry() gives null), so no such key outlives the
        // next pass (FRM-7). LEFT() compares bytes, so no value is a pattern.
        $head = $this->specHead() . '|';
        $todo = $this->db->select('SELECT project_id, field_name, spec, asked_at FROM ' . Schema::table('lookup_field') . '
            WHERE wanted_at > 0 OR asked_at < ? OR (ready = 1 AND building_at = 0 AND built_at < ?)
               OR (building_at > 0 AND building_at < ?) OR LEFT(spec, ?) <> ?
            ORDER BY wanted_at = 0, wanted_at, built_at LIMIT 500',
            [$now - self::UNUSED_AFTER, $now - (self::REBUILD_AFTER - self::REFRESH_BEFORE), $now - self::BUILD_STALE,
             strlen($head), $head]);
        foreach ($todo as $i => $t) {
            if (time() >= $until) {
                $out['left'] = count($todo) - $i;
                break;
            }
            $pid = (int) $t[0];
            $field = (string) $t[1];
            try {
                $src = $this->sources($pid);
                if (!$this->lock($pid, self::waitUntil($until))) {
                    $out['waiting']++;
                    $note('lock');
                    continue;
                }
                try {
                    // A spec this version cannot read, or a composite whose
                    // definition is gone, cannot be built here: it goes, and
                    // the next lookup that wants it starts it again. Keys left
                    // at $until go on the next run, which finds the row again.
                    $entry = $src === null ? null : $this->storedEntry($pid, $field, (string) $t[2]);
                    if ($entry === null || (int) $t[3] < $now - self::UNUSED_AFTER) {
                        if ($this->dropField($pid, $field, $until)) $out['dropped']++;
                        else $out['left']++;
                        continue;
                    }
                    // Each field syncs afresh: the lock was free while the last one built.
                    unset($this->rows[$pid]);
                    $this->builds = 0;
                    $before = $this->built;
                    $this->why = null;
                    if (!$this->prepare($pid, $src, [$field => $entry], true, $until)) {
                        $out['waiting']++;
                        $note($this->why === null ? 'building' : $this->why);
                    } elseif ($this->built > $before) {
                        $out['built']++;
                    }
                } finally {
                    $this->unlock($pid);
                }
            } catch (\Throwable $e) {
                Faults::swallow($e, 'LookupIndex::maintain');
                $out['waiting']++;
                $note('error');
            }
        }
        if (!$out['left']) $out['synced'] = $this->syncBehind($until);
        return $out;
    }

    /**
     * Bring up to date, until $until, the projects with indexed fields whose
     * log is more than half of MAX_SYNC_IDS ahead of what was read, the
     * furthest behind first, so no lookup finds more than it may read. Each
     * under its project's lock. Returns how many were synced.
     */
    private function syncBehind($until)
    {
        $done = 0;
        $tops = [];
        $rows = $this->db->select('SELECT s.project_id, s.synced_id FROM ' . Schema::table('lookup_sync') . ' s
            WHERE EXISTS (SELECT 1 FROM ' . Schema::table('lookup_field') . ' f WHERE f.project_id = s.project_id)
            ORDER BY s.synced_id LIMIT ' . self::CRON_SYNC_PROJECTS, []);
        foreach ($rows as $r) {
            if (time() >= $until) break;
            $pid = (int) $r[0];
            try {
                $src = $this->sources($pid);
                if ($src === null) continue;
                if (!isset($tops[$src['log']])) $tops[$src['log']] = $this->logEnds($src['log'])[0];
                if (!self::further($tops[$src['log']], (string) $r[1], self::MAX_SYNC_IDS / 2)) continue;
                if (!$this->lock($pid, self::CRON_LOCK_WAIT)) continue;
                try {
                    unset($this->rows[$pid]);
                    $this->prepare($pid, $src, [], true);
                    $done++;
                } finally {
                    $this->unlock($pid);
                }
            } catch (\Throwable $e) {
                Faults::swallow($e, 'LookupIndex::syncBehind');
            }
        }
        return $done;
    }

    /** The log position a sync row was read up to: its own, or its newest sample's when that is higher. */
    private static function readTo(array $row)
    {
        $at = (string) $row['synced'];
        if ($row['samples']) {
            $last = $row['samples'][count($row['samples']) - 1][1];
            if (SourceFence::decCmp($last, $at) > 0) $at = (string) $last;
        }
        return $at;
    }

    /** Whether log position $top is more than $by positions above $from. */
    private static function further($top, $from, $by)
    {
        return (float) $top - (float) $from > (float) $by;
    }

    /** The data and log tables of $pid, allowlisted, or null. */
    private function sources($pid)
    {
        if (!array_key_exists($pid, $this->sources)) {
            $data = RecordManifestSource::dataTable($this->db, $pid);
            $log = SourceFence::resolveTable($this->db, $pid);
            $ok = is_string($data) && preg_match('/^redcap_data[0-9]*\z/', $data)
                && is_string($log) && preg_match('/^redcap_log_event[0-9]*\z/', $log);
            $this->sources[$pid] = $ok ? ['data' => $data, 'log' => $log] : false;
        }
        return $this->sources[$pid] === false ? null : $this->sources[$pid];
    }

    /**
     * Sync the project's index with its log, then build any entry of $entries
     * (name => plainEntry() or compositeEntry()) that is missing, stale or
     * keyed another way, as far as this request may ($cron: the cron, which
     * builds any size, lets the lock go while it reads, rebuilds
     * REFRESH_BEFORE early, and pauses a composite build at $until). False
     * when the index cannot answer for all of them in this request.
     */
    private function prepare($pid, array $src, array $entries, $cron, $until = 0)
    {
        $now = time();
        $states = null;
        if (!isset($this->rows[$pid])) {
            list($top, $min) = $this->logEnds($src['log']);
            $row = $this->syncRow($pid);
            $fresh = $row === null || $row['log'] !== $src['log'] || $row['data'] !== $src['data'];
            // More log than a request reads: the cron's (syncBehind()).
            if (!$cron && !$fresh && self::further($top, self::readTo($row), self::MAX_SYNC_IDS)) {
                $this->why = ($now - $this->cronAt >= self::CRON_GRACE) ? 'no-cron' : 'behind';
                return false;
            }
            if ($fresh) {
                $this->resetProject($pid);
                // A position from before the last OVERLAP seconds, read from the
                // log's times where an index allows it. Without one, nothing is
                // built until a sample taken now is OVERLAP seconds old.
                $floor = $this->tsFloor($src['log'], $now - self::OVERLAP, $top);
                $row = ['log' => $src['log'], 'data' => $src['data'], 'meta' => '0', 'open' => 0];
                if ($floor !== null) {
                    $row += ['synced' => $floor, 'buildAfter' => $now, 'samples' => [[$now - self::OVERLAP, $floor, 0]]];
                } else {
                    $row += ['synced' => $top, 'buildAfter' => $now + self::OVERLAP, 'samples' => [[$now, $top, 0]]];
                }
            }
            $states = $this->fieldStates($pid);
            $this->sync($pid, $src, $row, $top, $min, $now, $states, $fresh);
            $this->rows[$pid] = $row;
        }
        $row = $this->rows[$pid];
        if ($now < $row['buildAfter']) {
            $this->why = 'cooldown';
            return false;
        }
        if ($states === null) $states = $this->fieldStates($pid);
        $age = $cron ? self::REBUILD_AFTER - self::REFRESH_BEFORE : self::REBUILD_AFTER;
        foreach ($entries as $f => $entry) {
            $f = (string) $f;
            $spec = $entry['spec'];
            $st = isset($states[$f]) ? $states[$f] : null;
            $answers = $st !== null && $st['ready'] && $st['spec'] === $spec;
            $building = $st !== null && self::building($st, $now);
            // A field being rebuilt still answers, from its old keys and the new.
            if ($answers && ($building || $now - $st['built'] < $age)) {
                if (!$cron && $now - $st['asked'] >= self::ASKED_EVERY) $this->touch($pid, $f, $now);
                continue;
            }
            if ($building || $this->builds >= self::BUILDS_PER_REQUEST) {
                $this->why = 'building';
                return false;
            }
            if (!$cron && !$this->mayBuildNow($pid, $src, $f, $entry, $st, $now)) {
                $this->why = ($now - $this->cronAt >= self::CRON_GRACE) ? 'no-cron' : 'cron';
                return false;
            }
            $this->builds++;
            $ok = $entry['kind'] === 'plain'
                ? $this->build($pid, $f, $spec, $entry['mark'], $st, $cron, $cron ? (int) $until : 0)
                : $this->buildComposite($pid, $src, $f, $entry, $st, $cron, $cron ? (int) $until : 0);
            if (!$ok) {
                $this->why = 'building';
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a lookup may build $field itself: the field has at most
     * liveBuildRows rows in the data table. Otherwise the field is marked as
     * wanted for the cron, with its new spec; keys of another spec are
     * cleared before the build. A field already wanted stays the cron's
     * however long it has waited: a lookup built it after CRON_GRACE with no
     * cron, a read with no bound inside a request (UNQ-3). A new spec also raises the generation
     * and ends any build marked on the row, so a build of the old spec still
     * running (one past BUILD_STALE) cannot mark the field built under the
     * new one. A composite entry is counted by the rows of all its fields,
     * and its definition is stored first, so the cron can build it.
     */
    private function mayBuildNow($pid, array $src, $field, array $entry, $st, $now)
    {
        $spec = $entry['spec'];
        $wanted = $st !== null ? $st['wanted'] : 0;
        if ($wanted > 0) return false;
        // A composite reads the rows of all its fields. Counted only as far as
        // the limit: a field of 400,000 rows costs 20,001 index entries.
        $fields = $entry['kind'] === 'plain' ? [$field] : self::compositeFields($entry['def']);
        if ($this->rowsUpTo($src['data'], $pid, $fields, $this->liveBuildRows) <= $this->liveBuildRows) return true;
        if ($entry['kind'] !== 'plain') $this->saveDef($pid, $field, self::defJson($entry['def']), null, null);
        // MySQL applies these assignments in order, so all four read the old spec.
        $this->db->execNoCount('INSERT INTO ' . Schema::table('lookup_field') . '
            (project_id, field_name, spec, ready, built_at, gen, building_at, wanted_at, asked_at, wipe)
            VALUES (?, ?, ?, 0, 0, 0, 0, ?, ?, 0)
            ON DUPLICATE KEY UPDATE ready = IF(spec = VALUES(spec), ready, 0), wipe = IF(spec = VALUES(spec), wipe, 1),
                gen = IF(spec = VALUES(spec), gen, gen + 1), building_at = IF(spec = VALUES(spec), building_at, 0),
                spec = VALUES(spec), wanted_at = IF(wanted_at = 0, VALUES(wanted_at), wanted_at), asked_at = VALUES(asked_at)',
            [$pid, $field, $spec, $now, $now]);
        return false;
    }

    /**
     * Bring the fields that answer or are being built up to the log's newest
     * position $top.
     *
     * THE WINDOW is the log from the newest sample at least OVERLAP seconds
     * old. A sample [time, position, rows] says the log stood at that position
     * at that time, and how many of the project's log rows lay between it and
     * the sample before. Every range of the window is counted again on each
     * sync; one whose count grew holds rows that became visible late, and only
     * that range is read again. The range since the last sample is tracked in
     * the same way ('open') until it is SAMPLE_EVERY old and becomes a sample.
     * So a burst of changes is read once, not once per lookup for an hour.
     *
     * Reading a range gives the records the log names there, each with its
     * newest position, and indexes again the ones last indexed below it.
     *
     * One statement counts every range and finds the newest change to the
     * data dictionary (window()); the row is written back only when it
     * changed, so a lookup with nothing new in the log sends no write.
     *
     * The fields are reset to be rebuilt when the window cannot be trusted
     * (the log was pruned into it, or the data dictionary changed) or a range
     * names more records than is worth patching; after the second, builds
     * wait SAMPLE_EVERY, so a long import is not rebuilt over and over.
     */
    private function sync($pid, array $src, array &$row, $top, $min, $now, array &$states, $fresh)
    {
        $was = $fresh ? null : self::rowSign($row);
        $samples = $row['samples'] ? $row['samples'] : [[$now, $row['synced'], 0]];
        $start = 0;
        foreach ($samples as $i => $s) {
            if ($s[0] <= $now - self::OVERLAP) $start = $i;
        }
        $samples = array_values(array_slice($samples, $start));
        $from = $samples[0][1];
        $last = count($samples) - 1;
        $lastId = $samples[$last][1];
        $synced = SourceFence::decCmp($row['synced'], $lastId) > 0 ? $row['synced'] : $lastId;
        $end = SourceFence::decCmp($top, $synced) > 0 ? $top : $synced;
        // Ranges 1..$last lie between samples, $last + 1 is the open range
        // up to $synced and $last + 2 what is new since then.
        $edges = array_merge(array_map(function ($s) { return $s[1]; }, $samples), [$synced, $end]);
        $win = $this->window($src['log'], $pid, $edges);

        $reset = false;
        if (SourceFence::decCmp($top, $from) > 0) {
            if ($min !== null && SourceFence::decCmp($from, '0') > 0 && SourceFence::decCmp($min, $from) > 0) $reset = true;
            if ($win['meta'] !== null && SourceFence::decCmp($win['meta'], $row['meta']) > 0) {
                // The fields the change can have touched, or every field.
                $some = $reset ? null : $this->metaFields($pid, $src['log'],
                    SourceFence::decCmp($row['meta'], $from) > 0 ? $row['meta'] : $from, $end, array_keys($states));
                if ($some === null) {
                    $reset = true;
                } elseif ($some) {
                    $this->resetFields($pid, $some);
                    foreach ($some as $f) {
                        $states[$f]['ready'] = false;
                        $states[$f]['building'] = 0;
                        $states[$f]['gen']++;
                    }
                }
                $row['meta'] = $win['meta'];
            }
        }
        // Read when the log first names a change, so a sync with nothing new
        // reads no definitions.
        $kept = null;
        $overflow = false;
        $read = function ($a, $b) use (&$overflow, &$kept, &$states, $reset, $pid, $src, $now) {
            if ($reset || $overflow) return;
            if ($kept === null) $kept = $this->keptOf($pid, $states, $now);
            if (!$kept || $overflow) return;
            $changed = $this->changedSince($src['log'], $pid, $a, $b, self::MAX_CHANGED + 1);
            $renamed = count($changed) > self::MAX_CHANGED ? [] : $this->renamedSince($src['log'], $pid, $a, $b);
            if ($renamed === null) {
                $overflow = true;
                return;
            }
            foreach ($renamed as $rec => $at) {
                if (!isset($changed[$rec]) || SourceFence::decCmp($at, $changed[$rec]) > 0) $changed[$rec] = $at;
            }
            $todo = count($changed) > self::MAX_CHANGED ? null : $this->staleOf($pid, $changed);
            if ($todo === null || count($todo) > self::MAX_REINDEX) {
                $overflow = true;
                return;
            }
            if ($todo) $this->reindex($pid, $src['data'], $kept, $todo);
        };

        for ($i = 1; $i <= $last; $i++) {
            $c = $win['counts'][$i - 1];
            if ($c !== $samples[$i][2]) {
                $read($samples[$i - 1][1], $samples[$i][1]);
                $samples[$i][2] = $c;
            }
        }
        $open = 0;
        if (SourceFence::decCmp($synced, $lastId) > 0) {
            $open = $win['counts'][$last];
            if ($open !== $row['open']) $read($lastId, $synced);
        }
        if (SourceFence::decCmp($top, $synced) > 0) {
            $open += $win['counts'][$last + 1];
            // The log is shared by projects: none of this one's rows, nothing to
            // read. A row that shows up late is counted in the open range.
            if ($win['counts'][$last + 1] !== 0) $read($synced, $top);
            $synced = $top;
        }
        if ($samples[$last][0] <= $now - self::SAMPLE_EVERY && SourceFence::decCmp($synced, $lastId) > 0) {
            $samples[] = [$now, $synced, $open];
            $open = 0;
        }
        if ($reset || $overflow) {
            $this->resetFields($pid);
            foreach ($states as $f => $st) {
                $states[$f]['ready'] = false;
                $states[$f]['building'] = 0;
                $states[$f]['gen']++;
            }
            if ($overflow) $row['buildAfter'] = max($row['buildAfter'], $now + self::SAMPLE_EVERY);
        }
        $row['samples'] = $samples;
        $row['open'] = $open;
        $row['synced'] = $synced;
        if (self::rowSign($row) !== $was) $this->saveSyncRow($pid, $row);
    }

    /**
     * $pid's log rows in each range ($edges[i-1], $edges[i]], and the newest
     * position of a change to its data dictionary in ($edges[0], last edge]
     * (null when none), from one read of that stretch of the log.
     *
     * @return array ['counts' => int[], 'meta' => ?string]
     */
    private function window($log, $pid, array $edges)
    {
        $n = count($edges) - 1;
        $from = $edges[0];
        $to = $edges[$n];
        if (SourceFence::decCmp($to, $from) <= 0) return ['counts' => array_fill(0, $n, 0), 'meta' => null];
        $cols = [];
        $params = [];
        for ($i = 1; $i <= $n; $i++) {
            $cols[] = 'COALESCE(SUM(log_event_id > ? AND log_event_id <= ?), 0)';
            array_push($params, (string) $edges[$i - 1], (string) $edges[$i]);
        }
        $cols[] = "MAX(CASE WHEN object_type LIKE ? ESCAPE '!' THEN log_event_id END)";
        $params[] = 'redcap!_metadata%';
        array_push($params, (string) $from, (string) $to, $pid);
        $r = $this->db->select('SELECT ' . implode(', ', $cols) . ' FROM ' . $log . self::BY_POSITION
            . ' WHERE log_event_id > ? AND log_event_id <= ? AND project_id = ?', $params);
        $counts = [];
        for ($i = 0; $i < $n; $i++) $counts[] = isset($r[0][$i]) ? (int) $r[0][$i] : -1;
        $meta = isset($r[0][$n]) && $r[0][$n] !== null && $r[0][$n] !== '' ? (string) $r[0][$n] : null;
        return ['counts' => $counts, 'meta' => $meta];
    }

    /** What saveSyncRow() writes, to tell whether a sync changed the row. */
    private static function rowSign(array $row)
    {
        return json_encode([$row['log'], $row['data'], (string) $row['synced'], (string) $row['meta'],
            (int) $row['buildAfter'], $row['samples'], (int) $row['open']]);
    }

    /**
     * Index every row the data table holds for $field, as generation gen + 1.
     *
     * The field answers during the build only when it answered under the
     * same spec; then old and new keys answer together. Every delete takes
     * only generations below this build's, so a delete never touches a key
     * the build or a sync meanwhile writes, and runs outside the lock when
     * $release (the cron) lets the project's lock go while the rows are read
     * and written. The keys of a field marked to wipe (its spec changed while
     * it waited for the cron, mayBuildNow()) go before any key is written, once the row says the field
     * does not answer; a cron run out of time there stops the build, with
     * wipe still set, and the next run goes on. Every other old key goes once
     * the new ones are written, before the field answers from them alone; one
     * a cron run has no time left for stays as part of a superset, until the
     * next build. The build counts only if no reset or other build changed
     * the field's generation meanwhile.
     */
    private function build($pid, $field, $spec, $mark, $st, $release, $until = 0)
    {
        $ft = Schema::table('lookup_field');
        $now = time();
        $keep = $st !== null && $st['spec'] === $spec && !$st['wipe'];
        // Generation 0 is a row no build has marked, so no key of it exists.
        $old = $st !== null && $st['gen'] > 0;
        $gen = ($st !== null ? $st['gen'] : 0) + 1;
        $this->db->execNoCount('INSERT INTO ' . $ft . '
            (project_id, field_name, spec, ready, built_at, gen, building_at, wanted_at, asked_at, wipe)
            VALUES (?, ?, ?, ?, 0, ?, ?, 0, ?, 0)
            ON DUPLICATE KEY UPDATE spec = VALUES(spec), ready = VALUES(ready), gen = VALUES(gen),
                building_at = VALUES(building_at), wipe = 0, asked_at = GREATEST(asked_at, VALUES(asked_at))',
            // The cron is not a lookup: it leaves the field's last-asked time as it was.
            [$pid, $field, $spec, ($keep && $st['ready']) ? 1 : 0, $gen, $now, ($release && $st !== null) ? $st['asked'] : $now]);
        if ($release) $this->unlock($pid);
        if ($old && $st['wipe'] && !$this->deleteKeys($pid, $field, $gen, $until)) {
            if ($release && !$this->lock($pid, self::CRON_LOCK_WAIT)) return false;
            $this->db->execNoCount('UPDATE ' . $ft . ' SET building_at = 0, wipe = 1, wanted_at = IF(wanted_at = 0, ?, wanted_at)
                WHERE project_id = ? AND field_name = ? AND gen = ?', [$now, $pid, $field, $gen]);
            return false;
        }
        $ok = $this->writeKeys($pid, $field, $mark, $gen);
        if ($ok && $old) $this->deleteKeys($pid, $field, $gen, $until);
        if ($release && !$this->lock($pid, self::CRON_LOCK_WAIT)) return false;
        $cur = $this->db->select('SELECT gen, building_at FROM ' . $ft . ' WHERE project_id = ? AND field_name = ?', [$pid, $field]);
        if (!isset($cur[0]) || (int) $cur[0][0] !== $gen || (int) $cur[0][1] !== $now) return false;
        if (!$ok) {
            $this->db->execNoCount('UPDATE ' . $ft . ' SET building_at = 0 WHERE project_id = ? AND field_name = ? AND gen = ?', [$pid, $field, $gen]);
            return false;
        }
        $this->db->execNoCount('UPDATE ' . $ft . ' SET ready = 1, built_at = ?, building_at = 0, wanted_at = 0
            WHERE project_id = ? AND field_name = ? AND gen = ?', [$now, $pid, $field, $gen]);
        $this->built++;
        return true;
    }

    /** Write a key for every row the data table holds for $field, as generation $gen. */
    private function writeKeys($pid, $field, $mark, $gen)
    {
        $buf = [];
        $flush = function () use (&$buf, $pid, $field, $gen) {
            if ($buf) $this->insertKeys($pid, $field, $buf, $gen);
            $buf = [];
        };
        $ok = (new FieldValues($this->module))->eachRow($pid, $field, null, function ($rec, $evt, $value) use (&$buf, $mark, $flush, $pid) {
            $buf[bin2hex($rec) . ':' . $this->keyHex($pid, $value, $mark)] = true;
            if (count($buf) >= self::INSERT_ROWS) $flush();
        });
        if (!$ok) return false;
        $flush();
        return true;
    }

    /**
     * Index a composite entry from the data table, as build() indexes a
     * field, BATCH records at a time (writeCompositeKeys()).
     *
     * A build of the same definition and generation that paused (its cursor
     * is set) goes on from its cursor; any other starts at generation gen + 1.
     * Keys of older generations (an older build, another definition) go when
     * the build has written its own, before the entry answers from them
     * alone, so an entry that answered goes on answering from old and new
     * keys meanwhile; one a cron run has no time left for stays as part of a
     * superset, until the next build. No composite has keys of key version 1
     * to wipe first: maintain() drops a composite of another version or
     * secret. $until > 0 (the cron) pauses the build there: the cursor is
     * saved, the entry is marked as wanted, so the next cron run picks it up,
     * and false is returned. At least one batch is written per call, so every
     * run moves it on.
     */
    private function buildComposite($pid, array $src, $name, array $entry, $st, $release, $until)
    {
        $ft = Schema::table('lookup_field');
        $now = time();
        $json = self::defJson($entry['def']);
        $same = $st !== null && $st['spec'] === $entry['spec'] && !$st['wipe'];
        $old = $st !== null && $st['gen'] > 0;
        $saved = $this->defRow($pid, $name);
        if ($same && $saved !== null && $saved['def'] === $json && $saved['cursor'] !== '' && $saved['gen'] === $st['gen']) {
            $gen = $st['gen'];
            $cursor = $saved['cursor'];
        } else {
            $gen = ($st !== null ? $st['gen'] : 0) + 1;
            $cursor = '';
        }
        $this->saveDef($pid, $name, $json, $cursor, $gen);
        $this->db->execNoCount('INSERT INTO ' . $ft . '
            (project_id, field_name, spec, ready, built_at, gen, building_at, wanted_at, asked_at, wipe)
            VALUES (?, ?, ?, ?, 0, ?, ?, 0, ?, 0)
            ON DUPLICATE KEY UPDATE spec = VALUES(spec), ready = VALUES(ready), gen = VALUES(gen),
                building_at = VALUES(building_at), wipe = 0, asked_at = GREATEST(asked_at, VALUES(asked_at))',
            [$pid, $name, $entry['spec'], ($same && $st['ready']) ? 1 : 0, $gen, $now, ($release && $st !== null) ? $st['asked'] : $now]);
        if ($release) $this->unlock($pid);
        list($ok, $cursor) = $this->writeCompositeKeys($pid, $src['data'], $name, $entry['def'], $gen, $cursor, $until);
        if ($ok && $cursor === '' && $old) $this->deleteKeys($pid, $name, $gen, $until);
        if ($release && !$this->lock($pid, self::CRON_LOCK_WAIT)) return false;
        $cur = $this->db->select('SELECT gen, building_at FROM ' . $ft . ' WHERE project_id = ? AND field_name = ?', [$pid, $name]);
        if (!isset($cur[0]) || (int) $cur[0][0] !== $gen || (int) $cur[0][1] !== $now) return false;
        if (!$ok || $cursor !== '') {
            // Failed, or paused: a paused build keeps its place for the next run.
            if ($ok) $this->saveDef($pid, $name, $json, $cursor, $gen);
            $this->db->execNoCount('UPDATE ' . $ft . ' SET building_at = 0' . ($ok ? ', wanted_at = IF(wanted_at = 0, ?, wanted_at)' : '')
                . ' WHERE project_id = ? AND field_name = ? AND gen = ?', $ok ? [$now, $pid, $name, $gen] : [$pid, $name, $gen]);
            return false;
        }
        $this->saveDef($pid, $name, $json, '', $gen);
        $this->db->execNoCount('UPDATE ' . $ft . ' SET ready = 1, built_at = ?, building_at = 0, wanted_at = 0
            WHERE project_id = ? AND field_name = ? AND gen = ?', [$now, $pid, $name, $gen]);
        $this->built++;
        return true;
    }

    /**
     * Write the keys of every record after $cursor that holds the entry's
     * first field, as generation $gen: [ok, cursor], where the cursor is where
     * the build paused at $until, '' when it ended.
     *
     * TWO WAYS THROUGH THE RECORDS, chosen when the build starts (walkOf())
     * and kept in the cursor's first byte, so a paused build goes on the way
     * it began.
     * - 'l', listing: the records that hold the first field, LIST_RECORDS at a
     *   time in byte order (recordsAfter()). Each listing reads all the
     *   field's rows (the cursor's range cannot use an index), so a field
     *   held by H records costs rows x ceil(H / LIST_RECORDS) reads; one read
     *   when H is at most LIST_RECORDS.
     * - 'w', walking: every record of the project from REDCap's record index
     *   (RecordManifestSource), BATCH at a time in its collation's order; a
     *   record without the field gets no key. Taken where it reads less than
     *   the listings would, and only from an index RecordManifestSource
     *   trusts: one REDCap reports complete, or one it keeps no status for
     *   that holds rows for the project. A resume reads the records the
     *   collation ties to its cursor again; keying a record twice writes the
     *   same keys.
     * A walk that cannot go on (the index is no longer trusted when it
     * resumes, or a page cannot be read: a failed read, more ids than
     * RecordManifestSource::TIE_CAP tied at its boundary, an index REDCap
     * began rebuilding) lists the field from the start instead, in the same
     * call, rather than failing at the same place on every run. A cursor that
     * names neither way (written before the cursor named its way) starts
     * again: read as a listing's, it could skip records.
     *
     * @return array{0: bool, 1: string}
     */
    private function writeCompositeKeys($pid, $dataTable, $name, array $def, $gen, $cursor, $until)
    {
        try {
            $fields = self::compositeFields($def);
            $write = function (array $chunk) use ($pid, $dataTable, $name, $def, $gen, $fields) {
                $buf = [];
                foreach ($this->compositeKeysOf($pid, $dataTable, [$name => $def], $chunk, $fields) as $rh => $byName) {
                    foreach ($byName[$name] as $k => $_) $buf[$rh . ':' . $k] = true;
                }
                foreach (array_chunk($buf, self::INSERT_ROWS, true) as $part) $this->insertKeys($pid, $name, $part, $gen);
            };
            $way = $cursor === '' ? '' : $cursor[0];
            if ($way !== 'l' && $way !== 'w') $cursor = '';
            $walk = null;
            if ($cursor === '') {
                $walk = $this->walkOf($pid, $dataTable, $def['slot']);
            } elseif ($way === 'w') {
                $o = RecordManifestSource::open($this->db, $pid, []);
                if (!empty($o['ok']) && $o['source']->via() === RecordManifestSource::VIA_INDEX) $walk = $o['source'];
                else $cursor = '';
            }
            if ($walk !== null) {
                $after = $cursor === '' ? null : (string) substr($cursor, 1);
                $emitted = [];
                while (true) {
                    $page = $walk->page($after, $emitted, self::BATCH);
                    if (empty($page['ok'])) {
                        $cursor = '';
                        break;
                    }
                    $ids = [];
                    foreach ($page['rows'] as $r) $ids[] = (string) $r['id'];
                    if ($ids) $write($ids);
                    if ($page['done']) return [true, ''];
                    $after = (string) $page['cursor'];
                    $emitted = $page['emitted'];
                    if ($until > 0 && time() >= $until) return [true, 'w' . $after];
                }
            }
            $at = $cursor === '' ? '' : (string) substr($cursor, 1);
            while (true) {
                list($recs, $more) = $this->recordsAfter($pid, $dataTable, $def['slot'], $at, $this->listRecords);
                $chunks = array_chunk($recs, self::BATCH);
                foreach ($chunks as $i => $chunk) {
                    $write($chunk);
                    $at = (string) end($chunk);
                    $done = !$more && $i === count($chunks) - 1;
                    if ($until > 0 && !$done && time() >= $until) return [true, 'l' . $at];
                }
                if (!$more) return [true, ''];
            }
        } catch (\Throwable $e) {
            Faults::swallow($e, 'LookupIndex::writeCompositeKeys');
            return [false, ''];
        }
    }

    /**
     * REDCap's record index, for a composite build that starts to walk, or
     * null when listing its first field $slot reads less (writeCompositeKeys()).
     *
     * The listings read the field's R rows once per listRecords of the H
     * records that hold it: R x ceil(H / listRecords). A walk reads the
     * project's N record ids, and the rows of the records without the field
     * besides, taken here as one read each: 2N. So the walk is taken when
     * 2N < R x ceil(H / listRecords). A field of at most listRecords rows is
     * listed on a count that stops there; a larger one is counted (one read
     * of its rows, which a listing would make anyway), and the project's
     * records only as far as a walk would still be cheaper.
     */
    private function walkOf($pid, $dataTable, $slot)
    {
        if ($this->rowsUpTo($dataTable, $pid, [$slot], $this->listRecords) <= $this->listRecords) return null;
        $o = RecordManifestSource::open($this->db, $pid, []);
        if (empty($o['ok']) || $o['source']->via() !== RecordManifestSource::VIA_INDEX) return null;
        $n = $this->db->select('SELECT COUNT(*), COUNT(DISTINCT CAST(record AS BINARY)) FROM ' . $dataTable
            . ' WHERE project_id = ? AND field_name = ?', [$pid, $slot]);
        if (!isset($n[0][1])) return null;
        $listed = (int) $n[0][0] * intdiv((int) $n[0][1] + $this->listRecords - 1, $this->listRecords);
        // 2N < listed, so N is at most (listed - 1) / 2.
        $most = intdiv($listed - 1, 2);
        return $o['source']->rowsUpTo($most) <= $most ? $o['source'] : null;
    }

    /**
     * The first $limit records after $after, in byte order, that hold $field,
     * and whether more follow: [records, more]. The server sorts and cuts the
     * list (SQL compares a binary string byte by byte, as strcmp() does), so
     * at most $limit + 1 record ids reach PHP.
     *
     * @return array{0: string[], 1: bool}
     */
    private function recordsAfter($pid, $dataTable, $field, $after, $limit)
    {
        $sql = 'SELECT DISTINCT CAST(record AS BINARY) AS r FROM ' . $dataTable . ' WHERE project_id = ? AND field_name = ?';
        $params = [$pid, $field];
        if ($after !== '') {
            $sql .= ' AND CAST(record AS BINARY) > ?';
            $params[] = $after;
        }
        $out = [];
        foreach ($this->db->select($sql . ' ORDER BY r LIMIT ' . ((int) $limit + 1), $params) as $r) $out[] = (string) $r[0];
        $more = count($out) > $limit;
        if ($more) array_pop($out);
        return [$out, $more];
    }

    /** The data rows of $fields in $pid, counted only as far as $limit + 1. */
    private function rowsUpTo($dataTable, $pid, array $fields, $limit)
    {
        $r = $this->db->select('SELECT COUNT(*) FROM (SELECT 1 FROM ' . $dataTable . ' WHERE project_id = ? AND field_name IN ('
            . implode(', ', array_fill(0, count($fields), '?')) . ') LIMIT ' . ((int) $limit + 1) . ') AS n',
            array_merge([$pid], array_values($fields)));
        return isset($r[0][0]) ? (int) $r[0][0] : PHP_INT_MAX;
    }

    /**
     * The keys each composite entry of $defs (name => def) holds for the
     * records of $chunk, from one read of their rows of $fields:
     * recordHex => [name => [keyHex => true]], every record of $chunk present.
     */
    private function compositeKeysOf($pid, $dataTable, array $defs, array $chunk, array $fields)
    {
        $out = [];
        $rows = [];
        foreach ($chunk as $r) {
            $out[bin2hex((string) $r)] = array_fill_keys(array_keys($defs), []);
        }
        foreach ($this->db->select('SELECT record, field_name, `value`, event_id, instance FROM ' . $dataTable . ' WHERE project_id = ? AND record IN ('
                . implode(', ', array_fill(0, count($chunk), '?')) . ') AND field_name IN (' . implode(', ', array_fill(0, count($fields), '?')) . ')',
                array_merge([$pid], array_map('strval', $chunk), $fields)) as $row) {
            if (!isset($row[2]) || $row[2] === null) continue;
            $rows[(string) $row[0]][] = [(string) $row[1], (string) $row[2], (string) $row[3], $row[4]];
        }
        foreach ($rows as $rec => $recRows) {
            $rh = bin2hex((string) $rec);
            // IN() compares by the data table's collation and may return a record
            // of another letter case; that record is indexed in its own batch.
            if (!isset($out[$rh])) continue;
            foreach ($defs as $name => $def) {
                foreach (self::compositeMaterials($recRows, $def) as $m => $_) $out[$rh][$name][$this->compositeKeyHex($pid, $name, $m)] = true;
            }
        }
        return $out;
    }

    /**
     * Delete $field's keys, all of them ($below null) or those of generations
     * below $below, DELETE_ROWS at a time; with $until > 0, no page is started
     * at or after that Unix time. True when none is left.
     */
    private function deleteKeys($pid, $field, $below, $until = 0)
    {
        $sql = 'DELETE FROM ' . Schema::table('lookup_key') . ' WHERE project_id = ? AND field_name = ?'
            . ($below === null ? '' : ' AND gen < ?') . ' LIMIT ' . self::DELETE_ROWS;
        $params = $below === null ? [$pid, $field] : [$pid, $field, (int) $below];
        while (true) {
            $this->db->exec($sql, $params);
            if ($this->db->affected() < self::DELETE_ROWS) return true;
            if ($until > 0 && time() >= $until) return false;
        }
    }

    /** One batch of "recordHex:keyHex" pairs of $field, as generation $gen (a key already there takes the higher). */
    private function insertKeys($pid, $field, array $pairs, $gen)
    {
        $vals = [];
        $params = [];
        foreach (array_keys($pairs) as $p) {
            list($rec, $key) = explode(':', $p, 2);
            $vals[] = '(?, ?, UNHEX(?), UNHEX(?), ?)';
            array_push($params, $pid, $field, $key, $rec, (int) $gen);
        }
        $this->db->execNoCount('INSERT INTO ' . Schema::table('lookup_key')
            . ' (project_id, field_name, key_hash, record, gen) VALUES ' . implode(', ', $vals)
            . ' ON DUPLICATE KEY UPDATE gen = GREATEST(gen, VALUES(gen))', $params);
    }

    /**
     * Index the records of $todo (record => log position) again for the
     * entries of $kept (keptOf()), from their rows in the data table. New
     * keys go in before stale ones come out, so a lookup reading at the same
     * time sees the old keys, the new ones or both: a superset. A key kept
     * from an older generation is written again at the entry's generation, so
     * the end of a build running meanwhile does not clear it.
     */
    private function reindex($pid, $dataTable, array $kept, array $todo)
    {
        $plain = [];
        $defs = [];
        foreach ($kept as $name => $k) {
            if ($k['kind'] === 'plain') $plain[(string) $name] = true;
            else $defs[(string) $name] = $k['def'];
        }
        $fields = array_map('strval', array_keys($plain));
        $fMarks = implode(', ', array_fill(0, count($fields), '?'));
        $cFields = [];
        foreach ($defs as $def) $cFields = array_merge($cFields, self::compositeFields($def));
        $cFields = array_values(array_unique($cFields));
        $kt = Schema::table('lookup_key');
        foreach (array_chunk(array_keys($todo), self::BATCH) as $chunk) {
            $chunk = array_map('strval', $chunk);
            $want = [];     // recordHex => [entry => [keyHex => true]]
            foreach ($chunk as $r) $want[bin2hex($r)] = [];
            $rows = !$fields ? [] : $this->db->select('SELECT record, field_name, `value` FROM ' . $dataTable . ' WHERE project_id = ? AND record IN ('
                . implode(', ', array_fill(0, count($chunk), '?')) . ') AND field_name IN (' . $fMarks . ')',
                array_merge([$pid], $chunk, $fields));
            foreach ($rows as $row) {
                if (!isset($row[2]) || $row[2] === null) continue;
                $f = (string) $row[1];
                $rh = bin2hex((string) $row[0]);
                // IN() compares by the data table's collation and may return a
                // record of another letter case, which is not in this chunk.
                if (!isset($plain[$f]) || !isset($want[$rh])) continue;
                $want[$rh][$f][$this->keyHex($pid, (string) $row[2], $kept[$f]['mark'])] = true;
            }
            if ($defs) {
                foreach ($this->compositeKeysOf($pid, $dataTable, $defs, $chunk, $cFields) as $rh => $byName) {
                    foreach ($byName as $name => $keys) {
                        if ($keys) $want[$rh][$name] = $keys;
                    }
                }
            }
            $recHex = array_keys($want);
            $have = [];     // "recordHex:field:keyHex" => generation
            $existing = $this->db->select('SELECT HEX(record), field_name, HEX(key_hash), gen FROM ' . $kt . ' WHERE project_id = ? AND record IN ('
                . implode(', ', array_fill(0, count($recHex), 'UNHEX(?)')) . ')', array_merge([$pid], $recHex));
            foreach ($existing as $e) {
                if (!array_key_exists((string) $e[1], $kept)) continue;
                $have[strtolower((string) $e[0]) . ':' . $e[1] . ':' . strtolower((string) $e[2])] = (int) $e[3];
            }
            $add = [];      // field => ["recordHex:keyHex" => true]
            foreach ($want as $rh => $byField) {
                foreach ($byField as $f => $keys) {
                    foreach ($keys as $k => $_) {
                        $h = $rh . ':' . $f . ':' . $k;
                        if (!isset($have[$h]) || $have[$h] < $kept[$f]['gen']) $add[$f][$rh . ':' . $k] = true;
                        unset($have[$h]);
                    }
                }
            }
            foreach ($add as $f => $pairs) {
                foreach (array_chunk($pairs, self::INSERT_ROWS, true) as $part) $this->insertKeys($pid, $f, $part, $kept[$f]['gen']);
            }
            foreach (array_chunk(array_keys($have), 200) as $part) {
                $or = [];
                $params = [$pid];
                foreach ($part as $h) {
                    list($rh, $f, $k) = explode(':', $h, 3);
                    $or[] = '(field_name = ? AND key_hash = UNHEX(?) AND record = UNHEX(?))';
                    array_push($params, $f, $k, $rh);
                }
                $this->db->execNoCount('DELETE FROM ' . $kt . ' WHERE project_id = ? AND (' . implode(' OR ', $or) . ')', $params);
            }
            $vals = [];
            $params = [];
            foreach ($chunk as $r) {
                $vals[] = '(?, UNHEX(?), ?)';
                array_push($params, $pid, bin2hex($r), (string) $todo[$r]);
            }
            $this->db->execNoCount('INSERT INTO ' . Schema::table('lookup_record') . ' (project_id, record, version) VALUES '
                . implode(', ', $vals) . ' ON DUPLICATE KEY UPDATE version = GREATEST(version, VALUES(version))', $params);
        }
    }

    /** The records of $changed (record => newest log position) indexed below that position, or never. */
    private function staleOf($pid, array $changed)
    {
        $out = [];
        foreach (array_chunk(array_keys($changed), self::BATCH) as $chunk) {
            $chunk = array_map('strval', $chunk);
            $seen = [];
            $rows = $this->db->select('SELECT HEX(record), version FROM ' . Schema::table('lookup_record') . ' WHERE project_id = ? AND record IN ('
                . implode(', ', array_fill(0, count($chunk), 'UNHEX(?)')) . ')', array_merge([$pid], array_map('bin2hex', $chunk)));
            foreach ($rows as $r) $seen[strtolower((string) $r[0])] = (string) $r[1];
            foreach ($chunk as $rec) {
                $v = isset($seen[bin2hex($rec)]) ? $seen[bin2hex($rec)] : null;
                if ($v === null || SourceFence::decCmp($changed[$rec], $v) > 0) $out[$rec] = $changed[$rec];
            }
        }
        return $out;
    }

    /** The records the log names in ($from, $to] for $pid, each with its newest position there. */
    private function changedSince($log, $pid, $from, $to, $limit)
    {
        $rows = $this->db->select('SELECT CAST(pk AS BINARY) AS p, MAX(log_event_id) FROM ' . $log . self::BY_POSITION . '
            WHERE log_event_id > ? AND log_event_id <= ? AND project_id = ?
              AND pk IS NOT NULL AND pk <> \'\' AND LENGTH(pk) <= 400
            GROUP BY p LIMIT ' . (int) $limit, [(string) $from, (string) $to, $pid]);
        $out = [];
        foreach ($rows as $r) $out[(string) $r[0]] = (string) $r[1];
        return $out;
    }

    /**
     * The other id of each record renamed in ($from, $to] (UNQ-6), with the
     * position of its row: record id => position. [] when there is none or
     * the project's record ID field cannot be read; null when there are more
     * than MAX_RENAMED, which resets the fields as too many changes do.
     *
     * REDCap logs a rename as one row whose values are the record ID field
     * alone ("record_id = 'NEW'"), with one of the two ids in pk. Whichever
     * it is, changedSince() names it and this names the one in the values,
     * so the old id loses its keys and the new one gets them. A record
     * created with nothing but its id has the same row; it is named twice
     * and indexed once. The rows come through the same stretch of the
     * primary key as changedSince() reads.
     */
    private function renamedSince($log, $pid, $from, $to)
    {
        $pk = $this->recordIdField($pid);
        if ($pk === '') return [];
        try {
            $rows = $this->db->select('SELECT data_values, log_event_id FROM ' . $log . self::BY_POSITION . '
                WHERE log_event_id > ? AND log_event_id <= ? AND project_id = ?
                  AND data_values LIKE ? ESCAPE \'!\' AND data_values NOT LIKE ? AND LENGTH(data_values) <= 600
                LIMIT ' . (self::MAX_RENAMED + 1),
                // One value and no more: a save of several fields has a line for each.
                [(string) $from, (string) $to, $pid, str_replace('_', '!_', $pk) . " = '%", "%\n%"]);
        } catch (\Throwable $e) {
            // A log table without the column: nothing more is known than before.
            Faults::swallow($e, 'LookupIndex::renamedSince');
            return [];
        }
        if (count($rows) > self::MAX_RENAMED) return null;
        $out = [];
        $lead = strlen($pk) + 4;
        foreach ($rows as $r) {
            $v = rtrim((string) $r[0]);
            if (strpos($v, "\n") !== false || substr($v, -1) !== "'" || strlen($v) <= $lead) continue;
            $rec = substr($v, $lead, -1);
            if ($rec === '' || strlen($rec) > 400) continue;
            $at = (string) $r[1];
            if (!isset($out[$rec]) || SourceFence::decCmp($at, $out[$rec]) > 0) $out[$rec] = $at;
        }
        return $out;
    }

    /** The project's record ID field (the first of its dictionary), or '' when it cannot be read. */
    private function recordIdField($pid)
    {
        if (!array_key_exists($pid, $this->pkFields)) {
            $f = '';
            try {
                $r = $this->db->select('SELECT field_name FROM redcap_metadata WHERE project_id = ? ORDER BY field_order LIMIT 1', [$pid]);
                if (isset($r[0][0]) && preg_match('/^[a-z][a-z0-9_]*\z/', (string) $r[0][0])) $f = (string) $r[0][0];
            } catch (\Throwable $e) {
                Faults::swallow($e, 'LookupIndex::recordIdField');
            }
            $this->pkFields[$pid] = $f;
        }
        return $this->pkFields[$pid];
    }

    /**
     * The fields of $indexed that the data dictionary changes logged in
     * ($from, $to] can have touched, or null for every field.
     *
     * What can leave a field's keys short is rows that arrive in it with no
     * log row per record: a field renamed into its name. REDCap logs a
     * dictionary change with the field in pk, and this does not rely on
     * which of a rename's two names that is. A change that names a field
     * the dictionary holds now touches that field. A name the dictionary
     * does not hold now (a field deleted or renamed away, an instrument, the
     * project id of a whole upload) may stand for any field: null. An
     * indexed field the dictionary no longer holds is reset too, so its
     * name starts from nothing if a field is renamed into it later. More
     * than META_FIELDS names, or a dictionary that cannot be read: null.
     *
     * @param string[] $indexed
     * @return string[]|null
     */
    private function metaFields($pid, $log, $from, $to, array $indexed)
    {
        try {
            $rows = $this->db->select('SELECT DISTINCT CAST(pk AS BINARY) FROM ' . $log . self::BY_POSITION . "
                WHERE log_event_id > ? AND log_event_id <= ? AND project_id = ? AND object_type LIKE ? ESCAPE '!'
                LIMIT " . (self::META_FIELDS + 1), [(string) $from, (string) $to, $pid, 'redcap!_metadata%']);
            if (count($rows) > self::META_FIELDS) return null;
            $named = [];
            foreach ($rows as $r) {
                $n = isset($r[0]) ? (string) $r[0] : '';
                if (!preg_match('/^[a-z][a-z0-9_]*\z/', $n)) return null;
                $named[$n] = true;
            }
            $indexed = array_map('strval', $indexed);
            $ask = array_values(array_unique(array_merge(array_keys($named), $indexed)));
            if (!$ask) return [];
            $have = [];
            foreach ($this->db->select('SELECT field_name FROM redcap_metadata WHERE project_id = ? AND field_name IN ('
                    . implode(', ', array_fill(0, count($ask), '?')) . ')', array_merge([$pid], $ask)) as $r) {
                $have[(string) $r[0]] = true;
            }
            foreach ($named as $n => $_) {
                if (!isset($have[$n])) return null;
            }
            $out = [];
            foreach ($indexed as $f) {
                if (isset($named[$f]) || !isset($have[$f])) $out[] = $f;
            }
            return $out;
        } catch (\Throwable $e) {
            Faults::swallow($e, 'LookupIndex::metaFields');
            return null;
        }
    }

    /**
     * The log table's newest position ('0' when it is empty) and its oldest
     * (null when it is empty), in one read of the primary key's two ends.
     *
     * @return array [string, ?string]
     */
    private function logEnds($log)
    {
        $r = $this->db->select('SELECT MAX(log_event_id), MIN(log_event_id) FROM ' . $log, []);
        $top = isset($r[0][0]) ? $r[0][0] : null;
        $min = isset($r[0][1]) ? $r[0][1] : null;
        if ($top === null || $top === '') return ['0', null];
        if (!ctype_digit((string) $top)) throw new ScanStoreUnavailable('the log answered its top with something that is not a position');
        return [(string) $top, ($min === null || $min === '') ? null : (string) $min];
    }

    /**
     * The position just below the first log row written at or after $since,
     * $top when there is none, or null when no index leads with `ts` (the
     * read would walk the whole log).
     */
    private function tsFloor($log, $since, $top)
    {
        if (!array_key_exists($log, $this->tsIndexed)) {
            $r = $this->db->select('SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? AND seq_in_index = 1', [$log, 'ts']);
            $this->tsIndexed[$log] = isset($r[0][0]) && (int) $r[0][0] > 0;
        }
        if (!$this->tsIndexed[$log]) return null;
        // REDCap writes ts from PHP's clock as YYYYMMDDHHMMSS, so this does too.
        $r = $this->db->select('SELECT MIN(log_event_id) - 1 FROM ' . $log . ' WHERE ts >= ?', [date('YmdHis', $since)]);
        $v = isset($r[0][0]) ? $r[0][0] : null;
        return ($v === null || $v === '') ? $top : (string) $v;
    }

    /** @return array|null the project's sync row */
    private function syncRow($pid)
    {
        $r = $this->db->select('SELECT log_table, data_table, synced_id, meta_id, build_after, samples FROM '
            . Schema::table('lookup_sync') . ' WHERE project_id = ?', [$pid]);
        if (!isset($r[0])) return null;
        $j = json_decode((string) $r[0][5], true);
        $ok = [];
        foreach ((is_array($j) && isset($j['s']) && is_array($j['s'])) ? $j['s'] : [] as $s) {
            if (is_array($s) && count($s) === 3 && is_int($s[0]) && ctype_digit((string) $s[1]) && is_int($s[2])) {
                $ok[] = [$s[0], (string) $s[1], $s[2]];
            }
        }
        return ['log' => (string) $r[0][0], 'data' => (string) $r[0][1], 'synced' => (string) $r[0][2],
                'meta' => (string) $r[0][3], 'buildAfter' => (int) $r[0][4], 'samples' => $ok,
                'open' => (is_array($j) && isset($j['o']) && is_int($j['o'])) ? $j['o'] : -1];
    }

    private function saveSyncRow($pid, array $row)
    {
        $this->db->execNoCount('INSERT INTO ' . Schema::table('lookup_sync') . '
            (project_id, log_table, data_table, synced_id, meta_id, build_after, samples) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE log_table = VALUES(log_table), data_table = VALUES(data_table), synced_id = VALUES(synced_id),
                meta_id = VALUES(meta_id), build_after = VALUES(build_after), samples = VALUES(samples)',
            [$pid, $row['log'], $row['data'], (string) $row['synced'], (string) $row['meta'], (int) $row['buildAfter'],
             json_encode(['s' => $row['samples'], 'o' => (int) $row['open']])]);
    }

    /**
     * @return array field => ['spec' => string, 'ready' => bool, 'built' => int, 'gen' => int,
     *               'building' => int, 'wanted' => int, 'asked' => int, 'wipe' => bool]
     */
    private function fieldStates($pid)
    {
        $out = [];
        foreach ($this->db->select('SELECT field_name, spec, ready, built_at, gen, building_at, wanted_at, asked_at, wipe FROM '
                . Schema::table('lookup_field') . ' WHERE project_id = ?', [$pid]) as $r) {
            $out[(string) $r[0]] = ['spec' => (string) $r[1], 'ready' => (int) $r[2] === 1, 'built' => (int) $r[3],
                'gen' => (int) $r[4], 'building' => (int) $r[5], 'wanted' => (int) $r[6], 'asked' => (int) $r[7],
                'wipe' => (int) $r[8] === 1];
        }
        return $out;
    }

    /** Whether a build of the field started less than BUILD_STALE ago and has not ended. */
    private static function building(array $st, $now)
    {
        return $st['building'] > 0 && $now - $st['building'] < self::BUILD_STALE;
    }

    /**
     * The entries a sync keeps current: those that answer, have a build not
     * ended (a dead one too, until the cron starts it again), or are
     * composites whose build paused, keyed by this version and secret.
     * The definitions are read only when the project has a composite entry.
     *
     * An entry that answers, is being built or paused its build, and cannot
     * be kept current here (another key version or secret, a composite whose
     * definition is gone) stops
     * answering, in the table and in $states, and its build will not count:
     * the sync is about to move past changes it would miss.
     *
     * @return array entry => ['kind' => 'plain', 'mark' => ?string, 'gen' => int]
     *                      | ['kind' => 'c', 'def' => array, 'gen' => int]
     */
    private function keptOf($pid, array &$states, $now)
    {
        $out = [];
        $stale = [];
        $defs = null;
        foreach ($states as $f => $st) {
            // Any build not ended, even one past BUILD_STALE: it may still end
            // and mark the entry built, so the changes it missed must be in it.
            $live = $st['ready'] || $st['building'] > 0;
            $p = $this->parseSpec($st['spec']);
            if ($p !== false && $p['kind'] === 'plain' && !self::isComposite($f)) {
                if ($live) $out[$f] = ['kind' => 'plain', 'mark' => $p['mark'], 'gen' => $st['gen']];
                continue;
            }
            $def = null;
            $paused = false;
            if (self::isComposite($f)) {
                if ($defs === null) $defs = $this->defsOf($pid);
                $d = isset($defs[$f]) ? $defs[$f] : null;
                // A build paused under another key version or secret counts
                // too: the sync row does not say which secret kept the
                // project, so it stops here as a live entry does, or it would
                // go on past the changes this sync moves beyond.
                $paused = $d !== null && $d['cursor'] !== '' && $d['gen'] === $st['gen'];
                if ($p !== false && $p['kind'] === 'c' && $d !== null && md5($d['def']) === $p['hash']) {
                    $def = self::compositeDef(json_decode($d['def'], true));
                }
            }
            if ($def !== null) {
                if ($live || $paused) $out[$f] = ['kind' => 'c', 'def' => $def, 'gen' => $st['gen']];
            } elseif ($live || $paused) {
                $stale[] = (string) $f;
            }
        }
        foreach (array_chunk($stale, 200) as $part) {
            $this->db->execNoCount('UPDATE ' . Schema::table('lookup_field') . ' SET ready = 0, building_at = 0, gen = gen + 1
                WHERE project_id = ? AND field_name IN (' . implode(', ', array_fill(0, count($part), '?')) . ')', array_merge([$pid], $part));
            foreach ($part as $f) {
                $states[$f]['ready'] = false;
                $states[$f]['building'] = 0;
                $states[$f]['gen']++;
            }
        }
        return $out;
    }

    /**
     * Every field of the project, or those of $fields, stops answering until
     * built again, and a build running now will not count.
     */
    private function resetFields($pid, ?array $fields = null)
    {
        $sql = 'UPDATE ' . Schema::table('lookup_field') . ' SET ready = 0, building_at = 0, gen = gen + 1
            WHERE project_id = ?';
        if ($fields === null) {
            $this->db->execNoCount($sql, [$pid]);
            return;
        }
        if (!$fields) return;
        $this->db->execNoCount($sql . ' AND field_name IN (' . implode(', ', array_fill(0, count($fields), '?')) . ')',
            array_merge([$pid], array_map('strval', $fields)));
    }

    /** Forget what the project's fields and records were indexed at; their keys are replaced at the next build. */
    private function resetProject($pid)
    {
        $this->resetFields($pid);
        do {
            $this->db->exec('DELETE FROM ' . Schema::table('lookup_record') . ' WHERE project_id = ? LIMIT ' . self::DELETE_ROWS, [$pid]);
        } while ($this->db->affected() >= self::DELETE_ROWS);
    }

    /**
     * A field no lookup asks for any more, or of a project that is gone: its
     * keys and its row. It stops answering first, so a drop that fails part
     * way leaves an entry that is built again, not one that answers from half
     * its keys. With $until > 0 its keys go only until then: false when some
     * are left, and the row is kept for the next run.
     */
    private function dropField($pid, $field, $until = 0)
    {
        $this->db->execNoCount('UPDATE ' . Schema::table('lookup_field') . ' SET ready = 0, building_at = 0, gen = gen + 1
            WHERE project_id = ? AND field_name = ?', [$pid, $field]);
        // Out of time: the row stays, with its spec, for the next run to finish.
        if (!$this->deleteKeys($pid, $field, null, $until)) return false;
        if (self::isComposite($field)) {
            $this->db->execNoCount('DELETE FROM ' . Schema::table('lookup_def') . ' WHERE project_id = ? AND field_name = ?', [$pid, $field]);
        }
        $this->db->execNoCount('DELETE FROM ' . Schema::table('lookup_field') . ' WHERE project_id = ? AND field_name = ?', [$pid, $field]);
        return true;
    }

    private function touch($pid, $field, $now)
    {
        $this->db->execNoCount('UPDATE ' . Schema::table('lookup_field') . ' SET asked_at = ? WHERE project_id = ? AND field_name = ?',
            [$now, $pid, $field]);
    }

    /** The records whose key in one of $fieldMarks equals $value's. */
    private function keysFor($pid, array $fieldMarks, $value)
    {
        $or = [];
        $params = [$pid];
        foreach ($fieldMarks as $f => $mark) {
            $or[] = '(field_name = ? AND key_hash = UNHEX(?))';
            array_push($params, (string) $f, $this->keyHex($pid, $value, $mark));
        }
        $out = [];
        foreach ($this->db->select('SELECT DISTINCT HEX(record) FROM ' . Schema::table('lookup_key')
                . ' WHERE project_id = ? AND (' . implode(' OR ', $or) . ')', $params) as $r) {
            $out[] = (string) hex2bin((string) $r[0]);
        }
        return $out;
    }

    /** One lock per project and database: only one request syncs a project at a time. */
    /** Seconds the cron waits for a project's lock: CRON_LOCK_WAIT, never past the Unix time $until. */
    private static function waitUntil($until)
    {
        return max(0, min(self::CRON_LOCK_WAIT, (int) $until - time()));
    }

    private function lock($pid, $wait)
    {
        $r = $this->db->select("SELECT GET_LOCK(CONCAT('uvlk', MD5(CONCAT(COALESCE(DATABASE(), ''), '/', ?))), ?)",
            [(string) $pid, (int) $wait]);
        return isset($r[0][0]) && (string) $r[0][0] === '1';
    }

    private function unlock($pid)
    {
        try {
            $this->db->select("SELECT RELEASE_LOCK(CONCAT('uvlk', MD5(CONCAT(COALESCE(DATABASE(), ''), '/', ?))))", [(string) $pid]);
        } catch (\Throwable $e) {
            // The connection ends with the request, and the lock with it.
            Faults::swallow($e, 'LookupIndex::unlock');
        }
    }

    /** The shortest secret taken: the module's is 64 hex characters. */
    const SECRET_MIN = 32;

    /**
     * What a spec of $secret starts with: the key version and an id of the
     * secret, 8 hex characters of a hash that says nothing else about it. A
     * secret that changed is another id, so keys made with the old one stop
     * answering; they could never match a key made with the new one, and an
     * index that still answered would say "not found" of every value.
     */
    public static function specHeadOf($secret)
    {
        return self::KEY_VERSION . '.' . substr(hash('sha256', 'uv-lookup-key-id|' . (string) $secret), 0, 8);
    }

    /** What a field's keys are built with under $secret: specHeadOf() and the field's number mark. */
    public static function specOf($secret, $mark)
    {
        return self::specHeadOf($secret) . '|' . (($mark === 'point' || $mark === 'comma') ? $mark : 'text');
    }

    /**
     * The key of $value in project $pid under $secret, as 32 hex characters:
     * the first 16 bytes of HMAC-SHA-256 over the value's lookup key (letter
     * case folded), keyed with the secret and the project id. The same value
     * has another key in another project, and no key can be worked out
     * without the secret.
     */
    public static function keyOf($secret, $pid, $value, $mark)
    {
        return substr(hash_hmac('sha256',
            Logic::lookupKey((string) $value, true, ($mark === 'point' || $mark === 'comma') ? $mark : null),
            (string) $secret . '|lookup|' . (int) $pid), 0, 32);
    }

    /** specHeadOf() for this object's secret. Only called with one (candidates, maintain). */
    private function specHead()
    {
        return self::specHeadOf($this->secret);
    }

    /** What a field's keys were built with: the key version, the secret's id and the field's number mark. */
    private function spec($mark)
    {
        return self::specOf($this->secret, $mark);
    }

    /** keyOf() for this object's secret. */
    private function keyHex($pid, $value, $mark)
    {
        return self::keyOf($this->secret, $pid, $value, $mark);
    }

    /**
     * Everything the index holds for $pid: its keys, its fields, its records'
     * positions, its sync row and its composite definitions (FRM-7). Called when the module is disabled
     * in the project, so no hash of a value stays behind for a project that
     * no longer uses the module. Needs no secret. The keys go a page at a
     * time. True when every statement ran.
     */
    public function forgetProject($pid)
    {
        $pid = (int) $pid;
        if ($pid <= 0 || !is_object($this->module) || !is_callable([$this->module, 'query'])) return false;
        try {
            foreach (['lookup_key', 'lookup_record'] as $t) {
                do {
                    $this->db->exec('DELETE FROM ' . Schema::table($t) . ' WHERE project_id = ? LIMIT ' . self::DELETE_ROWS, [$pid]);
                } while ($this->db->affected() >= self::DELETE_ROWS);
            }
            foreach (['lookup_field', 'lookup_sync', 'lookup_def'] as $t) {
                $this->db->execNoCount('DELETE FROM ' . Schema::table($t) . ' WHERE project_id = ?', [$pid]);
            }
            unset($this->rows[$pid], $this->sources[$pid]);
            return true;
        } catch (\Throwable $e) {
            Faults::swallow($e, 'LookupIndex::forgetProject');
            return false;
        }
    }

    /**
     * The records whose key in entry $name is $keyHex, after $after in byte
     * order, at most $limit (0: all). One range of uv_lookup_key's primary
     * key: rows read equal rows returned.
     *
     * @return string[]
     */
    private function recordsOf($pid, $name, $keyHex, $after, $limit)
    {
        $sql = 'SELECT HEX(record) FROM ' . Schema::table('lookup_key') . ' WHERE project_id = ? AND field_name = ? AND key_hash = UNHEX(?)';
        $params = [$pid, $name, $keyHex];
        if ($after !== '') {
            $sql .= ' AND record > UNHEX(?)';
            $params[] = bin2hex($after);
        }
        $out = [];
        foreach ($this->db->select($sql . ' ORDER BY record' . ($limit > 0 ? ' LIMIT ' . $limit : ''), $params) as $r) {
            $out[] = (string) hex2bin((string) $r[0]);
        }
        return $out;
    }

    /** The entry of one field's values in a field of number mark $mark (null for text). */
    private function plainEntry($mark)
    {
        $mark = ($mark === 'point' || $mark === 'comma') ? $mark : null;
        return ['kind' => 'plain', 'mark' => $mark, 'spec' => $this->spec($mark)];
    }

    /** A composite entry of a definition compositeDef() returned. */
    private function compositeEntry(array $def)
    {
        return ['kind' => 'c', 'def' => $def, 'spec' => $this->specHead() . '|c|' . md5(self::defJson($def))];
    }

    /**
     * What a spec says: ['kind' => 'plain', 'mark' => ?string] or
     * ['kind' => 'c', 'hash' => md5 of the definition], or false for another
     * key version or secret, or a spec this version cannot read.
     */
    private function parseSpec($spec)
    {
        $p = explode('|', (string) $spec);
        if (count($p) < 2 || $p[0] !== $this->specHead()) return false;
        if (count($p) === 2 && in_array($p[1], ['text', 'point', 'comma'], true)) {
            return ['kind' => 'plain', 'mark' => $p[1] === 'text' ? null : $p[1]];
        }
        if (count($p) === 3 && $p[1] === 'c' && preg_match('/^[0-9a-f]{32}\z/', $p[2])) return ['kind' => 'c', 'hash' => $p[2]];
        return false;
    }

    /** The entry a stored spec stands for, to build it again, or null when it cannot be built here. */
    private function storedEntry($pid, $name, $spec)
    {
        $p = $this->parseSpec($spec);
        if ($p === false) return null;
        if ($p['kind'] === 'plain') return self::isComposite($name) ? null : $this->plainEntry($p['mark']);
        if (!self::isComposite($name)) return null;
        $d = $this->defRow($pid, $name);
        if ($d === null || md5($d['def']) !== $p['hash']) return null;
        $def = self::compositeDef(json_decode($d['def'], true));
        if ($def === null || self::compositeName($def) !== $name) return null;
        $entry = $this->compositeEntry($def);
        return $entry['spec'] === $spec ? $entry : null;
    }

    private static function isComposite($name)
    {
        return isset($name[0]) && $name[0] === '#';
    }

    /**
     * The hex key of composite $material in entry $name: keyed as keyOf()
     * keys a field's value, with the secret and the project, and the entry
     * in the message, so a key means nothing without the secret, in another
     * project or in another entry.
     */
    private function compositeKeyHex($pid, $name, $material)
    {
        return substr(hash_hmac('sha256', (string) $name . "\0" . (string) $material,
            (string) $this->secret . '|lookup|' . (int) $pid), 0, 32);
    }

    /**
     * A composite definition in the one form its name, spec and keys are made
     * from, or null when it is not one (bad field names, too many parts, an
     * unknown scope, a repeat bucket that is not an event id and a form name
     * or '').
     */
    private static function compositeDef($def)
    {
        if (!is_array($def)) return null;
        $ok = function ($f) { return is_string($f) && preg_match('/^[a-z][a-z0-9_]{0,99}\z/', $f); };
        $slot = isset($def['slot']) ? $def['slot'] : null;
        $with = isset($def['with']) && is_array($def['with']) ? array_values($def['with']) : [];
        $scope = isset($def['scope']) ? $def['scope'] : 'project';
        if (!$ok($slot) || count($with) > self::MAX_PARTS || !in_array($scope, ['project', 'event', 'dag'], true)) return null;
        foreach ($with as $w) {
            if (!$ok($w) || $w === $slot) return null;
        }
        if (count(array_unique($with)) !== count($with)) return null;
        $marks = [];
        $rep = [];
        foreach (array_merge([$slot], $with) as $f) {
            $m = isset($def['marks'][$f]) ? $def['marks'][$f] : null;
            $marks[$f] = ($m === 'point' || $m === 'comma') ? $m : null;
            $byEvent = isset($def['rep'][$f]) ? $def['rep'][$f] : [];
            if (!is_array($byEvent)) return null;
            $buckets = [];
            foreach ($byEvent as $e => $b) {
                // '' is a repeating event; a form name, a form that repeats in the event.
                if (!ctype_digit((string) $e) || (int) $e < 1 || !is_string($b) || ($b !== '' && !preg_match('/^[a-z0-9_]{1,100}\z/', $b))) return null;
                $buckets[(string) (int) $e] = $b;
            }
            ksort($buckets, SORT_STRING);
            if ($buckets) $rep[$f] = $buckets;
        }
        ksort($marks, SORT_STRING);
        ksort($rep, SORT_STRING);
        return ['slot' => $slot, 'with' => $with, 'marks' => $marks, 'scope' => $scope, 'rep' => $rep];
    }

    /** The entry's name: "#" and the MD5 of what the key is made of, which no REDCap field name can be. */
    private static function compositeName(array $def)
    {
        return '#' . md5(json_encode([$def['slot'], $def['with'], $def['scope']]));
    }

    private static function defJson(array $def)
    {
        return json_encode($def);
    }

    /** The data table fields a composite entry's keys are read from. */
    private static function compositeFields(array $def)
    {
        $out = array_merge([$def['slot']], $def['with']);
        if ($def['scope'] === 'dag') $out[] = '__GROUPID__';
        return $out;
    }

    /**
     * The key material of one entry: the parts' lookup keys, hex, joined by
     * ".", then the event (scope event) or the group (scope dag). A blank
     * part is the lookup key of '' ("="), the same as one of only spaces.
     */
    private static function compositeMaterial(array $def, $value, array $with, $event, $dag)
    {
        $p = [bin2hex(Logic::lookupKey((string) $value, true, $def['marks'][$def['slot']]))];
        foreach ($def['with'] as $w) {
            $v = isset($with[$w]) && is_scalar($with[$w]) ? (string) $with[$w] : '';
            $p[] = bin2hex(Logic::lookupKey($v, true, $def['marks'][$w]));
        }
        if ($def['scope'] === 'event') $p[] = 'e' . (string) $event;
        if ($def['scope'] === 'dag') $p[] = 'd' . (string) $dag;
        return implode('.', $p);
    }

    /**
     * The key materials of one record, from its rows [field, value, event_id,
     * instance] of compositeFields(): one per entry that holds the first field
     * with a value, where the entries are the ones the scan reads
     * (ScanRuns::recordContexts()). Each event has its row, and each instance
     * of each repeat bucket (def['rep']: a form that repeats there, or '' for a
     * repeating event) is merged over that row. So a field on the event's row
     * is seen from every entry of the event, a field of a bucket only from that
     * bucket's instances, and blank elsewhere. The data table stores instance
     * 1 with no instance, as it stores the event's row; def['rep'] tells them
     * apart. A row of a field that does not repeat there counts only when it
     * is instance 1. A record's group is its __GROUPID__ row, '' when it has
     * none or that row is blank.
     *
     * @return array material => true
     */
    private static function compositeMaterials(array $rows, array $def)
    {
        $cells = [];    // event => '-' (its row) or 'b:' . bucket => instance => field => value
        $dag = '';
        foreach ($rows as $r) {
            if ($r[0] === '__GROUPID__') {
                if ($dag === '' && $r[1] !== '') $dag = $r[1];
                continue;
            }
            $evt = (string) $r[2];
            $i = ($r[3] === null || $r[3] === '' || (int) $r[3] < 1) ? 1 : (int) $r[3];
            if (isset($def['rep'][$r[0]][$evt])) $cells[$evt]['b:' . $def['rep'][$r[0]][$evt]][$i][$r[0]] = $r[1];
            elseif ($i === 1) $cells[$evt]['-'][1][$r[0]] = $r[1];
        }
        $slot = $def['slot'];
        $out = [];
        foreach ($cells as $evt => $byBucket) {
            $base = isset($byBucket['-'][1]) ? $byBucket['-'][1] : [];
            $entries = [$base];
            foreach ($byBucket as $b => $byInst) {
                if ($b === '-') continue;
                foreach ($byInst as $vals) $entries[] = array_merge($base, $vals);
            }
            foreach ($entries as $vals) {
                if (!isset($vals[$slot]) || Logic::lookupTrim($vals[$slot]) === '') continue;
                $with = [];
                foreach ($def['with'] as $w) $with[$w] = isset($vals[$w]) ? $vals[$w] : '';
                $out[self::compositeMaterial($def, $vals[$slot], $with, (string) $evt, $dag)] = true;
            }
        }
        return $out;
    }

    /** @return array|null ['def' => json, 'cursor' => string, 'gen' => int] */
    private function defRow($pid, $name)
    {
        $r = $this->db->select('SELECT def, build_cursor, build_gen FROM ' . Schema::table('lookup_def') . ' WHERE project_id = ? AND field_name = ?',
            [$pid, $name]);
        if (!isset($r[0])) return null;
        return ['def' => (string) $r[0][0], 'cursor' => (string) $r[0][1], 'gen' => (int) $r[0][2]];
    }

    /** @return array name => ['def' => json, 'cursor' => string, 'gen' => int] */
    private function defsOf($pid)
    {
        $out = [];
        foreach ($this->db->select('SELECT field_name, def, build_cursor, build_gen FROM ' . Schema::table('lookup_def') . ' WHERE project_id = ?',
                [$pid]) as $r) {
            $out[(string) $r[0]] = ['def' => (string) $r[1], 'cursor' => (string) $r[2], 'gen' => (int) $r[3]];
        }
        return $out;
    }

    /**
     * Store a composite entry's definition, with where its build stands
     * ($cursor and $gen; null keeps them, unless the definition changed, which
     * clears them). MySQL applies the assignments in order, so the cursor and
     * generation read the old definition.
     */
    private function saveDef($pid, $name, $json, $cursor, $gen)
    {
        if ($cursor === null) {
            $this->db->execNoCount('INSERT INTO ' . Schema::table('lookup_def') . ' (project_id, field_name, def, build_cursor, build_gen)
                VALUES (?, ?, ?, \'\', 0)
                ON DUPLICATE KEY UPDATE build_cursor = IF(def = VALUES(def), build_cursor, \'\'),
                    build_gen = IF(def = VALUES(def), build_gen, 0), def = VALUES(def)', [$pid, $name, $json]);
            return;
        }
        $this->db->execNoCount('INSERT INTO ' . Schema::table('lookup_def') . ' (project_id, field_name, def, build_cursor, build_gen)
            VALUES (?, ?, ?, UNHEX(?), ?)
            ON DUPLICATE KEY UPDATE def = VALUES(def), build_cursor = VALUES(build_cursor), build_gen = VALUES(build_gen)',
            [$pid, $name, $json, bin2hex((string) $cursor), (int) $gen]);
    }
}
