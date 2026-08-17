# Validation scan: implementation review against the rebuild plan

Reviewed: commits `8bca32d..c1635d7` (the plan, then Tasks 1–4 and the work committed as
"1.8.0") against `reports/scan-rebuild-plan-2026-08-17.md`.

Files read in full: `pages/scan.php`, `pages/export.php`, `php/ScanPageView.php`,
`php/ScanColumns.php`, `php/ScanDimensions.php`, `php/MessageCatalog.php`,
`php/FindingSink.php`, `php/ScanCapabilities.php`, `php/messages/catalog.json`,
`UniversalValidator.php` (scan path, lines 2033–2913), `tests/scan_page_php.php`,
`tests/scan_capabilities_php.php`, `tests/hosting_php.php` (SINK and VALUE sections),
`config.json`, `.github/workflows/parity.yml`, `.gitattributes`, `CHANGELOG.md`.

## Verdict

The individual pieces are careful work. The seam extraction (`scanPlan` / `scanRecord` /
`FindingSink`) is clean, the differential test that runs every scenario through both sinks is
the right shape, and `ScanCapabilities` is an honest probe layer with an exhaustive
64-subset monotonicity test.

The problem is direction. The plan's first safety release (Task 1) says to **disable** the
GET-triggered synchronous scan and the export-by-rerun control, and to keep them disabled
until the durable path exists. What shipped instead keeps the GET-triggered scan, adds a
**second** production page that reruns the same legacy array scan, and turns on raw value
disclosure by default. Every one of those moves widens the surface the plan wanted narrowed,
and each is now covered by tests that lock the widened behaviour in place.

Two properties the plan calls non-negotiable are absent from the shipped path and cannot be
added later without changing what the page already tells operators:

- a scan still reports `complete` and renders the green tick with no fence of any kind;
- a design-rights user can now export every field's raw value for every record, with no
  check on form rights or export rights.

Counts below: 7 blocking, 15 medium, 9 low.

---

## Blocking

### B1. The release inverts Task 1

Plan §1: *"Production UI and exports must not call the legacy array scan. The current
GET-triggered run/export path is disabled in the first safety release and stays disabled
until the durable path is complete."* Task 1 repeats it as a checklist item: *"Write page
tests for … refusal of every legacy `run=1`/`csv=1` GET or POST execution attempt"* and
*"Disable the production synchronous scan and export-by-rerun controls."*

What is there:

- `pages/scan.php:46` still runs `$module->scanProject($pid, $dagFilter)` on `?run=1`.
- `pages/scan.php:57` still serves the legacy `?csv=1` route, rerunning the scan.
- `pages/export.php:69` is a **new** page that reruns the whole scan on every GET and is now
  the target of the Download CSV button (`pages/scan.php:127`).
- `tests/scan_page_php.php:366` asserts the opposite of the plan: *"a resolvable DAG still
  runs the scan"*.

No temporary notice about the durable scan being unavailable exists anywhere. The result is
three live entry points to the legacy scan where the plan asked for zero, and a test suite
that will now fail if anyone implements the plan.

If the decision was to defer Task 1 deliberately, that is a defensible call, but it is
recorded nowhere — not in the CHANGELOG, not in the plan file, not in a commit message.

### B2. `complete` is still claimed with no fence

`UniversalValidator.php:2272`:

```php
$result['status'] = $result['incomplete'] ? 'incomplete' : 'complete';
```

There is no opening fence, no closing fence, no re-read of source versions, and no
reconciliation of record identity. A record edited, added or deleted while the sweep was
running is invisible to the result. `pages/scan.php:240` then renders
**✓ No violations found** in green.

Plan §1: *"`complete` means every in-scope record was stably validated at or beyond a
recorded source-change fence … If a reliable source fence cannot be proven, the strongest
terminal coverage state is `manifest-complete`; it must never render as complete or clean."*

