# @UVJUSTIFY live acceptance protocol

Live test of reasons for a broken rule on a real REDCap. The automated suites
(`tests/justify_php.php`, `tests/justify_js.cjs`, `tests/justify_dom_js.cjs`,
`tests/justify_module_php.php`) prove the logic against stubs. This run checks
the real text, notes, dropdown, radio and SQL fields as reason fields, the
"M" button of a Missing Data Code, branching logic that hides a reason, a
read-only field, the save alert and the "Save anyway?" dialog, the Enter key,
the duplicate check and the write-once lock beside a reason, a survey of five
pages, the Data Import Tool, the module log and the Validation scan. Section J
checks what REDCap does that the code depends on.

Target: chpr-redcap.org pid 149 (longitudinal, seed records XE-1 to XE-4). The
fixture is [`uvjustify_test_fields.csv`](uvjustify_test_fields.csv), 47 fields
on one new instrument `uv_justify_test`, in five sections:

- Section A of the form pairs one field per rule a reason stands for with its
  reason field: `uj_req` (`@UVREQUIRED`), `uj_wt` (`@UVRANGE`), `uj_id`
  (`@UVALIDATE`), `uj_pool` (pooled `@UVALIDATE`), `uj_dia` (`@UVASSERT`),
  `uj_dose` (`@UVCHOICES`), `uj_visit` (`@UVWINDOW`, with its own note in two
  languages), `uj_wt2` (`@UVDELTA`) and `uj_mother` (`@UVEXISTS`). Then
  `uj_temp` asks "Save anyway?" (blockSave `confirm`, the soft mode),
  `uj_off` only warns (blockSave `off`), `uj_hr` has its reason in a row
  that branching logic hides, `uj_ro` is read-only, `uj_emb` has its reason
  field `uj_emb_why` embedded in the label of the line below it
  (`uj_emb_box`), and `uj_rr` names an SQL reason field that only an
  administrator can add (J4).
- The reason fields cover each type a reason may sit in: text (`uj_wt_why`
  and nine more), notes (`uj_pool_why`, `uj_visit_why`), dropdown
  (`uj_dia_why`), radio (`uj_req_why`, `uj_wt2_why`) and SQL (`uj_sql_why`,
  added in J4). `uj_sys`, `uj_arm`, `uj_anchor`, `uj_hr_show` and the
  descriptive `uj_emb_box` carry no rule.
- Section B of the form puts a reason beside `@UVUNIQUE` (`uj_code`) and
  beside `@UVWRITEONCE` (`uj_lock`). A reason releases neither.
- Sections C and D hold `uj_far` and its reason, one survey page each.
- Section E holds the checkbox `uj_signs` and six refused tags `uj_bad_*`.

Every "Expect" in sections A to H and in J2 to J4 is the module's own answer.
`tests/justify_live_twin_php.php` runs the real module over the fixture on a
copy of pid 149's events and forms, with REDCap's data and log kept as each
save and import of this sheet leaves them. It checks each configuration error
word for word, the rules each page carries, every log entry of each save, the
lookup and duplicate answers, and both scans. `tests/justify_live_twin_js.cjs`
replays the clicks and typing through `js/engine.js` with the page configs the
module built (`tests/justify_live_pages.json`) on a page shaped as REDCap
renders these field types. It checks each note, each hold and each line of the
alert and the dialog. Steps J1, J5, J6 and J7 read what REDCap itself does, and
each says what to record when REDCap does it another way. Both twins run in
CI; run them after any change to the module, the engine or this sheet:

```bash
php tests/justify_live_twin_php.php && node tests/justify_live_twin_js.cjs
```

---

## How a reason works, in brief

- `@UVJUSTIFY={"reason":"[uj_wt_why]"}` pairs a field with the field where the
  reason is written. While a rule on the field holds the save, a note under
  the field asks for the reason, and the save alert names the reason field.
  Once the reason field holds a reason, those rules let the save through.
  The rule's own note stays.
- A reason stands for `@UVALIDATE` (single and pooled), `@UVASSERT`,
  `@UVREQUIRED`, `@UVCHOICES`, `@UVWINDOW`, `@UVEXISTS`, `@UVRANGE` and
  `@UVDELTA`. It never stands for `@UVUNIQUE` or `@UVWRITEONCE` (section F).
