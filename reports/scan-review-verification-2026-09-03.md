# Verification of the 2026-09-03 adversarial review

Every finding in `reports/validation-scan-adversarial-review-2026-09-03.md` checked against the
code at `0799fac`, then cross-referenced against `reports/scan-remediation-plan-2026-08-25.md`
(waves 1-5 landed, 6-12 outstanding).

53 agents. Each was told to reproduce or refute rather than agree, to run something wherever
running was possible, and to look for what the review missed nearby. Confirmed Critical and High
findings then went to three adversaries each — one trying to refute outright, one attacking the
severity, one attacking the proposed fix. Most agents wrote throwaway PHP probes that drove the
shipped classes; those probes are quoted in the evidence and were deleted.

The verification itself ran with no MySQL available, so anything needing a live server was
marked rather than guessed at. **The matrix has since run** — see "The database matrix" below,
which resolves most of what was open and found one defect of its own.

## The headline

**32 findings. 0 refuted. 23 needed a correction.**

| | |
|---|---|
| CONFIRMED as written | 8 |
| CONFIRMED, with a correction to mechanism or severity | 23 |
| REFUTED | 0 |
| UNVERIFIABLE offline | 1 (L-2) |

The review was accurate throughout, which is worth saying plainly. Where it needed correction, the
correction was usually about *which* consequence lands first, not about whether the defect exists.

Six severity disagreements: **M-2, M-4 and M-7 are lower** than filed; **L-6, COMPLIANCE-5 and
HOLDS-CSV are higher**. The last two are the interesting ones, because both were filed as things
that were *fine*.

## Fixed today

Five commits. Three are defects this branch introduced, and finding them is the main reason this
verification was worth running.

| Commit | What |
|---|---|
| `3de4f1a` | **`//` comment inside a SQL string** — regression from `c86a974` |
| `2204742` | **Survey throttle inert by default** — regression from `b49354e` |
| `ec02946` | Both throttle tiers now run — pre-existing, not this branch's doing |
| `114751d` | **CSV formula injection bypass** — the review filed this under "what holds up" |
| `2d8d9ba` | `preview()`/`expireValues()` scope disagreement documented (L-7) |

### The three regressions

**`c86a974` put five `//` lines inside a single-quoted SQL string** in `ScanRetention::revokePreviews()`.
MySQL takes `#`, `-- ` and `/* */`, and answers `//` with ERROR 1064. The statement could not parse
on any server — so the cross-project scoping fix those five lines *describe* has never once run.
The commit broke the thing it was explaining. Nothing caught it because the method has no
production caller and there is no MySQL here; a suite cannot fail on a statement it never sends.
Guarded now by a lint (`namespace_lint_php.php` L-06), which measured against the whole shipped
tree flags nothing but the defect.

**`b49354e` made the survey throttle inert on every default installation.** That one is mine, from wave 5.
It moved tier 2 from a system-setting counter to `uv_rate_bucket`, whose DDL sits in
`statementsV2()`, reachable only through `installScanSchema()`, which returns early unless
`scan-system-enable-durable` is set. That flag is off by default and `config.json` tells the
administrator to leave it off until they have piloted the scan. So the table did not exist, the
increment threw, and the catch returned `false`. The only rate limit on `unique-check` — the
module's only unauthenticated action — was gone.

That commit message named the failure mode and misjudged it: it justified failing open so a
missing table "on an installation that has not migrated yet" would not refuse survey responses. It
treated the missing table as a transient upgrade state. It is the documented steady state.

One correction to the review's number, from the verification: main's counter was a read-modify-write
and lost increments under concurrency, so against a *concurrent* flood main was already close to
unbounded. The real loss is a serial cookieless enumerator, which main bounded at 600/min/project
and this branch bounded not at all. Measured by the verifying agent: 4400 refusals in 5000 requests
on main, 0 in 5000 here.

Two more defects surfaced while fixing it, both found by adversaries rather than by the review:

- An unreadable count was read as zero. `isset($r[0][0]) ? ... : 0` answered "under the cap" for any
  result shape `ModuleDb::rows()` could not walk — no throttle, no pruning, nothing logged.
  `ModuleDb::exec()` already refuses to guess a row count for exactly this reason; the read-back was
  more trusting than the write.
- **The table fix alone would have been close to inert.** Tier 1 returned as soon as it passed, so
  tier 2 was reached only by a caller carrying no session at all — and REDCap starts a session on
  most paths. Discarding the session cookie between requests took a fresh 30-request budget every
  time and never met the per-project cap. That is byte-identical to main, so it is a design fix
  owed before this branch existed, and it got its own commit. Sessioned and sessionless traffic now
  count in separate buckets with separate caps, so bounding the evasion does not throttle a busy
  survey.

