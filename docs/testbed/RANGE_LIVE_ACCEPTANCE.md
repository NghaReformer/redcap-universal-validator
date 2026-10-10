# @UVRANGE live acceptance protocol

Live test of plausibility limits on a real REDCap. The automated suites
(`tests/range_php.php`, `tests/range_js.cjs`, `tests/range_dom_js.cjs`,
`tests/range_module_php.php`) prove the logic against stubs. This run checks
real number validation, real comma-decimal fields, a real calc and slider, the
browser's save dialog, the module log, missing data codes and the Validation scan's count.

Target: chpr-redcap.org pid 149 (longitudinal, repeating, seed records XE-1 to
XE-4). Fixture: [`uvrange_test_fields.csv`](uvrange_test_fields.csv), 19 fields
on one new instrument `uv_range_test`: 11 rule fields carry a valid
`@UVRANGE`, 6 `ur_bad_*` fields carry a broken one, and `ur_sex` and `ur_ht`
carry no tag. Before this sheet was written, every annotation and every saved
value below was run offline through the module against the dictionary and the
seed data in `event_instance/`: the 11 rule fields configure, the 6 `ur_bad_*`
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
| A14 | Clear `ur_msg`, `ur_big` and `ur_small`. Type `ur_sbp` = 30 and click Save straight away | Save is blocked and the dialog names only Systolic pressure. (Clicking Save leaves the field first; the save-time re-check of a value never left is pinned offline by `tests/range_dom_js.cjs`) |
| A15 | `ur_wt` = 2E2, leave | Record whether REDCap's own number validation accepts the exponent. If it does, the module reads it as 200 and shows amber "higher than usual (expected 30 to 150 kg)". Then set 70 |
| A16 | Clear every field you set, Save | Saves; a blank field checks nothing |

## Section B: limits by group, and a selector on another form

| # | Action | Expect |
|---|---|---|
| B1 | `ur_sex` blank, `ur_hb` = 2, leave | No note: no branch applies while `ur_sex` is blank |
| B2 | `ur_sex` = Male | Without touching `ur_hb`: red "below the plausible range (allowed 3 to 25 g/dL)" |
| B3 | `ur_hb` = 13, leave | Amber "lower than usual (expected 13.5 to 17.5 g/dL)" |
| B4 | `ur_sex` = Female | The note goes away: 13 is usual for the women's limits |
| B5 | `ur_hb` = 16, leave | Amber "higher than usual (expected 12 to 15.5 g/dL)" |
| B6 | Clear `ur_hb`. `ur_snap` = 120, leave, Save | Red "above the plausible range (allowed 0 to 100)", followed by "(limits chosen from xe_site, read when this page was opened ...)". The save goes through, because a rule whose limits come from another form never blocks |

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
| D5 | [optional] Give `ur_sbp` the `@READONLY` action tag next to its `@UVRANGE`, import `ur_sbp` = 30 for XE-1, open the form | Red note on `ur_sbp`; Save is not blocked by it. Then remove `@READONLY` again and clear `ur_sbp`, so section G sees the normal field |

## Section E: post-save audit and scan

| # | Action | Expect |
|---|---|---|
| E1 | Data Import Tool, XE-1, event `event_1_arm_1`: `ur_sex` = 1, `ur_hb` = 13, `ur_free` = n/a, `ur_big` = 9007199254740993 | Module log of pid 149: `invalid-id-saved` for `ur_hb` `soft-low`, `ur_free` `not-a-number`, `ur_big` `hard-high` (if the save hook runs for imports on this server) |
| E2 | [only if the durable scan is enabled] Run the Validation scan | The panel counts at least 4 findings: `ur_hb`, `ur_free` and `ur_big` from E1, and `ur_temp_c` from C4. The scan page shows counts only while the report is being rebuilt; the wording and detail lines of each finding are pinned offline by `tests/range_module_php.php` until the stored-report exporter ships |

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
| G1 | Enable `uv_range_test` as a survey and open it for XE-2 (E1 left blocking values on XE-1) | The rule fields behave as in section A |
| G2 | `ur_snap` = 120, leave | Red note without the "limits chosen from xe_site" part: the note does not name `xe_site`. The survey still submits. The page config still carries the `when` text, its answer (`["const",true]`) and `snapshotFields`; write down what it shows |
| G3 | `ur_sbp` = 30, Submit | Blocked, as on the form |


