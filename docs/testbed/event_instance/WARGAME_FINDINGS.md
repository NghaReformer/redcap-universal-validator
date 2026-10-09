# Wargame findings: event and repeating-instance references (2.1.0-rc.1)

Date: 2026-09-23. Branch `codex/event-instance-references` at `b3411b6`.
Baseline: all 59 CI suites green before the wargame.

Method: three adversarial agents (security, semantics/parity, scale +
audit/scan) worked offline against the real module with framework mocks, plus
`tools/temporal_testbed_check.php`, which runs the module over the pid 149
test bed. Every finding below has a repro script under `tools/` that was run
and reproduced a second time before this report. Live confirmation on pid 149
waits for the 2.1.0-rc.1 deploy (see `LIVE_TEST_PLAN.md`).

Status, 2026-10-09: P1 and P2 are fixed on `main` (CHANGELOG, Unreleased); their
regressions are part 3 of `tests/temporal_adversarial_php.php`. The other findings
are open.

No Critical findings. No SQL, XSS or eval path. PHP and JS evaluators agreed
on 20,000 fuzzed compiled conditions.

## Summary

| ID | Severity | Area | One line |
|---|---|---|---|
| P1 | High | performance | 4,000-digit numbers make one save or scan run for minutes; the budget never charges for it |
| P2 | High | performance | A duplicated `events` list costs seconds per host context without spending budget |
| S1 | Medium | security | Existence and match count of rows the user cannot read leak through empty collections and deferral reasons |
| C1 | Medium | correctness | Untyped comparisons on D-M-Y / M-D-Y date fields give a different verdict on the page than in the audit and scan |
| C2 | Medium | correctness | `count`, `exists` and host contexts use the event row, not the instrument's completion marker |
| C4 | Medium | correctness | A branch with a configuration error (including feature OFF, the documented rollback) makes the fallback branch unconditional |
| P3 | Medium | performance | `[x][any-instance]` / `[x][all-instances]`, `excludeCurrent` and self-key `match` are quadratic, contrary to the docs |
| P4 | Medium | audit | Past 500 host contexts the audit skips the entry actually saved; every save re-logs every violation in the record |
| C5 | Low-Med | correctness | One unresolved operand defers a condition that and/or has already decided |
| P5 | Low-Med | scan | Direct-scan unresolved notes do not say which record was not checked |
| P6 | Low-Med | performance | Page payload budget counts raw bytes; escaping inflates to 13 MB; big decimals cost ~300 ms per keystroke |
| S2 | Low | security | A field named `constructor` disables live uniqueness and its hard block |
| C3 | Low | config | Relative instance on a non-repeating target and an ambiguous repeat reference pass configuration, then never validate |
| C6 | Low | correctness | Whitespace-only saved values count as populated; numeric aggregates become unresolved |
| C7 | Low | config | `elapsedFrom` field of the wrong date type passes configuration, then never resolves |
| C8 | Low | operations | Every save logs `uvalidate-unconfigurable` for designed gaps (first visit, instance 1) |
| P7 | Low | correctness | `minimum`/`maximum` compute an exact sum first, so large values come back unresolved |

## High

### P1. Big decimal values are uncharged and quadratic
`php/TemporalLogic.php:94,101` and `php/ExactDecimal.php:52-62` multiply one
digit at a time (4-7 ms per member at 4,000 digits). A cached collection hit
costs `1+n/32` units regardless of value size (`php/AddressResolver.php:263,296`);
size is charged once, on first read (`:355`). `excludeCurrent` disables the
cache (`:100-103`), so each host context re-sums.

Measured, one record, identical 4,000-digit values, `[a][all-instances]<={avg}`:
n=10 0.17 s, n=20 0.54 s, n=40 2.8 s, n=160 166 s audit / 260 s scan.
Budget never ran out (`unresolved=0`). Any data-entry user can type such
values into an unvalidated number-like text field.
Repro: `php tools/temporal_perf_bigdecimal.php 4000 10,20,40 SAME`.

**Fixed 2026-10-09.** `ExactDecimal` multiplies and adds in machine-integer
limbs, and `ExactDecimal::sumCost()` / `multiplyCost()` charge one budget unit
per 64 bytes of each operand, each time the arithmetic runs: in
`AddressResolver::bindingResult()` before a sum, and in `TemporalLogic::compare()`
before scaling a rational, through `AddressResolver::charge()`. After the fix the
same repro gives n=10 0.02 s, n=40 0.22 s, n=160 0.19 s; past the budget the
remaining contexts report `limit`.

### P2. Duplicate `events` entries
`TemporalRules::validate` only caps the list at 10,000 entries and allows
duplicates (`php/AddressResolver.php:140`). Entries pointing at events without
a row are never charged (`:174-176`); each relative entry walks every event
(`php/ProjectShape.php:49-58`). A 64 KB annotation (2,900
`previous-event-name` entries, 200 events) made one save's audit take 70 s at
50 instances and 167 s at 200. Designer-triggered.
Repro: `php tools/temporal_perf_eventlist.php 200 2900 50`.

