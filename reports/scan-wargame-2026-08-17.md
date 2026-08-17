# Validation scan: adversarial battle-test

Companion to `reports/scan-implementation-review-2026-08-17.md`. That review was a read.
This one is executable.

**Harness:** `tools/temporal_scan_wargame.php` (export-ignored, so it never ships). Run it:

```bash
php tools/temporal_scan_wargame.php .
```

It stands up the same fake `REDCap` the suite uses, drives the real `scanProject()`,
`pages/export.php`, `ScanColumns`, `MessageCatalog` and `ScanCapabilities`, and prints
CONFIRMED per reproduced defect. Baseline before starting: `hosting_php` 121,
`scan_page_php` 78, `scan_capabilities_php` 53, `hook_php` 285 — **537 checks, 0 failures**,
PHP 8.3.32.

**Result: 28 probes, 27 defects reproduced.**

The suite is green because it asks the questions the implementation already answers. Every
probe below is a question nothing asks.

---

## Critical — none of these were in the first review

### X1. A scan that checked nothing reports "Scanned 400 record(s)"

`UniversalValidator.php:2153` sets the headline before the loop runs:

```php
$result['stats']['records'] = count($ids);
```

and nothing ever revises it. The halt guard added in 1.6.4 counts separately, into
`$reached`, and only `$reached` reaches the prose note.

Probe W28, memory guard tripped at the first chunk boundary:

```
halt fired                   : true
status                       : incomplete
stats.records (the headline) : 400
incomplete note              : the scan stopped after 0 record(s) … 400 record(s) were not checked
page prints                  : Scanned <b>400</b> record(s)
export prints                : | records 400
```

Zero records examined. The page's first line, in bold, claims four hundred. The export's
metadata line claims four hundred. The truth is in a bullet inside a warning box, and the CSV
carries it only as a `#` comment and a `not-scanned` row far below the number a reader
already took.

The whole 1.6.4 release exists so that a stopped scan says so. It says so in the small print
while the headline says the opposite. **Report `$reached` as the scanned count and the
manifest size separately, and never let the two share a label.**

### X2. Hashed-record-id mode leaks the raw record id

`log-values = none` is documented as *"minimal-identifier mode for sites where record IDs are
themselves participant identifiers."* `reportRecordId()` (`UniversalValidator.php:2293`) is
applied to findings. It is applied to nothing else.

Probe W3, same run, same file:

```
finding Record column = 4c863533044de875023742bf…   (HMAC)
incomplete note       = record PATIENT-0008 was requested but not returned
```

The note is rendered verbatim on the page (`pages/scan.php:174`), written as a `#` comment in
the export (`pages/export.php:87`) and written again as a `not-scanned` data row
(`pages/export.php:118`). Sources: `UniversalValidator.php:2221` and `:2512`, both of which
interpolate `$rec` raw.

So the mode that exists to keep record ids out of the report puts them in the report, in the
downloadable file, three times, for exactly the records a site is most likely to be chasing.

### X3. Project-scope `@UVUNIQUE` is silently wrong for a DAG user

`collectUniqueCandidates()` builds its group key from the records the scan actually read. A
DAG-confined scan reads only that DAG. A rule whose scope is `project` is then evaluated
against a fraction of the project and reports no duplicate.

Probe W5, one value duplicated across two DAGs:

```
project-wide scan  duplicate findings = 2
DAG-scoped   scan  duplicate findings = 0
DAG scan status                       = complete
any warning that uniqueness is scoped = NONE
```

Status `complete`. Green tick. No note, no rule problem, nothing in `incomplete`.

The live `unique-check` endpoint queries the whole project and **would** flag it. The README
claims *"the same dispatch the save-hook audit uses, so the two can never disagree about what
a violation is."* Here they disagree, and the scan is the one that is wrong, and it is the one
that issues certificates.

Every other unevaluable condition in this module produces an entry in `unconfigurable`. This
one produces silence. **A project-scope unique rule under a DAG scope must be reported as a
rule that could not be evaluated.**

### X4. The dialog's Rule label and Message are discarded for check-character rules

