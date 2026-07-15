# Universal Regex & Check-Character Validator — IDs, codes & patterns (REDCap external module)

Live, as-you-type validation for any REDCap field with a structure. A mistyped
participant ID costs hours of reconciliation later; this module catches it while
the person who typed it is still looking at the field.

Two validation families, one engine:

- **Check-character IDs** (the flagship): participant and specimen IDs minted
  with ISO 7064, Damm, Verhoeff, or Luhn check characters. Recomputing the check
  catches virtually every typo and mis-scan — including the ones a regex can
  never see, like a `3` typed as an `8`.
- **Any structured value** (regex): study codes, lab numbers, device serials,
  legacy IDs, or anything else with a fixed shape. In stock REDCap a custom
  regex validation type has to be added server-wide by an administrator; here a
  project designer sets a pattern per rule, and typists get progressive
  "what's still missing" guidance instead of a bare error.

Configured entirely through REDCap's settings screen or field annotations — no
code pasting, no JavaScript Injector. IDs minted by the companion QR/ID generator
validate identically here, in Excel, and in the browser (same verified engine).

## What it does

- **Single-value fields** — recomputes the check character and/or tests the
  format, with a green "verified" or red "typo?" message under the field. Format
  errors and check-character errors are reported separately, so the person knows
  which kind of mistake they made and where. A "should end in X" hint naming
  the expected check character is available per rule (`suggestFix`) but **OFF
  by default** — a visible expected character can entice staff to force-fit a
  mistyped ID instead of re-scanning it.
- **Pooled fields** — splits a box holding several IDs (space/comma-separated, or
  jammed together with no separator) into individual IDs at the boundaries where
  the check character verifies, then shows one chip per member with warnings for
  leftover junk, duplicates, and wrong pool size.
- **Configurable enforcement per rule** — *informational* (message only),
  *advisory* (warn and confirm before saving), or *compulsory* (block the
  **browser** form/survey save until fixed). *Compulsory* blocks human form
  saves in the browser; it cannot stop an API or data-import write (see the
  safety net below), and it never traps a read-only field the user cannot fix.
- **Server-side safety net** — a `redcap_save_record` hook re-checks the saved
  value on the server with the **same** rule semantics as the client (single and
  pooled fields, check character, format pattern, regex-only) and logs any invalid
  value to the module log, scoped to the instrument that was actually saved. It
  fires *after* the write, so treat it as detection/audit, not a hard reject; the
  client *Compulsory* block is the primary control for human form entry.
  **Coverage caveat:** whether this hook fires for **Data Import Tool** and
  **API** writes depends on your REDCap version and how those imports are
  performed — do not assume import/API writes are audited until you have verified
  it on your own instance (the step is in [`docs/TESTING.md`](docs/TESTING.md)).
  A rule the server cannot evaluate leaves a `uvalidate-unconfigurable` entry
  and a hook failure leaves a `uvalidate-audit-error` entry, so neither can pass
  silently. Raw identifiers are not stored in the log by default — a keyed,
  project-scoped hash is (HMAC-SHA-256 with a module-held secret), configurable
  per project down to logging nothing. A keyed hash is pseudonymization, not
  anonymity: treat the module log as identifying data in access and retention
  policies. Format-pattern audits cover printable-ASCII values; other values are
  left to the client and the check-character math, because JavaScript and PCRE
  regex semantics are only proven to agree on that subset.
- **Save-time settings validation** — an invalid rule (unknown algorithm,
  catastrophic or non-compiling regex, unsafe pooled lengths, unsupported field
  types) is rejected when the Configure dialog is saved, with a message naming
  the rule, instead of surfacing later on a data-entry form.
- **Accessible by construction** — validation messages are polite live regions
  tied to their inputs with `aria-describedby`/`aria-invalid`, save-block
  dialogs name fields by their visible label, and every state pairs color with
  a text mark. Screen-reader behavior still needs the manual pass in
  [`docs/TESTING.md`](docs/TESTING.md) before a release is called accessible.

