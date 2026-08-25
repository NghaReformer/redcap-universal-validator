<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * The database failed. That is a different answer from "you lost the race".
 *
 * THE INCIDENT THIS CLASS EXISTS FOR. SqlScanStore's four hot-path fences —
 * claim(), claimPending(), releaseClaims() and advancePhase() — each caught
 * every Throwable and returned the value that means "the fence refused you":
 * false, false, 0, false. A deadlock (1213) and a lock-wait timeout (1205) were
 * therefore reported to the worker in the same words as a cancelled run and a
 * moved epoch, and the worker did what those words tell it to do. Nothing
 * anywhere recorded that the DATABASE had failed. Five pilot rounds were spent
 * chasing causes that were never the cause, which is the same disease as
 * startRun() answering "a validation scan is already running for this project"
 * over a missing table.
 *
 * The masking was also worse than "the worker stops". A swallowed error inside
 * advancePhase() reaches ScanWorker as "there is no next phase", and the worker
 * reports the run DONE — a false complete written over a database fault, which
 * is the one outcome ScanStore's invariant list exists to make impossible.
 *
 * WHY A THROW RATHER THAN A FIFTH RETURN VALUE. Every one of those methods has
 * a return type whose whole meaning is a fence decision, and the contract
 * already spends two paragraphs on the difference between `[]` and `false`. A
 * third value in that vocabulary would be a third thing every caller has to
 * remember to test for, and the callers that forgot are how the first two got
 * conflated. An exception cannot be forgotten: it either reaches a handler that
 * knows what a storage failure means, or it ends the request loudly.
 *
 * TWO AUDIENCES, TWO STRINGS, AND THEY NEVER SWAP. `OPERATOR_TEXT` is a fixed
 * sentence with no table name, no column, no value and no error number, and it
 * is the only thing that reaches a page. `safeDetail()` is what the server
 * said, rebuilt by DbError from structural captures, and it goes to the module
 * log where an administrator can read it. Putting the server's text on the page
 * is how a batch of participant data reaches a browser through an error
 * message; putting nothing in the log is how the pilot lost five rounds.
 *
 * NOT RETRIED HERE, DELIBERATELY. A rolled-back batch is idempotent, so the
 * correct retry is the client's next scan-work request — which already exists
 * and is already paced. A retry loop inside the store would hold an HTTP
 * request open against a server that is failing, which is the opposite of what
 * a failing server needs.
 *
 * PHP 7.4.
 */
final class ScanStoreUnavailable extends \RuntimeException
{
    /**
     * The only sentence anyone outside this module reads.
     *
     * It says what happened, what was NOT lost, and what happens next, and it
     * names nothing. "Nothing it had already examined was lost" is a promise
     * the store actually keeps: every failure that arrives here rolled its
     * transaction back, and I3 means no record is marked done outside the
     * transaction that scanned it.
     */
    const OPERATOR_TEXT = 'this scan could not reach its own storage just now. Nothing it had '
        . 'already examined was lost, and it will continue when the database answers.';

    /** @var string what the server said, with the participant data taken out. */
    private $detail;

    public function __construct($detail, \Throwable $previous = null)
    {
        // The exception MESSAGE is deliberately the same fixed string for every
        // instance. An uncaught throw prints its message, and a stack trace on
        // a REDCap error page is not a place where the server's text should
        // arrive by accident.
        parent::__construct('scan storage unavailable', 0, $previous);
        $this->detail = (string) $detail;
    }

    /**
     * Wrap whatever the driver or the framework threw.
     *
     * DbError::safe() is the single redaction point in this module and this
     * method is why it is a class rather than somebody's private helper: three
     * call sites needed the same answer and historically invented three
     * different ones. Do not build the detail string any other way.
     */
    public static function from(\Throwable $e)
    {
        return new self(DbError::safe($e), $e);
    }

    /**
     * For the module log, and for nothing else.
     *
     * @return string never empty — DbError::safe() falls back to the class name
     */
    public function safeDetail()
    {
        return $this->detail;
    }
}
