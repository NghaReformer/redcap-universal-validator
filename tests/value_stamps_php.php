<?php
/**
 * value_stamps_php.php — when a saved value was saved, read from a mock of
 * REDCap's log (php/ValueStamps.php): the data_values format with repeating
 * instances, checkboxes and ambiguous lines, the newest row winning, paging,
 * the byte compare of the record id, the time zones, and the states a
 * caller decides on.
 *
 * Run:  php tests/value_stamps_php.php
 */

require_once __DIR__ . '/../php/Scan/ScanDb.php';
require_once __DIR__ . '/../php/Scan/SourceFence.php';
require_once __DIR__ . '/../php/ValueStamps.php';

use INSPIRE\UniversalValidator\ValueStamps;
use INSPIRE\UniversalValidator\Scan\ScanDb;

$n = 0;
$fails = 0;
function check($label, $ok)
{
    global $n, $fails;
    $n++;
    if (!$ok) { $fails++; echo "FAIL: $label\n"; }
}

/** The log as rows [id, pid, pk, event_id, ts, data_values, object_type, event]; select() answers the two queries ValueStamps makes. */
final class FakeLog implements ScanDb
{
    public $rows = [];
    public $table = 'redcap_log_event4';
    public $queries = 0;
    public $fail = false;
    /** information_schema.statistics rows of the log table: [index_name, seq_in_index, column_name]; null = cannot be read */
    public $index = [['PRIMARY', '1', 'log_event_id'], ['pk', '1', 'pk'], ['project_id', '1', 'project_id']];
    /** every statement on the log table, as sent */
    public $sent = [];
    public function select($sql, array $params = [])
    {
        $this->queries++;
        if ($this->fail) throw new \RuntimeException('down');
        if (strpos($sql, 'FROM redcap_projects') !== false) return [[$this->table]];
        if (strpos($sql, 'FROM information_schema.statistics') !== false) {
            if ($this->index === null) throw new \RuntimeException('no catalogue');
            return $params === [$this->table] ? $this->index : [];
        }
        if (strpos($sql, 'FROM ' . $this->table . ' ') === false) throw new \RuntimeException('wrong table');
        $this->sent[] = $sql;
        preg_match('/LIMIT (\d+)$/', $sql, $m);
        $limit = (int) $m[1];
        $marks = substr_count($sql, '?');
        $pid = array_shift($params);
        $before = strpos($sql, ' AND log_event_id < ?') !== false ? (int) array_pop($params) : PHP_INT_MAX;
        $pks = array_map('strtolower', array_map('strval', $params));
        if ($marks !== count($pks) + 1 + ($before === PHP_INT_MAX ? 0 : 1)) throw new \RuntimeException('placeholders');
        $out = [];
        foreach ($this->rows as $r) {
            // the collation matches letter case loosely, as MySQL's may
            if ($r[1] !== $pid || !in_array(strtolower($r[2]), $pks, true) || $r[0] >= $before) continue;
            if ($r[6] !== 'redcap_data' || !in_array($r[7], ['INSERT', 'UPDATE'], true)) continue;
            $out[] = [(string) $r[0], $r[4], $r[3], $r[5], $r[2]];
        }
        usort($out, function ($a, $b) { return (int) $b[0] - (int) $a[0]; });
        return array_slice($out, 0, $limit);
    }
    public function exec($sql, array $params = []) {}
    public function execNoCount($sql, array $params = []) {}
    public function affected() { return 0; }
    public function begin() {}
    public function commit() {}
    public function rollback() {}
}

$utc = new \DateTimeZone('UTC');
$row = function ($id, $pk, $event, $ts, $dv, $obj = 'redcap_data', $ev = 'UPDATE', $pid = 149) {
    return [$id, $pid, $pk, $event, $ts, $dv, $obj, $ev];
};

// ---- the data_values format ------------------------------------------------
check('parse: plain lines', ValueStamps::parseDataValues("visit = '2026-10-01',\nweight = '12.5'")
    === [1 => ['visit' => '2026-10-01', 'weight' => '12.5']]);