## Three ways to configure (pick per rule — they mix freely)

One rule = one kind of validation, applied to any number of fields:

1. **The settings dialog** — pick fields with the field picker (click its `+` to
   add more fields to the same rule) and choose the method and enforcement.
2. **Fast entry** — type field names into the rule's fast-entry box, separated by
   commas or spaces. Unknown names show a configuration error instead of failing
   silently.
3. **`@UVALIDATE` field annotations** — tag fields where you already design them:
   the Action Tags box in the Online Designer, or the `field_annotation` column
   of the data dictionary CSV. Tagging 50 fields is one spreadsheet column and
   one upload:

   ```text
   @UVALIDATE                                            default check (ISO 7064 Mod 37,36)
   @UVALIDATE=verhoeff                                   pick the algorithm
   @UVALIDATE={"algorithm":"none","pattern":"FC[0-9]{4}","blockSave":"hard"}
   @UVALIDATE={"type":"pooled","expectedIds":3}          pooled field, warn unless 3 IDs
   ```

   JSON keys: `type`, `algorithm`, `source`, `pattern`, `strip`, `keepChars`,
   `idLengths`, `idMinLen`, `idMaxLen`, `expectedIds`, `blockSave`, `when`,
   `suggestFix`, `note`. A malformed tag shows a configuration error under that
   field — never a silent no-op. Fields with identical tags are grouped into
   one rule automatically, and one field may carry SEVERAL tags when each has
   a different `when` condition (branched validation — see below).

   **Algorithm shorthands.** So you need not spell out the full internal name,
   the `algorithm` value accepts case-insensitive shorthands (e.g.
   `@UVALIDATE=3736` = ISO 7064 Mod 37,36). They also work inside the JSON form
   (`{"algorithm":"9710", ...}`).

   | Shorthands | Resolves to |
   |---|---|
   | `3736`, `37,36`, `37_36`, `mod37_36` | `iso7064_mod37_36` (default) |
   | `1110`, `11,10`, `11_10`, `mod11_10` | `iso7064_mod11_10` |
   | `9710`, `97,10`, `97_10`, `mod97_10` | `iso7064_mod97_10` |
   | `112`, `11,2`, `11_2`, `mod11_2` | `iso7064_mod11_2` |
   | `372`, `37,2`, `37_2`, `mod37_2` | `iso7064_mod37_2` |
   | `letters1` / `letters2` | `iso7064_letters1` / `iso7064_letters2` |
   | `mod10` | `luhn` |
   | `gs1`, `gtin`, `ean`, `upc` | `gs1_mod10` (GS1/GTIN/EAN/UPC Mod-10) |
   | `aba`, `routing` | `aba_mod10` (US ABA routing Mod-10) |
   | `mrz`, `icao` | `mrz_mod10` (ICAO 9303 MRZ Mod-10) |
   | `isbn`, `mod11w`, `weighted11` | `weighted_mod11` (ISBN-10 weighted Mod-11) |
   | `regex`, `format` | `none` (format/regex only — pair with a `pattern`) |

   The full names still work everywhere; shorthands are resolved when the module
   builds its config, so the browser and the server-side audit both see the
   canonical name. The single source of truth is `ALGORITHM_SYNONYMS` in
   [`php/AnnotationRules.php`](php/AnnotationRules.php).

## Conditional validation — the optional `when` key

Any rule, in all three configuration channels, may carry a condition; the rule
validates only while the condition is true:

```text
@UVALIDATE={"algorithm":"verhoeff","when":"[specimen_type]='2'"}
@UVALIDATE={"algorithm":"none","pattern":"FC[0-9]{4}","when":"[consent(1)]='1' and [site]<>'9'"}
```

