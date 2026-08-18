<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * The one question ScanWorker asks the duplicate finalizer.
 *
 * The same seam as RecordVersions, for the same reason: the worker's phase
 * behaviour - including its refusal to walk past a finalizer that was never
 * configured - has to be exercisable without a database, while the finalizer
 * itself is almost entirely SQL and is tested against real servers.
 * UniqueFinalizer is the only production implementation.
 */
interface DuplicateFinalizer
{
    /**
     * One bounded unit of work.
     *
     * @return array{done:bool, groups:int, verified:int, emitted:int, published:int,
     *               collisions:int, why:?string}
     */
    public function step($generationId, $limit = 500);
}

/**
 * Deciding which values are duplicates, on a project too large to hold.
 *
 * WHY UNIQUENESS IS THE HARD ONE. Every other check this module makes is a
 * property of ONE record: a pattern either matches or it does not, and the
 * verdict is reached and written before the next record is read. Uniqueness is a
 * property of the whole project — no record can be a duplicate on its own
 * evidence — so it is the only thing here that cannot be finished while scanning.
 * It gets its own phase, its own table, and this file.
 *
 * THE GROUP IS A KEYED HASH, NEVER THE VALUE. A `@UVUNIQUE` rule can sit on a
 * Notes field, and a Notes field is up to 64 KB; storing one per candidate would
 * be a second copy of the project, and storing it in a project-readable table
 * would be a copy nobody asked for. So a candidate carries a project-scoped
 * HMAC of its key and nothing else, and the values are re-read from the source
 * for the handful of groups that actually turn out to have more than one record
 * in them.
 *
 * AND THE HASH IS VERIFIED RATHER THAN TRUSTED. Two different values sharing a
 * SHA-256 HMAC is not something data entry can produce, and the verification is
 * still done, because the alternative to checking is asserting - and the
 * assertion would be "these two participants have the same hospital number",
 * about people. When a group's tuples disagree the group is marked a blocking
 * degradation and NO uniqueness verdict is emitted for it: the module says it
 * could not decide, rather than picking one of two possible answers. Partitioning
 * the group by value would be the tempting alternative and would quietly turn a
 * hash failure into a confident wrong report.
 *
 * BOUNDED, INCLUDING THE PATHOLOGICAL CASE. A rule on a field where every record
 * holds the same value puts every record in ONE group. Nothing here accumulates
 * a group: verification and emission both walk candidates by keyset page and
 * persist their cursor, so the memory a million-candidate group costs is the
 * memory of one page.
 *
 * STAGED, THEN PUBLISHED. Duplicate findings are written with no active slot, so
 * no report can see them; the group's `published_epoch` is what makes them
 * visible, and it is one row. If the candidates change underneath a finalization
 * - a record edited during the phase - the group's `candidate_epoch` moves, the
 * staged rows for the old epoch become obsolete, and the group starts again.
 * Half a group is never published, because half a duplicate group is a report
 * that names one of two matching records and not the other.
 *
 * PHP 7.4.
 */
final class UniqueFinalizer implements DuplicateFinalizer
{
    /** Group phases. Ordered: a group moves forward through these and never back. */
    const G_NEW       = 'new';
    const G_VERIFYING = 'verifying';
    const G_EMITTING  = 'emitting';
    const G_PUBLISHED = 'published';
    /** Terminal and blocking: the group could not be decided. */
    const G_COLLISION = 'collision';
    /** A group with one record in it. Not a duplicate, and not work. */
    const G_SINGLETON = 'singleton';

    const COLLISION_NONE     = 0;
    const COLLISION_BLOCKING = 1;

    /** The most of one value the representative tuple keeps. */
    const REP_BYTES = 255;

    /** @var ScanDb */
    private $db;
    /** @var array */
    private $deps;

