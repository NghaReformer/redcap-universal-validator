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
     * it does not delete them, because a row being deleted may be leased right
     * now and its worker would keep running with no record that it holds
     * anything. Shrinking takes effect as slots fall idle - see idleAbove().
     *
     * @return int rows added
     */
    public function provision($limit)
    {
        $limit = max(1, (int) $limit);
        $t = Schema::table('scan_worker_slot');
        $have = $this->db->select('SELECT COALESCE(MAX(slot_no), 0) FROM ' . $t);
        $max = isset($have[0][0]) ? (int) $have[0][0] : 0;
        $added = 0;
        for ($n = $max + 1; $n <= $limit; $n++) {
            // INSERT IGNORE, not INSERT: two administrators saving settings at
            // the same moment must not turn provisioning into an error.
            $this->db->exec('INSERT IGNORE INTO ' . $t . ' (slot_no, epoch) VALUES (?, 0)', [$n]);
            $added++;
        }
        return $added;
    }

    /**
     * Slots above the configured limit that are currently free.
     *
     * The safe half of shrinking: these can be removed without stranding a
     * worker. Reported rather than acted on, because deleting rows is the kind
     * of thing an operator should choose.
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
     * Take a slot, or null when the installation is at its limit.
     *
     * One statement. The free-or-expired test and the claim are the same
     * operation, so there is no window between deciding and taking.
     */
    public function acquire($owner, $runId, $ttlSeconds)
    {
        $t = Schema::table('scan_worker_slot');
        $this->db->exec('UPDATE ' . $t . '
            SET owner = ?, run_id = ?, epoch = epoch + 1, expires_at = ?
            WHERE (owner IS NULL OR expires_at < ?) ORDER BY slot_no LIMIT 1',
            [$owner, $runId, self::inSeconds($ttlSeconds), self::now()]);
        if ($this->db->affected() !== 1) return null;
        $r = $this->db->select('SELECT slot_no, epoch FROM ' . $t
            . ' WHERE owner = ? ORDER BY slot_no LIMIT 1', [$owner]);
        if (!isset($r[0])) return null;
        return ['slot_no' => (int) $r[0][0], 'epoch' => (int) $r[0][1]];
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
