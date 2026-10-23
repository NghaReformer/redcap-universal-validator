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
$row = function ($id, $pk, $event, $ts, $dv, $user = 'ann', $ev = 'UPDATE', $obj = 'redcap_data', $pid = 149, $descr = null) {
    $r = [$id, $pid, $pk, $event, $ts, $dv, $obj, $ev, $user];
    if ($descr !== null) $r[9] = $descr;
    return $r;
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
check('reader: an event the log never shows has no saves', $h->saves('R1', 382, 1, 'dob') === ['state' => 'none', 'saves' => []]);
check('reader: a field not named when it was made is unknown, never "no saves"', $h->saves('R1', 380, 1, 'wt') === ['state' => 'unknown', 'saves' => [], 'why' => 'unnamed']);
$q = $db->queries;
$h->saves('R1', 381, 1, 'dob');
check('reader: a record is read once', $db->queries === $q);
check('reader: the record id compared as bytes', $values($h->saves('r1', 380, 1, 'dob')) === [['20261005090000', 'eve', '1970-01-01']]);
$db->looseEvent = true;
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('reader: a row of another event is passed over even when the server returns it', $values($h->saves('R1', 381, 1, 'dob')) === [['20261002100000', 'bob', '1999-12-31']]);
check('reader: a record with no rows', $h->saves('R9', 380, 1, 'dob') === ['state' => 'none', 'saves' => []]);
check('reader: the query reads the user, the deletes and their description', strpos($db->sql[2], '`user`') !== false && strpos($db->sql[2], "'DELETE'") !== false
    && strpos($db->sql[2], 'description') !== false, $db->sql[2]);
check('reader: the newest project-wide erase is asked for once per reader, before its first page', strpos($db->sql[1], "event = 'DELETE' AND event_id IS NULL") !== false
    && count(array_filter($db->sql, function ($q) { return strpos($q, "NOT LIKE 'Delete record%'") !== false; }))
       === count(array_filter($db->sql, function ($q) { return strpos($q, 'FROM redcap_projects') !== false; })), $db->sql);

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

// Only a whole-record DELETE in a project of one arm ends the history; any
// other DELETE cannot be placed (review H3).
$del = function ($descr, array $opts = [], $ev = 'DELETE') use ($row, $utc) {
    $db = new FakeHistoryLog();
    $db->rows = [
        $row(1, 'R1', 380, '20261001090000', "dob = '2000-01-01'", 'ann', 'INSERT'),
        $row(2, 'R1', null, '20261002090000', '', 'cat', $ev, 'redcap_data', 149, $descr),
        $row(3, 'R1', 380, '20261003090000', "dob = '1990-09-09'", 'dan'),
    ];
    return ValueHistory::forProject($db, 149, ['dob'], $utc, $utc, $opts)->saves('R1', 380, 1, 'dob');
};
check('reader: "Delete record" ends the history', $values($del('Delete record')) === [['20261003090000', 'dan', '1990-09-09']]);
check('reader: "Delete record (API)" ends it too', $values($del('Delete record (API)')) === [['20261003090000', 'dan', '1990-09-09']]);
check('reader: a DELETE written in lower case is a DELETE', $values($del('Delete record', [], 'delete')) === [['20261003090000', 'dan', '1990-09-09']]);
check('reader: a whole-record DELETE described in other letter case ends the history', $values($del('delete record (api)')) === [['20261003090000', 'dan', '1990-09-09']]
    && $values($del('DELETE RECORD')) === [['20261003090000', 'dan', '1990-09-09']], $del('delete record (api)'));
foreach (['Delete all record data for single form', 'Delete all record data for single event', 'Delete record (arm 2)', ''] as $d) {
    check('reader: a DELETE described "' . $d . '" cannot be placed', $del($d) === ['state' => 'unknown', 'saves' => [], 'why' => 'delete'], $del($d));
}
check('reader: a whole-record DELETE in a project of several arms cannot be placed', $del('Delete record', ['multiArm' => true]) === ['state' => 'unknown', 'saves' => [], 'why' => 'delete']);
// A save with no event that names a kept field cannot be placed; one that names none is passed over (review M2).
$db = new FakeHistoryLog();
$db->rows = [
    $row(1, 'R1', null, '20261001090000', "dob = '2000-01-01'", 'ann', 'INSERT'),
    $row(2, 'R1', 380, '20261003090000', "dob = '1990-09-09'", 'dan'),
    $row(3, 'R2', null, '20261001090000', "wt = '70'", 'ann'),
    $row(4, 'R2', 380, '20261003090000', "dob = '1990-09-09'", 'dan'),
];
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('reader: a save of a kept field with no event cannot be placed', $h->saves('R1', 380, 1, 'dob') === ['state' => 'unknown', 'saves' => [], 'why' => 'noevent']);
check('reader: a save with no event of fields not kept is passed over', $values($h->saves('R2', 380, 1, 'dob')) === [['20261003090000', 'dan', '1990-09-09']]);

// Erased data (review W2-1): every history starts after the newest project-wide erase row.
$erase = function (array $extra, $descr = 'Erase all data') use ($row) {
    $db = new FakeHistoryLog();
    $db->rows = array_merge([
        $row(1, '1', 380, '20250105090000', "record_id = '1',\ndob = '2025-01-05'", 'builder', 'INSERT'),
        $row(2, '149', null, '20250201090000', 'project_id = 149', 'builder', 'DELETE', 'redcap_data', 149, $descr),
        $row(3, '1', 380, '20260301090000', "record_id = '1',\ndob = '2026-03-01'", 'nurse', 'INSERT'),
    ], $extra);
    return $db;
};
$db = $erase([]);
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('erase: a record made again after "Erase all data" starts a new history', $values($h->saves('1', 380, 1, 'dob')) === [['20260301090000', 'nurse', '2026-03-01']], $h->saves('1', 380, 1, 'dob'));
$db = $erase([]);
$db->rows[1][9] = null;
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('erase: one with no description is an erase too', $values($h->saves('1', 380, 1, 'dob')) === [['20260301090000', 'nurse', '2026-03-01']]);
$db = $erase([$row(4, '1', 380, '20260401090000', "dob = '2026-04-01'", 'nurse')], 'Erase all data');
$db->rows[] = $row(5, '149', null, '20260402090000', '', 'x', 'DELETE', 'redcap_data', 149, 'Erase all data');
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('erase: the newest erase counts', $h->saves('1', 380, 1, 'dob') === ['state' => 'none', 'saves' => []], $h->saves('1', 380, 1, 'dob'));
// a record named like the project id: its own whole-record DELETE is no erase
$db = $erase([], 'Delete record');
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('erase: a record "149" deleted whole is not an erase of the project', count($h->saves('1', 380, 1, 'dob')['saves']) === 2, $h->saves('1', 380, 1, 'dob'));
$db = $erase([], 'Delete record (API)');
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('erase: nor one deleted by the API', count($h->saves('1', 380, 1, 'dob')['saves']) === 2);
$db = $erase([]);
$db->rows[1][3] = 380;
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('erase: a DELETE that names an event is no erase of the project', count($h->saves('1', 380, 1, 'dob')['saves']) === 2);
foreach (['Delete all record data for single form', 'Delete all record data for single event', 'Delete all data on event'] as $part) {
    $db = $erase([], $part);
    $h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
    check('erase: part of a record "149" deleted with no event is no erase (review W3-4): ' . $part, count($h->saves('1', 380, 1, 'dob')['saves']) === 2, $h->saves('1', 380, 1, 'dob'));
}
$db = $erase([]);
$db->rows[1][2] = '149 ';
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('erase: the project id compared as bytes: a record "149 " is not the project', count($h->saves('1', 380, 1, 'dob')['saves']) === 2, $h->saves('1', 380, 1, 'dob'));
// An erase placed after a record was made, so that its creation is not read: the history is
// unknown, never read from the erase on (review W3-4).
$db = new FakeHistoryLog();
$db->rows = [
    $row(1, '1', 380, '20250105090000', "record_id = '1',
dob = '2025-01-05'", 'builder', 'INSERT'),
    $row(2, '149', null, '20250201090000', '', 'mallory', 'DELETE', 'redcap_data', 149, 'Something the reader takes for an erase'),
    $row(3, '1', 380, '20250301090000', "dob = '2025-03-03'", 'mallory'),
];
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc, ['recordIdField' => 'record_id']);
check('erase: a floor after the record was made leaves its history unknown', $h->saves('1', 380, 1, 'dob') === ['state' => 'unknown', 'saves' => [], 'why' => 'start'], $h->saves('1', 380, 1, 'dob'));
$db = $erase([]);
$db->rows[1][1] = 150;
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('erase: another project\'s erase is not this one\'s', count($h->saves('1', 380, 1, 'dob')['saves']) === 2);
$db = $erase([]);
$db->failOn = 2;
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('erase: a floor that cannot be read makes every history unknown', $h->saves('1', 380, 1, 'dob') === ['state' => 'unknown', 'saves' => [], 'why' => 'read']);
check('... the next record too', $h->saves('2', 380, 1, 'dob')['state'] === 'unknown');

// The record's start (reviews W2-6, W3-3, W3-5): told the record id field, the reader reads a
// history only when it holds the INSERT that made the record, with a line for that field.
$START = ['recordIdField' => 'record_id', 'order' => ['record_id' => 0, 'dob' => 1, 'notes' => 2, 'wt' => 3], 'freeText' => ['notes' => true]];
$ren = function (array $rows, array $opts = null) use ($utc, $START) {
    $db = new FakeHistoryLog();
    $db->rows = $rows;
    return ValueHistory::forProject($db, 149, ['dob'], $utc, $utc, $opts === null ? $START : $opts)->saves('B1', 380, 1, 'dob');
};
$UNSTARTED = ['state' => 'unknown', 'saves' => [], 'why' => 'start'];
// A renamed record whose saves under its old name stay under that name: the new name's log
// starts with the rename, an UPDATE that writes the record id field.
$rows = [
    $row(1, 'A1', 380, '20260105090000', "record_id = 'A1',\ndob = '2026-01-05'", 'nurse', 'INSERT'),
    $row(2, 'B1', 380, '20260201090000', "record_id = 'B1'", 'nurse'),
    $row(3, 'B1', 380, '20260301090000', "dob = '2026-02-02'", 'nurse'),
];
check('start: a renamed record whose first saves are logged under its old name is unknown', $ren($rows) === $UNSTARTED, $ren($rows));
$rows[1][3] = null;
check('start: ... whatever event the rename names', $ren($rows) === $UNSTARTED);
check('start: not told the record id field, the reader does not ask for the start', $ren($rows, [])['state'] === 'read');
// The same rename where the old rows were moved to the new name: the INSERT names the old one,
// and the history is whole.
$rows[0][2] = 'B1';
check('start: an INSERT under this name whose record id line names another record still starts it',
    $values($ren($rows)) === [['20260105090000', 'nurse', '2026-01-05'], ['20260301090000', 'nurse', '2026-02-02']], $ren($rows));
$made = [
    $row(1, 'B1', 381, '20260105090000', "record_id = 'B1',\nwt = '3'", 'nurse', 'INSERT'),
    $row(2, 'B1', 380, '20260106090000', "record_id = 'B1',\ndob = '2026-01-06'", 'nurse'),
    $row(3, 'B1', 380, '20260107090000', "dob = '2026-01-07'", 'nurse'),
];
check('start: the INSERT is read whatever event it names, and an UPDATE that writes the record id, as a first save at another event may, is no rename',
    $values($ren($made)) === [['20260106090000', 'nurse', '2026-01-06'], ['20260107090000', 'nurse', '2026-01-07']], $ren($made));
$made[] = $row(4, 'B1', 381, '20260108090000', "notes = 'x',\nrecord_id = 'R9'", 'mallory');
check('start: a record id line typed into a notes value at any event changes nothing', $values($ren($made)) === [['20260106090000', 'nurse', '2026-01-06'], ['20260107090000', 'nurse', '2026-01-07']], $ren($made));
$typed = [
    $row(1, 'B1', 380, '20260105090000', "dob = '2026-01-05',\nnotes = 'x',\nrecord_id = 'B1'", 'mallory', 'INSERT'),
    $row(2, 'B1', 380, '20260106090000', "dob = '2026-01-06'", 'nurse'),
];
check('start: an INSERT whose record id line could have been typed is no start, and says so (review W4-1)', $ren($typed) === ['state' => 'unknown', 'saves' => [], 'why' => 'startrow'], $ren($typed));
$typed[0][7] = 'UPDATE';
$typed[0][5] = "record_id = 'B1',\ndob = '2026-01-05'";
check('start: an UPDATE with the record id line is no start either', $ren($typed) === $UNSTARTED, $ren($typed));
$made = [$row(1, 'B1', 380, '20260105090000', "record_id = 'B1',\ndob = '2026-01-05'", 'nurse', 'INSERT')];
check('start: the INSERT that makes the record', $values($ren($made)) === [['20260105090000', 'nurse', '2026-01-05']]);
// A record deleted, then another renamed to its name: the rows after the DELETE hold no INSERT.
$onto = [
    $row(1, 'B1', 380, '20260105090000', "record_id = 'B1',\ndob = '2026-01-05'", 'nurse', 'INSERT'),
    $row(2, 'B1', null, '20260106090000', '', 'nurse', 'DELETE', 'redcap_data', 149, 'Delete record'),
    $row(3, 'B1', 380, '20260107090000', "record_id = 'B1'", 'nurse'),
    $row(4, 'B1', 380, '20260108090000', "dob = '2026-01-08'", 'nurse'),
];
check('start: a whole-record DELETE with no INSERT after it starts no history', $ren($onto) === $UNSTARTED, $ren($onto));
$onto[2] = $row(3, 'B1', 380, '20260107090000', "record_id = 'B1',\ndob = '2026-01-07'", 'nurse', 'INSERT');
check('start: ... while a record made again after it does', $values($ren($onto)) === [['20260107090000', 'nurse', '2026-01-07'], ['20260108090000', 'nurse', '2026-01-08']], $ren($onto));
$made = [
    $row(1, 'B1', 380, '20260105090000', "recordXid = 'B1',\ndob = '2026-01-05'", 'nurse', 'INSERT'),
    $row(2, 'B1', 381, '20260106090000', "prev_record_id = 'B1'", 'nurse', 'INSERT'),
];
check('start: a field whose name only looks like the record id field is no start', $ren($made) === $UNSTARTED, $ren($made));
$made = [$row(1, 'B1', 380, '20260105090000', "wt = '3',\nrecord_id = 'B1'", 'nurse', 'INSERT'), $row(2, 'B1', 380, '20260106090000', "dob = '2026-01-06'", 'nurse')];
check('start: told no free-text fields, the reader counts every field as one, so a record id line after another field is no start',
    $ren($made, ['recordIdField' => 'record_id']) === ['state' => 'unknown', 'saves' => [], 'why' => 'startrow'], $ren($made, ['recordIdField' => 'record_id']));

// The start read from the row as written (review W4-1), with the options the module gives the reader
// (UniversalValidator::valueHistoryOptions): every Text and Notes field is free text, the record id
// field included, so in the row that made the record its record id line is the first free-text value.
$MOD = function (array $dd) {
    $free = [];
    foreach ($dd as $f => $t) if ($t === 'text' || $t === 'notes') $free[$f] = true;
    return ['order' => array_flip(array_keys($dd)), 'freeText' => $free, 'recordIdField' => 'record_id', 'multiArm' => false];
};
$mod = function (array $rows, array $dd, $rec, $event, $field) use ($utc, $MOD) {
    $db = new FakeHistoryLog();
    $db->rows = $rows;
    return ValueHistory::forProject($db, 149, ['consent', 'wt'], $utc, $utc, $MOD($dd))->saves($rec, $event, 1, $field);
};
$STARTROW = ['state' => 'unknown', 'saves' => [], 'why' => 'startrow'];
$rows = [
    $row(1, '7', 380, '20260101090000', "record_id = '7',\nconsent = '2026-01-01',\narm = '1',\nsite = '2'", 'nurse', 'INSERT', 'redcap_data', 149, 'Create record'),
    $row(2, '7', 380, '20260301090000', "consent = '2026-03-03'", 'mallory'),
    $row(3, '7', 381, '20260401090000', "record_id = '7',\nwt = '70'", 'nurse'),
    $row(4, '7', 381, '20260402090000', "wt = '71'", 'mallory'),
];
$before = ['record_id' => 'text', 'consent' => 'text', 'arm' => 'radio', 'site' => 'radio', 'wt' => 'text'];
$after = ['record_id' => 'text', 'consent' => 'text', 'site' => 'radio', 'arm' => 'radio', 'wt' => 'text'];
check('start (module options): the row that made the record, read in the dictionary order it was written in',
    $values($mod($rows, $before, '7', 380, 'consent')) === [['20260101090000', 'nurse', '2026-01-01'], ['20260301090000', 'mallory', '2026-03-03']], $mod($rows, $before, '7', 380, 'consent'));
check('start (module options): a field moved since leaves the record started; only that row\'s values are not plain',
    $values($mod($rows, $after, '7', 380, 'consent')) === [['20260101090000', 'nurse', null], ['20260301090000', 'mallory', '2026-03-03']], $mod($rows, $after, '7', 380, 'consent'));
check('start (module options): ... and a field locked at another event is read whole',
    $values($mod($rows, $after, '7', 381, 'wt')) === [['20260401090000', 'nurse', '70'], ['20260402090000', 'mallory', '71']], $mod($rows, $after, '7', 381, 'wt'));
$notes = [
    $row(1, '8', 380, '20260101090000', "record_id = '8',\nconsent = '2026-01-01',\nnote = 'He said 'no',\nthen agreed'", 'nurse', 'INSERT'),
    $row(2, '8', 380, '20260301090000', "consent = '2026-03-03'", 'mallory'),
];
check('start (module options): a Notes value in that row with a line ending in quote-comma leaves the record started',
    $values($mod($notes, ['record_id' => 'text', 'consent' => 'text', 'note' => 'notes'], '8', 380, 'consent')) === [['20260101090000', 'nurse', null], ['20260301090000', 'mallory', '2026-03-03']],
    $mod($notes, ['record_id' => 'text', 'consent' => 'text', 'note' => 'notes'], '8', 380, 'consent'));
$late = function ($dv) use ($row) {
    return [$row(1, '9', 381, '20260101090000', $dv, 'nurse', 'INSERT'), $row(2, '9', 381, '20260102090000', "wt = '70'", 'nurse')];
};
$DD9 = ['record_id' => 'text', 'wt' => 'text', 'visit_note' => 'notes', 'arm' => 'radio', 'cb' => 'checkbox'];
check('start (module options): a record id line after a Notes line is an untrusted start ("startrow")', $mod($late("visit_note = 'x',\nrecord_id = '9'"), $DD9, '9', 381, 'wt') === $STARTROW,
    $mod($late("visit_note = 'x',\nrecord_id = '9'"), $DD9, '9', 381, 'wt'));
check('start (module options): ... and after a Text line', $mod($late("wt = '1',\nrecord_id = '9'"), $DD9, '9', 381, 'wt') === $STARTROW);
foreach (["arm = '1',\nrecord_id = '9'" => 'a line of a field no one types', "[instance = 2],\nrecord_id = '9'" => 'an instance line',
          "cb(1) = checked,\ncb(2) = unchecked,\nrecord_id = '9',\nvisit_note = 'x'" => 'checkbox lines', "record_id = '9'" => 'nothing (the whole row)'] as $dv => $label) {
    check('start (module options): a record id line after ' . $label . ' starts the record', $mod($late($dv), $DD9, '9', 381, 'wt')['state'] === 'read', $mod($late($dv), $DD9, '9', 381, 'wt'));
}
check('start (module options): a record id line after a stray line is an untrusted start', $mod($late("what is this\nrecord_id = '9'"), $DD9, '9', 381, 'wt') === $STARTROW);
check('start (module options): ... and after a value of another field that runs on', $mod($late("arm = '1\nrecord_id = '9'"), $DD9, '9', 381, 'wt') === $STARTROW);
check('start (module options): a record id line that runs on is no start', $mod($late("record_id = '9\nx'"), $DD9, '9', 381, 'wt') === $STARTROW);
check('start (module options): ... nor one with no value at all', $mod($late("record_id = '\nwt = '1'"), $DD9, '9', 381, 'wt') === $STARTROW
    && $mod($late("record_id = '"), $DD9, '9', 381, 'wt') === $STARTROW && $mod($late("record_id = ',\nwt = '1'"), $DD9, '9', 381, 'wt') === $STARTROW,
    [$mod($late("record_id = '"), $DD9, '9', 381, 'wt'), $mod($late("record_id = ',\nwt = '1'"), $DD9, '9', 381, 'wt')]);
check('start (module options): an INSERT with no record id line is no start', $mod($late("wt = '1'"), $DD9, '9', 381, 'wt') === $UNSTARTED);
// A typed record id line never starts a record (review W3-3 kept): a value only adds lines after its own field's line.
check('start (module options): a record id line typed into a Notes value of the creating row is no start',
    $mod($late("wt = '1',\nvisit_note = 'x',\nrecord_id = '9'"), $DD9, '9', 381, 'wt') === $STARTROW);
$both = [
    $row(1, '9', 381, '20260101090000', "record_id = '9',\nwt = '69'", 'nurse', 'INSERT'),
    $row(2, '9', 381, '20260102090000', "visit_note = 'x',\nrecord_id = '9'", 'mallory', 'INSERT'),
    $row(3, '9', 381, '20260103090000', "wt = '70'", 'nurse'),
];
check('start (module options): a start older than an untrusted INSERT still starts the record', $mod($both, $DD9, '9', 381, 'wt')['state'] === 'read', $mod($both, $DD9, '9', 381, 'wt'));
$both[0][7] = 'UPDATE';
check('start (module options): an untrusted INSERT and no start: "startrow"', $mod($both, $DD9, '9', 381, 'wt') === $STARTROW, $mod($both, $DD9, '9', 381, 'wt'));
$both[0][7] = 'INSERT';
$both[1][5] = "record_id = '9'";
$both[0][5] = "visit_note = 'x',\nrecord_id = '9'";
check('start (module options): a start newer than an untrusted INSERT starts it too', $mod($both, $DD9, '9', 381, 'wt')['state'] === 'read');
// Rows of other events that only look like the record id line are not read at all (review W3-8).
$db = new FakeHistoryLog();
$db->rows[] = $row(1, 'B1', 380, '20260105090000', "record_id = 'B1',\ndob = '2026-01-05'", 'nurse', 'INSERT');
for ($i = 0; $i < 1200; $i++) $db->rows[] = $row(2 + $i, 'B1', 381, '20260106090000', "wt = '1',\n" . ($i % 2 ? 'prev_record_id' : 'recordXid') . " = 'B$i'", 'nurse');
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc, $START)->saves('B1', 380, 1, 'dob');
check('like: "prev_record_id" and "recordXid" lines at another event are not read', $values($h) === [['20260105090000', 'nurse', '2026-01-05']] && $db->queries <= 3, [$h, $db->queries]);
// More rows of other events than MAX_ROWS_READ before the start: capped, not read on for ever.
$db = new FakeHistoryLog();
$db->rows[] = $row(1, 'B1', 380, '20260105090000', "record_id = 'B1',\ndob = '2026-01-05'", 'nurse', 'INSERT');
for ($i = 0; $i < ValueHistory::MAX_ROWS_READ; $i++) $db->rows[] = $row(2 + $i, 'B1', 381, '20260106090000', "record_id = 'B1',\nwt = '$i'", 'nurse');
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc, $START)->saves('B1', 380, 1, 'dob');
check('cap: more rows of other events than MAX_ROWS_READ before the start is capped', $h['state'] === 'capped', $h['state']);
array_pop($db->rows);
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc, $START)->saves('B1', 380, 1, 'dob');
check('cap: ... one fewer, and the start is reached', $h['state'] === 'read', $h['state']);
// They do not count toward MAX_ROWS_PER_RECORD.
$db = new FakeHistoryLog();
$db->rows[] = $row(1, 'B1', 380, '20260105090000', "record_id = 'B1',\ndob = '2026-01-05'", 'nurse', 'INSERT');
for ($i = 0; $i < ValueHistory::MAX_ROWS_PER_RECORD + 10; $i++) $db->rows[] = $row(2 + $i, 'B1', 381, '20260106090000', "record_id = 'B1',\nwt = '$i'", 'nurse');
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc, $START)->saves('B1', 380, 1, 'dob');
check('cap: rows of another event do not count toward MAX_ROWS_PER_RECORD', $values($h) === [['20260105090000', 'nurse', '2026-01-05']], $h['state']);
// ... while a row with no event and a DELETE do, even one the reader passes over (review W4-8).
$counted = function (array $extra) use ($row, $utc) {
    $db = new FakeHistoryLog();
    $db->rows[] = $row(1, 'R6', 380, '20261001090000', "wt = '0'");
    $db->rows[] = $extra;
    for ($i = 1; $i < ValueHistory::MAX_ROWS_PER_RECORD; $i++) $db->rows[] = $row(2 + $i, 'R6', 380, '20261001090000', "wt = '$i'");
    return ValueHistory::forProject($db, 149, ['dob'], $utc, $utc)->saves('R6', 380, 1, 'dob')['state'];
};
check('cap: a row with no event counts toward MAX_ROWS_PER_RECORD', $counted($row(2, 'R6', null, '20261001090000', "wt = 'x'")) === 'capped');
check('cap: a DELETE of another event\'s form counts too', $counted($row(2, 'R6', 381, '20261001090000', '', 'cat', 'DELETE', 'redcap_data', 149, 'Delete all record data for single form')) === 'capped');
check('cap: ... while a row of another event does not', $counted($row(2, 'R6', 381, '20261001090000', "wt = 'x'")) === 'read');

