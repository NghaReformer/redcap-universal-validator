# Live test results: durable Validation scan on chpr-redcap.org, v2.4.0

Date: 2026-10-30, 17:19 to 18:45 UTC. Server: REDCap 17.0.6, module folder
`universal_validator_v2.4.0` (tag `v2.4.0`, commit c5220d3). Browser: the Claude desktop
app's built-in pane. Run sheet:
[`docs/testbed/scan_live_run_sheet.md`](../../docs/testbed/scan_live_run_sheet.md).

Accounts: only ngha.ndze (REDCap administrator, full rights) was used, so the steps that
need designer, north and deid as separate accounts are listed under "Left for the project
owner". The administrator account stood in for designer in every step marked "as
designer".

Projects:

- **Project A** is pid 157, "UV SCAN DEMO Project A (live run sheet)": classic, the
  `scan_demo` dictionary, groups `north` and `south`, the 5,000 records of the sheet's
  script. Record 5001 is still in it (see D3 below), with valid values, so the project holds
  5,001 records and 96 violations.
- **Project B** is pid 149. Its scans are reported in `LIVE_TEST_v2.4.0_delta.md` and
  `LIVE_TEST_v2.4.0_justify.md`.

Fixes: every defect in this report is fixed on branch `fix/live-v240` (not committed or
deployed yet, 2026-10-30); see "Unreleased" in `CHANGELOG.md`. A live re-test is owed.

## Verdict

The scan's results were right in every run that read the whole record list: 96 findings
with the sheet's split by reason, check and group, the right counts after changes made
during a run, and the right coverage lines. Three defects were found:

- **S-1 (High): the scan trusts REDCap's record list cache and then reports full
  coverage.** The first scan of Project A read 1,347 of 5,000 records and said "Every record
  was checked, including changes made while it ran."
- **S-2 (Medium): Stop stalls at "Stopping"** until Continue is pressed, and the note "The
  scan was stopped." never shows.
- **S-3 (Low): the page shows only two phases.** "Listing the records to check",
  "Checking what changed while it ran", "Looking for duplicate values" and "Building the
  summary" never appeared.

D2 of the DELTA report ("Data Access Groups could not be read" on a project with no groups)
also shows on pid 149 and pid 156. It does not show on Project A, which has groups.

## Setup

| # | Result | Seen |
|---|---|---|
| Dictionary | pass, sheet error | REDCap 17.0.6 refused the sheet's six-column dictionary: "The only valid field types are …", because it reads columns by position. The same six fields in the full 18-column layout loaded with no error or warning, and the Online Designer shows no configuration error |
| Data | pass | The 5,000-row file of the sheet's script, imported as a background import (a real-time import of 5,000 rows did not finish in the browser): 5,000 provided, 5,000 imported, 0 errors, 5 minutes. Records 3 and 4 kept `SD<1>` and `=1+1` |
| S1 | pass | v2.4.0 in External Modules |
| S2 | pass, sheet out of date | Log 45895: `scan-schema-migrate` ok 1, from 0, to 8, applied 54. The sheet says "to 6"; this build's schema is 8. Log 45896: `scan-slots-provision` added 2, limit 2 |
| S3 | not run | Needs database access |
| S4 | pass | "⚠ Scan unavailable … Why: the durable validation scan is not enabled here.", no Start button |
| S5 | pass | The panel with "Start a scan" |
| S6 | not run | Switching the server setting off and on was refused in this session as a change to a shared resource. It is the owner's to run |
| U1 to U3 | not run | This server had no 2.3.0 release candidate deployed |

## Section A

| # | Result | Seen |
|---|---|---|
| A1 | **fail (S-3)** | For the first 5 to 6 seconds after Start the phase line kept the previous run's "Finished" (or was blank on a fresh page). "Listing the records to check" never showed |
| A2 | **fail (S-3)** | Phases seen, sampled every 150 to 250 ms over five runs: only "Checking records" and "Finished" |
| A3 | pass in substance | The count rose 15, 28, 45, 58, 73, 89 and then showed 96 at "Finished". The step from 89 to 96 came in one update, so the stop at 94 before the duplicates could not be seen |
| A4 | pass | "5000 of 5000 records (100%)", "96 findings so far", "Every record was checked, including changes made while it ran. This result does not certify the project. Dates were judged as of 2026-10-30." |
| A5 | pass | Seven work requests of 3.1 to 5.6 s, 775 records each; the run took 31 s |
| A6 | pass | A second run: 96 findings |

