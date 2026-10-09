# Validation scan (durable rebuild) — adversarial pre-submission review

**Target:** `origin/main` at `2600e8d` — version **1.9.10**.
**Reviewed as:** a REDCap Consortium module reviewer plus a distributed-systems engineer, with
execution rather than reading wherever a claim could be settled by running something.

**Files read in full:** `pages/scan.php`, `pages/export.php`, `js/scan.js`, `config.json`,
`UniversalValidator.php` (hooks, AJAX, `durableScanContext`, `durableEvaluateRecord`, `scanPlan`,
`scanRecord`, `ruleFindings`), all 22 classes under `php/Scan/`, `php/ScanPageView.php`,
`php/ScanColumns.php`, `php/ScanDimensions.php`, `php/ScanCapabilities.php`, the scan test suites,
`tests/mysql/run.php`, `.github/workflows/*`, `CHANGELOG.md` 1.8.0–1.9.10, and
`reports/scan-rebuild-plan-2026-08-17.md`.

**Baseline established before any finding was written** (all green):

| Suite | Result |
|---|---|
| 22 PHP suites | ~8,960 assertions, 0 failures (PHP 8.3.32) |
| 18 JS suites | ~3,100 assertions, 0 failures |
| `tests/mysql/run.php` against a real MySQL 8.0.46 | 285 checks, 0 failures |

Everything below lives underneath that green tick.

---

## 1. Verdict

**Do not submit, and do not pilot again until the blocking list is closed.** The durable scan in
1.9.10 cannot complete a second run on any project, cannot show an operator a single finding it
found, and — in the two configurations most likely to be used first (a Data Access Group user, or
any project whose findings are duplicates) — reports a clean or complete result over work it did
not do.

The engineering underneath is careful: the phase machine, the store contract, the HMAC identities
and the outcome derivation are all better than most modules in the repository. The failure is not
in the parts. It is that a large fraction of the safety logic **is written, unit-tested, and never
called**, and that the one seam nothing tests — `durableEvaluateRecord`, where the rule engine's
output becomes database rows — is where every live pilot has died.

Counts: **12 blocking, 17 high, 19 medium, 7 low**, plus 11 measured performance findings (§5a).

Method note: eight review dimensions were worked independently and every finding was then attacked
by a second reviewer whose brief was to refute it. Five of those dimensions arrived at the same root
cause from different directions. Two findings were refuted and dropped; several severities moved in
both directions; one claim of my own — that the cross-project rollup is a live *disclosure* — was
corrected to stored corruption, because nothing renders those rows yet. Where a claim survived only
as reasoning, it says so.

---

## 1a. What already works — measured, not assumed

Nothing in the repository drives `ScanService::start()` through `work()` to a terminal state, so
this review built that harness (`tools/temporal_e2e.php`): a module over one real mysqli
connection, a `\REDCap` stand-in, and the REDCap-side tables the code reads directly
(`redcap_projects`, `redcap_log_event`, `redcap_record_list`, `redcap_data`), with the schema
installed through the real administrator path and a **fresh module instance per pass**, because
every pass is its own HTTP request in production.

On a healthy, undisturbed first run the feature works end to end:

| Scenario | Result |
|---|---|
| 12 records | one browser pass; `terminal=complete`, `coverage=complete-through-fence` |
| 60 records | 5 passes |
| 800 records | 8 passes, ~70 s, claim size adapting 25 → 175, no unbounded memory growth |
| 3-arm record list | duplicate rows correctly collapsed by the manifest's record hash |
| sharded log table (`redcap_log_event7`) | resolved correctly |
| cancel mid-run | `cancelling` → next worker finishes it → `terminal=cancelled`, `coverage=partial` |
| edit mid-run | reconciled, aggregate written, record requeued |

That matters for the fix plan: the phase machine, the budget, the manifest and the fence are not
what is broken. Three specific conditions wedge a run permanently, each of them holding the
project's only slot with nothing to reap it — a second scan of any project that found something
(B1), a record edited mid-run that still violates the same rule (B11), and any failed REDCap read
(B12).

---

## 2. Why the pilot fails — reproduced

The pilot's last symptom, recorded in `CHANGELOG.md` 1.9.9, was every batch of a 39-record run
failing identically with *"the database refused to store these findings"*, and the root cause was
never identified. It is this:

### `generation_id` is the constant `1`, for every run of every project

`UniversalValidator.php:2879`

```php
$gen = isset($opts['generation']) ? (int) $opts['generation'] : 1;
```

No caller anywhere passes `generation`. `ScanService::start()` → `durableScanContext()` →
`ScanPlanner::begin()` all take the default. Meanwhile `php/Scan/Schema.php:250` declares

```sql
UNIQUE KEY uq_active_identity (generation_id, finding_identity, active_slot)
```

and `finding_identity` is a stable HMAC over (record, event, instance, host form, field, rule
source id, reason code) — deliberately *not* the value, so that the same problem found twice is
the same row. Nothing supersedes the previous run's active rows before a new run inserts. So the
**second scan of a project inserts identities that are already there**, the insert raises
`SQLSTATE 23000 Duplicate entry`, `SqlScanStore::commitBatch()` rolls the whole batch back, and the
worker hands the records back and retries them — forever.

