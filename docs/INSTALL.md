# Install and configure

## 1. Install the module (REDCap administrator, once per server)

**From a downloaded copy**

1. Put the module folder on the server under `modules/` named with its version,
   e.g. `redcap/modules/universal_validator_v0.4.0/` (the folder must contain
   `config.json` at its top level).
2. In the **Control Center → External Modules → Manage**, the module appears as
   available. Click **Enable**.

**From the REDCap repository** (once published): Control Center → External
Modules → **View modules available in the repository** → find *Universal Field
Validator* → **Download** → **Enable**.

## 2. Enable it on a project (project administrator)

Project **External Modules** page → **Enable a module** → *Universal Field
Validator*.

## 3. Add validation rules

One rule = one kind of validation, applied to any number of fields. Two places to
declare rules; they mix freely, and a field claimed twice shows a configuration
error rather than running two validators.

### A. The Configure dialog (project settings)

Add one **Validation rule** per kind of validation:

- **Rule label** — optional, your own name for the rule (e.g. "Specimen IDs").
- **Field type** — *Single value* (one ID/code per field) or *Pooled* (several
  IDs in one box).
- **Field(s)** — pick fields with the picker and click its **+** to add more
  fields to the same rule, and/or type extra field names into the **fast entry**
  box (comma- or space-separated; unknown names show a configuration error).
- **Check-character method** — must match how the IDs were minted. The generator's
  default is *ISO 7064 Mod 37,36*. Validating a plain format with no check
  character (study codes, legacy IDs)? Choose *No check character* and set the
  format pattern.

### B. `@UVALIDATE` field annotations (bulk setup)

Tag fields where you already design them — the **Action Tags / Field Annotation**
box in the Online Designer, or the `field_annotation` column of the data
dictionary CSV. To validate 50 fields, fill one spreadsheet column and upload the
dictionary once.

```text
@UVALIDATE                                            default check (ISO 7064 Mod 37,36), message only
@UVALIDATE=verhoeff                                   pick the algorithm
@UVALIDATE={"algorithm":"none","pattern":"FC[0-9]{4}","blockSave":"hard"}
@UVALIDATE={"type":"pooled","expectedIds":3}          pooled field, warn unless 3 IDs
```