- A reason is text that is not blank (spaces, a no-break space or a
  zero-width space alone are blank, as for `@UVREQUIRED`) and not a Missing
  Data Code, or a choice picked in a dropdown, radio or SQL field. On the page, a
  reason field whose row branching logic hides gives no reason. A reason
  field embedded in another field's label counts as shown while the row it
  sits in shows (C8). The audit and
  the scans read the saved value and never see branching (J2).
- A reason never adds a hold. A rule with blockSave `off` still only warns
  and gets no note. A field the person cannot change (read-only) or cannot see
  never holds the save, so no note asks for its reason (C7, F5).
- The reason field must be on the same form. On a survey page without it the
  tag does nothing, and the rule holds as it would alone (G3).
- Each save of the form audits all of it and logs each finding the module
  log does not hold yet. A finding whose reason is given is logged as
  `uvalidate-justified` with `justified_by` naming the reason field; an open
  one as `invalid-id-saved`. No entry holds the reason text. An import runs
  no module hook, so it logs nothing (J5).
- The durable scan counts findings saved with a reason apart from the open
  ones, and a run whose findings all have their reason is clean (H3).

---

## Setup

1. Deploy the release that contains `@UVJUSTIFY` and confirm the version in
   Control Center.
2. Append, never replace. Download the current dictionary, paste the 47 rows
   of `uvjustify_test_fields.csv` after its last row and upload. The diff must
   report one new instrument with 47 new fields and nothing else.
3. Designate `uv_justify_test` on `visit_1_arm_1` (380).
4. In Project Setup, Additional customizations, the project needs the Missing
   Data Code `UNK, Unknown` (C3 and H2). Add it for this run if the project
   has none, and remove it afterwards.
5. For section G: Online Designer, enable `uv_justify_test` as a survey, and
   in its survey settings set "Question display format" to one section per
   page. The fixture's five section headers make five pages.
6. The tester's account needs edit rights to `uv_justify_test`, the Data
   Import Tool, Project Design and Setup with read access to every form (the
   Validation scan asks for both), and the module log.
7. [only if the durable scan is enabled] H0: run the Validation scan now,
   before any save on the new form. Write down the finding count of its panel
   (F0) and the number in its line "N rules could not be evaluated" (R0). R0
   already counts the seven refused tags of A1.
8. Form URL, record XE-1:
   `DataEntry/index.php?pid=149&id=XE-1&event_id=380&page=uv_justify_test`

Read the injected config at any point with:

```js
JSON.parse(document.getElementById('inspire-validator-config').textContent)
```

Save with "Save & Stay", so the form opens again with what was saved. The
"reason note" below is the pale yellow box under a field that reads "Fix this
value, or say why it is right in “<label of the reason field>”." unless the
rule gives its own words. "Held" means REDCap does not save and an alert
opens with `Cannot save yet — please fix the flagged field(s):` and one line
per field. A line ends " (or give a reason in “<label>”)" while a reason can
still release that field.

The module log holds each finding once. A save writes an entry, with a
`fingerprint` and a `finding` key, for each finding whose newest entry in the
log does not already say the same. A finding found again (same record, field,
event, instance, type, `reason` code, value and `justified_by`) writes
nothing, so each Expect cell names the entries a save adds. A finding whose
value changes gets a new entry. One that gains or loses its reason moves
between `invalid-id-saved` and `uvalidate-justified` and gets a new entry each
time, also when it goes back to a kind of entry it had before (C7c, C7d). The
earlier entries stay as they were. The reason text is not part of
the comparison: a reworded reason writes nothing. By B9b the log holds nine
`uvalidate-justified` entries for XE-1. B1b logs the refused tags of A1, and
no later save writes a rule-problem entry (`uvalidate-unconfigurable`). The
entries below assume the log holds none from an earlier run of this sheet.

