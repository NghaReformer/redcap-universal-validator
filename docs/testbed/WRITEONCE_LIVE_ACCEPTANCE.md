# @UVWRITEONCE live acceptance protocol

Live test of write-once locks on a real REDCap. The automated suites
(`tests/writeonce_php.php`, `tests/writeonce_dom_js.cjs`,
`tests/writeonce_module_php.php`) prove the logic against stubs. This run
checks the real date picker, radio buttons, dropdown, notes box and slider,
the real project log as the lock's source, a Data Import Tool change the page
never sees (on a visit and on a whole repeating event), user roles, a
multi-page survey, a deletion of a form's data, the module log and the
Validation scan. Section I checks what REDCap writes to its log on an import
with its columns out of order, a form with an embedded field, a renamed
record, an SQL field and an erase of all records, which the module's reading
of the log assumes.

Target: chpr-redcap.org pid 149 (longitudinal, seed records XE-1 to XE-4). The
fixture is [`uvwriteonce_test_fields.csv`](uvwriteonce_test_fields.csv), 13
fields on one new instrument `uv_writeonce_test`:

- 7 carry a valid `@UVWRITEONCE`: `uw_consent` (date), `uw_arm` (radio, the
  role may change it), `uw_site` (dropdown with `@AUTOCOMPLETE`, the role, its
  own message), `uw_yn` (yes/no, the role written in other letters),
  `uw_notes` (notes, saved over two lines), `uw_pain` (slider) and `uw_weight`
  (the lock starts on 2099-01-01). The field notes name neither the role nor
  that date, because B2 checks that the page does not.
- `uw_free` carries no tag.
- The 5 `uw_bad_*` fields carry a broken one.

Every "Expect" in sections A to H is the module's own answer.
`tests/writeonce_live_twin_php.php` runs the real module over the fixture on a
copy of pid 149's events and forms, with a copy of REDCap's log that gains the
row each save of this sheet writes (the deletion of section H included). It
checks each configuration error word for word, which fields each page locks,
each log entry each save writes, and the scan. `tests/writeonce_live_twin_js.cjs` replays the clicks through
`js/engine.js` with the page configs the module built
(`tests/writeonce_live_pages.json`) on a page shaped as REDCap renders these
field types, and checks each lock, each note and each save. Section I is
not in the twin: its steps read what REDCap itself logs, and each says what
to record when REDCap logs it another way. Both twins run in CI; run them
after any change to the module, the engine or this sheet:

```bash
php tests/writeonce_live_twin_php.php && node tests/writeonce_live_twin_js.cjs
```

---

## How the lock works, in brief

- A field is locked from the first save of a value that is not blank and not a
  Missing Data Code. The page then keeps that value. A text box is read-only,
  radio buttons are disabled, a dropdown takes no clicks, the date picker does
  not open, REDCap's Today and Now buttons are hidden, and a change made any
  other way is undone. Nothing the form posts is disabled, because REDCap
  would then save the field blank.
- A person who holds one of the rule's roles may change the value, and the
  lock moves to the new value. Clearing it releases the lock.
- The lock is the browser's only. A value written by the Data Import Tool or
  the API is found afterwards, by the post-save audit of the next save of this
  form and by the Validation scan, from REDCap's own log.
- Roles are read when the audit or the scan runs, not as they were at the
  save. REDCap's log names the user who saved but not that user's role at the
  time. So a change counts as the role's while its maker holds the role, and
  as a violation once they leave it. Section D shows both.
- Each record and event (and instance) has its own history. A deletion of the
  whole record starts a new one, and an erase of all the project's records
  starts every history again (section I). A deletion of this event's data, or
  of an arm, makes the field "not checked" from then on, since the check
  cannot tell what it removed; REDCap never trims its log, so this lasts.
  Section H shows it. A deletion of another event's data changes nothing here.
  A renamed record is "not checked" too, because REDCap logs its earlier saves
  under the old name (section I).

---

## Setup

1. Deploy the release that contains `@UVWRITEONCE` and confirm the version in
   Control Center.
