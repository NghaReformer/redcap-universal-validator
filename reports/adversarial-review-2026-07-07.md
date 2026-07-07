# Adversarial Review: REDCap Universal ID Validator

Date: 2026-07-07  
Repository: `/mnt/d/SCRIPTS_PATH/redcap-universal-validator`  
Reviewed commit: `d99c113`  
Scan mode: parent-agent repository review with security-scan phase artifacts.

## Scope

Reviewed tracked runtime/config files: `UniversalValidator.php`, `config.json`, `js/engine.js`, and `php/CheckCharacter.php`.

Reviewed supporting files: `README.md`, `docs/INSTALL.md`, `js/README.md`, `tests/*`, `.github/workflows/parity.yml`, `CHANGELOG.md`, and `.gitignore`.

Excluded from runtime scope: untracked hidden local worktree under `.claude/worktrees/...`, treated as local tooling state.

Validation limits:

- No REDCap runtime was available, so REDCap hook behavior was validated by static trace.
- Local PHP CLI is not installed, so `tests/parity_php.php` could not be run here.
- JS parity and targeted Node probes were run locally.
- Delegated subagents were not used because the available subagent tool contract requires explicit user authorization for spawning agents.

## Executive Summary

This module has a solid check-character algorithm core and useful client-side ID validation behavior, but it is not yet strong enough to be a standard REDCap-wide validation external module. The highest-priority problems are:

1. Stored XSS paths from project settings into REDCap form/survey pages.
2. "Compulsory" validation is browser-only; server-side coverage is after-save and incomplete.
3. REDCap event/repeating-instance handling is not precise enough for reliable server audit.
4. The configuration and tests are ID-focused, not universal validation-grade.

No critical server-side RCE, SQL injection, SSRF, arbitrary file access, unsafe deserialization, hardcoded secrets, or direct REDCap auth bypass was found.

## Findings Summary

| ID | Severity | Area | Finding |
| --- | --- | --- | --- |
| UV-003 | High | Data integrity | Compulsory validation is bypassable outside the browser and server coverage is incomplete. |
| UV-008 | High | Product coverage | Settings schema is not broad enough for a universal REDCap validation module. |
| UV-001 | Medium | Security / XSS | Project settings can break out of the injected inline config script. |
| UV-002 | Medium | Security / XSS | Configured regex text can be rendered as executable HTML in validation messages. |
| UV-004 | Medium | Accuracy | Server audit can read the wrong event or repeat instance. |
| UV-005 | Medium | Privacy | Invalid ID logging stores raw participant/specimen identifiers in module logs. |
| UV-006 | Medium | Availability | Arbitrary project regexes can freeze browser validation. |
| UV-007 | Medium | Scalability | Validation work scales by configured fields instead of saved/current fields. |
| UV-009 | Medium | Accuracy | PHP and JavaScript normalization can disagree for Unicode digits and multibyte input. |
| UV-010 | Medium | Coverage | Tests prove algorithm compute parity but not live REDCap validation behavior. |

## Detailed Findings

### UV-003: Compulsory Validation Is Bypassable Outside The Browser

Affected lines: `UniversalValidator.php:54-71`, `js/engine.js:742-783`, `js/engine.js:1121-1159`

The module calls rules "compulsory", but the only blocking control is browser-side. `redcap_save_record` is explicitly after-save and the implementation only audits single check-character rules. It skips pooled rules, skips `algorithm === 'none'`, and never evaluates `idPattern` on the server.

Impact: API imports, data imports, crafted POSTs, JavaScript-disabled clients, and browser tampering can persist invalid values. This is the biggest blocker to calling the module a strong REDCap data-quality control.

Recommended fix:

- Implement server parity for every rule type: single, pooled, check+format, and regex-only.
- If REDCap cannot reject in this hook, implement rollback/restore or a formal data-quality issue workflow and avoid representing browser blocking as server enforcement.
- Add integration tests for API/import/crafted-save paths.

### UV-008: Settings Schema Is Not Universal Validation-Grade

Affected lines: `config.json:31-100`, `UniversalValidator.php:33-36`, `UniversalValidator.php:130-132`, `js/engine.js:905-936`

The exposed settings are useful for ID validation, but not for validating "any type of input" in REDCap. The engine has latent pooled controls such as `idLengths`, `idMinLen`, `idMaxLen`, and `keepChars`, but these are not exposed in `config.json`. There are also no first-class validators for numeric ranges, dates, times, enumerations, cross-field dependencies, required/conditional logic, async uniqueness checks, or REDCap-native validation parity.

