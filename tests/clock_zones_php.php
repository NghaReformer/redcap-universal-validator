<?php
/**
 * clock_zones_php.php — the time zone names of the "window-timezone" setting
 * (php/ClockZones.php, php/timezone_links.json).
 *
 * Four groups of checks:
 *
 *   1. THE MAP. php/timezone_links.json decodes, holds the names the WIN-4
 *      checks use, and every old name in it opens a zone on this PHP.
 *   2. A SERVER WITHOUT tzdata-legacy. With every old name of the map taken
 *      away from what PHP knows, each one still opens a zone, and the zone it
 *      opens has the offset PHP gives the old name (where this PHP knows it).
 *   3. RESOLUTION. A known name is kept, an old name opens the zone it names
 *      now, a zone PHP does not know opens one of its other names, and
 *      anything else is no zone.
 *   4. FAILURE. A links file that cannot be read leaves the names PHP knows
 *      working, and is counted as a fault.
 *
 * CI runs this file twice on Ubuntu: once before tzdata-legacy is installed
 * (PHP there does not know the old names, so group 1 goes through the map),
 * and once after.
 *
 * Run:  php tests/clock_zones_php.php
 */

require_once __DIR__ . '/../php/ClockZones.php';

use INSPIRE\UniversalValidator\ClockZones;
use INSPIRE\UniversalValidator\Faults;

$n = 0;
$fails = 0;
function check($label, $ok)
{
    global $n, $fails;
    $n++;
    if (!$ok) { $fails++; echo "FAIL: $label\n"; }
}

/** The offset PHP gives $zone at $ts, or null when this PHP cannot open it. */
function offsetAt($zone, $ts)
{
    try {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone($zone))->getOffset();
    } catch (\Throwable $e) {
        return null;
    }
}

$phpKnows = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC);

// ---- 1. the map --------------------------------------------------------------

$j = json_decode((string) file_get_contents(__DIR__ . '/../php/timezone_links.json'), true);
$links = is_array($j) && isset($j['links']) && is_array($j['links']) ? $j['links'] : [];
check('the map decodes and holds the links', count($links) > 100);
check('the map says which tzdata it was written from', isset($j['tzdata']) && preg_match('/^\d{4}[a-z]$/', $j['tzdata']) === 1);
check('the map holds the names WIN-4 checks',
    ($links['Asia/Calcutta'] ?? null) === 'Asia/Kolkata' && ($links['US/Eastern'] ?? null) === 'America/New_York'
    && ($links['Europe/Kiev'] ?? null) === 'Europe/Kyiv');
check('no old name is mapped to itself or to another old name',
    !array_filter($links, function ($now, $old) use ($links) { return $now === $old || isset($links[$now]); }, ARRAY_FILTER_USE_BOTH));
$none = array_filter(array_keys($links), function ($old) { return ClockZones::resolve($old) === null; });
check('every old name of the map opens a zone on this PHP (not: ' . implode(', ', array_slice($none, 0, 5)) . ')', !$none);
$ok = true;
foreach (array_keys($links) as $old) {
    $use = ClockZones::resolve($old);
    if ($use === null) continue;
    try { new \DateTimeZone($use); } catch (\Throwable $e) { $ok = false; }
}
check('the name each old name resolves to is one PHP can open', $ok);

// ---- 2. a server without tzdata-legacy ------------------------------------------