2. Append, never replace. Download the current dictionary, paste the 13 rows of
   `uvwriteonce_test_fields.csv` after its last row and upload. The diff must
   report one new instrument with 13 new fields and nothing else.
3. Designate `uv_writeonce_test` on `visit_1_arm_1` (380) and on
   `unscheduled_arm_1` (382), which repeats as a whole. Section E uses 382.
4. Do not create the role yet. Section A starts without it.
5. The tester's account needs edit rights to `uv_writeonce_test`, the Data
   Import Tool, User Rights, and Project Design and Setup with read access to
   every form (the Validation scan of C1b, D3, D4, E2, H3, I1 and I2 asks
   for both).
   Note the role the tester holds now, if any, because section D moves the
   tester into another role and back.
6. Form URL, record XE-3:
   `DataEntry/index.php?pid=149&id=XE-3&event_id=380&page=uv_writeonce_test`
7. For section G: Online Designer, enable `uv_writeonce_test` as a survey, and
   in its survey settings set "Question display format" to one section per
   page. The fixture's two section headers make two pages. Keep the "Previous
   page" button.

Read the injected config at any point with:

```js
JSON.parse(document.getElementById('inspire-validator-config').textContent)
```

A "lock note" below is the grey box under the field, starting with 🔒. Write
down the time of each save in sections B, C, D and E: the module log and the
scan give the lock times to the minute, in the project's time zone. The twin
uses 2026-10-23 09:00 for B1, 09:05 for B3, 09:20 for C1, 09:30 for D2, 09:40
for E1, 09:50 for E2, 10:00 for G2, 10:05 for G4, 10:10 for H1 and 10:15 for H2.

---

## Section A: configuration, before and after the role exists

| # | Action | Expect |
|---|---|---|
| A1 | Open the form for XE-3 | Under `uw_arm` and `uw_site`: "⚠ Configuration error: @UVWRITEONCE on "uw_arm": "UV Data manager" is not a role of this project — use the role name the User Rights page shows, or its unique role name." (with `uw_site` in its own). Under `uw_yn` the same, naming "uv data MANAGER". The `uw_site` error sits under the dropdown, not in a box at the top of the page |
| A2 | User Rights: create a role named "UV Data manager" with the rights of Setup 5: edit rights to `uv_writeonce_test`, the Data Import Tool, User Rights, Project Design and Setup, and read access to every form. Section D moves the tester into this role, and D4 needs User Rights to move them out again. Assign nobody to it. Reload the form | The three errors are gone. The five `uw_bad_*` errors of section F stay. Nothing is locked, since the record has no saved value on this form yet |

## Section B: the first save and the page lock (the tester holds no role)

