# @UVDELTA live acceptance protocol

Live test of change limits on a real REDCap. The automated suites
(`tests/delta_php.php`, `tests/delta_js.cjs`, `tests/delta_dom_js.cjs`,
`tests/delta_module_php.php`) prove the logic against stubs. This run checks
real number, comma-decimal, calc and slider fields, real events and repeating
instances, the browser's save dialog, the module log and the Validation scan.

Target: chpr-redcap.org pid 149 (longitudinal, repeating, seed records XE-1 to
XE-4). The fixture is two files:

- [`uvdelta_test_fields.csv`](uvdelta_test_fields.csv), 27 fields on one new
  instrument `uv_delta_test`. 14 carry a valid `@UVDELTA`, the 6 `ud_bad_*`
  fields carry a broken one, and 7 hold a value compared with or the `when`
  switch (`ud_w1`, `ud_sbp1`, `ud_ht0`, `ud_pct0`, `ud_c0`, `ud_pain0`,
  `ud_grp`).
- [`uvdelta_test_fields_xr_specimen.csv`](uvdelta_test_fields_xr_specimen.csv),
  one field, `ud_rep`, for the existing repeating instrument `xr_specimen`.
  It is a file of its own because REDCap wants the fields of one instrument
  next to each other in the dictionary: appended at the end, after
  `uv_delta_test`, it would split `xr_specimen` in two and the upload would
  fail.

Every "Expect" below is the module's own answer. `tests/delta_live_twin_php.php`
runs the real module over the fixture on a copy of pid 149's events, forms and
repeats with XE-1's seed values. It checks each configuration error word for
word, replays each save of this sheet in order and checks every log entry it
writes, and runs the scan. `tests/delta_live_twin_js.cjs` replays the typing
through `js/engine.js` with the page configs the module built
(`tests/delta_live_pages.json`) and checks each note, each save and the label
the save dialog names, word for word. Both run in CI; run them after any
change to the module, the engine or this sheet:

```bash
php tests/delta_live_twin_php.php && node tests/delta_live_twin_js.cjs
```

---

## Setup

1. Deploy the release that contains `@UVDELTA` and confirm the version in
   Control Center.
2. Append, never replace. Download the current dictionary. Paste the 27 rows
   of `uvdelta_test_fields.csv` after its last row. Paste the row of
   `uvdelta_test_fields_xr_specimen.csv` right after the last `xr_specimen`
   row, not at the end. Upload. (Or upload the 27 rows and add `ud_rep` to
   `xr_specimen` in the Online Designer, with the validation and the Action
   Tag of its CSV row.) The diff must report one new instrument with 27 new
   fields, one new field on `xr_specimen`, and nothing else.
3. Designate `uv_delta_test` on `baseline_arm_1` (379), `visit_1_arm_1` (380)
   and `visit_2_arm_1` (381). Do not designate it on `event_1_arm_1`.
4. Sections A, B and F run with **Enable event and instance references** off.
   Sections C and D need it on. On pid 149 the setting cannot be switched on
   while the negative fixtures `xn_unknown_event`, `xn_inst_nonrep` and
   `xn_arm_empty` are in the dictionary (the activation check refuses them on
   purpose). Remove those three rows before section C, with the project
   owner's agreement, or skip C and D.
5. Form URLs, record XE-1:
   - `DataEntry/index.php?pid=149&id=XE-1&event_id=380&page=uv_delta_test` (visit 1)
   - the same with `event_id=379` (baseline) and `event_id=381` (visit 2)
   - `DataEntry/index.php?pid=149&id=XE-1&event_id=379&page=xr_specimen&instance=2`

XE-1's seed has `xv_weight` (form `xe_visit`) 70 at baseline, 72 at visit 1
and 74 at visit 2.

Read the injected config at any point with:

```js
JSON.parse(document.getElementById('inspire-validator-config').textContent)
```

A "note" below is the message under the field. Red notes start with ✗.
"Leave the field" means Tab out of it or click elsewhere. Fields validated as
"Number (1 decimal place)" take `70.0`, not `70`; the comma-decimal fields take
`70,0`.

---

