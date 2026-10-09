# @UVEXISTS live acceptance protocol

Live test of lookups on a real REDCap, and of the form-rights and rate checks
that now also guard `@UVUNIQUE`. The automated suites (`tests/exists_php.php`,
`tests/exists_dom_js.cjs`) prove the logic against stubs. This run checks
REDCap's real `getData` (its `filterLogic` and event filters), real AJAX calls,
real rights and the module log.

Target: chpr-redcap.org pid 149 (longitudinal, repeating, seed records XE-1 to
XE-4). Fixture: [`uvexists_test_fields.csv`](uvexists_test_fields.csv), 19
fields on one new instrument `uv_exists_test`. Before this sheet was written,
every annotation and every lookup below was run offline through the module
against the dictionary and the seed data in `event_instance/`: the 13 rule
fields configure, the 6 `ux_bad_*` fields show the errors in section F, and
each "Expect" below is what the module answered.

---

## Setup

1. Deploy the release that contains `@UVEXISTS` and confirm the version in
   Control Center.
2. **Append, never replace.** Download the current dictionary, append the 19
   rows of the CSV, upload. The diff must report one new instrument and 19 new
   fields, and nothing else.
3. Designate `uv_exists_test` on `event_1_arm_1` (event 351).
4. Seed values used below: specimen IDs (`xs_id`, repeating `xr_specimen`)
   S-001 and S-002 at Baseline and S-003 at Visit 1 for XE-1; S-100 (Sputum
   and Blood) and S-101 (Urine) at Baseline for XE-2. Participant codes
   (`xe_code`) PX-0001 to PX-0004; every consent date is 2026-01-10; every
   enrolment site is 1 (North).
5. Form URL: `DataEntry/index.php?pid=149&id=XE-1&event_id=351&page=uv_exists_test`.

Read the injected config at any point with:

```js
JSON.parse(document.getElementById('inspire-validator-config').textContent)
```

---

## Section A: found, not found, and the exact comparison

| # | Action | Expect |
|---|---|---|
| A1 | Read the config rules for `ux_spec` and `ux_spec_m` | No `existsIn`, `existsEvent`, `existsScope`, `existsMatch` or `existsTargets` key; `xs_id` appears nowhere in the config. `ux_spec_m` carries `existsLocal: ["ux_type"]` |
| A2 | `ux_rec` = XE-2, leave the field | Green "Found." with no record named |
| A3 | `ux_rec` = XE-9, press Save | Red message; save blocked (hard) |
| A4 | `ux_spec` = S-003 | Green "Found (record XE-1)." |
| A5 | `ux_spec` = S-999, press Save | Red "Register this specimen on the specimen form first."; save blocked |
| A6 | `ux_spec` = s-003 | Red: the comparison is case-sensitive |
| A7 | `ux_spec` = ` S-001 ` (spaces around) | Green: values are trimmed |
| A8 | `ux_spec_bl` = S-001, then S-003 | Green, then red: S-003 is registered at Visit 1, not Baseline. Save goes through (advisory) |
| A9 | `ux_code` = PX-0003, then PX-9999 | Found (record XE-3), then not found |
| A10 | `ux_consent_dmy` = 10-01-2026, then 11-01-2026 | Found (stored as 2026-01-10), then not found |

## Section B: when the page asks

Open the browser's network panel and filter on `exists-check`.

| # | Action | Expect |
|---|---|---|
| B1 | Open the form with `ux_spec` already saved | One request per saved lookup field as the page loads |
| B2 | Click into `ux_spec` and type S-0, S-00, S-003 slowly | The previous answer disappears on the first keystroke. **No request while typing**, also after pausing |
| B3 | Leave the field (Tab) | One request; green "Found" |
| B4 | Click into the field and out again without changing it | No new request (the answer is cached) |
| B5 | `ux_type` blank; type S-100 in `ux_spec_m` and leave it | No request, no message: a blank match field leaves nothing to narrow by |
| B6 | `ux_type` = Blood | One request; "Found (record XE-2)." |
| B7 | `ux_type` = Sputum; `ux_spec_m` = S-101 | Red: S-101 is registered as Urine |
| B8 | `ux_gate` = No; `ux_when` = S-999 | No request, no message |
| B9 | `ux_gate` = Yes | One request; red; save blocked (hard) |