Reproduced against a real MySQL 8.0.46 in `STRICT_TRANS_TABLES`, driving the shipped
`SqlScanStore`:

```
=== SCENARIO 1: the SAME project scanned twice (generation_id is always 1) ===
run 1: batch of 2 records -> COMMITTED
findings stored after run 1: 2
run 2: batch of 2 records -> REFUSED: the database refused to store these findings, so nothing
                             from these records was kept: Duplicate entry '...' for key '...'
findings stored after run 2: 2
```

The pilot wrote 1,815 findings on an earlier build (recorded in 1.9.6). Every run after that was
run 2.

**A fresh install fails the same way** whenever one rule produces two findings at one location.
`UniversalValidator.php:576` emits one finding per checked hidden code for a `@UVCHOICES` rule on a
**checkbox** field, all with reason `hidden-choice`, so two ticked hidden options collide on the
first batch of the first run:

```
=== SCENARIO 2: two findings on ONE field in ONE context (checkbox, two hidden choices) ===
checkbox run: batch of 1 records -> REFUSED: ... Duplicate entry '...' for key '...'
findings stored: 0
```

**And the failure has no exit.** `ScanWorker.php:528-546` releases the claim on a refused commit,
but `attempts` is only incremented *inside* the transaction that rolled back, so the retry cap
(`scan-system-record-attempts`, default 3) never counts:

```
=== SCENARIO 5: a refused batch does not increment attempts ===
record state=0 attempts=0   (state 0 = pending; handed straight back, attempts stays 0 forever)
```

The run therefore never reaches a terminal state, never releases the project's one active slot, and
burns a full REDCap request plus a full `getData()` per retry. That is the "forty batches,
identically" the changelog describes, and it is also a self-inflicted load problem on a shared
server.

Reproduction harness: `tools/temporal_pilot_repro.php` (run instructions in Appendix A).

---

## 3. Blocking

### B1 — `generation_id` is constant, so nothing in the store is project-scoped

Beyond the duplicate-key failure above, the constant generation has four further consequences,
because `uv_finding`, `uv_unique_candidate`, `uv_unique_group` and `uv_scan_dim` **carry no
`project_id` column at all** and every query over them filters by generation alone.

**One project's run cannot finish because of another project's groups (executed).**
`UniqueFinalizer::status()` (`php/Scan/UniqueFinalizer.php:488-499`) counts groups across the whole
generation. With one project holding a `collision` group and a `new` group, it answered
`done=false, blocking=1, pending=1` to a *different* project's run — and `ScanPromotion::facts()`
refuses to promote while `uniqueDone` is false. That second run can never reach a terminal state and
holds its project slot indefinitely. Worse, `nextUnfinished()` will hand the first project's group to
the second project's worker, which re-reads it through a read closure bound to the wrong project.

**Duplicates are silently skipped (executed).** `UniqueFinalizer::discover()`
(`php/Scan/UniqueFinalizer.php:167-182`) uses `SELECT MAX(group_hmac) … WHERE generation_id = ?` as
its keyset cursor and then discovers only groups whose hash sorts *above* it. Once any project has
discovered a high hash, every later candidate group below it is never discovered — no group row, no
verification, no duplicate finding — and `status()` reports `done=true`. A missed `@UVUNIQUE` result
presented as a clean one.

**Cross-project aggregates (executed).** `RollupBuilder::step()` (`php/Scan/RollupBuilder.php:70`)
selects `WHERE generation_id = ? AND active_slot = 1`. Rolling up project 101 counted project 202's
findings and wrote project 202's instrument and DAG names into project 101's summary:

```
aggregates recorded against PROJECT 101's run:
  rollup-check      checkchar                    7
  rollup-group      group_A                      2
  rollup-group      SECRET_dag_B                 5     <- another project's DAG name
  rollup-instrument form_of_project_A            2
  rollup-instrument SECRET_form_of_project_B     5     <- another project's instrument name
  rollup-reason     checkdigit                   7
```

`SqlScanStore::findings()` (`:776`) has the same filter. **It has no production caller today**, and
`ScanService::status()` returns no axis data, so nothing currently *renders* another project's names
— this is stored corruption now and a disclosure the moment the Task 7 report reads either surface.
Stating it precisely matters: the first draft of this review called it a live disclosure, and the
verification pass was right to correct that.

**Cross-project deletion (executed).** `ScanRetention::purgeRuns()`
(`php/Scan/ScanRetention.php:114-117`) deletes `uv_finding`, `uv_unique_candidate`,
`uv_unique_group` and `uv_scan_dim` **by generation id**:

```
findings, both projects: 2
purged 1 run(s) of project 111 -> findings left across ALL projects: 0
```

One project's retention purge destroys every project's findings on the installation.

**Uniqueness contamination.** `UniqueFinalizer` keys every query on generation only, so run 2 sees
run 1's `unique_group` rows already `published` and skips them, while stale candidates from run 1
stay in the table for records whose values have since changed. Duplicates are then decided over a
union of two runs.

*Fix:* add `project_id` to the four tables and to every key and query; make the generation a real
per-project sequence (a `run_seq`/generation counter on the project) and supersede the previous
generation's active rows at run start, which is what `valid_to_seq` and `active_slot` were designed
for.

