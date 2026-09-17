# Plan: event and instance references in rule conditions

Status: approved plan, not implemented. Written 2026-09-16 against `fix/audit-2026-09-03-confirmed-set` at `e588d47`.

## Context

Rule conditions (the `when` gate of all five tags, branch selectors, and the `@UVASSERT` test) can only read fields in the current event. A field on a different repeating instrument is refused as `ambiguous`, and one in another event as `missing`. The lexer rejects `[event][field]` outright (`php/Logic.php:431-434`, `js/engine.js:1259-1261`). Longitudinal studies need rules such as "AE onset on or after baseline consent", "weight vs the previous visit" and "required if any lab instance is positive".

The hard constraint: every rule that works today must keep producing the same page payloads, audit log entries, scan results, getData calls and rule identities.

**Decisions made**
- The unmerged scan-remediation branch `claude/validation-scan-report-944c61` (29 commits) is merged first.
- The feature is a per-project setting, off by default.
- Scope: named events plus instance selectors, relative events, and any/all over instances.

**Defaults this plan adopts** (flagged in the CHANGELOG and docs):
- Surveys behave like plain cross-form refs: values are never baked in, but server-folded constants are allowed.
- An instance-less reference to another repeating bucket stays `ambiguous`. This is module policy; REDCap would use the current instance number.
- A numeric instance on a non-repeating target is `invalid`.
- An empty instance set counts as "no answer", the same as a blank operand.
- A set includes the current instance.

## How existing rules stay untouched (the four guarantees)

1. **Dialect option.**
   - `Logic::parse($expr, $opts)` and `QRID_whenParse(expr, opts)` accept the new syntax only when `qualified` is set.
   - The default mode stays byte-identical, including error messages and order.
   - So no existing fixture row, fuzz row or annotation test changes.
2. **New operand kind.**
   - Qualified references become `['qref', field, code, eventTok, instTok]`; plain refs stay `['ref', field, code]`.
   - Values are keyed by `Logic::refKey()` (`[event][field][instance]`), which can never collide with a bare field name.
   - A qref whose key is absent throws, so an unconverted caller fails closed instead of reading a blank.
   - A qref is never live in `fold` and never reaches the browser.
3. **Partition by rule.**
   - After `getRules()`, rules whose conditions contain a qref go down a separate path.
   - Legacy loops iterate only legacy rules, so their read sets, getData params, chunk widths and contexts are unchanged.
   - Rule arrays gain no keys, so `rule_revision` and durable finding identities are unchanged.
4. **Golden harness first.** Before any feature code, commit byte-level captures of today's outputs. They must match at every later commit.

## Syntax and semantics (both runtimes, only with the setting on)

| Form | Meaning |
|---|---|
| `[f]`, `[f(code)]` | Unchanged |
| `[E][f]` | `f` in event with unique name `E` (token `[a-z0-9_]+`, may start with a digit) |
| `[event-name\|previous-event-name\|next-event-name\|first-event-name\|last-event-name][f]` | Relative events (REDCap semantics, see below) |
| `[f][N]` | Instance N, where N ≥ 1 |
| `[f][current-instance\|previous-instance\|next-instance\|first-instance\|last-instance]` | REDCap instance selectors |
| `[f][any-instance\|all-instances]` | Module extension: the comparison holds for at least one / every instance |
| `[E][f][I]` | Combined forms |

**Lexer rules**
- A reference has one to three adjacent bracket groups, with no whitespace between them.
- A `(code)` is allowed only on the field group.
- `[new-instance]` and a bare `[event-name]` are errors.
- At most one set operand per comparison.
- A qualified ref counts once toward `MAX_REFS`. `MAX_EXPR_LEN` stays 500 (documented).

**Resolution for context C = (record, event, instrument, instance)**
- **Event**
  - None, or `event-name`: C's event.
  - A name: resolve to an event id. Unknown → `invalid` (catches renamed events).
  - `previous-event-name` / `next-event-name`: the nearest earlier/later event in C's arm where `f`'s form is designated. None → blank.
  - `first-event-name` / `last-event-name`: the first/last such event in the arm.
  - Unknown order or designation → `unreadable` for relative tokens only. Named and current events fail open, as legacy does.