check('parse: a repeating instance', ValueStamps::parseDataValues("[instance = 3],\nvisit = '2026-10-01'")
    === [3 => ['visit' => '2026-10-01']]);
check('parse: CRLF line ends', ValueStamps::parseDataValues("visit = '2026-10-01',\r\nwt = '1'") === [1 => ['visit' => '2026-10-01', 'wt' => '1']]);
check('parse: a checkbox line is skipped', ValueStamps::parseDataValues("sym(2) = checked,\nvisit = '2026-10-01'") === [1 => ['visit' => '2026-10-01']]);
check('parse: a blank value', ValueStamps::parseDataValues("visit = ''") === [1 => ['visit' => '']]);
check('parse: a field logged twice in one row is ambiguous', ValueStamps::parseDataValues("notes = 'x',\nvisit = '2026-10-01',\nvisit = '2026-01-01'")
    === [1 => ['notes' => 'x', 'visit' => null]]);
check('parse: a value that spans lines is one entry, and its lines are not read as fields',
    ValueStamps::parseDataValues("visit = '2026-10-01',\nnotes = 'first line\nw_past = '2026-10-09'\nlast line',\nwt = '1'")
    === [1 => ['visit' => '2026-10-01', 'notes' => null, 'wt' => '1']]);
check('parse: a value that spans lines to the end of the row', ValueStamps::parseDataValues("notes = 'a\nb = 'c'")
    === [1 => ['notes' => null]]);
check('parse: an instance line past the first is a stray line, so the row is ambiguous',
    ValueStamps::parseDataValues("visit = '2026-10-01',\n[instance = 3],\nwt = '1'") === [1 => ['visit' => null, 'wt' => null]]);
check('parse: a notes line ending in a quote hides where a value ends: the row is ambiguous',
    ValueStamps::parseDataValues("notes = 'He said 'hi'\nw_past = '2026-10-09'\nend',\nwt = '1'")
    === [1 => ['notes' => null, 'w_past' => null, 'wt' => null]]);
// WIN-1: the line REDCap logs for a save made in a data access group is not a
// field and not a stray line. It voided every stamp of the row.
check('parse: the data access group line is skipped (WIN-1)',
    ValueStamps::parseDataValues("record_id = '5',\nappt_date = '2026-11-20',\n__GROUPID__ = '12'")
    === [1 => ['record_id' => '5', 'appt_date' => '2026-11-20']]);
check('parse: ... first in the row, blank, or under an instance line',
    ValueStamps::parseDataValues("__GROUPID__ = '',\nappt_date = '2026-11-20'") === [1 => ['appt_date' => '2026-11-20']]
    && ValueStamps::parseDataValues("[instance = 2],\n__GROUPID__ = '12',\nappt_date = '2026-11-20'") === [2 => ['appt_date' => '2026-11-20']]);
check('parse: ... and so is any line named in capitals between double underscores',
    ValueStamps::parseDataValues("appt_date = '2026-11-20',\n__LOCKED_2__ = 'x'") === [1 => ['appt_date' => '2026-11-20']]);
check('parse: a lower-case or half-shaped name is still a stray line',
    ValueStamps::parseDataValues("appt_date = '2026-11-20',\n__groupid__ = '12'") === [1 => ['appt_date' => null]]
    && ValueStamps::parseDataValues("appt_date = '2026-11-20',\n__GROUPID = '12'") === [1 => ['appt_date' => null]]
    && ValueStamps::parseDataValues("appt_date = '2026-11-20',\n__GROUPID__ = 12") === [1 => ['appt_date' => null]]);
check('parse: a system line inside a value that spans lines belongs to the value',
    ValueStamps::parseDataValues("notes = 'a\n__GROUPID__ = '12'\nb',\nappt_date = '2026-11-20'")
    === [1 => ['notes' => null, 'appt_date' => '2026-11-20']]);