    /**
     * @param array $deps {
     *   pid:      int|string
     *   read:     callable(array $locations): array{ok:bool, values:array, why:?string}
     *             keyed by "recordId|eventId|instance|field"
     *   versions: ?RecordVersions
     *   page:     int  candidates per bounded page
     * }
     */
    public function __construct(ScanDb $db, array $deps = [])
    {
        $this->db = $db;
        $this->deps = $deps;
    }

    /**
     * One bounded unit of finalization.
     *
     * Returns what it did rather than looping to completion, so the caller's
     * budget decides how much of a request this phase may take - the same
     * arrangement the scanning phase has, for the same reason.
     *
     * @return array{done:bool, groups:int, verified:int, emitted:int, published:int,
     *               collisions:int, why:?string}
     */
    public function step($generationId, $limit = 500)
    {
        $limit = max(1, min(5000, (int) $limit));
        $out = ['done' => false, 'groups' => 0, 'verified' => 0, 'emitted' => 0,
                'published' => 0, 'collisions' => 0, 'why' => null];

        // Discovery first: a group that has not been discovered cannot be
        // worked, and discovery is cheap and resumable from what it has already
        // written.
        $found = $this->discover($generationId, $limit);
        $out['groups'] += $found;
        if ($found > 0) return $out;

        $g = $this->nextUnfinished($generationId);
        if ($g === null) {
            $out['done'] = true;
            return $out;
        }

        if ($g['phase'] === self::G_NEW || $g['phase'] === self::G_VERIFYING) {
            $r = $this->verify($generationId, $g, $limit);
            $out['verified'] += $r['checked'];
            $out['collisions'] += $r['collision'] ? 1 : 0;
            $out['why'] = $r['why'];
            return $out;
        }
        if ($g['phase'] === self::G_EMITTING) {
            $r = $this->emit($generationId, $g, $limit);
            $out['emitted'] += $r['emitted'];
            $out['published'] += $r['published'] ? 1 : 0;
            $out['why'] = $r['why'];
            return $out;
        }
        // A phase this build does not recognise. Refusing to guess is the same
        // decision the phase machine makes about a run.
        $out['why'] = 'a duplicate group is in a state this version does not recognise';
        return $out;
    }

    /**
     * Create group rows for candidate groups not yet known, in keyset order.
     *
     * THE CURSOR IS THE DATA. The highest group hash already discovered is the
     * cursor, so discovery resumes exactly where it stopped with no extra column
     * and no state to lose. Groups are discovered in ascending hash order and
     * candidates never move between groups, so nothing can be skipped by
     * arriving late.
     *
     * @return int groups created
     */
    public function discover($generationId, $limit)
    {
        $c = $this->db->select('SELECT MAX(group_hmac) FROM ' . Schema::table('unique_group')
            . ' WHERE generation_id = ?', [$generationId]);
        $after = (isset($c[0][0]) && $c[0][0] !== null) ? $c[0][0] : null;

        $sql = 'SELECT group_hmac, COUNT(DISTINCT record_hash), MIN(candidate_id)
                FROM ' . Schema::table('unique_candidate') . ' WHERE generation_id = ?';
        $params = [$generationId];
        if ($after !== null) {
            $sql .= ' AND group_hmac > ?';
            $params[] = $after;
        }
        $sql .= ' GROUP BY group_hmac ORDER BY group_hmac LIMIT ' . max(1, (int) $limit);

        $rows = $this->db->select($sql, $params);
        $made = 0;
        foreach ($rows as $r) {
            $records = (int) $r[1];
            // A group with one record in it is not a duplicate and never
            // becomes one. It is still WRITTEN, so discovery has a cursor past
            // it and so "we looked and there was nothing" is a stored fact
            // rather than an absence.
            $phase = ($records > 1) ? self::G_NEW : self::G_SINGLETON;
            $this->db->exec('INSERT INTO ' . Schema::table('unique_group') . '
                (generation_id, group_hmac, candidate_epoch, verify_cursor, emit_cursor,
                 phase, distinct_records, collision_state)
                VALUES (?,?,?,0,0,?,?,0)
                ON DUPLICATE KEY UPDATE distinct_records = VALUES(distinct_records)',
                [$generationId, $r[0], 1, $phase, $records]);
            $made++;
        }
        return $made;
    }

