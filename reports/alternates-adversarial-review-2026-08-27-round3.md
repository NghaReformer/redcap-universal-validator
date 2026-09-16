# Adversarial review — multi-format rules (`@UVALIDATE` alternates), round 3

**Branch** `feat/uvalidate-alternates` (head `46ba1f8`) vs `main` (merge base `daeb931`)
**Version** 1.10.0 · **Date** 2026-08-27
**Scope** security, performance, scalability, accuracy, REDCap external-module compliance
**Method** full-diff read; PHP↔JS differential fuzzing (5,842 paired cases); regex-dialect
probe (58 patterns × 54 values across both engines); brute-force verification of
`swallowSum`; type-hostility fuzzing of the config validators (196 shapes × 5 entry
points); wall-clock measurement of the pooled parser across the admitted config space.

Note: strix was not used. It was requested, then withdrawn at the reviewer's
direction before any run; nothing in this report comes from it.

---

## Verdict

**Conditional go.** One High finding (H-1) is a silent correctness hole in the new
single-field alternates path and should be fixed before merge. Two Medium findings
(M-1, M-2) are pre-existing dialect gaps that this branch inherits into its new
"one admission point" and multiplies by up to eight patterns per rule; they are
worth fixing here because `gatePattern` is the natural place for them. M-3 is a
measurement correction to the performance note in this branch, not a defect.

The core structural guarantee the feature rests on — `swallowSum` — is **correct**.
Cross-runtime parity for well-formed configs is **clean**: 4,291 single-field
verdicts and 500 pooled segmentations, zero disagreements. The XSS surface the
feature opens (a designer-supplied label reaching `innerHTML`) is **closed** at
every sink checked.

| ID | Severity | Area | One line |
|----|----------|------|----------|
| H-1 | High | accuracy | A format-only alternate silently swallows check-bearing values when the witness builder makes no claim — single fields only |
| M-1 | Medium | security/accuracy | `gatePattern` admits patterns the two regex engines interpret differently; `\pL` bypasses the F2 gate outright |
| M-2 | Medium | accuracy | 10 patterns one runtime admits and the other refuses — the client stops validating while the server keeps auditing |
| M-3 | Medium | performance | The work-budget recalibration does not bind the worst case; measured 203 ms/parse, paid again per record by the durable scan |
| L-1 | Low | robustness | Config validators throw on non-string `algorithm`/`label`/`strip`; `validateSettings` swallows the throw into an allowed save |
| L-2 | Low | maintenance | `CheckCharacter::pooledClaimedBy` is dead code — public, uncalled, untested |
| L-3 | Low | test coverage | `swallowSum` and `alternatesOf` have no direct cross-runtime test; the JS twins are not exported |
| L-4 | Low | messaging | `patternWitness` can return `""`, yielding `for example ""` in a designer error |
| L-5 | Low | parity | Scan-cap length unit differs: UTF-16 code units (JS) vs code points (PHP) |

---

## H-1 — a format-only alternate swallows check-bearing values, silently

**Where** `php/AnnotationRules.php` `checkFragment()`, the overlap guard;
`php/CheckCharacter.php` `patternWitness()`; `js/engine.js` `verdict()` pass 1.

The branch adds a guard for the case a mixed rule makes possible: a format-only
alternate whose pattern also accepts values a check-bearing alternate is meant to
verify. Because the first accepting alternate wins, the check character would never
be tested. The guard asks a decidable question — is there a concrete string both
patterns accept? — by building a witness from the check-bearing pattern and testing
it against the format-only one. Its own comment states the fallback: *"stays silent
whenever no witness can be produced"*, and *"The pooled path also has the length
guard below; this is the only protection a single-value field gets."*

That fallback is reachable. `witnessSeq()` returns `null` for `\D`, `\W`, `\S`,
lookaround, backreferences, named groups, POSIX classes and word boundaries — all
ordinary regex a designer might write. A check-bearing alternate using any of them
silences the guard completely.

Reproduced end to end. Rule (type `single`):

```json
[{"label":"LEGACY","pattern":"[0-9A-Z]{9}",  "algorithm":"none"},
 {"label":"MINTED","pattern":"\\D[0-9A-Z]{8}","algorithm":"iso7064_mod37_36"}]
```

Both patterns add nothing to the KEEP set, so the KEEP-agreement check does not
fire either. `AnnotationRules::checkFragment` returns **no errors**. At runtime:

```
  ABCDEFGH0  MINTED-shape=yes  real check=BAD   module verdict=VALID
  ABCDEFGH1  MINTED-shape=yes  real check=BAD   module verdict=VALID
  ZQ12345XY  MINTED-shape=yes  real check=BAD   module verdict=VALID
  X00000000  MINTED-shape=yes  real check=BAD   module verdict=VALID
```

The browser agrees (`__qridInvalid=false`, "LEGACY format OK"), and the post-save
audit raises nothing. Every mis-scanned MINTED ID is recorded as clean — the exact
failure the module exists to prevent.

The pooled path refuses the same shape correctly, via the shared-length guard:
*"a format-only alternate and a check-character alternate are both 9 characters
long."* Single-value fields have no equivalent.

Pattern classes that silence the guard (verified):

| Pattern | Witness |
|---|---|
| `\D[0-9A-Z]{8}` | none |
| `\W[0-9]{8}`, `\S[0-9]{8}` | none |
| `(?=[A-Z])[0-9A-Z]{9}`, `(?![0])[0-9A-Z]{9}` | none |
| `(?<x>[A-Z])[0-9]{8}` | none |
| `[[:alpha:]]{9}`, `\b[A-Z]{9}` | none |
| `[0-9A-Z]{9}` (control) | `000000000` — guard fires |

**Fix.** Fail closed, matching this file's own stated precedent that ambiguity is
refused at config time rather than guessed at runtime. When a rule mixes a
format-only alternate with a check-bearing one and `patternWitness` cannot produce
a witness for the check-bearing pattern, refuse the rule and say why — "cannot
prove these two formats do not overlap; narrow the format-only pattern, or give the
check-bearing one a pattern this can analyse." That keeps the analysable cases
working and stops the unanalysable ones from shipping as a silent pass. Ordering
check-bearing alternates ahead of format-only ones in pass 1 would also close it,
but changes documented declaration-order semantics, so the config-time refusal is
the smaller change.

---

## M-1 — `gatePattern` does not close the dialect gap it exists to close

**Where** `php/CheckCharacter.php` `gatePattern()`, `usesUFlagEscape()`;
`js/engine.js` `QRID_gatePattern`, `QRID_uFlagEscape`.

`gatePattern` is introduced as *"THE one place an ID pattern is admitted and
compiled"*, with four gates — Python-only anchors, printable ASCII, u-flag-only
escapes, catastrophic backtracking. The escape gate is keyed on a brace:

```php
return preg_match('/\\\\[pPux]\{/', $d) === 1 || strpos($d, '\\k<') !== false;
```

PCRE accepts the single-letter form without braces, and it is not caught:

```
  \p{L}      usesUFlagEscape=YES  gate=reject
  \pL        usesUFlagEscape=no   gate=ACCEPT
  \PL        usesUFlagEscape=no   gate=ACCEPT
  \P{L}      usesUFlagEscape=YES  gate=reject
```

`\pL` is a Unicode letter to PCRE and the literal `pL` to a browser RegExp compiled
without `u`. Both engines admit it, then disagree about every value.

A 58-pattern probe found **16 patterns both runtimes admit and then classify
differently**: `\K`, `\G`, `\h`, `\v`, `\R`, `\N`, `\X`, `\C`, `\Q…\E`, `\g1`,
`\pL`, `\PL`, `[[:digit:]]`, `[[:alpha:]]`, `[[:upper:]]`, and the brace-less
property forms. Each is a PCRE construct that JavaScript reads as an identity
escape or an ordinary character class.

End-to-end split, on a rule `checkFragment` accepts without complaint:

```json
[{"label":"L","pattern":"\\pL{8}[0-9A-Z]",       "algorithm":"iso7064_mod37_36"},
 {"label":"N","pattern":"SK[1-5]-[0-9]{4}[0-9A-Z]","algorithm":"iso7064_mod37_36"}]
```

For the correctly minted value `ABCDEFGHK`:

- server — `validateSingleField` → **VALID**
- browser — `__qridInvalid=true`, *"FORMAT error — this matches none of this
  field's accepted ID formats (L, N)"*, and with `blockSave` on, the save is blocked

The fielder cannot enter a legitimate ID; the audit thinks the field is fine. The
mirror case (browser accepts, server files an invalid-ID finding) follows from the
same table.

This gap predates the branch — `idPattern` had it too — but the branch is where the
gate was extracted and declared complete, and a rule may now carry eight patterns
instead of one.

**Fix.** Extend `usesUFlagEscape` to the brace-less forms (`/\\[pP](\{|[A-Za-z])/`)
and add a PCRE-only construct gate covering `\K \G \h \H \v \V \R \N \X \C \Q \E`,
`\g`, `\z`, and `[[:…:]]`. Keep both runtimes byte-identical, and extend
`tests/risky_js.cjs` / `tests/risky_php.php`, which already lock this wording.