// WIN-6: a notes line that ends with ', closes the value early and leaves a
// stray line. The fields REDCap wrote before the notes field keep their stamps.
check('parse: the fields before a split notes value keep their values (WIN-6)',
    ValueStamps::parseDataValues("appt_date = '2026-11-20',\nnotes = 'he said 'no',\nand left'")
    === [1 => ['appt_date' => '2026-11-20', 'notes' => null]]);
check('parse: ... the split value and every field after it do not',
    ValueStamps::parseDataValues("appt_date = '2026-11-20',\nnotes = 'he said 'no',\nand left',\nw_past = '2026-10-09',\nwt = '1'")
    === [1 => ['appt_date' => '2026-11-20', 'notes' => null, 'w_past' => null, 'wt' => null]]);
check('parse: ... also when the value began as one that spans lines',
    ValueStamps::parseDataValues("appt_date = '2026-11-20',\nnotes = 'first\nhe said 'no',\nand left',\nwt = '1'")
    === [1 => ['appt_date' => '2026-11-20', 'notes' => null, 'wt' => null]]);
check('parse: ... and a checkbox or system line between does not move the cut',
    ValueStamps::parseDataValues("appt_date = '2026-11-20',\nnotes = 'x',\nsym(2) = checked,\n__GROUPID__ = '1',\nstray")
    === [1 => ['appt_date' => '2026-11-20', 'notes' => null]]);
check('parse: an earlier entry with no comma is where the split began',
    ValueStamps::parseDataValues("appt_date = '2026-11-20',\nnotes = 'he said 'hi'\nw_past = '2026-10-09',\nend',\nwt = '1'")
    === [1 => ['appt_date' => '2026-11-20', 'notes' => null, 'w_past' => null, 'wt' => null]]);
check('parse: a stray line before any entry leaves no value',
    ValueStamps::parseDataValues("stray\nappt_date = '2026-11-20'") === [1 => ['appt_date' => null]]);
check('parse: a blank line is not a stray line', ValueStamps::parseDataValues("visit = '2026-10-01',\n\nwt = '1'")
    === [1 => ['visit' => '2026-10-01', 'wt' => '1']]);
check('parse: nothing', ValueStamps::parseDataValues('') === [] && ValueStamps::parseDataValues(null) === []);

// ---- stamps ------------------------------------------------------------------
$db = new FakeLog();
$db->rows = [
    $row(10, '1', 351, '20261001090000', "visit = '2026-10-01',\nwt = '12'", 'redcap_data', 'INSERT'),
    $row(11, '1', 351, '20261003101500', "wt = '13'"),
    $row(12, '1', 351, '20261005080000', "[instance = 2],\nvisit = '2026-10-04'"),
    $row(13, '1', 352, '20261006120000', "visit = '2026-10-06'"),
    $row(14, '1', 351, '20261007000000', "visit = '2026-10-01'", 'redcap_data', 'DATA_EXPORT'),
    $row(15, '1', 351, '20261008000000', "visit = '2026-10-02'", 'redcap_user'),
    $row(16, 'R-A', 351, '20261009000000', "visit = '2026-10-09'"),
    $row(17, '1', 351, '20261009000000', "visit = '2026-10-09'", 'redcap_data', 'UPDATE', 150),
];
$s = ValueStamps::forProject($db, 149, $utc, $utc);
check('a reader for the project', $s instanceof ValueStamps);
check('the value saved at creation', $s->savedAt('1', 351, 1, 'visit', '2026-10-01') === ['state' => 'logged', 'at' => '2026-10-01 09:00:00']);
check('the newest row wins', $s->savedAt('1', 351, 1, 'wt', '13') === ['state' => 'logged', 'at' => '2026-10-03 10:15:00']);
check('an older value is "changed"', $s->savedAt('1', 351, 1, 'wt', '12') === ['state' => 'changed', 'at' => null]);
check('a repeating instance', $s->savedAt('1', 351, 2, 'visit', '2026-10-04') === ['state' => 'logged', 'at' => '2026-10-05 08:00:00']);
check('another event', $s->savedAt('1', 352, 1, 'visit', '2026-10-06') === ['state' => 'logged', 'at' => '2026-10-06 12:00:00']);
check('an export or a user change is not a save', $s->savedAt('1', 351, 1, 'visit', '2026-10-01')['at'] === '2026-10-01 09:00:00');
check('a field the log never shows for the record is "unlogged"', $s->savedAt('1', 351, 1, 'height', '90') === ['state' => 'unlogged', 'at' => null]);
check('a record with no rows is "none"', $s->savedAt('9', 351, 1, 'visit', '2026-10-01') === ['state' => 'none', 'at' => null]);
check('another project\'s row is never read', $s->savedAt('1', 351, 1, 'visit', '2026-10-09')['state'] === 'changed');
check('the record id compares as bytes', $s->savedAt('r-a', 351, 1, 'visit', '2026-10-09')['state'] === 'none'
    && $s->savedAt('R-A', 351, 1, 'visit', '2026-10-09')['state'] === 'logged');
