# js/engine.js — provenance and re-vendoring

`engine.js` is vendored from the `qrcode_generation` repository:

    integrations/redcap/redcap_combined_id_check.js

## Intentional deviations from upstream

The **verified engine core** (the `QRCheck` IIFE — every algorithm, ISO/IEC 7064
factory, and the check-character registry) is byte-identical to upstream and is
proven so by `tests/parity_js.cjs`. The changes are confined to the config source
and the field-facing UI layer:

1. **Config source.** Upstream declares a hardcoded `QRID_COMBINED_CONFIG` literal
   (edited by hand for the JavaScript Injector). Here the engine parses the inert
   `<script type="application/json" id="inspire-validator-config">` node that
   `UniversalValidator.php` emits (no executable config script, no config
   global); `window.INSPIRE_VALIDATOR_CONFIG` remains as a fallback for the Node
   test suites. The config carries a `context` flag (`form`/`survey`) and a
   `configShape` number. The engine declares the same number
   (`QRID_CONFIG_SHAPE`, twin of `UniversalValidator::CONFIG_SHAPE`) and, on a
   page whose config states another, checks nothing and shows a notice to
   reload, so a cached engine never reads a config it was not written for.
2. **Security and performance hardening of the UI layer** (everything below the
   engine core):
   - All config-derived text (regex class bodies, config-error messages) is
     HTML-escaped before it reaches `innerHTML` — closes a DOM-XSS path (UV-002).
   - The catastrophic-backtracking gate rejects, at config time, both the
     exponential shapes (nested quantifiers and repeated ambiguous groups —
     `(a+)+`, `(a|aa)+`, `(a?)+`, nested variants — by collapsing inner groups
     layer by layer) and the polynomial shapes (two or more unbounded quantifiers
     over overlapping classes with no mandatory separator — `.*.*`,
     `[0-9]*[0-9]*`, `[A-Z]+[A-Z0-9]+`), plus a 512-code-point pattern cap. A
     flagged pattern is never compiled, so it can never run; the genuinely-linear
     `[A-Z]+[0-9]+` and `.*x.*` still pass. Byte-identical-behavior twin of
     `CheckCharacter::riskyPattern` (php), locked by `tests/risky_*`
     (SEC-001/SEC-001R/UV-006).
   - Per-field input caps, hard rule caps (ID length ≤ 64, ≤ 32 candidate
     lengths, keepChars ≤ 64 printable ASCII, expectedIds ≤ 9999), a per-rule
     pooled work budget that shrinks the scan cap for expensive configs, and
     debounced keystroke validation (PER-002).
   - The `MutationObserver` is disconnected when a field never appears, instead of
     observing the whole page for the life of the tab (UV-007). Every rule
     shares one late-render timer and one observer (`QRID_lateAttach`), and the
     poll for values written without an event stops after 10 s in which nothing
     changed and starts again on the next user event (PRF-6).
3. **Accessibility layer.** Validation messages render in polite live regions
   (`role=status`, `aria-live=polite`, stable ids) wired to their inputs with
   `aria-describedby`; inputs carry `aria-invalid`; save-block dialogs name
   fields by their visible label and focus the field; read-only/disabled fields
   never arm the save blocker; chip/message colors meet WCAG 2.2 AA contrast
   with non-color marks (A11Y-001/002, UX-003). Config errors attach to their
   field when it is on the page and fall back to one page-level notice;
   surveys show a generic line instead of technical detail (UX-001).
4. **Single public namespace.** All module-authored code lives in one IIFE and
   publishes exactly ONE global, `window.INSPIREUniversalValidator` (config,
   engine, riskyPattern, configErrorNotice, whenLogic, factories, validators,
   guard, lastPooled). The legacy upstream globals (`QRCheck`, `QRIDSingleInit`,
   `QRIDPooledInit`, `QRIDValidators`, `QRIDMulti`, `QRID_COMBINED_CONFIG`,
   `__QRIDGuard`) are NOT published — the core's own `QRCheck` global is
   captured as `.engine` and deleted after load. This module never shipped with
   those globals, so there is no consumer to break.