**Fixed 2026-10-09.** `TemporalRules::validate()` refuses an event listed twice,
`ProjectShape::eventId()` memoizes its answers, and an event with no saved row
costs one budget unit. The repro's annotation is now a configuration error (5 ms).
The largest list still accepted, every event once plus the relative selectors, is
`tools/temporal_perf_eventlist_distinct.php`: 32 ms at 50 instances, 0.33 s at 500,
where the budget stops the last two contexts.

## Medium

### S1. Protected-row oracle
The rights check runs inside the per-member callback
(`php/TemporalRules.php:170`); an empty aggregate never calls it (`:185`), so a
live-vs-protected comparison ships to the page when the protected rows are
empty and is deferred as "unauthorized" when they are not. Resolution problems
are recorded before rights (`:168`, reason text `:216`,
`php/TemporalIntegration.php:236`): the reason is `absent` for 0 matches,
`unauthorized` for 1, `ambiguous` for 2+. The reason is shown on data-entry
pages (`js/engine.js:2827-2835`) and present in survey JSON.
Impact: a user without rights to the source instrument learns whether a key
exists there, and how often, one save/reload per guess.
Related Low: with an empty collection the survey payload carries the saved
current-instrument match key as a guard literal.
Repro: `php tools/temporal_sec_denial_oracle.php`, `php tools/temporal_sec_survey_payload.php`.

### C1. Display-format dates, untyped
Live values of `date_dmy`/`date_mdy` fields are read in display order; off-page
snapshots are saved `Y-M-D`. Untyped comparisons mix them:
- the documented example `[visit_date]>=[previous-event-name][visit_date]`
  shows a false violation on the page while the audit is silent;
- `@UVUNIQUE=record` on a D-M-Y field misses the duplicate on the page (scan finds 2);
- a match keyed on a D-M-Y field never matches live, always "save and reload".
Advisory only, but the page contradicts the audit. The examples guide uses the
failing form without a warning. Live case: `xv_dmy_legacy` on pid 149.
Repro: `php tools/temporal_sem_display_format.php`.

### C2. Row existence taken from the event row
`php/AddressResolver.php:175-176, 304-315, 336` never read `<form>_complete`,
although the module fetches it for this purpose. Effects: `exists` says 1 and
`count` says 2 for a form never entered; in a repeating event each form counts
once per event instance; `[fu_arm_1][lab_date]` is blank or unresolved
depending on an unrelated form.
Also reproduced on the pid 149 test bed: the audit logs `xv_ae_note
required-blank` for XE-2 at `followup_arm_1`, where xe_visit was never
entered (only xe_close is saved there). In a study this flags every
not-yet-started visit form in any event that has other data.
Repro: `php tools/temporal_sem_row_existence.php`;
`php tools/temporal_testbed_check.php --trace="XE-2|followup_arm_1|1|xv_ae_note"`.

### C4. Fallback branch promoted by a configuration error
`getRules()` sets `configError` on extended rules before `Branching::resolve`
(`UniversalValidator.php:1569-1581`), and `Branching` ignores configError
rules when grouping (`php/Branching.php:220`). The unconditional sibling then
becomes the only rule for the field. Triggers: feature OFF (the documented
rollback), a renamed or deleted event, any extended metadata error.
Seen on pid 149 under the deployed v1.10.0 and reproduced on 2.1.0-rc.1 with
the feature off: `xv_clinic` shows only `E1` for every site. For a
`@UVALIDATE`/`@UVASSERT` fallback with `blockSave:"hard"` this can block
correct saves. The docs say deferred selectors never activate an else branch;
configuration errors do.
Repro: `php tools/temporal_testbed_check.php --off --pages`.

### P3. Collections still quadratic
Every host context rebuilds the n-member set (`php/TemporalRules.php:193`)
and compares against every member (`php/TemporalLogic.php:77`). `all`,
no early exit: 0.68 s at n=500, 4.0 s at 1,500, 15.3 s at 3,000 (audit). With
`any-instance` the budget runs out near instance 991 of 1,500, so the record is
unresolved. `excludeCurrent` 2.5 s at n=1,000; self-key `match` 2.2 s.
The docs claim cost grows with the number of entries, not its square.
Cached aggregates and record uniqueness are linear (5,000 in <0.5 s).
Repro: `php tools/temporal_perf_allinst.php 500,1500,3000`, `php tools/temporal_perf_curve.php 100,1000,1500,5000`.