check('space around the value does not matter', $s->savedAt('1', 351, 1, 'wt', ' 13 ')['state'] === 'logged');
$q = $db->queries;
$s->savedAt('1', 351, 1, 'wt', '13');
check('a record is read once', $db->queries === $q);

// ---- ambiguous rows ------------------------------------------------------------
$db = new FakeLog();
$db->rows = [$row(1, '1', 351, '20261001090000', "visit = '2026-10-01'"),
             $row(2, '1', 351, '20261002090000', "notes = 'a',\nvisit = '2026-10-01',\nvisit = '2026-10-01'")];
$s = ValueStamps::forProject($db, 149, $utc, $utc);
check('an ambiguous newest row is never a stamp, even over an older one that matches',
    $s->savedAt('1', 351, 1, 'visit', '2026-10-01') === ['state' => 'changed', 'at' => null]);

// ---- a save made in a data access group (WIN-1), a split notes value (WIN-6) ------
$db = new FakeLog();
$db->rows = [$row(1, '5', 351, '20261101090000', "record_id = '5',\nappt_date = '2026-11-20',\n__GROUPID__ = '12'"),
             $row(2, '6', 351, '20261102090000', "appt_date = '2026-11-21',\nnotes = 'he said 'no',\nand left',\nwt = '4'")];
$s = ValueStamps::forProject($db, 149, $utc, $utc);
check('a save logged with its data access group is a stamp (WIN-1)',
    $s->savedAt('5', 351, 1, 'appt_date', '2026-11-20') === ['state' => 'logged', 'at' => '2026-11-01 09:00:00']);
check('a field logged before a split notes value is a stamp (WIN-6)',
    $s->savedAt('6', 351, 1, 'appt_date', '2026-11-21') === ['state' => 'logged', 'at' => '2026-11-02 09:00:00']);
check('... a field logged after it is not', $s->savedAt('6', 351, 1, 'wt', '4') === ['state' => 'changed', 'at' => null]);

// ---- time zones ------------------------------------------------------------------
$db = new FakeLog();
$db->rows = [$row(1, '1', 351, '20261001233000', "visit = '2026-10-01'")];
$s = ValueStamps::forProject($db, 149, new \DateTimeZone('America/New_York'), new \DateTimeZone('Africa/Douala'));
check('the log zone is turned into the clock zone', $s->savedAt('1', 351, 1, 'visit', '2026-10-01')['at'] === '2026-10-02 04:30:00');
// WIN-4: the log's ts is a time on the server's clock. In the hour that clock
// shows twice it names two moments; "at" is the earlier, and the fold says how
// far the save may lie from it as the clock zone shows time.
$ny = new \DateTimeZone('America/New_York');
$db->rows = [$row(1, '1', 351, '20261101013000', "visit = '2026-10-01'")];
$s = ValueStamps::forProject($db, 149, $ny, new \DateTimeZone('Africa/Douala'));
check('a ts in the server\'s repeated hour: the earlier moment, and an hour later it may be (WIN-4)',
    $s->savedAt('1', 351, 1, 'visit', '2026-10-01') === ['state' => 'logged', 'at' => '2026-11-01 06:30:00', 'foldFuture' => 3600]);