// Deletes of another event's data (review W2-8): no part of this event's history.
$evdel = function ($descr, $event = 381, array $opts = []) use ($row, $utc) {
    $db = new FakeHistoryLog();
    $db->rows = [
        $row(1, 'R1', 380, '20261001090000', "dob = '2000-01-01'", 'ann', 'INSERT'),
        $row(2, 'R1', $event, '20261002090000', '', 'cat', 'DELETE', 'redcap_data', 149, $descr),
        $row(3, 'R1', 380, '20261003090000', "dob = '1990-09-09'", 'dan'),
    ];
    return ValueHistory::forProject($db, 149, ['dob'], $utc, $utc, $opts);
};
check('event delete: a form deleted at another event is passed over',
    $values($evdel('Delete all record data for single form')->saves('R1', 380, 1, 'dob')) === [['20261001090000', 'ann', '2000-01-01'], ['20261003090000', 'dan', '1990-09-09']]);
check('event delete: ... and makes that event\'s history unknown', $evdel('Delete all record data for single form')->saves('R1', 381, 1, 'dob')['why'] === 'delete');
check('event delete: one that names an arm cannot be placed, whatever its event', $evdel('Delete record (arm 2)')->saves('R1', 380, 1, 'dob')['why'] === 'delete');
check('event delete: a whole-record DELETE that names an event still ends the history',
    $values($evdel('Delete record')->saves('R1', 380, 1, 'dob')) === [['20261003090000', 'dan', '1990-09-09']]);
