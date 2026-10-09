# @UVWINDOW live acceptance protocol

Live test of date windows on a real REDCap, plus the `@UVUNIQUE` fix for
D-M-Y and M-D-Y date fields that ships with it. The automated suites
(`tests/window_php.php`, `tests/window_js.cjs`, `tests/window_dom_js.cjs`,
`tests/window_module_php.php`) prove the logic against stubs. This run checks
real REDCap date inputs, real save paths and the real server clock.

Target: chpr-redcap.org pid 149 (longitudinal, event and instance references
enabled). Fixture: [`uvwindow_test_fields.csv`](uvwindow_test_fields.csv), 19
fields on one new instrument `uv_window_test`. Every annotation in it was run
through the module offline before this sheet was written: the 14 rule fields
configure, and the 5 `uw_bad_*` fields show the errors listed in section F.

---

## Setup

1. Deploy the release that contains `@UVWINDOW` and confirm the version in
   Control Center.
2. **Append, never replace.** Download the current dictionary, append the 19
   rows of the CSV, upload. The diff must report one new instrument and 19 new
   fields, and nothing else.
3. Designate `uv_window_test` on `event_1_arm_1` (same event as `xe_enrol`,
   so `uw_offpage` reads the consent date from another form of the same event).
4. Use record XE-1. Its consent date is 2026-01-10 and its Baseline visit
   date is 2026-01-15 (seed data).
5. Form URL: `DataEntry/index.php?pid=149&id=XE-1&event_id=351&page=uv_window_test`.

Read the injected config at any point with:

```js
JSON.parse(document.getElementById('inspire-validator-config').textContent)
```

---

## Section A: windows counted from a date on this page

Type `2026-03-01` in `uw_anchor` first.

