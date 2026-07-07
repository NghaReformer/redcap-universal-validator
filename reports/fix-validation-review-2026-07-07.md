# Fix Validation Review - 2026-07-07

Scope: REDCap Universal ID Validator external module at
`/mnt/d/SCRIPTS_PATH/redcap-universal-validator`.

Reviewed state:

- Current branch: `main`
- Current HEAD: `c724660 Add adversarial review report (2026-07-07)`
- Fix commit present in history: `591f0c3 Harden validator: fix adversarial-review findings UV-001..UV-010`
- Docker PHP image used: `php:8.1-cli`
- No repository Dockerfile, compose file, Composer project, npm package, PHPUnit config, or Makefile was present.
- Local `php` is not installed; PHP checks below were run in Docker.
- No REDCap runtime/container was present, so REDCap hook behavior was not exercised end to end.

## Executive Verdict

The main runtime fixes are substantially in place and the module's own JS/PHP parity
tests pass. The previously reported browser XSS and browser regex-ReDoS classes
were directly probed and did not reproduce in the current root checkout.

However, not all issues are fully closed:

1. Server-side regex safety is not aligned with browser-side regex safety. The
   browser rejects risky `idPattern` values before compilation, but the PHP
   server path compiles and runs the same pattern without an equivalent
   risky-pattern/config-error gate.
2. The working tree is dirty from line-ending churn. There is no functional diff
   when ignoring end-of-line whitespace, but `git diff --check` fails with
   thousands of trailing-whitespace warnings.
3. REDCap integration behavior, especially `redcap_save_record()` data shape for
   longitudinal and repeating instruments, could not be proven in this environment
   because no REDCap test harness/container exists in the repo.

## Findings

### P2 - PHP server regex validation does not mirror browser risky-pattern rejection

Files:

- `js/engine.js:543`
- `js/engine.js:565`
- `js/engine.js:901`
- `php/CheckCharacter.php:188`
- `php/CheckCharacter.php:221`
- `php/CheckCharacter.php:250`
- `UniversalValidator.php:95`
- `UniversalValidator.php:97`

The browser has a dedicated `QRID_riskyPattern()` check and applies it to both
single-field and pooled rules before `new RegExp()` is used. The PHP path has no
equivalent check in `matchesPattern()` or `pooledState()`. It compiles the
admin-provided regex into PCRE and treats `preg_match()` failure as a normal
format mismatch.

Reproduction command:

```bash
docker run --rm -i -v "$PWD":/app -w /app php:8.1-cli php <<'PHP'
<?php
require 'php/CheckCharacter.php';
use INSPIRE\UniversalValidator\CheckCharacter;
$patterns = [
  '(a+)+$' => str_repeat('a', 100000) . 'X',
  '(a|aa)+$' => str_repeat('a', 100000) . 'X',
  '([A-Z]+)*$' => str_repeat('A', 100000) . '!',
  '(x+x+)+y' => str_repeat('x', 100000),
];
foreach ($patterns as $pat => $value) {
  $start = microtime(true);
  $res = CheckCharacter::validateSingleField('none', 'normalized_id', '', $pat, $value);
  $elapsed = microtime(true) - $start;
  echo $pat . ' => elapsed_seconds=' . sprintf('%.3f', $elapsed) .
       ' result=' . json_encode($res) . ' preg_last_error=' . preg_last_error() . PHP_EOL;
}
PHP
```

Observed output:

```text
(a+)+$ => elapsed_seconds=0.002 result={"ok":false,"reason":"format"} preg_last_error=0
(a|aa)+$ => elapsed_seconds=0.001 result={"ok":false,"reason":"format"} preg_last_error=0
([A-Z]+)*$ => elapsed_seconds=0.005 result={"ok":false,"reason":"format"} preg_last_error=2
(x+x+)+y => elapsed_seconds=0.001 result={"ok":false,"reason":"format"} preg_last_error=0
```

Fact pattern:

- Browser rejects `(a+)+$` as a configuration error.
- PHP accepts the same class of pattern into the server validation path.
- For `([A-Z]+)*$`, Docker PHP returned `preg_last_error=2`
  (`PREG_BACKTRACK_LIMIT_ERROR`) and the module converted that to
  `{"ok":false,"reason":"format"}`.
