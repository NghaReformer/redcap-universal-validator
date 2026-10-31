# Live test results: @UVJUSTIFY on chpr-redcap.org pid 149, v2.4.0

Date: 2026-10-30, about 16:30 to 18:00 server time. Server: REDCap 17.0.6, module folder
`universal_validator_v2.4.0` (tag `v2.4.0`, commit c5220d3). Browser: the Claude desktop
app's built-in pane, signed in as ngha.ndze (administrator). Run sheet:
[`docs/testbed/JUSTIFY_LIVE_ACCEPTANCE.md`](../../docs/testbed/JUSTIFY_LIVE_ACCEPTANCE.md),
fixture `uvjustify_test_fields.csv`.

Setup: `uv_justify_test` designated at `visit_1_arm_1` (event 380) and enabled as a
survey with one page per section. Missing Data Code `UNK, Unknown` added (Setup 4).
Durable scan on for the server and for pid 149. Event and instance references off.
`log-values` = hashed, so log entries carry `value_hmac` and never `value`.

H0 baseline before the JUSTIFY steps: R0 = 127 rules that could not be evaluated, and no
finding on `uv_justify_test`. The whole project then held 23 findings, from the DELTA run
and the older fixtures, so F0 = 23 for the counts of Section H.

Fixes: every defect in this report is fixed on branch `fix/live-v240` (not committed or
deployed yet, 2026-10-30); see "Unreleased" in `CHANGELOG.md`. A live re-test is owed.

## Verdict

Two new defects, D3 (Low) and D4 (High), and one difference in REDCap's behaviour (J2).
Every other step passed with the sheet's wording, apart from the sheet errors listed
below.

## Results

