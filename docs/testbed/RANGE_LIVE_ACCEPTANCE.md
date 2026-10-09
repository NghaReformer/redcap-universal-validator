# @UVRANGE live acceptance protocol

Live test of plausibility limits on a real REDCap. The automated suites
(`tests/range_php.php`, `tests/range_js.cjs`, `tests/range_dom_js.cjs`,
`tests/range_module_php.php`) prove the logic against stubs. This run checks
real number validation, real comma-decimal fields, a real calc and slider, the
browser's save dialog, the module log and the Validation scan.

Target: chpr-redcap.org pid 149 (longitudinal, repeating, seed records XE-1 to
XE-4). Fixture: [`uvrange_test_fields.csv`](uvrange_test_fields.csv), 19 fields
on one new instrument `uv_range_test`: 12 rule fields carry a valid
`@UVRANGE`, 6 `ur_bad_*` fields carry a broken one, and `ur_sex` and `ur_ht`
carry no tag. Before this sheet was written, every annotation and every saved
value below was run offline through the module against the dictionary and the
seed data in `event_instance/`: the 12 rule fields configure, the 6 `ur_bad_*`
fields show the errors in section F, and each audit and scan "Expect" below is
what the module answered.

---

## Setup

1. Deploy the release that contains `@UVRANGE` and confirm the version in
   Control Center.
2. Append, never replace. Download the current dictionary, append the 19 rows
   of the CSV, upload. The diff must report one new instrument and 19 new
   fields, and nothing else.
3. Designate `uv_range_test` on `event_1_arm_1` (event 351).
4. Form URL: `DataEntry/index.php?pid=149&id=XE-1&event_id=351&page=uv_range_test`.
   The seed sets `xe_site` (form `xe_enrol`, same event) to 1 for every record.

Read the injected config at any point with:

```js
JSON.parse(document.getElementById('inspire-validator-config').textContent)
```

A "note" below is the message under the field. Amber starts with ⚠, red with ✗.
"Leave the field" means Tab out of it or click elsewhere.

---

## Section A: notes and blocks

| # | Action | Expect |
|---|---|---|
| A1 | `ur_sbp`: type 150, but do not leave the field | No note while typing |
| A2 | Leave the field | Amber "This value is higher than usual (expected at most 140 mmHg)." |
| A3 | Click Save and stay | The record saves with no question (`softBlock` off) |
| A4 | `ur_sbp` = 30, leave | Red "This value is below the plausible range (allowed 40 to 250 mmHg)." Save is blocked and the dialog names "Systolic pressure" |
| A5 | `ur_sbp` = 120, leave | The note goes away; Save works |
| A6 | `ur_wt` = 160, leave, Save | Amber note "expected 30 to 150 kg"; Save asks "save anyway?" first |
| A7 | `ur_wt` = 400, leave, Save | Red note "allowed 2 to 300 kg"; Save asks instead of blocking (`hardBlock` confirm). Cancel, then set 70 |
| A8 | `ur_free` = n/a, leave | Red "This is not a number."; Save is blocked |
| A9 | `ur_free` = 1,200, leave | Red "This is not a number." |
| A10 | `ur_free` = 42 | No note |
| A11 | `ur_msg` = 0.4, leave | Red "Check the scale."; Save is blocked |
| A12 | `ur_big` = 9007199254740992, leave; then 9007199254740993 | No note; then red "above the plausible range (allowed 0 to 9007199254740992)" |
| A13 | `ur_small` = 0.0000001, leave; then 0.00000009 | No note; then red "below the plausible range (allowed 0.0000001 to 0.00025)" |
| A14 | `ur_sbp` = 30, and click Save without leaving the field | Save is blocked anyway: the save-time check reads the value as it is |
| A15 | Clear every field you set, Save | Saves; a blank field checks nothing |

## Section B: limits by group, and a selector on another form

| # | Action | Expect |
|---|---|---|
| B1 | `ur_sex` blank, `ur_hb` = 2, leave | No note: no branch applies while `ur_sex` is blank |
| B2 | `ur_sex` = Male | Without touching `ur_hb`: red "below the plausible range (allowed 3 to 25 g/dL)" |
| B3 | `ur_hb` = 13, leave | Amber "lower than usual (expected 13.5 to 17.5 g/dL)" |
| B4 | `ur_sex` = Female | The note goes away: 13 is usual for the women's limits |
| B5 | `ur_hb` = 16, leave | Amber "higher than usual (expected 12 to 15.5 g/dL)" |
| B6 | `ur_snap` = 120, leave, Save | Red "above the plausible range (allowed 0 to 100)", followed by "(limits chosen from xe_site, read when this page was opened ...)". The save goes through, because a rule whose limits come from another form never blocks |

## Section C: comma decimals (pilot)

This section also records what REDCap's `getData` returns
for a comma-decimal field. The module accepts both `37,6` and `37.6` there, so
the audit is right either way; the step records which one REDCap uses.

