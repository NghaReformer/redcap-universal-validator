# Changelog

## 1.5.2 — renamed to "Universal Field Validator"; tightened the module description

Presentation only — no functional change, no rule behaves differently.

- **Renamed** from "Universal Regex & Check-Character Validator — IDs, codes &
  patterns" to **"Universal Field Validator — check-character & regex IDs,
  cross-field rules, uniqueness & dynamic choices"**. The old name framed the
  module as ID validation; four of its five modes (constraints, required,
  uniqueness, dynamic choices) are not about IDs. The `INSPIRE\UniversalValidator`
  namespace and the module directory are unchanged, so this is a display-name
  change with no deployment impact. The browser-facing strings (the
  configuration-error box title and console messages in `js/engine.js`), the
  README and user-guide titles, and the class docblock were updated to match;
  historical CHANGELOG entries keep the name they shipped under.
- **Description rewritten** shorter (~330 → ~190 words) and made scannable: each
  of the five tags gets a one-line "what it does", and the **Validation scan** is
  now called out as its own capability (it was previously a clause buried in the
  first sentence) — a post-save audit plus an on-demand project scan that
  re-checks every saved record, covering values entered by API, Data Import, or
  before a rule existed, with CSV export.
- The five action-tag helper entries (shown in the Online Designer) were already
  complete; no change there.

## 1.5.1 — checkbox state was unreadable on REDCap 17 (live-found, pid 149)

**Bug fix. Anyone using a checkbox in a `when`/`assert` condition, or filtering a
checkbox with `@UVCHOICES`, should take this release.** Found on the first live
run of 1.5.0 on REDCap 17.0.6.

- **The defect.** For each checkbox option, REDCap 17 renders TWO elements: a
  **hidden** input named `__chk__<field>_RC_<code>` (its VALUE is the code when
  checked, `""` when not — `type=hidden`, so its `.checked` is always false) and
  the **visible** clickable `<input type=checkbox>` carrying id
  `id-__chk__<field>_RC_<code>` (shared name `__chkn__<field>`). The engine read
  `.checked` off the element it found **by name** — the hidden mirror — so a
  checked box read as **unchecked**. Consequence: every `[field(code)]` checkbox
  reference evaluated false regardless of the real state. A cascade gated on a
  checkbox (e.g. `@UVCHOICES={"when":"[pilot(1)]='1'",…}`) never activated; a
  checkbox `@UVASSERT`/`@UVREQUIRED`/`@UVUNIQUE` `when` never fired; a
  checked-but-hidden `@UVCHOICES` code was never detected as stale.
- **The fix.** A single `QRID_readCheckbox(field, code)` now resolves the state
  across renderings: a `__chk__…_RC_code` that IS a checkbox → its `.checked`
  (classic REDCap); one that is `hidden` → `value === code` (17.x mirror); else
  the visible `id-__chk__…_RC_code` checkbox → its `.checked`. `readRef` routes
  every checkbox reference through it, `requestField` also binds change/click on
  the visible `__chkn__<field>` (the hidden mirror fires no events), and the
  `@UVCHOICES` renderer computes "is this option checked" the same robust way so
  a checked option is never hidden from under the user.
- **Why the tests missed it.** The DOM stub modeled `__chk__…_RC_code` as a real
  checkbox with a working `.checked` — more forgiving than REDCap 17. The stub
  now models the 17.x two-element structure (hidden mirror + visible box in one
  choicevert row); `tests/choices_dom_js.cjs` gained a checkbox-ref-gated
  cascade and a checked-hidden-stale case (58→67 checks) that FAIL on the old
  code and pass on the fix (verified by reverting). Backward compatibility with
  the classic single-checkbox rendering is retained and still covered.
- Radio, dropdown, the two-level cascade, stale-kept selections, and the
  save-block (off/confirm/hard) were all verified working live on the same run.

### Known issues (not fixed here)

- **Performance on rule-heavy projects.** A project injecting ~69 rules made a
  checkbox click freeze the page for tens of seconds live (each rule installs its
  own `document.body` MutationObserver; a click that mutates the DOM fans out to
  all of them). Pre-existing and module-wide, not specific to choices mode —
  tracked separately. Candidate fix: inject only rules whose fields are on the
  rendered instrument, and share one observer.
- **Checkbox message placement.** A `@UVCHOICES` message on a checkbox field is
  anchored inside the first option row; a show/hide list that hides that first
  code could hide the message with it. Narrow (typical cascades keep the first
  code shown); to be re-anchored above the option rows in a later release.

## 1.5.0 — dynamic choice filtering: the @UVCHOICES tag (choices mode)

A fifth rule mode. REDCap's `@HIDECHOICE` hides options statically;
`@UVCHOICES` shows/hides individual options of a **radio, dropdown or
checkbox** field while a REDCap-style condition holds — cascading
country → region → site lists in one field instead of a near-duplicate field
per country.