// Harsher than Debian: every old name of the map is gone, the ones Debian's
// main tzdata package keeps (America/Montreal, UTC, ...) too.
ClockZones::useKnown(array_values(array_diff($phpKnows, array_keys($links))));
$known = array_fill_keys(array_diff($phpKnows, array_keys($links)), true);
$tried = 0; $lost = []; $wrong = [];
$now = time();
$summer = gmmktime(12, 0, 0, 7, 1, (int) gmdate('Y'));
$winter = gmmktime(12, 0, 0, 1, 1, (int) gmdate('Y'));
foreach ($links as $old => $zone) {
    // A zone this PHP does not know at all (an older database) cannot be opened anywhere.
    if (!isset($known[$zone])) continue;
    $tried++;
    $use = ClockZones::resolve($old);
    if ($use === null || !isset($known[$use])) { $lost[] = $old; continue; }
    foreach ([$now, $summer, $winter] as $ts) {
        $a = offsetAt($old, $ts);
        if ($a !== null && $a !== offsetAt($use, $ts)) { $wrong[] = $old . ' at ' . gmdate('Y-m-d', $ts); break; }
    }
}
check('without the old names, the map is tried on most of them (got ' . $tried . ')', $tried > 100);
check('without the old names, each one still opens a zone PHP knows (lost: ' . implode(', ', array_slice($lost, 0, 5)) . ')', !$lost);
check('the zone it opens keeps the old name\'s offset, summer and winter (wrong: ' . implode(', ', array_slice($wrong, 0, 5)) . ')', !$wrong);
check('without the old names: Asia/Calcutta opens Asia/Kolkata', ClockZones::resolve('Asia/Calcutta') === 'Asia/Kolkata');
check('without the old names: US/Eastern opens America/New_York', ClockZones::resolve('US/Eastern') === 'America/New_York');
check('without the old names: Europe/Kiev opens Europe/Kyiv', !isset($known['Europe/Kyiv']) || ClockZones::resolve('Europe/Kiev') === 'Europe/Kyiv');
check('without the old names: a current name is kept', ClockZones::resolve('Africa/Douala') === 'Africa/Douala');
ClockZones::useKnown(null);

// ---- 3. resolution, on a made-up map and server ----------------------------------

$tmp = tempnam(sys_get_temp_dir(), 'uvtz');
file_put_contents($tmp, json_encode(['links' => [
    'Europe/Kiev' => 'Europe/Kyiv',
    'Asia/Calcutta' => 'Asia/Kolkata',
    'Old/Gone' => 'New/Gone',
    'Bad/Target' => '',
]]));
ClockZones::useFile($tmp);
ClockZones::useKnown(['Europe/Kiev', 'Asia/Kolkata', 'Africa/Douala']);
check('a name PHP knows is kept', ClockZones::resolve('Africa/Douala') === 'Africa/Douala');
check('an old name PHP does not know opens the zone it names now', ClockZones::resolve('Asia/Calcutta') === 'Asia/Kolkata');
check('a new name PHP does not know opens an old name of the same zone', ClockZones::resolve('Europe/Kyiv') === 'Europe/Kiev');
check('an old name PHP knows is kept, not renamed', ClockZones::resolve('Europe/Kiev') === 'Europe/Kiev');
check('an old name whose zone PHP does not know under any name is no zone', ClockZones::resolve('Old/Gone') === null);
check('a link to nothing is ignored', ClockZones::resolve('Bad/Target') === null);
check('an unknown name is no zone', ClockZones::resolve('Mars/Olympus') === null);
check('an offset is not a zone name', ClockZones::resolve('+14:00') === null);
check('blank is no zone', ClockZones::resolve('') === null);
check('a value that is not a string is no zone', ClockZones::resolve(null) === null && ClockZones::resolve(['Europe/Kiev']) === null);
check('names are matched exactly, case included', ClockZones::resolve('asia/calcutta') === null);

// ---- 4. failure -------------------------------------------------------------------

Faults::take();
ClockZones::useFile($tmp . '.missing');
check('without a links file, a name PHP knows still works', ClockZones::resolve('Africa/Douala') === 'Africa/Douala');
check('a name PHP knows is resolved without reading the links file', !Faults::peek()['places']);
check('without a links file, an old name PHP does not know is no zone', ClockZones::resolve('Asia/Calcutta') === null);
check('a links file that cannot be read is counted as a fault',
    isset(Faults::peek()['places']['ClockZones::links|RuntimeException']));
file_put_contents($tmp, '{"links": "not a map"}');
ClockZones::useFile($tmp);
Faults::take();
check('a links file without a "links" map: no zone, and a fault', ClockZones::resolve('Asia/Calcutta') === null
    && isset(Faults::peek()['places']['ClockZones::links|RuntimeException']));
@unlink($tmp);
ClockZones::useFile(null);
ClockZones::useKnown(null);
check('restored: the shipped map and the names PHP knows', ClockZones::resolve('Asia/Calcutta') !== null);

echo "clock_zones_php: $n checks, $fails failure(s)\n";
exit($fails ? 1 : 0);
