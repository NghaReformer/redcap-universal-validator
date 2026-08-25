# Durable validation scan — remediation plan

**Target:** this worktree, branched from `2600e8d` (v1.9.10).  
**Source:** `reports/scan-durable-adversarial-review-2026-08-24.md` — 12 blocking, 17 high, 19 medium, 7 low, and 11 measured performance findings.

Every finding in that review was re-verified against this checkout before a line of it was planned.
Nothing was taken on the review's word: where a claim could be settled by running something, it was.

## Verification outcome

| Cluster | Verdicts |
|---|---|
| B8 + B12 + H13: no run may wedge forever | `B8` confirmed; `B12` confirmed, corrected; `H13` confirmed; `B6a` confirmed; `NEW-1` confirmed |
| H4 + H5 + H6 + M6 + M7 + L1 + L2 + L3 — the browser client (js/scan.js) and the panel (pages/scan.php) | `H4` confirmed; `H5` confirmed, corrected; `H6` confirmed, corrected; `M6` confirmed; `M7` confirmed; `L1` confirmed, corrected; `L2` confirmed; `L3` confirmed, corrected; `N-C1` confirmed; `N-C2` confirmed |
| B4 + H15 + H18 + L4 + L5 — the outcome tells the truth | `B4` confirmed, corrected; `H18` confirmed, corrected; `H15` confirmed, corrected; `L4` confirmed, corrected; `L5` confirmed |
| B3 + M1 + M2 + M3 — DAG scope is compared name-vs-id | `B3` confirmed; `B3b` confirmed; `B3c` confirmed; `M1` confirmed, corrected; `M2` confirmed; `M3` confirmed |
| B5 / Task 7 — build the findings report and export (with M5 and L8) | `B5` confirmed, corrected; `M5` confirmed; `L8` confirmed; `B5-N1` confirmed; `B5-N2` confirmed |
| B9 + H12 + M4 + M10 + M14 — fencing, error masking, store contract | `B9` confirmed; `H12` confirmed, corrected; `M4` confirmed, corrected; `M10` confirmed; `M14` confirmed, corrected |
| H3 + H7 + H16 + H17 + M16 + L6 — sharp correctness bugs | `H3` confirmed, corrected; `H7` confirmed; `H16` confirmed, corrected; `H17` confirmed, corrected; `M16` confirmed; `L6` confirmed |
| B1 project-scoping + generation sequence (root cause): B1, B1-uniq, B1-extra (new), M15, M18 | `B1` confirmed; `B1-uniq` confirmed, corrected; `B1-extra` confirmed; `M15` confirmed; `M18` confirmed, corrected |
| B6 + B7 + H8 + H9 + H10 + H11 — safeguards written and never invoked | `B6` confirmed, corrected; `B7` confirmed, corrected; `H8` confirmed, corrected; `H9` confirmed, corrected; `H10` confirmed, corrected; `H11` confirmed, corrected |
| M13 + M14 + M17 + M19 + section 7 — store parity, uninstall, docs, and the wiring test | `M13` confirmed, corrected; `M14` confirmed; `M17` confirmed; `M19` confirmed, corrected; `S7-WIRING` confirmed, corrected; `NEW-1` confirmed; `NEW-2` confirmed |
| B10 + P4 + M8: planning is one unbounded unresumable request | `B10` confirmed; `B10a` confirmed; `B10b` confirmed; `P4` confirmed, corrected; `M8` confirmed, corrected |
| performance (P1–P11 + M9 + M11 + M12) | `P1` confirmed, corrected; `P2` confirmed; `P3` confirmed; `P5` confirmed; `P6` confirmed, corrected; `P7` confirmed; `P8` confirmed; `P9` confirmed, corrected; `P10` confirmed; `P11` confirmed, corrected; `M9` confirmed; `M11` confirmed, corrected; `M12` confirmed, corrected |
| H1 + H2: findings attributed to the wrong rule (rule identity derivation in the durable scan) | `H1` confirmed, corrected; `H2` confirmed, corrected; `H1/H2-N1 (new, found while settling this cluster)` confirmed; `H1/H2-N2 (new, design contradiction surfaced by this cluster)` confirmed; `H1/H2-N3 (new, fingerprint consequence)` confirmed |
| B2 + B11 — finding-identity collisions (intra-record duplicates and re-examination duplicates) | `B2` confirmed, corrected; `B11` confirmed |
| P1-P11 + M9 + M11 + M12 — the measured performance work | `P3` confirmed; `P2` confirmed; `M9` confirmed; `P1` confirmed, corrected; `M12` confirmed; `P5` confirmed; `P8` confirmed; `P7` confirmed; `P11` confirmed; `P6` confirmed; `P9` confirmed, corrected; `M11` confirmed, corrected; `P10` confirmed |

## What the verification changed

Nothing in the audit was refuted outright — all 13 clusters confirmed their findings, several by direct execution against MySQL 8.0.46. What changed is the FIX in a number of cases, and those are the phantoms to avoid.

RECOMMENDATIONS REFUTED (the finding is real, the proposed fix is wrong and would cause harm):
- P9 "DROP ix_record from uv_finding". Overruled, and this is the single most dangerous item in the plan. The observation is correct for 1.9.10 as shipped (I enumerated all 12 queries naming the finding table; none filters by record_hash) and becomes wrong the moment B11's supersede lands. Measured: 2.136 ms shipped, 1.690 ms widened, 333.527 ms dropped — 156x, on a query run once per record per batch. Widen to (project_id, generation_id, record_hash, active_slot). B1 Part 1(b) and B5-N2 both carry the same DROP clause; both are overruled with it. P9's secondary claim that ix_record is the largest secondary index is also downgraded — it is the third-largest.
- B9's implied fix, "bump lease_epoch on takeover". Rejected by its own verifier: it invalidates the whole run's fence, not just the taken-over records. The fix is a per-record claim token (claim_owner + claim_seq).
- M4's implied fix, "implement $expectCursor". Rejected: a cursor compare-and-set in commitBatch would reintroduce the rows-CHANGED-versus-rows-MATCHED bug documented at SqlScanStore.php:24-31. Delete the parameter and correct the invariant instead.
- B2's implied fix, "collapse same-identity findings and carry the detail in reason_bits". Rejected on two grounds: two ticked hidden codes are two different problems, and reason_bits physically cannot hold the detail (a 5-bit closed set against up to 200 arbitrary choice codes). Use a semantic locus discriminator inside the HMAC. Separately and emphatically: the discriminator must NOT be the per-record $seq at UniversalValidator.php:2977 — it is positional, so a record whose finding count changes renumbers every later finding and breaks the supersede matching B1/B11 depend on.
- B1's "supersede the previous generation at RUN START". Corrected to PROMOTION, and only for a run that reached terminal complete: closing at start means a run that then fails leaves the project with no current report, and this codebase's failure modes make that likely rather than hypothetical.
- B1/M18's "single-project installations can keep their version-1 findings by UPDATEing project_id". Overruled — see ddlRequirements step 1. The identity space changed underneath those rows; attribution does not make them comparable.
- M18's fallback of allow-listing MySQL errnos 1060/1061/1091 in migrate(). Rejected in favour of information_schema skipIf predicates.
- The review's own §7 countermeasure, "a wiring test that fails on any public method under php/Scan/ whose only callers are tests". It is worth building (it would have caught six of nine blocking findings and sixteen dead methods) but it would NOT have caught B2 — every call site involved is wired — and would NOT have caught B3, which needs a PROVENANCE test instead: for every value crossing a module boundary, one test must OBTAIN it from the production producer rather than construct it.

SEVERITY CHANGES:
- H1 and H2 RAISED from high to BLOCKING. H1 silently writes a finding against a rule that did not produce it, in a compliance-facing artifact, on the most ordinary trigger the module supports, and it corrupts finding_identity, which is the key B1's supersede depends on. H2 is wrong from the first run on every project that uses the settings dialog.
- H3 CONFIRMED but BROADER than stated, with one sub-claim overstated. Its premise is fictional: MySQL single-quotes identifiers, it does not backtick them, so safeDbMessage was redacting every identifier in every error shape, not only the duplicate-entry key name. Its regression test at tests/mysql/run.php:757 was a disjunction passing on the wrong half and had never actually verified the thing it named. Against the specific duplicate-entry shape on 8.0.46 the key NAME did survive, so that one claim is weaker than stated — while a different leak exists there that the review missed entirely: raw binary from the 32-byte finding identity reached a user-visible message because a quote byte inside it re-opened the redaction pattern.
- H7 CONFIRMED at HEAD. One agent reported it "appears refuted" after reading the working tree; that reading was of an uncommitted fix, not of 2600e8d. It is a genuine one-character defect and is the only unqualified catch in the tree.
- P6 OVERSTATED: 160 s for the unpaged 500,000-row DELETE, not 644 s. The "13 minutes rolling back" figure is unverified.
- P8 WORSE than stated: 811 s, not 262 s. P11 WORSE: recordStates is 75-150 ms, not 46 ms, and is called up to six times per request, not twice.
- P1 CONFIRMED_WITH_CORRECTION: the mechanism list is incomplete and the "~1.8 s" absolute is unverifiable without a live REDCap; the DUPLICATION is proved structurally. M12 is proved exactly — the plan is rebuilt precisely twice per request.
- P4 and P10 have no independent fix; they are measurements that size B10 and justify B6/P6 respectively. Do not schedule a patch for either.
- L5 is fixed entirely by L4. Do not write a second patch.
- B3c requires NO change to ScanAuthorization — it resolves as a consequence of B3 Part 1. Only the invariant comment and the cleanup of already-wedged runs are its own.
- M15 is closed by DELETION of the store's duplicate purge, not by reconciling the two cascades.

ALREADY FIXED IN THE WORKING TREE (verify, do not re-implement): H7, H17, L6, the H3 DbError rewrite, and the tests/mysql/run.php:757 disjunction. H16 and M16 are the live remainder of that cluster.

