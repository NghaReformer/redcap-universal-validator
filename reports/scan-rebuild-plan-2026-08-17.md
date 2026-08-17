# Secure, Unbounded Validation Scan Rebuild — Implementation Plan

> **For agentic workers:** REQUIRED: Use `superpowers:subagent-driven-development` if subagents are available, otherwise use `superpowers:executing-plans`. Implement tasks in order, test-first, and do not expose an intermediate production path that lacks authorization, durable uniqueness, fencing, quotas, or cleanup.

**Goal:** Rebuild the validation scan so it can examine every record in a REDCap project with bounded memory, resumable execution, strict coverage claims, secure persisted findings, efficient filtering, and truthful exports.

**Architecture:** An authenticated user creates an immutable, project/DAG-scoped scan run. A database-backed work queue is advanced by short browser requests and a framework cron worker using fenced leases; all phases, including unique finalization and rollups, are resumable. Detailed findings are bounded, while validation continues aggregate-only after the configured detail budget is reached.

**Tech stack:** PHP 7.4+, REDCap 13.7+, External Modules Framework 14, MySQL/MariaDB, authenticated JSMO AJAX, framework cron, vanilla JavaScript, and the repository's existing PHP/Node test harness.

---

## 1. Non-negotiable product and safety decisions

- There is no hard record-count ceiling. Record IDs, findings, errors, unique candidates, and exports must never be accumulated for the whole project in PHP memory.
- `complete` means every in-scope record was stably validated at or beyond a recorded source-change fence. Equal record counts are never evidence of completeness.
- If a reliable source fence cannot be proven, the strongest terminal coverage state is `manifest-complete`; it must never render as complete or clean.
- Field values are not stored by default. `scan-value-storage=locations` is the default; `identifier-redacted` and `raw` require explicit project opt-in.
- Raw record IDs are displayed by default so findings remain actionable. A hashed-display option never removes the raw worker locator from the protected manifest; it changes only finding/UI/export presentation.
- One resource-intensive scan may be active per project, regardless of whether it is global or DAG-scoped. The default installation-wide worker concurrency is two projects.
- Detailed storage defaults to the first of 1,000,000 findings or an estimated 512 MiB. The scan then continues aggregate-only, sets `detail=truncated`, and can never certify a clean report.
- Value previews expire after 30 days. Location findings, summaries, and run metadata expire after 90 days. Projects may shorten, never extend, administrator-defined maxima.
- Required rules on never-started instruments are reported as collection gaps, aggregated by instrument, rather than emitted as millions of data-quality violations.
- No release may expose batching without read-side authorization, lease fencing, durable cross-batch uniqueness, quotas, retention, cancellation, and real MySQL/MariaDB concurrency tests.
- Production UI and exports must not call the legacy array scan. The current GET-triggered run/export path is disabled in the first safety release and stays disabled until the durable path is complete.
- A user must have design rights, readable access to the complete entitlement form set, full identified-data export rights, and an authorized DAG scope. The entitlement form set is every rule host plus every form owning a `when`/assertion/cross-form reference, unique composite field, action-tag enrichment value, or other snapshotted validation dependency. Unknown ownership or partial rights deny the whole scan/report rather than producing inference-prone partial columns and counts.

## 2. Public contracts and state model

### Legacy compatibility

Keep the public signature:

```php
public function scanProject($pid, $dagFilter = null, $chunkSize = 200, $sink = null)
```

- A null sink constructs `ArrayFindingSink` and returns the current array contract.
- The legacy path remains intentionally bounded by request memory and is used only by compatibility tests and small synchronous scans during migration.
- New production UI and exports must never invoke the legacy array sink.

### Authenticated AJAX actions

Add only to `auth-ajax-actions` in `config.json`:

- `scan-start`: ignore client identity/scope claims; derive project, user, full scan entitlement, and current DAG server-side. If an inaccessible run already owns the project slot, return only a generic project-busy result—never its run ID or scope.
- `scan-work`: claim and process one bounded work unit using the current lease epoch.
- `scan-status`: return authorized scope-specific progress and status.
- `scan-cancel`: atomically move to `cancelling` and increment the lease epoch so an already-evaluating worker cannot commit afterward.
- `scan-findings`: validate filters and sort keys against the declarative column catalog; return one keyset page.

Every action accepts an opaque `run_id`; that identifier is a locator, never authorization. Bind it to the current project before returning any distinction between missing and forbidden.

### Status payload

Return these independent dimensions:

```text
phase: planning | scanning | catch-up | unique-finalize | rollup-finalize | cancelling | terminal
coverage: complete-through-fence | manifest-complete | partial | failed
detail: complete | truncated
values: none | identifier-redacted | raw | expired
terminal: null while active | complete | partial | failed | cancelled | expired
```

Also return authorized counts, target fence, timestamps, collection-gap totals, rule-problem totals, whether the caller may resume/cancel/export, and a stable keyset cursor where applicable.

### Clean predicate

The UI and every exporter use one shared predicate. A result is data-quality clean only when:

1. `coverage === complete-through-fence`;
2. `detail === complete`;
3. no validation-blocking exclusion exists;
4. no rule is unconfigurable;
5. no violation exists.

Collection gaps do not become violations, but must appear immediately beside any clean statement. Never render a bare “No issues found” when collection gaps exist.

### Permission matrix

`ScanAuthorization` resolves rights from the framework user object, reusing the fail-closed form-rights strategy already established by `userFormRights()`. Unknown rights shape is denial.

| Action | Required authorization |
|---|---|
| Start full/incremental run | Design rights, nonzero read access to every form in the snapshotted entitlement form set, full identified-data export rights, and resolved current DAG or unrestricted scope |
| Browser work/resume | Same entitlement as start; DAG must exactly match the immutable run scope. Cron uses only the immutable authorized scope and cannot widen it |
| Status/findings/CSV | Same current entitlement; every visible form and record locator must remain authorized |
| Cancel DAG run | Creator, another fully entitled user currently in that exact DAG, or an unrestricted fully entitled user/administrator |
| Cancel global run | Unrestricted fully entitled user or administrator only |
| Change value/privacy policy | Unrestricted fully entitled user or administrator; restriction takes effect immediately |

Do not implement partial-form report filtering. Denying the whole report avoids leaks through counts, rollups, filter options, cursors, timing, filenames, and value previews. Add inverse tests for host forms, dependency-only forms, composite/enrichment forms, unknown ownership, export level, Identifier access, DAG, creator, and rights revocation.

### Terminal-state derivation

Use one shared `ScanOutcome::derive()` function in workers, UI, and exporters:

| Condition | Terminal | Coverage | Detail | Clean allowed | Export suffix |
|---|---|---|---|---|---|
| Fenced coverage, no blocking exclusions, detail retained | `complete` | `complete-through-fence` | `complete` | Only with zero violations/rule problems | none |
| Fenced coverage but detail budget exceeded | `partial` | `complete-through-fence` | `truncated` | No | `_TRUNCATED` |
| Frozen manifest processed without reliable fence | `partial` | `manifest-complete` | either | No | `_MANIFEST_ONLY` plus `_TRUNCATED` when applicable |
| Any unread/unstable record or validation-blocking degradation | `partial` | `partial` | either | No | `_INCOMPLETE` plus `_TRUNCATED` when applicable |
| Nonblocking reporting-label degradation only | `complete` if all complete conditions otherwise hold | `complete-through-fence` | `complete` | Yes, with adjacent reporting warning | none |
| Explicit cancellation | `cancelled` | `partial` | either | No | `_CANCELLED` |
| Unrecoverable store/schema/fingerprint failure | `failed` | `failed` | either | No | `_FAILED` |
| Abandoned beyond configured lifetime | `expired` | `partial` | either | No | `_EXPIRED` |

Value expiry changes only `values`; it never rewrites historical coverage. Exports always describe the current value state.

## 3. File and component boundaries

Keep `UniversalValidator.php` as the framework adapter and existing validation engine. Add focused classes under `php/Scan/`:

| File | Responsibility |
|---|---|
| `FindingSink.php` | `open`, `emit`, `note`, `close` producer contract |
| `ArrayFindingSink.php` | Exact legacy return shape |
| `RecordingFindingSink.php` | Differential-test event stream |
| `ScanPlanner.php` | Rules, dependencies, fingerprints, policy, immutable manifest planning |
| `RecordManifestSource.php` | Bounded, deterministic, DAG-aware record-ID keyset traversal |
| `SourceFence.php` | Monotonic source versions, change lookup, log-retention validation |
| `ScanWorker.php` | Fenced claims, adaptive batches, stable reads, state-machine transitions |
| `WorkerSlots.php` | Installation-wide fenced semaphore shared by browser and cron workers |
| `ScanStore.php` | Storage interface and transaction-level invariants |
| `SqlScanStore.php` | Prepared MySQL/MariaDB implementation |
| `Schema.php` | Versioned, idempotent DDL and health diagnostics |
| `ScanAuthorization.php` | Start/read/export/work/cancel policy and current-DAG revalidation |
| `ScanColumns.php` | Declarative columns, filters, sort whitelist, sensitivity, dependencies |
| `MessageCatalog.php` | PHP catalog lookup and total fallback |
| `ScanExporter.php` | Streaming authorized CSV row production |
| `ScanRetention.php` | Value expiry, run purge, abandoned-run expiry |
| `UniqueFinalizer.php` | Bounded, epoch-staged verification and publication of duplicate groups |

Add `js/scan.js` for start/progress/resume/cancel/paging. Keep `pages/scan.php` as a thin authorized shell and add `pages/scan-export.php` for a single authenticated streaming CSV response. XLSX is deliberately out of scope because the repository has no PHP-7.4-compatible streaming workbook dependency.

Avoid a per-context table initially. Denormalize only location/filter keys required by findings; lazily enrich optional log information for the visible/exported record batch.