Write down the time of each save and import. The module log gives F6's lock
times to the minute, in the project's time zone. The twin uses 2026-10-26
09:00 for B1b, 09:05 for B2b, 09:10 for B3b, 09:15 for B4b, 09:20 for B5b,
09:25 for B6b, 09:30 for B7b, 09:35 for B8b, 09:40 for B9b, 09:45 for C4b,
09:50 for C5b, 09:52 for C6b, 09:53 for the import of C7 and 09:54 for its
save, 09:55 for C7b to C7e, 09:56 for C8b and C8c, 09:57 for D2, 10:00 for D3, 10:05 for D4, 10:10 for E2,
10:15 for F1b, 10:20 for F2, 10:25 for F3c, 10:30 for F4b, 10:35 for F5b,
10:40 for the import of F6 and 10:45 for its save, 10:50 to 10:54 for the
five survey pages, 11:00, 11:05 and 11:10 for the imports of H2, 11:15 and
11:20 for those of J2, and 11:25 for J4b.

---

## Section A: install and configuration errors

| # | Action | Expect |
|---|---|---|
| A1 | Open the form for XE-1 | Seven fields show their error under the field after "⚠ Validator configuration error: ", word for word as in the table below. No other field shows an error. Under "Required field": "✗ This field must not be left blank." and the reason note naming “Why it is blank”. No other field has a reason note |
| A2 | Run the config snippet above | Each of the 16 fields `uj_req`, `uj_wt`, `uj_id`, `uj_pool`, `uj_dia`, `uj_dose`, `uj_visit`, `uj_wt2`, `uj_mother`, `uj_temp`, `uj_off`, `uj_hr`, `uj_ro`, `uj_code`, `uj_lock` and `uj_far` has one rule with `"type":"justify"` and `"justifyReason"` naming its reason field (`uj_req_why` for `uj_req`, and so on). The rule on `uj_visit` carries `"message":"Say why this visit date is right."` and the French text under `i18n`. `missingCodes` is `["UNK"]`. The page source has no `justifyBy` |
| A3 | "Bare tag" = 11 and leave. Save. Clear it. "Respiratory rate" = 70 and leave. Save. Clear it | Under "Bare tag": "✗ This value is above the plausible range (allowed 0 to 10)." Held, with the line "• Bare tag" after the required field's line, offering no reason. Respiratory rate: "✗ This value is above the plausible range (allowed 5 to 60)." and no reason note; held with "• Respiratory rate (per minute)". A refused tag releases nothing, and the field's own rule still runs |

The seven errors:

| Field | Error |
|---|---|
| `uj_rr` | `@UVJUSTIFY on "uj_rr": "reason" names "[uj_sql_why]", which is not a field of this project.` |
| `uj_bad_bare` | `@UVJUSTIFY on "uj_bad_bare": @UVJUSTIFY needs the field that holds the reason, e.g. @UVJUSTIFY={"reason":"[weight_reason]"}.` |
| `uj_bad_form` | `@UVJUSTIFY on "uj_bad_form": "reason" names "[record_id]", which is on the form "xe_enrol", not on "uv_justify_test" with this field — the reason is given on the form where the value is entered.` |
| `uj_bad_type` | `@UVJUSTIFY on "uj_bad_type": "reason" names "[uj_signs]", a "checkbox" field — the reason is written in a Text or Notes field, or picked in a dropdown, radio or SQL field.` |
| `uj_bad_self` | `@UVJUSTIFY on "uj_bad_self": "reason" names this field itself — name the field where the reason is written, e.g. "[uj_bad_self_reason]".` |
| `uj_bad_none` | `@UVJUSTIFY on "uj_bad_none": no rule a reason can stand for checks this field — give it a rule of @UVALIDATE, @UVASSERT, @UVREQUIRED, @UVCHOICES, @UVWINDOW, @UVEXISTS, @UVRANGE or @UVDELTA (a reason does not stand for @UVUNIQUE or @UVWRITEONCE).` |
| `uj_bad_twice` | `field "uj_bad_twice" is given @UVJUSTIFY 2 times — this tag takes no "when" condition, so keep one.` |

`uj_rr` names an SQL reason field that does not exist yet. J4 adds it, and
the error goes.

## Section B: each rule a reason stands for (XE-1)

Each step breaks one rule, checks the notes and the hold, then gives the
reason and saves. Each reason stays for the rest of the run. Each reopened
form saves as it opens, since every reason saved before still stands.

