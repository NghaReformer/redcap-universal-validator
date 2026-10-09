# @UVEXISTS live acceptance protocol

Live test of lookups on a real REDCap, and of the form-rights, group and rate
checks that now also guard `@UVUNIQUE`. The automated suites
(`tests/exists_php.php`, `tests/exists_dom_js.cjs`, `tests/unique_dom_js.cjs`)
prove the logic against stubs. This run checks REDCap's real `getData` (its
`filterLogic`, event filters and Data Access Group behaviour), real AJAX calls,
real rights, real widgets (radio reset link, autocomplete, date picker) and the
module log.

Target: chpr-redcap.org pid 149 (longitudinal, repeating, seed records XE-1 to
XE-4). Fixture: [`uvexists_test_fields.csv`](uvexists_test_fields.csv), 25
fields on one new instrument `uv_exists_test`: 16 rule fields carry a valid
`@UVEXISTS`, 6 `ux_bad_*` fields carry a broken one, and `ux_type`, `ux_gate`
and `ux_site_sel` carry no tag. Before this sheet was written, every annotation
and every lookup below was run offline through the module against the
dictionary and the seed data in `event_instance/`: the 16 rule fields
configure, the 6 `ux_bad_*` fields show the errors in section F, and each
"Expect" below is what the module answered.

---

## Setup

1. Deploy the release that contains `@UVEXISTS` and confirm the version in
   Control Center.
2. Append, never replace. Download the current dictionary, append the 25
   rows of the CSV, upload. The diff must report one new instrument and 25 new
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

## Section A: found, not found, and the exact comparison

Wait for each answer (green, red or amber) before the next step, unless the
step says otherwise.

| # | Action | Expect |
|---|---|---|
| A1 | Read the config rules for `ux_spec` and `ux_spec_m` | No `existsIn`, `existsEvent`, `existsScope`, `existsMatch` or `existsTargets` key; `xs_id` appears nowhere in the config. `ux_spec_m` carries `existsLocal: ["ux_type"]` |
| A2 | `ux_rec` = XE-2, leave the field | Green "Found." with no record named |
| A3 | `ux_rec` = XE-9, leave the field, wait for the red message, press Save | Red message; save blocked (hard) |
| A4 | `ux_spec` = S-003 | Green "Found (record XE-1)." |
| A5 | `ux_spec` = S-999, leave the field, wait for the red message, press Save | Red "Register this specimen on the specimen form first."; save blocked |
| A6 | `ux_spec` = s-003 | Red: the comparison is case-sensitive |
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
| E3 | Run the Validation scan | XE-1 `ux_spec_bl` with Issue "Not found in its source" (and XE-2 `ux_spec` if E2 ran) |
| E4 | [only if the project has DAGs] Run the scan confined to one Data Access Group | Every `@UVEXISTS` rule except `ux_dag` is listed as not evaluated: "looks for the value across every Data Access Group". No finding is reported for those rules |
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

---

## Sign-off

| # | Gate | Result |
|---|---|---|
| 1 | Section A: verdicts match, comparison exact, typed-then-Save blocked | ☐ |
| 2 | Section B: no request while typing; one per change; cache | ☐ |
| 3 | Section C: "could not check" never blocks, rights respected | ☐ |
| 4 | Section D: surveys opt-in, no record named, no field named in errors | ☐ |
| 5 | Section E: audit and scan agree with the browser | ☐ |
| 6 | Section G: composition and the `unique-check` gate | ☐ |
| 7 | Section H: choice fields, reset, autocomplete, branches, groups | ☐ |

## Cleanup

Delete the `uv_exists_test` instrument, or restore the dictionary downloaded in
setup step 2. Delete the record C2 created (and any record H9 saved). Remove the
survey setting if D1 enabled it, and the one-section-per-page setting if D5
changed it.