### B2 — Two findings at one location collide, so the first run of a fresh project can fail too

`UniversalValidator.php:576` (checkbox `@UVCHOICES`) and `Hmac::findingIdentity()`
(`php/Scan/Hmac.php:86`) between them make two findings on one field in one context byte-identical.
`durableEvaluateRecord()` does not deduplicate, and `insertFinding()` inserts one row per finding.

Demonstrated through the real evaluation path — a checkbox with three options, a rule hiding two of
them, and a record with both hidden options ticked (`tools/temporal_choices_identity.php`):

```
findings produced for ONE record: 2
  #1 field=symptoms reason=hidden-choice identity=29d95b40d9f9ca45416440c8...
  #2 field=symptoms reason=hidden-choice identity=29d95b40d9f9ca45416440c8...

COLLISION: 1 identity value produced more than once in ONE record.
```

Independently reproduced by two reviewers, on both halves (evaluation and storage).

*Fix:* either include a per-location discriminator (the value fingerprint, or the finding's
ordinal within the record) in the identity, or collapse same-identity findings before commit and
carry the extra detail in `reason_bits`.

### B3 — A DAG-scoped run scans nothing and calls it complete

`ScanService::start()` takes the scope from `ScanPageView::scanScope()`, which resolves it through
`\REDCap::getGroupNames(true, $rights['group_id'])` — a DAG **unique name**
(`php/ScanPageView.php:225-226`). `ScanPlanner::stream()` then filters the manifest with

```php
if ($dag !== null && (string) $row['dag'] !== (string) $dag) { $stats['outOfScope']++; continue; }
```

but `RecordManifestSource` supplies `dag` as a **numeric group id** — `redcap_record_list.dag_id`
or `group_id` (`php/Scan/RecordManifestSource.php:98`), or the `__GROUPID__` value from the data
table (`:376`). A name is compared with an id, nothing matches, and the manifest is written empty.

An empty manifest satisfies `manifestComplete()` (no row is non-terminal), so promotion derives
`complete` / `complete-through-fence`, and `js/scan.js:77` renders *"Every record was checked,
including changes made while it ran."* over zero records.

*Fix:* compare like with like — store the numeric group id in `scope_dag` (and keep the name for
display only) — and refuse to promote a run whose manifest is empty unless the record source
genuinely returned zero rows for the project.

### B4 — `clean` is computed from two inputs that are structurally always favourable

`ScanOutcome::derive()` (`php/Scan/ScanOutcome.php:136`):

```php
$clean = ($i('violations') === 0 && $i('ruleProblems') === 0);
```

* `violations` is `detail_rows` (`php/Scan/ScanPromotion.php:127`), and `detail_rows` is only
  incremented in `commitBatch` (`SqlScanStore.php:477`). Every duplicate-value finding is written
  by `UniqueFinalizer` (`:387`) and never counted. A project whose violations are duplicates is
  therefore **clean**.
* `ruleProblems` is always 0. `durableEvaluateRecord()` returns `problems`, and `ScanWorker` never
  reads the key — the string `problems` does not appear in `php/Scan/ScanWorker.php`. No caller
  writes a `rule-problem` aggregate anywhere (`addAggregate` is called only from `CatchUp` and
  `RollupBuilder`). A rule that could not be evaluated at all is silently dropped by the durable
  path — the exact failure the module's own documentation says it exists to prevent.

`collection-gap` aggregates are likewise never written, so `gapCount` is always 0 and the plan's
"required rules on never-started instruments are collection gaps" policy is not implemented.

### B5 — There is no way to read a finding

`SqlScanStore::findings()` has **zero callers**. `php/ScanColumns.php` — the declarative column
catalogue, with its own tests — has **zero callers**. `pages/export.php` is a permanent
`503 EXPORT UNAVAILABLE` (`pages/export.php:31-39`). The panel shows a count and nothing else.

This is Task 7 of the rebuild plan and is scheduled after 1.9.x, so it is not a surprise — but it
means the feature as shipped cannot deliver its output, and `config.json:4` still advertises to
administrators that the scan "exports the findings as CSV".

### B6 — Retention, revocation and abandoned-run recovery are written and never invoked

`ScanRetention` is instantiated exactly once in the repository, in `tests/mysql/run.php`. In
production nothing calls `expireValues()`, `purgeRuns()`, `expire()` or `revoke()`. `config.json`
declares **no cron** (the string `cron` does not occur in it), and there is no `redcap_cron` method.

Consequences, all of them contradicting shipped setting text:

* Value previews never expire — and doubly so, because `value_expires_at` is never written by any
  producer either (`ScanPolicy::valueExpiry()` has zero callers), so the expiry query
  `WHERE value_expires_at IS NOT NULL` would match nothing even if it ran.
* Finished runs are never purged.
* An abandoned run is never reaped, so **one closed browser tab holds the project's only active
  slot indefinitely** (`uq_project_active`), and the `scan-system-stale-run-hours` setting governs
  nothing.
* `ScanPolicy::tightened()` — the immediate-revocation check for a project that tightens its
  privacy policy — has zero callers.

### B7 — The configuration-changed guard never runs

