<?php
/**
 * tests/mysql/cases/lookup_index.php — Scan\LookupIndex over REDCap-shaped tables.
 *
 * The live @UVUNIQUE and @UVEXISTS lookups ask the index which records may
 * hold a value, then confirm those records against the data table
 * (FieldValues::recordsAmong). The index is only worth having if it is never
 * short: a record that holds the value and is missing from it is a duplicate
 * let through. So this case changes data the ways REDCap does, writing the log
 * row REDCap writes, and asks after each change; and it pins the cases the
 * index is documented NOT to see on its own (a row that appears below the
 * window, a write with no log row) together with the rebuild that catches them.
 *
 * Each `new LookupIndex` is one request: a request syncs a project once and
 * builds at most one field.
 */

if (PHP_SAPI !== 'cli') {
    // A test or a tool, never a page: answer a web request with 404 and stop.
    http_response_code(404);
    exit;
}

use INSPIRE\UniversalValidator\Scan\LookupIndex;
use INSPIRE\UniversalValidator\Scan\FieldValues;
use INSPIRE\UniversalValidator\Scan\Schema;

/** The secret this case's keys are hashed with (LookupIndex option "keySecret", FRM-7). */
function uv_lix_secret() { return str_repeat('7c', 32); }
/** $o with that secret, for every index this case builds. */
function uv_lix_opts(array $o = array()) { return $o + array('keySecret' => uv_lix_secret()); }

$PID = 900;
$NEIGHBOUR = uv_neighbour($PID);

