<?php
/**
 * tests/mysql/run.php — the invariants only a real database can prove.
 *
 * WHY THIS FILE EXISTS SEPARATELY. Every other suite in this repository runs
 * against hand-written mocks, and for the scan's concurrency invariants that
 * would be worse than no test: "at most one active run per project" and "a stale
 * worker cannot commit" are properties of InnoDB under TWO CONNECTIONS, and a
 * mock asserting them proves only that the mock agrees with itself. This module
 * has shipped that mistake before — v1.4.0 disabled @UVUNIQUE in production
 * while every mocked test passed, because the framework serves methods through
 * __call() and method_exists() answers false.
 *
 * So: two independent mysqli connections, a real server, and assertions about
 * what the SECOND connection observes.
 *
 * Run locally against any MySQL/MariaDB:
 *   UV_DB_HOST=127.0.0.1 UV_DB_USER=root UV_DB_PASS=root UV_DB_NAME=uv_test \
 *     php tests/mysql/run.php
 *
 * In CI it runs once per service in .github/workflows/scan-database.yml.
 * It creates only tables carrying the module's own prefix and drops exactly
 * those at the end — never the schema, never anything it did not create.
 */

require_once __DIR__ . '/../../php/Scan/Schema.php';

use INSPIRE\UniversalValidator\Scan\Schema;

$n = 0; $fail = 0;
function check($label, $cond) {
    global $n, $fail; $n++;
    if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
}

if (!class_exists('mysqli')) {
    fwrite(STDERR, "mysqli is not available in this PHP build\n");
    exit(2);
}

$host = getenv('UV_DB_HOST') ?: '127.0.0.1';
$user = getenv('UV_DB_USER') ?: 'root';
$pass = getenv('UV_DB_PASS');
$name = getenv('UV_DB_NAME') ?: 'uv_test';
$port = (int) (getenv('UV_DB_PORT') ?: 3306);
if ($pass === false) $pass = '';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/** Two INDEPENDENT connections. One cannot see the other's uncommitted work. */
function connect($host, $user, $pass, $name, $port) {
    $c = new mysqli($host, $user, $pass, $name, $port);
    $c->set_charset('utf8mb4');
    return $c;
}

try {
    $A = connect($host, $user, $pass, $name, $port);
    $B = connect($host, $user, $pass, $name, $port);
} catch (\Throwable $e) {
    fwrite(STDERR, "could not connect: " . $e->getMessage() . "\n");
    exit(2);
}

echo 'server: ' . $A->server_info . "\n";