check('event delete: ... unless the project has several arms', $evdel('Delete record', 381, ['multiArm' => true])->saves('R1', 380, 1, 'dob')['why'] === 'delete');
check('event delete: this event\'s own form data deleted cannot be placed', $evdel('Delete all record data for single form', 380)->saves('R1', 380, 1, 'dob')['why'] === 'delete');
check('event delete: one event\'s data deleted at another event is passed over too',
    $evdel('Delete all record data for single event (API)')->saves('R1', 380, 1, 'dob')['state'] === 'read'
    && $evdel('Delete all data on event')->saves('R1', 380, 1, 'dob')['state'] === 'read');
// A DELETE at another event described neither as one form nor as one event may have removed the
// whole record (an import, the mobile app, a newer wording): it cannot be placed (review W3-7).
check('event delete: another description at another event cannot be placed', $evdel('Delete record via mobile app')->saves('R1', 380, 1, 'dob')['why'] === 'delete');
check('event delete: ... nor one with an empty description', $evdel('')->saves('R1', 380, 1, 'dob')['why'] === 'delete');

// The record compared as bytes before a DELETE is read (review W2-15 V2, V4), and a whole-record
// DELETE past the cap of a project of several arms (V3).
$db = new FakeHistoryLog();
$db->rows = [
    $row(1, 'R1', 380, '20261001090000', "dob = '2000-01-01'", 'ann', 'INSERT'),
    $row(2, 'r1', null, '20261002090000', '', 'cat', 'DELETE'),
];
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('bytes: a DELETE of another record whose name differs only in letter case is no part of this history',
    $values($h->saves('R1', 380, 1, 'dob')) === [['20261001090000', 'ann', '2000-01-01']], $h->saves('R1', 380, 1, 'dob'));
