<?php
/**
 * tests/mysql/cases/field_values.php — FieldValues over a REDCap-shaped data table.
 *
 * A @UVUNIQUE lookup now asks the data table which records hold a value before
 * it builds any record through getData (UNQ-7, PRF-2). That read is the one
 * statement in the lookup a mock cannot vouch for: the table name comes from
 * redcap_projects, the rows come back through the framework's query(), and its
 * cost is decided by which index the server picks. So this case gives it the
 * indexes REDCap creates on redcap_data, not the fixture's own, and asserts the
 * plan of the statement the class actually sent.
 *
 * TWO PROJECTS. 900 is under test; 901 holds the same values, so a statement
 * that lost its project predicate answers with the neighbour's records.
 */

use INSPIRE\UniversalValidator\Scan\FieldValues;

$PID = 900;
$NEIGHBOUR = uv_neighbour($PID);

{
    uv_redcap_schema($A);
    uv_redcap_project($A, $PID);
    uv_redcap_project($A, $NEIGHBOUR);
    // The keys REDCap itself puts on redcap_data, in place of the fixture's
    // (project_id, field_name, record), which a real installation does not have.
    $A->query('ALTER TABLE redcap_data DROP INDEX project_id,
        ADD KEY event_id_instance (event_id, instance),
        ADD KEY proj_record_field (project_id, record, field_name),
        ADD KEY project_field (project_id, field_name)');

    // A table shaped like a real one, where one field of one project is a
    // small part of the rows: 900 and 901 each hold 600 records of twelve
    // fields over two events. With three fields a project, MariaDB rightly
    // preferred a scan of the whole table to fetching a sixth of it by index.
    foreach (array($PID, $NEIGHBOUR) as $p) {
        for ($i = 1; $i <= 600; $i++) {
            $rec = 'R' . $i;
            $vals = array("($p, 1, '$rec', 'record_id', '$rec')", "($p, 1, '$rec', 'tok', 'T-$i')");
            for ($f = 1; $f <= 10; $f++) $vals[] = "($p, " . (1 + $f % 2) . ", '$rec', 'f$f', 'V-$i-$f')";
            $A->query('INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`) VALUES '
                . implode(', ', $vals));
        }
    }
    // The cases the comparison has to see: spaces and case around a value, a
    // value in a second field, and the same value in the other event.
    $A->query("INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`) VALUES
        ($PID, 1, 'S1', 'tok', '  dup-1 '),
        ($PID, 1, 'S2', 'scan', 'DUP-1'),
        ($PID, 2, 'S3', 'tok', 'DUP-1'),
        ($NEIGHBOUR, 1, 'N1', 'tok', 'DUP-1')");
    $A->query('ANALYZE TABLE redcap_data');

    $mod = new class($A) {
        public $log = array();
        public $inner;
        public function __construct($c) { $this->inner = new UvFlakyModule($c); }
        public function query($sql, $params = array()) {
            $this->log[] = array($sql, $params);
            return $this->inner->query($sql, $params);
        }
    };
    $same = function ($f, $v) { return strtolower(trim($v)) === 'dup-1'; };

    $fv = new FieldValues($mod);
    $recs = $fv->recordsWhere($PID, array('tok', 'scan'), null, $same);
    sort($recs);
    check('field values: the records of this project holding the value in either field (got '
        . json_encode($recs) . ')', $recs === array('S1', 'S2', 'S3'));
    check('field values: the neighbour\'s record is not among them', !in_array('N1', (array) $recs, true));

    $reads = array();
    foreach ($mod->log as $q) {
        if (strpos($q[0], 'FROM redcap_data WHERE') !== false) $reads[] = $q;
    }
    check('field values: the table named in redcap_projects is read, once per field', count($reads) === 2);
    // An IN list over field_name had both MySQL 5.7 and 8.0 walk every row of
    // the project through (project_id, record, field_name).
    foreach ($reads as $read) {
        $key = uv_explain_key($A, $read[0], $read[1]);
        $cols = uv_index_columns($A, 'redcap_data', $key);
        check('field values: the read of ' . $read[1][1] . ' uses an index that starts (project_id, field_name) (plan '
            . json_encode(uv_explain_rows($A, $read[0], $read[1])) . ')',
            array_slice($cols, 0, 2) === array('project_id', 'field_name'));
    }

    $mod->log = array();
    $recs = $fv->recordsWhere($PID, array('tok'), 2, $same);
    check('field values: with an event, only that event\'s rows (got ' . json_encode($recs) . ')', $recs === array('S3'));
    $tableReads = 0;
    foreach ($mod->log as $q) {
        if (strpos($q[0], 'redcap_projects') !== false) $tableReads++;
    }
    check('field values: the table name is resolved once per project', $tableReads === 0);

    $recs = $fv->recordsWhere($PID, array('tok'), null, function ($f, $v) { return false; });
    check('field values: nothing accepted is an empty answer, not a failure', $recs === array());

    // A read the framework answers with false is no answer: the caller falls
    // back to the export instead of calling the value free.
    $mod->inner->failOn = 'FROM redcap_data WHERE';
    $recs = $fv->recordsWhere($PID, array('tok'), null, $same);
    check('field values: a failed read is null, never an empty list', $recs === null);
    $mod->inner->failOn = null;

    // No data table the module can name: null, before any row is read.
    $A->query("UPDATE redcap_projects SET data_table = 'redcap_data; DROP TABLE x' WHERE project_id = " . $PID);
    $A->query('RENAME TABLE redcap_data TO redcap_data_moved');
    $fresh = new FieldValues($mod);
    check('field values: with no table it can name, the answer is null',
        $fresh->recordsWhere($PID, array('tok'), null, $same) === null);
    $A->query('RENAME TABLE redcap_data_moved TO redcap_data');
}