---

## M-2 — patterns one runtime admits and the other refuses

Same probe, opposite failure mode — 10 patterns where the two gates disagree:

| Pattern | PHP | JS | Consequence |
|---|---|---|---|
| `(?i)…`, `(?x)…`, `(?#…)`, `(?>…)` | accept | reject | Server accepts the rule; browser raises a config error and **stops validating the field**, while the post-save audit keeps enforcing it |
| `[]`, `[^]`, `[A-\d]`, `A` | reject | accept | Rule refused server-side; browser would have compiled it |
| `\g{1}`, `\Q` | reject | accept | as above |

The first row is the one that matters: a rule that passes the server's config gate
leaves the browser unable to compile it, so live checking silently degrades to a
notice while findings continue to accumulate server-side. The JS error text already
names inline flags — *"Python-only syntax like (?P<name>…) or inline flags is not
supported"* — but only `(?P<` is actually gated on the server.

**Fix.** Add `(?i` `(?x` `(?m` `(?s` `(?#` `(?>` `(?(` `(?P=` to the server gate
alongside `(?P<`, and reject an empty or negated-empty class rather than relying on
PCRE's compile failure, so both runtimes refuse for the same stated reason.

---

## M-3 — the work-budget recalibration does not bind the worst case

**Where** `POOLED_WORK_BUDGET` / `QRID_POOLED_WORK_BUDGET`, lowered 2,000,000 → 500,000.

The comment says the old budget *"admitted ~300 ms per pooled field per save"* and
that 500,000 *"leaves every ordinary rule at the full 4096 and shrinks only the wide
tail."* Both halves are true, and together they mean the reduction does not touch
the expensive configs: for them `MAX_POOLED_LEN` (4096), not the budget, is the
binding constraint, so lowering the budget changed nothing.

Measured, each config filled to its own cap (PHP 8.3.32; 10–20 iterations):

| Config | cap | pairs | maxLen | ms/parse (PHP) | ms/parse (Node) |
|---|---|---|---|---|---|
| legacy `idMinLen 8 / idMaxLen 15` | 4096 | 8 | 15 | **203.3** | 71.7 |
| legacy `8..14` (the shipped default) | 4096 | 7 | 14 | 152.8 | 62.7 |
| alternates ×6 check-bearing, 17..22 | 3787 | 6 | 22 | 171.5 | — |
| alternates ×8 check-bearing, 15..22 | 2840 | 8 | 22 | 166.9 | 10.3 |
| **alternates ×4 (the shipped example)** | 4096 | 4 | 10 | **14.2** | 3.0 |
| legacy exact `[9]` | 4096 | 1 | 9 | 19.1 | 6.8 |

Two things follow.

**The feature is not the problem.** The four-family rule the CHANGELOG is written
around costs 14 ms — an order of magnitude below the legacy default it sits beside,
because each alternate's pattern rejects early. The worst admitted config is a
*legacy* contiguous-range rule with no pattern at all.

**The stated bound is optimistic.** 500,000 nominal units buys ~200 ms of PHP work,
not ~75 ms. The budget counts a regex-only step and a full normalize + check-character
step as equal, and the comment acknowledges the unit is nominal — but the headline
number reads as a 4× improvement that the measurements do not show. The durable scan
pays this per record: 203 ms × 10,000 records ≈ 34 minutes of CPU for one field.

**Fix.** No code change required for the alternates feature. Either correct the
comment to state the measured envelope (~200 ms worst case, unchanged by the
recalibration because the length ceiling binds first), or lower `MAX_POOLED_LEN`
for pattern-less rules if ~50 ms is the real target. Worth confirming that
`php/Scan/WorkBudget.php` accounts for a 200 ms per-record field cost.

---

## Low findings

**L-1 — config validators are not type-safe on their own.** Fuzzing 196 hostile
`alternates` shapes across five entry points produced 24 uncaught `TypeError` /
`Error` throws — non-string `algorithm` (array or object) in `validateSingleField`
and `validatePooledField`, non-string `label` / `strip` in `checkFragment` and
`alternatesOf` — plus `Array to string conversion` warnings at
`AnnotationRules.php:762,763,772,782,788` and `CheckCharacter.php:511`.