| # | Action | Expect |
|---|---|---|
| B1 | Save with nothing typed | Held, one line: "• Required field (or give a reason in “Why it is blank”)" |
| B1b | "Why it is blank" = Not done. Save | The reason note goes and "✗ This field must not be left blank." stays. The record saves. The module log gains `uvalidate-justified` for `uj_req`, with type `required`, reason `required-blank`, `justified_by` `uj_req_why`. Its only rule problems are one `uvalidate-unconfigurable` entry for each refused tag of A1 (`uj_rr` and the six `uj_bad_*` fields), its `why` starting "configuration error:". This first save of the form logs them, and no later save logs them again |
| B2 | "Weight" = 300.0 and leave | "✗ This value is above the plausible range (allowed 30 to 250 kg)." and the reason note "Fix this value, or say why it is right in “Reason for change”." Save is held: "• Weight (or give a reason in “Reason for change”)" |
| B2b | "Reason for change" = Scale checked twice. Save | The reason note goes; the range note stays. The record saves. New entry `uvalidate-justified` for `uj_wt`, with type `range`, reason `hard-high`, `justified_by` `uj_wt_why`, `event_id` 380, `instance` 1, `value` 300.0, a `fingerprint` of 64 hex digits and a `finding` key of 64 hex digits that differs from it. No entry holds "Scale checked twice" |
| B3 | "Lab number" = 79927398710 and leave | `✗ This ID's check character does not match — probably a typo or mis-scan. Please re-scan or re-type it.` and the reason note naming “Why the lab number is right”. Held: "• Lab number (Luhn check digit) (or give a reason in “Why the lab number is right”)" |
| B3b | "Why the lab number is right" = Number printed on the tube. Save | The reason note goes. The record saves. New entry for `uj_id`, with type `single`, reason `check-character`, `justified_by` `uj_id_why` |
| B4 | "Pooled lab numbers" = `79927398713 79927398710` and leave | `2 IDs read — 1 check-character mismatch`, with ✓ 79927398713 and ✗ 79927398710, and the reason note naming “Why the pooled numbers are right”. Held: "• Pooled lab numbers (or give a reason in “Why the pooled numbers are right”)" |
| B4b | In the notes box "Why the pooled numbers are right" type Second tube relabelled, Enter, at the lab (two lines). Save | The reason note goes. The record saves. New entry for `uj_pool`, with type `pooled`, reason `check-character`, `justified_by` `uj_pool_why`. Neither line of the reason is in any entry |
| B5 | "Systolic pressure" = 80, "Diastolic pressure" = 90, leave | "✗ The diastolic pressure must be below the systolic." and the reason note naming “Why the pressures are right”. Held: "• Diastolic pressure (mmHg) (or give a reason in “Why the pressures are right”)" |
| B5b | "Why the pressures are right" = Re-measured, same result. Save | The reason note goes. The record saves. New entry for `uj_dia`, with type `constraint`, reason `assert:[uj_dia]<[uj_sys]`, `justified_by` `uj_dia_why` |
| B6 | "Age group" = Adult, "Dose" = 500 mg, then "Age group" = Child | Dose keeps 500 mg with `✗ The selected choice is no longer available while: [uj_arm]='2' — pick one of the choices shown (the current answer is kept until you do).` and the reason note naming “Why this dose”. Held: "• Dose (or give a reason in “Why this dose”)" |
| B6b | "Why this dose" = Dose by weight. Save | The reason note goes. The record saves. New entry for `uj_dose`, with type `choices`, reason `hidden-choice`, `justified_by` `uj_dose_why` |
| B7 | "Screening date" = 2026-10-01, "Visit date" = 2026-10-20, leave | "✗ This date must be between 2026-10-01 and 2026-10-15. (0 to 14 days after [uj_anchor])" and the rule's own reason note "Say why this visit date is right." Held: "• Visit date (or give a reason in “Why the visit date is right”)" |
| B7b | "Why the visit date is right" = Clinic closed for a week. Save | The reason note goes. The record saves. New entry for `uj_visit`, with type `window`, reason `window-late`, `justified_by` `uj_visit_why` |
| B8 | "Second weighing" = 302.5 and leave | `✗ This value rose by 2.5 kg (0.8%) from 300.0 kg ([uj_wt]). Allowed: a change of at most 1 kg.` and the reason note naming “Why the two weighings differ”. Held: "• Second weighing (kg) (or give a reason in “Why the two weighings differ”)" |
| B8b | "Why the two weighings differ" = Another scale. Save | The reason note goes. The record saves. New entry for `uj_wt2`, with type `delta`, reason `up`, `justified_by` `uj_wt2_why` |
| B9 | "Mother's record ID" = XE-2 and leave. Then XE-9 and leave | With XE-2 there is no ✗ note and no reason note, since XE-2 is a saved record. With XE-9, "✗ This value is not saved where it should be. Check it, or record it there first." and the reason note naming “Why the mother has no record”. Held: "• Mother's record ID (or give a reason in “Why the mother has no record”)" |
| B9b | "Why the mother has no record" = Mother enrolled at another site. Save | The reason note goes. The record saves. New entry for `uj_mother`, with type `exists`, reason `not-found`, `justified_by` `uj_mother_why`. The log now holds nine `uvalidate-justified` entries for XE-1, one for each step from B1b to B9b |