    /** The next group with work left, or null when every group is settled. */
    private function nextUnfinished($generationId)
    {
        $r = $this->db->select('SELECT group_id, group_hmac, candidate_epoch, verify_cursor,
            emit_cursor, phase, representative, distinct_records
            FROM ' . Schema::table('unique_group') . '
            WHERE generation_id = ? AND phase IN (?,?,?)
            ORDER BY group_hmac LIMIT 1',
            [$generationId, self::G_NEW, self::G_VERIFYING, self::G_EMITTING]);
        if (!isset($r[0])) return null;
        return ['group_id' => (int) $r[0][0], 'group_hmac' => $r[0][1],
                'candidate_epoch' => (int) $r[0][2], 'verify_cursor' => (int) $r[0][3],
                'emit_cursor' => (int) $r[0][4], 'phase' => $r[0][5],
                'representative' => $r[0][6], 'distinct_records' => (int) $r[0][7]];
    }

    /**
     * Check one bounded page of a group's candidates against its representative.
     *
     * The representative is the FIRST candidate's value tuple, captured on the
     * first page and stored on the group row so a resumed verification compares
     * against the same thing the earlier pages did. Comparison is byte-for-byte
     * in PHP, never in SQL: MySQL's TRIM strips only spaces where PHP's strips
     * six characters, and a PAD SPACE collation calls two different values equal
     * - so a comparison delegated to the server would merge tuples this module
     * considers distinct, and it would do it silently.
     *
     * @return array{checked:int, collision:bool, why:?string}
     */
    public function verify($generationId, array $g, $limit)
    {
        $rows = $this->db->select('SELECT candidate_id, record_id_bin, event_id, instance, field,
            version_scanned FROM ' . Schema::table('unique_candidate') . '
            WHERE generation_id = ? AND group_hmac = ? AND candidate_id > ?
            ORDER BY candidate_id LIMIT ' . max(1, (int) $limit),
            [$generationId, $g['group_hmac'], $g['verify_cursor']]);

        if (!$rows) {
            // Every candidate matched the representative. The group is real and
            // may now be emitted.
            $this->db->exec('UPDATE ' . Schema::table('unique_group') . '
                SET phase = ?, emit_cursor = 0 WHERE group_id = ? AND candidate_epoch = ?',
                [self::G_EMITTING, $g['group_id'], $g['candidate_epoch']]);
            return ['checked' => 0, 'collision' => false, 'why' => null];
        }

        $locs = [];
        foreach ($rows as $r) {
            $locs[] = ['record' => $r[1], 'event_id' => $r[2], 'instance' => (int) $r[3],
                       'field' => $r[4], 'candidate_id' => (int) $r[0],
                       'version' => $r[5]];
        }

        $read = isset($this->deps['read']) ? $this->deps['read'] : null;
        if (!is_callable($read)) {
            // Without a way to re-read the values there is no verification, and
            // an unverified duplicate verdict about people is not one this
            // module will emit.
            $this->block($g, 'the values behind this duplicate group could not be re-read, so it '
                . 'was not decided');
            return ['checked' => 0, 'collision' => true, 'why' => 'no reader was configured'];
        }
        $got = $read($locs);
        if (empty($got['ok'])) {
            $this->block($g, 'the values behind this duplicate group could not be re-read, so it '
                . 'was not decided');
            return ['checked' => 0, 'collision' => true,
                    'why' => isset($got['why']) ? $got['why'] : 'the values could not be re-read'];
        }
        $values = isset($got['values']) && is_array($got['values']) ? $got['values'] : [];

        // THE CANDIDATES MOVED. A record edited during finalization invalidates
        // the group: its value may no longer belong to this group at all, so the
        // group starts again at a new epoch rather than being finished against a
        // reading half of which is stale.
        $fence = isset($this->deps['versions']) ? $this->deps['versions'] : null;
        if ($fence instanceof RecordVersions) {
            $ids = [];
            foreach ($locs as $l) $ids[] = $l['record'];
            $now = $fence->versions($ids);
            foreach ($locs as $l) {
                $was = $l['version'];
                $is  = isset($now[$l['record']]) ? $now[$l['record']] : null;
                if ($was !== $is) {
                    $this->restart($g);
                    return ['checked' => 0, 'collision' => false,
                            'why' => 'a record in this duplicate group changed while it was being '
                                   . 'checked, so the group is being checked again'];
                }
            }
        }

        $rep = $g['representative'];
        $checked = 0;
        $cursor = $g['verify_cursor'];
        foreach ($locs as $l) {
            $key = self::locKey($l);
            if (!array_key_exists($key, $values)) {
                // A candidate whose value cannot be found is not evidence of a
                // duplicate. Blocking rather than dropping: dropping it would
                // shrink the group and could turn a real duplicate into a
                // singleton with nothing said.
                $this->block($g, 'part of this duplicate group could not be re-read, so it was '
                    . 'not decided');
                return ['checked' => $checked, 'collision' => true, 'why' => null];
            }
            $tuple = self::canonicalTuple($values[$key]);
            if ($rep === null) {
                $rep = $tuple;
                $this->db->exec('UPDATE ' . Schema::table('unique_group')
                    . ' SET representative = ? WHERE group_id = ? AND candidate_epoch = ?',
                    [$rep, $g['group_id'], $g['candidate_epoch']]);
            } elseif (!hash_equals($rep, $tuple)) {
                // Two different values under one keyed hash. Not partitioned,
                // not guessed: reported as undecidable, which caps the run's
                // coverage and says so.
                $this->block($g, 'two different values in this project share a hash, so this '
                    . 'group\'s duplicates could not be decided');
                return ['checked' => $checked, 'collision' => true, 'why' => null];
            }
            $cursor = $l['candidate_id'];
            $checked++;
        }

        $this->db->exec('UPDATE ' . Schema::table('unique_group') . '
            SET phase = ?, verify_cursor = ? WHERE group_id = ? AND candidate_epoch = ?',
            [self::G_VERIFYING, $cursor, $g['group_id'], $g['candidate_epoch']]);
        return ['checked' => $checked, 'collision' => false, 'why' => null];
    }

