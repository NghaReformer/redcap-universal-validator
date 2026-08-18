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
require_once __DIR__ . '/../../php/Scan/ScanOutcome.php';
require_once __DIR__ . '/../../php/Scan/ScanStore.php';
require_once __DIR__ . '/../../php/Scan/ScanDb.php';
require_once __DIR__ . '/../../php/Scan/SqlScanStore.php';
require_once __DIR__ . '/../../php/Scan/WorkerSlots.php';
require_once __DIR__ . '/../../php/Scan/ScanRetention.php';

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
    //
    // The variable has TWO names across this matrix and neither server has both:
    // MySQL 8.0 removed @@tx_isolation, and MariaDB 10.5/10.11 have not yet
    // added @@transaction_isolation. Asking for the MySQL name alone was a fatal
    // on both MariaDB legs - the SET had already succeeded, so what failed was
    // the check that the SET worked. Try each, and treat "neither answered" as a
    // failure rather than as a pass, or this lands back where it started.
    $readIso = function ($conn) {
        foreach (array('@@transaction_isolation', '@@tx_isolation') as $var) {
            try {
                $q = $conn->query('SELECT ' . $var);
                $row = $q ? $q->fetch_row() : null;
                if ($row && isset($row[0])) {
                    return str_replace('-', ' ', strtoupper((string) $row[0]));
                }
            } catch (\Throwable $e) {
                // This server names it the other way; try that.
            }
        }
        return '';
    };
    foreach (array(array('A', $A), array('B', $B)) as $pair) {
        check('isolation: connection ' . $pair[0] . ' really is ' . $iso,
            $readIso($pair[1]) === $iso);
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

// -- SqlScanStore: the SAME class the module runs -----------------------------
//
// Everything above asserts that the SCHEMA holds its invariants. This asserts
// that the STORE uses them correctly, which is a different claim: a correct
// UNIQUE key with a store that catches the wrong exception still lets two runs
// start. Only ScanDb differs between here and REDCap.

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

use INSPIRE\UniversalValidator\Scan\SqlScanStore;
use INSPIRE\UniversalValidator\Scan\ScanStore;
use INSPIRE\UniversalValidator\Scan\ScanOutcome;

$A->query('DELETE FROM ' . Schema::table('scan_record'));
$A->query('DELETE FROM ' . Schema::table('finding'));
$A->query('DELETE FROM ' . Schema::table('scan_run'));
$A->query('DELETE FROM ' . Schema::table('scan_worker_slot'));
$A->query('DELETE FROM ' . Schema::table('scan_audit'));
for ($i = 1; $i <= 2; $i++) {
    $A->query('INSERT INTO ' . Schema::table('scan_worker_slot') . ' (slot_no, epoch) VALUES (' . $i . ', 0)');
}

$storeA = new SqlScanStore(new MysqliDb($A));
$storeB = new SqlScanStore(new MysqliDb($B));

// START: one wins, the other is told busy WITHOUT being told anything else.
$r1 = $storeA->startRun(700, ['created_by' => 'alice']);
check('store: the first start succeeds', $r1['ok'] === true && $r1['busy'] === false);
$runId = (int) $r1['run']['run_id'];
$r2 = $storeB->startRun(700, ['created_by' => 'bob']);
check('store: a second start on the same project is BUSY, not an error',
    $r2['ok'] === false && $r2['busy'] === true && $r2['run'] === null);
check('store: and busy names no run, owner or scope',
    preg_match('/\d/', $r2['why']) === 0 && stripos($r2['why'], 'alice') === false);

// The run id is a LOCATOR. It must not resolve across projects.
check('store: a run id from another project does not resolve',
    $storeB->run(701, $runId) === null);
check('store: but resolves for its own', $storeA->run(700, $runId) !== null);

// MANIFEST: totals are set with the rows, in one transaction.
$recs = [];
for ($i = 1; $i <= 7; $i++) {
    $recs[] = ['id_bin' => 'REC-' . $i, 'hash' => hash('sha256', 'REC-' . $i, true),
               'dag' => $i % 2 ? 'north' : null];
}
check('store: the manifest writes every record', $storeA->writeManifest($runId, $recs) === 7);
$run = $storeA->run(700, $runId);
check('store: and publishes the total with them', (int) $run['manifest_total'] === 7);
check('store: leaving the run ready to scan', $run['phase'] === 'scanning');
check('store: an empty manifest is not complete-by-vacuum',
    $storeA->manifestComplete($runId) === false);

// CLAIM: fenced on the epoch.
$epoch = (int) $run['lease_epoch'];
$claim = $storeA->claim($runId, 'workerA', $epoch, 3);
check('store: a claim returns the requested range', count($claim) === 3);
check('store: in ordinal order', $claim[0]['ordinal'] === 1 && $claim[2]['ordinal'] === 3);
check('store: carrying the worker locator, not a hash',
    $claim[0]['id_bin'] === 'REC-1');
$stale = $storeB->claim($runId, 'workerB', $epoch - 1, 3);
check('store: a claim at a STALE epoch gets nothing', $stale === []);

// COMMIT: findings + record states + counters, atomically and fenced.
$batch = ['bytes' => 40, 'records' => [], 'findings' => []];
foreach ($claim as $c) {
    $batch['records'][] = ['ordinal' => $c['ordinal'], 'state' => ScanStore::REC_DONE,
                           'version' => 'v1'];
    $batch['findings'][] = [
        'generation_id' => 1, 'identity' => hash('sha256', 'f' . $c['ordinal'], true),
        'seq' => 1, 'record_hash' => $c['hash'], 'record_id_bin' => $c['id_bin'],
        'event_id' => null, 'instance' => 1, 'host_form' => 'fa', 'field' => 'x',
        'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64), 'rule_ord' => 1,
        'check_type' => 'required', 'reason_code' => 'required-blank',
    ];
}
check('store: a fenced batch commits', $storeA->commitBatch($runId, 'workerA', $epoch, 0, $batch) === true);
$run = $storeA->run(700, $runId);
check('store: advancing manifest_done by the records it finished',
    (int) $run['manifest_done'] === 3);
check('store: and counting the findings it retained', (int) $run['detail_rows'] === 3);
check('store: still not complete with four records left',
    $storeA->manifestComplete($runId) === false);

// A worker whose epoch moved must commit NOTHING - not "some of it".
$storeB->cancel(700, $runId, 'admin');
$after = $storeA->run(700, $runId);
check('store: cancel bumps the epoch', (int) $after['lease_epoch'] === $epoch + 1);
$rows0 = $storeA->run(700, $runId)['detail_rows'];
$lost = ['bytes' => 10, 'records' => [['ordinal' => 4, 'state' => ScanStore::REC_DONE]],
         'findings' => [[
            'generation_id' => 1, 'identity' => hash('sha256', 'f-lost', true), 'seq' => 1,
            'record_hash' => hash('sha256', 'REC-4', true), 'record_id_bin' => 'REC-4',
            'instance' => 1, 'host_form' => 'fa', 'field' => 'x', 'rule_source_id' => 'r1',
            'rule_revision' => str_repeat('c', 64), 'check_type' => 'required',
            'reason_code' => 'required-blank']]];
check('store: an overtaken worker cannot commit',
    $storeA->commitBatch($runId, 'workerA', $epoch, 0, $lost) === false);
check('store: and left NO finding behind',
    (int) $storeA->run(700, $runId)['detail_rows'] === (int) $rows0);
$left = $ca->query('SELECT COUNT(*) FROM ' . Schema::table('finding')
    . " WHERE record_id_bin = 'REC-4'", []);
check('store: the rolled-back finding row does not exist', (int) $left[0][0] === 0);
$st4 = $ca->query('SELECT state FROM ' . Schema::table('scan_record')
    . ' WHERE run_id = ' . $runId . ' AND ordinal = 4', []);
check('store: and its record is still pending, so it can be re-claimed',
    (int) $st4[0][0] === ScanStore::REC_PENDING);

// FINISH: releases the slot, idempotently.
$outcome = ScanOutcome::derive(['fenced' => true, 'manifestDone' => false, 'blocked' => true]);
check('store: finishing a run succeeds once', $storeA->finish($runId, $outcome) === true);
check('store: and a retried finaliser changes nothing',
    $storeA->finish($runId, $outcome) === false);
check('store: the slot is released, so the next scan may start',
    $storeB->startRun(700, ['created_by' => 'carol'])['ok'] === true);

// SLOTS through the store, with only two precreated.
$s1 = $storeA->leaseSlot('w1', $runId, 60);
$s2 = $storeB->leaseSlot('w2', $runId, 60);
check('store: two slots lease', $s1 !== null && $s2 !== null);
check('store: a third finds none free', $storeA->leaseSlot('w3', $runId, 60) === null);
check('store: a stale holder releases nothing',
    $storeA->releaseSlot($s1['slot_no'], 'w1', $s1['epoch'] + 5) === false);
check('store: the real holder does', $storeA->releaseSlot($s1['slot_no'], 'w1', $s1['epoch']) === true);
check('store: freeing it for the next worker', $storeA->leaseSlot('w3', $runId, 60) !== null);

// RETENTION: a value expires without the finding disappearing.
$A->query('UPDATE ' . Schema::table('finding') . " SET value_bin = 'secret',
    value_expires_at = '2000-01-01 00:00:00' WHERE record_id_bin = 'REC-1'");
check('store: an expired value is cleared', $storeA->expireValues(gmdate('Y-m-d H:i:s')) >= 1);
$v = $ca->query('SELECT value_bin FROM ' . Schema::table('finding')
    . " WHERE record_id_bin = 'REC-1'", []);
check('store: the value is gone', $v[0][0] === null);
$cnt = $ca->query('SELECT COUNT(*) FROM ' . Schema::table('finding')
    . " WHERE record_id_bin = 'REC-1'", []);
check('store: but the finding remains - a report must not shrink as it ages',
    (int) $cnt[0][0] === 1);

// -- the SHARED contract, against SqlScanStore --------------------------------
//
// The identical assertions run in the fast suite against ArrayScanStore. Two
// independent implementations judged by one set disagree wherever the contract
// is ambiguous - which is exactly how the affected()-versus-FOR-UPDATE fence bug
// would have surfaced, since an in-memory store has no notion of "rows changed".
//
// Each scenario needs a clean slate, so the factory truncates rather than
// reconnecting: the contract must not know which store it is judging, and must
// not be handed a store that remembers the previous scenario's run.
require_once __DIR__ . '/../scan_store_contract.php';

$fresh = function () use ($A, $storeA) {
    foreach (array('scan_record', 'finding', 'scan_run', 'scan_worker_slot', 'scan_audit') as $t) {
        $A->query('DELETE FROM ' . Schema::table($t));
    }
    for ($i = 1; $i <= 2; $i++) {
        $A->query('INSERT INTO ' . Schema::table('scan_worker_slot')
            . ' (slot_no, epoch) VALUES (' . $i . ', 0)');
    }
    return $storeA;
};
\INSPIRE\UniversalValidator\Scan\storeContract($fresh, 'sql-store');

// -- WorkerSlots: provisioning, renewal, and browser/cron overlap -------------
{
    $dbA = new MysqliDb($A);
    $dbB = new MysqliDb($B);
    $slotsA = new \INSPIRE\UniversalValidator\Scan\WorkerSlots($dbA);
    $slotsB = new \INSPIRE\UniversalValidator\Scan\WorkerSlots($dbB);
    $A->query('DELETE FROM ' . Schema::table('scan_worker_slot'));

    check('slots: provisioning creates the configured number', $slotsA->provision(3) === 3);
    check('slots: and is idempotent - saving settings twice adds nothing',
        $slotsA->provision(3) === 0);
    check('slots: raising the limit adds only the difference', $slotsA->provision(5) === 2);
    // Additive only: lowering must not delete a row that may be leased right now.
    check('slots: LOWERING the limit deletes nothing', $slotsA->provision(2) === 0);
    $c = $slotsA->census();
    check('slots: the census reports what exists', $c['total'] === 5 && $c['held'] === 0);
    check('slots: and names which are idle above a reduced limit',
        $slotsA->idleAbove(2) === [3, 4, 5]);

    // BROWSER AND CRON COMPETE FOR THE SAME POOL. That is the point of an
    // installation-wide semaphore: a cron that ignored browser leases would let
    // the server run 2N workers whenever someone had a tab open.
    $A->query('DELETE FROM ' . Schema::table('scan_worker_slot'));
    $slotsA->provision(2);
    $browser = $slotsA->acquire('browser-1', 1, 60);
    $cron    = $slotsB->acquire('cron-1', 1, 60);
    check('slots: a browser worker and a cron worker share one pool',
        $browser !== null && $cron !== null);
    check('slots: and the third is refused whichever kind it is',
        $slotsA->acquire('cron-2', 1, 60) === null);

    check('slots: the holder may renew',
        $slotsA->renew($browser['slot_no'], 'browser-1', $browser['epoch'], 60) === true);
    check('slots: a stale epoch may not renew',
        $slotsA->renew($browser['slot_no'], 'browser-1', $browser['epoch'] - 1, 60) === false);
    check('slots: nor may someone else',
        $slotsB->renew($browser['slot_no'], 'cron-1', $browser['epoch'], 60) === false);
    check('slots: the census counts held leases',
        $slotsA->census()['held'] === 2);

    // EXPIRY IS WHAT MAKES THIS A SEMAPHORE RATHER THAN A LEAK: the browser
    // closes and nobody runs any cleanup.
    $A->query('UPDATE ' . Schema::table('scan_worker_slot')
        . " SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)
            WHERE owner = 'browser-1'");
    $taken = $slotsB->acquire('cron-2', 1, 60);
    check('slots: an abandoned lease returns to the pool on its own', $taken !== null);
    check('slots: and the worker that lost it can no longer renew',
        $slotsA->renew($browser['slot_no'], 'browser-1', $browser['epoch'], 60) === false);
    check('slots: nor release it, which would hand away a LIVE lease',
        $slotsA->release($browser['slot_no'], 'browser-1', $browser['epoch']) === false);
}

// -- ScanRetention: three clocks, and nothing silently loses a finding --------
{
    $dbA = new MysqliDb($A);
    $ret = new \INSPIRE\UniversalValidator\Scan\ScanRetention($dbA);
    $store = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA);
    foreach (array('scan_record', 'finding', 'scan_run', 'scan_audit') as $t) {
        $A->query('DELETE FROM ' . Schema::table($t));
    }

    $r = $store->startRun(800, array('created_by' => 'alice'));
    $rid = (int) $r['run']['run_id'];
    $gen = (int) $r['run']['generation_id'];
    $store->writeManifest($rid, array(
        array('id_bin' => 'R1', 'hash' => hash('sha256', 'R1', true), 'dag' => null)));
    $epoch = (int) $store->run(800, $rid)['lease_epoch'];
    $store->claim($rid, 'w', $epoch, 1);
    $store->commitBatch($rid, 'w', $epoch, 0, array(
        'bytes' => 10,
        'records' => array(array('ordinal' => 1, 'state' => \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE)),
        'findings' => array(array(
            'generation_id' => $gen, 'identity' => hash('sha256', 'x', true), 'seq' => 1,
            'record_hash' => hash('sha256', 'R1', true), 'record_id_bin' => 'R1',
            'instance' => 1, 'host_form' => 'fa', 'field' => 'x', 'rule_source_id' => 'r1',
            'rule_revision' => str_repeat('c', 64), 'check_type' => 'required',
            'reason_code' => 'required-blank', 'value_bin' => 'SECRET',
            'value_expires_at' => '2000-01-01 00:00:00'))));

    check('retention: nothing expires before its time',
        $ret->expireValues('1999-01-01 00:00:00') === 0);
    check('retention: an overdue value is cleared', $ret->expireValues() === 1);
    $v = $ca->query('SELECT value_bin, value_expires_at FROM ' . Schema::table('finding'), array());
    check('retention: the value is gone', $v[0][0] === null && $v[0][1] === null);
    // The row survives: a report that shrinks as it ages reads as the project
    // having improved, which is the misreading this module exists to prevent.
    // NOT $n: check() keeps its counter in a global of that name, and assigning
    // to it here silently replaced an integer with a result set - the suite then
    // died incrementing an array, several checks later and nowhere near the
    // cause. Test-local names in a file that uses globals are a hazard.
    $kept = $ca->query('SELECT COUNT(*) FROM ' . Schema::table('finding'), array());
    check('retention: but the FINDING remains', (int) $kept[0][0] === 1);

    // An active run is never purged, however old it looks.
    $A->query('UPDATE ' . Schema::table('scan_run')
        . " SET updated_at = '2000-01-01 00:00:00' WHERE run_id = " . $rid);
    check('retention: an ACTIVE run is never purged, whatever its age',
        $ret->purgeRuns(800, 1) === 0);

    // Abandonment: the lease lapsed and nothing has moved.
    $A->query('UPDATE ' . Schema::table('scan_run')
        . " SET lease_expires_at = '2000-01-01 00:00:00' WHERE run_id = " . $rid);
    check('retention: an abandoned run is expired', $ret->expireAbandoned(1) === 1);
    $ex = $store->run(800, $rid);
    check('retention: as a real terminal state, never as complete',
        $ex['terminal'] === \INSPIRE\UniversalValidator\Scan\ScanOutcome::EXPIRED
        && $ex['coverage'] === \INSPIRE\UniversalValidator\Scan\ScanOutcome::COV_PARTIAL);
    check('retention: releasing the project slot for the next scan',
        $store->startRun(800, array('created_by' => 'bob'))['ok'] === true);
    check('retention: and expiring it again does nothing', $ret->expireAbandoned(1) === 0);

    // Purge cascades to every child table.
    $A->query('UPDATE ' . Schema::table('scan_run')
        . " SET updated_at = '2000-01-01 00:00:00' WHERE run_id = " . $rid);
    check('retention: a finished, aged run purges', $ret->purgeRuns(800, 1) === 1);
    $left = $ca->query('SELECT COUNT(*) FROM ' . Schema::table('scan_record')
        . ' WHERE run_id = ' . $rid, array());
    check('retention: taking its manifest rows with it', (int) $left[0][0] === 0);
    $lf = $ca->query('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE generation_id = ' . $gen, array());
    check('retention: and its findings, so no orphan outlives its run',
        (int) $lf[0][0] === 0);
}

// -- fault injection: what a real failure does -------------------------------
//
// The plan asks for injected database failures rather than mocked ones, because
// the question is what the STORE does when the server says no - and a mock that
// throws whatever the test chose proves only that the test can throw.
{
    $dbA = new MysqliDb($A);
    $store = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA);
    foreach (array('scan_record', 'finding', 'scan_run') as $t) {
        $A->query('DELETE FROM ' . Schema::table($t));
    }
    $r = $store->startRun(900, array('created_by' => 'alice'));
    $rid = (int) $r['run']['run_id'];
    $store->writeManifest($rid, array(
        array('id_bin' => 'R1', 'hash' => hash('sha256', 'R1', true), 'dag' => null),
        array('id_bin' => 'R2', 'hash' => hash('sha256', 'R2', true), 'dag' => null)));
    $epoch = (int) $store->run(900, $rid)['lease_epoch'];
    $store->claim($rid, 'w', $epoch, 2);

    // A finding whose reason_code exceeds its column. The batch must roll back
    // ENTIRELY - a half-written batch would mark records done whose findings
    // were never stored, which is the one outcome that produces a confidently
    // clean report over unexamined data.
    $bad = array(
        'bytes' => 10,
        'records' => array(array('ordinal' => 1, 'state' => \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE)),
        'findings' => array(array(
            'generation_id' => 1, 'identity' => hash('sha256', 'bad', true), 'seq' => 1,
            'record_hash' => hash('sha256', 'R1', true), 'record_id_bin' => 'R1',
            'instance' => 1, 'host_form' => 'fa', 'field' => 'x', 'rule_source_id' => 'r1',
            'rule_revision' => str_repeat('c', 64), 'check_type' => 'required',
            'reason_code' => str_repeat('z', 200))));   // column is VARCHAR(64)
    check('fault: a batch whose write fails does not commit',
        $store->commitBatch($rid, 'w', $epoch, 0, $bad) === false);
    $f = $ca->query('SELECT COUNT(*) FROM ' . Schema::table('finding'), array());
    check('fault: leaving no partial findings', (int) $f[0][0] === 0);
    $st = $ca->query('SELECT state FROM ' . Schema::table('scan_record')
        . ' WHERE run_id = ' . $rid . ' AND ordinal = 1', array());
    check('fault: and the record still PENDING, so the work is re-claimable',
        (int) $st[0][0] === \INSPIRE\UniversalValidator\Scan\ScanStore::REC_PENDING);
    check('fault: the run is not marked as having done it',
        (int) $store->run(900, $rid)['manifest_done'] === 0);

    // A revoked privilege is a failure the store cannot retry its way out of.
    // It must report false rather than throw past its caller: the worker needs
    // to stop, and a fatal would leave the run with no terminal state at all.
    $A->query('CREATE TABLE IF NOT EXISTS uv_readonly_probe (id INT)');
    $A->query('LOCK TABLES uv_readonly_probe READ');
    $ok = true;
    try {
        // Writing to a table not named in LOCK TABLES is refused while the lock
        // is held - a real server-side write failure, not a simulated one.
        $store->commitBatch($rid, 'w', $epoch, 0, array(
            'bytes' => 0, 'records' => array(), 'findings' => array()));
    } catch (\Throwable $e) {
        $ok = false;
    }
    $A->query('UNLOCK TABLES');
    $A->query('DROP TABLE IF EXISTS uv_readonly_probe');
    check('fault: a refused write returns rather than escaping as a fatal', $ok === true);
}

// -- teardown: exactly our tables, nothing else ------------------------------
foreach (array_reverse(Schema::tables()) as $t) $A->query('DROP TABLE IF EXISTS ' . $t);

echo "scan_store_mysql: $n checks, $fail failure(s)\n";
exit($fail ? 1 : 0);