`ScanCapabilities::policy()` computes exactly this cap — `maxCompletion` is
`manifest-complete` whenever `sourceFence` is unavailable — and **nothing in production calls
it**. Confirmed by grep: `recordEnumeration`, `sourceFence`, `schemaPrivilege`,
`repeatMetadata` and `policy` have call sites only in `tests/scan_capabilities_php.php`. Only
`eventNames()` and `dagNames()` are wired, and only into `ScanDimensions`.

So the module now contains a correct, tested implementation of the safety property and does
not consult it. That is worse than not having written it, because the test suite reports the
property as covered.

**Fix:** have `scanPlan()` call `ScanCapabilities::policy()`, carry `maxCompletion` on the
result, and make `pages/scan.php`'s `$clean` predicate and `pages/export.php`'s `$complete`
require `maxCompletion === 'complete-through-fence'`. Until a fence exists that is never true,
which is the honest state.

### B3. Value disclosure defaults on, and fails open

Plan §1: *"Field values are not stored by default. `scan-value-storage=locations` is the
default; `identifier-redacted` and `raw` require explicit project opt-in."* Plan §9 repeats
it in the settings table. Plan §9 closes: *"Unknown or malformed settings fail toward less
disclosure."*

Implementation (`config.json:75`, `UniversalValidator.php:2351`):

| | Plan | Shipped |
|---|---|---|
| Default | `locations` | `raw` |
| Setting unread / throws | less disclosure | `raw` |
| Mode names | `locations` / `identifier-redacted` / `raw` | `none` / `identifiers` / `raw` |

```php
private function scanValueMode($pid)
{
    try {
        $m = $this->getProjectSetting('scan-value-storage', $pid);
        if ($m === 'identifiers' || $m === 'none' || $m === 'raw') return $m;
    } catch (\Throwable $e) {
    }
    return 'raw';
}
```

Because External Modules dropdowns store nothing until the settings dialog is saved,
`getProjectSetting` returns `null` on every project that has not been reconfigured. Every
existing installation therefore switches from locations-only to full raw values on upgrade,
silently, and a settings-backend failure does the same.

The code comment defends this by analogy to `logMode()`. The analogy does not hold:
`log-values` writes to the module log, which is already treated as identifying data;
`scan-value-storage` governs a file that gets downloaded and emailed. The plan made the
opposite call for that reason.

The fail-closed work inside `mustRedact()` is correct and well tested. It is guarding the
wrong default.

### B4. No form-rights or export-rights check on a report that now carries values

Plan §1: *"A user must have design rights, readable access to the complete entitlement form
set, full identified-data export rights, and an authorized DAG scope."* Plan §5 and the
permission matrix in §2 repeat it for start, read and export.

`ScanPageView::scanScope()` checks exactly two things: `hasDesignRights()` and DAG
confinement. `scanProject()` then calls `\REDCap::getData()` with `project_id` and no
`userid`, which bypasses per-user rights entirely.

In REDCap, design rights are independent of form-level rights and of export rights. A data
manager with design rights, **No Access** on an instrument, and **De-Identified** export
rights can now download raw values for every field on every form of every record, by
visiting one URL. Before this release that user got locations only, which is why the gap did
not matter; adding the Value column made it matter.

`config.json:76` acknowledges the audience is wider than record-level access control and
tells the administrator to pick locations-only if that is a problem. Warning text is not a
control, and the default is the disclosing one (B3).

**Minimum fix before this reaches a server:** gate the Value column on export rights (REDCap
`data_export_tool` = 1 for raw, and Identifier redaction forced otherwise), and gate the
whole report on nonzero read access to every host form. Denying the whole report on partial
rights is the plan's position and avoids inference through counts.

### B5. The scan page still promises it never shows values

`pages/scan.php:13-15` (file docblock):

> Stored VALUES are deliberately not shown — the report names where the problem is (record /
> event / instance / field / reason); the value itself stays behind REDCap's own access
> control on the record pages.

`pages/scan.php:133-135`, rendered to the user, above a table with a Value column:

