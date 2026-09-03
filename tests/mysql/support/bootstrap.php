<?php
/**
 * tests/mysql/support/bootstrap.php — everything the case files share.
 *
 * WHY THIS FILE EXISTS. tests/mysql/run.php used to be one 1746-line script, and
 * every wave of work on the durable scan wanted to edit it. One file that
 * thirteen work items all need is not a test suite, it is a queue: the second
 * person to arrive either waits or resolves a conflict in someone else's
 * assertions. So the runner now walks tests/mysql/cases/*.php, and everything
 * they have in common lives here: the check counter, the two connections, the
 * ScanDb and framework stand-ins, and the schema the runner rebuilds between
 * cases.
 *
 * Nothing in here asserts anything. A helper that can fail a check is a helper
 * that decides what a case means, and the cases have to keep deciding that for
 * themselves.
 */

require_once __DIR__ . '/../../../php/Scan/Schema.php';
require_once __DIR__ . '/../../../php/Scan/ScanOutcome.php';
require_once __DIR__ . '/../../../php/Scan/ScanPhase.php';
require_once __DIR__ . '/../../../php/Scan/ScanStore.php';
require_once __DIR__ . '/../../../php/Scan/ScanDb.php';
require_once __DIR__ . '/../../../php/Scan/DbError.php';
require_once __DIR__ . '/../../../php/Scan/SqlScanStore.php';
require_once __DIR__ . '/../../../php/Scan/WorkerSlots.php';
require_once __DIR__ . '/../../../php/Scan/ScanRetention.php';
require_once __DIR__ . '/../../../php/Scan/SourceFence.php';
require_once __DIR__ . '/../../../php/Scan/RecordManifestSource.php';
require_once __DIR__ . '/../../../php/Scan/Hmac.php';
require_once __DIR__ . '/../../../php/Scan/ScanPolicy.php';
require_once __DIR__ . '/../../../php/Scan/ScanPlanner.php';
require_once __DIR__ . '/../../../php/Scan/WorkBudget.php';
require_once __DIR__ . '/../../../php/Scan/ScanWorker.php';
require_once __DIR__ . '/../../../php/Scan/UniqueFinalizer.php';
require_once __DIR__ . '/../../../php/Scan/RollupBuilder.php';
require_once __DIR__ . '/../../../php/Scan/ScanPromotion.php';

use INSPIRE\UniversalValidator\Scan\Schema;

/** A version source the finalizer tests drive directly. */
class MovingVersions implements \INSPIRE\UniversalValidator\Scan\RecordVersions
{
    public $v = array();
    public function versions(array $ids)
    {
        $out = array();
        foreach ($ids as $id) $out[(string) $id] = isset($this->v[$id]) ? $this->v[$id] : null;
        return $out;
    }
}

$n = 0; $fail = 0;
function check($label, $cond) {
    global $n, $fail; $n++;
    if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
}

/**
 * Bind a list of values without hand-counting a type string.
 *
 * mysqli wants a type character per variable, and getting that wrong is an
 * ArgumentCountError that kills the process - so it does not fail one check, it
 * silently un-runs every check after it. Hand-counting produced exactly that
 * twice here (nine characters for ten variables, then eleven), and each attempt
 * cost a CI round trip to discover.
 *
 * Everything binds as 's'. The server casts on the way in, and these are test
 * fixtures rather than a performance path; what matters is that the arity is
 * derived rather than asserted.
 */
function bindAll($st, array $vals) {
    $refs = [];
    foreach ($vals as $k => $v) $refs[$k] = &$vals[$k];
    array_unshift($refs, str_repeat('s', count($vals)));
    call_user_func_array([$st, 'bind_param'], $refs);
}

/** A module stand-in over one connection, matching the framework's query(). */
class Conn {
    private $c;
    public function __construct($c) { $this->c = $c; }
    public function query($sql, $params = []) {
        if (!$params) {
            $r = $this->c->query($sql);
            return $r === true ? [] : $this->rows($r);
        }
        $st = $this->c->prepare($sql);
        bindAll($st, array_values($params));
        $st->execute();
        $r = $st->get_result();
        $out = $r === false ? [] : $this->rows($r);
        $st->close();
        return $out;
    }
    private function rows($r) {
        $out = [];
        while ($row = $r->fetch_row()) $out[] = $row;
        $r->free();
        return $out;
    }
    public function raw() { return $this->c; }
    public function affected() { return $this->c->affected_rows; }
}

