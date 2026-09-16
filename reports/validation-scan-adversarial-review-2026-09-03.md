# Validation scan — adversarial review

**Branch** `claude/validation-scan-report-944c61` (head `0799fac`) against `main` (`03b90c7`)
**Date** 2026-09-03
**Scope** security, performance, scalability, accuracy, REDCap External Module compliance
**Size** 14,605 insertions / 2,367 deletions across 70 files; ~12,700 lines under `php/Scan/`, `php/Scan*.php`, `pages/scan.php`, `js/scan.js`

## Verdict

**Do not enable on a production installation yet.** One defect (C-1) makes a group-scoped
scan certify a Data Access Group it never read, and the same defect lets any DAG-restricted
designer wedge a project's only scan slot. Five High findings are gaps between what the
module advertises and what the wired code does: there is no way to read a finding, no cron,
no storage ceiling, no mid-run configuration guard, and a survey throttle that fails open
on the default configuration.

Everything below C-1 is either a wiring gap the branch already tracks in
`tests/scan_wiring_php.php`, or a cost that will only be felt at scale. The engineering
underneath is strong: the store, the fences, the authorization model and the error
redaction are better than most REDCap modules that reach the community repository.

All twelve scan PHP suites and the JS client suite pass — 1,228 PHP checks and 82 JS
checks, zero failures. Every finding below survives a green suite, which is the point of
an adversarial pass.

---

## Findings

| ID | Severity | Area | Summary |
|----|----------|------|---------|
| C-1 | Critical | Accuracy / availability | DAG scope is a group *name* where it is written and a group *id* where it is read |
| H-1 | High | Completeness | Findings are stored and cannot be read; the CSV export is a permanent 503 |
| H-2 | High | Privacy / scalability | No cron: abandoned runs, value expiry, run purge and preview revocation are all inert |
| H-3 | High | Scalability | The detail budget never stops storage |
| H-4 | High | Accuracy | The mid-run configuration and privacy guards are unwired and silently no-op |
| H-5 | High | Security | Sessionless survey throttle fails open on a default installation (regression from `main`) |
| M-1 | Medium | Performance | 14 metadata statements plus two full plan rebuilds per `scan-work` |
| M-2 | Medium | Performance | `GROUP BY CAST(pk AS BINARY)` defeats the index on `redcap_log_event`, twice per batch |
| M-3 | Medium | Performance | `commitBatch` is round-trip bound: one statement per record, one per finding |
| M-4 | Medium | Operability | Unbounded paged DELETE inside an administrator's settings save |
| M-5 | Medium | Privacy / compliance | No uninstall path; 13 tables and raw value previews survive removal |
| M-6 | Medium | Accuracy | An empty manifest promotes to `complete-through-fence` and reads as clean |
| M-7 | Medium | Accuracy | `ScanOutcome::mayClaimClean()` has no caller |
| L-1..L-7 | Low | mixed | See below |

---

## C-1 (Critical) — the DAG scope identifier has two different types

**Where** `php/ScanPageView.php:414`, `php/Scan/ScanAuthorization.php:220`,
`php/Scan/ScanPlanner.php:270`, `php/Scan/RecordManifestSource.php:98,220`

`ScanPageView::scanScope()` resolves the reader's group with
`\REDCap::getGroupNames(true, $rights['group_id'])`, which returns the **unique group
name** (`site_a`). `ScanService::openRun()` passes that string through to the planner as
`dagFilter`, and it is stored verbatim in `uv_scan_run.scope_dag`.

Every consumer compares it against a **numeric group id**:

- `ScanPlanner::stream()` filters manifest rows with
  `(string) $row['dag'] !== (string) $dag`, where `$row['dag']` is
  `redcap_record_list.dag_id`.
- `RecordManifestSource::inScope()` does the same for catch-up.
- `ScanAuthorization::scopeMatches()` compares `$runScopeDag` against
  `(string) $rights['group_id']`.

Reproduced with a throwaway probe against the shipped classes (rights `group_id = 7`,
group name `site_a`, one accessible instrument, Full Data Set export):