Not reachable today: both channels normalize first (`jsonToConfig` → line 588 and
`settingRowToRule` → `normalizeAlternates`), and `getSettingRules` re-normalizes on
read, so stored rules are clean. The object cases cannot arise from
`json_decode($s, true)` at all. It matters because `validateSettings` ends in
`catch (\Throwable $e) { return null; }` — *"never block settings saves on a
validator crash"* — so any future caller that reaches `checkFragment` without the
normalizer converts a crash into an allowed save of an unvalidated rule.
`checkFragment` is documented as the authoritative gate every channel goes through;
it should be safe standing alone. Guard the string casts.

**L-2 — dead twin.** `CheckCharacter::pooledClaimedBy` is public and never called
anywhere in the repo. Its JS counterpart `claimedBy` is exported and asserted on in
`tests/alternates_dom_js.cjs:248`. An uncalled, untested twin of a tested function
drifts. Either wire it into the server-side summary or drop it.

**L-3 — no direct test for the new twins.** `QRID_swallowSum` and
`QRID_alternatesOf` are not on `window.INSPIREUniversalValidator`, so CI has no
differential test for either; `swallowSum` is reachable only through configError
text. Both are hand-mirrored across runtimes and both are load-bearing. Export them
next to `gatePattern` (which this branch did export) and add a fixture pair.

**L-4 — empty witness.** `patternWitness('A{0,9}')` returns `""`, which is not
`null`, so the overlap guard can fire with `for example ""`. Treat an empty witness
as no claim.

