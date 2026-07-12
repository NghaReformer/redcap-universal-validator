# Changelog

## 0.5.0 — predeployment-review hardening

Addresses `reports/predeployment-review-2026-07-12.md` (4 blockers, 7 high, 11
medium, 4 low). Every code-addressable finding is fixed here; the remaining
release-gate items (public SemVer release, REDCap security scan, live
REDCap/browser/screen-reader matrix) are people-work, tracked as explicit
blockers at the top of `docs/TESTING.md`.

Security and privacy
- **SEC-001:** the ReDoS gate now catches repeated alternation/optional groups
  (`(a|aa)+`, `(a?)+`, `((a)|(aa))+`, non-capturing/lookahead variants) by
  collapsing inner groups layer by layer, extends the adjacent-quantifier rule
  to `*{`/`+{`, and caps the pattern source at 512 code points. Both runtimes
  stay byte-identical (14,399-pattern differential: zero divergence). The
  pre-fix gate passed `(a|aa)+`, which froze a browser tab on 43 characters.
- **SEC-002:** every settings read on the audit path now carries the hook's
  explicit `$project_id` (`getSubSettings('rules', $pid)`,
  `getProjectSetting('log-values', $pid)`); the hook-test mocks now REFUSE to
  return settings without a resolvable pid, so a regression fails the suite.
- **SEC-003:** the audit-error path honors the project's log-privacy mode:
  keyed record hash in `none`, no record identifier at all in `off`, and the
  exception MESSAGE (which can quote data) only with the new `debug-log`
  project setting on. Previously a raw record id leaked in every mode.
- **SEC-004:** logged identifiers are keyed, project-scoped HMAC-SHA-256
  (module-held secret in a system setting) instead of plain SHA-256 — no
  offline enumeration, no cross-project linking; settings copy now says
  pseudonymization, not anonymity. Log keys renamed `value_hmac`/`record_hmac`.
- **SEC-005 (partial):** instrument scoping (below) removes the main duplicate
  source — unrelated-instrument saves re-logging an old invalid value. Full
  cross-save deduplication remains future work.

Correctness
- **COR-001:** with an event id supplied, the audit reads ONLY that event's
  node — the whole-record fallback that could validate (and log) another
  event's value now runs only when the hook supplies no event id at all.
- **COR-002:** `validateSettings()` rejects invalid rules at save time with a
  message naming the rule; one shared validator (`AnnotationRules::checkFragment`)
  serves the dialog, annotations, and the save gate; each rule is audited in
  isolation (one failure cannot abort the rest); rules the server cannot
  evaluate log `uvalidate-unconfigurable` instead of passing silently.
- **COR-003:** non-Text/Notes fields are rejected by the dialog channel too
  (config error naming the field and its type), matching the annotation channel.
- **COR-004:** the client/server regex parity subset is now explicit: patterns,
  strip, and keepChars must be printable ASCII (enforced at save time), and the
  server format audit fails open on non-ASCII values instead of risking a
  verdict the browser never showed. Python-only `(?P<...)` is rejected with
  `\A`/`\Z`; patterns must compile in PCRE at save time.
- **COR-005:** field-keyed registries use prototype-free objects, so a field
  named `constructor` can no longer corrupt duplicate detection.
- The single-field factory no longer lets an absent `source`/`strip` override
  scheme defaults with `undefined` (crashed on minimal configs).

Performance
- **PER-001:** the audit is scoped to the saved instrument (conservatively
  auditing everything when the instrument or dictionary is unknown, e.g. some
  import/API contexts); fields claimed by two rules are skipped exactly like
  the client; an unrelated-instrument save now reads no data at all.
- **PER-002:** hard rule caps (ID length ≤ 64, ≤ 32 candidate lengths,
  keepChars ≤ 64, expectedIds ≤ 9999) plus a per-rule pooled work budget that
  shrinks the scan cap for expensive configs (identical formula in both
  runtimes; over-cap input gets "too long to scan", never a slow parse), and
  keystroke validation is debounced (150 ms; change/blur immediate). The
  review's 100–199-length config (9.25 s per parse) is now a save-time
  rejection; the worst still-legal config parses in ~100 ms at its cap.
  Huge min/max values no longer allocate a giant candidate array (browser or
  server); the pooled input cap dropped 8192 → 4096.

Accessibility and UX
- **A11Y-001:** messages are polite live regions (`role=status`,
  `aria-live=polite`, stable ids) wired via `aria-describedby`; inputs carry
  `aria-invalid`; block dialogs name fields by their visible label and focus
  the field. New `tests/a11y_dom_js.cjs` locks the DOM contract; live
  screen-reader verification added to `docs/TESTING.md`.
- **A11Y-002:** pooled junk-chip text darkened `#b26a00` → `#8a5500` on
  `#fbf6e8` (3.93:1 → 5.75:1, WCAG 2.2 AA for normal text).
