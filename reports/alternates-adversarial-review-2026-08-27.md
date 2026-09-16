# Adversarial review — `feat/uvalidate-alternates` (1.10.0)

**Date:** 2026-08-27
**Branch:** `feat/uvalidate-alternates` (5 commits, `2aba260`..`7dfe91e`), 2,516 insertions / 367 deletions across 20 files
**Scope requested:** security, performance, scalability, accuracy, REDCap external-module compliance
**Verdict:** **NO-GO as it stands.** One defect stops the headline feature from working in a real project. The engineering underneath it is sound — two independent differential fuzzes found zero client/server divergences — but the pooled path never runs in the browser, and two config-time safety gates can be bypassed.

---

## 1. What was exercised

| Check | Method | Result |
|---|---|---|
| Existing suite | 19 JS + 22 PHP suites | all green |
| PHP 7.4 floor | portable 7.4.33 on the 6 changed suites | all green, no PHP 8-only syntax |
| Pooled parity | 800-case differential fuzz, 5 alternates configs, JS vs PHP segmentation | **0 mismatches** |
| Single-field parity | 1,460-case differential fuzz, 4 configs incl. legacy scalar | **0 mismatches** (4 documented COR-004 fail-opens) |
| Config acceptance | 22 hand-built adversarial rules through both runtimes + `checkFragment` | 3 gaps (H-01, H-02, M-01) |
| XSS | hostile printable-ASCII label through pooled chips, single verdict, config notice | escaped on every path |
| Worst-case cost | measured at the real `SCAN_CAP`, JS and PHP | see M-03 |
| Documented example | README/User Guide `@UVALIDATE` through `parseField` → server verdict | correct server-side; **fails client-side (C-01)** |

Repro scripts are in the session scratchpad under `temporal_*`.

---

## 2. Findings

### C-01 (Critical) — pooled multi-format rules never validate in the browser

`buildClientConfig` merges `defaults()` into the top level of the injected config, and `defaults()` contains `idMinLen => 8`, `idMaxLen => 14`:

- [`UniversalValidator.php:919`](UniversalValidator.php:919) — `array_merge($this->defaults(), [...])`
- [`js/engine.js:3705`](js/engine.js:3705) — `cfgFor` fills every rule key from that top level: `cfg[k] = (rule[k] !== undefined) ? rule[k] : C[k]`
- [`js/engine.js:3149`](js/engine.js:3149) — the guard reads the merged value:

```js
else if(cfg.idLengths != null || cfg.idMinLen !== undefined || cfg.idMaxLen !== undefined)
  configError = "a rule with \"alternates\" must not also set rule-level \"idLengths\", ...";
```

`cfg.idMinLen` is therefore always `8`, never `undefined`, so **every pooled `alternates` rule takes the error branch.** Booting the engine with the config `buildClientConfig` actually emits, using the exact rule the README and User Guide document:

```
validator attached for field: true
pooled configError  : a rule with "alternates" must not also set rule-level "idLengths",
                      "idMinLen" or "idMaxLen" — give each alternate its own "lengths".
field message       : ⚠ ID-check configuration error: ...
blocks save?        : undefined
```

The data collector sees a red configuration banner on the field, no chips, no member parsing, and `blockSave:"hard"` never engages. The server-side audit is unaffected — [`UniversalValidator.php:651`](UniversalValidator.php:651) builds `$vcfg` from the raw `$rule`, where those keys are genuinely absent, and the PHP guard tests `isset(...) && !== null && !== ''`. So the browser is dead while the server quietly validates, and nothing signals the gap.

Single-field `alternates` rules are unaffected: their only guard is `cfg.idPattern`, which defaults to `null` (falsy).

**Why every test misses it.** `QRID_readConfig` returns `window.INSPIRE_VALIDATOR_CONFIG` verbatim, and all four harnesses — `alternates_dom_js.cjs`, `gen_pooled_fixture.cjs`, `pooled_js.cjs`, `pooled_dom_js.cjs` — build that object without the defaults production always sends. The suite is green and the feature is broken.

