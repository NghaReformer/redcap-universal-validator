# Live test plan — v1.4.0 on chpr-redcap.org pid 149

The full pre-deployment acceptance run for the 1.x expansion: cross-field
constraints (`@UVASSERT`, 1.0.0), conditional required (`@UVREQUIRED`, 1.1.0),
the dialog rule-kind selector (1.2.0), uniqueness (`@UVUNIQUE` + the AJAX
endpoint, 1.3.0) and the Validation scan page (1.4.0) — plus regression over
everything ≤0.9.1 (the older sections of this file's previous revision are
folded into §9).

Every annotation in `uvalidate_140_test_fields.csv` was pushed through the
real parser, the branch resolver, the per-mode field-type gates and the
reference checker before this file was written, and every check-digit or
comparison claim in a field note was recomputed with the engine — an
unexpected result here is a genuine finding, not a typo.

**Two things only a live instance can prove** (mock-tested until now):
the JSMO AJAX transport for `@UVUNIQUE` (§5) and the Validation scan page
(§6). Treat those two sections as the release gate.

---

## 0. Setup

1. **Confirm the deployed version** — Control Center → External Modules must
   read **v1.4.0**. On any data-entry form, the console must show the config:
   ```js
   JSON.parse(document.getElementById('inspire-validator-config').textContent).rules.length
   ```

2. **Add the test fields.** `uvalidate_140_test_fields.csv` holds 21 fields on
   two NEW instruments (`uv_hidden_test`, `uv_modes_test`). A dictionary upload
   REPLACES everything, so **append, never upload this file alone**:
   - Project → Data Dictionary → download the current dictionary
   - Append the 21 data rows of this CSV to the bottom, save, upload
   - The diff must report ONLY additions (2 instruments, 21 fields)

3. **Enable `uv_modes_test` as a survey** (needed for §5.6, §7.3 and §8).

4. **Seed data:**
   - Record A: open **Hidden Test**, set `uht_code` = `ZZTOPSECRET77`, save.
     Open **Modes Test**, set `umt_pid` = `UNIQ-1001`, `umt_spec` = `SP-500`,
     `umt_specsite` = North, save.
   - Record B: leave it empty for now — most §1–§5 steps run here.

5. **DAG prep for §5.5/§6.5** (skip if pid 149 must stay DAG-free — mark those
   steps N/A): create DAGs `north`/`south`, assign Record A to `north`, and
   have one test account assigned to `south`.

---

## 1. Cross-field constraints (`@UVASSERT`) — on Record B, Modes Test

| Field | Do | Expect |
|---|---|---|
| `umt_end` | start = 2024-01-15, end = 2024-01-01 | red custom message; **Save blocked**, focus jumps here |
| | change end to 2024-02-01 | ✓ OK; save allowed |
| | now move START to 2024-03-01 (do not touch end) | the END field re-checks **live** and turns red again |
| `umt_dose` | type `150` | red message; Save asks *"Save anyway?"* (advisory) |
| | type `99` | ✓ (numeric compare — not lexicographic) |
| | type `100.5` | red (100.5 > 100 numerically) |
| `umt_grade` | pick **Unknown** | red GENERIC wording (no custom message set) |
| | pick Grade A | ✓ |
| `umt_sex` | pregnant = **Yes**, sex = **Male** | blocked with the custom message (radio constraint) |
| | pregnant = **No** | message clears — the when-gate turned the rule off |
| `umt_id_confirm` | `umt_id` = 23610, confirm = 23611 | mismatch message, blocked (double entry) |
| | confirm = 23610 | ✓ |
| `umt_branch` | Type one + `-5` | blocked: *positive* branch message |
| | switch to Type two (keep `-5`) | ✓ — the *negative* branch passes it |
| | switch to Type three | inert (no branch applies) |

**Empty is inert:** clear `umt_end` entirely → no message, save allowed
(emptiness is `@UVREQUIRED`'s job, not a constraint's).

---

## 2. Conditional required (`@UVREQUIRED`)

| Field | Do | Expect |
|---|---|---|
| `umt_phone` | consent = **Yes**, leave blank | red notice; **Save blocked** |
| | type anything | notice **clears** — deliberately no green tick |
| | type spaces only | still counts as blank; blocked again |
| | consent = **No** | blank is fine; notice gone, save allowed |
| `umt_site` | leave blank | notice shows but the save **goes through** (informational default) |
| `umt_score` | just look at it | a **configuration error** under the calc field — the tag was refused, never validating |

---

## 3. Mode composition — `umt_both` (one field, two independent rules)