- **UX-001:** configuration errors attach to their field when it is on the
  page, fall back to one page-level notice otherwise, and surveys show a
  generic "checking unavailable" line instead of technical detail (staff still
  see everything on data-entry forms, in the dialog gate, and in the log).
- **UX-002:** presence checks replace `empty()` (a pattern of `"0"` counts),
  the pattern box states the uppercase normalization and the ASCII subset, and
  save-time validation means designers see errors in the dialog, not typists.
- **UX-003:** enforcement copy now says BROWSER form/survey save everywhere;
  read-only/disabled fields never arm the save blocker (no more traps on
  `@READONLY` fields); the advisory dialog no longer double-prompts when a
  save-button click is followed by its own submit.
- **CMP-001 (partial):** everything below the vendored core now lives in one
  IIFE publishing exactly one global (`window.INSPIREUniversalValidator`); the
  legacy `QRCheck`/`QRID*`/`__QRIDGuard` globals are gone, and the config
  travels only through the inert JSON node (no config global, no inline config
  script). JSMO/`tt()` translation integration remains future work and is
  disclosed as a known limitation (English-only messages).

Claims, docs, assurance
- **PRE-004/DOC-001:** the Repo-facing description, class header, README,
  INSTALL, and settings copy no longer claim API/import audit coverage — they
  describe a best-effort post-save audit wherever REDCap fires the hook, with
  the live-instance verification step linked; "the server always logs" removed
  (off mode exists); stale test counts replaced by the actual suite list.
- **PRE-001/002/003:** recorded as explicit release-gate blockers in
  `docs/TESTING.md` (public SemVer release + anonymous download, current REDCap
  security scan, executed live matrix incl. browsers and a screen reader).
- **TST-001:** hook suite grown 14 → 56 checks (strict-pid mocks, privacy modes
  on success and exception paths, event/instrument scoping, repeats,
  duplicates, isolation, HMAC, `validateSettings`); annotation suite 30 → 52
  (shared-validator coverage); risky list 22 → 34 patterns + length-cap and
  measured-time checks; new 19-check DOM/a11y suite; PHP matrix 7.4/8.1/8.3 in
  CI so the declared floor is tested.
- **CI-001/PKG-001:** workflow token limited to `contents: read`; actions
  pinned to commit SHAs; new `package` job builds `universal_validator_vX.Y.Z.zip`
  from `git archive` and verifies required files, excluded dev trees
  (`.github`, `reports` via `export-ignore`), JSON validity, and namespace
  match.

## 0.4.0 — bulk configuration + repositioning

Driven by first live-REDCap field testing: configuring many fields one picker
click at a time doesn't scale, and the module under-sold what it validates.

- **`@UVALIDATE` field annotations** — a second configuration channel. Tag fields
  in the Online Designer's Action Tags box or the `field_annotation` column of
  the data dictionary CSV (bulk setup = one spreadsheet column + one upload).
  Forms: bare tag (default check), `@UVALIDATE=<algorithm>`, or full-rule JSON
  (`type`, `algorithm`, `source`, `pattern`, `strip`, `keepChars`, `idLengths`,
  `idMinLen`, `idMaxLen`, `expectedIds`, `blockSave`, `note`). Malformed tags,
  unknown keys, catastrophic patterns, and tags on non-text fields all surface as
  per-field configuration errors, never silent no-ops. Identically-tagged fields
  are grouped into one rule. Parsing lives in the new `php/AnnotationRules.php`
  (pure, no REDCap dependency) with 30 unit checks in `tests/annotation_php.php`,
  wired into CI.
- **Fast entry** — each dialog rule gets a text box for comma/space-separated
  field names, merged with the field pickers. Names are checked against the data
  dictionary; misspellings show a configuration error naming the bad field.
- **Rule labels** — an optional per-rule note so a project with many rules stays
  readable ("Specimen IDs", "Legacy screening codes").
- **Repositioning** — renamed to *Universal Field Validator — IDs, codes &
  patterns*: check-character IDs remain the flagship, and the regex side (any
  structured value, no administrator-added validation types needed) is now
  first-class in the name, description, README, and dialog labels.