```
scanScope()['dag']              : 'site_a'    <- stored as scan_run.scope_dag
rights['group_id']              : 7           <- what redcap_record_list.dag_id holds

mayRead(own run)                : false
  why                           : 'that scan is not available to you'
planner admits record in group 7: false

control: mayRead with scope_dag='7'          -> true
control: planner admits with dagFilter='7'   -> true
```

Two consequences, both bad, and they compound:

**(a) A group-scoped run certifies a group it never read.** Every record is filtered out,
so `manifest_total` is 0. `ScanPlanner::plan()` does not refuse an empty manifest (see
M-6). `ScanPromotion::facts()` then sees `pending === 0`, `blocked === false`, and once
catch-up records a `fence_target`, `ScanOutcome::derive()` returns
`terminal=complete`, `coverage=complete-through-fence`, `clean=true`. The page renders
*"Every record was checked, including changes made while it ran."* over zero records, next
to *"Nothing found yet"*. That is the founding complaint of this whole rebuild, reproduced
by a type mismatch.

**(b) A DAG designer bricks the project's scan slot.** `mayStart()` does not consult
scope, so `scan-start` succeeds and takes `uv_scan_run.active_slot`. Every subsequent
`scan-work`, `scan-status` and `scan-cancel` from that user routes through
`scopeMatches()` and is refused with *"that scan is not available to you"* — deliberately
the same wording used to hide another group's run, so the operator gets no thread to pull.
The run never advances, never promotes, and `ScanRetention::expireAbandoned()` has no
caller (H-2), so nothing reaps it. Recovery requires an *unrestricted* designer to open the
page and press Stop.

**Why the suite is green.** `tests/mysql/cases/planning.php:96` passes
`'dagFilter' => '7'` against `dag_id = 7` — the id semantics, consistently. The page tests
feed `scanScope()['dag']` into the *legacy* `scanProject()`, which compares it against
`redcap_data_access_group` from `\REDCap::getData()` — a group **name**, so that path is
correct. Nothing exercises `scanScope()` to `ScanService::openRun()` to `plan()` end to end.
The seam between the two conventions is the only place that is wrong, and it is the only
place with no test.

**Fix.** Pick one type and convert at a single boundary. The id is the better choice —
`redcap_record_list.dag_id` is what the manifest walk actually has, and an id survives a
group being renamed while a run is in flight. `scanScope()` should return
`(string) $rights['group_id']` as `dag`, keep the name only for display, and keep the
`getGroupNames()` call as the *resolvability* check it already is (a group whose name
cannot be read is still a refusal). Then add a test that drives
`scanScope()` → `openRun()` → `plan()` with a record in the group and asserts
`manifest_total > 0` — the shape the existing tests never join up.

---

## H-1 (High) — the scan has no report

`tests/scan_wiring_php.php` reports **38 public scan methods inert today**, each with a
written reason. The ones that matter here:

```
ScanStore::findings          'wave 11 (B5): there is no way to read a finding'
SqlScanStore::findings       'wave 11 (B5), with the contract'
ScanColumns::row/headers/keyLegend   'wave 11 (B5): the findings table and CSV'
ScanPageView::csvRow         'wave 11 (B5): pages/export.php is a permanent 503 until then'
```

`pages/export.php` emits HTTP 503 and an explanation, unconditionally.
`ScanService::status()` returns `findings => (int) $run['detail_rows']` — a count and
nothing else. So a completed scan writes rows into `uv_finding` (including up to 255 bytes
of raw participant value per row) that no user interface can display or export.

`config.json`'s module description, which is what a REDCap administrator reads in the
module manager, says the scan *"exports the findings as CSV"*. It does not. On a branch
whose whole ethic is that a claim must match what the code does, the config description is
the one place still over-claiming.

**Fix.** Either wire wave 11 before shipping, or amend the `config.json` description and
the scan page copy to say the report is not yet available. The second is a two-line change
and is honest.

---

## H-2 (High) — no cron, so four safeguards never run

`config.json` declares no `crons` block, and `ScanService::work()` is only ever called with
`mode = 'browser'`. The branch's own allow-list records what that costs:

| Method | What is lost |
|---|---|
| `ScanRetention::expireAbandoned` | *"one closed browser tab holds a project's only slot forever"* |
| `ScanRetention::expireValues` | stored value previews never expire |
| `ScanRetention::purgeRuns` | `uv_finding` grows without bound |
| `ScanRetention::revokePreviews` | *"a tightened privacy policy revokes nothing today"* |