CLOSED ENUMERATIONS — do not re-derive these:
- B2's collision sources are enumerated and closed by execution. Repeating form instances versus the base row, the repeating-event bucket, fields-csv duplicates, two rules of the same mode on one field, two identical rules, annotation rules with a repeated field, and UniqueFinalizer::emit are all REFUTED as collision sources. The live sources are @UVCHOICES checkboxes with multiple hidden codes ticked, and any settings rule naming the same field twice.
- B3's outOfScope counter is refuted as a gate: it is not read at all (ScanService::start discards the stats array), and even if read, outOfScope === listed is produced identically by an empty Data Access Group and by a scope value on the wrong axis. It measures the predicate's output, never its validity.
- The field-type gate at UniversalValidator.php:1538-1539 (['text','notes']) is CORRECT — a REDCap Notes field is 'notes' in the dictionary array. Not a defect; do not chase it.

## The single migration

ONE MIGRATION. Schema::VERSION 1 -> 2. Owner: wave 3, work item schema-v2-migration. No other work item may change Schema.php's DDL; anything discovered later is a version 3 and therefore a second ALTER pass over an ~800 MB uv_finding on a piloted server.

WHY A BUMP IS MANDATORY, NOT OPTIONAL. migrate() returns {ok, from:1, to:1, applied:0} over a complete version-1 schema — proved by execution on MySQL 8.0.46. The scan WAS piloted at 1.9.x (UniversalValidator.php:2265 has called migrate() since then), so version 1 exists in the field WITH DATA and the comment at Schema.php:40-47 ("the durable scan has never been enabled on any installation") is false and must be deleted. Any DDL change written into statements(1) is invisible to every installation that has already run 1.9.x.

statements(1) IS FROZEN BYTE-FOR-BYTE, FOREVER. It is now the definition of what a field installation contains; editing it makes the code and the field disagree.

VERSION 2 MUST BE ALTERs, NOT RE-ISSUED CREATEs. Proved: re-issuing CREATE TABLE IF NOT EXISTS with a changed column list against an existing populated table succeeds, emits one warning, and changes nothing. Three new tables are the only CREATEs.

=== NEW TABLES (CREATE TABLE IF NOT EXISTS; add all three to Schema::$tables, taking it from 10 to 13) ===
1. uv_project_seq (project_id INT UNSIGNED NOT NULL, next_seq BIGINT UNSIGNED NOT NULL DEFAULT 1, PRIMARY KEY (project_id))  [B1 — the per-project generation counter; one counter, because run_seq and generation_id are the same monotonic number for a full run, which is what makes valid_from_seq/valid_to_seq a real interval]
2. uv_scan_plan (run_id BIGINT UNSIGNED NOT NULL, plan_cursor VARBINARY(255) NULL, plan_carry MEDIUMBLOB NULL, plan_pages INT UNSIGNED NOT NULL DEFAULT 0, plan_listed BIGINT UNSIGNED NOT NULL DEFAULT 0, plan_out_of_scope BIGINT UNSIGNED NOT NULL DEFAULT 0, plan_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, plan_done TINYINT UNSIGNED NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL, PRIMARY KEY (run_id))  [B10 — MEDIUMBLOB is required, not chosen: plan_carry holds up to TIE_CAP=200 record ids of up to 255 bytes hex-encoded and comma-joined = 102,199 bytes, and BLOB caps at 65,535]
3. uv_rate_bucket (project_id INT UNSIGNED NOT NULL, bucket INT UNSIGNED NOT NULL, hits INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (project_id, bucket))  [M16]
All three ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4. uv_scan_plan must be added to ScanRetention::purgeRuns' cascade, child before parent.

=== ALTERS ===
uv_scan_run — ADD supersede_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0 [B1]; ADD progress_at DATETIME NULL [B8/B6a]; ADD clean TINYINT UNSIGNED NULL (NULL = not yet decided) [H15]; ADD gap_count BIGINT UNSIGNED NOT NULL DEFAULT 0 [H15]; ADD rule_problem_count BIGINT UNSIGNED NOT NULL DEFAULT 0 [H15]; ADD not_examined BIGINT UNSIGNED NOT NULL DEFAULT 0 [H15/H18].

uv_scan_record — ADD claim_owner VARBINARY(64) NULL [B9]; ADD claim_seq BIGINT UNSIGNED NOT NULL DEFAULT 0 [B9]. No new index: ix_run_state (run_id, state) is already the right shape; P11's problem is that the queries do not use it, which is a code fix.

uv_finding — ADD project_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER finding_id, then rebuild every key with project_id leading so every project-scoped query can use it:
  uq_active_identity (project_id, generation_id, finding_identity, active_slot)
  uq_staged_identity (project_id, generation_id, finding_identity, stage_epoch)
  ix_page            (project_id, generation_id, active_slot, finding_id)
  ix_group_stage     (project_id, generation_id, group_hmac, stage_epoch, finding_id)
  ix_filter_form     (project_id, generation_id, active_slot, host_form, finding_id)
  ix_filter_reason   (project_id, generation_id, active_slot, reason_code, finding_id)
  ix_filter_dag      (project_id, generation_id, active_slot, dag_key, finding_id)
  ix_record          (project_id, generation_id, record_hash, active_slot)   <-- WIDENED, NOT DROPPED
  ADD ix_filter_type (project_id, generation_id, active_slot, check_type, finding_id)  [B5-N2]
RECONCILIATION, ix_record: three separate specs (B1 Part 1(b), P9's recommendation, B5-N2's second clause) say DROP IT. All three are OVERRULED. They are correct about 1.9.10 as shipped — no query filters uv_finding by record_hash today — and wrong the moment B11's supersede lands in wave 4, because that query is exactly "WHERE <project/generation scope> AND record_hash = ? AND active_slot = 1", run once per record per batch. Measured on 125,000 findings / MySQL 8.0.46: 2.136 ms with ix_record as shipped, 1.690 ms with the trailing active_slot, 333.527 ms with it dropped (the optimiser falls back to uq_active_identity, rows=61,268). That is 156x, i.e. 166 s per 500-record batch instead of 1 s. The trailing active_slot is the part that must not be lost.
RECONCILIATION, the B2 discriminator: it is NOT a column. Verification settled on 'locus' appended to the tuple inside Hmac::findingIdentity, with a FINDING_TUPLE = 'f2' tag prefixed to the message so v1 identities are recognisably old. So uq_active_identity is unchanged apart from the project_id prefix, and no DDL is owed to B2. Do not bump Hmac::V — it is shared by P_RECORD/P_VALUE/P_UNIQUE and bumping it would invalidate record_hash and unique_candidate.group_hmac for nothing.
No other uv_finding index changes: the remaining keys measured 11.5-43.8 MB per 500,000 rows and each serves a real query. No index on value_expires_at — deliberately deferred; wave 8 pages those statements instead, and adding it is a version-3 decision once measured.

uv_unique_candidate — ADD project_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER candidate_id; MODIFY event_id INT UNSIGNED NOT NULL DEFAULT 0 (0 means 'no event'); rebuild uq_candidate (project_id, generation_id, group_hmac, record_hash, field, event_id, instance) and ix_group (project_id, generation_id, group_hmac). The event_id change is load-bearing, not tidiness: event_id is NULL on every classic project, MySQL treats each NULL in a unique index as distinct, so insertCandidate's ON DUPLICATE KEY UPDATE never fires there and duplicate candidate rows accumulate. Harmless today only because discover() counts COUNT(DISTINCT record_hash) — one line away from a false duplicate report. Every reader must map 0 back to null at the boundary; UniqueFinalizer is the only reader.

uv_unique_group — ADD project_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER group_id; rebuild uq_group (project_id, generation_id, group_hmac); ADD ix_pending (project_id, generation_id, phase, group_hmac) [P7 — measured, nextUnfinished() goes 2.88 ms at 0% settled to 283.82 ms at 100% settled without it and is a flat 1.49-1.73 ms with it; phase is VARCHAR(24) and key_len came out 106 bytes, fine under the ROW_FORMAT=DYNAMIC the DDL already sets].

uv_scan_dim — ADD project_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER dim_id; rebuild uq_dim (project_id, generation_id, kind, dim_key).

uv_scan_worker_slot — NO CHANGE. NEW-2's ambiguous lease read-back is resolved by REMOVAL (delete leaseSlot/releaseSlot from the store contract and both implementations, leaving WorkerSlots as the single semaphore) rather than by a lease_token column.

=== INVARIANTS THE MIGRATION MUST NOT BREAK ===
active_slot and stage_epoch stay TINYINT UNSIGNED NULL / BIGINT UNSIGNED NULL. MySQL treats every NULL in a UNIQUE index as distinct, which is what makes "at most one active version per finding identity, unlimited closed ones" a storage-engine invariant rather than a PHP check that races. Nothing in this migration may make either NOT NULL or introduce a sentinel for "closed": a NOT NULL active_slot with a 0 sentinel would permit exactly one closed row per identity and destroy the interval history the whole design rests on. Extend the assertion at tests/scan_schema_php.php:130-131 to cover stage_epoch too.
project_id keeps its DEFAULT 0 permanently. The default is what lets ADD COLUMN succeed against a populated table, and dropping it after the data step would make every wave between 3 and 4 fail its inserts under STRICT_TRANS_TABLES. The fail-loud lives in PHP instead: insertFinding/insertCandidate/emit/discover refuse a missing or zero project_id (wave 4), with a contract-suite case on both stores.
Do NOT append ALGORITHM=INPLACE, LOCK=NONE. Verified INPLACE/LOCK=NONE on MySQL 8.0.46 at 87 ms on a small table, but naming the algorithm turns "this server cannot do it online" into a migration FAILURE. Let the server choose; migrate() already fails closed and loud.

=== IDEMPOTENCE ===
ALTER TABLE x ADD COLUMN y fails if y exists, and migrate() fails closed on the first statement error, so a migration interrupted between two ALTERs could never be resumed — which breaks the class's stated promise that running it against a half-created schema completes it, and kills the $from = 0 repair branch. Version 2 therefore returns statement DESCRIPTORS, e.g. ['sql' => 'ALTER TABLE ...', 'skipIf' => ['column','uv_finding','project_id']] / ['index','uv_unique_group','ix_pending'], checked by new private hasColumn()/hasIndex() helpers against information_schema in the same preparable form health() already uses. statements(1) keeps returning plain strings. The class docblock changes from "idempotent by construction" to "idempotent by check"; the migrate() docblock must say the same. Do NOT take the fallback of allow-listing errnos 1060/1061/1091 — it swallows real errors sharing a code, and migrate()'s whole design is to fail loud.