JSON keys: `type`, `algorithm`, `source`, `pattern`, `strip`, `keepChars`,
`idLengths`, `idMinLen`, `idMaxLen`, `expectedIds`, `blockSave`, `when`,
`suggestFix`, `note`. Use double quotes inside the JSON. A malformed tag
(typo'd key, unknown algorithm, bad JSON) — or a tag on a non-Text/Notes field
— shows a configuration error in a notice at the top of the form. Fields with
identical tags are grouped into one rule automatically. Tags work on Text and
Notes fields.

The optional `when` key makes a rule conditional — it validates only while a
REDCap-style condition is true, e.g.
`@UVALIDATE={"algorithm":"verhoeff","when":"[specimen_type]='2'"}`. The
Configure dialog offers the same thing as an "Only validate when" box per rule.
One field may carry SEVERAL tags (or be covered by several dialog rules) when
each has a different condition, plus at most one without as the fallback —
branched validation. `suggestFix` (default false) opts in to the "should end
in X" check-character hint. See the README's "Conditional validation" and
"Branched validation" sections for the supported syntax and exact semantics
(a false condition skips the rule; it does NOT erase the value).

The `algorithm` value accepts case-insensitive shorthands so you need not type
the full name: `3736` (or `37,36`, `mod37_36`) → `iso7064_mod37_36`, `9710` →
`iso7064_mod97_10`, `112` → `iso7064_mod11_2`, `mod10` → `luhn`, the digit-only
weighted schemes `gs1`/`aba`/`mrz`/`isbn` → `gs1_mod10`/`aba_mod10`/`mrz_mod10`/
`weighted_mod11`, `regex`/`format` → `none`, and so on. The full names still work;
see the table in the README for the complete list.

### Shared rule options
- **On an invalid value** — *Informational*, *Advisory*, or *Compulsory*. All
  three are **browser** behaviors; API and data-import writes cannot be blocked
  from this module (see the audit notes below). *Compulsory* never traps a
  read-only field — a person who cannot edit the value is not blocked by it.
- **Advanced (optional)** — payload source (whole ID / digits only / trailing
  number), a format regex (printable ASCII, matched against the UPPERCASED
  value; catastrophic shapes — `(a+)+`, `(a|aa)+`, and overlapping unbounded
  quantifiers like `.*.*` or `[0-9]*[0-9]*` — are rejected when you save the
  settings), and separators to ignore. For *Pooled* rules only:
  exact ID length(s) or a min/max range (up to 64 characters per ID), extra
  characters to keep, and the expected pool size.

Invalid rules are rejected when you press Save in the Configure dialog, with a
message naming the rule and the problem — a bad rule never reaches data-entry
staff silently.

There is also one project-level setting, **How to log invalid values caught by
the post-save audit**. Every mode except *off* logs field, instrument, event,
and instance; the modes differ in how the invalid value and the record ID are
stored: *hashed* (default) stores a keyed hash of the value (HMAC-SHA-256 with
a module-held secret, scoped to the project) with the raw record ID so staff
can fix the record; *strict* stores no value and applies the keyed hash to the
record ID too (for sites where record IDs are themselves identifying); *raw*
stores both readable; *off* logs no detections (identifier-free audit ERRORS
are still logged so failures stay visible). A keyed hash is pseudonymization,
not anonymity — treat the module log as identifying data in your access and
retention policies. Pick the option your data-governance policy allows before
turning the module loose on real IDs.

Save. Open any record with those fields and type an ID — you'll see the live
verdict. A field listed in more than one rule shows a configuration error instead
of running two conflicting validators; give each field exactly one rule.

## 4. Confirm it works

- Type a correct minted ID → green "verified".
- Change one character → red "typo?" (and, if the format is still valid, it says
  the check character is the problem, not the format).
- Set a rule to *Compulsory* and try to save with a bad ID → the save is blocked
  until you fix it.

## The Validation scan

The scan stays off until the system setting `scan-system-enable-durable` and,
per project, `scan-enable-durable` are both on. Pilot it on one project before
turning it on more widely. What it does and what a finished run says are in
the README's "The Validation scan" section; this part is what an
administrator has to know to run it.

### Its tables

The scan keeps its work in thirteen tables of its own, all prefixed `uv_`:
`uv_schema_version`, `uv_scan_run`, `uv_scan_record`, `uv_finding`,
`uv_unique_candidate`, `uv_unique_group`, `uv_scan_worker_slot`,
`uv_scan_aggregate`, `uv_scan_dim`, `uv_scan_audit`, `uv_project_seq`,
`uv_scan_plan` and `uv_rate_bucket`. They are created when an administrator
saves the module's system configuration with `scan-system-enable-durable` on,
and when the module is enabled or a new version installed while it is on.
`uv_rate_bucket` is created in every case, even with the scan off, because the
survey uniqueness throttle uses it.

The module's database user needs a plain `CREATE` grant to make them and
`ALTER` to upgrade them. `CREATE TEMPORARY TABLES`, `CREATE VIEW` and
`CREATE ROUTINE` do not suffice; the scan page's health check says so by name
if the grant is missing.

### A schema change blocks the request that makes it

The module installs or upgrades its schema inside the request that asked for
it: saving the system configuration, enabling the module, or installing a new
version. That request does not return until every statement has run, and on
large tables an `ALTER` can take minutes. So:

- run an upgrade outside working hours, or at least when nobody is scanning;
- take a database backup first, as for any migration;
- if the request times out, save the system configuration again. Every
  statement checks whether it has already been applied, and the new version is
  recorded only after the result has been verified.

Until the schema is at the version the code expects, the scan is off. The page
says the scan's own tables are not ready and names what is missing, no run
starts or takes another batch, and the crons skip their pass. Each attempt is
written to the module log as `scan-schema-migrate`, with the version it
started from, the version it reached and, when it stopped, why. A failed
migration never half-installs.

To run the statements yourself (a database user with no `CREATE` or `ALTER`
grant, or a change you want to time), print them from the module's folder on
the server:

```bash
php -r 'require "php/Scan/Schema.php"; foreach (INSPIRE\UniversalValidator\Scan\Schema::plan(5) as $s) echo $s, ";\n";'
```

The number is the version the installation is at, which
`SELECT MAX(version) FROM uv_schema_version` returns: `Schema::plan(5)` for a
2.3.0 release candidate, `Schema::plan(1)` for 1.9.x, `Schema::plan(0)` for an
installation with no scan tables. Run the statements in order, then save the
system configuration once. The module finds the changes already made, skips
them, runs the steps that are not DDL (the version-2 and version-6 data
steps below), verifies the result and records the version.

### Upgrading from a 2.3.0 release candidate (schema version 5 to 6)

Version 6 changes three tables that hold only scan bookkeeping. `uv_finding`,
the largest, is not touched.

- `uv_scan_record` gains `unstored` and `stored_findings`: for each record, how
  many findings of its latest reading the detail limit left out, and how many
  it stored. One `ALTER`, so MySQL 5.7 rebuilds the table once.
- `uv_scan_run` gains `open_unstored` and `open_findings`, their sums for the
  run, in one `ALTER`. Neither has a default. Both are NULL on every run that
  exists at the upgrade and on any run the previous version starts while the
  upgrade runs; the new version writes 0 on each run it starts.
- `uv_unique_candidate` gains the index
  `ix_record (project_id, generation_id, record_hash)`, so catch-up can remove
  the duplicate candidates of a record it reads again, or that was deleted,
  without reading every candidate of the run.

That is three statements, and their time grows with the rows in those three
tables. Measured with 400,000 records and 400,000 candidates: MySQL 8.0 adds
the columns at once and spends about 17 seconds on the index; MySQL 5.7
spends about 19 seconds rebuilding `uv_scan_record` and 5 on the index.

Then one data step: each run that had already finished its catch-up pass is
marked as having done so, 5,000 runs per `UPDATE`. The row shows this for a
run in either finishing phase, and for a stopped or ended run whose summary
had begun. A scan of one Data Access Group stopped or ended after that point
shows its counts again. One stopped earlier in the finishing phases cannot be
told apart from one stopped before catch-up ended, so it keeps saying its
counts are shown once it has confirmed its group.

Runs that exist at the upgrade are counted the old way, from every finding
row they wrote. That shows only where such a run has no summary: one still in
progress, or one that failed, was cancelled or expired. A run in progress at
the upgrade ends on its next batch as failed, with the reason that the module
was upgraded while it ran, because this build judges some values differently;
start it again. Runs started after the upgrade are counted as the README's
"The finding count" says.

### Upgrading from 1.9.x

**This upgrade deletes every scan finding stored by 1.9.x, and it is not
reversible.** If a scan was ever run on this server, read this section before
saving the configuration.

Three things about a finding changed in 2.0.0. They are which rule it is
attributed to, what makes two findings "the same finding", and the numbering
that separates one run from the next. A finding written by 1.9.x is therefore not comparable with
one written by 2.0.0. It cannot be matched, and it cannot be closed. Keeping
those rows would leave a report mixing two schemes whose older half can never be
updated again, and on a server with more than one project they are additionally
attributed to the wrong project, because the tables that hold them had no project
column at all until this release.

So the upgrade removes them, in pages, and says so in the module log. Nothing
else is touched: no REDCap table, no project data, no record. The findings are
re-derivable by running the scan again.

Any scan that was still running is ended and marked `expired`. That is deliberate
too: those runs are holding their projects' only scan slot, several of them since
1.9.0, because nothing in 1.9.x ever reclaimed an abandoned run.

On a server with a large `uv_finding` table this upgrade can take minutes,
inside the request that saves the configuration; see the section above. If it
fails, the scan page names what failed and the scan stays off.

### Listing a large project takes more than one request

Before a scan checks any record it lists the project's records into its own
table, and that listing runs a few seconds at a time: Start lists the first
slice, and each request the open scan page sends after it lists the next, so no
single request has to outlast PHP's `max_execution_time` however large the
project is. While it runs, the page shows the phase "Listing the records to
check" and "Preparing" with no percentage, because the total is not known until
the last slice is in. Closing the page pauses the listing, and Continue carries
on from the last slice saved; no record is added twice. A listing nobody is
driving reads as paused, however long ago it stopped, and Continue resumes it.
If the same slice fails as many times in a row as the record-attempts setting
allows (3 by default), the scan ends as failed, and the run's stored reason
says planning could not finish.

### The crons

The module declares two crons, which REDCap's own cron runner calls, so the
server's REDCap cron has to be running. Neither does anything while
`scan-system-enable-durable` is off. While the tables are not ready a pass does
nothing and logs `scan-cron-skipped`.

| Cron | Runs every | Longest run | What it does |
|---|---|---|---|
| `uv_scan_reap_abandoned` | 15 minutes | 300 seconds | Ends as expired a run that made no progress for `scan-system-stale-run-hours` (24 by default), removes worker places above `scan-system-max-concurrent-projects`, and closes the previous findings of each project whose latest whole-project run finished complete. |
| `uv_scan_expire_values` | hour | 900 seconds | Clears stored values that a tightened privacy setting revoked, deletes finished runs past their project's run retention, and clears stored values past their value retention. |

A pass uses a quarter of its longest run, reads the projects with scan data
500 to a statement, works in bounded slices, and saves where it stopped so the
next pass carries on from there. A project whose step fails is logged as
`scan-cron-step-failed` and tried again next pass; the pass goes on to the next
project. Each pass ends with a `scan-cron` entry in the module log, which
counts what it did. Pressing Start also ends an abandoned run of its own
project at once, so a project never waits for the reap cron to scan again.

Run retention is `scan-run-retention-days` per project (90 days by default,
never more than `scan-system-max-run-retention-days`), and value retention is
`scan-value-retention-days` (30 days by default, never more than
`scan-system-max-value-retention-days`). A project may choose fewer days than
the system allows, never more.

### Disabling the module

Disabling the module on the server does three things, in order.

1. It writes a stop marker. A cron pass running in another process stops
   within a few seconds, at its next step, and a Start or a batch that arrives
   while the disable runs is refused.
2. With the scan switched on and its tables ready, it stops every active run
   and then frees every worker place. Each stopped run finishes as cancelled,
   with the reason that the module was disabled on this server and that
   nothing it had examined was lost. The disable spends at most 20 seconds
   finishing runs; a run it did not reach stays at *Stopping* until Start is
   pressed on its project, or the reap cron finishes it once it is past
   `scan-system-stale-run-hours`.
3. It deletes nothing, unless `scan-system-drop-tables-on-disable` is on. With
   that opt-in on, it drops every table listed above, `uv_schema_version`
   last, which deletes every stored scan, finding and audit row, and forgets
   where the crons stopped.

The disable never fails because of the scan; each step is logged
(`scan-disable`, `scan-disable-dropped`, `scan-disable-failed`). Enabling the
module again lets scans start at once. With the opt-in off, every earlier scan
is where it was; with it on, enabling with the scan switched on creates empty
tables.

Disabling the module for one project stops that project's run the same way,
with the reason that the module was disabled for this project, frees its
worker places, and deletes nothing (`scan-project-disable` in the log).

## Notes

- The module injects its own JavaScript; the JavaScript Injector module is **not**
  required and should not also carry an ID-check script for the same fields.
- The server-side audit validates the saved value with the same rules as the
  client — single and pooled fields, check character, format pattern, and
  regex-only — and logs any invalid value to the module log, scoped to the
  instrument that was saved. It fires *after* the write, and only where REDCap
  invokes `redcap_save_record`: **verify on your own instance whether that
  covers Data Import Tool and API writes** (the check is in
  [`TESTING.md`](TESTING.md)) before relying on it for those paths.
- Format-pattern auditing applies to printable-ASCII values; a value containing
  other characters is left to the client and check-character validation rather
  than risking a browser/server disagreement.
- Requires REDCap 13.7+ and PHP 7.4+ with the `mbstring` and `ctype` extensions
  (both ship enabled in standard PHP builds, but some minimal builds omit them;
  declared in `config.json`; External Module Framework version 14).
- After installing or upgrading, run the manual integration checklist in
  [`TESTING.md`](TESTING.md) on a test project.