Impact: the module will be perceived as universal but will fail common REDCap validation needs and mis-handle pooled IDs outside default length assumptions.

Recommended fix:

- Either narrow product naming to "Universal ID Validator" or build a typed validator registry.
- Expose exact ID length controls and safe pooled parsing controls.
- Add validator types with both client and server implementations.
- Replace `intval()` coercion for `expected-count` with strict positive-integer validation.

### UV-001: Inline Config Script Allows Stored XSS Breakout

Affected lines: `UniversalValidator.php:91-93`, source setting at `config.json:77-79`

`injectClient()` writes the config directly into an executable script block:

```php
echo '<script>window.INSPIRE_VALIDATOR_CONFIG = '
    . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . ';</script>' . "\n";
```

Because `JSON_UNESCAPED_SLASHES` preserves `</script>`, a free-text setting can terminate the script block and inject a new script. A local probe showed the generated HTML contains a literal `</script><script>...` sequence.

Impact: stored XSS in REDCap data-entry or survey pages. The settings-write precondition lowers severity, but project/module admins should not automatically be able to execute arbitrary JavaScript in other users' or public survey participants' browsers.

Recommended fix:

- Use `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`.
- Do not use `JSON_UNESCAPED_SLASHES` inside script tags.
- Prefer `<script type="application/json" id="...">` plus `textContent` parsing, or an external config endpoint with safe JSON response headers.

### UV-002: Configured Regex Text Reaches `innerHTML`

Affected lines: `js/engine.js:635`, `js/engine.js:715-718`, `js/engine.js:805`, `js/engine.js:816`, `js/engine.js:1181`

The single-field formatter builds human-readable guidance from `idPattern`. For char classes, the class body becomes `w.expected`, which is concatenated into `r.html` and assigned to `msg.innerHTML`.

Validated proof: running `QRIDSingleInit()` with `idPattern: '[<img src=x onerror=alert(1)>]+'` produced verdict HTML containing a literal `<img>` tag.

Impact: stored DOM XSS after the config is loaded. This remains exploitable even if the inline script breakout is fixed.

Recommended fix:

- Stop building validation messages as HTML strings.
- Use text nodes plus fixed markup elements.
- If `innerHTML` remains, escape all config-derived fragments, not only typed field values.

### UV-004: Server Audit Can Select The Wrong REDCap Value

Affected lines: `UniversalValidator.php:60`, `UniversalValidator.php:69`, `UniversalValidator.php:141-173`

The hook receives `$repeat_instance`, but `readValue()` ignores it. It filters by event only and `digInto()` returns the first nested value containing the field. The code comment already notes complex projects may need tighter selection.

Impact: longitudinal/repeating projects can see false negatives, false positives, or repeated stale logs.

Recommended fix:

- Include exact event, instrument, and repeat-instance selectors in the data read.
- Fetch all configured fields once and index by record/event/instrument/repeat instance.
- Add REDCap integration fixtures for longitudinal and repeating instruments.

### UV-005: Raw IDs Are Written To Module Logs

Affected lines: `UniversalValidator.php:72-78`

The module logs raw invalid values:

```php
'value' => $value,
```

Impact: participant/specimen identifiers move into external-module logs, which may have different access, retention, and review workflows than normal REDCap project data.

Recommended fix:

- Log record/event/field/rule/status without raw values by default.
- If correlation is needed, log a truncated or HMAC-hashed value.
- Make raw-value logging an explicit opt-in with a privacy warning.

### UV-006: Arbitrary Regex Can Cause Browser DoS

Affected lines: `config.json:77-79`, `js/engine.js:553`, `js/engine.js:704`, `js/engine.js:875`, `js/engine.js:972`, `js/engine.js:1046`

The project regex is compiled and synchronously tested on input events. Pooled parsing can call the regex repeatedly while segmenting text. A local Node timing probe with `(a+)+$` showed exponential growth, reaching roughly 140 ms at 24 `a` characters plus a failing suffix.

Impact: a bad or malicious project setting can freeze data-entry and survey browsers.

Recommended fix:

- Restrict regex to a safe subset or run a safe-regex check at configuration time.
- Add field input length caps.
- Prefer structured validators for common formats.
- Add tests for catastrophic regex cases.

### UV-007: Server And Client Work Scale Poorly