## 4. Durable schema

`Schema.php` owns migrations and uses the installation's confirmed module-table naming convention. A migration failure disables the new scan path and displays an administrator diagnostic; never fall back to framework logs.

### `uv_scan_run`

Store binary UUID, a monotonic per-generation `run_seq`, project, creator, requested DAG scope, full/incremental kind, baseline generation, phase/status dimensions, immutable policy JSON and policy revision, validation fingerprint, opening/target fences, manifest totals, phase cursors, detailed rows/bytes, aggregate counts, retry/error summary, lease owner/epoch/expiry, cancellation timestamp, timestamps, and terminal reason.

Enforce at most one active run per project with a nullable active slot and a unique `(project_id, active_slot)` key. Terminal transitions set the slot to `NULL`.

### `uv_scan_record`

Use `(run_id, ordinal)` as the primary traversal key and a unique `(run_id, record_id_bin)` identity. Store the protected raw `VARBINARY` worker locator, target-fence DAG, state, attempts, before/after/scanned source versions, and terminal error/tombstone code. Deleted records reach a terminal tombstone state rather than blocking completion forever. Hashed record-ID mode never hashes this worker locator; it stores/displays only a separate project-scoped HMAC outside worker operations.

### `uv_finding`

Store generation, keyed identity hash, monotonic `valid_from_seq`, nullable `valid_to_seq`, nullable `active_slot`, record/event/instance/host-form/field location, stable rule source ID and revision, reason code/bitmask, severity, DAG/event/arm/status filter keys, and optional value preview metadata.

Use sequence intervals so incremental runs change only affected records while historical “as of run” views remain reproducible. Enforce one active version with `UNIQUE(generation_id, finding_identity, active_slot)`, where current rows use `active_slot=1` and closed rows use `NULL`; verify this behavior across the declared MySQL/MariaDB matrix.

Optional value columns are binary preview, original byte length, keyed project-scoped fingerprint, truncation flag, encoding flag, and expiry timestamp. Never use unbounded reason text or assertions on finding rows.

### `uv_unique_candidate`

Store generation, rule source/revision, candidate location, project-scoped keyed HMAC group, scope keys, and scanned source version. Do not store whole Notes values. When a hash group contains multiple records, reread only that group under the stable-read protocol and compare canonical byte tuples in PHP before emitting duplicates.

### `uv_unique_group`

Store one row per candidate group with `candidate_epoch`, verification cursor, emission cursor, phase, representative locator, staged finding epoch, published epoch, distinct-record count, and blocking collision state. Candidate insert/update/delete increments the group epoch and invalidates unfinished staged output.

### `uv_scan_worker_slot`

Precreate the configured maximum number of installation-wide slots. A browser or cron worker atomically leases one slot with owner, epoch, run, and expiry before claiming project work. Slot acquisition, renewal, release, and stale takeover are fenced and tested for configured limits 1, 2, and N.

### Remaining tables

- `uv_scan_aggregate`: run, kind, configured axes, count, up to 20 samples, and whether the condition blocks validation coverage.
- `uv_scan_dim`: run/generation-scoped labels and rule snapshots; labels are never duplicated per finding.
- `uv_scan_audit`: start, open, export, cancel, privacy change, value expiry, purge, and administrative recovery events. Audit opening a run once per user/session, not every page fetch.

All joins and destructive retention operations use explicit foreign-key or application-enforced cascade tests. All dynamic REDCap table identifiers are server-derived and matched against a strict allowlist before interpolation; values always use prepared parameters.

## 5. Authorization and privacy behavior

- Derive authentication from the current framework user object, not hook parameters supplied to `redcap_module_ajax`.
- Require the complete permission-matrix entitlement to create, view, resume, cancel, or export a run.
- Unrestricted design users may access the whole authorized project run.
- DAG projections are explicitly target-fence snapshots, not claims about current membership. Manifest DAG, counts, rollups, filters, and findings all use the reconciled target-fence DAG.
- A DAG-scoped start is permitted only when `SourceFence` can prove DAG membership changes. Without that capability, reject the start before creating a run. `manifest-complete` remains available only to unrestricted users.
- Before a DAG run reaches a target fence, `scan-status` returns only run ownership, active phase, heartbeat/last-progress time, resumability/cancellation flags, and non-disclosing error category. It returns no record/finding totals, percentages, rollups, filters, samples, or cross-scope timing estimates. Findings and exports remain unavailable.
- Before any DAG-bound read, prove through `SourceFence` that no record moved into or out of that DAG after the target fence. If membership drift exists—or the installation cannot prove DAG-change coverage—deny the entire persisted projection with a generic stale-scope response and require a new DAG scan. Do not page until enough currently authorized rows happen to appear.
- Revalidate the current DAG for the bounded unique-record set on every findings page and CSV chunk as a second deny gate. Any mismatch invalidates the whole response and audit event; it never silently changes counts.
- A project-wide run may supply its target-fence DAG projection to a DAG user only while the no-membership-drift proof holds. Global totals, other DAG dimensions, and global filter cardinalities are never exposed.
- If another scope owns the one project-active slot, `scan-start` returns generic busy with no run identifier, owner, scope, progress, or timing distinction. DAG users may work/cancel only an exact matching DAG run. Only unrestricted fully entitled users or administrators may work/cancel a global run.
- Rights revoked during a run prevent further browser work and reads. The cron may finish the immutable authorized scope, but the former user cannot regain access through the run ID.
- `identifier-redacted` fails closed: unavailable or malformed identifier metadata downgrades every value to locations-only and records a visible reporting degradation.
- Stored binary data is never passed directly to JSON or HTML. Valid UTF-8 is escaped with substitution enabled; invalid bytes render as clearly labelled hexadecimal or Base64.
- Project-scoped HMACs reuse the module's protected secret mechanism and include purpose/version separation so record hashes, finding IDs, value fingerprints, and unique groups cannot be correlated across purposes or projects.
- A change from `raw` to `identifier-redacted` or `locations`, loss of trustworthy identifier metadata, a switch to hashed record presentation, or a reduced system retention maximum immediately increments the run policy revision, invalidates worker/export leases, blocks further preview reads, and queues bounded purge. Existing previews are never readable while waiting for purge.

