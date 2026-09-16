# Adversarial review round 2 — `feat/uvalidate-alternates` (1.10.0)

**Date:** 2026-08-27
**Branch:** `feat/uvalidate-alternates` (7 commits, `2aba260`..`01547c1`), 2,977 insertions / 377 deletions across 21 files
**Scope requested:** security, performance, scalability, accuracy, REDCap external-module compliance
**Relationship to the earlier report:** this is an independent re-review after `00ab169` and `01547c1`, which claim to fix C-01, H-01, H-02, M-01 through M-04 and the four Low findings in [`alternates-adversarial-review-2026-08-27.md`](alternates-adversarial-review-2026-08-27.md). Every claimed fix was re-tested rather than taken on trust.

**Verdict: conditional GO.** C-01 is genuinely fixed and verified on all four boot paths. Parity is excellent — 2,227 differential cases across 15 configurations found zero divergences, and the core safety proof is exhaustively correct. One finding should be closed before release: the new overlap guard is silent on the most natural way to write a multi-prefix ID family, and a corrupted ID is then accepted with a green tick on both runtimes.

---

## 1. What was exercised

| Check | Method | Result |
|---|---|---|
| Existing suite | 19 JS + 15 PHP suites | 34/34 green |
| C-01 regression | production-shaped boot (`defaults()` merged), plain **and branch**, single **and** pooled | fixed on all four |
| Cross-channel invariant | 42 adversarial configs through `checkFragment`, both runtimes, `normalizeAlternates` | 2 contract gaps (L-1, L-2) |
| H-01 guard | 9 check-bearing pattern shapes against a permissive format-only sibling | **3 live bypasses** |
| Pooled parity | 1,040-case differential fuzz, 8 configs, corrupted / truncated / lowercased members, unicode debris | **0 mismatches** |
| Single-field parity | 1,187-case differential fuzz, 7 configs incl. two legacy controls | **0 mismatches** outside documented fail-opens |
| `swallowSum` | exhaustive over all 4,095 subsets of 1..12 + 6,000 random sets vs an independent reference; witness parity over 6,000 sets | 0 mismatches, 0 invalid witnesses |
| Work budget | measured at each config's own cap, both runtimes, 6 shapes | see M-2 |
| XSS | hostile label / algorithm / source / pattern through 11 client sinks + the injected config | all escaped |
| REDCap compliance | manifest, setting plumbing, sanitizers, branch keys, archive build | clean |

Repro scripts are in the session scratchpad as `temporal_*`.

---

## 2. Findings

### H-1 (High) — the overlap guard is silent on the natural way to write a multi-prefix family

`00ab169` closed the old H-01 by asking a decidable question: build a concrete witness for the check-bearing alternate, and refuse if the format-only alternate also accepts it. `patternWitness` verifies its own output against the pattern's compiled regex before returning it, so the guard can only fire on a proven overlap. That part is sound.

The cost is what happens when no witness can be built. [`CheckCharacter::patternWitness`](php/CheckCharacter.php:591) returns null for `(`, `)`, `|` and negated classes, and [`checkFragment`](php/AnnotationRules.php:833) then does `continue` — no witness, no claim, no message. `(SK|DT)[1-5]-[0-9]{4}[0-9A-Z]` is an ordinary way to write "SK or DT", and it lands squarely in that hole.

Nine check-bearing shapes against the same permissive `[A-Z0-9-]+` format-only sibling:

| check-bearing pattern | witness | config | corrupted `SK1-0123Z` |
|---|---|---|---|
| `SK[1-5]-[0-9]{4}[0-9A-Z]` | `SK1-00000` | refused | — |
| `SK[1-5]-[0-9]{4,4}[0-9A-Z]` | `SK1-00000` | refused | — |
| `SK[1-5][-][0-9]{4}[0-9A-Z]` | `SK1-00000` | refused | — |
| `SK[1-5]\-[0-9]{4}[0-9A-Z]` | `SK1-00000` | refused | — |
| `(?:P-)?SK[1-5]-…` | null | refused *(by the KEEP gate)* | — |
| `(?:SK)[1-5]-…` | null | refused *(by the KEEP gate)* | — |
| **`(SK\|DT)[1-5]-[0-9]{4}[0-9A-Z]`** | **null** | **accepted** | **`ok: valid`** |
| **`SK1-…\|SK2-…`** | **null** | **accepted** | **`ok: valid`** |
| **`[^a-z]K[1-5]-[0-9]{4}[0-9A-Z]`** | **null** | **accepted** | **`ok: valid`** |

