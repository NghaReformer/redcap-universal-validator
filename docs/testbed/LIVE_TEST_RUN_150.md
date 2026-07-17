# Live acceptance run — v1.5.0 on chpr-redcap.org

**Status: PREPARED, waiting for the deploy signal.** When v1.5.0 is deployed
on the server, this document is the ordered, comprehensive run sheet. It
covers everything that has never been proven live: the v1.5.0 choice-filter
mode, the three 1.4.x fixes (two of them security), the still-open live gates
from the 1.4.0 plan (§5–§9 of `LIVE_TEST_PLAN.md`), the open audit-log
question, and the unquantified performance signal.

Assistant-executable steps are marked **[auto]** (driven through the browser
on data-entry forms, surveys, the scan page, and the module log); steps that
need a human are marked **[manual]** and say exactly why.

---

## Phase 0 — deploy verification (blocks everything else)

- [ ] **[manual] Deploy.** Install v1.5.0 as a new module version directory
      (`universal_validator_v1.5.0`) and enable it on **pid 149**; keep the
      old version directory so a rollback is one click. The server previously
      ran v1.4.0 — this deploy also delivers the 1.4.1–1.4.3 security fixes,
      so it should reach **every project** using the module, not only the
      testbed.
- [ ] **[auto] Version check.** Control Center → External Modules shows
      v1.5.0; the module description mentions FIVE rule kinds incl.
      @UVCHOICES.
- [ ] **[manual] Dictionary merge.** Download pid 149's current dictionary,
      **append** the 11 rows of `uvalidate_150_test_fields.csv` (new form
      `uv_choices_test`), upload the merged file. NEVER upload the testbed
      CSV alone — a dictionary upload replaces everything. (The 1.4.0 rows
      `uvalidate_140_test_fields.csv` should already be present from the
      last run; if not, append those too.)
- [ ] **[auto] Config parses.** Open a `uv_choices_test` data-entry form,
      read `#inspire-validator-config`: all `uch_*` rules present; `uch_region`
      and `uch_site` are branch rules with `choicesAll`; `uch_badcode`,
      `uch_badtype`, `uch_mx1` are configError rules with the expected
      wording.

## Phase 1 — DOM markup proof (the one untested v1.5.0 assumption)

- [ ] **[auto] Radio wrapper.** On `uv_choices_test`, inspect an option of
      `uch_region`: the `<input name="uch_region___radio">` must sit inside a
      wrapper element (REDCap's `div.choicevert` / `span.choicehoriz`) whose
      `display:none` removes the whole visible row — the client hides
      `input.parentNode`. Record the actual markup in the results log.
- [ ] **[auto] Checkbox wrapper.** Same check for
      `__chk__uch_reach_RC_9`.
- [ ] **[auto] Enhanced-choice guard.** Confirm the testbed fields do NOT use
      REDCap's "enhanced radio/checkbox" display or auto-complete dropdowns;
      if any project field does, note how the markup differs (candidate
      1.5.1 work, not a blocker for plain fields).

**If parentNode hiding does not remove the row visually, STOP — fix the
client ren