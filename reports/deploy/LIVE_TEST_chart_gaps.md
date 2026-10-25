# Live test results: @UVRANGE chart paths K18 to K36 on chpr-redcap.org pid 149

Date: 2026-10-21. Server: REDCap 17.0.6. Module folder `universal_validator_v2.3.1`, whose
`js/engine.js` is byte for byte the one tagged `v2.3.0-rc.2` (456,415 characters, SHA-256
`ce5fc550…0ef4`). Browser: the Claude desktop app's built-in pane, signed in as ngha.ndze.

Run sheet and fixture: [`docs/testbed/RANGE_LIVE_ACCEPTANCE.md`](../../docs/testbed/RANGE_LIVE_ACCEPTANCE.md),
section "Section K, continued", and
[`docs/testbed/uvrange_chart_gaps_fields.csv`](../../docs/testbed/uvrange_chart_gaps_fields.csv),
All of it is on local `main` (not pushed). Another session fixed F1, L1 and L2 in b82f3f1,
merged into `main` as 3bfb250. The branch `test/uvrange-chart-gaps` then went into `main` as
2a15ff1:

| Commit | What |
|---|---|
| 923c7c7 | Steps K18 to K36, the 14-field fixture, `tests/growth_chart_gaps_module_php.php` and `tests/growth_chart_gaps_dom_js.cjs` |
| b1b9adb | Run sheet notes from the first live run: MLM per-form switch, values that come back, column width, K14 wording |
| 64859c4 | An engine-only fix of F1 and L2. The merge kept b82f3f1's version of every file the two commits share, so none of 64859c4 is on `main` |
| e50a093 | K34 and K35 wording from the second live run; instance 2's table row checked offline |
| 79db777 | This report, first version |
| 2a15ff1 | The merge. It also writes the gaps fixture again on main's server output |
| on `test/uvrange-chart-gaps` after 2a15ff1 | The CHANGELOG entry for K18 to K36, and this report brought up to date |

The steps ran in two parts. The parent session ran K18 to K27 and K29 to K32 from 14:44 to
15:55; its results are in `reports/deploy/LIVE_TEST_v2.3.0-rc.2_charts.md` in the main
checkout. This session ran K28, K33, K34 and K36 from 16:09 to 16:26.

## Verdict

Of the 19 steps, 17 pass, K25 records REDCap's own behaviour, and K35 could not run on
pid 149 (see K35 below). The new paths showed no engine or server defect: labels in two
languages, inputs saved on another form or event, branches that switch reference by age,
comma decimals, a repeating instrument, and a z-score chart with percentile lines.

The parent's run of K1 to K17 found F1, L1 and L2. All three are fixed in b82f3f1 on local
`main`. The fixes are not live yet: the server still runs the rc.2 engine.

## Results

