<?php
/**
 * tests/mysql/cases/history.php — @UVWRITEONCE's two readers on a real server.
 *
 * tests/writeonce_php.php runs ValueHistory against a PHP mock of the log
 * table and RoleBook against a mock database. That proves the parsing and the
 * paging, but not the SQL. This case runs both on a real server.
 *
 * ValueHistory (php/ValueHistory.php):
 *   - the record compared as bytes, where the column's collation would match
 *     "xe-1" for "XE-1";
 *   - the event filter, which still lets every DELETE and every row with no
 *     event through;
 *   - a whole-record DELETE ending the history in a project with one arm, a
 *     DELETE of this event's data (or any DELETE where the record has more
 *     than one arm) making it unknown, and one of another event's data left
 *     out;
 *   - the record's start: an INSERT with the record id field's line read
 *     whatever its event, through the two LIKE patterns on data_values (at the
 *     start of a line, "_" escaped with ESCAPE '!'), and a history without
 *     one unknown;
 *   - the keyset cursor on log_event_id across a page boundary;
 *   - every history starting after the newest project-wide erase row (pk =
 *     the project id compared as bytes, no event, object type redcap_data,
 *     not described as a record's own DELETE or one of its forms or events).
 *
 * RoleBook (php/RoleBook.php):
 *   - roles joined to rights by role_id, not by project;
 *   - rights that expire on or before today, suspended accounts, and other
 *     projects' roles and rights left out;
 *   - a REDCap with no unique role names read without them.
 */

use INSPIRE\UniversalValidator\ValueHistory;
use INSPIRE\UniversalValidator\RoleBook;

require_once __DIR__ . '/../../../php/ValueHistory.php';
require_once __DIR__ . '/../../../php/ExactDecimal.php';
require_once __DIR__ . '/../../../php/Logic.php';
require_once __DIR__ . '/../../../php/RoleBook.php';

