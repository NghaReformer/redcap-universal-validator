# @UVCHOICES live acceptance protocol — v1.5.0

Comprehensive live test of dynamic choice filtering on a real REDCap. This is
the executable companion to the automated suites (`tests/choices_php.php`,
`tests/choices_dom_js.cjs`, 37 + 58 checks) — those prove the LOGIC in
isolation; this proves the module behaves correctly against **real REDCap
markup, real save paths, and real browsers**, which the stubs cannot.

Target: chpr-redcap.org **pid 149** (the 1.x testbed) unless you say otherwise;
**pid 134** for the cross-project isolation check. Fixture:
[`uvalidate_150_test_fields.csv`](uvalidate_150_test_fields.csv) — 11 fields on
one new instrument `uv_choices_test`.

---

## How I will run this

When you tell me the server is on v1.5.0 and give me a route in (a logged-in
browser session — I cannot enter your credentials myself), I drive the form
through the in-app browser: navigate to the data-entry form, read the rendered
DOM, click radios / pick dropdown options / tick checkboxes, submit, and read
back the DOM, the module log, and the page source. For each case below I record
the observed result against the **Expect** line and mark PASS / FAIL, then hand
you a single results table with every failure reproduced.

**What I need from you before I start:**
1. v1.5.0 deployed and enabled on the test project.
2. A browser session already logged in to REDCap (I take it from there).
3. Confirmation of the project id (default assumption: pid 149).
4. The `uv_choices_test` fields present — either you upload the merged
   dictionary, or tell me to walk you through it. **Never upload the CSV
   alone: a dictionary upload REPLACES the whole dictionary.** Append these 11
   rows to a fresh download of the current dictionary, then upload.

**Gate 0 — the load-bearing assumption (do this FIRST).** Before any
behavioral test, I inspect the real rendered markup of a radio and a checkbox
option on `uv_choices_test` and confirm that hiding `input.parentNode` hides
the whole option row (label included) on REDCap 17.0.6. The client hides the
option input's parent element; if REDCap wraps the label as a *sibling* rather
than a parent, that is the one thing that must be fixed before the rest of the
protocol means anything. Recorded as **Gate 0** in the results.

---

## Section A — the cascade (core feature)

Fields: `uch_country` (radio) → `uch_region` (radio) → `uch_site` (dropdown).

| # | Action | Expect |
|---|---|---|
| A1 | Load the form, nothing picked | `uch_region` shows **all four** options (101,102,201,202); `uch_site` shows all five — no active branch = no filter |
| A2 | `uch_country` = Cameroon (1) | `uch_region` shows **only** 101,102; 201/202 rows hidden |
| A3 | `uch_country` = Nigeria (2) | `uch_region` switches live to **only** 201,202 |
| A4 | Country=1, then `uch_region` = 101 | `uch_site` dropdown lists **only** 1101,1102 (+ blank); 1201/2101/2201 absent from the list, not just greyed |
| A5 | Keep country=1, change region → 102 | `uch_site` switches live to **only** 1201 |
| A6 | Country=1, region=102, pick site 1201; then change region → 101 | site 1201 becomes stale: it **stays in the dropdown but disabled**, the field is flagged red with the message, and the option order for the now-valid set is 1101,1102 with 1201 kept at its original position |
| A7 | From A6, pick site 1102 | flag clears; 1201 is now **removed** from the list; save allowed |
| A8 | Country=1/region=101/site=1102, then change country → 2 | region AND site both go stale together; both flagged; save blocked (hard) |

---

## Section B — hide-list and enforcement modes

Fields: `uch_legacy` (radio) controls `uch_method` (dropdown, hide `9`,
blockSave **confirm**).

| # | Action | Expect |
|---|---|---|
| B1 | Load form, `uch_legacy` unset (≠ Yes) | `uch_method` option 9 hidden (hide-list active while legacy≠1) |
| B2 | `uch_legacy` = Yes (1) | option 9 appears live |
| B3 | legacy=Yes, pick method 9, then legacy = No (0) | 9 stays visible but disabled + flagged with the custom "Legacy culture is retired…" message |
| B4 | From B3, click Save | a **confirm** dialog ("Save anyway?") appears; clicking **Cancel** keeps you on the form (save trapped) |
| B5 | From B3, click Save → **OK** | save proceeds (confirm accepted); no second prompt |
| B6 | Pick method 2 (shown), Save | no dialog; saves cleanly |

---

## Section C — checkbox filtering

Fields: `uch_pilot` (checkbox) controls `uch_reach` (checkbox, show 1,2 →
hide 9, blockSave **hard**).

| # | Action | Expect |
|---|---|---|
| C1 | Load form, pilot unticked | all three `uch_reach` options visible (no active branch) |
| C2 | Tick `uch_pilot` "Pilot active" | option 9 (Loudspeaker van) row hidden; 1 and 2 remain |
| C3 | Untick pilot, tick reach 9, then tick pilot | the CHECKED option 9 stays visible + flagged with the custom message; 1/2 unaffected |
| C4 | From C3, Save | hard block: cannot save while 9 is checked-and-hidden |
| C5 | From C3, untick reach 9 | its row hides again; flag clears; save allowed |
| C6 | Verify only 9 was ever hidden | rows for 1 and 2 were never touched throughout |

---

## Section D — configuration errors (must be visible, must not filter)

