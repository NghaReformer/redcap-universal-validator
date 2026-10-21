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

Most measurement fields in sections I to K are validated as "Number (1 decimal
place)". REDCap refuses a whole number there ("Required format: Number (1
decimal place)") before the module sees it, so the steps write `46.0`, not
`46`, and `0.0` for zero. A comma-decimal field takes `80,0`.

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

Fixture: [`uvrange_growth_test_fields.csv`](uvrange_growth_test_fields.csv), 34
fields on two new instruments. `uv_growth_test` (24 fields) holds the inputs
(`ug_sex`, `ug_dob`, `ug_visit`, `ug_age_days`, `ug_sex_txt`), eleven growth
rules and eight broken `ug_bad_*` tags. `uv_growth_more` (10 fields) holds rules that read sex
and date of birth from `uv_growth_test`. On a data entry form their z-score
notes do not block, while a value that is not a number or is 0 or below
still does; on a survey (I30, I31) the page holds only such a value, and the
z-score is checked after saving. The WHO
tables ship with the module; no setting is needed. Every value in this section
was run offline through the module against the pid 149 seed, as for sections A
to H.

Setup:

1. Append the 34 rows of the CSV to the dictionary, as in setup step 2. The
   diff must report two new instruments and 34 new fields.
2. Designate `uv_growth_test` on `event_1_arm_1` (351) and `visit_1_arm_1`
   (380). Designate `uv_growth_more` on `event_1_arm_1` (351) only.
3. Steps I22 to I25 and I48 need the project setting for event and instance references
   switched on, as for the event/instance test plan.
4. On `uv_growth_test` for XE-1, event 351, set `ug_sex` = Male,
   `ug_dob` = 10-01-2025 and `ug_visit` = 2026-01-10, then save. The child is
   365 days old.
5. Note the project setting for how the audit logs invalid values, then set it
   to "Raw value + raw record ID" for this section (the test project holds no
   real data). The module log keeps a growth finding's z-score only in that
   mode; I46 checks the default.

Each note names the z-score, the reference and its unit, for example "This value
is lower than usual (z-score -2.28 on Weight-for-age, WHO 2006 (birth to 5
years), in kg; expected z-score -2 to 2)." A grey note starts with ℹ and says why a check did
not run; it never blocks.