$cap = function ($pk, array $opts = []) use ($row, $utc) {
    $db = new FakeHistoryLog();
    $db->rows[] = $row(1, 'R5', 380, '20261001090000', "wt = '0'");
    $db->rows[] = $row(2, $pk, null, '20261001090000', '', 'x', 'DELETE');
    for ($i = 1; $i <= ValueHistory::MAX_ROWS_PER_RECORD; $i++) $db->rows[] = $row(2 + $i, 'R5', 380, '20261001090000', "wt = '$i'");
    return ValueHistory::forProject($db, 149, ['dob'], $utc, $utc, $opts)->saves('R5', 380, 1, 'dob')['state'];
};
check('cap: a whole-record DELETE just past the cap of a project of several arms cannot be placed', $cap('R5', ['multiArm' => true]) === 'capped');
check('cap: a DELETE of another record just past the cap is no start', $cap('r5') === 'capped');
check('cap: ... while its own is', $cap('R5') === 'read');
$db = new FakeHistoryLog();
for ($i = 1; $i <= ValueHistory::MAX_ROWS_PER_RECORD; $i++) $db->rows[] = $row(1 + $i, 'R5', 380, '20261001090000', "wt = '$i'");
check('cap: a history of exactly the cap where the log ends is read', ValueHistory::forProject($db, 149, ['dob'], $utc, $utc)->saves('R5', 380, 1, 'dob')['state'] === 'read');
$db->rows[] = $row(1, 'R5', 380, '20261001090000', "wt = '0'");
check('cap: ... one more row of its own, and it is capped', ValueHistory::forProject($db, 149, ['dob'], $utc, $utc)->saves('R5', 380, 1, 'dob')['state'] === 'capped');
// A row with no event that names a kept field on another instance (V5).
$db = new FakeHistoryLog();
$db->rows = [
    $row(1, 'R1', null, '20261001090000', "[instance = 2],\ndob = '2000-01-01'", 'ann'),
    $row(2, 'R1', 380, '20261003090000', "dob = '1990-09-09'", 'dan'),
];
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('noevent: a row with no event that names a kept field on instance 2 cannot be placed', $h->saves('R1', 380, 1, 'dob')['why'] === 'noevent');