/** ScanDb over the raw mysqli connection. */
class MysqliDb implements INSPIRE\UniversalValidator\Scan\ScanDb {
    private $c; private $aff = 0;
    public function __construct($c) { $this->c = $c; }
    public function select($sql, array $params = []) {
        if (!$params) {
            $r = $this->c->query($sql);
            return $r === true ? [] : $this->rows($r);
        }
        $st = $this->c->prepare($sql);
        bindAll($st, array_values($params));
        $st->execute();
        $r = $st->get_result();
        $out = $r === false ? [] : $this->rows($r);
        $st->close();
        return $out;
    }
    public function exec($sql, array $params = []) {
        if (!$params) { $this->c->query($sql); $this->aff = $this->c->affected_rows; return; }
        $st = $this->c->prepare($sql);
        bindAll($st, array_values($params));
        $st->execute();
        $this->aff = $st->affected_rows;
        $st->close();
    }
    public function affected() { return $this->aff; }
    public function begin()    { $this->c->query('START TRANSACTION'); }
    public function commit()   { $this->c->query('COMMIT'); }
    public function rollback() { $this->c->query('ROLLBACK'); }
    private function rows($r) {
        $out = [];
        while ($row = $r->fetch_row()) $out[] = $row;
        $r->free();
        return $out;
    }
}

/**
 * A ScanDb that remembers every statement, and changes nothing else.
 *
 * WHAT IT IS FOR. Two properties of this module are about the SHAPE of the
 * traffic rather than its result, and a test that only reads rows back cannot
 * see either: how many statements a page of work costs, and which index the
 * server chose. Both were measured defects - discover() issued one INSERT per
 * group, 811 seconds per 100,000 groups; nextUnfinished() degraded from 2.88 ms
 * to 283.82 ms as groups settled, because no key carried `phase`.
 *
 * IT CAPTURES THE STATEMENT THE CODE ACTUALLY ISSUED, which is the point. An
 * EXPLAIN of SQL copied into a test proves that the copy uses an index; an
 * EXPLAIN of the captured statement proves the production one does.
 */
class UvRecordingDb implements INSPIRE\UniversalValidator\Scan\ScanDb {
    /** @var array list of [kind, sql, params] */
    public $log = [];
    private $inner;
    public function __construct(INSPIRE\UniversalValidator\Scan\ScanDb $inner) { $this->inner = $inner; }
    public function select($sql, array $params = []) {
        $this->log[] = ['select', $sql, $params];
        return $this->inner->select($sql, $params);
    }
    public function exec($sql, array $params = []) {
        $this->log[] = ['exec', $sql, $params];
        $this->inner->exec($sql, $params);
    }
    public function affected() { return $this->inner->affected(); }
    public function begin()    { $this->inner->begin(); }
    public function commit()   { $this->inner->commit(); }
    public function rollback() { $this->inner->rollback(); }

    /** Statements of one kind whose text contains every one of $needles. */
    public function matching($kind, array $needles) {
        $out = [];
        foreach ($this->log as $row) {
            if ($row[0] !== $kind) continue;
            $hit = true;
            foreach ($needles as $n) { if (strpos($row[1], $n) === false) { $hit = false; break; } }
            if ($hit) $out[] = $row;
        }
        return $out;
    }
}

/**
 * A captured statement with its parameters inlined, for EXPLAIN.
 *
 * EXPLAIN cannot take a prepared statement's placeholders through this suite's
 * ScanDb, so the bound values go in as literals. Everything bound by the code
 * under test here is an integer or one of its own phase constants - never a
 * value read from a project - and the string is thrown away after the EXPLAIN.
 */
function uv_inline_params($conn, $sql, array $params) {
    $out = '';
    $i = 0;
    for ($p = 0; $p < strlen($sql); $p++) {
        $ch = $sql[$p];
        if ($ch !== '?') { $out .= $ch; continue; }
        $v = isset($params[$i]) ? $params[$i] : null;
        $i++;
        if ($v === null) { $out .= 'NULL'; continue; }
        if (is_int($v) || (is_string($v) && ctype_digit($v))) { $out .= (int) $v; continue; }
        $out .= "'" . $conn->real_escape_string((string) $v) . "'";
    }
    return $out;
}

/**
 * The index the server chose for a captured statement, or ''.
 *
 * EXPLAIN's column ORDER differs between MySQL and MariaDB - MySQL has a
 * `partitions` column MariaDB does not - so the row is read by NAME. A
 * positional read would silently report `possible_keys` on one engine and `key`
 * on the other, and this suite runs on both.
 */
function uv_explain_key($conn, $sql, array $params) {
    $r = $conn->query('EXPLAIN ' . uv_inline_params($conn, $sql, $params));
    if ($r === false) return '';
    $row = $r->fetch_assoc();
    $r->free();
    return ($row && isset($row['key']) && $row['key'] !== null) ? (string) $row['key'] : '';
}

/** Two INDEPENDENT connections. One cannot see the other's uncommitted work. */
function uv_connect($host, $user, $pass, $name, $port) {
    $c = new mysqli($host, $user, $pass, $name, $port);
    $c->set_charset('utf8mb4');
    return $c;
}

