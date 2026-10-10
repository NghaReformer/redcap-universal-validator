<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * The installation-wide worker semaphore.
 *
 * WHY INSTALLATION-WIDE AND NOT PER PROJECT. The resource being rationed is the
 * server. Two projects scanning at once cost it the same as one project scanning
 * twice, so a per-project limit rations the wrong thing: it would let twenty
 * projects each run "one" scan and flatten the box. `scan-system-max-concurrent-
 * projects` is a system setting for exactly this reason, and a project cannot
 * raise it.
 *
 * WHY ROWS ARE PRECREATED. Leasing is an UPDATE with a predicate, never an
 * INSERT. Two workers racing to insert "slot 3" both succeed or both fail
 * depending on a unique key they would have to remember to add; two workers
 * racing to UPDATE the same free row are serialised by InnoDB and exactly one
 * sees a row changed. The count of rows IS the limit, which also means changing
 * the limit is provisioning, not a code path.
 *
 * WHY LEASES EXPIRE. The browser is a worker, and a browser closes. Without an
 * expiry a closed tab holds a slot until someone notices; with one, the slot
 * returns to the pool on its own and a `renew()` from a live worker is what
 * keeps it. Expiry is the difference between a semaphore and a leak.
 *
 * Fencing is by (owner, epoch): every acquisition bumps the epoch, so a worker
 * whose lease was taken over cannot release or renew the slot it used to hold.
 * A stale release that succeeded would hand a live worker's slot to someone else.
 */
final class WorkerSlots
{
    /** Rows one provisioning statement writes at most (review R8-4). */
    const CHUNK = 500;

    /** The largest slot number scan_worker_slot.slot_no (SMALLINT UNSIGNED) holds. */
    const MAX_SLOT_NO = 65535;

    /** @var ScanDb */
    private $db;

    public function __construct(ScanDb $db)
    {
        $this->db = $db;
    }

    /**
     * Make the slot table match the configured limit.
     *
     * ADDITIVE ONLY, and deliberately so. Raising the limit adds rows; LOWERING
     * it does not delete them here, because a row being deleted may be leased
     * right now and its worker would keep running with no record that it holds
     * anything. A lowered limit takes effect in acquire(), which takes no slot
     * above it, and the rows above it are removed as they fall idle - see
     * shrinkTo().
     *
     * ROWS ADDED, AS THE SERVER COUNTS THEM (W6-N3). This counted one per
     * INSERT IGNORE it SENT, and an ignored insert - a row another save had
     * just written between the MAX read and the insert - was reported as added.
     * The rows now go in as few statements as the gap needs and the answer is
     * the sum of their affected counts, which for INSERT IGNORE is the rows
     * actually inserted.
     *
     * BOUNDED (wave 8 review R8-4). The limit comes from free text.
     * ScanPolicy clamps the setting to MAX_PROJECTS; this clamps whatever it
     * is handed to the slot_no column's range too, because a gap of a billion
     * made range() exhaust memory - a fatal error, not an exception. The rows
     * go in CHUNK at a time, because one statement with a placeholder a row
     * fails above 65535 placeholders and wrote no slot at all.
     *
     * @return int rows added
     */
    public function provision($limit)
    {
        $limit = min(self::MAX_SLOT_NO, max(1, (int) $limit));
        $t = Schema::table('scan_worker_slot');
        $have = $this->db->select('SELECT COALESCE(MAX(slot_no), 0) FROM ' . $t);
        $max = isset($have[0][0]) ? (int) $have[0][0] : 0;
        if ($max >= $limit) return 0;
        // The candidate list is the gap between the table and the limit; one
        // placeholder a row.
        $added = 0;
        foreach (array_chunk(range($max + 1, $limit), self::CHUNK) as $nos) {
            // INSERT IGNORE, not INSERT: two administrators saving settings at
            // the same moment must not turn provisioning into an error.
            $this->db->exec('INSERT IGNORE INTO ' . $t . ' (slot_no, epoch) VALUES '
                . implode(',', array_fill(0, count($nos), '(?, 0)')), $nos);
            $added += (int) $this->db->affected();
        }
        return $added;
    }

