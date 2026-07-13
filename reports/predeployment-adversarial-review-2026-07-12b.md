# Predeployment Adversarial Review (second pass)

**Module:** Universal Field Validator — IDs, codes & patterns
**Repository:** `redcap-universal-validator`
**Reviewed commit:** `d76bc1a` (`claude/redcap-module-review-bfa0db`, tree identical to `main` HEAD)
**Review date:** 2026-07-12
**Review posture:** Independent re-verification gate for the REDCap Repository of External Modules, after the `0.5.0` remediation of `predeployment-adversarial-review-2026-07-12.md`
**Overall verdict:** **One code fix still required before submission** (plus the three standing release-gate blockers that are people-work)

## 1. Executive summary

This is a second, independent adversarial pass. It re-verified — by running the
code, not by reading the changelog — every code-addressable finding from the
2026-07-12 review, and then hunted for issues that review missed. The result is
narrow and specific.

The `0.5.0` remediation is real. Of the 22 code-addressable findings, this pass
confirmed 21 are genuinely fixed with measured evidence: privacy-mode threading,
audit-error minimization, keyed HMAC identifiers, event and instrument scoping,
atomic settings validation with per-rule isolation, prototype-safe registries,
the pooled work budget, the ARIA wiring, the chip contrast, the honesty of the
public claims, and the CI/packaging hardening all hold up under test. The full
suite passes on PHP 7.4, 8.1-equivalent, and 8.3, and on Node. That is a strong
position.

One finding does not hold: **SEC-001 is only half-fixed.** The regex safety gate
now rejects the *exponential* backtracking shapes it was rewritten to catch
(`(a+)+`, `(a|aa)+`), but it still accepts a whole class of *polynomial*
backtracking patterns — overlapping unbounded quantifiers on ungrouped atoms,
such as `.*.*.*.*.*b` or `[0-9]*[0-9]*[0-9]*[0-9]*[0-9]*b`. Those patterns pass
the settings-save gate and the `@UVALIDATE` gate, and they freeze the browser:
measured at roughly 20 seconds for one match at a 200-character input, with the
field's own 512-character cap allowing far worse. The server (PCRE2) is not
affected, which is exactly why the browser exposure was not noticed — but the
browser is where the freeze happens, on data-entry forms and on public surveys.

This pass found:

- **1 high-severity finding** (SEC-001 re-opened: polynomial ReDoS freezes the browser)
- **3 standing submission blockers** (unchanged from the prior review; people-work, already disclosed in `docs/TESTING.md`)
- **4 low-severity findings**

The recommended disposition: close the one high finding, add regression coverage
for the polynomial class, then complete the standing release-gate work (public
tag, security scan, live matrix) before submitting.

## 2. Scope and method

### 2.1 What was done

- Read every source, doc, and test file at `d76bc1a`.
- Installed portable PHP 7.4.33 and 8.3.32 (with `mbstring` + `ctype`) and ran
  every PHP suite; ran every Node suite on Node 24.14. All green.
- Re-derived each 2026-07-12 finding against the current code and, where the fix
  was measurable, wrote a probe and measured it (ReDoS timing, WCAG contrast,
  pooled worst-case timing, save-time gate behavior).
- Confirmed the framework-version / REDCap-version claim against the authoritative
  Vanderbilt framework docs.

### 2.2 Threat model

Unchanged from the prior review: a careless-or-malicious project designer
controlling settings, annotations, regexes, field names, and rule sizes; an
unauthenticated survey respondent controlling field values and submit frequency;
an authenticated data-entry user; and API/import/JavaScript-off paths.

### 2.3 Evidence limits

No live REDCap runtime, Control Center security scanner, real browser, or screen
reader was available. This report therefore does not certify the REDCap security
scan, WCAG conformance, or live hook behavior. Those remain the standing
release-gate items in `docs/TESTING.md`. The ReDoS timings below were measured on
the same V8 engine that ships in Chrome and Edge; Firefox (SpiderMonkey) and
Safari (JavaScriptCore) also use backtracking regex engines and will exhibit the
same class of freeze.

## 3. Severity model

| Rating | Meaning |
|---|---|
| Blocker | Submission prerequisite missing or a materially misleading public claim. |
| High | Credible loss of availability, audit coverage, correctness, or sensitive-data protection. |
| Medium | Material UX/accessibility/privacy/compatibility problem to fix before broad deployment. |
| Low | Defense-in-depth, edge-case, packaging, or documentation issue with limited immediate impact. |

## 4. Findings summary