| # | Action | Expect |
|---|---|---|
| C1 | `ur_temp_c` = 37,5, leave | No note |
| C2 | `ur_temp_c` = 37,6, leave | Amber "higher than usual (expected 36 to 37,5 °C)", written with a comma |
| C3 | `ur_temp_c` = 98,6, leave | Red "above the plausible range (allowed 30 to 43 °C)" |
| C4 | `ur_temp_c` = 37,6, Save anyway | Module log: `invalid-id-saved`, `field: ur_temp_c`, `type: range`, `reason: soft-high` |
| C5 | Data Exports, Reports: export `ur_temp_c` for XE-1 as CSV (raw) | Write down the stored form, `37,6` or `37.6`. Either is fine; note it in the run record |
| C6 | `ur_temp_c` = 37.6 typed with a point, leave | Record whether REDCap's own validation accepts a point on a comma field. If it does, the module reads 37.6 and shows the same amber note as C2 |

## Section D: calc, slider, read-only

| # | Action | Expect |
|---|---|---|
| D1 | `ur_wt` = 160, `ur_ht` = 150 (BMI calculates to 71.1) | `ur_bmi` shows red "above the plausible range (allowed 10 to 60 kg/m²)" |
| D2 | Save (answer "save anyway" for `ur_wt`) | The save is not blocked by `ur_bmi`: a calc never blocks. Module log: `ur_bmi` `hard-high` and `ur_wt` `soft-high` |
| D3 | Move the `ur_pain` slider to 9 | Amber "higher than usual (expected 0 to 7)" without leaving anything; Save asks first |
| D4 | Move the slider back to 5 | The note goes away |
| D5 | [optional] Give `ur_sbp` the `@READONLY` action tag next to its `@UVRANGE`, import `ur_sbp` = 30 for XE-1, open the form | Red note on `ur_sbp`; Save is not blocked by it |

## Section E: post-save audit and scan

| # | Action | Expect |
|---|---|---|
| E1 | Data Import Tool, XE-1, event `event_1_arm_1`: `ur_sex` = 1, `ur_hb` = 13, `ur_free` = n/a, `ur_big` = 9007199254740993 | Module log of pid 149: `invalid-id-saved` for `ur_hb` `soft-low`, `ur_free` `not-a-number`, `ur_big` `hard-high` (if the save hook runs for imports on this server) |
| E2 | Run the Validation scan | XE-1 lists, among the values saved in sections C and D, `ur_hb` "Unusual value", `ur_free` "Not a number", `ur_big` "Implausible value" and `ur_temp_c` "Unusual value" |
| E3 | Read the "What is wrong" text of those rows (or the CSV export) | `ur_hb`: "The value is lower than usual for this field. Expected 13.5 to 17.5 g/dL." (the men's limits, from the branch that judged it). `ur_temp_c`: "Expected 36 to 37,5 °C." `ur_big`: "Allowed 0 to 9007199254740992." `ur_free`: "The value is not a number." |

## Section F: configuration errors

Open the form. Each `ur_bad_*` field shows a configuration error and checks
nothing.

| Field | Error contains |
|---|---|
| `ur_bad_email` | needs a number field — give this Text field integer or number validation, or none (it has "email") |
| `ur_bad_radio` | does not support "radio" fields |
| `ur_bad_block` | "blockSave" does not apply to @UVRANGE |
| `ur_bad_order` | the "hard" low limit (25) is above its high limit (3) |
| `ur_bad_inside` | the "soft" low limit (2) is outside the "hard" range |
| `ur_bad_refs` | unknown @UVRANGE option(s): references |

## Section G: survey

| # | Action | Expect |
|---|---|---|
| G1 | Enable `uv_range_test` as a survey and open it for XE-1 | The rule fields behave as in section A |
| G2 | `ur_snap` = 120, leave | Red note without the "limits chosen from xe_site" part: a survey never names another field. The survey still submits. The page config carries the `when` as an answered condition (`["const",true]`), never the value of `xe_site` |
| G3 | `ur_sbp` = 30, Submit | Blocked, as on the form |

---

## Sign-off

| # | Gate | Result |
|---|---|---|
| 1 | Section A: notes wait for the field to be left, each level blocks as set, exact limits | ☐ |
| 2 | Section B: branches follow `ur_sex` live; a selector on another form never blocks | ☐ |
| 3 | Section C: comma decimals in notes and audit; stored form recorded | ☐ |
| 4 | Section D: calc and read-only never block; the slider re-checks | ☐ |
| 5 | Section E: audit and scan agree with the browser, branch limits in the detail line | ☐ |
| 6 | Section F: every broken tag shows its error | ☐ |
| 7 | Section G: survey behaviour, no field names | ☐ |

## Cleanup

Delete the `uv_range_test` instrument, or restore the dictionary downloaded in
setup step 2. Remove the survey setting if G1 enabled it and the `@READONLY`
tag if D5 added it.
