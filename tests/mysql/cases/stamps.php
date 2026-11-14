<?php
/**
 * tests/mysql/cases/stamps.php — the day a value was saved, on a real server.
 *
 * The @UVWINDOW audit and scan read REDCap's log for the day each value was
 * saved (php/ValueStamps.php). tests/value_stamps_php.php runs that reader
 * against a PHP mock of the log table, which proves the parsing and the paging
 * logic but not the SQL. This case runs the same query on a real server:
 *
 *   - the table redcap_projects.log_event_table names;
 *   - the record ID compared as bytes, where the column's collation would
 *     match "xe-1" for "XE-1";
 *   - the object_type and event filters, and the project predicate;
 *   - the keyset cursor on log_event_id across a page boundary.
 *
 * It also checks that a durable finding keeps the day it was judged against
 * (the as_of column), from the write to the read.
 */

if (PHP_SAPI !== 'cli') {
    // A test or a tool, never a page: answer a web request with 404 and stop.
    http_response_code(404);
    exit;
}

use INSPIRE\UniversalValidator\ValueStamps;
use INSPIRE\UniversalValidator\Scan\Schema;
use INSPIRE\UniversalValidator\Scan\SqlScanStore;
use INSPIRE\UniversalValidator\Scan\ScanStore;

require_once __DIR__ . '/../../../php/ValueStamps.php';