- **Designation:** `f`'s form not designated in the resolved event → `missing`.
- **Instance**
  - No token:
    - target does not repeat → base row;
    - target bucket is C's own bucket → C's instance (identical to plain);
    - otherwise → `ambiguous`, with a message suggesting a selector.
  - `N`, `current`, `previous` (C−1), `next` (C+1): the target must repeat in the resolved event, else `invalid`. A missing instance reads blank.
  - `first` / `last`: min/max over the instance index for the target bucket. The index is built from `<form>_complete` markers, not field values. It is unioned with C's instance when the target bucket is the page's own, so a new unsaved instance counts as last in the browser just as it will in the post-save audit. An empty index reads blank.
  - `any` / `all`: the list over that same index. Evaluation is OR / AND of the existing `compare()` per element; an empty list gets the blank-operand rule.
- **Self:** a resolved (event, bucket, instance) equal to the page, with state `ok`, is rewritten on render to a plain `['ref', f, code]` and stays live.
- **States:** `ok | missing | ambiguous | unreadable | invalid`. New wording applies to qualified problems only; the four existing messages are unchanged.

## Steps (one or more commits each; every commit green)

### Step 0: Integrate and baseline
- The pending `.gitignore` change is already committed (`e588d47`).
- Merge `claude/validation-scan-report-944c61` into `fix/audit-2026-09-03-confirmed-set`. A trial merge on 2026-09-16 (aborted, nothing kept) conflicted in five files:
  - `UniversalValidator.php`: 1 hunk. The schema-install `catch` comment; take the scan branch's `\Throwable` logging version.
  - `php/Scan/RecordManifestSource.php`: 3 hunks. The fallback group resolution in `dagsOf()`, `inScope()` and the `__GROUPID__` fold; both sides fixed the same defect differently, so pick one implementation and keep both test sets passing.
  - `php/ScanPageView.php`: 2 hunks, docblock and comment text around the numeric DAG id.
  - `CHANGELOG.md`: 1 hunk.
  - `tests/namespace_lint_php.php`: add/add, 2 hunks.
- Keep both the 1.10/1.11 condition work and the wave 1-6 scan fixes.
- Run every suite in `.github/workflows/parity.yml` (PHP 8.3 on PATH, portable 7.4, Node) and the MySQL matrix. The scan branch changed `php/Scan`.
- Branch `feat/event-instance-references` from the merge.

### Step 1: Legacy golden harness (tests only)
- Add `tests/gen_legacy_golden_php.php` (generator), `tests/golden/*.json` and `tests/legacy_golden_php.php` (checker, added to `parity.yml`).
- Scenarios:
  - classic; longitudinal with 2 events; repeating form; repeating event;
  - survey; restricted-rights user;
  - unreadable read; absent record;
  - branch rules; the unique AJAX endpoint;
  - `scanProject`; durable `durableEvaluateRecord`.
- Pin the HMAC keys (`log-hmac-key` and the scan key).
- Capture payload JSON bytes, `logCalls`, full getData params, `settingReads`, scan results and durable rows.
- Record every suite's check count in `tests/README.md`.

### Step 2: Dialect twins (`php/Logic.php`, `js/engine.js`), no callers yet
- **PHP (`php/Logic.php`)**
  - `parse($expr, array $opts = [])`: `lex` and `parseOperand` get a qualified branch.
  - New helpers: `refKey`, `refText` (authored spelling, value-free), `qualifiedRefs`, `hasQualifiedRefs`.
  - `operandValue`: qref → `$values[refKey]`, throwing without values in the message if absent.
  - `evaluate` compares set operands.
  - `fold`: a qref is off-page by refKey; sets are baked as `['litset', 'any'|'all', [operands]]`; the snapshot is recorded by `refText`.
- **JS twins (`js/engine.js`)**
  - `QRID_whenParse(expr, opts)`, `QRID_whenLex`, `QRID_whenOperandTok`, `QRID_whenEvaluate`/`EvaluateWith` (refKey maps, `litset`), `QRID_whenQualifiedRefs`, and the exports.
  - `QRID_whenRefs` stays plain-only.
  - `gateFor` guard: a raw qref logs a console error and returns the safe polarity (true in the assert role, false for a gate) without throwing.
- **Tests**
  - Add `tests/when_qualified_fixture.json`, `tests/when_qualified_php.php` and `tests/when_qualified_js.cjs`, covering evaluation with refKey maps, sets (empty, blank, under `not`), errors, refs and fold.
  - `tests/gen_when_fuzz.cjs` appends a qualified batch with its own seed and a `mode` field; `tests/when_fuzz_php.php` honours `mode`.
  - An invariance check: every legacy-accepted expression gives the same AST in qualified mode.

