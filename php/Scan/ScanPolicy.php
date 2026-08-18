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
    const D_MAX_FINDINGS    = 1000000;
    const D_MAX_BYTES       = 536870912;      // 512 MiB
    const D_MAX_PROJECTS    = 2;
    const D_STALE_HOURS     = 24;
    const D_RECORD_ATTEMPTS = 3;

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
            'maxProjects'    => self::posInt(isset($system['scan-system-max-concurrent-projects'])
                                ? $system['scan-system-max-concurrent-projects'] : null, self::D_MAX_PROJECTS),
            'staleHours'     => self::posInt(isset($system['scan-system-stale-run-hours'])
                                ? $system['scan-system-stale-run-hours'] : null, self::D_STALE_HOURS),
            'recordAttempts' => self::posInt(isset($system['scan-system-record-attempts'])
                                ? $system['scan-system-record-attempts'] : null, self::D_RECORD_ATTEMPTS),
            // Fixed for this release. The plan states it as policy rather than a
            // setting precisely so it cannot be switched off: a project that
            // turns collection gaps back into violations re-creates the 95%-noise
            // report the rebuild exists to remove.
            'collectionGaps' => 'separate',
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

    /** When a value preview stored now must stop being readable. */
    public static function valueExpiry(array $policy, $now)
    {
        $days = (int) self::get($policy, 'valueDays', self::D_VALUE_DAYS);
        return date('Y-m-d H:i:s', ((int) $now) + ($days * 86400));
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