> The report shows *where* each problem is — never the stored value itself.

`UniversalValidator.php:2070-2072` (`scanProject` docblock): *"Stored VALUES are deliberately
not returned."*

The README was corrected in `c1635d7`; these three were not. The page text is the one an
operator actually reads, and it now states a privacy guarantee the page breaks two
paragraphs later.

### B6. Zero records in scope certifies the project clean

`UniversalValidator.php:2155`:

```php
if (!$ids) { $result['status'] = 'complete'; return $result; }
```

The DAG filter at `:2150` excludes any record whose `dagOfRecordNode()` does not equal the
filter, including every record when `exportDataAccessGroups` is not honoured, when the DAG
name and the exported group label disagree, or when the group genuinely has no records. All
three produce: `Scanned 0 record(s), 0 row(s) … 0 violation(s)` in green, plus
**✓ No violations found**.

This is S-03 — the bug 1.6.2 exists to fix — reached by a different route. 1.6.2 refused when
the DAG *name* could not be resolved; it did not refuse when the name resolves and matches
nothing. Nothing in the result distinguishes "your group has no records" from "the DAG filter
matched nothing because the export shape changed".

**Fix:** an empty in-scope manifest is not a clean project. Report it as its own state and
withhold the tick, the same way an unresolvable DAG does.

### B7. The export runs a different scan from the one on screen

`pages/export.php:38`:

```php
@set_time_limit(0);
```

`scanProject()` derives its deadline from `(int) ini_get('max_execution_time')`
(`UniversalValidator.php:2173-2174`). After `set_time_limit(0)` that is `0`, so `$deadline`
is `null` and the halt guard added in 1.6.4 never fires on the export path.

The consequences compound:

1. The screen scan can stop for time and report `incomplete`; the export of "the same" scan
   runs to completion and reports `complete`. Two coverage claims, two different answers, no
   way for the reader to know which run they are holding.
2. The export is a **second, independent scan**. Data changed between the two runs produces a
   CSV that does not match the table it was downloaded from. Plan §8: *"CSV is one
   authenticated streaming response from stored findings"* and Task 7: *"Remove
   rerun-on-export."*
3. With no time limit and no concurrency control (plan §1: one resource-intensive scan per
   project; §9: `scan-system-max-concurrent-projects` = 2), any page a logged-in user visits
   can issue cross-site GETs to `pages/export.php` and start unbounded full-project scans.
   The request is read-only so it is not CSRF in the state-changing sense, but the cost is
   real and unbounded.

---

## Medium

### M1. Spreadsheet formula defusing only inspects byte 0

`ScanPageView::csv()`:

```php
if ($s !== '' && strpos('=+-@', $s[0]) !== false) $s = "'" . $s;
```

Plan §8 names the gap precisely: *"Defuse spreadsheet formulas after leading whitespace,
tabs, carriage returns, BOMs, and other control characters."* Excel and Sheets both strip
leading whitespace before parsing, so `" =cmd|'/c calc'"`, `"\t=…"`, `"\r=…"` and a
BOM-prefixed payload all reach the formula parser. `tests/scan_page_php.php:494` tests the
four bare leading characters only.

### M2. The export CSV is not rectangular, and has no header row when clean

`pages/export.php` writes, in order: comment lines, a header row of N columns (only if at
least one finding was emitted), N-column finding rows, then 4-column `rule-problem`,
`not-scanned` and `INCOMPLETE` rows.

- A clean scan produces a file with no header row at all — just comments and one quoted
  sentence (`:108-110`). Any consumer that parses by header breaks on exactly the files that
  should be easiest to handle.
- Mixed arity in one file is not valid rectangular CSV. Plan §8: *"preserve a valid
  rectangular CSV structure."*

Write the header unconditionally, and pad the trailer rows to the column count.

### M3. The CSV header emits labels, not stable keys

`ScanColumns`' own descriptor documentation says:

> `key`  stable machine name, **used as the CSV header**