## Section H: missing data codes

Needs at least one missing data code in Project Setup, Additional
customizations (for example `UNK, Unknown`). Add it for this run if the project
has none, and remove it afterwards.

| # | Action | Expect |
|---|---|---|
| H1 | `ur_sex` = Male. On `ur_hb`, click the "M" button and choose `UNK` | No note on `ur_hb`; Save is not held by it |
| H2 | Save | Module log: no `invalid-id-saved` entry for `ur_hb` |
| H3 | On `ur_sbp`, choose `UNK` with the "M" button, then Save | No note, no log entry. Then clear the code from both fields |
| H4 | Data Import Tool, XE-2: `ur_free` = `UNK` | Module log: no `not-a-number` entry for `ur_free` (if the save hook runs for imports on this server) |

## Section I: growth references (WHO z-scores)

Fixture: [`uvrange_growth_test_fields.csv`](uvrange_growth_test_fields.csv), 23
fields on two new instruments. `uv_growth_test` (15 fields) holds the inputs
(`ug_sex`, `ug_dob`, `ug_visit`, `ug_age_days`), five growth rules and six
broken `ug_bad_*` tags. `uv_growth_more` (8 fields) holds rules that read sex
and date of birth from `uv_growth_test`. On a data entry form their notes do
not block; on a survey (I30, I31) the page holds only a value that is not a
number or is 0 or below, and the z-score is checked after saving. The WHO
tables ship with the module; no setting is needed. Every value in this section
was run offline through the module against the pid 149 seed, as for sections A
to H.

Setup:

1. Append the 22 rows of the CSV to the dictionary, as in setup step 2. The
   diff must report two new instruments and 22 new fields.
2. Designate `uv_growth_test` on `event_1_arm_1` (351) and `visit_1_arm_1`
   (380). Designate `uv_growth_more` on `event_1_arm_1` (351) only.
3. Steps I22 to I25 need the project setting for event and instance references
   switched on, as for the event/instance test plan.
4. On `uv_growth_test` for XE-1, event 351, set `ug_sex` = Male,
   `ug_dob` = 10-01-2025 and `ug_visit` = 2026-01-10, then save. The child is
   365 days old.

Each note names the z-score and the reference, for example "This value is lower
than usual (z-score -2.28 on Weight-for-age, WHO 2006 (birth to 5 years);
expected z-score -2 to 2)." A grey note starts with ℹ and says why a check did
not run; it never blocks.

