<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * How much work one request may take on, and when it must stop.
 *
 * THE FAILURE THIS REPLACES. The legacy scan had no execution-time guard and no
 * memory guard anywhere — searching the repository for `memory_limit`,
 * `memory_get_usage`, `set_time_limit` or `max_execution_time` returned nothing.
 * A project large enough to exhaust either produced a blank page: an uncatchable
 * fatal, no report, no partial result, and nothing in the module able to say
 * what happened. That is the one failure mode a module built on "nothing fails
 * silently" cannot report, because the process that would report it is gone.
 *
 * SO THE BUDGET IS PREDICTIVE, NOT REACTIVE. Noticing that memory is nearly
 * exhausted is too late; the allocation that finishes the job is the one that
 * kills it. Every decision here is made BEFORE a batch is claimed, from what the
 * previous batch actually cost, and it refuses a batch whose predicted peak
 * would not leave a substantial part of the limit unused.
 *
 * WHAT THE RESERVE IS FOR. Forty per cent sounds generous until you count what
 * is not in the measurement: REDCap's own request state, the framework, the
 * database driver's result buffers, and whatever PHP's allocator has fragmented
 * and cannot reuse. The measured per-record cost is the marginal cost of OUR
 * work, and the reserve is everything else.
 *
 * TWO TARGETS, NOT ONE. A browser request is a person watching a progress bar,
 * so about three seconds of work keeps the bar moving and the tab responsive. A
 * cron invocation has nobody watching and a much longer limit, so about twenty
 * seconds amortises the per-batch fixed cost - re-reading the dictionary,
 * rebuilding the rules - over more records.
 *
 * A SHUTDOWN HANDLER IS NOT A MEMORY GUARD. PHP can run one after an OOM, and it
 * can improve the diagnostic, but the allocation has already failed and the
 * batch is already lost. It is worth having and it is not what keeps this
 * correct.
 *
 * PHP 7.4.
 */
final class WorkBudget
{
    /** Seconds of work a browser batch aims at: enough to progress, short enough to stay live. */
    const BROWSER_TARGET = 3.0;
    /** Seconds a cron batch aims at: nobody is watching, so amortise the fixed cost. */
    const CRON_TARGET = 20.0;

    /** Fraction of memory_limit that must remain unused after the predicted peak. */
    const RESERVE = 0.40;

    /** Fraction of max_execution_time this may spend, leaving the rest to finish and commit. */
    const TIME_SHARE = 0.80;

    /** A batch may at most double, whatever the arithmetic says. See next(). */
    const MAX_GROWTH = 2.0;

    // Said the same way wherever they are said. Both describe a PAUSE, not a
    // failure: the request stops claiming and the next one carries on, and a
    // message that read like an error would send someone looking for a fault.
    const OUT_OF_TIME = 'this request reached the time it may spend, so the scan continues in '
                      . 'the next one';
    const OUT_OF_MEMORY = 'this request has used as much memory as it may, so the scan continues '
                        . 'in the next one';

    private $mode;
    private $min;
    private $max;
    private $memLimit;      // bytes, or null for "no limit"
    private $timeLimit;     // seconds, or null for "no limit"
    private $startedAt;
    private $claim;         // the size the next batch should ask for

    /**
     * @param array $opts {mode: 'browser'|'cron', min:int, max:int,
     *                     memoryLimit: string|int|null, timeLimit: string|int|null,
     *                     startedAt: ?float, first: ?int}
     */
    public function __construct(array $opts = [])
    {
        $this->mode = (isset($opts['mode']) && $opts['mode'] === 'cron') ? 'cron' : 'browser';
        $this->min  = isset($opts['min']) ? max(1, (int) $opts['min']) : 1;
        $this->max  = isset($opts['max']) ? max($this->min, (int) $opts['max']) : 500;
        $this->memLimit = array_key_exists('memoryLimit', $opts)
            ? self::bytes($opts['memoryLimit']) : self::bytes(ini_get('memory_limit'));
        $this->timeLimit = array_key_exists('timeLimit', $opts)
            ? self::seconds($opts['timeLimit']) : self::seconds(ini_get('max_execution_time'));
        $this->startedAt = isset($opts['startedAt']) ? (float) $opts['startedAt'] : microtime(true);
        // START SMALL. The first batch of a run has no measurement behind it, so
        // its size is a guess, and a guess that is too large is an OOM while a
        // guess that is too small costs one extra round trip.
        $this->claim = isset($opts['first'])
            ? max($this->min, min($this->max, (int) $opts['first']))
            : max($this->min, min($this->max, 25));
    }

    /** Seconds of work this kind of request aims at. */
    public function target()
    {
        $t = ($this->mode === 'cron') ? self::CRON_TARGET : self::BROWSER_TARGET;
        if ($this->timeLimit !== null) {
            // Never aim past the share of the limit we are allowed to spend: the
            // remainder is what commits the batch, and a batch that ran out of
            // time before committing did its work for nothing.
            $t = min($t, max(0.5, $this->timeLimit * self::TIME_SHARE));
        }
        return $t;
    }

    /** How many records the next batch should ask for. */
    public function claim()
    {
        return $this->claim;
    }

    /**
     * The moment this request must stop asking for more work.
     *
     * Not the moment it must stop working: a batch already in flight finishes
     * and commits, because abandoning it would discard records already examined
     * and I3 says a record is only done if the transaction that scanned it says
     * so. This is the deadline for STARTING something new.
     */
    public function deadline()
    {
        return $this->startedAt + $this->target();
    }

