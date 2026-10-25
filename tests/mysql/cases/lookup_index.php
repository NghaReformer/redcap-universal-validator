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
    $ask = function (array $fields, $value) use ($mod, $PID, $sorted) {
        return $sorted((new LookupIndex($mod))->candidates($PID, $fields, $value));
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
        $st !== null && $st['ready'] === 1 && $st['spec'] === LookupIndex::KEY_VERSION . '|text' && $st['built'] > 0);
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
    $ix = new LookupIndex($mod);
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
    check('lookup index: [num] is keyed by number', $field('num')['spec'] === LookupIndex::KEY_VERSION . '|point');
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
    $small = function () use ($mod) { return new LookupIndex($mod, array('liveBuildRows' => 50)); };
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
    $mod->after = function ($sql, $params) use (&$during, $B, $P3, $A, $set, $log, &$mod) {
        if (strpos($sql, 'SELECT record, event_id, `value` FROM redcap_data') !== 0 || !in_array('big2', $params, true)) return;
        $mod->after = null;
        $set($P3, 'B9', 'big2', 'CHANGED-9');
        $log($P3, 'B9');
        $during = (new LookupIndex(new UvFlakyModule($B), array('liveBuildRows' => 50)))->candidates($P3, array('big' => null), 'big-8');
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
        (new LookupIndex(new UvFlakyModule($B), array('liveBuildRows' => 50)))->candidates($P3, array('big' => null), 'big-8');
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
    $mod->after = function ($sql, $params) use (&$during, $B, $P3, &$mod) {
        if (strpos($sql, 'SELECT record, event_id, `value` FROM redcap_data') !== 0 || !in_array('big', $params, true)) return;
        $mod->after = null;
        $during = (new LookupIndex(new UvFlakyModule($B), array('liveBuildRows' => 50)))->candidates($P3, array('big' => null), 'big-7');
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
    $busy = new LookupIndex($mod, array('liveBuildRows' => 50, 'cronAt' => time() - 60));
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
