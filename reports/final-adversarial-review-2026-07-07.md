# Final Adversarial Review - 2026-07-07

Scope: `/mnt/d/SCRIPTS_PATH/redcap-universal-validator`

Reviewed commit:

- `abd1465 Address fix-validation review: server risky-pattern parity (P2) + LF policy (P3)`
- Branches `main`, `origin/main`, `claude/wizardly-cannon-0ac4ab`, and
  `origin/claude/wizardly-cannon-0ac4ab` all point to this commit.

This was a final adversarial pass over security, REDCap External Module standards,
scalability, performance, accuracy, and maintainability. It was not a live REDCap
Control Center security scan because this repository does not include a REDCap
runtime/container.

External standards checked:

- Official REDCap External Module Framework docs:
  `https://github.com/vanderbilt-redcap/external-module-framework-docs`
- `config.json` reference:
  `https://raw.githubusercontent.com/vanderbilt-redcap/external-module-framework-docs/main/config.md`
- module requirements:
  `https://raw.githubusercontent.com/vanderbilt-redcap/external-module-framework-docs/main/requirements.md`
- JavaScript guidance:
  `https://raw.githubusercontent.com/vanderbilt-redcap/external-module-framework-docs/main/javascript.md`
- framework version table:
  `https://raw.githubusercontent.com/vanderbilt-redcap/external-module-framework-docs/main/versions/README.md`

## Executive Verdict

No new P1/P2 exploitable security bug was found in the tested code paths. The
previous PHP risky-pattern gap is fixed in the current commit, and the regression
suite now includes dedicated JS/PHP risky-pattern tests.

The module is materially stronger than the prior reviewed state, but I would not
call it strictly REDCap-standard-clean yet because of three pending conformance
and maintainability issues:

1. It relies on `branchingLogic` inside repeatable `sub_settings`, which the
   official External Module docs call out as having known issues.
2. The browser code still exposes several top-level globals instead of using one
   module namespace object, contrary to REDCap JavaScript guidance.
3. Vendoring/provenance documentation is stale and understates the local
   deviations from upstream.

There is also one privacy-hardening decision to make: even with `log-values=none`,
the module logs raw REDCap record IDs. That may be acceptable operationally, but
it is not a complete no-identifier logging mode if record IDs are participant
identifiers at a site.

## Findings

### P3 - Pooled-only settings rely on `branchingLogic` inside repeatable sub-settings

Files:

- `config.json:43`
- `config.json:46`
- `config.json:103`
- `config.json:109`
- `config.json:115`
- `config.json:121`
- `config.json:127`

`rules` is a repeatable `sub_settings` group. The pooled-only settings
`keep-chars`, `id-lengths`, `id-min-len`, `id-max-len`, and `expected-count` are
hidden/shown with `branchingLogic` based on `rule-type`.

The official REDCap `config.json` docs support `branchingLogic`, but explicitly
warn that there are known issues with sub-settings and branching logic, and
suggest using `redcap_module_configuration_settings` for more complex conditional
configuration.

Why this matters:

- These settings control pooled-field accuracy and parser safety.
- If REDCap's settings UI mishandles this branching in repeatable sub-settings,
  admins may not see the controls they need or may see controls in contexts where
  they do not apply.
- This is a standards/conformance risk, not a direct exploit in the validator
  engine.

Recommended remediation:

- Either remove `branchingLogic` from nested repeatable settings and make labels
  self-explanatory, or use `redcap_module_configuration_settings()` to generate a
  REDCap-version-safe configuration surface.
- Add a REDCap UI/manual test case for creating one single rule and one pooled rule
  and verifying the settings shown for each.

### P3 - JavaScript still pollutes the global namespace

Files:

- `js/engine.js:23`
- `js/engine.js:526`
- `js/engine.js:563`
- `js/engine.js:789`
- `js/engine.js:867`
- `js/engine.js:898`
- `js/engine.js:1112`
- `js/engine.js:1184`

The module exposes or uses multiple top-level/global browser names:

- `QRID_COMBINED_CONFIG`
- `QRCheck`
- `QRIDSingleInit`
- `QRIDPooledInit`
- `QRIDValidators`
- `QRIDMulti`
- `__QRIDGuard`
- `INSPIRE_VALIDATOR_CONFIG`

The official REDCap JavaScript guidance says modules should minimize globals and
prefer an IIFE or a single global scope object to reduce conflicts between modules
and REDCap core.

Why this matters:

- REDCap pages can have multiple External Modules enabled.
- Generic global names can collide with this module's own future versions, the
  upstream JavaScript Injector version, or unrelated modules.
- A collision can break validation, attach the wrong guard, or make debugging
  difficult.

Recommended remediation:

- Keep one public namespace such as `window.INSPIREUniversalValidator`.
- Move config, engine, factories, validators, test hooks, and guard state under
  that object.
- Keep backward-compatible aliases only if needed, and mark them deprecated.

### P3 - `log-values=none` still logs raw record IDs

Files:

- `UniversalValidator.php:110`
- `UniversalValidator.php:115`
- `UniversalValidator.php:116`
- `UniversalValidator.php:125`
- `UniversalValidator.php:127`

