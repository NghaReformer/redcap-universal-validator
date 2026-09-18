# Changelog

Release notes for Universal Field Validator, a REDCap external module.
Git tags identify release snapshots. Release candidates are intended for development-project verification before production adoption.

## 2.1.0-rc.1 — 2026-09-18

**Release candidate: expanded validation and scan reliability.** This release combines case-insensitive conditions, validation across events and repeating instruments, dropdown/autocomplete choice filtering, and the merged scan-remediation work.

### Release history

The last previously published Git tag is `v1.10.0`. Earlier changelog entries labelled `1.11.0` and `2.0.0` described parallel development milestones; neither had a published Git tag. Those changes are included here. The `2.1.0` release line preserves the repository's established `2.x` development numbering and distinguishes this combined release from the scan-remediation milestone. No retrospective stable releases have been created.

This release includes behavior changes from `v1.10.0`; it is not a patch-only update. Detailed historical notes remain available in the [changelog archive](CHANGELOG-ARCHIVE.md).

### Added

- **Cross-event and repeating-data validation**, enabled through a project setting that is off by default. References support named and relative events, explicit and relative instances, and checkbox values within qualified references.
- **Shared-key matching between independent repeating instruments**, including composite keys. Matching preserves case and leading zeros; missing or ambiguous matches are reported as unresolved.
- **Collection predicates and aggregates**: any/all, existence, row/populated/distinct counts, sum, minimum, maximum, and average. Decimal comparisons remain exact, and average comparisons avoid rounding.
- **Typed date and elapsed-time bindings**, with calendar validation, configured display formats for live inputs, and signed elapsed-time comparisons.
- **Within-record uniqueness** across the target instrument's designated events and repeating instances. Existing project, DAG, and event scopes retain their cross-record behavior.
- Shared reference resolution across all five action-tag rule kinds, conditions, branch selectors, browser feedback, post-save audits, and direct/durable scans.
- Configuration diagnostics, bounded evaluation, incomplete-audit notices, and scan invalidation when relevant rules or project metadata change.

### Changed

- **Text conditions are case-insensitive by default for ASCII A–Z**. This applies to `@UVASSERT`, `when` conditions, and branch selectors. Set `"caseSensitive":true` to retain exact comparisons for an individual rule. Numeric comparisons, non-ASCII case distinctions, and uniqueness matching are unchanged.
- Ordered legacy comparisons with blank operands no longer create a violation solely from string ordering. Assertions treat them as nonviolations; conditions and branch selectors remain inactive. Equality checks against `''` retain their existing meaning.
- Extended browser validation is advisory. Exact current-page operands remain live; authorized off-page values are snapshots. Missing rows, unavailable metadata, inaccessible data, and resource limits produce unresolved results rather than fabricated blank values.
- Dependent post-save audits re-evaluate affected host contexts across events and instances. The default synchronous limit is 500 host contexts; incomplete work is reported and can be reconciled by a scan.

### Fixed

- **`@UVCHOICES` dropdown and autocomplete filtering**: select elements take precedence over hidden mirrors; grouped options are filtered and restored in order; existing disabled states and selected values are preserved.
- Autocomplete suggestions follow the active choice branch, including cached responses. jQuery-triggered changes are observed, stale menus close after branch changes, and invalid state is exposed on the visible input.
- A selected choice that becomes unavailable remains visible and is flagged rather than cleared. Selecting an allowed value releases the choice-filter save block.
- Unresolved branch selectors no longer activate a fallback. Stale uniqueness responses are discarded when the active branch changes.
- Multiple validation modes on the same field retain separate messages and combine their accessibility and presentation state correctly.
- Scan findings, uniqueness data, and generations are scoped consistently to their projects. Rule attribution and persistent identities remain stable when rules are reordered.
- Scan DAG arguments use numeric group identities consistently. Authorization covers every instrument read by a scan, including referenced fields and composite keys.
- Scan migration, lease fencing, worker recovery, retry handling, maintenance scheduling, and database diagnostics have been strengthened. No-progress browser polling uses backoff, and duplicate-group writes are batched.
- Scan regression fixtures now reflect the current schema version, DAG interface, and maintenance wiring.