## Section A: one page (references off), visit 1

| # | Action | Expect |
|---|---|---|
| A1 | `ud_w1` = 70.0, `ud_w2` = 70.2, leave | No note under `ud_w2`: a change of 0.2 is the limit, and limits are inclusive |
| A2 | `ud_w2` = 70.3, leave; then Save | "✗ This value rose by 0.3 kg (0.4%) from 70.0 kg ([ud_w1]). Allowed: a change of at most 0.2 kg." Save is blocked and the dialog names "Second weighing (kg)" |
| A3 | `ud_w1` = 70.5, leave | Without touching `ud_w2`, its note goes away (70.3 is 0.2 below 70.5) |
| A4 | `ud_sbp1` = 120, `ud_sbp2` = 145, leave; Save | "✗ The two readings differ by more than 20 mmHg. Take a third." Save asks "save anyway?". Cancel, then set `ud_sbp2` = 125 |
| A5 | `ud_ht0` = 150.0, `ud_ht` = 149.9, leave; Save | "✗ This value fell by 0.1 cm (0.1%) from 150.0 cm ([ud_ht0]). Allowed: no fall." Save is blocked |
| A6 | `ud_ht` = 152.0, leave; Save | No note under `ud_ht` (a rise passes). The calc `ud_htcalc` shows 152 and "✗ This value rose by 2 (1.3%) from 150.0 ([ud_ht0]). Allowed: a change of at most 0.5." The record saves. A calc never blocks |
| A7 | `ud_pct0` = 3, `ud_pct` = 3.5, leave | "✗ This value rose by 0.5 (16.7%) from 3 ([ud_pct0]). Allowed: a change of at most 2 and 15%." (within 2, past 15%) |
| A8 | `ud_pct` = 2.9; then `ud_pct0` = 0 and `ud_pct` = 1; then `ud_pct` = 3 | No note; no note (an earlier 0 has no percent, and 1 is within 2); "✗ This value rose by 3 from 0 ([ud_pct0]). Allowed: a change of at most 2 and 15%." with no percent. Then clear `ud_pct` |
| A9 | `ud_c0` = 70,0, `ud_c` = 72,6, leave; then `ud_c` = 72,5 | "✗ This value rose by 2,6 kg (3,7%) from 70,0 kg ([ud_c0]). Allowed: a change of at most 2,5 kg."; then no note |
| A10 | Slider `ud_pain0` to 2, `ud_pain` to 7; Save | "✗ This value rose by 5 (250%) from 2 ([ud_pain0]). Allowed: a change of at most 3." The slider writes its value from script; the note still comes. Save asks. Cancel, then set `ud_pain` to 4 |
| A11 | `ud_two` = 130, leave; Save, answer "save anyway" | One line: "✗ This value rose by 59.5 (84.4%) from 70.5 ([ud_w1]). Allowed: a change of at most 5%." The record saves |
| A11b | `ud_two` = 150, leave; Save | Two lines, the second "✗ This value rose by 30 (25%) from 120 ([ud_sbp1]). Allowed: a change of at most 15%." Save is blocked (the 15% line is `hard`). Clear `ud_two` |
| A12 | `ud_grp` = Check, `ud_when` = 80, leave; Save | "✗ This value rose by 9.5 (13.5%) from 70.5 ([ud_w1]). Allowed: a change of at most 1." Save is blocked |
| A12b | `ud_grp` = Skip; Save | The note goes away and the record saves |
| A13 | `ud_sbp2` = 145; Save, answer "save anyway" | The record saves |
| A14 | Module log (External Modules → View Logs) | `invalid-id-saved` entries with `type: delta`, all at event 380, instance 1. Each save that went through logs every finding left on the form: A6 `ud_htcalc` reason `up`, `vs` `[ud_ht0]`. A11 `ud_htcalc` `up` `[ud_ht0]` and `ud_two` `up` `[ud_w1]`. A12b `ud_htcalc` `up` `[ud_ht0]` only (`ud_two` is blank and `ud_when` is off). A13 `ud_htcalc` `up` `[ud_ht0]` and `ud_sbp2` `up` `[ud_sbp1]`. The saves the sheet held or cancelled (A2, A4, A5, A10, A11b, A12) log nothing |

