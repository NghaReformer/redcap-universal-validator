# Live run sheet: event and repeating-instance references (2.1.0-rc.1) on pid 149

**Status (2026-09-23): test bed BUILT on chpr-redcap.org pid 149; the server
still runs v1.10.0.** Everything below Phase 0 needs 2.1.0-rc.1 deployed.
Under v1.10.0 every extended tag already renders as a configuration error
(checked live), which is the expected fail-closed legacy behaviour.

Files in this folder come from `../gen_tb_event_instance.py`. The offline
twin of this sheet is `tools/temporal_testbed_check.php`: it runs the real
module over the same dictionary, events and seed and diffs the audit against
`expected_violations.csv`. Run it after any engine change before a live run.

Steps marked **[auto]** can be driven through the browser; **[manual]** steps
need a person (server access, a second account, or a judgment call).

---

## What pid 149 now contains

Longitudinal, two arms, repeating instruments and one repeating event.

| Event (unique name) | id | Arm | Instruments | Repeats |
|---|---|---|---|---|
| event_1_arm_1 | 351 | 1 | 6 legacy test forms, xe_enrol, xe_negative | no |
| baseline_arm_1 | 379 | 1 | xe_visit, xr_specimen | xr_specimen |
| visit_1_arm_1 | 380 | 1 | xe_visit, xr_specimen, xr_result | xr_specimen, xr_result |
| visit_2_arm_1 | 381 | 1 | xe_visit, xr_result | xr_result |
| unscheduled_arm_1 | 382 | 1 | xe_unsched | whole event |
| followup_arm_1 | 383 | 1 | xe_visit, xe_close (survey) | no |
| sub_enrol_arm_2 | 384 | 2 | xe_enrol | no |
| sub_visit_arm_2 | 385 | 2 | xe_visit, xe_sub | no |

Designed gaps: xr_specimen is NOT on visit_2 and xe_visit is NOT on
unscheduled, so relative event selectors must skip them.

Seeded records (Data Import, so no save hook ran for them):

- **XE-1**: every rule passes. A scan must report no extended violations.
- **XE-2**: each rule broken once; see `expected_violations.csv`.
- **XE-3**: gaps (specimen instances 1, 3 and a blank instance 4; unscheduled
  instance 2 without 1). Every relative selector across a gap is unresolved.
- **XE-4**: same record in both arms; arm-2 rules read arm-1 Baseline.

Legacy forms (id_validation_test, wb_test, uv_*) moved into event_1_arm_1 when
longitudinal was enabled. Their URLs now need `&event_id=351`.

---

## Phase 0: deploy (blocks everything else)

- [ ] **[manual] Decide the scope.** The module is enabled for ALL projects on
      chpr-redcap.org. Installing 2.1.0-rc.1 upgrades every project, although
      the extended feature itself stays off until a project opts in. Known
      High findings (see the wargame report) are reachable only by projects
      that enable the feature.
- [ ] **[manual] Install** the package as `modules/universal_validator_v2.1.0`
      (keep `universal_validator_v1.10.0` for a one-click rollback), then
      upgrade the module in Control Center.
- [ ] **[auto] Version check**: Control Center shows v2.1.0.
- [ ] **[manual] Enable the feature on pid 149 only**: module settings, tick
      "Enable event and instance references".

## Phase 1: configuration (feature ON)

Open `DataEntry/index.php?pid=149&id=XE-1&event_id=351&page=xe_negative` and
read `JSON.parse(document.getElementById('inspire-validator-config').textContent).rules`.

- [ ] **[auto]** Each `xn_*` field except `xn_xss` shows a configuration error
      whose text matches its field note.
- [ ] **[auto]** Known gaps from the offline run, confirm live:
      `xn_rel_nonrep` and `xn_ambiguous` show NO configuration error; they
      are dropped at run time as "invalid, unresolved" / "ambiguous,
      unresolved" (finding C3).
- [ ] **[auto]** `xn_xss`: type `x`; the message shows the literal text
      `<img src=x ...><b>bold?</b>`, no image request, no bold.
- [ ] **[auto]** `wb_err_event` on wb_test (event 351) was a config error
      before the feature. With the feature on it is now a valid rule. Record
      this: enabling the feature changes a legacy rule's meaning.

## Phase 2: browser behaviour per form

For each row, open the page, type the value, observe the message, and do NOT
save unless the step says so.

### xe_visit, XE-1 (Baseline 379, Visit 1 380, Visit 2 381, Followup 383)

