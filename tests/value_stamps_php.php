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
    public function select($sql, array $params = [])
    {
        $this->queries++;
        if ($this->fail) throw new \RuntimeException('down');
        if (strpos($sql, 'FROM redcap_projects') !== false) return [[$this->table]];
        if (strpos($sql, 'FROM ' . $this->table . ' ') === false) throw new \RuntimeException('wrong table');
        preg_match('/LIMIT (\d+)$/', $sql, $m);
        $limit = (int) $m[1];
        list($pid, $pk) = $params;
        $before = isset($params[2]) ? (int) $params[2] : PHP_INT_MAX;
        $out = [];
        foreach ($this->rows as $r) {
            // the collation matches letter case loosely, as MySQL's may
            if ($r[1] !== $pid || strtolower($r[2]) !== strtolower($pk) || $r[0] >= $before) continue;
            if ($r[6] !== 'redcap_data' || !in_array($r[7], ['INSERT', 'UPDATE'], true)) continue;
            $out[] = [(string) $r[0], $r[4], $r[3], $r[5], $r[2]];
        }
        usort($out, function ($a, $b) { return (int) $b[0] - (int) $a[0]; });
        return array_slice($out, 0, $limit);
    }
    public function exec($sql, array $params = []) {}
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
check('parse: an instance line only counts first', ValueStamps::parseDataValues("visit = '2026-10-01',\n[instance = 3],")
    === [1 => ['visit' => '2026-10-01']]);
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

// ---- time zones ------------------------------------------------------------------
$db = new FakeLog();
$db->rows = [$row(1, '1', 351, '20261001233000', "visit = '2026-10-01'")];
$s = ValueStamps::forProject($db, 149, new \DateTimeZone('America/New_York'), new \DateTimeZone('Africa/Douala'));
check('the log zone is turned into the clock zone', $s->savedAt('1', 351, 1, 'visit', '2026-10-01')['at'] === '2026-10-02 04:30:00');
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
check('...in three queries after the table lookup', $db->queries === 4);
$db = new FakeLog();
$db->rows[] = $row(1, '1', 351, '20260101000000', "visit = '2026-01-01'");
for ($i = 2; $i <= ValueStamps::MAX_ROWS_PER_RECORD + 1; $i++) $db->rows[] = $row($i, '1', 351, '20260102000000', "wt = '" . $i . "'");
$s = ValueStamps::forProject($db, 149, $utc, $utc);
check('past the cap the value is "unlogged"', $s->savedAt('1', 351, 1, 'visit', '2026-01-01')['state'] === 'unlogged');

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