// The log's data_values, row by row (ValueHistory::parseRow).
$ord = ['record_id' => 0, 'notes' => 1, 'dob' => 2, 'site' => 3, 'cb' => 4, 'wt' => 5];
$pr = function ($t, $o = null) use ($ord) { return ValueHistory::parseRow($t, $o === null ? $ord : $o); };
check('parse: one line per field', $pr("dob = '2000-01-01',\nsite = 'A'") === [1 => ['dob' => '2000-01-01', 'site' => 'A']], $pr("dob = '2000-01-01',\nsite = 'A'"));
check('parse: a notes value over several lines is read whole (review M4)', $pr("notes = 'line one\nline two',\ndob = '2000-01-01'") === [1 => ['notes' => "line one\nline two", 'dob' => '2000-01-01']],
    $pr("notes = 'line one\nline two',\ndob = '2000-01-01'"));
check('parse: a notes value whose line looks like a field is read whole', $pr("notes = 'a\nsite = 'C',\ndob = '2000-01-01'") === [1 => ['notes' => "a\nsite = 'C", 'dob' => '2000-01-01']],
    $pr("notes = 'a\nsite = 'C',\ndob = '2000-01-01'"));
check('parse: the instance line', $pr("[instance = 3],\nsite = 'A'") === [3 => ['site' => 'A']]);
check('parse: an instance line that is not the first line is a line of no known shape (V6)',
    $pr("dob = 'a',\n[instance = 2],\nsite = 'B'") === [1 => ['dob' => null, 'site' => null]], $pr("dob = 'a',\n[instance = 2],\nsite = 'B'"));
