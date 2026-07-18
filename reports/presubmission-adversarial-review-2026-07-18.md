# Pre-submission adversarial review — Universal Field Validator v1.5.2

**Date:** 2026-07-18
**Reviewer role:** senior QA + application-security engineer, pre-repository-submission gate
**Module:** `INSPIRE\UniversalValidator` v1.5.2 · Framework v14 · PHP ≥ 7.4.0 · REDCap ≥ 13.7.0
**Method:** grey-box source review of the full checkout, an 8-dimension adversarial pass with every
candidate finding independently refutation-tested, first-hand re-run of all 12 JavaScript test
suites (Node 24.14), and targeted execution probes for the two headline findings. No live REDCap
instance was exercised from this pass — see §6.

> **Status update (2026-07-18, post-review):** **F1, F2, F3 are fixed** and released as **1.5.3**
> (see `CHANGELOG.md`). A second independent review then surfaced **H-01** (the composite-key sibling
> of F3: the Identifier refusal covered only the primary unique field, not the `with` fields) — now
> **also fixed in 1.5.3**, all three gates, via a shared `firstIdentifier()` helper. Both runtimes
> re-verified — full PHP 7.4 / 8.3 and JavaScript suites green, with regression tests per finding
> (`risky_patterns.json` 50→56, `annotation_php` 136→141, `hook_php` 246→**252**).
>
> **H-03 (CONFIRMED, now also fixed in 1.5.3):** cross-instrument / repeating composite `@UVUNIQUE`
> gave three verdicts — the live check sent `""` for an off-instrument `with` field
> (`engine.js:2574`) → false "available"; the post-save audit's `findCollision` compared each other
> record within a single raw row → **missed** a composite spread across an event node + a repeat
> row; only the scan merged rows (`recordContexts:1685`) and caught it. Fixed server-side, two legs:
> `findCollision` now compares each record's **merged** contexts (`recordContexts`, the same view the
> scan uses), and the live endpoint **server-resolves** an off-instrument `with` field's saved value
> (never sending it back to the page). Audit == scan == live for composite keys; `hook_php` 252→256,
> each leg verified to fail without its fix. Same-instrument composites unaffected.
>
> **M-05 and L-01 (from the second review, fixed in 1.5.4):** the Validation scan silently dropped
> config-error rules (a broken rule read as "no violations") — it now discloses them, plus any
> unique-branch conflict/unparseable branch, in its "rule problems" section; and the scan's
> composite-duplicate key moved from a raw-`0x1F` join to `json_encode`, so two distinct tuples
> whose values contain that byte can no longer collide into a false duplicate. `hook_php` 256→261,
> both verified to fail without the fix.
>
> **F9, F6, F7 (fixed in 1.5.5):** F9 — `buildClientConfig` now injects only the rendered
> instrument's rules (and each rule's on-form fields), so a rule-heavy project no longer stacks one
> `document.body` MutationObserver per project rule on every form (the documented freeze). F6 — the
> client dedups pooled `idLengths` to match the server's `array_unique` (scan-cap parity). F7 — the
> client `when`/`assert` ordering comparison is now code-point order, matching the server's `strcmp`
> on astral values. `hook_php` 261→265, four astral cases locked in `when_fixture.json`, F6 confirmed
> by cross-runtime probe. **Deferred:** consolidating the 7 per-factory MutationObservers into one
> shared observer — the DOM test harness has no `MutationObserver`, so it needs a live/browser pass;
> F9's per-form filtering already removes the documented freeze.
>
> **F4 (fixed in 1.5.6):** the live endpoint's `findCollision` now narrows its read with a REDCap
> `filterLogic` on the candidate value(s) instead of exporting the whole project on every no-auth
> call, with a fail-safe fallback to the full read (unsafe value / unsupported build) and the audit
> staying authoritative. `hook_php` 265→270, verified to fail without the fix.
>
> **Still open:** F5 (an effective *sessionless* rate limit — F4 bounds per-call cost, F5 would bound
> call volume), the second review's M-03 (pooled ambiguous-length segmentation), and the deferred
> shared-observer. Live in-situ verification of all fixes is still outstanding (§6) — F4 in
> particular relies on REDCap `filterLogic` semantics that should be confirmed on the target build.

