# Validation scan: round 3 — verification of the 1.8.1–1.8.5 fixes

Fourth pass. Verifies `reports/scan-implementation-review-2026-08-17.md` (read),
`scan-wargame-2026-08-17.md` (round 1) and `scan-wargame-round2-2026-08-17.md` (round 2)
against the shipped fixes.

**Code under test:** `main` at `d7f1b1f` — 1.8.1 through 1.8.5, 298 lines changed in
`UniversalValidator.php` plus every scan file.

**Harnesses:** `tools/temporal_scan_verify3.php` (21 property probes),
`tools/temporal_scan_regress3.php` (5 regression probes), plus re-runs of rounds 1 and 2.
All in `tools/`, which is `export-ignore`d.

```bash
php tools/temporal_scan_verify3.php  .
php tools/temporal_scan_regress3.php .
```

## Headline

| | Round 1 (handed over) | Round 2 (never handed over) | New |
|---|---|---|---|
| Confirmed defects | 27 | 10 | — |
| Fixed | **23** | 0 | — |
| Still open | 4 | 10 | 2 regressions + 1 latent |

Suite on the fixed tree: 892 checks, 0 failures across eight files
(`hosting_php` 121→146, `scan_page_php` 78→93, `scan_capabilities_php` 53→63).

**The fixes are real.** I re-verified every one by asserting the property rather than the old
symptom, because a probe that checks yesterday's string reports a fix that never happened. Both
directions of that error occurred here — see "probe staleness" below.

---

## Round 1: 23 of 27 closed, with evidence

Each verified by a probe that asserts the new behaviour, not the absence of the old one.

| Probe | Property now holding | Evidence |
|---|---|---|
| V1 | Defusing looks past whitespace/BOM; NUL, SUB, ESC stripped; TAB/CR/LF kept | cell hex `226f6b4e554c45534322`, no bypassing prefix |
| V2 | `log-values=none` leaks no raw id in any channel | note reads `record 797ae0f8…`; export clean |
| V3 | No HMAC key → a marker | `'[record id unavailable]'` |
| V4 | Halted scan reports records **examined** | `{"records":0,…,"manifest":400}` — the manifest size moved to its own key |
| V5 | Empty scope cannot certify | `status=incomplete`, *"no record was in scope … this is not evidence that the group's data is clean"* |
| V6 | DAG + project-scope unique is disclosed and blocks clean | rule problem raised, `clean=false` |
| V7 | A throwing sink is caught | `status=incomplete`, *"record 1 could not be reported: RuntimeException"* |
| V9 | `degraded[]` stated on page and in file | `degradedSummary()` at `scan.php:193`, `export.php:123` |
| V10 | Export will not certify broken rules | filename `…_NOT-CERTIFIED.csv` |
| V11 | Rectangular, header always present | widths `13,13,13,13` and `13,13` |
| V12 | Export keeps the execution budget | `max_execution_time` stays 30 |
| V13 | Value policy closed by default and on failure | both `NULL` |
| V14 | `?csv=1` redirects | `Location: /x/pages/export.php`, no second schema |
| V15 | Hidden choice has its own label | `No longer an allowed choice` |
| V16 | Label and message reach single/pooled | both present |
| V17 | Labels follow the rule list the scan used | no misattribution across a reordered read |
| V18 | Capability probes fail closed | no false CREATE positives; fence `unavailable` on a bare table name |
| V19 | A fenceless run cannot claim whole-project proof | `coverage=manifest-complete`, export hedges |
| V20 | No second copy of the id list | `array_chunk($ids…)` gone |

`ScanCapabilities::policy()` is now wired — 1.8.1 recorded it as an unmet deviation, and 1.8.4
closed it. `recordEnumeration()` now issues a real bounded probe query
(`probeKeysetWalk`, `ScanCapabilities.php:373`) and correctly treats an empty project as
legitimate while catching a failed query.

## Round 1: 4 still open

### R1-a — two events still render identically when event metadata is unreadable

`ScanColumns.php:76` gates the Event column on `$d->longitudinal`. 1.8.5 added two fallbacks for
deriving that flag, but when `getEventNames()` and `getInstrumentEventMappings()` are both
unusable, all three routes fail and the column is dropped.

```
Event column present = false
rows identical       = true
findings carry event_id [10,20] and the report drops it
```

