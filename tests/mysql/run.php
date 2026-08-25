<?php
/**
 * tests/mysql/run.php — the invariants only a real database can prove.
 *
 * WHY THIS SUITE EXISTS SEPARATELY. Every other suite in this repository runs
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
 * WHY THIS FILE IS NOW A RUNNER. It used to be all 1746 lines of that, and the
 * durable-scan remediation has thirteen work items that each need to edit it.
 * A single file wanted by everyone is not a suite, it is a queue. The assertions
 * live in tests/mysql/cases/*.php now, one file per area, and this file decides
 * only the order, the schema and what happens when a case falls over.
 *
 * Run locally against any MySQL/MariaDB:
 *   UV_DB_HOST=127.0.0.1 UV_DB_USER=root UV_DB_PASS=root UV_DB_NAME=uv_test \
 *     php tests/mysql/run.php
 *
 * If anyone else might be using that server, mint a private schema instead and
 * this run will neither see nor drop their tables:
 *   UV_DB_SCHEMA_PREFIX=uv_mine php tests/mysql/run.php
 *
 * In CI it runs once per service in .github/workflows/scan-database.yml.
 * It creates only tables carrying the module's own prefix and the REDCap-shaped
 * stand-ins the walk needs, and drops exactly those at the end — never the
 * schema it was given, never anything it did not create.
 */

require_once __DIR__ . '/support/bootstrap.php';
require_once __DIR__ . '/support/fixture.php';

use INSPIRE\UniversalValidator\Scan\Schema;

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

try {
    $A = uv_connect($host, $user, $pass, $name, $port);
} catch (\Throwable $e) {
    fwrite(STDERR, "could not connect: " . $e->getMessage() . "\n");
    exit(2);
}

// The schema is a parameter, and it may be one this run mints for itself. See
// uv_resolve_schema(): three agents sharing uv_test destroyed two measurement
// passes during the remediation audit, and neither of them could tell a real
// red from someone else's teardown.
$schema = uv_resolve_schema($A, $name);
$A->select_db($schema['name']);

try {
    $B = uv_connect($host, $user, $pass, $schema['name'], $port);
} catch (\Throwable $e) {
    fwrite(STDERR, "could not open a second connection: " . $e->getMessage() . "\n");
    exit(2);
}

echo 'server: ' . $A->server_info . "\n";
echo 'schema: ' . $schema['name'] . ($schema['created'] ? " (created for this run)\n" : "\n");

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

$ca = new Conn($A);
$cb = new Conn($B);

/**
 * THE ORDER IS DELIBERATE BUT NOT LOAD-BEARING.
 *
 * Each case gets a freshly migrated schema, so no case may depend on what
 * another left behind. The order below is the order a reader wants: what the
 * storage engine guarantees, then the store built on it, then the classes built
 * on the store. If a case ever starts needing to run after another one, that is
 * a shared-state bug in the case, not a scheduling requirement for this list.
 */
$cases = array(
    'schema',       // the engine's own guarantees: the migration and four UNIQUE keys
    'store',        // SqlScanStore end to end, and the cross-store contract
    'slots',        // the installation-wide worker semaphore
    'retention',    // three clocks, and nothing silently losing a finding
    'fault',        // what the store does when the server says no
    'walk',         // RecordManifestSource over REDCap-shaped tables
    'fence',        // SourceFence: versions, retention of the log, catch-up paging
    'planning',     // ScanPlanner: a project to a frozen manifest
    'worker',       // ScanWorker against the real store and a real fence
    'uniqueness',   // UniqueFinalizer: duplicates without holding the project
    'rollup',       // RollupBuilder: the summary and the two ways it lies
    'promotion',    // ScanPromotion: which terminal state a run has earned
);

$only = getenv('UV_CASES');
if ($only !== false && $only !== '') {
    $want = array_filter(array_map('trim', explode(',', $only)));
    $cases = array_values(array_intersect($cases, $want));
    echo 'cases: ' . implode(', ', $cases) . " (UV_CASES)\n";
}

$ran = 0;
foreach ($cases as $case) {
    $path = __DIR__ . '/cases/' . $case . '.php';
    if (!is_file($path)) {
        check('runner: the case file ' . $case . '.php exists', false);
        continue;
    }

    // A SCHEMA NO EARLIER CASE HAS TOUCHED. The old file truncated a hand-kept
    // list of tables between sections, and the list was never complete - so a
    // second run of the suite in the same database inherited the first run's
    // rows and the expected values quietly shifted.
    if (!uv_reset_schema($A, $ca)) {
        check('runner: ' . $case . ' starts on a freshly migrated schema', false);
        continue;
    }

    // A CASE THAT FALLS OVER FAILS ONE CHECK, NOT ALL THE REMAINING ONES. In the
    // single-file suite an uncaught throw - a mysqli error, a bad bind arity -
    // killed the process where it stood, and every assertion after it went
    // unrun while the summary line was never printed at all. Twice that cost a
    // CI round trip to even identify.
    $ok = true; $why = '';
    try {
        uv_run_case($path, array(
            'A' => $A, 'B' => $B, 'ca' => $ca, 'cb' => $cb,
            'dbA' => new MysqliDb($A), 'dbB' => new MysqliDb($B),
        ));
    } catch (\Throwable $e) {
        $ok = false;
        $why = get_class($e) . ': ' . $e->getMessage()
             . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        // A case that threw inside commitBatch left a transaction open on one
        // of these connections. MySQL would commit it implicitly at the next
        // DROP TABLE, which is a quiet way for a failed case to write half a
        // batch into the schema the next case is about to be handed.
        foreach (array($A, $B) as $conn) {
            try { $conn->query('ROLLBACK'); } catch (\Throwable $ignored) { }
        }
    }
    check('runner: the ' . $case . ' case ran to the end', $ok);
    if (!$ok) fwrite(STDERR, '  ' . $case . ' threw ' . $why . "\n");
    $ran++;
}

check('runner: every case listed was found and executed', $ran === count($cases));

// -- teardown: exactly our tables, nothing else ------------------------------
uv_drop_everything($A);
if ($schema['created']) {
    // Only a schema this run minted. One it merely found belongs to whoever
    // made it, and dropping that is the failure this parameter exists to stop.
    $A->query('DROP DATABASE IF EXISTS `' . $schema['name'] . '`');
}

echo "scan_store_mysql: $n checks, $fail failure(s)\n";
exit($fail ? 1 : 0);