`settingRowToRule()` reads `$s['message']` and `$s['rule-note']` at
`UniversalValidator.php:1424` and `:1432` — **inside the `constraint | required | unique`
branch only.** The `single | pooled` branch starts at `:1480` and reads neither.

Probe W26:

```
single    note=NO  message=NO
pooled    note=NO  message=NO
required  note=yes message=yes

rendered "Rule name"    = ''
rendered "Wording from" = 'catalog'
```

Consequences, all shipped in this release:

- The **Rule name** column, added in 1.8.0, is permanently blank for every check-character and
  pooled rule — the two kinds the module is named after.
- `docs/TESTING.md`, added in the same commit, instructs the tester: *"Set a rule's **Rule
  label** in the dialog; it appears in the Rule name column."* It does not. The checklist item
  fails on the most common rule kind and passes on the others, which is the worst possible
  mix, because whoever runs it will try one rule and generalise.
- `MessageCatalog`'s tier 1 — *"the rule author's own message — only they can word what a rule
  means"* — is **unreachable** for single and pooled. The **Wording from** column, whose stated
  purpose is to show a designer which rules still lack authored wording, will report `catalog`
  for those rules no matter what the designer writes.

And the annotation channel does not rescue it. Probe W27:

```
configError = @UVALIDATE on "sid": unknown @UVALIDATE option(s): message —
              valid: type, algorithm, source, pattern, strip, keepChars, idLengths,
              idMinLen, idMaxLen, expectedIds, blockSave, when, suggestFix, note.
```

`@UVALIDATE` rejects `message` outright and turns the rule into a config error. So a
check-character rule has **no channel at all** for authored wording, through either
configuration route.

The 1.8.0 commit for `rule-note` says it plainly: *"config.json has offered 'rule-note' since
the dialog existed and nothing ever read it, so a designer could name a rule and never see the
name again."* That was fixed for three rule kinds out of five and the CHANGELOG does not
mention the gap.

### X5. Losing the HMAC key empties the Record column instead of marking it

```php
private function reportRecordId(array $plan, $rec)
{
    if (empty($plan['hashRecordIds'])) return (string) $rec;
    try {
        return $this->hashedIdentifier($plan['pid'], (string) $rec);
    } catch (\Throwable $e) {
        return '[record id withheld]';      // never fall back to the raw id
    }
}
```

`hashedIdentifier()` returns **null** when `hmacKey()` cannot obtain a key — it catches its own
failure at `:683` and returns null rather than throwing. The `catch` never fires.

Probe W3b, system settings unavailable:

```
reportRecordId() returned : NULL
rendered Record cell      : ''
documented fallback       : "[record id withheld]"
```

Every finding renders with an empty Record column. On screen that is a table of violations
with no way to reach any of them. In the CSV it is `""` in the first location column, which a
reader will take for a data problem in their own export. The one outcome the comment promises
cannot occur.

### X6. The export certifies a project that enforces nothing

`pages/scan.php:55` gets the clean predicate right:

```php
$clean = $complete && !$result['violations'] && !$result['unconfigurable'];
```

`pages/export.php:71` does not have one:

```php
$complete = ($result['status'] === 'complete');
```

Rule problems do not affect `status`, so a project where every rule is a configuration error
is `complete`. Probe W19, one field, one broken `@UVALIDATE`:

```
rule problems found = 1
file asserts clean  = true      ("No violations found.")
incomplete banner   = absent
filename suffix     = none
```

The downloaded file's first data row is the sentence **No violations found.** The rule-problem
rows appear below it, in a different column arity, after the reader has already read the
verdict. The filename carries no marker, so the file forwards as a clean result.

Plan §2: *"The UI and every exporter use one shared predicate."* There are two, and the one on
the artefact people file and cite is the weaker.

---

## Reproduced from the first review

Each of these was argued from the source. Each now has a run.