### Step 3: Project shape and resolver (pure classes)
- **`php/ProjectShape.php`**
  - Components: event name ↔ id (`REDCap::getEventNames(true)`, `getEventIdFromUniqueEvent`); arm per event (`$Proj->eventInfo`, falling back to the `_arm_N` suffix); ordered events per arm; designation (`getInstrumentEventMappings`); repeating forms and whole events (`getRepeatingFormsEvents`, including `'WHOLE'`); the longitudinal flag.
  - Each component answers unknown/yes/no and caches successes only.
  - It does **not** reuse the `formsForEvent`/`repeatingFormsFromMap` caches, which cache null and misread `'WHOLE'`.
- **`php/AddressResolver.php`, plus a `RecordIndex` of instances per event bucket**
  - Implements the resolution rules above and returns `{state, value|values, self, resolvedAt, why}`.
  - Plain refs inside qualified rules resolve through the existing `resolveOne()` on the same node.
- **Adapter:** `UniversalValidator::projectShape($pid)`, plus `require_once`, the CI `php -l` list and the package file list.
- **Tests (`tests/address_resolver_php.php`)**
  - arms: 2 arms; a record in both arms;
  - events: an event with no forms; unknown order or designation; `WHOLE` events; a form repeating in some events only;
  - selectors: every keyword × context; the new-instance union; markers vs blank fields; checkbox maps; sets;
  - self and reads: the self matrix; the empty-result read semantics copied from `readValues` (`UniversalValidator.php:4539-4547`).
- **Probe page:** add a dev-only probe page, `pages/temporal_oracle_probe.php`. It compares resolver output with `REDCap::evaluateLogic` and is deleted before release.

### Step 4: Config channels and the setting (inert)
- **`config.json`:** project settings `enable-event-instance-refs` (checkbox, default off) and `qualified-audit-max-contexts` (default 500).
- **Thread the parse options** through `php/AnnotationRules.php`:
  - `parseAllTags` → per-tag parsers → `checkFragment`, `checkConstraint`, `checkRequired`, `checkUnique`, `checkChoices`.
  - In `UniversalValidator.php`, through `getSettingRules`, `settingRowToRule`, `getAnnotationRules` and `validateSettings` (using the submitted value), via a new `qualifiedOpts($pid)`.
- **`Logic::checkQualifiedRefs($ast, $types, $choices, $shape)`**
  - Called at the three `checkRefs` sites (`UniversalValidator.php:1643-1652`, `1763-1770`, `1938-1955`), after the existing checks so legacy error order is unchanged.
  - Checks:
    - field rules: the field exists, is not file/descriptive, and the code fits;
    - event tokens: require a longitudinal project; a named event must exist, with the form designated there;
    - instance tokens: require a form that repeats somewhere;
    - one set per comparison.
- **Temporary guard:** a qualified rule gets the configError "recognised but not enabled in this build" (removed in Step 9).
- **Tests:** opted-in cases in `tests/annotation_php.php` and `tests/hosting_php.php`. The golden test must pass with the setting on and with it off.

### Step 5: Client `strictDeferral` flag (`js/engine.js`)
- Add `strictDeferral` to `DEFAULT_KEYS`.
- When true, all six factories (single, pooled, choices, unique, required, constraint) behave the same way:
  - `deferred` → no block, show the notice;
  - `snapshotFields` → advisory only.
- Evaluate `litset` in gates.
- Rules without the key behave as today.
- Tests: `tests/strict_deferral_dom_js.cjs`, covering 6 factories × deferred/snapshot/branch else/survey, plus control assertions that lock today's behaviour when the key is absent.

### Step 6: Render (`UniversalValidator.php`)
- **`buildClientConfig`:** partition the rules.
  - Legacy rules go through the unchanged `foldRuleConditions`.
  - Qualified rules go through a new `foldQualifiedRules()`.
  - Splice results back by original index, keeping a sequential list.
- **`foldQualifiedRules`**
  1. **Read:** one getData (record, qualified-rule fields + markers + pk, no events filter), with `readValues`' empty-result semantics.
  2. **Resolve and rewrite:** resolve every reference, then rewrite self refs (state `ok` only).
  3. **Disclosure and fold:**
     - `disclosableQualified`: form rights to the target form, no same-instrument exclusion, never on surveys.
     - `Logic::fold` with refKey maps.
     - `qualifiedResolutionProblem` wording.
  4. **Output:** set `strictDeferral` on the rule and every branch. Assert that no `qref` node remains.
  5. **Errors:** try/catch → defer the rule and log without values.
- **Tests (`tests/qualified_render_php.php`)**
  - sentinel values never appear unless entitled;
  - exactly one extra getData, only on pages with qualified rules;
  - self stays live; `last-instance` on a new instance stays live;
  - survey deferral; relative events; sets baked as `litset`.

