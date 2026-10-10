# Validation scan: round 2 — verification and a new attack surface

Third pass. Companion to `scan-implementation-review-2026-08-17.md` (read) and
`scan-wargame-2026-08-17.md` (round 1).

## Verification result: nothing has been fixed

```
HEAD          c1635d7  "1.8.0: reach the export, render one column list, …"
tracked diff  (empty)
```

The tree is unchanged apart from the three report/tool files this review added. Re-running the
round-1 harness against the current code:

**28 probes, 27 defects still reproduce. 0 findings closed.**

The existing suite is still green — 626 checks across `hosting_php` (121), `scan_page_php` (78),
`scan_capabilities_php` (53), `hook_php` (285) and `crossform_resolution_php` (89), 0 failures.
Every defect in both rounds lives underneath that green.

## Round 2 result

New harness: `tools/temporal_scan_wargame2.php`. Round 1 attacked the report layer; round 2
attacks **what the scan finds** — scope enforcement, silent skips, and agreement with the
save-time audit.

```bash
php -d memory_limit=1G tools/temporal_scan_wargame2.php .
```

**12 probes, 10 defects confirmed, 2 came back clean.** Two of the ten outrank most of round 1.

---

## Y1 — the DAG filter fails OPEN, and I said the opposite

`UniversalValidator.php:2150`:

```php
foreach ($idData as $rec => $node) {
    if ($dagFilter !== null && is_array($node) && self::dagOfRecordNode($node) !== $dagFilter) continue;
    $ids[] = $rec;
}
```

`is_array($node)` is inside the exclusion test. A node that is **not** an array short-circuits
the whole condition, `continue` never fires, and the record is admitted to a DAG-scoped scan.

A non-array node is precisely the case where the record's group cannot be determined. The guard
admits exactly the records it cannot vouch for.

```
records scanned under DAG "north" = 2   (one record is in north)
out-of-scope id in incomplete[]   = YES
```

**Round 1 recorded this under "checked and clean" — *"The DAG filter fails closed: a record
whose group cannot be read is excluded, not included."* That was wrong.** I read the guard as
if `is_array` were a separate precondition. It is a conjunct of the exclusion test, which
inverts it. Correction issued below.

**Fix:** hoist the shape check and fail closed on it.

```php
if ($dagFilter !== null && (!is_array($node) || self::dagOfRecordNode($node) !== $dagFilter)) {
    continue;   // and record a note, so an unreadable node is not a silent drop either
}
```

## Y12 — that leak lands in a DAG user's downloaded file

Y1 plus the export. The record admitted by the broken guard fails the chunk read (it is not an
array), so it becomes an `incomplete` note carrying its raw id, which `pages/export.php` writes
twice — once as a `#` comment (`:87`) and once as a `not-scanned` data row (`:118`).

The file's own metadata line, from the same run:

```
# scan of project 700 at 2026-08-17T21:40:38+00:00 | scope: Data Access Group "north" ONLY | records 2 | rules 1 | findings 1
contains "OTHERS" : true
```

The header asserts the scope is one group. The body contains an identifier from outside it. The
count says 2 for a group holding 1. Three claims in one file, none of them true, in the artefact
that gets emailed.

Chain with X2 from round 1 (`log-values=none` leaks raw ids through the same notes) and the
strict-privacy mode leaks an out-of-group identifier to a DAG-confined user.

## Y3 — a rule on an unmapped instrument is never evaluated, and nothing says so

`hostContextsFor()` drops every context whose event does not designate the host form
(`UniversalValidator.php:2722`). If the form is designated for **no** event, it returns an empty
array, the caller's inner loop body never executes, and no finding and no rule problem is
produced.

Longitudinal project, form `fb` mapped to no event, `@UVREQUIRED` on a blank field of `fb`, two
events per record:

```
violations                  = 0
rule problems               = 0
contexts counted as scanned = 2
status                      = complete   -> green tick
```

The rule did not run. The report says the project is clean and claims two rows were checked.

This is reachable by ordinary use: add a rule to an instrument before mapping it to events, or
unmap an instrument later. Every other unevaluable condition in this module produces an
`unconfigurable` entry — `ruleHostForms()` reports a field whose form is unknown, and
`collectUniqueCandidates()` reports six separate resolution failures. This path reports nothing.

**Fix:** when `hostContextsFor()` returns no context for a rule in a record where the event
exists, that is a rule that could not be evaluated. Emit it once per rule, not per record.

## Y11 — never-started instruments flood the report

500 records, one instrument never started, two `@UVREQUIRED` fields on it:

```
violations emitted = 1000  (2 per record)
```

No aggregation, and no collection-gap concept anywhere in the result shape.