| # | Action | Expect |
|---|---|---|
| I1 | `ug_weight` = 9.6, leave | No note (z-score -0.04) |
| I2 | `ug_weight` = 7.5, leave, Save | Amber "lower than usual (z-score -2.28 on Weight-for-age, WHO 2006 (birth to 5 years), in kg; expected z-score -2 to 2)". Save asks first |
| I3 | `ug_weight` = 4.0, leave | Red "below the plausible range (z-score -6.59 ...; allowed z-score -6 to 5)". Save is blocked |
| I4 | `ug_weight` = 0.0, leave | Red "A measurement must be above 0."; Save is blocked |
| I5 | `ug_weight` = 7.5, then `ug_sex` = Female | Without touching `ug_weight`, the note goes away (z-score -1.46 on the girls' rows) |
| I6 | `ug_sex` = Not recorded | Grey "Not checked against Weight-for-age, WHO 2006 (birth to 5 years): the sex code is neither 1 (male) nor 2 (female)." |
| I7 | Clear `ug_sex` | Grey "... the sex is blank." Set `ug_sex` = Male again |
| I8 | `ug_visit` = 2031-01-10 | Grey "... the age is outside the reference (0 to under 1826.25 days)." |
| I9 | `ug_visit` = 2024-12-31 | Grey "... the measurement is dated before the birth." Set `ug_visit` = 2026-01-10 again, and `ug_weight` = 9.6 |
| I10 | `ug_height` = 75.5, then 70.0, then 60.0, then 95.0, leaving each time | No note (-0.10); amber -2.42; red -6.62; red 8.11 "above the plausible range" |
| I11 | `ug_height` = 80.0. `ug_wfh` = 13.0, then 10.5, then 6.0 | Amber "higher than usual (z-score 2.40 on Weight-for-height, WHO 2006 (65 to 120 cm, standing), in kg ...)"; no note (-0.09); red -6.48 |
| I12 | With `ug_wfh` = 6.0, set `ug_height` = 60.0 | `ug_wfh` turns grey "... the height is outside the reference (65 to 120 cm)." `ug_height` shows red. Set `ug_height` = 80.0 and `ug_wfh` = 10.5 |
| I13 | `ug_age_days` = 365. `ug_hc` = 46.0, then 42.0, then 30.0 | No note (-0.05); amber -3.16 on "Head circumference-for-age"; red -12.50 |
| I14 | Clear `ug_age_days` | `ug_hc` turns grey "... the age is blank." Set 365 again and `ug_hc` = 46.0 |
| I15 | Save with `ug_weight` = 7.5 (answer "save anyway") | Module log: `invalid-id-saved`, `field: ug_weight`, `type: range`, `reason: soft-low`, `z: -2.28`, `reference: who-wfa` |
| I16 | Open `uv_growth_more` for XE-1, event 351. `ug_age_m` = 100.0, `ug_bmi` = 16.0, then 22.0, then 30.0 | No note (0.11); no note (2.67, this rule has hard limits only); red "above the plausible range (z-score 5.03 on BMI-for-age, WHO 2007 (5 to 19 years), in kg/m2; allowed z-score -5 to 5)", followed by "(worked out with ug_sex, read when this page was opened ...)". Save is not blocked |
| I17 | `ug_age_m` = 50.0 | Grey "... the age is outside the reference (60 to under 229 months)." Set 100.0 again |
| I18 | `ug_len_c` = 80,5. `ug_wfl_c` = 10,5, then 8,0, then 6,0 | No note (-0.05); amber -3.45 on "Weight-for-length"; red -6.45. The commas are read as decimals |
| I19 | `ug_oedema` = No. `ug_muac` = 14.0, then 11.0, then 9.0 | No note (-0.58); no note (-3.62, hard limits only); red -5.72 |
| I20 | `ug_oedema` = Yes | The `ug_muac` note goes away: the rule's `when` switches it off |
| I21 | `ug_ssf` = 15.0, leave. `ug_tsf` = 30.0, leave. Save | `ug_ssf` shows amber 4.33. `ug_tsf` shows red 8.53 "above the plausible range", followed by "(worked out with ...". Neither blocks. Module log after the save: `ug_bmi` `hard-high`, `ug_wfl_c` `hard-low`, `ug_ssf` `soft-high`, `ug_tsf` `hard-high` (z 8.53). The sex is saved on `uv_growth_test`, so the page config's `growth` holds five tables with male rows only: who2007-bfa (170 rows) and who-wfl (651), and who-acfa, who-ssfa and who-tsfa with the 3 rows around 365 days, since the dates are saved too. Together they count as one table of the four a page may carry |
| I22 | `uv_growth_test` for XE-1 on `visit_1_arm_1` (380): `ug_visit` = 2026-01-10, `ug_xev_wt` = 4.0, leave | Red "below the plausible range (z-score -6.59 ...)", followed by "(worked out with saved event/instance values ...)". The page config shows `rangeSexOp` `["lit","1"]` and `extendedAdvisory` `true` (every page carries `blockSave` `off` at the top level; @UVRANGE ignores it) |
| I23 | Save | The save goes through. Module log: `ug_xev_wt` `hard-low` with `event_id` 380 |
| I24 | Back on event 351, set `ug_weight` = 9.6, change `ug_dob` to 11-01-2025 and save | Module log: a new `ug_xev_wt` `hard-low` entry with `event_id` 380, because the save of event 351 re-checks the rule that reads it. The save also re-checks the `uv_growth_more` rules, which read the same sex and date of birth, so the four entries of I21 (`ug_bmi`, `ug_wfl_c`, `ug_ssf`, `ug_tsf`) appear again. Set `ug_dob` back to 10-01-2025 |
| I25 | On event 351, `ug_xev_wt` = 4.0, leave | Red note; Save is not blocked (an event-qualified rule never blocks). Clear it |
| I26 | `uv_growth_test`: check the eight `ug_bad_*` fields | Each shows its error: `ug_bad_case` "male" "m" and "female" "M" differ only in letter case, which this rule ignores. Add "caseSensitive": true to tell them apart; `ug_bad_months` "age" "months" field "ug_age_days" holds whole months, but "who-wfa" is read by day; `ug_bad_ref` "who-nope" is not a growth reference this server has, and lists the known ids; `ug_bad_axis` "who-wfh" is read by height — give "by", the field holding the height in cm, instead of "age"; `ug_bad_code` "male" is "M", which is not a choice of "sex" field "ug_sex" (its codes are 1, 2, 3); `ug_bad_unit` "unit" does not apply with a "reference"; `ug_bad_kind` "age" "dob" field "ug_weight" is not a date field; `ug_bad_reach` its "hard" low limit -7 is out of reach: with "who-tsfa" no measurement scores below -6.97 at 1826 days (female), so a low limit must be above -6.97 |
| I27 | [only if section H added a missing data code] `ug_sex`: choose `UNK` with the "M" button | `ug_weight` turns grey "... the sex is blank." Clear the code |
| I28 | [only if the durable scan is enabled] Run the Validation scan | The panel counts the findings saved in I15 to I24. The detail lines ("Expected z-score -2 to 2. Its z-score is -2.28 on the growth reference who-wfa." for `ug_weight`, "Allowed z-score -5 to 5. Its z-score is 5.03 on the growth reference who2007-bfa." for `ug_bmi`) are pinned offline by `tests/growth_module_php.php`, and the labels ("Unusual value", "Implausible value") by `tests/range_module_php.php` |
| I29 | Enable `uv_growth_test` and `uv_growth_more` as surveys. Open `uv_growth_test` for XE-2 at event 351: `ug_sex` = Male, `ug_dob` = 10-01-2025, `ug_visit` = 2026-01-10, `ug_weight` = 4.0 | Red note as in I3, without field names; Submit is blocked. Set `ug_weight` = 9.6 and submit |
| I30 | Survey `uv_growth_more` for XE-2: `ug_bmi` = 0.0, leave | Red "A measurement must be above 0."; Submit is blocked |
| I31 | Same survey: `ug_age_m` = 100.0, `ug_bmi` = 30.0, Submit | No note (sex is on another form, so it is not sent to a survey) and the survey submits. The page config has no `growth` key. Module log: `ug_bmi` `hard-high` for XE-2 |

Rows I32 to I40 run on the data entry form `uv_growth_test` for XE-1, event 351,
with the setup values (`ug_sex` = Male, `ug_dob` = 10-01-2025,
`ug_visit` = 2026-01-10) and `ug_height` = 80.0.

| # | Action | Expect |
|---|---|---|
| I32 | Open the page config | `growth` holds four tables, every row of both sexes: who-wfa, who-lhfa, who-wfh and who-hcfa (1827, 1827, 551 and 1827 rows per sex). `ug_bfa`, the fifth reference, is marked `deferred` and `deferredOnSave` |
| I33 | `ug_bfa` = 25.0, leave | Grey "Not checked on this page: this form already carries the rows of 4 growth reference tables, and this one would take it past the most one page may carry (4), so this check runs when the record is saved." No block |
| I34 | `ug_bfa` = 0.0, leave. Then `ug_bfa` = 25.0 and Save (answer "save anyway" where asked) | Red "A measurement must be above 0."; Save is blocked, though the z-score waits for the save. After the save, module log: `ug_bfa` `soft-high`, `z: 4.86`, `reference: who-bfa` |
| I35 | `ug_wt_two` = 9.6, then 7.5, then 6.0, leaving each time | No note; amber, one line, "lower than usual (z-score -4.24 on Weight-for-height ..." (weight-for-age gives -2.28, inside its soft limits, and says nothing); red, two lines, amber "(z-score -4.14 on Weight-for-age ..." and red "(z-score -6.48 on Weight-for-height ...". Save is blocked |
| I36 | `ug_wt_two` = 7.5, Save (answer "save anyway") | Module log: one `ug_wt_two` entry, `soft-low`, `z: -4.24`, `reference: who-wfh`; no who-wfa entry |
| I37 | `ug_wt_g` = 6600, leave, then 9600 | Amber "This value is lower than usual (z-score -3.40 on Weight-for-age, WHO 2006 (birth to 5 years), in kg after × 0.001; expected z-score -3 to 3)."; no note |
| I38 | Clear `ug_dob`. `ug_wt_g` = 95000, then 300, then 6600, leaving each time | Red "This value is above the plausible range (allowed 0.5 to 30 kg; no z-score on Weight-for-age, WHO 2006 (birth to 5 years): the date of birth or the date of the measurement is blank)." and Save is blocked; red "below the plausible range (allowed 0.5 to 30 kg; ..."; grey "Not checked against Weight-for-age, WHO 2006 (birth to 5 years): the date of birth or the date of the measurement is blank." |
| I39 | With `ug_dob` still blank and `ug_wt_g` = 6600, Save (answer "save anyway" where asked) | Module log: `uvalidate-not-evaluated` with `field: ug_wt_g`, `reference: who-wfa`, `why: the date of birth or the date of the measurement is blank`, and one such entry for each other rule read by date whose field holds a value. No `invalid-id-saved` entry for `ug_wt_g`. Set `ug_dob` = 10-01-2025 again and save |
| I40 | `ug_sex_txt` = m. `ug_wt_txt` = 6.6, leave. Then `ug_sex_txt` = F, then X | Amber "lower than usual (z-score -3.40 on Weight-for-age ..." (`m` is male); no note (-2.56 on the girls' rows); grey "Not checked against Weight-for-age, WHO 2006 (birth to 5 years): the sex code is neither M (male) nor F (female)." |
| I41 | [only if I29 enabled surveys] In the survey settings of `uv_growth_test`, show one section per page. Open the survey for XE-2 at event 351. On page 1 set `ug_sex` = Male, `ug_dob` = 10-01-2025, `ug_visit` = 2026-01-10, Next. On page 2 ("Phase 10 checks"), `ug_wt_g` = 95000, leave, then 6600, leave, then Submit | 95000: red "above the plausible range", because the inputs on page 1 are not on this page and read as blank; Submit is held. 6600: no note, and the survey submits. Module log: `ug_wt_g` `soft-low` `z: -3.40` for XE-2, judged with the saved inputs. If 6600 instead shows a z-score note on page 2, REDCap put the page 1 fields on page 2 and the multi-page note in the guide is wrong. Set the survey back to all questions on one page |
| I42 | On `uv_growth_more` for XE-1, event 351, set `ug_oedema` = No and save. Open `uv_growth_test` (`ug_height` = 80.0). `ug_wt_oed` = 25.0, then `abc`, then 7.5, leaving each time | 25.0: red, two lines, "above the plausible range (z-score 12.59 on Weight-for-height ..." followed by "(based on ug_oedema, read when this page was opened ...)", and "above the plausible range (z-score 11.58 on Weight-for-age ...; allowed z-score -6 to 5)". Save is blocked, because the saved `ug_oedema` makes only weight-for-height advisory and weight-for-age reads only this page. `abc`: red "This is not a number."; Save is blocked. 7.5: amber, one line, "lower than usual (z-score -4.24 on Weight-for-height ..." (weight-for-age gives -2.28); Save is not blocked |
| I43 | On `uv_growth_more` set `ug_oedema` = Yes and save. Reopen `uv_growth_test`: `ug_wt_oed` = 25.0, then 7.5, leaving each time | 25.0: red, one line, weight-for-age only (z-score 11.58), and no configuration error; in the page config the weight-for-height branch of `ug_wt_oed` is marked `growthIdle`. Save is blocked. 7.5: no note. Set `ug_oedema` = No again and save |
| I44 | Open `uv_growth_more` for XE-1 (a boy). `ug_wt_girl` = 6.6, then `abc`, leaving each time | No note and no configuration error box: the rule applies to girls only. The page config has no `who-wfa` table, and the rule on `ug_wt_girl` is marked `growthIdle`. Save is not blocked |
| I45 | On `uv_growth_test` set `ug_sex` = Female and save (answer "save anyway" if the save asks: the saved values are judged for a girl now). Reopen `uv_growth_more`: `ug_wt_girl` = 6.6, then 9.0, leaving each time | 6.6: amber "lower than usual (z-score -2.56 on Weight-for-age, WHO 2006 (birth to 5 years), in kg; expected z-score -2 to 2)", followed by "(based on ug_sex, ug_dob, ug_visit, read when this page was opened ...)"; Save is not blocked. 9.0: no note. The `who-wfa` table in the page config holds 3 rows for girls and none for boys. Set `ug_sex` = Male again and save (answer "save anyway" if asked) |
| I46 | Set the audit log setting to "Keyed hash of the value + raw record ID" (the default). On `uv_growth_test`, `ug_weight` = 7.5, Save (answer "save anyway") | Module log: `invalid-id-saved`, `field: ug_weight`, `reason: soft-low`, `reference: who-wfa` and a `value_hmac`; no `z` and no `value`. Set the setting back to the value noted in setup step 5 |
| I47 | Open `uv_growth_more` for XE-1 (a boy). `ug_wt_mix` = 6.6, then 50.0, then `abc`, leaving each time | No configuration error box at any point: the weight-for-age rule applies to girls only and is marked `growthIdle` in the page config, which has no `who-wfa` table, while the fixed limits judge. 6.6: no note. 50.0: red "This value is above the plausible range (allowed 1 to 40 kg)."; Save is blocked. `abc`: red "This is not a number."; Save is blocked. Clear it |
| I48 | `uv_growth_test` for XE-1 on `visit_1_arm_1` (380), `ug_visit` = 2026-01-10. `ug_xev_mix` = 50.0, then 4.0, leaving each time | 50.0: red, two lines, "above the plausible range (z-score 29.99 on Weight-for-age ..." followed by "(worked out with saved event/instance values ...)", and "above the plausible range (allowed 1 to 40 kg)". Save is blocked, because the fixed limits read only this page. In the page config only the `who-wfa` branch carries `extendedAdvisory`. 4.0: red, one line, z-score -6.59 on Weight-for-age; Save is not blocked. Clear it. Then on event 351 (the event the rule names), `ug_xev_mix` = 4.0: the same red line, and Save is still not blocked; 50.0: two red lines, Save is blocked. Clear it |

---

## Section J: returned values

Fixture: [`uvrange_return_test_fields.csv`](uvrange_return_test_fields.csv), 21
fields on one new instrument, `uv_growth_return`. It holds its own inputs
(`ugr_sex`, `ugr_dob`, `ugr_visit`), four rules that return values
(`ugr_weight` writes all four, `ugr_head` only the z-score and needs no
limits, `ugr_height` writes the range from the hard limits with no decimals,
`ugr_weight_g` reads grams with `"measureScale":0.001` and writes the range in
grams), their `@READONLY` target fields, and four broken rules (`ugr_bad_*`).
`tests/growth_return_module_php.php` loads this CSV as the data dictionary
and checks the rule errors of J1 and the values of J2 and J3 for the boy of
365 days; the girl's values are what `GrowthReference` answers for her.

Setup:

1. Append the 21 rows of the CSV, as in setup step 2. The diff must report one
   new instrument and 21 new fields.
2. Designate `uv_growth_return` on `event_1_arm_1` (351).
3. Open the form for XE-1, event 351. It is new and blank.

| # | Action | Expect |
|---|---|---|
| J1 | Look at the form | `ugr_bad_notes` shows "a returned value goes into a Text field"; `ugr_bad_clash_a` and `ugr_bad_clash_b` each show "a field takes the value of one rule" and name the other; `ugr_bad_chain` shows "it reads "[ugr_z]", which the rule on "ugr_weight" returns a value to". The other fields show no error |
| J2 | `ugr_sex` = Male, `ugr_dob` = 10-01-2025, `ugr_visit` = 2026-01-10 | Without a weight: `ugr_low` 7.8, `ugr_high` 11.9, `ugr_range` "7.8 to 11.9 kg", `ugr_height_range` "64 to 87 cm", `ugr_range_g` "7737 to 11989 g"; `ugr_z` and `ugr_head_z` stay empty |
| J3 | `ugr_weight` = 9.6, leave | `ugr_z` -0.04. The weight has no note |
| J4 | `ugr_weight` = 7.5, leave | `ugr_z` -2.28 and the amber note "lower than usual". The note and the value agree. Bounds do not change |
| J5 | `ugr_sex` = Female | Without touching the weight: `ugr_z` -1.46, `ugr_low` 7.1, `ugr_high` 11.5, `ugr_range` "7.1 to 11.5 kg", `ugr_height_range` "62 to 86 cm", `ugr_range_g` "7038 to 11513 g" |
| J6 | Clear `ugr_dob` | Every returned field is emptied. Set `ugr_dob` = 10-01-2025 again and `ugr_sex` = Male |
| J7 | `ugr_head` = 46.0, leave. Then 0.0 | `ugr_head_z` -0.05 with no note (the rule has no limits). Then red "A measurement must be above 0." and `ugr_head_z` empty |
| J8 | Try to type in `ugr_z` | The field is read-only |
| J9 | `ugr_weight` = 9.6. Save | Saves. In Logging, record whether the update of this form shows the four returned fields, or a second update by the module that wrote them. Either is right; the second would say the page's values were not posted |
| J10 | Data Import Tool, XE-2, event 351: `ugr_sex` 1, `ugr_dob` 10-01-2025, `ugr_visit` 2026-01-10, `ugr_weight` 9.6, no returned field in the file. Open XE-2 | `ugr_z` -0.04, `ugr_low` 7.8, `ugr_high` 11.9, `ugr_range` "7.8 to 11.9 kg", `ugr_height_range` "64 to 87 cm". Logging shows an update of XE-2 that wrote only these fields (if the save hook runs for imports on this server; record what it shows) |
| J11 | Import `ugr_sex` = 2 for XE-2 and nothing else. Open XE-2 | `ugr_z` 0.57, `ugr_low` 7.1, `ugr_high` 11.5, `ugr_range` "7.1 to 11.5 kg" |
| J12 | Tick the project setting "@UVRANGE returned values". Import `ugr_weight` = 7.5 for XE-2 | The returned fields keep the values of J11: the save writes nothing. Untick the setting |
| J13 | Open XE-2 | The page fills the fields as it opens (z -1.46 for a girl of 7.5 kg). Leave the page without saving and record whether REDCap warns about unsaved changes: it should not, because the first fill sends no change event. Open it again and Save: the fields are written |
| J14 | Enable `uv_growth_return` as a survey. For XE-3 fill sex, date of birth, date of the measurement and weight, then submit | The fields fill as you go, and the saved record holds the same values. Module log: no `uvalidate-audit-error` for the write |
| J15 | Open XE-1 again (a boy). `ugr_weight_g` = 7737, leave. Then 7736 | At 7737 no note shows, since 7737 g is the lowest weight the check accepts. At 7736 the amber note "lower than usual" shows. `ugr_range_g` stays "7737 to 11989 g" |
| J16 | `ugr_weight_g` = 11989, leave. Then 11990 | At 11989 no note shows. At 11990 the amber note "higher than usual" shows |

---

## Section K: growth chart and page table

Fixture: [`uvrange_chart_test_fields.csv`](uvrange_chart_test_fields.csv), 13
fields on one new instrument, `uv_growth_chart`. It holds its own inputs
(`ugc_sex`, `ugc_dob`, `ugc_visit`), six rules that show a chart or a table
(`ugc_weight` with percentile curves, the band and this visit's row;
`ugc_weight_z` on a z-score axis with its own title and an x title holding
`{unit}`; `ugc_height` with a chart and a table of the reference at each
birthday; `ugc_head`, which only shows and has no limits; `ugc_arm`, the fifth
set of curves on the page; `ugc_span`, whose `span` reaches past the
reference), and four broken rules (`ugc_bad_*`).
`tests/growth_chart_module_php.php` loads this CSV as the data dictionary and
checks the rule errors of K1, the page's sets of curves and its cap (K2, K7,
K8), the length table of K5 and the percentiles below. The texts on the chart
were read from the engine drawing this page in a browser.

Setup:

1. Append the 13 rows of the CSV, as in setup step 2. The diff must report one
   new instrument and 13 new fields.
2. Designate `uv_growth_chart` on `event_1_arm_1` (351).
3. Open the form for XE-1, event 351. It is new and blank.

A chart sits under its field's note, in a box headed "Growth chart"; a table
sits under the chart, in its own box. Hover the point to read its tooltip; the
same text ends the chart's description (`desc` inside the `svg`, read by
screen readers).

