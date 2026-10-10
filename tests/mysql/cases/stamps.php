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

use INSPIRE\UniversalValidator\ValueStamps;
use INSPIRE\UniversalValidator\Scan\Schema;
use INSPIRE\UniversalValidator\Scan\SqlScanStore;
use INSPIRE\UniversalValidator\Scan\ScanStore;

require_once __DIR__ . '/../../../php/ValueStamps.php';

$PID = 720;
uv_redcap_schema($A);
uv_redcap_project($A, $PID, 'redcap_log_event4');
uv_redcap_project($A, uv_neighbour($PID), 'redcap_log_event4');
// The columns ValueStamps reads, with REDCap's types and a case-insensitive
// collation, as a real installation has them.
$A->query('CREATE TABLE redcap_log_event4 (log_event_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL DEFAULT 0, ts BIGINT NULL, event VARCHAR(32) NULL, object_type VARCHAR(128) NULL,
    pk TEXT NULL, event_id INT NULL, data_values TEXT NULL,
    KEY (project_id), KEY (pk(191))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

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

// Over a page boundary: the save of w_past sits under 520 newer rows.
$log(200, $PID, 20260901080000, 'P-1', "w_past = '2026-09-01'");
for ($i = 1; $i <= 520; $i++) $log(200 + $i, $PID, 20260902080000 + $i, 'P-1', "wt = '" . $i . "'");
$vs = ValueStamps::forProject($dbA, $PID, $utc, $utc);
$r = $vs->savedAt('P-1', 41, 1, 'w_past', '2026-09-01');
check('stamps: found on the second page, by the log_event_id cursor (got ' . json_encode($r) . ')',
    $r === ['state' => 'logged', 'at' => '2026-09-01 08:00:00']);

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
foreach (['w_past' => '2026-10-02', 'v_end' => null] as $field => $asOf) {
    $batch['findings'][] = ['ordinal' => $c['ordinal'], 'project_id' => $PID, 'generation_id' => $gen,
        'identity' => hash('sha256', 'stamps-' . $field, true), 'valid_from_seq' => uv_run_seq($dbA, $runId),
        'record_hash' => $c['hash'], 'record_id_bin' => $c['id_bin'], 'event_id' => 41, 'instance' => 1,
        'host_form' => 'visit_form', 'field' => $field, 'rule_source_id' => 'r-' . $field,
        'rule_revision' => str_repeat('c', 64), 'rule_ord' => 1, 'check_type' => 'window',
        'reason_code' => 'past', 'as_of' => $asOf];
}
check('stamps: the batch commits', $store->commitBatch($runId, 'stamps-worker', $epoch, $batch) === true);
$rows = $ca->query('SELECT field, as_of FROM ' . Schema::table('finding') . ' WHERE project_id = ? ORDER BY field', [$PID]);
check('stamps: as_of is written, and NULL where there is none (got ' . json_encode($rows) . ')',
    $rows === [['v_end', null], ['w_past', '2026-10-02']]);
