# Master implementation plan: cross-event and repeating-data validation

Status: implementation and local integration completed; final local verification recorded below. Real-REDCap pilot and release acceptance remain pending. Updated 2026-09-17. Supersedes the original plan preserved in `docs/event-instance-reference-plan-original.md`.

## 1. Decisions and corrections

Deliver explicit event/instance references, relative selectors, shared-key matching, collection predicates and numeric aggregates, within-record repeat uniqueness, and typed date/elapsed-time operations. Cover all five rule kinds, their conditions and branches, browser feedback, post-save audit, and both scan paths. Use existing tag/settings channels; no visual builder. Cross-context browser validation remains advisory.

Corrections to the earlier proposals:

| Topic | Decision |
|---|---|
| Previous/next instance | Current instance number minus/plus one; never skip gaps silently. |
| Relative events | Nearest designated event for the referenced instrument in the current arm. |
| Missing repeat | Unresolved, not an invented saved blank. |
| Missing metadata | Extended references remain unresolved; do not inherit legacy fail-open behavior. |
| Self references | Exact current-page context stays live. |
| Collections | Keep current-context members live instead of freezing the entire collection. |
| Audit dependencies | Track possible dependencies, including newly created/deleted rows and changed keys. |
| Compatibility | Preserve valid legacy behavior and identities; separately document confirmed safety fixes. |
| Integration | Finish the existing merge; never start a second merge blindly. |
| Release | Determine version and test deployment from the reconciled baseline. |

Reference semantics: https://washu.atlassian.net/wiki/spaces/RDCPPub/pages/765067492/REDCap%2BSmart%2BVariables . Unresolved results are module policy, not a claim of identical REDCap behavior.

## 2. Baseline, compatibility, activation

1. Identify the active merge and preserve staged/unstaged changes. Complete intended scan-remediation integration, retaining condition and scan fixes. Do not overwrite another task's active changes.
2. Run reconciled CI and database integration suites; record baseline commit; create a `codex/` feature branch. Do not capture goldens from a conflicted baseline.
3. Retain PHP 7.4 and REDCap 13.7 targets. Exercise PHP 7.4/8.1/8.3/8.4 and Node matrix.
4. Add deterministic golden fixtures for payloads, audit entries, read parameters, scan findings and rule identities. Normalize only genuinely variable timestamps/identifiers.
5. Test feature off and feature on with legacy-only rules. Preserve legacy ASTs, order, errors and revisions, except separately documented defect corrections.
6. Introduce a per-project setting disabled by default. Disabled extended syntax gives actionable configuration errors. Activation preflight reports newly recognized rules, branch conflicts, invalid references and unavailable capabilities.
7. Disabling retains configuration text and marks extended rules inactive/unconfigured. Never reinterpret them as legacy rules. Invalidate compilation caches and active scans on semantic configuration changes.

## 3. Language/configuration contract

### Scalar references

Support `[baseline_arm_1][consent_date]`, `[weight][2]`, `[weight][previous-instance]`, `[followup_arm_1][weight][last-instance]`, `[previous-event-name][weight]`, and checkbox-qualified variants.

- One to three adjacent bracket groups; checkbox code only on the field group.
- Positive numeric instances only; numeric selectors on non-repeating targets are invalid.
- Unselected cross-bucket repeat references remain ambiguous.
- Current/previous/next require a repeating source context; first/last use existing target index min/max.
- Relative events stay in the current arm; explicit unique event names may address another arm in the same record.
- Reject unsupported smart variables and arbitrary functions clearly. Preserve legacy limits and grammar by default.

### Extended bindings

Add `references` to JSON tags and settings, addressed by `{alias}` operands. Bindings are declarative: target field, event or event collection, instance selector/collection, matching keys and optional aggregate/typed operation. Infer source instrument from metadata. No SQL or executable code.

```json
{
  "assert": "[result_date]>={collection_date}",
  "references": {
    "collection_date": {
      "field": "specimen_date",
      "event": "collection_arm_1",
      "match": {"specimen_id": "[specimen_id]"}
    }
  }
}
```