`expireValues` is the one with regulatory weight. `scan-value-retention-days` is a
project-facing setting in `config.json`; `UniversalValidator.php:3033` computes
`value_expires_at` and `SqlScanStore::insertFinding()` writes it into every row that
carries a value. Nothing ever reads that column. A project that sets retention to 30 days
gets a setting that does nothing, and raw record values sit in `uv_finding.value_bin`
indefinitely, readable by anyone with design rights and Full Data Set export.

`expireAbandoned` is what turns C-1(b) from an annoyance into a wedge, and it is also the
ordinary failure mode: the browser is the only worker, so closing the tab mid-run leaves an
active run holding `uq_project_active` with nothing to reap it.

**Fix.** A `crons` entry running `ScanRetention` daily, plus `expireAbandoned` on a shorter
interval, is the minimum. Until it exists, the scan page should say that a run left
unattended must be stopped by hand.

---

## H-3 (High) — the detail budget never stops storage

`ScanPolicy::budgetSpent()` is on the inert list: *"the detail budget never stops storage;
it gates in ScanWorker::batch once wired"*. `ScanWorker::batch()` never calls it.

`ScanPromotion::facts()` reads `maxFindings` and `maxBytes` and sets `truncated` when
`detail_rows >= maxFindings` — but that is a *label applied after the fact*, not a ceiling.
Nothing declines to insert.

The defaults are `D_MAX_FINDINGS = 1,000,000` and `D_MAX_BYTES = 512 MiB`. A single
misconfigured rule — a `@UVALIDATE` pattern that matches nothing, on a required field, in a
200,000-record longitudinal project with 12 events — produces millions of findings, each an
individual `INSERT` (M-3), each carrying a value preview. The run keeps going until the
manifest is exhausted. On a shared REDCap the first symptom is table growth and replication
lag, not a scan that stopped.

**Fix.** Call `ScanPolicy::budgetSpent()` in `ScanWorker::batch()` before buffering, stop
storing detail once spent, and keep counting so the `truncated` label stays true. The
promotion side already handles the label correctly.

---

## H-4 (High) — the configuration-change guards are dead in the wired path

Two guards exist, are documented at length, are tested, and are never armed in production.

`ScanWorker::work()`:

```php
if (isset($this->deps['fingerprint'])
        && !ScanPlanner::fingerprintMatches($run['fingerprint'], $this->deps['fingerprint'])) { ... }
if (isset($this->deps['policyRevision'])
        && (int) $run['policy_revision'] !== (int) $this->deps['policyRevision']) { ... }
```

`ScanService::advanceRun()` builds the worker with `slots`, `slotTtl`, `fence`, `read`,
`evaluate`, `budget`, `owner`, `attempts`, `finalizer`, `catchup`, `rollup`, `note` — and
neither `fingerprint` nor `policyRevision`. Both `isset()` tests are false forever. The only
call site that supplies a fingerprint is `tests/scan_worker_php.php:988`.

The same on the promotion side: `ScanPromotion::facts()` accepts `fingerprintNow` and
`policyRevisionNow` and computes `$fpBad` / `$polBad` from them;
`ScanService::advanceRun()` passes neither, so `failed` is always false for these causes.

So a designer who edits a rule while a scan is running gets a report whose first half was
checked against one rule set and whose second half was checked against another, and the run
still promotes to `complete`. `ScanWorker`'s own docblock says it *"never continues when the
validation configuration has changed underneath it — a run half-checked against rules that
no longer exist is worse than no run, because it looks like one."* Today it always does.

This is the same shape as commit `563f8c3` on this branch, *"three controls that were
written, tested, and decided nothing"*. `tests/scan_wiring_php.php` catches methods with no
callers; it cannot catch an **optional dependency that no caller supplies**, which is the
harder version of the same bug.

**Fix.** Pass both from `ScanService::advanceRun()` (the fingerprint is recomputable from
`$ent['ctx']`, the policy revision from `$policy`), and make the deps **required** rather
than `isset()`-optional so the next omission is a fatal rather than a silent pass. Then
extend the wiring test to assert that every `$deps[...]` key `ScanWorker` reads is supplied
by the composition root.