5. **"when" conditions and branched validation (REDCap-only module features).**
   The `QRID_when*` block (parser/evaluator twins of `php/Logic.php`, locked by
   `tests/when_fixture.json` via `tests/when_js.cjs`/`tests/when_php.php`), the
   `QRID_WHEN` runtime gate (live DOM refs by REDCap name conventions; a
   comparison the browser cannot read arrives PRE-FOLDED from the server as a
   `["const",bool]` node — `Logic::fold()` — so no record value is ever in the
   page; `tests/when_dom_js.cjs`), and the branch plumbing
   (`QRID_buildVariants`/`QRID_activeVariants`/`QRID_renderConflict`; both
   factories build their mode-resolution closure per VARIANT via an internal
   `makeVariant(cfg)`, so a per-field branch rule from `php/Branching.php`
   selects the active branch at check time — `tests/branch_dom_js.cjs`) live in
   the module-authored UI layer. Nothing of this exists upstream — it rides the
   UI module IIFE across re-vendors, like the rest of the module-authored
   layer.

These deviations touch presentation and safety only, never the check-character
math, so parity with the Python source is unaffected. Upstreaming them is
encouraged (except the REDCap-only "when" gate, which has no upstream
counterpart to receive it).

## The cross-repo fixture contract

The check-character logic lives in two repos now (Python in `qrcode_generation`,
PHP + JS here). They are kept in step by a fixture, not by shared code:

- `tests/check_fixture.json` is generated by the Python source of truth
  (`playground/gen_check_fixture.py` in `qrcode_generation`).
- `tests/parity_js.cjs` and `tests/parity_php.php` recompute every fixture row and
  fail on any mismatch, in CI on every push.

So a change to the engine can never silently drift this module: the fixture is the
contract, and CI enforces it.

## To re-vendor after an upstream engine change

1. In `qrcode_generation`, regenerate the fixture:
   `python playground/gen_check_fixture.py`
2. Copy the refreshed `playground/check_fixture.json` to `tests/check_fixture.json`
   here.
3. Copy `integrations/redcap/redcap_combined_id_check.js` to `js/engine.js`, then
   re-apply the deviations listed above: ONLY the engine core (the `QRCheck`
   IIFE) comes from upstream — everything below it (the UI module IIFE with the
   config reader, hardening, accessibility layer, factories, dispatcher, and
   namespace) is module-authored. Diff against the previous `engine.js` to port
   the core forward; the UI module should apply cleanly.
4. Run `node tests/parity_js.cjs`, `php tests/parity_php.php`, and both pooled
   tests — all must be green before committing. If the pooled parser changed,
   regenerate `tests/pooled_fixture.json` with `node tests/gen_pooled_fixture.cjs`.
5. Rebuild the minified copy with `node tools/build_engine_min.cjs` and run
   `node tests/engine_min_js.cjs`.

## Files derived from the engine and the tables

- `engine.min.js` is `engine.js` minified by a pinned terser
  (`tools/build_engine_min.cjs`). Its first line names the SHA-256 and the
  CRC-32 of the source it was built from. A form loads it only while that
  CRC-32 is the one of the `engine.js` beside it, and loads `engine.js`
  otherwise, or when the project's debug setting is on. Every edit of
  `engine.js` needs a rebuild: CI checks the file is exactly what terser makes
  of the source, and `tests/engine_min_js.cjs` runs every browser suite on it.
- `growth/<hash>.js` holds the rows of one bundled growth table
  (`tools/build_growth_rows.php`), named by the first 16 hex digits of the
  table's SHA-256. A page that may read any row of a bundled table loads this
  script before the engine, which the browser caches, instead of carrying the
  rows inline. Rebuild after adding or changing a bundled table; CI checks each
  script against its table.