`ScanColumns::headers()` returns `$c['label']`. So the header row is
`"Issue","Record","Data Access Group",…`, and any wording change to a label silently breaks
every downstream consumer. The docblock and the code disagree in the one file whose stated
purpose is to stop the screen and the file drifting apart.

### M4. The reason code and rule type were dropped from the report

The pre-1.8.0 table had `Kind` and `Reason` columns carrying `$v['type']` and `$v['reason']`
verbatim. Neither survives:

- `type` is folded into `Issue` through a two-entry map, so `single`, `pooled`, `constraint`
  and `choices` all render as **"Wrong value"**.
- `reason` reaches the reader only through `MessageCatalog::explain()`, and tier 1 of that
  chain is the rule author's own message, which wins for every finding of that rule
  regardless of reason.

A single-value rule with both a pattern and a check-character algorithm, carrying an authored
message, now produces identical rows for a format failure and a mistyped check digit. The
distinction exists in the data and is discarded on the way out. Plan §4 requires findings to
carry a reason code; plan §8 requires pooled character problems to be encoded as a five-bit
mask rather than free text. Add a `reason` column, or at minimum keep it in the CSV.

### M5. `ScanDimensions::degraded[]` is collected and never read

The class docblock states the contract:

> A source that cannot be read NEVER guesses and never blanks. … the failure is recorded in
> `degraded[]` so the report can say a column is unreliable. **Degradation nobody can see is
> the failure this module exists to prevent.**

`degraded` is populated in four places and read in none. Neither `pages/scan.php` nor
`pages/export.php` nor `ScanColumns` touches it. `docs/TESTING.md` adds a manual check —
*"confirm the report … says a column is degraded"* — that no code path can satisfy.

### M6. Unreadable event names silently delete the Event column

```php
$d->longitudinal = count($d->events) > 1;
```

`longitudinal` is derived from whether names were **read**, not from the project's shape. If
`getEventNames` is unavailable, throws, or returns a non-array, `$d->events` is empty,
`longitudinal` is false, and `ScanColumns` drops the Event column on a longitudinal project.
Two findings on the same field in different events then render as identical rows on screen
and in the CSV, with no note that anything was lost.

The degradation direction is inverted: `ScanDimensions::event()` already falls back to the
raw id, which is the correct behaviour. Base column visibility on the project being
longitudinal (arm/event count), not on the label read succeeding.

### M7. A failed DAG read is indistinguishable from a project with no DAGs

```php
$g = \REDCap::getGroupNames(true);
if (is_array($g) && $g) $d->hasDags = true;
```

A non-array return sets nothing and records nothing in `degraded` — only a thrown exception
is caught. The DAG column then vanishes with no signal, which is the same failure shape as
M6 and contradicts the class's stated rule. Compare the `forms` branch immediately above,
which does record degradation on an empty result.

### M8. Findings join to rules by array ordinal, across two independent reads

Findings carry `'rule' => $i + 1`, where `$i` indexes `getRules($pid)`. `ScanDimensions`
resolves that ordinal against a **second, separate** `getRules($pid)` call —
`getRules()` is not memoized, unlike `dataDictionary()`. In `pages/export.php` that second
call happens *inside* the scan loop, on the first finding.

Any change to the rule list between the two reads shifts every ordinal, and every label,
message and assertion in the report attaches to the wrong rule, with nothing to detect it.
Plan §6 requires persistent UUIDs for settings rules and a revision-hashed identity for
annotation rules for exactly this reason; plan §4 requires findings to store a *stable rule
source ID and revision*.

Short of implementing that: memoize `getRules()` per request, and have `scanProject()` return
the rule snapshot it actually used instead of letting the report re-derive one.

### M9. `reportValue()` collapses three of the four cases its own docblock separates

The docblock:

> Four things can stop a value reaching the report, and they are NOT the same and must not
> look the same to a reader