Plan §1: *"Required rules on never-started instruments are reported as collection gaps,
aggregated by instrument, rather than emitted as millions of data-quality violations."* Plan §2
requires collection gaps to be a separate dimension that never becomes a violation and must
appear beside any clean statement.

At 100,000 records this one rule contributes 200,000 rows. The on-screen table caps at 1,000, so
the operator sees a wall of the same non-finding and the real data-quality problems are pushed
past the cap. The CSV carries all 200,000.

This is not a "later task" item: the shape of the result has no place to put a collection gap, so
adding one later is a breaking change to `scanProject()`'s contract and to both exporters.

## Y6 — the authorization gap, now executable

Round 1 argued B4 from the source. Driven:

```
scanScope() verdict   = ALLOWED     (it inspects only hasDesignRights + group_id)
rights presented      = forms fb:0, data_export_tool=2 (de-identified)
getData userid param  = ABSENT      (no per-user filtering)
fields requested      = record_id,open_field,restricted
value reaching report = 'SECRET'
```

A user with design rights, **No Access** to form `fb`, and **de-identified** export rights
downloads the raw contents of a field on `fb`. `ScanPageView::scanScope()` reads exactly two keys
out of the rights array and ignores the rest; `scanProject()` calls `REDCap::getData()` with
`project_id` and no `userid`, so REDCap applies no rights filtering of its own.

## Y4 — the Instance column cannot locate a repeating row

```php
'render' => function (array $f) {
    return ((int) $f['instance']) > 1 ? (string) $f['instance'] : '';
},
```

A violation on **instance 1 of a repeating form** and a violation on the **base row of a
non-repeating form** both render an empty Instance cell:

```
base row  (form fa, not repeating) Instance cell = ''
repeat instance 1 (form fb)        Instance cell = ''
```

The comment says *"1 on a form that does not repeat carries no information."* True. But 1 on a
form that **does** repeat is the whole location. The renderer has `ScanDimensions` in hand and
`$f['instrument']`, so it can tell the two apart and does not.

## Y7 — truncation splits a combining sequence

`reportValue()` cuts at 120 characters with `mb_substr`. A base character at position 120 with its
combining mark at 121 loses the mark:

```
120 chars -> untouched (correct)
121 chars -> truncated (correct)
combining acute at the cut: base kept, mark dropped = true
```

The reported value is then a *different* string from the stored one — `e` where the record holds
`é` — and the `… (truncated)` suffix reads as "there is more", not "this character is not what
is stored". Someone comparing the report against the record sees a mismatch the report caused.

## Y8, Y9, Y10 — confirmed, lower rank

- **Y8**: an unreadable `catalog.json` collapses every explanation to `Rule N reported "reason"`.
  The **Wording from** column does say `fallback`, so this one degrades visibly. Recorded as
  working-as-designed; noted only because the catalog is cached once per request, so a single bad
  read poisons the whole report.
- **Y9**: `ScanColumns::row()` calls `MessageCatalog::explain()` twice per finding — once for
  `problem`, once for `wording`. Measured 0.005 ms/row over 20,000 findings, projecting to ~5 s of
  pure rendering at 1,000,000. Not urgent; free to fix.
- **Y10**: 20,000 records × 300-byte Notes under `@UVUNIQUE` grew peak memory by **16.0 MiB**
  against 5.7 MiB of source values — the value is held both hex-encoded inside the group key and
  raw alongside it (`:2904`, `:2910`), regardless of the value policy. Plan, `uv_unique_candidate`:
  *"Do not store whole Notes values."*

---

## Came back clean

Recorded so the next reviewer does not re-spend the time.

**Y2 — `@UVREQUIRED` on a checkbox is handled correctly.** I expected a silent skip at
`UniversalValidator.php:501` (`if (is_array($value)) continue;`). The configuration gate refuses
it first:

```
configError = @UVREQUIRED on "boxes": @UVREQUIRED does not support "checkbox" fields
              — it requires a scalar input the person can fill in.
```

and the scan surfaces it as a rule problem. The `continue` is unreachable for a configured rule.
Hypothesis wrong; the module is right.

**Y5 — the scan and the save-audit agree.** Same fixture, same record, both channels:

```
scan  for record 1 = ["required/required-blank","unique/duplicate-value"]
audit for record 1 = ["required/required-blank","unique/duplicate-value"]
```

The README's *"the two can never disagree"* holds for evaluation. The one divergence found
(round 1, X3) comes from the scan's **record pool** being narrowed by a DAG filter, not from the
dispatch. That refines X3 rather than weakening it: the disagreement is a scope defect, and the
fix belongs in scope handling, not in `ruleFindings`.

---

## Corrections to my earlier reports

I have been wrong twice. Both are recorded in place.