---

## H-5 (High) — the sessionless survey throttle fails open by default

`unique-check` is declared in `no-auth-ajax-actions`. It is the module's only
unauthenticated surface and it answers a value-existence question, so the throttle is a real
control, not hygiene.

This branch replaced tier 2 of `surveyRateLimited()` (`UniversalValidator.php:4318`). On
`main` it was a read-modify-write over a system setting — lossy under concurrency, which is
why it was changed, but always present. It is now an atomic counter in `uv_rate_bucket`.

`uv_rate_bucket` is created in `Schema::statementsV2()`, and `Schema::migrate()` only runs
from `installScanSchema()`, which returns early unless `scan-system-enable-durable` is
already on. That flag is off by default, and the module's own documentation says it should
stay off until the durable scan has been piloted.

So on the configuration the module recommends, the table does not exist, `$db->exec(...)`
throws, and the `catch` returns `false`:

```php
} catch (\Throwable $e) {
    // FAILS OPEN, as the whole method does.
    return false;
}
```

The unauthenticated existence oracle went from 600 checks/minute/project to unlimited. The
tests never see it: `tests/hook_php.php:70` stubs `query()` to always succeed, so the
missing-table branch has no coverage.

Second, smaller issue in the same method. Tier 1 (30/minute) keys on `$_SESSION`, and tier
2 is only reached when `session_status() !== PHP_SESSION_ACTIVE`. REDCap starts a session on
most request paths, including survey ones — so in production tier 1 is likely the tier that
runs, and a caller that discards its session cookie between requests gets a fresh
30-request budget every time while never falling through to the per-project counter. Worth
confirming against a live server; if it holds, the two tiers should be **additive** rather
than alternatives.

**Fix.** Make tier 2 independent of the durable-scan schema — either create
`uv_rate_bucket` unconditionally at `redcap_module_system_enable()`, or fall back to the
`main` system-setting counter when the table is absent. Run both tiers rather than choosing
between them. Add a test where `query()` throws.

---

## Medium

### M-1 — fixed per-request overhead against a 3-second budget

`ScanService::advanceRun()` calls `available()`, which calls `Schema::health()`. Measured
against a counting stub:

```
Schema::health() ok=true  statements=14
  x1   SELECT MAX(version) FROM uv_schema_version
  x13  SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() ...
```

14 statements, 13 of them against `information_schema.tables`, on **every** `scan-start` and
every `scan-work`, before any record is read. `information_schema` is not cheap on a MySQL
instance holding a REDCap's worth of per-project data and log tables.

On top of that, each `scan-work`:

- builds the whole plan twice — once via `entitlement()` for the worker, once via the
  trailing `status()` call, which calls `entitlement()` again. Each build runs
  `ScanCapabilities::all()` (six probes, ten `query()` sites) and a full
  `projectIdentifierFields()` dictionary read.
- resolves the source fence three or four times: `$this->fence($pid)` is called separately
  for the worker deps, for `catchUp()`, and for `finalizer()`, and each call runs
  `SourceFence::forProject()` → `resolveTable()` + `now()` + `retained()`.
- opens `RecordManifestSource` a second time inside `catchUp()`.

`js/scan.js` re-issues `scan-work` at 0 ms whenever a pass made progress, so this prologue
runs continuously for the length of a scan. Against `WorkBudget::BROWSER_TARGET = 3.0`
seconds of useful work, the fixed cost is a material fraction and it lands on shared
infrastructure.

**Fix.** Memoize per request: `available()` once, one `entitlement()` result reused by the
worker and the trailing status, one `fence()`, one `RecordManifestSource`. `Schema::health()`
can be one `information_schema` query with `table_name IN (...)` instead of thirteen, and
can be cached for the life of the request.

### M-2 — the stable-read fence defeats its own index

`SourceFence::versions()`:

```sql
SELECT CAST(pk AS BINARY) AS p, MAX(log_event_id)
FROM redcap_log_eventN WHERE project_id = ? AND pk IN (...) GROUP BY p
```

The `CAST` is deliberate and the reasoning is right — a case-insensitive collation would
group `abc` and `ABC` together and report both unstable. But MySQL cannot satisfy a
`GROUP BY` on an expression from an index, so this becomes a temporary table plus filesort
over every matched row, on `redcap_log_event` — the table REDCap itself writes to on every
save, and the largest table on most installations.

