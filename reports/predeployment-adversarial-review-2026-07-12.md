# Predeployment Adversarial Review

**Module:** Universal Field Validator — IDs, codes & patterns  
**Repository:** `redcap-universal-validator`  
**Reviewed commit:** `0263a93` (`main`)  
**Review date:** 2026-07-12  
**Review posture:** Pre-submission gate for the REDCap Repository of External Modules  
**Overall verdict:** **DO NOT SUBMIT in the current state**

## 1. Executive summary

The module has a strong core: its check-character implementations are carefully
cross-tested, the PHP and JavaScript engines agree on the supplied fixtures, the
configuration-to-script boundary is materially hardened against XSS, and the
documentation is unusually candid about the post-save nature of the server hook.

That core is not yet enough for a REDCap Repo submission. This review found:

- **4 submission blockers**
- **7 high-severity findings**
- **11 medium-severity findings**
- **4 low-severity findings**

The most important issues are:

1. There is no public, tagged release available to the submission process, and no
   evidence of a passing REDCap security scan on the latest REDCap release.
2. The module's own live test notes say Data Import Tool writes on REDCap 17.0.6
   produced no audit log. The Repo-facing description nevertheless advertises a
   server-side audit of API and import writes.
3. A JavaScript-valid regex can pass the ReDoS gate and freeze the browser with
   only 42 attacker-controlled characters.
4. Project IDs are explicitly threaded into dictionary/data reads but not into
   `getSubSettings()` or `getProjectSetting()`, undermining rules and privacy-mode
   resolution in exactly the API/import contexts the code says are unreliable.
5. The exception path logs a raw record ID even in strict or logging-off modes.
6. The event fallback can validate a value from the wrong longitudinal event.
7. Every save re-reads and revalidates every configured field across the project,
   including unchanged fields on other instruments, causing duplicate logs and
   unnecessary server load.
8. Dynamic validation results are not exposed as status/error relationships to
   assistive technologies; the current implementation is likely a WCAG 2.2
   Success Criterion 4.1.3 failure.

The recommended disposition is to fix all Blocker and High findings, complete a
live REDCap/browser/accessibility test matrix, run REDCap's current security
scanner, then perform a second independent submission-gate review.

## 2. Scope and evidence

### 2.1 Reviewed surfaces

- `config.json`
- `UniversalValidator.php`
- `php/AnnotationRules.php`
- `php/CheckCharacter.php`
- `js/engine.js`
- installation, testing, changelog, provenance, and test documentation
- all shipped JavaScript and PHP tests
- Git history, tags, tracked-package contents, and CI workflow
- current official REDCap External Module Framework documentation
- current REDCap External Modules Submission Survey and Module Review Guidelines
- privacy/security principles relevant to identifiers and logs
- WCAG 2.2 criteria relevant to live validation and errors

### 2.2 Threat model

The review considered:

- a malicious or careless project designer controlling module settings, field
  annotations, regexes, field names, and rule sizes;
- an unauthenticated survey respondent controlling field values and submission
  frequency;
- an authenticated data-entry user controlling field values;
- API/import/JavaScript-disabled paths bypassing the browser guard;
- malformed or legacy project settings after upgrades;
- longitudinal/repeating projects and unrelated-instrument saves;
- sensitive identifiers appearing in module logs;
- coexistence with other External Modules and REDCap Multi-Language Management.

### 2.3 Authoritative criteria

