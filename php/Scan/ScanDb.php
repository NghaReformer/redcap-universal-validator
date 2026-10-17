<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * What SqlScanStore needs from a database, and nothing else.
 *
 * WHY NOT USE $module->query() DIRECTLY. Two reasons, and the second is the real
 * one. The framework's query() returns whatever mysqli handed it and exposes no
 * affected-row count, but every compare-and-set in this design is decided by
 * exactly that number: "zero rows changed" is how a worker learns it was
 * overtaken. A store built on a call that cannot report it would have to re-read
 * and compare, which is a second race.
 *
 * And it makes the store testable against a real server without a REDCap. The
 * database matrix runs the SAME SqlScanStore the module runs, over a plain
 * mysqli connection, which is the only way the concurrency invariants get
 * exercised by the code that will actually hold them.
 *
 * TRANSACTIONS ARE EXPLICIT. No implicit commit, no autocommit toggling behind
 * the caller's back: a batch either commits as a unit or rolls back as one, and
 * the caller decides which by what it returns.
 */
interface ScanDb
{
    /**
     * Rows as a list of positional arrays. Params are always bound, never interpolated.
     *
     * @throws ScanStoreUnavailable when the read failed. An empty list means
     *         "no rows" and nothing else; a read that failed must never be
     *         delivered as one, because every reader of a count or a verdict
     *         would take it as zero.
     */
    public function select($sql, array $params = []);

    /**
     * Run a statement. Returns nothing; ask affected() for the count.
     *
     * @throws ScanStoreUnavailable when the statement failed, or when the
     *         implementation cannot say how many rows it changed. "I do not
     *         know" must never be delivered as a number - see affected().
     */
    public function exec($sql, array $params = []);

    /**
     * Run a statement whose row count NOBODY READS.
     *
     * Most writes in this store are decided by whether they threw, not by how
     * many rows they changed: an audit line, a counter bump, a supersede. Over
     * the External Modules framework every counted write costs a second round
     * trip to read ROW_COUNT(), so a write that discards the answer pays for a
     * question it never asked. This is that write without the question.
     *
     * THE CONTRACT IS ABOUT WHAT affected() SAYS AFTERWARDS. It must throw, or
     * answer -1. It must never answer 0, and never the count of an earlier
     * exec(): zero means "you were fenced out" everywhere in this design, and a
     * stale 1 means "you hold it". tests/scan_moduledb_php.php carries a lint
     * that fails on any affected() read whose nearest preceding write in the
     * same function is not an exec().
     *
     * @throws ScanStoreUnavailable when the statement failed.
     */
    public function execNoCount($sql, array $params = []);

    /**
     * Rows changed by the last exec().
     *
     * THE LOAD-BEARING METHOD. Every fenced update in this design is written so
     * that zero here means "someone moved past me, discard everything". An
     * implementation that returns -1, or the matched-rather-than-changed count,
     * silently converts a rollback into a commit.
     *
     * After execNoCount() there is no count to give. Implementations throw
     * \LogicException rather than hand back a number somebody might compare.
     */
    public function affected();

    public function begin();
    public function commit();
    public function rollback();
}

/**
 * ScanDb over the External Modules framework.
 *
 * affected() goes through ROW_COUNT() rather than a mysqli handle, because the
 * framework does not hand one out. ROW_COUNT() is session-scoped and reports the
 * last statement on THIS connection, which is what we need and is portable
 * across MySQL and MariaDB.
 *
 * ONE ROUND TRIP LATER, WHICH IS THE WHOLE RISK. The write and the row-count
 * read are two statements, and ROW_COUNT() answers for whatever ran last on the
 * session. Measured against MySQL 8.0.46, the arithmetic itself is sound: over
 * every write shape this store uses - plain and prepared UPDATEs, an UPDATE
 * that matched without changing, `UPDATE ... ORDER BY ... LIMIT 1`, multi-row
 * `INSERT ... ON DUPLICATE KEY UPDATE`, `INSERT IGNORE`, a DELETE matching
 * nothing, and DDL - ROW_COUNT() returned exactly what mysqli_affected_rows
 * returned. Every compare-and-set in this module is therefore correct in
 * production TODAY.
 *
 * It is correct because nothing gets between the two statements. ROW_COUNT() is
 * -1 after any statement that returned a result set, INCLUDING A PREVIOUS
 * ROW_COUNT() READ, and after any statement that FAILED - all three measured on
 * the same server. So a framework build that ran one extra query inside query()
 * would make this method report -1 for every write at once: cancel() and
 * finish() would answer false, WorkerSlots::acquire() would return null for a
 * slot it had just taken and never release it, and the operator would be told
 * "this server is running as many scans as it allows at once" over a pool that
 * is free. That is, to the letter, the 1.9.5 pilot symptom ScanWorker::work()
 * carries twenty lines about. -1 is MySQL saying "I do not know"; it is not a
 * row count, and exec() throws rather than passing it on.
 *
 * One caveat worth stating rather than discovering: MySQL's default client flag
 * makes UPDATE report rows CHANGED, not rows MATCHED. That is the behaviour this
 * design wants - an update that matched a row but set it to the value it already
 * held has done nothing, and treating it as success would let two workers both
 * believe they advanced the cursor. The store's predicates are written so the
 * new value always differs from the old.
 *
 * WHAT execNoCount() SKIPS. The ROW_COUNT() read did a second job besides
 * counting: it is -1 after a statement that failed, so it also caught a
 * framework build that reports failure by neither throwing nor returning false.
 * An uncounted write gives that up. It is used only where the framework's own
 * throw-or-false is the whole answer the caller wanted, and never for the
 * findings INSERT, whose silent shortfall would read as a clean project.
 */