| # | Action | Expect |
|---|---|---|
| B1 | `uw_consent` = 2026-10-01, `uw_arm` = Arm A, `uw_yn` = Yes, `uw_notes` = "First note", Enter, "second line" (two lines), `uw_pain` slider to 50, `uw_weight` = 70.0, `uw_free` = a. Leave `uw_site` blank. Type anything in the `uw_bad_*` text boxes. Save | The record saves. The broken rules never block. The module log has no `type: writeonce` entry |
| B2 | Reopen the form | Locked, each with the lock note "🔒 This value was saved once and is locked. It cannot be changed here.": `uw_consent` (read-only, the date picker does not open, and its calendar button and REDCap's Today button are gone), `uw_arm` and `uw_yn` (buttons greyed out, the "reset" link gone), `uw_notes` (read-only, both lines), `uw_pain` (the slider does not move, its number box is read-only). Not locked and no note: `uw_site` (blank), `uw_weight` (its lock starts in 2099), `uw_free` (no tag). The page source names neither "UV Data manager" nor 2099 |
| B2b | Click Arm B | Nothing changes |
| B2c | Only if the project has Missing Data Codes: click the "M" button beside `uw_consent` and pick a code | The date comes back, and the note ends " A change was undone." |
| B3 | `uw_site` = Douala. Save | The record saves. No `type: writeonce` entry |
| B4 | Reopen. Click into the `uw_site` autocomplete box and type "Yao", then Tab to the list and press the Down arrow | `uw_site` shows Douala with the note "🔒 Ask the data manager to change the site." The autocomplete box takes no typing and a mouse click does nothing. A keyboard change snaps back to Douala and the note ends " A change was undone." The dropdown is not greyed out: a greyed-out dropdown would save blank |
| B4b | Save | The record saves with Douala. No `type: writeonce` entry |
| B5 | `uw_weight` = 71.0. Save | The weight changes and saves; no entry. Reopened, it is still not locked |

## Section C: a change the page never sees (Data Import Tool)

| # | Action | Expect |
|---|---|---|
| C1 | Data Import Tool, CSV with the columns `record_id,redcap_event_name,uw_consent,uw_site,uw_notes` and the row `XE-3,visit_1_arm_1,2026-10-02,2,` (blank note). Choose "Overwrite existing values with blank values" = Yes. Import | REDCap accepts the import. The module log gains no entry, because an import runs no module hook |
| C1b | Run the Validation scan | Three `@UVWRITEONCE` findings for XE-3 at visit 1: `uw_consent` "Changed after it was locked" with "The value was changed after its first save locked it. It was locked on <B1 time>."; `uw_notes` "Cleared after it was locked" with "The value was cleared after its first save locked it. It was locked on <B1 time>."; `uw_site` "Changed after it was locked" with "Ask the data manager to change the site. It was locked on <B3 time>." The five `uw_bad_*` fields are listed as rule problems, never as data |
| C2 | Reopen the form | `uw_consent` shows 2026-10-02 and `uw_site` Yaounde, both locked: the page locks whatever is saved. `uw_notes` is blank and open. The page itself says nothing about the import |
| C3 | Save the form without changing anything | The record saves. Three `type: writeonce` entries at event 380: `uw_consent` reason `changed`, `uw_site` `changed`, `uw_notes` `cleared`, each with `locked_at` (B1's time, B3's for the site) and `changed_at` (C1's time) |

## Section D: the role

| # | Action | Expect |
|---|---|---|
| D1 | User Rights: assign the tester to "UV Data manager". Reopen the form | `uw_arm`, `uw_site` and `uw_yn` are open, with no note. `uw_consent` and `uw_pain` stay locked: their rules name no role |
| D2 | `uw_arm` = Arm B. Save | The record saves. Two `type: writeonce` entries: `uw_consent` `changed` and `uw_notes` `cleared`. No entry for `uw_arm`, changed by the role. None for `uw_site` either, because the tester who imported it holds the role now |
| D3 | Run the scan | Two findings: `uw_consent` and `uw_notes` |
| D4 | User Rights: take the tester out of the role (back to the role noted in Setup 5, if any). Reopen the form, then run the scan | `uw_arm` is locked at Arm B, and `uw_site` and `uw_yn` are locked again. The scan has four findings: `uw_arm` "Changed after it was locked" (locked at B1, changed at D2), `uw_consent`, `uw_notes` and `uw_site`. The arm and the site count as changes again because the scan reads the roles as they are now (see "How the lock works") |

## Section E: a whole repeating event (the tester holds no role)

The API behaves as the import of section C. A value it writes is found by the
next form save's audit and by the scan, and is never blocked. This section
imports into an event that repeats as a whole, where REDCap's log starts each
row past the first instance with "[instance = N]".

| # | Action | Expect |
|---|---|---|
| E1 | Open XE-3 at `unscheduled_arm_1`, add instance 1 of the event, `uw_consent` = 2026-10-03. Save. Reopen | The record saves with no `type: writeonce` entry. `uw_consent` is locked at instance 1 |
| E2 | Data Import Tool, CSV with the columns `record_id,redcap_event_name,redcap_repeat_instrument,redcap_repeat_instance,uw_consent` and the rows `XE-3,unscheduled_arm_1,,1,2026-10-04` and `XE-3,unscheduled_arm_1,,2,2026-10-09`. Import, then run the scan | Five `@UVWRITEONCE` findings. Four are those of D4 at visit 1. The fifth is `uw_consent` at `unscheduled_arm_1` instance 1, "Changed after it was locked", locked at E1's time. Instance 2 has none, because its first save is the import, which locks it |
| E3 | Open instance 2 | `uw_consent` shows 2026-10-09, locked |
| E4 | Open instance 1 and save it without changing anything | One `type: writeonce` entry at event 382, instance 1: `uw_consent` reason `changed`, `locked_at` E1's time, `changed_at` E2's time |

## Section F: configuration errors

Open the form. Each `uw_bad_*` field shows its error under the field, after
"⚠ Configuration error: ". A broken rule never blocks a save:

| Field | Error |
|---|---|
| `uw_bad_role` | @UVWRITEONCE on "uw_bad_role": "Nobody here" is not a role of this project — use the role name the User Rights page shows, or its unique role name. |
| `uw_bad_calc` | @UVWRITEONCE on "uw_bad_calc": @UVWRITEONCE does not support "calc" fields — REDCap works a calc's value out again whenever the fields it reads change. |
| `uw_bad_setvalue` | @UVWRITEONCE on "uw_bad_setvalue": @UVWRITEONCE cannot lock a field with @SETVALUE — REDCap writes its value again each time the form or survey page opens. |
| `uw_bad_when` | @UVWRITEONCE on "uw_bad_when": unknown @UVWRITEONCE option(s): when — valid: roles, since, message. |
| `uw_bad_value` | @UVWRITEONCE on "uw_bad_value": @UVWRITEONCE takes no value, or its settings as JSON, e.g. @UVWRITEONCE={"roles":["Data manager"]}. |

On a survey page each of these reads only "this check is not set up
correctly; the study team can see the details on the data entry form.", so a
role name never reaches a respondent.

## Section G: a survey of two pages (the tester holds no role)

| # | Action | Expect |
|---|---|---|
| G1 | Open XE-4 at `visit_1_arm_1` on the data entry form, then "Survey options", "Open survey" | The first page opens with nothing locked: XE-4 has no saved value on this form |
| G2 | `uw_consent` = 2026-10-05, `uw_arm` = Arm A, `uw_free` = s. Click Next | The second page (the `uw_bad_*` fields) opens. Next saved the first page. The module log has no `type: writeonce` entry and no rule-problem entry for a `uw_*` field other than the five `uw_bad_*` |
| G3 | Click Previous | `uw_consent` and `uw_arm` are locked, each with the lock note; `uw_free` is open. A survey respondent holds no role, so `uw_arm` would be locked for a member of the role too. The page source names no role, and each `uw_bad_*` error reads the survey's general wording |
| G4 | Click Next, then Submit | The survey closes. No `type: writeonce` entry, and no rule-problem entry for `uw_consent` or `uw_arm` |

## Section H: a deletion of the form's data

| # | Action | Expect |
|---|---|---|
| H1 | Open XE-4 at `visit_1_arm_1` on the data entry form and delete the data of this form only (REDCap's "Delete data for THIS FORM only"). Confirm. Reopen the form | The form is blank and nothing is locked |
| H2 | `uw_consent` = 2026-10-06. Save | The record saves. No `type: writeonce` entry. A rule-problem entry for `uw_consent` reads "the project log holds a deletion this check cannot place (part of the record, or one arm of it) — field not checked" |
| H3 | Run the scan | XE-4's `uw_consent` is listed as not checked, with the same reason, and has no finding |

Two cases are not run live. A deletion of the whole record starts a new
history, and pid 149's seed records are kept. In a project of several arms
every deletion makes the field "not checked", because REDCap can delete a
record from one arm only, and pid 149 has one arm. `tests/writeonce_php.php`
and `tests/mysql/cases/history.php` cover both.

## Section I: what REDCap writes to its log

The audit reads REDCap's log and assumes how REDCap writes it. Each step
below checks one assumption on the real server. When REDCap logs it another
way, the step says what to record: copy the rows from the Logging page (the
"List of Data Changes" column word for word) into the run report. The tester
holds no role, as after D4.

| # | Action | Expect |
|---|---|---|
| I1 | An import with its columns out of order. Open XE-1 at `visit_1_arm_1`: `uw_consent` = 2026-10-07, `uw_notes` = "first". Save. Data Import Tool, CSV with the columns `uw_notes,uw_consent,redcap_event_name,record_id` (reversed) and the row `second,2026-10-08,visit_1_arm_1,XE-1`. Import. On the Logging page, filter on record XE-1 and read the import's row. Run the scan | The row lists `uw_consent` before `uw_notes`, the data dictionary's order. The scan has two findings for XE-1: `uw_consent` and `uw_notes`, "Changed after it was locked", locked at I1's save. If the row lists `uw_notes` first, both fields are listed as not checked, "the project log does not say plainly what one save of it wrote": record the row, since the order check then has to change |
| I2 | A form with an embedded field. In the Online Designer, add ` {uw_free}` at the end of `uw_consent`'s label. Open XE-2 at `visit_1_arm_1`: `uw_free` shows inside `uw_consent`'s row. `uw_consent` = 2026-10-07, `uw_notes` = "n", `uw_free` = e. Save. Read XE-2's row on the Logging page. Data Import Tool, CSV `record_id,redcap_event_name,uw_consent` with the row `XE-2,visit_1_arm_1,2026-10-08`. Import, then run the scan. Remove ` {uw_free}` from the label | The row lists `uw_free` after `uw_notes`. The scan has one finding for XE-2: `uw_consent` "Changed after it was locked". If the row lists `uw_free` straight after `uw_consent`, `uw_consent` is listed as not checked with the reason of I1: record the row |
| I3 | A renamed record. Add record XE-R1, open it at `visit_1_arm_1`, `uw_consent` = 2026-10-07. Save. On the Record Home Page, rename the record to XE-R2. Read the rows the rename wrote on the Logging page. Open XE-R2's form and save it without changing anything | `uw_consent` is locked at 2026-10-07. No `type: writeonce` entry. A rule-problem entry for `uw_consent` reads "the record was renamed, and the saves made under its old name are not read — field not checked". If the entry gives another reason, record it with the rename rows |
| I4 | Only an administrator can add an SQL field; skip this step otherwise. In the Online Designer, add a field `uw_sql` of type "Dynamic Query (SQL)" with the query `select 1, 'One' union all select 2, 'Two'` and the annotation `@UVWRITEONCE`. Open XE-1, `uw_sql` = Two. Save. Change the query to `select 1, 'One'`. Reopen XE-1, then Save | Reopened, `uw_sql` shows the saved code 2 as its own choice, locked, with the lock note. The record saves with 2 and no `type: writeonce` entry; a `cleared` entry means the form posted the field blank. Then delete `uw_sql` |
| I5 | An erase of all records, on a copy of the project only, never on pid 149. Copy pid 149 without its records (Other Functionality, "Copy the project"), enable the module in the copy, and give the tester the rights of Setup 5. In the copy, add record XE-1, `uw_consent` = 2026-10-07 at `visit_1_arm_1`. Save. Other Functionality, "Erase all data". Read the rows the erase wrote on the Logging page. Add record XE-1 again, `uw_consent` = 2026-10-08. Save. Reopen. Data Import Tool, CSV `record_id,redcap_event_name,uw_consent` with the row `XE-1,visit_1_arm_1,2026-10-09`. Import, then save the form | The erase writes one row for the project, with no record named. The save after the erase writes no `type: writeonce` entry, because the save of 2026-10-07 no longer counts. Reopened, `uw_consent` is locked at 2026-10-08. The last save writes one entry, `uw_consent` reason `changed`, `locked_at` the second save's time. If the erase writes one row per record instead, or the save after it writes a `changed` entry, record the rows. Then delete the copy |

---

## Recording the run

Copy these tables into `reports/deploy/LIVE_TEST_<version>_writeonce.md` with
a column for what was seen, and note the REDCap version, the browser, the save
times and every difference from "Expect". Then, with the project owner's
agreement, delete the role "UV Data manager", the record XE-R2 of I3 and
the instrument `uv_writeonce_test` (with it, its survey settings and the data
XE-1 to XE-4 hold on it), and the copy of I5 if it is still there.