## Section C: what is no reason (XE-1)

C1 to C4 change the page only, and each puts the reason back.

| # | Action | Expect |
|---|---|---|
| C1 | Clear "Reason for change" and leave. Save. Type Scale checked twice again | The reason note is back under Weight. Held: "• Weight (or give a reason in “Reason for change”)". With the reason back the note goes |
| C2 | Type three spaces in "Reason for change" and leave. Save. Put the reason back | As C1 |
| C3 | Click the "M" button beside "Reason for change" and choose UNK. Save. Clear the code and put the reason back | The box shows UNK (J1). As C1, since a Missing Data Code is no reason |
| C4 | "Why the pressures are right" = the blank choice. Save. Choose Re-measured, same result again | The reason note is back under Diastolic pressure. Held: "• Diastolic pressure (mmHg) (or give a reason in “Why the pressures are right”)". With the choice back the note goes |
| C4b | Save | The record saves and writes no entry: each finding on the form is one the log holds |
| C5 | "Heart rate" = 250 and leave. Save. Then "Explain the heart rate?" = Yes, and type Athlete, resting rate checked in "Why the heart rate is right" | "✗ This value is above the plausible range (allowed 30 to 220)." and the reason note naming “Why the heart rate is right”, a row the page still hides. Held: "• Heart rate (bpm) (or give a reason in “Why the heart rate is right”)". Yes shows the reason box and the note stays until the reason is typed |
| C5b | Save | The record saves. New entry for `uj_hr`, with type `range`, reason `hard-high`, `justified_by` `uj_hr_why` |
| C6 | "Explain the heart rate?" = No. If REDCap asks whether to erase the value of "Why the heart rate is right", click OK and write down that it asked. Save | The reason row hides. The reason note is back under Heart rate at once, with no other click, whether or not the text was erased. Held, the line of C5 |
| C6b | "Explain the heart rate?" = Yes. If C6 erased the reason, type Athlete, resting rate checked again. Save | Once the reason shows with its text the note goes. The record saves and writes no entry |
| C7 | Data Import Tool, CSV `record_id,redcap_event_name,uj_ro` with the row `XE-1,visit_1_arm_1,300.0`. Import. Reopen the form. Save | "Weight from the device" is read-only and shows "✗ This value is above the plausible range (allowed 30 to 250 kg)." No reason note, because the person cannot change the field here and it never holds the save. The record saves. New entry `invalid-id-saved` for `uj_ro`, with type `range`, reason `hard-high`, no `justified_by` |
| C7b | "Why the device weight is right" = Device recalibrated. Save | Still no reason note; the range note stays. The record saves. New entry `uvalidate-justified` for `uj_ro`, with `justified_by` `uj_ro_why`. C7's `invalid-id-saved` entry stays in the log |
| C7c | Clear "Why the device weight is right". Save | Still no reason note. The record saves. New entry `invalid-id-saved` for `uj_ro`, with type `range`, reason `hard-high`, no `justified_by`, though C7 wrote one like it: the newest entry for the finding said it had a reason |
| C7d | "Why the device weight is right" = Device recalibrated. Save | The record saves. New entry `uvalidate-justified` for `uj_ro`, with `justified_by` `uj_ro_why`, as in C7b |
| C7e | Save | The record saves and writes no entry: the newest entry for `uj_ro` already says what this save would |
| C8 | "Weight on the second scale" = 300.0 and leave. Save. Then type Second scale checked in the box of the line "Reason for the second-scale weight:" | No row "Why the second-scale weight is right" shows; its box sits in the line "Reason for the second-scale weight:". "✗ This value is above the plausible range (allowed 30 to 250 kg)." and the reason note naming “Why the second-scale weight is right”. Held: "• Weight on the second scale (or give a reason in “Why the second-scale weight is right”)". Once the reason is typed the note goes and the range note stays |
| C8b | Save | The record saves. New entry for `uj_emb`, with type `range`, reason `hard-high`, `justified_by` `uj_emb_why` |
| C8c | Clear the box of "Reason for the second-scale weight:" and leave. Save. Then clear "Weight on the second scale" and leave. Save | With the reason cleared the note is back. Held, the line of C8. With the weight cleared both notes go and the record saves, writing no entry |