- The [REDCap submission survey](https://redcap.vumc.org/surveys/?s=X83KEHJ7EA)
  requires a public GitHub repository, an open-source license, a SemVer release
  tag, testing on recent REDCap and multiple browsers, and a current security-scan
  pass.
- The official [Module Review Guidelines](https://redcap.vumc.org/consortium/modules/external_modules_review_guidelines.pdf)
  require a complete, error-free module; discourage global JavaScript; require
  large- and small-project performance testing; and require escaping, appropriate
  authentication/permissions, and transparent data handling.
- The [framework requirements](https://github.com/vanderbilt-redcap/external-module-framework-docs/blob/main/requirements.md),
  [configuration reference](https://github.com/vanderbilt-redcap/external-module-framework-docs/blob/main/config.md),
  [JavaScript guidance](https://github.com/vanderbilt-redcap/external-module-framework-docs/blob/main/javascript.md),
  and [security guidance](https://github.com/vanderbilt-redcap/external-module-framework-docs/blob/main/security.md)
  were used for conformance checks.

### 2.4 Evidence limits

No live REDCap runtime, Control Center security scanner, browser matrix, screen
reader, or rendered module settings/form/survey UI was available in this
workspace. Therefore:

- this report does **not** claim a completed REDCap security scan;
- it does **not** certify WCAG conformance;
- it does **not** verify real REDCap save-button, survey, MLM, repeating-event,
  API, or import behavior;
- UI findings based on DOM/code are confirmed implementation risks; visual and
  assistive-technology behavior still requires live testing.

These are release-gate gaps, not reasons to assume the behavior is correct.

## 3. Severity model

| Rating | Meaning |
|---|---|
| Blocker | Submission prerequisite missing, materially misleading public claim, or missing evidence that REDCap explicitly requires before approval. |
| High | Credible loss of validation/audit coverage, wrong-record/event behavior, sensitive-data exposure, or severe availability/performance failure. |
| Medium | Material UX, accessibility, privacy-hardening, compatibility, or maintainability problem that should be fixed before broad deployment. |
| Low | Defense-in-depth, edge-case, packaging, or documentation issue with limited immediate impact. |

## 4. Findings summary

| ID | Severity | Area | Finding |
|---|---|---|---|
| PRE-001 | Blocker | Submission | No public SemVer release/tag is available to the REDCap submission process. |
| PRE-002 | Blocker | Security assurance | REDCap's mandatory current security scan has not been run or evidenced. |
| PRE-003 | Blocker | Integration assurance | No completed recent live REDCap/browser test matrix; a documented import test already failed. |
| PRE-004 | Blocker | Claims/compliance | Repo-facing API/import audit claim is stronger than observed coverage. |
| SEC-001 | High | ReDoS/availability | Regex safety gate misses a pattern that freezes the browser at 42 characters. |
| SEC-002 | High | Privacy/audit | Project ID is omitted from project-setting reads in unreliable contexts. |
| SEC-003 | High | Privacy | Audit-error logging exposes raw record IDs regardless of log privacy mode. |
| COR-001 | High | Longitudinal correctness | Cross-event fallback can audit the wrong event's value. |
| PER-001 | High | Server/logging | Every save audits all configured fields, including unrelated instruments. |
| PER-002 | High | Client/server availability | Pooled parser bounds are insufficient; a legal rule took 9.25 seconds per parse. |
| COR-002 | High | Configuration integrity | Settings are not validated atomically; invalid rules fail open or abort later audits. |
| COR-003 | Medium | Field compatibility | Settings picker accepts unsupported non-text fields. |
| COR-004 | Medium | Runtime parity | JavaScript and PCRE/Unicode semantics are not equivalent beyond fixture scope. |
| SEC-004 | Medium | Privacy | Unsalted SHA-256 identifiers remain enumerable and cross-project linkable. |
| SEC-005 | Medium | Log availability | Invalid-save logs are unthrottled and repeatedly duplicated. |
| A11Y-001 | Medium | Accessibility | Dynamic results lack live-region, error-state, and descriptive relationships. |
| A11Y-002 | Medium | Accessibility | Pooled “junk” chip text fails WCAG AA normal-text contrast. |
| UX-001 | Medium | Usability | Configuration errors appear too late and can be shown to unrelated survey users. |
| UX-002 | Medium | Usability | Settings surface is overloaded and contains undocumented normalization edge cases. |
| UX-003 | Medium | Enforcement | Browser “compulsory” mode is bypassable and may trap users on readonly fields. |
| CMP-001 | Medium | REDCap/MLM | Hard-coded globals/messages and one-time binding do not follow modern JSMO/MLM patterns. |
| TST-001 | Medium | Assurance | Current tests mask key context bugs and do not cover declared compatibility. |
| COR-005 | Low | Edge case | Prototype-inherited field names can silently drop client validators. |
| CI-001 | Low | Supply chain | GitHub Actions are tag-pinned only and token permissions are not minimized. |
| DOC-001 | Low | Documentation | Test counts and several user-facing claims are stale or internally inconsistent. |
| PKG-001 | Low | Packaging | No reproducible release/package check enforces the required module directory shape. |

## 5. Detailed findings

### PRE-001 — No public SemVer release/tag

**Severity:** Blocker  
**Evidence:** local `git tag --list` returned no tags; unauthenticated GitHub API
requests for the configured origin returned 404; the submission survey requires a
public GitHub repository and version-number release tag.

The changelog calls the code `0.4.0`, but HEAD contains changes after that
milestone and no immutable release identifies the exact submission payload.

**Impact:** REDCap's submission service cannot reliably retrieve `config.json` or
the static source set it will review and distribute.

**Required remediation:** Make the repository public; decide the actual release
version; create a signed/annotated SemVer tag and GitHub release; verify the
release URL anonymously; ensure the archive contains one correctly named module
directory such as `universal_validator_v0.4.1/` with `config.json` at its root.

### PRE-002 — Mandatory REDCap security scan not evidenced

**Severity:** Blocker  
**Evidence:** `docs/TESTING.md:113-114` leaves the scan unchecked. The current
submission survey states modules are not approved until they pass the security
scan in the latest REDCap version.

Local grep/static review is not a substitute for REDCap's scanner because the
scanner understands framework-specific sources, sinks, escaping, authentication,
and false-positive suppression.

**Required remediation:** Install the release candidate on the latest available
REDCap, run Control Center → External Modules → Manage → Module Security
Scanning, resolve every real issue, document any Vanderbilt-confirmed false
positive, and retain scan output/version/date as release evidence.

### PRE-003 — Live integration and browser matrix is incomplete

**Severity:** Blocker  
**Evidence:** `docs/TESTING.md` is entirely unchecked and explicitly reports that
Data Import Tool testing on REDCap 17.0.6 produced no module audit log
(`docs/TESTING.md:80-91`). There is no Cypress/REDCap integration harness or
recorded manual execution evidence.

**Impact:** Core claims involving real hooks, form/survey DOM, save buttons,
longitudinal data, repeating instruments, MLM, API/imports, and permissions remain
unverified.

**Required remediation:** Execute and sign the checklist on a current standard and
supported LTS REDCap version, with Chrome, Firefox, and Edge/Safari as applicable.
Add keyboard-only and screen-reader checks, public survey tests, JavaScript-off
tests, and at least one large project. Archive versioned evidence.

### PRE-004 — API/import audit marketing overstates known behavior

**Severity:** Blocker  
**Files:** `config.json:4`, `UniversalValidator.php:7-8`, `README.md:32-44`,
`docs/TESTING.md:80-91`

The Repo-displayed `config.json` description says “a server-side audit of API and
import writes.” The class header says invalid IDs are caught via API/import. The
README later adds a caveat, while the test guide records an actual REDCap 17.0.6
import with no audit entry.

**Impact:** A site may treat the module as a compensating control for non-browser
writes when that control did not execute. This is a data-integrity and compliance
representation risk.

**Required remediation:** Until coverage is proven across supported versions,
remove API/import coverage from the short Repo description and describe the hook
as “best-effort post-save audit where REDCap invokes the hook.” Never call it
enforcement. Add a startup/project diagnostic or documented data-quality report
for unsupported ingestion paths.

### SEC-001 — ReDoS gate is bypassable with short input

**Severity:** High  
**Files:** `js/engine.js:549-567`, `js/engine.js:613-628`,
`php/CheckCharacter.php:181-223`, `tests/risky_patterns.json`

The heuristic misses overlapping-alternation patterns. Reproduction:

```text
pattern: (a|aa)+
gate result: false
input: "a" repeated 42 times followed by "b"
result: Node process exceeded 3 seconds and was killed (exit 124)
```

The 512-character single-field cap does not bound regex time. The browser runs
the expression synchronously on every `input` event. A project designer can add
such a rule accidentally; any data-entry user or public survey respondent can
then trigger the expensive match with field input.

**Impact:** Form/survey UI freeze; repeated server PCRE work; loss of availability.

**Required remediation:** Do not accept arbitrary JavaScript regex as an
untrusted runtime primitive. Prefer a safe-regex subset/parser or RE2-compatible
engine. At minimum, reject ambiguous quantified alternation/optional overlap,
cap regex source and quantified ranges, reduce input caps, debounce validation,
and run adversarial timeout tests in isolated processes. A heuristic should never
be described as bounding ReDoS unless the bound is demonstrated.

### SEC-002 — Project settings are read without the explicit project ID

**Severity:** High  
**Files:** `UniversalValidator.php:76`, `UniversalValidator.php:142`,
`UniversalValidator.php:218-223`, `tests/hook_php.php:23-25`

The code correctly explains that `getProjectId()` can be null in API/import
contexts and passes `$project_id` to dictionary/data APIs. It does not pass `$pid`
to either:

```php
$subs = $this->getSubSettings('rules');
$mode = $this->getProjectSetting('log-values');
```

Both official framework methods accept an optional project ID. The hook test mock
always returns the configured values and does not record a second argument, so it
masks the production risk.

**Impact:** dialog rules may disappear or come from the wrong context; a project's
`none` or `off` privacy selection may fall back to `hashed`, which still logs a raw
record ID.

**Required remediation:** Thread `$project_id` through `getRules()`,
`getSettingRules()`, and `logInvalid()`, and call `getSubSettings('rules', $pid)`
and `getProjectSetting('log-values', $pid)`. Update mocks to require and assert the
hook PID when `getProjectId()` is null.

### SEC-003 — Error path logs raw record IDs in every privacy mode

**Severity:** High  
**Files:** `UniversalValidator.php:114-123`, `UniversalValidator.php:140-165`

`logInvalid()` respects raw/hashed/strict/off modes, but the outer catch always
logs:

```php
'record' => (string) $record
```

It also logs the exception message without applying the selected data-minimization
policy. Thus a `none` project that deliberately hashes record IDs, and even an
`off` project, can emit a raw record ID on audit failure.

**Impact:** unexpected identifier/possible PHI disclosure to the External Module
log; contradiction of the strict-mode privacy promise.

**Required remediation:** Resolve the log mode with explicit PID before the try
body; apply the same record transformation to error logs; make `off` semantics
explicit; sanitize exception detail for production and place diagnostic detail
behind an administrator-only debug mode.

HIPAA requires safeguards for confidentiality, integrity, and availability of
ePHI ([HHS Security Rule summary](https://www.hhs.gov/hipaa/for-professionals/security/laws-regulations/index.html)).
GDPR treats pseudonymized-but-reidentifiable information as personal data and
requires data minimization and privacy by default
([GDPR Articles 5 and 25](https://eur-lex.europa.eu/eli/reg/2016/679/oj)). This
report is engineering guidance, not a legal compliance certification.

### COR-001 — Cross-event fallback can audit the wrong value

**Severity:** High  
**Files:** `UniversalValidator.php:417-451`

If the exact repeating/non-repeating event lookup does not find the field, the
code scans every non-repeating event node and accepts the first occurrence. In a
longitudinal project, failure to locate event B can therefore validate event A's
value while logging event B's ID.

**Impact:** false invalid logs, missed invalid values, misleading audit evidence,
and loss of event/record integrity.

**Required remediation:** Never cross event boundaries when an event ID is
supplied. Normalize event keys explicitly if REDCap returns a different key form;
otherwise log a scoped “value unavailable” diagnostic. Add tests with valid event
A/invalid event B, invalid A/valid B, repeating event, repeating instrument, and
missing-instance cases.

### PER-001 — Every save revalidates the entire project rule set

**Severity:** High  
**Files:** `UniversalValidator.php:79-113`, `UniversalValidator.php:335-352`,
`UniversalValidator.php:398-408`

On every `redcap_save_record`, the module loads all settings, scans the full data
dictionary for annotations, requests all configured fields, and validates them
all. It does not filter rules by `$instrument` or changed fields.

**Impact:**

- saving an unrelated instrument can repeatedly log an old invalid value;
- log counts do not represent distinct invalid-save events;
- large projects pay dictionary, `getData`, regex, and pooled-parser costs on
  every save;
- public/automated save traffic can amplify log and server load.

This conflicts with the review guideline to test modules handling project data
against both large and small data sets.

**Required remediation:** Map configured fields to `form_name`, filter to the
saved instrument/event, and define a conservative strategy for API/import calls
where the instrument is absent. Dedupe equivalent invalid detections and consider
framework throttling. Add query-count and timing assertions for 10, 100, and
1,000 configured fields.

### PER-002 — Pooled parsing permits multi-second work per keystroke

**Severity:** High  
**Files:** `js/engine.js:566-567`, `js/engine.js:989-1023`,
`js/engine.js:1062-1104`, `php/CheckCharacter.php:316-336`

The dynamic program tests every configured candidate length at every character,
and each check-character validation processes the candidate substring. Numeric
configuration values have no practical upper bound.

Measured locally on Node 20.20.0:

```text
default lengths 8..14, 8,192 chars: ~137 ms
lengths 100..199, 8,192 chars:       ~9,250 ms
```

`100..199` passes the current safety rule (`max < 2 * min`) and is therefore a
legal configuration. Parsing runs on every input event. Much larger min/max
ranges can also allocate enormous `LENS` arrays during initialization.

**Required remediation:** Establish realistic hard maxima for ID length, number
of candidate lengths, pool characters, and expected members; reject excessive
rules in PHP before injection; debounce input; cancel stale work; benchmark low-end
clients; and place server-side time/complexity guards around pooled parsing.

### COR-002 — Configuration is not validated atomically

**Severity:** High  
**Files:** `UniversalValidator.php:218-327`, `UniversalValidator.php:87-114`,
`php/CheckCharacter.php:211-235`, `php/CheckCharacter.php:293-336`

The settings channel does not centrally validate algorithm, source, type,
JavaScript/PCRE compilation, `none`-without-pattern, pooled length relationships,
sum-swallow combinations, or upper bounds. Several errors are discovered only by
the browser; server helpers deliberately return “valid/unconfigurable” on regex
compile/engine failures. An unknown algorithm can throw inside validation, and the
single outer catch then stops auditing all later rules.

**Impact:** fail-open API/server audit, partial coverage loss, and configuration
errors discovered by data collectors rather than designers.

**Required remediation:** Override the framework's documented `validateSettings()`
to reject invalid settings before save. Reuse one pure rule validator for settings
and annotations. Validate all rules first; isolate each rule/field during audit so
one failure cannot suppress the rest; log explicit `unconfigurable` outcomes
instead of treating them as valid.

### COR-003 — Field picker accepts unsupported field types

**Severity:** Medium  
**Files:** `config.json:80-84`, `UniversalValidator.php:335-350`,
`js/engine.js:860-866`, `js/engine.js:1255-1261`

Annotations explicitly reject non-Text/Notes fields. The settings `field-list`
has no `field-type` restriction and no matching server-side check. A dropdown is
not found by the client and silently receives no browser validator; a radio group
returns the first input rather than the selected logical value; the server may
still audit it.

**Required remediation:** Restrict the picker to supported fields where the
framework permits; otherwise validate selected metadata and reject incompatible
fields at settings-save time. Test text, notes, radio, checkbox, select, calc,
descriptive, slider, and readonly/disabled cases.

### COR-004 — JS/PCRE/Unicode parity is narrower than advertised

**Severity:** Medium  
**Files:** `js/engine.js:613-628`, `php/CheckCharacter.php:202-235`,
`php/CheckCharacter.php:385-448`

The parity fixture strongly covers check-character math and selected
normalization. It does not make JavaScript RegExp and PCRE equivalent. Example:
JavaScript treats an astral emoji as two UTF-16 code units, while PCRE `/u` treats
it as one code point; a `.`/`..` pattern can therefore produce opposite verdicts.
The pooled PHP parser later uses byte `strlen()`/`substr()` after a Unicode-aware
normalization step, while JavaScript slices UTF-16 code units.

**Impact:** client/server disagreement, false logs, or server fail-open behavior
for non-ASCII patterns/keep characters.

**Required remediation:** Explicitly constrain regexes, separators, and keep
characters to an audited ASCII subset, or build a cross-runtime compatibility
validator and differential corpus. Document the subset in the UI.

### SEC-004 — “Hashed” logging is pseudonymization, not anonymity

**Severity:** Medium  
**Files:** `UniversalValidator.php:154-163`, `config.json:36-42`

Plain SHA-256 of low-entropy or predictable study IDs is vulnerable to offline
enumeration and allows the same value to be correlated across projects. The
setting copy emphasizes correlation but does not explain this risk.

**Required remediation:** Use a keyed, project-scoped HMAC with a server-held
secret if repeat correlation is required; otherwise omit the value. State clearly
that hashes remain identifiers/pseudonymous data. Document log access and
retention responsibilities.

### SEC-005 — Log volume is unbounded and duplicate-prone

**Severity:** Medium  
**Files:** `UniversalValidator.php:109-110`, `UniversalValidator.php:140-165`

Each invalid field on each save creates a log row. There is no deduplication,
sampling, retention guidance, or throttle. Combined with PER-001, an unchanged
invalid field can be logged repeatedly on unrelated saves.

**Required remediation:** Dedupe by project/record/event/instance/field/value-HMAC
and state transition; add an administratively visible count; define retention;
and use the framework throttle facility where appropriate.

### A11Y-001 — Dynamic validation is not programmatically exposed

**Severity:** Medium  
**Files:** `js/engine.js:572-591`, `js/engine.js:873-900`,
`js/engine.js:1169-1212`, `js/engine.js:1268-1282`

Result containers are plain `div` elements. They have no `role=status`/`alert`,
`aria-live`, stable ID, `aria-describedby` relationship, or input
`aria-invalid`. Their content changes without focus moving to it. The hard-block
alert lists internal field names rather than accessible labels.

This closely matches W3C's documented [Failure F103](https://www.w3.org/WAI/WCAG22/Techniques/failures/F103.html)
for status messages that cannot be programmatically determined and creates risks
under WCAG 2.2 SC 3.3.1 and 4.1.3
([status-message guidance](https://www.w3.org/WAI/WCAG22/Understanding/status-messages.html)).

**Required remediation:** Give each message a stable ID; connect it with
`aria-describedby`; maintain `aria-invalid`; use a polite status region for
progress/success and an appropriate alert/error announcement for final errors;
focus the actual field and announce its REDCap label at blocked save. Test with
NVDA/JAWS and VoiceOver, not only DOM inspection.

### A11Y-002 — Amber chip contrast fails AA for normal text

**Severity:** Medium  
**Files:** `js/engine.js:1157-1167`

Computed contrast for `#b26a00` on `#fbf6e8` is approximately **3.93:1** at 12px,
below WCAG 2.2's 4.5:1 minimum for normal text. Other measured message text pairs
passed 4.5:1; the status borders were low-contrast but are supplemented by text.

**Required remediation:** Darken the amber foreground to at least 4.5:1, retain a
non-color marker and text label, and verify all states in the rendered REDCap
theme at 200%/400% zoom.

### UX-001 — Configuration errors surface in the wrong audience/context

**Severity:** Medium  
**Files:** `js/engine.js:568-591`, `js/engine.js:912-934`,
`js/engine.js:1285-1307`, `js/engine.js:1341-1362`

Any config-error rule immediately creates a page-level notice without first
checking whether its field belongs to the current form. One malformed annotation
or duplicate elsewhere in the project can therefore show technical details and
field names on every form, including public surveys. Conversely, valid rules for
missing fields silently stop looking after ten seconds.

**Required remediation:** Show configuration diagnostics to project designers in
the configuration/Online Designer workflow. On forms, scope errors to fields on
the current instrument and replace respondent-facing technical detail with a safe
generic message plus an administrator log/diagnostic reference.

### UX-002 — Configuration is difficult and contains hidden semantics

**Severity:** Medium  
**Files:** `config.json:51-180`, `UniversalValidator.php:278-283`

Each repeatable rule exposes many controls, including pooled-only controls for
single rules. Regexes require JavaScript syntax, are matched against an uppercased
value, and have no test box or save-time preview. Technical errors appear only
when a form opens. `!empty()` means a legitimate pattern equal to string `"0"` is
discarded, and the settings dialog cannot express an intentionally empty `strip`
value even though annotations can.

**Required remediation:** Build save-time validation and a rule tester showing
sample valid/invalid values; group basic/advanced/pooled settings; state uppercase
normalization next to the pattern; use presence/trim checks rather than `empty()`;
and provide copyable presets for common algorithms.

### UX-003 — “Compulsory” is browser-only and can trap users

**Severity:** Medium  
**Files:** `js/engine.js:815-857`, `js/engine.js:1214-1253`, `config.json:167-179`

The guard relies on submit/click interception and specific save-button naming. It
cannot stop API/import/JavaScript-off writes and may not cover every programmatic
REDCap save route. It also attaches to readonly/disabled fields and can block a
save while the user has no means to correct the value. Native `alert`/`confirm`
is disruptive and advisory mode may prompt on both click and submit in some DOM
paths unless explicitly tested.

**Required remediation:** Rename it “block browser form/survey save”; test every
REDCap save/continue/exit/survey button and keyboard submission; handle readonly,
disabled, hidden, and calculated values; and implement one accessible REDCap-style
summary dialog rather than multiple native dialogs.

### CMP-001 — JavaScript and MLM integration do not follow modern framework patterns

**Severity:** Medium  
**Files:** `js/engine.js:33`, `js/engine.js:536-540`, `js/engine.js:601`,
`js/engine.js:937`, `js/engine.js:1365-1382`

The module adds one namespace but retains multiple writable global aliases
(`QRCheck`, factories, config, registry, guard, pooled state). The official review
guidelines highly discourage global scope. Messages are hard-coded English, and
binding occurs once via DOM ready/observer rather than the framework JavaScript
Module Object's `afterRender`, which is designed for MLM rerenders.

**Required remediation:** Use `initializeJavascriptModuleObject()` and one module
namespace; remove unnecessary legacy aliases for a new Repo module; register
idempotent binding through `afterRender`; transfer translatable strings with the
framework `tt` facilities; test live language switching.

### TST-001 — Tests do not cover the risky integration contracts

**Severity:** Medium  
**Files:** `.github/workflows/parity.yml`, `tests/hook_php.php`,
`tests/pooled_fixture.json`, `tests/README.md`

Strengths notwithstanding, the suite has these gaps:

- `hook_php` mocks `getSubSettings()`/`getProjectSetting()` without optional PID
  and returns settings even when `getProjectId()` is null.
- No exact-event/repeat-instance negative tests exercise the cross-event fallback.
- No tests prove instrument filtering or log deduplication.
- No tests cover strict/off behavior on the exception path.
- Only eight pooled fixtures exist; the JS fixture is generated from the JS
  implementation itself, so it is a parity contract, not an independent oracle.
- No regex timeout corpus covers overlapping alternatives.
- CI tests PHP 8.1 only despite declaring PHP 7.4 minimum.
- No real DOM/browser, accessibility, MLM, or REDCap integration tests exist.

**Required remediation:** Add PHP 7.4/8.1/8.3 matrix coverage; property/fuzz and
timeout tests; independent pooled invariants; jsdom/Playwright or Cypress tests;
and a REDCap Cypress Developer Toolkit suite for critical flows.

### COR-005 — Prototype-inherited field names can drop validators

**Severity:** Low  
**Files:** `js/engine.js:1334-1354`

`counts = {}` is indexed by REDCap field name. Names such as `constructor`,
`toString`, or `hasOwnProperty` inherit truthy prototype members, corrupt the
count, and fail the strict `counts[f] === 1` filter.

**Required remediation:** Use `Object.create(null)` or `Map` for all user/config
keyed registries and add reserved-name tests.

### CI-001 — Workflow hardening is incomplete

**Severity:** Low  
**Files:** `.github/workflows/parity.yml:11-13`, `.github/workflows/parity.yml:34-36`

Actions use floating major tags rather than immutable commit SHAs, and workflow
token permissions are not explicitly reduced.

**Required remediation:** Add top-level `permissions: contents: read`, pin actions
to reviewed commit SHAs, and enable release provenance/attestation if available.

### DOC-001 — Documentation and metadata are inconsistent

**Severity:** Low  
**Files:** `README.md`, `tests/README.md`, `config.json`, `UniversalValidator.php`

Examples include “server always logs” despite `off` and hook non-coverage; class
header statements stronger than the README caveat; and README language that says
CI runs six tests although the workflow contains additional annotation, hook, and
notice tests. The `config.json` description is also unusually long for the Repo
listing and hides the most important limitation.

**Required remediation:** Make one concise, accurate capability statement the
source of truth; lead with browser-only enforcement and best-effort post-save
audit; update test counts automatically or avoid fixed counts.

### PKG-001 — Release packaging is not reproducibly checked

**Severity:** Low  
**Files:** `.github/workflows/parity.yml`, `docs/INSTALL.md`

There is no CI job that constructs the exact release archive, checks its root
directory name, rejects development-only files, installs it into REDCap, or
verifies anonymous release retrieval.

**Required remediation:** Add a release-packaging job that produces
`universal_validator_vX.Y.Z.zip`, validates `config.json`, checks class/namespace
matching, lists package contents, and smoke-installs the archive.

## 6. Positive controls confirmed

The following should be preserved:

- Inert JSON plus `JSON_HEX_*` encoding prevents script-tag breakout in the
  injected configuration (`UniversalValidator.php:174-190`).
- Config-derived content is escaped before current `innerHTML` sinks.
- No SQL, filesystem write, network relay, secret, or third-party runtime
  dependency exists in the module.
- `framework-version: 14` is compatible with the declared REDCap 13.7 floor, and
  the deprecated hook `permissions` block is absent.
- Required `README.md`, `LICENSE`, `config.json`, and matching module class exist.
- The module declares `@UVALIDATE` in `config.json`.
- Raw invalid field values are not logged by default.
- PHP and JS check-character parity is comprehensive for the supplied 643-row
  fixture.
- Client and server pooled implementations agree on the supplied fixtures.
- The code distinguishes client prevention from post-save server detection in the
  longer README, even though short metadata must be corrected.

## 7. Test execution evidence

### 7.1 Passed

```text
Node 20.20.0
PHP 8.3.6, locally extracted runtime with mbstring + ctype

node --check js/engine.js                                      PASS
php -l UniversalValidator.php                                 PASS
php -l php/CheckCharacter.php                                 PASS
php -l php/AnnotationRules.php                                PASS
node tests/parity_js.cjs       643 rows, 0 mismatches          PASS
php tests/parity_php.php       643 rows, 0 mismatches          PASS
node tests/pooled_js.cjs       8 cases, 0 mismatches           PASS
php tests/pooled_php.php       8 cases, 0 mismatches           PASS
node tests/risky_js.cjs        22 patterns, 0 mismatches       PASS
php tests/risky_php.php        28 checks, 0 mismatches         PASS
node tests/config_notice_js.cjs 7 checks, 0 failures           PASS
node tests/dispatch_notice_js.cjs 6 checks, 0 failures         PASS
php tests/annotation_php.php   30 checks, 0 failures           PASS
php tests/hook_php.php         14 checks, 0 failures           PASS
python3 -m json.tool config.json                              PASS
git diff --check                                              PASS
```

The first PHP attempt using `-n` without `ctype` failed because `ctype_space()`
was unavailable; loading the declared runtime's standard `ctype` module made the
test pass. Production prerequisites should explicitly include both `mbstring` and
`ctype` if the target PHP build can omit standard extensions.

### 7.2 Independent adversarial probes

```text
ReDoS probe: (a|aa)+ against 42 x "a" + "b"
gate=false; process timed out after 3 seconds                   FAIL

Pooled parse, 8,192 chars, length range 100..199
~9,250 ms for one parse                                        FAIL

Contrast: #b26a00 on #fbf6e8
3.93:1 at 12px                                                 FAIL WCAG AA normal text
```

## 8. Remediation plan

### Phase 0 — Freeze submission

1. Do not tag or submit the current commit.
2. Correct Repo-facing API/import and enforcement claims immediately.
3. Create tracked issues for every Blocker and High finding.

### Phase 1 — Security, privacy, and correctness

1. Replace or severely constrain arbitrary regex execution; add timeout probes.
2. Pass explicit PID into every project-setting/logging path.
3. Apply privacy mode consistently to audit-error logs.
4. Remove cross-event fallback and add longitudinal/repeating regression tests.
5. Filter audit work to the saved instrument/context and dedupe logs.
6. Add atomic `validateSettings()` validation and per-rule exception isolation.
7. Bound pooled-rule lengths/counts and debounce browser parsing.

### Phase 2 — UX and accessibility

1. Restrict supported fields and fail configuration at save time.
2. Add a rule tester and simplify/group the configuration UI.
3. Add ARIA relationships/live regions/invalid state and accessible save summary.
4. Fix amber contrast and test zoom/reflow.
5. Move technical configuration diagnostics away from public respondents.
6. Integrate JSMO `afterRender` and translations for MLM.

### Phase 3 — Assurance and release engineering

1. Expand unit, property, timeout, Unicode, event, privacy, and browser tests.
2. Test PHP 7.4 through current PHP and recent REDCap standard/LTS releases.
3. Complete live classic/longitudinal/repeating/survey/API/import tests.
4. Run REDCap's latest security scan and resolve all results.
5. Make the repository public, add support/security policies, tag a SemVer
   release, build a reproducible versioned archive, and verify it anonymously.
6. Commission a fresh independent review of the release tag, not the moving
   branch.

## 9. Minimum acceptance criteria before submission

- [ ] PRE-001 through PRE-004 closed with evidence.
- [ ] All High findings fixed and regression-tested.
- [ ] No known regex can exceed an agreed client/server time budget at allowed
      input/config limits.
- [ ] Exact event/instrument/instance audit behavior proven.
- [ ] Strict/off logging modes proven on success and exception paths.
- [ ] WCAG status/error behavior tested with at least one screen reader.
- [ ] Recent REDCap standard and LTS manual/integration matrices signed.
- [ ] API/import wording matches verified hook coverage.
- [ ] Latest REDCap External Module security scan passes.
- [ ] Public GitHub SemVer release and correctly named package are anonymously
      downloadable.
- [ ] Final review is performed against the immutable release tag.

## 10. Final opinion

The module is promising and its algorithmic verification work is above average,
but it is **not deployment- or submission-ready**. The current risk is not that
the check-character arithmetic is wrong; it is that validation can fail open,
audit the wrong context, expose identifiers through an exception path, consume
seconds on the main browser thread, and present inaccessible status changes. The
missing release/security-scan/live-integration evidence independently blocks a
responsible REDCap Repo submission.

Fixing the Blocker and High findings should be treated as a release requirement,
not backlog polish.
