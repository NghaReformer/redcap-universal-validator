<?php
/**
 * tests/mysql/cases/schema.php — what the storage engine itself guarantees.
 *
 * Four invariants live in UNIQUE keys rather than in PHP, and this case asserts
 * them the only way they can be asserted: from a second connection, against a
 * real server. A read-then-write "is one already running?" check in PHP is a
 * race; a UNIQUE key is not.
 *
 * TWO PROJECTS, NAMED. 900 is the project under test and 901 is its neighbour,
 * and the neighbour is here to answer the question the rest of the suite could
 * not: whether a constraint that looks project-scoped actually is. See
 * support/fixture.php for why every case carries one.
 */

use INSPIRE\UniversalValidator\Scan\Schema;

$PID = 900;
$NEIGHBOUR = uv_neighbour($PID);

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
    $ins($ca, $PID, random_bytes(16), 1) === true);
check('one-active-run: a SECOND active run on the same project is refused by the engine',
    $ins($cb, $PID, random_bytes(16), 1) === false);
check('one-active-run: a different project is unaffected',
    $ins($cb, $NEIGHBOUR, random_bytes(16), 1) === true);

// A terminal transition releases the slot by setting it NULL, and MySQL permits
// unlimited NULLs in a UNIQUE index - which is what makes history retainable.
$A->query('UPDATE ' . $run . " SET active_slot = NULL, terminal = 'complete', phase = 'terminal'
    WHERE project_id = " . $PID);
check('one-active-run: a terminal run frees the slot for the next start',
    $ins($cb, $PID, random_bytes(16), 1) === true);
$rows = $ca->query('SELECT COUNT(*) FROM ' . $run . ' WHERE project_id = ' . $PID, []);
check('one-active-run: and the finished run is still on record, not overwritten',
    (int) $rows[0][0] === 2);
// AND THE NEIGHBOUR IS STILL THERE. Every assertion above is about a slot that
// is supposed to be per-project, and a per-project claim proved in a schema
// holding one project is not proved at all. This check exists so that a later
// edit which quietly drops the second project fails here rather than silently
// removing the only thing that makes the three above mean anything.
$nbRuns = $ca->query('SELECT COUNT(*) FROM ' . $run . ' WHERE project_id = ' . $NEIGHBOUR, []);
check('one-active-run: the neighbouring project still holds its own run throughout',
    (int) $nbRuns[0][0] === 1);

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
$A->query('UPDATE ' . $run . ' SET lease_epoch = 7, cursor_ordinal = 100 WHERE project_id = ' . $NEIGHBOUR);
$rid = (int) $ca->query('SELECT run_id FROM ' . $run . ' WHERE project_id = ' . $NEIGHBOUR, [])[0][0];

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

// -- the upgrade an installation that ALREADY RAN 1.9.x actually takes --------
//
// Every other check in this file migrates from nothing. That is the one path a
// piloted server will never take, and it is the path where version 1's frozen
// comment ("the durable scan has never been enabled on any installation") cost
// five releases of invisible schema changes.
//
// So: build version 1 ALONE, put rows in it the way 1.9.x did, record version 1,
// and migrate for real.
foreach (array_reverse(Schema::tables()) as $t) $A->query('DROP TABLE IF EXISTS ' . $t);
foreach (Schema::statements(1) as $sql) $A->query($sql);
$A->query('INSERT INTO ' . Schema::table('schema_version') . ' (version, applied_at) VALUES (1, NOW())');

// A version-1 run and its findings, in the shape 1.9.10 wrote them: generation 1
// for every project, and no project_id on the finding at all.
$A->query('INSERT INTO ' . Schema::table('scan_run') . '
    (run_uuid, project_id, run_seq, generation_id, created_by, scope_kind, run_kind, phase,
     coverage, detail, values_state, policy_json, policy_revision, fingerprint,
     created_at, updated_at, active_slot)
    VALUES (UNHEX(REPLACE(UUID(), "-", "")), 900, 1, 1, "alice", "project", "full", "scanning",
            "partial", "complete", "none", "{}", 1, REPEAT("a", 64), NOW(), NOW(), 1)');
$A->query('INSERT INTO ' . Schema::table('scan_run') . '
    (run_uuid, project_id, run_seq, generation_id, created_by, scope_kind, run_kind, phase,
     coverage, detail, values_state, policy_json, policy_revision, fingerprint,
     created_at, updated_at, active_slot)
    VALUES (UNHEX(REPLACE(UUID(), "-", "")), 901, 1, 1, "bob", "project", "full", "scanning",
            "partial", "complete", "none", "{}", 1, REPEAT("b", 64), NOW(), NOW(), 1)');
for ($i = 1; $i <= 3; $i++) {
    $A->query('INSERT INTO ' . Schema::table('finding') . '
        (generation_id, finding_identity, valid_from_seq, active_slot, record_hash,
         record_id_bin, instance, host_form, field, rule_source_id, rule_revision, rule_ord,
         check_type, reason_code)
        VALUES (1, UNHEX(SHA2(' . $i . ', 256)), 1, 1, UNHEX(SHA2(' . $i . ', 256)),
                "R' . $i . '", 1, "fa", "x", "r1", REPEAT("c", 64), 1, "required", "required-blank")');
}
$A->query('INSERT INTO ' . Schema::table('unique_group') . '
    (generation_id, group_hmac, phase) VALUES (1, UNHEX(SHA2("g", 256)), "new")');
$A->query('INSERT INTO ' . Schema::table('scan_dim') . '
    (generation_id, kind, dim_key, label) VALUES (1, "form", "fa", "Form A")');

$before = (int) $ca->query('SELECT COUNT(*) FROM ' . Schema::table('finding'))[0][0];
check('upgrade: the version-1 fixture really has findings to lose', $before === 3);
check('upgrade: and it reports itself at version 1', Schema::currentVersion($ca) === 1);

$r = Schema::migrate($ca);
check('upgrade: an installation at version 1 migrates rather than sitting still',
    $r['ok'] === true && $r['from'] === 1 && $r['to'] === 2);

// The four tables gain the column, and the rows that predate it go: their
// identities were computed by a naming pass that has since changed, so they can
// never be matched against a new run's rows and could never be closed.
check('upgrade: uv_finding now carries project_id',
    (int) $ca->query('SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = "' . Schema::table('finding') . '"
          AND column_name = "project_id"')[0][0] === 1);
check('upgrade: and the version-1 findings are gone rather than mis-attributed',
    (int) $ca->query('SELECT COUNT(*) FROM ' . Schema::table('finding'))[0][0] === 0);
check('upgrade: with nothing left belonging to no project, in any of the four tables',
    (int) $ca->query('SELECT COUNT(*) FROM ' . Schema::table('unique_group')
        . ' WHERE project_id = 0')[0][0] === 0
    && (int) $ca->query('SELECT COUNT(*) FROM ' . Schema::table('scan_dim')
        . ' WHERE project_id = 0')[0][0] === 0);

// Both wedged runs are retired, which is what releases the project slot the
// pilot's runs have been holding since 1.9.0 with nothing able to reap them.
check('upgrade: every pre-existing run is retired, for every project',
    (int) $ca->query('SELECT COUNT(*) FROM ' . Schema::table('scan_run')
        . ' WHERE active_slot = 1')[0][0] === 0);
check('upgrade: and each is expired rather than silently deleted',
    (int) $ca->query('SELECT COUNT(*) FROM ' . Schema::table('scan_run')
        . ' WHERE terminal = "expired"')[0][0] === 2);

// The sequence starts ABOVE what those runs used, so a new run on either
// project cannot reuse a generation number that already appears in the tables.
check('upgrade: the generation sequence is seeded per project',
    (int) $ca->query('SELECT COUNT(*) FROM ' . Schema::table('project_seq'))[0][0] === 2);
check('upgrade: and starts past the generation the retired run used',
    (int) $ca->query('SELECT next_seq FROM ' . Schema::table('project_seq')
        . ' WHERE project_id = 900')[0][0] === 2);

// The index the supersede query needs is the one three specs asked to drop.
check('upgrade: ix_record survives the migration, widened rather than dropped',
    // COUNT over information_schema.statistics counts one row PER COLUMN of an
    // index, so a four-column key answers 4. The question is whether the key
    // exists, not how wide it is.
    (int) $ca->query('SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = "' . Schema::table('finding') . '"
          AND index_name = "ix_record_v2"')[0][0] === 1);

// And running it again changes nothing. An ALTER that ran twice would fail the
// whole migration, which is why every version-2 statement carries a predicate.
$r2 = Schema::migrate($ca);
check('upgrade: migrating an already-migrated installation succeeds', $r2['ok'] === true);
check('upgrade: and leaves the retired runs retired',
    (int) $ca->query('SELECT COUNT(*) FROM ' . Schema::table('scan_run')
        . ' WHERE terminal = "expired"')[0][0] === 2);
