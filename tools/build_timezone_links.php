<?php
/**
 * tools/build_timezone_links.php - write php/timezone_links.json, the map from
 * each backward-compatible time zone name to the zone it names now.
 *
 * Debian 13 and Ubuntu 26.04 put the backward-compatible names (Asia/Calcutta,
 * US/Eastern, Europe/Kiev, ...) in a separate package, tzdata-legacy, and their
 * PHP reads the system's time zone database. Without that package PHP does not
 * know those names, and a project that saved one lost its time zone
 * (ClockZones says what the module does with the map).
 *
 * The links come from the IANA time zone database: the "L target link" lines
 * of a compiled tzdata.zi (/usr/share/zoneinfo/tzdata.zi on Debian and Ubuntu)
 * or the "Link target link" lines of the IANA source files (backward and the
 * region files). Give one or more files; when two give a name different
 * targets, the first file wins. A link to a link is followed to its zone.
 *
 * Run it after a tzdata release that adds or renames zones, and commit
 * php/timezone_links.json. The map in the repository was written from the
 * tzdata.zi of Ubuntu 24.04 (tzdata 2026e), which lists every name Debian's
 * tzdata-legacy holds except the 11 that tzdb keeps as zones of their own
 * (CET, EET, EST, EST5EDT, ...).
 *
 * Usage: php tools/build_timezone_links.php [--out FILE] SOURCE [SOURCE...]
 */

$args = array_slice($argv, 1);
$out = dirname(__DIR__) . '/php/timezone_links.json';
$i = array_search('--out', $args, true);
if ($i !== false) {
    if (!isset($args[$i + 1])) { fwrite(STDERR, "--out needs a file\n"); exit(2); }
    $out = $args[$i + 1];
    array_splice($args, $i, 2);
}
if (!$args) {
    fwrite(STDERR, "usage: php tools/build_timezone_links.php [--out FILE] SOURCE [SOURCE...]\n");
    exit(2);
}

$links = [];     // link name => target, as written
$zones = [];     // zone name => true
$versions = [];  // "# version 2026e" of each source that has one
$sources = [];
foreach ($args as $file) {
    $lines = @file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) { fwrite(STDERR, "cannot read $file\n"); exit(1); }
    $sources[] = basename($file);
    foreach ($lines as $line) {
        if (preg_match('/^#\s*version\s+(\S+)/', $line, $m)) { $versions[$m[1]] = true; continue; }
        $f = preg_split('/\s+/', trim(preg_replace('/#.*$/', '', $line)));
        if (count($f) >= 3 && ($f[0] === 'L' || $f[0] === 'Link')) {
            if (!isset($links[$f[2]])) $links[$f[2]] = $f[1];
        } elseif (count($f) >= 2 && ($f[0] === 'Z' || $f[0] === 'Zone')) {
            $zones[$f[1]] = true;
        }
    }
}
if (!$links) { fwrite(STDERR, "no links found in " . implode(', ', $sources) . "\n"); exit(1); }

$map = [];
foreach ($links as $name => $target) {
    $seen = [$name => true];
    while (isset($links[$target]) && !isset($seen[$target])) { $seen[$target] = true; $target = $links[$target]; }
    if (isset($links[$target])) { fwrite(STDERR, "the links of $name go round in a circle\n"); exit(1); }
    if ($zones && !isset($zones[$target])) { fwrite(STDERR, "$name links to $target, which is no zone of the sources\n"); exit(1); }
    $map[$name] = $target;
}
ksort($map, SORT_STRING);

$doc = [
    '_comment' => [
        'Each backward-compatible IANA time zone name and the zone it names now, for a server whose PHP does not know the old name.',
        'tools/build_timezone_links.php writes this file from the IANA links; php/ClockZones.php reads it.',
    ],
    'tzdata' => implode(', ', array_keys($versions)),
    'links' => $map,
];
// Two-space indents, like the other JSON files in php/.
$json = preg_replace_callback('/^( +)/m', function ($m) { return str_repeat(' ', strlen($m[1]) / 2); },
    json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
if (@file_put_contents($out, $json) === false) { fwrite(STDERR, "cannot write $out\n"); exit(1); }
echo count($map) . ' links (tzdata ' . ($doc['tzdata'] === '' ? 'version unknown' : $doc['tzdata']) . ') written to ' . $out . "\n";