## Section D: "Save anyway?", and a rule that never blocks (XE-1)

| # | Action | Expect |
|---|---|---|
| D1 | "Temperature" = 43.0 and leave. Save | "✗ This value is above the plausible range (allowed 34 to 42 C)." and the reason note naming “Why the temperature is right”. Save opens a dialog that reads "Validation FAILED for:", then "• Temperature (degrees C) (or give a reason in “Why the temperature is right”)", then "Save anyway?". Click Cancel. The form stays open and nothing is saved |
| D2 | "Why the temperature is right" = Fever confirmed with a second thermometer. Save | The reason note goes. Save asks nothing and the record saves. New entry for `uj_temp`, with type `range`, reason `hard-high`, `justified_by` `uj_temp_why` |
| D3 | "Oxygen saturation" = 85 and leave. Save | `✗ Below 90%: check the probe.` and no reason note, since the rule never blocks. The record saves with no alert and no dialog. New entry `invalid-id-saved` for `uj_off`, with type `constraint`, reason `assert:[uj_off]>=90` |
| D4 | "Why the saturation is right" = Known lung disease. Save | The warning stays and still no reason note. The record saves. New entry `uvalidate-justified` for `uj_off`, with `justified_by` `uj_off_why`. A reason marks the finding although the rule never blocks |

## Section E: the Enter key (XE-1)

| # | Action | Expect |
|---|---|---|
| E1 | Clear "Reason for change". Click into "Weight" and press Enter | Held: "• Weight (or give a reason in “Reason for change”)". Nothing is saved |
| E2 | Type Scale checked twice in "Reason for change" and press Enter there | The record saves, since Enter saves the form as REDCap does. No new entry |

## Section F: a reason does not release @UVUNIQUE or @UVWRITEONCE

| # | Action | Expect |
|---|---|---|
| F1 | XE-1. "Participant code" = UJ-1 and leave | `✗ FORMAT error — the ID is incomplete. Still remaining: 2 x digit.`, "✓ Not used before." and the reason note naming “Why the code is right”. Held: "• Participant code (or give a reason in “Why the code is right”)" |
| F1b | "Why the code is right" = Code from the paper form. Save | The reason note goes; the format note stays. The record saves. New entry for `uj_code`, with type `single`, reason `format`, `justified_by` `uj_code_why` |
| F2 | "Participant code" = UJ-001 and leave. Save | "✓ ID format OK. (This project's IDs carry no check character, so typos that keep the format cannot be detected.)" and "✓ Not used before."; no reason note. The record saves and writes no entry, since `uj_code` is no longer a finding; F1b's entry stays in the log |
| F3 | Open XE-2 at `visit_1_arm_1`. "Why it is blank" = Not done. "Participant code" = UJ-001 and leave. Save | "✗ This value is already recorded (record XE-1)." and no reason note. Held, one line, "• Participant code", offering no reason |
| F3b | "Why the code is right" = Code from the paper form. Save | Still held, the same line, and still no reason note |
| F3c | Clear "Participant code" and its reason. Save | XE-2 saves, with one entry, `uvalidate-justified` for XE-2's `uj_req` |
| F4 | XE-1. "Enrolment weight" = 300.0 and leave | The range note of B2 and the reason note naming “Why the enrolment weight is right”. Held: "• Enrolment weight (kg) (or give a reason in “Why the enrolment weight is right”)" |
| F4b | "Why the enrolment weight is right" = Weighed in a cast. Save | The reason note goes. The record saves. New entry for `uj_lock`, with type `range`, reason `hard-high`, `justified_by` `uj_lock_why` |
| F5 | Reopen the form | "Enrolment weight" is read-only, with "🔒 This value was saved once and is locked. It cannot be changed here." and the range note. No reason note |
| F5b | Clear "Why the enrolment weight is right". Save | Still no reason note, as a locked field never holds the save. The record saves. New entry `invalid-id-saved` for `uj_lock`, with type `range`, reason `hard-high` and no `justified_by` |
| F6 | Data Import Tool, CSV `record_id,redcap_event_name,uj_lock,uj_lock_why` with the row `XE-1,visit_1_arm_1,310.0,Weighed in a cast`. Import. Reopen the form. Save | 310.0, read-only, with the lock note and the range note, and no reason note. The record saves with two new entries for `uj_lock`. One is `invalid-id-saved` with type `writeonce`, reason `changed`, `locked_at` F4b's time, `changed_at` the import's time and no `justified_by`. The other is `uvalidate-justified` with type `range`, `value` 310.0 and `justified_by` `uj_lock_why`, new because the value is. The reason releases the range finding and leaves the write-once finding open |