The code returns `null` for *policy says never*, *the finding has no value*, and *the value
is the empty string*. Only the identifier case and the invalid-bytes case get markers. In
`none` mode every row's Value cell is empty, which is exactly what a genuinely blank
required-field violation also renders. `docs/TESTING.md` asserts the opposite is true: *"the
Value column is still present so the omission is visible rather than silent."* It is present
and empty, which is not visible.

Emit `[withheld by policy]` in `none` mode.

### M10. `schemaPrivilege` substring-matches `CREATE`

```php
if (strpos($all, 'ALL PRIVILEGES') !== false || strpos($all, 'CREATE') !== false) {
    return self::yes('SHOW GRANTS');
}
```

`CREATE TEMPORARY TABLES`, `CREATE VIEW` and `CREATE ROUTINE` all match and none of them
permits `CREATE TABLE`. A database or user name containing the string also matches, since the
whole grant line including the `ON`/`TO` clauses is searched. The probe is also not scoped to
the schema REDCap lives in. No test covers any of this — the only fixture is a grant line
that genuinely contains `CREATE`.

### M11. `sourceFence` claims a fence on the existence of a table name

`sourceFence()` returns available as soon as `redcap_projects.log_event_table` resolves to a
name matching `/^redcap_log_event[0-9]*\z/`. It checks neither log retention nor the event
taxonomy.

Plan §6: *"If log retention cannot cover the opening-to-target interval, or the event
taxonomy cannot safely identify every record-changing operation, terminate `manifest-complete`
or `partial`, never `complete-through-fence`."* Plan Task 4 lists log-retention validation and
DAG-change visibility as things to verify.

As written, `policy()` would grant `complete-through-fence` and enable incremental mode on
almost every installation. Because `policy()` is unused (B2) this is latent, but it is the
wrong default for the moment it is wired up, and the probe's docblock claims the module
"never guesses".

### M12. `recordEnumeration`'s fallback never proves the walk works

The fallback returns available when `query()` is callable and a record-id field is known. It
issues no probe query. Plan Task 4 calls bounded record enumeration *"the hard implementation
gate"*. A gate that passes on the availability of two prerequisites rather than on the
operation succeeding is not a gate.

The preferred branch has a smaller version of the same problem: `redcap_record_list` existing
does not mean it is populated for this project.

### M13. Whole-project accumulators remain, and the README says otherwise

Three structures in `scanProject()` still grow with the data:

| Structure | Growth |
|---|---|
| `$ids` (`:2148`) | one entry per record in the project |
| `$uniqueSeen` (`:2157`) | one entry per unique-rule candidate value, project-wide, held to the end |
| `$result['incomplete']` (`:2221`) | one string per record requested and not returned |

Plan §1: *"Record IDs, findings, errors, unique candidates, and exports must never be
accumulated for the whole project in PHP memory."*

This is expected — the durable path is Tasks 5–6 — but the README now says:

> since 1.7.0 findings can be handed out as they are produced rather than accumulated, so the
> download holds one row at a time however many the project produces

The row *buffer* holds one row. The scan behind it does not, and `pages/export.php` spools
the entire CSV to `php://temp` before sending a byte, so the export also needs disk equal to
the full report and delivers no output until the scan finishes. On any project large enough
for the claim to matter, the request dies at the reverse proxy first.

### M14. `pages/export.php` has no `exit`, and no guard around the sink callback

- The file ends at line 124 with no `exit`. `pages/scan.php`'s CSV path uses `exit` for a
  reason: anything the External Modules router emits after the page returns lands inside the
  CSV. It happens not to on the builds tested; that is not the same as being safe.
- The `CallbackFindingSink` closure calls `$module->scanDimensions($pid)`, which calls
  `getRules()` and `Branching::resolve()`, from inside `scanRecord()`. A throw there escapes
  `scanProject()` entirely — past both `try/catch` blocks, which only wrap `getData` — and
  produces a PHP fatal with nothing recorded about the project not being examined. That is
  the exact failure mode M-03 and 1.6.4 were written to eliminate.