| # | Action | Expect |
|---|---|---|
| I1 | `ug_weight` = 9.6, leave | No note (z-score -0.04) |
| I2 | `ug_weight` = 7.5, leave, Save | Amber "lower than usual (z-score -2.28 on Weight-for-age, WHO 2006 (birth to 5 years); expected z-score -2 to 2)". Save asks first |
| I3 | `ug_weight` = 4.0, leave | Red "below the plausible range (z-score -6.59 ...; allowed z-score -6 to 5)". Save is blocked |
| I4 | `ug_weight` = 0, leave | Red "A measurement must be above 0."; Save is blocked |
| I5 | `ug_weight` = 7.5, then `ug_sex` = Female | Without touching `ug_weight`, the note goes away (z-score -1.46 on the girls' rows) |
| I6 | `ug_sex` = Not recorded | Grey "Not checked against Weight-for-age, WHO 2006 (birth to 5 years): the sex code is neither 1 (male) nor 2 (female)." |
| I7 | Clear `ug_sex` | Grey "... the sex is blank." Set `ug_sex` = Male again |
| I8 | `ug_visit` = 2031-01-10 | Grey "... the age is outside the reference (0 to under 1826.25 days)." |
| I9 | `ug_visit` = 2024-12-31 | Grey "... the measurement is dated before the birth." Set `ug_visit` = 2026-01-10 again, and `ug_weight` = 9.6 |
| I10 | `ug_height` = 75.5, then 70, then 60, then 95, leaving each time | No note (-0.10); amber -2.42; red -6.62; red 8.11 "above the plausible range" |
| I11 | `ug_height` = 80. `ug_wfh` = 13, then 10.5, then 6.0 | Amber "higher than usual (z-score 2.40 on Weight-for-height, WHO 2006 (65 to 120 cm, standing) ...)"; no note (-0.09); red -6.48 |
| I12 | With `ug_wfh` = 6.0, set `ug_height` = 60 | `ug_wfh` turns grey "... the height is outside the reference (65 to 120 cm)." `ug_height` shows red. Set `ug_height` = 80 and `ug_wfh` = 10.5 |
| I13 | `ug_age_days` = 365. `ug_hc` = 46, then 42, then 30 | No note (-0.05); amber -3.16 on "Head circumference-for-age"; red -12.50 |
| I14 | Clear `ug_age_days` | `ug_hc` turns grey "... the age is blank." Set 365 again and `ug_hc` = 46 |
| I15 | Save with `ug_weight` = 7.5 (answer "save anyway") | Module log: `invalid-id-saved`, `field: ug_weight`, `type: range`, `reason: soft-low` |
| I16 | Open `uv_growth_more` for XE-1, event 351. `ug_age_m` = 100, `ug_bmi` = 16, then 22, then 30 | No note (0.11); no note (2.67, this rule has hard limits only); red "above the plausible range (z-score 5.03 on BMI-for-age, WHO 2007 (5 to 19 years); allowed z-score -5 to 5)", followed by "(worked out with ug_sex, read when this page was opened ...)". Save is not blocked |
| I17 | `ug_age_m` = 50 | Grey "... the age is outside the reference (60 to under 229 months)." Set 100 again |
| I18 | `ug_len_c` = 80,5. `ug_wfl_c` = 10,5, then 8,0, then 6,0 | No note (-0.05); amber -3.45 on "Weight-for-length"; red -6.45. The commas are read as decimals |
| I19 | `ug_oedema` = No. `ug_muac` = 14, then 11, then 9 | No note (-0.58); no note (-3.62, hard limits only); red -5.72 |
| I20 | `ug_oedema` = Yes | The `ug_muac` note goes away: the rule's `when` switches it off |
| I21 | `ug_ssf` = 15, leave. `ug_tsf` = 30, leave. Save | `ug_ssf` shows amber 4.33. `ug_tsf` shows grey "Not checked on this page: this form already carries 4 growth reference tables, ... so this check runs when the record is saved." No verdict, no block. Module log after the save: `ug_bmi` `hard-high`, `ug_wfl_c` `hard-low`, `ug_ssf` `soft-high`, `ug_tsf` `hard-high` (z 8.53). The page config's `growth` holds 4 tables (who2007-bfa, who-wfl, who-acfa and who-ssfa) |
| I22 | `uv_growth_test` for XE-1 on `visit_1_arm_1` (380): `ug_visit` = 2026-01-10, `ug_xev_wt` = 4.0, leave | Red "below the plausible range (z-score -6.59 ...)", followed by "(worked out with saved event/instance values ...)". The page config shows `rangeSexOp` `["lit","1"]` and `extendedAdvisory` `true` (every page carries `blockSave` `off` at the top level; @UVRANGE ignores it) |
| I23 | Save | The save goes through. Module log: `ug_xev_wt` `hard-low` with `event_id` 380 |
| I24 | Back on event 351, set `ug_weight` = 9.6, change `ug_dob` to 11-01-2025 and save | Module log: a new `ug_xev_wt` `hard-low` entry with `event_id` 380, because the save of event 351 re-checks the rule that reads it. The save also re-checks the `uv_growth_more` rules, which read the same sex and date of birth, so the four entries of I21 (`ug_bmi`, `ug_wfl_c`, `ug_ssf`, `ug_tsf`) appear again. Set `ug_dob` back to 10-01-2025 |
| I25 | On event 351, `ug_xev_wt` = 4.0, leave | Red note; Save is not blocked (an event-qualified rule never blocks). Clear it |
| I26 | `uv_growth_test`: check the six `ug_bad_*` fields | Each shows its error: `ug_bad_ref` "who-nope" is not a growth reference this server has, and lists the known ids; `ug_bad_axis` "who-wfh" is read by height, give "by"; `ug_bad_code` "male" is "M", which is not a choice of "sex" field "ug_sex" (its codes are 1, 2, 3); `ug_bad_unit` "unit" does not apply with a "reference"; `ug_bad_kind` "age" "dob" field "ug_weight" is not a date field; `ug_bad_reach` its "hard" low limit -7 is out of reach: with "who-tsfa" no measurement scores below -6.97 at 1826 days (female). Use a low limit of -6.96 or above |
| I27 | [only if section H added a missing data code] `ug_sex`: choose `UNK` with the "M" button | `ug_weight` turns grey "... the sex is blank." Clear the code |
| I28 | [only if the durable scan is enabled] Run the Validation scan | The panel counts the findings saved in I15 to I24. The detail lines ("Expected z-score -2 to 2." for a soft finding, "Allowed z-score -5 to 5." for `ug_bmi`) are pinned offline by `tests/growth_module_php.php`, and the labels ("Unusual value", "Implausible value") by `tests/range_module_php.php` |
| I29 | Enable `uv_growth_test` and `uv_growth_more` as surveys. Open `uv_growth_test` for XE-2 at event 351: `ug_sex` = Male, `ug_dob` = 10-01-2025, `ug_visit` = 2026-01-10, `ug_weight` = 4.0 | Red note as in I3, without field names; Submit is blocked. Set `ug_weight` = 9.6 and submit |
| I30 | Survey `uv_growth_more` for XE-2: `ug_bmi` = 0, leave | Red "A measurement must be above 0."; Submit is blocked |
| I31 | Same survey: `ug_age_m` = 100, `ug_bmi` = 30, Submit | No note (sex is on another form, so it is not sent to a survey) and the survey submits. The page config has no `growth` key. Module log: `ug_bmi` `hard-high` for XE-2 |

---

## Sign-off

| # | Gate | Result |
|---|---|---|
| 1 | Section A: notes wait for the field to be left, each level blocks as set, exact limits | ☐ |
| 2 | Section B: branches follow `ur_sex` live; a selector on another form never blocks | ☐ |
| 3 | Section C: comma decimals in notes and audit; stored form recorded | ☐ |
| 4 | Section D: calc and read-only never block; the slider re-checks | ☐ |
| 5 | Section E: the audit agrees with the browser; the scan counts the findings | ☐ |
| 6 | Section F: every broken tag shows its error | ☐ |
| 7 | Section G: survey behaviour, no field names in the notes | ☐ |
| 8 | Section H: a missing data code is never judged | ☐ |
| 9 | Section I: z-scores and verdicts match the values above; grey notes say why a check did not run; on a data entry form the rules on `uv_growth_more`, event-qualified rules and the fifth table never block; on a survey only a value that is not a number above 0 is held; the audit logs them | ☐ |

## Cleanup

Delete the `uv_range_test`, `uv_growth_test` and `uv_growth_more` instruments,
or restore the dictionary downloaded in setup step 2. Remove the survey settings
if G1 or I29 enabled them, and the missing data code if section H added it.
