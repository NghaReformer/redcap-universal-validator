# Live test results: @UVDELTA on chpr-redcap.org pid 149, v2.4.0

Date: 2026-10-30. Server: REDCap 17.0.6, module folder `universal_validator_v2.4.0`
(tag `v2.4.0`, commit c5220d3). Browser: the Claude desktop app's built-in pane, signed in
as ngha.ndze (administrator). Run sheet: [`docs/testbed/DELTA_LIVE_ACCEPTANCE.md`](../../docs/testbed/DELTA_LIVE_ACCEPTANCE.md),
fixtures `uvdelta_test_fields.csv` and `uvdelta_test_fields_xr_specimen.csv`.

Project settings during the run: "Enable event and instance references" off (pid 149
refuses it while the `xn_*` rows are in the dictionary, and the project owner chose to keep
them), `log-values` = hashed, durable scan on for the server and for pid 149.

Fixes: every defect in this report is fixed in commit 7934dd6 on branch `fix/live-v240` (not
pushed or deployed yet, 2026-10-31); see "Unreleased" in `CHANGELOG.md`. A live re-test is owed.

## Verdict

One defect: D1, a slider rule never asks or blocks on save (A10). Every other step that
could run passed with the sheet's wording. Sections C and D and the references-on half of
Section F were skipped, because they need event and instance references.

## Results

| # | Result | Seen |
|---|---|---|
| A1 | pass | No note under `ud_w2` |
| A2 | pass | The note as written; Save blocked, the dialog names "Second weighing" |
| A3 | pass | The note goes when `ud_w1` = 70.5 |
| A4 | pass | The note as written; Save asks "save anyway?"; cancelled, then `ud_sbp2` = 125 |
| A5 | pass | The note as written; Save blocked |
| A6 | pass | No note under `ud_ht`; `ud_htcalc` shows 152 and its note |
| A7 | pass | The note as written |
| A8 | pass | No note; no note; the "rose by 3 from 0" note |
| A9 | pass | The comma-decimal note, then no note |
| A10 | **fail (D1)** | The live note is right: "✗ This value rose by 5 (250%) from 2 ([ud_pain0]). Allowed: a change of at most 3." Save neither asked nor blocked, and the record saved with `ud_pain` = 7. A second try at 8 saved the same way |
| A11 | pass | One line, as written; saved after "save anyway" |
| A11b | pass | Two lines; Save blocked |
| A12 | pass | The note as written; Save blocked |
| A12b | pass | The note goes; the record saves |
| A13 | pass | Saved after "save anyway" |
| A14 | pass, with extra entries | Every entry the sheet lists, at event 380, instance 1, and the `uvalidate-unconfigurable` entries of A6. Extra: 45869 and 45870, `type: delta` for `ud_pain`, written by the two A10 saves that D1 let through. Entries carry `value_hmac`, not `value`, because this server keeps no raw values |
| B1 | pass | The note as written, ending "it is re-checked after the save." |
| B2 | pass | Saved after "save anyway" for `ud_sbp2`; one new `ud_snap` `up` `[xv_weight]` entry |
| B3 | pass | No entry on either save |
| C0 | pass | The references-off configuration errors as written |
| C1 to C5 | skipped | Need event and instance references |
| D1 to D3 | skipped | Need event and instance references |
| E1 | pass, references off | Scan run 1: 23 findings in the project. The `@UVDELTA` findings are `ud_sbp2`, `ud_htcalc` and `ud_snap`, the three the references-off steps leave. `ud_two` has none, because A11b cleared it |
| E2 | pass | `ud_sbp2`: "The two readings differ by more than 20 mmHg. Take a third. Allowed a change of at most 20 mmHg from [ud_sbp1]." `ud_height` has no finding with references off |
| E3 | pass, wording differs | The `ud_bad_*` fields never appear as data. The durable scan does not list rule problems by name: the panel says "127 rules could not be evaluated, so this result does not certify the project." The sheet's wording fits the legacy scan |
| F | pass, references off | All six errors under their fields, each starting `@UVDELTA on "<field>": `, none blocking |

The CSV download of run 1 (`pages/export`, POST) returned 200 with the file name
`uv-scan-149-run1-20261030_NOT-CERTIFIED.csv` and the closing line `# export_complete=1`,
`rows=23`.

## Defects

### D1 (Medium): a rule on a slider never asks or blocks on save

REDCap 17.0.6 draws the slider's number box as `<input class="sldrnum" readonly="readonly">`.
`QRID_registerBlocker` in `js/engine.js` skips any input that `QRID_fieldLocked` reports
as locked, and a `readonly` input counts as locked (the UX-003 rule for read-only fields).
The slider's rule therefore shows its live note but is never registered as a save blocker.
Seen on `ud_pain` (A10): saved at 7 and then at 8, with no prompt.

Not fixed in this run. A fix would tell a REDCap slider box (`.sldrnum` beside
`#slider-<field>`) apart from a field the project made read-only.

### D2 (Low): "Data Access Groups could not be read" on a project with no groups

pid 149 has no Data Access Groups. `REDCap::getGroupNames(true)` returns a non-array there,
and `php/ScanDimensions.php` (about line 222) reports it as a read failure. Every scan
report on pid 149, and on the JUSTIFY H3 scratch project (pid 156, no groups either),
says "Data Access Groups could not be read, so the DAG column is omitted — this is not
evidence the project has no groups". The CSV head repeats it in `# labels`.

## Left in the project

The `uv_delta_test` and `xr_specimen` instruments, their designations and XE-1's data on
them stay in pid 149, as the project owner asked.