## Section B: a value of another form (references off), visit 1

| # | Action | Expect |
|---|---|---|
| B1 | `ud_snap` = 74, leave | "✗ This value rose by 2 kg (2.8%) from 72 kg ([xv_weight]). Allowed: a change of at most 1 kg. (compared with xv_weight, read when this page was opened — reload if it has changed since. This check does not block saving; it is re-checked after the save.)" |
| B2 | Save; the dialog asks "save anyway?" for `ud_sbp2` alone (left at 145 in A13): answer yes | The record saves although `ud_snap`'s rule says `hard`. Log at event 380: `ud_htcalc` `up` `[ud_ht0]`, `ud_sbp2` `up` `[ud_sbp1]` and `ud_snap` `up` `[xv_weight]` |
| B3 | Open `xe_visit` at visit 1, set `xv_weight` = 73.5, Save. Then set it back to 72, Save | First save: no `type: delta` entry at all (74 is within 1 kg of 73.5, and a save of `xe_visit` re-checks only the rules that read it). Second: one entry, `ud_snap` `up` `[xv_weight]`. Saving the form that holds the `vs` re-checks the rule |

## Section C: other events (references on)

| # | Action | Expect |
|---|---|---|
| C0 | Before switching the setting on, open visit 1 | Configuration errors: `ud_wt` '"vs": "previous-event" reads another event or instance, which needs event and instance references — enable them in project settings first.' (`ud_height`, `ud_twoev` and `ud_bad_inst` the same, with their shorthand); `ud_bl` 'a "vs" value in another event or instance needs event and instance references — enable them in project settings first.' Now switch the setting on (see Setup 4) and reload: the four rule fields configure |
| C1 | Baseline (379): `ud_wt` = 70, `ud_height` = 170.0, `ud_bl` = 70, `ud_twoev` = 70; Save | No note and no `type: delta` entry. `ud_wt`, `ud_height` and `ud_twoev`: baseline is the first event that has `uv_delta_test`, so there is nothing earlier. `ud_bl` is compared with `xv_weight` at baseline, which is 70 too, so there is no change |
| C2 | Visit 1 (380): `ud_wt` = 78, `ud_height` = 169.4, `ud_bl` = 85, `ud_twoev` = 74, leaving each | `ud_wt`: "✗ This value rose by 8 kg (11.4%) from 70 kg (the previous event). Allowed: a change of at most 10%." `ud_height`: "✗ This value fell by 0.6 cm (0.4%) from 170.0 cm (the first event). Allowed: a fall of at most 0.5 cm." `ud_bl`: "✗ This value rose by 15 (21.4%) from 70 ([baseline_arm_1][xv_weight]). Allowed: a change of at most 20%." `ud_twoev`: one line, "… rose by 4 (5.7%) from 70 (the previous event). Allowed: a change of at most 5%." Each ends with "(compared with saved event/instance values, read when this page was opened — … re-checked after the save.)" |
| C3 | Save; the dialog asks "save anyway?" for `ud_sbp2` alone, as in B2: answer yes | The record saves, although `ud_wt` says `hard`. Seven entries, all at event 380: `ud_wt` `up` `[previous-event-name][ud_wt]`, `ud_height` `down` `[first-event-name][ud_height]`, `ud_bl` `up` `[baseline_arm_1][xv_weight]`, `ud_twoev` `up` `[previous-event-name][ud_twoev]`, and the three findings left from A and B: `ud_htcalc` `up` `[ud_ht0]`, `ud_sbp2` `up` `[ud_sbp1]`, `ud_snap` `up` `[xv_weight]` |
| C4 | Visit 2 (381): `ud_wt` = 80, `ud_height` = 170.0, `ud_twoev` = 82; Save | `ud_twoev` shows two lines: "… rose by 8 (10.8%) from 74 (the previous event) …" and "… rose by 12 (17.1%) from 70 (the first event). Allowed: a change of at most 15%. …". No note under `ud_wt` (80 is 2.6% above 78) or `ud_height` (no fall from 170.0). The record saves. Six entries: at event 381, `ud_twoev` `up` `[previous-event-name][ud_twoev]` and `ud_twoev` `up` `[first-event-name][ud_twoev]`; at event 380, which the save re-checks because its rules read other events, `ud_wt`, `ud_height`, `ud_bl` and `ud_twoev` as in C3. The rules of event 380 that read only their own entry (`ud_htcalc`, `ud_sbp2`, `ud_snap`) are not re-checked |
| C5 | Baseline: `ud_wt` = 76; Save | The save re-checks visit 1 and visit 2. Five entries: at event 380, `ud_height` `down` `[first-event-name][ud_height]`, `ud_bl` `up` `[baseline_arm_1][xv_weight]` and `ud_twoev` `up` `[previous-event-name][ud_twoev]`, and no longer `ud_wt` (78 is 2.6% above 76); at event 381, the two `ud_twoev` entries of C4 |

