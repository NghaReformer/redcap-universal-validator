# @UVWRITEONCE live acceptance protocol

Live test of write-once locks on a real REDCap. The automated suites
(`tests/writeonce_php.php`, `tests/writeonce_dom_js.cjs`,
`tests/writeonce_module_php.php`) prove the logic against stubs. This run
checks the real date picker, radio buttons, dropdown, notes box and slider,
the real project log as the lock's source, a Data Import Tool change the page
never sees, user roles, the module log and the Validation scan.

Target: chpr-redcap.org pid 149 (longitudinal, seed records XE-1 to XE-4). The
fixture is [`uvwriteonce_test_fields.csv`](uvwriteonce_test_fields.csv), 13
fields on one new instrument `uv_writeonce_test`:

- 7 carry a valid `@UVWRITEONCE`: `uw_consent` (date), `uw_arm` (radio, the
  role may change it), `uw_site` (dropdown, the role, its own message),
  `uw_yn` (yes/no, the role written in other letters), `uw_notes` (notes),
  `uw_pain` (slider) and `uw_weight` (the lock starts on 2099-01-01).
- `uw_free` carries no tag.
- The 5 `uw_bad_*` fields carry a broken one.

Every "Expect" below is the module's own answer.
`tests/writeonce_live_twin_php.php` runs the real module over the fixture on a
copy of pid 149's events and forms, with a copy of REDCap's log that gains the
row each save of this sheet writes. It checks each configuration error word
for word, which fields each page locks, each log entry each save writes, and
the scan. `tests/writeonce_live_twin_js.cjs` replays the clicks through
`js/engine.js` with the page configs the module built
(`tests/writeonce_live_pages.json`) on a page shaped as REDCap renders these
field types, and checks each lock, each note and each save. Both run in CI;
run them after any change to the module, the engine or this sheet:

```bash
php tests/writeonce_live_twin_php.php && node tests/writeonce_live_twin_js.cjs
```

---

## How the lock works, in brief

- A field is locked from the first save of a value that is not blank and not a
  Missing Data Code. The page then keeps that value. A text box is read-only,
  radio buttons are disabled, a dropdown takes no clicks, and a change made
  any other way is undone.
- A person who holds one of the rule's roles may change the value, and the
  lock moves to the new value. Clearing it releases the lock.
- The lock is the browser's only. A value written by the Data Import Tool or
  the API is found afterwards, by the post-save audit of the next form save and
  by the Validation scan, from REDCap's own log.
- Roles are read when the audit or the scan runs, not as they were at the
  save. REDCap's log names the user who saved but not that user's role at the
  time. So a change counts as the role's while its maker holds the role, and
  as a violation once they leave it. Section D shows both.

---

## Setup

1. Deploy the release that contains `@UVWRITEONCE` and confirm the version in
   Control Center.
2. Append, never replace. Download the current dictionary, paste the 13 rows of
   `uvwriteonce_test_fields.csv` after its last row and upload. The diff must
   report one new instrument with 13 new fields and nothing else.
3. Designate `uv_writeonce_test` on `visit_1_arm_1` (380) only.
4. Do not create the role yet. Section A starts without it.
5. The tester's account needs edit rights to `uv_writeonce_test` and the Data
   Import Tool. Note the role the tester holds now, if any, because section D
   moves the tester into another role and back.
6. Form URL, record XE-3:
   `DataEntry/index.php?pid=149&id=XE-3&event_id=380&page=uv_writeonce_test`

Read the injected config at any point with:

```js
JSON.parse(document.getElementById('inspire-validator-config').textContent)
```

A "lock note" below is the grey box under the field, starting with 🔒. Write
down the time of each save in sections B and C: the module log and the scan
give the lock times to the minute, in the project's time zone. The twin uses
2026-10-23 09:00 for B1, 09:05 for B3, 09:20 for C1 and 09:30 for D2.

---

## Section A: configuration, before and after the role exists

| # | Action | Expect |
|---|---|---|
| A1 | Open the form for XE-3 | Under `uw_arm` and `uw_site`: "⚠ Configuration error: @UVWRITEONCE on "uw_arm": "UV Data manager" is not a role of this project — use the role name the User Rights page shows, or its unique role name." (with `uw_site` in its own). Under `uw_yn` the same, naming "uv data MANAGER". The `uw_site` error sits under the dropdown, not in a box at the top of the page |
| A2 | User Rights: create a role named "UV Data manager" with edit rights to `uv_writeonce_test`. Assign nobody to it. Reload the form | The three errors are gone. The five `uw_bad_*` errors of section F stay. Nothing is locked, since the record has no saved value on this form yet |

## Section B: the first save and the page lock (the tester holds no role)

| # | Action | Expect |
|---|---|---|
| B1 | `uw_consent` = 2026-10-01, `uw_arm` = Arm A, `uw_yn` = Yes, `uw_notes` = First note, `uw_pain` slider to 50, `uw_weight` = 70.0, `uw_free` = a. Leave `uw_site` blank. Type anything in the `uw_bad_*` text boxes. Save | The record saves. The broken rules never block. The module log has no `type: writeonce` entry |
| B2 | Reopen the form | Locked, each with the lock note "🔒 This value was saved once and is locked. It cannot be changed here.": `uw_consent` (read-only, the date picker button does nothing), `uw_arm` and `uw_yn` (buttons greyed out, the "reset" link gone), `uw_notes` (read-only), `uw_pain` (the slider does not move, its number box is read-only). Not locked and no note: `uw_site` (blank), `uw_weight` (its lock starts in 2099), `uw_free` (no tag). The page source names neither "UV Data manager" nor 2099 |
| B2b | Click Arm B | Nothing changes |
| B3 | `uw_site` = Douala. Save | The record saves. No `type: writeonce` entry |
| B4 | Reopen. Click into the `uw_site` dropdown, or Tab to it and press the Down arrow | `uw_site` shows Douala with the note "🔒 Ask the data manager to change the site." A mouse click does nothing. A keyboard change snaps back to Douala and the note ends " A change was undone." The dropdown is not greyed out: a greyed-out dropdown would save blank |
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

## Section E: what the browser cannot stop

There is nothing to click here. The API behaves as the import of section C: a
value it writes is found by the next form save's audit and by the scan, and
is never blocked.

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

---

## Recording the run

Copy these tables into `reports/deploy/LIVE_TEST_<version>_writeonce.md` with
a column for what was seen, and note the REDCap version, the browser, the save
times and every difference from "Expect". Then, with the project owner's
agreement, delete the role "UV Data manager" and the instrument
`uv_writeonce_test`.