## Section G: a survey of five pages (XE-4)

| # | Action | Expect |
|---|---|---|
| G1 | Open XE-4 at `visit_1_arm_1` on the data entry form, then "Survey options", "Open survey". Click Next with nothing typed | Page 1 shows section A of the form only. Under "Respiratory rate": "⚠ Automatic checking of this field is unavailable (a configuration issue has been logged for the study team)." Next is held: "• Required field (or give a reason in “Why it is blank”)" |
| G1b | "Why it is blank" = Refused. "Weight" = 300.0 and leave. Next | The reason note under Weight. Held: "• Weight (or give a reason in “Reason for change”)" |
| G1c | "Reason for change" = Scale checked twice. Next | Page 2 opens. XE-4 gets one entry, `uvalidate-justified` for `uj_wt`. `uj_req` is judged once the response is complete (G5), so a page turn logs nothing for it. No entry holds the reason |
| G2 | Page 2 (participant code, enrolment weight). Next | Page 3 opens. No new entry, since G1c logged the `uj_wt` finding |
| G3 | Page 3. "Hours fasted" = 30 and leave. Next | "✗ This value is above the plausible range (allowed 0 to 24)." and no reason note, because the reason field is on page 4. Next is held: "• Hours fasted", offering no reason |
| G4 | "Hours fasted" = 12. Next. On page 4, Next | Pages 4 and 5 open. Neither Next writes an entry |
| G5 | Page 5. Submit | Each `uj_bad_*` field shows the general line of G1. The survey closes. One new entry, `uvalidate-justified` for `uj_req`, now that the response is complete |

## Section H: the Validation scan

The scan page on this release runs the durable scan, whose panel shows
counts. The legacy scan has no page here (`export.php` answers 503 until the
stored-report exporter ships). `tests/justify_live_twin_php.php` runs the
legacy scan over the same data, so its rows are pinned offline: 17 rows
marked as saved with a reason, each naming its reason field, the column
"Saved with a reason" reading "Yes", and `uj_wt`'s problem text "The value is
above the highest plausible value for this field. Allowed 30 to 250 kg. It was
saved with a reason, given in [uj_wt_why]." Only XE-1's `uj_lock` write-once
row is open.