check('parse: checkbox lines of one field side by side', $pr("site = 'A',\ncb(1) = checked,\ncb(2) = unchecked,\nwt = '70'") === [1 => ['site' => 'A', 'wt' => '70']]);
check('parse: a field written twice is not plain', $pr("dob = 'a',\ndob = 'b'", []) === [1 => ['dob' => null]]);
check('parse: a value with no closing quote is not plain', $pr("site = 'A") === [1 => ['site' => null]]);
check('parse: a line of no known shape makes every field not plain', $pr("site = 'A',\nwhat is this") === [1 => ['site' => null]]);
// A value typed to look like another field's line (review M1): caught when it breaks the dictionary order.
check('parse: a line of a field that comes earlier in the dictionary makes the row not plain',
    $pr("wt = 'x',\nsite = 'C'") === [1 => ['wt' => null, 'site' => null]], $pr("wt = 'x',\nsite = 'C'"));
check('parse: a field written twice makes the row not plain when the order is known', $pr("site = 'A',\nwt = 'x',\nsite = 'C'") === [1 => ['site' => null, 'wt' => null]]);
check('parse: a field the dictionary does not hold is left out of the order', $pr("site = 'A',\nsite_complete = '2'") === [1 => ['site' => 'A', 'site_complete' => '2']]);
// The order check trusts what cannot be typed (review W2-3): only the fields from the first free-text value on are doubted.
$free = ['notes' => true, 'dob' => true, 'wt' => true];
$pf = function ($t) use ($ord, $free) { return ValueHistory::parseRow($t, $ord, $free); };
check('parse: a line out of order after no free-text value is kept (a field moved since)', $pf("site = 'A',\nnotes = 'n'") === [1 => ['site' => 'A', 'notes' => 'n']], $pf("site = 'A',\nnotes = 'n'"));
check('parse: the fields before the first free-text value are kept, the rest doubted',
    $pf("site = 'A',\nwt = 'x',\nnotes = 'n'") === [1 => ['site' => 'A', 'wt' => null, 'notes' => null]], $pf("site = 'A',\nwt = 'x',\nnotes = 'n'"));
check('parse: a forged run through a checkbox line is still caught', $pf("wt = 'x',\ncb(1) = checked,\nsite = 'C'") === [1 => ['wt' => null, 'site' => null]], $pf("wt = 'x',\ncb(1) = checked,\nsite = 'C'"));
check('parse: a forged run through a field no one types is caught from its first free-text value',
    $pf("dob = 'x',\nsite = 'C',\nnotes = 'D'") === [1 => ['dob' => null, 'site' => null, 'notes' => null]], $pf("dob = 'x',\nsite = 'C',\nnotes = 'D'"));
