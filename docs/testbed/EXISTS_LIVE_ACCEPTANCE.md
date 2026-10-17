# @UVEXISTS live acceptance protocol

Live test of lookups on a real REDCap, and of the form-rights, group and rate
checks that now also guard `@UVUNIQUE`. The automated suites
(`tests/exists_php.php`, `tests/exists_dom_js.cjs`, `tests/unique_dom_js.cjs`)
prove the logic against stubs. This run checks REDCap's real `getData` (its
`filterLogic`, event filters and Data Access Group behaviour), real AJAX calls,
real rights, real widgets (radio reset link, autocomplete, date picker) and the
module log.

Target: chpr-redcap.org pid 149 (longitudinal, repeating, seed records XE-1 to
XE-4). Fixture: [`uvexists_test_fields.csv`](uvexists_test_fields.csv), 32
fields on one new instrument `uv_exists_test`: 19 rule fields carry a valid
`@UVEXISTS` (one of them with `@UVUNIQUE` too), 3 carry `@UVUNIQUE` only, 7
`ux_bad_*` fields carry a broken `@UVEXISTS`, and `ux_type`, `ux_gate` and
`ux_site_sel` carry no tag. Before this sheet was written, every annotation
and every lookup below was run offline through the module against the
dictionary and the seed data in `event_instance/`: the 22 rule fields
configure, the 7 `ux_bad_*` fields show the errors in section F, and each
"Expect" below is what the module answered.

---

## Setup

1. Deploy the release that contains `@UVEXISTS` and confirm the version in
   Control Center.
2. Append, never replace. Download the current dictionary, append the 32
   rows of the CSV, upload. The diff must report one new instrument and 32 new
   fields, and nothing else.
3. Designate `uv_exists_test` on `event_1_arm_1` (event 351).
4. The steps below use these seed values. Specimen IDs (`xs_id`, repeating `xr_specimen`)
   S-001 and S-002 (Sputum) at Baseline and S-003 (Blood) at Visit 1 for XE-1;
   S-100 (Sputum and Blood) and S-101 (Urine) at Baseline for XE-2. Participant
   codes (`xe_code`) PX-0001 to PX-0004; every consent date is 2026-01-10; every
   enrolment site is 1 (North).
5. Form URL: `DataEntry/index.php?pid=149&id=XE-1&event_id=351&page=uv_exists_test`.

Read the injected config at any point with:

```js
JSON.parse(document.getElementById('inspire-validator-config').textContent)
```

The module's AJAX object, used in C5 and G3, is the one the config names:

```js
const cfg = JSON.parse(document.getElementById('inspire-validator-config').textContent);
const uv = cfg.jsmoName.split('.').reduce((o, k) => o[k], window);
```

---

## Section A: found, not found, and how values compare

Wait for each answer (green, red or amber) before the next step, unless the
step says otherwise.

| # | Action | Expect |
|---|---|---|
| A1 | Read the config rules for `ux_spec` and `ux_spec_m` | No `existsIn`, `existsEvent`, `existsScope`, `existsMatch` or `existsTargets` key; `xs_id` appears nowhere in the config. `ux_spec_m` carries `existsLocal: ["ux_type"]` |
| A2 | `ux_rec` = XE-2, leave the field | Green "Found." with no record named |
| A3 | `ux_rec` = XE-9, leave the field, wait for the red message, press Save | Red message; save blocked (hard) |
| A4 | `ux_spec` = S-003 | Green "Found (record XE-1)." |
| A5 | `ux_spec` = S-999, leave the field, wait for the red message, press Save | Red "Register this specimen on the specimen form first."; save blocked |
| A6 | `ux_spec` = s-003 | Green "Found (record XE-1).": letter case is ignored |
| A7 | `ux_spec` = ` S-001 ` (spaces around) | Green: values are trimmed |
| A8 | `ux_spec_bl` = S-001, then S-003 | Green, then red: S-003 is registered at Visit 1, not Baseline. Save goes through (advisory) |
| A9 | `ux_code` = PX-0003, then PX-9999 | Found (record XE-3), then not found |
| A10 | `ux_consent_dmy` = 10-01-2026 typed, then 11-01-2026 picked with the date picker | Found (stored as 2026-01-10), then not found. The picker's choice is asked without leaving the field |
| A11 | Clear `ux_spec`, type S-999 and click Save at once, without leaving the field first | Save is refused: the alert names `ux_spec`, either as flagged or as "(still being checked)". After the red message appears, Save is refused again |

## Section B: when the page asks

Open the browser's network panel and filter on `exists-check`.