**Fix.** The guard has to read the *rule*, not the merged config. Either stop inheriting `idLengths`/`idMinLen`/`idMaxLen` from `C` in `cfgFor` when `rule.alternates` is set, or have the server omit those keys from the top-level config. Whichever way, add a regression test that boots through the dispatcher with `defaults()` present — that harness gap will hide the next one too.

---

### H-01 (High) — a permissive format-only alternate silently disables the check character on single fields

[`CheckCharacter.php:697`](php/CheckCharacter.php:697) walks alternates in declaration order and returns on the first accept; a format-only alternate accepts on shape alone. The pooled path guards the analogous case by refusing a format-only alternate that shares a length with a check-bearing one ([`AnnotationRules.php:830`](php/AnnotationRules.php:830)). **The single-field path has no equivalent guard**, and `checkFragment` raises nothing:

```php
alternates: [ {label:'catchall', pattern:'[A-Z0-9-]+',              algorithm:'none'},
              {label:'SK',       pattern:'SK[1-5]-[0-9]{4}[0-9A-Z]', algorithm:'iso7064_mod37_36'} ]

checkFragment(...)                          => []          // no errors
validateSingleField(..., 'SK1-00000')       => ok:true, reason:'valid'
```

`SK1-00000` carries a deliberately wrong check character and is accepted. The client behaves the same way (pass 1 returns on the first regex-only accept). Pattern subsumption is not decidable in general, but the cheap 90% case is: warn at config time when a format-only alternate precedes a check-bearing one *and* its pattern accepts a value the check-bearing pattern also accepts — sample the check-bearing alternate's own literal prefix, or simply refuse a format-only alternate declared before any check-bearing one and make the designer order it last.

---

### H-02 (High) — duplicate `label`s collapse the KEEP-agreement gate

[`AnnotationRules.php:789`](php/AnnotationRules.php:789) keys the per-alternate KEEP sets by display name:

```php
$keepSets[$nm] = self::keepSetFor($baseKeep, $aAlgo, $a['pattern']);
...
if (!$errors && count($keepSets) > 1) { /* compare */ }
```

`$nm` is the label when one is set. Two alternates sharing a label overwrite each other; when *all* alternates share one label, `count($keepSets)` is 1 and the comparison is skipped outright. The same rule, differing only in labels:

```
labels 'QR' + 'QR2'  => "QR and QR2 disagree about which characters survive cleaning (*) ..."
labels 'QR' + 'QR'   => []          // accepted
```

The `*` an `iso7064_mod37_2` alternate can emit then survives cleaning for a sibling that cannot contain it, changing the string recorded for that sibling — exactly the hazard the gate exists to catch. Copy-pasting an alternate entry and forgetting to rename it is the obvious way to hit this. Key the map by index, and compare labels for uniqueness separately.

---

### M-01 (Medium) — a non-ASCII label passes config validation and kills the rule at runtime

`CheckCharacter::alternatesOf` and `QRID_alternatesOf` both refuse a label outside printable ASCII. `checkFragment` only checks its *length* ([`AnnotationRules.php:735`](php/AnnotationRules.php:735)). A label containing an em dash therefore saves clean and then:

- server: `validateSingleField` → `ok:true, reason:'unconfigurable'` (logged as `uvalidate-unconfigurable`, so at least visible)
- client: `configError` → notice, no validator attached

This contradicts the invariant the code states for itself — *"a rule one channel accepts can never be one another channel rejects"* ([`AnnotationRules.php:952`](php/AnnotationRules.php:952)). Add the `/[^\x20-\x7E]/` test next to the length test. Alternate-level `strip` has the same hole (M-04).

Not an escalation risk: invalid UTF-8 cannot reach the config at all, because `json_decode` gates both channels, so the `json_encode === false → return` path (which would drop validation for the whole page) is unreachable this way.