final class ModuleDb implements ScanDb
{
    private $module;
    // -1, not 0. Nothing has been counted yet, and zero is an ANSWER in this
    // design ("you were fenced out"). A fresh connection has no answer to give.
    private $affected = -1;
    private $uncounted = false;

    public function __construct($module)
    {
        $this->module = $module;
    }

    /**
     * A READ THAT FAILED IS NOT AN EMPTY ONE (review OC-3).
     *
     * This used to hand whatever query() returned to rows(), which answered []
     * for false and for any shape it could not walk. So on a framework build
     * that reports a failed statement by returning false, one failed read of
     * scan_aggregate gave promotion zero violations and zero rule problems and
     * the run was stored clean; recordStates() failed open the same way. P11
     * closed this for manifestComplete() alone, by changing that one query.
     * The fix belongs here, under every read: false, and any result this class
     * cannot walk, throw ScanStoreUnavailable - the same answer exec() gives a
     * write that returned false, and the one the common framework build already
     * gives by throwing. Every caller reaches the H12 storage-failure handlers
     * in ScanWorker::work() and ScanService, or wraps the read itself.
     */
    public function select($sql, array $params = [])
    {
        $q = $this->module->query($sql, $params);
        if ($q === false) {
            throw new ScanStoreUnavailable('the database refused a read');
        }
        $rows = self::rows($q);
        if ($rows === null) {
            throw new ScanStoreUnavailable('the database answered a read with a result this '
                . 'module cannot walk (' . (is_object($q) ? get_class($q) : gettype($q)) . ')');
        }
        return $rows;
    }

    public function exec($sql, array $params = [])
    {
        // No statement of ours has been counted yet. If anything below throws,
        // affected() must not still be answering for the PREVIOUS write.
        $this->affected = -1;
        $this->uncounted = false;

        $q = $this->module->query($sql, $params);
        if ($q === false) {
            // Framework builds differ: most throw on a failed statement, some
            // return false. Reading a row count for a statement that never ran
            // would answer for whatever ran before it.
            throw new ScanStoreUnavailable('the database refused the statement');
        }

        $r = self::rows($this->module->query('SELECT ROW_COUNT()', []));
        $n = (isset($r[0][0]) && $r[0][0] !== null) ? (int) $r[0][0] : -1;
        if ($n < 0) {
            // "I DO NOT KNOW" IS NOT ZERO. Both directions of guessing are a
            // silent data bug: rounded down to 0, every fenced update reads as
            // "you were fenced out" and a good batch is discarded; passed
            // through as -1, `affected() === 1` is false and WorkerSlots leaks
            // the slot it just took. See the class docblock for what produces a
            // negative count and for the pilot round it reproduces.
            throw new ScanStoreUnavailable('the database did not report a row count '
                . 'for the last write');
        }
        $this->affected = $n;
    }

    public function execNoCount($sql, array $params = [])
    {
        // Poisoned BEFORE the statement runs, for the same reason exec() does
        // it: whatever happens next, affected() must not answer for an earlier
        // write.
        $this->affected = -1;
        $this->uncounted = true;

        $q = $this->module->query($sql, $params);
        if ($q === false) {
            throw new ScanStoreUnavailable('the database refused the statement');
        }
    }

    public function affected()
    {
        if ($this->uncounted) {
            // A caller comparing this to 1 would fail quietly and one adding it
            // to a total would corrupt the total. Neither is a storage fault, so
            // neither gets the operator sentence: this is a programming error.
            throw new \LogicException('affected() was asked about a write sent with '
                . 'execNoCount(); send it with exec() if the count matters');
        }
        return $this->affected;
    }

    // START TRANSACTION rather than SET autocommit: the latter is connection
    // state that outlives a throw, so a failure between begin and commit would
    // leave every later statement on this request inside an open transaction.
    public function begin()    { $this->module->query('START TRANSACTION', []); }
    public function commit()   { $this->module->query('COMMIT', []); }
    public function rollback() { $this->module->query('ROLLBACK', []); }

    /**
     * Whatever shape the framework returned, as a list of positional rows.
     *
     * NULL, NOT [], FOR A SHAPE IT CANNOT WALK. An empty list is an answer -
     * "no such row" - and a result nobody could read is not one. select()
     * throws on null; exec() reads a null ROW_COUNT() as "no count", which it
     * already refuses.
     *
     * @return array|null
     */
    private static function rows($q)
    {
        if (is_array($q)) return $q;
        $out = [];
        if (is_object($q) && is_callable([$q, 'fetch_row'])) {
            while ($row = $q->fetch_row()) $out[] = $row;
        } elseif (is_object($q) && is_callable([$q, 'fetch_assoc'])) {
            while ($row = $q->fetch_assoc()) $out[] = array_values($row);
        } else {
            return null;
        }
        return $out;
    }
}
