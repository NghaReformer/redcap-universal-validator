# Validation scan live run sheet

The live test of the durable Validation scan on a real REDCap 17.x server. The
automated suites prove the scan's logic against stubs, and `tests/mysql/` proves
its SQL against MySQL and MariaDB. This sheet checks what only a live REDCap can
show: the page and its requests, REDCap's own rights and log, the module log, the
crons, and switching the module off.

Two projects:

- **Project A**, classic (no events, no repeating instruments), built for this
  sheet from the dictionary and data below. Every expected count in this sheet is
  for Project A.
- **Project B**, longitudinal with a repeating instrument or a repeating event.
  chpr-redcap.org pid 149 has both, and its seeded records XE-1 to XE-4.

Four accounts, all with design rights on both projects:

| Account | Export rights | Data Access Group (Project A) |
|---|---|---|
| designer | Full Data Set | none |
| north | Full Data Set | `north` |
| deid | De-Identified | none |
| admin | REDCap administrator | none |

Beside every step, write pass, fail, or what happened instead.

---

## Setup

### Project A dictionary

One instrument, `scan_demo`. Upload these rows as the data dictionary.

```text
Variable / Field Name,Form Name,Field Type,Field Label,Text Validation Type OR Show Slider Number,Field Annotation
record_id,scan_demo,text,Record ID,,
sd_fmt,scan_demo,text,Sample code,,"@UVALIDATE={""algorithm"":""none"",""pattern"":""SD[0-9]{4}""}"
sd_age,scan_demo,text,Age,integer,"@UVRANGE={""hard"":[0,120]}"
sd_phone,scan_demo,text,Phone,,@UVREQUIRED
sd_code,scan_demo,text,Enrolment code,,@UVUNIQUE
sd_visit,scan_demo,text,Visit date,date_ymd,"@UVWINDOW={""notFuture"":true}"
```

The upload must add one instrument and six fields, and the Online Designer must
show no configuration error on any of them.

### Project A data

Create two Data Access Groups, `north` and `south`, and put the account north in
`north`. Write the data file with this script and import it with the Data Import
Tool.

```python
import csv
with open('scan_live_data.csv', 'w', newline='') as f:
    w = csv.writer(f)
    w.writerow(['record_id', 'redcap_data_access_group', 'sd_fmt', 'sd_age',
                'sd_phone', 'sd_code', 'sd_visit'])
    for n in range(1, 5001):
        fmt = 'SD%04d' % n
        if n % 100 == 0: fmt = 'SD-BAD'
        if n == 3: fmt = 'SD<1>'
        if n == 4: fmt = '=1+1'
        w.writerow([n, 'north' if n <= 1000 else 'south', fmt,
                    150 if n % 250 == 0 else 40,
                    '' if n % 400 == 0 else '555-0100',
                    'C1' if n == 2 else 'C%d' % n,
                    '2099-01-01' if n % 500 == 0 else '2025-01-15'])
```

The import must report 5,000 records. If REDCap changes or refuses record 3's
`SD<1>` or record 4's `=1+1`, type that value on the form by hand. The records
hold exactly 96 violations:

| Rule | Records | Findings |
|---|---|---|
| `sd_fmt` format | every 100th, and records 3 and 4 | 52 |
| `sd_age` above 120 | every 250th | 20 |
| `sd_phone` blank | every 400th | 12 |
| `sd_visit` in the future | every 500th | 10 |
| `sd_code` duplicate | records 1 and 2 share `C1` | 2 |
| **Total** | | **96** |

In the group `north` (records 1 to 1,000) there are 12 + 4 + 2 + 2 + 2 = 22
findings, and in `south` 74.

### The module

| # | Action | Expect |
|---|---|---|
| S1 | Note the module version shown in Control Center → External Modules. | The version of this build. |
| S2 | As admin, open the module's system settings, tick `scan-system-enable-durable`, and save. | The save returns. The module log has a `scan-schema-migrate` entry with ok 1 and to 6. On a server that had no scan tables, from is 0; on one that ran a 2.3.0 release candidate, from is 5 and applied is 7. |
| S3 | In the database, list the tables whose names start with `uv_`. | Thirteen tables, the ones `docs/INSTALL.md` lists. |
| S4 | Open Project A's Validation scan page (left menu). | A notice that the scan is unavailable, and no Start button: the project setting is still off. |
| S5 | In Project A's module settings, tick `scan-enable-durable` and save. Reload the scan page. | The panel with a "Start a scan" button. |
| S6 | Untick `scan-system-enable-durable` and save. Reload the scan page. | The unavailable notice again. Tick it again and save; the panel comes back. |

