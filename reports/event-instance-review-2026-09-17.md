# Event and repeating-instance implementation review

Date: 2026-09-17. Branch: `codex/event-instance-references`. Reconciled baseline: `854ac72`.

## Implemented scope

The default-off project dialect is connected to the settings and annotation channels, browser rendering, saved-record auditing, direct scans, and durable scans. It includes named/relative events and instances, checkbox-code references, exact shared-key matching, collections and exact numeric aggregates, typed dates/elapsed time, and within-record repeat uniqueness. Conditions and branch selectors use the same resolver. Current-page operands stay live; authorized off-page operands are snapshots; extended rules are advisory.

Runtime components are `ProjectShape`, `TemporalMetadata`, `AddressResolver`, `ReferenceBudget`, `ExactDecimal`, `TemporalValue`, `TemporalLogic`, `TemporalRules`, and the `TemporalIntegration` trait. Compiled values/ASTs remain transient rather than changing authored legacy rule revisions.

## Review corrections

| Finding | Correction and regression evidence |
|---|---|
| Numeric equality could collapse distinct uniqueness keys | Record uniqueness uses exact trimmed strings; `001` and `1` differ. PHP/JS fixtures and integration tests cover this. |
| Changing a matching key could reuse a stale match | Live key guards invalidate the binding and show unresolved feedback until save/reload. |
| Protected matching keys could influence disclosed snapshots | Source key permissions participate in the same disclosure decision as target values. |
| Converting local uniqueness into a browser assertion could conflict with an existing assertion | The uniqueness factory now handles per-field record-local ASTs; independent modes compose. |
| Multiple uniqueness targets could share one incorrect verdict | Separate per-field saved results and browser ASTs; tests distinguish one duplicated field from a unique field. |
| Unknown branch selectors could activate fallback rules | Shared variant selection stops on unknowns; tests include real dropdown filtering/restoration and no-block behavior. |
| An asynchronous uniqueness reply could revive an inactive branch | Active-variant changes invalidate pending responses and cached answers; a deferred-response test exercises the race. |
| Missing data/unknown metadata could be confused with saved blanks | Explicit resolver states; unknown metadata remains unresolved; completion markers preserve blank repeat rows. |
| Repeated collection work could become too expensive across many hosts | Shared 100,000-unit budget plus per-collection and decimal limits; exhaustion is reported, never certified as complete. |
| Durable scans could continue after semantics changed | The service rechecks the same canonical fingerprint used by the planner, including the extended project shape/dialect. |
| Internal folded conditions made audit explanations meaningless | Findings preserve the authored assertion label while evaluating the compiled result. |
| An extended audit exception could suppress legacy checks | Extended failures produce an incomplete-audit notice and legacy auditing continues independently. |
| Browser reads always covered every event | Browser read planning narrows known event dependencies while retaining all required uniqueness contexts. |

## Local verification

| Verification | Result |
|---|---|
| 34 PHP suites on PHP 7.4 | Passed |
| 34 PHP suites on PHP 8.1 | Passed |
| 34 PHP suites on PHP 8.3 | Passed |
| 34 PHP suites on PHP 8.4 | Passed; existing implicitly-nullable-parameter deprecation notices remain in legacy scan classes |
| 22 Node/JavaScript suites | Passed |
| MySQL 5.7.44, default isolation | 431 checks, zero failures |
| MySQL 8.0.46, default isolation | 431 checks, zero failures |
| MariaDB 10.5.29, default isolation | 431 checks, zero failures |
| MariaDB 10.11.18, default isolation | 431 checks, zero failures |
| Each of those four databases, explicit READ COMMITTED | 433 checks per database, zero failures |
| Four executable safety mutations | All detected by their regression suites |
| PHP syntax, JSON validity, whitespace/conflict checks | Passed |
| Working-tree release-shaped archive | New runtime files present; development-only directories excluded; real Git index unchanged |

Database testing used Docker Desktop through its Windows CLI because the WSL default socket was unavailable. Tests created private disposable schemas and isolated containers/network, with synthetic data only. The task-owned database/PHP containers and anonymous data volumes were removed after verification. PHP 8.3 used an isolated extracted runtime; the other PHP versions used CLI containers.

`tests/temporal_fixture.json` is shared between PHP and JavaScript. `tests/temporal_golden.json` fixes browser payloads, audit entries, read parameters, findings and rule identities with a fixed synthetic HMAC key. The integration mock filters both fields and events so missing read dependencies cannot accidentally pass. Mutation checks deliberately introduce address collisions, unauthorized literals, absent-as-blank behavior, and duplicated self membership in temporary copies; the checkout is not mutated.

## Remaining deployment acceptance

No authorized live REDCap development project/access method was supplied during this run. Therefore no real REDCap pilot or deployment was performed, and no new live-version compatibility claim is made. REDCap 13.7 remains the repository target. Before release, verify actual project metadata shapes (including whole repeating events), native scalar selector behavior, page/survey rights and DAG boundaries, API/import hook coverage, deletion reconciliation, budget behavior, and disable/re-enable rollback in the site's development environment.

No universal deletion hook was verified; scans are the reconciliation path for deletions and write paths that omit save hooks. Cross-record uniqueness branches using extended selectors require authenticated source-read access for their AJAX check; unavailable/survey paths remain advisory and are covered by saved-data checks. The user guide documents these operational boundaries.

Implementation remains in the working tree for review. Existing unrelated edits were preserved. No production data was changed, no release version was invented, and no deployment was performed.