| # | Action | Expect |
|---|---|---|
| B1 | Save `ux_spec` = S-001, reopen the form | One request per saved lookup field as the page loads |
| B2 | Click into `ux_spec` and type S-0, S-00, S-003 slowly | The previous answer disappears on the first keystroke. No request while typing, also after pausing |
| B3 | Leave the field (Tab) | One request for S-003; green "Found" |
| B4 | Click into the field and out again without changing it | No new request (the answer is cached) |
| B5 | `ux_type` blank; type S-100 in `ux_spec_m` and leave it | No request, no message: a blank match field leaves nothing to narrow by |
| B6 | `ux_type` = Blood | One request; "Found (record XE-2)." |
| B7 | `ux_type` = Sputum; `ux_spec_m` = S-101 | Red: S-101 is registered as Urine |
| B8 | `ux_gate` = No; `ux_when` = S-999 | No request, no message |
| B9 | `ux_gate` = Yes | One request; red; save blocked (hard) |
| B10 | `ux_type` = Blood, then Sputum, then Blood again (with `ux_spec_m` = S-100) | Two requests in all: the third answer comes from the cache |

## Section C: could not check

| # | Action | Expect |
|---|---|---|
| C1 | `ux_code_site` = PX-0002 | Found: XE-2 is also site 1 (matched by `xe_site`, saved on `xe_enrol`) |
| C2 | On a new record (no `xe_enrol` saved yet), `ux_code_site` = PX-0002 | Amber "Could not check this value just now ([xe_site] is blank, so there is nothing to match against yet)." Save allowed. Note the new record ID for cleanup |
| C3 | Account with no access to `xr_specimen`: open XE-1, `ux_spec` = S-003 | Amber "Could not check ... (you do not have access to every form this lookup reads)"; no form or field named; save allowed; nothing about S-003's record shown |
| C4 | Same account: `ux_rec` = XE-2 | Found: a record-ID lookup needs no extra form |
| C5 | [optional] In the console (see Setup for `uv`): `for (let i=0;i<65;i++) uv.ajax('exists-check',{field:'ux_code',values:{ux_code:'PX-'+i}}).then(r=>console.log(i,r))` | The replies after the 60th in that minute are `{"state":"unknown", ... "too many checks ..."}`. Then, in the same minute, `uv.ajax('unique-check',{field:'ux_both',values:{ux_both:'S-001'}})` returns `{"error":"too many checks — slow down"}`. Use `ux_both`: any other field answers "not a checkable field" before the rate check |

## Section D: surveys

| # | Action | Expect |
|---|---|---|
| D1 | Enable `uv_exists_test` as a survey and open it | Only `ux_survey` and `ux_survey_m` ask the server. The other lookup fields show nothing; the six `ux_bad_*` fields show the general notice "Automatic checking of this field is unavailable ..." |
| D2 | `ux_survey` = PX-0001, then PX-9999 | Found (no record named), then not found |
| D3 | In the survey page's console, read the config | No configuration error names a field or event: search the JSON for `nowhere_arm_1`, `xe_consent_date` and `no_such_field`; none appears |
| D4 | [optional] Flag `xe_code` as an Identifier in the dictionary, then open the data entry form | `ux_survey` shows a configuration error naming `xe_code` (the survey shows only the general notice). Unflag after |
| D5 | [optional] Survey settings: one section per page. Page 1: `ux_type` = Blood, Next. Page 2: `ux_survey_m` = S-100, then S-101 | Found, then not found: the server reads `ux_type` saved on page 1 |

## Section E: post-save audit and scan

| # | Action | Expect |
|---|---|---|
| E1 | On XE-1 save `ux_spec_bl` = S-003 (advisory: red, does not block) | Module log: `invalid-id-saved`, `type: exists`, reason `not-found`, field `ux_spec_bl` |
| E2 | [optional] Import `ux_spec` = S-999 for XE-2 through the Data Import Tool | Module log: reason `not-found` (if this REDCap version runs the save hook on imports; otherwise E3 finds it) |
| E3 | [only if the durable scan is enabled] Run the Validation scan | The panel counts at least 1 finding (2 if E2 ran). The scan page shows counts only while its report is being rebuilt; the Issue label "Not found in its source" is pinned offline by `tests/registry_php.php` |
| E4 | [only if the project has DAGs and the durable scan is enabled] Run the scan as a user in one Data Access Group | The count includes no finding from a `@UVEXISTS` rule other than `ux_dag`: those rules are not evaluated in a group-confined scan. The "looks for the value across every Data Access Group" wording is pinned offline by `tests/exists_php.php` |
| E5 | [only if the durable scan is enabled] Start a durable scan, save any record while it runs, let it finish | Coverage is manifest-complete, and the reason says records changed during the run and `@UVEXISTS` answers depend on other records |

