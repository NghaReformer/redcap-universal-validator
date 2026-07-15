# Live test plan — v0.9.1 on chpr-redcap.org pid 149

Covers everything added since 0.7.1: conditional validation (`when`), branched
validation, opt-in check-character hints, pooled chip severity, and the SEC-005
fix (no record value in the page). The existing `id_validation_test` instrument
already covers the ≤0.7.1 features and is left untouched.

Every "type this" value below was minted with the engine itself and every
annotation was pushed through the real parser + branch resolver before this file
was written, so an unexpected result here is a genuine finding, not a typo.

---

## 0. Setup

1. **Confirm the deployed version.** Control Center → External Modules, or on any
   data-entry form open the console:
   ```js
   JSON.parse(document.getElementById('inspire-validator-config').textContent).rules.length
   ```
   The module list must read **v0.9.1**. (0.9.0 will fail §5 — that is the bug
   0.9.1 fixes, and §5 is written so you can see the difference.)

2. **Add the test fields.** `uvalidate_091_test_fields.csv` in this folder holds
   26 new fields on two NEW instruments (`staff_review`, `wb_test`). A REDCap
   dictionary upload REPLACES the whole dictionary, so **append, do not upload
   this file on its own**:
   - Project → **Data Dictionary** → *Download the current data dictionary*
   - Open both files, copy the 26 data rows from this CSV (everything except the
     header) to the bottom of the downloaded file, save
   - Upload the merged file. REDCap shows a diff first — it must report only
     ADDITIONS (2 new instruments, 26 new fields) and no deletions.

3. **Create/pick a record** and open **Staff Review** first:
   - `sr_elig` → `SECRET-PATIENT-042`
   - `sr_hiv` → `HIV-POSITIVE-CONTROL`
   - leave `sr_consent` unticked → **Save**

Then open **When & Branching Test** for the same record. Unless a step says
otherwise, leave *Specimen type* on **Sputum**.

---

## 1. Conditional validation (`when`) — fields react live

| Field | Do | Expect |
|---|---|---|
| `wb_when_drop` | Specimen type = **Sputum**, type `BLD000010` | No message at all — the rule is inert |
| | switch to **Blood** (do not touch the ID) | Message appears immediately: check-character error |
| | fix to `BLD000019` | ✓ verified |
| | switch back to **Other** | Message clears; the value stays (it is NOT erased) |
| `wb_when_cb` | leave *Consent given?* unticked, type `57241` | inert |
| | tick **Yes** | check-character error appears |
| | fix to `57240` | ✓ verified |
| `wb_when_radio` | Sex = **Male**, type `23611` | inert |
| | pick **Female** | error appears (radio DOM is read live) |
| | fix to `23610` | ✓ verified |

**The point:** a false condition means *not validated*, not *erased* — the
deliberate difference from REDCap's own branching logic.

---

## 2. Branched validation — several rules, one field

`wb_branch`: Sputum → Verhoeff (**hard block**) | Blood → Mod 37,36 (message only).

| Do | Expect |
|---|---|
| Specimen type = **Sputum**, type `23610` | ✓ verified (valid Verhoeff) |
| switch to **Blood** | ✗ check-character error — the *other* branch now judges the same value |
| switch back to **Sputum** | ✓ verified again |
| type `23611` (bad Verhoeff), Sputum, click **Save** | Save is **blocked**, focus jumps to the field |
| switch to **Blood**, click **Save** | Save is **allowed** — the Blood branch is message-only |

**The point:** enforcement is per branch, and the last row is the one to watch —
the same invalid value blocks under one branch and not the other.

`wb_branch_else`: Sputum → Verhoeff | anything else → Damm (the fallback).

| Do | Expect |
|---|---|
| **Sputum** + `23610` | ✓ verified |
| switch to **Blood** | ✗ error (Damm judges it now) |
| type `57240` | ✓ verified (valid Damm) |
| switch to **Other** | still ✓ — the else branch covers every non-Sputum value |
| switch to **Sputum** | ✗ error (Verhoeff again) |