$PID = 730;
$NB = uv_neighbour($PID);
uv_redcap_schema($A);
uv_redcap_project($A, $PID, 'redcap_log_event4');
uv_redcap_project($A, $NB, 'redcap_log_event4');
// The columns ValueHistory reads, with REDCap's types and a case-insensitive
// collation, as a real installation has them.
$A->query('CREATE TABLE redcap_log_event4 (log_event_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL DEFAULT 0, ts BIGINT NULL, `user` VARCHAR(255) NULL, event VARCHAR(32) NULL,
    object_type VARCHAR(128) NULL, pk TEXT NULL, event_id INT NULL, data_values TEXT NULL, description TEXT NULL,
    KEY (project_id), KEY (pk(191))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$log = function ($id, $pk, $dv, array $o = []) use ($ca, $PID) {
    $o += ['pid' => $PID, 'ts' => 20260101080000 + $id, 'event' => 'UPDATE', 'eventId' => 41, 'user' => 'nurse',
           'descr' => 'Update record', 'type' => 'redcap_data'];
    $ca->query('INSERT INTO redcap_log_event4 (log_event_id, project_id, ts, `user`, event, object_type, pk, event_id, data_values, description)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, $o['pid'], $o['ts'], $o['user'], $o['event'], $o['type'], $pk, $o['eventId'], $dv, $o['descr']]);
};
$values = function (array $h) { return array_map(function ($s) { return $s['value']; }, $h['saves']); };
$users = function (array $h) { return array_map(function ($s) { return $s['user']; }, $h['saves']); };

$FIELDS = ['consent', 'site'];
$ORDER = ['opts' => ['order' => ['record_id' => 0, 'consent' => 1, 'wt' => 2, 'site' => 3]]];
$utc = new \DateTimeZone('UTC');
$reader = function (array $opts = []) use ($dbA, $PID, $FIELDS, $utc, $ORDER) {
    return ValueHistory::forProject($dbA, $PID, $FIELDS, $utc, $utc, $opts + $ORDER['opts']);
};

// ---- one record, two events, and rows that are not its saves ----------------
$log(100, 'XE-1', "consent = '2026-01-01'", ['event' => 'INSERT', 'descr' => 'Create record']);
$log(101, 'XE-1', "consent = '2026-02-02',\nsite = 'B'", ['user' => 'dm']);
$log(102, 'XE-1', "consent = '2026-09-09'", ['eventId' => 42]);
// Another record whose ID differs only in case, the neighbour's row, a rights
// change: none of them is a save of XE-1 on this project.
$log(103, 'xe-1', "consent = '2026-03-03'");
$log(104, 'XE-1', "consent = '2026-04-04'", ['pid' => $NB]);
$log(105, 'XE-1', "consent = '2026-05-05'", ['type' => 'redcap_user_rights']);

$vh = $reader();
check('history: the log table is the one redcap_projects names', $vh !== null);
$h = $vh->saves('XE-1', 41, 1, 'consent');
check('history: the saves of the record and event, oldest first, past rows that do not count (got ' . json_encode($h) . ')',
    $h['state'] === 'read' && $values($h) === ['2026-01-01', '2026-02-02'] && $users($h) === ['nurse', 'dm']);
check('history: each save keeps its time', ($h['saves'][0]['at'] ?? null) === '2026-01-01 08:01:00');
$h = $vh->saves('XE-1', 42, 1, 'consent');
check('history: the other event has its own history (got ' . json_encode($h) . ')', $h['state'] === 'read' && $values($h) === ['2026-09-09']);
$h = $vh->saves('xe-1', 41, 1, 'consent');
check('history: a record ID that differs only in case is its own record', $h['state'] === 'read' && $values($h) === ['2026-03-03']);
check('history: a record with no rows', $vh->saves('NONE-1', 41, 1, 'consent')['state'] === 'none');

// ---- deletions --------------------------------------------------------------
$log(200, 'D-1', "consent = 'old'", ['event' => 'INSERT']);
// The DELETE row is logged on another event: the event filter must let it through.
$log(201, 'D-1', "record_id = 'D-1'", ['event' => 'DELETE', 'descr' => 'Delete record', 'eventId' => 42]);
$log(202, 'D-1', "consent = 'new'", ['event' => 'INSERT']);
$h = $reader()->saves('D-1', 41, 1, 'consent');
check('history: a whole-record DELETE starts a new history (got ' . json_encode($h) . ')', $h['state'] === 'read' && $values($h) === ['new']);
$h = $reader(['multiArm' => true])->saves('D-1', 41, 1, 'consent');
check('history: ... but not where the record can be deleted from one arm only (got ' . json_encode($h) . ')',
    $h['state'] === 'unknown' && ($h['why'] ?? null) === 'delete');
$log(210, 'D-2', "consent = 'a'", ['event' => 'INSERT']);
$log(211, 'D-2', "consent = ''", ['event' => 'DELETE', 'descr' => 'Delete all data on event', 'eventId' => 42]);
$h = $reader()->saves('D-2', 42, 1, 'consent');
check('history: a DELETE of part of the record makes the history of its event unknown (got ' . json_encode($h) . ')',
    $h['state'] === 'unknown' && ($h['why'] ?? null) === 'delete' && $h['saves'] === []);
$h = $reader()->saves('D-2', 41, 1, 'consent');
check('history: ... and is no part of another event\'s (got ' . json_encode($h) . ')', $h['state'] === 'read' && $values($h) === ['a']);
$log(212, 'D-3', "consent = 'a'", ['event' => 'INSERT']);
$log(213, 'D-3', '', ['event' => 'DELETE', 'descr' => 'Delete record (arm 2)', 'eventId' => 42]);
$h = $reader()->saves('D-3', 41, 1, 'consent');
check('history: a DELETE that names an arm makes every event\'s history unknown (got ' . json_encode($h) . ')',
    $h['state'] === 'unknown' && ($h['why'] ?? null) === 'delete');

// ---- the record's start --------------------------------------------------------
$START = ['recordIdField' => 'record_id'];
$log(240, 'B-1', "record_id = 'B-1',\nconsent = 'x'", ['event' => 'INSERT']);
$log(241, 'B-1', "record_id = 'B-1'", ['eventId' => 42]);
$h = $reader($START)->saves('B-1', 41, 1, 'consent');
check('start: an UPDATE at another event that writes the record id is no rename (got ' . json_encode($h) . ')',
    $h['state'] === 'read' && $values($h) === ['x']);
// a renamed record whose first saves stay under its old name: its new name's log starts with the rename
$log(242, 'B-2', "record_id = 'B-2'", ['eventId' => 42]);
$log(243, 'B-2', "consent = 'y'");
$h = $reader($START)->saves('B-2', 41, 1, 'consent');
check('start: a history with no INSERT of the record is unknown (got ' . json_encode($h) . ')',
    $h['state'] === 'unknown' && ($h['why'] ?? null) === 'start');
$h = $reader()->saves('B-2', 41, 1, 'consent');
check('start: ... which a reader not told the record id field does not ask', $h['state'] === 'read' && $values($h) === ['y']);
// the INSERT at another event, found by the LIKE: at the start of the row, or after the instance line
$log(244, 'S-1', "record_id = 'S-1'", ['event' => 'INSERT', 'eventId' => 42]);
$log(245, 'S-1', "consent = 's1'");
$log(246, 'S-2', "[instance = 2],\nrecord_id = 'S-2'", ['event' => 'INSERT', 'eventId' => 42]);
$log(247, 'S-2', "consent = 's2'");
$h = $reader($START)->saves('S-1', 41, 1, 'consent');
check('start: an INSERT at another event starts the history (got ' . json_encode($h) . ')', $h['state'] === 'read' && $values($h) === ['s1']);
$h = $reader($START)->saves('S-2', 41, 1, 'consent');
check('start: ... after an instance line too (got ' . json_encode($h) . ')', $h['state'] === 'read' && $values($h) === ['s2']);
// lines that only look like the record id field's are not read: "_" is no wildcard, and the name starts the line
$log(248, 'S-3', "recordXid = 'S-3'", ['event' => 'INSERT', 'eventId' => 42]);
$log(249, 'S-3', "prev_record_id = 'S-3'", ['event' => 'INSERT', 'eventId' => 42]);
$log(250, 'S-3', "consent = 's3'");
$h = $reader($START)->saves('S-3', 41, 1, 'consent');
check('start: "recordXid" and "prev_record_id" lines at another event are not the start (got ' . json_encode($h) . ')',
    $h['state'] === 'unknown' && ($h['why'] ?? null) === 'start');

// ---- rows with no event ----------------------------------------------------
$log(220, 'N-1', "consent = 'a'", ['event' => 'INSERT']);
$log(221, 'N-1', "consent = 'z'", ['eventId' => null]);
$h = $reader()->saves('N-1', 41, 1, 'consent');
check('history: a row with no event that names a kept field makes the history unknown (got ' . json_encode($h) . ')',
    $h['state'] === 'unknown' && ($h['why'] ?? null) === 'noevent');
$log(230, 'N-2', "consent = 'a'", ['event' => 'INSERT']);
$log(231, 'N-2', "wt = '3'", ['eventId' => null]);
$h = $reader()->saves('N-2', 41, 1, 'consent');
check('history: one that names no kept field is passed over (got ' . json_encode($h) . ')', $h['state'] === 'read' && $values($h) === ['a']);

// ---- over a page boundary ----------------------------------------------------
$log(300, 'P-1', "consent = 'first'", ['event' => 'INSERT']);
for ($i = 1; $i <= 520; $i++) $log(300 + $i, 'P-1', "wt = '" . $i . "'");
$h = $reader()->saves('P-1', 41, 1, 'consent');
check('history: the first save found on the second page, by the log_event_id cursor (got ' . json_encode($h['state']) . ')',
    $h['state'] === 'read' && $values($h) === ['first']);

// ---- a project-wide erase (last: every reader made after it starts there) --------
$log(1000, 'R-7', "consent = 'dev'", ['event' => 'INSERT', 'user' => 'builder']);
// a record named like the project: its own whole-record DELETE, and a DELETE of one event's data
$log(1001, (string) $PID, '', ['event' => 'DELETE', 'descr' => 'Delete record', 'eventId' => null]);
$log(1002, (string) $PID, '', ['event' => 'DELETE', 'descr' => 'Delete all record data for single event', 'eventId' => 41]);
// the neighbour project's erase
$log(1003, (string) $NB, '', ['event' => 'DELETE', 'descr' => 'Erase all data', 'eventId' => null, 'pid' => $NB]);
$h = $reader()->saves('R-7', 41, 1, 'consent');
check('erase: a record named like the project, deleted whole or in part, is no erase, nor is another project\'s (got ' . json_encode($h) . ')',
    $h['state'] === 'read' && $values($h) === ['dev']);
// an older erase, before R-7 was first made: only the newest counts
$log(900, (string) $PID, 'project_id = ' . $PID, ['event' => 'DELETE', 'descr' => 'Erase all data', 'eventId' => null, 'user' => 'builder']);
$log(1004, (string) $PID, 'project_id = ' . $PID, ['event' => 'DELETE', 'descr' => null, 'eventId' => null, 'user' => 'builder']);
$log(1005, 'R-7', "consent = 'prod'", ['event' => 'INSERT']);
$h = $reader()->saves('R-7', 41, 1, 'consent');
check('erase: a record made again after the erase starts a new history (got ' . json_encode($h) . ')', $h['state'] === 'read' && $values($h) === ['prod']);
check('erase: a record whose saves all came before it has none', $reader()->saves('XE-1', 41, 1, 'consent')['state'] === 'none');
// rows after it that are no erase: a record "730 " or "７３０" (equal to "730" under the column's
// collation, not as bytes), one form of a record "730" deleted with no event, another object type,
// and a DELETE that names an event
$log(1006, 'R-8', "consent = 'r8'", ['event' => 'INSERT']);
$log(1007, $PID . ' ', 'project_id = ' . $PID, ['event' => 'DELETE', 'descr' => 'Erase all data', 'eventId' => null]);
$log(1008, "\u{FF17}\u{FF13}\u{FF10}", 'project_id = ' . $PID, ['event' => 'DELETE', 'descr' => 'Erase all data', 'eventId' => null]);
$log(1009, (string) $PID, '', ['event' => 'DELETE', 'descr' => 'Delete all record data for single form', 'eventId' => null]);
$log(1010, (string) $PID, 'project_id = ' . $PID, ['event' => 'DELETE', 'descr' => 'Erase all data', 'eventId' => null, 'type' => 'redcap_user_rights']);
$log(1011, (string) $PID, 'project_id = ' . $PID, ['event' => 'DELETE', 'descr' => 'Erase all data', 'eventId' => 41]);
$h = $reader()->saves('R-8', 41, 1, 'consent');
check('erase: the project id compared as bytes, one form of a record "730", another object type and a named event are no erase (got ' . json_encode($h) . ')',
    $h['state'] === 'read' && $values($h) === ['r8']);

// ---- RoleBook ------------------------------------------------------------------
$A->query('CREATE TABLE redcap_user_roles (role_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, project_id INT NULL,
    role_name TEXT NULL, unique_role_name VARCHAR(50) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$A->query('CREATE TABLE redcap_user_rights (project_id INT NOT NULL, username VARCHAR(191) NOT NULL, expiration DATE NULL,
    role_id INT NULL, PRIMARY KEY (project_id, username)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$A->query('CREATE TABLE redcap_user_information (ui_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, username VARCHAR(191) NULL,
    user_suspended_time DATETIME NULL, UNIQUE KEY (username)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
foreach ([[1, $PID, 'Data manager', 'U-3DM'], [2, $PID, 'Monitor', 'U-4MO'], [3, $NB, 'Auditor', 'U-5AU']] as $r) {
    $ca->query('INSERT INTO redcap_user_roles (role_id, project_id, role_name, unique_role_name) VALUES (?, ?, ?, ?)', $r);
}
$TODAY = '2026-10-22';
foreach ([
    [$PID, 'dm', null, 1],                // holds Data manager
    [$PID, 'fut', '2999-12-31', 2],       // rights that expire later: holds Monitor
    [$PID, 'gone', '2000-01-01', 2],      // rights that expired
    [$PID, 'lastday', $TODAY, 1],         // rights that expire today: REDCap has already shut them
    [$PID, 'sus', null, 1],               // a suspended account
    [$PID, 'noinfo', null, 1],            // no user_information row: not suspended
    [$PID, 'plain', null, null],          // no role
    [$NB, 'dm', null, 3],                 // another project's role
    [$NB, 'aud', null, 3],
] as $r) {
    $ca->query('INSERT INTO redcap_user_rights (project_id, username, expiration, role_id) VALUES (?, ?, ?, ?)', $r);
}
foreach ([['dm', null], ['fut', null], ['gone', null], ['lastday', null], ['sus', '2026-01-01 00:00:00'], ['plain', null], ['aud', null]] as $r) {
    $ca->query('INSERT INTO redcap_user_information (username, user_suspended_time) VALUES (?, ?)', $r);
}
$book = RoleBook::read($dbA, $PID, $TODAY);
$roles = array_keys($book['roles'] ?? []);
sort($roles);
check('roles: the project\'s roles by both names, and no other project\'s (got ' . json_encode($roles) . ')',
    $roles === ['data manager', 'monitor', 'u-3dm', 'u-4mo']);
$users = $book['users'] ?? [];
ksort($users);
check('roles: each user holds the role of their own rights row, and only the users whose rights count (got ' . json_encode($users) . ')',
    $users === ['dm' => ['data manager', 'u-3dm'], 'fut' => ['monitor', 'u-4mo'], 'noinfo' => ['data manager', 'u-3dm']]);

$A->query('ALTER TABLE redcap_user_roles DROP COLUMN unique_role_name');
$book = RoleBook::read($dbA, $PID, $TODAY);
$roles = array_keys($book['roles'] ?? []);
sort($roles);
check('roles: a REDCap with no unique role names is read without them (got ' . json_encode($book) . ')',
    $roles === ['data manager', 'monitor'] && ($book['users']['dm'] ?? null) === ['data manager']);
$A->query('DROP TABLE redcap_user_rights');
check('roles: tables that cannot be read give null', RoleBook::read($dbA, $PID, $TODAY) === null);
