<?php
namespace INSPIRE\UniversalValidator\Scan;

require_once __DIR__ . '/../Faults.php';
require_once __DIR__ . '/Hmac.php';

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
 * where the key is a keyed hash (Hmac::P_LOOKUP, with the project and the
 * field in the message) of Logic::lookupKey(value, letter case folded, the
 * field's number mark), cut to 16 bytes. Folding always gives a superset of a
 * lookup that keeps letter case, and a hash that collides only adds a record.
 * So the records this class returns are a SUPERSET of the records that hold
 * the value, and the caller confirms them against the data table rows of
 * those records (FieldValues::recordsAmong) before it uses one.
 *
 * KEYED, NOT A PLAIN DIGEST. Up to 2.4.0 the key was the MD5 of the lookup
 * key, with no project in it: a dictionary turned a stored date of birth,
 * phone number or short ID back into the value, and the same value had the
 * same row in every project, which Hmac's rule forbids. With no secret to key
 * with, the index does not answer (null) and the lookups read the data table;
 * it never falls back to an unkeyed hash. A field's spec carries a
 * fingerprint of the secret, so a replaced secret rebuilds every field
 * instead of leaving keys no lookup can match. Each cron run first clears the
 * keys of entries of another key version or secret (retireOld()), with the
 * index turned off too, so an upgrade from 2.4 does not leave the digests.
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
 * A COMPOSITE BUILD CAN PAUSE. It walks the records that hold the first field
 * in byte order, BATCH at a time, listing at most LIST_RECORDS of them from
 * one read (recordsAfter()). A cron that reaches its deadline keeps its
 * place in uv_lookup_def.build_cursor and the next run goes on from there.
 * Meanwhile the sync keeps the records already indexed current, as it does for
 * a build that runs (keptOf()), and a reset of the project starts it again.
 *
 * BUILT ONCE, BY THE CRON WHEN LARGE. A field's first build reads all its
 * rows. A lookup builds a field of up to LIVE_BUILD_ROWS rows itself; a
 * larger one it marks as wanted (uv_lookup_field.wanted_at) and the module's
 * cron builds it (maintain()), letting the project's lock go while it reads
 * the data table, so the project's other fields keep answering. Until then
 * the lookup reads the data table, as it did before the index. Where no cron
 * has run for CRON_GRACE seconds (the caller passes when the last one ran), a
 * lookup builds a field that has waited that long itself.
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
 * data or log table changed, the log was pruned past the window, the data
 * dictionary changed (a metadata row in the log), too many records changed
 * at once, the field's number mark or this class's key version changed, or
 * the sync row was kept under another key version or secret (that sync kept
 * only its own entries current).
 *
 * NULL WHEN IT CANNOT ANSWER. The tables are missing, the log or data table
 * cannot be resolved, another request holds the project's lock for longer
 * than LOCK_WAIT, the field is not built yet and this request may not build
 * it (too large, being built elsewhere, or this request has used its build),
 * or any read fails. The caller then reads the data table as before, so the
 * index only ever saves work.
 *
 * PHP 7.4.
 */
final class LookupIndex
{
    /**
     * Bump when Logic::lookupKey or keyHex() changes what a value's key is.
     * 2: keyed hashes in place of MD5; every field of version 1 is rebuilt.
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
    /** A field wanted this long, with no cron run this long, is built by a lookup anyway. */
    const CRON_GRACE = 900;
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
    /** Entries of another key version or secret a cron run clears at most. */
    const RETIRE_ROWS = 500;
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
    /** @var string|null the module's secret, null when there is none (the index then never answers) */
    private $key;
    /** @var string the secret's fingerprint in every spec, so a new secret rebuilds every entry */
    private $keyPrint = '';

    /**
     * @param object $module anything with the framework's query($sql, $params)
     * @param array $options 'liveBuildRows' => int, in place of LIVE_BUILD_ROWS;
     *                       'cronAt' => int, when the cron last ran;
     *                       'key' => string, the module's protected secret;
     *                       'listRecords' => int, in place of LIST_RECORDS
     */
    public function __construct($module, array $options = [])
    {
        $this->module = $module;
        $this->db = new ModuleDb($module);
        $this->liveBuildRows = isset($options['liveBuildRows']) ? (int) $options['liveBuildRows'] : self::LIVE_BUILD_ROWS;
        $this->cronAt = isset($options['cronAt']) ? (int) $options['cronAt'] : 0;
        $this->listRecords = isset($options['listRecords']) ? max(1, (int) $options['listRecords']) : self::LIST_RECORDS;
        $key = isset($options['key']) ? $options['key'] : null;
        $this->key = (is_string($key) && $key !== '') ? $key : null;
        if ($this->key !== null) $this->keyPrint = substr(hash_hmac('sha256', 'uv lookup index key print', $this->key), 0, 8);
    }

    /** Whether the index can work at all: a module to query and a secret to key with. */
    private function usable()
    {
        return $this->key !== null && is_object($this->module) && is_callable([$this->module, 'query']);
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
        if ($pid <= 0 || !$fieldMarks || !$this->usable()) return null;
        $entries = [];
        foreach ($fieldMarks as $f => $mark) $entries[(string) $f] = $this->plainEntry($mark);
        try {
            $src = $this->sources($pid);
            if ($src === null) return null;
            if (!$this->lock($pid, self::LOCK_WAIT)) return null;
            try {
                if (!$this->prepare($pid, $src, $entries, false)) return null;
                return $this->keysFor($pid, $fieldMarks, $value);
            } finally {
                $this->unlock($pid);
            }
        } catch (\Throwable $e) {
            Faults::swallow($e, 'LookupIndex::candidates');
            return null;
        }
    }

    /**
     * The records of $pid whose entry of $def holds the key of $parts, in byte
     * order, those after $after only, at most $limit of them (0: all). A
     * superset the caller confirms, as candidates() returns. Null when the
     * index cannot answer, or $def is not one compositeDef() accepts.
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
        if ($pid <= 0 || $def === null || !$this->usable()) return null;
        $value = isset($parts['value']) && is_scalar($parts['value']) ? (string) $parts['value'] : '';
        if (Logic::lookupTrim($value) === '') return [];
        $with = isset($parts['with']) && is_array($parts['with']) ? $parts['with'] : [];
        $event = isset($parts['event']) && is_scalar($parts['event']) ? (string) $parts['event'] : '';
        $dag = isset($parts['dag']) && is_scalar($parts['dag']) ? (string) $parts['dag'] : '';
        $name = self::compositeName($def);
        try {
            $src = $this->sources($pid);
            if ($src === null) return null;
            if (!$this->lock($pid, self::LOCK_WAIT)) return null;
            try {
                if (!$this->prepare($pid, $src, [$name => $this->compositeEntry($def)], false)) return null;
                $key = $this->keyHex($pid, $name, self::compositeMaterial($def, $value, $with, $event, $dag));
                return $this->recordsOf($pid, $name, $key, (string) $after, (int) $limit);
            } finally {
                $this->unlock($pid);
            }
        } catch (\Throwable $e) {
            Faults::swallow($e, 'LookupIndex::compositeCandidates');
            return null;
        }
    }

    /**
     * The cron's work, until the Unix time $until: build the fields lookups
     * wanted and could not build, rebuild a field REFRESH_BEFORE short of
     * REBUILD_AFTER, start again a build that died, and drop the keys of a
     * field no lookup asked for in UNUSED_AFTER (or whose project is gone).
     * One field at a time, each under its project's lock, which a build lets
     * go while it reads the data table.
     *
     * @return array ['built' => int, 'dropped' => int, 'waiting' => int, 'left' => int]
     */
    public function maintain($until)
    {
        $out = ['built' => 0, 'dropped' => 0, 'waiting' => 0, 'left' => 0];
        // No secret: nothing is built or dropped. A secret that comes back finds
        // every entry as it was; a new one rebuilds them (the spec's key print).
        if (!$this->usable()) return $out;
        $now = time();
        $todo = $this->db->select('SELECT project_id, field_name, spec, asked_at FROM ' . Schema::table('lookup_field') . '
            WHERE wanted_at > 0 OR asked_at < ? OR (ready = 1 AND building_at = 0 AND built_at < ?)
               OR (building_at > 0 AND building_at < ?)
            ORDER BY wanted_at = 0, wanted_at, built_at LIMIT 500',
            [$now - self::UNUSED_AFTER, $now - (self::REBUILD_AFTER - self::REFRESH_BEFORE), $now - self::BUILD_STALE]);
        foreach ($todo as $i => $t) {
            if (time() >= $until) {
                $out['left'] = count($todo) - $i;
                break;
            }
            $pid = (int) $t[0];
            $field = (string) $t[1];
            try {
                $src = $this->sources($pid);
                if (!$this->lock($pid, self::CRON_LOCK_WAIT)) {
                    $out['waiting']++;
                    continue;
                }
                try {
                    // An entry of another key version or secret, or a composite
                    // whose definition is gone, cannot be built here: it goes,
                    // and the next lookup that wants it starts it again.
                    $entry = $src === null ? null : $this->storedEntry($pid, $field, (string) $t[2]);
                    if ($entry === null || (int) $t[3] < $now - self::UNUSED_AFTER) {
                        $this->dropField($pid, $field);
                        $out['dropped']++;
                        continue;
                    }
                    // Each field syncs afresh: the lock was free while the last one built.
                    unset($this->rows[$pid]);
                    $this->builds = 0;
                    $before = $this->built;
                    if (!$this->prepare($pid, $src, [$field => $entry], true, $until)) $out['waiting']++;
                    elseif ($this->built > $before) $out['built']++;
                } finally {
                    $this->unlock($pid);
                }
            } catch (\Throwable $e) {
                Faults::swallow($e, 'LookupIndex::maintain');
                $out['waiting']++;
            }
        }
        return $out;
    }

    /**
     * The cron's first step, run whether or not the index is on or has a
     * secret: clear the keys of the entries keyed by another key version or
     * another secret, until the Unix time $until, RETIRE_ROWS entries a run.
     * Up to 2.4.0 a key was the MD5 of the value (key version 1), which a
     * dictionary turns back into the value, so an upgrade must not leave them
     * in the table until a lookup happens to ask for the field again.
     *
     * Without a secret, only the entries of another key version are cleared:
     * whether a current one was keyed with the secret that may come back
     * cannot be told. A plain field a lookup asked for in UNUSED_AFTER is
     * queued for the cron to build again under the current secret, so the
     * lookups do not all read the data table until each is asked once more;
     * any other entry goes, with its definition.
     *
     * @return array ['retired' => int, 'requeued' => int, 'waiting' => int, 'left' => int]
     */
    public function retireOld($until)
    {
        $out = ['retired' => 0, 'requeued' => 0, 'waiting' => 0, 'left' => 0];
        if (!is_object($this->module) || !is_callable([$this->module, 'query'])) return $out;
        $now = time();
        $current = $this->key !== null ? $this->stamp() . '|' : self::KEY_VERSION . '|';
        try {
            $todo = $this->db->select('SELECT project_id, field_name, spec, asked_at FROM ' . Schema::table('lookup_field')
                . ' WHERE spec NOT LIKE ? ORDER BY project_id, field_name LIMIT ' . self::RETIRE_ROWS, [$current . '%']);
        } catch (\Throwable $e) {
            Faults::swallow($e, 'LookupIndex::retireOld');
            return $out;
        }
        foreach ($todo as $i => $t) {
            if (time() >= $until) {
                $out['left'] = count($todo) - $i;
                break;
            }
            $pid = (int) $t[0];
            $field = (string) $t[1];
            try {
                if (!$this->lock($pid, self::CRON_LOCK_WAIT)) {
                    $out['waiting']++;
                    continue;
                }
                try {
                    $mark = ($this->key !== null && !self::isComposite($field) && (int) $t[3] >= $now - self::UNUSED_AFTER)
                        ? self::oldPlainMark((string) $t[2]) : false;
                    if ($mark === false) {
                        $this->dropField($pid, $field);
                        $out['retired']++;
                        continue;
                    }
                    // It stops answering and takes the current spec before its keys
                    // go; wipe makes the build clear any a failed delete left.
                    $this->db->execNoCount('UPDATE ' . Schema::table('lookup_field') . ' SET ready = 0, building_at = 0, gen = gen + 1,
                        spec = ?, wipe = 1, wanted_at = IF(wanted_at = 0, ?, wanted_at) WHERE project_id = ? AND field_name = ?',
                        [$this->plainEntry($mark)['spec'], $now, $pid, $field]);
                    $this->deleteKeys($pid, $field, null);
                    $out['requeued']++;
                } finally {
                    $this->unlock($pid);
                }
            } catch (\Throwable $e) {
                Faults::swallow($e, 'LookupIndex::retireOld');
                $out['waiting']++;
            }
        }
        return $out;
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
            // A sync row written under another key version or secret: that
            // sync kept only its own entries current, so none can be trusted.
            $fresh = $row === null || $row['log'] !== $src['log'] || $row['data'] !== $src['data'] || $row['stamp'] !== $this->stamp();
            if ($fresh) {
                $this->resetProject($pid);
                // A position from before the last OVERLAP seconds, read from the
                // log's times where an index allows it. Without one, nothing is
                // built until a sample taken now is OVERLAP seconds old.
                $floor = $this->tsFloor($src['log'], $now - self::OVERLAP, $top);
                $row = ['log' => $src['log'], 'data' => $src['data'], 'meta' => '0', 'open' => 0, 'stamp' => $this->stamp()];
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
        if ($now < $row['buildAfter']) return false;
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
            if ($building || $this->builds >= self::BUILDS_PER_REQUEST) return false;
            if (!$cron && !$this->mayBuildNow($pid, $src, $f, $entry, $st, $now)) return false;
            $this->builds++;
            $ok = $entry['kind'] === 'plain'
                ? $this->build($pid, $f, $spec, $entry['mark'], $st, $cron)
                : $this->buildComposite($pid, $src, $f, $entry, $st, $cron, $cron ? (int) $until : 0);
            if (!$ok) return false;
        }
        return true;
    }

    /**
     * Whether a lookup may build $field itself: the field has at most
     * liveBuildRows rows in the data table, or it has waited CRON_GRACE and
     * no cron has run for as long (a cron with a queue still builds it).
     * Otherwise the field is marked as wanted for the cron, with its new
     * spec; keys of another spec are cleared before the build. A composite
     * entry is counted by its first field's rows, and its definition is
     * stored first, so the cron can build it.
     */
    private function mayBuildNow($pid, array $src, $field, array $entry, $st, $now)
    {
        $spec = $entry['spec'];
        $wanted = $st !== null ? $st['wanted'] : 0;
        if ($wanted > 0) return $now - $wanted >= self::CRON_GRACE && $now - $this->cronAt >= self::CRON_GRACE;
        $rowsOf = $entry['kind'] === 'plain' ? $field : $entry['def']['slot'];
        // Counted only as far as the limit: a field of 400,000 rows costs 20,001 index entries.
        $r = $this->db->select('SELECT COUNT(*) FROM (SELECT 1 FROM ' . $src['data'] . ' WHERE project_id = ? AND field_name = ? LIMIT '
            . ($this->liveBuildRows + 1) . ') AS n', [$pid, $rowsOf]);
        if (isset($r[0][0]) && (int) $r[0][0] <= $this->liveBuildRows) return true;
        if ($entry['kind'] !== 'plain') $this->saveDef($pid, $field, self::defJson($entry['def']), null, null);
        // MySQL applies these assignments in order, so ready and wipe read the old spec.
        $this->db->execNoCount('INSERT INTO ' . Schema::table('lookup_field') . '
            (project_id, field_name, spec, ready, built_at, gen, building_at, wanted_at, asked_at, wipe)
            VALUES (?, ?, ?, 0, 0, 0, 0, ?, ?, 0)
            ON DUPLICATE KEY UPDATE ready = IF(spec = VALUES(spec), ready, 0), wipe = IF(spec = VALUES(spec), wipe, 1),
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
                $reset = true;
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
        $r = $this->db->select('SELECT ' . implode(', ', $cols) . ' FROM ' . $log
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
            (int) $row['buildAfter'], $row['samples'], (int) $row['open'], (string) $row['stamp']]);
    }

    /**
     * Index every row the data table holds for $field, as generation gen + 1.
     *
     * Keys of another spec (or of a field marked to wipe) are cleared first,
     * under the lock. Otherwise the old keys stay, a field that answered goes
     * on answering from old and new, and the keys of older generations go at
     * the end. $release (the cron) lets the project's lock go while the rows
     * are read and written; the build then counts only if no reset or other
     * build changed the field's generation meanwhile.
     */
    private function build($pid, $field, $spec, $mark, $st, $release)
    {
        $ft = Schema::table('lookup_field');
        $kt = Schema::table('lookup_key');
        $now = time();
        $keep = $st !== null && $st['spec'] === $spec && !$st['wipe'];
        $gen = ($st !== null ? $st['gen'] : 0) + 1;
        if (!$keep) $this->deleteKeys($pid, $field, null);
        $this->db->execNoCount('INSERT INTO ' . $ft . '
            (project_id, field_name, spec, ready, built_at, gen, building_at, wanted_at, asked_at, wipe)
            VALUES (?, ?, ?, ?, 0, ?, ?, 0, ?, 0)
            ON DUPLICATE KEY UPDATE spec = VALUES(spec), ready = VALUES(ready), gen = VALUES(gen),
                building_at = VALUES(building_at), wipe = 0, asked_at = GREATEST(asked_at, VALUES(asked_at))',
            // The cron is not a lookup: it leaves the field's last-asked time as it was.
            [$pid, $field, $spec, ($keep && $st['ready']) ? 1 : 0, $gen, $now, ($release && $st !== null) ? $st['asked'] : $now]);
        if ($release) $this->unlock($pid);
        $ok = $this->writeKeys($pid, $field, $mark, $gen);
        if ($release && !$this->lock($pid, self::CRON_LOCK_WAIT)) return false;
        $cur = $this->db->select('SELECT gen, building_at FROM ' . $ft . ' WHERE project_id = ? AND field_name = ?', [$pid, $field]);
        if (!isset($cur[0]) || (int) $cur[0][0] !== $gen || (int) $cur[0][1] !== $now) return false;
        if (!$ok) {
            $this->db->execNoCount('UPDATE ' . $ft . ' SET building_at = 0 WHERE project_id = ? AND field_name = ? AND gen = ?', [$pid, $field, $gen]);
            return false;
        }
        if ($keep) $this->deleteKeys($pid, $field, $gen);
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
        $ok = (new FieldValues($this->module))->eachRow($pid, $field, null, function ($rec, $evt, $value) use (&$buf, $pid, $field, $mark, $flush) {
            $buf[bin2hex($rec) . ':' . $this->keyHex($pid, $field, self::plainMaterial($value, $mark))] = true;
            if (count($buf) >= self::INSERT_ROWS) $flush();
        });
        if (!$ok) return false;
        $flush();
        return true;
    }

    /**
     * Index a composite entry from the data table, as build() indexes a
     * field, BATCH records at a time in byte order.
     *
     * A build of the same definition and generation that paused (its cursor
     * is set) goes on from its cursor; any other starts at generation gen + 1
     * and clears the keys of another spec first. Keys of older generations go
     * when the build ends, so an entry that answered goes on answering from
     * old and new keys meanwhile. $until > 0 (the cron) pauses the build there:
     * the cursor is saved, the entry is marked as wanted, so the next cron run
     * picks it up, and false is returned. At least one batch is written per
     * call, so every run moves it on.
     */
    private function buildComposite($pid, array $src, $name, array $entry, $st, $release, $until)
    {
        $ft = Schema::table('lookup_field');
        $now = time();
        $json = self::defJson($entry['def']);
        $same = $st !== null && $st['spec'] === $entry['spec'] && !$st['wipe'];
        $saved = $this->defRow($pid, $name);
        if ($same && $saved !== null && $saved['def'] === $json && $saved['cursor'] !== '' && $saved['gen'] === $st['gen']) {
            $gen = $st['gen'];
            $cursor = $saved['cursor'];
        } else {
            $gen = ($st !== null ? $st['gen'] : 0) + 1;
            $cursor = '';
            if (!$same) $this->deleteKeys($pid, $name, null);
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
        $this->deleteKeys($pid, $name, $gen);
        $this->saveDef($pid, $name, $json, '', $gen);
        $this->db->execNoCount('UPDATE ' . $ft . ' SET ready = 1, built_at = ?, building_at = 0, wanted_at = 0
            WHERE project_id = ? AND field_name = ? AND gen = ?', [$now, $pid, $name, $gen]);
        $this->built++;
        return true;
    }

    /**
     * Write the keys of every record after $cursor that holds the entry's
     * first field, as generation $gen: [ok, cursor], where the cursor is the
     * last record written when the build paused at $until, '' when it ended.
     * The records come from recordsAfter(), listRecords at a time, so memory
     * stays bounded however many records hold the field.
     *
     * @return array{0: bool, 1: string}
     */
    private function writeCompositeKeys($pid, $dataTable, $name, array $def, $gen, $cursor, $until)
    {
        try {
            $fields = self::compositeFields($def);
            while (true) {
                list($recs, $more) = $this->recordsAfter($pid, $dataTable, $def['slot'], $cursor, $this->listRecords);
                if ($recs === null) return [false, ''];
                $chunks = array_chunk($recs, self::BATCH);
                foreach ($chunks as $i => $chunk) {
                    $buf = [];
                    foreach ($this->compositeKeysOf($pid, $dataTable, [$name => $def], $chunk, $fields) as $rh => $byName) {
                        foreach ($byName[$name] as $k => $_) $buf[$rh . ':' . $k] = true;
                    }
                    foreach (array_chunk($buf, self::INSERT_ROWS, true) as $part) $this->insertKeys($pid, $name, $part, $gen);
                    $cursor = (string) end($chunk);
                    $done = !$more && $i === count($chunks) - 1;
                    if ($until > 0 && !$done && time() >= $until) return [true, $cursor];
                }
                if (!$more) return [true, ''];
            }
        } catch (\Throwable $e) {
            Faults::swallow($e, 'LookupIndex::writeCompositeKeys');
            return [false, ''];
        }
    }

    /**
     * The first $limit records after $after, in byte order, that hold $field,
     * and whether more follow: [records, more]; records null when the read
     * failed.
     *
     * Byte order is the order the cursor compares in; SQL would sort by the
     * data table's collation. The rows stream from one read, and at most
     * 2 x $limit record ids are held: when the list reaches that, it is sorted
     * and cut to $limit, and the largest kept bounds every later row. At the
     * default listRecords (LIST_RECORDS) that is about 10 MB.
     *
     * @return array{0: ?string[], 1: bool}
     */
    private function recordsAfter($pid, $dataTable, $field, $after, $limit)
    {
        $sql = 'SELECT DISTINCT CAST(record AS BINARY) FROM ' . $dataTable . ' WHERE project_id = ? AND field_name = ?';
        $params = [$pid, $field];
        if ($after !== '') {
            // A comparison with a binary string compares bytes, as strcmp() does.
            $sql .= ' AND CAST(record AS BINARY) > ?';
            $params[] = $after;
        }
        $keep = [];
        $cap = null;
        $more = false;
        $take = function ($rec) use (&$keep, &$cap, &$more, $after, $limit) {
            if ($after !== '' && strcmp($rec, $after) <= 0) return;
            if ($cap !== null && strcmp($rec, $cap) >= 0) {
                $more = true;
                return;
            }
            $keep[] = $rec;
            if (count($keep) >= 2 * $limit) {
                sort($keep, SORT_STRING);
                $keep = array_slice($keep, 0, $limit);
                $cap = $keep[$limit - 1];
                $more = true;
            }
        };
        $q = $this->module->query($sql, $params);
        if (is_array($q)) {
            foreach ($q as $r) $take((string) array_values((array) $r)[0]);
        } elseif (is_object($q) && is_callable([$q, 'fetch_row'])) {
            while ($r = $q->fetch_row()) $take((string) $r[0]);
        } else {
            return [null, false];
        }
        sort($keep, SORT_STRING);
        if (count($keep) > $limit) {
            $keep = array_slice($keep, 0, $limit);
            $more = true;
        }
        return [$keep, $more];
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
                foreach (self::compositeMaterials($recRows, $def) as $m => $_) $out[$rh][$name][$this->keyHex($pid, $name, $m)] = true;
            }
        }
        return $out;
    }

    /** $field's keys, all of them ($below null) or those of generations below $below, a page at a time. */
    private function deleteKeys($pid, $field, $below)
    {
        $sql = 'DELETE FROM ' . Schema::table('lookup_key') . ' WHERE project_id = ? AND field_name = ?'
            . ($below === null ? '' : ' AND gen < ?') . ' LIMIT ' . self::DELETE_ROWS;
        $params = $below === null ? [$pid, $field] : [$pid, $field, (int) $below];
        do {
            $this->db->exec($sql, $params);
        } while ($this->db->affected() >= self::DELETE_ROWS);
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
                $want[$rh][$f][$this->keyHex($pid, $f, self::plainMaterial((string) $row[2], $kept[$f]['mark']))] = true;
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
        $rows = $this->db->select('SELECT CAST(pk AS BINARY) AS p, MAX(log_event_id) FROM ' . $log . '
            WHERE log_event_id > ? AND log_event_id <= ? AND project_id = ?
              AND pk IS NOT NULL AND pk <> \'\' AND LENGTH(pk) <= 400
            GROUP BY p LIMIT ' . (int) $limit, [(string) $from, (string) $to, $pid]);
        $out = [];
        foreach ($rows as $r) $out[(string) $r[0]] = (string) $r[1];
        return $out;
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
                'open' => (is_array($j) && isset($j['o']) && is_int($j['o'])) ? $j['o'] : -1,
                'stamp' => (is_array($j) && isset($j['k']) && is_string($j['k'])) ? $j['k'] : ''];
    }

    private function saveSyncRow($pid, array $row)
    {
        $this->db->execNoCount('INSERT INTO ' . Schema::table('lookup_sync') . '
            (project_id, log_table, data_table, synced_id, meta_id, build_after, samples) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE log_table = VALUES(log_table), data_table = VALUES(data_table), synced_id = VALUES(synced_id),
                meta_id = VALUES(meta_id), build_after = VALUES(build_after), samples = VALUES(samples)',
            [$pid, $row['log'], $row['data'], (string) $row['synced'], (string) $row['meta'], (int) $row['buildAfter'],
             json_encode(['s' => $row['samples'], 'o' => (int) $row['open'], 'k' => (string) $row['stamp']])]);
    }

    /** The key version and secret a sync row was kept under, stored with its samples. */
    private function stamp()
    {
        return self::KEY_VERSION . '|' . $this->keyPrint;
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
     * The entries a sync keeps current: those that answer, are being built,
     * or are composites whose build paused, keyed by this version and secret.
     * The definitions are read only when the project has a composite entry.
     *
     * An entry that answers or is being built and cannot be kept current
     * here (another key version, a composite whose definition is gone) stops
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
            $live = $st['ready'] || self::building($st, $now);
            $p = $this->parseSpec($st['spec']);
            if ($p !== false && $p['kind'] === 'plain' && !self::isComposite($f)) {
                if ($live) $out[$f] = ['kind' => 'plain', 'mark' => $p['mark'], 'gen' => $st['gen']];
                continue;
            }
            $def = null;
            $paused = false;
            if ($p !== false && $p['kind'] === 'c' && self::isComposite($f)) {
                if ($defs === null) $defs = $this->defsOf($pid);
                $d = isset($defs[$f]) ? $defs[$f] : null;
                if ($d !== null && md5($d['def']) === $p['hash']) {
                    $def = self::compositeDef(json_decode($d['def'], true));
                    $paused = $d['cursor'] !== '' && $d['gen'] === $st['gen'];
                }
            }
            if ($def !== null) {
                if ($live || $paused) $out[$f] = ['kind' => 'c', 'def' => $def, 'gen' => $st['gen']];
            } elseif ($live) {
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

    /** Every field stops answering until built again, and a build running now will not count. */
    private function resetFields($pid)
    {
        $this->db->execNoCount('UPDATE ' . Schema::table('lookup_field') . ' SET ready = 0, building_at = 0, gen = gen + 1
            WHERE project_id = ?', [$pid]);
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
     * its keys.
     */
    private function dropField($pid, $field)
    {
        $this->db->execNoCount('UPDATE ' . Schema::table('lookup_field') . ' SET ready = 0, building_at = 0, gen = gen + 1
            WHERE project_id = ? AND field_name = ?', [$pid, $field]);
        $this->deleteKeys($pid, $field, null);
        if (self::isComposite($field)) {
            $this->db->execNoCount('DELETE FROM ' . Schema::table('lookup_def') . ' WHERE project_id = ? AND field_name = ?', [$pid, $field]);
        }
        $this->db->execNoCount('DELETE FROM ' . Schema::table('lookup_field') . ' WHERE project_id = ? AND field_name = ?', [$pid, $field]);
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
            array_push($params, (string) $f, $this->keyHex($pid, (string) $f, self::plainMaterial($value, $mark)));
        }
        $out = [];
        foreach ($this->db->select('SELECT DISTINCT HEX(record) FROM ' . Schema::table('lookup_key')
                . ' WHERE project_id = ? AND (' . implode(' OR ', $or) . ')', $params) as $r) {
            $out[] = (string) hex2bin((string) $r[0]);
        }
        return $out;
    }

    /** One lock per project and database: only one request syncs a project at a time. */
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
        return ['kind' => 'plain', 'mark' => $mark, 'spec' => self::KEY_VERSION . '|' . $this->keyPrint . '|' . ($mark === null ? 'text' : $mark)];
    }

    /** A composite entry of a definition compositeDef() returned. */
    private function compositeEntry(array $def)
    {
        return ['kind' => 'c', 'def' => $def, 'spec' => self::KEY_VERSION . '|' . $this->keyPrint . '|c|' . md5(self::defJson($def))];
    }

    /**
     * What a spec says: ['kind' => 'plain', 'mark' => ?string] or
     * ['kind' => 'c', 'hash' => md5 of the definition], or false for another
     * key version or secret, or a spec this version cannot read.
     */
    private function parseSpec($spec)
    {
        $p = explode('|', (string) $spec);
        if (count($p) < 3 || $p[0] !== (string) self::KEY_VERSION || $p[1] !== $this->keyPrint) return false;
        if (count($p) === 3 && in_array($p[2], ['text', 'point', 'comma'], true)) {
            return ['kind' => 'plain', 'mark' => $p[2] === 'text' ? null : $p[2]];
        }
        if (count($p) === 4 && $p[2] === 'c' && preg_match('/^[0-9a-f]{32}\z/', $p[3])) return ['kind' => 'c', 'hash' => $p[3]];
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

    /**
     * The number mark (null for text) of a plain field's spec of an older key
     * version ("1|text") or another secret ("2|print|text"), false for any
     * other spec.
     */
    private static function oldPlainMark($spec)
    {
        $p = explode('|', (string) $spec);
        $n = count($p);
        if (!(($n === 2 && $p[0] === '1') || ($n === 3 && ctype_digit($p[0]) && $p[0] !== '1'))) return false;
        $m = $p[$n - 1];
        if ($m === 'text') return null;
        return ($m === 'point' || $m === 'comma') ? $m : false;
    }

    private static function isComposite($name)
    {
        return isset($name[0]) && $name[0] === '#';
    }

    /** $value's lookup key in a field of number mark $mark, letter case folded. */
    private static function plainMaterial($value, $mark)
    {
        return Logic::lookupKey((string) $value, true, ($mark === 'point' || $mark === 'comma') ? $mark : null);
    }

    /**
     * The hex key of $material in entry $name: the first 16 bytes of a keyed
     * hash whose message holds the project and the entry, so a key means
     * nothing without the secret, in another project or in another field.
     */
    private function keyHex($pid, $name, $material)
    {
        return substr(Hmac::hex(Hmac::P_LOOKUP, (int) $pid, (string) $name . "\0" . (string) $material, $this->key), 0, 32);
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