`ScanWorker.php:122` compares the run's stored fingerprint against `$this->deps['fingerprint']`,
but `ScanService::work()` (`php/Scan/ScanService.php:206-218`) does not pass a `fingerprint` dep, so
the `isset()` short-circuits. `ScanPromotion::promote()` is likewise called without
`fingerprintNow` or `policyRevisionNow`. A rule edited mid-run is therefore never detected, and a
run half-checked against rules that no longer exist promotes normally.

### B8 — A permanently refused batch has no give-up path

Covered in §2: `attempts` never increments on a rolled-back commit, so `recordAttempts` cannot
trip, the run never becomes terminal, and the project's slot is held. Any persistent write error —
not only the duplicate key — produces an unbounded retry loop.

### B9 — The lease epoch only moves on cancellation, so takeover fencing does not exist

`lease_epoch = lease_epoch + 1` occurs in exactly one statement in the codebase
(`SqlScanStore.php:732`, inside `cancel()`). No takeover path bumps it. Therefore:

* `claimPending()` lets a second worker re-claim a stale worker's rows after 15 minutes while both
  hold the same epoch; the first worker's later commit passes the fence and writes findings for
  records another worker has already committed (which, given B2's identity key, refuses the whole
  batch).
* `releaseClaims()` is fenced on an epoch that never moves, so the documented protection — "a
  worker whose rows were taken over cannot pull them out of the new holder's hands"
  (`SqlScanStore.php:556`) — does nothing.

### B10 — Planning is one request, unbounded and unresumable

`ScanPlanner::stream()` honours a `deadline` and a `memCap`, and `ScanService::start()`
(`php/Scan/ScanService.php:163-172`) passes **neither**. Both guards are therefore `null` and never
fire, so the entire record list of the project is walked, hashed and inserted inside the single
`scan-start` request. `planning` is not a phase the worker can advance, and a walk that returns
`ok=false` finishes the run as failed rather than resuming it (`php/Scan/ScanPlanner.php:190-196`).

On a project large enough to exceed `max_execution_time`, the request dies mid-walk — an uncatchable
fatal — leaving a run row in `planning` that holds the project's active slot with nothing to reap it
(B6). This contradicts the rebuild plan's first non-negotiable ("there is no hard record-count
ceiling") and the panel's own promise that a large project need not finish inside one page load.

*Fix:* give planning a budget and a resumable cursor, exactly as scanning has, or bound it
explicitly and say what the ceiling is.

### B11 — A record edited during a run wedges the run, on the first scan

Catch-up correctly requeues a record that changed while the scan was reading the project. The record
is then examined a second time, produces the same violation, and `insertFinding()`
(`php/Scan/SqlScanStore.php:627`) re-inserts the identity that the first examination already
committed. From that point every batch is refused, exactly as in B1 — but on a **first** run of a
fresh installation, with no prior scan involved.

Demonstrated in the end-to-end harness by editing one record mid-run.

*Fix:* the same one B1 needs — close or supersede the record's existing active findings inside the
transaction that re-examines it, which is what `valid_to_seq` exists for.

### B12 — A failed REDCap export leaves the batch claimed forever

When `getData` fails for a whole batch, `ScanWorker` returns early
(`php/Scan/ScanWorker.php:435-442`) without committing, without calling `releaseClaims()`, and
without counting an attempt. The rows stay `CLAIMED`, the straggler sweep cannot see them for fifteen
minutes, and the phase machine (correctly) refuses to advance over records nobody examined. With no
reaper (B6), the run holds the project's slot indefinitely.

This is the third independent way a run wedges permanently, and the only one that needs nothing more
than a transient REDCap read failure.

---

## 4. High

