# Universal Field Validator — check-character & regex IDs, cross-field rules, uniqueness & dynamic choices (REDCap external module)

**Current release candidate: `v2.3.0-rc.2`.** See the [changelog](CHANGELOG.md) for features, case-comparison migration guidance, scan-storage changes, and verification limits.

**Opt-in event and repeat validation:** see [Event and instance references](docs/EVENT-INSTANCE-REFERENCES.md) for qualified references, matching, collections, dates, record-local uniqueness, activation and rollback.

Live, as-you-type validation for any REDCap field with a structure. A mistyped
participant ID costs hours of reconciliation later; this module catches it while
the person who typed it is still looking at the field.

Documentation: <https://nghareformer.github.io/redcap-universal-validator/> — one page per action tag.

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
- **Several ID formats in one field** — a rule may declare a list of
  `alternates`, each with its own pattern and its own check algorithm (or
  `none`). A value, or a token inside a pooled box, is valid if any one of them
  accepts it — so a shipment holding IDs from four studies, three of which carry
  a check character and one of which does not, validates in a single field
  without giving up the check character on the three that have one.
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

One rule = one kind of validation, applied to any number of fields. Every rule
kind — Single value / Pooled (check-character or regex), **Constraint**
(`@UVASSERT`) and **Required** (`@UVREQUIRED`) — is available in all three
channels; the dialog's "What this rule checks" selector picks the kind:

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

   **Several ID formats in one field.** Give the rule a list of `alternates`
   instead of one `pattern`/`algorithm`; a value is valid if any entry accepts
   it. Each entry carries its own `pattern` and `algorithm`, and a pooled rule
   also needs `lengths` on every entry so the parser knows where one member
   ends and the next begins:

   ```text
   @UVALIDATE={"type":"pooled","strip":"-","blockSave":"hard","alternates":[
     {"label":"GHIT",      "pattern":"FC[1-9]-[0-9]{4}",        "algorithm":"none","lengths":[8]},
     {"label":"START4KIDS","pattern":"SK[1-9]-[0-9]{4}[0-9A-Z]","algorithm":"3736","lengths":[9]},
     {"label":"DARETB",    "pattern":"DT[1-9]-[0-9]{5}[0-9A-Z]","algorithm":"3736","lengths":[10]},
     {"label":"SCREENTB",  "pattern":"ST[1-9]-[0-9]{5}[0-9A-Z]","algorithm":"3736","lengths":[10]}]}
   ```

   Entry keys: `pattern` (required), `algorithm`, `source`, `strip`, `lengths`,
   `label`. Anything not given falls back to the rule-level value. The `label`
   names the family in messages and on the chips. Up to 8 entries and 32
   format/length combinations per rule; when `alternates` is used the
   rule-level `pattern`, `idLengths`, `idMinLen` and `idMaxLen` must be left
   unset, because each entry carries its own.

   Check-bearing entries own their shape. A value that matches the pattern of
   any check-bearing entry must pass that entry's check character; a
   format-only entry (`"algorithm":"none"`) is tried only when no check-bearing
   pattern matches, whatever the declaration order. A format-only entry may
   share a length with a check-bearing one as long as their patterns cannot
   match the same value, as with `C`-`Z` codes that carry a check character
   beside legacy `A`/`B` codes that do not, all six long.

   Some combinations are refused when the rule is saved rather than guessed at
   later: lengths where one is the sum of two or more others (one token could
   then swallow several real members and still verify), a format-only pattern
   that accepts a value a check-bearing pattern also accepts, and entries that
   disagree about which characters survive cleaning. The overlap refusal is
   needed even with shape ownership: a mis-scan that breaks the check-bearing
   shape (an `O` for a `0`, a dropped digit) would otherwise land in the
   overlapping format-only shape and pass on format. The overlap test is exact
   for ordinary patterns (classes, groups, alternation, quantifiers); a
   backreference, or lookaround it cannot settle, makes the rule refused rather
   than assumed safe. In a pooled field it compares only entries that share a
   declared length, since the parser never tries an entry at another length.
   Length separation does not stop an inserted or dropped character from
   turning a check-bearing ID into a valid-looking ID of a format-only family of
   the neighbouring length. Each message names the entry and the fix.

   **Patterns must be portable.** They run as JavaScript in the browser and as
   PCRE on the server, so syntax the two engines read differently is refused
   when the rule is saved: `\pL` and `\p{L}`, `\K \G \h \v \R \N \X \C \Q...\E
   \g \z`, POSIX classes such as `[[:digit:]]`, inline flags `(?i)` `(?x)`,
   comments `(?#...)`, atomic groups `(?>...)` and an empty class `[]`.
   Explicit classes, `(?:...)`, lookahead, lookbehind and `(?<name>...)` all
   work.

   JSON keys: `type`, `algorithm`, `source`, `pattern`, `alternates`, `strip`,
   `keepChars`, `idLengths`, `idMinLen`, `idMaxLen`, `expectedIds`, `blockSave`,
   `when`, `suggestFix`, `note`, `caseSensitive`. A malformed tag shows a configuration error under
   that field — never a silent no-op. Fields with identical tags are grouped into
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
| `=` `<>` `!=` `>` `<` `>=` `<=` | `[event][field]` prefixes, unless extended references are on |
| `and` / `or` / `not` in any letter case, parentheses | `&&`, `\|\|` and `!` (write `and`, `or`, `not`) |
| | arithmetic and piping |

One condition may mix `and`, `or` and `not`. `not` binds tightest, then `and`,
then `or`, as in REDCap; parentheses change the grouping. An unquoted
`@UVASSERT` or `@UVREQUIRED` condition is read whole, up to the end of the line or the next action tag.

Semantics worth knowing before relying on it:

- **Comparisons are numeric when both sides look numeric** (`[age]>'9'` with
  age `10` is true, not a lexicographic accident), and string comparison when
  neither side does. **Text ignores letter case by default** (2.1.0-rc.1):
  `[status]='active'` is true for `Active` and `ACTIVE`, in a `when`, in a
  branch selector and in an `@UVASSERT` test alike. Add `"caseSensitive":true`
  to any tag's JSON (or tick "compare text case-sensitively" in the Configure
  dialog) when case carries meaning; the flag covers every condition of that
  rule. Only the letters A-Z are folded, so accented letters keep their case
  (`É` still differs from `é`): the browser and the server lowercase other
  letters differently, and both must reach the same verdict. When the two sides are in
  **different domains** — one numeric, one not, both non-empty — `=` and `<>`
  still answer by string identity, but `<` `>` `<=` `>=` are false whichever way
  round you ask: ordering across domains produced cycles (`'2' <= '10'`,
  `'10' <= '1e1'` and `'2' > '1e1'` were all true at once). An **empty** side is
  different again: empty is absence, so an ordered comparison against it has no
  answer, and what that means depends on which question is being asked. In an
  `@UVASSERT` test a value nobody has entered cannot violate a constraint, so
  the comparison passes: `[end]>=[start]` and `[start]<=[end]` both hold while
  `start` is blank, and so does `not([end]<[start])`. In a `when` gate or a
  branch selector it means the module cannot tell whether the rule applies, so
  the rule stays inert: `when:"[age]<=18"` does not fire on a record whose age
  is blank. `=` and `<>` are unaffected either way — `[field]<>''` is still how
  you ask whether a field has been filled in. A missing or empty field reads as
  `''`; a checkbox reference reads `'1'`/`'0'`.
- **Referenced fields on the same instrument react live**: pick the right
  dropdown option and the gated field's verdict appears or clears immediately —
  including a *Compulsory* save block. A calc field updates without DOM events,
  so a calc ref refreshes at the next event on any watched field — the manual
  check for this is in [`docs/TESTING.md`](docs/TESTING.md).
