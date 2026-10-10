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

Fixtures: [`uvexists_cross_fields.csv`](uvexists_cross_fields.csv) (10 rows,
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
3. Append the 10 rows to pid 149's dictionary and designate `uv_exists_cross`
   on `event_1_arm_1`.
4. In pid 149's module settings, add the alias `lab` for SRC.

| # | Action | Expect |
|---|---|---|
| I1 | Control Center: "@UVEXISTS in other projects" off. Open `uv_exists_cross` on XE-1 | Every `uxc_*` rule except `uxc_bad_alias` shows "looking in another project ("project") is turned off on this REDCap server". `uxc_bad_alias` names the alias setting |
| I2 | Turn the switch on; the source project lists nothing yet. Reload | Every `uxc_*` rule except `uxc_bad_alias` shows "project SRC cannot be searched from this project", word for word the same |
| I3 | Source project settings, "Projects that may look up values here": add 149, fields `lab_spec, lab_site, lab_date, record, lab_donor, lab_ghost`, "Only users who have rights" (default). Save, reload the form | `uxc_spec`, `uxc_rec`, `uxc_m`, `uxc_date`, `uxc_donor` set up. `uxc_bad_secret` still shows the same "cannot be searched" text. `uxc_bad_ghost`: "lab_ghost" is not a field of project SRC. `uxc_survey`: "does not answer survey respondents" |
| I4 | Read the config rules for `uxc_spec` | No `existsProject`, `existsPid`, `existsRemoteTargets` or `existsIn` key; SRC and `lab_spec` appear nowhere |
| I5 | `uxc_spec` = LS-001, then LS-999 and press Save | Green "Found." with no record named; then red "Register this specimen in the lab project first." and the save is blocked |
| I6 | `uxc_rec` = L-2, then L-9 | Found, then not found |
| I7 | `uxc_site` = South, `uxc_m` = LS-002; then `uxc_site` = North | Found, then not found |
| I8 | `uxc_date` = 01-02-2026, then 02-02-2026 | Found (stored 2026-02-01), then not found |
| I9 | Source project module log | One `uv-exists-probe` line per lookup in I5 to I8: `source_project` 149, channel `staff`, the user, the field, the result, and `value_hash` (64 hex characters). No value appears raw. The not-found line for `uxc_rec` = L-9 also carries `extra_read` `record ids` (the lookup read the record IDs there to make sure the miss was real); no other line does |
| I10 | Second account (rights in 149 only): `uxc_spec` = LS-001 | Amber "Could not check ... (you do not have rights in the project this lookup searches)"; a `refused` line in the source project's log |
| I11 | Source project: switch the row to "Any signed-in user of the asking project". Second account: `uxc_spec` = LS-001 | Found. `uxc_donor` now shows "field "lab_donor" of project SRC is an Identifier there" |
| I12 | Source project: also tick "Also answer survey respondents". Enable `uv_exists_cross` as a survey, open it, `uxc_survey` = LS-001, then LS-999 | Found, then not found, no record named; the source project's log shows channel `survey`, user `survey` |
| I13 | Source project settings: try to save a second row for 149, a row with field `nope`, and a row with surveys ticked under "Only users who have rights" | The dialog refuses each and names the row |
| I14 | Back to "Only users who have rights". Import `uxc_spec` = LS-999 for XE-1 with the Data Import Tool (the form itself blocks that save) | Module log of pid 149: `invalid-id-saved`, `type: exists`, reason `not-found`; source project log: channel `audit` |
| I15 | [only if the durable scan is enabled] Run the Validation scan in pid 149 | The panel counts the `uxc_spec` finding; one `uv-exists-index-read` line per searched field in the source project's log, none per record |
| I16 | [optional] Control Center: set "lookups one searched project answers per minute" to 2, then enter four values in `uxc_spec` within a minute | The third and fourth answer "could not check (too many lookups in the other project in the last minute)". The source project's log shows two `found` lines and one `throttled` line, not two. Clear the setting after |
| I17 | [only if the source project has DAGs] Put the test account in a group there; `uxc_spec` = a specimen of another group | Not found (the lookup stays in the account's group there). A scan then counts no finding for `uxc_spec`; the "Data Access Group of project SRC" wording is pinned offline by `tests/exists_cross_php.php` |
| I18 | [only if I17 ran] Same account, still in the group there: import `uxc_spec` = LS-001 for XE-1 | Module log of pid 149: `uvalidate-unconfigurable` for `uxc_spec`, "your account is in a Data Access Group of the other project, so the check after saving cannot see values saved in its other groups". Source project log: channel `audit`, result `refused`. Take the account out of the group after |
| I19 | [deferred until the scan report page returns] Second account (rights in 149 only, and to the Validation scan): open the scan report from I15 | The report is not shown: "this scan looks values up in project SRC, which does not answer your lookups, so its results are not shown to you". Pinned offline by `tests/exists_cross_php.php` meanwhile |

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
| J6 | On XE-1 save `ux_uq` = UQ-1 and `ux_uq_cs` = UQ-1. Open XE-2, type uq-1 in both, leave each field | `ux_uq`: "already recorded (record XE-1)", and Save is blocked (hard). `ux_uq_cs`: not used before. The `ux_uq` answer comes from REDCap's filtered read with `lower()`: if it says not used before, that REDCap does not honour `lower()` in `filterLogic`; record the version |
| J7 | Leave XE-2 without saving. Run the Validation scan with `ux_uq` = uq-1 saved on XE-2 (clear `ux_uq_cs` first, or save it too) | Two "Duplicate value" rows for `ux_uq` (XE-1 and XE-2); none for `ux_uq_cs` |
| J8 | On XE-2, `ux_spec` = S-998 and leave the field (red). In a second tab, register S-998 as a specimen of XE-1 and save. Back in the first tab, at least 30 seconds after the red answer, press Save | That click asks again instead of trusting the red answer: an alert says the field is still being checked, then the field turns green. The next Save goes through |
| J9 | On XE-1 save `ux_uq_num` = 7. Open XE-2, type 007 and leave the field | "already recorded (record XE-1)": REDCap's filtered read compares the number by value. If it says not used before, record the REDCap version |

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

## Cleanup

Delete the `uv_exists_test` instrument, or restore the dictionary downloaded in
setup step 2. Delete the record C2 created (and any record H9 saved), and the
specimen J8 registered on XE-1. Remove the
survey setting if D1 enabled it, and the one-section-per-page setting if D5
changed it. After section I, delete the `uv_exists_cross` instrument and the alias
row, delete the source project, and turn "@UVEXISTS in other projects" off
again unless it stays in use.