| # | Result | Seen |
|---|---|---|
| K18 | Pass | Parent run: English map texts, "· boy", tooltip and table row as the sheet says |
| K19 | Pass | Parent run: French in place with no request to the module; `ucg_comma` stays English |
| K20 | Pass | Parent run: "Âge (ans)", "2.49 ans, z-score -2.78, percentile 0.3", "· fille" |
| K21 | Pass | Parent run: French on reload |
| K22 | Pass | Parent run: no chart with a blank age; WHO 2006 chart at 365 days |
| K23 | Pass | Parent run: WHO 2007 at 2000 and 1827 days, WHO 2006 at 1826, one chart at a time |
| K24 | Pass | Parent run: "9,6 kg at 11,99 months, z-score -0,04, percentile 48,3"; the 7,0 note writes the z-score with a point |
| K25 | Recorded | Parent run: REDCap refuses "9.6" on the comma field, as in C6 |
| K26 | Pass | Parent run: starts closed; z axis -4 to 4; 3 and 97 lines at z ±1.88 |
| K27 | Pass | Parent run: 4 curve sets with both sexes; `growth` holds 1827 and 62 rows per sex |
| K28 | Pass | XE-1 on 380: "WHO Child Growth Standards (2006) · girl", tooltip "This visit: 9.6 kg at 11.99 months, z-score 0.57, percentile 71.6", table row 365, 9.6, 0.57, 71.6. The off-chart arrow is drawn hidden. The config holds the same 4 curve sets as K27, each with `male` and `female` |
| K29 | Pass | Parent run: boy's curves at once; reference values 7.8, 9.6, 11.8 at 365.25 days |
| K30 | Pass | Parent run: amber note naming `ucg_sex, ucg_dob`; the save went through |
| K31 | Pass | Parent run: XE-2's blank-sex notes; no curve sets in the config |
| K32 | Pass | Parent run: XE-3 "· female", `female` only |
| K33 | Pass | `ucr_xev`: "⚠ ID-check configuration error: @UVRANGE on "ucr_xev": a "sex" field in another event or instance needs event and instance references — enable them in project settings first. a "age" "dob" field in another event or instance needs …". `ucr_weight`: "· female", line names 97, 50, 3, tooltip "This visit: 9.6 kg at 11.99 months, z-score 0.57, percentile 71.6", row 365, 9.6, 0.57, 71.6. The page's curve set holds `female` only. Saved |
| K34 | Pass | Instance 2: tooltip "This visit: 8.0 kg at 17.94 months, z-score -2.05, percentile 2.0", row 546, 8.0, -2.05, 2.0, and "⚠ This value is lower than usual (z-score -2.05 on Weight-for-age, WHO 2006 (birth to 5 years), in kg; expected z-score -2 to 2). (worked out with ucg_sex, ucg_dob, read when this page was opened — reload if it has changed since. This check does not block saving; it is re-checked after the save.)". The save asked nothing. Instance 1 still reads 9.6 at 11.99 months |
| K35 | Not run | The module refused to switch the setting on (below). Offline, `growth_chart_gaps_dom_js` checks K35's heading, tooltip and note, and `growth_chart_gaps_module_php` checks its config |
| K36 | Pass | Charts drawn on `uv_chart_gaps` (351, four charted fields), `uv_chart_repeat` (380, instances 1 and 2) and `uv_chart_saved` (351). No Content Security Policy error and no engine error in the console. The saved forms hold only typed values: `uv_chart_gaps` on 380 holds sex, date of birth and visit date; the repeat instances hold `ucr_visit` and `ucr_weight`, with `ucr_xev` blank; `uv_chart_saved` on 351 holds the parent run's `ucs_visit` and `ucs_weight` |

### K35: why it could not run

In the module's Configure dialog, ticking "Enable event and instance references" and pressing
Save returned:

> The configuration was NOT saved — fix these problems first:
> - xn_unknown_event: Unknown event reference: nosuch_arm_1.
> - xn_inst_nonrep: Instance selector targets a non-repeating instrument in event_1_arm_1.
> - xn_arm_empty: Binding x names arm 9, where no event collects this instrument.

This is the module working as built. `validateSettings` (UniversalValidator.php:5486) runs
`temporalActivationProblems` (php/TemporalIntegration.php:13) when the setting goes from off
to on, and that check reads every annotation in the project. The three `xn_*` fields are
negative fixtures that carry broken event references on purpose, so pid 149 cannot turn the
setting on while they are in the dictionary. Nothing was stored: `get-settings.php` shows the
setting unset, and the dialog was cancelled. The user chose to skip K35 rather than remove the
three fields for the step. Setup step 6 of the run sheet now says this (e50a093).

## Defects

No defect was found in K18 to K36. These come from the parent's run of K1 to K17.

All three are fixed in b82f3f1 on local `main`; the CHANGELOG's "Unreleased" section has the
details.

### F1 (Medium): a chart of the median alone cuts off usual values

Repro on rc.2: a rule whose chart draws only the median, such as `ugc_head` with
`"lines":{"z":[0]}`. The y axis fits the lines drawn, so it hugs the median. A girl of 12
months with a head circumference of 46.0 cm (z 0.81) drew on the top frame, and 47.0 cm
(z 1.55) drew as the "outside the chart" arrow.

Fix: `GrowthCurve::build` returns the reference at z -2 and 2 (`GrowthCurve::EXTENT_Z`) per
sex as `extent`, leaving out a side the drawn curves already reach. The engine adds it to the
lines the y axis is sized from and does not draw it. Tests: `tests/growth_curve_php.php`,
`tests/growth_chart_module_php.php` (a `median` page) and `tests/growth_chart_dom_js.cjs`
(points at z 1.5 and -1.5 at 10 and 365 days inside the plot; without `extent` the point at
1.5 and 365 days is the arrow). Charts of percentiles 3 to 97 carry the extent too, since
their outer lines sit at z ±1.88. That is why the gaps fixture changed in the merge: the
sets of `ucg_comma` and `ucr_weight` (percentiles 3, 50 and 97) gained `extent`, and their
lines were simplified again against the wider y range. Sets with the band at z -2 and 2,
such as `ucg_mlm`'s, send none.

### L1 (Low, open since rc.1): the save dialog reads one label as two fields