## Section C: could not check

| # | Action | Expect |
|---|---|---|
| C1 | `ux_code_site` = PX-0002 | Found: XE-2 is also site 1 (matched by `xe_site`, saved on `xe_enrol`) |
| C2 | On a **new** record (no `xe_enrol` saved yet), `ux_code_site` = PX-0002 | Amber "Could not check this value just now ([xe_site] is blank, so there is nothing to match against yet)". Save allowed |
| C3 | Account with **no access** to `xr_specimen`: open XE-1, `ux_spec` = S-003 | Amber "Could not check ... (you do not have access to the form that holds [xs_id])"; save allowed; nothing about S-003's record shown |
| C4 | Same account: `ux_rec` = XE-2 | Found: a record-ID lookup needs no extra form |
| C5 | **[optional]** In the browser console, run `for (let i=0;i<65;i++) ExternalModules.<prefix>.ajax('exists-check',{field:'ux_code',values:{ux_code:'PX-'+i}})` and inspect the replies | The replies after the 60th in that minute are `{"state":"unknown", ... "too many checks ..."}`. A `unique-check` in the same minute returns "too many checks — slow down" |

## Section D: surveys

| # | Action | Expect |
|---|---|---|
| D1 | Enable `uv_exists_test` as a survey and open it | Only `ux_survey` asks the server; `ux_spec` and the others show nothing |
| D2 | `ux_survey` = PX-0001, then PX-9999 | Found (no record named), then not found |
| D3 | **[optional]** Flag `xe_code` as an Identifier in the dictionary | `ux_survey` shows a configuration error naming `xe_code`. Unflag after |

## Section E: post-save audit and scan

| # | Action | Expect |
|---|---|---|
| E1 | On XE-1 save `ux_spec_bl` = S-003 (advisory: red, does not block) | Module log: `invalid-id-saved`, `type: exists`, reason `not-found`, field `ux_spec_bl` |
| E2 | **[optional]** Import `ux_spec` = S-999 for XE-2 through the Data Import Tool | Module log: reason `not-found` (if this REDCap version runs the save hook on imports; otherwise E3 finds it) |
| E3 | Run the Validation scan | XE-1 `ux_spec_bl` with Issue "Not found in its source" (and XE-2 `ux_spec` if E2 ran) |
| E4 | **[only if the project has DAGs]** Run the scan confined to one Data Access Group | Every `@UVEXISTS` rule except `ux_dag` is listed as not evaluated: "looks for the value across every Data Access Group" |

## Section F: configuration errors

Each field shows a visible error and asks nothing:

| Field | Error contains |
|---|---|
| `ux_bad_self` | "in" names this field itself |
| `ux_bad_nofield` | "no_such_field" is not a field in this project |
| `ux_bad_multi` | "xe_consent" is a checkbox field |
| `ux_bad_family` | "xe_consent_date" holds dates and "ux_bad_family" holds no date |
| `ux_bad_event` | "nowhere_arm_1" is not an event of this project |
| `ux_bad_notes` | does not support "notes" fields |

## Section G: `@UVEXISTS` with `@UVUNIQUE`, and the new `unique-check` gate

| # | Action | Expect |
|---|---|---|
| G1 | On XE-1 save `ux_both` = S-001 | Found; not used before; saved |
| G2 | On XE-2, `ux_both` = S-001 | Two messages: found (exists) and "already recorded (record XE-1)" (unique); save blocked |
| G3 | Account with no access to `uv_exists_test`'s form, through the console: `ExternalModules.<prefix>.ajax('unique-check',{field:'ux_both',values:{ux_both:'S-001'}})` | `{"error":"not a checkable field"}` |

---

## Sign-off

| # | Gate | Result |
|---|---|---|
| 1 | Section A: verdicts match, comparison exact | ☐ |
| 2 | Section B: no request while typing; one per change | ☐ |
| 3 | Section C: "could not check" never blocks, rights respected | ☐ |
| 4 | Section D: surveys opt-in, no record named | ☐ |
| 5 | Section E: audit and scan agree with the browser | ☐ |
| 6 | Section G: composition and the `unique-check` gate | ☐ |

## Cleanup

Delete the `uv_exists_test` instrument, or restore the dictionary downloaded in
setup step 2. Remove the survey setting if D1 enabled it.
