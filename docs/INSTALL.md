# Install and configure

## 1. Install the module (REDCap administrator, once per server)

**From a downloaded copy**

1. Unzip the release archive `universal_validator_vX.Y.Z.zip` into the server's
   `modules/` folder. The archive holds one folder, `universal_validator_vX.Y.Z/`,
   with `config.json` at its top level, e.g.
   `redcap/modules/universal_validator_v2.3.3/`. Keep that folder name:
   External Modules reads the module's version from it.
2. In the **Control Center → External Modules → Manage**, the module appears as
   available. Click **Enable**.

### Version numbers

- A release is tagged `vX.Y.Z` in git, digits only. The same number is the
  first version heading of `CHANGELOG.md`, the name of the zip and the name of
  the folder in it. The 2.3.0 release candidates (`v2.3.0-rc.1` to
  `v2.3.0-rc.3`) were the last tags with a suffix.
- Each release has a higher number than the one before, so REDCap lists it as
  an upgrade. To upgrade, unzip the new release next to the old folder and
  enable the new version in **Control Center → External Modules → Manage**.
  REDCap runs one version for all projects.
- Do not rename a module folder, and do not copy a build into a folder with
  another number. REDCap would then show a version the code is not.
- To see which build a folder holds, open its `CHANGELOG.md`, on disk or at
  `<your REDCap URL>/modules/universal_validator_vX.Y.Z/CHANGELOG.md`. Its first
  version heading must be the folder's number, except in the folders listed
  below.

**Folder numbers already in use.** Before this rule, the 2.3.0 release
candidates were installed on the CHPR test server (chpr-redcap.org) in folders
renamed to plain numbers. No release takes these numbers, and CI refuses a tag
that does (`tools/release_reserved_versions.json`). On that server, a module log
entry or a report that names one of these versions means the tag beside it:

| Folder | REDCap shows | Holds tag |
| --- | --- | --- |
| `universal_validator_v2.3.0` | v2.3.0 | `v2.3.0-rc.1` (78d1acd) |
| `universal_validator_v2.3.1` | v2.3.1 | `v2.3.0-rc.2` (680b8fb) |
| `universal_validator_v2.3.2` | v2.3.2 | `v2.3.0-rc.3` (ba09488) |

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
— shows a configuration error in a notice at the top of the form (a user
without design rights sees only the errors of the fields on that form). Fields with
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
survey uniqueness throttle uses it. So are the four tables of the value index
of the live lookups (`uv_lookup_key`, `uv_lookup_field`, `uv_lookup_sync`,
`uv_lookup_record`), which the README's "Lookups on large projects: the value
index" describes, unless that index is turned off.

### Every table the module creates, and when

The module's own tables number 17 at most, all prefixed `uv_`. It writes to no table of
REDCap's, and creates none during data entry: a table is created only when an
administrator enables the module, installs a new version or saves the module's
system settings. Every statement is `CREATE TABLE IF NOT EXISTS`.