The two `(?:...)` rows are caught by accident, not by design: `:` lands in that alternate's KEEP set and trips the keep-agreement comparison. `(SK|DT)` carries no such character and goes straight through.

Both runtimes then agree on the wrong answer, so nothing anywhere signals it:

```
value SK1-0123Z  (deliberately wrong check character)
  server : {"ok":true,"reason":"valid"}
  browser: ✓ legacy format OK. (IDs in this format carry no check character, so typos …)
```

The pooled path is protected by structure — at a different declared length the DP falls back to a member plus junk, which surfaces — so this bites the single-value field, which is exactly where `checkFragment`'s comment says the witness guard is *"the only protection a single-value field gets"*.

**Fix.** Extend `patternWitness` across groups and alternation by expanding the first branch, and across negated classes by picking the first printable ASCII character the class does not exclude. The result is still verified against the real regex before use, so the "proven overlap only" property survives untouched, and the four-family driving case is unaffected (its witnesses are already buildable and do not match `FC[1-9]-[0-9]{4}`). Failing that, invert the silence: when a check-bearing alternate yields no witness *and* a format-only alternate exists, say so rather than say nothing.

---

### M-1 (Medium) — a mixed pool credits the wrong family and under-states what was verified

[`claimedBy`](js/engine.js:3361) returns the first alternate whose pattern accepts a token, recomputed after segmentation, while the DP may have matched through a different `(alternate, length)` pair. With a permissive format-only alternate the two disagree:

```
alternates: [ {legacy, [A-Z0-9-]+,          none, lengths [8]},
              {SK,     (SK|DT)[1-5]-…,      mod37_36, lengths [9]} ]
value: "SK1-0123D SK2-45679"          (both genuinely check-verified)

  id SK1-0123D  credited = 0 (legacy, shape-only)  | actually accepted by: 0:shape-only, 1:CHECK-VERIFIED
  id SK2-45679  credited = 0 (legacy, shape-only)  | actually accepted by: 0:shape-only, 1:CHECK-VERIFIED
  summary: 2 IDs read — all match the ID format ✓ (no check character in that format)
  chips  : both grey "shape only"
```

Every member carried a verified check character and the field reports that it has none. The reachable case shares H-1's root cause, so fixing the guard removes it in practice, but the underlying inconsistency is separate: the render layer re-derives an answer the parser already knew.

**Fix.** `PAIRS[li].alt` is the winning alternate; record it on the segment instead of re-matching. The comment at [`js/engine.js:3263`](js/engine.js:3263) rightly warns against feeding alternate identity into the DP *score* — recording which pair won is not that, and does not touch `betterThan`. Note this changes the segment shape, so `pooled_fixture.json` regenerates.

---

### M-2 (Medium) — the recalibrated budget does not bound the shape that costs the most

`01547c1` lowered `POOLED_WORK_BUDGET` from 2,000,000 to 500,000 against measurement. The wide tail improves a lot. Measured at each config's own cap, on this machine:

| config | pairs × maxLen | cap | JS ms | PHP ms |
|---|---|---|---|---|
| **default range 8..14** | 98 | **4096** | **58** | **129** |
| 1 format, exact length 64 | 64 | 4096 | 45 | 85 |
| 1 format, exact length 9 | 9 | 4096 | 5 | 37 |
| 1 format, 32 exact lengths | 2048 | 256 | 45 | 63 |
| 8 formats × 4 lengths (32 pairs) | 2048 | 256 | 0.4 | 11 |
| 4-family driving case | 40 | 4096 | 1.4 | 13 |

The tail is down about 15× from the earlier round (977 ms → 63 ms). What the recalibration did not move is the top of the table: the module's **default** pooled rule keeps the full 4096 cap and is now the measured worst case, at roughly twelve times the cost of the 32-pair configuration the budget throttles hardest.