### P4. Audit order and log flood
`php/TemporalIntegration.php:261-270` re-checks every host context in record
order on every relevant save. Saving instance 600 of 600 logged 500
violations, never checked instance 600, and the incomplete-audit notice named
instance 501. Two saves of instance 3 wrote 1,000 `invalid-id-saved` rows
(non-extended rule: 2).
Repro: `php tools/temporal_perf_audit.php 600`.

## Low and Low-Medium

- **C5** `php/TemporalRules.php:167,179,216`: any unresolved member defers the
  rule, although `true or unknown` is true. `@UVREQUIRED when "[flag]='1' or
  [result][previous-instance]='1'"` never fires on instance 1. The notice
  says the study team must fix the rule, which is wrong for this case.
  Repro: `php tools/temporal_sem_kleene.php`.
- **P5** `UniversalValidator.php:4039,4045`: direct-scan unresolved notes are
  keyed by rule and in-record position, no record id; 3 exhausted records gave
  2 notes, `incomplete=[]`. Durable scan is correct.
  Repro: `php tools/temporal_perf_scan.php`.
- **P6** `AddressResolver.php:355` charges raw bytes, `UniversalValidator.php:924`
  escapes `<` to 6 bytes: 13.18 MB payload for 4 x 9,000 members.
  `js/engine.js:1234-1263` costs 236-302 ms per keystroke with big decimals.
  Repro: `php tools/temporal_perf_payload.php <dir>` then `node tools/temporal_perf_engine.cjs <dir>`.
- **S2** `js/engine.js:3548,3565`: `cfg.uniqueRecordAsts || {}` read with a
  plain property lookup; field `constructor` resolves to
  `Object.prototype.constructor`, the gate is `1=1`, no AJAX, no block.
  Fix: `Object.create(null)` or `hasOwnProperty`.
  Repro: `node tools/temporal_sec_constructor_field.cjs`.
- **C3** `[xe_code][previous-instance]` on a non-repeating page and
  `[baseline_arm_1][xs_id]` without a selector raise no configuration error
  (numeric `[2]` on a non-repeating target does). They defer at run time and
  log `uvalidate-unconfigurable` on every save. pid 149 fields
  `xn_rel_nonrep`, `xn_ambiguous`.
- **C6** `AddressResolver.php:219`, `TemporalLogic.php:56`, `engine.js:1305`:
  `"  "` is blank to comparisons but populated to `populated-count`, and makes
  `sum`/`average`/`minimum`/`maximum` unresolved. Repro: `php tools/temporal_sem_whitespace.php`.
- **C7** `TemporalRules.php:129-134` checks the binding field's type, not the
  `elapsedFrom` field's. Repro: `php tools/temporal_sem_elapsed_type.php`.
- **C8** Designed gaps (Baseline has no previous visit, instance 1 has no
  previous instance) write a `uvalidate-unconfigurable` row on every save:
  replaying the seed saves once produced 40 distinct such entries, 2 of them
  from the deliberately broken `xn_*` fields.
- **P7** `AddressResolver.php:225`, `engine.js:1306`: min/max run the exact
  sum first; three 4,096-digit values give `limit`.

## Suspicions, not reproduced

- `unique-check` extended branch path (`UniversalValidator.php:4942-4951`)
  reads the caller-supplied record with no DAG check; the answer can differ by
  branch. Needs a DAG-restricted account on a live server.
- An audit that exceeds `max_execution_time` (P1-P3) dies after the data is
  saved, so no incomplete-audit notice is written.
- A durable-scan batch holding one 16-260 s record can exceed the request
  time limit on every retry (`WorkBudget` checks only between records).
- REDCap returning repeating instance 1 in the event base row would be read
  as missing; the audit also merges the base row into entry values.
- Page variants without a `deferred` property (single, pooled, choices,
  unique; `engine.js:2206,2246`) may go silently inactive when the active
  branch is deferred.
- A survey page can reveal a protected value through several branches or
  `@UVCHOICES` conditions evaluated on the server (`{v}='1'`, `{v}='2'`...).
  Documented; worth stating plainly in the docs.

## Held up

- Event selectors: previous/next/first/last skip undesignated events, stay in
  the arm, named events cross arms; repeating events treat every form as
  repeating; previous/next instance use current-1/+1 across gaps.
- Budget exhaustion always gives unresolved, never a partial aggregate or a
  false verdict; budget resets per record in both scan paths.
- Surveys carry no protected literal for scalar, match, aggregate,
  any/all-instance, `excludeCurrent` or record uniqueness.
- `unique-check` refuses no-auth, survey-only and unentitled callers for
  extended branches; `scope:record` is never answered by AJAX.
- Feature OFF: extended shapes become configuration errors, no reads, no logs.
- Injection: aliases, fields, events, arms, instances and match operands are
  regex-checked in PHP and JS; JSON uses `JSON_HEX_*`; messages are escaped.
- Deployed v1.10.0 on pid 149 renders every extended tag as a configuration
  error (checked live 2026-09-23).