**`bee6a85`** added the paged DELETE in `Schema::upgradeDataV2()` (M-4). The plan prescribed it
verbatim, so it is an unspecified consequence of a correctly executed plan item rather than a
deviation. The verification lowered it to Low: the loop is hard-bounded, it makes forward progress
across retries, `verifyV2()` catches the degenerate case loudly, and `docs/INSTALL.md:164-172`
already warns the operator. Left alone deliberately.

### The one the review scored as passing

`HOLDS-CSV` was filed under **What holds up**, called "the detail most implementations miss". The
named detail is handled. The defence as a whole was bypassable by one byte:

```
"\x01=1+1"               ->  =1+1       live formula
"\x1A=1+1" "\x1B" "\x7F" ->  =1+1       live formula
" \x01 =cmd|'/c calc'"   ->   =cmd|...  live (Excel strips the spaces)
```

The leading-byte scan ran on the raw value, stopped at the first byte outside its skip set, and
concluded the cell was not a formula. `scrub()` then deleted exactly that byte, promoting the `=`
to the first content byte. Every working prefix comes from `scrub()`'s own removal set: the
sanitiser armed the payload. The comment defending the operation order had the causality backwards.

Latent today, since the exporter has no caller. Wave 11 wires it, which is why it is fixed now.

A verified positive hides a finding as effectively as a missed one. Checking the review's
"what holds up" section was worth more than checking most of its findings.

## Where the remaining findings sit

| | Count |
|---|---|
| Already scheduled in waves 6-12 | 17 |
| Genuinely new | 13 |
| Contradicts a decision already recorded | 2 |
| Regressions (all fixed or filed above) | 5 |

**Most of the review overlaps the existing plan**, including its Critical. That is not a criticism
of the review — the reviewer had no reason to know what was scheduled.

### C-1, the Critical, is wave 6

`ScanPageView::scanScope()` returns a group **name**; every consumer compares it against a group
**id**. Reproduced end to end, with both sides of the comparison printed. It is the plan's B3/B3b/B3c,
which was already the next wave.

The verification inverted the review's two consequences. The review leads with "a group-scoped run
certifies a group it never read"; the immediate and certain consequence is that the DAG designer
who started the run is refused `scan-work`, `scan-status` and `scan-cancel` on **their own run**,
which then holds the project's only scan slot with no reaper. The false certification is downstream
of a *recovery attempt* — it needs a second, unrestricted designer to press Continue. Both are real;
the wedge comes first.

Also worth carrying into wave 6: `tests/scan_security_php.php:228` seeds group **names** into the
rights array and asserts against name-shaped scopes, while the planning suite is written entirely
on ids. Each suite is internally consistent, which is why 1,228 green checks cannot see the seam.

### The most serious genuinely-new finding

**COMPLIANCE-5**, filed by the review as a documentation item, raised to a security finding by the
verification and upheld 3/3 on adversarial review.

`\REDCap::getData()` is called with a project id and no user context. The compensating control —
`ScanAuthorization::mayStart()` requiring Full Data Set export plus non-zero access to every
instrument — exists and works. But the entitlement set it is asked about is built from
`$plan['hostFields']`, which covers only rule *host* fields, while the read pulls `$plan['readSet']`:
host fields **plus** every field referenced by a `when`/`assert` condition **plus** unique-composite
`with` fields. Those extra fields decide findings.

Reproduced: a designer with explicit No Access to form `fb` starts a scan whose rule lives on form
`fa` with `"when":"[b_secret]='1'"`. `mayStart()` returns true, `getData` reads `b_secret`, and the
finding count differs by its value. The gate is otherwise live — export level 2 is refused, a barred
host form is refused — it is simply never asked about the instruments the condition operands live on.

The proposed fix is to widen ownership to cover `ruleRefFields()`, so a field whose form cannot be
resolved lands in the existing `$unknown` branch and refuses. Explicitly *not* by enabling
`enforceFormRights` on the durable path: that filters rules rather than refusing the run, and
`ScanAuthorization.php:14-21` records why whole-report denial was chosen over filtering.

Not fixed here — it touches `scanPlan()`/`durableScanContext()`, which is wave 6 territory and
interacts with C-1's fix. It should be wave 6's second item.

### M-6 makes every scoping bug a certification bug

An empty manifest promotes to `complete-through-fence`, `clean=true`, and the page says "Every
record was checked, including changes made while it ran." Confirmed outright, no correction:
`ScanPlanner::plan()` checks `freezeManifest()` only for `false`, so `0` falls through to `ok=>true`;
`openRun()` discards the total; `pending === 0` over an empty census reads as `manifestDone`.

This is the amplifier. Any bug that empties a manifest, C-1 included, becomes a false clean bill
rather than an error. Worth doing before C-1, not after, so the class of defect is capped even if
another scoping bug is found later.

### Two findings contradict decisions already on record