## 6. Bounded scan algorithm

### Planning

1. Authorize the current user and resolve DAG shape fail-closed.
2. Verify schema, worker, source-fence, retention, and storage health.
3. Load rules and dictionary once; snapshot every rule and label required for the report.
4. Resolve the entitlement form set from all hosts, conditions, assertions, unique composites, action-tag enrichment fields, and validation dependencies. Unknown field ownership fails authorization and planning closed.
5. Assign settings rules persistent UUIDs. Identify annotation rules by field, tag family, occurrence locator, and revision hash. Duplicate identical annotations at distinct occurrences remain distinct.
6. Compute a fingerprint over canonical validation rules, rule identities, field/form ownership, events/arms/repeating structure, choices, untouched-form policy, privacy mode, and validation-engine version. Wording-only catalog changes do not invalidate validation.
7. Capture the opening source fence.
8. Stream in-scope record IDs into `uv_scan_record` using deterministic binary ordering and source-level DAG filtering.
9. Set totals before entering `scanning`; coverage is based on record states, not a batch count.

`RecordManifestSource` must prefer a supported paged REDCap capability. A version-tested read-only keyset adapter is permitted when required. If neither can enumerate records without a whole-project PHP array, fail before work begins.

### Stable record reads

For each claimed record batch:

1. Acquire one installation-wide fenced worker slot, then a project/run lease epoch.
2. Read the current source version for each record.
3. Call `REDCap::getData` with explicit records and only the union of required fields.
4. Read source versions again.
5. Requeue records whose versions changed during the read.
6. Evaluate stable records and buffer findings, unique candidates, aggregates, and not-checked notes.
7. In one transaction, replace/version those records' results, mark their scanned versions, update counters, and advance the ordinal cursor last with a compare-and-set conditioned on `run_id`, old cursor, active phase, unchanged policy revision, `cancel_requested_at IS NULL`, and current run/worker-slot lease epochs.
8. Release or renew the installation slot through its own fenced compare-and-set.

The worker retries deadlocks and transient reads with bounded exponential backoff. A record that cannot stabilize or be read after three attempts becomes a validation-blocking exclusion; the scan continues over every other record and terminates partial.

Cancellation atomically sets `cancel_requested_at`, changes the CAS-visible phase to `cancelling`, and increments the run lease epoch. A worker already evaluating outside a transaction therefore fails its final CAS and rolls back every buffered result. Apply the same cancellation/epoch predicate to unique and rollup finalizers.

### Adaptive work sizing

- Cursor semantics use record ordinals, never precomputed batch numbers.
- Browser requests target about three seconds of work; cron invocations target about twenty seconds.
- Adjust record count within configured minimum/maximum bounds from observed elapsed time, peak allocated memory, result bytes, contexts, and findings.
- Refuse a new batch unless the predicted peak preserves at least 40% of `memory_limit`. Handle `-1` and unit parsing explicitly.
- Stop before the configured execution-time reserve. A shutdown guard may improve diagnostics but must not be claimed as OOM prevention.
- One oversized record is processed alone. If it still exceeds safe bounds, record a blocking exclusion rather than repeatedly crashing.

### Catch-up and coverage

After baseline scanning:

1. Capture a target closing fence.
2. Query additions, deletions, DAG moves, repeat changes, and edits newer than each record's scanned source version and no newer than the target fence.
3. Add/requeue the affected manifest rows and process them with the stable-read protocol.
4. Repeat until every in-scope record is stable at or beyond the target fence and the fingerprint still matches.

An equal final record count is irrelevant; reconcile record identities and source versions. If log retention cannot cover the opening-to-target interval, or the event taxonomy cannot safely identify every record-changing operation, terminate `manifest-complete` or `partial`, never `complete-through-fence`.