$PID = 720;
uv_redcap_schema($A);
uv_redcap_project($A, $PID, 'redcap_log_event4');
uv_redcap_project($A, uv_neighbour($PID), 'redcap_log_event4');
// The columns ValueStamps reads, with REDCap's types, a case-insensitive
// collation and the indexes REDCap's install.sql gives the log table (WIN-3):
// none leads with (project_id, pk); "pk" holds the first 191 characters of
// the record ID. The plan below is asserted against these, not against a
// set of keys made for the test.
$A->query("CREATE TABLE redcap_log_event4 (log_event_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL DEFAULT 0, ts BIGINT NULL, `user` VARCHAR(255) NULL,
    event ENUM('UPDATE','INSERT','DELETE','SELECT','ERROR','LOGIN','LOGOUT','OTHER','DATA_EXPORT','DOC_UPLOAD','DOC_DELETE','MANAGE','LOCK_RECORD','ESIGNATURE') NULL,
    object_type VARCHAR(128) NULL, pk TEXT NULL, event_id INT NULL, data_values TEXT NULL,
    KEY user_project (project_id, `user`(191)), KEY event_project (event, project_id), KEY object_type (object_type),
    KEY ts (ts), KEY pk (pk(191))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$log = function ($id, $pid, $ts, $pk, $dv, $event = 'UPDATE', $type = 'redcap_data', $eventId = 41) use ($ca) {
    $ca->query('INSERT INTO redcap_log_event4 (log_event_id, project_id, ts, event, object_type, pk, event_id, data_values)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$id, $pid, $ts, $event, $type, $pk, $eventId, $dv]);
};
$log(100, $PID, 20261001080000, 'XE-1', "w_past = '2026-10-01',\nwt = '1'");
// Another record whose ID differs only in case: never read for XE-1.
$log(101, $PID, 20261002080000, 'xe-1', "w_past = '2026-10-05'");
// A notes value with a line shaped like an entry: not a save of w_past.
$log(102, $PID, 20261003080000, 'XE-1', "notes = 'first\nw_past = '2026-10-09'\nlast',\nwt = '2'");
// The neighbour's newer row, a rights change and a deletion: none of them count.
$log(103, uv_neighbour($PID), 20261004080000, 'XE-1', "w_past = '2026-10-09'");
$log(104, $PID, 20261005080000, 'XE-1', "w_past = '2026-10-09'", 'UPDATE', 'redcap_user_rights');
$log(105, $PID, 20261006080000, 'XE-1', "w_past = '2026-10-09'", 'DELETE');

$utc = new \DateTimeZone('UTC');
$vs = ValueStamps::forProject($dbA, $PID, $utc, $utc);
check('stamps: the log table is the one redcap_projects names', $vs !== null);
$r = $vs->savedAt('XE-1', 41, 1, 'w_past', '2026-10-01');
check('stamps: the newest save of the value, read past rows that do not count (got ' . json_encode($r) . ')',
    $r === ['state' => 'logged', 'at' => '2026-10-01 08:00:00']);
$r = $vs->savedAt('xe-1', 41, 1, 'w_past', '2026-10-05');
check('stamps: a record ID that differs only in case is its own record', $r === ['state' => 'logged', 'at' => '2026-10-02 08:00:00']);
check('stamps: a field the log never shows', $vs->savedAt('XE-1', 41, 1, 'w_never', 'x')['state'] === 'unlogged');
check('stamps: a record with no rows', $vs->savedAt('NONE-1', 41, 1, 'w_past', 'x')['state'] === 'none');

// A save made in a data access group logs the group on a line of its own (WIN-1).
$log(110, $PID, 20261007080000, 'DAG-1', "record_id = 'DAG-1',\nw_past = '2026-10-07',\n__GROUPID__ = '12'");
$vs = ValueStamps::forProject($dbA, $PID, $utc, $utc);
$r = $vs->savedAt('DAG-1', 41, 1, 'w_past', '2026-10-07');
check('stamps: a save logged with its data access group is a stamp (got ' . json_encode($r) . ')',
    $r === ['state' => 'logged', 'at' => '2026-10-07 08:00:00']);

// Over a page boundary: the save of w_past sits under 520 newer rows.
$log(200, $PID, 20260901080000, 'P-1', "w_past = '2026-09-01'");
for ($i = 1; $i <= 520; $i++) $log(200 + $i, $PID, 20260902080000 + $i, 'P-1', "wt = '" . $i . "'");
$vs = ValueStamps::forProject($dbA, $PID, $utc, $utc);
$r = $vs->savedAt('P-1', 41, 1, 'w_past', '2026-09-01');
check('stamps: found on the second page, by the log_event_id cursor (got ' . json_encode($r) . ')',
    $r === ['state' => 'logged', 'at' => '2026-09-01 08:00:00']);

// THE PLAN (WIN-3), of the statements the reader sent, on a log where this
// project's records are a small part: 3,000 rows of the neighbour, newer than
// every row read here. Without the hint a server may walk the primary key
// backwards for the ORDER BY ... LIMIT, through all of them.
$filler = [];
for ($i = 1; $i <= 3000; $i++) {
    $filler[] = '(' . (1000 + $i) . ', ' . uv_neighbour($PID) . ", 20261101080000, 'UPDATE', 'redcap_data', 'N-" . ($i % 300) . "', 41, 'wt')";
}
$A->query('INSERT INTO redcap_log_event4 (log_event_id, project_id, ts, event, object_type, pk, event_id, data_values) VALUES ' . implode(', ', $filler));
$A->query('ANALYZE TABLE redcap_log_event4');
$rec = new UvRecordingDb($dbA);
$vs = ValueStamps::forProject($rec, $PID, $utc, $utc);
$r = $vs->savedAt('P-1', 41, 1, 'w_past', '2026-09-01');
$sent = $rec->matching('select', ['FROM redcap_log_event4']);
check('stamps: the reads name the pk index read from the server (got ' . json_encode(array_column($sent, 1)) . ')',
    $r['state'] === 'logged' && count($sent) === 2 && strpos($sent[0][1], 'FROM redcap_log_event4 FORCE INDEX (`pk`) WHERE project_id = ? AND pk = ?') !== false);
foreach ($sent as $i => $q) {
    $ex = $A->query('EXPLAIN ' . uv_inline_params($A, $q[1], $q[2]))->fetch_assoc();
    check('stamps: page ' . ($i + 1) . ' of one record is read through the pk index, not the whole log (got '
        . json_encode([$ex['key'], $ex['rows'], $ex['Extra']]) . ')', $ex['key'] === 'pk' && (int) $ex['rows'] < 1500);
}
// A scan names its records, and they are read in one statement.
$rec = new UvRecordingDb($dbA);
$vs = ValueStamps::forProject($rec, $PID, $utc, $utc);
$vs->expect(['XE-1', 'xe-1', 'DAG-1', 'NONE-1']);
$got = [$vs->savedAt('XE-1', 41, 1, 'w_past', '2026-10-01'), $vs->savedAt('xe-1', 41, 1, 'w_past', '2026-10-05'),
        $vs->savedAt('DAG-1', 41, 1, 'w_past', '2026-10-07'), $vs->savedAt('NONE-1', 41, 1, 'w_past', 'x')];
$sent = $rec->matching('select', ['FROM redcap_log_event4']);
check('stamps: four records a scan named are read in one statement, each its own (got ' . json_encode($got) . ')',
    count($sent) === 1 && strpos($sent[0][1], 'pk IN (?, ?, ?, ?)') !== false
    && $got === [['state' => 'logged', 'at' => '2026-10-01 08:00:00'], ['state' => 'logged', 'at' => '2026-10-02 08:00:00'],
                 ['state' => 'logged', 'at' => '2026-10-07 08:00:00'], ['state' => 'none', 'at' => null]]);
$ex = $A->query('EXPLAIN ' . uv_inline_params($A, $sent[0][1], $sent[0][2]))->fetch_assoc();
check('stamps: ... through the pk index (got ' . json_encode([$ex['key'], $ex['rows'], $ex['Extra']]) . ')',
    $ex['key'] === 'pk' && (int) $ex['rows'] < 1500);
// What the server does with the same read and no hint is not asserted: it is
// the server's choice, and the reason the hint is there.
$A->query('DELETE FROM redcap_log_event4 WHERE log_event_id > 1000');

// An installation at version 2 (no as_of, no row for any later version, and
// none of the later versions' columns) gains the column on the way up.
$A->query('ALTER TABLE ' . Schema::table('finding') . ' DROP COLUMN as_of');
$A->query('ALTER TABLE ' . Schema::table('unique_candidate') . ' DROP COLUMN lookup');
$A->query('ALTER TABLE ' . Schema::table('finding') . ' DROP COLUMN z_score');
$A->query('ALTER TABLE ' . Schema::table('finding') . ' DROP COLUMN growth_reference');
$A->query('ALTER TABLE ' . Schema::table('finding') . ' DROP COLUMN justified');
$A->query('ALTER TABLE ' . Schema::table('scan_run') . ' DROP COLUMN detail_justified');
$A->query('DELETE FROM ' . Schema::table('schema_version') . ' WHERE version > 2');
$mig = Schema::migrate($ca);
check('stamps: an installation at version 2 migrates to 3 and on (got ' . json_encode($mig) . ')',
    $mig['ok'] === true && $mig['from'] === 2 && $mig['to'] === Schema::VERSION
    && $mig['applied'] === count(Schema::statements(3)) + count(Schema::statements(4)) + count(Schema::statements(5))
        + count(Schema::statements(6)) + count(Schema::statements(7)) + count(Schema::statements(9)));
check('stamps: and health says so', Schema::health($ca)['ok'] === true);

// A durable finding keeps the day it was judged against.
$cols = $ca->query('SELECT COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [Schema::table('finding'), 'as_of']);
check('stamps: the finding table has as_of', count($cols) === 1);
$store = new SqlScanStore($dbA);
$started = $store->startRun($PID, ['created_by' => 'stamps-case']);
check('stamps: a run starts', $started['ok'] === true);
$runId = (int) $started['run']['run_id'];
$gen = (int) $started['run']['generation_id'];
$store->writeManifest($runId, [['id_bin' => 'XE-1', 'hash' => hash('sha256', 'XE-1', true), 'dag' => null]]);
$epoch = (int) $store->run($PID, $runId)['lease_epoch'];
$claim = $store->claim($runId, 'stamps-worker', $epoch, 1);
check('stamps: the record is claimed', is_array($claim) && count($claim) === 1);
$c = $claim[0];
$batch = ['bytes' => 0, 'records' => [['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
    'state' => ScanStore::REC_DONE, 'version' => 'v1', 'claim' => $c['claim']]], 'findings' => []];
foreach (['w_past' => '2026-10-02', 'v_end' => null, 'x_wt' => null, 'y_wt' => null] as $field => $asOf) {
    $batch['findings'][] = ['ordinal' => $c['ordinal'], 'project_id' => $PID, 'generation_id' => $gen,
        'identity' => hash('sha256', 'stamps-' . $field, true), 'valid_from_seq' => uv_run_seq($dbA, $runId),
        'record_hash' => $c['hash'], 'record_id_bin' => $c['id_bin'], 'event_id' => 41, 'instance' => 1,
        'host_form' => 'visit_form', 'field' => $field, 'rule_source_id' => 'r-' . $field,
        'rule_revision' => str_repeat('c', 64), 'rule_ord' => 1, 'check_type' => 'window',
        'reason_code' => 'past', 'as_of' => $asOf]
        // x_wt keeps its value preview; y_wt has none, so its z-score never reads back
        + ($field === 'x_wt' ? ['value_bin' => '25', 'z_score' => '-3.40', 'growth_reference' => 'who-wfa'] : [])
        + ($field === 'y_wt' ? ['z_score' => '-1.00', 'growth_reference' => 'who-wfa'] : []);
}
check('stamps: the batch commits', $store->commitBatch($runId, 'stamps-worker', $epoch, $batch) === true);
$rows = $ca->query('SELECT field, as_of, z_score, growth_reference FROM ' . Schema::table('finding') . ' WHERE project_id = ? ORDER BY field', [$PID]);
check('stamps: as_of, z_score and growth_reference are written, and NULL where there is none (got ' . json_encode($rows) . ')',
    $rows === [['v_end', null, null, null], ['w_past', '2026-10-02', null, null], ['x_wt', null, '-3.40', 'who-wfa'],
               ['y_wt', null, '-1.00', 'who-wfa']]);
// read back by the report's page query, each row keyed by FindingRowMapper::INPUT_KEYS
$page = $store->findings($PID, $gen, ['dag' => null], [], 0, 10);
$byField = function ($f) use ($page) {
    return array_values(array_filter($page, function ($r) use ($f) { return $r['field'] === $f; }));
};
$px = $byField('x_wt');
check('stamps: the report reads z_score and growth_reference back (got ' . json_encode($px) . ')',
    count($px) === 1 && $px[0]['z_score'] === '-3.40' && $px[0]['growth_reference'] === 'who-wfa');
$py = $byField('y_wt');
check('stamps: ... and no z-score where there is no value preview (got ' . json_encode($py) . ')',
    count($py) === 1 && $py[0]['z_score'] === null && $py[0]['growth_reference'] === 'who-wfa');
$pw = $byField('w_past');
check('stamps: ... and the day a @UVWINDOW value was judged against (got ' . json_encode($pw) . ')',
    count($pw) === 1 && $pw[0]['as_of'] === '2026-10-02');