| # | Action | Expect |
|---|---|---|
| K1 | Look at the form | `ugc_bad_noref` shows ""show" only applies with a "reference""; `ugc_bad_bands` shows ""bands" shades the "soft" range, and this rule has no "soft" limits"; `ugc_bad_list` shows ""show" "chart" must be true or an object of its settings"; `ugc_bad_rows` shows "has more than 100 rows over its "span" [0, 365]". The other fields show no error. `ugc_head` takes no limits and shows no error |
| K2 | Same page, blank | `ugc_weight`, `ugc_height` and `ugc_head` each show an open "Growth chart" box saying "The curves show once the sex is entered."; `ugc_weight_z` shows its open box without that sentence, since its z-score lines do not depend on the sex; the table under `ugc_height` says "The values show once the sex is entered.". `ugc_arm` shows the grey note "Growth chart not shown: this form already shows 4 growth charts or tables of reference values, the most one page may show (4). The check itself is not affected." `ugc_span` shows "Growth chart not shown: its "span" [0, 2000] lies outside "who-wfa", which covers 0 to under 1826.25 days." In the config (read as in Setup), `growthCurves` has 3 sets and `growthCurveTables` 1 |
| K3 | `ugc_sex` = Male, `ugc_dob` = 10-01-2025, `ugc_visit` = 2026-01-10 | The charts draw the boy's curves; the heading reads "Weight-for-age, WHO 2006 (birth to 5 years)", "WHO Child Growth Standards (2006) · male" and the legend "This visit", plus "Usual range" on `ugc_weight` only. The x axis reads "Age (months)", with ticks at 0, 5 and 10, and "Age in months" on `ugc_weight_z`. With no measurement each chart's description ends "No measurement to show yet." |
| K4 | `ugc_weight` = 9.6, leave | A point at 12 months between the 15th and 85th percentile curves, inside the band. Tooltip: "This visit: 9.6 kg at 11.99 months, z-score -0.04, percentile 48.3". The table under the chart reads Age (days) 365, Weight (kg) 9.6, Z-score -0.04, Percentile 48.3. No note under the field. The axes did not move |
| K5 | `ugc_height` = 75.7, leave | Tooltip "This visit: 75.7 cm at 11.99 months, z-score -0.02, percentile 49.3". The table "Reference values" has 5 rows: 0, 365.25, 730.5, 1095.75 and 1461 days; the row 365.25 reads 71.3, 75.7 and 80.2 (the 3rd, 50th and 97th percentile, cm) |
| K6 | `ugc_head` = 46.0, leave. Then 0.0 | The chart draws the median only. Tooltip "This visit: 46.0 cm at 11.99 months, z-score -0.05, percentile 48.0", and no note under the field (the rule has no limits). At 0.0 the red note "A measurement must be above 0." shows and the description ends "The measurement is not above 0." |
| K7 | `ugc_arm` = 15.0, leave. Then 11.0 | No chart; the cap note of K2 stays. The check runs as usual, with no note at 15.0 (percentile 62.3) and the amber note "lower than usual" at 11.0 |
| K8 | `ugc_span` = 9.6, then 6.6, leave | No chart; the span note of K2 stays. At 6.6 the amber note "lower than usual" shows, as on `ugc_weight` |
| K9 | `ugc_weight_z` = 9.6, leave | The y axis is the z-score, from -4 to 4 or wider, with flat lines at -3, -2, 0, 2 and 3. The point sits just under the 0 line. The title reads "Weight-for-age z-score" |
| K10 | `ugc_weight` = 7.0, leave | Only the point moves, below the 3rd percentile curve and outside the band; the amber note "lower than usual" shows; the table's row follows (the weight, z-score and percentile change) |
| K11 | `ugc_visit` = 2027-07-10 | The charts are drawn again for a window of 0 to 3 years: "Age (years)" on `ugc_weight` and "Age in years" on `ugc_weight_z`. With 9.6 kg the tooltip reads "This visit: 9.6 kg at 2.49 years, z-score -2.78, percentile 0.3", and the amber note shows. Set `ugc_visit` back to 2026-01-10 |
| K12 | `ugc_sex` = Female | The curves change to the girl's; the heading says "female"; the table of reference values shows the girl's values (row 365.25: 69.2, 74.0, 78.8) |
| K13 | Collapse the "Growth chart" box of `ugc_weight` and type a new weight | The box stays closed; the note under the field still follows the value |
| K14 | Narrow the browser window until the form's data column is under 640 pixels wide, then type a new `ugc_weight` and leave | While the window narrows the chart shrinks as a whole and nothing overflows the column. After the new value it is drawn again to the column's width (at least 320 pixels), with its text at the size it had in a wide column. Widen the window and type another value, and it grows back, up to 640 pixels |
| K15 | Save the form. Open it again | The fields hold only what was typed: no chart or table value is saved. Logging shows no field the chart wrote |
| K16 | Enable `uv_growth_chart` as a survey and open it for XE-3 | The chart and table boxes start closed. An opened box draws as on the form. The cap and span notes of K2 do not show on the survey (`ugc_arm` and `ugc_span` show nothing), and the broken rules show the survey's general notice |
| K17 | With the charts drawn, open the browser's console | No Content Security Policy error. The chart sets its styles through each element's `style` object, which a strict `style-src` allows; the browser then shows them as `style` attributes in the markup, so those attributes are expected |

