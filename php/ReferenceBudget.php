<?php
namespace INSPIRE\UniversalValidator;

/** Shared across host contexts so repeated collection scans cannot grow quadratically without a bound. */
final class ReferenceBudget
{
    private $remaining;

    public function __construct($units = 100000)
    {
        $this->remaining = max(0, (int) $units);
    }

    public function take($units = 1)
    {
        $units = max(1, (int) $units);
        if ($units > $this->remaining) {
            $this->remaining = 0;
            return false;
        }
        $this->remaining -= $units;
        return true;
    }

    public function exhausted()
    {
        return $this->remaining === 0;
    }
}