| # | Finding | Where |
|---|---|---|
| H1 | Findings are attributed to the **wrong rule** when any rule is dropped from the list: `$plan['live']` keeps the original sparse indices while `ScanPlanner::identifyAll()` re-indexes with `array_values()`, and `durableEvaluateRecord()` looks the identity up by `ordinal - 1`. One config-broken rule shifts every later rule's identity. | `UniversalValidator.php:2960-2966`, `php/Scan/ScanPlanner.php:449-464` |
| H2 | `settingsCount` is never passed to `identifyAll()`, so every settings rule is named as an annotation rule; rule identities are wrong from the first run and unstable if the two lists ever change length. | `UniversalValidator.php:2880` |
| H3 | The 1.9.9 diagnostic redacts the thing it was written to preserve: MySQL quotes column and index names with single quotes in several messages, and `safeDbMessage()` replaces every single-quoted run with `'...'`. Our own reproduction printed `Duplicate entry '...' for key '...'` — the key name, the whole diagnosis, is gone. | `php/Scan/SqlScanStore.php:508-538` |
| H4 | `js/scan.js:171` re-issues `scan-work` with `setTimeout(pump, 0)` on any `ok:true` response that did no work (`stop:'waiting'`, `stop:'fenced'`), so a stalled run becomes a hot loop against REDCap from a single open tab. | `js/scan.js:150-178` |
| H5 | The client blanks the note (`text(el('uv-scan-note'), '')`, `js/scan.js:169`) on every successful response, so the server's explanation of why nothing is progressing is written and immediately erased. | `js/scan.js:169` |
| H6 | A synchronous throw from the JSMO transport (no `UVScan.ajax`, framework error) escapes `pump()`'s promise chain, leaves `running = true`, and prints a message that contradicts the server. | `js/scan.js:88-93, 150-152` |
| H7 | `catch (Throwable $e)` at `UniversalValidator.php:2310` is unqualified inside `namespace INSPIRE\UniversalValidator`, so it resolves to a class that does not exist and never catches. A migration or slot-provisioning failure therefore escapes `redcap_module_save_configuration()` and fails the administrator's settings save — precisely what the docblock says must not happen. Demonstrated with a two-line script. | `UniversalValidator.php:2310` |
| H8 | Stored value previews are written with `value_expires_at` NULL and no purge exists (see B6): participant data is retained indefinitely, in a table readable by anyone with design rights. | `UniversalValidator.php:2975-2995`, `php/Scan/ScanRetention.php` |
| H9 | `ScanPolicy::budgetSpent()` has zero callers: the detail budget (`scan-system-max-detail-findings`, `-bytes`) never stops storage. A run can write past the configured ceiling; only the promotion afterwards notices and labels the run truncated. | `php/Scan/ScanPolicy.php:126` |
| H10 | Two DAG safety controls are written, documented, tested and never invoked: `ScanAuthorization::preFenceStatus()` (withhold counts until the scope is provable) and `projectionStillValid()` (invalidate a report after DAG drift). Both have zero callers. | `php/Scan/ScanAuthorization.php:158-200` |
| H11 | Once `fence_target` is stored, a change log that stops answering lets catch-up settle and the run claim `complete-through-fence`. A rotated or truncated REDCap log is a routine administrative act, and `CatchUp::unfenced()` never clears `fence_target`, so a half-walked window still promotes as fenced. | `php/Scan/CatchUp.php:117-147, 259-267`, `php/Scan/SourceFence.php:222-250` |
| H12 | `claim()`, `claimPending()`, `advancePhase()` and `releaseClaims()` catch every `Throwable` and return "refused". A deadlock (1213) or lock-wait timeout (1205) is therefore indistinguishable from "another worker took over", and the worker stops without recording that the database failed. | `php/Scan/SqlScanStore.php:321-324, 380-382, 705-709, 588-591` |
| H13 | A whole-batch read failure commits nothing and increments no attempt counter, so in catch-up the same records are re-claimed forever — the same shape as B8, on a second path. | `php/Scan/ScanWorker.php` (catch-up branch) |
| H15 | `ScanOutcome::mayClaimClean()` and `mustShowGaps()` — the two predicates that decide whether the word "clean" may appear and whether collection gaps must be shown — have no production callers. | `php/Scan/ScanOutcome.php:174-192` |
| H16 | The availability gate probes a hardcoded `redcap_data`, while the walk it gates honours `redcap_projects.data_table`. On an installation with per-project data tables the two disagree: the gate can refuse a project the walk could have listed, or pass one it cannot. | `php/ScanCapabilities.php:380-390` vs `php/Scan/RecordManifestSource.php:437-462` |
| H18 | A record REDCap does not return, after the configured attempts, becomes a **tombstone** — and a tombstone deliberately does not block coverage. A record whose rule fields are all blank is omitted by `getData`, so it is recorded as deleted rather than unexamined while the run still claims complete. | `php/Scan/ScanWorker.php:475-483`, `php/Scan/ScanPromotion.php` |
| H17 | `ScanCapabilities::schemaPrivilege()` loops forever and kills the request with an out-of-memory fatal when `query()` returns an array: `fetchRow()` does `array_shift($q)` on a by-value parameter, so the loop's input never shrinks. | `php/ScanCapabilities.php:355` |

---

## 5. Medium and Low