| # | Field | Expect |
|---|---|---|
| D1 | `uch_badcode` (`show:["999"]`) | a visible config-error notice under/near the field naming the real codes (1, 2); the field is **not** filtered |
| D2 | `uch_badtype` (text field, `hide:["1"]`) | config error: "@UVCHOICES … radio, dropdown or checkbox" |
| D3 | `uch_mx1` (matrix member) | config error: matrix fields refused, naming the matrix group |
| D4 | On a **survey** rendering of the form | all D1–D3 errors are muted to the generic "a configuration issue has been logged" line — no field names, no codes, no technical text |

---

## Section E — out-of-scope values (never destroy / never mis-flag)

Fields: `uch_mdc`.

| # | Action | Expect |
|---|---|---|
| E1 | Set legacy=Yes so `uch_mdc`'s filter (`show:["1"]`) is active; import a record with `uch_mdc = -99` (a missing-data code) via **Data Import Tool** | the value is NOT flagged as a hidden choice on the form OR in the audit (a value outside the choice list is out of the filter's scope) |
| E2 | Enter a valid shown value (1) then trigger the filter | not flagged |

---

## Section F — survey parity

Enable `uv_choices_test` as a survey (or use the public survey link).

| # | Action | Expect |
|---|---|---|
| F1 | Open the survey, run A2–A5 | filtering works identically to the data-entry form |
| F2 | Create a stale selection (A6-style) on the survey | the stale message is **generic** — no condition text, no field names — and the survey submit is blocked per blockSave |
| F3 | Branch conflict on the survey (if reproducible) | generic muted message; submit never blocked on the conflict |

---

## Section G — off-instrument conditions & SEC-005 (no value leaks)

| # | Action | Expect |
|---|---|---|
| G1 | Add a temporary @UVCHOICES on a `uv_choices_test` field whose `when` references a field on **another** instrument (e.g. `uv_modes_test`), give that off-page field a saved value, load the form | the filter reflects the saved off-page value (server folds it) AND the page source (`inspire-validator-config`) carries only the folded boolean/AST — **never the off-page field's raw value** |
| G2 | grep the rendered page source for any real saved value | only field names, the designer's own literals, codes, and booleans appear — no record value (SEC-005) |

---

## Section H — server audit & Validation scan

| # | Action | Expect |
|---|---|---|
| H1 | Import (or race a save so) a record where `uch_region = 201` while `uch_country = 1` | module log gains `invalid-id-saved` with `type: choices`, `reason: hidden-choice`, scoped to the right instrument/event/instance (value stored per the project's log-privacy mode) |
| H2 | Import a checked-and-hidden checkbox code (`uch_reach` code 9 checked, pilot active) | logged the same way, one entry per hidden checked code |
| H3 | Open the **Validation scan** project page | it lists the H1/H2 violations with `type=choices, reason=hidden-choice`, names record/event/instance/field, and **never shows the stored value**; CSV export matches |
| H4 | The E1 MDC record | does NOT appear in the scan (out of scope) |
| H5 | A record with a currently-VALID selection | not logged, not scanned |

---

## Section I — browser & regression

| # | Action | Expect |
|---|---|---|
| I1 | **Safari** (if available): run A4–A7 | dropdown filtering genuinely shortens the list (options removed, not merely styled) — this is the case CSS `hidden` would fail |
| I2 | Chrome + Firefox: spot-check A2, B3, C3 | identical behavior |
| I3 | Load a form with the module's OTHER modes live (an @UVALIDATE / @UVASSERT / @UVUNIQUE field alongside a @UVCHOICES field) | every prior mode still works; no console errors; the choices field composes cleanly |
| I4 | **pid 134** (second project): add one simple @UVCHOICES rule | filtering works and the two projects' settings do not bleed into each other |

---

## Section J — performance sanity

The v1.4.0 live run flagged a possible per-rule boot() fan-out stall on a
project carrying many rules (see [[validator-universal-expansion]] memory).
Choice filtering adds per-code watchers, so:

| # | Action | Expect |
|---|---|---|
| J1 | Load `uv_choices_test` (11 fields, several multi-branch rules) and time to interactive | no multi-second stall; the field is responsive within ~1s of load |
| J2 | Rapidly toggle `uch_country` back and forth 10× | the region/site lists track each change without lag or leaked listeners; no growing memory |

---

## Sign-off

- [ ] Gate 0 (real markup: parentNode hiding works) — **blocks everything else**
- [ ] Section A — cascade
- [ ] Section B — hide-list + confirm/hard
- [ ] Section C — checkbox
- [ ] Section D — config errors (+ survey muting)
- [ ] Section E — out-of-scope values
- [ ] Section F — survey parity
- [ ] Section G — off-instrument folding + SEC-005
- [ ] Section H — audit + scan
- [ ] Section I — browsers + cross-mode regression + cross-project
- [ ] Section J — performance

Any FAIL is reproduced with the exact steps and the observed DOM/log before we
call v1.5.0 accepted. SEC-005 sentinel value convention: use a token that
appears in **no** note, label, or condition literal (e.g. `ZZCHOICES99`) so a
leak is distinguishable from documentation — see the appendix note in
[`LIVE_TEST_PLAN.md`](LIVE_TEST_PLAN.md).

---

*Companion to `LIVE_TEST_PLAN.md` §9b (the brief in-plan version). This document
is the exhaustive protocol for v1.5.0's one new mode.*