Before A1, the first scan of Project A gave the result that is defect S-1.

## Section B

| # | Result | Seen |
|---|---|---|
| B1 | **fail (S-2)** | "Stopping" appeared after the batch in flight (4 to 11 s after the click), and then nothing more: "This scan was asked to stop and has not finished stopping. Press Continue to finish stopping it." After 25 s Continue was pressed and the run read "Finished". The note "The scan was stopped." did not appear, before or after a reload. Seen twice (runs 12 and 18) |
| B2 | pass | "Some records could not be checked. This is not a complete picture of the project." and "This result does not certify the project." |
| B3 | pass | A new run started at once and finished with 96 findings |

## Section C

| # | Result | Seen |
|---|---|---|
| C1 | pass | Tab 2 showed "Checking records", "1925 of 5000 records (38%)", Continue and Stop |
| C2 | pass | After Continue in tab 2 both tabs worked on the run. Neither showed an error or a waiting message |
| C3 | pass | One finish, 96 findings |
| C4 | pass | Tab closed at 775 of 5000 (18:13:28 UTC). After 5 minutes: "This scan is paused: nothing has worked on it for 5 minutes, which is what happens when the page that was running it is closed. Nothing has been lost. Press Continue to carry on from where it stopped." |
| C5 | pass | The count went on from 1550 to 5000; 96 findings |

## Section D

| # | Result | Seen |
|---|---|---|
| D1 | pass | Record 100 saved with `SD0100` during "Checking records" |
| D2 | not run | Deleting record 200 is a permanent deletion; left for the owner |
| D3 | pass | Record 5001 saved (`SD-BAD`, 40, 555-0100, C5001, 2025-01-15) |
| D4 | pass, without D2 | "5001 of 5001 records (100%)", 96 findings: record 100's finding gone, record 5001's added. "Checking what changed while it ran" was not seen (S-3). With no deletion there was no "1 deleted during the scan" line |
| D5 | pass | No row for record 100; one `sd_fmt` row for record 5001 (CSV of run 16) |
| D6 | pass, changed | Record 100 back to `SD-BAD`. Record 5001 was set to `SD5001` instead of being deleted |

Saving two data-entry forms from two tabs of the same browser hit REDCap's "Multiple
tabs/windows open!" CSRF refusal once; the save was repeated from a fresh page.

## Section E

| # | Result | Seen |
|---|---|---|
| E1 | not run | Needs the deid account |
| E2 | pass as designer | A GET of `pages%2Fexport&run_id=16`: HTTP 405, `text/plain`, starting `# EXPORT REFUSED`, "the file is sent only in answer to the download button on the scan page", no `Content-Disposition`. The deid half needs the deid account |
| E3 | not run | Needs designer and admin as separate accounts |

## Section F

Not run: needs the north account.

## Section G

| # | Result | Seen |
|---|---|---|
| G1 | pass | "This report holds 96 findings.", caption "Findings of this scan, page 1", 50 rows. Columns: Issue, Record, Data Access Group, Instrument, Instance, Field, Field label, Value, Check, Reason code, Rule, Rule name, What is wrong, Wording from. No Event column |
| G2 | pass | Page 2 holds 46 rows; Next again leaves page 2 as it is; Previous gives the same 50 rows |
| G3 | pass | duplicate-value (2), format (52), future (10), hard-high (20), required-blank (12). "hard-high" shows 20 `sd_age` rows, from page 1 |
| G4 | pass | Checks range (20), required (12), single (52), unique (2), window (10). Groups north (22), south (74) |
| G5 | pass | "Scan Demo (96)" |
| G6 | pass | "No findings match these filters." and no row; with `north`, records 1 and 2 (`sd_code`) |
| G7 | pass | Tab order: Start, the four filters, Previous, Next, the download. Enter on Next opened page 2; Down on the Reason filter chose duplicate-value and showed its 2 rows |
| G8 | pass | The new run's report opened at "Finished" with no reload |
| G9 | pass | After a reload: "Scan 12 started after this one and did not complete, so this report is still the current one." and "Show what scan 12 found"; for run 18 the button "Show what scan 18 found" opened its 28 findings with the INCOMPLETE banner, and "Show the current report" went back to 96 |
| G10 | not run | Needs the north account |

## Section H