The "Validation FAILED for:" confirm and the "Cannot save yet" alert joined labels with ", ".
Each label now has its own line after a bullet. Test: `tests/silent_change_dom_js.cjs`.

### L2 (Low): a page table's caption sits under the rows

REDCap 17's Bootstrap sets `caption-side: bottom`, so the caption read like a footer. The
engine sets the caption's `captionSide` to `top` through its `style` object. Test:
`tests/growth_chart_dom_js.cjs`.

## Offline checks

Run on a tree whose code, tests and fixtures match `main` at 2a15ff1 (it differs only in
the CHANGELOG and this report):

- All 61 PHP suites pass on PHP 8.3.32 and on PHP 7.4.33, and all 40 JavaScript suites pass
  on Node 24.14.
- `tests/growth_chart_gaps_module_php.php`: 29 checks on each PHP version.
  `tests/growth_chart_gaps_dom_js.cjs`: 51 checks.
- `tests/growth_chart_module_php.php`: 119 checks; `tests/growth_curve_php.php`: 145;
  `tests/growth_chart_dom_js.cjs`: 134.

## Other observations

- The growth note of a comma field writes the z-score with a point ("z-score -2.90") while the
  chart and the table use the comma. The server does this on purpose: growth limits are
  z-scores, plain numbers whatever the field's decimal mark. K24 says so.
- The configuration error of K33 reads `a "age" "dob" field`. It is grammatical slack in an
  existing message, not part of the chart work, and was left as it is.
- On the dictionary upload page REDCap logs its own console error, "Uncaught TypeError:
  document.form.submit is not a function", from the page's own code. The upload and commit
  worked. It comes from REDCap, not the module.

## Changes made to pid 149, and their reversal

All with the user's approval on 2026-10-21. Times are from REDCap's Logging.

1. 16:09: downloaded the dictionary as the backup: 49,277 bytes with the BOM (SHA-256
   `6e9f8957…c851`), 49,226 characters without it (`d2799dce…7db5`, the same as the parent's
   and the rc.1 backup). Kept in the browser's local storage as
   `uv_dd_backup_pid149_chartgaps_2026-10-21`.
2. 16:13: uploaded the dictionary with the 14 fixture rows appended. REDCap reported no errors
   or warnings, 196 fields replacing 182. The dictionary downloaded afterwards starts with the
   backup unchanged; the new rows differ from the CSV only where REDCap quotes text cells and
   puts a space before an annotation.
3. 16:15: designated `uv_chart_gaps` on `event_1_arm_1` (351) and `visit_1_arm_1` (380), and
   `uv_chart_repeat` on 380.
4. 16:16: made `uv_chart_repeat` a repeating instrument on `visit_1_arm_1`. The other repeat
   settings stayed as they were: 379 `xr_specimen`; 380 `xr_specimen`, `xr_result`;
   381 `xr_result`; 382 the whole event.
5. 16:18: saved `uv_chart_gaps` for XE-1 on 380 (Female, 2025-01-10, 2026-01-10). 16:19: saved
   `uv_chart_repeat` for XE-1 on 380, instance 1 (2026-01-10, 9.6) and instance 2 (2026-07-10,
   8.0). XE-1 on 351 already held the Male values the parent run saved: REDCap kept them while
   the fields were out of the dictionary.
6. Tried to switch on "Enable event and instance references". The module refused (K35);
   nothing was stored.
7. 16:25: designated `uv_chart_saved` on 351 for K36, which the user approved during the run.

No MLM setting, survey setting or other module setting was changed in this session.

Reversal: at 16:26 the backup was uploaded and committed, 182 fields replacing 196. The
dictionary downloaded afterwards has SHA-256 `d2799dce…7db5`, the backup's. The designation
page lists none of the three forms, the repeat settings read as before step 4, and the event
and instance references setting is off.

Left behind:

- The values saved in the removed fields (XE-1: `uv_chart_gaps` on 351 and 380,
  `uv_chart_saved` on 351, `uv_chart_repeat` instances 1 and 2 on 380; XE-3: `uv_chart_gaps`
  on 351). REDCap keeps them out of sight, and they come back if the fixture is added again.
  Setup step 5 of the run sheet warns about this.
- The backup in the browser's local storage, next to the parent's older keys.

## Not covered

- K35 on the server (above).
- F1, L1 and L2 on the server: they need a build of `main` deployed, then run sheet K6 and
  K12 (which b82f3f1 updated), a table caption and a save with a failed check.
- The module's own audit log entries for this session's saves were not read.
