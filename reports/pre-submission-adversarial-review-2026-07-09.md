# Pre-Submission Adversarial Review - 2026-07-09

Scope: `/mnt/d/SCRIPTS_PATH/redcap-universal-validator`

Reviewed commit:

- `53823db Add bulk configuration channels + reposition as Universal Field Validator`
- Branches `main`, `origin/main`, `origin/HEAD`,
  `claude/wizardly-cannon-0ac4ab`, and
  `origin/claude/wizardly-cannon-0ac4ab` all pointed to this commit during review.

This was a fresh pre-submission adversarial review after the recent changes. It
covered security, REDCap External Module standards, scalability, performance,
accuracy, maintainability, and anti-patterns. It was not a full app-backed Codex
Security scan and not REDCap's Control Center External Module security scanner;
those tools were not available in this local run.

Official REDCap External Module references checked:

- `config.json` reference:
  `https://raw.githubusercontent.com/vanderbilt-redcap/external-module-framework-docs/main/config.md`
- JavaScript guidance:
  `https://raw.githubusercontent.com/vanderbilt-redcap/external-module-framework-docs/main/javascript.md`
- module requirements:
  `https://raw.githubusercontent.com/vanderbilt-redcap/external-module-framework-docs/main/requirements.md`
- framework version table:
  `https://raw.githubusercontent.com/vanderbilt-redcap/external-module-framework-docs/main/versions/README.md`

## Executive Verdict

No new P1/P2 exploitable security vulnerability was proven in the runnable paths.
The prior major conformance findings are addressed in the current code:

- `config.json` now uses `framework-version: 14`, appropriate for REDCap 13.7.x.
- The deprecated pre-framework-12 `permissions` block is gone.
- Nested `branchingLogic` was removed from repeatable `sub_settings`.
- A single public namespace object, `window.INSPIREUniversalValidator`, now exists.
- `log-values=none` now hashes the record ID and omits the invalid field value.
- The new `@UVALIDATE` parser is covered by a PHP test in CI.

However, I found two concrete pre-submission defects and two process/standards
gaps:

1. Fast-entry field names can fail silently when every typed field is invalid.
2. Config errors for `@UVALIDATE` on non-text fields may not render on select-like
   fields, contradicting the manual checklist.
3. `@UVALIDATE` is not declared in `config.json` as an External Module action tag.
4. PHP runtime tests could not be executed locally in this run because neither PHP
   nor Docker was available from the shell.

## Findings

### P3 - Fast-entry rules with only invalid field names are silently dropped

Files:

- `UniversalValidator.php:214`
- `UniversalValidator.php:226`
- `UniversalValidator.php:227`
- `UniversalValidator.php:231`
- `UniversalValidator.php:234`
- `config.json:64`
- `docs/INSTALL.md:35`

The settings UI promises that unknown fast-entry names show a configuration error.
The code collects those errors in `$csvErrors`, but if all fields in the rule are
invalid or unknown, the rule is dropped before the errors are attached:

```php
$bad = array_values(array_diff($fields, $known));
if ($bad) {
    $csvErrors[] = 'field(s) not in this project: ' . implode(', ', $bad) ...
    $fields = array_values(array_intersect($fields, $known));
}
if (!$fields) continue;
```

Attack/failure scenario:

- Admin creates a rule using only `fields-csv`.
- They mistype every field name, for example `studyid specimenid` instead of
  `study_id specimen_id`.
- `$csvErrors` contains the problem, but `$fields` becomes empty.
- The rule is skipped and no client config-error rule is emitted.
- The project appears configured, but no validator is attached to those intended
  fields.

Impact:

- Silent validation coverage loss.
- Misleading setup path for bulk configuration.
- Bad pre-submission behavior for a module whose main new feature is scalable
  bulk field assignment.

Recommended remediation:

- If `$csvErrors` is non-empty and `$fields` is empty, emit a project-level visible
  configuration warning if possible.
- If no project-level display target exists, require at least one picker-selected
  field for fast-entry errors or add a hidden/settings diagnostics panel.
- Add a PHP unit test for: only invalid `fields-csv` names must not disappear
  silently.

### P3 - `@UVALIDATE` non-text-field config errors do not render on select fields

Files:

- `UniversalValidator.php:307`
- `UniversalValidator.php:308`
- `UniversalValidator.php:309`
- `js/engine.js:832`
- `js/engine.js:835`
- `js/engine.js:836`
- `js/engine.js:840`
- `docs/TESTING.md:38`

The server deliberately creates a config-error rule when `@UVALIDATE` appears on a
non-text/non-notes field:

```php
if (!in_array($ftype, ['text', 'notes'], true)) {
    $frag = ['error' => 'this tag only works on Text or Notes fields ...'];
}
```

But the browser field lookup only attaches validators to `input` and `textarea`:

```js
var tag = (els[i].tagName || "").toLowerCase();
if(tag === "input" || tag === "textarea") return els[i];
```

Reproduction with a Node DOM stub:

```text
select_messages 0
select_validator_registered true
```

That means a `<select>` field can have an internally registered config-error rule
but no rendered error message. The manual checklist says "Tag on a radio/calc
field -> only works on Text or Notes fields error"; that is not proven for
select-like REDCap controls with the current lookup.

Impact:

- Misconfigured annotation may not be visible to the data manager.
- The server skips `configError` rules in `redcap_save_record`, so this can become
  silent no-op behavior for affected non-text controls.

Recommended remediation:

- For config-error-only rules, attach the message to any named REDCap field
  container, not only text `input`/`textarea`.
- Alternatively, reject or surface annotation misuse in a project-level diagnostic
  rather than trying to attach to the field widget.