- **Fields on other instruments** are resolved on the server against the record's
  saved values, since such a field cannot change while this page is open. What
  the page then receives depends on the comparison:
  - Against a **literal** (`[baseline_eligible]='1'`) there is no live side, so
    the whole comparison is settled here and sent as a `true`/`false` — correct
    as of page load, and nothing on this page can change it.
  - Against a field **on this instrument** (`[end_date]>=[start_date]`) the
    comparison is kept **live** (1.6.0): the off-page value is baked in and the
    browser re-checks as you type. This requires you to be entitled to read that
    instrument — authenticated data entry, with REDCap rights to it. On a survey,
    or without those rights, the value is withheld and the rule is **deferred**
    (no verdict, never blocks — what that costs is spelled out under `@UVASSERT`
    below).

  So the page carries field names, your own literals, booleans — and, only in the
  live case, values you already have the right to read. A survey respondent, or a
  user without rights to that instrument, never receives one. A brand-new record
  has no saved values yet, so such refs resolve as `''`.
- **A reference the module cannot resolve is refused, not guessed** (1.6.0).
  Until then an unreadable reference looked exactly like a genuine blank, and the
  rule was checked against a `''` that had never been read. Three cases are now
  detected positively:
  - **A different repeating instrument.** Instance 3 of form A has no defined
    counterpart in form B, so a reference across two independently repeating
    instruments is treated as a configuration problem: the rule stops checking
    and says why. Everything else still resolves — a repeating form reading a
    non-repeating one, the event's base row, two fields in the same repeating
    instrument, and repeating **events**.
  - **A different event.** Where the module can read the project's
    instrument-event mapping, a reference to a field on a form that is not
    designated for the event being saved is refused the same way. Where that
    mapping cannot be established (a classic project, or a REDCap build that does
    not expose it), the reference still reads as empty — keep both fields in the
    same event.
  - **A read that failed.** A `getData` error or a malformed result defers the
    rule instead of treating the missing value as blank.

  In each case the browser shows no verdict and never blocks, and the reason
  names the field and the fix. For `@UVASSERT` the server reports it too: a
  `uvalidate-unconfigurable` module-log entry after a save, and a *Rule problems*
  line on the Validation scan page.
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
  when-less rules on one field; two rules whose conditions say the same thing,
  however written (keyword case, spacing, quote style, parentheses, the order
  of `and`/`or` members and of a comparison's sides aside); a single-value rule
  and a pooled rule sharing a field.
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

## Cross-field constraints — the `@UVASSERT` tag

Stock REDCap cannot *block* a bad relationship between fields at entry: branching
only hides, a range check only warns, and Data Quality runs in batch. `@UVASSERT`
closes that gap — the field is invalid unless a condition holds, checked live and
enforced with the same message/confirm/block modes as everything else:

```text
@UVASSERT="[end_date]>=[start_date]"
@UVASSERT={"assert":"[dose]<=[max_dose]","message":"Dose exceeds the protocol maximum","blockSave":"hard"}
@UVASSERT={"assert":"[sex]='2'","when":"[pregnant]='1'","message":"Pregnant participants must be recorded female"}
```

- The condition uses the **same dialect** as `when` (`[field]`/`[checkbox(code)]`
  refs, `= <> != > < >= <=`, `and`/`or`/`not`, parentheses). ISO dates and numbers
  compare correctly; the evaluator is the one parity-locked engine, used here as
  the *test* rather than a gate.
- **An empty field is inert** — requiring a value is `@UVREQUIRED`'s job, not a
  constraint's. Confirm-a-value ("type it twice") is just `@UVASSERT="[id]=[id_confirm]"`.
- **Text is compared without regard to case** (2.1.0-rc.1), like every condition in
  the module: `@UVASSERT="[answer]='yes'"` accepts `Yes` and `YES`. Add
  `"caseSensitive":true` when case carries meaning, such as IDs where `ab12` and
  `AB12` are different: `@UVASSERT={"assert":"[id]=[id_confirm]","caseSensitive":true}`.
  The flag covers both the `assert` and the `when` of that tag.
- `message` is your own wording, shown on failure (a generic line is used if you
  omit it — recommended to set one, since only you can word an arbitrary relationship).
  Under Multi-Language Management it may be a map of language id to text,
  `{"default":"End is before start","fr":"La fin précède le début"}`, on this tag
  and every other tag that takes a `message`; the page shows the language on screen
  ([Messages in several languages](docs/action_tag_validation_examples.md#messages-in-several-languages)).
- Optional `when` enforces the constraint only while a condition is true; several
  `@UVASSERT` tags with different `when` conditions **branch** (plus at most one
  without, as the fallback), exactly like `@UVALIDATE`.
- Works on **Text, Notes, dropdown, radio, yes/no, true/false, calc and slider**
  fields (check-character/regex validation stays Text/Notes). A field may carry
  `@UVASSERT` **alongside** `@UVALIDATE` and other modes — they **compose**, and
  all must pass; each keeps an independent save-block state.
- The server audit honors the constraint against saved values (logged as
  `type: constraint`).
- **Across instruments (1.6.0) — live, but ADVISORY.** The condition may reference a
  field on another instrument in the same event. When you are entitled to read that
  field — staff data entry, with REDCap rights to its instrument — its value is
  resolved on the server and baked into the condition, so the check stays **live**
  as you type. It does **not** block the save, at any `blockSave` setting: that
  value is read once, when the page opens, and nothing can refresh it while you
  type, so a concurrent edit on the other form would otherwise produce a wrong
  block, or a wrong pass, with no way to tell which. A failure names the off-page
  field and says when it was read, so you know to reload; the post-save audit and
  the Validation scan are the enforcement record. Survey respondents see the plain
  message, without field names.
- **Rules whose fields are all on THIS instrument are unaffected** and still block
  exactly as before — both sides are live in the form, so nothing is a snapshot.
- **Deferred means detection, not prevention.** On a **survey**, or for a user
  **without rights** to the referenced instrument, the value is never sent to the
  page (SEC-005) and the rule is **deferred**: the browser shows no verdict and
  never blocks, so the save is **accepted**. `redcap_save_record` fires *after*
  the write, so the audit logs the violation to the module log once it has
  happened, and the Validation scan can find it later. Deferring buys privacy at
  the cost of both live feedback and the block — someone has to read the log or
  run the scan.
- **A reference the module cannot resolve is refused** (1.6.0) — a field on a
  different repeating instrument, a field not collected in this event, or a read
  that failed. The rule stops checking, and the reason names the field instead of
  comparing against a blank that was never read; see the `when` section above for
  the three cases and what still resolves normally.

## Conditional required — the `@UVREQUIRED` tag

REDCap's own required flag is unconditional and only warns. `@UVREQUIRED` adds
the two things it lacks: a **condition** and a **real block**:

```text
@UVREQUIRED                                              always required
@UVREQUIRED="[consent]='1'"                              required only while consented
@UVREQUIRED={"when":"[consent]='1'","message":"Phone needed for consented participants","blockSave":"hard"}
```

- A blank (or whitespace-only) field shows the notice while the requirement is
  in force; the requirement turns on and off **live** as referenced fields
  change. Filling the field clears the notice — deliberately no green "OK",
  because required mode never judges the *value*. Pair it with `@UVALIDATE`
  or `@UVASSERT` for that (modes compose, each with its own save-block state:
  on a blank field only `@UVREQUIRED` fires; on a filled-but-wrong value only
  the value checks fire).
- Works on **Text, Notes, dropdown, radio, yes/no, true/false and slider**
  fields. Not calc — the person entering data cannot fill a calc, so requiring
  one would trap them (the same reasoning as the read-only exemption: a
  read-only field shows the notice but never blocks the save).
- Several `@UVREQUIRED` tags with different `when` conditions **branch**; two
  conditions true at once is a visible conflict that never blocks.
- The server audit logs a blank-while-required save as `type: required`,
  `reason: required-blank` (a blank carries nothing identifying, so this entry
  is safe in every privacy mode).

## No duplicates across records — the `@UVUNIQUE` tag

REDCap has no native field-level uniqueness. `@UVUNIQUE` checks the value
against every other record **as it is typed** (a CSRF-protected module AJAX
call — no page reload), with the usual message/confirm/block enforcement:

```text
@UVUNIQUE                                                unique across the project
@UVUNIQUE=event                                          scope: project | dag | event
@UVUNIQUE={"with":["site"],"message":"Specimen already registered","blockSave":"hard"}
@UVUNIQUE={"also":["study_id_scan"]}                     also look in other fields
```

- `with` makes the key **composite** — the value plus those fields together
  must be unique (a specimen ID within its site). Scopes: `project` (default),
  `dag` (unique within each Data Access Group), `event` (within the same
  event of a longitudinal project).
- `also` looks for the value in up to 5 other fields too. With
  `@UVUNIQUE={"also":["study_id_scan"]}` on `study_id_typed`, an ID typed there
  counts as used when another record holds it in `study_id_typed` or in
  `study_id_scan`. Scope and `with` apply to a match in an `also` field the same
  way: the `with` values must match in the same entry as that field. The check
  runs one way: to stop a scanned ID that was already typed in another record,
  put `@UVUNIQUE={"also":["study_id_typed"]}` on `study_id_scan` as well. The
  record being checked is never compared with itself, so a value typed in both
  fields of one record is not a duplicate; use `@UVASSERT` for that
  (`{"assert":"[study_id_scan]=''"}` on the typed field allows typing only when
  nothing was scanned). Each `also` field must exist, hold one value, and store
  values the way the field does: both text, both dates of the same kind, or both
  numbers with the same decimal mark. Every `@UVUNIQUE` tag on one field must list
  the same `also` fields, and `also` cannot be used with `"scope":"record"`.
  A placement that could never match is refused when the rule is read: an
  `also` field and a `with` field on two different repeating instruments, an
  `also` field on an instrument no event collects, or, under `"scope":"event"`,
  an `also` field in no event with the tagged field. Staff are told which field
  the value was found in, unless the record is in another Data Access Group;
  they get an answer only when they may open the forms that hold the `also`
  fields. The list of `also` fields is not sent to the page. Each `also` field
  adds entries to the Validation scan's duplicate groups, one per tagged field
  and per `with`/scope/letter-case combination of the tag's branches.
- **Under `dag`, records in no group form one group of their own.** They are
  compared against each other, not exempted: "no DAG" is a scope like any other,
  and the alternative reading — that an ungrouped record has nothing to be
  compared with — would let the rule lapse exactly where records are hardest to
  attribute. Use `project` scope if you want them compared against everything.
- **Privacy posture.** The endpoint answers only for fields carrying a unique
  rule (it cannot be used to probe arbitrary fields for value existence).
  Staff see the colliding record id only when it is inside their own DAG.
- **What counts as the same value.** Stored values, trimmed, with letter case
  ignored (`SP-1` duplicates `sp-1`) unless the rule sets `"caseSensitive":true`.
  In a field that holds numbers (an integer or number Text field, or a slider)
  values compare by value, so `007` duplicates `7`. `@UVEXISTS` compares the same
  way. Up to 2.1.0-rc.2 the comparison was exact: a project that holds both
  spellings of a value gets duplicate findings after the upgrade.
- **Surveys: opt-in, and never on an identifier.** Survey respondents are not
  logged in, so an "already used" answer tells anyone holding the survey link
  that a specific value is in the study — one value at a time. `{"surveys":true}`
  is therefore off by default, answered as a bare yes/no (never a record id),
  rate-limited, and **refused outright on any field REDCap flags as an
  Identifier** — there it would let a stranger test whether a named person is
  enrolled, so the module makes that a configuration error rather than trusting
  a warning to be read. The same refusal applies when a `with` or `also` field
  is an Identifier, and with `also` every `also` field must sit on the tagged
  field's own instrument, so a respondent is only told about fields of the
  survey they are filling in. Reasonable use: a non-identifying response token, to
  stop the same person submitting twice. Leaving it off costs no data quality —
  survey submissions are still covered by the post-save audit and the scan.
- **The race is audited, not denied.** Two near-simultaneous saves can both
  pass the live check; the post-save audit re-checks the saved value against
  every other record and logs a collision (`type: unique`,
  `reason: duplicate-value`) — review the module log for races. A failed check
  fails **open**, so a network error on a first check never traps a save. When
  Save asks again about an "already used" the page has held for 30 seconds or
  more and gets no answer, that earlier answer stands and is enforced.
- Works on Text, Notes, dropdown, radio, yes/no, true/false and slider fields;
  composes with the other modes on the same field.

## Dynamic choice filtering — the `@UVCHOICES` tag

REDCap's `@HIDECHOICE` hides options **statically**; `@UVCHOICES` shows or
hides individual options of a **radio, dropdown or checkbox** field based on
the live values of other fields — a country → region → site cascade in one
field instead of one near-duplicate field per country:

```text
@UVCHOICES={"when":"[country]='1'","show":["101","102","103"]}
@UVCHOICES={"when":"[country]='2'","show":["201","202"]}

@UVCHOICES={"when":"[legacy_entry]<>'1'","hide":["9"]}

@UVCHOICES={"when":"[pilot(1)]='1'","show":["s01","s02"],
            "message":"Only pilot sites during the pilot phase.","blockSave":"hard"}
```

- **JSON form only.** Exactly one of `show` (whitelist — every other code of
  the field hides) or `hide` (blacklist) per tag, plus optional `when`,
  `message`, `blockSave`. Codes must exist in the field's own choice list — an
  unknown code is a configuration error naming the real codes.
- **Branches like every other mode.** Repeat the tag with different `when`
  conditions (plus at most one without, as the fallback). Exactly one true
  condition filters; none (and no fallback) shows everything; more than one
  true is a visible conflict — the filter is **not** applied and the save is
  never blocked on a configuration problem. Conditions may reference fields on
  other instruments; those are resolved server-side against saved values
  (nothing off-page leaks into the browser).
- **A hidden selection is never cleared.** If the stored/selected choice
  becomes hidden (the user changes the country after picking a site), the
  module keeps it visible — dropdowns keep the option in place, greyed and
  marked `data-uv-stale`, but still enabled so the browser submits it —
  flags the field invalid with your `message`, and applies `blockSave`
  (off/confirm/hard). Silently erasing an entered value is the one thing this
  mode refuses to do; fix it by picking one of the shown choices.
- **Out-of-list values are out of scope.** A value that is not in the field's
  choice list at all (e.g. a missing-data code like `-99`) is never flagged.
- Works on radio, dropdown and checkbox fields; **not** yes/no, true/false,
  sql, or matrix fields (matrix rows render different markup — the tag is
  refused there rather than half-working). Dropdown filtering physically
  removes and re-inserts `<option>`s because Safari ignores CSS hiding on
  options. Configure via field annotation only in this version.
- **Audited like everything else.** A save that lands a hidden choice (API,
  import, a race) is logged by the post-save audit as `type: choices`,
  `reason: hidden-choice`, and the Validation scan reports it retrospectively.

## Dates within a window — the `@UVWINDOW` tag

REDCap's date validation takes a fixed minimum and maximum and only warns.
`@UVWINDOW` checks a date against a window counted from another date of the same
record, from today or from a fixed date, or against a calendar period, and/or
that it is not after today or not before it:

```text
@UVWINDOW={"from":"[visit_date_bl]","window":[21,35]}
@UVWINDOW={"from":"[dob]","window":[0,null],"notFuture":true}
@UVWINDOW={"from":"[dose_given_at]","window":[0,6],"unit":"hours","blockSave":"hard"}
@UVWINDOW={"notFuture":true}
@UVWINDOW={"notPast":true}
@UVWINDOW={"from":"today","window":[-7,0]}
@UVWINDOW={"period":"month","offset":-1}
```

- **JSON form only.** `window` is `[earliest, latest]` in whole units from the
  `from` date, both ends included; `null` leaves an end open and negative bounds
  count back. `unit` is `days` (default), `weeks`, `months` or `years`, and also
  `minutes` or `hours` on datetime fields. `from` is a field, `today`, `now` or a
  Y-M-D date. `period` (`week`, `month`, `quarter`, `year`) is the period holding
  today or the `from` date, moved by `offset`; weeks start on Monday unless the
  project setting "First day of the week for @UVWINDOW" or `weekStart` says
  Sunday. Optional `when`, `message`, `blockSave`.
- **Judged on the day it was saved.** A part counted from today or now, a period
  without `from`, and `notPast` are judged on the form only for a new or changed
  value, and after saving against the day REDCap's log shows the value was
  saved; a value the log does not show is reported as not checked. Use them for
  timeliness; count clinical windows from a date in the record.
- **Date fields only:** Text fields with date, datetime or datetime-with-seconds
  validation, in any display format. The `from` field must be the same kind of
  date. The message names the allowed dates in the field's own format.
- **Today is the server's date.** The page gets the server clock and moves it
  forward while it stays open, so a wrong computer clock cannot accept tomorrow's
  date and a form left open past midnight moves to the next day. A computer clock
  moved forward while the form is open moves it too; the post-save audit reads the
  server clock and still logs the date. The project setting **Time zone for
  @UVWINDOW "notFuture"** sets the time zone, when it differs from the server's.
- **Blank checks nothing.** A blank field, or a blank `from` date, is not
  checked; a date still being typed shows no verdict.
- **Other forms and events.** A `from` date on another form is read when the page
  opens and the window part is advisory (it never blocks); `notFuture` keeps its
  `blockSave`. On a survey, or without rights to that form, the value never
  reaches the page: the browser checks only `notFuture`, and staff see a note
  that the window is checked on save. With event and instance references
  enabled, `from` may name another event: `[baseline_arm_1][visit_date]`. Its
  window part is advisory on the page and `notFuture` keeps its `blockSave`. A
  `when`, or a branch selector, that reads a value from another form or event
  makes the whole rule advisory, `notFuture` included. On a multi-page survey, put the `from` field on the same page as the date it
  anchors, or the window is checked only after the save.
- **Audited.** The post-save audit logs `type: window` with reason
  `window-early`, `window-late`, `future` or `past`, and saving the `from` date's form
  re-checks the windows counted from it. The Validation scan reports the same
  findings. Configure via field annotation only.

## Values that must already exist — the `@UVEXISTS` tag

REDCap can check that a value has the right shape, not that it refers to
something that is real. `@UVEXISTS` checks, as the value is entered, that it is
already saved in the project: a record ID, or a value of another field such as
the specimen ID on the collection form.

```text
@UVEXISTS=record
@UVEXISTS=[specimen_id]
@UVEXISTS={"in":"[specimen_id]","event":"enrolment_arm_1","scope":"dag",
           "match":{"site_code":"[site]"},"blockSave":"hard"}
```

- `in` is `record` (a record ID of this project) or one field reference (a value
  saved in that field, in any record). `event` looks in one event, by its unique
  name. `scope` is `project` (default), `dag` (records of the same Data Access
  Group; records in no group form one group) or `event` (the event of the entry
  being checked). `match` looks only at saved entries whose target field equals
  a field of this record, `{"target":"[field of this record]"}`, up to 5 pairs;
  the target values must sit in the same entry as the searched value (one event
  row, or one repeat instance with its event row). Optional `when`, `message`,
  `blockSave`, `surveys`, `return` and `where` (below).
- **Comparison of stored values.** Trimmed, with A-Z letter case ignored
  (`sp-1` finds `SP-1`) unless the rule sets `"caseSensitive":true`. When the
  searched field holds numbers (an integer or number Text field, a calc or a
  slider), values compare by value: `7` finds `007`. A date is compared as
  REDCap stores it (Y-M-D), whatever format the field shows, so the searched
  field and the field itself must be stored the same way: both dates, both
  datetimes to the minute, both datetimes to the second, both times of the same
  precision, or neither a date nor a time. Two number fields must use the same
  decimal mark. Dropdown and radio values are compared by code.
  `@UVEXISTS=record` never finds the record being edited, and a record ID typed
  in another case is found after one read of every record ID.
- **Asked on change, not per keystroke.** The page asks the server when the value
  is entered or changed, and once when the form opens. Typing clears the
  previous answer, typing in a text field a `when` condition reads included.
  The answer is *found* (green), *not found* (red, enforced per `blockSave`) or
  *could not check* (amber, never blocks): a failed read, a `match` field that is
  blank on another form, or a busy server all answer *could not check*.
- **Save waits for the answer.** Clicking Save re-checks the field first. A value
  typed and saved at once is asked before the save is decided, and while an
  answer is on its way the save is held ("still being checked"). After 10
  seconds without an answer the value counts as *could not check* and the save
  goes through. The exception is a *not found* the page has held for 30 seconds
  or more, which Save asks about again: if that second question gets no answer,
  the earlier *not found* stands and is enforced.
- **Branches.** With several `when` branches, the page sends the values its
  conditions read, so the server answers from the branch the page is enforcing,
  also on a record not saved yet.
- **The page config never says where the rule looks.** `in`, `event`, `scope`,
  `match` and `where` stay on the server, which reads them from the stored rule. On a
  survey a misconfigured rule shows only a general notice, and the "no access"
  reason staff see does not name a form or field.
- **Who gets an answer.** Signed-in users are answered only when they may open
  every form the lookup reads (the searched field, the `match` fields and the
  `where` fields; a record-ID lookup without `where` needs no extra form), and at most 60 lookups a minute per
  session, shared with `@UVUNIQUE`. A user in a Data Access Group is answered
  only on records of their own group. Staff in no group see the record the value
  was found in; staff in a group see it only when it is in their group. A
  record-ID lookup never names a record.
- **Data Access Groups.** REDCap may confine a group user's reads to their own
  group. A *not found* from a rule that looks across groups is therefore kept
  only when the module sees records of other groups in the same request;
  otherwise the answer is *could not check*, and the post-save audit reports a
  rule problem instead of a violation.
- **Surveys: opt-in, and never on an Identifier.** `"surveys":true` enables the
  check on surveys, answered as found / not found only, under the survey
  throttle. It is refused when any field the lookup touches is an Identifier
  (the field, the `match` fields, the searched field, the `where` fields, or the
  record-ID field for a record-ID lookup), and when the Identifier flags cannot
  be read. A *not
  found* costs a read of the whole searched field, so survey lookups may cause
  at most 60 such reads a minute per project; past that they answer *could not
  check*, which a survey shows as nothing.
- **Audited.** The post-save audit logs `type: exists`, reason `not-found`
  (`filtered` for a value saved only in entries that fail `where`).
  Saving the form that holds a `match` field checks the lookup again. The
  Validation scan reads the searched field once per rule per scan request and
  checks every record against it. A lookup that cannot be completed is reported
  as a rule problem, never as a pass. A scan confined to one Data Access Group
  does not evaluate a rule that looks across groups (`scope` other than `dag`)
  and says so. A durable scan with `@UVEXISTS` rules claims coverage through its
  change fence only when no record changed during the run: an answer depends on
  other records, which the catch-up does not re-check.
- **Another project.** `"project":412`, or an alias such as `"project":"lab"`
  that this project's settings map to a project id, looks in another project on
  the same server. `in`, `event` and the `match` targets then name that
  project's fields and events. Three parties must agree. An administrator turns
  on "@UVEXISTS in other projects". The searched project enables the module and
  lists this project under "Projects that may look up values here", with the
  fields it may search or filter on and who gets an answer (only users with rights to those
  fields there, confined to their group there; or any signed-in user of this
  project, found / not found only, with no Identifier searched). Surveys are
  answered only when that project also allows it. Until its agreement
  passes, every problem shows one setup error and nothing of that project is
  read. Its record is never shown, unless a rule copies `record` and the
  agreement lists it. Each lookup, answered or refused, leaves a
  `uv-exists-probe` line in that project's module log, with the value as a
  keyed hash under its key. At most 30 lookups a minute per browser session
  into one project. One project answers at most 1,200 a minute for signed-in
  data entry, another 1,200 for post-save checks and scans, and 120 for survey
  respondents (the first two are Control Center settings), so one kind of
  caller cannot use up another's. Over any of them the answer is *could not
  check*, and the searched project's log gets one "throttled" line a minute.
- **Copy fields of the entry found (`return`).**
  `"return":{"lab_site":"[result_site]","record":"[source_record]"}` writes
  fields of the entry the lookup found (`record`: its record ID) into fields of
  this form, up to 10, from the same record, event and instance as the match.
  The rule owns them: found fills, *not found*, a blank value or a false
  `when` empties, *could not check* leaves them. Several entries with
  different values copy nothing and an amber line says so; a user in a Data
  Access Group gets values only from their own group. The page fills the
  fields for a user who may open every form the lookup reads, and the
  post-save audit, checking the same rights, writes the values that differ as
  the person who saved (the project setting "@UVEXISTS returned values" turns
  that write off; a survey respondent's save writes nothing). The setup is
  checked when the rule is saved: copied fields hold one value on the form of
  `in` or a form that does not repeat (in every event the entry can be found
  in), each field here can hold what the field there holds (choice codes, kind
  of date, decimal mark and places, validation), an Identifier goes only into
  an Identifier. Refused with `"surveys":true`. Another project must list
  the fields it lets others copy, under "Only users who have rights"; its
  probe log then names the fields the rule copies and, when values were
  handed over, the record they came from.
- **Only entries that meet conditions (`where`).**
  `"where":"[consent]='1' and ([status]<>'3' or [withdraw_date]>[coll_date])"`
  accepts the value only when the entry that holds it meets the condition. The
  condition takes the language of `when` (`and`, `or`, `not`, parentheses) and
  tests up to 10 fields of the entry found, against fixed values or each other;
  `match` stays the way to compare with this record. A value saved only in
  entries that fail it gets its own answer for staff (`whereMessage`, or "This
  value is saved, but not in an entry that meets this rule's conditions."),
  enforced as *not found*; a survey shows the plain *not found*. The audit
  reason is `filtered`. The `where` fields are fields the lookup reads, for
  rights, survey Identifier checks and another project's agreement, and must be
  on the form searched or a form that does not repeat, in every event the entry
  can be found in. The condition stays on the server, and a survey page does not
  get `whereMessage` either.
- Works on Text, dropdown, radio and SQL fields; composes with the other modes on
  the same field (for example `@UVEXISTS` with `@UVUNIQUE`). Configure via field
  annotation only.

## Numbers within plausible limits — the `@UVRANGE` tag

REDCap's number validation takes one minimum and one maximum and only warns.
`@UVRANGE` checks a number against two levels of limits: a usual range, outside
which the value is unusual, and a plausible range, outside which it is a typing
slip:

```text
@UVRANGE={"soft":[12,17.5],"hard":[3,25],"unit":"g/dL"}
@UVRANGE={"soft":[null,140],"softBlock":"off","hard":[40,250],"unit":"mmHg"}
@UVRANGE={"soft":[12,15.5],"hard":[3,25],"unit":"g/dL","when":"[sex]='2'"}
```

- **JSON form only.** `soft` and `hard` are `[low, high]`, both ends included;
  `null` leaves an end open, and the soft limits must lie inside the hard ones.
  Outside `soft`, the note is amber and `softBlock` decides (`off` or `confirm`,
  default `confirm`). Outside `hard`, or not a number, the note is red and
  `hardBlock` decides (`confirm` or `hard`, default `hard`). `blockSave` is
  refused. Optional `unit`, `when`, `message`.
- **Number fields:** Text fields with no, integer or number validation
  (including comma decimals), calc fields and sliders. A calc field and a
  read-only field never block a save.
- **Exact.** Limits and values are compared as decimals, digit by digit, so
  `17.50` equals `17.5`, and a limit is kept exactly as written, quoted or not.
  `1.5E1` is 15, as REDCap's number validation reads it. Thousands separators
  and units typed into the box are not numbers. Blank checks nothing, and so
  does a missing data code (`UNK`, `-99`).
- **Branch by group.** Several `@UVRANGE` tags with `when` give different
  limits by sex, age group or anything else on the form. Tags that name
  different growth references on one field all apply, and the worst result
  decides.
- **The note waits.** It appears when the field is left, not while a number is
  being typed.
- **Audited.** The post-save audit logs `type: range` with reason `soft-low`,
  `soft-high`, `hard-low`, `hard-high`, `not-a-number` or, for a growth
  reference, `not-positive`, `plausible-low` or `plausible-high`, whatever
  `softBlock` says; a growth finding carries its reference, and its z-score
  only where values are kept raw (a raw audit log, a scan that stores raw
  values). The Validation scan
  counts the same findings; its report (see below) labels them "Unusual
  value", "Implausible value", "Not a number" or "Not a positive measurement",
  with the limits in the detail line. Configure via field annotation only.
- **Growth references.** With `reference`, the limits apply to the value's
  z-score for the child's sex and age (or length or height), computed the way
  WHO's own software computes it:

  ```text
  @UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-6,5]}
  ```

  The `hard` limits -6 and 5 are the ones WHO's software uses to flag a
  weight-for-age z-score as implausible. In a nutrition programme, where an
  extreme value may be a real child, add `"hardBlock":"confirm"` so staff can
  save it after a second look.

  The WHO 2006 standards (birth to 5 years: weight, length/height, BMI, head
  and arm circumference, skinfolds, weight-for-length/height) and the WHO 2007
  reference (5 to 19 years: weight, height, BMI) ship in `data/references`
  under WHO's GPL-3 licence. An administrator adds others, such as CDC 2000, in
  a folder named in the module's system settings; `data/references/README.md`
  explains how, and `data/references/convert.php` turns a published LMS table
  into the module's format, and `convert.php --check` prints one z-score from
  it to compare with the source. A measurement at or below 0 is implausible
  (`not-positive`). Blank inputs, an unknown sex code and an age outside the
  reference check nothing; staff see why, and the post-save audit logs it as
  not evaluated. `"plausible":[0.5,30]` judges the value in the reference's
  unit when there is no z-score, and `"measureScale":0.001` reads grams
  against a reference in kg. Sex codes match without regard to letter case
  unless `caseSensitive` is true. A limit the reference can never pass
  (some rows give no z-score beyond a point: a CDC-style BMI row with L -2 and
  S 0.13 gives none above 3.85) is a configuration error that says which value
  to use. The inputs may sit on another form,
  or in another event with event and instance references on.
- **Returning the z-score, the percentile, the bounds or a reference value.** A growth rule can write
  what it worked out into Text fields of the same form, so the result is on
  the record and in exports:

  ```text
  @UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-2,2],"hard":[-5,5],"return":{"z":"[wfa_z]","lower":"[wfa_low]","upper":"[wfa_high]","range":"[wfa_range]"}}
  ```

  For a boy of one year weighing 9.6 kg the fields read `-0.04`, `7.8`,
  `11.9` and `7.8 to 11.9 kg`. `z` is the z-score, `lower` and `upper` the
  smallest and largest measurements, written with `decimals` (default: the
  decimals of the measured field), that pass the low and high limit of `soft`
  (`"returnLimits":"hard"` takes the hard limits), and `range` the two as text
  with the reference's unit. With `"measureScale":0.001` (grams typed into a
  table in kg) the bounds are in grams, and `"unit":"g"` names the unit in
  `range`. `percentile` writes the percentile of the measurement (one
  decimal, `percentileDecimals` 0 to 3 changes it), `valueAtZ:-2` the
  measurement at a z-score of -2 and `valueAtPercentile:97` the one at the
  97th percentile; for the same boy `48.3`, `7.7` and `11.8`. Give up to 16,
  each its own field and each key once. A rule that returns only `z`,
  `percentile` and values at a z-score or percentile needs no limits, and
  `decimals`, `returnLimits` or `percentileDecimals` on a rule that neither
  returns nor shows in a page table anything they shape is refused. The page fills the fields as it opens and after each
  change; the save fills them again, also for an API or import save, writes
  only the fields that differ, and judges every other rule after that write.
  A field with nothing to report (sex blank, age outside the reference) is
  emptied. The rule owns its fields, so mark them `@READONLY`. Two references
  on one field each fill their own fields, and a rule that returns values may
  not read a returned field. The project setting "@UVRANGE returned values"
  turns the save-time write off.
- **A growth chart and a table on the form.** `show` draws the reference's
  curves with this visit's point under the field, and a table under them,
  on the page only:

  ```text
  @UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-2,2],"hard":[-5,5],"show":{"chart":{"lines":{"percentiles":[3,15,50,85,97]},"bands":true},"table":true}}
  ```

  The chart draws up to 9 percentile or z-score curves (by default 0 and
  each of the rule's limits), shades the `soft` range with `bands`, and marks
  this visit with a point, or an arrow at the edge when it lies outside. On a
  table read by `round` or `floor` the point sits at the row the check reads,
  so it falls on the side of a curve the verdict gives. The chart fits the
  field's cell, 320 to 640 pixels wide, under a heading with the reference,
  its source, the sex and a legend. The SVG has a title and a description that
  state the reference, the value, the z-score and the percentile. The server
  builds the curves (`php/GrowthCurve.php`), within half a unit of the
  312-unit-tall plot of the exact ones at every row the check can read, at the
  narrowest window, and at most 5,000 points; the browser moves only the point
  when the value changes. `"y":"z"` draws z-scores, `span` fixes the ages
  shown, the age axis counts months, years, or the `chartUnit` of the
  reference's `index.json` entry (weeks for a reference by gestational age),
  and `"table"` shows this visit's age, value, z-score and percentile, or any
  value `return` takes (`columns`), or the reference's own values every `step`
  (`"rows":"curve"`, one copy per page for fields with the same table). A rule
  that only shows needs no limits. The labels, the axis title's `{unit}` word
  and the sex words may be per-language maps; the module's other words are
  English. The box is open on data entry and closed on surveys unless `open`
  says otherwise. A page shows at most 4 sets of curves and tables of
  reference values (`pageCharts` in `index.json`); past it, staff see why and
  the check is unchanged. A `span` the reference does not cover, a table of
  more than 100 rows over a reference with no `span`, or a sex saved blank
  leaves that part off with a note to staff, never a configuration error.
  Nothing shown is saved, and nothing is drawn with `innerHTML` or inline
  styles.

## The Validation scan — checking data that is already saved

> **Off until an administrator and the project both turn it on.** The scan
> runs only while the system setting `scan-system-enable-durable` and the
> project setting `scan-enable-durable` are both on. Both are off by default.
> Until then the page shows a notice and reads no record. Live form validation,
> the save-time audit and the live uniqueness check run either way.

Live validation guards the form. It cannot reach values that arrived through
the Data Import Tool or the API (where the save-hook audit is
version-dependent), or records entered before a rule existed. The Validation
scan project page closes that gap. It runs every configured rule, of every tag
and every settings rule kind, over every saved record and lists each
violation, with a CSV export.

Operators: [`docs/INSTALL.md`](docs/INSTALL.md) covers the tables, the upgrade,
the crons and switching the module off.

### Who may use it

The page is the "Validation scan" link on the project's left menu, shown to
users with design rights. Every request from it, from Start to the download,
re-checks three things.

- **Design rights** on the project.
- **Full Data Set export rights.** A run stores record values, so a user with
  a lower export level cannot start a scan, follow one, read its findings on
  screen or download them. The refusal names the export level it read. This
  holds for the screen and the file alike.
- **Every instrument the scan reads.** A rule that reads an instrument the
  user cannot open refuses the whole report rather than narrowing it, so no
  finding, count or label from that instrument is shown. Stop asks only for
  the instruments the rules sit on, so a user barred from an instrument that
  another field's rule reads can still stop a run.

A user in a Data Access Group scans that group only. A group whose name cannot
be resolved is refused, because a scan of zero records is not a clean project.
A group-scoped run shows no counts until it has confirmed which records belong
to the group, and a group user is never shown a whole-project run.

### How a run proceeds

Start lists the records, then checks them in batches of about three seconds,
one request per batch, sent by the open page. The open page is what drives a
scan. Closing it pauses the run, and Continue on a later visit carries on from
the last batch saved. The page names each phase.

1. *Listing the records to check*. The record list is frozen a few seconds per
   request, so no request has to outlast PHP's time limit, however large the
   project.
2. *Checking records*. Every rule runs over every listed record, through the
   same dispatch the save-time audit uses (`ruleFindings`), so the two agree on
   what a violation is.
3. *Checking what changed while it ran*. Records created, edited or deleted
   during the scan are found in REDCap's log, up to a fixed point, and read
   again. An edited record is judged on its new values, and a deleted record
   leaves the run with its findings.
4. *Looking for duplicate values*. `@UVUNIQUE` needs every record, so
   duplicates are decided once all of them are read, within each rule's scope
   (project, group or event).
5. *Building the summary*. The counts by rule, instrument, issue and group
   that the findings page filters on.

The run then reads *Finished*. Stop asks it to end, and the page reads
*Stopping* until it has, after which the run is reported as cancelled. A
project has one active run at a time. A second tab opened on it watches, and
its Continue button makes that tab the one driving the scan; the run's lease
lets only one tab work it at a time. The server scans at most
`scan-system-max-concurrent-projects` projects at once (2 by default), and a
batch refused because the server is full is retried at growing intervals, up
to 30 seconds.

When the project has an `@UVWINDOW` rule, the run freezes the date and time it
was planned, in the time zone of `window-timezone`, and every record of the
run is judged by that clock, catch-up included. A record read after midnight
is judged as of the same day as the rest. The page, the findings page and the
file say "Dates were judged as of" that day. On a record changed since the run
was planned, a `notFuture` date whose save the project log cannot show may have
been saved after that day. If it is later than that day but not in the future
when the record is read, it is reported as not checked ("the day this value was
saved is not known"), not as a date in the future.

### What a finished run says

Five sentences say how much of the project a finished run covered. Each is a
different claim.

| The page says | It means |
|---|---|
| "Every record was checked, including changes made while it ran." | The run read every record, and every change up to a fixed point in REDCap's log. |
| "Every record on the opening list was checked. This server cannot prove the project did not change during the scan." | The run read its whole list, but could not prove nothing changed after it, or records changed that `@UVEXISTS` answers depend on. |
| "Some records could not be checked. This is not a complete picture of the project." | The run stopped early, or a record could not be read, held still, or examined in full. |
| "This scan has no record left to report on: ..." | Nothing was in scope, or every record was deleted or left the group while it ran. |
| "The scan failed, so it describes nothing." | The run failed. |

A run ends as `complete`, `partial`, `cancelled`, `failed` or `expired`. Only a
complete run can certify the project: every record checked through the fixed
point, every finding kept, nothing found, and every rule evaluated. A run that
reached the limit on stored detail adds "Some findings were not kept, because
the scan reached the limit this project allows." Every finished run that does
not certify the project carries "This result does not certify the project." or
says how many rules could not be evaluated. A run that failed, was cancelled
or expired reached no decision at all, and the page reads that as not
certified. It never reads it as clean, or as a sign that something was found.

An incomplete report says so three ways. The banner "INCOMPLETE SCAN - this
report does NOT certify the project as clean" opens it, on the page and in the
file. A terminal row, saying the scan did not cover the whole project, closes
its rows; in the file it is a data row whose first cell is `INCOMPLETE SCAN`,
so it survives deleting the comment lines or sorting the sheet. The
download's file name carries a suffix, which the page states beside the
download link, so a copy forwarded under its own name still says it.
Renaming the file removes that mark and leaves the other two. An incomplete
run's suffix starts with `_INCOMPLETE` and adds
`_CANCELLED`, `_FAILED`, `_EXPIRED`, `_MANIFEST_ONLY`, `_NO_RECORDS` or
`_TRUNCATED` as they apply. A complete run that may not be called clean gets
`_NOT-CERTIFIED` and no banner.

**The finding count.** For each record, a run counts the findings of the
reading it holds for that record, the stored ones and any the detail limit
left out. A record read again during catch-up counts once, from its new
reading. A record deleted while the run went on, or moved out of the run's
group, counts nothing, and its findings are closed with it. Duplicate values
are counted when the run finishes, so the count shown while a run works can
rise at the end.

**The detail limit.** A run stores at most `scan-max-detail-findings` findings
and `scan-max-detail-bytes` bytes of detail (1,000,000 and 512 MiB by default,
never more than `scan-system-max-detail-findings` and
`scan-system-max-detail-bytes`). Past either limit it keeps counting and stops
storing, labels itself truncated, and cannot certify the project. Duplicate
values are not held to the limit.

**Rule problems.** A rule the scan cannot evaluate is counted as a rule
problem, with its reason, and a run with a rule problem does not certify the
project.

### The findings page

Under the panel, the page shows the findings of the newest finished run in
the reader's own scope. For a user outside any group that is the newest
whole-project run; for a group user, the newest run of their group. The
findings appear when no run of the reader's is going, and open by themselves
when a run the tab is watching finishes. Earlier runs are not listed.

Findings come 50 to a page, in the order they were stored, with "Next page"
and "Previous page". The table's caption gives the page, and a line above it
gives how many findings the whole report holds. Four filters narrow the table:
Instrument, Reason, Check and Data Access Group. Each lists "All" and then the
values the run's summary counted, with their counts; a filter with nothing to
offer is not shown, and changing one starts again on page 1. A page can come
back empty while later pages still hold matches. The page then reads up to
four more pages, saying it is looking further, and says "No findings match
these filters." only when nothing more matches. Every cell is plain text.

An incomplete report shows its banner above the table and its terminal row
below it, and beside the download it says that the file name ends in the
run's suffix. A group-scoped run that never confirmed its group
shows only the sentence saying why, with no table, filters or download. The
link "Download these findings as a CSV file" is offered only beside a report
the page has just read.

The columns are Issue, Record, Data Access Group, Event, Instrument, Instance,
Field, Field label, Value, Check, Reason code, Rule, Rule name, What is wrong
and Wording from. A column that does not apply to the project's shape is
absent. A classic project has no Event column, and a project without groups
has no Data Access Group column. When the event map cannot be read the Event
column stays, with raw event ids, and the report says why. The What is wrong
sentence comes from `php/messages/catalog.json`, which only the server reads.
The form words its own messages in `js/engine.js`, often with live values, so
for most checks the form's sentence and the report's differ.
`tests/messages_fixture.json` holds each pair, and its two suites fail when
either side changes without the fixture. Instruments, fields and events are
named as they were when the run was planned, from a snapshot kept with the
run. A run planned before snapshots were kept shows today's names and says
so.

### The CSV file

The download is `pages/export.php`, which streams the stored run a page at a
time and runs no scan of its own. It asks the same questions as the findings
page. The file is UTF-8 with a byte-order mark. It opens with `# ` lines about
the report (schema, project, run, when it was generated, scope, coverage,
whether it certifies, values, labels, a column legend and how many rows to
expect), then a header row of column keys, then the findings. It ends on
`# export_complete=1` with the row count. A download cut off partway has no
such line, and one whose rows could not all be read ends on a row saying rows
were lost. Cells are quoted and formula-defused, and record ids follow the
privacy mode the audit log uses (`log-values`). A refused export is plain text
that starts `# EXPORT REFUSED`, and is never offered as a file.

### Values

Whether a finding shows the offending value is the project's choice in
`scan-value-storage`. The choices are locations only (the default), values
with the fields REDCap marks as an Identifier withheld, and every value. The
choice in force when a run is planned decides what the run stores. The choice
in force when the report is read decides what is shown, so a tighter setting
hides values at once. Redaction fails closed. A data dictionary that cannot be
read withholds every value, and a field flagged as an Identifier when the run
was planned, or flagged since, is withheld. A stored value keeps at most its
first 255 bytes, marked when cut, for at most `scan-value-retention-days` days
(30 by default, never more than `scan-system-max-value-retention-days`). A
finding with no value to show, such as a blank required field, shows an empty
cell.

### Upkeep and retention

Two crons keep the scan's tables bounded. Each pass works in slices for a
quarter of its run-time limit, carries on where the last pass stopped, and
writes a `scan-cron` entry to the module log.

- `uv_scan_reap_abandoned` runs every 15 minutes. It ends a run that has made
  no progress for `scan-system-stale-run-hours` hours (24 by default) as
  expired, removes worker places above the concurrency limit, and closes the
  previous findings of each project whose latest whole-project run finished
  complete. Pressing Start ends an abandoned run of its own project at once.
- `uv_scan_expire_values` runs every hour. It clears stored values that a
  tightened privacy setting revoked, deletes finished runs older than
  `scan-run-retention-days` days (90 by default, never more than
  `scan-system-max-run-retention-days`), and clears stored values past their
  retention window.

### Switching the module off

Disabling the module on the server stops every scan in progress and frees
every worker place. Each stopped run finishes as cancelled, says the module was
disabled, and keeps what it had examined. Nothing is deleted unless
`scan-system-drop-tables-on-disable` is on; with it on, every `uv_` table is
dropped, with every stored scan, finding and audit row. Disabling the module
for one project stops that project's run the same way. With
`scan-system-enable-durable` off, a disable stops nothing, since no scan can
run then.

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
- `tests/window_js.cjs` / `tests/window_php.php` — the `@UVWINDOW` verdict
  (window bounds, units, `notFuture` against a given clock, date formats, leap
  days, year limits) in both runtimes from one fixture,
  `tests/window_fixture.json`. `tests/window_dom_js.cjs` drives the browser rule
  and `tests/window_module_php.php` the server side: annotation checks, the
  page fold of an off-page `from` date, the audit, reverse dependencies, the
  scan, the scan clock and the `window-timezone` setting.
- `tests/exists_php.php` — `@UVEXISTS` on the server: the grammar and
  dictionary checks, the page config without the lookup target, the
  `exists-check` endpoint (found / not found / could not check, a narrowed read
  that compares as the rule does and a miss confirmed by a full read, rights,
  throttle, surveys, the DAG-masked record),
  the audit, the chunked scan index and group-confined scans, letter case and
  numbers in both `@UVEXISTS` and `@UVUNIQUE`.
  `tests/exists_dom_js.cjs` drives the browser rule: asks on change and blur
  only, never blocks on *could not check*, the cache and stale replies, and
  Save asking again after a cached *not found*.
  `tests/exists_where_php.php` and `tests/exists_where_dom_js.cjs` cover
  `where`: the grammar, the dictionary checks, the test of each entry (AND,
  OR, letter case, blanks, checkboxes, dates, decimal commas), the read that
  confirms a narrowed one, the fourth answer on the server and on the page,
  surveys, rights, `return`, the audit and the scan.
- `tests/lookup_php.php` / `tests/lookup_js.cjs` — how `@UVEXISTS` and
  `@UVUNIQUE` compare values (trim, letter case, numbers by value) in both
  runtimes from one fixture, `tests/lookup_fixture.json`.
  `tests/exists_cross_php.php` covers lookups in another project: the server
  switch, the alias, the searched project's agreement (one refusal for every
  reason, nothing read before it passes), rights there, the probe log, the
  budgets, the audit and the scan.
  `tests/exists_return_php.php` and `tests/exists_return_dom_js.cjs` cover
  `"return"`: the grammar and setup checks, the values the endpoint sends and
  the save writes, entries that disagree or sit in another group, rights, the
  agreement's list of fields another project may copy, and the page fill.
- `tests/range_js.cjs` / `tests/range_php.php` — the `@UVRANGE` verdict
  (edges, exact decimals past 2^53, comma decimals, what is not a number) in
  both runtimes from one fixture, `tests/range_fixture.json`, plus the grammar.
  `tests/range_dom_js.cjs` drives the browser rule (the note waits for the
  field to be left, each level's block, calc and read-only fields never block)
  and `tests/range_module_php.php` the server side: eligible fields, the audit,
  the scan and its per-branch detail line.
- `tests/growth_js.cjs` / `tests/growth_php.php` — growth references: the
  z-score in both runtimes from one fixture (`tests/growth_fixture.json`),
  2,880 points computed by WHO's own R code (`tests/who_golden.json`, made by
  `tools/who_golden.R` in the source repository; `tools/` is not in the
  release package), and 5,000 random points that must hash the same in
  both. The normal distribution function and its inverse behind the
  percentile are held to R's `pnorm` and `qnorm`
  (`tests/growth_normal_golden.json`, from `tools/normal_golden.R`); the
  percentile and the values at a z-score or percentile are held to 720
  points worked out at 50 digits (`tests/growth_outputs_golden.json`, from
  `tools/growth_outputs_golden.py`) and to a second hash of 3,000 random
  points. `growth_php.php` also covers
  a folder of extra references and every refusal of a broken index or table.
  `tests/growth_outputs_php.php` covers the catalog of values a rule can
  return (`php/growth_outputs.json`). `tests/growth_dom_js.cjs` drives the
  browser rule (live inputs, the notes that say why nothing was checked,
  withheld inputs, never blocking on saved or cross-event inputs) and
  `tests/growth_module_php.php` the server side: the grammar, the dictionary
  checks, the page's copy of the tables and its cap, the audit and the scan.
  `tests/growth_return_dom_js.cjs` and `tests/growth_return_module_php.php`
  cover the values a rule returns to fields: the fill on the page, and the one
  save that writes only what differs, in the order that lets a rule on a
  returned field see it. `tests/growth_curve_php.php` measures how close the
  chart's simplified curves lie to the exact ones on every bundled reference,
  `tests/growth_chart_module_php.php` covers the `show` grammar, the page's
  curves and their cap, and `tests/growth_chart_dom_js.cjs` the chart and
  table the browser draws from them.
- `tests/missing_codes_dom_js.cjs` / `tests/missing_codes_php.php` — a field
  marked with a missing data code is not judged by any mode, on the page, in
  the audit, in the scan or by the lookup endpoints; `@UVREQUIRED` counts it
  as an answer.
- `tests/a11y_dom_js.cjs` — the field-facing DOM contract: live-region status
  messages, `aria-describedby`/`aria-invalid`, label-based block dialogs,
  debounce, the read-only exemption, and survey muting of technical detail.

CI (`.github/workflows/parity.yml`) runs the JS suite on Node 20, the PHP suite
on PHP 7.4, 8.1 and 8.3 (the declared floor is exercised, not just stated),
`php -l`-lints the PHP, checks the pooled fixture is regenerated, and builds a
release-shaped package to verify its layout. If either engine drifts, CI fails.
See [`tests/README.md`](tests/README.md).

## Install

See [`docs/INSTALL.md`](docs/INSTALL.md), or the
[install page](https://nghareformer.github.io/redcap-universal-validator/install/)
on the documentation site.

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
php  tests/registry_php.php    # mode registry (php/modes.json): frozen maps, coverage, bad file
node tests/gen_mode_registry.cjs # rewrite the generated blocks in js/engine.js after editing php/modes.json or php/growth_outputs.json
node tests/branch_dom_js.cjs  # branched validation DOM contract (active/else/conflict)
node tests/constraint_dom_js.cjs # @UVASSERT constraint DOM contract (assert test, compose, branches)
node tests/required_dom_js.cjs   # @UVREQUIRED required DOM contract (blank, when-gate, compose)
node tests/unique_dom_js.cjs     # @UVUNIQUE unique DOM contract (transport, fail-open, cache)
php  tests/unique_also_scan_php.php  # @UVUNIQUE lookups in the durable scan: settled, re-read by form, never findings
node tests/window_js.cjs         # @UVWINDOW verdict vs window_fixture.json
php  tests/window_php.php         # @UVWINDOW verdict, PHP twin, same fixture
node tests/window_dom_js.cjs     # @UVWINDOW DOM contract (messages, clock, snapshot, guard)
php  tests/window_module_php.php  # @UVWINDOW hooks, page fold, audit, scan, clock and time zone
php  tests/value_stamps_php.php   # @UVWINDOW save days read from a mock of REDCap's log
php  tests/exists_php.php         # @UVEXISTS grammar, hooks, endpoint, audit and scan index
php  tests/exists_cross_php.php   # @UVEXISTS in another project: agreement, rights, probe log, budgets
node tests/exists_dom_js.cjs      # @UVEXISTS DOM contract (asks on change, could-not-check, cache)
php  tests/exists_return_php.php  # @UVEXISTS "return": grammar, setup checks, endpoint values, save write, rights
node tests/exists_return_dom_js.cjs  # @UVEXISTS "return": fields filled, emptied or left on the page
php  tests/exists_where_php.php   # @UVEXISTS "where": grammar, setup checks, entry tests, confirm read, audit, scan
node tests/exists_where_dom_js.cjs   # @UVEXISTS "where": the filtered line, whereMessage and languages, surveys
php  tests/lookup_php.php         # @UVEXISTS/@UVUNIQUE comparison key vs lookup_fixture.json
node tests/lookup_js.cjs          # the same comparison key, JS twin, same fixture
node tests/range_js.cjs          # @UVRANGE verdict vs range_fixture.json
php  tests/range_php.php          # @UVRANGE verdict and grammar, PHP twin, same fixture
node tests/range_dom_js.cjs      # @UVRANGE DOM contract (note on leave, per-level block, calc)
php  tests/range_module_php.php   # @UVRANGE eligible fields, audit, scan and report detail
node tests/growth_js.cjs         # growth references: z-scores and outputs vs growth_fixture.json, WHO golden, R pnorm/qnorm, mpmath outputs, two parity hashes
php  tests/growth_php.php         # growth references, PHP twin, same fixture, extra folder
php  tests/growth_outputs_php.php # @UVRANGE "return" catalog: outputs, key grammar, engine copy, bad file
node tests/growth_dom_js.cjs     # growth references DOM contract (inputs, notes, withheld, no block)
php  tests/growth_module_php.php  # growth references: grammar, dictionary, page tables, audit and scan
node tests/growth_return_dom_js.cjs  # @UVRANGE "return": values written into fields on the page
php  tests/growth_return_module_php.php  # @UVRANGE "return": the save writes them once; grammar, hook, branches
php  tests/growth_curve_php.php   # @UVRANGE "show": chart curves within 0.5 of the exact ones, payload, span, table rows
php  tests/growth_chart_module_php.php  # @UVRANGE "show": grammar, page curves and cap; --write refreshes growth_chart_fixture.json
node tests/growth_chart_dom_js.cjs  # @UVRANGE "show": the chart and the table on the page, parity with the server, MLM
node tests/missing_codes_dom_js.cjs  # Missing Data Codes on the page, every mode
php  tests/missing_codes_php.php     # Missing Data Codes in the audit, scan, endpoints and page config
node tests/pooled_dom_js.cjs  # pooled chip severity colors + marks
php  tests/annotation_php.php  # @UVALIDATE parser + shared rule validator
php  tests/hook_php.php        # redcap_save_record audit path (mocked framework)
php  tests/docs_claims_php.php # scan docs name only settings, tables, crons, pages and actions that exist
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

For complete, copyable region/site dropdown rules, see [the region/site annotation](docs/region-site-choices-example.txt). The [action-tag examples](docs/action_tag_validation_examples.md) cover single/pooled `alternates`, autocomplete choice filtering, cross-event references, and repeating-instrument matching.
