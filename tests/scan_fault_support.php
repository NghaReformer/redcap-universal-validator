<?php
/**
 * scan_fault_support.php — the fakes that let a test break the database.
 *
 * WHY THIS FILE EXISTS. The four masking catches in SqlScanStore were invisible
 * to twelve thousand green assertions for one reason: no test in the repository
 * could make a ScanDb fail. The fast suite drives ArrayScanStore, which has no
 * database to break, and the database matrix substitutes its own MysqliDb,
 * which has no fault injection. So the entire error-handling half of the store
 * had never been executed once.
 *
 * Three fakes, each as small as the thing it has to stand in for:
 *
 *   ScriptedDb   a ScanDb whose selects are canned and whose Nth statement
 *                matching a substring THROWS. It is not a mock of a database -
 *                it answers only what the test wrote down, and the assertions
 *                are about what SqlScanStore does with a throw, which is
 *                exactly the behaviour a real server cannot be asked to produce
 *                on demand.
 *
 *   FaultyStore  a ScanStore that delegates to a real ArrayScanStore and throws
 *                ScanStoreUnavailable from whichever methods the test armed.
 *                Written out method by method rather than through __call,
 *                because the interface is the contract and a decorator that
 *                silently absorbs a new method is a decorator that hides the
 *                next gap.
 *
 *   RecordingFramework  a $module stand-in with query() and log(), so a test
 *                can read back what reached the module log - which is where
 *                everything the server said is now supposed to go.
 *
 * Included by tests/scan_sqlstore_fault_php.php, tests/scan_worker_php.php and
 * tests/scan_service_php.php. It runs nothing on its own.
 */

namespace INSPIRE\UniversalValidator\Scan;

// Its own dependencies, so a suite that wants only the framework stand-in does
// not have to know which of the eight classes below the store fakes touch.
require_once __DIR__ . '/../php/Scan/ScanOutcome.php';
require_once __DIR__ . '/../php/Scan/ScanPhase.php';
require_once __DIR__ . '/../php/Scan/ScanDb.php';
require_once __DIR__ . '/../php/Scan/DbError.php';
require_once __DIR__ . '/../php/Scan/ScanStoreUnavailable.php';
require_once __DIR__ . '/../php/Scan/ScanStore.php';
require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';
// findings() keys its rows by FindingRowMapper::INPUT_KEYS (B5-N1).
require_once __DIR__ . '/../php/Scan/FindingRowMapper.php';

/**
 * A ScanDb the test controls completely.
 *
 * SELECTS answer from $rows: the first key that appears in the statement wins,
 * so a test writes 'FROM uv_scan_run' rather than the whole statement. Anything
 * unmatched is an empty result, which is what a store reads as "no such row".
 *
 * FAULTS are armed by substring and by occurrence, because the interesting
 * failures are not the first statement. `failAt('FOR UPDATE', 2)` breaks the
 * second fenced read and leaves the first one working, which is how a deadlock
 * actually arrives.
 */
class ScriptedDb implements ScanDb
{
    /** @var array substring => rows */
    public $rows = [];
    /** @var int what the next exec() should report */
    public $affectedNext = 1;
    /** @var string[] every statement, in order */
    public $log = [];
    /** @var int how many times rollback() was called */
    public $rollbacks = 0;
    /** @var bool make rollback() itself throw, as a dead connection does */
    public $rollbackFails = false;

    private $faults = [];      // list of [substring, occurrence, throwable]
    private $seen = [];        // substring => how many statements have matched it
    /** @var bool the last write was sent with execNoCount() */
    private $uncounted = false;
    /** @var string the last write, for a subclass whose count depends on it */
    protected $lastWrite = '';

    /**
     * Break the $nth statement containing $needle.
     *
     * The exception carries a real MySQL message and errno, because DbError
     * reads both and a fake message would test the redaction against text no
     * server produces.
     */
    public function failAt($needle, $nth, ?\Throwable $e = null)
    {
        if ($e === null) {
            $e = new \RuntimeException('Deadlock found when trying to get lock; '
                . 'try restarting transaction', 1213);
        }
        $this->faults[] = [$needle, max(1, (int) $nth), $e];
        return $this;
    }

