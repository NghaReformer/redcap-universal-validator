# Action Tag Validation Examples

A worked, parameter-by-parameter guide to the five action tags of the **Universal
Regex & Check-Character Validator** module (v1.4.0). Every tag is shown from its
simplest form to its most complete, with the meaning of each option and what the
person entering data will see.

**Where you type these:** the *Action Tags / Field Annotation* box of a field in the
Online Designer, or the `field_annotation` column of a data dictionary CSV. Tagging
50 fields is one spreadsheet column and one upload. Everything here is also available
in the module's Configure dialog — the tags and the dialog are the same rules through
different doors, and they mix freely.

---

## Contents

- [The five tags at a glance](#the-five-tags-at-a-glance)
- [Rules that apply to every tag](#rules-that-apply-to-every-tag)
- [`@UVALIDATE` — check characters and format](#uvalidate--check-characters-and-format)
- [`@UVASSERT` — cross-field constraints](#uvassert--cross-field-constraints)
- [`@UVREQUIRED` — conditional required](#uvrequired--conditional-required)
- [`@UVUNIQUE` — no duplicates across records](#uvunique--no-duplicates-across-records)
- [Combining tags on one field](#combining-tags-on-one-field)
- [Branching — several tags of the same kind](#branching--several-tags-of-the-same-kind)
- [The `when` condition language](#the-when-condition-language)
- [Examples cookbook](#examples-cookbook)
  - [`@UVALIDATE` recipes](#uvalidate-recipes)
  - [`@UVASSERT` recipes](#uvassert-recipes)
  - [`@UVREQUIRED` recipes](#uvrequired-recipes)
  - [`@UVUNIQUE` recipes](#uvunique-recipes)
  - [Combination recipes](#combination-recipes)
- [Parameter reference tables](#parameter-reference-tables)
- [Copy-paste cheat sheet](#copy-paste-cheat-sheet)

---

## The five tags at a glance

| Tag | What it checks | Field types it may sit on |
|---|---|---|
| `@UVALIDATE` | The value's **check character and/or format** (an ID is well-formed) | Text, Notes |
| `@UVASSERT` | A **condition across fields** holds (end ≥ start, dose ≤ max) | Text, Notes, dropdown, radio, yes/no, true/false, calc, slider |
| `@UVREQUIRED` | The field is **not blank**, optionally only while a condition is true | Same as above, **minus calc** |
| `@UVUNIQUE` | The value is **not used by another record** | Same as above, **minus calc** |
| `@UVCHOICES` | Which **options are offered** — show/hide choices while a condition holds | radio, dropdown, checkbox (not matrix) |

Different tags on one field **compose** — all must pass, and each keeps its own
save-block state. Several tags of the *same* kind on one field **branch** (one wins
by condition). Both are covered below.

---

## Rules that apply to every tag

Learn these once and all five tags behave predictably.

**Three value forms.** Every tag accepts a bare form, a short form, and a JSON form:

```text
@UVALIDATE                                       bare — all defaults
@UVALIDATE=verhoeff                              short — the tag's one most common option
@UVALIDATE={"algorithm":"verhoeff","blockSave":"hard"}   JSON — every option
```

**JSON must be real JSON.** Double quotes around keys and string values; `true`/`false`
unquoted. Single quotes or a trailing comma produce a visible configuration error under
the field, never a silently skipped rule. Note that REDCap logic literals use single
quotes *inside* a JSON string, which is exactly why the outer quoting must be double:
`{"when":"[consent]='1'"}`.

**A malformed tag is always visible.** An unknown option name, a bad algorithm, a
non-compiling regex — each shows a configuration error on the tagged field and names
the problem. The module never fails silently.

**`blockSave` is how strongly you enforce.** Available on all five tags:

| Value | Behavior | Dialog label |
|---|---|---|
| `off` *(default)* | Shows the message; the save proceeds | Informational |
| `confirm` | Asks "save anyway?" — the user may override | Advisory |
| `hard` | Blocks the browser save until the value is fixed | Compulsory |

Enforcement is a **browser behavior**. It does not block API or Data Import Tool
writes; those are covered by the post-save audit (coverage is REDCap-version
dependent) and reliably by the **Validation scan** page. Read-only fields show the
notice but never block a save.

**`when` gates any rule.** Any tag may carry a `when` condition; the rule validates
only while that condition is true. A false `when` skips the rule — it never erases
the value. See [The `when` condition language](#the-when-condition-language).

**Identical tags group.** Fifty fields carrying byte-identical tags become one rule
with fifty fields, not fifty rules.

---

## `@UVALIDATE` — check characters and format

The original tag: is this ID well-formed? It runs a check-character algorithm, a
regex format pattern, or both.

### Level 1 — the bare tag

```text
@UVALIDATE
```

Validates the field with the default algorithm, **ISO 7064 Mod 37,36**, and shows a
message on a bad value (`blockSave` defaults to `off`). This is the tag for IDs minted
by the module's companion generator.

### Level 2 — pick an algorithm

```text
@UVALIDATE=verhoeff
@UVALIDATE=9710                 ISO 7064 Mod 97,10 — a shorthand
@UVALIDATE=gs1                  GS1 / GTIN / EAN / UPC barcodes
@UVALIDATE=isbn                 ISBN-10 weighted Mod-11
```

The shorthands are case-insensitive and resolve to canonical names before the browser
or the audit ever see them. `damm` and `verhoeff` have no shorthand — type them in
full. `@UVALIDATE=none` (or `regex`/`format`) is rejected on its own, because
format-only validation needs a pattern — use the JSON form for that.

See [Algorithms](#algorithms) and [Algorithm shorthands](#algorithm-shorthands) below
for the full lists.

### Level 3 — enforcement

```text
@UVALIDATE={"blockSave":"confirm"}
@UVALIDATE={"algorithm":"damm","blockSave":"hard"}
```

### Level 4 — a format pattern

```text
@UVALIDATE={"algorithm":"none","pattern":"FC[0-9]{4}"}
@UVALIDATE={"algorithm":"regex","pattern":"TB-[0-9]{6}","blockSave":"hard"}
@UVALIDATE={"algorithm":"regex","pattern":"(19|20)[0-9]{2}"}
```

Pattern rules worth knowing:

- **JavaScript regex syntax.**
- **Anchored automatically** — the pattern must match the whole value, as if wrapped
  in `^(?:…)$`. You do not add `^` or `$`.
- **Write patterns in uppercase.** The value is upper-cased and dash-unified before
  matching, so `FC[0-9]{4}`, not `fc[0-9]{4}`.
- **Printable ASCII only.** The browser and server regex engines are only proven to
  agree on that subset.
- **Python-only constructs are rejected** with a specific message: `\A`, `\Z`,
  `(?P<name>…)`. Use `^`/`$` and plain groups.
- **Catastrophically backtracking patterns are rejected at save time** — nested
  quantifiers (`(a+)+`), a repeated ambiguous group (`(a|aa)+`), overlapping unbounded
  quantifiers (`.*.*`, `[0-9]*[0-9]*`). The fix: put a required, non-overlapping piece
  between quantifiers, or use bounded `{n}` counts. `.*x.*`, `[A-Z]+[0-9]+`, `FC[0-9]{4}`
  all pass.

### Level 5 — pattern *and* check character together

```text
@UVALIDATE={"algorithm":"iso7064_mod37_36","pattern":"TB[A-Z]{3}-[0-9]{5}[0-9A-Z]"}
```

With both, the **format is tested first**, then the check character — so the typist
learns which kind of mistake they made.

### Level 6 — what the check runs over (`source`)

```text
@UVALIDATE={"algorithm":"mod11_10","source":"digits_only"}
```

| `source` | The algorithm sees |
|---|---|
| `normalized_id` *(default)* | The whole normalized value |
| `digits_only` | Only the digits — for a mixed ID whose check covers the numbers |
| `sequence_only` | Only the sequence portion |

If a value you know is correct is flagged, `source` (or `algorithm`) is usually the
mismatch. Confirm the minting method with whoever generates the IDs.

### Level 7 — separators (`strip`, `keepChars`)

```text
@UVALIDATE={"algorithm":"3736","strip":"-/ _|\\"}
```

`strip` lists the separator characters ignored before checking. It defaults to dash,
slash, space, underscore, pipe and backslash — so `TBABC-00239` checks as `TBABC00239`
without configuration. Unicode dashes in *values* are unified automatically; `strip`
itself must be printable ASCII.

`keepChars` (pooled rules only) lists extra characters to keep while splitting a pooled
field, capped in length.

### Level 8 — pooled fields (many IDs in one box)

A pooled rule reads several IDs from one field.

```text
@UVALIDATE={"type":"pooled","idLengths":[9],"expectedIds":3}
@UVALIDATE={"type":"pooled","algorithm":"none","pattern":"FC[0-9]{4}","idLengths":[6]}
@UVALIDATE={"type":"pooled","idMinLen":9,"idMaxLen":12,"blockSave":"confirm"}
```

| Option | Default | Meaning |
|---|---|---|
| `idLengths` | *(none)* | Exact length(s): `[9]`, `[10,12]`, or the string `"10, 12"` |
| `idMinLen` | `8` | Minimum length when no exact lengths are given |
| `idMaxLen` | `14` | Maximum length; must be **less than 2× the minimum** |
| `expectedIds` | *(none)* | How many IDs the field should contain; a mismatch is reported |

**The 2× rule.** If the maximum is 2× the minimum or more, one "member" could swallow
two real IDs, and the parser could not tell the difference. The same applies to exact
lengths: `[4,5,9]` is rejected because 9 = 4 + 5. Narrow the range, set exact lengths,
or split into separate fields. This is checked when you save, not discovered on a form.

### Level 9 — conditional, labeled, with a fix hint

```text
@UVALIDATE={"algorithm":"verhoeff","when":"[specimen_type]='2'"}
@UVALIDATE={"algorithm":"3736","suggestFix":true,"note":"Blood specimen barcode"}
```

- `suggestFix` (boolean, default `false`) opts **in** to the "should end in X" hint.
  It tells the typist the correct check character — useful when the payload is
  trusted, unhelpful when it is not, which is why it is opt-in. Must be unquoted
  `true`/`false`.
- `note` is a label for the rule, for your own bookkeeping.

### Full `@UVALIDATE` JSON keys

`type`, `algorithm`, `source`, `pattern`, `strip`, `keepChars`, `idLengths`,
`idMinLen`, `idMaxLen`, `expectedIds`, `blockSave`, `when`, `suggestFix`, `note`.

---

## `@UVASSERT` — cross-field constraints

Stock REDCap cannot *block* a bad relationship between fields at entry: branching only
hides, a range check only warns, Data Quality runs in batch. `@UVASSERT` makes the
field invalid unless a condition holds — checked live, enforceable with a real block.

### Level 1 — the condition is the value

```text
@UVASSERT="[end_date]>=[start_date]"
```

Put this on `end_date`. While the condition is false, the field is invalid and shows a
generic message. ISO dates and numbers compare correctly.

A bare `@UVASSERT` with no condition is a configuration error — there is nothing to
assert.

### Level 2 — confirm-a-value

```text
@UVASSERT="[participant_id]=[participant_id_confirm]"
```

"Type it twice" needs no special feature; it is an assertion like any other.

### Level 3 — your own message

```text
@UVASSERT={"assert":"[dose]<=[max_dose]","message":"Dose exceeds the protocol maximum"}
```

`message` is optional but **strongly recommended**: only you can word what an arbitrary
relationship means. Without it the module shows a generic line.

### Level 4 — enforcement

```text
@UVASSERT={"assert":"[dose]<=[max_dose]","message":"Dose exceeds the protocol maximum","blockSave":"hard"}
```

### Level 5 — gate the constraint with `when`

```text
@UVASSERT={"assert":"[sex]='2'","when":"[pregnant]='1'","message":"Pregnant participants must be recorded female"}
```

Read it as: *while* `[pregnant]='1'`, the field is invalid unless `[sex]='2'`. Outside
that condition the rule is inert.

Note the two conditions do different jobs: `assert` is the **test**, `when` is the
**gate**.

### Level 6 — compound logic

```text
@UVASSERT={"assert":"([visit_type]='1' and [weight]>'0') or [visit_type]<>'1'","message":"A baseline visit needs a weight above zero","blockSave":"confirm"}
```

### Semantics worth knowing

- **An empty field is inert.** A constraint never demands a value — that is
  `@UVREQUIRED`'s job. The two compose cleanly.
- Sits on Text, Notes, dropdown, radio, yes/no, true/false, **calc** and slider fields.
  Any field type — including checkbox and file — may be *referenced* inside the
  condition; the restriction is only on the field the tag sits on.
- The server audit honors the constraint against saved values (logged as
  `type: constraint`), so the browser and the audit agree.
- Off-instrument references are resolved on the server and folded to constants, so no
  record value reaches the page.

### `@UVASSERT` JSON keys

`assert`, `message`, `blockSave`, `when`. Any other key is a configuration error.

---

## `@UVREQUIRED` — conditional required

REDCap's own required flag is unconditional and only warns. `@UVREQUIRED` adds the two
things it lacks: a **condition** and a **real block**.

### Level 1 — always required

```text
@UVREQUIRED
```

A blank (or whitespace-only) field shows the notice. Filling it clears the notice —
deliberately no green "OK", because required mode never judges the *value*.

### Level 2 — required only while a condition is true

```text
@UVREQUIRED="[consent]='1'"
```

The bare value **is** the `when` condition. The requirement turns on and off live as
the referenced fields change — pick the consent option and the notice appears
immediately, including a save block if configured.

### Level 3 — message and enforcement

```text
@UVREQUIRED={"when":"[consent]='1'","message":"Phone needed for consented participants","blockSave":"hard"}
@UVREQUIRED={"message":"Every specimen needs a collection date","blockSave":"confirm"}
```

### Level 4 — compound conditions

```text
@UVREQUIRED={"when":"[consent]='1' and [site]<>'9' and not [withdrawn(1)]='1'","message":"Required for active consented participants at study sites","blockSave":"hard"}
```

### Semantics worth knowing

- Sits on Text, Notes, dropdown, radio, yes/no, true/false and slider fields. **Not
  calc** — the person entering data cannot fill a calc, so requiring one would trap
  them. Same reasoning as the read-only exemption: a read-only field shows the notice
  but never blocks the save.
- **Required mode never judges the value.** Pair it with `@UVALIDATE` or `@UVASSERT`
  for that. On a blank field only `@UVREQUIRED` fires; on a filled-but-wrong value only
  the value checks fire.
- The audit logs a blank-while-required save as `type: required`,
  `reason: required-blank`. A blank carries nothing identifying, so this entry is safe
  in every privacy mode.

### `@UVREQUIRED` JSON keys

`when`, `message`, `blockSave`. Any other key is a configuration error.

---

## `@UVUNIQUE` — no duplicates across records

REDCap has no native field-level uniqueness. `@UVUNIQUE` checks the value against every
other record **as it is typed**, over a CSRF-protected module AJAX call — no page
reload.

### Level 1 — unique across the project

```text
@UVUNIQUE
```

### Level 2 — pick a scope

```text
@UVUNIQUE=project      the default — no other record anywhere may hold this value
@UVUNIQUE=dag          unique within each Data Access Group
@UVUNIQUE=event        unique within the same event of a longitudinal project
```

Any other bare value is a configuration error naming the three valid scopes.

### Level 3 — message and enforcement

```text
@UVUNIQUE={"message":"This participant ID is already registered","blockSave":"hard"}
```

### Level 4 — composite keys (`with`)

```text
@UVUNIQUE={"with":["site"]}
@UVUNIQUE={"with":["site","visit_type"],"scope":"event","message":"Specimen already registered for this site and visit","blockSave":"hard"}
```

`with` makes the key composite: the tagged value **plus** those fields together must be
unique. A specimen ID may repeat across sites but not within one.

Constraints on `with`: a JSON list of real REDCap field names, at most **5** entries, no
duplicates, and each must exist in the data dictionary and be a scalar field. Names are
lowercased automatically.

### Level 5 — surveys (explicit opt-in)

```text
@UVUNIQUE={"surveys":true,"message":"That ID is already in use","blockSave":"hard"}
```

A live used/free answer is record-derived information, so survey respondents get the
check only when you decide the trade-off is acceptable. Must be unquoted `true`/`false`.
Respondents always receive a **boolean** — never a record id.

### Level 6 — gated uniqueness

```text
@UVUNIQUE={"with":["site"],"scope":"dag","when":"[specimen_collected]='1'","message":"Specimen barcode already registered in this DAG","blockSave":"hard","surveys":false}
```

### Semantics worth knowing

- Sits on Text, Notes, dropdown, radio, yes/no, true/false and slider fields — not calc
  (a data enterer cannot fix a calc collision).
- **Privacy posture.** The endpoint answers only for fields that carry a unique rule, so
  it cannot be used to probe arbitrary fields for value existence. Staff see the
  colliding record id only when that record is inside their own DAG. Comparison is exact
  against stored values, after trimming.
- **The race is audited, not denied.** Two near-simultaneous saves can both pass the
  live check. The post-save audit re-checks the saved value against every other record
  and logs a collision (`type: unique`, `reason: duplicate-value`) — review the module
  log for races.
- **Transport failures fail open.** A network error never traps a save.

### `@UVUNIQUE` JSON keys

`with`, `scope`, `when`, `message`, `blockSave`, `surveys`. Any other key is a
configuration error.

---

## `@UVCHOICES` — dynamic choice filtering

Shows or hides individual options of a **radio, dropdown or checkbox** field
while a condition holds. REDCap's own `@HIDECHOICE` is static; this one follows
the form live. JSON form only — there is no bare or short form.

### Level 1 — hide a code conditionally

```text
# on: method — hide the retired option 9 unless legacy entry is flagged
@UVCHOICES={"when":"[legacy_entry]<>'1'","hide":["9"]}
```

`hide` is a blacklist: the listed codes disappear while the condition is true;
everything else stays.

### Level 2 — show-list (whitelist)

```text
# on: region — while the country is Cameroon, offer only its regions
@UVCHOICES={"when":"[country]='1'","show":["101","102","103"]}
```

`show` is a whitelist: every OTHER code of the field hides. Exactly one of
`show`/`hide` per tag; the codes must exist in the field's own choice list
(an unknown code is a configuration error naming the real codes).

### Level 3 — a cascade is one tag per branch

```text
# on: site — one branch per country, no fallback needed
@UVCHOICES={"when":"[country]='1'","show":["101","102"]}
@UVCHOICES={"when":"[country]='2'","show":["201","202"]}
```

The branching rules are the same as every other tag: exactly one true
condition filters, no active branch (and no fallback) shows everything, two
true conditions are a visible conflict and the filter is not applied. A
three-level cascade is this same pattern on two fields: `region` branches on
`[country]`, `site` branches on `[country]` + `[region]` combinations, e.g.
`{"when":"[country]='1' and [region]='101'","show":["s01","s02"]}`.

### Level 4 — message and enforcement

```text
# on: site_cb — checkbox options restricted during the pilot, hard block
@UVCHOICES={"when":"[pilot(1)]='1'","show":["s01","s02"],
            "message":"Only pilot sites may be selected during the pilot phase.",
            "blockSave":"hard"}
```

### Semantics worth knowing

- **A hidden selection is never cleared.** Change the country after picking a
  site and the stale site stays visible (a dropdown keeps it in place,
  disabled), the field is flagged with your message, and `blockSave` decides
  whether the save is challenged. The module never erases an entered value.
- A value outside the field's choice list entirely (a missing-data code such
  as `-99`) is out of the filter's scope and never flagged.
- Conditions may reference fields on other instruments; they are resolved
  server-side against saved values.
- Not available on yes/no, true/false, sql, or matrix fields — the tag is
  refused there with a configuration error.
- The post-save audit logs a saved hidden choice as `type: choices`,
  `reason: hidden-choice`; the Validation scan reports the same.

### `@UVCHOICES` JSON keys

`show` **or** `hide` (exactly one, a list of choice codes), `when`, `message`,
`blockSave`. Any other key is a configuration error.

---

## Combining tags on one field

Different kinds of tag on one field **compose**: all must pass, and each keeps an
independent save-block state.

```text
@UVREQUIRED="[consent]='1'"
@UVALIDATE={"algorithm":"3736","blockSave":"hard"}
@UVUNIQUE={"blockSave":"hard","message":"That participant ID already exists"}
```

That field, in one annotation box, now demands a value while consented, requires it to
be a well-formed ID, and refuses a duplicate. The behavior is layered sensibly: on a
**blank** field only the required notice fires; once filled, the value checks take over.

Another common pairing — an ID typed twice, format-checked, and unique:

```text
@UVALIDATE={"algorithm":"none","pattern":"TB-[0-9]{6}","blockSave":"hard"}
@UVASSERT={"assert":"[participant_id]=[participant_id_confirm]","message":"The two ID entries do not match","blockSave":"hard"}
@UVUNIQUE={"scope":"dag","blockSave":"hard"}
```

---

## Branching — several tags of the same kind

Several tags of the **same** kind on one field branch: the rule whose `when` is true
validates the field.

```text
@UVALIDATE={"algorithm":"verhoeff","when":"[specimen_type]='2'"}
@UVALIDATE={"algorithm":"none","pattern":"FC[0-9]{4}"}
```

Blood specimens get a Verhoeff check; everything else gets the format pattern. The
when-less tag is the **else** branch.

The rules:

- Every sharing tag must carry a `when`, **except at most one**, which becomes the else
  branch.
- If no condition is true and there is no else branch, the field is simply not validated
  at that moment.
- `blockSave`, `suggestFix` and `message` are **per branch** — Compulsory for blood
  specimens, informational otherwise.
- **Rejected at save time** (a visible configuration error, never silent): two when-less
  tags of the same kind on one field; two tags with byte-identical `when` strings (they
  could never be told apart); a single-value and a pooled `@UVALIDATE` sharing a field.
- **Overlapping conditions are a runtime conflict.** If two conditions are ever true at
  once, the field shows a "Validation conflict" notice naming both, validates nothing,
  and **never** blocks the save; the server logs the same conflict. Mutually exclusive
  conditions (`='2'` vs `<>'2'`) can never conflict.
- Dialog rules and tags mix freely — a dialog rule and a tag may legally share a field
  as long as the sharing is gated.

A three-way branch:

```text
@UVALIDATE={"algorithm":"verhoeff","when":"[specimen_type]='1'","blockSave":"hard"}
@UVALIDATE={"algorithm":"damm","when":"[specimen_type]='2'","blockSave":"hard"}
@UVALIDATE={"algorithm":"none","pattern":"[A-Z]{2}[0-9]{6}","note":"anything else"}
```

---

## The `when` condition language

The same dialect powers `when` on all five tags and `assert` on `@UVASSERT`. It is a
**REDCap-style subset — not byte-for-byte REDCap logic**.

| Supported | Rejected when the rule is saved |
|---|---|
| `[field]` and `[checkbox(code)]` references | functions (`datediff(...)`, …) |
| `'text'` / `"text"` / number literals | smart variables (`[record-name]`, …) |
| `=` `<>` `!=` `>` `<` `>=` `<=` | `[event][field]` prefixes (cross-event) |
| `and` / `or` / `not` (case-insensitive), parentheses | arithmetic and piping |

A bare `[field]` with no comparison is an error — write `[field]<>''`.

Semantics:

- **Comparisons are numeric when both sides look numeric** (`[age]>'9'` with age `10` is
  true, not a lexicographic accident); otherwise the comparison is exact and
  case-sensitive.
- A missing or empty field reads as `''`. A checkbox reference `[f(code)]` reads `'1'`
  when checked, `'0'` otherwise.
- **Fields on the same instrument react live.** A calc field updates without DOM events,
  so a calc reference refreshes at the next event on any watched field.
- **Fields on other instruments are resolved on the server, never sent to the browser.**
  Such a field cannot change while the page is open, so the server settles that part of
  the condition against the record's saved values and sends only the result. The page
  carries field names, your literals and booleans — never a record value, so a survey
  respondent (or a user without rights to that instrument) cannot read one out of the
  page source. A comparison mixing an on-instrument and an off-instrument field is
  settled the same way: correct as of page load, but it does **not** react live. Put both
  fields on one instrument if you need that. A brand-new record has no saved values, so
  such references resolve as `''`.
- **A false condition skips the rule — it never erases the value.** That is the
  deliberate difference from REDCap's own field branching. Combine `when` with normal
  branching if you also want erasure.
- **The server audit honors the same condition** against saved values, so the browser and
  the audit skip (or check) a rule consistently.
- **Caps:** 500 characters, 20 field references, 10 nesting levels. Field references are
  checked against the data dictionary at save time — unknown fields, missing or wrong
  checkbox codes, and references to file/descriptive fields are configuration errors.

Examples:

```text
"[consent]='1'"
"[age]>='18'"
"[specimen_type]='2' and [site]<>'9'"
"[consent(1)]='1' or [consent(2)]='1'"
"not ([withdrawn]='1')"
"([visit]='1' and [weight]>'0') or [visit]<>'1'"
```

---

## Examples cookbook

Working examples grouped by the job they do. Every tag in this section has been run
through the module's own parser, so each one is syntactically sound — but only you can
say whether the *codes* (`'1'`, `'2'`, …) match your project's choice codes, so treat
those as placeholders.

**Each recipe names the field the tag belongs on** (`# on: …`). This matters more than
it looks: the tag validates the field it sits on, and that is where the message appears.

### `@UVALIDATE` recipes

#### Participant and specimen IDs with a check character

```text
# on: participant_id — an ID minted by the companion generator
@UVALIDATE

# on: participant_id — same, blocking the save
@UVALIDATE={"blockSave":"hard"}

# on: national_id — digit-only ID, strong check
@UVALIDATE=verhoeff

# on: specimen_barcode — GS1 / GTIN / EAN / UPC scanned barcode
@UVALIDATE=gs1

# on: passport_mrz_number — ICAO 9303 passport MRZ digit field
@UVALIDATE=mrz

# on: bank_routing — US ABA routing number
@UVALIDATE=aba

# on: book_isbn — ISBN-10
@UVALIDATE=isbn

# on: lab_accession — Damm; catches single-digit and adjacent-swap errors
@UVALIDATE={"algorithm":"damm","blockSave":"confirm"}
```

#### Format-only codes (no check character)

```text
# on: facility_code
@UVALIDATE={"algorithm":"none","pattern":"FC[0-9]{4}","blockSave":"hard"}

# on: tb_register_no
@UVALIDATE={"algorithm":"regex","pattern":"TB-[0-9]{6}"}

# on: year_of_diagnosis
@UVALIDATE={"algorithm":"regex","pattern":"(19|20)[0-9]{2}"}

# on: specimen_id — two letters then six digits
@UVALIDATE={"algorithm":"regex","pattern":"[A-Z]{2}[0-9]{6}"}

# on: site_or_facility_code — either prefix
@UVALIDATE={"algorithm":"regex","pattern":"(FC|TB)-[0-9]{4}"}

# on: phone_number — a fixed national shape
@UVALIDATE={"algorithm":"regex","pattern":"[0-9]{3}-[0-9]{3}-[0-9]{4}"}
```

#### Format *and* check character together

```text
# on: specimen_barcode — shape checked first, then the check character,
# so the typist learns which kind of mistake they made
@UVALIDATE={"algorithm":"iso7064_mod37_36","pattern":"TB[A-Z]{3}-[0-9]{5}[0-9A-Z]"}
```

#### Choosing what the check runs over

```text
# on: mixed_id — the check covers only the digits of a mixed ID
@UVALIDATE={"algorithm":"mod11_10","source":"digits_only"}

# on: mixed_id — the check covers only the sequence portion
@UVALIDATE={"algorithm":"3736","source":"sequence_only"}

# on: hyphenated_id — only dashes are separators here
@UVALIDATE={"algorithm":"3736","strip":"-"}
```

#### Pooled fields (several IDs in one box)

```text
# on: specimen_ids — three 9-character IDs in one field
@UVALIDATE={"type":"pooled","idLengths":[9],"expectedIds":3}

# on: specimen_ids — two possible ID lengths
@UVALIDATE={"type":"pooled","idLengths":[10,12]}

# on: specimen_ids — the same, written as a string
@UVALIDATE={"type":"pooled","idLengths":"10, 12"}

# on: facility_codes — pooled, format only
@UVALIDATE={"type":"pooled","algorithm":"none","pattern":"FC[0-9]{4}","idLengths":[6]}

# on: specimen_ids — a length RANGE; max must stay under 2x min
@UVALIDATE={"type":"pooled","idMinLen":9,"idMaxLen":12,"blockSave":"confirm"}
```

#### Conditional and annotated

```text
# on: specimen_id — only validate blood specimens
@UVALIDATE={"algorithm":"verhoeff","when":"[specimen_type]='2'"}

# on: specimen_id — tell the typist the expected check character, and label the rule
@UVALIDATE={"algorithm":"3736","suggestFix":true,"note":"Blood specimen barcode"}
```

### `@UVASSERT` recipes

#### Dates and temporal logic

> **Use `date_ymd` fields for dates you compare.** The browser reads a date field's
> value as the form holds it. Y-M-D text sorts correctly as a string, which is why the
> module's own live test for `@UVASSERT` dates uses `date_ymd` fields
> ([`docs/testbed/uvalidate_140_test_fields.csv`](testbed/uvalidate_140_test_fields.csv)).
> A **D-M-Y or M-D-Y** field is displayed day- or month-first, so a live comparison on
> it would order by day or month rather than by year — while the server audit, which
> reads the Y-M-D stored value, would order correctly. Verify on your own instance
> before relying on a date comparison over a non-Y-M-D field.

```text
# on: discharge_date
@UVASSERT={"assert":"[discharge_date]>=[admission_date]","message":"Discharge cannot precede admission"}

# on: death_date
@UVASSERT={"assert":"[death_date]>=[enrollment_date]","message":"Death date is before enrollment - check both dates"}

# on: specimen_received_date
@UVASSERT={"assert":"[specimen_collected_date]<=[specimen_received_date]","message":"A specimen cannot be received before it was collected"}

# on: treatment_start_date
@UVASSERT={"assert":"[treatment_start_date]>=[diagnosis_date]","message":"Treatment start predates diagnosis"}

# on: dob — catches a typo'd year
@UVASSERT={"assert":"[dob]<[enrollment_date]","message":"Date of birth is after enrollment - check for a typo'd year"}

# on: art_start_date — only while the participant is on ART
@UVASSERT={"assert":"[art_start_date]>=[hiv_diagnosis_date]","when":"[art_status]='1'","message":"ART cannot start before HIV diagnosis"}

# on: consent_date
@UVASSERT={"assert":"[consent_date]>=[screening_date]","message":"Consent cannot precede screening"}

# on: symptom_onset
@UVASSERT={"assert":"[symptom_onset]<=[diagnosis_date]","message":"Symptom onset cannot be after diagnosis"}

# on: lab_result_date
@UVASSERT={"assert":"[lab_result_date]>=[specimen_received_date]","message":"A result cannot predate receipt of the specimen"}

# on: second_dose_date — only when a second dose was given
@UVASSERT={"assert":"[second_dose_date]>[first_dose_date]","when":"[doses_given]='2'","message":"The second dose must be after the first"}

# on: followup_date
@UVASSERT={"assert":"[followup_date]>[enrollment_date]","when":"[visit_type]='3'","message":"A follow-up visit must be after enrollment"}
```

#### Numeric plausibility

```text
# on: sbp
@UVASSERT={"assert":"[sbp]>[dbp]","message":"Systolic must exceed diastolic blood pressure"}

# on: dose_mg
@UVASSERT={"assert":"[dose_mg]<='800'","message":"Dose exceeds the protocol maximum of 800mg","blockSave":"hard"}

# on: age_years — assent applies only under 18
@UVASSERT={"assert":"[age_years]<'18'","when":"[consent_type]='2'","message":"Assent (not consent) applies only under 18"}

# on: weight_kg — a RANGE, not a one-sided floor (see the note below)
@UVASSERT={"assert":"[weight_kg]>'2' and [weight_kg]<'300'","message":"Weight outside the plausible range","blockSave":"confirm"}

# on: haemoglobin
@UVASSERT={"assert":"[haemoglobin]>='2' and [haemoglobin]<='25'","message":"Haemoglobin outside the plausible range 2-25 g/dL"}

# on: cd4_count
@UVASSERT={"assert":"[cd4_count]<='5000'","message":"CD4 count above 5000 - check units"}

# on: bmi
@UVASSERT={"assert":"[bmi]>'10' and [bmi]<'60'","message":"BMI outside the plausible range - check height and weight"}
```

> **Make the bound do the work the message claims.** An assertion like
> `[discharge_weight_kg]>='0'` paired with the message *"Discharge weight is implausibly
> low"* only ever fires on a **negative** number — zero and every plausible weight pass,
> so a `3` typed for `30` sails through. Set the bound where the implausibility actually
> starts (`>'2'`), and prefer a two-sided range: a plausibility check that cannot fail in
> practice is worse than none, because it reads as coverage on a rule inventory.

#### Skip patterns and logical consistency

```text
# on: sex
@UVASSERT={"assert":"[sex]='2'","when":"[pregnant]='1'","message":"A pregnant participant must be recorded female"}

# on: pregnant — the same relationship from the other side, hard-blocked
@UVASSERT={"assert":"not ([pregnant]='1' and [sex]='1')","message":"A participant recorded male cannot be pregnant","blockSave":"hard"}

# on: quit_smoking_date — a quit date implies the participant is not a current smoker
@UVASSERT={"assert":"[currently_smoking]='1' or [quit_smoking_date]=''","message":"Quit date entered but currently_smoking was not set to No"}

# on: referral_facility
@UVASSERT={"assert":"[referral_facility]<>[current_facility]","when":"[referred_out]='1'","message":"Referral facility must differ from the current site"}

# on: art_regimen — pair with @UVREQUIRED to also catch a BLANK regimen
@UVASSERT={"assert":"[art_regimen]<>'0'","when":"[on_art]='1'","message":"A regimen must be selected when the participant is on ART"}

# on: tb_status — pair with @UVREQUIRED to also catch a BLANK status
@UVASSERT={"assert":"[tb_status]='1'","when":"[tb_treatment_start]<>''","message":"Treatment start recorded but TB status is not Positive - check both fields"}

# on: sample_condition
@UVASSERT={"assert":"[sample_condition]<>'1'","when":"[cold_chain_break]='1'","message":"A cold-chain break cannot be reported with sample condition Good"}

# on: outcome
@UVASSERT={"assert":"[outcome]<>'1'","when":"[death_date]<>''","message":"A death date is recorded but outcome is not Died"}

# on: weight — a baseline visit needs a weight above zero
@UVASSERT={"assert":"([visit_type]='1' and [weight]>'0') or [visit_type]<>'1'","message":"A baseline visit needs a weight above zero","blockSave":"confirm"}
```

> **A blank field is inert, so a consistency check cannot catch a blank.** The
> `[tb_status]='1'` recipe fires only once `tb_status` has *some* value — if the
> treatment start is filled and `tb_status` is left **empty**, nothing fires, which is
> exactly the case you wanted caught. Add `@UVREQUIRED={"when":"[tb_treatment_start]<>''"}`
> on the same field. The two modes compose: the required rule covers the blank, the
> assertion covers the wrong value. This applies to every "X implies Y" recipe above.

#### Double entry (the "type it twice" pattern)

```text
# on: participant_id_confirm — put the tag on the CONFIRM field, so the message
# lands next to the box the typist should re-check
@UVASSERT={"assert":"[participant_id]=[participant_id_confirm]","message":"IDs do not match - re-type","blockSave":"hard"}

# on: phone_confirm
@UVASSERT={"assert":"[phone_number]=[phone_confirm]","message":"Phone numbers do not match"}

# on: specimen_id_confirm
@UVASSERT={"assert":"[specimen_id]=[specimen_id_confirm]","message":"Specimen ID confirmation mismatch - re-scan"}
```

#### Specimen and lab workflow

```text
# on: volume_received_ml
@UVASSERT={"assert":"[volume_received_ml]<=[volume_collected_ml]","message":"Cannot receive more volume than was collected"}

# on: reader2_id — independent double reading
@UVASSERT={"assert":"[reader2_id]<>[reader1_id]","message":"Independent readers must be two different staff members"}

# on: interviewer_id
@UVASSERT={"assert":"[interviewer_id]<>[supervisor_id]","message":"The interviewer and supervisor must be different staff"}

# on: aliquot_count
@UVASSERT={"assert":"[aliquot_count]<='6'","message":"Aliquot count exceeds the tube's physical capacity"}

# on: hiv_test_date
@UVASSERT={"assert":"[hiv_test_date]<=[art_start_date]","when":"[on_art]='1'","message":"HIV test must precede ART start"}
```

#### Referencing a field on another instrument

```text
# on: enrollment_id — baseline_eligible lives on the screening form
@UVASSERT={"assert":"[baseline_eligible]='1'","message":"This participant was not marked eligible at screening"}
```

Two behaviors are specific to off-instrument references, and both are deliberate:

- **The comparison is settled on the server and folded to a boolean** before the page is
  built, so the screening value never reaches the browser. The page carries field names,
  your literals, and a `true`/`false`.
- **It is therefore correct as of page load, but does not react live.** Nothing on this
  page can change it. On a **brand-new record** there are no saved values yet, so the
  reference resolves to `''` and the assertion is false — which, on a form where the
  host field is filled, means every new record shows the message. Gate it
  (`{"when":"[record_status]<>''"}`) or put both fields on one instrument if that is not
  what you want.

### `@UVREQUIRED` recipes

#### Consent-driven

```text
# on: phone_number
@UVREQUIRED={"when":"[consent_contact]='1'","message":"Phone number is needed to contact this participant"}

# on: alternate_contact
@UVREQUIRED={"when":"[willing_followup]='1'","message":"An alternate contact is required for participants agreeing to follow-up"}

# on: consent_signature_date
@UVREQUIRED={"when":"[consent_given]='1'","message":"Consent signature date is required"}
```

#### Outcome-driven

```text
# on: cause_of_death
@UVREQUIRED={"when":"[vital_status]='2'","message":"Cause of death is required for deceased participants"}

# on: withdrawal_reason
@UVREQUIRED={"when":"[study_status]='3'","message":"A withdrawal reason is required"}

# on: ae_severity
@UVREQUIRED={"when":"[ae_occurred]='1'","message":"Adverse event severity must be graded"}

# on: ae_narrative
@UVREQUIRED={"when":"[ae_serious]='1'","message":"A serious adverse event requires a narrative"}

# on: referral_facility
@UVREQUIRED={"when":"[referred_out]='1'","message":"Referral facility is required when a referral was made"}

# on: rejection_reason
@UVREQUIRED={"when":"[sample_rejected]='1'","message":"A rejection reason is required"}

# on: receiving_facility
@UVREQUIRED={"when":"[transferred]='1'","message":"The receiving facility is required for a transfer"}
```

#### Screening and eligibility

```text
# on: pregnancy_test_result
@UVREQUIRED={"when":"[sex]='2' and [age_years]>='12' and [age_years]<='49'","message":"A pregnancy test result is required for women of childbearing age"}

# on: hiv_test_date
@UVREQUIRED={"when":"[hiv_status_baseline]='3'","message":"An HIV test date is required when baseline status is Unknown"}

# on: art_regimen
@UVREQUIRED={"when":"[art_status]='1'","message":"An ART regimen is required for participants on treatment","blockSave":"hard"}

# on: result_date — required once any TB result is entered
@UVREQUIRED={"when":"[tb_result]='1' or [tb_result]='2'","message":"A result date is required once a TB result is entered"}
```

#### Visit and follow-up completeness

```text
# on: missed_visit_reason
@UVREQUIRED={"when":"[visit_completed]='0'","message":"A reason is required for a missed visit"}

# on: specimen_id
@UVREQUIRED={"when":"[visit_type]='2'","message":"A specimen must be collected on a specimen-collection visit"}
```

#### Unconditional (still worth it over REDCap's native required flag — a real block, not a warning)

```text
# on: site
@UVREQUIRED={"blockSave":"hard","message":"Site is mandatory for every enrollment"}

# on: collection_date — the bare tag: always required, message only
@UVREQUIRED

# on: specimen_id — a compound gate
@UVREQUIRED={"when":"[consent]='1' and [site]<>'9' and not [withdrawn(1)]='1'","message":"Required for active consented participants at study sites","blockSave":"hard"}
```

### `@UVUNIQUE` recipes

#### Participant identifiers

```text
# on: national_id
@UVUNIQUE={"message":"This national ID is already enrolled under another record","blockSave":"hard"}

# on: hospital_mrn
@UVUNIQUE={"message":"This hospital MRN is already in the project"}

# on: email
@UVUNIQUE={"message":"This email has already registered - check for a duplicate screening entry","blockSave":"confirm"}
```

#### Specimen and sample tracking

```text
# on: specimen_barcode
@UVUNIQUE={"message":"This specimen barcode is already recorded"}

# on: specimen_id — unique within its collection site
@UVUNIQUE={"with":["collection_site"],"message":"This specimen ID is already used at this site","blockSave":"hard"}

# on: aliquot_id
@UVUNIQUE={"message":"This aliquot ID has already been assigned"}
```

#### Site-scoped (a composite key, or DAG scope)

```text
# on: enrollment_no — each site numbers its own enrollments
@UVUNIQUE={"scope":"dag","message":"This enrollment number is already used at your site"}

# on: bed_number
@UVUNIQUE={"with":["site","enrollment_date"],"message":"Bed/room assignment conflict for this site and date"}

# on: specimen_barcode — composite key AND a DAG scope AND a gate
@UVUNIQUE={"with":["site"],"scope":"dag","when":"[specimen_collected]='1'","message":"Specimen barcode already registered in this DAG","blockSave":"hard","surveys":false}
```

`with` and `scope` are different tools and compose: `with` widens the **key** (what
counts as the same value), `scope` narrows the **search** (which records are compared).

#### Event-scoped (longitudinal — unique per round, reusable across rounds)

```text
# on: household_member_id
@UVUNIQUE={"scope":"event","message":"This household-member ID is already used in this survey round"}

# on: member_no — composite key within the round
@UVUNIQUE={"with":["household_id"],"scope":"event","message":"This member number is already used in this household this round"}
```

#### Survey deduplication (opt-in; respondents get a boolean, never a record id)

```text
# on: participant_id
@UVUNIQUE={"surveys":true,"message":"This ID has already submitted a response"}

# on: participant_id — composite key on a survey
@UVUNIQUE={"surveys":true,"with":["dob"],"message":"A response already exists for this ID and date of birth"}
```

### Combination recipes

Different kinds of tag on one field compose — all must pass, each with its own
save-block state. Put them in the same annotation box, separated by whitespace or
newlines.

#### The enrollment ID field — well-formed, present, and not a duplicate

```text
@UVALIDATE=verhoeff @UVREQUIRED @UVUNIQUE
```

The short form of the everyday case. Spelled out with enforcement and wording:

```text
@UVREQUIRED="[consent]='1'"
@UVALIDATE={"algorithm":"3736","blockSave":"hard"}
@UVUNIQUE={"blockSave":"hard","message":"That participant ID already exists"}
```

The layering is what makes this readable to the person typing: on a **blank** field only
the required notice fires; once filled, the value checks take over.

#### The specimen barcode field — format, double entry, and site-scoped uniqueness

```text
@UVALIDATE={"algorithm":"none","pattern":"TB-[0-9]{6}","blockSave":"hard"}
@UVASSERT={"assert":"[participant_id]=[participant_id_confirm]","message":"The two ID entries do not match","blockSave":"hard"}
@UVUNIQUE={"scope":"dag","blockSave":"hard"}
```

#### A scanned barcode — check character plus no duplicates

```text
@UVALIDATE=gs1 @UVUNIQUE={"message":"This barcode is already recorded","blockSave":"hard"}
```

#### Closing the blank gap on a consistency check

The pairing the `@UVASSERT` gotcha above calls for — required covers the blank, the
assertion covers the wrong value:

```text
# on: tb_status
@UVREQUIRED={"when":"[tb_treatment_start]<>''","message":"A TB status is required once treatment start is recorded"}
@UVASSERT={"assert":"[tb_status]='1'","when":"[tb_treatment_start]<>''","message":"Treatment start recorded but TB status is not Positive"}
```

#### An outcome cluster

```text
# on: death_date
@UVREQUIRED={"when":"[vital_status]='2'","message":"Cause of death is required"}
@UVASSERT={"assert":"[death_date]>=[enrollment_date]","message":"Death date precedes enrollment"}
```

#### Compose *and* branch on one field

Composition and branching are independent, so a field may do both: two `@UVALIDATE`
tags branch by specimen type, while `@UVREQUIRED` composes across both branches.

```text
@UVREQUIRED
@UVALIDATE={"algorithm":"verhoeff","when":"[specimen_type]='2'","blockSave":"hard"}
@UVALIDATE={"algorithm":"none","pattern":"FC[0-9]{4}"}
```

Blood specimens get a Verhoeff check that blocks the save; everything else gets the
format pattern as the else branch; the field is required either way.

#### What you cannot combine

| Attempt | Result |
|---|---|
| Two `@UVALIDATE` tags, neither with a `when` | Configuration error — two when-less rules of one kind |
| Two `@UVASSERT` tags with byte-identical `when` | Configuration error — they could never be told apart |
| A `single` and a `pooled` `@UVALIDATE` on one field | Configuration error — mixed types in one kind |
| Two `@UVREQUIRED` tags whose conditions are true at once | Runtime conflict — a notice, validates nothing, never blocks |
| `@UVREQUIRED` or `@UVUNIQUE` on a **calc** field | Configuration error — a data enterer cannot fix a calc |
| `@UVALIDATE` on a dropdown/radio/slider | Configuration error — check/format rules are Text and Notes only |

---

## Parameter reference tables

### `@UVALIDATE`

| Key | Type | Default | Scope | Notes |
|---|---|---|---|---|
| `type` | string | `single` | all | `single` or `pooled` |
| `algorithm` | string | `iso7064_mod37_36` | all | See the algorithm list; shorthands accepted |
| `source` | string | `normalized_id` | all | `normalized_id`, `digits_only`, `sequence_only` |
| `pattern` | string | *(none)* | all | JS regex, auto-anchored, uppercase, printable ASCII |
| `strip` | string | `-/ _\|\` | all | Separator characters ignored before checking |
| `keepChars` | string | *(none)* | pooled | Extra characters kept when splitting; length-capped |
| `idLengths` | list or string | *(none)* | pooled | `[9]`, `[10,12]`, `"10, 12"` |
| `idMinLen` | integer | `8` | pooled | Positive whole number |
| `idMaxLen` | integer | `14` | pooled | Must be **< 2× `idMinLen`** |
| `expectedIds` | integer | *(none)* | pooled | Expected number of IDs in the field |
| `blockSave` | string | `off` | all | `off`, `confirm`, `hard` |
| `when` | string | *(none)* | all | Condition; the rule runs only while true |
| `suggestFix` | boolean | `false` | all | Opt in to the "should end in X" hint |
| `note` | string | *(none)* | all | Rule label |

### `@UVASSERT`

| Key | Type | Default | Notes |
|---|---|---|---|
| `assert` | string | *(required)* | The condition that must hold; a missing one is an error |
| `message` | string | generic line | Your own wording — recommended |
| `blockSave` | string | `off` | `off`, `confirm`, `hard` |
| `when` | string | *(none)* | Enforce the constraint only while true |

### `@UVREQUIRED`

| Key | Type | Default | Notes |
|---|---|---|---|
| `when` | string | *(none)* | Required only while true; the bare short form sets this |
| `message` | string | generic line | Your own wording |
| `blockSave` | string | `off` | `off`, `confirm`, `hard` |

### `@UVUNIQUE`

| Key | Type | Default | Notes |
|---|---|---|---|
| `with` | list of strings | *(none)* | Composite key fields; max 5, no duplicates, must exist |
| `scope` | string | `project` | `project`, `dag`, `event`; the bare short form sets this |
| `surveys` | boolean | `false` | Opt in to the check on surveys; boolean answer only |
| `when` | string | *(none)* | Check only while true |
| `message` | string | generic line | Your own wording |
| `blockSave` | string | `off` | `off`, `confirm`, `hard` |

### Algorithms

| Algorithm | Payload / output | Example (payload → check) | Typical use |
|---|---|---|---|
| `iso7064_mod37_36` **(default)** | letters+digits, 1 char | `0ABCD12345` → `K` | Participant/specimen IDs |
| `iso7064_mod11_10` | digits, 1 char | `079` → `2` | Strong digit-only check |
| `iso7064_mod97_10` | digits, 2 chars | `1` → `95` | Longer digit IDs (IBAN scheme) |
| `iso7064_mod11_2` | digits, 1 char (may be `X`) | `079` → `X` | Digit IDs where `X` is acceptable |
| `iso7064_mod37_2` | letters+digits, 1 char (may be `*`) | `1` → `*` | Alphanumeric, pure Mod 37,2 |
| `iso7064_letters1` | 1 letter | `0ABCD12345` → `N` | Letter-only check |
| `iso7064_letters2` | 2 letters (A–F) | `0ABCD12345` → `DC` | Letter-only check, two chars |
| `damm` | digits, 1 char | `572` → `4` | Catches single-digit and adjacent-swap errors |
| `verhoeff` | digits, 1 char | `123456` → `8` | Strong single-error + transposition coverage |
| `luhn` | digits, 1 char | `7992739871` → `3` | Compatibility only (weakest) |
| `gs1_mod10` | digits, 1 char | `978030640615` → `7` | GS1 / GTIN / EAN / UPC barcodes |
| `aba_mod10` | digits, 1 char | `01100001` → `5` | US bank routing numbers |
| `mrz_mod10` | digits, 1 char | `740812` → `2` | ICAO 9303 passport MRZ fields |
| `weighted_mod11` | digits, 1 char (may be `X`) | `080442957` → `X` | ISBN-10 style, ≤ 9-digit payloads |
| `none` | *(format only)* | — | Codes with a fixed shape and no check character |

### Algorithm shorthands

| Shorthands | Resolves to |
|---|---|
| `3736`, `37,36`, `37_36`, `mod37_36`, `mod3736` | `iso7064_mod37_36` |
| `1110`, `11,10`, `11_10`, `mod11_10`, `mod1110` | `iso7064_mod11_10` |
| `9710`, `97,10`, `97_10`, `mod97_10`, `mod9710` | `iso7064_mod97_10` |
| `112`, `11,2`, `11_2`, `mod11_2`, `mod112` | `iso7064_mod11_2` |
| `372`, `37,2`, `37_2`, `mod37_2`, `mod372` | `iso7064_mod37_2` |
| `letters1`, `letter1` / `letters2`, `letter2` | `iso7064_letters1` / `iso7064_letters2` |
| `mod10` | `luhn` |
| `gs1`, `gtin`, `ean`, `upc` | `gs1_mod10` |
| `aba`, `routing` | `aba_mod10` |
| `mrz`, `icao` | `mrz_mod10` |
| `isbn`, `mod11w`, `weighted11` | `weighted_mod11` |
| `regex`, `format` | `none` (pair with a `pattern`) |

The separators `,` `_` `-` are interchangeable, and each numeric shorthand also accepts a
`mod…` prefix. `damm` and `verhoeff` have no shorthand.

---

## Copy-paste cheat sheet

```text
# ── @UVALIDATE — check character / format ────────────────────────────────────
@UVALIDATE                                            default check, message only
@UVALIDATE=verhoeff                                   pick an algorithm
@UVALIDATE=9710                                       shorthand (ISO 7064 Mod 97,10)
@UVALIDATE=gs1                                        GS1 / GTIN / EAN / UPC
@UVALIDATE={"blockSave":"confirm"}                    ask before saving a bad value
@UVALIDATE={"blockSave":"hard"}                       block the save until fixed
@UVALIDATE={"algorithm":"none","pattern":"FC[0-9]{4}","blockSave":"hard"}
@UVALIDATE={"algorithm":"regex","pattern":"TB-[0-9]{6}"}
@UVALIDATE={"algorithm":"mod11_10","source":"digits_only"}
@UVALIDATE={"algorithm":"verhoeff","when":"[specimen_type]='2'"}
@UVALIDATE={"algorithm":"3736","suggestFix":true,"note":"Blood barcode"}
@UVALIDATE={"type":"pooled","idLengths":[9],"expectedIds":3}
@UVALIDATE={"type":"pooled","algorithm":"none","pattern":"FC[0-9]{4}","idLengths":[6]}

# ── @UVASSERT — cross-field constraint ───────────────────────────────────────
@UVASSERT="[end_date]>=[start_date]"
@UVASSERT="[participant_id]=[participant_id_confirm]"
@UVASSERT={"assert":"[dose]<=[max_dose]","message":"Dose exceeds the protocol maximum","blockSave":"hard"}
@UVASSERT={"assert":"[sex]='2'","when":"[pregnant]='1'","message":"Pregnant participants must be recorded female"}

# ── @UVREQUIRED — conditional required ───────────────────────────────────────
@UVREQUIRED                                           always required
@UVREQUIRED="[consent]='1'"                           required only while consented
@UVREQUIRED={"when":"[consent]='1'","message":"Phone needed for consented participants","blockSave":"hard"}

# ── @UVUNIQUE — no duplicates across records ─────────────────────────────────
@UVUNIQUE                                             unique across the project
@UVUNIQUE=dag                                         unique within each DAG
@UVUNIQUE=event                                       unique within the same event
@UVUNIQUE={"with":["site"],"message":"Specimen already registered","blockSave":"hard"}
@UVUNIQUE={"surveys":true,"blockSave":"hard"}         also check on surveys (opt-in)

# ── @UVCHOICES — dynamic choice filtering ────────────────────────────────────
@UVCHOICES={"when":"[legacy_entry]<>'1'","hide":["9"]}
@UVCHOICES={"when":"[country]='1'","show":["101","102","103"]}
@UVCHOICES={"when":"[country]='1' and [region]='101'","show":["s01","s02"]}
@UVCHOICES={"when":"[pilot(1)]='1'","show":["s01"],"message":"Pilot sites only","blockSave":"hard"}

# ── Composing several kinds on ONE field ─────────────────────────────────────
@UVREQUIRED="[consent]='1'"
@UVALIDATE={"algorithm":"3736","blockSave":"hard"}
@UVUNIQUE={"blockSave":"hard","message":"That participant ID already exists"}

# ── Branching: several tags of the SAME kind ─────────────────────────────────
@UVALIDATE={"algorithm":"verhoeff","when":"[specimen_type]='2'"}
@UVALIDATE={"algorithm":"none","pattern":"FC[0-9]{4}"}   <- the "otherwise" branch
```

---

*Documents Universal Field Validator v1.5.0. For the full training
guide see [`USER_GUIDE.md`](USER_GUIDE.md); for installation see
[`INSTALL.md`](INSTALL.md); for the manual REDCap test checklist see
[`TESTING.md`](TESTING.md); for the product overview see the [README](../README.md).*