- In `redcap_save_record()`, that result is logged as an invalid saved value via
  `logInvalid()`.

Impact:

- A rule that the browser reports as misconfigured can still generate server-side
  invalid-ID audit logs.
- Server-side import/API behavior can diverge from live browser behavior.
- PCRE backtrack-limit errors are indistinguishable from real format failures.

Recommended fix:

- Move the risky-pattern detection into shared PHP config validation as well, or
  add a PHP equivalent to `QRID_riskyPattern()` and set `configError` in
  `getRules()`.
- In PHP, check `preg_last_error()` after `preg_match()` and return a distinct
  config/runtime error instead of `format` for PCRE engine failures.

### P3 - Dirty working tree caused only by line-ending churn, but `git diff --check` fails

Command results:

```text
git status --short
 M .github/workflows/parity.yml
 M CHANGELOG.md
 M README.md
 M UniversalValidator.php
 M config.json
 M docs/INSTALL.md
 M js/README.md
 M js/engine.js
 M php/CheckCharacter.php
 M tests/README.md
 M tests/check_fixture.json
 M tests/gen_pooled_fixture.cjs
 M tests/parity_js.cjs
 M tests/parity_php.php
 M tests/pooled_fixture.json
 M tests/pooled_js.cjs
 M tests/pooled_php.php
```

`git diff --ignore-space-at-eol --stat` produced no output, so the dirty state is
line-ending/whitespace churn rather than a functional delta.

`git diff --check` exited nonzero and emitted 18,874 warning lines in the tool
output, starting with:

```text
.github/workflows/parity.yml:1: trailing whitespace.
+name: parity
```

Impact:

- This does not change module behavior.
- It makes the repository look dirty and can break whitespace checks or confuse
  future reviews.

Recommended fix:

- Normalize line endings and add an explicit `.gitattributes` policy.

## Verified Fixed Items

### UV-001 stored script breakout: fixed in tested path

Code:

- `UniversalValidator.php:145` uses `json_encode()` with
  `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`.
- `UniversalValidator.php:150` embeds config as `type="application/json"`.
- `UniversalValidator.php:152` parses via `JSON.parse()`.

Probe:

```text
{"rules":[{"pattern":"\u003C\/script\u003E\u003Cscript\u003Ealert(1)\u003C\/script\u003E","strip":"\u003C\u003E\u0026\u0022\u0027"}]}
```

`</script>` was encoded and did not remain as a literal closing script tag.

### UV-002 DOM XSS in validation messages: fixed in tested path

Code:

- `js/engine.js:534` defines `QRID_escapeHtml()`.
- `js/engine.js:830` escapes config-error text before `innerHTML`.
- `js/engine.js:1108` escapes pooled chip text before HTML insertion.

Probe output:

```text
single-html-contains-raw-img: false
single-html-contains-escaped-img: true
config-error-contains-raw-img: false
config-error-contains-escaped-img: true
pooled-html-contains-raw-img: false
pooled-html-contains-escaped-img: true
```

### UV-003 server validation coverage: materially improved, tested at engine level

Code:

- `UniversalValidator.php:85` calls pooled validation for pooled rules.
- `UniversalValidator.php:95` calls single-field validation for single rules.
- `php/CheckCharacter.php:221` validates pattern first, then check character.
- `php/CheckCharacter.php:420` validates pooled fields.

Probe output:

```text
single-format={"ok":false,"reason":"format"}
single-valid={"ok":true,"reason":"valid"}
pooled-ok={"ok":true,"reason":"","count":2}
pooled-dup={"ok":false,"reason":"duplicate","count":2}
pooled-junk={"ok":false,"reason":"junk;count","count":1}
```

Limitation: this proves the engine behavior, not a live REDCap save hook, because
the repository does not include a REDCap runtime test harness.

### UV-005 raw identifier logging by default: fixed by code review

Code:

- `UniversalValidator.php:112` reads `log-values`.
- `UniversalValidator.php:113` defaults empty setting to `hashed`.
- `UniversalValidator.php:125` stores raw values only when explicitly set to
  `raw`.
- `UniversalValidator.php:127` otherwise stores `value_sha256`, except `none`
  and `off`.

### UV-006 browser regex ReDoS: fixed in tested browser path, not fully mirrored in PHP