$s = ValueStamps::forProject($db, 149, $ny, $ny);
check('... server and clock zone the same: an hour either way on the clock',
    $s->savedAt('1', 351, 1, 'visit', '2026-10-01') === ['state' => 'logged', 'at' => '2026-11-01 01:30:00', 'foldFuture' => 3600, 'foldPast' => 3600]);
$db->rows = [$row(1, '1', 351, '20261101063000', "visit = '2026-10-01'")];
$s = ValueStamps::forProject($db, 149, $utc, $ny);
check('a save in the clock zone\'s repeated hour, on a server with none',
    $s->savedAt('1', 351, 1, 'visit', '2026-10-01') === ['state' => 'logged', 'at' => '2026-11-01 01:30:00', 'foldFuture' => 3600]);
$db->rows = [$row(1, '1', 351, '20261101053000', "visit = '2026-10-01'")];
$s = ValueStamps::forProject($db, 149, $utc, $ny);
check('... in its first showing', $s->savedAt('1', 351, 1, 'visit', '2026-10-01') === ['state' => 'logged', 'at' => '2026-11-01 01:30:00', 'foldPast' => 3600]);
$db->rows = [$row(1, '1', 351, '20261101023000', "visit = '2026-10-01'")];
$s = ValueStamps::forProject($db, 149, $ny, $ny);
check('the hour after carries no fold', $s->savedAt('1', 351, 1, 'visit', '2026-10-01') === ['state' => 'logged', 'at' => '2026-11-01 02:30:00']);
$db->rows = [$row(1, '1', 351, '2026100123', "visit = '2026-10-01'")];
$s = ValueStamps::forProject($db, 149, $utc, $utc);
check('a ts that does not read is "unknown"', $s->savedAt('1', 351, 1, 'visit', '2026-10-01')['state'] === 'unknown');
$db->rows = [$row(1, '1', 351, '20261341000000', "visit = '2026-10-01'")];
$s = ValueStamps::forProject($db, 149, $utc, $utc);
check('an impossible ts is "unknown"', $s->savedAt('1', 351, 1, 'visit', '2026-10-01')['state'] === 'unknown');

// ---- paging and its cap --------------------------------------------------------------
$db = new FakeLog();
$db->rows[] = $row(1, '1', 351, '20260101000000', "visit = '2026-01-01'");
for ($i = 2; $i <= 1200; $i++) $db->rows[] = $row($i, '1', 351, '20260102000000', "wt = '" . $i . "'");
$s = ValueStamps::forProject($db, 149, $utc, $utc);
check('a value logged 1199 rows back is found across pages', $s->savedAt('1', 351, 1, 'visit', '2026-01-01')['at'] === '2026-01-01 00:00:00');
check('...in three queries after the table and index lookups', $db->queries === 5 && count($db->sent) === 3);
$db = new FakeLog();
$db->rows[] = $row(1, '1', 351, '20260101000000', "visit = '2026-01-01'");
for ($i = 2; $i <= ValueStamps::MAX_ROWS_PER_RECORD + 1; $i++) $db->rows[] = $row($i, '1', 351, '20260102000000', "wt = '" . $i . "'");
$s = ValueStamps::forProject($db, 149, $utc, $utc);
check('past the cap the value is "capped", not "unlogged"', $s->savedAt('1', 351, 1, 'visit', '2026-01-01')['state'] === 'capped');
check('...a field logged within the cap is still found', $s->savedAt('1', 351, 1, 'wt', (string) ValueStamps::MAX_ROWS_PER_RECORD + 1)['state'] === 'logged');

