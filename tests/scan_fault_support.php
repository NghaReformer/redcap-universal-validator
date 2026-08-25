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

    /**
     * Break the $nth statement containing $needle.
     *
     * The exception carries a real MySQL message and errno, because DbError
     * reads both and a fake message would test the redaction against text no
     * server produces.
     */
    public function failAt($needle, $nth, \Throwable $e = null)
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
        $this->fire($sql);
    }

    public function affected()
    {
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

    public function appendManifest($runId, array $records)
    { $this->gate('appendManifest'); return $this->inner->appendManifest($runId, $records); }

    public function freezeManifest($runId)
    { $this->gate('freezeManifest'); return $this->inner->freezeManifest($runId); }

    public function claim($runId, $owner, $epoch, $limit)
    { $this->gate('claim'); return $this->inner->claim($runId, $owner, $epoch, $limit); }

    public function claimPending($runId, $owner, $epoch, $limit, $staleSeconds = 900)
    {
        $this->gate('claimPending');
        return $this->inner->claimPending($runId, $owner, $epoch, $limit, $staleSeconds);
    }

    public function commitBatch($runId, $owner, $epoch, $expectCursor, array $batch)
    {
        $this->gate('commitBatch');
        return $this->inner->commitBatch($runId, $owner, $epoch, $expectCursor, $batch);
    }

    public function releaseClaims($runId, $epoch, array $ordinals)
    { $this->gate('releaseClaims'); return $this->inner->releaseClaims($runId, $epoch, $ordinals); }

    public function manifestComplete($runId)
    { $this->gate('manifestComplete'); return $this->inner->manifestComplete($runId); }

    public function advancePhase($runId, $epoch, $to)
    { $this->gate('advancePhase'); return $this->inner->advancePhase($runId, $epoch, $to); }

    public function finish($runId, array $outcome)
    { $this->gate('finish'); return $this->inner->finish($runId, $outcome); }

    public function cancel($pid, $runId, $actor)
    { $this->gate('cancel'); return $this->inner->cancel($pid, $runId, $actor); }

    public function findings($projectId, $generationId, array $filter, $afterId, $limit)
    { $this->gate('findings'); return $this->inner->findings($generationId, $filter, $afterId, $limit); }

    public function aggregates($runId)
    { $this->gate('aggregates'); return $this->inner->aggregates($runId); }

    public function expireValues($now)
    { $this->gate('expireValues'); return $this->inner->expireValues($now); }


    public function audit($pid, $runId, $event, $actor, $detail)
    { $this->gate('audit'); return $this->inner->audit($pid, $runId, $event, $actor, $detail); }

    public function reconcileAdd($runId, $epoch, array $records)
    { $this->gate('reconcileAdd'); return $this->inner->reconcileAdd($runId, $epoch, $records); }

    public function requeue($runId, $epoch, array $recordIds)
    { $this->gate('requeue'); return $this->inner->requeue($runId, $epoch, $recordIds); }

    public function tombstone($runId, $epoch, array $recordIds)
    { $this->gate('tombstone'); return $this->inner->tombstone($runId, $epoch, $recordIds); }

    public function recordStates($runId)
    { $this->gate('recordStates'); return $this->inner->recordStates($runId); }

    public function scannedVersions($runId, array $recordIds)
    { $this->gate('scannedVersions'); return $this->inner->scannedVersions($runId, $recordIds); }

    public function progressState($runId)
    { $this->gate('progressState'); return $this->inner->progressState($runId); }

    public function setProgressState($runId, $epoch, array $state)
    { $this->gate('setProgressState'); return $this->inner->setProgressState($runId, $epoch, $state); }

    public function addAggregate($runId, $kind, $axis1, $axis2, $cnt, $blocks = 0, $samples = null)
    {
        $this->gate('addAggregate');
        return $this->inner->addAggregate($runId, $kind, $axis1, $axis2, $cnt, $blocks, $samples);
    }

    public function blockingAggregates($runId)
    { $this->gate('blockingAggregates'); return $this->inner->blockingAggregates($runId); }
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