---

## 1. Verdict

**Conditional GO — submit after three small client-side fixes.**

No Critical or High finding survived verification. No confirmed XSS, SQL injection, authentication
bypass, record-id disclosure, stored-value leak, scan authorization hole, or regression of any
previously-fixed issue. The module is markedly more hardened than a typical repository submission:
every high-risk line the review targeted carries a guard whose comment traces the exact prior
finding it closed, and the JavaScript suites reproduce green on a clean checkout.

Three **Medium** findings remain, each localized and cheap to fix, and two of them undercut a claim
the module makes about itself — so they are worth closing before REDCap's own security team runs the
same analysis:

| # | Finding | Why fix before submit |
|---|---------|----------------------|
| **F1** | Client tab-freeze ReDoS from a bounded-quantifier chain (`A{1,20}A{1,20}…!`) that the `riskyPattern` gate admits | The module advertises that catastrophic patterns are rejected; this class is not, and the client has no match-time backstop the server has. ~10-line fix. |
| **F2** | Regex-dialect parity break: `\p{…}`/`\u{…}` patterns match differently on client (no `u` flag) vs server (`/u`) | Parity is the module's stated core correctness property; a valid ID is blocked live yet passes the audit/scan. ~5-line fix. |
| **F3** | Identifier existence-oracle fails **open** when the data dictionary is momentarily unreadable | The anti-oracle control the no-auth story leans on has no independent backstop. Defense-in-depth; narrow trigger. |

Everything below Medium is Low/Info and can ship with a tracking note. The two design decisions the
brief flags for defense — the no-auth uniqueness oracle and arbitrary-regex acceptance — are both
sound in intent and mostly well-guarded; F1 and F3 are the residual gaps in exactly those two areas.

---

## 2. Conformance checklist (brief §1)

| Item | Result | Evidence |
|------|--------|----------|
| `framework-version` 14 present | **PASS** | `config.json:13` |
| `compatibility` sane (PHP 7.4.0, REDCap 13.7.0) | **PASS** | `config.json:29-32`; PHP uses only ≤7.4 syntax |
| Project link + icon resolve | **PASS** | `links.project` → `pages/scan.php` (exists); `fas fa-magnifying-glass` valid; `documentation:README.md` exists |
| `no-auth-ajax-actions:["unique-check"]` justified & minimal | **PASS** | Exactly one no-auth action; `redcap_module_ajax` returns `unknown action` for anything else (`:1786`). Survey pages are unauthenticated, so the endpoint must be reachable no-auth. |
| Listing `unique-check` in **both** auth and no-auth lists is intentional | **PASS** | Required, not accidental: the framework gates each transport against its own list; a field checked from both a form (authed) and a survey (no-auth) must appear in both. |
| No hook silently unhandled / mis-signatured (v14 auto-detect) | **PASS** | Six hooks present and correctly signatured: `redcap_data_entry_form_top`, `redcap_survey_page_top`, `redcap_save_record`, `redcap_module_ajax`, `redcap_module_link_check_display`, `validateSettings` |
| Hooks emit nothing on pages they shouldn't touch | **PASS** | `injectClient` returns before emitting when the project has no rules (`:637`); JSMO bootstrap emitted only when a live unique rule exists (`:657`) |
| No double-fire / stray HTML / layout break | **PASS** | Binding is idempotent (`data-qrid-bound`, engine `:1802`); config travels as one inert `<script type="application/json">` node |
| Enable/disable clean; settings validated at save; no project leakage | **PASS** | `validateSettings` save-gate (`:1119`); every settings/dictionary read threads the explicit `$pid` (SEC-002); the one system-scope write is the project-salted HMAC key |
| Malformed tag → visible config error, never crash or silent pass | **PASS** | `configError` channel across all three config channels; each rule audited in isolation (`:178-183`); un-evaluable rules log `uvalidate-unconfigurable` |
| Algorithm docs vs implementation parity | **PASS** | 15 canonical algorithms, all implemented (`CheckCharacter::compute`), all in the dropdown; shorthands all resolve to implemented canonicals; `damm`/`verhoeff` usable by full name |

**Conformance: PASS**, with the single behavioral caveat that the no-auth Identifier refusal is only
as reliable as the data-dictionary read (F3).