/**
 * Which database the suite works in, and whether it is ours to destroy.
 *
 * THE SUITE DROPS TABLES, AND OTHER PEOPLE USE THIS SERVER. Three agents ran
 * this file against the same uv_test during the remediation audit and two
 * measurement passes were destroyed by the teardown of a run that was not
 * theirs - a red result nobody could trust and nobody could reproduce. So the
 * schema is a parameter now:
 *
 *   UV_DB_NAME            the database to connect to (default uv_test). Used
 *                         directly when nothing below is set, which is what CI
 *                         does, so the "nothing of ours is left behind" step
 *                         still inspects the schema the suite actually used.
 *   UV_DB_SCHEMA          work in this database instead, creating it if absent.
 *   UV_DB_SCHEMA_PREFIX   mint a private database per run, named from the prefix
 *                         plus this process, and drop it at the end. This is the
 *                         one to use when someone else may be running the suite.
 *
 * A database this function created is dropped at teardown; one it merely found
 * is not. Deleting a schema the caller already had is the same mistake wearing
 * different clothes.
 */
function uv_resolve_schema($conn, $name) {
    $prefix = getenv('UV_DB_SCHEMA_PREFIX');
    $explicit = getenv('UV_DB_SCHEMA');
    $target = null;
    if ($prefix !== false && $prefix !== '') {
        if (!preg_match('/\A[A-Za-z0-9_]{1,32}\z/', $prefix)) {
            fwrite(STDERR, "UV_DB_SCHEMA_PREFIX may only hold letters, digits and underscore\n");
            exit(2);
        }
        $target = $prefix . getmypid() . '_' . bin2hex(random_bytes(3));
    } elseif ($explicit !== false && $explicit !== '') {
        if (!preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $explicit)) {
            fwrite(STDERR, "UV_DB_SCHEMA may only hold letters, digits and underscore\n");
            exit(2);
        }
        $target = $explicit;
    }
    if ($target === null) return array('name' => $name, 'created' => false);

    // A schema name can never be a bound parameter, which is why the pattern
    // above runs before this line rather than after it.
    $existed = $conn->query('SELECT SCHEMA_NAME FROM information_schema.schemata
        WHERE SCHEMA_NAME = ' . "'" . $conn->real_escape_string($target) . "'");
    $found = $existed && $existed->num_rows > 0;
    if (!$found) {
        $conn->query('CREATE DATABASE `' . $target . '`
            DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci');
    }
    return array('name' => $target, 'created' => !$found);
}

/**
 * Everything a case may create that Schema::tables() does not name.
 *
 * The REDCap-shaped stand-ins the walk and the fence need, plus the one-column
 * table the fault case locks to produce a real refused write. uv_readonly_probe
 * is dropped by that case itself; it is listed here because a case that throws
 * before its own cleanup would otherwise leave a uv_ table behind, and the
 * database job's last step fails the build on exactly that.
 */
function uv_redcap_tables() {
    return array('redcap_log_event', 'redcap_record_list', 'redcap_data', 'redcap_projects',
                 'uv_readonly_probe');
}

/**
 * A schema no earlier case can have touched.
 *
 * WHY PER CASE AND NOT ONCE. The old file truncated a handful of tables between
 * sections and trusted the list to stay complete. It did not: uv_scan_dim and
 * uv_scan_aggregate were never in any of those lists, so a second run of the
 * suite in the same database inherited the first run's rows, and a case that
 * exited early left its state for whichever case ran next to fail on. Rebuilding
 * the whole schema costs about a tenth of a second per case and removes the
 * class.
 */
function uv_reset_schema($conn, $modconn) {
    foreach (array_reverse(Schema::tables()) as $t) $conn->query('DROP TABLE IF EXISTS ' . $t);
    foreach (uv_redcap_tables() as $t) $conn->query('DROP TABLE IF EXISTS ' . $t);
    $r = Schema::migrate($modconn);
    return $r['ok'] === true && $r['to'] === Schema::VERSION;
}

/** Everything this suite may ever have created, gone. */
function uv_drop_everything($conn) {
    foreach (array_reverse(Schema::tables()) as $t) $conn->query('DROP TABLE IF EXISTS ' . $t);
    foreach (uv_redcap_tables() as $t) $conn->query('DROP TABLE IF EXISTS ' . $t);
}

/**
 * Run one case file with its own variable scope.
 *
 * require inside a function gives the included file that function's scope, so a
 * case cannot leave a variable behind for the next one - and, more usefully, a
 * case that writes $n no longer overwrites check()'s global counter. That
 * collision really happened here: a case assigned a result set to $n and the
 * suite died several checks later incrementing an array, nowhere near the cause.
 * extract() is doing real work in this one place, so the context is a fixed,
 * documented list rather than whatever the runner happens to hold.
 */
function uv_run_case($path, array $context) {
    extract($context, EXTR_SKIP);
    require $path;
}