### Unique and rollup finalization

- Finalize keyed HMAC groups and candidates in keyset pages. A group containing every project record must still use bounded memory.
- Capture `candidate_epoch` and one canonical representative tuple of at most the validation engine's maximum field bytes. Page candidate locations, reread values under the stable-read protocol, and compare each tuple to the representative without accumulating the group.
- If a tuple differs despite sharing the keyed HMAC, mark the group as a validation-blocking hash-collision degradation; do not partition or emit a possibly wrong uniqueness verdict.
- Revalidate candidate source versions while resolving pages. Candidate changes increment `candidate_epoch`, invalidate staged rows, and restart that group.
- Stage duplicate findings under a finalizer epoch. Report queries see only the group's published epoch. After a complete stable verification/emission pass, publish the epoch with one fenced pointer update; delete obsolete staged rows asynchronously in bounded pages.
- Emit versioned duplicate findings idempotently using the finding identity and staged-epoch constraints.
- Build rollups incrementally from bounded keyset pages and persist the rollup cursor.
- Promotion uses `ScanOutcome::derive()` in one fenced conditional transaction checking manifest states, blocking aggregates, current fingerprint/policy revision, cancellation, target fence, detail state, and completion of both finalizers.

## 7. Incremental generations

- Enable incremental scans only after a `complete-through-fence` full baseline and only while the source-fence adapter proves retained monotonic coverage.
- Freeze an incremental upper fence before selecting changes.
- Include edits, additions, deletions, DAG changes, event/repeat changes, and any affected unique peers.
- For non-unique rules, close active finding versions for the changed record and insert the new set atomically.
- For unique rules, retain each changed record's old candidate group, compute its new group, and re-finalize both groups so unchanged peers gain or lose violations correctly.
- A validation fingerprint change, source-log retention gap, missing baseline, schema change affecting validation, or validator-engine revision forces a new full generation.
- Incremental runs may reach `complete-through-fence` when the baseline plus all changes through their upper fence are proven. Do not carry stale findings silently; every active row records the run that last established it.

## 8. Report, explanation, and export behavior

- Define columns, sensitivities, dependencies, visibility, filters, indexed sort keys, and renderers in one declarative `ScanColumns.php` array. Do not reintroduce type switches in the report layer.
- Keep validation wording in `php/messages/catalog.json`, consumed by PHP and injected selectively into JavaScript. Resolution order is rule message, exact catalog entry, type wildcard, terminal generic fallback. Blank wording is impossible.
- Encode assertions as rule metadata and pooled character problems as a five-bit mask, not repeated free text.
- Show event columns only for longitudinal projects, DAG columns only where applicable, and correct repeat-form versus repeat-event labels. Instrument always comes from the host form.
- Treat missing display labels as reporting degradation, not automatically as failed validation. Store coverage impact explicitly in the aggregate policy configuration.
- Fetch “Last change to this record/event” lazily for unique record/event keys on the current page or export chunk. Validate the server-derived log shard name and inspect representative `EXPLAIN` plans. Drop the column if it cannot be implemented with an indexed bounded query.
- Use server-validated filters and a small whitelist of indexed keyset orders, each tied with `finding_id`. Default to 50 rows and enforce a hard maximum of 100. Permit at most 10 filter axes, 100 filter values total, 255 bytes per value, and 8 KiB of decoded filter input. Reject duplicates or values invalid for their descriptor before querying.
- Keyset cursors are versioned HMAC-signed Base64URL payloads bound to run, generation/run sequence, authorized DAG projection, policy revision, normalized filter hash, sort key/direction, page size, last sort tuple, and a 15-minute expiry. Tampered, stale, cross-filter, cross-scope, or oversized cursors fail before database access.
- CSV is one authenticated streaming response from stored findings. Defuse spreadsheet formulas after leading whitespace, tabs, carriage returns, BOMs, and other control characters; preserve a valid rectangular CSV structure.
- Every CSV begins with schema version, immutable run/view identity, normalized filter hash, expected filtered row count, and current policy revision represented as schema-valid metadata rows. It ends with a mandatory completion trailer containing emitted row count, final authorization result, final policy revision, and `export_complete=1`.
- Reauthorize, recheck DAG-scope validity, and compare policy revision before every export chunk. A disconnect, timeout, rights change, policy change, missing trailer, or emitted/expected mismatch defines an invalid incomplete export even when the underlying scan was complete.
- XLSX is out of scope for this rebuild. Add it only in a later separately reviewed plan that selects, licenses, packages, and memory-tests a PHP-7.4-compatible streaming writer.
- Export metadata includes project/DAG scope, run/generation, target fence, coverage/detail/value states, filters, timestamps, rule fingerprint, collection gaps, and reporting degradations.
- Partial/truncated exports use a filename suffix and schema-valid metadata/trailer state. They may not contain the word “clean.” Complete-run filenames still require consumers to verify the mandatory trailer; filenames alone never prove a complete download.

## 9. Configuration defaults