| Tables | Created | What they hold |
|---|---|---|
| `uv_rate_bucket` | Always | A counter per project and minute that limits `@UVUNIQUE` and `@UVEXISTS` lookups from surveys and into other projects. No value, no record. While it cannot be created or written, lookups from a survey are refused (the page shows nothing and the save goes through). |
| `uv_lookup_key`, `uv_lookup_field`, `uv_lookup_sync`, `uv_lookup_record` | Unless *Live lookups: turn OFF the value index* is checked | The value index. Empty until a project uses `@UVUNIQUE` or `@UVEXISTS`. A key is a keyed hash of a saved value (HMAC-SHA-256 with the module's secret and the project id) beside the record that holds it. |
| The scan's other twelve | Only with `scan-system-enable-durable` on | Scan runs, findings and their audit rows. |

To keep the module from creating anything but the counter table, check
*Live lookups: turn OFF the value index* before or right after enabling it and
leave `scan-system-enable-durable` off. A database user with no `CREATE` grant
gets no table: the module still validates in the browser and audits after each
save, the lookups read the data table, and lookups from surveys are refused.

### What stays when the module is disabled

- **Disabled in one project:** that project's rows of the value index are
  deleted, its running scan is stopped, and the module log gets
  `lookup-index-project-cleared`. Stored scan findings stay until their
  retention days pass.
- **Disabled on the server:** every running scan is stopped. Nothing is
  deleted unless the system setting *delete every table this module created
  ... when the module is disabled* (`scan-system-drop-tables-on-disable`) is
  checked. It is off by default, so that disabling and enabling again loses
  no scan. With it checked, all the module's tables are dropped.
- **To remove the tables by hand** after disabling without that setting, drop
  every table whose name starts with `uv_` in the list above.
- The module's project and system settings and its log entries are kept by
  REDCap as for any module, in `redcap_external_module_settings` and
  `redcap_external_modules_log`.

### What the module removes from its own log

Once a day the `uv_lookup_index` cron removes the module's operational log
entries older than 90 days: the cron entries (`scan-cron`, `scan-cron-skipped`,
`scan-cron-failed`, `scan-cron-state-unreadable`) and the entries that say a
request went wrong (`uvalidate-faults`, `uvalidate-page-error`,
`uvalidate-no-unique-transport`, `uv-throttle-storage-unavailable`). It never
removes what a project relies on: `invalid-id-saved` and the other findings of
the save audit, the reasons given under `@UVJUSTIFY`, and the scan's audit
entries stay until an administrator removes them. On a REDCap whose framework
has no `removeLogs()` nothing is removed.

### Upgrading to 2.5.0: the value index is rebuilt

Up to 2.4.0 a key of the value index was a plain MD5 of the value. From this
version it is a keyed hash. No table changes. The old keys stop being read at
once, the `uv_lookup_index` cron deletes them at its next pass (every 5
minutes) and builds each field again when a lookup next asks for it. Until a
field is built the lookups read the data table, as they do on a new
installation.

The module's database user needs a plain `CREATE` grant to make them and
`ALTER` to upgrade them. `CREATE TEMPORARY TABLES`, `CREATE VIEW` and
`CREATE ROUTINE` do not suffice; the scan page's health check says so by name
if the grant is missing.

### REDCap internals this module reads

Wherever REDCap has a documented method the module uses it: `REDCap::getData`,
`getDataDictionary`, `getRecordIdField`, `getEventNames`, `getGroupNames`,
`getUserRights`, `getInstrumentEventMappings`, `getRepeatingFormsEvents` and
`saveData`, and the framework's `query`, `log`, `queryLogs` and settings
methods. What follows has no documented method, or none that is fast enough
on a large project, so the module reads it directly. Every statement is a
`SELECT` with bound values; the module writes to no `redcap_` table. A REDCap
upgrade that renames one of these is what would break the feature named
beside it, and each read fails towards "not checked", never towards a wrong
answer.

| What is read | Used by | Why not a method |
|---|---|---|
| `redcap_metadata` (`misc`, counted for one project) | Every form and survey page | One indexed count says whether any field carries a module tag, so a project with no rule skips the dictionary read. |
| `redcap_projects` (`data_table`, `log_event_table`) | Validation scan, value index, live lookups, `@UVWRITEONCE`, `@UVWINDOW` | `REDCap::getDataTable()` and `REDCap::getLogEventTable()` are asked first where the REDCap has them. The column is read only when they give no usable answer. The name is checked against `redcap_data[0-9]*` or `redcap_log_event[0-9]*` before it is used. |
| `redcap_data` and its numbered siblings | Validation scan, `@UVUNIQUE`, `@UVEXISTS`, value index | `REDCap::getData` loads whole records. A duplicate check over 400,000 records needs one indexed read of one field. |
| `redcap_record_list`, `redcap_record_counts` | Validation scan | The list and count of records, a page at a time. |
| `redcap_log_event` and its numbered siblings (`data_values`, `pk`, `ts`, `user`, `event`, `description`) | Validation scan (what changed since a run began), `@UVWRITEONCE` (the first saved value), `@UVWINDOW` rules against today (when a value was saved) | REDCap has no method for "what was this field's earlier value", "when was it saved" or "which records changed after this log entry". The module parses `data_values` in the format REDCap writes (`field = 'value'`, one per line). |
| `redcap_user_rights`, `redcap_user_roles`, `redcap_user_information` | `@UVWRITEONCE` roles | Which role a user holds in the project, by role name. |
| `redcap_data_access_groups` | Validation scan | The group a scan is confined to. |
| `redcap_locking_data`, `redcap_locking_records` | Returned values (`@UVRANGE`, `@UVEXISTS` outputs) | The page does not fill returned fields on a locked form or record. |
| `redcap_events_metadata` (`arm_id`) | Returned values | REDCap locks a whole record per arm, so the lock is read for the arm of the event shown. A REDCap whose lock table has no arm column is asked without it. |
| `redcap_surveys`, `redcap_surveys_participants`, `redcap_surveys_response` (`completion_time`) | Returned values | A completed survey response opened on the data-entry form is read only until someone with the "Edit survey responses" right opens it for editing, so the page fills nothing there. |
| `new \Project($pid)`, `$GLOBALS['Proj']` | Events, forms and repeating instruments of the project | The project object REDCap itself builds for the request; read, never changed. |
| `$GLOBALS['super_user']` | Scan page | Whether the viewer is an administrator, where the framework's user object does not say. |

Two more things an administrator may want to know. The value index writes its
own tables with `INSERT ... ON DUPLICATE KEY UPDATE ... VALUES()`, which MySQL
8.0.20 marks as deprecated and still runs; the replacement syntax does not
exist in MariaDB or MySQL 5.7, which REDCap also supports. And the returned
values of `@UVRANGE` and `@UVEXISTS` are written with `REDCap::saveData` from
inside the `redcap_save_record` hook; the module marks its own save so that it
does not run again for it.

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
php -r 'require "php/Scan/Schema.php"; foreach (INSPIRE\UniversalValidator\Scan\Schema::plan(6) as $s) echo $s, ";\n";'
```

The number is the version the installation is at, which
`SELECT MAX(version) FROM uv_schema_version` returns: `Schema::plan(6)` for
2.3.0-rc.3 and the 2.3 releases after it, `Schema::plan(5)` for an earlier
2.3.0 release candidate, `Schema::plan(1)` for 1.9.x, `Schema::plan(0)` for an
installation with no scan tables. Run the statements in order, then save the
system configuration once. The module finds the changes already made, skips
them, runs the steps that are not DDL (the version-2 and version-8 data
steps below), verifies the result and records the version.

### Upgrading from 2.3 (schema versions 6 to 8)

An installation at version 5 first gets version 6, which creates the four
tables of the value index, each with `CREATE TABLE IF NOT EXISTS`.

Version 7 is `@UVJUSTIFY`'s. `uv_finding` gains `justified`, 1 when a reason
stands for the finding, and `uv_scan_run` gains `detail_justified`, how many
of a run's stored rows carry it. Both are 0 on every row that exists at the
upgrade. `uv_finding` is the largest table: MySQL 8.0 adds the column without
rebuilding it, and MySQL 5.7 rebuilds it, so on 5.7 the time grows with the
findings stored.

Version 8 changes three tables that hold only scan bookkeeping.

- `uv_scan_record` gains `unstored` and `stored_findings`: for each record, how
  many findings of its latest reading the detail limit left out, and how many
  it stored. With them come `unstored_justified` and `stored_justified`, how
  many of each a reason stands for. One `ALTER`, so MySQL 5.7 rebuilds the
  table once.
- `uv_scan_run` gains `open_unstored`, `open_findings`, `open_justified` and
  `open_unstored_justified`, their sums for the run, in one `ALTER`. None has
  a default. All four are NULL on every run that exists at the upgrade and on
  any run the previous version starts while the upgrade runs; the new version
  writes 0 on each run it starts.
- `uv_unique_candidate` gains `branch`, the branch of the `@UVUNIQUE` rule
  that offered the value (NULL for a rule with no branches), so a duplicate
  is reported with its branch's message.
- `uv_unique_candidate` gains the index
  `ix_record (project_id, generation_id, record_hash)`, so catch-up can remove
  the duplicate candidates of a record it reads again, or that was deleted,
  without reading every candidate of the run.

That is four statements, and their time grows with the rows in those three
tables. Measured with 400,000 records and 400,000 candidates, before the two
justified columns joined each of the first two `ALTER`s: MySQL 8.0 adds
the columns at once and spends about 17 seconds on the index. MySQL 5.7
rebuilds `uv_scan_record` (10 to 19 seconds over two runs on a shared test
host), rebuilds `uv_unique_candidate` to add `branch` (about 15 seconds),
and spends 2 to 5 seconds on the index: about 27 seconds in all on the
second run.

Then one data step: each run that had already finished its catch-up pass is
marked as having done so, 5,000 runs per `UPDATE`. The row shows this for a
run in either finishing phase, and for a stopped or ended run whose summary
had begun. A scan of one Data Access Group stopped or ended after that point
shows its counts again. One stopped earlier in the finishing phases cannot be
told apart from one stopped before catch-up ended, so it keeps saying its
counts are shown once it has confirmed its group.

Runs that exist at the upgrade are counted the old way, from every finding
row they wrote and from their summary. That shows only where such a run has
no summary: one still in progress, or one that failed, was cancelled or
expired, which may say its findings were not counted where a later run
shows 0. A run in progress at
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

The module declares three crons, which REDCap's own cron runner calls, so
the server's REDCap cron has to be running. The scan's two do nothing while
`scan-system-enable-durable` is off; while the tables are not ready a pass does
nothing and logs `scan-cron-skipped`, once for each reason and once a day while
it lasts. The third keeps the value index of the
live lookups (README, "Lookups on large projects: the value index") and runs
whether the scan is on or not.

| Cron | Runs every | Longest run | What it does |
|---|---|---|---|
| `uv_scan_reap_abandoned` | 15 minutes | 300 seconds | Ends as expired a run that made no progress for `scan-system-stale-run-hours` (24 by default), removes worker places above `scan-system-max-concurrent-projects`, and closes the previous findings of each project whose latest whole-project run finished complete. |
| `uv_scan_expire_values` | hour | 900 seconds | Clears stored values that a tightened privacy setting revoked, deletes finished runs past their project's run retention, and clears stored values past their value retention. |
| `uv_lookup_index` | 5 minutes | 240 seconds | Builds the value index of a field too large to build during a lookup, refreshes it weekly, and drops the index of a field no lookup has used in 30 days. Brings a project's index up to date when no lookup has asked about the project for a long time. Its summary line says when the index cannot work. |

A pass uses a quarter of its longest run, reads the projects with scan data
500 to a statement, works in bounded slices, and saves where it stopped so the
next pass carries on from there. A project whose step fails is logged as
`scan-cron-step-failed` and tried again next pass; the pass goes on to the next
project. A pass that changed something, stopped early or had a failure ends
with a `scan-cron` entry in the module log, which counts what it did; a pass
that found nothing to do writes no entry, and REDCap's own cron history has its
one-line summary. Pressing Start also ends an abandoned run of its own
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
   last, which deletes every stored scan, finding and audit row and the value
   index of the live lookups, and forgets where the crons stopped.

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
  (External Module Framework version 14). Both ship enabled in standard PHP
  builds, but some minimal builds omit them. `config.json` declares the REDCap
  and PHP versions; the framework has no key for extensions, so the module's
  description names them. On a server without one, enabling the module and
  each save request write a `uvalidate-missing-extension` entry to the module
  log, the save audit does not run, and the Validation scan page says which
  extension is missing.
- After installing or upgrading, run the manual integration checklist in
  [`TESTING.md`](TESTING.md) on a test project.