| # | Defect | Evidence |
|---|---|---|
| W1 | Formula defusing inspects byte 0 only | `csv("=cmd")` → `"'=cmd"`; `csv(" =cmd")` → `" =cmd"`. Bypassed by leading **space, TAB, CR, LF, VT, BOM, NUL** |
| W2 | NUL and control bytes reach the CSV | `reportValue()` passes `ok\x00INJECT\x1a\x07`; cell hex `226f6b00494e4a4543541a0722` |
| W4 | Empty in-scope manifest certifies clean | DAG `south`, 0 records, `status=complete`, clean predicate `true`, 0 incomplete notes |
| W6 | A sink failure escapes `scanProject()` | `RuntimeException` propagates out; return value never produced; `pages/export.php` has no `try` |
| W7 | Degraded event names delete the Event column | names readable → column present, rows differ. Names unreadable → column **absent**, the two events render **byte-identical** |
| W8 | `degraded[]` is unreachable | `{"events":"no event names were returned"}` populated; appears in rendered output: `false` |
| W9 | CSV header is labels, not keys | `issue,record,…` vs `Issue,Record,…` |
| W11 | Withheld value looks like a real blank | required-blank cell `''`, policy-withheld cell `''` |
| W12 | `schemaPrivilege` substring-matches CREATE | false positives on `CREATE TEMPORARY TABLES`, `CREATE VIEW`, `CREATE ROUTINE`, a db named `createdb`, a user named `app_create` |
| W13 | `sourceFence` claims a fence on a table name | state `available`, derived `maxCompletion=complete-through-fence`, `incremental=true`, with no retention or taxonomy query |
| W14 | The hard gate passes without a probe query | `available via redcap_data keyset walk` from a module whose every query returns nothing |
| W15 | `policy()` unwired; fenceless scan says complete | `status=complete`; no reference to `policy()`, `maxCompletion` or `manifest-complete` anywhere in production code |
| W16 | Record list materialised before any budget check | first `getData` call: `records=NULL` |
| W17 | Findings join to rules by array ordinal | rule list reordered between the two `getRules()` reads → `aa` labelled **RULE-B**, `bb` labelled **RULE-A** |
| W18 | `array_chunk()` doubles the id list | 200,000 ids → +8 MiB, held for the whole scan |
| W20 | A clean export has no header row | only data line: `"No violations found."` |
| W21 | Export CSV is not rectangular | field counts per row: `11,11,4,4` |
| W22 | Export removes the time budget | `max_execution_time` 30 → **0** after including `pages/export.php`; `scanHalt()` 99999s past any deadline returns `NULL` |
| W23 | Value policy discloses on unset **and** on failure | field flagged `identifier="y"`; unconfigured → `NHS-4457711`; settings read throws → `NHS-4457711` |
| W24 | Two live export formats | `pages/export.php` quoted labels with Value and explanation; `scan.php&csv=1` unquoted `section,record,event_id,…`, no value, no BOM, no `_INCOMPLETE` suffix |
| W25 | Hidden-choice labelled "Wrong value" | type `choices`, reason `hidden-choice`, Issue column `Wrong value` — for a code that was a legal choice when it was saved |

---

## Correction to the first review

**M4 overstated the case.** I wrote that an authored rule message makes a format failure and a
check-character failure render identically. Probe W10 refutes it: the catalog resolves the two
reasons to different sentences, and the authored message cannot mask them because it never
reaches a single or pooled rule at all (X4).

What survives from M4:

- `type` and `reason` are absent from the report and the CSV. The `Issue` column maps two types
  by name and collapses `single`, `pooled`, `constraint` and `choices` into **"Wrong value"**
  (W25 shows the cost).
- Any consumer that scripted against the old `type` / `reason` columns is broken with no
  migration path.

---

## Checked and clean

Recorded so nobody re-spends the time:

- The `$emitted` dedup key in the unique tail (`UniversalValidator.php:2251`) joins with `|`
  where the group key uses `bin2hex`. Worked through: event ids and instances are numeric and
  the field name is fixed, so no two distinct locations can produce the same string. It is an
  inconsistency, not a collision.
- `ScanPageView::h()` covers every generated cell in the table, including a value containing
  markup. Verified in `tests/scan_page_php.php:639`.