### Section K, continued: labels in two languages, saved inputs, branches, comma decimals, other events

K1 to K17 type every input on one non-repeating form on event 351, in
English, with a point for decimals. Steps K18 to K36 cover the rest.

Fixture: [`uvrange_chart_gaps_fields.csv`](uvrange_chart_gaps_fields.csv), 14
fields on three new instruments:

- `uv_chart_gaps` (8 fields) holds its own inputs (`ucg_sex`, `ucg_dob`,
  `ucg_visit`, `ucg_age_days`) and four charted rules. `ucg_mlm` gives every
  chart and table label as an English and French map (`"en"` and `"fr"` for
  the chart, `"default"` and `"fr"` for the table). `ucg_comma` is a
  comma-decimal field. `ucg_zpct` is a chart of z-scores with percentile
  lines, `"span":"auto"` and `"open":false`. `ucg_branch` has two rules on one
  field: WHO 2006 while `ucg_age_days` is at most 1826, WHO 2007 from 1827.
- `uv_chart_saved` (3 fields) reads the sex and the date of birth saved on
  `uv_chart_gaps`: `ucs_weight` with the date of the measurement typed in
  `ucs_visit`, `ucs_all` with all three inputs saved.
- `uv_chart_repeat` (3 fields) is a repeating instrument on `visit_1_arm_1`.
  `ucr_weight` reads the sex and the date of birth saved on `uv_chart_gaps`
  in the same event; `ucr_xev` reads them from `event_1_arm_1`.