| Field | Event | Type | Expect |
|---|---|---|---|
| xv_date | 379 | 2026-01-01 | "Visit is before consent"; Save NOT blocked (advisory, although tag says hard) |
| xv_weight | 380 | 60 | "Weight dropped since the previous visit" (previous = Baseline 70) |
| xv_weight | 383 | 70 | violation: previous of Followup is Visit 2 (74), NOT Unscheduled |
| xv_weight | 379 | 1 | no verdict: Baseline has no previous event |
| xv_temp | 379 | 40 | "Higher than the next visit" (Visit 1 = 36.6) |
| xv_height | 380 | 100 | "Shorter than at the first visit" |
| xv_close_note | any | blank | required only after Followup xv_status is set to 2 and saved |
| xv_ae_note | any, XE-2 | blank | REQUIRED (XE-2 enrolment AE = Yes) |
| xv_kit_check | 379 | 57241 | Damm error (XE-1 consent ticks Specimens) |
| xv_clinic | 379 | open list | only N1, N2 visible (XE-1 site = North) |
| xv_code_confirm | 379 | px-0001 | passes (case-insensitive) |
| xv_code_exact | 379 | px-0001 | fails (caseSensitive) |
| xv_window | 379 | 2026-06-01 | "Outside the 120-day window" |
| xv_date_dmy | 379 | 09-01-2026 | "Before consent (D-M-Y)"; 11-01-2026 passes |
| xv_dt | 379 | 01-10-2026 08:59 | "Before the consent time"; 09:00 passes |
| xv_dmy_legacy | 380 | (seeded 10-02-2026) | page shows a violation, audit/scan stay silent: finding C1 |
| xv_dose | 379 | 40 | "Total dose exceeds the enrolment limit" (40+5+5+5 > 50) |
| xv_hr | 379 | 71 | "Highest heart rate so far" (others max 70) |
| xv_kit | 380 | K-001 | duplicate of Baseline kit (record scope) |
| xv_tube | 379 | same label, different tube type | passes; same type fails |

### xr_specimen (repeating), XE-1 Baseline

- [ ] Instance 2, `xs_date` = 2026-01-14: "Earlier than the previous specimen".
- [ ] Instance 1, `xs_date`: no verdict (no previous instance).
- [ ] New instance 3, `xs_id` = S-001: duplicate; `xs_type` = 3 then 2: distinct-count trips at the third type.
- [ ] `xs_volume` = 25 on a new instance: total 55 > 50.
- [ ] `xs_hb` = 13 on instance 1: above the mean of the others (12).
- [ ] `xs_pop`: type 2 on Baseline (two thresholds entered); 3 fails.

### xr_result, XE-1 Visit 1 instance 1

- [ ] `xr_spec_id` = s-001 (lower case): "No specimen with this ID" after save and reload (match is exact).
- [ ] `xr_value` = 4: below matched threshold 5.
- [ ] `xr_time` = 2026-01-18 09:00: more than 48 h after first collection.
- [ ] Change `xr_spec_id` without saving: matched checks go quiet with a "save and reload" notice.

### xe_unsched (repeating EVENT), XE-1 instances 1 and 2

- [ ] Instance 2, `xu_date` = 2026-03-01: earlier than instance 1.
- [ ] Instance 3 (new): `xu_reason` required; `xu_code` = U-1 duplicate.

### xe_sub, XE-4 arm 2 (event 385)

- [ ] `xsub_weight` = 79: below arm-1 Baseline 80.
- [ ] `xsub_prev` = 77: below arm-2 enrolment 78 (relative event stays in arm 2).

## Phase 3: post-save audit

Module log filter `invalid-id-saved`. Log mode should be `raw` on pid 149 for
readable values.

- [ ] **[auto]** Save XE-2 event 351 xe_enrol unchanged. Expected: findings for
      every XE-2 context whose rule depends on enrolment values.
- [ ] **[auto] Finding C2 check**: the audit also logs
      `xv_ae_note required-blank` for XE-2 at **followup_arm_1**, where
      xe_visit was never entered (only xe_close is saved there).
- [ ] **[auto]** Save an unrelated legacy form (wb_test): no extended reads,
      no new log rows.
- [ ] **[auto]** Save XE-2 Baseline xr_specimen instance 3 twice: count
      duplicate rows written per save (finding P4 predicts one row per
      dependent context on every save).
- [ ] **[auto]** `uvalidate-unconfigurable` rows: note how many appear for
      designed gaps (first visit, instance 1). Every save writes them.

## Phase 4: scans

- [ ] **[auto]** Direct scan: compare the violation list with
      `expected_violations.csv` (37 rows, 3 of them marked UNRESOLVED).
- [ ] **[auto]** Durable scan: same list; unresolved rules listed apart.
- [ ] **[auto]** Change one annotation while a durable scan runs: the old
      run refuses further work.

## Phase 5: survey leak test

- [ ] **[manual]** Open xe_close as a survey for XE-2 (Followup). View page
      source. It must contain no value from xr_specimen or xe_visit (no
      `"lit"` with a volume, weight, kit or haemoglobin). Record every
      `deferredWhy` string: they must not differ with the protected data
      (finding S1).

## Phase 6: rights (needs a second account)

- [ ] **[manual]** User with no rights to xr_specimen opens xr_result: the
      matched rules defer with "unauthorized". Change `xr_spec_id` between a
      key that exists (S-001), one that does not (S-999) and save/reload
      each: the deferral text must be the same (finding S1).

## Phase 7: scale

- [ ] **[auto]** Import `seed_scale.csv` (record XE-SCALE, 1,500 Baseline
      specimens). Open instance 1500; record page-load time and config size.
- [ ] **[auto]** Save instance 1500; record audit time and whether the saved
      instance itself was audited (finding P4: past 500 host contexts it is
      not).
- [ ] **[manual]** Do NOT enter 4,000-digit numbers on the shared server:
      finding P1 shows a save then runs for minutes.

## Phase 8: rollback

- [ ] **[auto]** Turn the feature OFF. Every extended rule shows
      "Enable event and instance references in project settings first".
- [ ] **[auto] Finding C4 check**: `xv_clinic` now shows ONLY E1 for XE-1
      (North). The unconditional fallback branch became the only rule.