- Add a browser/DOM test for `select`, radio, checkbox, calc/display-only, and
  textarea field shapes.

### P3 - `@UVALIDATE` is not declared as a module action tag

Files:

- `config.json:20`
- `README.md:54`
- `docs/INSTALL.md:43`
- `php/AnnotationRules.php:32`

The module documents `@UVALIDATE` as an Action Tags / field annotation workflow,
but `config.json` has no `action-tags` entry. The official External Module
`config.json` docs describe adding module-provided action tags through an
`action-tags` array so they appear in REDCap's Action Tags popup.

Facts from current config inspection:

```text
action-tags None
```

Impact:

- Users may not discover the tag from REDCap's Action Tags popup.
- Depending on REDCap settings/version behavior, unknown action-tag-like text may
  be confusing or flagged in UI workflows even if field annotations are stored.
- It is an avoidable REDCap Repo review concern because the module presents this
  as a first-class configuration channel.

Recommended remediation:

- Add an `action-tags` declaration for `@UVALIDATE` with a concise description and
  examples.
- Keep the parser tolerant of manually typed tags, but make the tag officially
  visible in REDCap.

### P2 - Local PHP verification is blocked in this run

Files:

- `.github/workflows/parity.yml:27`
- `.github/workflows/parity.yml:35`
- `.github/workflows/parity.yml:39`
- `.github/workflows/parity.yml:41`
- `.github/workflows/parity.yml:43`
- `.github/workflows/parity.yml:45`

This is not a code vulnerability, but it is a pre-submission gate. The module is
PHP-heavy, and the local environment could not execute PHP tests:

```text
php -v
/bin/bash: line 1: php: command not found
```

Docker was also unavailable:

```text
docker run ...
The command 'docker' could not be found in this WSL 2 distro.
```

The Windows Docker bridge existed but the daemon pipe was missing:

```text
failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine
```

Non-interactive install was not possible:

```text
sudo -n true
sudo: a password is required
```

The GitHub Actions workflow does include the right PHP checks:

- `php -l php/CheckCharacter.php`
- `php -l php/AnnotationRules.php`
- `php -l UniversalValidator.php`
- `php tests/parity_php.php`
- `php tests/pooled_php.php`
- `php tests/risky_php.php`
- `php tests/annotation_php.php`

Recommended remediation:

- Do not submit to REDCap Repo until these PHP checks have passed in CI or a local
  PHP 8.1+mbstring environment.
- Save the CI run URL or output with the submission package.

## Verified Positive Items

### REDCap framework conformance improved

Current config facts:

```text
framework-version 14
permissions_present False
redcap-version-min 13.7.0
duplicate_keys []
```

This aligns with the official framework version table for REDCap 13.7.x and the
official instruction that framework 12+ modules must remove hook `permissions`.

### Settings UI anti-pattern from prior review is addressed

`rg branchingLogic config.json` found no active nested `branchingLogic`. The
pooled-only settings are still shown, but labels explicitly say "Pooled only".

### Browser XSS probes passed

Node DOM-stub probe:

```text
single_raw_img false
single_escaped_img true
config_raw_img false
config_escaped_img true
namespace_exists true
```

The tested message paths did not render raw `<img>` payloads.

### Browser risky-pattern probes passed

Node probe:

```text
risky_nested true
risky_bounded true
safe_simple false
```

Automated JS test:

```text
node tests/risky_js.cjs
risky_js: 22 patterns checked, 0 mismatch(es)
```

### JS engine parity and pooled parser tests passed

```text
node tests/parity_js.cjs
compute algorithms covered: damm, iso7064_letters1, iso7064_letters2, iso7064_mod11_10, iso7064_mod11_2, iso7064_mod37_2, iso7064_mod37_36, iso7064_mod97_10, luhn, none, verhoeff
parity_js: 643 rows checked (compute + normalize + scheme_ops), 0 mismatch(es)
```

```text
node tests/pooled_js.cjs
pooled_js: 8 cases checked, 0 mismatch(es)
```

### JS syntax, JSON, whitespace, and generated fixture checks passed

Commands:

```text
node --check js/engine.js
node --check tests/parity_js.cjs
node --check tests/pooled_js.cjs
node --check tests/risky_js.cjs
node --check tests/gen_pooled_fixture.cjs
```

Result: exit code 0, no output.

```text
python3 -m json.tool config.json
python3 -m json.tool tests/check_fixture.json
python3 -m json.tool tests/pooled_fixture.json
python3 -m json.tool tests/risky_patterns.json
json-ok
```

```text
git diff --check
diff_check_exit:0
```

```text
node tests/gen_pooled_fixture.cjs && git diff --exit-code -- tests/pooled_fixture.json
pooled_fixture_exit:0
```

### Working tree was clean before this report

Before this report file was added:

```text
git status --short
```

produced no output.

## Residual Coverage Gaps

- No live REDCap instance was available.
- REDCap's Control Center External Module security scanner was not run here.
- No browser automation was run against a real REDCap data-entry form or survey.
- PHP tests were not run locally due missing PHP/Docker, although CI is configured
  to run them.
- REDCap data dictionary and hook behavior were reviewed statically, not exercised
  inside REDCap.

## Pre-Submission Checklist

Before submitting to REDCap Repo:

1. Fix the silent all-invalid `fields-csv` path.
2. Make non-text `@UVALIDATE` misuse visibly report somewhere reliable.
3. Add `@UVALIDATE` to `config.json` `action-tags`.
4. Run the GitHub Actions parity workflow and attach/save the successful run.
5. Run the manual REDCap checklist in `docs/TESTING.md`, including the built-in
   REDCap External Module security scanner.