    /**
     * 'time' | 'memory' | null — must this request stop before claiming again?
     *
     * A null limit never halts. That is declining to guess rather than being
     * permissive: a guard that fires because it could not read a limit would
     * stop healthy scans and report them incomplete, which is the same class of
     * mistake as a failed read judged as a blank.
     */
    public function mustStop($now = null, $usage = null)
    {
        $now = $now === null ? microtime(true) : (float) $now;
        $usage = $usage === null ? memory_get_usage(true) : (int) $usage;
        if ($now >= $this->deadline()) return 'time';
        if ($this->memLimit !== null && $usage >= (int) ($this->memLimit * (1.0 - self::RESERVE))) {
            return 'memory';
        }
        return null;
    }

    /**
     * Learn from the batch that just finished, and size the next one.
     *
     * @param array $obs {records:int, seconds:float, memoryDelta:int, usage:int}
     * @return array{claim:int, stop:?string, why:?string}
     */
    public function next(array $obs)
    {
        $done    = isset($obs['records']) ? max(0, (int) $obs['records']) : 0;
        $seconds = isset($obs['seconds']) ? max(0.0, (float) $obs['seconds']) : 0.0;
        $memPer  = 0;
        if ($done > 0 && isset($obs['memoryDelta'])) {
            $memPer = max(0, (int) $obs['memoryDelta']) / $done;
        }
        $usage = isset($obs['usage']) ? (int) $obs['usage'] : memory_get_usage(true);

        $want = $this->claim;
        if ($done > 0 && $seconds > 0.0) {
            $perRecord = $seconds / $done;
            $want = (int) floor($this->target() / $perRecord);
            if ($want < 1) $want = 1;
            // GROWTH IS CAPPED. A first batch that happened to hit a run of
            // empty records measures a per-record cost that no later batch will
            // repeat, and acting on it in one step is how a scan that had been
            // running comfortably suddenly asks for ten thousand records.
            $ceiling = (int) ceil($this->claim * self::MAX_GROWTH);
            if ($want > $ceiling) $want = $ceiling;
        }
        $want = max($this->min, min($this->max, $want));

        // MEMORY HAS THE LAST WORD. Time only makes a batch slow; memory ends
        // the request without a report.
        $memoryCapped = false;
        if ($this->memLimit !== null && $memPer > 0) {
            $room = (int) ($this->memLimit * (1.0 - self::RESERVE)) - $usage;
            if ($room <= 0) {
                $this->claim = $this->min;
                return ['claim' => $this->claim, 'stop' => 'memory', 'why' => self::OUT_OF_MEMORY];
            }
            $fits = (int) floor($room / $memPer);
            if ($fits < $want) {
                // ONE RECORD ALONE when nothing else fits - never zero. A record
                // too large to examine beside others may still be examinable by
                // itself, and excluding it without trying would record a guess
                // as a fact. The configured minimum is overridden here on
                // purpose: a floor that forces four records into memory that
                // holds one is a floor that ends the request.
                $want = max(1, $fits);
                $memoryCapped = true;
            }
        }

        $this->claim = $want;
        $stop = $this->mustStop(null, $usage);
        if ($stop === 'time')   return ['claim' => $want, 'stop' => $stop, 'why' => self::OUT_OF_TIME];
        if ($stop === 'memory') return ['claim' => $want, 'stop' => $stop, 'why' => self::OUT_OF_MEMORY];
        if ($memoryCapped) {
            return ['claim' => $want, 'stop' => null,
                    'why' => $want === 1
                        ? 'the records in this project are large enough that they are being '
                          . 'examined one at a time'
                        : 'the size of the records here, rather than the clock, is setting '
                          . 'how much each request takes on'];
        }
        return ['claim' => $want, 'stop' => null, 'why' => null];
    }

    // -- ini parsing --------------------------------------------------------

    /**
     * A `memory_limit`-style value in bytes, or null when there is no limit.
     *
     * `-1` means no limit and MUST NOT become a very small number - a limit of
     * minus one byte would refuse every batch and the scan would never start. An
     * unreadable value is also null: declining to guess, for the reason in
     * mustStop().
     */
    public static function bytes($v)
    {
        if ($v === null || $v === false) return null;
        if (is_int($v)) return $v < 0 ? null : $v;
        $v = trim((string) $v);
        if ($v === '') return null;
        if (!preg_match('/^(-?\d+)\s*([kmg]?)b?\z/i', $v, $m)) return null;
        $n = (int) $m[1];
        if ($n < 0) return null;      // -1: no limit
        switch (strtolower($m[2])) {
            case 'g': return $n * 1024 * 1024 * 1024;
            case 'm': return $n * 1024 * 1024;
            case 'k': return $n * 1024;
        }
        return $n;
    }

    /**
     * A `max_execution_time`-style value in seconds, or null for no limit.
     *
     * Zero means unlimited in PHP, which is the opposite of what a naive read
     * gives: a zero-second budget would stop the scan before it started.
     */
    public static function seconds($v)
    {
        if ($v === null || $v === false) return null;
        $v = is_int($v) ? $v : trim((string) $v);
        if ($v === '') return null;
        if (!is_int($v) && !preg_match('/^-?\d+\z/', $v)) return null;
        $n = (int) $v;
        if ($n <= 0) return null;
        return $n;
    }
}