---

### M-02 (Medium) — `validateSingleField` reports a config error as a data error

`pooledState` refuses an unknown per-alternate algorithm via `knownAlgorithm` ([`CheckCharacter.php:771`](php/CheckCharacter.php:771)). `validateSingleField` has no such guard, so `compute()` throws inside `validateId`, which reads as a failed check:

```
single, alternate algorithm 'sha256_thing', shape-valid value 'SK1-0123D'
  => ok:false, reason:'check-character'     // a false finding against a good ID
pooled, same config
  => ok:true,  reason:'unconfigurable'      // correct
```

This is the COR-002 class the rule-level gate at [`UniversalValidator.php:462`](UniversalValidator.php:462) exists to prevent — that gate covers `$rule['algorithm']` but has no alternate-level twin. Reachability is low (`checkFragment` rejects unknown alternate algorithms, and the audit skips `configError` rules), so this is defence-in-depth rather than a live bug. Add the `knownAlgorithm` check to `validateSingleField`.

---

### M-03 (Medium) — the pooled work budget under-costs `PAIRS`

`SCAN_CAP = BUDGET / (|PAIRS| × maxLen)` holds the *product* constant, but real cost per (position, pair) is a `substr` plus a regex test plus a full normalize-and-check pass — not a character comparison. Measured at each config's own cap:

| Config | pairs × maxLen | cap | JS | PHP |
|---|---|---|---|---|
| 1 format, length 64 | 1 × 64 | 4096 | 49 ms | 96 ms |
| 8 formats × 4 lengths | 32 × 64 | 976 | 232 ms | **1,062 ms** |
| *(pre-existing)* 1 format, 32 lengths | 32 × 64 | 976 | 330 ms | 977 ms |
| the 4-family driving case | 5 × 10 | 4096 | 7 ms | 41 ms |

**This branch does not widen the ceiling** — the same nominal budget already cost the same on `main` via a 32-entry `idLengths`, and the alternates variant is in fact slightly cheaper. Credit where due: swapping `|LENS|` for `|PAIRS|` was the right call and no existing rule's cap moves. What changes is *reachability*: eight formats each declaring a few lengths is a natural configuration, where 32 hand-written lengths was not. The server pays this per pooled field per save, and again per record during a durable scan — a thousand-record scan of such a rule is ~18 minutes of parsing alone.

The driving case is fine. Recommend recalibrating the divisor against measured cost (pairs are roughly 5× more expensive than the formula credits) rather than adding a new cap.

---

### M-04 (Medium) — alternate-level `strip` bypasses the printable-ASCII gate

Rule-level `strip` is refused outside printable ASCII with an explicit parity rationale. An alternate's own `strip` is validated only as "must be a string" ([`AnnotationRules.php:990`](php/AnnotationRules.php:990)) and reaches `Q.makeScheme` / `CheckCharacter::normalize` unchecked. PHP splits the strip set by code point; JS splits by UTF-16 code unit, so an astral character is removed differently by the two runtimes. Apply the same gate the rule level has.

---

### Low

- **L-01** `normalizeAlternates` does not bound `lengths` array size; the `MAX_LEN_CHOICES` cap only applies through the pooled union check, so a single-type rule can carry an arbitrarily long list into stored config.
- **L-02** `claim` and `occ` in the pooled render use `{}` where the codebase's COR-005 convention is `Object.create(null)`. Not exploitable — tokens are uppercased and restricted to the KEEP set — but inconsistent.
- **L-03** Labelled `gatePattern` wording diverges between runtimes (`idPattern (GHIT) looks…` vs `GHIT: the format pattern looks…`). Only the unlabelled legacy text is asserted, so the "byte-identical" claim in `2aba260` holds only for the legacy case.
- **L-04** `QRID_swallowSum` uses `continue` where the PHP twin uses `break` on the ascending early exit. Same result, marginally more work.

