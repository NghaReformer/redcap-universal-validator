# Event/instance implementation progress

Historical status as of 2026-09-16: foundations only. Superseded by `event-instance-review-2026-09-17.md`; this file preserves the earlier checkpoint.

Baseline merge: `854ac72`. Branch: `codex/event-instance-references`.

## Local verification

53 PHP 8.3/Node suites passed. PHP CLI used isolated downloaded distribution packages with mbstring, ctype and tokenizer enabled. No database or REDCap live acceptance result is claimed.

| Suite | Exit code |
|---|---|
| tests/algorithm_coverage_php.php | 0 |
| tests/annotation_php.php | 0 |
| tests/binding_php.php | 0 |
| tests/branching_php.php | 0 |
| tests/choices_php.php | 0 |
| tests/crossform_adversarial_php.php | 0 |
| tests/crossform_php.php | 0 |
| tests/crossform_resolution_php.php | 0 |
| tests/hook_php.php | 0 |
| tests/hosting_php.php | 0 |
| tests/namespace_lint_php.php | 0 |
| tests/numeric_php.php | 0 |
| tests/parity_php.php | 0 |
| tests/pooled_php.php | 0 |
| tests/qualified_php.php | 0 |
| tests/risky_php.php | 0 |
| tests/scan_capabilities_php.php | 0 |
| tests/scan_cron_php.php | 0 |
| tests/scan_entitlement_php.php | 0 |
| tests/scan_identity_php.php | 0 |
| tests/scan_moduledb_php.php | 0 |
| tests/scan_page_php.php | 0 |
| tests/scan_schema_php.php | 0 |
| tests/scan_security_php.php | 0 |
| tests/scan_service_php.php | 0 |
| tests/scan_sqlstore_fault_php.php | 0 |
| tests/scan_store_php.php | 0 |
| tests/scan_wiring_php.php | 0 |
| tests/scan_worker_php.php | 0 |
| tests/temporal_value_php.php | 0 |
| tests/when_fuzz_php.php | 0 |
| tests/when_php.php | 0 |
| tests/a11y_dom_js.cjs | 0 |
| tests/algorithm_coverage_js.cjs | 0 |
| tests/alternates_dom_js.cjs | 0 |
| tests/branch_dom_js.cjs | 0 |
| tests/choices_dom_js.cjs | 0 |
| tests/config_notice_js.cjs | 0 |
| tests/constraint_dom_js.cjs | 0 |
| tests/deferral_dom_js.cjs | 0 |
| tests/dispatch_notice_js.cjs | 0 |
| tests/explain_js.cjs | 0 |
| tests/numeric_js.cjs | 0 |
| tests/parity_js.cjs | 0 |
| tests/pooled_dom_js.cjs | 0 |
| tests/pooled_js.cjs | 0 |
| tests/qualified_js.cjs | 0 |
| tests/required_dom_js.cjs | 0 |
| tests/risky_js.cjs | 0 |
| tests/scan_client_js.cjs | 0 |
| tests/unique_dom_js.cjs | 0 |
| tests/when_dom_js.cjs | 0 |
| tests/when_js.cjs | 0 |

Follow-up checks after tightening collection syntax and instance overflow handling: qualified PHP 63 checks, qualified JS 118 checks, binding PHP 14 checks; all pass.

See `PLAN-event-instance-references.md` for the remaining integration work. Existing project configuration cannot enable the new grammar yet.