| ID | Severity | Area | Status | Finding |
|---|---|---|---|---|
| SEC-001R | High | ReDoS / availability | **Open** | The regex gate accepts polynomial backtracking patterns (`.*.*.*.*.*b`); the browser freezes on form and survey input. |
| PRE-001 | Blocker | Submission | Open (disclosed) | No public SemVer release/tag exists (`git tag` is empty). |
| PRE-002 | Blocker | Security assurance | Open (disclosed) | REDCap's current security scan has not been run/evidenced. |
| PRE-003 | Blocker | Integration assurance | Open (disclosed) | No completed live REDCap/browser/screen-reader matrix; a prior import test produced no audit log. |
| LOW-01 | Low | Test coverage | Open | The ReDoS regression corpus has zero polynomial-overlap cases, so SEC-001R is untested and a fix would be unguarded. |
| LOW-02 | Low | Docs | Open | `config.json` description (118 words) and INSTALL/CHANGELOG imply catastrophic patterns are rejected at save; the polynomial class is not. |
| LOW-03 | Low | Consistency | Open | The JS fallback config default `strip` (`-/ _|`) omits the backslash present in the PHP default and the config help text. |
| LOW-04 | Low | Log volume | Open (partly disclosed) | Detection logs are still un-deduplicated across saves (SEC-005 was only partially closed); unknown-instrument import paths can re-log the same value. |

## 5. The one substantive finding

### SEC-001R — Polynomial ReDoS passes the gate and freezes the browser

**Severity:** High
**Files:** `js/engine.js` (`QRID_riskyPattern`, ~608–634; single-field `verdict`/`check`, ~1006–1090; pooled `parse`, ~1291–1380), `php/CheckCharacter.php` (`riskyPattern`, 209–234), `php/AnnotationRules.php` (`checkFragment`, 238–244), `UniversalValidator.php` (`validateSettings`, 535–557), `tests/risky_patterns.json`

**What the fix did and did not do.** The `0.5.0` rewrite of `riskyPattern`
correctly closed the exponential class. Confirmed rejected at save time by both
channels:

```
(a+)+          -> REJECTED (backtracking)
(a|aa)+        -> REJECTED (backtracking)
```

But the gate looks only for (a) adjacent quantifiers and (b) a *quantified group*
followed by a quantifier. A pattern with two or more unbounded quantifiers on
overlapping *ungrouped* atoms matches neither shape and passes:

```
.*.*.*.*.*b                       -> ACCEPTED (passes gate)
[0-9]*[0-9]*[0-9]*[0-9]*[0-9]*b   -> ACCEPTED (passes gate)
```

This is not a surprise to the gate's authors — `tests/risky_patterns.json` states
"polynomial shapes like A*A* pass it, which is why the server keeps the
match-time PCRE-error guard as a backstop and why input-length caps stay in
place." The problem is that **neither of those two compensating controls protects
the browser**, and the browser is where a user's input runs the regex.

**Measured client cost.** Reproduced on Node 24.14 (the V8/Irregexp engine that
ships in Chrome and Edge), matching the anchored pattern the engine builds
(`^(?:…)$`) against a non-matching input:

```
single-field path, input length 200 (field cap is 512):
  .*.*.*b       -> 14 ms
  .*.*.*.*b     -> 479 ms
  .*.*.*.*.*b   -> 19,912 ms
  .*.*.*.*.*.*b -> did not finish within 20 s (killed)
  [0-9]*[0-9]*[0-9]*[0-9]*[0-9]*b -> did not finish within 20 s

pooled path, input length 120 (bounded 60-char windows):
  .*.*.*.*.*b   -> 1,035 ms
```

At the field's 512-character cap the single-field match does not complete — the
tab is frozen until the browser kills it. The match runs synchronously on
`change`, on `blur`, and on the initial `check()` when the field is first bound,
so the freeze does not even require typing: opening a record whose field already
holds a long non-matching value triggers it. The 150 ms debounce only defers the
keystroke path; it does not bound the work.

**Server behavior (why it was missed, and a secondary effect).** The same
patterns on the PHP path return a clean verdict in well under a millisecond,
because PCRE2 auto-possessifies `.*.*` and never enters the backtracking blow-up:

```
.*.*.*.*.*b, value length 200 -> reason=format, 0.0 ms, preg_last_error=0
```

So the server is safe — but it also disagrees with the client, which
freezes/blocks rather than returning "format mismatch." The audit and the browser
can reach different conclusions for the same value.

**Reachability.** The pattern is short (11 characters) and passes both
configuration channels — the settings dialog (`validateSettings`) and
`@UVALIDATE` annotations. Once configured it is a persistent landmine: every
data-entry user and every public survey respondent who lands on that field is
exposed. A public survey makes it an unauthenticated client-side denial of
service.