`tests/growth_chart_gaps_module_php.php` loads this CSV as the data
dictionary of a project laid out as pid 149 is (two events, the repeating
instrument, the map of what repeats on the `\Project` object only, as on
REDCap 17.0.6). It checks the rule errors, the curve sets and the reference
values each page carries, and works out with `GrowthReference` every z-score
and percentile below. `tests/growth_chart_gaps_dom_js.cjs` draws those pages
with the engine and checks each text below against those numbers.

Setup:

1. Append the 14 rows of the CSV, as in setup step 2. The diff must report
   three new instruments and 14 new fields.
2. Designate `uv_chart_gaps` on `event_1_arm_1` (351) and `visit_1_arm_1`
   (380), `uv_chart_saved` on 351 and `uv_chart_repeat` on 380.
3. In Project Setup, "Repeating instruments and events", make
   `uv_chart_repeat` repeat on `visit_1_arm_1`.
4. Check in Multi-Language Management that the project's languages are `en`
   and `fr`.
5. Save `uv_chart_gaps` for XE-1 on 351 (`ucg_sex` = Male, `ucg_dob` =
   10-01-2025, `ucg_visit` = 2026-01-10), for XE-1 on 380 (Female, the same
   dates) and for XE-3 on 351 (Female, the same dates). Leave XE-2 without
   `uv_chart_gaps`.