check('parse: in order, nothing is doubted', $pf("notes = 'n',\ndob = 'd',\nsite = 'A'") === [1 => ['notes' => 'n', 'dob' => 'd', 'site' => 'A']]);
check('parse: a stray line still makes every field not plain', $pf("site = 'A',\nwhat is this") === [1 => ['site' => null]]);
// Each line is looked at once (review W2-10): a long notes value costs one pass.
// Ten times the lines must cost about ten times the time, not a hundred.
// Either test passes it: the ratio holds on a loaded machine, where a fixed
// limit does not, and the limit holds when the small run is too quick for the
// timer to measure. A parse that walks the value once per line fails both.
$parseTime = function (int $lines) use ($ord, $free) {
    $text = "notes = 'start\n" . str_repeat("a line\n", $lines) . "end',\nsite = 'A'";
    $best = INF;
    for ($i = 0; $i < 3; $i++) {
        $t0 = microtime(true);
        $r = ValueHistory::parseRow($text, $ord, $free);
        $best = min($best, microtime(true) - $t0);
    }
    return [$best, $r];
};
[$small] = $parseTime(13000);
[$took, $r] = $parseTime(130000);
$ratio = $took / max($small, 0.0005);
check('parse: 130,000 lines of one value cost about ten times 13,000 (took ' . round($took, 2) . ' s, ' . round($ratio, 1) . ' times)', ($ratio < 30 || $took < 1.0) && $r[1]['site'] === 'A' && strlen($r[1]['notes']) === 5 + 7 * 130000 + 4);

// Paging: a record read across pages, then the cap.
$db = new FakeHistoryLog();
for ($i = 1; $i <= ValueHistory::PAGE + 20; $i++) $db->rows[] = $row($i, 'R1', 380, sprintf('202610%02d%06d', 1 + intdiv($i, 100), $i % 100), "dob = 'v$i'", 'u' . $i);
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
$r = $h->saves('R1', 380, 1, 'dob');
check('reader: every page read, oldest first', $r['state'] === 'read' && count($r['saves']) === ValueHistory::PAGE + 20
    && $r['saves'][0]['value'] === 'v1' && end($r['saves'])['value'] === 'v' . (ValueHistory::PAGE + 20), [$r['state'], count($r['saves'])]);
check('reader: the projects lookup, the erase floor, then two pages', $db->queries === 4, $db->queries);
$db = new FakeHistoryLog();
for ($i = 1; $i <= ValueHistory::MAX_ROWS_PER_RECORD + 1; $i++) $db->rows[] = $row($i, 'R1', 380, '20261001090000', "wt = '$i'");
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('reader: a log past the cap is capped, not read in part', $h->saves('R1', 380, 1, 'dob') === ['state' => 'capped', 'saves' => []]);
$db->rows[] = $row(ValueHistory::MAX_ROWS_PER_RECORD + 2, 'R2', null, '20261001090000', '', 'x', 'DELETE');
for ($i = 1; $i <= ValueHistory::MAX_ROWS_PER_RECORD; $i++) $db->rows[] = $row(ValueHistory::MAX_ROWS_PER_RECORD + 2 + $i, 'R2', 380, '20261001090000', "wt = '$i'");
check('reader: a history of exactly the cap, a whole-record DELETE just past it, is read in full', $h->saves('R2', 380, 1, 'dob') === ['state' => 'read', 'saves' => []]);
$db = new FakeHistoryLog();
for ($i = 1; $i <= ValueHistory::MAX_ROWS_PER_RECORD; $i++) $db->rows[] = $row($i, 'R3', 380, '20261001090000', "dob = 'v$i'");
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
$r = $h->saves('R3', 380, 1, 'dob');
check('reader: a history of exactly the cap and nothing past it is read in full', $r['state'] === 'read' && count($r['saves']) === ValueHistory::MAX_ROWS_PER_RECORD, [$r['state'], count($r['saves'])]);
// The cap counts the rows of one event: a long history at another event does not cap this one.
$db = new FakeHistoryLog();
for ($i = 1; $i <= ValueHistory::MAX_ROWS_PER_RECORD + 10; $i++) $db->rows[] = $row($i, 'R4', 381, '20261001090000', "dob = 'x$i'");
$db->rows[] = $row(ValueHistory::MAX_ROWS_PER_RECORD + 20, 'R4', 380, '20261002090000', "dob = 'here'");
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('reader: the cap counts one event\'s rows', $values($h->saves('R4', 380, 1, 'dob')) === [['20261002090000', 'ann', 'here']]);
check('... and the long event is capped', $h->saves('R4', 381, 1, 'dob')['state'] === 'capped');

// A failed read is unknown, never "no saves".
$db = new FakeHistoryLog();
$db->rows = [$row(1, 'R1', 380, '20261001090000', "dob = '2000-01-01'")];
$db->failOn = 2;
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('reader: a failed search for an erase is unknown', $h->saves('R1', 380, 1, 'dob') === ['state' => 'unknown', 'saves' => [], 'why' => 'read']);
// queries: the log table, the erase search, then the first page
$db = new FakeHistoryLog();
$db->rows = [$row(1, 'R1', 380, '20261001090000', "dob = '2000-01-01'")];
$db->failOn = 3;
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('reader: a failed read of a page is unknown', $h->saves('R1', 380, 1, 'dob') === ['state' => 'unknown', 'saves' => [], 'why' => 'read'], $db->sql);
// An id that is not digits cannot be paged on, nor taken for the erase floor (review W4-8).
$db = new FakeHistoryLog();
$db->rows = [$row(1, 'R1', 380, '20261001090000', "dob = '2000-01-01'", 'ann', 'INSERT'), $row('2x', 'R1', 380, '20261002090000', "dob = '2000-01-02'", 'bob')];
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('reader: a log id that is not digits is unknown', $h->saves('R1', 380, 1, 'dob') === ['state' => 'unknown', 'saves' => [], 'why' => 'read'], $h->saves('R1', 380, 1, 'dob'));
$db = $erase([]);
$db->rows[1][0] = '2e';
$h = ValueHistory::forProject($db, 149, ['dob'], $utc, $utc);
check('erase: an erase row whose id is not digits makes every history unknown', $h->saves('1', 380, 1, 'dob') === ['state' => 'unknown', 'saves' => [], 'why' => 'read'], $h->saves('1', 380, 1, 'dob'));
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

