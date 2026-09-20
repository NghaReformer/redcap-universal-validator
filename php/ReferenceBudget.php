<?php
namespace INSPIRE\UniversalValidator;

/** Shared across host contexts so repeated collection scans cannot grow quadratically without a bound. */
final class ReferenceBudget
{
    private $remaining;
    private $refused = false;

    public function __construct($units = 100000)
    {
        $this->remaining = max(0, (int) $units);
    }

    public function take($units = 1)
    {
        $units = max(1, (int) $units);
        if ($units > $this->remaining) {
            $this->remaining = 0;
            $this->refused = true;
            return false;
        }
        $this->remaining -= $units;
        return true;
    }

    /** True once a request has been REFUSED. Spending the last unit exactly left nothing unchecked. */
    public function exhausted()
    {
        return $this->refused;
    }
}