    public function select($sql, array $params = [])
    {
        $this->fire($sql);
        foreach ($this->rows as $needle => $rows) {
            if (strpos($sql, $needle) !== false) return $rows;
        }
        // A real server always answers LAST_INSERT_ID(), so a stub that does
        // not is modelling a database that cannot exist - and every scenario
        // here would then be testing the generation allocator rather than the
        // fault it was written for. A scenario that wants a specific number,
        // or wants the allocation to fail, still says so through rows/failAt.
        if (strpos($sql, 'LAST_INSERT_ID') !== false) return [[1]];
        return [];
    }

    public function exec($sql, array $params = [])
    {
        $this->uncounted = false;
        $this->lastWrite = $sql;
        $this->fire($sql);
    }

    /**
     * Through fire(), like exec(): a fault armed on a statement must still go
     * off when the store stops counting that statement, or converting a call
     * site would quietly disarm the test written for it.
     */
    public function execNoCount($sql, array $params = [])
    {
        $this->uncounted = true;
        $this->lastWrite = $sql;
        $this->fire($sql);
    }

    public function affected()
    {
        if ($this->uncounted) {
            throw new \LogicException('affected() was asked about a write sent with execNoCount()');
        }
        return $this->affectedNext;
    }

    public function begin() { $this->log[] = 'BEGIN'; }
    public function commit() { $this->log[] = 'COMMIT'; }

    public function rollback()
    {
        $this->rollbacks++;
        $this->log[] = 'ROLLBACK';
        if ($this->rollbackFails) {
            throw new \RuntimeException('MySQL server has gone away', 2006);
        }
    }

    private function fire($sql)
    {
        $this->log[] = $sql;
        foreach ($this->faults as $f) {
            list($needle, $nth, $e) = $f;
            if (strpos($sql, $needle) === false) continue;
            if (!isset($this->seen[$needle])) $this->seen[$needle] = 0;
            $this->seen[$needle]++;
            if ($this->seen[$needle] === $nth) throw $e;
        }
    }
}

/**
 * A ScanStore that fails on demand, over one that does not.
 *
 * The inner store is a real ArrayScanStore, so everything the test did NOT arm
 * behaves exactly as the contract says. That matters: a worker driven by a
 * store that answers nothing would stop for reasons unrelated to the one under
 * test, and the assertion would pass for the wrong reason.
 */
class FaultyStore implements ScanStore
{
    /** @var ArrayScanStore */
    private $inner;
    /** @var array method name => true */
    private $armed = [];
    /** @var string what the failure says it was */
    public $detail = '[1213] Deadlock found when trying to get lock; the work was rolled back';

    public function __construct(ArrayScanStore $inner)
    {
        $this->inner = $inner;
    }

    /** @param string|string[] $methods */
    public function failFrom($methods)
    {
        foreach ((array) $methods as $m) $this->armed[$m] = true;
        return $this;
    }

    private function gate($method)
    {
        if (isset($this->armed[$method])) {
            throw new ScanStoreUnavailable($this->detail);
        }
    }

    public function startRun($pid, array $run)
    { $this->gate('startRun'); return $this->inner->startRun($pid, $run); }

    public function run($pid, $runId)
    { $this->gate('run'); return $this->inner->run($pid, $runId); }

    public function writeManifest($runId, array $records)
    { $this->gate('writeManifest'); return $this->inner->writeManifest($runId, $records); }

    public function appendManifest($runId, array $records, ?array $plan = null)
    { $this->gate('appendManifest'); return $this->inner->appendManifest($runId, $records, $plan); }

    public function claimPlan($runId, $owner, $epoch, $leaseSeconds, $maxAttempts)
    {
        $this->gate('claimPlan');
        return $this->inner->claimPlan($runId, $owner, $epoch, $leaseSeconds, $maxAttempts);
    }

    public function releasePlan($runId, $owner)
    { $this->gate('releasePlan'); return $this->inner->releasePlan($runId, $owner); }

    public function freezeManifest($runId)
    { $this->gate('freezeManifest'); return $this->inner->freezeManifest($runId); }

    public function claim($runId, $owner, $epoch, $limit)
    { $this->gate('claim'); return $this->inner->claim($runId, $owner, $epoch, $limit); }

    public function claimPending($runId, $owner, $epoch, $limit, $staleSeconds = 900,
                                 $maxAttempts = null, $exhausted = ScanStore::REC_UNREADABLE,
                                 &$retired = null)
    {
        $this->gate('claimPending');
        return $this->inner->claimPending($runId, $owner, $epoch, $limit, $staleSeconds,
                                          $maxAttempts, $exhausted, $retired);
    }