`wb_pool_branch` (pooled branches):

| Do | Expect |
|---|---|
| **Sputum**, paste `PL000001C PL000002A` | 2 green chips, "all verified" |
| switch to **Blood** | the same text is now judged as 11-char Luhn → red/junk chips |
| paste `79927398713 12345678903` | 2 green chips |

---

## 3. Conflict — two conditions true at once

`wb_conflict` deliberately overlaps: `[wb_stype]<>'3'` and `[wb_stype]='1'`.

| Do | Expect |
|---|---|
| **Blood** (only the first matches), type `23610` | ✓ verified |
| switch to **Sputum** — now BOTH are true | ⚠ **"Validation conflict"** naming *both* conditions; no verdict |
| click **Save** with the conflict showing | Save is **NOT blocked** (a config problem must never trap a user) |
| switch to **Other** | no branch matches → field inert |

Then check the module log (**Logging** → filter to this module, or *View Logs*
under External Modules): the Sputum save must leave one
**`uvalidate-unconfigurable`** entry saying *branch conflict* — the conflict is
never silent.

---

## 4. Check-character hints are opt-in (0.9.0)

| Field | Do | Expect |
|---|---|---|
| `wb_hint_off` | type `57241` | error ending at "…re-scan or re-type it." — **no** "should end in" |
| `wb_hint_on` | type `57241` | same error **plus** "…the ID should end in **0**." |

**The point:** the hint reveals the expected check character, which can tempt
staff to force-fit a mistyped ID. It is now off unless a rule asks for it.

---

## 5. SEC-005 — no record value reaches the page ⚠ the important one

`wb_offpage` is validated only when the **staff form** says `ELIGIBLE`. You set
`sr_elig` to `SECRET-PATIENT-042` in setup, so the condition is false.

1. Open **When & Branching Test**, type `57241` into `wb_offpage` → **inert**
   (condition false).
2. **View Source** (Ctrl+U) → find `inspire-validator-config`. Then:

| Check | v0.9.1 (expected) | v0.9.0 (the bug) |
|---|---|---|
| Search the page for `SECRET-PATIENT-042` | **not found** | **found** in a `whenValues` block |
| The `wb_offpage` rule's condition | `"whenAst":["const",false]` | `"when":"[sr_elig]='ELIGIBLE'"` + the raw value |
| A `whenValues` key exists | **no** | yes |

   Console one-liner:
   ```js
   const c = JSON.parse(document.getElementById('inspire-validator-config').textContent);
   ({ leaks: document.documentElement.outerHTML.includes('SECRET-PATIENT-042'),
      hasWhenValues: 'whenValues' in c,
      offpageRule: c.rules.find(r => (r.fields||[]).includes('wb_offpage')) })
   ```
   Want: `leaks: false`, `hasWhenValues: false`, and the rule carrying
   `whenAst: ["const", false]`.

3. `sr_hiv` (`HIV-POSITIVE-CONTROL`) is referenced by nothing — it must not
   appear in the config in **any** version.
4. Now go back to Staff Review, set `sr_elig` = `ELIGIBLE`, save, reopen this
   form: `wb_offpage` validates (`57240` ✓ / `57241` ✗), and the config shows
   `["const",true]` — still no value.
5. **Repeat on the survey.** `wb_test` must be enabled as a survey; open its
   public link and run the same page-source check. This is the case that
   mattered: a respondent must never be able to read a staff field.
6. `wb_offpage_cb`: tick **Written** on the staff form, save, reload → validates
   (`23610` ✓). Off-instrument *checkbox* refs fold the same way.
7. `wb_mixed_ref`: condition is `[wb_stype]='2' and [sr_elig]='ELIGIBLE'`. The
   `wb_stype` half stays live (flip the dropdown and it re-checks); the
   `sr_elig` half was settled on the server. With staff = ELIGIBLE and Blood
   selected → `57240` ✓.