**Why the existing caps do not save it.** The 512-character single-field cap
bounds only *input length*, not *match time*; for a degree-5 polynomial regex,
512 characters is already unbounded in practice. JavaScript cannot interrupt a
running regex, so there is no runtime backstop on the client — the only defense
is refusing the pattern at configuration time.

**Required remediation (in order of robustness):**

1. **Preferred:** compile and match user patterns on the client with a
   linear-time engine (RE2 / `re2js`) instead of the native `RegExp`, so match
   time is bounded regardless of pattern shape. This also erases the
   client/server divergence, since PCRE2 is already effectively linear here.
2. **If staying with native `RegExp`:** extend the config-time gate to reject two
   or more unbounded quantifiers (`*`, `+`, `{n,}`) applied to *overlapping*
   character classes (intersection non-empty) without a mandatory separating
   literal — this catches `.*.*`, `[0-9]*[0-9]*`, `[A-Z0-9]*[A-Z0-9]*` while
   still admitting the genuinely-linear `[A-Z]+[0-9]+`. Keep the JS and PHP twins
   byte-identical, as today.
3. **Regardless:** lower the client input cap far enough that even a missed
   polynomial pattern cannot exceed an agreed time budget on a low-end device,
   and stop describing the caps as a ReDoS bound in code comments and docs until
   the bound is demonstrated.
4. Add the polynomial-overlap cases to `tests/risky_patterns.json` (see LOW-01).

## 6. Standing submission blockers (unchanged, already disclosed)

These are not new and are honestly tracked at the top of `docs/TESTING.md`. They
remain genuine gates on an actual Repo submission and are restated so the release
owner does not lose them:

- **PRE-001 — No public SemVer release/tag.** `git tag --list` is empty at
  `d76bc1a`. The submission process needs a public repository and an immutable
  `vX.Y.Z` tag identifying the exact payload. The CI `package` job proves the
  archive shape; a person must still publish the tag and confirm the anonymous
  download.
- **PRE-002 — REDCap security scan not evidenced.** Must be run on the latest
  REDCap (Control Center → External Modules → Module Security Scanning), with the
  output, version, and date retained. Local static review is not a substitute.
- **PRE-003 — Live integration/browser/accessibility matrix incomplete.** The
  checklist in `docs/TESTING.md` is unchecked, and it records a real REDCap
  17.0.6 Data Import Tool run that produced **no** module audit log. The
  form/survey/longitudinal/repeating/API/import behavior, the save-button guard,
  and the screen-reader announcements are all still unverified on a live
  instance.

## 7. Low-severity findings

- **LOW-01 — ReDoS corpus has no polynomial case.** `tests/risky_patterns.json`
  covers exponential and bounded-nested shapes but contains zero `.*.*`-style
  entries. The gap in SEC-001R is therefore untested in both runtimes, and any
  fix would ship without a regression guard. Add the polynomial-overlap patterns
  to the `risky` list (and confirm both `riskyPattern` twins reject them) as part
  of the SEC-001R fix.