// ---- memory: a scan holds a few records at a time ---------------------------------------
$db = new FakeLog();
for ($i = 1; $i <= 40; $i++) $db->rows[] = $row($i, 'R' . $i, 351, '20261001090000', "visit = '2026-10-01'");
$s = ValueStamps::forProject($db, 149, $utc, $utc);
for ($i = 1; $i <= 40; $i++) $s->savedAt('R' . $i, 351, 1, 'visit', '2026-10-01');
// read off the private list: the class has no accessor only a test would call (QUA-3)
$kept = new ReflectionProperty(ValueStamps::class, 'kept');
$kept->setAccessible(true);
check('a scan holds at most RECORDS_KEPT records', count($kept->getValue($s)) === ValueStamps::RECORDS_KEPT);
$q = $db->queries;
check('a dropped record is read again, and answers the same', $s->savedAt('R1', 351, 1, 'visit', '2026-10-01')['state'] === 'logged'
    && $db->queries === $q + 1);
$q = $db->queries;
$s->savedAt('R40', 351, 1, 'visit', '2026-10-01');
check('a kept record is not read again', $db->queries === $q);

// ---- the query: its shape, its index, and a scan's records read together (WIN-3) --------
$SELECT = 'SELECT log_event_id, ts, event_id, data_values, CAST(pk AS BINARY) AS p FROM redcap_log_event4';
$WHERE = " AND object_type = 'redcap_data' AND event IN ('INSERT', 'UPDATE')";
$db = new FakeLog();
$db->rows[] = $row(1, '1', 351, '20260101000000', "visit = '2026-01-01'");
for ($i = 2; $i <= 600; $i++) $db->rows[] = $row($i, '1', 351, '20260102000000', "wt = '" . $i . "'");
$s = ValueStamps::forProject($db, 149, $utc, $utc);
$s->savedAt('1', 351, 1, 'visit', '2026-01-01');
check('the read names the index that leads with pk, one record, newest first, one page (got ' . json_encode($db->sent[0]) . ')',
    $db->sent[0] === $SELECT . ' FORCE INDEX (`pk`) WHERE project_id = ? AND pk = ?' . $WHERE . ' ORDER BY log_event_id DESC LIMIT 500');
check('the next page goes on below the last row read',
    $db->sent[1] === $SELECT . ' FORCE INDEX (`pk`) WHERE project_id = ? AND pk = ?' . $WHERE . ' AND log_event_id < ? ORDER BY log_event_id DESC LIMIT 500');
$q = $db->queries;
$s->savedAt('2', 351, 1, 'visit', '2026-01-01');
check('the index is looked up once per reader', $db->queries === $q + 1);
foreach ([
    'an index on pk and project_id is preferred' => [[['pk', '1', 'pk'], ['pk_project', '1', 'pk'], ['pk_project', '2', 'project_id']], ' FORCE INDEX (`pk_project`)'],
    'an index that holds pk only further on is not one' => [[['project_pk', '1', 'project_id'], ['project_pk', '2', 'pk']], ''],
    'a name that is not a plain identifier is never sent' => [[['pk`) x', '1', 'pk']], ''],
    'no index, no hint' => [[], ''],
    'a catalogue that cannot be read, no hint' => [null, ''],
] as $label => $case) {
    $db = new FakeLog();
    $db->index = $case[0];
    $db->rows[] = $row(1, '1', 351, '20260101000000', "visit = '2026-01-01'");
    $s = ValueStamps::forProject($db, 149, $utc, $utc);
    $r = $s->savedAt('1', 351, 1, 'visit', '2026-01-01');
    check($label, $r['state'] === 'logged' && $db->sent[0] === $SELECT . $case[1] . ' WHERE project_id = ? AND pk = ?' . $WHERE . ' ORDER BY log_event_id DESC LIMIT 500');
}
// A scan names the records it judges next: one query for RECORDS_KEPT of them.
$db = new FakeLog();
for ($i = 1; $i <= 40; $i++) $db->rows[] = $row($i, 'R' . $i, 351, '20261001090000', "visit = '2026-10-" . sprintf('%02d', 1 + $i % 28) . "'");
$db->rows[] = $row(41, 'r3', 351, '20261002090000', "visit = '2026-10-30'");
$s = ValueStamps::forProject($db, 149, $utc, $utc);
$ids = [];
for ($i = 1; $i <= 40; $i++) $ids[] = 'R' . $i;
$ids[] = 'R-NONE';
$s->expect($ids);
$ok = true;
foreach ($ids as $i => $id) {
    $r = $s->savedAt($id, 351, 1, 'visit', '2026-10-' . sprintf('%02d', 1 + ($i + 1) % 28));
    $ok = $ok && ($id === 'R-NONE' ? $r === ['state' => 'none', 'at' => null] : $r === ['state' => 'logged', 'at' => '2026-10-01 09:00:00']);
}
check('41 expected records answer as they do one by one', $ok);
check('... in 3 queries, not 41 (got ' . count($db->sent) . ')', count($db->sent) === 3);
check('... each for ' . ValueStamps::RECORDS_KEPT . ' records at most (got ' . json_encode($db->sent[0]) . ')',
    $db->sent[0] === $SELECT . ' FORCE INDEX (`pk`) WHERE project_id = ? AND pk IN (' . implode(', ', array_fill(0, ValueStamps::RECORDS_KEPT, '?')) . ')'
        . $WHERE . ' ORDER BY log_event_id DESC LIMIT 500'
    && substr_count($db->sent[2], '?') === 1 + 41 - 2 * ValueStamps::RECORDS_KEPT);