| # | Action | Expect |
|---|---|---|
| A1 | `uw_live` = 2026-03-22, then 2026-04-05 | Green OK both times |
| A2 | `uw_live` = 2026-03-21 | Red: "This date must be between 2026-03-22 and 2026-04-05." plus "(21 to 35 days from [uw_anchor])" |
| A3 | `uw_live` = 2026-04-06, press Save | Save blocked (hard); the dialog names the field by its label |
| A4 | Clear `uw_anchor` | `uw_live`, `uw_dmy`, `uw_weeks` and `uw_when` messages disappear; save allowed |
| A5 | Anchor back to 2026-03-01; `uw_dmy` = 08-03-2026 then 09-03-2026 | OK, then "between 01-03-2026 and 08-03-2026" (the field's D-M-Y format) |
| A6 | `uw_dmy`: type `09-03-20` and stop | No verdict while the date is incomplete |
| A7 | `uw_weeks` = 2026-02-15, 2026-03-15, 2026-02-14 | OK, OK, then the custom message |
| A8 | `uw_weeks` = tomorrow's date | The custom message (the date is also in the future) |
| A9 | `uw_gate` = Yes; `uw_when` = 2026-03-12 | Red, window late |
| A10 | `uw_gate` = No | `uw_when` message disappears |

## Section B: not in the future, server clock

| # | Action | Expect |
|---|---|---|
| B1 | `uw_nofuture` = today's date (M-D-Y) | OK |
| B2 | `uw_nofuture` = tomorrow, press Save | "This date is after today (MM-DD-YYYY)." with today's date; save blocked |
| B3 | Set the computer clock one day ahead, reload, `uw_nofuture` = the computer's "today" | Still refused: today is the server's date. Restore the clock after |
| B4 | `uw_now_sec` = now; then now + 5 minutes | OK; then "This date and time is in the future." |
| B5 | **[optional]** Open the form shortly before the server's midnight, type tomorrow's date in `uw_nofuture`, wait until after midnight, press Save | Before midnight: refused. After midnight the save goes through without a reload |
| B6 | `uw_nofuture` = tomorrow, leave the field; then click REDCap's **Today** button beside it | Red, then green OK without leaving or retyping the field (the date picker changes the field without a native change event) |
| B7 | Project setting **Time zone for @UVWINDOW "notFuture"** = `Mars/Olympus`, Save | Refused: "... is not a time zone name ..." |
| B8 | **[optional, only while the server's date is behind UTC+14]** Set the time zone to `Pacific/Kiritimati`, reload, `uw_nofuture` = the server's tomorrow | OK: it is already that day in Kiritimati. Clear the setting after |

## Section C: datetime window

| # | Action | Expect |
|---|---|---|
| C1 | `uw_dt_anchor` = 2026-03-01 08:00; `uw_dt` = 01-03-2026 14:00 | OK |
| C2 | `uw_dt` = 01-03-2026 14:01 | "between 01-03-2026 08:00 and 01-03-2026 14:00" |

## Section D: anchors elsewhere

| # | Action | Expect |
|---|---|---|
| D1 | Read the config rule for `uw_offpage` | `windowFromOp` is `["lit","2026-01-10"]`, `snapshotFields` is `["xe_consent_date"]` |
| D2 | `uw_offpage` = 2026-02-09 | OK |
| D3 | `uw_offpage` = 2026-02-10, press Save | Red message with "(counted from xe_consent_date, read when this page was opened ...)". The save is not blocked, although the tag says hard |
| D3b | `uw_offpage_nf` = tomorrow, press Save | "This date is after today (...)", with no "counted from" note; save blocked. "Future" does not depend on the consent date, so it keeps the hard block |
| D4 | `uw_cross` = 2026-01-29, then 2026-03-17 | OK, then red ("between 2026-01-29 and 2026-03-16"); never blocks |
| D5 | Account without rights to `xe_enrol`: open the form, read the `uw_offpage` rule | `windowFromOp` is `["withheld"]`, `deferred` is not set, and 2026-01-10 appears nowhere in the config |
| D5b | Same account: `uw_offpage` = 2026-02-10, press Save | Amber note "The window counted from [xe_consent_date] is not checked on this page ... It is checked when the record is saved."; the save goes through |
| D5c | Same account: `uw_offpage_nf` = tomorrow, press Save | "This date is after today"; save blocked |
| D6 | Enable `uv_window_test` as a survey and open it | No rule carries the consent date; staff-only text such as "(21 to 35 days from ...)" is absent |
| D7 | In the survey: `uw_offpage` = 2026-02-10, then `uw_offpage_nf` = tomorrow and submit | `uw_offpage` shows nothing; `uw_offpage_nf` says "This date is after today" and the submit is blocked |

## Section E: post-save audit and scan

| # | Action | Expect |
|---|---|---|
| E1 | `uw_anchor` = 2026-03-01, `uw_dmy` = 09-03-2026 (advisory: red, does not block), press Save | Module log: `invalid-id-saved`, `type: window`, reason `window-late` |
| E2 | `uw_now_sec` = `2027-06-01 10:00:00` (advisory), press Save | Module log: reason `future` |
| E2b | **[optional]** Import `uw_live` = 2026-04-06 through the Data Import Tool (bypasses the browser) | Module log: reason `window-late`. Whether REDCap runs the save hook on an import depends on its version; if no entry appears, E4 still finds the value |
| E3 | With `uw_offpage` saved as 2026-02-09, change only `xe_consent_date` on `xe_enrol` to 2026-01-01 and save | Saving the anchor's form re-audits `uw_offpage`: a new `window-late` finding (2026-02-09 is 39 days after 2026-01-01) |
| E4 | Run the Validation scan | Findings for E1/E2 with Issue "Date outside allowed window" / "Date in the future" and the missed bound in the detail line |
| E5 | **[durable scan only]** Run a durable scan | The same findings as E4. Each part of the run reads the server clock when it runs, so a later catch-up does not flag a date that has become today |

## Section F: configuration errors

Each field shows a visible error and checks nothing:

| Field | Error contains |
|---|---|
| `uw_bad_text` | needs a date field |
| `uw_bad_family` | holds dates with a time and this field holds dates |
| `uw_bad_unit` | "unit" "hours" needs a datetime field |
| `uw_bad_self` | names this field itself |
| `uw_bad_order` | is after its latest bound |

## Section G: `@UVUNIQUE` on a D-M-Y date

| # | Action | Expect |
|---|---|---|
| G1 | On XE-1, save `uw_uniq_dmy` = 15-03-2026 | Saved; stored value is 2026-03-15 |
| G2 | On XE-2, type 15-03-2026 in `uw_uniq_dmy` and leave the field | "This value is already recorded (record XE-1)." Before this release the answer was "Not used before." |
| G3 | On XE-2, type 16-03-2026 | "Not used before." |

---

## Sign-off

| # | Gate | Result |
|---|---|---|
| 1 | Sections A to C: verdicts and messages match | ☐ |
| 2 | B3: the computer clock cannot move "today" | ☐ |
| 3 | D3/D4: an anchor read at page load never blocks | ☐ |
| 4 | D5/D6: no anchor value reaches a survey or a user without rights | ☐ |
| 5 | Section E: audit and scan agree with the browser | ☐ |
| 6 | Section G: the D-M-Y duplicate is found live | ☐ |

## Cleanup

Delete the `uv_window_test` instrument, or restore the dictionary downloaded in
setup step 2. Restore `xe_consent_date` on XE-1 to 2026-01-10 if E3 changed it.