The value logging mode controls the invalid field value, but the log entry always
includes:

```php
'record' => (string) $record
```

Facts:

- `raw` logs the invalid value.
- `hashed` logs `value_sha256`.
- `none` omits the invalid value.
- `off` disables logging.
- All modes except `off` still log the raw record ID.

Why this matters:

- In many REDCap projects, the record ID is itself a participant identifier or can
  be linked to one.
- The current setting name accurately says "log invalid IDs", but the surrounding
  privacy comments and README language can be read as "no raw identifiers in logs"
  by default. That is not fully true if record IDs are identifying at the site.

Recommended remediation:

- Either document clearly that record IDs are always logged unless logging is
  `off`, or add a separate setting for record identity handling:
  `raw-record`, `hashed-record`, or `omit-record`.
- Consider defaulting to a record hash in environments with strict PHI log
  retention policies.

### P3 - Module framework version is older than the declared REDCap floor

Files:

- `config.json:13`
- `config.json:15`
- `config.json:20`

The module declares:

```json
"framework-version": 9,
"compatibility": { "redcap-version-min": "13.7.0" },
"permissions": [...]
```

The official version table says REDCap 13.7.0 supports External Module Framework
version 14. The official requirements docs recommend setting new modules to the
latest framework version supported by the minimum REDCap version. The `config.json`
reference also says hook `permissions` were required before framework 12, but from
framework 12 forward hooks work automatically and `permissions` must be removed.

This is not a runtime bug today because framework 9 explains why `permissions` are
present. It is still not the strict modern standard for a module whose REDCap
floor is 13.7.0.

Recommended remediation:

- Move to `"framework-version": 14` if 13.7.0 remains the minimum REDCap version.
- Remove the `permissions` section when doing so.
- Re-run module enable/configuration checks on a REDCap 13.7.x instance.

### P4 - Vendoring/provenance comments and README are stale

Files:

- `js/engine.js:3`
- `js/engine.js:5`
- `README.md:68`
- `README.md:86`
- `tests/README.md:63`

The top of `js/engine.js` says the only upstream change is the config source and
that every algorithm/factory/dispatch line below is identical. That is now false:
the UI/factory/dispatcher layer includes additional escaping, ReDoS gates, length
caps, observer cleanup, and testing hooks. `js/README.md` correctly documents
these deviations, but `engine.js` and `README.md` still understate them. The README
also says CI runs "all four" tests, while CI now runs parity, pooled, and risky
tests across both runtimes.

Why this matters:

- Future re-vendoring can accidentally drop security hardening if maintainers
  trust the stale header.
- Reviewers get conflicting provenance claims from different docs.

Recommended remediation:

- Update `js/engine.js` header to match `js/README.md`: engine core is the
  byte-identical part, while the UI/dispatcher layer has intentional module
  deviations.
- Update README/test docs to mention the risky-pattern tests and current CI matrix.

## Verified Resolved / No New Finding

### PHP risky-pattern parity is fixed

Files:

- `UniversalValidator.php:200`
- `UniversalValidator.php:204`
- `php/CheckCharacter.php:189`
- `php/CheckCharacter.php:211`
- `php/CheckCharacter.php:246`
- `php/CheckCharacter.php:293`
- `php/CheckCharacter.php:456`
- `tests/risky_php.php:37`
- `tests/risky_php.php:54`

Evidence:

```text
node tests/risky_js.cjs
risky_js: 22 patterns checked, 0 mismatch(es)
```

```text
docker run --rm -v "$PWD":/app -w /app php:8.1-cli php tests/risky_php.php
risky_php: 28 checks, 0 mismatch(es)
```

Targeted PHP probe:

```text
(a+)+$ {"ok":true,"reason":"valid"} elapsed=0.000 risky=yes
([A-Z]+)*$ {"ok":true,"reason":"valid"} elapsed=0.000 risky=yes
(A|A?)+$ {"ok":true,"reason":"valid"} elapsed=0.001 risky=no
```

The server no longer converts PCRE backtracking failures into invalid-ID logs.

### Stored script breakout remains fixed

Probe output:

```text
{"rules":[{"pattern":"\u003C\/script\u003E\u003Cscript\u003Ealert(1)\u003C\/script\u003E","strip":"\u003C\u003E\u0026\u0022\u0027"}]}
```

`</script>` is hex/slash escaped in the embedded JSON path.

### DOM message escaping remains fixed

Probe output:

```text
single_raw_img false
single_escaped_img true
config_raw_img false
config_escaped_img true
```

### `readValues()` lookup logic works for expected shapes

Reflection probe with a stub `REDCap::getData()` returned:

```text
classic-single={"out":{"id":"ABC"},"params":{"project_id":123,"return_format":"array","records":["R1"],"fields":["id"]}}
longitudinal-event={"out":{"id":"EV456"},"params":{"project_id":123,"return_format":"array","records":["R1"],"fields":["id"],"events":[456]}}
repeating-instrument={"out":{"tube":"A2"},"params":{"project_id":123,"return_format":"array","records":["R1"],"fields":["tube"],"events":[456]}}
repeating-event={"out":{"id":"E2"},"params":{"project_id":123,"return_format":"array","records":["R1"],"fields":["id"],"events":[456]}}
```