- The DAG filter fails **closed**: a record whose group cannot be read is excluded, not
  included. The problem is what happens when it excludes everything (W4), not the direction.
- Chunk-size independence holds: sizes 1 and 500 produce identical findings and status
  (`tests/hook_php.php`).
- `parseByteSize()` handles `-1`, bare bytes, and binary K/M/G suffixes correctly.

---

## What the green suite is not telling you

Three test-quality problems let all of the above through.

**The mocks cannot produce the shapes the code branches on.** In
`tests/scan_page_php.php:150-157`, `getEventNames()` and `getGroupNames()` both return
**strings**. `ScanDimensions` requires arrays. So `events` is always empty and `hasDags` always
false, and these two assertions

```php
check('columns: a classic project shows no Event column', …);
check('columns: a project with no DAGs shows no DAG column', …);
```

pass because the labels were unreadable, not because of project shape. **Neither column has
ever been rendered by a test.** W7 is the bug hiding behind that. This is the same defect
1.6.3 was written to fix in the chunk mocks, in a new place.

**The differential compares the extraction to itself.** The SINK section proves
`CallbackFindingSink` agrees with `ArrayFindingSink`. It cannot see W6, because no scenario
has a sink that fails, which is the only thing a streaming sink does that an array one does
not.

**The capability tests exercise `policy()`, never the probes' judgement.** The 64-subset
monotonicity proof is genuinely good, and it operates on hand-built capability arrays. The
probes that decide those arrays get one happy-path fixture each — which is why W12, W13 and
W14 survive a 53-check file whose stated purpose is that the module never guesses.

---

## Ranked

| | Defect | Why it ranks here |
|---|---|---|
| 1 | X1 halted scan reports full manifest as scanned | The headline number is wrong in the safe-looking direction, on the release built to prevent that |
| 2 | X2 raw record ids in hashed mode | A privacy mode that publishes what it exists to withhold |
| 3 | B4 no export/form rights check (first review) | Design rights now yield every raw value on every form |
| 4 | X3 project-scope uniqueness silently wrong under a DAG | A wrong clean answer, with the live endpoint disagreeing |
| 5 | B3 / W23 value default raw, fails open | Every existing project discloses on upgrade |
| 6 | X6 export has no clean predicate | The filed artefact certifies a project enforcing nothing |
| 7 | B2 / W15 `complete` with no fence | The plan's central property, implemented and unwired |
| 8 | B6 / W4 empty scope certifies clean | S-03 reached by a second route |
| 9 | X4 rule label and message dropped | A shipped feature that cannot work, with a doc telling testers it does |
| 10 | X5 empty Record column | Findings nobody can act on |
| 11 | W1 / W2 CSV injection and control bytes | Named in the plan, still open |
| 12 | W22 export disables the time budget | Two runs, two coverage answers |
| 13 | W17 ordinal rule identity | Silently attributes findings to the wrong rule |
| 14 | W12–W14 capability probes fail open | Latent while unwired; wrong the moment they are used |
| 15 | W20 / W21 CSV shape | Breaks consumers, no data loss |

Items 1–8 should block a server. Nothing below item 4 gets safer by waiting for the durable
rebuild, because the surface they are wrong on is the one already shipped.

## Tests these should become

Each maps to a probe already written:

- halted scan: `stats.records` must equal records **examined** (W28)
- `log-values=none`: no raw record id anywhere in the result, notes included (W3)
- no HMAC key: Record column is the marker, never empty (W3b)
- DAG scope + project-scope unique: a rule problem is raised (W5)
- every rule kind: `rule-note` and `message` survive `settingRowToRule()` (W26)
- export: rule problems block the clean sentence and set the suffix (W19)
- CSV: header always present, every row the same width, defusing after whitespace (W1, W20, W21)
- `ScanDimensions`: an **array** from `getEventNames`/`getGroupNames`, so the Event and DAG
  columns are actually exercised (W7, and the mock fix that makes it possible)
- capability probes: `CREATE TEMPORARY TABLES` is not CREATE (W12)