---

## 3. What held up

**Accuracy.** Two differential fuzzes found nothing. 800 pooled cases across five alternates configurations — four-family mixed, overlapping same-length patterns, a `keepChars` union with `mod37_2`'s `*`, two check families, a `digits_only` alternate — with corrupted characters, junk debris, separators, unified dashes and non-ASCII, produced byte-identical segmentation in both runtimes. 1,460 single-field cases across four configurations, including the legacy scalar path, produced identical verdicts; the only four differences were the documented COR-004 fail-opens.

**The `alternatesOf` normalization is the right shape.** One list, one loop, legacy rules as a one-element list, and `pooled_fixture.json` regenerating unchanged as the proof. Attaching the winning alternate's identity *after* segmentation, never as a DP input, is what keeps `betterThan` unchanged and segmentation deterministic — and the fixture confirms it.

**`swallowSum` is correct.** The DP is sound (`rep1[s]` ⟺ s is a sum of one or more declared lengths; `L` is a sum of ≥2 ⟺ some shorter declared length leaves a representable remainder), the witness reconstruction terminates (`rep1[s]` true implies `via[s] ≥ 1`, and `rep1[0]` is false), and it strictly supersedes the pairwise test. Both pre-existing bugs are verified fixed: `[4,12]` is now refused with the witness `12 = 4 + 4 + 4`, and the junk re-scan on `idLengths [10,12]` no longer stamps a chip at length 11 while `idMinLen 10 / idMaxLen 12` still does.

**Security.** A hostile printable-ASCII label (`<img src=x onerror=alert(1)>"'`) is escaped on all three paths — pooled chips via `QRID_escapeHtml`, the single-field verdict via `nameOf`, and the config-error notice via `QRID_configErrorNotice`. The config JSON is emitted with `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`, so no project setting can close the `<script>` element. `json_decode` gates invalid UTF-8 on both channels. Every alternate pattern goes through the same `gatePattern` chain as a rule-level one — ReDoS heuristic, printable ASCII, u-flag escapes, Python-only syntax — and extracting that chain to one admission point is a genuine improvement over the three copies it replaces.

**REDCap compliance.** `framework-version: 14` (no `permissions` block needed), `php-version-min: 7.4.0` honoured and verified on 7.4.33. `alternates-json` is a valid `textarea` inside the repeatable `rules` sub-settings, registered in the `is_scalar` sanitizer ([`UniversalValidator.php:1484`](UniversalValidator.php:1484)) and in `normalizeSettings`' key list ([`UniversalValidator.php:1793`](UniversalValidator.php:1793)), validated by the same `checkFragment` as the annotation channel. No new AJAX action, no new no-auth page, no new permission. The scan path reaches alternates through the shared `ruleFindings`, so the durable scan has no blind spot. `alternates` is registered in `Branching::BRANCH_KEYS`, and the new assertion in `branching_php` that `BRANCH_KEYS` covers every option `checkFragment` reads is a good addition — that omission was silent before.

**Documentation.** README, User Guide scenario 6b, the `@UVALIDATE` page and the CHANGELOG all match the implementation, and the breaking `[4,12]` change is stated plainly rather than buried. One operational note worth adding: an existing project with `idLengths: [4,12]` loses enforcement on upgrade — the client shows a config error and the server returns `unconfigurable` — so the release notes should tell admins to check the module log for `uvalidate-unconfigurable` after upgrading.

---

## 4. Recommended order

1. **C-01** — the feature does not work without it, and the fix is small.
2. **A dispatcher-level test harness** that boots with `defaults()` merged in. C-01 is a class of bug, not an instance.
3. **H-02**, then **H-01** — both are silent weakenings of validation that a designer cannot see.
4. **M-01**, **M-02**, **M-04** — three small missing guards, each a few lines.
5. **M-03** — recalibrate the budget divisor; not a release blocker.