| Type | Expect |
|---|---|
| `23611` | check-character block (constraint is satisfied) |
| `23610` | valid check — but the RESERVED message blocks (independent guards; a passing rule never clears the other's block) |
| `12340` | both pass; save allowed |

---

## 4. The Configure dialog (1.2.0)

In the module's Configure dialog on this project:

1. Add a rule, kind **Constraint**, field `umt_dose`, condition box empty →
   Save must refuse: *"…needs a non-empty assert condition"* (row named).
2. Kind **Required**, field `umt_score` (the calc) → refused: *"…not calc"*.
3. Kind **Unique**, field `umt_pid`, composite box `no_such_field` → refused.
4. Kind **Constraint**, field `umt_dose`, condition `[umt_dose]>='0'`,
   and deliberately ALSO paste `(a+)+` into the format-pattern box → **saves
   fine** (the pattern box is ignored for constraints) and the rule works on
   the form. Delete the rule afterwards.

---

## 5. Uniqueness (`@UVUNIQUE`) — ⚠ first live proof of the AJAX transport

On Record B, Modes Test:

1. **Transport sanity.** Focus `umt_pid`, type `UNIQ-1001` (Record A's value),
   tab out → *"checking…"* then the custom message naming **Record A's id**;
   Save blocked. Console must show no errors; the Network tab shows one
   module-AJAX POST (CSRF token present).
2. **Free value.** Type `UNIQ-2002` → green *"Not used before."*; save allowed.
   Retype the same value → **no second network request** (answer cache).
3. **Self-match.** Reopen Record A: its own `umt_pid` = `UNIQ-1001` must show
   green (a record never collides with itself).
4. **Composite.** Record B: `umt_spec` = `SP-500` + site **North** → advisory
   warning (same code, same site). Switch site to **South** → passes (the
   composite key differs).
5. **DAG masking** (if §0.5 done): as the `south` user, type `UNIQ-1001` into
   a fresh record → *used* but **without** a record id (Record A is in
   `north`).
6. **Surveys are opt-in.** Open the `uv_modes_test` survey link:
   `umt_supid` still checks live (opted in) but a duplicate names **no
   record**; `umt_pid` shows nothing at all (not opted in).
7. **Fail-open.** DevTools → Network → Offline, then edit `umt_pid` → console
   notes the failure, field stays unflagged, **save is never trapped**.
8. **The race.** Two browsers on two new records; type the same fresh value in
   both while both show green; save both quickly. The second save must appear
   in the module log as `invalid-id-saved` with `type: unique` (this is §8's
   entry) — and §6's scan must also list both records.

---

## 6. The Validation scan (1.4.0) — ⚠ first live proof

1. **Link + rights.** "Validation scan" appears on the left menu for a
   design-rights user; a data-entry-only account must not see the link, and
   pasting the URL directly must show the rights refusal.
2. **Seed violations, then scan.** Ensure at least: one bad check value
   (`umt_id` = 23611 saved via a non-blocking route — e.g. save with the
   advisory dialog accepted, or import), one end<start pair, one blank
   `umt_phone` with consent Yes, and the §5.8 duplicate pair. Run the scan →
   every seeded violation listed with record / event / instance / field /
   rule / kind / reason.
3. **No values anywhere.** Search the scan page source AND the downloaded CSV
   for `ZZTOPSECRET77`, `UNIQ-1001` and `23611` — none may appear (the report
   names *where*, never *what*).
4. **CSV.** Downloads, opens in Excel, one row per violation; a cell beginning
   with `=` `+` `-` `@` is formula-defused (leading apostrophe).
5. **DAG confinement** (if §0.5 done): as the `south` user the scan must count
   only south records and never name a north record id.
6. **The import gap — the reason this page exists.** Data Import Tool: import
   a row with `umt_id` = 23611 and `umt_end` earlier than `umt_start`. Check
   the module log (per the standing 17.0.6 issue the post-save audit may be
   silent for imports) — then run the scan: **both violations must appear**
   regardless of whether the hook fired.
7. **Performance spot-check** (PRE-gate): on a project with 1000+ records the
   scan completes without a memory error (chunked reads) — time it and note
   the duration.

---

## 7. SEC-005 sweep for the new modes

1. **Assert refs fold.** Record with `uht_code` saved: open Modes Test,
   View Source → `ZZTOPSECRET77` must NOT appear anywhere; the `umt_offref`
   rule in `inspire-validator-config` carries a folded `assertAst` (constants
   in place of the off-instrument comparison), not the value.
   ```js
   const c = JSON.parse(document.getElementById('inspire-validator-config').textContent);
   ({ leak: document.documentElement.outerHTML.includes('ZZTOPSECRET77'),
      offref: c.rules.find(r => (r.fields||[]).includes('umt_offref')) })
   ```
   Want `leak: false`.
2. **jsmoName scoping.** On Modes Test (has unique rules) the config carries
   `jsmoName`; on `id_validation_test` (none) it must NOT, and no JSMO
   bootstrap script is injected there.
3. **Survey wording.** On the survey, force a constraint failure and a config
   error into view: respondents must see the generic wording, never field
   names, conditions, or technical detail.
4. Repeat the ≤0.9.1 `wb_offpage` checks (§9) — unchanged behavior expected.

---

## 8. Server audit — module log entries for the new modes

After §1–§6, External Modules → View Logs must contain:

| Entry | From |
|---|---|
| `invalid-id-saved` with `type: constraint`, reason starting `assert:` | any end<start save that got through (advisory/import) |
| `invalid-id-saved` with `type: required`, reason `required-blank` | a blank-phone-with-consent save |
| `invalid-id-saved` with `type: unique`, reason `duplicate-value` | the §5.8 race |
| value handling per the project's privacy mode | hashed by default — no raw values in any entry |

> The standing 17.0.6 caveat applies: if the post-save audit is silent for
> *imports*, that is the pre-existing hook-coverage issue — §6.6 (the scan) is
> the mitigation shipped for exactly that, and it must find what the hook
> missed.

---

## 9. Regression — nothing before 1.x broke

Run the previous revision's sections against their original instruments
(`id_validation_test`, `staff_review`, `wb_test`); this file's git history
holds the full 0.9.1 text. Spot-check at minimum:

- `main_id_tag`: `8QRS-55555E` ✓ / `8QRS-55556E` ✗; hard block and confirm
  modes still enforce; the deliberately-broken tags still show config errors;
  ReDoS fields stay responsive.
- `wb_when_drop` / `wb_when_cb` / `wb_when_radio`: conditions react live;
  false condition = inert, value NOT erased.
- `wb_branch` / `wb_branch_else` / `wb_pool_branch`: per-branch verdicts and
  enforcement; `wb_conflict`: conflict notice, save never trapped, one
  `uvalidate-unconfigurable` log entry.
- `wb_hint_off` / `wb_hint_on`: the check-character hint stays opt-in.
- `wb_offpage` + survey: the 0.9.1 SEC-005 checks (no `whenValues`, no staff
  value in source, `["const",…]` in the config).
- Pooled chips: duplicate amber, junk red.

---

## 10. Sign-off checklist

| # | Gate | Result |
|---|---|---|
| 1 | §1–§4 all pass (constraints, required, compose, dialog) | ☐ |
| 2 | §5 transport proven live (incl. fail-open + race) | ☐ |
| 3 | §6 scan proven live (incl. the import case) | ☐ |
| 4 | §7 SEC-005 sweep clean on form AND survey | ☐ |
| 5 | §8 audit entries present with correct privacy handling | ☐ |
| 6 | §9 regression clean | ☐ |
| 7 | §6.7 performance spot-check on 1000+ records | ☐ |
| 8 | Control Center security scan re-run and archived (PRE-002) | ☐ |
| 9 | Screen-reader pass per docs/TESTING.md on one constraint + one required field | ☐ |

---

## Cleanup

The two instruments are additive and self-contained: delete `uv_hidden_test`
and `uv_modes_test` in the Online Designer, or restore the dictionary
downloaded in §0.2. Remove any dialog rules added in §4 and the DAGs from
§0.5 if they were created only for this run.

---

## Appendix — REDCap pipes `[field]` in Field Notes (learned the hard way)

An earlier revision of this test bed wrote condition documentation into field
notes literally, e.g. *"the `[sr_elig]` half is settled on the server"*.
REDCap **pipes** `[field]` references in Field Notes and Labels: it
substituted the staff record's real value into the specimen form's HTML,
which made the test bed itself leak the value the SEC-005 check looks for — a
false positive on the grep and a small real exposure of its own.

The rows in both testbed CSVs therefore refer to fields WITHOUT brackets in
notes and labels; only the action-tag annotations use bracket syntax, where
it is required and never piped. Two consequences:

- When grepping a page for a leak, use a value that appears in no note,
  label, or condition literal (`ZZTOPSECRET77`), or you cannot tell a leak
  from your own documentation.
- The module is not the only way data reaches a page — piping, `@DEFAULT`,
  smart variables and custom JS all do too. SEC-005 is about what the MODULE
  ships (`inspire-validator-config` and, since 1.3.0, the AJAX responses);
  check those specifically, not just the raw HTML.