// ISOLATION, honoured rather than merely announced.
//
// The workflow ran this file twice and set UV_DB_ISOLATION on the second pass,
// but nothing here read it - so the "Same invariants under READ COMMITTED" step
// re-ran the DEFAULT isolation and reported a pass for a level it had never
// selected. A test that names a condition it does not create is worse than an
// absent one, because the job's green tick claims the coverage.
//
// Allowlisted rather than interpolated: an isolation level cannot be a bound
// parameter, so it has to be one of a known set before it reaches a statement.
$iso = getenv('UV_DB_ISOLATION');
if ($iso !== false && $iso !== '') {
    $allowed = ['READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE', 'READ UNCOMMITTED'];
    $iso = strtoupper(trim($iso));
    if (!in_array($iso, $allowed, true)) {
        fwrite(STDERR, "unknown isolation level: $iso\n");
        exit(2);
    }
    foreach (array($A, $B) as $conn) {
        $conn->query('SET SESSION TRANSACTION ISOLATION LEVEL ' . $iso);
    }
    echo 'isolation: ' . $iso . "\n";
    // PROVED, not assumed. A SET that silently did nothing would put the job
    // straight back to claiming a level it never selected - which is the defect
    // this block exists to remove, so it must not be reintroduced one line down.
    foreach (array(array('A', $A), array('B', $B)) as $pair) {
        $q = $pair[1]->query('SELECT @@transaction_isolation');
        $row = $q ? $q->fetch_row() : null;
        $got = $row ? str_replace('-', ' ', strtoupper((string) $row[0])) : '';
        check('isolation: connection ' . $pair[0] . ' really is ' . $iso, $got === $iso);
    }
} else {
    echo "isolation: server default\n";
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

$ca = new Conn($A);
$cb = new Conn($B);

// -- clean slate, then migrate -----------------------------------------------
foreach (array_reverse(Schema::tables()) as $t) $A->query('DROP TABLE IF EXISTS ' . $t);

$r = Schema::migrate($ca);
check('migrate: a fresh install succeeds on this server', $r['ok'] === true);
check('migrate: reaching the build version', $r['to'] === Schema::VERSION);

$h = Schema::health($ca);
check('health: reports ok immediately after migrate', $h['ok'] === true);
if (!$h['ok']) {
    // A bare pass/fail on a schema check is unactionable: the whole point is
    // WHICH table is missing, or which read failed.
    fwrite(STDERR, '  health said: ' . (string) $h['why']
        . ' | missing: ' . implode(', ', $h['missing']) . "\n");
}

// IDEMPOTENT: the second connection re-runs it and changes nothing.
$r2 = Schema::migrate($cb);
check('migrate: re-running from another connection is a no-op',
    $r2['ok'] === true && $r2['applied'] === 0);

// -- ONE ACTIVE RUN PER PROJECT ----------------------------------------------
// The whole design rests on this being enforced by the storage engine. A
// read-then-write "is one running?" check in PHP is a race; a UNIQUE key is not.
$run = Schema::table('scan_run');
$ins = function ($conn, $pid, $uuid, $slot) use ($run) {
    $now = date('Y-m-d H:i:s');
    $st = $conn->raw()->prepare('INSERT INTO ' . $run . ' (run_uuid, project_id, run_seq,
        generation_id, created_by, scope_kind, run_kind, phase, coverage, detail, values_state,
        policy_json, policy_revision, fingerprint, created_at, updated_at, active_slot)
        VALUES (?,?,1,1,?,?,?,?,?,?,?,?,1,?,?,?,?)');
    $b = ['uuid' => $uuid, 'pid' => $pid, 'by' => 'tester', 'sk' => 'global', 'rk' => 'full',
          'ph' => 'planning', 'cov' => 'partial', 'det' => 'complete', 'vs' => 'none',
          'pj' => '{}', 'fp' => str_repeat('a', 64), 'c' => $now, 'u' => $now, 'slot' => $slot];
    bindAll($st, array_values($b));
    try { $st->execute(); $st->close(); return true; }
    catch (\Throwable $e) { $st->close(); return false; }
};

check('one-active-run: the first start on a project succeeds',
    $ins($ca, 900, random_bytes(16), 1) === true);
check('one-active-run: a SECOND active run on the same project is refused by the engine',
    $ins($cb, 900, random_bytes(16), 1) === false);
check('one-active-run: a different project is unaffected',
    $ins($cb, 901, random_bytes(16), 1) === true);

// A terminal transition releases the slot by setting it NULL, and MySQL permits
// unlimited NULLs in a UNIQUE index - which is what makes history retainable.
$A->query('UPDATE ' . $run . " SET active_slot = NULL, terminal = 'complete', phase = 'terminal'
    WHERE project_id = 900");
check('one-active-run: a terminal run frees the slot for the next start',
    $ins($cb, 900, random_bytes(16), 1) === true);
$rows = $ca->query('SELECT COUNT(*) FROM ' . $run . ' WHERE project_id = 900', []);
check('one-active-run: and the finished run is still on record, not overwritten',
    (int) $rows[0][0] === 2);

// -- WORKER SLOTS: an installation-wide semaphore ----------------------------
$slots = Schema::table('scan_worker_slot');
foreach ([1, 2, 5] as $limit) {
    $A->query('DELETE FROM ' . $slots);
    for ($i = 1; $i <= $limit; $i++) {
        $A->query('INSERT INTO ' . $slots . ' (slot_no, epoch) VALUES (' . $i . ', 0)');
    }
    // Leasing is an UPDATE with a predicate, never an INSERT that can race.
    $take = function ($conn, $owner) use ($slots) {
        $conn->raw()->query("UPDATE " . $slots . " SET owner = '" . $conn->raw()->real_escape_string($owner)
            . "', epoch = epoch + 1, expires_at = DATE_ADD(NOW(), INTERVAL 60 SECOND)
            WHERE owner IS NULL ORDER BY slot_no LIMIT 1");
        return $conn->raw()->affected_rows === 1;
    };
    $got = 0;
    for ($i = 0; $i < $limit + 3; $i++) {
        if ($take($i % 2 ? $cb : $ca, 'w' . $i)) $got++;
    }
    check("worker-slots: limit $limit hands out exactly $limit leases", $got === $limit);

    // A stale lease can be taken over; a live one cannot.
    $A->query('UPDATE ' . $slots . ' SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE slot_no = 1');
    $B->query("UPDATE " . $slots . " SET owner = 'takeover', epoch = epoch + 1,
        expires_at = DATE_ADD(NOW(), INTERVAL 60 SECOND)
        WHERE slot_no = 1 AND expires_at < NOW()");
    check("worker-slots: limit $limit lets an EXPIRED lease be taken over",
        $B->affected_rows === 1);
    $B->query("UPDATE " . $slots . " SET owner = 'thief' WHERE slot_no = 1 AND expires_at < NOW()");
    check("worker-slots: limit $limit refuses to steal a LIVE lease", $B->affected_rows === 0);
}

// -- LEASE EPOCH FENCING -----------------------------------------------------
// A worker that was overtaken must not be able to commit. The cursor advance is
// the last write of its transaction and is conditioned on its own old value AND
// the epoch it started with; if either moved, zero rows change and it rolls back.
$A->query('UPDATE ' . $run . ' SET lease_epoch = 7, cursor_ordinal = 100 WHERE project_id = 901');
$rid = (int) $ca->query('SELECT run_id FROM ' . $run . ' WHERE project_id = 901', [])[0][0];

$B->query('UPDATE ' . $run . ' SET lease_epoch = 8 WHERE run_id = ' . $rid);   // someone took over
$A->query('UPDATE ' . $run . ' SET cursor_ordinal = 200
    WHERE run_id = ' . $rid . ' AND cursor_ordinal = 100 AND lease_epoch = 7');
check('lease-fencing: a worker whose epoch moved changes NOTHING', $A->affected_rows === 0);

$A->query('UPDATE ' . $run . ' SET cursor_ordinal = 200
    WHERE run_id = ' . $rid . ' AND cursor_ordinal = 100 AND lease_epoch = 8');
check('lease-fencing: and the current epoch holder advances normally', $A->affected_rows === 1);

// Cancellation is the same mechanism: it bumps the epoch, so an already
// evaluating worker fails its final CAS and rolls back everything it buffered.
$B->query('UPDATE ' . $run . " SET cancel_requested_at = NOW(), phase = 'cancelling',
    lease_epoch = lease_epoch + 1 WHERE run_id = " . $rid);
$A->query('UPDATE ' . $run . ' SET cursor_ordinal = 300
    WHERE run_id = ' . $rid . ' AND cursor_ordinal = 200 AND lease_epoch = 8
      AND cancel_requested_at IS NULL');
check('cancellation: an in-flight worker cannot commit after a cancel', $A->affected_rows === 0);

// -- ONE ACTIVE VERSION PER FINDING IDENTITY ---------------------------------
$fnd = Schema::table('finding');
$insF = function ($conn, $identity, $slot) use ($fnd) {
    $sql = 'INSERT INTO ' . $fnd . ' (generation_id, finding_identity, valid_from_seq, active_slot,
        record_hash, record_id_bin, host_form, field, rule_source_id, rule_revision, rule_ord,
        check_type, reason_code) VALUES (1, ?, 1, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)';
    $st = $conn->raw()->prepare($sql);
    $rh = hash('sha256', 'r1', true); $rid = 'r1'; $hf = 'fa'; $f = 'x';
    $rs = 'rule1'; $rv = str_repeat('b', 64); $ct = 'required'; $rc = 'required-blank';
    bindAll($st, [$identity, $slot, $rh, $rid, $hf, $f, $rs, $rv, $ct, $rc]);
    try { $st->execute(); $st->close(); return true; }
    catch (\Throwable $e) { $st->close(); return false; }
};
$id1 = hash('sha256', 'identity-1', true);
check('finding-versions: the first active version inserts', $insF($ca, $id1, 1) === true);
check('finding-versions: a SECOND active version of the same identity is refused',
    $insF($cb, $id1, 1) === false);
$A->query('UPDATE ' . $fnd . ' SET active_slot = NULL, valid_to_seq = 2 WHERE finding_identity = 0x'
    . bin2hex($id1));
check('finding-versions: closing the old one lets the new generation insert',
    $insF($cb, $id1, 1) === true);
$cnt = (int) $ca->query('SELECT COUNT(*) FROM ' . $fnd . ' WHERE finding_identity = 0x'
    . bin2hex($id1), [])[0][0];
check('finding-versions: history is retained, not replaced', $cnt === 2);

// -- teardown: exactly our tables, nothing else ------------------------------
foreach (array_reverse(Schema::tables()) as $t) $A->query('DROP TABLE IF EXISTS ' . $t);

echo "scan_store_mysql: $n checks, $fail failure(s)\n";
exit($fail ? 1 : 0);