The Configure dialog offers the same thing as an **"Only validate when"** box
on each rule (fast-entry fields share the rule's box). Fields whose tags differ
only in `when` become separate rules; identical tags still group.

The condition language is a **REDCap-style subset — not byte-for-byte REDCap
logic**:

| Supported | Rejected when the rule is saved |
|---|---|
| `[field]` and `[checkbox(code)]` references | functions (`datediff(...)`, …) |
| `'text'` / `"text"` / number literals | smart variables (`[record-name]`, …) |
| `=` `<>` `!=` `>` `<` `>=` `<=` | `[event][field]` prefixes (cross-event) |
| `and` / `or` / `not`, parentheses | arithmetic and piping |

Semantics worth knowing before relying on it:

- **Comparisons are numeric when both sides look numeric** (`[age]>'9'` with
  age `10` is true, not a lexicographic accident), otherwise exact,
  case-sensitive string comparison. A missing or empty field reads as `''`; a
  checkbox reference reads `'1'`/`'0'`.
- **Referenced fields on the same instrument react live**: pick the right
  dropdown option and the gated field's verdict appears or clears immediately —
  including a *Compulsory* save block. A calc field updates without DOM events,
  so a calc ref refreshes at the next event on any watched field — the manual
  check for this is in [`docs/TESTING.md`](docs/TESTING.md).
- **Fields on other instruments are resolved on the server, never sent to the
  browser.** Such a field cannot change while the page is open, so the server
  settles that part of the condition against the record's saved values and
  sends only the result. The page carries field names, your own literals, and
  booleans — never a record value, so a survey respondent (or a user without
  rights to that instrument) cannot read one out of the page source. A
  comparison mixing an on-instrument and an off-instrument field is settled the
  same way, which means it is correct as of page load but does **not** react
  live; put both fields on one instrument if you need that. A brand-new record
  has no saved values yet, so such refs resolve as `''`.
- **A false condition skips the rule — it never erases the value.** That is
  the deliberate difference from REDCap's own field branching, which erases
  hidden fields on save. Combine `when` with normal field branching if you
  also want erasure.
- **The server-side audit honors the same condition** against the record's
  saved values, so browser and audit skip (or check) a rule consistently.
- Caps: 500 characters, 20 field references, 10 nesting levels. References are
  checked against the data dictionary at save time — unknown fields, missing
  or wrong checkbox codes, and refs to file/descriptive fields are
  configuration errors, not silent surprises.

The dialect is specified normatively in [`php/Logic.php`](php/Logic.php); the
browser twin lives in `js/engine.js`, and `tests/when_fixture.json` locks the
two together (see Verification below).

### Branched validation — several conditional rules on ONE field

Since 0.9.0, a field may be covered by MORE THAN ONE rule, provided the
sharing is gated: every sharing rule carries a `when`, except at most ONE rule
without a condition, which becomes the **else** branch. The rule whose
condition is true validates the field; if none is true, the else branch does;
if there is no else either, the field is simply not validated at that moment.

```text
Field annotation (two tags in one box), or two dialog rules covering the field:
@UVALIDATE={"algorithm":"verhoeff","when":"[specimen_type]='2'"}
@UVALIDATE={"algorithm":"none","pattern":"FC[0-9]{4}"}          <- the "otherwise"
```

Rules worth knowing:

- **All configuration channels mix freely** — a dialog rule and an
  `@UVALIDATE` tag may legally share a field, as long as the sharing is gated.
- **Rejected at save time** (a configuration error, never silent): two
  when-less rules on one field; two rules with byte-identical conditions; a
  single-value rule and a pooled rule sharing a field.
- **Overlapping conditions are a runtime conflict**: if two conditions are
  ever true at once, the field shows a "Validation conflict" notice naming
  both conditions, validates nothing, and NEVER blocks the save; the server
  logs the same conflict to the module log. Mutually exclusive conditions
  (`='2'` vs `<>'2'`) can never conflict.
- `blockSave` and `suggestFix` are **per branch** — e.g. *Compulsory* for
  blood specimens, informational otherwise.
- The branch semantics are specified normatively in
  [`php/Branching.php`](php/Branching.php); browser and audit implement the
  same table (see Verification).

## Methods supported

ISO/IEC 7064 Mod 37,36 (default), Mod 11,10, Mod 97,10, Mod 11,2, Mod 37,2, two
letters-only variants, plus Damm, Verhoeff, Luhn, four digit-only weighted-modulus
schemes (GS1 Mod-10, ABA Mod-10, ICAO MRZ Mod-10, and ISBN-10 weighted Mod-11),
and "none" (format/regex only). The method must match how the IDs were minted.

The four weighted-modulus schemes run over a digit payload and each add one
check character (`weighted_mod11` may emit `X`). The three Mod-10 schemes catch
every single-digit error at any length but miss adjacent swaps of digits
differing by 5. `weighted_mod11` catches every single-digit error and every
adjacent swap only up to 9 digits (the ISBN-10 domain): at 10+ digits the
position carrying weight 11 goes blind to substitutions, so prefer Mod 11,2 or
Mod 97,10 for longer numbers.

## How it works

A REDCap admin installs the module once; each project enables it and adds rules
on the settings screen. On every form and survey the module reads its settings,
builds a config object, and injects `js/engine.js` (the verified engine). Nothing
is hard-coded per project and no third-party module is required.

```
REDCap settings  ->  UniversalValidator.php  ->  window.INSPIRE_VALIDATOR_CONFIG
                                              ->  js/engine.js (verified engine)
                     redcap_save_record       ->  php/CheckCharacter.php (server guard)
```

## Verification

Both runtimes are checked against one fixture generated by the Python source of
truth (`qrcode_generation/check_characters.py`) — and not only the raw
check-character primitive, but the full runtime path the module actually uses:

- `tests/parity_js.cjs` / `tests/parity_php.php` — recompute every fixture row
  across all three sections: `compute` (the primitive, 574 rows across 15
  algorithms), `normalize` (Unicode dash folding / case / strip), and
  `scheme_ops` (append + validate = normalize → source → compute → compare,
  including every weighted scheme through `digits_only` and a `weighted_mod11`
  `X` check tail). 918 rows total.
- `tests/pooled_js.cjs` / `tests/pooled_php.php` — recompute the pooled-field
  parser for every case in `tests/pooled_fixture.json` (frozen from the verified
  browser parser), so the server pooled auditor can never drift from the client.
- `tests/risky_js.cjs` / `tests/risky_php.php` — lock the catastrophic-regex
  (ReDoS) gate to one shared pattern list in both runtimes (nested quantifiers
  AND repeated alternation/optional groups such as `(a|aa)+`), and prove the
  server never turns a PCRE engine failure into a false invalid-ID log.
- `tests/when_js.cjs` / `tests/when_php.php` — lock the `when` condition
  dialect (parse errors, evaluation verdicts, referenced-field extraction,
  caps) to `tests/when_fixture.json` in both runtimes, so the browser gate and
  the server audit can never disagree about a condition. `tests/when_dom_js.cjs`
  drives the browser gate itself: live dropdown/radio/checkbox flips, the
  server-folded constants, fail-open on an unparseable condition, and the
  guarantee that a gated-off rule never blocks a save. `tests/hook_php.php`
  additionally asserts that no record value ever reaches the page (SEC-005),
  on data-entry forms and survey pages alike.
- `tests/when_fuzz_php.php` — the cases nobody thought of: `gen_when_fuzz.cjs`
  builds 4048 seeded conditions (valid ones from the grammar, plus mutated and
  hostile ones), freezes what the browser twin does with each, and the PHP
  engine must agree on every accept/reject, verdict and referenced-field list.
  This is what catches a numeric-vs-string comparison quietly drifting between
  the runtimes on inputs like `1e3`, `0x10` or `" 2 "`.
- `tests/branching_php.php` and `tests/branch_dom_js.cjs` — implement the SAME
  branched-validation scenario table on both sides (active branch, else
  branch, conflicts, per-branch blockSave/suggestFix, illegal-sharing
  wording), with the resolver semantics specified in `php/Branching.php`;
  `tests/hook_php.php` additionally drives branch selection through the whole
  server audit. `tests/pooled_dom_js.cjs` locks pooled chip severity (invalid
  and junk red, duplicates amber) with their non-color marks.
- `tests/annotation_php.php` — the `@UVALIDATE` parser and the shared rule
  validator (`checkFragment`) used by every configuration channel.
- `tests/hook_php.php` — the whole `redcap_save_record` audit path against a
  framework mock that refuses settings reads without an explicit project id:
  privacy modes on success AND exception paths, event/instrument scoping,
  repeat instances, duplicate-field skips, per-rule isolation, keyed hashing,
  and the save-time `validateSettings` gate.
- `tests/a11y_dom_js.cjs` — the field-facing DOM contract: live-region status
  messages, `aria-describedby`/`aria-invalid`, label-based block dialogs,
  debounce, the read-only exemption, and survey muting of technical detail.

CI (`.github/workflows/parity.yml`) runs the JS suite on Node 20, the PHP suite
on PHP 7.4, 8.1 and 8.3 (the declared floor is exercised, not just stated),
`php -l`-lints the PHP, checks the pooled fixture is regenerated, and builds a
release-shaped package to verify its layout. If either engine drifts, CI fails.
See [`tests/README.md`](tests/README.md).

## Install

See [`docs/INSTALL.md`](docs/INSTALL.md).

## Develop

```bash
node tests/parity_js.cjs      # JS engine vs fixture (compute + normalize + scheme_ops)
php  tests/parity_php.php      # PHP port vs fixture (PHP 7.4+, needs mbstring + ctype)
node tests/pooled_js.cjs      # JS pooled parser vs pooled_fixture.json
php  tests/pooled_php.php      # PHP pooled parser vs pooled_fixture.json
node tests/risky_js.cjs       # JS ReDoS gate vs risky_patterns.json
php  tests/risky_php.php       # PHP ReDoS gate + server-behavior checks
node tests/when_js.cjs        # JS "when" evaluator vs when_fixture.json
php  tests/when_php.php        # PHP "when" evaluator vs the same fixture
node tests/gen_when_fuzz.cjs  # regenerate the seeded when-fuzz fixture
php  tests/when_fuzz_php.php   # PHP "when" engine vs the JS twin (4048 fuzz cases)
node tests/when_dom_js.cjs    # "when" gate DOM contract (live refs, folded consts, fail-open)
php  tests/branching_php.php   # branch resolver (shared fields -> branch rules)
node tests/branch_dom_js.cjs  # branched validation DOM contract (active/else/conflict)
node tests/pooled_dom_js.cjs  # pooled chip severity colors + marks
php  tests/annotation_php.php  # @UVALIDATE parser + shared rule validator
php  tests/hook_php.php        # redcap_save_record audit path (mocked framework)
node tests/a11y_dom_js.cjs    # field DOM contract (a11y, debounce, survey, readonly)
node tests/config_notice_js.cjs     # page-level config-error notice
node tests/dispatch_notice_js.cjs   # dispatcher config-error routing
node tests/gen_pooled_fixture.cjs   # regenerate pooled_fixture.json after a parser change
```

`js/engine.js` is vendored from the `qrcode_generation` repo with a set of
documented deviations (config source, UI-layer security hardening, and the
`INSPIREUniversalValidator` namespace); see [`js/README.md`](js/README.md) for the
authoritative list, how to re-vendor without losing them, and how the cross-repo
fixture contract keeps the two repos in sync. There is also a manual REDCap test
checklist in [`docs/TESTING.md`](docs/TESTING.md).

## Where this fits

This is the free, open-source client of an open-core system: it catches typos in
the form for anyone, and the paid server features (central ID minting, printable
QR label sheets, pooled multi-site audit) live behind a hosted API the module can
call. The module works fully on its own without any of that.

## License

MIT — see [`LICENSE`](LICENSE).