The reason is in the model. The budget bounds `cap × pairs × maxLen`, but the dominant term is whether a pattern rejects before the normalize-and-compute path runs. A pattern-less range rule has nothing to reject with, so all seven candidate lengths at all 4,096 positions run the full check-character computation; the 32-pair configs each carry a distinctive literal prefix that rejects immediately. `01547c1`'s message notes this observation and then leaves the constant as the only lever.

This is **not a regression** — the default shape costs the same on `main` — and the durable scan absorbs it: `Scan\WorkBudget` predicts per-record cost before claiming a batch and targets 3 s in a browser request, 20 s under cron, so a slow rule shrinks batches instead of stalling. The cost lands on interactive save latency and on total scan wall-clock, not on stability.

**Fix (optional).** Either give the divisor a term for "does a pattern gate this pair", or accept the number and document it: the default pooled rule costs about 130 ms of server work per field per save, and about 2 minutes per thousand records in a scan.

---

### Low

- **L-1 — `checkFragment` fatals instead of reporting on a non-list `alternates`.** `$hasAlts` tests `is_array()` and `count()` but not list-ness, so [`AnnotationRules.php:736`](php/AnnotationRules.php:736) evaluates `'alternate ' . ($i + 1)` with a string key: `TypeError: Unsupported operand types: string + int` on PHP 8, and silent mis-numbering on the declared 7.4 floor. Not reachable through either configuration channel today — both funnel through `normalizeAlternates`, which rejects non-lists first — but `checkFragment` is public and documented as *the* shared validator returning a list of error strings. Inside `validateSettings` the throw is swallowed by `catch (\Throwable)`, which returns null and **allows the save**.
- **L-2 — `checkFragment` accepts `alternates: []`; both runtimes refuse it.** An empty list makes `$hasAlts` false, so the whole block is skipped and the fragment validates clean, while `alternatesOf` and `QRID_alternatesOf` both reject it — `unconfigurable` on the server, a config-error notice in the browser. This is the invariant the same file states for itself at [`AnnotationRules.php:1005`](php/AnnotationRules.php:1008). Same reachability caveat and same one-line class of fix as L-1.
- **L-3 — the COR-005 conversion missed a third map in the same function.** `claim` and `occ` became `Object.create(null)`; `seen`, the duplicate counter three lines above at [`js/engine.js:3568`](js/engine.js:3568), is still `{}`. Not exploitable — tokens are uppercased and confined to the KEEP set, so no prototype key is reachable — but it is the same convention in the same block.
- **L-4 — `alternates` is stored on rule types that never read it.** `constraint`, `required`, `unique` and `choices` fragments carrying `alternates` validate clean, keep the key, and carry it through `Branching::BRANCH_KEYS`. Dead configuration a designer will read as active.
- **L-5 — `normalizeAlternates` builds the whole list before the 8-entry cap applies.** 20,000 entries normalize in about 41 ms before `checkFragment` refuses them. Admin-authenticated and config-time only; checking the count first is two lines.

---

### Informational — surfaced here, not introduced here