    /**
     * Slots above the configured limit that are currently free.
     *
     * The safe half of shrinking: these can be removed without stranding a
     * worker. shrinkTo() removes them.
     */
    public function idleAbove($limit)
    {
        $rows = $this->db->select('SELECT slot_no FROM ' . Schema::table('scan_worker_slot')
            . ' WHERE slot_no > ? AND (owner IS NULL OR expires_at < ?) ORDER BY slot_no',
            [max(1, (int) $limit), self::now()]);
        $out = [];
        foreach ($rows as $r) $out[] = (int) $r[0];
        return $out;
    }

    /**
     * Remove the free slots above a lowered limit (W1-N1, review R4-4).
     *
     * LOWERING THE LIMIT DID NOTHING. provision() only adds rows and acquire()
     * took any free row, so the rows above a lowered limit stayed in the pool
     * and kept being leased: measured on MySQL 8.0, 1200 slots provisioned
     * before the R8-4 clamp admitted 1200 concurrent workers with the setting
     * text promising 1000. acquire() now takes no slot above the limit it is
     * given, which is what makes a lower limit take effect at once; this
     * removes the rows above it, so the table says what the limit is.
     *
     * ONLY ROWS NOBODY HOLDS. A leased row above the limit is left until its
     * holder releases it or its lease runs out, and the next call removes it.
     * Deleting it under its holder would let that worker run on with no row
     * saying it holds anything. The free test is repeated in the DELETE, so a
     * row leased between the read and the delete stays: InnoDB serialises the
     * two on the row.
     *
     * Called by the settings save and by every pass of the reap cron, so a
     * lowered limit is reflected in the table within one cron interval.
     *
     * @return int rows removed
     */
    public function shrinkTo($limit)
    {
        $limit = min(self::MAX_SLOT_NO, max(1, (int) $limit));
        $removed = 0;
        foreach (array_chunk($this->idleAbove($limit), self::CHUNK) as $nos) {
            $this->db->exec('DELETE FROM ' . Schema::table('scan_worker_slot')
                . ' WHERE slot_no IN (' . implode(',', array_fill(0, count($nos), '?')) . ')'
                . ' AND slot_no > ? AND (owner IS NULL OR expires_at < ?)',
                array_merge($nos, [$limit, self::now()]));
            $removed += (int) $this->db->affected();
        }
        return $removed;
    }

    /**
     * Take a slot, or null when the installation is at its limit.
     *
     * NAME THE SLOT BEFORE TAKING IT. This was one statement — `UPDATE ...
     * WHERE owner IS NULL ORDER BY slot_no LIMIT 1` — followed by a read-back
     * asking which slot the owner now held, and the read-back was written as
     * `WHERE owner = ? ORDER BY slot_no LIMIT 1`. That finds the LOWEST slot
     * this owner holds, which is the one just taken only if the owner held none
     * before. Measured against MySQL 8.0.46: an owner leasing twice is told
     * "slot 1" both times, the table shows it holding slots 1 and 2, and
     * releasing what it was told frees slot 1 and strands slot 2 until its TTL
     * expires. The owner string is not unique by construction — ScanWorker
     * falls back to the literal 'worker' when no owner is configured — so this
     * is reachable rather than theoretical.
     *
     * So the free slot is chosen by a read, and the claim is a compare-and-set
     * on the epoch that read returned. Three properties come out of that:
     *
     *   The slot number is known BEFORE the write, so nothing has to be guessed
     *   afterwards and there is no second read to be wrong.
     *
     *   The epoch is written explicitly as old + 1 rather than as `epoch + 1`,
     *   so the value handed back is the value in the row, not an inference
     *   about it. This is the store's own rule from SqlScanStore's docblock: if
     *   success does not change a value, affected() cannot tell you whether it
     *   happened — and here it always changes, because old + 1 never equals old.
     *
     *   A worker that loses the race between the read and the write sees
     *   affected() === 0 and moves to the next candidate, so contention costs a
     *   statement rather than a false "the server is at its limit".
     *
     * The candidate list is not paged. It is bounded by the slot table, whose
     * row count IS the configured concurrency limit — tens of rows, provisioned
     * by an administrator.
     *
     * AND BY $limit, WHEN ONE IS GIVEN (review R4-4). The count of rows is the
     * limit only until the limit is lowered: the rows above it stay until they
     * are idle and shrinkTo() removes them, and a free one used to be taken
     * like any other. A slot numbered above the limit is never taken now, so
     * the setting's number is the most workers there can be. Null is no limit,
     * for a caller that has none to give (tests, and nothing in production).
     */
    public function acquire($owner, $runId, $ttlSeconds, $limit = null)
    {
        $t = Schema::table('scan_worker_slot');
        if ($limit !== null) {
            $free = $this->db->select('SELECT slot_no, epoch FROM ' . $t
                . ' WHERE slot_no <= ? AND (owner IS NULL OR expires_at < ?) ORDER BY slot_no',
                [min(self::MAX_SLOT_NO, max(1, (int) $limit)), self::now()]);
        } else {
            $free = $this->db->select('SELECT slot_no, epoch FROM ' . $t
                . ' WHERE owner IS NULL OR expires_at < ? ORDER BY slot_no', [self::now()]);
        }
        foreach ($free as $row) {
            $no = (int) $row[0];
            $next = (int) $row[1] + 1;
            $this->db->exec('UPDATE ' . $t . '
                SET owner = ?, run_id = ?, epoch = ?, expires_at = ?
                WHERE slot_no = ? AND epoch = ? AND (owner IS NULL OR expires_at < ?)',
                [$owner, $runId, $next, self::inSeconds($ttlSeconds), $no, (int) $row[1],
                 self::now()]);
            if ($this->db->affected() === 1) {
                return ['slot_no' => $no, 'epoch' => $next];
            }
        }
        return null;
    }