REDCap 13.7 cannot be assumed to support native system settings with project overrides. Define separate keys and resolve them through one `ScanPolicyConfig` object; effective numeric limits are `min(system_max, valid_project_value)`.

| System setting | Project setting | Default/effective rule |
|---|---|---|
| — | `scan-value-storage` | `locations` |
| — | `scan-record-id-storage` | `raw` presentation; manifest locator remains raw/protected |
| `scan-system-max-value-retention-days` | `scan-value-retention-days` | 30; project may lower |
| `scan-system-max-run-retention-days` | `scan-run-retention-days` | 90; project may lower |
| `scan-system-max-detail-findings` | `scan-max-detail-findings` | 1,000,000; project may lower |
| `scan-system-max-detail-bytes` | `scan-max-detail-bytes` | 512 MiB; project may lower |
| `scan-system-max-concurrent-projects` | — | 2 |
| `scan-system-stale-run-hours` | — | 24 |
| `scan-system-record-attempts` | — | 3 |

Collection-gap behavior is the fixed `separate` policy for this release, represented once in `ScanPolicyConfig`; no `off` mode exists. Hold literal defaults, parsing, clamping, privacy ordering, and blocking-kind policy in that object, not scattered branches or duplicated `config.json` prose. Unknown or malformed settings fail toward less disclosure, less concurrency, immediate preview revocation, and no clean certification.

## 10. Implementation sequence

### Task 1: Immediate page and scope correctness — release 1.6.2

**Files:** modify `pages/scan.php`, `UniversalValidator.php`, `.github/workflows/parity.yml`; create `tests/scan_page_php.php`.

- [ ] Write page tests for proxy users, flat and PID-keyed rights, no rights, unresolved DAG, and refusal of every legacy `run=1`/`csv=1` GET or POST execution attempt.
- [ ] Verify the tests fail against the current page.
- [ ] Replace rights probes with fail-closed callable/shape handling and refuse unresolved DAG scope.
- [ ] Move page helpers to reusable static/class methods to avoid redeclaration.
- [ ] Fix `repeatFormsCache` with API-result and form-set-specific fallback keys; cover single-form-before-all-forms ordering.
- [ ] Disable the production synchronous scan and export-by-rerun controls. Render an explicit temporary notice that the durable scan is unavailable until the new worker feature is enabled.
- [ ] Keep `scanProject` callable only from tests/internal compatibility paths; do not add a client-accessible legacy AJAX action.
- [ ] Add predictive memory and elapsed-time checks only at chunk boundaries; never claim they eliminate OOM.
- [ ] Add `pages/scan.php` to PHP lint and packaged-file assertions.
- [ ] Verify state-changing scan work cannot be triggered by GET and that later authenticated JSMO AJAX uses the framework CSRF path.
- [ ] Run all existing PHP and Node checks and commit the isolated fixes.

### Task 2: Make the existing tests truthful — release 1.6.3

**Files:** modify `tests/hook_php.php`, `tests/hosting_php.php`, `docs/TESTING.md`.

- [ ] Change both `getData` mocks to honor requested records and fields.
- [ ] Add assertions for exact requested chunks, DAG scope, missing primary-key capability, and no unbounded fallback read.
- [ ] Run the scan tests and investigate every newly exposed failure before continuing.
- [ ] Document live capability checks for record enumeration, source fences, log retention, database grants, table naming, and cron.
- [ ] Commit test-fidelity changes separately from production refactoring.

### Task 3: Extract the producer boundary — release 1.7.0

**Files:** create the sink/planner classes under `php/Scan/`; modify `UniversalValidator.php`; create `tests/scan_sink_php.php`.

- [ ] Write differential tests that run every current scan scenario through `ArrayFindingSink` and `RecordingFindingSink`.
- [ ] Implement immutable finding/note/summary value objects compatible with PHP 7.4.
- [ ] Extract rule planning, per-record evaluation, and canonical unique tuple construction without changing verdicts.
- [ ] Keep the legacy façade and byte-for-byte array shape.
- [ ] Aggregate production not-checked notes by configured kind/code/rule with counts and at most 20 samples; retain legacy list behavior only in the array sink.
- [ ] Prove no caller mutates memoized ASTs before adding parse caches; cache successful pattern compilations only and reset caches around PCRE-limit tests.
- [ ] Run differential, fuzz, parity, PHP-version, and Node suites.
- [ ] Commit the complete extraction and all caller conversions atomically.

### Task 4: Measure and lock capability adapters

**Files:** create diagnostic coverage in `tests/scan_capabilities_php.php`; update `docs/TESTING.md`.

- [ ] Measure peak memory and latency for record enumeration, chunk reads, context resolution, findings, and unique candidates on representative projects.
- [ ] Verify the supported paged record-list source or implement and test the read-only keyset adapter.
- [ ] Verify source-version ordering, event taxonomy, log retention, log shard lookup, and DAG-change visibility.
- [ ] Capture `EXPLAIN` plans for manifest, change-fence, lazy-log, page, export, unique, rollup, and purge queries.
- [ ] Confirm database migration privileges or document administrator-run schema installation.
- [ ] Record measured findings/context distributions and keep the schema minimal unless measurements justify expansion.
- [ ] Treat bounded record enumeration as the hard implementation gate. Treat fencing as a capability gate: without it, allow only explicitly labelled `manifest-complete`, disable strict completion and incremental mode, and never improvise an unbounded fallback.

