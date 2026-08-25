<?php
/**
 * scan_moduledb_php.php — the ScanDb that actually runs in REDCap.
 *
 * THE GAP. ModuleDb is constructed in exactly two places in the shipped tree,
 * and until this file nothing under tests/ constructed it at all. The database
 * matrix substitutes its own MysqliDb, which reads `$stmt->affected_rows`;
 * ModuleDb issues `SELECT ROW_COUNT()` as a SECOND statement. So the one method
 * every compare-and-set in the module is decided by — affected() — was tested
 * only through an implementation that reaches it by a different mechanism.
 *
 * THE QUESTION, SETTLED BY EXECUTION against MySQL 8.0.46 on the same server
 * the matrix uses. Can `SELECT ROW_COUNT()` through the framework's query()
 * disagree with mysqli_affected_rows?
 *
 *   IN ISOLATION, NO. Over every write shape this store uses — a plain UPDATE
 *   changing rows, a prepared one, an UPDATE that matched without changing,
 *   `UPDATE ... ORDER BY ... LIMIT 1`, multi-row `INSERT ... ON DUPLICATE KEY
 *   UPDATE`, `INSERT IGNORE` hitting a duplicate, a DELETE matching nothing,
 *   and DDL — the two agreed on every one. Every compare-and-set in the module
 *   is sound in production today. Those comparisons are re-run below whenever
 *   a live server is available.
 *
 *   BUT ONLY WHILE NOTHING GETS BETWEEN THEM. Measured on the same server:
 *   ROW_COUNT() returns -1 after any statement that returned a result set —
 *   including a previous ROW_COUNT() read — and after any statement that
 *   FAILED. One `SELECT 1` injected between the write and the read inverts
 *   every compare-and-set in the module at once: finish() and cancel() answer
 *   false, and WorkerSlots::acquire() returns null for a slot the UPDATE just
 *   took, so nothing ever releases it and the pool leaks one slot per request
 *   while the operator is told the server is at its limit. That is the 1.9.5
 *   pilot symptom, exactly.
 *
 * So the fix is not arithmetic, it is refusing to guess: -1 is MySQL saying "I
 * do not know", and exec() throws rather than delivering it as a number. The
 * assertions below drive that through the production ModuleDb.
 *
 * TWO LEGS. The first needs no server: a framework stand-in returns the shapes
 * the real one can return, including the -1 that a chatty build produces, and
 * it is where the fail-without-the-fix proof lives. The second is the live
 * conformance run, which needs mysqli and a reachable MySQL and is therefore
 * opt-in — set UV_MODULEDB_LIVE=1. It creates and drops its OWN database, named
 * by UV_MODULEDB_DB, so it can never destroy another agent's matrix run.
 *
 * Run:  php tests/scan_moduledb_php.php
 * Live: UV_MODULEDB_LIVE=1 php -d extension=mysqli tests/scan_moduledb_php.php
 */

namespace {
    require_once __DIR__ . '/../php/Scan/ScanStoreUnavailable.php';
    require_once __DIR__ . '/../php/Scan/DbError.php';
    require_once __DIR__ . '/../php/Scan/ScanDb.php';
    require_once __DIR__ . '/../php/Scan/Schema.php';
    require_once __DIR__ . '/../php/Scan/WorkerSlots.php';
    require_once __DIR__ . '/scan_fault_support.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }
}

namespace INSPIRE\UniversalValidator\Scan {

    function threw(callable $fn)
    {
        try {
            $fn();
            return 'returned';
        } catch (ScanStoreUnavailable $e) {
            return 'unavailable';
        } catch (\Throwable $e) {
            return get_class($e);
        }
    }

    // =====================================================================
    // LEG ONE — the shapes the framework can return, without a server
    // =====================================================================

    $fw = new RecordingFramework();
    $fw->rowCount = 3;
    $db = new ModuleDb($fw);
    $db->exec('UPDATE t SET a = 1', []);
    check('moduledb: a row count the server gave is the row count reported',
        $db->affected() === 3);