| # | Finding | Where |
|---|---|---|
| M1 | `@UVUNIQUE` with `scope=dag`: the durable scan keys the group on the DAG value it read, the live check and the save audit use the acting user's group — they disagree for any record whose DAG changed. | `UniversalValidator.php` `collectUniqueCandidates()` |
| M2 | `unique_candidate.scope_key` is hard-coded to `'project'` regardless of the rule's actual scope. | `UniversalValidator.php:3012` |
| M3 | `ScanService::activeRun()` is not scoped to the reader's DAG, so a DAG-confined user is handed a run id belonging to a project-wide run. | `php/Scan/ScanService.php` |
| M4 | `commitBatch()`'s `$expectCursor` parameter is in the store contract, documented as an invariant, and read by neither implementation nor any test. | `php/Scan/ScanStore.php:137` |
| M5 | `reason_bits` is never populated and `uv_scan_dim` is never written, so a stored finding cannot be explained later without re-deriving the rule list. | `UniversalValidator.php:2975-2995` |
| M6 | Nothing guards a second click on Start/Continue; two `pump()` chains then drive one run from one tab. | `js/scan.js:197-221` |
| M7 | The scan panel has none of the ARIA wiring (`role="progressbar"`, live regions) the module's own a11y test enforces for its live-validation UI. | `pages/scan.php:159-169` |
| M8 | `fingerprint()`'s "missing inputs throw" guard is defeated by its only caller, which supplies every key with a default. | `php/Scan/ScanPlanner.php:145-155` |
| M9 | Findings and candidates are written one `INSERT` per row inside a single transaction; a 4,000-finding record produces 4,000 round trips holding a write transaction open. | `php/Scan/SqlScanStore.php:612, 633` |
| M10 | `startRun()` reports **any** insert failure as "a validation scan is already running for this project". A missing table, a column overflow or a lost connection is therefore presented to the operator as contention — the same shape of misdiagnosis that cost the 1.9.5 pilot a round ("the server is busy" over an empty slot pool). | `php/Scan/SqlScanStore.php:87-95` |
| M11 | `available()` runs `Schema::health()` on every AJAX call, which is one `information_schema` query per table plus the version read — 11 extra queries per `scan-work`, and the client issues `scan-work` continuously. | `php/Scan/ScanService.php:85`, `php/Scan/Schema.php:432-460` |
| M12 | Each `scan-work` rebuilds the whole plan (rule discovery, data dictionary, host resolution, ownership map) for three seconds of work (`WorkBudget::BROWSER_TARGET = 3.0`). On the 329-rule project the code comments cite, the fixed cost plausibly dominates the batch; nothing measures it. | `php/Scan/WorkBudget.php:44`, `UniversalValidator.php:2865` |
| M13 | The two stores disagree on eight observable behaviours (uniqueness enforcement, candidate dedup with a NULL `event_id`, refusal-versus-empty answers, `appendManifest` return, purge cascade). The fast suite drives only the in-memory one. | `php/Scan/ArrayScanStore.php` vs `SqlScanStore.php` |
| M14 | The production `ScanDb` implementation (`ModuleDb`, over `$module->query()`) is exercised by no test at all — the MySQL matrix substitutes its own `MysqliDb`. `affected()` via `ROW_COUNT()` is the load-bearing method for every compare-and-set and is never tested against the framework's own call. | `php/Scan/ScanDb.php` |
| M15 | Two `purgeRuns()` implementations with the same name and different cascades: the store's deletes run rows only, the retention class's also deletes findings by generation. Whichever gets wired later decides how much is destroyed. | `SqlScanStore.php:836`, `ScanRetention.php:100` |
| M16 | The unauthenticated survey uniqueness endpoint performs a read-modify-write on its rate-limit counter, so concurrent survey requests can lose increments. | `UniversalValidator.php` `surveyRateLimited()` |
| M17 | Ten module-created tables holding record ids and value previews survive module removal: no `redcap_module_system_disable` hook, no uninstall path, and `docs/INSTALL.md` never tells an administrator the tables exist, what grants they need, or how to remove them. | `config.json`, `docs/INSTALL.md`, `php/Scan/Schema.php` |
| M18 | `Schema::health()` only checks that a table *exists*, and `migrate()` no-ops once the version row is present — so any fix that changes the version-1 DDL (which every fix for B1 and B2 must) will never reach an installation that already ran 1.9.x unless `Schema::VERSION` is bumped. | `php/Scan/Schema.php:38-47, 436-546` |
| M19 | The README, USER_GUIDE and TESTING docs still describe the withdrawn 1.8.x synchronous scan — chunked reads, an on-screen table, a CSV download with an `_INCOMPLETE` filename — and `config.json`'s module description still promises the CSV export. None of it exists in 1.9.10. | `README.md:428-517`, `docs/USER_GUIDE.md:673-685`, `docs/TESTING.md:311-386`, `config.json:4` |
| L1 | An unrecognised coverage value renders a visible but empty completion sentence. | `js/scan.js:76-83` |
| L2 | `pages/scan.php:193` echoes the JSMO object name into a `<script>` block unescaped. | `pages/scan.php:193` |
| L3 | The `noscript` promise is inaccurate: with scripting off, the panel shows an empty phase, an empty count, a 0% bar and a Continue button that does nothing. | `pages/scan.php:148-174` |
| L4 | Unreadable, unstable and tombstoned records count toward "done" in the status payload (all states ≥ 100), so the progress line can read "39 of 39 (100%)" for a run that examined 3. The coverage sentence stays honest; the number does not. | `php/Scan/ScanService.php:258-261` |
| L5 | A run that reads its own progress counts every terminal state as done, so an operator watching a wedged run sees the bar reach 100%. See H18 for the coverage half of the same behaviour. | `php/Scan/ScanService.php:258-261` |
| L6 | `mayCancel()`'s creator check is dead code — both branches return the same expression. | `php/Scan/ScanAuthorization.php:130-136` |
| L8 | A user without design rights can distinguish "that run exists in this project" from "no such run" through the refusal ordering. | `php/Scan/ScanService.php` |

---

## 5a. Performance and scale — measured

Benchmarked against the real MySQL 8.0.46 using the production DDL, with 500,000 finding-shaped
rows, a 100,000-record manifest, ~100,000 candidate groups and a 2.2M-row log table shaped like
`redcap_log_event`. Absolute numbers are from Docker over TCP loopback with a 128 MB buffer pool, so
a tuned server should be 2–4× faster; the ratios and the query plans are properties of the SQL.