- **LOW-02 — Docs imply full catastrophic-pattern rejection.** `docs/INSTALL.md`
  ("catastrophic shapes such as `(a+)+` and `(a|aa)+` are rejected when you save
  the settings") and the `0.5.0` changelog read as if catastrophic patterns in
  general are refused. Until SEC-001R is fixed, that overstates the protection;
  align the wording with what the gate actually catches. Separately, the
  `config.json` description is 118 words — accurate and honest, but long for the
  repository listing card; consider leading with the one-line capability and
  moving the caveats to the README.
- **LOW-03 — Fallback `strip` default is inconsistent.** The JS fallback config
  in `QRID_readConfig` uses `strip: "-/ _|"` while the PHP `defaults()` and the
  `config.json` help text use `-/ _|\` (with a backslash). Only the test-harness
  fallback path uses the JS literal — production always receives the PHP-built
  config — so this is cosmetic, but it is a latent trap for a future maintainer
  and for any test that exercises the fallback. Make the three agree.
- **LOW-04 — Detection logs still not deduplicated across saves.** SEC-005 was
  only partially closed: instrument scoping removed the main duplicate source for
  form saves, but there is still no cross-save dedup, and the conservative
  "unknown instrument → audit everything" path (used for some import/API
  contexts) can re-log the same invalid value on repeated imports. This is
  disclosed in the `0.5.0` changelog as future work; it is noted here only so it
  is not forgotten before broad deployment. Framework log throttling or a
  per-record/field/value-hash state check would close it.

## 8. Controls re-verified as genuinely fixed

Each of the following was confirmed against the running code, not the changelog:

- **SEC-002 (PID threading).** Every settings/dictionary read on the audit path
  carries the hook's explicit `$project_id`; `tests/hook_php.php` proves it with
  mocks that refuse to return settings without a resolvable pid (56 checks pass).
- **SEC-003 (audit-error minimization).** `logMode` is resolved before the `try`,
  and the exception path honors it: keyed record hash in `none`, no record
  identifier in `off`, exception text only under the `debug-log` opt-in. Verified
  on the exception path in the hook suite.
- **SEC-004 (keyed HMAC).** Identifiers are project-scoped HMAC-SHA-256 with a
  module-held secret; the key is generated once via `random_bytes(32)` and
  persisted. Verified stable across saves.
- **COR-001 (event scoping).** With an event id supplied, only that event's node
  is read; the whole-record fallback runs only when no event id is given.
  Verified with cross-event data.
- **COR-002 (atomic settings + isolation).** `validateSettings()` rejects invalid
  rules at save time via the one shared `checkFragment` validator; each rule is
  audited in isolation; unevaluable rules log `uvalidate-unconfigurable` instead
  of passing silently. Verified, including a PCRE-backtrack-limit case.
- **COR-003 (non-text fields).** The dialog channel now rejects non-Text/Notes
  fields with a naming config error, matching the annotation channel. Verified.
- **COR-005 (prototype-safe registries).** `Object.create(null)` for field-keyed
  maps; a field named `constructor` no longer corrupts duplicate detection.
- **PER-001 (instrument scoping).** Unrelated-instrument saves read no data and
  log nothing; unknown instruments fall back conservatively. Verified.
- **PER-002 (pooled work budget).** The worst-case *legal* pooled config (32
  lengths spanning 33–64) parses in ~98 ms at its scan cap; huge ranges no longer
  allocate. Measured.
- **A11Y-001 (ARIA).** Live-region status messages, `aria-describedby`,
  `aria-invalid`, label-named block dialogs, readonly exemption, survey muting —
  all present and locked by `tests/a11y_dom_js.cjs`.
- **A11Y-002 (contrast).** Measured: junk chip `#8a5500` on `#fbf6e8` = 5.75:1;
  every other message color pair (green 4.68:1, red 4.90:1, blue 6.69:1) also
  clears the 4.5:1 AA-normal threshold.
- **PRE-004 / DOC-001 (honest claims).** The `config.json` description, class
  header, README, and INSTALL now describe a best-effort post-save audit
  "wherever REDCap fires the hook," with the live-instance verification step
  linked; the "server always logs" overstatement is gone.
- **CI-001 / PKG-001.** Workflow token is `contents: read`; actions are pinned to
  commit SHAs; a `package` job builds the release archive and checks required
  files, excluded dev trees (`export-ignore`), JSON validity, and namespace
  match.
- **Framework compatibility.** `framework-version: 14` with
  `redcap-version-min: 13.7.0` is correct: per the Vanderbilt framework
  versioning table, framework 14 first shipped in REDCap standard 13.7.0 (LTS
  13.7.3).
- **Stored/DOM XSS.** Config travels as inert JSON in a
  `<script type="application/json">` node with `JSON_HEX_TAG|HEX_AMP|HEX_APOS|HEX_QUOT`
  and no unescaped slashes; all config-derived text is HTML-escaped before every
  `innerHTML` sink. No breakout path found.

## 9. Test execution evidence

```
Node 24.14.0
PHP 7.4.33 and 8.3.32 (portable, mbstring + ctype)

php -l  (both PHP versions)  CheckCharacter / AnnotationRules / UniversalValidator   PASS
php tests/parity_php.php      643 rows, 0 mismatches           PASS (7.4 and 8.3)
php tests/pooled_php.php      8 cases, 0 mismatches            PASS (7.4 and 8.3)
php tests/risky_php.php       42 checks, 0 mismatches          PASS (7.4 and 8.3)
php tests/annotation_php.php  52 checks, 0 failures            PASS (7.4 and 8.3)
php tests/hook_php.php        56 checks, 0 failures            PASS (7.4 and 8.3)
node tests/parity_js.cjs      643 rows, 0 mismatches           PASS
node tests/pooled_js.cjs      8 cases, 0 mismatches            PASS
node tests/risky_js.cjs       37 patterns, 0 mismatches        PASS
node tests/config_notice_js.cjs   7 checks, 0 failures         PASS
node tests/dispatch_notice_js.cjs 6 checks, 0 failures         PASS
node tests/a11y_dom_js.cjs    19 checks, 0 failures            PASS
```

### Independent adversarial probes

```
Save-time gate (checkFragment / validateSettings / @UVALIDATE):
  (a+)+                             -> REJECTED
  (a|aa)+                           -> REJECTED
  .*.*.*.*.*b                       -> ACCEPTED   (FAIL)
  [0-9]*[0-9]*[0-9]*[0-9]*[0-9]*b   -> ACCEPTED   (FAIL)

Client match time, .*.*.*.*.*b, input length 200 (cap is 512):
  single-field  -> 19,912 ms                     (FAIL: browser freeze)
  pooled(120)   -> 1,035 ms                       (FAIL)

Server match time, same patterns, value length 200:
  reason=format, 0.0 ms, preg_last_error=0        (safe — but diverges from client)

WCAG contrast (recomputed): junk 5.75:1, green 4.68:1, red 4.90:1, blue 6.69:1  PASS
Pooled worst-case legal config (32 lengths, 33..64), input at scan cap: ~98 ms  PASS
```

## 10. Minimum acceptance criteria before submission

- [ ] SEC-001R closed: no configurable pattern can exceed an agreed client match-time
      budget at allowed input/config limits, proven by an adversarial timeout corpus.
- [ ] Polynomial-overlap patterns added to `tests/risky_patterns.json`; both
      `riskyPattern` twins reject them; CI green.
- [ ] Docs/comments no longer describe the input caps or the gate as a ReDoS bound.
- [ ] PRE-001: public repository + annotated SemVer tag, anonymously downloadable.
- [ ] PRE-002: current REDCap security scan passed and archived.
- [ ] PRE-003: live REDCap/browser/screen-reader matrix executed and signed,
      including the API/import audit-coverage confirmation.
- [ ] Final review performed against the immutable release tag, not the branch.

## 11. Final opinion

The remediation between the first review and `d76bc1a` is substantial and, on
measurement, honest: 21 of 22 code findings are genuinely closed, the engines
agree across four runtimes, the privacy and audit posture is careful, and the
public claims are now accurate. The module is close.

The one remaining code defect is real and should be treated as a release
requirement, not backlog: the regex gate is described — in code, tests, and docs
— as bounding catastrophic backtracking, but it bounds only the exponential half.
An 11-character pattern that passes every configuration channel freezes the
browser on ordinary form and survey input. Fixing it (ideally with a linear-time
client engine, at minimum with an overlap-aware config-time gate plus regression
tests) closes the last substantive gap; the remaining work is the standing
release-gate people-work already tracked in `docs/TESTING.md`.

---

## Remediation note (2026-07-13, `0.5.1`)

SEC-001R and its associated low findings were closed in `0.5.1`. The chosen
remediation was option 2 (an overlap-aware config-time gate) plus option 4
(regression corpus), not option 1 (a bundled linear-time engine): the module
ships vendored plain JS with no build step, and adding a bundled RE2/`re2js`
dependency was judged a poorer fit than a self-contained gate that keeps the
JS/PHP twins behavior-identical. Specifically:

- **SEC-001R (High) — closed.** `QRID_riskyPattern` / `CheckCharacter::riskyPattern`
  gained a second stage (`QRID_polyOverlap` / `polynomialOverlap`) that tokenizes
  the group-collapsed pattern and refuses two or more unbounded quantifiers over
  overlapping character classes with no mandatory separator between them. The
  client never compiles a flagged pattern, so it can never run. Genuinely-linear
  patterns (`[A-Z]+[0-9]+`, `.*x.*`, `[A-Z]+-[A-Z]+`) still pass. Verified on
  Node 24.14 and PHP 7.4.33 / 8.3.32 with full JS/PHP parity.
- **LOW-01 — closed.** `tests/risky_patterns.json` now carries the
  polynomial-overlap cases in `risky` and the linear precision-guarantee cases in
  `safe`; both runtimes assert agreement.
- **LOW-02 — addressed.** Config-error messages, `config.json`, `docs/INSTALL.md`,
  `docs/TESTING.md`, `js/README.md`, and `tests/README.md` now describe both gate
  stages and no longer imply the input caps bound regex match time.
- **LOW-03 — closed.** The JS fallback `strip` default now matches the PHP default
  and config help (`-/ _|\`).
- **LOW-04 — unchanged.** Cross-save log deduplication remains disclosed future
  work.

The three standing blockers (PRE-001/002/003) remain people-work, tracked at the
top of `docs/TESTING.md`. A final review should run against the immutable release
tag.