- Configure-dialog copy rewritten around "one rule, many fields" (the field
  picker's + button, fast entry, annotations).

## 0.3.0 — REDCap-standards conformance

Addresses `reports/final-adversarial-review-2026-07-07.md` (no runtime bugs; five
conformance/maintainability findings, all closed).

- **Framework:** `framework-version` 9 → 14 (the version REDCap 13.7.0, our
  declared floor, supports) and the pre-framework-12 hook `permissions` block is
  removed, per the official docs.
- **Settings UI:** `branchingLogic` removed from the repeatable `sub_settings`
  (the official docs warn of known issues with that combination); the pooled-only
  settings are labeled "Pooled only:" instead.
- **Privacy:** the `none` log mode is now a true minimal-identifier mode — it
  hashes the record ID as well as omitting the value (record IDs can themselves be
  identifying at some sites). `hashed`/`raw` keep the raw record ID so staff can
  fix the record; setting text and docs now state exactly what each mode stores.
- **JavaScript:** one public namespace, `window.INSPIREUniversalValidator`
  (config, engine, factories, validators, guard), per REDCap JS guidance; the
  individual upstream globals remain as deprecated aliases for the
  JavaScript-Injector contract.
- **Docs:** the stale `engine.js` provenance header now points to `js/README.md`'s
  authoritative deviation list (a future re-vendor must not silently drop the
  hardening); README/tests README count the current six-test CI matrix; new
  `docs/TESTING.md` manual REDCap integration checklist (classic, longitudinal,
  repeating, survey, API/import, log modes, security spot-checks).

## 0.2.0 — adversarial-review hardening

Addresses the findings in `reports/adversarial-review-2026-07-07.md`.

Security
- **UV-001:** the client config is embedded as inert JSON in a
  `<script type="application/json">` block and parsed with `JSON.parse`, hex-escaped
  (`JSON_HEX_TAG|HEX_AMP|HEX_APOS|HEX_QUOT`, no `JSON_UNESCAPED_SLASHES`). A project
  setting can no longer break out of the inline script (stored XSS).
- **UV-002:** all config-derived text (regex class bodies, config-error messages)
  is HTML-escaped before it reaches `innerHTML` in the client (DOM XSS).
- **UV-006:** `idPattern` is rejected at config time if it has nested/adjacent
  unbounded quantifiers, and per-field input-length caps bound the work (ReDoS).

Server-side coverage
- **UV-003:** `redcap_save_record` now mirrors the full client rule set — single
  and pooled fields, check character, format pattern, and regex-only.
- **UV-004:** server reads the exact saved event and repeat instance instead of the
  first matching value (fixes longitudinal / repeating-instrument audits).
- **UV-005:** invalid-ID logging no longer stores raw identifiers by default; the
  new **log-values** setting chooses hash (default) / none / raw / off.
- **UV-007:** server reads all fields in one `getData` call; the client
  `MutationObserver` is disconnected when a field never appears.

Configuration & tests
- **UV-008:** exposes exact ID length(s), min/max, and keep-chars for pooled rules;
  `expected-count` and lengths are strictly validated (a bad value is a visible
  config error, not silent coercion). Adds a `compatibility` block.
- **UV-009:** PHP normalization is multibyte-safe (`mb_strtoupper`, code-point
  splitting, `\p{Nd}` source extraction) so client and server agree on Unicode.
- **UV-010:** parity tests now cover `normalize` and `scheme_ops` (643 rows), a new
  `pooled_fixture.json` freezes the pooled parser across both runtimes, and CI adds
  `php -l` and a fixture-staleness check.

Follow-up (fix-validation review, `reports/fix-validation-review-2026-07-07.md`)
- **P2:** the server now mirrors the browser's catastrophic-pattern gate.
  `CheckCharacter::riskyPattern()` (the byte-identical PHP twin of
  `QRID_riskyPattern`) is checked in `getRules()`, so a risky pattern is a config
  error on both sides; `matchesPattern()` and the pooled `patTest()` treat a PCRE
  engine failure (backtrack/recursion limit) as "not a real (non-)match" — the
  pooled path bails to *unconfigurable* rather than logging a false invalid-ID;
  and `pooledState()` rejects risky patterns. An adversarial review of that fix
  then found two gaps, also closed here: the heuristic now catches **bounded**
  nested quantifiers (`([0-9]{1,20}){1,20}`), not only `+`/`*`, and both runtimes
  use an explicit ASCII whitespace class instead of `\s` (JS `\s` matches Unicode
  whitespace, PCRE `\s` does not — a silent parity gap). New `risky_js.cjs` /
  `risky_php.php` lock the two heuristics to one shared list; a differential over
  thousands of generated patterns (including Unicode whitespace and bounded
  quantifiers) shows zero divergence.
- **P3:** added `.gitattributes` (`* text=auto eol=lf`) so checkouts stop showing
  phantom CRLF churn.

## 0.1.0 — scaffold

Initial standalone REDCap external module extracted from the JavaScript-Injector
script.

- Config-driven client validation on data-entry forms and surveys (no code
  pasting, no JavaScript Injector dependency).
- Repeatable per-rule settings: field type (single/pooled), fields, method,
  payload source, format pattern, separators, expected pool size, and per-rule
  enforcement (informational / advisory / compulsory).
- `js/engine.js` vendored from the `qrcode_generation` combined validator, config
  now injected by the module.
- `php/CheckCharacter.php`: PHP port of all 11 check-character algorithms for the
  server-side `redcap_save_record` guard.
- Parity harness: `tests/parity_js.cjs` (JS, 420/420 green) and
  `tests/parity_php.php` (PHP) against the shared Python-generated fixture; CI in
  `.github/workflows/parity.yml`.