- **Grammar.** JSON form only, exactly one of `show` (whitelist — the
  complement of the field's own choice list hides) or `hide` (blacklist) per
  tag, plus optional `when`, `message`, `blockSave`. Repeated tags with
  different `when` conditions branch through the existing `Branching`
  machinery (one tag per country, at most one unconditional fallback); no
  active branch means no filter. Codes are validated against the field's
  `select_choices_or_calculations` at rule-build time; unknown codes,
  non-choice field types, and matrix membership are per-field config errors.
- **A hidden selection is never cleared.** A currently-selected choice that
  becomes hidden stays visible (dropdowns keep it in place, disabled), the
  field is flagged invalid with the message, and `blockSave` (off/confirm/
  hard) runs through the shared save guard. Values outside the field's choice
  list (missing-data codes) are out of scope on both runtimes.
- **Plumbing.** Rules carry `choicesAll` (the full code list, attached from
  the data dictionary) so the client computes a `show` whitelist's complement
  without DOM enumeration — checkbox options are only findable by exact
  `__chk__<field>_RC_<code>` name. `choicesAll` participates in the
  `groupMulti` canonical key, so identically-tagged fields with different
  choice lists never merge into one rule. `projectFieldChoices()` now
  enumerates radio and dropdown rows too (previously checkbox-only;
  `Logic::checkRefs` is unaffected — it only consults checkbox entries).
- **Client.** New `QRIDChoiceFilterInit` factory (same variant/gate/boot
  skeleton as required mode, own guard item, composes with the other modes).
  Dropdown filtering physically removes and re-inserts `<option>`s in
  original order — Safari ignores `hidden`/`display:none` on options; radio
  and checkbox options hide their wrapper element. Live re-evaluation rides
  the shared when-registry.
- **Audit + scan.** `ruleFindings` gains a `choices` block: a saved value
  (or checked checkbox code — the one mode that judges checkbox arrays) that
  the active filter hides logs `type: choices`, `reason: hidden-choice`; the
  Validation scan reports the same verdicts unchanged.
- **Tests.** `tests/choices_php.php` (37 checks: grammar, errors, grouping,
  branching), `tests/choices_dom_js.cjs` (44 checks: remove/restore order,
  stale-kept semantics, conflict, survey muting, blocking), and
  `tests/choices_fixture.json` — the hidden-set contract consumed by BOTH
  runtimes (`hook_php.php` drives every fixture case through the real audit;
  the DOM test through the real factory). `tests/hook_php.php` 210→246.
  Full existing suite green (no regressions).

## 1.4.3 — the v1.4.1 survey guards were bypassable by omitting a parameter

**Security fix. Anyone running 1.4.1 or 1.4.2 with an `@UVUNIQUE` rule should
take this release.** Found by adversarial review of 1.4.1 itself: the hardening
it added did not defend the path that actually mattered.

- **The defect.** `unique-check` is declared in `no-auth-ajax-actions`, so the
  endpoint is reachable with **no session at all**. v1.4.1 decided "is this
  caller untrusted?" from `$survey_hash` — a value the *caller* supplies. An
  unauthenticated request that simply **omitted the hash** got
  `$isSurvey === false` and skipped every guard added in 1.4.1: the
  `surveys` opt-in requirement, the Identifier refusal, and the rate limit. The
  endpoint then answered `used: true/false` for **any** field carrying a live
  unique rule — including a field flagged `Identifier?`, and including rules
  whose designer never opted surveys in. An unauthenticated, unthrottled
  existence oracle ("is this national ID enrolled?") — precisely what 1.4.1 was
  written to prevent, defeated by leaving a parameter out.
- **The fix.** The guards now key on **authentication** (`$user_id`), the only
  value here that means REDCap authenticated the caller; a survey hash proves
  nothing. Any unauthenticated caller — survey page or bare HTTP — must pass the
  opt-in, the Identifier refusal and the throttle. The colliding record id still
  requires an authenticated, non-survey request (and the DAG check).
- **Why the tests missed it.** They covered `(survey_hash, no user)` and
  `(no hash, staff user)` but never `(no hash, no user)` — the unauthenticated
  caller. `tests/hook_php.php` now exercises that exact shape: an anonymous
  request is refused on an Identifier field and on any rule without the opt-in,
  answers boolean-only on an opted-in non-identifying field, and the same field
  still answers a staff session in full. Verified by reverting the fix and
  watching the new checks fail.
- `tests/hook_php.php` 205→210.

## 1.4.2 — @UVUNIQUE was inert on a real REDCap (live-found)

Found on the first live run of v1.4.0 (pid 149, REDCap 17.0.6): every rule kind
parsed and attached correctly, but the injected config carried **no
`jsmoName`** on a form with three unique rules — so the browser had no AJAX
transport and the live duplicate check did nothing at all. Silently. The
post-save audit and the Validation scan still caught duplicates, so no data was
wrong; the headline as-you-type check simply never ran.

- **Root cause.** The transport was guarded with
  `method_exists($this, 'initializeJavascriptModuleObject')`. The External
  Modules framework exposes those methods through
  `AbstractExternalModule::__call()`, and **`method_exists()` returns FALSE for
  a magic-proxied method** — so the entire block was skipped, with no exception
  to notice. Now guarded with `is_callable()`, which honours `__call()` and is
  true for a directly-declared method too. Both framework shapes work.
- **Why no test caught it.** The test stub *declares* the methods, so
  `method_exists()` was true in the mock and false in production — the mock was
  more permissive than reality. `tests/hook_php.php` now carries a
  `ProxyJsmoModule` that serves both methods **only** through `__call()`,
  exactly as the real framework does, so this class of mistake cannot return.
- **The empty catch was the other bug.** A missing transport was swallowed,
  which is precisely what hid the diagnosis and violates the module's own rule
  that nothing fails silently. A missing/failing JSMO now logs
  `uvalidate-no-unique-transport` with the reason and the consequence ("the live
  duplicate check is inert on this page; the post-save audit and the Validation
  scan still apply"). The client still fails open and never traps a save.
- `tests/hook_php.php` 197→205.

## 1.4.1 — the survey uniqueness check is refused on Identifier fields

Closes the one advisory from the 15 Jul 2026 security scan (v1.4.0: **0 errors**,
one warning). The scanner flagged the module's `no-auth-ajax-actions` and asked
us to confirm two things. The first was already true — the `unique-check`
payload is allow-listed (field name validated, answered only for fields
carrying a live unique rule, scope/composite/opt-in re-derived from stored
rules, payload capped). The second — *"a survey-side uniqueness reply does not
expose sensitive record existence"* — **could not honestly be confirmed**: an
"already used" answer to an unauthenticated respondent IS record-existence
disclosure. That is inherent to the feature, and opt-in + boolean-only limits
the blast radius but does nothing about a TARGETED probe ("is this national ID
enrolled?"). So the guard is no longer left to the designer's reading:

- **Refused on Identifier fields.** REDCap already knows which fields identify
  a person, so `surveys:true` on a field flagged `Identifier?` is now a
  configuration ERROR in both channels, not a warning — enforced again at the
  endpoint (defence in depth), and staff-side uniqueness on those fields is
  unaffected.
- **The unauthenticated path is rate-limited** (30 checks/minute/session,
  fail-open when there is no session). Honest about scope: this blunts a script
  walking an ID space; it is not a defence against a targeted probe or an
  attacker who clears cookies — the Identifier refusal is.
- **The opt-in label now says what is true**: "anyone holding your survey link
  can test whether a specific value is already in this study", with the
  reasonable use (a non-identifying response token) named, instead of the old
  euphemism "record-derived information".
- **Fixed a fail-open the guard itself introduced** (caught by PHP warnings, not
  by a passing test): the dialog channel resolved the identifier map inside
  `settingRowToRule`, which has no project id and would fall back to
  `getProjectId()` — null on import/API contexts (SEC-002), so the dictionary
  read would come back empty and the guard would silently pass. The map is now
  passed in from the caller's explicit pid, like `$types`/`$choices`, with a
  regression test that models a null `getProjectId()`.
- Also: the Control Center **description** still described only `@UVALIDATE` and
  "Text/Notes fields" — factually wrong since 1.0.0. It now covers the four
  composable rule kinds and the Validation scan.
- `tests/hook_php.php` 187→197.

## 1.4.0 — the Validation scan (retrospective project sweep)

The last piece of the 1.x expansion: a project page that runs EVERY configured
rule over EVERY saved record. Live validation guards the form; the scan
reaches what it cannot — Data Import Tool and API writes (save-hook coverage
is version-dependent), and records entered before a rule existed.

- **"Validation scan" project link** (`pages/scan.php`), visible to users
  with design rights via `redcap_module_link_check_display` and re-checked on
  the page. Read-only; results as an on-page table and a CSV download
  (quoted, spreadsheet-formula-defused).
- **One dispatch, two consumers.** `auditRule` was refactored into a thin
  logging wrapper over the new `ruleFindings()` — pure evaluation returning
  findings — and the scan consumes the same method, so the save-hook audit
  and the scan can never disagree about what a violation is. All 175
  pre-refactor hook checks pass unchanged.
- **Scan semantics.** Records are read in chunks (memory-safe on large
  projects); every record/event/instance context is evaluated, with repeat
  rows merged over their event row exactly as the audit's value reader does.
  Unique rules run as ONE aggregate pass over the scanned data (project /
  DAG / event scopes honored; a group is a violation only across two or more
  distinct records) instead of a whole-project read per record.
- **Privacy by construction.** The report names record / event / instance /
  field / rule / reason — never the stored value (staff open the record under
  REDCap's own access control). A DAG-bound user scans only their own group's
  records; an unresolvable DAG scans nothing rather than everything.
- **Verification.** `tests/hook_php.php` 175→187: all four modes found where
  seeded, DAG record-set confinement, dag-scoped unique across DAGs, repeat
  instance numbers, chunked reads, config-error exclusion, and a guard that
  no stored value appears in the report.

## 1.3.0 — no duplicates across records (`@UVUNIQUE`)

Fourth validation mode, and the module's first server round-trip: field-level
uniqueness, which REDCap has no native equivalent for. As the value is typed,
the browser asks the server whether it is already recorded in another record
(framework AJAX — CSRF-protected, survey-aware) and shows used/free live with
the usual message/confirm/block enforcement.

- **`@UVUNIQUE` tag / `unique` mode.** Bare (project-wide), `=project|dag|event`
  scope shorthand, or JSON `{with, scope, when, message, blockSave, surveys}`.
  `with` makes the key composite (value + those fields together unique, e.g.
  specimen ID within site); available in all three configuration channels
  (new dialog boxes: composite fields, scope, survey opt-in).
- **Privacy posture.** The endpoint re-derives scope/composite from stored
  rules — nothing security-relevant is trusted from the page — and answers
  ONLY for fields carrying a live unique rule, so it cannot be used as an
  existence oracle for arbitrary fields. Staff see the colliding record id
  only inside their own DAG; surveys are an explicit per-rule opt-in
  (`surveys:true`) answered boolean-only, never a record id.
- **Fail-open transport.** No JSMO, a network error, an error response, or an
  answer that never arrives — each leaves the field unflagged and never traps
  a save; the console explains why. A one-deep answer cache plus a pending-key
  guard means one request per candidate value (the direct listeners and the
  when-registry self-watch cannot double-fire a request), and stale responses
  are discarded by sequence.
- **The race is audited, not denied.** Two near-simultaneous saves can both
  pass the live check; the post-save audit re-checks the saved value against
  every other record (same scope/composite semantics via one shared
  `findCollision`) and logs `type: unique, reason: duplicate-value`.
- **Field types.** Text, Notes, dropdown, radio, yes/no, true/false, slider
  (no calc); composes with the other modes on one field.
- **Verification.** New `tests/unique_dom_js.cjs` (32 checks: transport stub,
  payload shape, composite re-check, fail-open paths, pending/stale/cache,
  survey opt-in, when-gate, composition); `tests/hook_php.php` 151→175 (the
  AJAX endpoint end-to-end: collision/self-exclusion/trim, anti-oracle,
  composite, survey opt-in + boolean-only, DAG masking both directions,
  payload hygiene, event-scope read, audit backstop, JSMO injection on/off,
  dialog save-gate); `tests/annotation_php.php` 121→136. Full JS + PHP
  7.4/8.3 suites green.

## 1.2.0 — Constraint and Required rules in the Configure dialog

The two 1.x modes reach the dialog and fast-entry channels, so every rule kind
is now available in all three configuration channels (dialog, fast entry,
action tags) — no annotations needed for cross-field or required rules.

- **"What this rule checks" selector.** The dialog's rule-type radio grows two
  kinds: *Constraint* (cross-field: invalid unless a condition is true) and
  *Required* (must not be blank, optionally gated by "Only validate when").
  New per-rule boxes: the constraint **condition** (`assert`) and the shared
  optional **message**.
- **Per-mode key isolation.** Constraint/Required rows read ONLY their own
  boxes — algorithm/pattern/pooled boxes visible in the shared dialog are
  ignored for those kinds (their labels say so), proven by a test that puts a
  catastrophic regex in an ignored box and shows the rule still runs clean.
- **Per-mode field types in the dialog** (mirrors the annotation channel):
  constraints accept Text/Notes/dropdown/radio/yes-no/true-false/calc/slider;
  required the same minus calc; check rules stay Text/Notes.
- **Save-time gate covers the new kinds.** A constraint without a condition, a
  function in the dialect, required-on-calc, and an assert referencing an
  unknown field are all rejected in the dialog with the row named; a check
  rule and a constraint sharing a field pass (they compose, not conflict).
- **Docs.** README channel intro reflects the four kinds; USER_GUIDE's
  outdated "no cross-field validation" and "Text/Notes only" answers updated.
- **Verification.** `tests/hook_php.php` 139→151 (dialog-channel audits for
  both modes, key isolation, save-gate cases). Full JS + PHP suites green.

## 1.1.0 — conditional required (`@UVREQUIRED`)

Third validation mode: a field must not be left blank — with the two things
REDCap's own required flag lacks, a **condition** and a **real block**. Native
required is unconditional and only warns; `@UVREQUIRED="[consent]='1'"` turns
the requirement on and off live as the referenced fields change, and
`blockSave:"hard"` actually stops the browser save.

- **`@UVREQUIRED` tag / `required` mode.** Bare (always required), a bare
  condition value as the `when` shorthand, or JSON `{when, message, blockSave}`.
- **The inverse emptiness rule.** Every other mode is inert on blank; required
  fires ON blank (whitespace-only counts). Filling the field clears the notice
  — deliberately no green "OK", because required never judges the value. Pair
  with `@UVALIDATE`/`@UVASSERT` (modes compose): on a blank field only required
  fires; on a filled-but-wrong value only the value checks fire.
- **Field types.** Text, Notes, dropdown, radio, yes/no, true/false, slider —
  not calc (the person entering data cannot fill a calc, so requiring one would
  trap them). Read-only fields show the notice but never block (UX-003).
- **Self-watch via the shared when-registry.** The factory watches its own
  field through a synthetic `[field]<>''` gate, reusing the existing
  `___radio`/select/hidden-mirror listener wiring instead of duplicating it.
- **Server audit.** A blank-while-required save logs as `type: required`,
  `reason: required-blank`; a blank carries nothing identifying, so the entry
  is safe in every privacy mode. The `when` gate is honored server-side.
- **Verification.** New `tests/required_dom_js.cjs` (33 checks: blank/fill,
  whitespace, live when-flip, dropdown + radio anchors, composition, readonly
  exemption, branch conflict); `annotation_php` 109→121, `hook_php` 128→139.
  CI wired.

## 1.0.0 — cross-field constraints (`@UVASSERT`)

The 1.0 milestone: the module grows beyond ID/check-character validation into a
universal data-integrity validator. This release adds a second validation mode,
`@UVASSERT`, that turns the existing condition engine from an applicability
*gate* into a validation *test*: the field is invalid unless a cross-field
condition holds. This closes a gap stock REDCap leaves open — branching only
hides, a range check only warns, and Data Quality runs in batch — none can
*block* a bad relationship at entry.

- **`@UVASSERT` tag / `constraint` mode.** `@UVASSERT="[end_date]>=[start_date]"`,
  or JSON `{assert, message, blockSave, when}`. The condition uses the same
  REDCap-style dialect as `when` (parity-locked in `php/Logic.php` + the JS
  twin); ISO dates and numbers compare correctly. Confirm-a-value is just
  `@UVASSERT="[id]=[id_confirm]"`.
- **An empty field is inert** (requiring a value will be `@UVREQUIRED`'s job).
  `message` is the designer's own wording, with a generic fallback.
- **Field types.** Constraints attach to Text, Notes, dropdown, radio, yes/no,
  true/false, calc and slider fields (check-character/regex validation stays
  Text/Notes). The validated field's value is read through the same
  name-convention reader the `when` gate uses.
- **Modes compose.** A field may carry `@UVASSERT` alongside `@UVALIDATE`; the
  two attach independent validators and both must pass. Duplicate detection and
  `Branching::resolve()` now key on **(field, mode)** — same-mode sharing still
  branches; different modes coexist. Each validator owns an independent
  save-block item, so a passing constraint never clears a failing check's block.
- **Server audit + privacy.** `redcap_save_record` evaluates the assert against
  saved values and logs a failure as `type: constraint`. Off-instrument refs are
  server-`fold()`ed to constants, so no record value reaches the page (SEC-005).
- **Verification.** New `tests/constraint_dom_js.cjs` (assert test, dropdown,
  when-gate, composition, branches, config error); `tests/annotation_php.php`
  and `tests/hook_php.php` extended for the parser and the server audit. Full
  JS + PHP (7.4/8.1/8.3) suites pass.

## 0.9.1 — conditions are resolved on the server; no record value reaches the page

Security fix (SEC-005) for a data-exposure regression introduced with the
`when` feature in 0.8.0, found while reviewing the 15 Jul 2026 external-module
security scan. **Sites running 0.8.0 or 0.9.0 should take this release**;
0.7.1 and earlier are unaffected (they never sent record data to the page).

- **What was wrong.** To let the browser evaluate a condition that references a
  field on another instrument, 0.8.0 baked that field's saved value into the
  page as a `whenValues` block. Anything in the page is readable by whoever
  loads it — so on a **survey page** a respondent could read a staff-only field
  out of the page source (`View Source` → `inspire-validator-config`), and on a
  data-entry form a user without rights to that instrument could do the same.
  Only fields named in a `when` condition were exposed, and only for the record
  being viewed; no XSS was involved (see below).
- **The fix.** A field the browser cannot see also cannot change while the page
  is open, so there is no reason to send its value. The server now resolves
  those comparisons itself and sends only the outcome: `Logic::fold()` walks
  each condition and replaces every comparison it will not ship a value for
  with a `["const",true|false]` node. The page now carries field names, the
  designer's own literals, and booleans — never a record value. Comparisons
  over fields of the rendered instrument are untouched and still react live as
  the user edits them; a comparison mixing an on- and off-instrument field is
  folded whole (correct at page load, no live reaction — documented).
  `whenValues` is gone from the injected config.
- **The two scanner findings (`TaintedHtml`, `TaintedTextWithQuotes`) were a
  correct false positive** for XSS — verified by pushing `</script><script>`,
  `"><img src=x onerror=>` and three more breakout payloads through the old
  path: `json_encode`'s `JSON_HEX_TAG|AMP|APOS|QUOT` flags escaped every markup
  character, exactly as the scan summary argued. That analysis stands. What the
  findings were pointing at, though, was the taint flow itself — saved record
  values reaching the page — and this release removes it at the source. The
  `REDCap::getData()` → `echo` string flow that appeared in 0.8.0 and made the
  two builds differ from the clean v0.7.1 scans no longer exists, so the
  whitelist request to the framework maintainers should no longer be needed —
  worth a scanner re-run to confirm.
- **Tests.** `tests/hook_php.php` now asserts the absence of record values in
  the emitted page on both form and survey contexts, and the folded shape of
  every rule/branch condition; `tests/when_php.php` unit-tests `fold()`
  (live refs kept, off-page folded, mixed comparisons folded whole, checkbox
  refs, values absent from the output); the shared `tests/when_fixture.json`
  gains `astEval`/`astRefs` sections that lock the `["const",bool]` wire format
  across both runtimes; `tests/when_dom_js.cjs` covers pre-folded ASTs, the
  AST-beats-text precedence, and the parse-the-text fallback.

## 0.9.0 — branched validation, rename, opt-in hints, chip colors

Four changes from live use. The headline: one field may now be covered by
SEVERAL rules, each gated by a `when` condition — "validate as Verhoeff when
[specimen_type]='2', otherwise as a plain format code".

- **Branched validation.** Sharing a field is legal when every sharing rule
  carries a `when`, plus at most ONE rule without (the else branch, firing
  only when no condition is true). The new pure `php/Branching.php` resolves
  sharing at config-build time into explicit per-field branch rules that the
  client engine, the server audit, and the saved-value snapshot all consume —
  its docblock is the normative semantics spec. Runtime: the branch whose
  condition is true validates; none + no else = the field is inert; MORE than
  one true = a "Validation conflict" notice naming both conditions, nothing
  validated, the save never blocked, and an `uvalidate-unconfigurable` entry
  (never silent). Illegal sharing (two unconditional rules, byte-identical
  conditions, single+pooled mix) is a configuration error — in the Configure
  dialog it is rejected at save time naming the rows. Both engine factories
  now build their whole mode-resolution closure per VARIANT (an internal
  `makeVariant` seam; a plain rule is exactly one variant, so single-rule
  configs take byte-identical code paths). `blockSave` and `suggestFix` are
  per branch; the submit guard now skips items whose ACTIVE mode is "off".
  One field annotation may carry several `@UVALIDATE` tags
  (`extractTags`/`parseFieldAll`/`groupMulti`; the single-tag forms delegate,
  so nothing existing changed), and dialog + annotation rules may legally
  share a field cross-channel. New `tests/branching_php.php` and
  `tests/branch_dom_js.cjs` implement the same scenario table on both sides;
  `tests/hook_php.php` drives branch selection through the full audit.
- **Renamed** to **"Universal Regex & Check-Character Validator — IDs, codes &
  patterns"** (module list, Configure dialog, action-tag help, page notices,
  docs). The PHP namespace, the `INSPIREUniversalValidator` JS global, and the
  `inspire-validator-config` node are deliberately unchanged — nothing
  installed breaks.
- **Check-character hints are now OFF by default and configurable.** The
  "should end in X" tail on a check mismatch (`suggestFix`) revealed the
  expected check character, which can entice staff to force-fit a mistyped ID
  instead of re-scanning it. It previously had NO off switch; now it is a
  per-rule opt-in in all three channels (dialog checkbox, `"suggestFix":true`
  JSON key — strict boolean), default off. The progressive "what's still
  missing" format guidance is unchanged (it reveals the shape, never the
  answer).
- **Pooled chip severity colors corrected.** What read as "errors are yellow"
  was leftover-junk chips (amber) — actual invalid members were always red,
  and DUPLICATES shared that same red. Now hard problems are red (invalid ✗
  AND junk ?) and a repeat-scan of a VALID ID is amber ⊗ "(again!)" — a
  warning, not an error. Both color pairs were already WCAG 2.2 AA
  (amber 5.7:1, red 4.9:1) and every state keeps its non-color mark. New
  `tests/pooled_dom_js.cjs` locks the severity mapping.

## 0.8.0 — conditional validation: the `when` rule key

## 0.8.0 — conditional validation: the `when` rule key

A rule can now carry an optional REDCap-style condition and validates only
while it is true — `@UVALIDATE={"algorithm":"verhoeff","when":"[specimen_type]='2'"}`,
or the new "Only validate when" box on each Configure-dialog rule. A false
condition makes the rule inert in the browser (message cleared, a *Compulsory*
block never traps the save) and skips it in the server audit; it does NOT erase
the value (unlike REDCap field branching).

- **New `php/Logic.php` — the normative dialect spec.** A deliberate subset of
  REDCap logic: `[field]` and `[checkbox(code)]` references, quoted/number
  literals, `= <> != > < >= <=`, `and/or/not`, parentheses. Functions
  (datediff…), smart variables, `[event][field]` prefixes, arithmetic and
  piping are rejected when the rule is saved — with an error naming the
  construct — instead of misbehaving later. Caps: 500 chars, 20 refs, 10
  nesting levels. Comparisons are numeric iff both resolved sides are numeric
  (own regex — no `is_numeric`/`Number()` leniency where PHP and JS disagree),
  else exact case-sensitive string comparison; missing/empty refs read `''`,
  checkbox refs read `'1'`/`'0'`.
- **Cross-runtime parity, same discipline as everything else.** A JS twin
  (`QRID_when*`, exposed as `whenLogic` on the namespace) lives in the
  module-authored layer of `js/engine.js`; the hand-curated
  `tests/when_fixture.json` locks parse errors, verdicts, referenced-field
  extraction and the caps across both runtimes via `tests/when_js.cjs` +
  `tests/when_php.php` (both in CI, which also lints and ships the new PHP
  file).
- **Live browser gate.** Referenced fields on the page react as they are
  edited (dropdowns, radio groups via REDCap's hidden input, checkbox options
  by `__chk__` name), shared listeners re-check every gated field on a flip,
  and refs to fields on other instruments resolve from a saved-value snapshot
  (`whenValues`) baked into the page config — checkbox maps are cast to JSON
  objects so sequential codes (0,1,2…) cannot degrade into arrays. A condition
  that cannot be evaluated fails OPEN (validation skipped, reason on the
  console) so a save is never trapped by a gate bug; `tests/when_dom_js.cjs`
  drives all of it through the DOM stub.
- **Server audit honors the condition.** The one-getData read now includes
  condition refs (never instrument-filtered), checkbox arrays survive
  `readValues` (`$keepArrays`), and `auditRule` skips a false-condition rule
  entirely — with the unconfigurable-log fallback if the stored condition can
  ever not be parsed. Page hooks thread record/event/instance context into the
  config build for the snapshot.
- **All three channels, one validator.** `when` joins `@UVALIDATE`'s JSON keys,
  the dialog (`rowsFromFlatSettings`/`settingRowToRule`), and fast entry;
  `checkFragment` validates the syntax for every channel, and the
  dictionary-dependent checks (field exists; checkbox needs a real `(code)`;
  no file/descriptive refs) run wherever the data dictionary is available —
  including the save-time `validateSettings` gate. Identical tags still group;
  tags differing only in `when` split into separate rules.
- **Docs.** README "Conditional validation" section (dialect table, live vs
  snapshot, the no-erasure difference from field branching, calc-ref liveness
  caveat), INSTALL.md, a manual `when` checklist in TESTING.md, and the
  js/README.md deviation list.

## 0.7.1 — post-review corrections for the weighted-modulus family

## 0.7.1 — post-review corrections for the weighted-modulus family

Closes every finding from the 2026-07-13 multi-agent adversarial review of
0.7.0 (7 dimensions, each finding independently refutation-tested by executing
all four runtimes). No engine math changed; the review confirmed 0 blockers.

- **Docs — `weighted_mod11` detection claim scoped honestly.** The linear
  ISBN-10 weighting puts weight 11 ≡ 0 (mod 11) on the 10th-from-right digit,
  so a substitution there is invisible for payloads of 10+ digits. The blanket
  "catches every single-digit error" claims in the README, the engine module
  docstring, and the 0.7.0 entry's "detection-equivalent to `iso7064_mod11_2`"
  wording are all corrected to state the ≤9-digit boundary; the
  `WeightedModulus` docstring now documents the blind positions explicitly.
- **Dropdown help states the domain.** The `weighted_mod11` choice now says
  "full strength only up to 9 digits — prefer Mod 11,2 for longer IDs" and the
  `mrz_mod10` choice says "digits ONLY … not for letter-bearing MRZ fields",
  closing the two label-clarity findings.
- **Fixture now locks the weighted validate/append path.** The cross-runtime
  contract gains all four weighted schemes through their real `digits_only`
  config plus a dedicated `weighted_mod11` `X`-check-tail group (valid mint,
  revalidate, and a hand-tampered `TBX-00007X` that must fail), so the
  peel-check-then-extract-source order can no longer drift in any single
  runtime unnoticed. 918 rows total (332 scheme_ops, up from 219); the same
  rows now also run under Excel/VBA (879 assertions) and the playground
  self-test (1592 checks), whose embedded fixture copy was refreshed.
- **New `tests/explain_js.cjs` (wired into CI).** Asserts
  `explain(payload).check === compute(payload)` for every fixture row, porting
  the playground's explain-vs-compute guard so the vendored derivation tracer
  cannot silently drift from the verified engine.
- **`js/engine.js` header catalog completed.** The four weighted schemes are
  documented in the in-file algorithm reference (they were present in the
  engine and the upstream snippet catalog but missing from this file's
  header), each with the "digit-only — use source digits_only for
  letter-bearing IDs" note.
- Studio (sibling repo): the four new preset example strings are recomputed
  over the same `00239` base every other preset uses, matching what the
  generator actually mints.

## 0.7.0 — four widely-used weighted-modulus check schemes

Adds four digit-only check-character methods so the validator can mint and verify
IDs that use the check schemes already embedded in common external identifiers:

- **`gs1_mod10`** — GS1 Mod-10 (weights 3,1 from the right): GTIN/EAN/UPC, GLN, SSCC.
- **`aba_mod10`** — US ABA routing-number Mod-10 (weights 3,7,1 from the left).
- **`mrz_mod10`** — ICAO 9303 machine-readable-zone Mod-10 (weights 7,3,1, no complement).
- **`weighted_mod11`** — ISBN-10 weighted Mod-11 (may emit `X`).

The three Mod-10 schemes catch every single-digit error at any length but miss
adjacent swaps of digits differing by 5. `weighted_mod11` matches
`iso7064_mod11_2`'s detection only up to 9 digits, the ISBN-10 domain — at 10+
digits the position carrying weight 11 goes blind to substitutions (prefer Mod
11,2 or Mod 97,10 for longer numbers, which stay strong at any length). It is
provided for compatibility with externally-minted ISBN-style IDs, not for extra
strength. *(Wording corrected in 0.7.1: this entry originally called it
"detection-equivalent", which holds only for payloads up to 9 digits.)*

- One data-driven engine: a single `WeightedModulus` primitive parameterised by a
  `WEIGHTED_SCHEMES` table (weights, modulus, direction, complement, alphabet) in
  each runtime, so a future scheme is one table row, not new code. Kept in step
  with the Python source of truth (`qrcode_generation/check_characters.py`) and the
  JS/VBA ports through the shared `check_fixture.json` (now 574 compute rows across
  15 algorithms; 805 rows total).
- Selectable from the settings dropdown and via `@UVALIDATE` shorthands (`gs1`,
  `gtin`, `ean`, `upc`, `aba`, `routing`, `mrz`, `icao`, `isbn`, `mod11w`).
- New completeness guard: `tests/algorithm_coverage_js.cjs` and
  `tests/algorithm_coverage_php.php` assert the algorithm set is identical across
  the fixture, the JS registry, the PHP engine, `AnnotationRules::ALGORITHMS`, and
  the `config.json` dropdown, so a half-wired algorithm (added to some surfaces but
  not others) fails CI. Both are wired into `.github/workflows/parity.yml`.

## 0.6.0 — algorithm-name shorthands (ease of use)

Configuring a rule no longer requires typing the full internal algorithm name.
The `algorithm` value now accepts case-insensitive shorthands in both
configuration channels — `@UVALIDATE=3736` (or `37,36`, `mod37_36`) resolves to
`iso7064_mod37_36`, `9710` to `iso7064_mod97_10`, `112` to `iso7064_mod11_2`,
`mod10` to `luhn`, `regex`/`format` to `none`, and so on for each method.

- Single source of truth: `AnnotationRules::ALGORITHM_SYNONYMS` (canonical name →
  list of shorthands) plus `AnnotationRules::canonicalAlgorithm()`. Shorthands are
  resolved server-side wherever a raw algorithm string enters a rule (the
  `@UVALIDATE` bare and JSON forms, and the settings dialog), so the check-character
  engine, the server-side audit, and the browser all receive the canonical name —
  no second synonym table in the JavaScript engine to keep in sync.
- Unknown values are still rejected with the existing "unknown check algorithm"
  error, and full names keep working everywhere. Documented in the `config.json`
  action-tag help, the README (with a shorthand table), and `docs/INSTALL.md`.
- Tests: `tests/annotation_php.php` gains shorthand-resolution, case-insensitivity,
  and a maintenance guard that fails if a shorthand ever collides with a canonical
  name or another shorthand; `tests/hook_php.php` proves a shorthand annotation is
  audited server-side under its canonical name.

## 0.5.1 — polynomial-ReDoS gate closure

Addresses `reports/predeployment-adversarial-review-2026-07-12b.md`, the second
independent pass, which re-verified 21 of 22 code findings from `0.5.0` as
genuinely fixed and re-opened one: **SEC-001R**.

Security
- **SEC-001R:** the regex safety gate now rejects the *polynomial* backtracking
  class it previously let through — two or more unbounded quantifiers (`*`, `+`,
  `{n,}`) over overlapping character classes with no mandatory separator between
  them (`.*.*`, `[0-9]*[0-9]*`, `[A-Z]+[A-Z0-9]+`, `A*A*A*9`, and repeated
  ungrouped/collapsed atoms such as `(abc)+(abc)+`). The `0.5.0` gate caught only
  the exponential shapes; the polynomial ones passed every configuration channel
  (settings dialog and `@UVALIDATE`) and, because a browser's backtracking engine
  has no runtime backstop, froze the tab on ordinary form and survey input —
  measured at ~20 s for `.*.*.*.*.*b` at a 200-character value, and unbounded at
  the 512-character field cap. PCRE2 auto-possessifies the same patterns and was
  never affected, which is why the client-only exposure was missed. The new
  second stage (`QRID_polyOverlap` / `CheckCharacter::polynomialOverlap`)
  tokenizes the already-group-collapsed pattern and refuses the
  overlapping-unbounded shape while still admitting genuinely-linear patterns —
  disjoint adjacent classes (`[A-Z]+[0-9]+`) and a mandatory separator (`.*x.*`).
  A flagged pattern is never compiled on the client, so it can never run. The JS
  and PHP twins stay behavior-identical, locked by an expanded
  `tests/risky_patterns.json` that now covers the polynomial class in both
  runtimes. Chosen over a bundled linear-time client engine (RE2/`re2js`) to keep
  the module a build-free, dependency-free vendored script.

Tests and docs
- `tests/risky_patterns.json` gains the polynomial-overlap cases in `risky` and
  two linear precision-guarantee cases (`[A-Z]+-[A-Z]+`, `.*x.*`) in `safe`.
- The residual class the gate deliberately still passes is now a *bounded*
  backtracker (`A{1,40}A{1,40}A{1,40}9`, work capped by the pattern rather than
  the input length); `risky_php.php` and `hook_php.php` use it — instead of the
  now-gated `A*A*A*9` — to keep exercising the server's match-time PCRE-error
  guard.
- Config-error messages (dialog + `@UVALIDATE`), `config.json`, `docs/INSTALL.md`,
  `docs/TESTING.md`, `js/README.md`, and `tests/README.md` now describe both gate
  stages and no longer imply the input caps bound regex match time (LOW-02).
- **LOW-03:** the JS fallback config `strip` default (`-/ _|`) now matches the
  PHP default and the `config.json` help text (`-/ _|\`). Production always
  receives the PHP-built config, so this only affected the test-harness fallback.

The three standing release-gate blockers (public SemVer tag, REDCap security
scan, live REDCap/browser/screen-reader matrix) remain people-work, tracked at
the top of `docs/TESTING.md`. LOW-04 (cross-save log deduplication) remains
disclosed future work.
## 0.5.0 — predeployment-review hardening

Addresses `reports/predeployment-adversarial-review-2026-07-12.md` (4 blockers,
7 high, 11 medium, 4 low). Every code-addressable finding is fixed here; the remaining
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
