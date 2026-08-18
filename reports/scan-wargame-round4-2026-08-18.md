# Validation scan: round 4 — an independent attack

Fifth pass, deliberately independent of rounds 1–3. Nothing here re-tests an earlier finding.
Every probe targets a surface the previous rounds never touched: PHP array-key coercion on record
ids, the request-scoped dictionary cache, malformed REDCap result shapes, the HTML escaping
surface, rule identity when the rule list has gaps, and the boundary the new value ceiling
actually draws.

**Code under test:** `main` at `c8765b0` (1.8.6).
**Suite:** 922 checks, 0 failures across eight files.

**Harnesses:** `tools/temporal_scan_wargame4.php` (10 probes),
`tools/temporal_scan_wargame4b.php` (3 probes). Both in `tools/`, which is `export-ignore`d.

```bash
php tools/temporal_scan_wargame4.php  .
php tools/temporal_scan_wargame4b.php .
```

**13 probes, 8 confirmed, 5 clean.** Four of the eight are authorization; one is a robustness
defect with a nasty failure shape; three are documented-behaviour gaps.

---

## Authorization: the ceiling caps values, and nothing else

1.8.x added `ScanPageView::valueCeilingFor()`, which derives a disclosure cap from
`data_export_tool`. It is a real improvement and it closes the worst of the old B4. Three probes
map what it still does not reach.

### A1 — form-level rights are never read

`valueCeilingFor()` (`php/ScanPageView.php:195`) inspects one key. `forms` and
`data_export_instruments` are not consulted anywhere in the scan path.

A user with **design rights**, **No Access to instrument `fb`**, and **full data-set export
rights**, on a project that has opted into `scan-value-storage = raw`:

```
rights  : forms fa:1,fb:0 | data_export_tool=1
setting : scan-value-storage = raw
ceiling : raw
value from the form the user cannot open = 'MRN-99881'
```

REDCap's own answer to "may this user see `fb`" is No. The scan's answer is the field's contents.
The docblock immediately above `valueCeilingFor()` describes this exact scenario — *"design
rights, No Access on an instrument and De-Identified export rights would otherwise download every
field's raw value"* — and the implementation fixes only the export-rights half of the sentence it
wrote.

**My round-4 first pass reported this clean.** The value came back `NULL` because the *project*
default (`locations`) withheld it, not because rights did. The finding only appears once the
project opts into raw. A probe that stops at the first `NULL` certifies a control that is not
there.

### A2 — locations from an unreadable form leak regardless of the ceiling

Values are the only thing the ceiling governs. With **No Access to `fb`** and **no export rights
at all**:

```
ceiling : locations  (values correctly withheld)
record     = 1
instrument = Secret Form
field      = secret
label      = Participant MRN
```

The user learns that the record exists, that it has a problem on an instrument they cannot open,
the instrument's display name, the field's name, and the field's label — which in REDCap is
routinely the question text. The count of findings on that form is a channel of its own.

### A3 — the export is served to a user with no export rights

`data_export_tool = 0` is REDCap for "No Access to the data export tool."

```
scope       : ALLOWED, ceiling locations
file served : true (Content-Disposition: attachment)
```

The ceiling downgrades the contents; nothing gates the download. A user who cannot use REDCap's
exporter can still download a project-wide findings file from this module.

**Shape of the fix for all three:** resolve the entitlement form set the way the rebuild plan
already specifies (§1, §5) — every rule host plus every form owning a referenced field — and deny
the whole report when any of it is unreadable. Partial filtering leaks through counts and labels,
which is why the plan calls for whole-report denial rather than per-row suppression.

---

## A4 — one failed dictionary read is cached for the whole request

`UniversalValidator.php:1829`:

```php
private function dataDictionary($pid = null)
{
    if ($this->dd !== false) return $this->dd;   // short-circuits BEFORE $pid is read
    $this->dd = null;
    ...
}
```

The cache is a single slot, and the failure result is cached as eagerly as the success. Two scans
in one request, with a transient failure on the first:

```
scan 1 (dictionary throwing) status = failed
scan 2 (dictionary healthy)  status = failed
getDataDictionary() calls in total  = 1   (no retry, ever)
```

The second scan never asks again. One hiccup disables every annotation rule, every field-name
check and every host resolution for the life of the request, and a retry cannot recover it.

Two things make this worse than a plain caching bug:

- The docblock states the method *"prefers an explicitly passed `$pid`… without this, the
  dictionary silently fails to load [in import/API/cron contexts] and every `@UVALIDATE` rule is
  dropped from the server-side audit."* After the first call the `$pid` is never looked at again.
  If the first call in a request happens without a project context, every later call — including
  ones passing the correct pid — returns the poisoned `null`.
- The scan reports this honestly (`status = failed`, with a reason). `redcap_save_record` shares
  the same helper, and a dropped rule set there is the silent-failure class the module exists to
  prevent.