| # | Finding | Measured |
|---|---|---|
| P1 | **Every `scan-work` request pays ~1.8 s of fixed cost before it examines a record.** `entitlement()` is computed twice per request (once by `work()`, once by the `status()` it returns), `ScanCapabilities::all` runs twice, and `Schema::health()` adds 11 `information_schema` queries. The work budget for the same request is 3.0 s. | 1.8 s fixed vs a 3.0 s budget |
| P2 | **One `INSERT` per finding, doubled by the production `ScanDb`.** `ModuleDb` follows every `exec()` with a second round trip for `SELECT ROW_COUNT()`, whether or not anyone asks for `affected()`. Batched inserts measured 10.8× faster. | 5.7 ms per finding → **1.6 h per million** |
| P3 | **Catch-up re-groups the whole change-log window on every page.** `GROUP BY` on an expression (`CAST(pk AS BINARY)`) forces a temporary table of every group in the window before `LIMIT` applies, so page 2 costs what page 1 did. | quadratic in window size |
| P4 | Planning walks the entire manifest in one request with both its guards passed as `null` (B10). | 32 s for 100,000 records |
| P5 | **The predictive memory guard never fires.** It predicts from `memory_get_usage(true)` — the allocator arena, which only grows and is quantised to 2 MB — so once the arena holds one batch the delta is 0 for every batch after it. | guard inert in steady state |
| P6 | **Retention is the one unpaged path.** `DELETE FROM uv_finding WHERE generation_id = ?` over 500,000 rows ran as a single statement in a single transaction; an interrupted attempt spent ~13 minutes rolling back while concurrent statements waited. | 644 s to purge one run |
| P7 | `UniqueFinalizer::nextUnfinished()` has no index on `phase`, so it walks the group index and degrades as groups are published. | 2.9 ms → 292 ms |
| P8 | `discover()` writes one `INSERT` per group. | 262 s per 100,000 groups (520 s through `ModuleDb`) |
| P9 | `ix_record (generation_id, record_hash)` is the largest secondary index on `uv_finding` and **no query in the module filters by `record_hash`** — pure write cost. | 22.6 MB per 200,000 rows |
| P10 | One scan of the plan's target project writes roughly 880 MB across installation-wide tables, and nothing prunes them (B6). | ~800 MB `uv_finding` alone |
| P11 | Completeness and progress are `O(manifest)` counts on hot paths, run twice per request. | 46 ms each at 100,000 records |

Rolling those up for the project size the rebuild plan targets — 100,000 records, 300 rules,
1,000,000 findings — the fixed per-request cost alone accounts for **1.7 to 9 hours of server time
spent examining nothing**, on top of evaluation and writes. The plan's own acceptance criterion
("query counts scale as O(chunks), not O(findings)") is not met: P2 and P8 are both O(findings).

---

## 6. Consortium-review notes

* **The module creates and manages ten of its own tables** and, separately, reads REDCap's internal
  schema directly: `redcap_projects` (`log_event_table`, `data_table`), `redcap_log_event*`,
  `redcap_record_list`, `redcap_data*`, and `__GROUPID__` rows. The reads are defensive
  (`information_schema` probes, name allowlists, graceful degradation), but they are still a
  dependency on tables that are not a public API and that have changed shape across REDCap
  versions. Expect a reviewer to raise it; be ready with the justification (bounded record listing
  and a monotonic change fence, neither of which the framework exposes).
* **No cron is declared**, while the design, the settings text and the class comments all assume a
  background worker. Either declare one or remove the claims.
* **`Schema::VERSION` is still 1** with a comment stating the scan "has never been enabled on any
  installation" — that stopped being true at 1.9.0, when it was piloted on a live server. Any
  further change to the version-1 DDL will now silently skip installations that already have it.
* PHP floor and CI: the matrix lints and runs 7.4 / 8.1 / 8.3 / 8.4 and the package job checks the
  release archive shape. That part is in good order.

---

## 7. Why ~12,300 green assertions did not catch any of this

This is the part worth acting on structurally, because every blocking finding above is invisible to
the current suite by construction.

1. **The in-memory store enforces none of the constraints the real one does.**
   `ArrayScanStore::commitBatch()` is `$this->findings[] = $f;` — no unique key, no column widths,
   no NOT NULL, no charset. Every mocked test therefore passes on rows MySQL rejects. This is the
   same class of error the repository's own comments describe from v1.4.0 ("shipped inert while
   every mocked test passed").
2. **The real-database matrix never scans twice.** `tests/mysql/run.php` deletes `uv_finding`
   between sections, so the second-run collision cannot appear.
3. **The four verbs the feature consists of have no test at all.** `ScanService` appears in exactly
   one test file, which calls only `available()`. `start()`, `work()`, `status()` and `cancel()`
   are never driven.
4. **The seam between the rule engine and the store is untested.** `durableScanContext()` and
   `durableEvaluateRecord()` — where host form, identity, generation, value preview and the
   candidate key are decided — appear in no test.
5. **Nothing asserts that a safeguard is wired.** `valueExpiry`, `budgetSpent`, `tightened`,
   `preFenceStatus`, `projectionStillValid`, `mayClaimClean`, `mustShowGaps`, `expireValues`,
   `purgeRuns`, `expire`, `revoke` and `findings` are all tested as functions and never called from
   production. A test that a pure function returns the right answer is not a test that anyone asks
   it.