`ScanWorker::batch()` calls it **twice per batch** (before and after the read), with up to
500 record ids each time. A long-lived record can have hundreds of log rows. On a
100,000-record project that is roughly 400 such queries over the run.

The code already reconciles the collation over-match in PHP (`if (!array_key_exists($id,
$out)) continue;`), which is most of the machinery needed to do this the index-friendly way.

**Fix.** Measure it first — `EXPLAIN` on a real installation will settle whether the
optimiser materializes. If it does, `GROUP BY pk` (collated) and resolve the ambiguity in
PHP; the failure direction there is an unnecessary requeue, which is safe, and the code
already tolerates extra keys coming back.

### M-3 — `commitBatch` is round-trip bound

`SqlScanStore::commitBatch()` issues one `UPDATE uv_scan_record` per claimed record (the
per-row claim fence, which has to be per row), then one `INSERT` per finding via
`insertFinding()` and one per candidate via `insertCandidate()`. At a 500-record batch with
findings on a third of them, that is 700+ statements inside one transaction.

`UniqueFinalizer::discover()` already learned this lesson and batches — its comment records
100,000 groups taking 811 seconds one-at-a-time versus 200 statements batched, and notes
that the External Modules `query()` wrapper *"runs a second statement per write to read
ROW_COUNT()"*, doubling the count. `insertFinding()` and `insertCandidate()` did not get the
same treatment.

`php/Scan/DbError.php`'s docblock still describes a findings batch as *"one multi-row INSERT
holding thousands of placeholders"*, which is no longer what the store does — stale
rationale worth correcting either way.

**Fix.** Chunked multi-row `INSERT` for findings and candidates, sized the way `discover()`
sizes its chunks. The record `UPDATE`s have to stay per-row.

### M-4 — an unbounded DELETE loop inside a settings save

`Schema::upgradeDataV2()` runs, for each of four tables:

```php
for ($page = 0; $page < 100000; $page++) {
    $module->query('DELETE FROM ' . $t . ' WHERE project_id = 0 LIMIT 5000', []);
    // + a COUNT(*) per page
}
```

Up to 500 million rows per table, with no wall-clock or memory guard, inside
`redcap_module_save_configuration()` — an administrator's synchronous HTTP request. Paging
is the right instinct and the comment explains why (a single unpaged statement measured 160
seconds over 500,000 rows). What is missing is a deadline.

It does make forward progress across retries, and failing to record the version keeps the
scan disabled, which is the safe direction. But an administrator on a piloted installation
sees a settings save that times out with no explanation, repeatedly.

**Fix.** A `microtime(true)` deadline in the loop, and return a resumable "migration in
progress" state rather than a failure, so the health check can say what is happening.

### M-5 — nothing removes the module's data

There is no `redcap_module_system_disable()`, and `Schema` states *"There is no DROP anywhere
in this file"*. `Schema::tables()` is documented as *"Every qualified table name, for health
checks and uninstall"* — there is no uninstall caller.

Uninstalling the module leaves 13 `uv_*` tables in REDCap's schema, including
`uv_finding.value_bin` (raw participant values, up to 255 bytes each, with an expiry nothing
enforces per H-2) and `uv_finding.record_id_bin` (raw record identifiers). For a module
handling clinical trial data this is the finding an institutional review will ask about
first.

**Fix.** Not necessarily an automatic DROP — that has its own risks — but at minimum a
documented removal procedure in `docs/INSTALL.md`, and a system-settings control that purges
scan data on request. `Schema::plan()` already sets the precedent of handing an
administrator the statements.

### M-6 — an empty manifest promotes to a clean bill

`ScanPlanner::plan()` accepts `freezeManifest()` returning 0 and starts the run.
`ScanPromotion::facts()` reads `pending === 0` as `manifestDone`, and `ScanOutcome::derive()`
takes the `clean` branch. The page renders *"Every record was checked, including changes made
while it ran."*

For a genuinely empty project that is arguably correct. For any scoping bug it is the
mechanism that converts the bug into a false certification — as C-1 does. A scan of zero
records is not evidence about a project.