### Step 7: Save audit
- **Legacy loops** in `redcap_save_record` iterate legacy rule indices only.
- **New `auditQualified()`**
  - **Index first:** a dependant index built from the dictionary (forms of qualified-rule fields). An unrelated save reads nothing (`tests/hook_php.php:322`).
  - **Evaluate:** one whole-record read; host contexts via `hostContextsFor()`. A context is evaluated only when:
    - it is the saved context itself;
    - `resolvedAt` matches the save exactly (event + bucket + instance; any instance for sets);
    - or a plain ref points to the saved form in the same event.
  - **Limits:** capped by `qualified-audit-max-contexts`, with a single "truncated, run the scan" note. An import save without project context gets one note.
- **`ruleFindings(..., $opts)`:** qualified parse, plus refKey guards over `qualifiedRefs` (an empty loop for legacy rules).
- **Tests:** `tests/qualified_audit_php.php`; golden log entries and getData calls unchanged.

### Step 8: Scan and unique (most sensitive to the merge)
- **`scanPlan`:** separate `qualified` rules and `qualifiedReadSet`; the legacy `readSet` is untouched. Rights and ownership include qualified target forms (`ruleRefFields` for qualified rules, durable ownership map).
- **`scanProject` and the durable read closure:** a second, sub-chunked read for qualified fields, stashed per record (no worker interface change). `scanRecord` evaluates qualified rules against that node.
- **Unique rules:** `collectUniqueCandidates` and `uniqueRuleFor` resolve qualified selectors; a non-ok result gives no verdict.
- **`php/Scan/ScanService.php`:** the fingerprint `structure` is a ProjectShape digest, only for projects with qualified rules.
- **Tests:** `tests/qualified_scan_php.php` (scanProject, durable evaluate, unique) and a render = audit = scan agreement test on shared fixtures. Scan goldens unchanged. Run the MySQL matrix if any store code changed.

### Step 9: Live verification, enable, document
- **Dev project on chpr-redcap.org** (development status): 2 arms, an event with no forms, a form repeating in some events, a repeating event, a survey, and a classic project.
  - Run the probe page.
  - Confirm: `WHOLE`, mapping row shapes, event order vs `[previous-event-name]`, whether all-blank rows are omitted, case of event names.
  - Fix any mismatch in the resolver and its tests, then delete the probe page.
- **Enable:** remove the Step 4 guard.
- **Docs:**
  - `config.json` help texts (lines 210, 220);
  - `README.md` tables; `docs/USER_GUIDE.md` (553-560, 568-575, 816-841); `docs/action_tag_validation_examples.md`;
  - `site/content/uvassert.html` and `uvalidate.html`;
  - `docs/TESTING.md`, `tests/README.md`.
- **CHANGELOG 1.12.0 and version bump.** Upgrade note: turning the setting on revives stored `][` rules, which can merge into branch rules with legacy rules on the same field.

## Out of scope (legacy defects to raise separately; fixing them changes current behaviour)
- The single/pooled/unique/choices factories ignore `deferred`: an unresolved branch selector still enforces the else branch (H-01).
- The assert gate turns an evaluator throw into a violation plus a hard block (`engine.js:1764-1767`, `2613`).
- Required/check/unique/choices factories ignore `snapshotFields`, although `config.json` promises cross-instrument comparisons never block.
- `formsForEvent` caches null for events with no forms.
- A set variant that excludes the current instance (duplicates across instances).

## Verification (every step)
- **PHP:** every suite in `parity.yml` with `php tests/<suite>.php` on PHP 8.3, and on portable PHP 7.4 per the local runtime recipe.
- **JS:** every suite with `node tests/<suite>.cjs`.
- **Fuzz corpus:** run `node tests/gen_when_fuzz.cjs`; `git diff tests/when_fuzz.json` must show appended rows only.
- **Goldens and counts:** `php tests/legacy_golden_php.php` stays byte-identical; pre-existing suites keep their Step 1 check counts.
- **MySQL matrix** after Steps 0 and 8.
- **Mutation checks:** remove the qref "never live" rule, the throw-on-absent-key, or the partition. The golden or render tests must fail each time.
- **Live pilot:**
  - With the setting off, pid 149 payload, module log and scan CSV match the pre-upgrade captures.
  - With the setting on and no qualified rules, they are still identical.
  - After adding qualified rules on the dev project, legacy rows still match, and the new rules behave per the matrix (data entry via the redcap-form-filler skill).
- **After any subagent run,** check `git diff --stat`.