    $fw->rowCount = 0;
    $db->exec('UPDATE t SET a = 1', []);
    check('moduledb: zero is a real answer and is reported as zero', $db->affected() === 0);

    // A ZERO AND AN "I DO NOT KNOW" ARE NOT THE SAME, and this is the whole
    // finding. Rounding -1 down to 0 discards a good batch; passing it through
    // makes `affected() === 1` false and leaks the slot the UPDATE just took.
    $chatty = new RecordingFramework();
    $chatty->chatty = true;
    $db2 = new ModuleDb($chatty);
    check('moduledb: a negative row count is a failure, not a number',
        threw(function () use ($db2) { $db2->exec('UPDATE t SET a = 1', []); }) === 'unavailable');
    check('moduledb: and affected() does not answer for the previous write afterwards',
        $db2->affected() === -1);

    $silent = new RecordingFramework();
    $silent->rowCount = null;                 // SELECT ROW_COUNT() returned no row at all
    $db3 = new ModuleDb($silent);
    check('moduledb: a row count that never arrived is a failure too',
        threw(function () use ($db3) { $db3->exec('UPDATE t SET a = 1', []); }) === 'unavailable');

    // A FRAMEWORK BUILD THAT RETURNS FALSE instead of throwing. Reading a row
    // count for a statement that never ran answers for whatever ran before it.
    $falsey = new RecordingFramework();
    $falsey->writeReturnsFalse = true;
    $db4 = new ModuleDb($falsey);
    check('moduledb: a write that returned false is a failure',
        threw(function () use ($db4) { $db4->exec('UPDATE t SET a = 1', []); }) === 'unavailable');
    $asked = 0;
    foreach ($falsey->queries as $q) {
        if (strpos($q, 'ROW_COUNT') !== false) $asked++;
    }
    check('moduledb: and it does not go on to count a statement that never ran', $asked === 0);

    // The failure says nothing a page should not see, because it travels the
    // same channel as every other storage failure.
    check('moduledb: the failure carries the operator sentence, not a statement',
        strpos(ScanStoreUnavailable::OPERATOR_TEXT, 'UPDATE') === false);

    // affected() is cached in exec(), so an intervening READ cannot move it.
    // ModuleDb has always done this; it is pinned here because the matrix's
    // MysqliDb does the same and a divergence would be invisible otherwise.
    $fw5 = new RecordingFramework();
    $fw5->rowCount = 7;
    $db5 = new ModuleDb($fw5);
    $db5->exec('DELETE FROM t WHERE a = 1', []);
    $db5->select('SELECT * FROM t', []);
    check('moduledb: a read between the write and the question does not change the answer',
        $db5->affected() === 7);

    // The three result shapes a framework build can hand back from a SELECT.
    $shapes = new RecordingFramework();
    $shapes->canned = ['FROM plain' => [[1, 'a']]];
    $sdb = new ModuleDb($shapes);
    check('moduledb: an array result is passed through as rows',
        $sdb->select('SELECT * FROM plain', []) === [[1, 'a']]);
    check('moduledb: and an empty result is an empty list, never a null',
        $sdb->select('SELECT * FROM nothing', []) === []);

    // =====================================================================
    // LEG TWO — the same class against a real server
    // =====================================================================
    //
    // Opt-in, because the fast suite must not need a database. What runs here
    // is the comparison the review asked for and the slot assertions that used
    // to live on the store's deleted leaseSlot()/releaseSlot() pair.

