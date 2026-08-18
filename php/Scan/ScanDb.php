<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * The four things SqlScanStore needs from a database, and nothing else.
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
    /** Rows as a list of positional arrays. Params are always bound, never interpolated. */
    public function select($sql, array $params = []);

    /** Run a statement. Returns nothing; ask affected() for the count. */
    public function exec($sql, array $params = []);

    /**
     * Rows changed by the last exec().
     *
     * THE LOAD-BEARING METHOD. Every fenced update in this design is written so
     * that zero here means "someone moved past me, discard everything". An
     * implementation that returns -1, or the matched-rather-than-changed count,
     * silently converts a rollback into a commit.
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
 * One caveat worth stating rather than discovering: MySQL's default client flag
 * makes UPDATE report rows CHANGED, not rows MATCHED. That is the behaviour this
 * design wants - an update that matched a row but set it to the value it already
 * held has done nothing, and treating it as success would let two workers both
 * believe they advanced the cursor. The store's predicates are written so the
 * new value always differs from the old.
 */
final class ModuleDb implements ScanDb
{
    private $module;
    private $affected = 0;

    public function __construct($module)
    {
        $this->module = $module;
    }

    public function select($sql, array $params = [])
    {
        $q = $this->module->query($sql, $params);
        return self::rows($q);
    }

    public function exec($sql, array $params = [])
    {
        $this->module->query($sql, $params);
        $r = self::rows($this->module->query('SELECT ROW_COUNT()', []));
        $this->affected = (isset($r[0][0]) && $r[0][0] !== null) ? (int) $r[0][0] : 0;
    }

    public function affected()
    {
        return $this->affected;
    }

    // START TRANSACTION rather than SET autocommit: the latter is connection
    // state that outlives a throw, so a failure between begin and commit would
    // leave every later statement on this request inside an open transaction.
    public function begin()    { $this->module->query('START TRANSACTION', []); }
    public function commit()   { $this->module->query('COMMIT', []); }
    public function rollback() { $this->module->query('ROLLBACK', []); }

    /** Whatever shape the framework returned, as a list of positional rows. */
    private static function rows($q)
    {
        if (is_array($q)) return $q;
        $out = [];
        if (is_object($q) && is_callable([$q, 'fetch_row'])) {
            while ($row = $q->fetch_row()) $out[] = $row;
        } elseif (is_object($q) && is_callable([$q, 'fetch_assoc'])) {
            while ($row = $q->fetch_assoc()) $out[] = array_values($row);
        }
        return $out;
    }
}