**Fix.** Refuse a run whose frozen manifest is empty, with wording that separates the two
cases ("this project has no records" vs "no records matched your Data Access Group"). That
single refusal would have made C-1 visible on its first run instead of producing a green
tick.

### M-7 — `mayClaimClean()` has no caller

From the branch's own list: *"wave 7 (B4/H15) asks it: nothing currently asks whether 'clean'
may be said, so it is said"*. The page's completion sentence comes from
`ScanPageView::coverageSentences()`, keyed on `coverage` alone, so
`coverage = complete-through-fence` prints *"Every record was checked"* regardless of what
`ScanOutcome::derive()` decided about `clean`. Low impact today because `derive()` sets both
consistently — but the two are meant to be independent axes, and one of them is not being
consulted.

---

## Low

- **L-1** Table prefix is `uv_` (`Schema::PREFIX`). Thirteen tables named `uv_finding`,
  `uv_scan_run`, `uv_rate_bucket` and so on sit in REDCap's shared schema. A two-letter
  prefix is a collision waiting for a second module; `uv_universal_validator_` or similar
  costs nothing now and is unchangeable later.
- **L-2** `framework-version: 14` with `redcap-version-min: 13.7.0`. Framework 14 landed
  slightly later in the 13.7.x line; confirm the floor, or REDCap will refuse to enable the
  module on the versions the config claims to support.
- **L-3** `WorkerSlots::provision()` returns the loop count, not rows inserted — the
  statement is `INSERT IGNORE`. The `scan-slots-provision` log entry can report slots it did
  not create.
- **L-4** `WorkBudget`'s cron path is unreachable (no cron, and `work()` is only called with
  `'browser'`). Separately, `startedAt` is set when the budget is constructed — after
  `available()` and `entitlement()` have already spent request time — so the `TIME_SHARE`
  reasoning is measuring from the wrong zero. Harmless at a 3-second browser target;
  relevant the day the cron target of 20 seconds is used.
- **L-5** `DbError`'s docblock describes a findings batch as a single multi-row INSERT. See
  M-3.
- **L-6** `UniversalValidator::scanAction()`'s catch-all returns *"the validation scan could
  not be reached; ask an administrator to check the module log"* and **does not write to the
  module log**. Any `Throwable` that is not `ScanStoreUnavailable` — a missing HMAC key, an
  `InvalidArgumentException` from `Schema::table()`, a framework change — sends the operator
  to a log with nothing in it. `ScanService::storageFailed()` does this correctly; this catch
  should call the same `note()`.
- **L-7** `SqlScanStore::expireValues()` is installation-wide with no project predicate.
  Defensible (expiry is a per-row fact) but it is the exact shape of the cross-project bug
  schema version 2 was written to fix, and it will be wired in wave 10. Worth an explicit
  comment saying the omission is deliberate, so the next reviewer does not have to re-derive
  it.

---

## What holds up

Stated because it is load-bearing for the risk assessment, not as padding.

**Injection.** Every statement is parameterized. The only interpolated identifiers come from
`Schema::table()`, which throws on anything outside a hard-coded 13-entry allow-list, or from
`\z`-anchored regexes over server-supplied names (`/^redcap_data[0-9]*\z/`,
`/^redcap_log_event[0-9]*\z/`). The `\z`-not-`$` choice is correct and the reason is written
down. No `db_query`, no string concatenation of user input anywhere in the scan tree.

**Concurrency.** One active run per project is enforced by a unique index over
`(project_id, active_slot)` with NULL on every terminal transition, not by a read-then-write.
Batch commits take `SELECT ... FOR UPDATE` on the run row and check four separate fences
(existence, cancellation, terminal, epoch) with distinct messages. Takeover is fenced per
record on a claim token rather than by bumping the run epoch, which is the right call and the
comment explains why the obvious alternative is wrong. The `affected() === 1` versus re-read
discipline around MySQL's rows-changed semantics is applied consistently and is the kind of
thing that usually ships broken.

**Authorization.** Whole-report denial rather than row filtering, with the reasoning
(count, rollup, filter options, cursor, timing, filename all leak). Rights are re-derived on
every request rather than carried on the run. Refusals are uniform, so "no such run" and "not
yours" are indistinguishable. `is_callable` rather than `method_exists` throughout, with the
`__call()` reason documented. Unreadable rights fail closed at every branch. The
administrator normalization (`normalizeRights`) resolves rather than invents.

