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
client renderer against the real markup before running anything below. This is
the one v1.5.0 assumption the automated stubs cannot verify.**

## Phase 2 — @UVCHOICES behavioral run

Execute the full [`CHOICES_LIVE_ACCEPTANCE.md`](CHOICES_LIVE_ACCEPTANCE.md)
protocol — Sections A–J. All **[auto]** (I drive the forms/surveys/scan) except
where that document marks a Data Import Tool step, which is **[manual]** (you
run the import; I read the result). In order:

- [ ] **[auto] A — cascade** (country → region → site, live switching, stale-kept)
- [ ] **[auto] B — hide-list + confirm/hard** enforcement modes
- [ ] **[auto] C — checkbox** filtering (checked-hidden stays visible + blocks)
- [ ] **[auto] D — config errors** (badcode/badtype/matrix) + survey muting
- [ ] **[manual→auto] E — out-of-scope MDC** (you import `-99`; I verify not flagged)
- [ ] **[auto] F — survey parity** (generic messages, submit block)
- [ ] **[auto] G — off-instrument folding + SEC-005** (no raw value in page source)
- [ ] **[manual→auto] H — audit + scan** (you import/race a save; I read the log + scan)
- [ ] **[auto] I — browsers + cross-mode regression + pid 134**
- [ ] **[auto] J — performance sanity**

## Phase 3 — close the still-open 1.4.x live gates

These never got proven live because the server stayed on v1.4.0; v1.5.0 carries
the 1.4.1–1.4.3 fixes, so this deploy is the first chance. From
[`LIVE_TEST_PLAN.md`](LIVE_TEST_PLAN.md):

- [ ] **[auto] §5 @UVUNIQUE live transport.** Load a `uv_modes_test` form with a
      unique rule; confirm the injected config now carries `jsmoName` (the
      v1.4.2 fix) and the as-you-type used/free check actually fires against the
      server. This is the headline gate — it was silently inert on v1.4.0.
- [ ] **[auto] §5b anti-oracle / Identifier refusal.** On an Identifier field,
      confirm the survey opt-in is refused (config error) and the no-auth
      endpoint answers boolean-only and rate-limits (the v1.4.3 fix). Probe the
      endpoint with no session and no `survey_hash` — it must NOT leak existence
      on an Identifier or on any rule without the opt-in.
- [ ] **[auto] §6 Validation scan.** Run the scan page; confirm every mode
      (check/constraint/required/unique/**choices**) reports, values are never
      shown, DAG confinement holds, CSV exports.
- [ ] **[auto] §7 SEC-005 sweep.** grep every testbed form's page source for the
      sentinel `ZZTOPSECRET77` (1.4.x) and `ZZCHOICES99` (1.5.0) — neither may
      appear in `inspire-validator-config` or any AJAX response.
- [ ] **[auto] §8 audit log.** Confirm `redcap_save_record` writes the expected
      module-log entries for each mode. **Watch specifically for the open
      "audit log not firing live" question** (see the
      [[validator-audit-log-not-firing-live]] memory — v0.6.0 produced zero
      entries live): if choices/other detections do not appear, capture the
      REDCap version, the save path used, and whether the hook fired at all.
- [ ] **[manual] §8b import/API coverage.** You perform a Data Import Tool load
      and (if used) an API import of a known-bad value; I check whether the
      post-save hook audited it. Coverage is REDCap-version-dependent — this
      documents the actual behavior on your instance rather than assuming it.
- [ ] **[auto] §9 regression.** Re-run a representative 1.0–1.4 case
      (check-character ID, constraint, required, unique) to confirm v1.5.0 broke
      nothing.

## Phase 4 — performance measurement (the unquantified 1.4.0 signal)

The v1.4.0 run saw a multi-second stall on a project injecting ~61 rules /
64 validators with many absent referenced fields (per-rule boot() fan-out of
MutationObserver + setInterval, and the when-registry sweep at engine.js:1281).
Choices mode adds per-code watchers, so measure rather than assume:

- [ ] **[auto] Baseline.** Time-to-interactive on a no-module form vs the
      `uv_choices_test` form; capture the delta and any long-task warnings.
- [ ] **[auto] Stress.** Toggle `uch_country` 10× rapidly; confirm no lag
      growth and no leaked listeners (Phase J of the acceptance doc).
- [ ] If a real stall reproduces, file it with numbers (not a hunch) as a
      1.5.1 candidate — candidate fix already noted: inject only rules whose
      fields are on the rendered instrument.

## Results & sign-off

I keep a running results table as I execute — one row per checkbox above with
observed vs expected and PASS/FAIL — and reproduce every FAIL with exact steps
and the observed DOM/log before v1.5.0 is called accepted. The two hard release
gates are **Phase 1** (real-markup proof) and **Phase 3 §5** (the @UVUNIQUE
transport that was inert on v1.4.0). SEC-005 sentinels: `ZZTOPSECRET77`,
`ZZCHOICES99`.

---

*Master ordered run sheet. The detailed @UVCHOICES case tables live in
[`CHOICES_LIVE_ACCEPTANCE.md`](CHOICES_LIVE_ACCEPTANCE.md); the 1.4.0-era
detail in [`LIVE_TEST_PLAN.md`](LIVE_TEST_PLAN.md).*