- The comment says building the dimensions on the first finding is "a memory read". It is
  not: `getRules()` is uncached and rebuilds settings rules, annotation rules and the
  branching resolution.

### M15. Unique candidates hold the raw value and its hex form, for the whole project

`collectUniqueCandidates()` stores `'value' => $v` on every candidate, alongside a key that
already contains `bin2hex(trim($v))` — so roughly three times the value bytes per candidate,
retained until after the last record, **including in `none` mode** where the value can never
be shown. The comment says "this costs nothing"; on a `@UVUNIQUE` rule over a Notes field it
costs the most of anything in the scan.

Plan §4, `uv_unique_candidate`: *"Do not store whole Notes values."* Apply `reportValue()` at
collection time, or store the offset and reread at emit.

---

## Low

### L1. There is no 1.8.0 CHANGELOG entry, and CI derives the version from it

Three commits are titled 1.8.0. `CHANGELOG.md` stops at 1.7.0. The `package` job reads:

```bash
VERSION=$(sed -n 's/^## \([0-9][0-9.]*\).*/\1/p' CHANGELOG.md | head -1)
```

so it builds `universal_validator_v1.7.0.zip`. External Modules takes the version from the
directory name, so the release would install as 1.7.0 while containing the 1.8.0 report,
export page and value policy. The 1.6.x entries are unusually good; this one is missing
entirely.

### L2. `ScanDimensions::build()` has an unused parameter and a wrong docblock

```php
/**
 * @param array      $dd    …
 * @param array      $rules …
 * @param array|null $dags  group id => name, or null when unavailable
 */
public static function build($pid, array $dd, array $rules)
```

`$pid` is documented nowhere and used nowhere in the body. `$dags` is documented and does not
exist.

### L3. The Event and DAG columns are never rendered by any test

`tests/scan_page_php.php`'s REDCap mock returns a **string** from both
`getEventNames()` and `getGroupNames()`:

```php
public static function getEventNames($u = false, $x = false, $evt = null) { return 'event_' . $evt . '_arm_1'; }
public static function getGroupNames($unique = false, $gid = null) { … return ''; }
```

`ScanDimensions` requires arrays, so `events` is always empty and `hasDags` always false. The
two assertions

- *"columns: a classic project shows no Event column"*
- *"columns: a project with no DAGs shows no DAG column"*

therefore pass because the labels were unreadable, not because of project shape — they would
pass unchanged on a longitudinal project with three DAGs. Neither column's render path, nor
`ScanDimensions::event()`, has ever been executed by a test. This is the same class of defect
1.6.3 was written to fix in the chunk mocks.

### L4. The legacy `?csv=1` export is still live and emits a different schema

Unlinked but reachable, still tested, and it produces: no BOM, no `_INCOMPLETE` filename
suffix, an unquoted header row (`section,record,event_id,…`) in a file whose format is
documented as unconditionally quoted, and the old eight-column shape with no value,
instrument or explanation. Two export formats with different truthfulness guarantees, one of
which a user can still reach by editing a URL or from a bookmark.

### L5. `MessageCatalog::explain()` runs twice per row

`ScanColumns`' `problem` and `wording` renderers each call it. At export scale that is two
`preg_replace_callback` passes per finding for one result. Resolve once in `row()` and pass
the outcome to both.

### L6. `catalog.json` describes machinery that does not exist

The comment block claims:

- *"`tests/messages_fixture.json` freezes the rendering so CI fails the moment the two
  disagree"* — that file does not exist and no test compares the two runtimes.
- *"A report renders staff wording, but shows the survey line too where it differs"* — no
  column does this; `explain()` is only ever called with `'staff'`.

The README's version of the first claim is stronger and also false:

> The wording comes from `php/messages/catalog.json`, which the browser and the server both
> read, so the sentence a respondent saw and the sentence in the report cannot drift apart.

