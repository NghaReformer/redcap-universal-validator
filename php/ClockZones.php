<?php
/**
 * ClockZones - the time zone names the "window-timezone" setting accepts, and
 * the name each one is opened under on this server.
 *
 * PHP knows the zones of the time zone database it reads. A PHP built with its
 * own copy knows every IANA name, the backward-compatible ones (Asia/Calcutta,
 * US/Eastern, Europe/Kiev) included. The Debian and Ubuntu packages of PHP read
 * the system's copy instead, and Debian 13 and Ubuntu 26.04 put the
 * backward-compatible names in a separate package, tzdata-legacy. Without it,
 * a project that saved one of those names lost its time zone without a word:
 * today became the server's date, and the next save of the settings refused
 * the name (WIN-4 again, found when CI moved to Ubuntu 26.04).
 *
 * php/timezone_links.json maps each backward-compatible name to the zone it
 * names now (tools/build_timezone_links.php writes it from the IANA links). A
 * name PHP knows is used as it is. Otherwise the zone it names now is tried,
 * then the other names of that zone, so Europe/Kyiv also works on a server
 * whose database predates that name and knows only Europe/Kiev.
 *
 * Pure static class with no REDCap dependency; tests/clock_zones_php.php pins it.
 */

namespace INSPIRE\UniversalValidator;

require_once __DIR__ . '/Faults.php';

final class ClockZones
{
    /** @var array|null the names PHP knows, name => true */
    private static $known = null;
    /** @var array|null the links file: old name => the zone it names now */
    private static $links = null;
    /** @var array|null zone => its other names, built on first need */
    private static $aliases = null;
    /** @var string|null test override for the links file */
    private static $path = null;

    /** Point the class at another links file (tests only). null restores the default. */
    public static function useFile($path)
    {
        self::$path = $path;
        self::$links = null;
        self::$aliases = null;
    }

    /**
     * Act as if PHP knew only these names (tests only: a server without
     * tzdata-legacy). null restores the names PHP knows.
     */
    public static function useKnown($names)
    {
        self::$known = is_array($names) ? array_fill_keys($names, true) : null;
    }

    /**
     * The name to open $name's zone with on this server: $name itself when PHP
     * knows it, else the zone it names now, else another name of that zone that
     * PHP knows. null for a name that is no zone here, or not a string.
     */
    public static function resolve($name)
    {
        if (!is_string($name) || $name === '') return null;
        if (self::$known === null) {
            self::$known = array_fill_keys(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true);
        }
        if (isset(self::$known[$name])) return $name;
        $links = self::links();
        $zone = isset($links[$name]) ? $links[$name] : $name;
        if (isset(self::$known[$zone])) return $zone;
        if (self::$aliases === null) {
            self::$aliases = [];
            foreach ($links as $old => $now) self::$aliases[$now][] = $old;
        }
        if (isset(self::$aliases[$zone])) {
            foreach (self::$aliases[$zone] as $other) {
                if (isset(self::$known[$other])) return $other;
            }
        }
        return null;
    }

    /**
     * The links file, read on the first name PHP does not know. A file that
     * cannot be read leaves the map empty: names PHP knows still work, and the
     * fault is counted (Faults).
     */
    private static function links()
    {
        if (self::$links !== null) return self::$links;
        $file = self::$path !== null ? self::$path : __DIR__ . '/timezone_links.json';
        $raw = @file_get_contents($file);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        $links = [];
        if (is_array($j) && isset($j['links']) && is_array($j['links'])) {
            foreach ($j['links'] as $old => $now) {
                if (is_string($old) && is_string($now) && $now !== '') $links[$old] = $now;
            }
        } else {
            Faults::swallow(new \RuntimeException('time zone links unreadable'), 'ClockZones::links');
        }
        return self::$links = $links;
    }
}