**Fix:** key the cache by pid, and cache only successful reads.

---

## A5 — the read set is unbounded in field count

Every rule field, every `when`/`assert` reference and every composite unique key goes into one
`REDCap::getData()` call per chunk. A project with 1,500 ruled fields:

```
fields sent to getData in a single call = 1500
```

No cap, no batching, no note. Each chunk builds one very wide export, and the row width multiplies
against the chunk size in memory. The 1.6.4 halt guard measures elapsed time and memory between
chunks, so it will eventually notice — after the allocation that caused the problem.

## A6 — the value ceiling defaults open when `opts` is omitted

```php
isset($opts['valueCeiling']) ? $opts['valueCeiling'] : 'raw'    // :2591
```

```
scanProject($pid) with no opts -> 'RAW-SECRET'
scanProject(..., 'locations')  -> NULL
```

Both pages pass the ceiling, so this is latent rather than live. It is still a fail-open default
in the disclosure path, sitting beside a `valueRank()` whose docblock states *"Unknown ranks
LOWEST, so anything unrecognised is treated as the least disclosing option."* The two defaults
point in opposite directions. `locations` is the safe literal; a caller that wants raw can ask.

This is the third instance of the same pattern (`reportValue()`'s `: 'raw'`, flagged in round 3,
is still present). Worth fixing as one class rather than three sites.

## A7 — DAG-scoped uniqueness puts every ungrouped record in one bucket

`scope=dag` appends `(string) $recDag` to the key. For a record in no DAG that is `''`, so all
ungrouped records share a bucket and are compared against each other:

```
two records, neither in any DAG, same value, scope=dag
duplicate findings = 2
```

Defensible — it is the only consistent reading — but neither `README.md` nor `config.json`
says so, and the opposite reading ("no DAG means no group, so nothing to compare") is at least as
natural. Documentation, not code.

---

## Clean — five probes found nothing

Recorded so the next reviewer does not re-spend the time.

**Record ids that PHP coerces as array keys.** `'7'`, `'007'`, `'0'`, `'1e3'`, `' 8'`, `'00'`,
`'ID-1'` — all seven survive the manifest, the chunk read and the report intact.
`stats.records = 7 of 7`, nothing missing, no collisions. `$ids[] = $rec` plus `(string) $rec` at
every boundary is the right handling and it holds.

**Malformed record nodes.** Six hostile shapes — a scalar event row, `repeat_instances` as a
string, a repeat instance that is not an array, a nested value array, a null event row, an empty
node — all returned a reported result. No exception, no silent success.

**The escaping surface.** `"><img src=x onerror=alert(1)>` placed simultaneously in a field
label, a rule note and a rule message renders escaped; no raw tag anywhere in the page. Every
generated cell goes through `ScanPageView::h()`.

**Rule ordinals with a gap.** A config-error rule between two live rules does not shift the
labels of either. The 1.8.5 change that carries the scan's own rule list holds under a
non-contiguous live set.

**`MessageCatalog`'s new memo.** The one-slot template cache keys on audience, type, full reason,
rule ordinal and the authored message — everything the template branches on, including the two
values the last-resort sentence quotes verbatim. `fill()` still runs per finding, and an authored
message is returned without substitution so a designer's `{braces}` stay their own text. I tried
to make it serve one finding's sentence to another and could not.

---

## Register

| # | Finding | Severity | Fix |
|---|---|---|---|
| A1 | Form-level rights unread; No-Access form yields raw values | High | entitlement form set, deny whole report |
| A3 | Export served with `data_export_tool = 0` | High | gate the download, not just the contents |
| A2 | Locations from an unreadable form always disclosed | Medium-High | same fix as A1 |
| A4 | Failed dictionary read cached for the request; no retry | Medium | key by pid, cache successes only |
| A5 | Read set unbounded in field count | Medium | cap and batch, or note it |
| A6 | Value ceiling defaults to `raw` when `opts` omitted | Low (latent) | default `locations` |
| A7 | No-DAG records share one uniqueness bucket | Low | document |

A1, A2 and A3 are one decision, not three patches: what entitlement does this report require, and
what happens when part of it is missing. The plan already answers it — whole-report denial on any
unreadable dependency — and that answer is the reason it rejects per-row filtering.

A4 is the one to fix today regardless: it is eight characters of cache key, and its failure mode
is a scan that cannot recover within a request.

## Standing open items from earlier rounds

Not re-tested here; carried forward from `scan-wargame-round3-2026-08-18.md` for one register:

DAG filter fails open on a non-array node (round 2 Y1/Y12) · rule on an unmapped instrument never
runs (Y3) · never-started instruments emit one violation per record (Y11) · `?csv=1` scans twice
(round 3 N1) · control bytes stripped in the file but not the page (round 3 N2) · required-blank
labelled "withheld by policy" (round 3 R1-b) · two events indistinguishable when event metadata
fails (round 3 R1-a).