| # | Result | Seen |
|---|---|---|
| H1 | pass | With the Reason filter on format, "Download the whole report as a CSV file (96 findings)" gave `uv-scan-157-run17-20261030_NOT-CERTIFIED.csv` with 96 rows. The POST carried `run_id` and `redcap_csrf_token` only |
| H2 | pass | First bytes EF BB BF, then `# schema`. The head lines in the sheet's order; `# judged_as_of` 2026-10-30, `# values` locations, `# labels` "as the project was when this scan was planned", `# expected_rows` 96; the header row; 96 rows; last `# export_complete=1`, `rows=96` |
| H3 | not run | The file was read in the page, not saved and opened in Excel |
| H4 | pass | "INCOMPLETE SCAN - this report does NOT certify the project as clean", "This scan did not cover the whole project. …", and "Its file name ends in _INCOMPLETE_CANCELLED, so the file says it is incomplete wherever it is saved." |
| H5 | pass | `uv-scan-157-run18-20261030_INCOMPLETE_CANCELLED.csv`. First line `# INCOMPLETE SCAN - this report does NOT certify the project as clean`; an `INCOMPLETE SCAN` row after the 28 findings and before `# export_complete=1`, `rows=28` |

## Section I

| # | Result | Seen |
|---|---|---|
| I1 | pass | Value column present; every cell "[not kept by this scan]" |
| I2 | pass | With "Show every value" (run 19): `SD-BAD`, `150`, `2099-01-01` |
| I3 | pass | Record 3 shows `SD<1>` (the cell's HTML is `SD&lt;1&gt;`). Record 4 is not on page 1; the file shows it |
| I4 | pass | In the file record 4's value is `'=1+1` |
| I5 | pass | Back to locations only: every value cell reads "[withheld by policy]" (blank where the value was blank), before any cron |
| I6 | pass | Log 45946 at 18:36:13: `scan-cron`, what retention, runs_revoked 11, previews_revoked 82, failed 0 |
| I7 | pass | With "Show every value" set again, the I2 run's value cells read "[no longer kept]". The setting was then put back to locations only |
| I8 | not run | Next day |

## Section J

| # | Result | Seen |
|---|---|---|
| J1 | REDCap differs | REDCap 17.0.6's Cron Jobs page does not list module crons. The module log was used |
| J2 | pass | `scan-cron` `what: reap` every 15 minutes, failed 0, with runs_expired, runs_stopped, slots_removed and superseded. superseded was above 0 in the passes after repeated runs: 187 (17:51), 1 (18:06), 521 (18:21, after the Project A runs) |
| J3, J4 | not run | Need `scan-system-stale-run-hours` changed, a server setting |

## Defects

### S-1 (High): a stale REDCap record list makes the scan report full coverage of part of the project

The first scan of Project A ran while REDCap's Record List Cache was incomplete: the
background import had finished, but the cache held only records 3654 to 5000. REDCap's own
Record Status Dashboard said "of 1,347 records". The scan listed the same 1,347 records,
found 26 violations, and its panel and CSV said "Every record was checked, including changes
made while it ran." Of the 96 violations, 70 were missed under a full-coverage line. After
"Clear all record & page caches" (Project Setup, Other Functionality) the dashboard showed
5,000 and every later scan read 5,000.

The cache is REDCap's, but the claim is the module's. The opening list should come from the
data table itself, or the scan should check the list against a count from the data table
and say the coverage is unknown when they differ.

### S-2 (Medium): Stop stalls at "Stopping"

After Stop, the page finishes the batch in flight, shows "Stopping", and then sends no work
request. The run stays at "Stopping" with "Press Continue to finish stopping it." until
someone presses Continue. If the tab is closed, the run stays that way until the reap cron.
The note "The scan was stopped." from B1 never appears.

### S-3 (Low): the phase line shows only "Checking records" and "Finished"

Five runs were sampled every 150 to 250 ms. The listing phase never showed; the panel kept
the last run's "Finished" (or a blank) until the first batch came back. The phases after the
last batch were never on screen, so the findings count jumped from 89 to 96 in one update.
Either the server does them inside one request, or the page does not draw them.

## Left for the project owner

- S3: list the `uv_` tables in the database (13 expected).
- S6, J3 and J4: change the server settings `scan-system-enable-durable` and
  `scan-system-stale-run-hours`.
- E1, E2 (deid half), E3, F1 to F5 and G10: need the deid, north and designer accounts
  of the sheet. Put north in the `north` group of pid 157.
- D2 and D6: delete record 200 (and record 5001) and re-import record 200, if you want the
  deletion path tested.
- H3: open a downloaded file in Excel.
- I8: the next-day retention step.
- pid 157 can be deleted when the run is finished.