### Upgrade from a 2.3.0 release candidate

Only on a server that ran 2.3.0-rc.1 or rc.2 before this build. Skip the section
otherwise.

| # | Action | Expect |
|---|---|---|
| U1 | Before deploying this build, start a scan on Project A and close the tab during "Checking records". | The run is left part done. |
| U2 | Deploy this build in a new version folder and upgrade the module in the Control Center. | The upgrade request returns. The module log has `scan-schema-migrate` with ok 1, from 5, to 6 and applied 7. |
| U3 | Open Project A's scan page and press Continue. | The run ends at once, as failed. The note starts "This module was upgraded while this scan was running" and ends "Start a new scan." |

---

## Section A: one scan, start to finish (Project A)

| # | Action | Expect |
|---|---|---|
| A1 | As designer, press "Start a scan" and keep the tab open. | The phase reads "Listing the records to check", with no percentage. |
| A2 | Watch the phase line until the run ends. | It moves through "Checking records", "Checking what changed while it ran", "Looking for duplicate values" and "Building the summary", to "Finished". No phase is skipped. |
| A3 | Watch the findings count while the phase is "Checking records". | It rises to 94 and no further, because the 2 duplicates are counted at the end. |
| A4 | Read the finished panel. | "5000 of 5000 records", 96 findings, "Every record was checked, including changes made while it ran.", "This result does not certify the project." and "Dates were judged as of" today's date. |
| A5 | Time the run, and one batch in the browser's network panel. | A `scan-work` request takes about three seconds. Note the total time; the mid-run steps in section D need a run of at least two minutes, so import more records if it took less. |
| A6 | Press "Start a scan" again and let it finish. | A second run finishes with 96 findings. |

## Section B: Stop

| # | Action | Expect |
|---|---|---|
| B1 | Start a scan. During "Checking records", press Stop. | The phase reads "Stopping", then "Finished". The note says "The scan was stopped." |
| B2 | Read the finished panel. | "Some records could not be checked. This is not a complete picture of the project." and "This result does not certify the project." |
| B3 | Press "Start a scan" at once. | A new run starts; nothing says the project is busy. Let it finish. |

## Section C: a second tab, and closing the tab

| # | Action | Expect |
|---|---|---|
| C1 | Start a scan in tab 1. Open the scan page in tab 2. | Tab 2 shows the run's phase and counts and a Continue button, and sends no `scan-work` request. |
| C2 | Press Continue in tab 2. | Both tabs now send `scan-work` requests. Either tab may say it is waiting for another worker; neither shows an error, and the run goes on. |
| C3 | Let the run finish. | It finishes once, with 96 findings, the same as a run driven from one tab. |
| C4 | Start a scan. During "Checking records", close the tab. Wait five minutes. Open the scan page. | "This scan is paused: nothing has worked on it for" some minutes, and that pressing Continue carries on from where it stopped. |
| C5 | Press Continue. | The record count carries on from where it was; it does not go back to zero. The run finishes with 96 findings. |

## Section D: records changed while a scan runs

Have record 100 and record 200 open in a second tab before you start.

| # | Action | Expect |
|---|---|---|
| D1 | Start a scan. Once the phase reads "Checking records", set record 100's `sd_fmt` to `SD0100` and save. | The record saves. |
| D2 | While the scan still runs, delete record 200. | The record is deleted. |
| D3 | While the scan still runs, create record 5001 with `sd_fmt` = `SD-BAD`, `sd_age` 40, `sd_phone` 555-0100, `sd_code` C5001, `sd_visit` 2025-01-15. | The record saves. |
| D4 | Let the run finish. | The phase "Checking what changed while it ran" appeared. The counts line says "1 deleted during the scan". 95 findings: record 100's and record 200's are gone and record 5001's is added. "Every record was checked, including changes made while it ran." |
| D5 | In the findings under the panel, look for records 100, 200 and 5001. | No `sd_fmt` finding on record 100, no finding at all on record 200, one `sd_fmt` finding on record 5001. |
| D6 | Restore the data: set record 100's `sd_fmt` to `SD-BAD`, delete record 5001, and import record 200 again from the data file. | The project holds 5,000 records and 96 violations again. |

## Section E: rights