{
    uv_redcap_schema($A);
    uv_redcap_log_schema($A);
    // The two log columns the index reads besides pk: REDCap's ts (written
    // from PHP's clock as YYYYMMDDHHMMSS) and object_type, with ts indexed as
    // REDCap indexes it.
    // And data_values, which holds a renamed record's other id (UNQ-6).
    $A->query('ALTER TABLE redcap_log_event ADD COLUMN ts BIGINT NULL, ADD COLUMN object_type VARCHAR(128) NULL,
        ADD COLUMN data_values TEXT NULL, ADD KEY ts (ts)');
    uv_redcap_project($A, $PID);
    uv_redcap_project($A, $NEIGHBOUR);
    // The data dictionary, as far as the index reads it: which fields a
    // project holds, and which is first (the record ID field).
    // Not one of the runner's tables: made and dropped here.
    $A->query('DROP TABLE IF EXISTS redcap_metadata');
    $A->query('DROP TABLE IF EXISTS redcap_log_event9');
    $A->query('CREATE TABLE redcap_metadata (project_id INT NOT NULL, field_name VARCHAR(100) NOT NULL, field_order FLOAT NULL,
        PRIMARY KEY (project_id, field_name), KEY project_id_fieldorder (project_id, field_order)) ENGINE=InnoDB');
    foreach (array($PID, $NEIGHBOUR, 950) as $p) {
        foreach (array('record_id', 'tok', 'scan', 'num', 'label_only', 'big', 'big2', 'big3', 'big4') as $o => $f) {
            $A->query("INSERT INTO redcap_metadata (project_id, field_name, field_order) VALUES ($p, '$f', " . ($o + 1) . ')');
        }
    }

    $old = date('YmdHis', time() - 2 * 86400);
    $logId = 0;
    $log = function ($pid, $rec, $type = 'redcap_data', $id = null, $ts = null) use ($A, &$logId) {
        if ($id === null) { $logId += 10; $id = $logId; }
        $A->query(sprintf("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event, ts, object_type)
            VALUES (%d, %d, %s, 'UPDATE', %s, '%s')", $id, $pid,
            $rec === null ? 'NULL' : "'" . $A->real_escape_string($rec) . "'", $ts === null ? date('YmdHis') : $ts, $type));
        return $id;
    };
    $put = function ($pid, $rec, $field, $value, $event = 1) use ($A) {
        $A->query(sprintf("INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`) VALUES (%d, %d, '%s', '%s', '%s')",
            $pid, $event, $A->real_escape_string($rec), $field, $A->real_escape_string($value)));
    };
    $set = function ($pid, $rec, $field, $value) use ($A) {
        $A->query(sprintf("UPDATE redcap_data SET `value` = '%s' WHERE project_id = %d AND record = '%s' AND field_name = '%s'",
            $A->real_escape_string($value), $pid, $A->real_escape_string($rec), $field));
    };

    // 300 records a project, saved two days ago: tok, scan and num. Their
    // log rows start at 100, leaving room below for a row that turns up late.
    $logId = 99;
    $rows = [];
    $logs = [];
    foreach (array($PID, $NEIGHBOUR) as $p) {
        for ($i = 1; $i <= 300; $i++) {
            $rec = 'R' . $i;
            $rows[] = "($p, 1, '$rec', 'record_id', '$rec')";
            $rows[] = "($p, 1, '$rec', 'tok', 'T-$i')";
            $rows[] = "($p, 1, '$rec', 'scan', 'S-$i')";
            $rows[] = "($p, 1, '$rec', 'num', '" . (1000 + $i) . "')";
            $logs[] = '(' . (++$logId) . ", $p, '$rec', 'INSERT', $old, 'redcap_data')";
        }
    }
    $A->query('INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`) VALUES ' . implode(', ', $rows));
    $A->query('INSERT INTO redcap_log_event (log_event_id, project_id, pk, event, ts, object_type) VALUES ' . implode(', ', $logs));
    // What a lookup has to see: spaces and letter case around a value, the
    // value in a second field and in another event, numbers written three
    // ways, and the same value in the neighbour project.
    $put($PID, 'S1', 'tok', '  dup-1 ');
    $put($PID, 'S2', 'scan', 'DUP-1');
    $put($PID, 'S3', 'tok', 'DUP-1', 2);
    $put($PID, 'S4', 'num', '7');
    $put($PID, 'S5', 'num', '7.0');
    $put($PID, 'S6', 'num', '007');
    $put($NEIGHBOUR, 'N1', 'tok', 'DUP-1');
    foreach (array('S1', 'S2', 'S3', 'S4', 'S5', 'S6') as $r) $log($PID, $r, 'redcap_data', null, $old);
    $log($NEIGHBOUR, 'N1', 'redcap_data', null, $old);
    $A->query('ANALYZE TABLE redcap_data');
    $A->query('ANALYZE TABLE redcap_log_event');
    // Everything so far is older than the window; the rows below are recent.
    $logId = 5000;

    $mod = new class($A) {
        public $log = array();
        public $inner;
        /** @var callable|null called after each statement, with it */
        public $after = null;
        public function __construct($c) { $this->inner = new UvFlakyModule($c); }
        public function query($sql, $params = array()) {
            $this->log[] = array($sql, $params);
            $r = $this->inner->query($sql, $params);
            if ($this->after !== null) call_user_func($this->after, $sql, $params);
            return $r;
        }
    };
    $sorted = function ($a) { if (is_array($a)) sort($a); return $a; };
    $ask = function (array $fields, $value) use ($mod, $PID, $sorted) {
        return $sorted((new LookupIndex($mod, uv_lix_opts()))->candidates($PID, $fields, $value));
    };
    $field = function ($f) use ($A, $PID) {
        $r = $A->query("SELECT spec, ready, built_at FROM " . Schema::table('lookup_field')
            . " WHERE project_id = $PID AND field_name = '$f'")->fetch_row();
        return $r ? array('spec' => $r[0], 'ready' => (int) $r[1], 'built' => (int) $r[2]) : null;
    };
    $statements = function ($needle) use ($mod) {
        return array_values(array_filter($mod->log, function ($q) use ($needle) { return strpos($q[0], $needle) !== false; }));
    };

    // ---- the first lookup builds the field ------------------------------------------
    $got = $ask(array('tok' => null), 'dup-1');
    check('lookup index: the first lookup builds [tok] and answers (got ' . json_encode($got) . ')', $got === array('S1', 'S3'));
    $st = $field('tok');
    check('lookup index: [tok] is built, keyed as text (got ' . json_encode($st) . ')',
        $st !== null && $st['ready'] === 1 && $st['spec'] === LookupIndex::specOf(uv_lix_secret(), null) && $st['built'] > 0);
    $keys = (int) $A->query('SELECT COUNT(*) FROM ' . Schema::table('lookup_key') . " WHERE project_id = $PID AND field_name = 'tok'")->fetch_row()[0];
    check('lookup index: one key per record holding [tok], none of the neighbour\'s (got ' . $keys . ')', $keys === 302);
    check('lookup index: a value saved nowhere has no candidate', $ask(array('tok' => null), 'NOPE') === array());

    $mod->log = array();
    $got = $ask(array('tok' => null), 'DUP-1');
    $read = $statements('SELECT DISTINCT HEX(record)');
    check('lookup index: a built field answers from one key read (got ' . count($read) . ')', count($read) === 1);
    $cols = $read ? uv_index_columns($A, Schema::table('lookup_key'), uv_explain_key($A, $read[0][0], $read[0][1])) : array();
    check('lookup index: the key read uses the primary key (plan '
        . json_encode($read ? uv_explain_rows($A, $read[0][0], $read[0][1]) : null) . ')',
        array_slice($cols, 0, 3) === array('project_id', 'field_name', 'key_hash'));
    check('lookup index: and reads no row of the data table', $statements('FROM redcap_data') === array());
    // With nothing new in the log: the two table names, the lock, the log's
    // two ends, the sync row, the field states, the key read and the unlock.
    // No write. (The window is empty here: every log row is older than it.
    // A window with rows adds one statement that counts them all.)
    $writes = function () use ($mod) {
        return array_values(array_filter($mod->log, function ($q) { return preg_match('/^\s*(INSERT|UPDATE|DELETE)/i', $q[0]) === 1; }));
    };
    $n = count($mod->log);
    check('lookup index: a lookup with nothing new in the log sends 8 statements and writes nothing (got ' . $n . ', '
        . count($writes()) . ' writes)', $n === 8 && $writes() === array());
    $ix = new LookupIndex($mod, uv_lix_opts());
    $ix->candidates($PID, array('tok' => null), 'DUP-1');
    $mod->log = array();
    $got = $sorted($ix->candidates($PID, array('tok' => null), 'dup-1'));
    check('lookup index: a second lookup in the same request reads the log no more (got ' . count($mod->log) . ' statements)',
        $got === array('S1', 'S3') && $statements('redcap_log_event') === array() && count($mod->log) === 4);

    // ---- one build a request ---------------------------------------------------------
    check('lookup index: two unbuilt fields in one request: one is built, no answer yet',
        $ask(array('scan' => null, 'num' => 'point'), 'DUP-1') === null);
    $got = $ask(array('scan' => null, 'num' => 'point'), 'DUP-1');
    check('lookup index: the next request builds the other and answers (got ' . json_encode($got) . ')', $got === array('S2'));
    check('lookup index: [num] is keyed by number', $field('num')['spec'] === LookupIndex::specOf(uv_lix_secret(), 'point'));
    $got = $ask(array('num' => 'point'), '7.00');
    check('lookup index: 7, 7.0 and 007 are one number (got ' . json_encode($got) . ')', $got === array('S4', 'S5', 'S6'));
    $got = $ask(array('tok' => null, 'scan' => null), 'dup-1');
    check('lookup index: in either of two fields (got ' . json_encode($got) . ')', $got === array('S1', 'S2', 'S3'));

    // ---- confirmation against the data table -----------------------------------------
    $fv = new FieldValues($mod);
    $same = function ($f, $v) { return strtolower(trim($v)) === 'dup-1'; };
    $conf = $sorted($fv->recordsAmong($PID, array('tok', 'scan'), null, array('S1', 'S2', 'S3', 'R5'), $same));
    check('lookup index: recordsAmong keeps the named records that hold the value (got ' . json_encode($conf) . ')',
        $conf === array('S1', 'S2', 'S3'));
    check('lookup index: recordsAmong with an event keeps that event\'s rows only',
        $fv->recordsAmong($PID, array('tok'), 2, array('S1', 'S3'), $same) === array('S3'));
    check('lookup index: recordsAmong of no record reads nothing and is empty',
        $fv->recordsAmong($PID, array('tok'), null, array(), $same) === array());
    $mod->log = array();
    $fv->recordsAmong($PID, array('tok'), null, array('S1'), $same);
    $q = $statements('AND record IN (');
    $cols = $q ? uv_index_columns($A, 'redcap_data', uv_explain_key($A, $q[0][0], $q[0][1])) : array();
    check('lookup index: recordsAmong reads through (project_id, record, field_name) (plan '
        . json_encode($q ? uv_explain_rows($A, $q[0][0], $q[0][1]) : null) . ')',
        array_slice($cols, 0, 2) === array('project_id', 'record'));

    // ---- changes REDCap logs ----------------------------------------------------------
    $set($PID, 'R5', 'tok', 'NEW-5');
    $log($PID, 'R5');
    check('lookup index: an edit is seen by the next lookup', $ask(array('tok' => null), 'new-5') === array('R5'));
    check('lookup index: and the old value is gone from the record', $ask(array('tok' => null), 'T-5') === array());
    $put($PID, 'R900', 'tok', 'DUP-1');
    $log($PID, 'R900');
    check('lookup index: a new record is seen', $ask(array('tok' => null), 'DUP-1') === array('R900', 'S1', 'S3'));
    $A->query("DELETE FROM redcap_data WHERE project_id = $PID AND record = 'R900'");
    $log($PID, 'R900');
    check('lookup index: a deleted record is gone', $ask(array('tok' => null), 'DUP-1') === array('S1', 'S3'));
    $put($NEIGHBOUR, 'N2', 'tok', 'NB-ONLY');
    $log($NEIGHBOUR, 'N2');
    check('lookup index: a change in the neighbour project is not this project\'s', $ask(array('tok' => null), 'NB-ONLY') === array());

    $mod->log = array();
    $ask(array('tok' => null), 'DUP-1');
    check('lookup index: a record already indexed at its log position is not read again',
        $statements('FROM redcap_data WHERE project_id = ? AND record IN (') === array());
    check('lookup index: and the window, which now holds rows, is counted in one statement, with no write (got '
        . count($mod->log) . ' statements)',
        count($statements('SUM(log_event_id > ?')) === 1 && count($mod->log) === 9 && $writes() === array());

    // ---- a log row that becomes visible late ------------------------------------------
    // An import's transaction commits its log rows after rows with higher ids
    // were written: the window reads back over them.
    $set($PID, 'R7', 'tok', 'LATE-7');
    $log($PID, 'R7', 'redcap_data', 5001);
    check('lookup index: a row that appears below the last position, inside the window, is seen',
        $ask(array('tok' => null), 'LATE-7') === array('R7'));
    // Below the window it is not, until the field is rebuilt (REBUILD_AFTER).
    $set($PID, 'R8', 'tok', 'LATE-8');
    $log($PID, 'R8', 'redcap_data', 3, $old);
    $set($PID, 'R9', 'tok', 'GHOST-9');      // no log row at all
    check('lookup index: a row that appears below the window is not seen (documented)', $ask(array('tok' => null), 'LATE-8') === array());
    check('lookup index: nor is a write REDCap did not log (documented)', $ask(array('tok' => null), 'GHOST-9') === array());
    $A->query('UPDATE ' . Schema::table('lookup_field') . ' SET built_at = built_at - ' . (LookupIndex::REBUILD_AFTER + 1)
        . " WHERE project_id = $PID AND field_name = 'tok'");
    check('lookup index: a field older than REBUILD_AFTER is built again, and then has both',
        $ask(array('tok' => null), 'LATE-8') === array('R8') && $ask(array('tok' => null), 'GHOST-9') === array('R9'));

    // ---- what makes the index rebuild ------------------------------------------------
    // A change to one field of the dictionary resets that field, not the
    // project (UNQ-3 of 2026-11-13: one edited label stopped every lookup).
    $builtOf = function ($f) use ($field) { $st = $field($f); return $st === null ? null : $st['built']; };
    $A->query('UPDATE ' . Schema::table('lookup_field') . " SET built_at = built_at - 50 WHERE project_id = $PID");
    $before = array($builtOf('tok'), $builtOf('scan'), $builtOf('num'));
    $log($PID, 'label_only', 'redcap_metadata');
    $mod->log = array();
    check('lookup index: a change to a field no lookup searches resets nothing',
        $ask(array('tok' => null), 'DUP-1') === array('S1', 'S3')
        && array($builtOf('tok'), $builtOf('scan'), $builtOf('num')) === $before
        && $statements('INSERT INTO ' . Schema::table('lookup_key')) === array());
    $named = $statements('SELECT DISTINCT CAST(pk AS BINARY)');
    check('lookup index: ...after one read of the names the change logged, through the log\'s primary key (plan '
        . json_encode($named ? uv_explain_rows($A, $named[0][0], $named[0][1]) : null) . ')',
        count($named) === 1 && uv_explain_key($A, $named[0][0], $named[0][1]) === 'PRIMARY');
    $log($PID, 'tok', 'redcap_metadata');
    check('lookup index: a change to an indexed field rebuilds that field',
        $ask(array('tok' => null), 'DUP-1') === array('S1', 'S3') && $field('tok')['ready'] === 1 && $builtOf('tok') > $before[0]);
    check('lookup index: ...and the others go on answering from the keys they have',
        $field('scan')['ready'] === 1 && $builtOf('scan') === $before[1] && $builtOf('num') === $before[2]);
    $mod->log = array();
    $ask(array('tok' => null), 'DUP-1');
    check('lookup index: the same dictionary change does not rebuild twice',
        $statements('DELETE FROM ' . Schema::table('lookup_key')) === array()
        && $statements('SELECT DISTINCT CAST(pk AS BINARY)') === array());
    // A name the dictionary does not hold now may stand for any field: a
    // field deleted or renamed away, an instrument, a whole upload.
    $log($PID, 'gone_field', 'redcap_metadata');
    check('lookup index: a change that names no field the dictionary holds resets every field',
        $ask(array('tok' => null), 'DUP-1') === array('S1', 'S3') && $field('scan')['ready'] === 0 && $field('num')['ready'] === 0);
    check('lookup index: the fields left for later answer once built', $ask(array('scan' => null), 'dup-1') === array('S2')
        && $ask(array('num' => 'point'), '7') === array('S4', 'S5', 'S6'));
    $log($PID, (string) $PID, 'redcap_metadata');
    $ask(array('tok' => null), 'DUP-1');
    check('lookup index: an upload of the whole dictionary (the project id in pk) resets every field', $field('scan')['ready'] === 0);
    $ask(array('scan' => null), 'x');
    $ask(array('num' => 'point'), 'x');
    // An indexed field the dictionary no longer holds: reset with the next
    // change, so a field renamed into its name later starts from nothing.
    $A->query("DELETE FROM redcap_metadata WHERE project_id = $PID AND field_name = 'num'");
    $log($PID, 'label_only', 'redcap_metadata');
    $ask(array('tok' => null), 'DUP-1');
    check('lookup index: an indexed field that left the dictionary is reset, the others are not',
        $field('num')['ready'] === 0 && $field('tok')['ready'] === 1 && $field('scan')['ready'] === 1);
    $A->query("INSERT INTO redcap_metadata (project_id, field_name, field_order) VALUES ($PID, 'num', 4)");
    $ask(array('num' => 'point'), 'x');

    // ---- a renamed record (UNQ-6 of 2026-11-13) ----------------------------------------
    // REDCap moves the rows to the new id and logs one row, with one of the
    // two ids in pk and the other in its values. Either way round, the new
    // id is found and the old one is gone.
    $rename = function ($old, $new, $pkIsOld) use ($A, $PID, &$logId) {
        $A->query("UPDATE redcap_data SET record = '$new' WHERE project_id = $PID AND record = '$old'");
        $A->query("UPDATE redcap_data SET `value` = '$new' WHERE project_id = $PID AND record = '$new' AND field_name = 'record_id'");
        $logId += 10;
        $A->query(sprintf("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event, ts, object_type, data_values)
            VALUES (%d, %d, '%s', 'UPDATE', %s, 'redcap_data', 'record_id = ''%s''')", $logId, $PID,
            $pkIsOld ? $old : $new, date('YmdHis'), $pkIsOld ? $new : $old));
    };
    check('lookup index: before the rename, the value is found under the old id', $ask(array('tok' => null), 'T-20') === array('R20'));
    $rename('R20', 'R20-NEW', true);
    check('lookup index: a record renamed, logged under its old id, is found under its new one (got '
        . json_encode($ask(array('tok' => null), 'T-20')) . ')', $ask(array('tok' => null), 'T-20') === array('R20-NEW'));
    $ask(array('scan' => null), 'S-21');
    $rename('R21', 'R21-NEW', false);
    check('lookup index: ...and logged under its new id, the same', $ask(array('tok' => null), 'T-21') === array('R21-NEW')
        && $ask(array('scan' => null), 'S-21') === array('R21-NEW'));
    $mod->log = array();
    $ask(array('tok' => null), 'T-21');
    $rn = $statements('SELECT data_values, log_event_id');
    check('lookup index: a lookup with nothing new in the log does not look for renames', $rn === array());

    $many = array();
    for ($i = 0; $i <= LookupIndex::MAX_CHANGED; $i++) $many[] = '(' . (100000 + $i) . ", $PID, 'X$i', 'UPDATE', " . date('YmdHis') . ", 'redcap_data')";
    foreach (array_chunk($many, 2000) as $part) {
        $A->query('INSERT INTO redcap_log_event (log_event_id, project_id, pk, event, ts, object_type) VALUES ' . implode(', ', $part));
    }
    $logId = 200000;
    $syncRow = function () use ($A, $PID) {
        return $A->query('SELECT synced_id, samples, build_after, log_table FROM ' . Schema::table('lookup_sync')
            . " WHERE project_id = $PID")->fetch_row();
    };
    check('lookup index: more than MAX_CHANGED changed records reset the fields, with no answer while they cool down',
        $ask(array('tok' => null), 'DUP-1') === null && $field('tok')['ready'] === 0 && $field('scan')['ready'] === 0
        && (int) $syncRow()[2] > time());
    $A->query('UPDATE ' . Schema::table('lookup_sync') . ' SET build_after = ' . (time() - 1) . " WHERE project_id = $PID");
    check('lookup index: after the cooldown the fields are rebuilt one a request',
        $ask(array('tok' => null), 'DUP-1') === array('S1', 'S3'));
    $ask(array('scan' => null), 'x');
    $ask(array('num' => 'point'), 'x');
    $mod->log = array();
    check('lookup index: and the burst, read once, is not read again inside the window',
        $ask(array('tok' => null, 'scan' => null, 'num' => 'point'), 'DUP-1') === array('S1', 'S2', 'S3')
        && $statements('GROUP BY p') === array() && $statements('DELETE FROM ' . Schema::table('lookup_key')) === array());

    // A log pruned past the start of the window: the gap may hold changes.
    $A->query('UPDATE ' . Schema::table('lookup_sync') . " SET synced_id = 2, samples = '{\"s\":[[" . (time() - 7200) . ",\"2\",0]],\"o\":0}' WHERE project_id = $PID");
    $A->query('DELETE FROM redcap_log_event WHERE log_event_id <= 5');
    check('lookup index: a log pruned past the window rebuilds the fields',
        $ask(array('tok' => null), 'DUP-1') === array('S1', 'S3') && $field('scan')['ready'] === 0);

    // ---- another log table, with no index on ts ---------------------------------------
    $A->query('CREATE TABLE redcap_log_event4 LIKE redcap_log_event');
    $A->query('ALTER TABLE redcap_log_event4 DROP INDEX ts');
    $A->query('INSERT INTO redcap_log_event4 SELECT * FROM redcap_log_event');
    $A->query("UPDATE redcap_projects SET log_event_table = 'redcap_log_event4' WHERE project_id = $PID");
    check('lookup index: a project moved to another log table, with no way to read its recent rows by time, gets no answer yet',
        $ask(array('tok' => null), 'DUP-1') === null);
    $sr = $syncRow();
    check('lookup index: ...and waits OVERLAP seconds from now (got ' . json_encode($sr) . ')',
        $sr[3] === 'redcap_log_event4' && (int) $sr[2] >= time() + LookupIndex::OVERLAP - 5);
    $A->query('UPDATE ' . Schema::table('lookup_sync') . ' SET build_after = ' . (time() - 1)
        . ", samples = '{\"s\":[[" . (time() - LookupIndex::OVERLAP - 10) . ',"' . $sr[0] . "\",0]],\"o\":0}' WHERE project_id = $PID");
    check('lookup index: once a sample is OVERLAP old, the fields are rebuilt and answer',
        $ask(array('tok' => null), 'DUP-1') === array('S1', 'S3'));
    $set($PID, 'R11', 'tok', 'MOVED-11');
    $A->query("INSERT INTO redcap_log_event4 (log_event_id, project_id, pk, event, ts, object_type)
        VALUES (300000, $PID, 'R11', 'UPDATE', " . date('YmdHis') . ", 'redcap_data')");
    check('lookup index: and follow the new log table', $ask(array('tok' => null), 'moved-11') === array('R11'));

    // ---- no answer, never a wrong one ------------------------------------------------
    $held = $B->query("SELECT GET_LOCK(CONCAT('uvlk', MD5(CONCAT(COALESCE(DATABASE(), ''), '/', '$PID'))), 0)")->fetch_row()[0];
    $t = microtime(true);
    $got = $ask(array('tok' => null), 'DUP-1');
    $waited = microtime(true) - $t;
    check('lookup index: while another request holds the project, no answer after LOCK_WAIT (waited '
        . round($waited, 1) . ' s, got ' . json_encode($got) . ', lock held by the other: ' . json_encode($held) . ')',
        $got === null && $waited >= LookupIndex::LOCK_WAIT - 0.5);
    // Why there was no answer (UNQ-9 of 2026-11-13).
    $ix = new LookupIndex($mod);
    $ix->candidates($PID, array('tok' => null), 'DUP-1');
    check('lookup index: ...and the index says it was the lock (got ' . json_encode($ix->lastProblem()) . ')', $ix->lastProblem() === 'lock');
    $B->query("SELECT RELEASE_LOCK(CONCAT('uvlk', MD5(CONCAT(COALESCE(DATABASE(), ''), '/', '$PID'))))");
    check('lookup index: ...and an answer once it lets go', $ask(array('tok' => null), 'DUP-1') === array('S1', 'S3'));
    $ix = new LookupIndex($mod);
    $ix->candidates($PID, array('tok' => null), 'DUP-1');
    check('lookup index: an answer leaves no problem to report', $ix->lastProblem() === null);

    // ---- a project no lookup asked about for a long time (UNQ-3 of 2026-11-13) ---------
    // The log has moved further than a request reads: no answer and no read
    // of the stretch, until the cron has brought the project up to date.
    $far = (int) $A->query('SELECT MAX(log_event_id) FROM redcap_log_event4')->fetch_row()[0] + LookupIndex::MAX_SYNC_IDS + 10;
    $set($PID, 'R12', 'tok', 'FAR-12');
    $A->query("INSERT INTO redcap_log_event4 (log_event_id, project_id, pk, event, ts, object_type)
        VALUES ($far, $PID, 'R12', 'UPDATE', " . date('YmdHis') . ", 'redcap_data')");
    $mod->log = array();
    $ix = new LookupIndex($mod, array('cronAt' => time() - 60));
    $got = $ix->candidates($PID, array('tok' => null), 'far-12');
    check('lookup index: a log more than MAX_SYNC_IDS ahead gives a lookup no answer (got ' . json_encode(array($got, $ix->lastProblem())) . ')',
        $got === null && $ix->lastProblem() === 'behind');
    $ix = new LookupIndex($mod);
    $ix->candidates($PID, array('tok' => null), 'far-12');
    check('lookup index: ...and with no cron run lately, it says the cron is not running', $ix->lastProblem() === 'no-cron');
    check('lookup index: ...and the lookup reads none of it', $statements('SUM(log_event_id > ?') === array()
        && $statements('GROUP BY p') === array());
    $r = (new LookupIndex($mod))->maintain(time() + 60);
    check('lookup index: the cron brings the project up to date (got ' . json_encode($r) . ')', $r['synced'] === 1);
    check('lookup index: ...and the next lookup answers, with the change it had missed',
        $ask(array('tok' => null), 'far-12') === array('R12'));
    check('lookup index: a cron run with every project in reach syncs none', (new LookupIndex($mod))->maintain(time() + 60)['synced'] === 0);

    // ---- the plan on REDCap's own log table (UNQ-7 of 2026-11-13) ------------------------
    // REDCap's log table has no index on (project_id, log_event_id). Its keys
    // that lead with project_id reach every row the project ever logged, so
    // each read of a stretch of the log names the primary key.
    $A->query('CREATE TABLE redcap_log_event9 (
        log_event_id BIGINT NOT NULL AUTO_INCREMENT, project_id INT NOT NULL DEFAULT 0, ts BIGINT NULL,
        `user` VARCHAR(255) NULL, ip VARCHAR(100) NULL, page VARCHAR(255) NULL, event VARCHAR(32) NULL,
        object_type VARCHAR(128) NULL, sql_log MEDIUMTEXT NULL, pk TEXT NULL, event_id INT NULL,
        data_values TEXT NULL, description TEXT NULL, legacy INT NOT NULL DEFAULT 0, change_reason TEXT NULL,
        PRIMARY KEY (log_event_id), KEY description_project (description(20), project_id),
        KEY event_project (event, project_id), KEY object_type (object_type, legacy), KEY pk (pk(191)),
        KEY ts (ts), KEY `user` (`user`(191)), KEY user_project (project_id, `user`(191))) ENGINE=InnoDB');
    // 20,000 rows of this project (two users) among 1,000 of others: the
    // shape in which a key led by project_id looks cheapest to the server.
    $vals = array();
    for ($i = 1; $i <= 21000; $i++) {
        $own = $i % 21 !== 0;
        $vals[] = '(' . ($own ? $PID : $NEIGHBOUR) . ', ' . date('YmdHis') . ", 'u" . ($i % 2) . "', 'UPDATE', 'redcap_data', 'R" . ($i % 300) . "')";
        if (count($vals) === 3000) {
            $A->query('INSERT INTO redcap_log_event9 (project_id, ts, `user`, event, object_type, pk) VALUES ' . implode(', ', $vals));
            $vals = array();
        }
    }
    $A->query('ANALYZE TABLE redcap_log_event9');
    $mod->log = array();
    $set($PID, 'R13', 'tok', 'PLAN-13');
    $logId = $far + 10;
    $A->query("INSERT INTO redcap_log_event4 (log_event_id, project_id, pk, event, ts, object_type, data_values)
        VALUES ($logId, $PID, 'R13', 'UPDATE', " . date('YmdHis') . ", 'redcap_data', 'tok = ''PLAN-13''')");
    $A->query("INSERT INTO redcap_log_event4 (log_event_id, project_id, pk, event, ts, object_type)
        VALUES (" . ($logId + 1) . ", $PID, 'label_only', 'MANAGE', " . date('YmdHis') . ", 'redcap_metadata')");
    $ask(array('tok' => null), 'plan-13');
    foreach (array('the window count' => 'SUM(log_event_id > ?', 'the changed records' => 'GROUP BY p',
                   'the renamed records' => 'SELECT data_values, log_event_id', 'the dictionary names' => 'SELECT DISTINCT CAST(pk AS BINARY)') as $what => $needle) {
        $q = $statements($needle);
        // The same statement over the last 50 positions of REDCap's table.
        $sql = $q ? str_replace('redcap_log_event4', 'redcap_log_event9', $q[0][0]) : '';
        $params = $q ? $q[0][1] : array();
        // The stretch is the two parameters before the project id.
        $at = array_search($PID, $params, true);
        if ($at !== false && $at >= 2) {
            $params[$at - 2] = '20950';
            $params[$at - 1] = '21000';
        }
        $plan = $q ? $A->query('EXPLAIN ' . uv_inline_params($A, $sql, $params))->fetch_assoc() : null;
        check('lookup index: ' . $what . ' read a stretch of REDCap\'s log table through its primary key (plan '
            . json_encode($plan ? array($plan['key'], $plan['type'], $plan['rows']) : null) . ')',
            $plan !== null && $plan['key'] === 'PRIMARY' && $plan['type'] === 'range' && (int) $plan['rows'] <= 200);
    }
    $A->query('DROP TABLE redcap_log_event9');
    $mod->inner->failOn = 'FROM redcap_log_event4';
    check('lookup index: a log that cannot be read is no answer', $ask(array('tok' => null), 'DUP-1') === null);
    $mod->inner->failOn = null;
    $free = $A->query("SELECT IS_FREE_LOCK(CONCAT('uvlk', MD5(CONCAT(COALESCE(DATABASE(), ''), '/', '$PID'))))")->fetch_row()[0];
    check('lookup index: ...and the lock is let go after a failure', (string) $free === '1');
    $A->query('DROP TABLE ' . Schema::table('lookup_key'));
    check('lookup index: a missing table is no answer', $ask(array('tok' => null), 'DUP-1') === null);
    $ix = new LookupIndex($mod);
    $ix->candidates($PID, array('tok' => null), 'DUP-1');
    check('lookup index: ...and the index says a read failed', $ix->lastProblem() === 'error');
    check('lookup index: the tables come back with ensureLookupIndex()', Schema::ensureLookupIndex($mod) === true);
    $A->query("UPDATE redcap_projects SET log_event_table = 'redcap_log_event' WHERE project_id = $PID");

    // ---- a field too large for a lookup to build: the cron builds it -------------------
    // A project of its own, with three fields of 60 rows; lookups here may build
    // 50 rows (liveBuildRows), so each field is the cron's.
    $P3 = 950;
    uv_redcap_project($A, $P3);
    $rows = array();
    foreach (array('big', 'big2', 'big3') as $f) {
        for ($i = 1; $i <= 60; $i++) $rows[] = "($P3, 1, 'B$i', '$f', '" . strtoupper($f) . "-$i')";
    }
    $A->query('INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`) VALUES ' . implode(', ', $rows));
    $A->query("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event, ts, object_type)
        VALUES (400000, $P3, 'B1', 'INSERT', $old, 'redcap_data')");
    $logId = 400000;
    $small = function () use ($mod) { return new LookupIndex($mod, uv_lix_opts(array('liveBuildRows' => 50))); };
    $askB = function ($f, $v) use ($small, $P3, $sorted) { return $sorted($small()->candidates($P3, array($f => null), $v)); };
    $fieldB = function ($f) use ($A, $P3) {
        $r = $A->query('SELECT ready, wanted_at, gen, building_at, asked_at, built_at FROM ' . Schema::table('lookup_field')
            . " WHERE project_id = $P3 AND field_name = '$f'")->fetch_row();
        return $r ? array('ready' => (int) $r[0], 'wanted' => (int) $r[1], 'gen' => (int) $r[2], 'building' => (int) $r[3],
            'asked' => (int) $r[4], 'built' => (int) $r[5]) : null;
    };
    $keysB = function ($f) use ($A, $P3) {
        return (int) $A->query('SELECT COUNT(*) FROM ' . Schema::table('lookup_key') . " WHERE project_id = $P3 AND field_name = '$f'")->fetch_row()[0];
    };
    $mod->log = array();
    $got = $askB('big', 'big-7');
    $fb = $fieldB('big');
    check('lookup index: a field larger than a lookup may build gets no answer, and is marked for the cron (got '
        . json_encode(array($got, $fb)) . ')', $got === null && $fb !== null && $fb['ready'] === 0 && $fb['wanted'] > 0
        && $keysB('big') === 0 && $statements('INSERT INTO ' . Schema::table('lookup_key')) === array());
    $ix = new LookupIndex($mod, array('liveBuildRows' => 50, 'cronAt' => time() - 60));
    $ix->candidates($P3, array('big' => null), 'big-7');
    check('lookup index: ...and says it waits for the cron (got ' . json_encode($ix->lastProblem()) . ')', $ix->lastProblem() === 'cron');
    $count = $statements('SELECT 1 FROM redcap_data');
    check('lookup index: ...after counting its rows only as far as the limit (got ' . json_encode($count) . ')',
        count($count) === 1 && strpos($count[0][0], 'LIMIT 51') !== false);
    $mod->log = array();
    $askB('big', 'big-7');
    check('lookup index: ...and the next lookup does not count its rows again',
        $statements('SELECT 1 FROM redcap_data') === array());
    $r = $small()->maintain(time() + 60);
    $fb = $fieldB('big');
    check('lookup index: the cron builds it (got ' . json_encode(array($r, $fb)) . ')',
        $r['built'] === 1 && $fb['ready'] === 1 && $fb['wanted'] === 0 && $fb['building'] === 0 && $keysB('big') === 60);
    check('lookup index: ...and lookups answer from it', $askB('big', 'big-7') === array('B7'));
    check('lookup index: a cron run with nothing to do builds nothing', $small()->maintain(time() + 60)['built'] === 0);

    // While the cron reads a field, the project's lock is free: another
    // request's lookup answers, and the change it syncs reaches the field
    // being built, although that field does not answer yet.
    $askB('big2', 'x');
    $during = 'not asked';
    $mod->after = function ($sql, $params) use (&$during, $B, $P3, $A, $set, $log, &$mod) {
        if (strpos($sql, 'SELECT record, event_id, `value` FROM redcap_data') !== 0 || !in_array('big2', $params, true)) return;
        $mod->after = null;
        $set($P3, 'B9', 'big2', 'CHANGED-9');
        $log($P3, 'B9');
        $during = (new LookupIndex(new UvFlakyModule($B), uv_lix_opts(array('liveBuildRows' => 50))))->candidates($P3, array('big' => null), 'big-8');
    };
    $r = $small()->maintain(time() + 60);
    $mod->after = null;
    check('lookup index: a lookup while the cron builds another field of the project answers (got ' . json_encode($during) . ')',
        $during === array('B8'));
    check('lookup index: the build counts (got ' . json_encode($r) . ')', $r['built'] === 1 && $fieldB('big2')['ready'] === 1);
    check('lookup index: and a value changed while it read is found under its new value',
        $askB('big2', 'changed-9') === array('B9'));

    // A reset while the cron reads: the build ends but does not count, and the
    // next cron run builds the field again.
    $askB('big3', 'x');
    $mod->after = function ($sql, $params) use ($B, $P3, $log, &$mod) {
        if (strpos($sql, 'SELECT record, event_id, `value` FROM redcap_data') !== 0 || !in_array('big3', $params, true)) return;
        $mod->after = null;
        $log($P3, 'big3', 'redcap_metadata');
        (new LookupIndex(new UvFlakyModule($B), uv_lix_opts(array('liveBuildRows' => 50))))->candidates($P3, array('big' => null), 'big-8');
    };
    $r = $small()->maintain(time() + 60);
    $mod->after = null;
    $f3 = $fieldB('big3');
    check('lookup index: a change to the field\'s dictionary entry during its cron build: the build does not count (got '
        . json_encode(array($r, $f3)) . ')', $r['built'] === 0 && $f3['ready'] === 0 && $f3['building'] === 0);
    check('lookup index: ...the project\'s other fields go on answering', $askB('big', 'big-7') === array('B7'));
    $r = $small()->maintain(time() + 60);
    check('lookup index: ...and the next cron run builds the field again (got ' . json_encode($r) . ')',
        $r['built'] === 1 && $askB('big3', 'big3-4') === array('B4') && $askB('big', 'big-7') === array('B7'));

    // A refresh ahead of REBUILD_AFTER: the field answers throughout, and a key
    // the log never told the index about is replaced.
    $set($P3, 'B5', 'big', 'GHOST-5');     // no log row
    check('lookup index: before the refresh, a write with no log row is not seen', $askB('big', 'ghost-5') === array());
    $A->query('UPDATE ' . Schema::table('lookup_field') . ' SET built_at = built_at - '
        . (LookupIndex::REBUILD_AFTER - LookupIndex::REFRESH_BEFORE + 10) . " WHERE project_id = $P3 AND field_name = 'big'");
    check('lookup index: a field the cron will refresh still answers', $askB('big', 'big-7') === array('B7'));
    $during = 'not asked';
    $mod->after = function ($sql, $params) use (&$during, $B, $P3, &$mod) {
        if (strpos($sql, 'SELECT record, event_id, `value` FROM redcap_data') !== 0 || !in_array('big', $params, true)) return;
        $mod->after = null;
        $during = (new LookupIndex(new UvFlakyModule($B), uv_lix_opts(array('liveBuildRows' => 50))))->candidates($P3, array('big' => null), 'big-7');
    };
    $r = $small()->maintain(time() + 60);
    $mod->after = null;
    check('lookup index: the cron refreshes it, and a lookup during the refresh answers (got ' . json_encode(array($r, $during)) . ')',
        $r['built'] === 1 && $during === array('B7'));
    check('lookup index: after the refresh the unlogged value is found, and its old key is gone',
        $askB('big', 'ghost-5') === array('B5') && $askB('big', 'big-5') === array() && $keysB('big') === 60);

    // No cron: a field wanted for CRON_GRACE is built by a lookup.
    $A->query("INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`) VALUES ($P3, 1, 'B1', 'big4', 'X')");
    for ($i = 2; $i <= 60; $i++) $A->query("INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`) VALUES ($P3, 1, 'B$i', 'big4', 'Y$i')");
    check('lookup index: a fourth large field is wanted', $askB('big4', 'x') === null && $fieldB('big4')['wanted'] > 0);
    $A->query('UPDATE ' . Schema::table('lookup_field') . ' SET wanted_at = wanted_at - ' . (LookupIndex::CRON_GRACE + 1)
        . " WHERE project_id = $P3 AND field_name = 'big4'");
    $busy = new LookupIndex($mod, uv_lix_opts(array('liveBuildRows' => 50, 'cronAt' => time() - 60)));
    check('lookup index: wanted for CRON_GRACE while a cron runs, a lookup still leaves it to the cron',
        $busy->candidates($P3, array('big4' => null), 'x') === null && $fieldB('big4')['ready'] === 0);
    // With no cron either, the build is still not a lookup's (UNQ-3 of
    // 2026-11-13: it read the whole field inside a request). The lookup says
    // the cron is not running, and the cron builds the field when it does.
    $mod->log = array();
    $idle = $small();
    check('lookup index: wanted for CRON_GRACE with no cron, a lookup still does not build it (got ' . json_encode($idle->lastProblem()) . ')',
        $idle->candidates($P3, array('big4' => null), 'x') === null && $idle->lastProblem() === 'no-cron'
        && $fieldB('big4')['ready'] === 0 && $statements('INSERT INTO ' . Schema::table('lookup_key')) === array());
    $r = $small()->maintain(time() + 60);
    check('lookup index: ...the cron does (got ' . json_encode($r) . ')', $r['built'] === 1 && $askB('big4', 'x') === array('B1')
        && $fieldB('big4')['wanted'] === 0);

    // The cron's limits: a deadline already past does nothing, and a field no
    // lookup asked for in UNUSED_AFTER loses its keys and its row.
    $A->query('UPDATE ' . Schema::table('lookup_field') . ' SET asked_at = asked_at - ' . (LookupIndex::UNUSED_AFTER + 10)
        . " WHERE project_id = $P3 AND field_name = 'big2'");
    $r = $small()->maintain(time() - 1);
    check('lookup index: a cron run past its deadline leaves the work for the next (got ' . json_encode($r) . ')',
        $r['built'] === 0 && $r['dropped'] === 0 && $r['left'] >= 1 && $keysB('big2') >= 60);
    $r = $small()->maintain(time() + 60);
    check('lookup index: a field no lookup asked for in UNUSED_AFTER is dropped (got ' . json_encode($r) . ')',
        $r['dropped'] === 1 && $keysB('big2') === 0 && $fieldB('big2') === null);

    // ---- FRM-7: keyed hashes, keys of another version cleared, a disabled project cleared ----
    $kt = Schema::table('lookup_key');
    $k = strtolower((string) $A->query("SELECT HEX(key_hash) FROM $kt WHERE project_id = $P3 AND field_name = 'big4' AND record = 'B1'")->fetch_row()[0]);
    check('FRM-7: a stored key is the keyed hash of the value for this project (got ' . $k . ')',
        $k === LookupIndex::keyOf(uv_lix_secret(), $P3, 'X', null));
    check('FRM-7: ...and neither the MD5 of the value nor the key another project or secret would give',
        $k !== md5(\INSPIRE\UniversalValidator\Logic::lookupKey('X', true, null))
        && $k !== LookupIndex::keyOf(uv_lix_secret(), $P3 + 1, 'X', null)
        && $k !== LookupIndex::keyOf(str_repeat('9d', 32), $P3, 'X', null));
    check('FRM-7: with no secret the index answers nothing and sends no statement',
        (function () use ($mod, $P3) {
            $mod->log = array();
            $a = (new LookupIndex($mod))->candidates($P3, array('big4' => null), 'x');
            $b = (new LookupIndex($mod))->maintain(time() + 60);
            return $a === null && $b === array('built' => 0, 'dropped' => 0, 'waiting' => 0, 'left' => 0) && $mod->log === array();
        })());
    // A field keyed the version-1 way (plain MD5): the cron drops it and its keys at
    // its next pass, though a lookup asked for it a moment ago.
    $A->query('UPDATE ' . Schema::table('lookup_field') . " SET spec = '1|text' WHERE project_id = $P3 AND field_name = 'big4'");
    $r = $small()->maintain(time() + 60);
    check('FRM-7: a field keyed under version 1 is dropped with its keys by the next cron pass (got ' . json_encode($r) . ')',
        $r['dropped'] === 1 && $keysB('big4') === 0 && $fieldB('big4') === null);
    check('FRM-7: asked again, the field is wanted, built by the cron and answers',
        $askB('big4', 'x') === null && $fieldB('big4')['wanted'] > 0
        && $small()->maintain(time() + 60)['built'] === 1 && $askB('big4', 'x') === array('B1'));
    // Another secret: the keys there cannot match, so the field does not answer from them.
    $other = new LookupIndex($mod, array('liveBuildRows' => 50, 'keySecret' => str_repeat('9d', 32)));
    check('FRM-7: under another secret the field does not answer from the old keys',
        $other->candidates($P3, array('big4' => null), 'x') === null && $fieldB('big4')['ready'] === 0);
    // The module disabled in a project: everything the index holds for it goes, and only for it.
    $count = function ($pid) use ($A) {
        $n = 0;
        foreach (array('lookup_key', 'lookup_field', 'lookup_sync', 'lookup_record') as $t) {
            $n += (int) $A->query('SELECT COUNT(*) FROM ' . Schema::table($t) . " WHERE project_id = $pid")->fetch_row()[0];
        }
        return $n;
    };
    $beforeOther = $count($PID);
    check('FRM-7: (both projects hold rows)', $count($P3) > 0 && $beforeOther > 0);
    check('FRM-7: forgetProject() clears the four tables of the project, with no secret needed',
        (new LookupIndex($mod))->forgetProject($P3) === true && $count($P3) === 0);
    check('FRM-7: ...and leaves every other project as it was', $count($PID) === $beforeOther);
    check('FRM-7: forgetProject() of no project does nothing', (new LookupIndex($mod))->forgetProject(0) === false);
    $A->query('DROP TABLE IF EXISTS redcap_metadata');
}
