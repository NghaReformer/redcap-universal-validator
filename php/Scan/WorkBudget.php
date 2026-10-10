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
 * TWO MEMORY FIGURES, AND THEY ANSWER DIFFERENT QUESTIONS. PHP takes memory
 * from the system in 2 MiB chunks - the ARENA, memory_get_usage(true) - and
 * memory_limit is enforced against THAT: a request using 10 MB inside a 22 MB
 * arena is refused an allocation that would grow the arena past the limit, and
 * is not refused one that fits in chunks it already holds. So how much room is
 * left is an arena question, and mustStop() and the headroom in next() ask it
 * of the arena, the larger and therefore the safe figure.
 *
 * What one record COSTS is not an arena question, and asking it of the arena is
 * what this class used to do. The arena moves in 2 MiB steps, so a batch that
 * allocated 300 KB measured a cost of zero and one that happened to cross a
 * chunk boundary measured 2 MiB, whatever it actually did. A budget fed those
 * numbers predicts nothing. The per-unit cost is measured on REAL usage,
 * memory_get_usage(false), by mark() and observe().
 *
 * AND THE COST THAT MATTERS IS THE PEAK, NOT WHAT WAS LEFT BEHIND. A batch that
 * builds 40 MB of rows and frees them retains nothing and still needed 40 MB.
 * On PHP 8.2+ the peak is reset before each batch and read after it, which is
 * exact. Below 8.2 it cannot be reset, so it is a request-wide high-water mark:
 * it is believed only when the batch MOVED it, and the last figure measured
 * that way is kept as a floor for the rest of the request. Subtracting a stale
 * peak every time looks conservative and is not - the gap is a constant, so a
 * smaller batch divides it into a LARGER cost per record, which asks for a
 * smaller batch again, down to one record at a time and a false message saying
 * the records in the project are large.
 *
 * BUT A BATCH UNDER THE MARK STILL PROVES SOMETHING (review BU-3, BU-4). Its
 * peak cannot have been above the request's, so the gap up to the mark is an
 * UPPER bound on what it cost. Used as a cost, that bound is the spiral above.
 * Used as a limit on growth, it is exactly what was missing: a batch that
 * measured nothing used to double on the clock alone, and below a stale mark
 * of 100 MiB in a 128 MiB limit that went 25, 50, 100, 200, 400 records and
 * died on the first batch big enough to pass the mark. So a kind with no exact
 * figure yet may grow only as far as the gap proves fits, and otherwise holds;
 * a kind that has one (the floor) may relax it down to that bound, because the
 * bound is a fact about THIS batch and the floor is a fact about an earlier
 * one. Without that, one outlier record pinned the batch size for the rest of
 * the request. What a batch held at once, sampled between its units, is a
 * LOWER bound and may only cap it.
 *
 * ONE CLAIM PER KIND OF UNIT, NOT ONE PER REQUEST (review BU-1). A request
 * works records, duplicate groups, summary rows and change-log rows, and a
 * unit of one costs nothing like a unit of another. The floor was already kept
 * per kind; the claim was not. So a run of cheap batches - group pages of a
 * kilobyte each - doubled the one claim up to the maximum with nothing to say
 * about memory, and the next batch of an expensive kind was handed that number:
 * a verify page re-read 500 whole records and the request died at
 * memory_limit. Each kind now has its own claim and its own floor, each starts
 * at the first claim, and a kind's claim moves only on that kind's own
 * batches. A batch that re-reads records is a batch of records, whoever runs
 * it, and the caller labels it so.
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

    /**
     * The share of memory_limit one unit is assumed to need when the request
     * has measured nothing yet. See admit(). Deliberately generous: it is the
     * whole of the safety margin for the one unit let past the reserve.
     */
    const FIRST_UNIT = 0.10;

    /**
     * Fraction of memory_limit a batch in flight may grow to before it stops
     * taking on units. Half the reserve is left for committing what it holds.
     * See sample().
     */
    const IN_BATCH = 0.80;

    // Said the same way wherever they are said. Both describe a PAUSE, not a
    // failure: the request stops claiming and the next one carries on, and a
    // message that read like an error would send someone looking for a fault.
    const OUT_OF_TIME = 'this request reached the time it may spend, so the scan continues in '
                      . 'the next one';
    const OUT_OF_MEMORY = 'this request has used as much memory as it may, so the scan continues '
                        . 'in the next one';
    // Also a pause, and an honest one: the request did one unit and stopped,
    // and the next does the same. Said so the slowness has a reason attached.
    const ONE_AT_A_TIME = 'most of the memory this request may use was taken before the scan '
                        . 'began, so each request does one piece of work and the scan continues '
                        . 'in the next one';
    // NOT a pause, and deliberately not worded like one. Every request arrives
    // with the same footprint and would stop in the same place, so "continues
    // in the next one" here is a promise that is never kept.
    const NO_ROOM = 'this request had used nearly all of the memory PHP allows it before the '
                  . 'scan began, so it could not do any work. The scan has stopped rather than '
                  . 'retrying; raise memory_limit for this server and resume it';
    // A batch stopped part-way by sample(). Pauses, both of them: what was
    // examined is kept, and the rest is handed back for the next request.
    const CUT_SHORT = 'the records in this batch took more memory than expected, so it kept what '
                    . 'it had examined, handed the rest back, and the scan continues in the next '
                    . 'one';
    const SET_ASIDE = 'one record produced more than this request could hold beside what was '
                    . 'already in memory, so it was set aside to be tried again and the scan '
                    . 'continues in the next one; a record that keeps doing this is reported as '
                    . 'one that could not be examined';

    private $mode;
    private $min;
    private $max;
    private $memLimit;      // bytes, or null for "no limit"
    private $timeLimit;     // seconds, or null for "no limit"
    private $startedAt;
    private $first;         // the size a kind's first batch asks for
    private $claims = [];   // kind => the size that kind's next batch should ask for
    private $probe;         // ?callable(bool $resetPeak): {used, peak, arena}
    private $floors = [];   // kind => the last EXACT per-unit peak cost, in bytes
    private $observed = 0;  // batches this request has finished and been told about
    private $squeezed = false; // admit() let this request past the reserve for one unit
    private $batchTop = null;  // the highest real usage sampled since mark(), or null
    private $batchBase = 0;    // real usage before the first unit: what the units hold is above it
    private $batchLast = 0;    // real usage at the last sample, for the growth of one unit
    private $batchStep = 0;    // the most one unit of this batch has grown real usage by
    private $pinned = [];      // kind => the claim it keeps for the rest of the request; see alone()
    private $retryPass = false; // alone() owes the next admit() one unit, whatever it measures
    private $retried = false;  // ... and owes it once per request

    /**
     * @param array $opts {mode: 'browser'|'cron', min:int, max:int,
     *                     memoryLimit: string|int|null, timeLimit: string|int|null,
     *                     startedAt: ?float, first: ?int,
     *                     probe: ?callable(bool $resetPeak): array{used:int, peak:int, arena:int}}
     *        `probe` replaces the three PHP memory functions, so the measurement
     *        seam can be driven with known numbers instead of real allocations.
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
        $this->first = isset($opts['first'])
            ? max($this->min, min($this->max, (int) $opts['first']))
            : max($this->min, min($this->max, 25));
        $this->probe = (isset($opts['probe']) && is_callable($opts['probe'])) ? $opts['probe'] : null;
    }

    /**
     * The three memory figures, from the injected probe or from PHP.
     *
     * $resetPeak asks for the peak to be restarted first, which PHP can do from
     * 8.2. Where it cannot, the figure returned is the request-wide high-water
     * mark and observe() treats it accordingly.
     *
     * @return array{used:int, peak:int, arena:int}
     */
    private function read($resetPeak)
    {
        if ($this->probe !== null) {
            $r = call_user_func($this->probe, (bool) $resetPeak);
            $used = isset($r['used']) ? (int) $r['used'] : 0;
            return ['used' => $used,
                    'peak' => isset($r['peak']) ? max($used, (int) $r['peak']) : $used,
                    'arena' => isset($r['arena']) ? max($used, (int) $r['arena']) : $used];
        }
        if ($resetPeak && function_exists('memory_reset_peak_usage')) {
            call_user_func('memory_reset_peak_usage');
        }
        return ['used' => memory_get_usage(false), 'peak' => memory_get_peak_usage(false),
                'arena' => memory_get_usage(true)];
    }

    /**
     * Note where a batch starts, for observe() to measure from.
     *
     * $kind names what the batch is a batch OF - records, groups, rows. A cost
     * per record says nothing about the cost per duplicate group, so the claim
     * and the floor observe() keeps are both kept per kind. The kind is read
     * from the mark when the batch is observed, so a caller that learns only
     * afterwards what its batch was (a finalizer step that turned out to
     * re-read records) relabels the mark before observing it.
     *
     * @return array{at:float, used:int, peak:int, kind:string}
     */
    public function mark($kind = '', $now = null)
    {
        $m = $this->read(true);
        $this->batchTop = $m['used'];
        $this->batchBase = $m['used'];
        $this->batchLast = $m['used'];
        $this->batchStep = 0;
        return ['at' => $now === null ? microtime(true) : (float) $now,
                'used' => $m['used'], 'peak' => $m['peak'], 'kind' => (string) $kind];
    }

    /**
     * Note what a batch in flight holds now, and say whether it may go on.
     *
     * Between units is the only place the real usage of a batch in flight can
     * be seen, and below 8.2 it is the only in-batch figure there is: the peak
     * is request-wide, so a batch that stays under an earlier high-water mark
     * shows nothing at all by the time it is observed. The highest usage
     * sampled here is a LOWER bound on the batch's peak, so observe() lets it
     * cap the next batch and never lets it justify growth.
     *
     * THE BATCH IS SIZED BEFORE IT IS CLAIMED, AND THAT IS NOT ENOUGH (review
     * EE-1). The first batch of a request has no measurement behind it. 25
     * records that raise 4,000 findings each hold about 156 MB of findings
     * until the commit, and at memory_limit=128M the request died before the
     * budget had observed anything.
     *
     * AND THE COMMIT IS NOT FREE. What the units hold - their findings - is
     * written by the commit, and a store may hold a second copy of it while it
     * does: the in-memory store copies every finding it keeps. Leaving a fixed
     * share of the limit for the commit was measured against real memory and
     * died inside the commit. So the room kept is a copy of what the units
     * hold, measured from just before the first unit; the record data the
     * batch read is not counted, because the commit does not copy it.
     *
     * After each unit ($unitDone) this answers:
     *
     *   'drop'  the unit just finished cannot be kept: the arena plus a copy of
     *           everything the units hold, this one included, reaches the limit
     *           itself. The caller lets this unit's result go, which returns its
     *           share. When the unit was the only thing the batch had read, it
     *           counts an attempt against it, so a record that always does this
     *           ends in a terminal state instead of a loop. When other units'
     *           read data was held beside it, the arena is partly theirs, so it
     *           hands the unit back uncharged and retries it alone - see
     *           alone() (review R2S-3).
     *   'stop'  keep this unit and take on no more: one more unit growing like
     *           the largest so far, with a copy of it and of what is held,
     *           would reach the limit, or the arena would pass IN_BATCH of it.
     *           The caller commits what it examined and hands the rest back.
     *   null    go on.
     *
     * A sample that is not after a unit only notes what is held. The one after
     * the read also marks where the units start: the read is not a unit, and
     * its size says nothing about the next one.
     *
     * @return string|null 'drop' | 'stop' | null
     */
    public function sample($unitDone = true)
    {
        $m = $this->read(false);
        if ($this->batchTop === null) return null;          // no batch in flight
        if ($m['used'] > $this->batchTop) $this->batchTop = $m['used'];
        $step = max(0, $m['used'] - $this->batchLast);
        $this->batchLast = $m['used'];
        if (!$unitDone) {
            $this->batchBase = $m['used'];
            return null;
        }
        if ($this->memLimit === null) return null;
        if ($step > $this->batchStep) $this->batchStep = $step;
        $held = max(0, $m['used'] - $this->batchBase);
        if ($m['arena'] + $held >= $this->memLimit) return 'drop';
        if ($m['arena'] + $held + 2 * $this->batchStep >= $this->memLimit
                || $m['arena'] + $this->batchStep >= (int) ($this->memLimit * self::IN_BATCH)) {
            return 'stop';
        }
        return null;
    }

    /**
     * A unit of $kind was dropped while other units' read data was held: take
     * this kind one at a time for the rest of the request, and let the next
     * unit through whatever admit() would measure.
     *
     * WHY THE DROP WAS NOT THE UNIT'S FAULT (review R2S-3). sample() compares
     * the arena with the limit, and the arena holds the read data of every
     * record in the batch, not only the one just evaluated. Charging that one
     * an attempt charged it for its neighbours: the rest went back uncharged,
     * the cursor rewound, the next request rebuilt the same batch and charged
     * the same first record, and healthy records were excluded one after
     * another - one needing 57 MB of 128 MB was retired after three requests.
     * The caller now hands it back uncharged, frees the batch's read data, and
     * asks again for ONE record, so the next unit is examined alone and a drop
     * there is the record's own.
     *
     * THE RETRY IS LET THROUGH ONCE, AND NOT GATED. The request has already
     * evaluated this record with more held beside it and survived, so retrying
     * it alone needs less than that. Gating it on the reserve or the clock
     * would end a request that had just read a batch it could not keep; the
     * next request would start from the first claim again, read the same
     * batch, and drop the same record uncharged - a loop with no attempt
     * counted and no end. One pass per request, because after it the kind is
     * pinned at one and every later drop is a drop of a record alone.
     */
    public function alone($kind)
    {
        $kind = (string) $kind;
        $this->pinned[$kind] = 1;
        $this->claims[$kind] = 1;
        if (!$this->retried) {
            $this->retried = true;
            $this->retryPass = true;
        }
    }

    /**
     * The caller has let go of a batch's read data: have PHP say so.
     *
     * Freeing returns memory to the allocator at once - memory_get_usage()
     * drops - but an emptied 2 MiB chunk may be kept in PHP's cache rather
     * than given back, and a cached chunk still counts in the arena that the
     * limit and sample() are measured against. The retry alone() arranges
     * would then be measured as though the neighbours' data were still held,
     * and charged for it. gc_mem_caches() hands cached chunks back, so the
     * arena reads what is really free. A probe stands in for PHP's figures,
     * so with one there is nothing to ask.
     */
    public function reclaim()
    {
        if ($this->probe !== null) return;
        if (function_exists('gc_mem_caches')) gc_mem_caches();
    }

    /**
     * The batch that started at $mark has finished: size the next one.
     *
     * See the class docblock for why the peak is believed only when it moved,
     * and what a batch that did not move it still proves.
     *
     * @return array{claim:int, stop:?string, why:?string} as next() returns
     */
    public function observe(array $mark, $units, $now = null)
    {
        $m = $this->read(false);
        $units = max(0, (int) $units);
        $now = $now === null ? microtime(true) : (float) $now;
        $used0 = isset($mark['used']) ? (int) $mark['used'] : $m['used'];
        $peak0 = isset($mark['peak']) ? (int) $mark['peak'] : $m['peak'];
        $kind  = isset($mark['kind']) ? (string) $mark['kind'] : '';
        // What was held at once: the highest usage sampled between units, and
        // what is held now. Usage the batch reached is a peak it reached, so a
        // sample above the old mark means the mark moved.
        $held = max($m['used'], $this->batchTop === null ? 0 : $this->batchTop);
        $this->batchTop = null;
        $peak = max($m['peak'], $held);
        // THE FLOOR IS THIS KIND'S. It used to be one figure that any batch of
        // another kind wiped, so a records batch after a page of groups below
        // 8.2 had no floor at all and measured only what it retained.
        $floor = isset($this->floors[$kind]) ? $this->floors[$kind] : 0.0;
        $delta = 0;
        $atMost = null;
        if ($units > 0) {
            $low = max(0, $held - $used0) / $units;   // a lower bound on the cost per unit
            $gap = max(0, $peak - $used0) / $units;   // exact if the peak moved; else an upper bound
            if ($peak > $peak0) {
                // EXACT: restarted at mark() on 8.2+, or moved by this batch.
                $per = $floor = $this->floors[$kind] = $gap;
            } elseif ($floor > 0) {
                // AN EARLIER EXACT FIGURE, AND A BOUND ON THIS BATCH. The floor
                // relaxes to the bound when the bound is tighter (BU-4).
                $per = max($low, min($floor, $gap));
            } else {
                // NOTHING EXACT FOR THIS KIND YET. The lower bound may cap the
                // next batch; the upper bound limits how far it may grow (BU-3).
                $per = $low;
                $atMost = $gap;
            }
            $delta = (int) ceil($per * $units);
        }
        return $this->next([
            'kind' => $kind,
            'atMost' => $atMost,
            'records' => $units,
            'seconds' => max(0.0, $now - (isset($mark['at']) ? (float) $mark['at'] : $now)),
            'memoryDelta' => $delta,
            'usage' => $m['arena'],
        ]);
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

    /**
     * How many units the next batch of this kind should ask for.
     *
     * A kind with no batch behind it in this request asks for the first claim,
     * never for another kind's: what a page of group rows proved about memory
     * says nothing about a page of records.
     */
    public function claim($kind = '')
    {
        // The one unit admit() let past the reserve. One, of any kind, and
        // below the configured minimum on purpose - see admit().
        if ($this->squeezed && $this->observed === 0) return 1;
        $kind = (string) $kind;
        // Pinned by alone() for the rest of the request.
        if (isset($this->pinned[$kind])) return $this->pinned[$kind];
        return isset($this->claims[$kind]) ? $this->claims[$kind] : $this->first;
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
        // THE ARENA, which is what memory_limit is enforced against.
        $usage = $usage === null ? $this->read(false)['arena'] : (int) $usage;
        if ($now >= $this->deadline()) return 'time';
        if ($this->memLimit !== null && $usage >= (int) ($this->memLimit * (1.0 - self::RESERVE))) {
            return 'memory';
        }
        return null;
    }

    /**
     * May this request start another unit of work? Null when it may.
     *
     * Otherwise {stop: 'time'|'memory', why: string, resumable: bool}, and the
     * caller passes `why` on as it stands. `resumable` false means the next
     * request would stop in the same place, so the caller must not answer as
     * though the scan were carrying on.
     *
     * THE FIRST UNIT OF A REQUEST GETS THROUGH (review BU-2). mustStop() asks
     * whether the arena is past the reserve, and asked before the first claim
     * that is a question about the HOST: REDCap's bootstrap, the project's
     * metadata and the rules the module builds are all in the arena before the
     * scan does anything. Where that alone passed 60% of the limit, every
     * request stopped before claiming, every request said "continues in the
     * next one", and the run never moved until the abandoned-run sweep expired
     * it. next() has always said one record alone, never zero; this is the same
     * rule at the door. So when nothing has been done yet, one unit is let
     * through if the arena plus a generous guess at one unit (FIRST_UNIT of the
     * limit) is still under the limit itself - the reserve is spent on that
     * one unit, which is what it is for. claim() then answers 1 until the unit
     * has been observed, and the request stops after it.
     *
     * AND WHEN EVEN ONE UNIT WILL NOT FIT, IT SAYS SO. That is not a pause: the
     * next request would arrive with the same footprint. It is reported as a
     * stop that is not resumable, in words that name the setting to change.
     *
     * @return array{stop:string, why:string, resumable:bool}|null
     */
    public function admit($now = null, $usage = null)
    {
        // The retry alone() owes. See there for why it is not measured.
        if ($this->retryPass) {
            $this->retryPass = false;
            return null;
        }
        $now = $now === null ? microtime(true) : (float) $now;
        $usage = $usage === null ? $this->read(false)['arena'] : (int) $usage;
        $stop = $this->mustStop($now, $usage);
        if ($stop === null) return null;
        if ($stop === 'time') return ['stop' => 'time', 'why' => self::OUT_OF_TIME, 'resumable' => true];
        if ($this->observed > 0) {
            return ['stop' => 'memory', 'why' => $this->memoryWhy(), 'resumable' => true];
        }
        if ($usage + (int) ($this->memLimit * self::FIRST_UNIT) < $this->memLimit) {
            $this->squeezed = true;
            return null;
        }
        return ['stop' => 'memory', 'why' => self::NO_ROOM, 'resumable' => false];
    }

    /** The memory pause, said for the request it is: an ordinary one, or one let through for a unit. */
    private function memoryWhy()
    {
        return ($this->squeezed && $this->observed <= 1) ? self::ONE_AT_A_TIME : self::OUT_OF_MEMORY;
    }

    /**
     * Learn from the batch that just finished, and size the next one.
     *
     * Callers inside the scan go through mark()/observe(), which measure; this
     * is the arithmetic, kept public so it can be driven with stated numbers.
     *
     * @param array $obs {kind:string     what the batch was a batch of; '' when unsaid,
     *                    records:int, seconds:float,
     *                    memoryDelta:int  what the batch cost, in bytes of REAL usage,
     *                    usage:int        the ARENA now, which is what the limit caps,
     *                    atMost:?float    bytes per unit the batch is known NOT to have
     *                                     exceeded, when nothing exact was measured}
     * @return array{claim:int, stop:?string, why:?string}
     */
    public function next(array $obs)
    {
        $kind    = isset($obs['kind']) ? (string) $obs['kind'] : '';
        $current = $this->claim($kind);
        $this->observed++;
        $done    = isset($obs['records']) ? max(0, (int) $obs['records']) : 0;
        $seconds = isset($obs['seconds']) ? max(0.0, (float) $obs['seconds']) : 0.0;
        $memPer  = 0;
        if ($done > 0 && isset($obs['memoryDelta'])) {
            $memPer = max(0, (int) $obs['memoryDelta']) / $done;
        }
        $atMost = isset($obs['atMost']) ? max(0.0, (float) $obs['atMost']) : 0.0;
        $usage = isset($obs['usage']) ? (int) $obs['usage'] : $this->read(false)['arena'];
        $room = ($this->memLimit === null) ? null
              : (int) ($this->memLimit * (1.0 - self::RESERVE)) - $usage;

        $want = $current;
        if ($done > 0 && $seconds > 0.0) {
            $perRecord = $seconds / $done;
            $want = (int) floor($this->target() / $perRecord);
            if ($want < 1) $want = 1;
            // GROWTH IS CAPPED. A first batch that happened to hit a run of
            // empty records measures a per-record cost that no later batch will
            // repeat, and acting on it in one step is how a scan that had been
            // running comfortably suddenly asks for ten thousand records.
            $ceiling = (int) ceil($current * self::MAX_GROWTH);
            if ($want > $ceiling) $want = $ceiling;
        }
        $want = max($this->min, min($this->max, $want));
        // One at a time once alone() has said so, below the minimum on purpose.
        if (isset($this->pinned[$kind])) $want = $this->pinned[$kind];

        // NOTHING EXACT, ONLY A BOUND (review BU-3). Below 8.2 a batch that
        // stayed under the request's high-water mark measured only what it
        // kept, and the clock doubled it on that - into the one batch big
        // enough to pass the mark, which could need nearly twice the room
        // there was. Growth now goes only as far as the bound proves fits, and
        // otherwise the claim holds. Never below where it is: the bound is an
        // over-estimate, and shrinking on it is the spiral the class docblock
        // describes.
        if ($room !== null && $atMost > 0 && $want > $current) {
            $proven = $room > 0 ? (int) floor($room / $atMost) : 0;
            if ($want > $proven) $want = max($current, $proven);
        }

        // MEMORY HAS THE LAST WORD. Time only makes a batch slow; memory ends
        // the request without a report.
        $memoryCapped = false;
        if ($room !== null && $memPer > 0) {
            if ($room <= 0) {
                $this->claims[$kind] = $this->min;
                return ['claim' => $this->min, 'stop' => 'memory', 'why' => $this->memoryWhy()];
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

        $this->claims[$kind] = $want;
        $stop = $this->mustStop(null, $usage);
        if ($stop === 'time')   return ['claim' => $want, 'stop' => $stop, 'why' => self::OUT_OF_TIME];
        if ($stop === 'memory') return ['claim' => $want, 'stop' => $stop, 'why' => $this->memoryWhy()];
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
     *
     * THE NUMBER IS READ THE WAY PHP READS IT (review BU-6). This used to accept
     * plain decimal digits and nothing else, so every other spelling PHP
     * enforces came back null - "no limit" - and switched the memory guard off
     * on a server that had one. PHP 8.2+ reads 0x, 0o and 0b prefixes; 7.4 and
     * 8.x both read a leading 0 as octal (0256M is enforced as 174 MiB, so
     * reading it as 256 put the reserve line above the real limit); both accept
     * a leading '+'. A suffix may follow the digits after spaces, as PHP allows.
     * A prefix with no digits the base can hold is unreadable, and a figure too
     * large for an integer is not a limit anything can reach, so both are null.
     */
    public static function bytes($v)
    {
        if ($v === null || $v === false) return null;
        if (is_int($v)) return $v < 0 ? null : $v;
        $v = trim((string) $v);
        if ($v === '') return null;
        if (!preg_match('/^([+-]?)(0x[0-9a-f]+|0o[0-7]+|0b[01]+|0[0-7]*|[1-9][0-9]*)\s*([kmg]?)b?\z/i',
                        $v, $m)) {
            return null;
        }
        $digits = $m[2];
        $prefix = strtolower(substr($digits, 0, 2));
        if ($prefix === '0x')      $n = intval(substr($digits, 2), 16);
        elseif ($prefix === '0o')  $n = intval(substr($digits, 2), 8);
        elseif ($prefix === '0b')  $n = intval(substr($digits, 2), 2);
        elseif ($digits[0] === '0') $n = intval(substr($digits, 1), 8);
        else                       $n = intval($digits, 10);
        // intval() saturates rather than wrapping, so the top of the range is
        // where an overflow lands.
        if ($n === PHP_INT_MAX) return null;
        if ($m[1] === '-' && $n > 0) return null;      // -1: no limit
        $factor = 1;
        switch (strtolower($m[3])) {
            case 'g': $factor = 1024 * 1024 * 1024; break;
            case 'm': $factor = 1024 * 1024; break;
            case 'k': $factor = 1024; break;
        }
        if ($n > intdiv(PHP_INT_MAX, $factor)) return null;
        return $n * $factor;
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