    public function commitBatch($runId, $owner, $epoch, array $batch, &$heldCount = null,
                                &$heldOrdinals = null)
    {
        $this->gate('commitBatch');
        return $this->inner->commitBatch($runId, $owner, $epoch, $batch, $heldCount,
                                         $heldOrdinals);
    }

    public function releaseClaims($runId, $epoch, $owner, array $claims)
    {
        $this->gate('releaseClaims');
        return $this->inner->releaseClaims($runId, $epoch, $owner, $claims);
    }

    public function noteAttempts($runId, $epoch, $owner, array $claims, $maxAttempts, $exhausted)
    {
        $this->gate('noteAttempts');
        return $this->inner->noteAttempts($runId, $epoch, $owner, $claims, $maxAttempts, $exhausted);
    }

    public function manifestComplete($runId)
    { $this->gate('manifestComplete'); return $this->inner->manifestComplete($runId); }

    public function advancePhase($runId, $epoch, $to)
    { $this->gate('advancePhase'); return $this->inner->advancePhase($runId, $epoch, $to); }

    public function finish($runId, array $outcome)
    { $this->gate('finish'); return $this->inner->finish($runId, $outcome); }

    public function cancel($pid, $runId, $actor)
    { $this->gate('cancel'); return $this->inner->cancel($pid, $runId, $actor); }

    public function expireAbandoned($pid, $staleHours, $actor, $now = null)
    { $this->gate('expireAbandoned'); return $this->inner->expireAbandoned($pid, $staleHours, $actor, $now); }

    public function findings($projectId, $generationId, array $scope, array $filter, $afterId, $limit,
                             &$through = null)
    {
        // EVERY ARGUMENT, IN ORDER. This delegate once dropped $projectId and
        // shifted every other one along, so the generation arrived as the
        // project; it now carries the scope and hands the cursor back.
        $this->gate('findings');
        return $this->inner->findings($projectId, $generationId, $scope, $filter, $afterId, $limit, $through);
    }

    public function writeDimensions($projectId, $generationId, array $rows)
    { $this->gate('writeDimensions'); return $this->inner->writeDimensions($projectId, $generationId, $rows); }

    public function dimensions($projectId, $generationId, ?array $after = null, $limit = self::DIM_PAGE)
    { $this->gate('dimensions'); return $this->inner->dimensions($projectId, $generationId, $after, $limit); }

    public function dimension($projectId, $generationId, $kind, $key)
    { $this->gate('dimension'); return $this->inner->dimension($projectId, $generationId, $kind, $key); }

    public function aggregates($runId)
    { $this->gate('aggregates'); return $this->inner->aggregates($runId); }

    public function supersedePrevious($pid, $runId, $limit)
    { $this->gate('supersedePrevious'); return $this->inner->supersedePrevious($pid, $runId, $limit); }

    public function supersededBy($pid, $runId)
    { $this->gate('supersededBy'); return $this->inner->supersededBy($pid, $runId); }

    public function audit($pid, $runId, $event, $actor, $detail)
    { $this->gate('audit'); return $this->inner->audit($pid, $runId, $event, $actor, $detail); }

    public function reconcileAdd($runId, $epoch, array $records)
    { $this->gate('reconcileAdd'); return $this->inner->reconcileAdd($runId, $epoch, $records); }

    public function requeue($runId, $epoch, array $hashes)
    { $this->gate('requeue'); return $this->inner->requeue($runId, $epoch, $hashes); }

    public function tombstone($runId, $epoch, array $hashes)
    { $this->gate('tombstone'); return $this->inner->tombstone($runId, $epoch, $hashes); }

    public function depart($runId, $epoch, array $hashes)
    { $this->gate('depart'); return $this->inner->depart($runId, $epoch, $hashes); }

    public function departedOf($runId, array $hashes)
    { $this->gate('departedOf'); return $this->inner->departedOf($runId, $hashes); }

    public function recordStates($runId)
    { $this->gate('recordStates'); return $this->inner->recordStates($runId); }

    public function scannedVersions($runId, array $hashes)
    { $this->gate('scannedVersions'); return $this->inner->scannedVersions($runId, $hashes); }

    public function progressState($runId)
    { $this->gate('progressState'); return $this->inner->progressState($runId); }

    public function setProgressState($runId, $epoch, array $state)
    { $this->gate('setProgressState'); return $this->inner->setProgressState($runId, $epoch, $state); }

