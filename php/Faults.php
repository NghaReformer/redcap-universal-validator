<?php

namespace INSPIRE\UniversalValidator;

/**
 * The exceptions the module catches and carries on from (QUA-5).
 *
 * Many paths fail open on purpose: rights that cannot be read grant nothing, a
 * log write that fails is given up, a probe that throws reports the capability
 * as absent. Those catch blocks were empty, so a fault on one of them left no
 * trace, and a fallback taken on every request (the filterLogic read of PRF-2)
 * looked the same as one never taken. Each now hands its exception here.
 *
 * Only the place and the exception's class are kept, never its message: a
 * message from the framework or the database can quote record values or SQL.
 * A request keeps at most MAX_PLACES distinct places, each with a count, and
 * counts the rest as dropped. UniversalValidator::reportFaults() writes them
 * to the module log as one "uvalidate-faults" entry at the end of a request,
 * only when the project's debug setting is on.
 */
final class Faults
{
    /** The most distinct place|class pairs one request keeps. */
    const MAX_PLACES = 50;

    /** @var array<string,int> "place|class" => count */
    private static $seen = [];

    /** @var int faults past MAX_PLACES */
    private static $dropped = 0;

    /**
     * Count one caught exception at $place ("Class::method").
     *
     * @param \Throwable $e
     * @param string     $place
     */
    public static function swallow(\Throwable $e, $place)
    {
        $k = (string) $place . '|' . get_class($e);
        if (isset(self::$seen[$k])) {
            self::$seen[$k]++;
            return;
        }
        if (count(self::$seen) >= self::MAX_PLACES) {
            self::$dropped++;
            return;
        }
        self::$seen[$k] = 1;
    }

    /**
     * What this request has counted, without clearing it.
     *
     * @return array{places: array<string,int>, dropped: int}
     */
    public static function peek()
    {
        return ['places' => self::$seen, 'dropped' => self::$dropped];
    }

    /**
     * What this request has counted, and start again from nothing.
     *
     * @return array{places: array<string,int>, dropped: int}
     */
    public static function take()
    {
        $out = self::peek();
        self::$seen = [];
        self::$dropped = 0;
        return $out;
    }
}