| # | Action | Expect |
|---|---|---|
| E1 | As deid, open the scan page and press "Start a scan". | Refused. The message says the scan needs Full Data Set export rights and that your export level reads as De-Identified. |
| E2 | As deid, open the download link of a finished run (copy it from designer's session). | Plain text starting `# EXPORT REFUSED`; no file is saved. |
| E3 | As designer, start a scan. While it runs, as admin, remove designer's access to `scan_demo` in User Rights. | Designer's next batch is refused, and the run does not carry on for them. Restore the access. |

## Section F: a Data Access Group user (Project A)

| # | Action | Expect |
|---|---|---|
| F1 | As designer, start a scan and leave it running. As north, open the scan page and press "Start a scan". | Refused with "a validation scan is already running for this project. Try again when it has finished." North is shown no Continue button for designer's run. |
| F2 | Let designer's run finish. As north, press "Start a scan". | The page says "Your scope: records in your Data Access Group only." While the run works, the counts line says "This scan covers one Data Access Group, so its counts are shown once it has confirmed which records belong to that group." |
| F3 | Let the run finish. | 1,000 records, 20 findings, and "1 rule could not be evaluated, so this result does not certify the project.": `sd_code` is unique across the whole project, which a group scan cannot see, so its 2 duplicates are not counted. |
| F4 | As north, page through the findings under the panel and open the download. | No record above 1000 appears, on the page or in the file. The file's `# scope` line names the group `north`. |
| F5 | As north, start a scan and press Stop during "Checking records". | "This scan was stopped before it confirmed which records belong to this Data Access Group, so it is not a complete picture of the group." No findings table and no download link. |

## Section G: the findings page (Project A)

The page shows, under the panel, the findings of the newest finished run in
your own scope, once no run of yours is going. Earlier runs are not listed.
Before this section, as designer, run a scan to the end (96 findings) and do
not start another.

| # | Action | Expect |
|---|---|---|
| G1 | Open the scan page. | "Findings of the last finished scan", "This report holds 96 findings.", and a table captioned "Findings of this scan, page 1" with 50 rows. The columns include Record, Data Access Group, Instrument, Field, Value and What is wrong; there is no Event column. |
| G2 | Press "Next page", then "Previous page". | Page 2 holds the other 46 rows, and "Next page" then does nothing. "Previous page" shows the same 50 rows as the first page. |
| G3 | Open the Reason filter. | "All", then five reasons with counts 52, 20, 12, 10 and 2. Choosing one shows only its rows, from page 1. |
| G4 | Open the Check filter, then the Data Access Group filter. | Five checks with the same counts as G3. "All", `north (22)` and `south (74)`. |
| G5 | Open the Instrument filter. | One instrument, the `scan_demo` form's name, with 96. |
| G6 | Set Reason to the duplicate reason and Data Access Group to `south`. | "No findings match these filters." and no row: both duplicates are in `north`. Set both back to "All". |
| G7 | Use only the keyboard (Tab, Enter, Space) to move pages and set a filter. | Every control is reachable and works. |
| G8 | Start a scan and let it finish. Watch the area under the panel. | The new run's findings open by themselves when the phase reads "Finished", with no reload. |

## Section H: the CSV file (Project A)

| # | Action | Expect |
|---|---|---|
| H1 | Under the findings of a complete run, press "Download these findings as a CSV file". | A file named `uv-scan-<pid>-run<id>-<date>_NOT-CERTIFIED.csv`. |
| H2 | Open it in a text editor. | The first bytes are a byte-order mark and a line starting `# schema`. Then `# project`, `# run`, `# generated_at`, `# scope` (whole project), `# coverage`, `# certified` (no), `# judged_as_of` (the run's frozen day, or `not applicable` when no rule reads the clock), `# values`, `# labels`, `# columns` and `# expected_rows` (96), a header row of column keys, 96 rows, and last `# export_complete=1` with rows=96. |
| H3 | Open it in Excel. | Columns, not one cell of HTML. |
| H4 | Start a scan and press Stop during "Checking records". When it reads "Finished", look under the panel. | The stopped run's findings, with the banner "INCOMPLETE SCAN - this report does NOT certify the project as clean" above the table, a row below it starting "This scan did not cover the whole project.", and beside the download "Its file name ends in _INCOMPLETE_CANCELLED, so the file says it is incomplete wherever it is saved." |
| H5 | Download that run. | The name ends `_INCOMPLETE_CANCELLED.csv`. The first line, before `# schema`, starts `# INCOMPLETE SCAN - this report does NOT certify the project as clean`. A row whose first cell is `INCOMPLETE SCAN` comes after the findings and before `# export_complete=1`. |

## Section I: values and retention (Project A)

This build has no screen that previews what retention will remove. The steps
check retention by its effects.

| # | Action | Expect |
|---|---|---|
| I1 | With `scan-value-storage` at its default, run a scan to the end and look at its Value column. | The column is there and shows no value. |
| I2 | Set `scan-value-storage` to show every value. Run a scan. | The Value column shows values: `SD-BAD`, `150`, the future dates. |
| I3 | Find record 3 and record 4 on the page. | Record 3 shows the text `SD<1>`, with the angle brackets visible. Record 4 shows `=1+1`. |
| I4 | Download the run and open it in Excel. | Record 4's value cell reads `'=1+1` as text and computes nothing. |
| I5 | Set `scan-value-storage` back to locations only, and save. Reload the scan page, which still shows the I2 run. | No value is shown, before any cron has run. |
| I6 | Wait for the next `uv_scan_expire_values` pass (section J). | Its `scan-cron` log entry (what: retention) has runs_revoked at least 1 and previews_revoked above 0. |
| I7 | Set `scan-value-storage` to show every value again. Reload the scan page. | The values do not come back. |
| I8 | (Next day.) Set Project A's `scan-run-retention-days` to 1, then wait for a retention pass more than a day after a run finished. | The pass logs runs_purged at least 1, and that run can no longer be opened. |

## Section J: the crons

Each cron runs when REDCap's cron runner finds it due.

- `uv_scan_reap_abandoned` runs every 15 minutes.
- `uv_scan_expire_values` runs every hour.

To see a pass, wait for it, or have the server administrator run REDCap's
`cron.php` once the cron is due. The module log shows each pass as a
`scan-cron` entry.

| # | Action | Expect |
|---|---|---|
| J1 | As admin, open the Control Center's cron jobs page. | Both module crons are listed with their last run. If this REDCap build does not list module crons there, note it and use the module log instead. |
| J2 | Wait for a `uv_scan_reap_abandoned` pass. | A `scan-cron` entry with what: reap, failed 0, and the counts runs_expired, runs_stopped, slots_removed and superseded. After A6, superseded is above 0, because the second run closed the first run's findings. |
| J3 | Set `scan-system-stale-run-hours` to 1. Start a scan, close its tab, and wait over an hour for a reap pass. | The pass logs runs_expired 1. The scan page offers Start, and the run reads as expired. Set the setting back to 24. |
| J4 | Repeat J3, and press "Start a scan" instead of waiting for the pass. | The abandoned run is ended as expired at once and the new run starts. |

## Section K: Project B (longitudinal and repeating)

| # | Action | Expect |
|---|---|---|
| K1 | Turn `scan-enable-durable` on in Project B. As designer, run a scan to the end. | The same phases as A2. The run finishes. |
| K2 | Open the findings. | There is an Event column, and every row names its event. A finding on a repeating instrument or event names its instance. The Instrument column is filled on every row. |
| K3 | If the project has an `@UVWINDOW` rule, read the finished panel and the file's header. | "Dates were judged as of" the day the run was planned, on the panel and in the file. |
| K4 | Download the file. | It opens as columns, has the Event column, and ends `# export_complete=1`. |

## Section L: switching the module off

Do the system-level steps on a test server only. They switch the module off for
every project.

| # | Action | Expect |
|---|---|---|
| L1 | Start a scan on Project A and close the tab during "Checking records". In Project A, disable the module (External Modules). | The disable returns. The module log has `scan-project-disable` with runs_stopped 1. |
| L2 | Enable the module in Project A again. Open the scan page. | No run is active; Start works at once, and the run finishes with 96 findings. |
| L3 | Leave `scan-system-drop-tables-on-disable` off. Start a scan on Project A and close its tab. As admin, disable the module in the Control Center. | The module log has `scan-disable` with runs_stopped 1 and failed 0. The thirteen `uv_` tables are still there. |
| L4 | Enable the module again in the Control Center, and in Project A if REDCap asks. Open the scan page. | No run is active, Start works at once, and the earlier runs' findings can still be opened. |
| L5 | (Optional, on a test server only.) Tick `scan-system-drop-tables-on-disable`, disable the module, then enable it with `scan-system-enable-durable` still on. | After the disable, the module log has `scan-disable-dropped` and no `uv_` table is left. After the enable, the thirteen tables exist again, empty. |

## Afterwards

Record the module version, the REDCap version, the server's MySQL or MariaDB
version, the time of the A1 run and of one batch, and every step that failed,
with what the page or the log said.
