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
 * @see UniversalValidator::scanProject()  the façade, which uses ArrayFindingSink
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

    /**
     * How many violations have been handed over.
     *
     * On the interface rather than left to each implementation, so a caller can
     * always report a count even when the rows themselves were not kept — "no
     * violations" must never be inferred from an array that was never filled.
     */
    public function count();
}

/**
 * Collects violations in memory, reproducing exactly what scanProject returned
 * before the sink existed.
 *
 * This is the default, and it is what every existing test drives. Its continued
 * agreement with a streaming sink is the regression proof that the extraction
 * changed nothing — see the S-01 differential section in tests/hosting_php.php,
 * which runs every scenario through both and compares.
 */
final class ArrayFindingSink implements FindingSink
{
    /** @var array[] */
    public $violations = [];

    public function violation(array $v)
    {
        $this->violations[] = $v;
    }

    public function count()
    {
        return count($this->violations);
    }
}

/**
 * Counts violations and keeps none.
 *
 * The cheapest way to answer "how many would this project produce" without
 * paying for the answer — which is what sizing a report, or measuring a project
 * before deciding how to store its findings, actually needs.
 */
final class CountingFindingSink implements FindingSink
{
    /** @var int */
    private $n = 0;

    public function violation(array $v)
    {
        $this->n++;
    }

    public function count()
    {
        return $this->n;
    }
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
    /** @var int */
    private $n = 0;

    public function __construct(callable $fn)
    {
        $this->fn = $fn;
    }

    public function violation(array $v)
    {
        $this->n++;
        call_user_func($this->fn, $v);
    }

    public function count()
    {
        return $this->n;
    }
}