1. **Round 1, "Checked and clean": the DAG filter fails closed.** Wrong — Y1. It fails open for
   any node REDCap does not return as an array, and the resulting identifier reaches a DAG user's
   downloaded file (Y12). This was the single worst error in the two reports, because it retired a
   real leak as verified-safe.
2. **First review, M4: an authored message makes two failures render identically.** Wrong — round 1
   W10. The catalog distinguishes them, and an authored message cannot reach a single or pooled
   rule at all (X4). Already annotated in that file.

Both errors ran the same way: I read a conjunction or a resolution chain and did not execute it.
Everything asserted in these three reports that was *not* executed should be treated as an
untested claim.

---

## Standing register

Nothing below is fixed. Ranked by what it does to someone who trusts the output.

### Leaks and false assurances

| # | Defect | Probe |
|---|---|---|
| Y1 / Y12 | DAG filter fails open; out-of-group id reaches the file, under a header claiming one group | Y1, Y12 |
| X2 | `log-values=none` publishes raw record ids through `incomplete` notes | W3 |
| B4 / Y6 | Design rights alone yield raw values from forms the user cannot read | Y6 |
| X1 | A scan that examined 0 records headlines "Scanned 400 record(s)" | W28 |
| X3 | Project-scope `@UVUNIQUE` silently wrong under a DAG scope | W5 |
| Y3 | A rule on an unmapped instrument never runs; project reported clean | Y3 |
| B3 / W23 | Value policy defaults to raw and fails open | W23 |
| X6 | Export certifies a project whose every rule is broken | W19 |
| B2 / W15 | `complete` with no fence; `ScanCapabilities::policy()` unwired | W15 |
| B6 / W4 | Empty in-scope manifest certifies clean | W4 |

### Broken or unusable output

| # | Defect | Probe |
|---|---|---|
| X4 | Rule label and Message discarded for single/pooled rules | W26, W27 |
| X5 | No HMAC key empties the Record column | W3b |
| Y11 | Never-started instruments emit one violation per record | Y11 |
| Y4 | Instance column cannot distinguish repeat instance 1 from a base row | Y4 |
| W7 / W8 | Degraded event names delete the Event column; `degraded[]` unreachable | W7, W8 |
| W20 / W21 | No header row when clean; row widths 11,11,4,4 | W20, W21 |
| W9 | CSV header is labels, not keys | W9 |
| W25 | Hidden-choice labelled "Wrong value" | W25 |
| Y7 | Truncation drops a combining mark | Y7 |

### Robustness and cost

| # | Defect | Probe |
|---|---|---|
| W1 / W2 | Formula defusing bypassed by leading whitespace; NUL reaches the CSV | W1, W2 |
| W6 | A sink failure escapes `scanProject()` with no status | W6 |
| W22 | Export disables the 1.6.4 time budget | W22 |
| W17 | Findings join to rules by array ordinal across two reads | W17 |
| W12–W14 | Capability probes fail open | W12, W13, W14 |
| W16 / W18 | Record list materialised unguarded; `array_chunk` doubles it | W16, W18 |
| Y10 | Unique candidates retain raw values project-wide | Y10 |
| Y9 | Explanation resolved twice per row | Y9 |
| W24 | Two live export formats with different guarantees | W24 |
| W11 | Withheld value indistinguishable from a real blank | W11 |
| B5 / L1 | Page still promises it never shows values; no 1.8.0 CHANGELOG entry | read |

## Smallest set that stops a leak

Four edits, none structural, all testable today:

1. `UniversalValidator.php:2150` — hoist `is_array($node)` out of the exclusion test and record a
   note when a node cannot be read. Closes Y1 and Y12.
2. `UniversalValidator.php:2221`, `:2512` — route `$rec` through `reportRecordId()` in the
   `incomplete` notes. Closes X2, and closes Y12's second half independently of fix 1.
3. `UniversalValidator.php:2153` — report records **examined**, not records **listed**. Closes X1.
4. `config.json:75` + `scanValueMode()` — default `none`, and return `none` when the setting cannot
   be read. Closes B3/W23, and shrinks Y6 from a data leak to a metadata leak until rights
   checking lands.

Then the two that need a decision rather than an edit: what a scan may claim without a fence
(B2/W15, B6/W4, X6), and what a DAG-scoped run may say about project-scope uniqueness (X3).

## Both harnesses

`tools/` is `export-ignore`d, so neither ships. Delete with:

```bash
rm tools/temporal_scan_wargame.php tools/temporal_scan_wargame2.php
```

Every probe in both files is a test that should exist. The ones marked clean (Y2, Y5) are worth
keeping too — they pin behaviour that is currently correct by construction and has no test
holding it there.