This proves the local array traversal logic for the expected shapes. It does not
replace a live REDCap integration test.

## Verification Commands

All commands below were run from `/mnt/d/SCRIPTS_PATH/redcap-universal-validator`.

```text
node tests/parity_js.cjs
compute algorithms covered: damm, iso7064_letters1, iso7064_letters2, iso7064_mod11_10, iso7064_mod11_2, iso7064_mod37_2, iso7064_mod37_36, iso7064_mod97_10, luhn, none, verhoeff
parity_js: 643 rows checked (compute + normalize + scheme_ops), 0 mismatch(es)
```

```text
node tests/pooled_js.cjs
pooled_js: 8 cases checked, 0 mismatch(es)
```

```text
node tests/risky_js.cjs
risky_js: 22 patterns checked, 0 mismatch(es)
```

```text
docker run --rm -v "$PWD":/app -w /app php:8.1-cli php tests/parity_php.php
compute algorithms covered: damm, iso7064_letters1, iso7064_letters2, iso7064_mod11_10, iso7064_mod11_2, iso7064_mod37_2, iso7064_mod37_36, iso7064_mod97_10, luhn, none, verhoeff
parity_php: 643 rows checked (compute + normalize + scheme_ops), 0 mismatch(es)
```

```text
docker run --rm -v "$PWD":/app -w /app php:8.1-cli php tests/pooled_php.php
pooled_php: 8 cases checked, 0 mismatch(es)
```

```text
docker run --rm -v "$PWD":/app -w /app php:8.1-cli php tests/risky_php.php
risky_php: 28 checks, 0 mismatch(es)
```

```text
node --check js/engine.js
node --check tests/parity_js.cjs
node --check tests/pooled_js.cjs
node --check tests/risky_js.cjs
node --check tests/gen_pooled_fixture.cjs
```

Result: exit code 0, no output.

```text
docker run --rm -v "$PWD":/app -w /app php:8.1-cli sh -lc 'php -l UniversalValidator.php && php -l php/CheckCharacter.php && php -l tests/parity_php.php && php -l tests/pooled_php.php && php -l tests/risky_php.php'
No syntax errors detected in UniversalValidator.php
No syntax errors detected in php/CheckCharacter.php
No syntax errors detected in tests/parity_php.php
No syntax errors detected in tests/pooled_php.php
No syntax errors detected in tests/risky_php.php
```

```text
python3 -m json.tool config.json >/dev/null
python3 -m json.tool tests/check_fixture.json >/dev/null
python3 -m json.tool tests/pooled_fixture.json >/dev/null
python3 -m json.tool tests/risky_patterns.json >/dev/null
json-ok
```

```text
git diff --check
```

Result: exit code 0, no output.

```text
node tests/gen_pooled_fixture.cjs && git diff --exit-code tests/pooled_fixture.json
```

Output showed the fixture regenerated with 8 cases. The worktree file content
matched the index blob exactly:

```text
git hash-object --path=tests/pooled_fixture.json tests/pooled_fixture.json
01ef05f1c5bcbd0c6db2bc974eb21c805b1b3d0d
git ls-files -s tests/pooled_fixture.json
100644 01ef05f1c5bcbd0c6db2bc974eb21c805b1b3d0d 0 tests/pooled_fixture.json
```

`git status` still marks `tests/pooled_fixture.json` modified on this mount, but
`git diff --exit-code --textconv -- tests/pooled_fixture.json` returned exit code
0 and the index/worktree SHA-256 values matched.

## Residual Coverage Gaps

- No live REDCap instance was available. I could not run REDCap's built-in External
  Module security scanner from Control Center.
- No browser automation was run against a real REDCap form. DOM probes used a Node
  document/window stub targeted at the vulnerable rendering paths.
- `redcap_save_record()` hook invocation was tested through helper-level stubs, not
  through the actual REDCap hook dispatcher.
- The repo does not include PHPUnit/Cypress/REDCap integration tests. Official
  REDCap docs mention unit testing and Cypress support for External Modules; this
  module currently relies on Node/PHP fixture tests plus manual REDCap testing.

## Recommended Closure List

Before treating this as strict REDCap-standard-clean:

1. Replace nested `branchingLogic` in repeatable `sub_settings`, or move the
   conditional config UI to `redcap_module_configuration_settings()`.
2. Namespace all browser globals under a single module object.
3. Decide whether record IDs should be hashed/omitted under privacy modes.
4. Upgrade to the framework version supported by the REDCap floor, then remove
   deprecated hook `permissions`.
5. Fix stale provenance and CI wording in `js/engine.js`, `README.md`, and
   `tests/README.md`.
6. Add one REDCap integration harness or documented manual test checklist covering:
   data-entry form, survey, classic project, longitudinal event, repeating
   instrument, repeating event, JavaScript disabled/API import, duplicate rule
   config, and risky regex config.