The findings hold the event ids. The degraded note now says the names are unreadable — an
improvement — but the operator still cannot tell the two rows apart, and no fallback shows the
raw id. Narrower than before and no longer silent, but the data is still unlocatable.

**Fix:** derive `longitudinal` from the findings when metadata fails, and let `event()` fall back
to the raw id as its own docblock already promises.

### R1-b — the withheld/blank fix does not achieve what its comment claims

`UniversalValidator.php:2448`:

```php
// 'locations' WITHHELD a value that exists; a finding with no value has
// nothing to withhold. Both used to return null and render as the same
// empty cell, which is exactly what a genuinely blank required field renders too.
if ($mode === 'locations') return array_key_exists('value', $v) ? false : null;
```

The required path sets `'value' => ''` unconditionally (`UniversalValidator.php:502`), so the key
**always exists** and the `false` branch always wins:

```
required-blank (nothing to withhold) Value = '[withheld by policy]'
policy-withheld value                Value = '[withheld by policy]'
```

The one finding type the comment singles out is the one it still gets wrong — and now it makes an
affirmative false statement, asserting a value was withheld where none exists. Worse in meaning
than the empty cell it replaced.

**Fix:** branch on the value being non-empty, not on the key existing.

### R1-c — the record list is still materialised before any budget check

`REDCap::getData()` with no `records` key still runs first, so the manifest is built unguarded.
Architectural, and squarely inside the durable rebuild's scope. Recorded, not urgent.

### R1-d — `@UVALIDATE` still rejects `message`

```
unknown @UVALIDATE option(s): message — valid: … blockSave, when, suggestFix, note.
```

Now that the settings dialog carries `message` for single and pooled rules (V16), annotation-
configured check-character rules are the only ones with no wording channel. Minor, and possibly
deliberate.

---

## Round 2: 10 of 10 still open

Round 2 was never handed to the devs, so this is the control group — and it behaves like one.

**Y1 / Y12 — the DAG filter still fails open.** `UniversalValidator.php:2150` is unchanged:

```php
if ($dagFilter !== null && is_array($node) && dagOf($node) !== $dagFilter) continue;
```

`is_array($node)` remains a conjunct of the exclusion test, so a node REDCap does not return as an
array is **admitted** to a DAG-scoped scan. The 1.8.x record-id hashing does not cover it, because
the id reaches the file through the `incomplete` notes:

```
# scan of project 700 … | scope: Data Access Group "north" ONLY | records 2 | rules 1 | findings 1
contains "OTHERS" : true
```

This is now the most serious open item in the module. It is a four-token fix.

**Y3 — a rule on an instrument mapped to no event never runs**, with no violation, no rule
problem, and `status=complete`. Every other unevaluable condition reports itself; this one does
not.

**Y11 — never-started instruments still emit one violation per record** (1000 rows from 500
records), with no collection-gap concept in the result shape. Plan §1 requires aggregation. The
longer this waits the more expensive it gets: adding a gap dimension later breaks
`scanProject()`'s contract and both exporters.

**Y6 — the authorization gap is narrowed but not closed.** The value-policy fix means the
restricted field's value now comes back `NULL` instead of `'SECRET'`, so it is a metadata leak
rather than a data leak. `scanScope()` still reads only `hasDesignRights` and `group_id`, and
`getData()` still carries no `userid`.

**Y4, Y7, Y8, Y9, Y10** — instance column ambiguity, truncation dropping a combining mark,
catalog-cache degradation, double explanation resolution per row, unique candidates retaining
value bytes — all unchanged.

---

## New: 2 regressions introduced by the fixes

### N1 — `?csv=1` now runs the scan twice

`pages/scan.php:47` runs the scan for `$run || $csv`. The `if ($csv)` block at `:65` then discards
`$result` entirely and issues `Location: pages/export.php`, which scans **again**.

```
manifest reads performed before the redirect = 1
record chunks read and thrown away           = 1
headers = ["Location: /x/pages/export.php"]
```

The 1.8.5 change to collapse two exporters into one is right; it just landed below the work it
makes pointless. On a project where a scan takes 20 s, the deprecated route now costs 40 s and
double the database load, and the first scan's findings are thrown away.

**Fix:** move the `if ($csv)` redirect above the `if ($run || $csv)` scan, and drop `$csv` from
that condition.

### N2 — control-byte stripping covers the CSV but not the page

1.8.5 strips NUL, SUB and ESC in `ScanPageView::csv()`. `ScanPageView::h()` is untouched:

```
csv()  hex = 226f6b4e554c45534322      (stripped)
h()    hex = 6f6b004e554c1b455343      (raw NUL and ESC)
```

The same stored value is sanitised in the downloaded file and passed through raw into the HTML
table. ESC into a terminal is the case the changelog itself cites for `cat`; the page is the other
half of that surface.

**Fix:** strip in one place — a shared `scrub()` both `h()` and `csv()` call.

### N3 (latent) — `reportValue()` defaults to `raw`

```php
$mode = isset($plan['valueMode']) ? $plan['valueMode'] : 'raw';
```

`scanValueMode()` is now carefully fail-closed, and `valueRank()`'s docblock states *"Unknown ranks
LOWEST, so anything unrecognised is treated as the least disclosing option."* Twenty lines above
it, a missing key defaults to the **most** disclosing option. Not reachable today — `scanPlan()`
always sets it — but it is a fail-open default in the one function whose job is to withhold, and
it contradicts the posture stated beside it.

---

## Probe staleness, in both directions

Five of my 28 round-1 probes returned the wrong verdict against the fixed code:

- **W8, W14, W18, W20 said CONFIRMED when the defect was fixed** — they asserted old strings
  (`"Record"` with a capital R after headers became keys), or checked a construct in the probe
  rather than in the code.
- **W11 said "not reproduced" when the defect was open** — the cell text changed from `''` to
  `[withheld by policy]`, which flipped the assertion while the substance stayed (R1-b).

That last one is the dangerous direction: a stale probe certifying a fix that did not happen. It
is the same failure mode as the mock-shape defect 1.8.5 documents in its own suite, and the
reason every round-3 verdict is a property assertion with its evidence printed beside it.
Treat any claim in these four reports that was not executed as untested.

---

## Standing register

**Open, ranked.**

| # | Defect | Origin | Probe |
|---|---|---|---|
| 1 | DAG filter fails open; out-of-group id reaches the file under a one-group header | R2 | Y1, Y12 |
| 2 | Design rights alone read forms the user cannot access (`getData` has no `userid`) | R1 B4 | Y6 |
| 3 | A rule on an unmapped instrument never runs; project reported clean | R2 | Y3 |
| 4 | `?csv=1` runs the scan twice | **new** | N1 |
| 5 | Never-started instruments emit one violation per record | R2 | Y11 |
| 6 | Required-blank asserts "withheld by policy" | R1 | V21 |
| 7 | Control bytes sanitised in the file, raw in the page | **new** | N2 |
| 8 | Two events indistinguishable when event metadata fails | R1 | V8 |
| 9 | Instance column cannot separate repeat instance 1 from a base row | R2 | Y4 |
| 10 | Truncation drops a combining mark | R2 | Y7 |
| 11 | Unique candidates retain value bytes project-wide | R2 | Y10 |
| 12 | Explanation resolved twice per row | R2 | Y9 |
| 13 | Record list materialised before any budget check | R1 | W16 |
| 14 | `reportValue()` defaults to `raw` on a missing key | **new** | N3 |
| 15 | `@UVALIDATE` rejects `message` | R1 | W27 |
| 16 | Catalog cache degrades every row on one bad read | R2 | Y8 |

**Four edits stop the leaks.** Items 1, 2, 4 and 7 are all small and local:

1. `UniversalValidator.php:2150` — hoist `is_array($node)` out of the exclusion test and note the
   unreadable node.
2. `pages/scan.php:47` — move the `$csv` redirect above the scan.
3. `ScanPageView` — one `scrub()` shared by `h()` and `csv()`.
4. `UniversalValidator.php:2452` — branch on a non-empty value, not on key existence.

Item 2 in the table (rights) and items 3 and 5 (silent skip, collection gaps) need a decision
about scope, not a patch.

## Credit where it is due

The 1.8.x work is the good kind. It fixed the substance rather than the symptom: `manifest` became
its own stat instead of being renamed; the clean predicate grew a `coverage` axis instead of a
warning string; `recordEnumeration()` gained a real probe query instead of a better comment; the
`_NOT-CERTIFIED` suffix and the *"this is not evidence that the group's data is clean"* wording
say what they mean. 1.8.5's own note about the mock-shape defect — that two assertions passed
*because the labels were unreadable, not because of project shape* — is a better articulation of
that failure than the one in my report, and the PHP 7.4 control-byte slip was caught by the
matrix leg that exists for exactly that.
