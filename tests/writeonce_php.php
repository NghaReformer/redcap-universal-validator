<?php
/**
 * writeonce_php.php — @UVWRITEONCE without a REDCap: the lock verdict
 * (Logic::writeOnceVerdict) against tests/writeonce_fixture.json, and the
 * log reader it is fed by (php/ValueHistory.php) against a mock of REDCap's
 * log: users, instances, a DELETE that ends the history, paging, the cap,
 * the byte compare of the record id, the time zones and a failed read.
 *
 * Run:  php tests/writeonce_php.php
 */

require_once __DIR__ . '/../php/Scan/ScanDb.php';
require_once __DIR__ . '/../php/Scan/SourceFence.php';
require_once __DIR__ . '/../php/ValueStamps.php';
require_once __DIR__ . '/../php/ValueHistory.php';
require_once __DIR__ . '/../php/ExactDecimal.php';
require_once __DIR__ . '/../php/Logic.php';
require_once __DIR__ . '/writeonce_fake_log.php';

use INSPIRE\UniversalValidator\Logic;
use INSPIRE\UniversalValidator\ValueHistory;

$n = 0;
$fails = 0;
function check($label, $ok, $got = null)
{
    global $n, $fails;
    $n++;
    if (!$ok) {
        $fails++;
        echo "FAIL: $label" . ($got === null ? '' : ' — got ' . json_encode($got, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
    }
}

// ---- the verdict, case by case -------------------------------------------------
$fx = json_decode(file_get_contents(__DIR__ . '/writeonce_fixture.json'), true);
check('the fixture parses', is_array($fx) && !empty($fx['cases']));
foreach ($fx['cases'] as $c) {
    $saves = [];
    foreach ($c['saves'] as $s) {
        $at = preg_replace('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', '$1-$2-$3 $4:$5:$6', $s[0]);
        $saves[] = ['at' => $at, 'ts' => $s[0], 'user' => $s[1], 'value' => $s[2]];
    }
    $roles = isset($c['roles']) ? $c['roles'] : [];
    $unknown = !empty($c['rolesUnknown']);
    $asked = [];
    $holds = function ($user) use ($roles, $unknown, &$asked) { $asked[] = $user; return $unknown ? null : in_array($user, $roles, true); };
    $missing = isset($c['missing']) ? $c['missing'] : [];
    $isMissing = function ($v) use ($missing) { return in_array($v, $missing, true); };
    $got = Logic::writeOnceVerdict($saves, $c['current'], $holds, $isMissing,
        isset($c['since']) ? $c['since'] : null, isset($c['sinceLabel']) ? $c['sinceLabel'] : null);
    check('verdict: ' . $c['name'], $got === $c['expect'], $got);
}

// The roles are asked only for a save that departs from the lock, and only by its own user.
$asked = [];
$holds = function ($user) use (&$asked) { $asked[] = $user; return false; };
Logic::writeOnceVerdict([
    ['at' => 'a', 'ts' => '20261001090000', 'user' => 'ann', 'value' => ''],
    ['at' => 'b', 'ts' => '20261002090000', 'user' => 'bob', 'value' => 'A'],
    ['at' => 'c', 'ts' => '20261003090000', 'user' => 'cat', 'value' => 'B'],
    ['at' => 'd', 'ts' => '20261004090000', 'user' => 'dan', 'value' => 'A'],
], 'A', $holds, function () { return false; });
check('verdict: the roles of the users who departed from the lock are asked, no other', $asked === ['cat'], $asked);

// ---- the log reader ----------------------------------------------------------------

$utc = new \DateTimeZone('UTC');
$row = function ($id, $pk, $event, $ts, $dv, $user = 'ann', $ev = 'UPDATE', $obj = 'redcap_data', $pid = 149) {
    return [$id, $pid, $pk, $event, $ts, $dv, $obj, $ev, $user];
};
$values = function (array $r) { return array_map(function ($s) { return [$s['ts'], $s['user'], $s['value']]; }, $r['saves']); };

$db = new FakeHistoryLog();
$db->rows = [
    $row(1, 'R1', 380, '20261001090000', "dob = '2000-01-01',\nwt = '70'", 'ann', 'INSERT'),
    $row(2, 'R1', 380, '20261002090000', "dob = '2000-01-02'", 'bob'),
    $row(3, 'R1', 381, '20261002100000', "dob = '1999-12-31'", 'bob'),
    $row(4, 'R1', 380, '20261003090000', "[instance = 2],\ndob = '2001-01-01'", 'cat'),
    $row(5, 'R1', 380, '20261004090000', "notes = 'x',\ndob = 'a',\ndob = 'b'", 'dan'),
    $row(6, 'R2', 380, '20261001090000', "dob = '1980-05-05'", 'ann', 'INSERT'),
    $row(7, 'r1', 380, '20261005090000', "dob = '1970-01-01'", 'eve'),          // another record that the collation matches
    $row(8, 'R1', 380, '20261006090000', "dob = '2002-02-02'", 'fay', 'UPDATE', 'redcap_metadata'),
];
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('reader: made for the project', $h instanceof ValueHistory);
$r = $h->saves('R1', 380, 1, 'dob');
check('reader: oldest first, with the user, other records and other object types left out',
    $r['state'] === 'read' && $values($r) === [['20261001090000', 'ann', '2000-01-01'], ['20261002090000', 'bob', '2000-01-02'], ['20261004090000', 'dan', null]], $r);
check('reader: the save time in the clock zone', $r['saves'][0]['at'] === '2026-10-01 09:00:00', $r['saves'][0]);
check('reader: another event has its own history', $values($h->saves('R1', 381, 1, 'dob')) === [['20261002100000', 'bob', '1999-12-31']]);
check('reader: an instance has its own history', $values($h->saves('R1', 380, 2, 'dob')) === [['20261003090000', 'cat', '2001-01-01']]);
check('reader: a field the log never shows has no saves', $h->saves('R1', 382, 1, 'dob') === ['state' => 'read', 'saves' => []]);
check('reader: a field not named when it was made is unknown, never "no saves"', $h->saves('R1', 380, 1, 'wt') === ['state' => 'unknown', 'saves' => []]);
$q = $db->queries;
$h->saves('R1', 381, 1, 'dob');
check('reader: a record is read once', $db->queries === $q);
check('reader: the record id compared as bytes', $values($h->saves('r1', 380, 1, 'dob')) === [['20261005090000', 'eve', '1970-01-01']]);
check('reader: a record with no rows', $h->saves('R9', 380, 1, 'dob') === ['state' => 'none', 'saves' => []]);
check('reader: the query reads the user and the deletes', strpos($db->sql[1], '`user`') !== false && strpos($db->sql[1], "'DELETE'") !== false, $db->sql[1]);

// A DELETE ends the history: the record made again under that name starts over.
$db = new FakeHistoryLog();
$db->rows = [
    $row(1, 'R1', 380, '20261001090000', "dob = '2000-01-01'", 'ann', 'INSERT'),
    $row(2, 'R1', 380, '20261002090000', "dob = '2000-01-02'", 'bob'),
    $row(3, 'R1', null, '20261003090000', "record_id = 'R1'", 'cat', 'DELETE'),
    $row(4, 'R1', 380, '20261004090000', "dob = '1990-09-09'", 'dan', 'INSERT'),
];
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('reader: a DELETE ends the history', $values($h->saves('R1', 380, 1, 'dob')) === [['20261004090000', 'dan', '1990-09-09']]);
$db->rows[] = $row(5, 'R2', null, '20261003090000', "record_id = 'R2'", 'cat', 'DELETE');
check('reader: a record whose newest row is a DELETE has no saves', $h->saves('R2', 380, 1, 'dob') === ['state' => 'read', 'saves' => []]);

// Paging: a record read across pages, then the cap.
$db = new FakeHistoryLog();
for ($i = 1; $i <= ValueHistory::PAGE + 20; $i++) $db->rows[] = $row($i, 'R1', 380, sprintf('202610%02d%06d', 1 + intdiv($i, 100), $i % 100), "dob = 'v$i'", 'u' . $i);
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
$r = $h->saves('R1', 380, 1, 'dob');
check('reader: every page read, oldest first', $r['state'] === 'read' && count($r['saves']) === ValueHistory::PAGE + 20
    && $r['saves'][0]['value'] === 'v1' && end($r['saves'])['value'] === 'v' . (ValueHistory::PAGE + 20), [$r['state'], count($r['saves'])]);
check('reader: two pages, then the projects lookup', $db->queries === 3, $db->queries);
$db = new FakeHistoryLog();
for ($i = 1; $i <= ValueHistory::MAX_ROWS_PER_RECORD + 1; $i++) $db->rows[] = $row($i, 'R1', 380, '20261001090000', "wt = '$i'");
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('reader: a log past the cap is capped, not read in part', $h->saves('R1', 380, 1, 'dob') === ['state' => 'capped', 'saves' => []]);
$db->rows[] = $row(ValueHistory::MAX_ROWS_PER_RECORD + 2, 'R2', null, '20261001090000', '', 'x', 'DELETE');
for ($i = 1; $i <= ValueHistory::MAX_ROWS_PER_RECORD; $i++) $db->rows[] = $row(ValueHistory::MAX_ROWS_PER_RECORD + 2 + $i, 'R2', 380, '20261001090000', "wt = '$i'");
check('reader: a history that fills the cap is capped, though a DELETE lies just past it', $h->saves('R2', 380, 1, 'dob')['state'] === 'capped');

// A failed read is unknown, never "no saves".
$db = new FakeHistoryLog();
$db->rows = [$row(1, 'R1', 380, '20261001090000', "dob = '2000-01-01'")];
$db->failOn = 2;
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('reader: a failed read is unknown', $h->saves('R1', 380, 1, 'dob') === ['state' => 'unknown', 'saves' => []]);
$db = new FakeHistoryLog();
$db->fail = true;
check('reader: no reader when the log table cannot be read', ValueHistory::forProject($db, 149, ['dob'], $utc, $utc) === null);
$db = new FakeHistoryLog();
$db->table = 'users; DROP TABLE x';
check('reader: no reader for a log table name outside the allowlist', ValueHistory::forProject($db, 149, ['dob'], $utc, $utc) === null);

// Records kept in memory.
$db = new FakeHistoryLog();
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
for ($i = 0; $i < ValueHistory::RECORDS_KEPT + 5; $i++) $h->saves('R' . $i, 380, 1, 'dob');
check('reader: at most RECORDS_KEPT records held', $h->recordsHeld() === ValueHistory::RECORDS_KEPT, $h->recordsHeld());

// Time zones: the log in the server's zone, the answer in the project's.
$db = new FakeHistoryLog();
$db->rows = [$row(1, 'R1', 380, '20261001233000', "dob = '2000-01-01'")];
$h = ValueHistory::forProject($db, 149, ['dob'], new \DateTimeZone('America/New_York'), new \DateTimeZone('Africa/Douala'));
check('reader: a save time moved into the clock zone', $h->saves('R1', 380, 1, 'dob')['saves'][0]['at'] === '2026-10-02 04:30:00');
check('reader: a date starts at the log time of its midnight in the clock zone', $h->logTimeOf('2026-10-05') === '20261004190000', $h->logTimeOf('2026-10-05'));
check('reader: a date that is not a date has no log time', $h->logTimeOf('2026-02-30') === null && $h->logTimeOf('20261005') === null);
$db->rows = [$row(1, 'R1', 380, 'not a time', "dob = '2000-01-01'")];
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('reader: a ts that is not a time has no save time', $h->saves('R1', 380, 1, 'dob')['saves'][0]['at'] === null);

echo "writeonce_php: $n checks, $fails failure(s)\n";
exit($fails ? 1 : 0);
