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

    /**
     * Groups written per INSERT by discover().
     *
     * Five placeholders per row, so 500 rows is 2,500 against MySQL's 65,535
     * limit - two orders of magnitude of headroom, and a chunk small enough to
     * be a short transaction rather than a lock held over a whole page. The
     * number is here rather than inline because it is a property of the
     * statement's shape, and the next person to add a placeholder to that
     * statement needs to see it.
     */
    const DISCOVER_CHUNK = 500;

    /** @var ScanDb */
    private $db;
    /**
     * The project every statement in this file is scoped to. Not nullable, not
     * defaulted, and read once - see the constructor.
     *
     * @var int
     */
    private $pid;
    /** @var array */
    private $deps;

    /**
     * @param array $deps {
     *   pid:      int|string  REQUIRED; a project id of 1 or more
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
        // THE PROJECT IS A CONSTRUCTOR FACT, NOT AN OPTIONAL DEP. emit() used
        // to read it as `isset($deps['pid']) ? $deps['pid'] : 0` at the one
        // place it happened to need one, and a silent 0 is exactly the shape of
        // the constant generation this release exists to undo: every query in
        // this file filtered by generation alone, the generation was the
        // literal 1 for every run of every project on the installation, and so
        // one project's groups decided another project's run. Reproduced
        // against a real server: status() answered "not done, 1 blocking" over
        // groups belonging to a different project, so the run never reached a
        // terminal state and never released its project's slot.
        //
        // Refusing an absent project is the same stance Hmac::raw() takes about
        // an absent key - never an unkeyed fallback - and for the same reason.
        // A wrong project here is not distinguishable from a right one by
        // anyone reading the report.
        $pid = isset($deps['pid']) ? $deps['pid'] : null;
        if ($pid === null || !is_numeric($pid) || (int) $pid < 1) {
            throw new \InvalidArgumentException('the duplicate finalizer was constructed with no '
                . 'project; every query it makes would then span the whole installation');
        }
        $this->pid = (int) $pid;
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
     * AND THE CURSOR IS THE PROJECT'S OWN. This is the subtle half of the
     * cross-project bug and the one that could publish a wrong ANSWER rather
     * than merely stall a run. The MAX below used to be taken over the whole
     * generation, and the generation was the literal 1 everywhere, so as soon as
     * ANY project on the installation had discovered a high hash, every
     * candidate group of ours sorting below it was skipped: no group row, no
     * verification, no duplicate finding. status() then found nothing pending
     * and answered done: a missed duplicate presented as a clean result.
     *
     * @return int groups created
     */
    public function discover($generationId, $limit)
    {
        // Scoping this MAX to the project is necessary and sufficient: the
        // cursor is a fact about how far WE have discovered. The first-run
        // branch is scoped by the same predicate - no group of ours yet leaves
        // $after null and starts us at the bottom of our own candidates, no
        // matter how far another project has already walked.
        $c = $this->db->select('SELECT MAX(group_hmac) FROM ' . Schema::table('unique_group')
            . ' WHERE project_id = ? AND generation_id = ?', [$this->pid, $generationId]);
        $after = (isset($c[0][0]) && $c[0][0] !== null) ? $c[0][0] : null;

        $sql = 'SELECT group_hmac, COUNT(DISTINCT record_hash), MIN(candidate_id)
                FROM ' . Schema::table('unique_candidate')
                . ' WHERE project_id = ? AND generation_id = ?';
        $params = [$this->pid, $generationId];
        if ($after !== null) {
            $sql .= ' AND group_hmac > ?';
            $params[] = $after;
        }
        $sql .= ' GROUP BY group_hmac ORDER BY group_hmac LIMIT ' . max(1, (int) $limit);

        $rows = $this->db->select($sql, $params);
        // ONE STATEMENT PER PAGE, NOT ONE PER GROUP. This was a loop issuing an
        // INSERT per discovered group, and the cost is not theoretical: measured
        // against MySQL 8.0.46, 100,000 groups took 811 seconds through this
        // method - the review that found it estimated 262. Through the External
        // Modules wrapper, which runs a second statement per write to read
        // ROW_COUNT(), it is worse again. Batched, the same work is 200
        // statements.
        //
        // FIVE PLACEHOLDERS PER ROW, and the chunk is sized from that. MySQL's
        // limit is 65,535 placeholders per statement, so 500 rows is 2,500 -
        // comfortably inside it, and small enough that a chunk is a short
        // transaction rather than a lock held over a hundred thousand rows.
        // candidate_epoch, verify_cursor, emit_cursor and collision_state are
        // written as literals because they are constants for a new group; every
        // one of them turned into a placeholder would cut the chunk size for no
        // gain.
        $made = 0;
        foreach (array_chunk($rows, self::DISCOVER_CHUNK) as $chunk) {
            $marks = [];
            $flat = [];
            foreach ($chunk as $r) {
                $records = (int) $r[1];
                // A group with one record in it is not a duplicate and never
                // becomes one. It is still WRITTEN, so discovery has a cursor
                // past it and so "we looked and there was nothing" is a stored
                // fact rather than an absence.
                $phase = ($records > 1) ? self::G_NEW : self::G_SINGLETON;
                $marks[] = '(?,?,?,1,0,0,?,?,0)';
                $flat[] = $this->pid;
                $flat[] = $generationId;
                $flat[] = $r[0];
                $flat[] = $phase;
                $flat[] = $records;
            }
            $this->db->exec('INSERT INTO ' . Schema::table('unique_group') . '
                (project_id, generation_id, group_hmac, candidate_epoch, verify_cursor,
                 emit_cursor, phase, distinct_records, collision_state)
                VALUES ' . implode(',', $marks) . '
                ON DUPLICATE KEY UPDATE distinct_records = VALUES(distinct_records)', $flat);
            $made += count($chunk);
        }
        return $made;
    }

    /**
     * The next group with work left, or null when every group is settled.
     *
     * PROJECT FIRST, THEN GENERATION, THEN PHASE, THEN HASH - and that order is
     * not style. It is the column order of ix_pending (project_id,
     * generation_id, phase, group_hmac), which exists BECAUSE of this query:
     * without it the optimiser used the group key, which does not carry phase,
     * and the walk got slower as groups settled - measured 2.88 ms with none
     * settled against 283.82 ms with all of them settled, and a flat 1.49 ms
     * once the key was there. The equality columns have to lead for the range
     * on phase to be usable, and the trailing group_hmac is what makes the
     * ORDER BY free.
     *
     * Scoping it to the project is also what stops one project's worker being
     * handed another project's group and then re-reading it through a read
     * closure bound to the wrong project.
     */
    private function nextUnfinished($generationId)
    {
        // FORCE INDEX, AND IT IS NOT A MICRO-OPTIMISATION. Two indexes can serve
        // this: uq_group_v2 (project_id, generation_id, group_hmac) supplies the
        // ORDER BY for free and then has to read every group to test phase;
        // ix_pending (project_id, generation_id, phase, group_hmac) seeks
        // straight to the pending rows and stops.
        //
        // The difference only shows in the state this method spends most of its
        // life in - every group settled, nothing pending - because that is when
        // uq_group_v2's "stop at the first match" never happens and it walks the
        // whole generation instead. Measured on 1,200 groups with the four-engine
        // matrix:
        //
        //   MySQL 5.7 / 8.0     ix_pending,   3 rows      either way
        //   MariaDB 10.5/10.11  uq_group_v2,  1,200 rows  without fresh statistics
        //                       ix_pending,   3 rows      after ANALYZE TABLE
        //
        // So on MariaDB the plan depends on how fresh the table statistics are,
        // and the moment this runs is the moment they are stalest: discover()
        // bulk-inserts every group and step() walks them immediately, so the
        // optimiser is reading statistics that describe the table before the
        // insert. The good plan was not chosen by luck on MySQL and the bad one
        // was not bad luck on MariaDB; neither is a plan to leave to chance.
        //
        // Safe to force: both indexes are created by the same version-2
        // migration, and project_id is a version-2 column too - so a schema on
        // which ix_pending is missing is a schema on which this statement's
        // WHERE clause could not compile either.
        $r = $this->db->select('SELECT group_id, group_hmac, candidate_epoch, verify_cursor,
            emit_cursor, phase, representative, distinct_records
            FROM ' . Schema::table('unique_group') . ' FORCE INDEX (ix_pending)
            WHERE project_id = ? AND generation_id = ? AND phase IN (?,?,?)
            ORDER BY group_hmac LIMIT 1',
            [$this->pid, $generationId, self::G_NEW, self::G_VERIFYING, self::G_EMITTING]);
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
            WHERE project_id = ? AND generation_id = ? AND group_hmac = ? AND candidate_id > ?
            ORDER BY candidate_id LIMIT ' . max(1, (int) $limit),
            [$this->pid, $generationId, $g['group_hmac'], $g['verify_cursor']]);

        if (!$rows) {
            // Every candidate matched the representative. The group is real and
            // may now be emitted.
            $this->db->exec('UPDATE ' . Schema::table('unique_group') . '
                SET phase = ?, emit_cursor = 0
                WHERE project_id = ? AND group_id = ? AND candidate_epoch = ?',
                [self::G_EMITTING, $this->pid, $g['group_id'], $g['candidate_epoch']]);
            return ['checked' => 0, 'collision' => false, 'why' => null];
        }

        $locs = [];
        foreach ($rows as $r) {
            $locs[] = ['record' => $r[1], 'event_id' => self::eventOrNull($r[2]),
                       'instance' => (int) $r[3], 'field' => $r[4],
                       'candidate_id' => (int) $r[0], 'version' => $r[5]];
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
                    . ' SET representative = ?
                       WHERE project_id = ? AND group_id = ? AND candidate_epoch = ?',
                    [$rep, $this->pid, $g['group_id'], $g['candidate_epoch']]);
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
            SET phase = ?, verify_cursor = ?
            WHERE project_id = ? AND group_id = ? AND candidate_epoch = ?',
            [self::G_VERIFYING, $cursor, $this->pid, $g['group_id'], $g['candidate_epoch']]);
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
            WHERE project_id = ? AND generation_id = ? AND group_hmac = ? AND candidate_id > ?
            ORDER BY candidate_id LIMIT ' . max(1, (int) $limit),
            [$this->pid, $generationId, $g['group_hmac'], $g['emit_cursor']]);

        if (!$rows) {
            return ['emitted' => 0, 'published' => $this->publish($generationId, $g), 'why' => null];
        }

        $key = isset($this->deps['hmacKey']) ? $this->deps['hmacKey'] : null;
        $cursor = $g['emit_cursor'];
        $flat = [];
        $marks = [];
        foreach ($rows as $r) {
            // The candidate's 0 sentinel is undone before the event reaches
            // either the identity or the finding row. uv_finding.event_id is
            // still nullable and the ordinary scan path writes null there on a
            // classic project, so a 0 here would put duplicate findings in a
            // different identity space from every other finding about the same
            // location - and superseding matches on identity.
            $event = self::eventOrNull($r[3]);
            $loc = ['record' => $r[2], 'event_id' => $event, 'instance' => (int) $r[4],
                    'host_form' => $r[5], 'field' => $r[6], 'rule_source_id' => $r[7],
                    'reason_code' => 'duplicate'];
            $marks[] = '(?,?,?,?,NULL,?,?,?,?,?,?,?,?,0,?,?,0,0,?,?)';
            foreach ([$this->pid, $generationId, Hmac::findingIdentity($this->pid, $loc, $key),
                      $g['candidate_epoch'], $r[1], $r[2], $event, (int) $r[4], $r[5], $r[6],
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
            (project_id, generation_id, finding_identity, valid_from_seq, active_slot,
             record_hash, record_id_bin, event_id, instance, host_form, field, rule_source_id,
             rule_revision, rule_ord, check_type, reason_code, reason_bits, severity,
             group_hmac, stage_epoch)
            VALUES ' . implode(',', $marks) . '
            ON DUPLICATE KEY UPDATE stage_epoch = VALUES(stage_epoch)', $flat);
        $emitted = count($marks);
        $this->db->exec('UPDATE ' . Schema::table('unique_group')
            . ' SET emit_cursor = ?
               WHERE project_id = ? AND group_id = ? AND candidate_epoch = ?',
            [$cursor, $this->pid, $g['group_id'], $g['candidate_epoch']]);
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
        // The group hash is a project-scoped HMAC, so two projects cannot share
        // one - but these statements are still scoped, because "cannot collide"
        // is a property of the key material and not of the query, and the
        // project_id leading ix_group_stage_v2 is what makes them read one
        // project's rows instead of the generation's.
        $t = Schema::table('finding');
        $this->db->exec('UPDATE ' . $t . ' SET active_slot = NULL, valid_to_seq = ?
            WHERE project_id = ? AND generation_id = ? AND group_hmac = ? AND active_slot = 1
              AND stage_epoch <> ?',
            [$g['candidate_epoch'], $this->pid, $generationId, $g['group_hmac'],
             $g['candidate_epoch']]);
        $this->db->exec('UPDATE ' . $t . ' SET active_slot = 1
            WHERE project_id = ? AND generation_id = ? AND group_hmac = ? AND stage_epoch = ?
              AND active_slot IS NULL',
            [$this->pid, $generationId, $g['group_hmac'], $g['candidate_epoch']]);
        $this->db->exec('UPDATE ' . Schema::table('unique_group') . '
            SET phase = ?, published_epoch = ?, staged_epoch = ?
            WHERE project_id = ? AND group_id = ? AND candidate_epoch = ?',
            [self::G_PUBLISHED, $g['candidate_epoch'], $g['candidate_epoch'],
             $this->pid, $g['group_id'], $g['candidate_epoch']]);
        return true;
    }

    /** The group could not be decided. Terminal, and it blocks the run's coverage. */
    private function block(array $g, $why)
    {
        $this->db->exec('UPDATE ' . Schema::table('unique_group') . '
            SET phase = ?, collision_state = ? WHERE project_id = ? AND group_id = ?',
            [self::G_COLLISION, self::COLLISION_BLOCKING, $this->pid, $g['group_id']]);
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
            WHERE project_id = ? AND group_id = ? AND candidate_epoch = ?',
            [self::G_NEW, $this->pid, $g['group_id'], $g['candidate_epoch']]);
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
        // BOTH SIDES OF THE JOIN ARE SCOPED. Matching the two tables on
        // generation and hash alone was enough to pair a finding with a group
        // row belonging to another project - the generation was the same
        // literal for all of them - and a stage epoch compared against the
        // wrong group's epoch decides that a live staged row is abandoned. The
        // join condition carries the project across, and each side is pinned to
        // ours as well: a join that scopes only one side is a join that can
        // still cross projects.
        $rows = $this->db->select('SELECT f.finding_id FROM ' . Schema::table('finding') . ' f
            JOIN ' . Schema::table('unique_group') . ' g
              ON g.project_id = f.project_id AND g.generation_id = f.generation_id
             AND g.group_hmac = f.group_hmac
            WHERE f.project_id = ? AND g.project_id = ? AND f.generation_id = ?
              AND f.stage_epoch IS NOT NULL AND f.stage_epoch <> g.candidate_epoch
            LIMIT ' . max(1, (int) $limit), [$this->pid, $this->pid, $generationId]);
        if (!$rows) return 0;
        $ids = [];
        foreach ($rows as $r) $ids[] = (int) $r[0];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        // The ids came from the scoped select above, so the predicate here is
        // belt and braces - and it is the cheap kind: this is a DELETE, and a
        // DELETE that trusts an id list is one refactor away from deleting
        // another project's evidence.
        $this->db->exec('DELETE FROM ' . Schema::table('finding')
            . ' WHERE project_id = ? AND finding_id IN (' . $marks . ')',
            array_merge([$this->pid], $ids));
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
     * COUNTED OVER THIS PROJECT'S GROUPS AND NO OTHERS. Unscoped, this counted
     * every group in the generation, and the generation was the literal 1 for
     * every run of every project: one project's unfinished group answered
     * "pending 1" for a project whose own groups were all settled, so its run
     * could never be promoted and never gave up the slot it held. The reverse
     * direction is worse and was also reachable - our own undiscovered groups
     * are not counted here at all, which is why discover()'s cursor had to be
     * scoped too.
     *
     * @return array{done:bool, groups:int, published:int, blocking:int, pending:int}
     */
    public function status($generationId)
    {
        $r = $this->db->select('SELECT phase, COUNT(*) FROM ' . Schema::table('unique_group')
            . ' WHERE project_id = ? AND generation_id = ? GROUP BY phase',
            [$this->pid, $generationId]);
        $by = [];
        foreach ($r as $row) $by[(string) $row[0]] = (int) $row[1];
        $get = function ($k) use ($by) { return isset($by[$k]) ? $by[$k] : 0; };
        $pending = $get(self::G_NEW) + $get(self::G_VERIFYING) + $get(self::G_EMITTING);
        return ['done' => $pending === 0, 'groups' => array_sum($by),
                'published' => $get(self::G_PUBLISHED), 'blocking' => $get(self::G_COLLISION),
                'pending' => $pending];
    }

    // -- helpers -------------------------------------------------------------

    /**
     * A candidate's event, with the storage sentinel mapped back to "no event".
     *
     * uv_unique_candidate.event_id is NOT NULL DEFAULT 0 from schema version 2,
     * where 0 means the project has no events. It was nullable, and MySQL counts
     * every NULL in a unique index as distinct, so insertCandidate's ON
     * DUPLICATE KEY UPDATE never fired on a classic project and duplicate
     * candidate rows accumulated for one record - harmless only because
     * discover() counts DISTINCT record hashes, which is one line away from
     * reporting a duplicate that is one record counted twice.
     *
     * This class is the only reader of that column, so the sentinel is undone
     * here and nowhere else. It matters in two places: a 0 handed to the read
     * closure would look for the value under an event id that does not exist,
     * find nothing, and block a group that was perfectly decidable; and a 0 in
     * the finding tuple would give duplicate findings a different identity from
     * every other finding about the same location.
     *
     * @return string|null
     */
    private static function eventOrNull($v)
    {
        if ($v === null || $v === '' || (int) $v === 0) return null;
        return $v;
    }

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