`js/engine.js` never loads the catalog — grep for `catalog` across `js/engine.js` and
`UniversalValidator.php` returns nothing — and carries its own literals, which **already**
differ from the catalog entries:

| | `js/engine.js` | `catalog.json` |
|---|---|---|
| required | `This field must not be left blank` | `This field must not be left blank.` / survey: `This field is required.` |
| unique | `This value is already recorded` | `This value is already recorded on another record.` |

Plan §8 asks for the catalog to be *"consumed by PHP and injected selectively into
JavaScript"*, and Task 7 for parity fixtures. Neither exists. Until they do, the README claim
should be removed rather than left standing as a guarantee.

### L7. `SHOW TABLES LIKE ?` treats `_` as a wildcard

`tableExists()` passes `redcap_record_list` as a `LIKE` pattern, where `_` matches any single
character. Harmless for this literal, but the probe is documented as a whitelisted, careful
one. Use `SHOW TABLES LIKE ?` with the underscores escaped, or query
`information_schema.tables` with an equality test.

### L8. `tools/*.php` are not linted

`tools/measure_scan.php` and `tools/dryrun_measure.php` are absent from the CI lint list. They
are `export-ignore`d so they never ship, but they are installed by hand on a live server by
the instructions in their own header, which is the worst moment to find a parse error.

### L9. The Value column is always visible, contradicting the descriptor rule

`ScanColumns`' docblock: *"a column absent on this project's shape is ABSENT, not
present-and-empty."* The Value column is `function () { return true; }` even when
`scan-value-storage` is `none`. The reasoning in `docs/TESTING.md` (omission should be
visible) is sound, but it is the opposite rule, applied to one column, with no note in the
file that states the rule. Resolve it either way and say which.

---

## What holds up

Worth recording, so a rewrite does not discard it:

- **The sink extraction.** `scanPlan` / `scanRecord` / `FindingSink` is the correct seam, and
  the differential in `tests/hosting_php.php` — four scenarios through `ArrayFindingSink`,
  `CallbackFindingSink` and `CountingFindingSink`, every result field compared — is the right
  way to prove an extraction changed nothing. `stats.violations` surviving on the streaming
  path closes the "no violations inferred from an array nobody filled" hole properly.
- **`ScanCapabilities::policy()`'s monotonicity test.** Asserting over all 64 subsets that
  removing a capability never raises what a run may claim, rather than testing three examples,
  is the strongest thing in this diff. It deserves a caller (B2).
- **`mustRedact()`.** Inverting `isIdentifier()`'s posture, with the reasoning written down
  and the null-dictionary case tested, is exactly right. It is guarding the wrong default
  (B3), not implemented wrongly.
- **`logEventTable()`'s `\z` anchor**, with the comment explaining why `$` is unsafe and why
  the value is deliberately not trimmed. That is the level of care this table name needs.
- **The three-way incomplete marking in `pages/export.php`** — banner, terminal data row,
  filename suffix — each surviving a different way of mangling the file. Keep it.
- **`ScanColumns` having no `switch ($type)`.** The property is real and worth protecting; the
  problems above are with what the descriptors contain, not with the shape.

## Suggested order

1. B5 (page text) and L1 (CHANGELOG) — minutes, and B5 is a live false statement.
2. B3 + B4 — flip the default to `none`, fail closed on an unread setting, and gate the Value
   column on export rights. Until B4 lands, `none` is the only defensible default.
3. B2 + B6 — wire `ScanCapabilities::policy()` into the status, and stop certifying an empty
   scope. Both are small; both remove a false clean bill of health.
4. B7 + M14 — drop `set_time_limit(0)`, add `exit`, wrap the sink callback.
5. B1 — decide explicitly whether Task 1 is deferred, and write the decision into the plan
   file. The current state is the plan's position reversed with no record of the reversal.
6. M1–M4 — the file-format defects, before anyone builds a consumer against the current shape.
7. M5–M8 — the degradation and identity problems, which need a design decision each.
