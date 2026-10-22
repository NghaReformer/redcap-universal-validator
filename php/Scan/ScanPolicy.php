<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * Every limit and privacy setting the scan obeys, resolved once per run and
 * frozen into it.
 *
 * FROZEN, NOT READ REPEATEDLY. A run stores the policy it started under, so a
 * setting changed halfway through cannot make the first half of a scan mean
 * something different from the second. The one exception is a change toward
 * LESS disclosure, which takes effect immediately by bumping the run's policy
 * revision — that invalidates worker and export leases and blocks preview reads
 * before the purge has even started. Tightening is urgent; loosening can wait
 * for the next run.
 *
 * EFFECTIVE = min(system maximum, valid project value). REDCap 13.7 cannot be
 * assumed to support native system settings with project overrides, so the two
 * are separate keys resolved here rather than by the framework. A project can
 * always ask for LESS than the system allows and never more.
 *
 * UNKNOWN OR MALFORMED FAILS TOWARD: less disclosure, less concurrency, and no
 * clean certification. Every default below is chosen in that direction, and the
 * parser returns the safe value rather than throwing, because a settings read
 * that throws must not be able to stop a scan from being cautious.
 */
final class ScanPolicy
{
    /** Defaults, matching the plan's §9 table. */
    const D_VALUE_MODE      = 'locations';
    const D_VALUE_DAYS      = 30;
    const D_RUN_DAYS        = 90;
    /** collectionGaps, while no gap detector exists. */
    const GAPS_UNIMPLEMENTED = 'unimplemented';
    const D_MAX_FINDINGS    = 1000000;
    const D_MAX_BYTES       = 536870912;      // 512 MiB
    const D_MAX_PROJECTS    = 2;
    const D_STALE_HOURS     = 24;
    const D_RECORD_ATTEMPTS = 3;
    /**
     * The most attempts a record may be given, because it is the most the store
     * can count.
     *
     * scan_record.attempts is a TINYINT UNSIGNED and both stores saturate it at
     * 254 (LEAST(attempts + 1, 254)). ScanWorker ends a record when
     * `$tries + 1 >= $maxAttempts`, so with the setting at 256 or more that is
     * never true: a record the read keeps leaving out is requeued for ever,
     * never reaches UNREADABLE or TOMBSTONE, and every pass reports
     * `requeued > 0` - which js/scan.js reads as progress and re-issues at 0 ms.
     * The setting had no upper bound. 254 rather than 255, which would also be
     * reached, so the cap sits where the counter visibly stops and does not
     * depend on the worker's comparison being off by exactly one.
     */
    const MAX_RECORD_ATTEMPTS = 254;

    /**
     * The most projects the installation may scan at once (wave 8 review
     * R8-4), because it is the number of worker-slot rows a settings save
     * writes.
     *
     * scan-system-max-concurrent-projects is free text and had no upper bound.
     * WorkerSlots::provision() turns it into one row per slot: above 65535 the
     * insert needed more placeholders than a prepared statement may carry, so
     * it failed and no slot was written - every worker was then refused as if
     * the server were busy - and near 1e9 range() exhausted memory, a fatal
     * error no catch around the settings save can stop. slot_no is a
     * SMALLINT UNSIGNED, so the column's own ceiling is 65535; a server that
     * could run a thousand scans at once is not one this rations. The ceiling
     * is said in the setting's text.
     */
    const MAX_PROJECTS = 1000;

    /** Value modes, least disclosing first. Order is the safety ordering. */
    private static $modes = ['locations', 'identifier-redacted', 'raw'];

    /**
     * @param array $system system-wide maxima (may be empty)
     * @param array $projct per-project requests (may be empty)
     * @return array the effective policy, safe to freeze into a run
     */
    public static function resolve(array $system = [], array $projct = [])
    {
        $mode = self::mode(isset($projct['scan-value-storage']) ? $projct['scan-value-storage'] : null);

        return [
            'valueMode'      => $mode,
            // A project may shorten retention, never lengthen it.
            'valueDays'      => self::bounded($projct, 'scan-value-retention-days',
                                              $system, 'scan-system-max-value-retention-days',
                                              self::D_VALUE_DAYS),
            'runDays'        => self::bounded($projct, 'scan-run-retention-days',
                                              $system, 'scan-system-max-run-retention-days',
                                              self::D_RUN_DAYS),
            'maxFindings'    => self::bounded($projct, 'scan-max-detail-findings',
                                              $system, 'scan-system-max-detail-findings',
                                              self::D_MAX_FINDINGS),
            'maxBytes'       => self::bounded($projct, 'scan-max-detail-bytes',
                                              $system, 'scan-system-max-detail-bytes',
                                              self::D_MAX_BYTES),
            // System-only: a project cannot grant itself more of the server.
            // Clamped, not merely parsed - see MAX_PROJECTS.
            'maxProjects'    => min(self::MAX_PROJECTS,
                                self::posInt(isset($system['scan-system-max-concurrent-projects'])
                                ? $system['scan-system-max-concurrent-projects'] : null, self::D_MAX_PROJECTS)),
            'staleHours'     => self::posInt(isset($system['scan-system-stale-run-hours'])
                                ? $system['scan-system-stale-run-hours'] : null, self::D_STALE_HOURS),
            // Clamped, not merely parsed - see MAX_RECORD_ATTEMPTS. posInt()
            // already turns zero, negatives and junk into the default, so the
            // floor of 1 is its; the ceiling is the counter's.
            'recordAttempts' => min(self::MAX_RECORD_ATTEMPTS,
                                self::posInt(isset($system['scan-system-record-attempts'])
                                ? $system['scan-system-record-attempts'] : null, self::D_RECORD_ATTEMPTS)),
            // WHAT IS TRUE, which is that nothing detects a collection gap. This
            // said 'separate' - gaps reported apart from violations - for as long
            // as the file has existed, and no gap detector has ever shipped: the
            // aggregate kind they would be counted under has no writer. A policy
            // that names a behaviour is read as a promise of it, by a reader and
            // by the next person to touch the report, so it names the absence
            // until there is something to name. When a detector lands, 'separate'
            // is still the only value it may take: a project that turns gaps back
            // into violations re-creates the 95%-noise report the rebuild removed.
            //
            // NOT A FINGERPRINT INPUT. See ScanPlanner::GAP_POLICY_WIRE.
            'collectionGaps' => self::GAPS_UNIMPLEMENTED,
        ];
    }