Matching uses exact string equality, preserving case and leading zeros. Composite keys are conjunctive. Blank keys are unresolved; zero matches are absent; multiple matches are ambiguous. Explicit existence/count operations permit intentional absence tests. Plain comparisons remain legacy comparisons; use typed bindings for date semantics.

### Collections

Support `any-instance`/`all-instances` operands and structured bindings. At most one collection operand per comparison. Operations: row count, populated count, distinct count, sum, minimum, maximum, average, any, all. Include current instance by default, with explicit exclusion available. Events are explicit lists or all designated events of an explicitly selected arm.

Empty counts return zero; other empty operations are unresolved. Numeric operations ignore saved blanks and reject populated nonnumeric values. Use exact decimal arithmetic and rational averages for comparison. Propagate unknown results through Boolean expressions without substituting blanks. Enforce read/evaluation limits without treating truncated collections as complete.

### Dates and elapsed time

Typed bindings, not implicit changes to legacy comparison. Parse saved values against dictionary metadata/canonical storage; normalize browser values using configured display format. Support date/date and datetime/datetime comparisons. Mixed precision requires explicit conversion. Signed elapsed days/hours/minutes/seconds; calendar days for dates and timezone-less wall-clock arithmetic for datetimes. Reject invalid/impossible dates; no browser timezone conversion or arbitrary function interpreter.

## 4. Architecture and integrations

### Compiler/resolver

Separate project shape, address resolution and compiled dependencies. Preserve plain nodes; introduce qualified/binding nodes. Key by complete address/selector identity. Keep compilation metadata outside legacy rule arrays. Use supported metadata adapters for event names, arm membership/order, designation and repeat configuration. Unknown stays unknown; cache successful results with invalidation. Never infer authoritative arm/order from name suffixes. Verify actual metadata representations including `WHOLE` on supported REDCap versions.

Return state, value/collection, location and value-free explanation. States distinguish resolved (including saved blanks), absent, invalid, ambiguous, unreadable, unauthorized and resource limit. Build instance indexes from returned buckets plus completion markers, not populated reference values. Union the unsaved instance only into its exact page bucket.

### Browser

Partition extended rules while preserving order. Batch required source reads. Rewrite exact self refs to live nodes. Apply disclosure permissions before folding off-page values. Collections combine permitted snapshot literals with live self operands. Never ship unresolved server nodes. All six factories, including pooled validation, honor deferral and snapshot advisory status. Unknown selectors never activate else branches. Cross-context rules remain advisory even after Boolean folding. Surveys receive no protected values/collections; existing constant-fold policy remains. Changed current-page matching keys invalidate the binding until save/reload; no new refresh endpoint required.

### Audit

Index possible dependencies on forms/events/selectors/keys/membership. Unrelated saves do no extra reads. Relevant saves reevaluate host contexts across events, including the same instrument elsewhere. Membership/key changes require recomputation, not old-address filtering. Use supported deletion hooks; scans reconcile paths without hooks. Default synchronous extended audit cap: 500 host contexts. Explicit incomplete notice on truncation/missing context. Never modify saved data.

### Scans/uniqueness

Both scans use the same resolver/evaluator. Planning/fingerprints include source forms, matching keys, date metadata, event/repeat shape and dialect version. Integrate bounded reads/retries with scan budgets; partial collections cannot yield complete findings. Keep existing cross-record uniqueness. Implement within-record uniqueness record-locally across full context identities, excluding only the exact entry. Do not misuse the distinct-record duplicate finalizer. Reuse finding storage; any necessary migration is additive. Respect existing rights/export limits when identifying sources.

## 5. Sequence and acceptance gates

| Stage | Deliverable | Gate |
|---|---|---|
| 0 | Reconciled baseline | No unmerged entries; CI/database matrix passes |
| 1 | Goldens and confirmed deferral fixes | Only documented differences; all factories handle unknown branches |
| 2 | Extended parser/project shape/resolver | Shared PHP/JS selector fixtures agree |
| 3 | Scalar references across all paths | Render/audit/scan parity on identical data |
| 4 | Matching and collections | Keys, membership and live self values work |
| 5 | Typed dates and repeat uniqueness | Calendar/elapsed and exact-address duplicate tests pass |
| 6 | Configuration/diagnostics/docs | All channels compile equivalently |
| 7 | Real-REDCap pilot/release | Permissions, compatibility, budgets and rollback verified |