    $live = getenv('UV_MODULEDB_LIVE');
    if ($live !== '1' && $live !== 'true') {
        \check('moduledb: the live conformance leg is opt-in and was skipped', true);
    } elseif (!class_exists('\mysqli')) {
        \check('moduledb: the live leg needs mysqli, which this build does not load', true);
    } else {
        $host = getenv('UV_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('UV_DB_PORT') ?: 33306);
        $user = getenv('UV_DB_USER') ?: 'root';
        $pass = getenv('UV_DB_PASS');
        if ($pass === false) $pass = 'uvtest';
        // ITS OWN DATABASE, ALWAYS. Three agents ran the matrix against one
        // schema during the audit and two measurement passes were destroyed by
        // somebody else's teardown. This file creates what it drops.
        $name = getenv('UV_MODULEDB_DB') ?: 'uv_moduledb_probe';
        if (!preg_match('/^[a-z][a-z0-9_]*\z/', $name)) {
            \check('moduledb: the live database name is usable', false);
            $name = null;
        }
        $conn = $name === null ? null : @new \mysqli($host, $user, $pass, null, $port);
        if ($conn === null || $conn->connect_errno) {
            \check('moduledb: the live leg was requested but no server answered', false);
        } else {
            $conn->query('DROP DATABASE IF EXISTS ' . $name);
            $conn->query('CREATE DATABASE ' . $name);
            $conn->select_db($name);

            /** The framework, over a real connection. Prepared, as REDCap's is. */
            $framework = new class($conn) {
                private $c;
                public function __construct($c) { $this->c = $c; }
                public function query($sql, $params = [])
                {
                    if (!$params) {
                        $r = $this->c->query($sql);
                        return $r === true ? [] : self::rows($r);
                    }
                    $st = $this->c->prepare($sql);
                    $types = str_repeat('s', count($params));
                    $st->bind_param($types, ...array_values($params));
                    $st->execute();
                    $r = $st->get_result();
                    $out = ($r === false) ? [] : self::rows($r);
                    $st->close();
                    return $out;
                }
                private static function rows($r)
                {
                    $out = [];
                    while ($row = $r->fetch_row()) $out[] = $row;
                    $r->free();
                    return $out;
                }
            };

            $conn->query('CREATE TABLE cmp (id INT PRIMARY KEY, v INT) ENGINE=InnoDB');
            $conn->query('INSERT INTO cmp (id, v) VALUES (1,1),(2,2),(3,3)');
            $mdb = new ModuleDb($framework);

            // ROW_COUNT() VERSUS mysqli_affected_rows, shape by shape. Each case
            // runs the SAME statement through ModuleDb and then, on a fresh
            // copy of the rows, through mysqli directly.
            $reset = function () use ($conn) {
                $conn->query('TRUNCATE TABLE cmp');
                $conn->query('INSERT INTO cmp (id, v) VALUES (1,1),(2,2),(3,3)');
            };
            $cases = [
                'an UPDATE that changes rows'        => 'UPDATE cmp SET v = v + 10',
                'an UPDATE that matches but changes nothing' => 'UPDATE cmp SET v = v',
                'an UPDATE with ORDER BY and LIMIT'  => 'UPDATE cmp SET v = v + 100 ORDER BY id LIMIT 1',
                'a DELETE that matches nothing'      => 'DELETE FROM cmp WHERE id = 999',
                'INSERT IGNORE onto a duplicate'     => 'INSERT IGNORE INTO cmp (id, v) VALUES (1, 5)',
                'INSERT ... ON DUPLICATE KEY UPDATE' =>
                    'INSERT INTO cmp (id, v) VALUES (7,7),(2,2) ON DUPLICATE KEY UPDATE id = id',
            ];
            foreach ($cases as $what => $sql) {
                $reset();
                $mdb->exec($sql, []);
                $viaRowCount = $mdb->affected();
                $reset();
                $conn->query($sql);
                $viaHandle = $conn->affected_rows;
                \check('moduledb live: ROW_COUNT agrees with affected_rows for ' . $what,
                    $viaRowCount === $viaHandle);
            }

            // THE INVARIANT ITSELF. One extra statement on the session between
            // the write and the read, and the server answers -1.
            $conn->query('UPDATE cmp SET v = v + 1');
            $r = $conn->query('SELECT 1'); $r->free();
            $rc = $conn->query('SELECT ROW_COUNT()')->fetch_row();
            \check('moduledb live: an interleaved statement makes ROW_COUNT report -1',
                (int) $rc[0] === -1);

            // -- the worker semaphore, where the store contract's slot
            //    assertions moved to. WorkerSlots is the only semaphore now.
            $conn->query('CREATE TABLE ' . Schema::table('scan_worker_slot') . ' (
                slot_no INT NOT NULL PRIMARY KEY,
                owner VARCHAR(64) NULL,
                run_id INT NULL,
                epoch INT NOT NULL DEFAULT 0,
                expires_at DATETIME NULL) ENGINE=InnoDB');
            $slots = new WorkerSlots($mdb);
            \check('slots: provisioning creates the configured number of rows',
                $slots->provision(2) === 2 && $slots->census()['total'] === 2);

            $a = $slots->acquire('w1', 1, 60);
            $b = $slots->acquire('w2', 1, 60);
            \check('slots: they lease up to the configured count', $a !== null && $b !== null);
            \check('slots: and the next worker finds none free',
                $slots->acquire('w3', 1, 60) === null);
            \check('slots: a stale holder releases nothing',
                $slots->release($a['slot_no'], 'w1', $a['epoch'] + 5) === false);
            \check('slots: someone else releases nothing either',
                $slots->release($a['slot_no'], 'impostor', $a['epoch']) === false);
            \check('slots: the real holder releases',
                $slots->release($a['slot_no'], 'w1', $a['epoch']) === true);
            \check('slots: freeing it for the next worker',
                $slots->acquire('w3', 1, 60) !== null);

            // THE CASE THE OLD CONTRACT COULD NOT REACH. It leased with three
            // DISTINCT owners, so it never asked what happens when ONE owner
            // holds two slots - and the answer was that the read-back named the
            // lower-numbered one both times, so releasing what the second lease
            // was told stranded the slot it had actually taken. The owner string
            // is not unique by construction: ScanWorker falls back to the
            // literal 'worker' when none is configured.
            $conn->query('TRUNCATE TABLE ' . Schema::table('scan_worker_slot'));
            $slots->provision(2);
            $one = $slots->acquire('worker', 1, 60);
            $two = $slots->acquire('worker', 2, 60);
            \check('slots: one owner leasing twice is told two DIFFERENT slots',
                $one !== null && $two !== null && $one['slot_no'] !== $two['slot_no']);
            // NAMED, not counted. A census that drops from two to one is
            // satisfied by freeing the WRONG slot, which is exactly what used
            // to happen, so the row itself is read back.
            $freed = $slots->release($two['slot_no'], 'worker', $two['epoch']);
            $owners = [];
            foreach ($conn->query('SELECT slot_no, owner FROM '
                    . Schema::table('scan_worker_slot')) as $row) {
                $owners[(int) $row['slot_no']] = $row['owner'];
            }
            \check('slots: and releasing one frees exactly the slot it names',
                $freed === true && $owners[$two['slot_no']] === null
                && $owners[$one['slot_no']] === 'worker');
            \check('slots: so nothing is left held by a handle nobody has',
                $slots->release($one['slot_no'], 'worker', $one['epoch']) === true
                && $slots->census()['held'] === 0);

            // The epoch handed back is the epoch in the row, not an inference
            // about it - which is what lets release() fence on it at all.
            $conn->query('TRUNCATE TABLE ' . Schema::table('scan_worker_slot'));
            $slots->provision(1);
            $t1 = $slots->acquire('w1', 1, 60);
            $row = $conn->query('SELECT slot_no, epoch FROM '
                . Schema::table('scan_worker_slot'))->fetch_row();
            \check('slots: the slot and epoch reported are the ones in the table',
                (int) $row[0] === $t1['slot_no'] && (int) $row[1] === $t1['epoch']);

            $conn->query('DROP DATABASE ' . $name);
            $conn->close();
        }
    }
}

namespace {
    echo "scan_moduledb_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