check('... holding no more than RECORDS_KEPT records', count($kept->getValue($s)) <= ValueStamps::RECORDS_KEPT);
$s->expect(['R3', 'r3']);
check('a record that differs only in case, read in the same batch, is its own record',
    $s->savedAt('r3', 351, 1, 'visit', '2026-10-30') === ['state' => 'logged', 'at' => '2026-10-02 09:00:00']
    && $s->savedAt('R3', 351, 1, 'visit', '2026-10-04')['at'] === '2026-10-01 09:00:00');
// A batch whose page is full: each record goes on alone from the last row read.
$db = new FakeLog();
$db->rows[] = $row(1, 'A', 351, '20260101000000', "visit = '2026-01-01'");
$db->rows[] = $row(2, 'B', 351, '20260102000000', "visit = '2026-01-02'");
for ($i = 3; $i <= 700; $i++) $db->rows[] = $row($i, 'B', 351, '20260103000000', "wt = '" . $i . "'");
$s = ValueStamps::forProject($db, 149, $utc, $utc);
$s->expect(['A', 'B', 'C']);
check('a record crowded out of a full batch page is read on from there',
    $s->savedAt('A', 351, 1, 'visit', '2026-01-01') === ['state' => 'logged', 'at' => '2026-01-01 00:00:00']
    && count($db->sent) === 2 && strpos($db->sent[0], 'pk IN (?, ?, ?)') !== false
    && strpos($db->sent[1], 'pk = ? ') !== false && strpos($db->sent[1], 'log_event_id < ?') !== false);
check('... and so is the record that filled it', $s->savedAt('B', 351, 1, 'visit', '2026-01-02')['at'] === '2026-01-02 00:00:00');
check('... and one with no rows is "none"', $s->savedAt('C', 351, 1, 'visit', 'x') === ['state' => 'none', 'at' => null]);
$q = count($db->sent);
$s->expect(['A', 'B', 'Z']);
$s->savedAt('Z', 351, 1, 'visit', 'x');
check('a record already held is not read again with the next batch', count($db->sent) === $q + 1 && strpos($db->sent[$q], 'pk = ? ') !== false);

// ---- the log cannot be read ----------------------------------------------------------
$db = new FakeLog();
$db->table = 'redcap_log_event; DROP TABLE x';
check('a log table outside the allowlist: no reader', ValueStamps::forProject($db, 149, $utc, $utc) === null);
$db = new FakeLog();
$s = ValueStamps::forProject($db, 149, $utc, $utc);
$db->fail = true;
check('a failed read is "unknown"', $s->savedAt('1', 351, 1, 'visit', '2026-10-01') === ['state' => 'unknown', 'at' => null]);

echo "value_stamps_php: $n checks, $fails failure(s)\n";
exit($fails ? 1 : 0);