    /**
     * Extend a lease this worker still holds.
     *
     * Returns false when the lease was taken over, which is the signal to stop
     * rather than to retry: a worker that lost its slot must not keep working,
     * because the installation has already allocated its capacity elsewhere.
     */
    public function renew($slotNo, $owner, $epoch, $ttlSeconds)
    {
        $t = Schema::table('scan_worker_slot');
        $this->db->exec('UPDATE ' . $t . '
            SET expires_at = ? WHERE slot_no = ? AND owner = ? AND epoch = ?',
            [self::inSeconds($ttlSeconds), $slotNo, $owner, $epoch]);
        if ($this->db->affected() === 1) return true;

        // ZERO IS AMBIGUOUS HERE, and that is the whole comment. MySQL reports
        // rows CHANGED: a worker renewing twice within the same second with the
        // same TTL writes the expiry it already had, changes nothing, and would
        // be told it had lost its lease. It would then stop working while still
        // holding a slot - the semaphore leaks and the scan stalls.
        //
        // So ask rather than assume. If the row is still ours at the same epoch,
        // the renewal was a no-op and succeeded. If someone took it over in the
        // gap between these two statements we answer false, which is the safe
        // direction: a worker that stops when it did not have to costs one
        // batch, and a worker that continues after losing its slot costs the
        // capacity limit the slot exists to enforce.
        $still = $this->db->select('SELECT 1 FROM ' . $t . '
            WHERE slot_no = ? AND owner = ? AND epoch = ?', [$slotNo, $owner, $epoch]);
        return isset($still[0]);
    }

    /** Give the slot back. A stale holder releases nothing. */
    public function release($slotNo, $owner, $epoch)
    {
        $this->db->exec('UPDATE ' . Schema::table('scan_worker_slot') . '
            SET owner = NULL, run_id = NULL, expires_at = NULL
            WHERE slot_no = ? AND owner = ? AND epoch = ?', [$slotNo, $owner, $epoch]);
        return $this->db->affected() === 1;
    }

    /** How many slots exist, and how many are currently held. For diagnostics. */
    public function census()
    {
        $r = $this->db->select('SELECT COUNT(*), SUM(CASE WHEN owner IS NOT NULL
            AND expires_at >= ? THEN 1 ELSE 0 END) FROM ' . Schema::table('scan_worker_slot'),
            [self::now()]);
        return ['total' => isset($r[0][0]) ? (int) $r[0][0] : 0,
                'held'  => isset($r[0][1]) ? (int) $r[0][1] : 0];
    }

    private static function now() { return gmdate('Y-m-d H:i:s'); }
    private static function inSeconds($s) { return gmdate('Y-m-d H:i:s', time() + (int) $s); }
}