## Section F: configuration errors

On the data entry form, each field shows a visible error and asks nothing:

| Field | Error contains |
|---|---|
| `ux_bad_self` | "in" names this field itself |
| `ux_bad_nofield` | "no_such_field" is not a field in this project |
| `ux_bad_multi` | "xe_consent" is a checkbox field |
| `ux_bad_family` | "xe_consent_date" holds dates and "ux_bad_family" holds no date or time |
| `ux_bad_event` | "nowhere_arm_1" is not an event of this project |
| `ux_bad_notes` | does not support "notes" fields |
| `ux_bad_mark` | "xe_weight" holds numbers with a decimal point and "ux_bad_mark" holds numbers with a decimal comma |

## Section G: `@UVEXISTS` with `@UVUNIQUE`, and the new `unique-check` gate

| # | Action | Expect |
|---|---|---|
| G1 | On XE-1 save `ux_both` = S-001 | Found; not used before; saved |
| G2 | On XE-2, `ux_both` = S-001, leave the field, wait for both messages, press Save | Two messages: found (exists) and "already recorded (record XE-1)" (unique); save blocked |
| G3 | Sign in as an account with no access to `uv_exists_test`, open any data entry form that account can open in pid 149 (the module's AJAX object is set up on every data entry page), and run `uv.ajax('unique-check',{field:'ux_both',values:{ux_both:'S-001'}})` | `{"error":"not a checkable field"}` |
| G4 | Control for G3: the same call from an account that has access | `{"used":true, ...}` (S-001 is saved on XE-1) |

## Section H: choice fields, branches and groups

| # | Action | Expect |
|---|---|---|
| H1 | `ux_radio` = Blood | Found (S-003 is Blood at Visit 1) |
| H2 | `ux_radio` = Sputum, press Save | Red; save blocked (hard) |
| H3 | Click the radio's reset link, press Save | Save goes through: the blank value is re-judged at the click |
| H4 | `ux_drop` = Urine, press Save | Red; "Save anyway?" dialog (confirm) |
| H5 | `ux_drop_ac` (autocomplete): type "Uri", pick Urine | Red message under the search box, not between the search box and the dropdown; the search box has the red outline. The dropdown still opens and searches normally |
| H6 | `ux_site_sel` = Specimen register (do not save); `ux_br` = S-003 | Found |
| H7 | Change `ux_site_sel` to Participant register (do not save); leave `ux_br` = S-003 | Red: S-003 is not a participant code. The request's `cond` (network panel) holds `ux_site_sel: "2"` |
| H8 | `ux_br` = PX-0002 | Found |
| H9 | On a new record: `ux_site_sel` = Specimen register, `ux_br` = S-003 | Found (before this fix: "could not check") |
| H10 | [only if the project has DAGs] As a user in one group, open a record of that group and enter in `ux_spec` a specimen ID saved only in a record of another group | Found, or amber "a value saved in another Data Access Group may not be visible from yours". Never red. Record which answer came, because it shows whether this REDCap confines a group user's reads |
| H11 | [only if the project has DAGs] Same user, in the console: `uv.ajax('exists-check',{field:'ux_spec',values:{ux_spec:'S-003'}})` sent from a form URL whose `id=` names a record of another group | `{"state":"unknown", ..., "why":"this record is not in your Data Access Group"}` |

## Section I: another project (`"project"`)

This section needs a second project on the same server, the source project.
Its outcome is also the pilot gate for the framework calls the module makes
about a project other than the one of the request.

Fixtures: [`uvexists_cross_fields.csv`](uvexists_cross_fields.csv) (16 rows,
instrument `uv_exists_cross`, appended to pid 149),
[`uvexists_cross_source.csv`](uvexists_cross_source.csv) (the source project's
dictionary, instrument `lab`) and
[`uvexists_cross_source_data.csv`](uvexists_cross_source_data.csv) (records L-1
and L-2). Every expectation below was run offline through the module first,
with pid 500 standing for the source project.

Setup:

1. Create the source project (classic, no surveys), upload its dictionary and
   import its two records. Note its project id; the steps write it as SRC.
2. Enable the module in the source project. Give the test account rights to the
   `lab` form there. Keep a second account with rights in pid 149 only.
3. Append the 16 rows to pid 149's dictionary and designate `uv_exists_cross`
   on `event_1_arm_1`.
4. In pid 149's module settings, add the alias `lab` for SRC.

| # | Action | Expect |
|---|---|---|
| I1 | Control Center: "@UVEXISTS in other projects" off. Open `uv_exists_cross` on XE-1 | Every `uxc_*` rule except `uxc_bad_alias` shows "looking in another project ("project") is turned off on this REDCap server". `uxc_bad_alias` names the alias setting |
| I2 | Turn the switch on; the source project lists nothing yet. Reload | Every `uxc_*` rule except `uxc_bad_alias` shows "project SRC cannot be searched from this project", word for word the same |
| I3 | Source project settings, "Projects that may look up values here": add 149, fields `lab_spec, lab_site, lab_date, record, lab_donor, lab_ghost`, "Only users who have rights" (default). Save, reload the form | `uxc_spec`, `uxc_rec`, `uxc_m`, `uxc_date`, `uxc_donor` set up. `uxc_bad_secret` still shows the same "cannot be searched" text (`lab_secret` is not listed), and so do `uxc_ret` and `uxc_bad_ret_secret` (nothing may be copied yet). `uxc_bad_ghost`: "lab_ghost" is not a field of project SRC. `uxc_survey`: "does not answer survey respondents" |
| I4 | Read the config rules for `uxc_spec` | No `existsProject`, `existsPid`, `existsRemoteTargets` or `existsIn` key; SRC and `lab_spec` appear nowhere |
| I5 | `uxc_spec` = LS-001, then LS-999 and press Save | Green "Found." with no record named; then red "Register this specimen in the lab project first." and the save is blocked |
| I6 | `uxc_rec` = L-2, then L-9 | Found, then not found |
| I7 | `uxc_site` = South, `uxc_m` = LS-002; then `uxc_site` = North | Found, then not found |
| I8 | `uxc_date` = 01-02-2026, then 02-02-2026 | Found (stored 2026-02-01), then not found |
| I9 | Source project module log | One `uv-exists-probe` line per lookup in I5 to I8: `source_project` 149, channel `staff`, the user, the field, the result, and `value_hash` (64 hex characters). No value appears raw. The not-found line for `uxc_rec` = L-9 also carries `extra_read` `record ids` (the lookup read the record IDs there to make sure the miss was real); no other line does |
| I10 | Second account (rights in 149 only): `uxc_spec` = LS-001 | Amber "Could not check ... (you do not have rights in the project this lookup searches)"; a `refused` line in the source project's log |
| I11 | Source project: un-flag `lab_donor` as an Identifier, switch the row to "Any signed-in user of the asking project" and save, then flag `lab_donor` as an Identifier again. Second account: `uxc_spec` = LS-001 | Found. Reloaded as the test account, `uxc_donor` shows "field "lab_donor" of project SRC is an Identifier there". The Configure dialog refuses the switch while `lab_donor` is flagged ("is an Identifier, so it can be searched only by users with rights"), which is why the flag comes off first |
| I12 | Source project: also tick "Also answer survey respondents". Enable `uv_exists_cross` as a survey, open it, `uxc_survey` = LS-001, then LS-999 | Found, then not found, no record named; the source project's log shows channel `survey`, user `survey` |
| I13 | Source project settings: try to save a second row for 149, a row with field `nope`, and a row with surveys ticked under "Only users who have rights" | The dialog refuses each and names the row |
| I14 | Back to "Only users who have rights". Import `uxc_spec` = LS-999 for XE-1 with the Data Import Tool (the form itself blocks that save) | Module log of pid 149: `invalid-id-saved`, `type: exists`, reason `not-found`; source project log: channel `audit` |
| I15 | [only if the durable scan is enabled] Run the Validation scan in pid 149 | The panel counts the `uxc_spec` finding; one `uv-exists-index-read` line per searched field in the source project's log, none per record |
| I16 | [optional] Control Center: set "lookups one searched project answers per minute" to 2, then enter four values in `uxc_spec` within a minute | The third and fourth answer "could not check (too many lookups in the other project in the last minute)". The source project's log shows two `found` lines and one `throttled` line, not two. Clear the setting after |
| I17 | [only if the source project has DAGs] Put the test account in a group there; `uxc_spec` = a specimen of another group | Not found (the lookup stays in the account's group there). A scan then counts no finding for `uxc_spec`; the "Data Access Group of project SRC" wording is pinned offline by `tests/exists_cross_php.php` |
| I18 | [only if I17 ran] Same account, still in the group there: import `uxc_spec` = LS-001 for XE-1 | Module log of pid 149: `uvalidate-unconfigurable` for `uxc_spec`, "your account is in a Data Access Group of the other project, so the check after saving cannot see values saved in its other groups". Source project log: channel `audit`, result `refused`. Take the account out of the group after |
| I19 | [deferred until the scan report page returns] Second account (rights in 149 only, and to the Validation scan): open the scan report from I15 | The report is not shown: "this scan looks values up in project SRC, which does not answer your lookups, so its results are not shown to you". Pinned offline by `tests/exists_cross_php.php` meanwhile |
| I20 | Source project row for 149, "Only users who have rights": set "Fields of this project whose values it may copy" to `lab_site, lab_date, record`. Save, reload the form | `uxc_ret` sets up. `uxc_bad_ret_secret` still shows the same "cannot be searched" text: `lab_secret` is not on the copy list |
| I21 | `uxc_ret` = ls-001 and leave the field; then LS-999 | Green "Found." with no record named, and `uxc_ret_site` North, `uxc_ret_date` 01-02-2026, `uxc_ret_from` L-1. Then red, and the three fields are emptied |
| I22 | Source project module log | The probe line for LS-001 carries `returned` `lab_site,lab_date,record` and `copied_from` `L-1` besides `value_hash`; the LS-999 line carries `returned` but no `copied_from` |
| I23 | Test account on XE-1: set `uxc_spec` to LS-001 first (I14 imported LS-999, and that rule blocks the save), then `uxc_ret` = LS-002 and save the form | Page: South, 03-02-2026, L-2. After the save the record holds `uxc_ret_date` 2026-02-03 (a data export shows the stored value), and the source project's log has a channel `audit` line with `copied_from` `L-2` |
| I24 | Source project: switch the row to "Any signed-in user of the asking project" with the copy list still filled, and save | The Configure dialog refuses the row: values can be copied only by users with rights there. Cancel, so the row stays as I20 left it |
| I25 | Second account (rights in 149 only): open XE-1, `uxc_ret` = LS-001 | Amber "Could not check"; the three fields keep I23's South, 03-02-2026 and L-2 |

---

## Section J: letter case and numbers

The fields under "Letter case and numbers". Wait for each answer before the
next step.

| # | Action | Expect |
|---|---|---|
| J1 | `ux_spec_cs` = S-003, then s-003 | Green "Found (record XE-1).", then red: this rule sets `"caseSensitive":true` |
| J2 | `ux_rec` = xe-2 | Green "Found.": a record ID in another letter case is found |
| J3 | On XE-1, `ux_rec` = XE-1 | Red: the record being edited does not find itself |
| J4 | `ux_weight` = 70.0, then 070, then 71 | Found (record XE-1) twice, then red: numbers compare by value, and every enrolment weight is 70 |
| J5 | `ux_weight_txt` = 70.00 | Found (record XE-1): the searched field holds numbers, so a plain Text field compares by value too |
| J6 | On XE-1 save `ux_uq` = UQ-1 and `ux_uq_cs` = UQ-1. Open XE-2, type uq-1 in both, leave each field | `ux_uq`: "already recorded (record XE-1)", and Save is blocked (hard). `ux_uq_cs`: not used before. The module first asks REDCap for `lower([ux_uq]) = 'uq-1'` and confirms a miss with a read of the whole field, so the answer is right either way |
| J7 | Leave XE-2 without saving. Run the Validation scan with `ux_uq` = uq-1 saved on XE-2 (clear `ux_uq_cs` first, or save it too) | Two "Duplicate value" rows for `ux_uq` (XE-1 and XE-2); none for `ux_uq_cs` |
| J8 | On XE-2, `ux_spec` = S-998 and leave the field (red). In a second tab, register S-998 as a specimen of XE-1 and save. Back in the first tab, at least 30 seconds after the red answer, press Save | That click asks again instead of trusting the red answer: an alert says the field is still being checked, then the field turns green. The next Save goes through |
| J9 | On XE-1 save `ux_uq_num` = 7. Open XE-2, type 007 and leave the field. Then save -5 on XE-1 and type -05 on XE-2 | "already recorded (record XE-1)" both times: numbers compare by value |
| J10 | Import `ux_uq` = `UQ-5 ` (a space after it) for XE-1 with Data Import, then open XE-1 and record whether the space was kept. Open XE-2, type UQ-5 and leave the field | "already recorded (record XE-1)" whether REDCap kept the space or not: a value with spaces around it is compared without them |

---

## Section K: copying fields of the entry found (`"return"`)

Fixture: [`uvexists_return_fields.csv`](uvexists_return_fields.csv), 23 rows on
one new instrument `uv_exists_return`: 4 rule fields copy values into 8
fields, 5 `uxr_bad_*` fields carry a refused `"return"` (with 5 targets of
their own), and `uxr_gate` is the gate `uxr_when` reads. Every expectation
below was run offline through the module, on XE-2, against the seed data in
`event_instance/` first (a scratch script, not shipped). Append the rows,
designate `uv_exists_return` on `event_1_arm_1`, and open it on XE-2. K14
uses the account of C3 (no access to `xr_specimen`); give it access to
`uv_exists_return`.

| # | Action | Expect |
|---|---|---|
| K1 | Open the form; read the config rules | `uxr_spec`, `uxr_code`, `uxr_rec`, `uxr_when` set up, each with `existsReturnFields` and `existsReturnNumbers` (`["uxr_rec_wt"]` for `uxr_rec`, `[]` for the others). No rule without an error names `xs_type`, `xs_date`, `xs_barcode`, `xe_site`, `xe_weight` or `xe_consent_date`, and none carries `existsReturn` |
| K2 | The `uxr_bad_*` fields | `uxr_bad_survey`: "cannot be used with "surveys"". `uxr_bad_rep`: ""xs_id" is on the repeating form "xr_specimen", not on "xe_enrol"". `uxr_bad_kind`: "holds no date or time and "xs_date" holds dates". `uxr_bad_noevent`: "needs "event" in a longitudinal project". `uxr_bad_codes`: "has no choice "3"" |
| K3 | `uxr_spec` = S-001 and leave the field | Green "Found (record XE-1)."; `uxr_type` Sputum, `uxr_date` 15-01-2026, `uxr_from` XE-1 |
| K4 | `uxr_spec` = s-003 | Blood, 10-02-2026, XE-1 |
| K5 | `uxr_spec` = S-100 | Green "Found (record XE-2)." with an amber line "2 saved entries hold this value with different values to copy"; the three fields still hold K4's values |
| K6 | `uxr_spec` = S-999, then clear it | Red, and the three fields are emptied; clearing it asks nothing and leaves them empty |
| K7 | `uxr_code` = PX-0002 | North is checked on the radio `uxr_site`; `uxr_consent` 2026-01-10. PX-9999 then turns red and empties both, leaving no radio button checked |
| K8 | `uxr_rec` = XE-2, then XE-1 | XE-2 is the record being edited: red, and both fields stay empty. XE-1: `uxr_rec_code` PX-0001, `uxr_rec_wt` 70. Type 70.0 into `uxr_rec_wt` with the browser's console (`document.getElementsByName('uxr_rec_wt')[0].value='70.0'`), change `uxr_rec` to XE-3 and back to XE-1: `uxr_rec_code` PX-0003 then PX-0001, and `uxr_rec_wt` keeps 70.0, the same number |
| K9 | `uxr_gate` = No, `uxr_when` = S-001 | Nothing is asked and `uxr_bc` stays empty. Gate Yes: `uxr_bc` BC-S-001. Gate No again: `uxr_bc` is emptied |
| K10 | `uxr_spec` = S-003 and press Save at once, without leaving the field | The save waits for the answer ("still being checked") although the rule does not block; the next Save goes through and the record holds `uxr_type` 2, `uxr_date` 2026-02-10 (stored), `uxr_from` XE-1 |
| K11 | On XE-1, Visit 1, set S-003's `xs_type` to Urine and save. Reopen XE-2's `uv_exists_return` and leave it without touching anything | `uxr_type` shows Urine (the saved record still holds Blood), and leaving raises no "unsaved changes" prompt: the fill made as the page opens does not mark the form as changed. Set S-003 back to Blood |
| K12 | Data Import Tool: for XE-2 import `uxr_spec` = S-001 with `uxr_type`, `uxr_date` and `uxr_from` blank | After the import XE-2 holds 1, 2026-01-15, XE-1. The record's log shows the module's write as an update by the importing user |
| K13 | Import `uxr_spec` = S-100 for XE-2 | The three fields keep K12's values. Module log: `uvalidate-unconfigurable` for `uxr_spec`, "2 saved entries hold this value with different values to copy" |
| K14 | The C3 account (no access to `xr_specimen`): open XE-2, `uxr_spec` = S-003; `uxr_rec` = XE-1. Then, in User Rights, also set `xe_enrol` to No Access for it and type `uxr_rec` = XE-3 | `uxr_spec`: amber "Could not check this value just now (you do not have access to every form this lookup reads)."; the fields keep what they hold. `uxr_rec` = XE-1: PX-0001 and 70 (a record-ID lookup needs no form, and the copied fields' form is open to it). `uxr_rec` = XE-3 without `xe_enrol`: the same amber answer, since the copied fields' form counts too. Importing `uxr_spec` = S-003 as that account writes nothing, and the module log names the rule problem "the person who saved may not open every form this lookup reads". Give the account `xe_enrol` back |
| K15 | Project setting "@UVEXISTS returned values" on. Import `uxr_spec` = S-999 for XE-2 | Nothing is written, and the fields keep their values. Opening the form still shows red and empties the fields on the page, without saving them. Turn the setting off |
| K16 | [only if the project has DAGs] Put XE-1 in one group and XE-2 and a test account in another. As that account open XE-2, `uxr_spec` = S-001 | Green "Found" with the amber line "It is saved outside your Data Access Group", and nothing copied; or, if this REDCap confines the user's reads to their group, the H10 answer. Record which. Take the records and the account out of the groups after |
| K17 | Run the Validation scan | No field of `uv_exists_return` changes; the scan writes nothing |

## Section L: only entries that meet conditions (`"where"`)

Fixture: [`uvexists_where_fields.csv`](uvexists_where_fields.csv), 16 rows on
one new instrument `uv_exists_where`: 4 rule fields in this project, 7 rules
with a `where` this project refuses, and 5 rows that look in the source
project of section I (2 of them refused). The expectations for this project
were run offline through the module, from XE-4, against the seed data in
`event_instance/`. The rows that look in the lab project follow
`tests/exists_cross_php.php`, and L10's survey wording follows
`tests/exists_where_dom_js.cjs`. Append the rows, designate `uv_exists_where`
on `event_1_arm_1`, and open it on XE-4. Run this section after section I and
before its cleanup, with the source project's row for 149 as I24 left it:
"Only users who have rights", the fields of I3 (`lab_spec, lab_site, lab_date,
record, lab_donor, lab_ghost`) and the copy list of I20.

| # | Action | Expect |
|---|---|---|
| L1 | Read the config rules | `uxw_or`, `uxw_base`, `uxw_rec`, `uxw_code`, `uxw_lab`, `uxw_lab_rec` and `uxw_lab_donor` set up; those with a `whereMessage` carry it. No rule carries `existsWhere` or `existsWhereFields`, and no sound rule names `xs_type`, `xv_status`, `xe_ae`, `xe_weight`, `lab_site` or `lab_date` |
| L2 | The `uxw_bad_*` fields | `uxw_bad_rep`: ""where" field "[xr_value]" is on the repeating form "xr_result"". `uxw_bad_event`: "is on the form "xe_enrol", which is not in every event that holds "xr_specimen"". `uxw_bad_date`: "write it as Y-M-D". `uxw_bad_cb`: "reference one option as [xe_consent(code)]". `uxw_bad_noevent`: "needs "event" in a longitudinal project". `uxw_bad_and`: "write "and" instead". `uxw_bad_kind`: "both must be the same kind". `uxw_bad_lab_secret`: "project SRC cannot be searched from this project", word for word as in I2. `uxw_bad_lab_date`: "project SRC cannot apply this "where" condition", naming no field of SRC |
| L3 | `uxw_or` = S-003, then S-101, then S-100 | Green "Found (record XE-1).", "Found (record XE-2).", "Found (record XE-2).": S-100 has a Sputum and a Blood entry, and one entry that passes is enough |
| L4 | `uxw_or` = S-001, leave the field, press Save | Red "This specimen is registered, but it is not blood or urine." (S-001 is Sputum); the save is blocked (hard) |
| L5 | `uxw_or` = S-999 | Red "Register this specimen first.": a value saved nowhere keeps the rule's `message` |
| L6 | `uxw_base` = S-001, then S-003 | Found (record XE-1) both times. The condition tests `xv_status` of the visit form beside the repeating `xr_specimen` entry, with an upper-case `AND` and a date literal |
| L7 | `uxw_base` = S-303, then S-301 | Red "This value is saved, but not in an entry that meets this rule's conditions." both times: S-303 was collected on 10 January; XE-3 has no Baseline visit status. The rule is advisory, so Save goes through |
| L8 | `uxw_rec` = XE-1, xe-3, XE-2, XE-9, XE-4 | Found, found (another letter case), red "This participant had an adverse event at enrolment or did not consent to specimens.", red "This value is not saved where it should be. Check it, or record it there first.", and the same for XE-4 (the record being edited does not find itself) |
| L9 | `uxw_code` = PX-0004, then PX-0001 | Found (record XE-4): its arm 2 enrolment weighs 78 kg, though its arm 1 enrolment weighs 70. Then red "This participant weighed 75 kg or less at enrolment." |
| L10 | Enable `uv_exists_where` as a survey and open it; `uxw_code` = PX-0004, then PX-0001 | Found, no record named; then "This value is not saved where it should be. Check it, or record it there first.", the line for a value not found, never the `whereMessage`. Disable the survey after |
| L11 | [optional] Flag `xe_weight` as an Identifier, reload the data entry form | `uxw_code` shows "field "xe_weight" is an Identifier, so the survey lookup ("surveys") cannot be enabled". Unflag after |
| L12 | In User Rights set `xe_visit` to No Access for the test account; `uxw_base` = S-001, `uxw_or` = S-003 | `uxw_base`: amber "Could not check this value just now (you do not have access to every form this lookup reads)."; `uxw_or`: found, since it reads no `xe_visit` field. Then set `xe_enrol` to No Access too: `uxw_rec` = XE-1 gives the same amber answer. Give both forms back |
| L13 | Save XE-4 with `uxw_base` = S-301 | Module log: `invalid-id-saved`, `type: exists`, reason `filtered`, field `uxw_base`. Saving again with S-001 adds no line |
| L14 | [only if the durable scan is enabled] Run the Validation scan with L13's S-301 saved | The panel counts the `uxw_base` finding. The Issue label "Saved, but not where its conditions hold" is pinned offline by `tests/registry_php.php` |
| L15 | `uxw_lab` = LS-001, then LS-002 | Found, no record named; then red "Registered in the lab project, but not at North and not after 5 February." (LS-002 is South, 3 February) |
| L16 | Source project module log | The LS-002 line of L15 has `field` `lab_spec,lab_site,lab_date`, result `not-found` and `filtered` `true`; the LS-001 line has no `filtered` |
| L17 | `uxw_lab_rec` = L-2, then l-1 | Found; then red "This value is saved, but not in an entry that meets this rule's conditions.": L-1 is North |
| L18 | Source project: clear the row's copy list (I20), un-flag `lab_donor` as an Identifier, switch the row for 149 to "Any signed-in user of the asking project" and save. Then flag `lab_donor` as an Identifier again and reload the form in pid 149 | `uxw_lab_donor` shows "field "lab_donor" of project SRC is an Identifier there". The Configure dialog would refuse the switch while `lab_donor` is flagged ("is an Identifier, so it can be searched only by users with rights") or while the copy list is filled. Then switch the row back to "Only users who have rights" and restore I20's copy list |

## Sign-off

| # | Gate | Result |
|---|---|---|
| 1 | Section A: verdicts match, values trimmed and compared without letter case, typed-then-Save blocked | ☐ |
| 2 | Section B: no request while typing; one per change; cache | ☐ |
| 3 | Section C: "could not check" never blocks, rights respected | ☐ |
| 4 | Section D: surveys opt-in, no record named, no field named in errors | ☐ |
| 5 | Section E: the audit agrees with the browser; the scan counts the findings | ☐ |
| 6 | Section G: composition and the `unique-check` gate | ☐ |
| 7 | Section H: choice fields, reset, autocomplete, branches, groups | ☐ |
| 8 | Section I: another project. Agreement, rights there, probe log, surveys, audit, scan. This is also the pilot gate for `getProjectSetting`/`getSubSettings` with another project id, `log()` with `project_id`, the `project-id` setting type, `isModuleEnabled`, `getProjectStatus`, `\Project` event and group names, and `User::getRights` for another project | ☐ |
| 9 | Section J: `caseSensitive`, record IDs in another case, the record itself, numbers by value, `@UVUNIQUE` the same way, Save asking again | ☐ |
| 10 | Section K and I20 to I25: `"return"` fills, empties and leaves fields as documented, on the page and at save; dropdown, radio and D-M-Y targets; rights over every form the lookup reads, on the page and at save; the agreement's copy list, the probe log, the kill switch; the page-open fill does not mark the form as changed | ☐ |
| 11 | Section L: `"where"` with OR, upper-case AND, a date literal, a checkbox box and a number; the event row beside a repeat instance; `"in":"record"`; the filtered line with and without `whereMessage`; the plain survey wording; setup refusals; rights to the `where` forms; the audit reason `filtered`; the lab project's agreement, probe log and one refusal | ☐ |

## Cleanup

Delete the `uv_exists_test` instrument, or restore the dictionary downloaded in
setup step 2. Delete the record C2 created (and any record H9 saved), and the
specimen J8 registered on XE-1. Remove the
survey setting if D1 enabled it, and the one-section-per-page setting if D5
changed it. After section I, delete the `uv_exists_cross` instrument and the alias
row, delete the source project, and turn "@UVEXISTS in other projects" off
again unless it stays in use. After section K, delete the `uv_exists_return`
instrument, make sure "@UVEXISTS returned values" is off, that S-003 is Blood
again (K11), that the C3 account has `xe_enrol` back (K14), and that no record
or account is left in a group K16 used. After section L, delete the
`uv_exists_where` instrument (before section I's cleanup deletes the source
project), make sure `uv_exists_where` is no longer a survey (L10), that
`xe_weight` is not an Identifier (L11), that the test account has `xe_visit` and
`xe_enrol` back (L12), and that the source project's row for 149 is back on
"Only users who have rights" (L18).
