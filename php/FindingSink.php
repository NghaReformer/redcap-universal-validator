<?php

namespace INSPIRE\UniversalValidator;

/**
 * Where a scan's violations go as it finds them.
 *
 * Until 1.7.0 scanProject() appended every violation to one array and returned
 * it whole. That array is the scan's dominant cost — measured at ~440 bytes a
 * row, and a live project produces ~49 rows per record — so it grows with the
 * project and nothing ever flushes it. On a large project the scan therefore
 * dies of memory exhaustion, which is an uncatchable fatal: the process stops
 * before the return, the page renders nothing, and nothing anywhere records
 * that the project was not examined.
 *
 * The sink is the seam that lets a caller consume findings as they are produced
 * instead. It is deliberately narrow — one method — because violations are the
 * only channel that scales with the DATA. Rule problems are bounded by the rule
 * list, and 'incomplete' notes by the number of unreadable records, so both stay
 * on the returned result where every existing caller and test already reads
 * them.
 *
 * @see tests/support/scan_facade.php  the legacy one-request scan, which uses
 *      ArrayFindingSink (moved there with it, QUA-3)
 */
interface FindingSink
{
    /**
     * One violation, in the shape scanProject has always returned:
     * ['record','event_id','instance','field','type','reason','rule'].
     *
     * Called once per finding, in scan order, except for duplicate-value
     * findings — uniqueness needs the whole project, so those arrive together
     * after the last record.
     */
    public function violation(array $v);

    // count() stood here for the legacy one-request scan, which reported how
    // many violations it had handed over. That scan and the sinks that count
    // moved to tests/support/scan_facade.php (QUA-3), and nothing in the
    // module asked a sink for a count.
}

/**
 * Hands each violation to a callable as it is found, keeping nothing.
 *
 * The streaming case: a caller that writes rows straight to php://output holds
 * one row at a time regardless of how many the project produces.
 */
final class CallbackFindingSink implements FindingSink
{
    /** @var callable */
    private $fn;

    public function __construct(callable $fn)
    {
        $this->fn = $fn;
    }

    public function violation(array $v)
    {
        call_user_func($this->fn, $v);
    }
}