---

## 3. Findings by area

Severity is the post-verification value. Confidence: **Confirmed** (code path proven, and for F1/F2
reproduced by execution), **Suspected** (real code path, impact depends on deployment), **Theoretical**
(depends on an unverified premise).

### Security

#### F1 — Client tab-freeze ReDoS: bounded-quantifier chain passes the gate, then runs unguarded (Medium · vuln · Confirmed)

- **Where:** `js/engine.js:1742` (single) and `:2782/:2859` (pooled) run `fullRe.test(value)`; the gate is `php/CheckCharacter.php:238` `riskyPattern` and its JS twin `QRID_riskyPattern`.
- **Defect:** both stages of `riskyPattern` reason only about **unbounded** quantifiers. Stage one fires on `+`/`*` adjacency or a repeated group; stage two (`polynomialOverlap`) inspects only tokens where `unbounded === true`. A `{n,m}` quantifier is parsed `unbounded=false` (`CheckCharacter.php:328`), so a flat chain of bounded quantifiers over an overlapping class — `A{1,20}A{1,20}…!` — trips neither stage and saves with no config error. The server has a match-time backstop (`preg_last_error` bail, `matchesPattern:398`; and PCRE2 auto-possessifies these anyway), but the **client** — where a real backtracking engine runs on every keystroke — has only a 512-char input cap and no timeout.
- **Reproduction (executed, Node 24.14):** pattern `A{1,20}` ×6 + `!` (57 chars, under the 512 cap) passes the gate; matching against a run of `A` with no trailing `!` backtracks super-linearly — 16 chars → 0.4 ms, 28 → 4.2 ms, 32 → 4.8 ms; the 7–8-factor variants at ~44–60 chars run for seconds and the 10–14-factor variants freeze the tab (100% CPU, no return). Any data-entry user who types or scans such a value (a mis-scanned barcode suffices) freezes the form for the duration.
- **The code already concedes this residue.** `CheckCharacter.php:234` and `tests/risky_patterns.json` note `A{1,40}A{1,40}A{1,40}9` as a bounded backtracker "relying on the match-time PCRE-error guard" — but that guard is server-only, so on the client it is unguarded, not backstopped.
- **Fix:** add a client-side match-time defense independent of the config heuristic — run `fullRe.test` under a wall-clock budget (e.g. a Web Worker with a 50–100 ms timeout, mirroring the server's `preg_last_error` bail), and/or extend stage two to treat a chain of ≥2 bounded quantifiers with large upper bounds over overlapping classes as an overlap. Lowering `QRID_MAX_SINGLE_LEN` alone does not help — the blowup is fatal by input length ~50.
- **Threat model:** requires a project designer (or a compromised designer account) to author the pattern; impact is a recoverable availability freeze for every data-entry user on the form, not data compromise. That is why it verifies Medium, not High — but it is a real gap in a defense the module presents as complete.

#### F3 — Identifier existence-oracle fails open when the data dictionary is unreadable (Medium · vuln · Confirmed path, narrow trigger)

- **Where:** `UniversalValidator.php:1830` (runtime re-check), and the same pattern at the two config-time gates `:1015` and `:1231`.
- **Defect:** the unauthenticated Identifier refusal is `if (self::isIdentifier($this->projectIdentifierFields($project_id), $field))`. `projectIdentifierFields` returns **null** whenever `dataDictionary()` yields null (any `REDCap::getDataDictionary` throw or empty result — caught, `$this->dd` stays null). `isIdentifier(null, …)` is `is_array($ids) && …` → **false**, so the refusal is skipped and control falls through to `findCollision`, which does **not** consult the dictionary (it queries `redcap_data` by field name) and still returns `{used: true/false}`. All three identifier gates share the single dictionary dependency; there is no independent backstop, contradicting the in-code comment at `:1826-1832` that line 1830 "holds the line."
- **Reproduction:** a setting/annotation unique rule with the surveys opt-in exists on a field REDCap flags as Identifier (saved while the dictionary was momentarily unreadable, or the flag added after the rule). During any request where the dictionary read transiently fails, an unauthenticated POST to `unique-check` for that field is answered used/free — the exact enrolled-or-not oracle the control was built to prevent.
- **Why not higher:** the trigger is a compound abnormal state (metadata read failing while the data read succeeds) plus a pre-existing opt-in on an identifier field. Not routinely reachable, so Medium and defense-in-depth rather than a live leak.
- **Fix:** fail **closed** on unknown identifier status. On the no-auth path: `$ids = $this->projectIdentifierFields($project_id); if ($ids === null || self::isIdentifier($ids, $field)) return ['error' => 'not enabled on surveys'];`. Apply the same at the config gates so a surveys opt-in can never be saved when identifier status is unverifiable.

#### F4 — Unauthenticated call triggers an unbounded whole-project `getData` (Low · perf/DoS · Suspected)

- **Where:** `findCollision` (`UniversalValidator.php:1958-1965`).
- **Defect:** for the default `project` scope, `findCollision` calls `REDCap::getData` with no record narrowing, materializing every record on each call. Combined with the sessionless rate-limit no-op (F5), an unauthenticated client can force repeated full-project scans against a surveys-opted-in field. Payload caps bound the request but not the server-side scan cost.
- **Impact is conditional** on a designer opt-in plus a large project, and a full scan is partly inherent to uniqueness checking — hence Low/Suspected.
- **Fix:** bound the number of records scanned or push the equality match into a targeted query/`filterLogic` rather than exporting the whole project; gate the no-auth path behind an effective sessionless throttle.

#### F5 — Rate limit is inert on the actual no-session attack path (Low · vuln · Confirmed, largely by-design)

- **Where:** `surveyRateLimited` (`UniversalValidator.php:1878`).
- **Defect:** returns `false` when there is no active PHP session, and the counter lives in `$_SESSION` under a single global key. A cookieless script is never counted (fresh empty session each request), and clearing cookies resets it. The documented throttle provides no protection against automated sessionless enumeration; the real gates are the surveys-off-by-default posture and the Identifier refusal (F3).
- **Status:** the docstring (`:1861-1872`) openly concedes the fail-open and the cookie-clearing bypass, so this is disclosed, not hidden. Keep it, but do not represent a session-keyed counter as a defense against sessionless enumeration — either key it on IP+project via the module's own store, or document it explicitly as UX-only.

### Parity / correctness — *the module's stated core property*

#### F2 — `\p{…}`/`\u{…}` regex dialect diverges: client (no `u`) vs server (`/u`) (Medium · parity · Confirmed by execution)

- **Where:** client compiles `new RegExp("^(?:"+p+")$")` with **no** `u` flag (`js/engine.js:1597`, `:2655`); server compiles PCRE with `/u` (`php/CheckCharacter.php:419`).
- **Defect:** the config-time guards reject Python `\A`/`\Z` and non-ASCII bytes, but a Unicode-property escape like `\p{Lu}{3}\p{Nd}{5}` is all-ASCII, is not `\A`/`\Z`, and passes `riskyPattern` — so it reaches both engines with no config error on either channel. In non-`u` JS, `\p` is an identity escape (literal `p`), so the client never matches a real ID; PCRE `/u` treats `\p{…}` as a real Unicode class and matches.
- **Reproduction (executed, Node 24.14):**
  - `pattern="\p{Nd}{3}"`, value `"123"` → client (no `u`) `false`, server (`/u`) `true` → **diverge**.
  - `pattern="\p{Lu}{3}\p{Nd}{5}"`, value `"ABC12345"` → client `false`, server `true` → **diverge**.
  - **Live behavior:** the client renders a FORMAT error and (under `blockSave: confirm/hard`) traps the save on a value that is valid. **Audit/scan:** the server PCRE accepts it and logs nothing. A designer who writes a `\p{…}` pattern gets silent client/server disagreement — the exact class the printable-ASCII guard was meant to prevent.
- **Fix:** reject `u`-only syntax at config time the way `\A`/`\Z` is rejected — refuse `\p{`, `\P{`, `\u{`, named backrefs `\k<` in the `idPattern` guard (both single ~`:1582` and pooled ~`:2640`) — or compile the client `RegExp` with the `u` flag so both engines agree. Add a shared cross-runtime **match** fixture (pattern+value pairs, not just heuristic-agreement pairs) to CI so a dialect gap is caught, since `risky_patterns.json` only checks that the two `riskyPattern` twins agree, never that a compiled pattern matches identically.

#### F6 — Pooled `idLengths` duplicate handling diverges → different `SCAN_CAP` (Low · parity · Confirmed, narrowed)

- **Where:** client keeps duplicates (`js/engine.js:2694`, `SCAN_CAP` divides by raw `LENS.length` at `:2776`); server `array_unique`s first (`php/CheckCharacter.php:508`, `pooledScanCap` divides by the deduped count at `:655`). `settingRowToRule` stores `idLengths` without dedup (`:1074-1078`).
- **Corrected scope:** the verifier refuted the finding's headline. The length-**count** cap (`MAX_LEN_CHOICES`) is enforced by the shared `checkFragment` on the raw array on both sides, so `[10]×33` is a config error on both — no divergence there. The real residue is narrower: a config that passes the count cap but carries duplicates (e.g. `[40]×20`) yields client `SCAN_CAP` 2500 vs server 4096. A pooled value 2501–4096 chars then gets **no client verdict** but is fully audited server-side — under a hard `blockSave` the client would not block while the server logs a violation.
- **Fix:** dedup `idLengths` in the JS engine (or once at config emission in `settingRowToRule`) so both runtimes consume the identical list.

#### F7 — `when`/`assert` ordering compare diverges on non-ASCII (astral) values (Low · parity · Confirmed)

- **Where:** client `a > b` (`js/engine.js:1133`, UTF-16 code-unit order) vs server `strcmp($a,$b)` (`php/Logic.php:471`, UTF-8 byte order).
- **Defect:** condition **literals** are ASCII-gated at parse time, but resolved **field values** are not. For `</>/<=/>=` over non-numeric operands, an astral character (> U+FFFF) sorts before a high-BMP char in UTF-16 units but after it in byte order, flipping a gate between runtimes. Equality (`=`/`<>`) uses exact match and agrees. No `when_fixture` row exercises non-ASCII ordering.
- **Fix:** compare by code point on both sides for ordering (or fold ordering comparisons over non-ASCII operands to one deterministic result); add a non-ASCII ordering fixture row.

#### F8 — `fold()` snapshot vs save-time audit diverge on a concurrently-edited off-instrument field (Info · parity · Confirmed, by-design)

- **Where:** `php/Logic.php:190` folds an off-instrument comparison to a page-load constant for the client; the server audit re-parses the raw `when`/`assert` against save-time values (`UniversalValidator.php:297-306`, `:412-424`).
- **Defect:** if the off-instrument field changes in another session between page load and save, the client's frozen constant and the server's live verdict differ — a `blockSave:hard` rule can trap a save the server would accept, or clear a block the server would flag. The audit stays authoritative for logging, so no invalid data escapes the log.
- **Status:** inherent (the browser physically cannot read off-instrument fields) and documented at `Logic.php:164-167`. Worth a one-line note that hard `blockSave` decisions should not depend on off-instrument refs; no code change strictly required.

### Performance (brief §6)

#### F9 — One `MutationObserver` per rule on `document.body` → multi-second freeze on rule-heavy projects (Medium · perf · Confirmed, disclosed)

- **Source:** live-found on pid 149 and disclosed in `CHANGELOG.md` (1.5.1 "Known issues"): a project injecting ~69 rules made a checkbox click freeze the page for tens of seconds, because each rule installs its own `document.body` observer and a DOM-mutating click fans out to all of them. Pre-existing and module-wide.
- **Assessment:** the brief's §6 asks precisely about this. `engine.js` (~163 KB) loads on every data-entry and survey page of a **using** project (mitigated: nothing injects when the project has no rules, `:637`). The per-rule observer is the real scaling cliff.
- **Fix (as the CHANGELOG proposes):** inject only rules whose fields are on the rendered instrument, and share one observer across rules. Flag for a dedicated load test before large-project rollout.

### Refuted during verification (checked, dismissed — recorded so they are not re-raised)

| Claim | Why refuted |
|-------|-------------|
| DAG record-id confinement bypassable via empty `group_id` | `group_id` is a framework hook argument, never read from `$payload`; empty means the user has no DAG (no confinement owed). A DAG-bound user always carries a server-derived non-empty `group_id`. |
| Pooled-chip `esc()` escapes only `&`/`<` → XSS | Insertion is HTML text-content context, where only `<`/`&` are parser-significant; safe as written. Latent defense-in-depth only — unify with `QRID_escapeHtml` for robustness. |
| Escape-strip blinds the gate to `\d`/`\w` quantifier chains | The tokenizer never parses `\d` regardless of the strip; bounded tokens are invisible to both stages for a different reason (F1). The strip's net bias is safe over-rejection. |
| Composite `with` field name unvalidated when dictionary absent | `uniqueWith` tokens are unconditionally regex-guarded by `checkUnique` (`AnnotationRules.php:865`) — same guard as the AJAX field; only the *existence* check is dictionary-gated. |

---

## 4. Regression status

**No regressions and no gaps.** Every previously-fixed issue from the six prior reports and the
CHANGELOG remains fixed in v1.5.2, verified by static trace and by re-running the suites.

**Suites re-run green (first-hand, Node 24.14):** `parity_js` 918/0 · `risky_js` 50/0 · `pooled_js`
8/0 · `when_js` 114/0 · `unique_dom_js` 32/0 · `branch_dom_js` 24/0 · `constraint_dom_js` 29/0 ·
`required_dom_js` 33/0 · `choices_dom_js` 67/0 · `a11y_dom_js` 22/0 · `algorithm_coverage_js` 62/0 ·
`explain_js` 574/0. (The workflow's regression agent additionally reports the PHP suites green on a
portable PHP 8.3 build — `parity_php` 918/0, `hook_php` 246/0, `annotation_php` 136/0, `risky_php`
55/0 — not independently re-run in this pass; see §6.)

| Prior fix | Status @ location |
|-----------|-------------------|
| **UV-001** stored-XSS breakout | Still fixed @ `UniversalValidator.php:683-689` — `json_encode` with `JSON_HEX_TAG\|AMP\|APOS\|QUOT`, no `UNESCAPED_SLASHES`; inert `application/json` node parsed via `JSON.parse` |
| **UV-002** DOM XSS in messages | Still fixed — `QRID_escapeHtml` (`engine.js:673`) at every `innerHTML` sink |
| **v1.4.1→1.4.3** no-auth hash-omission bypass | Still fixed @ `:1803` — guards key on `$user_id` (auth), not `$survey_hash`; `(null,null)` caller refused for `surveys:false` and Identifier fields |
| **SEC-002** `getProjectId()` unreliability | Still fixed — the save hook threads the hook's `$project_id` everywhere; `getProjectId()` used only in dialog/page contexts |
| **SEC-003** audit-error log privacy | Still fixed @ `:92`, `:608-626` |
| **SEC-004** keyed HMAC | Still fixed @ `:495-519` — `random_bytes(32)`, project-salted; identifier omitted (not unkeyed) when key unavailable |
| **SEC-005** fold privacy | Still fixed @ `:725` — off-instrument comparisons folded to booleans; no record value on the page |
| **SEC-001 / SEC-001R** exponential + polynomial ReDoS gates | Still present (`riskyPattern` + `polynomialOverlap`, both runtimes) — but see **F1** for the bounded-chain residue the polynomial stage does not cover |
| **v1.4.2** `@UVUNIQUE` inert (`is_callable` vs `method_exists`) | Still fixed @ `:660`, `:668`; missing transport logged, never swallowed |
| **v1.5.1** REDCap-17 checkbox DOM | Still fixed @ `engine.js:1170-1194` — hidden-mirror vs visible-box resolution |
| **UV-003…010, COR-001…005, PER-001/002, A11Y-001/002, UX-001/003, CMP-001** | All located and still fixed (see workflow notes) |

The 0.8.0/0.9.0 security-scan "false positive" (a real taint flow that pointed at SEC-005) is
consistent with the current `fold()` implementation being intact.

---

## 5. Prioritized punch list (pre-mortem)

Ranked by likelihood × severity. The two design decisions to defend on review — the **no-auth
uniqueness oracle** and **arbitrary-regex acceptance** — are exactly where the top two residual gaps
sit.

1. **F1 — client bounded-quantifier ReDoS (Medium).** Most likely rejection trigger: REDCap's team
   will run its own ReDoS analysis, and the module *claims* catastrophic patterns are rejected. A
   browser freeze on ordinary form input reproduces trivially. Fix first.
2. **F2 — `\p{}` regex parity break (Medium).** Parity is the module's advertised core property and
   its CI is built on it; a reviewer who tries one `\p{…}` pattern sees the client block a valid ID
   the server accepts. Fix first.
3. **F3 — identifier-oracle fail-open when the dictionary is unreadable (Medium).** Narrow trigger,
   but it is a single-point-of-failure in the anti-oracle control the no-auth design rests on. Fix
   to close the defense-in-depth gap.
4. **F9 — per-rule `MutationObserver` freeze on rule-heavy projects (Medium, disclosed).** Real
   scaling cliff; a large deployment will hit it. Schedule the shared-observer refactor.
5. **F4 — unauthenticated whole-project scan amplification (Low).** Bound the scan / add a real
   sessionless throttle.
6. **F6 / F7 — pooled `idLengths` dedup and non-ASCII ordering parity (Low).** Small, mechanical.
7. **F5 — rate-limiter honesty (Low).** Re-key or re-document.
8. **F8 — fold temporal divergence (Info).** Documentation note.

No item is a Critical/High blocker. F1 and F2 are the two I would not submit without.

---

## 6. Coverage & limits

**What this pass verified (source + JS execution):**

- Full grey-box read of every server file (`config.json`, `UniversalValidator.php`, `AnnotationRules.php`, `Logic.php`, `Branching.php`, `CheckCharacter.php`, `pages/scan.php`) and the client engine (`js/engine.js`), plus all six prior reports and the CHANGELOG.
- The no-auth AJAX oracle traced end-to-end: the `(null,null)` case yields at most `{used:bool, record:null}` — a record id requires `$isAuthenticated && !$isSurvey` and can never reach an unauthenticated caller; `$user_id`/`group_id` are framework-supplied, not payload; payload values reach only in-PHP string comparison, never a query.
- No SQL injection reachable: the only DB sinks are `REDCap::getData`/`getDataDictionary`/`getGroupNames` with array field/record/event parameters; no raw query, no string-concatenated SQL.
- No confirmed DOM-XSS: `QRID_escapeHtml` wraps every value-, pattern-, message-, and record-id-derived fragment at every `innerHTML` sink; the config is an inert `application/json` node.
- `scan.php` authorization, DAG confinement (unresolvable DAG scans nothing), value-suppression, CSV formula-defusing, and GET-only/no-writes all sound.
- Logic-parser DoS ruled out: only `not` and `(` recurse and both are depth-guarded; `and`/`or` chains iterate.
- All 12 JavaScript suites re-run green; F1 (ReDoS timing) and F2 (`\p{}` divergence) reproduced by direct execution.

**What this pass did NOT and cannot cover — must be checked at source/live before release:**

- **No live REDCap instance was exercised.** The module's own release-gate items in `docs/testbed/LIVE_TEST_PLAN.md` §5 (JSMO AJAX transport) and §6 (Validation scan) remain the two things only a live instance proves. F1/F2/F3 should each be reproduced against a live install to confirm severity in situ.
- **PHP suites were not re-run in this pass** (no system PHP in this environment). The workflow subagent reports them green on a portable 8.3 build; treat that as corroboration, not independent proof. Re-run `hook_php.php`, `parity_php.php`, `risky_php.php` on the target PHP floor (7.4) before submission.
- **Server-side ReDoS / true concurrency / real load** are not observable from source: F1's server-side behavior depends on the deployment's PCRE build (F1 argues it is client-only because PCRE2 auto-possessifies), and F4/F9 need a real large project to quantify.
- **The `docs/testbed/LIVE_TEST_PLAN.md` header still reads "v1.4.0"** and its §0 version check says v1.4.0 — a doc-freshness gap now that the module is v1.5.2 (the §9b choices section was appended for 1.5.0). Refresh before the live run.
- This pass **complements** the module's shipped PHP/JS parity tests and REDCap's own review; it does not replace either.

---

*Findings F1 and F2 were reproduced by execution (Node 24.14). All other findings are source-verified
grey-box; F3–F9 were each independently refutation-tested. Four candidate findings were refuted during
verification and are listed in §3 so they are not re-raised.*