Browser code:

- `js/engine.js:543` defines `QRID_riskyPattern()`.
- `js/engine.js:571` rejects risky single-field regexes.
- `js/engine.js:907` rejects risky pooled regexes.
- `js/engine.js:550` and `js/engine.js:551` define input length caps.

Probe output:

```text
redos-config-error: idPattern looks catastrophically backtracking (nested or adjacent unbounded quantifiers, e.g. (a+)+). Rewrite it without nested quantifiers.
pooled-redos-has-catastrophic-message: true
```

Residual issue: see P2 above for the PHP server path.

### UV-007 server getData scaling and MutationObserver leak: improved by code review

Code:

- `UniversalValidator.php:69` collects all configured fields.
- `UniversalValidator.php:73` performs one `readValues()` call per save.
- `js/engine.js:867` and `js/engine.js:1239` disconnect observers when complete.

Limitation: no REDCap runtime was available to profile actual hook behavior.

### UV-008 pooled config validation and controls: improved by code review

Code:

- `UniversalValidator.php:200` strictly validates `expected-count`.
- `UniversalValidator.php:206` parses and validates `id-lengths`.
- `UniversalValidator.php:217` validates min/max lengths.
- `UniversalValidator.php:225` emits rule-level `configError`.

### UV-009 JS/PHP parity coverage: passed

Commands and outputs:

```text
node tests/parity_js.cjs
compute algorithms covered: damm, iso7064_letters1, iso7064_letters2, iso7064_mod11_10, iso7064_mod11_2, iso7064_mod37_2, iso7064_mod37_36, iso7064_mod97_10, luhn, none, verhoeff
parity_js: 643 rows checked (compute + normalize + scheme_ops), 0 mismatch(es)
```

```text
docker run --rm -v "$PWD":/app -w /app php:8.1-cli php tests/parity_php.php
compute algorithms covered: damm, iso7064_letters1, iso7064_letters2, iso7064_mod11_10, iso7064_mod11_2, iso7064_mod37_2, iso7064_mod37_36, iso7064_mod97_10, luhn, none, verhoeff
parity_php: 643 rows checked (compute + normalize + scheme_ops), 0 mismatch(es)
```

### UV-010 pooled parser parity: passed

Commands and outputs:

```text
node tests/pooled_js.cjs
pooled_js: 8 cases checked, 0 mismatch(es)
```

```text
docker run --rm -v "$PWD":/app -w /app php:8.1-cli php tests/pooled_php.php
pooled_php: 8 cases checked, 0 mismatch(es)
```

## Syntax Checks

Commands:

```bash
node --check js/engine.js
node --check tests/parity_js.cjs
node --check tests/pooled_js.cjs
node --check tests/gen_pooled_fixture.cjs
```

Result: exit code 0, no output.

Command:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.1-cli sh -lc 'php -l UniversalValidator.php && php -l php/CheckCharacter.php && php -l tests/parity_php.php && php -l tests/pooled_php.php'
```

Output:

```text
No syntax errors detected in UniversalValidator.php
No syntax errors detected in php/CheckCharacter.php
No syntax errors detected in tests/parity_php.php
No syntax errors detected in tests/pooled_php.php
```

## Coverage Gaps

- No live REDCap container or test harness was available, so hook invocation,
  `\REDCap::getData()` array shape, project setting serialization, and real
  External Module framework behavior were not executed.
- No browser automation against an actual REDCap form was possible from this repo.
  DOM checks used Node stubs targeted at the vulnerable rendering paths.
- PHP was verified with Docker `php:8.1-cli`; local PHP is not installed.
- Tests cover parity fixtures and pooled parser fixtures, but not a full matrix of
  REDCap project structures: classic, longitudinal, repeating events, repeating
  instruments, surveys, and API/import saves.

## Recommended Next Steps

1. Fix P2 by adding PHP-side risky-pattern/config-error parity and
   `preg_last_error()` handling.
2. Normalize line endings and add `.gitattributes`.
3. Add a REDCap hook harness or containerized integration test that stubs or runs
   the External Module framework and asserts `redcap_save_record()` reads the
   correct event/repeat instance.
4. Add regression tests for risky patterns in both JS and PHP so the browser and
   server paths cannot diverge again.