### Task 5: Add the inert durable foundation — release 1.8.0

**Files:** create store/schema/authorization/retention/worker-slot classes; modify `config.json`; create `tests/scan_store_mysql.php`, `tests/scan_security_php`, `tests/mysql/bootstrap.sql`, `tests/mysql/run.php`, and `.github/workflows/scan-database.yml`.

- [ ] Write failing migration and schema-health tests against supported MySQL and MariaDB versions.
- [ ] Implement idempotent schema versions and an administrator-visible health result.
- [ ] Add a CI service matrix for MySQL 5.7/8.0 and MariaDB 10.5/10.11, using InnoDB and the server's default isolation plus explicit READ COMMITTED compatibility coverage.
- [ ] Initialize each service from `tests/mysql/bootstrap.sql`; run `php tests/mysql/run.php` with two independent mysqli connections; tear down by dropping only the test-prefixed schema.
- [ ] Write two-connection tests for the one-active-project constraint, installation-wide slot limits 1/2/N, run/slot lease epoch fencing, browser/cron overlap, stale takeover, CAS rollback, deadlock retry, and terminal unlock.
- [ ] Implement `SqlScanStore` transactions only after the concurrency tests fail correctly.
- [ ] Write authorization tests for cross-project run IDs, full/partial host access, inaccessible dependency-only/composite/enrichment forms, unknown field ownership, every export level, Identifier rights, global/DAG creators, generic busy responses, exact-scope work/cancel, phase-only pre-fence DAG status, rejection without DAG-change fencing, target-fence projections, move-in/move-out staleness, revoked rights, status, findings, and exports.
- [ ] Implement current-rights and bounded current-DAG revalidation with non-disclosing denial behavior.
- [ ] Write cancellation tests before read, during `getData`, before commit, during both finalizers, and after stale takeover; every stale commit must fail its epoch/phase/policy CAS.
- [ ] Write quota, value-expiry, immediate privacy-downgrade revocation/purge, run-purge, cascade, and failed-write tests.
- [ ] Inject real database failures with `KILL CONNECTION`, reverse-order update deadlocks, held-lock timeout, and temporary INSERT/UPDATE privilege revocation; use store-boundary fault injection only for disk-full behavior that the service container cannot safely reproduce.
- [ ] Test fresh install and upgrade fixtures from every prior schema version under every database service.
- [ ] Implement HMAC purpose separation, binary value metadata, TTLs, quotas, audit events, and cleanup.
- [ ] Keep the durable execution feature disabled after migration.
- [ ] Run unit, integration, security, and migration-upgrade tests; commit only when all pass.

### Task 6: Ship full durable scanning as one feature — release 1.9.0

**Files:** create planner/manifest/fence/worker classes and `js/scan.js`; modify `UniversalValidator.php` and `config.json`; create `tests/scan_worker_php.php`.

- [ ] Write state-machine tests covering every phase including `cancelling`, nullable active `terminal`, and every row of the terminal-state derivation table.
- [ ] Write stable-read race tests for edits between version reads, hot records, missing records, additions, deletions, DAG moves, and repeat changes.
- [ ] Implement bounded manifest planning and immutable fingerprints.
- [ ] Implement browser and cron work entrypoints over the same worker service.
- [ ] Implement adaptive ordinal claims, lease renewal, cancellation, retries, and aggregate-only quota transition.
- [ ] Write cross-batch unique tests, including invalid bytes, long Notes values, composite/event/DAG scope, HMAC-group collision verification, and idempotent retries.
- [ ] Add a million-candidate single-group test proving bounded memory, resumable verification/emission cursors, staged visibility, candidate-epoch restart, collision degradation, and crash-safe retries.
- [ ] Implement the persisted group finalizer and staged publication before enabling the feature.
- [ ] Write killed-worker and resumable rollup tests.
- [ ] Implement catch-up, target-fence reconciliation, unique finalization, rollups, and the single terminal-promotion predicate.
- [ ] Verify every failure leaves a resumable or truthful terminal state and never a false complete state.
- [ ] Enable full durable scanning behind a project/system feature flag and run a real-server pilot.

### Task 7: Add the actionable report and exports — release 1.10.0

**Files:** modify `pages/scan.php`; create `pages/scan-export.php`, `php/Scan/ScanColumns.php`, `php/Scan/MessageCatalog.php`, `php/messages/catalog.json`, and `tests/scan_export_php.php`.