---

## 6. Negative configs — each must show an error, never validate

Open the form and read the message under each. None may validate anything.

| Field | Expected message contains |
|---|---|
| `wb_err_ref` | `not a field in this project` |
| `wb_err_cbcode` | `is a checkbox — reference one option as [wb_cb(code)]` |
| `wb_err_badcode` | `has no choice code "9"` |
| `wb_err_func` | `functions such as "datediff("… are not supported` |
| `wb_err_event` | `no [event][field] prefixes` |
| `wb_err_twouncond` | `at most ONE unconditional rule may share a field` |
| `wb_err_identical` | `the identical condition "[wb_stype]='1'"` |
| `wb_err_mixedtype` | `all rules sharing a field must have the same field type` |

Also try the **save-time gate**: Configure the module on this project, add two
rules both covering `wb_when_drop` with no condition, and Save → the dialog must
refuse with *"Rule 1 and Rule 2 … at most ONE unconditional rule"*.

---

## 7. Regression — nothing from before broke

On `id_validation_test` (the original instrument), spot-check:

- `main_id_tag`: `8QRS-55555E` ✓ / `8QRS-55556E` ✗
- `fc_id` hard block still blocks; `zrc_id` still asks to confirm
- the 4 deliberately-broken tags still show their config errors
- `redos_poly` / `redos_exp` still show the ReDoS config error and the tab stays responsive
- `pool_main`: paste `1ABC-00001E 1ABC-00001E 8QRS-55556E zz` → duplicate chip is
  **amber ⊗ (again!)**, junk chip is **red ?** (the 0.9.0 swap)

---

## 8. Server audit

After the runs above, open **External Modules → View Logs** (or Logging) and
confirm:

- invalid values saved while a condition was **true** produce `invalid-id-saved`
  entries naming the branch's algorithm;
- values saved while a condition was **false** produce **no** entry;
- the `wb_conflict` Sputum save produced exactly one `uvalidate-unconfigurable`.

> Known caveat from earlier testing on 17.0.6: the post-save audit has previously
> produced no module-log entries on a live instance (see the project's
> `validator-audit-log-not-firing-live` note). If §8 is silent, that is the
> pre-existing open issue, **not** something 0.9.1 introduced — browser
> enforcement (§1–§4) is unaffected.

---

## Cleanup

The two instruments are additive and self-contained. To remove them, delete the
`staff_review` and `wb_test` instruments in the Online Designer, or re-upload the
dictionary you downloaded in step 0.2.

---

## Appendix — REDCap pipes `[field]` in Field Notes (learned the hard way)

The first cut of this test bed wrote condition documentation into the field
notes literally, e.g. *"the `[sr_elig]` half is settled on the server"*. REDCap
**pipes** `[field]` references in Field Notes and Labels: it substituted the
staff record's real value into the specimen form's HTML, which made the test
bed itself leak the value the SEC-005 check is looking for — a false positive
on the grep, and a genuine (if small) exposure of its own, since a
survey-enabled form would show it to respondents.

The rows in `uvalidate_091_test_fields.csv` therefore refer to fields WITHOUT
brackets in notes/labels (`sr_elig`, not `[sr_elig]`); only the `@UVALIDATE`
annotations use bracket syntax, where it is required and is never piped.

Two things follow:

- When grepping a page for a leak, use a record value that appears nowhere in
  any note, label, or condition literal (`ZZTOPSECRET9`, not a word that is
  also the condition's own literal). Otherwise you cannot tell a leak from your
  own documentation.
- The module is not the only way data reaches a page. Piping, `@DEFAULT`,
  smart variables and custom JS all do too. SEC-005 is about what the MODULE
  ships (`inspire-validator-config`); check that node specifically, not just
  the raw HTML.