=== HOW AN INSTALLATION THAT ALREADY RAN 1.9.x WITH DATA IS UPGRADED IN PLACE ===
Ordered PHP steps inside migrate(), after the DDL, all bounded/paged, all logged through the existing scan-schema-migrate entry:
1. DELETE the version-1 rows from uv_finding, uv_unique_candidate, uv_unique_group and uv_scan_dim — every row where project_id = 0, which after ADD COLUMN DEFAULT 0 is all of them — in pages of 5,000. This is stricter than the B1 spec, which proposed keeping them on a single-project server by UPDATEing them to that pid, and the correction is deliberate: attribution is only half the problem. H1/H2 change every stored rule_source_id and B2 changes the finding tuple, so a 1.9.x row's finding_identity is not comparable with a post-upgrade one; superseding across that boundary is impossible and attempting it would match the wrong rows. Keeping them would leave a report that mixes two identity schemes and whose rows can never be closed. On a multi-project server they are additionally wrong about which project they describe — reproduced: one project's instrument and DAG names were rolled up into another project's summary, a duplicate group was silently skipped, and every second scan wedged. The findings are re-derivable by re-running the scan; the corruption is not detectable by a reader.
2. Retire every pre-existing run: UPDATE uv_scan_run SET active_slot = NULL, terminal = 'expired', coverage = 'partial', clean = NULL, terminal_reason = '<upgraded>' WHERE active_slot = 1 (or terminal IS NULL). This releases the project slot, matches what ScanOutcome::derive(['expired' => true]) produces, and cleans up the runs already wedged on the DAG name axis.
3. Seed the sequence so no project reuses generation 1: INSERT INTO uv_project_seq (project_id, next_seq) SELECT project_id, MAX(generation_id) + 1 FROM uv_scan_run GROUP BY project_id ON DUPLICATE KEY UPDATE next_seq = GREATEST(next_seq, VALUES(next_seq)).
4. POST-CONDITION: assert currentVersion() === VERSION and health()['ok'] and that no row in the four tables carries project_id = 0, before returning ok = true. Today migrate() returns ok on the strength of having executed statements without error; with conditional ALTERs that is no longer sufficient evidence that the schema is right.
Schema::health() must KEEP its strict $v !== self::VERSION check. It is what disables the scan on an installation carrying the new code and the old tables, and that fail-closed behaviour is the only protection for a server whose administrator has not yet re-saved the configuration.

=== TEST ASSERTIONS THAT MUST BE RE-SCOPED, NOT LOOSENED ===
tests/scan_schema_php.php:106-123 asserts that every statement in Schema::plan(0) is a CREATE TABLE IF NOT EXISTS, that substr_count(ENGINE=InnoDB) === count(Schema::tables()), the same for ROW_FORMAT=DYNAMIC, and (:118) that /ALTER\s+TABLE/i matches zero times. All four break on version 2. Re-scope them to Schema::statements(1) plus the three new CREATEs, and relax the ALTER prohibition to forbid only DROP TABLE / DROP COLUMN / DROP DATABASE / TRUNCATE / DELETE. Loosening them instead removes the idempotence property the whole class rests on. :129's literal uq_active_identity string becomes the project_id-leading form. The version-relative assertions at :147 and :149 still hold once statements(3) returns []. The migrate() stubs at :194/:208/:221/:230/:277/:282 must now answer information_schema column and index probes — the largest single test edit in the job. Add the test class the repo has no analogue for: a schema that HAS DATA migrating to 2, the same migration run twice changing nothing, a migration interrupted between two ALTERs resuming, and a build ahead of its schema disabling the scan.

## Implementation waves

Two work items in the same wave never touch the same file. Later waves build on earlier ones;
nothing depends on a change scheduled after it. Every wave ends where the full suite runs.

### Wave 1

Nothing here changes scan semantics, so it can land while the tree is still at 1.9.10 behaviour, and all three items are needed as scaffolding by later waves. The MySQL suite is one 1746-line file that thirteen clusters all want to edit; until it is partitioned, the per-wave file-disjointness rule collapses to one work item per wave for the rest of the job. The client is the other unblocker: H12 (wave 2) and B8/B12 (wave 5) both introduce response shapes that today make js/scan.js spin at setTimeout(pump,0), so the progress-keyed backoff must exist before the server starts emitting them. Landing the client first is safe because H4's fix is specified to be correct under both the ok:true-no-work and ok:false-stop shapes.

#### `test-harness-partition`

*S7-SUITE*

Split tests/mysql/run.php into per-area case files behind a shared bootstrap (check(), connection factory, schema-name override), so later waves own disjoint test files. Two structural changes, not cosmetics: (a) every fixture gains an explicit project and the default fixture becomes TWO projects, because the suite's habit of hand-partitioning the broken axis (tests/mysql/run.php:1090 injects dagFilter '7' directly, :1314/:1415/:1453/:1482 each pick their own synthetic generation 4242-4245) is exactly the isolation production does not have and is why 285 real-database checks are blind to B1; (b) the truncate-between-sections preamble is replaced by per-case schema isolation so a second run of the suite cannot shift expected values. Also parameterise the schema name — other agents run this suite concurrently against uv_test and it drops the uv_* tables.

<details><summary>13 files</summary>

- `tests/mysql/run.php`
- `tests/mysql/support/bootstrap.php`
- `tests/mysql/cases/schema.php`
- `tests/mysql/cases/store.php`
- `tests/mysql/cases/fence.php`
- `tests/mysql/cases/retention.php`
- `tests/mysql/cases/uniqueness.php`
- `tests/mysql/cases/catchup.php`
- `tests/mysql/cases/rollup.php`
- `tests/mysql/cases/promotion.php`
- `tests/mysql/cases/summary.php`
- `tests/mysql/bootstrap.sql`
- `docs/TESTING.md`

</details>

#### `browser-client`

*H4, H5, H6, M6, M7, L1, L2, L3, C1*

js/scan.js and pages/scan.php are owned by this item for the whole remediation; no other work item in any wave may edit them in the same wave. Replace stop-string-keyed scheduling with progress-keyed backoff (NET_MIN/NET_MAX for transport, IDLE_MIN 1500ms/IDLE_MAX 30000ms for no-progress), because two hot-loop paths carry stop:null. The backoff predicate MUST be 'did this request make progress' (worked > 0 || listed > 0), not 'did it examine a record' — B10's planning step legitimately works zero records while doing real work. Preserve the server's own why sentence instead of blanking it; make call() unable to throw synchronously and separate transport failure from handler bugs; add the in-flight flag plus chain token so a double click cannot start two pumps; name an unrecognised coverage value rather than rendering an empty certificate; whitelist the JSMO name as a dotted identifier before printing it; full ARIA contract on the panel; honest noscript text plus a server-side pre-render of status; indeterminate-bar CSS with a prefers-reduced-motion branch. Move the PHASE and COVERAGE label tables into ScanPageView so PHP and JS cannot drift — that is what keeps waves 6, 7 and 11 from reproducing L1.

<details><summary>6 files</summary>

- `js/scan.js`
- `pages/scan.php`
- `php/ScanPageView.php`
- `tests/scan_client_js.cjs`
- `tests/scan_page_php.php`
- `CHANGELOG.md`

</details>

#### `hosting-lints-capabilities`

*H7, H16, H17, L6, S7-WIRING*

Four of these are already implemented uncommitted in this worktree by an earlier pass — H7 (catch (\Throwable) with the class-only scan-schema-install-failed log), H17 (fetchAll with the 500 cap), L6 (three-parameter mayCancel), and the H3 supporting work. VERIFY and commit them rather than re-implementing; the specs serve as the review checklist. New work: the static namespace lint that fails on any unqualified catch of a global class inside the namespace (H7 item 3, the countermeasure that generalises the one-character fix); H16, making the availability gate probe redcap_projects.data_table like the walk does instead of a hardcoded redcap_data, with a gate-vs-walk agreement test; and the wiring test (tests/scan_wiring_php.php) that fails on any public method under php/Scan/ whose only callers are tests. This item also owns the require_once block additions for php/Scan/DbError.php and php/Scan/ScanStoreUnavailable.php that wave 2 needs — hand the two lines to this owner rather than touching UniversalValidator.php twice.

<details><summary>11 files</summary>

- `UniversalValidator.php`
- `php/ScanCapabilities.php`
- `php/Scan/RecordManifestSource.php`
- `php/Scan/ScanAuthorization.php`
- `tests/hosting_php.php`
- `tests/hook_php.php`
- `tests/scan_capabilities_php.php`
- `tests/scan_security_php.php`
- `tests/namespace_lint_php.php`
- `tests/scan_wiring_php.php`
- `.github/workflows/parity.yml`

</details>

### Wave 2

Two independent prerequisites for the migration. H12 first, per its own ordering note: it is nearly test-neutral (only catch blocks change, no successful return type moves) and it makes every later failure visible instead of silent, including failures the later waves introduce. H3 must be early for the same reason the lead engineer established by execution — every later wave debugs against that message, and its regression test was passing on the wrong disjunct. H1/H2 must precede both the migration and the report: they change every stored rule_source_id and therefore every finding_identity, and M5 would otherwise persist the wrong ordinal-to-rule mapping authoritatively into uv_scan_dim.

#### `db-fault-channel`

*H3, H12, M10, M14, NEW-2-worker-slot*