6. K35 needs the project setting "Enable event and instance references"; the
   steps before it run with the setting off.

A chart is drawn to the width of the field's data column. REDCap 17 gives
that column about 327 pixels (K14), and the axis ticks below are the ones
drawn at that width; a wider column has more ticks. "Tooltip" is the text of
the point's `<title>`, as in K4.

| # | Action | Expect |
|---|---|---|
| K18 | Open `uv_chart_gaps` for XE-1, event 351, with the MLM language on English. `ucg_mlm` = 9.6, leave | The chart's box reads "Growth chart" and is open. The heading reads "Weight-for-age", then "WHO Child Growth Standards (2006) · boy", and the legend "This visit" and "Usual range". The x axis reads "Age (months)" with ticks 0, 5 and 10, the y axis "Weight (kg)", and "Percentiles" heads the line names 97, 85, 50, 15 and 3. Tooltip: "This visit: 9.6 kg at 11.99 months, z-score -0.04, percentile 48.3". The table's box reads "This visit", its caption "Weight-for-age", its headers Age (days), Weight (kg), Z-score, Percentile, and its row 365, 9.6, -0.04, 48.3 |
| K19 | Open the browser's Network panel. Switch the MLM language to French, without reloading | The boxes read "Courbe de croissance" and "Cette visite". The heading reads "Poids pour l'âge", "WHO Child Growth Standards (2006) · garçon", and the legend "Cette visite" and "Zone habituelle"; the axes "Âge (mois)" and "Poids (kg)"; "Centiles" heads the line names. Tooltip: "Cette visite: 9.6 kg at 11.99 mois, z-score -0.04, percentile 48.3" (the module's own words "at", "z-score" and "percentile" have no translation and stay English). The table's caption reads "Poids pour l'âge", its headers Âge (jours), Poids (kg), Score z, Centile, and the row is unchanged. The curves are not drawn again, the note is not written again, and the switch sends no request to the module (REDCap's own MLM request only). The boxes of `ucg_comma`, a rule without language maps, still read "Growth chart" and "This visit" |
| K20 | Still in French, `ucg_visit` = 2027-07-10, then `ucg_sex` = Female. Then set `ucg_visit` = 2026-01-10 and `ucg_sex` = Male, and switch to English | 2027-07-10: the x axis reads "Âge (ans)" and the tooltip "Cette visite: 9.6 kg at 2.49 ans, z-score -2.78, percentile 0.3". With Female the heading reads "... · fille". Back in English, every text reads as in K18 |
| K21 | Switch to French and reload the page | Once MLM has started, the chart and the table show the French texts of K19. Before that the English ones may show for a moment, as the rc.1 run saw for messages |
| K22 | Switch to English. With `ucg_age_days` blank, look under `ucg_branch`. Then `ucg_age_days` = 365, `ucg_branch` = 9.6, leave | Blank age: no chart under `ucg_branch`, since neither rule applies. 365: one chart, headed "Weight-for-age, WHO 2006 (birth to 5 years)" and "WHO Child Growth Standards (2006) · male", line names 5, 3, 0, -3, -6, x ticks 0, 5 and 10, tooltip "This visit: 9.6 kg at 11.99 months, z-score -0.04, percentile 48.3", and no note |
| K23 | `ucg_age_days` = 2000, `ucg_branch` = 18.0, leave. Then `ucg_age_days` = 1826, then 1827 | 2000: the WHO 2006 chart goes, and a chart headed "Weight-for-age, WHO 2007 (5 to 10 years)" and "WHO Growth Reference for 5 to 19 years (2007) · male" takes its place, x axis "Age (months)" with ticks 60, 62, 64, 66, tooltip "This visit: 18.0 kg at 65.71 months, z-score -0.55, percentile 29.1", and no note. 1826: the WHO 2006 chart again, "Age (years)" with ticks 0, 2 and 4, tooltip "This visit: 18.0 kg at 5 years, z-score -0.14, percentile 44.6". 1827: the WHO 2007 chart, tooltip "This visit: 18.0 kg at 60.02 months, z-score -0.14, percentile 44.3". One chart shows at a time |
| K24 | `ucg_comma` = 9,6, leave. Then 7,0 | 9,6: tooltip "This visit: 9,6 kg at 11,99 months, z-score -0,04, percentile 48,3". The table's caption reads "Weight-for-age, WHO 2006 (birth to 5 years)" (the reference's title, as the rule gives none) and its row 365, 9,6, -0,04, 48,3. The point sits between the 3rd and 97th percentile curves, at 9.6 on the y axis. No note. 7,0: amber "This value is lower than usual (z-score -2.90 on Weight-for-age, WHO 2006 (birth to 5 years), in kg; expected z-score -2 to 2)." The note writes the z-score with a point, as it writes every growth limit; the chart and the table use the field's comma. Tooltip "This visit: 7,0 kg at 11,99 months, z-score -2,90, percentile 0,2", row 365, 7,0, -2,90, 0,2, and the point drops below the 3rd percentile curve |
| K25 | `ucg_comma` = 9.6, typed with a point, leave | Record whether REDCap's own check accepts a point on a comma field (as in C6). If it does, the tooltip reads "This visit: 9.6 kg at 11,99 months, z-score -0,04, percentile 48,3", row 365, 9.6, -0,04, 48,3 (the value as typed), no note, and the point at 9.6 |
| K26 | Look at `ucg_zpct`. Open its box, then `ucg_zpct` = 9.6, leave | The "Growth chart" box starts closed (`"open":false`). Once opened it shows a y axis "z-score" with ticks from -4 to 4; five flat lines named 97, 85, 50, 15 and 3, the 50 line green and the others grey, the 3 and 97 lines at z -1.88 and 1.88; "Percentiles" heads the names. The x axis reads "Age (months)" with ticks 0, 5 and 10, as with no `span`: `"auto"` is the default. The point sits just under the 50 line. Tooltip "This visit: 9.6 kg at 11.99 months, z-score -0.04, percentile 48.3" |
| K27 | Read the page config (as in Setup) | `growthCurves` has 4 sets, each with `male` and `female`: those of `ucg_mlm`, `ucg_comma` and the two branches of `ucg_branch` (span `[60, 121]` for WHO 2007). `ucg_zpct` has no `growthCurveKey`. `growth` holds `who-wfa` and `who2007-wfa`, 1827 and 62 rows for each sex. The `ucg_mlm` rule's `i18n` has 17 keys, `show.chart.labels.*` with `en` and `fr`, `show.table.labels.*` with `fr` only |
| K28 | Open `uv_chart_gaps` for XE-1 on `visit_1_arm_1` (380). `ucg_mlm` = 9.6, leave. Leave the page without saving | The girl's inputs saved in setup step 5: the heading reads "... · girl", tooltip "This visit: 9.6 kg at 11.99 months, z-score 0.57, percentile 71.6". The config has the same 4 sets as K27 |
| K29 | Open `uv_chart_saved` for XE-1, event 351. Look, then `ucs_visit` = 2026-01-10 | With no sex on this page, `ucs_weight`'s chart draws the boy's curves at once: "WHO Child Growth Standards (2006) · male". The table's box reads "Reference values": 5 rows, headers Age (days), Value at percentile 3 (kg), Value at percentile 50 (kg), Value at percentile 97 (kg), and row 365.25 reads 7.8, 9.6, 11.8. After the date the description ends "No measurement to show yet." In the config the sets of `ucs_weight` and `ucs_all` and the reference values hold `male` only, `growth.who-wfa.female` is empty, and `ucs_weight` has `rangeSexOp` `["lit","1"]` and `rangeAgeDobOp` `["lit","2025-01-10"]`. Compare K27, where the sex is typed on the page and every set holds both sexes |
| K30 | `ucs_weight` = 9.6, leave. Then 7.0, leave, and Save and stay (answer "save anyway" if asked). Then `ucs_visit` = 2027-07-10. Then `ucs_all` = 9.6, leave | 9.6: tooltip as in K18, no note. 7.0: amber "This value is lower than usual (z-score -2.90 on Weight-for-age, WHO 2006 (birth to 5 years), in kg; expected z-score -2 to 2). (worked out with ucg_sex, ucg_dob, read when this page was opened — reload if it has changed since. This check does not block saving; it is re-checked after the save.)", and the save is not held by it. 2027-07-10: the x axis reads "Age (years)" and the heading still says male. `ucs_all`: its point is placed from the saved date, tooltip as in K18, line names 5, 2, 0, -2, -5 |
| K31 | Open `uv_chart_saved` for XE-2 (no sex saved). Then `ucs_visit` = 2026-01-10, `ucs_weight` = 9.6, leave | Under `ucs_weight`: "ℹ Growth chart not shown: the sex saved for this record is blank or neither the rule's male nor its female code, so there is nothing to draw." and "ℹ Table not shown: " with the same reason; under `ucs_all` the chart's note. The config has no `growthCurves` and no `growthCurveTables`. The weight: grey "Not checked against Weight-for-age, WHO 2006 (birth to 5 years): the sex is blank." Leave without saving |
| K32 | Open `uv_chart_saved` for XE-3 (a girl saved) | The heading reads "... · female", and the curve set and the reference values in the config hold `female` only |
| K33 | Open `uv_chart_repeat` for XE-1 on `visit_1_arm_1` (380), instance 1, with event and instance references off. `ucr_visit` = 2026-01-10, `ucr_weight` = 9.6, leave, Save | `ucr_xev` shows its configuration error: "a "sex" field in another event or instance needs event and instance references — enable them in project settings first." followed by the same for "age" "dob". `ucr_weight` draws the girl's curves, read from `uv_chart_gaps` in this event: "· female", line names 97, 50, 3, tooltip "This visit: 9.6 kg at 11.99 months, z-score 0.57, percentile 71.6", table row 365, 9.6, 0.57, 71.6. The save goes through |
| K34 | Add instance 2: `ucr_visit` = 2026-07-10, `ucr_weight` = 8.0, leave, Save (answer "save anyway"). Reopen instance 1 | Instance 2: tooltip "This visit: 8.0 kg at 17.94 months, z-score -2.05, percentile 2.0", and amber "This value is lower than usual (z-score -2.05 on Weight-for-age, ...)" followed by "(worked out with ucg_sex, ucg_dob, read when this page was opened ...)". Instance 1 still shows 9.6 at 11.99 months |
| K35 | Tick "Enable event and instance references". Reopen instance 1. `ucr_xev` = 7.0, leave | No configuration error. `ucr_xev` draws the boy's curves, read from `event_1_arm_1`: "· male", tooltip "This visit: 7.0 kg at 11.99 months, z-score -2.90, percentile 0.2", and amber "This value is lower than usual (z-score -2.90 on Weight-for-age, WHO 2006 (birth to 5 years), in kg; expected z-score -2 to 2). (worked out with saved event/instance values, read when this page was opened — reload if it has changed since. This check does not block saving; it is re-checked after the save.)". The config's set for `ucr_weight` holds `female` only and the one for `ucr_xev` `male` only; `ucr_xev` has `extendedAdvisory` `true`. Clear `ucr_xev`, then untick the setting |
| K36 | With charts drawn on each of the three forms, open the console. Then reopen each saved form | No Content Security Policy error and no engine error. The forms hold only what was typed: no chart or table value was saved |

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
| 9 | Section I: z-scores and verdicts match the values above; grey notes say why a check did not run; on a data entry form the z-scores of the rules on `uv_growth_more` and of event-qualified rules never block (a value that is not a number or is 0 or below on `uv_growth_more` does), and the fifth whole table is checked on save while the page still holds 0; two references on one field both judge; `measureScale`, `plausible` and a lower-case sex code work; on a survey only a value that is not a number above 0 is held; a reference's `when` and its event/instance values affect only that reference, and a rule whose `when` is settled false needs no table; the audit logs findings with their reference, their z-score only with raw values, and values not evaluated | ☐ |
| 10 | Section J: the returned fields follow the inputs on the page, are emptied when an input is blank, are written for an import and a survey, and the project setting turns the save write off | ☐ |
| 11 | Section K: the charts and tables draw for the inputs on the page, the point and the table match the values above, only the point moves on a new value, the axes follow the age, a rule that only shows needs no limits, the cap and the span leave a note for staff and nothing on a survey, nothing is saved, and the console shows no Content Security Policy error | ☐ |
| 12 | Section K, K18 to K36: chart and table labels follow the MLM language in place, with no request; a sex saved on another form sends one sex's curves and a blank one leaves a note for staff; only the active branch's chart shows, with its own reference; a comma field's tooltip, table and point read the comma value; a chart of z-scores draws percentile lines and starts closed; a charted field works on another event and on a repeating instrument, and reads another event's inputs with event and instance references on | ☐ |

## Cleanup

Delete the `uv_range_test`, `uv_growth_test`, `uv_growth_more`,
`uv_growth_return`, `uv_growth_chart`, `uv_chart_gaps`, `uv_chart_saved` and
`uv_chart_repeat` instruments, or restore the dictionary downloaded in setup
step 2. Remove the survey settings if G1, I29, J14 or K16 enabled them, the
missing data code if section H added it, and the repeating setting of
`uv_chart_repeat` if deleting the instrument left it. Untick "Enable event and
instance references" if K35 ticked it.
