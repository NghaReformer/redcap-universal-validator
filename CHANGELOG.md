# Changelog

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
