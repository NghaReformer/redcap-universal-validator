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

use INSPIRE\UniversalValidator\Scan\LookupIndex;
use INSPIRE\UniversalValidator\Scan\FieldValues;
use INSPIRE\UniversalValidator\Scan\Schema;

$PID = 900;
$NEIGHBOUR = uv_neighbour($PID);
// The module's secret, as UniversalValidator::hmacKey() hands it over, and
// the fingerprint of it that every spec carries.
$KEY = str_repeat('a1', 32);
$PRINT = substr(hash_hmac('sha256', 'uv lookup index key print', $KEY), 0, 8);
// What a sync row written under that secret carries with its samples ("k").
$STAMP = LookupIndex::KEY_VERSION . '|' . $PRINT;

{
    uv_redcap_schema($A);
    uv_redcap_log_schema($A);
    // The two log columns the index reads besides pk: REDCap's ts (written
    // from PHP's clock as YYYYMMDDHHMMSS) and object_type, with ts indexed as
    // REDCap indexes it.
    $A->query('ALTER TABLE redcap_log_event ADD COLUMN ts BIGINT NULL, ADD COLUMN object_type VARCHAR(128) NULL, ADD KEY ts (ts)');
    uv_redcap_project($A, $PID);
    uv_redcap_project($A, $NEIGHBOUR);

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
    $ask = function (array $fields, $value) use ($mod, $PID, $sorted, $KEY) {
        return $sorted((new LookupIndex($mod, array('key' => $KEY)))->candidates($PID, $fields, $value));
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
        $st !== null && $st['ready'] === 1 && $st['spec'] === LookupIndex::KEY_VERSION . '|' . $PRINT . '|text' && $st['built'] > 0);
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
    $ix = new LookupIndex($mod, array('key' => $KEY));
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
    check('lookup index: [num] is keyed by number', $field('num')['spec'] === LookupIndex::KEY_VERSION . '|' . $PRINT . '|point');
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
    $log($PID, 'tok', 'redcap_metadata');
    check('lookup index: a change to the data dictionary rebuilds the field asked for',
        $ask(array('tok' => null), 'DUP-1') === array('S1', 'S3') && $field('tok')['ready'] === 1);
    check('lookup index: and leaves the others to their own next lookup', $field('scan')['ready'] === 0);
    $mod->log = array();
    $ask(array('tok' => null), 'DUP-1');
    check('lookup index: the same dictionary change does not rebuild twice',
        $statements('DELETE FROM ' . Schema::table('lookup_key')) === array());
    check('lookup index: the field left for later answers once built', $ask(array('scan' => null), 'dup-1') === array('S2'));

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
    $A->query('UPDATE ' . Schema::table('lookup_sync') . " SET synced_id = 2, samples = '{\"s\":[[" . (time() - 7200) . ",\"2\",0]],\"o\":0,\"k\":\"$STAMP\"}' WHERE project_id = $PID");
    $A->query('DELETE FROM redcap_log_event WHERE log_event_id <= 5');
    $mod->log = array();
    check('lookup index: a log pruned past the window rebuilds the fields, without forgetting its records',
        $ask(array('tok' => null), 'DUP-1') === array('S1', 'S3') && $field('scan')['ready'] === 0
        && $statements('DELETE FROM ' . Schema::table('lookup_record')) === array());
    // A sync row with no secret's print (as 2.4.0 wrote it), or another's,
    // was kept by a request that kept only its own entries: a fresh start,
    // which forgets the records too.
    $j = json_decode((string) $syncRow()[1], true);
    unset($j['k']);
    $A->query('UPDATE ' . Schema::table('lookup_sync') . " SET samples = '" . $A->real_escape_string(json_encode($j)) . "' WHERE project_id = $PID");
    $mod->log = array();
    check('lookup index: a sync row with no secret\'s print starts afresh',
        $ask(array('tok' => null), 'DUP-1') === array('S1', 'S3') && count($statements('DELETE FROM ' . Schema::table('lookup_record'))) >= 1
        && strpos((string) $syncRow()[1], '"k":"' . $STAMP . '"') !== false);

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
        . ", samples = '{\"s\":[[" . (time() - LookupIndex::OVERLAP - 10) . ',"' . $sr[0] . "\",0]],\"o\":0,\"k\":\"$STAMP\"}' WHERE project_id = $PID");
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
    // Waited, not refused at once. With two suites running on one host, a 2 s
    // GET_LOCK has returned in under 1.5 s, so the bound is half the wait.
    check('lookup index: while another request holds the project, no answer after LOCK_WAIT (waited '
        . round($waited, 2) . ' s, got ' . json_encode($got) . ', lock held by the other: ' . json_encode($held) . ')',
        $got === null && $waited >= LookupIndex::LOCK_WAIT / 2);
    $B->query("SELECT RELEASE_LOCK(CONCAT('uvlk', MD5(CONCAT(COALESCE(DATABASE(), ''), '/', '$PID'))))");
    check('lookup index: ...and an answer once it lets go', $ask(array('tok' => null), 'DUP-1') === array('S1', 'S3'));
    $mod->inner->failOn = 'FROM redcap_log_event4';
    check('lookup index: a log that cannot be read is no answer', $ask(array('tok' => null), 'DUP-1') === null);
    $mod->inner->failOn = null;
    $free = $A->query("SELECT IS_FREE_LOCK(CONCAT('uvlk', MD5(CONCAT(COALESCE(DATABASE(), ''), '/', '$PID'))))")->fetch_row()[0];
    check('lookup index: ...and the lock is let go after a failure', (string) $free === '1');
    $A->query('DROP TABLE ' . Schema::table('lookup_key'));
    check('lookup index: a missing table is no answer', $ask(array('tok' => null), 'DUP-1') === null);
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
    $small = function () use ($mod, $KEY) { return new LookupIndex($mod, array('liveBuildRows' => 50, 'key' => $KEY)); };
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
    $mod->after = function ($sql, $params) use (&$during, $B, $P3, $A, $set, $log, &$mod, $KEY) {
        if (strpos($sql, 'SELECT record, event_id, `value` FROM redcap_data') !== 0 || !in_array('big2', $params, true)) return;
        $mod->after = null;
        $set($P3, 'B9', 'big2', 'CHANGED-9');
        $log($P3, 'B9');
        $during = (new LookupIndex(new UvFlakyModule($B), array('liveBuildRows' => 50, 'key' => $KEY)))->candidates($P3, array('big' => null), 'big-8');
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
    $mod->after = function ($sql, $params) use ($B, $P3, $log, &$mod, $KEY) {
        if (strpos($sql, 'SELECT record, event_id, `value` FROM redcap_data') !== 0 || !in_array('big3', $params, true)) return;
        $mod->after = null;
        $log($P3, 'big3', 'redcap_metadata');
        (new LookupIndex(new UvFlakyModule($B), array('liveBuildRows' => 50, 'key' => $KEY)))->candidates($P3, array('big' => null), 'big-8');
    };
    $r = $small()->maintain(time() + 60);
    $mod->after = null;
    $f3 = $fieldB('big3');
    check('lookup index: a data dictionary change during a cron build: the build does not count (got '
        . json_encode(array($r, $f3)) . ')', $r['built'] === 0 && $f3['ready'] === 0 && $f3['building'] === 0);
    check('lookup index: ...the project\'s other fields stop answering too', $askB('big', 'big-7') === null);
    $r = $small()->maintain(time() + 60);
    check('lookup index: ...and the next cron runs build them again (got ' . json_encode($r) . ')',
        $r['built'] >= 1 && $small()->maintain(time() + 60)['built'] >= 0
        && $askB('big3', 'big3-4') === array('B4') && $askB('big', 'big-7') === array('B7'));

    // A refresh ahead of REBUILD_AFTER: the field answers throughout, and a key
    // the log never told the index about is replaced.
    $set($P3, 'B5', 'big', 'GHOST-5');     // no log row
    check('lookup index: before the refresh, a write with no log row is not seen', $askB('big', 'ghost-5') === array());
    $A->query('UPDATE ' . Schema::table('lookup_field') . ' SET built_at = built_at - '
        . (LookupIndex::REBUILD_AFTER - LookupIndex::REFRESH_BEFORE + 10) . " WHERE project_id = $P3 AND field_name = 'big'");
    check('lookup index: a field the cron will refresh still answers', $askB('big', 'big-7') === array('B7'));
    $during = 'not asked';
    $mod->after = function ($sql, $params) use (&$during, $B, $P3, &$mod, $KEY) {
        if (strpos($sql, 'SELECT record, event_id, `value` FROM redcap_data') !== 0 || !in_array('big', $params, true)) return;
        $mod->after = null;
        $during = (new LookupIndex(new UvFlakyModule($B), array('liveBuildRows' => 50, 'key' => $KEY)))->candidates($P3, array('big' => null), 'big-7');
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
    $busy = new LookupIndex($mod, array('liveBuildRows' => 50, 'cronAt' => time() - 60, 'key' => $KEY));
    check('lookup index: wanted for CRON_GRACE while a cron runs, a lookup still leaves it to the cron',
        $busy->candidates($P3, array('big4' => null), 'x') === null && $fieldB('big4')['ready'] === 0);
    check('lookup index: wanted for CRON_GRACE with no cron, a lookup builds it itself', $askB('big4', 'x') === array('B1')
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
}

/* ---- keyed keys (2.5.0) ----------------------------------------------------------------
 * Up to 2.4.0 a key was the MD5 of the lookup key, the same in every project and
 * reversible by a dictionary. Now it is a keyed hash with the project and the
 * field in its message; with no secret the index does not answer at all.
 */
{
    $keyOf = function ($pid, $field, $rec) use ($A) {
        $r = $A->query('SELECT HEX(key_hash) FROM ' . Schema::table('lookup_key') . " WHERE project_id = $pid AND field_name = '$field'
            AND record = '" . $A->real_escape_string($rec) . "'")->fetch_row();
        return $r ? strtolower($r[0]) : null;
    };
    // The first case ended on a project moved back to its log table, which
    // reset its fields: this lookup builds [tok] again.
    $ask(array('tok' => null), 'DUP-1');
    (new LookupIndex($mod, array('key' => $KEY)))->candidates($NEIGHBOUR, array('tok' => null), 'DUP-1');
    $here = $keyOf($PID, 'tok', 'S3');
    $there = $keyOf($NEIGHBOUR, 'tok', 'N1');
    check('keyed: a stored key is not the MD5 of the value (got ' . json_encode($here) . ')',
        $here !== null && $here !== md5(\INSPIRE\UniversalValidator\Logic::lookupKey('DUP-1', true)));
    check('keyed: the same value has another key in another project', $there !== null && $there !== $here);
    $mod->log = array();
    check('keyed: with no secret the index does not answer, and reads nothing',
        (new LookupIndex($mod, array()))->candidates($PID, array('tok' => null), 'DUP-1') === null && $mod->log === array()
        && (new LookupIndex($mod, array('key' => '')))->maintain(time() + 60) === array('built' => 0, 'dropped' => 0, 'waiting' => 0, 'left' => 0)
        && $mod->log === array());
    // A field built by 2.4.0 (key version 1) is built again on its next lookup.
    $A->query('UPDATE ' . Schema::table('lookup_field') . " SET spec = '1|text' WHERE project_id = $PID AND field_name = 'tok'");
    $got = $ask(array('tok' => null), 'dup-1');
    check('keyed: a field of key version 1 is built again and answers (got ' . json_encode($got) . ')',
        $got === array('S1', 'S3') && $field('tok')['spec'] === LookupIndex::KEY_VERSION . '|' . $PRINT . '|text');
    // Another secret: every key would miss, so the field is built again with it.
    $other = str_repeat('b2', 32);
    $got = $sorted((new LookupIndex($mod, array('key' => $other)))->candidates($PID, array('tok' => null), 'DUP-1'));
    check('keyed: a field built with another secret is built again, and answers (got ' . json_encode($got) . ')',
        $got === array('S1', 'S3') && $keyOf($PID, 'tok', 'S3') !== $here);
    check('keyed: and back with the first secret', $ask(array('tok' => null), 'DUP-1') === array('S1', 'S3')
        && $keyOf($PID, 'tok', 'S3') === $here);
    // An entry of another version is retireOld()'s, not maintain()'s: maintain
    // would drop one retireOld was about to queue again.
    $A->query('INSERT INTO ' . Schema::table('lookup_field') . " (project_id, field_name, spec, ready, built_at, gen, building_at, wanted_at, asked_at, wipe)
        VALUES ($PID, 'oldfield', '1|text', 1, " . time() . ', 1, 0, ' . time() . ', ' . (time() - LookupIndex::UNUSED_AFTER - 60) . ', 0)');
    (new LookupIndex($mod, array('key' => $KEY)))->maintain(time() + 60);
    $oldThere = (bool) $A->query('SELECT 1 FROM ' . Schema::table('lookup_field') . " WHERE project_id = $PID AND field_name = 'oldfield'")->fetch_row();
    (new LookupIndex($mod, array('key' => $KEY)))->retireOld(time() + 60);
    check('keyed: maintain leaves an entry of key version 1, and retireOld drops it when no lookup asked for it',
        $oldThere && !$A->query('SELECT 1 FROM ' . Schema::table('lookup_field') . " WHERE project_id = $PID AND field_name = 'oldfield'")->fetch_row());

    // A request under another secret syncs the project past a change to tok,
    // which it does not keep current. The first secret's tok must not answer
    // from its old keys afterwards: the sync row carries the secret's print.
    $ask(array('tok' => null), 'DUP-1');
    $set($PID, 'S3', 'tok', 'MOVED-1');
    $log($PID, 'S3');
    (new LookupIndex($mod, array('key' => $other)))->candidates($PID, array('scan' => null), 'DUP-1');
    $got = $ask(array('tok' => null), 'MOVED-1');
    check('keyed: after a sync under another secret, the first secret\'s field finds a value changed meanwhile (got ' . json_encode($got) . ')',
        $got === array('S3'));
    $set($PID, 'S3', 'tok', 'DUP-1');
    $log($PID, 'S3');

    // ---- the cron clears what an older version or another secret wrote --------------
    $ft = Schema::table('lookup_field');
    $kt = Schema::table('lookup_key');
    $dt = Schema::table('lookup_def');
    $now = time();
    $keysOf = function ($pid, $f) use ($A, $kt) {
        return (int) $A->query("SELECT COUNT(*) FROM $kt WHERE project_id = $pid AND field_name = '" . $A->real_escape_string($f) . "'")->fetch_row()[0];
    };
    $rowOf = function ($pid, $f) use ($A, $ft) {
        $r = $A->query("SELECT spec, ready, wanted_at, wipe FROM $ft WHERE project_id = $pid AND field_name = '" . $A->real_escape_string($f) . "'")->fetch_row();
        return $r ? array('spec' => $r[0], 'ready' => (int) $r[1], 'wanted' => (int) $r[2], 'wipe' => (int) $r[3]) : null;
    };
    // tok as 2.4.0 left it: key version 1, answering, with a plain digest of a value.
    $A->query("UPDATE $ft SET spec = '1|text', ready = 1, wanted_at = 0, asked_at = $now WHERE project_id = $PID AND field_name = 'tok'");
    $A->query("INSERT INTO $kt (project_id, field_name, key_hash, record, gen) VALUES ($PID, 'tok', UNHEX(MD5('dup-1')), 'S9', 1)");
    $oldComp = '#' . md5('an old composite');
    $A->query("INSERT INTO $ft (project_id, field_name, spec, ready, built_at, gen, building_at, wanted_at, asked_at, wipe) VALUES
        ($PID, 'unasked', '1|text', 1, $now, 1, 0, 0, " . ($now - LookupIndex::UNUSED_AFTER - 60) . ", 0),
        ($PID, '$oldComp', '2|ffffffff|c|" . md5('def') . "', 1, $now, 1, 0, 0, $now, 0)");
    $A->query("INSERT INTO $kt (project_id, field_name, key_hash, record, gen) VALUES ($PID, 'unasked', UNHEX(MD5('a')), 'R1', 1),
        ($PID, '$oldComp', UNHEX(MD5('b')), 'R1', 1)");
    $A->query("INSERT INTO $dt (project_id, field_name, def) VALUES ($PID, '$oldComp', '{}')");
    $mod->log = array();
    // liveBuildRows 50: tok's 300-odd rows make it the cron's to build.
    $r = (new LookupIndex($mod, array('key' => $KEY, 'liveBuildRows' => 50)))->retireOld(time() + 60);
    $tok = $rowOf($PID, 'tok');
    check('retire: a large field of key version 1 a lookup asked for is queued for the cron, its digests gone (got ' . json_encode(array($r, $tok)) . ')',
        $r['requeued'] >= 1 && $tok !== null && $tok['spec'] === LookupIndex::KEY_VERSION . '|' . $PRINT . '|text'
        && $tok['ready'] === 0 && $tok['wanted'] > 0 && $tok['wipe'] === 1 && $keysOf($PID, 'tok') === 0);
    check('retire: one no lookup asked for in UNUSED_AFTER goes, with its keys',
        $rowOf($PID, 'unasked') === null && $keysOf($PID, 'unasked') === 0);
    check('retire: a composite of another secret goes, with its keys and its definition',
        $rowOf($PID, $oldComp) === null && $keysOf($PID, $oldComp) === 0
        && !$A->query("SELECT 1 FROM $dt WHERE project_id = $PID AND field_name = '$oldComp'")->fetch_row());
    $order = array();
    foreach ($mod->log as $i => $q) {
        if (in_array('unasked', $q[1], true) && preg_match('/^\s*(UPDATE|DELETE FROM) (\S+)/', $q[0], $mm)) $order[] = $mm[1] . ' ' . $mm[2];
    }
    check('retire: a dropped entry stops answering before its keys go (got ' . json_encode($order) . ')',
        isset($order[0]) && $order[0] === "UPDATE $ft" && in_array("DELETE FROM $kt", $order, true));
    $r = (new LookupIndex($mod, array('key' => $KEY)))->maintain(time() + 60);
    $mod->log = array();
    $got = $ask(array('tok' => null), 'DUP-1');
    check('retire: the cron builds the queued field, and a lookup answers from it without building (got ' . json_encode(array($r, $got)) . ')',
        $r['built'] >= 1 && $got === array('S1', 'S3') && $statements('INSERT INTO ' . $kt) === array());
    // A field to wipe whose old keys the cron cannot clear by its deadline:
    // the build stops before it writes a key, with wipe still set, and the
    // next run clears the rest and builds it.
    $curText = LookupIndex::KEY_VERSION . '|' . $PRINT . '|text';
    $A->query("INSERT INTO $ft (project_id, field_name, spec, ready, built_at, gen, building_at, wanted_at, asked_at, wipe)
        VALUES ($PID, 'scan', '$curText', 0, 0, 5, 0, 1, $now, 1)
        ON DUPLICATE KEY UPDATE spec = VALUES(spec), ready = 0, gen = GREATEST(gen, 5), building_at = 0, wanted_at = 1, asked_at = $now, wipe = 1");
    $genScan = (int) $A->query("SELECT gen FROM $ft WHERE project_id = $PID AND field_name = 'scan'")->fetch_row()[0];
    $vals = array();
    for ($i = 0; $i <= LookupIndex::DELETE_ROWS; $i++) {
        $vals[] = "($PID, 'scan', UNHEX('" . md5('w' . $i) . "'), 'R1', 1)";
        if (count($vals) === 2000) { $A->query("INSERT INTO $kt (project_id, field_name, key_hash, record, gen) VALUES " . implode(', ', $vals)); $vals = array(); }
    }
    if ($vals) $A->query("INSERT INTO $kt (project_id, field_name, key_hash, record, gen) VALUES " . implode(', ', $vals));
    $slept = false;
    $mod->after = function ($sql) use (&$slept) {
        if (!$slept && strpos($sql, 'DELETE FROM') === 0 && strpos($sql, 'lookup_key') !== false) { $slept = true; sleep(3); }
    };
    $r = (new LookupIndex($mod, array('key' => $KEY)))->maintain(time() + 2);
    $mod->after = null;
    $scanRow = $A->query("SELECT ready, wipe, wanted_at, building_at FROM $ft WHERE project_id = $PID AND field_name = 'scan'")->fetch_row();
    $written = (int) $A->query("SELECT COUNT(*) FROM $kt WHERE project_id = $PID AND field_name = 'scan' AND gen > $genScan")->fetch_row()[0];
    check('retire: a field to wipe the cron cannot clear in time writes no key and keeps wipe set (got ' . json_encode(array($r, $scanRow, $written)) . ')',
        (int) $scanRow[0] === 0 && (int) $scanRow[1] === 1 && (int) $scanRow[2] > 0 && (int) $scanRow[3] === 0
        && $written === 0 && $keysOf($PID, 'scan') > 0);
    $r = (new LookupIndex($mod, array('key' => $KEY)))->maintain(time() + 60);
    $scanRow = $A->query("SELECT ready, wipe FROM $ft WHERE project_id = $PID AND field_name = 'scan'")->fetch_row();
    check('retire: and the next run clears the rest and builds it, one key a record (got ' . json_encode(array($r, $scanRow, $keysOf($PID, 'scan'))) . ')',
        (int) $scanRow[0] === 1 && (int) $scanRow[1] === 0 && $keysOf($PID, 'scan') === 301 && $ask(array('scan' => null), 'dup-1') === array('S2'));
    // A field its own lookup may build is not queued: the cron's last run is
    // recent, so a queued field would wait for it while its lookups read the table.
    $A->query("UPDATE $ft SET spec = '1|point', ready = 1, wanted_at = 0, asked_at = $now WHERE project_id = $PID AND field_name = 'num'");
    (new LookupIndex($mod, array('key' => $KEY)))->retireOld(time() + 60);
    $num = $rowOf($PID, 'num');
    check('retire: a small field takes the current spec and is left to its next lookup (got ' . json_encode($num) . ')',
        $num['spec'] === LookupIndex::KEY_VERSION . '|' . $PRINT . '|point' && $num['wanted'] === 0 && $num['ready'] === 0
        && $ask(array('num' => 'point'), '7') === array('S4', 'S5', 'S6') && $rowOf($PID, 'num')['ready'] === 1);
    // An entry a lookup built again after retireOld listed it is left alone.
    $A->query("INSERT INTO $ft (project_id, field_name, spec, ready, built_at, gen, building_at, wanted_at, asked_at, wipe) VALUES
        ($PID, 'race_f', '1|text', 1, $now, 1, 0, 0, " . ($now - LookupIndex::UNUSED_AFTER - 60) . ", 0)");
    $current = LookupIndex::KEY_VERSION . '|' . $PRINT . '|text';
    $mod->after = function ($sql) use ($A, $ft, $PID, $current) {
        if (strpos($sql, 'spec NOT LIKE') !== false) {
            $A->query("UPDATE $ft SET spec = '$current', asked_at = " . time() . " WHERE project_id = $PID AND field_name = 'race_f'");
        }
    };
    (new LookupIndex($mod, array('key' => $KEY)))->retireOld(time() + 60);
    $mod->after = null;
    $race = $rowOf($PID, 'race_f');
    check('retire: an entry built again since the list was read is read again under the lock, and left as it was (got ' . json_encode($race) . ')',
        $race !== null && $race['spec'] === $current && $race['ready'] === 1 && $race['wipe'] === 0);
    $A->query("DELETE FROM $ft WHERE project_id = $PID AND field_name = 'race_f'");
    // Out of time inside a field's keys: no DELETE page starts after the
    // deadline, and the entry stays, with its spec, for the next run.
    $A->query("INSERT INTO $ft (project_id, field_name, spec, ready, built_at, gen, building_at, wanted_at, asked_at, wipe) VALUES
        ($PID, 'many_f', '1|text', 1, $now, 1, 0, 0, " . ($now - LookupIndex::UNUSED_AFTER - 60) . ", 0)");
    $vals = array();
    for ($i = 0; $i <= LookupIndex::DELETE_ROWS; $i++) {
        $vals[] = "($PID, 'many_f', UNHEX('" . md5('k' . $i) . "'), 'R1', 1)";
        if (count($vals) === 2000) { $A->query("INSERT INTO $kt (project_id, field_name, key_hash, record, gen) VALUES " . implode(', ', $vals)); $vals = array(); }
    }
    if ($vals) $A->query("INSERT INTO $kt (project_id, field_name, key_hash, record, gen) VALUES " . implode(', ', $vals));
    $slept = false;
    $mod->after = function ($sql) use (&$slept) {
        if (!$slept && strpos($sql, 'DELETE FROM') === 0 && strpos($sql, 'lookup_key') !== false) { $slept = true; sleep(3); }
    };
    $r = (new LookupIndex($mod, array('key' => $KEY)))->retireOld(time() + 2);
    $mod->after = null;
    check('retire: out of time inside a field\'s keys, it stops between pages and keeps the entry (got ' . json_encode(array($r, $keysOf($PID, 'many_f'))) . ')',
        $r['left'] >= 1 && $rowOf($PID, 'many_f') !== null && $rowOf($PID, 'many_f')['ready'] === 0 && $keysOf($PID, 'many_f') === 1);
    (new LookupIndex($mod, array('key' => $KEY)))->retireOld(time() + 60);
    check('retire: and the next run finishes it', $rowOf($PID, 'many_f') === null && $keysOf($PID, 'many_f') === 0);
    // maintain() as well, for a current field no lookup asked for in
    // UNUSED_AFTER: it stops between pages and keeps the entry.
    $A->query("INSERT INTO $ft (project_id, field_name, spec, ready, built_at, gen, building_at, wanted_at, asked_at, wipe) VALUES
        ($PID, 'many_m', '$curText', 1, 0, 1, 0, 0, " . ($now - LookupIndex::UNUSED_AFTER - 60) . ", 0)");
    $vals = array();
    for ($i = 0; $i <= LookupIndex::DELETE_ROWS; $i++) {
        $vals[] = "($PID, 'many_m', UNHEX('" . md5('m' . $i) . "'), 'R1', 1)";
        if (count($vals) === 2000) { $A->query("INSERT INTO $kt (project_id, field_name, key_hash, record, gen) VALUES " . implode(', ', $vals)); $vals = array(); }
    }
    if ($vals) $A->query("INSERT INTO $kt (project_id, field_name, key_hash, record, gen) VALUES " . implode(', ', $vals));
    $slept = false;
    $mod->after = function ($sql) use (&$slept) {
        if (!$slept && strpos($sql, 'DELETE FROM') === 0 && strpos($sql, 'lookup_key') !== false) { $slept = true; sleep(3); }
    };
    $r = (new LookupIndex($mod, array('key' => $KEY)))->maintain(time() + 2);
    $mod->after = null;
    check('maintain: out of time inside an unused field\'s keys, it stops between pages and keeps the entry (got '
        . json_encode(array($r, $keysOf($PID, 'many_m'))) . ')',
        $r['left'] >= 1 && $r['dropped'] === 0 && $rowOf($PID, 'many_m') !== null && $rowOf($PID, 'many_m')['ready'] === 0 && $keysOf($PID, 'many_m') === 1);
    $r = (new LookupIndex($mod, array('key' => $KEY)))->maintain(time() + 60);
    check('maintain: and the next run drops it (got ' . json_encode($r) . ')',
        $rowOf($PID, 'many_m') === null && $keysOf($PID, 'many_m') === 0);
    // Without a secret, only key version 1 goes: whether a current entry was
    // keyed with the secret that may come back cannot be told.
    $A->query("INSERT INTO $ft (project_id, field_name, spec, ready, built_at, gen, building_at, wanted_at, asked_at, wipe) VALUES
        ($PID, 'v1_nokey', '1|text', 1, $now, 1, 0, 0, $now, 0), ($PID, 'print_nokey', '2|ffffffff|point', 1, $now, 1, 0, 0, $now, 0)");
    (new LookupIndex($mod, array()))->retireOld(time() + 60);
    check('retire: with no secret, key version 1 goes and a current version stays',
        $rowOf($PID, 'v1_nokey') === null && $rowOf($PID, 'print_nokey') !== null);
    $r = (new LookupIndex($mod, array('key' => $KEY)))->retireOld(time() - 1);
    check('retire: past its deadline it leaves the rest for the next run (got ' . json_encode($r) . ')',
        $r['left'] >= 1 && $r['retired'] + $r['requeued'] === 0 && $rowOf($PID, 'print_nokey')['spec'] === '2|ffffffff|point');
    (new LookupIndex($mod, array('key' => $KEY)))->retireOld(time() + 60);
    check('retire: a field of another secret is queued under this one, with its number mark',
        $rowOf($PID, 'print_nokey')['spec'] === LookupIndex::KEY_VERSION . '|' . $PRINT . '|point');
    // It has no data: leave it out of the builds the cases below count.
    $A->query("DELETE FROM $ft WHERE project_id = $PID AND field_name = 'print_nokey'");
}

/* ---- composite entries (2.5.0) ---------------------------------------------------------
 * An arm code narrows nothing on its own: half the project holds it. Keyed with
 * the site (and the event or the group, as asked) it narrows to the records of
 * one stratum, and a count of them reads that many keys.
 */
{
    $P4 = 960;
    uv_redcap_project($A, $P4);
    $row = function ($rec, $field, $value, $event = 1, $instance = null) use ($P4) {
        return sprintf("(%d, %d, '%s', '%s', '%s', %s)", $P4, $event, $rec, $field, $value, $instance === null ? 'NULL' : (int) $instance);
    };
    // 400 records: arm 1 or 2, site A to D. C1..C400; C(i) is arm 1 + (i % 2), site chr(65 + i % 4).
    $rows = array();
    for ($i = 1; $i <= 400; $i++) {
        $rows[] = $row("C$i", 'arm', (string) (1 + $i % 2));
        $rows[] = $row("C$i", 'site', chr(65 + $i % 4));
        $rows[] = $row("C$i", '__GROUPID__', (string) (10 + $i % 3));
    }
    $A->query('INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`, instance) VALUES ' . implode(', ', $rows));
    $A->query("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event, ts, object_type) VALUES (500000, $P4, 'C1', 'INSERT', $old, 'redcap_data')");
    $A->query('ANALYZE TABLE redcap_data');
    $logId = 500000;
    $def = array('slot' => 'arm', 'with' => array('site'), 'scope' => 'project');
    $comp = function ($def, $value, array $with, $after = '', $limit = 0, array $extra = array()) use ($mod, $P4, $KEY) {
        return (new LookupIndex($mod, array('key' => $KEY)))->compositeCandidates($P4, $def, array('value' => $value, 'with' => $with) + $extra, $after, $limit);
    };
    $expect = function ($arm, $site, $dag = null) {
        $out = array();
        for ($i = 1; $i <= 400; $i++) {
            if ((string) (1 + $i % 2) === $arm && chr(65 + $i % 4) === $site && ($dag === null || (string) (10 + $i % 3) === $dag)) $out[] = "C$i";
        }
        sort($out, SORT_STRING);
        return $out;
    };
    $got = $comp($def, '2', array('site' => 'b'));
    check('composite: arm 2 at site B is the 100 records of that stratum, in byte order (got ' . count((array) $got) . ')',
        $got === $expect('2', 'B'));
    check('composite: a value no record holds has no candidate', $comp($def, '3', array('site' => 'B')) === array()
        && $comp($def, '2', array('site' => 'Z')) === array());
    $page1 = $comp($def, '2', array('site' => 'B'), '', 3);
    $page2 = $comp($def, '2', array('site' => 'B'), end($page1), 3);
    check('composite: a page of 3, then the 3 after it', $page1 === array_slice($expect('2', 'B'), 0, 3)
        && $page2 === array_slice($expect('2', 'B'), 3, 3));
    $mod->log = array();
    $comp($def, '2', array('site' => 'B'), 'C1', 40);
    $read = array_values(array_filter($mod->log, function ($q) { return strpos($q[0], 'SELECT HEX(record) FROM') === 0; }));
    $key = $read ? uv_explain_key($A, $read[0][0], $read[0][1]) : '';
    check('composite: a page is one range of the primary key, with the LIMIT in the statement (plan '
        . json_encode($read ? uv_explain_rows($A, $read[0][0], $read[0][1]) : null) . ')',
        count($read) === 1 && $key === 'PRIMARY' && strpos($read[0][0], 'LIMIT 40') !== false
        && strpos($read[0][0], 'ORDER BY record') !== false && !array_filter($mod->log, function ($q) { return strpos($q[0], 'FROM redcap_data') !== false; }));
    check('composite: an entry is named "#" and its definition is stored',
        (bool) $A->query('SELECT 1 FROM ' . Schema::table('lookup_def') . " WHERE project_id = $P4 AND field_name LIKE '#%'")->fetch_row());
    check('composite: a definition this class does not accept is no answer',
        $comp(array('slot' => 'arm', 'with' => array('arm')), '2', array()) === null
        && $comp(array('slot' => 'Arm'), '2', array()) === null && $comp(array('slot' => 'arm', 'scope' => 'record'), '2', array()) === null);
    check('composite: nor is a repeat bucket that is not an event id and a form name or blank',
        $comp(array('slot' => 'arm', 'rep' => array('arm' => array(0 => 'f'))), '2', array()) === null
        && $comp(array('slot' => 'arm', 'rep' => array('arm' => array('x' => 'f'))), '2', array()) === null
        && $comp(array('slot' => 'arm', 'rep' => array('arm' => array(1 => 'Bad Form'))), '2', array()) === null
        && $comp(array('slot' => 'arm', 'rep' => array('arm' => 'f')), '2', array()) === null
        && $comp(array('slot' => 'arm', 'rep' => array('arm' => array(1 => 2))), '2', array()) === null);

    // A change to the "with" field only moves the record to its new stratum.
    $A->query("UPDATE redcap_data SET `value` = 'D' WHERE project_id = $P4 AND record = 'C2' AND field_name = 'site'");
    $log($P4, 'C2');
    $b = $comp($def, '1', array('site' => 'C'));
    $d = $comp($def, '1', array('site' => 'D'));
    check('composite: a record whose site changed is found at the new site, not the old',
        in_array('C2', $d, true) && !in_array('C2', $b, true));

    // Scope dag: the group is part of the key, and a move to another group is a log row for the record.
    $dagDef = array('slot' => 'arm', 'with' => array('site'), 'scope' => 'dag');
    $got = $comp($dagDef, '2', array('site' => 'B'), '', 0, array('dag' => '11'));
    check('composite: scope dag keys the group too (got ' . count((array) $got) . ')', $got === $expect('2', 'B', '11'));
    $moved = $expect('2', 'B', '11')[0];
    $A->query("UPDATE redcap_data SET `value` = '12' WHERE project_id = $P4 AND record = '$moved' AND field_name = '__GROUPID__'");
    $log($P4, $moved);
    check('composite: a record moved to another group is found in that group only',
        in_array($moved, $comp($dagDef, '2', array('site' => 'B'), '', 0, array('dag' => '12')), true)
        && !in_array($moved, $comp($dagDef, '2', array('site' => 'B'), '', 0, array('dag' => '11')), true));
    $A->query("UPDATE redcap_data SET `value` = '' WHERE project_id = $P4 AND record = '$moved' AND field_name = '__GROUPID__'");
    $log($P4, $moved);
    check('composite: a record taken out of its group is in no group (\'\')',
        in_array($moved, $comp($dagDef, '2', array('site' => 'B'), '', 0, array('dag' => '')), true));

    // Events and repeating instances. R1: arm 7 in event 1 at site A, and in
    // event 2 on visit_form, which repeats there (instances 1 and 2), with
    // site on the event's row (B) and visit_site on visit_form. The data table
    // stores instance 1 with no instance, as it stores the event's row.
    $A->query('INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`, instance) VALUES '
        . implode(', ', array($row('R1', 'arm', '7'), $row('R1', 'site', 'A'),
            $row('R1', 'arm', '7', 2), $row('R1', 'site', 'B', 2),
            $row('R1', 'arm', '8', 2, 2), $row('R1', 'visit_site', 'Q', 2, 2))));
    $log($P4, 'R1');
    $armRep = array('arm' => array(2 => 'visit_form'));
    $evDef = array('slot' => 'arm', 'with' => array('site'), 'scope' => 'event', 'rep' => $armRep);
    check('composite: scope event keys the event: arm 7 at site A in event 1, at site B in event 2',
        $comp($evDef, '7', array('site' => 'A'), '', 0, array('event' => '1')) === array('R1')
        && $comp($evDef, '7', array('site' => 'A'), '', 0, array('event' => '2')) === array()
        && $comp($evDef, '7', array('site' => 'B'), '', 0, array('event' => '2')) === array('R1'));
    $r1Def = $def + array('rep' => $armRep);
    check('composite: an instance reads a field that does not repeat from its event\'s row',
        $comp($r1Def, '8', array('site' => 'B')) === array('R1') && $comp($r1Def, '7', array('site' => 'B')) === array('R1'));
    check('composite: a field that does not repeat there counts only on instance 1',
        $comp($def, '8', array('site' => 'B')) === array() && $comp($def, '7', array('site' => 'B')) === array('R1'));
    $repDef = array('slot' => 'arm', 'with' => array('visit_site'), 'scope' => 'project',
        'rep' => array('arm' => array(2 => 'visit_form'), 'visit_site' => array(2 => 'visit_form')));
    check('composite: and a field of the same bucket from the instance itself; blank where the instance has none',
        $comp($repDef, '8', array('visit_site' => 'Q')) === array('R1')
        && $comp($repDef, '7', array('visit_site' => '')) === array('R1'));

    // The entries are the scan's (ScanRuns::recordContexts()). RA: arm on event
    // 3's row, wsite on f_w, which repeats there (instances 1 and 2): the row is
    // an entry with no wsite, and each instance one with the row's arm.
    // RB: arm on r1 and wsite on r2, both repeating in event 4: no entry holds both.
    $A->query('INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`, instance) VALUES '
        . implode(', ', array($row('RA', 'arm', 'V5', 3), $row('RA', 'wsite', 'A', 3), $row('RA', 'wsite', 'X', 3, 2),
            $row('RB', 'arm', 'V6', 4), $row('RB', 'wsite', 'Z', 4))));
    $log($P4, 'RA');
    $log($P4, 'RB');
    $aDef = array('slot' => 'arm', 'with' => array('wsite'), 'scope' => 'project', 'rep' => array('wsite' => array(3 => 'f_w')));
    check('composite: a row value is keyed with each instance of another bucket, and alone',
        $comp($aDef, 'V5', array('wsite' => '')) === array('RA') && $comp($aDef, 'V5', array('wsite' => 'a')) === array('RA')
        && $comp($aDef, 'V5', array('wsite' => 'X')) === array('RA'));
    $bDef = array('slot' => 'arm', 'with' => array('wsite'), 'scope' => 'project',
        'rep' => array('arm' => array(4 => 'r1'), 'wsite' => array(4 => 'r2')));
    check('composite: values of two buckets are never keyed together',
        $comp($bDef, 'V6', array('wsite' => '')) === array('RB') && $comp($bDef, 'V6', array('wsite' => 'Z')) === array());

    // IN() compares by the data table's collation, so a record of another
    // letter case comes back with a batch it is not in. It keeps its keys.
    // Only a collation that ties the two tests this: the fixture's must.
    $A->query('INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`, instance) VALUES '
        . implode(', ', array($row('CV', 'arm', '5'), $row('CV', 'site', 'A'), $row('cv', 'arm', '5'), $row('cv', 'site', 'A'))));
    $tied = (int) $A->query("SELECT COUNT(*) FROM redcap_data WHERE project_id = $P4 AND record = 'cv'")->fetch_row()[0];
    $log($P4, 'CV');
    $log($P4, 'cv');
    $comp($def, '5', array('site' => 'A'));
    (new LookupIndex($mod, array('key' => $KEY)))->candidates($P4, array('arm' => null), '5');
    $log($P4, 'CV');
    $got = $comp($def, '5', array('site' => 'A'));
    check('composite: a record whose letter-case twin was indexed again keeps its keys (got ' . json_encode(array($got, $tied)) . ')',
        $tied === 4 && $got === array('CV', 'cv'));

    // A sync with nothing new for this project reads no definitions and no
    // changed records, even when the shared log moved for another project.
    $log($PID, 'R1');
    $mod->log = array();
    $comp($def, '2', array('site' => 'B'));
    check('composite: a log row of another project costs no read of definitions or changes',
        $statements(Schema::table('lookup_def')) === array() && $statements('CAST(pk AS BINARY)') === array());

    // An entry that answers and whose definition is gone cannot be kept
    // current: the sync stops it answering before it moves on, and the next
    // lookup builds it again.
    $name4 = '#' . md5(json_encode(array('arm', array('site'), 'project')));
    $gen4 = function () use ($A, $P4, $name4) {
        $r = $A->query('SELECT ready, gen FROM ' . Schema::table('lookup_field') . " WHERE project_id = $P4 AND field_name = '$name4'")->fetch_row();
        return array((int) $r[0], (int) $r[1]);
    };
    $before = $gen4();
    $A->query('DELETE FROM ' . Schema::table('lookup_def') . " WHERE project_id = $P4 AND field_name = '$name4'");
    $A->query("UPDATE redcap_data SET `value` = 'D' WHERE project_id = $P4 AND record = 'C4' AND field_name = 'site'");
    $log($P4, 'C4');
    (new LookupIndex($mod, array('key' => $KEY)))->candidates($P4, array('arm' => null), '1');
    $after = $gen4();
    check('composite: an entry whose definition went stops answering at the next sync (got ' . json_encode(array($before, $after)) . ')',
        $before[0] === 1 && $after[0] === 0 && $after[1] > $before[1]);
    check('composite: and is built again, with the change',
        in_array('C4', (array) $comp($def, '1', array('site' => 'D')), true) && !in_array('C4', (array) $comp($def, '1', array('site' => 'A')), true));

    // A build the cron cannot finish by its deadline pauses, keeps its place,
    // and is kept current meanwhile; the next run finishes it.
    $P5 = 970;
    uv_redcap_project($A, $P5);
    $rows = array();
    for ($i = 1; $i <= 1200; $i++) {
        $rows[] = sprintf("(%d, 1, 'P%04d', 'arm', '%d', NULL)", $P5, $i, 1 + $i % 2);
        $rows[] = sprintf("(%d, 1, 'P%04d', 'site', 'S', NULL)", $P5, $i);
    }
    foreach (array_chunk($rows, 1000) as $part) {
        $A->query('INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`, instance) VALUES ' . implode(', ', $part));
    }
    $A->query("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event, ts, object_type) VALUES (600000, $P5, 'P0001', 'INSERT', $old, 'redcap_data')");
    $logId = 600000;
    $cron = function () use ($mod, $KEY) { return new LookupIndex($mod, array('liveBuildRows' => 50, 'key' => $KEY)); };
    $askP5 = function ($value) use ($cron, $P5, $def) { return $cron()->compositeCandidates($P5, $def, array('value' => $value, 'with' => array('site' => 'S'))); };
    $name = '#' . md5(json_encode(array('arm', array('site'), 'project')));
    $state = function () use ($A, $P5, $name) {
        $f = $A->query('SELECT ready, wanted_at, gen, building_at FROM ' . Schema::table('lookup_field') . " WHERE project_id = $P5 AND field_name = '$name'")->fetch_row();
        $d = $A->query('SELECT build_cursor, build_gen FROM ' . Schema::table('lookup_def') . " WHERE project_id = $P5 AND field_name = '$name'")->fetch_row();
        return array('ready' => $f ? (int) $f[0] : null, 'wanted' => $f ? (int) $f[1] : null, 'gen' => $f ? (int) $f[2] : null,
            'building' => $f ? (int) $f[3] : null, 'cursor' => $d ? (string) $d[0] : null, 'cgen' => $d ? (int) $d[1] : null);
    };
    // 1,200 arm rows fit in 1,500, but the build reads site's 1,200 as well.
    check('composite: a lookup counts the rows of every field of the entry before it builds one itself',
        (new LookupIndex($mod, array('liveBuildRows' => 1500, 'key' => $KEY)))->compositeCandidates($P5, $def,
            array('value' => '1', 'with' => array('site' => 'S'))) === null);
    // From no row: the lookup above marked the entry as wanted, which alone
    // would keep a lookup from building it.
    $A->query('DELETE FROM ' . Schema::table('lookup_field') . " WHERE project_id = $P5");
    $A->query('DELETE FROM ' . Schema::table('lookup_def') . " WHERE project_id = $P5");
    check('composite: an entry too large for a lookup is left to the cron, marked as wanted',
        $askP5('1') === null && $state()['wanted'] > 0 && $state()['ready'] === 0);
    $slept = false;
    $mod->after = function ($sql) use (&$slept) {
        if (!$slept && strpos($sql, 'SELECT record, field_name, `value`, event_id, instance FROM') === 0) { $slept = true; sleep(3); }
    };
    $r = $cron()->maintain(time() + 2);
    $mod->after = null;
    $s = $state();
    check('composite: past its deadline the cron build pauses after one batch, keeping its place (got ' . json_encode(array($r, $s)) . ')',
        $r['built'] === 0 && $s['ready'] === 0 && $s['building'] === 0 && $s['wanted'] > 0
        && $s['cursor'] === sprintf('lP%04d', LookupIndex::BATCH) && $s['cgen'] === $s['gen']);
    // P0003 (already written) and P1100 (not yet) change while it waits. A key
    // of an older generation (one an earlier build left) goes when it ends.
    $A->query("UPDATE redcap_data SET `value` = '9' WHERE project_id = $P5 AND record IN ('P0003', 'P1100') AND field_name = 'arm'");
    $log($P5, 'P0003');
    $log($P5, 'P1100');
    $A->query('INSERT INTO ' . Schema::table('lookup_key') . " (project_id, field_name, key_hash, record, gen)
        VALUES ($P5, '$name', UNHEX(MD5('older')), 'P9999', " . ($s['gen'] - 1) . ')');
    $r = $cron()->maintain(time() + 60);
    $s = $state();
    check('composite: the next run goes on from there and finishes (got ' . json_encode(array($r, $s)) . ')',
        $r['built'] === 1 && $s['ready'] === 1 && $s['cursor'] === '' && $s['wanted'] === 0);
    check('composite: a record written before the pause and changed during it is found under its new value only',
        $askP5('9') === array('P0003', 'P1100') && !in_array('P0003', (array) $askP5('2'), true));
    $all = (int) $A->query('SELECT COUNT(*) FROM ' . Schema::table('lookup_key') . " WHERE project_id = $P5 AND field_name = '$name'")->fetch_row()[0];
    check('composite: one key per record, none left from the paused part (got ' . $all . ')', $all === 1200);
    // The cron drops a composite entry whose definition is gone.
    $A->query('DELETE FROM ' . Schema::table('lookup_def') . " WHERE project_id = $P5 AND field_name = '$name'");
    $A->query('UPDATE ' . Schema::table('lookup_field') . " SET wanted_at = 1 WHERE project_id = $P5 AND field_name = '$name'");
    $r = $cron()->maintain(time() + 60);
    check('composite: an entry whose definition is gone is dropped with its keys (got ' . json_encode($r) . ')',
        $r['dropped'] === 1 && (int) $A->query('SELECT COUNT(*) FROM ' . Schema::table('lookup_key') . " WHERE project_id = $P5 AND field_name = '$name'")->fetch_row()[0] === 0);

    // The listing: records that hold the first field, listRecords at a time
    // in byte order, which is not the collation's ("10" before "9", "B"
    // before "a"). 12 records, 5 a read, take 3 reads (5, 5 and the rest),
    // with no record index to walk. 'blank' holds only spaces: no key.
    $P6 = 980;
    uv_redcap_project($A, $P6);
    $ids = array('9', '10', '100', 'A1', 'a1', 'Ab', 'aB', 'b', 'B', 'z', 'Z0');
    $rows = array();
    foreach ($ids as $id) {
        $e = $A->real_escape_string($id);
        $rows[] = "($P6, 1, '$e', 'arm', '1', NULL), ($P6, 1, '$e', 'site', 'S', NULL)";
    }
    $rows[] = "($P6, 1, 'blank', 'arm', '  ', NULL)";
    $A->query('INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`, instance) VALUES ' . implode(', ', $rows));
    $A->query("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event, ts, object_type) VALUES (700000, $P6, '9', 'INSERT', $old, 'redcap_data')");
    $logId = 700000;
    $want = $ids;
    sort($want, SORT_STRING);
    // A listing that cannot be read fails the build: the entry is not marked
    // built and the lookup reads the data table.
    $mod->inner->failOn = 'SELECT DISTINCT CAST(record AS BINARY) AS r';
    $got = (new LookupIndex($mod, array('key' => $KEY, 'listRecords' => 5)))->compositeCandidates($P6, $def, array('value' => '1', 'with' => array('site' => 'S')));
    $mod->inner->failOn = null;
    $ready6 = $A->query('SELECT ready FROM ' . Schema::table('lookup_field') . " WHERE project_id = $P6")->fetch_row();
    check('composite: a listing that cannot be read is not marked built (got ' . json_encode(array($got, $ready6)) . ')',
        $got === null && $ready6 !== null && (int) $ready6[0] === 0);
    $mod->log = array();
    $got = (new LookupIndex($mod, array('key' => $KEY, 'listRecords' => 5)))->compositeCandidates($P6, $def, array('value' => '1', 'with' => array('site' => 'S')));
    $lists = $statements('SELECT DISTINCT CAST(record AS BINARY) AS r');
    $all = (int) $A->query('SELECT COUNT(*) FROM ' . Schema::table('lookup_key') . " WHERE project_id = $P6")->fetch_row()[0];
    check('composite: a listing build reads 5 records a time in byte order, 3 reads for 12, and keys the 11 with a value once (got '
        . json_encode(array(count($lists), $got, $all)) . ')',
        $got === $want && count($lists) === 3 && $all === 11 && strpos($lists[0][0], 'LIMIT 6') !== false);

    // The walk: a field of more than listRecords rows, with REDCap's record
    // index complete, goes through every record of the project from that
    // index, each read once, and never lists the field. W01..W30, with 'w05'
    // tied to 'W05' by the collation; the arm on the odd ones and on w05.
    uv_redcap_counts_schema($A);
    $P7 = 985;
    uv_redcap_project($A, $P7);
    uv_redcap_counts($A, $P7, 'COMPLETE');
    $rows = array();
    $list = array();
    $holders = array();
    $walkIds = array('w05');
    for ($i = 1; $i <= 30; $i++) $walkIds[] = sprintf('W%02d', $i);
    foreach ($walkIds as $id) {
        $list[] = "($P7, 1, '$id', NULL)";
        $rows[] = "($P7, 1, '$id', 'record_id', '$id', NULL)";
        if ($id === 'w05' || (int) substr($id, 1) % 2 === 1) {
            $rows[] = "($P7, 1, '$id', 'arm', '1', NULL), ($P7, 1, '$id', 'site', 'S', NULL)";
            $holders[] = $id;
        }
    }
    sort($holders, SORT_STRING);
    $A->query('INSERT INTO redcap_record_list (project_id, arm, record, dag_id) VALUES ' . implode(', ', $list));
    $A->query('INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`, instance) VALUES ' . implode(', ', $rows));
    $A->query("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event, ts, object_type) VALUES (710000, $P7, 'W01', 'INSERT', $old, 'redcap_data')");
    $logId = 710000;
    $walker = function () use ($mod, $KEY) { return new LookupIndex($mod, array('key' => $KEY, 'listRecords' => 5, 'liveBuildRows' => 5)); };
    $askP7 = function ($value) use ($walker, $P7, $def) { return $walker()->compositeCandidates($P7, $def, array('value' => $value, 'with' => array('site' => 'S'))); };
    $askP7('1');    // too large for a lookup: wanted
    $name7 = '#' . md5(json_encode(array('arm', array('site'), 'project')));
    $cur7 = function () use ($A, $P7, $name7) {
        $d = $A->query('SELECT build_cursor FROM ' . Schema::table('lookup_def') . " WHERE project_id = $P7 AND field_name = '$name7'")->fetch_row();
        return $d ? (string) $d[0] : null;
    };
    // BATCH is 500 records; with 31 the walk is one page, so a deadline that
    // passes during the first page's keys leaves nothing to pause after.
    $mod->log = array();
    $r = $walker()->maintain(time() + 60);
    $walked = $statements('FROM redcap_record_list');
    check('composite: a walk build reads the record index and never lists the field (got ' . json_encode(array($r, count($walked))) . ')',
        $r['built'] >= 1 && count($walked) >= 1 && $statements('SELECT DISTINCT CAST(record AS BINARY) AS r') === array()
        && $cur7() === '');
    check('composite: and keys every record that holds the field, the collation\'s twins too (got ' . json_encode($askP7('1')) . ')',
        $askP7('1') === $holders);
    // A walk paused at W10 (its keys cleared here, to see which it writes)
    // goes on from there: the records the collation puts before W10 are not
    // read again.
    $pauseAt = function ($cursor) use ($A, $P7, $name7) {
        $A->query('DELETE FROM ' . Schema::table('lookup_key') . " WHERE project_id = $P7");
        $A->query('UPDATE ' . Schema::table('lookup_field') . " SET ready = 0, wanted_at = 1 WHERE project_id = $P7 AND field_name = '$name7'");
        $gen = (int) $A->query('SELECT gen FROM ' . Schema::table('lookup_field') . " WHERE project_id = $P7 AND field_name = '$name7'")->fetch_row()[0];
        $A->query('UPDATE ' . Schema::table('lookup_def') . " SET build_cursor = '$cursor', build_gen = $gen WHERE project_id = $P7 AND field_name = '$name7'");
    };
    // A page of the record index that cannot be read would fail at the same
    // place on every run: the build lists the field from the start instead,
    // in the same run, and keys every holder.
    $pauseAt('');
    $mod->inner->failOn = 'SELECT record, dag_id FROM redcap_record_list';
    $mod->log = array();
    $r = $walker()->maintain(time() + 60);
    $mod->inner->failOn = null;
    check('composite: a walk whose page cannot be read lists the field from the start instead (got ' . json_encode(array($r, $askP7('1'))) . ')',
        $r['built'] >= 1 && count($statements('SELECT DISTINCT CAST(record AS BINARY) AS r')) >= 1 && $askP7('1') === $holders);
    // A cursor that names neither way, as a build before the cursor named its
    // way wrote it: read as a listing's, "XW20" would go on after "W20" and
    // skip W01..W19. It starts again.
    $pauseAt('XW20');
    $r = $walker()->maintain(time() + 60);
    check('composite: a cursor of no known way starts the build again (got ' . json_encode(array($r, $askP7('1'))) . ')',
        $r['built'] >= 1 && $askP7('1') === $holders);
    // Paused on a record the collation ties to another: the resumed page
    // reads the tied pair again, so w05 is keyed as well as W05.
    $pauseAt('wW05');
    $r = $walker()->maintain(time() + 60);
    $from05 = array_values(array_filter($holders, function ($id) { return strcasecmp($id, 'W05') >= 0; }));
    check('composite: a walk paused on a tied record goes on with both of the pair (got ' . json_encode(array($r, $askP7('1'))) . ')',
        $r['built'] >= 1 && $askP7('1') === $from05 && in_array('w05', $from05, true) && in_array('W05', $from05, true));
    $pauseAt('wW10');
    $mod->log = array();
    $r = $walker()->maintain(time() + 60);
    $from10 = array_values(array_filter($holders, function ($id) { return strcasecmp($id, 'W10') >= 0; }));
    check('composite: a paused walk goes on from its cursor, in the collation\'s order (got ' . json_encode(array($r, $askP7('1'))) . ')',
        $r['built'] >= 1 && $askP7('1') === $from10 && $statements('SELECT DISTINCT CAST(record AS BINARY) AS r') === array());
    // Paused, then resumed after REDCap marked its record index incomplete:
    // the field is listed from the start instead.
    $pauseAt('wW10');
    uv_redcap_counts($A, $P7, 'PROCESSING');
    $mod->log = array();
    $r = $walker()->maintain(time() + 60);
    check('composite: a walk resumed over an index REDCap no longer calls complete lists the field from the start (got ' . json_encode($r) . ')',
        $r['built'] >= 1 && count($statements('SELECT DISTINCT CAST(record AS BINARY) AS r')) >= 1 && $askP7('1') === $holders);
    // A field of more than listRecords rows held by few records: one listing
    // reads its 8 rows; a walk would read the 31 records of the index. So it
    // is listed, though the index is complete again.
    uv_redcap_counts($A, $P7, 'COMPLETE');
    $rows = array();
    foreach (array('W02', 'W04') as $id) {
        for ($e = 1; $e <= 4; $e++) $rows[] = "($P7, $e, '$id', 'rep', '1', NULL), ($P7, $e, '$id', 'site', 'S', NULL)";
    }
    $A->query('INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`, instance) VALUES ' . implode(', ', $rows));
    $defRep = array('slot' => 'rep', 'with' => array('site'), 'scope' => 'project');
    $mod->log = array();
    $gotRep = (new LookupIndex($mod, array('key' => $KEY, 'listRecords' => 5)))->compositeCandidates($P7, $defRep, array('value' => '1', 'with' => array('site' => 'S')));
    check('composite: a large field few records hold is listed, not walked, over a complete index (got ' . json_encode($gotRep) . ')',
        $gotRep === array('W02', 'W04') && count($statements('SELECT DISTINCT CAST(record AS BINARY) AS r')) === 1
        && $statements('SELECT record, dag_id FROM redcap_record_list') === array());
    $A->query('DROP TABLE redcap_record_counts');

    // A walk of more than one page, paused at the end of its first: that page
    // ends on a pair the collation ties (B0500 and b0500). It goes on over the
    // pair and the pages after, keying each record once. With no status
    // table, the index is judged by RecordManifestSource's probe alone.
    $P11 = 986;
    uv_redcap_project($A, $P11);
    $rows = array();
    $list = array();
    $ids11 = array('b0500');
    for ($i = 1; $i <= 1001; $i++) $ids11[] = sprintf('B%04d', $i);
    foreach ($ids11 as $id) {
        $list[] = "($P11, 1, '$id', NULL)";
        $rows[] = "($P11, 1, '$id', 'arm', '1', NULL), ($P11, 1, '$id', 'site', 'S', NULL)";
    }
    foreach (array_chunk($list, 500) as $part) $A->query('INSERT INTO redcap_record_list (project_id, arm, record, dag_id) VALUES ' . implode(', ', $part));
    foreach (array_chunk($rows, 500) as $part) {
        $A->query('INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`, instance) VALUES ' . implode(', ', $part));
    }
    $A->query("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event, ts, object_type) VALUES (715000, $P11, 'B0001', 'INSERT', $old, 'redcap_data')");
    $logId = 715000;
    $ask11 = function () use ($walker, $P11, $def) { return $walker()->compositeCandidates($P11, $def, array('value' => '1', 'with' => array('site' => 'S'))); };
    $ask11();    // too large for a lookup: wanted
    $slept = false;
    $mod->after = function ($sql) use (&$slept) {
        if (!$slept && strpos($sql, 'SELECT record, field_name, `value`, event_id, instance FROM') === 0) { $slept = true; sleep(3); }
    };
    $mod->log = array();
    $r = $walker()->maintain(time() + 2);
    $mod->after = null;
    $cur11 = $A->query('SELECT build_cursor FROM ' . Schema::table('lookup_def') . " WHERE project_id = $P11")->fetch_row();
    check('composite: a walk past its deadline pauses after its first page of 500 (got ' . json_encode(array($r, $cur11)) . ')',
        $r['built'] === 0 && $cur11 !== null && in_array((string) $cur11[0], array('wB0500', 'wb0500'), true));
    $r = $walker()->maintain(time() + 60);
    $want11 = $ids11;
    sort($want11, SORT_STRING);
    $keys11 = (int) $A->query('SELECT COUNT(*) FROM ' . Schema::table('lookup_key') . " WHERE project_id = $P11")->fetch_row()[0];
    check('composite: and goes on over the tied pair and the pages after, keying each of 1,002 records once (got '
        . json_encode(array($r, $keys11)) . ')',
        $r['built'] >= 1 && $ask11() === $want11 && $keys11 === 1002 && $statements('SELECT DISTINCT CAST(record AS BINARY) AS r') === array());

    // A listing paused after a record id of 400 bytes (100 characters of 4
    // bytes each) keeps its cursor whole, under strict mode: one byte for its
    // way and the id.
    $P12 = 988;
    uv_redcap_project($A, $P12);
    $long = str_repeat("\u{1F600}", 100);
    $later = str_repeat("\u{1F600}", 99) . "\u{1F601}";
    $ids12 = array('a', 'b', 'c', 'd', $long, $later);
    $rows = array();
    foreach ($ids12 as $id) {
        $e = $A->real_escape_string($id);
        $rows[] = "($P12, 1, '$e', 'arm', '1', NULL), ($P12, 1, '$e', 'site', 'S', NULL)";
    }
    $A->query('INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`, instance) VALUES ' . implode(', ', $rows));
    $A->query("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event, ts, object_type) VALUES (716000, $P12, 'a', 'INSERT', $old, 'redcap_data')");
    $logId = 716000;
    $sqlMode = (string) $A->query('SELECT @@SESSION.sql_mode')->fetch_row()[0];
    $A->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'");
    $lister = function () use ($mod, $KEY) { return new LookupIndex($mod, array('key' => $KEY, 'listRecords' => 5, 'liveBuildRows' => 5)); };
    $ask12 = function () use ($lister, $P12, $def) { return $lister()->compositeCandidates($P12, $def, array('value' => '1', 'with' => array('site' => 'S'))); };
    $ask12();    // too large for a lookup: wanted
    $slept = false;
    $mod->after = function ($sql) use (&$slept) {
        if (!$slept && strpos($sql, 'SELECT record, field_name, `value`, event_id, instance FROM') === 0) { $slept = true; sleep(3); }
    };
    $lister()->maintain(time() + 2);
    $mod->after = null;
    $cur12 = $A->query('SELECT build_cursor FROM ' . Schema::table('lookup_def') . " WHERE project_id = $P12")->fetch_row();
    $building12 = (int) $A->query('SELECT building_at FROM ' . Schema::table('lookup_field') . " WHERE project_id = $P12")->fetch_row()[0];
    check('composite: a listing paused after a 400-byte record id saves a 401-byte cursor (got '
        . json_encode(array($cur12 === null ? null : strlen((string) $cur12[0]), $building12)) . ')',
        $cur12 !== null && (string) $cur12[0] === 'l' . $long && $building12 === 0);
    $r = $lister()->maintain(time() + 60);
    $A->query("SET SESSION sql_mode = '" . $A->real_escape_string($sqlMode) . "'");
    check('composite: and the next run goes on after it (got ' . json_encode($r) . ')',
        $r['built'] >= 1 && $ask12() === array('a', 'b', 'c', 'd', $long, $later));

    // A build paused under one secret, a request under another syncs past a
    // change to a record the build already wrote, and the first secret's cron
    // goes on: only the sync row's print stops that resume.
    $P8 = 990;
    uv_redcap_project($A, $P8);
    $rows = array();
    for ($i = 1; $i <= 1200; $i++) {
        $rows[] = sprintf("(%d, 1, 'Q%04d', 'arm', '%d', NULL), (%d, 1, 'Q%04d', 'site', 'S', NULL)", $P8, $i, 1 + $i % 2, $P8, $i);
    }
    foreach (array_chunk($rows, 500) as $part) {
        $A->query('INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`, instance) VALUES ' . implode(', ', $part));
    }
    $A->query("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event, ts, object_type) VALUES (720000, $P8, 'Q0001', 'INSERT', $old, 'redcap_data')");
    $logId = 720000;
    $cronA = function () use ($mod, $KEY) { return new LookupIndex($mod, array('liveBuildRows' => 50, 'key' => $KEY)); };
    $cronA()->compositeCandidates($P8, $def, array('value' => '1', 'with' => array('site' => 'S')));
    $slept = false;
    $mod->after = function ($sql) use (&$slept) {
        if (!$slept && strpos($sql, 'SELECT record, field_name, `value`, event_id, instance FROM') === 0) { $slept = true; sleep(3); }
    };
    $cronA()->maintain(time() + 2);
    $mod->after = null;
    $A->query("UPDATE redcap_data SET `value` = '7' WHERE project_id = $P8 AND record = 'Q0003' AND field_name = 'arm'");
    $log($P8, 'Q0003');
    (new LookupIndex($mod, array('key' => $other)))->candidates($P8, array('site' => null), 'S');
    $cronA()->maintain(time() + 60);
    $got = $cronA()->compositeCandidates($P8, $def, array('value' => '7', 'with' => array('site' => 'S')));
    check('composite: a build paused under one secret does not go on past a sync under another (got ' . json_encode($got) . ')',
        $got === array('Q0003'));

    // A plain build that runs past BUILD_STALE is still kept current: it may
    // yet end and mark the field built.
    $P9 = 995;
    uv_redcap_project($A, $P9);
    $A->query("INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`, instance) VALUES
        ($P9, 1, 'V1', 'f', 'one', NULL), ($P9, 1, 'V2', 'f', 'two', NULL), ($P9, 1, 'V1', 'g', 'x', NULL)");
    $A->query("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event, ts, object_type) VALUES (730000, $P9, 'V1', 'INSERT', $old, 'redcap_data')");
    $logId = 730000;
    $askP9 = function ($f, $v) use ($mod, $KEY, $P9) { return (new LookupIndex($mod, array('key' => $KEY)))->candidates($P9, array($f => null), $v); };
    $askP9('f', 'one');
    $ft9 = Schema::table('lookup_field');
    $A->query("UPDATE $ft9 SET ready = 0, building_at = " . (time() - LookupIndex::BUILD_STALE - 10) . " WHERE project_id = $P9 AND field_name = 'f'");
    $A->query("UPDATE redcap_data SET `value` = 'three' WHERE project_id = $P9 AND record = 'V2' AND field_name = 'f'");
    $log($P9, 'V2');
    $askP9('g', 'x');
    $A->query("UPDATE $ft9 SET ready = 1, building_at = 0 WHERE project_id = $P9 AND field_name = 'f'");
    check('lookup index: a build past BUILD_STALE is kept current, so when it ends the field holds the change',
        $askP9('f', 'three') === array('V2') && $askP9('f', 'two') === array());

    // A build of another spec deletes the old keys only once the field's row
    // says it does not answer, and clears a key an older generation left.
    $kt9 = Schema::table('lookup_key');
    $A->query("UPDATE $ft9 SET spec = '" . LookupIndex::KEY_VERSION . '|' . $PRINT . "|point' WHERE project_id = $P9 AND field_name = 'f'");
    $A->query("INSERT INTO $kt9 (project_id, field_name, key_hash, record, gen) VALUES ($P9, 'f', UNHEX(MD5('older')), 'V9', 0)");
    $readyAtDelete = null;
    $mod->after = function ($sql) use (&$readyAtDelete, $A, $ft9, $kt9, $P9) {
        if ($readyAtDelete === null && strpos($sql, 'DELETE FROM ' . $kt9) === 0) {
            $readyAtDelete = (int) $A->query("SELECT ready FROM $ft9 WHERE project_id = $P9 AND field_name = 'f'")->fetch_row()[0];
        }
    };
    $mod->log = array();
    $askP9('f', 'one');
    $mod->after = null;
    $first = array();
    foreach ($mod->log as $i => $q) {
        if (!isset($first['row']) && strpos($q[0], 'INSERT INTO ' . $ft9) !== false) $first['row'] = $i;
        if (!isset($first['del']) && strpos($q[0], 'DELETE FROM ' . $kt9) !== false) $first['del'] = $i;
    }
    $stray = (int) $A->query("SELECT COUNT(*) FROM $kt9 WHERE project_id = $P9 AND field_name = 'f' AND record = 'V9'")->fetch_row()[0];
    check('lookup index: a rebuild under another spec marks the field, then deletes keys while it does not answer (got '
        . json_encode(array($first, $readyAtDelete, $stray)) . ')',
        isset($first['row'], $first['del']) && $first['row'] < $first['del'] && $readyAtDelete === 0 && $stray === 0);

    // A lookup that finds a new spec for a field too large for it raises the
    // generation and ends the build marked on the row: a build of the old
    // spec past BUILD_STALE cannot mark the field built under the new one.
    $pointSpec = LookupIndex::KEY_VERSION . '|' . $PRINT . '|point';
    $A->query("UPDATE $ft9 SET spec = '$pointSpec', ready = 0, wanted_at = 0, building_at = " . (time() - LookupIndex::BUILD_STALE - 10)
        . " WHERE project_id = $P9 AND field_name = 'f'");
    $gen9 = (int) $A->query("SELECT gen FROM $ft9 WHERE project_id = $P9 AND field_name = 'f'")->fetch_row()[0];
    (new LookupIndex($mod, array('key' => $KEY, 'liveBuildRows' => 1)))->candidates($P9, array('f' => null), 'one');
    $f9 = $A->query("SELECT gen, building_at, spec, wanted_at FROM $ft9 WHERE project_id = $P9 AND field_name = 'f'")->fetch_row();
    check('lookup index: a new spec a lookup cannot build raises the generation and ends the old build (got ' . json_encode(array($gen9, $f9)) . ')',
        (int) $f9[0] === $gen9 + 1 && (int) $f9[1] === 0 && $f9[2] !== $pointSpec && (int) $f9[3] > 0);
}