    /**
     * Write one bounded page of a verified group's duplicate findings, staged.
     *
     * Staged means active_slot NULL: written, durable, and invisible to every
     * report. When the last page is written the group's published_epoch is set,
     * and that ONE row is what makes the whole group appear at once. Half a
     * duplicate group is a report that names one of two matching records and not
     * the other, which is worse than naming neither.
     *
     * @return array{emitted:int, published:bool, why:?string}
     */
    public function emit($generationId, array $g, $limit)
    {
        $rows = $this->db->select('SELECT candidate_id, record_hash, record_id_bin, event_id,
            instance, host_form, field, rule_source_id, rule_revision
            FROM ' . Schema::table('unique_candidate') . '
            WHERE generation_id = ? AND group_hmac = ? AND candidate_id > ?
            ORDER BY candidate_id LIMIT ' . max(1, (int) $limit),
            [$generationId, $g['group_hmac'], $g['emit_cursor']]);

        if (!$rows) {
            return ['emitted' => 0, 'published' => $this->publish($generationId, $g), 'why' => null];
        }

        $key = isset($this->deps['hmacKey']) ? $this->deps['hmacKey'] : null;
        $pid = isset($this->deps['pid']) ? $this->deps['pid'] : 0;
        $cursor = $g['emit_cursor'];
        $flat = [];
        $marks = [];
        foreach ($rows as $r) {
            $loc = ['record' => $r[2], 'event_id' => $r[3], 'instance' => (int) $r[4],
                    'host_form' => $r[5], 'field' => $r[6], 'rule_source_id' => $r[7],
                    'reason_code' => 'duplicate'];
            $marks[] = '(?,?,?,NULL,?,?,?,?,?,?,?,?,0,?,?,0,0,?,?)';
            foreach ([$generationId, Hmac::findingIdentity($pid, $loc, $key),
                      $g['candidate_epoch'], $r[1], $r[2], $r[3], (int) $r[4], $r[5], $r[6],
                      $r[7], $r[8], 'unique', 'duplicate', $g['group_hmac'],
                      $g['candidate_epoch']] as $v) {
                $flat[] = $v;
            }
            $cursor = (int) $r[0];
        }
        // ONE STATEMENT PER PAGE, not one per finding. A group holding every
        // record in the project would otherwise be one round trip per record,
        // which is the shape of cost this rebuild exists to remove - and it is
        // measurable: batching took a 20,000-candidate group from minutes to
        // seconds on the database matrix.
        //
        // Idempotent on (generation, identity, stage epoch). That key exists
        // because the ACTIVE-identity key cannot do this job: a staged row has
        // no active slot, and every NULL in a unique index counts as distinct,
        // so a retried page would insert a second copy of every row it had
        // already written.
        $this->db->exec('INSERT INTO ' . Schema::table('finding') . '
            (generation_id, finding_identity, valid_from_seq, active_slot, record_hash,
             record_id_bin, event_id, instance, host_form, field, rule_source_id,
             rule_revision, rule_ord, check_type, reason_code, reason_bits, severity,
             group_hmac, stage_epoch)
            VALUES ' . implode(',', $marks) . '
            ON DUPLICATE KEY UPDATE stage_epoch = VALUES(stage_epoch)', $flat);
        $emitted = count($marks);
        $this->db->exec('UPDATE ' . Schema::table('unique_group')
            . ' SET emit_cursor = ? WHERE group_id = ? AND candidate_epoch = ?',
            [$cursor, $g['group_id'], $g['candidate_epoch']]);
        return ['emitted' => $emitted, 'published' => false, 'why' => null];
    }

    /**
     * Make a fully staged group visible.
     *
     * Two steps and an order that matters. Rows from an EARLIER staging epoch
     * are closed first, because the active-identity unique key permits only one
     * live row per finding and activating the new one over a surviving old one
     * would fail. Then the group's pointer is written, fenced on the epoch that
     * produced these rows: if the candidates moved while we were emitting, that
     * fence fails and nothing becomes visible.
     */
    private function publish($generationId, array $g)
    {
        $t = Schema::table('finding');
        $this->db->exec('UPDATE ' . $t . ' SET active_slot = NULL, valid_to_seq = ?
            WHERE generation_id = ? AND group_hmac = ? AND active_slot = 1 AND stage_epoch <> ?',
            [$g['candidate_epoch'], $generationId, $g['group_hmac'], $g['candidate_epoch']]);
        $this->db->exec('UPDATE ' . $t . ' SET active_slot = 1
            WHERE generation_id = ? AND group_hmac = ? AND stage_epoch = ? AND active_slot IS NULL',
            [$generationId, $g['group_hmac'], $g['candidate_epoch']]);
        $this->db->exec('UPDATE ' . Schema::table('unique_group') . '
            SET phase = ?, published_epoch = ?, staged_epoch = ?
            WHERE group_id = ? AND candidate_epoch = ?',
            [self::G_PUBLISHED, $g['candidate_epoch'], $g['candidate_epoch'],
             $g['group_id'], $g['candidate_epoch']]);
        return true;
    }

    /** The group could not be decided. Terminal, and it blocks the run's coverage. */
    private function block(array $g, $why)
    {
        $this->db->exec('UPDATE ' . Schema::table('unique_group') . '
            SET phase = ?, collision_state = ? WHERE group_id = ?',
            [self::G_COLLISION, self::COLLISION_BLOCKING, $g['group_id']]);
        return $why;
    }

    /**
     * Start this group again at a new candidate epoch.
     *
     * The staged rows from the old epoch are left where they are and become
     * unreachable: publication only ever activates rows whose stage epoch is the
     * group's current one. Deleting them here would put an unbounded delete
     * inside a bounded step; sweep() removes them in pages instead.
     */
    private function restart(array $g)
    {
        $this->db->exec('UPDATE ' . Schema::table('unique_group') . '
            SET candidate_epoch = candidate_epoch + 1, verify_cursor = 0, emit_cursor = 0,
                representative = NULL, phase = ?
            WHERE group_id = ? AND candidate_epoch = ?',
            [self::G_NEW, $g['group_id'], $g['candidate_epoch']]);
    }

    /**
     * Remove staged rows no publication can ever reach, in bounded pages.
     *
     * Separate from the work above and safe to run at any time: a row whose
     * stage epoch is not its group's current epoch belongs to an abandoned
     * attempt, and nothing reads it.
     *
     * @return int rows removed
     */
    public function sweep($generationId, $limit = 1000)
    {
        $rows = $this->db->select('SELECT f.finding_id FROM ' . Schema::table('finding') . ' f
            JOIN ' . Schema::table('unique_group') . ' g
              ON g.generation_id = f.generation_id AND g.group_hmac = f.group_hmac
            WHERE f.generation_id = ? AND f.stage_epoch IS NOT NULL
              AND f.stage_epoch <> g.candidate_epoch
            LIMIT ' . max(1, (int) $limit), [$generationId]);
        if (!$rows) return 0;
        $ids = [];
        foreach ($rows as $r) $ids[] = (int) $r[0];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $this->db->exec('DELETE FROM ' . Schema::table('finding')
            . ' WHERE finding_id IN (' . $marks . ')', $ids);
        return count($ids);
    }

    /**
     * Is this generation's uniqueness settled, and did anything block?
     *
     * The promotion predicate asks this. "Settled" means every discovered group
     * reached a terminal phase - published, singleton, or undecidable - and
     * `blocking` is what stops a run with an undecidable group claiming it
     * covered the project.
     *
     * @return array{done:bool, groups:int, published:int, blocking:int, pending:int}
     */
    public function status($generationId)
    {
        $r = $this->db->select('SELECT phase, COUNT(*) FROM ' . Schema::table('unique_group')
            . ' WHERE generation_id = ? GROUP BY phase', [$generationId]);
        $by = [];
        foreach ($r as $row) $by[(string) $row[0]] = (int) $row[1];
        $get = function ($k) use ($by) { return isset($by[$k]) ? $by[$k] : 0; };
        $pending = $get(self::G_NEW) + $get(self::G_VERIFYING) + $get(self::G_EMITTING);
        return ['done' => $pending === 0, 'groups' => array_sum($by),
                'published' => $get(self::G_PUBLISHED), 'blocking' => $get(self::G_COLLISION),
                'pending' => $pending];
    }

    // -- helpers -------------------------------------------------------------

    /** How a re-read value is addressed. Bytes, joined by a byte no id may hold. */
    public static function locKey(array $l)
    {
        return (string) $l['record'] . "\0" . (string) $l['event_id'] . "\0"
             . (string) $l['instance'] . "\0" . (string) $l['field'];
    }

    /**
     * The comparable form of one candidate's value tuple.
     *
     * Trimmed with PHP's trim(), matching the live uniqueness check exactly -
     * the scan and the live endpoint must agree about what "the same value"
     * means, or a record blocked at save time would not appear in the report
     * that is supposed to explain it. Hashed rather than stored so the
     * representative on the group row is bounded whatever the field holds.
     */
    public static function canonicalTuple($parts)
    {
        if (!is_array($parts)) $parts = [$parts];
        $flat = '';
        foreach ($parts as $p) {
            $p = is_scalar($p) ? trim((string) $p) : '';
            // Length-prefixed: without it ['ab','c'] and ['a','bc'] compare
            // equal, and a composite unique key is exactly a list of values
            // somebody chose the boundaries of.
            $flat .= strlen($p) . ':' . $p . "\0";
        }
        return hash('sha256', $flat, true);
    }
}