Required cases: classic/longitudinal/multi-arm/repeating-form/repeating-event; selective designation; gaps, absent/all-blank/new instances; first/last after membership changes; same field at multiple addresses; zero/one/multiple matches and changed composite keys; empty/mixed-validity sets and negation; exact large decimals and budgets; leap years/display formats/midnight/signed elapsed; within-record versus cross-record duplicates; restricted users/surveys/DAG/log/CSV privacy; audit truncation/worker restart/invalidation/read failures; actual API/import hooks. Mutation tests must detect address collisions, unauthorized literals, unknown-as-blank and double-counted self.

Probe authorized development projects outside public module pages. Compare native scalar selectors with REDCap and module extensions with shared fixtures. Update README, user guide, examples, config help, testing docs and changelog. Advertise only verified capabilities/versions. Feature-off is operational rollback; retain stored rules.

## Execution record (2026-09-17)

- Baseline merge `854ac72`; feature branch `codex/event-instance-references`. Existing unrelated working-tree changes were preserved.
- Added the default-off project setting, both configuration channels, activation checks, qualified/binding grammar, metadata adapter, complete-address resolution, declaration/dependency discovery, and transient compilation outside stored rule identities.
- Integrated relative/named events and instances, exact shared-key matching, collections with live current members, exact decimal/rational comparisons, typed dates and elapsed time, and per-field within-record uniqueness.
- Connected all five modes and their branches to disclosure-aware browser preparation, dependent saved-data audits, direct scans, and durable scans. Record-local uniqueness remains a separate uniqueness validator in the browser and bypasses the distinct-record finalizer on the server.
- Added narrow event reads for browser contexts, completion-marker reads, a 500-context default audit cap, a shared 100,000-unit evaluation budget, 10,000-member collection limits, and 4,096-digit arithmetic limits. Unresolved/limited work is explicitly reported.
- Durable continuation checks the rule/ownership/metadata/dialect fingerprint. Feature changes cannot silently resume an old run. Disabling retains authored configuration and marks extended rules unconfigured.
- Added deterministic integration goldens covering browser payloads, audit entries, read parameters, scan findings and identities; shared PHP/JavaScript expression fixtures; DOM and integration regressions; four executable safety mutation tests; and CI/package checks.
- Updated README, configuration help, changelog, test guidance and `docs/EVENT-INSTANCE-REFERENCES.md`.

### Review corrections incorporated

- Changed matching keys invalidate the saved match; hidden source keys also participate in disclosure checks.
- Repeat uniqueness uses exact string identity, keeps leading zeros, excludes only the exact current context, handles multiple target fields independently, and composes with assertions.
- Unknown selectors never choose fallback branches. Inactive assertions do not force saved-data validation, and dynamic unresolved browser expressions show an advisory status.
- Uniqueness responses/caches are invalidated when the active branch changes.
- Metadata is loaded for the explicit project; unexpected repeat representations remain unknown. Missing event/instance data is not fabricated as a saved blank.
- Audit failures do not suppress independent legacy audits. Findings preserve the authored assertion in their explanation rather than exposing the internal folded Boolean expression.

### Verification and release boundary

See `reports/event-instance-review-2026-09-17.md` for the final local matrix, mutation results and limitations. The earlier September 16 report is historical and has been superseded.

Stage 7 remains a deployment acceptance gate: an authorized REDCap development project is needed to verify actual metadata representations, scalar selectors against REDCap, browser permissions/surveys/DAGs, API/import hook coverage, deletion reconciliation, and rollback. No real-project pilot, production deployment, or new live REDCap version certification is claimed. There is no verified universal deletion hook; scans reconcile deletions and write paths that omit save hooks. Local implementation does not authorize removing this release gate.