- [ ] Write filter, visibility, sensitivity, unknown-type fallback, and indexed-sort contract tests.
- [ ] Write paging abuse tests for page sizes 0/100/101/unbounded, filter-axis/value/byte caps, invalid descriptor values, cursor tampering, expiry, and cross-run/filter/scope/policy reuse.
- [ ] Implement the declarative column catalog and total explanation fallback.
- [ ] Create PHP/JavaScript message parity fixtures and migrate check-character strings without changing verdicts.
- [ ] Write page tests for every coverage/detail/value combination and the shared clean predicate.
- [ ] Replace legacy synchronous rendering with progress, resume, cancel, collection gaps, rollups, and keyset-paged findings.
- [ ] Write hostile CSV tests for formula prefixes after whitespace/control characters, invalid UTF-8, NULs, 64 KB values, multiline text, partial state, truncation metadata, disconnect, timeout, rights revocation, policy revision, absent trailer, and emitted/expected mismatch.
- [ ] Implement one-response streaming CSV; keep XLSX absent from configuration, package, UI, and documentation.
- [ ] Verify DAG users cannot infer global counts through filters, cursors, export metadata, timing-specific errors, or filenames.
- [ ] Remove rerun-on-export and whole-result production paths.

### Task 8: Add fenced incremental generations — release 1.11.0

**Files:** extend planner/worker/store classes; create `tests/scan_incremental_mysql.php`.

- [ ] Write full-versus-incremental differential tests over randomized edit/add/delete/DAG/repeat sequences.
- [ ] Write unique fan-out tests where a changed record makes or breaks violations for unchanged peers in old and new groups.
- [ ] Implement versioned finding intervals and current unique-candidate state.
- [ ] Implement upper-fence change selection and atomic record replacement.
- [ ] Force full generations on fingerprints, retention gaps, missing baselines, or unsupported source-fence capabilities.
- [ ] Keep incremental disabled by capability by default until live-server tests prove no missed event taxonomy.
- [ ] Verify historical as-of-run exports remain reproducible after later incremental runs.

### Task 9: Load, failure, and rollout acceptance

**Files:** create `tests/scan_load_php.php`; update `docs/TESTING.md`, `README.md`, `docs/USER_GUIDE.md`, and `CHANGELOG.md`.

- [ ] Exercise 100,000 records with representative rules and millions of findings.
- [ ] Exercise a synthetic one-million-record manifest without constructing a whole-project PHP array.
- [ ] Verify worker memory remains below the configured reserve and query counts scale as O(chunks), not O(findings).
- [ ] Verify keyset paging remains stable at the end of a multi-million-row run.
- [ ] Trigger the detail-row and byte budgets and prove every record is still evaluated aggregate-only.
- [ ] Test disk-full/write-error simulation, deadlocks, connection loss, timeout, browser closure, cron takeover, stale worker wake-up, cancellation, and purge during idle periods.
- [ ] Run a 39-record equivalence pilot using fixtures with no eligible never-started instruments, then target-project pilots with the mandatory separate collection-gap policy.
- [ ] Run a live 100,000-record or largest-available project and record elapsed time, peak memory, storage bytes, query counts, retries, and final fence.
- [ ] Update documentation to define coverage, detail truncation, privacy modes, retention, DAG projections, collection gaps, exports, and operational recovery.
- [ ] Enable by default only after the database, security, live-REDCap, and load acceptance gates pass.

## 11. Acceptance criteria

The rebuild is complete only when all of the following are demonstrated:

- Any project size can be traversed without a whole-project PHP accumulator.
- A killed request, expired lease, retry, OOM-risk stop, timeout, or browser closure cannot produce false completion or permanent lockout.
- Every complete run identifies its target fence and proves every record stable at or beyond it.
- Record creation/deletion substitution, concurrent edits, DAG movement, and repeat changes cannot evade catch-up.
- Full and incremental outcomes are equivalent for the same terminal data state, including unique peers.
- One global plus multiple DAG users cannot multiply project scan concurrency.
- A DAG user cannot read or infer another DAG's records, values, counts, filters, or exports.
- Raw values are never stored without explicit opt-in; missing identifier metadata redacts rather than exposes.
- Invalid bytes and spreadsheet payloads cannot break rendering or execute as formulas.
- Detailed-storage exhaustion degrades to bounded aggregates while validation continues over every record.
- Pages issue O(1) bounded queries, scan work issues O(chunks) queries, and no enrichment performs a per-finding query.
- The current PHP and Node parity suites remain green on PHP 7.4, 8.1, and 8.3.

## 12. Explicit assumptions

- Full scans retain PHP 7.4 and REDCap 13.7 compatibility through capability adapters.
- Strict incremental mode is unavailable when monotonic source versions and retained change logs cannot be proven.
- Module-owned tables are a deployment prerequisite; sites without migration permission must have an administrator install the documented schema.
- Framework crons and authenticated JSMO AJAX are the only execution mechanisms; no external queue or object store is required.
- The scan evaluates all records, but finite infrastructure cannot promise unlimited detailed findings. Bounded aggregate-only continuation is the defined safe degradation.
- All thresholds and blocking policies are configuration, not scattered hard-coded business logic.