### Documentation

- Expanded [action-tag examples](docs/action_tag_validation_examples.md) for single and pooled `alternates`, events, repeat selectors, key matching, aggregates, dates, and record-local uniqueness.
- Added a [complete six-region site annotation](docs/region-site-choices-example.txt) for dropdown and autocomplete filtering.
- Added the [event and instance reference guide](docs/EVENT-INSTANCE-REFERENCES.md), including activation, permissions, unresolved results, limits, and rollback.

### Upgrade guidance

1. **Review conditions that depend on letter case before upgrading.** For identifiers where `ab12` and `AB12` differ, add `"caseSensitive":true` to the relevant rule. Branches distinguished only by case can otherwise become conflicting branches.
2. **Review blank-dependent conditions.** Ordered comparisons involving empty answers no longer activate a rule merely because an empty string sorts before a populated value. Use explicit blank checks where required.
3. **Review durable scan storage migration before enabling it.** The included schema-version-2 migration changes finding identities and project/generation attribution. It removes incompatible version-1 findings and retires affected runs; generate replacement findings with a new scan. It does not change REDCap record data. See [installation guidance](docs/INSTALL.md).
4. **Enable extended references deliberately.** Keep the project feature setting off until its rules, event mappings, repeat configuration, and access behavior have been checked in a development project. Disabling it preserves authored rules but marks extended rules unconfigured; it does not reverse the broader scan-storage migration.
5. **Save and reload after changing matching keys or off-page data.** Browser snapshots are not refreshed by a new endpoint. Run scans after deletions and imports or API writes that do not invoke the relevant save hook.

### Verification and known limitations

- Local testing covers PHP 7.4, 8.1, 8.3, and 8.4; all 22 JavaScript suites; and MySQL 5.7/8.0 plus MariaDB 10.5/10.11 integration tests. The cross-event database matrix completed 3,456 checks.
- Dropdown regressions include 96 checks and the six-region alphanumeric site configuration. Chromium verification exercised native dropdowns and real jQuery UI autocomplete, including stale selections, branch changes, and delayed initialization.
- **Live REDCap acceptance remains pending.** Local fixtures do not certify the behavior of a particular installation, its permissions, API/import hooks, or deletion hooks. REDCap 13.7 remains the compatibility target, not a newly verified deployment claim.
- Survey and restricted-user payloads withhold protected source data. Rules requiring unavailable live comparisons defer rather than disclose that data.
- Browser enforcement cannot prevent API/import writes or concurrent edits. Audits detect saved violations; scans provide reconciliation.
- PHP 8.4 reports an existing implicit-nullability deprecation in scan exception code. The tested suites pass, but the notice remains to be resolved.

## 1.10.0 — Multiple ID formats per field

Published as [`v1.10.0`](https://github.com/NghaReformer/redcap-universal-validator/tree/v1.10.0).

- Added `alternates` to accept several ID formats within one single-value or pooled field, each with its own pattern and check-character algorithm.
- Added per-format labels, normalization settings, and pooled candidate lengths.
- Strengthened pooled ambiguity checks, cross-runtime regex validation, and parsing work limits.
- Improved feedback for mixed-format single and pooled values.

See the [archived detailed notes](CHANGELOG-ARCHIVE.md#1100---one-field-several-id-formats) for migration details and implementation history.

## Earlier versions

Release and development notes preceding `v1.10.0` are preserved in [CHANGELOG-ARCHIVE.md](CHANGELOG-ARCHIVE.md). Published tags are listed in the [GitHub tag history](https://github.com/NghaReformer/redcap-universal-validator/tags).
