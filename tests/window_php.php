<?php
/**
 * window_php.php — the @UVWINDOW verdict and its date helpers (server side).
 *
 * Runs every case of tests/window_fixture.json through
 * TemporalLogic::windowVerdict; tests/window_js.cjs runs the same cases through
 * the browser twin, so the two cannot disagree about a date. Also pins
 * TemporalValue::fromValidation / format / canonical, which the window's
 * messages and the audit rely on.
 *
 * Run:  php tests/window_php.php
 */

require_once __DIR__ . '/../php/TemporalLogic.php';

use INSPIRE\UniversalValidator\TemporalLogic;
use INSPIRE\UniversalValidator\TemporalValue;

$n = 0;
$fails = 0;
function check($label, $ok)
{
    global $n, $fails;
    $n++;
    if (!$ok) { $fails++; echo "FAIL: $label\n"; }
}

$fx = json_decode(file_get_contents(__DIR__ . '/window_fixture.json'), true);
check('fixture loads', is_array($fx) && !empty($fx['cases']));
foreach ($fx['cases'] as $c) {
    $spec = $c['spec'] + ['unit' => 'days'];
    $clock = array_key_exists('clock', $c) ? $c['clock'] : $fx['clock'];
    $got = TemporalLogic::windowVerdict($spec, $c['value'], $c['valueFormat'] ?? 'ymd',
        $c['anchor'], $c['anchorFormat'] ?? 'ymd', $clock);
    $want = ['verdict' => $c['verdict'], 'earliest' => $c['earliest'], 'latest' => $c['latest']];
    check($c['name'] . ' -> ' . json_encode($want) . ' (got ' . json_encode($got) . ')', $got === $want);
}

// calendar arithmetic, the same cases as window_js.cjs
check('calendar fixture loads', !empty($fx['calendar']));
foreach ($fx['calendar'] as $c) {
    $got = call_user_func_array([TemporalValue::class, $c['fn']], $c['args']);
    check('calendar ' . $c['fn'] . json_encode($c['args']) . ' -> ' . json_encode($c['out']) . ' (got ' . json_encode($got) . ')',
        $got === $c['out']);
}

// fromValidation: the type and format a REDCap validation implies
foreach ([
    'date_ymd' => ['type' => 'date', 'format' => 'ymd'],
    'date_dmy' => ['type' => 'date', 'format' => 'dmy'],
    'date_mdy' => ['type' => 'date', 'format' => 'mdy'],
    'datetime_dmy' => ['type' => 'datetime', 'format' => 'dmy'],
    'datetime_seconds_mdy' => ['type' => 'datetime_seconds', 'format' => 'mdy'],
    'integer' => null, '' => null, 'date_xyz' => null, 'time' => null,
] as $v => $want) {
    check('fromValidation(' . $v . ')', TemporalValue::fromValidation($v) === $want);
}
check('fromValidation(null)', TemporalValue::fromValidation(null) === null);

// format: canonical -> how the field displays it
check('format date dmy', TemporalValue::format('2026-01-22', 'date', 'dmy') === '22-01-2026');
check('format date mdy', TemporalValue::format('2026-01-22', 'date', 'mdy') === '01-22-2026');
check('format date ymd', TemporalValue::format('2026-01-22', 'date', 'ymd') === '2026-01-22');
check('format datetime drops seconds', TemporalValue::format('2026-01-22 08:05:09', 'datetime', 'dmy') === '22-01-2026 08:05');
check('format datetime_seconds keeps them', TemporalValue::format('2026-01-22 08:05:09', 'datetime_seconds', 'mdy') === '01-22-2026 08:05:09');
check('format of a date for a datetime field adds midnight', TemporalValue::format('2026-01-22', 'datetime', 'ymd') === '2026-01-22 00:00');
check('format refuses junk', TemporalValue::format('22/01/2026', 'date', 'ymd') === '' && TemporalValue::format(null, 'date', 'ymd') === '');

// canonical: seconds back to the parse() form, no timezone shift
$p = TemporalValue::parse('2024-02-29', 'date', 'ymd');
check('canonical round trip, date', TemporalValue::canonical($p['seconds'], 'date') === '2024-02-29');
$p = TemporalValue::parse('2024-02-29 23:59', 'datetime', 'ymd');
check('canonical round trip, datetime', TemporalValue::canonical($p['seconds'], 'datetime') === '2024-02-29 23:59:00');
check('canonical ignores the server timezone', (function () {
    $tz = date_default_timezone_get();
    date_default_timezone_set('Pacific/Kiritimati');
    $c = TemporalValue::canonical(0, 'datetime');
    date_default_timezone_set($tz);
    return $c === '1970-01-01 00:00:00';
})());

// The verdict never reads the server's timezone either: same answer in UTC+14.
$tz = date_default_timezone_get();
date_default_timezone_set('Pacific/Kiritimati');
$r = TemporalLogic::windowVerdict(['lo' => 1, 'hi' => 1, 'unit' => 'days', 'type' => 'date', 'fromType' => 'date'],
    '2026-03-29', 'ymd', '2026-03-28', 'ymd', null);
date_default_timezone_set($tz);
check('verdict is timezone-free', $r['verdict'] === 'ok' && $r['earliest'] === '2026-03-29');

echo "window_php: $n checks, $fails failure(s)\n";
exit($fails ? 1 : 0);