- **H-2(b)** describes findings as "readable by anyone with design rights and Full Data Set export".
  The read path does not exist: `pages/export.php:31` is an unconditional 503 and wave 11 turns it
  on, deliberately, because a findings page built before wave 4's project scoping would have been a
  cross-project leak. The retention failure is real; the exposure is database-layer only.
- **L-2** questions `framework-version: 14` against `redcap-version-min: 13.7.0`. That pairing is
  recorded in `CHANGELOG.md:3780-3784` and `docs/TESTING.md:132` as a deliberate 0.3.0 decision, and
  is byte-identical to main. Confirming it needs REDCap release history, which is not available
  offline — the one UNVERIFIABLE item.

## What I would do next

1. **M-6** — the empty-manifest certifier. Small, and it caps the blast radius of everything below.
2. **Wave 6 as planned** — C-1/B3, with the security suite's name-vs-id seeding corrected.
3. **COMPLIANCE-5** — as wave 6's second item, while the plan code is open.
4. **Wave 10 (H-2/H-3/M-5/COMPLIANCE-1/2)** — the cron and the lifecycle. Four inert safeguards, and
   `value_expires_at` is written by every finding and read by nothing, so a project-facing retention
   setting currently does nothing.
5. **H-4** — the unwired optional dependencies. The verification produced a full dep-vs-supplied diff.

## The database matrix

Docker came up, so the matrix ran for the first time since wave 3: four engines (MySQL 5.7 and 8.0,
MariaDB 10.5 and 10.11), each on the server default isolation and again under READ COMMITTED, plus
CI's check that no table is left behind. **8 runs, 399 checks each (401 under READ COMMITTED), 0
failures, nothing left behind.**

Getting there took three fixes, and the first two are why the gap mattered.

**The matrix could not reach its first assertion.** Wave 5 made `commitBatch` refuse a finding with
no record ordinal. `ScanWorker` stamps it, so every mocked suite stayed green; the database cases
build batches by hand, and `uv_plant_neighbour()` in the shared fixture did not. All eight cases
threw the same exception before asserting anything. The refusal is correct and production supplies
the field, so this was the test suite lagging the contract — but it meant 27 green PHP suites stood
over a store that would have refused every batch shape the cases meant to exercise. 144 checks
reached before the fix, 373 after.

**Abandonment was tested on the wrong clock.** Wave 5 moved `expireAbandoned()` from `updated_at` to
`COALESCE(progress_at, created_at)`, precisely because `updated_at` moves on a touch that did
nothing. The test still backdated `updated_at` and expected expiry. It did not expire, correctly:
the run had committed a batch moments earlier. The test now asserts both halves — an ancient
`updated_at` with recent progress is not abandonment; an aged `progress_at` is — which is the
distinction wave 5 introduced and nothing tested.

**And one real defect in production code.** `UniqueFinalizer::nextUnfinished()` can be served by two
indexes: `uq_group_v2` gives the `ORDER BY` for free and then reads every group to test `phase`;
`ix_pending` seeks straight to the pending rows. The difference shows only when nothing is pending,
which is the state the method spends most of its life in, because that is how `step()` learns it is
done. Measured on 1,200 groups:

| Engine | Without fresh statistics | After `ANALYZE TABLE` |
|---|---|---|
| MySQL 5.7 / 8.0 | `ix_pending`, 3 rows | `ix_pending`, 3 rows |
| MariaDB 10.5 / 10.11 | `uq_group_v2`, **1,200 rows** | `ix_pending`, 3 rows |

MariaDB takes the scan, and which plan it takes depends on statistics freshness. The timing is the
worst available: `discover()` bulk-inserts every group and `step()` walks them immediately, so the
optimiser reads statistics describing the table before the insert. `FORCE INDEX (ix_pending)` holds
the plan at 3 rows on all four engines with statistics deliberately stale. This is the P7 work
sitting inert on half the supported fleet, and only an `EXPLAIN` assertion on the statement the code
actually issued could have attributed it — a timing test would have called it noise.

## Still not verified

- **No case for the rate-limit counter under two connections.** `uv_rate_bucket`'s atomicity is
  still asserted only against a single-process stub. The matrix is the right home for it and does
  not have one yet.
- **M-2**'s `GROUP BY CAST(pk AS BINARY)` claim on `redcap_log_event` needs its own `EXPLAIN`
  against a table with realistic cardinality. Its code claims are confirmed; the optimiser
  behaviour is not, and after the `nextUnfinished()` result it should be measured rather than
  reasoned about.

## Suites

27 PHP, 18 JS, and the 8-run database matrix green at every commit.
suite re-run, and the specific checks confirmed red. The `//` lint reports `ScanRetention.php:83`;
removing the throttle installer reddens 2 hook and 4 hosting checks; restoring the guess-zero
reddens 1; removing the log call reddens 8; restoring the tier-1 short-circuit reddens exactly the
4 checks describing the fall-through; restoring the CSV order reddens 8.