A cheap, high-value countermeasure: a "wiring" test that greps the production tree for a call to
every public method of `php/Scan/*` and fails on any that only tests call. It would have caught
six of the nine blocking findings.

---

## 8. Fix order

1. **B1 + B11** — add `project_id` to the four generation-scoped tables and to every query and key;
   make the generation a per-project sequence; and supersede a record's existing active findings
   inside the transaction that re-examines it, which closes the re-scan collision and the
   edited-mid-run collision with one change. **Bump `Schema::VERSION`** — `migrate()` is a no-op once
   the version row exists and the tables are present, so a DDL change with the version left at 1
   never reaches an installation that has already run 1.9.x.
2. **B2** — make one finding per location per rule unambiguous (discriminator in the identity, or
   collapse before commit).
3. **B8 + B12** — count an attempt outside the failing transaction, release the claim on a failed
   read as well as on a failed commit, and give a permanently refused batch a terminal state. No
   failure should be able to hold a project's slot forever.
4. **B3** — compare DAG ids with DAG ids; refuse to promote an empty manifest.
5. **B4** — count duplicate findings as violations; write rule problems and collection gaps as
   aggregates.
6. **B6/B7** — declare a cron and wire retention, revocation, abandoned-run reaping, the fingerprint
   comparison and the policy revision. Set `value_expires_at` at write time.
7. **B9** — bump the lease epoch on takeover, or drop the takeover claims from the documentation.
8. **H3** — fix the diagnostic before the next pilot; without it the next failure costs another
   round.
9. **B5 / Task 7** — build the report and export before piloting again. A pilot that cannot show a
   finding cannot be evaluated.
10. Only then: re-pilot, on a project that has never been scanned, with the tables dropped first.

---

## Appendix A — confirming this on the pilot server in five minutes

Run these against the pilot installation's database. They settle which of B1 and B2 the pilot hit,
without another scan.

```sql
-- 1. Did an earlier run leave active findings behind? (B1: anything > 0 means every later run fails)
SELECT generation_id, active_slot, COUNT(*) FROM uv_finding GROUP BY generation_id, active_slot;
```

```sql
-- 2. Do two active findings share an identity? (they cannot, but this shows how close the key is)
SELECT COUNT(*) AS identities, COUNT(DISTINCT finding_identity) AS distinct_identities FROM uv_finding;
```

```sql
-- 3. Is there a checkbox @UVCHOICES rule in the pilot project? (B2, the first-run failure)
--    Look for @UVCHOICES on a field whose field_type is 'checkbox' in the project's data dictionary.
```

```sql
-- 4. Which runs are holding a project slot, and since when?
SELECT run_id, project_id, phase, terminal, coverage, manifest_total, manifest_done,
       detail_rows, updated_at
FROM uv_scan_run WHERE active_slot = 1;
```

The stuck run from the last pilot is almost certainly still in row 4, holding the project's only
slot, because nothing reaps it (B6).

Note that the error text the module shows can no longer name the key: `safeDbMessage()` replaces
every single-quoted run, and MySQL quotes the index name (H3). Until that is fixed, read the real
message from `SHOW ENGINE INNODB STATUS` or the server error log rather than from the page.

## Appendix B — reproducing locally

**Where the harnesses are.** Five are copied into this checkout's `tools/` (which is
`export-ignore`d, so none of them ship): `temporal_pilot_repro.php` (the second-run and retention
reproductions), `temporal_choices_identity.php` (the checkbox identity collision, through the real
evaluation path), `temporal_rollup_crossproject.php` (cross-project aggregates),
`temporal_e2e.php` (the whole-scan harness, with scenario knobs documented in its header), and
`temporal_ns_catch.php` (the namespaced `catch (Throwable)` demonstration).

They target the **1.9.10** tree. This worktree is at 1.8.0, so check out `origin/main` (or a
worktree of it) and copy them into that tree's `tools/` before running.

Environment used: PHP 8.3.32 (`mysqli` loaded with `-d extension=mysqli`), MySQL 8.0.46 in Docker,
`sql_mode` at the MySQL 8 default (`STRICT_TRANS_TABLES`).

```bash
docker run -d --name uv_mysql8 -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=uv_test -p 33306:3306 mysql:8.0
```

```bash
UV_DB_HOST=127.0.0.1 UV_DB_PORT=33306 UV_DB_USER=root UV_DB_PASS=root UV_DB_NAME=uv_test php -d extension=mysqli tools/temporal_pilot_repro.php
```

The harness drives the shipped `SqlScanStore` through a whole run (start, manifest, claim, commit,
finish) and then a second run, and prints each batch's verdict. `tools/temporal_rollup_crossproject.php`
demonstrates the cross-project rollup. Both are under `tools/`, which is `export-ignore`d.

## Appendix C — what was verified by execution rather than reading

* Second-run duplicate-key refusal, and that nothing supersedes prior findings.
* Checkbox `@UVCHOICES` identity collision on a first run.
* Cross-project rollup contamination (another project's instrument and DAG names).
* Cross-project deletion by `ScanRetention::purgeRuns()`.
* `attempts` staying at 0 after a refused batch.
* Unqualified `catch (Throwable)` inside a namespace not catching.
* The full PHP, JS and real-MySQL suites passing on the same tree.