Finish and prove DbError (already drafted untracked in this tree): rebuild the message from a template allowlist rather than filtering the original, and add the two things the drafts miss — strip non-printable bytes BEFORE redacting (B11's reproduction leaked raw binary from the 32-byte identity because a quote byte inside it re-opened the /'[^']*'/ pattern), and confirm no value can reach a name slot through ident()'s loose /^[0-9A-Za-z_$. -]+\z/. Add ScanStoreUnavailable so 'the database failed' is its own answer without changing any successful return type; convert the four catch blocks that return false/0 (SqlScanStore 321-324, 376-381, 643-646, 758-761) to throw it. M10: on startRun failure ask the one presence question that separates a genuine uq_project_active duplicate from a fault, so an operator is never told to wait for something that will never finish. M14: harden ModuleDb::exec so a -1 ROW_COUNT is a loud failure rather than a silent compare-and-set, with a conformance harness driving six framework return shapes through it. NEW-2: delete leaseSlot/releaseSlot from the store contract and both implementations — two semaphores for one resource, and the read-back picks the wrong slot when one owner string holds two (the owner defaults to the literal 'worker'). The client already stops on any ok:false after wave 1, so no js/scan.js edit is needed here.

<details><summary>18 files</summary>

- `php/Scan/DbError.php`
- `php/Scan/ScanStoreUnavailable.php`
- `php/Scan/ScanDb.php`
- `php/Scan/SqlScanStore.php`
- `php/Scan/ArrayScanStore.php`
- `php/Scan/ScanStore.php`
- `php/Scan/ScanWorker.php`
- `php/Scan/ScanService.php`
- `php/Scan/WorkerSlots.php`
- `tests/db_error_php.php`
- `tests/scan_moduledb_php.php`
- `tests/support/FrameworkStub.php`
- `tests/scan_store_contract.php`
- `tests/scan_worker_php.php`
- `tests/scan_service_php.php`
- `tests/mysql/cases/store.php`
- `tests/mysql/cases/fault.php`
- `CHANGELOG.md`

</details>

#### `rule-identity`

*H1, H2, H1/H2-N1, H1/H2-N2, H1/H2-N3*

Both re-severitied from high to BLOCKING by verification. identifyAll() re-indexes with array_values() while $plan['live'] is sparse, so one config-broken rule shifts every later rule's identity and findings are written against a rule that did not produce them — in a compliance artifact, on the most ordinary trigger the module supports. Fix: key-preserving walk returning $out[$k]; carry the origin ON the rule as _origin rather than threading settingsCount, because Branching::resolve() drops and synthesizes rules so no integer boundary survives it. Then Part B: put ruleIds on the plan keyed identically to live, drop the positional array from durableEvaluateRecord's signature, and make the unnamed: fallback a counted problem rather than a plausible-looking rule name. N1 mints a persistent rule-uid per settings row (hidden sub-setting, minted in redcap_module_save_configuration) so the already-written uid: branch does its job. N3 makes the rule contribution to the fingerprint a SET, sorted by id with ord dropped, so dragging a row in the Online Designer stops aborting in-flight runs. Ship with at least one test entering through durableScanContext() — grep for durableScanContext or durableEvaluateRecord across tests/ returns zero hits today, which is why 12,300 green assertions cannot tell the corrupt build from the corrected one.

<details><summary>6 files</summary>

- `UniversalValidator.php`
- `php/Scan/ScanPlanner.php`
- `php/Branching.php`
- `config.json`
- `tests/scan_identity_php.php`
- `docs/USER_GUIDE.md`

</details>

### Wave 3

The single migration, alone in its own commit, because eleven clusters need DDL and a second version bump means a second ALTER pass over an ~800 MB uv_finding on a piloted server. Everything downstream of here assumes version 2 exists; nothing upstream of here may assume it. P3 rides along because it is the one blocking performance fix that touches no file the migration touches.

#### `schema-v2-migration`

*M18, B1, B9, B8, H15, B10, M16, B5-N2, P7, P9, NEW-1-uq-candidate*

Carries the DDL half of every cluster; the code halves land in waves 4-11. See ddlRequirements for the reconciled statement list. The three things that must not be got wrong: (1) statements(1) is frozen byte-for-byte forever — it is now the definition of what a field installation contains, and version 2 must be ALTERs plus three CREATEs, because re-issuing CREATE TABLE IF NOT EXISTS with a changed column list against an existing populated table succeeds, warns once, and changes nothing (proved on MySQL 8.0.46). (2) ALTERs are not idempotent and migrate() fails closed on the first error, so version 2 returns statement descriptors with skipIf predicates checked against information_schema (hasColumn/hasIndex) — otherwise a migration interrupted between two ALTERs can never be resumed and the class's central promise dies. (3) tests/scan_schema_php.php:106-123 asserts every statement is a CREATE TABLE IF NOT EXISTS, that count(plan(0)) === count(tables()), and that no statement matches /ALTER\s+TABLE/. Re-SCOPE all three to statements(1) plus the three new CREATEs; do not loosen them, or the idempotence property the whole class rests on is quietly removed. Add the migration test class the repo has no analogue for: nothing in ~12,300 assertions migrates a schema that has data in it.

<details><summary>7 files</summary>

- `php/Scan/Schema.php`
- `UniversalValidator.php`
- `tests/scan_schema_php.php`
- `tests/mysql/cases/schema.php`
- `tests/mysql/bootstrap.sql`
- `docs/INSTALL.md`
- `CHANGELOG.md`

</details>

#### `changelog-paging-axis`

*P3*

Change SourceFence::changedSince's paging axis from the grouped record id to the log id, removing the GROUP BY entirely and moving deduplication into PHP where it is a hash-map insert. $afterId becomes $afterSeq (a decimal log position), the page limit rises from 5000 to 20000, and the ChangeLog interface docblock is corrected. No file in this item is touched by the migration item.

<details><summary>5 files</summary>

- `php/Scan/SourceFence.php`
- `php/Scan/CatchUp.php`
- `php/Scan/ScanWorker.php`
- `tests/scan_worker_php.php`
- `tests/mysql/cases/catchup.php`

</details>

### Wave 4

Deliberately ONE work item. B1's four parts, B2's discriminator and B11's supersede all converge on the same two regions — UniversalValidator::durableEvaluateRecord() 2950-3030 and SqlScanStore::commitBatch() 392-495 — and the specs say in three separate places that splitting them leaves the store inconsistent. B2 and B1 also both change the identity space, and doing them in one release gives an installation exactly one identity discontinuity, at the same moment its generations become per-project, with no stored row ever compared across the boundary. Nothing else can run in this wave because the item owns essentially all of php/Scan/ plus the hot 60-line window in UniversalValidator.php; pretending otherwise would produce exactly the merge that the repo's own memory note (validator-rule-hosting.md) records as the failure mode that kept re-failing the 1.6.0 reviews.

#### `project-scoping-and-finding-identity`

*B1, B1-uniq, B1-extra, M15, B2, B11, M2, M5, H8*

The generation sequence becomes real: allocateSeq() in one statement via LAST_INSERT_ID against uv_project_seq, called BEFORE startRun's INSERT so a missing table cannot be reported as 'a scan is already running'; the two upstream defaults (ScanPlanner.php:173, UniversalValidator.php:2879) are deleted, not defaulted differently — a default is what let this ship. project_id threads through every producer and all 31 predicate sites; UniqueFinalizer refuses at construction without a project instead of falling back to 0. valid_from_seq stops carrying the per-record ordinal and carries the run's run_seq, which is what finally makes valid_from_seq/valid_to_seq an interval. Superseding the previous generation happens at PROMOTION, not at run start — closing the old rows at start means a run that then fails leaves the project with no current report, and this codebase's failure modes make that likely rather than hypothetical — and is paged through scan_run.supersede_cursor. B11's re-examination supersede goes inside commitBatch, after the FOR UPDATE and all three fence refusals, before the insert loop, scoped BY RECORD (not by the identities in this batch, or a violation fixed between examinations stays active forever), with stage_epoch IS NULL to leave duplicate findings to UniqueFinalizer, and gains AND project_id = ? the moment the column exists. B2's discriminator is 'locus' INSIDE the keyed HMAC, never a column: the ticked choice code for hidden-choice, empty for every rule kind that can produce at most one finding per field per context. It must NOT be the per-record $seq — that is positional, so a record whose finding count changes renumbers every later finding and breaks the very supersede matching this wave installs. Also: array_unique on a settings rule's field list (a field named twice in one rule is the same field), and an identity-keyed collapse in durableEvaluateRecord as a counted BACKSTOP, not a fix. M15 is closed by deletion — SqlScanStore::purgeRuns and ScanStore::purgeRuns go, ScanRetention becomes the only purge, and its parameter is renamed $olderThanDays so two methods can never again disagree about whether an integer meant days or a datetime. The finding literal also gains reason_bits, value_expires_at, value_binary and arm_id here (M5/H8 producer half) so that 60-line window is opened once.

<details><summary>23 files</summary>

- `UniversalValidator.php`
- `php/Scan/Hmac.php`
- `php/Scan/SqlScanStore.php`
- `php/Scan/ArrayScanStore.php`
- `php/Scan/ScanStore.php`
- `php/Scan/UniqueFinalizer.php`
- `php/Scan/RollupBuilder.php`
- `php/Scan/ScanRetention.php`
- `php/Scan/ScanPlanner.php`
- `php/Scan/ScanService.php`
- `php/Scan/ScanWorker.php`
- `php/Scan/ScanPolicy.php`
- `php/Scan/ReasonCode.php`
- `tests/scan_store_contract.php`
- `tests/scan_worker_php.php`
- `tests/scan_service_php.php`
- `tests/scan_security_php.php`
- `tests/scan_identity_php.php`
- `tests/mysql/cases/store.php`
- `tests/mysql/cases/uniqueness.php`
- `tests/mysql/cases/retention.php`
- `tests/mysql/cases/rollup.php`
- `CHANGELOG.md`

</details>

### Wave 5

Now that the store no longer refuses every second scan, give the runs that still fail a way out. B8/B12/H13 and B9/M4 both rewrite commitBatch's control flow and ScanWorker::batch's record appends, so they are one item; separating them means touching all 14 commitBatch call sites twice. Two small periphery items ride along on files this item does not touch. Note for the changelog: the visible effect of this wave on its own would be that some scans now end quickly as terminal=failed instead of hanging — correct, honest, and easily misread as a regression. Wave 4 is what makes that rare.

#### `worker-control-flow-and-claim-fence`

*B8, B12, H13, B9, M4, NEW-1-attempts, B6a*

The invariant: an attempt is counted for work that was ATTEMPTED, not for work that was COMMITTED, so it must be written by a transaction the failure cannot roll back. Add noteAttempts() plus the fifth record state REC_UNSTORED (104, always blocking, explicitly not a tombstone) and REC_UNREADABLE for the failed-read path; both branches funnel through the same if (empty($got['ok'])) block, and the catch-up path must RELEASE claimed rows rather than merely rewind a cursor. Saturate at LEAST(attempts+1, 254) in both stores so a TINYINT does not wrap or, under a non-strict sql_mode, silently clamp. B9's correction matters: do NOT bump lease_epoch on takeover, which is what the review implies — add a per-record claim fence instead (claim_owner + claim_seq, set inside the same transaction as the pending select), fence the record-state UPDATE on it, and insert only the findings whose ordinal is in $held. That is what removes the duplicate-identity batch kill for taken-over records. commitBatch's fence must also refuse a run that is already terminal — measured, an expired run currently accepts a late commit and advances manifest_done. Write progress_at in exactly two places (commitBatch when $applied > 0, and freezeManifest) so wave 10's reaper can have a predicate about progress rather than about activity: updated_at is written by claim(), advancePhase() and setProgressState() alike, so a wedged run refreshes it continuously and cannot be reaped at any staleHours. M4: DELETE $expectCursor rather than implementing it — a cursor CAS in commitBatch would reintroduce the rows-CHANGED-not-MATCHED bug the file's own comment documents — and correct invariant I2 to describe the locking read that actually holds it.

<details><summary>11 files</summary>

- `php/Scan/ScanWorker.php`
- `php/Scan/ScanStore.php`
- `php/Scan/SqlScanStore.php`
- `php/Scan/ArrayScanStore.php`
- `php/Scan/ScanPromotion.php`
- `php/Scan/ScanRetention.php`
- `tests/scan_store_contract.php`
- `tests/scan_worker_php.php`
- `tests/mysql/cases/store.php`
- `tests/mysql/cases/fence.php`
- `CHANGELOG.md`

</details>

#### `rate-limit-atomic`

*M16*

Replace the timestamp-array read-modify-write with a fixed-window counter incremented by one statement against uv_rate_bucket (created in wave 3), so no read precedes the write and the survey rate limit stops being defeated by concurrency.

<details><summary>3 files</summary>

- `UniversalValidator.php`
- `tests/hook_php.php`
- `tests/mysql/cases/ratelimit.php`

</details>

#### `uniqueness-finalizer-cost`

*P8, P7*

discover() becomes one chunked multi-row INSERT per page (DISCOVER_CHUNK 500, 2500 placeholders against a 65,535/5 ceiling) instead of one statement per group — measured worse than the review claimed, 811 s not 262 s. Verify that ix_group_phase from wave 3 makes nextUnfinished() flat: measured 2.88 ms at 0% settled degrading to 283.82 ms at 100% settled without it, 1.49-1.73 ms with it.

<details><summary>3 files</summary>

- `php/Scan/UniqueFinalizer.php`
- `php/Scan/ScanDb.php`
- `tests/mysql/cases/uniqueness.php`

</details>

### Wave 6

The DAG scope must move onto the id axis before wave 7 writes the final outcome decision table (B3 contributes an EMPTY-SCOPE fact and a new coverage constant to it) and before wave 10 can wire H10's projection controls, which cannot work while scope_dag and the drift source are on different axes. The store I/O work runs alongside because it touches no page, service, planner or promotion file.

#### `dag-scope-on-the-id-axis`

*B3, B3b, B3c, M1, M3*

scanScope() returns the numeric group id as 'dag' and the name as 'dagName'; id is the axis three of the four consumers need, and any consumer left unmigrated then compares an id to a name and fails CLOSED, never open. Reproduced live: a name-scoped run listed 25 records, appended 0, called manifestComplete over an empty manifest true, and promoted to terminal=complete coverage=complete-through-fence clean=true — a clean certificate over nothing. The outOfScope counter is not a sufficient gate and is not even read (ScanService::start discards the whole stats array): outOfScope === listed is produced identically by a group that genuinely holds no records and by a scope value on the wrong axis. The gate is the two-part domain test in the spec. NO change to ScanAuthorization is required — readable() already produces the id and scopeMatches() already string-compares; add the invariant comment that stops a future author storing the friendly name, plus the migration that terminates the runs already wedged on the name axis. M1: a dag-scoped uniqueness rule on a record with no Data Access Group must REFUSE with a rule problem, not bucket into ''. M3: activeRun() returns only a run the caller may work and distinguishes 'none' from 'one you may not touch'. This item owns js/scan.js and pages/scan.php for this wave — the new coverage constant must land in the client's COVERAGE map in the same commit or it reproduces L1 exactly.

<details><summary>18 files</summary>

- `UniversalValidator.php`
- `php/ScanPageView.php`
- `php/Scan/ScanPlanner.php`
- `php/Scan/RecordManifestSource.php`
- `php/Scan/ScanService.php`
- `php/Scan/ScanAuthorization.php`
- `php/Scan/ScanPromotion.php`
- `php/Scan/ScanOutcome.php`
- `php/Scan/CatchUp.php`
- `pages/scan.php`
- `js/scan.js`
- `tests/scan_page_php.php`
- `tests/scan_security_php.php`
- `tests/scan_service_php.php`
- `tests/scan_client_js.cjs`
- `tests/mysql/cases/dag.php`
- `tools/measure_scan.php`
- `CHANGELOG.md`

</details>

#### `store-io-cost`

*P2, M9, P5, P11*

Stop the doubling: add execNoCount() to the ScanDb interface for statements whose affected-row count nobody reads, and poison $affected to -1 rather than 0 — the interface docblock already says an implementation returning -1 converts a rollback into a commit, so 0 would be the dangerous choice. Batch insertFinding into a multi-row statement (M9 is fixed entirely by this: a 4,000-finding record holds the run-row lock for 0.85 s instead of 15.9 s), and it must NOT be ON DUPLICATE KEY UPDATE on uq_active_identity — that hides the intra-batch collision B2 exists to surface. Do not move the FOR UPDATE after the inserts to shorten the lock; that reintroduces the rows-CHANGED race the file's comment documents. P11: manifestComplete becomes an existence test with FORCE INDEX rather than a count. P5: measure memory_get_usage(false) delta and peak delta, not the arena size, so the budget predicts anything. Leave P11's caller-side deduplication of recordStates to wave 8.

<details><summary>10 files</summary>

- `php/Scan/ScanDb.php`
- `php/Scan/SqlScanStore.php`
- `php/Scan/ArrayScanStore.php`
- `php/Scan/ScanStore.php`
- `php/Scan/ScanWorker.php`
- `php/Scan/WorkBudget.php`
- `php/Scan/DbError.php`
- `tests/scan_store_contract.php`
- `tests/scan_worker_php.php`
- `tests/mysql/cases/store.php`

</details>

### Wave 7

The outcome can only be made honest once the states it must report exist: REC_UNSTORED and the reachable attempt counter from wave 5, the EMPTY-SCOPE fact from wave 6, and the scan_run columns from wave 3. H18 in particular is unreachable before wave 5 — with attempts stuck at 0 the tombstone branch can never trip, so a record absent from every read is requeued forever instead of reaching any terminal state. The presentation layer runs alongside on new and leaf files only.

#### `outcome-tells-the-truth`

*B4, H15, H18, L4, L5*

One ordered decision table, agreed once: failed > cancelled > expired > EMPTY-SCOPE > blocked > !fenced > !manifestDone > truncated > clean. B4a: violations reads the true active-finding count from the rollup aggregates, which already count every active finding exactly once and are complete by the time promotion can be ready — this inherits wave 4's project filter rather than inventing a second scoping rule. B4b: a new store verb with presence-not-count semantics for the rule-problem and collection-gap aggregates that are computed and thrown away today; B4c: ScanPolicy's 'collectionGaps' => 'separate' becomes 'unimplemented', because a policy that names a behaviour nobody wrote is worse than one that admits the gap. H15: persist clean, gap_count, rule_problem_count and not_examined at promotion — the answer exists at promotion time and is currently discarded, forcing every future reader to re-derive it. H18 is the blocking half: a tombstone must be a CONFIRMED deletion, not an absence, so work() gains the exists probe already written for catchUp() (hoisted into one method so the two cannot drift), and a record merely absent from the export must not be recordable as deleted while the run claims complete coverage. L4: status() counts REC_DONE separately from the terminal exclusions and must exclude 104; L5 is fixed entirely by L4 plus a 'stalled' flag and one non-terminal sentence in the client, which turns a silent wedge into a support ticket at minute two instead of never. Also delete ScanPageView::verdict() — a second clean predicate with zero callers that does not agree with ScanOutcome::mayClaimClean(), which is the exact drift ScanOutcome's docblock exists to prevent.

<details><summary>21 files</summary>

- `php/Scan/ScanOutcome.php`
- `php/Scan/ScanPromotion.php`
- `php/Scan/ScanWorker.php`
- `php/Scan/ScanService.php`
- `php/Scan/ScanStore.php`
- `php/Scan/SqlScanStore.php`
- `php/Scan/ArrayScanStore.php`
- `php/Scan/UniqueFinalizer.php`
- `php/Scan/ScanPolicy.php`
- `php/Scan/RecordManifestSource.php`
- `UniversalValidator.php`
- `php/ScanPageView.php`
- `js/scan.js`
- `tests/scan_worker_php.php`
- `tests/scan_service_php.php`
- `tests/scan_client_js.cjs`
- `tests/scan_security_php.php`
- `tests/scan_store_contract.php`
- `tests/scan_page_php.php`
- `tests/mysql/cases/promotion.php`
- `CHANGELOG.md`

</details>

#### `report-presentation-layer`

*M5, B5*

Build the leaf half of the report ahead of the store half: FindingRowMapper (one stored row to one presentable row), ScanReport, and the column/dimension/message-catalogue entries that M5's reason_bits and dimension labels need. Pure new and leaf files, so it collides with nothing in this wave. The store signature and paging work is wave 8; the page and export are wave 11.

<details><summary>7 files</summary>

- `php/Scan/FindingRowMapper.php`
- `php/Scan/ScanReport.php`
- `php/ScanColumns.php`
- `php/ScanDimensions.php`
- `php/MessageCatalog.php`
- `php/Scan/ReasonCode.php`
- `tests/scan_report_php.php`

</details>

### Wave 8

Retention must be paged BEFORE wave 10 wires a cron to it — measured, an unpaged DELETE of 500,000 findings is a single 160-second statement holding row locks throughout, and wiring the shipped version would turn the purge into the outage. It must also come after wave 4, because on today's schema one project's expired run deletes every project's findings across the whole installation. The request-cost work is disjoint from it by file.

#### `retention-paged-and-store-parity`

*P6, M13, M15, B5-N1, NEW-2-worker-slot, P11*

Every retention statement that can touch an unbounded number of rows becomes a bounded page (PAGE = 5000) and every paging method returns whether there is more to do, so a cron can run a fixed slice rather than one that runs until it is killed. This covers three the review missed and that land on the same cron: expireValues() is an unpaged UPDATE over the installation-wide table, revokePreviews() an unpaged multi-table UPDATE JOIN that runs synchronously when an operator tightens a policy, and preview() filters on value_expires_at, which has no index (deliberately not adding one — that would be a version-3 decision; page instead). M13: give ArrayScanStore the constraints the schema has — a findings-shape assertion for every NOT NULL column, the active-identity uniqueness, the supersede, and a paging cursor that does not re-return a page-1 row on page 2 — and run one contract file against both stores, because that asymmetry is why ~12,300 green assertions covered none of this. B5-N1: both implementations return associative rows with the same documented key list in the same order, built by array_combine from the SELECT list exactly as run() and aggregates() already do. P11's caller half: ask recordStates once per request rather than up to six times.

<details><summary>7 files</summary>

- `php/Scan/ScanRetention.php`
- `php/Scan/SqlScanStore.php`
- `php/Scan/ScanStore.php`
- `php/Scan/ArrayScanStore.php`
- `tests/scan_store_contract.php`
- `tests/scan_store_php.php`
- `tests/mysql/cases/retention.php`

</details>

#### `per-request-cost`

*P1, M11, M12, P10*

Request-scoped memoisation only — ScanService is constructed fresh per HTTP request, which is the property that makes this safe. Memoise entitlement() on (pid, scope_dag, generation_id), available() on pid, ScanCapabilities::all, and scanPlan() itself; M12 is proved by execution to rebuild the whole plan exactly twice per request. Keep Schema::health() on the hot path — the comment at ScanService.php:88-89 is right that a migration nobody chose is worse than a slow check, and removing it trades a clear diagnostic for a stack trace mid-batch — but make it two queries instead of eleven. P10 has no standalone fix; add the operator-visible size figure from detail_rows/detail_bytes, which commitBatch already maintains and nothing renders. This item's Schema.php edit is health() only; no DDL, no version change.

<details><summary>8 files</summary>

- `php/Scan/ScanService.php`
- `UniversalValidator.php`
- `php/ScanCapabilities.php`
- `php/Scan/Schema.php`
- `php/Scan/ScanPromotion.php`
- `tests/scan_service_php.php`
- `tests/scan_schema_php.php`
- `tests/mysql/cases/perf.php`

</details>

### Wave 9

Planning becomes a budgeted, resumable phase driven by the existing browser pump. It needs uv_scan_plan (wave 3), the attempt mechanism it must call into rather than duplicate (wave 5), and the client's progress-keyed backoff whose predicate already counts listed > 0 as progress (wave 1). It is alone in its wave because it re-opens the store contract, ScanService, ScanWorker and the planner at once.

#### `planning-budgeted-and-resumable`

*B10, P4, M8*

appendManifest gains a plan-state parameter so a page and its cursor commit in one transaction — a request that dies has either committed both or neither. The manifest freeze is gated on a persisted 'the source said done' flag, not on the walk having returned. P4 has no independent fix and closes here; carry forward its two warnings: do NOT reduce pageSize to make planning faster (6.3 s of the 8 s is DB wait across 809 queries; halving the page doubles them), and if throughput ever needs work the lever is the per-page boundaryGroup SELECT, which is load-bearing for collation correctness and must not be removed without replacing the guarantee. M8: durableScanContext must produce the two facts the fingerprint guard needs — project structure and choice sets — or the guard that is supposed to abort a run when the project changes underneath it is comparing nothing. Do NOT let two attempt-counting mechanisms exist: planStep calls into wave 5's mechanism. appendManifest must also bump uv_scan_run.updated_at, or a healthy multi-request planning phase looks abandoned to wave 10's reaper and is expired out from under itself.

<details><summary>22 files</summary>

- `php/Scan/ScanPlanner.php`
- `php/Scan/ScanPhase.php`
- `php/Scan/ScanWorker.php`
- `php/Scan/ScanService.php`
- `php/Scan/ScanStore.php`
- `php/Scan/SqlScanStore.php`
- `php/Scan/ArrayScanStore.php`
- `php/Scan/ScanPolicy.php`
- `php/Scan/ScanRetention.php`
- `php/Scan/WorkBudget.php`
- `UniversalValidator.php`
- `js/scan.js`
- `config.json`
- `tests/scan_worker_php.php`
- `tests/scan_store_contract.php`
- `tests/scan_store_php.php`
- `tests/scan_service_php.php`
- `tests/scan_client_js.cjs`
- `tests/scan_schema_php.php`
- `tests/mysql/cases/planning.php`
- `docs/INSTALL.md`
- `CHANGELOG.md`

</details>

### Wave 10

The safeguards get wired last among the behavioural waves, because every one of them is dangerous before its dependency lands: the cron before paged retention is a 160-second statement per generation; the cron before wave 4 deletes other projects' data; the cron before wave 5's epoch bump lets a browser worker that returns after the reap commit findings into a run already marked expired; and B7's shared fingerprint helper before H2 makes every run abort on its first work pass with 'the validation rules changed during this scan'. All four dependencies are now in.

#### `wire-the-safeguards-and-module-lifecycle`

*B6, B7, H9, H10, H11, B6a, M17*

Declare the crons in config.json (there is no crons key at all today) and call expireAbandoned defensively at the head of ScanService::start beside the existing reapCancelled, so pressing Start is always sufficient to unstick a project, with failures swallowed the same way. The reaper's predicate uses wave 5's progress_at, bumps lease_epoch in the same statement, produces the same row ScanOutcome::derive(['expired' => true]) would, and leaves an audit trail — a scan that vanished from 'running' with no event is indistinguishable from one that was never started. B7: extract the fingerprint computation into one function with two callers so the configuration-changed guard finally runs; both call sites must take one argument bag, or they will disagree exactly as H2 describes. H9: the detail budget gates at the findings merge in ScanWorker::batch, before the batch is handed to commitBatch and after evaluate() has produced the findings so the COUNT stays correct — past the budget the run keeps counting and stops storing, which is the promise the setting text makes. H10 part 1 only: a group-scoped run discloses no counts or totals until its scope is proved at the target fence; part 2 waits for wave 11's read path. H11: make a failed change-log read distinguishable from an empty one, because today it means 'the project did not change during the scan'. M17: redcap_module_system_disable releases every worker slot, terminates every active run and stops the crons — and deletes nothing without an explicit per-installation opt-in.

<details><summary>26 files</summary>

- `config.json`
- `UniversalValidator.php`
- `php/Scan/ScanService.php`
- `php/Scan/ScanRetention.php`
- `php/Scan/ScanPolicy.php`
- `php/Scan/ScanWorker.php`
- `php/Scan/ScanPlanner.php`
- `php/Scan/ScanPromotion.php`
- `php/Scan/CatchUp.php`
- `php/Scan/SourceFence.php`
- `php/Scan/Schema.php`
- `php/Scan/SqlScanStore.php`
- `php/Scan/ScanDb.php`
- `php/Scan/ScanStore.php`
- `php/Scan/ArrayScanStore.php`
- `php/Scan/ScanAuthorization.php`
- `php/ScanPageView.php`
- `pages/scan.php`
- `js/scan.js`
- `tests/scan_service_php.php`
- `tests/scan_security_php.php`
- `tests/scan_schema_php.php`
- `tests/hosting_php.php`
- `tests/mysql/cases/cron.php`
- `docs/INSTALL.md`
- `CHANGELOG.md`

</details>

### Wave 11

The report is last of the feature work and could not have been built earlier: SqlScanStore::findings filters on generation_id alone, and generation_id was the literal 1 for every run of every project, so a findings page or CSV built before wave 4 is a cross-project data leak rather than a feature. It also needs wave 2's rule identities (or it persists the wrong ordinal-to-rule mapping authoritatively), wave 7's presentation layer and persisted clean flag, wave 8's pinned row shape and paging cursor, and wave 10's policy-revision setting for the read-time value gate.

#### `findings-report-and-export`

*B5, L8, M5*

findings() becomes findings($pid, $generationId, array $scope, array $filter, $afterId, $limit) with $scope a MANDATORY confinement whose absent dag key throws rather than defaults — a read path that can be called without a scope is one that will eventually be called without a scope. Keyset paging, not the 1,000-row one-shot table the user guide promises. pages/export.php stops being a permanent 503; scan-findings joins auth-ajax-actions; the CSV claim in config.json:4 becomes true rather than being deleted. M5's writer half lands here: uv_scan_dim gets its first producer (with the project_id wave 3 made NOT NULL), and reason_bits stops being written as a constant 0 — split the reason once and reuse it so the identity tuple is unchanged. L8: invert the order in work(), status() and cancel() so entitlement is decided before the run row is read, and make the two refusals byte-identical, closing the oracle that distinguishes 'no such run' from 'a run you may not see'. Preserve README.md:512-517's three distinct ways of saying incomplete; they are correct and the exporter must carry them.

<details><summary>26 files</summary>

- `config.json`
- `pages/export.php`
- `pages/scan.php`
- `js/scan.js`
- `UniversalValidator.php`
- `php/Scan/ScanStore.php`
- `php/Scan/SqlScanStore.php`
- `php/Scan/ArrayScanStore.php`
- `php/Scan/ScanService.php`
- `php/Scan/ScanReport.php`
- `php/Scan/FindingRowMapper.php`
- `php/Scan/ScanPolicy.php`
- `php/Scan/ScanRetention.php`
- `php/Scan/ScanWorker.php`
- `php/Scan/ScanPlanner.php`
- `php/ScanPageView.php`
- `php/ScanColumns.php`
- `php/ScanDimensions.php`
- `php/MessageCatalog.php`
- `tests/scan_report_php.php`
- `tests/scan_export_php.php`
- `tests/scan_store_contract.php`
- `tests/scan_page_php.php`
- `tests/scan_client_js.cjs`
- `tests/mysql/cases/report.php`
- `CHANGELOG.md`

</details>

### Wave 12

Documentation last, because a doc rewritten against a half-finished rebuild goes stale inside a wave. Everything the docs describe is now true.

#### `documentation-matches-the-build`

*M19, P10*

Replace README.md:428-519 wholesale: keep the paragraph on why the scan exists, delete the 1.7.0 streaming-writer and chunked-read prose and the 75%/70% budget, and move the withdrawal notice to the top. Rewrite the config.json descriptions that promise behaviour, and the USER_GUIDE section that describes a one-shot table that is now keyset paging. Add tests/docs_claims_php.php: a small set of assertions that fail when a doc names a setting key, table count or page that does not exist, so the next round of rot is caught by CI rather than by a reviewer. Include the operator-facing consequences of this release: version-1 scan data is discarded on upgrade, the upgrade blocks an HTTP request while it ALTERs, and the scan is disabled until it completes.

<details><summary>8 files</summary>

- `README.md`
- `docs/USER_GUIDE.md`
- `docs/TESTING.md`
- `docs/INSTALL.md`
- `config.json`
- `CHANGELOG.md`
- `tests/docs_claims_php.php`
- `.github/workflows/parity.yml`

</details>

## Risks

1. WAVE 4 IS ONE VERY LARGE COMMIT AND THERE IS NO SAFE WAY TO MAKE IT SMALLER. B1, B2 and B11 converge on two regions — UniversalValidator::durableEvaluateRecord() 2950-3030 and SqlScanStore::commitBatch() 392-495 — and three independent specs say any subset leaves the store inconsistent. Mitigation is the wave-1 harness split, the two-project fixture, and a seam test entering through durableScanContext(); nothing in tests/ references durableScanContext or durableEvaluateRecord today, so that seam is where the risk actually lives.

2. THE IDENTITY DISCONTINUITY MUST BE ONE RELEASE. H1/H2 (wave 2) change every stored rule_source_id; B2 (wave 4) changes the finding tuple; B1 (wave 4) changes the generation space. No build may be released between wave 2 and wave 4, or an installation gets two discontinuities and a middle state where old and new identities coexist in generation 1. Say this in the changelog as one identity discontinuity at one moment, not as three fixes.

3. THE MIGRATION DELETES DATA AND BLOCKS A REQUEST. Version-1 scan findings, candidates, groups and dims are deleted on every installation, and the ALTERs run inside redcap_module_system_enable / save_configuration — an HTTP request that at the plan's target size blocks for minutes over an ~800 MB uv_finding. Both must be in docs/INSTALL.md and the changelog before wave 3 ships, together with the manual path (Schema::plan(1) prints the statements, which is what that method exists for).

4. ANY DDL DISCOVERED AFTER WAVE 3 COSTS A SECOND ALTER PASS. Version 3 against a piloted server means re-walking uv_finding. Waves 5 through 11 must review their specs against the frozen version 2 BEFORE wave 3 ships; the report work in wave 11 is eight waves downstream of the migration and is the likeliest source of a late column request. The two I deliberately deferred rather than folded in are an index on value_expires_at (wave 8 pages those statements instead) and any per-finding locus column (B2's discriminator lives inside the HMAC).

5. WAVE ORDER IS LOAD-BEARING FOR RETENTION AND THE CRON, IN THREE SEPARATE WAYS. Wiring the cron before wave 4 lets one project's expired run delete every project's findings installation-wide. Wiring it before wave 8 executes a single 160-second DELETE holding row locks throughout. Wiring it before wave 5's epoch bump creates a bug that does not exist today: a browser worker returning after the reap still holds the matching epoch, passes the commit fence, and writes findings into a run already reported expired. All three are why the cron is wave 10.

6. B7 MUST NOT MERGE BEFORE H2. B7 extracts the fingerprint into one helper with two callers; if the two are given different settingsCount — exactly what H2 describes — every run aborts on its first work pass with "the validation rules changed during this scan". Scheduled wave 10 against wave 2, but if any resequencing happens this is the pairing that breaks loudest.

7. js/scan.js IS EDITED IN FIVE WAVES (1, 6, 7, 9, 11) AND IS 253 LINES. It is single-owner within each wave, but the PHASE and COVERAGE label tables will drift against ScanPageView across waves. Wave 1 must move both tables into ScanPageView, or wave 6's new coverage constant and wave 7's new status keys will each reproduce L1 independently. The repo's own memory note records this exact failure mode.

8. THE BASELINE IS SHARED AND MUTABLE. 22 PHP suites, 18 JS suites and 285 MySQL checks green is the return point for every wave, but other agents run tests/mysql/run.php against the same uv_test database and it drops the uv_* tables — two measurement passes were destroyed that way during the audit. Wave 1's harness split must parameterise the schema name; until it does, no wave can trust a red MySQL result.

9. THE WORKING TREE IS NOT CLEAN AND WAS BEING WRITTEN TO DURING THE AUDIT. Twelve modified tracked files and seven untracked tools/temporal_*.php, including an unreviewed php/Scan/DbError.php that SqlScanStore already calls. Waves 1 and 2 must start by reviewing and committing that work, not by re-implementing it. Every line number in every spec is HEAD-relative (2600e8d): the working tree already drifts about 9 lines in UniversalValidator.php and 46-52 in SqlScanStore.php, so anyone diffing citations against the tree will find them shifted.

10. THE SUITE CANNOT DISTINGUISH SEVERAL BROKEN BUILDS FROM THEIR FIXES, AND WILL STAY GREEN THROUGH MISTAKES. Proved twice during the audit: the full H1+H2 fix was applied to a temp copy and all 22 suites plus 286 MySQL checks stayed green, and the same is true of the B1 axis because three tests independently hand-write the value production derives, each on the axis that makes the code pass (tests/mysql/run.php:1090 injects the DAG id directly; tests/scan_security_php.php:224 puts a NAME in group_id and asserts it matches; tests/scan_page_php.php:383 is the only test calling scanScope() and feeds it to the legacy name-axis consumer). Treat a green suite as necessary and not sufficient for waves 2, 4 and 6, and require the provenance and seam tests named in those items as acceptance criteria rather than as follow-up.

11. TWO VERIFICATION AGENTS FAILED THEIR FIRST PASS BUT RETURNED COMPLETE SPECS ON THE SECOND. The B2+B11 and H1+H2 specs are present, executed against a live MySQL and a real evaluation harness, and are not thin — the risk the brief anticipated did not materialise. The residual is that both were written against HEAD while the tree was being modified, so their line citations need re-anchoring at implementation time, not their reasoning.

12. FIVE WAVES CONTAIN A SINGLE WORK ITEM (4, 9, 10, 11, 12) AND WAVE 3 IS EFFECTIVELY SERIAL. That is the honest consequence of six files being needed by nine to eleven clusters each, not a scheduling failure — but it means the critical path is long and cannot be parallelised by adding people. The only real lever is wave 1's harness split, which is what lets waves 1, 2, 3, 5, 6, 7 and 8 carry two or three items each.

## File contention

| File | Wanted by | Contention |
|---|---|---|
| `php/Scan/Schema.php` | B1/M18 scoping, B2/B11 identity, B8/B12 wedge, B9/H12 fencing, B4/H15 outcome, B10 planning, B5 report, H3/M16 sharp, M13/M17 parity, performance P7/P9, B3 dag | heavily-contended |
| `UniversalValidator.php` | B1 scoping, B2 identity, H1/H2 rule identity, B3/M1/M2 dag, B4/H18 outcome, B5/M5 report, B6/H8 unwired, H3/H7/M16 sharp, M17 lifecycle, B10/M8 planning, P1/M12 performance | heavily-contended |
| `php/Scan/SqlScanStore.php` | B1 scoping, B2/B11 identity, B8/B12 wedge, B9/H12/M4/M10 fencing, B4/H15 outcome, B10 planning, B5 report, H3 sharp, M13/M15 parity, performance P2/P6/P11/M9 | heavily-contended |
| `php/Scan/ScanWorker.php` | B1 scoping, B2/B11 identity, B8/B12/H13 wedge, B9 fencing, B4/H18 outcome, B10 planning, B6/B7/H9 unwired, B5/M5 report, performance P3/P5 | heavily-contended |
| `php/Scan/ScanService.php` | B1 scoping, B8/B6a wedge, B9/H12 fencing, B3/M3 dag, B4/L4/L5 outcome, B10/M8 planning, B6/B7/H10 unwired, B5/L8 report, L6 sharp, P1/M11/M12 performance | heavily-contended |
| `php/Scan/ScanStore.php` | B1/M15 scoping, B2/B11 identity, B8 wedge, B9/M4 fencing, B4 outcome, B10 planning, B5/B5-N1 report, M13/NEW-2 parity, P2/P11 performance | heavily-contended |
| `php/Scan/ArrayScanStore.php` | B1 scoping, B2/B11 identity, B8 wedge, B9/H12 fencing, B4 outcome, B10 planning, B5 report, M13 parity, P2/P6 performance | heavily-contended |
| `tests/mysql/run.php` | all thirteen clusters | heavily-contended |
| `tests/scan_store_contract.php` | B1 scoping, B2/B11 identity, B8 wedge, B9/M4 fencing, B4 outcome, B10 planning, B5 report, M13 parity, P2 performance | heavily-contended |
| `tests/scan_worker_php.php` | B1 scoping, B2/B11 identity, B8/B12 wedge, B9 fencing, H1/H2 rule identity, B4 outcome, B10 planning, B5 report, P3/P5 performance | heavily-contended |
| `js/scan.js` | H4-L3 client, B8/B12 wedge, B9/H12/M10 fencing, B3 dag, B4/L4/L5 outcome, B10 planning, B6/H10 unwired, B5 report | heavily-contended |
| `CHANGELOG.md` | nine clusters | heavily-contended |
| `php/Scan/ScanPlanner.php` | B1 scoping, H1/H2/N3 rule identity, B3 dag, B10/M8 planning, B7 unwired, B5/M5 report, P5 performance | heavily-contended |
| `php/Scan/ScanRetention.php` | B1-extra/M15 scoping, B6a wedge, B10 planning, B6 unwired, B5 report, M13/M17 parity, P6 performance | heavily-contended |
| `php/Scan/ScanPromotion.php` | B1 scoping, B8 wedge, B3 dag, B4/H15/H18 outcome, B7 unwired, P11 performance | heavily-contended |
| `tests/scan_schema_php.php` | B1/M18 scoping, B8 wedge, B10 planning, B5-N2 report, M13/M17 parity, P7/P9/M11 performance, B4/H15 outcome | heavily-contended |
| `tests/scan_service_php.php` | B1 scoping, B8 wedge, B10 planning, B7/H10 unwired, B5/L8 report, P1 performance, L4/L5 outcome | heavily-contended |
| `config.json` | B8 wedge, H1/H2-N1 rule identity, B10 planning, B6 unwired, B5 report, M17/M19 parity | heavily-contended |
| `pages/scan.php` | M7/L2/L3/C1 client, M10 fencing, B3 dag, B5 report, M17 lifecycle | contended |
| `php/ScanPageView.php` | B3/B3c dag, B4/H15 outcome, H10 unwired, B5 report, L3 client | contended |
| `tests/scan_security_php.php` | B3/B3c dag, B2 identity, H9/H10 unwired, H3/H7 sharp, H15 outcome | contended |
| `tests/scan_client_js.cjs` | H4-L1 client, B12 wedge, B10 planning, L4/L5 outcome, B5 report | contended |
| `tests/scan_page_php.php` | M7/L2/L3 client, B3/M1/M3 dag, H15 outcome, B5 report | contended |
| `php/Scan/RecordManifestSource.php` | B3/B3b dag, H18 outcome, B10 planning, H16 sharp | contended |
| `php/Scan/ScanDb.php` | H11 unwired, M14 fencing, M13 parity, P2/P8 performance | contended |
| `php/Scan/ScanPolicy.php` | B4c outcome, B10 planning, B6/H8 unwired, B5 report | contended |
| `php/Scan/UniqueFinalizer.php` | B1/B1-uniq scoping, B4 outcome, M13 parity, P7/P8 performance | contended |
| `docs/INSTALL.md` | B1/M18 scoping, B6a wedge, B10 planning, M17/M19 parity, P10 performance | contended |
| `php/ScanCapabilities.php` | H16/H17 sharp, P1 performance | contended |
| `php/Scan/ScanAuthorization.php` | B3c dag, L6 sharp, H10 unwired | contended |
| `php/Scan/ScanOutcome.php` | B3 dag, B4/H15/H18 outcome | contended |
| `php/Scan/CatchUp.php` | B3b dag, H11 unwired, P3 performance | contended |
| `php/Scan/DbError.php` | H3 sharp, P2 performance | contended |
| `php/Scan/SourceFence.php` | H11 unwired, P3 performance | contended |
| `php/Scan/WorkBudget.php` | B10/P4 planning, P5 performance | contended |
| `README.md` | B5 report, M19 docs | contended |
| `docs/USER_GUIDE.md` | H1/H2 rule identity, B10 planning, B5 report, M19 docs | contended |
| `docs/TESTING.md` | M7 client, B5 report, M19 docs | contended |
| `php/Scan/Hmac.php` | B2 identity | exclusive |
| `php/Scan/RollupBuilder.php` | B1 scoping | exclusive |
| `php/Branching.php` | H2 rule identity | exclusive |
| `php/Scan/ScanPhase.php` | B10 planning | exclusive |
| `php/Scan/WorkerSlots.php` | NEW-2 parity | exclusive |
| `pages/export.php` | B5 report | exclusive |
| `php/ScanColumns.php` | B5/M5 report | exclusive |
| `php/ScanDimensions.php` | B5/M5 report | exclusive |
| `php/MessageCatalog.php` | B5/M5 report | exclusive |
| `php/Scan/ReasonCode.php` | M5 report | exclusive |
| `tests/mysql/bootstrap.sql` | B1/M18 scoping | exclusive |
| `tools/measure_scan.php` | B3 dag | exclusive |

---

## Findings added during implementation

These were not in the adversarial review. Each was found by building the thing the review asked for
and discovering what it caught.

### W1-N1 — `WorkerSlots::idleAbove()` has no production caller, so lowering the concurrency limit does nothing

Found by the wiring test (`tests/scan_wiring_php.php`), which is itself the review's §7 countermeasure.
`scan-system-max-concurrent-projects` is additive in one direction only: raising it provisions rows on
the next settings save, and `idleAbove()` — the method that would retire rows above a lowered limit —
is called by nothing. An administrator who lowers the limit to shed load sees no effect at all, and
the setting text does not say so.

**Severity:** medium. It fails in the safe direction (too much concurrency, never too little), but it
is a control that reports success and does nothing, which is the same class as B6.
**Schedule:** wave 10, with the rest of the safeguard wiring.

### W1-N2 — `WorkerSlots::renew()` has no production caller, so a slot lease expires under the worker holding it

Same source. A worker leases a slot with a TTL (`ScanService::SLOT_TTL`) and never renews it. A batch
that runs longer than the TTL therefore keeps working while its slot is, as far as the semaphore is
concerned, free — so a second worker can lease the same slot and the installation-wide concurrency
limit is exceeded by however many workers are in that state.

The batch budget is 3.0 s for a browser pass, so this is not reachable today through the browser. It
becomes reachable the moment wave 9 gives planning its own budgeted phase and wave 10 introduces a
cron pass, both of which run longer.

**Severity:** medium now, high after wave 9. **Schedule:** wave 5 (worker control flow) — the worker is
already being opened there, and renewing a lease belongs beside claiming one.

### W1-N3 — the plan's case-file names do not match the harness split

The harness partition produced twelve case files, not the nine the plan named: `catchup` content lives
in `cases/fence.php` and `summary` content in `cases/rollup.php`, and `slots`, `fault`, `walk`,
`planning` and `worker` are new. Later waves that cite a case filename must be remapped:

| plan says | actually |
|---|---|
| `tests/mysql/cases/catchup.php` | `tests/mysql/cases/fence.php` |
| `tests/mysql/cases/summary.php` | `tests/mysql/cases/rollup.php` |
| `tests/mysql/cases/perf.php` | not created; wave 8 creates it |
| `tests/mysql/cases/dag.php` | not created; wave 6 creates it |
| `tests/mysql/cases/cron.php` | not created; wave 10 creates it |
| `tests/mysql/cases/report.php` | not created; wave 11 creates it |
| `tests/mysql/cases/ratelimit.php` | not created; wave 5 creates it |

### W1-N4 — three concessions in the new MySQL fixture are wave 4's to remove

The two-project fixture cannot yet do the one thing it exists for, because the schema it runs against
is not project-scoped. The concessions are named in the case-file headers and repeated here:

- `cases/uniqueness.php` and `cases/rollup.php` hold the neighbouring project apart **by generation**,
  which is precisely the isolation production does not have. Once generations are per project, put the
  neighbour in the same generation and delete the concession.
- `support/fixture.php`'s `uv_clear_project()` cannot scope its `uv_finding` DELETE by project and
  excludes the neighbour by record-id prefix instead.
- `cases/retention.php` is missing the assertion that purging one project leaves the neighbour's
  findings alone — it is red today, which is B1 reproduced inside the suite.

### W4-D1 — superseding the PREVIOUS generation is deferred to wave 8, deliberately

B1 part 3(c) asks for a bounded pass that closes the previous generation's active findings when a
run reaches terminal complete, advancing `scan_run.supersede_cursor`. It is **not** in wave 4, and
this is the reasoning, recorded so the column does not become another thing that exists and is never
written.

**It is no longer a correctness fix.** Its original purpose was to stop a new run colliding with the
previous run's active rows. Once the identity key became
`(project_id, generation_id, finding_identity, active_slot)` and generations became a per-project
sequence, a new run writes under a generation no earlier row uses, so there is nothing to collide
with. Every reader is scoped to one project and one generation:

| reader | predicate |
|---|---|
| `RollupBuilder::step` | `project_id`, `generation_id` |
| `SqlScanStore::findings` | `project_id`, `generation_id` |
| `UniqueFinalizer` (all 19 sites) | `project_id`, `generation_id` |
| `ScanRetention::purgeRuns` | `project_id`, `generation_id` |

So an older generation's rows are history, not contamination. What remains is that "the current
state of this project" is a per-generation question rather than a single query, and that
`valid_to_seq` is written only by the within-run supersede.

**Why wave 8 and not now.** The pass has to be paged — the one unpaged statement in this module
measured 160 seconds over 500,000 rows while holding row locks — and paging across requests needs
either a phase the worker can advance or a cron pass. Wave 8 builds the paged retention machinery
and wave 10 declares the cron. Building a second, half-paged mechanism here would be a worse version
of what wave 8 is about to build properly.

**What this leaves.** `scan_run.supersede_cursor` exists in schema version 2 with no writer until
wave 8. That is deliberate and is the one column in this migration deliberately ahead of its code —
adding it later would mean a second `ALTER` pass over an ~800 MB table, which is the trap version 1
fell into. Wave 8 owns it.