| # | Action | Expect |
|---|---|---|
| H1 | [only if the durable scan is enabled] Run the Validation scan | The panel reads "<F0 + 1> findings so far, and 17 saved with a reason" (with F0 = 0, "1 finding so far, and 17 saved with a reason") and "<R0> rules could not be evaluated, so this result does not certify the project." The new finding is XE-1's write-once change of F6. The 17 are XE-1's `uj_req`, `uj_wt`, `uj_id`, `uj_pool`, `uj_dia`, `uj_dose`, `uj_visit`, `uj_wt2`, `uj_mother`, `uj_hr`, `uj_ro`, `uj_temp`, `uj_off` and `uj_lock` range finding, XE-2's `uj_req`, and XE-4's `uj_req` and `uj_wt`. XE-3, never opened on this form, has none |
| H2 | Data Import Tool, CSV `record_id,redcap_event_name,uj_wt_why` with the row `XE-1,visit_1_arm_1,UNK`. Import. Run the scan | "<F0 + 2> findings so far, and 16 saved with a reason". XE-1's weight now counts as a finding, because a Missing Data Code is no reason |
| H2b | The same import with three spaces as the value, and "Overwrite existing values with blank values" = Yes. Run the scan | The counts of H2. REDCap may keep the spaces or trim them to a blank; either is no reason. Write down which (the form shows it) |
| H2c | The same import with Scale checked twice. Run the scan | The counts of H1 again |
| H3 | [only if the durable scan is enabled] Create a scratch project (classic, `record_id` on `my_first_instrument`). Upload a dictionary with its `record_id` row and the `uj_wt` and `uj_wt_why` rows of the fixture (form `uv_justify_test`). Enable the module with the scan settings of pid 149. Add record 1: "Weight" = 300.0, "Reason for change" = Scale checked twice. Save. Run the Validation scan | The record saves once the reason is typed. The panel reads "Nothing found yet, and 1 saved with a reason" and "Every record was checked, including changes made while it ran.", and no line says the result does not certify the project. The run is clean. A clean result also needs that coverage line; if it reads otherwise, record it |
| H3b | Data Import Tool, CSV `record_id,uj_wt_why` with the row `1,` and "Overwrite existing values with blank values" = Yes. Run the scan | "1 finding so far" and "This result does not certify the project." Then delete the scratch project |

## Section J: what REDCap does that the code depends on

Each step checks one thing REDCap does. When it does it another way, write
down what you saw in the run report.

| # | Action | Expect |
|---|---|---|
| J1 | In C3, look at the box after choosing UNK with the "M" button | The box holds UNK, the code, which the page reads as no reason. If it shows the label "Unknown" or stays blank, record it |
| J2 | Data Import Tool, CSV `record_id,redcap_event_name,uj_hr_show` with the row `XE-1,visit_1_arm_1,0`. Import. Reopen XE-1's form. Save. [only if the durable scan is enabled] Run the scan. Then import the row again with 1 | The reason row is hidden and its text is still saved. The page counts no reason, so the reason note asks under Heart rate, and Save is held with the line of C5. The scan reads the saved text and keeps the counts of H1 |
| J3 | On XE-1's form, click "reset" under "Why it is blank". Save. Then choose Not done again and Save | REDCap clears the choice without an event. Within a second the reason note is back under "Required field". Held: "• Required field (or give a reason in “Why it is blank”)". With Not done again the note goes and the record saves |
| J4 | Only an administrator can add an SQL field; skip this step and J4b otherwise. In the Online Designer, add `uj_sql_why` after "Respiratory rate", with type "Dynamic Query (SQL)", label "Why the respiratory rate is right", query `select 1, 'Patient crying' union all select 2, 'Counted twice'`. Reopen XE-1. "Respiratory rate" = 70 and leave. Save | `uj_rr`'s error of A1 is gone; the six `uj_bad_*` errors stay. "✗ This value is above the plausible range (allowed 5 to 60)." and the reason note naming “Why the respiratory rate is right”. Held: "• Respiratory rate (per minute) (or give a reason in “Why the respiratory rate is right”)" |
| J4b | "Why the respiratory rate is right" = Counted twice. Save | The reason note goes. The record saves. New entry for `uj_rr`, with type `range`, reason `hard-high`, `justified_by` `uj_sql_why`. Then delete `uj_sql_why` |
| J5 | Read the module log at the imports of C7, F6, H2 and J2 | No entry at any import, since an import runs no module hook. If an import writes an entry, record it |
| J6 | On survey page 3 (G3), run `document.getElementsByName('uj_far_why').length` in the browser console | 0, because REDCap draws only the current page's fields. If it is 1, record it, since the reason field would then sit hidden on the page |
| J7 | In C8, run `document.getElementsByName('uj_emb_why')[0].closest('tr').id` in the browser console | `uj_emb_box-tr`: REDCap moves the embedded box into the row of the label that names it, and the page judges that row. If it names another row, record it |

---

## Recording the run

Copy these tables into `reports/deploy/LIVE_TEST_<version>_justify.md` with a
column for what was seen, and note the REDCap version, the browser, the save
times, F0 and R0, and every difference from "Expect". Then, with the project
owner's agreement, delete the instrument `uv_justify_test` (with it, its
survey settings and the data XE-1 to XE-4 hold on it), `uj_sql_why` if J4
left it, the Missing Data Code if Setup 4 added it, and the scratch project
of H3 if it is still there.