**L-5 — scan-cap unit mismatch.** `js/engine.js:3563` compares `v.length` (UTF-16
code units); `php/CheckCharacter.php:1240` compares `mb_strlen($raw, 'UTF-8')`
(code points). Astral input near the cap makes the browser refuse ("too long to
scan") while the server still parses and can file findings on a field the fielder
was told was not checked. Pre-existing; use the same unit on both sides.

---

## What held up

These were attacked and did not break.

**`swallowSum` is correct.** Checked against true multiset enumeration over 1,581
length-sets (all subsets of 1..12 up to size 4, plus 800 random sets up to 64):
**zero verdict disagreements**, and every printed witness is a genuine ≥2-term
decomposition of a declared length using only declared lengths. The `[4,12]` class
the old pairwise test accepted (12 = 4+4+4) is genuinely closed, and the PHP and JS
witnesses match on every unsafe set the differential run produced.

**Cross-runtime parity for well-formed configs.** 4,291 single-field verdicts over
random 1–4-alternate rules (mod 37,36 / mod 37,2 / mod 11,2, `digits_only`, mixed
format-only) and 500 pooled segmentations including deliberately corrupted members:
**zero verdict disagreements, zero segmentation disagreements, zero reason-precedence
drift.** The carried `alt` index matches between runtimes on every segment. The only
disagreement in 5,842 comparisons was the `[]` gate case reported in M-2.

**XSS.** An alternate `label` is designer-supplied and reaches `innerHTML`. Every
sink escapes it: `chip()` (`js/engine.js:3536`), `nameOf()` (`:2056`), the per-field
config-error notice (`:1743`), and `QRID_configErrorNotice` (`:1772`).
`QRID_escapeHtml` covers `& < > " '`. Labels are capped at 40 printable-ASCII
characters, so `</script><svg onload=…>` fits — but the config is injected as an
inert `<script type="application/json">` element encoded with
`JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` and default slash
escaping, which closes the breakout. The scan page routes dynamic output through
`ScanPageView::h()` (`htmlspecialchars(scrub($s), ENT_QUOTES, 'UTF-8')`); the only
unescaped echoes in `pages/scan.php` are framework-controlled (JSMO bootstrap, module
URL, `(int)` casts). `js/scan.js` uses `textContent`.

**ReDoS.** Per-alternate patterns each go through `riskyPattern` (512-char source
cap, exponential and polynomial stages), so eight alternates is eight gated patterns,
not a bypass. `patternWitness` is bounded: recursion depth ≤ 8, output capped at
`MAX_ID_LEN`, `{n}` above 64 refused, and at most 8×8 witness builds per rule at
config time only. `expandClass` mis-expands escapes inside classes, but every witness
is verified against the pattern's own compiled regex before use, so a bad expansion
costs a missed warning, never a false refusal.

**Pooled work bounds.** `pooledScanCap` is enforced on both sides
(`php/CheckCharacter.php:1240`, `js/engine.js:3563`). `PAIRS` is capped at
`MAX_LEN_CHOICES` (32) and `MAX_ALTERNATES` at 8, consistently across
`normalizeAlternates`, `checkFragment`, `alternatesOf` and the JS twin. Work is
bounded at ~524k nominal units for every admitted config.

**Tests.** All 43 suites pass locally — 20 JS (Node 24), 23 PHP (8.3.32). The branch
adds `tests/alternates_dom_js.cjs` (37 checks) and wires it into
`.github/workflows/parity.yml`, extends the pooled fixture with five multi-format
cases carrying the `alt` index, and adds coverage in `annotation_php`, `hook_php`,
`branching_php`, `risky_php` and `risky_js`.

**The `OWN_KEYS` fix is right.** With `alternates` present, the client no longer
inherits `idPattern` / `idLengths` / `idMinLen` / `idMaxLen` from the injected
defaults (`js/engine.js` `cfgFor`, both the plain and branch paths). That matches
what the server does — `UniversalValidator.php:451-454` reads the raw rule — so the
"don't set these alongside alternates" guard sees the same thing in both runtimes.
Branch rules carrying alternates were probed and behave identically.

---

## REDCap external-module compliance

Reviewed against the framework contract, not just the diff.

**Clean.**

- `framework-version: 14`, `compatibility: {php-version-min 7.4.0, redcap-version-min 13.7.0}`. CI runs the PHP matrix 7.4 / 8.1 / 8.3 / 8.4, so the declared floor and the deployed runtime are both exercised. (Only 8.3 was available locally; the 7.4 floor rests on CI.)
- `namespace INSPIRE\UniversalValidator;` matches `config.json`, and the `package` CI job asserts both plus the shipped-file list, and excludes `.github`, `reports`, `tools`.
- The feature adds **no** hook, ajax action, page, table or permission. It is one repeatable project setting (`alternates-json`, `type: textarea`) inside the existing `rules` sub-settings, plus one key threaded through `Branching::BRANCH_KEYS`. HTML in the setting `name` is normal EM practice.
- `validateSettings` returns a string to block the save and `null` to allow it — correct contract — and the new alternates error is prepended via `array_unshift` so it leads the message.
- Hooks in use are all documented framework hooks: `redcap_data_entry_form_top`, `redcap_survey_page_top`, `redcap_save_record`, `redcap_module_save_configuration`, `redcap_module_system_enable`, `redcap_module_link_check_display`, `redcap_module_ajax`.
- Client config is injected as an inert JSON element rather than a JS global, and the engine URL goes through `getUrl()` + `htmlspecialchars(…, ENT_QUOTES)`.
- Non-scalar settings are stripped before use (`UniversalValidator.php:1481`), and `alternates-json` was added to that allow-list.

**Worth stating.**

- `validateSettings` is fail-open by construction (`catch (\Throwable) { return null; }`). Deliberate, documented, and defensible — a validator crash should not lock an admin out of their own settings — but it is the mechanism that turns L-1 from a warning into a silent accepted save, so the two should be read together.
- `@UVALIDATE` annotations get no save-time gate, because REDCap exposes no hook on Data Dictionary upload. Annotation config errors therefore surface only at runtime, as `configError` rules with a page notice. This is the module's existing design and it is consistent, but it means H-1, M-1 and M-2 all reach production through the annotation channel without an admin ever seeing a warning.
- `unique-check` appears in both `auth-ajax-actions` and `no-auth-ajax-actions`, which is how survey respondents reach it. Pre-existing, untouched by this branch, and previously reviewed (F3 identifier-oracle fail-open). Out of scope here, noted so it is not mistaken for something this branch introduced.

---

## Recommendation

Fix **H-1** before merge — it is a silent accept of broken check characters, in the
feature's own new code path, on the rule shape the feature was built for.

**M-1** and **M-2** are one change to two files and belong in this branch, because
this is the branch that created the single admission point and the one that lets a
rule carry eight patterns.

**M-3** needs a comment correction, not code.

The Lows can follow. Nothing found requires reverting or redesigning the feature:
the segmentation model, the union length proofs, the KEEP-agreement rule, the
per-alternate reporting and the cross-runtime parity all hold up under attack.

---

### Reproducing

Scratch harnesses used for this review (all named `*_temporal.*`):

| File | What it does |
|---|---|
| `gen_temporal.cjs` / `cmp_temporal.php` | 5,842-case PHP↔JS differential (verdicts, segmentation, gate, swallow, config acceptance) |
| `pat_gen_temporal.cjs` / `pat_cmp_temporal.php` | regex-dialect probe, 58 patterns × 54 values |
| `swallow_temporal.php` | `swallowSum` vs true multiset enumeration |
| `witness_temporal.php` | the H-1 overlap-guard bypass |
| `throw_temporal.php` | type-hostility fuzz of the config validators |
| `perf_temporal.php` / `perf2_temporal.php` / `jsperf_temporal.cjs` | pooled-parser wall clock across the admitted config space |