    public function holdRollupCursor($runId, $epoch)
    { $this->gate('holdRollupCursor'); return $this->inner->holdRollupCursor($runId, $epoch); }

    public function holdRun($runId, $epoch, $phase)
    { $this->gate('holdRun'); return $this->inner->holdRun($runId, $epoch, $phase); }

    public function markAggregate($runId, $kind, $axis1, $axis2, $blocks = 0, $samples = null)
    { $this->gate('markAggregate'); return $this->inner->markAggregate($runId, $kind, $axis1, $axis2, $blocks, $samples); }

    public function addAggregate($runId, $kind, $axis1, $axis2, $cnt, $blocks = 0, $samples = null)
    {
        $this->gate('addAggregate');
        return $this->inner->addAggregate($runId, $kind, $axis1, $axis2, $cnt, $blocks, $samples);
    }

    public function blockingAggregates($runId)
    { $this->gate('blockingAggregates'); return $this->inner->blockingAggregates($runId); }
}

/**
 * Make every claim on an in-memory run look $seconds older.
 *
 * A dead worker is a claim that has aged (review R2S-1), and the fast suite
 * cannot wait fifteen minutes to show one. The database matrix moves
 * updated_at for the same purpose. Bound into the store rather than added to
 * it, because a public method there would be one more method with no
 * production caller - tests/scan_wiring_php.php counts those.
 */
function ageClaims(ArrayScanStore $store, $runId, $seconds)
{
    $age = function ($runId, $seconds) {
        if (!isset($this->records[$runId])) return;
        foreach ($this->records[$runId] as $o => $rec) {
            if ($rec['claimed_at'] === null) continue;
            $this->records[$runId][$o]['claimed_at'] = (int) $rec['claimed_at'] - (int) $seconds;
        }
    };
    \Closure::bind($age, $store, ArrayScanStore::class)($runId, $seconds);
}

/**
 * A framework stand-in that remembers what it was asked and what it was told.
 *
 * query() answers from a canned map keyed by substring, exactly as ScriptedDb
 * does, with two switches for the shapes M14 is about: $rowCount decides what
 * `SELECT ROW_COUNT()` returns, and $writeReturnsFalse makes a write answer
 * false rather than throwing, which some framework builds do.
 */
class RecordingFramework
{
    /** @var array substring => rows */
    public $canned = [];
    /** @var int|null what SELECT ROW_COUNT() answers; null means "no row at all" */
    public $rowCount = 1;
    /** @var bool run one extra statement inside query(), as a chatty build does */
    public $chatty = false;
    /** @var bool a failed write returns false instead of throwing */
    public $writeReturnsFalse = false;
    /**
     * @var bool when true, every SELECT except ROW_COUNT() answers $selectAnswer -
     *      false for a build that reports a failed read that way, or a shape
     *      ModuleDb cannot walk
     */
    public $overrideSelect = false;
    /** @var mixed what an overridden SELECT answers */
    public $selectAnswer = false;
    /** @var string|null when set, only a SELECT containing this is overridden */
    public $overrideOnly = null;
    /** @var string[] statements, in order */
    public $queries = [];
    /** @var array list of [event, context] */
    public $logs = [];

    public function query($sql, $params = [])
    {
        $this->queries[] = $sql;
        if (strpos($sql, 'SELECT ROW_COUNT()') !== false) {
            // A chatty build has already run something else on this session, so
            // the count the server has for us is the one MySQL gives after a
            // result set: -1. Measured on 8.0.46, not invented.
            if ($this->chatty) return [[-1]];
            return $this->rowCount === null ? [] : [[$this->rowCount]];
        }
        if ($this->writeReturnsFalse && preg_match('/^\s*(INSERT|UPDATE|DELETE)/i', $sql)) {
            return false;
        }
        if ($this->overrideSelect && preg_match('/^\s*SELECT/i', $sql)
                && ($this->overrideOnly === null || strpos($sql, $this->overrideOnly) !== false)) {
            return $this->selectAnswer;
        }
        foreach ($this->canned as $needle => $rows) {
            if (strpos($sql, $needle) !== false) return $rows;
        }
        return [];
    }

    public function log($event, $context = [])
    {
        $this->logs[] = [$event, $context];
        return 1;
    }

    /** Everything logged under one event name, as a flat list of contexts. */
    public function logged($event)
    {
        $out = [];
        foreach ($this->logs as $l) {
            if ($l[0] === $event) $out[] = $l[1];
        }
        return $out;
    }
}