**Whitespace against a narrow `strip`.** A value with leading or trailing whitespace is accepted by the browser and logged by the server as a check-character failure whenever the rule's `strip` excludes a space — `matchesPattern` trims before testing the shape, `validateId` does not. Loading `main`'s `CheckCharacter` alongside the branch's gives byte-identical output on all eight probe values, so this predates the branch. The module's default strip (`-/ _|\`) contains a space, so a default-configured rule never sees it. Worth noting only because per-alternate `strip` makes a narrow strip easier to write.

**PHP 7.4 was not exercised.** No 7.4 runtime is installed on this machine (8.3.32 only), so the declared floor was checked by reading, not running. CI covers 7.4 / 8.1 / 8.3 / 8.4.

---

## 3. What held up

**C-01 is fixed, and fixed at the right level.** `OWN_KEYS` in [`cfgFor`](js/engine.js:3710) takes a multi-format rule's own `idPattern` / `idLengths` / `idMinLen` / `idMaxLen` or nothing, never the top-level default. Booted through a config assembled exactly as `buildClientConfig` emits it:

| path | result |
|---|---|
| pooled, plain rule | 3 IDs read, chips rendered, no config error |
| single, plain rule | `START4KIDS: format OK and check character verified` |
| pooled, **branch** rule | 2 IDs read, chips rendered, no config error |
| single, **branch** rule | correct verdict |
| pooled + a genuine `idMinLen` | still refused, on the rule rather than on a default |

The branch path is new coverage; the repo's own suite exercises plain rules only. The mirror-image risk does not exist on the server — `ruleFindings` reads `$rule['idPattern']` directly with no defaults merged.

**Parity is the strongest part of this branch.** 1,040 pooled cases across eight configurations — four-family mixed, two check families at one length, a `keepChars` union with `mod37_2`'s `*`, a `digits_only` alternate, a three-length family, damm + verhoeff, and two legacy controls — with corrupted, truncated, extended, lowercased members and unicode debris between them: **zero segmentation mismatches**. 1,187 single-field cases across seven configurations, including per-alternate `strip` and both legacy controls: **zero verdict disagreements** outside the documented COR-004 fail-open and the pre-existing whitespace case above.

**The safety proof is exact.** `swallowSum` was checked against an independent reachability reference over all 4,095 non-empty subsets of 1..12 and 6,000 random sets: zero verdict mismatches, and every witness genuine (parts all declared, at least two of them, summing to a declared target). The witness is byte-identical in both runtimes across 6,000 sets, which matters because both print it. `[4,12] → 12 = 4+4+4` and `[4,5,9] → 9 = 4+5` are refused; `[10,12]`, `[8,14]`, `[3,5,7]` are not.

**Scan caps agree.** All six measured shapes produce the same cap in both runtimes (4096 / 4096 / 4096 / 256 / 256 / 4096), recovered on the client by probing where `parse()` starts returning null. An over-cap value returns `unconfigurable` — logged, not silently passed.

**The mixed-rule junk re-scan is right.** A broken check character alongside a format-only family surfaces as its own `BAD` chip rather than anonymous junk, and a format-only alternate correctly takes no part in the re-scan.

**Security.** Eleven alternates-reachable client sinks were driven with `<img src=x onerror=alert(1)>"'&` as label, algorithm, source and pattern — the new chip label, the alternate-naming verdict in all three passes, the field-level config error, the page notice, the guidance remaining-text built from a pattern's own class body, and the `suggestFix` hint. All eleven escape. The injected config is inert `<script type="application/json">` written with `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`; a label of `</script><script>alert(1)</script>` comes out fully hex-escaped with no raw `<` anywhere. The durable-scan client writes through `textContent`. Every alternate pattern goes through the same `gatePattern` chain as a rule-level one, and `matchesPattern` still refuses to run a catastrophic pattern at all.

**REDCap compliance.** `framework-version: 14`, so no `permissions` block is required and none is present; `php-version-min 7.4.0`, `redcap-version-min 13.7.0`; no `no-auth-pages`. `alternates-json` is a `textarea` inside the repeatable `rules` sub-settings, positioned after `pattern`, registered in the `is_scalar` sanitizer at [`UniversalValidator.php:1484`](UniversalValidator.php:1484) and in `normalizeSettings`' key list at [`UniversalValidator.php:1793`](UniversalValidator.php:1793), and validated by the same `checkFragment` the annotation channel uses. No sub-setting in this module uses `branchingLogic`, so the new box matches house style. `alternates` is in `Branching::BRANCH_KEYS`. `validateSettings` returns a string and cannot throw out. No new AJAX action, no new page, no new SQL, no new permission. The release archive builds clean at 1.10.0 — every required file present, no `.github` / `reports` / `tools`, no stray PDFs, `config.json` parses, both namespace checks pass.

**The documented example works.** The README's `@UVALIDATE` block parses, resolves the `3736` shorthand to `iso7064_mod37_36`, and segments a jammed four-family run with no separators at the right boundaries; a corrupted member is reported as a check-character error and junk between members is surfaced.

---

## 4. Recommended order

1. **H-1** — extend `patternWitness` over groups, alternation and negated classes, or make an unbuildable witness speak instead of stay silent. This is the one finding that lets a corrupted ID through with a green tick.
2. **M-1** — record the winning alternate on the segment rather than re-deriving it.
3. **L-1** and **L-2** — one list-ness test in `checkFragment` closes both, and restores the invariant the file states for itself.
4. **L-3**, **L-4**, **L-5** — small and independent.
5. **M-2** — a decision, not a defect: model pattern-gating in the divisor, or document the default rule's cost.