// ---- the role book (php/RoleBook.php; its SQL runs in tests/mysql/cases/history.php) ----
/** A database that answers the role queries, or refuses those naming $refuse. */
final class FakeRoleDb implements \INSPIRE\UniversalValidator\Scan\ScanDb
{
    public $refuse = null;
    public $calls = [];
    public $intl = false;
    public $spaced = false;
    public function select($sql, array $params = [])
    {
        $this->calls[] = [$sql, $params];
        if ($this->refuse !== null && strpos($sql, $this->refuse) !== false) throw new \RuntimeException('no such column');
        $unique = strpos($sql, 'unique_role_name') !== false;
        if ($this->intl) return strpos($sql, 'FROM redcap_user_roles') !== false ? [['ÉQUIPE  Médicale', '']] : [['Ann', ' Équipe   médicale ', '']];
        if ($this->spaced && strpos($sql, 'FROM redcap_user_rights') !== false) return [[' DM ', 'Data manager', 'U-3DM']];
        if (strpos($sql, 'FROM redcap_user_roles') !== false) return $unique ? [['Data manager', 'U-3DM'], [' Monitor ', '']] : [['Data manager'], [' Monitor ']];
        return $unique ? [['DM', 'Data manager', 'U-3DM'], ['', 'Monitor', 'U-4MO']] : [['DM', 'Data manager']];
    }
    public function exec($sql, array $params = []) {}
    public function execNoCount($sql, array $params = []) {}
    public function affected() { return 0; }
    public function begin() {}
    public function commit() {}
    public function rollback() {}
}
require_once __DIR__ . '/../php/RoleBook.php';
use INSPIRE\UniversalValidator\RoleBook;
$rdb = new FakeRoleDb();
$book = RoleBook::read($rdb, '149', '2026-10-22');
check('roles: both names of each role, trimmed and in lowercase', $book['roles'] === ['data manager' => true, 'u-3dm' => true, 'monitor' => true], $book);
check('roles: users by lowercase name; a row with no user name is passed over', $book['users'] === ['dm' => ['data manager', 'u-3dm']], $book);
check('roles: the project and today are bound, never written into the SQL', $rdb->calls[1][1] === [149, '2026-10-22']
    && strpos($rdb->calls[1][0], 'u.expiration > ?') !== false && strpos($rdb->calls[1][0], '2026') === false, $rdb->calls);
$rdb = new FakeRoleDb();
$rdb->refuse = 'unique_role_name';
$book = RoleBook::read($rdb, 149, '2026-10-22');
check('roles: a REDCap with no unique role names is read without them', $book === ['roles' => ['data manager' => true, 'monitor' => true], 'users' => ['dm' => ['data manager']]], $book);
$rdb = new FakeRoleDb();
$rdb->refuse = 'redcap_user_r';
check('roles: tables that cannot be read give null', RoleBook::read($rdb, 149, '2026-10-22') === null);
$rdb = new FakeRoleDb();
$rdb->spaced = true;
check('roles: a user name stored with spaces around it is kept trimmed', array_keys(RoleBook::read($rdb, 149, '2026-10-22')['users']) === ['dm'], RoleBook::read($rdb, 149, '2026-10-22'));

// Role names in any script's letter case, spaces folded (review W2-9).
check('roleKey: letter case in any script', Logic::roleKey('Équipe Médicale') === 'équipe médicale' && Logic::roleKey('ÉQUIPE MÉDICALE') === 'équipe médicale');
check('roleKey: spaces trimmed and folded, tabs too', Logic::roleKey("  Data \t  manager ") === 'data manager');
check('roleKey: a no-break or ideographic space at either end goes too (review W3-9)', Logic::roleKey("\u{A0}Data manager\u{3000}") === 'data manager' && Logic::roleKey("Data\u{A0}\u{A0}manager\u{A0}") === 'data manager');
check('roleKey: a name that is not UTF-8 is lowercased byte by byte', Logic::roleKey("Caf\xE9  Team") === "caf\xE9 team");
// Unicode normalization, format characters and full case folding (review W4-7)
check('roleKey: a zero-width space or a byte-order mark is not part of the name', Logic::roleKey("Data\u{200B} manager\u{200B}") === 'data manager'
    && Logic::roleKey("\u{FEFF}Data manager") === 'data manager' && Logic::roleKey("Data\u{2060}manager") === 'datamanager', Logic::roleKey("Data\u{200B} manager\u{200B}"));
check('roleKey: full case folding: STRASSE and Straße are one role', Logic::roleKey('STRASSE') === Logic::roleKey("Stra\u{DF}e") && Logic::roleKey('STRASSE') === 'strasse', Logic::roleKey("Stra\u{DF}e"));
if (class_exists('Normalizer', false)) {
    check('roleKey: a letter and its combining accent (NFD) are the accented letter (NFC)', Logic::roleKey("E\u{301}quipe me\u{301}dicale") === Logic::roleKey("\u{C9}quipe M\u{E9}dicale")
        && Logic::roleKey("E\u{301}quipe me\u{301}dicale") === "\u{E9}quipe m\u{E9}dicale", Logic::roleKey("E\u{301}quipe me\u{301}dicale"));
} else {
    echo "note: PHP's intl extension is not loaded, so the NFC/NFD check of roleKey is skipped (run with -d extension=intl)\n";
    check('roleKey: without intl, a combining accent is kept as typed', Logic::roleKey("E\u{301}quipe") === "e\u{301}quipe", Logic::roleKey("E\u{301}quipe"));
}
$rdb = new FakeRoleDb();
$rdb->intl = true;
$book = RoleBook::read($rdb, 149, '2026-10-22');
check('roles: names kept as role keys', isset($book['roles']['équipe médicale']) && $book['users']['ann'] === ['équipe médicale'], $book);

echo "writeonce_php: $n checks, $fails failure(s)\n";
exit($fails ? 1 : 0);