| # | Result | Seen |
|---|---|---|
| A1 | pass | The six `uj_bad_*` errors and `uj_rr`'s. Sheet error: `uj_bad_form`'s error names the form `id_validation_test`, where `record_id` lives, not `xe_enrol` |
| A2 | pass | 17 reason pairs (the sheet's list leaves out `uj_emb`); `missingCodes` = `["UNK"]` |
| A3 | pass | |
| B1 to B9b | pass | Every note and "Cannot save yet" line word for word |
| C1 to C8c | pass | J7 below. At C6 REDCap asked "ERASE THE VALUES OF FIELDS TO BE HIDDEN?" (Keep All / Erase); Erase was chosen |
| D1 to D4 | pass | |
| E1 | pass, with defect D4 | Held: "• Weight (or give a reason in “Reason for change”)", nothing saved. The same Enter press also set `uj_anchor` to today with no message (defect D4) |
| E2 | pass | The record saves. It saved the changed `uj_anchor` too, so `uj_visit` fell out of its window and entry 45924 (`window-early`) was written |
| F1 to F6 | pass | |
| G1 | pass | Page 1 shows section A only. `uj_rr` shows the general line. Next held: "• Required field (or give a reason in “Why it is blank”)" |
| G1b | pass | Held: "• Weight (or give a reason in “Reason for change”)" |
| G1c | pass | Page 2 opens. Entry 45933, `uvalidate-justified` for `uj_wt` (XE-4, event 380), `justified_by` `uj_wt_why`, no reason text |
| G2 | pass | Page 3 opens, no new entry |
| G3 | pass | "✗ This value is above the plausible range (allowed 0 to 24)." and no reason note. Held: "• Hours fasted" |
| G4 | pass | Pages 4 and 5 open, no entry |
| G5 | pass | Page 5 shows the six general lines. The survey closes. Entry 45934, `uvalidate-justified` for `uj_req` |
| H1 | pass | "24 findings so far, and 17 saved with a reason" (F0 + 1) and "127 rules could not be evaluated, so this result does not certify the project." The new finding is XE-1's `uj_lock` write-once change |
| H1b | pass | "This report holds 41 findings, 17 of them saved with a reason." The 17 rows the sheet names read "Yes"; `uj_lock`'s write-once row is blank. CSV (run 2): `justified` column, `# expected_rows` 41, `# justified_rows` 17 |
| H2 | pass | "25 findings so far, and 16 saved with a reason" |
| H2b | pass | The counts of H2. REDCap kept the spaces: the next import's table showed the old value as "( )", not blank |
| H2c | pass | "24 findings so far, and 17 saved with a reason" |
| H3 | pass | Scratch project pid 156. The record saved once the reason was typed. "Nothing found yet, and 1 saved with a reason", "Every record was checked, including changes made while it ran.", no line about certifying. "This report holds 1 finding, 1 of them saved with a reason." |
| H3b | pass | "1 finding so far" and "This result does not certify the project." The scratch project is not deleted (see below) |
| J1 | pass | The box holds `UNK` |
| J2 | **REDCap differs** | After the import of `uj_hr_show` = 0, REDCap 17.0.6 does not hide "Why the heart rate is right" on page load, because the field has a saved value. The row stays visible with its text, so the page counts the reason and the record saved with no hold. Calling REDCap's `doBranching()` by hand opens its "ERASE THE VALUES OF FIELDS TO BE HIDDEN?" dialog; it was closed without a choice. The scan kept the counts of H1 (24 / 17), so page and scan agree. The import of 1 restored the fixture |
| J3 | pass | After "reset": the note is back and Save is held with "• Required field (or give a reason in “Why it is blank”)". With Not done again the record saves |
| J4 | pass | `uj_sql_why` added in the Online Designer. `uj_rr`'s error is gone and the six `uj_bad_*` errors stay. Note and hold as written |
| J4b | pass | Entry 45935: `uj_rr`, type `range`, reason `hard-high`, `justified_by` `uj_sql_why`. `uj_sql_why` was not deleted (see below) |
| J5 | pass | No entry at the imports of C7, F6, H2, H2b, H2c and J2 |
| J6 | pass | `document.getElementsByName('uj_far_why').length` = 0 on survey page 3 |
| J7 | pass | `uj_emb_box-tr` |

Log entries: 45899 to 45935, as the sheet expects, apart from 45924 (`uj_visit`
`window-early`), which defect D4's silent date change caused at E2.

## Defects

### D3 (Low): the reason label includes the Missing Data Code menu

Once the "M" menu of a reason field has been opened, the label the engine reads for the
reason field includes the menu text, because REDCap puts the menu in the label's
`rc-field-icons` cell. Seen in the note and in the alert:
"“Reason for change Mark field as:[Clear value]Unknown (UNK)”". The label should be read
without the `rc-field-icons` cell.

### D4 (High): Enter in a text field silently changes a date while the module holds the save

With the save held by the module, pressing Enter in a text field does not stop the
browser's implicit form submission (the keydown has `defaultPrevented` = false). The
browser then presses the form's first submit-type button. On `uv_justify_test` that is
REDCap's "Today" button beside `uj_anchor`,
`<button onclick="setToday('uj_anchor','date_ymd');return false;">`, which has no `type`
and so counts as a submit button. The date is set to today with no message and focus
moves to it. Reproduced live. XE-1's `uj_anchor` was put back to 2026-10-01 and saved.

`QRID_guardDataEntrySubmit` in `js/engine.js` (about line 4273) wraps
`window.dataEntrySubmit`, which REDCap's own Enter handler calls. The implicit submission
reaches the "Today" button before that. A fix would cancel Enter's default action in the
form's text inputs while the guard holds the save, or ignore clicks on
`button:not([type])` that the browser synthesises from Enter.

D1 (slider rules never block) and D2 ("Data Access Groups could not be read" on a project
with no groups) are described in `LIVE_TEST_v2.4.0_delta.md`; D2 shows on every scan here.

## Wording seen in the scan but not in the sheet

The two rows whose wording comes from a rule's own message (`uj_dia` and `uj_off`,
"Wording from" = `rule-message`) do not end with "It was saved with a reason, given in
[…]." The catalog rows do. Their "Saved with a reason" cell reads "Yes" all the same.

## Left in place for the project owner

The project owner asked to keep the test instruments and data. These are also still there
and are the owner's to remove:

- `uj_sql_why` on `uv_justify_test` (J4b says to delete it)
- the Missing Data Code `UNK` (Setup 4)
- the scratch project pid 156, "UV JUSTIFY SCRATCH H3 (delete after test)" (H3b says to
  delete it)