Affected lines: `UniversalValidator.php:43-50`, `UniversalValidator.php:62-69`, `UniversalValidator.php:150`, `js/engine.js:833-847`, `js/engine.js:1192-1206`

The module injects the full config on every data-entry/survey page. Server-side save audit loops every configured field on every save and calls `REDCap::getData()` once per field.

Impact: large REDCap projects will accumulate avoidable browser sweeps, observers, server data reads, and duplicate logs.

Recommended fix:

- Filter rules by current instrument/form before injection.
- Batch server reads for all configured fields in one `REDCap::getData()` call.
- Use one client dispatcher/observer rather than per-rule timers/observers.

### UV-009: PHP/JS Unicode Normalization May Diverge

Affected lines: `js/engine.js:451-459`, `php/CheckCharacter.php:69-90`

The JS deliberately uses Unicode-aware digit extraction with `\p{Nd}` and comments that non-ASCII digits should be kept and then rejected by ASCII algorithms. PHP uses byte-oriented `strtoupper`, `str_split`, `strlen/substr`, and default `preg_replace('/\D/')` / `preg_match('/\d/')` semantics.

Impact: client verdict and server audit can disagree for Unicode digits or multibyte pasted/scanned input.

Recommended fix:

- Add parity tests for normalization, source extraction, and full-ID validation, not just compute.
- Use explicit Unicode PCRE flags/UCP or reject non-ASCII before source extraction consistently.

### UV-010: Tests Cover Compute Parity Only

Affected lines: `tests/parity_js.cjs:41-55`, `tests/parity_php.php:24-45`, `.github/workflows/parity.yml:15-26`

The fixture contains `compute`, `normalize`, and `scheme_ops`, but both parity tests iterate only `fx.compute`. There are no tests for DOM rendering, save blocking, pooled parser edge cases, REDCap hook selection, regex safety, config escaping, or packaging.

Local verification:

- `node tests/parity_js.cjs` passed: 420 rows, 0 mismatches.
- `php tests/parity_php.php` could not run locally: `php` command not found.

Recommended fix:

- Extend parity tests to normalize/source/full validation.
- Add browser/DOM unit tests for escaping and save blocking.
- Add pooled parser regression tests.
- Add REDCap hook integration tests or mocks for event/repeat-instance selection.
- Add packaging checks to ensure hidden worktrees/local artifacts are excluded.

## Reviewed Surfaces

| Surface | Risk area | Outcome | Notes |
| --- | --- | --- | --- |
| `UniversalValidator.php` injection | Stored XSS | Reported | Inline config script is not script-tag safe. |
| `js/engine.js` message rendering | DOM XSS | Reported | Config-derived regex text can reach `innerHTML`. |
| Browser save blocking | Data integrity | Reported | Useful UX control, not server enforcement. |
| `redcap_save_record` audit | Accuracy / coverage | Reported | After-save, partial rule coverage, imprecise repeat selection. |
| Module logging | Privacy | Reported | Raw invalid IDs logged. |
| Regex validation | Availability | Reported | Valid catastrophic regexes accepted. |
| Pooled parsing | Accuracy / scalability | Reported | Good algorithmic intent, but key length controls are not exposed. |
| Check-character algorithms | Accuracy | No algorithm mismatch found in JS | JS compute parity passed locally. PHP parity could not be run locally. |
| Command/RCE/SSRF/filesystem/SQL/deserialization | High-impact server sinks | Not applicable | No such sinks found in reviewed runtime code. |

## Recommended Remediation Order

1. Fix XSS sinks first: safe JSON embedding and no config-derived `innerHTML`.
2. Decide the enforcement model: either server-enforce every rule or clearly position hard blocking as client UX only.
3. Make server audit exact for event/repeat/instrument and batched for scalability.
4. Add server/client parity for pooled, regex-only, check+format, normalization, and Unicode behavior.
5. Redesign the settings model if the target is truly universal REDCap validation.
6. Expand tests beyond compute parity.

## Scan Artifacts

Detailed phase artifacts were written under:

`/tmp/codex-security-scans/redcap-universal-validator/d99c113_20260707T050514Z/`

Key files:

- `artifacts/01_context/threat_model.md`
- `artifacts/02_discovery/finding_discovery_report.md`
- `artifacts/03_coverage/repository_coverage_ledger.md`
- `artifacts/05_findings/validation_summary.md`
- `artifacts/05_findings/attack_path_analysis_report.md`
