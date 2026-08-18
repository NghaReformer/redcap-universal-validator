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
require_once __DIR__ . '/../../php/Scan/ScanPhase.php';
require_once __DIR__ . '/../../php/Scan/ScanStore.php';
require_once __DIR__ . '/../../php/Scan/ScanDb.php';
require_once __DIR__ . '/../../php/Scan/SqlScanStore.php';
require_once __DIR__ . '/../../php/Scan/WorkerSlots.php';
require_once __DIR__ . '/../../php/Scan/ScanRetention.php';
require_once __DIR__ . '/../../php/Scan/SourceFence.php';
require_once __DIR__ . '/../../php/Scan/RecordManifestSource.php';
require_once __DIR__ . '/../../php/Scan/Hmac.php';
require_once __DIR__ . '/../../php/Scan/ScanPolicy.php';
require_once __DIR__ . '/../../php/Scan/ScanPlanner.php';
require_once __DIR__ . '/../../php/Scan/WorkBudget.php';
require_once __DIR__ . '/../../php/Scan/ScanWorker.php';
require_once __DIR__ . '/../../php/Scan/UniqueFinalizer.php';

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

// -- the record walk and the source fence, against REDCap-shaped tables ------
//
// These two classes are almost entirely SQL, so a mock of them would be a mock
// of the thing being tested. What they need instead is tables shaped like
// REDCap's, on a real server, with a real collation - because the one behaviour
// that decides whether a record can be silently skipped is how the server
// compares two record ids, and no PHP fixture has an opinion about that.
{
    $dbA = new MysqliDb($A);

    // Nothing exists yet: the walk must refuse BEFORE a run is created, rather
    // than fall back to exporting the project - which is the failure the whole
    // rebuild exists to remove.
    foreach (array('redcap_record_list', 'redcap_data', 'redcap_projects',
                   'redcap_log_event') as $t) {
        $A->query('DROP TABLE IF EXISTS ' . $t);
    }
    $none = \INSPIRE\UniversalValidator\Scan\RecordManifestSource::open($dbA, 42, array('pk' => 'record_id'));
    check('walk: with no usable source the walk is refused, not softened',
        $none['ok'] === false && $none['source'] === null);
    check('walk: and the refusal says what is missing',
        strpos($none['why'], 'without exporting the whole project') !== false);
    $nf = \INSPIRE\UniversalValidator\Scan\SourceFence::forProject($dbA, 42);
    check('fence: with no project row there is no fence', $nf['ok'] === false);

    $A->query('CREATE TABLE redcap_projects (project_id INT PRIMARY KEY,
        log_event_table VARCHAR(64) NULL, data_table VARCHAR(64) NULL) ENGINE=InnoDB');
    // No UNIQUE key on (project_id, record) on purpose: the tie handling below
    // is defensive code for a source that permits two ids the server considers
    // equal, and a unique key would make that state unreachable - which is
    // exactly why a real REDCap rarely produces it, and no reason to leave the
    // handling untested.
    $A->query('CREATE TABLE redcap_record_list (project_id INT NOT NULL,
        record VARCHAR(100) NOT NULL, dag_id INT NULL, KEY (project_id, record)) ENGINE=InnoDB');
    $A->query('CREATE TABLE redcap_data (project_id INT NOT NULL, event_id INT NOT NULL,
        record VARCHAR(100) NOT NULL, field_name VARCHAR(100) NOT NULL,
        `value` TEXT NULL, instance INT NULL,
        KEY (project_id, field_name, record)) ENGINE=InnoDB');
    $A->query("INSERT INTO redcap_projects (project_id, log_event_table, data_table)
        VALUES (900, 'redcap_log_event', 'redcap_data')");

    // 25 records, so paging is exercised rather than described.
    for ($i = 1; $i <= 25; $i++) {
        $A->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES (900, '"
            . sprintf('R%03d', $i) . "', " . ($i % 3 === 0 ? '7' : 'NULL') . ')');
    }
    $open = \INSPIRE\UniversalValidator\Scan\RecordManifestSource::open($dbA, 900, array('pk' => 'record_id'));
    check('walk: the record index is preferred when it answers for this project',
        $open['ok'] === true && $open['source']->via() === 'redcap_record_list');
    check('walk: and it can report which group a record is in',
        $open['source']->hasDag() === true);

    $src = $open['source'];
    $seen = array();
    $cursor = null; $carry = array(); $pages = 0;
    while ($pages++ < 50) {
        $pg = $src->page($cursor, $carry, 10);
        if (!$pg['ok']) { check('walk: paging stayed usable', false); break; }
        foreach ($pg['rows'] as $r) $seen[] = $r['id'];
        $cursor = $pg['cursor']; $carry = $pg['emitted'];
        if ($pg['done']) break;
    }
    check('walk: every record is listed', count($seen) === 25);
    check('walk: exactly once', count(array_unique($seen)) === 25);
    check('walk: in order', $seen === array_values($seen) && $seen[0] === 'R001'
        && $seen[24] === 'R025');
    check('walk: and it finished in bounded pages', $pages <= 5);

    $pg = $src->page(null, array(), 3);
    check('walk: a group is carried with the record it belongs to',
        $pg['rows'][2]['id'] === 'R003' && $pg['rows'][2]['dag'] === '7');
    check('walk: and an ungrouped record says so rather than guessing',
        $pg['rows'][0]['dag'] === null);

    // THE CASE THAT DECIDES WHETHER A RECORD CAN BE SKIPPED. utf8mb4_unicode_ci
    // ignores trailing spaces, so the server considers 'T1' and 'T1 ' the same
    // value while they are different records. A keyset walk using `>` would step
    // over the second one and the run would certify a record it never read.
    $A->query('DELETE FROM redcap_record_list');
    // FORCED, not assumed. MySQL 8.0 defaults its schemas to utf8mb4_0900_ai_ci,
    // which is NO PAD, so trailing spaces are significant there and this
    // scenario would quietly test nothing; MariaDB 10.x defaults to a PAD SPACE
    // collation, where they are not. Naming the collation makes the boundary
    // machinery run on every server in the matrix rather than on whichever ones
    // happen to pad - and it is a real collation a REDCap installation can have.
    $A->query('ALTER TABLE redcap_record_list
        MODIFY record VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
    foreach (array('T1', 'T1 ', 'T1  ', 'T2') as $r) {
        $A->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES (900, '"
            . $r . "', NULL)");
    }
    // THE COLUMN'S COLLATION, NOT THE CONNECTION'S - and the two really do
    // disagree. This schema's columns are utf8mb4_unicode_ci, which pads; MySQL
    // 8.0's default connection collation is utf8mb4_0900_ai_ci, which does not.
    // So `SELECT 'T1' = 'T1 '` answers 0 on MySQL and 1 on MariaDB while the
    // COLUMN answers the same on both. That is exactly why the page boundary is
    // established by querying the source table rather than by comparing two
    // bound parameters: the parameter form asks a different question that
    // happens to have the same shape, and it would have passed on MariaDB.
    $eq = $dbA->select("SELECT COUNT(*) FROM redcap_record_list
        WHERE project_id = 900 AND record = 'T1'");
    check('walk: the record COLUMN treats those three ids as one value',
        isset($eq[0][0]) && (int) $eq[0][0] === 3);

    $seen = array(); $cursor = null; $carry = array(); $pages = 0;
    while ($pages++ < 20) {
        $pg = $src->page($cursor, $carry, 1);      // one at a time: every page is a boundary
        if (!$pg['ok']) { check('walk: tie paging stayed usable', false); break; }
        foreach ($pg['rows'] as $r) $seen[] = $r['id'];
        $cursor = $pg['cursor']; $carry = $pg['emitted'];
        if ($pg['done']) break;
    }
    sort($seen);
    check('walk: ids the server cannot tell apart are still all listed',
        count($seen) === 4);
    check('walk: exactly once each, by their real bytes',
        $seen === array('T1', 'T1 ', 'T1  ', 'T2'));

    // The fallback source: no record index for this project at all.
    $A->query('DELETE FROM redcap_record_list');
    for ($i = 1; $i <= 4; $i++) {
        // Two events per record: the walk must list each record once, not once
        // per event.
        $A->query("INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`)
            VALUES (900, 1, 'D" . $i . "', 'record_id', 'D" . $i . "'),
                   (900, 2, 'D" . $i . "', 'record_id', 'D" . $i . "')");
    }
    $A->query("INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`)
        VALUES (900, 1, 'D2', '__GROUPID__', '31')");
    $fb = \INSPIRE\UniversalValidator\Scan\RecordManifestSource::open($dbA, 900, array('pk' => 'record_id'));
    check('walk: an empty record index falls through to the data table',
        $fb['ok'] === true && strpos($fb['source']->via(), 'redcap_data') === 0);
    $pg = $fb['source']->page(null, array(), 10);
    $ids = array();
    foreach ($pg['rows'] as $r) $ids[] = $r['id'];
    check('walk: a record held in several events is listed once',
        $ids === array('D1', 'D2', 'D3', 'D4'));
    check('walk: and its group comes from the project\'s own group rows',
        $pg['rows'][1]['dag'] === '31' && $pg['rows'][0]['dag'] === null);

    // Without the record-id field name there is no bounded walk of the data
    // table, and refusing is the only honest answer.
    $noPk = \INSPIRE\UniversalValidator\Scan\RecordManifestSource::open($dbA, 900, array());
    check('walk: no record-id field means no walk, and it says so',
        $noPk['ok'] === false
        && strpos($noPk['why'], 'record-id field could not be determined') !== false);

    // A table name can never be a bound parameter, so the allowlist is the only
    // thing between redcap_projects and an interpolated identifier.
    $A->query("UPDATE redcap_projects SET data_table = 'redcap_data; DROP TABLE x'
        WHERE project_id = 900");
    check('walk: a data-table name that is not one is refused, not interpolated',
        \INSPIRE\UniversalValidator\Scan\RecordManifestSource::dataTable($dbA, 900) === 'redcap_data');
    $A->query("UPDATE redcap_projects SET data_table = 'redcap_data' WHERE project_id = 900");

    // -- the fence ------------------------------------------------------------
    $A->query('CREATE TABLE redcap_log_event (log_event_id BIGINT PRIMARY KEY,
        project_id INT NOT NULL, pk VARCHAR(100) NULL, event VARCHAR(32) NULL,
        KEY (project_id, log_event_id)) ENGINE=InnoDB');
    $A->query("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event) VALUES
        (100, 900, 'D1', 'INSERT'), (110, 900, 'D2', 'UPDATE'),
        (120, 900, 'D1', 'UPDATE'), (130, 900, NULL, 'DATA_EXPORT'),
        (140, 900, 'D3', 'UPDATE'), (150, 901, 'X1', 'UPDATE')");

    $ff = \INSPIRE\UniversalValidator\Scan\SourceFence::forProject($dbA, 900);
    check('fence: a project with an ordered log can be fenced', $ff['ok'] === true);
    $fence = $ff['fence'];
    check('fence: the opening fence is the top of the log', $fence->now() === '140');
    check('fence: and it is a string, because a bigint is not an int everywhere',
        is_string($fence->now()));

    $v = $fence->versions(array('D1', 'D2', 'D9'));
    check('fence: each record carries its own latest version',
        $v['D1'] === '120' && $v['D2'] === '110');
    check('fence: a record with no history answers null, which is an answer',
        array_key_exists('D9', $v) && $v['D9'] === null);
    check('fence: another project\'s entries are not this project\'s versions',
        !array_key_exists('X1', $v));

    check('fence: the interval is covered while the opening entry survives',
        $fence->retained('140')['ok'] === true);
    // Some installations prune their log. A catch-up over a window it cannot see
    // would report "nothing changed" about changes it simply cannot read.
    $A->query('DELETE FROM redcap_log_event WHERE log_event_id < 130 AND project_id = 900');
    $gone = $fence->retained('100');
    check('fence: a pruned log refuses to certify the interval', $gone['ok'] === false);
    check('fence: and says the log was removed rather than that nothing changed',
        strpos($gone['why'], 'removed since') !== false);

    $A->query("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event) VALUES
        (160, 900, 'D2', 'UPDATE'), (170, 900, 'D4', 'INSERT'),
        (180, 900, 'D2', 'UPDATE'), (190, 900, '', 'MANAGE')");
    $chg = $fence->changedSince('130', '180', null, 10);
    $names = array();
    foreach ($chg as $c) $names[] = $c['id'];
    check('fence: only records changed inside the window are listed',
        $names === array('D2', 'D3', 'D4'));
    check('fence: and each carries the newest version in that window',
        $chg[0]['version'] === '180');
    check('fence: an entry with no record is not a record change',
        !in_array('', $names, true));
    $one = $fence->changedSince('130', '180', null, 1);
    $rest = $fence->changedSince('130', '180', $one[0]['id'], 10);
    check('fence: the change list pages by keyset rather than by offset',
        count($one) === 1 && $one[0]['id'] === 'D2'
        && count($rest) === 2 && $rest[0]['id'] === 'D3' && $rest[1]['id'] === 'D4');

    // A log table name that is not a log table name never reaches a statement.
    $A->query("UPDATE redcap_projects SET log_event_table = 'redcap_log_event; DROP TABLE x'
        WHERE project_id = 900");
    check('fence: a log table name that is not one is refused',
        \INSPIRE\UniversalValidator\Scan\SourceFence::resolveTable($dbA, 900) === null);
    // PHP's '$' also matches before a trailing newline; the anchor is '\z'.
    $A->query("UPDATE redcap_projects SET log_event_table = 'redcap_log_event\n'
        WHERE project_id = 900");
    check('fence: nor is one with a trailing newline',
        \INSPIRE\UniversalValidator\Scan\SourceFence::resolveTable($dbA, 900) === null);
    $A->query("UPDATE redcap_projects SET log_event_table = 'redcap_log_event7'
        WHERE project_id = 900");
    check('fence: a sharded log table is accepted',
        \INSPIRE\UniversalValidator\Scan\SourceFence::resolveTable($dbA, 900) === 'redcap_log_event7');

    // Log ids outgrow an int, and outgrow a float's exact range before that.
    check('fence: fences compare as numbers, not as strings',
        \INSPIRE\UniversalValidator\Scan\SourceFence::decCmp('9', '10') < 0 && \INSPIRE\UniversalValidator\Scan\SourceFence::decCmp('10', '9') > 0);
    check('fence: and stay exact past what a float can hold',
        \INSPIRE\UniversalValidator\Scan\SourceFence::decCmp('9007199254740993', '9007199254740992') > 0);
    check('fence: leading zeros are not a different number',
        \INSPIRE\UniversalValidator\Scan\SourceFence::decCmp('0042', '42') === 0);


    // -- planning: from a project to a frozen manifest -----------------------
    //
    // Run against the real record source and the real store, because planning is
    // almost entirely the joins between them - a planner tested against a fake
    // source would prove that the fake agrees with the planner.
    $A->query("UPDATE redcap_projects SET log_event_table = 'redcap_log_event'
        WHERE project_id = 900");
    $A->query('DELETE FROM redcap_record_list');
    for ($i = 1; $i <= 25; $i++) {
        $A->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES (900, '"
            . sprintf('P%03d', $i) . "', " . ($i % 3 === 0 ? '7' : 'NULL') . ')');
    }
    foreach (array('scan_record', 'finding', 'scan_run') as $t) {
        $A->query('DELETE FROM ' . Schema::table($t));
    }

    $store = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA);
    $planner = new \INSPIRE\UniversalValidator\Scan\ScanPlanner($store, 'test-secret-key');
    $srcP = \INSPIRE\UniversalValidator\Scan\RecordManifestSource::open($dbA, 900, array('pk' => 'record_id'));
    $fenceP = \INSPIRE\UniversalValidator\Scan\SourceFence::forProject($dbA, 900);
    $baseReq = array(
        'source' => $srcP['source'],
        'fence' => $fenceP['ok'] ? $fenceP['fence'] : null,
        'rules' => array(array('type' => 'required', 'fields' => array('dob'))),
        'settingsCount' => 0,
        'ownership' => array('dob' => 'demographics'),
        'structure' => array('longitudinal' => false),
        'choices' => array(),
        'policy' => \INSPIRE\UniversalValidator\Scan\ScanPolicy::resolve(),
        'engine' => '1.8.20',
        'createdBy' => 'alice',
        'pageSize' => 10,
    );

    $res = $planner->plan(900, $baseReq);
    check('plan: a project with records and rules plans', $res['ok'] === true);
    check('plan: every record reaches the manifest',
        (int) $res['run']['manifest_total'] === 25);
    check('plan: and the run is ready to be worked',
        $res['run']['phase'] === 'scanning');
    check('plan: the walk was paged rather than read whole', $res['stats']['pages'] >= 3);
    check('plan: the opening fence is captured BEFORE the manifest',
        $dbA->select('SELECT fence_open FROM ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run')
            . ' WHERE run_id = ?', array($res['run']['run_id']))[0][0] === '190');

    // The project slot is the mutual exclusion, and busy must disclose nothing.
    $busy = $planner->plan(900, $baseReq);
    check('plan: a second start is busy rather than a second run',
        $busy['ok'] === false && $busy['busy'] === true && $busy['run'] === null);
    check('plan: and names neither the owner nor any number',
        strpos($busy['why'], 'alice') === false && preg_match('/[0-9]/', $busy['why']) === 0);

    $rid = (int) $res['run']['run_id'];
    $fp1 = $res['run']['fingerprint'];
    $store->finish($rid, \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));

    // A group-scoped run must carry a group-scoped manifest. Building the whole
    // project and filtering at display time is the leak the persisted store
    // creates.
    $scoped = $planner->plan(900, array_merge($baseReq, array('dagFilter' => '7')));
    check('plan: a group-scoped run lists only that group',
        $scoped['ok'] === true && (int) $scoped['run']['manifest_total'] === 8);
    check('plan: and says how many records it left out',
        $scoped['stats']['outOfScope'] === 17);
    check('plan: the scope is stored on the run, not applied later',
        $scoped['run']['scope_dag'] === '7');
    $store->finish((int) $scoped['run']['run_id'],
        \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));

    // Editing a rule must change what a later run compares against.
    $moved = $planner->plan(900, array_merge($baseReq, array(
        'rules' => array(array('type' => 'required', 'fields' => array('dob'),
                               'when' => '[age] > 18')))));
    check('plan: changing a rule changes the run fingerprint',
        $moved['ok'] === true && $moved['run']['fingerprint'] !== $fp1);
    $store->finish((int) $moved['run']['run_id'],
        \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));

    // A planner that runs out of budget must finish its run terminally. An
    // abandoned run holds the project's scan slot and looks like one still
    // working, which is the worse of the two failures by a distance.
    $slow = $planner->plan(900, array_merge($baseReq,
        array('deadline' => microtime(true) - 1)));
    check('plan: running out of time refuses', $slow['ok'] === false && $slow['busy'] === false);
    check('plan: and says it was time rather than something unnamed',
        strpos($slow['why'], 'time this server allows') !== false);
    $left = $dbA->select('SELECT phase, terminal FROM ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run')
        . ' WHERE project_id = 900 AND active_slot = 1');
    check('plan: the abandoned run does not keep the project slot', $left === array());
    check('plan: so the next attempt is not told the project is busy',
        $planner->plan(900, $baseReq)['ok'] === true);
    $A->query('UPDATE ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run')
        . " SET active_slot = NULL, phase = 'terminal', terminal = 'cancelled'
            WHERE project_id = 900 AND active_slot = 1");

    // Records the server cannot tell apart are offered twice by the walk, on
    // purpose. They must land once.
    $A->query('DELETE FROM redcap_record_list');
    foreach (array('T1', 'T1 ', 'T1  ', 'T2') as $r) {
        $A->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES (900, '"
            . $r . "', NULL)");
    }
    $tie = $planner->plan(900, array_merge($baseReq, array('pageSize' => 1)));
    check('plan: a re-offered page boundary does not duplicate a record',
        $tie['ok'] === true && (int) $tie['run']['manifest_total'] === 4);
    check('plan: and the walk reported offering more than it stored',
        $tie['stats']['listed'] >= $tie['stats']['appended']);
    $store->finish((int) $tie['run']['run_id'],
        \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));

    // A project with no rules is not a project with nothing wrong.
    $A->query('DELETE FROM ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run'));
    $noRules = $planner->plan(900, array_merge($baseReq, array('rules' => array())));
    check('plan: a project with no rules is refused rather than certified',
        $noRules['ok'] === false
        && strpos($noRules['why'], 'no validation rules') !== false);
    check('plan: and no run was created for it',
        $dbA->select('SELECT COUNT(*) FROM ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run'))[0][0] === '0');


    // -- the worker, against the real store and the real fence ---------------
    //
    // The fast suite drives ScanWorker against the in-memory store, where the
    // fence is an array a test can move. What it cannot show is the worker
    // meeting a real transaction: the commit that rolls back, the claim that a
    // second connection cannot repeat, the version read that goes to a log
    // table. Those are here.
    $A->query('DELETE FROM redcap_record_list');
    for ($i = 1; $i <= 12; $i++) {
        $A->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES (900, 'W"
            . $i . "', NULL)");
    }
    $A->query('DELETE FROM redcap_log_event');
    for ($i = 1; $i <= 12; $i++) {
        $A->query('INSERT INTO redcap_log_event (log_event_id, project_id, pk, event) VALUES ('
            . (1000 + $i) . ", 900, 'W" . $i . "', 'UPDATE')");
    }
    foreach (array('scan_record', 'finding', 'scan_run') as $t) {
        $A->query('DELETE FROM ' . Schema::table($t));
    }

    $srcW = \INSPIRE\UniversalValidator\Scan\RecordManifestSource::open($dbA, 900, array('pk' => 'record_id'));
    $fenceW = \INSPIRE\UniversalValidator\Scan\SourceFence::forProject($dbA, 900);
    check('worker: the run it will work has a real fence', $fenceW['ok'] === true);
    $planned = $planner->plan(900, array_merge($baseReq, array(
        'source' => $srcW['source'], 'fence' => $fenceW['fence'], 'pageSize' => 5)));
    check('worker: and a manifest of every record',
        $planned['ok'] === true && (int) $planned['run']['manifest_total'] === 12);
    $wrid = (int) $planned['run']['run_id'];
    $gen = (int) $planned['run']['generation_id'];

    $readAll = function ($ids) {
        $out = array();
        foreach ($ids as $id) $out[$id] = array('1' => array('x' => ''));
        return array('ok' => true, 'data' => $out, 'why' => null);
    };
    $findOne = function ($id, $node) use ($gen) {
        return array('bytes' => 12, 'contexts' => 1, 'why' => null, 'findings' => array(array(
            'generation_id' => $gen, 'identity' => hash('sha256', 'w' . $id, true), 'seq' => 1,
            'record_hash' => hash('sha256', $id, true), 'record_id_bin' => $id,
            'instance' => 1, 'host_form' => 'f', 'field' => 'x', 'rule_source_id' => 'r1',
            'rule_revision' => str_repeat('c', 64), 'check_type' => 'required',
            'reason_code' => 'required-blank')));
    };
    $slots = new \INSPIRE\UniversalValidator\Scan\WorkerSlots($dbA);
    $A->query('DELETE FROM ' . Schema::table('scan_worker_slot'));
    $slots->provision(2);

    $worker = new \INSPIRE\UniversalValidator\Scan\ScanWorker($store, array(
        'slots' => $slots, 'fence' => $fenceW['fence'], 'read' => $readAll,
        'evaluate' => $findOne, 'owner' => 'browser-1', 'attempts' => 3,
        'budget' => new \INSPIRE\UniversalValidator\Scan\WorkBudget(array('mode' => 'cron', 'memoryLimit' => null,
            'timeLimit' => null, 'min' => 1, 'max' => 5, 'first' => 5,
            'startedAt' => microtime(true)))));
    $wres = $worker->work(900, $wrid);
    check('worker: every record is examined against a real store', $wres['worked'] === 12);
    check('worker: and every finding is stored', $wres['findings'] === 12);
    $stored = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE generation_id = ?', array($gen));
    check('worker: the findings are really in the table', (int) $stored[0][0] === 12);
    check('worker: the manifest is complete as a predicate over states',
        $store->manifestComplete($wrid) === true);
    check('worker: and progress equals the manifest, never more',
        (int) $store->run(900, $wrid)['manifest_done'] === 12);
    // The installation slot is a resource, not a lock the worker keeps.
    check('worker: the installation slot is handed back when the request ends',
        $slots->census()['held'] === 0);

    // A REAL EDIT DURING A REAL RUN. The record is touched in the log between
    // the worker's two version reads, which is the race the protocol exists for.
    foreach (array('scan_record', 'finding', 'scan_run') as $t) {
        $A->query('DELETE FROM ' . Schema::table($t));
    }
    $planned2 = $planner->plan(900, array_merge($baseReq, array(
        'source' => $srcW['source'], 'fence' => $fenceW['fence'], 'pageSize' => 20)));
    $wrid2 = (int) $planned2['run']['run_id'];
    $gen2 = (int) $planned2['run']['generation_id'];
    $touched = false;
    $readAndEdit = function ($ids) use (&$touched, $A, $readAll) {
        if (!$touched) {
            $touched = true;
            // Someone saves W3 while the export is running.
            $A->query("INSERT INTO redcap_log_event (log_event_id, project_id, pk, event)
                VALUES (2000, 900, 'W3', 'UPDATE')");
        }
        return $readAll($ids);
    };
    $findNone = function ($id, $node) {
        return array('bytes' => 0, 'contexts' => 1, 'why' => null, 'findings' => array());
    };
    $worker2 = new \INSPIRE\UniversalValidator\Scan\ScanWorker($store, array(
        'slots' => $slots, 'fence' => $fenceW['fence'], 'read' => $readAndEdit,
        'evaluate' => $findNone, 'owner' => 'browser-2', 'attempts' => 3,
        'budget' => new \INSPIRE\UniversalValidator\Scan\WorkBudget(array('mode' => 'cron', 'memoryLimit' => null,
            'timeLimit' => null, 'min' => 1, 'max' => 20, 'first' => 20,
            'startedAt' => microtime(true)))));
    $wres2 = $worker2->work(900, $wrid2);
    check('worker: a record saved during the export is requeued rather than reported',
        $wres2['requeued'] === 1);
    check('worker: and examined once it is stable', $wres2['worked'] === 12);
    check('worker: so the run still covers every record',
        $store->manifestComplete($wrid2) === true);
    $store->finish($wrid2, \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('fenced' => true, 'manifestDone' => true)));

    // A CANCEL FROM ANOTHER CONNECTION, arriving while this worker evaluates.
    // The epoch bump is what makes it beat the worker; nothing the worker had
    // buffered may reach the tables.
    foreach (array('scan_record', 'finding', 'scan_run') as $t) {
        $A->query('DELETE FROM ' . Schema::table($t));
    }
    $planned3 = $planner->plan(900, array_merge($baseReq, array(
        'source' => $srcW['source'], 'fence' => $fenceW['fence'], 'pageSize' => 20)));
    $wrid3 = (int) $planned3['run']['run_id'];
    $gen3 = (int) $planned3['run']['generation_id'];
    $storeB = new \INSPIRE\UniversalValidator\Scan\SqlScanStore(new MysqliDb($B));
    $cancelMidway = function ($id, $node) use ($storeB, $wrid3, $gen3) {
        // The SECOND connection cancels, exactly as an administrator's request
        // in another tab would.
        $storeB->cancel(900, $wrid3, 'admin');
        return array('bytes' => 1, 'contexts' => 1, 'why' => null, 'findings' => array(array(
            'generation_id' => $gen3, 'identity' => hash('sha256', 'c' . $id, true), 'seq' => 1,
            'record_hash' => hash('sha256', $id, true), 'record_id_bin' => $id,
            'instance' => 1, 'host_form' => 'f', 'field' => 'x', 'rule_source_id' => 'r1',
            'rule_revision' => str_repeat('c', 64), 'check_type' => 'required',
            'reason_code' => 'required-blank')));
    };
    $worker3 = new \INSPIRE\UniversalValidator\Scan\ScanWorker($store, array(
        'slots' => $slots, 'fence' => $fenceW['fence'], 'read' => $readAll,
        'evaluate' => $cancelMidway, 'owner' => 'browser-3', 'attempts' => 3,
        'budget' => new \INSPIRE\UniversalValidator\Scan\WorkBudget(array('mode' => 'cron', 'memoryLimit' => null,
            'timeLimit' => null, 'min' => 1, 'max' => 20, 'first' => 20,
            'startedAt' => microtime(true)))));
    $wres3 = $worker3->work(900, $wrid3);
    check('worker: a cancel from another connection stops the worker at its fence',
        $wres3['ok'] === false && $wres3['stop'] === 'fenced');
    $leftF = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE generation_id = ?', array($gen3));
    check('worker: and not one buffered finding reached the table',
        (int) $leftF[0][0] === 0);
    $leftD = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('scan_record')
        . ' WHERE run_id = ? AND state >= ?', array($wrid3, \INSPIRE\UniversalValidator\Scan\ScanStore::REC_DONE));
    check('worker: nor was any record marked as examined', (int) $leftD[0][0] === 0);
    check('worker: the slot is still returned when the worker stops early',
        $slots->census()['held'] === 0);
    $A->query('UPDATE ' . Schema::table('scan_run')
        . " SET active_slot = NULL, phase = 'terminal', terminal = 'cancelled'
            WHERE run_id = " . $wrid3);

    foreach (array('redcap_log_event', 'redcap_record_list', 'redcap_data',
                   'redcap_projects') as $t) {
        $A->query('DROP TABLE IF EXISTS ' . $t);
    }
}

// -- the unique finalizer: deciding duplicates without holding the project ----
//
// Uniqueness is the only check here that is a property of the whole project
// rather than of one record, so it is the only one that cannot be finished while
// scanning. Everything below is about the two ways that goes wrong: deciding
// something the module cannot actually prove, and holding a group in memory.
{
    $dbA = new MysqliDb($A);
    $KEY = 'finalizer-test-key';
    $GEN = 4242;
    foreach (array('finding', 'unique_candidate', 'unique_group') as $t) {
        $A->query('DELETE FROM ' . Schema::table($t));
    }

    // Candidates as the worker writes them: a keyed group hash and a location,
    // never the value. Written with interpolated TEST-CONTROLLED literals and
    // UNHEX for the binary columns rather than through bind_param: this file has
    // already lost an afternoon to a type string that did not match its variable
    // count, and a fixture helper is not where that risk belongs.
    $put = function ($group, $rec, $field, $version = 'null') use ($A, $GEN, $KEY) {
        $g = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_UNIQUE, 900, $group, $KEY));
        $h = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_RECORD, 900, $rec, $KEY));
        $v = ($version === 'null') ? 'NULL' : ("'" . $version . "'");
        $A->query('INSERT INTO ' . Schema::table('unique_candidate') . "
            (generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
             record_hash, record_id_bin, event_id, instance, host_form, field, version_scanned)
            VALUES (" . $GEN . ", 'r1', '" . str_repeat('c', 64) . "', UNHEX('" . $g . "'),
                    'project', UNHEX('" . $h . "'), '" . $rec . "', 1, 1, 'f', '" . $field . "', " . $v . ")");
    };

    // Two records sharing a value, three sharing another, one on its own.
    $put('AB12', 'R1', 'hospno');
    $put('AB12', 'R2', 'hospno');
    $put('CD34', 'R3', 'hospno');
    $put('CD34', 'R4', 'hospno');
    $put('CD34', 'R5', 'hospno');
    $put('EF56', 'R6', 'hospno');

    // The re-read the finalizer verifies against. Every record in a group really
    // does hold the same value here.
    $truth = array('R1' => 'AB12', 'R2' => 'AB12', 'R3' => 'CD34', 'R4' => 'CD34',
                   'R5' => 'CD34', 'R6' => 'EF56');
    $reader = function ($locs) use (&$truth) {
        $out = array();
        foreach ($locs as $l) {
            if (!isset($truth[$l['record']])) continue;
            $out[\INSPIRE\UniversalValidator\Scan\UniqueFinalizer::locKey($l)] = array($truth[$l['record']]);
        }
        return array('ok' => true, 'values' => $out, 'why' => null);
    };

    $fin = new \INSPIRE\UniversalValidator\Scan\UniqueFinalizer($dbA, array('pid' => 900, 'hmacKey' => $KEY, 'read' => $reader));

    check('unique: nothing is settled before anything has run',
        $fin->status($GEN)['done'] === true && $fin->status($GEN)['groups'] === 0);

    $made = $fin->discover($GEN, 100);
    check('unique: every candidate group is discovered', $made === 3);
    $st = $fin->status($GEN);
    check('unique: a group with one record in it is settled without being work',
        $st['groups'] === 3 && $st['pending'] === 2);

    // Drive it to completion one bounded step at a time, exactly as a request
    // with a budget would.
    $steps = 0;
    while ($steps++ < 100) {
        $r = $fin->step($GEN, 2);
        if ($r['done']) break;
    }
    check('unique: finalization completes in bounded steps', $steps < 100);
    $st = $fin->status($GEN);
    check('unique: both real groups are published',
        $st['done'] === true && $st['published'] === 2 && $st['blocking'] === 0);

    $f = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE generation_id = ? AND reason_code = ?', array($GEN, 'duplicate'));
    check('unique: one finding for every record in a duplicate group',
        (int) $f[0][0] === 5);
    $active = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE generation_id = ? AND active_slot = 1', array($GEN));
    check('unique: and all of them are visible once their group is published',
        (int) $active[0][0] === 5);
    $lonely = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . " WHERE generation_id = ? AND record_id_bin = 'R6'", array($GEN));
    check('unique: a record whose value nobody shares is not reported',
        (int) $lonely[0][0] === 0);

    // A RETRIED PAGE MUST NOT DOUBLE THE REPORT. The staged rows are keyed by
    // identity within their epoch, which is a key the active-identity one cannot
    // supply: a staged row has no active slot, and MySQL counts every NULL in a
    // unique index as distinct.
    $A->query('UPDATE ' . Schema::table('unique_group')
        . " SET phase = 'emitting', emit_cursor = 0 WHERE generation_id = " . $GEN
        . " AND phase = 'published'");
    $again = 0;
    while ($again++ < 50) {
        $r = $fin->step($GEN, 2);
        if ($r['done']) break;
    }
    $f2 = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE generation_id = ? AND reason_code = ?', array($GEN, 'duplicate'));
    check('unique: re-emitting a group writes the same rows rather than a second set',
        (int) $f2[0][0] === 5);

    // -- two different values under one hash ---------------------------------
    //
    // Not producible by data entry, and checked anyway: the alternative to
    // checking is ASSERTING that two participants share a hospital number.
    // Partitioning the group by value would be the tempting response and would
    // turn a hash failure into a confident wrong report.
    $GEN2 = 4243;
    $gen2 = $GEN2;
    $put2 = function ($group, $rec) use ($A, $gen2, $KEY) {
        $g = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_UNIQUE, 900, $group, $KEY));
        $h = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_RECORD, 900, $rec, $KEY));
        $A->query('INSERT INTO ' . Schema::table('unique_candidate') . "
            (generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
             record_hash, record_id_bin, event_id, instance, host_form, field, version_scanned)
            VALUES (" . $gen2 . ", 'r1', '" . str_repeat('c', 64) . "', UNHEX('" . $g . "'),
                    'project', UNHEX('" . $h . "'), '" . $rec . "', 1, 1, 'f', 'hospno', NULL)");
    };
    $put2('SAME', 'X1');
    $put2('SAME', 'X2');
    $liar = function ($locs) {
        $out = array();
        foreach ($locs as $l) {
            // X1 and X2 landed in one group, and their values disagree.
            $out[\INSPIRE\UniversalValidator\Scan\UniqueFinalizer::locKey($l)] = array($l['record'] === 'X1' ? 'one' : 'two');
        }
        return array('ok' => true, 'values' => $out, 'why' => null);
    };
    $fin2 = new \INSPIRE\UniversalValidator\Scan\UniqueFinalizer($dbA, array('pid' => 900, 'hmacKey' => $KEY, 'read' => $liar));
    $n2 = 0;
    while ($n2++ < 50) {
        $r = $fin2->step($GEN2, 10);
        if ($r['done']) break;
    }
    $st2 = $fin2->status($GEN2);
    check('unique: a group whose values disagree is marked undecidable',
        $st2['blocking'] === 1 && $st2['published'] === 0);
    check('unique: and finalization still settles rather than looping',
        $st2['done'] === true);
    $f3 = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE generation_id = ?', array($GEN2));
    check('unique: no duplicate verdict is emitted for a group it could not decide',
        (int) $f3[0][0] === 0);

    // A reader that cannot answer is not evidence of anything either.
    $GEN3 = 4244;
    $gen3 = $GEN3;
    $put3 = function ($group, $rec) use ($A, $gen3, $KEY) {
        $g = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_UNIQUE, 900, $group, $KEY));
        $h = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_RECORD, 900, $rec, $KEY));
        $A->query('INSERT INTO ' . Schema::table('unique_candidate') . "
            (generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
             record_hash, record_id_bin, event_id, instance, host_form, field, version_scanned)
            VALUES (" . $gen3 . ", 'r1', '" . str_repeat('c', 64) . "', UNHEX('" . $g . "'),
                    'project', UNHEX('" . $h . "'), '" . $rec . "', 1, 1, 'f', 'hospno', NULL)");
    };
    $put3('Q', 'Y1');
    $put3('Q', 'Y2');
    $broken = function ($locs) {
        return array('ok' => false, 'values' => array(), 'why' => 'the export timed out');
    };
    $fin3 = new \INSPIRE\UniversalValidator\Scan\UniqueFinalizer($dbA, array('pid' => 900, 'hmacKey' => $KEY, 'read' => $broken));
    $n3 = 0;
    while ($n3++ < 50) {
        $r = $fin3->step($GEN3, 10);
        if ($r['done']) break;
    }
    check('unique: values that could not be re-read block the group rather than confirming it',
        $fin3->status($GEN3)['blocking'] === 1);
    $f4 = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE generation_id = ?', array($GEN3));
    check('unique: and nothing is reported about it', (int) $f4[0][0] === 0);

    // -- a record edited while its group was being decided --------------------
    $GEN4 = 4245;
    $gen4 = $GEN4;
    $put4 = function ($group, $rec, $ver) use ($A, $gen4, $KEY) {
        $g = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_UNIQUE, 900, $group, $KEY));
        $h = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_RECORD, 900, $rec, $KEY));
        $A->query('INSERT INTO ' . Schema::table('unique_candidate') . "
            (generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
             record_hash, record_id_bin, event_id, instance, host_form, field, version_scanned)
            VALUES (" . $gen4 . ", 'r1', '" . str_repeat('c', 64) . "', UNHEX('" . $g . "'),
                    'project', UNHEX('" . $h . "'), '" . $rec . "', 1, 1, 'f', 'hospno', '" . $ver . "')");
    };
    $put4('M', 'Z1', '100');
    $put4('M', 'Z2', '100');
    $moving = new MovingVersions();
    $moving->v = array('Z1' => '100', 'Z2' => '999');   // Z2 changed since it was scanned
    $fin4 = new \INSPIRE\UniversalValidator\Scan\UniqueFinalizer($dbA, array('pid' => 900, 'hmacKey' => $KEY,
        'read' => $reader, 'versions' => $moving));
    $fin4->discover($GEN4, 10);
    $before = $dbA->select('SELECT candidate_epoch FROM ' . Schema::table('unique_group')
        . ' WHERE generation_id = ?', array($GEN4));
    $fin4->step($GEN4, 10);
    $afterE = $dbA->select('SELECT candidate_epoch, phase, verify_cursor FROM '
        . Schema::table('unique_group') . ' WHERE generation_id = ?', array($GEN4));
    check('unique: a record edited mid-check restarts its group at a new epoch',
        (int) $afterE[0][0] === (int) $before[0][0] + 1);
    check('unique: from the beginning, not from where it stopped',
        (int) $afterE[0][2] === 0 && $afterE[0][1] === 'new');

    // Staged rows from an abandoned attempt are unreachable and swept in pages.
    $A->query('INSERT INTO ' . Schema::table('finding') . '
        (generation_id, finding_identity, valid_from_seq, active_slot, record_hash,
         record_id_bin, host_form, field, rule_source_id, rule_revision, rule_ord,
         check_type, reason_code, group_hmac, stage_epoch)
        SELECT ' . $GEN4 . ", UNHEX(SHA2('stale', 256)), 1, NULL, record_hash, record_id_bin,
               'f', 'hospno', 'r1', '" . str_repeat('c', 64) . "', 0, 'unique', 'duplicate',
               group_hmac, 1
        FROM " . Schema::table('unique_candidate') . ' WHERE generation_id = ' . $GEN4 . ' LIMIT 1');
    check('unique: rows from an abandoned pass are swept', $fin4->sweep($GEN4, 100) === 1);
    check('unique: and sweeping again finds nothing', $fin4->sweep($GEN4, 100) === 0);

    // -- the group that holds the whole project -------------------------------
    //
    // A rule on a field where every record holds the same value puts every
    // record in ONE group. The property that matters is that the memory this
    // costs does not grow with the group, so it is measured against a small
    // group rather than asserted.
    $BIG = 4250; $SMALL = 4251;
    $bulk = function ($gen, $n) use ($A, $KEY) {
        $rows = array();
        for ($i = 1; $i <= $n; $i++) {
            $g = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_UNIQUE, 900, 'ONE', $KEY));
            $h = bin2hex(\INSPIRE\UniversalValidator\Scan\Hmac::raw(\INSPIRE\UniversalValidator\Scan\Hmac::P_RECORD, 900, 'B' . $i, $KEY));
            $rows[] = "(" . $gen . ", 'r1', '" . str_repeat('c', 64) . "', UNHEX('" . $g
                . "'), 'project', UNHEX('" . $h . "'), 'B" . $i . "', 1, 1, 'f', 'hospno', NULL)";
            if (count($rows) >= 500) {
                $A->query('INSERT INTO ' . Schema::table('unique_candidate') . '
                    (generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
                     record_hash, record_id_bin, event_id, instance, host_form, field,
                     version_scanned) VALUES ' . implode(',', $rows));
                $rows = array();
            }
        }
        if ($rows) {
            $A->query('INSERT INTO ' . Schema::table('unique_candidate') . '
                (generation_id, rule_source_id, rule_revision, group_hmac, scope_key,
                 record_hash, record_id_bin, event_id, instance, host_form, field,
                 version_scanned) VALUES ' . implode(',', $rows));
        }
    };
    $same = function ($locs) {
        $out = array();
        foreach ($locs as $l) $out[\INSPIRE\UniversalValidator\Scan\UniqueFinalizer::locKey($l)] = array('ONE');
        return array('ok' => true, 'values' => $out, 'why' => null);
    };
    $bulk($SMALL, 50);
    $bulk($BIG, 20000);

    $finS = new \INSPIRE\UniversalValidator\Scan\UniqueFinalizer($dbA, array('pid' => 900, 'hmacKey' => $KEY, 'read' => $same));
    $m0 = memory_get_usage(true);
    $k = 0; while ($k++ < 200) { if ($finS->step($SMALL, 500)['done']) break; }
    $smallPeak = memory_get_usage(true) - $m0;

    $finB = new \INSPIRE\UniversalValidator\Scan\UniqueFinalizer($dbA, array('pid' => 900, 'hmacKey' => $KEY, 'read' => $same));
    $m1 = memory_get_usage(true);
    $k = 0; while ($k++ < 2000) { if ($finB->step($BIG, 500)['done']) break; }
    $bigPeak = memory_get_usage(true) - $m1;

    check('unique: a 20,000-record group finalizes', $finB->status($BIG)['done'] === true
        && $finB->status($BIG)['published'] === 1);
    $bigF = $dbA->select('SELECT COUNT(*) FROM ' . Schema::table('finding')
        . ' WHERE generation_id = ?', array($BIG));
    check('unique: reporting every record in it', (int) $bigF[0][0] === 20000);
    // 400x the candidates. If anything accumulated a group, this is where it
    // would show; the page size is the only thing that sets the footprint.
    check('unique: and costs no more memory than a group 400 times smaller',
        $bigPeak <= $smallPeak + (4 * 1024 * 1024));

    foreach (array('finding', 'unique_candidate', 'unique_group') as $t) {
        $A->query('DELETE FROM ' . Schema::table($t));
    }
}

// -- teardown: exactly our tables, nothing else ------------------------------
foreach (array_reverse(Schema::tables()) as $t) $A->query('DROP TABLE IF EXISTS ' . $t);

echo "scan_store_mysql: $n checks, $fail failure(s)\n";
exit($fail ? 1 : 0);