## Section D: repeating instances (references on)

| # | Action | Expect |
|---|---|---|
| D1 | `xr_specimen` at baseline, instance 1: `ud_rep` = 10.0; Save | No note and no entry. The first instance has nothing earlier |
| D2 | Instance 2: `ud_rep` = 7.5, leave; Save | "✗ This value fell by 2.5 (25%) from 10.0 (the previous instance). Allowed: a fall of at most 20%. (compared with saved event/instance values …)". The record saves. One entry: `ud_rep` `down` `[ud_rep][previous-instance]`, event 379, instance 2 |
| D3 | Instance 2: set `ud_rep` = 8.0 and leave; then reload without saving | Once the field is left, no note: 8.0 is exactly 20% below 10.0, and limits are inclusive. After the reload, instance 2 still holds 7.5 |

## Section E: the Validation scan

| # | Action | Expect |
|---|---|---|
| E1 | Run the scan with "Enable event and instance references" on | Nine `@UVDELTA` findings for XE-1: event 380 `ud_bl` (Rose more than allowed), `ud_height` (Fell more than allowed), `ud_htcalc`, `ud_sbp2`, `ud_snap`, `ud_twoev`; event 381 `ud_twoev` twice; event 379 instance 2 `ud_rep` (Fell more than allowed) |
| E2 | The detail lines | `ud_height`: "The value fell more than allowed from the value it is compared with. Allowed a fall of at most 0.5 cm from the first event." `ud_sbp2`: "The two readings differ by more than 20 mmHg. Take a third. Allowed a change of at most 20 mmHg from [ud_sbp1]." |
| E3 | Rule problems | The six `ud_bad_*` fields are listed as rule problems, never as data |

## Section F: configuration errors

Open visit 1. Each `ud_bad_*` field shows its error under the field. A broken
rule shows a red note but never blocks a save:

| Field | Error |
|---|---|
| `ud_bad_email` | @UVDELTA needs a number field — give this Text field integer or number validation, or none (it has "email"). |
| `ud_bad_self` | "vs" names this field itself — compare it with another entry, e.g. "vs":"previous-event" or "vs":"[baseline_arm_1][ud_bad_self]". |
| `ud_bad_vs` | "vs" field "xv_date" is not a number field — it needs integer or number validation, or none, or to be a calc or a slider. |
| `ud_bad_nofield` | "vs" names "[ud_nope]", which is not a field in this project. |
| `ud_bad_nolimit` | @UVDELTA needs a limit: "max" (the most the value may change, in its own unit) or "maxPct" (the most it may change as a percent of the earlier value), e.g. "maxPct":10. |
| `ud_bad_inst` | References off: "vs": "previous-instance" reads another event or instance, which needs event and instance references — enable them in project settings first. References on: "vs": "previous-instance" needs a form that repeats — "uv_delta_test" does not repeat at "baseline_arm_1", "visit_1_arm_1" and "visit_2_arm_1". |

Each error starts with `@UVDELTA on "<field>": `.

---

## Recording the run

Copy this table into `reports/deploy/LIVE_TEST_<version>_delta.md` with a
column for what was seen, and note the REDCap version, the browser and every
difference from "Expect".