**Output safety.** One `scrub()` shared by the HTML and CSV paths, so a byte cannot be
sanitized in one and passed raw into the other. CSV quoting is unconditional and formula
injection is defused *after* skipping leading whitespace, tabs, CR and a BOM — which is the
detail most implementations miss. `DbError` rebuilds a safe message from structural captures
instead of filtering a raw one, which drops bound parameters rather than trimming them.

**Honesty as a control.** `tests/scan_wiring_php.php` is unusual and effective: it enumerates
every public method in the scan tree with no production caller and requires a written reason
for each. It is what let this review enumerate the inert surface in one command rather than
by reading 12,700 lines. Its blind spot is H-4 — an optional dependency no caller supplies
looks wired — and closing that is the highest-value change to the test itself.

---

## REDCap External Module compliance

**Correct:**

- Framework `query($sql, $params)` throughout; no direct `db_query`.
- Hooks used are all valid for framework 14: `redcap_module_ajax`,
  `redcap_module_link_check_display`, `redcap_module_system_enable`,
  `redcap_module_save_configuration`.
- `auth-ajax-actions` for the four scan verbs, with a defence-in-depth `$user_id` check
  inside `redcap_module_ajax()` — correct, since the framework guards the action name and
  passes identity through unchecked.
- The project link is gated by `redcap_module_link_check_display()` and the page re-checks.
- Per-project data tables and log shards are resolved from `redcap_projects.data_table` and
  `.log_event_table` and allow-listed, rather than assuming `redcap_data`.
- Schema installation is idempotent, fails closed, never DROPs, and version 1 is frozen with
  version 2 expressed as conditional ALTERs.

**Needs attention before a community submission:**

1. **No `crons` block** (H-2). The module's own design depends on scheduled work.
2. **No uninstall/disable cleanup** (M-5). Reviewers ask about this.
3. **`config.json` description over-claims** the CSV export (H-1).
4. **Direct reads of core tables** — `redcap_record_list`, `redcap_projects`,
   `redcap_log_event*`, `redcap_data*`. This is the module's largest version-coupling risk.
   It is mitigated as well as it can be (capability probes, allow-list regexes, documented
   fallbacks, refusal rather than degradation), but it should be stated plainly in the
   submission notes rather than left for a reviewer to discover.
5. **`\REDCap::getData()` is called with a project id and no user context**
   (`UniversalValidator.php:3066`), which bypasses per-user rights entirely. The mitigation
   is real — `ScanAuthorization::mayStart()` requires Full Data Set export plus non-zero
   access to every instrument in the entitlement set, or the whole run is refused — but it
   should be documented for reviewers as a deliberate design with a named compensating
   control, because the call on its own looks like a privilege escalation.
6. **Unauthenticated `unique-check`** is legitimate and well-defended in principle
   (opt-in per rule, refusal on Identifier fields including composite members, fail-closed
   when the dictionary cannot be read, boolean-only answers), but see H-5 for the throttle.
7. **DDL from a settings-save hook** requires the REDCap database user to hold `CREATE` and
   `ALTER`. Many institutional deployments restrict that. `Schema::plan()` exists so an
   administrator can run the statements by hand, which is the right escape hatch — but the
   scan page shows only `$health['why']` and never surfaces the plan. Consider printing it.

---

## Recommended order

1. **C-1** — one type conversion plus the missing integration test. Nothing else matters
   while a group-scoped scan can certify a group it never read.
2. **M-6** — refuse an empty manifest. Two lines, and it turns the next scoping bug into a
   refusal instead of a green tick.
3. **H-4** — wire the fingerprint and policy revision, make the deps required, and extend
   the wiring test to cover unsupplied optional dependencies.
4. **H-5** — restore the sessionless throttle on installations without the scan schema.
5. **H-1** — wire the report, or correct the config description and page copy.
6. **H-2 / H-3** — the cron and the storage ceiling, together; each is dangerous without the
   other.
7. **M-1 / M-2 / M-3** — measure first (`EXPLAIN` on M-2, statement counts on M-1), then act.