    /**
     * How disclosing this mode is; higher shows more. UNKNOWN RANKS LOWEST, so
     * anything unrecognised is treated as the least disclosing option.
     */
    public static function rank($mode)
    {
        $i = array_search((string) $mode, self::$modes, true);
        return $i === false ? 0 : $i;
    }

    /** The less disclosing of two modes. Used to cap a project by a reader. */
    public static function floor($a, $b)
    {
        return self::rank($a) <= self::rank($b) ? self::mode($a) : self::mode($b);
    }

    /**
     * Did the policy change in a direction that must take effect IMMEDIATELY?
     *
     * Any reduction in disclosure or retention, and any switch to hashed record
     * presentation. Returns true when the new policy is stricter in any respect,
     * because a reader must never keep a preview the project has just decided
     * they should not have.
     */
    public static function tightened(array $old, array $new)
    {
        if (self::rank(self::get($new, 'valueMode')) < self::rank(self::get($old, 'valueMode'))) return true;
        foreach (['valueDays', 'runDays', 'maxFindings', 'maxBytes'] as $k) {
            $o = (int) self::get($old, $k, PHP_INT_MAX);
            $n = (int) self::get($new, $k, PHP_INT_MAX);
            if ($n < $o) return true;
        }
        if (empty($old['hashRecordIds']) && !empty($new['hashRecordIds'])) return true;
        return false;
    }

    /**
     * When a value preview stored now must stop being readable, in UTC.
     *
     * UTC because the purge and the report compare it with
     * ScanRetention::now() and the mapper's `now`, both gmdate() (W7-R6).
     * date() wrote the server's zone: east of Greenwich a preview stayed
     * readable hours past its window, west of it it expired hours early.
     */
    public static function valueExpiry(array $policy, $now)
    {
        $days = (int) self::get($policy, 'valueDays', self::D_VALUE_DAYS);
        return gmdate('Y-m-d H:i:s', ((int) $now) + ($days * 86400));
    }

    /** Has the detail budget been spent? Either limit alone is enough. */
    public static function budgetSpent(array $policy, $rows, $bytes)
    {
        return ((int) $rows) >= (int) self::get($policy, 'maxFindings', self::D_MAX_FINDINGS)
            || ((int) $bytes) >= (int) self::get($policy, 'maxBytes', self::D_MAX_BYTES);
    }

    // -- parsing, all of it failing toward the safe answer -------------------

    private static function mode($raw)
    {
        return in_array((string) $raw, self::$modes, true) ? (string) $raw : self::D_VALUE_MODE;
    }

    /**
     * min(system max, project request), with the DEFAULT used whenever either is
     * unusable. A project asking for more than the system allows silently gets
     * the system's answer rather than an error, because the alternative is a
     * scan that refuses to run over a number nobody looks at.
     */
    private static function bounded(array $p, $pk, array $s, $sk, $default)
    {
        $max = self::posInt(isset($s[$sk]) ? $s[$sk] : null, $default);
        $req = self::posInt(isset($p[$pk]) ? $p[$pk] : null, $max);
        return $req < $max ? $req : $max;
    }

    /** A positive integer, or the fallback. Zero and negatives are malformed. */
    private static function posInt($raw, $fallback)
    {
        if (is_int($raw) && $raw > 0) return $raw;
        if (is_string($raw) && preg_match('/^\d+$/', trim($raw))) {
            $v = (int) trim($raw);
            if ($v > 0) return $v;
        }
        return (int) $fallback;
    }

    private static function get(array $a, $k, $default = null)
    {
        return array_key_exists($k, $a) ? $a[$k] : $default;
    }
}
